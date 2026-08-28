<?php

declare(strict_types=1);

/**
 * Ein geoeffnetes Infofaehnchen liegt obenauf.
 *
 * Die Ausfuellhilfe eines Feldes oeffnet sich als kleines Blatt neben dem
 * Fragezeichen. Sie trug `position: fixed` und `z-index: 1000` -- hoeher
 * geht kaum, und trotzdem legten sich andere Infopunkte und die klebende
 * Aktionsleiste darueber.
 *
 * Der Grund steht nicht am Faehnchen, sondern am Vordruck: Er traegt
 * `container-type: inline-size` und `zoom`, damit er sich der Spalte
 * anpasst, ohne gestaucht zu werden. Beides macht ihn zum enthaltenden Block
 * fuer fest gestellte Nachfahren und zu einem eigenen Stapelkontext. Die
 * 1000 gilt damit nur *innerhalb* des Vordrucks. Die Aktionsleiste daneben
 * steht auf 30 -- und gewinnt, weil sie ausserhalb liegt.
 *
 * Ein hoeherer Wert haette daran nichts geaendert. Das Blatt muss den
 * Stapelkontext verlassen: Beim Oeffnen wandert es an das Dokument, wo seine
 * 1000 und seine Bildschirmkoordinaten wieder gelten.
 *
 * Was diese Pruefung nicht kann: nachsehen, ob wirklich nichts mehr
 * ueberdeckt. Das misst tools/bedienpruefung/blick/infofaehnchen.mjs im
 * Browser ueber alle Faehnchen und mehrere Bildschirmgroessen. Hier steht
 * nur, dass der Umzug im Bauplan steht -- damit er nicht still verschwindet.
 */

$root = dirname(__DIR__, 2);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$quelle = file_get_contents($root . '/4fach/official_message_form.php');
$assert(is_string($quelle), 'Der Vordruck ist nicht lesbar.');
$quelle = (string) $quelle;

// Der Umzug geschieht beim Oeffnen, vor dem Ausrichten -- sonst rechnete das
// Ausrichten mit Koordinaten aus dem alten Kontext.
if (preg_match('~function open\(button\) \{(.*?)\n  \}~s', $quelle, $treffer) !== 1) {
    throw new RuntimeException(
        'Das Oeffnen der Ausfuellhilfe ist nicht mehr auffindbar.'
    );
}
$oeffnen = $treffer[1];
$assert(
    str_contains($oeffnen, 'document.body.appendChild(dialog)'),
    'Das Infofaehnchen verlaesst den Stapelkontext des Vordrucks nicht. '
        . 'Solange es darin steht, gewinnt jede Nachbarflaeche mit einem '
        . 'eigenen z-index gegen seine 1000 -- die Aktionsleiste zum '
        . 'Beispiel steht auf 30.'
);
$umzug = strpos($oeffnen, 'document.body.appendChild(dialog)');
$ausrichten = strpos($oeffnen, 'positionDialog(button, dialog)');
$assert(
    $umzug !== false && $ausrichten !== false && $umzug < $ausrichten,
    'Das Faehnchen wird ausgerichtet, bevor es umgezogen ist. Die '
        . 'Koordinaten stammen dann aus dem Stapelkontext des Vordrucks und '
        . 'werden im Dokument falsch gelesen.'
);

// Der Grund fuer den Umzug steht am Vordruck und darf nicht unbemerkt
// wegfallen -- dann waere der Umzug unerklaerlich.
$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$stylesheet = (string) $stylesheet;
$assert(
    str_contains($stylesheet, 'container-type: inline-size'),
    'Der Vordruck skaliert nicht mehr ueber einen Groessenbehaelter. Wenn '
        . 'der Stapelkontext damit verschwunden ist, kann das Faehnchen '
        . 'wieder an Ort und Stelle bleiben -- dann gehoert diese Pruefung '
        . 'geprueft, nicht angepasst.'
);

/*
 * Und im Ausdruck bleibt das Blatt weg. Es haengt jetzt am Dokument, nicht
 * mehr am Anker -- die Regel, die den Anker im Druck ausblendet, erreicht es
 * dort nicht mehr.
 */
$druck = '';
$stelle = 0;
while (($start = strpos($stylesheet, '@media print', $stelle)) !== false) {
    $tiefe = 0;
    for ($i = (int) strpos($stylesheet, '{', $start); $i < strlen($stylesheet); $i++) {
        $tiefe += $stylesheet[$i] === '{' ? 1 : 0;
        $tiefe -= $stylesheet[$i] === '}' ? 1 : 0;
        if ($tiefe === 0) {
            $druck .= substr($stylesheet, $start, $i - $start + 1);
            $stelle = $i + 1;
            break;
        }
    }
    if ($tiefe !== 0) {
        break;
    }
}
$assert(
    str_contains($druck, '.estab-official-help-dialog'),
    'Der Ausdruck blendet das Blatt der Ausfuellhilfe nicht eigens aus. Es '
        . 'haengt am Dokument statt am Anker; die Regel fuer den Anker '
        . 'erreicht es nicht mehr.'
);

printf("Infofaehnchen: OK (%d assertions)\n", $assertions);
