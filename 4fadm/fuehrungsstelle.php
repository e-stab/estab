<?php

declare(strict_types=1);

/**
 * Route the Schicht administration from the authoritative active-incident
 * permission mode. The selected policy snapshot is retained so every
 * transactional mutation rejects an incident or mode change that happened
 * after routing.
 */

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';

estab_admin_require_http_auth($_SERVER);

$shiftAdminMode = ESTAB_PERMISSION_MODE_STRICT;
$shiftAdminConnection = null;
try {
    $shiftAdminConnection = estab_auth_connect($conf_4f_db);
    $shiftAdminStatus = estab_incident_status($shiftAdminConnection);
    if (($shiftAdminStatus['active_einsatz_id'] ?? null) !== null) {
        estab_permission_context_set_from_incident($shiftAdminStatus);
        $shiftAdminMode = estab_permission_mode(
            $shiftAdminStatus['estab_permission_mode'] ?? null
        );
    }
} catch (Throwable $exception) {
    error_log(
        'eStab Schichtadministration: Modus konnte nicht ermittelt werden: '
        . $exception->getMessage()
    );
} finally {
    if ($shiftAdminConnection instanceof mysqli) {
        estab_auth_close($shiftAdminConnection);
    }
}

require_once __DIR__ . '/../app/tabelle.php';

/**
 * Eine Tafel der Führungsstellenverwaltung aus dem Tabellenbauteil.
 *
 * Die vier Listen dieser Seite -- Konten, Konten mit Schichten, geplante
 * und laufende Besetzung -- schrieben ihr Tabellenmarkup selbst. Das ist
 * die Uneinheitlichkeit, die im Betrieb auffällt: Wer sich in einer
 * Liste zurechtgefunden hat, findet sich in der nächsten nicht wieder.
 *
 * Sie tragen `baender => false`, also die zweite Betriebsart des
 * Bauteils: einen festen Stand ohne Suchfeld, Filter und Blätterer. Eine
 * Schicht hat fünf Besetzungen; eine Volltextsuche darüber wäre Zierrat,
 * und ein Blätterer für fünf Zeilen ist eine Bedienung, die nichts
 * bedient. Was das Bauteil hier bringt, ist das Gemeinsame: dieselbe
 * Kopfzeile, dieselben Abstände, dieselbe Karte auf schmalem Schirm,
 * derselbe Satz bei leerer Liste -- und die Zusicherung, dass eine Zeile
 * genau so viele Zellen hat wie die Tabelle Spalten.
 *
 * Der Inhalt jeder Zelle bleibt, wie er war: Er wird zwischengespeichert
 * und danach zerlegt. Aus dem Quelltext einer Vorlage ist nicht ablesbar,
 * welches `<td>` zu welcher Spalte gehört -- aus dem Ergebnis schon.
 *
 * @param list<string> $koepfe
 * @param list<string> $zeilenMarkup Je Eintrag das rohe Markup der Zellen.
 */
function fuehrungsstelle_tafel(
    string $id,
    string $beschriftung,
    array $koepfe,
    array $zeilenMarkup,
    string $leer
): string {
    $breite = (int) floor(100 / max(1, count($koepfe)));
    $spalten = [];
    foreach ($koepfe as $nummer => $kopf) {
        $spalten[] = [
            'schluessel' => 'z' . $nummer,
            'kopf' => $kopf,
            'breite' => $breite,
            'sortierbar' => false,
            'suchbar' => false,
            'art' => 'text',
            /*
             * Die erste Spalte benennt ihre Zeile: das Benutzerkonto, die
             * Funktion. Ein Vorleseprogramm sagt damit "Benutzerkonto Meier,
             * Kontostatus nicht gesperrt" statt nur "Kontostatus nicht
             * gesperrt". Die Schichtverwaltung hatte das von Hand als
             * <th scope="row">; das Bauteil setzt es jetzt.
             */
            'zeilenkopf' => $nummer === 0,
            'zelle' => static fn (array $z): string => (string) $z['z' . $nummer],
        ];
    }
    $zeilen = [];
    foreach ($zeilenMarkup as $nummer => $markup) {
        $zellen = estab_tabelle_zeile_zerlegen($markup, count($koepfe));
        $zeile = ['id' => (string) $nummer];
        foreach ($zellen as $stelle => $inhalt) {
            $zeile['z' . $stelle] = $inhalt;
        }
        $zeilen[] = $zeile;
    }
    return estab_tabelle_markup([
        'id' => $id,
        'beschriftung' => $beschriftung,
        'baender' => false,
        'mindestbreite' => '38rem',
        'spalten' => $spalten,
        'zeilen' => $zeilen,
        'leer' => $leer,
    ]);
}

if ($shiftAdminMode === ESTAB_PERMISSION_MODE_LOOSE) {

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
        if (in_array(
            $action,
            [
                'create_duty_shift',
                'assign_duty_function',
                'relieve_duty_function',
                'activate_duty_shift',
                'handover_duty_shift',
                'cancel_duty_handover',
                'close_duty_shift',
            ],
            true
        )) {
            throw new EstabShiftAccessConflictException(
                'Der Einsatz wird inzwischen im lockeren '
                . 'Berechtigungsmodus geführt. Bitte laden Sie die Seite '
                . 'neu und verwenden Sie die optionale Zugangsschichtplanung.'
            );
        }
        if (!in_array(
            $action,
            [
                'create_access_shift',
                'add_access_member',
                'remove_access_member',
                'set_access_enabled',
            ],
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

        if ($action === 'create_access_shift') {
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
        if ($action === 'add_access_member') {
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
        if ($action === 'remove_access_member') {
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
        | EstabIncidentConflictException
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
  <title>eStab Optionale Zugangsschichten</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
<main class="estab-tool-main estab-tool-main-wide" data-estab-shift-admin>
  <header class="estab-tool-hero">
    <p class="estab-tool-eyebrow">Administration · Berechtigungsmodus Locker</p>
    <h1>Optionale Zugangsschichten</h1>
    <p>Fassen Sie Konten bei Bedarf zu Schichten zusammen und schalten Sie
      deren Anmeldung gemeinsam frei oder aus. Ohne Zuordnung behält ein
      Konto seinen individuellen Zugang.</p>
  </header>

  <aside class="estab-tool-notice" aria-label="Geltungsbereich">
    <strong>Schichten steuern ausschließlich den Zugang.</strong>
    <p>Fachrechte stammen aus der festen Primärfunktion des Kontos und – nur
      im lockeren Modus – aus ausdrücklich in der Benutzerverwaltung
      vergebenen persönlichen Zusatzfunktionen.
      Für operative Eingaben ist ein aktiver Einsatz zwingend; eine aktive
      Schicht ist dafür niemals erforderlich. Eine individuelle Kontosperre
      hat unabhängig von jeder Schicht Vorrang.</p>
    <a class="estab-button" href="incidents.php">
      Berechtigungsmodus des Einsatzes verwalten
    </a>
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
        <input type="hidden" name="admin_action"
          value="create_access_shift">
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
                    value="set_access_enabled">
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
                <input type="hidden" name="admin_action"
                  value="set_access_enabled">
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

          <?php
          $kontoMarkup = [];
          foreach ($members as $member) {
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
              ob_start();
              ?>
                    <td
                      class="estab-tool-identity">
                      <strong><?= estab_admin_html(
                          $member['benutzer'] !== ''
                              ? $member['benutzer']
                              : $member['benutzer_kuerzel']
                      ) ?></strong>
                      <span><?= estab_admin_html(
                          $member['benutzer_kuerzel']
                      ) ?></span>
                    </td>
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
                            value="remove_access_member">
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
              <?php
              $kontoMarkup[] = (string) ob_get_clean();
          }
          echo fuehrungsstelle_tafel(
              'zugangsschicht-' . $shiftId,
              'Konten der Zugangsschicht ' . $shiftLabel,
              ['Benutzerkonto', 'Feste Funktion', 'Kontostatus', 'Aktion'],
              $kontoMarkup,
              'Dieser Schicht ist noch kein Konto zugeordnet.'
          );
          ?>

          <?php if ($availableUsers !== []): ?>
            <form class="estab-tool-form" method="post"
              action="fuehrungsstelle.php">
              <?= estab_csrf_field() ?>
              <input type="hidden" name="admin_action"
                value="add_access_member">
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
      <?php
      $zugangMarkup = [];
      foreach ($users as $user) {
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
          ob_start();
          ?>
                <td
                  class="estab-tool-identity">
                  <strong><?= estab_admin_html($user['benutzer']) ?></strong>
                  <span><?= estab_admin_html($code) ?></span>
                </td>
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
          <?php
          $zugangMarkup[] = (string) ob_get_clean();
      }
      echo fuehrungsstelle_tafel(
          'zugang-nach-konto',
          'Zugangsübersicht nach Konto mit Sperre, Schichten und '
              . 'wirksamem Zugang',
          ['Benutzerkonto', 'Feste Funktion', 'Schichten', 'Wirksamer Zugang'],
          $zugangMarkup,
          'Keine Benutzerkonten vorhanden.'
      );
      ?>
    </section>
  <?php endif; ?>

  <footer class="estab-tool-footer">
    <a href="admin.php">Zurück zur Administration</a>
    <span>Zuordnungen und Statusänderungen werden einsatzbezogen protokolliert.</span>
  </footer>
</main>
</body>
</html>
<?php
    return;
}

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/dv_operations.php';
require_once __DIR__ . '/../app/dv_shift_start.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);
$handoverIdentity = estab_auth_session_identity($_SESSION);

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
        if (in_array(
            $action,
            [
                'create_access_shift',
                'add_access_member',
                'remove_access_member',
                'set_access_enabled',
            ],
            true
        )) {
            throw new EstabDvConflictException(
                'Der Einsatz wird inzwischen im strengen '
                . 'Berechtigungsmodus geführt. Bitte laden Sie die Seite '
                . 'neu und verwenden Sie die formale Dienstschichtplanung.'
            );
        }
        $connection = estab_auth_connect($conf_4f_db);
        $incident = estab_incident_require_active($connection);
        $incidentId = (int) $incident['active_einsatz_id'];
        $actor = estab_dv_actor($_SERVER['REMOTE_USER'] ?? 'admin');
        if ($action === 'create_duty_shift') {
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
        if ($action === 'assign_duty_function') {
            $policyLock = estab_assignment_acquire_policy_lock(
                $connection,
                (string) $conf_4f_db['datenbank'],
                $conf_4f_tbl['empfmtx']
            );
            try {
                $assignedHat = estab_dv_assign_hat(
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
            dv_admin_redirect(
                ($assignedHat['active_shift_extension'] ?? false) === true
                    ? 'hat_extension_assigned'
                    : 'hat_assigned'
            );
        }
        if ($action === 'relieve_duty_function') {
            estab_dv_relieve_hat(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstbesetzung_id'] ?? null,
                    'Dienstbesetzung'
                ),
                $_POST['nachfolger_kuerzel'] ?? null,
                $_POST['abloesungsgrund'] ?? null,
                $actor,
                $conf_4f_tbl['protokoll']
            );
            dv_admin_redirect('hat_relieved');
        }
        if ($action === 'activate_duty_shift') {
            estab_dv_activate_initial_shift(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstschicht_id'] ?? null,
                    'Dienstschicht'
                ),
                $actor,
                $conf_4f_tbl['protokoll']
            );
            dv_admin_redirect('shift_activated');
        }
        if ($action === 'handover_duty_shift') {
            if (
                !is_array($handoverIdentity)
                || !isset($handoverIdentity['duty_assignment_id'])
            ) {
                throw new EstabDvPermissionException(
                    'Melden Sie sich zusätzlich persönlich an und wählen Sie '
                    . 'eine angenommene Funktion der aktiven Schicht, bevor '
                    . 'Sie die Übergabe anfordern.'
                );
            }
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
                (int) $handoverIdentity['duty_assignment_id'],
                $handoverIdentity,
                $actor,
                $conf_4f_tbl['protokoll']
            );
            dv_admin_redirect('shift_handover_initiated');
        }
        if ($action === 'cancel_duty_handover') {
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
        if ($action === 'close_duty_shift') {
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
        | EstabIncidentConflictException
        | EstabIncidentConfigurationException
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
    'hat_relieved' =>
        'Die Funktion wurde einzeln abgelöst. Abgebende und übernehmende '
        . 'Person stehen im ETB; wirksam wird die Nachbesetzung mit der '
        . 'persönlichen Annahme durch die übernehmende Person.',
    'hat_extension_assigned' =>
        'Die Ergänzung wurde zugewiesen. Sie wird erst mit der persönlichen '
        . 'Annahme wirksam und dann automatisch im ETB nachgewiesen.',
    'shift_activated' => 'Die erste Dienstschicht ist jetzt aktiv.',
    'shift_handover_initiated' =>
        'Die übergebende Person hat die Übergabe angefordert. Eine persönlich '
        . 'angemeldete Person der Nachfolgeschicht muss sie jetzt bestätigen.',
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

/*
 * Die Ablaufführung der Inbetriebnahme.
 *
 * Sie wird immer berechnet, auch wenn schon eine Schicht läuft: Dann steht
 * dieselbe Liste als Nachweis da, dass nichts übersprungen wurde, und der
 * letzte Schritt erinnert weiter an die Arbeitsfunktion, die nur die Person
 * selbst wählen kann.
 */
$startState = estab_dv_shift_start_state($users, $shifts);
$startSteps = estab_dv_shift_start_steps($startState);
$startCurrent = estab_dv_shift_start_current($startSteps);
/*
 * Ein Auswahlfeld ohne Auswahl ist keine Bedienung.
 *
 * Ohne ungesperrtes Konto rendert die Zuweisung ein leeres Pflichtfeld und
 * einen Knopf, der nur scheitern kann. Statt dessen steht dann der Grund da
 * und der Weg dorthin.
 */
$hasAssignableAccount = $startState['konten_frei'] > 0;

/**
 * Die Funktionsauswahl in Pflicht- und weitere Funktionen teilen.
 *
 * Vierzehn Einträge in alphabetischer Ordnung verraten nicht, dass genau
 * fünf davon über die Aktivierung entscheiden. Die Gruppen sagen es, ohne
 * eine Funktion zu verstecken: Eine Führungsstelle darf jede Funktion
 * besetzen, sie muss nur fünf davon besetzen.
 *
 * @param array<string,string> $roles
 * @return array{pflicht:array<string,string>,weitere:array<string,string>}
 */
function fuehrungsstelle_funktionsgruppen(array $roles): array
{
    $pflicht = [];
    $weitere = [];
    foreach ($roles as $function => $role) {
        if (in_array((string) $function, ESTAB_DV_REQUIRED_HATS, true)) {
            $pflicht[$function] = $role;
        } else {
            $weitere[$function] = $role;
        }
    }
    return ['pflicht' => $pflicht, 'weitere' => $weitere];
}

/**
 * Die Auswahlliste einer Funktionszuweisung ausgeben.
 *
 * `$vorwahl` ist die nächste fehlende Pflichtfunktion. Sie vorzuwählen nimmt
 * niemandem eine Entscheidung ab -- die Liste bleibt vollständig --, aber sie
 * erspart im Regelfall das Suchen und macht die Reihenfolge sichtbar.
 *
 * @param array<string,string> $roles
 */
function fuehrungsstelle_funktionsauswahl(
    array $roles,
    ?string $vorwahl = null
): string {
    $gruppen = fuehrungsstelle_funktionsgruppen($roles);
    $markup = '';
    $optionen = static function (array $auswahl) use ($vorwahl): string {
        $inhalt = '';
        foreach ($auswahl as $function => $role) {
            $inhalt .= '<option value="'
                . estab_admin_html($function) . '"'
                . ((string) $function === (string) $vorwahl
                    ? ' selected' : '') . '>'
                . estab_admin_html(
                    estab_function_identity_display_name(
                        (string) $function,
                        (string) $role
                    )
                )
                . '</option>';
        }
        return $inhalt;
    };
    if ($gruppen['pflicht'] !== []) {
        $markup .= '<optgroup label="Pflichtfunktionen der Schicht">'
            . $optionen($gruppen['pflicht']) . '</optgroup>';
    }
    if ($gruppen['weitere'] !== []) {
        $markup .= '<optgroup label="Weitere Funktionen">'
            . $optionen($gruppen['weitere']) . '</optgroup>';
    }
    return $markup;
}

/**
 * Die Kontoauswahl einer Zuweisung ausgeben.
 *
 * Beide Zuweisungsformulare -- die geplante Schicht und die Ergänzung der
 * laufenden -- brauchen dieselbe Liste. Sie stand zweimal im Quelltext, und
 * genau eine der beiden Stellen hätte man beim nächsten Umbau vergessen.
 *
 * @param list<array<string,mixed>> $users
 */
function fuehrungsstelle_kontoauswahl(array $users): string
{
    $markup = '';
    foreach ($users as $user) {
        $blocked = (int) ($user['estab_gesperrt'] ?? 0) === 1;
        $presence = estab_auth_presence_state($user);
        $zustand = $blocked
            ? 'gesperrt'
            : match ($presence) {
                'online' => 'aktiv',
                'inactive' => 'inaktiv (15+ Min.)',
                default => 'nicht angemeldet',
            };
        $markup .= '<option value="'
            . estab_admin_html($user['kuerzel']) . '"'
            . ($blocked ? ' disabled' : '') . '>'
            . estab_admin_html(
                $user['benutzer'] . ' (' . $user['kuerzel'] . ') · '
                . $zustand
            )
            . '</option>';
    }
    return $markup;
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
    <p class="estab-tool-eyebrow">Administration · Berechtigungsmodus Streng</p>
    <h1>Führungsstelle und Dienstschichten</h1>
    <p>Hier werden Schichten vorbereitet, Funktionen echten Konten
      zugewiesen und Übergaben revisionssicher dokumentiert. Eine Person darf
      mehrere getrennte Funktionen übernehmen; jede Funktion muss sie selbst
      in der eStab-Oberfläche annehmen.</p>
  </header>

  <aside class="estab-tool-notice" aria-label="Berechtigungsmodus">
    <strong>Formaler Dienstbetrieb ist aktiv.</strong>
    <p>Operative Rechte entstehen ausschließlich durch eine persönlich
      angenommene Funktion der aktiven Dienstschicht. Hier planen Sie
      Dienstschichten, besetzen Funktionen und dokumentieren Übergaben.</p>
    <a class="estab-button" href="incidents.php">
      Berechtigungsmodus des Einsatzes verwalten
    </a>
  </aside>

  <section class="estab-tool-status" aria-label="Unterstützter Betriebsmodus">
    <strong>Unterstützter Betriebsmodus: Führungsstelle mit eingerichteter
      Fernmeldebetriebsstelle.</strong>
    <span>Deshalb sind LdF und Fernmelder Pflichtbesetzungen und eStab führt je
      Einsatz genau ein TBB. Führungsstellen ohne eigene
      Fernmeldebetriebsstelle (reiner ETB-Betrieb) gehören derzeit nicht zum
      unterstützten Produktumfang.</span>
  </section>

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
  <?php elseif (($status['fuehrungsstellenname'] ?? null) === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Name der Führungsstelle fehlt.</strong>
      <span>Ergänzen Sie zuerst den aktiven Einsatz. Dienstschichten und
        operative Vorgänge bleiben bis dahin gesperrt.</span>
      <a class="estab-button" href="incidents.php">
        Führungsstellenname festlegen
      </a>
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
        <span>Führungsstelle</span>
        <strong><?= estab_admin_html(
            $status['fuehrungsstellenname']
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

    <section class="estab-tool-panel" aria-label="Ablauf der Inbetriebnahme">
      <header class="estab-tool-panel-heading">
        <h2>Ablauf: erste Dienstschicht in Betrieb nehmen</h2>
        <p>Im strengen Berechtigungsmodus entstehen operative Rechte
          ausschließlich aus einer persönlich angenommenen Funktion. Diese
          sechs Schritte führen dorthin; zwei davon kann die Administration
          nicht selbst ausführen.</p>
      </header>
      <?php if ($startCurrent !== null): ?>
        <?php
        /*
         * Der Kasten zitiert denselben Schritt, den die Liste hervorhebt.
         *
         * Er trug vorher bei laufender Schicht einen festen Satz -- „offen
         * bleibt nur die Arbeitsfunktion". Nach einer Einzelablösung sprang
         * die Liste darunter auf Schritt 4 zurück, und beide Aussagen standen
         * nebeneinander. Wer eine Zusammenfassung schreibt, die ihre eigene
         * Liste nicht liest, schreibt irgendwann etwas anderes als sie.
         */
        ?>
        <p class="estab-tool-feedback <?= $activeShift !== null
            ? 'estab-tool-feedback-success'
            : 'estab-tool-feedback-warning' ?>" role="status">
          <?php if ($activeShift !== null): ?>
            <strong>Der formale Dienstbetrieb läuft.</strong>
          <?php endif; ?>
          <strong>Als Nächstes: Schritt
            <?= (int) $startCurrent['nummer'] ?> ·
            <?= estab_admin_html($startCurrent['titel']) ?></strong>
          <?= estab_admin_html($startCurrent['wer']) ?>.
        </p>
      <?php endif; ?>
      <?= estab_dv_shift_start_markup($startSteps) ?>
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
          <input type="hidden" name="admin_action"
            value="cancel_duty_handover">
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
        <h2><?= $activeShift === null
            ? 'Schritt 2: Dienstschicht planen'
            : 'Nachfolgeschicht planen' ?></h2>
        <?php if ($activeShift === null): ?>
          <p>Die Pflichtfunktionen
            <?= estab_admin_html(estab_dv_shift_start_function_text(
                ESTAB_DV_REQUIRED_HATS
            )) ?>
            müssen mindestens einmal zugewiesen und persönlich angenommen
            sein, bevor eine Schicht aktiv werden kann. Mehrere
            Fernmelder-Besetzungen sind ausdrücklich möglich, und eine Person
            darf mehrere Funktionen tragen.</p>
        <?php else: ?>
          <p>Der Dienstbetrieb läuft. Eine hier angelegte Schicht wird nicht
            aktiviert, sondern über eine persönlich bestätigte Übergabe von
            der laufenden Schicht übernommen. Wählen Sie dafür die laufende
            Schicht als Vorgängerschicht.</p>
        <?php endif; ?>
      </header>
      <form class="estab-tool-form" method="post" action="fuehrungsstelle.php">
        <?= estab_csrf_field() ?>
        <input type="hidden" name="admin_action"
          value="create_duty_shift">
        <label>Schichtbezeichnung
          <input name="bezeichnung" maxlength="100"
            placeholder="z. B. Tagschicht 30.07." required>
        </label>
        <label>Vorgängerschicht
          <?php
          /*
           * Läuft bereits eine Schicht, ist „keine Vorgängerschicht" eine
           * Sackgasse: Als erste Schicht kann sie nicht mehr aktiviert
           * werden, und ohne Vorgänger gibt es keine Übergabe, die sie
           * übernimmt. Sie stünde geplant da und wäre zu nichts zu
           * gebrauchen. Deshalb wird die laufende Schicht vorgewählt und
           * die leere Wahl gar nicht erst angeboten.
           */
          ?>
          <select name="vorgaenger_id"
            <?= $activeShift === null ? '' : 'required' ?>>
            <?php if ($activeShift === null): ?>
              <option value="">Erste Schicht / keine</option>
            <?php endif; ?>
            <?php foreach ($shifts as $shift): ?>
              <?php if (in_array($shift['status'], ['AKTIV', 'GEPLANT'], true)): ?>
                <option value="<?= (int) $shift['dienstschicht_id'] ?>"
                  <?= $activeShift !== null
                      && (int) $shift['dienstschicht_id']
                          === (int) $activeShift['dienstschicht_id']
                      ? 'selected' : '' ?>>
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
        <?php
        $besetzungMarkup = [];
        foreach ($shift['besetzungen'] as $hat) {
            ob_start();
            ?>
              <td><?= estab_admin_html(
                  estab_function_identity_display_name(
                      (string) $hat['funktion'],
                      (string) $hat['rolle']
                  )
              ) ?></td>
              <td><?= estab_admin_html(
                  $hat['benutzer'] . ' (' . $hat['benutzer_kuerzel'] . ')'
              ) ?></td>
              <td><?= estab_admin_html($hat['status']) ?></td>
            <?php
            $besetzungMarkup[] = (string) ob_get_clean();
        }
        echo fuehrungsstelle_tafel(
            'geplante-schicht-' . (int) $shift['dienstschicht_id'],
            'Geplante Besetzung der Schicht #' . (int) $shift['nummer'],
            ['Funktion', 'Person', 'Status'],
            $besetzungMarkup,
            'Für diese Schicht ist noch niemand eingeplant.'
        );
        ?>
        <?php
        /*
         * Zuweisung und Annahme sind zwei verschiedene Rückstände, und sie
         * haben verschiedene Adressaten. Was noch niemandem zugewiesen ist,
         * macht die Administration; was zugewiesen und noch nicht angenommen
         * ist, kann nur die benannte Person selbst. Die Seite hat das früher
         * in einen Satz zusammengezogen -- und genau daran ist die
         * Inbetriebnahme gescheitert.
         */
        $shiftAssigned = [];
        $shiftAccepted = [];
        $shiftWaiting = [];
        foreach ($shift['besetzungen'] as $hat) {
            if ((int) ($hat['benutzer_gesperrt'] ?? 1) === 1) {
                continue;
            }
            $shiftAssigned[] = (string) $hat['funktion'];
            if (($hat['status'] ?? '') === 'ANGENOMMEN') {
                $shiftAccepted[] = (string) $hat['funktion'];
            } elseif (($hat['status'] ?? '') === 'ZUGEWIESEN') {
                $shiftWaiting[] = $hat;
            }
        }
        $missingAssignment = estab_dv_shift_start_missing($shiftAssigned);
        $missing = estab_dv_shift_start_missing($shiftAccepted);
        /*
         * Nur eine Schicht wird je aktiviert: die erste, ohne Vorgänger.
         * estab_dv_activate_initial_shift() verlangt genau das. Jede andere
         * geplante Schicht wird übernommen, nicht aktiviert -- sie darf
         * deshalb weder die Schrittnummern der Inbetriebnahme tragen noch
         * eine Aktivierung versprechen, die es für sie nicht gibt.
         */
        $istInbetriebnahme = $activeShift === null
            && !$hasActivationHistory
            && ($shift['vorgaenger_id'] ?? null) === null;
        ?>
        <?php if (!$hasAssignableAccount): ?>
          <section class="estab-tool-status estab-tool-status-danger"
            role="alert">
            <strong>Noch kein Benutzerkonto vorhanden.</strong>
            <span>Eine Dienstfunktion wird an ein ungesperrtes Benutzerkonto
              vergeben, nicht an einen Namen. Legen Sie zuerst die Konten der
              Funktionsträger an; danach steht die Zuweisung hier zur
              Verfügung.</span>
            <a class="estab-button estab-button-primary" href="users.php">
              Benutzer verwalten
            </a>
          </section>
        <?php else: ?>
          <form class="estab-tool-form" method="post"
            action="fuehrungsstelle.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="admin_action"
              value="assign_duty_function">
            <input type="hidden" name="dienstschicht_id"
              value="<?= (int) $shift['dienstschicht_id'] ?>">
            <h3><?= $istInbetriebnahme
                ? 'Schritt 3: Funktion zuweisen'
                : 'Funktion zuweisen' ?></h3>
            <p>Ein ungesperrtes Konto kann bereits für die kommende Schicht
              eingeplant werden. Die Person muss sich erst zur persönlichen
              Annahme anmelden. Die danach gespeicherte Annahme zählt auch nach
              der Abmeldung; ein gesperrtes Konto zählt dagegen nie.</p>
            <?php if ($missingAssignment !== []): ?>
              <p class="estab-tool-feedback estab-tool-feedback-warning">
                Noch nicht zugewiesen:
                <strong><?= estab_admin_html(
                    estab_dv_shift_start_function_text($missingAssignment)
                ) ?></strong>. Die nächste fehlende Pflichtfunktion ist
                vorgewählt.
              </p>
            <?php endif; ?>
            <label>Benutzerkonto
              <select name="benutzer_kuerzel" required>
                <?= fuehrungsstelle_kontoauswahl($users) ?>
              </select>
            </label>
            <label>Dienstfunktion
              <select name="funktion" required>
                <?= fuehrungsstelle_funktionsauswahl(
                    $functionRoles,
                    $missingAssignment[0] ?? null
                ) ?>
              </select>
            </label>
            <button class="estab-button" type="submit">Funktion zuweisen</button>
          </form>
        <?php endif; ?>
        <?php if ($shiftWaiting !== []): ?>
          <section class="estab-tool-status"
            aria-label="Wartende Annahmen">
            <strong><?= $istInbetriebnahme ? 'Schritt 4: ' : '' ?>Diese
              Zuweisungen warten auf die persönliche Annahme.</strong>
            <span>Die Administration kann die Annahme nicht ersatzweise
              erklären. Jede benannte Person meldet sich mit ihrem eigenen
              Konto an und nimmt ihre Funktion im
              <?= estab_admin_html(
                  ESTAB_DV_SHIFT_START_PERSONAL_LABEL
              ) ?> verbindlich an.</span>
            <ul class="estab-ablauf-namen">
              <?php foreach ($shiftWaiting as $hat): ?>
                <li><?= estab_admin_html(
                    estab_function_identity_display_name(
                        (string) $hat['funktion'],
                        (string) $hat['rolle']
                    )
                    . ' — ' . $hat['benutzer']
                    . ' (' . $hat['benutzer_kuerzel'] . ')'
                ) ?></li>
              <?php endforeach; ?>
            </ul>
            <?php
            /*
             * Hier stand ein Knopf „Annahmeseite ansehen".
             *
             * Er führte die einzige Person, die ihn sehen kann, gegen eine
             * Anmeldewand: Diese Seite schützt der HTTP-Basiszugang, und der
             * ist kein eStab-Funktionskonto. Die Annahmeseite verlangt aber
             * genau ein solches. Ein Bedienelement, das in der gegenwärtigen
             * Lage nur scheitern kann, wird nicht angeboten -- der Ort steht
             * oben im Satz, und weitergeben muss ihn ohnehin ein Mensch.
             */
            ?>
          </section>
        <?php endif; ?>
        <?php if ($missing === []): ?>
          <?php if ($activeShift === null && !$hasActivationHistory): ?>
            <form class="estab-tool-form" method="post"
              action="fuehrungsstelle.php">
              <?= estab_csrf_field() ?>
              <input type="hidden" name="admin_action"
                value="activate_duty_shift">
              <input type="hidden" name="dienstschicht_id"
                value="<?= (int) $shift['dienstschicht_id'] ?>">
              <h3>Schritt 5: Schicht aktivieren</h3>
              <p>Alle Pflichtfunktionen sind zugewiesen und persönlich
                angenommen. Die Aktivierung eröffnet ETB und TBB und gibt den
                formalen Dienstbetrieb frei.</p>
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
              <input type="hidden" name="admin_action"
                value="handover_duty_shift">
              <input type="hidden" name="von_dienstschicht_id"
                value="<?= (int) $activeShift['dienstschicht_id'] ?>">
              <input type="hidden" name="an_dienstschicht_id"
                value="<?= (int) $shift['dienstschicht_id'] ?>">
              <label>Übergabezusammenfassung
                <textarea name="zusammenfassung" maxlength="10000"
                  required></textarea>
              </label>
              <?php if (is_array($handoverIdentity)
                  && isset($handoverIdentity['duty_assignment_id'])): ?>
                <p class="estab-tool-feedback">Persönlich übergebend:
                  <strong><?= estab_admin_html(
                      $handoverIdentity['benutzer'] . ' ['
                      . $handoverIdentity['kuerzel'] . '] · '
                      . $handoverIdentity['funktion'] . ' ('
                      . $handoverIdentity['rolle'] . ')'
                  ) ?></strong>. Die übernehmende Person bestätigt danach
                  mit ihrer angenommenen Funktion.</p>
              <?php else: ?>
                <p class="estab-tool-feedback estab-tool-feedback-error">
                  Vor der Anforderung muss die übergebende Person sich
                  <a href="../4fach/fuehrungsstelle.php">persönlich anmelden
                  und ihre aktive Dienstfunktion wählen</a>.</p>
              <?php endif; ?>
              <button class="estab-button estab-button-primary" type="submit"
                <?= is_array($handoverIdentity)
                    && isset($handoverIdentity['duty_assignment_id'])
                    ? '' : 'disabled' ?>>
                Übergabe verbindlich anfordern
              </button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p class="estab-tool-feedback estab-tool-feedback-warning">
            <?php
            /*
             * Aktivierbar wird nur die erste Schicht. Eine Nachfolgeschicht
             * verspricht das nicht: Sie wird über eine persönlich bestätigte
             * Übergabe übernommen, und dieselbe Bedingung -- alle
             * Pflichtfunktionen angenommen -- gilt dort für die Übergabe.
             */
            ?>
            <?php if ($istInbetriebnahme): ?>
              <strong>Schritt 5 ist noch gesperrt.</strong>
              Die Schicht wird aktivierbar, sobald diese Pflichtfunktionen
              persönlich angenommen sind:
            <?php else: ?>
              <strong>Die Übergabe ist noch gesperrt.</strong>
              Diese Schicht wird nicht aktiviert, sondern von der laufenden
              übernommen. Angefordert werden kann die Übergabe, sobald diese
              Pflichtfunktionen persönlich angenommen sind:
            <?php endif; ?>
            <strong><?= estab_admin_html(
                estab_dv_shift_start_function_text($missing)
            ) ?></strong>.
            <?= estab_admin_html($missingAssignment === []
                ? 'Alle sind zugewiesen; es fehlt nur noch die Annahme durch '
                    . 'die oben benannten Personen.'
                : 'Davon ist noch keinem Konto zugewiesen: '
                    . estab_dv_shift_start_function_text($missingAssignment)
                    . '.') ?>
          </p>
        <?php endif; ?>
        <details class="estab-tool-details">
          <summary>Diese Planung verwerfen</summary>
          <p>Das Schließen beendet die geplante Schicht, ohne sie je zu
            aktivieren. Es ist <em>nicht</em> der Weg zur Inbetriebnahme —
            wer auf eine Annahme wartet, schließt hier nichts, sondern
            wartet. Die Schicht bleibt danach als geschlossene Planung
            nachvollziehbar; eine neue Schicht muss neu besetzt und neu
            angenommen werden.</p>
          <form method="post" action="fuehrungsstelle.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="admin_action"
              value="close_duty_shift">
            <input type="hidden" name="dienstschicht_id"
              value="<?= (int) $shift['dienstschicht_id'] ?>">
            <button class="estab-button estab-button-danger-outline"
              type="submit">
              Planung schließen
            </button>
          </form>
        </details>
      </section>
    <?php endforeach; ?>

    <?php if ($activeShift !== null): ?>
      <?php
      $occupiedActiveFunctions = [];
      $activeHasLogbookWriter = false;
      foreach ($activeShift['besetzungen'] as $activeHat) {
          if (in_array(
              $activeHat['status'] ?? null,
              ['ZUGEWIESEN', 'ANGENOMMEN'],
              true
          )) {
              $occupiedActiveFunctions[] = (string) $activeHat['funktion'];
          }
          if (
              ($activeHat['status'] ?? null) === 'ANGENOMMEN'
              && in_array(
                  (string) ($activeHat['funktion'] ?? ''),
                  ['ETB', 'S2'],
                  true
              )
          ) {
              $activeHasLogbookWriter = true;
          }
      }
      $occupiedActiveFunctions = array_values(array_unique(
          $occupiedActiveFunctions
      ));
      $activeExtensionRoles = array_filter(
          $functionRoles,
          static fn (string $role, string $function): bool => (
              $function === 'A/W'
              || !in_array($function, $occupiedActiveFunctions, true)
          ) && !($function === 'ETB' && $activeHasLogbookWriter),
          ARRAY_FILTER_USE_BOTH
      );
      ?>
      <section class="estab-tool-panel">
        <header class="estab-tool-panel-heading">
          <h2>Aktive Schicht #<?= (int) $activeShift['nummer'] ?></h2>
          <p><?= estab_admin_html($activeShift['bezeichnung']) ?> · Eine
            zusätzliche, bislang unbesetzte Funktion kann während des
            laufenden Betriebs ergänzt werden. Die bereits bestimmte
            ETB-Führung wechselt ausschließlich mit einer bestätigten
            Schichtübergabe.</p>
        </header>
        <?php
        $besetzungMarkup = [];
        foreach ($activeShift['besetzungen'] as $hat) {
            ob_start();
            ?>
                <td><?= estab_admin_html(
                    estab_function_identity_display_name(
                        (string) $hat['funktion'],
                        (string) $hat['rolle']
                    )
                ) ?></td>
                <td><?= estab_admin_html(
                    $hat['benutzer'] . ' (' . $hat['benutzer_kuerzel'] . ')'
                ) ?></td>
                <td><?= estab_admin_html($hat['status']) ?></td>
                <td>
                  <?php
                  /*
                   * Ablösen heißt: jemand anderes übernimmt. Gibt es
                   * niemanden, ist das Formular kein Angebot, sondern eine
                   * Falle -- ein Pflichtfeld ohne Auswahl.
                   */
                  $nachfolger = array_values(array_filter(
                      $users,
                      static fn (array $user): bool =>
                          (int) ($user['estab_gesperrt'] ?? 0) !== 1
                          && (string) $user['kuerzel']
                              !== (string) $hat['benutzer_kuerzel']
                  ));
                  ?>
                  <?php if (($hat['status'] ?? null) !== 'ANGENOMMEN'): ?>
                    <span>Nur eine angenommene Funktion kann abgelöst
                      werden.</span>
                  <?php elseif ((string) $hat['funktion'] === 'ETB'): ?>
                    <span>Die bestimmte ETB-Führung wechselt ausschließlich
                      über eine bestätigte Schichtübergabe.</span>
                  <?php elseif ($nachfolger === []): ?>
                    <span>Es gibt kein weiteres ungesperrtes Konto, das diese
                      Funktion übernehmen könnte. Legen Sie zuerst ein Konto
                      an oder entsperren Sie eines.</span>
                  <?php else: ?>
                    <form class="estab-tool-form" method="post"
                      action="fuehrungsstelle.php">
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="admin_action"
                        value="relieve_duty_function">
                      <input type="hidden" name="dienstbesetzung_id"
                        value="<?= (int) $hat['dienstbesetzung_id'] ?>">
                      <label>Übernehmende Person
                        <select name="nachfolger_kuerzel" required>
                          <?php foreach ($nachfolger as $user): ?>
                            <option
                              value="<?= estab_admin_html($user['kuerzel']) ?>">
                              <?= estab_admin_html(
                                  $user['benutzer'] . ' ('
                                  . $user['kuerzel'] . ')'
                              ) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>Grund der Ablösung
                        <input name="abloesungsgrund" maxlength="200"
                          placeholder="z. B. Ausfall, Ablösung, Abbruch"
                          required>
                      </label>
                      <button class="estab-button" type="submit">
                        Funktion einzeln ablösen
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
            <?php
            $besetzungMarkup[] = (string) ob_get_clean();
        }
        echo fuehrungsstelle_tafel(
            'aktive-schicht-' . (int) $activeShift['dienstschicht_id'],
            'Besetzung der aktiven Schicht #' . (int) $activeShift['nummer'],
            ['Funktion', 'Person', 'Status', 'Einzelablösung'],
            $besetzungMarkup,
            'Diese Schicht hat keine Besetzung.'
        );
        ?>
        <?php if (!$hasAssignableAccount): ?>
          <section class="estab-tool-status estab-tool-status-danger"
            role="alert">
            <strong>Kein ungesperrtes Benutzerkonto vorhanden.</strong>
            <span>Die laufende Schicht lässt sich erst ergänzen, wenn wieder
              ein ungesperrtes Konto zur Verfügung steht. Die bereits
              angenommenen Funktionen bleiben davon unberührt.</span>
            <a class="estab-button" href="users.php">Benutzer verwalten</a>
          </section>
        <?php else: ?>
          <form class="estab-tool-form" method="post"
            action="fuehrungsstelle.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="admin_action"
              value="assign_duty_function">
            <input type="hidden" name="dienstschicht_id"
              value="<?= (int) $activeShift['dienstschicht_id'] ?>">
            <h3>Laufende Schichtbesetzung erweitern</h3>
            <p>Die Zuweisung allein ändert den Betrieb noch nicht. Erst wenn
              die betroffene Person sie selbst annimmt, wird sie wirksam und
              automatisch im ETB dokumentiert. Ergänzungen für LdF und
              Fernmelder werden
              zusätzlich im TBB nachgewiesen. Bereits besetzte Funktionen
              können nicht ausgetauscht werden; dafür ist eine geordnete
              Schichtübergabe erforderlich. Weitere Fernmelder dürfen ergänzt
              werden. Eine ETB-Ergänzung, die S2 oder ETB als bestimmten
              Schreiber verdrängen würde, wird nicht angeboten.</p>
            <label>Benutzerkonto
              <select name="benutzer_kuerzel" required>
                <?= fuehrungsstelle_kontoauswahl($users) ?>
              </select>
            </label>
            <label>Neue oder zusätzliche Fernmelder-Funktion
              <select name="funktion" required>
                <?= fuehrungsstelle_funktionsauswahl($activeExtensionRoles) ?>
              </select>
            </label>
            <button class="estab-button" type="submit">
              Ergänzung verbindlich zuweisen
            </button>
          </form>
        <?php endif; ?>
        <?php if ($plannedShifts === []
            && !$hasOpenHandover
            && ($finalShiftPreflight['closable'] ?? false)): ?>
          <form method="post" action="fuehrungsstelle.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="admin_action"
              value="close_duty_shift">
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
          <?php if (is_array($finalShiftPreflight)): ?>
            <dt>ETB und TBB</dt>
            <dd><?= $finalShiftPreflight['logbuecher_eroeffnet']
                ? 'eröffnet'
                : 'noch nicht eröffnet' ?></dd>
          <?php endif; ?>
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
