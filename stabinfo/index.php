<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

?>
<!doctype html>
<html lang="de" class="estab-message-workspace-document">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="author" content="Hajo Landmesser">
  <link rel="shortcut icon" href="../favicon.ico">
  <link rel="stylesheet" href="../estab-ui.css">
  <title>Infosammlung BOS</title>
</head>
<body class="estab-message-workspace">
  <main
    class="estab-message-workspace-grid"
    data-estab-bos-workspace
  >
    <iframe
      class="estab-message-sidebar-frame"
      name="status"
      title="eStab Navigation und BOS-Dokumente"
      src="./l_index.php"
    ></iframe>
    <iframe
      class="estab-message-content-frame"
      name="mainframe"
      title="BOS-Informationsbereich"
      src="./f_info.php"
    ></iframe>
  </main>
  <button
    class="estab-mobile-sidebar-return"
    type="button"
    data-estab-mobile-menu-return
    hidden
  >
    <span aria-hidden="true">←</span>
    Info-Menü
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

      function prepareContentDocument() {
        if (!content) {
          return;
        }
        try {
          var documentElement = content.contentDocument.documentElement;
          var body = content.contentDocument.body;
          if (!documentElement || !body) {
            return;
          }
          documentElement.classList.add('estab-bos-embedded-document');
          body.classList.add('estab-bos-embedded-content');
          if (
            !content.contentDocument.querySelector(
              'link[data-estab-bos-responsive-style]'
            )
          ) {
            var stylesheet = content.contentDocument.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = '../estab-ui.css';
            stylesheet.setAttribute(
              'data-estab-bos-responsive-style',
              ''
            );
            content.contentDocument.head.appendChild(stylesheet);
          }
        } catch (ignore) {
          // Only same-origin repository documents are enhanced.
        }
      }

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
          prepareContentDocument();
          if (contentRequested) {
            window.requestAnimationFrame(showContent);
          }
        });
        prepareContentDocument();
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

      function resetWideLayout() {
        if (!narrow.matches && returnButton) {
          contentRequested = false;
          returnButton.hidden = true;
        }
      }

      if (typeof narrow.addEventListener === 'function') {
        narrow.addEventListener('change', resetWideLayout);
      } else if (typeof narrow.addListener === 'function') {
        narrow.addListener(resetWideLayout);
      }
    })();
  </script>
</body>
</html>
