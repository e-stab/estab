<?php

declare(strict_types=1);

session_start();

if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Administrative authentication required.');
}

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/e_cfg.inc.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/export.php';

$export = null;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $connection = estab_auth_connect($conf_4f_db);
        try {
            $export = estab_export_database(
                $connection,
                estab_env('ESTAB_EXPORT_DIR', '/var/lib/estab/export') ?? '/var/lib/estab/export'
            );
        } finally {
            estab_auth_close($connection);
        }
    } catch (Throwable $exception) {
        error_log('eStab export failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Der Einsatzexport konnte nicht vollständig erstellt werden. Details stehen im Container-Log.';
    }
}

function export_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Einsatzexport</title>
  <style>
    body { font: 16px/1.45 system-ui, sans-serif; max-width: 80rem; margin: 2rem auto; padding: 0 1rem; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #aaa; padding: .4rem; text-align: left; }
    code { overflow-wrap: anywhere; }
    .error { color: #8b0000; font-weight: bold; }
  </style>
</head>
<body>
  <h1>Einsatzexport</h1>
  <p>Alle Basistabellen werden durch PHP als UTF-8-CSV mit Kopfzeile exportiert. Ein Manifest hält Datensatzanzahl und SHA-256-Prüfsumme fest; MariaDB benötigt dafür kein <code>FILE</code>-Privileg.</p>

  <?php if ($error !== null): ?>
    <p class="error"><?= export_html($error) ?></p>
  <?php elseif (is_array($export)): ?>
    <p><strong>Export vollständig.</strong></p>
    <p>Verzeichnis: <code><?= export_html($export['directory']) ?></code></p>
    <?php if (is_string($export['archive'])): ?>
      <p>ZIP-Archiv: <code><?= export_html($export['archive']) ?></code></p>
    <?php endif; ?>
    <table>
      <thead><tr><th>Tabelle</th><th>Datensätze</th><th>SHA-256</th></tr></thead>
      <tbody>
      <?php foreach ($export['manifest']['tables'] as $table): ?>
        <tr>
          <td><?= export_html($table['table']) ?></td>
          <td><?= (int) $table['rows'] ?></td>
          <td><code><?= export_html($table['sha256']) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post">
    <?= estab_csrf_field() ?>
    <button type="submit">Neuen vollständigen Export erzeugen</button>
  </form>
  <p><a href="admin.php">Zurück zu den administrativen Maßnahmen</a></p>
</body>
</html>
