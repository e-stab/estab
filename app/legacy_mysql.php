<?php

/**
 * Narrow compatibility layer for the ext/mysql API removed in PHP 7.
 *
 * It intentionally implements only the functions used by eStab. New code
 * must use mysqli/PDO directly and prepared statements. Keeping the adapter in
 * one file makes the remaining migration surface measurable.
 */

if (!defined('MYSQL_ASSOC')) {
    define('MYSQL_ASSOC', 1);
}
if (!defined('MYSQL_NUM')) {
    define('MYSQL_NUM', 2);
}
if (!defined('MYSQL_BOTH')) {
    define('MYSQL_BOTH', 3);
}

final class EstabLegacyMysql
{
    private static ?mysqli $lastLink = null;
    private static int $lastErrno = 0;
    private static string $lastError = '';

    public static function rememberError(mysqli|false|null $link = null): void
    {
        if ($link instanceof mysqli) {
            self::$lastErrno = $link->errno;
            self::$lastError = $link->error;
            return;
        }
        self::$lastErrno = mysqli_connect_errno();
        self::$lastError = mysqli_connect_error() ?: '';
    }

    public static function setLink(mysqli $link): void
    {
        self::$lastLink = $link;
        self::$lastErrno = 0;
        self::$lastError = '';
    }

    public static function link(mysqli|false|null $link = null): mysqli|false
    {
        if ($link instanceof mysqli) {
            return $link;
        }
        if (self::$lastLink instanceof mysqli) {
            return self::$lastLink;
        }
        if (self::$lastError === '') {
            self::$lastErrno = 2006;
            self::$lastError = 'No active database connection';
        }
        return false;
    }

    public static function clear(mysqli $link): void
    {
        if (self::$lastLink === $link) {
            self::$lastLink = null;
        }
    }

    public static function errno(): int
    {
        return self::$lastErrno;
    }

    public static function error(): string
    {
        return self::$lastError;
    }
}

if (!function_exists('mysql_connect')) {
    function mysql_connect(
        string $server = '',
        string $username = '',
        string $password = '',
        bool $newLink = false,
        int $clientFlags = 0
    ): mysqli|false {
        unset($newLink);
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = $server !== '' ? $server : (getenv('ESTAB_DB_HOST') ?: 'db');
        $port = function_exists('estab_env_integer')
            ? estab_env_integer('ESTAB_DB_PORT', 3306, 1, 65535)
            : (int) (getenv('ESTAB_DB_PORT') ?: 3306);
        if (preg_match('/^\[([^]]+)](?::(\d+))?$/', $host, $matches)) {
            $host = $matches[1];
            $port = isset($matches[2]) ? (int) $matches[2] : $port;
        } elseif (substr_count($host, ':') === 1 && preg_match('/^(.+):(\d+)$/', $host, $matches)) {
            $host = $matches[1];
            $port = (int) $matches[2];
        }

        $link = mysqli_init();
        if ($link === false) {
            EstabLegacyMysql::rememberError(false);
            return false;
        }
        $link->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int) (getenv('ESTAB_DB_CONNECT_TIMEOUT') ?: 5));
        if (!$link->real_connect($host, $username, $password, '', $port, null, $clientFlags)) {
            EstabLegacyMysql::rememberError($link);
            return false;
        }
        $link->set_charset('utf8mb4');
        EstabLegacyMysql::setLink($link);
        return $link;
    }

    function mysql_select_db(string $databaseName, mysqli|false|null $link = null): bool
    {
        $connection = EstabLegacyMysql::link($link);
        if (!$connection) {
            return false;
        }
        $result = $connection->select_db($databaseName);
        if (!$result) {
            EstabLegacyMysql::rememberError($connection);
        }
        return $result;
    }

    function mysql_query(string $query, mysqli|false|null $link = null): mysqli_result|bool
    {
        $connection = EstabLegacyMysql::link($link);
        if (!$connection) {
            return false;
        }
        $result = $connection->query($query);
        if ($result === false) {
            EstabLegacyMysql::rememberError($connection);
        }
        return $result;
    }

    function mysql_ping(mysqli|false|null $link = null): bool
    {
        $connection = EstabLegacyMysql::link($link);
        if (!$connection) {
            return false;
        }
        try {
            // mysqli::ping() is deprecated since PHP 8.4 because its former
            // reconnect behaviour no longer exists. A lightweight roundtrip
            // preserves the legacy call site's actual liveness check.
            $result = $connection->query('SELECT 1');
            if ($result instanceof mysqli_result) {
                $result->free();
                return true;
            }
            return $result === true;
        } catch (mysqli_sql_exception) {
            EstabLegacyMysql::rememberError($connection);
            return false;
        }
    }

    function mysql_close(mysqli|false|null $link = null): bool
    {
        $connection = EstabLegacyMysql::link($link);
        if (!$connection) {
            return false;
        }
        EstabLegacyMysql::clear($connection);
        return $connection->close();
    }

    function mysql_error(mysqli|false|null $link = null): string
    {
        $connection = EstabLegacyMysql::link($link);
        return $connection instanceof mysqli && $connection->error !== ''
            ? $connection->error
            : EstabLegacyMysql::error();
    }

    function mysql_errno(mysqli|false|null $link = null): int
    {
        $connection = EstabLegacyMysql::link($link);
        return $connection instanceof mysqli && $connection->errno !== 0
            ? $connection->errno
            : EstabLegacyMysql::errno();
    }

    function mysql_fetch_assoc(mysqli_result $result): array|null|false
    {
        return $result->fetch_assoc();
    }

    function mysql_fetch_row(mysqli_result $result): array|null|false
    {
        return $result->fetch_row();
    }

    function mysql_fetch_array(mysqli_result $result, int $resultType = MYSQL_BOTH): array|null|false
    {
        $mode = match ($resultType) {
            MYSQL_ASSOC => MYSQLI_ASSOC,
            MYSQL_NUM => MYSQLI_NUM,
            default => MYSQLI_BOTH,
        };
        return $result->fetch_array($mode);
    }

    function mysql_num_rows(mysqli_result $result): int
    {
        return $result->num_rows;
    }

    function mysql_free_result(mysqli_result $result): bool
    {
        $result->free();
        return true;
    }

    function mysql_list_tables(string $database, mysqli|false|null $link = null): mysqli_result|false
    {
        $connection = EstabLegacyMysql::link($link);
        if (!$connection) {
            return false;
        }
        $identifier = str_replace('`', '``', $database);
        $result = $connection->query("SHOW TABLES FROM `{$identifier}`");
        if ($result === false) {
            EstabLegacyMysql::rememberError($connection);
        }
        return $result;
    }

    function mysql_result(mysqli_result $result, int $row, int|string $field = 0): mixed
    {
        if (!$result->data_seek($row)) {
            return false;
        }
        $values = $result->fetch_array(MYSQLI_BOTH);
        return $values === null ? false : ($values[$field] ?? false);
    }

    function mysql_escape_string(string $unescapedString): string
    {
        $connection = EstabLegacyMysql::link();
        return $connection instanceof mysqli
            ? $connection->real_escape_string($unescapedString)
            : addslashes($unescapedString);
    }

    function mysql_real_escape_string(string $unescapedString, mysqli|false|null $link = null): string
    {
        $connection = EstabLegacyMysql::link($link);
        return $connection instanceof mysqli
            ? $connection->real_escape_string($unescapedString)
            : addslashes($unescapedString);
    }
}


/**
 * Fail a legacy database operation without telling the browser why.
 *
 * The legacy layer used to end the request with die() and print the failing
 * SQL statement together with the MySQL error text and number. That path is
 * reachable in production, so the details now go to the server log and the
 * browser sees a message it can act on.
 */
function estab_legacy_database_failure(
    string $context,
    ?string $query = null
): never {
    $details = '[' . $context . '] ' . mysql_errno() . ' ' . mysql_error();
    if ($query !== null && $query !== '') {
        $details .= ' | statement: ' . $query;
    }
    error_log('eStab legacy database failure: ' . $details);
    if (!headers_sent()) {
        http_response_code(500);
    }
    exit(
        'Die Datenbankabfrage ist fehlgeschlagen. '
        . 'Die Einzelheiten stehen im Serverprotokoll.'
    );
}
