<?php

declare(strict_types=1);

define('debug', false);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';
require_once __DIR__ . '/../app/sidebar.php';
require_once __DIR__ . '/../app/incident_ui.php';
require_once __DIR__ . '/../app/read_authorization.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!is_string($method) || !in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Method not allowed.';
    exit;
}

$statusFragment = false;
$loginDestination = null;
foreach (array_keys($_GET) as $requestKey) {
    if (
        !is_string($requestKey)
        || !in_array($requestKey, ['fragment', 'next'], true)
    ) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Ungültige Navigationsauswahl.';
        exit;
    }
}
/*
 * Drei Fragmente. "status" ist das kleine, das sich im Takt selbst erneuert;
 * "cockpit" ist die rechte Spalte der Huelle; "aktionen" sind die
 * Arbeitsschritte, die unten in der Menuespalte stehen.
 *
 * Die Arbeitsschritte kommen aus dieser Datei und nicht aus der Huelle,
 * obwohl sie links erscheinen: Wer sie aufbauen will, braucht den
 * aufgeloesten Einsatzbezug, die wirksamen Funktionen und die
 * Korrekturzaehler -- alles, was hier ohnehin schon steht. Dasselbe in der
 * Huelle noch einmal zu ermitteln waere eine zweite Verbindung zur Datenbank
 * je Seitenaufbau und eine zweite Stelle, an der dieselbe Regel gepflegt
 * werden muesste.
 *
 * Kein Fragment enthaelt ein Menue -- das steht links in der Huelle und
 * gehoert dorthin, damit es auf jeder Seite an derselben Stelle steht.
 */
$cockpitFragment = false;
$actionsFragment = false;
if (array_key_exists('fragment', $_GET)) {
    if (
        count($_GET) !== 1
        || !is_string($_GET['fragment'])
        || !in_array(
            $_GET['fragment'],
            ['status', 'cockpit', 'aktionen'],
            true
        )
    ) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Ungültige Statusauswahl.';
        exit;
    }
    $statusFragment = $_GET['fragment'] === 'status';
    $cockpitFragment = $_GET['fragment'] === 'cockpit';
    $actionsFragment = $_GET['fragment'] === 'aktionen';
} elseif (array_key_exists('next', $_GET)) {
    $loginDestination = estab_navigation_login_destination_key(
        $_GET['next']
    );
    if ($loginDestination === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Ungültiges Anmeldeziel.';
        exit;
    }
}

session_start();

$identity = estab_auth_session_identity($_SESSION);
if ($statusFragment && $identity === null) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($method !== 'HEAD') {
        echo 'Anmeldung erforderlich.';
    }
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
if ($method === 'HEAD') {
    exit;
}

include __DIR__ . '/../4fcfg/config.inc.php';
include __DIR__ . '/../4fcfg/dbcfg.inc.php';
include __DIR__ . '/../4fcfg/e_cfg.inc.php';
// Die Taktangaben ($cfg["itv"]) stehen hier -- und wurden bis zum
// 30.08.2026 nie geladen. Die Abfrage unten fiel deshalb immer auf
// ihren eingebauten Rueckfallwert zurueck: Das Cockpit lief mit 10
// Sekunden, gleichgueltig was eingestellt war.
include __DIR__ . '/../4fcfg/para.inc.php';

$selectedIdentity = null;
$readGateStatus = 200;
$readGateMessage = '';
if ($identity !== null) {
    $scopeConnection = null;
    try {
        $scopeConnection = estab_auth_connect($conf_4f_db);
        $scope = estab_read_require_operational_scope(
            $scopeConnection,
            estab_read_session_identity($_SESSION) ?? []
        );
        $selectedIdentity = $scope['identity'];
        estab_permission_context_set_from_incident($scope['incident']);
    } catch (EstabNoActiveIncidentException $exception) {
        $readGateStatus = 409;
        $readGateMessage = 'Kein Einsatz ist aktiv.';
    } catch (EstabReadPermissionException $exception) {
        $readGateStatus = 403;
        $readGateMessage =
            'Die ausgewählte Strict-Dienstfunktion oder die im lockeren '
            . 'Modus wirksame Konto-/Zusatzfunktion ist nicht mehr gültig.';
    } catch (Throwable $exception) {
        error_log(
            'eStab sidebar read gate failed: ' . $exception->getMessage()
        );
        $readGateStatus = 503;
        $readGateMessage =
            'Der operative Status kann derzeit nicht geprüft werden.';
    } finally {
        if ($scopeConnection instanceof mysqli) {
            estab_auth_close($scopeConnection);
        }
    }
}

if ($statusFragment && $selectedIdentity === null) {
    http_response_code($readGateStatus === 200 ? 403 : $readGateStatus);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($method !== 'HEAD') {
        echo $readGateMessage !== ''
            ? $readGateMessage
            : 'Operativer Zugang nicht verfügbar.';
    }
    exit;
}

/**
 * Load live role occupancy and the current role-specific work queue.
 */
function estab_vorgaben_status_markup(
    array &$session,
    array $databaseConfig,
    string $userTable,
    string $messageTable,
    string $userTablePrefix,
    string $matrixTable,
    array $queueProfiles,
    bool $includeOutgoingForReview,
    bool $soundsEnabled,
    ?string $soundUrl,
    array $correctionProfiles = [],
    array &$correctionCounts = []
): string {
    $correctionCounts = [];
    $identity = estab_read_session_identity($session);
    if ($identity === null) {
        return '';
    }

    $users = [];
    $positions = [];
    $queueCounts = [];
    $dutyFunctions = [];
    $freshnessState = 'current';
    $incidentState = [
        'availability' => 'unavailable',
        'active' => false,
        'incident' => null,
    ];
    $connection = null;
    try {
        $connection = estab_auth_connect($databaseConfig);
    } catch (Throwable $exception) {
        $freshnessState = 'unavailable';
        error_log(
            'eStab sidebar database connection failed: '
            . $exception->getMessage()
        );
    }

    if ($connection instanceof mysqli) {
        try {
            $scope = estab_read_require_operational_scope(
                $connection,
                $identity
            );
            $identity = $scope['identity'];
            estab_permission_context_set_from_incident($scope['incident']);
            try {
                $positions = estab_sidebar_fetch_configured_positions(
                    $connection,
                    $matrixTable
                );
            } catch (Throwable $exception) {
                $freshnessState = 'partial';
                error_log(
                    'eStab sidebar matrix lookup failed: '
                    . $exception->getMessage()
                );
            }

            try {
                $users = estab_auth_fetch_users($connection, $userTable);
            } catch (Throwable $exception) {
                $freshnessState = 'partial';
                error_log(
                    'eStab sidebar occupancy lookup failed: '
                    . $exception->getMessage()
                );
            }

            /*
             * Die Korrekturzaehler reisen im selben Statement mit; eine
             * zweite Abfrage je Seitenleistenaufbau waere reine Verdopplung.
             * Das Budget des Stapels bleibt massgeblich, damit sehr viele
             * getragene Funktionen die Statusanzeige nicht zum Absturz
             * bringen.
             */
            $correctionBudget = ESTAB_SIDEBAR_MAX_QUEUES
                - count($queueProfiles);
            $measuredCorrections = $correctionBudget > 0
                ? array_slice($correctionProfiles, 0, $correctionBudget)
                : [];
            try {
                $queueCounts = estab_sidebar_queue_counts(
                    $connection,
                    array_merge($queueProfiles, $measuredCorrections),
                    $messageTable,
                    $userTablePrefix,
                    $includeOutgoingForReview,
                    (int) $scope['incident']['active_einsatz_id']
                );
                foreach ($measuredCorrections as $correctionProfile) {
                    $pending =
                        $queueCounts[$correctionProfile['baseline_key']] ?? null;
                    unset($queueCounts[$correctionProfile['baseline_key']]);
                    if (is_int($pending) && $pending > 0) {
                        $correctionCounts[$correctionProfile['funktion']] =
                            $pending;
                    }
                }
            } catch (Throwable $exception) {
                $queueCounts = [];
                $correctionCounts = [];
                $freshnessState = 'partial';
                error_log(
                    'eStab sidebar queue lookup failed: '
                    . $exception->getMessage()
                );
            }

            try {
                if (estab_incident_duty_shift_required($scope['incident'])) {
                    $dutyFunctions = estab_dv_active_duty_functions(
                        $connection,
                        (int) $scope['incident']['active_einsatz_id']
                    );
                }
            } catch (Throwable $exception) {
                $dutyFunctions = [];
                $freshnessState = 'partial';
                error_log(
                    'eStab sidebar duty occupancy lookup failed: '
                    . $exception->getMessage()
                );
            }

            try {
                $status = estab_incident_status($connection);
                if ($status['active_einsatz_id'] !== null) {
                    estab_permission_context_set_from_incident($status);
                }
                $incidentState = estab_incident_ui_state_from_status($status);
            } catch (Throwable $exception) {
                error_log(
                    'eStab sidebar incident lookup failed: '
                    . $exception->getMessage()
                );
            }
        } finally {
            estab_auth_close($connection);
        }
    }

    $primaryQueue = $queueProfiles[0] ?? null;
    $queueCount = $primaryQueue === null
        ? null
        : ($queueCounts[$primaryQueue['baseline_key']] ?? null);
    $queueLabel = 'Offene Meldungen';
    if ($primaryQueue !== null) {
        // Wer zwei Funktionen traegt, muss am grossen Zaehler erkennen,
        // welche gemeint ist; allein bleibt es beim bisherigen Wortlaut.
        $queueLabel = $primaryQueue['session_key'] === 'old_que_stab'
            && count($queueProfiles) > 1
            ? $primaryQueue['label'] . ' · ' . $primaryQueue['short_label']
            : $primaryQueue['label'];
    }
    $measurements = [];
    $secondaryQueues = [];
    foreach ($queueProfiles as $index => $profile) {
        $measurements[] = [
            'baseline_key' => $profile['baseline_key'],
            'count' => $queueCounts[$profile['baseline_key']] ?? null,
        ];
        if ($index === 0) {
            continue;
        }
        $secondaryQueues[] = [
            'baseline_key' => $profile['baseline_key'],
            'label' => $profile['label'],
            'short_label' => $profile['short_label'],
            'count' => $queueCounts[$profile['baseline_key']] ?? null,
        ];
    }
    $notificationSoundUrl = estab_sidebar_queue_notifications(
        $session,
        $measurements,
        $soundsEnabled,
        $soundUrl
    );
    return estab_sidebar_status_markup(
        $session,
        $positions,
        $users,
        $queueLabel,
        $queueCount,
        null,
        $soundUrl,
        $notificationSoundUrl,
        $freshnessState,
        estab_incident_ui_markup($incidentState, true, true),
        $identity,
        $secondaryQueues,
        $dutyFunctions,
        // Anmeldung und Abmelden stehen oben mit in der Statuszeile. Die
        // Marke faellt weg: Sie steht links im Menue der Huelle.
        estab_session_ui_current_markup(
            $session,
            true,
            null,
            false,
            true,
            false,
            false,
            null,
            false
        )
    );
}

$queueProfiles = $selectedIdentity === null
    ? []
    : estab_sidebar_queue_profiles($selectedIdentity);
$correctionProfiles = $selectedIdentity === null
    ? []
    : estab_sidebar_correction_profiles($selectedIdentity);
$correctionCounts = [];
$soundsEnabled = (bool) ($conf_4f['sounds'] ?? false);
$soundUrl = $soundsEnabled
    && is_string($queueProfiles[0]['sound_file'] ?? null)
    ? estab_application_url(
        '4fach/audio/' . $queueProfiles[0]['sound_file']
    )
    : null;
/*
 * Ohne gewählte Arbeitsfunktion gibt es keine Warteschlangen -- aber es gibt
 * eine Anmeldung, und die muss man beenden können.
 *
 * Hier stand ein leerer String. Die Statuszeile trägt seit dem Umbau auch die
 * Anmeldung und den Weg hinaus ("Anmeldung und Abmelden stehen oben mit in der
 * Statuszeile"), und so verschwand der Abmeldeknopf ausgerechnet in der Lage,
 * in der man ihn am ehesten braucht: Wer im strengen Modus keine aktive
 * Dienstschicht hat, sieht nur noch den Hinweis, dass operativer Zugriff nicht
 * verfügbar ist -- und kam aus seiner Anmeldung nicht mehr heraus, ohne die
 * Adresse der Abmeldeseite zu kennen.
 *
 * Die Leiste wird deshalb auch dann gebaut, nur eben ohne den operativen
 * Status: dieselben Angaben wie sonst, dieselbe Stelle, derselbe Knopf.
 */
$statusMarkup = $selectedIdentity === null
    ? estab_session_ui_current_markup(
        $_SESSION,
        true,
        null,
        false,
        true,
        false,
        false,
        null,
        false
    )
    : estab_vorgaben_status_markup(
        $_SESSION,
        $conf_4f_db,
        (string) $conf_4f_tbl['benutzer'],
        (string) $conf_4f_tbl['nachrichten'],
        (string) $conf_4f_tbl['usrtblprefix'],
        (string) $conf_4f_tbl['empfmtx'],
        $queueProfiles,
        (bool) ($conf_4f['si_in_out'] ?? false),
        $soundsEnabled,
        $soundUrl,
        $correctionProfiles,
        $correctionCounts
    );

if ($statusFragment) {
    echo $statusMarkup;
    exit;
}

$menuState = $_SESSION['menue'] ?? '';
$actions = estab_sidebar_workflow_actions(
    $selectedIdentity,
    $menuState,
    $correctionCounts
);
$navigationIdentity = $selectedIdentity ?? $identity;

$refreshInterval = isset($cfg['itv']['status'])
    ? (int) $cfg['itv']['status']
    : 10;
$refreshScript = $selectedIdentity === null
    ? ''
    : estab_sidebar_status_refresh_script(
        estab_application_url('4fach/vorgaben.php?fragment=status'),
        $refreshInterval
    );
if ($actionsFragment):
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Arbeitsschritte</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-navigation-frame estab-actions-page">
  <div class="estab-shell-actions-inner" data-estab-actions-root>
    <?php if ($selectedIdentity !== null): ?>
      <main class="estab-sidebar-workflow" data-estab-workflow-menu>
        <div class="estab-sidebar-section-heading">
          <h2>Aktionen</h2>
          <p>
            <?= estab_auth_html(estab_function_identity_display_name(
                (string) $selectedIdentity['funktion'],
                (string) $selectedIdentity['rolle']
            )) ?>
          </p>
        </div>
        <?php if ($actions !== []): ?>
          <div class="estab-sidebar-actions">
            <?php foreach ($actions as $action): ?>
              <form
                class="estab-sidebar-action-form"
                data-estab-requires-incident
                action="<?= estab_auth_html((string) $conf_4f['MainURL']) ?>"
                method="post"
                target="mainframe"
              >
                <?= estab_csrf_field() ?>
                <?php if ($loginDestination !== null): ?>
                  <input
                    type="hidden"
                    name="next"
                    value="<?= htmlspecialchars(
                        $loginDestination,
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) ?>"
                  >
                <?php endif; ?>
                <?php if (is_string($action['acting_function'] ?? null)): ?>
                  <input
                    type="hidden"
                    name="acting_function"
                    value="<?= estab_auth_html($action['acting_function']) ?>"
                  >
                <?php endif; ?>
                <button
                  class="estab-sidebar-action"
                  type="submit"
                  name="<?= estab_auth_html($action['name']) ?>"
                  value="1"
                  data-estab-workflow-key="<?= estab_auth_html($action['key']) ?>"
                >
                  <span class="estab-sidebar-action-title">
                    <?= estab_auth_html($action['label']) ?>
                    <?php if (is_string($action['badge'] ?? null)): ?>
                      <span class="estab-sidebar-action-badge"
                        ><?= estab_auth_html($action['badge']) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="estab-sidebar-action-description">
                    <?= estab_auth_html($action['description']) ?>
                  </span>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="estab-sidebar-empty">
            Für diese Rolle sind hier keine Nachrichtenaktionen hinterlegt.
          </p>
        <?php endif; ?>
      </main>
    <?php endif; ?>
  </div>
  <script<?= estab_csp_script_attribute() ?> data-estab-sidebar-workspace-link>
    document.addEventListener('submit', function (event) {
      if (
        event.target instanceof HTMLFormElement
        && event.target.matches('.estab-sidebar-action-form')
        && window.parent !== window
      ) {
        window.parent.postMessage('estab:show-content', window.location.origin);
      }
    });
  </script>
</body>
</html>
<?php
    exit;
endif;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Navigation</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-navigation-frame estab-cockpit-page">
  <div class="estab-message-sidebar" data-estab-sidebar-root>
    <?= $statusMarkup ?>
    <?php
    /*
     * Die Anmeldeleiste stand hier als eigenes Feld. Sie steht jetzt oben in
     * der Statuszeile: Wer angemeldet ist, die Glocke und der Weg hinaus sind
     * drei kurze Angaben, und ein eigener Kasten dafuer kostete mehr Platz
     * als sie selbst.
     */
    ?>
    <?= estab_sidebar_account_function_markup($_SESSION, $selectedIdentity) ?>
    <?php if ($identity !== null && $selectedIdentity === null): ?>
      <aside
        class="estab-sidebar-duty-required"
        role="alert"
      >
        <strong>Operativer Zugriff nicht verfügbar</strong>
        <p>
          <?= estab_auth_html($readGateMessage !== ''
              ? $readGateMessage
              : 'Aktivieren Sie zuerst einen Einsatz.') ?>
        </p>
        <a
          class="estab-button estab-button-primary"
          href="<?= estab_auth_html(
              estab_navigation_url_for_key('command-post')
          ) ?>"
          target="_top"
        >Status und Hinweise öffnen</a>
      </aside>
    <?php endif; ?>
  </div>
  <?= estab_sidebar_audio_markup($soundUrl) ?>
  <?= $refreshScript ?>
  <script<?= estab_csp_script_attribute() ?> data-estab-sidebar-workspace-link>
    document.addEventListener('submit', function (event) {
      if (
        event.target instanceof HTMLFormElement
        && event.target.matches('.estab-sidebar-action-form')
        && window.parent !== window
      ) {
        window.parent.postMessage('estab:show-content', window.location.origin);
      }
    });
  </script>
</body>
</html>
