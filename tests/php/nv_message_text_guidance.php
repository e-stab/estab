<?php

declare(strict_types=1);

/**
 * Was im Nachrichtentext stehen muss, und was er unterscheidbar halten muss.
 *
 * Eine Meldung, die nicht sagt wo, wann, was, wie und wer, zwingt die
 * Führungsstelle zur Rückfrage -- und eine Rückfrage kostet im Einsatz mehr
 * Zeit als die Meldung selbst. Der gedruckte Vordruck trägt diese Leitfragen
 * deshalb als Merke am Textfeld. Die Maske muss sie ebenso tragen, und zwar
 * sichtbar: Wer sie erst in einer Hilfe suchen muss, sucht sie nicht.
 *
 * Die zweite Anforderung ist folgenschwerer. Eine Lage entsteht aus
 * Meldungen, und eine Vermutung, die als Feststellung ankommt, führt zu
 * Entscheidungen über eine Lage, die es nicht gibt. Der Vordruck muss
 * deshalb erkennbar machen, was der Verfasser selbst festgestellt hat, was
 * ihm berichtet wurde und was er vermutet.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

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

final class MessageTextFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,mixed> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [];

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
$render = static function (callable $callback): string {
    ob_start();
    try {
        $callback();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
};

/** Der sichtbare Text einer Ausgabe, ohne Auszeichnung und ohne Skripte. */
$visibleText = static function (string $markup): string {
    $markup = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $markup)
        ?? $markup;
    $text = preg_replace('~<[^>]*>~', ' ', $markup);
    $text = html_entity_decode(
        is_string($text) ? $text : '',
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $text = preg_replace('~\s+~u', ' ', $text);
    return is_string($text) ? $text : '';
};

$writing = new MessageTextFixture();
$writing->feld = [12 => true];
$textMarkup = $render(static function () use ($writing): void {
    $writing->official_message_text_guidance();
});
$textVisible = $visibleText($textMarkup);

/* --- Die fünf Leitfragen stehen am Feld --- */

foreach (['Wo', 'Wann', 'Was', 'Wie', 'Wer'] as $question) {
    $assert(
        preg_match('~\b' . $question . '\b~u', $textVisible) === 1,
        estab_dv_requirement(
            'NV-14-5W',
            'Die Leitfrage „' . $question . '“ steht nicht am Textfeld. Eine '
                . 'Meldung, die sie unbeantwortet lässt, erzwingt eine '
                . 'Rückfrage.'
        )
    );
}

// In der Reihenfolge des Vordrucks, damit sie sich abarbeiten lassen.
$positions = [];
foreach (['Wo', 'Wann', 'Was', 'Wie', 'Wer'] as $question) {
    $positions[$question] = (int) mb_strpos($textVisible, $question);
}
$ordered = $positions;
asort($ordered);
$assert(
    array_keys($ordered) === ['Wo', 'Wann', 'Was', 'Wie', 'Wer'],
    estab_dv_requirement(
        'NV-14-5W',
        'Die Leitfragen stehen in der Reihenfolge '
            . implode(', ', array_keys($ordered))
            . ' statt Wo, Wann, Was, Wie, Wer.'
    )
);

// Und sie werden vorgelesen, statt nur gedruckt zu werden.
$assert(
    !str_contains($textMarkup, 'aria-hidden="true"'),
    estab_dv_requirement(
        'NV-14-5W',
        'Die Leitfragen sind für Vorleseprogramme ausgeblendet.'
    )
);

/* --- Feststellung, Bericht und Vermutung bleiben unterscheidbar --- */

foreach (['festgestellt', 'gemeldet', 'vermutet'] as $kind) {
    $assert(
        str_contains(mb_strtolower($textVisible), $kind),
        estab_dv_requirement(
            'NV-14-TATSACHE-VERMUTUNG',
            'Der Hinweis am Textfeld unterscheidet „' . $kind . '“ nicht. '
                . 'Eine Vermutung, die als Feststellung ankommt, führt zu '
                . 'Entscheidungen über eine Lage, die es nicht gibt.'
        )
    );
}

/* --- Der Hinweis gehört an das Feld, nicht an die Nachricht --- */

/*
 * Er darf nicht in den Nachrichtentext geraten: Was der Verfasser abschickt,
 * hat er geschrieben. Ein von der Anwendung vorgesetzter Text stünde später
 * als seine Aussage im Nachweis.
 */
$assert(
    !str_contains($textMarkup, 'name="12_inhalt"')
        || !preg_match(
            '~<textarea[^>]*name="12_inhalt"[^>]*>\s*\S~',
            $textMarkup
        ),
    estab_dv_requirement(
        'NV-14-TATSACHE-VERMUTUNG',
        'Der Hinweis steht im Nachrichtentext und würde als Aussage des '
            . 'Verfassers abgesetzt.'
    )
);

/*
 * Und er verschwindet, sobald niemand mehr schreibt: Ein gesichteter
 * Vordruck zeigt, was gemeldet wurde, nicht mehr, wie man meldet.
 */
foreach (['Stab_sichten', 'Stab_lesen', 'FM-Ausgang'] as $task) {
    $reading = new MessageTextFixture();
    $reading->task = $task;
    $reading->feld = [12 => false];
    $readingMarkup = $render(static function () use ($reading): void {
        $reading->official_message_text_guidance();
    });
    $assert(
        trim($visibleText($readingMarkup)) === '',
        estab_dv_requirement(
            'NV-14-5W',
            'Der Arbeitsschritt ' . $task . ' zeigt die Ausfüllhinweise, '
                . 'obwohl dort niemand schreibt.'
        )
    );
}

/* --- Und der Hinweis steht im Vordruck am Feld 14 --- */

$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$renderSource = substr(
    $view,
    (int) strpos($view, 'function plot_official_message_form()')
);
$section = (int) strpos($renderSource, 'estab-official-message-text');
$number = (int) strpos($renderSource, 'estab-official-print-number">14<');
$guidanceCall = strpos($renderSource, 'official_message_text_guidance()');
$assert(
    $guidanceCall !== false && $guidanceCall > $section
        && $guidanceCall < $number,
    estab_dv_requirement(
        'NV-14-5W',
        'Die Leitfragen stehen nicht am Feld 14 des Vordrucks.'
    )
);

printf("Leitfragen und Tatsachenkennzeichnung: OK (%d assertions)\n", $assertions);
