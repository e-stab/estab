# Tests, Funktionsnachweis und Monitoring

Die Funktionssicherung besteht aus mehreren Ebenen. Keine einzelne
Health-Antwort beweist die vollständige Fachfunktion.

Die Zuordnung jeder Funktion zu ihrem automatisierten und fachlichen Gate
steht in der [Funktionsmatrix](FUNKTIONSNACHWEIS.md).

| Ebene | Nachweis |
| --- | --- |
| Quellprüfung | PHP-8.5-Lint, Kompatibilitäts-, Sicherheits-, Upload-, Export- und PDF-Regressionen |
| Image-Build | benötigte PHP-Erweiterungen und Apache-Konfiguration |
| Datenbank | echtes MariaDB-Schema, Indizes, Matrix, Engines, Collations und Zero-Date-Freiheit |
| HTTP | Header, direkte Endpunktfläche, 403-/400-Grenzen, PNG-Antworten, Registrierung, erneute Anmeldung, Nachrichten-/Kategorien- und ETB-/TBB-Rollengrenzen sowie optional Admin-Export |
| Fachabnahme | kompletter Nachrichten-, Anhang-, PDF-, ETB-/TBB- und Restore-Ablauf |
| Betrieb | kontinuierliche Readiness, Logs, Restarts, Kapazität und Backup-Alter |

## Statische Tests

Mit lokalem PHP 8.5:

```console
tests/static/run.sh
```

Oder reproduzierbar mit dem festgelegten CLI-Image:

```console
podman run --rm \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  php:8.5.8-cli-trixie \
  tests/static/run.sh
```

Die Suite lintet alle aktiven PHP-Dateien und führt die Prüfungen unter
`tests/php/` aus. Dazu gehören unter anderem:

- PHP-8.5-Laufzeit- und Legacy-Konstruktor-Kompatibilität,
- `NULL`-/Zero-Date-Behandlung,
- Anmelde-, Konto-Flow-, aktive Funktionsbindungs-, Session-, Proxy- und
  Passwortregeln,
- Nachrichten-IDs, Rollen-/Objektregeln, Empfänger-Tokens, erlaubte
  Workflow-Aktionen, POST-/CSRF-Verträge, Prepared Statements, sichere
  UTF-8-/Legacy-Entity-Ausgabe und die inerten Payloads Quotes, Ampersand,
  `<script>` sowie SQL-ähnlicher Text,
- erlaubte Bildbutton-Typen, Farb-/Größen-/Textgrenzen, öffentliche
  Endpunktverträge, vollständige Apache-Sperrlisten und eine CSP ohne
  `unsafe-eval`,
- CSRF-Token,
- Empfängermatrix-, Nachrichtenzähler- und Grafikreset-Validierung samt
  Prepared-Statement-, Transaktions-, Auth-/CSRF- und PRG-Vertrag,
- Kategorien-Typen, positive IDs, sessionabgeleitete Tabellenräume,
  Master-Rechte, doppelte Auswahllisten, HTML-Ausgabe sowie den
  Prepared-Statement-, Transaktions-, Objektberechtigungs- und PRG-Vertrag,
- Upload- und Anhangpfadvalidierung,
- authentifizierte Dateiauslieferung samt Traversal-, Symlink- und
  Header-Injection-Schutz,
- portabler Tabellenexport,
- Compose-Startgate, private MariaDB-Optionsdatei, Migration-Ledger,
  Prüfsummenbindung und Runtime-Schemavertrag,
- Erzeugung eines lesbaren PDF-Dokuments.

Ein Prozess-Exitcode ungleich null sperrt die Freigabe.

## Vollständiges lokales CI-Gate

Der gleiche Orchestrator wie in GitHub Actions lässt sich mit Docker oder
Podman ausführen. Er akzeptiert für seine absichtliche Volume-Löschung nur
Compose-Projektnamen `estab_ci` oder `estab_ci_*` und erzeugt alle Secrets in
einem privaten temporären Verzeichnis:

```console
COMPOSE_PROJECT_NAME=estab_ci_local_01 \
ESTAB_CONTAINER_CLI=podman \
ESTAB_HTTP_PORT=18080 \
tests/integration/ci.sh
```

Bei Podman liegen die kurzlebigen Verzeichnisse unterhalb des ausgecheckten
Repositorys, damit Podman Desktop den Hostpfad sicher in seine VM einhängen
kann. `.gitignore` und `.dockerignore` schließen sie aus; der Exit-Trap entfernt
sie auch nach einem Fehler. Docker verwendet standardmäßig `RUNNER_TEMP` oder
`/tmp`. Ein anderer bereits vorhandener, schreibbarer Pfad kann für beide
Engines explizit mit `ESTAB_CI_TEMP_PARENT` gesetzt werden.

Der Lauf baut die festgelegten Images frisch, migriert ein leeres Schema,
führt PHP-, Datenbank-, Rollen-, HTTP- und Administrationsnachweise aus, prüft
die Containerlogs und stellt Datenbank, Anhänge und Export aus einem
prüfsummengebundenen Backup in neue Volumes wieder her. Am Ende werden nur die
guardierten CI-Container, CI-Volumes und temporären Secrets entfernt. Mit
Docker wird `ESTAB_CONTAINER_CLI=docker` gesetzt oder die Variable weggelassen.
Die HTTP-Stufe beweist dabei den Startseiten-Anmeldebutton, die getrennten
Bestandskonto-/Neukonto-Formulare, die sichtbare Kontenauswahl,
Kennwortbestätigung, unveränderte Kontenzahlen und Passwort-Hashes bei
Fehlversuchen, aktive Funktionsbindung sowie deaktivierte Selbstregistrierung.

## Wegwerfbarer Integrations-Stack

Integrations- und HTTP-Tests verändern Daten. Sie gehören nicht auf die
Produktionsinstanz, sondern in ein eigenes Compose-Projekt mit eigenen
Volumes:

```console
ESTAB_HTTP_PORT=18080 \
ESTAB_ALLOW_SELF_REGISTRATION=true \
podman compose -p estab-acceptance up -d --build

ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance ps

curl --fail --silent --show-error \
  http://127.0.0.1:18080/health.php
```

Das Schema dieses Testprojekts wird so geprüft:

```console
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm migrate
```

Die Ausgabe muss `Post-migration schema verification passed` enthalten. Der
Runner prüft alle Ergebnisfelder und übergibt das Root-Secret nicht in der
Prozessargumentliste.

### One-shot-Migrator und bestehendes Volume

Der gezielte Test erzeugt in derselben MariaDB eine isolierte
Legacy-Testdatenbank. Zuerst muss ein doppelter Anhangname die
Runtime-Migration blockieren. Danach prüft der Test Bereinigung/Retry,
idempotenten Zweitlauf, Zero-Date-Konvertierung, Benutzer-/IP-/Kürzelbreiten,
Indizes, Datenwerterhalt und einen absichtlich manipulierten
Migration-Checksum:

```console
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --entrypoint /workspace/tests/integration/schema_migrator.sh \
  migrate
```

Die Fixture-Datenbank trägt einen pro Prozess abgegrenzten Namen und wird per
Trap wieder entfernt. Der Test setzt die Produktions-Schema-Verifikation nur
für diese unvollständige Vier-Tabellen-Fixture aus; Migration, Ledger und
Prüfsummenlogik laufen unverändert aus dem gebauten Migrationsimage.

### Datenbank-Integration der Datumsmigration

Der Test verwendet nur temporäre Tabellen, wendet die echte
Migrationsdatei zweimal an und prüft SQL-Mode, gültige Altwerte, Zero-Date-
Konvertierung und Warteschlangenprädikate:

```console
ESTAB_TEST_DB_PASSWORD="$(tr -d '\r\n' < secrets/db_password.txt)" \
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  --env ESTAB_TEST_DB_PASSWORD \
  --env ESTAB_TEST_DB_HOST=db \
  --entrypoint php \
  app tests/integration/date_compatibility.php
```

### Datenbank-Integration dynamischer Tabellen

Diese Regression erzeugt sechs typische Legacy-Tabellen für Benutzer,
Funktion und Kategorien als MyISAM/latin1, erhält repräsentative Daten und
Duplikate und ruft die echte Bestandsmigration zweimal auf. Anschließend prüft
sie InnoDB, `utf8mb4`, nullable Datumswerte, Indizes, Strict Mode,
Identifier-Angriffe und die vollständige Fixture-Bereinigung:

```console
ESTAB_TEST_DB_PASSWORD="$(tr -d '\r\n' < secrets/db_password.txt)" \
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  --env ESTAB_TEST_DB_PASSWORD \
  --env ESTAB_TEST_DB_HOST=db \
  --env ESTAB_TEST_DB_NAME=estab \
  --env ESTAB_TEST_DB_USER=estab \
  --entrypoint php \
  app tests/integration/dynamic_tables.php
```

Wurden `ESTAB_DB_NAME` oder `ESTAB_DB_USER` in `.env` geändert, müssen die
beiden `ESTAB_TEST_DB_*`-Werte im Befehl identisch angepasst werden. Der Test
verwendet fest abgegrenzte Tabellennamen und entfernt sie am Anfang, bei
Prozessende und nach erfolgreichem Lauf; er gehört trotzdem ausschließlich in
den Wegwerf-Stack.

### Datenbank-Integration der Nachrichten-Parallelität

`tests/integration/message_concurrency.php` erzeugt zufällig benannte
InnoDB-Wegwerftabellen und startet unabhängige PHP-Prozesse mit eigenen
MariaDB-Verbindungen:

```console
ESTAB_TEST_DB_PASSWORD="$(tr -d '\r\n' < secrets/db_password.txt)" \
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  --env ESTAB_TEST_DB_PASSWORD \
  --env ESTAB_TEST_DB_HOST=db \
  --env ESTAB_TEST_DB_NAME=estab \
  --env ESTAB_TEST_DB_USER=estab \
  --entrypoint php \
  app tests/integration/message_concurrency.php
```

Der Test beweist für die implementierte Repository-Grenze:

- zwei gleichzeitige Writer auf einer leeren Tabelle erhalten verschiedene,
  fortlaufende Nachweisnummern,
- der administrative Zähler-Lock blockiert einen regulären Writer und beide
  verwenden damit denselben Namespace,
- ein fremdes A/W-Kürzel kann weder Sperre noch Save übernehmen und beim
  parallelen Save-/Reset-Rennen gewinnt genau eine konditionale Änderung,
- zwei parallele Read-State-Inserts erzeugen trotz fehlendem Schema-Unique-Key
  nur eine Zeile; Empfänger-Substring und fremde Funktion werden abgewiesen,
- idempotente Updates werden vom verlorenen beziehungsweise gelöschten Ziel
  unterschieden.

Der Nachweis endet bewusst an dieser Repository-Grenze. Er beweist keine
gemeinsame Transaktion zwischen jeder Nachrichtenmutation, dem separaten
Legacy-`protokolleintrag()` und einem nachgelagerten Read-/Done-State. Bei der
Abnahme sind deshalb sowohl fehlgeschlagene Anwendungsanforderungen als auch
korrespondierende `nv_protokoll`-Einträge zu kontrollieren.

Auch die statische Legacy-Entity-Prüfung kann ohne Encoding-Marker pro
Nachrichtenzeile nicht unterscheiden, ob beispielsweise `&amp;` ein alter
codierter Wert oder ein neu wörtlich eingegebenes Literal ist. Sie belegt die
sichere HTML-Ausgabe beider Speicherformen, nicht den verlustfreien
Roundtrip entity-förmiger Literale.

Die Fixture-Tabellen werden beim Prozessende entfernt. Das vollständige
CI-Gate führt diesen Test nach der parallelen Anhangreservierung und vor den
HTTP-Abläufen aus.

### HTTP-Smoke-Test

Der Test legt den deterministischen Funktionsbenutzer `e2e001` an, startet
eine zweite Sitzung zur Prüfung des gespeicherten Passwort-Hashes und kann
zusätzlich einen echten Admin-Export erzeugen:

```console
COMPOSE_PROJECT_NAME=estab-acceptance \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_ADMIN_USER=estab-admin \
ESTAB_TEST_ADMIN_PASSWORD="$(tr -d '\r\n' < secrets/admin_password.txt)" \
tests/integration/http_smoke.sh
```

`COMPOSE_PROJECT_NAME` muss auf den gestarteten, ausschließlich für die
Abnahme vorgesehenen Teststack zeigen. `ESTAB_TEST_COMPOSE_ENGINE` ist
verpflichtend; ohne die direkte MariaDB-Verbindung bricht der Test ab, damit
Kontenzahl, Passwort-Hash und Rollenbindung niemals nur vermeintlich geprüft
werden.

Falls `ESTAB_ADMIN_USER` in `.env` geändert wurde, muss
`ESTAB_TEST_ADMIN_USER` denselben Wert erhalten. Der Test prüft unter anderem:

- Readiness und Security Header,
- Startseite, BOS-Infos und historisches PDF-Handbuch,
- HTTP 403 für interne beziehungsweise abgeschaltete Provisionierungspfade,
- HTTP 410 für unsichere historische Direkt-Upload-Endpunkte,
- HTTP 401 für den anonymen Administrationszugriff,
- Image-Button-Login, Registrierung, authentifizierte Oberfläche und erneute
  Anmeldung über Kontenliste und gespeicherten Passwort-Hash,
- sitzungsgebundene Voranmelde-CSRF-Tokens, HTTP 403 ohne Token im Browserflow
  und HTTP 403 für erkannte Cross-Site-Requests im explizit aktivierten
  Legacy-Kompatibilitätsmodus,
- direkte MariaDB-Nachweise, dass Fehlversuche kein Konto erzeugen und eine
  Neuanlage-Kollision den vorhandenen Passwort-Hash nicht verändert,
- unveränderte DB-Zuordnung beim blockierten Funktions-/Rollenwechsel für
  aktive Konten, erlaubte Neuzuordnung erst nach erfolgreichem Logout und
  blockierte Zweitanmeldung aus einer bereits authentifizierten Sitzung,
- Speichern und Suchen einer Nachricht mit Quotes, Ampersand, `<script>` und
  SQL-ähnlichem Text bei nachweislich inertem HTML sowie HTTP 403 für den
  historischen GET-Detailaufruf,
- Adminseite, CSRF-Token und vollständigen Einsatzexport.

### Direkte HTTP-Oberfläche

Der separate Oberflächentest verändert weder Datenbank noch Sitzung und kann
gegen jeden dafür vorgesehenen Test-Stack laufen:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
tests/integration/http_surface_http.sh
```

Er weist nach:

- vollständige Erreichbarkeit des sichtbaren Root-Menüs, seiner lokalen
  Piktogramme, des Nachrichtenvordruck-Framesets sowie aller verlinkten
  `stabinfo`-Frames, Inhaltsseiten und lokalen Bilder,
- HTTP 403 für dateisysteminterne Vierfach-Controller, den vollständigen
  `/4fbak/`-Baum, Spracharrays, alte Admin-Generatoren,
  `js_windowtwar.php` und direkte `*.inc.php`-Aufrufe,
- weiterhin HTTP 410 für die beiden absichtlichen Upload-Tombstones,
- HTML-Escaping sowie Array-/Parameterablehnung in `info.php`,
- HTTP 200, `image/png` und die acht Byte lange PNG-Signatur für
  repräsentative aktive Icon-, Push-, Menü- und Kategoriebuttons,
- HTTP 400 für entfernte Buttonarten, Arrays, unbekannte Farben sowie
  übergroße Schrift-, Breiten- und Textwerte,
- ausschließlich bekannte Hilfetextschlüssel und einen anonym neutralen
  Statusframe ohne Rollenbelegung.

Der Test und der statische Vertrag stellen zusätzlich sicher, dass unter
`stabinfo/` keine `http://`-Fremdressource verblieben ist und alle zwölf
ersetzten Vergleichszeichen weiterhin semantisch ausgegeben werden.

`tests/php/http_surface_security.php` prüft dieselben Validator- und
Apache-Verträge ohne Webserver. Das vollständige CI-Gate führt zuerst diesen
read-only HTTP-Test und danach die zustandsverändernden Fachabläufe aus.

### ETB-/TBB-HTTP-Integration

Der getrennte, idempotente Logbuchtest registriert je eine S2- und
A/W-Funktionssitzung und verwendet bei einem Wiederholungslauf dieselben
Testkennungen:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
tests/integration/logbooks_http.sh
```

Er weist nach, dass anonyme Zugriffe HTTP 403 erhalten, während jeder gültig
angemeldete Benutzer ETB und TBB lesen kann. Die jeweils fachfremde Sitzung
erhält HTTP 200 samt gespeichertem Inhalt, aber weder Titel- noch
Eintragsformular. Cross-Rollen-POSTs liefern HTTP 403. Nur S2/Red-Copy darf
ETB-Daten und nur A/W mit der Rolle Fernmelder TBB-Daten über POST und
Session-CSRF-Token schreiben. Zusätzlich prüft der Test serverseitige
Längengrenzen, inerte historische GET-Schreibparameter und HTML-Escaping.

### Kategorien-HTTP-Integration

Der Kategorien-Test baut auf der S1-Nachricht des HTTP-Smoke-Tests und dem
S2-Benutzer des Logbuchtests auf. Zusätzlich registriert er eine isolierte
`Si`-Sitzung. Weil er echte Kategorien, Links und eine absichtlich fremde
Nachrichten-Fixture erzeugt, startet er nur mit Sicherheitsvariable und einem
Compose-Projektnamen `estab_ci` beziehungsweise `estab_ci_*`:

```console
COMPOSE_PROJECT_NAME=estab_ci_category \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_WORKFLOW_MARKER=MARKER_AUS_HTTP_SMOKE \
ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION=true \
tests/integration/categories_http.sh
```

Nachgewiesen werden HTTP 403 ohne Sitzung, positive IDs, inerte
GET-Schreibparameter, Session-CSRF und HTTP-303-PRG. S1 darf Funktions- und
Benutzerkategorien seines eigenen Tabellenraums verwalten, aber weder
Master-Kategorien ändern noch eine Master-Zuordnung einschleusen. S2/Rotkopie
und `Si` erreichen die Masterverwaltung. CRUD, Zuordnung und Linkbereinigung
werden direkt in MariaDB geprüft. Eine existierende Nachricht ohne exakten
S1-Empfänger-Token liefert beim Zuordnungsversuch HTTP 403 und bleibt
unverändert. Kategorien mit SQL-ähnlichen Quotes sowie Beschreibungen mit
Quotes, `&` und `<script>` werden bytegenau roh gespeichert, im HTML jedoch
escaped und ohne ausführbares Script ausgegeben. Der Listenfilter wird mit der
positiven Kategorie-ID erfolgreich ausgeführt und beim Löschen dieser Kategorie
aus der Sitzung entfernt; der SQL-artige Name als Filterparameter wird mit
HTTP 403 abgewiesen.

Ein Trap entfernt sämtliche Testkategorien, Links, die fremde Nachricht und
das isolierte Si-Konto samt persönlicher Tabellen. Die CI führt diesen Test
nach HTTP-Smoke und ETB/TBB, aber vor Admin-Workflow und Backup-/Restore aus.

### Admin-Workflow-HTTP-Integration

Der Test verändert Empfängermatrix, Nachrichtenzähler, Grafikflags und Audit.
Er verweigert deshalb ohne die ausdrückliche Sicherheitsvariable den Start und
darf ausschließlich gegen das eigene Wegwerfprojekt laufen:

```console
COMPOSE_PROJECT_NAME=estab-acceptance \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_ADMIN_USER=estab-admin \
ESTAB_TEST_ADMIN_PASSWORD_FILE=secrets/admin_password.txt \
ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION=true \
tests/integration/admin_workflows_http.sh
```

Der Test verwendet das bereits im Datenbankcontainer gemountete Root-Secret
über eine private temporäre Clientdatei. Er prüft:

- HTTP 401 ohne Admin-Basic-Auth und HTTP 403 für `all_msg.php`, die
  gefährlichen Uploadhelfer sowie die direkt aufrufbaren Print-/FPDF-Bäume,
- inerte historische GET-Schreibparameter und HTTP 403 ohne Session-CSRF,
- vollständigen 5x4-Matrix-Roundtrip mit exakt einer Rotkopie und unveränderten
  Benutzerkonten,
- zwei parallele, getrennte Admin-Sitzungen: genau eine darf denselben
  Nachrichtenzähler erhöhen; die andere erhält HTTP 409,
- Systemnachricht, Audit und POST-only-Grafikreset.

Ein Trap stellt die ursprüngliche Matrix, alle vorherigen Grafikflags und die
Auto-Inkremente wieder her und entfernt synthetische Nachrichten/Audits. Das
ist eine zusätzliche Schutzschicht, keine Freigabe für den Lauf gegen
Produktionsdaten. In der CI läuft dieser Test nach HTTP-Smoke, ETB/TBB und
Kategorien-Test, aber vor dem eigenständigen Backup-/Restore-Roundtrip.

Nach abgeschlossener Abnahme wird ausschließlich das explizite Testprojekt
mitsamt seinen Testvolumes entfernt:

```console
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance down --volumes
```

Vor diesem destruktiven Befehl muss der Projektname `estab-acceptance` sichtbar
im Kommando stehen.

## Fachliche Abnahme

Die technischen Tests werden um einen browserbasierten Ablauf ergänzt. Als
fachliche Referenz dient das
[historische Anwendungshandbuch](../doku/Handbuch_eStab.pdf); seine alten
Installations- und Sicherheitskapitel gelten nicht.

Mindestens zu prüfen:

- neuen Benutzer für jede tatsächlich verwendete Funktion registrieren,
  abmelden und mit demselben Kennwort erneut anmelden,
- Berechtigungs-/Rollenzuordnung aus der Empfängermatrix kontrollieren,
- eingehende und ausgehende Nachricht mit Richtung, Gegenstelle,
  Prioritätsstufe, Empfängern und Inhalt erfassen,
- Weiterleitung, Sichtung, Quittierung, Statuswechsel und Listenfilter über
  zwei unterschiedliche Funktionssitzungen nachvollziehen,
- globale, funktionsbezogene und persönliche Kategorie anlegen, zuweisen,
  suchen und entfernen,
- zulässigen Anhang hochladen, Vorschau/Download prüfen und eine unzulässige
  Datei ablehnen lassen,
- Nachrichtenvordruck als PDF und als Bild erzeugen und aus der geschützten
  Vordruckliste abrufen,
- Einsatztagebuch und technisches Betriebsbuch mit S2/Red-Copy sowie A/W in
  der Rolle Fernmelder beschreiben; anschließend beide Bücher mit der jeweils
  anderen angemeldeten Funktion vollständig, aber ohne Schreibformular lesen
  und Cross-Rollen-Schreibversuche mit HTTP 403 abweisen; Kommunikationsplan
  und alle lokal benötigten Zusatzmodule öffnen und je einen repräsentativen
  Datensatz anlegen/lesen,
- administrativen Einsatzexport erzeugen, ZIP öffnen und
  `manifest.json`-Hashes gegen die CSV-Dateien prüfen,
- Vollbackup in ein leeres Testprojekt zurückspielen und denselben
  Nachrichten-/Anhangdatensatz wiederfinden.

Für jeden Abnahmelauf werden Commit, Image-Digest, Datenbankversion, Browser,
Zeitpunkt, Prüfer und Ergebnis protokolliert. Screenshots allein reichen nicht:
Die maschinenlesbaren Testausgaben und Backup-Prüfsummen gehören zum
Freigabenachweis.

## Readiness und Containerzustand

`/health.php` liefert HTTP 200 nur, wenn alle folgenden Prüfungen erfolgreich
sind:

- PHP ist mindestens Version 8.5,
- `gd`, `mbstring`, `mysqli`, `Zend OPcache` und `zip` sind geladen,
- Datenbankverbindung und `SELECT 1` funktionieren,
- 14 Basistabellen sowie die exakt definierten Benutzer- und Anhangindizes
  vorhanden sind,
- beide versionierten Migrationen mit gültigem SHA-256 als angewendet
  protokolliert sind,
- Benutzer-, IP-, Anhang- und alle sechs Nachrichten-Kürzelfelder die
  erforderlichen Breiten besitzen,
- Anhang-, Vordruck- und Exportverzeichnis beschreibbar sind.

Der Speichercheck legt kurzzeitig eine kleine Probe-Datei an und entfernt sie
wieder. Bei Fehlern liefert der Endpunkt HTTP 503 und nur boolesche
Teilergebnisse, keine Zugangsdaten.

```console
curl --fail --silent --show-error \
  http://127.0.0.1:8080/health.php
podman compose ps
```

Die Compose-Healthchecks verwenden denselben App-Endpunkt beziehungsweise
MariaDBs `healthcheck.sh --connect --innodb_initialized`. Ein externer Monitor
soll auf HTTP-Status, JSON-Status, Container-Restarts und Antwortzeit
alarmieren.

`/health.php` ist bewusst knapp und ersetzt nicht `docker/db/verify.sql`.
Der ausführlichere `/4fadm/system_status.php` zeigt hinter Admin-Authentisierung
PHP-Module, Tabellenanzahl, beschreibbare Speicher und Konfigurationsstatus.

## Logs und Kapazität

```console
podman compose logs --since=10m migrate app db
podman compose logs --follow --tail=100 migrate app db

podman compose exec -T app \
  du -sh /var/www/html/4fdata /var/lib/estab/export
podman compose exec -T db du -sh /var/lib/mysql
```

Zu alarmieren sind mindestens:

- `unhealthy`, HTTP 503 oder wiederholte Container-Restarts,
- PHP `Fatal error`, `Uncaught`, Datenbank- oder Schreibfehler,
- fehlgeschlagene Login-/Export-/Upload-Abläufe in auffälliger Häufung,
- knapper Datenträger oder unerwartetes Wachstum,
- fehlendes beziehungsweise zu altes Backup,
- fehlgeschlagene Schema- oder Restore-Probe.

Container-Logs gehen standardmäßig an die Laufzeit. Rotation, zentrale
Sammlung und Aufbewahrung müssen dort konfiguriert werden; Logs können IP- und
einsatzbezogene Informationen enthalten.

## Prüfzeitpunkte

| Zeitpunkt | Mindestprüfung |
| --- | --- |
| jeder Commit | statische Suite |
| jeder Image-Build | erfolgreicher Build und Apache `configtest` |
| Erstinstallation | Readiness, `verify.sql`, HTTP-Smoke und Fachabnahme |
| Upgrade/Migration | Restore-Kopie, SQL-Migrationen, alle Testebenen |
| laufender Betrieb | Readiness, Logs, Restarts, Speicher, Backup-Alter |
| regelmäßig | vollständige Restore-Probe und repräsentative Fachabnahme |

Apache kann im gestarteten Test-Stack separat geprüft werden:

```console
podman compose exec -T app apache2ctl configtest
```
