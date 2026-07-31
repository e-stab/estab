<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/incident_ui.php';
require_once __DIR__ . '/../../app/session_ui.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$none = estab_incident_ui_state_from_status([
    'active_einsatz_id' => null,
]);
$assert(
    $none['active'] === false && $none['incident'] === null,
    'No-active state was not preserved'
);
$noneMarkup = estab_incident_ui_markup($none);
$assert(
    str_contains($noneMarkup, 'data-estab-incident-state="none"')
        && str_contains($noneMarkup, 'Kein Einsatz aktiv')
        && str_contains($noneMarkup, 'Operative Eingaben sind gesperrt.')
        && str_contains($noneMarkup, '4fadm/incidents.php')
        && str_contains($noneMarkup, 'data-estab-incident-input-guard'),
    'No-active banner is incomplete'
);
$assert(
    str_contains(
        $noneMarkup,
        'form[data-estab-requires-incident]'
    )
        && str_contains($noneMarkup, 'controls[j].disabled=true')
        && !str_contains($noneMarkup, '.estab-session-logout'),
    'Incident UI guard is not scoped to explicit operational forms'
);

$active = estab_incident_ui_state_from_status([
    'active_einsatz_id' => 7,
    'kennung' => 'EL-2026-007',
    'name' => 'Sturm & Hochwasser',
    'fuehrungsstellenname' =>
        'FüSt "Nord" & <script>alert(1)</script>',
    'beginn' => '2026-07-29 12:34:00',
    'ort' => '<Leitstelle>',
]);
$activeMarkup = estab_incident_ui_markup($active, true, true);
$assert(
    str_contains($activeMarkup, 'data-estab-incident-state="active"')
        && str_contains($activeMarkup, 'data-estab-incident-id="7"')
        && str_contains($activeMarkup, 'EL-2026-007')
        && str_contains($activeMarkup, '29.07.2026 12:34')
        && str_contains($activeMarkup, '&lt;Leitstelle&gt;')
        && str_contains(
            $activeMarkup,
            'data-estab-command-post-name="FüSt &quot;Nord&quot; '
                . '&amp; &lt;script&gt;alert(1)&lt;/script&gt;"'
        )
        && str_contains(
            $activeMarkup,
            '<strong>FüSt &quot;Nord&quot; &amp; '
                . '&lt;script&gt;alert(1)&lt;/script&gt;</strong>'
        )
        && !str_contains($activeMarkup, '<Leitstelle>')
        && !str_contains($activeMarkup, '<script>alert(1)</script>')
        && !str_contains($activeMarkup, 'incident-input-guard'),
    'Active incident banner is incomplete or unsafe'
);
$assert(
    str_contains($activeMarkup, 'estab-incident-indicator-compact')
        && str_contains($activeMarkup, 'estab-incident-indicator-sidebar'),
    'Compact sidebar incident presentation is missing'
);

$incomplete = estab_incident_ui_state_from_status([
    'active_einsatz_id' => 8,
    'kennung' => 'LEGACY-IMPORT',
    'name' => 'Historischer Einsatz',
    'fuehrungsstellenname' => null,
    'beginn' => '2020-01-01 00:00:00',
    'ort' => '',
]);
$assert(
    $incomplete['active'] === true
        && array_key_exists(
            'fuehrungsstellenname',
            $incomplete['incident']
        )
        && $incomplete['incident']['fuehrungsstellenname'] === null,
    'historical NULL command-post state was replaced with invented data'
);
$incompleteMarkup = estab_incident_ui_markup($incomplete);
$assert(
    str_contains(
        $incompleteMarkup,
        'data-estab-incident-state="incomplete"'
    )
        && str_contains($incompleteMarkup, 'data-estab-incident-id="8"')
        && str_contains($incompleteMarkup, 'Führungsstellenname fehlt')
        && str_contains($incompleteMarkup, 'Name der Führungsstelle fehlt.')
        && str_contains($incompleteMarkup, 'Operative Eingaben sind gesperrt.')
        && str_contains($incompleteMarkup, '4fadm/incidents.php')
        && str_contains(
            $incompleteMarkup,
            'data-estab-incident-input-guard'
        )
        && !str_contains(
            $incompleteMarkup,
            'data-estab-incident-state="active"'
        ),
    'historical incomplete incident does not fail closed in the shared UI'
);

$unavailable = estab_incident_ui_markup([
    'availability' => 'unavailable',
    'active' => false,
    'incident' => null,
]);
$assert(
    str_contains($unavailable, 'data-estab-incident-state="unavailable"')
        && str_contains($unavailable, 'Bitte Datenbank und Migrationen prüfen.')
        && str_contains($unavailable, 'incident-input-guard'),
    'Unavailable state does not fail closed'
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [
    'benutzer' => 'Ada Beispiel',
    'kuerzel' => 'ada001',
    'funktion' => 'S2',
    'rolle' => 'Stab',
];
$sessionMarkup = estab_session_ui_current_markup(
    $_SESSION,
    false,
    null,
    false,
    false,
    true,
    true,
    $active
);
$assert(
    substr_count($sessionMarkup, 'data-estab-incident-state="active"') === 1
        && strpos($sessionMarkup, 'data-estab-incident-state')
            < strpos($sessionMarkup, 'data-estab-navigation'),
    'Session bar does not expose exactly one incident before navigation'
);
$withoutIncident = estab_session_ui_current_markup(
    $_SESSION,
    true,
    null,
    false,
    true,
    false,
    false,
    $active
);
$assert(
    !str_contains($withoutIncident, 'data-estab-incident-state')
        && !str_contains($withoutIncident, 'data-estab-navigation-mode'),
    'Sidebar split did not suppress duplicated incident/navigation markup'
);

$sidebarSource = file_get_contents(__DIR__ . '/../../4fach/vorgaben.php');
$sidebarLibrary = file_get_contents(__DIR__ . '/../../app/sidebar.php');
$runtimeVerifier = file_get_contents(
    __DIR__ . '/../../docker/app/verify-runtime-surface.sh'
);
foreach ([$sidebarSource, $sidebarLibrary, $runtimeVerifier] as $source) {
    $assert(is_string($source), 'Incident UI integration source is unreadable');
}
$assert(
    str_contains($sidebarSource, 'estab_incident_status($connection)')
        && str_contains($sidebarSource, 'estab_incident_ui_markup(')
        && str_contains($sidebarLibrary, '$incidentMarkup'),
    'Live sidebar refresh does not carry the global incident state'
);
$assert(
    str_contains($runtimeVerifier, 'app/incident_ui.php'),
    'Runtime image surface omits shared incident UI'
);

echo 'incident UI security: OK (' . $assertions . " assertions)\n";
