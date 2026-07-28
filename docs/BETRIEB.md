# Betrieb und Konfiguration

Dieses Runbook beschreibt den regulären Containerbetrieb. Eine Übernahme
vorhandener Daten ist kein Erststart und wird separat unter
[Migration und Upgrade](MIGRATION-UND-UPGRADE.md) behandelt.

## Voraussetzungen

- Docker mit Compose v2 oder Podman mit einem Compose-Provider
- ausreichend persistenter Speicher für Datenbank, Anhänge, Vordrucke und
  Exporte
- `openssl` oder ein gleichwertiger kryptographischer Passwortgenerator
- `curl` für die Bereitschaftsprüfung
- bei Zugriff außerhalb des Hosts: eigener Reverse Proxy mit gültigem
  TLS-Zertifikat

Das Image ist reproduzierbar auf `php:8.5.8-apache-trixie`, die Datenbank auf
`mariadb:11.8.8` festgelegt. Versionsänderungen sind damit bewusste Upgrades
und keine unbeobachteten automatischen Aktualisierungen.

## Erstinstallation

### 1. Konfiguration und Secrets anlegen

```console
cp .env.example .env
install -d -m 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

`.env` und `secrets/` sind von Git und vom Image-Build ausgeschlossen. Die
Secret-Dateien müssen jeweils genau ein starkes Kennwort enthalten und dürfen
nicht in Tickets, Logs oder unverschlüsselte Sicherungen kopiert werden.

Wichtige Werte in `.env`:

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `ESTAB_DB_PASSWORD_SECRET_FILE` | `./secrets/db_password.txt` | Hostdatei mit dem Kennwort des DB-Anwendungsbenutzers |
| `ESTAB_DB_ROOT_PASSWORD_SECRET_FILE` | `./secrets/db_root_password.txt` | Hostdatei mit dem MariaDB-Root-Kennwort |
| `ESTAB_ADMIN_PASSWORD_SECRET_FILE` | `./secrets/admin_password.txt` | Hostdatei mit dem Kennwort für `/4fadm` |
| `ESTAB_DB_NAME` | `estab` | Datenbankname und Unterverzeichnis in `4fdata`; nur Buchstaben, Ziffern und `_` |
| `ESTAB_DB_USER` | `estab` | Anwendungsbenutzer der Datenbank |
| `ESTAB_ADMIN_USER` | `estab-admin` | Benutzer für HTTP Basic Auth unter `/4fadm` |
| `ESTAB_HTTP_BIND` | `127.0.0.1` | veröffentlichte Hostadresse |
| `ESTAB_HTTP_PORT` | `8080` | veröffentlichter Hostport |
| `ESTAB_PUBLIC_URL` | `/` | portable Browser-Basis; nur bei garantiert einer externen Adresse auf eine absolute HTTP(S)-URL setzen |
| `ESTAB_BASE_PATH` | leer | historischer Installationspfad im Document Root; im gelieferten Root-Image leer lassen |
| `ESTAB_ORGANISATION` | `Einsatzleitung` | angezeigte Dienststelle/Organisation |
| `ESTAB_AUTHORITY_CODE` | `EL` | Hoheits-/Organisationskürzel |
| `ESTAB_ALLOW_SELF_REGISTRATION` | `true` | erlaubt neuen Funktionsbenutzern die erste Registrierung |
| `ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF` | `false` | erlaubt ausdrücklich benötigten direkten Legacy-Clients tokenlose Anmeldung; nicht für Browserbetrieb aktivieren |
| `ESTAB_TRUST_PROXY_HEADERS` | `false` | wertet validierte `X-Forwarded-*`-Ketten aus |
| `ESTAB_UPLOAD_MAX_BYTES` | `5242880` | anwendungsseitige maximale Uploadgröße |
| `TZ` | `Europe/Berlin` | Zeitzone von Anwendung und Datenbank |

Die effektive Uploadgrenze ist der kleinste Wert aus
`ESTAB_UPLOAD_MAX_BYTES`, PHPs `upload_max_filesize` von 20 MiB und
`post_max_size` von 24 MiB.

Die Datenbank-Initialisierung wertet Datenbankname, Benutzer und
Datenbank-Secrets nur aus, wenn `/var/lib/mysql` leer ist. Nach dem ersten
Start dürfen diese Werte nicht einfach geändert werden. Eine
Datenbankkennwort-Rotation muss mit `ALTER USER` in MariaDB koordiniert werden;
eine reine Änderung der Secret-Datei trennt sonst die Anwendung von der
Datenbank.

### 2. Konfiguration prüfen und Image bauen

```console
podman compose config >/dev/null
podman compose build --pull migrate app
```

Der App-Build bricht ab, wenn eine der benötigten PHP-Erweiterungen `gd`,
`mbstring`, `mysqli`, `Zend OPcache` oder `zip` fehlt. Das separate
Migrationsimage enthält den MariaDB-Client, den checksum-prüfenden Runner, die
versionierten SQL-Dateien und die vollständige Schema-Verifikation.

### 3. Stack starten

```console
podman compose up -d
podman compose ps
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Beim ersten Start legt der offizielle MariaDB-Entrypoint das Basisschema aus
`docker/db/init/10-schema.sql` an. Das geschieht nur bei einem leeren
Datenbank-Volume. Danach läuft der einmalige Service `migrate`. Er wendet jede
noch nicht protokollierte Datei unter `docker/db/migrations/` in
Dateinamenreihenfolge an, speichert Version, SHA-256, Status und Zeitpunkt in
`estab_schema_migrations` und führt `docker/db/verify.sql` aus.

Der App-Service hängt mit `service_completed_successfully` von diesem Lauf ab.
Bei SQL-Fehler, doppeltem Anhangnamen, geänderter Prüfsumme oder fehlgeschlagener
Schema-Verifikation bleibt die Anwendung aus. Erst danach legt der
App-Entrypoint die beschreibbaren Verzeichnisse für Anhänge, Vordrucke,
Exporte und Sitzungen an und erzeugt aus dem Admin-Secret eine
bcrypt-geschützte `htpasswd`-Datei im flüchtigen Container-Dateisystem.

MariaDB läuft explizit mit
`STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO`
und `NO_ENGINE_SUBSTITUTION`. Damit werden ungültige Legacy-Defaults oder
stilles Abschneiden nicht durch lockere Servervorgaben verdeckt.
Bestandsmigrationen lockern den Modus nur für ihre eigene Sitzung und stellen
ihn anschließend wieder her.

### 4. Schema freigeben

```console
podman compose run --rm migrate
```

Ein bereits aktueller Bestand meldet beide Migrationen als vorhanden und
führt trotzdem den vollständigen Read-only-Schematest aus. Die Ausgabe muss
`Post-migration schema verification passed` und anschließend
`All schema migrations are applied` enthalten. Erst danach sollte der Stack
fachlich freigegeben werden.

## Normaler Lebenszyklus

```console
# Zustand
podman compose ps

# laufende Logs einschließlich letztem Migrationslauf
podman compose logs --follow --tail=100 migrate app db

# Dienste geordnet anhalten; unverändertes, erfolgreiches Gate wiederverwenden
podman compose stop
podman compose up -d

# vollständige Migration-/Schemaprüfung bewusst erneut ausführen
podman compose stop app
podman compose up --force-recreate migrate
podman compose up -d app

# Container nach einer Änderung an .env oder Secrets neu erzeugen
podman compose up -d --force-recreate app

# neue Version nur nach Vollbackup bauen und kontrolliert ausrollen
podman compose build --pull migrate app
podman compose stop app
podman compose up --force-recreate migrate
podman compose up -d app
```

Der Vordergrundlauf von `migrate` muss mit Exitcode 0 enden. Schlägt er fehl,
bleibt `app` gestoppt; zuerst Logs und Datenbestand klären, nicht das
Compose-Gate umgehen.

OPcache prüft im laufenden Produktionscontainer keine Datei-Zeitstempel.
Änderungen am Quellcode werden deshalb ausschließlich durch einen neuen
Image-Build und Container-Recreate ausgerollt, nicht durch Änderungen in einem
laufenden Container.

`podman compose down` entfernt Container und Netzwerke, behält aber die drei
benannten Volumes. `podman compose down --volumes` entfernt auch die
persistenten Daten und ist ein destruktiver Neuaufbau.

## Benutzer und Administration

Die Anwendung hat zwei getrennte Anmeldebereiche:

1. Funktionsbenutzer wählen auf `/` unmittelbar „Mit bestehendem Konto
   anmelden“ oder – sofern freigeschaltet – „Neues Konto anlegen“. Das
   Nachrichtenvordruck-Modul öffnet das passende Formular direkt in seinem
   rechten `iframe` namens `mainframe`. Neue Kennwörter werden mit
   `password_hash()` gespeichert; ein beim Altimport vorhandenes
   Klartextkennwort wird beim ersten erfolgreichen Login transparent ersetzt.
2. `/4fadm` verwendet den in `.env` konfigurierten Admin-Benutzer und das
   separate Admin-Secret als HTTP Basic Auth. Der im historischen Pfad
   verbliebene Grafikreset `/4fach/resetpic.php` liegt hinter demselben Schutz.

Die Selbstregistrierung ist aus Kompatibilitätsgründen standardmäßig aktiv.
„Mit bestehendem Konto anmelden“ erzeugt auch bei einem unbekannten Kürzel
niemals einen neuen Datensatz. „Neues Konto anlegen“ verlangt das Kennwort
zweimal und weist bereits vergebene Kürzel ab. Name, eindeutiges Kürzel mit
höchstens sechs Buchstaben, Ziffern oder `_` sowie die organisatorisch
zugeteilte Funktion sind Pflichtangaben; die Rolle ist nicht frei wählbar.
Die öffentliche Kontenliste übernimmt bei Auswahl nur Name, Kürzel und
Funktion, niemals das Kennwort.
Ein bereits aktives Konto kann nicht durch eine abweichende Funktionsauswahl
die Rolle wechseln. Für einen vorgesehenen Funktionswechsel muss das Konto
zuerst ordnungsgemäß abgemeldet und anschließend mit der neuen zugeteilten
Funktion angemeldet werden.

Die gemeinsame Navigation führt in derselben Reihenfolge durch Übersicht,
Nachrichtenvordruck, Meldungsübersicht, Vordrucke, Einsatztagebuch,
Technisches Betriebsbuch, Nachweisung und BOS-Info. Der aktuelle Bereich ist
hervorgehoben; alle internen Ziele ersetzen die aktuelle Ansicht und erzeugen
keine zusätzlichen Tabs. Der Nachrichtenvordruck verwendet genau zwei moderne
`iframe`-Elemente: die vollhohe linke `vorgaben`-Sidebar und den rechten
`mainframe`. In der Sidebar folgen auf die Statuskarte die Sitzungsidentität
mit Logout, alle dauerhaft sichtbaren Bereichslinks und die zur angemeldeten
Rolle passenden Textbuttons für Fachaktionen. Die frühere aufklappbare Auswahl
„Bereich wechseln“ und ihre kleine eigene Scrollfläche entfallen. Bei geringer
Höhe scrollt ausschließlich das gesamte Sidebar-Dokument, sodass Status,
Navigation und Aktionen in einer durchgehenden Reihenfolge erreichbar bleiben.
Der BOS-Bereich verwendet unabhängig davon weiterhin seinen kompakten
Disclosure-Modus im eigenen Navigationsframe. Bis einschließlich 672
CSS-Pixel Breite werden Sidebar und Fachinhalt als zwei jeweils viewporthohe
Zeilen angeordnet. Das Ausführen einer rollenabhängigen Fachaktion wechselt
automatisch zur Inhaltszeile und setzt den Tastaturfokus auf den Inhaltsframe.
Der dort sichtbare, mindestens 44 Pixel große Button „Menü“ führt samt Fokus
zurück zur Sidebar.

Vor der Anmeldung zeigt die Leiste „Nicht angemeldet“ und den Anmeldebutton.
Geschützte Bereiche und Karten bleiben zur Orientierung sichtbar, tragen die
Kennzeichnung „Anmeldung erforderlich“ und führen zum Anmeldeeinstieg statt
auf eine 403-Fehlerseite. Nach einer erfolgreichen Anmeldung wird der zuvor
gewählte geschützte Bereich direkt geöffnet. Die Auswahl bleibt pro
Browser-Tab erhalten; parallele Anmeldefenster überschreiben ihr Ziel nicht.
Übersicht und BOS-Info bleiben öffentlich; die Administration ist als
separater technischer Zugang markiert.

Nach erfolgreicher Anmeldung erscheint auf der Übersicht, im
Nachrichtenarbeitsbereich, auf den Administrationsseiten und auf allen
ausgewählten eigenständigen HTML-Modulen die Sitzungsleiste. Sie nennt Name,
Kürzel, Funktion und Rolle, damit vor jeder fachlichen Aktion sichtbar ist, in
welchem Kontext gearbeitet wird. Der Button „Abmelden“ sendet einen
CSRF-geschützten POST, beendet die gesamte eStab-Browsersitzung und führt mit
HTTP 303 zum Anmeldeeinstieg zurück. Mehrere Tabs teilen sich dieselbe
Browsersitzung und sind danach gemeinsam abgemeldet. Direkt geöffnete
Status-/Zählerseiten zeigen die kompakte Leiste selbst; im
Nachrichtenarbeitsbereich befindet sich die einzige sichtbare Identität in der
Sidebar. Hilfe- und Problem-Popups zeigen die Leiste im jeweiligen Fenster.

Die Statuskarte am Anfang der Sidebar vereint den rollenabhängigen
Arbeitszähler, Datum und Serverzeit sowie die Onlinebelegung aller
konfigurierten Funktionen. Die Anwendung lädt regelmäßig ausschließlich das
authentifizierte Statusfragment
`/4fach/vorgaben.php?fragment=status` nach und ersetzt nur diese Karte. Das
Sidebar-Dokument, die Bereichslinks und die Aktionsbuttons werden dabei nicht
neu geladen; Tastaturfokus und Scrollposition bleiben auch dann erhalten, wenn
der Hinweiston-Schalter fokussiert ist. Ein Zähler größer null bleibt bis zur
Abarbeitung dauerhaft kontrastreich markiert. Schlägt die Datenbankabfrage
fehl, zeigt der Zähler „–“; Identität, Navigation und Aktionen bleiben
verfügbar, der letzte erfolgreiche `old_que_*`-Basiswert bleibt unverändert und
die Karte meldet „Statusdaten unvollständig“ beziehungsweise „Statusdaten nicht
verfügbar“. Ein HTTP-, Parse- oder Netzwerkfehler kennzeichnet die bestehende
Karte sichtbar als „Status nicht aktuell“ mit Uhrzeit des letzten erfolgreichen
Abrufs. Jeder Abruf wird spätestens nach 4,5 bis 15 Sekunden abgebrochen, damit
ein hängender Request spätere Aktualisierungen nicht dauerhaft blockiert. Der
nächste vollständige Abruf entfernt die Warnung und meldet die Erholung.

Ist `conf_4f["sounds"]` aktiviert, bietet die Statuskarte für Fernmelder, Si
und Stab/FB den Schalter „Hinweistöne aktivieren“ an. Die Zustimmung ist pro
Browser ausdrücklich erforderlich und wird lokal im Browser gespeichert; ein
frischer Browser startet mit ausgeschaltetem Ton. Ausschalten wird sofort mit
anderen offenen Tabs derselben Origin synchronisiert. Nach einem Reload wird
eine gespeicherte Einschaltabsicht so lange als „erneut freigeben“ angezeigt,
bis in diesem Tab wirklich eine Wiedergabe gelungen ist. Die Anwendung
verwendet die mitgelieferten gleich-originigen PCM-WAV-Dateien
`4fach/audio/notify_aw.wav`, `notify_si.wav` und `notify_stab.wav`. Das
langlebige Audioelement liegt außerhalb des regelmäßig ersetzten
Statusfragments und bleibt deshalb über Aktualisierungen hinweg erhalten.
Browserblockaden, ein nicht unterstütztes Format sowie der Ein-/Aus-Zustand
werden direkt unter dem Schalter sichtbar gemeldet. Eine Zunahme lässt die
Statuskarte zusätzlich aufleuchten; solange Meldungen offen sind, bleibt
bereits der Zähler selbst hervorgehoben. Ausschalten oder eine Änderung aus
einem anderen Tab verwirft auch einen noch laufenden Wiedergabeversuch; dessen
spätes Ergebnis kann den Ton nicht unbemerkt wieder aktivieren.

Die Warteschlangenerkennung führt pro Sitzung genau einen Basiswert
`old_que_aw`, `old_que_si` beziehungsweise `old_que_stab`. Die erste
erfolgreiche Messung initialisiert ihn ohne Hinweis; jede weitere erfolgreiche
Messung aktualisiert ihn, aber nur eine Erhöhung fordert genau einmal die
rollenabhängige Wiedergabe an. Ein unveränderter oder kleinerer Wert löst
nichts aus. Ist die Warteschlange vorübergehend nicht messbar, bleibt der
letzte erfolgreiche Basiswert erhalten. Diese Auslöse- und Browsermechanik ist
automatisiert geprüft. Für die Betriebsabnahme muss der Ton nach ausdrücklicher
Aktivierung auf jedem vorgesehenen Browser und Endgerät zusätzlich tatsächlich
angehört werden; die Automation kann physische Hörbarkeit nicht beweisen.

Wurde in einem dafür markierten Formular ein Wert geändert oder eine Datei
ausgewählt, fragt die Oberfläche vor einem globalen Bereichswechsel oder
Logout nach. Bei Nachrichtenformular, Empfängermatrix und Zählerreparatur gilt
das auch für serverseitig wegen eines Fehlers erneut angezeigte, noch
ungespeicherte Eingaben. Die Matrix behält validierte Eingaben selbst dann
sichtbar, wenn die Datenbanktransaktion fehlschlägt. Die von eStab geöffneten
Hilfe- und Problemfenster prüfen die Formulare ihres zugehörigen
Hauptfensters; bestätigte globale Navigation und Logout werden dort
ausgeführt. „Abbrechen“, „Speichern“ und andere lokale Fachaktionen bleiben
unverändert. Die Warnung verhindert keinen Verlust durch Browserabsturz oder
das Schließen eines Tabs; wichtige Eingaben weiterhin zeitnah speichern.

Diese Schaltfläche beendet ausschließlich die eStab-Funktionssitzung. Die
Administration nutzt HTTP Basic Auth; dessen Zugangsdaten verwaltet der
Browser separat und sie besitzen deshalb keine verlässliche
Anwendungs-Abmeldeschaltfläche. Für einen vollständigen Admin-Benutzerwechsel
ist je nach Browser ein privates Fenster oder das Schließen aller betreffenden
Browserfenster erforderlich. Die Leiste nennt auf Administrationsseiten den
technischen Basic-Auth-Benutzer und unterscheidet ihn ausdrücklich vom
eStab-Funktionskonto. Der eStab-Button „Abmelden“ beendet nur die
Funktionssitzung.

Browserformulare für Bestandsanmeldung und Kontoanlage tragen ein
sitzungsgebundenes CSRF-Token. Direkte historische Clients, die dieses Token
nicht beziehen können, funktionieren nur nach der bewussten Ausnahme
`ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=true`. Diese Ausnahme bleibt für
erkannte Cross-Site-Browserrequests gesperrt, ist aber schwächer als der
normale Tokenfluss und darf nur in einem kontrollierten Netz sowie für die
Dauer der Migration solcher Clients aktiviert werden.

Wo Benutzer vorab kontrolliert eingerichtet werden können, sollte anschließend
`ESTAB_ALLOW_SELF_REGISTRATION=false` gesetzt und der App-Container neu
erzeugt werden. Ein versehentliches Abschalten vor Anlage des ersten
Funktionsbenutzers verhindert dessen Registrierung.

Das Admin-Kennwort kann ohne Datenbankänderung rotiert werden:

1. neue starke Zeichenfolge atomar in die konfigurierte
   `admin_password.txt` schreiben,
2. Dateirecht `0600` sicherstellen,
3. `podman compose up -d --force-recreate app` ausführen,
4. den alten und den neuen Zugang über TLS prüfen.

### Empfängermatrix, Zählerreparatur und Grafikreset

Die drei aktiven Maßnahmen sind über die Administrationsübersicht erreichbar
und schreiben nur nach POST plus Session-CSRF:

- **Empfängermatrix:** Vorher Datenbanksicherung erstellen und die örtliche
  Rollen-/Rotkopieplanung festhalten. Das Speichern ersetzt alle 20
  Matrixpositionen atomar in MariaDB; im read-only Image wird keine
  Konfigurationsdatei geschrieben. Bereits bestehende Benutzerkonten und
  laufende Sitzungen werden bewusst nicht automatisch umbenannt oder einer
  anderen Funktion zugeteilt. Nach dem Speichern müssen abgemeldete
  Neuanmeldung, Sichtung, Rotkopie und Autosichtung mit den betroffenen
  Funktionen fachlich geprüft werden.
- **Nachrichtenzähler:** Ausschließlich nach dokumentiertem Systemausfall die
  letzte tatsächlich auf Papier verwendete Nummer eintragen. Der Zielwert muss
  strikt größer als der angezeigte Höchstwert sein. Gemeinsame und getrennte
  Nachweisung werden getrennt behandelt; ein Absenken oder Teilupdate ist
  ausgeschlossen. Die Maßnahme erzeugt Systemnachricht(en) und einen
  `nv_protokoll`-Eintrag.
- **Grafikreset:** Die GET-Seite zeigt nur die Auswirkung. Erst die bestätigte
  POST-Anforderung setzt `x04_druck` zurück. Danach erzeugt der historische
  Abschlusslauf Nachrichtengrafiken/PDFs erneut; Fortschritt wird weiterhin
  nicht separat angezeigt.

Jede Maßnahme schreibt einen Audit-Eintrag. Datenbank- oder
Validierungsfehler dürfen deshalb nicht durch wiederholtes Browser-Refresh
„korrigiert“ werden; Ursache in App-/DB-Log klären und den aktuellen Zustand
neu laden.

### Kategorienverwaltung

Die Ordnersymbole im Nachrichtenvordruck öffnen den aktiven Endpunkt
`/4fach/katgoedt.php`. Jede Sitzung verwaltet nur ihre aus Funktion und Kürzel
abgeleiteten Funktions-/Benutzertabellen. Globale Kategorien sind der aktuell
in der Empfängermatrix markierten Rotkopie und `Si` vorbehalten. Sämtliche
Änderungen und Nachrichtenzuordnungen erfolgen über POST mit Session-CSRF;
Browser-Refresh wiederholt deshalb keine Änderung. Ein Rotkopiewechsel wirkt
bei der nächsten Anfrage; nach einer Änderung von Funktion oder Rolle müssen
sich die betroffenen Benutzer neu anmelden. Die Kategorienrechte sind danach
im fachlichen Abnahmelauf erneut zu prüfen.

Kategorie und Beschreibung dürfen Quotes, Ampersands und internationalen Text
enthalten und werden als UTF-8 gespeichert. Sie dürfen nicht vorab als
HTML-Entities eingegeben oder importiert werden; die Anwendung escaped erst
bei der Ausgabe. Eine gelöschte Kategorie verliert ihre Zuordnungen atomar.

## Reverse Proxy und TLS

Der Stack stellt selbst kein TLS-Zertifikat bereit. Bei einem Host-Reverse-Proxy
bleibt `ESTAB_HTTP_BIND=127.0.0.1`; nur der Proxy lauscht auf externen
Adressen. Für eine Veröffentlichung unter `https://estab.example.org/` gelten:

```dotenv
ESTAB_HTTP_BIND=127.0.0.1
ESTAB_PUBLIC_URL=https://estab.example.org/
ESTAB_BASE_PATH=
ESTAB_TRUST_PROXY_HEADERS=true
```

Minimales Nginx-Prinzip:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

Zertifikate, sichere TLS-Protokolle, Zugriffsbeschränkung, Rate Limiting und
Logrotation liegen in der Verantwortung des Reverse Proxys. HTTP Basic Auth
darf außerhalb eines isolierten Testhosts niemals ohne TLS verwendet werden.

Für einen Unterpfad, beispielsweise `https://example.org/estab/`, muss der
Proxy `/estab/` vor der Weiterleitung entfernen und die URL so gesetzt werden:

```dotenv
ESTAB_PUBLIC_URL=https://example.org/estab/
ESTAB_BASE_PATH=
```

`ESTAB_BASE_PATH` bleibt beim gelieferten Image leer, weil die Anwendung im
Container direkt unter `/var/www/html` liegt. Die Variable ist nur für ein
bewusst angepasstes Image gedacht, das Code und Daten tatsächlich in einem
Unterverzeichnis des Document Roots installiert.

`ESTAB_TRUST_PROXY_HEADERS=true` ist nur sicher, wenn der App-Port
ausschließlich vom kontrollierten Proxy erreichbar ist. Die Anwendung
akzeptiert dann eine vollständig syntaktisch gültige
`X-Forwarded-For`-Kette und verwendet deren erste Adresse für Auditzwecke;
eine eigene Proxy-Allowlist führt sie nicht. `X-Forwarded-Proto: https`
aktiviert außerdem das `Secure`-Attribut des Session-Cookies.

## Speicher und Kapazität

```console
podman compose exec -T app \
  du -sh /var/www/html/4fdata /var/lib/estab/export
podman compose exec -T db du -sh /var/lib/mysql
```

Anhänge und Vordrucke liegen pro Datenbank unter
`/var/www/html/4fdata/$ESTAB_DB_NAME/`. Exporte wachsen unabhängig davon im
Export-Volume und werden nicht automatisch gelöscht. Für beide Bereiche sind
Aufbewahrungsfrist und Kapazitätsalarm festzulegen.

Eine Sicherung und ein regelmäßig geprobter Restore sind in
[Backup und Wiederherstellung](BACKUP-UND-WIEDERHERSTELLUNG.md) beschrieben.
