<?php

declare(strict_types=1);

/**
 * Die erzeugte Kommunikationsskizze nach Fb Fü 77.
 *
 * Sie ist ERZEUGT, nicht gezeichnet. Damit gilt für sie, was für den Plan
 * gilt: Sie trägt dessen Stand, dessen Version und dessen F.d.R. Es gibt
 * keine zweite Wahrheit, die von der ersten weglaufen könnte -- und genau das
 * ist der Grund, warum überhaupt erzeugt und nicht gemalt wird. Eine von Hand
 * gepflegte Skizze ist nach der zweiten Planänderung falsch, und niemand
 * merkt es.
 *
 * Der Aufbau folgt dem Vordruck, und der ist eindeutig:
 *
 *      übergeordnete Stellen   ┌──────────────────┐   nachgeordnete Stellen
 *              ◄───────────────┤  FÜHRUNGSSTELLE  ├───────────────►
 *                              │   Funkrufname    │
 *                              │  ── unsere ──    │
 *                              │     Mittel       │
 *                              └──────────────────┘
 *
 * In der Mitte steht die EIGENE Führungsstelle mit ihrem Funkrufnamen und
 * ihren Kommunikationsmitteln. Links, wen wir nach oben erreichen, rechts,
 * wen wir nach unten erreichen. Das ist der ganze Sinn der Fernmeldeplanung:
 * die EIGENEN Erreichbarkeiten festzulegen. Andere Stellen darzustellen ist
 * nicht ihre Aufgabe -- sie erscheinen nur als das, was sie sind: die
 * Gegenstellen, die über eines unserer Mittel erreichbar sind.
 *
 * Deshalb ist eine Planzeile eines UNSERER Mittel und die Stellenart eine
 * Eigenschaft der GEGENSTELLE. Wer die Zeilen mit fremden Stellen füllt, baut
 * ein Adressbuch und keinen Fernmeldeplan.
 *
 * Querformat, weil Fb Fü 76 es so verlangt: "Bewährt hat sich die Darstellung
 * im Querformat."
 *
 * Die Skizze steht auf weißer Fläche, auch im dunklen Bild. Das ist die in
 * docs/GESTALTUNG.md benannte Ausnahme: Ein taktisches Zeichen trägt seine
 * Farben als Bedeutung, nicht als Geschmack -- Gelb heißt Führungsstelle. Wer
 * sie umfärbt, ändert die Aussage.
 */

/** Die Zeichenfläche. A4 quer bei rund 100 Bildpunkten je Zoll. */
const ESTAB_SKETCH_BREITE = 1190;
const ESTAB_SKETCH_HOEHE = 842;
const ESTAB_SKETCH_KASTEN_BREITE = 230;
const ESTAB_SKETCH_KASTEN_HOEHE = 62;
/**
 * Die Mitte: unsere Führungsstelle mit unseren Mitteln.
 *
 * Breiter als zuerst gedacht. Eine Zeile trägt Mittel, Erreichbarkeit UND
 * das Kennzeichen des Wegs -- ohne das sind zwei Digitalfunkwege derselben
 * Stelle nicht zu unterscheiden --, und bei 330 Bildpunkten wurde davon zu
 * viel abgeschnitten.
 */
const ESTAB_SKETCH_MITTE_BREITE = 420;

/**
 * Die Linienart je Mittel.
 *
 * Sie trägt die Aussage auch ohne die taktischen Zeichen -- die Skizze ist
 * ohne sie baubar und mit ihnen besser, aber sie hängt nicht an ihnen.
 * Leitergebundenes durchgezogen, Funk gestrichelt (digital doppelt), Melder
 * gepunktet.
 *
 * @return array{strich:string,breite:float}
 */
function estab_telecom_sketch_line_style(
    mixed $medium,
    mixed $funkart
): array {
    $medium = is_string($medium) ? $medium : '';
    $funkart = is_string($funkart) ? $funkart : null;
    if ($medium === 'Me') {
        return ['strich' => '2 6', 'breite' => 2.0];
    }
    if ($medium === 'Fu') {
        return $funkart === 'DIGITAL'
            ? ['strich' => '14 4 4 4', 'breite' => 2.0]
            : ['strich' => '12 6', 'breite' => 2.0];
    }
    return ['strich' => '', 'breite' => 2.0];
}

/**
 * Das taktische Zeichen für eine Verbindung, wenn es eines gibt.
 *
 * Die Sammlung (Q9, CC-BY-4.0) führt eine eigene Gattung `Bedingung_*`, die
 * mit `preserveAspectRatio` an einer Linie mitwächst -- genau das, was eine
 * Verbindungsbeschriftung braucht. Für den Analogfunk gibt es keine; Fb Fü 76
 * schreibt die Bandangabe dort als Text („Kanal 2 / 4 m"), und so wird sie
 * gesetzt. Der Melder trägt seine Aussage in der punktierten Linie.
 */
function estab_telecom_sketch_symbol_name(array $weg): ?string
{
    $medium = (string) ($weg['medium'] ?? '');
    $funkart = $weg['funkart'] ?? null;
    if ($medium === 'Fu') {
        if ($funkart !== 'DIGITAL') {
            return null;
        }
        return ($weg['betriebsart'] ?? null) === 'DMO'
            ? 'Bedingung_DMO'
            : 'Bedingung_TMO';
    }
    if ($medium === 'Fe') {
        return ($weg['anschlussart'] ?? null) === 'NST'
            ? 'Bedingung_Nebenstelle'
            : 'Bedingung_Telefon';
    }
    if ($medium === 'FAX') {
        return 'Bedingung_Fax';
    }
    if ($medium === '@') {
        return 'Datenverbindung';
    }
    return null;
}

/**
 * Der Inhalt eines Zeichens, ohne seine äußere Hülle.
 *
 * Eingebettet wird als verschachteltes `<svg>` mit eigenem Koordinatenraum --
 * so behält das Zeichen seine Maße und der Aufrufer bestimmt nur Platz und
 * Größe. Fehlt eine Datei, fehlt das Zeichen und sonst nichts: die Skizze ist
 * ohne die Zeichen baubar, das ist ausdrücklich so entschieden.
 */
function estab_telecom_sketch_symbol(string $name): ?string
{
    static $zeichen = [];
    if (array_key_exists($name, $zeichen)) {
        return $zeichen[$name];
    }
    $zeichen[$name] = null;
    if (preg_match('/\A[A-Za-zÀ-ÿ0-9_-]{1,64}\z/u', $name) !== 1) {
        return null;
    }
    $pfad = dirname(__DIR__) . '/4fsym/taktische-zeichen/' . $name . '.svg';
    if (!is_readable($pfad)) {
        return null;
    }
    $inhalt = file_get_contents($pfad);
    if (!is_string($inhalt)) {
        return null;
    }
    $anfang = strpos($inhalt, '>', (int) strpos($inhalt, '<svg '));
    $ende = strrpos($inhalt, '</svg>');
    if ($anfang === false || $ende === false || $ende <= $anfang) {
        return null;
    }
    $zeichen[$name] = trim(substr($inhalt, $anfang + 1, $ende - $anfang - 1));
    return $zeichen[$name];
}

/** Ein Text, der in einen Kasten passt. */
function estab_telecom_sketch_kurz(string $text, int $zeichen): string
{
    $text = trim($text);
    $laenge = function_exists('mb_strlen')
        ? mb_strlen($text, 'UTF-8')
        : strlen($text);
    if ($laenge <= $zeichen) {
        return $text;
    }
    $kurz = function_exists('mb_substr')
        ? mb_substr($text, 0, $zeichen - 1, 'UTF-8')
        : substr($text, 0, $zeichen - 1);
    return rtrim($kurz) . '…';
}

function estab_telecom_sketch_html(mixed $wert): string
{
    return htmlspecialchars(
        (string) $wert,
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    );
}

/**
 * Unsere Mittel und die Gegenstellen, die über sie erreichbar sind.
 *
 * Zurück kommt beides getrennt: die Mittel für die Mitte, die Gegenstellen
 * für die Seiten. Eine Gegenstelle ohne Stellenart steht rechts bei den
 * nachgeordneten und trägt dort den Vermerk, dass ihre Art nicht gepflegt
 * ist. Das ist keine Behauptung, sondern die sichtbare Aufforderung, sie
 * nachzutragen -- eine stille Einordnung wäre eine Behauptung.
 *
 * @return array{mittel:list<array<string,mixed>>,links:list<array<string,mixed>>,rechts:list<array<string,mixed>>}
 */
function estab_telecom_sketch_sides(array $plan): array
{
    $mittel = [];
    $links = [];
    $rechts = [];
    foreach (($plan['eintraege'] ?? []) as $eintrag) {
        if (!is_array($eintrag)) {
            continue;
        }
        $mittel[] = $eintrag;
        foreach (($eintrag['gegenstellen'] ?? []) as $gegenstelle) {
            if (!is_array($gegenstelle)) {
                continue;
            }
            $eintragung = [
                'name' => trim((string) ($gegenstelle['name'] ?? '')),
                'erreichbarkeit' => trim(
                    (string) ($gegenstelle['erreichbarkeit'] ?? '')
                ),
                'stellenart' => $gegenstelle['stellenart'] ?? null,
                'weg' => $eintrag,
            ];
            if (($gegenstelle['stellenart'] ?? null) === 'UEBER') {
                $links[] = $eintragung;
                continue;
            }
            $rechts[] = $eintragung;
        }
    }
    return ['mittel' => $mittel, 'links' => $links, 'rechts' => $rechts];
}

/**
 * Die Bildpunkte je Gegenstelle.
 *
 * Links und rechts je eine Spalte, senkrecht verteilt. Eine einzelne Stelle
 * steht auf halber Höhe; viele rücken zusammen, bis der Platz ausgeht --
 * danach wird nicht kleiner gesetzt, sondern abgeschnitten und gesagt, wie
 * viele fehlen. Eine unlesbare Skizze ist schlechter als eine unvollständige,
 * die ihre Unvollständigkeit nennt.
 *
 * @return array{links:list<array{x:float,y:float}>,rechts:list<array{x:float,y:float}>,platz:int}
 */
function estab_telecom_sketch_layout(array $seiten): array
{
    $oben = 150.0;
    $unten = ESTAB_SKETCH_HOEHE - 80.0;
    $platz = (int) floor(
        ($unten - $oben) / (ESTAB_SKETCH_KASTEN_HOEHE + 14.0)
    ) + 1;
    $spalte = static function (
        int $anzahl,
        float $x
    ) use ($oben, $unten, $platz): array {
        $orte = [];
        $anzahl = min($anzahl, $platz);
        if ($anzahl === 0) {
            return $orte;
        }
        if ($anzahl === 1) {
            return [['x' => $x, 'y' => ($oben + $unten) / 2]];
        }
        /*
         * Zusammenruecken, nicht auseinanderziehen.
         *
         * Zwei Stellen ueber die ganze Blatthoehe verteilt sehen aus, als
         * gehoerten sie nicht zusammen, und ihre Linien laufen unnoetig weit.
         * Der Schritt ist deshalb hoechstens Kastenhoehe plus Luft; die
         * Spalte steht mittig und waechst erst nach aussen, wenn viele
         * Stellen es verlangen.
         */
        $schritt = min(
            ($unten - $oben) / ($anzahl - 1),
            ESTAB_SKETCH_KASTEN_HOEHE + 22.0
        );
        $hoehe = $schritt * ($anzahl - 1);
        $start = ($oben + $unten) / 2 - $hoehe / 2;
        for ($i = 0; $i < $anzahl; $i++) {
            $orte[] = ['x' => $x, 'y' => $start + $schritt * $i];
        }
        return $orte;
    };
    return [
        'links' => $spalte(
            count($seiten['links']),
            ESTAB_SKETCH_KASTEN_BREITE / 2 + 25.0
        ),
        'rechts' => $spalte(
            count($seiten['rechts']),
            ESTAB_SKETCH_BREITE - ESTAB_SKETCH_KASTEN_BREITE / 2 - 25.0
        ),
        'platz' => $platz,
    ];
}

/**
 * Der Funkrufname der eigenen Führungsstelle.
 *
 * Er steht in der Mitte des Vordrucks, gleich unter dem Namen. Genommen wird
 * die Erreichbarkeit des ersten Funkwegs -- das ist der Funkrufname, unter
 * dem die Stelle im Netz gerufen wird. Gibt es keinen Funkweg, bleibt die
 * Zeile leer, statt eine Telefonnummer als Funkrufnamen auszugeben.
 */
function estab_telecom_sketch_own_callsign(array $plan): string
{
    foreach (($plan['eintraege'] ?? []) as $eintrag) {
        if (
            is_array($eintrag)
            && ($eintrag['medium'] ?? null) === 'Fu'
            && trim((string) ($eintrag['erreichbarkeit'] ?? '')) !== ''
        ) {
            return trim((string) $eintrag['erreichbarkeit']);
        }
    }
    return '';
}

/**
 * Die Skizze als eigenständiges SVG.
 *
 * Eigenständig heißt: keine externen Verweise, keine Schriftbindung, kein
 * Skript. Sie lässt sich in die Seite einbetten, ausdrucken und als Datei
 * weitergeben, ohne dass etwas nachgeladen werden müsste.
 */
function estab_telecom_sketch_svg(
    array $plan,
    string $fuehrungsstelle
): string {
    $h = 'estab_telecom_sketch_html';
    $seiten = estab_telecom_sketch_sides($plan);
    $orte = estab_telecom_sketch_layout($seiten);
    $mitteX = ESTAB_SKETCH_BREITE / 2;

    /* --- Die Mitte wächst mit unseren Mitteln und Nebenstellen --- */
    $nebenstellen = array_values(array_filter(
        (array) ($plan['nebenstellen'] ?? []),
        'is_array'
    ));
    $mittelZeile = 26.0;
    $nebenZeile = 20.0;
    $mitteKopf = 78.0;
    $mittelHoehe = max(1, count($seiten['mittel'])) * $mittelZeile;
    // Die Nebenstellentafel bekommt eine Kopfzeile und ihre Zeilen.
    $nebenHoehe = $nebenstellen === []
        ? 0.0
        : 22.0 + (count($nebenstellen) + 1) * $nebenZeile;
    $mitteHoehe = $mitteKopf + $mittelHoehe + $nebenHoehe + 14.0;
    $mitteY = max(120.0, (ESTAB_SKETCH_HOEHE - 40.0 - $mitteHoehe) / 2);

    $stand = 'Stand: ' . (string) ($plan['gueltig_ab'] ?? '');
    if (($plan['gueltig_bis'] ?? null) !== null) {
        $stand .= ' bis ' . (string) $plan['gueltig_bis'];
    }
    $vs = trim((string) ($plan['vs_vermerk'] ?? ''));
    $fdr = trim((string) ($plan['freigegeben_von'] ?? ''));
    $fdrStellung = trim((string) ($plan['freigabe_dienststellung'] ?? ''));

    $teile = [];
    $teile[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '
        . ESTAB_SKETCH_BREITE . ' ' . ESTAB_SKETCH_HOEHE . '"'
        . ' width="100%" role="img"'
        . ' aria-label="Kommunikationsskizze der eigenen Führungsstelle"'
        . ' class="estab-telecom-sketch">';
    $teile[] = '<rect x="0" y="0" width="' . ESTAB_SKETCH_BREITE
        . '" height="' . ESTAB_SKETCH_HOEHE . '" fill="#ffffff"/>';

    /* --- Kopfleiste wie im Vordruck --- */
    $teile[] = '<g font-size="15" fill="#000000">';
    $teile[] = '<rect x="24" y="20" width="' . (ESTAB_SKETCH_BREITE - 48)
        . '" height="62" fill="none" stroke="#000000" stroke-width="2"/>';
    $teile[] = '<line x1="330" y1="20" x2="330" y2="82"'
        . ' stroke="#000000" stroke-width="2"/>';
    $teile[] = '<line x1="880" y1="20" x2="880" y2="82"'
        . ' stroke="#000000" stroke-width="2"/>';
    $teile[] = '<text x="38" y="44" font-weight="bold">Führungsstelle</text>';
    $teile[] = '<text x="38" y="68">'
        . $h(estab_telecom_sketch_kurz($fuehrungsstelle, 32)) . '</text>';
    $teile[] = '<text x="605" y="44" text-anchor="middle"'
        . ' font-weight="bold">Kommunikationsskizze</text>';
    $teile[] = '<text x="605" y="68" text-anchor="middle">'
        . $h(estab_telecom_sketch_kurz(
            'für ' . (string) ($plan['einsatzbezeichnung'] ?? ''),
            50
        )) . '</text>';
    $teile[] = '<text x="894" y="44">' . $h($stand) . '</text>';
    $teile[] = '<text x="894" y="68">'
        . $h('F.d.R.: ' . ($fdr === '' ? '—' : $fdr)
            . ($fdrStellung === '' ? '' : ', ' . $fdrStellung))
        . '</text>';
    $teile[] = '<text x="' . (ESTAB_SKETCH_BREITE - 38)
        . '" y="44" text-anchor="end" font-weight="bold">'
        . $h($vs) . '</text>';
    $teile[] = '</g>';

    /* --- Die Linien: von unserem Mittel zur Gegenstelle --- */
    foreach (['links', 'rechts'] as $seite) {
        foreach ($seiten[$seite] as $index => $gegenstelle) {
            $ort = $orte[$seite][$index] ?? null;
            if ($ort === null) {
                continue;
            }
            $weg = $gegenstelle['weg'];
            $stil = estab_telecom_sketch_line_style(
                $weg['medium'] ?? null,
                $weg['funkart'] ?? null
            );
            $istErsatz = ($weg['rueckfallebene_fuer_weg'] ?? null) !== null;
            /*
             * Die Linie beginnt an der Zeile DIESES Mittels in der Mitte,
             * nicht an der Kastenmitte. So ist ablesbar, worueber die Stelle
             * erreicht wird, ohne die Linie bis zum Ende zu verfolgen -- und
             * genau das ist die Frage, die eine Fuehrungskraft an das Bild
             * stellt.
             */
            $zeile = 0;
            foreach ($seiten['mittel'] as $nummer => $eigenes) {
                if (
                    (int) ($eigenes['fernmeldeplan_eintrag_id'] ?? 0)
                    === (int) ($weg['fernmeldeplan_eintrag_id'] ?? -1)
                ) {
                    $zeile = $nummer;
                }
            }
            $startY = $mitteY + $mitteKopf + $zeile * $mittelZeile;
            $startX = $seite === 'links'
                ? $mitteX - ESTAB_SKETCH_MITTE_BREITE / 2
                : $mitteX + ESTAB_SKETCH_MITTE_BREITE / 2;
            $zielX = $seite === 'links'
                ? $ort['x'] + ESTAB_SKETCH_KASTEN_BREITE / 2
                : $ort['x'] - ESTAB_SKETCH_KASTEN_BREITE / 2;
            /*
             * Erst ein kurzes Stueck, dann senkrecht, dann die lange Waage.
             *
             * Der Knick sass zuerst auf halber Strecke -- damit blieb fuer das
             * taktische Zeichen neben dem Kasten kaum Platz, und es fiel weg.
             * So bleibt die lange Waagerechte fuer das Zeichen frei, und die
             * senkrechte Buendelung dicht an der Mitte sieht aus wie im
             * Vordruck.
             */
            $knick = $seite === 'links' ? $startX - 30.0 : $startX + 30.0;
            $teile[] = '<path d="M ' . $startX . ' ' . $startY
                . ' L ' . $knick . ' ' . $startY
                . ' L ' . $knick . ' ' . $ort['y']
                . ' L ' . $zielX . ' ' . $ort['y'] . '" fill="none"'
                . ' stroke="' . ($istErsatz ? '#7a7a7a' : '#000000') . '"'
                . ' stroke-width="'
                . ($istErsatz ? 1.0 : $stil['breite']) . '"'
                . ($stil['strich'] === ''
                    ? ''
                    : ' stroke-dasharray="' . $stil['strich'] . '"')
                . '/>';
            /*
             * Das taktische Zeichen sitzt auf dem waagerechten Stueck neben
             * dem Kasten -- dort, wo der Vordruck es auch hat. Es waechst mit
             * der Breite mit; das ist die Bauart der `Bedingung_*`-Zeichen.
             */
            $zeichenName = estab_telecom_sketch_symbol_name($weg);
            $zeichen = $zeichenName === null
                ? null
                : estab_telecom_sketch_symbol($zeichenName);
            $bandBreite = abs($zielX - $knick);
            if ($zeichen !== null && $bandBreite >= 80.0) {
                $bandX = min($knick, $zielX);
                $bandHoehe = 34.0;
                $teile[] = '<svg x="' . $bandX . '" y="'
                    . ($ort['y'] - $bandHoehe / 2) . '" width="'
                    . $bandBreite . '" height="' . $bandHoehe
                    . '" viewBox="0 0 256 256" preserveAspectRatio="none"'
                    . ($seite === 'links'
                        ? ' transform="translate(' . (2 * $bandX + $bandBreite)
                            . ',0) scale(-1,1)"'
                        : '')
                    . '>' . $zeichen . '</svg>';
            } elseif (!$istErsatz) {
                // Kein Zeichen fuer diese Technik: dann sagt es der Text.
                $wort = estab_dv_telecom_route_label(
                    $weg['medium'] ?? null,
                    $weg['funkart'] ?? null
                );
                $band = trim((string) ($weg['band'] ?? ''));
                $kanal = trim((string) ($weg['kanal'] ?? ''));
                if ($band !== '' || $kanal !== '') {
                    $wort .= ' · Kanal ' . trim($kanal . ' ' . $band);
                }
                /*
                 * Die Beschriftung wird auf das Band beschnitten, nicht auf
                 * eine feste Zeichenzahl. Sonst laeuft sie bei einem langen
                 * Kanalnamen in den Kasten der Gegenstelle -- am Bildschirm
                 * gesehen, in keiner Zahl sichtbar.
                 */
                $teile[] = '<text x="' . (($knick + $zielX) / 2) . '" y="'
                    . ($ort['y'] - 7) . '" text-anchor="middle"'
                    . ' font-size="11" fill="#000000" stroke="#ffffff"'
                    . ' stroke-width="4" paint-order="stroke fill">'
                    . $h(estab_telecom_sketch_kurz(
                        $wort,
                        max(6, (int) floor($bandBreite / 5.6))
                    )) . '</text>';
            }
            if ($istErsatz) {
                $teile[] = '<text x="' . (($knick + $zielX) / 2) . '" y="'
                    . ($ort['y'] - 7) . '" text-anchor="middle"'
                    . ' font-size="11" fill="#5a5a5a" stroke="#ffffff"'
                    . ' stroke-width="4" paint-order="stroke fill">'
                    . 'Ersatzweg</text>';
            }
        }
    }

    /* --- Die Kaesten der Gegenstellen --- */
    foreach ([
        'links' => 'übergeordnet',
        'rechts' => 'nachgeordnet',
    ] as $seite => $richtung) {
        foreach ($seiten[$seite] as $index => $gegenstelle) {
            $ort = $orte[$seite][$index] ?? null;
            if ($ort === null) {
                continue;
            }
            $x = $ort['x'] - ESTAB_SKETCH_KASTEN_BREITE / 2;
            $y = $ort['y'] - ESTAB_SKETCH_KASTEN_HOEHE / 2;
            $art = $gegenstelle['stellenart'];
            $wort = $art === null
                ? 'Stellenart nicht angegeben'
                : ($art === 'NEBEN' ? 'benachbart' : $richtung);
            $teile[] = '<g>';
            $teile[] = '<rect x="' . $x . '" y="' . $y . '" width="'
                . ESTAB_SKETCH_KASTEN_BREITE . '" height="'
                . ESTAB_SKETCH_KASTEN_HOEHE
                . '" fill="#ffffff" stroke="#000000" stroke-width="2"/>';
            $teile[] = '<text x="' . $ort['x'] . '" y="' . ($ort['y'] - 12)
                . '" text-anchor="middle" font-size="14" font-weight="bold">'
                . $h(estab_telecom_sketch_kurz($gegenstelle['name'], 28))
                . '</text>';
            $teile[] = '<text x="' . $ort['x'] . '" y="' . ($ort['y'] + 6)
                . '" text-anchor="middle" font-size="12">'
                . $h(estab_telecom_sketch_kurz(
                    $gegenstelle['erreichbarkeit'],
                    32
                )) . '</text>';
            $teile[] = '<text x="' . $ort['x'] . '" y="' . ($ort['y'] + 22)
                . '" text-anchor="middle" font-size="10" fill="#555555">'
                . $h($wort) . '</text>';
            $teile[] = '</g>';
        }
        $fehlend = count($seiten[$seite]) - count($orte[$seite]);
        if ($fehlend > 0) {
            $x = $seite === 'links'
                ? ESTAB_SKETCH_KASTEN_BREITE / 2 + 24.0
                : ESTAB_SKETCH_BREITE - ESTAB_SKETCH_KASTEN_BREITE / 2 - 24.0;
            $teile[] = '<text x="' . $x . '" y="'
                . (ESTAB_SKETCH_HOEHE - 46) . '" text-anchor="middle"'
                . ' font-size="12" fill="#7d1a14">'
                . $h('… und ' . $fehlend . ' weitere, hier nicht dargestellt')
                . '</text>';
        }
    }

    /* --- Die Mitte: wir selbst, zuletzt gezeichnet --- */
    $linkeKante = $mitteX - ESTAB_SKETCH_MITTE_BREITE / 2;
    $rechteKante = $mitteX + ESTAB_SKETCH_MITTE_BREITE / 2;
    $teile[] = '<g>';
    $teile[] = '<rect x="' . $linkeKante
        . '" y="' . $mitteY . '" width="' . ESTAB_SKETCH_MITTE_BREITE
        . '" height="' . $mitteHoehe
        . '" fill="#ffffff" stroke="#000000" stroke-width="3"/>';
    /*
     * Das taktische Zeichen der Fuehrungsstelle steht im Kopf, wie im
     * Vordruck. Es traegt seine Farbe als Bedeutung -- Gelb heisst
     * Fuehrungsstelle -- und wird deshalb nicht umgefaerbt.
     */
    $eigenesZeichen = estab_telecom_sketch_symbol('Führungsstelle');
    if ($eigenesZeichen !== null) {
        $teile[] = '<svg x="' . ($linkeKante + 10) . '" y="'
            . ($mitteY + 6) . '" width="58" height="58"'
            . ' viewBox="0 0 256 256">' . $eigenesZeichen . '</svg>';
    }
    $teile[] = '<text x="' . ($linkeKante + 76) . '" y="' . ($mitteY + 30)
        . '" font-size="15" font-weight="bold">'
        . $h(estab_telecom_sketch_kurz($fuehrungsstelle, 32)) . '</text>';
    $eigenerRufname = estab_telecom_sketch_own_callsign($plan);
    $teile[] = '<text x="' . ($linkeKante + 76) . '" y="' . ($mitteY + 52)
        . '" font-size="12">'
        . $h($eigenerRufname === ''
            ? 'Funkrufname: —'
            : 'Funkrufname: ' . estab_telecom_sketch_kurz(
                $eigenerRufname,
                26
            ))
        . '</text>';
    $teile[] = '<line x1="' . $linkeKante
        . '" y1="' . ($mitteY + $mitteKopf - 12) . '" x2="'
        . $rechteKante . '" y2="' . ($mitteY + $mitteKopf - 12)
        . '" stroke="#000000" stroke-width="2"/>';
    if ($seiten['mittel'] === []) {
        $teile[] = '<text x="' . $mitteX . '" y="'
            . ($mitteY + $mitteKopf + 8)
            . '" text-anchor="middle" font-size="12" fill="#555555">'
            . 'noch kein Mittel erfasst</text>';
    }
    foreach ($seiten['mittel'] as $zeile => $eigenes) {
        $y = $mitteY + $mitteKopf + $zeile * $mittelZeile;
        $wort = estab_dv_telecom_route_label(
            $eigenes['medium'] ?? null,
            $eigenes['funkart'] ?? null
        );
        $teile[] = '<text x="' . ($linkeKante + 12)
            . '" y="' . ($y + 4) . '" font-size="12" font-weight="bold">'
            . $h(estab_telecom_sketch_kurz($wort, 18)) . '</text>';
        /*
         * Das Kennzeichen des Wegs steht rechts unter der Erreichbarkeit.
         * Ohne es stuenden zwei Digitalfunkwege derselben Stelle mit
         * demselben Funkrufnamen untereinander und waeren nicht zu
         * unterscheiden -- am Bildschirm aufgefallen, nicht im Test.
         */
        $kennzeichen = estab_dv_telecom_route_key($eigenes);
        $adresse = estab_telecom_sketch_kurz(
            (string) ($eigenes['erreichbarkeit'] ?? ''),
            30
        );
        if ($kennzeichen !== '') {
            $adresse .= '  ·  ' . estab_telecom_sketch_kurz(
                $kennzeichen,
                30
            );
        }
        $teile[] = '<text x="' . ($rechteKante - 12)
            . '" y="' . ($y + 4) . '" text-anchor="end" font-size="11">'
            . $h($adresse) . '</text>';
    }
    /*
     * Die Nebenstellentafel, wie im Vordruck: Technik, NSt-Nr., Teilnehmer.
     * Sie steht unter den Mitteln, in derselben Umrandung -- sie gehoert der
     * eigenen Fuehrungsstelle und niemandem sonst.
     */
    if ($nebenstellen !== []) {
        $tafelY = $mitteY + $mitteKopf + $mittelHoehe + 10.0;
        $teile[] = '<line x1="' . $linkeKante . '" y1="' . $tafelY
            . '" x2="' . $rechteKante . '" y2="' . $tafelY
            . '" stroke="#000000" stroke-width="2"/>';
        $spalteTechnik = $linkeKante + 12;
        $spalteNummer = $linkeKante + 120;
        $spalteTeilnehmer = $linkeKante + 240;
        $teile[] = '<text x="' . $spalteTechnik . '" y="'
            . ($tafelY + 16) . '" font-size="10" font-weight="bold">'
            . 'Technik</text>';
        $teile[] = '<text x="' . $spalteNummer . '" y="'
            . ($tafelY + 16) . '" font-size="10" font-weight="bold">'
            . 'NSt-Nr.</text>';
        $teile[] = '<text x="' . $spalteTeilnehmer . '" y="'
            . ($tafelY + 16) . '" font-size="10" font-weight="bold">'
            . 'Teilnehmer</text>';
        foreach ($nebenstellen as $reihe => $nebenstelle) {
            $y = $tafelY + 16 + ($reihe + 1) * $nebenZeile;
            $technik = (string) ($nebenstelle['technik'] ?? '');
            $teile[] = '<text x="' . $spalteTechnik . '" y="' . $y
                . '" font-size="11">'
                . $h(estab_telecom_sketch_kurz(
                    ESTAB_DV_TELECOM_EXTENSION_KINDS[$technik] ?? $technik,
                    14
                )) . '</text>';
            $teile[] = '<text x="' . $spalteNummer . '" y="' . $y
                . '" font-size="11">'
                . $h(estab_telecom_sketch_kurz(
                    (string) ($nebenstelle['nummer'] ?? ''),
                    16
                )) . '</text>';
            $teile[] = '<text x="' . $spalteTeilnehmer . '" y="' . $y
                . '" font-size="11">'
                . $h(estab_telecom_sketch_kurz(
                    (string) ($nebenstelle['teilnehmer'] ?? ''),
                    24
                )) . '</text>';
        }
    }
    $teile[] = '</g>';

    /* --- Fusszeile --- */
    $fuss = 'Fernmeldeplan Version ' . (int) ($plan['version'] ?? 0)
        . ' · ' . (string) ($plan['status'] ?? '')
        . ' · Betriebsleitung ' . (string) ($plan['betriebsleitung'] ?? '');
    $teile[] = '<text x="24" y="' . (ESTAB_SKETCH_HOEHE - 20)
        . '" font-size="13" fill="#000000">' . $h($fuss) . '</text>';
    $teile[] = '<text x="' . (ESTAB_SKETCH_BREITE - 24) . '" y="'
        . (ESTAB_SKETCH_HOEHE - 20)
        . '" font-size="12" text-anchor="end" fill="#555555">'
        . 'links übergeordnet · rechts nachgeordnet</text>';
    $teile[] = '</svg>';
    return implode("\n", $teile);
}
