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
require_once __DIR__ . '/../app/tabelle.php';
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

/**
 * Eine Statuszelle mit Marke.
 *
 * Die vier Statustafeln zeigen alle dasselbe: eine Prüfung, ihr Ergebnis
 * als grüne oder rote Marke. Ohne diese Stelle stünde derselbe Ausdruck
 * viermal da, und beim fünften Mal stünde er anders.
 */
function system_status_marke(bool $bereit): string
{
    return '<span class="estab-tool-badge '
        . ($bereit ? 'estab-tool-badge-success' : 'estab-tool-badge-danger')
        . '">' . system_status_html(system_status_label($bereit))
        . '</span>';
}

/**
 * Eine Statustafel: gleiche Optik wie jede Liste, ohne ihre Maschinerie.
 *
 * `baender => false` laesst Suchband, Ergebnisleiste, Spaltenmasken und
 * Blaetterer weg. Eine Volltextsuche ueber vier feste Zeilen waere kein
 * Gewinn, sondern Beiwerk, das die Tafel hoeher macht als ihren Inhalt --
 * und auf einem flachen Bildschirm ist Hoehe das Knappe.
 *
 * Die Zellen kommen fertig ausgezeichnet herein: Sie tragen Marken und
 * Codeauszeichnung, und beides ist an der Aufrufstelle klarer zu lesen als
 * hinter einer weiteren Abstraktion.
 *
 * @param array<string,string> $spalten Schluessel => Ueberschrift
 * @param list<array<string,string>> $zeilen
 */
function system_status_tafel(
    string $id,
    string $beschriftung,
    array $spalten,
    array $zeilen
): string {
    $breite = (int) floor(100 / max(1, count($spalten)));
    $aufbau = [];
    foreach ($spalten as $schluessel => $ueberschrift) {
        $aufbau[] = [
            'schluessel' => $schluessel,
            'kopf' => $ueberschrift,
            'breite' => $breite,
            'sortierbar' => false,
            'suchbar' => false,
            'art' => 'text',
            'zelle' => static fn (array $z): string =>
                (string) ($z[$schluessel] ?? ''),
        ];
    }
    return estab_tabelle_markup([
        'id' => $id,
        'beschriftung' => $beschriftung,
        'baender' => false,
        'mindestbreite' => '32rem',
        'schmal' => true,
        'spalten' => $aufbau,
        'zeilen' => $zeilen,
        'leer' => 'Keine Angabe verfügbar.',
    ]);
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
    'Organisationskennung' => estab_env('ESTAB_AUTHORITY_CODE') !== null,
    'Exportverzeichnis' => estab_env('ESTAB_EXPORT_DIR') !== null,
    'DB-Secret' => estab_env('ESTAB_DB_PASSWORD_FILE') !== null,
    'Admin-Anmeldedatei' => is_file('/run/estab-auth/admin.htpasswd')
        && is_readable('/run/estab-auth/admin.htpasswd'),
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
      <?php
        $laufzeitZeilen = [
            [
                'pruefung' => 'PHP',
                'wert' => '<code>' . system_status_html(PHP_VERSION) . '</code>',
                'ergebnis' => system_status_marke($runtimeReady),
            ],
            [
                'pruefung' => 'Laufzeitkonfiguration',
                'wert' => 'Container- und PHP-Vorgaben',
                'ergebnis' => system_status_marke($configurationReady),
            ],
        ];
        foreach ($extensions as $extension => $loaded) {
            $laufzeitZeilen[] = [
                'pruefung' => 'PHP-Erweiterung',
                'wert' => '<code>' . system_status_html($extension) . '</code>',
                'ergebnis' => '<span class="estab-tool-badge '
                    . ($loaded
                        ? 'estab-tool-badge-success'
                        : 'estab-tool-badge-danger')
                    . '">' . ($loaded ? 'geladen' : 'fehlt') . '</span>',
            ];
        }
        echo system_status_tafel(
            'stand-laufzeit',
            'Status der PHP-Laufzeit',
            ['pruefung' => 'Prüfung', 'wert' => 'Wert', 'ergebnis' => 'Ergebnis'],
            $laufzeitZeilen
        );
      ?>
    </section>

    <section class="estab-tool-panel" aria-labelledby="database-status-title">
      <header class="estab-tool-panel-heading">
        <h2 id="database-status-title">Datenbank</h2>
        <p>Erreichbarkeit und Vollständigkeit des erwarteten Schemas.</p>
      </header>
      <?php
        $datenbankZeilen = [];
        foreach ([
            'Verbindung und Lesetest' => $databaseReady,
            'Schema, Matrix und Migrationen' => $schemaReady,
        ] as $label => $ready) {
            $datenbankZeilen[] = [
                'pruefung' => system_status_html($label),
                'ergebnis' => system_status_marke($ready),
            ];
        }
        $datenbankZeilen[] = [
            'pruefung' => 'Basistabellen',
            'ergebnis' => $databaseTables === null
                ? 'nicht ermittelbar'
                : (string) (int) $databaseTables,
        ];
        echo system_status_tafel(
            'stand-datenbank',
            'Status der Datenbank',
            ['pruefung' => 'Prüfung', 'ergebnis' => 'Ergebnis'],
            $datenbankZeilen
        );
      ?>
    </section>

    <section class="estab-tool-panel" aria-labelledby="storage-status-title">
      <header class="estab-tool-panel-heading">
        <h2 id="storage-status-title">Persistente Speicher</h2>
        <p>Schreib- und Lesebereitschaft der dauerhaft eingebundenen Datenpfade.</p>
      </header>
      <?php
        $speicherZeilen = [];
        foreach ($storageReady as $label => $ready) {
            $speicherZeilen[] = [
                'pruefung' => system_status_html($label),
                'ergebnis' => system_status_marke($ready),
            ];
        }
        echo system_status_tafel(
            'stand-speicher',
            'Status der persistenten Speicher',
            ['pruefung' => 'Speicher', 'ergebnis' => 'Ergebnis'],
            $speicherZeilen
        );
      ?>
    </section>

    <section class="estab-tool-panel" aria-labelledby="configuration-status-title">
      <header class="estab-tool-panel-heading">
        <h2 id="configuration-status-title">Containerkonfiguration</h2>
        <p>Datenbank, Verzeichnisse und Laufzeitparameter werden über Compose,
          Umgebungsvariablen und eingehängte Secret-Dateien bereitgestellt.
          Historische Web-Installer, Konfigurationsschreiber und
          <code>phpinfo()</code> sind deshalb deaktiviert.</p>
      </header>
      <?php
        $vorgabenZeilen = [];
        foreach ($environmentChecks as $label => $configured) {
            $vorgabenZeilen[] = [
                'pruefung' => system_status_html($label),
                /*
                 * Hier ist "nicht gesetzt" kein Fehler, sondern der
                 * Vorgabewert. Deshalb eine neutrale Marke und nicht die
                 * rote: Ein roter Punkt neben einer richtigen Einstellung
                 * schickt jemanden auf die Suche nach einem Fehler, den es
                 * nicht gibt.
                 */
                'ergebnis' => '<span class="estab-tool-badge '
                    . ($configured
                        ? 'estab-tool-badge-success'
                        : 'estab-tool-badge-neutral')
                    . '">' . ($configured ? 'konfiguriert' : 'Standardwert aktiv')
                    . '</span>',
            ];
        }
        echo system_status_tafel(
            'stand-vorgaben',
            'Wirksame nicht-sensible Containerkonfiguration',
            ['pruefung' => 'Vorgabe', 'ergebnis' => 'Zustand'],
            $vorgabenZeilen
        );
      ?>
    </section>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zur Administration</a>
      <a href="export.php">Einsatzexporte öffnen</a>
    </footer>
  </main>
</body>
</html>
