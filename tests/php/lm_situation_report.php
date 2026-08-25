<?php

declare(strict_types=1);

/**
 * Die Lagemeldung: acht Punkte, ein Vordruck, derselbe Laufweg.
 *
 * Eine Lagemeldung ist keine eigene Gattung, sondern eine Meldung, deren
 * Text acht Punkte abarbeitet: allgemeine Lage, Schaden- und Gefahrenlage,
 * eigene Lage, Lageentwicklung, Presse- und Öffentlichkeitsarbeit, besondere
 * Vorkommnisse, Anforderungen, Sonstiges.
 *
 * Die Reihenfolge ist der Punkt. Wer regelmäßig Lagemeldungen liest, liest
 * sie nicht von vorn bis hinten -- er springt zu dem Punkt, der ihn angeht.
 * Steht die eigene Lage einmal an dritter und einmal an sechster Stelle,
 * muss er jedes Mal suchen, und im Einsatz sucht niemand.
 *
 * Angaben gehören nur zu zutreffenden Punkten. Ein leerer Punkt im Text ist
 * keine Aussage, sondern eine Zeile, die der Leser überspringen muss.
 *
 * Und die Lagemeldung durchläuft denselben Laufweg wie jede andere
 * Nachricht. Eine Abkürzung für sie wäre ein zweiter Nachweis neben dem
 * ersten.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/message_status.php';

$previousDirectory = getcwd();
if (!is_string($previousDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    require_once $root . '/4fach/vali_data.php';
} finally {
    chdir($previousDirectory);
}
require_once $root . '/app/permission_mode.php';
require_once $root . '/4fach/official_message_form.php';

final class SituationReportFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,mixed> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [12 => true];

    /** @var list<array<string,mixed>> */
    public array $activeTelecomRoutes = [];

    public string $task = 'Stab_schreiben';

    public function safe_message_value(string $field): string
    {
        return estab_message_html($this->formdata[$field] ?? '');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixture = new SituationReportFixture();
$definition = $fixture->official_message_help_definitions()[14] ?? [];

/* --- Die acht Punkte, in dieser Reihenfolge --- */

$points = [
    'allgemeine Lage',
    'Schaden- und Gefahrenlage',
    'eigene Lage',
    'Lageentwicklung',
    'Presse- und Öffentlichkeitsarbeit',
    'besondere Vorkommnisse',
    'Anforderungen',
    'Sonstiges',
];

$list = $definition['liste'] ?? null;
$assert(
    is_array($list) && $list !== [],
    estab_dv_requirement(
        'LM-AUFBAU',
        'Die Ausfüllhilfe zu Feld 14 führt keine Liste. Acht Punkte in '
            . 'einem Fliesstext sind acht Punkte, die niemand zählt.'
    )
);
$found = is_array($list) ? $list : [];
$assert(
    count($found) === 8,
    estab_dv_requirement(
        'LM-AUFBAU',
        'Die Lagemeldung führt ' . count($found) . ' statt acht Punkte.'
    )
);
foreach ($points as $index => $point) {
    // Am Zeilenanfang steht der Punkt gross geschrieben; verglichen wird
    // die Sache, nicht die Schreibweise.
    $entry = (string) ($found[$index] ?? '');
    $assert(
        str_contains(mb_strtolower($entry), mb_strtolower($point)),
        estab_dv_requirement(
            'LM-AUFBAU',
            'An Stelle ' . ($index + 1) . ' steht „' . $entry . '“ statt „'
                . $point . '“. Wer eine Lagemeldung liest, springt zu dem '
                . 'Punkt, der ihn angeht; steht er woanders, muss er suchen.'
        )
    );
}

/* --- Nur Zutreffendes wird ausgefüllt --- */

$text = (string) ($definition['text'] ?? '');
$assert(
    preg_match('~(?:nur\s+zu\s+zutreffenden|nur\s+zutreffende|nicht\s+zutrifft)~ui', $text) === 1,
    estab_dv_requirement(
        'LM-AUFBAU',
        'Die Ausfüllhilfe sagt nicht, dass Angaben nur zu zutreffenden '
            . 'Punkten gehören. Ein leerer Punkt ist keine Aussage, sondern '
            . 'eine Zeile, die der Leser überspringen muss.'
    )
);
$assert(
    str_contains($text, 'Lagemeldung'),
    estab_dv_requirement(
        'LM-AUFBAU',
        'Die Ausfüllhilfe benennt die Lagemeldung nicht; die acht Punkte '
            . 'stünden ohne Anlass da.'
    )
);

/* --- Und sie erscheint auf dem Bildschirm --- */

ob_start();
$fixture->official_message_help(14);
$dialog = (string) ob_get_clean();
foreach ($points as $point) {
    $assert(
        str_contains(
            mb_strtolower($dialog),
            mb_strtolower(estab_message_html($point))
        ),
        estab_dv_requirement(
            'LM-AUFBAU',
            'Der Punkt „' . $point . '“ steht im Katalog, erscheint aber '
                . 'nicht in der geöffneten Ausfüllhilfe.'
        )
    );
}
$assert(
    substr_count($dialog, '<li') === 8,
    estab_dv_requirement(
        'LM-AUFBAU',
        'Die acht Punkte erscheinen nicht als Liste; ' . substr_count($dialog, '<li')
            . ' Einträge gefunden.'
    )
);

/* --- Derselbe Laufweg wie jede andere Nachricht --- */

/*
 * Kein eigener Stand, kein eigener Übergang, kein eigener Arbeitsschritt.
 * Eine Abkürzung für die Lagemeldung wäre ein zweiter Nachweis neben dem
 * ersten -- und im Einsatz eine zweite Sache, die man kennen muss.
 */
$transitions = estab_message_status_transitions();
$assert(
    array_keys($transitions) === ['incoming', 'outgoing', 'conversation-note'],
    estab_dv_requirement(
        'LM-MELDEWEG',
        'Der Laufweg kennt die Wege '
            . implode(', ', array_keys($transitions))
            . '. Die Lagemeldung braucht keinen eigenen.'
    )
);
$view = file_get_contents($root . '/4fach/official_message_form.php');
$workflow = file_get_contents($root . '/app/workflow.php');
if (!is_string($view) || !is_string($workflow)) {
    throw new RuntimeException('Vordruck oder Ablauf nicht lesbar.');
}
foreach (['lagemeldung', 'situation_report'] as $invented) {
    foreach (
        ['Vordruck' => $view, 'Ablaufsteuerung' => $workflow] as $where => $source
    ) {
        $assert(
            !preg_match(
                '~(?:name|id|value|task)\s*=?>?\s*[\'"][^\'"]*' . $invented . '~i',
                $source
            ),
            estab_dv_requirement(
                'LM-MELDEWEG',
                'Die ' . $where . ' führt einen eigenen Weg „' . $invented
                    . '“. Die Lagemeldung durchläuft denselben Laufweg wie '
                    . 'jede andere Nachricht.'
            )
        );
    }
}
$assert(
    str_contains($text, 'Laufweg') || str_contains($text, 'wie jede andere'),
    estab_dv_requirement(
        'LM-MELDEWEG',
        'Die Ausfüllhilfe sagt nicht, dass die Lagemeldung denselben Laufweg '
            . 'nimmt. Wer eine Abkürzung sucht, sucht sonst weiter.'
    )
);

/* --- Und wann sie ergeht --- */

/*
 * Eine Lagemeldung entsteht nicht von selbst. Sie ergeht auf Anforderung,
 * regelmässig auf Anordnung, bei umfassender Lageänderung, als
 * Lageinformation nach unten und als Lageorientierung zur Seite.
 *
 * Wer die Anlässe nicht kennt, meldet auf Zuruf -- und meldet damit zu
 * selten. Der häufigste Fall in einer Führungsstelle ist nicht die
 * angeforderte Lagemeldung, sondern die, die niemand angefordert hat, weil
 * oben niemand ahnt, dass sich unten etwas geändert hat.
 *
 * Die Anwendung führt den Anlass nicht mit. Wer eine Lagemeldung abfasst,
 * weiss, warum -- ein Pflichtfeld dafür wäre eine Angabe, die niemand liest.
 */
foreach (
    [
        'Anforderung' => 'auf Anforderung',
        'Anordnung' => 'regelmässig auf Anordnung',
        'Lageänderung' => 'bei umfassender Lageänderung',
        'Lageinformation' => 'als Lageinformation nach unten',
        'Lageorientierung' => 'als Lageorientierung zur Seite',
    ] as $needle => $what
) {
    $assert(
        str_contains($text, $needle),
        estab_dv_requirement(
            'LM-ANLASS',
            'Die Ausfüllhilfe nennt den Anlass „' . $what . '“ nicht. Wer '
                . 'die Anlässe nicht kennt, meldet auf Zuruf -- und damit zu '
                . 'selten.'
        )
    );
}

// Und der Anlass wird nicht mitgeführt: kein Feld, keine Spalte.
foreach (['lm_anlass', 'lagemeldung_anlass', 'meldeanlass'] as $invented) {
    $assert(
        !str_contains(mb_strtolower($view), $invented),
        estab_dv_requirement(
            'LM-ANLASS',
            'Der Vordruck führt ein Merkmal „' . $invented . '“. Wer eine '
                . 'Lagemeldung abfasst, weiss, warum.'
        )
    );
}

printf("Lagemeldung: OK (%d assertions)\n", $assertions);
