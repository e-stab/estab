<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
    } catch (Throwable) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen.';
    }

    if ($error === null) {
        if (($_POST['admin_action'] ?? null) !== 'reset_print_flags') {
            http_response_code(422);
            $error = 'Unbekannte administrative Aktion.';
        }
    }

    if ($error === null) {
        try {
            $connection = estab_auth_connect($conf_4f_db);
            try {
                $affected = estab_admin_reset_print_flags(
                    $connection,
                    $conf_4f_tbl['nachrichten'],
                    $conf_4f_tbl['protokoll']
                );
            } finally {
                estab_auth_close($connection);
            }
            header('Location: resetpic.php?updated=1&affected=' . $affected, true, 303);
            exit;
        } catch (EstabNoActiveIncidentException) {
            http_response_code(409);
            $error = 'Ohne aktiven Einsatz können keine '
                . 'Vordruckmarkierungen zurückgesetzt werden.';
        } catch (Throwable $exception) {
            error_log('eStab print flag reset failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Die Vordruckmarkierungen konnten nicht atomar zurückgesetzt werden.';
        }
    }
}

$updated = ($_GET['updated'] ?? '') === '1';
$affectedValue = $_GET['affected'] ?? '';
$affected = is_string($affectedValue) && preg_match('/\A[0-9]{1,10}\z/D', $affectedValue) === 1
    ? (int) $affectedValue
    : null;

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab PDF-Vordrucke erneut erzeugen</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main
    class="estab-tool-main estab-tool-main-narrow"
    data-estab-print-reset-tool>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Administration · Wiedererzeugung</p>
      <h1>PDF-Vordrucke erneut erzeugen</h1>
      <p>Setzen Sie die Erzeugungsmarkierungen abgeschlossener Nachrichten des
        aktiven Einsatzes gezielt zurück.</p>
    </header>

    <?php if ($updated && $affected !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        <?= $affected ?> Vordruckmarkierung(en) wurden zurückgesetzt.
      </p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= estab_admin_html($error) ?>
      </p>
    <?php endif; ?>

    <section class="estab-tool-panel" aria-labelledby="print-reset-title">
      <header class="estab-tool-panel-heading">
        <h2 id="print-reset-title">Wiedererzeugung bestätigen</h2>
        <p>Verwenden Sie diese Maßnahme, wenn vorhandene PDF-Vordrucke
          beschädigt sind oder kontrolliert neu erstellt werden sollen.</p>
      </header>

      <aside
        id="print-reset-impact"
        class="estab-tool-notice estab-tool-notice-warning"
        aria-label="Auswirkung der Wiedererzeugung">
        <strong>Wirkung auf den aktiven Einsatz:</strong>
        <p>Nach der Bestätigung werden ausschließlich dessen Markierungen
          zurückgesetzt. Abgeschlossene Nachrichten werden beim nächsten
          Abschlusslauf erneut als PDF erzeugt. Andere und bereits beendete
          Einsätze bleiben unverändert.</p>
      </aside>

      <form
        class="estab-tool-form"
        method="post"
        action="resetpic.php"
        data-estab-dirty-guard
        data-estab-requires-incident>
        <?= estab_csrf_field() ?>
        <input type="hidden" name="admin_action" value="reset_print_flags">
        <div class="estab-tool-actions">
          <button
            class="estab-button estab-button-primary"
            type="submit"
            aria-describedby="print-reset-impact">
            PDF-Vordrucke erneut erzeugen
          </button>
          <a class="estab-button" href="../4fadm/admin.php">Abbrechen</a>
        </div>
      </form>
    </section>

    <footer class="estab-tool-footer">
      <a href="../4fadm/admin.php">Zurück zur Administration</a>
      <span>Die Aktion wird mit der Anzahl betroffener Nachrichten protokolliert.</span>
    </footer>
  </main>
</body>
</html>
