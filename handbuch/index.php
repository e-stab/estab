<?php

declare(strict_types=1);

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Für das Web-Handbuch sind nur GET und HEAD erlaubt.';
    exit;
}

session_start();

require_once __DIR__ . '/../app/session_ui.php';

estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');

$routes = [
    'home' => estab_application_root(),
    'login' => estab_navigation_login_url(),
    'messages' => estab_application_url('4fach/index.php'),
    'command_post' => estab_application_url('4fach/fuehrungsstelle.php'),
    'message_overview' => estab_application_url('4fueltg/ue_ltg.php'),
    'forms' => estab_application_url('4fach/vordrucke.php'),
    'attachments' => estab_application_url('4fach/anhang.php'),
    'etb' => estab_application_url('stabetb/etb.php'),
    'ttb' => estab_application_url('fmtbb/tbb.php'),
    'tracking' => estab_application_url('4fach/nachwea.php?nwalle'),
    'bos_info' => estab_application_url('stabinfo/index.php'),
    'admin' => estab_application_url('4fadm/admin.php'),
    'incidents' => estab_application_url('4fadm/incidents.php'),
    'users' => estab_application_url('4fadm/users.php'),
    'admin_command_post' => estab_application_url('4fadm/fuehrungsstelle.php'),
    'matrix' => estab_application_url('4fadm/make_fkt.php'),
    'incident_pdf' => estab_application_url('4fadm/incident_export.php'),
    'exports' => estab_application_url('4fadm/export.php'),
    'system_status' => estab_application_url('4fadm/system_status.php'),
    'counter' => estab_application_url('4fadm/set_number_after_crash.php'),
    'form_reset' => estab_application_url('4fach/resetpic.php'),
    'health' => estab_application_url('health.php'),
];
$href = static fn (string $key): string => estab_auth_html($routes[$key]);
$handbookVersion = '2026.08';
$handbookUpdated = '1. August 2026';

?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Aktuelles Web-Handbuch für eStab: Einsatzbetrieb, Nachrichtenvordruck, ETB, TBB, Administration und Export.">
  <link rel="shortcut icon" href="../favicon.ico">
  <link rel="stylesheet" href="../estab-ui.css">
  <link rel="stylesheet" href="./handbuch.css">
  <script src="./handbuch.js" defer></script>
  <title>eStab Web-Handbuch</title>
</head>
<body class="estab-handbook-page" data-estab-handbook data-estab-handbook-version="<?= estab_auth_html($handbookVersion) ?>">
  <a class="estab-handbook-skip" href="#handbook-content">Zum Handbuchinhalt</a>

  <header class="estab-handbook-hero">
    <div class="estab-handbook-hero-inner">
      <p class="estab-handbook-eyebrow">Aktuelle Anwendungshilfe</p>
      <h1>eStab <span>Web-Handbuch</span></h1>
      <p class="estab-handbook-lead">Vom ersten Login bis zum abgeschlossenen
        Einsatz: Dieses Handbuch beschreibt den Funktionsstand, der zusammen
        mit dieser Anwendung ausgeliefert wird.</p>
      <div class="estab-handbook-meta" aria-label="Handbuchstand">
        <span>Version <?= estab_auth_html($handbookVersion) ?></span>
        <span>Stand <?= estab_auth_html($handbookUpdated) ?></span>
        <span>Öffentlich und offline verfügbar</span>
      </div>
      <div class="estab-handbook-hero-actions">
        <a class="estab-handbook-primary-action" href="#schnellstart">In 5 Minuten startklar</a>
        <a class="estab-handbook-secondary-action" href="<?= $href('home') ?>">Zur eStab-Übersicht</a>
      </div>
    </div>
  </header>

  <main id="handbook-content" class="estab-handbook-main">
    <section class="estab-handbook-search" aria-labelledby="handbook-search-title">
      <div>
        <p class="estab-handbook-eyebrow">Direkt zur Antwort</p>
        <h2 id="handbook-search-title">Handbuch durchsuchen</h2>
        <p>Mehrere Begriffe werden gemeinsam gesucht, zum Beispiel
          <em>Ausgang LdF Beförderungsweg</em> oder <em>PDF Anlagen</em>.</p>
      </div>
      <div class="estab-handbook-search-control">
        <label for="handbook-search">Suchbegriff</label>
        <div class="estab-handbook-search-row">
          <input id="handbook-search" type="search" autocomplete="off"
            placeholder="Rolle, Aufgabe oder Fehlermeldung"
            aria-controls="handbook-chapters"
            data-estab-handbook-search>
          <button type="button" data-estab-handbook-clear hidden>Suche löschen</button>
        </div>
        <p class="estab-handbook-search-status" data-estab-handbook-status
          role="status" aria-live="polite" aria-atomic="true">Alle Kapitel werden angezeigt.</p>
      </div>
    </section>

    <section class="estab-handbook-role-entry" aria-labelledby="role-entry-title">
      <header>
        <p class="estab-handbook-eyebrow">Schnelleinstieg nach Aufgabe</p>
        <h2 id="role-entry-title">Was möchten Sie gerade tun?</h2>
      </header>
      <div class="estab-handbook-role-grid">
        <a href="#nachrichtenlauf"><strong>Nachricht bearbeiten</strong><span>A/W, Si, LdF oder Verfasser</span></a>
        <a href="#etb"><strong>ETB führen</strong><span>Ereignisse, Bezüge und Korrekturen</span></a>
        <a href="#ttb"><strong>TBB führen</strong><span>Fernmeldebetrieb dokumentieren</span></a>
        <a href="#vorbereitung"><strong>Einsatz vorbereiten</strong><span>Administration und Dienstschicht</span></a>
        <a href="#export"><strong>Dokumentation ausgeben</strong><span>PDF-Dossier oder ZIP-Export</span></a>
        <a href="#probleme"><strong>Problem lösen</strong><span>Login, Einsatz, Hut oder Upload</span></a>
      </div>
    </section>

    <div class="estab-handbook-layout">
      <aside class="estab-handbook-toc">
        <details open>
          <summary>Inhalt</summary>
          <nav aria-label="Handbuchkapitel">
            <ol data-estab-handbook-toc>
              <li><a href="#willkommen">1. Willkommen</a></li>
              <li><a href="#schnellstart">2. Schnellstart</a></li>
              <li><a href="#navigation">3. Navigation und Sitzung</a></li>
              <li><a href="#rollen">4. Rollen und Rechte</a></li>
              <li><a href="#vorbereitung">5. Einsatz vorbereiten</a></li>
              <li><a href="#vordruck">6. Nachrichtenvordruck</a></li>
              <li><a href="#nachrichtenlauf">7. Nachrichtenlauf</a></li>
              <li><a href="#anhaenge">8. Anhänge</a></li>
              <li><a href="#finden">9. Finden und ordnen</a></li>
              <li><a href="#etb">10. Einsatztagebuch</a></li>
              <li><a href="#ttb">11. Technisches Betriebsbuch</a></li>
              <li><a href="#fernmeldeplan">12. S6-Fernmeldeplan</a></li>
              <li><a href="#melder">13. Melderaufträge</a></li>
              <li><a href="#uebergabe">14. Übergabe und Abschluss</a></li>
              <li><a href="#export">15. Export und PDF</a></li>
              <li><a href="#administration">16. Administration</a></li>
              <li><a href="#betrieb">17. Installation und Betrieb</a></li>
              <li><a href="#probleme">18. Probleme lösen</a></li>
              <li><a href="#kurzreferenz">19. Kurzreferenz</a></li>
            </ol>
          </nav>
        </details>
      </aside>

      <div id="handbook-chapters" class="estab-handbook-chapters">
        <p class="estab-handbook-no-results" data-estab-handbook-empty hidden>
          Kein Kapitel passt zu dieser Suche. Prüfen Sie die Schreibweise oder
          löschen Sie einzelne Suchbegriffe.
        </p>

        <article id="willkommen" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Einführung Zweck Papier Nachrichtenvordruck THW Freigabe Rückfallebene">
          <header><span>01</span><div><p>Grundidee und Geltungsbereich</p><h2>Willkommen bei eStab</h2></div></header>
          <p>eStab ersetzt den vierfachen Nachrichtenvordruck aus Papier durch
            einen rollenbezogenen digitalen Ablauf. Das Formular bleibt am
            amtlichen Erscheinungsbild orientiert; Weitergabe, Sichtung,
            Nachweisung, ETB und TBB werden einsatzgebunden dokumentiert.</p>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>Unterstützter Umfang</strong>
            <p>Die Anwendung ist für eine Führungsstelle <em>mit</em>
              eingerichteter Fernmeldebetriebsstelle ausgelegt. S2, Si, S6,
              LdF und A/W müssen deshalb für den Dienstbetrieb besetzt sein.</p>
          </div>
          <p>Die technische Umsetzung ersetzt weder Ausbildung noch örtliche
            Stabsordnung oder eine erforderliche formale THW-Freigabe. Die
            Unterschriftszeilen in PDF-Ausgaben sind für eine manuelle
            Zeichnung vorgesehen und stellen keine digitale Signatur dar.
            Eine beherrschte Papier-Rückfallebene bleibt erforderlich.</p>
        </article>

        <article id="schnellstart" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="erste Schritte Login anmelden Zuweisung annehmen Hut auswählen Einsatz Schicht">
          <header><span>02</span><div><p>Der sichere Einstieg</p><h2>In 5 Minuten startklar</h2></div></header>
          <ol class="estab-handbook-steps">
            <li><strong>Mit bestehendem Konto anmelden.</strong> Öffnen Sie die
              <a href="<?= $href('login') ?>">Anmeldeseite</a> und verwenden
              Sie Name, Kürzel, Funktion und Kennwort Ihres Funktionskontos.</li>
            <li><strong>Dienstzuweisung annehmen.</strong> Öffnen Sie den
              <a href="<?= $href('command_post') ?>">Führungsstellenbetrieb</a>.
              Eine administrative Zuweisung wird erst durch Ihre persönliche
              Annahme zu einer wirksamen Besetzung.</li>
            <li><strong>Aktiven Funktions-Hut wählen.</strong> Wenn Sie mehrere
              Funktionen übernehmen, wählen Sie genau die Funktion, in deren
              Verantwortung Sie jetzt arbeiten.</li>
            <li><strong>Status prüfen.</strong> Oben müssen Ihr Name, Funktion,
              Rolle, Führungsstellenname, aktiver Einsatz und aktive
              Dienstschicht erscheinen.</li>
            <li><strong>Arbeitsbereich öffnen.</strong> Wechseln Sie über die
              obere Navigation zum Nachrichtenvordruck, ETB, TBB oder in den
              für Ihren Hut freigegebenen Spezialbereich.</li>
          </ol>
          <div class="estab-handbook-callout estab-handbook-callout-danger">
            <strong>Roter Warnhinweis</strong>
            <p>Ohne aktiven Einsatz, bestätigten Führungsstellennamen, aktive
              Schicht oder gewählten angenommenen Hut sind operative Eingaben
              absichtlich gesperrt. Wenden Sie sich dann an die Einsatz- oder
              Systemadministration; versuchen Sie nicht, die Sperre über eine
              andere URL zu umgehen.</p>
          </div>
          <h3>Bestehendes oder neues Konto?</h3>
          <p>Im Regelbetrieb legt die zuständige Stelle Konten in der
            Benutzerverwaltung an. Wählen Sie dann immer „Mit bestehendem
            Konto anmelden“: Ein unbekanntes Kürzel erzeugt dabei bewusst kein
            neues Konto. „Neues Konto anlegen“ erscheint nur, wenn die
            öffentliche Selbstregistrierung ausdrücklich freigeschaltet wurde.
            Sie verlangt Name, ein eindeutiges Kürzel, die zugeteilte Funktion
            und zweimal dasselbe Kennwort. Auch ein so angelegtes Konto erhält
            erst durch eine persönlich angenommene Dienstzuweisung operative
            Rechte.</p>
        </article>

        <article id="navigation" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Menü obere Leiste Sidebar Status Benutzer online inaktiv logout abmelden 15 Minuten 12 Stunden ungespeichert">
          <header><span>03</span><div><p>Überall dieselbe Orientierung</p><h2>Navigation, Status und Sitzung</h2></div></header>
          <p>Die obere Leiste zeigt immer, als wer Sie angemeldet sind. Nach der
            Hutauswahl enthält sie Kürzel, Funktion und Rolle sowie den aktiven
            Einsatz, die Führungsstelle und den Dienststatus. Der sichtbare
            Menüpunkt ist markiert; nicht berechtigte Spezialbereiche werden
            nicht angeboten.</p>
          <ul>
            <li><strong>Bereich wechseln:</strong> Nutzen Sie die zentrale
              Navigation. Im Nachrichtenarbeitsbereich steht dieselbe
              Navigation kompakt in der linken Seitenleiste.</li>
            <li><strong>Ungespeicherte Eingaben:</strong> Beim Bereichswechsel
              oder Abmelden warnt eStab, wenn ein gekennzeichnetes Formular
              geändert wurde.</li>
            <li><strong>Präsenz:</strong> Nach 15 Minuten ohne echte
              Browserinteraktion erscheint ein Konto als inaktiv. Das ist
              noch keine Abmeldung. Polling, automatische Aktualisierung und
              ein bloß geöffneter Tab verlängern die Frist nicht.</li>
            <li><strong>Sitzungsende:</strong> Nach 12 Stunden ohne Aktivität
              wird die Sitzung serverseitig beendet. Ungespeicherte
              Formulareingaben werden nicht nachträglich übernommen.</li>
            <li><strong>Abmelden:</strong> Verwenden Sie immer den sichtbaren
              Knopf „Abmelden“, besonders an gemeinsam genutzten Geräten.</li>
          </ul>
        </article>

        <article id="rollen" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="S1 S2 S3 S4 S5 S6 Fachberater Si Sichter LdF Leiter Fernmeldebetrieb A/W Aufnahme Weitergabe ETB Admin Rollen Rechte Mehrfachhut">
          <header><span>04</span><div><p>Verantwortung statt bloßer Menüfreigabe</p><h2>Rollen und Rechte</h2></div></header>
          <p>Ein Konto, eine Dienstbesetzung und der aktuell ausgewählte Hut
            sind drei verschiedene Dinge. Rechte entstehen erst aus der
            servergeprüften Kombination aus Konto, aktivem Einsatz, aktiver
            Schicht und persönlich angenommener Besetzung.</p>
          <div class="estab-handbook-table-wrap" role="region" aria-label="Rollenübersicht" tabindex="0">
            <table>
              <thead><tr><th>Rolle/Funktion</th><th>Hauptaufgaben in eStab</th></tr></thead>
              <tbody>
                <tr><td>S1-S6, Fachberatung, Verbindung</td><td>Nachrichten lesen, eigene Ausgänge verfassen, antworten, weiterleiten, Gesprächsnotizen sowie gelesen/erledigt und Kategorien pflegen.</td></tr>
                <tr><td>S2</td><td>Verbindliche Lage-/Dokumentationsfunktion und einziges Rotkopieziel; Meldungsübersicht. Schreibt ETB nur, wenn kein vorrangiger ETB-Hut bestimmt ist.</td></tr>
                <tr><td>ETB</td><td>Eigenständige Buchführungsfunktion. Daraus folgen weder S2-Rotkopien noch allgemeine Lageberechtigungen.</td></tr>
                <tr><td>Si</td><td>Formale Sichtung aller Ausgänge; Eingang bewerten und Verteiler festlegen; begründete Rückgabe und zweite Sichtung. Es gibt keine Autosichtung.</td></tr>
                <tr><td>S6</td><td>Zusätzlich zu normalen Stabsaufgaben den versionierten Fernmeldeplan erstellen, Wege pflegen und freigeben.</td></tr>
                <tr><td>LdF</td><td>Eingangsweg bestätigen, Rufnamen übersetzen, Ausgangsweg disponieren, Weg-Rückgaben und Melderaufträge führen sowie Nachweisung lesen.</td></tr>
                <tr><td>A/W</td><td>Eingang aufnehmen, Ausgänge tatsächlich befördern, Anhänge bearbeiten, zweite Sichtung und Nachweisung; die designierte erste A/W-Besetzung führt das TBB.</td></tr>
                <tr><td>Technische Administration</td><td>Einsätze, Konten, Matrix, Schichten, Exporte und Systemstatus. Dieser Zugang ist vom Funktionskonto getrennt.</td></tr>
              </tbody>
            </table>
          </div>
          <p>Eine Person darf mehrere Hüte besitzen. Jeder Wechsel ist bewusst
            vorzunehmen; eine Aktion wird stets dem ausgewählten Hut und der
            konkreten Dienstbesetzung zugeordnet.</p>
        </article>

        <article id="vorbereitung" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Einsatz anlegen aktivieren Führungsstellenname Bedarfsträger Leitung Ausgangslage Benutzer Dienstschicht planen besetzen annehmen eröffnen">
          <header><span>05</span><div><p>Vor der ersten Nachricht</p><h2>Einsatz und Dienstbetrieb vorbereiten</h2></div></header>
          <ol class="estab-handbook-steps">
            <li>Unter <a href="<?= $href('incidents') ?>">Einsätze verwalten</a>
              Kennung, Einsatzbezeichnung, Beginn, Bedarfsträger,
              <strong>Namen der Führungsstelle</strong>, verantwortliche
              Leitung, Auftrag und Ausgangslage erfassen und den Einsatz
              aktivieren.</li>
            <li>Unter <a href="<?= $href('users') ?>">Benutzer verwalten</a>
              persönliche Konten für mindestens S2, Si, S6, LdF und A/W
              anlegen beziehungsweise passend zuweisen.</li>
            <li>Unter <a href="<?= $href('admin_command_post') ?>">Dienstschichten</a>
              eine geplante Schicht anlegen und tatsächliche Personen den
              Funktionen zuweisen.</li>
            <li>Jede Person meldet sich selbst an und nimmt ihre Zuweisung im
              <a href="<?= $href('command_post') ?>">Führungsstellenbetrieb</a>
              an.</li>
            <li>Wenn alle Pflichtfunktionen angenommen sind, aktiviert die
              Administration die Schicht. Dabei werden ETB und TBB einmalig
              mit der lokalen Nummer 1 eröffnet.</li>
            <li>S6 erstellt und veröffentlicht den ersten Fernmeldeplan, bevor
              LdF einen Ausgang auf einen verbindlichen Weg disponiert.</li>
          </ol>
          <div class="estab-handbook-callout">
            <strong>Führungsstellenname ist kein Umgebungswert</strong>
            <p>Er gehört zum Einsatz und wird als lokale Anschrift und
              Absendereinheit verwendet. Nach der ersten operativen Eintragung
              ist der bestätigte Name unveränderlich.</p>
          </div>
        </article>

        <article id="vordruck" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Formular Ausfüllhilfe Infoblase amtlich Betreff Rufnummer Zeit Zeichen Vorrang Sofort Blitz Staatsnot Antwort Weiterleitung Gesprächsnotiz Druck">
          <header><span>06</span><div><p>Das digitale amtliche Blatt</p><h2>Nachrichtenvordruck bedienen</h2></div></header>
          <p>Der Bildschirmvordruck folgt dem aktuellen dreiteiligen Raster:
            Fm-Zentrale, Nachricht und Sichter. Zwanzig mit „i“ markierte
            Hilfen erklären die Felder unmittelbar im Formular. Auf kleinen
            Bildschirmen bleibt das Blatt geometrisch erhalten und wird in
            einem beschrifteten Bereich horizontal verschoben.</p>
          <ul>
            <li><strong>Pflichtangaben:</strong> Betreff und Nachrichtentext,
              Anschrift, Absender/Abfassungsangaben sowie Zeichen und Funktion
              werden abhängig vom Arbeitsschritt geprüft.</li>
            <li><strong>Vorrang:</strong> ohne Vorrang, Sofort, Blitz und die
              betriebliche Ergänzung Staatsnot werden eindeutig angezeigt.</li>
            <li><strong>Zeitangaben:</strong> Fachlich editierbare Zeiten können
              leer bleiben, wenn der aktuelle Zeitpunkt gelten soll. Die
              Sichterzeit entsteht ausschließlich beim erfolgreichen
              Sichtungsabschluss.</li>
            <li><strong>Identität:</strong> Aufnahme-, LdF-, Beförderungs-,
              Verfasser- und Sichterzeichen stammen aus der Sitzung und sind
              keine frei einschleusbaren Browserwerte.</li>
            <li><strong>Vorschläge:</strong> Rufname der Gegenstelle und - nur
              bei LdF-Eingangsbearbeitung - Absender verwenden frühere Werte
              desselben Einsatzes als auswählbare Vorschläge. Freie Eingaben
              bleiben möglich.</li>
            <li><strong>Folgenachrichten:</strong> „Antwort“ übernimmt die
              Rufnummer und setzt einen Betreff mit „AW:“; „Weiterleitung“
              beginnt ohne Rufnummer und mit „WG:“.</li>
          </ul>
          <p>Der Vordruck enthält auf Projektvorgabe weder VS-NfD-Aufdruck noch
            Wappen. <a href="<?= $href('messages') ?>">Nachrichtenvordruck öffnen</a>.</p>
        </article>

        <article id="nachrichtenlauf" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Eingang Ausgang Workflow Verfasser Sichter Rückgabe Freigabe LdF disponieren Fernmelder befördern Absender Weg korrigieren">
          <header><span>07</span><div><p>Die zuständige Stelle ist immer eindeutig</p><h2>Nachrichtenlauf nach Rolle</h2></div></header>
          <section class="estab-handbook-workflow" aria-labelledby="outgoing-title">
            <h3 id="outgoing-title">Ausgang</h3>
            <ol>
              <li><strong>Verfasser:</strong> Anschrift, Betreff, Inhalt,
                Abfassungszeit und Verteiler erfassen und zur Sichtung geben.</li>
              <li><strong>Si:</strong> Anschrift, Verfasserzeichen und Funktion
                formal prüfen. Freigeben oder mit Pflichtgrund zurückgeben;
                der Verfasser reicht die Korrektur erneut bei Si ein.</li>
              <li><strong>LdF:</strong> Gegenstellenrufname und vorgesehenen,
                im freigegebenen S6-Plan gültigen Beförderungsweg festlegen.</li>
              <li><strong>A/W:</strong> Nachricht tatsächlich übermitteln und
                wirklichen Weg, Beförderungszeit und Zeichen dokumentieren.
                Ist der Weg nicht nutzbar, geht die Nachricht mit Grund zu LdF
                zurück.</li>
            </ol>
          </section>
          <section class="estab-handbook-workflow" aria-labelledby="incoming-title">
            <h3 id="incoming-title">Eingang</h3>
            <ol>
              <li><strong>A/W:</strong> tatsächliches Medium, Aufnahmezeit,
                Gegenstellenrufname, Betreff und Inhalt erfassen.
                <strong>A/W darf den Absender nicht schreiben.</strong></li>
              <li><strong>LdF:</strong> Gegenstellenrufname in den Absender
                übersetzen und den erfassten Eingangsweg bestätigen. Eine
                Änderung benötigt eine Begründung.</li>
              <li><strong>Si:</strong> Nachricht bewerten, Empfänger festlegen
                und den Vorgang abschließen. Erst dann erhalten fremde
                Empfänger ihre Kopie.</li>
            </ol>
          </section>
          <div class="estab-handbook-flow-summary" aria-label="Kurzform der Nachrichtenwege">
            <span>Ausgang: Verfasser</span><b>→</b><span>Si</span><b>→</b><span>LdF</span><b>→</b><span>A/W</span><b>→</b><span>abgeschlossen</span>
            <span>Eingang: A/W</span><b>→</b><span>LdF</span><b>→</b><span>Si</span><b>→</b><span>Empfänger</span>
          </div>
        </article>

        <article id="anhaenge" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Datei Upload Anlage Anhang JPEG JPG PDF PNG GIF BMP TIFF ZIP DOC XLS ODT TXT XIA AVI 20 MiB MIME Vorschau Download Prüfsumme">
          <header><span>08</span><div><p>Dateien sicher mitführen</p><h2>Anhänge</h2></div></header>
          <p>Öffnen Sie im Nachrichtenvorgang „Anhänge“, wählen Sie eine Datei,
            ergänzen Sie Beschreibung und Kürzel und laden Sie sie hoch. Danach
            wird die fertige Datei ausgewählt und beim Zurückkehren mit dem
            noch offenen Entwurf verbunden. Abbrechen gibt eine nicht
            verwendete Reservierung wieder frei.</p>
          <div class="estab-handbook-facts">
            <div><strong>Erlaubte Endungen</strong><span>JPG, JPEG, TIF, TIFF, GIF, AVI, PNG, BMP, ZIP, PDF, DOC, XLS, ODT, TXT und XIA</span></div>
            <div><strong>Größenlimit</strong><span>Wird direkt am Dateifeld angezeigt; Standard sind 20 MiB je Upload.</span></div>
            <div><strong>Inhaltsprüfung</strong><span>Dateiendung und serverseitig erkannter MIME-Typ müssen zusammenpassen.</span></div>
          </div>
          <p>Eine verknüpfte Anlage übernimmt die Leserechte mindestens einer
            verknüpften Nachricht. Eine noch freie Anlage sehen nur Uploader
            oder ausgewähltes S2, Si beziehungsweise LdF. Download und
            Bildvorschau prüfen die Berechtigung sowie den unveränderlichen
            SHA-256-/Größennachweis erneut.</p>
          <p><a href="<?= $href('attachments') ?>">Anlagenbereich öffnen</a>
            (eine aktive Dienstfunktion ist erforderlich).</p>
        </article>

        <article id="finden" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Suche Filter Meldungsübersicht zweite Sichtung Kategorien global Funktion persönlich gelesen erledigt TBB Nummer Chips Sortierung 10000">
          <header><span>09</span><div><p>Auch bei tausenden Nachrichten</p><h2>Nachrichten finden und ordnen</h2></div></header>
          <p>Die Meldungsübersicht und die zweite Sichtung verwenden dieselbe
            Suchoberfläche. Gesucht werden kann nach lokaler TBB-Nachweisnummer
            oder nach Text aus Rufname, Von, An, Rufnummer, Betreff,
            Nachrichtentext und Verfasserfunktion.</p>
          <ul>
            <li>Richtung, Vorrang und Bearbeitungsstand sind sofort sichtbar.</li>
            <li>Zeitraum, Empfänger, Sortierung und 25/50/100 Treffer je Seite
              stehen unter den weiteren Filtern.</li>
            <li>Aktive Filter erscheinen als einzeln entfernbare Chips.</li>
            <li>Trefferzahl, Bereich, Sortierung und Seite werden ausgeschrieben.</li>
            <li>Fehlt ein automatischer TBB-Nachweis, steht ausdrücklich
              „noch kein TBB-Nachweis“.</li>
          </ul>
          <p><strong>Kategorien:</strong> Globale Kategorien kann nur die feste
            Rotkopiefunktion oder Si verwalten. Funktionskategorien gelten für
            den ausgewählten Hut, persönliche Kategorien nur für das Konto.
            Gelesen und erledigt sind von der Transportstufe getrennte
            Arbeitsmarkierungen.</p>
          <p>S2 öffnet die <a href="<?= $href('message_overview') ?>">Meldungsübersicht</a>;
            Si und A/W erreichen die zweite Sichtung im Nachrichtenmenü.
            Abgeschlossene, lesbare Formulare stehen unter
            <a href="<?= $href('forms') ?>">Vordrucke</a>.</p>
          <h3>Vordrucke und BOS-Info</h3>
          <p>Die Vordruckliste erzeugt den sichtbaren Download aus dem
            Nachrichtendatensatz mit der aktuell ausgelieferten Vorlage. Die
            separat ausgewiesenen Archivangaben beschreiben dagegen die
            unverändert gespeicherte frühere PDF-Datei. Damit bleibt erkennbar,
            ob sich das Darstellungslayout seit der ursprünglichen Ablage
            geändert hat.</p>
          <p>Die öffentliche <a href="<?= $href('bos_info') ?>">BOS-Info</a>
            bündelt Buchstabieralphabet, Karteninformationen,
            Stabzusammensetzung sowie Organisations- und Rufnamenschemata. Sie
            ist eine Nachschlagehilfe; einsatzbezogene Entscheidungen und
            Nachweise werden weiterhin im zuständigen operativen Bereich
            dokumentiert.</p>
        </article>

        <article id="etb" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Einsatztagebuch Fb Fü 2 Aufgabe Befehl Erledigung Kräfteanforderung wichtig Ereigniszeit Erfassungszeit Bezug Referenz Korrektur Anlage append-only">
          <header><span>10</span><div><p>Chronologische Einsatzdokumentation</p><h2>Einsatztagebuch (ETB)</h2></div></header>
          <p>Alle ausgewählten aktiven Funktionen dürfen das ETB lesen. Manuell
            schreibt ausschließlich die zuerst bestimmte angenommene
            ETB-Besetzung, ersatzweise die bestimmte S2-Besetzung. Einträge
            werden niemals überschrieben oder gelöscht.</p>
          <ul>
            <li>Fachliche Ereigniszeit und serverseitige Erfassungszeit bleiben getrennt.</li>
            <li>Arten: ohne Kennzeichnung, A Aufgabe, B Befehl/Auftrag,
              E Erledigung, K Kräfteanforderung und W sehr wichtig.</li>
            <li>Eine optionale Bearbeitungszuordnung friert Funktion, Rolle,
              Name und Kürzel als Suchhilfe ein.</li>
            <li>Ein Bezug verwendet ausschließlich die positive lokale Nummer
              eines vorhandenen ETB-Eintrags desselben Einsatzes.</li>
            <li>Optional kann genau eine noch freie Einsatzanlage verbunden
              werden; eStab erzeugt dafür eine eindeutige ETB-Anlagennummer.</li>
            <li>Fehler werden über „Korrektur“ mit Begründung und
              unveränderlichem Bezug auf das Original richtiggestellt.</li>
          </ul>
          <p>Volltext, Art, Nummer/Bezug, Anlage und Zuordnung sind kombinierbar;
            Referenzen lassen sich vorwärts oder rückwärts verfolgen.
            <a href="<?= $href('etb') ?>">ETB öffnen</a>.</p>
        </article>

        <article id="ttb" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Technisches Betriebsbuch Fb Fü 44 Fernmelder Betrieb Personal Dienstübergabe Kanal Rufgruppe Bedienung Nachricht Störung Quittung Empfänger Aushändigung Korrektur">
          <header><span>11</span><div><p>Chronologischer Fernmeldebetrieb</p><h2>Technisches Betriebsbuch (TBB)</h2></div></header>
          <p>Alle ausgewählten aktiven Funktionen dürfen das TBB lesen. Die
            zuerst bestimmte angenommene A/W-Besetzung führt es manuell. Jeder
            Eintrag soll auch ohne Anlage in Grundzügen verständlich sein.</p>
          <div class="estab-handbook-facts">
            <div><strong>Betrieb, Personal, Übergabe</strong><span>Aufnahme, Ende, Bereitschaft, Besetzung, Ablösung und Dienstübergabe.</span></div>
            <div><strong>Kanal, Rufgruppe, Bedienung</strong><span>Betriebsart und Wechsel mit bisherigem sowie neuem Wert.</span></div>
            <div><strong>Betriebsablauf, Ereignis, Störung</strong><span>Vorgänge, Unterbrechungen und ihre Beseitigung.</span></div>
            <div><strong>Quittung und Aushändigung</strong><span>Empfang, Empfänger, Zeitpunkt und ausführende Person.</span></div>
          </div>
          <p>„Nachricht von/an“ kann nicht manuell als Primärart gewählt werden:
            abgeschlossene Beförderungen übernimmt der verbindliche
            Nachrichtenworkflow automatisch und genau einmal. Auch das TBB ist
            append-only; Fehler erhalten einen begründeten Korrektureintrag.
            <a href="<?= $href('ttb') ?>">TBB öffnen</a>.</p>
        </article>

        <article id="fernmeldeplan" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="S6 Fernmeldeplan Version Entwurf Weg freigeben disponieren Kanal Funk Telefon E-Mail gültig">
          <header><span>12</span><div><p>Verbindliche Wege statt Freitext</p><h2>S6-Fernmeldeplan</h2></div></header>
          <p>S6 erstellt im <a href="<?= $href('command_post') ?>">Führungsstellenbetrieb</a>
            eine Planversion, beschreibt die vorgesehenen Wege und gibt den
            Plan bewusst frei. Freigegebene Versionen sind unveränderlich;
            Änderungen erfolgen über eine neue Version.</p>
          <p>Alle aktiven Funktionen können den gültigen Plan lesen. LdF darf
            einen Ausgang nur auf einen zum Zeitpunkt der Disposition gültigen
            Planweg legen. Die spätere A/W-Beförderung dokumentiert dennoch
            den tatsächlich verwendeten Weg. Plan, Disposition und realer
            Transport bleiben damit voneinander unterscheidbar.</p>
        </article>

        <article id="melder" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Melder Auftrag A/W LdF Empfänger Zustellung Rücknachricht Rückweg Rückkehr">
          <header><span>13</span><div><p>Persönlich nachvollziehbare Botengänge</p><h2>Melderaufträge</h2></div></header>
          <p>Ein Melder ist keine zusätzliche globale Rolle. LdF beauftragt ein
            konkret angemeldetes A/W-Konto mit angenommener Besetzung. Die
            beauftragte Person übernimmt den Lauf persönlich und dokumentiert
            tatsächlichen Empfänger, Zustellung, eine mögliche Rücknachricht,
            Rückweg und Rückkehr.</p>
          <p>Während eines offenen Auftrags bleibt die Verantwortung an diese
            Person gebunden. Erst LdF quittiert den vollständigen Abschluss.
            Auftrag, Übergaben und Statuswechsel erscheinen im
            einsatzgebundenen Nachweis und im PDF-Einsatzdossier.</p>
        </article>

        <article id="uebergabe" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Dienstschicht Übergabe Nachfolge bestätigen stornieren Ablösung Abschluss Einsatz beenden Legal Hold Aufbewahrung zehn Jahre">
          <header><span>14</span><div><p>Verantwortung lückenlos weitergeben</p><h2>Dienstübergabe und Einsatzabschluss</h2></div></header>
          <p>Eine laufende Schicht kann um eine noch unbesetzte Funktion ergänzt
            werden; wirksam wird die Ergänzung erst nach persönlicher Annahme.
            Bereits besetzte Nicht-A/W-Funktionen werden nicht still ersetzt.</p>
          <ol class="estab-handbook-steps">
            <li>Eine angenommene Besetzung der aktiven Schicht initiiert die
              Übergabe mit einer Zusammenfassung.</li>
            <li>Eine persönlich angemeldete angenommene Besetzung der
              Nachfolgeschicht bestätigt die Übernahme.</li>
            <li>eStab wechselt die Schicht atomar und schreibt Übergabe und
              Übernahme in ETB und TBB. Fehlanforderungen bleiben begründet
              storniert nachvollziehbar.</li>
          </ol>
          <p>Vor dem formalen Einsatzabschluss müssen unter anderem offene
            Nachrichten, Planentwürfe, Melderläufe und Übergaben geklärt sowie
            die letzte Schicht geschlossen sein. Der Abschluss erzeugt letzte
            Buchzeilen und setzt eine Mindestaufbewahrung von zehn Jahren.
            Eine gesetzte Aufbewahrungs- oder Legal-Hold-Sperre darf nicht
            durch das Löschen eines Exportes umgangen werden.</p>
          <div class="estab-handbook-callout"><strong>Deaktivieren ist nicht abschließen</strong>
            <p>Ein offener Einsatz kann vorübergehend deaktiviert und später
              wieder aktiviert werden. Der formale Abschluss ist dagegen
              unwiderruflich und verlangt tatsächliches Ende,
              Abschlussvermerk und ausdrückliche Bestätigung.</p></div>
        </article>

        <article id="export" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="PDF Einsatzdossier ETB TBB Vordruck Anlagen sichtbar eingebettet Original ZIP CSV Manifest Prüfsumme herunterladen löschen 50 MiB">
          <header><span>15</span><div><p>Lesbar und maschinenlesbar sichern</p><h2>Export und PDF-Einsatzdossier</h2></div></header>
          <div class="estab-handbook-compare">
            <section><h3>PDF-Einsatzdossier</h3><p>Für Lesen, Übergabe und
              Ausdruck eines ausgewählten aktiven oder historischen Einsatzes.
              Neun Bereiche sind wählbar: ETB, TBB,
              Nachrichtenvordrucke, Anlagen, Nachrichtenereignisse,
              Dienstbetrieb, S6-Pläne, Melderläufe und Betriebsereignisse.
              ETB/TBB können einsatzweit oder für genau eine Schicht ausgegeben
              werden; der Schichtfilter verändert nur diese beiden Bücher.
              Ein offener Einsatz wird als vorläufig gekennzeichnet.</p><a href="<?= $href('incident_pdf') ?>">PDF-Dossier erstellen</a></section>
            <section><h3>ZIP-Einsatzexport</h3><p>Für maschinelle Auswertung und
              Archivkontrolle des vollständigen aktuellen Tabellenstandes.
              UTF-8-CSV-Dateien, Manifest und Prüfsummen werden ohne einzelne
              Einsatzauswahl zusammengefasst. Bereits erzeugte Exporte können
              angesehen, heruntergeladen oder einzeln gelöscht werden; die
              Einsatzdaten selbst bleiben bestehen.</p><a href="<?= $href('exports') ?>">Exporte verwalten</a></section>
          </div>
          <p>Im PDF erscheinen JPEG, PNG, GIF und BMP sichtbar, PDF-Anlagen
            seitenweise einschließlich Annotationen und geeigneter Text als
            durchsuchbarer Inhalt. Andere Formate erhalten eine eindeutige
            Hinweisseite. Unabhängig von der Vorschau bleibt jedes Original
            bytegleich eingebettet. Für alle Originalanlagen eines Dossiers
            gilt eine Gesamtgrenze von 50 MiB; je PDF-Anlage gelten höchstens
            100 Seiten, insgesamt 200 sichtbare Anlagenseiten. Eine beschädigte
            oder überlimitierte darstellbare Datei bricht den Export ab, statt
            still ausgelassen zu werden.</p>
          <div class="estab-handbook-callout"><strong>Export ist kein Backup</strong>
            <p>Ein Export ersetzt niemals das Vollbackup von Datenbank,
              Anhangs-, Vordruck- und Exportvolumen.</p></div>
        </article>

        <article id="administration" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Basic Auth Admin Benutzer sperren Passwort zurücksetzen Matrix Zähler PDF Markierung Systemstatus">
          <header><span>16</span><div><p>Technische Maßnahmen mit eigenem Zugang</p><h2>Administration</h2></div></header>
          <p>Die Administration verwendet HTTP Basic Auth und ein eigenes
            technisches Kennwort. Diese Anmeldung ist kein eStab-Funktionskonto
            und verleiht keinen operativen Hut. Außerhalb eines isolierten
            Testhosts darf sie ausschließlich über TLS erreichbar sein.</p>
          <div class="estab-handbook-admin-grid">
            <a href="<?= $href('incidents') ?>"><strong>Einsätze</strong><span>Anlegen, aktivieren, deaktivieren und formal abschließen</span></a>
            <a href="<?= $href('users') ?>"><strong>Benutzer</strong><span>Anlegen, zuweisen, sperren, entsperren und Kennwort zurücksetzen</span></a>
            <a href="<?= $href('admin_command_post') ?>"><strong>Führungsstelle</strong><span>Schichten, Besetzungen, Übergaben und Abschlussblocker</span></a>
            <a href="<?= $href('matrix') ?>"><strong>Empfängermatrix</strong><span>Exakt 5 × 4 Positionen; S2 bleibt Rotkopieziel, Autosichtung bleibt aus</span></a>
            <a href="<?= $href('counter') ?>"><strong>Nachrichtenzähler</strong><span>Nach dokumentiertem Rückfallbetrieb sicher erhöhen</span></a>
            <a href="<?= $href('form_reset') ?>"><strong>Vordruckmarkierung</strong><span>Abgeschlossene Formulare erneut erzeugen lassen</span></a>
            <a href="<?= $href('incident_pdf') ?>"><strong>PDF-Dossier</strong><span>Einsatzgebundene lesbare Gesamtdokumentation</span></a>
            <a href="<?= $href('exports') ?>"><strong>Einsatzexporte</strong><span>Erstellen, prüfen, herunterladen und einzeln löschen</span></a>
            <a href="<?= $href('system_status') ?>"><strong>Systemstatus</strong><span>Laufzeit, Datenbank, Speicher und Konfiguration prüfen</span></a>
          </div>
          <p>Sperren, Funktionsneuzuweisung und Kennwortreset widerrufen aktive
            eStab-Sitzungen sofort. Ein neues Kennwort wird zweimal eingegeben;
            Entsperren meldet das Konto nicht automatisch an.</p>
          <p><a href="<?= $href('admin') ?>">Administrationsübersicht öffnen</a>.</p>
        </article>

        <article id="betrieb" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Podman Docker Compose Container Installation Secrets Port Health Backup Restore Upgrade Synology TLS Reverse Proxy Volume">
          <header><span>17</span><div><p>Für Betreiberinnen und Betreiber</p><h2>Installation und sicherer Betrieb</h2></div></header>
          <p>Eine Neuinstallation wird als Compose-Stack mit MariaDB,
            Migrationsdienst, Admin-Initialisierung und Webanwendung betrieben.
            Datenbank- und Administrationskennwörter liegen in privaten
            Secret-Dateien, nicht im Browser oder im Image.</p>
          <pre><code>cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 &gt; secrets/db_password.txt
openssl rand -base64 36 &gt; secrets/db_root_password.txt
openssl rand -base64 36 &gt; secrets/admin_password.txt
chmod 0600 secrets/*.txt
podman compose config
podman compose build --pull migrate app
podman compose up -d
curl --fail http://127.0.0.1:8080/health.php</code></pre>
          <ul>
            <li>Der Standard bindet nur an <code>127.0.0.1:8080</code>. Für
              Netzzugriff gehört ein TLS-terminierender Reverse Proxy davor.</li>
            <li><a href="<?= $href('health') ?>">health.php</a> muss HTTP 200
              und <code>"status":"ready"</code> liefern.</li>
            <li>Vor jeder neuen Version und vor einer Wiederherstellung ist ein
              geprüftes Vollbackup Pflicht. Das Repository stellt dafür
              <code>deploy/registry/backup.sh</code>,
              <code>verify-backup.sh</code> und den bewusst destruktiv
              gesicherten <code>restore.sh</code> bereit.</li>
            <li><code>podman compose down</code> behält die Volumes.
              <strong><code>down --volumes</code> löscht persistente Daten</strong>
              und darf nur nach geprüftem Backup und bewusster Zielkontrolle
              verwendet werden.</li>
            <li>Für Synology oder andere Architekturen darf nur ein sichtbares,
              unveränderliches und geprüftes Multiarch-Release verwendet
              werden. Ein bloßes Registry-Objekt oder <code>latest</code> ist
              kein freigegebener eStab-Stand.</li>
          </ul>
        </article>

        <article id="probleme" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Fehler Hilfe Unauthorized keine Anmeldung Passwort Konto gesperrt Einsatz inaktiv Schicht Hut Upload Planweg PDF Grenze Logs">
          <header><span>18</span><div><p>Ursache gezielt eingrenzen</p><h2>Probleme lösen</h2></div></header>
          <div class="estab-handbook-troubleshooting">
            <details><summary>Administration zeigt sofort „Unauthorized“</summary><p>Das ist die Browserabfrage für HTTP Basic Auth. Manche integrierten Browser zeigen sie nicht zuverlässig; verwenden Sie einen normalen Browser. Benutzername und Kennwort stammen aus dem technischen Admin-Secret, nicht aus einem Funktionskonto.</p></details>
            <details><summary>Die Kontoanmeldung funktioniert nicht</summary><p>Prüfen Sie Name, Kürzel, Funktion und Kennwort. Ein administrativ gesperrtes Konto oder eine widerrufene Sitzung muss in der Benutzerverwaltung geklärt werden. Legen Sie kein zweites Konto mit demselben Kürzel an.</p></details>
            <details><summary>Es ist keine operative Eingabe möglich</summary><p>Prüfen Sie nacheinander: aktiver Einsatz, bestätigter Führungsstellenname, aktive Dienstschicht, persönlich angenommene Zuweisung und ausgewählter Hut. Die Führungsstellenansicht zeigt den fehlenden Schritt.</p></details>
            <details><summary>Ein Ausgang erreicht A/W nicht</summary><p>Der Ausgang muss zuerst Si und danach LdF durchlaufen. LdF benötigt einen gültigen freigegebenen S6-Planweg. Eine Rückgabe enthält einen Pflichtgrund und muss in der zuständigen Stufe bearbeitet werden.</p></details>
            <details><summary>Eine Anlage lässt sich nicht hochladen</summary><p>Prüfen Sie die am Dateifeld angezeigte Grenze, erlaubte Endung und echten Inhaltstyp. Eine lediglich umbenannte Datei wird abgewiesen. Brechen Sie einen nicht mehr benötigten Anhangsvorgang sauber ab.</p></details>
            <details><summary>Der PDF-Export bricht bei Anlagen ab</summary><p>Beschädigte, verschlüsselte oder über den Sicherheitsgrenzen liegende PDF-/Bildanlagen werden fail-closed abgewiesen. Prüfen Sie Format, Einzeldatei und die 50-MiB-Gesamtsumme; der Systemstatus und die App-Logs liefern den technischen Kontext.</p></details>
          </div>
          <p>Bei technischen Fehlern zuerst den
            <a href="<?= $href('system_status') ?>">Systemstatus</a> sowie
            <code>podman compose ps</code> und
            <code>podman compose logs --tail=100 db migrate admin-auth-init app</code>
            prüfen. Keine Kennwörter oder vollständigen Einsatzdaten in
            öffentliche Fehlermeldungen kopieren.</p>
        </article>

        <article id="kurzreferenz" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Kurzreferenz URL Glossar Statuscode Zeitformat taktische Zeit Nachweisung BOS Info Sicherheit">
          <header><span>19</span><div><p>Die wichtigsten Ziele auf einen Blick</p><h2>Kurzreferenz</h2></div></header>
          <div class="estab-handbook-table-wrap" role="region" aria-label="Bereichsreferenz" tabindex="0">
            <table>
              <thead><tr><th>Bereich</th><th>Wer nutzt ihn?</th><th>Zweck</th></tr></thead>
              <tbody>
                <tr><td><a href="<?= $href('messages') ?>">Nachrichtenvordruck</a></td><td>ausgewählter aktiver Hut</td><td>Rollenabhängiger Nachrichtenlauf</td></tr>
                <tr><td><a href="<?= $href('command_post') ?>">Führungsstellenbetrieb</a></td><td>angemeldetes Konto</td><td>Hut annehmen/wählen, S6 und Melder</td></tr>
                <tr><td><a href="<?= $href('message_overview') ?>">Meldungsübersicht</a></td><td>S2/Stab</td><td>Einsatzweite Suche und Lageübersicht</td></tr>
                <tr><td><a href="<?= $href('tracking') ?>">Nachweisung</a></td><td>LdF oder A/W</td><td>Aufnahme und tatsächliche Beförderung</td></tr>
                <tr><td><a href="<?= $href('etb') ?>">ETB</a> / <a href="<?= $href('ttb') ?>">TBB</a></td><td>alle lesen; designierte Besetzung schreibt</td><td>Append-only Einsatz- und Betriebsbücher</td></tr>
                <tr><td><a href="<?= $href('bos_info') ?>">BOS-Info</a></td><td>öffentlich</td><td>Buchstabier-, Rufnamen- und Karteninformationen</td></tr>
                <tr><td><a href="<?= $href('admin') ?>">Administration</a></td><td>technischer Basic-Auth-Zugang</td><td>Einsatz-, Konto-, Schicht- und Datenverwaltung</td></tr>
              </tbody>
            </table>
          </div>
          <h3>Begriffe</h3>
          <dl class="estab-handbook-glossary">
            <div><dt>Hut</dt><dd>Aktuell ausgewählte, persönlich angenommene Dienstbesetzung.</dd></div>
            <div><dt>LdF</dt><dd>Leitung der Fernmeldebetriebsstelle.</dd></div>
            <div><dt>A/W</dt><dd>Aufnahme und Weitergabe in der Fernmeldebetriebsstelle.</dd></div>
            <div><dt>Si</dt><dd>Sichterfunktion für formale Prüfung und Verteilung.</dd></div>
            <div><dt>Nachweisung</dt><dd>Einsatzlokaler Nachweis von Aufnahme und tatsächlicher Beförderung; Nummern stammen aus dem TBB.</dd></div>
            <div><dt>Taktische Zeit</dt><dd>Je nach Feld Uhrzeit oder Datum/Uhrzeit in der vom Formular bezeichneten Form; Eingabehinweise direkt am Feld beachten.</dd></div>
            <div><dt>Append-only</dt><dd>Gespeicherte Nachweise werden nicht verändert; eine Berichtigung ist ein neuer verknüpfter Korrektureintrag.</dd></div>
          </dl>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>Nachweisgrenze</strong>
            <p>Auditkette, Prüfsummen und unveränderliche Einträge erhöhen die
              Nachvollziehbarkeit. Sie sind keine qualifizierte elektronische
              Signatur und ersetzen keine vorgeschriebene organisatorische
              Freigabe.</p>
          </div>
        </article>
      </div>
    </div>
  </main>

  <footer class="estab-handbook-footer">
    <p><strong>eStab Web-Handbuch <?= estab_auth_html($handbookVersion) ?></strong>
      · Stand <?= estab_auth_html($handbookUpdated) ?></p>
    <p><a href="<?= $href('home') ?>">Zur Übersicht</a> ·
      <a href="#handbook-content">Zum Seitenanfang</a></p>
  </footer>
</body>
</html>
