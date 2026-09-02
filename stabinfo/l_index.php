<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../app/session_ui.php';
// Diese Seite ist der Inhalt der Huelle, nicht die Huelle selbst.
// Menue und Cockpit stehen aussen; hier waeren sie ein Menue im Menue.
estab_session_ui_start($_SESSION, true, false, false);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

/*
 * Die Dokumentenliste traegt keine Sitzungsleiste und keine Navigation mehr.
 * Sie ist die linke Haelfte des Infosammlungs-Inhalts und steht damit
 * innerhalb der Huelle -- Menue links und Cockpit rechts kommen von dort.
 * Frueher brachte sie beides selbst mit und stand als zweites Menue neben
 * dem der Huelle.
 */

$documents = require __DIR__ . '/documents.php';

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BOS-Informationen</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-navigation-frame estab-bos-list-page">
  <div class="estab-bos-sidebar" data-estab-bos-sidebar>
    <main
      class="estab-sidebar-workflow estab-bos-document-navigation"
      data-estab-bos-document-navigation
    >
      <header class="estab-sidebar-section-heading">
        <h1>Info-Bereiche</h1>
        <p>Dokument auswählen</p>
      </header>
      <nav aria-label="BOS-Dokumente">
        <ul class="estab-sidebar-actions estab-bos-document-list">
          <?php foreach ($documents as $document): ?>
            <li>
              <a
                class="estab-sidebar-action estab-bos-document-link"
                href="<?= estab_auth_html($document['href']) ?>"
                target="mainframe"
                data-estab-bos-document-link
              >
                <strong class="estab-sidebar-action-title">
                  <?= estab_auth_html($document['title']) ?>
                </strong>
                <span class="estab-sidebar-action-description">
                  <?= estab_auth_html($document['description']) ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>
    </main>
  </div>
  <script<?= estab_csp_script_attribute() ?> data-estab-bos-workspace-link>
    (function () {
      var links = Array.from(
        document.querySelectorAll('[data-estab-bos-document-link]')
      );
      links.forEach(function (link) {
        link.addEventListener('click', function () {
          links.forEach(function (candidate) {
            candidate.removeAttribute('aria-current');
          });
          link.setAttribute('aria-current', 'page');
          if (window.parent !== window) {
            window.parent.postMessage(
              'estab:show-content',
              window.location.origin
            );
          }
        });
      });
    })();
  </script>
</body>
</html>
