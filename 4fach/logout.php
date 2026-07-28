<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/logout.php';
require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';

header('Cache-Control: no-store');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Abmelden ist nur über den vorgesehenen Button möglich.';
    exit;
}

if (estab_auth_session_identity($_SESSION) === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Anmeldung erforderlich.';
    exit;
}

try {
    estab_csrf_require_post($_SERVER, $_POST);
} catch (Throwable) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Die Abmeldeanforderung ist ungültig oder abgelaufen.';
    exit;
}

if (($_POST['logout_action'] ?? null) !== 'logout') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unbekannte Abmeldeaktion.';
    exit;
}

estab_logout_current_session(
    $conf_4f_db,
    (string) $conf_4f_tbl['benutzer'],
    (string) $conf_4f_tbl['protokoll'],
    $_SERVER
);

header(
    'Location: ' . estab_application_url('4fach/index.php'),
    true,
    303
);
exit;
