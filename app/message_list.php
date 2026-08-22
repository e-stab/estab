<?php

declare(strict_types=1);

require_once __DIR__ . '/message_priority.php';

/**
 * Shared, renderer-independent filter boundary for incident message lists.
 *
 * Request values are deliberately kept separate from SQL identifiers. Every
 * selectable SQL branch below is fixed in this file and every user value is
 * returned as a prepared-statement parameter.
 */

/** @return array<string, int|string> */
function estab_message_list_default_filters(): array
{
    return [
        'q' => '',
        'direction' => '',
        'priority' => '',
        'status' => '',
        'read_state' => '',
        'done_state' => '',
        'from' => '',
        'to' => '',
        'recipient' => '',
        'sort' => 'priority_newest',
        'page' => 1,
        'page_size' => 50,
    ];
}

/** Validate the namespace used only for request-array keys. */
function estab_message_list_prefix(string $prefix): string
{
    if (
        strlen($prefix) < 1
        || strlen($prefix) > 32
        || preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $prefix) !== 1
    ) {
        throw new InvalidArgumentException('Invalid message-list prefix');
    }
    return $prefix;
}

/** Validate a fixed local SQL alias before interpolating it. */
function estab_message_list_alias(string $alias): string
{
    if (
        strlen($alias) < 1
        || strlen($alias) > 64
        || preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $alias) !== 1
    ) {
        throw new InvalidArgumentException('Invalid message-list SQL alias');
    }
    return $alias;
}

/**
 * Return the canonical, incident-local TTB evidence number for one message.
 *
 * `nv_nachrichten.04_nummer` is a historical/internal message number and
 * `00_lfd` is a global technical key. Neither is a TTB evidence number. The
 * correlated subquery deliberately selects only the first entry whose type
 * is byte-exactly `nachricht` in the same incident.
 */
function estab_message_list_tbb_number_sql(string $alias = 'm'): string
{
    $alias = estab_message_list_alias($alias);
    return '(SELECT estab_tbb_proof.`estab_book_lfd`'
        . ' FROM `nv_tbb` AS estab_tbb_proof'
        . ' WHERE estab_tbb_proof.`einsatz_id` = '
        . $alias . '.`einsatz_id`'
        . ' AND estab_tbb_proof.`estab_message_id` = '
        . $alias . '.`00_lfd`'
        . " AND BINARY estab_tbb_proof.`estab_entry_type`"
        . " = BINARY 'nachricht'"
        . ' ORDER BY estab_tbb_proof.`estab_book_lfd`,'
        . ' estab_tbb_proof.`tbb_lfd-nr` LIMIT 1)';
}

/** Return the canonical TTB evidence number as the shared result alias. */
function estab_message_list_tbb_number_select_sql(string $alias = 'm'): string
{
    return estab_message_list_tbb_number_sql($alias)
        . ' AS `estab_tbb_book_lfd`';
}

/** Return a UTF-8 character count without accepting malformed input. */
function estab_message_list_text_length(string $value): int
{
    if (preg_match('//u', $value) !== 1) {
        return -1;
    }
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $matches = [];
    $count = preg_match_all('/./us', $value, $matches);
    return is_int($count) ? $count : -1;
}

/** Normalize surrounding Unicode whitespace and reject control/format data. */
function estab_message_list_query_value(mixed $value): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException('Invalid message-list search');
    }
    if (preg_match('/\p{C}/u', $value) === 1) {
        throw new InvalidArgumentException('Invalid message-list search');
    }
    $normalized = preg_replace(
        '/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u',
        '',
        $value
    );
    if (
        !is_string($normalized)
        || estab_message_list_text_length($normalized) > 120
    ) {
        throw new InvalidArgumentException('Invalid message-list search');
    }
    return $normalized;
}

/** @return list<string> */
function estab_message_list_allowed_recipients(array $allowedRecipients): array
{
    $allowed = [];
    foreach ($allowedRecipients as $recipient) {
        if (
            !is_string($recipient)
            || $recipient === ''
            || preg_match('//u', $recipient) !== 1
            || preg_match('/\p{C}/u', $recipient) === 1
            || estab_message_list_text_length($recipient) > 64
        ) {
            throw new InvalidArgumentException(
                'Invalid message-list recipient allowlist'
            );
        }
        if (!in_array($recipient, $allowed, true)) {
            $allowed[] = $recipient;
        }
    }
    return $allowed;
}

/** Parse one positive request integer without PHP's numeric coercions. */
function estab_message_list_positive_integer(mixed $value, string $field): int
{
    if (is_int($value)) {
        if ($value > 0) {
            return $value;
        }
        throw new InvalidArgumentException('Invalid message-list ' . $field);
    }
    if (
        !is_string($value)
        || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1
    ) {
        throw new InvalidArgumentException('Invalid message-list ' . $field);
    }
    $parsed = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
    );
    if (!is_int($parsed)) {
        throw new InvalidArgumentException('Invalid message-list ' . $field);
    }
    return $parsed;
}

/** Validate one canonical Gregorian calendar date. */
function estab_message_list_date(mixed $value, string $field): string
{
    if (!is_string($value) || $value === '') {
        if ($value === '') {
            return '';
        }
        throw new InvalidArgumentException('Invalid message-list ' . $field);
    }
    if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid message-list ' . $field);
    }
    $year = (int) substr($value, 0, 4);
    if ($year < 1000) {
        throw new InvalidArgumentException('Invalid message-list ' . $field);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date instanceof DateTimeImmutable
        || (
            is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0
                || ($errors['error_count'] ?? 0) > 0)
        )
        || $date->format('Y-m-d') !== $value
    ) {
        throw new InvalidArgumentException('Invalid message-list ' . $field);
    }
    return $value;
}

/**
 * Parse a complete request namespace. Missing keys receive safe defaults.
 *
 * @return array<string, int|string>
 */
function estab_message_list_parse_filters(
    array $input,
    array $allowedRecipients,
    string $prefix = 'ml_'
): array {
    $prefix = estab_message_list_prefix($prefix);
    $allowedRecipients = estab_message_list_allowed_recipients(
        $allowedRecipients
    );
    $defaults = estab_message_list_default_filters();

    $q = array_key_exists($prefix . 'q', $input)
        ? estab_message_list_query_value($input[$prefix . 'q'])
        : (string) $defaults['q'];

    $enum = static function (
        array $source,
        string $key,
        array $allowed,
        string $default
    ): string {
        if (!array_key_exists($key, $source)) {
            return $default;
        }
        $value = $source[$key];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                'Invalid message-list filter ' . $key
            );
        }
        return $value;
    };

    $direction = $enum(
        $input,
        $prefix . 'direction',
        ['', 'E', 'A'],
        (string) $defaults['direction']
    );
    $priority = $enum(
        $input,
        $prefix . 'priority',
        ['', 'none', 'sss', 'bbb', 'aaa'],
        (string) $defaults['priority']
    );
    $status = $enum(
        $input,
        $prefix . 'status',
        ['', 'draft', 'ldf', 'transport', 'review', 'done', 'returned'],
        (string) $defaults['status']
    );
    $readState = $enum(
        $input,
        $prefix . 'read_state',
        ['', 'unread', 'read'],
        (string) $defaults['read_state']
    );
    $doneState = $enum(
        $input,
        $prefix . 'done_state',
        ['', 'open', 'done'],
        (string) $defaults['done_state']
    );
    $sort = $enum(
        $input,
        $prefix . 'sort',
        ['priority_newest', 'newest', 'oldest', 'number_desc', 'number_asc'],
        (string) $defaults['sort']
    );

    $from = array_key_exists($prefix . 'from', $input)
        ? estab_message_list_date($input[$prefix . 'from'], 'from date')
        : (string) $defaults['from'];
    $to = array_key_exists($prefix . 'to', $input)
        ? estab_message_list_date($input[$prefix . 'to'], 'to date')
        : (string) $defaults['to'];
    if ($from !== '' && $to !== '' && $from > $to) {
        throw new InvalidArgumentException('Invalid message-list date range');
    }

    $recipient = $enum(
        $input,
        $prefix . 'recipient',
        array_merge([''], $allowedRecipients),
        (string) $defaults['recipient']
    );

    $page = array_key_exists($prefix . 'page', $input)
        ? estab_message_list_positive_integer(
            $input[$prefix . 'page'],
            'page'
        )
        : (int) $defaults['page'];
    $pageSize = array_key_exists($prefix . 'page_size', $input)
        ? estab_message_list_positive_integer(
            $input[$prefix . 'page_size'],
            'page size'
        )
        : (int) $defaults['page_size'];
    if (!in_array($pageSize, [25, 50, 100], true)) {
        throw new InvalidArgumentException('Invalid message-list page size');
    }

    return [
        'q' => $q,
        'direction' => $direction,
        'priority' => $priority,
        'status' => $status,
        'read_state' => $readState,
        'done_state' => $doneState,
        'from' => $from,
        'to' => $to,
        'recipient' => $recipient,
        'sort' => $sort,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

/** Convert a canonical state back into the request namespace for validation. */
function estab_message_list_state_input(
    array $state,
    string $prefix
): array {
    $defaults = estab_message_list_default_filters();
    $state = array_replace($defaults, array_intersect_key($state, $defaults));
    $input = [];
    foreach ($defaults as $field => $_default) {
        $input[$prefix . $field] = $state[$field];
    }
    return $input;
}

/**
 * Apply a partial request to current state without leaking pagination state.
 *
 * A request which changes any filter (including sort or page size) starts on
 * page one. A page-only request retains every filter. Reset always returns the
 * complete defaults.
 *
 * @return array<string, int|string>
 */
function estab_message_list_apply_request(
    array $current,
    array $request,
    array $allowedRecipients,
    string $prefix = 'ml_'
): array {
    $prefix = estab_message_list_prefix($prefix);
    $resetKey = $prefix . 'reset';
    if (array_key_exists($resetKey, $request)) {
        if (!is_string($request[$resetKey]) && !is_int($request[$resetKey])) {
            throw new InvalidArgumentException('Invalid message-list reset');
        }
        return estab_message_list_default_filters();
    }

    $allowedRecipients = estab_message_list_allowed_recipients(
        $allowedRecipients
    );
    $storedRecipient = $current['recipient'] ?? '';
    if (
        is_string($storedRecipient)
        && $storedRecipient !== ''
        && !in_array($storedRecipient, $allowedRecipients, true)
    ) {
        // Recipient functions are live configuration. A removed function must
        // not trap an otherwise valid session in a permanent 403 loop.
        $current['recipient'] = '';
        $current['page'] = 1;
    }
    $current = estab_message_list_parse_filters(
        estab_message_list_state_input($current, $prefix),
        $allowedRecipients,
        $prefix
    );

    $removeKey = $prefix . 'remove';
    if (array_key_exists($removeKey, $request)) {
        $removable = [
            'q', 'direction', 'priority', 'status',
            'read_state', 'done_state',
            'from', 'to', 'recipient',
        ];
        $field = $request[$removeKey];
        if (!is_string($field) || !in_array($field, $removable, true)) {
            throw new InvalidArgumentException(
                'Invalid message-list filter removal'
            );
        }
        $current[$field] = estab_message_list_default_filters()[$field];
        $current['page'] = 1;
        return $current;
    }

    $defaults = estab_message_list_default_filters();
    $candidateInput = estab_message_list_state_input($current, $prefix);
    $recognizedRequest = false;
    foreach ($defaults as $field => $_default) {
        $key = $prefix . $field;
        if (array_key_exists($key, $request)) {
            $candidateInput[$key] = $request[$key];
            $recognizedRequest = true;
        }
    }
    if (!$recognizedRequest) {
        return $current;
    }

    $candidate = estab_message_list_parse_filters(
        $candidateInput,
        $allowedRecipients,
        $prefix
    );
    $filterChanged = false;
    foreach (array_keys($defaults) as $field) {
        if ($field === 'page') {
            continue;
        }
        if ($candidate[$field] !== $current[$field]) {
            $filterChanged = true;
            break;
        }
    }
    if ($filterChanged) {
        $candidate['page'] = 1;
    }
    return $candidate;
}

/** Escape a value for a literal LIKE pattern using `!` as escape character. */
function estab_message_list_like_pattern(string $value): string
{
    return '%' . str_replace(
        ['!', '%', '_'],
        ['!!', '!%', '!_'],
        $value
    ) . '%';
}

/**
 * Build a conservative MariaDB Boolean-mode prefix query.
 *
 * Only Unicode letters and decimal numbers are admitted. This excludes every
 * Boolean-mode operator instead of trying to reproduce the server parser.
 * Short tokens deliberately fall back to literal LIKE search because the
 * canonical InnoDB full-text index does not index terms below three
 * characters.
 */
function estab_message_list_boolean_prefix_query(string $value): ?string
{
    $words = estab_message_list_safe_search_words($value);
    if ($words === null) {
        return null;
    }
    $query = [];
    foreach ($words as $word) {
        if (estab_message_list_text_length($word) < 3) {
            return null;
        }
        $query[] = '+' . $word . '*';
    }
    return implode(' ', $query);
}

/**
 * Split an operator-free search into Unicode letter/number terms.
 *
 * Punctuation deliberately returns null so values such as radio call signs
 * containing `+`, `/` or quotes retain literal phrase semantics.
 *
 * @return ?list<string>
 */
function estab_message_list_safe_search_words(string $value): ?array
{
    $words = preg_split(
        '/[\p{Z}\s]+/u',
        $value,
        -1,
        PREG_SPLIT_NO_EMPTY
    );
    if (!is_array($words) || $words === []) {
        return null;
    }
    foreach ($words as $word) {
        if (preg_match('/\A[\p{L}\p{N}]+\z/u', $word) !== 1) {
            return null;
        }
    }
    return array_values($words);
}

/** Return one canonical decimal for exact numeric lookup, or null for text. */
function estab_message_list_exact_number(string $value): int|string|null
{
    if (preg_match('/\A[0-9]+\z/D', $value) !== 1) {
        return null;
    }
    $canonical = ltrim($value, '0');
    $canonical = $canonical === '' ? '0' : $canonical;
    $maximum = (string) PHP_INT_MAX;
    if (
        strlen($canonical) > strlen($maximum)
        || (
            strlen($canonical) === strlen($maximum)
            && strcmp($canonical, $maximum) > 0
        )
    ) {
        // BIGINT cannot contain this value, but retaining an exact bound
        // predicate keeps numeric search semantics unambiguous without an
        // overflowing PHP cast.
        return $canonical;
    }
    return (int) $canonical;
}

/**
 * Build fixed filter clauses and their prepared-statement values.
 *
 * The returned SQL has no leading `WHERE` or `AND`; an empty filter returns an
 * empty string. Callers prepend their mandatory incident/authorisation scope.
 *
 * @return array{sql:string,params:list<int|string>}
 */
function estab_message_list_filter_sql(
    array $filters,
    string $alias = 'm',
    array $stateTables = []
): array {
    $alias = estab_message_list_alias($alias);
    $requestedRecipient = $filters['recipient'] ?? '';
    $internalRecipients = is_string($requestedRecipient)
        && $requestedRecipient !== ''
        ? [$requestedRecipient]
        : ['__estab_unused_recipient__'];
    $filters = estab_message_list_parse_filters(
        estab_message_list_state_input($filters, 'ml_'),
        $internalRecipients,
        'ml_'
    );
    $column = static fn (string $name): string =>
        $alias . '.`' . $name . '`';
    $where = [];
    $parameters = [];

    if ($filters['direction'] !== '') {
        $where[] = $column('04_richtung') . ' = ?';
        $parameters[] = $filters['direction'];
    }
    if ($filters['priority'] === 'none') {
        $where[] = '(' . $column('09_vorrangstufe')
            . ' = ? OR ' . $column('09_vorrangstufe') . ' = ?)';
        $parameters[] = '';
        $parameters[] = 'eee';
    } elseif ($filters['priority'] !== '') {
        $where[] = $column('09_vorrangstufe') . ' = ?';
        $parameters[] = $filters['priority'];
    }

    $statusValues = [
        'draft' => 0,
        'ldf' => 1,
        'transport' => 2,
        'review' => 4,
        'done' => 8,
        'returned' => 10,
    ];
    if ($filters['status'] !== '') {
        $where[] = $column('x00_status') . ' = ?';
        $parameters[] = $statusValues[$filters['status']];
    }
    if ($filters['read_state'] !== '') {
        $where[] = estab_message_list_state_exists_sql(
            $stateTables,
            'read',
            $alias,
            $filters['read_state'] === 'read'
        );
    }
    if ($filters['done_state'] !== '') {
        $where[] = estab_message_list_state_exists_sql(
            $stateTables,
            'done',
            $alias,
            $filters['done_state'] === 'done'
        );
    }
    if ($filters['from'] !== '') {
        $where[] = $column('12_abfzeit') . ' >= ?';
        $parameters[] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        if ($filters['to'] === '9999-12-31') {
            $where[] = $column('12_abfzeit') . ' <= ?';
            $parameters[] = '9999-12-31 23:59:59';
        } else {
            $exclusiveTo = (new DateTimeImmutable($filters['to']))
                ->modify('+1 day')
                ->format('Y-m-d 00:00:00');
            $where[] = $column('12_abfzeit') . ' < ?';
            $parameters[] = $exclusiveTo;
        }
    }
    if ($filters['recipient'] !== '') {
        $where[] = $column('16_empf') . ' REGEXP ?';
        $recipient = preg_quote((string) $filters['recipient'], '/');
        $parameters[] = '(^|,)[[:space:]]*(alle|' . $recipient
            . ')(_[^,[:space:]]+)?[[:space:]]*(,|$)';
    }

    if ($filters['q'] !== '') {
        $queryValue = (string) $filters['q'];
        $searchColumns = [
            '05_gegenstelle',
            '10_anschrift',
            '11_rufnummer',
            '12_betreff',
            '12_inhalt',
            '13_abseinheit',
            '14_funktion',
        ];
        $search = [];
        $exactNumber = estab_message_list_exact_number(
            $queryValue
        );
        if ($exactNumber !== null) {
            $search[] = estab_message_list_tbb_number_sql($alias) . ' = ?';
            $parameters[] = $exactNumber;
        }
        $fulltextColumns = implode(',', array_map(
            $column,
            $searchColumns
        ));
        $safeWords = estab_message_list_safe_search_words($queryValue);
        if ($safeWords !== null) {
            $longWords = [];
            $shortWords = [];
            foreach ($safeWords as $word) {
                if (estab_message_list_text_length($word) >= 3) {
                    $longWords[] = $word;
                } else {
                    $shortWords[] = $word;
                }
            }
            $termGroups = [];
            if ($longWords !== []) {
                $termGroups[] = 'MATCH(' . $fulltextColumns
                    . ') AGAINST (? IN BOOLEAN MODE)';
                $parameters[] = implode(' ', array_map(
                    static fn (string $word): string => '+' . $word . '*',
                    $longWords
                ));
            }
            foreach ($shortWords as $shortWord) {
                $shortSearch = [];
                $pattern = estab_message_list_like_pattern($shortWord);
                foreach ($searchColumns as $searchColumn) {
                    $shortSearch[] = $column($searchColumn)
                        . " LIKE ? ESCAPE '!'";
                    $parameters[] = $pattern;
                }
                $termGroups[] = '(' . implode(' OR ', $shortSearch) . ')';
            }
            $search[] = count($termGroups) === 1
                ? $termGroups[0]
                : '(' . implode(' AND ', $termGroups) . ')';
        } else {
            $pattern = estab_message_list_like_pattern($queryValue);
            $literalSearch = [];
            foreach ($searchColumns as $searchColumn) {
                $literalSearch[] = $column($searchColumn)
                    . " LIKE ? ESCAPE '!'";
                $parameters[] = $pattern;
            }
            $search[] = '(' . implode(' OR ', $literalSearch) . ')';
        }
        $where[] = '(' . implode(' OR ', $search) . ')';
    }

    return [
        'sql' => implode(' AND ', $where),
        'params' => $parameters,
    ];
}

/** Return one fixed stable ORDER BY expression without the `ORDER BY` keyword. */
function estab_message_list_order_sql(
    array $filters,
    string $alias = 'm'
): string {
    $alias = estab_message_list_alias($alias);
    $sort = $filters['sort'] ?? null;
    if (!is_string($sort)) {
        throw new InvalidArgumentException('Invalid message-list sort');
    }
    $column = static fn (string $name): string =>
        $alias . '.`' . $name . '`';
    $id = $column('00_lfd');
    $tbbNumber = estab_message_list_tbb_number_sql($alias);
    $priorityOrder = str_replace(
        '`09_vorrangstufe`',
        $column('09_vorrangstufe'),
        estab_message_priority_order_sql('`09_vorrangstufe`')
    );
    return match ($sort) {
        'priority_newest' =>
            $priorityOrder . ' DESC, '
                . $column('12_abfzeit') . ' DESC, ' . $id . ' DESC',
        'newest' => $column('12_abfzeit') . ' DESC, ' . $id . ' DESC',
        'oldest' => $column('12_abfzeit') . ' IS NULL ASC, '
            . $column('12_abfzeit') . ' ASC, ' . $id . ' ASC',
        'number_desc' => 'COALESCE(' . $tbbNumber . ', 0) DESC, '
            . $id . ' DESC',
        'number_asc' => 'COALESCE(' . $tbbNumber . ', 4294967296) ASC, '
            . $id . ' ASC',
        default => throw new InvalidArgumentException(
            'Invalid message-list sort'
        ),
    };
}

/**
 * Clamp a validated page to the result set and provide display boundaries.
 *
 * @return array{
 *   count:int,page:int,page_size:int,page_count:int,offset:int,
 *   first:int,last:int,has_previous:bool,has_next:bool
 * }
 */
function estab_message_list_page_window(int $count, array $filters): array
{
    if ($count < 0) {
        throw new InvalidArgumentException('Invalid message-list count');
    }
    $page = estab_message_list_positive_integer(
        $filters['page'] ?? null,
        'page'
    );
    $pageSize = estab_message_list_positive_integer(
        $filters['page_size'] ?? null,
        'page size'
    );
    if (!in_array($pageSize, [25, 50, 100], true)) {
        throw new InvalidArgumentException('Invalid message-list page size');
    }

    $pageCount = $count === 0
        ? 0
        : intdiv($count - 1, $pageSize) + 1;
    if ($pageCount > 0) {
        $page = min($page, $pageCount);
    } else {
        $page = 1;
    }
    $offset = ($page - 1) * $pageSize;
    return [
        'count' => $count,
        'page' => $page,
        'page_size' => $pageSize,
        'page_count' => $pageCount,
        'offset' => $offset,
        'first' => $count === 0 ? 0 : $offset + 1,
        'last' => $count === 0 ? 0 : min($count, $offset + $pageSize),
        'has_previous' => $pageCount > 0 && $page > 1,
        'has_next' => $pageCount > 0 && $page < $pageCount,
    ];
}

/**
 * Validate one caller-supplied per-identity state table name.
 *
 * Read and done tables are derived from the signed-in identity, never from a
 * request. Checking them here keeps this file free of a database dependency
 * while refusing everything that is not a bare table identifier.
 */
function estab_message_list_state_table(
    array $stateTables,
    string $kind
): string {
    if (!in_array($kind, ['read', 'done'], true)) {
        throw new InvalidArgumentException('Invalid message-list state kind');
    }
    $table = $stateTables[$kind] ?? null;
    if (
        !is_string($table)
        || strlen($table) > 64
        || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $table) !== 1
    ) {
        throw new InvalidArgumentException(
            'Invalid message-list state table'
        );
    }
    return '`' . $table . '`';
}

/** Whether both per-identity state tables are available for this caller. */
function estab_message_list_has_state_tables(array $stateTables): bool
{
    try {
        estab_message_list_state_table($stateTables, 'read');
        estab_message_list_state_table($stateTables, 'done');
    } catch (InvalidArgumentException) {
        return false;
    }
    return true;
}

/** Build one EXISTS/NOT EXISTS predicate against a per-identity state table. */
function estab_message_list_state_exists_sql(
    array $stateTables,
    string $kind,
    string $alias,
    bool $present
): string {
    $alias = estab_message_list_alias($alias);
    $table = estab_message_list_state_table($stateTables, $kind);
    $stateAlias = 'estab_' . $kind . '_state';
    return ($present ? 'EXISTS' : 'NOT EXISTS')
        . ' (SELECT 1 FROM ' . $table . ' AS ' . $stateAlias
        . ' WHERE ' . $stateAlias . '.`nachnum` = '
        . $alias . '.`00_lfd`)';
}

/**
 * Expose both per-identity states as fixed result columns.
 *
 * Callers without those tables receive an empty string and their list shows
 * the column as not maintained instead of guessing a table name.
 */
function estab_message_list_state_select_sql(
    array $stateTables,
    string $alias = 'm'
): string {
    if (!estab_message_list_has_state_tables($stateTables)) {
        return '';
    }
    return estab_message_list_state_exists_sql(
        $stateTables,
        'read',
        $alias,
        true
    ) . ' AS `estab_state_read`, '
        . estab_message_list_state_exists_sql(
            $stateTables,
            'done',
            $alias,
            true
        ) . ' AS `estab_state_done`';
}

/**
 * Seconds the message has been resting at its current workflow station.
 *
 * `nv_nachrichten_nachweiskopf`.`updated_at` carries the recorded_at value of
 * the last workflow event, written by the database trigger of migration 80.
 * Both ends of the difference are therefore database time, exactly like
 * estab_message_timeline_duration_seconds(), and never a browser clock.
 */
function estab_message_list_dwell_seconds_sql(string $alias = 'm'): string
{
    $alias = estab_message_list_alias($alias);
    return '(SELECT TIMESTAMPDIFF(SECOND, estab_dwell_head.`updated_at`,'
        . ' NOW(6)) FROM `nv_nachrichten_nachweiskopf` AS estab_dwell_head'
        . ' WHERE estab_dwell_head.`message_id` = ' . $alias . '.`00_lfd`'
        . ' AND estab_dwell_head.`einsatz_id` = '
        . $alias . '.`einsatz_id`)';
}

/** Return the dwell time as the shared result alias. */
function estab_message_list_dwell_select_sql(string $alias = 'm'): string
{
    return estab_message_list_dwell_seconds_sql($alias)
        . ' AS `estab_dwell_seconds`';
}

/** Upper bound for a configured threshold: 30 days. */
const ESTAB_MESSAGE_LIST_DWELL_MAX_THRESHOLD = 2592000;

/**
 * The single table of dwell deadlines, in seconds, per priority.
 *
 * Overdue is a property of the priority: Staatsnot and Blitz tolerate far
 * less waiting than routine traffic. The values live here once instead of
 * being spread over the views that display them.
 *
 * @return array<string, array{warn:int,overdue:int}>
 */
function estab_message_list_dwell_default_thresholds(): array
{
    return [
        'aaa' => ['warn' => 300, 'overdue' => 600],
        'bbb' => ['warn' => 600, 'overdue' => 1200],
        'sss' => ['warn' => 1800, 'overdue' => 3600],
        'eee' => ['warn' => 7200, 'overdue' => 14400],
        '' => ['warn' => 7200, 'overdue' => 14400],
    ];
}

/** Accept one configured threshold, or null for everything unusable. */
function estab_message_list_dwell_threshold_value(mixed $value): ?int
{
    if (is_int($value)) {
        $parsed = $value;
    } elseif (
        is_string($value)
        && preg_match('/\A[1-9][0-9]{0,7}\z/D', $value) === 1
    ) {
        $parsed = (int) $value;
    } else {
        return null;
    }
    return $parsed >= 60
        && $parsed <= ESTAB_MESSAGE_LIST_DWELL_MAX_THRESHOLD
        ? $parsed
        : null;
}

/**
 * Merge the configured deadlines over the documented defaults.
 *
 * The deployment configures `$conf_4f['verweildauer']`. An entry which is not
 * a usable pair keeps the documented default for that priority, so a broken
 * configuration can never silently switch the overdue warning off.
 *
 * @return array<string, array{warn:int,overdue:int}>
 */
function estab_message_list_dwell_thresholds(
    mixed $configuration = null
): array {
    $thresholds = estab_message_list_dwell_default_thresholds();
    if ($configuration === null) {
        $configuration = is_array($GLOBALS['conf_4f'] ?? null)
            ? ($GLOBALS['conf_4f']['verweildauer'] ?? null)
            : null;
    }
    if (!is_array($configuration)) {
        return $thresholds;
    }
    foreach ($thresholds as $priority => $default) {
        $candidate = $configuration[$priority] ?? null;
        if (!is_array($candidate)) {
            continue;
        }
        $warn = estab_message_list_dwell_threshold_value(
            $candidate['warn'] ?? null
        );
        $overdue = estab_message_list_dwell_threshold_value(
            $candidate['overdue'] ?? null
        );
        if ($warn === null || $overdue === null || $warn > $overdue) {
            continue;
        }
        $thresholds[$priority] = ['warn' => $warn, 'overdue' => $overdue];
    }
    return $thresholds;
}

/** Return the deadlines that apply to one stored priority value. */
function estab_message_list_dwell_limits(
    mixed $priority,
    mixed $configuration = null
): array {
    $thresholds = estab_message_list_dwell_thresholds($configuration);
    $key = estab_message_priority_storage_value($priority);
    // Malformed legacy data is not allowed to buy itself a longer deadline,
    // so it falls back to the routine entry that always exists.
    return $thresholds[$key ?? ''] ?? $thresholds[''];
}

/** Parse the database-supplied dwell time without numeric coercion. */
function estab_message_list_dwell_seconds(mixed $value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }
    if (
        !is_string($value)
        || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) !== 1
    ) {
        return null;
    }
    return (int) $value;
}

/**
 * Verdict for one row: closed, unknown, ok, warn or overdue.
 *
 * A finished message no longer accumulates dwell pressure; a row without an
 * evidence head stays honestly unknown instead of pretending to be punctual.
 */
function estab_message_list_dwell_state(
    mixed $priority,
    mixed $seconds,
    mixed $status = null,
    mixed $configuration = null
): string {
    if (filter_var($status, FILTER_VALIDATE_INT) === 8) {
        return 'closed';
    }
    $seconds = estab_message_list_dwell_seconds($seconds);
    if ($seconds === null) {
        return 'unknown';
    }
    $limits = estab_message_list_dwell_limits($priority, $configuration);
    if ($seconds >= $limits['overdue']) {
        return 'overdue';
    }
    return $seconds >= $limits['warn'] ? 'warn' : 'ok';
}
