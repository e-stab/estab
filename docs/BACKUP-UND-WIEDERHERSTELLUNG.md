# Backup und Wiederherstellung

Ein vollständiger eStab-Sicherungssatz besteht aus:

1. einem logischen MariaDB-Dump,
2. dem gesamten `estab_data`-Volume mit Anhängen und Vordrucken,
3. optional dem `estab_export`-Volume,
4. der verwendeten `.env`, den Image-/Git-Versionen und einer sicher getrennten
   Kopie der Secret-Dateien.

Nur die Kombination aus Datenbank und passendem Dateibaum stellt einen Einsatz
vollständig wieder her. Backups sind personenbezogene beziehungsweise
einsatzbezogene Daten und müssen verschlüsselt, zugriffsbeschränkt und nach
einer festgelegten Frist gelöscht werden.

## Konsistentes Vollbackup

Die folgenden Befehle halten die Anwendung an, lassen MariaDB aber für den
transaktionalen Dump laufen. Dadurch können während der Sicherung weder
Tabellen noch Anhänge verändert werden.

```console
backup_dir="backup/$(date +%Y%m%d-%H%M%S)"
install -d -m 0700 "$backup_dir"

podman compose stop app

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
  mariadb-dump \
    --defaults-extra-file="$client_file" \
    --single-transaction \
    --quick \
    --skip-lock-tables \
    --routines \
    --events \
    --triggers \
    --hex-blob \
    --default-character-set=utf8mb4 \
    --add-drop-database \
    --databases "$MARIADB_DATABASE"' \
  > "$backup_dir/database.sql"

podman compose run --rm --no-deps -T --entrypoint sh app -ceu \
  'tar -C /var/www/html/4fdata -czf - .' \
  > "$backup_dir/4fdata.tar.gz"

podman compose run --rm --no-deps -T --entrypoint sh app -ceu \
  'tar -C /var/lib/estab/export -czf - .' \
  > "$backup_dir/export.tar.gz"

podman compose start app
```

Der Dump-Client erhält das Root-Kennwort über eine temporäre Optionsdatei mit
Modus `0600`; es erscheint nicht in Prozessargumenten oder Logs. Die Datei
wird auch bei Abbruch per Trap entfernt.

Falls ein Befehl vor `podman compose start app` fehlschlägt, bleibt die
Anwendung absichtlich im Wartungszustand. Fehler zuerst klären, dann entweder
die Sicherung vollständig wiederholen oder die Anwendung bewusst starten.

Prüfsummen anlegen:

```console
(cd "$backup_dir" && \
  sha256sum database.sql 4fdata.tar.gz export.tar.gz > SHA256SUMS)
```

Auf macOS kann statt `sha256sum` verwendet werden:

```console
(cd "$backup_dir" && \
  shasum -a 256 database.sql 4fdata.tar.gz export.tar.gz > SHA256SUMS)
```

Zusätzlich sollten mindestens festgehalten werden:

```console
git rev-parse HEAD > "$backup_dir/git-commit.txt"
podman compose images > "$backup_dir/container-images.txt"
```

`.env` und die drei Secret-Dateien werden nicht unverschlüsselt in dasselbe
Backupverzeichnis gelegt. Sie gehören in einen getrennten Secret-Manager oder
ein verschlüsseltes, zugriffsbeschränktes Archiv. Bei einer Wiederherstellung
des bestehenden MariaDB-Volumes müssen die Datenbank-Secrets zum dort
gespeicherten Benutzerstand passen.

## Prüfung eines Sicherungssatzes

Vor Auslagerung:

```console
(cd "$backup_dir" && sha256sum --check SHA256SUMS)
gzip --test "$backup_dir/4fdata.tar.gz"
gzip --test "$backup_dir/export.tar.gz"
```

Auf macOS:

```console
(cd "$backup_dir" && shasum -a 256 --check SHA256SUMS)
```

Ein erfolgreicher Hash- und gzip-Test beweist nur die technische Lesbarkeit.
Der eigentliche Wiederherstellungsnachweis ist ein regelmäßig durchgeführter
Restore in einen separaten Compose-Projektnamen mit anschließendem Schema-,
HTTP- und Fachtest.

Das vollständige CI-Gate automatisiert davon einen destruktiv guardierten
Roundtrip: Es sichert Datenbank, `estab_data` und `estab_export`, löscht nur die
eindeutig als `estab_ci` beziehungsweise `estab_ci_*` erkannten Testcontainer
und -Volumes, legt alle drei Volumes leer neu an und spielt die Sicherung
zurück. Danach müssen das Schema, das bestehende Konto, die Nachricht, der
exakte Anhanginhalt, der SHA-256 des real erzeugten PDF-Vordrucks, vorhandene
ETB-/TBB-Titel und -Einträge sowie Kennung und SHA-256 des zuvor per
Manifest/CSV geprüften Export-ZIP unverändert nachweisbar sein. Die
ETB-/TBB-Prüfung ist dabei absichtlich read-only, damit fehlende Daten den Lauf
beenden und nicht unbemerkt neu angelegt werden.

## Vollständige Wiederherstellung

Die folgenden Schritte überschreiben die gewählte Zieldatenbank sowie beide
Dateivolumes. Vorher müssen Compose-Projekt, Backup-Pfad und Zielhost dreifach
geprüft werden.

Im Beispiel wird der konkrete Sicherungssatz einmal festgelegt:

```console
restore_dir=backup/20260723-120000
test -r "$restore_dir/database.sql"
test -r "$restore_dir/4fdata.tar.gz"
test -r "$restore_dir/export.tar.gz"
```

### 1. Zielversion bereitstellen

Checkout und Image müssen zur gesicherten Version passen. `.env` und Secrets
werden aus dem geschützten Konfigurationsbackup hergestellt, anschließend:

```console
podman compose build migrate app
podman compose up -d db
podman compose stop app
```

Bei einem leeren Zielvolume initialisiert MariaDB zunächst seine Benutzer. Der
Dump enthält dank `--add-drop-database` anschließend die vollständige
Anwendungsdatenbank, nicht jedoch die systemweite MariaDB-Benutzerdatenbank.

### 2. Datenbank wiederherstellen

```console
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
Compose-Projekt ausgeführt werden.

```console
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

## Administrativer Einsatzexport

Unter `/4fadm/export.php` kann ein Administrator Exporte aller Basistabellen
erzeugen, vorhandene Läufe mit Manifest und Prüfsummen ansehen und das jeweilige
ZIP herunterladen. Pro Tabelle entstehen:

- eine UTF-8-CSV-Datei mit Semikolon als Trennzeichen und Kopfzeile,
- `\N` als eindeutige Darstellung von SQL `NULL`,
- Datensatzanzahl und SHA-256-Prüfsumme im `manifest.json`,
- bei verfügbarer PHP-ZIP-Erweiterung zusätzlich ein ZIP-Archiv.

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
