<?php

declare(strict_types=1);

/**
 * Der Kopf des Vordrucks bleibt heil -- auch schreibgeschützt.
 *
 * > „In manchen Fällen ist der Vordruck etwas falsch dargestellt. Zum
 * > Beispiel beim Ausgang vom Fernmelder oder beim Lesen im Stab."
 *
 * Beides sind Zustände, in denen die Vermerke **nicht** bearbeitbar sind --
 * und genau daran lag es.
 *
 * ## Der Annahmevermerk brach um
 *
 * Ein Vermerk zeigt Datum, Uhrzeit und Handzeichen in drei Zellen, gefüllt
 * aus einem gemeinsamen Feld. Das Feld selbst ist durchsichtig gesetzt; die
 * drei Zellen liegen darüber und zeigen die zerlegten Teile. Solange dort ein
 * `<input>` steht, geht das auf: Ein Eingabefeld bricht nie um.
 *
 * Schreibgeschützt steht dort ein `<span>`. Der Annahmevermerk ist als
 * time-only gesetzt und deshalb nur halb so breit -- 74 statt 147
 * Bildpunkte. Der Wert `251532aug2026` passte nicht hinein und brach auf
 * zwei Zeilen um: 63 statt 32 Bildpunkte. Der Vermerk wurde damit 123
 * Bildpunkte hoch, sein Rasterfeld ist 114 -- und die neun Bildpunkte
 * Überlauf legten die Beschriftungszeile „Datum · Uhrzeit · Hdz." auf den
 * Rufnamen der Gegenstelle darunter.
 *
 * Der durchsichtige Wert soll den Platz des Eingabefeldes einnehmen, nicht
 * mehr. Er bricht deshalb nicht mehr um.
 *
 * ## Der Infopunkt lag auf „Kurier/Melder"
 *
 * Die beiden Mittel-Zeilen hielten rechts 0.55rem frei. Der Infopunkt sitzt
 * dort absolut und ist mit seinem Abstand rund 1.5rem breit. Die letzte
 * Aufschrift lief darunter. Die Vermerkköpfe halten seit jeher 1.8rem frei;
 * die Mittel-Zeilen tun es jetzt auch.
 *
 * Was diese Prüfung nicht kann: nachsehen, ob wirklich nichts mehr
 * überlappt. Das misst tools/bedienpruefung/blick/vordruckkopf.mjs im
 * Browser, bearbeitbar und schreibgeschützt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/stylesheet.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$regeln = estab_test_css_regeln((string) $stylesheet);

/** Die Erklärungen aller Regeln, deren Auswähler ein Muster trifft. */
$erklaerungen = static function (array $regeln, string $muster): array {
    $gefunden = [];
    foreach ($regeln as $regel) {
        if ($regel['kontext'] !== '') {
            continue;
        }
        if (preg_match($muster, $regel['auswaehler']) !== 1) {
            continue;
        }
        foreach ($regel['deklarationen'] as $e) {
            $gefunden[$e['eigenschaft']] = $e['wert'];
        }
    }
    return $gefunden;
};

/* --- Der durchsichtige Wert nimmt den Platz des Eingabefeldes ein --- */

$wert = $erklaerungen(
    $regeln,
    '~estab-official-stamp-(?:datetime|mark) > \.estab-official-readonly\z~'
);
$assert(
    ($wert['white-space'] ?? '') === 'nowrap',
    estab_ux_requirement(
        'GES-VORDRUCK-MASSSTAB',
        'Der schreibgeschützte Wert eines Vermerks darf umbrechen. Der '
            . 'Annahmevermerk ist nur halb so breit; sein Wert brach dort auf '
            . 'zwei Zeilen um, und der Vermerk wurde neun Bildpunkte höher '
            . 'als sein Rasterfeld. Gefunden: '
            . ($wert['white-space'] ?? 'gar keine Angabe')
    )
);
$assert(
    ($wert['overflow'] ?? '') === 'hidden',
    estab_ux_requirement(
        'GES-VORDRUCK-MASSSTAB',
        'Der schreibgeschützte Wert eines Vermerks läuft aus seinem Kasten '
            . 'heraus, statt verborgen zu bleiben. Sichtbar ist er ohnehin '
            . 'nicht -- die drei Zellen darüber zeigen ihn zerlegt.'
    )
);

/* --- Wo ein Infopunkt sitzt, bleibt Platz für ihn --- */

/*
 * Der Infopunkt liegt absolut am rechten Rand seines Abschnitts. Ein
 * Abschnitt, der seinen Inhalt bis an diesen Rand setzt, schiebt ihn unter
 * den Punkt. Die Vermerkköpfe halten dafür seit jeher 1.8rem frei.
 */
$freiraum = static function (string $wert): float {
    $teile = preg_split('~\s+~', trim($wert)) ?: [];
    // padding: oben rechts unten links -- oder kürzer.
    $rechts = match (count($teile)) {
        1 => $teile[0],
        default => $teile[1],
    };
    return (float) str_replace('rem', '', (string) $rechts);
};

foreach ([
    '.estab-official-actual-medium' => 'die Zeile des tatsächlich benutzten '
        . 'Übermittlungsmittels',
    '.estab-official-desired-medium' => 'die Zeile des gewünschten '
        . 'Übermittlungsmittels',
] as $auswaehler => $was) {
    $abschnitt = $erklaerungen($regeln, '~\A' . preg_quote($auswaehler, '~') . '\z~');
    $rechts = ($abschnitt['padding-right'] ?? '') !== ''
        ? (float) str_replace('rem', '', $abschnitt['padding-right'])
        : $freiraum($abschnitt['padding'] ?? '0');
    $assert(
        $rechts >= 1.8,
        estab_ux_requirement(
            'GES-VORDRUCK-LESBAR',
            'In ' . $was . ' bleiben rechts nur ' . $rechts . 'rem frei. Der '
                . 'Infopunkt sitzt dort und ist rund 1.5rem breit -- die '
                . 'letzte Aufschrift läuft darunter. Die Vermerkköpfe halten '
                . '1.8rem frei.'
        )
    );
}

// Und die Reserve der Vermerkköpfe bleibt, an der die Zahl sich bemisst.
$vermerkkopf = $erklaerungen(
    $regeln,
    '~\A\.estab-official-stamp > \.estab-official-cell-heading\z~'
);
$assert(
    ($vermerkkopf['padding-right'] ?? '') === '1.8rem',
    estab_ux_requirement(
        'GES-VORDRUCK-LESBAR',
        'Der Vermerkkopf hält keine 1.8rem für den Infopunkt frei; die '
            . 'Prüfung oben hätte damit kein Maß mehr.'
    )
);

printf("Vordruckkopf: OK (%d assertions)\n", $assertions);
