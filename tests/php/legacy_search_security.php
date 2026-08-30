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

/*
 * Wo die Suche heute steht.
 *
 * Die alte Liste hatte eine eigene zweite Suche über Nachweis, Anschrift,
 * Abfasszeit, Inhalt und absendende Einheit. Sie ist gefallen: Ein
 * Suchbegriff schaltete die Filter darunter -- gelesen, erledigt,
 * Kategorien -- stillschweigend ab, und ihre Abfasszeit durchsuchte die
 * Datenbankform „2026-08-24 02:15:26", während auf dem Bildschirm
 * „240215" steht. Gesucht wird jetzt im Tabellenbauteil und in
 * app/message_list.php.
 *
 * Die Anforderung ist dieselbe geblieben, nur ihr Ort nicht: Jeder
 * LIKE-Vergleich, der ein maskiertes Muster benutzt, muss sein
 * Fluchtzeichen auch erklären. Ohne die ESCAPE-Klausel nimmt der Server
 * das „!" wörtlich, und aus der Maskierung wird ein Suchbegriff.
 */
$suchQuelle = file_get_contents($root . '/app/message_list.php');
if (!is_string($suchQuelle)) {
    throw new RuntimeException('Could not read app/message_list.php');
}
$likeCount = substr_count($suchQuelle, 'LIKE ?');
$escapeCount = substr_count($suchQuelle, "LIKE ? ESCAPE '!'");
$assert(
    $likeCount > 0 && $likeCount === $escapeCount,
    sprintf(
        'Die Suche hat %d LIKE-Vergleiche, aber nur %d nennen ihr'
            . ' Fluchtzeichen.',
        $likeCount,
        $escapeCount
    )
);
$assert(
    substr_count($suchQuelle, '= estab_message_list_like_pattern(') === 2
        && !preg_match('~[\'"]%[\'"]\s*\.\s*\$~', $suchQuelle),
    'Die Suche baut ihr Muster nicht mehr über den maskierenden Bauer oder'
        . ' klebt wieder Prozentzeichen an einen Wert.'
);

/*
 * Und die Falle selbst ist fort: In der alten Liste hing die Erreichbarkeit
 * der Filter am Suchzustand.
 */
$listSource = file_get_contents($root . '/4fach/liste.php');
if (!is_string($listSource)) {
    throw new RuntimeException('Could not read 4fach/liste.php');
}
$assert(
    !str_contains($listSource, '$searchActive')
        && !str_contains($listSource, 'flt_search'),
    'Die alte zweite Suche ist zurück. Sie schaltete die Filter darunter'
        . ' stillschweigend ab.'
);

printf("legacy search: OK (%d assertions)\n", $assertions);
