<?php

declare(strict_types=1);

/**
 * Canonical presentation and validation boundary for message priorities.
 *
 * The database values are part of immutable message evidence and raw CSV
 * exports. They must therefore never be replaced by their user-facing German
 * labels. New forms use the empty string for "keine"; the historic `eee`
 * value remains valid and is displayed with the same non-urgent meaning.
 */

/** Return the exact database value, or null for every unsupported input. */
function estab_message_priority_storage_value(mixed $priority): ?string
{
    if (!is_string($priority)) {
        return null;
    }

    return in_array($priority, ['', 'eee', 'sss', 'bbb', 'aaa'], true)
        ? $priority
        : null;
}

/** Whether a value belongs to the canonical or supported legacy value set. */
function estab_message_priority_is_valid(mixed $priority): bool
{
    return estab_message_priority_storage_value($priority) !== null;
}

/**
 * User-facing label for a stored value.
 *
 * Unknown data remains visibly identifiable without reflecting the malformed
 * value into HTML or a document.
 */
function estab_message_priority_label(mixed $priority): string
{
    return match (estab_message_priority_storage_value($priority)) {
        '', 'eee' => 'keine',
        'sss' => 'Sofort',
        'bbb' => 'Blitz',
        'aaa' => 'Staatsnot',
        default => 'unbekannt',
    };
}

/**
 * Mark printed on the message form.
 *
 * A message without priority receives no mark. This also applies to the
 * historic `eee` representation, which had the same non-urgent behaviour.
 */
function estab_message_priority_document_label(mixed $priority): string
{
    return match (estab_message_priority_storage_value($priority)) {
        '', 'eee' => '',
        'sss' => 'Sofort',
        'bbb' => 'Blitz',
        'aaa' => 'Staatsnot',
        default => 'unbekannt',
    };
}

/**
 * Stable processing rank, independent of MariaDB SET bit positions.
 *
 * Unsupported data sorts behind canonical values and cannot accidentally
 * acquire operational priority.
 */
function estab_message_priority_rank(mixed $priority): int
{
    return match (estab_message_priority_storage_value($priority)) {
        'aaa' => 3,
        'bbb' => 2,
        'sss' => 1,
        '', 'eee' => 0,
        default => -1,
    };
}

/** Whether a canonical value interrupts messages without priority. */
function estab_message_priority_is_urgent(mixed $priority): bool
{
    return estab_message_priority_rank($priority) > 0;
}

/**
 * Whether a row should be highlighted for priority or malformed legacy data.
 *
 * Invalid values are not urgent, but they must not disappear as if they meant
 * "keine".
 */
function estab_message_priority_requires_attention(mixed $priority): bool
{
    return !estab_message_priority_is_valid($priority)
        || estab_message_priority_is_urgent($priority);
}

/**
 * Organisational warning for the highest priority.
 *
 * eStab records the operator and workflow evidence, but cannot establish
 * whether the external originator is legally authorised to issue a
 * Staatsnot-Nachricht. User interfaces exposing this option should show this
 * warning without claiming an application role grants that authority.
 */
function estab_message_priority_warning(mixed $priority): string
{
    return estab_message_priority_storage_value($priority) === 'aaa'
        ? 'Staatsnot nur auf ausdrückliche Weisung einer hierzu berechtigten Stelle verwenden.'
        : '';
}

/**
 * Options for new message forms.
 *
 * `eee` is deliberately absent: it remains readable for historical records,
 * while all new non-urgent messages use the single `keine` option.
 *
 * @return list<array{value:string,label:string,warning:string}>
 */
function estab_message_priority_options(): array
{
    return array_map(
        static fn (string $value): array => [
            'value' => $value,
            'label' => estab_message_priority_label($value),
            'warning' => estab_message_priority_warning($value),
        ],
        ['', 'sss', 'bbb', 'aaa']
    );
}

/**
 * Fixed SQL rank expression for one repository-owned priority column.
 *
 * The allowlist makes this helper unsuitable for request-derived identifiers.
 */
function estab_message_priority_order_sql(string $column): string
{
    if (!in_array(
        $column,
        ['`09_vorrangstufe`', 'm.`09_vorrangstufe`'],
        true
    )) {
        throw new InvalidArgumentException('Invalid priority column expression');
    }

    return "CASE BINARY {$column}"
        . " WHEN 'aaa' THEN 3"
        . " WHEN 'bbb' THEN 2"
        . " WHEN 'sss' THEN 1"
        . " WHEN 'eee' THEN 0"
        . " WHEN '' THEN 0"
        . ' ELSE -1 END';
}
