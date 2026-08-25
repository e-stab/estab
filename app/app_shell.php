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
 *
 * $contextMarkup nimmt auf, was ein Bereich zusätzlich zur Auswahl stellt --
 * die Dokumentliste der Infosammlung zum Beispiel. Sie stand vorher als
 * eigene Spalte im Inhalt und war damit ein zweites Menü neben diesem. Die
 * Ziele darüber bleiben in jedem Fall dieselben; der Bereich hängt seine
 * eigene Auswahl darunter, statt sie daneben zu stellen.
 */
function estab_shell_menu_markup(
    ?array $identity,
    array $server,
    ?array $navigationIdentity = null,
    bool $withActions = false,
    string $contextMarkup = ''
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
        . $contextMarkup
        . ($withActions ? estab_shell_actions_markup($identity) : '')
        . '</div>';
}

/**
 * Die Arbeitsschritte unter dem Menue.
 *
 * Sie standen rechts im Cockpit, unterhalb von Anmeldung, Einsatz und
 * Besetzung. Auf einem flachen Bildschirm hiess das: scrollen, um an den
 * naechsten Schritt zu kommen. Sie stehen jetzt links unter den Zielen --
 * dort, wo ohnehin hingesehen wird, wenn es weitergehen soll.
 *
 * Als eigener Rahmen, weil ihr Inhalt vom aufgeloesten Einsatzbezug und den
 * wirksamen Funktionen abhaengt. Den ermittelt vorgaben.php bereits; ihn hier
 * noch einmal zu ermitteln waere eine zweite Datenbankverbindung je
 * Seitenaufbau. Der Rahmen erneuert sich mit, wenn dort etwas wechselt, ohne
 * die Seite darunter anzufassen.
 *
 * Sie stehen nur im Nachrichtenbereich, denn nur dort haben sie ein Ziel:
 * Jeder Schritt laedt den Vordruck in die Mitte. Im Cockpit standen sie auf
 * jeder Seite, und das ging auf zwei Weisen schief -- in der Infosammlung
 * ersetzte ein Schritt das gerade gelesene Dokument, und auf der Startseite,
 * die keine Mitte zum Laden hat, riss er ein zweites Fenster auf. Die Ziele
 * links stehen ueberall gleich; die Schritte kommen dazu, wo sie hingehoeren.
 */
function estab_shell_actions_markup(?array $identity): string
{
    if ($identity === null) {
        return '';
    }
    $url = estab_application_url('4fach/vorgaben.php') . '?fragment=aktionen';
    return '<iframe class="estab-shell-actions" name="aktionen"'
        . ' title="Arbeitsschritte"'
        . ' src="' . estab_auth_html($url) . '"></iframe>';
}

/**
 * Eine zusätzliche Auswahl unter den Zielen, im selben Bild wie sie.
 *
 * Die Infosammlung braucht das: Sie hält Dokumente bereit, die nirgends
 * sonst vorkommen. Bisher stellte sie dafür eine eigene Spalte in den
 * Inhalt -- ein zweites Menü neben dem ersten, das noch einmal Breite kostete
 * und noch einmal anders aussah.
 *
 * @param list<array{title: string, description: string, href: string}> $items
 */
function estab_shell_context_markup(string $heading, array $items): string
{
    if ($items === []) {
        return '';
    }
    $entries = '';
    foreach ($items as $item) {
        $entries .= '<li class="estab-shell-context-item">'
            . '<a class="estab-shell-context-link"'
            . ' href="' . estab_auth_html($item['href']) . '"'
            . ' target="mainframe"'
            . ' data-estab-bos-document-link>'
            . estab_auth_html($item['title'])
            /*
             * Die Beschreibung bleibt für Vorleseprogramme stehen, nimmt aber
             * keine Zeile mehr: Sie steht ohnehin im Kopf des geöffneten
             * Dokuments, und dort liest sie, wer sie braucht.
             */
            . '<span class="estab-visually-hidden"> — '
            . estab_auth_html($item['description']) . '</span>'
            . '</a></li>';
    }
    return '<div class="estab-shell-context" data-estab-shell-context>'
        . '<div class="estab-navigation-sidebar-heading">'
        . '<h2>' . estab_auth_html($heading) . '</h2>'
        . '</div>'
        . '<ul class="estab-shell-context-list"'
        . ' aria-label="' . estab_auth_html($heading) . '">'
        . $entries . '</ul></div>';
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
