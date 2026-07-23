#!/usr/local/bin/php
<?php

$context = stream_context_create([
    'http' => [
        'timeout' => 4,
        'ignore_errors' => true,
    ],
]);

$body = @file_get_contents('http://127.0.0.1:8080/health.php', false, $context);
if ($body === false) {
    exit(1);
}

$result = json_decode($body, true);
exit(is_array($result) && ($result['status'] ?? null) === 'ready' ? 0 : 1);
