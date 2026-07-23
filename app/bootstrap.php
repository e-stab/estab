<?php

/**
 * Runtime bootstrap for the modern container.
 *
 * The application remains deliberately close to the last upstream release.
 * This bootstrap supplies removed PHP APIs while the call sites are migrated
 * incrementally and centralises safe runtime defaults.
 */

require_once __DIR__ . '/legacy_mysql.php';
require_once __DIR__ . '/legacy_php.php';
require_once __DIR__ . '/datetime.php';

date_default_timezone_set(getenv('TZ') ?: 'Europe/Berlin');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('default_charset', 'UTF-8');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
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

/** Accept HTTPS forwarding only from an explicitly trusted, valid chain. */
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

// Proxy transport headers affect secure-cookie handling only when the
// deployment explicitly enables them. Every hop must contain a known token.
if (estab_proxy_reports_https($_SERVER, estab_env_bool('ESTAB_TRUST_PROXY_HEADERS', false))) {
    $_SERVER['HTTPS'] = 'on';
}

ini_set(
    'session.cookie_secure',
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'
);
