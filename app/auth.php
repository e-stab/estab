<?php

/**
 * Authentication boundary for the legacy UI.
 *
 * Validation and password decisions are deliberately pure so they can be
 * tested without a database. Database calls use mysqli prepared statements;
 * only a validated table identifier is interpolated.
 */

require_once __DIR__ . '/bootstrap.php';

if (!defined('ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES')) {
    define('ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES', 1024);
}
if (!defined('ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH')) {
    // HTML counts UTF-16 code units while PHP validates Unicode code points.
    // A valid UTF-8 string never needs more UTF-16 code units than UTF-8
    // bytes. Matching the server byte envelope therefore keeps every
    // server-valid credential enterable, including astral symbols.
    define(
        'ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH',
        ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES
    );
}

/** Return the number of Unicode code points, with no mbstring dependency. */
function estab_auth_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? -1 : $count;
}

/** Return the non-truncating algorithm required for all newly written hashes. */
function estab_auth_password_algorithm(): string|int
{
    if (!defined('PASSWORD_ARGON2ID')) {
        throw new RuntimeException(
            'Argon2id-Unterstützung ist in dieser PHP-Laufzeit nicht verfügbar.'
        );
    }
    $algorithm = constant('PASSWORD_ARGON2ID');
    if (!is_string($algorithm) && !is_int($algorithm)) {
        throw new RuntimeException('Die Argon2id-Konfiguration ist ungültig.');
    }
    return $algorithm;
}

/** Return explicit, reproducible Argon2id costs from the pinned PHP runtime. */
function estab_auth_password_options(): array
{
    $options = [
        'memory_cost' => defined('PASSWORD_ARGON2_DEFAULT_MEMORY_COST')
            ? constant('PASSWORD_ARGON2_DEFAULT_MEMORY_COST')
            : null,
        'time_cost' => defined('PASSWORD_ARGON2_DEFAULT_TIME_COST')
            ? constant('PASSWORD_ARGON2_DEFAULT_TIME_COST')
            : null,
        'threads' => defined('PASSWORD_ARGON2_DEFAULT_THREADS')
            ? constant('PASSWORD_ARGON2_DEFAULT_THREADS')
            : null,
    ];
    foreach ($options as $value) {
        if (!is_int($value) || $value < 1) {
            throw new RuntimeException(
                'Die Argon2id-Kostenparameter sind ungültig.'
            );
        }
    }
    return $options;
}

/** Hash a validated credential without bcrypt's 72-byte truncation. */
function estab_auth_hash_password(string $password): string
{
    if (
        $password === ''
        || strlen($password) > ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES
        || str_contains($password, "\0")
    ) {
        throw new RuntimeException('Das Kennwort liegt außerhalb der sicheren Eingabegrenzen.');
    }
    $hash = password_hash(
        $password,
        estab_auth_password_algorithm(),
        estab_auth_password_options()
    );
    if (!is_string($hash) || $hash === '' || strlen($hash) > 255) {
        throw new RuntimeException('Das Kennwort konnte nicht sicher gehasht werden.');
    }
    return $hash;
}

/**
 * Decide whether one successfully verified hash can be upgraded safely.
 *
 * bcrypt cannot distinguish suffixes once the submitted value reaches 72
 * bytes. A historical bcrypt hash may therefore only be rebound to the exact
 * submitted value when that value is shorter than the truncation boundary.
 * Existing Argon2id parameters are upgraded only when every cost is at most
 * the target and at least one is lower; a stronger or mixed profile is never
 * silently downgraded.
 */
function estab_auth_password_hash_needs_upgrade(
    string $stored,
    string $provided
): bool {
    $info = password_get_info($stored);
    $algorithmName = $info['algoName'] ?? 'unknown';
    if ($algorithmName === 'bcrypt') {
        return strlen($provided) < 72;
    }
    if ($algorithmName !== 'argon2id') {
        return $algorithmName !== 'unknown';
    }

    $currentOptions = $info['options'] ?? null;
    if (!is_array($currentOptions)) {
        return false;
    }
    $targetOptions = estab_auth_password_options();
    $weaker = false;
    foreach ($targetOptions as $name => $target) {
        $current = $currentOptions[$name] ?? null;
        if (!is_int($current) || $current < 1) {
            return false;
        }
        if ($current > $target) {
            return false;
        }
        if ($current < $target) {
            $weaker = true;
        }
    }
    return $weaker;
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
    return estab_auth_validate_login_with_roles(
        $input,
        estab_auth_function_roles($confEmpf)
    );
}

/**
 * Validate login input against a function map read under the assignment-policy
 * lock. Keeping this decision pure makes both the legacy configuration adapter
 * and the database-authoritative login path use exactly the same boundary.
 *
 * @param array<string,string> $roles
 */
function estab_auth_validate_login_with_roles(array $input, array $roles): array
{
    $rawName = $input['benutzer'] ?? null;
    $rawCode = $input['kuerzel'] ?? null;
    $rawFunction = $input['funktion'] ?? null;
    $name = is_string($rawName)
        ? trim($rawName)
        : '';
    $code = is_string($rawCode)
        ? strtolower(trim($rawCode))
        : '';
    $function = is_string($rawFunction)
        ? trim($rawFunction)
        : '';
    $password = isset($input['kennwort1']) && is_string($input['kennwort1'])
        ? $input['kennwort1']
        : '';

    $errors = [];
    $nameLength = estab_auth_text_length($name);
    if (
        !is_string($rawName)
        || preg_match('//u', $rawName) !== 1
        || preg_match('/[\p{C}<>]/u', $rawName) === 1
        || $nameLength < 1
        || $nameLength > 50
    ) {
        $errors[] = 'benutzer';
    }
    if (
        !is_string($rawCode)
        || preg_match('/[\x00-\x1F\x7F]/D', $rawCode) === 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1
    ) {
        $errors[] = 'kuerzel';
    }

    if (
        !is_string($rawFunction)
        || preg_match('//u', $rawFunction) !== 1
        || preg_match('/[\p{C}]/u', $rawFunction) === 1
        || !array_key_exists($function, $roles)
        || !is_string($roles[$function])
        || !in_array($roles[$function], ['Stab', 'FB', 'Fernmelder'], true)
    ) {
        $errors[] = 'funktion';
    } elseif ($function !== 'A/W' && preg_match('/\A[A-Za-z0-9_]+\z/D', $function) !== 1) {
        // Non-A/W functions become part of dynamic SQL identifiers later.
        $errors[] = 'funktion';
    }

    if (
        $password === ''
        || strlen($password) > ESTAB_AUTH_PASSWORD_MAXIMUM_BYTES
        || str_contains($password, "\0")
    ) {
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
 * unambiguous hashes are upgraded without lowering stronger Argon2id costs.
 * bcrypt credentials presented with 72 or more bytes remain unchanged until
 * an explicit password reset because bcrypt cannot authenticate their suffix.
 */
function estab_auth_verify_password(string $provided, string $stored): array
{
    $info = password_get_info($stored);
    $isHash = ($info['algoName'] ?? 'unknown') !== 'unknown';

    if ($isHash) {
        $valid = password_verify($provided, $stored);
        $replacement = $valid && estab_auth_password_hash_needs_upgrade(
            $stored,
            $provided
        )
            ? estab_auth_hash_password($provided)
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
        'replacement' => $valid ? estab_auth_hash_password($provided) : null,
    ];
}

/**
 * Legacy deployment default for the migration-114 ENVIRONMENT mode.
 *
 * Runtime consumers must use app/self_registration.php. This helper remains
 * intentionally pure so an upgraded installation keeps its former setting
 * until an administrator stores an authoritative database mode.
 */
function estab_auth_self_registration_allowed(): bool
{
    return estab_env_bool('ESTAB_ALLOW_SELF_REGISTRATION', false);
}

/**
 * Resolve the account flow selected by the anonymous login UI.
 *
 * The explicit flow is authoritative for the new UI. The historical
 * 2teskennwort marker remains a compatibility fallback for existing clients.
 */
function estab_auth_login_flow(array $input): ?string
{
    if (array_key_exists('login_flow', $input)) {
        if (!is_string($input['login_flow'])) {
            return null;
        }
        return in_array($input['login_flow'], ['existing', 'new'], true)
            ? $input['login_flow']
            : null;
    }

    $legacyConfirmation = $input['2teskennwort'] ?? null;
    if ($legacyConfirmation === 'Yes') {
        return 'new';
    }
    if ($legacyConfirmation === 'No') {
        return 'existing';
    }
    if (array_key_exists('2teskennwort', $input)) {
        return null;
    }

    // Compatibility for the historical one-password form produced after
    // selecting an account from the public list. Unknown accounts remain in
    // the existing-account branch and can therefore never be registered.
    foreach (['benutzer', 'kuerzel', 'funktion', 'kennwort1'] as $credentialKey) {
        if (!isset($input[$credentialKey]) || !is_string($input[$credentialKey])) {
            return null;
        }
    }
    return 'existing';
}

/**
 * A stored function is an administrative assignment, not online state.
 *
 * Logout changes only `aktiv`; it must never let request data select another
 * function and thereby another role. Reassignment belongs to the independently
 * authenticated administration boundary.
 */
function estab_auth_assignment_allowed(array $storedUser, string $submittedFunction): bool
{
    return hash_equals(
        (string) ($storedUser['funktion'] ?? ''),
        $submittedFunction
    );
}

/** A blocked account can neither create nor retain an application session. */
function estab_auth_account_is_blocked(array $storedUser): bool
{
    return (int) ($storedUser['estab_gesperrt'] ?? 0) === 1;
}

/** A signed-in user is shown as inactive after this many idle seconds. */
function estab_auth_presence_idle_seconds(): int
{
    return 15 * 60;
}

/** An application session is revoked after this many idle seconds. */
function estab_auth_session_idle_seconds(): int
{
    return 12 * 60 * 60;
}

/** Parse the UTC DATETIME(6) written by the authentication boundary. */
function estab_auth_activity_time(mixed $value): ?DateTimeImmutable
{
    if (
        !is_string($value)
        || preg_match(
            '/\A([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})'
                . '(?:\.([0-9]{1,6}))?\z/D',
            $value,
            $matches
        ) !== 1
    ) {
        return null;
    }
    $normalised = $matches[1] . '.'
        . str_pad((string) ($matches[2] ?? ''), 6, '0');
    $time = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s.u',
        $normalised,
        new DateTimeZone('UTC')
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$time instanceof DateTimeImmutable
        || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $time->format('Y-m-d H:i:s.u') !== $normalised
    ) {
        return null;
    }
    return $time;
}

/**
 * Resolve one account's shared presence/session state.
 *
 * `aktiv` remains the revocable legacy session flag. The separate activity
 * timestamp controls only the visible 15-minute presence and the authoritative
 * 12-hour idle timeout. Missing, malformed and future timestamps fail closed.
 *
 * @return 'blocked'|'signed_out'|'expired'|'inactive'|'online'
 */
function estab_auth_presence_state(
    array $storedUser,
    ?DateTimeInterface $now = null
): string {
    if (estab_auth_account_is_blocked($storedUser)) {
        return 'blocked';
    }
    $storedSessionId = $storedUser['sid'] ?? null;
    $hasSessionMarker = array_key_exists(
        'estab_sitzung_vorhanden',
        $storedUser
    )
        ? (int) $storedUser['estab_sitzung_vorhanden'] === 1
        : is_string($storedSessionId)
            && estab_auth_session_id_is_valid($storedSessionId);
    if (
        (int) ($storedUser['aktiv'] ?? 0) !== 1
        || !$hasSessionMarker
    ) {
        return 'signed_out';
    }

    $activity = estab_auth_activity_time(
        $storedUser['estab_letzte_aktivitaet'] ?? null
    );
    if ($activity === null) {
        return 'expired';
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $utcNow = DateTimeImmutable::createFromInterface($now)->setTimezone(
        new DateTimeZone('UTC')
    );
    $idleSeconds = $utcNow->getTimestamp() - $activity->getTimestamp();
    if ($idleSeconds < 0 || $idleSeconds >= estab_auth_session_idle_seconds()) {
        return 'expired';
    }
    if ($idleSeconds >= estab_auth_presence_idle_seconds()) {
        return 'inactive';
    }
    return 'online';
}

/** Return whether the row still represents an authenticated session. */
function estab_auth_presence_has_session(
    array $storedUser,
    ?DateTimeInterface $now = null
): bool {
    return in_array(
        estab_auth_presence_state($storedUser, $now),
        ['online', 'inactive'],
        true
    );
}

/** Return whether the account counts as recently active in status views. */
function estab_auth_user_is_online(
    array $storedUser,
    ?DateTimeInterface $now = null
): bool {
    return estab_auth_presence_state($storedUser, $now) === 'online';
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
 * Return the syntactically valid application identity stored by the login flow.
 *
 * This pure shape check is also used for logout snapshots and tests. Protected
 * web requests must go through estab_auth_session_identity(), which additionally
 * binds the PHP session to the authoritative account row.
 */
function estab_auth_session_identity_shape(array $session): ?array
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

/**
 * Return the authenticated application identity.
 *
 * Calls concerning the active web session are checked against the current SID
 * in nv_benutzer. Other arrays remain a pure shape check so validation helpers
 * can safely inspect snapshots and constructed identities.
 */
function estab_auth_session_identity(array $session): ?array
{
    $identity = estab_auth_session_identity_shape($session);
    if ($identity === null) {
        return null;
    }

    if (
        PHP_SAPI !== 'cli'
        && session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION)
        && is_array($_SESSION)
        && $session === $_SESSION
    ) {
        return estab_auth_current_session_identity(
            $_SESSION,
            null,
            null,
            null,
            true
        );
    }

    return $identity;
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
    $sql = 'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`, `fwdip`,'
        . ' `aktiv`, `estab_gesperrt`, `estab_letzte_aktivitaet`, `password`'
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

/** Fetch the minimal authoritative account state needed for one request. */
function estab_auth_fetch_session_user(
    mysqli $connection,
    string $table,
    string $code
): ?array {
    $sql = 'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`,'
        . ' `estab_gesperrt`, `estab_letzte_aktivitaet`'
        . ' FROM ' . estab_auth_table($table) . ' WHERE `kuerzel` = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare session account lookup');
    }
    try {
        $statement->bind_param('s', $code);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute session account lookup');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/** Session IDs are opaque credentials, but still need strict storage bounds. */
function estab_auth_session_id_is_valid(string $sessionId): bool
{
    return $sessionId !== ''
        && strlen($sessionId) <= 50
        && preg_match('/\A[A-Za-z0-9,-]+\z/D', $sessionId) === 1;
}

/**
 * Encode a credential-free login lifecycle record.
 *
 * Session IDs are bearer credentials while their account session is active.
 * The audit therefore stores only the same one-way SHA-256 reference used by
 * logout auditing. The explicit field selection also prevents the password in
 * the validated login array from reaching the ledger.
 */
function estab_auth_login_audit_details(
    string $action,
    array $login,
    string $sessionId,
    string $remoteAddress
): string {
    if (
        !in_array(
            $action,
            ['existing_login', 'session_refresh', 'self_registration'],
            true
        )
        || !estab_auth_session_id_is_valid($sessionId)
    ) {
        throw new InvalidArgumentException('Invalid login audit boundary');
    }
    $identity = estab_auth_session_identity_shape([
        'vStab_benutzer' => $login['benutzer'] ?? null,
        'vStab_kuerzel' => $login['kuerzel'] ?? null,
        'vStab_funktion' => $login['funktion'] ?? null,
        'vStab_rolle' => $login['rolle'] ?? null,
    ]);
    if ($identity === null) {
        throw new InvalidArgumentException('Invalid login audit identity');
    }

    $payload = json_encode(
        [
            'version' => 1,
            'action' => $action,
            'name' => $identity['benutzer'],
            'target' => $identity['kuerzel'],
            'function' => $identity['funktion'],
            'role' => $identity['rolle'],
            'session_reference' => 'sha256:' . hash('sha256', $sessionId),
            'remote_address' => filter_var(
                $remoteAddress,
                FILTER_VALIDATE_IP
            ) === false ? '' : $remoteAddress,
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        throw new RuntimeException('Could not encode login audit');
    }
    return $payload;
}

/** Compare the complete application identity with one authoritative DB row. */
function estab_auth_account_matches_session(
    array $storedUser,
    array $identity,
    string $sessionId,
    ?DateTimeInterface $now = null,
    ?mysqli $connection = null,
    ?int $dutyAssignmentId = null
): bool {
    $storedSessionId = $storedUser['sid'] ?? null;
    if (
        !is_string($storedSessionId)
        || !estab_auth_session_id_is_valid($storedSessionId)
        || !estab_auth_session_id_is_valid($sessionId)
        || !estab_auth_presence_has_session($storedUser, $now)
    ) {
        return false;
    }

    $accountMatches = hash_equals($storedSessionId, $sessionId)
        && hash_equals(
            strtolower(trim((string) ($storedUser['kuerzel'] ?? ''))),
            (string) ($identity['kuerzel'] ?? '')
        )
        && hash_equals(
            (string) ($storedUser['benutzer'] ?? ''),
            (string) ($identity['benutzer'] ?? '')
        );
    if (!$accountMatches) {
        return false;
    }

    if ($dutyAssignmentId === null) {
        return hash_equals(
            (string) ($storedUser['funktion'] ?? ''),
            (string) ($identity['funktion'] ?? '')
        ) && hash_equals(
            (string) ($storedUser['rolle'] ?? ''),
            (string) ($identity['rolle'] ?? '')
        );
    }

    return $connection instanceof mysqli
        && estab_auth_duty_assignment_matches_session(
            $connection,
            $dutyAssignmentId,
            (string) ($identity['kuerzel'] ?? ''),
            (string) ($identity['funktion'] ?? ''),
            (string) ($identity['rolle'] ?? '')
        );
}

/**
 * Validate the exact selected duty assignment used as identity in STRICT.
 *
 * LOOSE deliberately rejects a selected hat: its identity always comes from
 * the fixed account plus explicitly administered additional functions.
 */
function estab_auth_duty_assignment_matches_session(
    mysqli $connection,
    int $assignmentId,
    string $userCode,
    string $function,
    string $role
): bool {
    if (
        $assignmentId < 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $userCode) !== 1
        || preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D', $function) !== 1
        || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
    ) {
        return false;
    }
    $statement = $connection->prepare(
        'SELECT 1 FROM `nv_dienstbesetzungen` AS duty_assignment'
        . ' JOIN `nv_dienstschichten` AS duty_shift'
        . ' ON duty_shift.`dienstschicht_id` ='
        . ' duty_assignment.`dienstschicht_id`'
        . ' JOIN `nv_einsatz_status` AS active_incident'
        . ' ON active_incident.`singleton_id` = 1'
        . ' AND active_incident.`active_einsatz_id` = duty_shift.`einsatz_id`'
        . ' JOIN `nv_einsaetze` AS incident'
        . ' ON incident.`einsatz_id` = duty_shift.`einsatz_id`'
        . ' WHERE duty_assignment.`dienstbesetzung_id` = ?'
        . " AND duty_assignment.`status` = 'ANGENOMMEN'"
        . " AND duty_shift.`status` = 'AKTIV'"
        . " AND incident.`estab_status` = 'open'"
        . " AND BINARY incident.`estab_permission_mode` = BINARY 'STRICT'"
        . ' AND BINARY duty_assignment.`benutzer_kuerzel` = BINARY ?'
        . ' AND BINARY duty_assignment.`funktion` = BINARY ?'
        . ' AND BINARY duty_assignment.`rolle` = BINARY ? LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare duty-session validation');
    }
    try {
        $statement->bind_param(
            'isss',
            $assignmentId,
            $userCode,
            $function,
            $role
        );
        if (!$statement->execute()) {
            throw new RuntimeException('Could not validate duty-session assignment');
        }
        $result = $statement->get_result();
        $valid = $result->fetch_row() !== null;
        $result->free();
        return $valid;
    } finally {
        $statement->close();
    }
}

/** Return the active incident's authoritative mode, or STRICT without one. */
function estab_auth_active_permission_mode(mysqli $connection): string
{
    $result = $connection->query(
        'SELECT incident.`estab_permission_mode`'
        . ' FROM `nv_einsatz_status` AS active_incident'
        . ' LEFT JOIN `nv_einsaetze` AS incident'
        . ' ON incident.`einsatz_id` = active_incident.`active_einsatz_id`'
        . ' WHERE active_incident.`singleton_id` = 1 LIMIT 1'
    );
    if (!$result) {
        throw new RuntimeException('Could not read active permission mode');
    }
    try {
        $row = $result->fetch_assoc();
    } finally {
        $result->free();
    }
    $mode = is_array($row) ? ($row['estab_permission_mode'] ?? null) : null;
    if ($mode === null) {
        return 'STRICT';
    }
    if (!is_string($mode) || !in_array($mode, ['STRICT', 'LOOSE'], true)) {
        throw new RuntimeException('Active permission mode is invalid');
    }
    return $mode;
}

/**
 * Merge the fixed/matrix function roles with the capability catalogue.
 *
 * One function may carry several capabilities, but it must have exactly one
 * role across both sources. Callers that are about to replace the active
 * matrix can lock the capability rows in the same transaction, so a direct
 * concurrent catalogue change cannot invalidate the decision before commit.
 *
 * @param array<string,string> $roles
 * @return array<string,string>
 */
function estab_auth_merge_function_role_catalog(
    mysqli $connection,
    array $roles,
    bool $forUpdate = false
): array {
    $canonicalRoles = [];
    foreach ($roles as $function => $role) {
        if (
            !is_string($function)
            || !is_string($role)
            || preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D', $function)
                !== 1
            || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
            || (
                isset($canonicalRoles[$function])
                && !hash_equals($canonicalRoles[$function], $role)
            )
        ) {
            throw new RuntimeException(
                'Empfängermatrix und Fachfunktionskatalog widersprechen sich.'
            );
        }
        $canonicalRoles[$function] = $role;
    }

    $statement = $connection->prepare(
        'SELECT `funktion`, `rolle` FROM `nv_funktionsfaehigkeiten`'
        . ' ORDER BY `funktion`, `faehigkeit`'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    if (!$statement) {
        throw new RuntimeException(
            'Fachfunktionskatalog konnte nicht vorbereitet werden.'
        );
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Fachfunktionskatalog konnte nicht gelesen werden.'
            );
        }
        $result = $statement->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $function = (string) ($row['funktion'] ?? '');
            $role = (string) ($row['rolle'] ?? '');
            if (
                preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D', $function)
                    !== 1
                || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
                || (
                    isset($canonicalRoles[$function])
                    && !hash_equals($canonicalRoles[$function], $role)
                )
            ) {
                throw new RuntimeException(
                    'Empfängermatrix und Fachfunktionskatalog '
                    . 'widersprechen sich.'
                );
            }
            $canonicalRoles[$function] = $role;
        }
        $result->free();
    } finally {
        $statement->close();
    }
    uksort($canonicalRoles, 'strnatcasecmp');
    return $canonicalRoles;
}

/**
 * Return whether one function owns a legacy Stab/FB message workspace.
 *
 * ETB is a capability-only function. It may operate the shared incident
 * logbook through EINSATZTAGEBUCH, but it never owns a recipient queue,
 * message state or category tables. Si has its separate review workflow.
 */
function estab_auth_has_staff_message_workspace(
    mixed $functionValue,
    mixed $roleValue
): bool {
    return is_string($functionValue)
        && is_string($roleValue)
        && !in_array($functionValue, ['Si', 'ETB'], true)
        && in_array($roleValue, ['Stab', 'FB'], true);
}

/** Return whether a canonical function may be granted personally in LOOSE. */
function estab_auth_extra_function_is_eligible(
    mixed $functionValue,
    mixed $roleValue
): bool {
    return is_string($functionValue)
        && is_string($roleValue)
        && preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D', $functionValue)
            === 1
        && in_array($roleValue, ['Stab', 'FB', 'Fernmelder'], true);
}

/**
 * Read the current active matrix and capability catalogue as one role map.
 *
 * The fixed Si/A/W/LdF roles are part of the same namespace. The active
 * matrix must retain S2 as its single non-automatic red-copy target. This
 * independent runtime check prevents a corrupt database row from turning an
 * otherwise stale personal grant back into authority.
 *
 * @return array<string,string>
 */
function estab_auth_current_function_role_catalog(
    mysqli $connection,
    string $matrixTable = 'nv_empfmtx'
): array {
    $roles = [
        'Si' => 'Stab',
        'A/W' => 'Fernmelder',
        'LdF' => 'Fernmelder',
    ];
    $seenMatrixFunctions = [];
    $lageTargets = 0;
    $statement = $connection->prepare(
        'SELECT `mtx_fkt`, `mtx_rolle`, `mtx_rc2`, `mtx_auto` FROM '
        . estab_auth_table($matrixTable)
        . " WHERE `mtx_typ` = 'cb' AND `mtx_fkt` <> ''"
        . ' ORDER BY `mtx_fkt`, `mtx_rolle`, `mtx_x`, `mtx_y`'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Empfängermatrix konnte nicht für den Funktionskatalog vorbereitet werden.'
        );
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Empfängermatrix konnte nicht für den Funktionskatalog gelesen werden.'
            );
        }
        $result = $statement->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $function = trim((string) ($row['mtx_fkt'] ?? ''));
            $role = trim((string) ($row['mtx_rolle'] ?? ''));
            if (
                preg_match('/\A[A-Za-z0-9_]{1,6}\z/D', $function) !== 1
                || !in_array($role, ['Stab', 'FB'], true)
                || isset($seenMatrixFunctions[$function])
                || isset($roles[$function])
            ) {
                throw new RuntimeException(
                    'Die Empfängermatrix enthält keine eindeutige '
                    . 'Funktionszuordnung.'
                );
            }
            $seenMatrixFunctions[$function] = true;
            $roles[$function] = $role;
            if (
                hash_equals('S2', $function)
                && hash_equals('Stab', $role)
                && in_array((string) ($row['mtx_rc2'] ?? ''), ['1', 't'], true)
                && !in_array((string) ($row['mtx_auto'] ?? ''), ['1', 't'], true)
            ) {
                $lageTargets++;
            }
        }
        $result->free();
    } finally {
        $statement->close();
    }
    if ($lageTargets !== 1) {
        throw new RuntimeException(
            'Die Empfängermatrix benötigt S2 als unveränderliches '
            . 'Lage-/Dokumentationsziel.'
        );
    }
    return estab_auth_merge_function_role_catalog($connection, $roles);
}

/**
 * Decide whether one function/role tuple still belongs to the authoritative
 * runtime catalogue.
 *
 * Primary account functions and explicitly granted additional functions use
 * this exact boundary. A stale tuple is therefore never kept effective merely
 * because it is still present in nv_benutzer.
 */
function estab_auth_function_role_is_current(
    mysqli $connection,
    mixed $functionValue,
    mixed $roleValue
): bool {
    if (!is_string($functionValue) || !is_string($roleValue)) {
        return false;
    }
    $function = trim($functionValue);
    $role = trim($roleValue);
    if (
        preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D', $function) !== 1
        || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
    ) {
        return false;
    }
    $canonicalRoles = estab_auth_current_function_role_catalog($connection);
    return isset($canonicalRoles[$function])
        && hash_equals($canonicalRoles[$function], $role);
}

/**
 * Load only additional functions that still exist in the authoritative maps.
 *
 * The table is administrative state, never session input. A removed matrix
 * function therefore fails closed immediately without deleting its audit
 * evidence; the administration may subsequently remove that stale grant.
 *
 * @return list<array{funktion:string,rolle:string}>
 */
function estab_auth_fetch_additional_functions(
    mysqli $connection,
    string $userCode,
    bool $forUpdate = false
): array {
    $userCode = strtolower(trim($userCode));
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $userCode) !== 1) {
        throw new InvalidArgumentException('Invalid additional-function account');
    }
    $canonicalRoles = estab_auth_current_function_role_catalog($connection);
    $statement = $connection->prepare(
        'SELECT extra.`funktion`, extra.`rolle`'
        . ' FROM `nv_benutzer_zusatzfunktionen` AS extra'
        . ' WHERE BINARY extra.`benutzer_kuerzel` = BINARY ?'
        . ' ORDER BY extra.`funktion`, extra.`rolle`'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare additional functions');
    }
    try {
        $statement->bind_param('s', $userCode);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not read additional functions');
        }
        $result = $statement->get_result();
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $function = (string) ($row['funktion'] ?? '');
            $role = (string) ($row['rolle'] ?? '');
            if (
                preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D', $function)
                    !== 1
                || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
            ) {
                throw new RuntimeException('Stored additional function is invalid');
            }
            if (
                !isset($canonicalRoles[$function])
                || !hash_equals($canonicalRoles[$function], $role)
                || !estab_auth_extra_function_is_eligible($function, $role)
            ) {
                // Keep stale rows as revocable audit evidence, but never turn
                // them into an effective function.
                continue;
            }
            $rows[] = ['funktion' => $function, 'rolle' => $role];
        }
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

/**
 * Return the base/selected function plus server-attached LOOSE additions.
 *
 * @return list<array{funktion:string,rolle:string}>
 */
function estab_auth_effective_function_roles(array $identity): array
{
    $functions = [];
    $seen = [];
    $append = static function (mixed $function, mixed $role) use (
        &$functions,
        &$seen
    ): void {
        if (
            !is_string($function)
            || !is_string($role)
            || preg_match('/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D', $function)
                !== 1
            || !in_array($role, ['Stab', 'FB', 'Fernmelder'], true)
        ) {
            return;
        }
        $key = $function . "\0" . $role;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $functions[] = ['funktion' => $function, 'rolle' => $role];
    };
    $accountFunction = $identity['authorization_account_function']
        ?? $identity['funktion']
        ?? null;
    $accountRole = $identity['authorization_account_role']
        ?? $identity['rolle']
        ?? null;
    $append($accountFunction, $accountRole);
    $additional = ($identity['estab_permission_mode'] ?? null) === 'LOOSE'
        ? ($identity['estab_additional_functions'] ?? [])
        : [];
    if (is_array($additional)) {
        foreach ($additional as $row) {
            if (
                is_array($row)
                && estab_auth_extra_function_is_eligible(
                    $row['funktion'] ?? null,
                    $row['rolle'] ?? null
                )
            ) {
                $append($row['funktion'] ?? null, $row['rolle'] ?? null);
            }
        }
    }
    return $functions;
}

function estab_auth_identity_has_function(
    array $identity,
    string $function,
    string $role
): bool {
    foreach (estab_auth_effective_function_roles($identity) as $candidate) {
        if (
            hash_equals($function, $candidate['funktion'])
            && hash_equals($role, $candidate['rolle'])
        ) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve the optional access-shift gate for one account.
 *
 * Accounts without a current membership remain deliberately unmanaged and
 * may sign in normally. Once an account is assigned to at least one access
 * shift of the active incident, at least one of those shifts must be enabled.
 * This gate never derives the account's function or role.
 *
 * @return array{managed:bool,allowed:bool,memberships:int,active_memberships:int}
 */
function estab_auth_shift_access_state(
    mysqli $connection,
    string $userCode,
    bool $forUpdate = false
): array {
    $userCode = strtolower(trim($userCode));
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $userCode) !== 1) {
        throw new InvalidArgumentException('Invalid access-shift account code');
    }
    $statement = $connection->prepare(
        ($forUpdate
            ? 'SELECT access_shift.`zugangsschicht_id`,'
                . ' access_shift.`zugang_aktiv`,'
                . ' membership.`zugangsschicht_mitglied_id`'
            : 'SELECT COUNT(*) AS `memberships`,'
                . ' COALESCE(SUM(CASE WHEN access_shift.`zugang_aktiv` = 1'
                . ' THEN 1 ELSE 0 END), 0) AS `active_memberships`')
        . ' FROM `nv_einsatz_status` AS active_incident'
        . ' JOIN `nv_zugangsschichten` AS access_shift'
        . ' ON access_shift.`einsatz_id` = active_incident.`active_einsatz_id`'
        . ' JOIN `nv_zugangsschicht_mitglieder` AS membership'
        . ' ON membership.`zugangsschicht_id` ='
        . ' access_shift.`zugangsschicht_id`'
        . ' AND membership.`entfernt_am` IS NULL'
        . ' WHERE active_incident.`singleton_id` = 1'
        . ' AND BINARY membership.`benutzer_kuerzel` = BINARY ?'
        . ($forUpdate
            ? ' ORDER BY access_shift.`zugangsschicht_id`,'
                . ' membership.`zugangsschicht_mitglied_id` FOR UPDATE'
            : '')
    );
    if (!$statement) {
        throw new RuntimeException('Could not prepare access-shift check');
    }
    try {
        $statement->bind_param('s', $userCode);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not execute access-shift check');
        }
        $result = $statement->get_result();
        if ($forUpdate) {
            $memberships = 0;
            $activeMemberships = 0;
            while (($row = $result->fetch_assoc()) !== null) {
                $memberships++;
                if ((int) ($row['zugang_aktiv'] ?? 0) === 1) {
                    $activeMemberships++;
                }
            }
        } else {
            $row = $result->fetch_assoc();
            $memberships = (int) ($row['memberships'] ?? 0);
            $activeMemberships = (int) ($row['active_memberships'] ?? 0);
        }
        $result->free();
    } finally {
        $statement->close();
    }
    return [
        'managed' => $memberships > 0,
        'allowed' => $memberships === 0 || $activeMemberships > 0,
        'memberships' => $memberships,
        'active_memberships' => $activeMemberships,
    ];
}

function estab_auth_shift_access_allowed(
    mysqli $connection,
    string $userCode,
    bool $forUpdate = false
): bool {
    return estab_auth_shift_access_state(
        $connection,
        $userCode,
        $forUpdate
    )['allowed'];
}

/** Resolve the same session store used by 4fcfg/dbcfg.inc.php. */
function estab_auth_runtime_session_store(): array
{
    return [
        'database' => [
            'server' => (string) estab_env('ESTAB_DB_HOST', 'db'),
            'user' => (string) estab_env('ESTAB_DB_USER', 'estab'),
            'password' => (string) estab_env('ESTAB_DB_PASSWORD', ''),
            'datenbank' => estab_env_identifier('ESTAB_DB_NAME', 'estab'),
        ],
        'table' => 'nv_benutzer',
    ];
}

/** Remove all local workflow state after revocation or a validation failure. */
function estab_auth_invalidate_local_session(array &$session): void
{
    $session = ['menue' => 'LOGIN'];
}

/**
 * Bind one PHP session to the current active account row.
 *
 * A failed connection, missing row, changed identity, inactive account or
 * superseded SID all invalidate the complete local workflow state. Successful
 * checks may be cached only for the duration of the current web request.
 */
function estab_auth_current_session_identity(
    array &$session,
    ?array $databaseConfig = null,
    ?string $userTable = null,
    ?string $sessionId = null,
    bool $useRequestCache = false
): ?array {
    static $requestCache = [];

    $identity = estab_auth_session_identity_shape($session);
    if ($identity === null) {
        return null;
    }

    if ($sessionId === null) {
        if (
            session_status() !== PHP_SESSION_ACTIVE
            || !isset($_SESSION)
            || !is_array($_SESSION)
            || $session !== $_SESSION
        ) {
            estab_auth_invalidate_local_session($session);
            return null;
        }
        $sessionId = session_id();
    }
    if (!estab_auth_session_id_is_valid($sessionId)) {
        estab_auth_invalidate_local_session($session);
        return null;
    }

    try {
        if ($databaseConfig === null || $userTable === null) {
            $store = estab_auth_runtime_session_store();
            $databaseConfig ??= $store['database'];
            $userTable ??= $store['table'];
        }
        if (!is_array($databaseConfig) || !is_string($userTable)) {
            throw new RuntimeException('Authentication session store is invalid');
        }
        estab_auth_table($userTable);

        $dutyAssignmentValue = $session['estab_duty_assignment_id'] ?? null;
        $dutyAssignmentId = null;
        if (is_int($dutyAssignmentValue) && $dutyAssignmentValue > 0) {
            $dutyAssignmentId = $dutyAssignmentValue;
        } elseif (
            is_string($dutyAssignmentValue)
            && preg_match('/\A[1-9][0-9]{0,18}\z/D', $dutyAssignmentValue)
                === 1
        ) {
            $parsedDutyAssignment = filter_var(
                $dutyAssignmentValue,
                FILTER_VALIDATE_INT
            );
            if (
                !is_int($parsedDutyAssignment)
                || $parsedDutyAssignment < 1
            ) {
                throw new RuntimeException(
                    'Authentication duty assignment is invalid'
                );
            }
            $dutyAssignmentId = $parsedDutyAssignment;
        } elseif ($dutyAssignmentValue !== null) {
            throw new RuntimeException(
                'Authentication duty assignment is invalid'
            );
        }
        $authenticatedIdentity = $identity;
        if ($dutyAssignmentId !== null) {
            $authenticatedIdentity['duty_assignment_id'] = $dutyAssignmentId;
        }

        $cacheKey = hash('sha256', json_encode([
            'server' => (string) ($databaseConfig['server'] ?? ''),
            'user' => (string) ($databaseConfig['user'] ?? ''),
            'password' => hash(
                'sha256',
                (string) ($databaseConfig['password'] ?? '')
            ),
            'database' => (string) ($databaseConfig['datenbank'] ?? ''),
            'table' => $userTable,
            'sid' => $sessionId,
            'identity' => $identity,
            'duty_assignment_id' => $dutyAssignmentId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        if ($useRequestCache && array_key_exists($cacheKey, $requestCache)) {
            if (is_array($requestCache[$cacheKey])) {
                $cachedIdentity = $requestCache[$cacheKey];
                $session['ROLLE'] = (string) $cachedIdentity['rolle'];
                return $cachedIdentity;
            }
            estab_auth_invalidate_local_session($session);
            return null;
        }

        $connection = null;
        $valid = false;
        try {
            $connection = estab_auth_connect($databaseConfig);
            $storedUser = estab_auth_fetch_session_user(
                $connection,
                $userTable,
                $identity['kuerzel']
            );
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $mode = estab_auth_active_permission_mode($connection);
            $authenticatedIdentity['estab_permission_mode'] = $mode;
            $valid = !($mode === 'LOOSE' && $dutyAssignmentId !== null)
                && is_array($storedUser)
                && estab_auth_account_matches_session(
                    $storedUser,
                    $identity,
                    $sessionId,
                    $now,
                    $connection,
                    $dutyAssignmentId
                )
                && (
                    $mode === 'STRICT'
                    || estab_auth_function_role_is_current(
                        $connection,
                        $identity['funktion'] ?? null,
                        $identity['rolle'] ?? null
                    )
                )
                && (
                    $mode !== 'LOOSE'
                    || estab_auth_shift_access_allowed(
                        $connection,
                        (string) $identity['kuerzel']
                    )
                );
            if ($valid && $mode === 'LOOSE') {
                $authenticatedIdentity['estab_additional_functions'] =
                    estab_auth_fetch_additional_functions(
                        $connection,
                        (string) $identity['kuerzel']
                    );
            }
            if (!$valid && is_array($storedUser)) {
                // The exact SID condition prevents an expired or superseded
                // browser from clearing a newer login of the same account.
                estab_auth_mark_logged_out(
                    $connection,
                    $userTable,
                    $identity['kuerzel'],
                    $sessionId
                );
            }
        } finally {
            if ($connection instanceof mysqli) {
                estab_auth_close($connection);
            }
        }
        if ($useRequestCache) {
            $requestCache[$cacheKey] = $valid
                ? $authenticatedIdentity
                : false;
        }
        if (!$valid) {
            estab_auth_invalidate_local_session($session);
            return null;
        }

        // Legacy routes still consult this duplicate role field.
        $session['ROLLE'] = $authenticatedIdentity['rolle'];
        return $authenticatedIdentity;
    } catch (Throwable $exception) {
        error_log(
            'eStab session validation failed: ' . $exception->getMessage()
        );
        estab_auth_invalidate_local_session($session);
        return null;
    }
}

/**
 * Activate an existing account without changing its assigned function.
 *
 * The role may be refreshed from the server-controlled function map. Binding
 * the stored function in the WHERE clause makes the assignment invariant hold
 * even if a caller reaches this function without the normal advisory lock.
 */
function estab_auth_update_user(
    mysqli $connection,
    string $table,
    array $user
): void {
    $sql = 'UPDATE ' . estab_auth_table($table)
        . ' SET `rolle` = ?, `sid` = ?, `ip` = ?, `fwdip` = ?,'
        . ' `aktiv` = 1, `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6),'
        . ' `password` = ?'
        . ' WHERE `kuerzel` = ? AND `funktion` = ?'
        . ' AND `estab_gesperrt` = 0';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare account update');
    }
    try {
        $statement->bind_param(
            'sssssss',
            $user['rolle'],
            $user['sid'],
            $user['ip'],
            $user['fwdip'],
            $user['password'],
            $user['kuerzel'],
            $user['funktion']
        );
        if (!$statement->execute() || $statement->affected_rows !== 1) {
            throw new RuntimeException(
                'Could not activate account with its assigned function'
            );
        }
    } finally {
        $statement->close();
    }
}

/** Insert a self-registered account with an already hashed password. */
function estab_auth_insert_user(mysqli $connection, string $table, array $user): void
{
    $sql = 'INSERT INTO ' . estab_auth_table($table)
        . ' (`benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `ip`, `fwdip`,'
        . ' `password`, `aktiv`, `estab_letzte_aktivitaet`)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6))';
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

/** Revoke every legacy/stale SID whose 12-hour idle window has elapsed. */
function estab_auth_expire_stale_sessions(
    mysqli $connection,
    string $table
): int {
    $sql = 'UPDATE ' . estab_auth_table($table)
        . " SET `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = ''"
        . ' WHERE `aktiv` = 1 AND ('
        . "`sid` = '' OR `estab_letzte_aktivitaet` IS NULL"
        . ' OR `estab_letzte_aktivitaet` > UTC_TIMESTAMP(6)'
        . ' OR `estab_letzte_aktivitaet` <= UTC_TIMESTAMP(6)'
        . ' - INTERVAL 43200 SECOND)';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare stale-session cleanup');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Could not expire stale sessions');
        }
        return $statement->affected_rows;
    } finally {
        $statement->close();
    }
}

/** Record one genuine browser interaction for the exact current SID. */
function estab_auth_touch_activity(
    mysqli $connection,
    string $table,
    string $code,
    string $sessionId
): bool {
    if (
        preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1
        || !estab_auth_session_id_is_valid($sessionId)
    ) {
        return false;
    }
    $sql = 'UPDATE ' . estab_auth_table($table)
        . ' SET `estab_letzte_aktivitaet` = UTC_TIMESTAMP(6)'
        . ' WHERE `kuerzel` = ? AND `sid` = ? AND `aktiv` = 1'
        . ' AND `estab_gesperrt` = 0'
        . ' AND `estab_letzte_aktivitaet` IS NOT NULL'
        . ' AND `estab_letzte_aktivitaet` <= UTC_TIMESTAMP(6)'
        . ' AND `estab_letzte_aktivitaet` > UTC_TIMESTAMP(6)'
        . ' - INTERVAL 43200 SECOND';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare activity update');
    }
    try {
        $statement->bind_param('ss', $code, $sessionId);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not update session activity');
        }
        return $statement->affected_rows === 1;
    } finally {
        $statement->close();
    }
}

/** Fetch users for status views through one canonical presence boundary. */
function estab_auth_fetch_users(mysqli $connection, string $table): array
{
    estab_auth_expire_stale_sessions($connection, $table);
    $sql = 'SELECT `benutzer`, `kuerzel`, `funktion`, `rolle`, `sid`, `aktiv`,'
        . ' `estab_gesperrt`, `estab_letzte_aktivitaet`'
        . ' FROM ' . estab_auth_table($table)
        . ' ORDER BY `estab_gesperrt`, `aktiv` DESC, `kuerzel`';
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

/**
 * Mark only the matching account session logged out.
 *
 * Binding the stored session ID prevents an older browser session from
 * deactivating a newer login of the same account.
 */
function estab_auth_mark_logged_out(
    mysqli $connection,
    string $table,
    string $code,
    string $sessionId
): bool
{
    $sql = 'UPDATE ' . estab_auth_table($table)
        . " SET `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = ''"
        . ' WHERE `kuerzel` = ? AND `sid` = ?';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare logout update');
    }
    try {
        $statement->bind_param('ss', $code, $sessionId);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not update logout state');
        }
        return $statement->affected_rows === 1;
    } finally {
        $statement->close();
    }
}

/** Append an authentication lifecycle event through a prepared statement. */
function estab_auth_log_event(
    mysqli $connection,
    string $table,
    string $event,
    string $detail
): void {
    $sql = 'INSERT INTO ' . estab_auth_table($table)
        . ' (`p_zeit`, `p_was`, `p_ereignis`) VALUES (NOW(), ?, ?)';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Could not prepare authentication audit event');
    }
    try {
        $statement->bind_param('ss', $event, $detail);
        if (!$statement->execute()) {
            throw new RuntimeException('Could not write authentication audit event');
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
