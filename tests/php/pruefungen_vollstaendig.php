<?php

declare(strict_types=1);

/**
 * Jede geschriebene Prüfung wird auch ausgeführt.
 *
 * Die Suite führt eine namentliche Liste. Wer eine Prüfung anlegt und die
 * Zeile vergisst, hat eine Datei, die aussieht wie ein Wächter, aber nie
 * läuft -- und der Fehler fällt genau dann nicht auf, wenn er zählt: Die
 * Suite meldet weiterhin „OK".
 *
 * Das ist mir in dieser Sitzung passiert. Drei Prüfungen -- der eine
 * Nachrichtenvordruck, der Filter nach Übermittlungsmittel und das
 * Speicherbudget -- lagen im Baum und liefen einzeln grün, während die
 * Suite sie nicht kannte. Ich habe sie von Hand aufgerufen und deshalb
 * nichts gemerkt. Ein anderer, der nur die Suite laufen lässt, hätte
 * nichts gemerkt und nichts von Hand aufgerufen.
 *
 * Eine Prüfung, die nicht läuft, ist schlimmer als keine: Sie beruhigt.
 */

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$ausgabe = [];
$stand = 0;
exec(
    'sh ' . escapeshellarg($root . '/tests/static/run.sh') . ' --list 2>&1',
    $ausgabe,
    $stand
);
$assert(
    $stand === 0,
    'Die Liste der Prüfungen ist nicht abrufbar: ' . implode("\n", $ausgabe)
);
$angemeldet = array_flip(array_values(array_filter(
    array_map('trim', $ausgabe),
    'strlen'
)));
$assert(
    $angemeldet !== [],
    'Die Suite meldet keine einzige Prüfung an.'
);

/**
 * Dateien, die keine eigenständige Prüfung sind.
 *
 * Sie werden von anderen Prüfungen hereingezogen und haben keinen
 * eigenen Einstiegspunkt.
 *
 * @var array<string,string>
 */
$keineEigenePruefung = [
    'pdf_test_fixture' =>
        'Beispieldaten fuer den Nachrichtenvordruck. Hereingezogen von '
        . 'pdf_smoke, incident_pdf_security und der Vorlagensonde.',
    'pdf_template_render_fixture' =>
        'Erzeugt Belegbilder in ein uebergebenes Verzeichnis. Kein Waechter, '
        . 'sondern ein Werkzeug -- aufgerufen aus .github/workflows/ci.yml.',
];
/*
 * Und ein Zulieferer liefert wirklich zu: Er wird von mindestens einer
 * angemeldeten Pruefung hereingezogen. Ohne diese Haelfte waere die Liste
 * oben eine Hintertuer -- wer eine Pruefung nicht anmelden will, traegt
 * sie einfach hier ein.
 */
foreach ($keineEigenePruefung as $name => $grund) {
    $benutzt = false;
    // Auch die Ablaeufe zaehlen: Ein Werkzeug, das nur die CI aufruft, ist
    // ebenso benutzt wie eines, das eine Pruefung hereinzieht.
    $ablauf = file_get_contents($root . '/.github/workflows/ci.yml');
    if (is_string($ablauf) && str_contains($ablauf, $name)) {
        $benutzt = true;
    }
    foreach (glob($root . '/tests/php/*.php') ?: [] as $pfad) {
        if (basename($pfad, '.php') === $name) {
            continue;
        }
        // Diese Datei zaehlt nicht: In ihr steht der Name, weil sie die
        // Ausnahmeliste fuehrt. Zaehlte sie mit, waere jede Eintragung
        // ihr eigener Nachweis -- und die Liste eine Hintertuer.
        if ($pfad === __FILE__) {
            continue;
        }
        $quelle = file_get_contents($pfad);
        if (is_string($quelle) && str_contains($quelle, $name)) {
            $benutzt = true;
            break;
        }
    }
    $assert(
        $benutzt,
        'Die Datei ' . $name . ' steht als Zulieferer in der Ausnahmeliste ('
            . $grund . '), wird aber von keiner Pruefung hereingezogen. Dann '
            . 'ist sie entweder eine Pruefung, die niemand anmeldet, oder '
            . 'toter Code.'
    );
}

$fehlend = [];
foreach (glob($root . '/tests/php/*.php') ?: [] as $pfad) {
    $name = basename($pfad, '.php');
    if (array_key_exists($name, $keineEigenePruefung)) {
        continue;
    }
    if (!array_key_exists($name, $angemeldet)) {
        $fehlend[] = $name;
    }
}
sort($fehlend);
$assert(
    $fehlend === [],
    'Diese Prüfungen liegen im Baum, laufen aber nicht in der Suite: '
        . implode(', ', $fehlend) . '. Eine Prüfung, die nicht läuft, ist '
        . 'schlimmer als keine -- sie beruhigt. Tragen Sie sie in die Liste '
        . 'in tests/static/run.sh ein.'
);

/*
 * Und umgekehrt: keine angemeldete Prüfung ohne Datei.
 *
 * Die Liste lässt eine fehlende Datei still aus (`[ -f ... ] || continue`).
 * Eine umbenannte Prüfung verschwände damit lautlos aus der Suite.
 */
$laufSkript = file_get_contents($root . '/tests/static/run.sh');
$assert(is_string($laufSkript), 'tests/static/run.sh ist nicht lesbar.');
$verwaist = [];
foreach (array_keys($angemeldet) as $name) {
    if (!is_string($name)) {
        continue;
    }
    if (
        is_file($root . '/tests/php/' . $name . '.php')
        || is_file($root . '/tests/static/' . $name . '.sh')
    ) {
        continue;
    }
    // Einige Pruefungen rufen ein Werkzeug auf statt einer Pruefdatei --
    // der Baumlint etwa. Sie stehen mit `register` und einem eigenen Pfad
    // in run.sh; das ist ihr Nachweis.
    if (
        preg_match(
            '~^register\s+' . preg_quote($name, '~') . '\s~m',
            (string) $laufSkript
        ) === 1
    ) {
        continue;
    }
    $verwaist[] = $name;
}
sort($verwaist);
$assert(
    $verwaist === [],
    'Diese Prüfungen sind angemeldet, aber es gibt sie nicht mehr: '
        . implode(', ', $verwaist) . '. Die Suite lässt eine fehlende Datei '
        . 'still aus; eine umbenannte Prüfung verschwände damit lautlos.'
);

printf(
    "Pruefungen vollstaendig: OK (%d assertions, %d angemeldet, %d Dateien)\n",
    $assertions,
    count($angemeldet),
    count(glob($root . '/tests/php/*.php') ?: [])
);
