<?php

declare(strict_types=1);

/**
 * Drei Anlässe, bei denen niemand auf eine Aufforderung wartet.
 *
 * Die Grundlagen des Meldewesens nennen drei Sachverhalte, die sofort und
 * ohne Aufforderung zu melden sind: Gefahrstoffe und Gefahrgüter, der
 * Abschluss des Auftrages und jede Abweichung vom Auftrag.
 *
 * Der gemeinsame Nenner ist, dass die Führung ohne diese Meldung ein
 * falsches Bild hat und danach entscheidet. Wer einen Gefahrstoff findet und
 * auf die nächste Lagemeldung wartet, lässt andere in eine Gefahr laufen,
 * von der er weiss. Wer einen Auftrag beendet und nichts sagt, bindet
 * Kräfte, die längst frei wären. Wer vom Auftrag abweicht und nichts sagt,
 * arbeitet an einer Lage, die es nur noch auf der Karte gibt.
 *
 * Die Anwendung belegt dafür keine Vorrangstufe vor. Welche Stufe angemessen
 * ist, hängt vom Fall ab, und eine Vorbelegung, die meistens falsch ist,
 * wird weggeklickt statt gelesen. Sie nennt die drei Anlässe und verweist
 * auf das Feld, in dem die Stufe steht.
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
require_once $root . '/app/message_priority.php';
require_once $root . '/4fach/official_message_form.php';

final class ImmediateReportFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,mixed> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [12 => true, 9 => true];

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

$fixture = new ImmediateReportFixture();
$help = $fixture->official_message_help_definitions();

/* --- Die drei Anlässe stehen in der Ausfüllhilfe --- */

/*
 * Sie stehen bei Feld 14, wo die Meldung geschrieben wird -- nicht bei der
 * Vorrangstufe. Wer die Stufe wählt, hat den Anlass längst; wer den Text
 * schreibt, muss überhaupt erst wissen, dass er ihn melden muss.
 */
$text = (string) ($help[14]['text'] ?? '');
foreach (
    [
        'Gefahrstoff' => 'Gefahrstoffe und Gefahrgüter',
        'Gefahrgüter' => 'Gefahrgüter',
        'Abschluss' => 'der Abschluss des Auftrages',
        'Abweichung' => 'jede Abweichung vom Auftrag',
    ] as $needle => $what
) {
    $assert(
        str_contains($text, $needle),
        estab_dv_requirement(
            'MW-SOFORTMELDUNG',
            'Die Ausfüllhilfe nennt ' . $what . ' nicht als Anlass, der '
                . 'ohne Aufforderung zu melden ist.'
        )
    );
}
$assert(
    preg_match('~ohne\s+Aufforderung~ui', $text) === 1,
    estab_dv_requirement(
        'MW-SOFORTMELDUNG',
        'Die Ausfüllhilfe sagt nicht, dass diese Anlässe ohne Aufforderung '
            . 'zu melden sind. Ohne diesen Satz warten alle auf die nächste '
            . 'Lagemeldung.'
    )
);
$assert(
    preg_match('~(?:sofort|unverzüglich)~ui', $text) === 1,
    estab_dv_requirement(
        'MW-SOFORTMELDUNG',
        'Die Ausfüllhilfe sagt nicht, dass sofort zu melden ist.'
    )
);

/* --- Und sie verweist auf die Vorrangstufe --- */

$assert(
    preg_match('~Vorrangstufe|Feld 9~ui', $text) === 1,
    estab_dv_requirement(
        'MW-SOFORTMELDUNG',
        'Die Ausfüllhilfe verweist nicht auf die Vorrangstufe. Eine '
            . 'Sofortmeldung ohne Vorrang läuft im Stapel mit.'
    )
);

/* --- Vorbelegt wird nichts --- */

/*
 * Welche Stufe angemessen ist, hängt vom Fall ab. Eine Vorbelegung, die
 * meistens falsch ist, wird weggeklickt statt gelesen -- und dann steht sie
 * auch dort, wo sie nicht hingehört.
 */
ob_start();
$fixture->official_message_priority();
$priority = (string) ob_get_clean();
preg_match_all(
    '~<input[^>]*id="f_09_vorrangstufe_([a-z]+)"[^>]*>~',
    $priority,
    $options,
    PREG_SET_ORDER
);
$checked = [];
foreach ($options as [$element, $id]) {
    if (str_contains($element, 'checked')) {
        $checked[] = $id;
    }
}
$assert(
    // Hier stand ['keine'] -- so drückte der Vordruck „keine Vorrangstufe"
    // damals aus: durch ein eigenes, angekreuztes Kästchen. Der amtliche
    // Vordruck hat kein solches Kästchen; die Aussage ist die Abwesenheit
    // eines Kreuzes. Geprüft wird unverändert dasselbe: Es ist nichts
    // vorbelegt.
    $checked === [],
    estab_dv_requirement(
        'MW-SOFORTMELDUNG',
        'Ein neuer Vordruck belegt die Vorrangstufe mit '
            . implode(', ', $checked) . ' vor. Welche Stufe angemessen ist, '
            . 'entscheidet der Verfasser.'
    )
);

// Und die Stufen bleiben, was der Vordruck kennt.
$assert(
    estab_message_priority_document_label('') === ''
        && estab_message_priority_is_urgent('') === false,
    estab_dv_requirement(
        'MW-SOFORTMELDUNG',
        'Eine Nachricht ohne Vorrangstufe gilt als dringend.'
    )
);
foreach (['sss', 'bbb', 'aaa'] as $urgent) {
    $assert(
        estab_message_priority_is_urgent($urgent),
        estab_dv_requirement(
            'MW-SOFORTMELDUNG',
            'Die Vorrangstufe ' . $urgent . ' unterbricht den Stapel nicht; '
                . 'eine Sofortmeldung liefe darin mit.'
        )
    );
}

/*
 * Und die Anwendung erfindet keinen eigenen Weg für die drei Anlässe. Ein
 * zweiter Weg neben dem Nachrichtenvordruck wäre ein zweiter Nachweis --
 * und im Einsatz eine zweite Sache, die man kennen muss.
 */
$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
foreach (['sofortmeldung', 'gefahrstoffmeldung', 'schnellmeldung'] as $invented) {
    $assert(
        !preg_match(
            '~(?:name|id|value)="[^"]*' . $invented . '~i',
            $view
        ),
        estab_dv_requirement(
            'MW-SOFORTMELDUNG',
            'Der Vordruck führt einen eigenen Weg „' . $invented . '“. Die '
                . 'drei Anlässe werden auf demselben Vordruck gemeldet wie '
                . 'alles andere.'
        )
    );
}

printf("Sofort und ohne Aufforderung: OK (%d assertions)\n", $assertions);
