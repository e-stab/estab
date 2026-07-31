<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/message_repository.php';

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
    array $identity,
    ?DateTimeInterface $now = null
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
        if (
            !is_array($user)
            || !estab_auth_presence_has_session($user, $now)
        ) {
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
    $positions[] = ['rolle' => 'Fernmelder', 'fkt' => 'LdF'];
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

    if ($role === 'Fernmelder' && $function === 'LdF') {
        return [
            'session_key' => 'old_que_ldf',
            'sound_file' => 'notify_aw.wav',
            'label' => 'Bei LdF',
        ];
    }
    if ($role === 'Fernmelder' && $function === 'A/W') {
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
 * @return array{sql: string, parameters: list<int|string>}
 */
function estab_sidebar_queue_query(
    string $queueSessionKey,
    string $messageTable,
    string $userTablePrefix,
    string $function,
    bool $includeOutgoingForReview,
    int $incidentId
): array {
    $messages = estab_auth_table($messageTable);
    $incidentId = estab_incident_positive_id($incidentId);

    if ($queueSessionKey === 'old_que_ldf') {
        return [
            'sql' => 'SELECT COUNT(*) FROM ' . $messages
                . ' WHERE `einsatz_id` = ?'
                . ' AND `x00_status` = 1'
                . " AND `04_richtung` IN ('E','A')"
                . ' AND `02_zeit` IS NULL'
                . " AND `02_zeichen` = ''"
                . ' AND `03_datum` IS NULL'
                . " AND `03_zeichen` = ''"
                . ' AND ('
                . "(`04_richtung` = 'E'"
                . ' AND `15_quitdatum` IS NULL'
                . " AND `15_quitzeichen` = '')"
                . ' OR '
                . "(`04_richtung` = 'A'"
                . ' AND `15_quitdatum` IS NOT NULL'
                . " AND `15_quitzeichen` != '')"
                . ')'
                . " AND `x01_abschluss` = 'f'",
            'parameters' => [$incidentId],
        ];
    }

    if ($queueSessionKey === 'old_que_aw') {
        return [
            'sql' => 'SELECT COUNT(*) FROM ' . $messages
                . ' WHERE `einsatz_id` = ?'
                . ' AND `x00_status` = 2'
                . " AND `04_richtung` = 'A'"
                . ' AND `02_zeit` IS NOT NULL'
                . " AND `02_zeichen` != ''"
                . " AND `06_befwegausw` != ''"
                . ' AND `03_datum` IS NULL'
                . " AND `03_zeichen` = ''"
                . ' AND `15_quitdatum` IS NOT NULL'
                . " AND `15_quitzeichen` != ''"
                . " AND `x01_abschluss` = 'f'",
            'parameters' => [$incidentId],
        ];
    }

    if ($queueSessionKey === 'old_que_si') {
        /*
         * DV 1-101, Abschnitt 4.3: Jeder Ausgang wird vor der Übergabe an
         * LdF/A-W formal gesichtet.  Der historische Schalter bleibt nur in
         * der Signatur, damit bestehende Aufrufer kompatibel bleiben; er darf
         * den verbindlichen Prüfschritt nicht mehr abschalten.
         */
        unset($includeOutgoingForReview);

        return [
            'sql' => 'SELECT COUNT(*) FROM ' . $messages
                . ' WHERE `einsatz_id` = ?'
                . ' AND `x00_status` = 4'
                . ' AND `15_quitdatum` IS NULL'
                . " AND `15_quitzeichen` = ''"
                . " AND `04_richtung` IN ('E','A')",
            'parameters' => [$incidentId],
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
    $recipientPattern = estab_message_recipient_pattern($function);
    $allAccess = estab_message_staff_access_sql('all_messages');
    $doneAccess = estab_message_staff_access_sql('done_messages');

    return [
        'sql' => 'SELECT GREATEST(0,'
            . ' (SELECT COUNT(*) FROM ' . $messages . ' AS `all_messages`'
            . ' WHERE `all_messages`.`einsatz_id` = ?'
            . ' AND ' . $allAccess . ')'
            . ' -'
            . ' (SELECT COUNT(*) FROM ' . $messages . ' AS `done_messages`'
            . ' INNER JOIN ' . $doneTable . ' AS `done_state`'
            . ' ON `done_messages`.`00_lfd` = `done_state`.`nachnum`'
            . ' WHERE `done_messages`.`einsatz_id` = ?'
            . ' AND ' . $doneAccess . ')'
            . ')',
        'parameters' => [
            $incidentId,
            $recipientPattern,
            $function,
            $incidentId,
            $recipientPattern,
            $function,
        ],
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
    $incident = estab_incident_active($connection);
    if ($incident === null) {
        return 0;
    }
    $query = estab_sidebar_queue_query(
        $queueSessionKey,
        $messageTable,
        $userTablePrefix,
        $function,
        $includeOutgoingForReview,
        (int) $incident['active_einsatz_id']
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
            ['old_que_ldf', 'old_que_aw', 'old_que_si', 'old_que_stab'],
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
    $assignment = $identity['duty_assignment_id'] ?? null;
    if (
        !(
            (is_int($assignment) && $assignment > 0)
            || (
                is_string($assignment)
                && preg_match('/\A[1-9][0-9]{0,18}\z/D', $assignment) === 1
            )
        )
    ) {
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
    } elseif ($role === 'Fernmelder' && $function === 'LdF') {
        $actions[] = [
            'key' => 'ldf_nachrichten',
            'name' => 'ldf_nachrichten_x',
            'label' => 'Disposition',
            'description' => 'Rufnamen und Beförderungswege festlegen',
        ];
    } elseif ($role === 'Fernmelder' && $function === 'A/W') {
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

/** Render the server-selected duty hat without trusting request parameters. */
function estab_sidebar_active_hat_markup(
    array $session,
    ?array $identity
): string {
    if ($identity === null) {
        return '';
    }
    $assignment = $session['estab_duty_assignment_id'] ?? null;
    if (
        (!is_int($assignment) || $assignment < 1)
        && (
            !is_string($assignment)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $assignment) !== 1
        )
    ) {
        return '';
    }
    return '<aside class="estab-sidebar-duty-hat"'
        . ' data-estab-active-duty-hat'
        . ' data-estab-duty-assignment="' . estab_auth_html((string) $assignment) . '">'
        . '<span>Aktiver Funktions-Hut</span>'
        . '<strong>' . estab_auth_html(
            (string) $identity['funktion'] . ' · ' . (string) $identity['rolle']
        ) . '</strong>'
        . '<a href="' . estab_auth_html(
            estab_application_url('4fach/fuehrungsstelle.php')
        ) . '" target="_top">Dienstfunktion wechseln</a>'
        . '</aside>';
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
    string $freshnessState = 'current',
    string $incidentMarkup = ''
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
    $online = [];
    $inactive = [];
    $onlineUsers = 0;
    $currentPresence = 'expired';
    foreach ($users as $user) {
        if (
            !is_array($user)
            || !is_string($user['rolle'] ?? null)
            || !is_string($user['funktion'] ?? null)
        ) {
            continue;
        }
        $presence = estab_auth_presence_state($user, $now);
        if (!in_array($presence, ['online', 'inactive'], true)) {
            continue;
        }
        $isCurrentAccount = hash_equals(
            (string) $identity['kuerzel'],
            strtolower(trim((string) ($user['kuerzel'] ?? '')))
        );
        if ($isCurrentAccount) {
            $currentPresence = $presence;
        }
        $role = $isCurrentAccount
            ? (string) $identity['rolle']
            : trim($user['rolle']);
        $function = $isCurrentAccount
            ? (string) $identity['funktion']
            : trim($user['funktion']);
        if ($role === '' || $function === '') {
            continue;
        }
        $key = $role . "\0" . $function;
        if ($presence === 'online') {
            $online[$key] = ($online[$key] ?? 0) + 1;
            $onlineUsers++;
        } else {
            $inactive[$key] = ($inactive[$key] ?? 0) + 1;
        }
    }

    $currentKey = $identity['rolle'] . "\0" . $identity['funktion'];

    $chips = '';
    foreach (
        estab_sidebar_positions($configuredPositions, $users, $identity, $now)
        as $position
    ) {
        $role = $position['rolle'];
        $function = $position['funktion'];
        $key = $role . "\0" . $function;
        $onlineCount = $online[$key] ?? 0;
        $inactiveCount = $inactive[$key] ?? 0;
        $sessionCount = $onlineCount + $inactiveCount;
        $isCurrent = $key === $currentKey;
        $isMixed = $onlineCount > 0 && $inactiveCount > 0;
        $isCurrentInactive = $isCurrent && $currentPresence === 'inactive';
        $state = $isCurrent
            ? 'current'
            : ($onlineCount > 0
                ? 'online'
                : ($inactiveCount > 0 ? 'inactive' : 'offline'));
        $stateText = $isCurrent
            ? 'Ihre aktuelle Funktion, '
                . ($currentPresence === 'online' ? 'aktiv' : 'inaktiv')
            : ($isMixed
                ? $onlineCount . ' aktiv, ' . $inactiveCount . ' inaktiv'
                : ($onlineCount > 0
                ? 'aktiv'
                : ($inactiveCount > 0
                    ? 'seit mindestens 15 Minuten inaktiv'
                    : 'abgemeldet')));
        $display = $function === 'A/W' && $sessionCount > 0
            ? $sessionCount . ' A/W'
            : $function;
        $visiblePresenceNote = $isCurrentInactive
            ? 'Sie: inaktiv'
            : ($isMixed
                ? $onlineCount . ' aktiv · ' . $inactiveCount . ' inaktiv'
                : '');
        $accessible = $role . ', Funktion ' . $function . ': ' . $stateText;
        if ($sessionCount > 1 && $function !== 'A/W') {
            $accessible .= ', ' . $sessionCount . ' Personen';
        }

        $chips .= '<li class="estab-sidebar-presence'
            . ' estab-sidebar-presence-' . $state
            . ($isMixed ? ' estab-sidebar-presence-mixed' : '')
            . ($isCurrentInactive
                ? ' estab-sidebar-presence-current-inactive'
                : '')
            . ($visiblePresenceNote !== ''
                ? ' estab-sidebar-presence-has-note'
                : '')
            . '"'
            . ' data-estab-presence-state="' . $state . '"'
            . ' data-estab-presence-role="' . estab_auth_html($role) . '"'
            . ' data-estab-presence-function="'
            . estab_auth_html($function) . '"'
            . ($isCurrent
                ? ' data-estab-current-activity="'
                    . estab_auth_html($currentPresence) . '"'
                : '')
            . ' aria-label="' . estab_auth_html($accessible) . '">'
            . '<span class="estab-sidebar-presence-dot" aria-hidden="true"></span>'
            . '<span class="estab-sidebar-presence-label">'
            . estab_auth_html($display) . '</span>'
            . ($visiblePresenceNote === ''
                ? ''
                : '<small class="estab-sidebar-presence-note">'
                    . estab_auth_html($visiblePresenceNote) . '</small>')
            . '</li>';
    }

    $queueValue = $queueCount === null ? '–' : (string) $queueCount;
    $queueState = $queueCount === null
        ? 'unavailable'
        : ($queueCount > 0 ? 'has-work' : 'empty');
    $onlineText = $onlineUsers === 1
        ? '1 Person aktiv'
        : $onlineUsers . ' Personen aktiv';
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
        . $incidentMarkup
        . '<div class="estab-sidebar-freshness"'
        . ' data-estab-sidebar-freshness'
        . ' data-estab-status-freshness="' . $freshnessState . '"'
        . ' role="status" aria-live="polite">'
        . '<i aria-hidden="true"></i>'
        . '<span data-estab-sidebar-freshness-text>'
        . estab_auth_html($freshnessText) . '</span>'
        . '</div>'
        . '<div class="estab-sidebar-presence-heading">'
        . '<h2>Aktivitätsübersicht</h2>'
        . '<span data-estab-online-count="' . $onlineUsers . '">'
        . estab_auth_html($onlineText) . '</span>'
        . '</div>'
        . '<ul class="estab-sidebar-presence-grid"'
        . ' aria-label="Besetzung der Funktionen">' . $chips . '</ul>'
        . '<div class="estab-sidebar-presence-legend" aria-label="Legende">'
        . '<span><i class="online" aria-hidden="true"></i>Aktiv</span>'
        . '<span><i class="inactive" aria-hidden="true"></i>Inaktiv (15 Min.)</span>'
        . '<span><i class="current" aria-hidden="true"></i>Ihre Funktion</span>'
        . '<span><i class="offline" aria-hidden="true"></i>Abgemeldet</span>'
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
    $encodedLoginUrl = json_encode(
        estab_application_url('4fach/index.php')
            . '?login_flow=existing&interrupted=1',
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
        . 'var loginUrl=' . $encodedLoginUrl . ';'
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
        . 'if(response.status===401){try{window.top.location.assign(loginUrl);}'
        . 'catch(ignore){window.location.assign(loginUrl);}return false;}'
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
