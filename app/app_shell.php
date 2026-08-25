<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/navigation.php';
require_once __DIR__ . '/csp.php';

/**
 * Die eine Hülle, in der jede Seite der Anwendung steht.
 *
 * Bis hierher trug jeder Bereich seine eigene: Die Startseite hatte eine
 * Leiste oben und Kacheln, der Nachrichtenbereich eine Seitenleiste links in
 * einem Rahmen, die Werkzeugseiten gar nichts. Wer zwischen ihnen wechselte,
 * fand das Menü jedesmal woanders -- und wer sich einen Weg gemerkt hatte,
 * merkte ihn sich vergeblich.
 *
 * Die Hülle teilt den Bildschirm in drei Spalten, und zwei davon stehen
 * still:
 *
 *   links   das Menü -- immer da, immer gleich, immer an derselben Stelle
 *   mitte   der Inhalt -- das Einzige, was sich ändert
 *   rechts  das Cockpit -- Anmeldung, Einsatz, Warteschlangen, Aktionen
 *
 * Jede Spalte scrollt für sich. Das ist nicht nur bequemer, es beseitigt
 * auch den Grund, aus dem sich Elemente beim Scrollen überlagerten: Bisher
 * klebte die Navigation mit `position: sticky` am unteren Rand einer
 * mitscrollenden Spalte und geriet dabei über ihre Nachbarn. Drei eigene
 * Scrollbereiche brauchen kein Kleben.
 */

/**
 * Die feste Breite der beiden stehenden Spalten -- dieselben Werte wie in
 * `.estab-shell` im Stylesheet.
 *
 * Das Menue ist breiter geworden, seit die Arbeitsschritte dort stehen: Zwei
 * Ziele nebeneinander brauchen Platz, und ohne ihn braeche
 * "Fuehrungsstelle" mitten durch. Die Breite kommt aus der Mitte, nicht vom
 * Cockpit -- das behaelt seine.
 */
const ESTAB_SHELL_MENU_WIDTH = 'clamp(15.5rem, 18vw, 18rem)';
const ESTAB_SHELL_COCKPIT_WIDTH = 'clamp(15rem, 19vw, 20rem)';

/**
 * Den Kopf der Seite ausgeben.
 *
 * Das Stylesheet wird eingebunden, nicht eingebettet: Es ist dasselbe für
 * alle Spalten und alle Bereiche, und der Browser soll es einmal holen.
 */
function estab_shell_head(string $title): string
{
    return '<!doctype html>' . "\n"
        . '<html lang="de">' . "\n"
        . '<head>' . "\n"
        . '<meta charset="UTF-8">' . "\n"
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . "\n"
        . '<title>' . estab_auth_html($title) . '</title>' . "\n"
        . '<link rel="shortcut icon" href="'
        . estab_auth_html(estab_application_url('favicon.ico')) . '">' . "\n"
        . '<link rel="stylesheet" href="'
        . estab_auth_html(estab_application_url('estab-ui.css')) . '">' . "\n"
        . '</head>' . "\n";
}

/**
 * Die linke Spalte: das Menü.
 *
 * Es kommt aus derselben Quelle wie bisher. Neu ist allein, dass es jede
 * Seite hat und nicht nur der Nachrichtenbereich.
 */
function estab_shell_menu_markup(
    ?array $identity,
    array $server,
    ?array $navigationIdentity = null
): string {
    return '<div class="estab-shell-menu" data-estab-shell-menu>'
        . '<a class="estab-shell-brand" href="'
        . estab_auth_html(estab_application_root()) . '" target="_top">'
        . 'eStab</a>'
        . estab_navigation_markup(
            $identity !== null,
            $server,
            true,
            true,
            $navigationIdentity ?? $identity
        )
        . '</div>';
}

/**
 * Die rechte Spalte: das Cockpit.
 *
 * Es steht in einem eigenen Dokument. Der Grund ist nicht Bequemlichkeit:
 * Das Cockpit fragt Warteschlangen, Besetzung und Einsatzstand ab und
 * erneuert sich selbst im Takt. Als eigenes Dokument tut es das, ohne die
 * Seite darunter anzufassen -- und eine Seite, die gerade ein halb
 * ausgefuelltes Formular traegt, wird davon nicht beruehrt.
 */
function estab_shell_cockpit_markup(): string
{
    $url = estab_application_url('4fach/vorgaben.php') . '?fragment=cockpit';
    return '<iframe class="estab-shell-cockpit" name="vorgaben"'
        . ' title="Anmeldung, Einsatz und Warteschlangen"'
        . ' src="' . estab_auth_html($url) . '"></iframe>';
}

/**
 * Die Huelle oeffnen: Kopf, Koerper, Raster, Menue, Beginn des Inhalts.
 *
 * Nach diesem Aufruf gibt die Seite ihren Inhalt aus; estab_shell_end()
 * schliesst ihn und setzt das Cockpit daneben.
 */
function estab_shell_begin(
    string $title,
    ?array $identity,
    array $server,
    string $contentClass = ''
): string {
    $classes = 'estab-shell-content'
        . ($contentClass === '' ? '' : ' ' . $contentClass);
    return estab_shell_head($title)
        . '<body class="estab-shell-body">' . "\n"
        . '<div class="estab-shell" data-estab-shell>' . "\n"
        . estab_shell_menu_markup($identity, $server) . "\n"
        . '<main class="' . estab_auth_html($classes) . '"'
        . ' data-estab-shell-content>' . "\n";
}

/** Den Inhalt schliessen, das Cockpit setzen, die Huelle schliessen. */
function estab_shell_end(): string
{
    return "\n" . '</main>' . "\n"
        . estab_shell_cockpit_markup() . "\n"
        . '</div>' . "\n"
        . '</body>' . "\n"
        . '</html>' . "\n";
}
