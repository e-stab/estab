<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Aktivität kann nur von der angemeldeten Anwendung gemeldet werden.';
    exit;
}

$identity = estab_auth_session_identity($_SESSION);
if ($identity === null) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Die Sitzung ist abgelaufen.';
    exit;
}

try {
    estab_csrf_require_post($_SERVER, $_POST);
} catch (Throwable) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Die Aktivitätsmeldung ist ungültig oder abgelaufen.';
    exit;
}

$connection = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $updated = estab_auth_touch_activity(
        $connection,
        (string) $conf_4f_tbl['benutzer'],
        (string) $identity['kuerzel'],
        session_id()
    );
    if (!$updated) {
        estab_auth_invalidate_local_session($_SESSION);
        http_response_code(401);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Die Sitzung ist abgelaufen.';
        exit;
    }
} catch (Throwable $exception) {
    error_log('eStab activity update failed: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Die Aktivität konnte vorübergehend nicht gespeichert werden.';
    exit;
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}

http_response_code(204);
exit;
