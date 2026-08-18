<?php

declare(strict_types=1);

/**
 * The search of the staff reading list must search, not match everything.
 *
 * The legacy list built its LIKE pattern by wrapping the raw session value in
 * percent signs. A percent sign or underscore the operator typed then acted as
 * a wildcard, and an empty term produced the pattern "%%", which matched every
 * message of the incident and silently switched off the read, done and
 * category filters that only apply while no search is active.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/message_list.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// Behaviour of the shared pattern builder.
$assert(
    estab_message_list_like_pattern('Nord') === '%Nord%',
    'A plain term no longer produces a containing pattern'
);
$assert(
    estab_message_list_like_pattern('100%') === '%100!%%',
    'A percent sign typed by the operator still acts as a wildcard'
);
$assert(
    estab_message_list_like_pattern('S_2') === '%S!_2%',
    'An underscore typed by the operator still matches any character'
);
$assert(
    estab_message_list_like_pattern('a!b') === '%a!!b%',
    'The escape character itself is not escaped and breaks the pattern'
);

$listSource = file_get_contents($root . '/4fach/liste.php');
if (!is_string($listSource)) {
    throw new RuntimeException('Could not read 4fach/liste.php');
}

// The legacy list must use the shared builder instead of its own concatenation.
$assert(
    !str_contains($listSource, '"%".(string) $_SESSION["flt_search"]."%"'),
    'The legacy search still concatenates its pattern from the raw session value'
);
$assert(
    str_contains($listSource, 'estab_message_list_like_pattern ($searchTerm)'),
    'The legacy search does not use the escaping pattern builder'
);

// Every LIKE of that search needs the escape clause, otherwise the escaping
// character is taken literally by the server.
$searchStart = strpos($listSource, 'if ($searchActive) {');
$assert($searchStart !== false, 'The search branch of the legacy list is gone');
$searchEnd = strpos($listSource, 'break;', (int) $searchStart);
$searchBlock = substr(
    $listSource,
    (int) $searchStart,
    (int) $searchEnd - (int) $searchStart
);
$likeCount = substr_count($searchBlock, 'LIKE ?');
$escapeCount = substr_count($searchBlock, "LIKE ? ESCAPE '!'");
$assert(
    $likeCount > 0 && $likeCount === $escapeCount,
    sprintf(
        'The legacy search has %d LIKE comparisons but only %d declare the'
            . ' escape character',
        $likeCount,
        $escapeCount
    )
);

// An empty term is no search.
$assert(
    !str_contains($listSource, '$searchActive = isset ($_SESSION["flt_search"]);'),
    'An empty search term still activates the search and matches everything'
);
$assert(
    str_contains($listSource, '$searchTerm = trim ((string) ($_SESSION["flt_search"] ?? ""));')
        && str_contains($listSource, '$searchActive = $searchTerm !== "";'),
    'The legacy list no longer distinguishes an empty term from a real search'
);

// The filters below must stay reachable while no search is running.
$assert(
    str_contains($listSource, 'if ($displayFilters && !$searchActive) {'),
    'The read, done and category filters no longer depend on the search state'
);

printf("legacy search: OK (%d assertions)\n", $assertions);
