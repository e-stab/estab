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
    'self_registration' => estab_application_url(
        '4fadm/self_registration.php'
    ),
    'password_policy' => estab_application_url('4fadm/password_policy.php'),
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
$handbookUpdated = '2. August 2026';

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
        <a href="#nachrichtenlauf"><strong>Nachricht bearbeiten</strong><span>Fernmelder, Si, LdF oder Verfasser</span></a>
        <a href="#etb"><strong>ETB führen</strong><span>Ereignisse, Bezüge und Korrekturen</span></a>
        <a href="#ttb"><strong>TBB führen</strong><span>Fernmeldebetrieb dokumentieren</span></a>
        <a href="#vorbereitung"><strong>Einsatz vorbereiten</strong><span>Administration und optionale Zugangsgruppen</span></a>
        <a href="#export"><strong>Dokumentation ausgeben</strong><span>PDF-Dossier oder ZIP-Export</span></a>
        <a href="#probleme"><strong>Problem lösen</strong><span>Login, Einsatz, Zugang oder Upload</span></a>
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
              eingerichteter Fernmeldebetriebsstelle ausgelegt. Die Funktionen
              S2, Si, S6, LdF und Fernmelder müssen deshalb für den
              Dienstbetrieb besetzt sein.</p>
          </div>
          <p>Die technische Umsetzung ersetzt weder Ausbildung noch örtliche
            Stabsordnung oder eine erforderliche formale THW-Freigabe. Die
            Unterschriftszeilen in PDF-Ausgaben sind für eine manuelle
            Zeichnung vorgesehen und stellen keine digitale Signatur dar.
            Eine beherrschte Papier-Rückfallebene bleibt erforderlich.</p>
        </article>

        <article id="schnellstart" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="erste Schritte Login anmelden feste Funktion Einsatz Berechtigungsmodus Streng Locker Strict Loose optionale Zugangsschicht">
          <header><span>02</span><div><p>Der sichere Einstieg</p><h2>In 5 Minuten startklar</h2></div></header>
          <ol class="estab-handbook-steps">
            <li><strong>Mit bestehendem Konto anmelden.</strong> Öffnen Sie die
              <a href="<?= $href('login') ?>">Anmeldeseite</a> und verwenden
              Sie Name, Kürzel, Funktion und Kennwort Ihres Funktionskontos.</li>
            <li><strong>Feste Funktion prüfen.</strong> Die Kontofunktion und die
              daraus serverseitig abgeleitete Rolle stehen nach der Anmeldung
              fest und werden bei jeder Handlung nachgewiesen. Eine zusätzliche
              Hutauswahl gibt es nicht.</li>
            <li><strong>Status prüfen.</strong> Oben müssen Ihr Name, feste
              Funktion, Rolle, Führungsstellenname, aktiver Einsatz und dessen
              Berechtigungsmodus erscheinen.</li>
            <li><strong>Arbeitsbereich öffnen.</strong> Wechseln Sie über die
              obere Navigation zum Nachrichtenvordruck, ETB, TBB oder in den
              für Ihre Kontofunktion freigegebenen Spezialbereich.</li>
          </ol>
          <div class="estab-handbook-callout estab-handbook-callout-danger">
            <strong>Roter Warnhinweis</strong>
            <p>Ohne aktiven Einsatz oder bestätigten Führungsstellennamen sind
              operative Eingaben absichtlich gesperrt. Eine aktive Schicht ist
              nicht erforderlich. Wenden Sie sich bei einem deaktivierten
              Gruppenzugang oder gesperrten Konto an die Administration.</p>
          </div>
          <h3>Bestehendes oder neues Konto?</h3>
          <p>Im Regelbetrieb legt die zuständige Stelle Konten in der
            Benutzerverwaltung an. Wählen Sie dann immer „Mit bestehendem
            Konto anmelden“: Ein unbekanntes Kürzel erzeugt dabei bewusst kein
            neues Konto. „Neues Konto anlegen“ erscheint nur, wenn die
            öffentliche Selbstregistrierung ausdrücklich freigeschaltet wurde.
            Die zuständige Stelle kann diese Freigabe in der Administration
            dauerhaft oder für einen festen Zeitraum erteilen und jederzeit
            vorzeitig beenden. Nach Ablauf wird auch ein bereits geöffnetes
            Formular beim Absenden sicher abgewiesen. Vor dem Aktivieren muss
            sie bestätigen, dass die Anmeldeseite nur in einem kontrollierten
            Netz und unter Aufsicht erreichbar ist. Während der Freigabe kann
            jede erreichende Person jede angebotene aktive Funktion auswählen.
            Sie verlangt Name, ein eindeutiges Kürzel, die zugeteilte Funktion
            und zweimal dasselbe Kennwort. Die dabei angezeigte zentrale
            Kennwortrichtlinie gilt ebenso für administrativ angelegte Konten
            und Kennwortresets. Die feste Kontofunktion bleibt in beiden
            Berechtigungsmodi die Identität und Nachweisgrundlage des Kontos;
            nur im ausdrücklich gewählten Modus „Locker“ können die dafür
            vorgesehenen Schreibzuständigkeiten in Nachrichtenworkflow,
            ETB/TBB, S6-Planung und Melderlauf einsatzweit erweitert sein.</p>
        </article>

        <article id="navigation" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Menü obere Leiste Sidebar Status Berechtigungsmodus Streng Locker Benutzer online inaktiv logout abmelden 15 Minuten 12 Stunden ungespeichert">
          <header><span>03</span><div><p>Überall dieselbe Orientierung</p><h2>Navigation, Status und Sitzung</h2></div></header>
          <p>Die obere Leiste zeigt immer, als wer Sie angemeldet sind. Sie
            enthält Kürzel, feste Funktion und Rolle sowie den aktiven Einsatz,
            seinen Berechtigungsmodus und die Führungsstelle. Der sichtbare
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
          data-handbook-keywords="S1 S2 S3 S4 S5 S6 Fachberater Si Sichter LdF Leiter Fernmeldebetrieb Fernmelder Aufnahme Weitergabe ETB Admin feste Funktion Rolle Rechte Berechtigungsmodus Streng Locker Strict Loose">
          <header><span>04</span><div><p>Verantwortung statt bloßer Menüfreigabe</p><h2>Rollen und Rechte</h2></div></header>
          <p>Jedes Konto besitzt genau eine feste Funktion; die Rolle wird
            serverseitig daraus abgeleitet. Beide Werte bleiben unveränderliche
            Identitäts- und Nachweismerkmale. Ob sie zusätzlich die dafür
            vorgesehenen operativen Schreibzuständigkeiten in
            Nachrichtenworkflow, ETB/TBB, S6-Planung und Melderlauf begrenzen,
            wird pro Einsatz festgelegt. Für
            operative Arbeit muss der Einsatz aktiv und offen sein. Eine
            Dienstschicht ist nicht erforderlich; eine optionale
            Zugangsschicht kann zugeordnete Konten jedoch gemeinsam sperren.</p>
          <div class="estab-handbook-facts">
            <div><strong>Streng (STRICT)</strong><span>Empfohlener Standard für
              bestehende und neue Einsätze. Nur die jeweils fest zugewiesene
              Funktion und Rolle darf den dazugehörigen operativen
              Arbeitsschritt schreiben.</span></div>
            <div><strong>Locker (LOOSE)</strong><span>Ein gültig angemeldetes,
              aktives und ungesperrtes Funktionskonto darf dafür vorgesehene
              Schreibzuständigkeiten im Nachrichtenlauf, in ETB/TBB sowie in
              S6-Planung und Melderlauf übernehmen. Die tatsächliche feste
              Kontofunktion wird weiterhin gespeichert.</span></div>
          </div>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>Locker bedeutet nicht rechtefrei</strong>
            <p>Nur die feste Funktions-/Rollenzuordnung der ausdrücklich
              angebotenen Schreibschritte wird gelockert. Reine Übersichten,
              Nachweisung, Archive der zweiten Sichtung, Kategorien und die
              technische Administration bleiben rollenstreng. Ein Konto erhält
              höchstens die Objektansicht, die für einen ausgewählten
              Schreibschritt erforderlich ist; allgemeine Leserechte werden
              nicht erweitert.</p>
          </div>
          <p>In beiden Modi bleiben Anmeldung, aktives und ungesperrtes Konto,
            gegebenenfalls aktiver Gruppenzugang, Einsatzgrenze, CSRF-Schutz,
            Pflichtfelder, Richtung und Bearbeitungsstand, Objektsperren,
            Unveränderlichkeit, Nachweise, Auditkette und Aufbewahrung
            verbindlich.</p>
          <div class="estab-handbook-table-wrap" role="region" aria-label="Rollenübersicht" tabindex="0">
            <table>
              <thead><tr><th>Rolle/Funktion</th><th>Hauptaufgaben in eStab</th></tr></thead>
              <tbody>
                <tr><td>S1-S6, Fachberatung, Verbindung</td><td>Nachrichten lesen, eigene Ausgänge verfassen, antworten, weiterleiten, Gesprächsnotizen sowie gelesen/erledigt und Kategorien pflegen.</td></tr>
                <tr><td>S2</td><td>Verbindliche Lage-/Dokumentationsfunktion und einziges Rotkopieziel; Meldungsübersicht und ETB-Schreibrecht.</td></tr>
                <tr><td>ETB</td><td>Eigenständige Buchführungsfunktion. Daraus folgen weder S2-Rotkopien noch allgemeine Lageberechtigungen.</td></tr>
                <tr><td>Si</td><td>Formale Sichtung aller Ausgänge; Eingang bewerten und Verteiler festlegen; begründete Rückgabe und zweite Sichtung. Es gibt keine Autosichtung.</td></tr>
                <tr><td>S6</td><td>Zusätzlich zu normalen Stabsaufgaben den versionierten Fernmeldeplan erstellen, Wege pflegen und freigeben.</td></tr>
                <tr><td>LdF</td><td>Eingangsweg bestätigen, Rufnamen übersetzen, Ausgangsweg disponieren, Weg-Rückgaben und Melderaufträge führen sowie Nachweisung lesen.</td></tr>
                <tr><td>Fernmelder</td><td>Eingang aufnehmen, Ausgänge tatsächlich befördern, Anhänge bearbeiten, zweite Sichtung und Nachweisung sowie das TBB führen.</td></tr>
                <tr><td>Technische Administration</td><td>Einsätze, Konten, Matrix, optionale Zugangsschichten, Exporte und Systemstatus. Dieser Zugang ist vom Funktionskonto getrennt.</td></tr>
              </tbody>
            </table>
          </div>
          <p>Die Tabelle beschreibt die fachliche Regelzuständigkeit. Im Modus
            „Locker“ kann ein anderes Funktionskonto einen dafür freigegebenen
            operativen Schreibschritt übernehmen; eStab protokolliert dabei das
            tatsächlich handelnde Konto und dessen feste Funktion.</p>
          <p>Eine Funktionsänderung ist ausschließlich in der
            Benutzerverwaltung möglich und beendet eine bestehende Sitzung.
            Organisatorische Funktionskombinationen werden mit getrennten
            persönlichen Konten abgebildet, nicht durch einen Sitzungswechsel.</p>
        </article>

        <article id="vorbereitung" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Einsatz anlegen aktivieren Berechtigungsmodus Streng Locker Strict Loose Führungsstellenname Bedarfsträger Leitung Ausgangslage Benutzer optionale Zugangsschicht Gruppe aktivieren deaktivieren">
          <header><span>05</span><div><p>Vor der ersten Nachricht</p><h2>Einsatz und Zugänge vorbereiten</h2></div></header>
          <ol class="estab-handbook-steps">
            <li>Unter <a href="<?= $href('incidents') ?>">Einsätze verwalten</a>
              Kennung, Einsatzbezeichnung, Beginn, Bedarfsträger,
              <strong>Namen der Führungsstelle</strong>, verantwortliche
              Leitung, Auftrag und Ausgangslage erfassen, den
              Berechtigungsmodus wählen und den Einsatz aktivieren. Ohne
              bewusste Abweichung gilt „Streng“.</li>
            <li>Unter <a href="<?= $href('users') ?>">Benutzer verwalten</a>
              persönliche Konten für mindestens die Funktionen S2, Si, S6,
              LdF und Fernmelder
              anlegen beziehungsweise passend zuweisen.</li>
            <li>Optional unter <a href="<?= $href('admin_command_post') ?>">Zugangsschichten</a>
              Gruppen anlegen, Konten zuordnen und Zugänge gemeinsam
              aktivieren. Unzugeordnete Konten bleiben zugelassen.</li>
            <li>Jede Person meldet sich selbst an. Bei mehreren
              Gruppenzuordnungen genügt für den Kontozugang eine aktive
              Zugangsschicht; daraus entsteht kein Fach- oder Schreibrecht.</li>
            <li>S6 erstellt und veröffentlicht den ersten Fernmeldeplan, bevor
              LdF einen Ausgang auf einen verbindlichen Weg disponiert.</li>
          </ol>
          <div class="estab-handbook-callout">
            <strong>Zugangsschicht ist keine Berechtigungsschicht</strong>
            <p>Sie verändert weder Funktion noch Rolle und sperrt keine Eingabe,
              wenn gar keine Schicht angelegt wurde. Aktivieren meldet niemanden
              an; Deaktivieren kann Gruppensitzungen widerrufen. Eine manuelle
              Kontosperre bleibt unabhängig und hat Vorrang.</p>
          </div>
          <div class="estab-handbook-callout">
            <strong>Berechtigungsmodus gehört zum Einsatz</strong>
            <p>Er wird weder über eine Umgebungsvariable noch über Konto oder
              Zugangsschicht festgelegt. Die Administration darf ihn für einen
              offenen Einsatz ändern. Der Wechsel gilt sofort, wird
              revisionsgeschützt protokolliert und erfordert beim Wechsel auf
              „Locker“ eine ausdrückliche Bestätigung.</p>
          </div>
          <div class="estab-handbook-callout">
            <strong>Führungsstellenname ist kein Umgebungswert</strong>
            <p>Er gehört zum Einsatz und wird als lokale Anschrift und
              Absendereinheit verwendet. Nach der ersten operativen Eintragung
              ist der bestätigte Name unveränderlich.</p>
          </div>
        </article>

        <article id="vordruck" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Formular Ausfüllhilfe Infoblase amtlich Betreff Rufnummer Zeit Zeichen Vorrang Sofort Blitz Staatsnot Antwort Weiterleitung Gesprächsnotiz Druck Anlage Anhang Badge Stationsleiste Laufzeit">
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
            <li><strong>Vorrang:</strong> Sofort, Blitz und Staatsnot werden
              direkt im Vorrangfeld des Nachrichtenvordrucks ausgewählt und
              dort eindeutig markiert. „Keine“ setzt das Feld zurück und
              bedeutet, dass kein besonderer Vorrang gilt. Staatsnot darf nur
              auf ausdrückliche Weisung einer hierzu berechtigten Stelle
              verwendet werden.</li>
            <li><strong>Zeitangaben:</strong> Fachlich editierbare Zeiten können
              leer bleiben, wenn der aktuelle Zeitpunkt gelten soll. Die
              Sichterzeit entsteht ausschließlich beim erfolgreichen
              Sichtungsabschluss.</li>
            <li><strong>Identität:</strong> Aufnahme-, LdF-, Beförderungs-,
              Verfasser- und Sichterzeichen stammen aus der Sitzung und sind
              keine frei einschleusbaren Browserwerte.</li>
            <li><strong>Bearbeitungsweg:</strong> Das Band oberhalb des
              Vordrucks markiert die aktuelle Station, zeigt für jede
              abgeschlossene Station die Laufzeit bis zum nächsten Übergang
              und bildet Rückgaben samt Grund als zusätzliche Runde ab. Bei
              einem neuen Entwurf erscheinen nur die geplanten Stationen ohne
              erfundene Zeiten.</li>
            <li><strong>Vorschläge:</strong> Rufname der Gegenstelle und - nur
              bei LdF-Eingangsbearbeitung - Absender verwenden frühere Werte
              desselben Einsatzes als auswählbare Vorschläge. Freie Eingaben
              bleiben möglich.</li>
            <li><strong>Folgenachrichten:</strong> „Antwort“ übernimmt die
              Rufnummer und setzt einen Betreff mit „AW:“; „Weiterleitung“
              beginnt ohne Rufnummer und mit „WG:“.</li>
            <li><strong>Anlagen:</strong> Dateien werden unmittelbar unter dem
              offenen Vordruck hinzugefügt. Die Anzahl ist bereits im
              Formularkopf sichtbar; jede Anlage erscheint darunter mit
              Dateiname, optionaler Beschreibung, soweit belegtem Zeitpunkt
              und Größe sowie passenden Aktionen.</li>
          </ul>
          <p>Die Eingabe folgt dem verbindlichen Formularraster.
            <a href="<?= $href('messages') ?>">Nachrichtenvordruck öffnen</a>.</p>
        </article>

        <article id="nachrichtenlauf" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Eingang Ausgang Workflow Verfasser Sichter Rückgabe Freigabe LdF disponieren Fernmelder befördern Absender Weg korrigieren Berechtigungsmodus Streng Locker Übernahme Stationsleiste Laufzeit Runde">
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
                im freigegebenen S6-Plan gültigen Beförderungsweg festlegen.
                Ist der Vordruck fachlich nicht disponierbar, geht er mit
                Pflichtgrund an den Verfasser zurück und durchläuft danach Si
                und LdF erneut.</li>
              <li><strong>Fernmelder:</strong> Nachricht tatsächlich übermitteln und
                wirklichen Weg, Beförderungszeit und Zeichen dokumentieren.
                Ist der Weg nicht nutzbar, geht die Nachricht mit Grund zu LdF
                zurück.</li>
            </ol>
          </section>
          <section class="estab-handbook-workflow" aria-labelledby="incoming-title">
            <h3 id="incoming-title">Eingang</h3>
            <ol>
              <li><strong>Fernmelder:</strong> tatsächliches Medium, Aufnahmezeit,
                Gegenstellenrufname, Betreff und Inhalt erfassen.
                <strong>Der Fernmelder darf den Absender nicht schreiben.</strong>
                Dieselbe Feldsperre gilt für jedes andere Konto, das im Modus
                „Locker“ diese Aufnahmestufe übernimmt.</li>
              <li><strong>LdF:</strong> Gegenstellenrufname in den Absender
                übersetzen und den erfassten Eingangsweg bestätigen. Eine
                Änderung benötigt eine Begründung.</li>
              <li><strong>Si:</strong> Nachricht bewerten, Empfänger festlegen
                und den Vorgang abschließen. Erst dann erhalten fremde
                Empfänger ihre Kopie.</li>
            </ol>
          </section>
          <div class="estab-handbook-flow-summary" aria-label="Kurzform der Nachrichtenwege">
            <span>Ausgang: Verfasser</span><b>→</b><span>Si</span><b>→</b><span>LdF</span><b>→</b><span>Fernmelder</span><b>→</b><span>abgeschlossen</span>
            <span>Eingang: Fernmelder</span><b>→</b><span>LdF</span><b>→</b><span>Si</span><b>→</b><span>Empfänger</span>
          </div>
          <div class="estab-handbook-callout">
            <strong>Der Ablauf bleibt in beiden Modi gleich</strong>
            <p>„Streng“ bindet jeden Schreibschritt an die oben genannte feste
              Funktion. „Locker“ erlaubt einem anderen Funktionskonto, eine
              angebotene Schreibstufe zu übernehmen, überspringt aber weder
              Status, Richtung noch Objektsperre. Auch ein zurückgegebener
              Ausgang darf dann von einer anderen Funktion korrigiert und
              erneut eingereicht werden; der Nachweis hält ursprüngliche und
              neu verantwortliche Funktion auseinander.</p>
          </div>
          <p>Die Stationsleiste jedes geöffneten Nachrichtenvordrucks wird aus
            der unveränderbaren Ereigniskette aufgebaut. Die Laufzeit verwendet
            die serverseitige Erfassungszeit eines Übergangs; bewusst
            korrigierte fachliche Zeitangaben verändern diese Messung nicht.
            Wiederholte Besuche bei Verfasser, Si, LdF oder Fernmelder bleiben
            einzeln sichtbar.</p>
        </article>

        <article id="anhaenge" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Datei Upload Anlage Anhang JPEG JPG PDF PNG GIF BMP TIFF ZIP DOC XLS ODT TXT XIA AVI EML RFC822 E-Mail Original 20 MiB 24 MiB MIME Vorschau Download Prüfsumme Badge Karte entfernen Archiv">
          <header><span>08</span><div><p>Dateien sicher mitführen</p><h2>Anhänge</h2></div></header>
          <p>Bleiben Sie beim Erfassen oder Bearbeiten im geöffneten
            Nachrichtenvordruck. Wählen Sie unter „Neue Anlage hinzufügen“
            eine Datei, ergänzen Sie bei Bedarf eine Beschreibung und wählen
            Sie „Datei hochladen“. Der offene Entwurf bleibt erhalten und die
            Datei erscheint sofort als Anlagenkarte am Vordruck. Alternativ
            können Sie mit ausgewählter Datei direkt die reguläre
            Formularaktion ausführen; eStab speichert dann zuerst die Anlage
            und danach den Nachrichtenschritt.</p>
          <p>Der direkte Upload und das Entfernen aus dem Entwurf stehen beim
            Erfassen eines Eingangs durch den Fernmelder sowie beim Schreiben,
            Korrigieren und bei Gesprächsnotizen von Stab/FB bereit. In den
            nachfolgenden Arbeitsschritten von LdF, Si und Fernmelder bleiben die
            zugeordneten Karten sichtbar, sind dort aber nicht mehr
            veränderbar. Höchstens 100 Anlagen können einem Vordruck
            zugeordnet werden.</p>
          <ol class="estab-handbook-steps">
            <li><strong>Anlage erkennen.</strong> Der Formularkopf sowie die
              Meldungsübersicht und zweite Sichtung zeigen „1 Anlage“ oder die
              entsprechende Anzahl. Damit ist eine Nachricht mit Anlagen auch
              in langen Trefferlisten sofort erkennbar.</li>
            <li><strong>Inhalt prüfen.</strong> JPEG-, PNG-, GIF- und
              BMP-Bilder erscheinen als Vorschau.
              PDF-Dateien lassen sich direkt in der Anlagenkarte aufklappen.
              Standardisierte E-Mail-Dateien mit <code>.eml</code> lassen sich
              dort als passive Textansicht aufklappen.
              Für diese Bildformate und PDF steht zusätzlich „Im Browser
              ansehen“ bereit; bei E-Mail heißt die Aktion „E-Mail ansehen“.
              Jede zulässige Datei einschließlich TIFF und EML kann
              heruntergeladen werden.</li>
            <li><strong>Zuordnung korrigieren.</strong> „Vom Vordruck entfernen“
              löst nur die Zuordnung im noch bearbeitbaren Vordruck. Die
              bereits sicher archivierte Datei wird dabei nicht gelöscht und
              kann später erneut ausgewählt werden.</li>
          </ol>
          <div class="estab-handbook-facts">
            <div><strong>Erlaubte Endungen</strong><span>JPG, JPEG, TIF, TIFF, GIF, AVI, PNG, BMP, ZIP, PDF, DOC, XLS, ODT, TXT, XIA und EML</span></div>
            <div><strong>Größenlimit</strong><span>Wird direkt am Dateifeld angezeigt; Standard sind 20 MiB je Upload. Für EML gilt auch bei höherem globalem Limit fest 20 MiB.</span></div>
            <div><strong>Inhaltsprüfung</strong><span>Dateiendung und serverseitig erkannter MIME-Typ müssen zusammenpassen.</span></div>
          </div>
          <p>Für E-Mail-Anlagen wird nur das standardisierte RFC-822-Format
            <code>.eml</code> unterstützt; Outlook-<code>.msg</code> kann eStab
            nicht lesen. Bei EML müssen zusätzlich der erkannte Typ
            <code>message/rfc822</code> und die interne MIME-Struktur gültig
            sein. Die Webansicht übernimmt kein Mail-HTML: Skripte, Formulare,
            eingebettete Objekte und entfernte Inhalte werden nicht ausgeführt
            oder nachgeladen. Anlagen innerhalb der E-Mail erscheinen nur mit
            Dateiname, Inhaltstyp und Größe.</p>
          <div class="estab-handbook-callout">
            <strong>E-Mail-Kopfzeilen sind kein Identitätsnachweis</strong>
            <p>eStab verifiziert weder den angezeigten Absender noch die
              Authentizität der Mail und führt keine DKIM- oder S/MIME-Prüfung
              durch. Nach Anmeldung und Objektprüfung kann die Originaldatei
              bytegetreu heruntergeladen werden, sie kann aber aktive oder
              anderweitig riskante Inhalte und Anlagen enthalten. Öffnen Sie
              das Original nur in einer geeigneten Prüfumgebung.</p>
          </div>
          <p>Die Schaltfläche „Bereits hochgeladene Anlage auswählen“
            öffnet den bisherigen Anlagenbereich als optionale Archivauswahl.
            Sie ist für Dateien gedacht, die schon zum aktiven Einsatz
            hochgeladen wurden; für eine neue Datei ist dieser Umweg nicht
            mehr erforderlich.</p>
          <p>Eine verknüpfte Anlage übernimmt die Leserechte mindestens einer
            verknüpften Nachricht. Eine noch freie Anlage sehen nur Uploader
            oder ausgewähltes S2, Si beziehungsweise LdF. Download und
            Browseransicht prüfen Berechtigung, tatsächlichen Dateityp sowie
            den unveränderlichen SHA-256-/Größennachweis erneut.</p>
          <p>Übernimmt im Modus „Locker“ ein anderes Funktionskonto eine
            zurückgegebene Nachricht, bleiben ausschließlich deren bereits
            verknüpfte Anlagen im Korrekturformular verfügbar. Die angezeigte
            Nachrichten-ID ist keine Freigabe: eStab prüft Konto, Einsatz,
            Modus, Nachrichtenstatus und exakte Anlagenzuordnung vor jeder
            Vorschau und jedem Download erneut. Das übrige Anlagenarchiv wird
            dadurch nicht geöffnet.</p>
          <p>Ist die Anlage serverseitig bereits vollständig gespeichert und
            geht erst die Antwort verloren oder schlägt danach die
            Nachrichtenvalidierung fehl, können Sie den geöffneten Vordruck
            ohne erneute Dateiauswahl absenden: eStab stellt die Referenz wieder
            her und erzeugt weder eine zweite Datei noch eine zweite Nachricht.
            Ein abgebrochener oder nur teilweise übertragener Datei-Upload muss
            dagegen erneut ausgewählt werden. Wird ein Entwurf bewusst nicht
            weiterverfolgt, bleibt
            die hochgeladene Datei als freie Archivdatei erhalten und kann
            später – sofern berechtigt – erneut ausgewählt werden.</p>
          <p>Für unterstützte Vorschaubilder über 16 Megapixel oder 24 MiB
            Dateigröße erscheint statt einer Miniatur ein Platzhalter; Download
            und „Im Browser ansehen“ bleiben verfügbar. Das schützt kleine Geräte vor
            aufwendigem Dekodieren und betrifft nicht die getrennten Grenzen
            des PDF-Einsatzdossiers.</p>
          <p><a href="<?= $href('messages') ?>">Nachrichtenvordruck mit direktem Upload öffnen</a>
            oder den <a href="<?= $href('attachments') ?>">Anlagenbereich als Archiv öffnen</a>
            (aktiver Einsatz und passende feste Kontofunktion erforderlich).</p>
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
            <li>Ein Anlagenhinweis nennt direkt am Treffer die Zahl der
              verknüpften Dateien.</li>
            <li>Zeitraum, Empfänger, Sortierung und 25/50/100 Treffer je Seite
              stehen unter den weiteren Filtern.</li>
            <li>Aktive Filter erscheinen als einzeln entfernbare Chips.</li>
            <li>Trefferzahl, Bereich, Sortierung und Seite werden ausgeschrieben.</li>
            <li>Fehlt ein automatischer TBB-Nachweis, steht ausdrücklich
              „noch kein TBB-Nachweis“.</li>
          </ul>
          <p><strong>Kategorien:</strong> Globale Kategorien kann nur die feste
            Rotkopiefunktion oder Si verwalten. Funktionskategorien gelten für
            die feste Kontofunktion, persönliche Kategorien nur für das Konto.
            Bei einer noch leeren globalen Liste stehen <em>Allgemein</em> und
            <em>EA1</em> bis <em>EA6</em> als anpassbare Grundstruktur bereit;
            die Beschreibungen der Einsatzabschnitte werden an die konkrete
            Einsatzorganisation angepasst.
            Gelesen und erledigt sind von der Transportstufe getrennte
            Arbeitsmarkierungen. Daran ändert auch der Berechtigungsmodus
            „Locker“ nichts.</p>
          <p>S2 öffnet die <a href="<?= $href('message_overview') ?>">Meldungsübersicht</a>;
            Die Funktionen Si und Fernmelder erreichen die zweite Sichtung im
            Nachrichtenmenü.
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
          <p>Das ETB gehört zum aktiven Einsatz. Im Modus „Streng“ schreiben
            Konten mit der festen Funktion ETB oder S2 und Rolle Stab. Im Modus
            „Locker“ darf ein anderes gültiges Funktionskonto diese operative
            Schreibzuständigkeit übernehmen; seine tatsächliche Identität wird
            nachgewiesen. Eine Dienstschicht ist nicht erforderlich. Einträge
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
          <p>Das TBB gehört zum aktiven Einsatz. Im Modus „Streng“ schreibt
            manuell ein Konto mit der festen Funktion Fernmelder. Im Modus
            „Locker“ darf ein anderes gültiges Funktionskonto diese operative
            Schreibzuständigkeit übernehmen; seine tatsächliche Identität wird
            nachgewiesen. Eine Dienstschicht ist nicht erforderlich. Jeder
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
          <p>Im Modus „Streng“ erstellt S6 im
            <a href="<?= $href('command_post') ?>">Führungsstellenbetrieb</a>
            einmalig den ersten Plan, beschreibt die vorgesehenen Wege und gibt
            ihn bewusst frei. Bei jeder späteren Änderung kopiert
            „Bearbeitung starten“ die vollständigen Kopfdaten und alle Wege der
            aktiven Fassung in einen Entwurf. Dort lassen sich der Kopf sowie
            jeder einzelne Weg ändern, ergänzen oder entfernen, ohne den
            laufenden Betrieb zu unterbrechen. Erst „Als Version … aktiv
            schalten“ ersetzt die bisherige Fassung. Ungespeicherte Änderungen
            in einem der Teilformulare stoppen die Aktivierung und andere
            Seitenwechsel-Aktionen und zeigen den betroffenen Bereich wieder an.
            Wurden mehrere Bereiche gleichzeitig geändert, lässt sich die
            gewählte Aktion nur über die deutlich als Datenverlust
            gekennzeichnete Schaltfläche fortsetzen; die anderen noch nicht
            gespeicherten Browserwerte werden dann bewusst verworfen. Ein
            veralteter Browser-Tab und ein zweiter paralleler Entwurf werden
            abgewiesen. Über „Entwurf
            verwerfen“ lässt sich ein nicht mehr benötigter oder veralteter
            Stand nachvollziehbar archivieren; danach kann erneut von der
            unveränderten aktiven Version begonnen werden. Im Modus
            „Locker“ darf ein anderes gültiges Funktionskonto diese operativen
            Schreibschritte übernehmen. Freigegebene Versionen bleiben in
            beiden Fällen unveränderlich. Die Versionshistorie zeigt ersetzte
            und verworfene Fassungen einschließlich Kopfdaten, sämtlicher Wege,
            Vermerke sowie Anlage- und Freigabezeit nur lesend an.</p>
          <p>Die Auswahl nennt die Medien vollständig: Fernsprecher, Funk,
            Melder, Telefax, Fernschreiber und Datenübertragung. Betriebsstelle,
            Rufname sowie Verkehrsform oder besondere Behandlung werden immer
            erfasst. Kanal beziehungsweise Rufgruppe und Bandlage sind
            Funkangaben und werden deshalb nur für das Medium Funk angeboten.
            Vermerke und Bemerkungen bleiben optional.</p>
          <p>Alle aktiven Funktionen können den gültigen Plan lesen. LdF darf
            einen Ausgang nur auf einen zum Zeitpunkt der Disposition gültigen
            Planweg legen. Die spätere tatsächliche Beförderung durch den
            Fernmelder dokumentiert dennoch
            den tatsächlich verwendeten Weg. Plan, Disposition und realer
            Transport bleiben damit voneinander unterscheidbar.</p>
        </article>

        <article id="melder" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Melder Auftrag Fernmelder LdF Empfänger Zustellung Rücknachricht Rückweg Rückkehr">
          <header><span>13</span><div><p>Persönlich nachvollziehbare Botengänge</p><h2>Melderaufträge</h2></div></header>
          <p>Ein Melder ist keine zusätzliche globale Rolle. Im Modus „Streng“
            beauftragt LdF ein konkret angemeldetes, ungesperrtes Konto mit der
            Funktion Fernmelder; im Modus „Locker“ darf ein anderes gültiges
            Funktionskonto den Auftrag disponieren. Die ausgewählte
            Melderperson bleibt ein Fernmelderkonto. Die
            beauftragte Person übernimmt den Lauf persönlich und dokumentiert
            tatsächlichen Empfänger, Zustellung, eine mögliche Rücknachricht,
            Rückweg und Rückkehr.</p>
          <p>Während eines offenen Auftrags bleibt die Verantwortung an diese
            Person gebunden. Im Modus „Streng“ quittiert erst LdF den
            vollständigen Abschluss; im Modus „Locker“ darf ein anderes
            gültiges Funktionskonto diesen vorgesehenen Bestätigungsschritt
            übernehmen. Der Auftragszustand und die Bindung an die
            Melderperson bleiben unverändert. Auftrag, Übergaben und
            Statuswechsel erscheinen im
            einsatzgebundenen Nachweis und im PDF-Einsatzdossier.</p>
        </article>

        <article id="uebergabe" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Zugangsschicht Gruppe Konten aktivieren deaktivieren Sitzung Abschluss Einsatz beenden Legal Hold Aufbewahrung zehn Jahre">
          <header><span>14</span><div><p>Gruppenzugänge und formaler Abschluss</p><h2>Zugangsschichten und Einsatzabschluss</h2></div></header>
          <p>Zugangsschichten sind optionale einsatzbezogene Gruppen. Sie
            vereinfachen den gemeinsamen Zugangsentzug, ohne Funktionen oder
            Fachrechte zu verändern.</p>
          <p>Beim Aktivieren eines neuen Einsatzes eröffnet eStab ETB und TTB
            automatisch mit je einer Systemzeile. Dafür muss keine Schicht
            angelegt oder aktiviert werden.</p>
          <ol class="estab-handbook-steps">
            <li>Die Administration legt eine Gruppe für den aktiven Einsatz an
              und ordnet bei Bedarf Konten zu.</li>
            <li>Aktivieren gibt zugeordnete Zugänge frei, erzeugt aber keine
              Anmeldung.</li>
            <li>Deaktivieren widerruft zugeordnete Sitzungen, sofern kein Zugang
              über eine andere aktive Gruppe verbleibt.</li>
          </ol>
          <p>Vor dem formalen Einsatzabschluss müssen unter anderem offene
            Nachrichten, Planentwürfe und Melderläufe geklärt sein. Historische
            formale Dienstschichten, offene Altbesetzungen oder fehlende
            schichtbezogene Eröffnungszeilen blockieren den Abschluss nicht.
            Der Abschluss erzeugt letzte Buchzeilen und setzt eine
            Mindestaufbewahrung von zehn Jahren.
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
          data-handbook-keywords="PDF Einsatzdossier ETB TBB Vordruck Anlagen sichtbar eingebettet Original EML E-Mail passiv ZIP CSV Manifest Prüfsumme herunterladen löschen 50 MiB">
          <header><span>15</span><div><p>Lesbar und maschinenlesbar sichern</p><h2>Export und PDF-Einsatzdossier</h2></div></header>
          <div class="estab-handbook-compare">
            <section><h3>PDF-Einsatzdossier</h3><p>Für Lesen, Übergabe und
              Ausdruck eines ausgewählten aktiven oder historischen Einsatzes.
              Neun Bereiche sind wählbar: ETB, TBB,
              Nachrichtenvordrucke, Anlagen, Nachrichtenereignisse,
              Dienstorganisation, S6-Pläne, Melderläufe und Betriebsereignisse.
              Die Dienstorganisation enthält Zugangsschichten samt aktuellen
              und entfernten Zuordnungen; formale Altschichten erscheinen
              getrennt als historischer Legacy-Nachweis.
              ETB/TBB können einsatzweit oder bei belegter historischer
              Provenienz für genau eine frühere formale Dienstschicht
              ausgegeben werden; dieser Legacy-Filter verändert nur diese
              beiden Bücher und umfasst keine Zugangsschicht.
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
            durchsuchbarer Inhalt. Standardisierte EML-E-Mails werden innerhalb
            der PDF-Text-/Zeichengrenzen passiv mit ausgewählten Kopfzeilen und
            Nachrichtentext dargestellt; enthaltene Mail-Anlagen erscheinen
            nur als Metadaten. Ist das nicht verlustfrei möglich, folgt eine
            Hinweisseite. Andere Formate erhalten ebenfalls eine eindeutige
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
          data-handbook-keywords="Basic Auth Admin Benutzer sperren Passwort Kennwort Richtlinie Mindestlänge Großbuchstabe Kleinbuchstabe Ziffer Sonderzeichen zurücksetzen Matrix Zähler PDF Markierung Systemstatus Berechtigungsmodus Streng Locker Strict Loose Einsatz">
          <header><span>16</span><div><p>Technische Maßnahmen mit eigenem Zugang</p><h2>Administration</h2></div></header>
          <p>Die Administration verwendet HTTP Basic Auth und ein eigenes
            technisches Kennwort. Diese Anmeldung ist kein eStab-Funktionskonto
            und verleiht keine operativen Fachrechte. Außerhalb eines isolierten
            Testhosts darf sie ausschließlich über TLS erreichbar sein.</p>
          <div class="estab-handbook-admin-grid">
            <a href="<?= $href('incidents') ?>"><strong>Einsätze</strong><span>Anlegen, Berechtigungsmodus festlegen, aktivieren, deaktivieren und formal abschließen</span></a>
            <a href="<?= $href('users') ?>"><strong>Benutzer</strong><span>Anlegen, zuweisen, sperren, entsperren und Kennwort zurücksetzen</span></a>
            <a href="<?= $href('self_registration') ?>"><strong>Selbstregistrierung</strong><span>Sofort schließen, dauerhaft oder für 15 Minuten bis 24 Stunden öffnen</span></a>
            <a href="<?= $href('password_policy') ?>"><strong>Kennwortrichtlinie</strong><span>Mindestlänge und optionale Zeichenanforderungen für neue Kennwörter festlegen</span></a>
            <a href="<?= $href('admin_command_post') ?>"><strong>Zugangsschichten</strong><span>Optionale Kontengruppen anlegen und gemeinsam aktivieren/deaktivieren</span></a>
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
          <h3>Berechtigungsmodus eines Einsatzes festlegen</h3>
          <p>Beim Anlegen und in der Verwaltung eines noch offenen Einsatzes
            wählen Sie „Streng“ oder „Locker“. Bestehende und neue Einsätze
            verwenden standardmäßig „Streng“. Ein Wechsel wird sofort
            einsatzweit wirksam und im Einsatzprotokoll festgehalten. Die
            Administration schützt vor einem veralteten Formularstand; für
            „Locker“ muss die Erweiterung der dafür vorgesehenen
            Schreibzuständigkeiten in Nachrichtenworkflow, ETB/TBB,
            S6-Planung und Melderlauf zusätzlich ausdrücklich bestätigt
            werden. Geschlossene Einsätze werden nicht nachträglich
            umgestellt.</p>
          <h3>Selbstregistrierung kontrolliert freigeben</h3>
          <p>Unter <a href="<?= $href('self_registration') ?>">Selbstregistrierung</a>
            schließen Sie die öffentliche Kontoanlage sofort, öffnen sie
            dauerhaft oder geben sie ab jetzt für 15 Minuten bis 24 Stunden
            frei. Ein Zeitfenster endet automatisch anhand der Datenbankzeit.
            Auch ein vorher geöffnetes Formular wird beim Absenden erneut
            geprüft.</p>
          <div class="estab-handbook-callout"><strong>Nur beaufsichtigt öffnen</strong>
            <p>Während einer Freigabe kann jede Person, die die Anmeldeseite
              erreicht, eine der dort angebotenen aktiven Funktionen wählen.
              Aktivieren Sie die Selbstregistrierung deshalb nur in einem
              kontrollierten Netz und unter Aufsicht; bestehende Konten können
              sich auch bei geschlossener Selbstregistrierung anmelden.</p></div>
          <h3>Kennwortrichtlinie verständlich anwenden</h3>
          <p>Unter <a href="<?= $href('password_policy') ?>">Kennwortrichtlinie</a>
            legen Sie eine Mindestlänge zwischen 8 und 128 Zeichen fest.
            Voreingestellt sind 12 Zeichen. Zusätzlich können Sie unabhängig
            mindestens einen Unicode-Großbuchstaben, Unicode-Kleinbuchstaben,
            eine Unicode-Ziffer und ein Sonderzeichen verlangen. Leerzeichen
            dürfen Teil einer Passphrase sein, zählen aber nicht als
            Sonderzeichen.</p>
          <p>Prüfen Sie zuerst die angezeigte Vorher-/Nachher-Ansicht und
            bestätigen Sie die Änderung anschließend ausdrücklich. Eine
            Abschwächung wird hervorgehoben; hat in der Zwischenzeit jemand
            anderes gespeichert, fordert eStab statt eines Überschreibens zum
            erneuten Prüfen auf.</p>
          <div class="estab-handbook-callout"><strong>Nur für neue Kennwörter</strong>
            <p>Die Richtlinie gilt für neue Konten, Kennwortresets und eine
              gegebenenfalls freigeschaltete Selbstregistrierung. Vorhandene
              Kennwörter und Sitzungen bleiben gültig. Auch das separate
              technische Kennwort der HTTP-Basic-Administration wird hier
              nicht geändert.</p></div>
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
          data-handbook-keywords="Fehler Hilfe Unauthorized keine Anmeldung Passwort Konto gesperrt Einsatz inaktiv Zugangsschicht Upload EML E-Mail MSG RFC822 Planweg PDF Grenze Logs">
          <header><span>18</span><div><p>Ursache gezielt eingrenzen</p><h2>Probleme lösen</h2></div></header>
          <div class="estab-handbook-troubleshooting">
            <details><summary>Administration zeigt sofort „Unauthorized“</summary><p>Das ist die Browserabfrage für HTTP Basic Auth. Manche integrierten Browser zeigen sie nicht zuverlässig; verwenden Sie einen normalen Browser. Benutzername und Kennwort stammen aus dem technischen Admin-Secret, nicht aus einem Funktionskonto.</p></details>
            <details><summary>Die Kontoanmeldung funktioniert nicht</summary><p>Prüfen Sie Name, Kürzel, Funktion und Kennwort. Ein administrativ gesperrtes Konto oder eine widerrufene Sitzung muss in der Benutzerverwaltung geklärt werden. Legen Sie kein zweites Konto mit demselben Kürzel an.</p></details>
            <details><summary>Ein neues Kennwort wird abgewiesen</summary><p>Lesen Sie die aktuell angezeigte Kennwortrichtlinie vollständig: Mindestlänge und aktivierte Anforderungen für Großbuchstaben, Kleinbuchstaben, Ziffern oder Sonderzeichen müssen gemeinsam erfüllt sein. Beide Eingaben müssen exakt übereinstimmen. Eine spätere Verschärfung verhindert keinen Bestandslogin, gilt aber bei jedem neuen Reset.</p></details>
            <details><summary>Es ist keine operative Eingabe möglich</summary><p>Prüfen Sie gültige Sitzung, aktiven und offenen Einsatz, bestätigten Führungsstellennamen sowie ein aktives, ungesperrtes Konto. Im Modus „Streng“ muss dessen feste Funktion für den Schreibschritt zuständig sein; „Locker“ erweitert nur diese operative Zuständigkeit und umgeht weder Bearbeitungsstand, Richtung, Sperre noch Pflichtfelder. Eine Dienstschicht und eine Hutauswahl sind keine Schreibvoraussetzung. Prüfen Sie bei einer Gruppenzuordnung zusätzlich, ob mindestens eine zugeordnete Zugangsschicht aktiv ist.</p></details>
            <details><summary>Ein Ausgang erreicht den Fernmelder nicht</summary><p>Der Ausgang muss zuerst die Si- und danach die LdF-Stufe durchlaufen. Die LdF-Stufe benötigt einen gültigen freigegebenen S6-Planweg. Im Modus „Locker“ darf ein anderes gültiges Konto eine angebotene Stufe übernehmen, aber keine Stufe oder Planprüfung überspringen. Eine Rückgabe enthält einen Pflichtgrund und muss in der zuständigen Stufe bearbeitet werden.</p></details>
            <details><summary>Eine Anlage lässt sich nicht hochladen</summary><p>Prüfen Sie die am Dateifeld angezeigte Grenze, erlaubte Endung und echten Inhaltstyp. Eine lediglich umbenannte Datei wird abgewiesen. Brechen Sie einen nicht mehr benötigten Anhangsvorgang sauber ab.</p></details>
            <details><summary>Eine E-Mail-Anlage wird abgewiesen</summary><p>Speichern oder exportieren Sie die Mail als standardisierte <code>.eml</code>-Datei. Outlook-<code>.msg</code> wird nicht unterstützt. Endung, erkannter Typ <code>message/rfc822</code>, MIME-Struktur und die feste Grenze von 20 MiB müssen gemeinsam passen.</p></details>
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
                <tr><td><a href="<?= $href('messages') ?>">Nachrichtenvordruck</a></td><td>berechtigtes Festfunktionskonto</td><td>Rollenabhängiger Nachrichtenlauf</td></tr>
                <tr><td><a href="<?= $href('command_post') ?>">Führungsstellenbetrieb</a></td><td>angemeldetes Konto</td><td>S6 und Melder gemäß fester Funktion</td></tr>
                <tr><td><a href="<?= $href('message_overview') ?>">Meldungsübersicht</a></td><td>S2/Stab</td><td>Einsatzweite Suche und Lageübersicht</td></tr>
                <tr><td><a href="<?= $href('tracking') ?>">Nachweisung</a></td><td>LdF oder Fernmelder</td><td>Aufnahme und tatsächliche Beförderung</td></tr>
                <tr><td><a href="<?= $href('etb') ?>">ETB</a> / <a href="<?= $href('ttb') ?>">TBB</a></td><td>ETB: ETB/S2; TTB: Fernmelder</td><td>Append-only Einsatz- und Betriebsbücher</td></tr>
                <tr><td><a href="<?= $href('bos_info') ?>">BOS-Info</a></td><td>öffentlich</td><td>Buchstabier-, Rufnamen- und Karteninformationen</td></tr>
                <tr><td><a href="<?= $href('admin') ?>">Administration</a></td><td>technischer Basic-Auth-Zugang</td><td>Einsatz-, Konto-, Zugangsgruppen- und Datenverwaltung</td></tr>
              </tbody>
            </table>
          </div>
          <p>Die Spalte „Wer nutzt ihn?“ nennt die Regelzuständigkeit im Modus
            „Streng“. „Locker“ erweitert ausschließlich ausdrücklich
            angebotene operative Schreibschritte; Übersichten, Nachweisung,
            Archive der zweiten Sichtung, Kategorien und Administration bleiben
            unverändert rollenstreng.</p>
          <h3>Begriffe</h3>
          <dl class="estab-handbook-glossary">
            <div><dt>Streng (STRICT)</dt><dd>Einsatzbezogener Standardmodus, in dem operative Schreibschritte an die feste Funktion und Rolle gebunden sind.</dd></div>
            <div><dt>Locker (LOOSE)</dt><dd>Einsatzbezogener Modus, der nur feste Funktions-/Rollengrenzen ausdrücklich angebotener Schreibschritte in Nachrichtenworkflow, ETB/TBB, S6-Planung und Melderlauf erweitert; sonstige Zugriffs- und Integritätsregeln bleiben bestehen.</dd></div>
            <div><dt>Zugangsschicht</dt><dd>Optionale einsatzbezogene Kontengruppe zum gemeinsamen Aktivieren oder Deaktivieren von Zugängen; keine Fachrechtsquelle.</dd></div>
            <div><dt>LdF</dt><dd>Leitung der Fernmeldebetriebsstelle.</dd></div>
            <div><dt>Fernmelder</dt><dd>Aufnahme und Weitergabe in der Fernmeldebetriebsstelle.</dd></div>
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
