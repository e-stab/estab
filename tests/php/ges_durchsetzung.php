<?php

declare(strict_types=1);

/**
 * `!important` sagt, wie stark eine Erklaerung gilt -- und das ist selten
 * noetig.
 *
 * Der Bestand trug 53 solcher Erklaerungen. Jede von ihnen setzt die Ordnung
 * der Stylesheets ausser Kraft, und wer sie einmal benutzt, braucht sie beim
 * naechsten Mal wieder, um die erste zu schlagen. Am Ende gilt nicht mehr,
 * was zuletzt geschrieben steht, sondern wer lauter gerufen hat.
 *
 * Vier Faelle sind zulaessig, und alle vier haben denselben Grund: Dort
 * reicht die normale Ordnung nicht.
 *
 *   1. Der Druckblock. Er muss den Bildschirmstil schlagen.
 *   2. `prefers-reduced-motion`. Eine Bewegung abzuschalten muss jede Regel
 *      schlagen, die sie einschaltet.
 *   3. `forced-colors`. Was das Betriebssystem setzt, darf nichts uebermalen.
 *   4. Fremdes Markup. Ein Inline-Stil, ein Praesentationsattribut wie
 *      `align` oder `bgcolor`, ein eingebettetes Dokument oder ein `[hidden]`
 *      gegen ein `display: flex` -- das schlaegt keine Regel ohne
 *      `!important`, weil ein Inline-Stil in der Ordnung ueber jeder Regel
 *      steht.
 *
 * Alles andere ist ein Befund.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/stylesheet.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');

/* Auswaehler, die auf fremdes Markup zeigen. Sie stehen namentlich hier,
   damit die Ausnahme eine Liste ist und keine Gewohnheit. */
$fremdesMarkup = '~(\[hidden\]|style\*=|estab-legacy-page|estab-bos-embedded'
    . '|estab-bos-document-content|estab-bos-welcome-hint'
    . '|estab-tool-table-number|estab-tool-table-responsive'
    . '|estab-message-list-table td|estab-message-attachment-list a'
    . '|estab-message-form-body)~';

$offen = [];
$zulaessig = 0;
foreach (estab_test_css_regeln((string) $stylesheet) as $regel) {
    foreach ($regel['deklarationen'] as $erklaerung) {
        if (!str_contains(strtolower($erklaerung['wert']), '!important')) {
            continue;
        }
        $imBlock = preg_match(
            '~print|reduced-motion|forced-colors~',
            $regel['kontext']
        ) === 1;
        $gegenFremdes = preg_match($fremdesMarkup, $regel['auswaehler']) === 1;
        if ($imBlock || $gegenFremdes) {
            $zulaessig++;
            continue;
        }
        $offen[] = $regel['auswaehler'] . ' { ' . $erklaerung['eigenschaft']
            . ' } Zeile ' . $regel['zeile'];
    }
}
$assert(
    $offen === [],
    estab_ux_requirement(
        'GES-DURCHSETZUNG',
        'Diese Erklaerungen rufen lauter, ohne dass die Ordnung dafuer zu '
            . 'schwach waere: ' . implode(' | ', array_slice($offen, 0, 6))
    )
);

// Beisst die Pruefung? Eine erfundene Regel ausserhalb der vier Faelle muss
// auffallen -- sonst waere ihre Ruhe kein Beweis.
$probe = '.estab-probe-ohne-grund { color: red !important; }';
$probeOffen = 0;
foreach (estab_test_css_regeln($probe) as $regel) {
    foreach ($regel['deklarationen'] as $erklaerung) {
        if (!str_contains(strtolower($erklaerung['wert']), '!important')) {
            continue;
        }
        if (preg_match($fremdesMarkup, $regel['auswaehler']) !== 1) {
            $probeOffen++;
        }
    }
}
$assert(
    $probeOffen === 1,
    estab_ux_requirement(
        'GES-DURCHSETZUNG',
        'Die Pruefung findet ein erfundenes !important nicht wieder.'
    )
);

printf(
    "Gestaltung Durchsetzung: OK (%d assertions, %d zulaessige !important)\n",
    $assertions,
    $zulaessig
);
