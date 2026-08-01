<?php

declare(strict_types=1);

session_start();

if (PHP_SAPI !== 'cli' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    exit('Administrative authentication required.');
}

require_once __DIR__ . '/../app/session_ui.php';

estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$sections = [
    [
        'title' => 'Einsatzsteuerung und Zugänge',
        'description' => 'Den globalen Einsatz festlegen und Benutzerkonten '
            . 'sicher verwalten. Operative Eingaben sind nur bei einem aktiven '
            . 'Einsatz möglich.',
        'items' => [
            [
                'key' => 'incidents',
                'number' => '01',
                'title' => 'Einsätze verwalten',
                'description' => 'Einsätze anlegen, global aktivieren oder '
                    . 'beenden und damit die Zuordnung aller operativen Daten '
                    . 'steuern.',
                'badge' => 'Einsatzsteuerung',
                'href' => 'incidents.php',
            ],
            [
                'key' => 'users',
                'number' => '02',
                'title' => 'Benutzer verwalten',
                'description' => 'Konten anlegen, Funktionen sicher zuweisen, '
                    . 'Konten sperren oder entsperren und Kennwörter mit '
                    . 'sofortigem Sitzungswiderruf zurücksetzen.',
                'badge' => 'Zugriffsschutz',
                'href' => 'users.php',
            ],
            [
                'key' => 'self-registration',
                'number' => '03',
                'title' => 'Selbstregistrierung',
                'description' => 'Die öffentliche Kontoanlage sofort '
                    . 'deaktivieren, dauerhaft freigeben oder für einen '
                    . 'festen Zeitraum automatisch öffnen.',
                'badge' => 'Kontoanlage',
                'href' => 'self_registration.php',
            ],
            [
                'key' => 'password-policy',
                'number' => '04',
                'title' => 'Kennwortrichtlinie',
                'description' => 'Mindestlänge und optionale Anforderungen '
                    . 'für neu gesetzte Kennwörter zentral festlegen.',
                'badge' => 'Kontosicherheit',
                'href' => 'password_policy.php',
            ],
            [
                'key' => 'command-post',
                'number' => '05',
                'title' => 'Optionale Schichten',
                'description' => 'Konten optional zu Schichten gruppieren '
                    . 'und deren Zugang gemeinsam aktivieren oder '
                    . 'deaktivieren. Feste Funktionen bleiben unverändert.',
                'badge' => 'Zugangsplanung',
                'href' => 'fuehrungsstelle.php',
            ],
        ],
    ],
    [
        'title' => 'Konfiguration und Notfallmaßnahmen',
        'description' => 'Änderungen wirken unmittelbar auf den laufenden Einsatz. '
            . 'Vorher den aktuellen Stand und ein geprüftes Backup sichern.',
        'items' => [
            [
                'key' => 'matrix',
                'number' => '06',
                'title' => 'Empfängermatrix',
                'description' => 'Funktionen und Rollen bearbeiten; S2 bleibt '
                    . 'verbindliche Lage-/Dokumentationsfunktion und '
                    . 'Rotkopieziel.',
                'badge' => 'Konfiguration',
                'href' => 'make_fkt.php',
            ],
            [
                'key' => 'counter',
                'number' => '07',
                'title' => 'Nachrichtenzähler',
                'description' => 'Nach einem dokumentierten Systemausfall die '
                    . 'zuletzt auf Papier verwendete Nummer sicher erhöhen.',
                'badge' => 'Notfallmaßnahme',
                'href' => 'set_number_after_crash.php',
            ],
            [
                'key' => 'print-reset',
                'number' => '08',
                'title' => 'Vordruckmarkierungen zurücksetzen',
                'description' => 'Abgeschlossene Nachrichten beim nächsten Lauf '
                    . 'erneut als PDF-Vordruck erzeugen lassen.',
                'badge' => 'Wiedererzeugung',
                'href' => '../4fach/resetpic.php',
            ],
        ],
    ],
    [
        'title' => 'Daten und Diagnose',
        'description' => 'Lesende Kontrollen und gezielte Datenexporte für Betrieb, '
            . 'Übergabe und Fehleranalyse.',
        'items' => [
            [
                'key' => 'incident-pdf',
                'number' => '09',
                'title' => 'PDF-Einsatzdossier',
                'description' => 'ETB, TBB, Nachrichtenvordrucke und '
                    . 'Originalanhänge eines gewählten Einsatzes als '
                    . 'durchsuchbare PDF ausgeben.',
                'badge' => 'Dokumentation',
                'href' => 'incident_export.php',
            ],
            [
                'key' => 'export',
                'number' => '10',
                'title' => 'Einsatzexporte',
                'description' => 'Exporte erstellen, Manifest und Prüfsummen '
                    . 'ansehen, ZIP-Dateien herunterladen oder einzeln löschen.',
                'badge' => 'Datenaustausch',
                'href' => 'export.php',
            ],
            [
                'key' => 'system-status',
                'number' => '11',
                'title' => 'Systemstatus',
                'description' => 'PHP, Datenbank, persistente Speicher und die '
                    . 'wirksame Containerkonfiguration kontrollieren.',
                'badge' => 'Nur Lesen',
                'href' => 'system_status.php',
            ],
        ],
    ],
];

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Administration</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-admin-dashboard-page">
  <main class="estab-admin-dashboard" data-estab-admin-dashboard>
    <header class="estab-admin-dashboard-hero">
      <p class="estab-admin-dashboard-eyebrow">Technischer Administrationszugang</p>
      <h1>Administration</h1>
      <p>Wählen Sie die gewünschte Maßnahme. Schreibende Aktionen zeigen ihre
        Auswirkung nochmals an und werden erst nach einer ausdrücklichen
        Bestätigung ausgeführt.</p>
    </header>

    <aside class="estab-admin-dashboard-notice" aria-label="Sicherheitshinweis">
      <strong>Getrennter Zugang:</strong>
      Die HTTP-Basic-Anmeldung schützt diese technischen Maßnahmen unabhängig
      vom eStab-Funktionskonto. Verwenden Sie sie außerhalb eines isolierten
      Testhosts ausschließlich über TLS.
    </aside>

    <?php foreach ($sections as $section): ?>
      <section class="estab-admin-dashboard-section">
        <header class="estab-admin-dashboard-section-heading">
          <h2><?= estab_auth_html($section['title']) ?></h2>
          <p><?= estab_auth_html($section['description']) ?></p>
        </header>
        <div class="estab-admin-dashboard-grid">
          <?php foreach ($section['items'] as $item): ?>
            <a
              class="estab-admin-dashboard-card"
              data-estab-admin-card="<?= estab_auth_html($item['key']) ?>"
              href="<?= estab_auth_html($item['href']) ?>">
              <span class="estab-admin-dashboard-number" aria-hidden="true">
                <?= estab_auth_html($item['number']) ?>
              </span>
              <span class="estab-admin-dashboard-card-content">
                <span class="estab-admin-dashboard-badge">
                  <?= estab_auth_html($item['badge']) ?>
                </span>
                <strong><?= estab_auth_html($item['title']) ?></strong>
                <span><?= estab_auth_html($item['description']) ?></span>
              </span>
              <span class="estab-admin-dashboard-arrow" aria-hidden="true">→</span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <footer class="estab-admin-dashboard-footer">
      <a href="../">Zur eStab-Übersicht</a>
      <span>Historische Web-Installer und PHP-Diagnoseseiten sind im Container deaktiviert.</span>
    </footer>
  </main>
</body>
</html>
