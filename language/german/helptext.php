<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../app/session_ui.php';
estab_session_ui_start($_SESSION, false, true);

/** Stop malformed or unknown help lookups without exposing internal keys. */
function estab_helptext_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!is_string($method) || !in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    estab_helptext_error(405, 'Method not allowed.');
}
if (array_keys($_GET) !== ['Errorart']) {
    estab_helptext_error(400, 'Unbekannter Hilfetext.');
}
$errorKind = $_GET['Errorart'];
if (
    !is_string($errorKind)
    || strlen($errorKind) > 40
    || preg_match('/\A[A-Za-z0-9_]+\z/D', $errorKind) !== 1
) {
    estab_helptext_error(400, 'Unbekannter Hilfetext.');
}

require __DIR__ . '/hilfetext.php';
if (
    !isset($Infotext)
    || !is_array($Infotext)
    || !array_key_exists($errorKind, $Infotext)
    || !is_array($Infotext[$errorKind])
    || !isset($Infotext[$errorKind][0], $Infotext[$errorKind][1])
) {
    estab_helptext_error(400, 'Unbekannter Hilfetext.');
}

$title = html_entity_decode((string) $Infotext[$errorKind][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$body = (string) $Infotext[$errorKind][1];

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
if ($method === 'HEAD') {
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $safeTitle ?></title>
</head>
<body>
<?php
// $body is executable-source documentation from the bundled hilfetext.php
// dictionary. The request supplies only a strictly validated existing key;
// no request or persisted content is interpolated into this trusted markup.
// nosemgrep: php.lang.security.injection.echoed-request.echoed-request
echo $body;
?>
<p><button type="button" onclick="window.close()">Hilfefenster schließen</button></p>
</body>
</html>
