<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../app/session_ui.php';
estab_session_ui_start($_SESSION, true);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

$sessionMarkup = estab_session_ui_current_markup(
    $_SESSION,
    true,
    null,
    false,
    true
);

$documents = [
    [
        'href' => 'Buchstabier.html',
        'title' => 'Buchstabieralphabet',
        'description' => 'Deutsches und internationales Alphabet',
    ],
    [
        'href' => 'Kartendatum.html',
        'title' => 'Neues Kartendatum',
        'description' => 'Hinweise zu ED50, WGS84 und UTMREF',
    ],
    [
        'href' => 'IuK-InfoPack.html',
        'title' => 'Stabzusammensetzung',
        'description' => 'Aufbau und Aufgaben des Einsatzleitstabs',
    ],
    [
        'href' => 'Orgas.html',
        'title' => 'Behörden und Organisationen',
        'description' => 'Abkürzungen und Sprechfunk-Rufnamen',
    ],
    [
        'href' => 'FF-Rufnamenschema.html',
        'title' => 'F-Rufnamenregel',
        'description' => 'Rufnamenschema der Feuerwehr',
    ],
    [
        'href' => 'DRK%20Rufnamenschema.html',
        'title' => 'DRK-Rufnamenregel',
        'description' => 'Rufnamenschema des Deutschen Roten Kreuzes',
    ],
    [
        'href' => 'THWFuRNR.html',
        'title' => 'THW-Rufnamenregel',
        'description' => 'Rufnamenschema des Technischen Hilfswerks',
    ],
];

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BOS-Informationen</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-navigation-frame estab-message-sidebar-page">
  <div class="estab-message-sidebar estab-bos-sidebar" data-estab-bos-sidebar>
    <?= $sessionMarkup ?>
    <main
      class="estab-bos-document-navigation"
      data-estab-bos-document-navigation
    >
      <header class="estab-sidebar-section-heading">
        <h1>Info-Bereiche</h1>
        <p>Dokument auswählen</p>
      </header>
      <nav aria-label="BOS-Dokumente">
        <ul class="estab-bos-document-list">
          <?php foreach ($documents as $document): ?>
            <li>
              <a
                class="estab-bos-document-link"
                href="<?= estab_auth_html($document['href']) ?>"
                target="mainframe"
                data-estab-bos-document-link
              >
                <strong><?= estab_auth_html($document['title']) ?></strong>
                <span><?= estab_auth_html($document['description']) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>
    </main>
  </div>
  <script data-estab-bos-workspace-link>
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
