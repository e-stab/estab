<?php

require_once __DIR__ . '/../app/file_access.php';
require __DIR__ . '/../4fcfg/config.inc.php';

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

try {
    $area = estab_file_area($area);
    $root = $area === 'attachment'
        ? (string) $conf_4f['ablage_dir']
        : (string) $conf_4f['vordruck_dir'];
    $path = estab_file_resolve($root, $area, $filename);
    $filename = estab_file_validate_name($area, $filename);
} catch (InvalidArgumentException) {
    estab_download_error(400, 'Ungültige Dateianforderung.');
} catch (RuntimeException) {
    estab_download_error(404, 'Datei nicht gefunden.');
}

$inline = $area === 'vordruck'
    && preg_match('/\.(?:pdf|png|jpe?g)\z/Di', $filename) === 1;
$size = filesize($path);
if ($size === false) {
    estab_download_error(404, 'Datei nicht gefunden.');
}

header('Content-Type: ' . estab_file_content_type($path));
header('Content-Disposition: ' . estab_file_content_disposition($filename, $inline));
header('Content-Length: ' . (string) $size);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox; default-src \'none\'');

$stream = fopen($path, 'rb');
if ($stream === false) {
    estab_download_error(404, 'Datei nicht gefunden.');
}
fpassthru($stream);
fclose($stream);
exit;
