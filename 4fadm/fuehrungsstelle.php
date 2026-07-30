<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/dv_operations.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function dv_admin_redirect(string $result): never
{
    header(
        'Location: fuehrungsstelle.php?result=' . rawurlencode($result),
        true,
        303
    );
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$error = null;
$flash = null;
$connection = null;

if ($requestMethod === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $action = $_POST['admin_action'] ?? null;
        if (!is_string($action)) {
            throw new EstabDvInputException('Unbekannte administrative Aktion.');
        }
        $connection = estab_auth_connect($conf_4f_db);
        $incident = estab_incident_require_active($connection);
        $incidentId = (int) $incident['active_einsatz_id'];
        $actor = estab_dv_actor($_SERVER['REMOTE_USER'] ?? 'admin');
        if ($action === 'create_shift') {
            estab_dv_create_shift(
                $connection,
                $incidentId,
                $_POST['bezeichnung'] ?? null,
                $_POST['vorgaenger_id'] ?? null,
                $actor,
                $conf_4f_tbl['protokoll']
            );
            dv_admin_redirect('shift_created');
        }
        if ($action === 'assign_hat') {
            $policyLock = estab_assignment_acquire_policy_lock(
                $connection,
                (string) $conf_4f_db['datenbank'],
                $conf_4f_tbl['empfmtx']
            );
            try {
                estab_dv_assign_hat(
                    $connection,
                    $incidentId,
                    estab_dv_positive_id(
                        $_POST['dienstschicht_id'] ?? null,
                        'Dienstschicht'
                    ),
                    $_POST['benutzer_kuerzel'] ?? null,
                    $_POST['funktion'] ?? null,
                    $actor,
                    $conf_4f_tbl['empfmtx'],
                    $conf_4f_tbl['protokoll']
                );
            } finally {
                estab_assignment_release_policy_lock($connection, $policyLock);
            }
            dv_admin_redirect('hat_assigned');
        }
        if ($action === 'activate_shift') {
            estab_dv_activate_initial_shift(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstschicht_id'] ?? null,
                    'Dienstschicht'
                ),
                $actor,
                $conf_4f_tbl['protokoll'],
                (string) $conf_4f['ablage_dir']
            );
            dv_admin_redirect('shift_activated');
        }
        if ($action === 'handover_shift') {
            estab_dv_initiate_handover_shift(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['von_dienstschicht_id'] ?? null,
                    'Abgebende Schicht'
                ),
                estab_dv_positive_id(
                    $_POST['an_dienstschicht_id'] ?? null,
                    'Übernehmende Schicht'
                ),
                $_POST['zusammenfassung'] ?? null,
                $actor,
                $conf_4f_tbl['protokoll']
            );
            dv_admin_redirect('shift_handover_initiated');
        }
        if ($action === 'cancel_handover') {
            estab_dv_cancel_handover_request(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstuebergabe_anfrage_id'] ?? null,
                    'Übergabeanforderung'
                ),
                $_POST['stornierungsgrund'] ?? null,
                $actor,
                $conf_4f_tbl['protokoll']
            );
            dv_admin_redirect('shift_handover_cancelled');
        }
        if ($action === 'close_shift') {
            estab_dv_close_shift(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstschicht_id'] ?? null,
                    'Dienstschicht'
                ),
                $actor,
                $conf_4f_tbl['protokoll'],
                (string) $conf_4f['ablage_dir']
            );
            dv_admin_redirect('shift_closed');
        }
        throw new EstabDvInputException('Unbekannte administrative Aktion.');
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
        | EstabAssignmentBusyException
        | EstabNoActiveIncidentException $exception
    ) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('eStab Führungsstellen-Administration: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Die Führungsstellenänderung konnte nicht gespeichert werden.';
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
$shifts = [];
$users = [];
$functionRoles = [];
$blockers = null;
$finalShiftPreflight = null;
$handoverRequests = [];
try {
    $connection = estab_auth_connect($conf_4f_db);
    $status = estab_incident_status($connection);
    if ($status['active_einsatz_id'] !== null) {
        $incidentId = (int) $status['active_einsatz_id'];
        $shifts = estab_dv_shift_list($connection, $incidentId);
        $handoverRequests = estab_dv_handover_requests(
            $connection,
            $incidentId
        );
        $users = estab_auth_fetch_users($connection, $conf_4f_tbl['benutzer']);
        $policyLock = estab_assignment_acquire_policy_lock(
            $connection,
            (string) $conf_4f_db['datenbank'],
            $conf_4f_tbl['empfmtx']
        );
        try {
            $functionRoles = estab_dv_function_roles(
                $connection,
                $conf_4f_tbl['empfmtx']
            );
        } finally {
            estab_assignment_release_policy_lock($connection, $policyLock);
        }
        $blockers = estab_dv_incident_closure_blockers(
            $connection,
            $incidentId
        );
        foreach ($shifts as $shift) {
            if (($shift['status'] ?? null) === 'AKTIV') {
                $finalShiftPreflight = estab_incident_close_preflight(
                    $connection,
                    $incidentId,
                    (string) $conf_4f['ablage_dir'],
                    (int) $shift['dienstschicht_id']
                );
                break;
            }
        }
    }
} catch (Throwable $exception) {
    error_log('eStab Führungsstellenübersicht: ' . $exception->getMessage());
    if ($error === null) {
        http_response_code(503);
        $error = 'Die Führungsstellenübersicht ist derzeit nicht verfügbar.';
    }
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}

$flashMessages = [
    'shift_created' => 'Die geplante Dienstschicht wurde angelegt.',
    'hat_assigned' => 'Die Funktionsbesetzung wurde verbindlich zugewiesen.',
    'shift_activated' => 'Die erste Dienstschicht ist jetzt aktiv.',
    'shift_handover_initiated' =>
        'Die Übergabe wurde angefordert. Ein persönlich angemeldetes Konto '
        . 'der Nachfolgeschicht muss sie jetzt bestätigen.',
    'shift_handover_cancelled' =>
        'Die unbestätigte Übergabeanforderung wurde mit Begründung '
        . 'revisionssicher storniert.',
    'shift_closed' => 'Die Dienstschicht wurde geschlossen.',
];
$resultValue = $_GET['result'] ?? null;
if (is_string($resultValue) && isset($flashMessages[$resultValue])) {
    $flash = $flashMessages[$resultValue];
}
$activeShift = null;
$plannedShifts = [];
$hasActivationHistory = false;
foreach ($shifts as $shift) {
    if (($shift['aktiviert_am'] ?? null) !== null) {
        $hasActivationHistory = true;
    }
    if (($shift['status'] ?? null) === 'AKTIV') {
        $activeShift = $shift;
    } elseif (($shift['status'] ?? null) === 'GEPLANT') {
        $plannedShifts[] = $shift;
    }
}
$hasOpenHandover = false;
foreach ($handoverRequests as $handoverRequest) {
    if (($handoverRequest['status'] ?? null) === 'INITIIERT') {
        $hasOpenHandover = true;
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
<main class="estab-tool-main" data-estab-dv-admin>
  <header class="estab-tool-hero">
    <p class="estab-tool-eyebrow">Administration · DV 1-101</p>
    <h1>Führungsstelle und Dienstschichten</h1>
    <p>Hier werden Schichten vorbereitet, Funktionen echten Konten
      zugewiesen und Übergaben revisionssicher dokumentiert. Eine Person darf
      mehrere getrennte Funktionen übernehmen; jede Funktion muss sie selbst
      in der eStab-Oberfläche annehmen.</p>
  </header>

  <?php if ($error !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
      <?= estab_admin_html($error) ?>
    </p>
  <?php endif; ?>
  <?php if ($flash !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
      <?= estab_admin_html($flash) ?>
    </p>
  <?php endif; ?>

  <?php if (!is_array($status) || $status['active_einsatz_id'] === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Kein Einsatz aktiv.</strong>
      <span>Aktivieren Sie zuerst einen Einsatz; Dienstbesetzungen sind strikt
        einsatzgebunden.</span>
    </section>
  <?php else: ?>
    <section class="estab-tool-status estab-tool-status-active">
      <div>
        <span>Aktiver Einsatz</span>
        <strong><?= estab_admin_html(
            $status['kennung'] . ' · ' . $status['name']
        ) ?></strong>
      </div>
      <div>
        <span>Dienstbetrieb</span>
        <strong><?= $activeShift === null
            ? 'Noch keine aktive Schicht'
            : estab_admin_html(
                '#' . $activeShift['nummer'] . ' · '
                . $activeShift['bezeichnung']
            ) ?></strong>
      </div>
    </section>

    <?php foreach ($handoverRequests as $handoverRequest): ?>
      <?php if (($handoverRequest['status'] ?? null) !== 'INITIIERT') {
          continue;
      } ?>
      <section class="estab-tool-panel" aria-label="Offene Übergabeanforderung">
        <header class="estab-tool-panel-heading">
          <h2>Übergabe wartet auf persönliche Bestätigung</h2>
          <p>Schicht #<?= (int) $handoverRequest['von_nummer'] ?> →
            Schicht #<?= (int) $handoverRequest['an_nummer'] ?> · initiiert
            von <?= estab_admin_html($handoverRequest['initiiert_von']) ?>
            am <?= estab_admin_html($handoverRequest['initiiert_am']) ?></p>
        </header>
        <p><?= nl2br(estab_admin_html(
            $handoverRequest['zusammenfassung']
        )) ?></p>
        <form class="estab-tool-form" method="post"
          action="fuehrungsstelle.php">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="cancel_handover">
          <input type="hidden" name="dienstuebergabe_anfrage_id"
            value="<?= (int) $handoverRequest[
                'dienstuebergabe_anfrage_id'
            ] ?>">
          <label>Stornierungsgrund
            <textarea name="stornierungsgrund" maxlength="10000"
              required></textarea>
          </label>
          <button class="estab-button estab-button-danger-outline"
            type="submit">Fehlanforderung begründet stornieren</button>
        </form>
      </section>
    <?php endforeach; ?>

    <section class="estab-tool-panel">
      <header class="estab-tool-panel-heading">
        <h2>Schicht vorbereiten</h2>
        <p>Die Pflichtfunktionen S2, Si, S6, LdF und A/W müssen mindestens
          einmal zugewiesen und persönlich angenommen sein, bevor eine
          Schicht aktiv werden kann. Mehrere A/W-Besetzungen sind ausdrücklich
          möglich.</p>
      </header>
      <form class="estab-tool-form" method="post" action="fuehrungsstelle.php">
        <?= estab_csrf_field() ?>
        <input type="hidden" name="admin_action" value="create_shift">
        <label>Schichtbezeichnung
          <input name="bezeichnung" maxlength="100"
            placeholder="z. B. Tagschicht 30.07." required>
        </label>
        <label>Vorgängerschicht
          <select name="vorgaenger_id">
            <option value="">Erste Schicht / keine</option>
            <?php foreach ($shifts as $shift): ?>
              <?php if (in_array($shift['status'], ['AKTIV', 'GEPLANT'], true)): ?>
                <option value="<?= (int) $shift['dienstschicht_id'] ?>">
                  #<?= (int) $shift['nummer'] ?> ·
                  <?= estab_admin_html($shift['bezeichnung']) ?> ·
                  <?= estab_admin_html($shift['status']) ?>
                </option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="estab-button estab-button-primary" type="submit">
          Geplante Schicht anlegen
        </button>
      </form>
    </section>

    <?php foreach ($plannedShifts as $shift): ?>
      <section class="estab-tool-panel">
        <header class="estab-tool-panel-heading">
          <h2>#<?= (int) $shift['nummer'] ?> ·
            <?= estab_admin_html($shift['bezeichnung']) ?></h2>
          <p>Geplant · Funktionsträger müssen ihre Zuweisung noch selbst
            annehmen.</p>
        </header>
        <div class="estab-tool-table-wrap">
          <table class="estab-tool-table">
            <thead><tr>
              <th>Funktion</th><th>Person</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($shift['besetzungen'] as $hat): ?>
              <tr>
                <td><?= estab_admin_html(
                    $hat['funktion'] . ' · ' . $hat['rolle']
                ) ?></td>
                <td><?= estab_admin_html(
                    $hat['benutzer'] . ' (' . $hat['benutzer_kuerzel'] . ')'
                ) ?></td>
                <td><?= estab_admin_html($hat['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <form class="estab-tool-form" method="post"
          action="fuehrungsstelle.php">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="assign_hat">
          <input type="hidden" name="dienstschicht_id"
            value="<?= (int) $shift['dienstschicht_id'] ?>">
          <p>Ein ungesperrtes Konto kann bereits für die kommende Schicht
            eingeplant werden. Die Person muss sich erst zur persönlichen
            Annahme anmelden. Die danach gespeicherte Annahme zählt auch nach
            der Abmeldung; ein gesperrtes Konto zählt dagegen nie.</p>
          <label>Benutzerkonto
            <select name="benutzer_kuerzel" required>
              <?php foreach ($users as $user): ?>
                <?php $userBlocked =
                    (int) ($user['estab_gesperrt'] ?? 0) === 1; ?>
                <option value="<?= estab_admin_html($user['kuerzel']) ?>"
                  <?= $userBlocked ? 'disabled' : '' ?>>
                  <?= estab_admin_html(
                      $user['benutzer'] . ' (' . $user['kuerzel'] . ') · '
                      . (
                          $userBlocked
                              ? 'gesperrt'
                              : (
                                  (int) ($user['aktiv'] ?? 0) === 1
                                      ? 'online'
                                      : 'nicht angemeldet'
                              )
                      )
                  ) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Zusätzlicher Funktions-Hut
            <select name="funktion" required>
              <?php foreach ($functionRoles as $function => $role): ?>
                <option value="<?= estab_admin_html($function) ?>">
                  <?= estab_admin_html($function . ' · ' . $role) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="estab-button" type="submit">Funktion zuweisen</button>
        </form>
        <?php $missing = array_values(array_diff(
            ESTAB_DV_REQUIRED_HATS,
            array_map(
                static fn (array $hat): string =>
                    $hat['status'] === 'ANGENOMMEN'
                        && (int) ($hat['benutzer_gesperrt'] ?? 1) === 0
                    ? $hat['funktion']
                    : '',
                $shift['besetzungen']
            )
        )); ?>
        <?php if ($missing === []): ?>
          <?php if ($activeShift === null && !$hasActivationHistory): ?>
            <form method="post" action="fuehrungsstelle.php">
              <?= estab_csrf_field() ?>
              <input type="hidden" name="admin_action" value="activate_shift">
              <input type="hidden" name="dienstschicht_id"
                value="<?= (int) $shift['dienstschicht_id'] ?>">
              <button class="estab-button estab-button-primary" type="submit">
                Als erste Schicht aktivieren
              </button>
            </form>
          <?php elseif ($activeShift !== null
              && (int) ($shift['vorgaenger_id'] ?? 0)
              === (int) $activeShift['dienstschicht_id']): ?>
            <form class="estab-tool-form" method="post"
              action="fuehrungsstelle.php">
              <?= estab_csrf_field() ?>
              <input type="hidden" name="admin_action" value="handover_shift">
              <input type="hidden" name="von_dienstschicht_id"
                value="<?= (int) $activeShift['dienstschicht_id'] ?>">
              <input type="hidden" name="an_dienstschicht_id"
                value="<?= (int) $shift['dienstschicht_id'] ?>">
              <label>Übergabezusammenfassung
                <textarea name="zusammenfassung" maxlength="10000"
                  required></textarea>
              </label>
              <p class="estab-tool-feedback">Die Administration fordert die
                Übergabe nur an. Ein persönlich angemeldetes Konto mit
                angenommener Funktion in der Nachfolgeschicht bestätigt sie
                anschließend im Führungsstellenbetrieb.</p>
              <button class="estab-button estab-button-primary" type="submit">
                Übergabe verbindlich anfordern
              </button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p class="estab-tool-feedback">
            Noch nicht angenommen:
            <strong><?= estab_admin_html(implode(', ', $missing)) ?></strong>
          </p>
        <?php endif; ?>
        <form method="post" action="fuehrungsstelle.php">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="close_shift">
          <input type="hidden" name="dienstschicht_id"
            value="<?= (int) $shift['dienstschicht_id'] ?>">
          <button class="estab-button estab-button-danger-outline" type="submit">
            Planung schließen
          </button>
        </form>
      </section>
    <?php endforeach; ?>

    <?php if ($activeShift !== null): ?>
      <section class="estab-tool-panel">
        <header class="estab-tool-panel-heading">
          <h2>Aktive Schicht #<?= (int) $activeShift['nummer'] ?></h2>
          <p><?= estab_admin_html($activeShift['bezeichnung']) ?></p>
        </header>
        <div class="estab-tool-table-wrap">
          <table class="estab-tool-table">
            <thead><tr><th>Funktion</th><th>Person</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($activeShift['besetzungen'] as $hat): ?>
              <tr>
                <td><?= estab_admin_html($hat['funktion']) ?></td>
                <td><?= estab_admin_html(
                    $hat['benutzer'] . ' (' . $hat['benutzer_kuerzel'] . ')'
                ) ?></td>
                <td><?= estab_admin_html($hat['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($plannedShifts === []
            && !$hasOpenHandover
            && ($finalShiftPreflight['closable'] ?? false)): ?>
          <form method="post" action="fuehrungsstelle.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="admin_action" value="close_shift">
            <input type="hidden" name="dienstschicht_id"
              value="<?= (int) $activeShift['dienstschicht_id'] ?>">
            <button class="estab-button estab-button-danger-outline"
              type="submit">Letzte Schicht schließen</button>
          </form>
        <?php else: ?>
          <p class="estab-tool-feedback">Die aktive Schicht bleibt offen,
            bis alle Nachrichten, Sperren, Anhänge, Nachweise,
            Fernmeldeplanentwürfe, Melderaufträge, Nachfolgeschichten und
            Übergabeanforderungen fachlich abgeschlossen sind. So bleibt der
            Einsatz jederzeit arbeitsfähig und gerät nicht in einen Zustand
            ohne aktive Schicht.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (is_array($blockers)): ?>
      <section class="estab-tool-panel">
        <header class="estab-tool-panel-heading">
          <h2>DV-Abschlussstatus</h2>
          <p>Dieser Status kann von der formalen Einsatzabschlussprüfung
            unverändert übernommen werden.</p>
        </header>
        <dl>
          <dt>Offene Schichten</dt>
          <dd><?= (int) $blockers['offene_schichten'] ?></dd>
          <dt>Offene Besetzungen</dt>
          <dd><?= (int) $blockers['offene_besetzungen'] ?></dd>
          <dt>Offene Melderaufträge</dt>
          <dd><?= (int) $blockers['offene_melderauftraege'] ?></dd>
          <dt>Offene Fernmeldeplanentwürfe</dt>
          <dd><?= (int) $blockers['offene_fernmeldeplanentwuerfe'] ?></dd>
          <dt>Offene Übergabeanforderungen</dt>
          <dd><?= (int) $blockers['offene_uebergabeanforderungen'] ?></dd>
          <dt>Betriebsereigniskette</dt>
          <dd><?= $blockers['betriebsereigniskette_gueltig']
              ? 'gültig'
              : 'FEHLER' ?></dd>
        </dl>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <footer class="estab-tool-footer">
    <a href="admin.php">Zurück zur Administration</a>
    <span>Schichten, Besetzungen und Übergaben bleiben nachvollziehbar erhalten.</span>
  </footer>
</main>
</body>
</html>
