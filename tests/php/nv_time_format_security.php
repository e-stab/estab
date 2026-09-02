<?php

declare(strict_types=1);

/**
 * Uhrzeiten vierstellig, Datumsangaben mindestens zweistellig.
 *
 * conv_time_datetime() ist die einzige Formatprüfung der Zeitfelder des
 * Vordrucks: vali_data_form::datatest("datumzeit") reicht jedes Zeitfeld an
 * sie weiter und übernimmt den zurückgegebenen Wert als taktische Zeit,
 * aus der konv_taktime_datetime() die Datenbankzeit bildet. Was diese
 * Prüfung durchlässt, steht damit im Vordruck und in der Datenbank.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    require_once $root . '/4fach/vali_data.php';
} finally {
    chdir($originalWorkingDirectory);
}

/** Run a callback where the legacy modules find their relative includes. */
$inMessageRuntime = static function (callable $callback) use ($root): mixed {
    $previous = getcwd();
    if (!is_string($previous) || !chdir($root . '/4fach')) {
        throw new RuntimeException('Cannot enter the message runtime directory');
    }
    try {
        return $callback();
    } finally {
        chdir($previous);
    }
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** @return array{l_data:bool,data:string} */
$convert = static function (string $input) use ($assert): array {
    $result = conv_time_datetime($input);
    $assert(
        is_array($result)
            && array_key_exists('l_data', $result)
            && array_key_exists('data', $result),
        estab_dv_requirement(
            'NV-ZEIT-FORMAT',
            'die Formatprüfung meldet für "' . $input . '" kein Ergebnis'
        )
    );

    return ['l_data' => (bool) $result['l_data'], 'data' => (string) $result['data']];
};

/** A rejected entry stays untouched so the form can show it again. */
$rejects = static function (string $input, string $why) use ($assert, $convert): void {
    $result = $convert($input);
    $assert(
        $result['l_data'] === false,
        estab_dv_requirement(
            'NV-ZEIT-FORMAT',
            $why . ': "' . $input . '" wurde als Zeitangabe angenommen und zu "'
                . $result['data'] . '" umgeschrieben'
        )
    );
    $assert(
        $result['data'] === $input,
        estab_dv_requirement(
            'NV-ZEIT-FORMAT',
            'die abgelehnte Eingabe "' . $input . '" wurde zu "'
                . $result['data'] . '" verändert'
        )
    );
};

/** An accepted entry becomes the tactical time TThhmmMMMjjjj. */
$accepts = static function (
    string $input,
    string $expected,
    int $offset
) use ($assert, $convert): string {
    $result = $convert($input);
    $assert(
        $result['l_data'] === true,
        estab_dv_requirement(
            'NV-ZEIT-FORMAT',
            'die vorschriftsgemäße Angabe "' . $input . '" wurde abgelehnt'
        )
    );
    $assert(
        preg_match('/\A\d{6}[a-z]{3}\d{4}\z/D', $result['data']) === 1,
        estab_dv_requirement(
            'NV-ZEIT-FORMAT',
            '"' . $input . '" ergibt keine taktische Zeit TThhmmMMMjjjj, sondern "'
                . $result['data'] . '"'
        )
    );
    $assert(
        substr($result['data'], $offset, strlen($expected)) === $expected,
        estab_dv_requirement(
            'NV-ZEIT-FORMAT',
            '"' . $input . '" steht in "' . $result['data'] . '" nicht mehr an '
                . 'seiner Stelle'
        )
    );

    return $result['data'];
};

// Vierstellige Uhrzeit: die Grenzen des Tages gehören dazu.
$accepts('0000', '0000', 2);
$accepts('2359', '2359', 2);
$accepts('0900', '0900', 2);
$accepts('0059', '0059', 2);

// Ausserhalb des Tages liegende Uhrzeiten sind keine Uhrzeiten.
foreach (['2400', '2460', '9999', '0060'] as $impossible) {
    $rejects($impossible, 'Uhrzeit ausserhalb von 0000 bis 2359');
}

// Nicht vierstellig heisst nicht lesbar: 900 ist weder 0900 noch 9000.
foreach (['9', '235', '900', '23599', '00000', '2359 ', ''] as $wrongLength) {
    $rejects($wrongLength, 'Uhrzeit nicht vierstellig');
}

// Vier Zeichen sind erst dann eine Uhrzeit, wenn es vier Ziffern sind.
foreach (
    ['1x59', '0e12', '23 9', '2 59', '230 ', ' 900', '09 0', '12 0', '+123',
     '1e2 ', 'abcd', '23:9'] as $notDigits
) {
    $rejects($notDigits, 'Uhrzeit mit Zeichen ausserhalb der Ziffern');
}

// Tag und Uhrzeit: der Tag steht zweistellig vor der vierstelligen Uhrzeit.
$accepts('011200', '011200', 0);
$accepts('311200', '311200', 0);
$accepts('010000', '010000', 0);
$accepts('012359', '012359', 0);

foreach (['001200', '321200', '012400'] as $impossibleDay) {
    $rejects($impossibleDay, 'Tag oder Uhrzeit ausserhalb des Kalenders');
}
foreach (['11200', '0112000'] as $wrongLength) {
    $rejects($wrongLength, 'Datum nicht zweistellig geführt');
}
foreach (['1 1200', '2a1200', '3123 9'] as $notDigits) {
    $rejects($notDigits, 'Datum oder Uhrzeit mit Zeichen ausserhalb der Ziffern');
}

// Volle taktische Zeit TThhmmMMMjjjj bleibt, wie sie erfasst wurde.
$assert(
    $convert('011200mar2024')['data'] === '011200mar2024',
    estab_dv_requirement(
        'NV-ZEIT-FORMAT',
        'die vollständige taktische Zeit wurde beim Prüfen umgeschrieben'
    )
);
$accepts('011200mar2024', '011200mar2024', 0);
$accepts('312359dec2026', '312359dec2026', 0);

foreach (['011200mar20a4', '3100 0feb2024', '01 200mar2024'] as $notDigits) {
    $rejects($notDigits, 'taktische Zeit mit Zeichen ausserhalb der Ziffern');
}
foreach (['011200xyz2024', '011200MAR2024'] as $unknownMonth) {
    $rejects($unknownMonth, 'taktische Zeit ohne bekanntes Monatskürzel');
}
foreach (
    ['001200mar2024', '321200mar2024', '012400mar2024', '011260mar2024',
     '011200mar1999'] as $outOfRange
) {
    $rejects($outOfRange, 'taktische Zeit ausserhalb des Kalenders');
}

// Ein unbekanntes Monatskürzel wird abgelehnt, nicht gemeldet: eine PHP-Notiz
// im Ausgabestrom beschädigt den Vordruck, den die Seite gerade aufbaut.
$diagnostics = [];
set_error_handler(
    static function (int $severity, string $message) use (&$diagnostics): bool {
        $diagnostics[] = $message;

        return true;
    }
);
try {
    conv_time_datetime('011200xyz2024');
} finally {
    restore_error_handler();
}
$assert(
    $diagnostics === [],
    estab_dv_requirement(
        'NV-ZEIT-FORMAT',
        'ein unbekanntes Monatskürzel löst eine PHP-Meldung aus: '
            . implode('; ', $diagnostics)
    )
);

// Der Weg des Vordrucks: jedes Zeitfeld der Regel und die daraus gebildete
// Datenbankzeit. Alle fünf Felder reichen ihre Eingabe an dieselbe
// Formatprüfung weiter, also muss die Regel an jedem von ihnen greifen.
$dvTimeFields = [
    '01_datum' => 'Feld 2',
    '02_zeit' => 'Feld 3',
    '03_datum' => 'Feld 4',
    '12_abfzeit' => 'Feld 16',
    '15_quitdatum' => 'Feld 18',
];

/** @return array{valid:bool,stored:string} */
$fieldVerdict = static function (
    string $field,
    string $value
) use ($inMessageRuntime): array {
    /** @var array{valid:bool,stored:string} $verdict */
    $verdict = $inMessageRuntime(static function () use ($field, $value): array {
        $validator = new vali_data_form([$field => $value]);
        $validator->checkallfields();

        return [
            'valid' => $validator->validate[$field] === true,
            'stored' => (string) $validator->i_data[$field],
        ];
    });

    return $verdict;
};

foreach ($dvTimeFields as $field => $dvNumber) {
    foreach (['1x59', '23 9', '2 59', '2400', '235', '1 1200'] as $malformed) {
        $verdict = $fieldVerdict($field, $malformed);
        $assert(
            $verdict['valid'] === false,
            estab_dv_requirement(
                'NV-ZEIT-FORMAT',
                $dvNumber . ' (' . $field . ') nimmt die Zeitangabe "'
                    . $malformed . '" an'
            )
        );
        $assert(
            $verdict['stored'] === $malformed,
            estab_dv_requirement(
                'NV-ZEIT-FORMAT',
                $dvNumber . ' (' . $field . ') speichert für die abgelehnte '
                    . 'Angabe "' . $malformed . '" den Wert "'
                    . $verdict['stored'] . '"'
            )
        );
    }

    foreach (['2359', '011200', '011200mar2024'] as $wellFormed) {
        $verdict = $fieldVerdict($field, $wellFormed);
        $assert(
            $verdict['valid'] === true,
            estab_dv_requirement(
                'NV-ZEIT-FORMAT',
                $dvNumber . ' (' . $field . ') lehnt die vorschriftsgemäße '
                    . 'Zeitangabe "' . $wellFormed . '" ab'
            )
        );

        $databaseTime = (string) $inMessageRuntime(
            static fn (): string => (string) konv_taktime_datetime(
                $verdict['stored']
            )
        );
        $assert(
            preg_match(
                '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D',
                $databaseTime
            ) === 1,
            estab_dv_requirement(
                'NV-ZEIT-FORMAT',
                'aus der für ' . $dvNumber . ' (' . $field . ') angenommenen '
                    . 'Zeitangabe "' . $wellFormed . '" entsteht die '
                    . 'Datenbankzeit "' . $databaseTime . '"'
            )
        );
    }
}

echo "NV time format tests: OK ({$assertions} assertions)\n";
