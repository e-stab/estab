<?php

require_once __DIR__ . '/../app/generated_form.php';
require_once __DIR__ . '/../app/session_ui.php';
require __DIR__ . '/../4fcfg/config.inc.php';
require __DIR__ . '/../4fcfg/dbcfg.inc.php';
require __DIR__ . '/../4fcfg/e_cfg.inc.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (
    session_status() !== PHP_SESSION_ACTIVE
    || !estab_auth_session_is_authenticated($_SESSION)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Anmeldung erforderlich.';
    exit;
}
estab_session_ui_start($_SESSION);

$files = [];
$listError = null;
$noActiveIncident = false;
$connection = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $files = estab_generated_form_list_active(
        $connection,
        $conf_4f_tbl['nachrichten'],
        (string) $conf_4f_db['datenbank'],
        (string) $conf_4f['vordruck_dir']
    );
} catch (EstabNoActiveIncidentException) {
    $noActiveIncident = true;
} catch (Throwable $exception) {
    error_log('eStab generated-form list failed: ' . $exception->getMessage());
    http_response_code(503);
    $listError = 'Die Vordruckliste kann derzeit nicht sicher geprüft werden.';
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Generierte Vordrucke</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main class="estab-tool-main" data-estab-generated-forms>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Nachrichten · Dokumente</p>
      <h1>Generierte Vordrucke</h1>
      <p>Hier finden Sie ausschließlich die abgeschlossenen und erzeugten
        Nachrichtenvordrucke des aktuell aktiven Einsatzes.</p>
    </header>

    <aside class="estab-tool-notice" aria-label="Gültigkeitsbereich">
      <strong>Einsatzbezogene Ansicht:</strong>
      <p>Ein Einsatzwechsel aktualisiert diese Liste. Vordrucke anderer oder
        beendeter Einsätze werden hier bewusst nicht vermischt.</p>
    </aside>

    <?php if ($listError !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= estab_auth_html($listError) ?>
      </p>
    <?php elseif ($noActiveIncident): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        Ohne aktiven Einsatz werden keine Vordrucke angezeigt.
        Aktivieren Sie zuerst einen Einsatz in der Administration.
      </p>
    <?php elseif ($files === []): ?>
      <section class="estab-tool-panel" aria-label="Keine Vordrucke">
        <p class="estab-tool-empty">
          Es sind für den aktiven Einsatz noch keine Vordrucke vorhanden.
        </p>
      </section>
    <?php else: ?>
      <div class="estab-tool-table-wrap estab-tool-table-responsive">
        <table class="estab-tool-table">
          <caption class="estab-visually-hidden">
            Generierte Nachrichtenvordrucke des aktiven Einsatzes
          </caption>
          <thead>
            <tr>
              <th scope="col">Meldung</th>
              <th scope="col">Datei und Download</th>
              <th scope="col">Größe</th>
              <th scope="col">Erstellt/geändert</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($files as $file): ?>
          <?php
              $url = estab_file_download_url(
                  (string) $conf_4f['download_uri'],
                  'vordruck',
                  $file['name']
              );
          ?>
            <tr>
              <td data-label="Meldung">
                <strong>
                  <?= estab_auth_html($file['direction'] . ' ' . $file['number']) ?>
                </strong>
              </td>
              <td data-label="Datei und Download">
                <a
                  class="estab-button"
                  href="<?= estab_auth_html($url) ?>"
                  target="_blank"
                  rel="noopener">
                  <?= estab_auth_html($file['name']) ?> herunterladen
                </a>
              </td>
              <td class="estab-tool-table-number" data-label="Größe">
                <?= estab_auth_html(
                    number_format($file['size'] / 1024, 1, ',', '.')
                ) ?> KiB
              </td>
              <td data-label="Erstellt/geändert">
                <?= estab_auth_html(
                    date('d.m.Y H:i:s', $file['modified'])
                ) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <footer class="estab-tool-footer">
      <a href="../">Zur eStab-Übersicht</a>
      <span>Downloads werden serverseitig gegen den aktiven Einsatz geprüft.</span>
    </footer>
  </main>
</body>
</html>
