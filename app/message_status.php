<?php

declare(strict_types=1);

/**
 * The workflow states of one message form.
 *
 * The numbers come from the imported schema and cannot change without a data
 * migration, but they carried no name: the value 4 appeared as a bare literal
 * in ten files, and reading a route meant remembering which number belonged to
 * which station. The constants give every state the name the Dienstvorschrift
 * uses, and estab_message_status_transitions() states the closed set of routes
 * in one place instead of scattering it across predicates.
 */

/** Draft: written, not yet submitted. */
const ESTAB_MESSAGE_STATUS_DRAFT = 0;

/** Waiting for the Leiter des Fernmeldebetriebes. */
const ESTAB_MESSAGE_STATUS_LDF = 1;

/** Disposed, waiting for Aufnahme und Weitergabe to transport it. */
const ESTAB_MESSAGE_STATUS_TRANSPORT = 2;

/** Waiting for the Sichter. */
const ESTAB_MESSAGE_STATUS_REVIEW = 4;

/** Closed: the evidence is complete. */
const ESTAB_MESSAGE_STATUS_CLOSED = 8;

/** Formally returned to its author for correction. */
const ESTAB_MESSAGE_STATUS_RETURNED = 10;

/**
 * Every state the schema allows, in the order a message passes them.
 *
 * @return list<int>
 */
function estab_message_status_values(): array
{
    return [
        ESTAB_MESSAGE_STATUS_DRAFT,
        ESTAB_MESSAGE_STATUS_LDF,
        ESTAB_MESSAGE_STATUS_TRANSPORT,
        ESTAB_MESSAGE_STATUS_REVIEW,
        ESTAB_MESSAGE_STATUS_CLOSED,
        ESTAB_MESSAGE_STATUS_RETURNED,
    ];
}

/** Operator-facing name of one state. */
function estab_message_status_name(mixed $status): string
{
    return match (estab_message_status($status)) {
        ESTAB_MESSAGE_STATUS_DRAFT => 'Entwurf',
        ESTAB_MESSAGE_STATUS_LDF => 'Bei LdF',
        ESTAB_MESSAGE_STATUS_TRANSPORT => 'In Beförderung',
        ESTAB_MESSAGE_STATUS_REVIEW => 'In Sichtung',
        ESTAB_MESSAGE_STATUS_CLOSED => 'Abgeschlossen',
        ESTAB_MESSAGE_STATUS_RETURNED => 'Zur Korrektur',
        default => 'Unbekannter Stand',
    };
}

/**
 * Parse a stored state without numeric coercion.
 *
 * filter_var() would turn true into 1 and hand a boolean the state of the
 * Leiter des Fernmeldebetriebes. Only an integer, or the decimal string the
 * database returns, is a state.
 */
function estab_message_status(mixed $value): ?int
{
    if (is_int($value)) {
        $parsed = $value;
    } elseif (
        is_string($value)
        && preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $value) === 1
    ) {
        $parsed = (int) $value;
    } else {
        return null;
    }
    return in_array($parsed, estab_message_status_values(), true)
        ? $parsed
        : null;
}

/**
 * The closed set of routes, keyed by the kind of message that may take them.
 *
 * "incoming" and "outgoing" are the two directions of the paper form;
 * "conversation-note" documents a call that already happened and therefore
 * ends with the Sichter.
 *
 * @return array<string, list<array{from:int,to:int,station:string}>>
 */
function estab_message_status_transitions(): array
{
    return [
        'incoming' => [
            ['from' => ESTAB_MESSAGE_STATUS_DRAFT,
             'to' => ESTAB_MESSAGE_STATUS_LDF, 'station' => 'A/W'],
            ['from' => ESTAB_MESSAGE_STATUS_LDF,
             'to' => ESTAB_MESSAGE_STATUS_REVIEW, 'station' => 'LdF'],
            ['from' => ESTAB_MESSAGE_STATUS_REVIEW,
             'to' => ESTAB_MESSAGE_STATUS_CLOSED, 'station' => 'Si'],
        ],
        'outgoing' => [
            ['from' => ESTAB_MESSAGE_STATUS_DRAFT,
             'to' => ESTAB_MESSAGE_STATUS_REVIEW, 'station' => 'Verfasser'],
            ['from' => ESTAB_MESSAGE_STATUS_REVIEW,
             'to' => ESTAB_MESSAGE_STATUS_LDF, 'station' => 'Si'],
            ['from' => ESTAB_MESSAGE_STATUS_REVIEW,
             'to' => ESTAB_MESSAGE_STATUS_RETURNED, 'station' => 'Si'],
            ['from' => ESTAB_MESSAGE_STATUS_RETURNED,
             'to' => ESTAB_MESSAGE_STATUS_REVIEW, 'station' => 'Verfasser'],
            ['from' => ESTAB_MESSAGE_STATUS_LDF,
             'to' => ESTAB_MESSAGE_STATUS_TRANSPORT, 'station' => 'LdF'],
            ['from' => ESTAB_MESSAGE_STATUS_LDF,
             'to' => ESTAB_MESSAGE_STATUS_RETURNED, 'station' => 'LdF'],
            ['from' => ESTAB_MESSAGE_STATUS_TRANSPORT,
             'to' => ESTAB_MESSAGE_STATUS_CLOSED, 'station' => 'A/W'],
            ['from' => ESTAB_MESSAGE_STATUS_TRANSPORT,
             'to' => ESTAB_MESSAGE_STATUS_LDF, 'station' => 'A/W'],
        ],
        'conversation-note' => [
            ['from' => ESTAB_MESSAGE_STATUS_DRAFT,
             'to' => ESTAB_MESSAGE_STATUS_REVIEW, 'station' => 'Verfasser'],
            ['from' => ESTAB_MESSAGE_STATUS_REVIEW,
             'to' => ESTAB_MESSAGE_STATUS_CLOSED, 'station' => 'Si'],
            ['from' => ESTAB_MESSAGE_STATUS_REVIEW,
             'to' => ESTAB_MESSAGE_STATUS_RETURNED, 'station' => 'Si'],
            ['from' => ESTAB_MESSAGE_STATUS_RETURNED,
             'to' => ESTAB_MESSAGE_STATUS_REVIEW, 'station' => 'Verfasser'],
        ],
    ];
}
