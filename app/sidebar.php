<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/message_repository.php';

/**
 * Return the distinct configured functions in their established display order.
 *
 * Primary account functions with a live session are appended if a
 * deployment's matrix is temporarily incomplete. Selected STRICT duty hats
 * and LOOSE additional functions are intentionally not inferred here.
 *
 * @return list<array{rolle: string, funktion: string}>
 */
function estab_sidebar_positions(
    array $configuredPositions,
    array $users,
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

/** Upper bound of queues one status fragment measures and renders. */
const ESTAB_SIDEBAR_MAX_QUEUES = 16;

/**
 * Resolve one queue profile per function the account actually wears.
 *
 * DV 1-101 lets one person occupy several stations of the message run in a
 * small command post. Every worn function therefore needs its own queue: a
 * single ranked profile hides the traffic of all further functions. The order
 * stays LdF, A/W, Si and then the staff functions, so the first entry remains
 * the primary display and its notification sound. The baseline key separates
 * the staff queues per function; the session key keeps naming the query shape.
 *
 * @return list<array{
 *     session_key: string,
 *     baseline_key: string,
 *     sound_file: string,
 *     label: string,
 *     short_label: string,
 *     funktion: string,
 *     rolle: string
 * }>
 */
function estab_sidebar_queue_profiles(?array $identity): array
{
    if ($identity === null) {
        return [];
    }
    $functions = estab_auth_effective_function_roles($identity);
    if ($functions === []) {
        throw new InvalidArgumentException('Invalid sidebar queue identity');
    }
    $wears = static function (string $role, string $function) use (
        $functions
    ): bool {
        foreach ($functions as $tuple) {
            if (
                hash_equals($role, $tuple['rolle'])
                && hash_equals($function, $tuple['funktion'])
            ) {
                return true;
            }
        }
        return false;
    };

    $profiles = [];
    $baselineKeys = [];
    $append = static function (array $profile) use (
        &$profiles,
        &$baselineKeys
    ): void {
        if (isset($baselineKeys[$profile['baseline_key']])) {
            return;
        }
        $baselineKeys[$profile['baseline_key']] = true;
        $profiles[] = $profile;
    };

    if ($wears('Fernmelder', 'LdF')) {
        $append([
            'session_key' => 'old_que_ldf',
            'baseline_key' => 'old_que_ldf',
            'sound_file' => 'notify_aw.wav',
            'label' => 'Bei LdF',
            'short_label' => 'LdF',
            'funktion' => 'LdF',
            'rolle' => 'Fernmelder',
        ]);
    }
    if ($wears('Fernmelder', 'A/W')) {
        $append([
            'session_key' => 'old_que_aw',
            'baseline_key' => 'old_que_aw',
            'sound_file' => 'notify_aw.wav',
            'label' => 'Im Ausgang',
            'short_label' => 'Ausgang',
            'funktion' => 'A/W',
            'rolle' => 'Fernmelder',
        ]);
    }
    if ($wears('Stab', 'Si')) {
        $append([
            'session_key' => 'old_que_si',
            'baseline_key' => 'old_que_si',
            'sound_file' => 'notify_si.wav',
            'label' => 'Zu sichten',
            'short_label' => 'Sichtung',
            'funktion' => 'Si',
            'rolle' => 'Stab',
        ]);
    }
    foreach ($functions as $tuple) {
        if (
            !estab_auth_has_staff_message_workspace(
                $tuple['funktion'],
                $tuple['rolle']
            )
            || preg_match('/\A[A-Za-z0-9_]{1,10}\z/D', $tuple['funktion'])
                !== 1
        ) {
            continue;
        }
        $append([
            'session_key' => 'old_que_stab',
            'baseline_key' => 'old_que_stab_' . strtolower($tuple['funktion']),
            'sound_file' => 'notify_stab.wav',
            'label' => 'Offene Meldungen',
            'short_label' => $tuple['funktion'],
            'funktion' => $tuple['funktion'],
            'rolle' => $tuple['rolle'],
        ]);
    }

    // The status fragment renders one large counter plus a strip of flat
    // rows. Beyond that budget the sidebar would throw while rendering and
    // take the whole navigation frame down, so the measurement stops here
    // instead: a legible partial list beats a dead sidebar.
    return array_slice($profiles, 0, ESTAB_SIDEBAR_MAX_QUEUES);
}

/**
 * Resolve one correction queue per staff function the account actually wears.
 *
 * A formally returned outgoing message waits for its author, not for a
 * station of the message run. It therefore needs its own measurement, in the
 * same shape the queue batch already understands, so that the sidebar can
 * offer the correction loop without a second database round trip.
 *
 * @return list<array{
 *     session_key: string,
 *     baseline_key: string,
 *     funktion: string,
 *     rolle: string
 * }>
 */
function estab_sidebar_correction_profiles(?array $identity): array
{
    if ($identity === null) {
        return [];
    }
    $profiles = [];
    $seen = [];
    foreach (estab_auth_effective_function_roles($identity) as $tuple) {
        if (
            !estab_auth_has_staff_message_workspace(
                $tuple['funktion'],
                $tuple['rolle']
            )
            || preg_match('/\A[A-Za-z0-9_]{1,10}\z/D', $tuple['funktion'])
                !== 1
        ) {
            continue;
        }
        $key = 'old_que_korr_' . strtolower($tuple['funktion']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $profiles[] = [
            'session_key' => 'old_que_korr',
            'baseline_key' => $key,
            'funktion' => $tuple['funktion'],
            'rolle' => $tuple['rolle'],
        ];
    }

    return array_slice($profiles, 0, ESTAB_SIDEBAR_MAX_QUEUES);
}

/**
 * Resolve the primary queue profile in the established legacy shape.
 *
 * @return array{
 *     session_key: string,
 *     sound_file: string,
 *     label: string,
 *     funktion: string
 * }|null
 */
function estab_sidebar_queue_profile(?array $identity): ?array
{
    $profiles = estab_sidebar_queue_profiles($identity);
    if ($profiles === []) {
        return null;
    }

    return [
        'session_key' => $profiles[0]['session_key'],
        'sound_file' => $profiles[0]['sound_file'],
        'label' => $profiles[0]['label'],
        'funktion' => $profiles[0]['funktion'],
    ];
}

/**
 * Validate one queue baseline key, including the per-function staff keys.
 *
 * Two staff functions share the query shape but never the baseline: a common
 * key would let the S2 measurement overwrite the S1 one and silence the tone.
 */
function estab_sidebar_queue_baseline_key(string $key): string
{
    if (
        !in_array(
            $key,
            [
                'old_que_ldf',
                'old_que_aw',
                'old_que_si',
                'old_que_stab',
                'old_que_korr',
            ],
            true
        )
        && preg_match(
            '/\Aold_que_(?:stab|korr)_[a-z0-9_]{1,10}\z/D',
            $key
        ) !== 1
    ) {
        throw new InvalidArgumentException('Invalid sidebar queue key');
    }

    return $key;
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
                . " AND `01_medium` != ''"
                . " AND `06_befweg` != ''"
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

    if ($queueSessionKey === 'old_que_korr') {
        if (preg_match('/\A[A-Za-z0-9_]{1,10}\z/D', $function) !== 1) {
            throw new InvalidArgumentException('Invalid sidebar queue function');
        }

        /*
         * Dieselben Merkmale, die 4fach/liste.php als Korrekturwarteschlange
         * anzeigt: formal zurueckgewiesener Ausgang der eigenen Funktion, vom
         * Sichter quittiert, noch ohne Annahme und noch nicht abgeschlossen.
         * Der Funktionsvergleich ist BINARY, weil estab_message_object_allowed()
         * die Zeilen der Liste anschliessend exakt vergleicht; ein loser
         * Kollationsvergleich meldete sonst mehr, als die Liste zeigt.
         */
        return [
            'sql' => 'SELECT COUNT(*) FROM ' . $messages
                . ' WHERE `einsatz_id` = ?'
                . ' AND `x00_status` = 10'
                . " AND `04_richtung` = 'A'"
                . ' AND BINARY `14_funktion` = BINARY ?'
                . " AND `14_zeichen` != ''"
                . ' AND `02_zeit` IS NULL'
                . " AND `02_zeichen` = ''"
                . ' AND `03_datum` IS NULL'
                . " AND `03_zeichen` = ''"
                . ' AND `15_quitdatum` IS NOT NULL'
                . " AND `15_quitzeichen` != ''"
                . " AND `x01_abschluss` = 'f'",
            'parameters' => [$incidentId, $function],
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
 * Build one prepared statement that measures every worn function's queue.
 *
 * A second or third function must not cost a second or third database round
 * trip: each profile contributes one scalar sub-select column to the same
 * statement, and the parameters follow the column order.
 *
 * The shape is re-checked at runtime instead of being trusted: this is the
 * boundary between the session-derived profile list and a prepared statement.
 *
 * @param list<array<string, mixed>> $profiles
 * @return array{sql: string, parameters: list<int|string>, keys: list<string>}
 */
function estab_sidebar_queue_batch_query(
    array $profiles,
    string $messageTable,
    string $userTablePrefix,
    bool $includeOutgoingForReview,
    int $incidentId
): array {
    if ($profiles === [] || count($profiles) > ESTAB_SIDEBAR_MAX_QUEUES) {
        throw new InvalidArgumentException('Invalid sidebar queue profile set');
    }
    $incidentId = estab_incident_positive_id($incidentId);
    $columns = [];
    $parameters = [];
    $keys = [];
    foreach ($profiles as $index => $profile) {
        if (
            !is_string($profile['session_key'] ?? null)
            || !is_string($profile['baseline_key'] ?? null)
            || !is_string($profile['funktion'] ?? null)
        ) {
            throw new InvalidArgumentException('Invalid sidebar queue profile');
        }
        $key = estab_sidebar_queue_baseline_key($profile['baseline_key']);
        if (in_array($key, $keys, true)) {
            throw new InvalidArgumentException('Duplicate sidebar queue key');
        }
        $query = estab_sidebar_queue_query(
            $profile['session_key'],
            $messageTable,
            $userTablePrefix,
            $profile['funktion'],
            $includeOutgoingForReview,
            $incidentId
        );
        $columns[] = '(' . $query['sql'] . ') AS `queue_' . $index . '`';
        foreach ($query['parameters'] as $parameter) {
            $parameters[] = $parameter;
        }
        $keys[] = $key;
    }

    return [
        'sql' => 'SELECT ' . implode(', ', $columns),
        'parameters' => $parameters,
        'keys' => $keys,
    ];
}

/** Accept only a non-negative integer measurement from the database. */
function estab_sidebar_queue_value(mixed $value): int
{
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
}

/**
 * Read every worn function's queue count without entering the legacy database
 * layer, whose query helpers terminate the complete request on an SQL error.
 *
 * @param list<array{
 *     session_key: string,
 *     baseline_key: string,
 *     funktion: string
 * }> $profiles
 * @return array<string, int> baseline key => waiting messages
 */
function estab_sidebar_queue_counts(
    mysqli $connection,
    array $profiles,
    string $messageTable,
    string $userTablePrefix,
    bool $includeOutgoingForReview,
    int $incidentId
): array {
    if ($profiles === []) {
        return [];
    }
    $query = estab_sidebar_queue_batch_query(
        $profiles,
        $messageTable,
        $userTablePrefix,
        $includeOutgoingForReview,
        $incidentId
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
        if (!is_array($row) || count($row) !== count($query['keys'])) {
            throw new RuntimeException('Invalid sidebar queue lookup result');
        }
        $counts = [];
        foreach ($query['keys'] as $index => $key) {
            $counts[$key] = estab_sidebar_queue_value($row[$index]);
        }
        return $counts;
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
    estab_sidebar_queue_baseline_key($queueSessionKey);
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
 * Advance every worn function's baseline and signal once when any of them
 * grew.
 *
 * The loop never stops at the first increase: a queue whose baseline is not
 * advanced would report the same growth again at the next poll.
 *
 * The shape is re-checked at runtime instead of being trusted: the baselines
 * are written back into the session.
 *
 * @param list<array<string, mixed>> $measurements
 */
function estab_sidebar_queue_notifications(
    array &$session,
    array $measurements,
    bool $soundsEnabled,
    ?string $soundUrl
): ?string {
    $signal = null;
    $seen = [];
    foreach ($measurements as $measurement) {
        if (
            !is_string($measurement['baseline_key'] ?? null)
            || (
                ($measurement['count'] ?? null) !== null
                && !is_int($measurement['count'])
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid sidebar queue measurement'
            );
        }
        $key = estab_sidebar_queue_baseline_key($measurement['baseline_key']);
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('Duplicate sidebar queue key');
        }
        $seen[$key] = true;
        $sound = estab_sidebar_queue_notification(
            $session,
            $key,
            $measurement['count'] ?? null,
            $soundsEnabled,
            $soundUrl
        );
        if ($sound !== null && $signal === null) {
            $signal = $sound;
        }
    }

    return $signal;
}

/**
 * Return the duty functions one account has actually accepted, or null when
 * the overview is not backed by an active duty shift.
 *
 * @return list<array{rolle: string, funktion: string}>|null
 */
function estab_sidebar_duty_functions_for_account(
    array $dutyFunctions,
    mixed $userCode
): ?array {
    if ($dutyFunctions === []) {
        return null;
    }
    if (!is_string($userCode)) {
        return [];
    }
    $assigned = $dutyFunctions[strtolower(trim($userCode))] ?? null;
    if (!is_array($assigned)) {
        return [];
    }
    $tuples = [];
    $seen = [];
    foreach ($assigned as $tuple) {
        if (
            !is_array($tuple)
            || !is_string($tuple['rolle'] ?? null)
            || !is_string($tuple['funktion'] ?? null)
        ) {
            continue;
        }
        $role = trim($tuple['rolle']);
        $function = trim($tuple['funktion']);
        if (
            $role === ''
            || $function === ''
            || strlen($role) > 80
            || strlen($function) > 40
            || preg_match('//u', $role) !== 1
            || preg_match('//u', $function) !== 1
            || isset($seen[$role . "\0" . $function])
        ) {
            continue;
        }
        $seen[$role . "\0" . $function] = true;
        $tuples[] = ['rolle' => $role, 'funktion' => $function];
    }

    return $tuples;
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
 * $correctionCounts maps one worn staff function to its number of formally
 * returned messages. The correction loop only ever appears with work behind
 * it, so an empty map keeps the established action set unchanged.
 *
 * @param array<string, int> $correctionCounts
 * @return list<array{
 *     key: string,
 *     name: string,
 *     label: string,
 *     description: string,
 *     acting_function?: string,
 *     badge?: string
 * }>
 */
function estab_sidebar_workflow_actions(
    ?array $identity,
    mixed $menuState,
    array $correctionCounts = []
): array {
    if ($identity === null || $menuState !== 'ROLLE') {
        return [];
    }
    $mode = $identity['estab_permission_mode'] ?? null;
    if ($mode === 'STRICT') {
        $assignmentId = filter_var(
            $identity['duty_assignment_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
        );
        if (!is_int($assignmentId)) {
            return [];
        }
    } elseif ($mode !== null && $mode !== 'LOOSE') {
        return [];
    }

    $actions = [];
    $seen = [];
    $append = static function (array $action) use (&$actions, &$seen): void {
        $key = (string) ($action['key'] ?? '');
        $actingFunction = is_string($action['acting_function'] ?? null)
            ? $action['acting_function']
            : '';
        $deduplicationKey = $key . "\0" . $actingFunction;
        if ($key === '' || isset($seen[$deduplicationKey])) {
            return;
        }
        $seen[$deduplicationKey] = true;
        $actions[] = $action;
    };

    foreach (estab_auth_effective_function_roles($identity) as $tuple) {
        $role = $tuple['rolle'];
        $function = $tuple['funktion'];
        if ($role === 'Stab' && $function === 'Si') {
            $append([
                'key' => 'stab_sichten',
                'name' => 'stab_sichten_x',
                'label' => 'Sichten',
                'description' => 'Neue Meldungen prüfen',
            ]);
            $append([
                'key' => 'si_admin',
                'name' => 'si_admin_x',
                'label' => '2. Sichtung',
                'description' => 'Zweite Sichtung öffnen',
            ]);
        } elseif (estab_auth_has_staff_message_workspace($function, $role)) {
            $functionLabel = estab_function_display_name($function);
            $append([
                'key' => 'stab_schreiben',
                'name' => 'stab_schreiben_x',
                'label' => 'Schreiben als ' . $functionLabel,
                'description' => 'Neue Meldung für diese Funktion verfassen',
                'acting_function' => $function,
            ]);
            $append([
                'key' => 'stab_lesen',
                'name' => 'stab_lesen_x',
                'label' => 'Lesen als ' . $functionLabel,
                'description' => 'Meldungseingang dieser Funktion anzeigen',
                'acting_function' => $function,
            ]);
        }

        if ($role === 'Fernmelder' && $function === 'LdF') {
            $append([
                'key' => 'ldf_nachrichten',
                'name' => 'ldf_nachrichten_x',
                'label' => 'Disposition',
                'description' => 'Rufnamen und Beförderungswege festlegen',
            ]);
        }
        if ($role === 'Fernmelder' && $function === 'A/W') {
            $append([
                'key' => 'fm_eingang',
                'name' => 'fm_eingang_x',
                'label' => 'Eingang',
                'description' => 'Eingehende Meldung erfassen',
            ]);
            $append([
                'key' => 'fm_ausgang',
                'name' => 'fm_ausgang_x',
                'label' => 'Ausgang',
                'description' => 'Ausgehende Meldungen bearbeiten',
            ]);
            $append([
                'key' => 'fm_admin',
                'name' => 'fm_admin_x',
                'label' => '2. Sichtung',
                'description' => 'Zweite Sichtung öffnen',
            ]);
            $append([
                'key' => 'fm_anhang',
                'name' => 'fm_anhang_x',
                'label' => 'Anhänge',
                'description' => 'Dateien auswählen und hochladen',
            ]);
        }
    }

    /*
     * Die Korrekturschleife hatte bisher keinen Einstieg: die Route bestand,
     * aber kein Bedienelement fuehrte dorthin. Sie erscheint genau dann, wenn
     * fuer eine getragene Stabsfunktion wirklich etwas zurueckliegt, und der
     * Zaehler stammt aus derselben Messung wie die Warteschlangen.
     */
    $pendingCorrections = 0;
    foreach (estab_auth_effective_function_roles($identity) as $tuple) {
        if (
            !estab_auth_has_staff_message_workspace(
                $tuple['funktion'],
                $tuple['rolle']
            )
        ) {
            continue;
        }
        $pending = $correctionCounts[$tuple['funktion']] ?? null;
        if (is_int($pending) && $pending > 0) {
            $pendingCorrections += $pending;
        }
    }
    if ($pendingCorrections > 0) {
        $append([
            'key' => 'stab_korrekturen',
            'name' => 'stab_korrekturen_x',
            'label' => 'Korrekturen',
            'description' => $pendingCorrections === 1
                ? '1 zurückgewiesene Meldung überarbeiten'
                : $pendingCorrections
                    . ' zurückgewiesene Meldungen überarbeiten',
            'badge' => (string) $pendingCorrections,
        ]);
    }

    $append([
        'key' => 'm2_benutzer',
        'name' => 'm2_benutzer_x',
        'label' => 'Benutzer',
        'description' => 'Konten und Anmeldestatus anzeigen',
    ]);
    return $actions;
}
/** Render the mode-specific operational function without trusting requests. */
function estab_sidebar_account_function_markup(
    array $session,
    ?array $identity
): string {
    if ($identity === null) {
        return '';
    }
    if (($identity['estab_permission_mode'] ?? null) === 'STRICT') {
        $sessionAssignment = filter_var(
            $session['estab_duty_assignment_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
        );
        $identityAssignment = filter_var(
            $identity['duty_assignment_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
        );
        if (
            !is_int($sessionAssignment)
            || !is_int($identityAssignment)
            || $sessionAssignment !== $identityAssignment
        ) {
            return '';
        }
        return '<aside class="estab-sidebar-duty-hat"'
            . ' data-estab-active-duty-hat'
            . ' data-estab-duty-assignment="'
            . estab_auth_html((string) $identityAssignment) . '">'
            . '<span>Aktive Dienstfunktion</span>'
            . '<strong>' . estab_auth_html(
                estab_function_identity_display_name(
                    (string) $identity['funktion'],
                    (string) $identity['rolle']
                )
            ) . '</strong>'
            . '<a href="' . estab_auth_html(
                estab_application_url('4fach/fuehrungsstelle.php')
            ) . '" target="_top">Dienstfunktion wechseln</a>'
            . '</aside>';
    }
    if (
        isset($identity['estab_permission_mode'])
        && ($identity['estab_permission_mode'] ?? null) !== 'LOOSE'
    ) {
        return '';
    }
    $additional = [];
    foreach (estab_auth_effective_function_roles($identity) as $tuple) {
        if (
            hash_equals((string) $identity['funktion'], $tuple['funktion'])
            && hash_equals((string) $identity['rolle'], $tuple['rolle'])
        ) {
            continue;
        }
        $additional[] = estab_function_identity_display_name(
            $tuple['funktion'],
            $tuple['rolle']
        );
    }
    $additionalMarkup = $additional === []
        ? ''
        : '<span>Zusatzfunktionen: '
            . estab_auth_html(implode(' · ', $additional)) . '</span>';
    return '<aside class="estab-sidebar-account-function"'
        . ' data-estab-account-function>'
        . '<span>Primärfunktion</span>'
        . '<strong>' . estab_auth_html(
            estab_function_identity_display_name(
                (string) $identity['funktion'],
                (string) $identity['rolle']
            )
        ) . '</strong>'
        . $additionalMarkup
        . '</aside>';
}

/**
 * Render queue, server time and account activity by occupied function.
 *
 * $secondaryQueues carries every further worn function beyond the primary one
 * as {baseline_key, label, short_label, count}; $dutyFunctions maps a lower
 * case account code to the duty functions that account has accepted. An empty
 * duty map keeps the established account-function overview.
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
    string $incidentMarkup = '',
    ?array $operationalIdentity = null,
    array $secondaryQueues = [],
    array $dutyFunctions = []
): string {
    $identity = estab_auth_session_identity($session);
    if ($identity === null) {
        return '';
    }
    if (
        is_array($operationalIdentity)
        && is_string($operationalIdentity['benutzer'] ?? null)
        && is_string($operationalIdentity['kuerzel'] ?? null)
        && is_string($operationalIdentity['funktion'] ?? null)
        && is_string($operationalIdentity['rolle'] ?? null)
        && hash_equals($identity['benutzer'], $operationalIdentity['benutzer'])
        && hash_equals($identity['kuerzel'], $operationalIdentity['kuerzel'])
        && preg_match(
            '/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D',
            $operationalIdentity['funktion']
        ) === 1
        && in_array(
            $operationalIdentity['rolle'],
            ['Stab', 'FB', 'Fernmelder'],
            true
        )
    ) {
        $identity = $operationalIdentity;
    }
    $permissionMode = $identity['estab_permission_mode']
        ?? $session['estab_permission_mode']
        ?? null;
    $dutyAssignment = filter_var(
        $identity['duty_assignment_id']
            ?? $session['estab_duty_assignment_id']
            ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
    );
    $strictDutyPresence = $permissionMode === 'STRICT'
        && is_int($dutyAssignment);
    $currentFunctionText = $strictDutyPresence || $dutyFunctions !== []
        ? 'Ihre aktive Dienstfunktion'
        : 'Ihre Primärfunktion';
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
    $currentKey = null;
    $presenceUsers = [];
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
        if ($isCurrentAccount && $strictDutyPresence) {
            $user['rolle'] = $identity['rolle'];
            $user['funktion'] = $identity['funktion'];
        }
        // A duty shift decides which station a person took over. Without one
        // the account columns remain the only truth the sidebar has.
        $occupied = estab_sidebar_duty_functions_for_account(
            $dutyFunctions,
            $user['kuerzel'] ?? null
        ) ?? [[
            'rolle' => trim((string) $user['rolle']),
            'funktion' => trim((string) $user['funktion']),
        ]];
        $occupied = array_values(array_filter(
            $occupied,
            static fn (array $tuple): bool =>
                $tuple['rolle'] !== '' && $tuple['funktion'] !== ''
        ));
        if ($occupied === []) {
            // A duty shift that no longer lists the signed-in account would
            // otherwise erase the very chip the legend points at.
            if (!$isCurrentAccount) {
                continue;
            }
            $occupied = [[
                'rolle' => trim((string) $identity['rolle']),
                'funktion' => trim((string) $identity['funktion']),
            ]];
            if (
                $occupied[0]['rolle'] === ''
                || $occupied[0]['funktion'] === ''
            ) {
                continue;
            }
        }
        if ($presence === 'online') {
            $onlineUsers++;
        }
        foreach ($occupied as $tuple) {
            $role = $tuple['rolle'];
            $function = $tuple['funktion'];
            $occupancy = $user;
            $occupancy['rolle'] = $role;
            $occupancy['funktion'] = $function;
            $presenceUsers[] = $occupancy;
            $key = $role . "\0" . $function;
            if (
                $isCurrentAccount
                && (
                    $currentKey === null
                    || (
                        hash_equals((string) $identity['rolle'], $role)
                        && hash_equals((string) $identity['funktion'], $function)
                    )
                )
            ) {
                $currentPresence = $presence;
                $currentKey = $key;
            }
            if ($presence === 'online') {
                $online[$key] = ($online[$key] ?? 0) + 1;
            } else {
                $inactive[$key] = ($inactive[$key] ?? 0) + 1;
            }
        }
    }

    $chips = '';
    foreach (
        estab_sidebar_positions($configuredPositions, $presenceUsers, $now)
        as $position
    ) {
        $role = $position['rolle'];
        $function = $position['funktion'];
        $key = $role . "\0" . $function;
        $onlineCount = $online[$key] ?? 0;
        $inactiveCount = $inactive[$key] ?? 0;
        $sessionCount = $onlineCount + $inactiveCount;
        $isCurrent = is_string($currentKey)
            && hash_equals($currentKey, $key);
        $isMixed = $onlineCount > 0 && $inactiveCount > 0;
        $isCurrentInactive = $isCurrent && $currentPresence === 'inactive';
        $state = $isCurrent
            ? 'current'
            : ($onlineCount > 0
                ? 'online'
                : ($inactiveCount > 0 ? 'inactive' : 'offline'));
        $stateText = $isCurrent
            ? $currentFunctionText . ', '
                . ($currentPresence === 'online' ? 'aktiv' : 'inaktiv')
            : ($isMixed
                ? $onlineCount . ' aktiv, ' . $inactiveCount . ' inaktiv'
                : ($onlineCount > 0
                ? 'aktiv'
                : ($inactiveCount > 0
                    ? 'seit mindestens 15 Minuten inaktiv'
                    : 'abgemeldet')));
        $functionLabel = estab_function_display_name($function);
        $display = $function === 'A/W' && $sessionCount > 0
            ? $sessionCount . ' ' . $functionLabel
            : $functionLabel;
        $visiblePresenceNote = $isCurrentInactive
            ? 'Sie: inaktiv'
            : ($isMixed
                ? $onlineCount . ' aktiv · ' . $inactiveCount . ' inaktiv'
                : '');
        $accessible = $function === 'A/W' && $role === 'Fernmelder'
            ? $functionLabel . ': ' . $stateText
            : $role . ', Funktion ' . $functionLabel . ': ' . $stateText;
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
    if (count($secondaryQueues) > ESTAB_SIDEBAR_MAX_QUEUES - 1) {
        throw new InvalidArgumentException('Too many sidebar queues');
    }
    $queueItems = '';
    foreach ($secondaryQueues as $queue) {
        if (!is_array($queue)) {
            throw new InvalidArgumentException('Invalid sidebar queue entry');
        }
        $itemKey = estab_sidebar_queue_baseline_key(
            is_string($queue['baseline_key'] ?? null)
                ? $queue['baseline_key']
                : ''
        );
        $itemLabel = is_string($queue['label'] ?? null)
            ? trim($queue['label'])
            : '';
        $itemShortLabel = is_string($queue['short_label'] ?? null)
            ? trim($queue['short_label'])
            : '';
        $itemCount = $queue['count'] ?? null;
        if (
            $itemLabel === ''
            || strlen($itemLabel) > 80
            || preg_match('//u', $itemLabel) !== 1
            || $itemShortLabel === ''
            || strlen($itemShortLabel) > 40
            || preg_match('//u', $itemShortLabel) !== 1
            || ($itemCount !== null && (!is_int($itemCount) || $itemCount < 0))
        ) {
            throw new InvalidArgumentException('Invalid sidebar queue entry');
        }
        $itemState = $itemCount === null
            ? 'unavailable'
            : ($itemCount > 0 ? 'has-work' : 'empty');
        $itemAccessible = $itemLabel . ' ' . $itemShortLabel . ': '
            . ($itemCount === null
                ? 'nicht verfügbar'
                : $itemCount . ' wartend');
        $queueItems .= '<li class="estab-sidebar-queue-item ' . $itemState . '"'
            . ' data-estab-queue-state="' . $itemState . '"'
            . ' aria-label="' . estab_auth_html($itemAccessible) . '">'
            . '<span>' . estab_auth_html($itemShortLabel) . '</span>'
            . '<strong data-estab-queue-count="'
            . estab_auth_html($itemKey) . '" aria-live="polite">'
            . estab_auth_html(
                $itemCount === null ? '–' : (string) $itemCount
            ) . '</strong>'
            . '</li>';
    }
    $queueStrip = $queueItems === ''
        ? ''
        : '<ul class="estab-sidebar-queue-strip" data-estab-queue-strip'
            . ' aria-label="Warteschlangen Ihrer weiteren Funktionen">'
            . $queueItems . '</ul>';
    $presenceScope = $dutyFunctions === []
        ? 'Primärfunktion'
        : 'Dienstfunktion';
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
        . $queueStrip
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
        . '<h2>Aktivität nach ' . $presenceScope . '</h2>'
        . '<span data-estab-online-count="' . $onlineUsers . '">'
        . estab_auth_html($onlineText) . '</span>'
        . '</div>'
        . '<ul class="estab-sidebar-presence-grid"'
        . ' aria-label="Anmeldeaktivität nach ' . $presenceScope . '">'
        . $chips . '</ul>'
        . '<div class="estab-sidebar-presence-legend"'
        . ' aria-label="Legende zur ' . $presenceScope . '">'
        . '<span><i class="online" aria-hidden="true"></i>Aktiv</span>'
        . '<span><i class="inactive" aria-hidden="true"></i>Inaktiv (15 Min.)</span>'
        . '<span><i class="current" aria-hidden="true"></i>'
        . estab_auth_html($currentFunctionText) . '</span>'
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

    return '<script' . estab_csp_script_attribute()
        . ' data-estab-sidebar-refresh data-refresh-seconds="'
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
        . 'function reloadWorkspace(){'
        . 'try{if(window.parent&&window.parent!==window'
        . '&&window.parent.location.origin===window.location.origin){'
        . 'window.parent.location.reload();return;}}catch(ignore){}'
        . 'window.location.reload();'
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
        . 'var currentQueues=current.querySelectorAll('
        . '"[data-estab-queue-count]");'
        . 'var freshQueues=fresh.querySelectorAll("[data-estab-queue-count]");'
        . 'if(currentQueues.length===freshQueues.length){'
        . 'for(var queueIndex=0;queueIndex<currentQueues.length;queueIndex++){'
        . 'var currentQueue=currentQueues[queueIndex];'
        . 'var freshQueue=freshQueues[queueIndex];'
        . 'if(currentQueue.getAttribute("data-estab-queue-count")'
        . '===freshQueue.getAttribute("data-estab-queue-count")'
        . '&&currentQueue.textContent===freshQueue.textContent){'
        . 'freshQueue.replaceWith(currentQueue);'
        . '}'
        . '}'
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
        . 'if(response.status===403||response.status===409){'
        . 'reloadWorkspace();return false;}'
        . 'if(!response.ok){markStatusStale();return false;}'
        . 'var html=await response.text();'
        . 'var parsed=new DOMParser().parseFromString(html,"text/html");'
        . 'var fresh=parsed.querySelector("[data-estab-sidebar-status]");'
        . 'var current=document.querySelector("[data-estab-sidebar-status]");'
        . 'if(!fresh||!current){markStatusStale();return false;}'
        . 'function incidentSignature(status){'
        . 'var incident=status.querySelector("[data-estab-incident-state]");'
        . 'if(!incident){return "missing";}'
        . 'var mode=incident.querySelector('
        . '"[data-estab-incident-permission-mode]");'
        . 'return (incident.getAttribute("data-estab-incident-state")||"")'
        . '+"|"+(incident.getAttribute("data-estab-incident-id")||"")'
        . '+"|"+(mode?mode.getAttribute('
        . '"data-estab-incident-permission-mode")||"":"");'
        . '}'
        . 'if(incidentSignature(current)!==incidentSignature(fresh)){'
        . 'reloadWorkspace();return false;}'
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
