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
require_once __DIR__ . '/../app/session_ui.php';

function export_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function export_plain_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    echo $message;
    exit;
}

function export_query_run_id(array $query, string $key): ?string
{
    if (!array_key_exists($key, $query)) {
        return null;
    }
    try {
        return estab_export_validate_run_id($query[$key]);
    } catch (InvalidArgumentException) {
        return null;
    }
}

function export_datetime(string $value): string
{
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('d.m.Y H:i:s');
    } catch (Throwable) {
        return 'Zeitpunkt unbekannt';
    }
}

function export_bytes(int $bytes): string
{
    $units = ['Byte', 'KiB', 'MiB', 'GiB', 'TiB'];
    $value = (float) max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    $decimals = $unit === 0 ? 0 : 1;
    return number_format($value, $decimals, ',', '.') . ' ' . $units[$unit];
}

function export_admin_log_identity(array $server): string
{
    $identity = $server['REMOTE_USER'] ?? null;
    if (
        !is_string($identity)
        || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $identity) !== 1
    ) {
        return 'unknown';
    }
    return $identity;
}

$exportDirectory = estab_env(
    'ESTAB_EXPORT_DIR',
    '/var/lib/estab/export'
) ?? '/var/lib/estab/export';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$getAction = $_GET['action'] ?? null;

if ($getAction !== null) {
    if (!is_string($getAction) || $getAction !== 'download') {
        export_plain_error(400, 'Ungültige Exportanforderung.');
    }
    if ($requestMethod !== 'GET') {
        header('Allow: GET');
        export_plain_error(405, 'Diese Exportanforderung unterstützt nur GET.');
    }

    try {
        $download = estab_export_open_archive(
            $exportDirectory,
            $_GET['export_id'] ?? null
        );
    } catch (InvalidArgumentException) {
        export_plain_error(400, 'Ungültige Exportkennung.');
    } catch (EstabExportNotFoundException) {
        export_plain_error(404, 'Der angeforderte Export wurde nicht gefunden.');
    } catch (Throwable $exception) {
        error_log('eStab export download failed: ' . $exception->getMessage());
        export_plain_error(500, 'Der Export konnte nicht geöffnet werden.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $download['filename'] . '"');
    header('Content-Length: ' . (string) $download['size']);
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Accept-Ranges: none');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    $streamed = fpassthru($download['handle']);
    fclose($download['handle']);
    if ($streamed === false) {
        error_log('eStab export download stream failed: ' . $download['filename']);
    }
    exit;
}

estab_session_ui_start($_SESSION);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$exportFlash = $_SESSION['estab_export_flash'] ?? null;
unset($_SESSION['estab_export_flash']);
$adminLogIdentity = export_admin_log_identity($_SERVER);
$error = null;
if ($requestMethod === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
    } catch (Throwable) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. Bitte laden Sie die Seite neu.';
    }

    $adminAction = $_POST['admin_action'] ?? null;
    if ($error === null && !is_string($adminAction)) {
        http_response_code(422);
        $error = 'Unbekannte administrative Aktion.';
    }

    if ($error === null && $adminAction === 'create_export') {
        try {
            $connection = estab_auth_connect($conf_4f_db);
            try {
                $created = estab_export_database($connection, $exportDirectory);
            } finally {
                estab_auth_close($connection);
            }
            $runId = estab_export_validate_run_id(basename($created['directory']));
            $_SESSION['estab_export_flash'] = [
                'type' => 'created',
                'id' => $runId,
            ];
            error_log(
                'eStab export created: ' . $runId
                . ' admin=' . $adminLogIdentity
            );
            header(
                'Location: export.php?created=' . rawurlencode($runId),
                true,
                303
            );
            exit;
        } catch (Throwable $exception) {
            error_log('eStab export creation failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Der Einsatzexport konnte nicht vollständig erstellt werden. Details stehen im Container-Log.';
        }
    } elseif ($error === null && $adminAction === 'delete_export') {
        try {
            $deleted = estab_export_delete_run(
                $exportDirectory,
                $_POST['export_id'] ?? null
            );
            $_SESSION['estab_export_flash'] = [
                'type' => 'deleted',
                'id' => $deleted['id'],
            ];
            error_log(
                'eStab export deleted: ' . $deleted['id']
                . ' admin=' . $adminLogIdentity
            );
            header(
                'Location: export.php?deleted=' . rawurlencode($deleted['id']),
                true,
                303
            );
            exit;
        } catch (InvalidArgumentException) {
            http_response_code(422);
            $error = 'Die übermittelte Exportkennung ist ungültig.';
        } catch (EstabExportNotFoundException) {
            http_response_code(404);
            $error = 'Der ausgewählte Export ist nicht mehr vorhanden.';
        } catch (EstabExportUnsafePathException $exception) {
            error_log('eStab unsafe export deletion refused: ' . $exception->getMessage());
            http_response_code(409);
            $error = 'Der Export enthält einen unerwarteten Dateiaufbau und wurde deshalb nicht gelöscht.';
        } catch (Throwable $exception) {
            error_log('eStab export deletion failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'Der Export konnte nicht vollständig gelöscht werden. Details stehen im Container-Log.';
        }
    } elseif ($error === null) {
        http_response_code(422);
        $error = 'Unbekannte administrative Aktion.';
    }
} elseif ($requestMethod !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $error = 'Diese Seite unterstützt nur GET und POST.';
}

$runs = [];
try {
    $runs = estab_export_list_runs($exportDirectory);
} catch (Throwable $exception) {
    error_log('eStab export listing failed: ' . $exception->getMessage());
    if ($error === null) {
        http_response_code(500);
        $error = 'Die vorhandenen Exporte konnten nicht gelesen werden.';
    }
}

$createdId = null;
$deletedId = null;
if (
    $requestMethod === 'GET'
    && is_array($exportFlash)
    && is_string($exportFlash['type'] ?? null)
) {
    try {
        $flashId = estab_export_validate_run_id($exportFlash['id'] ?? null);
        $queryKey = $exportFlash['type'] === 'created'
            ? 'created'
            : ($exportFlash['type'] === 'deleted' ? 'deleted' : null);
        if (
            $queryKey !== null
            && export_query_run_id($_GET, $queryKey) === $flashId
        ) {
            if ($queryKey === 'created') {
                $createdId = $flashId;
            } else {
                $deletedId = $flashId;
            }
        }
    } catch (InvalidArgumentException) {
        // Invalid session state is ignored and has already been consumed.
    }
}
$listedIds = array_column($runs, 'id');
$createdVisible = $createdId !== null && in_array($createdId, $listedIds, true);

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Einsatzexporte</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-export-page">
  <main class="estab-export-main">
    <header class="estab-export-hero">
      <p class="estab-export-eyebrow">Administration · Datenaustausch</p>
      <h1>Einsatzexporte</h1>
      <p>Jeder Export enthält alle Basistabellen als UTF-8-CSV, ein Manifest
        mit Datensatzanzahlen und SHA-256-Prüfsummen sowie ein ZIP-Archiv.
        Bereits erzeugte Exporte bleiben im persistenten Export-Speicher,
        bis Sie sie hier ausdrücklich löschen.</p>
    </header>

    <?php if ($error !== null): ?>
      <p class="estab-export-alert estab-export-alert-error" role="alert">
        <?= export_html($error) ?>
      </p>
    <?php elseif ($createdVisible): ?>
      <p class="estab-export-alert estab-export-alert-success" role="status">
        Der neue Export wurde vollständig erstellt und steht unten zum
        Download bereit.
      </p>
    <?php elseif ($deletedId !== null): ?>
      <p class="estab-export-alert estab-export-alert-success" role="status">
        Der ausgewählte Export wurde vollständig gelöscht.
      </p>
    <?php endif; ?>

    <section class="estab-export-create" aria-labelledby="export-create-title">
      <div>
        <h2 id="export-create-title">Neuen Export erstellen</h2>
        <p>Die Erstellung liest die aktuelle Datenbank und veröffentlicht das
          Archiv erst, wenn CSV-Dateien, Manifest und ZIP vollständig
          geschrieben wurden.</p>
      </div>
      <form method="post" data-estab-export-create data-estab-dirty-guard>
        <?= estab_csrf_field() ?>
        <input type="hidden" name="admin_action" value="create_export">
        <button class="estab-button estab-button-primary" type="submit">
          Vollständigen Export erstellen
        </button>
      </form>
    </section>

    <section class="estab-export-overview" aria-labelledby="export-list-title">
      <div class="estab-export-section-heading">
        <div>
          <p class="estab-export-eyebrow">Persistenter Speicher</p>
          <h2 id="export-list-title">Vorhandene Exporte</h2>
        </div>
        <?php $exportCount = count($runs); ?>
        <span
          class="estab-export-count"
          aria-label="<?= $exportCount === 1 ? '1 vorhandener Export' : $exportCount . ' vorhandene Exporte' ?>">
          <?= $exportCount ?>
        </span>
      </div>

      <?php if ($runs === []): ?>
        <div class="estab-export-empty" data-estab-export-list>
          <strong>Noch keine Exporte vorhanden.</strong>
          <span>Erstellen Sie oben den ersten vollständigen Einsatzexport.</span>
        </div>
      <?php else: ?>
        <div class="estab-export-list" data-estab-export-list>
          <?php foreach ($runs as $run): ?>
            <?php
              $manifest = is_array($run['manifest']) ? $run['manifest'] : null;
              $isCreated = $createdId === $run['id'];
              $displayDate = export_datetime($run['created_at']);
              $accessibleRunCode = substr($run['id'], -8);
            ?>
            <article
              class="estab-export-card<?= $isCreated ? ' estab-export-card-new' : '' ?>"
              data-estab-export-id="<?= export_html($run['id']) ?>">
              <header class="estab-export-card-header">
                <div>
                  <p class="estab-export-card-kicker">Einsatzexport</p>
                  <h3>
                    <time datetime="<?= export_html($run['created_at']) ?>">
                      <?= export_html($displayDate) ?>
                    </time>
                  </h3>
                </div>
                <?php
                  $stateWarning = !$run['safe_to_delete'] || $manifest === null;
                  $stateLabel = !$run['safe_to_delete']
                    ? 'Prüfung nötig'
                    : ($manifest === null ? 'Manifest nicht lesbar' : 'Manifest lesbar');
                ?>
                <span class="estab-export-state<?= $stateWarning ? ' estab-export-state-warning' : '' ?>">
                  <?= export_html($stateLabel) ?>
                </span>
              </header>

              <dl class="estab-export-metadata">
                <div>
                  <dt>Archiv</dt>
                  <dd><code><?= export_html($run['archive']) ?></code></dd>
                </div>
                <div>
                  <dt>Größe</dt>
                  <dd><?= export_html(export_bytes((int) $run['archive_size'])) ?></dd>
                </div>
                <?php if ($manifest !== null): ?>
                  <div>
                    <dt>Datenbank</dt>
                    <dd><code><?= export_html($manifest['database']) ?></code></dd>
                  </div>
                  <div>
                    <dt>Tabellen</dt>
                    <dd><?= (int) $manifest['table_count'] ?></dd>
                  </div>
                  <div>
                    <dt>Datensätze</dt>
                    <dd><?= export_html(number_format((int) $manifest['rows'], 0, ',', '.')) ?></dd>
                  </div>
                <?php endif; ?>
              </dl>

              <?php if ($manifest === null): ?>
                <p class="estab-export-card-note">
                  Das ZIP kann heruntergeladen werden; Manifestdetails sind
                  für diesen älteren oder unvollständigen Lauf nicht lesbar.
                </p>
              <?php endif; ?>

              <div class="estab-export-actions">
                <a
                  class="estab-button estab-button-primary"
                  href="export.php?action=download&amp;export_id=<?= rawurlencode($run['id']) ?>"
                  aria-label="ZIP-Export vom <?= export_html($displayDate) ?>, Kennung <?= export_html($accessibleRunCode) ?>, herunterladen">
                  ZIP herunterladen
                </a>

                <?php if ($run['safe_to_delete']): ?>
                  <details class="estab-export-delete-confirm">
                    <summary class="estab-button estab-button-danger-outline">
                      <span class="estab-export-delete-label-open">Export löschen …</span>
                      <span class="estab-export-delete-label-close">Abbrechen</span>
                      <span class="estab-visually-hidden">
                        – Export vom <?= export_html($displayDate) ?>,
                        Kennung <?= export_html($accessibleRunCode) ?>
                      </span>
                    </summary>
                    <div class="estab-export-delete-panel">
                      <strong>Export endgültig löschen?</strong>
                      <p>Das Archiv vom <?= export_html($displayDate) ?> wird
                        zusammen mit seinen CSV-Dateien entfernt. Dieser
                        Vorgang kann nicht rückgängig gemacht werden.</p>
                      <form method="post" data-estab-export-delete>
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="admin_action" value="delete_export">
                        <input type="hidden" name="export_id" value="<?= export_html($run['id']) ?>">
                        <button
                          class="estab-button estab-button-danger"
                          type="submit"
                          aria-label="Export vom <?= export_html($displayDate) ?>, Kennung <?= export_html($accessibleRunCode) ?>, endgültig löschen">
                          Ja, endgültig löschen
                        </button>
                      </form>
                    </div>
                  </details>
                <?php else: ?>
                  <p class="estab-export-card-note estab-export-card-note-warning">
                    Dieser Lauf enthält einen unerwarteten Dateiaufbau und
                    kann deshalb nicht sicher über die Oberfläche gelöscht
                    werden.
                  </p>
                <?php endif; ?>
              </div>

              <?php if ($manifest !== null): ?>
                <details
                  class="estab-export-manifest"
                  <?= $isCreated ? 'open' : '' ?>>
                  <summary>
                    Inhalt und Prüfsummen anzeigen
                    <span class="estab-visually-hidden">
                      – Export vom <?= export_html($displayDate) ?>,
                      Kennung <?= export_html($accessibleRunCode) ?>
                    </span>
                  </summary>
                  <ul class="estab-export-manifest-list">
                    <?php foreach ($manifest['tables'] as $table): ?>
                      <li>
                        <strong><?= export_html($table['table']) ?></strong>
                        <span><?= export_html(number_format((int) $table['rows'], 0, ',', '.')) ?> Datensätze</span>
                        <code><?= export_html($table['sha256']) ?></code>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <p class="estab-export-back">
      <a href="admin.php">Zurück zu den administrativen Maßnahmen</a>
    </p>
  </main>
</body>
</html>
