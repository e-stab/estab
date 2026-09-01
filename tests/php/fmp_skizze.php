<?php

declare(strict_types=1);

/**
 * Die erzeugte Kommunikationsskizze.
 *
 * Geprueft wird nicht, ob sie huebsch ist -- das entscheidet ein Blick --,
 * sondern ob sie die Aussagen traegt, die der Vordruck verlangt:
 *
 *   * Die Anordnung folgt der Stellenart. Uebergeordnet steht ueber der
 *     Mitte, nachgeordnet darunter, benachbart seitlich. Ein Bild, in dem
 *     die Unterstellung nicht abzulesen ist, ist keine Fuehrungsunterlage.
 *   * Die Linienart nennt das Mittel, auch ohne Farbe und ohne die
 *     taktischen Zeichen. Die Skizze ist ohne sie baubar.
 *   * Der Ersatzweg ist als solcher erkennbar und tritt zurueck.
 *   * Sie traegt Kopfleiste, Fassung und F.d.R. des Plans -- keine zweite
 *     Wahrheit, die von der ersten weglaufen koennte.
 *   * Sie ist eigenstaendig: kein externer Verweis, kein Skript.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/dv_operations.php';
require_once $root . '/app/telecom_sketch.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$weg = static function (array $werte): array {
    return array_replace([
        'fernmeldeplan_eintrag_id' => 1,
        'sortierung' => 1,
        'weg_nummer' => 1,
        'betriebsstelle' => 'Stelle',
        'stellenart' => null,
        'rueckfallebene_fuer_weg' => null,
        'erreichbarkeit' => 'Heros 1',
        'medium' => 'Fu',
        'funkart' => null,
        'kanal' => '',
        'bandlage' => '',
        'verkehrsform' => '',
        'rufgruppe' => '',
        'gegenstellen' => [],
    ], $werte);
};

$plan = [
    'fernmeldeplan_id' => 5,
    'version' => 4,
    'status' => 'AKTIV',
    'einsatzbezeichnung' => 'Übung Rheinhochwasser',
    'herkunft' => 'S 6 Führungsstelle Nord',
    'verfasser_funktion' => 'Fm-Zugführer',
    'gueltig_ab' => '2026-08-29 06:00:00',
    'gueltig_bis' => null,
    'vs_vermerk' => 'NfD',
    'betriebsleitung' => 'LdF Nord',
    'freigegeben_von' => 'S60001',
    'freigabe_dienststellung' => 'Sachgebietsleiter S 6',
    'bemerkungen' => '',
    'eintraege' => [
        $weg([
            'fernmeldeplan_eintrag_id' => 11,
            'weg_nummer' => 11,
            'betriebsstelle' => 'Regionalstelle',
            'stellenart' => 'UEBER',
            'medium' => 'Fu',
            'funkart' => 'DIGITAL',
            'erreichbarkeit' => 'Heros Regional 1',
            'gegenstellen' => [[
                'gegenstelle_id' => 1,
                'name' => 'Regionalstelle Braunschweig',
                'erreichbarkeit' => 'Heros Braunschweig',
                'bemerkungen' => null,
            ]],
        ]),
        $weg([
            'fernmeldeplan_eintrag_id' => 12,
            'weg_nummer' => 12,
            'betriebsstelle' => 'Einsatzabschnitt West',
            'stellenart' => 'UNTER',
            'medium' => 'Fu',
            'funkart' => 'ANALOG',
            'erreichbarkeit' => 'Florian West',
        ]),
        $weg([
            'fernmeldeplan_eintrag_id' => 13,
            'weg_nummer' => 13,
            'betriebsstelle' => 'Einsatzabschnitt West',
            'stellenart' => 'UNTER',
            'medium' => 'Fe',
            'erreichbarkeit' => '0228 940-1550',
            'rueckfallebene_fuer_weg' => 12,
        ]),
        $weg([
            'fernmeldeplan_eintrag_id' => 14,
            'weg_nummer' => 14,
            'betriebsstelle' => 'Kreisleitstelle',
            'stellenart' => 'NEBEN',
            'medium' => 'Me',
            'erreichbarkeit' => 'Melder Tor 2',
        ]),
        $weg([
            'fernmeldeplan_eintrag_id' => 15,
            'weg_nummer' => 15,
            'betriebsstelle' => 'Stadtwerke',
            'stellenart' => null,
            'medium' => '@',
            'erreichbarkeit' => 'netz@stadtwerke.invalid',
        ]),
    ],
];

$svg = estab_telecom_sketch_svg($plan, 'Führungsstelle Übungsplatz');

/* --- Die Anordnung folgt der Stellenart --- */

$baender = estab_telecom_sketch_bands($plan);
$orte = estab_telecom_sketch_layout($baender);
$assert(
    isset(
        $orte['Regionalstelle'],
        $orte['Einsatzabschnitt West'],
        $orte['Kreisleitstelle'],
        $orte['Stadtwerke']
    ),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Eine Stelle des Plans kommt in der Skizze nicht vor.'
    )
);
$mitteY = 430.0;
$assert(
    $orte['Regionalstelle']['y'] < $mitteY,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die übergeordnete Stelle steht nicht über der eigenen. Die '
            . 'Unterstellung ist dann nicht abzulesen.'
    )
);
$assert(
    $orte['Einsatzabschnitt West']['y'] > $mitteY,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die nachgeordnete Stelle steht nicht unter der eigenen.'
    )
);
$assert(
    $orte['Kreisleitstelle']['x'] < 300.0
        || $orte['Kreisleitstelle']['x'] > ESTAB_SKETCH_BREITE - 300.0,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die benachbarte Stelle steht nicht seitlich.'
    )
);
// Eine Stelle ohne Stellenart bekommt die schwaechste Einordnung, nicht die
// Mitte: der Plan weiss nichts ueber sie, und die Skizze behauptet nichts.
$assert(
    $orte['Stadtwerke']['band'] === 'NEBEN',
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Eine Stelle ohne Stellenart wird eingeordnet, als wäre sie bekannt.'
    )
);

/* --- Die Linienart nennt das Mittel, auch ohne Farbe --- */

$arten = [];
foreach ([
    ['Fe', null], ['FAX', null], ['@', null],
    ['Fu', 'ANALOG'], ['Fu', 'DIGITAL'], ['Me', null],
] as [$medium, $funkart]) {
    $arten[$medium . ':' . (string) $funkart] =
        estab_telecom_sketch_line_style($medium, $funkart)['strich'];
}
$assert(
    $arten['Fe:'] === '' && $arten['FAX:'] === '' && $arten['@:'] === '',
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Ein leitergebundener Weg wird nicht durchgezogen gezeichnet.'
    )
);
$assert(
    $arten['Fu:ANALOG'] !== ''
        && $arten['Fu:DIGITAL'] !== ''
        && $arten['Fu:ANALOG'] !== $arten['Fu:DIGITAL'],
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Analog- und Digitalfunk sind in der Skizze nicht zu unterscheiden. '
            . 'Genau diese Trennung war der Anlass der Überarbeitung.'
    )
);
$assert(
    $arten['Me:'] !== $arten['Fu:ANALOG'] && $arten['Me:'] !== '',
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Der Melder ist nicht von einem Funkweg zu unterscheiden.'
    )
);

/* --- Der Ersatzweg tritt zurueck und ist benannt --- */

$assert(
    str_contains($svg, 'Ersatzweg · '),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die Skizze benennt den Ersatzweg nicht. Wer sie liest, hielte ihn '
            . 'für eine gleichrangige Verbindung.'
    )
);
$assert(
    str_contains($svg, 'stroke="#7a7a7a" stroke-width="1"'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Der Ersatzweg ist so kräftig gezeichnet wie der Hauptweg.'
    )
);

/* --- Kopfleiste, Fassung und F.d.R. --- */

foreach ([
    'S 6 Führungsstelle Nord' => 'die herausgebende Dienststelle',
    'Fm-Zugführer' => 'die Funktion des Verfassers',
    'Kommunikationsskizze' => 'die Art der Skizze',
    'Übung Rheinhochwasser' => 'der Verwendungsbereich',
    'NfD' => 'der Verschlusssachenvermerk',
    'Stand: ab 2026-08-29' => 'der Gültigkeitsvermerk',
    'Fernmeldeplan Version 4' => 'die Fassung',
    'F.d.R.: S60001, Sachgebietsleiter S 6' => 'die Bestätigung der Richtigkeit',
] as $text => $was) {
    $assert(
        str_contains($svg, estab_telecom_sketch_html($text)),
        estab_dv_requirement(
            'TKM-FERNMELDEPLAN',
            'In der Kopf- oder Fußzeile der Skizze fehlt ' . $was . '.'
        )
    );
}

/* --- Eigenstaendig und unversehrt --- */

$assert(
    !str_contains($svg, '<script')
        && !str_contains($svg, 'href=')
        && !str_contains($svg, 'xlink')
        && substr_count($svg, 'http') === 1,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die Skizze lädt etwas nach oder führt ein Skript. Eine '
            . 'Führungsunterlage muss ohne Netz lesbar bleiben.'
    )
);
$assert(
    str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg"')
        && str_ends_with(trim($svg), '</svg>'),
    'Die Skizze ist kein vollständiges SVG.'
);

$gefaehrlich = estab_telecom_sketch_svg(
    array_replace($plan, [
        'herkunft' => '<script>alert(1)</script>',
        'einsatzbezeichnung' => '" onload="alert(2)',
    ]),
    'Führungs"stelle'
);
/*
 * Geprueft wird die FLUCHT, nicht das Wort.
 *
 * "onload=" darf als Text vorkommen -- eine Stelle darf so heissen. Gefaehrlich
 * ist erst das unmaskierte Anfuehrungszeichen davor, das aus Text ein Attribut
 * macht. Genau darauf zielt die Probe.
 */
$assert(
    !str_contains($gefaehrlich, '<script>')
        && !str_contains($gefaehrlich, '" onload="')
        && str_contains($gefaehrlich, '&lt;script&gt;')
        && str_contains($gefaehrlich, '&quot; onload=&quot;'),
    'Die Skizze übernimmt Text ungeprüft in das Markup.'
);

/* --- Ohne Wege bleibt sie ein gueltiges Bild --- */

$leer = estab_telecom_sketch_svg(
    array_replace($plan, ['eintraege' => []]),
    'Führungsstelle Übungsplatz'
);
$assert(
    str_contains($leer, 'Kommunikationsskizze')
        && str_ends_with(trim($leer), '</svg>'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Ein Plan ohne Wege bricht die Skizze.'
    )
);

/* --- Ein langer Name sprengt den Kasten nicht --- */

$assert(
    estab_telecom_sketch_kurz(str_repeat('A', 60), 24) !== str_repeat('A', 60)
        && mb_strlen(
            estab_telecom_sketch_kurz(str_repeat('A', 60), 24),
            'UTF-8'
        ) === 24,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Ein langer Stellenname läuft in der Skizze über seinen Kasten '
            . 'hinaus.'
    )
);
$assert(
    estab_telecom_sketch_kurz('Kurz', 24) === 'Kurz',
    'Ein kurzer Name wird unnötig gekürzt.'
);

printf("Kommunikationsskizze: OK (%d assertions)\n", $assertions);
