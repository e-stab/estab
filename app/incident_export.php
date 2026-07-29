<?php

declare(strict_types=1);

/**
 * Read-only incident dossier boundary.
 *
 * Every database query is scoped to one explicitly selected incident.  The
 * controller may therefore export the active incident as well as a closed,
 * historical incident without changing global state.
 */

require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/incident_pdf.php';
require_once __DIR__ . '/file_access.php';

final class EstabIncidentExportInputException extends InvalidArgumentException
{
}

final class EstabIncidentExportDataException extends RuntimeException
{
}

/**
 * Validate the four user-selectable dossier sections.
 *
 * @return list<string>
 */
function estab_incident_export_sections(mixed $input): array
{
    if (!is_array($input)) {
        throw new EstabIncidentExportInputException(
            'Die Auswahl für den PDF-Export ist ungültig.'
        );
    }

    $mapping = [
        'include_etb' => 'etb',
        'include_ttb' => 'ttb',
        'include_messages' => 'messages',
        'include_attachments' => 'attachments',
    ];
    $selected = [];
    foreach ($mapping as $field => $section) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        if ($input[$field] !== '1') {
            throw new EstabIncidentExportInputException(
                'Die Auswahl für den PDF-Export ist ungültig.'
            );
        }
        $selected[] = $section;
    }

    if ($selected === []) {
        throw new EstabIncidentExportInputException(
            'Wählen Sie mindestens einen Inhalt für das Einsatzdossier aus.'
        );
    }
    if (
        in_array('attachments', $selected, true)
        && !in_array('messages', $selected, true)
    ) {
        throw new EstabIncidentExportInputException(
            'Originalanhänge können nur zusammen mit den Nachrichtenvordrucken '
                . 'exportiert werden.'
        );
    }
    return $selected;
}

/**
 * Parse the semicolon-separated legacy message attachment field.
 *
 * @return list<string>
 */
function estab_incident_export_message_attachments(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_string($value) || strlen($value) > 65535) {
        throw new EstabIncidentExportDataException(
            'Eine Nachricht enthält eine ungültige Anhangliste.'
        );
    }

    $names = [];
    foreach (explode(';', $value) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        try {
            $name = estab_file_validate_name('attachment', $candidate);
        } catch (InvalidArgumentException $exception) {
            throw new EstabIncidentExportDataException(
                'Eine Nachricht verweist auf einen ungültigen Anhang.',
                0,
                $exception
            );
        }
        $names[$name] = true;
    }
    return array_keys($names);
}

/** Return a portable, non-secret download filename. */
function estab_incident_export_filename(
    array $incident,
    ?DateTimeImmutable $generatedAt = null
): string {
    $id = estab_incident_positive_id($incident['einsatz_id'] ?? null);
    $identifier = strtoupper(trim((string) ($incident['kennung'] ?? '')));
    if (
        preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{1,63}\z/D', $identifier) !== 1
    ) {
        throw new EstabIncidentExportDataException(
            'Die Einsatzkennung kann nicht als Dateiname verwendet werden.'
        );
    }
    $portable = strtolower(str_replace(
        ['/', '_', '.'],
        '-',
        $identifier
    ));
    $portable = preg_replace('/-+/', '-', $portable) ?? '';
    $generatedAt ??= new DateTimeImmutable('now');

    return sprintf(
        'estab-einsatz-%d-%s-%s.pdf',
        $id,
        $portable,
        $generatedAt->format('Ymd-His')
    );
}

/** Build a unique embedded-file name within FPDF's portable 181-byte limit. */
function estab_incident_export_embedded_name(
    int $position,
    string $storedName
): string {
    if ($position < 1 || $position > ESTAB_INCIDENT_PDF_MAX_ATTACHMENTS) {
        throw new EstabIncidentExportDataException(
            'Die Position eines Einsatzanhangs ist ungültig.'
        );
    }
    try {
        $storedName = estab_file_validate_name('attachment', $storedName);
    } catch (InvalidArgumentException $exception) {
        throw new EstabIncidentExportDataException(
            'Der gespeicherte Name eines Einsatzanhangs ist ungültig.',
            0,
            $exception
        );
    }

    $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    $base = pathinfo($storedName, PATHINFO_FILENAME);
    $prefix = sprintf('Anlage-%04d-', $position);
    $digest = substr(hash('sha256', $storedName), 0, 12);
    $suffix = '-' . $digest . '.' . $extension;
    $available = 181 - strlen($prefix) - strlen($suffix);
    $base = substr($base, 0, max(1, $available));

    return estab_incident_pdf_attachment_name($prefix . $base . $suffix);
}

/**
 * Execute one prepared incident-scoped SELECT and return all rows.
 *
 * @return list<array<string,mixed>>
 */
function estab_incident_export_rows(
    mysqli $connection,
    string $sql,
    int $incidentId,
    string $failure
): array {
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabIncidentExportDataException($failure);
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new EstabIncidentExportDataException($failure);
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new EstabIncidentExportDataException($failure);
        }
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

/**
 * Load the complete, immutable source bundle for one dossier.
 *
 * Missing files are a hard error when originals were requested.  The export
 * must never look complete after silently omitting an attachment.
 *
 * @return array{
 *   incident:array<string,mixed>,
 *   sections:list<string>,
 *   etb:list<array<string,mixed>>,
 *   ttb:list<array<string,mixed>>,
 *   messages:list<array<string,mixed>>,
 *   attachment_names_by_message:array<int,list<string>>,
 *   attachments:list<array<string,mixed>>,
 *   counts:array<string,int>
 * }
 */
function estab_incident_export_load(
    mysqli $connection,
    int $incidentId,
    array $sections,
    string $attachmentRoot
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $sections = estab_incident_export_sections(array_combine(
        array_map(
            static fn (string $section): string => 'include_' . $section,
            $sections
        ),
        array_fill(0, count($sections), '1')
    ) ?: []);
    $incident = estab_incident_find($connection, $incidentId);
    if (!is_array($incident)) {
        throw new EstabIncidentNotFoundException(
            'Der ausgewählte Einsatz wurde nicht gefunden.'
        );
    }

    $etb = [];
    if (in_array('etb', $sections, true)) {
        $etb = estab_incident_export_rows(
            $connection,
            'SELECT `etb_lfd-nr`, `etb_time`, `etb_aktion`, `etb_bemerk`,'
                . ' `etb_benutzer`, `etb_kuerzel`, `etb_funktion`'
                . ' FROM `nv_etb` WHERE `einsatz_id` = ?'
                . ' ORDER BY `etb_time`, `etb_lfd-nr`',
            $incidentId,
            'Das Einsatztagebuch konnte nicht gelesen werden.'
        );
    }

    $ttb = [];
    if (in_array('ttb', $sections, true)) {
        $ttb = estab_incident_export_rows(
            $connection,
            'SELECT `tbb_lfd-nr`, `tbb_time`, `tbb_aktion`, `tbb_bemerk`,'
                . ' `tbb_benutzer`, `tbb_kuerzel`, `tbb_funktion`'
                . ' FROM `nv_tbb` WHERE `einsatz_id` = ?'
                . ' ORDER BY `tbb_time`, `tbb_lfd-nr`',
            $incidentId,
            'Das Technische Betriebsbuch konnte nicht gelesen werden.'
        );
    }

    $messages = [];
    $attachmentNamesByMessage = [];
    if (in_array('messages', $sections, true)) {
        $messages = estab_incident_export_rows(
            $connection,
            'SELECT `00_lfd`, `01_medium`, `01_datum`, `01_zeichen`,'
                . ' `02_zeit`, `02_zeichen`, `03_datum`, `03_zeichen`,'
                . ' `04_richtung`, `04_nummer`, `05_gegenstelle`,'
                . ' `06_befweg`, `08_befhinweis`, `09_vorrangstufe`,'
                . ' `10_anschrift`, `12_anhang`, `12_inhalt`, `12_abfzeit`,'
                . ' `13_abseinheit`, `14_zeichen`, `14_funktion`,'
                . ' `15_quitdatum`, `15_quitzeichen`, `16_empf`,'
                . ' `17_vermerke`, `x00_status`'
                . ' FROM `nv_nachrichten` WHERE `einsatz_id` = ?'
                . ' ORDER BY COALESCE(`01_datum`, `12_abfzeit`), `00_lfd`',
            $incidentId,
            'Die Nachrichtenvordrucke konnten nicht gelesen werden.'
        );
        foreach ($messages as $message) {
            $messageId = (int) ($message['00_lfd'] ?? 0);
            if ($messageId < 1) {
                throw new EstabIncidentExportDataException(
                    'Ein Nachrichtendatensatz hat keine gültige Kennung.'
                );
            }
            $attachmentNamesByMessage[$messageId] =
                estab_incident_export_message_attachments(
                    $message['12_anhang'] ?? null
                );
        }
    }

    $attachments = [];
    if (in_array('attachments', $sections, true)) {
        $attachmentRows = estab_incident_export_rows(
            $connection,
            'SELECT `lfd-nr`, `filename`, `fileext`, `org_filename`,'
                . ' `comment`, `date`, `kuerzel`'
                . ' FROM `nv_anhang`'
                . ' WHERE `einsatz_id` = ? AND `status` = 1'
                . ' ORDER BY `filename`, `lfd-nr`',
            $incidentId,
            'Die Anhänge konnten nicht gelesen werden.'
        );

        $messageIdsByName = [];
        foreach ($attachmentNamesByMessage as $messageId => $names) {
            foreach ($names as $name) {
                $messageIdsByName[$name][] = $messageId;
            }
        }

        foreach ($attachmentRows as $position => $attachment) {
            $base = (string) ($attachment['filename'] ?? '');
            $extension = strtolower((string) ($attachment['fileext'] ?? ''));
            $storedName = $base . '.' . $extension;
            try {
                $storedName = estab_file_validate_name(
                    'attachment',
                    $storedName
                );
                $path = estab_file_resolve(
                    $attachmentRoot,
                    'attachment',
                    $storedName
                );
            } catch (InvalidArgumentException | RuntimeException $exception) {
                throw new EstabIncidentExportDataException(
                    'Ein abgeschlossener Einsatzanhang fehlt oder ist unsicher: '
                        . $storedName,
                    0,
                    $exception
                );
            }

            $original = trim((string) ($attachment['org_filename'] ?? ''));
            $comment = trim((string) ($attachment['comment'] ?? ''));
            $embeddedName = estab_incident_export_embedded_name(
                $position + 1,
                $storedName
            );
            $attachments[] = [
                'path' => $path,
                'stored_name' => $storedName,
                'embedded_name' => $embeddedName,
                'display_name' => $original !== ''
                    ? $storedName . ' · ' . $original
                    : $storedName,
                'description' => $comment !== ''
                    ? $comment
                    : 'Originalanhang aus eStab',
                'mime' => estab_file_content_type($path),
                'message_ids' => array_values(array_unique(
                    $messageIdsByName[$storedName] ?? []
                )),
            ];
        }

        $knownNames = array_fill_keys(
            array_column($attachments, 'stored_name'),
            true
        );
        foreach ($messageIdsByName as $name => $messageIds) {
            if (!isset($knownNames[$name])) {
                throw new EstabIncidentExportDataException(
                    'Ein Nachrichtenvordruck verweist auf einen nicht '
                        . 'abgeschlossenen Einsatzanhang: ' . $name
                );
            }
        }
    }

    return [
        'incident' => $incident,
        'sections' => $sections,
        'etb' => $etb,
        'ttb' => $ttb,
        'messages' => $messages,
        'attachment_names_by_message' => $attachmentNamesByMessage,
        'attachments' => $attachments,
        'counts' => [
            'etb' => count($etb),
            'ttb' => count($ttb),
            'messages' => count($messages),
            'attachments' => count($attachments),
        ],
    ];
}

/**
 * Render a complete PDF from a previously loaded bundle.
 *
 * @return array{bytes:string,attachment_count:int,attachment_bytes:int,sha256:string}
 */
function estab_incident_export_pdf(
    array $bundle,
    string $actor,
    int $attachmentByteLimit,
    ?DateTimeImmutable $generatedAt = null
): array {
    $incident = $bundle['incident'] ?? null;
    $sections = $bundle['sections'] ?? null;
    if (!is_array($incident) || !is_array($sections)) {
        throw new EstabIncidentExportDataException(
            'Die PDF-Quelldaten sind unvollständig.'
        );
    }
    $actor = estab_incident_actor($actor);
    $generatedAt ??= new DateTimeImmutable('now');
    $pdf = new EstabIncidentPdf($incident, $attachmentByteLimit);

    $embeddedIndex = [];
    if (in_array('attachments', $sections, true)) {
        foreach (($bundle['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) {
                throw new EstabIncidentExportDataException(
                    'Die Anhangdaten des PDF-Exports sind ungültig.'
                );
            }
            $embedded = $pdf->embedAttachment(
                (string) ($attachment['path'] ?? ''),
                (string) ($attachment['embedded_name'] ?? ''),
                (string) ($attachment['mime'] ?? ''),
                (string) ($attachment['description'] ?? '')
            );
            $embeddedIndex[] = [
                'display_name' => (string) (
                    $attachment['display_name'] ?? ''
                ),
                'stored_name' => $embedded['name'],
                'size' => $embedded['size'],
                'sha256' => $embedded['sha256'],
                'mime' => $embedded['mime'],
                'message_ids' => is_array(
                    $attachment['message_ids'] ?? null
                ) ? $attachment['message_ids'] : [],
            ];
        }
    }

    $pdf->addCover(
        $incident,
        $sections,
        is_array($bundle['counts'] ?? null) ? $bundle['counts'] : [],
        $generatedAt->format('d.m.Y H:i:s T'),
        $actor
    );
    if (in_array('etb', $sections, true)) {
        $pdf->addLogbook(
            'ETB',
            is_array($bundle['etb'] ?? null) ? $bundle['etb'] : []
        );
    }
    if (in_array('ttb', $sections, true)) {
        $pdf->addLogbook(
            'TTB',
            is_array($bundle['ttb'] ?? null) ? $bundle['ttb'] : []
        );
    }
    if (in_array('messages', $sections, true)) {
        $pdf->addMessages(
            is_array($bundle['messages'] ?? null)
                ? $bundle['messages']
                : [],
            is_array($bundle['attachment_names_by_message'] ?? null)
                ? $bundle['attachment_names_by_message']
                : []
        );
    }
    if (in_array('attachments', $sections, true)) {
        $pdf->addAttachmentIndex($embeddedIndex);
    }

    $bytes = $pdf->Output('', 'S');
    return [
        'bytes' => $bytes,
        'attachment_count' => $pdf->embeddedAttachmentCount(),
        'attachment_bytes' => $pdf->embeddedAttachmentBytes(),
        'sha256' => hash('sha256', $bytes),
    ];
}
