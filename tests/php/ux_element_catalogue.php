<?php

declare(strict_types=1);

/**
 * Gleiche Bedeutung, gleiches Element, gleiche Stelle.
 *
 * In der Aktionsleiste des Vordrucks kehren dieselben Knöpfe wieder:
 * Drucken, eine Hauptaktion, gelegentlich eine Rückgabe, Abbrechen, zurück
 * an den Anfang. Wer sie an neun Arbeitsschritten an neun Stellen findet,
 * liest jedes Mal neu -- und im Einsatz liest niemand neu, sondern greift.
 *
 * Der Bestand hatte genau hier einen Bruch: "Abbrechen" stand bei der
 * Sichtung hinter der Rückgabe, bei Beförderung und Disposition davor. Wer
 * gelernt hatte, dass Abbrechen gleich hinter der Hauptaktion liegt, traf
 * dort "Beförderung nicht möglich".
 *
 * Der Katalog legt Rolle, Element, Klasse und Reihenfolge fest. Er steht in
 * app/ui_elements.php, damit die Leiste ihn nicht jedes Mal neu erfindet.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once $root . '/app/ui_elements.php';

/*
 * Die Leiste zieht die Kategorienauswahl nach; die haengt an der
 * Laufzeitumgebung in 4fach und findet ihre Konfiguration nur von dort.
 */
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

final class ElementCatalogueFixture
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

/* --- Der Katalog selbst --- */

$catalogue = estab_ui_action_roles();
$assert(
    $catalogue !== [],
    estab_ux_requirement(
        'UX-ELEMENTKONSTANZ',
        'Der Katalog der wiederkehrenden Elemente ist leer.'
    )
);
$ranks = [];
foreach ($catalogue as $role => $definition) {
    foreach (['zweck', 'klasse', 'rang'] as $field) {
        $assert(
            isset($definition[$field]),
            estab_ux_requirement(
                'UX-ELEMENTKONSTANZ',
                'Die Rolle ' . $role . ' im Katalog hat kein ' . $field . '.'
            )
        );
    }
    $assert(
        !in_array($definition['rang'] ?? null, $ranks, true),
        estab_ux_requirement(
            'UX-ELEMENTKONSTANZ',
            'Zwei Rollen teilen sich den Rang '
                . var_export($definition['rang'] ?? null, true)
                . '; die Reihenfolge wäre dann nicht bestimmt.'
        )
    );
    $ranks[] = $definition['rang'] ?? null;
}

/* --- Und die Leiste hält ihn ein, an jedem Arbeitsschritt --- */

$tasks = [
    'Stab_schreiben', 'Stab_korrigieren', 'Stab_gesprnoti', 'Stab_lesen',
    'Stab_sichten', 'FM-Eingang', 'FM-Eingang_Anhang', 'FM-Ausgang',
    'LdF-Eingang', 'LdF-Ausgang', 'FM-Admin', 'SI-Admin',
];

/** Die Knöpfe einer Leiste, in der Reihenfolge der Ausgabe. */
$buttons = static function (string $markup): array {
    preg_match_all(
        '~<(button|a)\b([^>]*)>(.*?)</\1>~s',
        $markup,
        $found,
        PREG_SET_ORDER
    );
    $buttons = [];
    foreach ($found as [$whole, $element, $attributes, $label]) {
        preg_match('~class="([^"]*)"~', $attributes, $class);
        preg_match('~data-estab-action-role="([a-z-]+)"~', $attributes, $role);
        $buttons[] = [
            'element' => $element,
            'class' => $class[1] ?? '',
            'role' => $role[1] ?? null,
            'label' => trim(strip_tags($label)),
            'attributes' => $attributes,
        ];
    }
    return $buttons;
};

foreach ($tasks as $task) {
    foreach (['E', 'A'] as $direction) {
        foreach (['top', 'bottom'] as $position) {
            /*
             * Die obere Leiste des Lesens zieht die Kategorienauswahl nach,
             * und die spricht mit der Datenbank. Die Knöpfe dieser Leiste
             * sind dieselben wie unten; geprüft wird deshalb dort.
             */
            if ($task === 'Stab_lesen' && $position === 'top') {
                continue;
            }
            $fixture = new ElementCatalogueFixture();
            $fixture->task = $task;
            $fixture->formdata = [
                '04_richtung' => $direction,
                '12_anhang' => '',
            ];
            ob_start();
            $fixture->official_message_actions($position);
            $markup = (string) ob_get_clean();
            $where = $task . '/' . $direction . '/' . $position;
            $found = $buttons($markup);

            $seenRanks = [];
            foreach ($found as $button) {
                $assert(
                    $button['role'] !== null,
                    estab_ux_requirement(
                        'UX-ELEMENTKONSTANZ',
                        'Der Knopf „' . $button['label'] . '“ in ' . $where
                            . ' nennt seine Rolle nicht; er steht damit '
                            . 'ausserhalb des Katalogs.'
                    )
                );
                if ($button['role'] === null) {
                    continue;
                }
                $definition = $catalogue[$button['role']] ?? null;
                $assert(
                    $definition !== null,
                    estab_ux_requirement(
                        'UX-ELEMENTKONSTANZ',
                        'Die Rolle ' . $button['role'] . ' in ' . $where
                            . ' steht nicht im Katalog.'
                    )
                );
                if ($definition === null) {
                    continue;
                }
                $assert(
                    str_contains(
                        ' ' . $button['class'] . ' ',
                        ' ' . $definition['klasse'] . ' '
                    ),
                    estab_ux_requirement(
                        'UX-ELEMENTKONSTANZ',
                        'Der Knopf „' . $button['label'] . '“ in ' . $where
                            . ' trägt „' . $button['class'] . '“ statt „'
                            . $definition['klasse'] . '“; gleiche Bedeutung '
                            . 'heisst gleiches Aussehen.'
                    )
                );
                $seenRanks[] = [(int) $definition['rang'], $button['label']];
            }

            // Die Reihenfolge steigt: kein Element ueberholt ein anderes.
            $previous = null;
            foreach ($seenRanks as [$rank, $label]) {
                $assert(
                    $previous === null || $rank >= $previous[0],
                    estab_ux_requirement(
                        'UX-ELEMENTKONSTANZ',
                        'In ' . $where . ' steht „' . $label . '“ hinter „'
                            . ($previous[1] ?? '') . '“, obwohl der Katalog '
                            . 'die umgekehrte Reihenfolge festlegt.'
                    )
                );
                $previous = [$rank, $label];
            }

            // Genau eine Hauptaktion, ausser dort, wo es nichts zu tun gibt.
            $primaries = array_values(array_filter(
                $found,
                static fn (array $button): bool => $button['role'] === 'hauptaktion'
            ));
            $assert(
                count($primaries) <= 1,
                estab_ux_requirement(
                    'UX-ELEMENTKONSTANZ',
                    'In ' . $where . ' stehen ' . count($primaries)
                        . ' hervorgehobene Hauptaktionen; genau eine soll '
                        . 'der naheliegende Griff sein.'
                )
            );

            // Drucken steht immer, und immer zuerst.
            $assert(
                ($found[0]['role'] ?? null) === 'drucken',
                estab_ux_requirement(
                    'UX-ELEMENTKONSTANZ',
                    'In ' . $where . ' steht „'
                        . ($found[0]['label'] ?? '') . '“ an erster Stelle '
                        . 'statt Drucken.'
                )
            );
        }
    }
}

/* --- Abbrechen liegt überall an derselben Stelle --- */

/*
 * Das ist der Bruch, um den es geht. Geprüft wird nicht nur, dass die
 * Reihenfolge dem Katalog folgt, sondern dass Abbrechen relativ zu den
 * übrigen Knöpfen überall gleich liegt: hinter Hauptaktion und Rückgabe,
 * vor dem Sprung an den Anfang.
 */
$positionsOfCancel = [];
foreach ($tasks as $task) {
    foreach (['E', 'A'] as $direction) {
        $fixture = new ElementCatalogueFixture();
        $fixture->task = $task;
        $fixture->formdata = ['04_richtung' => $direction, '12_anhang' => ''];
        ob_start();
        $fixture->official_message_actions('bottom');
        $markup = (string) ob_get_clean();
        $roles = array_values(array_filter(array_map(
            static fn (array $button): ?string => $button['role'],
            $buttons($markup)
        )));
        $cancel = array_search('abbrechen', $roles, true);
        if ($cancel === false) {
            continue;
        }
        $after = array_slice($roles, $cancel + 1);
        $positionsOfCancel[$task . '/' . $direction] = $after;
        $assert(
            $after === ['zurueck'],
            estab_ux_requirement(
                'UX-ELEMENTKONSTANZ',
                'Nach Abbrechen folgt in ' . $task . '/' . $direction . ' '
                    . implode(', ', $after) . ' statt allein der Sprung an '
                    . 'den Anfang. Wer den Griff gelernt hat, trifft '
                    . 'woanders hin.'
            )
        );
    }
}
$assert(
    $positionsOfCancel !== [],
    estab_ux_requirement(
        'UX-ELEMENTKONSTANZ',
        'Kein Arbeitsschritt bietet Abbrechen an; die Prüfung ginge ins '
            . 'Leere.'
    )
);

printf("Katalog der Bedienelemente: OK (%d assertions)\n", $assertions);
