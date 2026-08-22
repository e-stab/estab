<?php

/**
 * Runtime bootstrap for the modern container.
 *
 * The application remains deliberately close to the last upstream release.
 * This bootstrap supplies removed PHP APIs while the call sites are migrated
 * incrementally and centralises safe runtime defaults.
 */

require_once __DIR__ . '/csp.php';
require_once __DIR__ . '/legacy_mysql.php';
require_once __DIR__ . '/legacy_php.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/message_priority.php';
require_once __DIR__ . '/function_label.php';

date_default_timezone_set(getenv('TZ') ?: 'Europe/Berlin');

// Die Richtlinie traegt eine Nonce je Anfrage und muss deshalb hier
// entstehen, nicht im Webserver.
estab_csp_send_header();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('default_charset', 'UTF-8');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '43200');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

/** Return an environment value without treating the string "0" as absent. */
function estab_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

/**
 * Parse deployment booleans without PHP's surprising string truthiness.
 *
 * Empty and unknown values are rejected: an operator typo must not silently
 * enable a security-relevant feature.
 */
function estab_parse_bool(string|bool|int|null $value, bool $default): bool
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        if ($value === 0 || $value === 1) {
            return (bool) $value;
        }
        throw new InvalidArgumentException('Boolean integers must be 0 or 1');
    }

    return match (strtolower(trim($value))) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => throw new InvalidArgumentException('Invalid boolean value'),
    };
}

/** Read and strictly parse a boolean environment option. */
function estab_env_bool(string $name, bool $default): bool
{
    return estab_parse_bool(estab_env($name), $default);
}

/**
 * Keep database identifiers and their matching data-directory component safe.
 */
function estab_env_identifier(string $name, string $default): string
{
    $value = estab_env($name, $default) ?? $default;
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $value)) {
        throw new RuntimeException("Invalid identifier in {$name}");
    }
    return $value;
}

/** Read a decimal deployment integer within an explicit closed interval. */
function estab_env_integer(
    string $name,
    int $default,
    int $minimum,
    int $maximum
): int {
    if ($minimum > $maximum) {
        throw new LogicException('Invalid deployment integer bounds');
    }
    $value = estab_env($name, (string) $default) ?? (string) $default;
    if (
        preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
        || strlen($value) > 10
    ) {
        throw new InvalidArgumentException("Invalid decimal integer in {$name}");
    }
    $integer = (int) $value;
    if ($integer < $minimum || $integer > $maximum) {
        throw new InvalidArgumentException("Integer in {$name} is outside its allowed range");
    }
    return $integer;
}

/** Normalise the optional deployment subdirectory to either "" or "name/". */
function estab_base_path(): string
{
    $value = trim(estab_env('ESTAB_BASE_PATH', '') ?? '', "/ \t\n\r\0\x0B");
    if ($value === '') {
        return '';
    }
    if (!preg_match('~^[A-Za-z0-9._/-]+$~D', $value) || str_contains($value, '..')) {
        throw new RuntimeException('Invalid ESTAB_BASE_PATH');
    }
    return $value . '/';
}

/** Return "/" or a validated absolute HTTP(S) root, always with a trailing slash. */
function estab_public_root(): string
{
    $value = rtrim(estab_env('ESTAB_PUBLIC_URL', '/') ?? '/', '/');
    if ($value === '') {
        return '/';
    }
    if (!preg_match('~^https?://[^/\s]+(?:/[^\s]*)?$~Di', $value)) {
        throw new RuntimeException('ESTAB_PUBLIC_URL must be / or an absolute HTTP(S) URL');
    }
    return $value . '/';
}

/** Return the configured public root including the optional deployment path. */
function estab_application_root(): string
{
    return estab_public_root() . estab_base_path();
}

/**
 * Build one application URL without ever producing a scheme-relative // path.
 *
 * Callers supply repository-owned relative paths. Rejecting absolute,
 * traversal, and control-character input keeps this helper unsuitable for
 * open redirects even if a future call accidentally forwards request data.
 */
function estab_application_url(string $relativePath): string
{
    if (
        $relativePath === ''
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, '\\')
        || str_contains($relativePath, '..')
        || preg_match('/[\x00-\x1F\x7F]/D', $relativePath) === 1
    ) {
        throw new InvalidArgumentException('Application URL path must be relative');
    }

    return estab_application_root() . $relativePath;
}

/**
 * Parse a comma-separated proxy allowlist into validated IP/CIDR rules.
 *
 * Rules deliberately contain no hostnames: proxy trust must not change when
 * DNS changes or is temporarily unavailable.
 *
 * @return list<string>
 */
function estab_parse_trusted_proxy_networks(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }
    if (strlen($value) > 4096) {
        throw new InvalidArgumentException('ESTAB_TRUSTED_PROXIES is too long');
    }

    $networks = [];
    foreach (explode(',', $value) as $rawNetwork) {
        $rawNetwork = trim($rawNetwork);
        if ($rawNetwork === '' || substr_count($rawNetwork, '/') > 1) {
            throw new InvalidArgumentException('Invalid trusted proxy network');
        }

        $parts = explode('/', $rawNetwork, 2);
        $address = $parts[0];
        $packed = @inet_pton($address);
        if (
            $packed === false
            || filter_var($address, FILTER_VALIDATE_IP) === false
        ) {
            throw new InvalidArgumentException('Trusted proxies must be IP literals or CIDRs');
        }

        $maximumPrefix = strlen($packed) * 8;
        $prefix = $maximumPrefix;
        if (isset($parts[1])) {
            if (
                preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $parts[1]) !== 1
                || (int) $parts[1] > $maximumPrefix
            ) {
                throw new InvalidArgumentException('Invalid trusted proxy CIDR prefix');
            }
            $prefix = (int) $parts[1];
        }
        if ($prefix === 0) {
            throw new InvalidArgumentException(
                'A catch-all network cannot be a trusted proxy allowlist'
            );
        }
        $networks[] = $address . '/' . $prefix;
        if (count($networks) > 128) {
            throw new InvalidArgumentException('Too many trusted proxy networks');
        }
    }

    return array_values(array_unique($networks));
}

/** Return whether one validated IP literal belongs to a validated CIDR rule. */
function estab_ip_matches_proxy_network(string $address, string $network): bool
{
    [$networkAddress, $prefixText] = explode('/', $network, 2);
    $packedAddress = @inet_pton($address);
    $packedNetwork = @inet_pton($networkAddress);
    if (
        $packedAddress === false
        || $packedNetwork === false
        || strlen($packedAddress) !== strlen($packedNetwork)
    ) {
        return false;
    }

    $prefix = (int) $prefixText;
    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if (
        $wholeBytes > 0
        && substr($packedAddress, 0, $wholeBytes)
            !== substr($packedNetwork, 0, $wholeBytes)
    ) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($packedAddress[$wholeBytes]) & $mask)
        === (ord($packedNetwork[$wholeBytes]) & $mask);
}

/** Trust proxy headers only when the direct peer matches the allowlist. */
function estab_proxy_peer_is_trusted(array $server, array $networks): bool
{
    $peer = $server['REMOTE_ADDR'] ?? '';
    if (
        !is_string($peer)
        || filter_var($peer, FILTER_VALIDATE_IP) === false
    ) {
        return false;
    }
    foreach ($networks as $network) {
        if (estab_ip_matches_proxy_network($peer, $network)) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve the complete request-scoped proxy trust decision.
 *
 * Enabling forwarded headers without an allowlist is a deployment error, not
 * a permissive fallback. Requests from other peers simply ignore the headers.
 */
function estab_request_trusts_proxy_headers(array $server): bool
{
    if (!estab_env_bool('ESTAB_TRUST_PROXY_HEADERS', false)) {
        return false;
    }
    $networks = estab_parse_trusted_proxy_networks(
        estab_env('ESTAB_TRUSTED_PROXIES', '')
    );
    if ($networks === []) {
        throw new RuntimeException(
            'ESTAB_TRUSTED_PROXIES is required when proxy headers are trusted'
        );
    }
    return estab_proxy_peer_is_trusted($server, $networks);
}

/**
 * Validate request-independent deployment values before serving traffic.
 *
 * Request-scoped peer matching remains in estab_request_trusts_proxy_headers;
 * this check proves only that the configured trust policy is complete.
 */
function estab_validate_runtime_configuration(): void
{
    estab_env_identifier('ESTAB_DB_NAME', 'estab');
    estab_env_integer('ESTAB_DB_PORT', 3306, 1, 65535);
    estab_env_integer(
        'ESTAB_UPLOAD_MAX_BYTES',
        20971520,
        1,
        52428800
    );
    estab_env_integer(
        'ESTAB_PDF_ATTACHMENT_MAX_BYTES',
        52428800,
        0,
        52428800
    );
    estab_public_root();
    estab_base_path();

    foreach ([
        'ESTAB_ALLOW_SELF_REGISTRATION' => false,
        'ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF' => false,
        'ESTAB_TRUST_PROXY_HEADERS' => false,
    ] as $name => $default) {
        estab_env_bool($name, $default);
    }

    $networks = estab_parse_trusted_proxy_networks(
        estab_env('ESTAB_TRUSTED_PROXIES', '')
    );
    if (
        estab_env_bool('ESTAB_TRUST_PROXY_HEADERS', false)
        && $networks === []
    ) {
        throw new RuntimeException(
            'ESTAB_TRUSTED_PROXIES is required when proxy headers are trusted'
        );
    }
}

/** Accept HTTPS forwarding only from a trusted peer and a valid chain. */
function estab_proxy_reports_https(array $server, bool $trustProxyHeaders): bool
{
    if (!$trustProxyHeaders) {
        return false;
    }
    $forwardedProto = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (!is_string($forwardedProto) || trim($forwardedProto) === '') {
        return false;
    }
    $protoChain = array_map('strtolower', array_map('trim', explode(',', $forwardedProto)));
    if (array_filter(
        $protoChain,
        static fn (string $proto): bool => !in_array($proto, ['http', 'https'], true)
    )) {
        return false;
    }
    return $protoChain[0] === 'https';
}

// Proxy transport headers affect secure-cookie handling only when the direct
// peer is allowlisted. Every forwarded hop must contain a known token.
if (estab_proxy_reports_https($_SERVER, estab_request_trusts_proxy_headers($_SERVER))) {
    $_SERVER['HTTPS'] = 'on';
}

ini_set(
    'session.cookie_secure',
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'
);
