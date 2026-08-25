<?php

declare(strict_types=1);

/**
 * The catalogue of recurring controls.
 *
 * The same buttons come back on every workflow step: print, one main action,
 * sometimes a return to the author, cancel, and a jump back to the top of the
 * form. If they sit in nine places across nine steps, the person in front of
 * the screen reads them anew every time -- and under load nobody reads, they
 * reach.
 *
 * The catalogue fixes what each role means, what it looks like and where it
 * sits. Order is expressed as a rank so a bar can leave a role out without
 * moving the others: leaving one out shifts nothing, because position comes
 * from the catalogue and not from the order in which a switch happens to emit.
 *
 * The ranks are deliberately spaced. A new role can be slotted between two
 * existing ones without renumbering, and renumbering is exactly how an order
 * that everyone relied on quietly changes.
 */

/**
 * @return array<string, array{zweck:string, klasse:string, rang:int}>
 */
function estab_ui_action_roles(): array
{
    return [
        'drucken' => [
            'zweck' => 'Den Vordruck zu Papier bringen. Steht überall und '
                . 'ändert nichts, deshalb zuerst.',
            'klasse' => 'estab-button-secondary',
            'rang' => 10,
        ],
        'nebenaktion' => [
            'zweck' => 'Ein Nebenweg, der die Nachricht nicht abschliesst: '
                . 'Anlagen, Antworten, Weiterleiten.',
            'klasse' => 'estab-button-secondary',
            'rang' => 20,
        ],
        'hauptaktion' => [
            'zweck' => 'Der Schritt, um dessentwillen die Seite offen ist. '
                . 'Genau einer je Leiste.',
            'klasse' => 'estab-button-primary',
            'rang' => 30,
        ],
        'rueckgabe' => [
            'zweck' => 'Die Nachricht geht an eine frühere Station zurück. '
                . 'Steht neben der Hauptaktion, weil beide den Vorgang '
                . 'weitergeben.',
            'klasse' => 'estab-button-danger',
            'rang' => 40,
        ],
        'abbrechen' => [
            'zweck' => 'Die Bearbeitung verlassen, ohne zu speichern. Liegt '
                . 'überall an derselben Stelle: hinter allem, was etwas '
                . 'bewirkt.',
            'klasse' => 'estab-button-ghost',
            'rang' => 50,
        ],
        'zurueck' => [
            'zweck' => 'Zurück an den Anfang des Vordrucks. Immer zuletzt.',
            'klasse' => 'estab-button-ghost',
            'rang' => 60,
        ],
        'hinweis' => [
            'zweck' => 'Kein Knopf, sondern eine Feststellung: Hier gibt es '
                . 'nichts zu tun.',
            'klasse' => 'estab-message-readonly-badge',
            'rang' => 70,
        ],
    ];
}

/**
 * Sort a list of actions into catalogue order.
 *
 * A stable sort keeps two actions of the same role in the order the caller
 * wrote them -- "Antworten" before "Weiterleiten" is a decision of the step,
 * not of the catalogue.
 *
 * @param list<array{role:string, markup:string}> $actions
 * @return list<array{role:string, markup:string}>
 */
function estab_ui_actions_in_order(array $actions): array
{
    $catalogue = estab_ui_action_roles();
    $ranked = [];
    foreach ($actions as $index => $action) {
        $role = $action['role'] ?? '';
        if (!isset($catalogue[$role])) {
            throw new InvalidArgumentException(
                'Unbekannte Rolle in der Aktionsleiste: ' . (string) $role
            );
        }
        $ranked[] = [$catalogue[$role]['rang'], $index, $action];
    }
    usort(
        $ranked,
        static fn (array $left, array $right): int =>
            $left[0] <=> $right[0] ?: $left[1] <=> $right[1]
    );
    return array_map(
        static fn (array $entry): array => $entry[2],
        $ranked
    );
}
