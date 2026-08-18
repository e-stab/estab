<?php

declare(strict_types=1);

require_once __DIR__ . '/message_repository.php';

/**
 * Reduce a legacy message-form value to one readable line.
 *
 * Message values normally come from utf8mb4 database columns. The defensive
 * scalar check keeps malformed test or migration data from becoming "Array"
 * or triggering an object conversion.
 */
function estab_message_transport_value(mixed $value): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return '';
    }

    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $normalized = preg_replace('/\s+/u', ' ', $text);
    return is_string($normalized) ? $normalized : $text;
}

/** Build a comparison key that also handles the historic DFÜ spelling. */
function estab_message_transport_key(string $value): string
{
    return strtolower(strtr(estab_message_transport_value($value), [
        'Ä' => 'ae',
        'Ö' => 'oe',
        'Ü' => 'ue',
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
    ]));
}

/**
 * Translate every transport medium accepted by current or legacy schemas.
 *
 * Unknown legacy values remain visible, but are explicitly labelled as such.
 * This function returns plain text; HTML output must use
 * estab_message_medium_html() or estab_message_html().
 */
function estab_message_medium_text(mixed $medium): string
{
    $value = estab_message_transport_value($medium);
    if ($value === '') {
        return '';
    }

    $labels = [
        'fe' => 'Fernsprecher',
        'fernsprecher' => 'Fernsprecher',
        'fu' => 'Funk',
        'funk' => 'Funk',
        'me' => 'Melder',
        'melder' => 'Melder',
        'fax' => 'Fax',
        'telefaksimile' => 'Fax',
        'fs' => 'Fernschreiber',
        'fernschreiber' => 'Fernschreiber',
        '@' => 'Datenübertragung',
        'dfue' => 'Datenübertragung',
        'datenuebertragung' => 'Datenübertragung',
    ];
    $key = estab_message_transport_key($value);

    return $labels[$key] ?? 'Unbekannt (' . $value . ')';
}

/**
 * Return the canonical SET value accepted by nv_nachrichten.
 *
 * Form submissions are intentionally narrower than display-time legacy
 * handling: an unknown value is rejected instead of being written.
 */
function estab_message_medium_storage_value(mixed $medium): ?string
{
    $value = estab_message_transport_value($medium);
    if ($value === '') {
        return null;
    }
    return match (estab_message_transport_key($value)) {
        'fe', 'fernsprecher' => 'Fe',
        'fu', 'funk' => 'Fu',
        'me', 'melder' => 'Me',
        'fax', 'telefaksimile' => 'FAX',
        'fs', 'fernschreiber' => 'FS',
        '@', 'dfue', 'datenuebertragung' => '@',
        default => null,
    };
}

/**
 * Name the workflow steps that may write Feld 7, the desired transport medium.
 *
 * Feld 7 carries a wish, not evidence: whoever writes an outgoing message says
 * over which telecommunication means it should travel. LdF later disposes the
 * actual way from the active S6 telecommunication plan, and the actually used
 * way is documented in Feld 1, so no later step may rewrite the wish.
 *
 * @return list<string>
 */
function estab_message_desired_medium_tasks(): array
{
    return ['Stab_schreiben', 'Stab_korrigieren'];
}

/** Decide whether one workflow step may write Feld 7. */
function estab_message_desired_medium_editable(mixed $task): bool
{
    return is_string($task)
        && in_array($task, estab_message_desired_medium_tasks(), true);
}

/**
 * Combine the selected medium with the operational free-text route.
 *
 * Repeating "Fu" and "Funk" would make the tracking table harder to scan, so
 * equivalent medium and route values are emitted only once.
 */
function estab_message_transport_text(mixed $medium, mixed $route): string
{
    $mediumValue = estab_message_transport_value($medium);
    $mediumText = estab_message_medium_text($mediumValue);
    $routeText = estab_message_transport_value($route);

    if ($mediumText === '') {
        return $routeText;
    }
    if ($routeText === '') {
        return $mediumText;
    }

    $routeKey = estab_message_transport_key($routeText);
    if (
        $routeKey === estab_message_transport_key($mediumValue)
        || $routeKey === estab_message_transport_key($mediumText)
        || estab_message_medium_text($routeText) === $mediumText
    ) {
        return $mediumText;
    }

    return $mediumText . ' · ' . $routeText;
}

/** Translate and escape one medium for an HTML text context. */
function estab_message_medium_html(mixed $medium): string
{
    return estab_message_html(estab_message_medium_text($medium));
}

/** Format and escape one transport route for an HTML text context. */
function estab_message_transport_html(mixed $medium, mixed $route): string
{
    return estab_message_html(estab_message_transport_text($medium, $route));
}
