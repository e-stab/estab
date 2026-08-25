<?php

declare(strict_types=1);

/**
 * Die Sichtung im Ausgang ist eine Formprüfung.
 *
 * Die Dienstvorschrift beschränkt die Prüfung des Sichters bei ausgehenden
 * Nachrichten auf Anschrift, Unterschrift und Funktion und sagt ausdrücklich:
 * „eine inhaltliche Prüfung der Nachricht entfällt." Der Grund ist keine
 * Geringschätzung des Sichters, sondern eine Zuständigkeitsfrage: Was in
 * einer Meldung steht, verantwortet der Verfasser mit seinem Namenszeichen.
 * Prüfte der Sichter den Inhalt mit, entstünde eine zweite, unbenannte
 * Instanz zwischen Sachgebiet und Gegenstelle -- und im Zweifel eine
 * Verzögerung ohne Zuständigen.
 *
 * Die Anwendung sperrt deshalb nicht, sondern führt: Der Sichter kann
 * weiterhin aus jedem Grund zurückgeben -- eine Maske, die im Einsatz eine
 * begründete Rückgabe verweigert, wäre schlimmer als eine überschiessende --
 * aber er liest am Feld, worauf sich seine Prüfung erstreckt.
 *
 * Was die Anwendung schon vorher tat und was hier festgehalten wird: Sie
 * lässt eine ausgehende Nachricht nur durch, wenn genau diese drei Felder
 * ausgefüllt sind, und verlangt für eine Rückgabe einen Vermerk.
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

final class FormalReviewFixture
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

    public string $task = 'Stab_sichten';

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

/* --- Der Sichter liest am Feld, worauf sich seine Prüfung erstreckt --- */

$outgoing = new FormalReviewFixture();
$outgoing->formdata = ['04_richtung' => 'A'];
$scope = $visibleText($render(static function () use ($outgoing): void {
    $outgoing->official_message_review_scope();
}));

foreach (
    ['Anschrift' => 'Feld 10', 'Absender' => 'Feld 15',
        'Zeichen' => 'Feld 17', 'Funktion' => 'Feld 17'] as $what => $field
) {
    $assert(
        str_contains($scope, $what),
        estab_dv_requirement(
            'FUEST-SICHTER-AUSGANG-FORMAL',
            'Die Sichtung nennt „' . $what . '“ (' . $field . ') nicht als '
                . 'Gegenstand ihrer Prüfung.'
        )
    );
}
/*
 * Das Wort "inhaltlich" allein genügt nicht: "Eine inhaltliche Prüfung ist
 * Sache des Sichters" enthält es auch und sagt das Gegenteil. Verlangt wird
 * die Aussage der Dienstvorschrift -- dass die inhaltliche Prüfung entfällt.
 */
$assert(
    preg_match(
        '~inhaltliche\s+Prüfung\b[^.]{0,40}\bentfällt~ui',
        $scope
    ) === 1,
    estab_dv_requirement(
        'FUEST-SICHTER-AUSGANG-FORMAL',
        'Die Sichtung sagt nicht, dass die inhaltliche Prüfung entfällt. '
            . 'Ohne diesen Satz prüft der Sichter im Zweifel doch den '
            . 'Inhalt. Gelesen wurde: „' . $scope . '“'
    )
);
$assert(
    str_contains($scope, 'Verfasser'),
    estab_dv_requirement(
        'FUEST-SICHTER-AUSGANG-FORMAL',
        'Die Sichtung sagt nicht, wer für den Inhalt einsteht.'
    )
);

/*
 * Und sie steht nur im Ausgang. Im Eingang prüft der Sichter den Inhalt
 * sehr wohl -- er verteilt die Nachricht an die zuständigen Sachgebiete,
 * und das geht nur inhaltlich.
 */
foreach (['E', ''] as $direction) {
    $incoming = new FormalReviewFixture();
    $incoming->formdata = ['04_richtung' => $direction];
    $incomingScope = $visibleText($render(static function () use ($incoming): void {
        $incoming->official_message_review_scope();
    }));
    $assert(
        trim($incomingScope) === '',
        estab_dv_requirement(
            'FUEST-SICHTER-AUSGANG-FORMAL',
            'Die Beschränkung auf die Form erscheint auch bei Richtung „'
                . $direction . '“; im Eingang verteilt der Sichter nach '
                . 'Inhalt.'
        )
    );
}

// Und nur beim Sichten: Andere Stationen prüfen nach ihren eigenen Regeln.
foreach (['Stab_schreiben', 'LdF-Ausgang', 'FM-Ausgang'] as $task) {
    $other = new FormalReviewFixture();
    $other->task = $task;
    $other->formdata = ['04_richtung' => 'A'];
    $otherScope = $visibleText($render(static function () use ($other): void {
        $other->official_message_review_scope();
    }));
    $assert(
        trim($otherScope) === '',
        estab_dv_requirement(
            'FUEST-SICHTER-AUSGANG-FORMAL',
            'Der Arbeitsschritt ' . $task . ' zeigt die Beschränkung der '
                . 'Sichtung, obwohl dort nicht gesichtet wird.'
        )
    );
}

/* --- Zurückweisen kann er weiterhin, aber nicht wortlos --- */

$handler = file_get_contents($root . '/4fach/data_hndl.php');
if (!is_string($handler)) {
    throw new RuntimeException('Die Speicherstrecke ist nicht lesbar.');
}

/*
 * Die Vollständigkeitsprüfung im Ausgang umfasst genau die drei formalen
 * Felder -- nicht Betreff, nicht Nachrichtentext. Käme ein inhaltliches Feld
 * hinzu, prüfte der Sichter den Inhalt durch die Hintertür.
 */
preg_match(
    '~\$formalOutgoingComplete\s*=(.*?);~s',
    $handler,
    $completeness
);
$assert(
    isset($completeness[1]),
    estab_dv_requirement(
        'FUEST-SICHTER-AUSGANG-FORMAL',
        'Die Vollständigkeitsprüfung der formalen Sichtung ist nicht mehr '
            . 'auffindbar.'
    )
);
preg_match_all(
    '~"([0-9]{2}_[a-z]+)"~',
    $completeness[1] ?? '',
    $checked
);
$expected = ['10_anschrift', '13_abseinheit', '14_zeichen', '14_funktion'];
$found = $checked[1];
sort($found, SORT_STRING);
$sortedExpected = $expected;
sort($sortedExpected, SORT_STRING);
$assert(
    $found === $sortedExpected,
    estab_dv_requirement(
        'FUEST-SICHTER-AUSGANG-FORMAL',
        'Die formale Sichtung prüft ' . implode(', ', $found) . ' statt '
            . implode(', ', $sortedExpected)
            . '. Anschrift, Absender, Verfasserzeichen und Funktion sind '
            . 'die Form; alles andere ist Inhalt.'
    )
);

// Eine Rückgabe ohne Vermerk wird zurückgewiesen: Der Verfasser muss
// erfahren, was zu ändern ist.
$assert(
    str_contains(
        $handler,
        '$isFormalReturn && trim ((string) $data ["17_vermerke"]) === ""'
    ),
    estab_dv_requirement(
        'FUEST-SICHTER-AUSGANG-FORMAL',
        'Eine formale Rückgabe ist ohne Vermerk möglich; der Verfasser '
            . 'erführe nicht, was zu ändern ist.'
    )
);

// Und sie bleibt möglich -- die Anwendung sperrt keinen Grund aus.
$assert(
    !preg_match(
        '~zurueckweisen[^;]*(?:preg_match|in_array)\s*\([^;]*grund~i',
        $handler
    ),
    estab_dv_requirement(
        'FUEST-SICHTER-AUSGANG-FORMAL',
        'Die Anwendung prüft den Wortlaut des Rückweisungsgrunds. Eine '
            . 'Maske, die im Einsatz eine begründete Rückgabe verweigert, '
            . 'ist schlimmer als eine überschiessende.'
    )
);

printf("Formale Sichtung im Ausgang: OK (%d assertions)\n", $assertions);
