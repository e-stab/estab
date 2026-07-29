<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);

$error = null;
$submitted = null;
$loadedStandard = false;
$standardMatrixTable = $conf_4f_tbl['empfmtx'] . '_standard';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
    } catch (Throwable) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen.';
    }

    $actionValue = $_POST['admin_action'] ?? null;
    $action = is_string($actionValue) ? $actionValue : '';
    if ($error === null) {
        if (!in_array(
            $action,
            ['load_standard', 'save_matrix', 'save_matrix_and_standard'],
            true
        )) {
            http_response_code(422);
            $error = 'Unbekannte administrative Aktion.';
        }
    }

    if ($error === null && $action === 'load_standard') {
        try {
            $connection = estab_auth_connect($conf_4f_db);
            try {
                $submitted = [
                    'valid' => true,
                    'errors' => [],
                    'data' => estab_admin_fetch_matrix($connection, $standardMatrixTable),
                ];
            } finally {
                estab_auth_close($connection);
            }
            $loadedStandard = true;
        } catch (Throwable $exception) {
            error_log('eStab standard recipient matrix lookup failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Die Standardmatrix konnte nicht vollständig gelesen werden.';
        }
    }

    if (
        $error === null
        && in_array($action, ['save_matrix', 'save_matrix_and_standard'], true)
    ) {
        $submitted = estab_admin_validate_matrix($_POST);
        if (!$submitted['valid']) {
            http_response_code(422);
            $error = 'Die Matrix ist ungültig. Funktionen müssen eindeutig sein; '
                . 'Si, A/W und LdF sind reserviert. Eine belegte Position muss als Rotkopie markiert sein.';
        } else {
            try {
                $connection = estab_auth_connect($conf_4f_db);
                try {
                    if ($action === 'save_matrix_and_standard') {
                        estab_admin_replace_matrix_and_standard(
                            $connection,
                            (string) $conf_4f_db['datenbank'],
                            $conf_4f_tbl['empfmtx'],
                            $standardMatrixTable,
                            $conf_4f_tbl['benutzer'],
                            $conf_4f_tbl['protokoll'],
                            $submitted['data'],
                            is_string($_SERVER['REMOTE_USER'] ?? null)
                                ? $_SERVER['REMOTE_USER']
                                : 'unknown',
                            estab_auth_remote_ip($_SERVER)
                        );
                    } else {
                        estab_admin_replace_matrix(
                            $connection,
                            (string) $conf_4f_db['datenbank'],
                            $conf_4f_tbl['empfmtx'],
                            $conf_4f_tbl['benutzer'],
                            $conf_4f_tbl['protokoll'],
                            $submitted['data'],
                            is_string($_SERVER['REMOTE_USER'] ?? null)
                                ? $_SERVER['REMOTE_USER']
                                : 'unknown',
                            estab_auth_remote_ip($_SERVER)
                        );
                    }
                } finally {
                    estab_auth_close($connection);
                }
                $updatedValue = $action === 'save_matrix_and_standard'
                    ? 'active-and-standard'
                    : 'active';
                header('Location: make_fkt.php?updated=' . $updatedValue, true, 303);
                exit;
            } catch (EstabAssignmentBusyException $exception) {
                http_response_code(409);
                $error = 'Die Funktionszuordnungen werden gerade von einer '
                    . 'anderen administrativen Aktion geändert. Bitte '
                    . 'versuchen Sie es erneut.';
            } catch (Throwable $exception) {
                error_log('eStab recipient matrix update failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = $action === 'save_matrix_and_standard'
                    ? 'Aktive Empfängermatrix und Standardmatrix konnten nicht atomar gespeichert werden.'
                    : 'Die aktive Empfängermatrix konnte nicht atomar gespeichert werden.';
            }
        }
    }
}

try {
    if (is_array($submitted)) {
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

$updatedValue = $_GET['updated'] ?? '';
$updated = is_string($updatedValue) ? $updatedValue : '';

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Empfängermatrix</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main
    class="estab-tool-main estab-tool-main-wide"
    data-estab-matrix-tool>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Administration · Konfiguration</p>
      <h1>Empfängermatrix bearbeiten</h1>
      <p>Die aktive Matrix steuert Funktionen, Rollen, Autosichtung und
        Rotkopie. Geänderte Rollen werden auf zugewiesene Konten übertragen;
        entfernte Funktionen bleiben zur administrativen Neuzuweisung sichtbar.
        Betroffene Sitzungen werden beim Speichern beendet.</p>
    </header>

    <aside
      class="estab-tool-notice estab-tool-notice-warning"
      id="matrix-impact"
      aria-label="Auswirkung der Matrixänderung">
      <strong>Vor dem Speichern prüfen:</strong>
      <p>Funktionsnamen bestehen aus höchstens sechs Buchstaben, Ziffern oder
        Unterstrichen. <code>Si</code>, <code>A/W</code> und
        <code>LdF</code> sind reserviert.
        Genau eine belegte Position muss Rotkopie-Empfänger sein.</p>
      <p>„Standard laden“ verwirft die aktuellen Editorwerte.
        „Standard ersetzen“ überschreibt die einzige gespeicherte Vorlage;
        der vorherige Stand bleibt dann nur in einem Datenbankbackup erhalten.
        Beide Aktionen verlangen deshalb eine ausdrückliche Bestätigung.</p>
      <p>Wenn Sie eine aktive Funktion entfernen oder ihre Rolle ändern,
        werden alle Sitzungen der betroffenen Konten widerrufen. Konten mit
        entfernter Funktion können sich erst nach einer Neuzuweisung in der
        Benutzerverwaltung wieder anmelden.</p>
    </aside>

    <?php if ($updated === 'active'): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        Die aktive Empfängermatrix wurde vollständig gespeichert.
      </p>
    <?php elseif ($updated === 'active-and-standard'): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        Aktive Empfängermatrix und Standardmatrix wurden gemeinsam gespeichert.
      </p>
    <?php endif; ?>
    <?php if ($loadedStandard): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        Die Standardmatrix wurde in den Editor geladen, aber noch nicht gespeichert.
      </p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= estab_admin_html($error) ?>
      </p>
    <?php endif; ?>

    <?php if (count($matrix['cells']) === 20): ?>
      <section class="estab-tool-panel" aria-labelledby="matrix-editor-title">
        <header class="estab-tool-panel-heading">
          <h2 id="matrix-editor-title">Aktive Belegung</h2>
          <p>Jede Position kann leer bleiben oder genau einer Funktion und
            Rolle zugeordnet werden.</p>
        </header>
        <form
          class="estab-tool-form"
          method="post"
          action="make_fkt.php"
          data-estab-dirty-guard
          aria-describedby="matrix-impact"
          <?= ($error !== null && is_array($submitted)) || $loadedStandard
              ? 'data-estab-dirty-initial'
              : '' ?>>
          <?= estab_csrf_field() ?>
          <div
            class="estab-tool-table-wrap estab-tool-table-responsive"
            data-estab-matrix-table>
            <table class="estab-tool-table estab-tool-matrix-table">
              <caption class="estab-visually-hidden">
                Empfängermatrix mit zwanzig konfigurierbaren Positionen
              </caption>
              <thead>
                <tr>
                  <th scope="col">Position</th>
                  <th scope="col">Funktion</th>
                  <th scope="col">Rolle</th>
                  <th scope="col">Autosichtung</th>
                  <th scope="col">Rotkopie</th>
                </tr>
              </thead>
              <tbody>
              <?php for ($row = 1; $row <= ESTAB_ADMIN_MATRIX_ROWS; $row++): ?>
                <?php for ($column = 1; $column <= ESTAB_ADMIN_MATRIX_COLUMNS; $column++): ?>
                  <?php
                    $position = (string) $row . (string) $column;
                    $cell = $matrix['cells'][$position];
                    $positionLabel = $row . '.' . $column;
                  ?>
                  <tr>
                    <td data-label="Position">
                      <strong><?= $positionLabel ?></strong>
                    </td>
                    <td data-label="Funktion">
                      <div class="estab-tool-field">
                        <label
                          class="estab-visually-hidden"
                          for="matrix-function-<?= $position ?>">
                          Funktion Position <?= $positionLabel ?>
                        </label>
                        <input
                          id="matrix-function-<?= $position ?>"
                          type="text"
                          name="pos_<?= $position ?>"
                          maxlength="6"
                          pattern="[A-Za-z0-9_]{0,6}"
                          autocomplete="off"
                          value="<?= estab_admin_html($cell['function']) ?>">
                      </div>
                    </td>
                    <td data-label="Rolle">
                      <fieldset class="estab-tool-matrix-options">
                        <legend class="estab-visually-hidden">
                          Rolle Position <?= $positionLabel ?>
                        </legend>
                        <?php foreach (['' => 'leer', 'Stab' => 'Stab', 'FB' => 'Fachberater'] as $value => $label): ?>
                          <label class="estab-tool-check">
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
                    <td data-label="Autosichtung">
                      <label class="estab-tool-check">
                        <input
                          type="checkbox"
                          name="stasi_<?= $position ?>"
                          value="1"
                          <?= !empty($cell['auto']) ? 'checked' : '' ?>>
                        automatisch
                      </label>
                    </td>
                    <td data-label="Rotkopie">
                      <label class="estab-tool-check">
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
          </div>
          <div class="estab-tool-actions">
            <button
              class="estab-button estab-button-primary"
              type="submit"
              name="admin_action"
              value="save_matrix">
              Nur aktive Matrix speichern
            </button>
            <button
              class="estab-button"
              type="submit"
              name="admin_action"
              value="load_standard"
              formnovalidate
              data-estab-confirm="replace-editor-with-standard">
              Ungespeicherte Eingaben verwerfen und Standard laden
            </button>
            <button
              class="estab-button estab-button-danger-outline"
              type="submit"
              name="admin_action"
              value="save_matrix_and_standard"
              data-estab-confirm="replace-standard">
              Aktive Matrix speichern und bisherigen Standard ersetzen
            </button>
            <a class="estab-button" href="admin.php">Abbrechen</a>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zur Administration</a>
      <span>Änderungen werden vollständig und atomar gespeichert.</span>
    </footer>
  </main>
</body>
</html>
