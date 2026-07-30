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
install -d -m 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
ESTAB_CONTAINER_CLI=podman sh deploy/registry/backup.sh "$backup_target"
```

```console
install -d -m 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
ESTAB_CONTAINER_CLI=docker sh ./backup.sh "$backup_target"
```

Das erste Beispiel gilt im Git-Checkout mit Podman, das zweite im
Releasepaket beziehungsweise auf Synology mit Docker. Ohne
`ESTAB_CONTAINER_CLI` wählt das Programm Docker Compose und danach Podman
Compose selbstständig. Ein gesetzter Wert darf ausschließlich `docker`,
`podman` oder der Pfad zu genau einem kompatiblen Programm sein.

Der Helfer erwirbt für das gesamte Backup atomar per `mkdir` den exklusiven
Lock `.estab-backup.lock` im Elternverzeichnis des Ziels. Damit können weder
zwei Sicherungen gleichzeitig die App stoppen noch zwei kooperierende
Prozesse denselben Veröffentlichungsbereich verändern. Existiert der Lock
bereits, bricht der Lauf geschlossen ab. Die darin liegende `owner.txt`
protokolliert PID, Ziel und UTC-Startzeit. Nach einem Hostabsturz darf ein
verwaister Lock erst manuell entfernt werden, nachdem auf dem Host sicher
bewiesen wurde, dass der genannte beziehungsweise kein anderer Backup-Prozess
mehr läuft.

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
   persistierten PDF-Vordrucks, den globalen Einsatzkopf, vorhandene
   ETB-/TBB-Einträge sowie
   Kennung und SHA-256 des zuvor per Manifest/CSV geprüften Export-ZIP
   unverändert nachweisbar sein. Die ETB-/TBB-Prüfung ist dabei absichtlich
   read-only, damit fehlende Daten den Lauf beenden und nicht unbemerkt neu
   angelegt werden.
2. Der Pull-only-Lauf startet dasselbe App-/Migrator-Imagepaar mit drei echten
   temporären Host-Bind-Mounts. Container-Inspect muss deren Typ, Quelle und
   Ziel exakt bestätigen. Anschließend werden ein Datenbankmarker und je ein
   Dateimarker in `4fdata` und `export` gesichert. Nur ein per Zufallsnamen,
   Projektkennung und Guard-Datei gebundener temporärer Wurzelpfad darf geleert
   werden. Nach Restore müssen Migrator, Readiness, Datenbankmarker, beide
   Dateiinhalte und deren SHA-256 unverändert sein. Ein grünes Ergebnis wird
   erst nach Entfernung beider Compose-Projekte, ihrer Container, Volumes und
   Netzwerke sowie des temporären Hostbaums ausgegeben.

## Vollständige Wiederherstellung

Die folgenden Schritte überschreiben die gewählte Zieldatenbank sowie beide
Dateivolumes. Vorher müssen Compose-Projekt, Backup-Pfad und Zielhost dreifach
geprüft werden.

Im Beispiel wird der konkrete Sicherungssatz einmal festgelegt:

```console
restore_dir=backups/20260723-120000
backup_verifier=deploy/registry/verify-backup.sh
expected_database=estab
test -r "$backup_verifier"
sh "$backup_verifier" "$restore_dir" "$expected_database"
```

Im installierten Pull-only-Paket liegt dasselbe Programm direkt als
`verify-backup.sh`; dort wird deshalb
`backup_verifier=./verify-backup.sh` gesetzt. Der Verifier ist ein zwingender,
rein lesender Preflight. Er verbindet sich nicht mit MariaDB und startet keinen
Container: Er prüft die
`SHA256SUMS`, die vollständigen gzip-Streams, ausschließlich relative
tar-Mitglieder sowie das Fehlen von Links und Sonderdateien. Beim SQL-Dump
verlangt er eine nichtleere, vollständig abgeschlossene MariaDB-Dumpstruktur
mit übereinstimmender Datenbank- und `USE`-Anweisung. Das Manifest darf genau
die drei erwarteten Nutzdateien eines Legacy-Satzes oder exakt alle neun
Payload- und Metadatendateien von Format 2 nennen. Für Format 2 prüft das
Programm zusätzlich Zeit-, Projekt-, Datenbank-, Speicherquellen- und
Image-Digest-Struktur. `expected_database` muss exakt dem wirksamen
`ESTAB_DB_NAME` des Zielprojekts entsprechen; dadurch kann ein formal gültiger
Dump einer anderen Datenbank nicht in einen scheinbar gesunden, aber leeren
eStab-Stack importiert werden. Ein Fehler beendet den Restore vor der ersten
überschreibenden Operation. Manipulierte Hashes, ungebundene oder semantisch
ungültige Metadaten, unsichere Manifestpfade, Links in Archiven, ein falsches
Restore-Ziel und abweichende Datenbanknamen sind Bestandteil der statischen
Testsuite.

### 1. Zielversion bereitstellen

Image beziehungsweise Checkout müssen zur gesicherten Version passen. `.env`
und Secrets werden aus dem geschützten Konfigurationsbackup hergestellt.

Beim Source-Build im Repository:

```console
container_cli=podman
"$container_cli" compose build migrate app
"$container_cli" compose up -d db
"$container_cli" compose stop app
```

Beim Pull-only-Paket auf Docker, Podman oder Synology werden ausdrücklich die
im Sicherungsprotokoll festgehaltenen App-/Migrator-Digests in `.env`
eingetragen. Im Projektverzeichnis gilt dann:

```console
container_cli=docker
"$container_cli" compose config
"$container_cli" compose pull
"$container_cli" compose up -d db
"$container_cli" compose stop app
```

Unmittelbar nach `up -d db` und zwingend vor dem Datenbankimport wartet derselbe
Docker-/Podman-taugliche Inspect-Loop auf den echten Health-Status:

```console
health_deadline=$(( $(date +%s) + 240 ))
while :; do
  db_id=$("$container_cli" compose ps -q db 2>/dev/null || :)
  if [ -n "$db_id" ]; then
    db_state=$("$container_cli" inspect --format \
      '{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' \
      "$db_id" 2>/dev/null || :)
    case "$db_state" in
      'true healthy') break ;;
      'true starting') ;;
      'true unhealthy'|'true missing'|false\ *|'<no value>'*)
        echo "Restore abgebrochen: Datenbankstatus $db_state" >&2
        exit 1
        ;;
    esac
  fi
  if [ "$(date +%s)" -ge "$health_deadline" ]; then
    echo "Restore abgebrochen: Datenbank wurde nicht innerhalb von 240 Sekunden healthy." >&2
    exit 1
  fi
  sleep 3
done
```

Ein bloßer `running`-Status ist keine Importfreigabe. `unhealthy`, eine
fehlende Health-Prüfung oder ein gestoppter Container brechen früh ab; nur
`true healthy` führt zu Schritt 2.

Im Pull-only-Zweig wird niemals `compose build` verwendet. Die drei in `.env`
referenzierten
`ESTAB_*_DATA_SOURCE`-Pfade müssen vor dem Restore noch einmal gegen das
beabsichtigte Zielprojekt geprüft werden.

Bei einem leeren Zielvolume initialisiert MariaDB zunächst seine Benutzer. Der
Dump enthält dank `--add-drop-database` anschließend die vollständige
Anwendungsdatenbank, nicht jedoch die systemweite MariaDB-Benutzerdatenbank.

### 2. Datenbank wiederherstellen

```console
if ! sh "$backup_verifier" "$restore_dir" "$expected_database"; then
  echo "Restore-Preflight fehlgeschlagen; Datenbank-Import wird nicht gestartet." >&2
  exit 1
fi

podman compose exec -T db sh -ceu \
  'umask 077
  client_file=$(mktemp)
  trap "rm -f -- \"$client_file\"" EXIT HUP INT TERM
  {
    printf "[client]\nuser=root\npassword=\""
    sed -e "s/\\\\/\\\\\\\\/g" -e "s/\"/\\\\\"/g" \
      "$MARIADB_ROOT_PASSWORD_FILE" | tr -d "\r\n"
    printf "\"\n"
  } > "$client_file"
  mariadb --defaults-extra-file="$client_file"' \
  < "$restore_dir/database.sql"
```

Auch beim Restore bleibt das Root-Kennwort ausschließlich in der kurzlebigen
Optionsdatei.

### 3. Dateivolumes exakt wiederherstellen

Die erste Operation leert ausschließlich die beiden im Compose-Service
eingehängten Zielpfade. Sie ist destruktiv und darf nicht gegen ein anderes
Compose-Projekt ausgeführt werden. Der vollständige Preflight wird unmittelbar
vor dieser zweiten destruktiven Grenze erneut ausgeführt; seit der
Datenbankwiederherstellung beschädigte oder ausgetauschte Archive können so
nicht in die Dateivolumes gelangen.

```console
if ! sh "$backup_verifier" "$restore_dir" "$expected_database"; then
  echo "Restore-Preflight fehlgeschlagen; Dateivolumes bleiben unverändert." >&2
  exit 1
fi

podman compose run --rm --no-deps -T --entrypoint sh app -ceu '
  find /var/www/html/4fdata -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  find /var/lib/estab/export -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
'

podman compose run --rm --no-deps -T --entrypoint sh app -ceu \
  'tar -xzf - -C /var/www/html/4fdata' \
  < "$restore_dir/4fdata.tar.gz"

podman compose run --rm --no-deps -T --entrypoint sh app -ceu \
  'tar -xzf - -C /var/lib/estab/export' \
  < "$restore_dir/export.tar.gz"
```

Der reguläre Entrypoint korrigiert beim nächsten Start fehlende
Laufzeitverzeichnisse. Fremde Besitzrechte aus dem Archiv können bei Bedarf
vorab im Test-Stack mit `tar -tvzf` geprüft werden.

Bei Bind-Mounts werden niemals die Hostpfade aus einer unkontrollierten
Shellvariable direkt rekursiv gelöscht. Das Leeren erfolgt wie oben innerhalb
der fest konfigurierten Container-Mounts, nachdem Projektordner, `.env` und die
drei effektiven Quellen sichtbar geprüft wurden. Der automatisierte Test
akzeptiert darüber hinaus ausschließlich seinen eigenen zufällig benannten
temporären Pfad samt passender Guard-Datei.

### 4. Freigabeprüfung

```console
podman compose up --force-recreate migrate
podman compose up -d app
podman compose ps
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Der Migrationslauf gleicht auch ein wiederhergestelltes älteres Schema ab und
führt die vollständige Prüfung aus. Er muss
`Post-migration schema verification passed` melden und mit Exitcode 0 enden,
bevor `app` gestartet wird.

Danach folgen HTTP-Smoke-Test und fachliche Abnahme aus
[Tests und Monitoring](TESTS-UND-MONITORING.md), insbesondere Anmeldung,
Nachrichtenfluss, Anhänge, PDF/Vordruck, ETB/TBB und Einsatzexport. Der
automatisierte Restore-Zweig vergleicht dabei nicht nur Dateinamen: Er verlangt
den ursprünglichen Anhanginhalt, den gespeicherten PDF-SHA-256 und den
gespeicherten SHA-256 des genau bezeichneten Export-ZIP.

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
podman compose exec -T app \
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
