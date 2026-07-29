<?php

declare(strict_types=1);

session_start();

if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Administrative authentication required.');
}

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
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
            } else {
                throw new EstabIncidentInputException(
                    'Unbekannte administrative Aktion.'
                );
            }
        } finally {
            estab_auth_close($connection);
        }
        incident_admin_redirect((string) $redirectResult);
    } catch (EstabIncidentInputException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (EstabIncidentNotFoundException $exception) {
        http_response_code(404);
        $error = $exception->getMessage();
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
try {
    $connection = estab_auth_connect($conf_4f_db);
    try {
        $status = estab_incident_status($connection);
        $incidents = estab_incident_list($connection);
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
        'deactivated' => 'Der Einsatz wurde deaktiviert. Eingaben sind jetzt gesperrt.',
        default => null,
    };
}

$old = $requestMethod === 'POST' ? $_POST : [];
$defaultStart = date('Y-m-d\TH:i');
$currentRevision = is_array($status) ? (int) $status['revision'] : 0;
$activeId = is_array($status) ? $status['active_einsatz_id'] : null;

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
          <span><?= incident_admin_html(incident_admin_datetime($status['beginn'])) ?>
            bis <?= incident_admin_html(incident_admin_datetime($status['ende'])) ?></span>
        </div>
        <form method="post">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="deactivate">
          <input type="hidden" name="einsatz_id" value="<?= (int) $activeId ?>">
          <input type="hidden" name="status_revision" value="<?= $currentRevision ?>">
          <button class="estab-button estab-button-danger" type="submit">
            Einsatz deaktivieren
          </button>
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
              <label for="incident-organisation">Organisation</label>
              <input
                id="incident-organisation"
                name="organisation"
                maxlength="255"
                value="<?= incident_admin_html($old['organisation'] ?? '') ?>">
            </div>
            <div class="estab-tool-field">
              <label for="incident-lead">Einsatzleitung</label>
              <input
                id="incident-lead"
                name="einsatzleitung"
                maxlength="255"
                value="<?= incident_admin_html($old['einsatzleitung'] ?? '') ?>">
            </div>
            <div class="estab-tool-field estab-tool-field-wide">
              <label for="incident-description">Beschreibung</label>
              <textarea
                id="incident-description"
                name="beschreibung"
                maxlength="10000"><?= incident_admin_html($old['beschreibung'] ?? '') ?></textarea>
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
                Ort, Organisation und Einsatzleitung gehören in die Felder oben.</small>
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
                $ended = is_string($incident['ende'] ?? null)
                    && $incident['ende'] !== ''
                    && strcmp($incident['ende'], date('Y-m-d H:i:s')) < 0;
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
                      <span>Organisation:
                        <?= incident_admin_html($incident['organisation']) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if ($incident['beschreibung'] !== ''): ?>
                    <p><?= nl2br(incident_admin_html($incident['beschreibung']), false) ?></p>
                  <?php endif; ?>
                </div>
                <div class="estab-tool-card-actions">
                  <?php if ($incident['ist_aktiv']): ?>
                    <span class="estab-tool-badge estab-tool-badge-success">
                      Aktiv
                    </span>
                  <?php elseif ($ended): ?>
                    <span class="estab-tool-badge estab-tool-badge-neutral">
                      Beendet
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
                      <button class="estab-button estab-button-primary" type="submit">
                        Aktivieren
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
