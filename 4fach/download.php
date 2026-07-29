<?php

require_once __DIR__ . '/../app/file_access.php';
require_once __DIR__ . '/../app/attachment.php';
require_once __DIR__ . '/../app/generated_form.php';
require __DIR__ . '/../4fcfg/config.inc.php';
require __DIR__ . '/../4fcfg/dbcfg.inc.php';
require __DIR__ . '/../4fcfg/e_cfg.inc.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function estab_download_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit;
}

if (
    session_status() !== PHP_SESSION_ACTIVE
    || !estab_auth_session_is_authenticated($_SESSION)
) {
    estab_download_error(403, 'Anmeldung erforderlich.');
}

$area = isset($_GET['area']) && is_string($_GET['area']) ? $_GET['area'] : '';
$filename = isset($_GET['file']) && is_string($_GET['file']) ? $_GET['file'] : '';
$layoutProvided = array_key_exists('layout', $_GET);
$layout = $layoutProvided && is_string($_GET['layout'])
    ? $_GET['layout']
    : '';

try {
    if ($layoutProvided && !is_string($_GET['layout'])) {
        throw new InvalidArgumentException('Invalid generated-form layout type');
    }
    $area = estab_file_area($area);
    $filename = estab_file_validate_name($area, $filename);
    if (
        $layoutProvided
        && ($area !== 'vordruck' || $layout !== 'current')
    ) {
        throw new InvalidArgumentException('Invalid generated-form layout');
    }
} catch (InvalidArgumentException) {
    estab_download_error(400, 'Ungültige Dateianforderung.');
}

$currentLayout = $area === 'vordruck' && $layout === 'current';
$inline = $area === 'vordruck'
    && preg_match('/\.(?:pdf|png|jpe?g)\z/Di', $filename) === 1;
$connection = null;
$transactionActive = false;
$stream = null;
$document = null;
$renderMessage = null;
$renderRecipientMatrix = null;
$size = 0;
$contentType = 'application/octet-stream';
$failure = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Could not start file authorization transaction');
    }
    $transactionActive = true;
    if ($area === 'attachment') {
        $storedName = pathinfo($filename, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $attachment = estab_attachment_find(
            $connection,
            $conf_4f_tbl['anhang'],
            $storedName,
            true
        );
        if (
            !is_array($attachment)
            || !hash_equals(
                strtolower((string) ($attachment['fileext'] ?? '')),
                $extension
            )
        ) {
            throw new EstabIncidentNotFoundException(
                'Attachment does not belong to the active incident'
            );
        }
        $root = (string) $conf_4f['ablage_dir'];
    } else {
        try {
            if ($currentLayout) {
                $activeForm = estab_generated_form_fetch_active(
                    $connection,
                    $conf_4f_tbl['nachrichten'],
                    (string) $conf_4f_db['datenbank'],
                    $filename,
                    true
                );
                $recipientMatrix = estab_generated_form_recipient_matrix(
                    $connection,
                    $conf_4f_tbl['empfmtx']
                );
                $renderMessage = $activeForm['message'];
                $renderRecipientMatrix = $recipientMatrix;
            } else {
                estab_generated_form_require_active(
                    $connection,
                    $conf_4f_tbl['nachrichten'],
                    (string) $conf_4f_db['datenbank'],
                    $filename,
                    true
                );
            }
        } catch (InvalidArgumentException $exception) {
            throw new EstabIncidentNotFoundException(
                'Generated form name is not canonical',
                previous: $exception
            );
        }
        $root = (string) $conf_4f['vordruck_dir'];
    }

    if ($currentLayout) {
        try {
            $archiveProof = estab_file_open($root, $area, $filename);
            fclose($archiveProof);
        } catch (RuntimeException $exception) {
            throw new EstabIncidentNotFoundException(
                'Authorized generated-form archive is unavailable',
                previous: $exception
            );
        }
    } else {
        try {
            $stream = estab_file_open($root, $area, $filename);
        } catch (RuntimeException $exception) {
            throw new EstabIncidentNotFoundException(
                'Authorized file is unavailable',
                previous: $exception
            );
        }
        $stat = fstat($stream);
        if (
            !is_array($stat)
            || !isset($stat['size'])
            || (int) $stat['size'] < 0
        ) {
            throw new EstabIncidentNotFoundException(
                'Authorized file metadata is unavailable'
            );
        }
        $size = (int) $stat['size'];
        $contentType = estab_file_stream_content_type($stream);
    }
    if (!$connection->commit()) {
        throw new RuntimeException('Could not commit file authorization transaction');
    }
    $transactionActive = false;
} catch (EstabNoActiveIncidentException) {
    $failure = [409, 'Kein Einsatz aktiv.'];
} catch (EstabIncidentNotFoundException) {
    $failure = [404, 'Datei nicht gefunden.'];
} catch (Throwable $exception) {
    error_log('eStab file download scope failed: ' . $exception->getMessage());
    $failure = [503, 'Die Dateiberechtigung kann derzeit nicht geprüft werden.'];
} finally {
    if ($connection instanceof mysqli) {
        if ($transactionActive) {
            $connection->rollback();
        }
        estab_auth_close($connection);
    }
    if ($failure !== null && is_resource($stream)) {
        fclose($stream);
        $stream = null;
    }
}
if (
    $failure === null
    && $currentLayout
    && is_array($renderMessage)
    && is_array($renderRecipientMatrix)
) {
    try {
        require_once __DIR__ . '/../4fbak/backup_pdf.php';
        $pdf = new vordruckaspdf(
            $renderMessage,
            $renderRecipientMatrix
        );
        $document = $pdf->render_message_form_document();
        $size = strlen($document);
        $contentType = 'application/pdf';
    } catch (Throwable $exception) {
        error_log(
            'eStab current-layout PDF render failed: '
                . $exception->getMessage()
        );
        $failure = [503, 'Der aktuelle PDF-Abzug konnte nicht erstellt werden.'];
    }
}
if (is_array($failure)) {
    estab_download_error((int) $failure[0], (string) $failure[1]);
}
if (!is_resource($stream) && !is_string($document)) {
    estab_download_error(503, 'Die Datei konnte nicht geöffnet werden.');
}

header('Content-Type: ' . $contentType);
header('Content-Disposition: ' . estab_file_content_disposition($filename, $inline));
header('Content-Length: ' . (string) $size);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox; default-src \'none\'');
if ($currentLayout) {
    header('X-eStab-PDF-Layout: current');
}

if (is_string($document)) {
    echo $document;
} else {
    fpassthru($stream);
    fclose($stream);
}
exit;
