<?php

declare(strict_types=1);

/**
 * Der Katalog der Übermittlungsmittel -- und was der Vordruck davon ankreuzt.
 *
 * Die Grundlagen des Meldewesens zählen mehr Mittel auf, als der amtliche
 * Vordruck Kästchen hat: neben Funk, Telefon, Telefax, Datenübertragung und
 * Melder auch Internet, E-Mail und Messenger. Das ist kein Widerspruch. Alle
 * drei sind Datenübertragung; der Vordruck hat dafür genau ein Kästchen, und
 * welcher Weg es im Einzelnen war, gehört in den Beförderungsweg.
 *
 * Ein eigenes Kästchen für „Messenger" wäre eine Fälschung des amtlichen
 * Rasters, und ein eigener Speicherwert wäre eine Schemaänderung für eine
 * Unterscheidung, die der Vordruck nicht trifft. Was bleibt, ist die
 * Aufgabe, die das Dokument wirklich stellt: Der Bedienende soll wissen,
 * welche Mittel es gibt und wo er sie einträgt.
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
require_once $root . '/app/message_transport.php';
require_once $root . '/4fach/official_message_form.php';

final class CatalogueFixture
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

$fixture = new CatalogueFixture();
$help = $fixture->official_message_help_definitions();

/* --- Die Ausfüllhilfe nennt alle Mittel des Katalogs --- */

/*
 * Benannt wird mit den Wörtern, die der Vordruck druckt: Er hat fünf
 * Kästchen -- Funk, Telefon, Telefax, DFÜ und Kurier/Melder -- und der
 * Fernschreiber teilt sich das der Datenübertragung. Eine Hilfe, die andere
 * Wörter benutzt als das Raster daneben, hilft nicht.
 */
$catalogue = [
    'Funk', 'Telefon', 'Telefax', 'DFÜ', 'Melder',
    'Internet', 'E-Mail', 'Messenger',
];
foreach ([1 => 'tatsächlich verwendetes', 7 => 'gewünschtes'] as $number => $what) {
    $text = (string) ($help[$number]['text'] ?? '');
    foreach ($catalogue as $medium) {
        $assert(
            str_contains($text, $medium),
            estab_dv_requirement(
                'TKM-KATALOG',
                'Die Hilfe zum Feld ' . $number . ' (' . $what
                    . ' Übermittlungsmittel) nennt „' . $medium
                    . '“ nicht. Wer das Mittel im Katalog nicht findet, '
                    . 'trägt irgendetwas ein.'
            )
        );
    }
}

/*
 * Und sie sagt, wo die drei Wege landen, für die der Vordruck kein Kästchen
 * hat: im Kästchen der Datenübertragung, und genau benannt im
 * Beförderungsweg. Ohne diesen Satz bleibt die Aufzählung eine Aufzählung.
 */
foreach ([1, 7] as $number) {
    $text = (string) ($help[$number]['text'] ?? '');
    $assert(
        str_contains($text, 'Beförderungsweg')
            || str_contains($text, 'Feld 6'),
        estab_dv_requirement(
            'TKM-KATALOG',
            'Die Hilfe zum Feld ' . $number . ' sagt nicht, wo der genaue '
                . 'Weg einzutragen ist.'
        )
    );
}

/* --- Der Vordruck kreuzt nur an, was er hat --- */

$boxes = $fixture->official_message_medium_options('01_medium');
$labels = array_column($boxes, 'label');
$assert(
    count($boxes) === 5,
    estab_dv_requirement(
        'TKM-KATALOG',
        'Der Vordruck bietet ' . count($boxes) . ' Kästchen für das '
            . 'Übermittlungsmittel an; der amtliche Vordruck hat fünf.'
    )
);
$assert(
    $labels === ['Funk', 'Telefon', 'Telefax', 'DFÜ', 'Kurier/Melder'],
    estab_dv_requirement(
        'TKM-KATALOG',
        'Die Kästchen heissen ' . implode(', ', $labels)
            . ' statt Funk, Telefon, Telefax, DFÜ, Kurier/Melder.'
    )
);
foreach (['Internet', 'E-Mail', 'Messenger'] as $absent) {
    $assert(
        !in_array($absent, $labels, true),
        estab_dv_requirement(
            'TKM-KATALOG',
            'Der Vordruck kreuzt „' . $absent . '“ als eigenes Mittel an. '
                . 'Der amtliche Vordruck hat dafür kein Kästchen; ein '
                . 'ausgedruckter Vordruck wäre damit gefälscht.'
        )
    );
}

// Und die Speicherwerte bleiben, was der Vordruck kennt.
foreach (['Internet', 'E-Mail', 'Messenger', 'WhatsApp'] as $unknown) {
    $assert(
        estab_message_medium_storage_value($unknown) === null,
        estab_dv_requirement(
            'TKM-KATALOG',
            'Der Wert „' . $unknown . '“ wird gespeichert, obwohl der '
                . 'Vordruck ihn nicht kennt.'
        )
    );
}
foreach (
    ['Fu' => 'Funk', 'Fe' => 'Fernsprecher', 'Me' => 'Melder',
        'FAX' => 'Fax', 'FS' => 'Fernschreiber',
        '@' => 'Datenübertragung'] as $value => $label
) {
    $assert(
        estab_message_medium_storage_value($value) === $value
            && estab_message_medium_text($value) === $label,
        estab_dv_requirement(
            'TKM-KATALOG',
            'Das Mittel ' . $value . ' des Vordrucks wird nicht mehr als '
                . $label . ' geführt.'
        )
    );
}

/* --- Der Weg selbst lässt sich genau benennen --- */

/*
 * Feld 6 ist frei beschreibbar und lang genug für eine Angabe wie
 * "E-Mail an S6, Betreff Lagemeldung 14". Wäre es eine Auswahl, liesse sich
 * der Weg nicht benennen, und die Aufzählung in der Hilfe ginge ins Leere.
 */
$view = file_get_contents($root . '/4fach/official_message_form.php');
if (!is_string($view)) {
    throw new RuntimeException('Die Ansicht des Vordrucks ist nicht lesbar.');
}
$assert(
    preg_match(
        "~official_message_text_input\\(\\s*\\n\\s*'06_befweg',"
            . "\\s*\\n\\s*true,\\s*\\n\\s*(\\d+),~",
        $view,
        $length
    ) === 1 && (int) $length[1] >= 64,
    estab_dv_requirement(
        'TKM-KATALOG',
        'Der Beförderungsweg ist kein freies Feld oder zu kurz, um einen '
            . 'Weg zu benennen, den der Vordruck nicht ankreuzt.'
    )
);

$guidance = $fixture->official_message_field_guidance();
$assert(
    isset($guidance['06_befweg'])
        && trim((string) $guidance['06_befweg']['label']) !== '',
    estab_dv_requirement(
        'TKM-KATALOG',
        'Der Beförderungsweg trägt keine Beschriftung.'
    )
);

printf("Katalog der Übermittlungsmittel: OK (%d assertions)\n", $assertions);
