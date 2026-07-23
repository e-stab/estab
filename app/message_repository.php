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
            '07_durchspruch', '08_befhinweis', '08_befhinwausw',
            '09_vorrangstufe', '10_anschrift', '11_gesprnotiz',
            '12_anhang', '12_inhalt', '12_abfzeit',
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
        $validated[$column] = $value;
    }
    return $validated;
}

/** Insert fields and return the positive auto-increment identifier. */
function estab_message_insert(
    mysqli $connection,
    string $table,
    array $fields
): int {
    $fields = estab_message_fields($fields);
    $columns = array_keys($fields);
    $sql = 'INSERT INTO ' . estab_message_table($table)
        . ' (`' . implode('`, `', $columns) . '`) VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $statement = estab_message_execute($connection, $sql, array_values($fields));
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
    array $fields
): array {
    if (!in_array($direction, ['E', 'A'], true)) {
        throw new InvalidArgumentException('Invalid message direction');
    }
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

    $transactionOpen = false;
    try {
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start message transaction');
        }
        $transactionOpen = true;

        if ($separateNumbering) {
            $numberStatement = estab_message_execute(
                $connection,
                'SELECT COALESCE(MAX(`04_nummer`), 0) FROM '
                    . estab_message_table($table)
                    . ' WHERE `04_richtung` = ? FOR UPDATE',
                [$direction]
            );
        } else {
            $numberStatement = estab_message_execute(
                $connection,
                'SELECT COALESCE(MAX(`04_nummer`), 0) FROM '
                    . estab_message_table($table)
                    . ' FOR UPDATE'
            );
        }
        try {
            $numberRow = $numberStatement->get_result()->fetch_row();
        } finally {
            $numberStatement->close();
        }
        $number = ((int) ($numberRow[0] ?? 0)) + 1;
        if ($number < 1) {
            throw new RuntimeException('Message number allocation overflowed');
        }

        $fields['04_richtung'] = $direction;
        $fields['04_nummer'] = $number;
        $recordId = estab_message_insert($connection, $table, $fields);
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit message transaction');
        }
        $transactionOpen = false;
        return ['id' => $recordId, 'number' => $number];
    } catch (Throwable $exception) {
        if ($transactionOpen) {
            $connection->rollback();
        }
        throw $exception;
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
    array $fields
): bool {
    $recordId = estab_message_positive_id($recordId);
    $fields = estab_message_fields($fields);
    $assignments = [];
    foreach (array_keys($fields) as $column) {
        $assignments[] = '`' . $column . '` = ?';
    }
    $parameters = array_values($fields);
    $parameters[] = $recordId;
    $statement = estab_message_execute(
        $connection,
        'UPDATE ' . estab_message_table($table)
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE `00_lfd` = ?',
        $parameters
    );
    try {
        if ($statement->affected_rows === 1) {
            return true;
        }
    } finally {
        $statement->close();
    }

    // An UPDATE that writes the values already stored reports zero affected
    // rows. Verify every requested field explicitly before treating that as
    // idempotent success; a concurrently deleted record therefore fails.
    $verificationParameters = [$recordId];
    $verificationConditions = ['`00_lfd` = ?'];
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
    $assignments = [];
    foreach (array_keys($fields) as $column) {
        $assignments[] = '`' . $column . '` = ?';
    }
    $parameters = array_values($fields);
    $parameters[] = $recordId;
    $parameters[] = '';
    $parameters[] = $operatorCode;
    $statement = estab_message_execute(
        $connection,
        'UPDATE ' . estab_message_table($table)
            . ' SET ' . implode(', ', $assignments)
            . " WHERE `00_lfd` = ? AND `04_richtung` = 'A'"
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

/** Atomically finish a message that is still in the viewer queue. */
function estab_message_update_pending_review(
    mysqli $connection,
    string $table,
    mixed $recordId,
    bool $reviewOutgoing,
    array $fields
): bool {
    $recordId = estab_message_positive_id($recordId);
    $fields = estab_message_fields($fields);
    $assignments = [];
    foreach (array_keys($fields) as $column) {
        $assignments[] = '`' . $column . '` = ?';
    }
    $parameters = array_values($fields);
    $parameters[] = $recordId;
    $parameters[] = '';
    $parameters[] = '';
    $sql = 'UPDATE ' . estab_message_table($table)
        . ' SET ' . implode(', ', $assignments)
        . ' WHERE `00_lfd` = ?'
        . ' AND `15_quitdatum` IS NULL AND `15_quitzeichen` = ?'
        . " AND (`04_richtung` = 'E'";
    if ($reviewOutgoing) {
        $sql .= " OR (`04_richtung` = 'A'"
            . ' AND `03_datum` IS NOT NULL AND `03_zeichen` <> ?)';
    } else {
        // Keep the parameter count stable while failing the outgoing branch.
        $sql .= ' OR (? <> ?)';
        $parameters[] = '';
    }
    $sql .= ')';
    $statement = estab_message_execute($connection, $sql, $parameters);
    try {
        return $statement->affected_rows === 1;
    } finally {
        $statement->close();
    }
}

/** Fetch one complete message by positive primary key. */
function estab_message_fetch_by_id(
    mysqli $connection,
    string $table,
    mixed $recordId
): ?array {
    $recordId = estab_message_positive_id($recordId);
    $statement = estab_message_execute(
        $connection,
        'SELECT * FROM ' . estab_message_table($table)
            . ' WHERE `00_lfd` = ? LIMIT 1',
        [$recordId]
    );
    try {
        $row = $statement->get_result()->fetch_assoc();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
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
    $statement = estab_message_execute(
        $connection,
        'UPDATE ' . estab_message_table($table)
            . " SET `x02_sperre` = 't', `x03_sperruser` = ?"
            . " WHERE `00_lfd` = ? AND `04_richtung` = 'A'"
            . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
            . " AND (`x02_sperre` = 'f' OR (`x02_sperre` = 't' AND `x03_sperruser` = ?))",
        [$operatorCode, $recordId, '', $operatorCode]
    );
    try {
        if ($statement->affected_rows === 1) {
            return true;
        }
    } finally {
        $statement->close();
    }

    // MariaDB reports zero affected rows when the same owner re-opens an
    // already acquired lock. Verify that idempotent success explicitly.
    $verification = estab_message_execute(
        $connection,
        'SELECT COUNT(*) FROM ' . estab_message_table($table)
            . " WHERE `00_lfd` = ? AND `04_richtung` = 'A'"
            . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
            . " AND `x02_sperre` = 't' AND `x03_sperruser` = ?",
        [$recordId, '', $operatorCode]
    );
    try {
        $row = $verification->get_result()->fetch_row();
        return ((int) ($row[0] ?? 0)) === 1;
    } finally {
        $verification->close();
    }
}

/** Release a lock owned by the operator; force is reserved for reset workflow. */
function estab_message_release_lock(
    mysqli $connection,
    string $table,
    mixed $recordId,
    ?string $operatorCode = null
): bool {
    $recordId = estab_message_positive_id($recordId);
    $sql = 'UPDATE ' . estab_message_table($table)
        . " SET `x02_sperre` = 'f', `x03_sperruser` = ''"
        . " WHERE `00_lfd` = ? AND `04_richtung` = 'A'"
        . ' AND `03_datum` IS NULL AND `03_zeichen` = ?';
    $parameters = [$recordId, ''];
    if ($operatorCode !== null) {
        if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $operatorCode) !== 1) {
            throw new InvalidArgumentException('Invalid message lock owner');
        }
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

    // Zero affected rows is successful only when the addressed pending row is
    // already in the requested unlocked state. A foreign lock, a transported
    // row or a deleted row must remain a failure.
    $verification = estab_message_execute(
        $connection,
        'SELECT COUNT(*) FROM ' . estab_message_table($table)
            . " WHERE `00_lfd` = ? AND `04_richtung` = 'A'"
            . ' AND `03_datum` IS NULL AND `03_zeichen` = ?'
            . " AND `x02_sperre` = 'f' AND `x03_sperruser` = ?",
        [$recordId, '', '']
    );
    try {
        $row = $verification->get_result()->fetch_row();
        return ((int) ($row[0] ?? 0)) === 1;
    } finally {
        $verification->close();
    }
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
    string $table,
    mixed $recordId
): bool {
    $recordId = estab_message_positive_id($recordId);
    $statement = estab_message_execute(
        $connection,
        'SELECT COUNT(*) FROM ' . estab_message_table($table)
            . ' WHERE `nachnum` = ?',
        [$recordId]
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
    string $table,
    mixed $recordId,
    string $state,
    string $timestamp
): void {
    $recordId = estab_message_positive_id($recordId);
    $dateColumn = match ($state) {
        'read' => 'gelesen',
        'done' => 'erledigt',
        default => throw new InvalidArgumentException('Invalid message state'),
    };
    $lockName = estab_message_acquire_state_lock($connection, $table, $recordId);
    try {
        $statement = estab_message_execute(
            $connection,
            'INSERT INTO ' . estab_message_table($table)
                . ' (`nachnum`, `' . $dateColumn . '`)'
                . ' SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM '
                . estab_message_table($table) . ' WHERE `nachnum` = ?)',
            [$recordId, $timestamp, $recordId]
        );
        $statement->close();
    } finally {
        estab_message_release_state_lock($connection, $lockName);
    }
}

function estab_message_state_unset(
    mysqli $connection,
    string $table,
    mixed $recordId
): void {
    $recordId = estab_message_positive_id($recordId);
    $lockName = estab_message_acquire_state_lock($connection, $table, $recordId);
    try {
        $statement = estab_message_execute(
            $connection,
            'DELETE FROM ' . estab_message_table($table)
                . ' WHERE `nachnum` = ?',
            [$recordId]
        );
        $statement->close();
    } finally {
        estab_message_release_state_lock($connection, $lockName);
    }
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
    string $function,
    string $state,
    string $timestamp
): bool {
    $recordId = estab_message_positive_id($recordId);
    $dateColumn = match ($state) {
        'read' => 'gelesen',
        'done' => 'erledigt',
        default => throw new InvalidArgumentException('Invalid message state'),
    };
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
                . ' SELECT ?, ? FROM ' . estab_message_table($messageTable) . ' AS m'
                . ' WHERE m.`00_lfd` = ? AND m.`16_empf` REGEXP ?'
                . ' AND NOT EXISTS (SELECT 1 FROM '
                . estab_message_table($stateTable) . ' WHERE `nachnum` = ?)',
            [$recordId, $timestamp, $recordId, $recipientPattern, $recordId]
        );
        try {
            if ($statement->affected_rows === 1) {
                return true;
            }
        } finally {
            $statement->close();
        }

        // Distinguish an authorised idempotent repeat from a missing or newly
        // foreign object without leaving the state lock.
        $verification = estab_message_execute(
            $connection,
            'SELECT COUNT(*) FROM ' . estab_message_table($messageTable) . ' AS m'
                . ' INNER JOIN ' . estab_message_table($stateTable) . ' AS s'
                . ' ON s.`nachnum` = m.`00_lfd`'
                . ' WHERE m.`00_lfd` = ? AND m.`16_empf` REGEXP ?',
            [$recordId, $recipientPattern]
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

/**
 * Remove state only while the message is still addressed to the staff
 * function. Concurrent set/unset requests share the same state-row lock.
 */
function estab_message_state_unset_for_recipient(
    mysqli $connection,
    string $messageTable,
    string $stateTable,
    mixed $recordId,
    string $function
): bool {
    $recordId = estab_message_positive_id($recordId);
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
                . ' INNER JOIN ' . estab_message_table($messageTable) . ' AS m'
                . ' ON m.`00_lfd` = s.`nachnum`'
                . ' WHERE s.`nachnum` = ? AND m.`16_empf` REGEXP ?',
            [$recordId, $recipientPattern]
        );
        try {
            if ($statement->affected_rows > 0) {
                return true;
            }
        } finally {
            $statement->close();
        }

        // No state row is an idempotent success only for an object that is
        // still visible to this function.
        $verification = estab_message_execute(
            $connection,
            'SELECT COUNT(*) FROM ' . estab_message_table($messageTable)
                . ' WHERE `00_lfd` = ? AND `16_empf` REGEXP ?',
            [$recordId, $recipientPattern]
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

/** @return list<int> */
function estab_message_state_ids(
    mysqli $connection,
    string $messageTable,
    string $stateTable
): array {
    $statement = estab_message_execute(
        $connection,
        'SELECT DISTINCT m.`00_lfd` FROM ' . estab_message_table($messageTable)
            . ' AS m INNER JOIN ' . estab_message_table($stateTable)
            . ' AS s ON m.`00_lfd` = s.`nachnum`'
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

/** Pure object-level route decision used after the role gate. */
function estab_message_object_allowed(
    array $identity,
    string $operation,
    array $message,
    bool $reviewOutgoing = true
): bool {
    $isTelecommunications = ($identity['funktion'] ?? '') === 'A/W'
        && ($identity['rolle'] ?? '') === 'Fernmelder';
    $isViewer = ($identity['funktion'] ?? '') === 'Si'
        && ($identity['rolle'] ?? '') === 'Stab';
    $isStaff = ($identity['funktion'] ?? '') !== 'Si'
        && in_array(($identity['rolle'] ?? ''), ['Stab', 'FB'], true);
    $isPendingOutgoing = ($message['04_richtung'] ?? '') === 'A'
        && estab_datetime_is_unset($message['03_datum'] ?? null)
        && (string) ($message['03_zeichen'] ?? '') === '';
    $isPendingReview = estab_datetime_is_unset($message['15_quitdatum'] ?? null)
        && (string) ($message['15_quitzeichen'] ?? '') === ''
        && (
            ($message['04_richtung'] ?? '') === 'E'
            || (
                $reviewOutgoing
                && ($message['04_richtung'] ?? '') === 'A'
                && !estab_datetime_is_unset($message['03_datum'] ?? null)
                && (string) ($message['03_zeichen'] ?? '') !== ''
            )
        );

    return match ($operation) {
        'staff-read', 'staff-state' =>
            $isStaff && estab_message_is_recipient($message, (string) $identity['funktion']),
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
        'telecommunications-reset' =>
            $isTelecommunications
            && $isPendingOutgoing
            && ($message['x02_sperre'] ?? '') === 't',
        default => false,
    };
}
