<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/file_access.php';
require_once __DIR__ . '/message_priority.php';
require_once __DIR__ . '/message_repository.php';
require_once __DIR__ . '/message_list.php';
require_once __DIR__ . '/message_status.php';
require_once __DIR__ . '/message_timeline.php';

/** Human-readable workflow state without relying on colour alone. */
function estab_message_list_status_label(mixed $status): string
{
    return estab_message_status_name($status);
}

function estab_message_list_status_class(mixed $status): string
{
    return match (estab_message_status($status)) {
        ESTAB_MESSAGE_STATUS_CLOSED => 'estab-message-list-status--done',
        ESTAB_MESSAGE_STATUS_RETURNED => 'estab-message-list-status--returned',
        ESTAB_MESSAGE_STATUS_LDF,
        ESTAB_MESSAGE_STATUS_TRANSPORT,
        ESTAB_MESSAGE_STATUS_REVIEW => 'estab-message-list-status--active',
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

/** Describe the canonical TTB link without inventing a fallback number. */
function estab_message_list_tbb_evidence_label(array $row): string
{
    $value = $row['estab_tbb_book_lfd'] ?? null;
    if (
        (is_int($value) && $value > 0)
        || (
            is_string($value)
            && preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) === 1
            && (int) $value > 0
            && (int) $value <= 4294967295
        )
    ) {
        return 'TBB-Nachweis ' . (string) ((int) $value);
    }
    return 'noch kein TBB-Nachweis';
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

/**
 * Return the exact, canonical attachment names stored on one message.
 *
 * Legacy rows may contain duplicate or malformed semicolon-delimited
 * fragments.  A visual attachment count must never turn such fragments into
 * HTML or claim that an unsafe/non-existent filename is a real attachment.
 *
 * @return list<string>
 */
function estab_message_list_attachment_tokens(mixed $value): array
{
    if (!is_string($value) || $value === '') {
        return [];
    }

    $tokens = [];
    foreach (explode(';', $value) as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        try {
            $token = estab_file_validate_name('attachment', $token);
        } catch (InvalidArgumentException) {
            continue;
        }
        $tokens[$token] = true;
    }
    return array_keys($tokens);
}

function estab_message_list_attachment_label(int $count): string
{
    if ($count < 1) {
        throw new InvalidArgumentException('Attachment count must be positive');
    }
    return $count === 1 ? '1 Anlage' : $count . ' Anlagen';
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
    if (($filters['read_state'] ?? '') !== '') {
        $labels['read_state'] = 'Kenntnis: ' . (
            $filters['read_state'] === 'unread' ? 'ungelesen' : 'gelesen'
        );
    }
    if (($filters['done_state'] ?? '') !== '') {
        $labels['done_state'] = 'Bearbeitung: ' . (
            $filters['done_state'] === 'done' ? 'erledigt' : 'offen'
        );
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
        $labels['recipient'] = 'Empfänger: ' . estab_function_display_name(
            (string) $filters['recipient']
        );
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
        'number_desc' => 'Höchste TBB-Nachweisnummer zuerst',
        'number_asc' => 'Niedrigste TBB-Nachweisnummer zuerst',
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

/** @return array<string,string> */
function estab_message_list_read_state_options(): array
{
    return [
        '' => 'Gelesen und ungelesen',
        'unread' => 'Nur ungelesene',
        'read' => 'Nur gelesene',
    ];
}

/** @return array<string,string> */
function estab_message_list_done_state_options(): array
{
    return [
        '' => 'Offen und erledigt',
        'open' => 'Nur offene',
        'done' => 'Nur erledigte',
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
 * @param array<string,mixed> $options
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
    // Only a caller which really owns per-identity read/done tables may offer
    // those filters; otherwise the surface would promise a filter that no
    // query can answer.
    $stateFilters = ($options['state_filters'] ?? false) === true;
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
        . 'placeholder="z. B. Überschrift, TBB-Nachweis 142 oder Rufname" '
        . 'aria-describedby="' . $helpId . '">';
    echo '<small id="' . $helpId . '">Durchsucht Vordruck-Überschrift '
        . '(Betreff), TBB-Nachweisnummer, Rufname, Rufnummer, Von, An, '
        . 'Verfasserfunktion und Nachrichtentext.</small>';
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
    if ($stateFilters) {
        echo '<label for="' . $domPrefix . '-read-state"><span>Kenntnis</span>';
        echo '<select id="' . $domPrefix . '-read-state" name="ml_read_state">'
            . estab_message_list_select_options(
                estab_message_list_read_state_options(),
                (string) ($filters['read_state'] ?? '')
            ) . '</select></label>';
        echo '<label for="' . $domPrefix . '-done-state"><span>Bearbeitung'
            . '</span>';
        echo '<select id="' . $domPrefix . '-done-state" name="ml_done_state">'
            . estab_message_list_select_options(
                estab_message_list_done_state_options(),
                (string) ($filters['done_state'] ?? '')
            ) . '</select></label>';
    }
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
        $recipientOptions[$recipient] = estab_function_display_name($recipient);
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

/** @param array<string,mixed> $options */
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
function estab_message_list_filter_hidden_fields(
    array $filters,
    string $prefix = 'ml_'
): array {
    $prefix = estab_message_list_prefix($prefix);
    return [
        $prefix . 'q' => (string) ($filters['q'] ?? ''),
        $prefix . 'direction' => (string) ($filters['direction'] ?? ''),
        $prefix . 'priority' => (string) ($filters['priority'] ?? ''),
        $prefix . 'status' => (string) ($filters['status'] ?? ''),
        $prefix . 'read_state' => (string) ($filters['read_state'] ?? ''),
        $prefix . 'done_state' => (string) ($filters['done_state'] ?? ''),
        $prefix . 'from' => (string) ($filters['from'] ?? ''),
        $prefix . 'to' => (string) ($filters['to'] ?? ''),
        $prefix . 'recipient' => (string) ($filters['recipient'] ?? ''),
        $prefix . 'sort' => (string) ($filters['sort'] ?? 'priority_newest'),
        $prefix . 'page_size' => (string) ($filters['page_size'] ?? 50),
    ];
}

/**
 * @param array<string,mixed> $options
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
    $prefix = estab_message_list_prefix(
        (string) ($options['prefix'] ?? 'ml_')
    );
    $pageField = $prefix . 'page';
    $hidden = $options['hidden'] ?? [];
    if (!is_array($hidden)) {
        throw new InvalidArgumentException('Ungültige Pagerfelder');
    }
    echo '<nav class="estab-message-list-pager" aria-label="Ergebnisseiten">';
    echo '<form method="' . estab_auth_html($method) . '" action="'
        . estab_auth_html($action) . '" target="' . estab_auth_html($target)
        . '">';
    echo (string) ($options['csrf_html'] ?? '');
    foreach (array_merge(
        estab_message_list_filter_hidden_fields($filters, $prefix),
        $hidden
    ) as $name => $value) {
        echo '<input type="hidden" name="' . estab_auth_html((string) $name)
            . '" value="' . estab_auth_html((string) $value) . '">';
    }
    $disabledBack = $page <= 1 ? ' disabled' : '';
    $disabledForward = $page >= $pages ? ' disabled' : '';
    echo '<button class="estab-button" name="' . $pageField
        . '" value="1" type="submit"' . $disabledBack . '>Erste</button>';
    echo '<button class="estab-button" name="' . $pageField . '" value="'
        . max(1, $page - 1) . '" type="submit"' . $disabledBack
        . '>Zurück</button>';
    echo '<span class="estab-message-list-page-status" aria-current="page">Seite '
        . $page . ' von ' . $pages . '</span>';
    echo '<button class="estab-button" name="' . $pageField . '" value="'
        . min($pages, $page + 1) . '" type="submit"' . $disabledForward
        . '>Weiter</button>';
    echo '<button class="estab-button" name="' . $pageField . '" value="'
        . $pages . '" type="submit"' . $disabledForward . '>Letzte</button>';
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
        $labels[] = estab_function_display_name($function);
    }
    sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    return $labels;
}

/**
 * Dwell time of one row together with its deadline verdict.
 *
 * The verdict is written out, so colour only reinforces a word which is
 * already there. The exact seconds stay in a data attribute for tooling and
 * for tests.
 */
function estab_message_list_dwell_markup(array $row): string
{
    $priority = $row['09_vorrangstufe'] ?? null;
    $state = estab_message_list_dwell_state(
        $priority,
        $row['estab_dwell_seconds'] ?? null,
        $row['x00_status'] ?? null
    );
    $seconds = estab_message_list_dwell_seconds(
        $row['estab_dwell_seconds'] ?? null
    );
    $verdict = [
        'overdue' => 'überfällig',
        'warn' => 'wird fällig',
        'ok' => 'in Frist',
    ][$state] ?? '';
    if ($state === 'closed') {
        $text = 'Laufzeit beendet';
    } elseif ($seconds === null) {
        $text = 'Verweildauer nicht nachgewiesen';
    } else {
        $text = 'seit ' . estab_message_timeline_duration_label($seconds)
            . ' · ' . $verdict;
    }
    $limits = estab_message_list_dwell_limits($priority);
    $title = 'Vorrang ' . estab_message_priority_label($priority)
        . ': überfällig ab '
        . estab_message_timeline_duration_label($limits['overdue']);
    return '<span class="estab-message-list-dwell '
        . 'estab-message-list-dwell--' . $state . '" '
        . 'data-estab-message-dwell="' . $state . '"'
        . ($seconds === null
            ? ''
            : ' data-estab-message-dwell-seconds="' . $seconds . '"')
        . ' title="' . estab_auth_html($title) . '">'
        . estab_auth_html($text) . '</span>';
}

/** Whether one EXISTS result column reports a present state row. */
function estab_message_list_state_flag(mixed $value): bool
{
    return $value === 1 || $value === true || $value === '1';
}

/**
 * Reading and handling state of one row.
 *
 * A caller without per-identity state tables says so instead of presenting an
 * unread message as read.
 */
function estab_message_list_awareness_markup(array $row): string
{
    if (
        !array_key_exists('estab_state_read', $row)
        && !array_key_exists('estab_state_done', $row)
    ) {
        return '<span class="estab-message-list-awareness '
            . 'estab-message-list-awareness--unknown">nicht geführt</span>';
    }
    $read = estab_message_list_state_flag($row['estab_state_read'] ?? null);
    $done = estab_message_list_state_flag($row['estab_state_done'] ?? null);
    return '<span class="estab-message-list-awareness '
        . 'estab-message-list-awareness--' . ($read ? 'read' : 'unread')
        . '">' . ($read ? 'gelesen' : 'ungelesen') . '</span>'
        . '<span class="estab-message-list-awareness '
        . 'estab-message-list-awareness--' . ($done ? 'done' : 'open')
        . '">' . ($done ? 'erledigt' : 'offen') . '</span>';
}

/**
 * Shorten one message text and keep the complete text one click away.
 *
 * Truncation is a display decision only: the full evidence text stays in the
 * same row, so a Nachweisung line remains one screen line high without
 * withholding anything.
 */
function estab_message_list_clamped_text(mixed $value, int $limit = 160): string
{
    if ($limit < 1) {
        throw new InvalidArgumentException('Ungültige Textkürzung');
    }
    $text = estab_message_plain_text($value);
    if (trim($text) === '') {
        return '<span class="estab-message-list-clamp '
            . 'estab-message-list-clamp--empty">ohne Inhalt</span>';
    }
    $excerpt = estab_message_excerpt($text, $limit);
    if ($excerpt === $text) {
        return '<span class="estab-message-list-clamp">'
            . estab_message_html($text) . '</span>';
    }
    return '<span class="estab-message-list-clamp">'
        . estab_message_html($excerpt) . ' …</span>'
        . '<details class="estab-message-list-fulltext">'
        . '<summary>Ganze Nachricht</summary><p>'
        . estab_message_html($text) . '</p></details>';
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
        'TBB-Nachweis', 'Zeit und Verweildauer', 'Von und An',
        'Überschrift und Inhalt', 'Bearbeitungsstand', 'Kenntnis',
        'Verteilung', 'Aktion',
    ] as $heading) {
        echo '<th scope="col">' . estab_auth_html($heading) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $recordId = estab_message_positive_id($row['00_lfd'] ?? null);
        $direction = (string) ($row['04_richtung'] ?? '');
        $from = (string) ($row['13_abseinheit'] ?? '');
        if ($from === '') {
            $from = estab_function_display_name(
                (string) ($row['14_funktion'] ?? '')
            );
        }
        if ($from === '') {
            $from = (string) ($row['05_gegenstelle'] ?? 'Unbekannt');
        }
        $to = trim((string) ($row['10_anschrift'] ?? ''));
        $to = $to !== '' ? $to : 'Nicht angegeben';
        $subject = trim(estab_message_plain_text($row['12_betreff'] ?? ''));
        $subjectMissing = $subject === '';
        $subject = $subjectMissing
            ? 'Keine Überschrift angegeben'
            : $subject;
        $content = estab_message_excerpt($row['12_inhalt'] ?? '', 180);
        $priorityLabel = estab_message_priority_label(
            $row['09_vorrangstufe'] ?? null
        );
        $urgent = estab_message_priority_requires_attention(
            $row['09_vorrangstufe'] ?? null
        );
        $statusLabel = estab_message_list_status_label($row['x00_status'] ?? null);
        $recipients = estab_message_list_recipient_labels($row['16_empf'] ?? '');
        $attachmentCount = count(estab_message_list_attachment_tokens(
            $row['12_anhang'] ?? null
        ));

        echo '<tr class="estab-message-list-row'
            . ($urgent ? ' estab-message-list-row--priority' : '')
            . '" data-message-id="' . $recordId . '">';
        $storedPriority = estab_message_priority_storage_value(
            $row['09_vorrangstufe'] ?? null
        );
        echo '<td data-label="TBB-Nachweis"><strong class="estab-message-list-route">'
            . estab_auth_html(estab_message_list_direction_label($direction))
            . ' · ' . estab_auth_html(estab_message_list_tbb_evidence_label($row))
            . '</strong><span class="estab-message-list-priority'
            . ($urgent ? ' estab-message-list-priority--urgent' : '')
            . '" data-priority="'
            . estab_auth_html($storedPriority ?? 'unknown') . '">'
            . estab_auth_html($priorityLabel) . '</span></td>';
        echo '<td data-label="Zeit und Verweildauer" '
            . 'class="estab-tool-table-number">'
            . '<span class="estab-message-list-time">'
            . estab_auth_html(estab_message_list_datetime_label(
                $row['12_abfzeit'] ?? null
            )) . '</span>'
            . estab_message_list_dwell_markup($row) . '</td>';
        echo '<td data-label="Von und An"><span '
            . 'class="estab-message-list-correspondents"><span>'
            . '<strong>Von:</strong> '
            . estab_message_html($from) . '</span><span><strong>An:</strong> '
            . estab_message_html($to) . '</span></span></td>';
        echo '<td data-label="Überschrift und Inhalt" '
            . 'class="estab-message-list-summary">'
            . '<span class="estab-message-list-field-label">'
            . 'Vordruck-Überschrift</span><strong '
            . 'class="estab-message-list-subject'
            . ($subjectMissing ? ' estab-message-list-subject--empty' : '')
            . '" data-estab-message-list-heading '
            . 'data-estab-message-list-heading-empty="'
            . ($subjectMissing ? 'true' : 'false') . '">'
            . estab_message_html($subject)
            . '</strong><span class="estab-message-list-field-label '
            . 'estab-message-list-content-label">Nachrichteninhalt</span>'
            . '<span class="estab-message-list-excerpt">'
            . estab_message_html($content) . '</span>';
        if ($attachmentCount > 0) {
            $attachmentLabel = estab_message_list_attachment_label(
                $attachmentCount
            );
            echo '<span class="estab-tool-badge estab-tool-badge-warning '
                . 'estab-message-list-attachments" '
                . 'data-estab-message-attachment-badge '
                . 'data-estab-message-attachment-count="' . $attachmentCount
                . '" aria-label="' . estab_auth_html($attachmentLabel) . '">'
                . estab_auth_html($attachmentLabel) . '</span>';
        }
        echo '</td>';
        echo '<td data-label="Bearbeitungsstand"><span '
            . 'class="estab-message-list-status '
            . estab_message_list_status_class($row['x00_status'] ?? null)
            . '" data-status="'
            . estab_auth_html((string) ($row['x00_status'] ?? 'unknown')) . '">'
            . estab_auth_html($statusLabel) . '</span></td>';
        echo '<td data-label="Kenntnis"><span '
            . 'class="estab-message-list-awareness-group">'
            . estab_message_list_awareness_markup($row) . '</span></td>';
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
