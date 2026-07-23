<?php

require_once __DIR__ . '/../app/file_access.php';
require __DIR__ . '/../4fcfg/config.inc.php';

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

$files = estab_file_list((string) $conf_4f['vordruck_dir'], 'vordruck');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Generierte Vordrucke</title>
  <style>
    body { font-family: sans-serif; margin: 2rem; color: #202020; }
    table { border-collapse: collapse; width: min(100%, 70rem); }
    th, td { border: 1px solid #aaa; padding: .55rem .7rem; text-align: left; }
    th { background: #eee; }
    td:nth-child(2) { text-align: right; }
  </style>
</head>
<body>
  <h1>Generierte Vordrucke</h1>
  <p>Angemeldet als <?= estab_auth_html($_SESSION['vStab_benutzer']) ?>.</p>
<?php if ($files === []): ?>
  <p>Es sind noch keine Vordrucke vorhanden.</p>
<?php else: ?>
  <table>
    <thead>
      <tr><th>Datei</th><th>Größe</th><th>Erstellt/geändert</th></tr>
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
        <td><a href="<?= estab_auth_html($url) ?>" target="_blank" rel="noopener"><?= estab_auth_html($file['name']) ?></a></td>
        <td><?= estab_auth_html(number_format($file['size'] / 1024, 1, ',', '.')) ?> KiB</td>
        <td><?= estab_auth_html(date('d.m.Y H:i:s', $file['modified'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</body>
</html>
