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
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/logbook_numbering.php';
require_once __DIR__ . '/../4fbak/backup_pdf.php';

const ESTAB_INCIDENT_PDF_DEFAULT_ATTACHMENT_BYTES = 50 * 1024 * 1024;
const ESTAB_INCIDENT_PDF_MAX_ATTACHMENTS = 1000;

final class EstabIncidentPdfInputException extends InvalidArgumentException
{
}

/** Return the incident-owned command-post label without inventing history. */
function estab_incident_pdf_command_post_label(array $incident): string
{
    if (
        !array_key_exists('fuehrungsstellenname', $incident)
        || $incident['fuehrungsstellenname'] === null
    ) {
        return 'historisch nicht erfasst';
    }
    try {
        return estab_incident_command_post_name($incident);
    } catch (EstabIncidentConfigurationException $exception) {
        throw new EstabIncidentPdfInputException(
            'Incident command-post name is invalid.',
            0,
            $exception
        );
    }
}

/** Build the auditable ETB/TBB selection printed on the dossier cover. */
function estab_incident_pdf_logbook_scope_label(array $scope): string
{
    $mode = $scope['mode'] ?? 'all';
    if ($mode === 'all') {
        return 'Gesamtbuch (alle Dienstschichten einschließlich historischer '
            . 'Einträge ohne Schichtzuordnung)';
    }
    if ($mode !== 'shift') {
        throw new EstabIncidentPdfInputException(
            'Logbook scope metadata is invalid.'
        );
    }

    $shiftId = $scope['shift_id'] ?? null;
    $number = $scope['number'] ?? null;
    if (!is_int($shiftId) || $shiftId < 1 || !is_int($number) || $number < 1) {
        throw new EstabIncidentPdfInputException(
            'Shift scope metadata is incomplete.'
        );
    }
    $name = trim((string) ($scope['name'] ?? ''));
    $status = trim((string) ($scope['status'] ?? ''));
    $compactTime = static function (mixed $value): string {
        $time = trim((string) $value);
        return preg_replace('/\.0{1,6}\z/D', '', $time) ?? $time;
    };
    $createdAt = $compactTime($scope['created_at'] ?? '');
    $activatedAt = $compactTime($scope['activated_at'] ?? '');
    $endedAt = $compactTime($scope['ended_at'] ?? '');

    return implode(' · ', [
        'Nur Dienstschicht ' . $number
            . ($name !== '' ? ' · ' . $name : ''),
        'ID: ' . $shiftId,
        'Status: ' . ($status !== '' ? $status : 'nicht erfasst'),
        'erstellt: ' . ($createdAt !== '' ? $createdAt : 'nicht erfasst'),
        'aktiv: '
            . ($activatedAt !== '' ? $activatedAt : 'nicht erfasst'),
        'bis: ' . ($endedAt !== '' ? $endedAt : 'nicht erfasst'),
    ]);
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
    int $remainingBytes,
    ?string $expectedSha256 = null,
    ?int $expectedSize = null
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
    if (($expectedSha256 === null) !== ($expectedSize === null)) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment integrity evidence is incomplete.'
        );
    }
    if (
        $expectedSha256 !== null
        && (
            preg_match('/\A[0-9a-f]{64}\z/D', $expectedSha256) !== 1
            || $expectedSize < 0
        )
    ) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment integrity evidence is invalid.'
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

    $sha256 = hash('sha256', $data);
    if (
        $expectedSha256 !== null
        && (
            strlen($data) !== $expectedSize
            || !hash_equals($expectedSha256, $sha256)
        )
    ) {
        throw new EstabIncidentPdfInputException(
            'Embedded attachment differs from its ingest evidence.'
        );
    }

    return [
        'data' => $data,
        'size' => strlen($data),
        'sha256' => $sha256,
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
    private const LAYOUT_ETB_FORM = 'etb-form';
    private const LAYOUT_TBB_FORM = 'tbb-form';

    private const ETB_PAGE_TOTAL_ALIAS = '{etb#}';
    private const TBB_PAGE_TOTAL_ALIAS = '{tbb#}';
    private const THW_MARK_PATH = __DIR__ . '/../4fbak/thw.png';

    /** @var array{0:int,1:int,2:int} */
    private const ETB_TITLE_FILL = [32, 69, 138];

    /** @var array{0:int,1:int,2:int} */
    private const TBB_TITLE_FILL = [64, 55, 137];

    private string $commandPostLabel = 'Führungsstelle historisch nicht erfasst';
    private string $incidentLabel = 'Einsatz nicht erfasst';
    private string $commandPostName = 'historisch nicht erfasst';
    private string $logbookIncidentLabel = 'nicht erfasst';
    private string $incidentBeginDate = '';
    private string $tbbWorkplace = '';
    private bool $incidentClosed = false;
    private int $etbIdentifier = 0;
    private string $sectionTitle = 'Einsatzdossier';
    private int $attachmentByteLimit;
    private int $attachmentBytes = 0;
    private string $nextPageLayout = self::LAYOUT_DOSSIER;
    private string $nextLogbookPageDate = '';

    /** @var array<int,string> */
    private array $pageLayouts = [];

    /** @var array<int,int> */
    private array $logbookPageNumbers = [];

    /** @var array<int,string> */
    private array $logbookPageDates = [];

    /** @var array{ETB:int,TBB:int} */
    private array $logbookPageCounts = ['ETB' => 0, 'TBB' => 0];

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
        $incidentId = $incident['einsatz_id'] ?? null;
        if (
            is_int($incidentId)
            || (is_string($incidentId)
                && preg_match('/\A[1-9][0-9]*\z/D', $incidentId) === 1)
        ) {
            $this->etbIdentifier = (int) $incidentId;
        }
        $commandPostName = estab_incident_pdf_command_post_label($incident);
        $historicalCommandPost = !array_key_exists(
            'fuehrungsstellenname',
            $incident
        ) || $incident['fuehrungsstellenname'] === null;
        $this->commandPostLabel = (
            $historicalCommandPost
                ? 'Führungsstelle historisch nicht erfasst'
                : 'Führungsstelle: ' . $commandPostName
        );
        $this->incidentLabel = 'Einsatz: ' . $code . ' · ' . $name;
        $this->commandPostName = $commandPostName;
        $this->logbookIncidentLabel = $code . ' · ' . $name;
        $this->incidentClosed = (string) ($incident['estab_status'] ?? '')
            === 'closed';
        $this->incidentBeginDate = $this->logbookDate(
            $incident['beginn'] ?? ''
        );
        foreach ([
            'tbb_arbeitsplatz',
            'fernmeldearbeitsplatz',
            'arbeitsplatz',
        ] as $workplaceField) {
            if (!array_key_exists($workplaceField, $incident)) {
                continue;
            }
            $workplace = $incident[$workplaceField];
            if (
                is_array($workplace)
                || is_object($workplace)
                || is_resource($workplace)
            ) {
                throw new EstabIncidentPdfInputException(
                    'Incident TBB workplace must be scalar.'
                );
            }
            $workplace = trim((string) $workplace);
            if ($workplace !== '') {
                $this->tbbWorkplace = $workplace;
                break;
            }
        }
    }

    /**
     * Encode and shorten one PDF header line to its actual rendered width.
     *
     * Core-font output is single-byte after conversion. A binary search keeps
     * the result deterministic and avoids emitting text beyond the A4 header.
     */
    private function fittedHeaderLine(string $value, float $maximumWidth): string
    {
        $encoded = estab_incident_pdf_text($value);
        if ($this->GetStringWidth($encoded) <= $maximumWidth) {
            return $encoded;
        }

        $suffix = '...';
        $suffixWidth = $this->GetStringWidth($suffix);
        if ($suffixWidth > $maximumWidth) {
            return '';
        }
        $minimum = 0;
        $maximum = strlen($encoded);
        while ($minimum < $maximum) {
            $candidate = intdiv($minimum + $maximum + 1, 2);
            $candidateText = rtrim(substr($encoded, 0, $candidate));
            if (
                $this->GetStringWidth($candidateText) + $suffixWidth
                    <= $maximumWidth
            ) {
                $minimum = $candidate;
            } else {
                $maximum = $candidate - 1;
            }
        }
        return rtrim(substr($encoded, 0, $minimum)) . $suffix;
    }

    /** Return a stable German calendar date without changing its timezone. */
    private function logbookDate(mixed $value): string
    {
        if (is_array($value) || is_object($value) || is_resource($value)) {
            throw new EstabIncidentPdfInputException(
                'Logbook date must be scalar.'
            );
        }
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }
        if (
            preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})/D', $candidate, $match)
                === 1
        ) {
            return $match[3] . '.' . $match[2] . '.' . $match[1];
        }
        return $candidate;
    }

    /** Format one database timestamp for the narrow official table column. */
    private function logbookDateTime(mixed $value): string
    {
        if (is_array($value) || is_object($value) || is_resource($value)) {
            throw new EstabIncidentPdfInputException(
                'Logbook timestamp must be scalar.'
            );
        }
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }
        if (
            preg_match(
                '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})[ T]'
                    . '([0-9]{2}):([0-9]{2})/D',
                $candidate,
                $match
            ) === 1
        ) {
            return $match[3] . '.' . $match[2] . '.' . $match[1]
                . "\n" . $match[4] . ':' . $match[5];
        }
        return $candidate;
    }

    private function currentPageLayout(): string
    {
        return $this->pageLayouts[$this->PageNo()]
            ?? self::LAYOUT_DOSSIER;
    }

    public function AddPage($orientation = '', $format = '')
    {
        $pageNumber = $this->PageNo() + 1;
        $layout = $this->nextPageLayout;
        $this->pageLayouts[$pageNumber] = $layout;
        if ($layout === self::LAYOUT_ETB_FORM) {
            $this->logbookPageCounts['ETB']++;
            $this->logbookPageNumbers[$pageNumber] =
                $this->logbookPageCounts['ETB'];
            $this->logbookPageDates[$pageNumber] =
                $this->nextLogbookPageDate;
        } elseif ($layout === self::LAYOUT_TBB_FORM) {
            $this->logbookPageCounts['TBB']++;
            $this->logbookPageNumbers[$pageNumber] =
                $this->logbookPageCounts['TBB'];
        }
        parent::AddPage($orientation, $format);
    }

    private function isMessageFormPage(): bool
    {
        return $this->currentPageLayout() === self::LAYOUT_MESSAGE_FORM;
    }

    private function isLogbookFormPage(): bool
    {
        return in_array(
            $this->currentPageLayout(),
            [self::LAYOUT_ETB_FORM, self::LAYOUT_TBB_FORM],
            true
        );
    }

    private function configureDossierLayout(): void
    {
        $this->nextPageLayout = self::LAYOUT_DOSSIER;
        // FPDF restores the state that was active before AddPage(). Keep later
        // dossier and message-form pages independent from a preceding table.
        $this->SetDrawColor(0);
        $this->SetFillColor(0);
        $this->SetTextColor(0);
        $this->SetLineWidth(0.2);
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

    private function configureEtbFormLayout(string $pageDate): void
    {
        $this->nextPageLayout = self::LAYOUT_ETB_FORM;
        $this->nextLogbookPageDate = $pageDate !== ''
            ? $pageDate
            : $this->incidentBeginDate;
        $this->SetMargins(12, 50, 12);
        $this->SetAutoPageBreak(false, 0);
    }

    private function configureTbbFormLayout(): void
    {
        $this->nextPageLayout = self::LAYOUT_TBB_FORM;
        $this->SetMargins(8, 48, 8);
        $this->SetAutoPageBreak(false, 0);
    }

    /** Draw the title band shared by Fb Fü 2 and Fb Fü 44. */
    private function drawLogbookTitleBand(
        float $x,
        float $y,
        float $width,
        string $formCode,
        string $title,
        float $codeWidth,
        float $markWidth,
        float $height,
        array $titleFill
    ): void {
        if (
            count($titleFill) !== 3
            || array_filter(
                $titleFill,
                static fn (mixed $component): bool => !is_int($component)
                    || $component < 0
                    || $component > 255
            ) !== []
        ) {
            throw new EstabIncidentPdfInputException(
                'Logbook title color is invalid.'
            );
        }
        if (!is_file(self::THW_MARK_PATH) || is_link(self::THW_MARK_PATH)) {
            throw new EstabIncidentPdfInputException(
                'THW header mark is unavailable.'
            );
        }

        $titleWidth = $width - $codeWidth - $markWidth;
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(43, 55, 73);
        $this->SetFillColor(
            $titleFill[0],
            $titleFill[1],
            $titleFill[2]
        );
        $this->SetTextColor(23, 32, 51);
        $this->SetFont('helvetica', 'B', 7.5);
        $this->SetXY($x, $y);
        $this->Cell(
            $codeWidth,
            $height,
            estab_incident_pdf_text($formCode),
            1,
            0,
            'C'
        );
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(
            $titleWidth,
            $height,
            estab_incident_pdf_text($title),
            1,
            0,
            'C',
            true
        );
        $markX = $x + $codeWidth + $titleWidth;
        $this->SetXY($markX, $y);
        $this->Cell($markWidth, $height, '', 1, 0);

        $markPadding = 1.0;
        $symbolSize = max(1.0, $height - 2.0 * $markPadding);
        $symbolX = $markX + $markWidth - $symbolSize - $markPadding;
        $symbolY = $y + $markPadding;
        $wordmarkWidth = max(
            1.0,
            $symbolX - $markX - 1.5 * $markPadding
        );
        $this->SetTextColor(23, 47, 77);
        $this->SetFont('helvetica', 'B', $height >= 9.0 ? 6.6 : 6.0);
        $this->SetXY($markX + $markPadding, $y + 0.7);
        $this->Cell(
            $wordmarkWidth,
            ($height - 1.4) / 2.0,
            estab_incident_pdf_text('Technisches'),
            0,
            0,
            'C'
        );
        $this->SetXY(
            $markX + $markPadding,
            $y + ($height - 1.4) / 2.0
        );
        $this->Cell(
            $wordmarkWidth,
            ($height - 1.4) / 2.0,
            estab_incident_pdf_text('Hilfswerk'),
            0,
            0,
            'C'
        );
        $this->Image(
            self::THW_MARK_PATH,
            $symbolX,
            $symbolY,
            $symbolSize,
            $symbolSize,
            'PNG'
        );
        $this->SetTextColor(23, 32, 51);
    }

    /** Draw centered, wrapped text inside an already outlined table cell. */
    private function drawCenteredCellText(
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        float $lineHeight
    ): void {
        $lines = $this->wrappedTextLines($width, $text);
        $textHeight = count($lines) * $lineHeight;
        $lineY = $y + max(0.0, ($height - $textHeight) / 2.0);
        foreach ($lines as $line) {
            $this->SetXY($x, $lineY);
            $this->Cell($width, $lineHeight, $line, 0, 0, 'C');
            $lineY += $lineHeight;
        }
    }

    /** Draw the official four-column Fb Fü 2 page head. */
    private function drawEtbHeader(): void
    {
        $x = 12.0;
        $width = 186.0;
        $this->drawLogbookTitleBand(
            $x,
            9.0,
            $width,
            'Fb Fü 2',
            'Einsatztagebuch',
            24.0,
            40.0,
            9.0,
            self::ETB_TITLE_FILL
        );

        $this->SetDrawColor(43, 55, 73);
        $this->SetTextColor(23, 32, 51);
        $this->Rect($x, 20.0, 146.0, 14.0);
        $this->SetFont('helvetica', 'B', 7.5);
        $this->SetXY($x + 2.0, 24.2);
        $this->Cell(20.0, 4.0, estab_incident_pdf_text('Einsatz:'), 0, 0);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetXY($x + 22.0, 24.2);
        $this->Cell(
            122.0,
            4.0,
            $this->fittedHeaderLine($this->logbookIncidentLabel, 120.0),
            0,
            0
        );

        $page = $this->PageNo();
        $bookPage = $this->logbookPageNumbers[$page] ?? 1;
        $pageDate = $this->logbookPageDates[$page]
            ?? $this->incidentBeginDate;
        $this->Rect($x + 146.0, 20.0, 40.0, 14.0);
        $this->SetFont('helvetica', 'B', 7.0);
        $this->SetXY($x + 148.0, 21.5);
        $this->Cell(11.0, 4.0, estab_incident_pdf_text('Datum:'), 0, 0);
        $this->SetFont('helvetica', '', 7.0);
        $this->Cell(
            25.0,
            4.0,
            $this->fittedHeaderLine($pageDate, 24.0),
            0,
            0
        );
        $this->SetFont('helvetica', 'B', 7.0);
        $this->SetXY($x + 148.0, 27.2);
        $this->Cell(10.0, 4.0, estab_incident_pdf_text('Seite:'), 0, 0);
        $this->SetFont('helvetica', '', 7.0);
        $this->Cell(
            26.0,
            4.0,
            estab_incident_pdf_text(
                $bookPage . ' von ' . self::ETB_PAGE_TOTAL_ALIAS
            ),
            0,
            0
        );

        $widths = [14.0, 34.0, 100.0, 38.0];
        $labels = [
            "Lfd.\nNr.",
            'Datum/Uhrzeit',
            'Darstellung der Ereignisse',
            'Bemerkungen',
        ];
        $headerY = 36.0;
        $headerHeight = 13.0;
        $cellX = $x;
        $this->SetFillColor(235, 237, 240);
        $this->SetFont('helvetica', 'B', 7.2);
        foreach ($widths as $index => $cellWidth) {
            $this->Rect($cellX, $headerY, $cellWidth, $headerHeight, 'DF');
            $this->drawCenteredCellText(
                $cellX,
                $headerY,
                $cellWidth,
                $headerHeight,
                $labels[$index],
                3.6
            );
            $cellX += $cellWidth;
        }
        $this->SetXY($x, 49.0);
    }

    /** Draw the official seven-column Fb Fü 44 page head. */
    private function drawTbbHeader(): void
    {
        $x = 8.0;
        $width = 281.0;
        $this->drawLogbookTitleBand(
            $x,
            6.0,
            $width,
            'Fb Fü 44',
            'Technisches Betriebsbuch',
            23.0,
            46.0,
            8.0,
            self::TBB_TITLE_FILL
        );

        $page = $this->PageNo();
        $bookPage = $this->logbookPageNumbers[$page] ?? 1;
        $detailY = 16.0;
        $detailHeight = 9.0;
        $detailWidths = [150.0, 95.0, 36.0];
        $detailLabels = [
            ['Fernmeldebetriebsstelle:', $this->commandPostName],
            ['Arbeitsplatz:', $this->tbbWorkplace],
            [
                'Seite:',
                $bookPage . ' von ' . self::TBB_PAGE_TOTAL_ALIAS,
            ],
        ];
        $detailX = $x;
        foreach ($detailWidths as $index => $detailWidth) {
            $this->Rect(
                $detailX,
                $detailY,
                $detailWidth,
                $detailHeight
            );
            $label = $detailLabels[$index][0];
            $value = $detailLabels[$index][1];
            $labelWidth = $index === 0 ? 42.0 : ($index === 1 ? 25.0 : 11.0);
            $this->SetFont('helvetica', 'B', 6.5);
            $this->SetXY($detailX + 1.5, $detailY + 2.5);
            $this->Cell(
                $labelWidth,
                4.0,
                estab_incident_pdf_text($label),
                0,
                0
            );
            $this->SetFont('helvetica', '', 6.5);
            $this->Cell(
                max(1.0, $detailWidth - $labelWidth - 3.0),
                4.0,
                $this->fittedHeaderLine(
                    $value,
                    max(1.0, $detailWidth - $labelWidth - 4.0)
                ),
                0,
                0
            );
            $detailX += $detailWidth;
        }

        $widths = [10.0, 22.0, 63.0, 48.0, 40.0, 68.0, 30.0];
        $labels = [
            "Lfd.\nNr.",
            "Datum/\nUhrzeit",
            "- Einsatz- und Betriebsbereitschaft\n"
                . "- Namen des Betriebspersonals\n"
                . "- Ablösungen\n"
                . "- Dienst übergeben/übernommen\n"
                . '- Betriebsende',
            "- Kanal\n- Bedingung\n- Kanalwechsel durchgeführt\n"
                . '(alter/neuer Kanal)',
            'Nachricht an/von',
            "Betriebsablauf/Ereignis\nStörung/Störungsbeseitigung",
            "Quittung\nEmpfänger\nAusgehändigt",
        ];
        $headerY = 27.0;
        $headerHeight = 20.0;
        $cellX = $x;
        $this->SetFillColor(235, 237, 240);
        $this->SetFont('helvetica', 'B', 5.4);
        foreach ($widths as $index => $cellWidth) {
            $this->Rect($cellX, $headerY, $cellWidth, $headerHeight, 'DF');
            $this->drawCenteredCellText(
                $cellX,
                $headerY,
                $cellWidth,
                $headerHeight,
                $labels[$index],
                2.9
            );
            $cellX += $cellWidth;
        }
        $this->SetXY($x, 47.0);
    }

    public function Header()
    {
        if ($this->isMessageFormPage()) {
            parent::Header();
            return;
        }
        if ($this->currentPageLayout() === self::LAYOUT_ETB_FORM) {
            $this->drawEtbHeader();
            return;
        }
        if ($this->currentPageLayout() === self::LAYOUT_TBB_FORM) {
            $this->drawTbbHeader();
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
        $headerWidth = max(1.0, $this->w - 34.0);
        $this->SetFont('helvetica', '', 8);
        $this->SetX(16);
        $this->Cell(
            0,
            3.5,
            $this->fittedHeaderLine(
                $this->commandPostLabel,
                $headerWidth
            ),
            0,
            1
        );
        $this->SetFont('helvetica', '', 7.5);
        $this->SetX(16);
        $this->Cell(
            0,
            3.5,
            $this->fittedHeaderLine($this->incidentLabel, $headerWidth),
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
        if ($this->currentPageLayout() === self::LAYOUT_ETB_FORM) {
            $this->SetDrawColor(43, 55, 73);
            $this->SetLineWidth(0.25);
            $this->Line(37.0, $this->h - 17.0, 84.0, $this->h - 17.0);
            $this->Line(126.0, $this->h - 17.0, 173.0, $this->h - 17.0);
            $this->SetTextColor(23, 32, 51);
            $this->SetFont('helvetica', '', 6.5);
            $this->SetXY(32.0, $this->h - 15.5);
            $this->Cell(
                57.0,
                4.0,
                estab_incident_pdf_text('Leiter/-in Führungsstelle'),
                0,
                0,
                'C'
            );
            $this->SetXY(121.0, $this->h - 15.5);
            $this->Cell(
                57.0,
                4.0,
                estab_incident_pdf_text('ETB-Führer/-in'),
                0,
                0,
                'C'
            );
            return;
        }
        if ($this->currentPageLayout() === self::LAYOUT_TBB_FORM) {
            $this->SetDrawColor(43, 55, 73);
            $this->SetLineWidth(0.25);
            $this->Line(18.0, $this->h - 13.0, 88.0, $this->h - 13.0);
            $this->SetTextColor(23, 32, 51);
            $this->SetFont('helvetica', '', 6.5);
            $this->SetXY(18.0, $this->h - 11.5);
            $this->Cell(
                70.0,
                4.0,
                estab_incident_pdf_text('Leiter/-in Fernmeldebetrieb (LdF)'),
                0,
                0,
                'C'
            );
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
        if ($this->isLogbookFormPage()) {
            return false;
        }
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

    /** Replace section-local page totals before FPDF compresses page streams. */
    public function _putpages()
    {
        foreach ($this->pages as $page => $content) {
            $this->pages[$page] = str_replace(
                [self::ETB_PAGE_TOTAL_ALIAS, self::TBB_PAGE_TOTAL_ALIAS],
                [
                    (string) $this->logbookPageCounts['ETB'],
                    (string) $this->logbookPageCounts['TBB'],
                ],
                $content
            );
        }
        parent::_putpages();
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
        $this->MultiCell(
            0,
            5.5,
            estab_incident_pdf_text($valueText),
            0,
            'L'
        );
    }

    private function recordHeading(string $text): void
    {
        $this->ensureSpace(14);
        $this->SetFillColor(226, 233, 241);
        $this->SetTextColor(23, 47, 77);
        $this->SetFont('helvetica', 'B', 10);
        $this->MultiCell(
            0,
            7,
            estab_incident_pdf_text($text),
            0,
            'L',
            true
        );
        $this->SetTextColor(23, 32, 51);
    }

    private function statusText(
        bool $valid,
        bool $terminalBindingComplete = true
    ): string
    {
        if ($valid && !$terminalBindingComplete) {
            return 'HASHKETTE GÜLTIG - historischer Import ohne '
                . 'belegbare Live-Bindung';
        }
        return $valid
            ? 'GÜLTIG - vollständig neu berechnet'
            : 'UNGÜLTIG - Abweichung in Kette oder Nachweiskopf';
    }

    /**
     * @param array<string,int> $counts
     * @param array{
     *   message?:array<string,mixed>,
     *   operations?:array<string,mixed>
     * } $evidence
     * @param array<string,mixed> $logbookScope
     */
    public function addCover(
        array $incident,
        array $selectedSections,
        array $counts,
        string $generatedAt,
        string $generatedBy,
        array $evidence = [],
        array $logbookScope = []
    ): void {
        $this->beginSection('Einsatzdossier');
        $this->heading('Einsatzdossier', 1);
        $closed = (string) ($incident['estab_status'] ?? '') === 'closed';
        $this->SetFillColor(
            $closed ? 24 : 174,
            $closed ? 120 : 62,
            $closed ? 78 : 62
        );
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 14);
        $this->MultiCell(
            0,
            11,
            estab_incident_pdf_text(
                $closed
                    ? 'FORMAL ABGESCHLOSSEN'
                    : 'VORLÄUFIG – Einsatz nicht formal abgeschlossen'
            ),
            0,
            'C',
            true
        );
        $this->Ln(3);
        $this->SetTextColor(23, 32, 51);
        $this->paragraph(
            'Revisionsdossier aus einem konsistenten Datenbank-Snapshot. '
            . 'Nachweisstatus und Head-Hashes wurden aus den geladenen '
            . 'Ereignisreihen neu berechnet. Anhänge mit Eingangsnachweis '
            . 'wurden vor dem Einbetten gegen SHA-256 und Größe geprüft. '
            . 'Legacy-Anhänge sind ausdrücklich als „Integrität beim Eingang '
            . 'nicht belegbar“ gekennzeichnet.'
        );
        $this->heading('Einsatz', 2);
        foreach ([
            'Kennung' => $incident['kennung'] ?? '',
            'Name' => $incident['name'] ?? '',
            'Beginn' => $incident['beginn'] ?? '',
            'Ende' => $incident['ende'] ?? '',
            'Ort' => $incident['ort'] ?? '',
            'Organisation' => $incident['organisation'] ?? '',
            'Führungsstelle' => estab_incident_pdf_command_post_label($incident),
            'Einsatzleitung' => $incident['einsatzleitung'] ?? '',
            'Beschreibung' => $incident['beschreibung'] ?? '',
        ] as $label => $value) {
            $this->definition($label, $value);
        }

        $this->heading('Formaler Abschluss und Aufbewahrung', 2);
        $this->definition(
            'Formaler Status',
            strtoupper((string) ($incident['estab_status'] ?? 'unbekannt'))
        );
        $this->definition(
            'Abschluss',
            implode(' · ', [
                'Zeit: ' . (string) ($incident['estab_closed_at'] ?? '-'),
                'Durch: ' . (string) ($incident['estab_closed_by'] ?? '-'),
            ])
        );
        $this->definition(
            'Abschlussvermerk',
            $incident['estab_close_note'] ?? ''
        );
        $this->definition(
            'Aufbewahrung bis',
            $incident['estab_retain_until'] ?? ''
        );
        $legalHold = (int) ($incident['estab_legal_hold'] ?? 0) === 1;
        $this->definition(
            'Legal Hold',
            ($legalHold ? 'AKTIV' : 'nicht aktiv')
                . ' · Zeitpunkt: '
                . (string) ($incident['estab_legal_hold_at'] ?? '-')
                . ' · Durch: '
                . (string) ($incident['estab_legal_hold_by'] ?? '-')
        );
        $this->definition(
            'Legal-Hold-Grund',
            $incident['estab_legal_hold_reason'] ?? ''
        );

        $messageEvidence = is_array($evidence['message'] ?? null)
            ? $evidence['message']
            : [];
        $operationsEvidence = is_array($evidence['operations'] ?? null)
            ? $evidence['operations']
            : [];
        $messageValid = ($messageEvidence['valid'] ?? false) === true;
        $operationsValid = ($operationsEvidence['valid'] ?? false) === true;
        $this->heading('Revisionsnachweise', 2);
        $this->definition(
            'Nachrichten-Nachweis',
            $this->statusText(
                $messageValid,
                ($messageEvidence['terminal_binding_complete'] ?? false)
                    === true
            )
                . ' · Ereignisse: '
                . (string) ($messageEvidence['event_count'] ?? 0)
                . ' · Köpfe: '
                . (string) ($messageEvidence['head_count'] ?? 0)
                . ' · Abweichungen: '
                . (string) ($messageEvidence['head_mismatches'] ?? 0)
                . ' · Historisch ohne Live-Bindung: '
                . (string) (
                    $messageEvidence['terminal_unverifiable'] ?? 0
                )
        );
        $this->definition(
            'Nachrichten-Head-Summenhash',
            $messageEvidence['head_set_sha256'] ?? ''
        );
        $this->definition(
            'Betriebsnachweis',
            $this->statusText($operationsValid)
                . ' · Ereignisse: '
                . (string) ($operationsEvidence['event_count'] ?? 0)
                . ' · Kopfsequenz: '
                . (string) ($operationsEvidence['stored_sequence'] ?? 0)
        );
        $operationsHead = (string) (
            $operationsEvidence['stored_head_sha256'] ?? ''
        );
        $calculatedOperationsHead = (string) (
            $operationsEvidence['calculated_head_sha256'] ?? ''
        );
        if (
            $calculatedOperationsHead !== ''
            && $operationsHead !== $calculatedOperationsHead
        ) {
            $operationsHead .= ' · berechnet: ' . $calculatedOperationsHead;
        }
        $this->definition('Betriebs-Head-Hash', $operationsHead);

        $this->heading('Umfang', 2);
        $this->definition(
            'Logbuchauswahl',
            estab_incident_pdf_logbook_scope_label($logbookScope)
        );
        $labels = [
            'etb' => 'ETB',
            'ttb' => 'TBB',
            'messages' => 'Nachrichtenvordrucke',
            'attachments' => 'Originalanhänge',
            'message_evidence' => 'Nachrichten-Nachweis',
            'duty' => 'Dienstorganisation',
            's6_plans' => 'S6-Fernmeldeplanung',
            'courier' => 'Melderaufträge',
            'operations_evidence' => 'Betriebsnachweis',
        ];
        $scope = [];
        foreach ($labels as $key => $label) {
            if (in_array($key, $selectedSections, true)) {
                $scope[] = $label . ': ' . (string) ($counts[$key] ?? 0);
            }
        }
        $this->definition('Ausgewählte Bereiche', implode(' · ', $scope));
        if (in_array('attachments', $selectedSections, true)) {
            $this->definition(
                'Anhangintegrität',
                'verifiziert: '
                    . (string) ($counts['attachments_verified'] ?? 0)
                    . ' · Integrität beim Eingang nicht belegbar: '
                    . (string) ($counts['attachments_legacy'] ?? 0)
            );
        }
        $this->definition(
            'Erzeugung',
            $generatedAt . ' · Administrationszugang: ' . $generatedBy
        );
    }

    /** Return one optional scalar row field without inventing a value. */
    private function logbookRowValue(array $row, string $field): string
    {
        if (!array_key_exists($field, $row) || $row[$field] === null) {
            return '';
        }
        $value = $row[$field];
        if (is_array($value) || is_object($value) || is_resource($value)) {
            throw new EstabIncidentPdfInputException(
                'Logbook field must be scalar: ' . $field
            );
        }
        return trim((string) $value);
    }

    /** Return the first populated scalar among compatible schema aliases. */
    private function firstLogbookRowValue(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = $this->logbookRowValue($row, $field);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Join distinct populated row values, optionally with an official label.
     *
     * @param list<array{0:string,1:string}> $fields
     */
    private function joinedLogbookRowValues(array $row, array $fields): string
    {
        $values = [];
        foreach ($fields as [$field, $label]) {
            $value = $this->logbookRowValue($row, $field);
            if ($value === '') {
                continue;
            }
            $line = $label . $value;
            if (!in_array($line, $values, true)) {
                $values[] = $line;
            }
        }
        return implode("\n", $values);
    }

    /** Append a non-empty unique line to one printable table field. */
    private function appendLogbookLine(
        string $content,
        string $value,
        string $label = ''
    ): string {
        $value = trim($value);
        if ($value === '') {
            return $content;
        }
        $line = $label . $value;
        $lines = $content === '' ? [] : explode("\n", $content);
        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** Render the session-bound author without adding a non-official column. */
    private function logbookAuthor(array $row, string $prefix): string
    {
        if (
            strtolower($this->logbookRowValue(
                $row,
                $prefix . '_kuerzel'
            )) === 'system'
        ) {
            return 'Automatisch durch eStab erzeugt';
        }
        $parts = [];
        foreach (['benutzer', 'kuerzel', 'funktion'] as $suffix) {
            $value = $this->logbookRowValue(
                $row,
                $prefix . '_' . $suffix
            );
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return implode(' - ', $parts);
    }

    /**
     * Wrap an encoded core-font string exactly within one FPDF cell width.
     *
     * @return list<string>
     */
    private function wrappedTextLines(float $width, mixed $value): array
    {
        $text = estab_incident_pdf_text($value);
        $text = str_replace("\r", '', $text);
        if ($text === '') {
            return [''];
        }
        $fontWidths = $this->CurrentFont['cw'] ?? null;
        if (!is_array($fontWidths) || $this->FontSize <= 0) {
            throw new EstabIncidentPdfInputException(
                'PDF font is not ready for logbook wrapping.'
            );
        }
        $maximumWidth = max(1.0, $width - 2.0 * $this->cMargin)
            * 1000.0 / $this->FontSize;
        $length = strlen($text);
        while ($length > 0 && $text[$length - 1] === "\n") {
            $length--;
        }
        if ($length === 0) {
            return [''];
        }

        $lines = [];
        $separator = -1;
        $lineStart = 0;
        $position = 0;
        $lineWidth = 0.0;
        while ($position < $length) {
            $character = $text[$position];
            if ($character === "\n") {
                $lines[] = rtrim(
                    substr($text, $lineStart, $position - $lineStart)
                );
                $position++;
                $separator = -1;
                $lineStart = $position;
                $lineWidth = 0.0;
                continue;
            }
            if ($character === ' ') {
                $separator = $position;
            }
            $lineWidth += (float) ($fontWidths[$character] ?? 600);
            if ($lineWidth > $maximumWidth) {
                if ($separator < $lineStart) {
                    if ($position === $lineStart) {
                        $position++;
                    }
                    $lines[] = rtrim(
                        substr($text, $lineStart, $position - $lineStart)
                    );
                    $lineStart = $position;
                } else {
                    $lines[] = rtrim(
                        substr($text, $lineStart, $separator - $lineStart)
                    );
                    $position = $separator + 1;
                    $lineStart = $position;
                }
                $separator = -1;
                $lineWidth = 0.0;
                continue;
            }
            $position++;
        }
        if ($position > $lineStart) {
            $lines[] = rtrim(substr($text, $lineStart, $position - $lineStart));
        }
        return $lines === [] ? [''] : $lines;
    }

    /** Draw the fine ruled writing grid used by the official paper forms. */
    private function drawLogbookWritingGrid(
        float $x,
        float $y,
        float $width,
        float $height,
        float $lineHeight,
        float $padding,
        float $reservedTop = 0.0
    ): void {
        if (
            $width <= 0.0
            || $height <= 0.0
            || $lineHeight <= 0.0
            || $padding < 0.0
            || $reservedTop < 0.0
        ) {
            throw new EstabIncidentPdfInputException(
                'Logbook writing grid dimensions are invalid.'
            );
        }

        $bottom = $y + $height;
        $ruleY = $y + $padding + $lineHeight;
        $firstAllowedRule = $y + $reservedTop;
        $this->SetDrawColor(190, 196, 205);
        $this->SetLineWidth(0.08);
        while ($ruleY < $bottom - 0.1) {
            if ($ruleY > $firstAllowedRule + 0.01) {
                $this->Line($x, $ruleY, $x + $width, $ruleY);
            }
            $ruleY += $lineHeight;
        }
    }

    /** Draw one vertically aligned table-row fragment on the current page. */
    private function drawLogbookRowChunk(
        array $widths,
        array $wrappedColumns,
        int $lineOffset,
        int $lineCount,
        float $lineHeight,
        float $padding,
        float $minimumHeight,
        bool $continuation
    ): float {
        $rowY = $this->GetY();
        $rowHeight = max(
            $minimumHeight,
            $lineCount * $lineHeight + 2.0 * $padding
        );
        $cellX = $this->lMargin;
        $this->drawLogbookWritingGrid(
            $cellX,
            $rowY,
            array_sum($widths),
            $rowHeight,
            $lineHeight,
            $padding
        );
        $this->SetDrawColor(70, 78, 90);
        $this->SetLineWidth(0.18);
        foreach ($widths as $index => $cellWidth) {
            $this->Rect($cellX, $rowY, $cellWidth, $rowHeight);
            $lines = array_slice(
                $wrappedColumns[$index] ?? [''],
                $lineOffset,
                $lineCount
            );
            if ($continuation && $index === 0) {
                $lines = [($wrappedColumns[0][0] ?? '')];
            } elseif ($continuation && $index === 1) {
                $lines = [estab_incident_pdf_text('Fortsetzung')];
            }
            $lineY = $rowY + $padding;
            foreach ($lines as $line) {
                $this->SetXY($cellX, $lineY);
                $this->Cell(
                    $cellWidth,
                    $lineHeight,
                    $line,
                    0,
                    0,
                    $index <= 1 ? 'C' : 'L'
                );
                $lineY += $lineHeight;
            }
            $cellX += $cellWidth;
        }
        $this->SetXY($this->lMargin, $rowY + $rowHeight);
        return $rowHeight;
    }

    private function startLogbookPage(string $kind, string $date): void
    {
        if ($kind === 'ETB') {
            $this->configureEtbFormLayout($date);
            $this->AddPage('P', 'A4');
            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(23, 32, 51);
            return;
        }
        $this->configureTbbFormLayout();
        $this->AddPage('L', 'A4');
        $this->SetFont('helvetica', '', 6.0);
        $this->SetTextColor(23, 32, 51);
    }

    private function logbookBodyBottom(string $kind): float
    {
        return $kind === 'ETB' ? $this->h - 23.0 : $this->h - 19.0;
    }

    /**
     * Mark the unused remainder of the last closed form as not writable.
     *
     * The THW examples require the empty part of the final ETB/TBB sheet to
     * be crossed out. Keeping the column grid visible also makes the digital
     * form immediately comparable with a closed paper book.
     *
     * @param list<float> $widths
     */
    private function strikeUnusedLogbookArea(
        string $kind,
        array $widths
    ): void {
        if (!$this->incidentClosed) {
            return;
        }
        $top = $this->GetY();
        $bottom = $this->logbookBodyBottom($kind);
        if ($bottom - $top < 3.0) {
            return;
        }

        $left = $this->lMargin;
        $right = $left + array_sum($widths);
        $lineHeight = $kind === 'ETB' ? 4.1 : 3.35;
        $padding = $kind === 'ETB' ? 1.4 : 1.0;
        $this->drawLogbookWritingGrid(
            $left,
            $top,
            $right - $left,
            $bottom - $top,
            $lineHeight,
            $padding,
            6.0
        );
        $this->SetDrawColor(70, 78, 90);
        $this->SetLineWidth(0.18);
        $this->Rect($left, $top, $right - $left, $bottom - $top);
        $columnX = $left;
        foreach (array_slice($widths, 0, -1) as $width) {
            $columnX += $width;
            $this->Line($columnX, $top, $columnX, $bottom);
        }
        $this->SetLineWidth(0.45);
        $this->Line($left, $bottom, $right, $top);
        $this->SetXY($left, $top);
        $this->SetFont('helvetica', 'I', $kind === 'ETB' ? 7.0 : 6.0);
        $this->Cell(
            $right - $left,
            min(6.0, $bottom - $top),
            estab_incident_pdf_text('Nicht beschriebener Bereich'),
            0,
            0,
            'C'
        );
        $this->SetY($bottom);
    }

    /**
     * @return array{columns:list<string>,date:string}
     */
    private function etbPrintableRow(array $row): array
    {
        $number = $this->firstLogbookRowValue(
            $row,
            ['estab_book_lfd', 'estab_buch_lfd', 'lfd']
        );
        $recordedAt = $this->firstLogbookRowValue(
            $row,
            ['estab_recorded_at', 'etb_time', 'estab_event_time']
        );
        $event = $this->firstLogbookRowValue(
            $row,
            ['etb_aktion', 'estab_betriebsvorgang', 'event']
        );
        $remarks = $this->firstLogbookRowValue(
            $row,
            ['etb_bemerk', 'comment']
        );

        $attachmentId = $this->logbookRowValue(
            $row,
            'estab_attachment_id'
        );
        if ($attachmentId !== '') {
            $attachmentNumber = $this->logbookRowValue(
                $row,
                'estab_attachment_number'
            );
            if (
                $attachmentNumber === ''
                && $this->etbIdentifier > 0
                && preg_match('/\A[1-9][0-9]*\z/D', $number) === 1
            ) {
                $attachmentNumber = estab_logbook_etb_attachment_number(
                    $this->etbIdentifier,
                    (int) $number
                );
            }
            $remarks = $this->appendLogbookLine(
                $remarks,
                $attachmentNumber !== ''
                    ? $attachmentNumber
                    : '#' . $attachmentId,
                'Anlage: '
            );
        }
        $correctionOf = $this->logbookRowValue(
            $row,
            'estab_correction_book_lfd'
        );
        $hasCorrectionLink = $correctionOf !== ''
            || $this->logbookRowValue($row, 'estab_correction_of') !== '';
        $reference = $this->logbookRowValue($row, 'estab_reference');
        if ($reference !== '' && !$hasCorrectionLink) {
            $remarks = $this->appendLogbookLine(
                $remarks,
                $reference,
                'Referenz: '
            );
        }
        if ($correctionOf !== '') {
            $remarks = $this->appendLogbookLine(
                $remarks,
                $correctionOf,
                'Korrektur zu ETB-Nr.: '
            );
        } elseif ($this->logbookRowValue($row, 'estab_correction_of') !== '') {
            $remarks = $this->appendLogbookLine(
                $remarks,
                'Korrekturverweis vorhanden; lokale ETB-Nr. nicht auflösbar.'
            );
        }
        $author = $this->logbookAuthor($row, 'etb');
        if ($author !== '') {
            $remarks = $this->appendLogbookLine(
                $remarks,
                $author,
                'Erfasst durch: '
            );
        }

        return [
            'columns' => [
                $number,
                $this->logbookDateTime($recordedAt),
                $event,
                $remarks,
            ],
            'date' => $this->logbookDate($recordedAt),
        ];
    }

    /**
     * @return array{columns:list<string>,date:string}
     */
    private function tbbPrintableRow(array $row): array
    {
        $number = $this->firstLogbookRowValue(
            $row,
            ['estab_book_lfd', 'estab_buch_lfd', 'lfd']
        );
        $eventTime = $this->firstLogbookRowValue(
            $row,
            ['estab_event_time', 'tbb_time', 'estab_recorded_at']
        );
        $service = $this->joinedLogbookRowValues($row, [
            ['estab_personnel_duty', ''],
            ['estab_dienst_personal', ''],
            ['estab_tbb_dienst_personal', ''],
            ['tbb_dienst_personal', ''],
            ['tbb_betrieb_personal', ''],
            ['tbb_bereitschaft', ''],
        ]);
        $channel = $this->joinedLogbookRowValues($row, [
            ['estab_channel', 'Kanal/Rufgruppe: '],
            ['estab_kanal_rufgruppe', 'Kanal/Rufgruppe: '],
            ['estab_tbb_kanal_rufgruppe', 'Kanal/Rufgruppe: '],
            ['tbb_kanal_rufgruppe', 'Kanal/Rufgruppe: '],
            ['tbb_kanal', 'Kanal/Rufgruppe: '],
            ['estab_verbindungszustand', 'Verbindungszustand: '],
            ['estab_tbb_verbindungszustand', 'Verbindungszustand: '],
            ['tbb_verbindungszustand', 'Verbindungszustand: '],
        ]);
        $message = $this->joinedLogbookRowValues($row, [
            ['estab_message_route', ''],
            ['estab_nachricht_von', 'Von: '],
            ['estab_tbb_nachricht_von', 'Von: '],
            ['tbb_nachricht_von', 'Von: '],
            ['estab_nachricht_an', 'An: '],
            ['estab_tbb_nachricht_an', 'An: '],
            ['tbb_nachricht_an', 'An: '],
        ]);
        $operation = $this->joinedLogbookRowValues($row, [
            ['estab_operations', ''],
            ['estab_betriebsvorgang', ''],
            ['estab_tbb_betriebsvorgang', ''],
            ['tbb_betriebsvorgang', ''],
            ['estab_stoerung_abstellung', 'Störung/Abstellung: '],
            ['estab_tbb_stoerung_abstellung', 'Störung/Abstellung: '],
            ['tbb_stoerung_abstellung', 'Störung/Abstellung: '],
        ]);
        $receipt = $this->joinedLogbookRowValues($row, [
            ['estab_receipt', ''],
            ['estab_empfang_nachweis', ''],
            ['estab_tbb_empfang_nachweis', ''],
            ['tbb_empfang_nachweis', ''],
            ['tbb_quittung', ''],
        ]);

        // New rows keep a compatibility summary in tbb_aktion, while the
        // official form must place each fact in exactly one of its five
        // specialist columns. Use legacy text only when no structured
        // content is available at all.
        if (
            $service === ''
            && $channel === ''
            && $message === ''
            && $operation === ''
            && $receipt === ''
        ) {
            $operation = $this->appendLogbookLine(
                $operation,
                $this->logbookRowValue($row, 'tbb_aktion')
            );
        }
        // tbb_bemerk is not the compatibility summary. It carries the
        // separately entered correction reason, handover summary or closing
        // note and therefore remains part of the official operations column.
        // appendLogbookLine also prevents duplication after legacy backfill.
        $operation = $this->appendLogbookLine(
            $operation,
            $this->logbookRowValue($row, 'tbb_bemerk'),
            'Bemerkung: '
        );
        $correctionOf = $this->logbookRowValue(
            $row,
            'estab_correction_book_lfd'
        );
        if ($correctionOf !== '') {
            $operation = $this->appendLogbookLine(
                $operation,
                $correctionOf,
                'Korrektur zu TBB-Nr.: '
            );
        } elseif ($this->logbookRowValue($row, 'estab_correction_of') !== '') {
            $operation = $this->appendLogbookLine(
                $operation,
                'Korrekturverweis vorhanden; lokale TBB-Nr. nicht auflösbar.'
            );
        }
        $author = $this->logbookAuthor($row, 'tbb');
        if ($author !== '') {
            $operation = $this->appendLogbookLine(
                $operation,
                $author,
                'Erfasst durch: '
            );
        }

        return [
            'columns' => [
                $number,
                $this->logbookDateTime($eventTime),
                $service,
                $channel,
                $message,
                $operation,
                $receipt,
            ],
            'date' => $this->logbookDate($eventTime),
        ];
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
        if ($this->logbookPageCounts[$kind] !== 0) {
            throw new EstabIncidentPdfInputException(
                'Logbook section was already rendered.'
            );
        }

        // Correction links use stable global primary keys internally. The
        // official forms must show the incident-local running number instead.
        $localNumberByGlobalId = [];
        $globalIdField = $kind === 'ETB' ? 'etb_lfd-nr' : 'tbb_lfd-nr';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new EstabIncidentPdfInputException(
                    'Logbook rows must be arrays.'
                );
            }
            $globalId = $this->logbookRowValue($row, $globalIdField);
            $localNumber = $this->firstLogbookRowValue(
                $row,
                ['estab_book_lfd', 'estab_buch_lfd']
            );
            if ($globalId !== '' && $localNumber !== '') {
                $localNumberByGlobalId[$globalId] = $localNumber;
            }
        }

        $printableRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new EstabIncidentPdfInputException(
                    'Logbook rows must be arrays.'
                );
            }
            $correctionOf = $this->logbookRowValue(
                $row,
                'estab_correction_of'
            );
            if (
                $correctionOf !== ''
                && array_key_exists($correctionOf, $localNumberByGlobalId)
            ) {
                $row['estab_correction_book_lfd'] =
                    $localNumberByGlobalId[$correctionOf];
            }
            $printableRows[] = $kind === 'ETB'
                ? $this->etbPrintableRow($row)
                : $this->tbbPrintableRow($row);
        }
        if ($printableRows === []) {
            $printableRows[] = [
                'columns' => $kind === 'ETB'
                    ? ['', '', 'Keine Einträge vorhanden.', '']
                    : ['', '', '', '', '', 'Keine Einträge vorhanden.', ''],
                'date' => $this->incidentBeginDate,
            ];
        }

        $widths = $kind === 'ETB'
            ? [14.0, 34.0, 100.0, 38.0]
            : [10.0, 22.0, 63.0, 48.0, 40.0, 68.0, 30.0];
        $lineHeight = $kind === 'ETB' ? 4.1 : 3.35;
        $padding = $kind === 'ETB' ? 1.4 : 1.0;
        $minimumHeight = $kind === 'ETB' ? 8.0 : 7.0;

        $firstDate = (string) ($printableRows[0]['date'] ?? '');
        $this->startLogbookPage($kind, $firstDate);
        foreach ($printableRows as $printableRow) {
            $columns = $printableRow['columns'] ?? null;
            if (!is_array($columns) || count($columns) !== count($widths)) {
                throw new EstabIncidentPdfInputException(
                    'Printable logbook row is incomplete.'
                );
            }
            $this->SetFont(
                'helvetica',
                '',
                $kind === 'ETB' ? 7.5 : 6.0
            );
            $wrappedColumns = [];
            $maximumLines = 1;
            foreach ($columns as $index => $column) {
                $wrapped = $this->wrappedTextLines($widths[$index], $column);
                $wrappedColumns[] = $wrapped;
                $maximumLines = max($maximumLines, count($wrapped));
            }

            $bodyTop = $kind === 'ETB' ? 49.0 : 47.0;
            $bodyBottom = $this->logbookBodyBottom($kind);
            $fullHeight = max(
                $minimumHeight,
                $maximumLines * $lineHeight + 2.0 * $padding
            );
            $available = $bodyBottom - $this->GetY();
            if (
                $this->GetY() > $bodyTop + 0.1
                && $fullHeight <= $bodyBottom - $bodyTop
                && $fullHeight > $available
            ) {
                $this->startLogbookPage(
                    $kind,
                    (string) ($printableRow['date'] ?? '')
                );
            }

            $offset = 0;
            while ($offset < $maximumLines) {
                $available = $bodyBottom - $this->GetY();
                $remaining = $maximumLines - $offset;
                $chunkLines = min(
                    $remaining,
                    max(
                        0,
                        (int) floor(
                            ($available - 2.0 * $padding) / $lineHeight
                        )
                    )
                );
                while (
                    $chunkLines > 0
                    && max(
                        $minimumHeight,
                        $chunkLines * $lineHeight + 2.0 * $padding
                    ) > $available + 0.001
                ) {
                    $chunkLines--;
                }
                if ($chunkLines < 1) {
                    $this->startLogbookPage(
                        $kind,
                        (string) ($printableRow['date'] ?? '')
                    );
                    continue;
                }
                $this->drawLogbookRowChunk(
                    $widths,
                    $wrappedColumns,
                    $offset,
                    $chunkLines,
                    $lineHeight,
                    $padding,
                    $minimumHeight,
                    $offset > 0
                );
                $offset += $chunkLines;
                if ($offset < $maximumLines) {
                    $this->startLogbookPage(
                        $kind,
                        (string) ($printableRow['date'] ?? '')
                    );
                }
            }
        }
        $this->strikeUnusedLogbookArea($kind, $widths);
        $this->configureDossierLayout();
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $heads
     * @param array<string,mixed> $status
     */
    public function addMessageEvidence(
        array $events,
        array $heads,
        array $status
    ): void {
        $this->beginSection('Nachrichtennachweis');
        $this->heading('Nachrichtenereignisse und Nachweisköpfe', 1);
        $this->paragraph(
            'Die nachfolgenden Hashketten wurden für diesen Export aus '
            . 'sämtlichen geladenen Ereignissen neu berechnet und mit den '
            . 'persistierten, einsatzgebundenen Nachweisköpfen verglichen.'
        );
        $this->heading('Prüfergebnis', 2);
        $this->definition(
            'Status',
            $this->statusText(
                ($status['valid'] ?? false) === true,
                ($status['terminal_binding_complete'] ?? false) === true
            )
        );
        $this->definition('Ereignisse', $status['event_count'] ?? 0);
        $this->definition('Nachrichten', $status['message_count'] ?? 0);
        $this->definition('Nachweisköpfe', $status['head_count'] ?? 0);
        $this->definition(
            'Kopfabweichungen',
            $status['head_mismatches'] ?? 0
        );
        $this->definition(
            'Erstes fehlerhaftes Event',
            $status['broken_event_id'] ?? ''
        );
        $this->definition(
            'Terminalbindungen',
            (string) ($status['terminal_count'] ?? 0)
                . ' · Abweichungen: '
                . (string) ($status['terminal_mismatches'] ?? 0)
        );
        $this->definition(
            'Historisch nicht belegbar',
            $status['terminal_unverifiable'] ?? 0
        );
        $this->definition(
            'Head-Summenhash',
            $status['head_set_sha256'] ?? ''
        );

        $this->heading('Nachweisköpfe', 2);
        if ($heads === []) {
            $this->paragraph(
                'Für diesen Einsatz sind keine Nachrichtennachweisköpfe '
                . 'vorhanden.'
            );
        }
        foreach ($heads as $head) {
            if (!is_array($head)) {
                throw new EstabIncidentPdfInputException(
                    'Message evidence heads must be arrays.'
                );
            }
            $this->recordHeading(
                'Nachweiskopf Nachricht '
                    . (string) ($head['message_id'] ?? '')
            );
            $this->definition('Einsatz-ID', $head['einsatz_id'] ?? '');
            $this->definition('Nachrichten-ID', $head['message_id'] ?? '');
            $this->definition('Ereignisanzahl', $head['event_count'] ?? '');
            $this->definition(
                'Letzter Event-Hash',
                $head['last_event_sha256'] ?? ''
            );
            $this->definition('Aktualisiert', $head['updated_at'] ?? '');
            $this->Ln(2);
        }

        $this->heading('Unveränderliche Nachrichtenereignisse', 2);
        if ($events === []) {
            $this->paragraph(
                'Für diesen Einsatz sind keine Nachrichtenereignisse vorhanden.'
            );
            return;
        }
        foreach ($events as $event) {
            if (!is_array($event)) {
                throw new EstabIncidentPdfInputException(
                    'Message evidence rows must be arrays.'
                );
            }
            $this->recordHeading(
                'Nachrichtenereignis '
                    . (string) ($event['event_id'] ?? '')
                    . ' · Nachricht '
                    . (string) ($event['message_id'] ?? '')
            );
            foreach ([
                'Einsatz-ID' => 'einsatz_id',
                'Nachrichten-ID' => 'message_id',
                'Ereignistyp' => 'event_type',
                'Ereigniszeit' => 'occurred_at',
                'Erfassungszeit' => 'recorded_at',
                'Akteur' => 'actor_user',
                'Akteurskürzel' => 'actor_code',
                'Akteursfunktion' => 'actor_function',
                'Status vorher' => 'from_status',
                'Status nachher' => 'to_status',
                'Feldsnapshot (JSON)' => 'field_snapshot',
                'Snapshot-SHA-256' => 'snapshot_sha256',
                'Vorgänger-Event-Hash' => 'previous_event_sha256',
                'Event-SHA-256' => 'event_sha256',
            ] as $label => $field) {
                $this->definition($label, $event[$field] ?? '');
            }
            $this->Ln(3);
        }
    }

    /**
     * @param list<array<string,mixed>> $shifts
     * @param list<array<string,mixed>> $assignments
     * @param list<array<string,mixed>> $handovers
     * @param list<array<string,mixed>> $handoverRequests
     */
    public function addDutyRecords(
        array $shifts,
        array $assignments,
        array $handovers,
        array $handoverRequests = []
    ): void {
        $this->beginSection('Dienstorganisation');
        $this->heading('Dienstschichten, Besetzungen und Übergaben', 1);

        $this->heading('Dienstschichten', 2);
        if ($shifts === []) {
            $this->paragraph('Es sind keine Dienstschichten vorhanden.');
        }
        foreach ($shifts as $shift) {
            if (!is_array($shift)) {
                throw new EstabIncidentPdfInputException(
                    'Duty shifts must be arrays.'
                );
            }
            $this->recordHeading(
                'Dienstschicht '
                    . (string) ($shift['nummer'] ?? '')
                    . ' · '
                    . (string) ($shift['bezeichnung'] ?? '')
            );
            foreach ([
                'Dienstschicht-ID' => 'dienstschicht_id',
                'Einsatz-ID' => 'einsatz_id',
                'Nummer' => 'nummer',
                'Bezeichnung' => 'bezeichnung',
                'Status' => 'status',
                'Vorgänger-ID' => 'vorgaenger_id',
                'Erstellt am' => 'erstellt_am',
                'Erstellt von' => 'erstellt_von',
                'Aktiviert am' => 'aktiviert_am',
                'Beendet am' => 'beendet_am',
            ] as $label => $field) {
                $this->definition($label, $shift[$field] ?? '');
            }
            $this->Ln(3);
        }

        $this->heading('Dienstbesetzungen', 2);
        if ($assignments === []) {
            $this->paragraph('Es sind keine Dienstbesetzungen vorhanden.');
        }
        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) {
                throw new EstabIncidentPdfInputException(
                    'Duty assignments must be arrays.'
                );
            }
            $this->recordHeading(
                'Besetzung '
                    . (string) ($assignment['dienstbesetzung_id'] ?? '')
                    . ' · Schicht '
                    . (string) ($assignment['dienstschicht_nummer'] ?? '')
                    . ' · '
                    . (string) ($assignment['funktion'] ?? '')
            );
            foreach ([
                'Dienstbesetzung-ID' => 'dienstbesetzung_id',
                'Dienstschicht-ID' => 'dienstschicht_id',
                'Schichtnummer' => 'dienstschicht_nummer',
                'Benutzerkürzel' => 'benutzer_kuerzel',
                'Funktion' => 'funktion',
                'Rolle' => 'rolle',
                'Status' => 'status',
                'Zugewiesen am' => 'zugewiesen_am',
                'Zugewiesen von' => 'zugewiesen_von',
                'Angenommen am' => 'angenommen_am',
                'Abgelöst am' => 'abgeloest_am',
                'Nachfolger-ID' => 'nachfolger_id',
            ] as $label => $field) {
                $this->definition($label, $assignment[$field] ?? '');
            }
            $this->Ln(3);
        }

        $this->heading('Übergabeanforderungen', 2);
        if ($handoverRequests === []) {
            $this->paragraph(
                'Es sind keine Übergabeanforderungen vorhanden.'
            );
        }
        foreach ($handoverRequests as $request) {
            if (!is_array($request)) {
                throw new EstabIncidentPdfInputException(
                    'Duty handover requests must be arrays.'
                );
            }
            $this->recordHeading(
                'Übergabeanforderung '
                    . (string) (
                        $request['dienstuebergabe_anfrage_id'] ?? ''
                    )
                    . ' · '
                    . (string) ($request['status'] ?? '')
            );
            foreach ([
                'Übergabeanforderung-ID' =>
                    'dienstuebergabe_anfrage_id',
                'Einsatz-ID' => 'einsatz_id',
                'Von Dienstschicht' => 'von_dienstschicht_id',
                'An Dienstschicht' => 'an_dienstschicht_id',
                'Zusammenfassung' => 'zusammenfassung',
                'Status' => 'status',
                'Initiiert am' => 'initiiert_am',
                'Initiiert von' => 'initiiert_von',
                'Bestätigt am' => 'bestaetigt_am',
                'Bestätigt von' => 'bestaetigt_von',
                'Bestätigende Besetzung-ID' =>
                    'bestaetigt_mit_besetzung_id',
                'Finale Dienstübergabe-ID' => 'dienstuebergabe_id',
                'Storniert am' => 'storniert_am',
                'Storniert von' => 'storniert_von',
                'Stornierungsgrund' => 'stornierungsgrund',
            ] as $label => $field) {
                $this->definition($label, $request[$field] ?? '');
            }
            $this->Ln(3);
        }

        $this->heading('Bestätigte Dienstübergaben', 2);
        if ($handovers === []) {
            $this->paragraph('Es sind keine Dienstübergaben vorhanden.');
        }
        foreach ($handovers as $handover) {
            if (!is_array($handover)) {
                throw new EstabIncidentPdfInputException(
                    'Duty handovers must be arrays.'
                );
            }
            $this->recordHeading(
                'Dienstübergabe '
                    . (string) ($handover['dienstuebergabe_id'] ?? '')
            );
            foreach ([
                'Dienstübergabe-ID' => 'dienstuebergabe_id',
                'Einsatz-ID' => 'einsatz_id',
                'Von Dienstschicht' => 'von_dienstschicht_id',
                'An Dienstschicht' => 'an_dienstschicht_id',
                'Zusammenfassung' => 'zusammenfassung',
                'Übernahme bestätigt am' => 'uebergeben_am',
                'Übergeben von' => 'uebergeben_von',
                'Angenommen von' => 'angenommen_von',
            ] as $label => $field) {
                $this->definition($label, $handover[$field] ?? '');
            }
            $this->Ln(3);
        }
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @param list<array<string,mixed>> $entries
     */
    public function addS6Plans(array $plans, array $entries): void
    {
        $entriesByPlan = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new EstabIncidentPdfInputException(
                    'S6 plan entries must be arrays.'
                );
            }
            $planId = (int) ($entry['fernmeldeplan_id'] ?? 0);
            if ($planId < 1) {
                throw new EstabIncidentPdfInputException(
                    'S6 plan entries require a plan ID.'
                );
            }
            $entriesByPlan[$planId][] = $entry;
        }

        $this->beginSection('S6-Fernmeldeplanung');
        $this->heading('S6-Fernmeldeplanversionen und Einträge', 1);
        if ($plans === []) {
            if ($entries !== []) {
                throw new EstabIncidentPdfInputException(
                    'S6 plan entries have no incident plan.'
                );
            }
            $this->paragraph(
                'Für diesen Einsatz sind keine S6-Fernmeldepläne vorhanden.'
            );
            return;
        }
        $seenPlans = [];
        foreach ($plans as $plan) {
            if (!is_array($plan)) {
                throw new EstabIncidentPdfInputException(
                    'S6 plans must be arrays.'
                );
            }
            $planId = (int) ($plan['fernmeldeplan_id'] ?? 0);
            if ($planId < 1 || isset($seenPlans[$planId])) {
                throw new EstabIncidentPdfInputException(
                    'S6 plans require unique plan IDs.'
                );
            }
            $seenPlans[$planId] = true;
            $this->recordHeading(
                'S6-Fernmeldeplan Version '
                    . (string) ($plan['version'] ?? '')
                    . ' · '
                    . (string) ($plan['status'] ?? '')
            );
            foreach ([
                'Fernmeldeplan-ID' => 'fernmeldeplan_id',
                'Einsatz-ID' => 'einsatz_id',
                'Version' => 'version',
                'Status' => 'status',
                'Einsatzbezeichnung' => 'einsatzbezeichnung',
                'Herkunft' => 'herkunft',
                'Gültig ab' => 'gueltig_ab',
                'Gültig bis' => 'gueltig_bis',
                'Betriebsleitung' => 'betriebsleitung',
                'Bemerkungen' => 'bemerkungen',
                'Erstellt am' => 'erstellt_am',
                'Erstellt von' => 'erstellt_von',
                'Freigegeben am' => 'freigegeben_am',
                'Freigegeben von' => 'freigegeben_von',
            ] as $label => $field) {
                $this->definition($label, $plan[$field] ?? '');
            }

            $planEntries = $entriesByPlan[$planId] ?? [];
            $this->heading(
                'Einträge der Version '
                    . (string) ($plan['version'] ?? ''),
                2
            );
            if ($planEntries === []) {
                $this->paragraph('Diese Planversion enthält keine Einträge.');
            }
            foreach ($planEntries as $entry) {
                $this->recordHeading(
                    'Planeintrag '
                        . (string) (
                            $entry['fernmeldeplan_eintrag_id'] ?? ''
                        )
                        . ' · '
                        . (string) ($entry['rufname'] ?? '')
                );
                foreach ([
                    'Planeintrag-ID' => 'fernmeldeplan_eintrag_id',
                    'Fernmeldeplan-ID' => 'fernmeldeplan_id',
                    'Planversion' => 'plan_version',
                    'Sortierung' => 'sortierung',
                    'Betriebsstelle' => 'betriebsstelle',
                    'Rufname' => 'rufname',
                    'Medium' => 'medium',
                    'Kanal' => 'kanal',
                    'Bandlage' => 'bandlage',
                    'Verkehrsform' => 'verkehrsform',
                    'Besondere Vermerke' => 'besondere_vermerke',
                    'Bemerkungen' => 'bemerkungen',
                ] as $label => $field) {
                    $this->definition($label, $entry[$field] ?? '');
                }
                $this->Ln(2);
            }
            unset($entriesByPlan[$planId]);
            $this->Ln(3);
        }
        if ($entriesByPlan !== []) {
            throw new EstabIncidentPdfInputException(
                'S6 plan entries refer to a missing incident plan.'
            );
        }
    }

    /** @param list<array<string,mixed>> $orders */
    public function addCourierOrders(array $orders): void
    {
        $this->beginSection('Melderaufträge');
        $this->heading('Melderaufträge', 1);
        $this->paragraph(
            'Dargestellt ist die vollständige Auftrags-, Empfänger-, '
            . 'Rückweg- und Abschlusskette jedes Melderauftrags.'
        );
        if ($orders === []) {
            $this->paragraph(
                'Für diesen Einsatz sind keine Melderaufträge vorhanden.'
            );
            return;
        }
        foreach ($orders as $order) {
            if (!is_array($order)) {
                throw new EstabIncidentPdfInputException(
                    'Courier orders must be arrays.'
                );
            }
            $this->recordHeading(
                'Melderauftrag '
                    . (string) ($order['melderauftrag_id'] ?? '')
                    . ' · '
                    . (string) ($order['status'] ?? '')
            );
            foreach ([
                'Melderauftrag-ID' => 'melderauftrag_id',
                'Einsatz-ID' => 'einsatz_id',
                'Nachrichten-ID' => 'nachricht_id',
                'Melderkürzel' => 'melder_kuerzel',
                'Ziel' => 'ziel',
                'Status' => 'status',
                'Beauftragt am' => 'beauftragt_am',
                'Beauftragt von' => 'beauftragt_von',
                'Übernommen am' => 'uebernommen_am',
                'Tatsächlicher Empfänger' => 'tatsaechlicher_empfaenger',
                'Übergeben am' => 'uebergeben_am',
                'Rücknachricht vorhanden' => 'ruecknachricht_vorhanden',
                'Rücknachricht' => 'ruecknachricht',
                'Rückweg am' => 'rueckweg_am',
                'Zurück am' => 'zurueck_am',
                'Abschlussvermerk' => 'abschlussvermerk',
                'Gemeldet am' => 'gemeldet_am',
                'Gemeldet an' => 'gemeldet_an',
                'Abgebrochen am' => 'abgebrochen_am',
                'Abbruchgrund' => 'abbruchgrund',
            ] as $label => $field) {
                $value = $order[$field] ?? null;
                if ($field === 'ruecknachricht_vorhanden') {
                    $value = $value === null
                        ? 'Nicht dokumentiert'
                        : ((int) $value === 1 ? 'Ja' : 'Nein');
                }
                $this->definition($label, $value);
            }
            $this->Ln(3);
        }
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $heads
     * @param array<string,mixed> $status
     */
    public function addOperationsEvidence(
        array $events,
        array $heads,
        array $status
    ): void {
        $this->beginSection('Betriebsnachweis');
        $this->heading('Betriebsereignisse und Nachweiskopf', 1);
        $this->paragraph(
            'Die einsatzweite Betriebsereigniskette umfasst Dienstbetrieb, '
            . 'S6-Fernmeldeplanung und Melderbeförderung. Sequenzen, '
            . 'Vorgänger- und Ereignishashes wurden neu berechnet.'
        );
        $this->heading('Prüfergebnis', 2);
        $this->definition(
            'Status',
            $this->statusText(($status['valid'] ?? false) === true)
        );
        $this->definition('Ereignisse', $status['event_count'] ?? 0);
        $this->definition(
            'Fehlerhafte Sequenz',
            $status['failed_sequence'] ?? ''
        );
        $this->definition(
            'Berechneter Head-Hash',
            $status['calculated_head_sha256'] ?? ''
        );
        $this->definition(
            'Gespeicherte Kopfsequenz',
            $status['stored_sequence'] ?? 0
        );
        $this->definition(
            'Gespeicherter Head-Hash',
            $status['stored_head_sha256'] ?? ''
        );

        $this->heading('Persistierter Nachweiskopf', 2);
        if ($heads === []) {
            $this->paragraph(
                'Für die leere Betriebskette ist kein persistierter Kopf '
                . 'vorhanden.'
            );
        }
        foreach ($heads as $head) {
            if (!is_array($head)) {
                throw new EstabIncidentPdfInputException(
                    'Operations evidence heads must be arrays.'
                );
            }
            $this->recordHeading('Betriebsnachweiskopf');
            $this->definition('Einsatz-ID', $head['einsatz_id'] ?? '');
            $this->definition(
                'Letzte Sequenz',
                $head['letzte_sequenz'] ?? ''
            );
            $this->definition('Letzter Hash', $head['letzter_hash'] ?? '');
        }

        $this->heading('Unveränderliche Betriebsereignisse', 2);
        if ($events === []) {
            $this->paragraph(
                'Für diesen Einsatz sind keine Betriebsereignisse vorhanden.'
            );
            return;
        }
        foreach ($events as $event) {
            if (!is_array($event)) {
                throw new EstabIncidentPdfInputException(
                    'Operations evidence rows must be arrays.'
                );
            }
            $this->recordHeading(
                'Betriebsereignis Sequenz '
                    . (string) ($event['sequenz'] ?? '')
                    . ' · '
                    . (string) ($event['objekttyp'] ?? '')
            );
            foreach ([
                'Betriebsereignis-ID' => 'betriebsereignis_id',
                'Einsatz-ID' => 'einsatz_id',
                'Sequenz' => 'sequenz',
                'Objekttyp' => 'objekttyp',
                'Objekt-ID' => 'objekt_id',
                'Aktion' => 'aktion',
                'Akteurskürzel' => 'akteur_kuerzel',
                'Akteursfunktion' => 'akteur_funktion',
                'Ereigniszeit' => 'ereigniszeit',
                'Details (JSON)' => 'details_json',
                'Vorgänger-Hash' => 'vorheriger_hash',
                'Ereignis-Hash' => 'ereignis_hash',
            ] as $label => $field) {
                $this->definition($label, $event[$field] ?? '');
            }
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
        string $description,
        ?string $expectedSha256 = null,
        ?int $expectedSize = null
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
        $file = estab_incident_pdf_read_attachment(
            $path,
            $remaining,
            $expectedSha256,
            $expectedSize
        );
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
     *     integrity_state?:string,
     *     integrity_statement?:string,
     *     archive_name?:string,
     *     etb_attachment_numbers?:list<string>,
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
            $archiveName = trim((string) (
                $attachment['archive_name'] ?? ''
            ));
            if ($archiveName !== '') {
                $this->definition('Ablagekennzeichen', $archiveName);
            }
            $etbNumbers = $attachment['etb_attachment_numbers'] ?? [];
            if (!is_array($etbNumbers)) {
                throw new EstabIncidentPdfInputException(
                    'ETB attachment numbers must be an array.'
                );
            }
            $validatedEtbNumbers = [];
            foreach ($etbNumbers as $etbNumber) {
                if (
                    !is_string($etbNumber)
                    || estab_logbook_parse_etb_attachment_number(
                        $etbNumber
                    ) === null
                ) {
                    throw new EstabIncidentPdfInputException(
                        'ETB attachment number is invalid.'
                    );
                }
                $validatedEtbNumbers[] = $etbNumber;
            }
            $this->definition(
                'ETB-Anlagennummer',
                $validatedEtbNumbers !== []
                    ? implode(', ', array_values(array_unique(
                        $validatedEtbNumbers
                    )))
                    : 'Nicht als ETB-Anlage zugeordnet'
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
            $this->definition(
                'SHA-256 der eingebetteten Datei',
                $attachment['sha256'] ?? ''
            );
            $integrityState = (string) (
                $attachment['integrity_state'] ?? ''
            );
            $this->definition(
                'Integrität beim Eingang',
                $integrityState === 'verified'
                    ? 'Belegt: SHA-256 und Größe stimmen mit dem '
                        . 'unveränderbaren Eingangsnachweis überein.'
                    : 'Integrität beim Eingang nicht belegbar'
            );
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
