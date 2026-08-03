<?php

declare(strict_types=1);

/**
 * Pure permission-mode vocabulary and request-local policy context.
 *
 * The context is never populated from a browser, session, environment value
 * or URL. Controllers may set it only from the incident row they just read
 * from the database. Missing context deliberately means STRICT.
 */

const ESTAB_PERMISSION_MODE_STRICT = 'STRICT';
const ESTAB_PERMISSION_MODE_LOOSE = 'LOOSE';
const ESTAB_PERMISSION_CONTEXT_KEY = 'estab.permission_mode.context';

/**
 * Parse one canonical database value, optionally defaulting old callers.
 *
 * @return 'STRICT'|'LOOSE'
 */
function estab_permission_mode(mixed $value, bool $defaultStrict = false): string
{
    if (($value === null || $value === '') && $defaultStrict) {
        return ESTAB_PERMISSION_MODE_STRICT;
    }
    if (
        !is_string($value)
        || !in_array(
            $value,
            [ESTAB_PERMISSION_MODE_STRICT, ESTAB_PERMISSION_MODE_LOOSE],
            true
        )
    ) {
        throw new InvalidArgumentException('Invalid incident permission mode');
    }
    return $value;
}

/** Stable operator-facing label. */
function estab_permission_mode_label(mixed $value): string
{
    return match (estab_permission_mode($value)) {
        ESTAB_PERMISSION_MODE_STRICT => 'Streng',
        ESTAB_PERMISSION_MODE_LOOSE => 'Locker',
    };
}

/**
 * Normalize an authoritative active-incident row into a policy snapshot.
 *
 * @param array<string,mixed> $incident Database status/incident row
 * @return array{incident_id:int,mode:string,revision:int}
 */
function estab_permission_context_from_incident(array $incident): array
{
    $incidentId = filter_var(
        $incident['active_einsatz_id'] ?? $incident['einsatz_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
    );
    $revision = filter_var(
        $incident['revision'] ?? 0,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]
    );
    if (!is_int($incidentId) || !is_int($revision)) {
        throw new InvalidArgumentException('Invalid incident permission context');
    }
    return [
        'incident_id' => $incidentId,
        'mode' => estab_permission_mode(
            $incident['estab_permission_mode'] ?? null
        ),
        'revision' => $revision,
    ];
}

/** Bind this PHP request to an authoritative active-incident snapshot. */
function estab_permission_context_set_from_incident(array $incident): void
{
    $GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY] =
        estab_permission_context_from_incident($incident);
}

/** @return array{incident_id:int,mode:string,revision:int}|null */
function estab_permission_context(): ?array
{
    $context = $GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY] ?? null;
    return is_array($context) ? $context : null;
}

/**
 * Fachrollen are mandatory in both modes.
 *
 * LOOSE changes the source of authorization (fixed account plus explicitly
 * administered additional functions), never the existence of a role check.
 */
function estab_permission_role_checks_enforced(): bool
{
    return true;
}

/** Missing context fails closed and therefore requires a selected duty hat. */
function estab_permission_duty_shift_required(): bool
{
    $context = estab_permission_context();
    return $context === null
        || $context['mode'] === ESTAB_PERMISSION_MODE_STRICT;
}

/** Return whether the authoritative request context explicitly selected LOOSE. */
function estab_permission_loose_mode_active(): bool
{
    $context = estab_permission_context();
    return $context !== null
        && $context['mode'] === ESTAB_PERMISSION_MODE_LOOSE;
}

/**
 * Reject a write when the policy changed after the request admission check.
 * The active incident/status row is already locked by the caller.
 */
function estab_permission_context_matches_incident(array $incident): bool
{
    $context = estab_permission_context();
    if ($context === null) {
        return true;
    }
    return estab_permission_context_snapshot_matches_incident(
        $context,
        $incident
    );
}

/** Compare an explicit policy snapshot without consulting mutable globals. */
function estab_permission_context_snapshot_matches_incident(
    array $context,
    array $incident
): bool {
    try {
        $expectedIncidentId = filter_var(
            $context['incident_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
        );
        $expectedRevision = filter_var(
            $context['revision'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]
        );
        $expectedMode = estab_permission_mode($context['mode'] ?? null);
        $current = estab_permission_context_from_incident($incident);
    } catch (InvalidArgumentException) {
        return false;
    }
    return is_int($expectedIncidentId)
        && is_int($expectedRevision)
        && $expectedIncidentId === $current['incident_id']
        && $expectedRevision === $current['revision']
        && hash_equals($expectedMode, $current['mode']);
}
