<?php

declare(strict_types=1);

/**
 * Die Migrationsgrenze: wo im Stylesheet noch Zahlen stehen dürfen.
 *
 * Eine Prüfung, die „kein Farbliteral außerhalb von :root" verlangt, kann erst
 * grün werden, wenn der letzte Bereich umgestellt ist. Bis dahin prüfte
 * nichts -- und genau in dieser Zeit kämen neue Abweichungen dazu.
 *
 * Deshalb ist es umgekehrt: Die Wächter sind von Anfang an scharf, und diese
 * Liste sagt, was sie noch durchgehen lassen. Jede Aufgabe des Umsetzungsplans
 * streicht ihre Präfixe; die letzte entfernt diese Datei.
 *
 * Ein Bereich, der künftig dazukommt, steht nicht in der Liste und wird damit
 * sofort geprüft. Das ist der eigentliche Zweck: Nicht am Ende feststellen,
 * dass alles passt, sondern von Tag eins verhindern, dass es auseinanderläuft.
 *
 * Siehe tasks/gestaltung-plan.md Abschnitt 2.3.
 */

/**
 * Auswählerpräfixe, die noch nicht auf die Marken umgestellt sind.
 *
 * Beim Anlegen deckt die Liste den gesamten Bestand ab. Sie ist die
 * Ausgangslage, nicht das Ziel.
 *
 * @return list<string>
 */
function estab_test_migrationsgrenze(): array
{
    return [
        // P2 -- Musterseite 1: Hülle und Vordruck
        '.estab-shell',
        '.estab-navigation',
        '.estab-sidebar',
        '.estab-session-bar',
        '.estab-cockpit',
        '.estab-menu-card',
        '.estab-root',
        '.estab-message-page-header',
        '.estab-message-actionbar',
        '.estab-message-workflow',
        '.estab-message-form',
        '.estab-message-confirmation',
        '.estab-message-error-summary',
        '.estab-official',

        // P3 -- Musterseite 2: die übernommene Liste
        '.estab-legacy-page',
        '.estab-list-',

        // P4 -- der Rest
        '.estab-message-list',
        '.estab-message-timeline',
        '.estab-message-attachment',
        '.estab-message-categories',
        '.estab-message-suggestion',
        '.estab-message-transport',
        '.estab-message-readonly',
        '.estab-message-recipient',
        '.estab-message-sidebar',
        '.estab-message-workspace',
        '.estab-message-mapping',
        '.estab-mobile-sidebar',
        '.estab-tool-',
        '.estab-admin-',
        '.estab-export-',
        '.estab-telecom-',
        '.estab-incident-',
        '.estab-bos-',
        '.estab-email-',
        '.estab-auth-',
        '.estab-login',
        '.estab-password-policy',
        '.estab-button',
        '.estab-visually-hidden',
        '.estab-actions-page',
        '.estab-assignment',
        '.estab-readiness',
        '.estab-situation',
        '.estab-logbook',
        '.estab-handbook',
        '.estab-guidance',
        '.estab-print',
        '.estab-skip',
    ];
}

/**
 * Trägt dieser Auswähler noch die Ausnahme?
 *
 * Geprüft wird auf Präfix, nicht auf Gleichheit: `.estab-tool-` deckt
 * `.estab-tool-panel` und `.estab-tool-field` mit ab, ohne dass jede
 * Ausprägung einzeln aufgezählt werden muss.
 */
function estab_test_in_migrationsgrenze(string $auswaehler): bool
{
    foreach (estab_test_migrationsgrenze() as $praefix) {
        if (str_contains($auswaehler, $praefix)) {
            return true;
        }
    }
    return false;
}
