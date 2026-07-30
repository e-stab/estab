<?php

require_once __DIR__ . '/../app/file_access.php';
require_once __DIR__ . '/../app/attachment.php';
require_once __DIR__ . '/../app/generated_form.php';
require_once __DIR__ . '/../app/read_authorization.php';
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

$readIdentity = session_status() === PHP_SESSION_ACTIVE
    ? estab_read_session_identity($_SESSION)
    : null;
if (!is_array($readIdentity)) {
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
$attachment = null;
$attachmentIntegrity = null;
$failure = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Could not start file authorization transaction');
    }
    $transactionActive = true;
    if ($area === 'attachment') {
        $attachment = estab_read_attachment(
            $connection,
            $conf_4f_tbl['anhang'],
            $conf_4f_tbl['nachrichten'],
            $filename,
            $readIdentity,
            true
        );
        if (!is_array($attachment)) {
            throw new EstabIncidentNotFoundException(
                'Attachment is missing or not readable'
            );
        }
        $root = (string) $conf_4f['ablage_dir'];
    } else {
        try {
            $activeForm = estab_generated_form_fetch_active(
                $connection,
                $conf_4f_tbl['nachrichten'],
                (string) $conf_4f_db['datenbank'],
                $filename,
                true
            );
            $selectedIdentity = estab_read_require_identity_scope(
                $connection,
                (int) $activeForm['incident_id'],
                $readIdentity
            );
            if (
                !estab_read_message_allowed(
                    $selectedIdentity,
                    $activeForm['message']
                )
            ) {
                throw new EstabIncidentNotFoundException(
                    'Generated form is missing or not readable'
                );
            }
            if ($currentLayout) {
                $recipientMatrix = estab_generated_form_recipient_matrix(
                    $connection,
                    $conf_4f_tbl['empfmtx']
                );
                $renderMessage = $activeForm['message'];
                $renderRecipientMatrix = $recipientMatrix;
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
            if ($area === 'attachment') {
                $attachmentIntegrity =
                    estab_attachment_integrity_open_snapshot(
                        $attachment,
                        $root,
                        $filename
                    );
                $stream = $attachmentIntegrity['stream'];
            } else {
                $stream = estab_file_open($root, $area, $filename);
            }
        } catch (EstabAttachmentIntegrityException $exception) {
            throw $exception;
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
        $size = is_array($attachmentIntegrity)
            ? (int) $attachmentIntegrity['content_size']
            : (int) $stat['size'];
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
} catch (EstabReadPermissionException) {
    $failure = [403, 'Aktion nicht erlaubt.'];
} catch (EstabAttachmentIntegrityException $exception) {
    error_log(
        'eStab attachment download integrity failed: '
            . $exception->getMessage()
    );
    $failure = [
        409,
        'Die Integrität des Anhangs konnte nicht bestätigt werden.',
    ];
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
if ($area === 'attachment' && is_array($attachmentIntegrity)) {
    header(
        'X-eStab-Attachment-Integrity: '
            . (string) $attachmentIntegrity['state']
    );
    if (
        $attachmentIntegrity['state'] === 'verified'
        && is_string($attachmentIntegrity['sha256'])
    ) {
        header(
            'X-eStab-Attachment-SHA256: '
                . $attachmentIntegrity['sha256']
        );
    }
}

if (is_string($document)) {
    echo $document;
} else {
    fpassthru($stream);
    fclose($stream);
}
exit;
