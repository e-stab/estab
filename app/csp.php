<?php

declare(strict_types=1);

/**
 * Content-Security-Policy with a per-request script nonce.
 *
 * The policy used to admit `script-src 'unsafe-inline'`, which makes the
 * script part of the policy decorative: an injected script element would run like
 * any other. Apache cannot mint an unguessable nonce per request, so the
 * policy is issued here, where the value can be random and where every page
 * that emits an inline script can read it back.
 *
 * Style attributes are a different matter. The lists paint a row from the
 * colour of the carbon copy that reaches the reading function; that value is
 * data, not markup, and a nonce does not cover style attributes at all.
 * `style-src 'unsafe-inline'` therefore stays, deliberately: it does not let
 * an attacker execute anything.
 */

const ESTAB_CSP_NONCE_KEY = 'estab.csp.nonce';

/**
 * The nonce of this request, created once and reused.
 *
 * Base64 of 16 random bytes: 128 bits, well above the 128-bit floor the CSP
 * specification asks for.
 */
function estab_csp_nonce(): string
{
    $nonce = $GLOBALS[ESTAB_CSP_NONCE_KEY] ?? null;
    if (is_string($nonce) && $nonce !== '') {
        return $nonce;
    }
    $nonce = base64_encode(random_bytes(16));
    $GLOBALS[ESTAB_CSP_NONCE_KEY] = $nonce;
    return $nonce;
}

/** The ready-to-use attribute for an inline script element. */
function estab_csp_script_attribute(): string
{
    return ' nonce="' . htmlspecialchars(
        estab_csp_nonce(),
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    ) . '"';
}

/** The complete policy for one HTML response. */
function estab_csp_policy(string $nonce): string
{
    return "default-src 'self'; base-uri 'self'; object-src 'self'; "
        . "frame-ancestors 'self'; form-action 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; "
        . "script-src 'self' 'nonce-" . $nonce . "'";
}

/**
 * Send the policy, unless the response is already on its way.
 *
 * A request that has begun writing keeps whatever Apache set; overwriting is
 * impossible at that point and warning about it would only add noise to the
 * log of a page that still works.
 */
function estab_csp_send_header(): void
{
    if (headers_sent() || PHP_SAPI === 'cli') {
        return;
    }
    header(
        'Content-Security-Policy: ' . estab_csp_policy(estab_csp_nonce()),
        true
    );
}
