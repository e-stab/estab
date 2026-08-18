<?php

declare(strict_types=1);

/**
 * Feld 16 gehört dem Verfasser, nicht der Serveruhr.
 *
 * Die Ausfüllanleitung verlangt in Feld 16 die Abfassungszeit der Nachricht.
 * Die Anwendung beobachtet, wann eine Eingabe bei ihr eintrifft; wann eine
 * Nachricht abgefasst wurde, beobachtet sie nicht. Eine stille Vorbelegung
 * mit der Erfassungszeit trägt deshalb eine erfundene Uhrzeit in den
 * Nachweis, in das Einsatztagebuch und in jeden Ausdruck ein, ohne dass der
 * Bearbeiter es bemerkt. Dieser Test hält fest, dass kein Schreibpfad die
 * Abfassungszeit ersetzt und dass die Pflichtfeldprüfung sie stattdessen
 * beim Bearbeiter einfordert.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/4fach/vali_data.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
};
$slice = static function (
    string $source,
    string $startMarker,
    string $endMarker
): string {
    $start = strpos($source, $startMarker);
    $end = strpos(
        $source,
        $endMarker,
        $start === false ? 0 : $start + strlen($startMarker)
    );
    if (!is_int($start) || !is_int($end) || $end <= $start) {
        throw new RuntimeException(
            'Could not isolate the region starting at ' . $startMarker
        );
    }
    return substr($source, $start, $end - $start);
};

/*
 * Ohne Angabe bleibt Feld 16 ungültig. Die Feldprüfung startet für jedes
 * Pflichtfeld auf "nicht geprüft" und wird nur durch eine lesbare Angabe
 * wahr. Ein Formular ohne Feld 16 kann damit nicht durchlaufen.
 */
$withoutCompositionTime = new vali_data_form(
    array('12_inhalt' => 'Lagemeldung Abschnitt Nord')
);
$withoutCompositionTime->checkallfields();
$assert(
    ($withoutCompositionTime->validate['12_abfzeit'] ?? null) === false,
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Ein Vordruck ohne Feld 16 gilt der Feldprüfung als vollständig'
    )
);

/*
 * Die Zeitprüfung selbst bleibt fehlerschliessend: sie kennt nur die
 * vierstellige Uhrzeit und die beiden taktischen Langformen. Jede andere
 * Länge, auch die leere Eingabe des Formulars, fällt in den Default.
 */
$timeParser = $slice(
    $read('4fach/tools.php'),
    'function conv_time_datetime',
    '} //conv_time_datetime'
);
$assert(
    str_contains($timeParser, 'default: $l_data = false;'),
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Die Zeitprüfung weist eine unbekannte Eingabelänge nicht mehr ab; '
            . 'eine leere Abfassungszeit könnte damit als gültig gelten'
    )
);
$assert(
    str_contains(
        $read('4fach/vali_data.php'),
        '$valid = conv_time_datetime ($data);'
    ),
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Die Feldprüfung benutzt die fehlerschliessende Zeitprüfung nicht mehr'
    )
);

/*
 * Kein Schreibpfad der ausgelieferten Anwendung ersetzt die Abfassungszeit
 * durch eine Serverzeit. Der Scan bleibt auf die Laufzeitverzeichnisse
 * beschränkt; Testfixtures dürfen feste Zeitstempel setzen.
 */
$clockSubstitution = '~[\'"]12_abfzeit[\'"]\s*\]?\s*(?:=>|=)\s*'
    . '(?:date|gmdate|time|mktime|strtotime|new\s+DateTime)\b~i';
$substituting = array();
foreach (array('4fach', '4fadm', '4fueltg', 'app') as $directory) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root . '/' . $directory,
            FilesystemIterator::SKIP_DOTS
        )
    );
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            throw new RuntimeException(
                'Could not read ' . $file->getPathname()
            );
        }
        if (preg_match($clockSubstitution, $source) === 1) {
            $substituting[] = $directory . '/' . $file->getFilename();
        }
    }
}
sort($substituting, SORT_STRING);
$assert(
    $substituting === array(),
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Diese Laufzeitdateien setzen eine Serverzeit als Abfassungszeit ein: '
            . implode(', ', $substituting)
    )
);

/*
 * Alle vier Arbeitsschritte, in denen ein Verfasser den Vordruck ausfüllt,
 * schreiben weiterhin genau den vom Bearbeiter angegebenen Wert fort.
 */
$handler = $read('4fach/data_hndl.php');
$assert(
    substr_count($handler, '"12_abfzeit" => konv_taktime_datetime') === 4,
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Nicht mehr alle vier Verfasserschritte schreiben die vom Bearbeiter '
            . 'angegebene Abfassungszeit fort'
    )
);

/*
 * Der Gegenfall bleibt bestehen: den Eingang beobachtet die Anwendung
 * selbst, deshalb ist die Vorbelegung der Eingangszeit in Feld 1 ein
 * Nachweis und keine Erfindung. Diese Zusicherung hält die Grenze zwischen
 * beobachteter und behaupteter Zeit fest.
 */
$assert(
    preg_match_all(
        '~\$data \["01_datum"\]\s*=\s*date \("Hi"\)~',
        $handler
    ) === 2,
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Die beobachtete Eingangszeit in Feld 1 wird nicht mehr vorbelegt; '
            . 'die Abgrenzung zur nicht beobachtbaren Abfassungszeit fehlt'
    )
);

/*
 * Die Pflichtfeldprüfung je Arbeitsschritt bleibt bestehen. Sonst liesse
 * sich die nun sichtbare Fehlermeldung durch Streichen der Prüfung
 * stillstellen statt durch Ausfüllen des Feldes.
 */
$validatorSource = $read('4fach/vali_data.php');
$validatorSections = array(
    'FM-Eingang' => $slice(
        $validatorSource,
        'case "FM-Eingang"',
        'case "Stab_schreiben"'
    ),
    'Stab_schreiben/Stab_korrigieren' => $slice(
        $validatorSource,
        'case "Stab_schreiben"',
        'case "Stab_gesprnoti"'
    ),
    'Stab_gesprnoti' => $slice(
        $validatorSource,
        'case "Stab_gesprnoti"',
        'case "FM-Ausgang"'
    ),
);
foreach ($validatorSections as $task => $section) {
    $assert(
        str_contains($section, '$this->validate["12_abfzeit"]'),
        estab_dv_requirement(
            'NV-16-ABFASSUNGSZEIT',
            'Der Arbeitsschritt ' . $task
                . ' verlangt die Abfassungszeit nicht mehr als Pflichtangabe'
        )
    );
}
$assert(
    str_contains(
        $validatorSections['Stab_schreiben/Stab_korrigieren'],
        'case "Stab_korrigieren"'
    ),
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Die Korrektur teilt die Pflichtprüfung des Verfassens nicht mehr'
    )
);

/*
 * Der Vordruck verlangt vom Bearbeiter dasselbe wie der Server.
 */
$compositionHelp = $slice(
    $read('4fach/official_message_form.php'),
    '16 => [',
    '17 => ['
);
$assert(
    str_contains($compositionHelp, 'Abfassungszeit')
    && str_contains($compositionHelp, 'Immer ausfüllen'),
    estab_dv_requirement(
        'NV-16-ABFASSUNGSZEIT',
        'Die Ausfüllhilfe 16 fordert die Abfassungszeit nicht mehr ein'
    )
);

echo 'DV composition time: OK (' . $assertions . " assertions)\n";
