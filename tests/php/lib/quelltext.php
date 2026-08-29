<?php

declare(strict_types=1);

/**
 * Den ausführbaren Teil einer Quelldatei.
 *
 * Viele Prüfungen suchen eine Zeichenkette im Quelltext, um zu belegen, dass
 * eine Stelle etwas tut -- etwa eine Datei hereinzieht. Solche Prüfungen
 * haben eine Lücke, die genau dann auffällt, wenn man sie ernsthaft probt:
 * Ein Kommentar, der den gesuchten Pfad erwähnt, erfüllt sie ebenso wie die
 * Anweisung selbst. Beim Abschalten der Anweisung blieb der Wächter still,
 * weil ein danebenstehender Satz seinen Suchbegriff enthielt.
 *
 * Ein Wächter, der nicht beißt, ist schlimmer als keiner -- er beruhigt.
 * Deshalb prüfen solche Stellen den Quelltext ohne Kommentare.
 */

/** Quelltext ohne Kommentare -- Zeichenketten und Code bleiben unberührt. */
function estab_test_ohne_kommentare(string $quelle): string
{
    $code = '';
    foreach (token_get_all($quelle) as $token) {
        if (is_string($token)) {
            $code .= $token;
            continue;
        }
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            // Zeilenumbrüche behalten, damit Zeilennummern in Meldungen
            // stimmen und Reihenfolgeprüfungen nicht zusammenrutschen.
            $code .= str_repeat("\n", substr_count($token[1], "\n"));
            continue;
        }
        $code .= $token[1];
    }
    return $code;
}
