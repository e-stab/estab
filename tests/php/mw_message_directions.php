<?php

declare(strict_types=1);

/**
 * Wohin die drei Arten laufen -- und was ein Antrag beantworten muss.
 *
 * „Der Meldeweg führt immer von unten nach oben." Das ist keine Etikette:
 * Eine Meldung an eine nachgeordnete Stelle erreicht niemanden, der etwas
 * entscheiden könnte, und die Lage oben bleibt unvollständig. Umgekehrt
 * unterrichtet die Orientierung nach unten und zur Seite, und der Antrag
 * geht dorthin, wo über Mittel verfügt wird: nach oben oder zu einem
 * Nachbarn, der aushelfen kann.
 *
 * Die Anwendung hindert niemanden. Wer eine Nachricht nach unten schickt,
 * hat dafür im Zweifel einen Grund, den keine Maske kennt -- und im Einsatz
 * ist eine verweigerte Nachricht teurer als eine falsch benannte. Sie
 * erinnert.
 *
 * Der Antrag hat zusätzlich ein Schema. Ein Antrag, der nicht sagt, wie
 * viele wovon und wer sie fordert, erzeugt eine Rückfrage statt einer
 * Lieferung.
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

final class DirectionFixture
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
$visibleText = static function (string $markup): string {
    $text = preg_replace('~<[^>]*>~', ' ', $markup);
    $text = html_entity_decode(
        is_string($text) ? $text : '',
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $text = preg_replace('~\s+~u', ' ', $text);
    return is_string($text) ? $text : '';
};

$fixture = new DirectionFixture();
ob_start();
$fixture->official_message_text_guidance();
$line = $visibleText((string) ob_get_clean());
$help = (string) ($fixture->official_message_help_definitions()[14]['text'] ?? '');

/* --- Die Meldung läuft nach oben --- */

$assert(
    preg_match('~Meldung\b.{0,80}?\bnach oben~us', $line) === 1,
    estab_dv_requirement(
        'MW-MELDEWEG-RICHTUNG',
        'Die Merkhilfe sagt nicht, dass die Meldung nach oben läuft. Eine '
            . 'Meldung an eine nachgeordnete Stelle erreicht niemanden, der '
            . 'etwas entscheiden könnte.'
    )
);
$assert(
    preg_match('~Meldung\b.{0,200}?vorgesetzte~us', $help) === 1,
    estab_dv_requirement(
        'MW-MELDEWEG-RICHTUNG',
        'Die Ausfüllhilfe nennt nicht, an wen eine Meldung geht.'
    )
);

/*
 * Und die Anwendung hindert niemanden daran, eine Nachricht nach unten zu
 * schicken. Im Einsatz ist eine verweigerte Nachricht teurer als eine falsch
 * benannte, und welchen Grund jemand hat, weiss keine Maske.
 */
$handler = file_get_contents($root . '/4fach/data_hndl.php');
$workflow = file_get_contents($root . '/app/workflow.php');
if (!is_string($handler) || !is_string($workflow)) {
    throw new RuntimeException('Speicherstrecke oder Ablauf nicht lesbar.');
}
foreach (['meldeweg', 'nach oben', 'nachgeordnet'] as $needle) {
    foreach (
        ['data_hndl.php' => $handler, 'workflow.php' => $workflow] as $file => $source
    ) {
        $assert(
            !str_contains(mb_strtolower($source), $needle),
            estab_dv_requirement(
                'MW-MELDEWEG-RICHTUNG',
                $file . ' prüft die Richtung des Meldewegs („' . $needle
                    . '“). Die Anwendung soll erinnern, nicht hindern.'
            )
        );
    }
}

/* --- Die Orientierung läuft nach unten und zur Seite --- */

$assert(
    preg_match(
        '~Orientierung\b.{0,120}?nach\s+unten.{0,40}?(?:zur Seite|Seite)~us',
        $line
    ) === 1,
    estab_dv_requirement(
        'MW-ORIENTIERUNG-RICHTUNG',
        'Die Merkhilfe nennt nicht beide Richtungen der Orientierung: nach '
            . 'unten und zur Seite.'
    )
);
$assert(
    preg_match(
        '~Orientierung\b.{0,200}?nachgeordnete.{0,60}?gleichgestellte~us',
        $help
    ) === 1,
    estab_dv_requirement(
        'MW-ORIENTIERUNG-RICHTUNG',
        'Die Ausfüllhilfe nennt nicht, wen eine Orientierung unterrichtet.'
    )
);

/* --- Der Antrag geht nach oben oder zum Nachbarn --- */

$assert(
    preg_match('~Antrag\b.{0,120}?fordert~us', $line) === 1,
    estab_dv_requirement(
        'MW-ANTRAG-RICHTUNG',
        'Die Merkhilfe sagt nicht, was ein Antrag tut.'
    )
);
$assert(
    preg_match('~Antrag\b.{0,300}?(?:Nachbar|benachbart)~us', $help) === 1,
    estab_dv_requirement(
        'MW-ANTRAG-RICHTUNG',
        'Die Ausfüllhilfe nennt nicht, dass ein Antrag auch an einen '
            . 'Nachbarn gehen kann, der aushelfen könnte.'
    )
);

/* --- Und was er beantworten muss --- */

foreach (
    [
        'wo' => 'wo',
        'wann' => 'wann',
        'was' => 'was',
        'warum' => 'warum',
        'wie viele' => 'wie viele',
        'wie beschaffen' => 'wie beschaffen',
        'wer' => 'wer',
    ] as $question => $needle
) {
    $assert(
        str_contains(mb_strtolower($help), $needle),
        estab_dv_requirement(
            'MW-ANTRAG-SCHEMA',
            'Die Leitfrage „' . $question . '“ des Antrags fehlt. Ein '
                . 'Antrag, der sie unbeantwortet lässt, erzeugt eine '
                . 'Rückfrage statt einer Lieferung.'
        )
    );
}
/*
 * Die letzte Leitfrage ist die, die am ehesten fehlt: „wer" allein steht
 * schon in den fünf W der Meldung. Der Antrag verlangt darüber hinaus, wer
 * anfordert -- ohne das weiss die abgebende Stelle nicht, wem sie liefert.
 */
$assert(
    preg_match('~wer\s+anfordert~ui', $help) === 1,
    estab_dv_requirement(
        'MW-ANTRAG-SCHEMA',
        'Die Ausfüllhilfe sagt nicht, dass der Antrag benennen muss, wer '
            . 'anfordert.'
    )
);

/* --- Und keine der drei Richtungen wird erzwungen --- */

/*
 * Ein Verteiler, der Ziele nach Meldeart sperrt, wäre eine Sperre durch die
 * Hintertür. Der Verteiler kennt die Art nicht -- er kann sie nicht kennen,
 * weil die Anwendung sie nicht mitführt.
 */
$distribution = file_get_contents($root . '/app/workflow.php');
$assert(
    is_string($distribution)
        && !preg_match(
            '~estab_workflow_distribution[a-z_]*\([^)]*\)[^{]*\{'
                . '(?:[^{}]|\{[^{}]*\})*(?:meldeart|orientierung|antrag)~is',
            $distribution
        ),
    estab_dv_requirement(
        'MW-ORIENTIERUNG-RICHTUNG',
        'Der Verteiler sperrt Ziele nach der Art der Nachricht.'
    )
);

printf("Richtungen und Antragsschema: OK (%d assertions)\n", $assertions);
