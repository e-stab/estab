<?php

declare(strict_types=1);

/** Send a bounded error response without reflecting request data. */
function estab_info_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
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
</head>
<body>
  <h1>Problembericht</h1>
<?php if ($subject !== ''): ?>
  <p><strong><?= $escape($subject) ?></strong></p>
<?php endif; ?>
<?php if ($information !== ''): ?>
  <p><?= $escape($information) ?></p>
<?php endif; ?>
  <p><button type="button" onclick="window.close()">Fenster zu</button></p>
</body>
</html>
