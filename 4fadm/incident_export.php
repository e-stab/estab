<?php

declare(strict_types=1);

session_start();

if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Administrative authentication required.');
}

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/incident_export.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function incident_export_html(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function incident_export_actor(array $server): string
{
    return estab_incident_actor($server['REMOTE_USER'] ?? null);
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$error = null;
$incidents = [];
$logbookShiftOptions = [];
$selectedIncidentId = null;
$selectedLogbookScope = 'all';
$selectedSections = [
    'etb',
    'ttb',
    'messages',
    'attachments',
    'message_evidence',
    'duty',
    's6_plans',
    'courier',
    'operations_evidence',
];
$attachmentByteLimit = estab_env_integer(
    'ESTAB_PDF_ATTACHMENT_MAX_BYTES',
    ESTAB_INCIDENT_PDF_DEFAULT_ATTACHMENT_BYTES,
    0,
    ESTAB_INCIDENT_PDF_MAX_ATTACHMENT_BYTES
);

if ($requestMethod === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $selectedIncidentId = estab_incident_positive_id(
            $_POST['einsatz_id'] ?? null
        );
        $selectedSections = estab_incident_export_sections($_POST);
        $parsedLogbookScope = estab_incident_export_logbook_scope(
            $_POST['logbook_scope'] ?? 'all'
        );
        $selectedLogbookScope = $parsedLogbookScope['mode'] === 'shift'
            ? 'shift:' . (string) $parsedLogbookScope['shift_id']
            : 'all';
        $actor = incident_export_actor($_SERVER);

        $connection = estab_auth_connect($conf_4f_db);
        try {
            $snapshotActive = false;
            if (
                !$connection->begin_transaction(
                    MYSQLI_TRANS_START_READ_ONLY
                        | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT
                )
            ) {
                throw new RuntimeException(
                    'Der konsistente Exportstand konnte nicht begonnen werden.'
                );
            }
            $snapshotActive = true;
            try {
                $bundle = estab_incident_export_load(
                    $connection,
                    $selectedIncidentId,
                    $selectedSections,
                    (string) $conf_4f['ablage_dir'],
                    $selectedLogbookScope
                );
                $generatedAt = new DateTimeImmutable('now');
                $rendered = estab_incident_export_pdf(
                    $bundle,
                    $actor,
                    $attachmentByteLimit,
                    $generatedAt
                );
                $filename = estab_incident_export_filename(
                    $bundle['incident'],
                    $generatedAt
                );
                if (!$connection->commit()) {
                    throw new RuntimeException(
                        'Der konsistente Exportstand konnte nicht abgeschlossen '
                            . 'werden.'
                    );
                }
                $snapshotActive = false;
            } catch (Throwable $exception) {
                if ($snapshotActive) {
                    $connection->rollback();
                    $snapshotActive = false;
                }
                throw $exception;
            }

            if (!$connection->begin_transaction()) {
                throw new RuntimeException(
                    'Das Exportprotokoll konnte nicht begonnen werden.'
                );
            }
            try {
                estab_incident_audit(
                    $connection,
                    $selectedIncidentId,
                    'pdf_export',
                    $actor,
                    null,
                    [
                        'sections' => $selectedSections,
                        'logbook_scope' => $bundle['logbook_scope'],
                        'counts' => $bundle['counts'],
                        'pdf_bytes' => strlen($rendered['bytes']),
                        'attachment_bytes' =>
                            $rendered['attachment_bytes'],
                        'attachment_visible_count' =>
                            $rendered['attachment_visible_count'],
                        'attachment_visible_pages' =>
                            $rendered['attachment_visible_pages'],
                        'attachment_rendered_count' =>
                            $rendered['attachment_rendered_count'],
                        'attachment_rendered_pages' =>
                            $rendered['attachment_rendered_pages'],
                        'attachment_information_pages' =>
                            $rendered['attachment_information_pages'],
                        'sha256' => $rendered['sha256'],
                    ]
                );
                if (!$connection->commit()) {
                    throw new RuntimeException(
                        'Das Exportprotokoll konnte nicht gespeichert werden.'
                    );
                }
            } catch (Throwable $exception) {
                $connection->rollback();
                throw $exception;
            }
        } finally {
            estab_auth_close($connection);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Content-Type: application/pdf');
        header(
            'Content-Disposition: '
                . estab_file_content_disposition($filename, false)
        );
        header('Content-Length: ' . strlen($rendered['bytes']));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Accept-Ranges: none');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo $rendered['bytes'];
        exit;
    } catch (EstabCsrfException) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    } catch (EstabIncidentExportInputException | EstabIncidentInputException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (EstabIncidentNotFoundException $exception) {
        http_response_code(404);
        $error = $exception->getMessage();
    } catch (EstabIncidentExportDataException | EstabIncidentPdfInputException $exception) {
        error_log(
            'eStab incident PDF export refused: ' . $exception->getMessage()
        );
        http_response_code(409);
        $error = 'Das Einsatzdossier konnte nicht vollständig erstellt werden: '
            . $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('eStab incident PDF export failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Das Einsatzdossier konnte nicht erstellt werden. '
            . 'Details stehen im Container-Log.';
    }
} elseif ($requestMethod !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $error = 'Diese Seite unterstützt nur GET und POST.';
}

try {
    $connection = estab_auth_connect($conf_4f_db);
    try {
        $incidents = estab_incident_list($connection);
        $logbookShiftOptions = estab_incident_export_shift_options(
            $connection
        );
    } finally {
        estab_auth_close($connection);
    }
} catch (Throwable $exception) {
    error_log('eStab incident PDF overview failed: ' . $exception->getMessage());
    if ($error === null) {
        http_response_code(503);
        $error = 'Die Einsatzliste ist derzeit nicht verfügbar.';
    }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab PDF-Einsatzdossier</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page estab-export-page">
  <main
    class="estab-tool-main estab-tool-main-wide
      estab-export-main estab-incident-export-main"
    data-estab-incident-export>
    <header class="estab-tool-hero estab-export-hero">
      <p class="estab-tool-eyebrow estab-export-eyebrow">
        Administration · Einsatzdokumentation
      </p>
      <h1>PDF-Einsatzdossier</h1>
      <p>Erstellen Sie für einen bestimmten Einsatz ein revisionsfähiges,
        durchsuchbares DV-1-101-Dossier. Der Standardumfang enthält alle
        Tagebücher, Nachrichtenvordrucke und Nachweisketten sowie
        Dienstorganisation, S6-Planung, Melderaufträge und Anlagen. Bilder,
        Textdateien und PDF-Seiten werden im Dossier direkt sichtbar
        dargestellt; die bytegleichen Originaldateien bleiben zusätzlich
        eingebettet.</p>
    </header>

    <?php if ($error !== null): ?>
      <p
        class="estab-tool-feedback estab-tool-feedback-error
          estab-export-alert estab-export-alert-error"
        role="alert">
        <?= incident_export_html($error) ?>
      </p>
    <?php endif; ?>

    <section class="estab-export-create estab-incident-export-panel">
      <div>
        <h2>Umfang festlegen</h2>
        <p>Der Export verändert den gewählten Einsatz nicht. Auch abgeschlossene
          Einsätze können ausgewählt werden. Die Erzeugung wird mit Umfang und
          PDF-Prüfsumme im Einsatzprotokoll festgehalten.</p>
      </div>

      <?php if ($incidents === []): ?>
        <div class="estab-export-empty">
          <strong>Noch kein Einsatz vorhanden</strong>
          <span>Legen Sie zuerst einen Einsatz an.</span>
          <a class="estab-button estab-button-primary" href="incidents.php">
            Zur Einsatzverwaltung
          </a>
        </div>
      <?php else: ?>
        <form
          class="estab-incident-export-form"
          method="post"
          action="incident_export.php">
          <?= estab_csrf_field() ?>

          <label class="estab-incident-export-field">
            <span>Einsatz</span>
            <select name="einsatz_id" id="incident-export-incident" required>
              <option value="">Bitte auswählen</option>
              <?php foreach ($incidents as $incident): ?>
                <?php $incidentId = (int) ($incident['einsatz_id'] ?? 0); ?>
                <option
                  value="<?= $incidentId ?>"
                  <?= $selectedIncidentId === $incidentId ? 'selected' : '' ?>>
                  <?= incident_export_html(
                      (string) ($incident['kennung'] ?? '')
                          . ' · ' . (string) ($incident['name'] ?? '')
                          . ' · Führungsstelle: '
                          . (string) (
                              $incident['fuehrungsstellenname']
                                  ?? 'historisch nicht erfasst'
                          )
                          . ' · Berechtigungsmodus: '
                          . estab_permission_mode_label(
                              $incident['estab_permission_mode'] ?? null
                          )
                          . (($incident['ist_aktiv'] ?? false)
                              ? ' (aktiv)'
                              : '')
                  ) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="estab-incident-export-field">
            <span>ETB/TBB-Ausgabe</span>
            <select
              name="logbook_scope"
              id="incident-export-logbook-scope"
              required>
              <option value="all"
                <?= $selectedLogbookScope === 'all' ? 'selected' : '' ?>>
                Gesamtbuch · alle Dienstschichten
              </option>
              <?php foreach ($incidents as $incident): ?>
                <?php
                $scopeIncidentId = (int) ($incident['einsatz_id'] ?? 0);
                $incidentShifts = array_values(array_filter(
                    $logbookShiftOptions,
                    static fn (array $shift): bool =>
                        (int) ($shift['einsatz_id'] ?? 0) === $scopeIncidentId
                ));
                ?>
                <?php if ($incidentShifts !== []): ?>
                  <optgroup
                    label="<?= incident_export_html(
                        (string) ($incident['kennung'] ?? '')
                            . ' · ' . (string) ($incident['name'] ?? '')
                    ) ?>">
                    <?php foreach ($incidentShifts as $shift): ?>
                      <?php
                      $shiftId = (int) ($shift['dienstschicht_id'] ?? 0);
                      $shiftValue = 'shift:' . $shiftId;
                      ?>
                      <option
                        value="<?= incident_export_html($shiftValue) ?>"
                        data-incident-id="<?= $scopeIncidentId ?>"
                        <?= $selectedLogbookScope === $shiftValue
                            ? 'selected'
                            : '' ?>>
                        <?= incident_export_html(
                            'Dienstschicht '
                                . (string) ($shift['nummer'] ?? '')
                                . ' · '
                                . (string) ($shift['bezeichnung'] ?? '')
                                . ' (' . (string) ($shift['status'] ?? '') . ')'
                        ) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
            <small>Eine einzelne Dienstschicht filtert ausschließlich ETB und
              TBB. Nachrichtenvordrucke, Anhänge und alle weiteren ausgewählten
              Bereiche bleiben vollständig einsatzweit.</small>
          </label>

          <fieldset class="estab-incident-export-sections">
            <legend>Inhalte</legend>
            <?php foreach ([
                'etb' => [
                    'Einsatztagebuch (ETB)',
                    'ETB-Einträge gemäß der oben gewählten ETB/TBB-Ausgabe',
                ],
                'ttb' => [
                    'Technisches Betriebsbuch (TBB)',
                    'TBB-Einträge gemäß der oben gewählten ETB/TBB-Ausgabe',
                ],
                'messages' => [
                    'Nachrichtenvordrucke',
                    'Alle Ein- und Ausgangsnachrichten als durchsuchbare Seiten',
                ],
                'attachments' => [
                    'Anlagen · sichtbar und im Original',
                    'Bilder, Text und PDF-Seiten direkt im Dossier; andere Formate mit Hinweisseite; alle Originale bytegleich eingebettet',
                ],
                'message_evidence' => [
                    'Nachrichtenereignisse und Nachweisköpfe',
                    'Vollständige Ereignisketten, Snapshots, Head-Hashes und Prüfstatus',
                ],
                'duty' => [
                    'Dienstorganisation',
                    'Formale Dienstschichten samt Besetzungen und Übergaben sowie getrennt die optionalen Zugangsschichten des lockeren Betriebs',
                ],
                's6_plans' => [
                    'S6-Fernmeldeplanung',
                    'Alle Planversionen und Einträge mit Gültigkeit und Freigabe',
                ],
                'courier' => [
                    'Melderaufträge',
                    'Komplette Auftrags-, Empfänger-, Rückweg- und Abschlusskette',
                ],
                'operations_evidence' => [
                    'Betriebsereignisse und Nachweiskopf',
                    'Unveränderliche Betriebskette mit neu berechnetem Hashstatus',
                ],
            ] as $section => [$label, $description]): ?>
              <label class="estab-incident-export-option">
                <input
                  type="checkbox"
                  name="include_<?= incident_export_html($section) ?>"
                  value="1"
                  <?= in_array($section, $selectedSections, true)
                      ? 'checked'
                      : '' ?>>
                <span>
                  <strong><?= incident_export_html($label) ?></strong>
                  <small><?= incident_export_html($description) ?></small>
                </span>
              </label>
            <?php endforeach; ?>
          </fieldset>

          <p class="estab-incident-export-limit">
            Sicherheitsgrenze für dargestellte und eingebettete Anlagen:
            <strong><?= number_format(
                $attachmentByteLimit / 1024 / 1024,
                0,
                ',',
                '.'
            ) ?> MiB</strong>.
            Wird sie überschritten oder fehlt eine Datei, bricht der Export
            sichtbar ab – Anhänge werden niemals stillschweigend ausgelassen.
          </p>

          <button class="estab-button estab-button-primary" type="submit">
            PDF-Dossier erstellen und herunterladen
          </button>
        </form>
      <?php endif; ?>
    </section>

    <footer class="estab-tool-footer estab-admin-dashboard-footer">
      <a href="admin.php">Zur Administration</a>
      <a href="incidents.php">Einsätze verwalten</a>
    </footer>
  </main>
  <script>
  (() => {
    const incident = document.getElementById('incident-export-incident');
    const scope = document.getElementById('incident-export-logbook-scope');
    if (!(incident instanceof HTMLSelectElement)
        || !(scope instanceof HTMLSelectElement)) {
      return;
    }
    const synchronizeShifts = () => {
      const incidentId = incident.value;
      for (const option of scope.options) {
        const optionIncidentId = option.dataset.incidentId || '';
        option.disabled = optionIncidentId !== ''
          && optionIncidentId !== incidentId;
      }
      if (scope.selectedOptions[0]?.disabled) {
        scope.value = 'all';
      }
    };
    incident.addEventListener('change', synchronizeShifts);
    synchronizeShifts();
  })();
  </script>
</body>
</html>
