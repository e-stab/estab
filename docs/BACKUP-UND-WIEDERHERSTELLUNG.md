# Backup und Wiederherstellung

Ein vollständiger eStab-Sicherungssatz besteht aus:

1. einem logischen MariaDB-Dump,
2. dem gesamten `estab_data`-Volume mit Anhängen und Vordrucken,
3. dem gesamten `estab_export`-Volume,
4. prüfsummengebundenen Angaben zu Zeitpunkt, Compose-Projekt, Datenbank,
   effektiven Speicherquellen und den drei tatsächlich laufenden Image-Digests,
5. einer getrennt geschützten Kopie von Konfiguration und Secret-Dateien.

Nur die Kombination aus Datenbank und passendem Dateibaum stellt einen Einsatz
vollständig wieder her. Backups sind personenbezogene beziehungsweise
einsatzbezogene Daten und müssen verschlüsselt, zugriffsbeschränkt und nach
einer festgelegten Frist gelöscht werden.

## Konsistentes Vollbackup

`deploy/registry/backup.sh` ist der produktive Operator-Helfer. Im
veröffentlichten Pull-only-Paket liegt er direkt als `backup.sh`. Er muss aus
dem Verzeichnis des laufenden Compose-Projekts aufgerufen werden. Das Ziel ist
absichtlich ein ausdrücklicher absoluter Pfad, sein Elternverzeichnis muss
bereits existieren und der Zielordner selbst darf noch nicht existieren.

```console
mkdir -p "$(pwd -P)/backups"
chmod 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
ESTAB_CONTAINER_CLI=podman sh deploy/registry/backup.sh "$backup_target"
```

```console
mkdir -p "$(pwd -P)/backups"
chmod 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
ESTAB_CONTAINER_CLI=docker sh ./backup.sh "$backup_target"
```

Das erste Beispiel gilt im Git-Checkout mit Podman, das zweite im
Releasepaket beziehungsweise auf Synology mit Docker. Ohne
`ESTAB_CONTAINER_CLI` wählt das Programm Docker Compose und danach Podman
Compose selbstständig. Ein gesetzter Wert darf ausschließlich `docker`,
`podman` oder der Pfad zu genau einem kompatiblen Programm sein.

Der Helfer erwirbt atomar den engine-weiten Container-Namen
`estab-maintenance-lock-<COMPOSE_PROJECT_NAME>`. `restore.sh` und
`deploy.sh up` verwenden dieselbe Sperre, auch aus anderen Releaseordnern.
Der Lock läuft netzlos mit dem verifizierten App-Image und ohne
Restart-Policy; nach einem Host-/Engine-Absturz bleibt sein Name fail-closed.
Zusätzlich
schützt `.estab-backup.lock` im Elternverzeichnis des Ziels die atomare
Veröffentlichung gegen einen zweiten Backup-Prozess. Existiert eine der
Sperren bereits, bricht der Lauf geschlossen ab. Lock-Labels protokollieren
Projekt, Operation, Eigentümerkennung und UTC-Startzeit. Ein verwaister Lock
darf erst über die gemeldete exakte Container-ID entfernt werden, nachdem
sicher bewiesen wurde, dass keine Wartungsoperation mehr läuft.

Das private Staging und der Zielordner sind Geschwister unter diesem
geschützten Elternverzeichnis. Nach erneuter Zielprüfung genügt deshalb ein
portables `mv SOURCE TARGET` als atomares Rename; GNU-spezifisches `mv -nT`
ist weder unter Linux noch macOS, BusyBox oder Synology erforderlich. Das Ziel
darf nie vorher existieren und wird nach dem Rename erneut vollständig
verifiziert.

Vor dem Stoppen der Anwendung müssen `app` und `db` nicht nur laufen, sondern
laut Container-Inspect den Status `healthy` besitzen und genau einem
Compose-Projekt zugeordnet sein; `migrate` muss beendet und erfolgreich sein.
`ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS` begrenzt jeden Health-Wait auf
standardmäßig 240 Sekunden (zulässig: 1 bis 3600). `unhealthy`, ein gestoppter
Container, eine fehlende Health-Prüfung oder ein unbekannter Status führen
sofort zum Abbruch.
Der Helfer ermittelt die tatsächlich eingehängten Quellen per
Container-Inspect. Anschließend stoppt er nur `app`, lässt MariaDB für den
transaktionalen Dump weiterlaufen und archiviert `4fdata` sowie die
Exportdaten über ihre Containerpfade. Das Root-Kennwort gelangt nur über eine
temporäre Optionsdatei mit Modus `0600` zum Dump-Client.
Zusätzliche Mountgrenzen unter oder über den produktiven Zielen sowie gleiche
oder ineinander verschachtelte Host-/Volume-Quellen werden vorher abgewiesen.
Auch das Backup-Elternverzeichnis darf keine produktive Quelle enthalten oder
von ihr enthalten werden.

Alle Ergebnisse entstehen zunächst in einem privaten, zufällig benannten
Geschwisterverzeichnis. Auch bei einem Fehler oder Signal wird ein Neustart
von `app` versucht und dabei bis zu ihrem echten `healthy`-Status gewartet;
ausschließlich das streng geprüfte Staging und der selbst erworbene Lock
werden aufgeräumt. Erst wenn App-Neustart, Health-Prüfung, Prüfsummen und
`verify-backup.sh` erfolgreich waren und das Ziel unter dem Lock weiterhin
nicht existiert, wird das Staging mit einer Umbenennung als fertiger
Backupordner veröffentlicht. Ein fehlgeschlagener Lauf überschreibt niemals
eine ältere Sicherung.

Format 2 enthält neben `database.sql`, `4fdata.tar.gz` und `export.tar.gz`:

- `backup-format.txt` und `backup-created-utc.txt`,
- `project-name.txt` und `database-name.txt`,
- `storage-sources.txt` mit Typ, Laufzeitziel, Volume-Name und Hostquelle,
- `image-references.txt` mit Referenz und Runtime-SHA-256 für App, Migrator und
  MariaDB,
- `SHA256SUMS`, das sämtliche Payload- und Metadatendateien bindet.

Docker liefert diese Runtime-ID als `sha256:<64 Kleinhexzeichen>`, Podman je
nach Version als nackte 64-stellige Kleinhex-ID. Der Backup-Helfer schreibt
beide Darstellungen einheitlich mit `sha256:`; abweichende Zeichen, Länge oder
Präfixe führen vor dem Stoppen der App zum Abbruch.

Format 2 erlaubt neben `SHA256SUMS` ausschließlich diese neun gebundenen
Dateien. Zusätzliche Dateien, Verzeichnisse oder Links lassen den Preflight
fehlschlagen.

`.env` und die drei Secret-Dateien werden bewusst nicht unverschlüsselt in
dasselbe Verzeichnis kopiert. Sie gehören in einen getrennten Secret-Manager
oder ein verschlüsseltes, zugriffsbeschränktes Konfigurationsbackup. Bei einer
Wiederherstellung des bestehenden MariaDB-Volumes müssen die
Datenbank-Secrets zum dort gespeicherten Benutzerstand passen.

Der lesende Verifier akzeptiert weiterhin ältere, nach diesem Runbook erzeugte
Sätze, deren `SHA256SUMS` exakt die drei Payloaddateien nennt. Ungebundene
historische Sidecar-Dateien wie `container-images.txt` bleiben dabei lediglich
Hinweise und werden nicht nachträglich als kryptographisch belegt behandelt.
Neue Sicherungen werden ausschließlich im gebundenen Format 2 erzeugt.

## Prüfung eines Sicherungssatzes

Vor Auslagerung:

```console
expected_database=estab
sh deploy/registry/verify-backup.sh "$backup_target" "$expected_database"
```

Im Pull-only-Paket wird stattdessen `sh ./verify-backup.sh ...` aufgerufen.
Der Verifier wählt auf Linux `sha256sum` und auf macOS bei Bedarf
`shasum -a 256`, prüft zusätzlich die Metadaten und beide tar/gzip-Archive.
Ein erfolgreicher Preflight beweist dennoch nur die technische Lesbarkeit.
Der eigentliche Wiederherstellungsnachweis ist ein regelmäßig durchgeführter
Restore in einen separaten Compose-Projektnamen mit anschließendem Schema-,
HTTP- und Fachtest.

Das vollständige CI-Gate automatisiert zwei destruktiv guardierte Roundtrips:

1. Der fachliche Hauptlauf sichert Datenbank, `estab_data` und `estab_export`,
   löscht nur die eindeutig als `estab_ci` beziehungsweise `estab_ci_*`
   erkannten Testcontainer und benannten Test-Volumes, legt alle drei Volumes
   leer neu an und spielt die Sicherung zurück. Danach müssen Schema,
   bestehendes Konto, Nachricht, exakter Anhanginhalt, SHA-256 des
   persistierten PDF-Vordrucks, den globalen Einsatzkopf einschließlich
   einsatzbezogenem Führungsstellennamen, vorhandene
   ETB-/TBB-Einträge sowie
   Kennung und SHA-256 des zuvor per Manifest/CSV geprüften Export-ZIP
   unverändert nachweisbar sein. Die ETB-/TBB-Prüfung ist dabei absichtlich
   read-only, damit fehlende Daten den Lauf beenden und nicht unbemerkt neu
   angelegt werden.
2. Der Pull-only-Lauf startet dasselbe App-/Migrator-Imagepaar mit drei echten
   temporären Host-Bind-Mounts. Container-Inspect muss deren Typ, Quelle und
   Ziel exakt bestätigen. Anschließend werden ein Datenbankmarker und je ein
   Dateimarker in `4fdata` und `export` gesichert. Der Test verfälscht den
   Datenbankmarker logisch und ergänzt veraltete Testdaten in den beiden
   Dateibereichen; der rohe MariaDB-Bind-Mount wird nicht geleert. Nach
   Restore müssen Migrator, Readiness, Datenbankmarker, beide
   Dateiinhalte und deren SHA-256 unverändert sein. Ein grünes Ergebnis wird
   erst nach Entfernung beider Compose-Projekte, ihrer Container, Volumes und
   Netzwerke sowie des temporären Hostbaums ausgegeben.

## Vollständige Wiederherstellung

`deploy/registry/restore.sh` ist der produktive Restore-Helfer; im
Pull-only-Paket liegt er direkt als `restore.sh`. Er überschreibt die
Anwendungsdatenbank sowie `4fdata` und die Exportdaten. Der Aufruf verlangt
deshalb neben einem existierenden absoluten Backup-Pfad die wörtliche
Bestätigung des Compose-Projektnamens:

```console
restore_dir="$(pwd -P)/backups/20260723-120000"
confirmed_project=estab
ESTAB_CONTAINER_CLI=podman \
  sh deploy/registry/restore.sh \
    --confirm-project "$confirmed_project" \
    "$restore_dir"
```

Im entpackten Releasepaket auf Docker oder Synology lautet derselbe Aufruf:

```console
restore_dir="$(pwd -P)/backups/20260723-120000"
confirmed_project=estab
ESTAB_CONTAINER_CLI=docker \
  sh ./restore.sh \
    --confirm-project "$confirmed_project" \
    "$restore_dir"
```

Ohne `ESTAB_CONTAINER_CLI` erkennt der Helfer wie `backup.sh` zuerst Docker
Compose und danach Podman Compose. Er verwendet anschließend ausschließlich
dieses erkannte Programm. `ESTAB_RESTORE_HEALTH_TIMEOUT_SECONDS` begrenzt jeden
Health-Wait auf standardmäßig 240 Sekunden und akzeptiert Werte von 1 bis
3600.

Vor dem Aufruf müssen `.env` und Secrets aus dem getrennt geschützten
Konfigurationsbackup stammen. App-, Migrator- und MariaDB-Image sowie die drei
Speicherquellen müssen exakt dem wiederherzustellenden Sicherungssatz
entsprechen. Das Compose-Projekt muss bereits einmal vollständig angelegt
worden sein, damit `app`, `migrate` und `db` eindeutig als je ein Container
prüfbar sind. Für ein Pull-only-Paket werden die im Backup protokollierten
App-/Migrator-Referenzen verwendet; ein lokaler Build ist dort nicht Teil des
Restore-Ablaufs.

Der Helfer akzeptiert ausschließlich das vollständig gebundene Format 2.
Ältere Legacy-Sätze bleiben mit `verify-backup.sh` lesbar, besitzen aber keine
kryptographisch gebundenen Projekt-, Mount- und Image-Metadaten und werden
deshalb nicht automatisch produktiv eingespielt. Vor der ersten Änderung
prüft `restore.sh`:

- Prüfsummen, Dump, Archive und sämtliche Format-2-Metadaten,
- Backup-Projekt, `--confirm-project` und die Projektlabels aller drei
  Laufzeitcontainer auf exakte Gleichheit,
- den Datenbanknamen aus Backup und laufender MariaDB,
- Ziel, Typ, Volume-Name und Quelle aller drei produktiven Mounts,
- den expliziten Read/Write-Status von `4fdata` und Exportdaten,
- Referenz und Runtime-SHA-256 von App, Migrator und MariaDB,
- Exitcode 0 und das exakte App-Image von `admin-auth-init`,
- einen echten `healthy`-Status der Datenbank innerhalb des Zeitlimits.

Der engine-weite Lockcontainer verhindert gleichzeitig laufende Backups,
Restores und `deploy.sh up`, unabhängig vom Releaseordner. Vor destruktiven
Grenzen werden exakte ID, verifiziertes Image, Labels und laufender Status
erneut geprüft. Ein verwaister Lock darf erst über die gemeldete exakte ID
entfernt werden, nachdem keine Wartungsoperation mehr läuft.

Erst danach stoppt der Helfer die App. Noch vor dem Start beziehungsweise
Import der Datenbank legt ein netzloser, schreibgeschützter Hilfscontainer mit
dem exakt verifizierten App-Image in beiden Dateimounts je eine private,
exklusiv erzeugte Probe an, liest sie zurück und entfernt sie wieder. Ein
Read-only-Mount oder eine abweichende effektive Schreibbarkeit beendet den
Restore ohne Datenbankaktion und startet eine zuvor laufende App wieder.
Unmittelbar vor dem Datenbankimport prüft er Laufzeitziel, `admin-auth-init`
und kompletten Backup-Satz erneut. Das Root-Kennwort
gelangt nur über eine kurzlebige Optionsdatei in den MariaDB-Client. Vor dem
Leeren der beiden Dateibereiche folgen dieselben Zielprüfungen und erneut der
vollständige Verifier. Das Leeren findet ausschließlich innerhalb der fest
geprüften Container-Mounts `/var/www/html/4fdata` und
`/var/lib/estab/export` statt; ein Hostpfad wird niemals aus einer Variable an
ein rekursives Löschkommando übergeben.
Das Backupverzeichnis selbst muss vollständig außerhalb aller produktiven
Mountquellen liegen, damit es weder mitgelöscht noch rekursiv in eine
Sicherung aufgenommen werden kann.

Nach dem Einspielen muss der One-shot-Migrator beendet sein und Exitcode 0
melden. Erst dann startet die App. Container-Health und
`estab-healthcheck` müssen erfolgreich sein. Zusätzlich muss
`admin-auth-init` beendet sein, Exitcode 0 melden und exakt dasselbe geprüfte
App-Image verwenden. Dieser Zustand wird sowohl vor der ersten Datenmutation
als auch nach dem Einspielen geprüft. Anschließend werden Laufzeitmetadaten
und Backup ein letztes Mal geprüft.

Schlägt ein Schritt vor dem ersten Import fehl, bleiben die Nutzdaten
unverändert und eine zuvor laufende App wird wieder bis `healthy` gestartet.
Sobald der Datenbankimport begonnen hat, bleibt die App bei jedem Fehler
absichtlich gestoppt. Die Meldung `RECOVERY REQUIRED` nennt die letzte Phase
und behält den globalen Lock absichtlich bei. Nach Ursachenbehebung muss
bewiesen werden, dass kein Restore mehr läuft, exakt die gemeldete Lock-ID
entfernt und derselbe verifizierte Restore erneut ausgeführt werden. Eine
teilweise wiederhergestellte Datenbank oder ein
teilweise entpackter Dateibaum darf niemals manuell als produktiv freigegeben
werden.

Danach folgen HTTP-Smoke-Test und fachliche Abnahme aus
[Tests und Monitoring](TESTS-UND-MONITORING.md), insbesondere Anmeldung,
Nachrichtenfluss, Anhänge, PDF/Vordruck, ETB/TBB und Einsatzexport. Der
automatisierte Restore-Zweig vergleicht dabei nicht nur Dateinamen: Er verlangt
den ursprünglichen Anhanginhalt, den gespeicherten PDF-SHA-256 und den
gespeicherten SHA-256 des genau bezeichneten Export-ZIP. Für einen nach
Migration 97 angelegten Einsatz muss außerdem derselbe Führungsstellenname
erhalten bleiben; ein historischer `NULL`-Wert darf beim Restore nicht aus
Organisation, Einsatzname oder Umgebung ergänzt werden.

Der Vordruck-SHA-256 bezieht sich ausdrücklich auf die beim Abschluss
persistierte Archivdatei. Die Benutzeroberfläche rendert unter
`/4fach/vordrucke.php` zusätzlich einen aktuellen, nicht persistierten
Layout-Abzug aus denselben Nachrichtendaten; dessen Bytes dürfen sich nach
einem Vorlagen-Upgrade ändern, ohne den Restore-Nachweis des Archivs zu
verletzen.

## Administrativer Einsatzexport

Unter `/4fadm/export.php` kann ein Administrator Exporte aller Basistabellen
erzeugen, vorhandene Läufe mit Manifest und Prüfsummen ansehen und das jeweilige
ZIP herunterladen. Pro Tabelle entstehen:

- eine UTF-8-CSV-Datei mit Semikolon als Trennzeichen und Kopfzeile,
- `\N` als eindeutige Darstellung von SQL `NULL`,
- Datensatzanzahl und SHA-256-Prüfsumme im `manifest.json`,
- bei verfügbarer PHP-ZIP-Erweiterung zusätzlich ein ZIP-Archiv.

Die `nv_einsaetze`-CSV führt `fuehrungsstellenname` und dessen dauerhaften
Sperrmarker getrennt von `name`, `organisation` und `einsatzleitung`. Ein
historisch nicht erfasster Wert bleibt dabei der eindeutige rohe
SQL-NULL-Marker `\N`; nur die historische PDF-Ausgabe beschriftet ihn
zusätzlich als „historisch nicht erfasst“.

Vor der Veröffentlichung prüft eStab alle nach Migration 95 finalisierten
Anhangdateien gegen den beim Eingang unveränderlich gespeicherten SHA-256 und
die Bytezahl. Eine Abweichung verhindert den gesamten Export. Das Manifest
weist Anzahl der verifizierten Dateien und des beim Upgrade vorhandenen
Legacy-Bestands getrennt aus; dessen Aussage lautet ausdrücklich
**Integrität beim Eingang nicht belegbar**. Die CSV enthält zwar auch die
Integritätsspalten, der Export erfindet für Legacy-Zeilen aber keinen
rückwirkenden Eingangshash.

CSV-Kopfzeilen und Werte, deren erstes Nicht-Leerraum-Zeichen `=`, `+`, `-`
oder `@` ist, erhalten ein führendes Apostroph und werden dadurch beim Öffnen
in Tabellenkalkulationen als Text behandelt. Der eindeutige NULL-Marker `\N`
bleibt davon unberührt. Neue Manifeste dokumentieren diese Regel mit
`spreadsheet_formula_prefix` und `spreadsheet_formula_triggers`; bestehende
Format-1-Manifeste ohne diese beiden optionalen Felder bleiben lesbar.

Die Dateien liegen privat im Volume `estab_export`. Sie können zusammen
gesichert werden:

```console
container_cli=${ESTAB_CONTAINER_CLI:-docker}
"$container_cli" compose exec -T app \
  tar -C /var/lib/estab/export -czf - . \
  > estab-export.tar.gz
```

Jeder vollständig veröffentlichte Lauf erscheint in der Übersicht mit
Zeitpunkt, Datenbank, Tabellen-/Datensatzanzahl und Archivgröße. „Export
löschen …“ öffnet zunächst eine Warnung; erst die zweite, CSRF-geschützte
Bestätigung entfernt genau dieses ZIP und sein CSV-Verzeichnis. Der Vorgang
ist nicht rückgängig zu machen. Ein anderer Export bleibt davon unberührt.
Symlinks oder unerwartete Unterverzeichnisse werden aus Sicherheitsgründen
nicht über die Weboberfläche gelöscht.

Während der Erzeugung liegen CSV, Manifest und ZIP ausschließlich in einem
privaten Stagingverzeichnis mit Modus `0700`, das in der Exportliste nicht
erscheint. Erst nach vollständiger Dateisatz- und Archivprüfung wird das
Verzeichnis umbenannt; ein nicht überschreibbarer Hardlink des fertigen ZIPs
ist anschließend der atomare Veröffentlichungsmarker. Schlägt eine Phase
vorher fehl, wird nur das eindeutig reservierte Staging aufgeräumt. Ein
bereits veröffentlichter anderer Export wird weder ersetzt noch beschädigt.

Dieser Export ist für Prüfung und Datenaustausch gedacht, aber **kein
Vollbackup**:

- er enthält keine Tabellendefinitionen, Benutzer/Grants oder
  Wiederherstellungslogik,
- er enthält keine Anhänge und keine erzeugten Vordrucke,
- die Tabellen werden nacheinander und nicht in einer gemeinsamen
  Snapshot-Transaktion gelesen,
- es gibt keinen automatischen CSV-Importer,
- alte Exporte werden nicht automatisch rotiert; die neue Einzellöschung
  bleibt eine bewusste manuelle Aufbewahrungsentscheidung.

Für Disaster Recovery ist immer das konsistente Vollbackup aus Datenbankdump
und beiden Dateibereichen maßgeblich.

## Aufbewahrung und Restore-Probe

Eine betriebliche Regelung sollte mindestens definieren:

- Sicherungsintervall und maximales toleriertes Datenverlustfenster,
- verschlüsselte Ablage und getrennte Zugangsdaten,
- mindestens eine Kopie außerhalb des Containerhosts,
- Aufbewahrungs- und Löschfristen,
- automatische Alarmierung bei fehlgeschlagener oder zu alter Sicherung,
- turnusmäßige Wiederherstellung in einen isolierten Test-Stack,
- dokumentierte Dauer bis zur fachlichen Freigabe.

Ein Backup gilt erst dann als belastbar, wenn eine Restore-Probe mit
`verify.sql`, HTTP-Smoke-Test, bytegenau geprüften Dateiartefakten,
ausschließlich gelesenen bestehenden ETB-/TBB-Daten und ausgewählten
Fachabläufen erfolgreich war. Das grüne lokale CI-Gate ersetzt nicht die
regelmäßige betriebliche Wiederherstellung auf einem getrennten Host.
