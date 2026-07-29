<?php

declare(strict_types=1);

session_start();

if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Administrative authentication required.');
}

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/readiness.php';
require_once __DIR__ . '/../app/session_ui.php';
estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function system_status_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function system_status_label(bool $ready): string
{
    return $ready ? 'bereit' : 'nicht bereit';
}

$readiness = estab_readiness_report();
$checks = $readiness['checks'];
$extensions = $readiness['extensions'];
$databaseTables = $readiness['database_tables'];
$storageReady = $readiness['storage'];
$databaseReady = $checks['database'];
$schemaReady = $checks['schema'];
$configurationReady = $checks['configuration'];

$environmentChecks = [
    'Öffentliche URL' => estab_env('ESTAB_PUBLIC_URL') !== null,
    'Organisation' => estab_env('ESTAB_ORGANISATION') !== null,
    'Hoheitszeichen' => estab_env('ESTAB_AUTHORITY_CODE') !== null,
    'Exportverzeichnis' => estab_env('ESTAB_EXPORT_DIR') !== null,
    'DB-Secret' => estab_env('ESTAB_DB_PASSWORD_FILE') !== null,
    'Admin-Secret' => estab_env('ESTAB_ADMIN_PASSWORD_FILE') !== null,
];

$runtimeReady = $checks['php'] && $checks['extensions'];
$overallReady = $readiness['ready'];

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Systemstatus</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main class="estab-tool-main" data-estab-system-status>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Administration · Diagnose</p>
      <h1>Systemstatus</h1>
      <p>Diese Ansicht prüft Laufzeit, Datenbank, persistente Speicher und die
        wirksame Containerkonfiguration, ohne Secrets oder interne
        Zugangsdaten offenzulegen.</p>
    </header>

    <section
      class="estab-tool-status <?= $overallReady
          ? 'estab-tool-status-active'
          : 'estab-tool-status-danger' ?>"
      role="<?= $overallReady ? 'status' : 'alert' ?>"
      data-estab-readiness="<?= $overallReady ? 'ready' : 'failed' ?>">
      <div>
        <span>Gesamtzustand</span>
        <strong>
          <?= $overallReady ? 'Betriebsbereit' : 'Prüfung erforderlich' ?>
        </strong>
        <span>
          <?= $overallReady
              ? 'Alle verpflichtenden Bereitschaftsprüfungen waren erfolgreich.'
              : 'Mindestens eine verpflichtende Prüfung ist fehlgeschlagen.' ?>
        </span>
      </div>
    </section>

    <section class="estab-tool-panel" aria-labelledby="runtime-status-title">
      <header class="estab-tool-panel-heading">
        <h2 id="runtime-status-title">Laufzeit</h2>
        <p>PHP-Version, Laufzeitkonfiguration und verpflichtende Erweiterungen.</p>
      </header>
      <div class="estab-tool-table-wrap estab-tool-table-responsive">
        <table class="estab-tool-table">
          <caption class="estab-visually-hidden">Status der PHP-Laufzeit</caption>
          <thead>
            <tr>
              <th scope="col">Prüfung</th>
              <th scope="col">Wert</th>
              <th scope="col">Ergebnis</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td data-label="Prüfung">PHP</td>
              <td data-label="Wert"><code><?= system_status_html(PHP_VERSION) ?></code></td>
              <td data-label="Ergebnis">
                <span class="estab-tool-badge <?= $runtimeReady
                    ? 'estab-tool-badge-success'
                    : 'estab-tool-badge-danger' ?>">
                  <?= system_status_label($runtimeReady) ?>
                </span>
              </td>
            </tr>
            <tr>
              <td data-label="Prüfung">Laufzeitkonfiguration</td>
              <td data-label="Wert">Container- und PHP-Vorgaben</td>
              <td data-label="Ergebnis">
                <span class="estab-tool-badge <?= $configurationReady
                    ? 'estab-tool-badge-success'
                    : 'estab-tool-badge-danger' ?>">
                  <?= system_status_label($configurationReady) ?>
                </span>
              </td>
            </tr>
            <?php foreach ($extensions as $extension => $loaded): ?>
              <tr>
                <td data-label="Prüfung">PHP-Erweiterung</td>
                <td data-label="Wert"><code><?= system_status_html($extension) ?></code></td>
                <td data-label="Ergebnis">
                  <span class="estab-tool-badge <?= $loaded
                      ? 'estab-tool-badge-success'
                      : 'estab-tool-badge-danger' ?>">
                    <?= $loaded ? 'geladen' : 'fehlt' ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="estab-tool-panel" aria-labelledby="database-status-title">
      <header class="estab-tool-panel-heading">
        <h2 id="database-status-title">Datenbank</h2>
        <p>Erreichbarkeit und Vollständigkeit des erwarteten Schemas.</p>
      </header>
      <div class="estab-tool-table-wrap estab-tool-table-responsive">
        <table class="estab-tool-table">
          <caption class="estab-visually-hidden">Status der Datenbank</caption>
          <thead>
            <tr>
              <th scope="col">Prüfung</th>
              <th scope="col">Ergebnis</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ([
                'Verbindung und Lesetest' => $databaseReady,
                'Schema, Matrix und Migrationen' => $schemaReady,
            ] as $label => $ready): ?>
              <tr>
                <td data-label="Prüfung"><?= system_status_html($label) ?></td>
                <td data-label="Ergebnis">
                  <span class="estab-tool-badge <?= $ready
                      ? 'estab-tool-badge-success'
                      : 'estab-tool-badge-danger' ?>">
                    <?= system_status_label($ready) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td data-label="Prüfung">Basistabellen</td>
              <td data-label="Ergebnis">
                <?= $databaseTables === null
                    ? 'nicht ermittelbar'
                    : (int) $databaseTables ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="estab-tool-panel" aria-labelledby="storage-status-title">
      <header class="estab-tool-panel-heading">
        <h2 id="storage-status-title">Persistente Speicher</h2>
        <p>Schreib- und Lesebereitschaft der dauerhaft eingebundenen Datenpfade.</p>
      </header>
      <div class="estab-tool-table-wrap estab-tool-table-responsive">
        <table class="estab-tool-table">
          <caption class="estab-visually-hidden">Status der persistenten Speicher</caption>
          <thead>
            <tr>
              <th scope="col">Speicher</th>
              <th scope="col">Ergebnis</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($storageReady as $label => $ready): ?>
              <tr>
                <td data-label="Speicher"><?= system_status_html($label) ?></td>
                <td data-label="Ergebnis">
                  <span class="estab-tool-badge <?= $ready
                      ? 'estab-tool-badge-success'
                      : 'estab-tool-badge-danger' ?>">
                    <?= system_status_label($ready) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="estab-tool-panel" aria-labelledby="configuration-status-title">
      <header class="estab-tool-panel-heading">
        <h2 id="configuration-status-title">Containerkonfiguration</h2>
        <p>Datenbank, Verzeichnisse und Laufzeitparameter werden über Compose,
          Umgebungsvariablen und eingehängte Secret-Dateien bereitgestellt.
          Historische Web-Installer, Konfigurationsschreiber und
          <code>phpinfo()</code> sind deshalb deaktiviert.</p>
      </header>
      <div class="estab-tool-table-wrap estab-tool-table-responsive">
        <table class="estab-tool-table">
          <caption class="estab-visually-hidden">
            Wirksame nicht-sensible Containerkonfiguration
          </caption>
          <thead>
            <tr>
              <th scope="col">Vorgabe</th>
              <th scope="col">Zustand</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($environmentChecks as $label => $configured): ?>
              <tr>
                <td data-label="Vorgabe"><?= system_status_html($label) ?></td>
                <td data-label="Zustand">
                  <span class="estab-tool-badge <?= $configured
                      ? 'estab-tool-badge-success'
                      : 'estab-tool-badge-neutral' ?>">
                    <?= $configured ? 'konfiguriert' : 'Standardwert aktiv' ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zur Administration</a>
      <a href="export.php">Einsatzexporte öffnen</a>
    </footer>
  </main>
</body>
</html>
