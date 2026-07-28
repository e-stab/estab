<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/session_ui.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!is_string($method) || !in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Method not allowed.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

if (estab_auth_session_identity($_SESSION) === null) {
    if ($method === 'HEAD') {
        exit;
    }
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="refresh" content="10">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Status</title>
</head>
<body style="background-color:#ececff">
  <p>Status erst nach Anmeldung verfügbar.</p>
</body>
</html>
    <?php
    exit;
}

require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/db_operation.php';

if ($method === 'HEAD') {
    exit;
}

if (!estab_session_ui_is_embedded_frame($_GET)) {
    estab_session_ui_start($_SESSION, true);
}
pre_html('status', 'Status', '');
echo '<body bgcolor="#ECECFF">';
systemstatus('vertikal');
echo '<table align="center" style="text-align:center; width:50px; '
    . 'background-color:rgb(150, 150, 150); height:10px;" '
    . 'border="0" cellpadding="0" cellspacing="0"><tbody><tr><td>';
echo '<img src="'
    . htmlspecialchars((string) $conf_design_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . '/timer.gif" alt="">';
echo '</td></tr></tbody></table></body></html>';
