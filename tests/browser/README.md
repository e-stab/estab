# Echter Browser-Akzeptanztest

`headless_ui.py` prüft den benutzerorientierten Anmelde- und
Navigationsablauf mit einem echten Chrome- oder Chromium-Browser. Der Test
verwendet nur die Python-Standardbibliothek und steuert den Browser direkt über
das Chrome DevTools Protocol. Node.js, Playwright und Selenium sind nicht
erforderlich.

Geprüft werden:

- die anonyme Übersicht mit eindeutigem Bestandslogin, deaktivierter
  öffentlicher Kontoanlage und Verweis auf die Benutzerverwaltung;
- die sichtbaren, anonym verständlich zum Login führenden Modulkarten sowie
  HTTP 403 bei direkten Zugriffen auf geschützte Fachendpunkte;
- das Anmelden eines zuvor über die Benutzerverwaltungs-API provisionierten
  Funktionskontos im Zwei-`iframe`-Nachrichtenarbeitsbereich;
- genau eine sichtbare, dauerhaft verfügbare Session-Bar im gesamten
  Nachrichtenarbeitsbereich sowie Name, Kürzel, Funktion, abgeleitete Rolle,
  gemeinsame Navigation und Abmeldebutton in der vollhohen
  `vorgaben`-Sidebar; der rechte `mainframe` enthält keine Duplikate;
- ausschließlich die beiden modernen `iframe`-Elemente `vorgaben` und
  `mainframe`, eine Statuskarte mit rollenabhängigem Zähler, Serverzeit und
  Onlinebelegung sowie echte rollenabhängige Textbuttons;
- die acht Kernbereiche der gemeinsamen Navigation in stabiler Reihenfolge
  sowie genau ein zum geöffneten Bereich passendes `aria-current="page"` und
  Top-Level-Ziele für alle Kernlinks;
- alle zehn Bereichs- und Dienstlinks dauerhaft sichtbar und mit mindestens
  44 × 44 CSS-Pixeln Bedienfläche, ohne „Bereich wechseln“-Disclosure und ohne
  eigene Scrollfläche; der sichtbare Link `Übersicht` verlässt den
  Nachrichtenarbeitsbereich im Top-Level-Kontext;
- die vollhohe Sidebar bei `1440x1000`, `1280x720` und `700x760`
  CSS-Pixeln: Status, Identität, Navigation und Aktionen überlappen nicht,
  passen horizontal und verwenden bei Bedarf ausschließlich die einzige
  dokumentweite Sidebar-Scrollfläche;
- den authentifizierten Nachrichtenarbeitsbereich bei `390x844` CSS-Pixeln:
  Sidebar und Inhalt bilden zwei volle Viewport-Zeilen, eine echte Rollenaktion
  scrollt samt Fokus vollständig zum geladenen Inhaltsframe und der sichtbare,
  mindestens 44 × 44 Pixel große Button „Menü“ führt samt Fokus vollständig
  zur Sidebar zurück;
- einen echten Statusfragment-Refresh, der genau die Statuskarte ersetzt und
  Fokus eines Aktionsbuttons und des ersetzten Hinweiston-Schalters sowie die
  Scrollposition der Sidebar erhält; ein simulierter HTTP-503-Abruf setzt
  sichtbar „Status nicht aktuell“, lässt die Navigation bedienbar und der
  folgende erfolgreiche Abruf meldet die Erholung;
- die gleich-originige Audioquelle als RIFF/WAVE mit PCM-Format, den zunächst
  deaktivierten Schalter zur ausdrücklichen Browserfreigabe, seine sichtbare
  Zustands-/Fehlerrückmeldung und das langlebige Audioelement außerhalb des
  ersetzten Statusfragments; eine simulierte Browserblockade bleibt nach
  Reload als noch nicht freigegeben erkennbar, ein `StorageEvent` synchronisiert
  Ein/Aus und ein Statusrefresh mit Auslösemarker fordert automatisch genau
  eine Wiedergabe an; verzögert auflösende Wiedergabeversuche können per
  erneutem Klick oder `StorageEvent` sicher abgebrochen werden;
- ein geändertes Nachrichtenfeld, der native Verlustwarnungsdialog, der nach
  Ablehnen unveränderte Formularwert und der erst nach Bestätigen ausgeführte
  Bereichswechsel;
- interne Karten der angemeldeten Übersicht ohne neues Browser-Tab;
- das Öffnen der Infosammlung BOS durch einen echten Klick auf ihre Root-Karte,
  den separat beibehaltenen kompakten Disclosure-Modus beim Wechsel einer
  statischen BOS-Inhaltsseite, das reale Öffnen seiner Bereichsauswahl und die
  Rückkehr über `Übersicht`;
- das Öffnen des geschützten Einsatztagebuchs durch einen echten Klick auf
  seine Root-Karte, den richtigen Pfad und den aktiven Navigationsbereich
  `incident-log`;
- ein echter Mausklick auf `Abmelden` im Einsatztagebuch, die ungültig
  gewordene Sitzung und die Rückkehr zur anonymen Übersicht;
- das Kartenraster bei `1440`, `1120`, `800`, `700`, `672` und `390`
  CSS-Pixeln: Jede Klickfläche bleibt vollständig in ihrer Karte, Karten und
  Klickflächen überdecken keine Nachbarn und ein echter Hover verändert weder
  Geometrie noch Zwischenräume;
- ein über das DevTools-Protokoll exakt auf `390x844` CSS-Pixel gesetzter
  Viewport, in dem öffentliche Leiste, Kopf, Login-Karte und einspaltige
  Modulkarten innerhalb der Breite bleiben, die Bereichsnavigation erreichbar
  ist und zentrale Login-Schaltflächen mindestens 44 Pixel hoch sind;
- mit separaten ephemeren Admin-Testdaten die Basic-Auth-geschützte
  Adminübersicht mit genau fünf klar getrennten Maßnahmen bei `1280x800` und
  `390x844` CSS-Pixeln: Navigation, Adminidentität und Karten bleiben sichtbar,
  die Karten überlappen sich nicht, erzeugen kein horizontales
  Dokument-Scrolling, sind vollständig klickbar und bilden mobil eine
  einheitliche Spalte;
- die Exportübersicht bei `1280x800` und `390x844` CSS-Pixeln, die echte
  Exporterstellung, das offene Manifest, genau einen Downloadlink sowie die
  zunächst geschlossene und bewusst zweistufig geöffnete Löschbestätigung,
  den bestätigten Löschvorgang und das anschließende Verschwinden genau dieses
  Exports; Karten bleiben innerhalb des Viewports und alle Aktionen mindestens
  44 × 44 Pixel groß; anschließend im Matrixeditor die beiden verständlichen
  Bestätigungsdialoge für „Standard laden“ und „Standard ersetzen“ sowie den
  unveränderten Editorwert und Seitenzustand nach ihrer Ablehnung.

Die stabile Reihenfolge der Navigationsbereiche lautet:

```text
overview
messages
message-overview
forms
incident-log
technical-log
tracking
bos-info
```

Die Navigation wird über `data-estab-navigation`, ihre Links über
`data-estab-nav-key` und der aktive Bereich über `aria-current="page"`
identifiziert. Der Link `overview` muss sichtbar mit `Übersicht` beschriftet
sein. Diese stabilen Attribute vermeiden, dass der Test von rein gestalterischen
CSS-Klassen oder übersetzten Modulbeschreibungen abhängt.

## Voraussetzungen

Die Anwendung muss laufen, die Selbstregistrierung muss freigeschaltet sein und
das gewählte Kürzel darf noch nicht existieren. Für wiederholte Läufe sollte
deshalb ein frisches Test-Deployment oder jedes Mal ein neues, höchstens sechs
Zeichen langes Kürzel verwendet werden.

Chrome wird über `ESTAB_BROWSER_BINARY` oder automatisch an den üblichen
macOS- und Linux-Pfaden gesucht.

## Ausführen

Die anonyme Startseite lässt sich ohne Testkonto und ohne Änderungen an
Anwendungsdaten gezielt prüfen. Dieser Lauf kontrolliert unter anderem die
Bezeichnung `Technisches Betriebsbuch (TBB)`, die Anmeldeziele und das
überlappungsfreie Kartenlayout:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
python3 tests/browser/headless_ui.py --overview-only
```

Der vollständige zustandsverändernde Akzeptanzlauf benötigt dagegen ein
eigens dafür provisioniertes Testkonto:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
ESTAB_TEST_LOGIN_NAME='Browser Test' \
ESTAB_TEST_LOGIN_CODE=brw001 \
ESTAB_TEST_LOGIN_FUNCTION=S1 \
python3 tests/browser/headless_ui.py
```

Die Exportverwaltung und die beiden destruktiven Matrix-Bestätigungen lassen
sich auf einem isolierten Test-Deployment gezielt prüfen. Der Lauf erzeugt
einen eigenen Export und löscht genau diesen anschließend wieder. Im
Matrixeditor lehnt er beide Bestätigungsdialoge ab und verändert daher weder
aktive noch gespeicherte Standardmatrix:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
ESTAB_TEST_ADMIN_USER=estab-admin \
ESTAB_TEST_ADMIN_PASSWORD_FILE=secrets/admin_password.txt \
python3 tests/browser/headless_ui.py --export-only
```

Wenn weder `ESTAB_TEST_LOGIN_PASSWORD` noch
`ESTAB_TEST_LOGIN_PASSWORD_FILE` gesetzt ist, erzeugt der Test intern mit
`secrets.token_urlsafe(32)` ein starkes ephemeres Kennwort. Es wird niemals
ausgegeben oder in einer Diagnosedatei gespeichert. Das ist der empfohlene Weg
für ein wegwerfbares Test-Deployment.

Falls sich ein späterer Testlauf erneut mit demselben Konto anmelden soll, kann
das Kennwort stattdessen aus einer eigenen Test-Secret-Datei gelesen werden:

```sh
ESTAB_TEST_LOGIN_PASSWORD_FILE=secrets/test_login_password.txt \
python3 tests/browser/headless_ui.py
```

`ESTAB_TEST_LOGIN_PASSWORD` hat Vorrang, falls beide Varianten gesetzt sind.
Produktive Kennwörter dürfen dafür nicht verwendet werden. Der optionale
Export-Browsertest liest entsprechend nur den ephemeren Admin-Benutzer und
das Admin-Kennwort des isolierten Test-Stacks. Der Test gibt keines der
Kennwörter im Erfolgsfall, in Fehlermeldungen oder Diagnosedateien aus.

Weitere Einstellungen:

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `ESTAB_TEST_BASE_URL` | `http://127.0.0.1:8080` | Basis-URL des Test-Deployments |
| `ESTAB_TEST_LOGIN_NAME` | `Browser Acceptance` | Anzeigename des neuen Kontos |
| `ESTAB_TEST_LOGIN_CODE` | `brw001` | Neues Kürzel, 1–6 Zeichen |
| `ESTAB_TEST_LOGIN_FUNCTION` | `S1` | Im Formular vorhandene Funktion |
| `ESTAB_TEST_LOGIN_PASSWORD` | zufällig erzeugt | Optionales, ausschließlich für diesen Browser-Test bestimmtes Kennwort |
| `ESTAB_TEST_LOGIN_PASSWORD_FILE` | nicht gesetzt | Optionale Datei mit dem Testkennwort |
| `ESTAB_TEST_ADMIN_USER` | nicht gesetzt | Optionaler Admin-Benutzer des isolierten Test-Stacks; aktiviert zusammen mit einem Kennwort den Export-Browsertest |
| `ESTAB_TEST_ADMIN_PASSWORD` | nicht gesetzt | Optionales ephemeres Admin-Testkennwort |
| `ESTAB_TEST_ADMIN_PASSWORD_FILE` | nicht gesetzt | Bevorzugte Secret-Datei mit dem ephemeren Admin-Testkennwort |
| `ESTAB_BROWSER_BINARY` | automatische Suche | Chrome-/Chromium-Programm |
| `ESTAB_BROWSER_TIMEOUT` | `25` | Timeout je Browseraktion in Sekunden |
| `ESTAB_BROWSER_STARTUP_TIMEOUT` | `15` | Browser-Starttimeout in Sekunden |
| `ESTAB_BROWSER_ARTIFACT_DIR` | temporäres Verzeichnis | Stammordner für Fehlerdiagnosen |
| `ESTAB_BROWSER_NO_SANDBOX` | aus | `true` nur für entsprechend isolierte CI-Container |

Die reine Browser-Erkennung lässt sich ohne laufende Anwendung prüfen:

```sh
python3 tests/browser/headless_ui.py --check-browser
```

## Fehlerdiagnose

Bei einem Testfehler nennt der Test ein Diagnoseverzeichnis. Soweit Chrome noch
erreichbar ist, enthält es:

- `failure.png` mit dem zuletzt sichtbaren Browserzustand;
- `state.json` mit URL, Seitentitel, Frame-URLs, der Anzahl gefundener
  Session-Bars sowie den gefundenen und aktiven Navigationsschlüsseln.

`state.json` enthält bewusst weder Formularinhalte noch Kennwörter. Der
Screenshot kann andere sichtbar eingegebene Formularwerte abbilden;
Kennwortfelder bleiben browserseitig maskiert. Diagnoseartefakte sind deshalb
wie Testdaten vertraulich zu behandeln. Ein fehlgeschlagener Lauf entfernt
außerdem sein temporäres Chrome-Profil.

## Abgrenzung

Der bestehende Konto-Flow wird durch einen echten Klick bis zum korrekten
Bestandsloginformular geprüft; die erfolgreiche Browseranmeldung verwendet
weiterhin die Neuanlage eines wegwerfbaren Testkontos. Die HTTP-Integration
weist die erfolgreiche Anmeldung eines vorhandenen Kontos separat nach.

Der Browserlauf prüft die vollständigen Klickpfade im Desktop-Viewport, die
Sidebar zusätzlich bei `1440x1000`, `1280x720`, `700x760` und authentifiziert
bei `390x844` CSS-Pixeln, das Kartenraster an vier Zwischenbreiten und die
zentrale öffentliche Übersicht ebenfalls bei exakt `390x844` CSS-Pixeln. Das
belegt Navigation, Anmeldeeinstieg, den Zwei-`iframe`-Arbeitsbereich ohne
verschachtelte Scrollflächen, die mobilen Vollviewport-Zeilen samt
Rollenaktionswechsel und „Menü“-Rückweg, überlappungsfreie Karten samt Hover
und Bediengrößen auf schmalen Displays.

Für den Audiotest ersetzt der Lauf `HTMLMediaElement.play()` kontrolliert
zunächst durch eine abgewiesene Promise und danach durch einen Aufrufzähler.
Damit weist er PCM-WAV-Datei, expliziten Opt-in, Browserblockade, Reload- und
tabübergreifenden Zustandswechsel, sichtbaren Rückfall sowie manuell und
automatisch angeforderte Wiedergabe und das racesichere Verwerfen verspäteter
Ergebnisse nach, aber nicht, dass Lautsprecher, Lautstärke, Browser und
Betriebssystem den Ton physisch hörbar ausgeben. Diese Hörprobe bleibt
verpflichtender Bestandteil der manuellen Fachabnahme. Die fachinterne
Bedienung sämtlicher historischer Formulare im rechten Inhalts-`iframe` wird
ebenfalls nicht als vollständig mobil optimiert oder fachlich abgenommen
behauptet.
