<?php

declare(strict_types=1);

/**
 * Feld 19: wie aus der Empfaengermatrix die drei gedruckten Gruppen werden.
 *
 * Der Vordruck zeigt den Verteiler nicht als 5x4-Matrix, sondern in drei
 * Bloecken: die Fuehrung (Leiter und S 1 bis S 6), die Fachberater und die
 * Verbindungsstellen. Die Matrix aus `4fcfg/fkt_rolle.inc.php` ist frei
 * belegbar; welche Zelle in welchen Block gehoert, entscheidet die Rolle
 * und der Funktionsname, nicht die Position.
 *
 * Diese Zuordnung stand bisher nur in der Bildschirmansicht. Das PDF hat
 * die Matrix stattdessen roh als 5x4-Gitter gedruckt -- derselbe Verteiler,
 * ein anderes Bild. Damit beide dasselbe Blatt zeigen, steht die Zuordnung
 * hier einmal.
 */

/**
 * Zerlegt die gespeicherte Empfaengerzeile in Funktion und Durchschriften.
 *
 * Gespeichert wird `S3_bl,S2_rt` -- Funktion, Unterstrich, Blattfarbe.
 *
 * @return array<string,array{function:string,copies:list<string>}>
 */
function estab_nv_gespeicherte_empfaenger(string $distribution): array
{
    $recipients = [];
    foreach (explode(',', $distribution) as $token) {
        $token = trim($token);
        if (
            preg_match('/\A(.+)_(bl|gn|rt|ge|gb)\z/Di', $token, $parts) !== 1
        ) {
            continue;
        }
        $function = trim($parts[1]);
        $colour = strtolower($parts[2]);
        if ($function === '') {
            continue;
        }
        $key = strtoupper($function);
        $recipients[$key] ??= ['function' => $function, 'copies' => []];
        if (!in_array($colour, $recipients[$key]['copies'], true)) {
            $recipients[$key]['copies'][] = $colour;
        }
    }
    return $recipients;
}

/** Die sieben Plaetze der Fuehrungsspalte in ihrer gedruckten Folge. */
function estab_nv_verteiler_fuehrung(): array
{
    return [
        0 => ['display' => 'Leiter', 'keys' => ['LS', 'LEITER']],
        1 => ['display' => 'S1', 'keys' => ['S1']],
        2 => ['display' => 'S2', 'keys' => ['S2']],
        3 => ['display' => 'S3', 'keys' => ['S3']],
        4 => ['display' => 'S4', 'keys' => ['S4']],
        5 => ['display' => 'S5', 'keys' => ['S5']],
        6 => ['display' => 'S6', 'keys' => ['S6']],
    ];
}

/**
 * Ordnet die Empfaengermatrix den drei gedruckten Bloecken zu.
 *
 * `$matrix` ist die 5x4-Matrix mit `fkt` und `rolle` je Zelle, `$stored`
 * das Ergebnis von estab_nv_gespeicherte_empfaenger(). Eine Funktion, die
 * gespeichert ist, aber in keiner Zelle mehr steht, faellt nicht unter den
 * Tisch: sie steht in `extras` und wird ausserhalb des Blattes benannt.
 *
 * @param array<int,array<int,array<string,mixed>>> $matrix
 * @param array<string,array{function:string,copies:list<string>}> $stored
 * @return array{
 *   groups:array<string,list<array<string,mixed>>>,
 *   extras:list<array<string,mixed>>,
 *   all:list<array<string,mixed>>
 * }
 */
function estab_nv_verteiler_modell(array $matrix, array $stored): array
{
    $groups = ['lead' => [], 'adviser' => [], 'liaison' => []];
    $extras = [];
    $all = [];
    $representedFunctions = [];
    $leadDefinitions = estab_nv_verteiler_fuehrung();
    $leadSlots = array_fill(0, count($leadDefinitions), null);

    for ($row = 1; $row <= 5; $row++) {
        for ($column = 1; $column <= 4; $column++) {
            $cell = $matrix[$row][$column] ?? [];
            $function = trim((string)($cell['fkt'] ?? ''));
            if ($function === '') {
                continue;
            }
            $key = strtoupper($function);
            $entry = [
                'row' => $row,
                'column' => $column,
                'function' => $function,
                'role' => (string)($cell['rolle'] ?? ''),
                'copies' => is_array($stored[$key]['copies'] ?? null)
                    ? $stored[$key]['copies']
                    : [],
            ];
            $representedFunctions[$key] = true;
            $all[] = $entry;
            if ($entry['role'] === 'FB') {
                $groups['adviser'][] = $entry;
                continue;
            }
            if (
                str_starts_with($key, 'VB')
                || str_starts_with($key, 'VERB')
            ) {
                $groups['liaison'][] = $entry;
                continue;
            }
            $leadKey = strtoupper(
                preg_replace('/\s+/u', '', $function) ?? $function
            );
            $leadPosition = null;
            foreach ($leadDefinitions as $position => $definition) {
                if (in_array($leadKey, $definition['keys'], true)) {
                    $leadPosition = $position;
                    break;
                }
            }
            if ($leadPosition !== null && $leadSlots[$leadPosition] === null) {
                $entry['display'] = $leadDefinitions[$leadPosition]['display'];
                $leadSlots[$leadPosition] = $entry;
                continue;
            }
            $entry['display'] = $function;
            $extras[] = $entry;
        }
    }

    foreach ($leadDefinitions as $position => $definition) {
        if ($leadSlots[$position] !== null) {
            $groups['lead'][] = $leadSlots[$position];
            continue;
        }
        $storedLead = null;
        foreach ($definition['keys'] as $leadKey) {
            if (isset($stored[$leadKey])) {
                $storedLead = $stored[$leadKey];
                $representedFunctions[$leadKey] = true;
                break;
            }
        }
        $groups['lead'][] = [
            'display' => $definition['display'],
            'function' => (string)(
                $storedLead['function'] ?? $definition['display']
            ),
            'copies' => is_array($storedLead['copies'] ?? null)
                ? $storedLead['copies']
                : [],
            'unavailable' => true,
        ];
    }

    // Fachberater und Verbindungsstellen fuehren je sechs Plaetze. Mehr
    // druckt das Blatt nicht; weniger laesst es leer stehen.
    foreach (['adviser', 'liaison'] as $group) {
        if (count($groups[$group]) > 6) {
            $extras = array_merge($extras, array_slice($groups[$group], 6));
            $groups[$group] = array_slice($groups[$group], 0, 6);
        }
        while (count($groups[$group]) < 6) {
            $groups[$group][] = [
                'display' => '',
                'function' => '',
                'copies' => [],
                'unavailable' => true,
            ];
        }
    }

    foreach ($stored as $key => $storedRecipient) {
        if (isset($representedFunctions[$key])) {
            continue;
        }
        $extras[] = [
            'display' => $storedRecipient['function'],
            'function' => $storedRecipient['function'],
            'copies' => $storedRecipient['copies'],
            'historical' => true,
            'unavailable' => true,
        ];
        $representedFunctions[$key] = true;
    }

    return ['groups' => $groups, 'extras' => $extras, 'all' => $all];
}

/** Die gedruckten Ueberschriften der drei Bloecke. */
function estab_nv_verteiler_ueberschriften(): array
{
    return [
        'lead' => 'TEL/EL/EAL/UEAL',
        'adviser' => 'Fachberater',
        'liaison' => 'Verb.stellen',
    ];
}
