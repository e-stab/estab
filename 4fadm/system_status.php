<?php

declare(strict_types=1);

session_start();

if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Administrative authentication required.');
}

require_once __DIR__ . '/../app/bootstrap.php';
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

$extensions = [];
foreach (['mysqli', 'gd', 'mbstring', 'zip', 'Zend OPcache'] as $extension) {
    $extensions[$extension] = extension_loaded($extension);
}

$databaseReady = false;
$databaseTables = null;
if ($extensions['mysqli']) {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $database = mysqli_init();
        if ($database !== false) {
            $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            $connected = @$database->real_connect(
                estab_env('ESTAB_DB_HOST', 'db') ?? 'db',
                estab_env('ESTAB_DB_USER', 'estab') ?? 'estab',
                estab_env('ESTAB_DB_PASSWORD', '') ?? '',
                estab_env_identifier('ESTAB_DB_NAME', 'estab'),
                (int) (estab_env('ESTAB_DB_PORT', '3306') ?? '3306')
            );
            if ($connected) {
                $result = @$database->query(
                    'SELECT COUNT(*) AS table_count FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''
                );
                if ($result instanceof mysqli_result) {
                    $row = $result->fetch_assoc();
                    $databaseTables = isset($row['table_count']) ? (int) $row['table_count'] : null;
                    $databaseReady = $databaseTables !== null;
                    $result->free();
                }
                $database->close();
            }
        }
    } catch (Throwable) {
        $databaseReady = false;
    }
}

$databaseName = estab_env_identifier('ESTAB_DB_NAME', 'estab');
$storageChecks = [
    'Anhangsspeicher' => __DIR__ . '/../4fdata/' . $databaseName . '/anhang',
    'Vordruckspeicher' => __DIR__ . '/../4fdata/' . $databaseName . '/vordruck',
    'Einsatzexport' => estab_env('ESTAB_EXPORT_DIR', '/var/lib/estab/export') ?? '/var/lib/estab/export',
];

$storageReady = [];
foreach ($storageChecks as $label => $directory) {
    $storageReady[$label] = is_dir($directory) && is_writable($directory);
}

$environmentChecks = [
    'Öffentliche URL' => estab_env('ESTAB_PUBLIC_URL') !== null,
    'Organisation' => estab_env('ESTAB_ORGANISATION') !== null,
    'Hoheitszeichen' => estab_env('ESTAB_AUTHORITY_CODE') !== null,
    'Exportverzeichnis' => estab_env('ESTAB_EXPORT_DIR') !== null,
    'DB-Secret' => estab_env('ESTAB_DB_PASSWORD_FILE') !== null,
    'Admin-Secret' => estab_env('ESTAB_ADMIN_PASSWORD_FILE') !== null,
];

$runtimeReady = PHP_VERSION_ID >= 80500 && !in_array(false, $extensions, true);
$allStorageReady = !in_array(false, $storageReady, true);
$overallReady = $runtimeReady && $databaseReady && $allStorageReady;

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Systemstatus</title>
  <style>
    body { font: 16px/1.45 system-ui, sans-serif; max-width: 72rem; margin: 2rem auto; padding: 0 1rem; }
    table { border-collapse: collapse; width: 100%; margin: 1rem 0 2rem; }
    th, td { border: 1px solid #aaa; padding: .45rem; text-align: left; }
    .ready { color: #176b24; font-weight: 700; }
    .failed { color: #9b1c1c; font-weight: 700; }
    code { overflow-wrap: anywhere; }
  </style>
</head>
<body>
  <h1>Systemstatus</h1>
  <p class="<?= $overallReady ? 'ready' : 'failed' ?>">
    Gesamtzustand: <?= $overallReady ? 'betriebsbereit' : 'Prüfung erforderlich' ?>
  </p>

  <h2>Laufzeit</h2>
  <table>
    <tbody>
      <tr><th>PHP</th><td><?= system_status_html(PHP_VERSION) ?></td><td class="<?= $runtimeReady ? 'ready' : 'failed' ?>"><?= system_status_label($runtimeReady) ?></td></tr>
      <?php foreach ($extensions as $extension => $loaded): ?>
        <tr><th><?= system_status_html($extension) ?></th><td colspan="2" class="<?= $loaded ? 'ready' : 'failed' ?>"><?= $loaded ? 'geladen' : 'fehlt' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Datenbank</h2>
  <table>
    <tbody>
      <tr><th>Verbindung und Lesetest</th><td class="<?= $databaseReady ? 'ready' : 'failed' ?>"><?= system_status_label($databaseReady) ?></td></tr>
      <tr><th>Basistabellen</th><td><?= $databaseTables === null ? 'nicht ermittelbar' : (int) $databaseTables ?></td></tr>
    </tbody>
  </table>

  <h2>Persistente Speicher</h2>
  <table>
    <tbody>
      <?php foreach ($storageReady as $label => $ready): ?>
        <tr><th><?= system_status_html($label) ?></th><td class="<?= $ready ? 'ready' : 'failed' ?>"><?= system_status_label($ready) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Containerkonfiguration</h2>
  <p>Datenbank, Verzeichnisse und Einsatzparameter werden im Container über Compose, Umgebungsvariablen und eingehängte Secret-Dateien bereitgestellt. Die historischen Web-Installer, Konfigurationsschreiber und <code>phpinfo()</code> sind deshalb deaktiviert.</p>
  <table>
    <tbody>
      <?php foreach ($environmentChecks as $label => $configured): ?>
        <tr><th><?= system_status_html($label) ?></th><td><?= $configured ? 'konfiguriert' : 'Standardwert aktiv' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p><a href="export.php">Einsatzexport öffnen</a></p>
  <p><a href="admin.php">Zurück zu den administrativen Maßnahmen</a></p>
</body>
</html>
