<?php

declare(strict_types=1);

/**
 * Secure category storage shared by the message form and category editor.
 *
 * Category and link table names are necessarily dynamic for function and user
 * scopes. They are derived exclusively from an authenticated session identity
 * and configured prefixes, then passed through estab_auth_table(). All data
 * values are bound parameters and all mutations are transactional.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/message_repository.php';

const ESTAB_CATEGORY_TYPES = ['master', 'fkt', 'user'];
const ESTAB_CATEGORY_NAME_MAX = 10;
const ESTAB_CATEGORY_DESCRIPTION_MAX = 254;

final class EstabCategoryInputException extends InvalidArgumentException
{
}

final class EstabCategoryAuthorizationException extends RuntimeException
{
}

final class EstabCategoryNotFoundException extends RuntimeException
{
}

final class EstabCategoryConflictException extends RuntimeException
{
}

/** Accept only one of the three application-owned category scopes. */
function estab_category_validate_type(mixed $value): string
{
    if (!is_string($value) || !in_array($value, ESTAB_CATEGORY_TYPES, true)) {
        throw new EstabCategoryInputException('Ungültiger Kategorienbereich.');
    }
    return $value;
}

/** Parse a canonical positive decimal identifier without implicit coercion. */
function estab_category_positive_id(mixed $value, string $field = 'ID'): int
{
    if (
        !is_string($value)
        && !is_int($value)
    ) {
        throw new EstabCategoryInputException($field . ' ist ungültig.');
    }

    $candidate = (string) $value;
    if (preg_match('/\A[1-9][0-9]*\z/D', $candidate) !== 1) {
        throw new EstabCategoryInputException($field . ' ist ungültig.');
    }

    $number = filter_var($candidate, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
    ]);
    if (!is_int($number)) {
        throw new EstabCategoryInputException($field . ' ist ungültig.');
    }
    return $number;
}

/** Parse an optional category selection; an empty value removes the link. */
function estab_category_optional_id(mixed $value, string $field = 'Kategorie'): ?int
{
    if ($value === '' || $value === null) {
        return null;
    }
    return estab_category_positive_id($value, $field);
}

/**
 * Validate raw UTF-8 category data.
 *
 * HTML entities are deliberately not stored. Escaping belongs solely at the
 * output boundary, which keeps quotes, ampersands and international text
 * round-trippable.
 */
function estab_category_validate_payload(array $input): array
{
    $name = $input['kategorie'] ?? null;
    $description = $input['beschreibung'] ?? '';
    if (!is_string($name) || !is_string($description)) {
        throw new EstabCategoryInputException('Kategorie und Beschreibung müssen Text sein.');
    }
    if (
        preg_match('//u', $name) !== 1
        || preg_match('//u', $description) !== 1
        || str_contains($name, "\0")
        || str_contains($description, "\0")
    ) {
        throw new EstabCategoryInputException('Kategorie enthält ungültiges UTF-8.');
    }

    $nameLength = estab_auth_text_length($name);
    $descriptionLength = estab_auth_text_length($description);
    if (
        trim($name) === ''
        || $nameLength < 1
        || $nameLength > ESTAB_CATEGORY_NAME_MAX
        || preg_match('/[\p{C}]/u', $name) === 1
    ) {
        throw new EstabCategoryInputException('Kategorie muss 1 bis 10 sichtbare Zeichen enthalten.');
    }
    if (
        $descriptionLength < 0
        || $descriptionLength > ESTAB_CATEGORY_DESCRIPTION_MAX
        || preg_match('/[\p{Cc}]/u', $description) === 1
    ) {
        throw new EstabCategoryInputException('Beschreibung ist zu lang oder enthält Steuerzeichen.');
    }

    return [
        'kategorie' => $name,
        'beschreibung' => $description,
    ];
}

/**
 * Derive the only tables a category request may reach.
 *
 * fkt and user scopes can therefore never be redirected through request data
 * into another participant's tables.
 */
function estab_category_scope(
    string $type,
    array $identity,
    array $tableConfig
): array {
    $type = estab_category_validate_type($type);
    if (estab_auth_session_identity([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]) === null) {
        throw new EstabCategoryAuthorizationException('Ungültige Sitzung.');
    }

    if ($type === 'master') {
        $categoryTable = (string) ($tableConfig['masterkatego'] ?? '');
        $linkTable = (string) ($tableConfig['masterkategolk'] ?? '');
    } else {
        $prefix = (string) ($tableConfig['usrtblprefix'] ?? '');
        $function = strtolower((string) $identity['funktion']);
        $code = strtolower((string) $identity['kuerzel']);
        if (
            preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $prefix) !== 1
            || preg_match('/\A[a-z0-9_]{1,10}\z/D', $function) !== 1
            || preg_match('/\A[a-z0-9_]{1,6}\z/D', $code) !== 1
        ) {
            throw new EstabCategoryAuthorizationException(
                'Für diese Funktion ist kein Kategorienraum verfügbar.'
            );
        }
        $base = $type === 'fkt'
            ? $prefix . '_fkt_' . $function
            : $prefix . $function . '_' . $code;
        $categoryTable = $base . '_katego';
        $linkTable = $base . '_kategolink';
    }

    // Validate now, and repeat at every SQL interpolation boundary.
    estab_auth_table($categoryTable);
    estab_auth_table($linkTable);
    return [
        'type' => $type,
        'category_table' => $categoryTable,
        'link_table' => $linkTable,
    ];
}

/** Read the currently configured red-copy function. */
function estab_category_redcopy_function(
    mysqli $connection,
    string $matrixTable,
    bool $forUpdate = false
): ?string {
    $sql = 'SELECT `mtx_fkt`, `mtx_rc2` FROM '
        . estab_auth_table($matrixTable)
        . ' ORDER BY `mtx_lfd`'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Redcopy-Abfrage konnte nicht vorbereitet werden.');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Redcopy-Abfrage ist fehlgeschlagen.');
        }
        $result = $statement->get_result();
        try {
            while ($row = $result->fetch_assoc()) {
                if (!in_array((string) ($row['mtx_rc2'] ?? ''), ['t', '1'], true)) {
                    continue;
                }
                if (!is_string($row['mtx_fkt'] ?? null)) {
                    return null;
                }
                $function = trim($row['mtx_fkt']);
                return $function === '' ? null : $function;
            }
            return null;
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

function estab_category_can_manage_master(array $identity, ?string $redcopy): bool
{
    $function = (string) ($identity['funktion'] ?? '');
    return $function === 'Si' || ($redcopy !== null && hash_equals($redcopy, $function));
}

/** Enforce management rights for the requested scope. */
function estab_category_require_management(
    string $type,
    array $identity,
    ?string $redcopy
): void {
    $type = estab_category_validate_type($type);
    if ($type === 'master' && !estab_category_can_manage_master($identity, $redcopy)) {
        throw new EstabCategoryAuthorizationException(
            'Master-Kategorien dürfen nur Redcopy oder Si verwalten.'
        );
    }
}

/** Reset a now-stale list filter after deleting a category in that scope. */
function estab_category_clear_session_filter(array &$session, string $type): void
{
    $prefix = match (estab_category_validate_type($type)) {
        'master' => 'ma',
        'fkt' => 'fk',
        'user' => 'us',
    };
    unset($session[$prefix . '_katego'], $session[$prefix . '_kategotyp']);
}

/** List categories in display order. */
function estab_category_fetch_all(mysqli $connection, array $scope): array
{
    $sql = 'SELECT `lfd`, `kategorie`, `beschreibung` FROM '
        . estab_auth_table((string) $scope['category_table'])
        . ' ORDER BY `kategorie`, `lfd`';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Kategorienliste konnte nicht vorbereitet werden.');
    }
    try {
        if (!$statement->execute()) {
            throw new RuntimeException('Kategorienliste konnte nicht gelesen werden.');
        }
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

/** Fetch one category by its primary key. */
function estab_category_fetch_one(
    mysqli $connection,
    array $scope,
    int $categoryId
): ?array {
    if ($categoryId < 1) {
        throw new EstabCategoryInputException('Kategorie-ID ist ungültig.');
    }
    $sql = 'SELECT `lfd`, `kategorie`, `beschreibung` FROM '
        . estab_auth_table((string) $scope['category_table'])
        . ' WHERE `lfd` = ? LIMIT 1';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Kategorieabfrage konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $categoryId);
        if (!$statement->execute()) {
            throw new RuntimeException('Kategorieabfrage ist fehlgeschlagen.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/** Fetch the category currently assigned to a message. */
function estab_category_fetch_for_message(
    mysqli $connection,
    array $scope,
    int $messageId
): ?array {
    if ($messageId < 1) {
        throw new EstabCategoryInputException('Meldungs-ID ist ungültig.');
    }
    $categoryTable = estab_auth_table((string) $scope['category_table']);
    $linkTable = estab_auth_table((string) $scope['link_table']);
    $sql = 'SELECT c.`lfd`, c.`kategorie`, c.`beschreibung`'
        . ' FROM ' . $linkTable . ' AS l'
        . ' INNER JOIN ' . $categoryTable . ' AS c ON c.`lfd` = l.`katego`'
        . ' WHERE l.`msg` = ? ORDER BY l.`lfd` LIMIT 1';
    if (($scope['type'] ?? '') === 'master') {
        // The master link table intentionally has no lfd column.
        $sql = 'SELECT c.`lfd`, c.`kategorie`, c.`beschreibung`'
            . ' FROM ' . $linkTable . ' AS l'
            . ' INNER JOIN ' . $categoryTable . ' AS c ON c.`lfd` = l.`katego`'
            . ' WHERE l.`msg` = ? LIMIT 1';
    }
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new RuntimeException('Kategoriezuordnung konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $messageId);
        if (!$statement->execute()) {
            throw new RuntimeException('Kategoriezuordnung konnte nicht gelesen werden.');
        }
        $result = $statement->get_result();
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    } finally {
        $statement->close();
    }
}

/** Read the selected category ID, or null if the message has no link. */
function estab_category_fetch_assignment_id(
    mysqli $connection,
    array $scope,
    int $messageId
): ?int {
    $row = estab_category_fetch_for_message($connection, $scope, $messageId);
    return $row === null ? null : (int) $row['lfd'];
}

/** Insert a raw UTF-8 category and return its generated ID. */
function estab_category_create(
    mysqli $connection,
    array $scope,
    array $payload
): int {
    $data = estab_category_validate_payload($payload);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Kategorietransaktion konnte nicht gestartet werden.');
    }
    try {
        $sql = 'INSERT INTO ' . estab_auth_table((string) $scope['category_table'])
            . ' (`kategorie`, `beschreibung`) VALUES (?, ?)';
        $statement = $connection->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Kategorie konnte nicht vorbereitet werden.');
        }
        try {
            $statement->bind_param('ss', $data['kategorie'], $data['beschreibung']);
            if (!$statement->execute()) {
                if ($statement->errno === 1062) {
                    throw new EstabCategoryConflictException('Kategorie ist bereits vorhanden.');
                }
                throw new RuntimeException('Kategorie konnte nicht angelegt werden.');
            }
            $categoryId = (int) $connection->insert_id;
        } finally {
            $statement->close();
        }
        if ($categoryId < 1) {
            throw new RuntimeException('Kategorie-ID konnte nicht ermittelt werden.');
        }
        if (!$connection->commit()) {
            throw new RuntimeException('Kategorietransaktion konnte nicht abgeschlossen werden.');
        }
        return $categoryId;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** Update one category while serialising against delete/update races. */
function estab_category_update(
    mysqli $connection,
    array $scope,
    int $categoryId,
    array $payload
): void {
    if ($categoryId < 1) {
        throw new EstabCategoryInputException('Kategorie-ID ist ungültig.');
    }
    $data = estab_category_validate_payload($payload);
    $table = estab_auth_table((string) $scope['category_table']);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Kategorietransaktion konnte nicht gestartet werden.');
    }
    try {
        estab_category_lock_existing($connection, $table, $categoryId);
        $statement = $connection->prepare(
            'UPDATE ' . $table . ' SET `kategorie` = ?, `beschreibung` = ? WHERE `lfd` = ?'
        );
        if (!$statement) {
            throw new RuntimeException('Kategorieänderung konnte nicht vorbereitet werden.');
        }
        try {
            $statement->bind_param('ssi', $data['kategorie'], $data['beschreibung'], $categoryId);
            if (!$statement->execute()) {
                if ($statement->errno === 1062) {
                    throw new EstabCategoryConflictException('Kategorie ist bereits vorhanden.');
                }
                throw new RuntimeException('Kategorie konnte nicht geändert werden.');
            }
        } finally {
            $statement->close();
        }
        if (!$connection->commit()) {
            throw new RuntimeException('Kategorietransaktion konnte nicht abgeschlossen werden.');
        }
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** Delete a category and all links to it as one atomic operation. */
function estab_category_delete(
    mysqli $connection,
    array $scope,
    int $categoryId
): void {
    if ($categoryId < 1) {
        throw new EstabCategoryInputException('Kategorie-ID ist ungültig.');
    }
    $categoryTable = estab_auth_table((string) $scope['category_table']);
    $linkTable = estab_auth_table((string) $scope['link_table']);
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('Kategorietransaktion konnte nicht gestartet werden.');
    }
    try {
        estab_category_lock_existing($connection, $categoryTable, $categoryId);
        $linkStatement = $connection->prepare('DELETE FROM ' . $linkTable . ' WHERE `katego` = ?');
        if (!$linkStatement) {
            throw new RuntimeException('Kategorieverknüpfungen konnten nicht vorbereitet werden.');
        }
        try {
            $linkStatement->bind_param('i', $categoryId);
            if (!$linkStatement->execute()) {
                throw new RuntimeException('Kategorieverknüpfungen konnten nicht gelöscht werden.');
            }
        } finally {
            $linkStatement->close();
        }

        $categoryStatement = $connection->prepare(
            'DELETE FROM ' . $categoryTable . ' WHERE `lfd` = ? LIMIT 1'
        );
        if (!$categoryStatement) {
            throw new RuntimeException('Kategorielöschung konnte nicht vorbereitet werden.');
        }
        try {
            $categoryStatement->bind_param('i', $categoryId);
            if (!$categoryStatement->execute() || $categoryStatement->affected_rows !== 1) {
                throw new RuntimeException('Kategorie konnte nicht gelöscht werden.');
            }
        } finally {
            $categoryStatement->close();
        }
        if (!$connection->commit()) {
            throw new RuntimeException('Kategorietransaktion konnte nicht abgeschlossen werden.');
        }
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

/** Select and lock one category, failing closed if it no longer exists. */
function estab_category_lock_existing(
    mysqli $connection,
    string $quotedCategoryTable,
    int $categoryId
): void {
    $statement = $connection->prepare(
        'SELECT `lfd` FROM ' . $quotedCategoryTable . ' WHERE `lfd` = ? FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException('Kategoriesperre konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('i', $categoryId);
        if (!$statement->execute()) {
            throw new RuntimeException('Kategorie konnte nicht gesperrt werden.');
        }
        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;
        $result->free();
        if (!$exists) {
            throw new EstabCategoryNotFoundException('Kategorie wurde nicht gefunden.');
        }
    } finally {
        $statement->close();
    }
}

/** Lock the target message so assignments cannot race with message deletion. */
function estab_category_lock_message(
    mysqli $connection,
    string $messageTable,
    int $messageId,
    int $incidentId
): array {
    $statement = $connection->prepare(
        'SELECT * FROM ' . estab_auth_table($messageTable)
        . ' WHERE `00_lfd` = ? AND `einsatz_id` = ? FOR UPDATE'
    );
    if (!$statement) {
        throw new RuntimeException('Meldungssperre konnte nicht vorbereitet werden.');
    }
    try {
        $statement->bind_param('ii', $messageId, $incidentId);
        if (!$statement->execute()) {
            throw new RuntimeException('Meldung konnte nicht gesperrt werden.');
        }
        $result = $statement->get_result();
        $message = $result->fetch_assoc();
        $result->free();
        if (!is_array($message)) {
            throw new EstabCategoryNotFoundException('Meldung wurde nicht gefunden.');
        }
        return $message;
    } finally {
        $statement->close();
    }
}

/**
 * Replace one or more message/category links atomically.
 *
 * $assignments maps a category type to an integer ID or null. Every selected
 * ID is verified in its own session-derived table before any link is changed.
 */
function estab_category_assign(
    mysqli $connection,
    int $messageId,
    string $messageTable,
    array $identity,
    array $scopes,
    array $assignments,
    string $matrixTable = 'nv_empfmtx'
): void {
    if ($messageId < 1 || $assignments === []) {
        throw new EstabCategoryInputException('Kategoriezuordnung ist unvollständig.');
    }
    foreach ($assignments as $type => $categoryId) {
        estab_category_validate_type($type);
        if (!isset($scopes[$type]) || !is_array($scopes[$type])) {
            throw new EstabCategoryInputException('Kategorienbereich fehlt.');
        }
        if ($categoryId !== null && (!is_int($categoryId) || $categoryId < 1)) {
            throw new EstabCategoryInputException('Kategorie-ID ist ungültig.');
        }
    }

    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $messageId,
            $messageTable,
            $identity,
            $scopes,
            $assignments,
            $matrixTable
        ): void {
            $incidentId = (int) $incident['active_einsatz_id'];
            estab_dv_require_operational_account(
                $connection,
                $incidentId,
                $identity
            );
            if (array_key_exists('master', $assignments)) {
                $lockedRedcopy = estab_category_redcopy_function(
                    $connection,
                    $matrixTable,
                    true
                );
                estab_category_require_management(
                    'master',
                    $identity,
                    $lockedRedcopy
                );
            }
            $message = estab_category_lock_message(
                $connection,
                $messageTable,
                $messageId,
                $incidentId
            );
            if (!estab_message_object_allowed($identity, 'staff-read', $message)) {
                throw new EstabCategoryAuthorizationException(
                    'Keine Berechtigung für diese Meldung.'
                );
            }
            foreach ($assignments as $type => $categoryId) {
                $scope = $scopes[$type];
                if ($categoryId !== null) {
                    estab_category_lock_existing(
                        $connection,
                        estab_auth_table((string) $scope['category_table']),
                        $categoryId
                    );
                }

                $linkTable = estab_auth_table((string) $scope['link_table']);
                $delete = $connection->prepare(
                    'DELETE FROM ' . $linkTable . ' WHERE `msg` = ?'
                );
                if (!$delete) {
                    throw new RuntimeException(
                        'Alte Kategoriezuordnung konnte nicht vorbereitet werden.'
                    );
                }
                try {
                    $delete->bind_param('i', $messageId);
                    if (!$delete->execute()) {
                        throw new RuntimeException(
                            'Alte Kategoriezuordnung konnte nicht entfernt werden.'
                        );
                    }
                } finally {
                    $delete->close();
                }

                if ($categoryId !== null) {
                    $insert = $connection->prepare(
                        'INSERT INTO ' . $linkTable
                        . ' (`msg`, `katego`) VALUES (?, ?)'
                    );
                    if (!$insert) {
                        throw new RuntimeException(
                            'Kategoriezuordnung konnte nicht vorbereitet werden.'
                        );
                    }
                    try {
                        $insert->bind_param('ii', $messageId, $categoryId);
                        if (!$insert->execute()) {
                            throw new RuntimeException(
                                'Kategoriezuordnung konnte nicht gespeichert werden.'
                            );
                        }
                    } finally {
                        $insert->close();
                    }
                }
            }
        }
    );
}

/**
 * Resolve the duplicated top/bottom pulldowns against the authoritative DB
 * selection. Exactly one changed control is accepted; contradictory edits fail.
 */
function estab_category_resolve_selection(
    array $post,
    string $type,
    ?int $currentId
): array {
    $type = estab_category_validate_type($type);
    $topKey = 'category_' . $type . '_oben';
    $bottomKey = 'category_' . $type . '_unten';
    $hasTop = array_key_exists($topKey, $post);
    $hasBottom = array_key_exists($bottomKey, $post);
    if (!$hasTop && !$hasBottom) {
        return ['present' => false, 'value' => $currentId];
    }

    $top = $hasTop ? estab_category_optional_id($post[$topKey], $topKey) : $currentId;
    $bottom = $hasBottom ? estab_category_optional_id($post[$bottomKey], $bottomKey) : $currentId;
    if ($top === $bottom) {
        return ['present' => true, 'value' => $top];
    }
    if ($top !== $currentId && $bottom === $currentId) {
        return ['present' => true, 'value' => $top];
    }
    if ($bottom !== $currentId && $top === $currentId) {
        return ['present' => true, 'value' => $bottom];
    }
    throw new EstabCategoryConflictException(
        'Obere und untere Kategorienauswahl widersprechen sich.'
    );
}
