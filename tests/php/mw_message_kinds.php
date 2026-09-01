<?php

declare(strict_types=1);

/**
 * Die drei Arten einer Nachricht -- als Merkhilfe, nicht als Merkmal.
 *
 * Die Grundlagen des Meldewesens unterscheiden Meldung, Orientierung und
 * Antrag. Der Unterschied liegt nicht in der Form -- alle drei laufen auf
 * demselben Vordruck -- sondern in dem, was sie tun: Eine Meldung berichtet
 * nach oben, eine Orientierung unterrichtet nach unten oder zur Seite, ein
 * Antrag fordert etwas an.
 *
 * Wer den Unterschied kennt, schreibt anders: Eine Meldung, die eigentlich
 * ein Antrag ist, bleibt unbeantwortet, weil niemand darin eine Forderung
 * erkennt. Deshalb steht die Unterscheidung am Nachrichtentext.
 *
 * Sie steht dort als Merkhilfe. Die Anwendung führt die Art nicht mit: Sie
 * bildet sich im Text ab, wo sie hingehört, und ein zusätzliches Merkmal
 * wäre eine Angabe, die jemand pflegen muss, ohne dass ein Nachweis davon
 * abhängt.
 *
 * Und sie steht nicht nur dort, wo geschrieben wird. Fernmeldezentrale und
 * Leiter des Fernmeldebetriebes lesen sie als passives Prüforgan: Wer weiss,
 * wie eine vollständige Meldung aussieht, stellt die richtige Rückfrage,
 * bevor die Nachricht hinausgeht.
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

final class MessageKindFixture
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
/** Die Merkhilfe, wie ein Arbeitsschritt sie sieht. */
$guidanceFor = static function (string $task, bool $writes) use ($visibleText): string {
    $fixture = new MessageKindFixture();
    $fixture->task = $task;
    $fixture->feld = $writes ? [12 => true] : [];
    ob_start();
    try {
        $fixture->official_message_text_guidance();
        return $visibleText((string) ob_get_contents());
    } finally {
        ob_end_clean();
    }
};

/* --- Die drei Arten stehen am Nachrichtentext --- */

$writing = $guidanceFor('Stab_schreiben', true);
foreach (['Meldung', 'Orientierung', 'Antrag'] as $kind) {
    $assert(
        str_contains($writing, $kind),
        estab_dv_requirement(
            'MW-MELDEART',
            'Die Merkhilfe am Nachrichtentext nennt die Art „' . $kind
                . '“ nicht. Eine Meldung, die eigentlich ein Antrag ist, '
                . 'bleibt unbeantwortet.'
        )
    );
}

/*
 * Und sie sagt, was die drei unterscheidet. Drei Wörter nebeneinander sind
 * eine Aufzählung, keine Hilfe.
 */
$assert(
    mb_strlen($writing) >= 120,
    estab_dv_requirement(
        'MW-MELDEART',
        'Die Merkhilfe zählt die Arten auf, ohne den Unterschied zu nennen: „'
            . $writing . '“'
    )
);

/* --- Die Art wird nicht mitgeführt --- */

/*
 * Kein Feld, keine Spalte, kein Speicherwert. Die Art bildet sich im Text
 * ab; ein zusätzliches Merkmal wäre eine Angabe, die jemand pflegen muss,
 * ohne dass ein Nachweis davon abhängt.
 */
$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
foreach (['meldeart', 'nachrichtenart', 'message_kind'] as $invented) {
    $assert(
        !str_contains(mb_strtolower($view), $invented),
        estab_dv_requirement(
            'MW-MELDEART',
            'Der Vordruck führt ein Merkmal „' . $invented . '“. Die Art '
                . 'steht im Text, wo sie hingehört.'
        )
    );
}
foreach (glob($root . '/docker/db/migrations/*.sql') ?: [] as $migration) {
    $number = (int) basename($migration);
    $assert(
        $number <= 131,
        estab_dv_requirement(
            'MW-MELDEART',
            'Es ist eine neue Migration hinzugekommen: '
                . basename($migration) . '. Die Grundlagen des Meldewesens '
                . 'erzeugen keine Schemaänderung.'
        )
    );
}

/* --- Sichtbar auch für das passive Prüforgan --- */

foreach (
    [
        'FM-Eingang' => true,
        'LdF-Eingang' => false,
        'LdF-Ausgang' => false,
        'FM-Ausgang' => false,
    ] as $task => $writes
) {
    $seen = $guidanceFor($task, $writes);
    $assert(
        str_contains($seen, 'Meldung') && str_contains($seen, 'Antrag'),
        estab_dv_requirement(
            'MW-MELDEART',
            'Der Arbeitsschritt ' . $task . ' sieht die Merkhilfe nicht. '
                . 'Fernmeldezentrale und LdF lesen sie als passives '
                . 'Prüforgan: Wer weiss, wie eine vollständige Meldung '
                . 'aussieht, stellt die richtige Rückfrage.'
        )
    );
}

/*
 * Und sie verschwindet dort, wo weder geschrieben noch befördert wird. Ein
 * abgeschlossener Nachweis zeigt, was gemeldet wurde, nicht mehr, wie man
 * meldet.
 */
foreach (['Stab_lesen', 'FM-Admin', 'SI-Admin'] as $task) {
    $assert(
        trim($guidanceFor($task, false)) === '',
        estab_dv_requirement(
            'MW-MELDEART',
            'Der Arbeitsschritt ' . $task . ' zeigt die Merkhilfe, obwohl '
                . 'dort weder geschrieben noch befördert wird.'
        )
    );
}

/* --- Und die Ausfüllhilfe erklärt die drei ausführlich --- */

$help = (new MessageKindFixture())->official_message_help_definitions();
$text = (string) ($help[14]['text'] ?? '');
foreach (
    ['Meldung' => 'berichtet', 'Orientierung' => 'unterrichtet',
        'Antrag' => 'fordert'] as $kind => $verb
) {
    $assert(
        str_contains($text, $kind),
        estab_dv_requirement(
            'MW-MELDEART',
            'Die Ausfüllhilfe zu Feld 14 nennt die Art „' . $kind
                . '“ nicht.'
        )
    );
    $assert(
        preg_match(
            '~' . preg_quote($kind, '~') . '\b.{0,120}?' . $verb . '~us',
            $text
        ) === 1,
        estab_dv_requirement(
            'MW-MELDEART',
            'Die Ausfüllhilfe sagt nicht, dass eine ' . $kind . ' '
                . $verb . '. Ohne das Verb bleibt die Aufzählung leer.'
        )
    );
}

printf("Die drei Meldearten: OK (%d assertions)\n", $assertions);
