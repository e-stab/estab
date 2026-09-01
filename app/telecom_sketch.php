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
 * Die Anordnung folgt der Stellenart, nicht einem Grafikalgorithmus. THW-DV
 * 1-101 Kapitel 6.1.2 gibt die Achsen vor -- vertikal von oben nach unten,
 * horizontal zur Seite:
 *
 *                        übergeordnet
 *                             |
 *          benachbart  ---  EIGEN  ---  benachbart
 *                             |
 *          nachgeordnet   nachgeordnet   nachgeordnet
 *
 * Damit ist die Skizze bei jeder Größe vorhersehbar. Ein Kräftespiel-Layout
 * wäre hübscher und bei jeder Neuberechnung anders; auf einer Lagekarte ist
 * "an derselben Stelle wie gestern" mehr wert als "schön".
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
const ESTAB_SKETCH_KASTEN_BREITE = 208;
const ESTAB_SKETCH_KASTEN_HOEHE = 66;

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
 * Die Stellen des Plans, nach Stellenart in Bänder sortiert.
 *
 * Eine Stelle ohne Stellenart bekommt kein eigenes Band, sondern steht bei
 * den benachbarten. Das ist keine Behauptung, sondern die schwächste
 * Einordnung, die es gibt: seitlich heißt "steht daneben", und mehr weiß der
 * Plan über sie nicht. Ein eigenes Band "unbekannt" hätte dieselbe Aussage
 * und zusätzlich eine leere Zeile, wenn alle Stellen eingeordnet sind.
 *
 * @return array<string, array<string, array{stellenart:?string,wege:list<array<string,mixed>>}>>
 */
function estab_telecom_sketch_bands(array $plan): array
{
    $baender = ['UEBER' => [], 'EIGEN' => [], 'NEBEN' => [], 'UNTER' => []];
    foreach (($plan['eintraege'] ?? []) as $eintrag) {
        if (!is_array($eintrag)) {
            continue;
        }
        $stelle = trim((string) ($eintrag['betriebsstelle'] ?? ''));
        if ($stelle === '') {
            continue;
        }
        $art = $eintrag['stellenart'] ?? null;
        $band = match ($art) {
            'UEBER' => 'UEBER',
            'EIGEN' => 'EIGEN',
            'UNTER' => 'UNTER',
            default => 'NEBEN',
        };
        if (!isset($baender[$band][$stelle])) {
            $baender[$band][$stelle] = [
                'stellenart' => is_string($art) ? $art : null,
                'wege' => [],
            ];
        }
        $baender[$band][$stelle]['wege'][] = $eintrag;
    }
    return $baender;
}

/**
 * Die Bildpunkte je Stelle.
 *
 * Übergeordnete oben in einer Reihe, nachgeordnete unten in einer Reihe,
 * benachbarte links und rechts abwechselnd, eigene in der Mitte unter dem
 * Mittelpunkt. Reihen werden gleichmäßig über die Breite verteilt; bei einer
 * einzigen Stelle steht sie mittig.
 *
 * @return array<string, array{x:float,y:float,band:string}>
 */
function estab_telecom_sketch_layout(array $baender): array
{
    $orte = [];
    $reihe = static function (
        array $namen,
        float $y,
        string $band
    ) use (&$orte): void {
        $anzahl = count($namen);
        if ($anzahl === 0) {
            return;
        }
        $rand = 90.0;
        $spanne = ESTAB_SKETCH_BREITE - 2 * $rand;
        $schritt = $anzahl === 1 ? 0.0 : $spanne / ($anzahl - 1);
        $start = $anzahl === 1 ? ESTAB_SKETCH_BREITE / 2 : $rand;
        $index = 0;
        foreach ($namen as $name) {
            $orte[$name] = [
                'x' => $start + $schritt * $index,
                'y' => $y,
                'band' => $band,
            ];
            $index++;
        }
    };
    $reihe(array_keys($baender['UEBER']), 178.0, 'UEBER');
    $reihe(array_keys($baender['UNTER']), 726.0, 'UNTER');

    // Die eigenen Stellen stehen unter dem Mittelpunkt, gestapelt.
    $eigene = array_keys($baender['EIGEN']);
    $eigenY = 500.0;
    foreach ($eigene as $name) {
        $orte[$name] = [
            'x' => ESTAB_SKETCH_BREITE / 2,
            'y' => $eigenY,
            'band' => 'EIGEN',
        ];
        $eigenY += ESTAB_SKETCH_KASTEN_HOEHE + 16.0;
    }

    // Benachbarte links und rechts, abwechselnd von der Mitte nach aussen.
    $neben = array_keys($baender['NEBEN']);
    $links = 0;
    $rechts = 0;
    foreach ($neben as $index => $name) {
        if ($index % 2 === 0) {
            $orte[$name] = [
                'x' => 130.0,
                'y' => 372.0 + $links * (ESTAB_SKETCH_KASTEN_HOEHE + 18.0),
                'band' => 'NEBEN',
            ];
            $links++;
            continue;
        }
        $orte[$name] = [
            'x' => ESTAB_SKETCH_BREITE - 130.0,
            'y' => 372.0 + $rechts * (ESTAB_SKETCH_KASTEN_HOEHE + 18.0),
            'band' => 'NEBEN',
        ];
        $rechts++;
    }
    return $orte;
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
    $baender = estab_telecom_sketch_bands($plan);
    $orte = estab_telecom_sketch_layout($baender);
    $mitteX = ESTAB_SKETCH_BREITE / 2;
    $mitteY = 430.0;

    $kopf = [];
    $kopf[] = trim((string) ($plan['einsatzbezeichnung'] ?? ''));
    $stand = 'Stand: ab ' . (string) ($plan['gueltig_ab'] ?? '');
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
        . ' aria-label="Kommunikationsskizze des aktiven Fernmeldeplans"'
        . ' class="estab-telecom-sketch">';
    $teile[] = '<rect x="0" y="0" width="' . ESTAB_SKETCH_BREITE
        . '" height="' . ESTAB_SKETCH_HOEHE . '" fill="#ffffff"/>';

    /* --- Kopfleiste, dreigeteilt wie im Vordruck --- */
    $teile[] = '<g font-size="15" fill="#000000">';
    $teile[] = '<rect x="24" y="24" width="' . (ESTAB_SKETCH_BREITE - 48)
        . '" height="64" fill="none" stroke="#000000" stroke-width="2"/>';
    $teile[] = '<line x1="' . ($mitteX - 200) . '" y1="24" x2="'
        . ($mitteX - 200) . '" y2="88" stroke="#000000" stroke-width="2"/>';
    $teile[] = '<line x1="' . ($mitteX + 200) . '" y1="24" x2="'
        . ($mitteX + 200) . '" y2="88" stroke="#000000" stroke-width="2"/>';
    $teile[] = '<text x="38" y="48" font-weight="bold">'
        . $h(estab_telecom_sketch_kurz(
            (string) ($plan['herkunft'] ?? ''),
            34
        )) . '</text>';
    $teile[] = '<text x="38" y="72">'
        . $h(estab_telecom_sketch_kurz(
            (string) ($plan['verfasser_funktion'] ?? ''),
            34
        )) . '</text>';
    $teile[] = '<text x="' . $mitteX . '" y="48" text-anchor="middle"'
        . ' font-weight="bold">Kommunikationsskizze</text>';
    $teile[] = '<text x="' . $mitteX . '" y="72" text-anchor="middle">'
        . $h(estab_telecom_sketch_kurz('für ' . $kopf[0], 46))
        . '</text>';
    $teile[] = '<text x="' . (ESTAB_SKETCH_BREITE - 38)
        . '" y="48" text-anchor="end">' . $h($stand) . '</text>';
    $teile[] = '<text x="' . (ESTAB_SKETCH_BREITE - 38)
        . '" y="72" text-anchor="end" font-weight="bold">'
        . $h($vs === '' ? 'ohne VS-Vermerk' : $vs) . '</text>';
    $teile[] = '</g>';

    /* --- Eine Linie je Weg, von der Mitte zur Stelle --- */
    foreach ($baender as $band => $stellen) {
        foreach ($stellen as $name => $stelle) {
            $ort = $orte[$name] ?? null;
            if ($ort === null) {
                continue;
            }
            $anzahl = count($stelle['wege']);
            foreach ($stelle['wege'] as $index => $weg) {
                $stil = estab_telecom_sketch_line_style(
                    $weg['medium'] ?? null,
                    $weg['funkart'] ?? null
                );
                $istErsatz = ($weg['rueckfallebene_fuer_weg'] ?? null) !== null;
                // Mehrere Wege zwischen denselben Kaesten laufen faecherfoermig,
                // sonst laege die zweite Linie auf der ersten.
                $versatz = ($index - ($anzahl - 1) / 2) * 22.0;
                $teile[] = '<line x1="' . ($mitteX + $versatz) . '" y1="'
                    . $mitteY . '" x2="' . ($ort['x'] + $versatz) . '" y2="'
                    . $ort['y'] . '"'
                    . ' stroke="' . ($istErsatz ? '#7a7a7a' : '#000000') . '"'
                    . ' stroke-width="'
                    . ($istErsatz ? 1.0 : $stil['breite']) . '"'
                    . ($stil['strich'] === ''
                        ? ''
                        : ' stroke-dasharray="' . $stil['strich'] . '"')
                    . '/>';
                /*
                 * Die Beschriftung sitzt NICHT auf der Mitte der Linie.
                 *
                 * Am Bildschirm gesehen: dort ueberdeckte sie den Kasten der
                 * eigenen Stelle, und zwei Wege zur selben Stelle druckten
                 * ihre Beschriftungen uebereinander. Beides faellt in keinem
                 * Zahlenvergleich auf und in der ersten Ansicht sofort.
                 *
                 * Sie rutscht deshalb auf die Stelle zu und je Weg um ein
                 * Stueck weiter -- so hat jede Linie ihren eigenen Platz.
                 */
                $anteil = 0.58;
                if ($anzahl > 1) {
                    $anteil += ($index - ($anzahl - 1) / 2) * 0.13;
                }
                $anteil = max(0.32, min(0.84, $anteil));
                $beschriftungX = $mitteX + $versatz
                    + ($ort['x'] - $mitteX) * $anteil;
                $beschriftungY = $mitteY + ($ort['y'] - $mitteY) * $anteil;
                $wort = estab_dv_telecom_route_label(
                    $weg['medium'] ?? null,
                    $weg['funkart'] ?? null
                );
                $erreichbar = trim((string) ($weg['erreichbarkeit'] ?? ''));
                $text = $wort . ($erreichbar === '' ? '' : ' · ' . $erreichbar);
                if ($istErsatz) {
                    $text = 'Ersatzweg · ' . $text;
                }
                // Weisser Saum hinter der Schrift: die Beschriftung kreuzt
                // fremde Linien, und eine Zahl auf einer Linie ist keine.
                $teile[] = '<text x="' . $beschriftungX . '" y="'
                    . $beschriftungY . '" text-anchor="middle" font-size="12"'
                    . ' fill="' . ($istErsatz ? '#5a5a5a' : '#000000') . '"'
                    . ' stroke="#ffffff" stroke-width="4"'
                    . ' paint-order="stroke fill">'
                    . '<tspan dy="-3">'
                    . $h(estab_telecom_sketch_kurz($text, 34))
                    . '</tspan></text>';
            }
        }
    }

    /*
     * Die Mitte kommt NACH den Linien.
     *
     * Am Bildschirm gesehen: davor gezeichnet, liefen die Beschriftungen der
     * kurzen seitlichen Wege in den Kasten der eigenen Stelle hinein und
     * ueberdruckten ihren Namen. Wer zuletzt zeichnet, deckt -- und die
     * eigene Stelle ist das, was zuerst gelesen wird.
     */
    $teile[] = '<g>';
    $teile[] = '<ellipse cx="' . $mitteX . '" cy="' . $mitteY
        . '" rx="120" ry="42" fill="#ffff00" stroke="#000000"'
        . ' stroke-width="3"/>';
    $teile[] = '<text x="' . $mitteX . '" y="' . ($mitteY + 6)
        . '" text-anchor="middle" font-size="16" font-weight="bold">'
        . $h(estab_telecom_sketch_kurz($fuehrungsstelle, 22)) . '</text>';
    $teile[] = '</g>';

    /* --- Die Kaesten der Stellen, ueber den Linien --- */
    $bandwort = [
        'UEBER' => 'übergeordnet',
        'EIGEN' => 'eigene Stelle',
        'UNTER' => 'nachgeordnet',
        'NEBEN' => 'benachbart',
    ];
    foreach ($baender as $band => $stellen) {
        foreach ($stellen as $name => $stelle) {
            $ort = $orte[$name] ?? null;
            if ($ort === null) {
                continue;
            }
            $x = $ort['x'] - ESTAB_SKETCH_KASTEN_BREITE / 2;
            $y = $ort['y'] - ESTAB_SKETCH_KASTEN_HOEHE / 2;
            $teile[] = '<g>';
            $teile[] = '<rect x="' . $x . '" y="' . $y . '" width="'
                . ESTAB_SKETCH_KASTEN_BREITE . '" height="'
                . ESTAB_SKETCH_KASTEN_HOEHE
                . '" fill="#ffffff" stroke="#000000" stroke-width="2"/>';
            $teile[] = '<text x="' . $ort['x'] . '" y="' . ($ort['y'] - 6)
                . '" text-anchor="middle" font-size="14" font-weight="bold">'
                . $h(estab_telecom_sketch_kurz($name, 24)) . '</text>';
            $erreicht = [];
            foreach ($stelle['wege'] as $weg) {
                foreach (($weg['gegenstellen'] ?? []) as $gegenstelle) {
                    $erreicht[] = trim(
                        (string) ($gegenstelle['name'] ?? '')
                    );
                }
            }
            $erreicht = array_values(array_unique(array_filter($erreicht)));
            $zweite = $erreicht === []
                ? ($bandwort[$stelle['stellenart'] ?? ''] ?? $bandwort[$band])
                : 'erreicht: ' . implode(', ', $erreicht);
            $teile[] = '<text x="' . $ort['x'] . '" y="' . ($ort['y'] + 14)
                . '" text-anchor="middle" font-size="11" fill="#333333">'
                . $h(estab_telecom_sketch_kurz($zweite, 32)) . '</text>';
            $teile[] = '</g>';
        }
    }

    /* --- Fusszeile: Stand, Fassung und F.d.R. --- */
    $fuss = 'Fernmeldeplan Version ' . (int) ($plan['version'] ?? 0)
        . ' · ' . (string) ($plan['status'] ?? '')
        . ' · Betriebsleitung ' . (string) ($plan['betriebsleitung'] ?? '');
    $teile[] = '<text x="24" y="' . (ESTAB_SKETCH_HOEHE - 24)
        . '" font-size="13" fill="#000000">' . $h($fuss) . '</text>';
    if ($fdr !== '') {
        $teile[] = '<text x="' . (ESTAB_SKETCH_BREITE - 24) . '" y="'
            . (ESTAB_SKETCH_HOEHE - 24)
            . '" font-size="13" text-anchor="end" fill="#000000">'
            . $h('F.d.R.: ' . $fdr
                . ($fdrStellung === '' ? '' : ', ' . $fdrStellung))
            . '</text>';
    }
    $teile[] = '</svg>';
    return implode("\n", $teile);
}
