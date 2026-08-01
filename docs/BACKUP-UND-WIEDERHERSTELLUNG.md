# Backup und Wiederherstellung

Ein vollständiger eStab-Sicherungssatz besteht aus:

1. einem logischen MariaDB-Dump,
2. dem gesamten `estab_data`-Volume mit Anhängen und Vordrucken,
3. dem gesamten `estab_export`-Volume,
4. prüfsummengebundenen Angaben zu Zeitpunkt, Compose-Projekt, Datenbank,
   effektiven Speicherquellen, der architekturunabhängigen Release-Identität
   und den drei tatsächlich laufenden Image-IDs,
5. einer getrennt geschützten Kopie von Konfiguration und Secret-Dateien.

Nur die Kombination aus Datenbank und passendem Dateibaum stellt einen Einsatz
vollständig wieder her. Backups sind personenbezogene beziehungsweise
einsatzbezogene Daten und müssen verschlüsselt, zugriffsbeschränkt und nach
einer festgelegten Frist gelöscht werden.

## Konsistentes Vollbackup

`deploy/registry/backup.sh` ist der produktive Operator-Helfer. Im
veröffentlichten Pull-only-Paket liegt er direkt als `backup.sh`. Er muss aus
dem Verzeichnis des laufenden Compose-Projekts aufgerufen werden. Das Ziel ist
absichtlich ein ausdrücklicher absoluter Pfad. Sein Elternverzeichnis muss
bereits existieren, dem ausführenden Benutzer gehören und exakt Modus `0700`
haben; der Zielordner selbst darf noch nicht existieren. Diese private
Vertrauensgrenze verhindert, dass ein anderer Hostbenutzer die reservierte
Veröffentlichung während des Backups austauscht.

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
Zusätzlich schützt `.estab-backup.lock` im Elternverzeichnis des Ziels die
Veröffentlichung gegen einen zweiten Backup-Prozess. Existiert eine der
Sperren bereits, bricht der Lauf geschlossen ab. Lock-Labels protokollieren
Projekt, Operation, Eigentümerkennung und UTC-Startzeit. Ein verwaister Lock
darf erst über die gemeldete exakte Container-ID entfernt werden, nachdem
sicher bewiesen wurde, dass keine Wartungsoperation mehr läuft.

Das private Staging und der Zielordner liegen unter demselben geschützten
Elternverzeichnis. Nach vollständiger Staging-Verifikation reserviert ein
einziges `mkdir TARGET` den endgültigen Namen atomar und ohne Überschreiben.
Gewinnt ein fremder Erzeuger die Race, schlägt diese Reservierung fehl; dessen
Verzeichnis und Inhalt bleiben unangetastet. Erst im selbst reservierten, durch
`umask 077` geschützten Ziel werden die exakt bekannten Dateien auf ihre
endgültigen Namen verschoben und anschließend erneut vollständig verifiziert.
Damit hängt die No-Clobber-Garantie weder von GNU-`mv -nT` noch vom
unterschiedlichen Verzeichnisverhalten von GNU-, BSD- oder BusyBox-`mv` ab.

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
von `app` versucht und dabei bis zu ihrem echten `healthy`-Status gewartet.
Das streng geprüfte Staging und ein nachweislich selbst reserviertes,
unvollständiges Ziel werden auf bekannten Dateinamen aufgeräumt; fremde oder
unerwartet veränderte Ziele bleiben fail-closed stehen. Nach einem harten
Prozess- oder Hostabbruch kann daher ein geschützter unvollständiger Zielname
zusammen mit `.estab-backup.lock` zur Diagnose verbleiben und darf erst nach
Prüfung gezielt entfernt werden. Als erfolgreich gilt ausschließlich der
erneut vollständig verifizierte exakte Format-3-Dateisatz. Ein
fehlgeschlagener Lauf überschreibt oder ergänzt niemals eine ältere oder
gleichzeitig fremd erzeugte Sicherung.

Das aktuelle Format 3 enthält neben `database.sql`, `4fdata.tar.gz` und
`export.tar.gz`:

- `backup-format.txt` und `backup-created-utc.txt`,
- `project-name.txt` und `database-name.txt`,
- `storage-sources.txt` mit Typ, Laufzeitziel, Volume-Name und Hostquelle,
- `release-identity.txt` mit der effektiven `Config.Image`-Referenz für App,
  Migrator und MariaDB als architekturunabhängiger Release-Identität,
- `image-references.txt` mit derselben Referenz und der
  architekturabhängigen Runtime-Image-ID für Diagnose und exakte
  Same-Host-Prüfung,
- `SHA256SUMS`, das sämtliche Payload- und Metadatendateien bindet.

Docker liefert diese Runtime-ID als `sha256:<64 Kleinhexzeichen>`, Podman je
nach Version als nackte 64-stellige Kleinhex-ID. Der Backup-Helfer schreibt
beide Darstellungen einheitlich mit `sha256:`; abweichende Zeichen, Länge oder
Präfixe führen vor dem Stoppen der App zum Abbruch. Format 3 erlaubt neben
`SHA256SUMS` ausschließlich diese zehn gebundenen Dateien. Der Verifier
verlangt außerdem, dass jede Referenz in `release-identity.txt` bytegenau zur
Referenz derselben Rolle in `image-references.txt` passt. Zusätzliche Dateien,
Verzeichnisse, Links, fehlende Einträge oder doppelte Rollen lassen den
Preflight fehlschlagen.

Auch manifestgebundene `Config.Image`-Referenzen werden
providerunabhängig kanonisiert. Ein redundanter Tag vor `@sha256:` entfällt,
kurze Docker-Hub-Namen werden zu `docker.io/library/…` beziehungsweise
`docker.io/…` erweitert und `index.docker.io` wird als `docker.io`
geschrieben. Ein ausdrücklich angegebener Registry-Port wie
`registry.example:5443/…` bleibt dabei Teil des Registry-Namens. Dadurch
bezeichnen zum Beispiel `mariadb:11.8.8@sha256:…` von einem Provider und
`docker.io/library/mariadb@sha256:…` von einem anderen dieselbe gebundene
Release-Identität. Veränderliche Tags und lokale Referenzen ohne
Manifest-Digest werden absichtlich nicht umgedeutet: Sie eignen sich nur für
den exakten Same-Runtime-Restore und können keine Runtime-ID-Abweichung
autorisieren.

`.env` und die drei Secret-Dateien werden bewusst nicht unverschlüsselt in
dasselbe Verzeichnis kopiert. Sie gehören in einen getrennten Secret-Manager
oder ein verschlüsseltes, zugriffsbeschränktes Konfigurationsbackup. Bei einer
Wiederherstellung des bestehenden MariaDB-Volumes müssen die
Datenbank-Secrets zum dort gespeicherten Benutzerstand passen.

Der lesende Verifier akzeptiert weiterhin Format 2 sowie ältere, nach diesem
Runbook erzeugte Sätze, deren `SHA256SUMS` exakt die drei Payloaddateien nennt.
Format 2 besitzt gebundene Runtime-Image-Datensätze, aber keine getrennte
Release-Identität; es ist deshalb nur für einen exakten Restore mit denselben
Runtime-Image-IDs, demselben Compose-Projekt und exakt denselben Speicher-,
Mounttyp- und Volume-Angaben geeignet. Sämtliche Projekt-, Speicher-,
Mounttyp- und Volume-Remaps sowie
`--allow-runtime-image-id-change` sind für Format 2 verboten. Ungebundene
historische Sidecar-Dateien wie
`container-images.txt` bleiben lediglich Hinweise und werden nicht
nachträglich als kryptographisch belegt behandelt. Legacy-Sätze bleiben
verifier-only. Neue Sicherungen werden ausschließlich im gebundenen Format 3
erzeugt.

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
   einsatzbezogenem Führungsstellennamen und Berechtigungsmodus, vorhandene
   ETB-/TBB-Einträge sowie
   Kennung und SHA-256 des zuvor per Manifest/CSV geprüften Export-ZIP
   unverändert nachweisbar sein. Die ETB-/TBB-Prüfung ist dabei absichtlich
   read-only, damit fehlende Daten den Lauf beenden und nicht unbemerkt neu
   angelegt werden.
2. Der Pull-only-Lauf erzeugt auf dem echten Docker- beziehungsweise
   Podman-Provider zunächst ein Format-3-Backup aus drei Named Volumes. Dabei
   muss die MariaDB-Referenz in beiden Metadatendateien kanonisch als
   `docker.io/library/mariadb@sha256:…` erscheinen. Danach startet er dasselbe
   App-/Migrator-Imagepaar in einem anderen Projekt mit drei echten temporären
   Host-Bind-Mounts. Container-Inspect muss deren Typ, Quelle und Ziel exakt
   bestätigen und der Restore muss den vollständigen
   Named-Volume-zu-Bind-Remap durchsetzen. Der Test verfälscht den zuvor
   gesicherten Datenbankmarker logisch und ergänzt veraltete Testdaten in den
   beiden Dateibereichen; der rohe MariaDB-Bind-Mount wird nicht geleert. Nach
   Restore müssen Migrator, Readiness, Datenbankmarker, beide Dateiinhalte
   und deren SHA-256 unverändert sein. Ein grünes Ergebnis wird erst nach
   Entfernung beider Compose-Projekte, ihrer Container, Volumes und Netzwerke
   sowie des temporären Hostbaums ausgegeben.

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
Konfigurationsbackup stammen. Das Compose-Projekt muss bereits einmal
vollständig angelegt worden sein, damit `app`, `migrate` und `db` eindeutig
als je ein Container prüfbar sind. Für ein Pull-only-Paket werden die im
Backup protokollierten App-/Migrator-Referenzen verwendet; ein lokaler Build
ist dort nicht Teil des Restore-Ablaufs.

Ohne weitere Optionen gilt fail-closed der Same-Host-Vertrag: Compose-Projekt,
alle drei Mountziele, Mounttypen, Volume-Namen, Speicherquellen,
`Config.Image`-Referenzen und Runtime-Image-IDs müssen bytegenau dem Backup
entsprechen. Der oben gezeigte Standardaufruf nimmt keinerlei Umbenennung oder
Pfadableitung vor.

Für einen bewusst auf ein anderes Compose-Projekt, andere Speicherpfade oder
eine andere CPU-Architektur übertragenen Format-3-Satz muss jede tatsächliche
Abweichung explizit als `alt=neu` angegeben werden. Beispiel:

```console
restore_dir=/mnt/restore/20260723-120000
source_project=estab
target_project=estab_dr
old_db_source=/var/lib/docker/volumes/estab_estab_db/_data
new_db_source=/srv/estab-dr/data/db
old_app_source=/srv/estab/data/4fdata
new_app_source=/srv/estab-dr/data/4fdata
old_export_source=/srv/estab/data/export
new_export_source=/srv/estab-dr/data/export

ESTAB_CONTAINER_CLI=docker \
  sh ./restore.sh \
    --confirm-project "$target_project" \
    --remap-project "$source_project=$target_project" \
    --remap-mount-type "database:volume=bind" \
    --remap-storage "database:$old_db_source=$new_db_source" \
    --remap-volume "database:${source_project}_estab_db=-" \
    --remap-storage "application:$old_app_source=$new_app_source" \
    --remap-storage "export:$old_export_source=$new_export_source" \
    --allow-runtime-image-id-change \
    "$restore_dir"
```

Die Rollen für `--remap-mount-type`, `--remap-storage` und `--remap-volume`
sind ausschließlich `database`, `application` und `export`. Ein Remap muss
exakt den im Backup gespeicherten Wert auf den aktuell inspizierten Wert
abbilden. `--remap-mount-type` akzeptiert nur `volume` und `bind`.
`--remap-volume` bestätigt das inspectierte Namensfeld; `-` bezeichnet dabei
den absichtlich namenlosen Bind-Mount. Bei einem Wechsel von Named Volume zu
Bind sind Typ, Name und Quelle somit drei getrennte, zwingende Bestätigungen.
Dasselbe gilt umgekehrt für Bind zu Named Volume.

Fehlende, falsche, doppelte, teilweise oder für unveränderte Werte unnötige
Remaps werden abgewiesen. Ein anderer Compose-Projektname leitet insbesondere
keinen Mounttyp, Volume-Namen und keinen Engine-Speicherpfad implizit ab. Sind
auch `application` und `export` im Quellbackup Named Volumes und am Ziel
Bind-Mounts, benötigen beide Rollen jeweils dasselbe Tripel aus
`--remap-mount-type ROLE:volume=bind`,
`--remap-volume ROLE:ALTER_NAME=-` und
`--remap-storage ROLE:ALTER_PFAD=NEUER_PFAD`.

`--allow-runtime-image-id-change` ist die ausdrückliche Bestätigung des
Bedieners, dass eine abweichende lokale Runtime-ID erwartet wird, etwa beim
Wechsel von Architektur oder Container-Engine. Der Helfer kann die äußere
Ursache dieser Abweichung nicht technisch beweisen. Er begrenzt die Ausnahme
deshalb auf Format 3 und verlangt, dass die kanonisierte aktuelle
`Config.Image`-Referenz jeder Rolle mit der kanonisierten Referenz aus
`release-identity.txt` übereinstimmt und jede Referenz auf
`@sha256:<64 Kleinhexzeichen>` endet. Dieser Digest muss die verwendete
kanonische Multi-Arch-Index-Identität des Releases sein.
Providerabhängige Schreibweisen wie kurzer Docker-Hub-Name oder redundanter
Tag vor demselben Digest dürfen sich unterscheiden; Registry-Host,
gegebenenfalls Registry-Port, Repository und Digest dürfen es nicht.
Veränderliche Tags und lokale Referenzen können selbst bei gesetzter Option
niemals eine Runtime-ID-Abweichung autorisieren. Bleiben die Runtime-IDs
gleich, ist ein lokaler oder tagbasierter Quellsatz ohne diese Option
weiterhin exakt auf demselben Runtime-Stand wiederherstellbar.

Der Helfer akzeptiert die vollständig gebundenen Formate 2 und 3.
Format 2 bleibt aus Kompatibilitätsgründen ausschließlich für den exakten
Same-Host-Restore lesbar. Es kann wegen der fehlenden getrennten
Release-Identität weder `--allow-runtime-image-id-change` noch irgendeinen
Projekt-, Speicher-, Mounttyp- oder Volume-Remap verwenden. Ältere
Legacy-Sätze bleiben mit `verify-backup.sh` lesbar, besitzen aber keine
kryptographisch gebundenen Projekt-, Mount- und Image-Metadaten und werden
nicht automatisch produktiv eingespielt. Vor der ersten Änderung prüft
`restore.sh`:

- Prüfsummen, Dump, Archive und sämtliche Metadaten des erkannten Formats,
- Backup-Projekt, `--confirm-project`, einen gegebenen exakten Projekt-Remap
  und die Projektlabels aller drei Laufzeitcontainer,
- den Datenbanknamen aus Backup und laufender MariaDB,
- Ziel, Typ, Volume-Name und Quelle aller drei produktiven Mounts sowie jeden
  hierfür erforderlichen rollenbezogenen Remap,
- den expliziten Read/Write-Status von Datenbank, `4fdata` und Exportdaten,
- kanonische Release-Referenz und Runtime-Image-ID von App, Migrator und
  MariaDB nach den obigen Regeln,
- Exitcode 0 und das exakte App-Image von `admin-auth-init`,
- einen echten `healthy`-Status der Datenbank innerhalb des Zeitlimits.

Der engine-weite Lockcontainer verhindert gleichzeitig laufende Backups,
Restores und `deploy.sh up`, unabhängig vom Releaseordner. Vor destruktiven
Grenzen werden exakte ID, verifiziertes Image, Labels und laufender Status
erneut geprüft. Ein verwaister Lock darf erst über die gemeldete exakte ID
entfernt werden, nachdem keine Wartungsoperation mehr läuft.

Bei jedem Projekt-Remap oder rollenbezogenen Speicheridentitäts-Remap
(`--remap-mount-type`, `--remap-storage` oder `--remap-volume`) durchsucht der
Helfer darüber hinaus den gesamten Containerbestand derselben Engine,
ausdrücklich einschließlich gestoppter Container. Das gilt auch, wenn der
Compose-Projektname unverändert bleibt. Bind- und Volume-Quellen jedes fremden
Containers dürfen weder gleich einer Zielspeicherquelle sein noch über oder
unter ihr liegen. Diese Bestands- und Überlappungsprüfung läuft unter der
Wartungssperre vor dem privaten Snapshot sowie erneut unmittelbar vor
Datenbankimport und Dateiwiederherstellung. Ein gestoppener alter
Projektcontainer schützt also
nicht vor einem Konflikt; er muss nach gesonderter Prüfung entfernt oder auf
einen eindeutig getrennten Speicher umgestellt werden.

Noch vor dem Stoppen der App erstellt `restore.sh` aus dem verifizierten
Sicherungssatz einen privaten, vollständigen Restore-Snapshot. Die Prüfsumme
des ursprünglichen `SHA256SUMS` wird vor und nach der Kopie verglichen,
anschließend wird der kopierte exakte Format-2- beziehungsweise
Format-3-Dateisatz erneut vollständig verifiziert. Ab diesem Punkt öffnen
Datenbankimport und Archivwiederherstellung ausschließlich Dateien dieses
Snapshots; spätere Änderungen am ursprünglichen Quellverzeichnis können den
laufenden Restore daher nicht mehr austauschen.

Standardmäßig entsteht der zufällig benannte Ordner
`.estab-restore-snapshot.…` im Elternverzeichnis des Backup-Pfads. Liegt das
Backup auf einem schreibgeschützten Medium oder soll der zusätzliche
Platzverbrauch getrennt werden, muss vor dem Aufruf ein vorhandenes,
absolutes und geschütztes Ziel angegeben werden:

```console
snapshot_parent=/srv/estab-restore-snapshots
mkdir -p "$snapshot_parent"
chmod 0700 "$snapshot_parent"
ESTAB_RESTORE_SNAPSHOT_PARENT="$snapshot_parent" \
ESTAB_CONTAINER_CLI=docker \
  sh ./restore.sh \
    --confirm-project estab \
    "$restore_dir"
```

Das Elternverzeichnis muss ein echtes, beschreibbares Verzeichnis sein, darf
nicht `/`, das Backup selbst oder ein Unterverzeichnis des Backups sein und
muss `root` oder dem ausführenden Benutzer gehören. `0700` ist der empfohlene
Modus; gruppen- oder weltbeschreibbare Eltern werden nur mit gesetztem
Sticky-Bit akzeptiert. Snapshot-Ordner erhalten `0700`, ihre Dateien `0600`.
Der Ort darf nicht mit einer produktiven Speicherquelle überlappen. Vor jedem
Restore muss dort mindestens Platz für einen vollständigen zusätzlichen
Sicherungssatz zuzüglich Reserve für temporäre Providerdaten und Logs
vorhanden sein. Schlägt Anlage, Kopie oder Verifikation fehl, geschieht dies
vor der ersten Datenmutation.

Nach einem erfolgreichen Restore sowie nach jedem Abbruch vor der ersten
Datenmutation entfernt der Helfer ausschließlich den von ihm nachgewiesenen
Snapshot mit seinem exakten bekannten Inventar. Unerwartete Einträge oder ein
geänderter Pfad verhindern die automatische Entfernung fail-closed. Schlägt
der Restore nach Beginn des Datenbankimports fehl, bleibt der weiterhin
verifizierte Snapshot dagegen als Recovery-Anker erhalten und sein absoluter
Pfad wird ausdrücklich ausgegeben.

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
und behält den globalen Lock sowie den verifizierten Recovery-Snapshot
absichtlich bei. Nach Ursachenbehebung muss bewiesen werden, dass kein Restore
mehr läuft, exakt die gemeldete Lock-ID entfernt und `restore.sh` mit dem
ausgegebenen Snapshot-Pfad erneut ausgeführt werden. Das ursprüngliche
Backupverzeichnis ist hierfür nicht erneut zu öffnen. Der als Quelle
übergebene Recovery-Snapshot wird nicht stillschweigend gelöscht; erst nach
erfolgreicher fachlicher Abnahme muss er gemäß der festgelegten
Aufbewahrungsregel archiviert oder kontrolliert entfernt werden. Eine
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
Organisation, Einsatzname oder Umgebung ergänzt werden. Der nach Migration
115 gespeicherte Berechtigungsmodus muss ebenfalls bytegleich erhalten
bleiben: Ein `STRICT`-Einsatz darf durch Restore nicht gelockert und ein
bewusst auditiertes `LOOSE` nicht still als streng ausgegeben werden.

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

Die `nv_einsaetze`-CSV führt `fuehrungsstellenname`, dessen dauerhaften
Sperrmarker und `estab_permission_mode` getrennt von `name`, `organisation`
und `einsatzleitung`. Ein
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
