<?php

declare(strict_types=1);

/**
 * Die Datum-Uhrzeit-Gruppe: 021234may2026, nicht 021234mai2026.
 *
 * Droht eine Verwechslung des Datums, verbindet die Dienstvorschrift die
 * zweistellige Tagesangabe mit dem Monatskürzel -- und zwar nach englischer
 * Schreibweise. Das ist keine Marotte: Die Gruppe wird einsatzübergreifend
 * gelesen, auch von Stellen, die deutsch nicht als Betriebssprache führen,
 * und ein Kürzel, das nur die eigene Führungsstelle versteht, verfehlt genau
 * den Zweck, für den es eingeführt wurde.
 *
 * Elf der zwölf Kürzel waren bereits englisch. Der Mai stand deutsch da, und
 * eine deutsche Angabe fällt nicht auf: "mai" liest sich für einen deutschen
 * Leser richtig. Bemerkt wird der Bruch erst dort, wo er schadet.
 *
 * Gelesen wird weiterhin beides. Bestandsdaten tragen "mai", "okt" und "dez";
 * sie nachträglich zu verwerfen hiesse, einen Nachweis unlesbar zu machen,
 * weil seine Schreibweise überholt ist.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/nv_datetime_group.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/* --- Geschrieben wird englisch --- */

/*
 * Die Prüfliste steht hier und nicht im Anwendungscode: Sie ist die
 * Schreibweise, gegen die die Anwendung gehalten wird.
 */
$english = [
    '01' => 'jan', '02' => 'feb', '03' => 'mar', '04' => 'apr',
    '05' => 'may', '06' => 'jun', '07' => 'jul', '08' => 'aug',
    '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dec',
];
$written = estab_nv_month_abbreviations();
$assert(
    $written === $english,
    estab_dv_requirement(
        'NV-DATUM-MONATSKUERZEL',
        'Die Monatskürzel lauten ' . implode(', ', $written)
            . ' statt ' . implode(', ', $english) . '.'
    )
);

/* --- Gelesen wird auch, was der Bestand trägt --- */

foreach ($english as $number => $abbreviation) {
    $assert(
        estab_nv_month_number($abbreviation) === (int) $number,
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            'Das Kürzel ' . $abbreviation . ' wird nicht als Monat '
                . $number . ' gelesen.'
        )
    );
}
foreach (['mai' => 5, 'okt' => 10, 'dez' => 12] as $historic => $number) {
    $assert(
        estab_nv_month_number($historic) === $number,
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            'Die Bestandsschreibweise ' . $historic . ' wird nicht mehr '
                . 'gelesen; ein vorhandener Nachweis würde unlesbar.'
        )
    );
}
// Auch in Grossschreibung, wie sie ein Fernschreiben trägt.
foreach (['MAY' => 5, 'Dez' => 12, 'OCT' => 10] as $written => $number) {
    $assert(
        estab_nv_month_number($written) === $number,
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            'Das Kürzel ' . $written . ' wird nur in Kleinschreibung '
                . 'gelesen.'
        )
    );
}
foreach (['', 'ma', 'mrz', 'januar', '05', 'xyz'] as $unknown) {
    $assert(
        estab_nv_month_number($unknown) === null,
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            'Die Zeichenfolge ' . var_export($unknown, true)
                . ' gilt als Monatskürzel.'
        )
    );
}

/* --- Und die Gruppe wird ganz gebildet --- */

foreach (
    [
        '2026-05-02 12:34:00' => '021234may2026',
        '2026-10-31 23:59:59' => '312359oct2026',
        '2026-01-01 00:00:00' => '010000jan2026',
    ] as $stored => $group
) {
    $assert(
        estab_nv_datetime_group($stored) === $group,
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            'Aus ' . $stored . ' entsteht die Gruppe '
                . var_export(estab_nv_datetime_group($stored), true)
                . ' statt ' . $group . '.'
        )
    );
}
// Auch eine Angabe ohne Sekunden ist keine gespeicherte Zeit: Die Datenbank
// fuehrt sie vollstaendig, und eine halbe Angabe stammt nicht von dort.
foreach (
    ['', '0000-00-00 00:00:00', 'irgendwas', null, 42, '2026-05-02 12:34',
        '2026-02-30 12:34:00'] as $unusable
) {
    $assert(
        estab_nv_datetime_group($unusable) === '',
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            'Aus ' . var_export($unusable, true) . ' entsteht die Gruppe '
                . var_export(estab_nv_datetime_group($unusable), true)
                . '; eine erfundene Zeit ist schlimmer als keine.'
        )
    );
}

/* --- Die Laufzeit benutzt dieselbe Tabelle --- */

/*
 * Zwei Tabellen, die dasselbe sagen sollen, sagen es irgendwann nicht mehr.
 * Der Bestand führte sie an zwei Stellen; die Anwendung liest jetzt beide
 * aus der Brücke.
 */
foreach (
    ['4fcfg/config.inc.php', '4fach/tools.php'] as $file
) {
    $contents = file_get_contents($root . '/' . $file);
    $assert(
        is_string($contents),
        'Nicht lesbar: ' . $file
    );
    if (!is_string($contents)) {
        continue;
    }
    $assert(
        !preg_match('~"05"\s*=>\s*\'mai\'~', $contents),
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            $file . ' schreibt den Mai deutsch.'
        )
    );
    // Der Bestand setzt ein Leerzeichen vor die Klammer; das ist seine
    // Schreibweise und kein zweiter Aufruf.
    $assert(
        preg_match(
            '~estab_nv_month_(?:abbreviations|numbers)\s*\(~',
            $contents
        ) === 1,
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            $file . ' führt eine eigene Monatstabelle; zwei Tabellen, die '
                . 'dasselbe sagen sollen, sagen es irgendwann nicht mehr.'
        )
    );
}

/* --- Und die Laufzeit bildet dieselbe Gruppe --- */

$previousDirectory = getcwd();
if (!is_string($previousDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    foreach (
        [
            '2026-05-02 12:34:00' => '021234may2026',
            '2026-12-24 18:00:00' => '241800dec2026',
        ] as $stored => $group
    ) {
        $assert(
            konv_datetime_taktime($stored) === $group,
            estab_dv_requirement(
                'NV-DATUM-MONATSKUERZEL',
                'Die Laufzeit bildet aus ' . $stored . ' die Gruppe '
                    . var_export(konv_datetime_taktime($stored), true)
                    . ' statt ' . $group . '.'
            )
        );
        $assert(
            konv_taktime_datetime($group) === $stored,
            estab_dv_requirement(
                'NV-DATUM-MONATSKUERZEL',
                'Die Gruppe ' . $group . ' wird nicht zu ' . $stored
                    . ' zurückgelesen.'
            )
        );
    }
    // Und der Bestand bleibt lesbar.
    $assert(
        konv_taktime_datetime('021234mai2026') === '2026-05-02 12:34:00',
        estab_dv_requirement(
            'NV-DATUM-MONATSKUERZEL',
            'Eine im Bestand vorhandene Gruppe mit deutschem Kürzel wird '
                . 'nicht mehr gelesen.'
        )
    );
} finally {
    chdir($previousDirectory);
}

printf("Datum-Uhrzeit-Gruppe: OK (%d assertions)\n", $assertions);
