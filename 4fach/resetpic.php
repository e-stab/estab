<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';

estab_admin_require_http_auth($_SERVER);

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
        } catch (Throwable $exception) {
            error_log('eStab print flag reset failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Die Grafikmarkierungen konnten nicht atomar zurückgesetzt werden.';
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
  <title>eStab Grafikmarkierungen zurücksetzen</title>
  <style>
    body { font: 16px/1.45 system-ui, sans-serif; max-width: 50rem; margin: 2rem auto; padding: 0 1rem; }
    .error { color: #8b0000; font-weight: bold; }
    .success { color: #075f23; font-weight: bold; }
    .warning { border-left: .4rem solid #c87900; padding-left: 1rem; }
  </style>
</head>
<body>
  <h1>Grafikerzeugung zurücksetzen</h1>

  <?php if ($updated && $affected !== null): ?>
    <p class="success"><?= $affected ?> Grafikmarkierung(en) wurden zurückgesetzt.</p>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <p class="error"><?= estab_admin_html($error) ?></p>
  <?php endif; ?>

  <p class="warning">Nach der Bestätigung werden die Markierungen aller bereits
    erzeugten Nachrichtengrafiken entfernt. Abgeschlossene Nachrichten werden
    anschließend erneut als Grafik beziehungsweise PDF erzeugt. Dieser
    Vorgang kann mehrere Sekunden dauern und liefert historisch keine eigene
    Fortschrittsanzeige.</p>

  <form method="post" action="resetpic.php">
    <?= estab_csrf_field() ?>
    <input type="hidden" name="admin_action" value="reset_print_flags">
    <button type="submit">Grafikmarkierungen jetzt zurücksetzen</button>
    <a href="../4fadm/admin.php">Abbrechen</a>
  </form>
</body>
</html>
