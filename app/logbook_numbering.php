<?php

declare(strict_types=1);

/**
 * Format the official reference written on one digital ETB attachment unit.
 *
 * eStab keeps exactly one ETB per incident. The immutable incident ID is
 * therefore the automatically allocated ETB identifier; the second component
 * is the incident-local ETB entry number. A stored upload is treated as one
 * bundled digital unit, matching the handbook variant where several pages are
 * kept together under one attachment number.
 */
function estab_logbook_etb_attachment_number(
    int $incidentId,
    int $entryNumber,
    int $unitNumber = 1
): string {
    if ($incidentId < 1 || $entryNumber < 1 || $unitNumber < 1) {
        throw new InvalidArgumentException(
            'ETB attachment number components must be positive.'
        );
    }

    return 'ETB ' . $incidentId . '-' . $entryNumber . '-' . $unitNumber;
}

/**
 * @return array{incident_id:int,entry_number:int,unit_number:int}|null
 */
function estab_logbook_parse_etb_attachment_number(string $value): ?array
{
    $value = trim($value);
    if (
        preg_match(
            '/\A(?:ETB[ ]+)?([1-9][0-9]*)-([1-9][0-9]*)-([1-9][0-9]*)\z/Di',
            $value,
            $matches
        ) !== 1
    ) {
        return null;
    }

    foreach (array_slice($matches, 1, 3) as $component) {
        if (strlen($component) > 18) {
            return null;
        }
    }

    $incidentId = (int) $matches[1];
    $entryNumber = (int) $matches[2];
    $unitNumber = (int) $matches[3];
    if ($incidentId < 1 || $entryNumber < 1 || $unitNumber < 1) {
        return null;
    }

    return [
        'incident_id' => $incidentId,
        'entry_number' => $entryNumber,
        'unit_number' => $unitNumber,
    ];
}
