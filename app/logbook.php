<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/incident.php';

const ESTAB_LOGBOOK_TITLE_MAX_LENGTH = 255;
const ESTAB_LOGBOOK_TEXT_MAX_LENGTH = 10000;

/** Return the global active incident for ETB/TBB display, or null. */
function estab_logbook_active_incident(array $databaseConfig): ?array
{
    $connection = estab_auth_connect($databaseConfig);
    try {
        return estab_incident_active($connection);
    } finally {
        estab_auth_close($connection);
    }
}

/**
 * Read only logbook rows belonging to the global active incident.
 *
 * @return list<array<string, mixed>>
 */
function estab_logbook_entries(
    array $databaseConfig,
    string $table,
    string $kind
): array {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }
    $connection = estab_auth_connect($databaseConfig);
    try {
        $incident = estab_incident_active($connection);
        if ($incident === null) {
            return [];
        }
        $orderColumn = $kind . '_lfd-nr';
        $statement = $connection->prepare(
            'SELECT * FROM ' . estab_auth_table($table)
            . ' WHERE `einsatz_id` = ?'
            . ' ORDER BY `' . $orderColumn . '` DESC'
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare logbook listing');
        }
        try {
            $incidentId = (int) $incident['active_einsatz_id'];
            $statement->bind_param('i', $incidentId);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not execute logbook listing');
            }
            $result = $statement->get_result();
            try {
                return $result->fetch_all(MYSQLI_ASSOC);
            } finally {
                $result->free();
            }
        } finally {
            $statement->close();
        }
    } finally {
        estab_auth_close($connection);
    }
}

/**
 * Validate and normalize the initial operation title.
 *
 * VARCHAR limits are measured as Unicode characters. Control characters are
 * rejected because both values are rendered as single-line labels.
 */
function estab_logbook_validate_title(array $input): array
{
    $values = [];
    $errors = [];
    foreach (['einsatz', 'ort'] as $field) {
        $value = isset($input[$field]) && is_string($input[$field])
            ? trim($input[$field])
            : '';
        $length = estab_auth_text_length($value);
        if (
            $length < 1
            || $length > ESTAB_LOGBOOK_TITLE_MAX_LENGTH
            || preg_match('/\p{C}/u', $value) === 1
        ) {
            $errors[] = $field;
        }
        $values[$field] = $value;
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'data' => $values,
    ];
}

/**
 * Validate a logbook event and optional comment before writing a TEXT column.
 */
function estab_logbook_validate_entry(array $input): array
{
    $event = isset($input['event']) && is_string($input['event'])
        ? trim($input['event'])
        : '';
    $comment = isset($input['comment']) && is_string($input['comment'])
        ? trim($input['comment'])
        : '';
    $errors = [];

    $eventLength = estab_auth_text_length($event);
    if (
        $eventLength < 1
        || $eventLength > ESTAB_LOGBOOK_TEXT_MAX_LENGTH
        || strlen($event) > 65535
        || str_contains($event, "\0")
    ) {
        $errors[] = 'event';
    }

    $commentLength = estab_auth_text_length($comment);
    if (
        $commentLength < 0
        || $commentLength > ESTAB_LOGBOOK_TEXT_MAX_LENGTH
        || strlen($comment) > 65535
        || str_contains($comment, "\0")
    ) {
        $errors[] = 'comment';
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'data' => [
            'event' => $event,
            'comment' => $comment,
        ],
    ];
}

/** Test whether a configured table exists without interpolating its name. */
function estab_logbook_table_exists(array $databaseConfig, string $table): bool
{
    // Apply the same identifier policy as every query, even though this
    // lookup binds the name as data.
    estab_auth_table($table);
    $connection = estab_auth_connect($databaseConfig);
    try {
        $statement = $connection->prepare(
            'SELECT 1 FROM information_schema.tables'
            . ' WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        if (!$statement) {
            throw new RuntimeException('Could not prepare logbook table lookup');
        }
        try {
            $statement->bind_param('s', $table);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not execute logbook table lookup');
            }
            $result = $statement->get_result();
            $exists = $result->fetch_row() !== null;
            $result->free();
            return $exists;
        } finally {
            $statement->close();
        }
    } finally {
        estab_auth_close($connection);
    }
}

/** Create a missing legacy title table using the modern storage defaults. */
function estab_logbook_create_title_table(array $databaseConfig, string $table): void
{
    $connection = estab_auth_connect($databaseConfig);
    try {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . estab_auth_table($table) . ' ('
            . ' `lfd-nr` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `einsatz` VARCHAR(255) NOT NULL,'
            . ' `ort` VARCHAR(255) NOT NULL,'
            . ' PRIMARY KEY (`lfd-nr`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        if (!$connection->query($sql)) {
            throw new RuntimeException('Could not create logbook title table');
        }
    } finally {
        estab_auth_close($connection);
    }
}

/**
 * Store the title only when the table is still empty.
 *
 * This makes repeated submissions and integration-test reruns harmless.
 */
function estab_logbook_insert_title(
    array $databaseConfig,
    string $table,
    array $title
): void {
    $connection = estab_auth_connect($databaseConfig);
    try {
        $quotedTable = estab_auth_table($table);
        $sql = 'INSERT INTO ' . $quotedTable . ' (`einsatz`, `ort`)'
            . ' SELECT ?, ? FROM DUAL'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $quotedTable . ' LIMIT 1)';
        $statement = $connection->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Could not prepare logbook title insert');
        }
        try {
            $operation = (string) ($title['einsatz'] ?? '');
            $location = (string) ($title['ort'] ?? '');
            $statement->bind_param('ss', $operation, $location);
            if (!$statement->execute()) {
                throw new RuntimeException('Could not execute logbook title insert');
            }
        } finally {
            $statement->close();
        }
    } finally {
        estab_auth_close($connection);
    }
}

/** Insert one ETB or TBB record through a prepared mysqli statement. */
function estab_logbook_insert_entry(
    array $databaseConfig,
    string $table,
    string $kind,
    array $entry,
    array $identity
): void {
    if (!in_array($kind, ['etb', 'tbb'], true)) {
        throw new InvalidArgumentException('Invalid logbook kind');
    }

    $connection = estab_auth_connect($databaseConfig);
    try {
        estab_incident_with_active_write(
            $connection,
            static function (array $incident) use (
                $connection,
                $table,
                $kind,
                $entry,
                $identity
            ): void {
                $prefix = $kind . '_';
                $sql = 'INSERT INTO ' . estab_auth_table($table)
                    . ' (`einsatz_id`, `' . $prefix . 'time`,'
                    . ' `' . $prefix . 'aktion`, `' . $prefix . 'bemerk`,'
                    . ' `' . $prefix . 'funktion`, `' . $prefix . 'kuerzel`,'
                    . ' `' . $prefix . 'benutzer`)'
                    . ' VALUES (?, NOW(), ?, ?, ?, ?, ?)';
                $statement = $connection->prepare($sql);
                if (!$statement) {
                    throw new RuntimeException(
                        'Could not prepare logbook entry insert'
                    );
                }
                try {
                    $incidentId = (int) $incident['active_einsatz_id'];
                    $event = (string) ($entry['event'] ?? '');
                    $comment = (string) ($entry['comment'] ?? '');
                    $function = (string) ($identity['funktion'] ?? '');
                    $code = (string) ($identity['kuerzel'] ?? '');
                    $user = (string) ($identity['benutzer'] ?? '');
                    $statement->bind_param(
                        'isssss',
                        $incidentId,
                        $event,
                        $comment,
                        $function,
                        $code,
                        $user
                    );
                    if (!$statement->execute()) {
                        throw new RuntimeException(
                            'Could not execute logbook entry insert'
                        );
                    }
                } finally {
                    $statement->close();
                }
            }
        );
    } finally {
        estab_auth_close($connection);
    }
}

/** Return the configured red-copy function, accepting both historic encodings. */
function estab_logbook_redcopy_function(array $databaseConfig, string $matrixTable): ?string
{
    $connection = estab_auth_connect($databaseConfig);
    try {
        $sql = 'SELECT `mtx_fkt` FROM ' . estab_auth_table($matrixTable)
            . " WHERE `mtx_rc2` IN ('t', '1') ORDER BY `mtx_lfd` LIMIT 1";
        $result = $connection->query($sql);
        if (!$result) {
            throw new RuntimeException('Could not read red-copy function');
        }
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row) || !isset($row['mtx_fkt']) || !is_string($row['mtx_fkt'])) {
            return null;
        }
        $function = trim($row['mtx_fkt']);
        return $function === '' ? null : $function;
    } finally {
        estab_auth_close($connection);
    }
}

/** Emit a deliberately small error response and stop request processing. */
function estab_logbook_abort(int $status, string $message): never
{
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function estab_logbook_require_csrf(array $server, array $post): void
{
    try {
        estab_csrf_require_post($server, $post);
    } catch (RuntimeException) {
        estab_logbook_abort(403, 'Ungültige oder abgelaufene Formularanforderung.');
    }
}

/** Complete a successful write with Post/Redirect/Get. */
function estab_logbook_redirect(string $path): never
{
    $publicRoot = estab_public_root();
    $valid = !str_contains($path, "\r")
        && !str_contains($path, "\n")
        && str_starts_with($path, $publicRoot);
    if ($publicRoot === '/') {
        $valid = $valid
            && preg_match('~\A/[A-Za-z0-9_./-]+\z/D~', $path) === 1
            && !str_starts_with($path, '//')
            && !str_contains($path, '..');
    }
    if (!$valid) {
        $path = $publicRoot;
    }
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    header('Location: ' . $path, true, 303);
    exit;
}
