<?php

declare(strict_types=1);

/** A rejected session-bound form request, distinct from application failures. */
final class EstabCsrfException extends RuntimeException
{
}

/** Return the per-session CSRF token, creating it with a CSPRNG when needed. */
function estab_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('A session is required for CSRF protection');
    }
    $token = $_SESSION['estab_csrf_token'] ?? null;
    if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['estab_csrf_token'] = $token;
    }
    return $token;
}

function estab_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(estab_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

function estab_csrf_is_valid(mixed $candidate): bool
{
    if (!is_string($candidate) || preg_match('/\A[a-f0-9]{64}\z/D', $candidate) !== 1) {
        return false;
    }
    $stored = $_SESSION['estab_csrf_token'] ?? null;
    return is_string($stored) && hash_equals($stored, $candidate);
}

function estab_csrf_require_post(array $server, array $post): void
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST' || !estab_csrf_is_valid($post['csrf_token'] ?? null)) {
        throw new EstabCsrfException(
            'Ungültige oder abgelaufene Formularanforderung'
        );
    }
}
