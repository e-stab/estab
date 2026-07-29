<?php

declare(strict_types=1);

/**
 * Self-contained PDF dossier for one eStab incident.
 *
 * The report renders ETB, TBB and message forms in a readable, searchable
 * document. Every completed attachment is additionally embedded as its
 * original byte stream. This preserves formats that cannot safely be rendered
 * by FPDF (for example PDF, ODT, ZIP or video) without silently omitting them.
 */

require_once __DIR__ . '/legacy_php.php';
require_once __DIR__ . '/../4fbak/backup_pdf.php';

const ESTAB_INCIDENT_PDF_DEFAULT_ATTACHMENT_BYTES = 50 * 1024 * 1024;
const ESTAB_INCIDENT_PDF_MAX_ATTACHMENTS = 1000;

final class EstabIncidentPdfInputException extends InvalidArgumentException
{
}

/** Convert application UTF-8 to the Windows-1252 encoding of FPDF core fonts. */
function estab_incident_pdf_text(mixed $value): string
{
    if (
        is_array($value)
        || is_object($value)
        || is_resource($value)
    ) {
        throw new EstabIncidentPdfInputException('PDF text must be scalar.');
    }
    $text = html_entity_decode(
        (string) $value,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace(
        '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}'
            . '\x{007F}-\x{009F}]/u',
        '',
        $text
    ) ?? '';

    return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
}

/** Validate one portable name for the PDF embedded-file name tree. */
function estab_incident_pdf_attachment_name(mixed $value): string
{
    if (!is_string($value)) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment name must be text.'
        );
    }
    $name = trim($value);
    if (
        preg_match(
            '/\A[A-Za-z0-9][A-Za-z0-9._ -]{0,180}\z/D',
            $name
        ) !== 1
        || $name !== basename($name)
        || str_contains($name, '..')
    ) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment name is unsafe.'
        );
    }
    return $name;
}

/** Validate and encode an RFC-style MIME token as a PDF name. */
function estab_incident_pdf_mime_name(mixed $value): string
{
    if (
        !is_string($value)
        || preg_match(
            '/\A[a-z0-9][a-z0-9!#$&^_.+-]*\/'
                . '[a-z0-9][a-z0-9!#$&^_.+-]*\z/DiD',
            $value
        ) !== 1
    ) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment MIME type is invalid.'
        );
    }

    $encoded = '';
    foreach (str_split($value) as $character) {
        $ordinal = ord($character);
        if (
            ($ordinal >= 48 && $ordinal <= 57)
            || ($ordinal >= 65 && $ordinal <= 90)
            || ($ordinal >= 97 && $ordinal <= 122)
            || str_contains('._+-', $character)
        ) {
            $encoded .= $character;
        } else {
            $encoded .= '#' . strtoupper(str_pad(
                dechex($ordinal),
                2,
                '0',
                STR_PAD_LEFT
            ));
        }
    }
    return $encoded;
}

/**
 * Read a stable regular file without following a final-component symlink.
 *
 * @return array{data:string,size:int,sha256:string,modified:int}
 */
function estab_incident_pdf_read_attachment(
    string $path,
    int $remainingBytes
): array {
    if (
        $path === ''
        || str_contains($path, "\0")
        || $remainingBytes < 0
    ) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment path is invalid.'
        );
    }
    $before = @lstat($path);
    if (
        !is_array($before)
        || (((int) ($before['mode'] ?? 0)) & 0170000) !== 0100000
        || (int) ($before['size'] ?? -1) < 0
        || (int) $before['size'] > $remainingBytes
    ) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment is unavailable or exceeds the PDF limit.'
        );
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment cannot be opened.'
        );
    }
    try {
        $opened = fstat($handle);
        if (
            !is_array($opened)
            || (int) ($opened['dev'] ?? -1) !== (int) ($before['dev'] ?? -2)
            || (int) ($opened['ino'] ?? -1) !== (int) ($before['ino'] ?? -2)
            || (int) ($opened['size'] ?? -1) !== (int) $before['size']
        ) {
            throw new EstabIncidentPdfInputException(
                'Embedded attachment changed while opening.'
            );
        }

        $expected = (int) $opened['size'];
        $data = '';
        while (strlen($data) < $expected && !feof($handle)) {
            $chunk = fread($handle, min(1048576, $expected - strlen($data)));
            if ($chunk === false) {
                throw new EstabIncidentPdfInputException(
                    'Embedded attachment cannot be read.'
                );
            }
            $data .= $chunk;
        }
        $after = fstat($handle);
        if (
            strlen($data) !== $expected
            || !is_array($after)
            || (int) ($after['dev'] ?? -1) !== (int) $opened['dev']
            || (int) ($after['ino'] ?? -1) !== (int) $opened['ino']
            || (int) ($after['size'] ?? -1) !== $expected
        ) {
            throw new EstabIncidentPdfInputException(
                'Embedded attachment changed while reading.'
            );
        }
    } finally {
        fclose($handle);
    }

    return [
        'data' => $data,
        'size' => strlen($data),
        'sha256' => hash('sha256', $data),
        'modified' => max(0, (int) ($before['mtime'] ?? 0)),
    ];
}

/**
 * A searchable incident dossier with original files embedded in the catalog.
 */
final class EstabIncidentPdf extends vordruckaspdf
{
    private const LAYOUT_DOSSIER = 'dossier';
    private const LAYOUT_MESSAGE_FORM = 'message-form';

    private string $incidentLabel = 'eStab';
    private string $sectionTitle = 'Einsatzdossier';
    private int $attachmentByteLimit;
    private int $attachmentBytes = 0;
    private string $nextPageLayout = self::LAYOUT_DOSSIER;

    /** @var array<int,string> */
    private array $pageLayouts = [];

    /**
     * @var list<array{
     *     name:string,
     *     mime:string,
     *     description:string,
     *     data:string,
     *     size:int,
     *     sha256:string,
     *     modified:int
     * }>
     */
    private array $embeddedFiles = [];

    /** @var list<int> */
    private array $embeddedFileSpecObjects = [];
    private ?int $embeddedNamesObject = null;

    public function __construct(
        array $incident,
        int $attachmentByteLimit = ESTAB_INCIDENT_PDF_DEFAULT_ATTACHMENT_BYTES,
        ?array $recipientMatrix = null
    ) {
        if ($attachmentByteLimit < 0 || $attachmentByteLimit > 500 * 1024 * 1024) {
            throw new EstabIncidentPdfInputException(
                'PDF attachment byte limit is invalid.'
            );
        }
        $this->initialize_message_form_document($recipientMatrix);
        $this->attachmentLinksEnabled = false;
        $this->PDFVersion = '1.7';
        $this->attachmentByteLimit = $attachmentByteLimit;
        $this->configureDossierLayout();
        $this->AliasNbPages();
        $this->SetTitle(
            estab_incident_pdf_text(
                'eStab Einsatzdossier '
                    . (string) ($incident['kennung'] ?? '')
            )
        );
        $this->SetAuthor('eStab');
        $this->SetCreator('eStab Einsatzexport');

        $code = trim((string) ($incident['kennung'] ?? ''));
        $name = trim((string) ($incident['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new EstabIncidentPdfInputException(
                'Incident identity is incomplete.'
            );
        }
        $this->incidentLabel = $code . ' · ' . $name;
    }

    public function AddPage($orientation = '', $format = '')
    {
        $this->pageLayouts[$this->PageNo() + 1] = $this->nextPageLayout;
        parent::AddPage($orientation, $format);
    }

    private function isMessageFormPage(): bool
    {
        return ($this->pageLayouts[$this->PageNo()] ?? self::LAYOUT_DOSSIER)
            === self::LAYOUT_MESSAGE_FORM;
    }

    private function configureDossierLayout(): void
    {
        $this->nextPageLayout = self::LAYOUT_DOSSIER;
        $this->SetMargins(16, 30, 16);
        $this->SetAutoPageBreak(true, 20);
    }

    private function configureMessageFormLayout(): void
    {
        $this->nextPageLayout = self::LAYOUT_MESSAGE_FORM;
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(
            true,
            $this->bottom - $this->point[38][1]
        );
    }

    public function Header()
    {
        if ($this->isMessageFormPage()) {
            parent::Header();
            return;
        }
        $this->SetFillColor(23, 47, 77);
        $this->Rect(0, 0, $this->w, 23, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 12);
        $this->SetXY(16, 6);
        $this->Cell(
            0,
            5,
            estab_incident_pdf_text($this->sectionTitle),
            0,
            1
        );
        $this->SetFont('helvetica', '', 8);
        $this->SetX(16);
        $this->Cell(
            0,
            4,
            estab_incident_pdf_text($this->incidentLabel),
            0,
            1
        );
        $this->SetTextColor(23, 32, 51);
        $this->SetY(30);
    }

    public function Footer()
    {
        if ($this->isMessageFormPage()) {
            parent::Footer();
            return;
        }
        $this->SetY(-14);
        $this->SetDrawColor(141, 162, 189);
        $this->Line(16, $this->GetY(), $this->w - 16, $this->GetY());
        $this->SetY(-11);
        $this->SetTextColor(73, 89, 111);
        $this->SetFont('helvetica', '', 8);
        $this->Cell(
            0,
            5,
            estab_incident_pdf_text(
                'eStab · Seite ' . $this->PageNo() . '/{nb}'
            ),
            0,
            0,
            'C'
        );
    }

    public function AcceptPageBreak()
    {
        if (!$this->isMessageFormPage()) {
            return $this->AutoPageBreak;
        }
        if ($this->GetY() >= $this->point[38][1] - 10) {
            $this->configureMessageFormLayout();
            $this->AddPage();
            $this->set_message_content_continuation_position();
        }
        return false;
    }

    private function beginSection(string $title, bool $newPage = true): void
    {
        $this->sectionTitle = $title;
        $this->configureDossierLayout();
        if ($newPage) {
            $this->AddPage();
        }
    }

    private function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > $this->h - 20) {
            $this->AddPage();
        }
    }

    private function heading(string $text, int $level = 1): void
    {
        $this->ensureSpace($level === 1 ? 16 : 11);
        $this->SetTextColor(23, 47, 77);
        $this->SetFont('helvetica', 'B', $level === 1 ? 17 : 12);
        $this->MultiCell(
            0,
            $level === 1 ? 8 : 6,
            estab_incident_pdf_text($text)
        );
        $this->Ln($level === 1 ? 2 : 1);
        $this->SetTextColor(23, 32, 51);
    }

    private function paragraph(string $text): void
    {
        $this->SetFont('helvetica', '', 10);
        $this->MultiCell(0, 5.5, estab_incident_pdf_text($text));
        $this->Ln(1.5);
    }

    private function definition(string $label, mixed $value): void
    {
        $valueText = trim((string) $value);
        if ($valueText === '') {
            $valueText = '-';
        }
        $this->ensureSpace(9);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(73, 89, 111);
        $this->Cell(58, 5.5, estab_incident_pdf_text($label), 0, 0);
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(23, 32, 51);
        $this->MultiCell(0, 5.5, estab_incident_pdf_text($valueText));
    }

    /** @param array<string,int> $counts */
    public function addCover(
        array $incident,
        array $selectedSections,
        array $counts,
        string $generatedAt,
        string $generatedBy
    ): void {
        $this->beginSection('Einsatzdossier');
        $this->heading('Einsatzdossier', 1);
        $this->paragraph(
            'Dieser Export fasst die ausgewählten Einsatzdaten in einer '
            . 'durchsuchbaren PDF zusammen. Originalanhänge werden im PDF '
            . 'eingebettet und im Anlagenverzeichnis mit SHA-256 ausgewiesen.'
        );
        $this->heading('Einsatz', 2);
        foreach ([
            'Kennung' => $incident['kennung'] ?? '',
            'Name' => $incident['name'] ?? '',
            'Beginn' => $incident['beginn'] ?? '',
            'Ende' => $incident['ende'] ?? '',
            'Ort' => $incident['ort'] ?? '',
            'Organisation' => $incident['organisation'] ?? '',
            'Einsatzleitung' => $incident['einsatzleitung'] ?? '',
            'Beschreibung' => $incident['beschreibung'] ?? '',
        ] as $label => $value) {
            $this->definition($label, $value);
        }

        $this->heading('Umfang', 2);
        $labels = [
            'etb' => 'Einsatztagebuch (ETB)',
            'ttb' => 'Technisches Betriebsbuch (TBB)',
            'messages' => 'Nachrichtenvordrucke',
            'attachments' => 'Originalanhänge',
        ];
        foreach ($labels as $key => $label) {
            if (in_array($key, $selectedSections, true)) {
                $this->definition(
                    $label,
                    (string) ($counts[$key] ?? 0)
                );
            }
        }
        $this->heading('Erzeugung', 2);
        $this->definition('Zeitpunkt', $generatedAt);
        $this->definition('Administrationszugang', $generatedBy);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function addLogbook(string $kind, array $rows): void
    {
        $kind = strtoupper($kind);
        if ($kind === 'TTB') {
            $kind = 'TBB';
        }
        if (!in_array($kind, ['ETB', 'TBB'], true)) {
            throw new EstabIncidentPdfInputException(
                'Unknown logbook kind.'
            );
        }
        $prefix = $kind === 'ETB' ? 'etb' : 'tbb';
        $this->beginSection(
            $kind === 'ETB'
                ? 'Einsatztagebuch (ETB)'
                : 'Technisches Betriebsbuch (TBB)'
        );
        $this->heading($this->sectionTitle, 1);
        if ($rows === []) {
            $this->paragraph('Für diesen Einsatz sind keine Einträge vorhanden.');
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new EstabIncidentPdfInputException(
                    'Logbook rows must be arrays.'
                );
            }
            $this->ensureSpace(28);
            $number = (string) (
                $row[$prefix . '_lfd-nr']
                    ?? $row['lfd']
                    ?? ''
            );
            $time = (string) ($row[$prefix . '_time'] ?? '');
            $this->SetFillColor(226, 233, 241);
            $this->SetTextColor(23, 47, 77);
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(
                0,
                7,
                estab_incident_pdf_text(
                    $kind . ' ' . $number . ' · ' . $time
                ),
                0,
                1,
                'L',
                true
            );
            $this->SetTextColor(23, 32, 51);
            $this->definition(
                'Ereignis',
                $row[$prefix . '_aktion'] ?? ''
            );
            $this->definition(
                'Bemerkung',
                $row[$prefix . '_bemerk'] ?? ''
            );
            $author = implode(' · ', array_filter([
                trim((string) ($row[$prefix . '_benutzer'] ?? '')),
                trim((string) ($row[$prefix . '_kuerzel'] ?? '')),
                trim((string) ($row[$prefix . '_funktion'] ?? '')),
            ], static fn (string $part): bool => $part !== ''));
            $this->definition('Erfasst durch', $author);
            $this->Ln(3);
        }
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param array<int,list<string>> $attachmentNamesByMessage
     */
    public function addMessages(
        array $messages,
        array $attachmentNamesByMessage = []
    ): void {
        $this->sectionTitle = 'Nachrichtenvordrucke';
        if ($messages === []) {
            $this->beginSection('Nachrichtenvordrucke');
            $this->heading('Nachrichtenvordrucke', 1);
            $this->paragraph(
                'Für diesen Einsatz sind keine Nachrichtenvordrucke vorhanden.'
            );
            return;
        }
        if (!is_array($this->recipientMatrix)) {
            throw new EstabIncidentPdfInputException(
                'Recipient matrix is required for message forms.'
            );
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                throw new EstabIncidentPdfInputException(
                    'Message rows must be arrays.'
                );
            }
            $recordId = (int) ($message['00_lfd'] ?? 0);
            if ($recordId < 1) {
                throw new EstabIncidentPdfInputException(
                    'Message rows require a positive record ID.'
                );
            }
            if (array_key_exists($recordId, $attachmentNamesByMessage)) {
                $attachments = $attachmentNamesByMessage[$recordId];
                if (!is_array($attachments)) {
                    throw new EstabIncidentPdfInputException(
                        'Message attachment names must be an array.'
                    );
                }
                $validated = [];
                foreach ($attachments as $attachment) {
                    if (!is_string($attachment)) {
                        throw new EstabIncidentPdfInputException(
                            'Message attachment names must be text.'
                        );
                    }
                    try {
                        $validated[] = estab_file_validate_name(
                            'attachment',
                            $attachment
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw new EstabIncidentPdfInputException(
                            'Message attachment name is unsafe.',
                            0,
                            $exception
                        );
                    }
                }
                $message['12_anhang'] = $validated === []
                    ? ''
                    : implode(';', $validated) . ';';
            }
            $this->set_message_form_data($message);
            $this->configureMessageFormLayout();
            $this->AddPage();
            $this->writedata_inhalt();
        }
    }

    /**
     * Add an original file to the PDF catalog and return its immutable digest.
     *
     * @return array{name:string,size:int,sha256:string,mime:string}
     */
    public function embedAttachment(
        string $path,
        string $name,
        string $mime,
        string $description
    ): array {
        if (count($this->embeddedFiles) >= ESTAB_INCIDENT_PDF_MAX_ATTACHMENTS) {
            throw new EstabIncidentPdfInputException(
                'PDF contains too many attachments.'
            );
        }
        $name = estab_incident_pdf_attachment_name($name);
        $mimeName = estab_incident_pdf_mime_name($mime);
        foreach ($this->embeddedFiles as $file) {
            if ($file['name'] === $name) {
                throw new EstabIncidentPdfInputException(
                    'Embedded attachment names must be unique.'
                );
            }
        }
        $remaining = $this->attachmentByteLimit - $this->attachmentBytes;
        $file = estab_incident_pdf_read_attachment($path, $remaining);
        $description = trim($description);
        if (
            strlen($description) > 1000
            || preg_match('//u', $description) !== 1
        ) {
            throw new EstabIncidentPdfInputException(
                'Embedded attachment description is invalid.'
            );
        }

        $this->attachmentBytes += $file['size'];
        $this->embeddedFiles[] = [
            'name' => $name,
            'mime' => $mimeName,
            'description' => $description,
            'data' => $file['data'],
            'size' => $file['size'],
            'sha256' => $file['sha256'],
            'modified' => $file['modified'],
        ];
        return [
            'name' => $name,
            'size' => $file['size'],
            'sha256' => $file['sha256'],
            'mime' => $mime,
        ];
    }

    /**
     * @param list<array{
     *     display_name:string,
     *     stored_name:string,
     *     size:int,
     *     sha256:string,
     *     mime:string,
     *     message_ids?:list<int>
     * }> $attachments
     */
    public function addAttachmentIndex(array $attachments): void
    {
        $this->beginSection('Anlagenverzeichnis');
        $this->heading('Anlagenverzeichnis', 1);
        if ($attachments === []) {
            $this->paragraph(
                'Für diesen Einsatz sind keine abgeschlossenen Anhänge vorhanden.'
            );
            return;
        }
        $this->paragraph(
            'Jede nachfolgend aufgeführte Originaldatei ist in dieser PDF '
            . 'eingebettet. PDF-Leser mit Anlagenansicht können sie unter '
            . 'ihrem portablen Dateinamen öffnen oder speichern.'
        );
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                throw new EstabIncidentPdfInputException(
                    'Attachment index rows must be arrays.'
                );
            }
            $this->ensureSpace(30);
            $this->SetFillColor(226, 233, 241);
            $this->SetTextColor(23, 47, 77);
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(
                0,
                7,
                estab_incident_pdf_text(
                    (string) ($attachment['display_name'] ?? '')
                ),
                0,
                1,
                'L',
                true
            );
            $this->definition(
                'Eingebettete Datei',
                $attachment['stored_name'] ?? ''
            );
            $this->definition(
                'Typ',
                $attachment['mime'] ?? 'application/octet-stream'
            );
            $this->definition(
                'Größe',
                number_format(
                    max(0, (int) ($attachment['size'] ?? 0)),
                    0,
                    ',',
                    '.'
                ) . ' Byte'
            );
            $this->definition('SHA-256', $attachment['sha256'] ?? '');
            $messageIds = $attachment['message_ids'] ?? [];
            if (is_array($messageIds) && $messageIds !== []) {
                $this->definition(
                    'Nachrichten',
                    implode(', ', array_map(
                        static fn (mixed $id): string => (string) $id,
                        $messageIds
                    ))
                );
            }
            $this->Ln(2);
        }
    }

    public function embeddedAttachmentCount(): int
    {
        return count($this->embeddedFiles);
    }

    public function embeddedAttachmentBytes(): int
    {
        return $this->attachmentBytes;
    }

    /** Emit embedded streams, file specifications and their name tree. */
    public function _putresources()
    {
        parent::_putresources();
        if ($this->embeddedFiles === []) {
            return;
        }

        $names = [];
        foreach ($this->embeddedFiles as $file) {
            $this->_newobj();
            $embeddedObject = $this->n;
            $this->_out(
                '<</Type /EmbeddedFile /Subtype /' . $file['mime']
            );
            $this->_out('/Length ' . $file['size']);
            $this->_out(
                '/Params <</Size ' . $file['size']
                . ' /CheckSum <' . md5($file['data']) . '>'
                . ' /ModDate '
                . $this->_textstring(
                    'D:' . date('YmdHis', $file['modified'])
                )
                . '>>>>'
            );
            $this->_putstream($file['data']);
            $this->_out('endobj');

            $this->_newobj();
            $fileSpecObject = $this->n;
            $this->_out('<</Type /Filespec');
            $this->_out('/F ' . $this->_textstring($file['name']));
            $this->_out('/UF ' . $this->_textstring($file['name']));
            $this->_out(
                '/Desc ' . $this->_textstring(
                    estab_incident_pdf_text($file['description'])
                )
            );
            $this->_out(
                '/EF <</F ' . $embeddedObject . ' 0 R'
                . ' /UF ' . $embeddedObject . ' 0 R>>'
            );
            $this->_out('/AFRelationship /Data>>');
            $this->_out('endobj');
            $this->embeddedFileSpecObjects[] = $fileSpecObject;
            $names[] = $this->_textstring($file['name'])
                . ' ' . $fileSpecObject . ' 0 R';
        }

        $this->_newobj();
        $this->embeddedNamesObject = $this->n;
        $this->_out('<</Names [' . implode(' ', $names) . ']>>');
        $this->_out('endobj');
    }

    /** Attach the embedded-file name tree to the document catalog. */
    public function _putcatalog()
    {
        parent::_putcatalog();
        if (
            $this->embeddedNamesObject === null
            || $this->embeddedFileSpecObjects === []
        ) {
            return;
        }
        $this->_out(
            '/Names <</EmbeddedFiles '
            . $this->embeddedNamesObject . ' 0 R>>'
        );
        $references = array_map(
            static fn (int $object): string => $object . ' 0 R',
            $this->embeddedFileSpecObjects
        );
        $this->_out('/AF [' . implode(' ', $references) . ']');
        $this->_out('/PageMode /UseAttachments');
    }
}
