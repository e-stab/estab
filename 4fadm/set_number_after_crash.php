<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);

$mode = estab_admin_validate_counter_mode((string) Nachweisung);
$error = null;
$submitted = [];
$counterAvailable = true;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
    } catch (Throwable) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen.';
    }

    if ($error === null) {
        if (($_POST['admin_action'] ?? null) !== 'raise_counter') {
            http_response_code(422);
            $error = 'Unbekannte administrative Aktion.';
        }
    }

    if ($error === null) {
        $validation = estab_admin_validate_counter_input($_POST, $mode);
        $submitted = $validation['data'];
        if (!$validation['valid']) {
            http_response_code(422);
            $error = 'Nur positive ganze Nachrichtennummern bis '
                . ESTAB_ADMIN_COUNTER_MAX . ' sind zulässig.';
        } else {
            try {
                $connection = estab_auth_connect($conf_4f_db);
                try {
                    estab_admin_raise_message_counter(
                        $connection,
                        $conf_4f_tbl['nachrichten'],
                        $conf_4f_tbl['protokoll'],
                        $mode,
                        $validation['data']
                    );
                } finally {
                    estab_auth_close($connection);
                }
                header('Location: set_number_after_crash.php?updated=1', true, 303);
                exit;
            } catch (EstabAdminConflictException $exception) {
                http_response_code(409);
                $error = $exception->getMessage();
            } catch (EstabNoActiveIncidentException) {
                http_response_code(409);
                $error = 'Ohne aktiven Einsatz kann der Nachrichtenzähler '
                    . 'nicht verändert werden.';
                $counterAvailable = false;
            } catch (Throwable $exception) {
                error_log('eStab message counter update failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Der Nachrichtenzähler konnte nicht atomar erhöht werden.';
            }
        }
    }
}

try {
    $connection = estab_auth_connect($conf_4f_db);
    try {
        $current = estab_admin_fetch_counter_maxima(
            $connection,
            $conf_4f_tbl['nachrichten'],
            $mode
        );
    } finally {
        estab_auth_close($connection);
    }
} catch (EstabNoActiveIncidentException) {
    http_response_code(409);
    $error = 'Kein Einsatz aktiv. Aktivieren Sie zuerst einen Einsatz.';
    $counterAvailable = false;
    $current = $mode === 'gemeinsam'
        ? ['ea_nummer' => 0]
        : ['e_nummer' => 0, 'a_nummer' => 0];
} catch (Throwable $exception) {
    error_log('eStab message counter lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    $error = 'Der aktuelle Nachrichtenzähler konnte nicht gelesen werden.';
    $counterAvailable = false;
    $current = $mode === 'gemeinsam'
        ? ['ea_nummer' => 0]
        : ['e_nummer' => 0, 'a_nummer' => 0];
}

$updated = ($_GET['updated'] ?? '') === '1';

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Nachrichtenzähler</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main
    class="estab-tool-main estab-tool-main-narrow"
    data-counter-mode="<?= estab_admin_html($mode) ?>"
    data-estab-counter-tool>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Administration · Notfallmaßnahme</p>
      <h1>Nachrichtenzähler erhöhen</h1>
      <p>Tragen Sie nach einem Systemausfall die letzte auf Papier verwendete
        Nachrichtennummer ein. Der Zähler kann ausschließlich erhöht werden.</p>
    </header>

    <aside class="estab-tool-notice" aria-label="Sicherheitswirkung">
      <strong>Transaktional und einsatzbezogen:</strong>
      <p>Die Korrektur gilt nur für den aktiven Einsatz und erzeugt
        Systemnachricht sowie Audit-Eintrag in derselben Transaktion.
        Ein vorhandener Höchstwert wird niemals abgesenkt.</p>
    </aside>

    <?php if ($updated): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        Der Nachrichtenzähler wurde erhöht.
      </p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= estab_admin_html($error) ?>
      </p>
    <?php endif; ?>

    <section class="estab-tool-panel" aria-labelledby="counter-form-title">
      <header class="estab-tool-panel-heading">
        <h2 id="counter-form-title">Letzte vergebene Nummer eintragen</h2>
        <p>Aktuelle Werte werden direkt am jeweiligen Eingabefeld angezeigt.
          Zulässig ist nur eine größere, positive ganze Zahl.</p>
      </header>
      <form
        class="estab-tool-form"
        method="post"
        action="set_number_after_crash.php"
        data-estab-dirty-guard
        data-estab-requires-incident
        <?= $error !== null && $submitted !== []
            ? 'data-estab-dirty-initial'
            : '' ?>>
        <?= estab_csrf_field() ?>
        <input type="hidden" name="admin_action" value="raise_counter">
        <fieldset class="estab-tool-counter-grid">
          <legend class="estab-visually-hidden">
            Nachrichtennummern des aktiven Einsatzes
          </legend>
      <?php if ($mode === 'gemeinsam'): ?>
          <div class="estab-tool-field estab-tool-field-wide">
            <label for="ea_nummer">Ein-/Ausgangsnummer</label>
            <input
              id="ea_nummer"
              name="ea_nummer"
              type="number"
              min="<?= $current['ea_nummer'] + 1 ?>"
              max="<?= ESTAB_ADMIN_COUNTER_MAX ?>"
              required
              value="<?= estab_admin_html($submitted['ea_nummer'] ?? '') ?>">
            <small>Aktueller Höchstwert:
              <strong id="current-common"><?= $current['ea_nummer'] ?></strong>
            </small>
          </div>
      <?php else: ?>
          <div class="estab-tool-field">
            <label for="e_nummer">Eingangsnummer</label>
            <input
              id="e_nummer"
              name="e_nummer"
              type="number"
              min="<?= $current['e_nummer'] + 1 ?>"
              max="<?= ESTAB_ADMIN_COUNTER_MAX ?>"
              required
              value="<?= estab_admin_html($submitted['e_nummer'] ?? '') ?>">
            <small>Aktueller Höchstwert:
              <strong id="current-incoming"><?= $current['e_nummer'] ?></strong>
            </small>
          </div>
          <div class="estab-tool-field">
            <label for="a_nummer">Ausgangsnummer</label>
            <input
              id="a_nummer"
              name="a_nummer"
              type="number"
              min="<?= $current['a_nummer'] + 1 ?>"
              max="<?= ESTAB_ADMIN_COUNTER_MAX ?>"
              required
              value="<?= estab_admin_html($submitted['a_nummer'] ?? '') ?>">
            <small>Aktueller Höchstwert:
              <strong id="current-outgoing"><?= $current['a_nummer'] ?></strong>
            </small>
          </div>
      <?php endif; ?>
        </fieldset>
        <div class="estab-tool-actions">
          <button
            class="estab-button estab-button-primary"
            type="submit"
            <?= $counterAvailable ? '' : 'disabled' ?>>
            Zähler atomar erhöhen
          </button>
          <a class="estab-button" href="admin.php">Abbrechen</a>
        </div>
      </form>
    </section>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zur Administration</a>
      <span>Diese Maßnahme ist ausschließlich für die dokumentierte Rückfallebene vorgesehen.</span>
    </footer>
  </main>
</body>
</html>
