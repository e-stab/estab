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
} catch (Throwable $exception) {
    error_log('eStab message counter lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    $error = 'Der aktuelle Nachrichtenzähler konnte nicht gelesen werden.';
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
  <style>
    body { font: 16px/1.45 system-ui, sans-serif; max-width: 50rem; margin: 2rem auto; padding: 0 1rem; }
    table { border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: .55rem; text-align: left; }
    .error { color: #8b0000; font-weight: bold; }
    .success { color: #075f23; font-weight: bold; }
  </style>
</head>
<body data-counter-mode="<?= estab_admin_html($mode) ?>">
  <h1>Nachrichtennummer nach Systemausfall erhöhen</h1>
  <p>Hier wird die letzte auf der Rückfallebene verwendete Nummer eingetragen.
    Der Vorgang kann einen Zähler niemals absenken und erzeugt
    Systemnachricht sowie Audit-Eintrag in derselben Transaktion.</p>

  <?php if ($updated): ?>
    <p class="success">Der Nachrichtenzähler wurde erhöht.</p>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <p class="error"><?= estab_admin_html($error) ?></p>
  <?php endif; ?>

  <form method="post" action="set_number_after_crash.php" data-estab-dirty-guard
    <?= $error !== null && $submitted !== []
        ? 'data-estab-dirty-initial'
        : '' ?>>
    <?= estab_csrf_field() ?>
    <input type="hidden" name="admin_action" value="raise_counter">
    <table>
      <tbody>
      <?php if ($mode === 'gemeinsam'): ?>
        <tr>
          <th scope="row"><label for="ea_nummer">Ein-/Ausgangsnummer</label></th>
          <td>
            <input
              id="ea_nummer"
              name="ea_nummer"
              type="number"
              min="<?= $current['ea_nummer'] + 1 ?>"
              max="<?= ESTAB_ADMIN_COUNTER_MAX ?>"
              required
              value="<?= estab_admin_html($submitted['ea_nummer'] ?? '') ?>">
            <small>Aktueller Höchstwert: <strong id="current-common"><?= $current['ea_nummer'] ?></strong></small>
          </td>
        </tr>
      <?php else: ?>
        <tr>
          <th scope="row"><label for="e_nummer">Eingangsnummer</label></th>
          <td>
            <input
              id="e_nummer"
              name="e_nummer"
              type="number"
              min="<?= $current['e_nummer'] + 1 ?>"
              max="<?= ESTAB_ADMIN_COUNTER_MAX ?>"
              required
              value="<?= estab_admin_html($submitted['e_nummer'] ?? '') ?>">
            <small>Aktueller Höchstwert: <strong id="current-incoming"><?= $current['e_nummer'] ?></strong></small>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="a_nummer">Ausgangsnummer</label></th>
          <td>
            <input
              id="a_nummer"
              name="a_nummer"
              type="number"
              min="<?= $current['a_nummer'] + 1 ?>"
              max="<?= ESTAB_ADMIN_COUNTER_MAX ?>"
              required
              value="<?= estab_admin_html($submitted['a_nummer'] ?? '') ?>">
            <small>Aktueller Höchstwert: <strong id="current-outgoing"><?= $current['a_nummer'] ?></strong></small>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
    <p>
      <button type="submit">Zähler atomar erhöhen</button>
      <a href="admin.php">Abbrechen</a>
    </p>
  </form>
</body>
</html>
