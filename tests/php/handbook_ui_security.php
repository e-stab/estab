<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$indexPath = $root . '/handbuch/index.php';
$scriptPath = $root . '/handbuch/handbuch.js';
$stylesheetPath = $root . '/handbuch/handbuch.css';

$assertions = 0;
$failures = [];
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$index = file_get_contents($indexPath);
$script = file_get_contents($scriptPath);
$stylesheet = file_get_contents($stylesheetPath);
$assert(is_string($index), 'handbook controller is unreadable');
$assert(is_string($script), 'handbook search script is unreadable');
$assert(is_string($stylesheet), 'handbook stylesheet is unreadable');

$index = is_string($index) ? $index : '';
$script = is_string($script) ? $script : '';
$stylesheet = is_string($stylesheet) ? $stylesheet : '';
$compactIndex = preg_replace('/\s+/u', ' ', $index);
$compactIndex = is_string($compactIndex) ? $compactIndex : '';
$markupWithoutPhp = preg_replace('/<\?(?:php|=).*?\?>/s', '', $index);
$markupWithoutPhp = is_string($markupWithoutPhp) ? $markupWithoutPhp : '';
$markupText = preg_replace('/<[^>]+>/s', ' ', $markupWithoutPhp);
$markupText = is_string($markupText) ? $markupText : '';
$visibleText = html_entity_decode(
    $markupText,
    ENT_QUOTES | ENT_HTML5,
    'UTF-8'
);
$visibleText = preg_replace('/\s+/u', ' ', $visibleText);
$visibleText = is_string($visibleText) ? trim($visibleText) : '';

/*
 * The handbook is a public, read-only service. It still starts the shared
 * session UI so an existing eStab identity and its logout action are visible,
 * but it may neither require an application login nor accept a mutation.
 */
$methodGuardPosition = strpos($index, '$requestMethod =');
$sessionStartPosition = strpos($index, 'session_start();');
$assert(
    is_int($methodGuardPosition)
        && is_int($sessionStartPosition)
        && $methodGuardPosition < $sessionStartPosition,
    'request-method guard must run before the session is opened'
);
$assert(
    str_contains($index, "in_array(\$requestMethod, ['GET', 'HEAD'], true)")
        && str_contains($index, "header('Allow: GET, HEAD')")
        && str_contains($index, 'http_response_code(405)')
        && str_contains(
            $index,
            'Für das Web-Handbuch sind nur GET und HEAD erlaubt.'
        ),
    'handbook does not fail closed with a documented GET/HEAD-only contract'
);
$assert(
    substr_count($index, 'session_start();') === 1
        && substr_count($index, "require_once __DIR__ . '/../app/session_ui.php'") === 1
        && substr_count($index, 'estab_session_ui_start($_SESSION);') === 1,
    'handbook is not integrated exactly once with the shared session-aware UI'
);
$assert(
    str_contains($index, "header('Cache-Control: private, no-store, max-age=0')")
        && str_contains($index, "header('Vary: Cookie')")
        && str_contains($index, "header('Content-Type: text/html; charset=UTF-8')"),
    'session-aware handbook responses lack private caching or UTF-8 headers'
);
$assert(
    !preg_match('/\$_(?:POST|PUT|PATCH|DELETE|FILES)\b/', $index)
        && !preg_match('/<form\b/i', $index)
        && !preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO|FROM|[A-Za-z_])/i', $index),
    'public handbook unexpectedly contains a mutating request or data path'
);
$assert(
    !str_contains($index, 'estab_auth_require')
        && !str_contains($index, 'estab_navigation_require')
        && !preg_match('/header\s*\(\s*[\'\"]Location:/i', $index),
    'public handbook introduced an authentication wall or redirect'
);

/* All application targets must be centrally built and escaped at output. */
$expectedRoutes = [
    'messages' => '4fach/index.php',
    'command_post' => '4fach/fuehrungsstelle.php',
    'messenger_jobs' => '4fach/melderauftraege.php',
    'message_overview' => '4fueltg/ue_ltg.php',
    'forms' => '4fach/vordrucke.php',
    'attachments' => '4fach/anhang.php',
    'etb' => 'stabetb/etb.php',
    'ttb' => 'fmtbb/tbb.php',
    'tracking' => '4fach/nachwea.php?nwalle',
    'bos_info' => 'stabinfo/index.php',
    'admin' => '4fadm/admin.php',
    'incidents' => '4fadm/incidents.php',
    'users' => '4fadm/users.php',
    'self_registration' => '4fadm/self_registration.php',
    'password_policy' => '4fadm/password_policy.php',
    'admin_command_post' => '4fadm/fuehrungsstelle.php',
    'matrix' => '4fadm/make_fkt.php',
    'incident_pdf' => '4fadm/incident_export.php',
    'exports' => '4fadm/export.php',
    'system_status' => '4fadm/system_status.php',
    'counter' => '4fadm/set_number_after_crash.php',
    'form_reset' => '4fach/resetpic.php',
    'health' => 'health.php',
];
$assert(
    str_contains($compactIndex, "'home' => estab_application_root()")
        && str_contains($compactIndex, "'login' => estab_navigation_login_url()")
        && substr_count($index, 'estab_application_url(') === count($expectedRoutes),
    'handbook root, login or application route construction changed'
);
foreach ($expectedRoutes as $key => $path) {
    $pattern = '/[\'\"]' . preg_quote($key, '/') . '[\'\"]\s*=>\s*'
        . 'estab_application_url\(\s*[\'\"]'
        . preg_quote($path, '/') . '[\'\"]\s*\)/';
    $assert(
        preg_match($pattern, $index) === 1,
        'handbook route is missing or not helper-built: ' . $key
    );
}
$assert(
    preg_match(
        '/\$href\s*=\s*static\s+fn\s*\(string\s+\$key\)\s*:\s*string\s*=>\s*'
            . 'estab_auth_html\(\$routes\[\$key\]\)/',
        $index
    ) === 1,
    'handbook route output is not centralized through HTML escaping'
);
$linkKeyMatches = [];
preg_match_all('/\$href\(\s*[\'\"]([a-z_]+)[\'\"]\s*\)/', $index, $linkKeyMatches);
$usedLinkKeys = array_values(array_unique($linkKeyMatches[1] ?? []));
$knownLinkKeys = array_merge(['home', 'login'], array_keys($expectedRoutes));
$unknownLinkKeys = array_values(array_diff($usedLinkKeys, $knownLinkKeys));
$assert(
    $usedLinkKeys !== [] && $unknownLinkKeys === [],
    'handbook renders an unknown or dynamically selected route key'
);
$assert(
    !preg_match('/href\s*=\s*[\'\"]<\?=\s*\$routes\b/i', $index)
        && !preg_match('/href\s*=\s*[\'\"]<\?=\s*estab_application_/i', $index)
        && !preg_match('/estab_application_url\(\s*\$/', $index)
        && !str_contains($index, 'javascript:'),
    'handbook bypasses its escaped, literal application-link helper'
);

/* Search semantics, landmarks and the exact current chapter contract. */
/*
 * Die Kapitel des Handbuchs, in ihrer Reihenfolge.
 *
 * Sie sind neu geschnitten: Der Nachrichtenlauf stand als ein Kapitel da und
 * beschrieb zwei Ablaeufe, die verschiedene Personen betreffen -- jetzt sind
 * es "Nachricht ausgeben" und "Nachricht aufnehmen". Aus "Uebergabe und
 * Abschluss" wurden "Schichten" und "Einsatz abschliessen", weil das eine
 * eingerichtet und das andere beendet wird. Die Kurzreferenz war eine zweite
 * Fassung des Inhaltsverzeichnisses und ist zu "Begriffe" geworden.
 */
$expectedChapters = [
    'ueberblick',
    'anmelden',
    'bildschirm',
    'rollen',
    'vordruck',
    'ausgang',
    'eingang',
    'anlagen',
    'suchen',
    'etb',
    'ttb',
    'fernmeldeplan',
    'melder',
    'einsatz',
    'schichten',
    'abschluss',
    'ausgabe',
    'administration',
    'betrieb',
    'probleme',
    'begriffe',
];
$chapterMatches = [];
preg_match_all(
    '/<article\s+id="([a-z0-9_-]+)"\s+class="estab-handbook-chapter"\s+'
        . 'data-estab-handbook-section\b/s',
    $index,
    $chapterMatches
);
$assert(
    ($chapterMatches[1] ?? []) === $expectedChapters,
    'handbook no longer exposes the canonical 21 chapters in order'
);
$tocSource = '';
if (preg_match('/<ol\s+data-estab-handbook-toc>(.*?)<\/ol>/s', $index, $tocMatch) === 1) {
    $tocSource = $tocMatch[1];
}
$tocMatches = [];
preg_match_all('/<a\s+href="#([a-z0-9_-]+)">/', $tocSource, $tocMatches);
$assert(
    ($tocMatches[1] ?? []) === $expectedChapters,
    'table of contents does not match all 21 chapter anchors exactly'
);
$assert(
    str_contains($index, '<main id="handbook-content"')
        && str_contains($index, 'href="#handbook-content">Zum Handbuchinhalt</a>')
        && str_contains($index, '<nav aria-label="Handbuchkapitel">')
        && str_contains($index, '<html lang="de">'),
    'handbook lacks its semantic main, skip link, navigation label or language'
);
$assert(
    str_contains($index, 'id="handbook-search" type="search"')
        && str_contains($index, '<label for="handbook-search">')
        && str_contains($index, 'aria-controls="handbook-chapters"')
        && str_contains($index, 'data-estab-handbook-clear hidden')
        && str_contains($index, 'data-estab-handbook-empty hidden'),
    'handbook search is not labelled or does not expose controlled results'
);
$assert(
    str_contains($index, 'data-estab-handbook-status')
        && str_contains($index, 'role="status" aria-live="polite" aria-atomic="true"')
        && str_contains($index, '<button type="button" data-estab-handbook-clear'),
    'handbook search result count is not announced accessibly'
);
$assert(
    str_contains($script, "document.querySelector('[data-estab-handbook-search]')")
        && str_contains($script, "document.querySelectorAll('[data-estab-handbook-section]')")
        && str_contains($script, ".normalize('NFD')")
        && str_contains($script, 'tokens.every(')
        && str_contains($script, 'section.hidden = !matches')
        && str_contains($script, 'status.textContent =')
        && str_contains($script, "initialQuery.slice(0, 200)"),
    'handbook search lost bounded, diacritic-tolerant AND filtering or status updates'
);
$assert(
    str_contains($script, "event.key === 'Escape'")
        && str_contains($script, "event.key === '/'")
        && str_contains($script, 'search.focus()')
        && str_contains($script, 'resetSearch(false)'),
    'handbook search lost its keyboard or hidden-anchor recovery behavior'
);

/* Bind the handbook to the currently implemented operational invariants. */
/*
 * Saetze, die im Handbuch stehen muessen.
 *
 * Sie halten die Beschreibung an der Anwendung fest: Wer eine Regel aendert,
 * merkt hier, dass das Handbuch sie noch anders erzaehlt. Der Wortlaut ist
 * einfacher geworden -- das Handbuch erklaert die Bedienung und preist
 * nichts an; "Autosichtung" und "append-only" waren Woerter, die auf keinem
 * Bildschirm stehen. Die Aussage ist dieselbe geblieben.
 */
$requiredCurrentStatements = [
    'Ausgang: Verfasser → Si → LdF → Fernmelder → abgeschlossen',
    'Eingang: Fernmelder → LdF → Si → Empfänger',
    'Der Fernmelder darf den Absender nicht schreiben.',
    'Der Name der Führungsstelle gehört zum Einsatz',
    'Eine automatische Sichtung gibt es nicht.',
    'Wer 15 Minuten lang nichts tippt und nichts anklickt, wird als inaktiv angezeigt.',
    'Nach 12 Stunden ohne Aktivität endet die Sitzung.',
    'Der Plan hält die eigenen Kommunikationsmittel fest.',
    'Die Melderaufträge stehen auf einer eigenen Seite.',
    'Das Technische Betriebsbuch führt der LdF.',
    'Einträge werden niemals überschrieben oder gelöscht.',
    'Auch im TBB wird nichts gelöscht.',
    'Deaktivieren ist nicht abschließen',
    'Benutzer',
    'sperren, entsperren und Kennwort zurücksetzen',
    'Neun Bereiche sind wählbar',
    'PDF-Anlagen Seite für Seite',
    'Jedes Original liegt bytegleich im Dossier',
    'Export ist kein Backup',
    'keine digitale Signatur',
];
foreach ($requiredCurrentStatements as $statement) {
    $assert(
        str_contains($visibleText, $statement),
        'current handbook invariant is missing: ' . $statement
    );
}

/* Old 2011 instructions must survive only in the legacy archive, never here. */
$obsoletePatterns = [
    '/\bXAMPP?\b/i' => 'XAMPP installation',
    '/\bv0\.9\.20\b/i' => 'v0.9.20 version',
    '#(?:^|[\s"\'])/kats(?:/|\b)#i' => '/kats installation path',
    '/xampp-win32/i' => 'obsolete Windows installer',
    '/localhost\/kats/i' => 'obsolete localhost redirect',
    '/Datenbankparameter\s+eingeben/i' => 'browser database credentials',
    '/Einsatz\s+bzw\.\s*Datenbankname/i' => 'database-per-incident model',
    '/upload\.php\s+Zeile\s+\d+/i' => 'source-edit upload configuration',
    '/Handbuch_eStab\.pdf/i' => 'historic PDF as current handbook',
    '/memory_limit\s*=\s*128M/i' => 'obsolete php.ini memory setting',
    '/upload_max_filesize\s*=\s*128M/i' => 'obsolete php.ini upload setting',
    '/Benutzer\s+[„"\']?root[“"\']?\s+mit\s+(?:dem\s+)?leer/i'
        => 'empty database root password',
];
foreach ($obsoletePatterns as $pattern => $description) {
    $assert(
        preg_match($pattern, $index) !== 1,
        'current web handbook contains obsolete guidance: ' . $description
    );
}

/* The client-side filter may only reveal existing text nodes and elements. */
$forbiddenScriptPatterns = [
    '/\binnerHTML\b/i' => 'innerHTML',
    '/\bouterHTML\b/i' => 'outerHTML',
    '/\binsertAdjacentHTML\b/i' => 'insertAdjacentHTML',
    '/\bdocument\.write\b/i' => 'document.write',
    '/(?:^|[^A-Za-z0-9_$])eval\s*\(/i' => 'eval',
    '/(?:^|[^A-Za-z0-9_$])fetch\s*\(/i' => 'fetch',
    '/\bXMLHttpRequest\b/' => 'XMLHttpRequest',
    '/\bnew\s+Function\b/' => 'Function constructor',
];
foreach ($forbiddenScriptPatterns as $pattern => $description) {
    $assert(
        preg_match($pattern, $script) !== 1,
        'handbook search script uses forbidden DOM/network execution: '
            . $description
    );
}
$assert(
    substr_count($index, '<script') === 1
        && str_contains($index, '<script src="./handbuch.js" defer></script>')
        && !preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $index)
        && str_contains($script, "'use strict'")
        && str_contains($script, 'section.textContent')
        && str_contains($script, 'root.toggleAttribute('),
    'handbook does not use one deferred, non-inline, text-only enhancement script'
);

/* Responsive, keyboard and paper presentation are part of the UI contract. */
$assert(
    str_contains($index, '<meta name="viewport" content="width=device-width, initial-scale=1">')
        && str_contains($index, '<link rel="stylesheet" href="./handbuch.css">'),
    'handbook lacks its responsive viewport or dedicated stylesheet'
);
foreach ([
    '@media (max-width: 64rem)',
    '@media (max-width: 48rem)',
    '@media (max-width: 32rem)',
    '@media (prefers-reduced-motion: reduce)',
    '@media print',
    '.estab-handbook-page a:focus-visible',
    '.estab-handbook-search-row button',
    '.estab-handbook-chapter[hidden]',
    '.estab-handbook-table-wrap table',
    '.estab-handbook-troubleshooting details > *',
] as $requiredCssContract) {
    $assert(
        str_contains($stylesheet, $requiredCssContract),
        'handbook stylesheet is missing: ' . $requiredCssContract
    );
}
$assert(
    str_contains($stylesheet, 'grid-template-columns: minmax(0, 1fr)')
        && str_contains($stylesheet, 'overflow-wrap: anywhere')
        && str_contains($stylesheet, 'break-inside: avoid')
        && str_contains($stylesheet, '.estab-session-bar,')
        && str_contains($stylesheet, 'display: none !important'),
    'handbook lacks narrow-screen reflow, long-token handling or clean print rules'
);

if ($failures !== []) {
    fwrite(
        STDERR,
        "handbook UI security test failed:\n- "
            . implode("\n- ", $failures)
            . "\n"
    );
    exit(1);
}

echo "handbook UI security: OK ({$assertions} assertions)\n";
