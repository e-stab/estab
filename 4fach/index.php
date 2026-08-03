<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/navigation.php';

$loginFlow = null;
$loginDestination = null;
$loginInterrupted = false;
foreach (array_keys($_GET) as $requestKey) {
   if (
      !is_string($requestKey)
      || !in_array(
         $requestKey,
         ['login_flow', 'next', 'interrupted'],
         true
      )
   ) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültige Anmeldeauswahl.';
      exit;
   }
}
if (array_key_exists('login_flow', $_GET)) {
   if (!is_string($_GET['login_flow'])) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültige Anmeldeauswahl.';
      exit;
   }
   $loginFlow = estab_auth_login_flow($_GET);
   if ($loginFlow === null) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültige Anmeldeauswahl.';
      exit;
   }
}
if (array_key_exists('next', $_GET)) {
   $loginDestination = estab_navigation_login_destination_key($_GET['next']);
   if ($loginDestination === null) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültiges Anmeldeziel.';
      exit;
   }
}
if (array_key_exists('interrupted', $_GET)) {
   if (!is_string($_GET['interrupted']) || $_GET['interrupted'] !== '1') {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültiger Anmeldehinweis.';
      exit;
   }
   $loginInterrupted = true;
}

session_start();
$mainFrameQuery = [];
if ($loginFlow !== null) {
   $mainFrameQuery['login_flow'] = $loginFlow;
}
if ($loginDestination !== null) {
   $mainFrameQuery['next'] = $loginDestination;
}
if ($loginInterrupted) {
   $mainFrameQuery['interrupted'] = '1';
}
$mainFrameUrl = './mainindex.php'
   . ($mainFrameQuery === [] ? '' : '?' . http_build_query($mainFrameQuery));
$navigationFrameUrl = './vorgaben.php'
   . ($loginDestination === null
      ? ''
      : '?next=' . rawurlencode($loginDestination));
/*****************************************************************************\
   Datei: index.php

   Beschreibung:

          In dieser Datei wird der Arbeitsbereich eingerichtet:
          links eine durchgehende Navigation, rechts mainindex.php.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
*****************************************************************************/
?>
<!doctype html>
<html lang="de" class="estab-message-workspace-document">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="author" content="Hajo Landmesser">
  <link rel="shortcut icon" href="../favicon.ico">
  <link rel="stylesheet" href="../estab-ui.css">
  <title>Nachrichtenvordruck</title>
</head>
<body class="estab-message-workspace">
  <main class="estab-message-workspace-grid" data-estab-message-workspace>
    <iframe
      class="estab-message-sidebar-frame"
      name="vorgaben"
      title="eStab Navigation und Einsatzstatus"
      src="<?= htmlspecialchars(
          $navigationFrameUrl,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8'
      ) ?>"
    ></iframe>
    <iframe
      class="estab-message-content-frame"
      name="mainframe"
      title="eStab Arbeitsbereich"
      src="<?= htmlspecialchars(
          $mainFrameUrl,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8'
      ) ?>"
    ></iframe>
  </main>
  <button
    class="estab-mobile-sidebar-return"
    type="button"
    data-estab-mobile-menu-return
    hidden
  >
    <span aria-hidden="true">←</span>
    Menü
  </button>
  <script data-estab-mobile-workspace-navigation>
    (function () {
      var sidebar = document.querySelector('.estab-message-sidebar-frame');
      var content = document.querySelector('.estab-message-content-frame');
      var returnButton = document.querySelector(
        '[data-estab-mobile-menu-return]'
      );
      var narrow = window.matchMedia('(max-width: 42rem)');
      var contentRequested = false;

      function showContent() {
        if (!narrow.matches || !content || !returnButton) {
          return;
        }
        contentRequested = true;
        returnButton.hidden = false;
        content.scrollIntoView({block: 'start'});
        content.focus({preventScroll: true});
      }

      window.addEventListener('message', function (event) {
        if (
          event.origin === window.location.origin
          && sidebar
          && event.source === sidebar.contentWindow
          && event.data === 'estab:show-content'
        ) {
          showContent();
        }
      });

      if (content) {
        content.addEventListener('load', function () {
          if (contentRequested) {
            window.requestAnimationFrame(showContent);
          }
        });
      }

      if (returnButton) {
        returnButton.addEventListener('click', function () {
          contentRequested = false;
          returnButton.hidden = true;
          if (sidebar) {
            sidebar.scrollIntoView({block: 'start'});
            sidebar.focus({preventScroll: true});
          }
        });
      }

      window.addEventListener('scroll', function () {
        if (
          narrow.matches
          && returnButton
          && window.scrollY < window.innerHeight / 2
        ) {
          contentRequested = false;
          returnButton.hidden = true;
        }
      }, {passive: true});

      narrow.addEventListener('change', function () {
        if (!narrow.matches && returnButton) {
          contentRequested = false;
          returnButton.hidden = true;
        }
      });
    })();
  </script>
</body>
</html>

