<?php

declare(strict_types=1);

session_start();

if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Administrative authentication required.');
}

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/incident.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function incident_admin_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function incident_admin_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return 'offen';
    }
    try {
        return (new DateTimeImmutable($value))
            ->format('d.m.Y H:i');
    } catch (Throwable) {
        return 'ungültiger Zeitpunkt';
    }
}

function incident_admin_redirect(string $result): never
{
    if (preg_match('/\A[a-z_]{3,32}\z/D', $result) !== 1) {
        $result = 'updated';
    }
    header('Location: incidents.php?result=' . rawurlencode($result), true, 303);
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$actor = $_SERVER['REMOTE_USER'] ?? '';
$error = null;
$databaseError = null;
$flash = $_SESSION['estab_incident_flash'] ?? null;
unset($_SESSION['estab_incident_flash']);

if ($requestMethod === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $action = $_POST['admin_action'] ?? null;
        if (!is_string($action)) {
            throw new EstabIncidentInputException(
                'Unbekannte administrative Aktion.'
            );
        }
        $redirectResult = null;
        $connection = estab_auth_connect($conf_4f_db);
        try {
            if ($action === 'create') {
                $activate = ($_POST['activate_immediately'] ?? null) === '1';
                $revision = $activate
                    ? estab_incident_revision($_POST['status_revision'] ?? null)
                    : null;
                $created = estab_incident_create(
                    $connection,
                    $_POST,
                    estab_incident_actor($actor),
                    $activate,
                    $revision
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => $activate ? 'created_active' : 'created',
                    'kennung' => $created['kennung'],
                ];
                $redirectResult = $activate ? 'created_active' : 'created';
            } elseif ($action === 'update_command_post_name') {
                $updated = estab_incident_update_command_post_name(
                    $connection,
                    estab_incident_positive_id($_POST['einsatz_id'] ?? null),
                    $_POST['fuehrungsstellenname'] ?? null,
                    $_POST['expected_fuehrungsstellenname'] ?? null,
                    estab_incident_actor($actor)
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => 'command_post_updated',
                    'kennung' => (string) ($updated['kennung'] ?? ''),
                ];
                $redirectResult = 'command_post_updated';
            } elseif ($action === 'update_logbook_header') {
                $updated = estab_incident_update_logbook_header(
                    $connection,
                    estab_incident_positive_id($_POST['einsatz_id'] ?? null),
                    $_POST,
                    estab_incident_actor($actor)
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => 'logbook_header_updated',
                    'kennung' => (string) ($updated['kennung'] ?? ''),
                ];
                $redirectResult = 'logbook_header_updated';
            } elseif ($action === 'activate') {
                $activated = estab_incident_activate(
                    $connection,
                    estab_incident_positive_id($_POST['einsatz_id'] ?? null),
                    estab_incident_revision($_POST['status_revision'] ?? null),
                    estab_incident_actor($actor)
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => 'activated',
                    'kennung' => $activated['kennung'],
                ];
                $redirectResult = 'activated';
            } elseif ($action === 'deactivate') {
                estab_incident_deactivate(
                    $connection,
                    estab_incident_positive_id($_POST['einsatz_id'] ?? null),
                    estab_incident_revision($_POST['status_revision'] ?? null),
                    estab_incident_actor($actor)
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => 'deactivated',
                    'kennung' => '',
                ];
                $redirectResult = 'deactivated';
            } elseif ($action === 'close') {
                if (($_POST['confirm_close'] ?? null) !== '1') {
                    throw new EstabIncidentInputException(
                        'Bestätigen Sie den unwiderruflichen Einsatzabschluss.'
                    );
                }
                $closed = estab_incident_close(
                    $connection,
                    estab_incident_positive_id($_POST['einsatz_id'] ?? null),
                    estab_incident_revision($_POST['status_revision'] ?? null),
                    estab_incident_actor($actor),
                    $_POST,
                    (string) $conf_4f['ablage_dir']
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => 'closed',
                    'kennung' => (string) ($closed['kennung'] ?? ''),
                ];
                $redirectResult = 'closed';
            } elseif ($action === 'set_legal_hold') {
                $held = estab_incident_set_legal_hold(
                    $connection,
                    estab_incident_positive_id($_POST['einsatz_id'] ?? null),
                    true,
                    $_POST['legal_hold_reason'] ?? null,
                    estab_incident_actor($actor)
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => 'legal_hold_set',
                    'kennung' => (string) ($held['kennung'] ?? ''),
                ];
                $redirectResult = 'legal_hold_set';
            } elseif ($action === 'release_legal_hold') {
                $released = estab_incident_set_legal_hold(
                    $connection,
                    estab_incident_positive_id($_POST['einsatz_id'] ?? null),
                    false,
                    null,
                    estab_incident_actor($actor)
                );
                $_SESSION['estab_incident_flash'] = [
                    'type' => 'legal_hold_released',
                    'kennung' => (string) ($released['kennung'] ?? ''),
                ];
                $redirectResult = 'legal_hold_released';
            } else {
                throw new EstabIncidentInputException(
                    'Unbekannte administrative Aktion.'
                );
            }
        } finally {
            estab_auth_close($connection);
        }
        incident_admin_redirect((string) $redirectResult);
    } catch (EstabCsrfException) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    } catch (EstabIncidentInputException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (EstabIncidentNotFoundException $exception) {
        http_response_code(404);
        $error = $exception->getMessage();
    } catch (EstabIncidentCloseBlockedException $exception) {
        http_response_code(409);
        $error = $exception->getMessage()
            . ' Offen: ' . (int) ($exception->preflight['open_messages'] ?? 0)
            . ' Nachrichten, '
            . (int) ($exception->preflight['locked_messages'] ?? 0)
            . ' Sperren und '
            . (int) ($exception->preflight['incomplete_attachments'] ?? 0)
            . ' unvollständige Anhänge; Anhang-Integritätsfehler: '
            . (int) (
                $exception->preflight['attachment_integrity_errors'] ?? 0
            )
            . '; Nachweisfehler: '
            . (int) ($exception->preflight['evidence_errors'] ?? 0)
            . '. Fachlich offen: '
            . (int) ($exception->preflight['offene_melderauftraege'] ?? 0)
            . ' Melderaufträge und '
            . (int) (
                $exception->preflight['offene_fernmeldeplanentwuerfe'] ?? 0
            )
            . ' Fernmeldeplanentwürfe. Historische formale Schichtdaten '
            . 'werden nur noch informativ ausgewiesen und blockieren den '
            . 'Einsatzabschluss nicht.';
    } catch (EstabIncidentConflictException $exception) {
        http_response_code(409);
        $error = $exception->getMessage()
            . ' Bitte laden Sie den aktuellen Stand neu.';
    } catch (Throwable $exception) {
        error_log('eStab incident administration failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Die Einsatzverwaltung konnte die Aktion nicht vollständig ausführen.';
    }
} elseif ($requestMethod !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $error = 'Diese Seite unterstützt nur GET und POST.';
}

$status = null;
$incidents = [];
$activePreflight = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    try {
        $status = estab_incident_status($connection);
        $incidents = estab_incident_list($connection);
        if ($status['active_einsatz_id'] !== null) {
            $activePreflight = estab_incident_close_preflight(
                $connection,
                (int) $status['active_einsatz_id'],
                (string) $conf_4f['ablage_dir']
            );
        }
    } finally {
        estab_auth_close($connection);
    }
} catch (Throwable $exception) {
    error_log('eStab incident overview failed: ' . $exception->getMessage());
    if ($error === null) {
        http_response_code(503);
    }
    $databaseError =
        'Der Einsatzstatus ist derzeit nicht verfügbar. Es werden keine '
        . 'Einsatzaktionen angeboten.';
}

$flashMessage = null;
if (
    is_array($flash)
    && is_string($flash['type'] ?? null)
    && is_string($flash['kennung'] ?? null)
) {
    $flashMessage = match ($flash['type']) {
        'created' => 'Einsatz ' . $flash['kennung'] . ' wurde angelegt.',
        'created_active' => 'Einsatz ' . $flash['kennung']
            . ' wurde angelegt und aktiviert.',
        'activated' => 'Einsatz ' . $flash['kennung'] . ' ist jetzt aktiv.',
        'command_post_updated' => 'Der Führungsstellenname für Einsatz '
            . $flash['kennung'] . ' wurde gespeichert.',
        'logbook_header_updated' => 'Die Pflichtangaben für ETB und TBB des '
            . 'Einsatzes ' . $flash['kennung'] . ' wurden gespeichert.',
        'deactivated' => 'Der Einsatz wurde deaktiviert. Eingaben sind jetzt gesperrt.',
        'closed' => 'Einsatz ' . $flash['kennung']
            . ' wurde formal und unwiderruflich abgeschlossen.',
        'legal_hold_set' => 'Für Einsatz ' . $flash['kennung']
            . ' wurde eine Aufbewahrungssperre gesetzt.',
        'legal_hold_released' => 'Die zusätzliche Aufbewahrungssperre für Einsatz '
            . $flash['kennung'] . ' wurde aufgehoben. Die Mindestfrist bleibt bestehen.',
        default => null,
    };
}

$old = $requestMethod === 'POST' ? $_POST : [];
$defaultStart = date('Y-m-d\TH:i');
$currentRevision = is_array($status) ? (int) $status['revision'] : 0;
$activeId = is_array($status) ? $status['active_einsatz_id'] : null;
$activeMissingHeader = is_array($status) && $activeId !== null
    ? estab_logbook_lifecycle_missing_header($status)
    : [];

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Einsatzverwaltung</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main class="estab-tool-main" data-estab-incident-admin>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Administration · Einsatzführung</p>
      <h1>Einsätze verwalten</h1>
      <p>Alle operativen Eingaben gehören zum genau einmal ausgewählten,
        aktiven Einsatz. Ein Wechsel wirkt systemweit und wird deshalb
        transaktional sowie revisionsgesichert durchgeführt.</p>
    </header>

    <?php if ($databaseError !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= incident_admin_html($databaseError) ?>
      </p>
    <?php elseif ($activeId === null): ?>
      <section
        class="estab-tool-status estab-tool-status-danger"
        role="alert"
        data-estab-no-active-incident>
        <div>
          <strong>Kein Einsatz aktiv – alle operativen Eingaben sind gesperrt.</strong>
          <span>Legen Sie unten einen Einsatz an und aktivieren Sie ihn oder
            wählen Sie einen vorhandenen Einsatz aus.</span>
        </div>
      </section>
    <?php else: ?>
      <section
        class="estab-tool-status estab-tool-status-active"
        data-estab-active-incident>
        <div>
          <span>Aktiver Einsatz</span>
          <strong><?= incident_admin_html($status['kennung']) ?>
            · <?= incident_admin_html($status['name']) ?></strong>
          <?php if (($status['fuehrungsstellenname'] ?? null) === null): ?>
            <span><strong>Name der Führungsstelle fehlt – Eingaben bleiben
              gesperrt.</strong></span>
          <?php else: ?>
            <span>Führungsstelle:
              <?= incident_admin_html($status['fuehrungsstellenname']) ?></span>
          <?php endif; ?>
          <span><?= incident_admin_html(incident_admin_datetime($status['beginn'])) ?>
            bis <?= incident_admin_html(incident_admin_datetime($status['ende'])) ?></span>
          <?php if ($activeMissingHeader !== []): ?>
            <span><strong>Logbuchbetrieb noch gesperrt.</strong> Es fehlen:
              <?= incident_admin_html(implode(', ', $activeMissingHeader)) ?>.
            </span>
          <?php endif; ?>
        </div>
        <form method="post">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="deactivate">
          <input type="hidden" name="einsatz_id" value="<?= (int) $activeId ?>">
          <input type="hidden" name="status_revision" value="<?= $currentRevision ?>">
          <button class="estab-button estab-button-danger" type="submit">
            Einsatz pausieren
          </button>
        </form>
      </section>
      <section class="estab-tool-panel" aria-labelledby="incident-close-title">
        <header class="estab-tool-panel-heading">
          <p class="estab-tool-eyebrow">Revisionssicherer Abschluss</p>
          <h2 id="incident-close-title">Einsatz formal abschließen</h2>
          <p>Der formale Abschluss ist unwiderruflich. Danach sind alle normalen
            Fachdaten gesperrt und ETB sowie TBB werden mindestens zehn Jahre
            aufbewahrt.</p>
        </header>
        <?php if (is_array($activePreflight)): ?>
          <p class="estab-tool-feedback <?= $activePreflight['closable']
              ? 'estab-tool-feedback-success'
              : 'estab-tool-feedback-error' ?>">
            Abschlussprüfung:
            <?= (int) $activePreflight['open_messages'] ?> offene Nachrichten,
            <?= (int) $activePreflight['locked_messages'] ?> Nachrichtensperren,
            <?= (int) $activePreflight['incomplete_attachments'] ?>
            unvollständige Anhänge,
            <?= (int) $activePreflight['attachment_integrity_errors'] ?>
            Anhang-Integritätsfehler,
            <?= (int) $activePreflight['legacy_attachments_unverifiable'] ?>
            Legacy-Anhänge (Integrität beim Eingang nicht belegbar),
            <?= (int) $activePreflight['offene_melderauftraege'] ?>
            offene Melderaufträge,
            <?= (int) $activePreflight['offene_fernmeldeplanentwuerfe'] ?>
            Fernmeldeplanentwürfe,
            <?= (int) $activePreflight['evidence_errors'] ?> Nachweisfehler.
            ETB und TBB sind
            <?= $activePreflight['logbuecher_eroeffnet']
                ? 'ordnungsgemäß eröffnet.'
                : 'noch ohne Eröffnungszeile; das blockiert den Abschluss nicht.' ?>
            Historische formale Dienstplanung (nicht blockierend):
            <?= (int) $activePreflight['offene_schichten'] ?> Schichten,
            <?= (int) $activePreflight['offene_besetzungen'] ?> Besetzungen,
            <?= (int) $activePreflight['offene_uebergabeanforderungen'] ?>
            Übergabeanforderungen.
          </p>
        <?php endif; ?>
        <form class="estab-tool-form" method="post" data-estab-dirty-guard>
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="close">
          <input type="hidden" name="einsatz_id" value="<?= (int) $activeId ?>">
          <input type="hidden" name="status_revision" value="<?= $currentRevision ?>">
          <div class="estab-tool-form-grid">
            <div class="estab-tool-field">
              <label for="incident-actual-end">Tatsächliches Einsatzende *</label>
              <input
                id="incident-actual-end"
                type="datetime-local"
                name="ende"
                required
                value="<?= incident_admin_html(date('Y-m-d\TH:i')) ?>">
            </div>
            <div class="estab-tool-field estab-tool-field-wide">
              <label for="incident-close-note">Abschlussvermerk *</label>
              <textarea
                id="incident-close-note"
                name="close_note"
                maxlength="10000"
                required
                placeholder="Abschlusslage, Übergabe und noch bestehende Nachweise"></textarea>
            </div>
          </div>
          <label class="estab-tool-check">
            <input type="checkbox" name="confirm_close" value="1" required>
            Ich bestätige, dass alle Vorgänge abgeschlossen sind und der
            Einsatz nicht wieder aktiviert werden kann.
          </label>
          <div class="estab-tool-actions">
            <button
              class="estab-button estab-button-danger"
              type="submit"
              <?= is_array($activePreflight) && !$activePreflight['closable']
                  ? 'disabled' : '' ?>>
              Einsatz unwiderruflich abschließen
            </button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($error !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= incident_admin_html($error) ?>
      </p>
    <?php elseif ($flashMessage !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        <?= incident_admin_html($flashMessage) ?>
      </p>
    <?php endif; ?>

    <?php if ($status !== null): ?>
      <section class="estab-tool-panel" aria-labelledby="incident-create-title">
        <header class="estab-tool-panel-heading">
          <p class="estab-tool-eyebrow">Neuer Datenraum</p>
          <h2 id="incident-create-title">Einsatz anlegen</h2>
          <p>Die Kennung bleibt dauerhaft mit allen Einträgen und Exporten
            verbunden. Pflichtfelder sind mit einem Stern gekennzeichnet.</p>
        </header>
        <form class="estab-tool-form" method="post" data-estab-dirty-guard>
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="create">
          <input type="hidden" name="status_revision" value="<?= $currentRevision ?>">
          <div class="estab-tool-form-grid">
            <div class="estab-tool-field">
              <label for="incident-code">Einsatzkennung *</label>
              <input
                id="incident-code"
                name="kennung"
                required
                maxlength="64"
                autocomplete="off"
                placeholder="2026-HW-001"
                value="<?= incident_admin_html($old['kennung'] ?? '') ?>">
              <small>Dauerhafte, eindeutige Kennung für Export und Archiv.</small>
            </div>
            <div class="estab-tool-field">
              <label for="incident-name">Name *</label>
              <input
                id="incident-name"
                name="name"
                required
                maxlength="255"
                value="<?= incident_admin_html($old['name'] ?? '') ?>">
            </div>
            <div class="estab-tool-field">
              <label for="incident-command-post-name">
                Name der Führungsstelle *
              </label>
              <input
                id="incident-command-post-name"
                name="fuehrungsstellenname"
                required
                maxlength="<?= ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH ?>"
                placeholder="z. B. FüSt Kirchheim/Teck"
                value="<?= incident_admin_html(
                    $old['fuehrungsstellenname'] ?? ''
                ) ?>">
              <small>Die lokale Anschrift beziehungsweise Absendereinheit
                aller Nachrichten dieses Einsatzes. Nicht mit Einsatzname,
                Trägerorganisation oder Einsatzleitung verwechseln.</small>
            </div>
            <div class="estab-tool-field">
              <label for="incident-start">Beginn *</label>
              <input
                id="incident-start"
                type="datetime-local"
                name="beginn"
                required
                value="<?= incident_admin_html($old['beginn'] ?? $defaultStart) ?>">
            </div>
            <div class="estab-tool-field">
              <label for="incident-end">Geplantes/tatsächliches Ende</label>
              <input
                id="incident-end"
                type="datetime-local"
                name="ende"
                value="<?= incident_admin_html($old['ende'] ?? '') ?>">
            </div>
            <div class="estab-tool-field">
              <label for="incident-location">Ort</label>
              <input
                id="incident-location"
                name="ort"
                maxlength="255"
                value="<?= incident_admin_html($old['ort'] ?? '') ?>">
            </div>
            <div class="estab-tool-field">
              <label for="incident-organisation">Bedarfsträger *</label>
              <input
                id="incident-organisation"
                name="organisation"
                required
                maxlength="255"
                value="<?= incident_admin_html($old['organisation'] ?? '') ?>">
              <small>Organisation oder Stelle, in deren Auftrag der Einsatz
                geführt wird.</small>
            </div>
            <div class="estab-tool-field">
              <label for="incident-lead">
                Verantwortliche Einsatz-/Führungsleitung *
              </label>
              <input
                id="incident-lead"
                name="einsatzleitung"
                required
                maxlength="255"
                value="<?= incident_admin_html($old['einsatzleitung'] ?? '') ?>">
            </div>
            <div class="estab-tool-field estab-tool-field-wide">
              <label for="incident-description">
                Einsatzauftrag und Ausgangslage *
              </label>
              <textarea
                id="incident-description"
                name="beschreibung"
                required
                maxlength="10000"><?= incident_admin_html($old['beschreibung'] ?? '') ?></textarea>
              <small>So vollständig, dass der Eröffnungseintrag ohne weitere
                Anlage verständlich bleibt.</small>
            </div>
            <details class="estab-tool-field estab-tool-field-wide">
              <summary>Weitere strukturierte Metadaten (optional)</summary>
              <label for="incident-metadata">JSON-Objekt</label>
              <textarea
                id="incident-metadata"
                name="metadaten"
                maxlength="65535"
                spellcheck="false"
                placeholder='{"aktenzeichen":"..."}'><?= incident_admin_html($old['metadaten'] ?? '') ?></textarea>
              <small>Nur für zusätzliche organisationsspezifische Angaben;
                Ort, Führungsstelle, Organisation und Einsatzleitung gehören
                in die Felder oben.</small>
            </details>
          </div>
          <div class="estab-tool-actions">
            <button class="estab-button estab-button-primary" type="submit">
              Einsatz anlegen
            </button>
            <label class="estab-tool-check">
              <input
                type="checkbox"
                name="activate_immediately"
                value="1"
                <?= ($old['activate_immediately'] ?? null) === '1' ? 'checked' : '' ?>>
              Sofort systemweit aktivieren
            </label>
          </div>
        </form>
      </section>

      <section class="estab-tool-panel" aria-labelledby="incident-list-title">
        <header class="estab-tool-panel-heading">
          <p class="estab-tool-eyebrow">Einsatzarchiv</p>
          <h2 id="incident-list-title">Vorhandene Einsätze</h2>
          <p>Es kann systemweit immer nur ein Einsatz aktiv sein. Beendete
            Einsätze bleiben unverändert für Auswertung und Export erhalten.</p>
        </header>
        <?php if ($incidents === []): ?>
          <p class="estab-tool-empty">Noch keine Einsätze vorhanden.</p>
        <?php else: ?>
          <div class="estab-tool-list">
            <?php foreach ($incidents as $incident): ?>
              <?php
                $ended = ($incident['estab_status'] ?? null) === 'closed';
              ?>
              <article class="estab-tool-card<?= $incident['ist_aktiv'] ? ' estab-tool-card-active' : '' ?>">
                <div>
                  <span class="estab-tool-card-code">
                    <?= incident_admin_html($incident['kennung']) ?>
                  </span>
                  <h3><?= incident_admin_html($incident['name']) ?></h3>
                  <div class="estab-tool-card-meta">
                    <span><?= incident_admin_html(incident_admin_datetime($incident['beginn'])) ?>
                      bis <?= incident_admin_html(incident_admin_datetime($incident['ende'])) ?></span>
                    <?php if ($incident['ort'] !== ''): ?>
                      <span>Ort: <?= incident_admin_html($incident['ort']) ?></span>
                    <?php endif; ?>
                    <?php if ($incident['organisation'] !== ''): ?>
                      <span>Bedarfsträger:
                        <?= incident_admin_html($incident['organisation']) ?></span>
                    <?php endif; ?>
                    <?php if (($incident['fuehrungsstellenname'] ?? null) !== null): ?>
                      <span>Führungsstelle:
                        <?= incident_admin_html(
                            $incident['fuehrungsstellenname']
                        ) ?></span>
                    <?php else: ?>
                      <span><strong>Führungsstellenname noch nicht
                        festgelegt</strong></span>
                    <?php endif; ?>
                  </div>
                  <?php if ($incident['beschreibung'] !== ''): ?>
                    <p><?= nl2br(incident_admin_html($incident['beschreibung']), false) ?></p>
                  <?php endif; ?>
                  <?php if ($ended): ?>
                    <p><strong>Formal abgeschlossen:</strong>
                      <?= incident_admin_html(incident_admin_datetime(
                          $incident['estab_closed_at'] ?? null
                      )) ?>
                      durch <?= incident_admin_html($incident['estab_closed_by'] ?? '') ?>.
                      Aufbewahrung mindestens bis
                      <?= incident_admin_html(incident_admin_datetime(
                          $incident['estab_retain_until'] ?? null
                      )) ?>.</p>
                    <?php if (($incident['estab_close_note'] ?? '') !== ''): ?>
                      <p><?= nl2br(
                          incident_admin_html($incident['estab_close_note']),
                          false
                      ) ?></p>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($incident['estab_legal_hold']): ?>
                    <p class="estab-tool-feedback estab-tool-feedback-error">
                      <strong>Zusätzliche Aufbewahrungssperre aktiv.</strong>
                      <?= incident_admin_html(
                          $incident['estab_legal_hold_reason'] ?? ''
                      ) ?>
                    </p>
                  <?php endif; ?>
                </div>
                <div class="estab-tool-card-actions">
                  <?php if (
                      !$ended
                      && !($incident['logbuchkopf_gesperrt'] ?? true)
                  ): ?>
                    <?php
                      $headerMissing = estab_logbook_lifecycle_missing_header(
                          $incident
                      );
                    ?>
                    <details class="estab-tool-field"
                      <?= $headerMissing !== [] ? 'open' : '' ?>>
                      <summary>Pflichtangaben für ETB und TBB</summary>
                      <?php if ($headerMissing !== []): ?>
                        <p class="estab-tool-feedback estab-tool-feedback-error">
                          Vor Aktivierung des Einsatzes fehlen:
                          <?= incident_admin_html(implode(', ', $headerMissing)) ?>.
                        </p>
                      <?php endif; ?>
                      <form class="estab-tool-form" method="post"
                        data-estab-dirty-guard>
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="admin_action"
                          value="update_logbook_header">
                        <input type="hidden" name="einsatz_id"
                          value="<?= (int) $incident['einsatz_id'] ?>">
                        <?php foreach (
                            ['organisation', 'einsatzleitung', 'beschreibung']
                            as $expectedField
                        ): ?>
                          <input type="hidden"
                            name="expected_<?= $expectedField ?>"
                            value="<?= incident_admin_html(
                                $incident[$expectedField] ?? ''
                            ) ?>">
                        <?php endforeach; ?>
                        <label>
                          Bedarfsträger *
                          <input name="organisation" required maxlength="255"
                            value="<?= incident_admin_html(
                                $incident['organisation'] ?? ''
                            ) ?>">
                        </label>
                        <label>
                          Verantwortliche Einsatz-/Führungsleitung *
                          <input name="einsatzleitung" required maxlength="255"
                            value="<?= incident_admin_html(
                                $incident['einsatzleitung'] ?? ''
                            ) ?>">
                        </label>
                        <label>
                          Einsatzauftrag und Ausgangslage *
                          <textarea name="beschreibung" required
                            maxlength="10000"><?= incident_admin_html(
                                $incident['beschreibung'] ?? ''
                            ) ?></textarea>
                        </label>
                        <small>Nach Aktivierung des Einsatzes sind
                          diese Angaben Bestandteil der Eröffnungseinträge und
                          nicht mehr veränderbar.</small>
                        <button class="estab-button" type="submit">
                          Logbuch-Stammdaten speichern
                        </button>
                      </form>
                    </details>
                  <?php endif; ?>
                  <?php if (
                      !$ended
                      && (int) (
                          $incident['fuehrungsstellenname_gesperrt'] ?? 1
                      ) === 0
                  ): ?>
                    <form class="estab-tool-form" method="post"
                      data-estab-dirty-guard>
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="admin_action"
                        value="update_command_post_name">
                      <input type="hidden" name="einsatz_id"
                        value="<?= (int) $incident['einsatz_id'] ?>">
                      <input type="hidden"
                        name="expected_fuehrungsstellenname"
                        value="<?= incident_admin_html(
                            $incident['fuehrungsstellenname'] ?? ''
                        ) ?>">
                      <label>
                        Name der Führungsstelle
                        <input
                          name="fuehrungsstellenname"
                          required
                          maxlength="<?=
                            ESTAB_INCIDENT_COMMAND_POST_NAME_MAX_LENGTH
                          ?>"
                          value="<?= incident_admin_html(
                              $incident['fuehrungsstellenname'] ?? ''
                          ) ?>"
                          placeholder="z. B. FüSt Kirchheim/Teck">
                      </label>
                      <?php if (
                          ($incident['fuehrungsstellenname'] ?? null) === null
                      ): ?>
                        <small>Historischer Fehlwert: Nach dem Speichern ist
                          der Name wegen der vorhandenen Einsatzdaten
                          revisionssicher unveränderlich.</small>
                      <?php else: ?>
                        <small>Bis zur ersten operativen Eintragung kann der
                          Name korrigiert werden. Danach ist er
                          revisionssicher unveränderlich.</small>
                      <?php endif; ?>
                      <button class="estab-button" type="submit">
                        Führungsstellenname speichern
                      </button>
                    </form>
                  <?php elseif (
                      ($incident['fuehrungsstellenname'] ?? null) !== null
                  ): ?>
                    <div
                      class="estab-tool-field"
                      data-estab-command-post-readonly>
                      <span>Name der Führungsstelle</span>
                      <strong><?= incident_admin_html(
                          $incident['fuehrungsstellenname']
                      ) ?></strong>
                      <small>Der am Einsatz bestätigte Name wird hier nur
                        angezeigt. Er ist nicht über die Bedienoberfläche
                        änderbar.</small>
                    </div>
                  <?php endif; ?>
                  <?php if ($incident['ist_aktiv']): ?>
                    <span class="estab-tool-badge estab-tool-badge-success">
                      Aktiv
                    </span>
                  <?php elseif ($ended): ?>
                    <span class="estab-tool-badge estab-tool-badge-neutral">
                      Formal abgeschlossen
                    </span>
                  <?php else: ?>
                    <form method="post">
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="admin_action" value="activate">
                      <input
                        type="hidden"
                        name="einsatz_id"
                        value="<?= (int) $incident['einsatz_id'] ?>">
                      <input
                        type="hidden"
                        name="status_revision"
                        value="<?= $currentRevision ?>">
                      <button class="estab-button estab-button-primary"
                        type="submit"
                        <?= estab_logbook_lifecycle_missing_header($incident) !== []
                            ? 'disabled' : '' ?>>
                        Aktivieren
                      </button>
                    </form>
                  <?php endif; ?>
                  <?php if ($incident['estab_legal_hold']): ?>
                    <form method="post">
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="admin_action" value="release_legal_hold">
                      <input type="hidden" name="einsatz_id"
                        value="<?= (int) $incident['einsatz_id'] ?>">
                      <button class="estab-button" type="submit">
                        Zusatzsperre aufheben
                      </button>
                    </form>
                  <?php else: ?>
                    <form class="estab-tool-form" method="post">
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="admin_action" value="set_legal_hold">
                      <input type="hidden" name="einsatz_id"
                        value="<?= (int) $incident['einsatz_id'] ?>">
                      <label>
                        Grund der zusätzlichen Aufbewahrungssperre
                        <input
                          name="legal_hold_reason"
                          maxlength="1000"
                          required>
                      </label>
                      <button class="estab-button" type="submit">
                        Aufbewahrungssperre setzen
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zu den administrativen Maßnahmen</a>
      <span>Aktivierung und Deaktivierung werden revisionsgesichert protokolliert.</span>
    </footer>
  </main>
</body>
</html>
