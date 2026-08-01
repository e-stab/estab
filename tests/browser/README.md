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
  die 303-Weiterleitung direkter Fachseiten und Download-Tabs zum
  Bestandslogin mit exakt passendem, erlaubtem Zielschlüssel;
- die Auswahl des Content-Logins über `Sec-Fetch-Dest` sowie dessen
  headerunabhängige Verwendung durch intrinsisch mainframe-lokale Anhangs- und
  Kategoriecontroller, ohne den Zwei-`iframe`-Arbeitsbereich in seinem rechten
  Inhaltsframe zu verschachteln;
- eine abgelaufene operative GET-Query, deren Werte vollständig verworfen
  werden und die ausschließlich zum erlaubten Ziel `messages` führt, während
  Zugangsdaten und Login-Metadaten in GET hart abgewiesen bleiben;
- `target="_self"` auf allen Loginformularen;
- den immer sichtbaren Abbruch der Anmeldung und die zuverlässige
  Top-Level-Rückkehr zur öffentlichen Übersicht auch aus dem `mainframe`;
- einen operativen Formular-POST ohne gültige Sitzung, den frame-sicheren
  Bestandslogin, den sichtbaren Hinweis auf die nicht gespeicherte Eingabe
  sowie dessen ebenfalls funktionierenden Abbruch zur Übersicht;
- das Anmelden eines zuvor über die Benutzerverwaltungs-API provisionierten
  Funktionskontos und den direkten Einstieg in den
  Zwei-`iframe`-Nachrichtenarbeitsbereich ohne Schicht- oder Hutauswahl;
- genau eine sichtbare, dauerhaft verfügbare Session-Bar im gesamten
  Nachrichtenarbeitsbereich sowie Name, Kürzel, Funktion, abgeleitete Rolle,
  gemeinsame Navigation und Abmeldebutton in der vollhohen
  `vorgaben`-Sidebar; der rechte `mainframe` enthält keine Duplikate;
- ausschließlich die beiden modernen `iframe`-Elemente `vorgaben` und
  `mainframe`, eine Statuskarte mit rollenabhängigem Zähler, Serverzeit und
  Onlinebelegung sowie echte rollenabhängige Textbuttons;
- die kanonischen neun Kernbereiche in stabiler Reihenfolge, nach der
  Anmeldung jedoch nur die für die feste Kontofunktion freigegebenen
  Bereiche, sowie genau ein zum geöffneten Bereich passendes
  `aria-current="page"` und Top-Level-Ziele für alle sichtbaren Kernlinks;
- alle funktionsabhängig sichtbaren Bereichs- und Dienstlinks dauerhaft und
  mit mindestens 44 × 44 CSS-Pixeln Bedienfläche, ohne
  „Bereich wechseln“-Disclosure und ohne eigene Scrollfläche; für die im
  Standardlauf verwendete Funktion S1 sind dies neun Links einschließlich
  Administration und Handbuch. Der sichtbare Link `Übersicht` verlässt den
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
- den amtlichen Nachrichtenvordruck im echten Browser: drei unverändert
  geordnete Zonen, feste 896-Pixel-Blattbreite, schwarzes Raster, den
  Blauton `#A2D9F7`, fehlenden VS-NfD-Aufdruck und fehlende Bilder sowie alle
  20 eindeutig zugeordneten Ausfüllhilfen; Außenklick und `Escape` schließen
  mit korrektem Fokus, jeder Dialog erhält beim Öffnen selbst den Fokus,
  Dialoge bleiben im Viewport und bei `390x844` liegt
  ausschließlich das Blatt in seinem beschrifteten horizontalen
  Scrollbereich; die drei Zeitstempel besitzen jeweils getrennte berechnete
  Datum-/Uhrzeit-/Hdz.-Zellen bei nur einem zugänglichen Backend-Zeitfeld,
  ein alleiniger Zeitwert liegt ausschließlich in der Uhrzeitzelle,
  Anschrift und Rufnummer überdecken sich nicht, der linke Steg ist
  durchgehend blau und Legende sowie beide weißen Lochmarken liegen sichtbar
  im Blatt; der feste Verteiler enthält Leiter sowie S1–S6 einschließlich S5
  und jeweils genau sechs Zeilen für Fachberater und Verbindungsstellen;
  dynamische Empfänger und die gemeinsame Auswahl für keinen oder genau einen
  Empfänger der einen grünen Durchschrift liegen als digitale Ergänzung
  außerhalb des amtlichen Blatts; nach dem echten Rendern wird das Formular
  mit rund 184,9 mm Blattbreite druckisoliert und per Chrome-`Page.printToPDF`
  auf genau ein A4-Seitenobjekt geprüft, ohne mobilen Wischhinweis oder
  Fragmentierung; anschließend öffnet der Test genau die von Chrome erzeugten
  PDF-Bytes erneut in Chromes integriertem PDF-Renderer und prüft im
  sichtbaren Renderergebnis die blaue Formularfläche und das schwarze Raster;
- interne Karten der angemeldeten Übersicht ohne neues Browser-Tab;
- das Öffnen der Infosammlung BOS durch einen echten Klick auf ihre Root-Karte,
  die gemeinsame Darstellungshülle der historischen Dokumente und die
  Rückkehr über `Übersicht`;
- das Öffnen des geschützten Einsatztagebuchs durch einen echten Klick auf
  seine Root-Karte, den richtigen Pfad und den aktiven Navigationsbereich
  `incident-log`;
- die angemeldete Führungsstellenansicht bei `1280x800` und `390x844`
  CSS-Pixeln mit genau einer Session-Bar, aktivem Navigationsbereich
  `command-post`, vollständig innerhalb des Viewports liegendem Inhalt und
  ohne horizontales Dokument-Scrolling;
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
  Adminübersicht mit zehn klar getrennten Maßnahmen bei `1280x800` und
  `390x844` CSS-Pixeln: Navigation, Adminidentität und Karten bleiben sichtbar,
  die Karten überlappen sich nicht, erzeugen kein horizontales
  Dokument-Scrolling, sind vollständig klickbar und bilden mobil eine
  einheitliche Spalte;
- die Kennwortrichtlinien-Seite bei `1280x800` und `390x844` CSS-Pixeln als
  vollständig geladene Werkzeugseite mit genau einer gemeinsamen Leiste,
  sichtbarer Navigation, Kopf- und Fußbereich sowie einer mindestens
  44 × 44 Pixel großen Aktion; Inhalt und Bedienelemente bleiben innerhalb
  des Viewports und erzeugen kein horizontales Dokument-Scrolling; in der
  Benutzerverwaltung zählt der echte Browser die Mindestlänge außerdem in
  Unicode-Codepoints statt UTF-16-Eingabeeinheiten, weist eine zu kurze
  Emoji-Passphrase ab und akzeptiert dieselbe Anzahl vollständiger Codepoints;
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
command-post
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
sein. Nach Auswahl einer S1-Dienstfunktion werden `message-overview` und
`tracking` ausgeblendet; S2 erhält `message-overview`, LdF und A/W erhalten
`tracking`. Diese stabilen Attribute vermeiden, dass der Test von rein
gestalterischen CSS-Klassen oder übersetzten Modulbeschreibungen abhängt.

## Voraussetzungen

Die Anwendung muss laufen. Für den vollständigen Lauf muss ein ausschließlich
dafür bestimmtes Bestandskonto bereits über die Benutzerverwaltung angelegt
und fest der Funktion `S1` zugewiesen sein. Eine Dienstschicht oder persönliche
Schichtannahme ist nicht erforderlich. Das Konto darf keiner ausschließlich
deaktivierten Zugangsschicht angehören. Öffentliche Selbstregistrierung bleibt
ausgeschaltet. Der Test verändert dieses Konto nicht und verwendet keine
produktiven Zugangsdaten.

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

Die wiederherstellbaren Anmeldepfade lassen sich ebenfalls ohne Testkonto und
ohne Änderung von Anwendungsdaten prüfen. Der Modus öffnet eine Fachseite
direkt, bricht den Login zur Übersicht ab, verwirft eine alte operative
Nachrichten-Query und sendet ein simuliertes Nachrichtenformular ohne gültige
Sitzung ab. Danach öffnet er die intrinsisch mainframe-lokalen Anhangs- und
Kategoriecontroller eingebettet. Erwartet werden das richtige Content-Login
ohne verschachtelten Arbeitsbereich, ausschließlich das erlaubte Ziel
`messages`, `target="_self"` auf allen Loginformularen, der Hinweis auf die
nicht gespeicherte Eingabe und ein funktionierender Top-Level-Abbruch ohne
manuelle Änderung der Browseradresse:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
python3 tests/browser/headless_ui.py --auth-recovery-only
```

Die öffentliche BOS-Infosammlung kann ebenfalls rein lesend geprüft werden.
Dieser Lauf öffnet alle sieben Dokumente bei Desktop- und Mobilbreite und
vergleicht jeweils den unveränderten Originaltext mit der einheitlich
dargestellten Dokumentfläche. Außerdem prüft er Dokumentkopf, Navigation,
Tabellenwrapper, Bilder, Fokuszustände und horizontalen Überlauf:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
python3 tests/browser/headless_ui.py --bos-only
```

Das öffentliche Web-Handbuch besitzt einen eigenen rein lesenden Lauf. Er
prüft alle 19 Kapitel, die Einbindung in die öffentliche Navigation, die
lokale Mehrwortsuche samt zurücksetzbarer URL-Abfrage sowie das
überlauffreie Desktop- und Mobil-Layout:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
python3 -B tests/browser/headless_ui.py --handbook-only
```

Der vollständige zustandsverändernde Akzeptanzlauf benötigt dagegen ein
eigens dafür provisioniertes Testkonto:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
ESTAB_TEST_LOGIN_NAME='Browser Test' \
ESTAB_TEST_LOGIN_CODE=brw001 \
ESTAB_TEST_LOGIN_FUNCTION=S1 \
ESTAB_TEST_LOGIN_PASSWORD_FILE=secrets/test_login_password.txt \
python3 tests/browser/headless_ui.py
```

Die Administrationsoberflächen, Exportverwaltung und die beiden destruktiven
Matrix-Bestätigungen lassen sich auf einem isolierten Test-Deployment gezielt
prüfen. Der Lauf kontrolliert unter anderem die zehnte Admin-Karte sowie die
Kennwortrichtlinien-Seite auf Desktop und Mobil, ohne die Richtlinie zu
ändern. Er erzeugt einen eigenen Export und löscht genau diesen anschließend
wieder. Im Matrixeditor lehnt er beide Bestätigungsdialoge ab und verändert
daher weder aktive noch gespeicherte Standardmatrix:

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
ESTAB_TEST_ADMIN_USER=estab-admin \
ESTAB_TEST_ADMIN_PASSWORD_FILE=secrets/admin_password.txt \
python3 tests/browser/headless_ui.py --export-only
```

Der vollständige Lauf benötigt das zum provisionierten Konto passende Kennwort
aus einer eigenen Test-Secret-Datei:

```sh
ESTAB_TEST_LOGIN_PASSWORD_FILE=secrets/test_login_password.txt \
python3 tests/browser/headless_ui.py
```

Ohne eine der beiden Kennwortvariablen bricht der vollständige Lauf
verständlich ab; ein zufälliges Kennwort könnte ein vorhandenes Konto nicht
authentisieren. Die lesenden Modi `--overview-only`,
`--auth-recovery-only`, `--bos-only` und `--handbook-only` sowie der
unabhängig authentisierte Modus `--export-only` benötigen kein
Anwendungskennwort. `ESTAB_TEST_LOGIN_PASSWORD` hat Vorrang, falls beide
Varianten gesetzt sind.
Produktive Kennwörter dürfen dafür nicht verwendet werden. Der optionale
Administrations-Browsertest liest entsprechend nur den ephemeren
Admin-Benutzer und das Admin-Kennwort des isolierten Test-Stacks. Der Test
gibt keines der Kennwörter im Erfolgsfall, in Fehlermeldungen oder
Diagnosedateien aus.

Weitere Einstellungen:

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `ESTAB_TEST_BASE_URL` | `http://127.0.0.1:8080` | Basis-URL des Test-Deployments |
| `ESTAB_TEST_LOGIN_NAME` | `Browser Acceptance` | Anzeigename des provisionierten Testkontos |
| `ESTAB_TEST_LOGIN_CODE` | `brw001` | Kürzel des provisionierten Testkontos, 1–6 Zeichen |
| `ESTAB_TEST_LOGIN_FUNCTION` | `S1` | Im Formular vorhandene Funktion |
| `ESTAB_TEST_LOGIN_PASSWORD` | nicht gesetzt | Kennwort des Testkontos; für den vollständigen Lauf erforderlich, sofern keine Datei gesetzt ist |
| `ESTAB_TEST_LOGIN_PASSWORD_FILE` | nicht gesetzt | Bevorzugte Datei mit dem Kennwort des Testkontos |
| `ESTAB_TEST_ADMIN_USER` | nicht gesetzt | Optionaler Admin-Benutzer des isolierten Test-Stacks; aktiviert zusammen mit einem Kennwort den Administrations-Browsertest |
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
Bestandsloginformular und durch die erfolgreiche Anmeldung des zuvor
provisionierten Wegwerf-Testkontos geprüft. Die feste Kontofunktion öffnet das
vor der Anmeldung angeforderte Einsatztagebuch direkt; eine Schicht- oder
Hutauswahl findet nicht statt. Der Lauf legt weder Konten noch Funktionen an;
diese administrative Vorbedingung weist die HTTP-Integration separat nach.

Der Browserlauf prüft die vollständigen Klickpfade im Desktop-Viewport, die
Sidebar zusätzlich bei `1440x1000`, `1280x720`, `700x760` und authentifiziert
bei `390x844` CSS-Pixeln, das Kartenraster an vier Zwischenbreiten und die
zentrale öffentliche Übersicht ebenfalls bei exakt `390x844` CSS-Pixeln. Das
belegt Navigation, Anmeldeeinstieg, den Zwei-`iframe`-Arbeitsbereich ohne
verschachtelte Scrollflächen, die mobilen Vollviewport-Zeilen samt
Rollenaktionswechsel und „Menü“-Rückweg, überlappungsfreie Karten samt Hover
und Bediengrößen auf schmalen Displays.

Die Kennwortrichtlinien-Seite wird dabei visuell und responsiv, aber ohne
Zustandsänderung geprüft. Vorschau, ausdrückliche Bestätigung, Revision,
CSRF-Schutz und Audit der Richtlinienänderung weist der getrennte
Admin-HTTP-Integrationstest nach.

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
