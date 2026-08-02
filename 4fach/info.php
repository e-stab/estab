<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../app/session_ui.php';
estab_session_ui_start($_SESSION, false, true);

/** Show a bounded popup error without reflecting request data. */
function estab_info_error(int $status, string $message): never
{
    estab_session_ui_abort(
        $_SESSION,
        $status,
        $status === 405 ? 'Anfragemethode nicht unterstützt' : 'Ungültige Anfrage',
        $message,
        'messages',
        true
    );
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!is_string($method) || !in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    estab_info_error(405, 'Method not allowed.');
}
foreach (array_keys($_GET) as $key) {
    if (!is_string($key) || !in_array($key, ['sub', 'info'], true)) {
        estab_info_error(400, 'Ungültige Anfrage.');
    }
}

$readText = static function (string $key, int $maximumBytes): string {
    if (!array_key_exists($key, $_GET)) {
        return '';
    }
    $value = $_GET[$key];
    if (
        !is_string($value)
        || strlen($value) > $maximumBytes
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{C}]/u', $value) === 1
    ) {
        estab_info_error(400, 'Ungültige Anfrage.');
    }
    return trim($value);
};
$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$subject = $readText('sub', 200);
$information = $readText('info', 1000);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
if ($method === 'HEAD') {
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Problembericht</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main
    class="estab-tool-main estab-tool-main-narrow"
    data-estab-problem-report>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Nachrichten · Hinweis</p>
      <h1>Problembericht</h1>
      <p>Die Anwendung konnte einen Teil der gewählten Aktion nicht
        vollständig ausführen.</p>
    </header>
    <section class="estab-tool-panel" aria-labelledby="problem-report-title">
      <header class="estab-tool-panel-heading">
        <h2 id="problem-report-title">Details</h2>
      </header>
<?php if ($subject !== ''): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= $escape($subject) ?>
      </p>
<?php endif; ?>
<?php if ($information !== ''): ?>
      <p><?= $escape($information) ?></p>
<?php endif; ?>
      <?php if ($subject === '' && $information === ''): ?>
        <p class="estab-tool-empty">Es wurden keine weiteren Details übermittelt.</p>
      <?php endif; ?>
      <div class="estab-tool-actions">
        <button
          class="estab-button estab-button-primary"
          type="button"
          onclick="window.close()">
          Problemfenster schließen
        </button>
      </div>
    </section>
    <footer class="estab-tool-footer">
      <span>Bei wiederholten Fehlern den Zeitpunkt und die ausgeführte Aktion
        an die Administration weitergeben.</span>
    </footer>
  </main>
</body>
</html>
