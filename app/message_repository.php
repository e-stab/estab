<?php

/**
 * Prepared persistence and output boundary for operational messages.
 *
 * The historic controller still supplies associative arrays whose keys mirror
 * the database columns. Only the fixed allowlist below may become SQL
 * identifiers; every value is bound through mysqli.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/dv_operations.php';
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/message_evidence.php';
require_once __DIR__ . '/message_priority.php';

/** @return array<string, true> */
function estab_message_columns(): array
{
    static $columns = null;
    if ($columns === null) {
        $columns = array_fill_keys([
            '01_medium', '01_datum', '01_zeichen',
            '02_zeit', '02_zeichen',
            '03_datum', '03_zeichen',
            '04_richtung', '04_nummer',
            '05_gegenstelle', '06_befweg', '06_befwegausw',
            'estab_fernmeldeplan_eintrag_id',
            '07_durchspruch', '08_befhinweis', '08_befhinwausw',
            '09_vorrangstufe', '10_anschrift',
            '11_rufnummer', '11_gesprnotiz',
            '12_anhang', '12_betreff', '12_inhalt', '12_abfzeit',
            '13_abseinheit', '14_zeichen', '14_funktion',
            '15_quitdatum', '15_quitzeichen',
            '16_empf', '17_vermerke', '20_master_katego',
            'x00_status', 'x01_abschluss', 'x02_sperre',
            'x03_sperruser', 'x04_druck', 'x05_druck_d',
        ], true);
    }
    return $columns;
}

/** Quote a fixed or validated eStab table identifier. */
function estab_message_table(string $table): string
{
    if (
        strlen($table) > 64
        || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $table) !== 1
    ) {
        throw new InvalidArgumentException('Invalid message table identifier');
    }
    return '`' . $table . '`';
}

/** Parse an externally supplied record identifier without numeric coercion. */
function estab_message_positive_id(mixed $value): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid message record identifier');
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
    ]);
    if (!is_int($parsed)) {
        throw new InvalidArgumentException('Invalid message record identifier');
    }
    return $parsed;
}

/** Validate and hash one high-entropy browser action token for evidence. */
function estab_message_action_token_hash(mixed $token): string
{
    if (
        !is_string($token)
        || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
    ) {
        throw new InvalidArgumentException('Invalid message action token');
    }
    return hash('sha256', $token);
}

/** Return the workflow tasks that persist a user-created message action. */
function estab_message_action_tasks(): array
{
    return [
        'FM-Eingang',
        'FM-Eingang_Anhang',
        'Stab_schreiben',
        'Stab_korrigieren',
        'Stab_gesprnoti',
    ];
}

/** Derive a bounded MariaDB advisory-lock name from one action token. */
function estab_message_action_lock_name(mixed $token): string
{
    return 'estab:message-action:'
        . substr(estab_message_action_token_hash($token), 0, 40);
}

/** Serialize the evidence lookup and message commit for one action token. */
function estab_message_action_lock(
    mysqli $connection,
    mixed $token,
    int $timeoutSeconds = 10
): string {
    if ($timeoutSeconds < 0 || $timeoutSeconds > 30) {
        throw new InvalidArgumentException('Invalid message action lock timeout');
    }
    $lockName = estab_message_action_lock_name($token);
    $statement = $connection->prepare('SELECT GET_LOCK(?, ?)');
    if (!$statement) {
        throw new RuntimeException('Message action lock could not be prepared');
    }
    try {
        $statement->bind_param('si', $lockName, $timeoutSeconds);
        if (!$statement->execute()) {
            throw new RuntimeException('Message action lock could not be acquired');
        }
        $row = $statement->get_result()->fetch_row();
    } finally {
        $statement->close();
    }
    if ((string) ($row[0] ?? '') !== '1') {
        throw new RuntimeException('Message action is already being processed');
    }
    return $lockName;
}

/** Release one advisory action lock and reject a silently lost lock. */
function estab_message_action_unlock(
    mysqli $connection,
    ?string $lockName
): void {
    if ($lockName === null) {
        return;
    }
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        throw new RuntimeException('Message action unlock could not be prepared');
    }
    try {
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            throw new RuntimeException('Message action lock could not be released');
        }
        $row = $statement->get_result()->fetch_row();
    } finally {
        $statement->close();
    }
    if ((string) ($row[0] ?? '') !== '1') {
        throw new RuntimeException('Message action lock was lost');
    }
}

/**
 * Bind a browser action to the immutable event written in the same commit.
 *
 * Only a one-way hash is persisted. An absent token remains supported for
 * legacy/internal callers, while every current browser form supplies one.
 */
function estab_message_action_evidence_snapshot(
    array $snapshot,
    mixed $token,
    string $task
): array {
    if ($token === null || $token === '') {
        return $snapshot;
    }
    if (!in_array($task, estab_message_action_tasks(), true)) {
        throw new InvalidArgumentException('Invalid message action task');
    }
    $snapshot['request_action'] = [
        'task' => $task,
        'token_sha256' => estab_message_action_token_hash($token),
    ];
    return $snapshot;
}

/**
 * Resolve exact durable proof that this browser action already committed.
 *
 * The immutable event is appended inside the same transaction as the message
 * INSERT/UPDATE. It is therefore a reliable replay result after a worker dies
 * between the database commit and the final session-token update.
 */
function estab_message_committed_action_id(
    mysqli $connection,
    mixed $incidentId,
    mixed $token,
    string $task,
    array $identity,
    mixed $recordId = null
): ?int {
    $incidentId = estab_incident_positive_id($incidentId);
    if (!in_array($task, estab_message_action_tasks(), true)) {
        throw new InvalidArgumentException('Invalid message action task');
    }
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if ($shape === null) {
        throw new InvalidArgumentException('Invalid message action identity');
    }
    $expectedRecordId = $task === 'Stab_korrigieren'
        ? estab_message_positive_id($recordId)
        : null;
    if ($task !== 'Stab_korrigieren' && $recordId !== null && $recordId !== '') {
        throw new InvalidArgumentException('Unexpected message action record');
    }
    $eventType = match ($task) {
        'Stab_korrigieren' => 'author_resubmitted',
        'Stab_gesprnoti' => 'conversation_note_created',
        default => 'created',
    };
    $tokenHash = estab_message_action_token_hash($token);
    $statement = $connection->prepare(
        'SELECT `message_id` FROM `nv_nachrichten_ereignisse`'
        . ' WHERE `einsatz_id` = ? AND `event_type` = ?'
        . ' AND BINARY `actor_user` = BINARY ?'
        . ' AND BINARY `actor_code` = BINARY ?'
        . ' AND BINARY `actor_function` = BINARY ?'
        . " AND JSON_UNQUOTE(JSON_EXTRACT(`field_snapshot`, "
        . "'$.request_action.task')) = ?"
        . " AND JSON_UNQUOTE(JSON_EXTRACT(`field_snapshot`, "
        . "'$.request_action.token_sha256')) = ?"
        . ' ORDER BY `event_id` ASC LIMIT 2'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Committed message action lookup could not be prepared'
        );
    }
    $actorUser = (string) $shape['benutzer'];
    $actorCode = (string) $shape['kuerzel'];
    $actorFunction = (string) $shape['funktion'];
    try {
        $statement->bind_param(
            'issssss',
            $incidentId,
            $eventType,
            $actorUser,
            $actorCode,
            $actorFunction,
            $task,
            $tokenHash
        );
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Committed message action lookup could not be executed'
            );
        }
        $result = $statement->get_result();
        $rows = [];
        while (is_array($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        $result->free();
    } finally {
        $statement->close();
    }
    if (count($rows) > 1) {
        throw new LogicException(
            'A message action token has more than one durable outcome'
        );
    }
    if ($rows === []) {
        return null;
    }
    $messageId = estab_message_positive_id($rows[0]['message_id'] ?? null);
    if ($expectedRecordId !== null && $messageId !== $expectedRecordId) {
        throw new LogicException(
            'A correction action token resolved to another message'
        );
    }
    return $messageId;
}

/**
 * Decode storage-time entities from older releases exactly once.
 *
 * The decoded value is never emitted as HTML directly. This compatibility
 * step lets old "&amp;" rows and new raw UTF-8 rows render identically.
 */
function estab_message_plain_text(mixed $value): string
{
    return html_entity_decode(
        (string) $value,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
}

/** Escape message data for HTML text, quoted attributes and textarea bodies. */
function estab_message_html(mixed $value): string
{
    return htmlspecialchars(
        estab_message_plain_text($value),
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    );
}

/** Return a short display excerpt without cutting through a UTF-8 character. */
function estab_message_excerpt(mixed $value, int $limit): string
{
    $text = estab_message_plain_text($value);
    $limit = max(0, min($limit, 10000));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return substr($text, 0, $limit);
}

/**
 * Normalize one official-form single-line value at the persistence boundary.
 *
 * A null result denotes malformed UTF-8, a non-string value, forbidden
 * control/line-break characters, an empty required value or an overlong
 * value. Optional empty strings remain valid.
 */
function estab_message_single_line_value(
    mixed $value,
    int $maxLength,
    bool $allowEmpty
): ?string {
    if (
        !is_string($value)
        || $maxLength < 1
        || preg_match('//u', $value) !== 1
        || preg_match('/\p{C}/u', $value) === 1
        || preg_match('/\R/u', $value) === 1
    ) {
        return null;
    }

    $normalized = preg_replace(
        '/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u',
        '',
        $value
    );
    if (!is_string($normalized)) {
        return null;
    }
    $length = estab_auth_text_length($normalized);
    if (
        $length < 0
        || $length > $maxLength
        || (!$allowEmpty && $length === 0)
    ) {
        return null;
    }
    return $normalized;
}

/**
 * Build the authoritative AW:/WG: subject without trusting browser fields.
 *
 * Existing identical prefixes are canonicalized instead of duplicated. A
 * legacy source without a subject still yields the explicit action marker.
 */
function estab_message_derived_subject(mixed $source, string $action): string
{
    if (!in_array($action, ['AW', 'WG'], true)) {
        throw new InvalidArgumentException('Invalid derived-message action');
    }

    $subject = estab_message_single_line_value($source, 255, true);
    if ($subject === null) {
        $subject = '';
    }
    $marker = $action . ':';
    if (
        preg_match(
            '/\A' . preg_quote($marker, '/') . '[\p{Z}\s]*(.*)\z/iu',
            $subject,
            $matches
        ) === 1
    ) {
        $subject = (string) ($matches[1] ?? '');
    }

    $separator = $subject === '' ? '' : ' ';
    $available = 255
        - estab_auth_text_length($marker)
        - estab_auth_text_length($separator);
    if (estab_auth_text_length($subject) > $available) {
        if (function_exists('mb_substr')) {
            $subject = mb_substr($subject, 0, $available, 'UTF-8');
        } else {
            $characters = preg_split('//u', $subject, -1, PREG_SPLIT_NO_EMPTY);
            $subject = is_array($characters)
                ? implode('', array_slice($characters, 0, $available))
                : '';
        }
    }
    return $marker . $separator . $subject;
}

/**
 * Return the contact fields shared by every reply/forward entry point.
 *
 * Replies retain a safe authoritative phone number; forwards deliberately
 * start without one because their counterparty has not been selected yet.
 *
 * @return array{11_rufnummer:string,12_betreff:string}
 */
function estab_message_followup_contact_fields(
    array $source,
    string $action
): array {
    return [
        '11_rufnummer' => $action === 'AW'
            ? (
                estab_message_single_line_value(
                    $source['11_rufnummer'] ?? '',
                    128,
                    true
                ) ?? ''
            )
            : '',
        '12_betreff' => estab_message_derived_subject(
            $source['12_betreff'] ?? '',
            $action
        ),
    ];
}

/**
 * Retire the obsolete transport-hint inputs at every new-record boundary.
 *
 * Existing rows retain these two fields because they are part of historical
 * message evidence.  A new record, including a draft derived from an older
 * message, must never inherit or accept either value from browser data.
 */
function estab_message_new_record_fields(array $fields): array
{
    $fields['08_befhinweis'] = '';
    $fields['08_befhinwausw'] = '';
    return $fields;
}

/** Remove retired hint columns from an update so stored evidence is preserved. */
function estab_message_existing_record_fields(array $fields): array
{
    unset($fields['08_befhinweis'], $fields['08_befhinwausw']);
    return $fields;
}

/**
 * Turn a derived reply/forward draft into a genuinely new message record.
 *
 * The source row id is useful while deriving the quoted content, but must not
 * survive into the rendered draft. In particular, the attachment workflow
 * accepts record ids only for an explicitly authorised correction.
 */
function estab_message_followup_new_record(array $draft): array
{
    $draft = estab_message_new_record_fields($draft);
    $draft['00_lfd'] = '';
    // A reply/forward is not linked to a TBB row until its own transport is
    // documented. Never carry the source message's visible evidence number.
    unset($draft['msglfd'], $draft['estab_ttb_lfd']);
    return $draft;
}

function estab_message_connect(array $databaseConfig): mysqli
{
    return estab_auth_connect($databaseConfig);
}

/**
 * Prepare and execute without exposing SQL, parameters or credentials in an
 * exception or audit record.
 */
function estab_message_execute(
    mysqli $connection,
    string $sql,
    array $parameters = []
): mysqli_stmt {
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare message operation');
    }
    try {
        $executed = $parameters === []
            ? $statement->execute()
            : $statement->execute(array_values($parameters));
        if (!$executed) {
            throw new RuntimeException('Could not execute message operation');
        }
        return $statement;
    } catch (Throwable $exception) {
        $statement->close();
        if ($exception instanceof RuntimeException) {
            throw $exception;
        }
        throw new RuntimeException('Could not execute message operation', 0, $exception);
    }
}

/** Validate a column/value map and return it in insertion order. */
function estab_message_fields(array $fields): array
{
    if ($fields === []) {
        throw new InvalidArgumentException('Message fields must not be empty');
    }
    $allowed = estab_message_columns();
    $validated = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !isset($allowed[$column])) {
            throw new InvalidArgumentException('Invalid message column');
        }
        if (is_array($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Invalid message value');
        }
        if ($column === '09_vorrangstufe') {
            $priority = estab_message_priority_storage_value($value);
            if ($priority === null) {
                throw new InvalidArgumentException('Invalid message priority');
            }
            $value = $priority;
        }
        if ($column === '11_rufnummer' || $column === '12_betreff') {
            $value = estab_message_single_line_value(
                $value,
                $column === '11_rufnummer' ? 128 : 255,
                $column === '11_rufnummer'
            );
            if ($value === null) {
                throw new InvalidArgumentException(
                    $column === '11_rufnummer'
                        ? 'Invalid message phone number'
                        : 'Invalid message subject'
                );
            }
        }
        $validated[$column] = $value;
    }
    return $validated;
}

/**
 * Append one caller-supplied workflow event inside the current transaction.
 *
 * Keeping this adapter in the repository makes it impossible for a successful
 * domain update to commit after a failed evidence insert.
 *
 * @param array{
 *   event_type:string,
 *   actor:array,
 *   from_status:?int,
 *   to_status:?int,
 *   snapshot:array,
 *   occurred_at?:?string
 * } $event
 */
function estab_message_append_transition_evidence(
    mysqli $connection,
    int $incidentId,
    int $messageId,
    array $event
): void {
    foreach (
        ['event_type', 'actor', 'from_status', 'to_status', 'snapshot']
        as $required
    ) {
        if (!array_key_exists($required, $event)) {
            throw new InvalidArgumentException('Incomplete message evidence');
        }
    }
    if (!is_array($event['actor']) || !is_array($event['snapshot'])) {
        throw new InvalidArgumentException('Invalid message evidence');
    }
    if (
        !is_string($event['event_type'])
        || (
            $event['from_status'] !== null
            && !is_int($event['from_status'])
        )
        || (
            $event['to_status'] !== null
            && !is_int($event['to_status'])
        )
        || (
            array_key_exists('occurred_at', $event)
            && $event['occurred_at'] !== null
            && !is_string($event['occurred_at'])
        )
    ) {
        throw new InvalidArgumentException('Invalid message evidence');
    }
    if ($event['to_status'] === 8) {
        $terminalStatement = $connection->prepare(
            'SELECT * FROM `nv_nachrichten`'
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                . ' FOR UPDATE'
        );
        if (!$terminalStatement) {
            throw new RuntimeException(
                'Abgeschlossener Nachrichtennachweis konnte nicht vorbereitet werden.'
            );
        }
        try {
            $terminalStatement->bind_param(
                'ii',
                $messageId,
                $incidentId
            );
            if (!$terminalStatement->execute()) {
                throw new RuntimeException(
                    'Abgeschlossener Nachrichtennachweis konnte nicht gelesen werden.'
                );
            }
            $terminalMessage = $terminalStatement
                ->get_result()
                ->fetch_assoc();
        } finally {
            $terminalStatement->close();
        }
        if (
            !is_array($terminalMessage)
            || (int) ($terminalMessage['x00_status'] ?? 0) !== 8
            || !in_array(
                (string) ($terminalMessage['x01_abschluss'] ?? ''),
                ['t', '1'],
                true
            )
        ) {
            throw new LogicException(
                'Terminales Ereignis passt nicht zum abgeschlossenen Datensatz.'
            );
        }
        $terminalSnapshot =
            estab_message_terminal_snapshot($terminalMessage);
        $event['snapshot']['terminal_message'] = $terminalSnapshot;
        $event['snapshot']['terminal_snapshot_sha256'] =
            estab_message_terminal_snapshot_sha256($terminalMessage);
    }
    // The guard runs inside the same active-incident transaction as the
    // message mutation. If a duty shift is active, a forged/stale basic
    // account context therefore rolls the domain update back together with
    // its evidence instead of racing an incident or hat change.
    estab_dv_require_operational_account(
        $connection,
        $incidentId,
        $event['actor']
    );
    estab_message_event_append(
        $connection,
        $incidentId,
        $messageId,
        $event['event_type'],
        $event['actor'],
        $event['from_status'],
        $event['to_status'],
        $event['snapshot'],
        $event['occurred_at'] ?? null
    );
}

/**
 * Insert fields after the singleton incident row has already been locked.
 *
 * `einsatz_id` is deliberately not part of the public field allowlist. It is
 * always supplied from the authoritative status row, never from request data.
 */
function estab_message_insert_for_incident(
    mysqli $connection,
    string $table,
    array $fields,
    int $incidentId
): int {
    $fields = estab_message_fields($fields);
    $incidentId = estab_incident_positive_id($incidentId);
    $columns = array_merge(['einsatz_id'], array_keys($fields));
    $values = array_merge([$incidentId], array_values($fields));
    $sql = 'INSERT INTO ' . estab_message_table($table)
        . ' (`' . implode('`, `', $columns) . '`) VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $statement = estab_message_execute($connection, $sql, $values);
    try {
        $recordId = (int) $connection->insert_id;
        if ($recordId < 1) {
            throw new RuntimeException('Message insert returned no record identifier');
        }
        return $recordId;
    } finally {
        $statement->close();
    }
}

/**
 * Bind local message identity to the incident locked by the write transaction.
 *
 * Incoming messages are addressed to the local command post. Outgoing
 * messages and internal conversation notes originate there. Browser fields,
 * stale form defaults and installation environment values are never
 * authoritative for these identities.
 */
function estab_message_bind_command_post(
    array $fields,
    array $incident,
    mixed $direction = null
): array {
    $direction = $direction ?? ($fields['04_richtung'] ?? null);
    if (!is_string($direction) || !in_array($direction, ['E', 'A'], true)) {
        throw new InvalidArgumentException('Invalid message direction');
    }
    $commandPostName = estab_incident_command_post_name($incident);
    if ($direction === 'E') {
        $fields['10_anschrift'] = $commandPostName;
        if ((string) ($fields['11_gesprnotiz'] ?? '') === 't') {
            $fields['13_abseinheit'] = $commandPostName;
        }
    } else {
        $fields['13_abseinheit'] = $commandPostName;
    }
    return $fields;
}

/**
 * Resolve the incident held by an operational write and reject stale forms.
 *
 * The controller captures the active incident before it renders or accepts a
 * form. The status row is locked only inside the repository transaction, so
 * that captured identifier must be compared here rather than in an earlier
 * read gate where an administrator could still switch the active incident.
 */
function estab_message_transaction_incident_id(
    array $incident,
    mixed $expectedIncidentId = null
): int {
    $incidentId = estab_incident_positive_id(
        $incident['active_einsatz_id'] ?? null
    );
    if (
        $expectedIncidentId !== null
        && $incidentId !== estab_incident_positive_id($expectedIncidentId)
    ) {
        throw new EstabIncidentConflictException(
            'Der aktive Einsatz hat sich seit dem Öffnen des Nachrichtenvordrucks '
                . 'geändert. Die Eingabe wurde nicht gespeichert.'
        );
    }
    return $incidentId;
}

/** Insert one message atomically into the globally active incident. */
function estab_message_insert(
    mysqli $connection,
    string $table,
    array $fields
): int {
    $fields = estab_message_new_record_fields($fields);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $fields
        ): int {
            return estab_message_insert_for_incident(
                $connection,
                $table,
                estab_message_bind_command_post($fields, $incident),
                (int) $incident['active_einsatz_id']
            );
        }
    );
}

/**
 * Verify that every attachment referenced by a new message is finalised in
 * the same incident. This closes the incident-switch window between selecting
 * an attachment and submitting the message form.
 */
function estab_message_require_attachment_scope(
    mysqli $connection,
    string $attachmentTable,
    int $incidentId,
    mixed $attachmentList
): void {
    if ($attachmentList === null || $attachmentList === '') {
        return;
    }
    if (!is_string($attachmentList) || strlen($attachmentList) > 65535) {
        throw new InvalidArgumentException('Invalid message attachment list');
    }
    $filenames = array_values(array_unique(array_filter(
        array_map('trim', explode(';', $attachmentList)),
        static fn (string $filename): bool => $filename !== ''
    )));
    if (count($filenames) > 100) {
        throw new InvalidArgumentException('Too many message attachments');
    }
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM ' . estab_message_table($attachmentTable)
        . ' WHERE `einsatz_id` = ? AND `filename` = ?'
        . ' AND `fileext` = ? AND `status` = 1'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare message attachment scope');
    }
    try {
        foreach ($filenames as $filename) {
            if (
                preg_match(
                    '/\A([A-Za-z0-9_-]{2,255})\.([a-z0-9]{1,16})\z/D',
                    $filename,
                    $parts
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid message attachment reference'
                );
            }
            $base = $parts[1];
            $extension = strtolower($parts[2]);
            $statement->bind_param(
                'iss',
                $incidentId,
                $base,
                $extension
            );
            if (!$statement->execute()) {
                throw new RuntimeException(
                    'Could not verify message attachment scope'
                );
            }
            $result = $statement->get_result();
            $row = $result->fetch_row();
            $result->free();
            if ((int) ($row[0] ?? 0) !== 1) {
                throw new EstabIncidentConflictException(
                    'Ein ausgewählter Anhang gehört nicht zum aktiven Einsatz.'
                );
            }
        }
    } finally {
        $statement->close();
    }
}

/**
 * Use one server-wide namespace for normal allocation and administrator
 * counter repair.
 */
function estab_message_counter_lock_name(
    string $databaseName,
    string $table
): string {
    if (
        $databaseName === ''
        || strlen($databaseName) > 255
        || str_contains($databaseName, "\0")
    ) {
        throw new InvalidArgumentException('Invalid message database name');
    }
    estab_message_table($table);
    return 'estab-counter-' . substr(
        hash('sha256', $databaseName . "\0" . $table),
        0,
        32
    );
}

/**
 * Return the highest explicit paper-counter repair recorded in the immutable
 * command-post event chain.
 *
 * A counter repair is operational evidence, not a fictitious message. Normal
 * allocation therefore takes the maximum of real rows and this watermark.
 */
function estab_message_counter_repair_max(
    mysqli $connection,
    int $incidentId,
    bool $separateNumbering,
    string $direction
): int {
    $incidentId = estab_incident_positive_id($incidentId);
    if (!in_array($direction, ['E', 'A'], true)) {
        throw new InvalidArgumentException('Invalid message direction');
    }
    // A deployment can change between common and split paper numbering.
    // Every prior repair remains a lower bound after such a configuration
    // change: a common watermark applies to both directions, while common
    // allocation must not reuse either split watermark.
    $fields = $separateNumbering
        ? [
            $direction === 'E' ? 'e_nummer' : 'a_nummer',
            'ea_nummer',
        ]
        : ['ea_nummer', 'e_nummer', 'a_nummer'];
    $watermarks = array_map(
        static fn (string $field): string =>
            "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(`details`, "
            . "'$.after.{$field}')) AS UNSIGNED), 0)",
        $fields
    );
    $statement = $connection->prepare(
        'SELECT COALESCE(MAX(GREATEST('
        . implode(', ', $watermarks)
        . ')), 0)'
        . ' FROM `nv_betriebsereignisse`'
        . ' WHERE `einsatz_id` = ?'
        // Current repairs belong to the incident itself. Keep the historical
        // object type readable so a pre-migration watermark is never reused.
        . " AND `objekttyp` IN ('EINSATZ', 'DIENSTSCHICHT')"
        . " AND `aktion` = 'message_counter_repaired'"
    );
    if (!$statement) {
        throw new RuntimeException(
            'Could not prepare message-counter repair lookup'
        );
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Could not read message-counter repair evidence'
            );
        }
        $row = $statement->get_result()->fetch_row();
    } finally {
        $statement->close();
    }
    $maximum = (int) ($row[0] ?? 0);
    if ($maximum < 0 || $maximum > 999999999) {
        throw new RuntimeException(
            'Message-counter repair evidence is outside the supported range'
        );
    }
    return $maximum;
}

/**
 * Allocate the paper-log number and insert atomically.
 *
 * MariaDB advisory locking covers the empty-table case that MAX()+FOR UPDATE
 * alone cannot serialize reliably. The same lock is used by administrative
 * counter repair, and all active message writers use this path.
 *
 * @return array{id: int, number: int}
 */
function estab_message_insert_numbered(
    mysqli $connection,
    string $databaseName,
    string $table,
    string $direction,
    bool $separateNumbering,
    array $fields,
    ?string $attachmentTable,
    array $event,
    ?callable $attachmentAuthorizer = null,
    mixed $expectedIncidentId = null
): array {
    if (!in_array($direction, ['E', 'A'], true)) {
        throw new InvalidArgumentException('Invalid message direction');
    }
    $fields = estab_message_new_record_fields($fields);
    estab_message_table($table);
    $lockName = estab_message_counter_lock_name($databaseName, $table);
    $lockStatement = estab_message_execute(
        $connection,
        'SELECT GET_LOCK(?, 10)',
        [$lockName]
    );
    try {
        $lockRow = $lockStatement->get_result()->fetch_row();
    } finally {
        $lockStatement->close();
    }
    if (($lockRow[0] ?? null) !== 1 && ($lockRow[0] ?? null) !== '1') {
        throw new RuntimeException('Could not serialize message number allocation');
    }

    try {
        return estab_incident_with_active_write(
            $connection,
            static function (array $incident) use (
                $connection,
                $table,
                $direction,
                $separateNumbering,
                $fields,
                $attachmentTable,
                $event,
                $attachmentAuthorizer,
                $expectedIncidentId
            ): array {
                $incidentId = estab_message_transaction_incident_id(
                    $incident,
                    $expectedIncidentId
                );
                if ($separateNumbering) {
                    $numberStatement = estab_message_execute(
                        $connection,
                        'SELECT COALESCE(MAX(`04_nummer`), 0) FROM '
                            . estab_message_table($table)
                            . ' WHERE `einsatz_id` = ?'
                            . ' AND `04_richtung` = ? FOR UPDATE',
                        [$incidentId, $direction]
                    );
                } else {
                    $numberStatement = estab_message_execute(
                        $connection,
                        'SELECT COALESCE(MAX(`04_nummer`), 0) FROM '
                            . estab_message_table($table)
                            . ' WHERE `einsatz_id` = ? FOR UPDATE',
                        [$incidentId]
                    );
                }
                try {
                    $numberRow = $numberStatement->get_result()->fetch_row();
                } finally {
                    $numberStatement->close();
                }
                $messageMaximum = (int) ($numberRow[0] ?? 0);
                $repairMaximum = estab_message_counter_repair_max(
                    $connection,
                    $incidentId,
                    $separateNumbering,
                    $direction
                );
                $number = max($messageMaximum, $repairMaximum) + 1;
                if ($number < 1) {
                    throw new RuntimeException('Message number allocation overflowed');
                }

                $fields['04_richtung'] = $direction;
                $fields['04_nummer'] = $number;
                $fields = estab_message_bind_command_post(
                    $fields,
                    $incident,
                    $direction
                );
                if (
                    array_key_exists('12_anhang', $fields)
                    && (string) $fields['12_anhang'] !== ''
                ) {
                    if ($attachmentTable === null) {
                        throw new LogicException(
                            'Message attachment table is required'
                        );
                    }
                    estab_message_require_attachment_scope(
                        $connection,
                        $attachmentTable,
                        $incidentId,
                        $fields['12_anhang']
                    );
                    if ($attachmentAuthorizer === null) {
                        throw new LogicException(
                            'Message attachment authorizer is required'
                        );
                    }
                    $attachmentAuthorizer(
                        $connection,
                        $incidentId,
                        $fields['12_anhang']
                    );
                }
                $recordId = estab_message_insert_for_incident(
                    $connection,
                    $table,
                    $fields,
                    $incidentId
                );
                estab_message_append_transition_evidence(
                    $connection,
                    $incidentId,
                    $recordId,
                    $event
                );
                // Internal Gesprächsnotizen share the legacy incoming-message
                // storage shape but are not received radio/telephone traffic
                // and therefore must not consume a TBB number.
                if (
                    $direction === 'E'
                    && ($event['event_type'] ?? null)
                        !== 'conversation_note_created'
                ) {
                    $occurredAt = is_string($event['occurred_at'] ?? null)
                        ? (string) $event['occurred_at']
                        : date('Y-m-d H:i:s');
                    estab_logbook_lifecycle_message_transport(
                        $connection,
                        $incidentId,
                        $recordId,
                        $occurredAt,
                        'E'
                    );
                }
                return ['id' => $recordId, 'number' => $number];
            }
        );
    } finally {
        $release = estab_message_execute(
            $connection,
            'SELECT RELEASE_LOCK(?)',
            [$lockName]
        );
        $release->close();
    }
}

/** Update an existing positive record through bound values only. */
function estab_message_update(
    mysqli $connection,
    string $table,
    mixed $recordId,
    array $fields,
    array $event
): bool {
    $recordId = estab_message_positive_id($recordId);
    $fields = estab_message_fields($fields);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $fields,
            $event
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $assignments = [];
            foreach (array_keys($fields) as $column) {
                $assignments[] = '`' . $column . '` = ?';
            }
            $parameters = array_values($fields);
            $parameters[] = $recordId;
            $parameters[] = $incidentId;
            $statement = estab_message_execute(
                $connection,
                'UPDATE ' . estab_message_table($table)
                    . ' SET ' . implode(', ', $assignments)
                    . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                    . " AND `x01_abschluss` = 'f' AND `x00_status` <> 8",
                $parameters
            );
            try {
                if ($statement->affected_rows === 1) {
                    estab_message_append_transition_evidence(
                        $connection,
                        $incidentId,
                        $recordId,
                        $event
                    );
                    return true;
                }
            } finally {
                $statement->close();
            }

            // An UPDATE that writes the values already stored reports zero
            // affected rows. Verify the incident and every submitted field.
            $verificationParameters = [$recordId, $incidentId];
            $verificationConditions = [
                '`00_lfd` = ?',
                '`einsatz_id` = ?',
                "`x01_abschluss` = 'f'",
                '`x00_status` <> 8',
            ];
            foreach ($fields as $column => $value) {
                $verificationConditions[] = '`' . $column . '` <=> ?';
                $verificationParameters[] = $value;
            }
            $verification = estab_message_execute(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($table)
                    . ' WHERE ' . implode(' AND ', $verificationConditions),
                $verificationParameters
            );
            try {
                $row = $verification->get_result()->fetch_row();
                return ((int) ($row[0] ?? 0)) === 1;
            } finally {
                $verification->close();
            }
        }
    );
}

/**
 * Save an outgoing transport transition only while the submitted operator
 * still owns the pending row. This closes the check/use window between the
 * controller's object decision and the actual UPDATE.
 */
function estab_message_update_locked_outgoing(
    mysqli $connection,
    string $table,
    mixed $recordId,
    string $operatorCode,
    array $fields
): bool {
    $recordId = estab_message_positive_id($recordId);
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $operatorCode) !== 1) {
        throw new InvalidArgumentException('Invalid message lock owner');
    }
    $fields = estab_message_fields($fields);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $operatorCode,
            $fields
        ): bool {
            $assignments = [];
            foreach (array_keys($fields) as $column) {
                $assignments[] = '`' . $column . '` = ?';
            }
            $parameters = array_values($fields);
            $parameters[] = $recordId;
            $parameters[] = (int) $incident['active_einsatz_id'];
            $parameters[] = '';
            $parameters[] = $operatorCode;
            $statement = estab_message_execute(
                $connection,
                'UPDATE ' . estab_message_table($table)
                    . ' SET ' . implode(', ', $assignments)
                    . " WHERE `00_lfd` = ? AND `einsatz_id` = ?"
                    . " AND `04_richtung` = 'A'"
                    . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
                    . " AND `x02_sperre` = 't' AND `x03_sperruser` = ?",
                $parameters
            );
            try {
                return $statement->affected_rows === 1;
            } finally {
                $statement->close();
            }
        }
    );
}

/**
 * Return the immutable SQL predicate for a staged LdF/A-W transition.
 *
 * Incoming status 1 belongs to LdF immediately after A/W registration.
 * Outgoing status 1 belongs to LdF only after the Sichter recorded the
 * mandatory formal approval. Status 2 belongs to A/W and is reachable only
 * after both approvals and the LdF transport decision. The direction/status
 * pairs are deliberately closed.
 */
function estab_message_operator_stage_predicate(
    string $direction,
    int $status
): string {
    if ($status === 1 && $direction === 'E') {
        return " AND `04_richtung` = '" . $direction . "'"
            . ' AND `x00_status` = 1'
            . ' AND `02_zeit` IS NULL AND `02_zeichen` = ?'
            . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
            . ' AND `15_quitdatum` IS NULL AND `15_quitzeichen` = ?'
            . " AND `x01_abschluss` = 'f'";
    }
    if ($status === 1 && $direction === 'A') {
        return " AND `04_richtung` = 'A'"
            . ' AND `x00_status` = 1'
            . ' AND `02_zeit` IS NULL AND `02_zeichen` = ?'
            . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
            . ' AND `15_quitdatum` IS NOT NULL AND `15_quitzeichen` <> ?'
            . " AND `x01_abschluss` = 'f'";
    }
    if ($status === 2 && $direction === 'A') {
        return " AND `04_richtung` = 'A'"
            . ' AND `x00_status` = 2'
            . ' AND `02_zeit` IS NOT NULL AND `02_zeichen` <> ?'
            . ' AND `06_befwegausw` <> ?'
            . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
            . ' AND `15_quitdatum` IS NOT NULL AND `15_quitzeichen` <> ?'
            . " AND `x01_abschluss` = 'f'";
    }
    throw new InvalidArgumentException('Invalid message operator stage');
}

/** Parameters matching estab_message_operator_stage_predicate(). */
function estab_message_operator_stage_parameters(
    string $direction,
    int $status
): array {
    estab_message_operator_stage_predicate($direction, $status);
    return $status === 1 ? ['', '', ''] : ['', '', '', ''];
}

/**
 * Revalidate the account and the current incident's stage policy atomically.
 *
 * STRICT binds status 1 to LdF and outgoing status 2 to Fernmelder. LOOSE
 * deliberately omits only that fixed function/role predicate; account,
 * incident, optional access-shift and messenger-availability checks remain.
 *
 * @return array{benutzer:string,kuerzel:string,funktion:string,rolle:string}
 */
function estab_message_require_operator_stage_actor(
    mysqli $connection,
    array $incident,
    array $actor,
    string $direction,
    int $status
): array {
    // Validate the closed direction/status vocabulary even in LOOSE.
    estab_message_operator_stage_predicate($direction, $status);
    $incidentId = (int) ($incident['active_einsatz_id'] ?? 0);
    $operationalActor = estab_dv_require_operational_account(
        $connection,
        $incidentId,
        $actor
    );
    if (!estab_incident_role_permissions_enforced($incident)) {
        return $operationalActor;
    }

    $requiredFunction = $status === 1 ? 'LdF' : 'A/W';
    if (
        !hash_equals(
            $requiredFunction,
            (string) $operationalActor['funktion']
        )
        || !hash_equals(
            'Fernmelder',
            (string) $operationalActor['rolle']
        )
    ) {
        throw new EstabDvPermissionException(
            'Diese Nachrichtenstufe ist im strengen Berechtigungsmodus '
                . 'der festgelegten Fernmeldefunktion vorbehalten.'
        );
    }
    return $operationalActor;
}

/**
 * Acquire one exact LdF/A-W stage without allowing stale queue identifiers to
 * cross a workflow transition.
 */
function estab_message_acquire_operator_stage_lock(
    mysqli $connection,
    string $table,
    mixed $recordId,
    array $actor,
    string $direction,
    int $status
): bool {
    $recordId = estab_message_positive_id($recordId);
    $stageSql = estab_message_operator_stage_predicate($direction, $status);
    $stageParameters = estab_message_operator_stage_parameters(
        $direction,
        $status
    );

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $actor,
            $direction,
            $status,
            $stageSql,
            $stageParameters
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $operationalActor = estab_message_require_operator_stage_actor(
                $connection,
                $incident,
                $actor,
                $direction,
                $status
            );
            $operatorCode = (string) $operationalActor['kuerzel'];
            $statement = estab_message_execute(
                $connection,
                'UPDATE ' . estab_message_table($table)
                    . " SET `x02_sperre` = 't', `x03_sperruser` = ?"
                    . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                    . $stageSql
                    . " AND (`x02_sperre` = 'f'"
                    . " OR (`x02_sperre` = 't' AND `x03_sperruser` = ?))",
                array_merge(
                    [$operatorCode, $recordId, $incidentId],
                    $stageParameters,
                    [$operatorCode]
                )
            );
            try {
                if ($statement->affected_rows === 1) {
                    return true;
                }
            } finally {
                $statement->close();
            }

            $verification = estab_message_execute(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($table)
                    . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                    . $stageSql
                    . " AND `x02_sperre` = 't' AND `x03_sperruser` = ?",
                array_merge(
                    [$recordId, $incidentId],
                    $stageParameters,
                    [$operatorCode]
                )
            );
            try {
                $row = $verification->get_result()->fetch_row();
                return ((int) ($row[0] ?? 0)) === 1;
            } finally {
                $verification->close();
            }
        }
    );
}

/**
 * Fetch the exact active-incident operator stage while this operator owns it.
 *
 * This read is used when an editable workflow form must be rebuilt after a
 * validation or domain conflict. Rehydrating immutable fields from the
 * browser would let a forged POST change the evidence shown to the operator,
 * even if persistence correctly ignored it.
 */
function estab_message_fetch_locked_operator_stage(
    mysqli $connection,
    string $table,
    mixed $recordId,
    string $operatorCode,
    string $direction,
    int $status
): ?array {
    $recordId = estab_message_positive_id($recordId);
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $operatorCode) !== 1) {
        throw new InvalidArgumentException('Invalid message lock owner');
    }
    $incident = estab_incident_active($connection);
    if ($incident === null) {
        return null;
    }
    $stageSql = estab_message_operator_stage_predicate($direction, $status);
    $stageParameters = estab_message_operator_stage_parameters(
        $direction,
        $status
    );
    $statement = estab_message_execute(
        $connection,
        'SELECT * FROM ' . estab_message_table($table)
            . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
            . $stageSql
            . " AND `x02_sperre` = 't'"
            . ' AND BINARY `x03_sperruser` = BINARY ?'
            . ' LIMIT 1',
        array_merge(
            [
                $recordId,
                (int) $incident['active_einsatz_id'],
            ],
            $stageParameters,
            [$operatorCode]
        )
    );
    try {
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/** Save one exact LdF/A-W stage while the same operator still owns it. */
function estab_message_update_locked_operator_stage(
    mysqli $connection,
    string $table,
    mixed $recordId,
    array $actor,
    string $direction,
    int $status,
    array $fields,
    array $event,
    mixed $expectedIncidentId = null
): bool {
    $recordId = estab_message_positive_id($recordId);
    $fields = estab_message_fields($fields);
    $stageSql = estab_message_operator_stage_predicate($direction, $status);
    $stageParameters = estab_message_operator_stage_parameters(
        $direction,
        $status
    );

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $actor,
            $direction,
            $status,
            $fields,
            $stageSql,
            $stageParameters,
            $event,
            $expectedIncidentId
        ): bool {
            $incidentId = estab_message_transaction_incident_id(
                $incident,
                $expectedIncidentId
            );
            $operationalActor = estab_message_require_operator_stage_actor(
                $connection,
                $incident,
                $actor,
                $direction,
                $status
            );
            $operatorCode = (string) $operationalActor['kuerzel'];
            // Evidence and lock ownership are one authenticated identity.
            $event['actor'] = $operationalActor;
            $incomingTbbCorrection = null;
            if ($direction === 'E' && $status === 1) {
                if (
                    ($event['snapshot']['incoming_transport_confirmed'] ?? null)
                    !== true
                ) {
                    throw new EstabDvInputException(
                        'Bestätigen Sie den vom Fernmelder erfassten Eingangsweg.'
                    );
                }
                if (!array_key_exists('01_medium', $fields)) {
                    throw new EstabDvInputException(
                        'LdF muss den Eingangsweg bestätigen.'
                    );
                }
                $requestedMedium = is_string($fields['01_medium'])
                    && in_array(
                        $fields['01_medium'],
                        ['Fe', 'Fu', 'Me', 'FAX', 'FS', '@'],
                        true
                    )
                    ? $fields['01_medium']
                    : null;
                if ($requestedMedium === null) {
                    throw new EstabDvInputException(
                        'Der bestätigte Eingangsweg ist ungültig.'
                    );
                }

                // Read the A/W value under the same active-incident and lock
                // predicate used by the update. Evidence must never trust a
                // browser-supplied "previous" medium.
                $previousMediumStatement = estab_message_execute(
                    $connection,
                    'SELECT `01_medium`, `13_abseinheit`, `05_gegenstelle` FROM '
                        . estab_message_table($table)
                        . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                        . $stageSql
                        . " AND `x02_sperre` = 't'"
                        . ' AND BINARY `x03_sperruser` = BINARY ?'
                        . ' FOR UPDATE',
                    array_merge(
                        [$recordId, $incidentId],
                        $stageParameters,
                        [$operatorCode]
                    )
                );
                try {
                    $previousMediumRow = $previousMediumStatement
                        ->get_result()
                        ->fetch_assoc();
                } finally {
                    $previousMediumStatement->close();
                }
                if (!is_array($previousMediumRow)) {
                    return false;
                }
                $previousMedium = (string) (
                    $previousMediumRow['01_medium'] ?? ''
                );
                $previousSenderUnit = trim((string) (
                    $previousMediumRow['13_abseinheit'] ?? ''
                ));
                $previousCallsign = trim((string) (
                    $previousMediumRow['05_gegenstelle'] ?? ''
                ));
                $previousSender = $previousSenderUnit !== ''
                    ? $previousSenderUnit
                    : ($previousCallsign !== ''
                        ? $previousCallsign
                        : 'nicht angegeben');
                if (
                    !in_array(
                        $previousMedium,
                        ['Fe', 'Fu', 'Me', 'FAX', 'FS', '@'],
                        true
                    )
                ) {
                    throw new EstabDvConflictException(
                        'Der vom Fernmelder dokumentierte Eingangsweg ist unvollständig.'
                    );
                }

                $reasonValue = $event['snapshot'][
                    'requested_transport_correction_reason'
                ] ?? '';
                if (!is_string($reasonValue)) {
                    throw new EstabDvInputException(
                        'Die Begründung der Wegkorrektur ist ungültig.'
                    );
                }
                $correctionReason = trim($reasonValue);
                $reasonLength = function_exists('mb_strlen')
                    ? mb_strlen($correctionReason, 'UTF-8')
                    : strlen($correctionReason);
                $reasonWithoutAllowedWhitespace = str_replace(
                    ["\t", "\r", "\n"],
                    '',
                    $correctionReason
                );
                if (
                    preg_match('//u', $correctionReason) !== 1
                    || $reasonLength > 500
                    || preg_match(
                        '/\p{C}/u',
                        $reasonWithoutAllowedWhitespace
                    ) === 1
                ) {
                    throw new EstabDvInputException(
                        'Die Begründung der Wegkorrektur ist ungültig.'
                    );
                }

                $transportCorrected = !hash_equals(
                    $previousMedium,
                    $requestedMedium
                );
                if ($transportCorrected && $correctionReason === '') {
                    throw new EstabDvInputException(
                        'Für die Korrektur des Eingangswegs ist eine '
                            . 'Begründung erforderlich.'
                    );
                }
                $fields['01_medium'] = $requestedMedium;
                unset(
                    $event['snapshot'][
                        'requested_transport_correction_reason'
                    ]
                );
                $event['snapshot']['incoming_transport_medium'] =
                    $requestedMedium;
                $event['snapshot']['incoming_transport_confirmed'] = true;
                $event['snapshot']['transport_confirmed_by'] =
                    $operatorCode;
                $event['snapshot']['transport_corrected'] =
                    $transportCorrected;
                if ($transportCorrected) {
                    $event['snapshot'][
                        'previous_incoming_transport_medium'
                    ] = $previousMedium;
                    $event['snapshot']['transport_correction_reason'] =
                        $correctionReason;
                }
                $confirmedSender = trim((string) (
                    $fields['13_abseinheit'] ?? ''
                ));
                if ($confirmedSender === '') {
                    throw new EstabDvInputException(
                        'LdF muss den Absender der Eingangsnachricht übersetzen.'
                    );
                }
                $incomingTbbCorrection = [
                    'before' => [
                        'medium' => $previousMedium,
                        'sender' => $previousSender,
                    ],
                    'after' => [
                        'medium' => $requestedMedium,
                        'sender' => $confirmedSender,
                    ],
                    'reason' => $correctionReason,
                ];
            }
            if ($direction === 'A' && $status === 1) {
                $previousRouteStatement = estab_message_execute(
                    $connection,
                    'SELECT `estab_fernmeldeplan_eintrag_id`,'
                        . ' `06_befwegausw`, `06_befweg` FROM '
                        . estab_message_table($table)
                        . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                        . " AND `04_richtung` = 'A'"
                        . ' AND `x00_status` = 1 FOR UPDATE',
                    [$recordId, $incidentId]
                );
                try {
                    $previousRoute = $previousRouteStatement
                        ->get_result()
                        ->fetch_assoc();
                } finally {
                    $previousRouteStatement->close();
                }
                if (!is_array($previousRoute)) {
                    return false;
                }
                if (
                    !array_key_exists(
                        'estab_fernmeldeplan_eintrag_id',
                        $fields
                    )
                ) {
                    throw new EstabDvInputException(
                        'Ein Weg aus dem aktiven S6-Fernmeldeplan ist erforderlich.'
                    );
                }
                $routeEntryId = estab_message_positive_id(
                    $fields['estab_fernmeldeplan_eintrag_id']
                );
                $previousRouteEntryId = (int) (
                    $previousRoute['estab_fernmeldeplan_eintrag_id'] ?? 0
                );
                if (
                    $previousRouteEntryId > 0
                    && $routeEntryId === $previousRouteEntryId
                ) {
                    throw new EstabDvConflictException(
                        'Nach einer Rückgabe ist ein anderer aktiver '
                        . 'S6-Beförderungsweg zu disponieren.'
                    );
                }
                $route = estab_dv_resolve_active_route(
                    $connection,
                    $incidentId,
                    $routeEntryId
                );
                $routeParts = array_values(array_filter(
                    [
                        trim((string) ($route['betriebsstelle'] ?? '')),
                        trim((string) ($route['rufname'] ?? '')),
                        trim((string) ($route['kanal'] ?? '')),
                        trim((string) ($route['bandlage'] ?? '')),
                        trim((string) ($route['verkehrsform'] ?? '')),
                    ],
                    static fn (string $part): bool => $part !== ''
                ));
                $routeText = estab_message_excerpt(
                    implode(' · ', $routeParts),
                    128
                );
                if ($routeText === '') {
                    throw new EstabDvConflictException(
                        'Der ausgewählte Fernmeldeweg hat keine lesbare Bezeichnung.'
                    );
                }
                $fields['estab_fernmeldeplan_eintrag_id'] = $routeEntryId;
                $fields['06_befwegausw'] = (string) $route['medium'];
                $fields['06_befweg'] = $routeText;
                $event['snapshot']['telecom_plan_id'] =
                    (int) $route['fernmeldeplan_id'];
                $event['snapshot']['telecom_plan_version'] =
                    (int) $route['version'];
                $event['snapshot']['telecom_plan_entry_id'] = $routeEntryId;
                $event['snapshot']['transport_medium'] =
                    (string) $route['medium'];
                $event['snapshot']['transport_route'] = $routeText;
                $event['snapshot']['route'] = [
                    'betriebsstelle' =>
                        (string) ($route['betriebsstelle'] ?? ''),
                    'rufname' => (string) ($route['rufname'] ?? ''),
                    'kanal' => (string) ($route['kanal'] ?? ''),
                    'bandlage' => (string) ($route['bandlage'] ?? ''),
                    'verkehrsform' =>
                        (string) ($route['verkehrsform'] ?? ''),
                ];
                if ($previousRouteEntryId > 0) {
                    $event['snapshot']['redisposition'] = true;
                    $event['snapshot']['previous_telecom_plan_entry_id'] =
                        $previousRouteEntryId;
                    $event['snapshot']['previous_transport_medium'] = trim(
                        (string) ($previousRoute['06_befwegausw'] ?? '')
                    );
                    $event['snapshot']['previous_transport_route'] = trim(
                        (string) ($previousRoute['06_befweg'] ?? '')
                    );
                }
            }
            if ($direction === 'A' && $status === 2) {
                $targetStatus = (int) ($fields['x00_status'] ?? 0);
                $mediumStatement = estab_message_execute(
                    $connection,
                    'SELECT `06_befwegausw`, `06_befweg`, '
                        . '`estab_fernmeldeplan_eintrag_id` FROM '
                        . estab_message_table($table)
                        . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                        . " AND `04_richtung` = 'A'"
                        . ' AND `x00_status` = 2 FOR UPDATE',
                    [$recordId, $incidentId]
                );
                try {
                    $mediumRow = $mediumStatement
                        ->get_result()
                        ->fetch_assoc();
                } finally {
                    $mediumStatement->close();
                }
                if (!is_array($mediumRow)) {
                    return false;
                }
                $confirmedRouteId = (int) (
                    $mediumRow['estab_fernmeldeplan_eintrag_id'] ?? 0
                );
                $confirmedMedium = trim(
                    (string) ($mediumRow['06_befwegausw'] ?? '')
                );
                $confirmedRoute = trim(
                    (string) ($mediumRow['06_befweg'] ?? '')
                );
                if (
                    $confirmedRouteId < 1
                    || $confirmedMedium === ''
                    || $confirmedRoute === ''
                ) {
                    throw new EstabDvConflictException(
                        'Der disponierte S6-Beförderungsweg ist unvollständig.'
                    );
                }

                if ($targetStatus === 8) {
                    if (
                        ($event['snapshot']['transport_route_confirmed'] ?? null)
                        !== true
                    ) {
                        throw new EstabDvInputException(
                            'Bestätigen Sie den disponierten S6-Beförderungsweg.'
                        );
                    }
                    // The immutable message row, not browser-supplied 06_*
                    // fields, is the authoritative proof of the confirmation.
                    $event['snapshot']['telecom_plan_entry_id'] =
                        $confirmedRouteId;
                    $event['snapshot']['transport_medium'] = $confirmedMedium;
                    $event['snapshot']['transport_route'] = $confirmedRoute;
                    if ($confirmedMedium === 'Me') {
                        $messengerJob =
                            estab_dv_require_messenger_reported_for_message(
                                $connection,
                                $incidentId,
                                $recordId
                            );
                        $event['snapshot']['messenger_job_id'] =
                            (int) $messengerJob['melderauftrag_id'];
                        $event['snapshot']['messenger_status'] =
                            (string) $messengerJob['status'];
                        $event['snapshot']['messenger_reported_at'] =
                            (string) $messengerJob['gemeldet_am'];
                        $event['snapshot']['messenger_reported_to'] =
                            (string) $messengerJob['gemeldet_an'];
                    }
                } elseif (
                    $targetStatus === 1
                    && ($event['event_type'] ?? null)
                        === 'aw_transport_returned'
                ) {
                    $returnReason = trim(
                        (string) (
                            $event['snapshot']['transport_return_reason'] ?? ''
                        )
                    );
                    if (
                        $returnReason === ''
                        || strlen($returnReason) > 2000
                        || str_contains($returnReason, "\0")
                    ) {
                        throw new EstabDvInputException(
                            'Für die Rückgabe an LdF ist ein Grund erforderlich.'
                        );
                    }
                    $messengerHistory =
                        estab_dv_require_no_open_messenger_for_redispatch(
                            $connection,
                            $incidentId,
                            $recordId
                        );
                    $event['snapshot']['transport_return_reason'] =
                        $returnReason;
                    $event['snapshot']['rejected_telecom_plan_entry_id'] =
                        $confirmedRouteId;
                    $event['snapshot']['rejected_transport_medium'] =
                        $confirmedMedium;
                    $event['snapshot']['rejected_transport_route'] =
                        $confirmedRoute;
                    if ($messengerHistory !== []) {
                        $event['snapshot']['previous_messenger_jobs'] =
                            array_map(
                                static fn (array $job): array => [
                                    'melderauftrag_id' =>
                                        (int) $job['melderauftrag_id'],
                                    'status' => (string) $job['status'],
                                ],
                                $messengerHistory
                            );
                    }
                } else {
                    throw new EstabDvConflictException(
                        'Ungültiger Übergang in der Fernmelder-Beförderung.'
                    );
                }
            }
            $assignments = [];
            foreach (array_keys($fields) as $column) {
                $assignments[] = '`' . $column . '` = ?';
            }
            $statement = estab_message_execute(
                $connection,
                'UPDATE ' . estab_message_table($table)
                    . ' SET ' . implode(', ', $assignments)
                    . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                    . $stageSql
                    . " AND `x02_sperre` = 't' AND `x03_sperruser` = ?",
                array_merge(
                    array_values($fields),
                    [
                        $recordId,
                        $incidentId,
                    ],
                    $stageParameters,
                    [$operatorCode]
                )
            );
            try {
                if ($statement->affected_rows !== 1) {
                    return false;
                }
                estab_message_append_transition_evidence(
                    $connection,
                    $incidentId,
                    $recordId,
                    $event
                );
                if (is_array($incomingTbbCorrection)) {
                    $occurredAt = is_string($event['occurred_at'] ?? null)
                        ? (string) $event['occurred_at']
                        : date('Y-m-d H:i:s');
                    estab_logbook_lifecycle_message_transport_correction(
                        $connection,
                        $incidentId,
                        $recordId,
                        $occurredAt,
                        $operatorCode,
                        $incomingTbbCorrection['before'],
                        $incomingTbbCorrection['after'],
                        (string) $incomingTbbCorrection['reason']
                    );
                }
                if (
                    $direction === 'A'
                    && $status === 2
                    && (int) ($fields['x00_status'] ?? 0) === 8
                    && ($event['event_type'] ?? null) === 'aw_transported'
                ) {
                    $occurredAt = is_string($event['occurred_at'] ?? null)
                        ? (string) $event['occurred_at']
                        : date('Y-m-d H:i:s');
                    estab_logbook_lifecycle_message_transport(
                        $connection,
                        $incidentId,
                        $recordId,
                        $occurredAt,
                        'A'
                    );
                }
                return true;
            } finally {
                $statement->close();
            }
        }
    );
}

/** Release one exact LdF/A-W stage; force is reserved for an explicit reset. */
function estab_message_release_operator_stage_lock(
    mysqli $connection,
    string $table,
    mixed $recordId,
    string $direction,
    int $status,
    array $actor,
    bool $force = false
): bool {
    $recordId = estab_message_positive_id($recordId);
    $stageSql = estab_message_operator_stage_predicate($direction, $status);
    $stageParameters = estab_message_operator_stage_parameters(
        $direction,
        $status
    );

    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $actor,
            $direction,
            $status,
            $force,
            $stageSql,
            $stageParameters
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $operationalActor = estab_message_require_operator_stage_actor(
                $connection,
                $incident,
                $actor,
                $direction,
                $status
            );
            $operatorCode = (string) $operationalActor['kuerzel'];
            $sql = 'UPDATE ' . estab_message_table($table)
                . " SET `x02_sperre` = 'f', `x03_sperruser` = ''"
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                . $stageSql;
            $parameters = array_merge(
                [$recordId, $incidentId],
                $stageParameters
            );
            if (!$force) {
                $sql .= " AND (`x02_sperre` = 'f' OR `x03_sperruser` = ?)";
                $parameters[] = $operatorCode;
            }
            $statement = estab_message_execute(
                $connection,
                $sql,
                $parameters
            );
            try {
                if ($statement->affected_rows === 1) {
                    return true;
                }
            } finally {
                $statement->close();
            }

            $verification = estab_message_execute(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($table)
                    . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                    . $stageSql
                    . " AND `x02_sperre` = 'f' AND `x03_sperruser` = ?",
                array_merge(
                    [$recordId, $incidentId],
                    $stageParameters,
                    ['']
                )
            );
            try {
                $row = $verification->get_result()->fetch_row();
                return ((int) ($row[0] ?? 0)) === 1;
            } finally {
                $verification->close();
            }
        }
    );
}

/**
 * Atomically transition a message that is still in the viewer queue.
 *
 * Incoming messages reach the queue after LdF translated the sender.
 * Outgoing messages reach it before any LdF/A-W field is populated. The
 * caller selects status 1 for formal approval, status 10 for a return to the
 * author, or status 8 for completed incoming sighting.
 */
function estab_message_update_pending_review(
    mysqli $connection,
    string $table,
    mixed $recordId,
    array $fields,
    array $event,
    mixed $expectedIncidentId = null
): bool {
    $recordId = estab_message_positive_id($recordId);
    $fields = estab_message_fields($fields);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $fields,
            $event,
            $expectedIncidentId
        ): bool {
            $incidentId = estab_message_transaction_incident_id(
                $incident,
                $expectedIncidentId
            );
            $assignments = [];
            foreach (array_keys($fields) as $column) {
                $assignments[] = '`' . $column . '` = ?';
            }
            $parameters = array_values($fields);
            $parameters[] = $recordId;
            $parameters[] = $incidentId;
            $parameters[] = '';
            $parameters[] = '';
            $sql = 'UPDATE ' . estab_message_table($table)
                . ' SET ' . implode(', ', $assignments)
                . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                . ' AND `x00_status` = 4'
                . ' AND `15_quitdatum` IS NULL AND `15_quitzeichen` = ?'
                . " AND (`04_richtung` = 'E'";
            $sql .= " OR (`04_richtung` = 'A'"
                . ' AND `02_zeit` IS NULL AND `02_zeichen` = ?'
                . ' AND `03_datum` IS NULL AND `03_zeichen` = ?)';
            $parameters[] = '';
            $sql .= ')';
            $statement = estab_message_execute($connection, $sql, $parameters);
            try {
                if ($statement->affected_rows !== 1) {
                    return false;
                }
                estab_message_append_transition_evidence(
                    $connection,
                    $incidentId,
                    $recordId,
                    $event
                );
                return true;
            } finally {
                $statement->close();
            }
        }
    );
}

/**
 * Resubmit one formally returned outgoing message under its staff function.
 *
 * STRICT permits another account only within the original staff function.
 * LOOSE permits another operational function to take over the correction.
 * Old and new responsibility remain explicit in the transition snapshot.
 */
function estab_message_resubmit_returned_outgoing(
    mysqli $connection,
    string $table,
    mixed $recordId,
    string $authorCode,
    string $authorFunction,
    array $fields,
    array $event,
    ?string $attachmentTable = null,
    ?callable $attachmentAuthorizer = null,
    mixed $expectedIncidentId = null
): bool {
    $recordId = estab_message_positive_id($recordId);
    if (
        preg_match('/\A[a-z0-9_]{1,6}\z/D', $authorCode) !== 1
        || trim($authorFunction) === ''
        || strlen($authorFunction) > 25
        || str_contains($authorFunction, "\0")
    ) {
        throw new InvalidArgumentException('Invalid returned-message author');
    }
    $fields = estab_message_fields(
        estab_message_existing_record_fields($fields)
    );
    if (
        !hash_equals($authorCode, (string) ($fields['14_zeichen'] ?? ''))
        || !hash_equals(
            $authorFunction,
            (string) ($fields['14_funktion'] ?? '')
        )
    ) {
        throw new InvalidArgumentException(
            'Returned-message responsibility does not match the actor'
        );
    }
    $eventActor = $event['actor'] ?? null;
    if (
        !is_array($eventActor)
        || !hash_equals(
            $authorCode,
            (string) ($eventActor['kuerzel'] ?? '')
        )
        || !hash_equals(
            $authorFunction,
            (string) ($eventActor['funktion'] ?? '')
        )
    ) {
        throw new InvalidArgumentException(
            'Returned-message evidence does not match the actor'
        );
    }
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $authorCode,
            $authorFunction,
            $fields,
            $event,
            $attachmentTable,
            $attachmentAuthorizer,
            $expectedIncidentId
        ): bool {
            $incidentId = estab_message_transaction_incident_id(
                $incident,
                $expectedIncidentId
            );
            $rolePermissionsEnforced =
                estab_incident_role_permissions_enforced($incident);
            $fields = estab_message_bind_command_post(
                $fields,
                $incident,
                'A'
            );
            $originalStatement = estab_message_execute(
                $connection,
                'SELECT * FROM '
                    . estab_message_table($table)
                    . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                    . " AND `04_richtung` = 'A' AND `x00_status` = 10"
                    . ' AND `02_zeit` IS NULL AND `02_zeichen` = ?'
                    . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
                    . ' AND `15_quitdatum` IS NOT NULL'
                    . " AND `15_quitzeichen` <> ?"
                    . " AND `x01_abschluss` = 'f'"
                    . " AND `x02_sperre` = 'f'"
                    . ' AND `x03_sperruser` = ? FOR UPDATE',
                [
                    $recordId,
                    $incidentId,
                    '',
                    '',
                    '',
                    '',
                ]
            );
            try {
                $originalMessage = $originalStatement
                    ->get_result()
                    ->fetch_assoc();
            } finally {
                $originalStatement->close();
            }
            if (
                !is_array($originalMessage)
                || (
                    $rolePermissionsEnforced
                    && !hash_equals(
                        (string) ($originalMessage['14_funktion'] ?? ''),
                        $authorFunction
                    )
                )
            ) {
                return false;
            }
            if (array_key_exists('12_anhang', $fields)) {
                if ($attachmentTable === null) {
                    throw new LogicException(
                        'Message attachment table is required for resubmission'
                    );
                }
                estab_message_require_attachment_scope(
                    $connection,
                    $attachmentTable,
                    $incidentId,
                    $fields['12_anhang']
                );
                if (
                    (string) $fields['12_anhang'] !== ''
                    && $attachmentAuthorizer === null
                ) {
                    throw new LogicException(
                        'Message attachment authorizer is required'
                    );
                }
                if (
                    (string) $fields['12_anhang'] !== ''
                    && $attachmentAuthorizer !== null
                ) {
                    $attachmentAuthorizer(
                        $connection,
                        $incidentId,
                        $fields['12_anhang'],
                        $originalMessage
                    );
                }
            }

            $assignments = [];
            foreach (array_keys($fields) as $column) {
                $assignments[] = '`' . $column . '` = ?';
            }
            $updateParameters = array_merge(
                array_values($fields),
                [
                    $recordId,
                    (int) $incident['active_einsatz_id'],
                ]
            );
            $authorPredicate = '';
            if ($rolePermissionsEnforced) {
                $authorPredicate = ' AND `14_funktion` = ?';
                $updateParameters[] = $authorFunction;
            }
            $updateParameters = array_merge(
                $updateParameters,
                ['', '', '', '']
            );
            $statement = estab_message_execute(
                $connection,
                'UPDATE ' . estab_message_table($table)
                    . ' SET ' . implode(', ', $assignments)
                    . ' WHERE `00_lfd` = ? AND `einsatz_id` = ?'
                    . " AND `04_richtung` = 'A' AND `x00_status` = 10"
                    . $authorPredicate
                    . ' AND `02_zeit` IS NULL AND `02_zeichen` = ?'
                    . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
                    . ' AND `15_quitdatum` IS NOT NULL'
                    . " AND `15_quitzeichen` <> ?"
                    . " AND `x01_abschluss` = 'f'"
                    . " AND `x02_sperre` = 'f'"
                    . ' AND `x03_sperruser` = ?',
                $updateParameters
            );
            try {
                if ($statement->affected_rows !== 1) {
                    return false;
                }
                $event['snapshot']['original_author_code'] =
                    (string) ($originalMessage['14_zeichen'] ?? '');
                $event['snapshot']['original_author_function'] =
                    (string) ($originalMessage['14_funktion'] ?? '');
                $event['snapshot']['responsible_author_code'] = $authorCode;
                $event['snapshot']['responsible_author_function'] =
                    $authorFunction;
                $event['snapshot']['correction_note'] =
                    (string) ($originalMessage['17_vermerke'] ?? '');
                estab_message_append_transition_evidence(
                    $connection,
                    $incidentId,
                    $recordId,
                    $event
                );
                return true;
            } finally {
                $statement->close();
            }
        }
    );
}

/** Fetch one complete message from one explicitly captured incident. */
function estab_message_fetch_for_incident_by_id(
    mysqli $connection,
    string $table,
    mixed $recordId,
    mixed $incidentId
): ?array {
    $recordId = estab_message_positive_id($recordId);
    $incidentId = estab_incident_positive_id($incidentId);
    $quotedTable = estab_message_table($table);
    $statement = estab_message_execute(
        $connection,
        'SELECT message_row.*,'
            . ' (SELECT ttb_row.`estab_book_lfd` FROM `nv_tbb` AS ttb_row'
            . ' WHERE ttb_row.`einsatz_id` = message_row.`einsatz_id`'
            . ' AND ttb_row.`estab_message_id` = message_row.`00_lfd`'
            . " AND BINARY ttb_row.`estab_entry_type` = BINARY 'nachricht'"
            . ' ORDER BY ttb_row.`estab_book_lfd`,'
            . ' ttb_row.`tbb_lfd-nr` LIMIT 1)'
            . ' AS `estab_ttb_lfd` FROM ' . $quotedTable . ' AS message_row'
            . ' WHERE message_row.`00_lfd` = ?'
            . ' AND message_row.`einsatz_id` = ? LIMIT 1',
        [$recordId, $incidentId]
    );
    try {
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/**
 * Fetch one complete message from the active incident.
 *
 * Callers that already captured an incident scope must use
 * estab_message_fetch_for_incident_by_id() so a concurrent activation cannot
 * replace their authorization boundary.
 */
function estab_message_fetch_by_id(
    mysqli $connection,
    string $table,
    mixed $recordId
): ?array {
    $recordId = estab_message_positive_id($recordId);
    $incident = estab_incident_active($connection);
    if ($incident === null) {
        return null;
    }
    return estab_message_fetch_for_incident_by_id(
        $connection,
        $table,
        $recordId,
        $incident['active_einsatz_id']
    );
}

/** Run a prepared read query and return all associative rows. */
function estab_message_query_rows(
    mysqli $connection,
    string $sql,
    array $parameters = []
): array {
    $statement = estab_message_execute($connection, $sql, $parameters);
    try {
        $result = $statement->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    } finally {
        $statement->close();
    }
}

/** Run a prepared read query whose first column is an integer. */
function estab_message_query_int(
    mysqli $connection,
    string $sql,
    array $parameters = []
): int {
    $statement = estab_message_execute($connection, $sql, $parameters);
    try {
        $row = $statement->get_result()->fetch_row();
        return (int) ($row[0] ?? 0);
    } finally {
        $statement->close();
    }
}

/**
 * Atomically acquire a pending outgoing record for the current A/W operator.
 *
 * Ownership is bound to the still-untransported status so a stale or foreign
 * record identifier cannot be turned into an editable message.
 */
function estab_message_acquire_outgoing_lock(
    mysqli $connection,
    string $table,
    mixed $recordId,
    string $operatorCode
): bool {
    $recordId = estab_message_positive_id($recordId);
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $operatorCode) !== 1) {
        throw new InvalidArgumentException('Invalid message lock owner');
    }
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $operatorCode
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $statement = estab_message_execute(
                $connection,
                'UPDATE ' . estab_message_table($table)
                    . " SET `x02_sperre` = 't', `x03_sperruser` = ?"
                    . " WHERE `00_lfd` = ? AND `einsatz_id` = ?"
                    . " AND `04_richtung` = 'A'"
                    . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
                    . " AND (`x02_sperre` = 'f'"
                    . " OR (`x02_sperre` = 't' AND `x03_sperruser` = ?))",
                [$operatorCode, $recordId, $incidentId, '', $operatorCode]
            );
            try {
                if ($statement->affected_rows === 1) {
                    return true;
                }
            } finally {
                $statement->close();
            }

            // MariaDB reports zero affected rows when the same owner re-opens
            // an already acquired lock. Verify that idempotent success.
            $verification = estab_message_execute(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($table)
                    . " WHERE `00_lfd` = ? AND `einsatz_id` = ?"
                    . " AND `04_richtung` = 'A'"
                    . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
                    . " AND `x02_sperre` = 't' AND `x03_sperruser` = ?",
                [$recordId, $incidentId, '', $operatorCode]
            );
            try {
                $row = $verification->get_result()->fetch_row();
                return ((int) ($row[0] ?? 0)) === 1;
            } finally {
                $verification->close();
            }
        }
    );
}

/** Release a lock owned by the operator; force is reserved for reset workflow. */
function estab_message_release_lock(
    mysqli $connection,
    string $table,
    mixed $recordId,
    ?string $operatorCode = null
): bool {
    $recordId = estab_message_positive_id($recordId);
    if (
        $operatorCode !== null
        && preg_match('/\A[a-z0-9_]{1,6}\z/D', $operatorCode) !== 1
    ) {
        throw new InvalidArgumentException('Invalid message lock owner');
    }
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $recordId,
            $operatorCode
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $sql = 'UPDATE ' . estab_message_table($table)
                . " SET `x02_sperre` = 'f', `x03_sperruser` = ''"
                . " WHERE `00_lfd` = ? AND `einsatz_id` = ?"
                . " AND `04_richtung` = 'A'"
                . ' AND `03_datum` IS NULL AND `03_zeichen` = ?';
            $parameters = [$recordId, $incidentId, ''];
            if ($operatorCode !== null) {
                $sql .= " AND (`x02_sperre` = 'f' OR `x03_sperruser` = ?)";
                $parameters[] = $operatorCode;
            }
            $statement = estab_message_execute($connection, $sql, $parameters);
            try {
                if ($statement->affected_rows === 1) {
                    return true;
                }
            } finally {
                $statement->close();
            }

            // Zero affected rows is successful only when the addressed active
            // incident row is already unlocked.
            $verification = estab_message_execute(
                $connection,
                'SELECT COUNT(*) FROM ' . estab_message_table($table)
                    . " WHERE `00_lfd` = ? AND `einsatz_id` = ?"
                    . " AND `04_richtung` = 'A'"
                    . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
                    . " AND `x02_sperre` = 'f' AND `x03_sperruser` = ?",
                [$recordId, $incidentId, '', '']
            );
            try {
                $row = $verification->get_result()->fetch_row();
                return ((int) ($row[0] ?? 0)) === 1;
            } finally {
                $verification->close();
            }
        }
    );
}

/** Construct one of the two runtime state tables from validated identity data. */
function estab_message_state_table(
    string $prefix,
    string $function,
    string $userCode,
    string $state
): string {
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $prefix) !== 1) {
        throw new InvalidArgumentException('Invalid state table prefix');
    }
    if (
        preg_match('/\A[A-Za-z0-9_]{1,10}\z/D', $function) !== 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $userCode) !== 1
    ) {
        throw new InvalidArgumentException('Invalid state table identity');
    }
    return match ($state) {
        'read' => strtolower($prefix . $function . '_' . $userCode . '_read'),
        'done' => strtolower($prefix . '_fkt_' . $function . '_erl'),
        default => throw new InvalidArgumentException('Invalid message state'),
    };
}

function estab_message_state_exists(
    mysqli $connection,
    string $stateTable,
    mixed $recordId,
    string $messageTable
): bool {
    $recordId = estab_message_positive_id($recordId);
    $incident = estab_incident_active($connection);
    if ($incident === null) {
        return false;
    }
    $statement = estab_message_execute(
        $connection,
        'SELECT COUNT(*) FROM ' . estab_message_table($stateTable) . ' AS s'
            . ' INNER JOIN ' . estab_message_table($messageTable) . ' AS m'
            . ' ON m.`00_lfd` = s.`nachnum`'
            . ' WHERE s.`nachnum` = ? AND m.`einsatz_id` = ?',
        [$recordId, (int) $incident['active_einsatz_id']]
    );
    try {
        $row = $statement->get_result()->fetch_row();
        return ((int) ($row[0] ?? 0)) > 0;
    } finally {
        $statement->close();
    }
}

/** Build the exact comma-delimited recipient predicate used by SQL writes. */
function estab_message_recipient_pattern(string $function): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,10}\z/D', $function) !== 1) {
        throw new InvalidArgumentException('Invalid message recipient function');
    }
    return '(^|,)[[:space:]]*(alle|' . preg_quote($function, '/')
        . ')(_[^,[:space:]]+)?[[:space:]]*(,|$)';
}

/**
 * SQL object gate shared by staff lists and recipient-state mutations.
 *
 * Bind the exact recipient REGEXP first and the active function second.
 * Foreign recipients receive an object only at terminal status 8. The
 * function author may continue to inspect their own outgoing object while it
 * moves through review, disposition, return and transport.
 */
function estab_message_staff_access_sql(string $alias = 'm'): string
{
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $alias) !== 1) {
        throw new InvalidArgumentException('Invalid message table alias');
    }
    $prefix = $alias . '.';
    return '((' . $prefix . '`x00_status` = 8'
        . ' AND ' . $prefix . '`16_empf` REGEXP ?)'
        . " OR (" . $prefix . "`04_richtung` = 'A'"
        . ' AND BINARY ' . $prefix . '`14_funktion` = BINARY ?))';
}

/** One advisory-lock namespace serializes set/unset for a state row. */
function estab_message_state_lock_name(string $table, int $recordId): string
{
    estab_message_table($table);
    return 'estab-state-' . substr(
        hash('sha256', $table . "\0" . (string) $recordId),
        0,
        36
    );
}

function estab_message_acquire_state_lock(
    mysqli $connection,
    string $table,
    int $recordId
): string {
    $lockName = estab_message_state_lock_name($table, $recordId);
    $statement = estab_message_execute(
        $connection,
        'SELECT GET_LOCK(?, 10)',
        [$lockName]
    );
    try {
        $row = $statement->get_result()->fetch_row();
    } finally {
        $statement->close();
    }
    if (($row[0] ?? null) !== 1 && ($row[0] ?? null) !== '1') {
        throw new RuntimeException('Could not serialize message state');
    }
    return $lockName;
}

function estab_message_release_state_lock(
    mysqli $connection,
    string $lockName
): void {
    $statement = estab_message_execute(
        $connection,
        'SELECT RELEASE_LOCK(?)',
        [$lockName]
    );
    $statement->close();
}

/** Idempotently record read/done state with a bound message identifier. */
function estab_message_state_set(
    mysqli $connection,
    string $stateTable,
    mixed $recordId,
    string $state,
    string $timestamp,
    string $messageTable
): void {
    $recordId = estab_message_positive_id($recordId);
    $dateColumn = match ($state) {
        'read' => 'gelesen',
        'done' => 'erledigt',
        default => throw new InvalidArgumentException('Invalid message state'),
    };
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $stateTable,
            $messageTable,
            $recordId,
            $dateColumn,
            $timestamp
        ): void {
            $lockName = estab_message_acquire_state_lock(
                $connection,
                $stateTable,
                $recordId
            );
            try {
                $statement = estab_message_execute(
                    $connection,
                    'INSERT INTO ' . estab_message_table($stateTable)
                        . ' (`nachnum`, `' . $dateColumn . '`)'
                        . ' SELECT m.`00_lfd`, ? FROM '
                        . estab_message_table($messageTable) . ' AS m'
                        . ' WHERE m.`00_lfd` = ? AND m.`einsatz_id` = ?'
                        . ' AND NOT EXISTS (SELECT 1 FROM '
                        . estab_message_table($stateTable)
                        . ' WHERE `nachnum` = ?)',
                    [
                        $timestamp,
                        $recordId,
                        (int) $incident['active_einsatz_id'],
                        $recordId,
                    ]
                );
                $statement->close();
            } finally {
                estab_message_release_state_lock($connection, $lockName);
            }
        }
    );
}

function estab_message_state_unset(
    mysqli $connection,
    string $stateTable,
    mixed $recordId,
    string $messageTable
): void {
    $recordId = estab_message_positive_id($recordId);
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $stateTable,
            $messageTable,
            $recordId
        ): void {
            $lockName = estab_message_acquire_state_lock(
                $connection,
                $stateTable,
                $recordId
            );
            try {
                $statement = estab_message_execute(
                    $connection,
                    'DELETE s FROM ' . estab_message_table($stateTable) . ' AS s'
                        . ' INNER JOIN ' . estab_message_table($messageTable)
                        . ' AS m ON m.`00_lfd` = s.`nachnum`'
                        . ' WHERE s.`nachnum` = ? AND m.`einsatz_id` = ?',
                    [$recordId, (int) $incident['active_einsatz_id']]
                );
                $statement->close();
            } finally {
                estab_message_release_state_lock($connection, $lockName);
            }
        }
    );
}

/**
 * Record state only if the addressed message is still visible to the staff
 * function in the same conditional INSERT that performs the write.
 */
function estab_message_state_set_for_recipient(
    mysqli $connection,
    string $messageTable,
    string $stateTable,
    mixed $recordId,
    array $actor,
    string $state,
    string $timestamp
): bool {
    $recordId = estab_message_positive_id($recordId);
    $dateColumn = match ($state) {
        'read' => 'gelesen',
        'done' => 'erledigt',
        default => throw new InvalidArgumentException('Invalid message state'),
    };
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $messageTable,
            $stateTable,
            $recordId,
            $dateColumn,
            $timestamp,
            $actor
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $operationalActor = estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $actor
            );
            $function = (string) $operationalActor['funktion'];
            $recipientPattern = estab_message_recipient_pattern($function);
            $lockName = estab_message_acquire_state_lock(
                $connection,
                $stateTable,
                $recordId
            );
            try {
                $statement = estab_message_execute(
                    $connection,
                    'INSERT INTO ' . estab_message_table($stateTable)
                        . ' (`nachnum`, `' . $dateColumn . '`)'
                        . ' SELECT m.`00_lfd`, ? FROM '
                        . estab_message_table($messageTable) . ' AS m'
                        . ' WHERE m.`00_lfd` = ? AND m.`einsatz_id` = ?'
                        . ' AND ' . estab_message_staff_access_sql('m')
                        . ' AND NOT EXISTS (SELECT 1 FROM '
                        . estab_message_table($stateTable)
                        . ' WHERE `nachnum` = ?)',
                    [
                        $timestamp,
                        $recordId,
                        $incidentId,
                        $recipientPattern,
                        $function,
                        $recordId,
                    ]
                );
                try {
                    if ($statement->affected_rows === 1) {
                        return true;
                    }
                } finally {
                    $statement->close();
                }

                // Distinguish an authorised idempotent repeat from a missing,
                // inactive or newly foreign object without leaving the lock.
                $verification = estab_message_execute(
                    $connection,
                    'SELECT COUNT(*) FROM '
                        . estab_message_table($messageTable) . ' AS m'
                        . ' INNER JOIN ' . estab_message_table($stateTable)
                        . ' AS s ON s.`nachnum` = m.`00_lfd`'
                        . ' WHERE m.`00_lfd` = ? AND m.`einsatz_id` = ?'
                        . ' AND ' . estab_message_staff_access_sql('m'),
                    [$recordId, $incidentId, $recipientPattern, $function]
                );
                try {
                    $row = $verification->get_result()->fetch_row();
                    return ((int) ($row[0] ?? 0)) > 0;
                } finally {
                    $verification->close();
                }
            } finally {
                estab_message_release_state_lock($connection, $lockName);
            }
        }
    );
}

/**
 * Remove state only while the message is still addressed to the staff
 * function. Concurrent set/unset requests share the same state-row lock.
 */
function estab_message_state_unset_for_recipient(
    mysqli $connection,
    string $messageTable,
    string $stateTable,
    mixed $recordId,
    array $actor
): bool {
    $recordId = estab_message_positive_id($recordId);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $messageTable,
            $stateTable,
            $recordId,
            $actor
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            $operationalActor = estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $actor
            );
            $function = (string) $operationalActor['funktion'];
            $recipientPattern = estab_message_recipient_pattern($function);
            $lockName = estab_message_acquire_state_lock(
                $connection,
                $stateTable,
                $recordId
            );
            try {
                $statement = estab_message_execute(
                    $connection,
                    'DELETE s FROM ' . estab_message_table($stateTable) . ' AS s'
                        . ' INNER JOIN ' . estab_message_table($messageTable)
                        . ' AS m ON m.`00_lfd` = s.`nachnum`'
                        . ' WHERE s.`nachnum` = ? AND m.`einsatz_id` = ?'
                        . ' AND ' . estab_message_staff_access_sql('m'),
                    [$recordId, $incidentId, $recipientPattern, $function]
                );
                try {
                    if ($statement->affected_rows > 0) {
                        return true;
                    }
                } finally {
                    $statement->close();
                }

                // No state row is an idempotent success only for an active
                // incident object that remains visible to this function.
                $verification = estab_message_execute(
                    $connection,
                    'SELECT COUNT(*) FROM '
                        . estab_message_table($messageTable) . ' AS m'
                        . ' WHERE m.`00_lfd` = ? AND m.`einsatz_id` = ?'
                        . ' AND ' . estab_message_staff_access_sql('m'),
                    [$recordId, $incidentId, $recipientPattern, $function]
                );
                try {
                    $row = $verification->get_result()->fetch_row();
                    return ((int) ($row[0] ?? 0)) === 1;
                } finally {
                    $verification->close();
                }
            } finally {
                estab_message_release_state_lock($connection, $lockName);
            }
        }
    );
}

/** @return list<int> */
function estab_message_state_ids(
    mysqli $connection,
    string $messageTable,
    string $stateTable
): array {
    $incident = estab_incident_active($connection);
    if ($incident === null) {
        return [];
    }
    $statement = estab_message_execute(
        $connection,
        'SELECT DISTINCT m.`00_lfd` FROM ' . estab_message_table($messageTable)
            . ' AS m INNER JOIN ' . estab_message_table($stateTable)
            . ' AS s ON m.`00_lfd` = s.`nachnum`'
            . ' WHERE m.`einsatz_id` = ?',
        [(int) $incident['active_einsatz_id']]
    );
    try {
        $result = $statement->get_result();
        $ids = [];
        while ($row = $result->fetch_row()) {
            $id = (int) ($row[0] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    } finally {
        $statement->close();
    }
}

/** Exact receiver-token check; substring matches are not authorisation. */
function estab_message_is_recipient(array $message, string $function): bool
{
    foreach (explode(',', (string) ($message['16_empf'] ?? '')) as $token) {
        $token = trim($token);
        if ($token === 'alle' || str_starts_with($token, 'alle_')) {
            return true;
        }
        if ($token === $function || str_starts_with($token, $function . '_')) {
            return true;
        }
    }
    return false;
}

/** Return whether LOOSE may replace only this operation's fixed role gate. */
function estab_message_operation_relaxes_write_role(string $operation): bool
{
    return in_array($operation, [
        'staff-state',
        'staff-correction',
        'telecommunications-lead-edit',
        'telecommunications-lead-incoming-save',
        'telecommunications-lead-outgoing-save',
        'telecommunications-edit',
        'telecommunications-save',
        'viewer-review',
        'telecommunications-reset',
        'message-operator-reset',
    ], true);
}

/** Pure object-level route decision used after the role gate. */
function estab_message_object_allowed(
    array $identity,
    string $operation,
    array $message,
    bool $allowLooseWriteMode = false
): bool {
    $rolesRelaxed = $allowLooseWriteMode
        && estab_message_operation_relaxes_write_role($operation)
        && !estab_permission_role_checks_enforced();
    $isTelecommunications = $rolesRelaxed || (
        ($identity['funktion'] ?? '') === 'A/W'
        && ($identity['rolle'] ?? '') === 'Fernmelder'
    );
    $isTelecommunicationsLead = $rolesRelaxed || (
        ($identity['funktion'] ?? '') === 'LdF'
        && ($identity['rolle'] ?? '') === 'Fernmelder'
    );
    $isViewer = $rolesRelaxed || (
        ($identity['funktion'] ?? '') === 'Si'
        && ($identity['rolle'] ?? '') === 'Stab'
    );
    $isStaff = $rolesRelaxed || (
        ($identity['funktion'] ?? '') !== 'Si'
        && in_array(($identity['rolle'] ?? ''), ['Stab', 'FB'], true)
    );
    $status = filter_var(
        $message['x00_status'] ?? null,
        FILTER_VALIDATE_INT
    );
    $direction = (string) ($message['04_richtung'] ?? '');
    $hasViewerApproval =
        !estab_datetime_is_unset($message['15_quitdatum'] ?? null)
        && (string) ($message['15_quitzeichen'] ?? '') !== '';
    $isPendingIncomingLead = $status === 1
        && $direction === 'E'
        && estab_datetime_is_unset($message['02_zeit'] ?? null)
        && (string) ($message['02_zeichen'] ?? '') === ''
        && estab_datetime_is_unset($message['03_datum'] ?? null)
        && (string) ($message['03_zeichen'] ?? '') === ''
        && !$hasViewerApproval
        && (string) ($message['x01_abschluss'] ?? 'f') === 'f';
    $isPendingOutgoingLead = $status === 1
        && $direction === 'A'
        && estab_datetime_is_unset($message['02_zeit'] ?? null)
        && (string) ($message['02_zeichen'] ?? '') === ''
        && estab_datetime_is_unset($message['03_datum'] ?? null)
        && (string) ($message['03_zeichen'] ?? '') === ''
        && $hasViewerApproval
        && (string) ($message['x01_abschluss'] ?? 'f') === 'f';
    $isPendingLead = $isPendingIncomingLead || $isPendingOutgoingLead;
    $isPendingOutgoing = $status === 2
        && $direction === 'A'
        && !estab_datetime_is_unset($message['02_zeit'] ?? null)
        && (string) ($message['02_zeichen'] ?? '') !== ''
        && (string) ($message['06_befwegausw'] ?? '') !== ''
        && estab_datetime_is_unset($message['03_datum'] ?? null)
        && (string) ($message['03_zeichen'] ?? '') === ''
        && $hasViewerApproval
        && (string) ($message['x01_abschluss'] ?? 'f') === 'f';
    $isPendingReview = $status === 4
        && estab_datetime_is_unset($message['15_quitdatum'] ?? null)
        && (string) ($message['15_quitzeichen'] ?? '') === ''
        && (
            (
                $direction === 'E'
                && !estab_datetime_is_unset($message['02_zeit'] ?? null)
                && (string) ($message['02_zeichen'] ?? '') !== ''
            )
            || (
                $direction === 'A'
                && estab_datetime_is_unset($message['02_zeit'] ?? null)
                && (string) ($message['02_zeichen'] ?? '') === ''
                && estab_datetime_is_unset($message['03_datum'] ?? null)
                && (string) ($message['03_zeichen'] ?? '') === ''
            )
        );
    $isReturnedToAuthor = $status === 10
        && $direction === 'A'
        && (string) ($message['14_zeichen'] ?? '') !== ''
        && (string) ($message['14_funktion'] ?? '') !== ''
        && (
            $rolesRelaxed
            || hash_equals(
                (string) ($message['14_funktion'] ?? ''),
                (string) ($identity['funktion'] ?? '')
            )
        )
        && estab_datetime_is_unset($message['02_zeit'] ?? null)
        && (string) ($message['02_zeichen'] ?? '') === ''
        && estab_datetime_is_unset($message['03_datum'] ?? null)
        && (string) ($message['03_zeichen'] ?? '') === ''
        && $hasViewerApproval
        && (string) ($message['x01_abschluss'] ?? 'f') === 'f';
    $isTerminalStaffRecipient = $status === 8
        && estab_message_is_recipient(
            $message,
            (string) ($identity['funktion'] ?? '')
        );
    $isOutgoingAuthor = $direction === 'A'
        && (string) ($message['14_funktion'] ?? '') !== ''
        && hash_equals(
            (string) ($message['14_funktion'] ?? ''),
            (string) ($identity['funktion'] ?? '')
        );

    return match ($operation) {
        'staff-read', 'staff-state' =>
            $isStaff
            // Recipient copies become operative only at terminal status 8.
            // The function author alone retains access to their own outgoing
            // object while it moves through the mandatory workflow.
            && ($isTerminalStaffRecipient || $isOutgoingAuthor),
        'staff-correction' => $isStaff && $isReturnedToAuthor,
        'telecommunications-lead-edit' =>
            $isTelecommunicationsLead && $isPendingLead,
        'telecommunications-lead-incoming-save' =>
            $isTelecommunicationsLead
            && $isPendingIncomingLead
            && ($message['x02_sperre'] ?? '') === 't'
            && hash_equals(
                (string) ($message['x03_sperruser'] ?? ''),
                (string) ($identity['kuerzel'] ?? '')
            ),
        'telecommunications-lead-outgoing-save' =>
            $isTelecommunicationsLead
            && $isPendingOutgoingLead
            && ($message['x02_sperre'] ?? '') === 't'
            && hash_equals(
                (string) ($message['x03_sperruser'] ?? ''),
                (string) ($identity['kuerzel'] ?? '')
            ),
        'telecommunications-edit' =>
            $isTelecommunications && $isPendingOutgoing,
        'telecommunications-save' =>
            $isTelecommunications
            && $isPendingOutgoing
            && ($message['x02_sperre'] ?? '') === 't'
            && hash_equals(
                (string) ($message['x03_sperruser'] ?? ''),
                (string) ($identity['kuerzel'] ?? '')
            ),
        'viewer-review' => $isViewer && $isPendingReview,
        'telecommunications-admin' => $isTelecommunications,
        'viewer-admin' => $isViewer,
        'telecommunications-reset', 'message-operator-reset' =>
            (
                ($isTelecommunications && $isPendingOutgoing)
                || ($isTelecommunicationsLead && $isPendingLead)
            )
            && ($message['x02_sperre'] ?? '') === 't',
        default => false,
    };
}
