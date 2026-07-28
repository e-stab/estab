<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Return the distinct configured functions in their established display order.
 *
 * Active and current functions are appended if a deployment's matrix is
 * temporarily incomplete, so the live status never hides a signed-in user.
 *
 * @return list<array{rolle: string, funktion: string}>
 */
function estab_sidebar_positions(
    array $configuredPositions,
    array $users,
    array $identity
): array {
    $positions = [];
    $seen = [];
    $append = static function (mixed $role, mixed $function) use (
        &$positions,
        &$seen
    ): void {
        if (
            !is_string($role)
            || !is_string($function)
            || trim($role) === ''
            || trim($function) === ''
            || strlen($role) > 80
            || strlen($function) > 40
            || preg_match('//u', $role) !== 1
            || preg_match('//u', $function) !== 1
        ) {
            return;
        }
        $role = trim($role);
        $function = trim($function);
        $key = $role . "\0" . $function;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $positions[] = [
            'rolle' => $role,
            'funktion' => $function,
        ];
    };

    foreach ($configuredPositions as $position) {
        if (!is_array($position)) {
            continue;
        }
        $append($position['rolle'] ?? null, $position['fkt'] ?? null);
    }
    foreach ($users as $user) {
        if (!is_array($user) || (int) ($user['aktiv'] ?? 0) !== 1) {
            continue;
        }
        $append($user['rolle'] ?? null, $user['funktion'] ?? null);
    }
    $append($identity['rolle'] ?? null, $identity['funktion'] ?? null);

    return $positions;
}

/**
 * Read the configured Stab/FB functions without the legacy include, whose
 * database errors terminate the complete sidebar request.
 *
 * @return list<array{rolle: string, fkt: string}>
 */
function estab_sidebar_fetch_configured_positions(
    mysqli $connection,
    string $matrixTable
): array {
    $statement = $connection->prepare(
        'SELECT `mtx_fkt`, `mtx_rolle` FROM '
        . estab_auth_table($matrixTable)
        . " WHERE `mtx_fkt` != ''"
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare sidebar matrix lookup');
    }

    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute sidebar matrix lookup');
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Could not read sidebar matrix lookup');
        }
        $staff = [];
        $advisers = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $function = is_string($row['mtx_fkt'] ?? null)
                ? trim($row['mtx_fkt'])
                : '';
            $role = is_string($row['mtx_rolle'] ?? null)
                ? trim($row['mtx_rolle'])
                : '';
            if (
                $function === ''
                || strlen($function) > 40
                || preg_match('//u', $function) !== 1
            ) {
                continue;
            }
            if ($role === 'Stab' && $function !== 'Si') {
                $staff[$function] = true;
            } elseif ($role === 'FB') {
                $advisers[$function] = true;
            }
        }
        $result->free();
    } finally {
        $statement->close();
    }

    $staffFunctions = array_keys($staff);
    $adviserFunctions = array_keys($advisers);
    sort($staffFunctions, SORT_STRING);
    sort($adviserFunctions, SORT_STRING);
    $positions = [];
    foreach ($staffFunctions as $function) {
        $positions[] = ['rolle' => 'Stab', 'fkt' => $function];
    }
    $positions[] = ['rolle' => 'Stab', 'fkt' => 'Si'];
    $positions[] = ['rolle' => 'Fernmelder', 'fkt' => 'A/W'];
    foreach ($adviserFunctions as $function) {
        $positions[] = ['rolle' => 'FB', 'fkt' => $function];
    }

    return $positions;
}

/**
 * Resolve the role-specific queue, label and portable notification sound.
 *
 * @return array{
 *     session_key: string,
 *     sound_file: string,
 *     label: string
 * }|null
 */
function estab_sidebar_queue_profile(?array $identity): ?array
{
    if ($identity === null) {
        return null;
    }
    $role = $identity['rolle'] ?? null;
    $function = $identity['funktion'] ?? null;
    if (!is_string($role) || !is_string($function)) {
        throw new InvalidArgumentException('Invalid sidebar queue identity');
    }

    if ($role === 'Fernmelder') {
        return [
            'session_key' => 'old_que_aw',
            'sound_file' => 'notify_aw.wav',
            'label' => 'Im Ausgang',
        ];
    }
    if ($role === 'Stab' && $function === 'Si') {
        return [
            'session_key' => 'old_que_si',
            'sound_file' => 'notify_si.wav',
            'label' => 'Zu sichten',
        ];
    }
    if ($role === 'Stab' || $role === 'FB') {
        return [
            'session_key' => 'old_que_stab',
            'sound_file' => 'notify_stab.wav',
            'label' => 'Offene Meldungen',
        ];
    }

    return null;
}

/**
 * Build the fixed, prepared queue-count query for one legacy role profile.
 *
 * @return array{sql: string, parameters: list<string>}
 */
function estab_sidebar_queue_query(
    string $queueSessionKey,
    string $messageTable,
    string $userTablePrefix,
    string $function,
    bool $includeOutgoingForReview
): array {
    $messages = estab_auth_table($messageTable);

    if ($queueSessionKey === 'old_que_aw') {
        return [
            'sql' => 'SELECT COUNT(*) FROM ' . $messages
                . " WHERE `04_richtung` = 'A'"
                . ' AND `03_datum` IS NULL'
                . " AND `03_zeichen` = ''",
            'parameters' => [],
        ];
    }

    if ($queueSessionKey === 'old_que_si') {
        $reviewScope = $includeOutgoingForReview
            ? "(`04_richtung` = 'E'"
                . " OR (`03_datum` IS NOT NULL AND `03_zeichen` != ''))"
            : "`04_richtung` = 'E'";

        return [
            'sql' => 'SELECT COUNT(*) FROM ' . $messages
                . ' WHERE `15_quitdatum` IS NULL'
                . " AND `15_quitzeichen` = ''"
                . ' AND (' . $reviewScope . ')',
            'parameters' => [],
        ];
    }

    if ($queueSessionKey !== 'old_que_stab') {
        throw new InvalidArgumentException('Invalid sidebar queue key');
    }
    if (
        preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $userTablePrefix) !== 1
        || strlen($userTablePrefix) > 40
        || preg_match('/\A[A-Za-z0-9_]{1,10}\z/D', $function) !== 1
    ) {
        throw new InvalidArgumentException('Invalid sidebar state table');
    }

    $doneTable = estab_auth_table(
        $userTablePrefix . '_fkt_' . strtolower($function) . '_erl'
    );
    $recipientPattern = '%' . $function . '%';

    return [
        'sql' => 'SELECT GREATEST(0,'
            . ' (SELECT COUNT(*) FROM ' . $messages . ' AS `all_messages`'
            . ' WHERE `all_messages`.`16_empf` LIKE ?)'
            . ' -'
            . ' (SELECT COUNT(*) FROM ' . $messages . ' AS `done_messages`'
            . ' INNER JOIN ' . $doneTable . ' AS `done_state`'
            . ' ON `done_messages`.`00_lfd` = `done_state`.`nachnum`'
            . ' WHERE `done_messages`.`16_empf` LIKE ?)'
            . ')',
        'parameters' => [$recipientPattern, $recipientPattern],
    ];
}

/**
 * Read one queue count without entering the legacy database layer, whose
 * query helpers terminate the complete request on an SQL error.
 */
function estab_sidebar_queue_count(
    mysqli $connection,
    string $queueSessionKey,
    string $messageTable,
    string $userTablePrefix,
    string $function,
    bool $includeOutgoingForReview
): int {
    $query = estab_sidebar_queue_query(
        $queueSessionKey,
        $messageTable,
        $userTablePrefix,
        $function,
        $includeOutgoingForReview
    );
    $statement = $connection->prepare($query['sql']);
    if (!$statement) {
        throw new RuntimeException('Could not prepare sidebar queue lookup');
    }

    try {
        if (!$statement->execute($query['parameters'])) {
            throw new RuntimeException('Could not execute sidebar queue lookup');
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Could not read sidebar queue lookup');
        }
        $row = $result->fetch_row();
        $result->free();
        $value = $row[0] ?? null;
        if (
            !is_int($value)
            && (
                !is_string($value)
                || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            )
        ) {
            throw new RuntimeException('Invalid sidebar queue lookup result');
        }
        $count = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]
        );
        if (!is_int($count)) {
            throw new RuntimeException('Sidebar queue count is out of range');
        }
        return $count;
    } finally {
        $statement->close();
    }
}

/**
 * Validate the internal WAV URL used by the long-lived sidebar audio player.
 */
function estab_sidebar_valid_audio_url(?string $url): ?string
{
    if ($url === null) {
        return null;
    }
    if (
        $url === ''
        || strlen($url) > 2048
        || preg_match('//u', $url) !== 1
        || preg_match('/[\x00-\x20]/', $url) === 1
        || str_starts_with($url, '//')
        || preg_match('#\.wav\z#Di', $url) !== 1
    ) {
        throw new InvalidArgumentException('Invalid sidebar audio URL');
    }

    foreach (
        ['notify_aw.wav', 'notify_si.wav', 'notify_stab.wav']
        as $soundFile
    ) {
        $allowedUrl = estab_application_url('4fach/audio/' . $soundFile);
        if (hash_equals($allowedUrl, $url)) {
            return $url;
        }
    }

    throw new InvalidArgumentException('Invalid sidebar audio URL');
}

/**
 * Advance one legacy queue baseline and return the sound URL exactly once
 * when a later successful measurement increases.
 */
function estab_sidebar_queue_notification(
    array &$session,
    ?string $queueSessionKey,
    ?int $queueCount,
    bool $soundsEnabled,
    ?string $soundUrl
): ?string {
    if ($queueSessionKey === null) {
        if ($queueCount !== null || $soundUrl !== null) {
            throw new InvalidArgumentException('Queue key required');
        }
        return null;
    }
    if (
        !in_array(
            $queueSessionKey,
            ['old_que_aw', 'old_que_si', 'old_que_stab'],
            true
        )
    ) {
        throw new InvalidArgumentException('Invalid sidebar queue key');
    }
    if ($queueCount === null) {
        return null;
    }
    if ($queueCount < 0) {
        throw new InvalidArgumentException('Invalid sidebar queue count');
    }

    $soundUrl = estab_sidebar_valid_audio_url($soundUrl);
    if ($soundsEnabled && $soundUrl === null) {
        throw new InvalidArgumentException('Enabled sidebar sound requires URL');
    }

    $previous = $session[$queueSessionKey] ?? null;
    $previousCount = null;
    if (is_int($previous) && $previous >= 0) {
        $previousCount = $previous;
    } elseif (
        is_string($previous)
        && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $previous) === 1
    ) {
        $previousCount = (int) $previous;
    }

    $session[$queueSessionKey] = $queueCount;
    if (
        !$soundsEnabled
        || $previousCount === null
        || $queueCount <= $previousCount
    ) {
        return null;
    }

    return $soundUrl;
}

/**
 * Render the persistent audio element outside the replaceable status fragment.
 */
function estab_sidebar_audio_markup(?string $soundUrl): string
{
    $soundUrl = estab_sidebar_valid_audio_url($soundUrl);
    if ($soundUrl === null) {
        return '';
    }

    return '<audio data-estab-sidebar-audio preload="auto"'
        . ' src="' . estab_auth_html($soundUrl) . '" aria-hidden="true"></audio>';
}

/**
 * Return the complete legacy role action set as semantic sidebar buttons.
 *
 * @return list<array{
 *     key: string,
 *     name: string,
 *     label: string,
 *     description: string
 * }>
 */
function estab_sidebar_workflow_actions(
    ?array $identity,
    mixed $menuState
): array {
    if ($identity === null || $menuState !== 'ROLLE') {
        return [];
    }
    $role = $identity['rolle'] ?? null;
    $function = $identity['funktion'] ?? null;
    if (!is_string($role) || !is_string($function)) {
        throw new InvalidArgumentException('Invalid sidebar workflow identity');
    }

    $actions = [];
    if ($role === 'Stab') {
        if ($function === 'Si') {
            $actions[] = [
                'key' => 'stab_sichten',
                'name' => 'stab_sichten_x',
                'label' => 'Sichten',
                'description' => 'Neue Meldungen prüfen',
            ];
            $actions[] = [
                'key' => 'si_admin',
                'name' => 'si_admin_x',
                'label' => '2. Sichtung',
                'description' => 'Zweite Sichtung öffnen',
            ];
        } else {
            $actions[] = [
                'key' => 'stab_schreiben',
                'name' => 'stab_schreiben_x',
                'label' => 'Schreiben',
                'description' => 'Neue Meldung verfassen',
            ];
            $actions[] = [
                'key' => 'stab_lesen',
                'name' => 'stab_lesen_x',
                'label' => 'Lesen',
                'description' => 'Meldungseingang anzeigen',
            ];
        }
    } elseif ($role === 'Fernmelder') {
        $actions[] = [
            'key' => 'fm_eingang',
            'name' => 'fm_eingang_x',
            'label' => 'Eingang',
            'description' => 'Eingehende Meldung erfassen',
        ];
        $actions[] = [
            'key' => 'fm_ausgang',
            'name' => 'fm_ausgang_x',
            'label' => 'Ausgang',
            'description' => 'Ausgehende Meldungen bearbeiten',
        ];
        $actions[] = [
            'key' => 'fm_admin',
            'name' => 'fm_admin_x',
            'label' => '2. Sichtung',
            'description' => 'Zweite Sichtung öffnen',
        ];
        $actions[] = [
            'key' => 'fm_anhang',
            'name' => 'fm_anhang_x',
            'label' => 'Anhänge',
            'description' => 'Dateien auswählen und hochladen',
        ];
    } elseif ($role === 'FB') {
        $actions[] = [
            'key' => 'stab_schreiben',
            'name' => 'stab_schreiben_x',
            'label' => 'Schreiben',
            'description' => 'Neue Meldung verfassen',
        ];
        $actions[] = [
            'key' => 'stab_lesen',
            'name' => 'stab_lesen_x',
            'label' => 'Lesen',
            'description' => 'Meldungseingang anzeigen',
        ];
    }

    $actions[] = [
        'key' => 'm2_benutzer',
        'name' => 'm2_benutzer_x',
        'label' => 'Benutzer',
        'description' => 'Konten und Anmeldestatus anzeigen',
    ];

    return $actions;
}

/**
 * Render queue, server time and online functions as one compact status card.
 */
function estab_sidebar_status_markup(
    array $session,
    array $configuredPositions,
    array $users,
    string $queueLabel,
    ?int $queueCount,
    ?DateTimeInterface $now = null,
    ?string $soundUrl = null,
    ?string $notificationSoundUrl = null,
    string $freshnessState = 'current'
): string {
    $identity = estab_auth_session_identity($session);
    if ($identity === null) {
        return '';
    }
    $queueLabel = trim($queueLabel);
    if (
        $queueLabel === ''
        || strlen($queueLabel) > 80
        || preg_match('//u', $queueLabel) !== 1
    ) {
        throw new InvalidArgumentException('Invalid sidebar queue label');
    }
    if ($queueCount !== null && $queueCount < 0) {
        throw new InvalidArgumentException('Invalid sidebar queue count');
    }
    if (
        !in_array(
            $freshnessState,
            ['current', 'partial', 'unavailable'],
            true
        )
    ) {
        throw new InvalidArgumentException('Invalid sidebar freshness state');
    }
    $soundUrl = estab_sidebar_valid_audio_url($soundUrl);
    $notificationSoundUrl = estab_sidebar_valid_audio_url(
        $notificationSoundUrl
    );
    if (
        $notificationSoundUrl !== null
        && ($soundUrl === null || !hash_equals($soundUrl, $notificationSoundUrl))
    ) {
        throw new InvalidArgumentException(
            'Sidebar notification URL does not match audio source'
        );
    }

    $now ??= new DateTimeImmutable('now');
    $active = [];
    $activeUsers = 0;
    foreach ($users as $user) {
        if (
            !is_array($user)
            || (int) ($user['aktiv'] ?? 0) !== 1
            || !is_string($user['rolle'] ?? null)
            || !is_string($user['funktion'] ?? null)
        ) {
            continue;
        }
        $role = trim($user['rolle']);
        $function = trim($user['funktion']);
        if ($role === '' || $function === '') {
            continue;
        }
        $key = $role . "\0" . $function;
        $active[$key] = ($active[$key] ?? 0) + 1;
        $activeUsers++;
    }

    $currentKey = $identity['rolle'] . "\0" . $identity['funktion'];
    if (($active[$currentKey] ?? 0) < 1) {
        $active[$currentKey] = 1;
        $activeUsers++;
    }

    $chips = '';
    foreach (
        estab_sidebar_positions($configuredPositions, $users, $identity)
        as $position
    ) {
        $role = $position['rolle'];
        $function = $position['funktion'];
        $key = $role . "\0" . $function;
        $count = $active[$key] ?? 0;
        $isCurrent = $key === $currentKey;
        $state = $isCurrent ? 'current' : ($count > 0 ? 'online' : 'offline');
        $stateText = $isCurrent
            ? 'Ihre aktuelle Funktion'
            : ($count > 0 ? 'online' : 'nicht besetzt');
        $display = $function === 'A/W' && $count > 0
            ? $count . ' A/W'
            : $function;
        $accessible = $role . ', Funktion ' . $function . ': ' . $stateText;
        if ($count > 1 && $function !== 'A/W') {
            $accessible .= ', ' . $count . ' Personen';
        }

        $chips .= '<li class="estab-sidebar-presence'
            . ' estab-sidebar-presence-' . $state . '"'
            . ' data-estab-presence-state="' . $state . '"'
            . ' data-estab-presence-role="' . estab_auth_html($role) . '"'
            . ' data-estab-presence-function="'
            . estab_auth_html($function) . '"'
            . ' aria-label="' . estab_auth_html($accessible) . '">'
            . '<span class="estab-sidebar-presence-dot" aria-hidden="true"></span>'
            . '<span>' . estab_auth_html($display) . '</span>'
            . '</li>';
    }

    $queueValue = $queueCount === null ? '–' : (string) $queueCount;
    $queueState = $queueCount === null
        ? 'unavailable'
        : ($queueCount > 0 ? 'has-work' : 'empty');
    $onlineText = $activeUsers === 1
        ? '1 Person online'
        : $activeUsers . ' Personen online';
    $machineTime = $now->format(DateTimeInterface::ATOM);
    $notify = $notificationSoundUrl === null ? '0' : '1';
    $notificationClass = $notificationSoundUrl === null
        ? ''
        : ' estab-sidebar-status-notification';
    $freshnessText = match ($freshnessState) {
        'partial' => 'Statusdaten unvollständig',
        'unavailable' => 'Statusdaten nicht verfügbar',
        default => 'Status aktuell',
    };
    $soundControl = $soundUrl === null
        ? ''
        : '<div class="estab-sidebar-sound-control">'
            . '<button class="estab-sidebar-sound-toggle" type="button"'
            . ' data-estab-sound-toggle'
            . ' data-estab-sound-url="' . estab_auth_html($soundUrl) . '"'
            . ' aria-pressed="false">'
            . '<span data-estab-sound-label>Hinweistöne aktivieren</span>'
            . '</button>'
            . '<span class="estab-sidebar-sound-feedback"'
            . ' data-estab-sound-feedback role="status"'
            . ' aria-live="polite">Hinweistöne sind ausgeschaltet.</span>'
            . '</div>';
    $notificationText = $notificationSoundUrl === null
        ? ''
        : '<span class="estab-visually-hidden"'
            . ' data-estab-queue-notification role="status">'
            . 'Neue Meldung in der Arbeitswarteschlange.'
            . '</span>';

    return '<section class="estab-sidebar-status'
        . $notificationClass . '"'
        . ' data-estab-status-data="' . $freshnessState . '"'
        . ' data-estab-notify="' . $notify . '"'
        . ' data-estab-sidebar-status aria-labelledby="estab-sidebar-status-title">'
        . '<div class="estab-sidebar-status-top">'
        . '<div class="estab-sidebar-queue ' . $queueState . '"'
        . ' data-estab-queue-state="' . $queueState . '">'
        . '<span class="estab-sidebar-eyebrow" id="estab-sidebar-status-title">'
        . estab_auth_html($queueLabel) . '</span>'
        . '<strong data-estab-queue-count aria-live="polite">'
        . estab_auth_html($queueValue) . '</strong>'
        . '</div>'
        . '<time class="estab-sidebar-time" datetime="'
        . estab_auth_html($machineTime) . '">'
        . '<strong>' . estab_auth_html($now->format('H:i')) . '</strong>'
        . '<span>' . estab_auth_html($now->format('d.m.Y')) . '</span>'
        . '</time>'
        . '</div>'
        . '<div class="estab-sidebar-freshness"'
        . ' data-estab-sidebar-freshness'
        . ' data-estab-status-freshness="' . $freshnessState . '"'
        . ' role="status" aria-live="polite">'
        . '<i aria-hidden="true"></i>'
        . '<span data-estab-sidebar-freshness-text>'
        . estab_auth_html($freshnessText) . '</span>'
        . '</div>'
        . '<div class="estab-sidebar-presence-heading">'
        . '<h2>Online-Übersicht</h2>'
        . '<span data-estab-online-count="' . $activeUsers . '">'
        . estab_auth_html($onlineText) . '</span>'
        . '</div>'
        . '<ul class="estab-sidebar-presence-grid"'
        . ' aria-label="Besetzung der Funktionen">' . $chips . '</ul>'
        . '<div class="estab-sidebar-presence-legend" aria-label="Legende">'
        . '<span><i class="online" aria-hidden="true"></i>Online</span>'
        . '<span><i class="current" aria-hidden="true"></i>Ihre Funktion</span>'
        . '<span><i class="offline" aria-hidden="true"></i>Unbesetzt</span>'
        . '</div>'
        . $soundControl
        . $notificationText
        . '</section>';
}

/**
 * Poll only the live status fragment, preserving sidebar focus and scroll.
 */
function estab_sidebar_status_refresh_script(
    string $statusUrl,
    int $intervalSeconds
): string {
    if ($statusUrl === '' || preg_match('//u', $statusUrl) !== 1) {
        throw new InvalidArgumentException('Invalid sidebar status URL');
    }
    $intervalSeconds = max(5, min(300, $intervalSeconds));
    $timeoutMilliseconds = min(
        15000,
        max(4000, ($intervalSeconds * 1000) - 500)
    );
    $encodedUrl = json_encode(
        $statusUrl,
        JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );

    return '<script data-estab-sidebar-refresh data-refresh-seconds="'
        . $intervalSeconds . '" data-timeout-ms="'
        . $timeoutMilliseconds . '">'
        . '(function(){'
        . 'var busy=false;'
        . 'var storageKey="estab.sidebar.sounds";'
        . 'var soundsEnabled=false;'
        . 'var soundState="inactive";'
        . 'var soundMessage="";'
        . 'var soundGeneration=0;'
        . 'var lastSuccessfulRefresh=new Date();'
        . 'try{soundsEnabled=localStorage.getItem(storageKey)==="on";}'
        . 'catch(ignore){soundsEnabled=false;}'
        . 'soundState=soundsEnabled?"blocked":"inactive";'
        . 'soundMessage=soundsEnabled'
        . '?"Hinweistöne in diesem Tab erneut freigeben: aus- und wieder einschalten.":'
        . '"Hinweistöne sind ausgeschaltet.";'
        . 'function audioPlayer(){'
        . 'return document.querySelector("audio[data-estab-sidebar-audio]");'
        . '}'
        . 'function storeSoundPreference(){'
        . 'try{localStorage.setItem(storageKey,soundsEnabled?"on":"off");}'
        . 'catch(ignore){}'
        . '}'
        . 'function syncSoundControl(){'
        . 'var button=document.querySelector("[data-estab-sound-toggle]");'
        . 'var feedback=document.querySelector("[data-estab-sound-feedback]");'
        . 'if(!button){return;}'
        . 'button.setAttribute("aria-pressed",soundsEnabled?"true":"false");'
        . 'button.setAttribute("data-estab-sound-state",soundState);'
        . 'var label=button.querySelector("[data-estab-sound-label]");'
        . 'if(label){'
        . 'label.textContent=soundState==="checking"?"Aktivierung abbrechen":'
        . 'soundState==="blocked"?"Hinweistöne ausschalten":'
        . 'soundState==="unsupported"?"Ton erneut testen":'
        . 'soundsEnabled?"Hinweistöne aktiv":"Hinweistöne aktivieren";'
        . '}'
        . 'if(feedback){feedback.textContent=soundMessage;}'
        . '}'
        . 'async function playSound(testMode){'
        . 'var player=audioPlayer();'
        . 'if(!player){return false;}'
        . 'var generation=++soundGeneration;'
        . 'soundState="checking";syncSoundControl();'
        . 'try{'
        . 'player.pause();player.currentTime=0;'
        . 'await player.play();'
        . 'if(generation!==soundGeneration||!soundsEnabled){'
        . 'player.pause();player.currentTime=0;return false;'
        . '}'
        . 'soundsEnabled=true;soundState="ready";'
        . 'soundMessage=testMode'
        . '?"Hinweistöne sind aktiviert.":'
        . '"Akustischer Hinweis abgespielt.";'
        . 'storeSoundPreference();syncSoundControl();return true;'
        . '}catch(error){'
        . 'if(generation!==soundGeneration||!soundsEnabled){return false;}'
        . 'if(error&&error.name==="NotSupportedError"){'
        . 'soundsEnabled=false;soundState="unsupported";'
        . 'soundMessage="Der Browser unterstützt die Audiodatei nicht.";'
        . '}else{'
        . 'soundsEnabled=true;soundState="blocked";'
        . 'soundMessage="Der Browser blockiert den Ton. Zum erneuten Freigeben aus- und wieder einschalten.";'
        . '}'
        . 'storeSoundPreference();syncSoundControl();return false;'
        . '}'
        . '}'
        . 'document.addEventListener("click",function(event){'
        . 'var target=event.target;'
        . 'if(!(target instanceof Element)){return;}'
        . 'var button=target.closest("[data-estab-sound-toggle]");'
        . 'if(!button){return;}'
        . 'if(soundsEnabled){'
        . 'soundGeneration+=1;'
        . 'var player=audioPlayer();'
        . 'if(player){player.pause();player.currentTime=0;}'
        . 'soundsEnabled=false;soundState="inactive";'
        . 'soundMessage="Hinweistöne sind ausgeschaltet.";'
        . 'storeSoundPreference();syncSoundControl();return;'
        . '}'
        . 'soundsEnabled=true;storeSoundPreference();playSound(true);'
        . '});'
        . 'window.addEventListener("storage",function(event){'
        . 'if(event.key!==storageKey){return;}'
        . 'soundGeneration+=1;'
        . 'soundsEnabled=event.newValue==="on";'
        . 'soundState=soundsEnabled?"blocked":"inactive";'
        . 'soundMessage=soundsEnabled'
        . '?"Hinweistöne in diesem Tab erneut freigeben: aus- und wieder einschalten.":'
        . '"Hinweistöne sind ausgeschaltet.";'
        . 'if(!soundsEnabled){'
        . 'var player=audioPlayer();'
        . 'if(player){player.pause();player.currentTime=0;}'
        . '}'
        . 'syncSoundControl();'
        . '});'
        . 'function staleTimestamp(){'
        . 'var hours=String(lastSuccessfulRefresh.getHours()).padStart(2,"0");'
        . 'var minutes=String(lastSuccessfulRefresh.getMinutes()).padStart(2,"0");'
        . 'return hours+":"+minutes;'
        . '}'
        . 'function markStatusStale(){'
        . 'var status=document.querySelector("[data-estab-sidebar-status]");'
        . 'if(!status){return;}'
        . 'status.classList.add("estab-sidebar-status-stale");'
        . 'var freshness=status.querySelector('
        . '"[data-estab-sidebar-freshness]");'
        . 'if(!freshness){return;}'
        . 'if(freshness.getAttribute('
        . '"data-estab-status-freshness")==="stale"){return;}'
        . 'freshness.setAttribute("data-estab-status-freshness","stale");'
        . 'var text=freshness.querySelector('
        . '"[data-estab-sidebar-freshness-text]");'
        . 'if(text){text.textContent="Status nicht aktuell · letzter Abruf "'
        . '+staleTimestamp();}'
        . '}'
        . 'function preserveRefreshState(current,fresh){'
        . 'var currentSound=current.querySelector('
        . '".estab-sidebar-sound-control");'
        . 'var freshSound=fresh.querySelector('
        . '".estab-sidebar-sound-control");'
        . 'var soundPreserved=false;'
        . 'if(currentSound&&freshSound){'
        . 'freshSound.replaceWith(currentSound);soundPreserved=true;'
        . '}'
        . 'var currentFreshness=current.querySelector('
        . '"[data-estab-sidebar-freshness]");'
        . 'var freshFreshness=fresh.querySelector('
        . '"[data-estab-sidebar-freshness]");'
        . 'if(currentFreshness&&freshFreshness){'
        . 'var currentFreshnessState=currentFreshness.getAttribute('
        . '"data-estab-status-freshness");'
        . 'var freshFreshnessState=freshFreshness.getAttribute('
        . '"data-estab-status-freshness");'
        . 'if(currentFreshnessState===freshFreshnessState){'
        . 'freshFreshness.replaceWith(currentFreshness);'
        . '}else if(freshFreshnessState==="current"){'
        . 'currentFreshness.setAttribute('
        . '"data-estab-status-freshness","current");'
        . 'var freshnessText=currentFreshness.querySelector('
        . '"[data-estab-sidebar-freshness-text]");'
        . 'if(freshnessText){freshnessText.textContent="Status wieder aktuell";}'
        . 'freshFreshness.replaceWith(currentFreshness);'
        . '}'
        . '}'
        . 'var currentQueue=current.querySelector("[data-estab-queue-count]");'
        . 'var freshQueue=fresh.querySelector("[data-estab-queue-count]");'
        . 'if(currentQueue&&freshQueue'
        . '&&currentQueue.textContent===freshQueue.textContent){'
        . 'freshQueue.replaceWith(currentQueue);'
        . '}'
        . 'return soundPreserved;'
        . '}'
        . 'async function refresh(){'
        . 'if(busy){return false;}busy=true;'
        . 'var controller=new AbortController();'
        . 'var timeout=window.setTimeout(function(){controller.abort();},'
        . $timeoutMilliseconds . ');'
        . 'try{var response=await fetch(' . $encodedUrl . ',{'
        . 'credentials:"same-origin",cache:"no-store",'
        . 'headers:{"X-Requested-With":"eStab-Sidebar"},'
        . 'signal:controller.signal});'
        . 'if(!response.ok){markStatusStale();return false;}'
        . 'var html=await response.text();'
        . 'var parsed=new DOMParser().parseFromString(html,"text/html");'
        . 'var fresh=parsed.querySelector("[data-estab-sidebar-status]");'
        . 'var current=document.querySelector("[data-estab-sidebar-status]");'
        . 'if(!fresh||!current){markStatusStale();return false;}'
        . 'var notify=fresh.getAttribute("data-estab-notify")==="1";'
        . 'var restoreSoundFocus='
        . 'document.activeElement==='
        . 'current.querySelector("[data-estab-sound-toggle]");'
        . 'var soundPreserved=preserveRefreshState(current,fresh);'
        . 'current.replaceWith(fresh);'
        . 'if(!soundPreserved){syncSoundControl();}'
        . 'if(restoreSoundFocus){'
        . 'var restoredButton=fresh.querySelector("[data-estab-sound-toggle]");'
        . 'if(restoredButton){restoredButton.focus({preventScroll:true});}'
        . '}'
        . 'lastSuccessfulRefresh=new Date();'
        . 'if(notify&&soundsEnabled){playSound(false);}'
        . 'return true;'
        . '}catch(ignore){markStatusStale();return false;}'
        . 'finally{window.clearTimeout(timeout);busy=false;}}'
        . 'window.estabRefreshSidebarStatus=refresh;'
        . 'window.estabMarkSidebarStatusStale=markStatusStale;'
        . 'window.estabPlaySidebarNotification=function(){'
        . 'return playSound(false);'
        . '};'
        . 'window.estabSidebarSoundState=function(){'
        . 'return {enabled:soundsEnabled,state:soundState,'
        . 'message:soundMessage,generation:soundGeneration};'
        . '};'
        . 'syncSoundControl();'
        . 'var initialStatus=document.querySelector('
        . '"[data-estab-sidebar-status]");'
        . 'if(initialStatus'
        . '&&initialStatus.getAttribute("data-estab-notify")==="1"'
        . '&&soundsEnabled){playSound(false);}'
        . 'window.setInterval(function(){'
        . 'if(!document.hidden){refresh();}'
        . '},' . ($intervalSeconds * 1000) . ');'
        . '})();'
        . '</script>';
}
