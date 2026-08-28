<?php

declare(strict_types=1);

/**
 * Zwei Buecher, zwei Zustaendigkeiten -- und keine dritte.
 *
 * Das Einsatztagebuch fuehrt die Lage- und Dokumentationsfunktion oder der
 * ausdruecklich bestimmte ETB-Fuehrer. Das Technische Betriebsbuch fuehrt der
 * Leiter des Fernmeldebetriebs. Sonst niemand.
 *
 * Der Bestand verlangte fuer das TBB die Funktion des Annahme- und
 * Weitergabeplatzes. Der LdF -- derjenige, der das Buch fuehrt -- bekam
 * deshalb: "TBB schreibgeschuetzt. Ihre aktuell wirksamen Funktionen erlauben
 * das Lesen, besitzen aber nicht die Fachzustaendigkeit fuer TTB-Eintraege."
 *
 * Das ist eine Sperre gegen den Zustaendigen und eine Erlaubnis fuer den
 * Unzustaendigen. Beides kehrt sich hier um.
 *
 * Geprueft wird an der Quelle, nicht an einer laufenden Datenbank: Welche
 * Funktion die Bestimmung eines Buches traegt, steht in einer Zeile, und
 * genau die soll sich nicht unbemerkt aendern.
 */

$root = dirname(__DIR__, 2);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$quelle = file_get_contents($root . '/app/logbook.php');
$assert(is_string($quelle), 'logbook.php ist nicht lesbar.');
$quelle = (string) $quelle;

// Der Rumpf, der die Zustaendigkeit ohne Dienstschicht entscheidet.
if (preg_match(
    '~return \$kind === \'etb\'\s*\?(.*?);\s*\}\s*catch~s',
    $quelle,
    $treffer
) !== 1) {
    throw new RuntimeException(
        'Die Zustaendigkeitsentscheidung der Buchfuehrung ist nicht mehr '
            . 'auffindbar. Wenn sie umgebaut wurde, muss dieser Test mit.'
    );
}
$entscheidung = $treffer[1];
// Der Aufruf steht ueber mehrere Zeilen; verglichen wird der Wortlaut,
// nicht sein Umbruch.
$entscheidung = trim(preg_replace('~\s+~', ' ', $entscheidung) ?? '');
$teile = explode(':', $entscheidung, 2);
$assert(count($teile) === 2, 'Die Entscheidung hat keinen zweiten Zweig.');
[$etbZweig, $tbbZweig] = $teile;

// ETB: S2 oder der bestimmte ETB-Fuehrer.
$assert(
    str_contains($etbZweig, "'ETB', 'Stab'") && str_contains($etbZweig, "'S2', 'Stab'"),
    'Das Einsatztagebuch wird nicht mehr von ETB oder S2 gefuehrt: '
        . trim(preg_replace('~\s+~', ' ', $etbZweig) ?? '')
);
$assert(
    !str_contains($etbZweig, 'Fernmelder'),
    'Eine Fernmelderfunktion fuehrt das Einsatztagebuch mit. Zwei Buecher, '
        . 'zwei Zustaendigkeiten.'
);

// TBB: der LdF, und nur er.
$assert(
    str_contains($tbbZweig, "'LdF', 'Fernmelder'"),
    'Das Technische Betriebsbuch wird nicht vom LdF gefuehrt: '
        . trim(preg_replace('~\s+~', ' ', $tbbZweig) ?? '')
);
$assert(
    !str_contains($tbbZweig, "'A/W'"),
    'Der Annahme- und Weitergabeplatz schreibt weiterhin ins Technische '
        . 'Betriebsbuch. Sonst muss niemand hineinschreiben duerfen.'
);
$assert(
    !str_contains($tbbZweig, "'Stab'"),
    'Eine Stabsfunktion schreibt ins Technische Betriebsbuch.'
);

// Zweite Sperre: die Auswahl der bestimmten Fuehrung in der Dienstschicht.
if (preg_match(
    "~\\\$functionClause = \\\$kind === 'etb'\\s*\\?(.*?);~s",
    $quelle,
    $treffer
) !== 1) {
    throw new RuntimeException(
        'Die Auswahl der bestimmten Buchfuehrung ist nicht mehr auffindbar.'
    );
}
$auswahl = trim(preg_replace('~\s+~', ' ', $treffer[1]) ?? '');
$assert(
    str_contains($auswahl, "IN ('ETB','S2')"),
    'Das Einsatztagebuch waehlt nicht mehr ETB oder S2: ' . $auswahl
);
$assert(
    str_contains($auswahl, "`funktion` = 'LdF'"),
    'Die Dienstschicht bestimmt fuer das Technische Betriebsbuch nicht den '
        . 'LdF: ' . $auswahl
);

// Dritte Sperre -- und die eigentliche im Betrieb: die Fachzustaendigkeit.
//
// Sie erzeugte die Meldung "Ihre aktuell wirksamen Funktionen besitzen nicht
// die erforderliche Fachzustaendigkeit BEFOERDERUNG". BEFOERDERUNG traegt
// laut nv_funktionsfaehigkeiten allein der A/W. Solange das TBB sie verlangt,
// bleibt der LdF draussen, gleich was die beiden Sperren darueber sagen.
if (preg_match(
    "~\\\$kind === 'etb'\\s*\\?\\s*'EINSATZTAGEBUCH'\\s*:\\s*'([A-Z_]+)'~",
    $quelle,
    $treffer
) !== 1) {
    throw new RuntimeException(
        'Die verlangte Fachzustaendigkeit der Buchfuehrung ist nicht mehr '
            . 'auffindbar.'
    );
}
$assert(
    $treffer[1] === 'FERNMELDEBETRIEB',
    'Das Technische Betriebsbuch verlangt die Fachzustaendigkeit '
        . $treffer[1] . '. Der LdF traegt FERNMELDEBETRIEB; BEFOERDERUNG '
        . 'traegt allein der A/W, und der fuehrt kein Buch.'
);

// Vierte Sperre: die Seite entscheidet noch einmal selbst, ob sie das
// Eingabefeld ueberhaupt anbietet -- mit demselben Paar aus Fachzustaendigkeit
// und bestimmter Fuehrung. Wird nur eine der vier Stellen umgestellt, bleibt
// die Anwendung still verschlossen, und die Quelle liest sich richtig.
foreach ([
    ['fmtbb/tbb.php', 'FERNMELDEBETRIEB', 'tbb'],
    ['stabetb/etb.php', 'EINSATZTAGEBUCH', 'etb'],
] as [$datei, $erwartet, $buch]) {
    $seite = file_get_contents($root . '/' . $datei);
    $assert(is_string($seite), $datei . ' ist nicht lesbar.');
    if (preg_match(
        // Die Argumentliste traegt selbst Klammern -- (int) $readScope[..] --
        // deshalb wird sie in der Laenge begrenzt, nicht ueber ihre Klammern.
        '~estab_dv_has_write_capability\s*\(.{0,300}?"([A-Z_]+)"\s*\)~s',
        (string) $seite,
        $treffer
    ) !== 1) {
        throw new RuntimeException(
            'Die Seite ' . $datei . ' prueft keine Fachzustaendigkeit mehr.'
        );
    }
    $assert(
        $treffer[1] === $erwartet,
        $datei . ' verlangt die Fachzustaendigkeit ' . $treffer[1]
            . ' statt ' . $erwartet . '. Die Seite entscheidet noch einmal '
            . 'selbst; steht sie auf einer anderen Zustaendigkeit als der '
            . 'Schreibweg, bleibt das Eingabefeld aus.'
    );
    $assert(
        str_contains((string) $seite, 'estab_logbook_is_designated_writer ('),
        $datei . ' fragt nicht mehr nach der bestimmten Buchfuehrung.'
    );
}

// Und die Zuordnung, auf die sich das stuetzt, steht so in der Datenbank.
$schema = file_get_contents(
    $root . '/docker/db/migrations/94-dv-organisational-controls.sql'
);
$assert(is_string($schema), 'Die Migration 94 ist nicht lesbar.');
$assert(
    str_contains(
        (string) $schema,
        "('LdF', 'Fernmelder', 'FERNMELDEBETRIEB',"
    ),
    'Der LdF traegt FERNMELDEBETRIEB nicht mehr; die Sperre oben griffe dann '
        . 'gegen ihn statt fuer ihn.'
);

// Die Abweisung nennt die richtige Funktion.
$assert(
    str_contains($quelle, 'TBB-Einträge erfordern die Funktion LdF.'),
    'Die Abweisung des TBB nennt weiterhin eine andere Funktion als die '
        . 'zustaendige. Wer abgewiesen wird, soll erfahren, wer darf.'
);
$assert(
    str_contains($quelle, 'ETB-Einträge erfordern die Funktion ETB oder S2.'),
    'Die Abweisung des ETB hat ihren Wortlaut verloren.'
);

printf("Buchfuehrung: OK (%d assertions)\n", $assertions);
