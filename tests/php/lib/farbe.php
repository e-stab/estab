<?php

declare(strict_types=1);

/**
 * Die Farbrechnung der Anwendung, für Prüfungen erreichbar gemacht.
 *
 * Die Rechnung selbst steht in `4fach/tools.php` und gehört dorthin: Sie
 * entscheidet im Betrieb, welche Tinte auf welcher Durchschrift steht. Eine
 * Prüfung darf diese Datei aber nicht einbinden -- sie zieht relative
 * Includes, Anmeldung und Sitzung nach sich und würde einen Webauftritt
 * starten statt eine Rechnung bereitzustellen.
 *
 * Bisher löste jede Prüfung das für sich. `list_contrast_security.php` schnitt
 * sich die Funktionen mit einem eigenen Klammerzähler aus dem Quelltext,
 * `ux_form_contrast.php` schrieb die Formel ein zweites Mal als Closure ab.
 * Zwei Wege, dieselbe Rechnung zu bekommen, und einer davon ist eine Kopie,
 * die auseinanderlaufen kann.
 *
 * Diese Datei ist der eine Weg. Sie kennt den Kunstgriff, die Prüfungen
 * benutzen nur noch die Rechnung.
 */

/**
 * Eine einzelne Funktion aus einer Datei holen, ohne die Datei auszuführen.
 *
 * Sucht die Kopfzeile, zählt die geschweiften Klammern bis zum Ende des
 * Rumpfes und wertet genau dieses Stück aus. Ist die Funktion schon
 * vorhanden, geschieht nichts -- so darf eine Prüfung mehrere Helfer
 * anfordern, ohne die Reihenfolge zu kennen.
 */
function estab_test_funktion_uebernehmen(string $pfad, string $name): void
{
    if (function_exists($name)) {
        return;
    }
    $quelle = file_get_contents($pfad);
    if (!is_string($quelle)) {
        throw new RuntimeException('Datei nicht lesbar: ' . $pfad);
    }
    $muster = '/\n\s*function\s+' . preg_quote($name, '/') . '\s*\(/';
    if (preg_match($muster, $quelle, $treffer, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException('Funktion nicht gefunden: ' . $name);
    }
    $beginn = $treffer[0][1];
    $klammer = strpos($quelle, '{', $beginn);
    if ($klammer === false) {
        throw new RuntimeException('Funktionsrumpf nicht gefunden: ' . $name);
    }
    $tiefe = 0;
    $laenge = strlen($quelle);
    for ($i = $klammer; $i < $laenge; $i++) {
        if ($quelle[$i] === '{') {
            $tiefe++;
        } elseif ($quelle[$i] === '}') {
            $tiefe--;
            if ($tiefe === 0) {
                eval(substr($quelle, $beginn, $i - $beginn + 1));
                return;
            }
        }
    }
    throw new RuntimeException('Unausgeglichener Rumpf: ' . $name);
}

/**
 * Die drei Farbfunktionen der Anwendung bereitstellen.
 *
 * Mehrfacher Aufruf ist unschädlich.
 */
function estab_test_farbrechnung_laden(): void
{
    // tests/php/lib -> tests/php -> tests -> Wurzel
    $tools = dirname(__DIR__, 3) . '/4fach/tools.php';
    foreach ([
        'estab_colour_channels',
        'estab_colour_relative_luminance',
        'estab_colour_contrast_ratio',
    ] as $name) {
        estab_test_funktion_uebernehmen($tools, $name);
    }
}

/**
 * Relative Helligkeit einer Farbe nach WCAG.
 *
 * Eine Farbe, die die Anwendung nicht lesen kann, ist ein Befund und kein
 * Sonderfall: Sie fliegt laut auf, statt still als Schwarz durchzugehen.
 */
function estab_test_helligkeit(string $farbe): float
{
    estab_test_farbrechnung_laden();
    $kanaele = estab_colour_channels($farbe);
    if (!is_array($kanaele)) {
        throw new RuntimeException('Unlesbare Farbangabe: ' . $farbe);
    }
    return (float) estab_colour_relative_luminance($kanaele);
}

/** Kontrastverhältnis zweier Farben nach WCAG, immer >= 1. */
function estab_test_kontrast(string $vorn, string $hinten): float
{
    estab_test_farbrechnung_laden();
    return (float) estab_colour_contrast_ratio(
        estab_test_helligkeit($vorn),
        estab_test_helligkeit($hinten)
    );
}
