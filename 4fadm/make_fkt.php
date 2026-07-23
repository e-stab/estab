<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';

estab_admin_require_http_auth($_SERVER);

$error = null;
$submitted = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
    } catch (Throwable) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen.';
    }

    if ($error === null) {
        if (($_POST['admin_action'] ?? null) !== 'save_matrix') {
            http_response_code(422);
            $error = 'Unbekannte administrative Aktion.';
        }
    }

    if ($error === null) {
        $submitted = estab_admin_validate_matrix($_POST);
        if (!$submitted['valid']) {
            http_response_code(422);
            $error = 'Die Matrix ist ungültig. Funktionen müssen eindeutig sein; '
                . 'Si und A/W sind reserviert. Eine belegte Position muss als Rotkopie markiert sein.';
        } else {
            try {
                $connection = estab_auth_connect($conf_4f_db);
                try {
                    estab_admin_replace_matrix(
                        $connection,
                        $conf_4f_tbl['empfmtx'],
                        $conf_4f_tbl['protokoll'],
                        $submitted['data']
                    );
                } finally {
                    estab_auth_close($connection);
                }
                header('Location: make_fkt.php?updated=1', true, 303);
                exit;
            } catch (Throwable $exception) {
                error_log('eStab recipient matrix update failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Die Empfängermatrix konnte nicht atomar gespeichert werden.';
            }
        }
    }
}

try {
    if (is_array($submitted) && !$submitted['valid']) {
        $matrix = $submitted['data'];
    } else {
        $connection = estab_auth_connect($conf_4f_db);
        try {
            $matrix = estab_admin_fetch_matrix($connection, $conf_4f_tbl['empfmtx']);
        } finally {
            estab_auth_close($connection);
        }
    }
} catch (Throwable $exception) {
    error_log('eStab recipient matrix lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    $error = 'Die Empfängermatrix konnte nicht vollständig gelesen werden.';
    $matrix = ['cells' => [], 'redcopy' => ''];
}

$updated = ($_GET['updated'] ?? '') === '1';

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Empfängermatrix</title>
  <style>
    body { font: 16px/1.45 system-ui, sans-serif; max-width: 92rem; margin: 2rem auto; padding: 0 1rem; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #999; padding: .45rem; vertical-align: top; }
    th { background: #eee; }
    input[type="text"] { width: 7em; }
    .error { color: #8b0000; font-weight: bold; }
    .success { color: #075f23; font-weight: bold; }
    .actions { margin-top: 1rem; }
    fieldset { border: 0; padding: 0; margin: 0; min-width: 12rem; }
  </style>
</head>
<body>
  <h1>Empfängermatrix bearbeiten</h1>
  <p>Die Laufzeit liest diese Matrix direkt aus MariaDB. Das Speichern ersetzt
    alle 20 Positionen in einer Transaktion; angemeldete Benutzer und deren
    aktuelle Funktionszuordnung werden dabei nicht verändert.</p>
  <p>Funktionsnamen bestehen aus höchstens sechs Buchstaben, Ziffern oder
    Unterstrichen. <code>Si</code> und <code>A/W</code> sind reserviert.
    Genau eine belegte Position muss Rotkopie-Empfänger sein.</p>

  <?php if ($updated): ?>
    <p class="success">Die Empfängermatrix wurde vollständig gespeichert.</p>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <p class="error"><?= estab_admin_html($error) ?></p>
  <?php endif; ?>

  <?php if (count($matrix['cells']) === 20): ?>
  <form method="post" action="make_fkt.php">
    <?= estab_csrf_field() ?>
    <input type="hidden" name="admin_action" value="save_matrix">
    <table>
      <thead>
        <tr>
          <th>Position</th>
          <th>Funktion</th>
          <th>Rolle</th>
          <th>Autosichtung</th>
          <th>Rotkopie</th>
        </tr>
      </thead>
      <tbody>
      <?php for ($row = 1; $row <= ESTAB_ADMIN_MATRIX_ROWS; $row++): ?>
        <?php for ($column = 1; $column <= ESTAB_ADMIN_MATRIX_COLUMNS; $column++): ?>
          <?php
            $position = (string) $row . (string) $column;
            $cell = $matrix['cells'][$position];
          ?>
          <tr>
            <th scope="row"><?= $row ?>.<?= $column ?></th>
            <td>
              <label>
                <span class="visually-hidden">Funktion <?= $row ?>.<?= $column ?></span>
                <input
                  type="text"
                  name="pos_<?= $position ?>"
                  maxlength="6"
                  pattern="[A-Za-z0-9_]{0,6}"
                  value="<?= estab_admin_html($cell['function']) ?>">
              </label>
            </td>
            <td>
              <fieldset>
                <legend>Rolle <?= $row ?>.<?= $column ?></legend>
                <?php foreach (['' => 'leer', 'Stab' => 'Stab', 'FB' => 'Fachberater'] as $value => $label): ?>
                  <label>
                    <input
                      type="radio"
                      name="rolle_<?= $position ?>"
                      value="<?= estab_admin_html($value) ?>"
                      <?= $cell['role'] === $value ? 'checked' : '' ?>>
                    <?= estab_admin_html($label) ?>
                  </label>
                <?php endforeach; ?>
              </fieldset>
            </td>
            <td>
              <label>
                <input
                  type="checkbox"
                  name="stasi_<?= $position ?>"
                  value="1"
                  <?= !empty($cell['auto']) ? 'checked' : '' ?>>
                automatisch
              </label>
            </td>
            <td>
              <label>
                <input
                  type="radio"
                  name="lagerot"
                  value="<?= $position ?>"
                  <?= !empty($cell['redcopy']) ? 'checked' : '' ?>
                  required>
                Rotkopie
              </label>
            </td>
          </tr>
        <?php endfor; ?>
      <?php endfor; ?>
      </tbody>
    </table>
    <p class="actions">
      <button type="submit">Matrix atomar speichern</button>
      <a href="admin.php">Abbrechen</a>
    </p>
  </form>
  <?php endif; ?>
</body>
</html>
