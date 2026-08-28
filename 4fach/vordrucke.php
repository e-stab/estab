<?php

require_once __DIR__ . '/../app/generated_form.php';
require_once __DIR__ . '/../app/tabelle.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/read_authorization.php';
require_once __DIR__ . '/../app/session_ui.php';
require __DIR__ . '/../4fcfg/config.inc.php';
require __DIR__ . '/../4fcfg/dbcfg.inc.php';
require __DIR__ . '/../4fcfg/e_cfg.inc.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
estab_navigation_require_session($_SESSION, 'forms', $_SERVER);
$readIdentity = session_status() === PHP_SESSION_ACTIVE
    ? estab_read_session_identity($_SESSION)
    : null;
estab_navigation_require_selected_duty(
    $_SESSION,
    $readIdentity,
    'forms',
    $_SERVER
);
estab_session_ui_start($_SESSION);

$files = [];
$listError = null;
$noActiveIncident = false;
$connection = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $files = estab_read_with_locked_operational_scope(
        $connection,
        $readIdentity,
        static function (array $readScope) use (
            $connection,
            $conf_4f_tbl,
            $conf_4f_db,
            $conf_4f
        ): array {
            $incidentId = (int) (
                $readScope['incident']['active_einsatz_id']
            );
            $generated = estab_generated_form_list_for_incident(
                $connection,
                $conf_4f_tbl['nachrichten'],
                (string) $conf_4f_db['datenbank'],
                (string) $conf_4f['vordruck_dir'],
                $incidentId
            );
            return estab_read_filter_generated_forms_for_incident(
                $connection,
                $conf_4f_tbl['nachrichten'],
                $generated,
                $readScope['identity'],
                $incidentId
            );
        }
    );
} catch (EstabNoActiveIncidentException) {
    http_response_code(409);
    $noActiveIncident = true;
} catch (EstabReadPermissionException) {
    http_response_code(403);
    $listError = 'Keine Ihrer aktuell wirksamen Funktionen darf die Vordruckliste öffnen.';
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
        beendeter Einsätze werden hier bewusst nicht vermischt. Beim Öffnen
        entsteht aus dem aktuell gespeicherten Nachrichtendatensatz und der
        aktuellen Empfängermatrix ein rein lesender PDF-Abzug mit derselben
        Vorlage wie im PDF-Einsatzdossier. Die beim Abschluss erzeugte
        Archivdatei bleibt im Einsatzspeicher erhalten.</p>
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
      <?php
      /*
       * Die Vordruckliste kommt aus dem Tabellenbauteil (app/tabelle.php).
       *
       * Sie hatte ihre eigene Gestaltung und keine Suche. Bei einem Einsatz
       * mit tausend Meldungen ist eine Liste ohne Suche kein Verzeichnis,
       * sondern ein Stapel.
       */
      $vordruckZeilen = [];
      foreach ($files as $file) {
          $vordruckZeilen[] = [
              'meldung' => (string) $file['direction'] . ' ' . (string) $file['number'],
              'richtung' => (string) $file['direction'],
              'datei' => (string) $file['name'],
              'adresse' => estab_file_download_url(
                  (string) $conf_4f['download_uri'],
                  'vordruck',
                  $file['name']
              ) . '&layout=current',
              // Die Groesse als Zahl, damit die Spalte nach Groesse sortiert
              // und nicht nach Zeichenkette -- 9,8 KiB stuende sonst hinter
              // 10,1 KiB.
              'groesse' => number_format($file['size'] / 1024, 1, ',', '.') . ' KiB',
              'geaendert' => date('d.m.Y H:i:s', (int) $file['modified']),
          ];
      }
      echo estab_tabelle_markup([
          'id' => 'vordrucke',
          'beschriftung' => 'Generierte Nachrichtenvordrucke des aktiven Einsatzes',
          'spalten' => [
              ['schluessel' => 'meldung', 'kopf' => 'Meldung', 'breite' => 14,
                  'sortierbar' => true, 'suchbar' => true, 'art' => 'zahl'],
              ['schluessel' => 'richtung', 'kopf' => 'E/A', 'breite' => 7,
                  'sortierbar' => true, 'suchbar' => false, 'art' => 'text',
                  'filter' => ['E', 'A'], 'filtername' => 'Ein- und Ausgang'],
              ['schluessel' => 'datei', 'kopf' => 'Aktuelles PDF', 'breite' => 41,
                  'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
                  'zelle' => static fn (array $z): string =>
                      '<a class="estab-button" href="'
                          . estab_auth_html($z['adresse'])
                          . '" target="_blank" rel="noopener">Meldung als PDF öffnen'
                          . '<span class="estab-visually-hidden">'
                          . ' (öffnet in neuem Tab)</span></a>'
                          . '<small>Dateiname: <code>'
                          . estab_auth_html($z['datei']) . '</code></small>'],
              ['schluessel' => 'groesse', 'kopf' => 'Archivgröße', 'breite' => 13,
                  'sortierbar' => true, 'suchbar' => false, 'art' => 'zahl'],
              ['schluessel' => 'geaendert', 'kopf' => 'Archivdatei geändert',
                  'breite' => 25, 'sortierbar' => true, 'suchbar' => true,
                  'art' => 'zeit'],
          ],
          'zeilen' => $vordruckZeilen,
          'leer' => 'Kein Vordruck entspricht den gesetzten Filtern.',
      ]);
      ?>
    <?php endif; ?>

    <footer class="estab-tool-footer">
      <a href="../">Zur eStab-Übersicht</a>
      <span>PDF-Abzüge werden serverseitig gegen den aktiven Einsatz geprüft
        und verändern weder Nachricht noch Archivdatei.</span>
    </footer>
  </main>
</body>
</html>
