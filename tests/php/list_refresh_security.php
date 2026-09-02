<?php

declare(strict_types=1);

/**
 * A list that refreshes itself must not fight the person reading it.
 *
 * The Sichter, LdF and Fernmelder lists carried a <meta http-equiv="refresh">
 * every ten seconds, the staff reading list every two minutes. That reload is
 * unconditional and cannot be stopped: it discarded the search term and the
 * scroll position, and whoever was typing typed into a page that vanished.
 * The replacement postpones while somebody is working in the page and puts the
 * scroll position back afterwards.
 */

$root = dirname(__DIR__, 2);
$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
} finally {
    chdir($originalWorkingDirectory);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// No list may carry an unconditional meta refresh any more.
$toolsSource = file_get_contents($root . '/4fach/tools.php');
if (!is_string($toolsSource)) {
    throw new RuntimeException('Could not read 4fach/tools.php');
}
foreach (['fmdliste', 'stabliste', 'siliste', 'si2liste'] as $list) {
    $marker = '$cfg ["itv"] ["' . $list . '"]';
    $offset = strpos($toolsSource, $marker);
    $assert(
        $offset !== false,
        'The refresh interval of ' . $list . ' is no longer used'
    );
    $line = substr(
        $toolsSource,
        (int) strrpos(substr($toolsSource, 0, (int) $offset), "\n"),
        200
    );
    $assert(
        str_contains($line, 'estab_list_refresh_script'),
        'List ' . $list . ' still reloads through an unconditional meta refresh'
    );
}

// The interval is validated, not echoed.
$assert(
    estab_list_refresh_script('abc') === '',
    'A non-numeric interval reaches the page'
);
$assert(
    estab_list_refresh_script(2) === '',
    'An interval below the lower bound is accepted and hammers the server'
);
$assert(
    estab_list_refresh_script(99999) === '',
    'An interval above the upper bound is accepted'
);
$assert(
    estab_list_refresh_script('10') !== '',
    'A numeric string interval is rejected although it is valid'
);

// 30 Sekunden -- die Entscheidung des Betreibers vom 30.08.2026. Zehn
// Sekunden waren nicht noetig und kosteten das Dreifache.
$script = estab_list_refresh_script(30);
$assert(
    str_starts_with($script, '<script nonce="')
        && str_contains($script, ' data-estab-list-refresh="30">')
        && str_ends_with(rtrim($script), '</script>'),
    'The refresh script is not a closed script element'
);
$assert(
    !str_contains($script, 'http-equiv'),
    'The refresh still runs through a meta element'
);

// It has to wait while somebody works in the page.
foreach ([
    "tag==='input'" => 'a text field',
    "tag==='textarea'" => 'a multi-line field',
    "tag==='select'" => 'a selection',
    'isContentEditable' => 'an editable area',
    'getSelection' => 'a marked text',
] as $needle => $what) {
    $assert(
        str_contains($script, $needle),
        'The refresh does not wait for ' . $what
    );
}
$assert(
    str_contains($script, 'schedule(5000)'),
    'The refresh gives up instead of trying again shortly after'
);

/*
 * Es wird nur der Inhalt erneuert, nicht die Seite.
 *
 * > "Bei den Aktualisierungen moechte ich es so haben das nicht die gesamte
 * > Seite staendig komplett neu geladen wird. Es sollen nur die Inhalten
 * > aktualisiert werden."
 *
 * Das Neuladen hatte drei Nachteile, und der dritte war der schlimmste:
 *
 * 1. Es warf die Bildlaufstelle weg und musste sie umstaendlich merken.
 * 2. Es baute die ganze Seite neu auf -- Kopf, Menue, Skripte.
 * 3. Die Listenseiten kommen aus einem POST. `window.location.reload()`
 *    auf einer POST-Antwort laesst den Browser fragen, ob die Daten
 *    erneut gesendet werden sollen. Alle zehn Sekunden. Genau das hat
 *    der Betrieb gemeldet.
 *
 * Nachgemessen: Ein GET auf dieselbe Adresse liefert dieselbe Ansicht --
 * die Sitzung traegt den Zustand. Der Inhalt laesst sich also holen und
 * einsetzen, ohne die Seite zu verlassen.
 */
$assert(
    !str_contains($script, 'window.location.reload'),
    'Die Aktualisierung laedt die Seite neu. Auf einer POST-Antwort fragt '
        . 'der Browser dann bei jedem Takt, ob die Daten erneut gesendet '
        . 'werden sollen.'
);
$assert(
    str_contains($script, 'fetch(')
        && str_contains($script, "credentials:'same-origin'"),
    'Die Aktualisierung holt den Inhalt nicht selbst.'
);
$assert(
    str_contains($script, 'DOMParser')
        && str_contains($script, 'replaceChildren'),
    'Die Aktualisierung setzt den geholten Inhalt nicht ein.'
);
/*
 * Skripte aus der Antwort werden nicht ausgefuehrt.
 *
 * Eingesetztes Markup fuehrt seine <script>-Elemente ohnehin nicht aus.
 * Sie neu zu erzeugen waere die uebliche Abhilfe -- hier waere sie falsch:
 * Die Richtlinie bindet Skripte an eine Einmalkennung, und die der
 * geholten Antwort ist eine andere als die des laufenden Dokuments. Ein
 * nachgebautes Skript waere entweder gesperrt oder die Kennung muesste
 * durchgereicht werden, und damit waere die Richtlinie ausgehebelt.
 *
 * Die Listenseiten tragen ihre Skripte im Kopf, nicht im Inhalt. Die
 * Pruefung unten haelt das fest.
 */
$assert(
    str_contains($script, "querySelectorAll('script')")
        && str_contains($script, 'remove()'),
    'Die Aktualisierung entfernt keine Skripte aus dem geholten Inhalt.'
);
$assert(
    str_contains($script, 'schedule(30000)'),
    'Der eingestellte Takt erreicht die Ablaufsteuerung nicht'
);

// The scheduler must run once, not stack up timers on every tick.
$assert(
    substr_count($script, 'window.setTimeout') === 1,
    'The refresh schedules more than one timer and drifts'
);

// The timer may only start once the document has finished loading. The meta
// refresh it replaced counted from the finished document; a timer started while
// the frame was still loading reloads that frame before it is done, and a frame
// that reloads before it is done keeps postponing the load event of the
// frameset around it. On a slow answer the workspace then never finished
// loading at all -- the page was readable, but the browser never called it
// loaded, and everything waiting for that event waited forever.
$assert(
    str_contains($script, "if(document.readyState==='complete'){start();}")
        && str_contains($script, "window.addEventListener('load',start);"),
    'The refresh timer starts before the document has finished loading'
);
$assert(
    !preg_match('~\n\s*schedule\(\d+\);\n~', $script),
    'The refresh still schedules unconditionally at parse time'
);

/*
 * Der Takt des Cockpits kommt aus derselben Einstellung.
 *
 * Beim Nachmessen im Browser stand die Liste auf 30 Sekunden, das Cockpit
 * aber weiter auf 10. Der Grund: 4fcfg/para.inc.php -- die Datei, in der
 * $cfg["itv"] steht -- wurde in vorgaben.php nie eingebunden. Die Abfrage
 * `isset($cfg["itv"]["status"]) ? ... : 10` fiel deshalb immer auf die 10
 * zurueck.
 *
 * Eine Einstellung, die aussieht wie eine Einstellung und keine ist, ist
 * schlimmer als ein fester Wert: Wer sie aendert, glaubt, etwas getan zu
 * haben.
 */
$vorgaben = file_get_contents($root . '/4fach/vorgaben.php');
$assert(is_string($vorgaben), '4fach/vorgaben.php ist nicht lesbar.');
$vorgaben = (string) $vorgaben;
$assert(
    str_contains($vorgaben, "/../4fcfg/para.inc.php"),
    'Das Cockpit bindet die Datei mit den Taktangaben nicht ein; sein Takt '
        . 'faellt still auf den eingebauten Rueckfallwert zurueck.'
);
$einbindung = strpos($vorgaben, "/../4fcfg/para.inc.php");
$verwendung = strpos($vorgaben, '$cfg[\'itv\'][\'status\']');
$assert(
    is_int($einbindung) && is_int($verwendung) && $einbindung < $verwendung,
    'Das Cockpit liest den Takt, bevor es ihn geladen hat.'
);

printf("list refresh: OK (%d assertions)\n", $assertions);
