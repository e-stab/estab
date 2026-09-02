<?php

declare(strict_types=1);

/*
 * Melderauftraege -- eigene Seite, eigener Ort.
 *
 * Sie standen bis hierher unten am Fernmeldeplan. Das war die Sammelstelle
 * "Fuehrungsstellenbetrieb", und sie warf zwei Dinge zusammen, die wenig
 * miteinander zu tun haben: Der Fernmeldeplan ist eine Unterlage, die der S6
 * fuehrt und die tagelang gilt. Ein Melderauftrag ist ein einzelner
 * Botengang, den der LdF erteilt und der in einer Stunde erledigt ist. Wer
 * einen Melder losschicken wollte, blaetterte an einem Plan vorbei; wer den
 * Plan las, fand fremde Formulare darunter.
 *
 * Die Annahme und Auswahl der Dienstfunktion bleibt am Fernmeldeplan. Sie
 * ist eine persoenliche Handlung, die fuer alle Bereiche gilt; zwei Stellen,
 * an denen man dieselbe Funktion annehmen kann, waeren zwei Stellen, an
 * denen man sich fragt, welche gilt.
 */

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
    'messenger-jobs',
    $_SERVER
);
$identity = estab_auth_session_identity($_SESSION);
if ($identity === null) {
    throw new LogicException('Authenticated messenger identity missing');
}
/*
 * Ohne angetretenen Dienst geht es zur Funktionswahl, nicht zur Seite.
 *
 * Wie an jedem anderen unmittelbar erreichbaren Endpunkt: In der Betriebsart
 * "streng" steht erst nach der persoenlichen Annahme fest, in welcher
 * Funktion jemand handelt -- und ein Melderauftrag ohne Funktion waere ein
 * Auftrag ohne Auftraggeber.
 */
estab_navigation_require_selected_duty(
    $_SESSION,
    $identity,
    'messenger-jobs',
    $_SERVER
);
$operationIdentity = estab_read_session_identity($_SESSION) ?? $identity;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

/**
 * Nach einer Handlung wird umgeleitet, nicht ausgegeben.
 *
 * Sonst fragt der Browser beim Neuladen nach dem erneuten Senden -- fuer
 * einen Auftrag, der genau einmal erteilt werden darf.
 */
function melderauftrag_redirect(
    string $result,
    array $parameters = []
): never {
    $query = http_build_query(
        ['result' => $result] + $parameters,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    header(
        'Location: melderauftraege.php?' . $query . '#melderauftraege',
        true,
        303
    );
    exit;
}

function melderauftrag_html(mixed $value): string
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
            throw new EstabDvInputException('Unbekannte Melderhandlung.');
        }
        $connection = estab_auth_connect($conf_4f_db);
        $incident = estab_incident_require_active($connection);
        estab_permission_context_set_from_incident($incident);
        $incidentId = (int) $incident['active_einsatz_id'];
        if ($action === 'assign_messenger') {
            $assignmentDetails = null;
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
                $conf_4f_tbl['protokoll'],
                $assignmentDetails
            );
            $requiresNotification = !is_array($assignmentDetails)
                || ($assignmentDetails['requires_separate_notification']
                    ?? true) === true;
            $presenceState = is_array($assignmentDetails)
                && is_string($assignmentDetails['presence_state'] ?? null)
                    ? $assignmentDetails['presence_state']
                    : 'unknown';
            melderauftrag_redirect(
                $requiresNotification
                    ? 'messenger_assigned_notification_required'
                    : 'messenger_assigned',
                ['presence' => $presenceState]
            );
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
            melderauftrag_redirect('messenger_updated');
        }
        throw new EstabDvInputException('Unbekannte Melderhandlung.');
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
    } catch (EstabDvConflictException $exception) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (
        EstabIncidentConfigurationException
        | EstabNoActiveIncidentException $exception
    ) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('eStab Melderaufträge: ' . $exception->getMessage());
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
$jobs = [];
$users = [];
$eligibleMessages = [];
$isLdf = false;
$isAw = false;
$selectedIdentity = null;
$strictMode = true;
$connection = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $status = estab_incident_status($connection);
    if ($status['active_einsatz_id'] !== null) {
        estab_permission_context_set_from_incident($status);
        $incidentId = (int) $status['active_einsatz_id'];
        $code = (string) $identity['kuerzel'];
        $strictMode = estab_incident_duty_shift_required($status);
        try {
            $readScope = estab_read_require_operational_scope(
                $connection,
                estab_read_session_identity($_SESSION) ?? []
            );
            $selectedIdentity = $readScope['identity'];
        } catch (EstabReadPermissionException $exception) {
            if (!$strictMode) {
                throw $exception;
            }
            /*
             * Im strengen Modus steht die Dienstfunktion erst nach der
             * persoenlichen Annahme fest. Die Seite bleibt erreichbar und
             * sagt, was fehlt -- sie behauptet nur nichts ueber Auftraege.
             */
        }
        if (is_array($selectedIdentity)) {
            $operationIdentity = $selectedIdentity;
            $isLdf = estab_dv_has_write_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'FERNMELDEBETRIEB',
                false
            );
            $isAw = estab_dv_has_write_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'BEFOERDERUNG',
                false
            );
            /*
             * Wer beauftragt oder befoerdert, sieht alle Auftraege; wer nur
             * geht, sieht die eigenen.
             */
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
                $eligibleMessages = estab_dv_messenger_eligible_messages(
                    $connection,
                    $incidentId,
                    (string) $conf_4f_tbl['nachrichten']
                );
            }
        }
    }
} catch (Throwable $exception) {
    error_log('eStab Melderaufträge: ' . $exception->getMessage());
    if ($error === null) {
        http_response_code(503);
        $error = 'Die Melderaufträge sind derzeit nicht verfügbar.';
    }
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}

$flashMessages = [
    'messenger_assigned' => 'Der Melderauftrag wurde verbindlich erteilt.',
    'messenger_assigned_notification_required' =>
        'Der Melderauftrag wurde verbindlich erteilt.',
    'messenger_updated' => 'Der Melderstatus wurde nachgewiesen.',
];
$result = $_GET['result'] ?? null;
$flash = is_string($result) ? ($flashMessages[$result] ?? null) : null;
$flashWarning = null;
if ($result === 'messenger_assigned_notification_required') {
    $presenceResult = $_GET['presence'] ?? null;
    $presenceLabel = estab_dv_messenger_presence_label(
        is_string($presenceResult) ? $presenceResult : null
    );
    $flashWarning = 'Status des Fernmelders: ' . $presenceLabel . '. '
        . 'Der LdF muss ihn separat über den Auftrag informieren.';
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Melderaufträge</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
<main class="estab-tool-main" data-estab-messenger-jobs>
  <header class="estab-tool-hero">
    <p class="estab-tool-eyebrow">Einsatzführung · DV 1-101</p>
    <h1>Melderaufträge</h1>
  </header>

  <section class="estab-tool-status estab-tool-status-active
    estab-tool-status-summary">
    <?php if (
        is_array($status)
        && $status['active_einsatz_id'] !== null
        && ($status['fuehrungsstellenname'] ?? null) !== null
    ): ?>
      <div>
        <span>Führungsstelle</span>
        <strong><?= melderauftrag_html(
            $status['fuehrungsstellenname']
        ) ?></strong>
      </div>
    <?php endif; ?>
    <div>
      <span>Angemeldet als</span>
      <strong><?= melderauftrag_html(
          $identity['benutzer'] . ' · ' . $identity['kuerzel']
      ) ?></strong>
    </div>
    <div>
      <span><?= $strictMode
          ? 'Aktive Arbeitsfunktion'
          : 'Kontofunktion' ?></span>
      <strong><?php if ($strictMode && !is_array($selectedIdentity)): ?>
        Noch nicht ausgewählt
      <?php else: ?><?= melderauftrag_html(
          estab_function_identity_display_name(
              (string) ($selectedIdentity['funktion'] ?? $identity['funktion']),
              (string) ($selectedIdentity['rolle'] ?? $identity['rolle'])
          )
      ) ?><?php endif; ?></strong>
    </div>
    <div>
      <span>Berechtigungsmodus</span>
      <strong><?= $strictMode ? 'Streng' : 'Locker' ?></strong>
    </div>
  </section>

  <?php if ($error !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
      <?= melderauftrag_html($error) ?>
    </p>
  <?php endif; ?>
  <?php if ($flash !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
      <?= melderauftrag_html($flash) ?>
    </p>
  <?php endif; ?>
  <?php if ($flashWarning !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-warning" role="status">
      <?= melderauftrag_html($flashWarning) ?>
    </p>
  <?php endif; ?>

  <?php if (!is_array($status) || $status['active_einsatz_id'] === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Kein Einsatz aktiv.</strong>
      <span>Melderaufträge sind gesperrt, bis ein Einsatz aktiv ist.</span>
    </section>
  <?php elseif (!is_array($selectedIdentity)): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Keine wirksame Dienstfunktion.</strong>
      <span>Nehmen Sie zuerst eine zugewiesene Funktion an und wählen Sie die
        Funktion aus, in der Sie gerade arbeiten. Das geschieht am
        Fernmeldeplan und gilt anschließend für alle Bereiche.</span>
      <a class="estab-button"
        href="fuehrungsstelle.php#meine-dienstfunktionen">
        Zu meinen Dienstfunktionen
      </a>
    </section>
  <?php else: ?>
    <section class="estab-tool-panel" id="melderauftraege">
      <header class="estab-tool-panel-heading">
        <h2>Melderaufträge</h2>
        <p>Übernahme, tatsächlicher Empfänger, Rücknachricht, Rückkehr und
          Abschlussmeldung werden als eigene unveränderbare Ereignisse
          protokolliert.</p>
      </header>
      <?php if ($isLdf): ?>
        <form class="estab-tool-form" method="post"
          action="melderauftraege.php" data-estab-messenger-assignment>
          <?= estab_csrf_field() ?>
          <input type="hidden" name="operation_action"
            value="assign_messenger">
          <p class="estab-tool-notice" data-estab-messenger-role-note>
            <strong>Melder oder Kurier?</strong>
            Ein <em>Melder</em> kennt den Inhalt der Nachricht und kann
            Rückfragen der Gegenstelle beantworten. Ein <em>Kurier</em> kennt
            ihn nicht; er überbringt einen verschlossenen Umschlag und kann
            zum Inhalt nichts sagen. Wer mit Rückfragen rechnet, schickt
            einen Melder und weist ihn ein. Der Vordruck unterscheidet die
            beiden nicht — das Kästchen in Feld 1 heißt „Kurier/Melder“;
            die Entscheidung treffen Sie hier.
          </p>
          <label>Ausgangsnachricht mit Weg „Melder“
            <select name="nachricht_id" required>
              <?php foreach ($eligibleMessages as $message): ?>
                <option value="<?= (int) $message['00_lfd'] ?>">
                  A<?= (int) $message['04_nummer'] ?> ·
                  <?= melderauftrag_html($message['10_anschrift']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Melder
            <select name="melder_kuerzel" required
              data-estab-messenger-select>
              <?php if ($users === []): ?>
                <option value="">Kein fachlich berechtigter Fernmelder verfügbar</option>
              <?php else: ?>
                <option value="" selected>Bitte Fernmelder auswählen</option>
              <?php endif; ?>
              <?php foreach ($users as $user): ?>
                <option value="<?= melderauftrag_html($user['kuerzel']) ?>"
                  data-estab-presence-state="<?= melderauftrag_html(
                      $user['presence_state']
                  ) ?>" data-estab-presence-label="<?= melderauftrag_html(
                      $user['presence_label']
                  ) ?>" data-estab-notification-required="<?=
                      ($user['requires_separate_notification'] ?? true)
                          ? '1'
                          : '0' ?>">
                  <?= melderauftrag_html(
                      $user['benutzer'] . ' (' . $user['kuerzel'] . ')'
                          . ' · ' . $user['presence_label']
                  ) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <p class="estab-tool-notice estab-tool-notice-warning" role="status"
            aria-live="polite" hidden data-estab-messenger-presence-warning>
            <strong>Separat informieren:</strong>
            Der gewählte Fernmelder ist aktuell
            <span data-estab-messenger-presence-label>nicht aktiv</span>.
            Der LdF muss ihn separat über den Auftrag informieren.
          </p>
          <label>Ziel
            <input name="ziel" maxlength="255" required>
          </label>
          <button class="estab-button estab-button-primary" type="submit"
            <?= $eligibleMessages === [] || $users === []
                ? 'disabled'
                : '' ?>>
            Melder verbindlich beauftragen
          </button>
          <?php if ($eligibleMessages === []): ?>
            <p class="estab-tool-empty">Zurzeit wartet keine Ausgangsnachricht
              auf einen Melder. Der LdF weist sie im Nachrichtenvordruck dem
              Mittel „Melder“ zu; danach steht sie hier zur Auswahl.</p>
          <?php endif; ?>
        </form>
      <?php endif; ?>

      <?php if ($jobs === []): ?>
        <p class="estab-tool-empty">Keine sichtbaren Melderaufträge.</p>
      <?php else: ?>
        <?php foreach ($jobs as $job): ?>
          <article class="estab-tool-panel">
            <h3>Auftrag #<?= (int) $job['melderauftrag_id'] ?> ·
              A<?= (int) $job['04_nummer'] ?> ·
              <?= melderauftrag_html($job['status']) ?></h3>
            <p><strong>Melder:</strong>
              <?= melderauftrag_html(
                  $job['melder_name'] . ' (' . $job['melder_kuerzel'] . ')'
              ) ?><br>
              <strong>Ziel:</strong> <?= melderauftrag_html($job['ziel']) ?>
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
                action="melderauftraege.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="messenger_transition">
                <input type="hidden" name="melderauftrag_id"
                  value="<?= (int) $job['melderauftrag_id'] ?>">
                <input type="hidden" name="transition"
                  value="<?= melderauftrag_html($transition) ?>">
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
                  <?= melderauftrag_html($button) ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($isLdf && $job['status'] === 'BEAUFTRAGT'): ?>
              <form class="estab-tool-form" method="post"
                action="melderauftraege.php">
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

  <footer class="estab-tool-footer">
    <a href="fuehrungsstelle.php">Zum Fernmeldeplan</a>
    <a href="mainindex.php">Zurück zu Nachrichten</a>
    <span>Alle Änderungen sind einsatzgebunden und hashverkettet.</span>
  </footer>
</main>
<script<?= estab_csp_script_attribute() ?> data-estab-messenger-presence>
(function () {
  'use strict';
  document.querySelectorAll('[data-estab-messenger-assignment]')
    .forEach(function (form) {
      var select = form.querySelector('[data-estab-messenger-select]');
      var warning = form.querySelector(
        '[data-estab-messenger-presence-warning]'
      );
      var label = warning && warning.querySelector(
        '[data-estab-messenger-presence-label]'
      );
      function updateMessengerPresence() {
        if (!select || !warning) return;
        var option = select.options[select.selectedIndex] || null;
        var required = option
          && option.dataset.estabNotificationRequired === '1';
        warning.hidden = !required;
        if (label && option) {
          label.textContent = option.dataset.estabPresenceLabel
            || 'nicht aktiv';
        }
      }
      updateMessengerPresence();
      if (select) {
        select.addEventListener('change', updateMessengerPresence);
      }
    });
}());
</script>
</body>
</html>
