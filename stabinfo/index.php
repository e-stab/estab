<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

require_once __DIR__ . '/../app/app_shell.php';

// Die Infosammlung steht auch ohne Anmeldung offen. Das Menue links soll
// aber wissen, ob jemand angemeldet ist -- sonst zeigt es einer angemeldeten
// Kraft lauter gesperrte Ziele.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$documents = require __DIR__ . '/documents.php';
$documentMetadata = [];
foreach ($documents as $document) {
    $documentMetadata[rawurldecode($document['href'])] = [
        'title' => $document['title'],
        'description' => $document['description'],
    ];
}
$documentMetadataJson = json_encode(
    $documentMetadata,
    JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR
);

?>
<?= estab_shell_head('Infosammlung BOS') ?>
<body class="estab-shell-body">
  <div class="estab-shell" data-estab-shell>
    <?= estab_shell_menu_markup(
        estab_auth_session_identity($_SESSION),
        $_SERVER,
        null,
        false,
        estab_shell_context_markup('Info-Bereiche', $documents)
    ) ?>
    <main class="estab-shell-content estab-shell-content--frame"
      data-estab-shell-content>
      <!--
        Die Auswahl der Dokumente stand hier als eigene Spalte -- ein zweites
        Menue neben dem der Huelle, das noch einmal Breite kostete. Sie steht
        jetzt links unter den Zielen. In der Mitte bleibt das gewaehlte
        Dokument, und das ist der Inhalt dieser Seite.
      -->
      <div class="estab-bos-panes" data-estab-bos-workspace>
        <iframe
          class="estab-bos-content-frame"
          name="mainframe"
          title="BOS-Informationsbereich"
          src="./f_info.php"
        ></iframe>
      </div>
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
    Info-Menü
  </button>
  <script<?= estab_csp_script_attribute() ?> data-estab-mobile-workspace-navigation>
    (function () {
      var sidebar = document.querySelector('[data-estab-shell-context]');
      var content = document.querySelector('.estab-bos-content-frame');
      var returnButton = document.querySelector(
        '[data-estab-mobile-menu-return]'
      );
      var narrow = window.matchMedia('(max-width: 42rem)');
      var contentRequested = false;
      var documentMetadata = <?= $documentMetadataJson ?>;

      function legacyDocumentName() {
        if (!content || !content.contentWindow) {
          return '';
        }
        try {
          var parts = content.contentWindow.location.pathname.split('/');
          return decodeURIComponent(parts[parts.length - 1] || '');
        } catch (ignore) {
          return '';
        }
      }

      function wrapLegacyTables(documentSurface) {
        Array.from(documentSurface.querySelectorAll('table')).forEach(
          function (table) {
            if (
              table.parentElement
              && table.parentElement.closest(
                '[data-estab-bos-table-scroll]'
              )
            ) {
              return;
            }
            var wrapper = table.ownerDocument.createElement('div');
            var widestRow = Array.from(table.rows || []).reduce(
              function (maximum, row) {
                var columns = Array.from(row.cells || []).reduce(
                  function (count, cell) {
                    return count + (cell.colSpan || 1);
                  },
                  0
                );
                return Math.max(maximum, columns);
              },
              0
            );
            var fixedWidth = parseFloat(table.style.width || '0');
            wrapper.className = 'estab-bos-table-scroll';
            wrapper.setAttribute('data-estab-bos-table-scroll', '');
            if (
              widestRow >= 4
              || (widestRow > 1 && fixedWidth >= 600)
            ) {
              wrapper.classList.add('estab-bos-table-wide');
            }
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
          }
        );
      }

      function updateTableScrollRegions(documentObject) {
        var documentHeading = documentObject.querySelector(
          '[data-estab-bos-document-shell] h1'
        );
        var documentTitle = documentHeading
          ? documentHeading.textContent.trim()
          : 'BOS-Dokument';
        Array.from(
          documentObject.querySelectorAll(
            '[data-estab-bos-table-scroll]'
          )
        ).forEach(function (wrapper, index) {
          var scrollable =
            wrapper.scrollWidth > wrapper.clientWidth + 1;
          if (scrollable) {
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute(
              'aria-label',
              'Horizontal verschiebbare Datentabelle '
                + (index + 1)
                + ' in '
                + documentTitle
            );
            wrapper.tabIndex = 0;
          } else {
            wrapper.removeAttribute('role');
            wrapper.removeAttribute('aria-label');
            wrapper.removeAttribute('tabindex');
          }
        });
      }

      function markDocumentLayoutReady(documentObject) {
        documentObject.defaultView.requestAnimationFrame(function () {
          updateTableScrollRegions(documentObject);
          documentObject.documentElement.setAttribute(
            'data-estab-bos-layout-ready',
            'true'
          );
        });
      }

      function syncSidebarSelection(documentName) {
        if (!sidebar) {
          return;
        }
        try {
          Array.from(
            sidebar.querySelectorAll('[data-estab-bos-document-link]')
          ).forEach(function (link) {
            var linkName = decodeURIComponent(
              new URL(link.href).pathname.split('/').pop() || ''
            );
            if (linkName === documentName) {
              link.setAttribute('aria-current', 'page');
            } else {
              link.removeAttribute('aria-current');
            }
          });
        } catch (ignore) {
          // Navigation remains usable even if a document URL is malformed.
        }
      }

      function wrapLegacyDocument(documentObject, metadata, documentName) {
        var body = documentObject.body;
        if (
          !body
          || !metadata
          || body.querySelector('[data-estab-bos-document-shell]')
        ) {
          return;
        }

        var originalContent = documentObject.createElement('div');
        originalContent.className = 'estab-bos-document-content';
        originalContent.setAttribute(
          'data-estab-bos-original-content',
          ''
        );
        while (body.firstChild) {
          originalContent.appendChild(body.firstChild);
        }

        var shell = documentObject.createElement('main');
        shell.className = 'estab-bos-document-shell';
        shell.setAttribute('data-estab-bos-document-shell', '');
        shell.setAttribute('data-estab-bos-document', documentName);

        var header = documentObject.createElement('header');
        header.className = 'estab-bos-document-header';

        var kicker = documentObject.createElement('span');
        kicker.className = 'estab-bos-document-kicker';
        kicker.textContent = 'Infosammlung BOS';

        var heading = documentObject.createElement('h1');
        heading.textContent = metadata.title;

        var description = documentObject.createElement('p');
        description.textContent = metadata.description;

        header.appendChild(kicker);
        header.appendChild(heading);
        header.appendChild(description);
        shell.appendChild(header);
        shell.appendChild(originalContent);
        body.appendChild(shell);
        wrapLegacyTables(originalContent);

        if (!documentObject.documentElement.dataset.estabBosTableResize) {
          documentObject.documentElement.dataset.estabBosTableResize = 'true';
          documentObject.defaultView.addEventListener(
            'resize',
            function () {
              updateTableScrollRegions(documentObject);
            }
          );
        }
      }

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
          var documentName = legacyDocumentName();
          var metadata = documentMetadata[documentName];
          syncSidebarSelection(documentName);
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
            stylesheet.addEventListener('load', function () {
              markDocumentLayoutReady(stylesheet.ownerDocument);
            });
            content.contentDocument.head.appendChild(stylesheet);
          }
          wrapLegacyDocument(
            content.contentDocument,
            metadata,
            documentName
          );
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

      if (sidebar) {
        sidebar.addEventListener('click', function (event) {
          if (
            event.target
            && event.target.closest('[data-estab-bos-document-link]')
          ) {
            showContent();
          }
        });
      }

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
          var menu = document.querySelector('.estab-shell-menu');
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
