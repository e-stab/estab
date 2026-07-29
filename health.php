<?php

require_once __DIR__ . '/app/readiness.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$report = estab_readiness_report();
$checks = $report['checks'];
$ready = $report['ready'];
http_response_code($ready ? 200 : 503);

echo json_encode(
    [
        'status' => $ready ? 'ready' : 'unavailable',
        'checks' => $checks,
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
