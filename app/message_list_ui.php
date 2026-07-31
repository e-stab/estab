<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/message_priority.php';
require_once __DIR__ . '/message_repository.php';

/** Human-readable workflow state without relying on colour alone. */
function estab_message_list_status_label(mixed $status): string
{
    $parsed = filter_var($status, FILTER_VALIDATE_INT);
    return match ($parsed) {
        0 => 'Entwurf',
        1 => 'Bei LdF',
        2 => 'In Beförderung',
        4 => 'In Sichtung',
        8 => 'Abgeschlossen',
        10 => 'Zur Korrektur',
        default => 'Unbekannter Stand',
    };
}

function estab_message_list_status_class(mixed $status): string
{
    $parsed = filter_var($status, FILTER_VALIDATE_INT);
    return match ($parsed) {
        8 => 'estab-message-list-status--done',
        10 => 'estab-message-list-status--returned',
        1, 2, 4 => 'estab-message-list-status--active',
        default => 'estab-message-list-status--neutral',
    };
}

function estab_message_list_direction_label(mixed $direction): string
{
    return match ((string) $direction) {
        'E' => 'Eingang',
        'A' => 'Ausgang',
        default => 'Ohne Richtung',
    };
}

function estab_message_list_datetime_label(mixed $value): string
{
    if (estab_datetime_is_unset($value) || !is_string($value)) {
        return 'Noch keine Abfassungszeit';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y · H:i');
    } catch (Throwable) {
        return 'Ungültige Abfassungszeit';
    }
}

/** @return array<string,string> */
function estab_message_list_filter_labels(array $filters): array
{
    $labels = [];
    if (($filters['q'] ?? '') !== '') {
        $labels['q'] = 'Suche: „' . (string) $filters['q'] . '“';
    }
    if (($filters['direction'] ?? '') !== '') {
        $labels['direction'] = 'Richtung: '
            . estab_message_list_direction_label($filters['direction']);
    }
    if (($filters['priority'] ?? '') !== '') {
        $labels['priority'] = 'Vorrang: ' . match ($filters['priority']) {
            'none' => 'keiner',
            default => estab_message_priority_label($filters['priority']),
        };
    }
    if (($filters['status'] ?? '') !== '') {
        $statusMap = [
            'draft' => 0,
            'ldf' => 1,
            'transport' => 2,
            'review' => 4,
            'done' => 8,
            'returned' => 10,
        ];
        $labels['status'] = 'Stand: '
            . estab_message_list_status_label($statusMap[$filters['status']] ?? null);
    }
    if (($filters['from'] ?? '') !== '') {
        $labels['from'] = 'Von: '
            . (new DateTimeImmutable((string) $filters['from']))->format('d.m.Y');
    }
    if (($filters['to'] ?? '') !== '') {
        $labels['to'] = 'Bis: '
            . (new DateTimeImmutable((string) $filters['to']))->format('d.m.Y');
    }
    if (($filters['recipient'] ?? '') !== '') {
        $labels['recipient'] = 'Empfänger: ' . (string) $filters['recipient'];
    }
    return $labels;
}

/** @return array<string,string> */
function estab_message_list_sort_options(): array
{
    return [
        'priority_newest' => 'Vorrang zuerst, dann neueste',
        'newest' => 'Neueste zuerst',
        'oldest' => 'Älteste zuerst',
        'number_desc' => 'Höchste Nachweisnummer zuerst',
        'number_asc' => 'Niedrigste Nachweisnummer zuerst',
    ];
}

/** @return array<string,string> */
function estab_message_list_status_options(): array
{
    return [
        '' => 'Alle Bearbeitungsstände',
        'draft' => 'Entwurf',
        'ldf' => 'Bei LdF',
        'transport' => 'In Beförderung',
        'review' => 'In Sichtung',
        'done' => 'Abgeschlossen',
        'returned' => 'Zur Korrektur',
    ];
}

function estab_message_list_select_options(
    array $options,
    string $selected
): string {
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . estab_auth_html((string) $value) . '"'
            . (hash_equals((string) $value, $selected) ? ' selected' : '')
            . '>' . estab_auth_html((string) $label) . '</option>';
    }
    return $html;
}

/**
 * Render the shared, always-visible search and filter surface.
 *
 * @param list<string> $recipients
 * @param array<string,string> $hidden
 */
function estab_message_list_render_controls(
    array $filters,
    array $recipients,
    array $options
): void {
    $action = (string) ($options['action'] ?? '');
    $method = strtolower((string) ($options['method'] ?? 'get'));
    if (!in_array($method, ['get', 'post'], true)) {
        throw new InvalidArgumentException('Ungültige Listenformularmethode');
    }
    $target = (string) ($options['target'] ?? '_self');
    if (!in_array($target, ['_self', 'mainframe'], true)) {
        throw new InvalidArgumentException('Ungültiges Listenformularziel');
    }
    $domPrefix = (string) ($options['dom_prefix'] ?? 'message-list');
    if (preg_match('/\A[a-z][a-z0-9-]{0,39}\z/D', $domPrefix) !== 1) {
        throw new InvalidArgumentException('Ungültiger Listen-DOM-Präfix');
    }
    $hidden = $options['hidden'] ?? [];
    if (!is_array($hidden)) {
        throw new InvalidArgumentException('Ungültige versteckte Listenfelder');
    }
    $csrfHtml = (string) ($options['csrf_html'] ?? '');
    $labels = estab_message_list_filter_labels($filters);
    $advancedOpen = ($filters['from'] ?? '') !== ''
        || ($filters['to'] ?? '') !== ''
        || ($filters['recipient'] ?? '') !== ''
        || ($filters['sort'] ?? 'priority_newest') !== 'priority_newest'
        || (int) ($filters['page_size'] ?? 50) !== 50;
    $helpId = $domPrefix . '-query-help';

    echo '<section class="estab-message-list-controls" '
        . 'data-estab-message-list-controls>';
    echo '<form class="estab-message-list-search-form" role="search" method="'
        . $method . '" action="' . estab_auth_html($action) . '" target="'
        . estab_auth_html($target) . '">';
    echo $csrfHtml;
    foreach ($hidden as $name => $value) {
        if (
            !is_string($name)
            || preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', $name) !== 1
            || (!is_string($value) && !is_int($value))
        ) {
            throw new InvalidArgumentException('Ungültiges verstecktes Listenfeld');
        }
        echo '<input type="hidden" name="' . estab_auth_html($name)
            . '" value="' . estab_auth_html((string) $value) . '">';
    }
    echo '<div class="estab-message-list-search-row">';
    echo '<label class="estab-message-list-query" for="' . $domPrefix . '-q">';
    echo '<span>Nachrichten durchsuchen</span>';
    echo '<input id="' . $domPrefix . '-q" type="search" name="ml_q" '
        . 'value="' . estab_auth_html((string) ($filters['q'] ?? '')) . '" '
        . 'maxlength="120" autocomplete="off" enterkeyhint="search" '
        . 'placeholder="z. B. 142, Betreff, Rufname oder Stichwort" '
        . 'aria-describedby="' . $helpId . '">';
    echo '<small id="' . $helpId . '">Durchsucht Nummer, Betreff, Rufname, '
        . 'Rufnummer, Von, An, Verfasserfunktion und Nachrichtentext.</small>';
    echo '</label>';
    echo '<button class="estab-button estab-button-primary" type="submit" '
        . 'name="ml_apply" value="1">Suchen</button>';
    echo '</div>';

    echo '<fieldset class="estab-message-list-quick-filters">';
    echo '<legend>Schnellfilter</legend>';
    echo '<label for="' . $domPrefix . '-direction"><span>Richtung</span>';
    echo '<select id="' . $domPrefix . '-direction" name="ml_direction">'
        . estab_message_list_select_options([
            '' => 'Alle Richtungen', 'E' => 'Eingang', 'A' => 'Ausgang',
        ], (string) ($filters['direction'] ?? '')) . '</select></label>';
    echo '<label for="' . $domPrefix . '-priority"><span>Vorrang</span>';
    $priorityOptions = [
        '' => 'Alle Vorrangstufen',
        'none' => 'Kein Vorrang',
    ];
    foreach (estab_message_priority_options() as $priorityOption) {
        $priorityValue = (string) ($priorityOption['value'] ?? '');
        if ($priorityValue !== '') {
            $priorityOptions[$priorityValue] = (string) (
                $priorityOption['label'] ?? $priorityValue
            );
        }
    }
    echo '<select id="' . $domPrefix . '-priority" name="ml_priority">'
        . estab_message_list_select_options(
            $priorityOptions,
            (string) ($filters['priority'] ?? '')
        ) . '</select></label>';
    echo '<label for="' . $domPrefix . '-status"><span>Bearbeitungsstand</span>';
    echo '<select id="' . $domPrefix . '-status" name="ml_status">'
        . estab_message_list_select_options(
            estab_message_list_status_options(),
            (string) ($filters['status'] ?? '')
        ) . '</select></label>';
    echo '</fieldset>';

    echo '<details class="estab-message-list-more"'
        . ($advancedOpen ? ' open' : '') . '><summary>Weitere Filter und Sortierung'
        . ($advancedOpen ? ' · aktiv' : '') . '</summary>';
    echo '<div class="estab-message-list-filter-grid">';
    echo '<label for="' . $domPrefix . '-from"><span>Zeitraum von</span>'
        . '<input id="' . $domPrefix . '-from" type="date" name="ml_from" '
        . 'value="' . estab_auth_html((string) ($filters['from'] ?? ''))
        . '"></label>';
    echo '<label for="' . $domPrefix . '-to"><span>Zeitraum bis</span>'
        . '<input id="' . $domPrefix . '-to" type="date" name="ml_to" '
        . 'value="' . estab_auth_html((string) ($filters['to'] ?? ''))
        . '"></label>';
    echo '<label for="' . $domPrefix . '-recipient"><span>Empfängerfunktion</span>';
    $recipientOptions = ['' => 'Alle Empfängerfunktionen'];
    foreach ($recipients as $recipient) {
        $recipientOptions[$recipient] = $recipient;
    }
    echo '<select id="' . $domPrefix . '-recipient" name="ml_recipient">'
        . estab_message_list_select_options(
            $recipientOptions,
            (string) ($filters['recipient'] ?? '')
        ) . '</select></label>';
    echo '<label for="' . $domPrefix . '-sort"><span>Sortierung</span>'
        . '<select id="' . $domPrefix . '-sort" name="ml_sort">'
        . estab_message_list_select_options(
            estab_message_list_sort_options(),
            (string) ($filters['sort'] ?? 'priority_newest')
        ) . '</select></label>';
    echo '<label for="' . $domPrefix . '-page-size"><span>Nachrichten pro Seite</span>'
        . '<select id="' . $domPrefix . '-page-size" name="ml_page_size">'
        . estab_message_list_select_options(
            ['25' => '25', '50' => '50', '100' => '100'],
            (string) ($filters['page_size'] ?? 50)
        ) . '</select></label>';
    echo '</div></details>';

    echo '<div class="estab-tool-actions">';
    echo '<button class="estab-button estab-button-primary" type="submit" '
        . 'name="ml_apply" value="1">Filter anwenden</button>';
    echo '<button class="estab-button" type="submit" name="ml_reset" '
        . 'value="1">Alle Filter zurücksetzen</button>';
    echo '</div>';

    if ($labels !== []) {
        echo '<div class="estab-message-list-active" aria-label="Aktive Filter">';
        echo '<strong>Aktive Filter</strong><div>';
        foreach ($labels as $key => $label) {
            echo '<button class="estab-message-list-chip" type="submit" '
                . 'name="ml_remove" value="' . estab_auth_html($key) . '" '
                . 'aria-label="' . estab_auth_html($label) . ' entfernen">'
                . estab_auth_html($label) . '<span aria-hidden="true">×</span>'
                . '</button>';
        }
        echo '</div></div>';
    }
    echo '</form></section>';
}

/** @param array<string,string> $hidden */
function estab_message_list_render_resultbar(
    array $filters,
    array $pageWindow,
    array $options = []
): void {
    $count = (int) ($pageWindow['count'] ?? 0);
    $first = (int) ($pageWindow['first'] ?? 0);
    $last = (int) ($pageWindow['last'] ?? 0);
    $sortLabel = estab_message_list_sort_options()[
        (string) ($filters['sort'] ?? 'priority_newest')
    ] ?? 'Vorrang zuerst, dann neueste';
    echo '<div class="estab-message-list-resultbar">';
    echo '<p class="estab-message-list-resultcount" role="status" '
        . 'aria-live="polite">';
    if ($count === 0) {
        echo '<strong>Keine Nachrichten gefunden</strong>';
    } else {
        echo '<strong>' . $first . '–' . $last . ' von ' . $count
            . ' Nachrichten</strong>';
    }
    echo '</p>';
    echo '<p class="estab-message-list-sort-summary">Sortierung: '
        . estab_auth_html($sortLabel) . '</p>';
    echo '<p class="estab-message-list-updated">Zuletzt aktualisiert: '
        . estab_auth_html((string) ($options['updated_at'] ?? date('H:i:s')))
        . '</p>';
    echo '</div>';
}

/** @return array<string,string> */
function estab_message_list_filter_hidden_fields(array $filters): array
{
    return [
        'ml_q' => (string) ($filters['q'] ?? ''),
        'ml_direction' => (string) ($filters['direction'] ?? ''),
        'ml_priority' => (string) ($filters['priority'] ?? ''),
        'ml_status' => (string) ($filters['status'] ?? ''),
        'ml_from' => (string) ($filters['from'] ?? ''),
        'ml_to' => (string) ($filters['to'] ?? ''),
        'ml_recipient' => (string) ($filters['recipient'] ?? ''),
        'ml_sort' => (string) ($filters['sort'] ?? 'priority_newest'),
        'ml_page_size' => (string) ($filters['page_size'] ?? 50),
    ];
}

/**
 * @param array<string,string> $hidden
 */
function estab_message_list_render_pager(
    array $filters,
    array $pageWindow,
    array $options
): void {
    $page = (int) ($pageWindow['page'] ?? 1);
    $pages = max(1, (int) ($pageWindow['page_count'] ?? 1));
    $action = (string) ($options['action'] ?? '');
    $method = strtolower((string) ($options['method'] ?? 'get'));
    $target = (string) ($options['target'] ?? '_self');
    $hidden = $options['hidden'] ?? [];
    if (!is_array($hidden)) {
        throw new InvalidArgumentException('Ungültige Pagerfelder');
    }
    echo '<nav class="estab-message-list-pager" aria-label="Ergebnisseiten">';
    echo '<form method="' . estab_auth_html($method) . '" action="'
        . estab_auth_html($action) . '" target="' . estab_auth_html($target)
        . '">';
    echo (string) ($options['csrf_html'] ?? '');
    foreach (array_merge(estab_message_list_filter_hidden_fields($filters), $hidden)
        as $name => $value) {
        echo '<input type="hidden" name="' . estab_auth_html((string) $name)
            . '" value="' . estab_auth_html((string) $value) . '">';
    }
    $disabledBack = $page <= 1 ? ' disabled' : '';
    $disabledForward = $page >= $pages ? ' disabled' : '';
    echo '<button class="estab-button" name="ml_page" value="1" type="submit"'
        . $disabledBack . '>Erste</button>';
    echo '<button class="estab-button" name="ml_page" value="'
        . max(1, $page - 1) . '" type="submit"' . $disabledBack
        . '>Zurück</button>';
    echo '<span class="estab-message-list-page-status" aria-current="page">Seite '
        . $page . ' von ' . $pages . '</span>';
    echo '<button class="estab-button" name="ml_page" value="'
        . min($pages, $page + 1) . '" type="submit"' . $disabledForward
        . '>Weiter</button>';
    echo '<button class="estab-button" name="ml_page" value="' . $pages
        . '" type="submit"' . $disabledForward . '>Letzte</button>';
    echo '</form></nav>';
}

/** @return list<string> */
function estab_message_list_recipient_labels(mixed $stored): array
{
    $copyMap = estab_recipient_copy_map($stored);
    $labels = [];
    foreach ($copyMap as $function => $copy) {
        if ($copy === '') {
            continue;
        }
        $labels[] = $function;
    }
    sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    return $labels;
}

/**
 * Render the common compact result table. The callback must emit exactly one
 * authenticated detail control for the supplied row.
 *
 * @param list<array<string,mixed>> $rows
 */
function estab_message_list_render_table(array $rows, callable $openControl): void
{
    echo '<div class="estab-message-list-table-wrap estab-tool-table-wrap">';
    echo '<table class="estab-message-list-table estab-tool-table">';
    echo '<caption class="estab-visually-hidden">Gefilterte Nachrichtenvordrucke '
        . 'des aktiven Einsatzes</caption>';
    echo '<thead><tr>';
    foreach ([
        'Nachweis', 'Zeitpunkt', 'Von und An', 'Betreff und Inhalt',
        'Bearbeitungsstand', 'Verteilung', 'Aktion',
    ] as $heading) {
        echo '<th scope="col">' . estab_auth_html($heading) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $recordId = estab_message_positive_id($row['00_lfd'] ?? null);
        $direction = (string) ($row['04_richtung'] ?? '');
        $from = (string) ($row['13_abseinheit'] ?? '');
        if ($from === '') {
            $from = (string) ($row['14_funktion'] ?? '');
        }
        if ($from === '') {
            $from = (string) ($row['05_gegenstelle'] ?? 'Unbekannt');
        }
        $to = trim((string) ($row['10_anschrift'] ?? ''));
        $to = $to !== '' ? $to : 'Nicht angegeben';
        $subject = trim(estab_message_plain_text($row['12_betreff'] ?? ''));
        $subject = $subject !== '' ? $subject : 'Ohne Betreff';
        $content = estab_message_excerpt($row['12_inhalt'] ?? '', 180);
        $priorityLabel = estab_message_priority_label(
            $row['09_vorrangstufe'] ?? null
        );
        $urgent = estab_message_priority_requires_attention(
            $row['09_vorrangstufe'] ?? null
        );
        $statusLabel = estab_message_list_status_label($row['x00_status'] ?? null);
        $recipients = estab_message_list_recipient_labels($row['16_empf'] ?? '');

        echo '<tr class="estab-message-list-row'
            . ($urgent ? ' estab-message-list-row--priority' : '')
            . '" data-message-id="' . $recordId . '">';
        $storedPriority = estab_message_priority_storage_value(
            $row['09_vorrangstufe'] ?? null
        );
        echo '<td data-label="Nachweis"><strong class="estab-message-list-route">'
            . estab_auth_html(estab_message_list_direction_label($direction))
            . ' ' . estab_auth_html((string) ($row['04_nummer'] ?? '–'))
            . '</strong><span class="estab-message-list-priority'
            . ($urgent ? ' estab-message-list-priority--urgent' : '')
            . '" data-priority="'
            . estab_auth_html($storedPriority ?? 'unknown') . '">'
            . estab_auth_html($priorityLabel) . '</span></td>';
        echo '<td data-label="Zeitpunkt" class="estab-tool-table-number">'
            . estab_auth_html(estab_message_list_datetime_label(
                $row['12_abfzeit'] ?? null
            )) . '</td>';
        echo '<td data-label="Von und An"><span '
            . 'class="estab-message-list-correspondents"><span>'
            . '<strong>Von:</strong> '
            . estab_message_html($from) . '</span><span><strong>An:</strong> '
            . estab_message_html($to) . '</span></span></td>';
        echo '<td data-label="Betreff und Inhalt"><strong '
            . 'class="estab-message-list-subject">' . estab_message_html($subject)
            . '</strong><span class="estab-message-list-excerpt">'
            . estab_message_html($content) . '</span></td>';
        echo '<td data-label="Bearbeitungsstand"><span '
            . 'class="estab-message-list-status '
            . estab_message_list_status_class($row['x00_status'] ?? null)
            . '" data-status="'
            . estab_auth_html((string) ($row['x00_status'] ?? 'unknown')) . '">'
            . estab_auth_html($statusLabel) . '</span></td>';
        echo '<td data-label="Verteilung"><span '
            . 'class="estab-message-list-recipients">';
        if ($recipients === []) {
            echo '<span>Keine Verteilung</span>';
        } else {
            foreach ($recipients as $recipient) {
                echo '<span class="estab-tool-badge estab-tool-badge-neutral">'
                    . estab_auth_html($recipient) . '</span>';
            }
        }
        echo '</span></td>';
        echo '<td data-label="Aktion" class="estab-message-list-action">';
        $openControl($row);
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function estab_message_list_render_empty(array $filters): void
{
    $active = estab_message_list_filter_labels($filters) !== [];
    echo '<div class="estab-message-list-empty" data-estab-message-list-empty>';
    echo '<h3>' . ($active ? 'Keine passenden Nachrichten' : 'Noch keine Nachrichten')
        . '</h3>';
    echo '<p>' . ($active
        ? 'Ändern oder entfernen Sie Filter, um weitere Treffer zu sehen.'
        : 'Im aktiven Einsatz wurden für diese Übersicht noch keine Nachrichten erfasst.')
        . '</p></div>';
}
