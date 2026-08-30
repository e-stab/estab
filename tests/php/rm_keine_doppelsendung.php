<?php

declare(strict_types=1);

/**
 * Nach einer Handlung folgt eine Weiterleitung, keine Seite.
 *
 * > „Um diese Seite anzuzeigen, müssen die von Firefox gesendeten Daten
 * > erneut gesendet werden, wodurch alle zuvor durchgeführten Aktionen
 * > wiederholt werden. Diese Meldung kommt ständig im Browser."
 *
 * Der Steuerlauf beantwortete jede Handlung mit einer Seite. Wer danach
 * neu lud oder zurückging, bekam die Frage -- und wer sie bestätigte,
 * verschickte eine Meldung ein zweites Mal, beförderte zweimal oder trug
 * zweimal ins Buch ein.
 *
 * ## Warum das überhaupt geht
 *
 * Nachgemessen: Ein GET auf dieselbe Adresse liefert dieselbe Ansicht. Die
 * Sitzung trägt den Zustand (`$_SESSION["menue"]`), nicht die Anfrage. Die
 * Weiterleitung braucht deshalb keine Zustandsangaben mitzuschleppen.
 *
 * ## Wohin die Rückmeldung geht
 *
 * Eine abgeschlossene Handlung sagt, was geschehen ist und wohin die
 * Nachricht ging. Dieser Satz überlebt die Weiterleitung in der Sitzung
 * und wird genau einmal gezeigt. Er wird serverseitig aus Aufgabe,
 * Aktionsschalter und gespeicherter Richtung abgeleitet -- kein
 * Bedienertext, und deshalb auch nichts, was über die Adresse laufen
 * müsste.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/session_ui.php';
require_once __DIR__ . '/lib/quelltext.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// ------------------------------------------- Die Rueckmeldung ueberlebt

$sitzung = [];
$assert(
    estab_session_ui_outcome_take($sitzung) === null,
    'Ohne hinterlegte Rueckmeldung kommt trotzdem eine.'
);

$rueckmeldung = [
    'tone' => 'forwarded',
    'title' => 'Die Meldung wurde befördert.',
    'destination' => 'An S2',
    'detail' => 'Der Eingang ist im Betriebsbuch nachgewiesen.',
];
estab_session_ui_outcome_store($sitzung, $rueckmeldung);
$assert(
    $sitzung !== [],
    'Die Rueckmeldung wird nicht in der Sitzung abgelegt.'
);
$geholt = estab_session_ui_outcome_take($sitzung);
$assert(
    $geholt === $rueckmeldung,
    'Die Rueckmeldung kommt veraendert zurueck.'
);
/*
 * Und sie kommt genau einmal. Bliebe sie liegen, staende die Bestaetigung
 * einer laengst erledigten Handlung noch auf der naechsten Seite -- und
 * auf der uebernaechsten.
 */
$assert(
    estab_session_ui_outcome_take($sitzung) === null,
    'Die Rueckmeldung bleibt liegen und wird ein zweites Mal gezeigt.'
);

/*
 * Was nicht wie eine Rueckmeldung aussieht, wird nicht abgelegt.
 *
 * Die Sitzung ist serverseitig, aber sie ueberlebt Programmfassungen. Ein
 * Eintrag aus einer alten Fassung darf die Anzeige nicht sprengen.
 */
$sitzung = ['estab_ui_outcome' => 'kein Feld, sondern eine Zeichenkette'];
$assert(
    estab_session_ui_outcome_take($sitzung) === null,
    'Ein unbrauchbarer Sitzungseintrag wird als Rueckmeldung ausgegeben.'
);
$sitzung = ['estab_ui_outcome' => ['tone' => 'erfunden']];
$assert(
    estab_session_ui_outcome_take($sitzung) === null,
    'Eine Rueckmeldung mit unbekannter Tonart wird ausgegeben.'
);

// -------------------------------------------- Der Steuerlauf leitet um

$steuerlauf = estab_test_ohne_kommentare(
    (string) file_get_contents($root . '/4fach/mainindex.php')
);

/*
 * Der Rumpf der Funktion, nicht der Rest der Datei.
 *
 * Eine Suche ab `function resetframeset` ohne Grenze findet jedes spaetere
 * Vorkommen im ganzen Steuerlauf -- und meldete deshalb einen
 * Seitenaufbau, den eine voellig andere Stelle macht.
 */
$reset = '';
$resetAnfang = strpos($steuerlauf, 'function resetframeset');
if (is_int($resetAnfang)) {
    $resetEnde = strpos($steuerlauf, "\n  }", $resetAnfang);
    if (is_int($resetEnde)) {
        $reset = substr($steuerlauf, $resetAnfang, $resetEnde - $resetAnfang);
    }
}
$assert(
    $reset !== '',
    'Die Antwortfunktion einer Handlung ist nicht auffindbar.'
);

/*
 * resetframeset() beantwortete die Handlung mit einer Seite. Jetzt legt es
 * die Rueckmeldung ab und leitet weiter -- 303, damit der Browser die
 * Weiterleitung als GET ausfuehrt und nicht die Handlung wiederholt.
 */
$assert(
    str_contains($reset, 'estab_session_ui_outcome_store'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Antwort einer Handlung legt ihre Rueckmeldung nicht in der '
            . 'Sitzung ab; sie ueberlebte die Weiterleitung nicht.'
    )
);
$assert(
    preg_match('~Location:.*?303~s', $reset) === 1,
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Antwort einer Handlung ist keine Weiterleitung mit 303. Der '
            . 'Browser fragt dann beim Neuladen, ob die Daten erneut '
            . 'gesendet werden sollen.'
    )
);
/*
 * Der Seitenaufbau darf nur den Fall *ohne* Rueckmeldung bedienen.
 *
 * Diesen Weg nimmt die Abmeldung: Sie frischt beide Rahmen auf, damit das
 * Cockpit nicht weiter eine Sitzung anzeigt, die es nicht mehr gibt. Dort
 * ist auch nichts zu wiederholen, wenn jemand neu laedt.
 *
 * Geprueft wird deshalb die Reihenfolge: Weiterleitung und `exit` stehen
 * *vor* dem Seitenaufbau. Stuende der Aufbau davor, gaebe es die
 * Weiterleitung nie -- und die Kopfzeilen kaemen zu spaet.
 */
$weiterleitung = strpos($reset, 'Location:');
$aufbau = strpos($reset, 'pre_html');
$assert(
    is_int($weiterleitung) && is_int($aufbau) && $weiterleitung < $aufbau,
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Antwort einer Handlung baut eine Seite auf, bevor sie '
            . 'weiterleitet.'
    )
);
$assert(
    preg_match(
        '~is_array \(\$confirmation\).*?Location:.*?exit;~s',
        $reset
    ) === 1,
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Weiterleitung haengt nicht am Vorliegen einer Rueckmeldung; '
            . 'sie traefe damit auch die Abmeldung, deren Cockpit dann '
            . 'weiter eine beendete Sitzung anzeigte.'
    )
);
$assert(
    !str_contains($reset, 'estab_session_ui_message_confirmation_markup'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Antwort einer Handlung baut die Bestaetigungstafel selbst; sie '
            . 'gehoert auf die Seite nach der Weiterleitung.'
    )
);

/*
 * Und die Weiterleitung endet die Anfrage. Ohne exit liefe der Steuerlauf
 * weiter und schriebe eine Seite hinter die Kopfzeilen -- der Browser
 * folgte zwar der Weiterleitung, aber die Handlung liefe unbemerkt in
 * Zweige, die sie nichts angehen.
 */
$assert(
    preg_match('~Location:.*?exit~s', $reset) === 1,
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Nach der Weiterleitung laeuft der Steuerlauf weiter.'
    )
);

// ------------------------------------ Und die Seite zeigt sie danach an

/*
 * Abgeholt wird sie an der einen Stelle, die jede Ansicht des
 * Nachrichtenrahmens vor ihrem <body> aufruft.
 *
 * Nicht in den Ansichten selbst: Dann muesste jede der sieben dieselbe
 * Zeile tragen, und die achte vergaesse sie. Wer eine neue Ansicht
 * hinzufuegt, soll die Bestaetigung bekommen, ohne davon zu wissen.
 */
$werkzeuge = estab_test_ohne_kommentare(
    (string) file_get_contents($root . '/4fach/tools.php')
);
$assert(
    str_contains($werkzeuge, 'estab_session_ui_outcome_take'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Nach der Weiterleitung holt niemand die Rueckmeldung ab; die '
            . 'Bestaetigung ginge verloren, und der Bedienende wuesste '
            . 'nicht, ob und wohin die Nachricht gegangen ist.'
    )
);
$vorHtml = '';
$vorHtmlAnfang = strpos($werkzeuge, 'function pre_html');
if (is_int($vorHtmlAnfang)) {
    $vorHtmlEnde = strpos($werkzeuge, "\n  }", $vorHtmlAnfang);
    if (is_int($vorHtmlEnde)) {
        $vorHtml = substr(
            $werkzeuge,
            $vorHtmlAnfang,
            $vorHtmlEnde - $vorHtmlAnfang
        );
    }
}
$assert(
    $vorHtml !== '' && str_contains($vorHtml, 'estab_rueckmeldung_einsetzen'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Der gemeinsame Seitenkopf setzt die Rueckmeldung nicht ein. Sie '
            . 'erschiene nur dort, wo jemand daran gedacht hat.'
    )
);
/*
 * Und sie steht *hinter* dem <body>, nicht davor. Vor dem Koerper
 * ausgegeben, oeffnete der Browser ihn selbst -- und die Klasse, die der
 * Aufrufer an sein <body> schreibt, ginge verloren.
 */
$assert(
    preg_match('~stripos\s*\(\s*\$ausgabe,\s*"<body"~', $werkzeuge) === 1,
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Rueckmeldung wird nicht hinter dem Koerper eingesetzt.'
    )
);

/*
 * Auch die Zustandshandlungen leiten um.
 *
 * "Gelesen" und "erledigt" aendern etwas und fielen danach in den
 * Seitenaufbau der Liste durch. Ein zweites Absenden setzte das Haekchen
 * ein weiteres Mal -- beim Umschalten also wieder zurueck, und der
 * Bedienende sah, dass sein Klick "nicht gewirkt" hat.
 */
/*
 * Gelesen wird ab dem Aufraeumen der Handlung -- die Weiterleitung muss
 * unmittelbar danach kommen, im selben Zweig. Nicht ab dem Anfang des
 * Zweiges bis zur naechsten Leerzeile: Nach dem Entfernen der Kommentare
 * bleiben deren Zeilenumbrueche stehen, und die Leerzeile faellt dann
 * mitten in den Zweig.
 */
$zustand = '';
$zustandAnfang = strpos(
    $steuerlauf,
    'unset ($returnValue ["action"], $returnValue ["todo"]);'
);
if (is_int($zustandAnfang)) {
    $zustand = substr($steuerlauf, $zustandAnfang, 600);
}
$assert(
    $zustand !== '',
    'Der Zweig fuer gelesen und erledigt ist nicht auffindbar.'
);
$assert(
    preg_match('~Location:.*?303~s', $zustand) === 1
        && str_contains($zustand, 'exit;'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Gelesen und erledigt bauen nach der Aenderung wieder eine Seite '
            . 'auf. Ein zweites Absenden schaltet das Haekchen zurueck.'
    )
);
/*
 * Und nur bei POST. Ein GET, der zufaellig dieselben Felder traegt, darf
 * nicht in eine Weiterleitungsschleife laufen.
 */
$assert(
    str_contains($zustand, 'REQUEST_METHOD')
        && str_contains($zustand, '"POST"'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Weiterleitung nach einer Zustandshandlung unterscheidet nicht '
            . 'zwischen POST und GET.'
    )
);

/*
 * Und sie wird wirklich eingesetzt -- hinter dem Koerper, mit seiner
 * Klasse.
 *
 * Die Pruefungen oben lesen Quelltext. Diese hier fuehrt die Stelle aus:
 * Sitzung fuellen, eine Seite aufbauen lassen, nachsehen, was herauskommt.
 * Ohne sie stuende nur fest, dass die richtigen Namen im Code vorkommen.
 */
$_SESSION = [];
estab_session_ui_outcome_store($_SESSION, [
    'tone' => 'forwarded',
    'title' => 'Die Meldung wurde befördert.',
    'destination' => 'An S2',
    'detail' => 'Der Vorgang ist nachgewiesen.',
]);
$vorherigesVerzeichnis = getcwd();
if (!is_string($vorherigesVerzeichnis) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Das Nachrichtenverzeichnis ist nicht erreichbar.');
}
try {
    require_once $root . '/4fach/tools.php';
    /*
     * Der aeussere Puffer faengt auf, der innere wird durchgereicht.
     *
     * ob_get_clean() gibt den Inhalt zurueck, *ohne* den Ausgabefilter
     * anzuwenden -- damit saehe diese Pruefung nie, was der Browser
     * bekommt. Erst ob_end_flush() laesst den Filter laufen; das Ergebnis
     * landet dann im aeusseren Puffer. Im Betrieb macht PHP dasselbe am
     * Ende der Anfrage von allein.
     */
    ob_start();
    estab_rueckmeldung_einsetzen();
    echo '<body class="estab-tool-page">Inhalt</body>';
    ob_end_flush();
    $seite = (string) ob_get_clean();
} finally {
    chdir($vorherigesVerzeichnis);
}
$assert(
    str_contains($seite, '<body class="estab-tool-page">'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Der Koerper verliert seine Klasse, wenn die Tafel eingesetzt wird.'
    )
);
$koerper = strpos($seite, '<body');
$tafel = strpos($seite, 'Die Meldung wurde befördert.');
$assert(
    is_int($koerper) && is_int($tafel) && $tafel > $koerper,
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Tafel steht nicht hinter dem Koerper. Vor ihm ausgegeben, '
            . 'oeffnete der Browser den Koerper selbst und die Klasse ginge '
            . 'verloren.'
    )
);
$assert(
    str_contains($seite, 'An S2'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Rueckmeldung steht nicht vollstaendig auf der Seite nach der '
            . 'Weiterleitung.'
    )
);
$assert(
    str_contains($seite, 'FramesVeraendern')
        && str_contains($seite, 'vorgaben'),
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Mit der Tafel wird die Seitenleiste nicht aufgefrischt; die '
            . 'Warteschlangenzaehler stuenden bis zu dreissig Sekunden lang '
            . 'falsch neben einer Meldung, die gerade abgesetzt wurde.'
    )
);
$assert(
    estab_session_ui_outcome_take($_SESSION) === null,
    estab_ux_requirement(
        'UX-KEINE-DOPPELSENDUNG',
        'Die Rueckmeldung bleibt nach dem Einsetzen liegen und erschiene ein '
            . 'zweites Mal.'
    )
);

printf("Keine Doppelsendung: OK (%d assertions)\n", $assertions);
