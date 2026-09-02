<?php

declare(strict_types=1);

/**
 * Die Merkhilfe steht am Bildschirm, nicht auf dem Papier.
 *
 * Der Vordruck trägt inzwischen mehrere Zeilen, die auf dem amtlichen
 * Papierformular nicht stehen: die geforderte Bezeichnungsart unter Anschrift
 * und Absender, die Leitfragen am Nachrichtentext, die Marke am Pflichtfeld,
 * der Umfang der Sichtung am Vermerkfeld. Alle helfen beim Ausfüllen -- und
 * alle haben auf einem Ausdruck nichts zu suchen.
 *
 * Der Grund ist derselbe wie beim erfundenen Ankreuzfeld: Ein ausgedruckter
 * Vordruck ist ein Nachweis. Wer ihn neben ein Papierformular legt und
 * Unterschiede findet, muss sich fragen, welches der beiden gilt. Die
 * Merkhilfe ist eine Hilfe beim Ausfüllen, kein Bestandteil des Nachweises.
 *
 * Diese Prüfung ist die Gegenprobe zu den Aufgaben, die die Zeilen
 * eingeführt haben. Sie führt eine Liste, damit eine neue Zeile ohne
 * Druckregel auffällt, statt still mitgedruckt zu werden.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($stylesheet) || !is_string($view)) {
    throw new RuntimeException('Stylesheet oder Vordruck nicht lesbar.');
}

/** Die Blöcke, die nur für den Druck gelten. */
$printBlocks = '';
$offset = 0;
while (($start = strpos($stylesheet, '@media print', $offset)) !== false) {
    $depth = 0;
    $index = (int) strpos($stylesheet, '{', $start);
    for ($cursor = $index; $cursor < strlen($stylesheet); $cursor++) {
        $depth += $stylesheet[$cursor] === '{' ? 1 : 0;
        $depth -= $stylesheet[$cursor] === '}' ? 1 : 0;
        if ($depth === 0) {
            $printBlocks .= substr($stylesheet, $start, $cursor - $start + 1);
            $offset = $cursor + 1;
            break;
        }
    }
    if ($depth !== 0) {
        break;
    }
}
$assert(
    $printBlocks !== '',
    estab_ux_requirement(
        'UX-PAPIERBILD',
        'Das Stylesheet kennt keine Regeln für den Druck.'
    )
);

/*
 * Die Liste der Zeilen, die der Vordruck zusätzlich trägt. Sie steht hier
 * und nicht im Anwendungscode: Der Test soll merken, wenn eine dazukommt.
 */
$guidance = [
    'estab-official-designation-hint'
        => 'die Bezeichnungsart unter Anschrift und Absender',
    'estab-official-text-guidance'
        => 'die Leitfragen am Nachrichtentext',
    'estab-official-required-mark'
        => 'die Marke am Pflichtfeld',
    'estab-official-review-scope'
        => 'der Umfang der Sichtung am Vermerkfeld',
];

foreach ($guidance as $class => $what) {
    // Sie ist wirklich vorhanden -- sonst prüfte die Liste ein Gespenst.
    $assert(
        str_contains($view, $class),
        estab_ux_requirement(
            'UX-PAPIERBILD',
            'Der Vordruck setzt „' . $what . '“ nicht mehr; die Liste der '
                . 'zu unterdrückenden Zeilen ist veraltet.'
        )
    );
    // Und sie wird nicht gedruckt.
    $assert(
        preg_match(
            '~\.' . preg_quote($class, '~') . '\b[^{}]*\{[^}]*'
                . 'display:\s*none~s',
            $printBlocks
        ) === 1,
        estab_ux_requirement(
            'UX-PAPIERBILD',
            'Auf dem Ausdruck erscheint ' . $what . '. Das Papierformular '
                . 'trägt diese Zeile nicht -- wer beide nebeneinanderlegt, '
                . 'muss sich fragen, welches gilt.'
        )
    );
}

/*
 * Und der Vordruck trägt keine weitere Merkhilfe, die niemand in die Liste
 * eingetragen hat.
 *
 * Damit das prüfbar bleibt, tragen Merkhilfen eine erkennbare Namensform:
 * „-hint", „-guidance" oder „-scope". Wer eine neue anlegt, kommt an dieser
 * Prüfung nicht vorbei. Die Marke am Pflichtfeld heisst aus gewachsenen
 * Gründen anders und steht deshalb namentlich in der Liste oben --
 * „estab-official-stamp-mark" dagegen ist ein Kasten des Papierformulars
 * und keine Merkhilfe.
 */
preg_match_all(
    '~estab-official-(?:[a-z]+-)*(?:hint|guidance|scope)\b~',
    $view,
    $found
);
foreach (array_unique($found[0]) as $class) {
    $assert(
        isset($guidance[$class])
            || preg_match(
                '~\.' . preg_quote($class, '~') . '\b[^{}]*\{[^}]*display:\s*none~s',
                $printBlocks
            ) === 1,
        estab_ux_requirement(
            'UX-PAPIERBILD',
            'Der Vordruck trägt die Zeile „' . $class . '“, die weder in '
                . 'der Liste steht noch vom Druck ausgenommen ist.'
        )
    );
}

/*
 * Umgekehrt bleibt alles stehen, was das Papierformular selbst trägt. Eine
 * Druckregel, die zu viel ausblendet, macht aus dem Nachweis einen
 * Lückentext.
 */
foreach (
    ['estab-official-print-number', 'estab-official-zone',
        'estab-official-readonly', 'estab-official-cell-heading'] as $paper
) {
    $assert(
        preg_match(
            '~\.' . preg_quote($paper, '~') . '\b[^{}]*\{[^}]*display:\s*none~s',
            $printBlocks
        ) !== 1,
        estab_ux_requirement(
            'UX-PAPIERBILD',
            'Der Ausdruck blendet „' . $paper . '“ aus; das gehört zum '
                . 'Papierformular.'
        )
    );
}

printf("Merkhilfe nur am Bildschirm: OK (%d assertions)\n", $assertions);
