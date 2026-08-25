<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/app_shell.php';

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
/*****************************************************************************\
   Datei: index.php

   Beschreibung:

          In dieser Datei wird der Arbeitsbereich eingerichtet:
          links eine durchgehende Navigation, rechts mainindex.php.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
*****************************************************************************/
?>
<?= estab_shell_head('Nachrichtenvordruck') ?>
<body class="estab-shell-body estab-message-workspace">
  <div class="estab-shell" data-estab-shell data-estab-message-workspace>
    <?= estab_shell_menu_markup(
        estab_auth_session_identity($_SESSION),
        $_SERVER,
        null,
        true
    ) ?>
    <main class="estab-shell-content estab-shell-content--frame"
      data-estab-shell-content>
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
    <?= estab_shell_cockpit_markup() ?>
  </div>
  <button
    class="estab-mobile-sidebar-return"
    type="button"
    data-estab-mobile-menu-return
    hidden
  >
    <span aria-hidden="true">←</span>
    Menü
  </button>
  <script<?= estab_csp_script_attribute() ?> data-estab-mobile-workspace-navigation>
    (function () {
      /*
       * Der Wechsel auf den Inhalt wird vom Cockpit angestossen: Dort liegen
       * die Arbeitsschritte, und wer auf einem schmalen Bildschirm einen
       * davon waehlt, will danach den Vordruck sehen und nicht die Spalte,
       * aus der er kam. Das Menue links braucht das nicht -- seine Verweise
       * ersetzen ohnehin das ganze Fenster.
       */
      var cockpit = document.querySelector('.estab-shell-cockpit');
      var menu = document.querySelector('.estab-shell-menu');
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
          && cockpit
          && event.source === cockpit.contentWindow
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
          if (menu) {
            menu.scrollIntoView({block: 'start'});
            menu.focus({preventScroll: true});
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

