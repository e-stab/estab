<?php

declare(strict_types=1);

/**
 * Object-level read boundary for messages, generated forms and attachments.
 *
 * Authentication alone deliberately grants no operational object access.
 * Every read is bound to the active incident and to the exact, personally
 * accepted duty assignment selected in the PHP session. Message visibility is
 * then derived from the same workflow fields that carry the signed processing
 * marks; attachment names are compared as complete canonical tokens.
 */

require_once __DIR__ . '/attachment.php';
require_once __DIR__ . '/file_access.php';
require_once __DIR__ . '/message_repository.php';

final class EstabReadPermissionException extends RuntimeException
{
}

/** Return a positive selected duty-assignment id, or null when none exists. */
function estab_read_duty_assignment_id(mixed $value): ?int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (
        !is_string($value)
        || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1
    ) {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return is_int($parsed) && $parsed > 0 ? $parsed : null;
}

/**
 * Return the authenticated identity plus its server-side selected duty hat.
 *
 * estab_auth_session_identity() has already checked that a present assignment
 * belongs to this account and is still accepted in the active shift.
 */
function estab_read_session_identity(array $session): ?array
{
    $identity = estab_auth_session_identity($session);
    if (!is_array($identity)) {
        return null;
    }
    $assignmentId = estab_read_duty_assignment_id(
        $session['estab_duty_assignment_id'] ?? null
    );
    if ($assignmentId !== null) {
        $identity['duty_assignment_id'] = $assignmentId;
    }
    return $identity;
}

/** Validate the exact accepted hat again inside the object-read transaction. */
function estab_read_require_selected_hat(
    mysqli $connection,
    int $incidentId,
    array $identity
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $assignmentId = estab_read_duty_assignment_id(
        $identity['duty_assignment_id'] ?? null
    );
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if ($assignmentId === null || $shape === null) {
        throw new EstabReadPermissionException(
            'Wählen Sie zuerst eine persönlich angenommene Dienstfunktion.'
        );
    }

    $statement = $connection->prepare(
        'SELECT 1 FROM `nv_dienstbesetzungen` AS assignment'
        . ' JOIN `nv_dienstschichten` AS duty_shift'
        . ' ON duty_shift.`dienstschicht_id`'
        . ' = assignment.`dienstschicht_id`'
        . ' JOIN `nv_einsaetze` AS incident'
        . ' ON incident.`einsatz_id` = duty_shift.`einsatz_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel`'
        . ' = BINARY assignment.`benutzer_kuerzel`'
        . ' WHERE assignment.`dienstbesetzung_id` = ?'
        . ' AND duty_shift.`einsatz_id` = ?'
        . " AND duty_shift.`status` = 'AKTIV'"
        . " AND assignment.`status` = 'ANGENOMMEN'"
        . " AND incident.`estab_status` = 'open'"
        . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        . ' AND BINARY assignment.`funktion` = BINARY ?'
        . ' AND BINARY assignment.`rolle` = BINARY ?'
        . ' AND account.`aktiv` = 1 AND account.`estab_gesperrt` = 0'
        . ' LIMIT 1'
    );
    if (!$statement) {
        throw new RuntimeException(
            'Die ausgewählte Dienstfunktion konnte nicht geprüft werden.'
        );
    }
    try {
        $statement->bind_param(
            'iisss',
            $assignmentId,
            $incidentId,
            $shape['kuerzel'],
            $shape['funktion'],
            $shape['rolle']
        );
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Die ausgewählte Dienstfunktion konnte nicht geprüft werden.'
            );
        }
        $allowed = $statement->get_result()->fetch_row() !== null;
    } finally {
        $statement->close();
    }
    if (!$allowed) {
        throw new EstabReadPermissionException(
            'Die ausgewählte Dienstfunktion ist nicht mehr aktiv angenommen.'
        );
    }

    return $shape + ['duty_assignment_id' => $assignmentId];
}

/** Capability expected for one of the fixed DV 1-101 duty functions. */
function estab_read_identity_capability(array $identity): ?string
{
    $function = (string) ($identity['funktion'] ?? '');
    $role = (string) ($identity['rolle'] ?? '');
    return match ([$function, $role]) {
        ['S2', 'Stab'] => 'LAGE_DOKUMENTATION',
        ['ETB', 'Stab'] => 'EINSATZTAGEBUCH',
        ['Si', 'Stab'] => 'SICHTUNG',
        ['S6', 'Stab'] => 'FERNMELDEPLANUNG',
        ['LdF', 'Fernmelder'] => 'FERNMELDEBETRIEB',
        ['A/W', 'Fernmelder'] => 'BEFOERDERUNG',
        default => null,
    };
}

/**
 * Require the selected hat and, for fixed DV functions, its DB capability.
 *
 * Ordinary Stab/FB functions have no row in nv_funktionsfaehigkeiten and are
 * admitted only through their exact accepted assignment.
 */
function estab_read_require_identity_scope(
    mysqli $connection,
    int $incidentId,
    array $identity
): array {
    $selected = estab_read_require_selected_hat(
        $connection,
        $incidentId,
        $identity
    );
    $capability = estab_read_identity_capability($selected);
    if ($capability !== null) {
        try {
            estab_dv_require_selected_capability(
                $connection,
                $incidentId,
                $selected,
                $capability
            );
        } catch (EstabDvPermissionException $exception) {
            throw new EstabReadPermissionException(
                'Die ausgewählte Dienstfunktion besitzt nicht die '
                . 'erforderliche Leseberechtigung.',
                previous: $exception
            );
        }
    } elseif (!in_array($selected['rolle'], ['Stab', 'FB'], true)) {
        throw new EstabReadPermissionException(
            'Diese Dienstfunktion besitzt keine operative Leseberechtigung.'
        );
    }
    return $selected;
}

/**
 * Require the complete baseline for any operational read.
 *
 * @return array{incident:array<string,mixed>,identity:array<string,mixed>}
 */
function estab_read_require_operational_scope(
    mysqli $connection,
    array $identity
): array {
    $incident = estab_incident_require_active($connection);
    $selected = estab_read_require_identity_scope(
        $connection,
        (int) $incident['active_einsatz_id'],
        $identity
    );
    return ['incident' => $incident, 'identity' => $selected];
}

/**
 * Return the fixed message-field policy for incident-scoped suggestions.
 *
 * Callsigns are operational input for A/W and LdF. Translated senders are an
 * LdF-only incoming-message responsibility; outgoing local organisations
 * must never pollute that suggestion set.
 *
 * @return array{direction:?string}
 */
function estab_read_message_suggestion_policy(
    array $identity,
    string $field
): array {
    $function = $identity['funktion'] ?? null;
    $role = $identity['rolle'] ?? null;
    if (
        $field === '05_gegenstelle'
        && $role === 'Fernmelder'
        && in_array($function, ['A/W', 'LdF'], true)
    ) {
        return ['direction' => null];
    }
    if (
        $field === '13_abseinheit'
        && $function === 'LdF'
        && $role === 'Fernmelder'
    ) {
        return ['direction' => 'E'];
    }
    throw new EstabReadPermissionException(
        'Diese Dienstfunktion darf keine Vorschläge für dieses Feld lesen.'
    );
}

/**
 * Normalize one legacy or current suggestion without pre-escaping its value.
 */
function estab_read_normalize_message_suggestion(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $decoded = estab_message_plain_text($value);
    if (
        preg_match('//u', $decoded) !== 1
        || preg_match('/[\p{C}]/u', $decoded) === 1
    ) {
        return null;
    }
    $normalized = preg_replace('/\p{Z}+/u', ' ', $decoded);
    $normalized = is_string($normalized) ? trim($normalized) : '';
    if ($normalized === '') {
        return null;
    }
    $length = function_exists('mb_strlen')
        ? mb_strlen($normalized, 'UTF-8')
        : strlen($normalized);
    return $length <= 128 ? $normalized : null;
}

/**
 * Load recent, unique values from the active incident for one fixed field.
 *
 * The exact selected hat is revalidated before the query and joined into the
 * same SELECT snapshot together with account, capability, shift and incident
 * state. A concurrent revocation or incident switch therefore degrades to an
 * empty result instead of returning operational history.
 *
 * @return list<string>
 */
function estab_read_message_suggestions(
    mysqli $connection,
    string $messageTable,
    array $identity,
    string $field,
    int $limit = 30
): array {
    if ($limit < 1 || $limit > 50) {
        throw new InvalidArgumentException(
            'Die Anzahl der Nachrichtenvorschläge ist ungültig.'
        );
    }
    $scope = estab_read_require_operational_scope($connection, $identity);
    $policy = estab_read_message_suggestion_policy(
        $scope['identity'],
        $field
    );
    $incidentId = (int) $scope['incident']['active_einsatz_id'];
    $selected = $scope['identity'];
    $assignmentId = (int) $selected['duty_assignment_id'];
    $capability = estab_read_identity_capability($selected);
    if ($capability === null) {
        throw new EstabReadPermissionException(
            'Diese Dienstfunktion besitzt keine Vorschlagsberechtigung.'
        );
    }
    $table = estab_message_table($messageTable);
    $column = match ($field) {
        '05_gegenstelle' => '`05_gegenstelle`',
        '13_abseinheit' => '`13_abseinheit`',
        default => throw new InvalidArgumentException(
            'Das Vorschlagsfeld ist ungültig.'
        ),
    };
    $direction = $policy['direction'];
    $sql = 'SELECT message.' . $column . ' AS `suggestion`'
        . ' FROM ' . $table . ' AS message'
        . ' JOIN (SELECT MAX(candidate.`00_lfd`) AS `last_id`'
        . ' FROM ' . $table . ' AS candidate'
        . ' JOIN `nv_dienstbesetzungen` AS assignment'
        . ' ON assignment.`dienstbesetzung_id` = ?'
        . ' JOIN `nv_dienstschichten` AS duty_shift'
        . ' ON duty_shift.`dienstschicht_id`'
        . ' = assignment.`dienstschicht_id`'
        . ' JOIN `nv_einsatz_status` AS active'
        . ' ON active.`singleton_id` = 1'
        . ' AND active.`active_einsatz_id` = duty_shift.`einsatz_id`'
        . ' JOIN `nv_einsaetze` AS incident'
        . ' ON incident.`einsatz_id` = duty_shift.`einsatz_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel`'
        . ' = BINARY assignment.`benutzer_kuerzel`'
        . ' JOIN `nv_funktionsfaehigkeiten` AS capability'
        . ' ON BINARY capability.`funktion`'
        . ' = BINARY assignment.`funktion`'
        . ' AND BINARY capability.`rolle` = BINARY assignment.`rolle`'
        . ' WHERE duty_shift.`einsatz_id` = ?'
        . " AND duty_shift.`status` = 'AKTIV'"
        . " AND assignment.`status` = 'ANGENOMMEN'"
        . " AND incident.`estab_status` = 'open'"
        . ' AND BINARY account.`benutzer` = BINARY ?'
        . ' AND BINARY assignment.`benutzer_kuerzel` = BINARY ?'
        . ' AND BINARY assignment.`funktion` = BINARY ?'
        . ' AND BINARY assignment.`rolle` = BINARY ?'
        . ' AND BINARY capability.`faehigkeit` = BINARY ?'
        . ' AND account.`aktiv` = 1'
        . ' AND account.`estab_gesperrt` = 0'
        . ' AND candidate.`einsatz_id` = duty_shift.`einsatz_id`'
        . ' AND candidate.`einsatz_id` = ?'
        . ' AND candidate.' . $column . ' IS NOT NULL'
        . ' AND CHAR_LENGTH(TRIM(candidate.' . $column . ')) > 0';
    $parameters = [
        $assignmentId,
        $incidentId,
        $selected['benutzer'],
        $selected['kuerzel'],
        $selected['funktion'],
        $selected['rolle'],
        $capability,
        $incidentId,
    ];
    if ($direction !== null) {
        $sql .= ' AND candidate.`04_richtung` = ?';
        $parameters[] = $direction;
    }
    $sql .= ' GROUP BY BINARY TRIM(candidate.' . $column . ')) AS latest'
        . ' ON latest.`last_id` = message.`00_lfd`'
        . ' ORDER BY message.`00_lfd` DESC';

    $statement = estab_message_execute($connection, $sql, $parameters);
    try {
        $suggestions = [];
        $seen = [];
        $storedSuggestion = null;
        if (!$statement->bind_result($storedSuggestion)) {
            throw new RuntimeException(
                'Nachrichtenvorschläge konnten nicht gelesen werden.'
            );
        }
        while ($statement->fetch()) {
            $suggestion = estab_read_normalize_message_suggestion(
                $storedSuggestion
            );
            if ($suggestion === null) {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($suggestion, 'UTF-8')
                : strtolower($suggestion);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $suggestions[] = $suggestion;
            if (count($suggestions) >= $limit) {
                break;
            }
        }
        return $suggestions;
    } finally {
        $statement->close();
    }
}

/** Require one fixed capability for a privileged application area. */
function estab_read_require_capability(
    mysqli $connection,
    int $incidentId,
    array $identity,
    string $capability
): array {
    try {
        return estab_dv_require_selected_capability(
            $connection,
            $incidentId,
            $identity,
            $capability
        );
    } catch (EstabDvPermissionException $exception) {
        throw new EstabReadPermissionException(
            'Die ausgewählte Dienstfunktion besitzt nicht die '
            . 'erforderliche Leseberechtigung.',
            previous: $exception
        );
    }
}

/**
 * Guard the two privileged aggregate views.
 *
 * @return array{incident:array<string,mixed>,identity:array<string,mixed>}
 */
function estab_read_require_area(
    mysqli $connection,
    array $identity,
    string $area
): array {
    $incident = estab_incident_require_active($connection);
    $incidentId = (int) $incident['active_einsatz_id'];
    if ($area === 'message-overview') {
        $selected = estab_read_require_capability(
            $connection,
            $incidentId,
            $identity,
            'LAGE_DOKUMENTATION'
        );
    } elseif ($area === 'tracking') {
        $selected = null;
        foreach (['FERNMELDEBETRIEB', 'BEFOERDERUNG'] as $capability) {
            try {
                $selected = estab_read_require_capability(
                    $connection,
                    $incidentId,
                    $identity,
                    $capability
                );
                break;
            } catch (EstabReadPermissionException) {
                // The second fixed telecommunications capability may match.
            }
        }
        if (!is_array($selected)) {
            throw new EstabReadPermissionException(
                'Die Nachweisung ist nur für LdF oder A/W verfügbar.'
            );
        }
    } else {
        throw new InvalidArgumentException('Unbekannter Lesebereich.');
    }
    return ['incident' => $incident, 'identity' => $selected];
}

/** Compare one persisted processing mark with the authenticated account code. */
function estab_read_code_matches(mixed $storedCode, mixed $identityCode): bool
{
    if (!is_string($storedCode) || !is_string($identityCode)) {
        return false;
    }
    $storedCode = strtolower(trim($storedCode));
    $identityCode = strtolower(trim($identityCode));
    if (
        preg_match('/\A[a-z0-9_]{1,6}\z/D', $storedCode) !== 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $identityCode) !== 1
    ) {
        return false;
    }
    return hash_equals($identityCode, $storedCode);
}

/**
 * Pure per-message visibility decision.
 *
 * Normal Stab/FB functions reuse the repository's exact staff predicate:
 * terminal recipient copy or their own outgoing object. Si, LdF and A/W see
 * only their current workflow queue/lock or an object carrying their own
 * immutable processing mark.
 */
function estab_read_message_allowed(array $identity, array $message): bool
{
    if (
        estab_read_duty_assignment_id(
            $identity['duty_assignment_id'] ?? null
        ) === null
    ) {
        return false;
    }
    $function = (string) ($identity['funktion'] ?? '');
    $role = (string) ($identity['rolle'] ?? '');
    $code = (string) ($identity['kuerzel'] ?? '');
    $ownsLock = ($message['x02_sperre'] ?? '') === 't'
        && estab_read_code_matches($message['x03_sperruser'] ?? null, $code);

    if ($function === 'Si' && $role === 'Stab') {
        return estab_message_object_allowed(
            $identity,
            'viewer-review',
            $message
        )
            || $ownsLock
            || (
                !estab_datetime_is_unset(
                    $message['15_quitdatum'] ?? null
                )
                && estab_read_code_matches(
                    $message['15_quitzeichen'] ?? null,
                    $code
                )
            );
    }
    if ($function === 'LdF' && $role === 'Fernmelder') {
        return estab_message_object_allowed(
            $identity,
            'telecommunications-lead-edit',
            $message
        )
            || $ownsLock
            || (
                !estab_datetime_is_unset($message['02_zeit'] ?? null)
                && estab_read_code_matches(
                    $message['02_zeichen'] ?? null,
                    $code
                )
            );
    }
    if ($function === 'A/W' && $role === 'Fernmelder') {
        return estab_message_object_allowed(
            $identity,
            'telecommunications-edit',
            $message
        )
            || $ownsLock
            || estab_read_code_matches(
                $message['01_zeichen'] ?? null,
                $code
            )
            || (
                !estab_datetime_is_unset($message['03_datum'] ?? null)
                && estab_read_code_matches(
                    $message['03_zeichen'] ?? null,
                    $code
                )
            );
    }

    return in_array($role, ['Stab', 'FB'], true)
        && estab_message_object_allowed($identity, 'staff-read', $message);
}

/** Return one active-incident message only when this identity may read it. */
function estab_read_message(
    mysqli $connection,
    string $messageTable,
    mixed $recordId,
    array $identity
): ?array {
    $incident = estab_incident_require_active($connection);
    $selected = estab_read_require_identity_scope(
        $connection,
        (int) $incident['active_einsatz_id'],
        $identity
    );
    $message = estab_message_fetch_by_id(
        $connection,
        $messageTable,
        $recordId
    );
    return is_array($message)
        && estab_read_message_allowed($selected, $message)
        ? $message
        : null;
}

/** Filter complete message rows without exposing the rejected rows. */
function estab_read_filter_messages(
    array $messages,
    array $selectedIdentity
): array {
    return array_values(array_filter(
        $messages,
        static fn (mixed $message): bool =>
            is_array($message)
            && estab_read_message_allowed($selectedIdentity, $message)
    ));
}

/** Filter generated-form metadata through its authoritative message object. */
function estab_read_filter_generated_forms(
    mysqli $connection,
    string $messageTable,
    array $forms,
    array $identity
): array {
    $incident = estab_incident_require_active($connection);
    $selected = estab_read_require_identity_scope(
        $connection,
        (int) $incident['active_einsatz_id'],
        $identity
    );
    $visible = [];
    foreach ($forms as $form) {
        if (!is_array($form)) {
            continue;
        }
        try {
            $messageId = estab_message_positive_id(
                $form['message_id'] ?? null
            );
        } catch (InvalidArgumentException) {
            continue;
        }
        $message = estab_message_fetch_by_id(
            $connection,
            $messageTable,
            $messageId
        );
        if (
            is_array($message)
            && estab_read_message_allowed($selected, $message)
        ) {
            $visible[] = $form;
        }
    }
    return $visible;
}

/**
 * Parse complete semicolon-delimited attachment tokens.
 *
 * Invalid legacy fragments are ignored; authorization never uses LIKE or a
 * substring comparison.
 *
 * @return list<string>
 */
function estab_read_attachment_tokens(mixed $value): array
{
    if (!is_string($value) || $value === '') {
        return [];
    }
    $tokens = [];
    foreach (explode(';', $value) as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        try {
            $token = estab_file_validate_name('attachment', $token);
        } catch (InvalidArgumentException) {
            continue;
        }
        $tokens[$token] = true;
    }
    return array_keys($tokens);
}

/** Build an exact filename-to-message map for active-incident attachments. */
function estab_read_attachment_message_map(
    mysqli $connection,
    string $messageTable,
    int $incidentId,
    bool $forUpdate = false
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'SELECT * FROM ' . estab_auth_table($messageTable)
        . ' WHERE `einsatz_id` = ? AND `12_anhang` <> ?'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    if (!$statement) {
        throw new RuntimeException(
            'Die Anhangverknüpfungen konnten nicht geprüft werden.'
        );
    }
    try {
        $empty = '';
        $statement->bind_param('is', $incidentId, $empty);
        if (!$statement->execute()) {
            throw new RuntimeException(
                'Die Anhangverknüpfungen konnten nicht geprüft werden.'
            );
        }
        $result = $statement->get_result();
        $map = [];
        while (($message = $result->fetch_assoc()) !== null) {
            foreach (
                estab_read_attachment_tokens(
                    $message['12_anhang'] ?? null
                ) as $filename
            ) {
                $map[$filename][] = $message;
            }
        }
        $result->free();
        return $map;
    } finally {
        $statement->close();
    }
}

/**
 * Free attachments are readable by their uploader and by the three roles
 * responsible for review, telecommunications supervision, or documentation.
 */
function estab_read_free_attachment_allowed(
    array $selectedIdentity,
    array $attachment
): bool {
    if (
        estab_read_code_matches(
            $attachment['kuerzel'] ?? null,
            $selectedIdentity['kuerzel'] ?? null
        )
    ) {
        return true;
    }
    return in_array(
        [
            (string) ($selectedIdentity['funktion'] ?? ''),
            (string) ($selectedIdentity['rolle'] ?? ''),
        ],
        [
            ['S2', 'Stab'],
            ['Si', 'Stab'],
            ['LdF', 'Fernmelder'],
        ],
        true
    );
}

/** Pure inherited-right decision for one attachment row. */
function estab_read_attachment_allowed(
    array $selectedIdentity,
    array $attachment,
    array $linkedMessages
): bool {
    if ($linkedMessages !== []) {
        foreach ($linkedMessages as $message) {
            if (
                is_array($message)
                && estab_read_message_allowed($selectedIdentity, $message)
            ) {
                return true;
            }
        }
        return false;
    }
    return estab_read_free_attachment_allowed(
        $selectedIdentity,
        $attachment
    );
}

/** Return the canonical stored filename represented by one attachment row. */
function estab_read_attachment_filename(array $attachment): ?string
{
    try {
        $base = estab_attachment_validate_reservation_name(
            (string) ($attachment['filename'] ?? '')
        );
        $extension = strtolower((string) ($attachment['fileext'] ?? ''));
        if (
            preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) !== 1
            || !estab_attachment_extension_is_allowed($extension)
        ) {
            return null;
        }
        return estab_file_validate_name(
            'attachment',
            $base . '.' . $extension
        );
    } catch (InvalidArgumentException) {
        return null;
    }
}

/** Filter an already incident-scoped attachment list by inherited rights. */
function estab_read_filter_attachments(
    mysqli $connection,
    string $messageTable,
    array $attachments,
    array $identity
): array {
    $incident = estab_incident_require_active($connection);
    $incidentId = (int) $incident['active_einsatz_id'];
    $selected = estab_read_require_identity_scope(
        $connection,
        $incidentId,
        $identity
    );
    $messageMap = estab_read_attachment_message_map(
        $connection,
        $messageTable,
        $incidentId
    );
    $visible = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $filename = estab_read_attachment_filename($attachment);
        if (
            $filename !== null
            && estab_read_attachment_allowed(
                $selected,
                $attachment,
                $messageMap[$filename] ?? []
            )
        ) {
            $visible[] = $attachment;
        }
    }
    return $visible;
}

/**
 * Resolve and authorize one active-incident attachment in the same transaction
 * used by download/preview. Missing and foreign objects both return null.
 */
function estab_read_attachment(
    mysqli $connection,
    string $attachmentTable,
    string $messageTable,
    string $requestedFilename,
    array $identity,
    bool $forUpdate = false
): ?array {
    $requestedFilename = estab_file_validate_name(
        'attachment',
        $requestedFilename
    );
    $incident = estab_incident_require_active($connection, $forUpdate);
    $incidentId = (int) $incident['active_einsatz_id'];
    $selected = estab_read_require_identity_scope(
        $connection,
        $incidentId,
        $identity
    );
    $base = pathinfo($requestedFilename, PATHINFO_FILENAME);
    $extension = strtolower(
        pathinfo($requestedFilename, PATHINFO_EXTENSION)
    );
    $attachment = estab_attachment_find(
        $connection,
        $attachmentTable,
        $base,
        $forUpdate
    );
    if (
        !is_array($attachment)
        || !hash_equals(
            strtolower((string) ($attachment['fileext'] ?? '')),
            $extension
        )
    ) {
        return null;
    }
    $messageMap = estab_read_attachment_message_map(
        $connection,
        $messageTable,
        $incidentId,
        $forUpdate
    );
    return estab_read_attachment_allowed(
        $selected,
        $attachment,
        $messageMap[$requestedFilename] ?? []
    ) ? $attachment : null;
}

/**
 * Require every complete submitted attachment token to be usable now.
 *
 * This function is designed to run inside the final message transaction. It
 * therefore closes both the forged lfd_* selection path and the gap between a
 * legitimate selection roundtrip and the eventual message INSERT/UPDATE.
 */
function estab_read_require_attachment_use_scope(
    mysqli $connection,
    string $attachmentTable,
    string $messageTable,
    int $incidentId,
    mixed $attachmentList,
    array $identity
): void {
    if ($attachmentList === null || $attachmentList === '') {
        return;
    }
    if (!is_string($attachmentList) || strlen($attachmentList) > 65535) {
        throw new InvalidArgumentException('Ungültige Anhangliste.');
    }
    $submitted = array_values(array_filter(
        array_map('trim', explode(';', $attachmentList)),
        static fn (string $filename): bool => $filename !== ''
    ));
    if (count($submitted) > 100 || count($submitted) !== count(array_unique($submitted))) {
        throw new InvalidArgumentException('Ungültige Anhangliste.');
    }
    $incidentId = estab_incident_positive_id($incidentId);
    $active = estab_incident_require_active($connection, true);
    if ((int) $active['active_einsatz_id'] !== $incidentId) {
        throw new EstabIncidentConflictException(
            'Der aktive Einsatz hat sich geändert.'
        );
    }
    foreach ($submitted as $filename) {
        try {
            $filename = estab_file_validate_name('attachment', $filename);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'Ungültige Anhangreferenz.',
                previous: $exception
            );
        }
        if (
            estab_read_attachment(
                $connection,
                $attachmentTable,
                $messageTable,
                $filename,
                $identity,
                true
            ) === null
        ) {
            throw new EstabReadPermissionException(
                'Ein ausgewählter Anhang ist für diese Dienstfunktion '
                . 'nicht freigegeben.'
            );
        }
    }
}
