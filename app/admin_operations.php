<?php

declare(strict_types=1);

/**
 * Transactional persistence boundary for the active administrative workflows.
 *
 * The legacy pages used GET writes, raw SQL and, for the recipient matrix, a
 * generated PHP file. The runtime reads the matrix from MariaDB, so this
 * boundary deliberately persists database state only.
 */

require_once __DIR__ . '/message_repository.php';
require_once __DIR__ . '/assignment.php';

const ESTAB_ADMIN_MATRIX_ROWS = 5;
const ESTAB_ADMIN_MATRIX_COLUMNS = 4;
const ESTAB_ADMIN_COUNTER_MAX = 999999999;

final class EstabAdminConflictException extends RuntimeException
{
}

/** Require the independent Apache Basic-Auth identity at the PHP boundary. */
function estab_admin_require_http_auth(array $server): void
{
    if (PHP_SAPI === 'cli' || !empty($server['REMOTE_USER'])) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Administrative authentication required.';
    exit;
}

function estab_admin_html(mixed $value): string
{
    return estab_auth_html($value);
}

/**
 * Validate all 20 recipient cells and return a canonical matrix.
 *
 * Empty cells cannot carry a role. S2 is the mandatory Lage/Dokumentation
 * capability and sole red-copy target. Automatic sighting is rejected because
 * it cannot replace the qualified Sichter required by DV 1-101. Function names
 * are unique case-insensitively because they become login/recipient
 * identifiers throughout the legacy runtime.
 */
function estab_admin_validate_matrix(array $input): array
{
    $cells = [];
    $errors = [];
    $functions = [];

    for ($row = 1; $row <= ESTAB_ADMIN_MATRIX_ROWS; $row++) {
        for ($column = 1; $column <= ESTAB_ADMIN_MATRIX_COLUMNS; $column++) {
            $position = (string) $row . (string) $column;
            $functionValue = $input['pos_' . $position] ?? '';
            $roleValue = $input['rolle_' . $position] ?? '';
            $autoValue = $input['stasi_' . $position] ?? null;

            $function = is_string($functionValue) ? trim($functionValue) : '';
            $role = is_string($roleValue) ? trim($roleValue) : '';
            $auto = false;

            if (
                !is_string($functionValue)
                || (
                    $function !== ''
                    && preg_match('/\A[A-Za-z0-9_]{1,6}\z/D', $function) !== 1
                )
                || in_array(strtoupper($function), ['SI', 'A/W', 'LDF'], true)
            ) {
                $errors[] = 'pos_' . $position;
            }

            if (!is_string($roleValue) || !in_array($role, ['', 'Stab', 'FB'], true)) {
                $errors[] = 'rolle_' . $position;
            }

            if ($autoValue !== null) {
                $errors[] = 'stasi_' . $position;
            }

            if ($function === '' && $role !== '') {
                $errors[] = 'cell_' . $position;
            }

            if ($function !== '') {
                $functionKey = strtolower($function);
                if (isset($functions[$functionKey])) {
                    $errors[] = 'pos_' . $position;
                }
                $functions[$functionKey] = $position;
            }

            $cells[$position] = [
                'row' => $row,
                'column' => $column,
                'function' => $function,
                'role' => $role,
                'auto' => $auto,
                'redcopy' => false,
            ];
        }
    }

    $redcopyValue = $input['lagerot'] ?? null;
    $redcopy = is_string($redcopyValue) ? $redcopyValue : '';
    if (
        !array_key_exists($redcopy, $cells)
        || $cells[$redcopy]['function'] !== 'S2'
        || $cells[$redcopy]['role'] !== 'Stab'
    ) {
        $errors[] = 'lagerot';
    } else {
        $cells[$redcopy]['redcopy'] = true;
    }

    return [
        'valid' => $errors === [],
        'errors' => array_values(array_unique($errors)),
        'data' => [
            'cells' => $cells,
            'redcopy' => $redcopy,
        ],
    ];
}

/** Read the current five-by-four matrix without relying on generated PHP. */
function estab_admin_fetch_matrix(mysqli $connection, string $table): array
{
    $statement = $connection->prepare(
        'SELECT `mtx_x`, `mtx_y`, `mtx_fkt`, `mtx_rolle`, `mtx_rc2`, `mtx_auto`'
        . ' FROM ' . estab_auth_table($table)
        . ' ORDER BY `mtx_x`, `mtx_y`'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare recipient matrix lookup');
    }

    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Could not read recipient matrix');
        }
        $result = $statement->get_result();
        $cells = [];
        $redcopy = '';
        while (($row = $result->fetch_assoc()) !== null) {
            $matrixRow = (int) ($row['mtx_x'] ?? 0);
            $matrixColumn = (int) ($row['mtx_y'] ?? 0);
            if (
                $matrixRow < 1
                || $matrixRow > ESTAB_ADMIN_MATRIX_ROWS
                || $matrixColumn < 1
                || $matrixColumn > ESTAB_ADMIN_MATRIX_COLUMNS
            ) {
                throw new RuntimeException('Recipient matrix has an invalid position');
            }
            $position = (string) $matrixRow . (string) $matrixColumn;
            if (isset($cells[$position])) {
                throw new RuntimeException('Recipient matrix contains a duplicate position');
            }
            $isRedcopy = in_array((string) ($row['mtx_rc2'] ?? ''), ['1', 't'], true);
            if (in_array((string) ($row['mtx_auto'] ?? ''), ['1', 't'], true)) {
                throw new RuntimeException(
                    'Die Empfängermatrix enthält eine unzulässige Autosichtung.'
                );
            }
            $cells[$position] = [
                'row' => $matrixRow,
                'column' => $matrixColumn,
                'function' => (string) ($row['mtx_fkt'] ?? ''),
                'role' => (string) ($row['mtx_rolle'] ?? ''),
                'auto' => false,
                'redcopy' => $isRedcopy,
            ];
            if ($isRedcopy) {
                if ($redcopy !== '') {
                    throw new RuntimeException('Recipient matrix contains multiple red-copy targets');
                }
                $redcopy = $position;
            }
        }
        $result->free();
    } finally {
        $statement->close();
    }

    if (count($cells) !== ESTAB_ADMIN_MATRIX_ROWS * ESTAB_ADMIN_MATRIX_COLUMNS) {
        throw new RuntimeException('Recipient matrix must contain exactly 20 positions');
    }
    if (
        $redcopy === ''
        || ($cells[$redcopy]['function'] ?? null) !== 'S2'
        || ($cells[$redcopy]['role'] ?? null) !== 'Stab'
    ) {
        throw new RuntimeException(
            'S2 muss das Lage-/Dokumentationsziel der Rotkopie sein.'
        );
    }

    return ['cells' => $cells, 'redcopy' => $redcopy];
}

/** Add a prepared audit record on an existing connection/transaction. */
function estab_admin_insert_audit(
    mysqli $connection,
    string $protocolTable,
    string $event,
    string $details,
    ?int $incidentId = null
): void {
    if ($incidentId === null) {
        $sql = 'INSERT INTO ' . estab_auth_table($protocolTable)
            . ' (`p_was`, `p_ereignis`) VALUES (?, ?)';
    } else {
        $incidentId = estab_incident_positive_id($incidentId);
        $sql = 'INSERT INTO ' . estab_auth_table($protocolTable)
            . ' (`einsatz_id`, `p_was`, `p_ereignis`) VALUES (?, ?, ?)';
    }
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare administrative audit');
    }
    try {
        if ($incidentId === null) {
            $statement->bind_param('ss', $event, $details);
        } else {
            $statement->bind_param('iss', $incidentId, $event, $details);
        }
        if (!$statement->execute()) {
            throw new RuntimeException('Could not write administrative audit');
        }
    } finally {
        $statement->close();
    }
}

/**
 * Replace one matrix inside the caller's transaction with prepared writes.
 *
 * DELETE is transactional for the InnoDB tables. Account-policy reconciliation
 * is deliberately performed by the outer active-matrix transaction.
 */
function estab_admin_write_matrix(
    mysqli $connection,
    string $matrixTable,
    array $matrix
): void {
    $cells = $matrix['cells'] ?? null;
    if (!is_array($cells) || count($cells) !== ESTAB_ADMIN_MATRIX_ROWS * ESTAB_ADMIN_MATRIX_COLUMNS) {
        throw new InvalidArgumentException('A complete validated recipient matrix is required');
    }

    $delete = $connection->prepare('DELETE FROM ' . estab_auth_table($matrixTable));
    if (!$delete) {
        throw new RuntimeException('Could not prepare recipient matrix replacement');
    }
    try {
        if (!$delete->execute()) {
            throw new RuntimeException('Could not clear recipient matrix');
        }
    } finally {
        $delete->close();
    }

    $insert = $connection->prepare(
        'INSERT INTO ' . estab_auth_table($matrixTable)
        . ' (`mtx_x`, `mtx_y`, `mtx_typ`, `mtx_fkt`, `mtx_rolle`,'
        . ' `mtx_mode`, `mtx_rc2`, `mtx_auto`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$insert) {
        throw new RuntimeException('Could not prepare recipient matrix insert');
    }
    try {
        foreach ($cells as $cell) {
            if (!is_array($cell)) {
                throw new InvalidArgumentException('Invalid recipient matrix cell');
            }
            $row = (int) ($cell['row'] ?? 0);
            $column = (int) ($cell['column'] ?? 0);
            $function = (string) ($cell['function'] ?? '');
            $role = (string) ($cell['role'] ?? '');
            $type = $function !== '' && $role !== '' ? 'cb' : 't';
            $mode = 'ro';
            $redcopy = !empty($cell['redcopy']) ? 't' : 'f';
            // Legacy-schema compatibility only. Autosichtung is forbidden.
            $auto = '0';
            $insert->bind_param(
                'iissssss',
                $row,
                $column,
                $type,
                $function,
                $role,
                $mode,
                $redcopy,
                $auto
            );
            if (!$insert->execute()) {
                throw new RuntimeException('Could not insert recipient matrix cell');
            }
        }
    } finally {
        $insert->close();
    }
}

/** Atomically replace only the matrix currently used by the runtime. */
function estab_admin_replace_matrix(
    mysqli $connection,
    string $database,
    string $matrixTable,
    string $userTable,
    string $protocolTable,
    array $matrix,
    string $actor = 'unknown',
    string $remoteAddress = ''
): void {
    $policyLockName = estab_assignment_acquire_policy_lock(
        $connection,
        $database,
        $matrixTable
    );
    $transactionActive = false;
    try {
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start recipient matrix transaction');
        }
        $transactionActive = true;
        $oldRoles = estab_assignment_function_roles($connection, $matrixTable);
        $newRoles = estab_assignment_roles_from_matrix($matrix);
        estab_auth_merge_function_role_catalog($connection, $newRoles, true);
        estab_admin_write_matrix($connection, $matrixTable, $matrix);
        $summary = estab_assignment_reconcile_accounts(
            $connection,
            $userTable,
            $protocolTable,
            $oldRoles,
            $newRoles,
            $actor,
            $remoteAddress
        );
        estab_admin_insert_audit(
            $connection,
            $protocolTable,
            'Empfängermatrix',
            estab_assignment_matrix_audit('replace_active', $summary)
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit recipient matrix replacement');
        }
        $transactionActive = false;
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        if ($transactionActive) {
            $connection->rollback();
        }
        try {
            estab_assignment_release_policy_lock($connection, $policyLockName);
        } catch (Throwable $exception) {
            error_log(
                'eStab matrix policy-lock cleanup failed: '
                . $exception->getMessage()
            );
        }
    }
}

/** Atomically make the submitted matrix both active and the single preset. */
function estab_admin_replace_matrix_and_standard(
    mysqli $connection,
    string $database,
    string $matrixTable,
    string $standardMatrixTable,
    string $userTable,
    string $protocolTable,
    array $matrix,
    string $actor = 'unknown',
    string $remoteAddress = ''
): void {
    $policyLockName = estab_assignment_acquire_policy_lock(
        $connection,
        $database,
        $matrixTable
    );
    $transactionActive = false;
    try {
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start recipient matrix transaction');
        }
        $transactionActive = true;
        $oldRoles = estab_assignment_function_roles($connection, $matrixTable);
        $newRoles = estab_assignment_roles_from_matrix($matrix);
        estab_auth_merge_function_role_catalog($connection, $newRoles, true);
        // Keep a stable lock order across both administrative save actions.
        estab_admin_write_matrix($connection, $matrixTable, $matrix);
        estab_admin_write_matrix($connection, $standardMatrixTable, $matrix);
        $summary = estab_assignment_reconcile_accounts(
            $connection,
            $userTable,
            $protocolTable,
            $oldRoles,
            $newRoles,
            $actor,
            $remoteAddress
        );
        estab_admin_insert_audit(
            $connection,
            $protocolTable,
            'Empfängermatrix',
            estab_assignment_matrix_audit(
                'replace_active_and_standard',
                $summary
            )
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit recipient matrix replacement');
        }
        $transactionActive = false;
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        if ($transactionActive) {
            $connection->rollback();
        }
        try {
            estab_assignment_release_policy_lock($connection, $policyLockName);
        } catch (Throwable $exception) {
            error_log(
                'eStab matrix policy-lock cleanup failed: '
                . $exception->getMessage()
            );
        }
    }
}

function estab_admin_validate_counter_mode(string $mode): string
{
    if (!in_array($mode, ['gemeinsam', 'getrennt'], true)) {
        throw new InvalidArgumentException('Invalid message-counter mode');
    }
    return $mode;
}

/** Strictly accept positive decimal counter values within the UI bound. */
function estab_admin_parse_counter_value(mixed $value): ?int
{
    if (
        !is_string($value)
        || preg_match('/\A[1-9][0-9]{0,8}\z/D', $value) !== 1
    ) {
        return null;
    }
    $number = (int) $value;
    return $number <= ESTAB_ADMIN_COUNTER_MAX ? $number : null;
}

function estab_admin_validate_counter_input(array $input, string $mode): array
{
    $mode = estab_admin_validate_counter_mode($mode);
    $fields = $mode === 'gemeinsam'
        ? ['ea_nummer']
        : ['e_nummer', 'a_nummer'];
    $data = [];
    $errors = [];
    foreach ($fields as $field) {
        $number = estab_admin_parse_counter_value($input[$field] ?? null);
        if ($number === null) {
            $errors[] = $field;
            continue;
        }
        $data[$field] = $number;
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'data' => $data,
    ];
}

/** Return the current common or direction-specific counter maxima. */
function estab_admin_fetch_counter_maxima(
    mysqli $connection,
    string $messageTable,
    string $mode,
    bool $forUpdate = false,
    ?int $incidentId = null
): array {
    $mode = estab_admin_validate_counter_mode($mode);
    if ($incidentId === null) {
        $incident = estab_incident_require_active($connection, $forUpdate);
        $incidentId = (int) $incident['active_einsatz_id'];
    } else {
        $incidentId = estab_incident_positive_id($incidentId);
    }
    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $quotedTable = estab_auth_table($messageTable);

    if ($mode === 'gemeinsam') {
        $statement = $connection->prepare(
            'SELECT `04_nummer` FROM ' . $quotedTable
            . ' WHERE `einsatz_id` = ?'
            . ' ORDER BY `04_nummer` DESC, `00_lfd` DESC LIMIT 1' . $suffix
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare common counter lookup');
        }
        try {
            $statement->bind_param('i', $incidentId);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not read common counter');
            }
            $result = $statement->get_result();
            $row = $result->fetch_row();
            $result->free();
            return [
                'ea_nummer' => max(
                    (int) ($row[0] ?? 0),
                    estab_message_counter_repair_max(
                        $connection,
                        $incidentId,
                        false,
                        'E'
                    )
                ),
            ];
        } finally {
            $statement->close();
        }
    }

    $maxima = [];
    $statement = $connection->prepare(
        'SELECT `04_nummer` FROM ' . $quotedTable
        . ' WHERE `einsatz_id` = ? AND `04_richtung` = ?'
        . ' ORDER BY `04_nummer` DESC, `00_lfd` DESC LIMIT 1' . $suffix
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare split counter lookup');
    }
    try {
        foreach (['E' => 'e_nummer', 'A' => 'a_nummer'] as $direction => $field) {
            $statement->bind_param('is', $incidentId, $direction);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not read split counter');
            }
            $result = $statement->get_result();
            $row = $result->fetch_row();
            $result->free();
            $maxima[$field] = max(
                (int) ($row[0] ?? 0),
                estab_message_counter_repair_max(
                    $connection,
                    $incidentId,
                    true,
                    $direction
                )
            );
        }
    } finally {
        $statement->close();
    }
    return $maxima;
}

/** Acquire a connection-scoped advisory lock for counter repair operations. */
function estab_admin_acquire_counter_lock(mysqli $connection, string $messageTable): string
{
    $databaseResult = $connection->query('SELECT DATABASE()');
    if (!$databaseResult instanceof mysqli_result) {
        throw new RuntimeException('Could not identify message-counter database');
    }
    try {
        $databaseRow = $databaseResult->fetch_row();
    } finally {
        $databaseResult->free();
    }
    $databaseName = (string) ($databaseRow[0] ?? '');
    $lockName = estab_message_counter_lock_name($databaseName, $messageTable);
    $statement = $connection->prepare('SELECT GET_LOCK(?, 10)');
    if (!$statement) {
        throw new RuntimeException('Could not prepare message-counter lock');
    }
    try {
        $statement->bind_param('s', $lockName);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not acquire message-counter lock');
        }
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $result->free();
        if ((int) ($row[0] ?? 0) !== 1) {
            throw new RuntimeException('Message-counter lock timed out');
        }
    } finally {
        $statement->close();
    }
    return $lockName;
}

function estab_admin_release_counter_lock(mysqli $connection, string $lockName): void
{
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        return;
    }
    try {
        $statement->bind_param('s', $lockName);
        $statement->execute();
        $result = $statement->get_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

/**
 * Increase the message counter and store evidence/audit records atomically.
 *
 * An advisory lock serializes concurrent administrative repairs; FOR UPDATE
 * also locks the selected sequence edge while the transaction is open.
 */
function estab_admin_raise_message_counter(
    mysqli $connection,
    string $messageTable,
    string $protocolTable,
    string $mode,
    array $values,
    string $actor = 'administration'
): array {
    $mode = estab_admin_validate_counter_mode($mode);
    $actor = estab_dv_actor($actor);
    $lockName = estab_admin_acquire_counter_lock($connection, $messageTable);
    try {
        if (!$connection->begin_transaction()) {
            throw new RuntimeException('Could not start message-counter transaction');
        }
        try {
            $incident = estab_incident_require_active($connection, true);
            estab_incident_lock_command_post_for_write($connection, $incident);
            $incidentId = (int) $incident['active_einsatz_id'];
            $strictMode = estab_incident_duty_shift_required($incident);
            $current = estab_admin_fetch_counter_maxima(
                $connection,
                $messageTable,
                $mode,
                true,
                $incidentId
            );
            foreach ($current as $field => $maximum) {
                $target = $values[$field] ?? null;
                if (!is_int($target) || $target <= $maximum || $target > ESTAB_ADMIN_COUNTER_MAX) {
                    throw new EstabAdminConflictException(
                        'Der Nachrichtenzähler darf ausschließlich erhöht werden.'
                    );
                }
            }

            $evidenceObjectType = 'EINSATZ';
            $evidenceObjectId = $incidentId;
            $shiftId = null;
            if ($strictMode) {
                $shiftStatement = $connection->prepare(
                    'SELECT `dienstschicht_id` FROM `nv_dienstschichten`'
                    . " WHERE `einsatz_id` = ? AND `status` = 'AKTIV'"
                    . ' ORDER BY `dienstschicht_id` DESC LIMIT 1 FOR UPDATE'
                );
                if (!$shiftStatement) {
                    throw new RuntimeException(
                        'Aktive Dienstschicht für die Zählerkorrektur konnte '
                        . 'nicht vorbereitet werden.'
                    );
                }
                try {
                    $shiftStatement->bind_param('i', $incidentId);
                    $shiftStatement->execute();
                    $shiftRow = $shiftStatement->get_result()->fetch_assoc();
                } finally {
                    $shiftStatement->close();
                }
                if (!is_array($shiftRow)) {
                    throw new EstabAdminConflictException(
                        'Im strengen Berechtigungsmodus kann der '
                        . 'Nachrichtenzähler erst mit einer aktiven '
                        . 'Dienstschicht erhöht werden.'
                    );
                }
                $shiftId = (int) ($shiftRow['dienstschicht_id'] ?? 0);
                if ($shiftId < 1) {
                    throw new RuntimeException(
                        'Die aktive Dienstschicht hat keine gültige Kennung.'
                    );
                }
                $evidenceObjectType = 'DIENSTSCHICHT';
                $evidenceObjectId = $shiftId;
            }

            // This is a recovery watermark in the immutable operational
            // chain. It must never masquerade as a received/sent message with
            // invented Fernmelder, LdF or Si marks. STRICT restores the formal
            // duty-shift evidence from before shifts became optional. LOOSE
            // binds the same evidence directly to the incident and never
            // treats an optional access shift as a source of authority.
            estab_dv_event_append(
                $connection,
                $incidentId,
                $evidenceObjectType,
                $evidenceObjectId,
                'message_counter_repaired',
                $actor,
                null,
                [
                    'version' => 1,
                    'action' => 'message_counter_repaired',
                    'reason' => 'paper_numbers_after_system_failure',
                    'numbering_mode' => $mode,
                    'before' => $current,
                    'after' => $values,
                    'actor' => $actor,
                    'permission_mode' => $strictMode
                        ? ESTAB_PERMISSION_MODE_STRICT
                        : ESTAB_PERMISSION_MODE_LOOSE,
                    'dienstschicht_id' => $shiftId,
                ]
            );
            $auditValue = $mode === 'gemeinsam'
                ? 'E/A' . $values['ea_nummer']
                : 'E' . $values['e_nummer'] . ' / A' . $values['a_nummer'];
            estab_admin_insert_audit(
                $connection,
                $protocolTable,
                'Nachrichtennummer Sync',
                'Nachrichtenzähler nach Systemausfall auf ' . $auditValue . ' gesetzt.',
                $incidentId
            );
            if (!$connection->commit()) {
                throw new RuntimeException('Could not commit message-counter update');
            }
            return ['before' => $current, 'after' => $values];
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    } finally {
        estab_admin_release_counter_lock($connection, $lockName);
    }
}

/** Reset generated-print flags only for the locked active incident. */
function estab_admin_reset_print_flags(
    mysqli $connection,
    string $messageTable,
    string $protocolTable
): int {
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Could not start print-flag transaction');
    }
    try {
        $incident = estab_incident_require_active($connection, true);
        estab_incident_lock_command_post_for_write($connection, $incident);
        $incidentId = (int) $incident['active_einsatz_id'];
        $statement = $connection->prepare(
            'UPDATE ' . estab_auth_table($messageTable)
            . ' SET `x04_druck` = ?, `x05_druck_d` = NULL'
            . ' WHERE `einsatz_id` = ? AND `x04_druck` <> ?'
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare print-flag reset');
        }
        try {
            $reset = 'f';
            $statement->bind_param('sis', $reset, $incidentId, $reset);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not reset print flags');
            }
            $affected = $statement->affected_rows;
        } finally {
            $statement->close();
        }

        estab_admin_insert_audit(
            $connection,
            $protocolTable,
            'Grafikstatus Reset',
            $affected . ' Grafikmarkierung(en) für Einsatz '
                . $incidentId . ' zurückgesetzt.',
            $incidentId
        );
        if (!$connection->commit()) {
            throw new RuntimeException('Could not commit print-flag reset');
        }
        return $affected;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}
