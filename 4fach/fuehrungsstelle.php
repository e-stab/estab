<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/dv_operations.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/read_authorization.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_session_ui_start($_SESSION);
estab_navigation_require_session(
    $_SESSION,
    'command-post',
    $_SERVER
);
$identity = estab_auth_session_identity($_SESSION);
if ($identity === null) {
    throw new LogicException('Authenticated command-post identity missing');
}
$operationIdentity = estab_read_session_identity($_SESSION) ?? $identity;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function dv_operations_redirect(string $result): never
{
    header(
        'Location: fuehrungsstelle.php?result=' . rawurlencode($result),
        true,
        303
    );
    exit;
}

/**
 * Continue to the protected area requested before login, but only after the
 * exact selected hat grants access to that navigation item.
 */
function dv_operations_redirect_after_hat(array $selectedIdentity): never
{
    $candidate = $_SESSION['estab_pending_navigation_key'] ?? null;
    unset($_SESSION['estab_pending_navigation_key']);
    $key = estab_navigation_login_destination_key($candidate);
    if ($key !== null && $key !== 'command-post') {
        $item = estab_navigation_item_for_key($key);
        if (
            is_array($item)
            && estab_navigation_duty_access_allowed(
                $item,
                $selectedIdentity
            )
        ) {
            header(
                'Location: ' . estab_navigation_url_for_key($key),
                true,
                303
            );
            exit;
        }
    }
    dv_operations_redirect('hat_selected');
}

function dv_operations_html(mixed $value): string
{
    return estab_auth_html($value);
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$error = null;
$connection = null;

if ($requestMethod === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $action = $_POST['operation_action'] ?? null;
        if (!is_string($action)) {
            throw new EstabDvInputException('Unbekannte Führungsstellenaktion.');
        }
        $connection = estab_auth_connect($conf_4f_db);
        $incident = estab_incident_require_active($connection);
        $incidentId = (int) $incident['active_einsatz_id'];
        $code = (string) $identity['kuerzel'];
        if ($action === 'accept_hat') {
            $acceptedHat = estab_dv_accept_hat(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstbesetzung_id'] ?? null,
                    'Dienstbesetzung'
                ),
                $code,
                $conf_4f_tbl['protokoll'],
                $conf_4f_tbl['empfmtx'],
                $conf_4f_tbl['usrtblprefix']
            );
            dv_operations_redirect(
                ($acceptedHat['active_shift_extension'] ?? false) === true
                    ? 'hat_extension_accepted'
                    : 'hat_accepted'
            );
        }
        if ($action === 'select_hat') {
            $selectedHat = estab_dv_select_session_hat(
                $connection,
                $_SESSION,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstbesetzung_id'] ?? null,
                    'Dienstbesetzung'
                ),
                $conf_4f_tbl['protokoll'],
                $conf_4f_tbl['empfmtx'],
                $conf_4f_tbl['usrtblprefix']
            );
            $selectedHat['duty_assignment_id'] =
                $_SESSION['estab_duty_assignment_id'];
            dv_operations_redirect_after_hat($selectedHat);
        }
        if ($action === 'confirm_handover') {
            estab_dv_confirm_handover_shift(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstuebergabe_anfrage_id'] ?? null,
                    'Übergabeanforderung'
                ),
                estab_dv_positive_id(
                    $_POST['dienstbesetzung_id'] ?? null,
                    'Bestätigende Dienstbesetzung'
                ),
                $identity,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('shift_handover_confirmed');
        }
        if ($action === 'create_plan') {
            estab_dv_create_telecom_plan(
                $connection,
                $incidentId,
                $operationIdentity,
                $_POST,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('plan_created');
        }
        if ($action === 'add_plan_entry') {
            estab_dv_add_telecom_entry(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Fernmeldeplan'
                ),
                $operationIdentity,
                $_POST,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('plan_entry_added');
        }
        if ($action === 'activate_plan') {
            estab_dv_activate_telecom_plan(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Fernmeldeplan'
                ),
                $operationIdentity,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('plan_activated');
        }
        if ($action === 'assign_messenger') {
            estab_dv_assign_messenger(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['nachricht_id'] ?? null,
                    'Nachricht'
                ),
                $_POST['melder_kuerzel'] ?? null,
                $_POST['ziel'] ?? null,
                $operationIdentity,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('messenger_assigned');
        }
        if ($action === 'messenger_transition') {
            $transition = $_POST['transition'] ?? null;
            if (!is_string($transition)) {
                throw new EstabDvInputException('Unbekannter Melderstatus.');
            }
            estab_dv_transition_messenger(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['melderauftrag_id'] ?? null,
                    'Melderauftrag'
                ),
                $transition,
                $operationIdentity,
                $_POST,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('messenger_updated');
        }
        throw new EstabDvInputException('Unbekannte Führungsstellenaktion.');
    } catch (EstabCsrfException) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    } catch (EstabDvInputException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (EstabDvPermissionException $exception) {
        http_response_code(403);
        $error = $exception->getMessage();
    } catch (
        EstabDvConflictException
        | EstabIncidentConfigurationException
        | EstabNoActiveIncidentException $exception
    ) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('eStab Führungsstellenbetrieb: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Die Aktion konnte nicht vollständig gespeichert werden.';
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
} elseif ($requestMethod !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $error = 'Diese Seite unterstützt nur GET und POST.';
}

$status = null;
$hats = [];
$plans = [];
$jobs = [];
$users = [];
$eligibleMessages = [];
$activeDutyShift = null;
$handoverRequests = [];
$confirmableHandovers = [];
$isS6 = false;
$isLdf = false;
$isAw = false;
$selectedIdentity = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $status = estab_incident_status($connection);
    if ($status['active_einsatz_id'] !== null) {
        $incidentId = (int) $status['active_einsatz_id'];
        $code = (string) $identity['kuerzel'];
        $activeDutyShift = estab_dv_active_shift_summary(
            $connection,
            $incidentId
        );
        $hats = estab_dv_user_hats($connection, $incidentId, $code);
        $handoverRequests = estab_dv_user_handover_requests(
            $connection,
            $incidentId,
            $code
        );
        foreach ($handoverRequests as $request) {
            if (($request['status'] ?? null) !== 'INITIIERT') {
                continue;
            }
            foreach ($hats as $hat) {
                if (
                    (int) ($hat['dienstschicht_id'] ?? 0)
                        === (int) $request['an_dienstschicht_id']
                    && ($hat['status'] ?? null) === 'ANGENOMMEN'
                    && ($hat['schicht_status'] ?? null) === 'GEPLANT'
                ) {
                    $confirmableHandovers[] = [
                        'request' => $request,
                        'assignment' => $hat,
                    ];
                }
            }
        }
        try {
            $readScope = estab_read_require_operational_scope(
                $connection,
                estab_read_session_identity($_SESSION) ?? []
            );
            $selectedIdentity = $readScope['identity'];
            $operationIdentity = $selectedIdentity;
        } catch (EstabReadPermissionException) {
            // The personal hat/hand-over controls above are the only
            // intentionally visible bootstrap before selecting a function.
        }
        if (is_array($selectedIdentity)) {
            $isS6 = estab_dv_has_selected_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'FERNMELDEPLANUNG'
            );
            $isLdf = estab_dv_has_selected_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'FERNMELDEBETRIEB'
            );
            $isAw = estab_dv_has_selected_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'BEFOERDERUNG'
            );
            $plans = estab_dv_telecom_plans($connection, $incidentId);
            $jobs = estab_dv_messenger_jobs(
                $connection,
                $incidentId,
                ($isLdf || $isAw) ? null : $code
            );
            if ($isLdf) {
                $users = estab_dv_messenger_candidates(
                    $connection,
                    $incidentId
                );
                $messageStatement = $connection->prepare(
                    'SELECT n.`00_lfd`, n.`04_nummer`, n.`10_anschrift`,'
                    . ' n.`12_inhalt` FROM `nv_nachrichten` AS n'
                    . ' WHERE n.`einsatz_id` = ?'
                    . " AND n.`04_richtung` = 'A'"
                    . " AND n.`06_befwegausw` = 'Me'"
                    . ' AND n.`x00_status` = 2'
                    . " AND n.`x01_abschluss` = 'f'"
                    . ' AND NOT EXISTS ('
                    . '   SELECT 1 FROM `nv_melderauftraege` AS m'
                    . '   WHERE m.`einsatz_id` = n.`einsatz_id`'
                    . '     AND m.`nachricht_id` = n.`00_lfd`'
                    . "     AND m.`status` <> 'ABGEBROCHEN'"
                    . ' )'
                    . ' ORDER BY n.`04_nummer`, n.`00_lfd`'
                );
                if (!$messageStatement) {
                    throw new RuntimeException(
                        'Melderfähige Nachrichten konnten nicht vorbereitet '
                        . 'werden.'
                    );
                }
                try {
                    $messageStatement->bind_param('i', $incidentId);
                    $messageStatement->execute();
                    $messageResult = $messageStatement->get_result();
                    $eligibleMessages = $messageResult->fetch_all(
                        MYSQLI_ASSOC
                    );
                    $messageResult->free();
                } finally {
                    $messageStatement->close();
                }
            }
        }
    }
} catch (Throwable $exception) {
    error_log('eStab Führungsstellenansicht: ' . $exception->getMessage());
    if ($error === null) {
        http_response_code(503);
        $error = 'Der Führungsstellenstatus ist derzeit nicht verfügbar.';
    }
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}

$flashMessages = [
    'hat_accepted' => 'Die Dienstfunktion wurde persönlich angenommen.',
    'hat_extension_accepted' =>
        'Die Ergänzung der aktiven Schicht wurde persönlich angenommen und '
        . 'automatisch in den vorgeschriebenen Betriebsbüchern nachgewiesen.',
    'hat_selected' => 'Die aktive Arbeitsfunktion wurde gewechselt.',
    'shift_handover_confirmed' =>
        'Sie haben die Schichtübernahme persönlich bestätigt. Die '
        . 'Nachfolgeschicht ist jetzt aktiv.',
    'plan_created' => 'Ein neuer Fernmeldeplanentwurf wurde angelegt.',
    'plan_entry_added' => 'Der Fernmeldeweg wurde dem Entwurf hinzugefügt.',
    'plan_activated' => 'Der Fernmeldeplan wurde freigegeben und versioniert.',
    'messenger_assigned' => 'Der Melderauftrag wurde verbindlich erteilt.',
    'messenger_updated' => 'Der Melderstatus wurde nachgewiesen.',
];
$result = $_GET['result'] ?? null;
$flash = is_string($result) ? ($flashMessages[$result] ?? null) : null;
$activePlan = null;
foreach ($plans as $plan) {
    if (($plan['status'] ?? null) === 'AKTIV') {
        $activePlan = $plan;
        break;
    }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Führungsstellenbetrieb</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
<main class="estab-tool-main" data-estab-dv-operations>
  <header class="estab-tool-hero">
    <p class="estab-tool-eyebrow">Einsatzführung · DV 1-101</p>
    <h1>Führungsstellenbetrieb</h1>
    <p>Dienstfunktion annehmen und auswählen, den Fernmeldeplan als S6 führen
      sowie Melderaufträge lückenlos nachweisen.</p>
  </header>

  <section class="estab-tool-status estab-tool-status-active">
    <?php if (
        is_array($status)
        && $status['active_einsatz_id'] !== null
        && ($status['fuehrungsstellenname'] ?? null) !== null
    ): ?>
      <div>
        <span>Führungsstelle</span>
        <strong><?= dv_operations_html(
            $status['fuehrungsstellenname']
        ) ?></strong>
      </div>
    <?php endif; ?>
    <div>
      <span>Angemeldet als</span>
      <strong><?= dv_operations_html(
          $identity['benutzer'] . ' · ' . $identity['kuerzel']
      ) ?></strong>
    </div>
    <div>
      <span>Aktive Arbeitsfunktion</span>
      <?php if (is_array($selectedIdentity)): ?>
        <strong><?= dv_operations_html(
            $selectedIdentity['funktion'] . ' · '
                . $selectedIdentity['rolle']
        ) ?></strong>
      <?php else: ?>
        <strong>Noch nicht ausgewählt</strong>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($error !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
      <?= dv_operations_html($error) ?>
    </p>
  <?php endif; ?>
  <?php if ($flash !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
      <?= dv_operations_html($flash) ?>
    </p>
  <?php endif; ?>

  <?php if (!is_array($status) || $status['active_einsatz_id'] === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Kein Einsatz aktiv.</strong>
      <span>Operative Aktionen und Dienstbesetzungen sind gesperrt.</span>
    </section>
  <?php elseif (($status['fuehrungsstellenname'] ?? null) === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Name der Führungsstelle fehlt.</strong>
      <span>Operative Aktionen sind gesperrt, bis der Name am Einsatz
        festgelegt wurde.</span>
      <a class="estab-button" href="../4fadm/incidents.php">
        Zur Einsatzverwaltung
      </a>
    </section>
  <?php else: ?>
    <?php if (!is_array($activeDutyShift)): ?>
      <section class="estab-tool-status estab-tool-status-danger" role="alert">
        <strong>Keine Dienstschicht aktiv.</strong>
        <span>Operative Eingaben sind gesperrt. Nehmen Sie eine geplante
          Funktion an; die Administration muss anschließend die erste Schicht
          aktivieren oder die laufende Schicht geordnet übergeben.</span>
      </section>
    <?php endif; ?>
    <section class="estab-tool-panel">
      <header class="estab-tool-panel-heading">
        <h2>Meine Dienstfunktionen</h2>
        <p>Eine Zuweisung wird erst wirksam, nachdem Sie sie persönlich
          angenommen haben. Das gilt auch für eine Ergänzung der aktiven
          Schicht; deren Annahme wird automatisch im ETB und bei
          Fernmeldebetriebspersonal zusätzlich im TBB dokumentiert. Zwischen
          mehreren angenommenen Funktionen können Sie anschließend wechseln.</p>
      </header>
      <?php if ($hats === []): ?>
        <p class="estab-tool-empty">Ihrem Konto ist in der aktuellen oder
          geplanten Schicht noch keine Funktion zugewiesen.</p>
      <?php else: ?>
        <div class="estab-tool-table-wrap estab-tool-table-responsive">
          <table class="estab-tool-table">
            <caption class="estab-visually-hidden">
              Persönlich zugewiesene Dienstfunktionen
            </caption>
            <thead><tr>
              <th scope="col">Schicht</th>
              <th scope="col">Funktion</th>
              <th scope="col">Status</th>
              <th scope="col">Aktion</th>
            </tr></thead>
            <tbody>
            <?php foreach ($hats as $hat): ?>
              <tr>
                <td data-label="Schicht">#<?= (int) $hat['nummer'] ?> ·
                  <?= dv_operations_html($hat['bezeichnung']) ?><br>
                  <?= dv_operations_html($hat['schicht_status']) ?></td>
                <td data-label="Funktion"><?= dv_operations_html(
                    $hat['funktion'] . ' · ' . $hat['rolle']
                ) ?></td>
                <td data-label="Status"><?= dv_operations_html(
                    $hat['status']
                ) ?></td>
                <td data-label="Aktion">
                  <?php if ($hat['status'] === 'ZUGEWIESEN'
                      && in_array(
                          $hat['schicht_status'],
                          ['GEPLANT', 'AKTIV'],
                          true
                      )): ?>
                    <form method="post" action="fuehrungsstelle.php">
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="operation_action"
                        value="accept_hat">
                      <input type="hidden" name="dienstbesetzung_id"
                        value="<?= (int) $hat['dienstbesetzung_id'] ?>">
                      <button class="estab-button estab-button-primary"
                        type="submit"><?= $hat['schicht_status'] === 'AKTIV'
                            ? 'Schichterweiterung annehmen'
                            : 'Verbindlich annehmen' ?></button>
                    </form>
                  <?php elseif ($hat['status'] === 'ANGENOMMEN'
                      && $hat['schicht_status'] === 'AKTIV'): ?>
                    <form method="post" action="fuehrungsstelle.php">
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="operation_action"
                        value="select_hat">
                      <input type="hidden" name="dienstbesetzung_id"
                        value="<?= (int) $hat['dienstbesetzung_id'] ?>">
                      <button class="estab-button" type="submit">
                        Als Arbeitsfunktion wählen
                      </button>
                    </form>
                  <?php else: ?>
                    <span>Keine Aktion erforderlich</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($confirmableHandovers !== []): ?>
      <section class="estab-tool-panel" aria-labelledby="handover-title">
        <header class="estab-tool-panel-heading">
          <h2 id="handover-title">Schichtübernahme persönlich bestätigen</h2>
          <p>Die Administration hat die Übergabe initiiert. Erst Ihre
            persönliche Bestätigung mit einer angenommenen Funktion der
            Nachfolgeschicht löst den Schichtwechsel aus.</p>
        </header>
        <?php foreach ($confirmableHandovers as $confirmation): ?>
          <?php
            $request = $confirmation['request'];
            $assignment = $confirmation['assignment'];
          ?>
          <form class="estab-tool-form" method="post"
            action="fuehrungsstelle.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="operation_action"
              value="confirm_handover">
            <input type="hidden" name="dienstuebergabe_anfrage_id"
              value="<?= (int) $request['dienstuebergabe_anfrage_id'] ?>">
            <input type="hidden" name="dienstbesetzung_id"
              value="<?= (int) $assignment['dienstbesetzung_id'] ?>">
            <p><strong>Von Schicht #<?= (int) $request['von_nummer'] ?>
              nach Schicht #<?= (int) $request['an_nummer'] ?></strong></p>
            <p><?= nl2br(dv_operations_html(
                $request['zusammenfassung']
            )) ?></p>
            <p>Bestätigung als
              <strong><?= dv_operations_html(
                  $assignment['funktion'] . ' · ' . $assignment['rolle']
              ) ?></strong></p>
            <button class="estab-button estab-button-primary" type="submit">
              Übernahme jetzt persönlich bestätigen
            </button>
          </form>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <?php if (!is_array($selectedIdentity)): ?>
      <section class="estab-tool-status estab-tool-status-danger"
        role="alert" data-estab-duty-selection-required>
        <strong>Keine Arbeitsfunktion ausgewählt.</strong>
        <span>Wählen Sie oben eine persönlich angenommene Funktion. Erst
          danach werden Fernmeldeplan, Melderaufträge und weitere operative
          Bereiche freigeschaltet.</span>
      </section>
    <?php else: ?>
    <section class="estab-tool-panel">
      <header class="estab-tool-panel-heading">
        <h2>Aktiver Fernmeldeplan</h2>
        <p>Für jede Route werden Betriebsstelle, Rufname, Medium, Kanal,
          Bandlage und Verkehrsform aus dem von S6 freigegebenen Plan
          angezeigt.</p>
      </header>
      <?php if ($activePlan === null): ?>
        <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
          Noch kein gültiger Fernmeldeplan freigegeben.
        </p>
      <?php else: ?>
        <p><strong>Version <?= (int) $activePlan['version'] ?></strong> ·
          <?= dv_operations_html($activePlan['herkunft']) ?> · gültig ab
          <?= dv_operations_html($activePlan['gueltig_ab']) ?> ·
          Betriebsleitung
          <?= dv_operations_html($activePlan['betriebsleitung']) ?></p>
        <div class="estab-tool-table-wrap estab-tool-table-responsive">
          <table class="estab-tool-table">
            <caption class="estab-visually-hidden">
              Wege des aktiven Fernmeldeplans
            </caption>
            <thead><tr>
              <th scope="col">Betriebsstelle</th>
              <th scope="col">Rufname</th>
              <th scope="col">Weg</th>
              <th scope="col">Verkehrsform</th>
              <th scope="col">Vermerke</th>
            </tr></thead>
            <tbody>
            <?php foreach ($activePlan['eintraege'] as $entry): ?>
              <tr>
                <td data-label="Betriebsstelle"><?= dv_operations_html(
                    $entry['betriebsstelle']
                ) ?></td>
                <td data-label="Rufname"><?= dv_operations_html(
                    $entry['rufname']
                ) ?></td>
                <td data-label="Weg"><?= dv_operations_html(
                    $entry['medium'] . ' · ' . $entry['kanal']
                    . ' · ' . $entry['bandlage']
                ) ?></td>
                <td data-label="Verkehrsform"><?= dv_operations_html(
                    $entry['verkehrsform']
                ) ?></td>
                <td data-label="Vermerke"><?= dv_operations_html(
                    trim(
                        (string) $entry['besondere_vermerke']
                        . ' ' . (string) $entry['bemerkungen']
                    )
                ) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($isS6): ?>
      <section class="estab-tool-panel">
        <header class="estab-tool-panel-heading">
          <h2>S6 · Fernmeldeplan versionieren</h2>
          <p>Ein freigegebener Plan ist unveränderlich. Änderungen beginnen
            immer als neue Version und ersetzen den bisherigen Plan erst nach
            Ihrer Freigabe.</p>
        </header>
        <form class="estab-tool-form" method="post"
          action="fuehrungsstelle.php">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="operation_action" value="create_plan">
          <label>Herkunft
            <input name="herkunft" maxlength="255" required>
          </label>
          <label>Gültig ab
            <input type="datetime-local" name="gueltig_ab"
              value="<?= date('Y-m-d\TH:i') ?>" required>
          </label>
          <label>Gültig bis
            <input type="datetime-local" name="gueltig_bis">
          </label>
          <label>Betriebsleitung
            <input name="betriebsleitung" maxlength="255" required>
          </label>
          <label>Bemerkungen
            <textarea name="bemerkungen" maxlength="10000"></textarea>
          </label>
          <button class="estab-button estab-button-primary" type="submit">
            Neue Planversion anlegen
          </button>
        </form>

        <?php foreach ($plans as $plan): ?>
          <?php if ($plan['status'] === 'ENTWURF'): ?>
            <section class="estab-tool-panel">
              <h3>Entwurf Version <?= (int) $plan['version'] ?></h3>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="add_plan_entry">
                <input type="hidden" name="fernmeldeplan_id"
                  value="<?= (int) $plan['fernmeldeplan_id'] ?>">
                <label>Betriebsstellen-Klarbezeichnung
                  <input name="betriebsstelle" maxlength="255" required>
                </label>
                <label>Rufname
                  <input name="rufname" maxlength="128" required>
                </label>
                <label>Medium
                  <select name="medium" required>
                    <?php foreach (ESTAB_DV_MEDIA as $medium): ?>
                      <option value="<?= dv_operations_html($medium) ?>">
                        <?= dv_operations_html($medium) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Kanal
                  <input name="kanal" maxlength="64" required>
                </label>
                <label>Bandlage
                  <input name="bandlage" maxlength="64" required>
                </label>
                <label>Verkehrsform
                  <input name="verkehrsform" maxlength="128" required>
                </label>
                <label>Besondere Vermerke
                  <textarea name="besondere_vermerke"
                    maxlength="10000"></textarea>
                </label>
                <label>Bemerkungen
                  <textarea name="bemerkungen" maxlength="10000"></textarea>
                </label>
                <button class="estab-button" type="submit">
                  Weg hinzufügen
                </button>
              </form>
              <form method="post" action="fuehrungsstelle.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="activate_plan">
                <input type="hidden" name="fernmeldeplan_id"
                  value="<?= (int) $plan['fernmeldeplan_id'] ?>">
                <button class="estab-button estab-button-primary" type="submit">
                  Version unveränderlich freigeben
                </button>
              </form>
            </section>
          <?php endif; ?>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <section class="estab-tool-panel">
      <header class="estab-tool-panel-heading">
        <h2>Melderaufträge</h2>
        <p>Übernahme, tatsächlicher Empfänger, Rücknachricht, Rückkehr und
          Abschlussmeldung werden als eigene unveränderbare Ereignisse
          protokolliert.</p>
      </header>
      <?php if ($isLdf): ?>
        <form class="estab-tool-form" method="post"
          action="fuehrungsstelle.php">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="operation_action"
            value="assign_messenger">
          <label>Ausgangsnachricht mit Weg „Me“
            <select name="nachricht_id" required>
              <?php foreach ($eligibleMessages as $message): ?>
                <option value="<?= (int) $message['00_lfd'] ?>">
                  A<?= (int) $message['04_nummer'] ?> ·
                  <?= dv_operations_html($message['10_anschrift']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Melder
            <select name="melder_kuerzel" required>
              <?php if ($users === []): ?>
                <option value="">Kein angenommenes A/W verfügbar</option>
              <?php endif; ?>
              <?php foreach ($users as $user): ?>
                <option value="<?= dv_operations_html($user['kuerzel']) ?>">
                  <?= dv_operations_html(
                      $user['benutzer'] . ' (' . $user['kuerzel'] . ')'
                  ) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Ziel
            <input name="ziel" maxlength="255" required>
          </label>
          <button class="estab-button estab-button-primary" type="submit"
            <?= $eligibleMessages === [] || $users === []
                ? 'disabled'
                : '' ?>>
            Melder verbindlich beauftragen
          </button>
        </form>
      <?php endif; ?>

      <?php if ($jobs === []): ?>
        <p class="estab-tool-empty">Keine sichtbaren Melderaufträge.</p>
      <?php else: ?>
        <?php foreach ($jobs as $job): ?>
          <article class="estab-tool-panel">
            <h3>Auftrag #<?= (int) $job['melderauftrag_id'] ?> ·
              A<?= (int) $job['04_nummer'] ?> ·
              <?= dv_operations_html($job['status']) ?></h3>
            <p><strong>Melder:</strong>
              <?= dv_operations_html(
                  $job['melder_name'] . ' (' . $job['melder_kuerzel'] . ')'
              ) ?><br>
              <strong>Ziel:</strong> <?= dv_operations_html($job['ziel']) ?>
            </p>
            <?php
              $isOwnJob = hash_equals(
                  (string) $job['melder_kuerzel'],
                  (string) $identity['kuerzel']
              );
              $transition = null;
              $button = '';
              if ($isOwnJob) {
                  [$transition, $button] = match ($job['status']) {
                      'BEAUFTRAGT' => ['accept', 'Auftrag übernehmen'],
                      'UEBERNOMMEN' => ['deliver', 'Übergabe nachweisen'],
                      'UEBERGEBEN' => ['return_path', 'Rückweg antreten'],
                      'RUECKWEG' => ['returned', 'Rückkehr melden'],
                      default => [null, ''],
                  };
              } elseif ($isLdf && $job['status'] === 'ZURUECK') {
                  $transition = 'report';
                  $button = 'Abschluss an FmZt bestätigen';
              }
            ?>
            <?php if ($transition !== null): ?>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="messenger_transition">
                <input type="hidden" name="melderauftrag_id"
                  value="<?= (int) $job['melderauftrag_id'] ?>">
                <input type="hidden" name="transition"
                  value="<?= dv_operations_html($transition) ?>">
                <?php if ($transition === 'deliver'): ?>
                  <label>Tatsächlicher Empfänger
                    <input name="tatsaechlicher_empfaenger" maxlength="255"
                      required>
                  </label>
                <?php elseif ($transition === 'return_path'): ?>
                  <fieldset>
                    <legend>Liegt eine Rücknachricht vor?</legend>
                    <label>
                      <input type="radio"
                        name="ruecknachricht_vorhanden" value="ja" required>
                      Ja, Rücknachricht nachfolgend erfassen
                    </label>
                    <label>
                      <input type="radio"
                        name="ruecknachricht_vorhanden" value="nein" required>
                      Nein, ausdrücklich keine Rücknachricht
                    </label>
                  </fieldset>
                  <label>Rücknachricht (nur bei „Ja“)
                    <textarea name="ruecknachricht"
                      maxlength="10000"></textarea>
                  </label>
                <?php elseif ($transition === 'report'): ?>
                  <label>Abschlussvermerk
                    <textarea name="abschlussvermerk" maxlength="10000"
                      required></textarea>
                  </label>
                <?php endif; ?>
                <button class="estab-button estab-button-primary" type="submit">
                  <?= dv_operations_html($button) ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($isLdf && $job['status'] === 'BEAUFTRAGT'): ?>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="messenger_transition">
                <input type="hidden" name="melderauftrag_id"
                  value="<?= (int) $job['melderauftrag_id'] ?>">
                <input type="hidden" name="transition" value="cancel">
                <label>Abbruchgrund
                  <textarea name="abbruchgrund" maxlength="10000"
                    required></textarea>
                </label>
                <button class="estab-button estab-button-danger-outline"
                  type="submit">Auftrag begründet abbrechen</button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  <?php endif; ?>

  <footer class="estab-tool-footer">
    <a href="mainindex.php">Zurück zu Nachrichten</a>
    <span>Alle Änderungen sind einsatzgebunden und hashverkettet.</span>
  </footer>
</main>
</body>
</html>
