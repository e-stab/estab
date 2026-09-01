<?php

declare(strict_types=1);

/**
 * Die erzeugte Kommunikationsskizze nach Fb Fue 77.
 *
 * Der Vordruck ist eindeutig, und die Pruefung haelt genau das fest:
 *
 *   * In der Mitte steht die EIGENE Fuehrungsstelle mit ihrem Funkrufnamen
 *     und ihren Mitteln. Der Fernmeldeplan legt die EIGENEN Erreichbarkeiten
 *     fest; fremde Stellen darzustellen ist nicht seine Aufgabe.
 *   * Links, wen wir nach oben erreichen. Rechts, wen wir nach unten
 *     erreichen. Die Stellenart gehoert deshalb der GEGENSTELLE -- am
 *     eigenen Weg waere sie sinnlos.
 *   * Die Linienart nennt das Mittel, auch ohne Farbe und ohne die
 *     taktischen Zeichen.
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

/** Eines UNSERER Mittel. Die Stelle ist unsere eigene Betriebsstelle. */
$weg = static function (array $werte): array {
    return array_replace([
        'fernmeldeplan_eintrag_id' => 1,
        'sortierung' => 1,
        'weg_nummer' => 1,
        'betriebsstelle' => 'Fm-Zentrale',
        'stellenart' => null,
        'rueckfallebene_fuer_weg' => null,
        'erreichbarkeit' => 'Heros Übungsplatz 10',
        'medium' => 'Fu',
        'funkart' => null,
        'kanal' => '',
        'bandlage' => '',
        'verkehrsform' => '',
        'rufgruppe' => '',
        'gegenstellen' => [],
    ], $werte);
};
/** Eine Gegenstelle: die ANDERE Seite, mit ihrer Richtung. */
$gegen = static function (
    int $id,
    string $name,
    ?string $art,
    string $erreichbarkeit
): array {
    return [
        'gegenstelle_id' => $id,
        'sortierung' => $id,
        'name' => $name,
        'stellenart' => $art,
        'erreichbarkeit' => $erreichbarkeit,
        'bemerkungen' => null,
    ];
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
        // Unser Digitalfunk. Darueber erreichen wir die Regionalstelle
        // (ueber uns) und den Ortsverband (unter uns).
        $weg([
            'fernmeldeplan_eintrag_id' => 11,
            'weg_nummer' => 11,
            'funkart' => 'DIGITAL',
            'erreichbarkeit' => 'Heros Übungsplatz 10',
            'gegenstellen' => [
                $gegen(1, 'Regionalstelle Braunschweig', 'UEBER',
                    'Heros Braunschweig'),
                $gegen(2, 'Ortsverband Buxtehude', 'UNTER',
                    'Heros Buxtehude 21'),
            ],
        ]),
        // Unser Analogfunk.
        $weg([
            'fernmeldeplan_eintrag_id' => 12,
            'weg_nummer' => 12,
            'funkart' => 'ANALOG',
            'erreichbarkeit' => 'Florian Übungsplatz',
            'gegenstellen' => [
                $gegen(3, 'Löschzug West', 'UNTER', 'Florian West 1'),
            ],
        ]),
        // Unser Amtsanschluss, Ersatzweg fuer den Analogfunk.
        $weg([
            'fernmeldeplan_eintrag_id' => 13,
            'weg_nummer' => 13,
            'medium' => 'Fe',
            'funkart' => null,
            'erreichbarkeit' => '0228 940-1500',
            'rueckfallebene_fuer_weg' => 12,
            'gegenstellen' => [
                $gegen(4, 'Kreisleitstelle', 'NEBEN', '0228 940-1550'),
            ],
        ]),
        // Unser Melderdienst; die Gegenstelle ist noch nicht eingeordnet.
        $weg([
            'fernmeldeplan_eintrag_id' => 14,
            'weg_nummer' => 14,
            'medium' => 'Me',
            'funkart' => null,
            'erreichbarkeit' => 'Meldekopf Tor 2',
            'gegenstellen' => [
                $gegen(5, 'Stadtwerke Netzführung', null, 'Tor 2'),
            ],
        ]),
    ],
];

/* --- Die Mitte sind wir, links oben, rechts unten --- */

$seiten = estab_telecom_sketch_sides($plan);
$assert(
    count($seiten['mittel']) === 4,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die Mitte führt nicht alle eigenen Mittel. Genau sie ist der '
            . 'Gegenstand des Fernmeldeplans.'
    )
);
$namenLinks = array_map(
    static fn (array $g): string => $g['name'],
    $seiten['links']
);
$namenRechts = array_map(
    static fn (array $g): string => $g['name'],
    $seiten['rechts']
);
$assert(
    $namenLinks === ['Regionalstelle Braunschweig'],
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Links steht nicht genau die übergeordnete Stelle. Der Vordruck '
            . 'setzt sie dorthin, und die Richtung ist die halbe Aussage.'
    )
);
$assert(
    in_array('Ortsverband Buxtehude', $namenRechts, true)
        && in_array('Löschzug West', $namenRechts, true),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die nachgeordneten Stellen stehen nicht rechts.'
    )
);
// Eine Gegenstelle ohne Stellenart steht rechts und sagt es -- eine stille
// Einordnung waere eine Behauptung.
$assert(
    in_array('Stadtwerke Netzführung', $namenRechts, true),
    'Eine Gegenstelle ohne Stellenart verschwindet aus der Skizze.'
);

$svg = estab_telecom_sketch_svg($plan, 'Führungsstelle Übungsplatz');
$assert(
    str_contains($svg, estab_telecom_sketch_html('Führungsstelle Übungsplatz'))
        && str_contains(
            $svg,
            estab_telecom_sketch_html('Funkrufname: Heros Übungsplatz 10')
        ),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'In der Mitte fehlt der Name der eigenen Führungsstelle oder ihr '
            . 'Funkrufname.'
    )
);
$assert(
    str_contains($svg, 'Stellenart nicht angegeben'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Eine Gegenstelle ohne Stellenart wird eingeordnet, als wäre sie '
            . 'bekannt, statt zum Nachtragen aufzufordern.'
    )
);
$assert(
    str_contains($svg, 'links übergeordnet · rechts nachgeordnet'),
    'Die Skizze sagt nicht, wofür ihre Seiten stehen.'
);
$orte = estab_telecom_sketch_layout($seiten);
$assert(
    $orte['links'] !== [] && $orte['rechts'] !== []
        && $orte['links'][0]['x'] < ESTAB_SKETCH_BREITE / 2
        && $orte['rechts'][0]['x'] > ESTAB_SKETCH_BREITE / 2,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die Seiten stehen nicht links und rechts der eigenen Stelle.'
    )
);
// Der eigene Funkrufname ist der des Funkwegs, nicht die erstbeste Zeile.
$ohneFunk = array_replace($plan, [
    'eintraege' => [
        $weg([
            'medium' => 'Fe',
            'funkart' => null,
            'erreichbarkeit' => '0228 940-1500',
        ]),
    ],
]);
$assert(
    estab_telecom_sketch_own_callsign($ohneFunk) === ''
        && str_contains(
            estab_telecom_sketch_svg($ohneFunk, 'Fü'),
            'Funkrufname: —'
        ),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Ohne Funkweg wird eine Telefonnummer als Funkrufname ausgegeben.'
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
    str_contains($svg, '>Ersatzweg</text>'),
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

/*
 * Die Kopfleiste des Fb Fue 77 -- nicht die des Fb Fue 76.
 *
 * Der Skizzenvordruck traegt links "Fuehrungsstelle", in der Mitte die Art
 * und den Verwendungsbereich, rechts Stand, F.d.R. und den
 * Verschlusssachenvermerk. Die herausgebende Dienststelle und die Funktion
 * des Verfassers stehen im PLAN (Fb Fue 76), nicht in der Skizze.
 */
foreach ([
    'Führungsstelle' => 'die Beschriftung des linken Kopffelds',
    'Führungsstelle Übungsplatz' => 'der Name der eigenen Führungsstelle',
    'Kommunikationsskizze' => 'die Art der Skizze',
    'Übung Rheinhochwasser' => 'der Verwendungsbereich',
    'NfD' => 'der Verschlusssachenvermerk',
    'Stand: 2026-08-29' => 'der Gültigkeitsvermerk',
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
        'einsatzbezeichnung' => '<script>alert(1)</script>',
        'vs_vermerk' => '" onload="alert(2)',
    ]),
    'Führungs"stelle'
);
/*
 * Geprueft wird die FLUCHT, nicht das Wort.
 *
 * "onload=" darf als Text vorkommen -- eine Stelle darf so heissen.
 * Gefaehrlich ist erst das unmaskierte Anfuehrungszeichen davor, das aus
 * Text ein Attribut macht. Genau darauf zielt die Probe.
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
