<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';
require_once __DIR__ . '/../app/shift_access.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function shift_admin_redirect(string $result): never
{
    header(
        'Location: fuehrungsstelle.php?result=' . rawurlencode($result),
        true,
        303
    );
    exit;
}

function shift_admin_boolean(mixed $value, string $label): bool
{
    if (!is_string($value) || !in_array($value, ['0', '1'], true)) {
        throw new EstabShiftAccessInputException($label . ' ist ungültig.');
    }
    return $value === '1';
}

function shift_admin_period(array $shift): string
{
    $begin = $shift['beginn'] ?? null;
    $end = $shift['ende'] ?? null;
    if (!is_string($begin) || $begin === '') {
        $begin = null;
    }
    if (!is_string($end) || $end === '') {
        $end = null;
    }
    if ($begin === null && $end === null) {
        return 'Kein Zeitraum hinterlegt';
    }
    if ($begin !== null && $end !== null) {
        return $begin . ' bis ' . $end;
    }
    return $begin !== null
        ? 'Ab ' . $begin
        : 'Bis ' . $end;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$error = null;

if ($requestMethod === 'POST') {
    $connection = null;
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $actionValue = $_POST['admin_action'] ?? null;
        $action = is_string($actionValue) ? $actionValue : '';
        if (!in_array(
            $action,
            ['create_shift', 'add_member', 'remove_member', 'set_enabled'],
            true
        )) {
            throw new EstabShiftAccessInputException(
                'Unbekannte administrative Aktion.'
            );
        }

        $connection = estab_auth_connect($conf_4f_db);
        $incident = estab_incident_require_active($connection);
        $incidentId = (int) $incident['active_einsatz_id'];
        $actor = estab_shift_access_actor(
            $_SERVER['REMOTE_USER'] ?? 'admin'
        );
        $protocolTable = $conf_4f_tbl['protokoll'];

        if ($action === 'create_shift') {
            estab_shift_access_create(
                $connection,
                $incidentId,
                $_POST['bezeichnung'] ?? null,
                $_POST['beginn'] ?? null,
                $_POST['ende'] ?? null,
                $actor,
                $protocolTable
            );
            shift_admin_redirect('shift_created');
        }

        $shiftId = estab_shift_access_positive_id(
            $_POST['zugangsschicht_id'] ?? null,
            'Zugangsschicht'
        );
        if ($action === 'add_member') {
            if (($_POST['confirm_assignment'] ?? null) !== '1') {
                throw new EstabShiftAccessInputException(
                    'Bitte bestätigen Sie die Schichtzuordnung.'
                );
            }
            $result = estab_shift_access_add_member(
                $connection,
                $incidentId,
                $shiftId,
                $_POST['benutzer_kuerzel'] ?? null,
                $actor,
                $protocolTable
            );
            shift_admin_redirect(
                ($result['session_revoked'] ?? false)
                    ? 'member_added_revoked'
                    : 'member_added'
            );
        }
        if ($action === 'remove_member') {
            $result = estab_shift_access_remove_member(
                $connection,
                $incidentId,
                $shiftId,
                $_POST['benutzer_kuerzel'] ?? null,
                $_POST['zugangsschicht_mitglied_id'] ?? null,
                $_POST['expected_confirmation_version'] ?? null,
                $actor,
                $protocolTable
            );
            shift_admin_redirect(
                ($result['session_revoked'] ?? false)
                    ? 'member_removed_revoked'
                    : 'member_removed'
            );
        }

        $enabled = shift_admin_boolean(
            $_POST['zugang_aktiv'] ?? null,
            'Gewünschter Zugangsstatus'
        );
        $expectedEnabled = shift_admin_boolean(
            $_POST['expected_enabled'] ?? null,
            'Bisheriger Zugangsstatus'
        );
        $result = estab_shift_access_set_enabled(
            $connection,
            $incidentId,
            $shiftId,
            $enabled,
            $expectedEnabled,
            $_POST['expected_confirmation_version'] ?? null,
            $actor,
            $protocolTable
        );
        if (!($result['changed'] ?? false)) {
            shift_admin_redirect('no_change');
        }
        if ($enabled) {
            shift_admin_redirect('shift_enabled');
        }
        shift_admin_redirect(
            ($result['revoked_accounts'] ?? []) === []
                ? 'shift_disabled'
                : 'shift_disabled_revoked'
        );
    } catch (EstabCsrfException) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (
        EstabShiftAccessConflictException
        | EstabShiftAccessBusyException
        | EstabIncidentConfigurationException
        | EstabNoActiveIncidentException $exception
    ) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log(
            'eStab optionale Zugangsschichten: ' . $exception->getMessage()
        );
        http_response_code(500);
        $error = 'Die Schichtänderung konnte nicht vollständig und atomar '
            . 'gespeichert werden. Details stehen im Container-Log.';
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
$overviewLoaded = false;
$connection = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $status = estab_incident_status($connection);
    if ($status['active_einsatz_id'] !== null) {
        $incidentId = (int) $status['active_einsatz_id'];
        $users = estab_auth_fetch_users(
            $connection,
            $conf_4f_tbl['benutzer']
        );
        $shifts = estab_shift_access_list($connection, $incidentId);
    }
    $overviewLoaded = true;
} catch (Throwable $exception) {
    error_log(
        'eStab Zugangsschichtübersicht: ' . $exception->getMessage()
    );
    if ($error === null) {
        http_response_code(503);
        $error = 'Die Schichtübersicht ist derzeit nicht verfügbar.';
    }
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}

$flashMessages = [
    'shift_created' => 'Die optionale Schicht wurde deaktiviert angelegt.',
    'member_added' => 'Das Konto wurde der Schicht zugeordnet.',
    'member_added_revoked' => 'Das Konto wurde zugeordnet und seine bisherige '
        . 'Sitzung beendet, weil keine zugeordnete Schicht aktiv ist.',
    'member_removed' => 'Das Konto wurde aus der Schicht entfernt.',
    'member_removed_revoked' => 'Das Konto wurde entfernt und seine bisherige '
        . 'Sitzung beendet, weil nur noch deaktivierte Zuordnungen bestehen.',
    'shift_enabled' => 'Der gemeinsame Zugang dieser Schicht ist jetzt aktiv. '
        . 'Es wurde niemand automatisch angemeldet.',
    'shift_disabled' => 'Der gemeinsame Zugang dieser Schicht ist jetzt '
        . 'deaktiviert.',
    'shift_disabled_revoked' => 'Der gemeinsame Zugang wurde deaktiviert. '
        . 'Betroffene laufende Sitzungen wurden sofort beendet.',
    'no_change' => 'Der gewünschte Schichtstatus war bereits gesetzt.',
];
$resultValue = $_GET['result'] ?? null;
$flash = is_string($resultValue) && isset($flashMessages[$resultValue])
    ? $flashMessages[$resultValue]
    : null;

$shiftMembershipsByUser = [];
$activeShiftCount = 0;
$memberCount = 0;
foreach ($shifts as $shift) {
    $shiftEnabled = (int) ($shift['zugang_aktiv'] ?? 0) === 1;
    if ($shiftEnabled) {
        $activeShiftCount++;
    }
    foreach (($shift['mitglieder'] ?? []) as $member) {
        $code = (string) ($member['benutzer_kuerzel'] ?? '');
        if ($code === '') {
            continue;
        }
        $memberCount++;
        $shiftMembershipsByUser[$code][] = [
            'zugangsschicht_id' => (int) $shift['zugangsschicht_id'],
            'bezeichnung' => (string) $shift['bezeichnung'],
            'zugang_aktiv' => $shiftEnabled,
        ];
    }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Optionale Schichten</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
<main class="estab-tool-main estab-tool-main-wide" data-estab-shift-admin>
  <header class="estab-tool-hero">
    <p class="estab-tool-eyebrow">Administration · Zugangssteuerung</p>
    <h1>Optionale Schichten</h1>
    <p>Fassen Sie Konten bei Bedarf zu Schichten zusammen und schalten Sie
      deren Anmeldung gemeinsam frei oder aus. Ohne Zuordnung behält ein
      Konto seinen individuellen Zugang.</p>
  </header>

  <aside class="estab-tool-notice" aria-label="Geltungsbereich">
    <strong>Schichten steuern ausschließlich den Zugang.</strong>
    <p>Fachrechte stammen immer aus der festen Funktion und Rolle des Kontos.
      Für operative Eingaben ist ein aktiver Einsatz zwingend; eine aktive
      Schicht ist dafür niemals erforderlich. Eine individuelle Kontosperre
      hat unabhängig von jeder Schicht Vorrang.</p>
  </aside>

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

  <?php if (!$overviewLoaded): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="status">
      <div>
        <strong>Schichtübersicht nicht geladen.</strong>
        <span>Es werden keine leeren oder möglicherweise veralteten
          Schicht- und Zugangsdaten angezeigt.</span>
      </div>
      <a class="estab-button" href="fuehrungsstelle.php">Erneut laden</a>
    </section>
  <?php elseif (!is_array($status) || $status['active_einsatz_id'] === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <div>
        <strong>Kein Einsatz aktiv.</strong>
        <span>Schichten sind einsatzgebunden. Aktivieren Sie zuerst einen
          Einsatz; operative Eingaben bleiben bis dahin gesperrt.</span>
      </div>
      <a class="estab-button" href="incidents.php">Einsatz verwalten</a>
    </section>
  <?php else: ?>
    <section class="estab-tool-status estab-tool-status-active"
      aria-label="Aktiver Einsatz">
      <div>
        <strong><?= estab_admin_html(
            $status['kennung'] . ' · ' . $status['name']
        ) ?></strong>
        <span>Führungsstelle
          <?= estab_admin_html(
              $status['fuehrungsstellenname'] ?? 'noch nicht benannt'
          ) ?></span>
      </div>
      <div>
        <strong><?= count($shifts) ?> Schichten ·
          <?= $activeShiftCount ?> aktiv</strong>
        <span><?= $memberCount ?> aktuelle Zuordnungen</span>
      </div>
    </section>

    <section class="estab-tool-panel">
      <header class="estab-tool-panel-heading">
        <h2>Schicht anlegen</h2>
        <p>Name und Zeitraum dienen nur der Planung. Eine neue Schicht ist
          zunächst deaktiviert und verändert keine Fachberechtigung.</p>
      </header>
      <form class="estab-tool-form" method="post"
        action="fuehrungsstelle.php">
        <?= estab_csrf_field() ?>
        <input type="hidden" name="admin_action" value="create_shift">
        <div class="estab-tool-form-grid">
          <div class="estab-tool-field estab-tool-field-wide">
            <label for="shift-name">Bezeichnung</label>
            <input id="shift-name" name="bezeichnung" maxlength="100"
              autocomplete="off" placeholder="z. B. Nachtschicht 01.08."
              required>
          </div>
          <div class="estab-tool-field">
            <label for="shift-begin">Beginn (optional)</label>
            <input id="shift-begin" type="datetime-local" name="beginn">
          </div>
          <div class="estab-tool-field">
            <label for="shift-end">Ende (optional)</label>
            <input id="shift-end" type="datetime-local" name="ende">
          </div>
        </div>
        <div class="estab-tool-actions">
          <button class="estab-button estab-button-primary" type="submit">
            Deaktivierte Schicht anlegen
          </button>
        </div>
      </form>
    </section>

    <section aria-labelledby="shift-list-title">
      <header class="estab-tool-panel-heading">
        <h2 id="shift-list-title">Schichten und Zuordnungen</h2>
        <p>Ein Konto mit mehreren Zuordnungen darf sich anmelden, sobald
          mindestens eine seiner Schichten aktiv ist.</p>
      </header>

      <?php if ($shifts === []): ?>
        <div class="estab-tool-table-wrap">
          <p class="estab-tool-empty">Noch keine optionale Schicht angelegt.
            Konten sind deshalb nicht über eine Schicht eingeschränkt.</p>
        </div>
      <?php endif; ?>

      <div class="estab-tool-list">
      <?php foreach ($shifts as $shift): ?>
        <?php
        $shiftId = (int) $shift['zugangsschicht_id'];
        $shiftLabel = (string) $shift['bezeichnung'];
        $confirmationVersion = estab_shift_access_confirmation_version(
            $shifts,
            $shiftId
        );
        $shiftEnabled = (int) $shift['zugang_aktiv'] === 1;
        $members = is_array($shift['mitglieder'] ?? null)
            ? $shift['mitglieder']
            : [];
        $accessEndingLabels = [];
        $sessionRevocationLabels = [];
        $alreadyBlockedLabels = [];
        foreach ($members as $member) {
            $memberCode = (string) ($member['benutzer_kuerzel'] ?? '');
            $memberName = trim((string) ($member['benutzer'] ?? ''));
            $memberLabel = ($memberName === '' ? $memberCode : $memberName)
                . ' [' . $memberCode . ']';
            if ((int) ($member['estab_gesperrt'] ?? 0) === 1) {
                $alreadyBlockedLabels[] = $memberLabel;
                continue;
            }
            $hasOtherEnabledShift = false;
            foreach (($shiftMembershipsByUser[$memberCode] ?? []) as $membership) {
                if (
                    (int) $membership['zugangsschicht_id'] !== $shiftId
                    && $membership['zugang_aktiv']
                ) {
                    $hasOtherEnabledShift = true;
                    break;
                }
            }
            if ($hasOtherEnabledShift) {
                continue;
            }
            $accessEndingLabels[] = $memberLabel;
            if ((int) ($member['session_present'] ?? 0) === 1) {
                $sessionRevocationLabels[] = $memberLabel;
            }
        }
        if ($members === []) {
            $accessEndingSummary = 'Kein Konto zugeordnet.';
        } elseif ($accessEndingLabels === []) {
            $accessEndingSummary = 'Kein zusätzlich zugangsberechtigtes Konto.';
        } else {
            $accessEndingSummary = implode(', ', $accessEndingLabels);
        }
        $assignedCodes = [];
        foreach ($members as $member) {
            $assignedCodes[(string) $member['benutzer_kuerzel']] = true;
        }
        $availableUsers = array_values(array_filter(
            $users,
            static fn (array $user): bool => !isset(
                $assignedCodes[(string) ($user['kuerzel'] ?? '')]
            )
        ));
        ?>
        <article class="estab-tool-panel">
          <header class="estab-tool-panel-heading">
            <div class="estab-tool-actions">
              <h2 id="shift-title-<?= $shiftId ?>"><?=
                  estab_admin_html($shiftLabel) ?></h2>
              <span class="estab-tool-badge <?= $shiftEnabled
                  ? 'estab-tool-badge-success'
                  : 'estab-tool-badge-neutral' ?>">
                Zugang <?= $shiftEnabled ? 'aktiv' : 'deaktiviert' ?>
              </span>
            </div>
            <p><?= estab_admin_html(shift_admin_period($shift)) ?> ·
              <?= count($members) ?> Konten zugeordnet</p>
          </header>

          <div class="estab-tool-status <?= $shiftEnabled
              ? 'estab-tool-status-active'
              : '' ?>">
            <div>
              <strong>Gemeinsamen Zugang <?= $shiftEnabled
                  ? 'deaktivieren'
                  : 'aktivieren' ?></strong>
              <span><?= $shiftEnabled
                  ? 'Konten ohne weitere aktive Schicht werden sofort abgemeldet.'
                  : 'Die nächste Anmeldung wird möglich; niemand wird automatisch angemeldet.' ?></span>
            </div>
            <?php if ($shiftEnabled): ?>
              <details class="estab-tool-details estab-shift-confirmation">
                <summary>Zugang deaktivieren prüfen<span
                  class="estab-visually-hidden"> für Schicht
                  <?= estab_admin_html($shiftLabel) ?></span></summary>
                <p><strong>Zugang endet nach aktuellem Stand für:</strong><br>
                  <?= estab_admin_html($accessEndingSummary) ?></p>
                <?php if ($alreadyBlockedLabels !== []): ?>
                  <p><strong>Bereits individuell gesperrt
                    (unverändert):</strong><br>
                    <?= estab_admin_html(implode(', ', $alreadyBlockedLabels)) ?>
                  </p>
                <?php endif; ?>
                <p><strong>Laufende Sitzungen werden sofort beendet für:</strong><br>
                  <?= estab_admin_html(
                      $sessionRevocationLabels === []
                          ? 'Derzeit kein angemeldetes Konto.'
                          : implode(', ', $sessionRevocationLabels)
                  ) ?></p>
                <form method="post" action="fuehrungsstelle.php">
                  <?= estab_csrf_field() ?>
                  <input type="hidden" name="admin_action"
                    value="set_enabled">
                  <input type="hidden" name="zugangsschicht_id"
                    value="<?= $shiftId ?>">
                  <input type="hidden" name="expected_enabled" value="1">
                  <input type="hidden" name="expected_confirmation_version" value="<?=
                      $confirmationVersion ?>">
                  <input type="hidden" name="zugang_aktiv" value="0">
                  <button class="estab-button estab-button-danger-outline"
                    type="submit">Zugang jetzt deaktivieren<span
                      class="estab-visually-hidden"> für Schicht
                      <?= estab_admin_html($shiftLabel) ?></span></button>
                </form>
              </details>
            <?php else: ?>
              <form method="post" action="fuehrungsstelle.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="admin_action" value="set_enabled">
                <input type="hidden" name="zugangsschicht_id"
                  value="<?= $shiftId ?>">
                <input type="hidden" name="expected_enabled" value="0">
                <input type="hidden" name="expected_confirmation_version" value="<?=
                    $confirmationVersion ?>">
                <input type="hidden" name="zugang_aktiv" value="1">
                <button class="estab-button estab-button-primary" type="submit">
                  Zugang aktivieren<span class="estab-visually-hidden"> für
                    Schicht <?= estab_admin_html($shiftLabel) ?></span>
                </button>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($members === []): ?>
            <div class="estab-tool-table-wrap">
              <p class="estab-tool-empty">Dieser Schicht ist noch kein Konto
                zugeordnet.</p>
            </div>
          <?php else: ?>
            <div class="estab-tool-table-wrap estab-tool-table-responsive">
              <table class="estab-tool-table">
                <thead><tr>
                  <th>Benutzerkonto</th>
                  <th>Feste Funktion</th>
                  <th>Kontostatus</th>
                  <th>Aktion</th>
                </tr></thead>
                <tbody>
                <?php foreach ($members as $member): ?>
                  <?php
                  $blocked = (int) $member['estab_gesperrt'] === 1;
                  $memberCode = (string) $member['benutzer_kuerzel'];
                  $memberName = trim((string) ($member['benutzer'] ?? ''));
                  $memberLabel = ($memberName === '' ? $memberCode : $memberName)
                      . ' [' . $memberCode . ']';
                  $remainingMemberships = array_values(array_filter(
                      $shiftMembershipsByUser[$memberCode] ?? [],
                      static fn (array $membership): bool =>
                          (int) $membership['zugangsschicht_id'] !== $shiftId
                  ));
                  $remainingActiveLabels = [];
                  foreach ($remainingMemberships as $remainingMembership) {
                      if ($remainingMembership['zugang_aktiv']) {
                          $remainingActiveLabels[] = (string) $remainingMembership[
                              'bezeichnung'
                          ];
                      }
                  }
                  if ($blocked) {
                      $removalEffect = 'Das Konto ist individuell gesperrt. '
                          . 'Das Entfernen der Zuordnung hebt diese Sperre '
                          . 'nicht auf; der Zugang bleibt gesperrt.';
                  } elseif ($remainingMemberships === []) {
                      $removalEffect = 'Das Konto ist danach keiner Schicht '
                          . 'zugeordnet und behält seinen individuellen Zugang.';
                  } elseif ($remainingActiveLabels !== []) {
                      $removalEffect = 'Der Zugang bleibt über folgende aktive '
                          . 'Schicht erhalten: '
                          . implode(', ', $remainingActiveLabels) . '.';
                  } elseif ((int) ($member['session_present'] ?? 0) === 1) {
                      $removalEffect = 'Danach bestehen nur deaktivierte '
                          . 'Zuordnungen. Die laufende Sitzung dieses Kontos '
                          . 'wird sofort beendet.';
                  } else {
                      $removalEffect = 'Danach bestehen nur deaktivierte '
                          . 'Zuordnungen. Der Kontozugang ist anschließend '
                          . 'deaktiviert.';
                  }
                  ?>
                  <tr>
                    <th scope="row" data-label="Benutzerkonto"
                      class="estab-tool-identity">
                      <strong><?= estab_admin_html(
                          $member['benutzer'] !== ''
                              ? $member['benutzer']
                              : $member['benutzer_kuerzel']
                      ) ?></strong>
                      <span><?= estab_admin_html(
                          $member['benutzer_kuerzel']
                      ) ?></span>
                    </th>
                    <td data-label="Feste Funktion">
                      <strong><?= estab_admin_html(
                          estab_function_identity_display_name(
                              (string) $member['funktion'],
                              (string) $member['rolle']
                          )
                      ) ?></strong>
                    </td>
                    <td data-label="Kontostatus">
                      <span class="estab-tool-badge <?= $blocked
                          ? 'estab-tool-badge-danger'
                          : 'estab-tool-badge-neutral' ?>">
                        <?= $blocked ? 'Individuell gesperrt' : 'Nicht gesperrt' ?>
                      </span>
                    </td>
                    <td data-label="Aktion">
                      <details class="estab-tool-details">
                        <summary>Zuordnung entfernen prüfen<span
                          class="estab-visually-hidden">: <?= estab_admin_html(
                              $memberLabel
                          ) ?> aus Schicht <?= estab_admin_html(
                              $shiftLabel
                          ) ?></span></summary>
                        <p><?= estab_admin_html($removalEffect) ?></p>
                        <form method="post" action="fuehrungsstelle.php">
                          <?= estab_csrf_field() ?>
                          <input type="hidden" name="admin_action"
                            value="remove_member">
                          <input type="hidden" name="zugangsschicht_id"
                            value="<?= $shiftId ?>">
                          <input type="hidden" name="benutzer_kuerzel"
                            value="<?= estab_admin_html($memberCode) ?>">
                          <input type="hidden"
                            name="zugangsschicht_mitglied_id" value="<?= (int)
                                $member['zugangsschicht_mitglied_id'] ?>">
                          <input type="hidden"
                            name="expected_confirmation_version" value="<?=
                                $confirmationVersion ?>">
                          <button class="estab-button
                            estab-button-danger-outline" type="submit">
                            Zuordnung jetzt entfernen<span
                              class="estab-visually-hidden">: <?=
                                  estab_admin_html($memberLabel) ?> aus Schicht
                              <?= estab_admin_html($shiftLabel) ?></span>
                          </button>
                        </form>
                      </details>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($availableUsers !== []): ?>
            <form class="estab-tool-form" method="post"
              action="fuehrungsstelle.php">
              <?= estab_csrf_field() ?>
              <input type="hidden" name="admin_action" value="add_member">
              <input type="hidden" name="zugangsschicht_id"
                value="<?= $shiftId ?>">
              <div class="estab-tool-field">
                <label for="shift-user-<?= $shiftId ?>">
                  Konto zuordnen<span class="estab-visually-hidden"> zu
                    Schicht <?= estab_admin_html($shiftLabel) ?></span>
                </label>
                <select id="shift-user-<?= $shiftId ?>"
                  name="benutzer_kuerzel" required>
                  <option value="">Benutzerkonto auswählen …</option>
                  <?php foreach ($availableUsers as $user): ?>
                    <option value="<?= estab_admin_html($user['kuerzel']) ?>">
                      <?= estab_admin_html(
                          $user['benutzer'] . ' (' . $user['kuerzel'] . ') · '
                          . estab_function_identity_display_name(
                              (string) $user['funktion'],
                              (string) $user['rolle'],
                              ' / '
                          )
                          . ((int) $user['estab_gesperrt'] === 1
                              ? ' · individuell gesperrt'
                              : '')
                      ) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small>Die feste Funktion bleibt unverändert. Bei einer
                  deaktivierten Schicht kann die Zuordnung eine bestehende
                  Sitzung sofort beenden.</small>
              </div>
              <?php if ($shiftEnabled): ?>
                <input type="hidden" name="confirm_assignment" value="1">
              <?php else: ?>
                <label class="estab-tool-check">
                  <input type="checkbox" name="confirm_assignment" value="1"
                    required>
                  Ich bestätige, dass das oben ausgewählte Konto durch diese
                  Zuordnung sofort abgemeldet werden kann, sofern keine andere
                  aktive Schicht besteht.<span class="estab-visually-hidden">
                    Bestätigung für Schicht <?= estab_admin_html(
                        $shiftLabel
                    ) ?></span>
                </label>
              <?php endif; ?>
              <div class="estab-tool-actions">
                <button class="estab-button" type="submit">
                  Konto zuordnen<span class="estab-visually-hidden"> zu
                    Schicht <?= estab_admin_html($shiftLabel) ?></span>
                </button>
              </div>
            </form>
          <?php else: ?>
            <p class="estab-tool-help">Alle Konten sind dieser Schicht bereits
              zugeordnet.</p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
    </section>

    <section class="estab-tool-panel">
      <header class="estab-tool-panel-heading">
        <h2>Zugangsübersicht nach Konto</h2>
        <p>Diese Übersicht trennt individuelle Sperren, optionale
          Schichtsteuerung und feste Fachfunktion sichtbar voneinander.</p>
      </header>
      <?php if ($users === []): ?>
        <p class="estab-tool-empty">Keine Benutzerkonten vorhanden.</p>
      <?php else: ?>
        <div class="estab-tool-table-wrap estab-tool-table-responsive">
          <table class="estab-tool-table">
            <thead><tr>
              <th>Benutzerkonto</th>
              <th>Feste Funktion</th>
              <th>Schichten</th>
              <th>Wirksamer Zugang</th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
              <?php
              $code = (string) $user['kuerzel'];
              $memberships = $shiftMembershipsByUser[$code] ?? [];
              $blocked = (int) $user['estab_gesperrt'] === 1;
              $hasEnabledMembership = false;
              foreach ($memberships as $membership) {
                  if ($membership['zugang_aktiv']) {
                      $hasEnabledMembership = true;
                      break;
                  }
              }
              if ($blocked) {
                  $accessLabel = 'Individuell gesperrt';
                  $accessBadge = 'estab-tool-badge-danger';
              } elseif ($memberships === []) {
                  $accessLabel = 'Individueller Zugang';
                  $accessBadge = 'estab-tool-badge-neutral';
              } elseif ($hasEnabledMembership) {
                  $accessLabel = 'Über Schicht freigegeben';
                  $accessBadge = 'estab-tool-badge-success';
              } else {
                  $accessLabel = 'Durch Schichtplanung deaktiviert';
                  $accessBadge = 'estab-tool-badge-warning';
              }
              ?>
              <tr>
                <th scope="row" data-label="Benutzerkonto"
                  class="estab-tool-identity">
                  <strong><?= estab_admin_html($user['benutzer']) ?></strong>
                  <span><?= estab_admin_html($code) ?></span>
                </th>
                <td data-label="Feste Funktion">
                  <strong><?= estab_admin_html(
                      estab_function_identity_display_name(
                          (string) $user['funktion'],
                          (string) $user['rolle']
                      )
                  ) ?></strong>
                </td>
                <td data-label="Schichten">
                  <?php if ($memberships === []): ?>
                    Keine Zuordnung
                  <?php else: ?>
                    <?php foreach ($memberships as $index => $membership): ?>
                      <?= $index > 0 ? '<br>' : '' ?>
                      <span class="estab-tool-badge <?=
                          $membership['zugang_aktiv']
                              ? 'estab-tool-badge-success'
                              : 'estab-tool-badge-neutral' ?>">
                        <?= estab_admin_html($membership['bezeichnung']) ?> ·
                        <?= $membership['zugang_aktiv'] ? 'aktiv' : 'aus' ?>
                      </span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </td>
                <td data-label="Wirksamer Zugang">
                  <span class="estab-tool-badge <?= $accessBadge ?>">
                    <?= estab_admin_html($accessLabel) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <footer class="estab-tool-footer">
    <a href="admin.php">Zurück zur Administration</a>
    <span>Zuordnungen und Statusänderungen werden einsatzbezogen protokolliert.</span>
  </footer>
</main>
</body>
</html>
