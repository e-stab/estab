<?php

declare(strict_types=1);

/**
 * Das Stylesheet als Regeln lesen, nicht als Text durchsuchen.
 *
 * Die Wächter der Gestaltungsspec fragen alle dasselbe: Welche Erklärung steht
 * unter welchem Auswähler? Wer das mit `grep` beantwortet, bekommt Treffer aus
 * Kommentaren, aus `@media`-Köpfen und aus Erklärungen, die einem ganz anderen
 * Block gehören -- und übersieht genau die Stellen, an denen ein verlorener
 * Auswähler die folgenden Erklärungen verschoben hat.
 *
 * Diese Datei liest einmal richtig: Kommentare weg, Zeichenketten neutralisiert,
 * Klammern gezählt. Was herauskommt, ist eine Liste aus Auswähler, Kontext und
 * Erklärungen -- und darauf prüft jeder Wächter für sich.
 *
 * Siehe tasks/gestaltung-plan.md Abschnitt 2.3.
 */

/**
 * Ein Stylesheet in seine Regeln zerlegen.
 *
 * Der Kontext nennt die umschließenden At-Regeln, von außen nach innen mit
 * ` > ` verbunden -- eine Erklärung im Druckblock ist etwas anderes als
 * dieselbe Erklärung auf dem Bildschirm, und ein Wächter muss das
 * unterscheiden können.
 *
 * @return list<array{auswaehler:string,kontext:string,zeile:int,
 *                    deklarationen:array<int,array{eigenschaft:string,wert:string}>}>
 */
function estab_test_css_regeln(string $quelle): array
{
    // Kommentare durch Leerzeichen ersetzen, damit die Zeilenzählung stimmt.
    $ohneKommentare = preg_replace_callback(
        '~/\*.*?\*/~s',
        static fn (array $t): string
            => str_repeat("\n", substr_count($t[0], "\n")),
        $quelle
    );
    if (!is_string($ohneKommentare)) {
        throw new RuntimeException('Das Stylesheet ließ sich nicht säubern.');
    }

    $regeln = [];
    $stapel = [];
    $puffer = '';
    $zeile = 1;
    $beginn = 1;
    $laenge = strlen($ohneKommentare);

    for ($i = 0; $i < $laenge; $i++) {
        $zeichen = $ohneKommentare[$i];
        if ($zeichen === "\n") {
            $zeile++;
        }
        if ($zeichen === '{') {
            $kopf = trim(preg_replace('~\s+~', ' ', $puffer) ?? '');
            $stapel[] = ['kopf' => $kopf, 'zeile' => $beginn];
            $puffer = '';
            $beginn = $zeile;
            continue;
        }
        if ($zeichen === '}') {
            $rahmen = array_pop($stapel);
            if ($rahmen === null) {
                throw new RuntimeException(
                    'Schließende Klammer ohne Block in Zeile ' . $zeile
                );
            }
            // Ein Block, der nur andere Blöcke enthält -- eine At-Regel --
            // hat keine eigenen Erklärungen; sein Rumpf ist dann leer.
            $erklaerungen = estab_test_css_deklarationen($puffer);
            if ($erklaerungen !== [] && !str_starts_with($rahmen['kopf'], '@')) {
                $kontext = implode(' > ', array_map(
                    static fn (array $r): string => $r['kopf'],
                    $stapel
                ));
                $regeln[] = [
                    'auswaehler' => $rahmen['kopf'],
                    'kontext' => $kontext,
                    'zeile' => $rahmen['zeile'],
                    'deklarationen' => $erklaerungen,
                ];
            }
            $puffer = '';
            $beginn = $zeile;
            continue;
        }
        $puffer .= $zeichen;
    }

    if ($stapel !== []) {
        throw new RuntimeException(
            'Unausgeglichene Klammern; offener Block ab Zeile '
                . $stapel[0]['zeile']
        );
    }

    return $regeln;
}

/**
 * Den Rumpf eines Blocks in einzelne Erklärungen zerlegen.
 *
 * Semikola innerhalb von Klammern trennen nicht -- `font: 800 0.86rem/1.25
 * Arial, Helvetica, sans-serif` und `grid-template-columns: repeat(auto-fit,
 * minmax(11rem, 1fr))` sind je eine Erklärung, keine drei.
 *
 * @return list<array{eigenschaft:string,wert:string}>
 */
function estab_test_css_deklarationen(string $rumpf): array
{
    $erklaerungen = [];
    $teil = '';
    $tiefe = 0;
    $laenge = strlen($rumpf);
    for ($i = 0; $i < $laenge; $i++) {
        $zeichen = $rumpf[$i];
        if ($zeichen === '(') {
            $tiefe++;
        } elseif ($zeichen === ')') {
            $tiefe = max(0, $tiefe - 1);
        }
        if ($zeichen === ';' && $tiefe === 0) {
            $erklaerungen[] = $teil;
            $teil = '';
            continue;
        }
        $teil .= $zeichen;
    }
    $erklaerungen[] = $teil;

    $ergebnis = [];
    foreach ($erklaerungen as $roh) {
        $roh = trim(preg_replace('~\s+~', ' ', $roh) ?? '');
        if ($roh === '') {
            continue;
        }
        $doppelpunkt = strpos($roh, ':');
        if ($doppelpunkt === false) {
            continue;
        }
        $eigenschaft = strtolower(trim(substr($roh, 0, $doppelpunkt)));
        $wert = trim(substr($roh, $doppelpunkt + 1));
        if ($eigenschaft === '' || $wert === '') {
            continue;
        }
        $ergebnis[] = ['eigenschaft' => $eigenschaft, 'wert' => $wert];
    }
    return $ergebnis;
}

/**
 * Die Marken aus dem :root-Block lesen.
 *
 * @return array<string,string>
 */
function estab_test_css_marken(string $quelle): array
{
    $marken = [];
    foreach (estab_test_css_regeln($quelle) as $regel) {
        if (!str_contains($regel['auswaehler'], ':root')) {
            continue;
        }
        foreach ($regel['deklarationen'] as $erklaerung) {
            if (str_starts_with($erklaerung['eigenschaft'], '--')) {
                // Die Dichtestufe setzt Marken ein zweites Mal um. Der erste
                // Wert ist der Regelfall und der, gegen den geprueft wird.
                $marken[$erklaerung['eigenschaft']]
                    ??= $erklaerung['wert'];
            }
        }
    }
    return $marken;
}

/**
 * Jede im Stylesheet definierte Marke, gleich unter welchem Auswähler.
 *
 * Die Zeitleiste und das Vordruckblatt setzen eigene Marken auf ihrem
 * Wurzelelement statt im :root-Block -- sie gelten nur dort und sind deshalb
 * richtig dort aufgehoben. Wer prüft, ob ein `var()`-Aufruf ins Leere greift,
 * muss sie trotzdem kennen.
 *
 * @return array<string,string>
 */
function estab_test_css_definierte_marken(string $quelle): array
{
    $marken = [];
    foreach (estab_test_css_regeln($quelle) as $regel) {
        foreach ($regel['deklarationen'] as $erklaerung) {
            if (str_starts_with($erklaerung['eigenschaft'], '--')) {
                $marken[$erklaerung['eigenschaft']] ??= $erklaerung['wert'];
            }
        }
    }
    return $marken;
}

/** Steht diese Regel in einem @keyframes-Block? */
function estab_test_css_ist_keyframe(array $regel): bool
{
    return str_contains($regel['kontext'], '@keyframes');
}
