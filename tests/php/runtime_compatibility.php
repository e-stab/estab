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

$proxyNetworks = estab_parse_trusted_proxy_networks(
    '127.0.0.1, 10.20.0.0/16, 2001:db8::/32,127.0.0.1/32'
);
$assert(
    $proxyNetworks === [
        '127.0.0.1/32',
        '10.20.0.0/16',
        '2001:db8::/32',
    ],
    'trusted proxy networks were not validated and normalised'
);
$assert(
    estab_ip_matches_proxy_network('10.20.44.9', '10.20.0.0/16')
        && !estab_ip_matches_proxy_network('10.21.44.9', '10.20.0.0/16')
        && estab_ip_matches_proxy_network('2001:db8:1::5', '2001:db8::/32')
        && !estab_ip_matches_proxy_network('2001:db9::5', '2001:db8::/32')
        && !estab_ip_matches_proxy_network('127.0.0.1', '2001:db8::/32'),
    'IPv4 or IPv6 proxy CIDR matching is incorrect'
);
$assert(
    estab_proxy_peer_is_trusted(
        ['REMOTE_ADDR' => '10.20.5.7'],
        $proxyNetworks
    )
        && !estab_proxy_peer_is_trusted(
            ['REMOTE_ADDR' => '198.51.100.8'],
            $proxyNetworks
        ),
    'direct proxy peer was not checked against the allowlist'
);
foreach ([
    'proxy.example.test',
    '127.0.0.1,',
    '127.0.0.1/33',
    '2001:db8::/129',
    '0.0.0.0/0',
    '::/0',
] as $invalidProxyNetwork) {
    $invalidProxyRejected = false;
    try {
        estab_parse_trusted_proxy_networks($invalidProxyNetwork);
    } catch (InvalidArgumentException) {
        $invalidProxyRejected = true;
    }
    $assert(
        $invalidProxyRejected,
        'unsafe trusted proxy rule accepted: ' . $invalidProxyNetwork
    );
}

$originalProxyTrust = getenv('ESTAB_TRUST_PROXY_HEADERS');
$originalTrustedProxies = getenv('ESTAB_TRUSTED_PROXIES');
try {
    putenv('ESTAB_TRUST_PROXY_HEADERS=false');
    putenv('ESTAB_TRUSTED_PROXIES=10.20.0.0/16');
    $assert(
        !estab_request_trusts_proxy_headers([
            'REMOTE_ADDR' => '10.20.5.7',
        ]),
        'proxy allowlist enabled forwarded headers without the explicit switch'
    );

    putenv('ESTAB_TRUST_PROXY_HEADERS=true');
    $assert(
        estab_request_trusts_proxy_headers([
            'REMOTE_ADDR' => '10.20.5.7',
        ])
            && !estab_request_trusts_proxy_headers([
                'REMOTE_ADDR' => '198.51.100.8',
            ]),
        'request-scoped proxy trust did not bind the switch to the direct peer'
    );

    putenv('ESTAB_TRUSTED_PROXIES');
    $missingProxyAllowlistRejected = false;
    try {
        estab_request_trusts_proxy_headers([
            'REMOTE_ADDR' => '10.20.5.7',
        ]);
    } catch (RuntimeException) {
        $missingProxyAllowlistRejected = true;
    }
    $assert(
        $missingProxyAllowlistRejected,
        'enabled proxy trust silently accepted a missing peer allowlist'
    );
} finally {
    if ($originalProxyTrust === false) {
        putenv('ESTAB_TRUST_PROXY_HEADERS');
    } else {
        putenv('ESTAB_TRUST_PROXY_HEADERS=' . $originalProxyTrust);
    }
    if ($originalTrustedProxies === false) {
        putenv('ESTAB_TRUSTED_PROXIES');
    } else {
        putenv('ESTAB_TRUSTED_PROXIES=' . $originalTrustedProxies);
    }
}

$runtimeConfigurationNames = [
    'ESTAB_DB_NAME',
    'ESTAB_DB_PORT',
    'ESTAB_UPLOAD_MAX_BYTES',
    'ESTAB_PDF_ATTACHMENT_MAX_BYTES',
    'ESTAB_PUBLIC_URL',
    'ESTAB_BASE_PATH',
    'ESTAB_ALLOW_SELF_REGISTRATION',
    'ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF',
    'ESTAB_TRUST_PROXY_HEADERS',
    'ESTAB_TRUSTED_PROXIES',
];
$runtimeConfigurationBefore = [];
foreach ($runtimeConfigurationNames as $name) {
    $runtimeConfigurationBefore[$name] = getenv($name);
}
$validRuntimeConfiguration = [
    'ESTAB_DB_NAME' => 'estab_test',
    'ESTAB_DB_PORT' => '3306',
    'ESTAB_UPLOAD_MAX_BYTES' => '5242880',
    'ESTAB_PDF_ATTACHMENT_MAX_BYTES' => '52428800',
    'ESTAB_PUBLIC_URL' => '/',
    'ESTAB_BASE_PATH' => '',
    'ESTAB_ALLOW_SELF_REGISTRATION' => 'true',
    'ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF' => 'false',
    'ESTAB_TRUST_PROXY_HEADERS' => 'false',
    'ESTAB_TRUSTED_PROXIES' => '',
];
try {
    foreach ($validRuntimeConfiguration as $name => $value) {
        putenv($name . '=' . $value);
    }
    estab_validate_runtime_configuration();
    $assert(true, 'valid runtime configuration accepted');

    foreach ([
        ['ESTAB_DB_NAME', 'estab-test'],
        ['ESTAB_DB_PORT', '0'],
        ['ESTAB_DB_PORT', '65536'],
        ['ESTAB_DB_PORT', '3306x'],
        ['ESTAB_UPLOAD_MAX_BYTES', '0'],
        ['ESTAB_UPLOAD_MAX_BYTES', '52428801'],
        ['ESTAB_PDF_ATTACHMENT_MAX_BYTES', '-1'],
        ['ESTAB_PDF_ATTACHMENT_MAX_BYTES', '52428801'],
        ['ESTAB_ALLOW_SELF_REGISTRATION', 'sometimes'],
        ['ESTAB_TRUSTED_PROXIES', '0.0.0.0/0'],
    ] as [$name, $value]) {
        foreach ($validRuntimeConfiguration as $validName => $validValue) {
            putenv($validName . '=' . $validValue);
        }
        putenv($name . '=' . $value);
        $rejected = false;
        try {
            estab_validate_runtime_configuration();
        } catch (Throwable) {
            $rejected = true;
        }
        $assert($rejected, "invalid runtime configuration accepted: {$name}");
    }

    foreach ($validRuntimeConfiguration as $name => $value) {
        putenv($name . '=' . $value);
    }
    putenv('ESTAB_TRUST_PROXY_HEADERS=true');
    putenv('ESTAB_TRUSTED_PROXIES=');
    $incompleteProxyConfigurationRejected = false;
    try {
        estab_validate_runtime_configuration();
    } catch (Throwable) {
        $incompleteProxyConfigurationRejected = true;
    }
    $assert(
        $incompleteProxyConfigurationRejected,
        'proxy trust without an allowlist passed startup validation'
    );
} finally {
    foreach ($runtimeConfigurationBefore as $name => $value) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}

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

$messageForm = file_get_contents(__DIR__ . '/../../4fach/4fachform.php');
$assert(is_string($messageForm), 'message form source is readable');
$assert(
    str_contains($messageForm, 'isset($this->formdata["16_empf"])')
        && str_contains(
            $messageForm,
            'is_string($this->formdata["16_empf"])'
        )
        && !str_contains(
            $messageForm,
            '$empf_text  = $this->formdata ["16_empf"]'
        ),
    'empty recipient data is not normalised before explode()'
);

$legacyTools = file_get_contents(__DIR__ . '/../../4fach/tools.php');
$pdfReset = file_get_contents(__DIR__ . '/../../4fbak/backup.php');
$assert(
    is_string($legacyTools)
        && is_string($pdfReset)
        && !preg_match('/charset\\s*=\\s*(?:iso|latin)/i', $legacyTools)
        && !preg_match('/charset\\s*=\\s*(?:iso|latin)/i', $pdfReset),
    'active legacy HTML generators still contradict the UTF-8 response charset'
);

echo "runtime compatibility: OK ({$assertions} assertions)\n";
