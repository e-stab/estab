<?php

declare(strict_types=1);

/**
 * Reconcile the six legacy tables used by one staff/subject-matter hat.
 *
 * These tables cannot be replaced with one static table without a data
 * migration because the historic controllers still address them by function
 * and account code.  All callers must derive both values from authenticated
 * database state.  Request parameters are never accepted by this boundary.
 */

function estab_dynamic_schema_identifier(string $identifier): string
{
    if (
        strlen($identifier) > 64
        || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $identifier) !== 1
    ) {
        throw new InvalidArgumentException(
            'Ungültiger dynamischer Tabellenname.'
        );
    }
    return '`' . $identifier . '`';
}

function estab_dynamic_schema_database(mysqli $connection): string
{
    $result = $connection->query('SELECT DATABASE()');
    if (!$result) {
        throw new RuntimeException(
            'Datenbank für dynamische Tabellen konnte nicht ermittelt werden.'
        );
    }
    try {
        $row = $result->fetch_row();
    } finally {
        $result->free();
    }
    $database = is_array($row) ? ($row[0] ?? null) : null;
    if (
        !is_string($database)
        || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
    ) {
        throw new RuntimeException(
            'Datenbank für dynamische Tabellen ist ungültig.'
        );
    }
    return $database;
}

function estab_dynamic_schema_lock_timeout(): int
{
    $timeout = defined('ESTAB_DYNAMIC_SCHEMA_LOCK_TIMEOUT_SECONDS')
        ? constant('ESTAB_DYNAMIC_SCHEMA_LOCK_TIMEOUT_SECONDS')
        : 15;
    if (!is_int($timeout) || $timeout < 0 || $timeout > 30) {
        throw new RuntimeException(
            'Ungültiges Zeitlimit für dynamische Tabellen.'
        );
    }
    return $timeout;
}

function estab_dynamic_schema_lock_name(
    string $database,
    string $functionBase
): string {
    estab_dynamic_schema_identifier($functionBase);
    return 'estab:schema:' . substr(
        hash('sha256', $database . "\0" . $functionBase),
        0,
        51
    );
}

function estab_dynamic_schema_acquire(
    mysqli $connection,
    string $lockName
): void {
    $timeout = estab_dynamic_schema_lock_timeout();
    $statement = $connection->prepare('SELECT GET_LOCK(?, ?)');
    if (!$statement) {
        throw new RuntimeException(
            'Sperre für dynamische Tabellen konnte nicht vorbereitet werden.'
        );
    }
    try {
        $statement->bind_param('si', $lockName, $timeout);
        $statement->execute();
        $row = $statement->get_result()->fetch_row();
    } finally {
        $statement->close();
    }
    if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
        throw new RuntimeException(
            'Dynamische Tabellen werden bereits bearbeitet.'
        );
    }
}

function estab_dynamic_schema_release(
    mysqli $connection,
    string $lockName
): void {
    $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if (!$statement) {
        throw new RuntimeException(
            'Sperre für dynamische Tabellen konnte nicht freigegeben werden.'
        );
    }
    try {
        $statement->bind_param('s', $lockName);
        $statement->execute();
        $row = $statement->get_result()->fetch_row();
    } finally {
        $statement->close();
    }
    if (!is_array($row) || (string) ($row[0] ?? '') !== '1') {
        throw new RuntimeException(
            'Sperre für dynamische Tabellen ging verloren.'
        );
    }
}

/** Execute one fixed schema statement without exposing it in an error page. */
function estab_dynamic_schema_execute(
    mysqli $connection,
    string $query
): void {
    if (!$connection->query($query)) {
        throw new RuntimeException(
            'Dynamische Tabellen konnten nicht abgeglichen werden.'
        );
    }
}

/** Fail before the first DDL statement if a caller already owns a transaction. */
function estab_dynamic_schema_require_no_transaction(
    mysqli $connection
): void {
    $result = $connection->query('SELECT @@SESSION.in_transaction');
    if (!$result) {
        throw new RuntimeException(
            'Transaktionsstatus für dynamische Tabellen ist nicht verfügbar.'
        );
    }
    try {
        $row = $result->fetch_row();
    } finally {
        $result->free();
    }
    if (!is_array($row) || !in_array((string) ($row[0] ?? ''), ['0', '1'], true)) {
        throw new RuntimeException(
            'Transaktionsstatus für dynamische Tabellen ist ungültig.'
        );
    }
    if ((string) $row[0] === '1') {
        throw new LogicException(
            'Dynamische Tabellen dürfen nicht innerhalb einer '
            . 'Fachtransaktion abgeglichen werden.'
        );
    }
}

/**
 * Reconcile tables addressed by already validated legacy base names.
 *
 * DDL commits implicitly.  Callers must invoke this function before starting
 * their domain transaction; the domain operation then revalidates the exact
 * assignment to close the time-of-check/time-of-use window.
 */
function estab_dynamic_schema_reconcile_bases(
    mysqli $connection,
    string $userBase,
    string $functionBase
): void {
    estab_dynamic_schema_require_no_transaction($connection);
    $userRead = estab_dynamic_schema_identifier($userBase . '_read');
    $functionDone = estab_dynamic_schema_identifier($functionBase . '_erl');
    $functionCategory =
        estab_dynamic_schema_identifier($functionBase . '_katego');
    $functionLink =
        estab_dynamic_schema_identifier($functionBase . '_kategolink');
    $userCategory = estab_dynamic_schema_identifier($userBase . '_katego');
    $userLink = estab_dynamic_schema_identifier($userBase . '_kategolink');

    $database = estab_dynamic_schema_database($connection);
    $lockName = estab_dynamic_schema_lock_name($database, $functionBase);
    $lockAcquired = false;
    $originalSqlMode = null;

    $createQueries = [
        "CREATE TABLE IF NOT EXISTS {$userRead} (
          `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
            COMMENT 'Laufende Nummer',
          `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
            COMMENT 'Zeitpunkt der letzten Aenderung',
          `nachnum` BIGINT NOT NULL COMMENT 'Nachrichtennummer',
          `gelesen` DATETIME NULL DEFAULT NULL
            COMMENT 'Zeitpunkt, zu dem die Nachricht gelesen wurde',
          PRIMARY KEY (`lfd`),
          KEY `idx_nachnum` (`nachnum`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$functionDone} (
          `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
            COMMENT 'Laufende Nummer',
          `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
            COMMENT 'Zeitpunkt der letzten Aenderung',
          `nachnum` BIGINT NOT NULL COMMENT 'Nachrichtennummer',
          `erledigt` DATETIME NULL DEFAULT NULL
            COMMENT 'Zeitpunkt, zu dem die Nachricht erledigt wurde',
          PRIMARY KEY (`lfd`),
          KEY `idx_nachnum` (`nachnum`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$functionCategory} (
          `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
            COMMENT 'Laufende Nummer',
          `kategorie` VARCHAR(10) NOT NULL
            COMMENT 'Benutzerdefinierte Kategorie',
          `beschreibung` VARCHAR(254) NULL
            COMMENT 'Beschreibung zur Kategorie',
          PRIMARY KEY (`lfd`),
          KEY `idx_kategorie` (`kategorie`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$functionLink} (
          `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `msg` BIGINT NOT NULL,
          `katego` BIGINT NOT NULL,
          PRIMARY KEY (`lfd`),
          KEY `idx_msg_katego` (`msg`, `katego`),
          KEY `idx_katego_msg` (`katego`, `msg`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$userCategory} (
          `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT
            COMMENT 'Laufende Nummer',
          `kategorie` VARCHAR(10) NOT NULL
            COMMENT 'Benutzerdefinierte Kategorie',
          `beschreibung` VARCHAR(254) NULL
            COMMENT 'Beschreibung zur Kategorie',
          PRIMARY KEY (`lfd`),
          KEY `idx_kategorie` (`kategorie`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS {$userLink} (
          `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `msg` BIGINT NOT NULL,
          `katego` BIGINT NOT NULL,
          PRIMARY KEY (`lfd`),
          KEY `idx_msg_katego` (`msg`, `katego`),
          KEY `idx_katego_msg` (`katego`, `msg`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci",
    ];
    $migrationQueries = [
        "ALTER TABLE {$userRead}
           MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
           MODIFY `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
             ON UPDATE CURRENT_TIMESTAMP,
           MODIFY `nachnum` BIGINT NOT NULL,
           MODIFY `gelesen` DATETIME NULL DEFAULT NULL,
           ENGINE=InnoDB,
           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "UPDATE {$userRead} SET `gelesen` = NULL
          WHERE `gelesen` = '0000-00-00 00:00:00'",
        "CREATE INDEX IF NOT EXISTS `idx_nachnum`
          ON {$userRead} (`nachnum`)",
        "ALTER TABLE {$functionDone}
           MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
           MODIFY `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
             ON UPDATE CURRENT_TIMESTAMP,
           MODIFY `nachnum` BIGINT NOT NULL,
           MODIFY `erledigt` DATETIME NULL DEFAULT NULL,
           ENGINE=InnoDB,
           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "UPDATE {$functionDone} SET `erledigt` = NULL
          WHERE `erledigt` = '0000-00-00 00:00:00'",
        "CREATE INDEX IF NOT EXISTS `idx_nachnum`
          ON {$functionDone} (`nachnum`)",
        "ALTER TABLE {$functionCategory}
           MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
           MODIFY `kategorie` VARCHAR(10) NOT NULL,
           MODIFY `beschreibung` VARCHAR(254) NULL,
           ENGINE=InnoDB,
           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE INDEX IF NOT EXISTS `idx_kategorie`
          ON {$functionCategory} (`kategorie`)",
        "ALTER TABLE {$functionLink}
           ADD COLUMN IF NOT EXISTS `lfd`
             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST",
        "ALTER TABLE {$functionLink}
           MODIFY `msg` BIGINT NOT NULL,
           MODIFY `katego` BIGINT NOT NULL,
           ENGINE=InnoDB,
           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE INDEX IF NOT EXISTS `idx_msg_katego`
          ON {$functionLink} (`msg`, `katego`)",
        "CREATE INDEX IF NOT EXISTS `idx_katego_msg`
          ON {$functionLink} (`katego`, `msg`)",
        "ALTER TABLE {$userCategory}
           MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
           MODIFY `kategorie` VARCHAR(10) NOT NULL,
           MODIFY `beschreibung` VARCHAR(254) NULL,
           ENGINE=InnoDB,
           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE INDEX IF NOT EXISTS `idx_kategorie`
          ON {$userCategory} (`kategorie`)",
        "ALTER TABLE {$userLink}
           ADD COLUMN IF NOT EXISTS `lfd`
             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST",
        "ALTER TABLE {$userLink}
           MODIFY `msg` BIGINT NOT NULL,
           MODIFY `katego` BIGINT NOT NULL,
           ENGINE=InnoDB,
           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE INDEX IF NOT EXISTS `idx_msg_katego`
          ON {$userLink} (`msg`, `katego`)",
        "CREATE INDEX IF NOT EXISTS `idx_katego_msg`
          ON {$userLink} (`katego`, `msg`)",
    ];

    $operationError = null;
    try {
        estab_dynamic_schema_acquire($connection, $lockName);
        $lockAcquired = true;
        $modeResult = $connection->query('SELECT @@SESSION.sql_mode');
        if (!$modeResult) {
            throw new RuntimeException(
                'SQL-Modus für dynamische Tabellen konnte nicht gelesen werden.'
            );
        }
        try {
            $modeRow = $modeResult->fetch_row();
        } finally {
            $modeResult->free();
        }
        $originalSqlMode = (string) ($modeRow[0] ?? '');

        // Invalid historic zero dates must be converted before strict column
        // definitions are restored.  Relax only this private reconciliation
        // connection/session and restore it in every outcome.
        estab_dynamic_schema_execute(
            $connection,
            "SET SESSION sql_mode = ''"
        );
        foreach ($createQueries as $query) {
            estab_dynamic_schema_execute($connection, $query);
        }
        foreach ($migrationQueries as $query) {
            estab_dynamic_schema_execute($connection, $query);
        }
    } catch (Throwable $exception) {
        $operationError = $exception;
    }

    $cleanupError = null;
    if ($originalSqlMode !== null) {
        try {
            $restore = $connection->prepare('SET SESSION sql_mode = ?');
            if (!$restore) {
                throw new RuntimeException(
                    'SQL-Modus konnte nicht wiederhergestellt werden.'
                );
            }
            try {
                $restore->bind_param('s', $originalSqlMode);
                $restore->execute();
            } finally {
                $restore->close();
            }
        } catch (Throwable $exception) {
            $cleanupError = $exception;
        }
    }
    if ($lockAcquired) {
        try {
            estab_dynamic_schema_release($connection, $lockName);
        } catch (Throwable $exception) {
            $cleanupError ??= $exception;
        }
    }
    if ($operationError instanceof Throwable) {
        throw $operationError;
    }
    if ($cleanupError instanceof Throwable) {
        throw $cleanupError;
    }
}

/**
 * Return whether a hat participates in the legacy staff message workspaces.
 *
 * ETB is deliberately capability-only.  It writes the common nv_etb domain
 * and receives neither S2 red-copy rights nor unused message/category tables.
 */
function estab_dynamic_schema_hat_requires_tables(
    string $function,
    string $role
): bool {
    return in_array($role, ['Stab', 'FB'], true)
        && strcasecmp($function, 'ETB') !== 0;
}

/** Reconcile a database-validated personal function hat. */
function estab_dynamic_schema_reconcile_hat(
    mysqli $connection,
    string $prefix,
    string $function,
    string $userCode,
    string $role
): void {
    if (!estab_dynamic_schema_hat_requires_tables($function, $role)) {
        return;
    }
    if (
        preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $prefix) !== 1
        || preg_match('/\A[A-Za-z0-9_]{1,10}\z/D', $function) !== 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $userCode) !== 1
    ) {
        throw new InvalidArgumentException(
            'Ungültige Identität für dynamische Funktionstabellen.'
        );
    }
    $normalizedFunction = strtolower($function);
    estab_dynamic_schema_reconcile_bases(
        $connection,
        strtolower($prefix . $normalizedFunction . '_' . $userCode),
        strtolower($prefix . '_fkt_' . $normalizedFunction)
    );
}
