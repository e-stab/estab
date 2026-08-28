<?php

declare(strict_types=1);

/**
 * Eine Brücke zwischen zwei Zählungen, und nur eine.
 *
 * Der Vordruck wird zweimal gezählt. Die Ausfüllanleitung nummeriert zwanzig
 * Felder und druckt diese Nummern in die Ecken der Kästen; die Stab-Unterlage
 * zählt siebzehn. Die Anwendung führt zusätzlich einen eigenen Zugriffsindex,
 * mit dem der Arbeitsschritt festlegt, welche Kästen offenstehen.
 *
 * Solange jede Stelle für sich übersetzt, ist jede für sich richtig und das
 * Ganze unlesbar: Ein Kommentar nennt „Feld 19“, die Zeile darunter greift
 * auf Index 16 zu, und wer das nicht auswendig weiss, hält es für einen
 * Fehler -- oder übersieht einen echten. Deshalb gibt es genau eine
 * Übersetzung, und der Vordruck spricht nur noch die Zählung, die er druckt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/nv_field_numbers.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/* --- Die Brücke deckt den ganzen Vordruck ab --- */

$map = estab_nv_field_map();
$assert(
    array_keys($map) === range(1, 20),
    estab_dv_requirement(
        'NV-NUMMERNBRUECKE',
        'Die Brücke kennt die Felder '
            . implode(', ', array_map('strval', array_keys($map)))
            . ' statt 1 bis 20.'
    )
);

/*
 * Die obere Grenze steht an zwei Stellen: hier und in der Schleife, die alle
 * Zugriffsbits zuruecksetzt. Weichen sie voneinander ab, bleibt ein Bit von
 * der vorigen Nachricht stehen -- und ein Feld steht offen, das zu sein
 * haette. Das ist kein sichtbarer Fehler, sondern ein stiller.
 */
$zuruecksetzen = file_get_contents($root . '/4fach/4fachform.php');
$assert(is_string($zuruecksetzen), '4fachform.php ist nicht lesbar.');
$hoechster = max(array_column($map, 'zugriff'));
$assert(
    preg_match(
        '~for \( \$i = 1; \$i <= (\d+); \$i\+\+ \)\{\s*\$this->bg~',
        (string) $zuruecksetzen,
        $grenze
    ) === 1 && (int) $grenze[1] >= $hoechster,
    estab_dv_requirement(
        'NV-NUMMERNBRUECKE',
        'Die Zugriffsbits werden bis ' . ($grenze[1] ?? '?') . ' '
            . 'zurueckgesetzt, die Bruecke reicht aber bis ' . $hoechster
            . '. Ein Bit der vorigen Nachricht bliebe stehen.'
    )
);

foreach ($map as $number => $entry) {
    $assert(
        isset($entry['bezeichnung']) && is_string($entry['bezeichnung'])
            && trim($entry['bezeichnung']) !== '',
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Feld ' . $number . ' der Brücke hat keine Bezeichnung.'
        )
    );
    $access = $entry['zugriff'] ?? null;
    $assert(
        is_int($access) && $access >= 1 && $access <= 17,
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Feld ' . $number . ' verweist auf den Zugriffsindex '
                . var_export($access, true) . ', den es nicht gibt.'
        )
    );
    $assert(
        array_key_exists('unterlage', $entry),
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Feld ' . $number . ' sagt nicht, wie die Unterlage es zählt -- '
                . 'auch ein "gar nicht" ist eine Antwort.'
        )
    );
    $unterlage = $entry['unterlage'];
    $assert(
        $unterlage === null || (is_int($unterlage) && $unterlage >= 1
            && $unterlage <= 17),
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Feld ' . $number . ' verweist auf die Unterlagen-Nummer '
                . var_export($unterlage, true) . ', die es nicht gibt.'
        )
    );
}

// Eine Nummer, die der Vordruck nicht kennt, wird zurückgewiesen -- nicht
// stillschweigend auf einen beliebigen Index abgebildet.
foreach ([0, 21, -1, 100] as $unknown) {
    $rejected = false;
    try {
        estab_nv_access_index($unknown);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert(
        $rejected,
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Die Brücke übersetzt das Feld ' . $unknown . ', das der '
                . 'Vordruck nicht hat.'
        )
    );
}

/*
 * Die Zählung der Stab-Unterlage steht hier und nicht im Anwendungscode: Sie
 * ist ein Auszug aus der Unterlage, gegen den die Brücke gehalten wird.
 */
$unterlage = [
    1 => null, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6, 8 => 7,
    9 => 8, 10 => 9, 11 => null, 12 => 10, 13 => 11, 14 => null, 15 => 12,
    16 => 12, 17 => 14, 18 => 15, 19 => 16, 20 => 17,
];
foreach ($unterlage as $number => $expected) {
    $assert(
        estab_nv_unterlage_number($number) === $expected,
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Feld ' . $number . ' entspricht in der Unterlage '
                . var_export(estab_nv_unterlage_number($number), true)
                . ' statt ' . var_export($expected, true) . '.'
        )
    );
}

/*
 * Der Zugriffsindex ist nicht die Zählung der Unterlage. Er trägt einen
 * ausgemusterten Beförderungshinweis auf Platz 8, und drei Kästen teilen
 * sich einen Index, weil der Arbeitsschritt sie gemeinsam freigibt. Auch
 * diese Liste steht im Test, damit eine Verschiebung auffällt.
 */
$access = [
    1 => 1, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6, 8 => 7,
    9 => 9, 10 => 10, 11 => 10, 12 => 11, 13 => 12, 14 => 12, 15 => 13,
    16 => 12, 17 => 14, 18 => 15, 19 => 16, 20 => 17,
];
foreach ($access as $number => $expected) {
    $assert(
        estab_nv_access_index($number) === $expected,
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Feld ' . $number . ' greift auf Index '
                . estab_nv_access_index($number) . ' statt ' . $expected
                . ' zu.'
        )
    );
}

/* --- Und es gibt keine zweite Übersetzungsstelle --- */

$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
preg_match_all('~\$this->feld\s*\[\s*(\d+)~', $view, $direct);
$assert(
    $direct[1] === [],
    estab_dv_requirement(
        'NV-NUMMERNBRUECKE',
        'Die Ansicht des Vordrucks greift an ' . count($direct[1])
            . ' Stellen unmittelbar auf den Zugriffsindex zu ('
            . implode(', ', $direct[1]) . '); sie übersetzt damit selbst.'
    )
);

/*
 * Im Bestand des alten Formulars bleiben die Indizes stehen, wo sie
 * hingehören: in der Zuteilung selbst und in der ausdrücklich als Beleg
 * aufbewahrten alten Ausgabe, die zur Laufzeit niemand aufruft.
 */
$legacy = file_get_contents($root . '/4fach/4fachform.php');
if (!is_string($legacy)) {
    throw new RuntimeException('Das alte Formular ist nicht lesbar.');
}
$allowedRegions = [];
foreach (
    ['function feldbgcolor', 'function get_access_by_task',
        'function plot_legacy_form'] as $function
) {
    $start = strpos($legacy, $function);
    $assert(
        $start !== false,
        estab_dv_requirement(
            'NV-NUMMERNBRUECKE',
            'Der Bereich ' . $function . ' fehlt; die Prüfung der '
                . 'Übersetzungsstellen ginge ins Leere.'
        )
    );
    if ($start === false) {
        continue;
    }
    $end = strlen($legacy);
    foreach (["\n  }", "\n}"] as $close) {
        $candidate = strpos($legacy, $close, $start);
        if ($candidate !== false && $candidate < $end) {
            $end = $candidate;
        }
    }
    $allowedRegions[] = [$start, $end];
}
$inAllowedRegion = static function (int $offset) use ($allowedRegions): bool {
    foreach ($allowedRegions as [$start, $end]) {
        if ($offset >= $start && $offset <= $end) {
            return true;
        }
    }
    return false;
};

preg_match_all(
    '~\$this->(?:feld|bg)\s*\[\s*(\d+)~',
    $legacy,
    $legacyUses,
    PREG_OFFSET_CAPTURE
);
$stray = [];
foreach ($legacyUses[0] as $use) {
    if (!$inAllowedRegion((int) $use[1])) {
        $stray[] = substr_count(
            substr($legacy, 0, (int) $use[1]),
            "\n"
        ) + 1;
    }
}
$assert(
    $stray === [],
    estab_dv_requirement(
        'NV-NUMMERNBRUECKE',
        'Ausserhalb der Zuteilung und der aufbewahrten alten Ausgabe wird '
            . 'in den Zeilen ' . implode(', ', array_map('strval', $stray))
            . ' auf den Zugriffsindex zugegriffen.'
    )
);

/* --- Kein Kommentar nennt eine Nummer neben einem anderen Bezeichner --- */

/*
 * Gelesen wird zeilenweise: Ein zusammenhängender Kommentarblock, der eine
 * Feldnummer nennt, wird gegen die erste Codezeile darunter gehalten. Ein
 * festes Zeilenfenster reichte nicht -- ein Block wächst um eine Zeile, und
 * die Prüfung schaut am Zugriff vorbei, ohne es zu melden.
 */
foreach (
    ['4fach/4fachform.php' => $legacy,
        '4fach/official_message_form.php' => $view] as $file => $contents
) {
    $pending = null;
    $pendingLine = 0;
    $inComment = false;
    $namesBothScales = false;
    foreach (preg_split('~\r\n|\n~', $contents) ?: [] as $index => $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '//')) {
            if (!$inComment) {
                $pending = null;
                $namesBothScales = false;
            }
            $inComment = true;
            if (preg_match('~\bFeld\s+(\d{1,2})\b~', $trimmed, $named) === 1) {
                $number = (int) $named[1];
                if ($number >= 1 && $number <= 20) {
                    $pending = $number;
                    $pendingLine = $index + 1;
                }
            }
            if (str_contains($trimmed, 'Zugriffsindex')) {
                // Der Kommentar benennt beide Zählungen ausdrücklich.
                $namesBothScales = true;
            }
            continue;
        }
        $wasComment = $inComment;
        $inComment = false;
        if (!$wasComment || $pending === null) {
            $pending = null;
            $namesBothScales = false;
            continue;
        }
        if (
            preg_match('~\$this->(?:feld|bg)\s*\[\s*(\d+)~', $line, $used)
            === 1
        ) {
            $expected = estab_nv_access_index($pending);
            $reached = (int) $used[1];
            $assert(
                $reached === $expected,
                estab_dv_requirement(
                    'NV-NUMMERNBRUECKE',
                    $file . ':' . $pendingLine . ' nennt Feld ' . $pending
                        . ', und die Zeile darunter greift auf Index '
                        . $reached . ' zu; das gedruckte Feld liegt auf '
                        . $expected . '.'
                )
            );
            /*
             * Und selbst wenn die Übersetzung stimmt: Stehen zwei
             * verschiedene Zahlen untereinander, ohne dass der Kommentar
             * sagt, welche Zählung er meint, liest ein Nachfolger das als
             * Fehler -- oder übersieht einen echten.
             */
            $assert(
                $reached === $pending || $namesBothScales,
                estab_dv_requirement(
                    'NV-NUMMERNBRUECKE',
                    $file . ':' . $pendingLine . ' nennt Feld ' . $pending
                        . ' und greift daneben auf ' . $reached . ' zu, '
                        . 'ohne die beiden Zählungen zu unterscheiden.'
                )
            );
        }
        $pending = null;
        $namesBothScales = false;
    }
}

printf("Brücke zwischen den Feldzählungen: OK (%d assertions)\n", $assertions);
