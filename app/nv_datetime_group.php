<?php

declare(strict_types=1);

require_once __DIR__ . '/datetime.php';

/**
 * The Datum-Uhrzeit-Gruppe of the Nachrichtenvordruck: 021234may2026.
 *
 * Where a date could be mistaken, the service regulation joins the two-digit
 * day to the month abbreviation, in English spelling. That is not a quirk:
 * the group is read across incidents and by units that do not run German as
 * their operating language, and an abbreviation only the writing command post
 * understands defeats the purpose it was introduced for.
 *
 * Writing and reading are deliberately asymmetric. New groups are always
 * written in the regulation's spelling; existing records carry "mai", "okt"
 * and "dez" and stay readable. Refusing them would make a record unreadable
 * because its spelling is out of date, which is the opposite of what an
 * append-only evidence trail is for.
 */

/**
 * The abbreviation each month is written with.
 *
 * @return array<string, string>
 */
function estab_nv_month_abbreviations(): array
{
    return [
        '01' => 'jan', '02' => 'feb', '03' => 'mar', '04' => 'apr',
        '05' => 'may', '06' => 'jun', '07' => 'jul', '08' => 'aug',
        '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dec',
    ];
}

/**
 * Every abbreviation that may be read, including the historic German ones.
 *
 * @return array<string, string>
 */
function estab_nv_month_numbers(): array
{
    $numbers = [];
    foreach (estab_nv_month_abbreviations() as $number => $abbreviation) {
        $numbers[$abbreviation] = $number;
    }
    // Bestandsdaten, weiterhin lesbar.
    $numbers['mai'] = '05';
    $numbers['okt'] = '10';
    $numbers['dez'] = '12';
    return $numbers;
}

/** Read one abbreviation, in any capitalisation, or nothing. */
function estab_nv_month_number(mixed $abbreviation): ?int
{
    if (!is_string($abbreviation)) {
        return null;
    }
    $number = estab_nv_month_numbers()[mb_strtolower(trim($abbreviation))]
        ?? null;
    return $number === null ? null : (int) $number;
}

/**
 * Build the full group from a stored timestamp, or nothing.
 *
 * An unusable value yields an empty string rather than a plausible-looking
 * group: an invented time is worse than a missing one.
 */
function estab_nv_datetime_group(mixed $value): string
{
    return estab_datetime_to_tactical($value, estab_nv_month_abbreviations());
}
