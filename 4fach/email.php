<?php

/**
 * Safe, authenticated browser representation of an RFC-822 email attachment.
 *
 * The original .eml bytes remain available through download.php. This
 * endpoint renders only escaped metadata and passive text extracted from an
 * integrity-verified snapshot. Mail HTML, scripts, remote images and other
 * active content never cross the output boundary.
 */

require_once __DIR__ . '/../app/file_access.php';
require_once __DIR__ . '/../app/attachment.php';
require_once __DIR__ . '/../app/email_attachment.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/read_authorization.php';
require __DIR__ . '/../4fcfg/config.inc.php';
require __DIR__ . '/../4fcfg/dbcfg.inc.php';
require __DIR__ . '/../4fcfg/e_cfg.inc.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function estab_email_view_escape(mixed $value): string
{
    return htmlspecialchars(
        is_string($value) ? $value : '',
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    );
}

function estab_email_view_size(mixed $bytes): string
{
    if (!is_int($bytes) || $bytes < 0) {
        return 'Größe unbekannt';
    }
    if ($bytes < 1024) {
        return number_format($bytes, 0, ',', '.') . ' Byte';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KiB';
    }
    return number_format($bytes / 1048576, 1, ',', '.') . ' MiB';
}

function estab_email_view_original_name(array $attachment, string $fallback): string
{
    $original = $attachment['org_filename'] ?? null;
    if (!is_string($original)) {
        return $fallback;
    }
    $original = basename(str_replace('\\', '/', trim($original)));
    if (
        $original === ''
        || strlen($original) > 255
        || preg_match('/\p{C}/u', $original) === 1
    ) {
        return $fallback;
    }
    return $original;
}

function estab_email_view_headers(int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Language: de');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Vary: Cookie');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header(
        "Content-Security-Policy: default-src 'none'; style-src 'self'; "
            . "base-uri 'none'; form-action 'none'; object-src 'none'; "
            . "img-src 'none'; script-src 'none'; frame-ancestors 'self'"
    );
}

function estab_email_view_error(int $status, string $message): never
{
    estab_email_view_headers($status);
    echo '<!doctype html><html lang="de"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>E-Mail-Anlage</title>'
        . '<link rel="stylesheet" href="../estab-ui.css"></head>'
        . '<body class="estab-email-preview-page"><main '
        . 'class="estab-email-preview estab-email-preview--error" '
        . 'data-estab-email-preview-error role="alert">'
        . '<p class="estab-section-kicker">E-Mail-Anlage</p>'
        . '<h1>Darstellung nicht verfügbar</h1><p>'
        . estab_email_view_escape($message) . '</p></main></body></html>';
    exit;
}

$readIdentity = session_status() === PHP_SESSION_ACTIVE
    ? estab_read_session_identity($_SESSION)
    : null;
if (!is_array($readIdentity)) {
    estab_navigation_require_session($_SESSION, 'messages', $_SERVER);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    estab_email_view_error(405, 'Für die E-Mail-Ansicht sind nur GET und HEAD erlaubt.');
}

// The immutable identity snapshot is sufficient for both object checks. Do
// not hold the PHP session lock while hashing or parsing a potentially large
// message on NAS storage.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$requested = isset($_GET['file']) && is_string($_GET['file'])
    ? $_GET['file']
    : '';
$messageWriteRecord = null;
try {
    $requested = estab_file_validate_name('attachment', $requested);
    $messageWriteRecord = estab_read_attachment_write_record($_GET);
    if (strtolower(pathinfo($requested, PATHINFO_EXTENSION)) !== 'eml') {
        throw new InvalidArgumentException('Only RFC-822 email files are supported');
    }
} catch (InvalidArgumentException) {
    estab_email_view_error(400, 'Ungültige E-Mail-Anforderung.');
}

$connection = null;
$transactionActive = false;
$stream = null;
$attachment = null;
$attachmentIntegrity = null;
$attachmentAuthorizationVersion = null;
$attachmentWritePermissionContext = null;
$parsed = null;
$failure = null;
try {
    $connection = estab_attachment_connection($conf_4f_db);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Could not start email attachment authorization');
    }
    $transactionActive = true;
    $attachmentWriteScope = is_int($messageWriteRecord)
        ? estab_read_attachment_write_scope_for_record(
            $connection,
            $conf_4f_tbl['nachrichten'],
            $readIdentity,
            $messageWriteRecord
        )
        : null;
    if (is_int($messageWriteRecord)) {
        $attachmentWritePermissionContext = estab_permission_context();
        if (!is_array($attachmentWritePermissionContext)) {
            throw new RuntimeException(
                'Attachment write policy snapshot is unavailable'
            );
        }
    }
    $attachment = estab_read_attachment(
        $connection,
        $conf_4f_tbl['anhang'],
        $conf_4f_tbl['nachrichten'],
        $requested,
        $readIdentity,
        false,
        $attachmentWriteScope
    );
    if (!is_array($attachment)) {
        throw new EstabIncidentNotFoundException(
            'Email attachment is missing or not readable'
        );
    }
    $attachmentAuthorizationVersion =
        estab_read_attachment_authorization_version($attachment);
    if (!$connection->commit()) {
        throw new RuntimeException(
            'Could not commit initial email attachment authorization'
        );
    }
    $transactionActive = false;

    try {
        $attachmentIntegrity = estab_attachment_integrity_open_snapshot(
            $attachment,
            (string) $conf_4f['ablage_dir'],
            $requested
        );
        $stream = $attachmentIntegrity['stream'];
    } catch (EstabAttachmentIntegrityException $exception) {
        throw $exception;
    } catch (RuntimeException $exception) {
        throw new EstabIncidentNotFoundException(
            'Authorized email attachment is unavailable',
            previous: $exception
        );
    }

    $parsed = estab_email_attachment_parse_stream($stream);
    fclose($stream);
    $stream = null;

    if (!$connection->begin_transaction()) {
        throw new RuntimeException(
            'Could not start final email attachment authorization'
        );
    }
    $transactionActive = true;
    $currentAttachmentWriteScope = is_int($messageWriteRecord)
        ? estab_read_attachment_write_scope_for_record(
            $connection,
            $conf_4f_tbl['nachrichten'],
            $readIdentity,
            $messageWriteRecord,
            $attachmentWritePermissionContext,
            true
        )
        : null;
    $currentAttachment = estab_read_attachment(
        $connection,
        $conf_4f_tbl['anhang'],
        $conf_4f_tbl['nachrichten'],
        $requested,
        $readIdentity,
        true,
        $currentAttachmentWriteScope
    );
    if (
        !is_array($currentAttachment)
        || !is_string($attachmentAuthorizationVersion)
        || !hash_equals(
            $attachmentAuthorizationVersion,
            estab_read_attachment_authorization_version($currentAttachment)
        )
    ) {
        throw new EstabIncidentNotFoundException(
            'Email attachment authorization changed during snapshot'
        );
    }
    if (!$connection->commit()) {
        throw new RuntimeException(
            'Could not commit final email attachment authorization'
        );
    }
    $transactionActive = false;
} catch (EstabNoActiveIncidentException|EstabIncidentConflictException) {
    $failure = [409, 'Kein Einsatz aktiv.'];
} catch (EstabIncidentNotFoundException) {
    $failure = [404, 'E-Mail-Anlage nicht gefunden.'];
} catch (EstabReadPermissionException) {
    $failure = [403, 'Aktion nicht erlaubt.'];
} catch (EstabAttachmentIntegrityException $exception) {
    error_log(
        'eStab email attachment integrity failed: '
            . $exception->getMessage()
    );
    $failure = [
        409,
        'Die Integrität der E-Mail-Anlage konnte nicht bestätigt werden.',
    ];
} catch (Throwable $exception) {
    error_log('eStab email attachment view failed: ' . $exception->getMessage());
    $failure = [503, 'Die E-Mail-Anlage kann derzeit nicht geprüft werden.'];
} finally {
    if ($connection instanceof mysqli) {
        if ($transactionActive) {
            $connection->rollback();
        }
        estab_attachment_close($connection);
    }
    if (is_resource($stream)) {
        fclose($stream);
    }
}

if (is_array($failure)) {
    estab_email_view_error((int) $failure[0], (string) $failure[1]);
}
if (!is_array($attachment) || !is_array($parsed)) {
    estab_email_view_error(503, 'Die E-Mail-Anlage konnte nicht gelesen werden.');
}

$downloadUrl = estab_file_download_url(
    (string) $conf_4f['download_uri'],
    'attachment',
    $requested
);
if (is_int($messageWriteRecord)) {
    $downloadUrl .= '&' . http_build_query(
        ['message_write_record' => $messageWriteRecord],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}
$originalName = estab_email_view_original_name($attachment, $requested);
$parseOk = ($parsed['ok'] ?? false) === true;
$headers = is_array($parsed['headers'] ?? null) ? $parsed['headers'] : [];
$subject = is_string($headers['subject'] ?? null)
    && trim($headers['subject']) !== ''
    ? trim($headers['subject'])
    : 'Ohne Betreff';
$body = is_string($parsed['body'] ?? null) ? trim($parsed['body']) : '';
$bodySource = ($parsed['body_source'] ?? null) === 'html' ? 'html' : 'plain';
$containedAttachments = is_array($parsed['attachments'] ?? null)
    ? $parsed['attachments']
    : [];
$warnings = is_array($parsed['warnings'] ?? null) ? $parsed['warnings'] : [];

estab_email_view_headers($parseOk ? 200 : 422);
if (is_array($attachmentIntegrity)) {
    header(
        'X-eStab-Attachment-Integrity: '
            . (string) $attachmentIntegrity['state']
    );
    if (
        ($attachmentIntegrity['state'] ?? null) === 'verified'
        && is_string($attachmentIntegrity['sha256'] ?? null)
    ) {
        header(
            'X-eStab-Attachment-SHA256: '
                . $attachmentIntegrity['sha256']
        );
    }
}
header('X-eStab-Email-Rendering: passive-text');
if ($method === 'HEAD') {
    exit;
}

echo '<!doctype html><html lang="de"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>' . estab_email_view_escape($subject) . '</title>'
    . '<link rel="stylesheet" href="../estab-ui.css"></head>'
    . '<body class="estab-email-preview-page"><main '
    . 'class="estab-email-preview" data-estab-email-preview '
    . 'data-estab-email-rendering="passive-text">'
    . '<header class="estab-email-preview-header">'
    . '<p class="estab-section-kicker">Passive E-Mail-Ansicht</p>'
    . '<h1 data-estab-email-subject>' . estab_email_view_escape($subject)
    . '</h1><p>' . estab_email_view_escape($originalName) . '</p></header>'
    . '<div class="estab-email-preview-trust" role="note">'
    . '<strong>Absenderangaben nicht verifiziert:</strong> Von, Datum und '
    . 'Betreff stammen aus der Datei. eStab prüft hier weder die Identität '
    . 'des Absenders noch DKIM, S/MIME oder andere E-Mail-Signaturen.</div>';

if (!$parseOk) {
    $parseError = is_string($parsed['error'] ?? null)
        ? (string) $parsed['error']
        : 'Die E-Mail-Struktur konnte nicht sicher ausgewertet werden.';
    echo '<div class="estab-alert estab-alert--danger" role="alert">'
        . '<strong>Keine Webdarstellung möglich.</strong> '
        . estab_email_view_escape($parseError) . '</div>';
} else {
    echo '<dl class="estab-email-preview-headers" data-estab-email-headers>';
    foreach ([
        'from' => 'Von',
        'to' => 'An',
        'cc' => 'Kopie',
        'date' => 'Datum',
    ] as $key => $label) {
        $value = $headers[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            continue;
        }
        echo '<div><dt>' . estab_email_view_escape($label) . '</dt><dd>'
            . estab_email_view_escape(trim($value)) . '</dd></div>';
    }
    echo '</dl>'
        . '<section class="estab-email-preview-body" '
        . 'data-estab-email-body data-estab-email-body-source="'
        . estab_email_view_escape($bodySource) . '"><h2>Nachricht</h2>';
    if ($body === '') {
        echo '<p class="estab-email-preview-empty">'
            . 'Die E-Mail enthält keinen darstellbaren Nachrichtentext.</p>';
    } else {
        echo '<div class="estab-email-preview-text">'
            . estab_email_view_escape($body) . '</div>';
    }
    echo '</section>';

    if ($containedAttachments !== []) {
        echo '<section class="estab-email-preview-contained" '
            . 'data-estab-email-contained-attachments><h2>In der E-Mail enthaltene '
            . 'Dateien (' . count($containedAttachments) . ')</h2><ul>';
        foreach ($containedAttachments as $contained) {
            if (!is_array($contained)) {
                continue;
            }
            $name = is_string($contained['filename'] ?? null)
                && trim($contained['filename']) !== ''
                ? trim($contained['filename'])
                : 'Unbenannte Datei';
            $type = is_string($contained['content_type'] ?? null)
                ? trim($contained['content_type'])
                : 'application/octet-stream';
            echo '<li><strong>' . estab_email_view_escape($name) . '</strong>'
                . '<span>' . estab_email_view_escape($type) . ' · '
                . estab_email_view_escape(
                    estab_email_view_size($contained['size'] ?? null)
                ) . '</span></li>';
        }
        echo '</ul><p>Einzelne eingebettete Dateien werden nicht aktiv geöffnet. '
            . 'Sie bleiben in der herunterladbaren Original-E-Mail erhalten.</p>'
            . '</section>';
    }
}

if ($warnings !== []) {
    echo '<div class="estab-email-preview-warning" role="status">'
        . '<strong>Hinweis:</strong> Teile der Darstellung wurden aus '
        . 'Sicherheits- oder Größen­gründen vereinfacht.</div>';
}

echo '<footer class="estab-email-preview-footer">'
    . '<p>Diese Ansicht lädt keine externen Inhalte und führt keinen Code aus. '
    . 'Die unveränderte E-Mail einschließlich aller enthaltenen Dateien ist '
    . 'weiterhin verfügbar. Beim Öffnen der Originaldatei in einem '
    . 'E-Mail-Programm gelten dessen Sicherheitsregeln; enthaltene Dateien '
    . 'werden von eStab nicht als schadfrei bestätigt.</p>'
    . '<a class="estab-button estab-button-primary" href="'
    . estab_email_view_escape($downloadUrl) . '" download="'
    . estab_email_view_escape($originalName)
    . '">Originaldatei herunterladen</a></footer></main></body></html>';
