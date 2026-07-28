# Echter Browser-Akzeptanztest

`headless_ui.py` prüft den vollständigen, benutzerorientierten
Anmeldeablauf mit einem echten Chrome- oder Chromium-Browser. Der Test verwendet
nur die Python-Standardbibliothek und steuert den Browser direkt über das Chrome
DevTools Protocol. Node.js, Playwright und Selenium sind nicht erforderlich.

Geprüft werden:

- die anonyme Startseite mit klar getrennten Wegen für ein bestehendes und ein
  neues Konto;
- die sichtbaren, aber für anonyme Zugriffe mit HTTP 403 gesperrten
  Modulkarten;
- das Anlegen und unmittelbare Anmelden eines neuen Funktionskontos im
  Legacy-Frameset;
- genau eine sichtbare, dauerhaft verfügbare Session-Bar im gesamten Frameset
  (im Anwendungs-Navigationsframe) sowie Name, Kürzel, Funktion, abgeleitete
  Rolle, Startseitenlink und Abmeldebutton;
- die persistente kompakte Session-Bar im echten BOS-Navigationsframe, auch
  nach dem Öffnen einer statischen BOS-Inhaltsseite;
- genau eine Session-Bar auf der angemeldeten Startseite;
- ein echter Mausklick auf `Abmelden`, die ungültig gewordene Sitzung und die
  Rückkehr zur anonymen Startseite.

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
- `state.json` mit URL, Seitentitel, Frame-URLs und der Anzahl gefundener
  Session-Bars.

`state.json` enthält bewusst weder Formularinhalte noch Kennwörter. Der
Screenshot kann andere sichtbar eingegebene Formularwerte abbilden;
Kennwortfelder bleiben browserseitig maskiert. Diagnoseartefakte sind deshalb
wie Testdaten vertraulich zu behandeln. Ein fehlgeschlagener Lauf entfernt
außerdem sein temporäres Chrome-Profil.
