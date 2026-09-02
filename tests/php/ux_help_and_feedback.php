<?php

declare(strict_types=1);

/**
 * Ausfüllhilfen und Rückmeldung: die zwei Fragen des Bedienenden.
 *
 * Vor dem Eintragen fragt jemand: Was gehört in dieses Feld? Nach dem
 * Absenden: Ist es weg, und wohin? Bleiben beide Fragen offen, bleibt die
 * Anwendung eine Zumutung, egal wie richtig sie rechnet.
 *
 * Die Hilfen sagen, was einzutragen ist -- nicht, wie man ein Bedienelement
 * benutzt. Wer bereits weiss, wie man tippt, lernt aus "Klicken Sie hier"
 * nichts; wer den Vordruck nicht kennt, ebenso wenig.
 *
 * Die Rückmeldung nennt drei Dinge: was geschehen ist, wohin die Nachricht
 * gegangen ist, und was jetzt zu tun ist. Fehlt das dritte, steht der
 * Bedienende vor einem leeren Bildschirm und weiss nur, dass irgendetwas
 * geklappt hat.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/session_ui.php';

if (!function_exists('estab_message_html')) {
    function estab_message_html(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}
require_once $root . '/app/permission_mode.php';
require_once $root . '/4fach/official_message_form.php';

final class HelpAndFeedbackFixture
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

/* --- Zu jedem Feld eine Hilfe, und jede sagt etwas über die Sache --- */

$definitions = (new HelpAndFeedbackFixture())
    ->official_message_help_definitions();

$assert(
    array_keys($definitions) === range(1, 20),
    estab_ux_requirement(
        'UX-INFOPOINTER',
        'Die Ausfüllhilfen decken nicht die Felder 1 bis 20 ab: '
            . implode(', ', array_map('strval', array_keys($definitions)))
    )
);

foreach ($definitions as $number => $definition) {
    foreach (['title', 'text'] as $part) {
        $assert(
            isset($definition[$part]) && is_string($definition[$part])
                && trim($definition[$part]) !== '',
            estab_ux_requirement(
                'UX-INFOPOINTER',
                'Die Hilfe zu Feld ' . $number . ' hat keinen ' . $part . '.'
            )
        );
    }
    // Ein Satz, kein Stichwort: Wer nachschlägt, braucht eine Aussage.
    $assert(
        mb_strlen($definition['text']) >= 40
            && str_contains($definition['text'], ' '),
        estab_ux_requirement(
            'UX-INFOPOINTER',
            'Die Hilfe zu Feld ' . $number . ' sagt in „'
                . $definition['text'] . '“ nicht, was einzutragen ist.'
        )
    );
}

/*
 * Und keine Hilfe erklärt das Bedienelement statt der Sache. "Klicken Sie
 * auf das Feld" hilft niemandem: Wer nicht weiss, was hineingehört, weiss es
 * danach immer noch nicht.
 */
$aboutTheWidget = [
    'klicken sie auf das feld',
    'klicken sie hier',
    'mit der maus',
    'drücken sie die eingabetaste',
    'scrollen sie',
    'schaltfläche unten',
];
foreach ($definitions as $number => $definition) {
    $text = mb_strtolower($definition['title'] . ' ' . $definition['text']);
    foreach ($aboutTheWidget as $phrase) {
        $assert(
            !str_contains($text, $phrase),
            estab_ux_requirement(
                'UX-INFOPOINTER',
                'Die Hilfe zu Feld ' . $number . ' erklärt mit „' . $phrase
                    . '“ die Bedienung statt den Inhalt des Feldes.'
            )
        );
    }
}

// Die Hilfe ist abrufbar, nicht nur vorhanden: Jedes Feld trägt sie am Feld.
$source = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($source)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$renderStart = (int) strpos($source, 'function plot_official_message_form()');
$render = substr($source, $renderStart);
preg_match_all(
    '~\$this->official_message_help\((\d+)\)'
        . '|official_message_timestamp_block\(\s*\'[^\']*\',\s*(\d+),~',
    $render,
    $anchors,
    PREG_SET_ORDER
);
$anchored = [];
foreach ($anchors as $anchor) {
    $anchored[] = (int) ($anchor[1] !== '' ? $anchor[1] : $anchor[2]);
}
sort($anchored, SORT_NUMERIC);
$assert(
    $anchored === range(1, 20),
    estab_ux_requirement(
        'UX-INFOPOINTER',
        'Am Vordruck hängen die Hilfen '
            . implode(', ', array_map('strval', $anchored))
            . ' statt aller zwanzig; die übrigen wären nur im Handbuch '
            . 'zu finden.'
    )
);

/* --- Nach jeder Handlung: was geschah, wohin, was nun --- */

/** Jeder Abschluss eines Arbeitsschritts, samt Umständen. */
$completions = [
    'Verfasser setzt ab' => ['Stab_schreiben', [], 'A', 'S3', ''],
    'Verfasser korrigiert' => ['Stab_korrigieren', [], 'A', 'S3', ''],
    'Gesprächsnotiz abgesetzt' => ['Stab_gesprnoti', [], 'E', 'S3', 't'],
    'Eingang aufgenommen' => ['FM-Eingang', [], 'E', 'A/W', ''],
    'Eingang mit Anlage aufgenommen'
        => ['FM-Eingang_Anhang', [], 'E', 'A/W', ''],
    'Eingang angenommen' => ['LdF-Eingang', [], 'E', 'LdF', ''],
    'Beförderungsweg festgelegt' => ['LdF-Ausgang', [], 'A', 'LdF', ''],
    'Ausgang vom LdF zurückgegeben'
        => ['LdF-Ausgang', ['ldf_zurueckweisen_x' => '1'], 'A', 'LdF', ''],
    'Nachricht befördert' => ['FM-Ausgang', [], 'A', 'A/W', ''],
    'Beförderung nicht möglich'
        => ['FM-Ausgang', ['transport_nicht_moeglich_x' => '1'], 'A', 'A/W', ''],
    'Eingang gesichtet' => ['Stab_sichten', [], 'E', 'Si', ''],
    'Ausgang gesichtet' => ['Stab_sichten', [], 'A', 'Si', ''],
    'Ausgang zurückgewiesen'
        => ['Stab_sichten', ['zurueckweisen_x' => '1'], 'A', 'Si', ''],
    'Gesprächsnotiz gesichtet' => ['Stab_sichten', [], 'E', 'Si', 't'],
];

foreach ($completions as $what => [$task, $request, $direction, $function, $kind]) {
    $outcome = estab_session_ui_message_outcome(
        $task,
        $request,
        $direction,
        $function,
        $kind
    );
    $assert(
        is_array($outcome),
        estab_ux_requirement(
            'UX-RUECKMELDUNG',
            'Der Abschluss „' . $what . '“ bleibt unbeantwortet.'
        )
    );
    if (!is_array($outcome)) {
        continue;
    }
    foreach (['title', 'destination', 'detail'] as $part) {
        $assert(
            isset($outcome[$part]) && is_string($outcome[$part])
                && trim($outcome[$part]) !== '',
            estab_ux_requirement(
                'UX-RUECKMELDUNG',
                'Die Rückmeldung zu „' . $what . '“ nennt kein ' . $part . '.'
            )
        );
    }
    $actions = $outcome['actions'] ?? [];
    $assert(
        is_array($actions) && $actions !== [],
        estab_ux_requirement(
            'UX-RUECKMELDUNG',
            'Nach „' . $what . '“ steht kein nächster Schritt bereit; der '
                . 'Bedienende bleibt vor einem fertigen Bildschirm stehen.'
        )
    );
    $primary = 0;
    foreach (is_array($actions) ? $actions : [] as $action) {
        $assert(
            isset($action['label']) && is_string($action['label'])
                && trim($action['label']) !== '',
            estab_ux_requirement(
                'UX-RUECKMELDUNG',
                'Ein nächster Schritt nach „' . $what . '“ ist unbenannt.'
            )
        );
        $primary += ($action['primary'] ?? false) === true ? 1 : 0;
    }
    $assert(
        $primary === 1,
        estab_ux_requirement(
            'UX-RUECKMELDUNG',
            'Nach „' . $what . '“ sind ' . $primary . ' Schritte '
                . 'hervorgehoben; genau einer soll der naheliegende sein.'
        )
    );
}

// Ein Arbeitsschritt, der nichts abschliesst, meldet auch nichts.
$assert(
    estab_session_ui_message_outcome('Stab_lesen', []) === null,
    estab_ux_requirement(
        'UX-RUECKMELDUNG',
        'Das blosse Lesen erzeugt eine Erfolgsmeldung und entwertet damit '
            . 'die Meldungen, die etwas bedeuten.'
    )
);

/*
 * Und die Rückmeldung wird auch angezeigt, statt in einem Rahmenwechsel zu
 * verschwinden. Die Schaltflächen des nächsten Schritts brauchen eine
 * Sitzung, weil sie ohne CSRF-Marke abgewiesen würden; die Aussage selbst
 * steht auch ohne sie.
 */
$outcome = estab_session_ui_message_outcome('Stab_schreiben', [], 'A', 'S3', '');
$statementOnly = estab_session_ui_message_confirmation_markup(
    $outcome ?? [],
    '/4fach/mainindex.php'
);
foreach (['Nachrichtenvordruck abgesetzt', 'An Sichter übergeben'] as $spoken) {
    $assert(
        str_contains($statementOnly, estab_auth_html($spoken)),
        estab_ux_requirement(
            'UX-RUECKMELDUNG',
            'Die angezeigte Bestätigung sagt „' . $spoken . '“ nicht.'
        )
    );
}
$assert(
    str_contains($statementOnly, 'role="status"')
        && str_contains($statementOnly, 'aria-live="polite"'),
    estab_ux_requirement(
        'UX-RUECKMELDUNG',
        'Die Bestätigung wird nicht angesagt; wer den Bildschirm nicht '
            . 'sieht, erfährt nichts.'
    )
);

session_save_path(sys_get_temp_dir());
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$withControls = estab_session_ui_message_confirmation_markup(
    $outcome ?? [],
    '/4fach/mainindex.php'
);
$assert(
    str_contains($withControls, estab_auth_html('Nächste Meldung schreiben')),
    estab_ux_requirement(
        'UX-RUECKMELDUNG',
        'Der nächste Schritt steht in der Bestätigung nicht zum Anfassen '
            . 'bereit.'
    )
);
$assert(
    str_contains($withControls, 'autofocus'),
    estab_ux_requirement(
        'UX-RUECKMELDUNG',
        'Der naheliegende nächste Schritt liegt nicht unter der Hand; er '
            . 'müsste erst gesucht werden.'
    )
);

printf("Ausfüllhilfen und Rückmeldung: OK (%d assertions)\n", $assertions);
