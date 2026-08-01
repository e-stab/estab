<?php

declare(strict_types=1);

/**
 * Object-level read boundary for messages, generated forms and attachments.
 *
 * Authentication alone deliberately grants no operational object access.
 * Every read is bound to the active incident and the fixed function/role of
 * the authenticated account. Optional access shifts can disable an account as
 * a group but do not alter fachliche rights. Message visibility is then
 * derived from the same workflow fields that carry signed processing marks.
 */

require_once __DIR__ . '/attachment.php';
require_once __DIR__ . '/file_access.php';
require_once __DIR__ . '/message_repository.php';

final class EstabReadPermissionException extends RuntimeException
{
}

/** Return the authenticated account identity without a shift-derived role. */
function estab_read_session_identity(array $session): ?array
{
    return estab_auth_session_identity($session);
}

/** Validate the fixed account and active incident inside the read request. */
function estab_read_require_account_identity(
    mysqli $connection,
    int $incidentId,
    array $identity
): array {
    try {
        return estab_dv_require_operational_account(
            $connection,
            estab_incident_positive_id($incidentId),
            $identity,
            false
        );
    } catch (EstabDvPermissionException $exception) {
        throw new EstabReadPermissionException(
            'Für diesen Bereich werden ein aktiver Einsatz und ein aktiver '
            . 'Benutzerzugang benötigt.',
            previous: $exception
        );
    }
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
 * Require the fixed account and, for DV functions, its DB capability.
 */
function estab_read_require_identity_scope(
    mysqli $connection,
    int $incidentId,
    array $identity
): array {
    $selected = estab_read_require_account_identity(
        $connection,
        $incidentId,
        $identity
    );
    $capability = estab_read_identity_capability($selected);
    if ($capability !== null) {
        try {
            estab_dv_require_account_capability(
                $connection,
                $incidentId,
                $selected,
                $capability,
                false
            );
        } catch (EstabDvPermissionException $exception) {
            throw new EstabReadPermissionException(
                'Die zugewiesene Funktion besitzt nicht die '
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
 * Return the fixed source/target pair for one LdF translation direction.
 *
 * Incoming messages translate the callsign recorded by A/W into the external
 * sender/unit. Outgoing messages translate the staff-supplied destination into
 * the callsign which LdF must address. The returned identifiers are constants,
 * never browser-controlled SQL fragments.
 *
 * @return array{
 *   message_context:string,
 *   message_target:string,
 *   plan_context:string,
 *   plan_target:string
 * }
 */
function estab_read_ldf_mapping_policy(
    array $identity,
    string $direction
): array {
    if (
        ($identity['funktion'] ?? null) !== 'LdF'
        || ($identity['rolle'] ?? null) !== 'Fernmelder'
    ) {
        throw new EstabReadPermissionException(
            'Nur ein Konto mit der festen Funktion LdF darf Zuordnungen lesen.'
        );
    }
    return match ($direction) {
        'E' => [
            'message_context' => '`05_gegenstelle`',
            'message_target' => '`13_abseinheit`',
            'plan_context' => '`rufname`',
            'plan_target' => '`betriebsstelle`',
        ],
        'A' => [
            'message_context' => '`10_anschrift`',
            'message_target' => '`05_gegenstelle`',
            'plan_context' => '`betriebsstelle`',
            'plan_target' => '`rufname`',
        ],
        default => throw new InvalidArgumentException(
            'Die Zuordnungsrichtung ist ungültig.'
        ),
    };
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
 * Normalize the read-only context shown next to a mapping suggestion.
 *
 * Addresses may legitimately contain line breaks. They are collapsed for the
 * compact hint while every other control character remains forbidden.
 */
function estab_read_normalize_mapping_context(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $decoded = estab_message_plain_text($value);
    if (preg_match('//u', $decoded) !== 1) {
        return null;
    }
    $normalized = preg_replace('/[\p{Z}\t\r\n]+/u', ' ', $decoded);
    $normalized = is_string($normalized) ? trim($normalized) : '';
    if (
        $normalized === ''
        || preg_match('/[\p{C}]/u', $normalized) === 1
    ) {
        return null;
    }
    $length = function_exists('mb_strlen')
        ? mb_strlen($normalized, 'UTF-8')
        : strlen($normalized);
    return $length <= 1000 ? $normalized : null;
}

/**
 * Build one fixed SQL expression for pair-mapping context comparison.
 *
 * The legacy application stored HTML-escaped text while current writes store
 * raw UTF-8. Decode the common one-pass htmlspecialchars representations before
 * collapsing whitespace. `&amp;` and its numeric forms deliberately come last:
 * an old `&amp;lt;` value therefore becomes `&lt;`, never `<`, and is not
 * decoded twice.
 *
 * The caller may only supply one of the repository-owned context expressions.
 * The returned lowercase text still uses the column collation; comparisons
 * must cast it to BINARY so utf8mb4_unicode_ci cannot conflate Bar/Bär or ss/ß.
 */
function estab_read_mapping_normalized_sql(string $expression): string
{
    if (!in_array(
        $expression,
        [
            'candidate.`05_gegenstelle`',
            'candidate.`10_anschrift`',
            'plan_entry.`rufname`',
            'plan_entry.`betriebsstelle`',
            'scope.`context_value`',
        ],
        true
    )) {
        throw new InvalidArgumentException(
            'Der SQL-Ausdruck für den Zuordnungskontext ist ungültig.'
        );
    }

    $decoded = $expression;
    foreach (
        [
            ['&quot;', 'CHAR(34)'],
            ['&#34;', 'CHAR(34)'],
            ['&#034;', 'CHAR(34)'],
            ['&#x22;', 'CHAR(34)'],
            ['&#X22;', 'CHAR(34)'],
            ['&apos;', 'CHAR(39)'],
            ['&#39;', 'CHAR(39)'],
            ['&#039;', 'CHAR(39)'],
            ['&#x27;', 'CHAR(39)'],
            ['&#X27;', 'CHAR(39)'],
            ['&lt;', 'CHAR(60)'],
            ['&#60;', 'CHAR(60)'],
            ['&#x3c;', 'CHAR(60)'],
            ['&#x3C;', 'CHAR(60)'],
            ['&#X3c;', 'CHAR(60)'],
            ['&#X3C;', 'CHAR(60)'],
            ['&gt;', 'CHAR(62)'],
            ['&#62;', 'CHAR(62)'],
            ['&#x3e;', 'CHAR(62)'],
            ['&#x3E;', 'CHAR(62)'],
            ['&#X3e;', 'CHAR(62)'],
            ['&#X3E;', 'CHAR(62)'],
            ['&nbsp;', 'CHAR(32)'],
            ['&#160;', 'CHAR(32)'],
            ['&#xa0;', 'CHAR(32)'],
            ['&#xA0;', 'CHAR(32)'],
            ['&#XA0;', 'CHAR(32)'],
            ['&amp;', 'CHAR(38)'],
            ['&#38;', 'CHAR(38)'],
            ['&#038;', 'CHAR(38)'],
            ['&#x26;', 'CHAR(38)'],
            ['&#X26;', 'CHAR(38)'],
        ] as [$entity, $replacement]
    ) {
        $decoded = "REPLACE({$decoded}, '{$entity}', {$replacement})";
    }

    return "LOWER(TRIM(REGEXP_REPLACE({$decoded}, '[[:space:]]+', ' ')))";
}

/**
 * Return context-dependent LdF mappings for one currently locked message.
 *
 * Completed message pairs from the active incident are deliberately ranked
 * before matching entries of the currently valid S6 telecommunications plan.
 * The fixed account, capability, active incident and current message lock are
 * rejoined in both branches of the same UNION query. A concurrent account
 * block, incident switch or lock loss therefore yields no stale data.
 *
 * @return list<array{
 *   value:string,
 *   source:string,
 *   context:string,
 *   match:string,
 *   matched_context:string
 * }>
 */
function estab_read_ldf_mapping_suggestions(
    mysqli $connection,
    string $messageTable,
    array $identity,
    mixed $messageId,
    string $direction,
    int $limit = 10
): array {
    if ($limit < 1 || $limit > 30) {
        throw new InvalidArgumentException(
            'Die Anzahl der Zuordnungsvorschläge ist ungültig.'
        );
    }
    $messageId = estab_message_positive_id($messageId);
    $scope = estab_read_require_operational_scope($connection, $identity);
    $policy = estab_read_ldf_mapping_policy(
        $scope['identity'],
        $direction
    );
    $selected = $scope['identity'];
    $incidentId = (int) $scope['incident']['active_einsatz_id'];
    $capability = estab_read_identity_capability($selected);
    if ($capability !== 'FERNMELDEBETRIEB') {
        throw new EstabReadPermissionException(
            'Die feste Kontofunktion besitzt keine LdF-Zuordnungsberechtigung.'
        );
    }

    $table = estab_message_table($messageTable);
    $messageContext = $policy['message_context'];
    $messageTarget = $policy['message_target'];
    $planContext = $policy['plan_context'];
    $planTarget = $policy['plan_target'];

    $scopeSql = 'SELECT active.`active_einsatz_id` AS `incident_id`,'
        . ' current_message.' . $messageContext . ' AS `context_value`'
        . ' FROM `nv_einsatz_status` AS active'
        . ' JOIN `nv_einsaetze` AS incident'
        . ' ON incident.`einsatz_id` = active.`active_einsatz_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` = BINARY ?'
        . ' JOIN `nv_funktionsfaehigkeiten` AS capability'
        . ' ON BINARY capability.`funktion`'
        . ' = BINARY account.`funktion`'
        . ' AND BINARY capability.`rolle` = BINARY account.`rolle`'
        . ' JOIN ' . $table . ' AS current_message'
        . ' ON current_message.`00_lfd` = ?'
        . ' AND current_message.`einsatz_id` = active.`active_einsatz_id`'
        . ' AND current_message.`04_richtung` = ?'
        . ' AND current_message.`x00_status` = 1'
        . " AND current_message.`x01_abschluss` IN ('f', '0')"
        . " AND current_message.`x02_sperre` IN ('t', '1')"
        . ' AND BINARY current_message.`x03_sperruser`'
        . ' = BINARY account.`kuerzel`'
        . ' WHERE active.`singleton_id` = 1'
        . ' AND active.`active_einsatz_id` = ?'
        . " AND incident.`estab_status` = 'open'"
        . ' AND BINARY account.`benutzer` = BINARY ?'
        . ' AND BINARY account.`funktion` = BINARY ?'
        . ' AND BINARY account.`rolle` = BINARY ?'
        . ' AND BINARY capability.`faehigkeit` = BINARY ?'
        . ' AND account.`aktiv` = 1'
        . ' AND account.`estab_gesperrt` = 0';
    $scopeParameters = [
        $selected['kuerzel'],
        $messageId,
        $direction,
        $incidentId,
        $selected['benutzer'],
        $selected['funktion'],
        $selected['rolle'],
        $capability,
    ];

    $scopeNormalized = estab_read_mapping_normalized_sql(
        'scope.`context_value`'
    );
    $messageNormalized = estab_read_mapping_normalized_sql(
        'candidate.' . $messageContext
    );
    $planNormalized = estab_read_mapping_normalized_sql(
        'plan_entry.' . $planContext
    );
    $binaryEquals = static fn (string $left, string $right): string =>
        'CAST(' . $left . ' AS BINARY) = CAST(' . $right . ' AS BINARY)';
    $messageExact = $binaryEquals($messageNormalized, $scopeNormalized);
    $planExact = $binaryEquals($planNormalized, $scopeNormalized);

    // Incoming callsigns are identifiers and therefore exact-only. Outgoing
    // operating-station addresses may carry a whitespace-separated annotation
    // (for example an Einsatzabschnitt). Only that direction receives a
    // symmetric, word-boundary prefix relation; arbitrary substrings such as
    // "Einheit" in "Einheitlich" never match.
    $relatedPrefix = static function (
        string $short,
        string $long
    ) use ($binaryEquals): string {
        return '(CHAR_LENGTH(' . $long . ') > CHAR_LENGTH(' . $short . ')'
            . ' AND '
            . $binaryEquals(
                'LEFT(' . $long . ', CHAR_LENGTH(' . $short . '))',
                $short
            )
            . ' AND SUBSTRING(' . $long . ', CHAR_LENGTH(' . $short
            . ") + 1, 1) = ' ')";
    };
    $messageRelated = '('
        . $relatedPrefix($messageNormalized, $scopeNormalized)
        . ' OR '
        . $relatedPrefix($scopeNormalized, $messageNormalized)
        . ')';
    $planRelated = '('
        . $relatedPrefix($planNormalized, $scopeNormalized)
        . ' OR '
        . $relatedPrefix($scopeNormalized, $planNormalized)
        . ')';
    $messageMatch = $direction === 'A'
        ? '(' . $messageExact . ' OR ' . $messageRelated . ')'
        : $messageExact;
    $planMatch = $direction === 'A'
        ? '(' . $planExact . ' OR ' . $planRelated . ')'
        : $planExact;

    $sql = 'SELECT mapped.`suggestion`, mapped.`source_kind`,'
        . ' mapped.`context_value`,'
        . " CASE mapped.`match_priority` WHEN 0 THEN 'exact'"
        . " ELSE 'related' END AS `match_kind`,"
        . ' CASE mapped.`match_priority`'
        . ' WHEN 0 THEN mapped.`exact_context`'
        . ' ELSE mapped.`related_context` END AS `matched_context`'
        . ' FROM ('
        . ' SELECT MAX(TRIM(candidate.' . $messageTarget
        . ')) AS `suggestion`,'
        . " 'message' AS `source_kind`, 0 AS `source_priority`,"
        . ' MIN(CASE WHEN ' . $messageExact
        . ' THEN 0 ELSE 1 END) AS `match_priority`,'
        . ' MAX(CASE WHEN ' . $messageExact
        . ' THEN TRIM(candidate.' . $messageContext
        . ') ELSE NULL END) AS `exact_context`,'
        . ' MAX(CASE WHEN NOT (' . $messageExact
        . ') THEN TRIM(candidate.' . $messageContext
        . ') ELSE NULL END) AS `related_context`,'
        . ' COUNT(*) AS `frequency`,'
        . ' MAX(candidate.`00_lfd`) AS `recency`,'
        . ' MAX(scope.`context_value`) AS `context_value`'
        . ' FROM (' . $scopeSql . ') AS scope'
        . ' JOIN ' . $table . ' AS candidate'
        . ' ON candidate.`einsatz_id` = scope.`incident_id`'
        . ' WHERE candidate.`04_richtung` = ?'
        . ' AND candidate.`x00_status` = 8'
        . " AND candidate.`x01_abschluss` IN ('t', '1')"
        . ' AND candidate.' . $messageContext . ' IS NOT NULL'
        . ' AND candidate.' . $messageTarget . ' IS NOT NULL'
        . ' AND CHAR_LENGTH(TRIM(candidate.' . $messageContext . ')) > 0'
        . ' AND CHAR_LENGTH(TRIM(candidate.' . $messageTarget . ')) > 0'
        . ' AND ' . $messageMatch
        . ' GROUP BY BINARY TRIM(candidate.' . $messageTarget . ')'
        . ' UNION ALL'
        . ' SELECT MAX(TRIM(plan_entry.' . $planTarget
        . ')) AS `suggestion`,'
        . " 'plan' AS `source_kind`, 1 AS `source_priority`,"
        . ' MIN(CASE WHEN ' . $planExact
        . ' THEN 0 ELSE 1 END) AS `match_priority`,'
        . ' MAX(CASE WHEN ' . $planExact
        . ' THEN TRIM(plan_entry.' . $planContext
        . ') ELSE NULL END) AS `exact_context`,'
        . ' MAX(CASE WHEN NOT (' . $planExact
        . ') THEN TRIM(plan_entry.' . $planContext
        . ') ELSE NULL END) AS `related_context`,'
        . ' COUNT(*) AS `frequency`,'
        . ' MAX(plan_entry.`fernmeldeplan_eintrag_id`) AS `recency`,'
        . ' MAX(scope.`context_value`) AS `context_value`'
        . ' FROM (' . $scopeSql . ') AS scope'
        . ' JOIN `nv_fernmeldeplaene` AS telecom_plan'
        . ' ON telecom_plan.`einsatz_id` = scope.`incident_id`'
        . " AND telecom_plan.`status` = 'AKTIV'"
        . ' AND telecom_plan.`gueltig_ab` <= NOW()'
        . ' AND (telecom_plan.`gueltig_bis` IS NULL'
        . ' OR telecom_plan.`gueltig_bis` >= NOW())'
        . ' JOIN `nv_fernmeldeplan_eintraege` AS plan_entry'
        . ' ON plan_entry.`fernmeldeplan_id`'
        . ' = telecom_plan.`fernmeldeplan_id`'
        . ' WHERE plan_entry.' . $planContext . ' IS NOT NULL'
        . ' AND plan_entry.' . $planTarget . ' IS NOT NULL'
        . ' AND CHAR_LENGTH(TRIM(plan_entry.' . $planContext . ')) > 0'
        . ' AND CHAR_LENGTH(TRIM(plan_entry.' . $planTarget . ')) > 0'
        . ' AND ' . $planMatch
        . ' GROUP BY BINARY TRIM(plan_entry.' . $planTarget . ')'
        . ') AS mapped'
        . ' ORDER BY mapped.`source_priority` ASC,'
        . ' mapped.`match_priority` ASC, mapped.`frequency` DESC,'
        . ' mapped.`recency` DESC';
    $parameters = array_merge(
        $scopeParameters,
        [$direction],
        $scopeParameters
    );
    $statement = estab_message_execute($connection, $sql, $parameters);
    try {
        $storedSuggestion = null;
        $storedSource = null;
        $storedContext = null;
        $storedMatch = null;
        $storedMatchedContext = null;
        if (
            !$statement->bind_result(
                $storedSuggestion,
                $storedSource,
                $storedContext,
                $storedMatch,
                $storedMatchedContext
            )
        ) {
            throw new RuntimeException(
                'LdF-Zuordnungen konnten nicht gelesen werden.'
            );
        }
        $suggestions = [];
        $seen = [];
        while ($statement->fetch()) {
            $suggestion = estab_read_normalize_message_suggestion(
                $storedSuggestion
            );
            $context = estab_read_normalize_mapping_context($storedContext);
            $matchedContext = estab_read_normalize_mapping_context(
                $storedMatchedContext
            );
            if (
                $suggestion === null
                || $context === null
                || $matchedContext === null
                || !in_array($storedSource, ['message', 'plan'], true)
                || !in_array($storedMatch, ['exact', 'related'], true)
            ) {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($suggestion, 'UTF-8')
                : strtolower($suggestion);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $suggestions[] = [
                'value' => $suggestion,
                'source' => $storedSource,
                'context' => $context,
                'match' => $storedMatch,
                'matched_context' => $matchedContext,
            ];
            if (count($suggestions) >= $limit) {
                break;
            }
        }
        return $suggestions;
    } finally {
        $statement->close();
    }
}

/**
 * Load recent, unique values from the active incident for one fixed field.
 *
 * The fixed account is revalidated before the query and joined into the same
 * snapshot with capability and incident state. A concurrent block or incident
 * switch therefore degrades to an empty result.
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
        . ' JOIN `nv_einsatz_status` AS active'
        . ' ON active.`singleton_id` = 1'
        . ' AND active.`active_einsatz_id` = ?'
        . ' JOIN `nv_einsaetze` AS incident'
        . ' ON incident.`einsatz_id` = active.`active_einsatz_id`'
        . ' JOIN `nv_benutzer` AS account'
        . ' ON BINARY account.`kuerzel` = BINARY ?'
        . ' JOIN `nv_funktionsfaehigkeiten` AS capability'
        . ' ON BINARY capability.`funktion`'
        . ' = BINARY account.`funktion`'
        . ' AND BINARY capability.`rolle` = BINARY account.`rolle`'
        . ' WHERE active.`active_einsatz_id` = ?'
        . " AND incident.`estab_status` = 'open'"
        . ' AND BINARY account.`benutzer` = BINARY ?'
        . ' AND BINARY account.`funktion` = BINARY ?'
        . ' AND BINARY account.`rolle` = BINARY ?'
        . ' AND BINARY capability.`faehigkeit` = BINARY ?'
        . ' AND account.`aktiv` = 1'
        . ' AND account.`estab_gesperrt` = 0'
        . ' AND candidate.`einsatz_id` = active.`active_einsatz_id`'
        . ' AND candidate.`einsatz_id` = ?'
        . ' AND candidate.' . $column . ' IS NOT NULL'
        . ' AND CHAR_LENGTH(TRIM(candidate.' . $column . ')) > 0';
    $parameters = [
        $incidentId,
        $selected['kuerzel'],
        $incidentId,
        $selected['benutzer'],
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
        return estab_dv_require_account_capability(
            $connection,
            $incidentId,
            $identity,
            $capability,
            false
        );
    } catch (EstabDvPermissionException $exception) {
        throw new EstabReadPermissionException(
            'Die zugewiesene Funktion besitzt nicht die '
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
                'Die Nachweisung ist nur für LdF oder Fernmelder verfügbar.'
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
 * SQL equivalent of estab_read_message_allowed() for pageable message lists.
 *
 * The returned predicate is deliberately followed by the pure PHP decision
 * for every selected row. Moving the same boundary before COUNT/LIMIT keeps
 * result totals exact without weakening the object-level read gate.
 *
 * @return array{sql:string,params:list<mixed>}
 */
function estab_read_message_visibility_sql(
    array $identity,
    string $alias = 'm'
): array {
    if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $alias) !== 1) {
        throw new InvalidArgumentException('Invalid message-list alias');
    }
    $function = (string) ($identity['funktion'] ?? '');
    $role = (string) ($identity['rolle'] ?? '');
    $code = (string) ($identity['kuerzel'] ?? '');
    if (
        preg_match('/\A[a-zA-Z0-9_]{1,6}\z/D', $code) !== 1
        || preg_match('/\A[^\x00-\x1F\x7F]{1,128}\z/uD', $function) !== 1
    ) {
        throw new EstabReadPermissionException(
            'Die Dienstidentität ist für die Nachrichtenliste ungültig.'
        );
    }
    $column = static fn (string $name): string => $alias . '.`' . $name . '`';
    $codeMatch = static fn (string $name): string =>
        'LOWER(TRIM(' . $column($name) . ')) = LOWER(?)';
    $dateUnset = static fn (string $name): string => '('
        . $column($name) . ' IS NULL OR TRIM(CAST('
        . $column($name) . " AS CHAR)) IN ('', '0000-00-00', "
        . "'0000-00-00 00:00:00'))";
    $dateSet = static fn (string $name): string =>
        '(NOT ' . $dateUnset($name) . ')';
    $ownsLock = '(' . $column('x02_sperre') . " = 't' AND "
        . $codeMatch('x03_sperruser') . ')';

    if ($function === 'Si' && $role === 'Stab') {
        $pendingReview = '('
            . $column('x00_status') . ' = 4'
            . ' AND ' . $dateUnset('15_quitdatum')
            . " AND " . $column('15_quitzeichen') . " = ''"
            . ' AND (('
            . $column('04_richtung') . " = 'E'"
            . ' AND ' . $dateSet('02_zeit')
            . " AND " . $column('02_zeichen') . " <> ''"
            . ') OR ('
            . $column('04_richtung') . " = 'A'"
            . ' AND ' . $dateUnset('02_zeit')
            . " AND " . $column('02_zeichen') . " = ''"
            . ' AND ' . $dateUnset('03_datum')
            . " AND " . $column('03_zeichen') . " = ''"
            . ')))';
        return [
            'sql' => '(' . $pendingReview . ' OR ' . $ownsLock
                . ' OR (' . $dateSet('15_quitdatum') . ' AND '
                . $codeMatch('15_quitzeichen') . '))',
            'params' => [$code, $code],
        ];
    }

    if ($function === 'A/W' && $role === 'Fernmelder') {
        $pendingTransport = '('
            . $column('x00_status') . ' = 2'
            . ' AND ' . $column('04_richtung') . " = 'A'"
            . ' AND ' . $dateSet('02_zeit')
            . " AND " . $column('02_zeichen') . " <> ''"
            . " AND " . $column('06_befwegausw') . " <> ''"
            . ' AND ' . $dateUnset('03_datum')
            . " AND " . $column('03_zeichen') . " = ''"
            . ' AND ' . $dateSet('15_quitdatum')
            . " AND " . $column('15_quitzeichen') . " <> ''"
            . " AND " . $column('x01_abschluss') . " = 'f')";
        return [
            'sql' => '(' . $pendingTransport . ' OR ' . $ownsLock
                . ' OR ' . $codeMatch('01_zeichen')
                . ' OR (' . $dateSet('03_datum') . ' AND '
                . $codeMatch('03_zeichen') . '))',
            'params' => [$code, $code, $code],
        ];
    }

    if ($function === 'LdF' && $role === 'Fernmelder') {
        $pendingLead = '('
            . $column('x00_status') . ' = 1'
            . ' AND ' . $dateUnset('02_zeit')
            . " AND " . $column('02_zeichen') . " = ''"
            . ' AND ' . $dateUnset('03_datum')
            . " AND " . $column('03_zeichen') . " = ''"
            . " AND " . $column('x01_abschluss') . " = 'f'"
            . ' AND (('
            . $column('04_richtung') . " = 'E'"
            . ' AND ' . $dateUnset('15_quitdatum')
            . " AND " . $column('15_quitzeichen') . " = ''"
            . ') OR ('
            . $column('04_richtung') . " = 'A'"
            . ' AND ' . $dateSet('15_quitdatum')
            . " AND " . $column('15_quitzeichen') . " <> ''"
            . ')))';
        return [
            'sql' => '(' . $pendingLead . ' OR ' . $ownsLock
                . ' OR (' . $dateSet('02_zeit') . ' AND '
                . $codeMatch('02_zeichen') . '))',
            'params' => [$code, $code],
        ];
    }

    if ($function !== 'Si' && in_array($role, ['Stab', 'FB'], true)) {
        return [
            'sql' => estab_message_staff_access_sql($alias),
            'params' => [
                estab_message_recipient_pattern($function),
                $function,
            ],
        ];
    }
    throw new EstabReadPermissionException(
        'Diese Dienstfunktion darf keine Nachrichtenliste lesen.'
    );
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
    $message = estab_message_fetch_for_incident_by_id(
        $connection,
        $messageTable,
        $recordId,
        $incident['active_einsatz_id']
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

/**
 * Filter generated-form metadata through messages from one captured incident.
 */
function estab_read_filter_generated_forms_for_incident(
    mysqli $connection,
    string $messageTable,
    array $forms,
    array $selectedIdentity,
    mixed $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
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
        $message = estab_message_fetch_for_incident_by_id(
            $connection,
            $messageTable,
            $messageId,
            $incidentId
        );
        if (
            is_array($message)
            && estab_read_message_allowed($selectedIdentity, $message)
        ) {
            $visible[] = $form;
        }
    }
    return $visible;
}

/** Filter active-incident form metadata for an uncaptured caller. */
function estab_read_filter_generated_forms(
    mysqli $connection,
    string $messageTable,
    array $forms,
    array $identity
): array {
    $scope = estab_read_require_operational_scope($connection, $identity);
    return estab_read_filter_generated_forms_for_incident(
        $connection,
        $messageTable,
        $forms,
        $scope['identity'],
        $scope['incident']['active_einsatz_id']
    );
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

/** Columns needed to decide inherited attachment access. */
function estab_read_attachment_authorization_columns(): string
{
    // Attachment authorization needs workflow state, marks, sender/recipient
    // functions and the attachment token list only. In particular, never
    // transfer message subjects or bodies for thumbnail/download checks: one
    // page can legitimately start several lazy preview requests and active
    // incidents may contain thousands of long messages.
    return implode(', ', [
        '`12_anhang`', '`04_richtung`', '`06_befwegausw`', '`16_empf`',
        '`x00_status`', '`x01_abschluss`', '`x02_sperre`',
        '`x03_sperruser`', '`01_zeichen`', '`02_zeit`', '`02_zeichen`',
        '`03_datum`', '`03_zeichen`', '`14_zeichen`', '`14_funktion`',
        '`15_quitdatum`', '`15_quitzeichen`',
    ]);
}

/** Build an exact filename-to-message map for active-incident attachments. */
function estab_read_attachment_message_map(
    mysqli $connection,
    string $messageTable,
    int $incidentId,
    bool $forUpdate = false
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    $authorizationColumns = estab_read_attachment_authorization_columns();
    $statement = $connection->prepare(
        'SELECT ' . $authorizationColumns . ' FROM '
        . estab_auth_table($messageTable)
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
 * Resolve only messages that can contain one of the requested exact tokens.
 *
 * The legacy schema stores a semicolon-delimited list instead of a normalized
 * relation. MariaDB must therefore inspect that compact column, but it no
 * longer transfers and parses every attachment-bearing message for each lazy
 * thumbnail. The PHP token parser remains the authority after the SQL
 * prefilter, so neither substrings nor malformed legacy fragments grant read
 * access.
 *
 * @param list<string> $requestedFilenames
 */
function estab_read_attachment_message_map_for_filenames(
    mysqli $connection,
    string $messageTable,
    int $incidentId,
    array $requestedFilenames
): array {
    $incidentId = estab_incident_positive_id($incidentId);
    if ($requestedFilenames === [] || count($requestedFilenames) > 100) {
        throw new InvalidArgumentException('Ungültige Anzahl Anhangreferenzen.');
    }
    $requested = [];
    foreach ($requestedFilenames as $filename) {
        if (!is_string($filename)) {
            throw new InvalidArgumentException('Ungültige Anhangreferenz.');
        }
        $filename = estab_file_validate_name('attachment', $filename);
        if (isset($requested[$filename])) {
            throw new InvalidArgumentException('Doppelte Anhangreferenz.');
        }
        $requested[$filename] = true;
    }

    $conditions = array_fill(
        0,
        count($requested),
        'LOCATE(?, `12_anhang`) > 0'
    );
    $statement = estab_message_execute(
        $connection,
        'SELECT ' . estab_read_attachment_authorization_columns()
            . ' FROM ' . estab_auth_table($messageTable)
            . ' WHERE `einsatz_id` = ? AND ('
            . implode(' OR ', $conditions) . ')',
        array_merge([$incidentId], array_keys($requested))
    );
    try {
        $result = $statement->get_result();
        $map = [];
        while (($message = $result->fetch_assoc()) !== null) {
            foreach (
                estab_read_attachment_tokens(
                    $message['12_anhang'] ?? null
                ) as $filename
            ) {
                if (isset($requested[$filename])) {
                    $map[$filename][] = $message;
                }
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

/**
 * Bind a private file snapshot to the exact attachment row that authorized it.
 *
 * Endpoints can commit the initial database read before hashing a large file,
 * then reauthorize and compare this opaque version afterwards. This keeps
 * operational incident/message rows unlocked during NAS I/O without opening
 * a time-of-check/time-of-use gap across an incident or uploader change.
 */
function estab_read_attachment_authorization_version(array $attachment): string
{
    $fields = [];
    foreach (
        [
            'einsatz_id', 'filename', 'fileext', 'status', 'kuerzel',
            'integrity_required', 'ingest_sha256', 'ingest_size',
            'integrity_captured_at',
        ] as $field
    ) {
        $value = $attachment[$field] ?? null;
        if (!is_int($value) && !is_string($value) && $value !== null) {
            throw new InvalidArgumentException(
                'Ungültiger Autorisierungsstand des Anhangs.'
            );
        }
        $fields[$field] = $value;
    }
    try {
        return hash(
            'sha256',
            json_encode(
                $fields,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    } catch (JsonException $exception) {
        throw new InvalidArgumentException(
            'Ungültiger Autorisierungsstand des Anhangs.',
            previous: $exception
        );
    }
}

/**
 * Filter an attachment list through one captured incident and identity.
 */
function estab_read_filter_attachments_for_incident(
    mysqli $connection,
    string $messageTable,
    array $attachments,
    array $selectedIdentity,
    mixed $incidentId
): array {
    $incidentId = estab_incident_positive_id($incidentId);
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
                $selectedIdentity,
                $attachment,
                $messageMap[$filename] ?? []
            )
        ) {
            $visible[] = $attachment;
        }
    }
    return $visible;
}

/** Filter an attachment list for an uncaptured active-incident caller. */
function estab_read_filter_attachments(
    mysqli $connection,
    string $messageTable,
    array $attachments,
    array $identity
): array {
    $scope = estab_read_require_operational_scope($connection, $identity);
    return estab_read_filter_attachments_for_incident(
        $connection,
        $messageTable,
        $attachments,
        $scope['identity'],
        $scope['incident']['active_einsatz_id']
    );
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
    $attachment = estab_attachment_find_for_incident(
        $connection,
        $attachmentTable,
        $base,
        $incidentId,
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
    // When $forUpdate is true, the locked active-incident singleton already
    // serializes every supported message write. Do not additionally issue an
    // unindexed FOR UPDATE scan over the complete message table: lazy image
    // requests would otherwise lock and serialize thousands of unrelated
    // messages. The exact-token query returns only possible linked rows.
    $messageMap = estab_read_attachment_message_map_for_filenames(
        $connection,
        $messageTable,
        $incidentId,
        [$requestedFilename]
    );
    return estab_read_attachment_allowed(
        $selected,
        $attachment,
        $messageMap[$requestedFilename] ?? []
    ) ? $attachment : null;
}

/**
 * Resolve several attachment cards with one active scope and one message map.
 *
 * This preserves the exact same object rule as estab_read_attachment() while
 * avoiding a complete active-incident message scan for every card rendered on
 * one Nachrichtenvordruck.
 *
 * @return array<string,array<string,mixed>> keyed by canonical filename
 */
function estab_read_attachments(
    mysqli $connection,
    string $attachmentTable,
    string $messageTable,
    array $requestedFilenames,
    array $identity,
    mixed $expectedIncidentId = null,
    bool $forUpdate = false
): array {
    if (count($requestedFilenames) > 100) {
        throw new InvalidArgumentException('Zu viele Anhangreferenzen.');
    }
    $requested = [];
    foreach ($requestedFilenames as $requestedFilename) {
        if (!is_string($requestedFilename)) {
            throw new InvalidArgumentException('Ungültige Anhangreferenz.');
        }
        $requestedFilename = estab_file_validate_name(
            'attachment',
            $requestedFilename
        );
        if (isset($requested[$requestedFilename])) {
            throw new InvalidArgumentException('Doppelte Anhangreferenz.');
        }
        $requested[$requestedFilename] = true;
    }
    if ($requested === []) {
        return [];
    }

    $incident = estab_incident_require_active($connection, $forUpdate);
    $incidentId = (int) $incident['active_einsatz_id'];
    if (
        $expectedIncidentId !== null
        && estab_incident_positive_id($expectedIncidentId) !== $incidentId
    ) {
        throw new EstabIncidentConflictException(
            'Der aktive Einsatz hat sich geändert.'
        );
    }
    $selected = estab_read_require_identity_scope(
        $connection,
        $incidentId,
        $identity
    );
    $messageMap = estab_read_attachment_message_map(
        $connection,
        $messageTable,
        $incidentId,
        $forUpdate
    );
    $visible = [];
    foreach (array_keys($requested) as $requestedFilename) {
        $base = pathinfo($requestedFilename, PATHINFO_FILENAME);
        $extension = strtolower(
            pathinfo($requestedFilename, PATHINFO_EXTENSION)
        );
        $attachment = estab_attachment_find_for_incident(
            $connection,
            $attachmentTable,
            $base,
            $incidentId,
            $forUpdate
        );
        if (
            !is_array($attachment)
            || !hash_equals(
                strtolower((string) ($attachment['fileext'] ?? '')),
                $extension
            )
            || !estab_read_attachment_allowed(
                $selected,
                $attachment,
                $messageMap[$requestedFilename] ?? []
            )
        ) {
            continue;
        }
        $visible[$requestedFilename] = $attachment;
    }
    return $visible;
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
    $validated = [];
    foreach ($submitted as $filename) {
        try {
            $filename = estab_file_validate_name('attachment', $filename);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'Ungültige Anhangreferenz.',
                previous: $exception
            );
        }
        if (isset($validated[$filename])) {
            throw new InvalidArgumentException('Ungültige Anhangliste.');
        }
        $validated[$filename] = true;
    }
    $allowed = estab_read_attachments(
        $connection,
        $attachmentTable,
        $messageTable,
        array_keys($validated),
        $identity,
        $incidentId,
        true
    );
    if (count($allowed) !== count($validated)) {
        throw new EstabReadPermissionException(
            'Ein ausgewählter Anhang ist für diese Kontofunktion '
            . 'nicht freigegeben.'
        );
    }
}
