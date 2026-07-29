<?php

declare(strict_types=1);

define('debug', false);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';
require_once __DIR__ . '/../app/sidebar.php';
require_once __DIR__ . '/../app/incident_ui.php';

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
if (array_key_exists('fragment', $_GET)) {
    if (
        count($_GET) !== 1
        || !is_string($_GET['fragment'])
        || $_GET['fragment'] !== 'status'
    ) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Ungültige Statusauswahl.';
        exit;
    }
    $statusFragment = true;
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
    http_response_code(403);
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
    ?array $queueProfile,
    bool $includeOutgoingForReview,
    bool $soundsEnabled,
    ?string $soundUrl
): string {
    $identity = estab_auth_session_identity($session);
    if ($identity === null) {
        return '';
    }

    $users = [];
    $positions = [];
    $queueCount = null;
    $freshnessState = 'current';
    $queueLabel = is_string($queueProfile['label'] ?? null)
        ? $queueProfile['label']
        : 'Offene Meldungen';
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

        try {
            $queueSessionKey = $queueProfile['session_key'] ?? null;
            if (is_string($queueSessionKey)) {
                $queueCount = estab_sidebar_queue_count(
                    $connection,
                    $queueSessionKey,
                    $messageTable,
                    $userTablePrefix,
                    $identity['funktion'],
                    $includeOutgoingForReview
                );
            }
        } catch (Throwable $exception) {
            $queueCount = null;
            $freshnessState = 'partial';
            error_log(
                'eStab sidebar queue lookup failed: '
                . $exception->getMessage()
            );
        }

        try {
            $incidentState = estab_incident_ui_state_from_status(
                estab_incident_status($connection)
            );
        } catch (Throwable $exception) {
            error_log(
                'eStab sidebar incident lookup failed: '
                . $exception->getMessage()
            );
        } finally {
            estab_auth_close($connection);
        }
    }

    $notificationSoundUrl = estab_sidebar_queue_notification(
        $session,
        is_string($queueProfile['session_key'] ?? null)
            ? $queueProfile['session_key']
            : null,
        $queueCount,
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
        estab_incident_ui_markup($incidentState, true, true)
    );
}

$queueProfile = $identity === null
    ? null
    : estab_sidebar_queue_profile($identity);
$soundsEnabled = (bool) ($conf_4f['sounds'] ?? false);
$soundUrl = $soundsEnabled && is_string($queueProfile['sound_file'] ?? null)
    ? estab_application_url('4fach/audio/' . $queueProfile['sound_file'])
    : null;
$statusMarkup = $identity === null
    ? ''
    : estab_vorgaben_status_markup(
        $_SESSION,
        $conf_4f_db,
        (string) $conf_4f_tbl['benutzer'],
        (string) $conf_4f_tbl['nachrichten'],
        (string) $conf_4f_tbl['usrtblprefix'],
        (string) $conf_4f_tbl['empfmtx'],
        $queueProfile,
        (bool) ($conf_4f['si_in_out'] ?? false),
        $soundsEnabled,
        $soundUrl
    );

if ($statusFragment) {
    echo $statusMarkup;
    exit;
}

$menuState = $_SESSION['menue'] ?? '';
$actions = estab_sidebar_workflow_actions($identity, $menuState);

$refreshInterval = isset($cfg['itv']['status'])
    ? (int) $cfg['itv']['status']
    : 10;
$refreshScript = $identity === null
    ? ''
    : estab_sidebar_status_refresh_script(
        estab_application_url('4fach/vorgaben.php?fragment=status'),
        $refreshInterval
    );
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Navigation</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-navigation-frame estab-message-sidebar-page">
  <div class="estab-message-sidebar" data-estab-sidebar-root>
    <?= $statusMarkup ?>
    <?= estab_session_ui_current_markup(
        $_SESSION,
        true,
        $loginDestination,
        false,
        true,
        false,
        false
    ) ?>
    <?php if ($identity !== null): ?>
      <main class="estab-sidebar-workflow" data-estab-workflow-menu>
        <div class="estab-sidebar-section-heading">
          <h2>Aktionen</h2>
          <p>
            <?= estab_auth_html($identity['funktion']) ?>
            · <?= estab_auth_html($identity['rolle']) ?>
          </p>
        </div>
        <?php if ($actions !== []): ?>
          <form
            class="estab-sidebar-action-form"
            data-estab-requires-incident
            action="<?= estab_auth_html((string) $conf_4f['MainURL']) ?>"
            method="post"
            target="mainframe"
          >
            <?= estab_csrf_field() ?>
            <?= estab_navigation_login_destination_field($loginDestination) ?>
            <div class="estab-sidebar-actions">
              <?php foreach ($actions as $action): ?>
                <button
                  class="estab-sidebar-action"
                  type="submit"
                  name="<?= estab_auth_html($action['name']) ?>"
                  value="1"
                  data-estab-workflow-key="<?= estab_auth_html($action['key']) ?>"
                >
                  <span class="estab-sidebar-action-title">
                    <?= estab_auth_html($action['label']) ?>
                  </span>
                  <span class="estab-sidebar-action-description">
                    <?= estab_auth_html($action['description']) ?>
                  </span>
                </button>
              <?php endforeach; ?>
            </div>
          </form>
        <?php else: ?>
          <p class="estab-sidebar-empty">
            Für diese Rolle sind hier keine Nachrichtenaktionen hinterlegt.
          </p>
        <?php endif; ?>
      </main>
    <?php endif; ?>
    <?= estab_navigation_markup(
        $identity !== null,
        $_SERVER,
        true,
        true
    ) ?>
  </div>
  <?= estab_sidebar_audio_markup($soundUrl) ?>
  <?= $refreshScript ?>
  <script data-estab-sidebar-workspace-link>
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
