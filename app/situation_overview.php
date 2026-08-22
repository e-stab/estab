<?php

declare(strict_types=1);

/**
 * Lagebild der Führungsstelle für den Einstieg nach der Anmeldung.
 *
 * Wer die Führungsstelle betrat, sah bisher ein Linkmenü und musste raten, wo
 * Arbeit liegt: die eigene Warteschlange, offener Vorrangverkehr, eine
 * unbesetzte Station des Nachrichtenlaufs und die Liegezeit der ältesten
 * offenen Nachricht standen auf keiner Einstiegsseite. Dieses Modul misst
 * diese Lage und stellt sie so dar, dass sie auf einen flachen
 * Laptopbildschirm passt.
 *
 * Das Lagebild ist ein Bericht: es öffnet keinen Vorgang, schreibt nichts und
 * ersetzt keine Berechtigungsprüfung. Es entsteht nur für ein angemeldetes
 * Konto, dessen operativer Lesezugriff auf den aktiven Einsatz in diesem
 * Augenblick gilt; sonst bleibt es bei der bisherigen Einstiegsseite.
 *
 * Alle Zählstände entstehen in einem einzigen Datenbankumlauf: die
 * Warteschlange jeder getragenen Funktion - DV 1-101 kennt die Doppelfunktion
 * als Regelfall der kleinen Führungsstelle - und die beiden Lagewerte sind je
 * eine Spalte derselben vorbereiteten Anweisung.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/dv_operations.php';
require_once __DIR__ . '/incident_ui.php';
require_once __DIR__ . '/message_priority.php';
require_once __DIR__ . '/read_authorization.php';
require_once __DIR__ . '/sidebar.php';

/** Anzahl der Lagespalten, die neben den Warteschlangen gemessen werden. */
const ESTAB_SITUATION_MEASUREMENT_COLUMNS = 2;

/**
 * Offen ist eine Nachricht, solange sie weder abgeschlossen noch endgültig
 * ist. Genau dieses Paar entscheidet auch im Nachrichtenspeicher darüber, ob
 * eine Nachricht überhaupt noch bearbeitet werden darf.
 */
function estab_situation_open_message_predicate(): string
{
    return "`x01_abschluss` = 'f' AND `x00_status` <> 8";
}

/**
 * Eine vorbereitete Anweisung für alle Warteschlangen und beide Lagewerte.
 *
 * Die Spalten der Warteschlangen stammen unverändert aus der Seitenleiste,
 * damit Lagebild und Seitenleiste niemals verschiedene Zahlen zeigen. Die
 * beiden Lagespalten stehen dahinter, so dass die Parameter weiterhin in der
 * Reihenfolge der Spalten gebunden werden.
 *
 * @param list<array{
 *     session_key: string,
 *     baseline_key: string,
 *     funktion: string
 * }> $profiles
 * @return array{sql: string, parameters: list<int|string>, keys: list<string>}
 */
function estab_situation_batch_query(
    array $profiles,
    string $messageTable,
    string $userTablePrefix,
    bool $includeOutgoingForReview,
    int $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $messages = estab_auth_table($messageTable);
    $open = estab_situation_open_message_predicate();
    $urgent = estab_message_priority_order_sql('`09_vorrangstufe`') . ' > 0';
    $columns = 'SELECT';
    $parameters = [];
    $keys = [];
    if ($profiles !== []) {
        $queues = estab_sidebar_queue_batch_query(
            $profiles,
            $messageTable,
            $userTablePrefix,
            $includeOutgoingForReview,
            $incidentId
        );
        if (!str_starts_with($queues['sql'], 'SELECT (')) {
            throw new RuntimeException('Unexpected sidebar queue statement');
        }
        $columns = $queues['sql'] . ',';
        $parameters = $queues['parameters'];
        $keys = $queues['keys'];
    }

    /*
     * Die Liegezeit rechnet die Datenbank aus, die auch die Zeitstempel
     * geschrieben hat; eine in PHP gerechnete Differenz läge bei einer
     * abweichenden Zeitzone still daneben. GREATEST fängt eine Nachricht ab,
     * deren Abfassungszeit in der Zukunft steht, und liefert ohne jede offene
     * Nachricht weiterhin NULL.
     */
    $sql = $columns
        . ' (SELECT COUNT(*) FROM ' . $messages
        . ' WHERE `einsatz_id` = ? AND ' . $open
        . ' AND ' . $urgent . ') AS `vorrang_offen`,'
        . ' (SELECT GREATEST(0, TIMESTAMPDIFF(MINUTE,'
        . ' MIN(COALESCE(`01_datum`, `12_abfzeit`)), NOW()))'
        . ' FROM ' . $messages
        . ' WHERE `einsatz_id` = ? AND ' . $open
        . ') AS `aelteste_offene_minuten`';
    $parameters[] = $incidentId;
    $parameters[] = $incidentId;

    return ['sql' => $sql, 'parameters' => $parameters, 'keys' => $keys];
}

/** Eine nicht gemessene Liegezeit bleibt null, jede andere ist eine Zahl. */
function estab_situation_minutes_value(mixed $value): ?int
{
    return $value === null ? null : estab_sidebar_queue_value($value);
}

/**
 * Alle Zählstände des Lagebilds in einem Datenbankumlauf lesen.
 *
 * @param list<array{
 *     session_key: string,
 *     baseline_key: string,
 *     funktion: string
 * }> $profiles
 * @return array{
 *     queues: array<string, int>,
 *     urgent_open: int,
 *     oldest_open_minutes: ?int
 * }
 */
function estab_situation_measure(
    mysqli $connection,
    array $profiles,
    string $messageTable,
    string $userTablePrefix,
    bool $includeOutgoingForReview,
    int $incidentId
): array {
    $query = estab_situation_batch_query(
        $profiles,
        $messageTable,
        $userTablePrefix,
        $includeOutgoingForReview,
        $incidentId
    );
    $statement = $connection->prepare($query['sql']);
    if (!$statement) {
        throw new RuntimeException('Could not prepare situation lookup');
    }

    try {
        if (!$statement->execute($query['parameters'])) {
            throw new RuntimeException('Could not execute situation lookup');
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Could not read situation lookup');
        }
        $row = $result->fetch_row();
        $result->free();
        $expected = count($query['keys'])
            + ESTAB_SITUATION_MEASUREMENT_COLUMNS;
        if (!is_array($row) || count($row) !== $expected) {
            throw new RuntimeException('Invalid situation lookup result');
        }
        $queues = [];
        foreach ($query['keys'] as $index => $key) {
            $queues[$key] = estab_sidebar_queue_value($row[$index]);
        }
        return [
            'queues' => $queues,
            'urgent_open' => estab_sidebar_queue_value(
                $row[$expected - ESTAB_SITUATION_MEASUREMENT_COLUMNS]
            ),
            'oldest_open_minutes' => estab_situation_minutes_value(
                $row[$expected - 1]
            ),
        ];
    } finally {
        $statement->close();
    }
}

/**
 * Vollständige Lage für ein angemeldetes Konto, oder null.
 *
 * Null bedeutet: dieses Konto bekommt die bisherige Einstiegsseite. Das ist
 * der Fall ohne aktiven Einsatz, ohne gültige operative Funktion und bei
 * einem Einsatz, dem der Name der Führungsstelle fehlt - dann sind operative
 * Eingaben ohnehin gesperrt, und die gemeinsame Anzeige sagt das bereits.
 *
 * @return array<string, mixed>|null
 */
function estab_situation_snapshot(
    mysqli $connection,
    array $identity,
    string $messageTable,
    string $userTablePrefix,
    bool $includeOutgoingForReview,
    ?DateTimeImmutable $now = null
): ?array {
    /*
     * Alles, was das Lagebild ueberhaupt erst moeglich macht, wird hier
     * geprueft: ein aktiver Einsatz, eine gueltige operative Funktion, eine
     * vollstaendige Einsatzidentitaet. Faellt eines davon aus, bekommt der
     * Einstieg seine bisherige Seite - und keine halbe Anzeige.
     */
    try {
        $scope = estab_read_require_operational_scope($connection, $identity);
        $incident = $scope['incident'];
        $selected = $scope['identity'];
        $incidentId = estab_incident_positive_id(
            $incident['active_einsatz_id'] ?? null
        );
        $commandPost = estab_incident_command_post_name($incident);
        $code = trim((string) ($incident['kennung'] ?? ''));
        $name = trim((string) ($incident['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new RuntimeException('Active incident identity is missing');
        }
        $mode = estab_permission_mode(
            $incident['estab_permission_mode'] ?? null
        );
        $profiles = estab_sidebar_queue_profiles($selected);
    } catch (Throwable $exception) {
        error_log(
            'eStab situation overview unavailable: ' . $exception->getMessage()
        );
        return null;
    }

    $measurement = null;
    try {
        $measurement = estab_situation_measure(
            $connection,
            $profiles,
            $messageTable,
            $userTablePrefix,
            $includeOutgoingForReview,
            $incidentId
        );
    } catch (Throwable $exception) {
        error_log(
            'eStab situation measurement failed: ' . $exception->getMessage()
        );
    }

    /*
     * Der Besetzungsbericht ist wie in der Einsatzverwaltung ein Bericht:
     * scheitert er, fehlt die Auskunft - nicht das Lagebild.
     */
    $staffing = null;
    try {
        $staffing = estab_dv_message_run_staffing(
            $connection,
            $incident,
            $incidentId
        );
    } catch (Throwable $exception) {
        error_log(
            'eStab situation staffing report failed: '
            . $exception->getMessage()
        );
    }

    $queues = [];
    foreach ($profiles as $profile) {
        $queues[] = [
            'key' => $profile['baseline_key'],
            'label' => $profile['label'],
            'short_label' => $profile['short_label'],
            'count' => $measurement === null
                ? null
                : ($measurement['queues'][$profile['baseline_key']] ?? null),
        ];
    }

    return [
        'incident' => [
            'command_post' => $commandPost,
            'kennung' => $code,
            'name' => $name,
            'beginn' => $incident['beginn'] ?? null,
            'ort' => trim((string) ($incident['ort'] ?? '')),
            'mode' => $mode,
        ],
        'identity' => [
            'funktion' => (string) ($selected['funktion'] ?? ''),
            'rolle' => (string) ($selected['rolle'] ?? ''),
        ],
        'queues' => $queues,
        'urgent_open' => $measurement === null
            ? null
            : $measurement['urgent_open'],
        'oldest_open_minutes' => $measurement === null
            ? null
            : $measurement['oldest_open_minutes'],
        'staffing' => $staffing,
        'measured' => $measurement !== null,
        'workspace_url' => estab_application_url('4fach/index.php'),
        'now' => $now ?? new DateTimeImmutable('now'),
    ];
}

/**
 * Die Einstiegsseite bekommt Verbindung, Messung und Aufräumen in einem Aufruf.
 *
 * @return array<string, mixed>|null
 */
function estab_situation_entry_snapshot(
    ?array $identity,
    array $databaseConfig,
    string $messageTable,
    string $userTablePrefix,
    bool $includeOutgoingForReview
): ?array {
    if ($identity === null) {
        return null;
    }
    $connection = null;
    try {
        $connection = estab_auth_connect($databaseConfig);
        return estab_situation_snapshot(
            $connection,
            $identity,
            $messageTable,
            $userTablePrefix,
            $includeOutgoingForReview
        );
    } catch (Throwable $exception) {
        error_log(
            'eStab situation overview failed: ' . $exception->getMessage()
        );
        return null;
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
}

/** Knappe Dauer in der Schreibweise der Führungsstelle. */
function estab_situation_duration_label(?int $minutes): string
{
    if ($minutes === null || $minutes < 0) {
        return '';
    }
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    return intdiv($minutes, 60) . ' h '
        . sprintf('%02d', $minutes % 60) . ' min';
}

/** Laufzeit des Einsatzes in vollen Minuten, oder null. */
function estab_situation_elapsed_minutes(
    mixed $start,
    DateTimeImmutable $now
): ?int {
    $parts = estab_datetime_parts($start);
    if ($parts['date'] === '') {
        return null;
    }
    try {
        $begin = new DateTimeImmutable(
            $parts['date'] . ' ' . $parts['time'],
            $now->getTimezone()
        );
    } catch (Throwable) {
        return null;
    }
    $minutes = intdiv($now->getTimestamp() - $begin->getTimestamp(), 60);
    return $minutes < 0 ? null : $minutes;
}

/**
 * Der eine Satz, der sagt, was jetzt zu tun ist.
 *
 * Vorrangverkehr schlägt die eigene Warteschlange, die eigene Warteschlange
 * schlägt die Ruhe. Eine nicht zustande gekommene Messung wird benannt statt
 * als Ruhe ausgegeben.
 *
 * @param array<string, mixed> $snapshot
 * @return array{state: string, text: string}
 */
function estab_situation_next_step(array $snapshot): array
{
    $queues = is_array($snapshot['queues'] ?? null)
        ? $snapshot['queues']
        : [];
    $waiting = 0;
    foreach ($queues as $queue) {
        $count = is_array($queue) ? ($queue['count'] ?? null) : null;
        if (is_int($count) && $count > 0) {
            $waiting += $count;
        }
    }
    $urgent = $snapshot['urgent_open'] ?? null;
    if (($snapshot['measured'] ?? false) !== true) {
        return [
            'state' => 'unbekannt',
            'text' => 'Die Warteschlangen konnten nicht gemessen werden. '
                . 'Öffnen Sie den Nachrichtenvordruck und sehen Sie die '
                . 'Liste selbst durch.',
        ];
    }
    if (is_int($urgent) && $urgent > 0) {
        return [
            'state' => 'vorrang',
            'text' => $urgent === 1
                ? 'Eine Nachricht mit Vorrang ist offen und wird vor allem '
                    . 'anderen bearbeitet.'
                : $urgent . ' Nachrichten mit Vorrang sind offen und werden '
                    . 'vor allem anderen bearbeitet.',
        ];
    }
    if ($waiting > 0) {
        return [
            'state' => 'arbeit',
            'text' => ($waiting === 1
                ? 'Eine Nachricht wartet'
                : $waiting . ' Nachrichten warten')
                . ' auf Ihre Funktion.',
        ];
    }
    return [
        'state' => 'ruhig',
        'text' => 'Auf Ihre Funktion wartet keine Nachricht.',
    ];
}

/**
 * Eine Warteschlangenkachel des Lagebilds.
 *
 * @param array<string, mixed> $queue
 */
function estab_situation_queue_markup(array $queue): string
{
    $key = estab_sidebar_queue_baseline_key(
        is_string($queue['key'] ?? null) ? $queue['key'] : ''
    );
    $label = is_string($queue['label'] ?? null) ? trim($queue['label']) : '';
    $shortLabel = is_string($queue['short_label'] ?? null)
        ? trim($queue['short_label'])
        : '';
    $count = $queue['count'] ?? null;
    if (
        $label === ''
        || strlen($label) > 80
        || preg_match('//u', $label) !== 1
        || $shortLabel === ''
        || strlen($shortLabel) > 40
        || preg_match('//u', $shortLabel) !== 1
        || ($count !== null && (!is_int($count) || $count < 0))
    ) {
        throw new InvalidArgumentException('Invalid situation queue entry');
    }
    $state = $count === null
        ? 'unavailable'
        : ($count > 0 ? 'has-work' : 'empty');
    $accessible = $label . ' ' . $shortLabel . ': '
        . ($count === null ? 'nicht verfügbar' : $count . ' wartend');

    return '<li class="estab-situation-queue estab-situation-queue-' . $state
        . '" data-estab-situation-queue="' . estab_auth_html($key) . '"'
        . ' data-estab-queue-state="' . $state . '"'
        . ' aria-label="' . estab_auth_html($accessible) . '">'
        . '<strong class="estab-situation-queue-count">'
        . estab_auth_html($count === null ? '–' : (string) $count)
        . '</strong>'
        . '<span class="estab-situation-queue-label">'
        . estab_auth_html($label) . '</span>'
        . '<small class="estab-situation-queue-function">'
        . estab_auth_html($shortLabel) . '</small>'
        . '</li>';
}

/**
 * Die Stationen des Nachrichtenlaufs mit ihrer Besetzung.
 *
 * @param array<string, mixed>|null $staffing
 */
function estab_situation_stations_markup(?array $staffing): string
{
    $unavailable = '<p class="estab-situation-empty">Die Besetzung des '
        . 'Nachrichtenlaufs konnte nicht ermittelt werden.</p>';
    $stations = is_array($staffing['stationen'] ?? null)
        ? $staffing['stationen']
        : [];
    $items = '';
    $missing = 0;
    foreach ($stations as $station) {
        if (
            !is_array($station)
            || !is_string($station['funktion'] ?? null)
            || !is_string($station['station'] ?? null)
        ) {
            throw new InvalidArgumentException('Invalid situation station');
        }
        $staffed = ($station['besetzt'] ?? null) === true;
        if (!$staffed) {
            $missing++;
        }
        $state = $staffed ? 'staffed' : 'open';
        $items .= '<li class="estab-situation-station'
            . ' estab-situation-station-' . $state . '"'
            . ' data-estab-station="' . estab_auth_html($station['funktion'])
            . '" data-estab-station-state="' . $state . '"'
            . ' title="' . estab_auth_html($station['station']) . '"'
            . ' aria-label="' . estab_auth_html(
                $station['station'] . ': '
                . ($staffed ? 'besetzt' : 'unbesetzt')
            ) . '">'
            . '<span class="estab-situation-station-name">'
            . estab_auth_html(
                estab_function_display_name($station['funktion'])
            ) . '</span>'
            . '<small class="estab-situation-station-state">'
            . ($staffed ? 'besetzt' : 'unbesetzt') . '</small>'
            . '</li>';
    }
    if ($items === '') {
        return $unavailable;
    }
    $note = $missing === 0
        ? 'Alle Stationen sind besetzt.'
        : 'Eine Nachricht, deren nächste Station unbesetzt ist, bleibt in '
            . 'ihrer Warteschlange liegen.';

    return '<ul class="estab-situation-stations"'
        . ' data-estab-message-run-staffing="'
        . ($missing === 0 ? 'complete' : 'incomplete') . '"'
        . ' aria-label="Stationen des Nachrichtenlaufs">' . $items . '</ul>'
        . '<p class="estab-situation-station-note">'
        . estab_auth_html($note) . '</p>';
}

/**
 * Das vollständige Lagebild als ein Block.
 *
 * @param array<string, mixed> $snapshot
 */
function estab_situation_markup(array $snapshot): string
{
    $incident = is_array($snapshot['incident'] ?? null)
        ? $snapshot['incident']
        : [];
    $commandPost = trim((string) ($incident['command_post'] ?? ''));
    $code = trim((string) ($incident['kennung'] ?? ''));
    $name = trim((string) ($incident['name'] ?? ''));
    $now = $snapshot['now'] ?? null;
    $workspaceUrl = $snapshot['workspace_url'] ?? null;
    if (
        $commandPost === ''
        || $code === ''
        || $name === ''
        || !$now instanceof DateTimeImmutable
        || !is_string($workspaceUrl)
        || $workspaceUrl === ''
    ) {
        throw new InvalidArgumentException('Invalid situation snapshot');
    }
    $mode = estab_permission_mode($incident['mode'] ?? null);
    $identity = is_array($snapshot['identity'] ?? null)
        ? $snapshot['identity']
        : [];
    $functionLabel = estab_function_identity_display_name(
        (string) ($identity['funktion'] ?? ''),
        (string) ($identity['rolle'] ?? '')
    );
    $start = estab_incident_ui_datetime($incident['beginn'] ?? null);
    $elapsed = estab_situation_duration_label(
        estab_situation_elapsed_minutes($incident['beginn'] ?? null, $now)
    );
    $since = $start === ''
        ? 'Beginn nicht hinterlegt'
        : 'seit ' . $start . ($elapsed === '' ? '' : ' · läuft ' . $elapsed);
    $place = trim((string) ($incident['ort'] ?? ''));

    $step = estab_situation_next_step($snapshot);
    $queues = is_array($snapshot['queues'] ?? null)
        ? $snapshot['queues']
        : [];
    if (count($queues) > ESTAB_SIDEBAR_MAX_QUEUES) {
        throw new InvalidArgumentException('Too many situation queues');
    }
    $queueItems = '';
    foreach ($queues as $queue) {
        if (!is_array($queue)) {
            throw new InvalidArgumentException('Invalid situation queue entry');
        }
        $queueItems .= estab_situation_queue_markup($queue);
    }
    $queueMarkup = $queueItems === ''
        ? '<p class="estab-situation-empty">Für Ihre Funktion führt eStab '
            . 'keine eigene Warteschlange. Die Meldungsübersicht zeigt den '
            . 'Nachrichtenverkehr des Einsatzes.</p>'
        : '<ul class="estab-situation-queues" data-estab-situation-queues'
            . ' aria-label="Warteschlangen Ihrer getragenen Funktionen">'
            . $queueItems . '</ul>';

    $urgent = $snapshot['urgent_open'] ?? null;
    $oldest = $snapshot['oldest_open_minutes'] ?? null;
    $urgentValue = is_int($urgent) ? (string) $urgent : '–';
    $oldestValue = '–';
    if (is_int($oldest)) {
        $oldestValue = estab_situation_duration_label($oldest);
    } elseif (($snapshot['measured'] ?? false) === true) {
        $oldestValue = 'keine offene';
    }

    return '<section class="estab-situation" data-estab-situation'
        . ' data-estab-situation-state="' . estab_auth_html($step['state'])
        . '" aria-labelledby="estab-situation-title">'
        . '<h1 class="estab-visually-hidden" id="estab-situation-title">'
        . 'Lagebild der Führungsstelle</h1>'
        . '<div class="estab-situation-incident"'
        . ' data-estab-permission-mode="' . estab_auth_html($mode) . '">'
        . '<strong class="estab-situation-post">'
        . estab_auth_html($commandPost) . '</strong>'
        . '<span class="estab-situation-code">Einsatz '
        . estab_auth_html($code) . ' · ' . estab_auth_html($name) . '</span>'
        . '<span class="estab-situation-mode">Modus '
        . estab_auth_html(estab_permission_mode_label($mode)) . '</span>'
        . '<span class="estab-situation-since">'
        . estab_auth_html($since) . '</span>'
        . ($place === ''
            ? ''
            : '<span class="estab-situation-place">'
                . estab_auth_html($place) . '</span>')
        . '</div>'
        . '<div class="estab-situation-work">'
        . '<div class="estab-situation-heading">'
        . '<h2>Ihre Arbeit</h2>'
        . '<p>' . estab_auth_html($functionLabel) . '</p>'
        . '</div>'
        . '<p class="estab-situation-step" role="status"'
        . ' data-estab-situation-step="' . estab_auth_html($step['state'])
        . '">' . estab_auth_html($step['text']) . '</p>'
        . '<a id="estab-open" class="estab-button estab-button-primary'
        . ' estab-situation-enter" href="' . estab_auth_html($workspaceUrl)
        . '" autofocus data-estab-situation-enter>Nachrichtenvordruck öffnen'
        . '<kbd class="estab-situation-key">Eingabe</kbd></a>'
        . $queueMarkup
        . '</div>'
        . '<div class="estab-situation-run">'
        . '<div class="estab-situation-heading">'
        . '<h2>Nachrichtenlauf</h2>'
        . '</div>'
        . estab_situation_stations_markup(
            is_array($snapshot['staffing'] ?? null)
                ? $snapshot['staffing']
                : null
        )
        . '<dl class="estab-situation-facts">'
        . '<div><dt>Vorrang offen</dt>'
        . '<dd data-estab-situation-urgent="' . estab_auth_html($urgentValue)
        . '">' . estab_auth_html($urgentValue) . '</dd></div>'
        . '<div><dt>Älteste offene Nachricht</dt>'
        . '<dd data-estab-situation-oldest>'
        . estab_auth_html($oldestValue) . '</dd></div>'
        . '</dl>'
        . '</div>'
        . '<p class="estab-situation-hint">Eingabetaste öffnet den '
        . 'Nachrichtenvordruck, die Tasten 1 bis 9 öffnen einen Bereich.</p>'
        . '</section>';
}

/**
 * Zifferntasten für die Bereiche, ohne etwas wegzunehmen.
 *
 * Die Verknüpfung steht am Bereich selbst; dieses Skript liest sie nur aus.
 * Ohne JavaScript bleibt jeder Bereich über seine Kachel erreichbar. Ein
 * Tastendruck in einem Eingabefeld gehört dem Eingabefeld.
 */
function estab_situation_shortcut_script(): string
{
    return '<script' . estab_csp_script_attribute() . ' data-estab-situation-shortcuts>'
        . '(function(){'
        . 'document.addEventListener("keydown",function(event){'
        . 'if(event.defaultPrevented||event.altKey||event.ctrlKey'
        . '||event.metaKey||event.shiftKey){return;}'
        . 'if(!/^[1-9]$/.test(event.key)){return;}'
        . 'var target=event.target;'
        . 'if(target&&(target.isContentEditable===true'
        . '||/^(?:INPUT|TEXTAREA|SELECT|BUTTON)$/.test('
        . 'String(target.tagName||"")))){return;}'
        . 'var area=document.querySelector('
        . '"[data-estab-shortcut=\'"+event.key+"\']");'
        . 'if(!area){return;}'
        . 'event.preventDefault();'
        . 'area.click();'
        . '});'
        . '})();'
        . '</script>';
}
