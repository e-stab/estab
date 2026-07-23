<?php

/**
 * Compatibility helpers for nullable MariaDB date/time columns.
 *
 * Legacy eStab installations represented "not set" as MySQL's zero date.
 * The strict MariaDB schema uses SQL NULL instead.  Keeping the recognition in
 * one place prevents display, export and backup paths from attempting to parse
 * NULL while still accepting records imported from an old database.
 */

/** Return true for every historic representation of an unset SQL date. */
function estab_datetime_is_unset(mixed $value): bool
{
    if ($value === null) {
        return true;
    }
    if (!is_string($value)) {
        return false;
    }

    $value = trim($value);
    return $value === ''
        || $value === '0000-00-00'
        || $value === '0000-00-00 00:00:00';
}

/**
 * Parse a database DATETIME without emitting warnings for NULL or old zero
 * dates. Invalid values are treated as unset instead of being reformatted into
 * another invalid date.
 *
 * @return array{date:string,time:string,datum:string,zeit:string,stak:string,year:string,month:string,day:string,hour:string,minute:string,second:string}
 */
function estab_datetime_parts(mixed $value): array
{
    $empty = [
        'date' => '',
        'time' => '',
        'datum' => '',
        'zeit' => '',
        'stak' => '',
        'year' => '',
        'month' => '',
        'day' => '',
        'hour' => '',
        'minute' => '',
        'second' => '',
    ];

    if (estab_datetime_is_unset($value) || !is_string($value)) {
        return $empty;
    }

    $value = trim($value);
    if (preg_match(
        '/\A(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})\z/D',
        $value,
        $matches
    ) !== 1) {
        return $empty;
    }

    [, $year, $month, $day, $hour, $minute, $second] = $matches;
    if (
        !checkdate((int) $month, (int) $day, (int) $year)
        || (int) $hour > 23
        || (int) $minute > 59
        || (int) $second > 59
    ) {
        return $empty;
    }

    return [
        'date' => $year . '-' . $month . '-' . $day,
        'time' => $hour . ':' . $minute . ':' . $second,
        'datum' => $day . $month,
        'zeit' => $hour . $minute,
        'stak' => $day . $hour . $minute,
        'year' => $year,
        'month' => $month,
        'day' => $day,
        'hour' => $hour,
        'minute' => $minute,
        'second' => $second,
    ];
}

/** Convert a valid database value to eStab's long tactical-time notation. */
function estab_datetime_to_tactical(mixed $value, array $monthNames): string
{
    $parts = estab_datetime_parts($value);
    if ($parts['date'] === '' || !isset($monthNames[$parts['month']])) {
        return '';
    }

    return $parts['day']
        . $parts['hour']
        . $parts['minute']
        . (string) $monthNames[$parts['month']]
        . $parts['year'];
}
