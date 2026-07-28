<?php

require_once __DIR__ . '/../../app/bootstrap.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$values = ['first' => 10, 'second' => 20];
$first = each($values);
$assert($first === [0 => 'first', 'key' => 'first', 1 => 10, 'value' => 10], 'each() first result');
$assert(key($values) === 'second', 'each() advances the array pointer');

$assert(split('[,;]', 'a,b;c') === ['a', 'b', 'c'], 'split() POSIX-style pattern');
$matches = [];
$assert(ereg('([0-9]+)', 'Einsatz 42', $matches) === 2, 'ereg() return length');
$assert($matches[1] === '42', 'ereg() captures');
$assert(eregi('stab', 'eStAb') === 4, 'eregi() case-insensitive match');
$assert(ereg_replace('[0-9]+', 'X', 'E42') === 'EX', 'ereg_replace()');
$assert(eregi_replace('stab', 'Lage', 'eStAb') === 'eLage', 'eregi_replace()');
$assert(get_magic_quotes_runtime() === false, 'magic quotes are disabled');
$assert(set_magic_quotes_runtime(false) === true, 'legacy magic-quotes setter is harmless');

putenv('ESTAB_TEST_IDENTIFIER=operation_42');
$assert(estab_env_identifier('ESTAB_TEST_IDENTIFIER', 'fallback') === 'operation_42', 'valid identifier');
putenv('ESTAB_TEST_IDENTIFIER=../escape');
$invalidIdentifierRejected = false;
try {
    estab_env_identifier('ESTAB_TEST_IDENTIFIER', 'fallback');
} catch (RuntimeException) {
    $invalidIdentifierRejected = true;
}
$assert($invalidIdentifierRejected, 'invalid identifier rejected');

putenv('ESTAB_BASE_PATH=estab/sub');
$assert(estab_base_path() === 'estab/sub/', 'base path normalisation');
putenv('ESTAB_PUBLIC_URL=/');
$assert(
    estab_application_root() === '/estab/sub/'
        && estab_application_url('4fach/mainindex.php') === '/estab/sub/4fach/mainindex.php',
    'application URL does not preserve the deployment base path'
);
putenv('ESTAB_BASE_PATH=');
$assert(
    estab_application_url('4fach/mainindex.php') === '/4fach/mainindex.php'
        && !str_starts_with(estab_application_url('4fach/mainindex.php'), '//'),
    'root deployment produced a scheme-relative application URL'
);
foreach (['', '/absolute', '../escape', 'safe\\escape', "line\nbreak"] as $invalidRelativeUrl) {
    $invalidRelativeRejected = false;
    try {
        estab_application_url($invalidRelativeUrl);
    } catch (InvalidArgumentException) {
        $invalidRelativeRejected = true;
    }
    $assert($invalidRelativeRejected, 'unsafe relative application URL accepted');
}
putenv('ESTAB_BASE_PATH=../escape');
$invalidPathRejected = false;
try {
    estab_base_path();
} catch (RuntimeException) {
    $invalidPathRejected = true;
}
$assert($invalidPathRejected, 'base path traversal rejected');

putenv('ESTAB_PUBLIC_URL=https://estab.example.test/base');
$assert(estab_public_root() === 'https://estab.example.test/base/', 'absolute public URL');
putenv('ESTAB_BASE_PATH=dispatch/site');
$assert(
    estab_application_url('fmtbb/tbb.php')
        === 'https://estab.example.test/base/dispatch/site/fmtbb/tbb.php',
    'absolute application URL lost its deployment base path'
);
putenv('ESTAB_PUBLIC_URL=javascript:alert(1)');
$invalidUrlRejected = false;
try {
    estab_public_root();
} catch (RuntimeException) {
    $invalidUrlRejected = true;
}
$assert($invalidUrlRejected, 'unsafe public URL rejected');

$proxyServer = ['HTTP_X_FORWARDED_PROTO' => 'https, https'];
$assert(!estab_proxy_reports_https($proxyServer, false), 'forwarded HTTPS ignored by default');
$assert(estab_proxy_reports_https($proxyServer, true), 'trusted HTTPS chain accepted');
$assert(
    !estab_proxy_reports_https(['HTTP_X_FORWARDED_PROTO' => 'https, injected'], true),
    'invalid forwarded protocol chain rejected'
);

$legacyMysqlSource = file_get_contents(__DIR__ . '/../../app/legacy_mysql.php');
$assert(is_string($legacyMysqlSource), 'legacy MySQL compatibility source is readable');
$assert(
    !str_contains($legacyMysqlSource, '->ping(')
        && str_contains($legacyMysqlSource, "query('SELECT 1')"),
    'legacy mysql_ping wrapper still invokes deprecated mysqli::ping'
);

$mainController = file_get_contents(__DIR__ . '/../../4fach/mainindex.php');
$assert(is_string($mainController), 'main controller source is readable');
$assert(
    preg_match(
        '~\\.\\s*\\$conf_4f\\s*\\[\\s*["\\\']NameVersion["\\\']\\s*\\]~',
        $mainController
    ) !== 1,
    'main controller concatenates the NameVersion array into a page title'
);
$assert(
    str_contains($mainController, '($_SESSION["flt_search"] ?? null) !=='),
    'search state still reads an absent session key'
);

echo "runtime compatibility: OK ({$assertions} assertions)\n";
