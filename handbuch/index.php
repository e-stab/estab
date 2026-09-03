<?php

declare(strict_types=1);

/*
 * Das Handbuch erklaert die Bedienung. Sonst nichts.
 *
 * Es ist keine Werbeschrift. Es preist nichts an, es lobt nichts, es
 * verspricht nichts. Wer es aufschlaegt, will wissen, welchen Knopf er
 * drueckt und was danach passiert -- und zwar in Saetzen, die man einmal
 * liest und dann versteht.
 *
 * Deshalb: kurze Saetze. Ein Gedanke je Satz. Handelnde zuerst. Fachwoerter
 * nur da, wo sie auf dem Bildschirm auch stehen, und dann erklaert.
 */

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
    'messenger_jobs' => estab_application_url('4fach/melderauftraege.php'),
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
$handbookVersion = '2026.09';
$handbookUpdated = '1. September 2026';

?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Handbuch zu eStab: Anmelden, Nachrichten bearbeiten, Bücher führen, Fernmeldeplan, Melderaufträge, Administration und Betrieb.">
  <link rel="shortcut icon" href="../favicon.ico">
  <link rel="stylesheet" href="../estab-ui.css">
  <link rel="stylesheet" href="./handbuch.css">
  <script src="./handbuch.js" defer></script>
  <title>eStab Handbuch</title>
</head>
<body class="estab-tool-page estab-handbook-page" data-estab-handbook data-estab-handbook-version="<?= estab_auth_html($handbookVersion) ?>">
  <a class="estab-handbook-skip" href="#handbook-content">Zum Handbuchinhalt</a>

  <header class="estab-tool-hero estab-handbook-hero">
    <p class="estab-tool-eyebrow">eStab · Bedienung</p>
    <h1>Handbuch</h1>
  </header>

  <main id="handbook-content" class="estab-tool-main estab-tool-main-wide estab-handbook-main">
    <section class="estab-tool-panel estab-handbook-search" aria-labelledby="handbook-search-title">
      <h2 id="handbook-search-title">Suchen</h2>
      <div class="estab-handbook-search-control">
        <label for="handbook-search">Suchbegriff</label>
        <div class="estab-handbook-search-row">
          <input id="handbook-search" type="search" autocomplete="off"
            placeholder="Zum Beispiel: Anlage, Rückgabe, Rufgruppe"
            aria-controls="handbook-chapters"
            data-estab-handbook-search>
          <button type="button" data-estab-handbook-clear hidden>Suche löschen</button>
        </div>
        <p class="estab-handbook-search-hint">Mehrere Wörter werden zusammen
          gesucht. Es bleiben die Kapitel stehen, in denen alle Wörter
          vorkommen.</p>
        <p class="estab-handbook-search-status" data-estab-handbook-status
          role="status" aria-live="polite" aria-atomic="true">Alle Kapitel werden angezeigt.</p>
      </div>
    </section>

    <div class="estab-handbook-layout">
      <aside class="estab-handbook-toc">
        <details open>
          <summary>Inhalt</summary>
          <nav aria-label="Handbuchkapitel">
            <ol data-estab-handbook-toc>
              <li><span class="estab-handbook-toc-group">Einstieg</span><a href="#ueberblick">1. Überblick</a></li>
              <li><a href="#anmelden">2. Anmelden</a></li>
              <li><a href="#bildschirm">3. Bildschirm und Sitzung</a></li>
              <li><a href="#rollen">4. Wer darf was</a></li>
              <li><span class="estab-handbook-toc-group">Nachrichten</span><a href="#vordruck">5. Der Nachrichtenvordruck</a></li>
              <li><a href="#ausgang">6. Nachricht ausgeben</a></li>
              <li><a href="#eingang">7. Nachricht aufnehmen</a></li>
              <li><a href="#anlagen">8. Anlagen</a></li>
              <li><a href="#suchen">9. Suchen und ordnen</a></li>
              <li><span class="estab-handbook-toc-group">Bücher</span><a href="#etb">10. Einsatztagebuch</a></li>
              <li><a href="#ttb">11. Technisches Betriebsbuch</a></li>
              <li><span class="estab-handbook-toc-group">Fernmeldedienst</span><a href="#fernmeldeplan">12. Fernmeldeplan</a></li>
              <li><a href="#melder">13. Melderaufträge</a></li>
              <li><span class="estab-handbook-toc-group">Einsatz führen</span><a href="#einsatz">14. Einsatz vorbereiten</a></li>
              <li><a href="#schichten">15. Schichten</a></li>
              <li><a href="#abschluss">16. Einsatz abschließen</a></li>
              <li><span class="estab-handbook-toc-group">Ausgeben und verwalten</span><a href="#ausgabe">17. Ausgeben</a></li>
              <li><a href="#administration">18. Administration</a></li>
              <li><a href="#betrieb">19. Installation und Betrieb</a></li>
              <li><span class="estab-handbook-toc-group">Nachschlagen</span><a href="#probleme">20. Wenn etwas nicht geht</a></li>
              <li><a href="#begriffe">21. Begriffe</a></li>
            </ol>
          </nav>
        </details>
      </aside>

      <div id="handbook-chapters" class="estab-handbook-chapters">
        <p class="estab-handbook-no-results" data-estab-handbook-empty hidden>
          Kein Kapitel enthält alle gesuchten Wörter. Prüfen Sie die
          Schreibweise oder löschen Sie ein Wort.
        </p>

        <article id="ueberblick" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Einführung Zweck Papier Nachrichtenvordruck THW Freigabe Rückfallebene Umfang">
          <header><span>1</span><h2>Überblick</h2></header>
          <p>eStab ersetzt den Nachrichtenvordruck aus Papier. Sie füllen
            dasselbe Blatt am Bildschirm aus. eStab merkt sich dabei, wer was
            wann getan hat.</p>
          <p>Die Anwendung ist für eine Führungsstelle mit eigener
            Fernmeldebetriebsstelle gebaut. Dafür müssen die Funktionen S2,
            Si, S6, LdF und Fernmelder besetzt sein.</p>
          <p>eStab führt neben den Nachrichten zwei Bücher: das
            Einsatztagebuch und das Technische Betriebsbuch. Dazu kommen der
            Fernmeldeplan, die Melderaufträge und die Nachweisung.</p>
          <h3>Was eStab nicht leistet</h3>
          <ul>
            <li>Es ersetzt keine Ausbildung und keine örtliche Stabsordnung.</li>
            <li>Es erteilt keine formale Freigabe. Die geben Menschen.</li>
            <li>Die Unterschriftszeilen im PDF sind zum Unterschreiben mit
              der Hand gedacht. Sie sind keine digitale Signatur.</li>
            <li>Der Betrieb auf Papier muss weiter beherrscht werden. Fällt
              die Technik aus, wird auf Papier weitergearbeitet.</li>
          </ul>
        </article>

        <article id="anmelden" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Login anmelden Konto Kürzel Kennwort Funktion wählen Dienstfunktion annehmen Selbstregistrierung neues Konto">
          <header><span>2</span><h2>Anmelden</h2></header>
          <ol class="estab-handbook-steps">
            <li><strong>Anmeldeseite öffnen.</strong> Über die
              <a href="<?= $href('login') ?>">Anmeldung</a> oder über die
              Kachel „Nachrichtenvordruck“ auf der Startseite.</li>
            <li><strong>Konto eingeben.</strong> Name, Kürzel, Funktion und
              Kennwort. Alle vier Angaben gehören zu Ihrem persönlichen
              Konto.</li>
            <li><strong>Funktion wählen.</strong> Arbeitet der Einsatz in der
              Betriebsart „Streng“, nehmen Sie am
              <a href="<?= $href('command_post') ?>">Fernmeldeplan</a> Ihre
              Besetzung an und wählen sie aus. In der Betriebsart „Locker“
              entfällt dieser Schritt.</li>
            <li><strong>Kopfzeile prüfen.</strong> Oben stehen Ihr Name, Ihre
              wirksame Funktion, die Führungsstelle und der aktive Einsatz.
              Stimmt etwas davon nicht, arbeiten Sie noch nicht richtig
              angemeldet.</li>
          </ol>
          <div class="estab-handbook-callout estab-handbook-callout-danger">
            <strong>Wenn Eingaben gesperrt sind</strong>
            <p>Ohne aktiven Einsatz können Sie nichts eintragen. Dasselbe
              gilt ohne festgelegten Namen der Führungsstelle. In der
              Betriebsart „Streng“ gilt es zusätzlich, solange Sie keine
              Dienstfunktion angenommen und ausgewählt haben. Wenden Sie sich
              an die Administration, wenn Ihr Konto gesperrt ist oder Ihnen
              eine Funktion fehlt.</p>
          </div>
          <h3>Bestehendes Konto oder neues Konto</h3>
          <p>Im Regelfall legt die zuständige Stelle Ihr Konto vorher an.
            Wählen Sie dann „Mit bestehendem Konto anmelden“. Ein unbekanntes
            Kürzel legt dabei kein Konto an.</p>
          <p>„Neues Konto anlegen“ erscheint nur, wenn die Administration die
            Selbstregistrierung geöffnet hat. Sie verlangt Name, ein noch
            freies Kürzel, die zugeteilte Funktion und zweimal dasselbe
            Kennwort. Die Anforderungen an das Kennwort stehen dabei auf dem
            Bildschirm.</p>
        </article>

        <article id="bildschirm" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Menü Navigation Seitenleiste Kopfzeile Status ungespeichert inaktiv 15 Minuten 12 Stunden abmelden Sitzung">
          <header><span>3</span><h2>Bildschirm und Sitzung</h2></header>
          <p>Links steht das Menü mit allen Bereichen. Der Bereich, in dem
            Sie gerade sind, ist hervorgehoben. Alle Bereiche bleiben
            anklickbar. Ob Sie in einem Bereich arbeiten dürfen, sagt Ihnen
            der Bereich selbst.</p>
          <p>Oben auf jeder Seite steht, als wer Sie angemeldet sind: Name,
            Kürzel, wirksame Funktion, Führungsstelle und Betriebsart des
            Einsatzes.</p>
          <ul>
            <li><strong>Ungespeicherte Eingaben.</strong> Verlassen Sie eine
              Seite mit geänderten Feldern, fragt eStab nach.</li>
            <li><strong>Inaktiv.</strong> Wer 15 Minuten lang nichts tippt
              und nichts anklickt, wird als inaktiv angezeigt. Das ist noch
              keine Abmeldung. Ein offener Tab allein genügt nicht.</li>
            <li><strong>Sitzungsende.</strong> Nach 12 Stunden ohne Aktivität
              endet die Sitzung. Nicht gespeicherte Eingaben sind dann
              verloren.</li>
            <li><strong>Abmelden.</strong> Nutzen Sie den Knopf „Abmelden“,
              besonders an einem Gerät, das mehrere Personen benutzen.</li>
          </ul>
        </article>

        <article id="rollen" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Rolle Funktion Rechte S1 S2 S3 S4 S5 S6 Si Sichter LdF Fernmelder ETB Betriebsart Streng Locker Zusatzfunktion Nachbesetzung">
          <header><span>4</span><h2>Wer darf was</h2></header>
          <p>Jedes Konto hat genau eine feste Funktion. Aus ihr leitet eStab
            die Rolle ab. Was Sie tun dürfen, hängt an der Funktion, in der
            Sie gerade arbeiten.</p>
          <p>Jeder Einsatz läuft in einer von zwei Betriebsarten. Sie legt
            fest, woher Ihre wirksame Funktion kommt.</p>
          <div class="estab-handbook-facts">
            <div><strong>Streng</strong><span>Ihre Rechte kommen aus einer
              Dienstschicht. Sie nehmen Ihre Besetzung persönlich an und
              wählen aus, in welcher Funktion Sie gerade arbeiten. Das ist
              die Voreinstellung.</span></div>
            <div><strong>Locker</strong><span>Es gibt keine Dienstschicht.
              Es gelten Ihre feste Kontofunktion und die Zusatzfunktionen,
              die die Administration Ihrem Konto gegeben hat.</span></div>
          </div>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>„Locker“ heißt nicht, dass alle alles dürfen</strong>
            <p>Auch dort braucht jede Handlung die passende Funktion. Fehlt
              sie, bleibt der Bereich gesperrt. Zusatzfunktionen vergibt nur
              die Administration, und zwar für jedes Konto einzeln. Sie
              erteilen keine Administrationsrechte.</p>
          </div>
          <div class="estab-handbook-table-wrap" role="region" aria-label="Funktionen und Aufgaben" tabindex="0">
            <table>
              <thead><tr><th>Funktion</th><th>Aufgaben in eStab</th></tr></thead>
              <tbody>
                <tr><td>S1 bis S6, Fachberatung, Verbindung</td><td>Nachrichten lesen, eigene Ausgänge schreiben, antworten, weiterleiten, Gesprächsnotizen erfassen, gelesen und erledigt setzen, Kategorien pflegen.</td></tr>
                <tr><td>S2</td><td>Lage und Dokumentation. Einziges Ziel der Rotkopie. Meldungsübersicht. Schreibt im Einsatztagebuch.</td></tr>
                <tr><td>ETB</td><td>Führt das Einsatztagebuch. Daraus folgen keine weiteren Rechte.</td></tr>
                <tr><td>Si</td><td>Sichtet jeden Ausgang, bewertet jeden Eingang und legt die Empfänger fest. Gibt mit Begründung zurück.</td></tr>
                <tr><td>S6</td><td>Führt den Fernmeldeplan: Wege anlegen, Gegenstellen pflegen, Versionen freigeben.</td></tr>
                <tr><td>LdF</td><td>Leitet den Fernmeldebetrieb. Bestätigt den Eingangsweg, übersetzt Rufnamen, wählt den Ausgangsweg, erteilt Melderaufträge und führt das Technische Betriebsbuch.</td></tr>
                <tr><td>Fernmelder</td><td>Nimmt Eingänge auf, befördert Ausgänge tatsächlich, bearbeitet Anlagen und führt die Nachweisung.</td></tr>
                <tr><td>Administration</td><td>Einsätze, Konten, Schichten, Matrix, Exporte und Systemstatus. Eigener technischer Zugang, kein Funktionskonto.</td></tr>
              </tbody>
            </table>
          </div>
          <p>Trägt ein Konto in „Locker“ mehrere Stabsfunktionen, zeigt die
            Seitenleiste für jede eine eigene Zeile: „Schreiben als S1“,
            „Lesen als S1“ und einen eigenen Zähler. Wählen Sie bewusst die
            Funktion, in der Sie den Vorgang bearbeiten. eStab prüft diese
            Wahl bei jedem Schritt erneut.</p>
          <div class="estab-handbook-callout">
            <strong>Wenn eine Kraft ausfällt</strong>
            <p>Eine einzelne Dienstfunktion lässt sich in der laufenden
              Schicht neu besetzen. Die ganze Schicht muss dafür nicht
              übergeben werden. Wer abgibt und wer übernimmt, steht mit Grund
              im Einsatztagebuch. Wirksam wird es, sobald die übernehmende
              Person annimmt.</p>
          </div>
          <div class="estab-handbook-callout">
            <strong>Aufwuchs im laufenden Einsatz</strong>
            <p>Eine Führungsstelle ohne Stab beginnt in „Locker“. Tritt
              später ein Stab zusammen, lässt sich der Einsatz auf „Streng“
              umstellen, ohne ihn zu beenden. Der Wechsel steht im
              Einsatztagebuch. Der Weg zurück ist gesperrt.</p>
          </div>
          <p>Funktionen ändert nur die Administration. Jede Änderung beendet
            bestehende Sitzungen des Kontos. Gemeinsam genutzte Zugangsdaten
            sind nicht zulässig.</p>
        </article>

        <article id="vordruck" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Formular Felder Betreff Anschrift Vorrang Sofort Blitz Staatsnot Zeit Zeichen Antwort Weiterleitung Gesprächsnotiz Stationsleiste Laufzeit Hilfe">
          <header><span>5</span><h2>Der Nachrichtenvordruck</h2></header>
          <p>Der Vordruck am Bildschirm hat drei Teile: Fm-Zentrale,
            Nachricht und Sichter. Er sieht aus wie das Blatt auf Papier. Auf
            einem schmalen Bildschirm bleibt das Blatt in seiner Form und
            lässt sich seitlich schieben.</p>
          <p>Zwanzig Felder haben ein kleines „i“. Ein Klick darauf erklärt
            das Feld an Ort und Stelle.</p>
          <ul>
            <li><strong>Pflichtfelder.</strong> Betreff und Text, Anschrift,
              Absender und Zeichen. Welche davon nötig sind, hängt vom
              Arbeitsschritt ab.</li>
            <li><strong>Vorrang.</strong> Sofort, Blitz und Staatsnot wählen
              Sie im Vorrangfeld. „Keine“ bedeutet: kein besonderer Vorrang.
              Staatsnot nur auf ausdrückliche Weisung einer berechtigten
              Stelle.</li>
            <li><strong>Zeiten.</strong> Lassen Sie ein Zeitfeld leer, gilt
              der jetzige Zeitpunkt. Die Zeit des Sichters entsteht erst,
              wenn die Sichtung abgeschlossen wird.</li>
            <li><strong>Zeichen.</strong> Ihr Zeichen setzt eStab aus Ihrer
              Anmeldung ein. Sie können es nicht überschreiben.</li>
            <li><strong>Stationsleiste.</strong> Über dem Vordruck steht der
              Weg der Nachricht. Sie zeigt die aktuelle Station, die
              Laufzeit bis zur nächsten und jede Rückgabe als eigene Runde.
              Bei einem neuen Entwurf stehen dort nur die geplanten
              Stationen.</li>
            <li><strong>Vorschläge.</strong> Beim Rufnamen der Gegenstelle
              schlägt eStab frühere Werte desselben Einsatzes vor. Eigene
              Eingaben bleiben möglich.</li>
            <li><strong>Antwort und Weiterleitung.</strong> „Antwort“
              übernimmt die Rufnummer und setzt „AW:“ vor den Betreff.
              „Weiterleitung“ beginnt ohne Rufnummer und mit „WG:“.</li>
            <li><strong>Anlagen.</strong> Dateien hängen Sie unmittelbar
              unter dem offenen Vordruck an. Die Anzahl steht schon im
              Kopf.</li>
          </ul>
          <p><a href="<?= $href('messages') ?>">Nachrichtenvordruck öffnen</a>.</p>
        </article>

        <article id="ausgang" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Ausgang Verfasser Sichter Si LdF Fernmelder disponieren befördern Rückgabe Grund Weg Feld 7 Feld 6 Feld 20">
          <header><span>6</span><h2>Nachricht ausgeben</h2></header>
          <p>Eine Nachricht nach draußen geht durch vier Hände. Jede Hand hat
            genau eine Aufgabe.</p>
          <ol class="estab-handbook-steps">
            <li><strong>Verfasser.</strong> Anschrift, Betreff, Inhalt,
              Abfassungszeit und Verteiler eintragen und zur Sichtung geben.
              Die Abfassungszeit in Feld 16 setzt eStab nicht selbst; sie
              gehört dem Verfasser. In Feld 7 trägt er ein, über welches
              Mittel die Nachricht seiner Ansicht nach laufen soll. Dieser
              Wunsch bleibt im Vordruck stehen.</li>
            <li><strong>Si.</strong> Anschrift, Verfasserzeichen und Funktion
              prüfen. Dann freigeben oder mit Grund zurückgeben. Der
              Verfasser bessert nach und reicht erneut bei Si ein.</li>
            <li><strong>LdF.</strong> Rufnamen der Gegenstelle festlegen. In
              Feld 1 das Mittel wählen, in Feld 6 den Weg. In „Streng“ kommt
              der Weg aus dem freigegebenen Fernmeldeplan. Hat eine
              Führungsstelle kein S6 und damit keinen Plan, benennt der LdF
              in „Locker“ Mittel und Weg selbst. Der Wunsch aus Feld 7 bleibt
              daneben stehen. Geht es fachlich nicht, gibt der LdF mit Grund
              an den Verfasser zurück.</li>
            <li><strong>Fernmelder.</strong> Nachricht tatsächlich
              übermitteln, Zeit und Zeichen eintragen. Ist die Gegenstelle
              über das gewählte Mittel nicht erreichbar, geht die Nachricht
              mit Grund an den LdF zurück. Feld 20 hält fest, über welches
              Mittel sie nicht erreichbar war. Der LdF wählt dann ein anderes
              Mittel.</li>
          </ol>
          <div class="estab-handbook-flow-summary" aria-label="Kurzform der Nachrichtenwege">
            <span>Ausgang: Verfasser</span><b>→</b><span>Si</span><b>→</b><span>LdF</span><b>→</b><span>Fernmelder</span><b>→</b><span>abgeschlossen</span>
            <span>Eingang: Fernmelder</span><b>→</b><span>LdF</span><b>→</b><span>Si</span><b>→</b><span>Empfänger</span>
          </div>
          <p>Die Stationsleiste baut eStab aus den gespeicherten Ereignissen
            auf. Für die Laufzeit zählt der Zeitpunkt, an dem der Schritt
            gespeichert wurde. Eine später berichtigte fachliche Zeit ändert
            diese Messung nicht. Kommt eine Nachricht ein zweites Mal zu
            derselben Station, steht das einzeln da.</p>
          <div class="estab-handbook-callout">
            <strong>Der Ablauf ist in beiden Betriebsarten gleich</strong>
            <p>„Streng“ bindet jeden Schritt an die angenommene Besetzung.
              „Locker“ verlangt dieselbe Funktion als feste oder
              zusätzliche Funktion. Keine der beiden überspringt einen
              Schritt. Auch eine zurückgegebene Nachricht darf nur bearbeiten,
              wer die passende Funktion hat.</p>
          </div>
        </article>

        <article id="eingang" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Eingang aufnehmen Fernmelder Absender Rufname LdF Eingangsweg bestätigen Si bewerten Empfänger blaue Durchschrift Feld 15">
          <header><span>7</span><h2>Nachricht aufnehmen</h2></header>
          <p>Eine Nachricht von draußen geht durch drei Hände.</p>
          <ol class="estab-handbook-steps">
            <li><strong>Fernmelder.</strong> Tatsächliches Mittel,
              Aufnahmezeit, Rufname der Gegenstelle, Betreff und Inhalt
              eintragen. <strong>Der Fernmelder darf den Absender nicht
              schreiben.</strong> Er hört einen Rufnamen, keine Dienststelle.
              Dieselbe Sperre gilt für jedes andere Konto, das diesen Schritt
              übernimmt.</li>
            <li><strong>LdF.</strong> Den Rufnamen in den Absender
              übersetzen. Dazu den Eingangsweg bestätigen, den der Fernmelder
              erfasst hat. Wer ihn ändert, muss das begründen.</li>
            <li><strong>Si.</strong> Nachricht bewerten, Empfänger festlegen
              und den Vorgang abschließen. Ohne mindestens einen Bearbeiter —
              eine blaue Durchschrift — lässt sich die Sichtung nicht
              abschließen; die Nachricht erreichte sonst niemanden. Erst mit
              dem Abschluss bekommen die Empfänger ihre Kopie, und das
              Technische Betriebsbuch verzeichnet die Aushändigung.</li>
          </ol>
          <p>Steht der Eingangsweg im Fernmeldeplan, schlägt eStab die
            passende Gegenstelle in Feld 15 vor. Der Vorschlag ist eine
            Hilfe, keine Vorgabe: Der LdF kann ihn übernehmen, ändern oder
            leer lassen. Wege, die der Plan nicht kennt, erscheinen am
            Fernmeldeplan in einer eigenen Liste.</p>
          <p>Eine automatische Sichtung gibt es nicht. Jeder Eingang wird von
            einer Person bewertet.</p>
        </article>

        <article id="anlagen" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Datei Anlage Anhang hochladen JPEG PDF PNG GIF BMP TIFF ZIP DOC XLS ODT TXT EML E-Mail 20 MiB MIME Vorschau Download entfernen Archiv">
          <header><span>8</span><h2>Anlagen</h2></header>
          <p>Anlagen hängen Sie im geöffneten Vordruck an. Wählen Sie unter
            „Neue Anlage hinzufügen“ eine Datei, schreiben Sie bei Bedarf
            eine Beschreibung dazu und klicken Sie „Datei hochladen“. Ihr
            Entwurf bleibt dabei stehen. Die Datei erscheint sofort als Karte
            am Vordruck.</p>
          <p>Anhängen und wieder entfernen können der Fernmelder beim
            Aufnehmen eines Eingangs sowie Stab und Fachberatung beim
            Schreiben, Korrigieren und bei Gesprächsnotizen. In den späteren
            Schritten von LdF, Si und Fernmelder sind die Karten sichtbar,
            aber nicht mehr veränderbar. Ein Vordruck trägt höchstens 100
            Anlagen.</p>
          <ol class="estab-handbook-steps">
            <li><strong>Anlage erkennen.</strong> Im Kopf des Vordrucks und
              in den Trefferlisten steht „1 Anlage“ oder die entsprechende
              Zahl.</li>
            <li><strong>Inhalt ansehen.</strong> JPEG, PNG, GIF und BMP
              zeigen eine Vorschau. PDF und E-Mails im Format
              <code>.eml</code> lassen sich in der Karte aufklappen. Jede
              erlaubte Datei können Sie herunterladen.</li>
            <li><strong>Zuordnung lösen.</strong> „Vom Vordruck entfernen“
              löst nur die Verbindung. Die Datei bleibt gespeichert und lässt
              sich später wieder auswählen.</li>
          </ol>
          <div class="estab-handbook-facts">
            <div><strong>Erlaubte Endungen</strong><span>JPG, JPEG, TIF, TIFF, GIF, AVI, PNG, BMP, ZIP, PDF, DOC, XLS, ODT, TXT, XIA und EML</span></div>
            <div><strong>Größe</strong><span>Die Grenze steht am Dateifeld. Voreingestellt sind 20 MiB je Datei. Für EML gelten immer 20 MiB.</span></div>
            <div><strong>Prüfung</strong><span>Endung und tatsächlicher Inhalt müssen zusammenpassen. Eine umbenannte Datei wird abgewiesen.</span></div>
          </div>
          <p>Bei E-Mails liest eStab nur das Format <code>.eml</code>. Dateien
            aus Outlook mit der Endung <code>.msg</code> kann eStab nicht
            lesen. Die Ansicht zeigt Kopfzeilen und Text. Sie führt kein
            HTML aus und lädt nichts nach. Anlagen innerhalb der E-Mail
            erscheinen nur mit Name, Typ und Größe.</p>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>Eine E-Mail weist niemanden aus</strong>
            <p>eStab prüft nicht, ob der angezeigte Absender echt ist. Es
              gibt keine Prüfung von DKIM oder S/MIME. Die Originaldatei
              können Sie herunterladen. Sie kann gefährliche Inhalte
              enthalten. Öffnen Sie sie nur in einer geeigneten Umgebung.</p>
          </div>
          <p>Wer eine Nachricht lesen darf, darf auch ihre Anlagen lesen.
            Eine Datei ohne Nachricht sehen nur die hochladende Person sowie
            S2, Si und LdF. Vor jeder Vorschau und jedem Download prüft eStab
            Berechtigung, Dateityp und Prüfsumme erneut.</p>
          <p>Geht nach dem Hochladen die Antwort verloren, können Sie den
            Vordruck ohne erneute Dateiauswahl absenden. eStab stellt die
            Verbindung wieder her und legt weder eine zweite Datei noch eine
            zweite Nachricht an. Ein abgebrochener Upload muss dagegen neu
            gewählt werden.</p>
          <p>Sehr große Bilder — über 16 Megapixel oder 24 MiB — zeigen statt
            der Vorschau einen Platzhalter. Herunterladen und im Browser
            ansehen bleiben möglich.</p>
          <p><a href="<?= $href('attachments') ?>">Anlagenbereich öffnen</a>
            (aktiver Einsatz und passende Funktion nötig).</p>
        </article>

        <article id="suchen" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Suche Filter Meldungsübersicht zweite Sichtung Kategorien gelesen erledigt Nummer Sortierung Vordrucke BOS-Info">
          <header><span>9</span><h2>Suchen und ordnen</h2></header>
          <p>Die Meldungsübersicht und die zweite Sichtung haben dieselbe
            Suche. Sie finden eine Nachricht über die Nummer aus dem
            Technischen Betriebsbuch oder über Text aus Rufname, Von, An,
            Rufnummer, Betreff, Inhalt und Verfasserfunktion.</p>
          <ul>
            <li>Richtung, Vorrang und Bearbeitungsstand stehen am Treffer.</li>
            <li>Die Zahl der Anlagen steht ebenfalls am Treffer.</li>
            <li>Zeitraum, Empfänger, Sortierung und 25, 50 oder 100 Treffer
              je Seite stehen unter „weitere Filter“.</li>
            <li>Jeder gesetzte Filter erscheint als Marke und lässt sich
              einzeln wieder entfernen.</li>
            <li>Fehlt die Nummer aus dem Betriebsbuch, steht dort
              ausdrücklich „noch kein TBB-Nachweis“.</li>
          </ul>
          <h3>Kategorien</h3>
          <p>Es gibt drei Arten. Globale Kategorien pflegen S2 oder Si.
            Funktionskategorien gelten für Ihre wirksame Funktion.
            Persönliche Kategorien gelten nur für Ihr Konto.</p>
          <p>Ist die globale Liste noch leer, stehen <em>Allgemein</em> und
            <em>EA1</em> bis <em>EA6</em> als Vorschlag bereit. Passen Sie
            die Beschreibungen an Ihre Einsatzabschnitte an.</p>
          <p>„Gelesen“ und „erledigt“ sind Ihre eigenen Arbeitsmarken. Sie
            haben nichts mit dem Bearbeitungsstand der Nachricht zu tun.</p>
          <h3>Vordrucke und BOS-Info</h3>
          <p>Die Liste unter <a href="<?= $href('forms') ?>">Vordrucke</a>
            erzeugt den Download aus dem gespeicherten Datensatz mit der
            heutigen Vorlage. Daneben stehen die Angaben zur früher
            abgelegten PDF-Datei. So sehen Sie, ob sich das Aussehen seit der
            Ablage geändert hat.</p>
          <p>Die <a href="<?= $href('bos_info') ?>">BOS-Info</a> ist eine
            Nachschlagehilfe: Buchstabieralphabet, Karten, Stab und
            Rufnamenschema. Entscheidungen werden dort nicht dokumentiert.</p>
          <p><a href="<?= $href('message_overview') ?>">Meldungsübersicht öffnen</a>.</p>
        </article>

        <article id="etb" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Einsatztagebuch ETB Fb Fü 2 Aufgabe Befehl Erledigung Kräfteanforderung wichtig Ereigniszeit Bezug Korrektur Anlage">
          <header><span>10</span><h2>Einsatztagebuch</h2></header>
          <p>Das Einsatztagebuch gehört zum aktiven Einsatz. Es schreibt, wer
            die Funktion ETB oder S2 hat. In „Streng“ muss die Besetzung
            angenommen und ausgewählt sein.</p>
          <p><strong>Einträge werden niemals überschrieben oder gelöscht.</strong>
            Ein Fehler wird mit einer Korrektur richtiggestellt. Die
            Korrektur nennt den Grund und zeigt auf den ursprünglichen
            Eintrag.</p>
          <ul>
            <li>Die fachliche Ereigniszeit und der Zeitpunkt der Erfassung
              stehen getrennt nebeneinander.</li>
            <li>Es gibt sechs Arten: ohne Kennzeichnung, A für Aufgabe, B für
              Befehl, E für Erledigung, K für Kräfteanforderung und W für
              sehr wichtig.</li>
            <li>Ein Eintrag kann auf einen anderen Eintrag desselben Einsatzes
              verweisen. Dafür nennen Sie dessen Nummer.</li>
            <li>Ein Eintrag kann genau eine noch freie Anlage aufnehmen.</li>
          </ul>
          <p>Suchen können Sie über Volltext, Art, Nummer, Anlage und
            Zuordnung. Verweise lassen sich in beide Richtungen verfolgen.
            <a href="<?= $href('etb') ?>">Einsatztagebuch öffnen</a>.</p>
        </article>

        <article id="ttb" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Technisches Betriebsbuch TBB Fb Fü 44 LdF Betrieb Personal Dienstübergabe Kanal Rufgruppe Störung Quittung Aushändigung Korrektur">
          <header><span>11</span><h2>Technisches Betriebsbuch</h2></header>
          <p><strong>Das Technische Betriebsbuch führt der LdF.</strong> Er
            leitet den Fernmeldebetrieb, und das Buch beschreibt diesen
            Betrieb. In „Streng“ muss seine Besetzung angenommen und
            ausgewählt sein.</p>
          <p>Jeder Eintrag soll auch ohne Anlage in Grundzügen verständlich
            sein.</p>
          <div class="estab-handbook-facts">
            <div><strong>Betrieb und Personal</strong><span>Aufnahme, Ende, Bereitschaft, Besetzung, Ablösung und Dienstübergabe.</span></div>
            <div><strong>Kanal und Rufgruppe</strong><span>Betriebsart und jeder Wechsel, mit altem und neuem Wert.</span></div>
            <div><strong>Ereignis und Störung</strong><span>Vorgänge, Unterbrechungen und wie sie beseitigt wurden.</span></div>
            <div><strong>Quittung und Aushändigung</strong><span>Empfang, Empfänger, Zeitpunkt und ausführende Person.</span></div>
          </div>
          <p>„Nachricht von“ und „Nachricht an“ können Sie nicht selbst
            wählen. Diese Zeilen schreibt eStab, sobald eine Beförderung
            abgeschlossen ist — jede genau einmal.</p>
          <p>Auch im TBB wird nichts gelöscht. Ein Fehler bekommt einen
            Korrektureintrag mit Grund.
            <a href="<?= $href('ttb') ?>">Technisches Betriebsbuch öffnen</a>.</p>
        </article>

        <article id="fernmeldeplan" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Fernmeldeplan S6 Fb Fü 76 Fb Fü 77 Weg Gegenstelle Nebenstelle Rufgruppe Kanal Band Version Entwurf freigeben Skizze taktisch betrieblich Ersatzweg">
          <header><span>12</span><h2>Fernmeldeplan</h2></header>
          <p><strong>Der Plan hält die eigenen Kommunikationsmittel fest.</strong>
            Er beantwortet die Frage: Womit ist unsere Führungsstelle zu
            erreichen, und wer hängt an welchem Mittel? Er ist keine Liste
            fremder Stellen.</p>
          <p>Er folgt zwei Vordrucken: Fb Fü 76 ist der Kommunikationsplan
            als Tabelle, Fb Fü 77 die Kommunikationsskizze als Bild. eStab
            führt beides aus denselben Daten.</p>
          <h3>Aufbau</h3>
          <ul>
            <li><strong>Kopf.</strong> Wer den Plan aufgestellt hat, für
              welchen Zeitraum er gilt, ein Verschlusssachenvermerk, die
              Betriebsleitung und wer freigegeben hat.</li>
            <li><strong>Wege.</strong> Je ein eigenes Kommunikationsmittel
              mit seiner Erreichbarkeit: unser Funkgerät mit Rufname und
              Rufgruppe, unser Anschluss mit Rufnummer, unser Postfach.</li>
            <li><strong>Gegenstellen.</strong> Zu jedem Weg eine eigene
              Tabelle: wer über dieses Mittel erreichbar ist. Dabei steht,
              ob die Stelle über-, unter- oder nebengeordnet ist.</li>
            <li><strong>Nebenstellen.</strong> Wer innerhalb der eigenen
              Führungsstelle unter welcher Nummer sitzt. In der Skizze steht
              diese Tafel in der Mitte.</li>
            <li><strong>Ersatzweg.</strong> Ein Weg kann als Ersatz für einen
              anderen benannt werden. Er steht dann eingerückt darunter.</li>
          </ul>
          <h3>Zwei Ansichten</h3>
          <p>Über dem Plan wählen Sie die Tiefe. <em>Taktisch</em> zeigt je
            Stelle einen Kasten mit dem, was für die Führungsentscheidung
            nötig ist. <em>Betrieblich</em> zeigt alle technischen Angaben in
            einer Tabelle. Beide Ansichten zeigen dieselben Daten.</p>
          <p>Bei Funkwegen steht immer die Rufgruppe dabei, bei analogem Funk
            Band und Kanal. Ohne diese Angabe wären zwei Wege derselben
            Betriebsstelle nicht zu unterscheiden.</p>
          <h3>Skizze</h3>
          <p>eStab zeichnet die Kommunikationsskizze aus dem Plan. In der
            Mitte steht die eigene Führungsstelle mit ihrem Funkrufnamen und
            der Nebenstellentafel. Links stehen die übergeordneten Stellen,
            rechts die untergeordneten. Die Linien tragen das Mittel und die
            Rufgruppe.</p>
          <h3>Ändern und freigeben</h3>
          <p>Ein freigegebener Plan wird nicht mehr verändert. Wollen Sie
            etwas ändern, klicken Sie „Bearbeitung starten“. eStab legt dann
            einen Entwurf an und kopiert Kopf, Wege, Gegenstellen und
            Nebenstellen der aktiven Fassung hinein. Im Entwurf ändern Sie,
            was nötig ist. Der laufende Betrieb arbeitet währenddessen weiter
            mit der aktiven Fassung.</p>
          <p>Erst „Als Version … aktiv schalten“ ersetzt die bisherige
            Fassung. Ungespeicherte Eingaben halten die Freigabe an und
            zeigen den betroffenen Bereich wieder. Ein veralteter Browser-Tab
            und ein zweiter Entwurf werden abgewiesen. Mit „Entwurf
            verwerfen“ legen Sie einen nicht mehr benötigten Stand
            nachvollziehbar zu den Akten.</p>
          <p>Alte Fassungen bleiben lesbar. Die Versionshistorie zeigt
            ersetzte und verworfene Stände vollständig.</p>
          <p>Alle wirksamen Funktionen dürfen den gültigen Plan lesen. Der
            LdF darf einen Ausgang nur auf einen Weg legen, der zum Zeitpunkt
            der Wahl gültig ist. Was der Fernmelder danach tatsächlich
            benutzt hat, steht getrennt davon im Vordruck.
            <a href="<?= $href('command_post') ?>">Fernmeldeplan öffnen</a>.</p>
        </article>

        <article id="melder" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Melder Kurier Auftrag LdF Fernmelder Übergabe Empfänger Rücknachricht Rückweg Rückkehr Abschluss inaktiv">
          <header><span>13</span><h2>Melderaufträge</h2></header>
          <p><strong>Die Melderaufträge stehen auf einer eigenen Seite.</strong>
            Ein Melderauftrag ist ein einzelner Botengang. Der Fernmeldeplan
            ist eine Unterlage, die tagelang gilt. Beides gehört nicht
            zusammen auf ein Blatt.</p>
          <h3>Melder oder Kurier</h3>
          <p>Ein <em>Melder</em> kennt den Inhalt der Nachricht. Er kann
            Rückfragen der Gegenstelle beantworten. Ein <em>Kurier</em> kennt
            ihn nicht. Er überbringt einen verschlossenen Umschlag. Wer mit
            Rückfragen rechnet, schickt einen Melder und weist ihn ein. Der
            Vordruck unterscheidet die beiden nicht; die Entscheidung treffen
            Sie.</p>
          <h3>Ablauf</h3>
          <ol class="estab-handbook-steps">
            <li><strong>Der LdF beauftragt.</strong> Er wählt eine
              Ausgangsnachricht, die auf dem Mittel „Melder“ liegt, dazu
              einen Fernmelder und das Ziel.</li>
            <li><strong>Die beauftragte Person übernimmt.</strong> Sie meldet
              sich mit ihrem eigenen Konto an. Der LdF kann das nicht für sie
              tun.</li>
            <li><strong>Sie weist die Übergabe nach.</strong> Dabei trägt sie
              ein, wer die Nachricht tatsächlich entgegengenommen hat.</li>
            <li><strong>Sie tritt den Rückweg an.</strong> Dabei sagt sie, ob
              es eine Rücknachricht gibt, und trägt sie gegebenenfalls ein.</li>
            <li><strong>Sie meldet die Rückkehr.</strong></li>
            <li><strong>Der LdF bestätigt den Abschluss</strong> mit einem
              Vermerk.</li>
          </ol>
          <p>Jeder dieser Schritte wird als eigener Eintrag festgehalten und
            lässt sich nicht mehr ändern. Der LdF kann einen Auftrag mit Grund
            abbrechen, solange die Person ihn noch nicht übernommen hat.</p>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>Inaktive Person zusätzlich informieren</strong>
            <p>Die Auswahl zeigt, ob ein Fernmelder gerade aktiv, inaktiv,
              abgemeldet oder dessen Sitzung abgelaufen ist. Ist er nicht
              aktiv, weist eStab darauf hin, dass Sie ihn zusätzlich
              informieren müssen. eStab benachrichtigt ihn nicht selbst.</p>
          </div>
          <p><a href="<?= $href('messenger_jobs') ?>">Melderaufträge öffnen</a>.</p>
        </article>

        <article id="einsatz" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Einsatz anlegen aktivieren Betriebsart Führungsstellenname Bedarfsträger Leitung Ausgangslage Konten vorbereiten">
          <header><span>14</span><h2>Einsatz vorbereiten</h2></header>
          <p>Bevor die erste Nachricht geschrieben wird, richtet die
            Administration den Einsatz ein.</p>
          <ol class="estab-handbook-steps">
            <li>Unter <a href="<?= $href('incidents') ?>">Einsätze verwalten</a>
              Kennung, Bezeichnung, Beginn, Bedarfsträger, Namen der
              Führungsstelle, Leitung, Auftrag und Ausgangslage eintragen.
              Betriebsart wählen und den Einsatz aktivieren. Ohne bewusste
              Abweichung gilt „Streng“.</li>
            <li>Unter <a href="<?= $href('users') ?>">Benutzer verwalten</a>
              Konten für mindestens S2, Si, S6, LdF und Fernmelder anlegen
              oder passend zuweisen. Zusatzfunktionen nur für „Locker“ und
              nur, wo sie gebraucht werden.</li>
            <li>In „Streng“ unter
              <a href="<?= $href('admin_command_post') ?>">Schichtverwaltung</a>
              eine Dienstschicht anlegen, aktivieren und besetzen. Jede
              Person nimmt ihre Besetzung selbst an.</li>
            <li>Eine Kraft mit der Funktion S6 legt den ersten Fernmeldeplan
              an und gibt ihn frei. Erst danach kann der LdF einen Ausgang
              auf einen Weg legen.</li>
          </ol>
          <div class="estab-handbook-callout">
            <strong>Der Name der Führungsstelle gehört zum Einsatz</strong>
            <p>Er steht nicht in einer Einstellung des Servers. eStab
              verwendet ihn als eigene Anschrift und als Absender. Nach der
              ersten Eintragung lässt er sich nicht mehr ändern.</p>
          </div>
          <div class="estab-handbook-callout">
            <strong>Die Betriebsart gehört ebenfalls zum Einsatz</strong>
            <p>Ändern lässt sie sich nur, solange der Einsatz offen und noch
              vollständig leer ist. Der Wechsel gilt sofort und wird
              festgehalten. Auf „Locker“ müssen Sie ihn ausdrücklich
              bestätigen. Die erste Eintragung friert die Betriebsart
              dauerhaft ein. Späteres Löschen einzelner Daten hebt das nicht
              auf.</p>
          </div>
        </article>

        <article id="schichten" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Dienstschicht Zugangsschicht Gruppe aktivieren deaktivieren Besetzung Übergabe Eröffnung Systemzeile">
          <header><span>15</span><h2>Schichten</h2></header>
          <p>Es gibt zwei verschiedene Dinge, die beide „Schicht“ heißen.
            Verwechseln Sie sie nicht.</p>
          <div class="estab-handbook-facts">
            <div><strong>Dienstschicht</strong><span>Nur in „Streng“. Sie ist
              Pflicht. Aus ihr kommen Funktion und Rechte. Jede Person nimmt
              ihre Besetzung persönlich an.</span></div>
            <div><strong>Zugangsschicht</strong><span>Nur in „Locker“. Sie ist
              freiwillig. Sie ist eine Gruppe von Konten und dient dazu,
              Zugänge gemeinsam ein- und auszuschalten. Sie verändert keine
              Funktion und kein Recht.</span></div>
          </div>
          <h3>Die erste Dienstschicht in Betrieb nehmen („Streng“)</h3>
          <p>Sechs Schritte, und zwei davon kann die Administration nicht
            selbst ausführen. Die Schichtverwaltung zeigt denselben Ablauf und
            hebt hervor, welcher Schritt gerade dran ist.</p>
          <ol class="estab-handbook-steps">
            <li>Benutzerkonten anlegen. Eine Dienstfunktion wird an ein Konto
              vergeben, nicht an einen Namen. Ohne Konto geht es nicht
              weiter.</li>
            <li>Dienstschicht planen. Die Administration legt sie unter einer
              Bezeichnung an.</li>
            <li>Pflichtfunktionen besetzen: S 2, Si, S 6, LdF und Fernmelder.
              Eine Person darf mehrere davon tragen.</li>
            <li><strong>Jede benannte Person nimmt ihre Funktion selbst
              an.</strong> Sie meldet sich mit ihrem eigenen Konto an und
              bestätigt sie im Bereich „Fernmeldeplan“, Abschnitt „Meine
              Dienstfunktionen“. Die Administration kann das nicht ersatzweise
              erklären.</li>
            <li>Die Administration aktiviert die Schicht. Damit eröffnet eStab
              ETB und TBB.</li>
            <li><strong>Jede Person wählt danach ihre Arbeitsfunktion</strong>
              — an derselben Stelle. Erst dann sind ihre operativen Bereiche
              frei.</li>
          </ol>
          <p>Aktiviert wird nur die erste Schicht. Jede weitere wird nicht
            aktiviert, sondern über eine persönlich bestätigte Übergabe
            übernommen.</p>
          <h3>Zugangsschicht schalten („Locker“)</h3>
          <ol class="estab-handbook-steps">
            <li>Die Administration legt die Gruppe für den aktiven Einsatz an
              und ordnet Konten zu.</li>
            <li>Aktivieren gibt die Zugänge frei. Angemeldet wird dabei
              niemand.</li>
            <li>Deaktivieren beendet die Sitzungen der zugeordneten Konten —
              außer ein Konto hat über eine andere aktive Gruppe noch
              Zugang.</li>
          </ol>
          <p>Eine Sperre des einzelnen Kontos wirkt unabhängig davon und geht
            vor.</p>
          <p>In „Streng“ eröffnet eStab beide Bücher, sobald die erste
            Dienstschicht aktiviert wird. In „Locker“ werden sie schon beim
            Aktivieren des Einsatzes eröffnet. Diese Eröffnungszeilen
            schreibt eStab selbst; für Einträge von Hand braucht es weiterhin
            die passende Funktion.</p>
        </article>

        <article id="abschluss" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="abschließen beenden deaktivieren Aufbewahrung zehn Jahre Legal Hold offene Vorgänge">
          <header><span>16</span><h2>Einsatz abschließen</h2></header>
          <p>Vor dem Abschluss müssen alle Vorgänge geklärt sein: offene
            Nachrichten, Planentwürfe und laufende Melderaufträge. In
            „Streng“ muss außerdem die Dienstorganisation beendet sein.</p>
          <p>Der Abschluss schreibt die letzten Zeilen in beide Bücher. Er
            setzt eine Aufbewahrung von mindestens zehn Jahren. Eine gesetzte
            Aufbewahrungssperre lässt sich nicht dadurch umgehen, dass jemand
            einen Export löscht.</p>
          <div class="estab-handbook-callout">
            <strong>Deaktivieren ist nicht abschließen</strong>
            <p>Ein offener Einsatz lässt sich vorübergehend deaktivieren und
              später wieder aktivieren. Der Abschluss dagegen ist endgültig.
              Er verlangt das tatsächliche Ende, einen Abschlussvermerk und
              eine ausdrückliche Bestätigung.</p>
          </div>
        </article>

        <article id="ausgabe" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="PDF Dossier Export ZIP CSV Manifest Prüfsumme Anlagen Original herunterladen löschen 50 MiB Nachweisung">
          <header><span>17</span><h2>Ausgeben</h2></header>
          <p>eStab gibt den Bestand auf zwei Wegen aus.</p>
          <div class="estab-handbook-compare">
            <section>
              <h3>PDF-Dossier</h3>
              <p>Zum Lesen, Übergeben und Ausdrucken eines Einsatzes. Neun
                Bereiche sind wählbar: Einsatztagebuch, Technisches
                Betriebsbuch, Nachrichtenvordrucke, Anlagen,
                Nachrichtenereignisse, Dienstorganisation, Fernmeldepläne,
                Melderaufträge und Betriebsereignisse. Die beiden Bücher
                lassen sich auf eine einzelne Dienstschicht eingrenzen. Ein
                noch offener Einsatz wird als vorläufig gekennzeichnet.</p>
              <a href="<?= $href('incident_pdf') ?>">PDF-Dossier erstellen</a>
            </section>
            <section>
              <h3>ZIP-Export</h3>
              <p>Zur maschinellen Auswertung. Er enthält CSV-Dateien in
                UTF-8, ein Verzeichnis der Dateien und Prüfsummen. Erzeugte
                Exporte können Sie ansehen, herunterladen und einzeln
                löschen. Die Einsatzdaten bleiben dabei bestehen.</p>
              <a href="<?= $href('exports') ?>">Exporte verwalten</a>
            </section>
          </div>
          <p>Im PDF erscheinen JPEG, PNG, GIF und BMP als Bild und
            PDF-Anlagen Seite für Seite. Text daraus bleibt durchsuchbar.
            E-Mails werden mit Kopfzeilen und Text abgedruckt; ihre eigenen
            Anlagen nur mit Name, Typ und Größe. Geht das nicht verlustfrei,
            folgt eine Hinweisseite. Jedes Original liegt bytegleich im
            Dossier, unabhängig von der Vorschau.</p>
          <p>Es gelten Grenzen: 50 MiB für alle Originale zusammen, 100
            Seiten je PDF-Anlage und 200 abgedruckte Anlagenseiten insgesamt.
            Eine beschädigte oder zu große Datei bricht den Export ab. Sie
            wird nicht stillschweigend weggelassen.</p>
          <div class="estab-handbook-callout">
            <strong>Export ist kein Backup</strong>
            <p>Ein Export ersetzt niemals die vollständige Sicherung von
              Datenbank, Anlagen, Vordrucken und Exporten.</p>
          </div>
          <p>Die <a href="<?= $href('tracking') ?>">Nachweisung</a> zeigt
            Aufnahme und tatsächliche Beförderung in der klassischen Form.</p>
        </article>

        <article id="administration" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Administration Basic Auth Benutzer sperren entsperren Kennwort zurücksetzen Richtlinie Matrix Zähler Systemstatus Selbstregistrierung">
          <header><span>18</span><h2>Administration</h2></header>
          <p>Die Administration hat einen eigenen technischen Zugang mit
            HTTP Basic Auth. Das ist kein eStab-Funktionskonto. Er gibt keine
            fachlichen Rechte. Außerhalb eines abgeschotteten Testrechners
            darf er nur über TLS erreichbar sein.</p>
          <div class="estab-handbook-admin-grid">
            <a href="<?= $href('incidents') ?>"><strong>Einsätze</strong><span>Anlegen, Betriebsart festlegen, aktivieren, deaktivieren, abschließen</span></a>
            <a href="<?= $href('users') ?>"><strong>Benutzer</strong><span>Anlegen, Funktionen zuweisen, sperren, entsperren und Kennwort zurücksetzen</span></a>
            <a href="<?= $href('self_registration') ?>"><strong>Selbstregistrierung</strong><span>Schließen, dauerhaft öffnen oder für 15 Minuten bis 24 Stunden freigeben</span></a>
            <a href="<?= $href('password_policy') ?>"><strong>Kennwortrichtlinie</strong><span>Mindestlänge und Zeichenanforderungen für neue Kennwörter</span></a>
            <a href="<?= $href('admin_command_post') ?>"><strong>Schichtverwaltung</strong><span>Dienstschichten in „Streng“, Zugangsgruppen in „Locker“</span></a>
            <a href="<?= $href('matrix') ?>"><strong>Empfängermatrix</strong><span>Genau 5 × 4 Felder; S2 bleibt Ziel der Rotkopie</span></a>
            <a href="<?= $href('counter') ?>"><strong>Nachrichtenzähler</strong><span>Nach dokumentiertem Betrieb auf Papier weitersetzen</span></a>
            <a href="<?= $href('form_reset') ?>"><strong>Vordruckmarkierung</strong><span>Abgeschlossene Vordrucke neu erzeugen lassen</span></a>
            <a href="<?= $href('system_status') ?>"><strong>Systemstatus</strong><span>Laufzeit, Datenbank, Speicher und Einstellungen prüfen</span></a>
          </div>
          <p>Sperren, eine neue Funktion und ein zurückgesetztes Kennwort
            beenden laufende Sitzungen sofort. Ein neues Kennwort geben Sie
            zweimal ein. Entsperren meldet niemanden an.</p>
          <h3>Selbstregistrierung</h3>
          <p>Sie öffnen sie dauerhaft oder für 15 Minuten bis 24 Stunden. Ein
            Zeitfenster endet von selbst. Auch ein vorher geöffnetes Formular
            wird beim Absenden erneut geprüft.</p>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>Nur unter Aufsicht öffnen</strong>
            <p>Solange die Selbstregistrierung offen ist, kann jede Person,
              die die Anmeldeseite erreicht, ein Konto mit einer der
              angebotenen Funktionen anlegen. Öffnen Sie sie deshalb nur in
              einem kontrollierten Netz und unter Aufsicht.</p>
          </div>
          <h3>Kennwortrichtlinie</h3>
          <p>Sie legen eine Mindestlänge zwischen 8 und 128 Zeichen fest.
            Voreingestellt sind 12. Zusätzlich können Sie einen
            Großbuchstaben, einen Kleinbuchstaben, eine Ziffer und ein
            Sonderzeichen verlangen. Ein Leerzeichen darf in einer
            Passphrase stehen, zählt aber nicht als Sonderzeichen.</p>
          <p>Vor dem Speichern zeigt eStab, was sich ändert. Eine
            Abschwächung wird hervorgehoben. Hat jemand anderes inzwischen
            gespeichert, fordert eStab zum erneuten Prüfen auf.</p>
          <p>Die Richtlinie gilt für neue Kennwörter. Vorhandene Kennwörter
            und Sitzungen bleiben gültig. Das technische Kennwort der
            Administration ändert sie nicht.</p>
          <p><a href="<?= $href('admin') ?>">Administration öffnen</a>.</p>
        </article>

        <article id="betrieb" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Installation Podman Docker Compose Container Secrets Port Health Backup Restore Upgrade TLS Reverse Proxy Volume">
          <header><span>19</span><h2>Installation und Betrieb</h2></header>
          <p>eStab läuft als Compose-Verbund aus Datenbank,
            Migrationsdienst, Admin-Einrichtung und Webanwendung. Die
            Kennwörter liegen in Dateien auf dem Server, nicht im Abbild und
            nicht im Browser.</p>
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
            <li>Voreingestellt hört eStab nur auf
              <code>127.0.0.1:8080</code>. Für den Zugriff aus dem Netz
              gehört ein Reverse Proxy mit TLS davor.</li>
            <li><a href="<?= $href('health') ?>">health.php</a> muss HTTP 200
              und <code>"status":"ready"</code> liefern.</li>
            <li>Vor jeder neuen Fassung und vor jeder Wiederherstellung
              gehört eine geprüfte Vollsicherung. Dafür liegen
              <code>deploy/registry/backup.sh</code>,
              <code>verify-backup.sh</code> und <code>restore.sh</code>
              bereit.</li>
            <li><code>podman compose down</code> behält die Daten.
              <strong><code>down --volumes</code> löscht sie.</strong> Nutzen
              Sie das nur nach geprüfter Sicherung.</li>
            <li>Verwenden Sie nur eine benannte, unveränderliche Fassung.
              <code>latest</code> ist kein freigegebener Stand.</li>
          </ul>
        </article>

        <article id="probleme" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Fehler Hilfe Unauthorized Anmeldung Kennwort gesperrt Einsatz Upload EML MSG Weg PDF Grenze Logs">
          <header><span>20</span><h2>Wenn etwas nicht geht</h2></header>
          <div class="estab-handbook-troubleshooting">
            <details><summary>Die Administration fragt sofort nach Benutzer und Kennwort</summary><p>Das ist die Abfrage des Browsers für HTTP Basic Auth. Benutzername und Kennwort stehen in der Datei mit dem technischen Admin-Kennwort. Sie sind nicht Ihr Funktionskonto. Manche eingebauten Browser zeigen die Abfrage nicht zuverlässig; nehmen Sie einen normalen Browser.</p></details>
            <details><summary>Die Anmeldung geht nicht</summary><p>Prüfen Sie Name, Kürzel, Funktion und Kennwort. Alle vier müssen zum Konto passen. Ist das Konto gesperrt oder wurde Ihre Sitzung beendet, hilft nur die Administration. Legen Sie kein zweites Konto mit demselben Kürzel an.</p></details>
            <details><summary>Das neue Kennwort wird abgewiesen</summary><p>Lesen Sie die angezeigte Richtlinie ganz. Mindestlänge und die verlangten Zeichenarten müssen gleichzeitig erfüllt sein. Beide Eingaben müssen genau übereinstimmen.</p></details>
            <details><summary>Ich kann nichts eintragen</summary><p>Prüfen Sie der Reihe nach: Sind Sie angemeldet? Ist ein Einsatz aktiv und offen? Steht der Name der Führungsstelle? Ist Ihr Konto entsperrt? In „Streng“ muss Ihre Besetzung angenommen und ausgewählt sein. In „Locker“ braucht Ihr Konto die passende Funktion.</p></details>
            <details><summary>Ein Ausgang kommt nicht beim Fernmelder an</summary><p>Er muss zuerst durch Si, dann durch den LdF. Der LdF braucht einen gültigen Weg aus dem freigegebenen Fernmeldeplan. Wurde die Nachricht zurückgegeben, steht der Grund im Vordruck; sie muss in der zuständigen Stufe bearbeitet werden.</p></details>
            <details><summary>Eine Datei lässt sich nicht hochladen</summary><p>Prüfen Sie die Grenze am Dateifeld, die Endung und den tatsächlichen Inhalt. Eine nur umbenannte Datei wird abgewiesen.</p></details>
            <details><summary>Eine E-Mail wird abgewiesen</summary><p>Speichern Sie die Mail als <code>.eml</code>. Das Format <code>.msg</code> aus Outlook liest eStab nicht. Endung, erkannter Typ, Aufbau und die Grenze von 20 MiB müssen zusammen passen.</p></details>
            <details><summary>Der PDF-Export bricht ab</summary><p>Eine beschädigte, verschlüsselte oder zu große Anlage bricht den Export bewusst ab. Prüfen Sie das Format, die einzelne Datei und die Summe von 50 MiB.</p></details>
          </div>
          <p>Bei technischen Fehlern sehen Sie zuerst in den
            <a href="<?= $href('system_status') ?>">Systemstatus</a>. Danach
            helfen <code>podman compose ps</code> und
            <code>podman compose logs --tail=100 db migrate admin-auth-init app</code>.
            Kopieren Sie keine Kennwörter und keine Einsatzdaten in eine
            öffentliche Fehlermeldung.</p>
        </article>

        <article id="begriffe" class="estab-handbook-chapter"
          data-estab-handbook-section
          data-handbook-keywords="Begriffe Glossar Streng Locker Zusatzfunktion Zugangsschicht LdF Si Fernmelder Nachweisung taktische Zeit">
          <header><span>21</span><h2>Begriffe</h2></header>
          <dl class="estab-handbook-glossary">
            <div><dt>Streng</dt><dd>Betriebsart eines Einsatzes. Funktion und Rechte kommen aus einer angenommenen und ausgewählten Besetzung der aktiven Dienstschicht.</dd></div>
            <div><dt>Locker</dt><dd>Betriebsart eines Einsatzes ohne Dienstschicht. Es gelten die feste Kontofunktion und vergebene Zusatzfunktionen.</dd></div>
            <div><dt>Zusatzfunktion</dt><dd>Eine Funktion, die die Administration einem Konto zusätzlich gibt. Sie wirkt nur in Einsätzen der Betriebsart „Locker“.</dd></div>
            <div><dt>Zugangsschicht</dt><dd>Eine Gruppe von Konten in „Locker“, mit der sich Zugänge gemeinsam ein- und ausschalten lassen. Sie gibt keine fachlichen Rechte.</dd></div>
            <div><dt>Si</dt><dd>Sichter. Prüft Ausgänge formal, bewertet Eingänge und legt die Empfänger fest.</dd></div>
            <div><dt>LdF</dt><dd>Leitung der Fernmeldebetriebsstelle. Wählt Wege, führt das Technische Betriebsbuch und erteilt Melderaufträge.</dd></div>
            <div><dt>Fernmelder</dt><dd>Nimmt Nachrichten auf und befördert sie tatsächlich.</dd></div>
            <div><dt>Gegenstelle</dt><dd>Die andere Seite einer Verbindung. Im Fernmeldeplan hängt sie an einem unserer Kommunikationsmittel.</dd></div>
            <div><dt>Nebenstelle</dt><dd>Ein Anschluss innerhalb der eigenen Führungsstelle.</dd></div>
            <div><dt>Nachweisung</dt><dd>Der Nachweis über Aufnahme und tatsächliche Beförderung. Die Nummern stammen aus dem Technischen Betriebsbuch.</dd></div>
            <div><dt>Taktische Zeit</dt><dd>Je nach Feld eine Uhrzeit oder Datum mit Uhrzeit. Die Form steht am Feld.</dd></div>
            <div><dt>Rotkopie</dt><dd>Die Durchschrift, die immer an S2 geht.</dd></div>
            <div><dt>Blaue Durchschrift</dt><dd>Die Durchschrift für den benannten Bearbeiter.</dd></div>
          </dl>
          <div class="estab-handbook-callout estab-handbook-callout-important">
            <strong>Was die Nachweise leisten</strong>
            <p>eStab verkettet seine Einträge und bildet Prüfsummen. Damit
              lässt sich zeigen, dass nachträglich nichts verändert wurde.
              Das ist keine digitale Signatur und ersetzt keine
              vorgeschriebene Freigabe.</p>
          </div>
        </article>
      </div>
    </div>
  </main>

  <footer class="estab-handbook-footer">
    <p>eStab Handbuch · Fassung <?= estab_auth_html($handbookVersion) ?>
      · Stand <?= estab_auth_html($handbookUpdated) ?></p>
    <p>Es beschreibt die Bedienung der Fassung, die mit dieser Anwendung
      ausgeliefert wird.</p>
    <p><a href="<?= $href('home') ?>">Zur Übersicht</a> ·
      <a href="#handbook-content">Zum Seitenanfang</a></p>
  </footer>
</body>
</html>
