<?php

/**
 * Authentication boundary for the legacy UI.
 *
 * Validation and password decisions are deliberately pure so they can be
 * tested without a database. Database calls use mysqli prepared statements;
 * only a validated table identifier is interpolated.
 */

require_once __DIR__ . '/bootstrap.php';

/** Return the number of Unicode code points, with no mbstring dependency. */
function estab_auth_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? -1 : $count;
}

/** Build the authoritative function-to-role map from $conf_empf. */
function estab_auth_function_roles(array $confEmpf): array
{
    $roles = [];
    foreach ($confEmpf as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $function = isset($entry['fkt']) && is_string($entry['fkt']) ? trim($entry['fkt']) : '';
        $role = isset($entry['rolle']) && is_string($entry['rolle']) ? trim($entry['rolle']) : '';
        if ($function !== '' && $role !== '') {
            $roles[$function] = $role;
        }
    }
    return $roles;
}

/**
 * Validate and normalise a submitted login identity and password.
 *
 * Names intentionally support international characters and punctuation, but
 * control characters and angle brackets are rejected. HTML escaping remains
 * mandatory at output boundaries.
 */
function estab_auth_validate_login(array $input, array $confEmpf): array
{
    $name = isset($input['benutzer']) && is_string($input['benutzer'])
        ? trim($input['benutzer'])
        : '';
    $code = isset($input['kuerzel']) && is_string($input['kuerzel'])
        ? strtolower(trim($input['kuerzel']))
        : '';
    $function = isset($input['funktion']) && is_string($input['funktion'])
        ? trim($input['funktion'])
        : '';
    $password = isset($input['kennwort1']) && is_string($input['kennwort1'])
        ? $input['kennwort1']
        : '';

    $errors = [];
    $nameLength = estab_auth_text_length($name);
    if (
        $nameLength < 1
        || $nameLength > 50
        || preg_match('/[\p{C}<>]/u', $name) === 1
    ) {
        $errors[] = 'benutzer';
    }
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1) {
        $errors[] = 'kuerzel';
    }

    $roles = estab_auth_function_roles($confEmpf);
    if (!array_key_exists($function, $roles)) {
        $errors[] = 'funktion';
    } elseif ($function !== 'A/W' && preg_match('/\A[A-Za-z0-9_]+\z/D', $function) !== 1) {
        // Non-A/W functions become part of dynamic SQL identifiers later.
        $errors[] = 'funktion';
    }

    if ($password === '' || strlen($password) > 255 || str_contains($password, "\0")) {
        $errors[] = 'kennwort1';
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
        'data' => [
            'benutzer' => $name,
            'kuerzel' => $code,
            'funktion' => $function,
            'password' => $password,
            'rolle' => $roles[$function] ?? '',
        ],
    ];
}

/**
 * Verify a modern hash or a legacy plaintext value.
 *
 * A successful plaintext check always returns a replacement hash. Existing
 * hashes are also upgraded when PHP's current PASSWORD_DEFAULT changes.
 */
function estab_auth_verify_password(string $provided, string $stored): array
{
    $info = password_get_info($stored);
    $isHash = ($info['algoName'] ?? 'unknown') !== 'unknown';

    if ($isHash) {
        $valid = password_verify($provided, $stored);
        $replacement = $valid && password_needs_rehash($stored, PASSWORD_DEFAULT)
            ? password_hash($provided, PASSWORD_DEFAULT)
            : null;
        return [
            'valid' => $valid,
            'migrated' => $replacement !== null,
            'replacement' => $replacement,
        ];
    }

    $valid = $stored !== '' && hash_equals($stored, $provided);
    return [
        'valid' => $valid,
        'migrated' => $valid,
        'replacement' => $valid ? password_hash($provided, PASSWORD_DEFAULT) : null,
    ];
}

/** Self-registration remains compatible by default and can be disabled. */
function estab_auth_self_registration_allowed(): bool
{
    return estab_env_bool('ESTAB_ALLOW_SELF_REGISTRATION', true);
}

/** Accept only a syntactically valid direct peer address. */
function estab_auth_remote_ip(array $server): string
{
    $candidate = $server['REMOTE_ADDR'] ?? '';
    if (!is_string($candidate)) {
        return '';
    }
    $candidate = trim($candidate);
    return filter_var($candidate, FILTER_VALIDATE_IP) === false ? '' : $candidate;
}

/**
 * Return a validated proxy address only when proxy headers are explicitly
 * trusted. The complete chain must consist solely of valid IP literals.
 */
function estab_auth_forwarded_ip(array $server, bool $trustProxyHeaders): string
{
    if (!$trustProxyHeaders) {
        return '';
    }
    $header = $server['HTTP_X_FORWARDED_FOR'] ?? '';
    if (!is_string($header) || trim($header) === '') {
        return '';
    }

    $addresses = array_map('trim', explode(',', $header));
    foreach ($addresses as $address) {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return '';
        }
    }
    return $addresses[0];
}

/** Encode a status-list selection for a POST-only form control. */
function estab_auth_identity_token(array $user): string
{
    $json = json_encode([
        'benutzer' => (string) ($user['benutzer'] ?? ''),
        'kuerzel' => (string) ($user['kuerzel'] ?? ''),
        'funktion' => (string) ($user['funktion'] ?? ''),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/** Decode a POSTed status-list selection; it is prefill data, not proof. */
function estab_auth_decode_identity_token(string $token, array $confEmpf): ?array
{
    if ($token === '' || strlen($token) > 512 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $token) !== 1) {
        return null;
    }
    $padding = (4 - strlen($token) % 4) % 4;
    $json = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
    if ($json === false) {
        return null;
    }

    try {
        $identity = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($identity)) {
        return null;
    }

    $name = isset($identity['benutzer']) && is_string($identity['benutzer'])
        ? trim($identity['benutzer'])
        : '';
    $code = isset($identity['kuerzel']) && is_string($identity['kuerzel'])
        ? strtolower(trim($identity['kuerzel']))
        : '';
    $function = isset($identity['funktion']) && is_string($identity['funktion'])
        ? trim($identity['funktion'])
        : '';
    $nameLength = estab_auth_text_length($name);
    if (
        $nameLength < 1
        || $nameLength > 50
        || preg_match('/[\p{C}<>]/u', $name) === 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1
        || !array_key_exists($function, estab_auth_function_roles($confEmpf))
    ) {
        return null;
    }
    return ['benutzer' => $name, 'kuerzel' => $code, 'funktion' => $function];
}

/** Escape untrusted text for HTML text and quoted-attribute contexts. */
function estab_auth_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return the authenticated application identity stored by the login flow.
 *
 * mainindex.php initialises these keys with empty strings for legacy code, so
 * checking only isset() is not an authentication decision.
 */
function estab_auth_session_identity(array $session): ?array
{
    $name = isset($session['vStab_benutzer']) && is_string($session['vStab_benutzer'])
        ? trim($session['vStab_benutzer'])
        : '';
    $code = isset($session['vStab_kuerzel']) && is_string($session['vStab_kuerzel'])
        ? strtolower(trim($session['vStab_kuerzel']))
        : '';
    $function = isset($session['vStab_funktion']) && is_string($session['vStab_funktion'])
        ? trim($session['vStab_funktion'])
        : '';
    $role = isset($session['vStab_rolle']) && is_string($session['vStab_rolle'])
        ? trim($session['vStab_rolle'])
        : '';

    if (
        $name === ''
        || estab_auth_text_length($name) > 50
        || preg_match('/[\p{C}<>]/u', $name) === 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1
        || preg_match('/\A[A-Za-z0-9_\/-]{1,10}\z/D', $function) !== 1
        || estab_auth_text_length($role) < 1
        || estab_auth_text_length($role) > 15
        || preg_match('/[\p{C}<>]/u', $role) === 1
    ) {
        return null;
    }

    return [
        'benutzer' => $name,
        'kuerzel' => $code,
        'funktion' => $function,
        'rolle' => $role,
    ];
}

function estab_auth_session_is_authenticated(array $session): bool
{
    return estab_auth_session_identity($session) !== null;
}

/** Stop a data-bearing endpoint unless the application session is valid. */
function estab_auth_require_session(array $session): void
{
    if (estab_auth_session_is_authenticated($session)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Anmeldung erforderlich.';
    exit;
}

/** Quote a configured table name after strict identifier validation. */
function estab_auth_table(string $table): string
{
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $table) !== 1 || strlen($table) > 64) {
        throw new InvalidArgumentException('Invalid authentication table identifier');
    }
    return '`' . $table . '`';
}

/** Open the mysqli connection supplied by the compatibility bootstrap. */
function estab_auth_connect(array $databaseConfig): mysqli
{
    $connection = mysql_connect(
        (string) ($databaseConfig['server'] ?? ''),
        (string) ($databaseConfig['user'] ?? ''),
        (string) ($databaseConfig['password'] ?? '')
    );
    if (!$connection instanceof mysqli) {
        throw new RuntimeException('Database connection failed');
    }
    if (!$connection->select_db((string) ($databaseConfig['datenbank'] ?? ''))) {
        mysql_close($connection);
        throw new RuntimeException('Database selection failed');
    }
    if (!$connection->set_charset('utf8mb4')) {
        mysql_close($connection);
        throw new RuntimeException('Database character set setup failed');
    }
    return $connection;
}

function estab_auth_close(mysqli $connection): void
{
    mysql_close($connection);
}

/** Fetch exactly one account by its primary key. */
function estab_auth_fetch_user(mysqli $connection, string $table, string $code): ?array
{
    $sql = 'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`, `fwdip`, `aktiv`, `password`'
        . ' FROM ' . estab_auth_table($table) . ' WHERE `kuerzel` = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare account lookup');
    }
    try {
        $statement->bind_param('s', $code);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute account lookup');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/** Update session/audit state and, for inactive users, their selected role. */
function estab_auth_update_user(
    mysqli $connection,
    string $table,
    array $user,
    bool $changeAssignment
): void {
    if ($changeAssignment) {
        $sql = 'UPDATE ' . estab_auth_table($table)
            . ' SET `funktion` = ?, `rolle` = ?, `sid` = ?, `ip` = ?, `fwdip` = ?, `aktiv` = 1, `password` = ?'
            . ' WHERE `kuerzel` = ?';
        $statement = $connection->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Could not prepare account update');
        }
        try {
            $statement->bind_param(
                'sssssss',
                $user['funktion'],
                $user['rolle'],
                $user['sid'],
                $user['ip'],
                $user['fwdip'],
                $user['password'],
                $user['kuerzel']
            );
            if (!$statement->execute()) {
                throw new RuntimeException('Could not update account');
            }
        } finally {
            $statement->close();
        }
        return;
    }

    $sql = 'UPDATE ' . estab_auth_table($table)
        . ' SET `sid` = ?, `ip` = ?, `fwdip` = ?, `aktiv` = 1, `password` = ? WHERE `kuerzel` = ?';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare account update');
    }
    try {
        $statement->bind_param(
            'sssss',
            $user['sid'],
            $user['ip'],
            $user['fwdip'],
            $user['password'],
            $user['kuerzel']
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Could not update account');
        }
    } finally {
        $statement->close();
    }
}

/** Insert a self-registered account with an already hashed password. */
function estab_auth_insert_user(mysqli $connection, string $table, array $user): void
{
    $sql = 'INSERT INTO ' . estab_auth_table($table)
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`, `fwdip`, `password`, `aktiv`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare account insert');
    }
    try {
        $statement->bind_param(
            'ssssssss',
            $user['benutzer'],
            $user['kuerzel'],
            $user['funktion'],
            $user['rolle'],
            $user['sid'],
            $user['ip'],
            $user['fwdip'],
            $user['password']
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Could not insert account');
        }
    } finally {
        $statement->close();
    }
}

/** Fetch users for the public status table through a prepared SELECT. */
function estab_auth_fetch_users(mysqli $connection, string $table): array
{
    $sql = 'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`'
        . ' FROM ' . estab_auth_table($table) . ' ORDER BY `aktiv` DESC, `kuerzel`';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare status lookup');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute status lookup');
        }
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

/** Mark an account logged out using its primary key. */
function estab_auth_mark_logged_out(mysqli $connection, string $table, string $code): void
{
    $sql = 'UPDATE ' . estab_auth_table($table)
        . " SET `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = '' WHERE `kuerzel` = ?";
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare logout update');
    }
    try {
        $statement->bind_param('s', $code);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not update logout state');
        }
    } finally {
        $statement->close();
    }
}

/**
 * Destroy server-side session data and expire both current and legacy cookies.
 */
function estab_auth_destroy_session(): void
{
    $cookieParams = session_get_cookie_params();
    $cookieOptions = [
        'expires' => time() - 42000,
        'path' => $cookieParams['path'] !== '' ? $cookieParams['path'] : '/',
        'secure' => (bool) $cookieParams['secure'],
        'httponly' => (bool) $cookieParams['httponly'],
        'samesite' => $cookieParams['samesite'] !== '' ? $cookieParams['samesite'] : 'Lax',
    ];
    if ($cookieParams['domain'] !== '') {
        $cookieOptions['domain'] = $cookieParams['domain'];
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', $cookieOptions);
        unset($_COOKIE[session_name()]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    foreach (['vStab_benutzer', 'vStab_kuerzel', 'vStab_funktion', 'vStab_rolle'] as $cookieName) {
        foreach (array_unique([$cookieOptions['path'], '/', '/intern/4fach/']) as $path) {
            $legacyOptions = $cookieOptions;
            $legacyOptions['path'] = $path;
            setcookie($cookieName, '', $legacyOptions);
        }
        unset($_COOKIE[$cookieName]);
    }
}
