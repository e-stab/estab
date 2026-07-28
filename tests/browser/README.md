# Echter Browser-Akzeptanztest

`headless_ui.py` prüft den benutzerorientierten Anmelde- und
Navigationsablauf mit einem echten Chrome- oder Chromium-Browser. Der Test
verwendet nur die Python-Standardbibliothek und steuert den Browser direkt über
das Chrome DevTools Protocol. Node.js, Playwright und Selenium sind nicht
erforderlich.

Geprüft werden:

- die anonyme Übersicht mit klar getrennten Wegen für ein bestehendes und ein
  neues Konto;
- die sichtbaren, anonym verständlich zum Login führenden Modulkarten sowie
  HTTP 403 bei direkten Zugriffen auf geschützte Fachendpunkte;
- das Anlegen und unmittelbare Anmelden eines neuen Funktionskontos im
  Legacy-Frameset;
- genau eine sichtbare, dauerhaft verfügbare Session-Bar im gesamten
  Anwendungs-Frameset sowie Name, Kürzel, Funktion, abgeleitete Rolle,
  gemeinsame Navigation und Abmeldebutton;
- die acht Kernbereiche der gemeinsamen Navigation in stabiler Reihenfolge
  sowie genau ein zum geöffneten Bereich passendes `aria-current="page"` und
  Top-Level-Ziele für alle Kernlinks;
- das reale Öffnen der kompakten Bereichsauswahl und die Rückkehr aus dem
  Anwendungs-Frameset über den sichtbaren Link `Übersicht`;
- ein geändertes Nachrichtenfeld, der native Verlustwarnungsdialog, der nach
  Ablehnen unveränderte Formularwert und der erst nach Bestätigen ausgeführte
  Bereichswechsel;
- interne Karten der angemeldeten Übersicht ohne neues Browser-Tab;
- das Öffnen der Infosammlung BOS durch einen echten Klick auf ihre Root-Karte,
  die persistente kompakte Navigation beim Wechsel einer statischen
  BOS-Inhaltsseite, das reale Öffnen ihrer Bereichsauswahl und die Rückkehr
  über `Übersicht`;
- das Öffnen des geschützten Einsatztagebuchs durch einen echten Klick auf
  seine Root-Karte, den richtigen Pfad und den aktiven Navigationsbereich
  `incident-log`;
- ein echter Mausklick auf `Abmelden` im Einsatztagebuch, die ungültig
  gewordene Sitzung und die Rückkehr zur anonymen Übersicht;
- ein über das DevTools-Protokoll exakt auf `390x844` CSS-Pixel gesetzter
  Viewport, in dem öffentliche Leiste, Kopf, Login-Karte und einspaltige
  Modulkarten innerhalb der Breite bleiben, die Bereichsnavigation erreichbar
  ist und zentrale Login-Schaltflächen mindestens 44 Pixel hoch sind.

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

```sh
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
ESTAB_TEST_LOGIN_NAME='Browser Test' \
ESTAB_TEST_LOGIN_CODE=brw001 \
ESTAB_TEST_LOGIN_FUNCTION=S1 \
python3 tests/browser/headless_ui.py
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
Produktive Kennwörter und insbesondere das separate Administrationskennwort
dürfen dafür nicht verwendet werden. Der Test gibt das Kennwort weder im
Erfolgsfall noch in Fehlermeldungen oder Diagnosedateien aus.

Weitere Einstellungen:

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `ESTAB_TEST_BASE_URL` | `http://127.0.0.1:8080` | Basis-URL des Test-Deployments |
| `ESTAB_TEST_LOGIN_NAME` | `Browser Acceptance` | Anzeigename des neuen Kontos |
| `ESTAB_TEST_LOGIN_CODE` | `brw001` | Neues Kürzel, 1–6 Zeichen |
| `ESTAB_TEST_LOGIN_FUNCTION` | `S1` | Im Formular vorhandene Funktion |
| `ESTAB_TEST_LOGIN_PASSWORD` | zufällig erzeugt | Optionales, ausschließlich für diesen Browser-Test bestimmtes Kennwort |
| `ESTAB_TEST_LOGIN_PASSWORD_FILE` | nicht gesetzt | Optionale Datei mit dem Testkennwort |
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

Der Browserlauf prüft die vollständigen Klickpfade im Desktop-Viewport und die
zentrale öffentliche Übersicht zusätzlich bei exakt `390x844` CSS-Pixeln. Das
belegt Navigation, Anmeldeeinstieg, Kartenraster und Bediengrößen auf schmalen
Displays. Die fachinterne Bedienung sämtlicher historischer Formulare im
mehrspaltigen Legacy-Frameset wird damit nicht als vollständig mobil optimiert
oder fachlich abgenommen behauptet.
