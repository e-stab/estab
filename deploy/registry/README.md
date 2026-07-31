# Pull-only-Deployment

Dieses Paket startet eStab aus bereits gebauten OCI-Images. Auf dem Zielgerät
werden weder PHP-Quellen noch Datenbankskripte gebaut. Der mitgelieferte
One-shot-Migrator enthält das Basisschema, initialisiert ausschließlich eine
leere eStab-Datenbank, führt danach die versionierten Migrationen aus und
blockiert den App-Start bei einer negativen Schema-Prüfung.

## Wichtiger Veröffentlichungsstatus

Die technischen Publish- und Multi-Arch-Dateien sind vorbereitet. Dieses
Repository dokumentiert aber noch keinen freigegebenen Image-Stand mit beiden
Manifest-Digests. Vor einer Installation muss deshalb anhand der
GitHub-Releaseinformationen und GHCR-Pakete geprüft werden, ob ein vollständiges
Imagepaar tatsächlich veröffentlicht und freigegeben wurde. GHCR legt erstmals
veröffentlichte Pakete standardmäßig privat an. Eine öffentliche Sichtbarkeit
darf erst nach der im Root-README beschriebenen Rechte- und Lizenzklärung für
den historischen Quell-, Schrift-, Grafik- und Dokumentationsbestand gesetzt
werden. Bis dahin benötigt jedes Zielgerät eine eigene GHCR-Anmeldung mit
minimalem Leserecht.

Der manuelle Workflow bleibt zusätzlich gesperrt, bis

- `ESTAB_CONTAINER_PUBLISH_ENABLED=true` und
  `ESTAB_CONTAINER_PUBLISH_REVIEWER_CONFIGURED=true` als
  Repositoryvariablen gesetzt sind,
- die Rechteprüfung im Workflowformular bestätigt ist,
- der Workflow ausdrücklich vom bereits vorhandenen Git-Tag gestartet wird,
  der exakt dem gewünschten OCI-Tag entspricht, und
- das GitHub-Environment `container-publish` tatsächlich einen
  `Required Reviewer` besitzt.

Diese Schalter ersetzen keine tatsächliche Rechteklärung. Der Workflow ist
global serialisiert, überschreibt keine vorhandenen Tags und unterstützt
bewusst kein `latest`. GitHub Actions, das privilegierte binfmt-Hilfsimage,
BuildKit sowie PHP-/MariaDB-Basen sind auf Commit- beziehungsweise
Multi-Arch-Digests festgelegt; ihre Aktualisierung ist ein eigener geprüfter
Änderungsschritt.

Der Publish-Pfad trennt Build und Freigabe bewusst. App und Migrator werden
jeweils genau einmal als Multi-Arch-Index unter
`candidate-<workflow-run>-<versuch>` gebaut und gepusht. Native Runner für
`linux/amd64` und `linux/arm64` ziehen anschließend genau diese
Index-Digests, binden sie kryptographisch an Plattformmanifest, Config und
lokale Runtime-Image-ID und führen das vollständige Fresh-/Restore-Gate aus.
SPDX-SBOM, SLSA-Provenance, die in GHCR gespeicherte Attestation und Trivy
werden ebenfalls gegen diese Digests geprüft; Chrome ist auf `amd64`
verpflichtend. Jeder grüne Architekturlauf hinterlegt ein
prüfsummengebundenes Evidence-Artefakt, Fehlerläufe behalten ihre separate
Diagnostik.

Erst nach beiden grünen Architekturen erstellt ein nachgelagerter Job ein
verstecktes Draft-Release, lädt Archiv und äußere SHA-256-Datei hoch, lädt beide
Dateien wieder herunter und prüft äußere sowie innere Checksummen. Danach
werden die bereits getesteten App-/Migrator-Index-Digests ohne Dockerfile-
Rebuild mit `imagetools` auf die Finaltags promotet und erneut verglichen. Die
Draft-Release wird erst nach einem letzten Asset-, Digest- und
Attestationscheck sichtbar.

## Installation mit Docker oder Podman

Das vom Workflow veröffentlichte Archiv wird vor dem Entpacken und danach
nochmals dateiweise geprüft. `RELEASE` zeigt anschließend den exakt gebundenen
Tag, Commit und beide Image-Digests:

```console
bundle=estab-RELEASE
sha256sum --check "$bundle.tar.gz.sha256"
tar -xzf "$bundle.tar.gz"
cd "$bundle"
sha256sum --check SHA256SUMS
```

`RELEASE` wird dabei durch den veröffentlichten Releasenamen ersetzt. Auf
macOS wird jeweils `shasum -a 256 --check` statt `sha256sum --check`
verwendet. Bei einer abweichenden Prüfsumme darf das Paket nicht gestartet
werden.

Anschließend im geprüften Paketverzeichnis:

```console
cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

Nur die Quellvorlage dieses Repositorys enthält leere Image-Werte, damit ein
ungebundener Checkout geschlossen fehlschlägt. Das veröffentlichte
Releasepaket bindet `.env.example` und `RELEASE` immer an genau dieses
kanonische Paar:

- `ghcr.io/e-stab/estab@sha256:<64 Kleinhexzeichen>`
- `ghcr.io/e-stab/estab-migrate@sha256:<64 Kleinhexzeichen>`

Tags, `latest`, andere Registries und manuell übertragene Digests sind im
Releasepaket unzulässig. Nach `cp .env.example .env` dürfen ausschließlich die
Nicht-Image-Einstellungen wie Port, Speicherquellen oder Proxykonfiguration
angepasst werden. Die Zeilen `ESTAB_APP_IMAGE` und `ESTAB_MIGRATE_IMAGE`
bleiben bytegenau unverändert; `verify-release.sh` vergleicht sie mit der
prüfsummengebundenen Vorlage und `RELEASE`. `deploy.sh` weist
Prozessvariablen für sämtliche von Compose verwendeten Laufzeiteinstellungen
sowie Umgehungen über `COMPOSE_FILE`,
`COMPOSE_ENV_FILES`, `COMPOSE_DISABLE_ENV_FILE` oder
`COMPOSE_PROJECT_NAME` zurück; die geprüfte `.env` ist die einzige
Konfigurationsquelle für Image-, Projekt-, Speicher- und Laufzeitidentität.
Automatisch geladene
Dateien wie `compose.override.yaml` oder `docker-compose.yml` sind im
Releaseordner ebenfalls verboten; der Helfer übergibt `.env` und
`compose.yaml` ausdrücklich an Compose.

`LICENSE` und `THIRD_PARTY_NOTICES.md` gehören ebenso zum gebundenen Paket.
Fehlt eine dieser Dateien oder eine andere in `SHA256SUMS` genannte Datei,
darf das Release nicht installiert werden.

### Nur bei privaten GHCR-Images: Anmeldung

Dieser Abschnitt ist ausschließlich nötig, solange die beiden GHCR-Images
privat sind. Öffentliche Images können ohne Registry-Anmeldung gezogen werden.
Für private Images braucht das verwendete GitHub-Konto Leserechte auf beide
Pakete und einen [Personal Access Token (classic) mit ausschließlich
`read:packages`](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry#authenticating-with-a-personal-access-token-classic).
`repo`, `write:packages` und `delete:packages` sind für ein Zielgerät nicht
erforderlich. Eine Organisation mit SAML-SSO muss den Token zusätzlich für
diese Organisation autorisieren. Ablaufdatum und Kontozugriff so eng wie
betrieblich möglich wählen.

Den Token in einer interaktiven Shell aus einem Passwortmanager in eine nicht
exportierte Shellvariable einlesen und per Standard-Eingabe übergeben; den
Tokenwert nicht als Kommandoargument eingeben:

```console
GHCR_USER='github-benutzername'
printf 'GHCR-Token: ' >&2
stty -echo
trap 'stty echo' 0 1 2 15
IFS= read -r GHCR_TOKEN
stty echo
trap - 0 1 2 15
printf '\n' >&2
printf '%s' "$GHCR_TOKEN" |
    docker login ghcr.io --username "$GHCR_USER" --password-stdin
unset GHCR_TOKEN
```

Mit Podman lautet der letzte Befehl entsprechend `podman login`. Der Token
gehört weder in `.env`, `compose.yaml`, Projektdateien, Logs noch
Shell-History. `docker login` beziehungsweise `podman login` speichert
Anmeldedaten im Credential Store oder in der Konfiguration des ausführenden
Betriebssystemkontos; auch diese Datei ist wie ein Secret zu schützen. Nach
einem erfolgreichen `deploy.sh pull` kann die gespeicherte Anmeldung mit
`docker logout ghcr.io` entfernt werden. Token bei Verlust sofort widerrufen
und ansonsten regelmäßig rotieren.

Danach:

```console
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
```

Mit Podman wird in allen drei Zeilen ausschließlich `docker` durch `podman`
ersetzt. `check` prüft `SHA256SUMS`, die vier Identitätszeilen in `RELEASE`,
das kanonische Digestpaar, die unveränderten Imagewerte in `.env.example` und
`.env`, die sichere Trennung der drei produktiven Speicherquellen sowie die
effektive Compose-Konfiguration. `pull` wiederholt dieses Gate, zieht die
beiden gebundenen Anwendungsimages und die digestgebundene MariaDB-Basis und
verlangt danach für App und Migrator passende OCI-Labels für Git-Tag und
Git-Commit. `up` führt dieselben Prüfungen aus, startet den Stack und wartet
höchstens 300 Sekunden auf `healthy` für `db` und `app` sowie Exitcode 0 von
`migrate` und `admin-auth-init`.
`ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS` kann diese Grenze auf 1 bis 3600
Sekunden setzen.
Erst die Ausgabe `eStab deployment: ready` bestätigt, dass beide One-shots
Exitcode 0 und beide langlebigen Dienste den Status `healthy` erreicht haben.

Nur zur Diagnose nach einem fehlgeschlagenen `deploy.sh up`, nicht als
alternativer Startweg:

```console
container_cli=docker
"$container_cli" compose ps --all
"$container_cli" compose logs --tail 200 db migrate admin-auth-init app
```

Standardmäßig sind `estab_db`, `estab_data`, `estab_export` und das nur für
den abgeleiteten Admin-Hash verwendete `estab_auth` benannte Volumes.
`COMPOSE_PROJECT_NAME=estab` und der gleichlautende Compose-Standard sind
absichtlich versionsunabhängig: Auch wenn ein neues Releasepaket in ein
anderes Verzeichnis entpackt wird, bleiben dadurch die Volume-Namen
`estab_estab_db`, `estab_estab_data`, `estab_estab_export` und
`estab_estab_auth` stabil. Diesen Wert nach der Erstinstallation nicht ändern.
`deploy.sh check` prüft die konfigurierte Projekt- und Speicheridentität,
inspiziert aber keinen laufenden Datenmount. Vor einem Upgrade müssen die in
`.env` konfigurierten Quellen zusätzlich mit Container-Inspect gegen die
Live-Mounts geprüft werden; das anschließende Vollbackup bindet genau diese
Quellen.

Alternativ akzeptieren `ESTAB_DB_DATA_SOURCE`,
`ESTAB_APP_DATA_SOURCE` und `ESTAB_EXPORT_DATA_SOURCE` absolute oder relative
Hostverzeichnisse. Sie müssen vor `deploy.sh check` als echte Verzeichnisse
angelegt sein, dürfen keine symbolischen Links sein und müssen getrennt
voneinander liegen. Der Helfer verweigert `/`, breite Verzeichnisse direkt
unterhalb der Dateisystemwurzel, den Releaseordner und dessen Vorfahren sowie
gleiche oder ineinander verschachtelte Quellen. Für benannte Volumes ist je
Rolle ausschließlich `estab_db`, `estab_data` beziehungsweise `estab_export`
zulässig; dadurch kann insbesondere das nur für den bcrypt-Hash bestimmte
`estab_auth` nicht als beschreibbarer Datenspeicher wiederverwendet werden.
Die Prüfung erfolgt vor `pull` und unter der engine-weiten Wartungssperre
erneut vor `up`.

Für das dokumentierte Bind-Mount-Layout:

```console
mkdir -p data data/db data/4fdata data/export
chmod 0700 data data/db data/4fdata data/export
```

Diese Daten sind produktiv. `compose down --volumes`, „Clean“ beziehungsweise
das Löschen eines Container-Manager-Projekts darf nur nach einem geprüften
Vollbackup erfolgen.

Die formale Ausgangssichtung ist auch im Pull-only-/Synology-Paket
verbindlich: Verfasser → Si → LdF → A/W. Sie ist nicht konfigurierbar. Fehlt
eine aktive Sichterbesetzung, bleibt der Ausgang in der Warteschlange und wird
nicht automatisch oder durch A/W freigegeben.

`ESTAB_PDF_ATTACHMENT_MAX_BYTES` begrenzt die Gesamtsumme der unverändert in
ein PDF-Einsatzdossier eingebetteten Originalanhänge. Der Standard sind
`52428800` Byte (50 MiB), erlaubt sind `0` bis `104857600` Byte. Der Wert
wirkt pro erzeugter PDF und muss zur verfügbaren Speicherausstattung des NAS
passen; bei Überschreitung bricht der Export sichtbar ab und lässt keine Datei
stillschweigend weg. Nach einer Änderung ist der App-Container neu zu
erzeugen. Einzelne Uploads bleiben zusätzlich durch
`ESTAB_UPLOAD_MAX_BYTES`, PHP `upload_max_filesize` und `post_max_size`
begrenzt. Der Auslieferungsstandard für `ESTAB_UPLOAD_MAX_BYTES` beträgt
`20971520` Byte (20 MiB). Der Anhangdialog unterstützt sowohl `.jpg` als auch
`.jpeg` und zeigt das wirksame Limit an; nur Endung und serverseitig erkannter
MIME-Typ gemeinsam autorisieren die Datei.

Eine frische Datenbank besitzt absichtlich keinen aktiven Einsatz. Nach dem
ersten gesunden Start muss der technische Administrator unter
`/4fadm/incidents.php` einen Einsatz anlegen und aktivieren; bis dahin bleiben
alle operativen Eingaben gesperrt. Unter `/4fadm/users.php` legt er
Funktionskonten mit fester Funktion an, weist Funktionen neu zu, sperrt oder
entsperrt Konten und setzt deren Kennwort zurück. Diese Konten sind unabhängig
vom Basic-Auth-Konto aus `ESTAB_ADMIN_USER` und `admin_password.txt`.
Selbstregistrierung ist standardmäßig deaktiviert; die öffentliche
Kompatibilitätsregistrierung darf nur bewusst mit
`ESTAB_ALLOW_SELF_REGISTRATION=true` aktiviert werden.

Der Hostverzeichnis-Pfad ist Bestandteil des automatisierten Release-Gates:
Ein zusätzliches Pull-only-Projekt startet mit drei echten temporären
Bind-Mounts. Der Test prüft die effektiven Mount-Typen und -Quellen, erzeugt
einen Datenbank- und zwei Dateimarker, erstellt ein prüfsummengebundenes
Vollbackup, verfälscht den Datenbankmarker logisch und ergänzt kontrollierte
veraltete Testdaten in den beiden Dateibereichen. Der Restore stellt beides
wieder her;
der rohe MariaDB-Bind-Mount wird in diesem Test nicht geleert. Migrator,
`/health.php`, Markerinhalt und Marker-SHA-256 müssen danach unverändert sein.
Erst nach vollständiger Entfernung von Containern, Volumes, Netzwerken und
temporärem Hostpfad wird der Lauf grün. Das belegt die technische
Backup-/Restore-Mechanik des NAS-Layouts; die regelmäßige Restore-Probe des
echten Betriebsbestands bleibt dennoch Pflicht.

## Synology Container Manager

Vorausgesetzt werden ein Synology-Modell mit Container Manager und eine
CPU-Architektur, für die das Release-Manifest ein Image enthält. Die
Publish-Pipeline baut `linux/amd64` und `linux/arm64`; ältere 32-Bit-NAS sind
nicht abgedeckt. Das vollständige Container-/Migrations-/Restore-Gate läuft im
normalen CI- und im Publish-Workflow nativ auf beiden Zielarchitekturen. Der
echte Browserlauf ist in GitHub Actions auf `amd64` verpflichtend; die
Bedienabnahme auf dem tatsächlichen NAS-Endgerät bleibt zusätzlich nötig.

1. Das äußerlich geprüfte Releasearchiv vollständig in einen geschützten
   Projektordner wie `/volume1/docker/estab` entpacken. Keine Einzelauswahl
   aus `compose.yaml` und Skripten treffen: `RELEASE`, `SHA256SUMS`,
   `.env.example`, `deploy.sh`, `verify-release.sh`, die Backup-/Restore-
   Helfer sowie Lizenz- und Hinweisdateien bilden gemeinsam die
   Vertrauensgrenze. `.env.example` nach `.env` kopieren; die beiden
   Imagezeilen nicht verändern. Zusätzlich `data/db`, `data/4fdata`,
   `data/export`, `backups` und `secrets` anlegen. In `.env` nur die drei
   `ESTAB_*_DATA_SOURCE`-Werte auf diese relativen `./data/...`-Pfade setzen.
   Secret-Dateien nur für den administrativen NAS-Benutzer lesbar machen.
2. Für LAN-Zugriff `ESTAB_HTTP_BIND=0.0.0.0` setzen und den gewählten
   `ESTAB_HTTP_PORT` in DSM-Firewall und Reverse Proxy ausschließlich für die
   vorgesehenen Netze freigeben. eStab nicht ungeprüft direkt aus dem Internet
   erreichbar machen. Falls Forwarded-Header ausgewertet werden sollen,
   `ESTAB_TRUST_PROXY_HEADERS=true` und
   `ESTAB_TRUSTED_PROXIES` auf die direkte Proxy-IP beziehungsweise ein enges
   Proxy-CIDR setzen; eine fehlende Allowlist sperrt diese Konfiguration.
3. **Nur für private GHCR-Images:** Da Synologys
   [offizielle Registry-Hilfe](https://kb.synology.com/de-de/DSM/help/ContainerManager/docker_registry?version=7)
   das Hinzufügen einer Registry-URL, aber keinen GHCR-PAT-Ablauf dokumentiert,
   wird hier kein nicht belegtes GUI-Credential-Feld vorausgesetzt. Stattdessen
   einmal per administrativer SSH-Sitzung am NAS anmelden und die oben
   beschriebene verdeckte Token-Eingabe verwenden:

   ```console
   GHCR_USER='github-benutzername'
   printf 'GHCR-Token: ' >&2
   stty -echo
   trap 'stty echo' 0 1 2 15
   IFS= read -r GHCR_TOKEN
   stty echo
   trap - 0 1 2 15
   printf '\n' >&2
   sudo -v
   printf '%s' "$GHCR_TOKEN" |
       sudo docker login ghcr.io --username "$GHCR_USER" --password-stdin
   unset GHCR_TOKEN
   ```

   Den PAT niemals in den Projekteditor oder die `.env` kopieren.
4. Im vollständig entpackten Projektordner ausschließlich den gebundenen
   Deployment-Helfer mit Docker ausführen:

   ```console
   sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
   sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
   sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
   ```

   Dadurch werden vor dem Pull und Start Paket, Digestpaar und
   Compose-Konfiguration sowie nach dem Pull die OCI-Identität geprüft. Ein
   lokaler Build findet nicht statt. Nach einem privaten Pull kann die
   Registry-Anmeldung mit `sudo docker logout ghcr.io` entfernt werden.
5. Container Manager dient anschließend für Status- und Logeinsicht. `db` und
   `app` müssen gesund sein; `migrate` und `admin-auth-init` müssen mit
   Exitcode 0 beendet sein. Danach `/health.php` und die Administration
   prüfen, den ersten Einsatz anlegen und aktivieren, benötigte Funktionskonten
   in der Benutzerverwaltung anlegen und deren feste Zuordnung kontrollieren.
   Anschließend eine Dienstschicht planen, mindestens S2, Si, S6, LdF und A/W
   persönlichen Konten zuweisen, jede Zuweisung durch die betreffende Person
   annehmen lassen und die Schicht erst danach administrativ aktivieren. Vor
   der fachlichen Abnahme wählt jede Person ihren angenommenen aktiven
   Funktions-Hut aus.

Synologys
[Projektassistent](https://kb.synology.com/de-de/DSM/help/ContainerManager/docker_project?version=7)
kann Compose-Dateien importieren, ersetzt aber weder
`verify-release.sh` noch die Digest-/OCI-Prüfungen von `deploy.sh`. Der
unterstützte Erststart und jedes Upgrade laufen deshalb über den Helfer; die
GUI darf die gebundenen Paketdateien und Imagezeilen nicht umschreiben. Der
optionale Web-Station-Portalweg ersetzt nicht die TLS-, Proxy- und
Firewall-Prüfung.

## Backup und Wiederherstellung auf dem NAS

Das Releasepaket enthält `backup.sh`, den rein lesenden `verify-backup.sh` und
den destruktiv guardierten `restore.sh`. Im Projektordner wird ein Vollbackup
mit einem neuen, ausdrücklich absoluten Ziel gestartet:

```console
mkdir -p "$(pwd -P)/backups"
chmod 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
ESTAB_CONTAINER_CLI=docker sh ./backup.sh "$backup_target"
```

Der Helfer verlangt einen eindeutig zugeordneten Stack mit erfolgreichem
Migrator und nachweislich gesunden `app`- und `db`-Containern. Er stoppt nur
die App, erzeugt einen transaktionalen MariaDB-Dump sowie Archive von
`4fdata` und Exportdaten, startet die App wieder und wartet mit begrenzter
Laufzeit auf ihren echten `healthy`-Status. Auch der Fehlerpfad gibt einen
Neustart erst nach dieser Health-Prüfung als erfolgreich frei.

Der atomar erworbene engine-weite Containername
`estab-maintenance-lock-<COMPOSE_PROJECT_NAME>` schließt Backup, Restore und
`deploy.sh up` auch über verschiedene Releaseordner hinweg gegenseitig aus.
Der Lock läuft netzlos mit dem verifizierten App-Image und ohne
Restart-Policy; nach einem Host-/Engine-Absturz bleibt sein Name fail-closed
als Diagnoseobjekt bestehen.
`backups/.estab-backup.lock` schützt zusätzlich die Veröffentlichung gegen
parallele Backup-Prozesse im selben Elternverzeichnis. Projekt, Datenbank,
effektive Speicherquellen und alle drei Runtime-Image-Digests werden in
`SHA256SUMS` gebunden. Der fertige Zielordner erscheint erst nach interner
Verifikation durch die portable Umbenennung des privaten
Geschwister-Stagings; dafür genügt das normale `mv SOURCE TARGET` von
GNU-, BSD- und BusyBox-Systemen. Unter dem Lock wird unmittelbar vor dem
Rename erneut bewiesen, dass das Ziel nicht existiert. Ein vorhandenes Ziel
wird nie überschrieben, danach wird der veröffentlichte Satz noch einmal
vollständig verifiziert.
Docker-IDs mit `sha256:` und Podmans nackte 64-stellige Kleinhex-IDs werden
dabei im Metadatensatz kanonisch als `sha256:<64 Kleinhexzeichen>` gespeichert;
jedes andere Runtime-ID-Format beendet den Lauf vor dem App-Stopp.

Bei einem Fehler oder Signal werden Staging und nur ein über exakte ID,
Labels, Image und Status als eigener Lock bewiesener Container entfernt.
Ein fremder oder nach Hostabsturz gestoppter Lock bleibt bestehen; die
Diagnose nennt ID, Projekt, Operation, Eigentümerkennung, Startzeit und
Status. Erst nach dem Beweis, dass keine Wartungsoperation mehr läuft, darf
exakt diese ID mit `docker container rm --force LOCK_ID` beziehungsweise dem
Podman-Pendant entfernt werden. `ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS` begrenzt Health-Waits auf
standardmäßig 240 Sekunden und akzeptiert Werte von 1 bis 3600.

Der Zugriff über Containerpfade funktioniert unverändert mit den drei
`./data/...`-Bind-Mounts und mit benannten Volumes. Das laufende
MariaDB-Verzeichnis `data/db` darf nicht als Ersatz für den logischen Dump
kopiert werden. Details, Format-2-Metadaten, Legacy-Verifikation und die
kontrollierte Wiederherstellung stehen in
[`docs/BACKUP-UND-WIEDERHERSTELLUNG.md`](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md).

Für den Restore wird das vollständig geprüfte Releasepaket verwendet, dessen
gebundene Image-Referenzen exakt den Sicherungsmetadaten entsprechen. Die
Imagezeilen in `.env` werden auch dafür nicht manuell geändert. Dieser
Pull-only-Weg verwendet keinen lokalen Build. Der Restore akzeptiert nur das
vollständig gebundene Format 2 und verlangt den exakten Compose-Projektnamen
als zweite, ausdrückliche Bestätigung:

```console
restore_dir="$(pwd -P)/backups/20260723-120000"
confirmed_project=estab
ESTAB_CONTAINER_CLI=docker \
  sh ./restore.sh \
    --confirm-project "$confirmed_project" \
    "$restore_dir"
```

Vor jedem Überschreiben vergleicht der Helfer Backup, Datenbank, Projekt,
Mounts und die drei Runtime-Images. Zusätzliche Mountgrenzen unter oder über
den produktiven Zielen sowie gleiche oder verschachtelte Host-/Volume-Quellen
werden abgewiesen. Backupziel beziehungsweise Restore-Quellverzeichnis dürfen
ebenfalls weder in einer produktiven Quelle liegen noch diese umschließen.
Beide Dateimounts müssen ausdrücklich Read/Write sein. Nach dem App-Stopp
beweist ein netzloser Hilfscontainer mit exakt dem geprüften App-Image durch
privates Anlegen, Lesen und Entfernen je einer Probe ihre effektive
Schreibbarkeit. Auch `admin-auth-init` muss schon vor dem Datenbankstart
Exitcode 0 und das geprüfte App-Image besitzen. Erst danach importiert der Helfer die
Datenbank, leert ausschließlich die zwei fest geprüften Container-Mounts,
spielt beide Archive ein, verlangt einen erfolgreichen Migrator und startet
die App erst nach bestandenem Health-Gate. `admin-auth-init` muss ebenfalls
mit Exitcode 0 beendet sein und das geprüfte App-Image verwenden. Nach einem
Fehler ab Beginn des Imports bleibt die App mit einer klaren Meldung
`RECOVERY REQUIRED` gestoppt. Der globale Lock bleibt dann absichtlich
bestehen. Nach Ursachenbehebung muss bewiesen werden, dass kein Restore mehr
läuft, exakt die gemeldete Lock-ID entfernt und derselbe verifizierte Restore
erneut ausgeführt werden.
Es gibt kein rekursives Löschen eines aus einer Hostvariable übernommenen
Pfads.

## Kontrolliertes Upgrade

Vor jedem Imagewechsel wird ein Vollbackup nach
[`docs/BACKUP-UND-WIEDERHERSTELLUNG.md`](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
erstellt und testweise wiederhergestellt. Das neue Archiv und seine äußere
Prüfsumme werden unabhängig geprüft und vollständig in ein neues
Versionsverzeichnis entpackt. Von der neuen, digestgebundenen `.env.example`
wird eine neue `.env` erzeugt; ausschließlich die bisherigen
Nicht-Image-Einstellungen werden übernommen. `COMPOSE_PROJECT_NAME`, Secrets
und die drei `ESTAB_*_DATA_SOURCE`-Werte müssen exakt beim bisherigen Stand
bleiben. Die beiden Imagezeilen stammen unverändert aus dem neuen Paket.

```console
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
```

Das Vollbackup muss dieselben effektiven Live-Speicherquellen binden;
`deploy.sh check` allein inspiziert sie nicht. `up` erwirbt denselben
projektweiten Lock wie Backup und Restore. Migrator und
`admin-auth-init` müssen mit Exitcode 0 enden. Ein fehlgeschlagener Lauf wird
nicht umgangen; zuerst bleiben Datenbank und Logs unverändert zu untersuchen.

## Unvollständigen Publish-Lauf behandeln

Die Promotion zweier GHCR-Repositories und die Veröffentlichung eines
GitHub-Releases sind technisch nicht atomar. Der Workflow verhindert deshalb
Finaltags und Release-Artefakte vollständig, solange ein nativer
Candidate-Test fehlschlägt; er kann aber einen Fehler zwischen den späteren
Einzeloperationen nicht ungeschehen machen. In jedem Fall gilt:

- Ein `candidate-*`-Tag ist ausschließlich nicht-finale Build- und
  Testevidenz. Verbleibende Candidate-Tags dürfen auf keinen Fall in eine
  Deployment-`.env` übernommen werden.
- Ein verstecktes Draft-Release ist nicht freigegeben. Dasselbe gilt für einen
  einzelnen oder nur teilweise promoteten Finaltag.
- Installierbar ist erst das Digestpaar aus einem sichtbaren GitHub-Release,
  deren Archiv und SHA-256-Datei vollständig vorhanden und geprüft sind.

Je nach Abbruchzeitpunkt ist unterschiedlich vorzugehen:

1. **Candidate- oder Plattform-Gate fehlgeschlagen:** Es entstehen keine
   Finaltags und kein Draft-Release. Die Candidate-Tags dürfen als
   nicht-finale Evidence verbleiben. Workflowlog, Architektur-Evidence und
   Digests sichern, Ursache beheben und nur den kontrollierten Workflow erneut
   ausführen.
2. **Draft/Asset-Prüfung vor Promotion fehlgeschlagen:** Der Workflow versucht,
   ausschließlich das von diesem Lauf erzeugte versteckte Draft-Release samt
   Assets wieder zu löschen. Schlägt diese Best-effort-Bereinigung fehl, das
   Draft-Release nicht veröffentlichen, sondern Kennung, Assets und Logs durch
   zwei Personen prüfen und das unvollständige Draft-Release kontrolliert
   entfernen. Finaltags dürfen zu diesem Zeitpunkt nicht existieren.
3. **Fehler ab Beginn der Promotion:** Der Workflow löscht absichtlich nichts
   automatisch. Ein oder beide Finaltags können bereits vorhanden sein; das
   versteckte Draft-Release bleibt als Recovery-Anker erhalten. Beide
   GHCR-Tags gegen die in Workflow-Evidence und Draft-Paket protokollierten
   Index-Digests vergleichen und beide heruntergeladenen Release-Assets
   vollständig prüfen.
   Nur wenn Digestpaar, Git-Commit, Attestationen und Checksummen exakt
   übereinstimmen, darf ein Required Reviewer das vorhandene Draft-Release
   veröffentlichen. Bei einem fehlenden oder abweichenden Teilstand müssen
   Finaltags und Draft-Release nach dokumentierter Vier-Augen-Freigabe bereinigt
   werden, bevor ein neuer Lauf beginnt.
4. **Veröffentlichungsaufruf unklar beendet:** Wenn `draft=false` bereits
   angenommen wurde, kann das Release sichtbar sein, obwohl die abschließende
   Statusabfrage fehlschlug. Vor jedem Retry zuerst Sichtbarkeit, beide Assets
   und beide Finaltag-Digests prüfen; niemals von einem roten Workflowstatus
   allein auf einen unveröffentlichten Stand schließen.

Vorhandene Finaltags oder ein vorhandenes Release werden nie still
überschrieben. Ein automatischer Retry nach begonnener Promotion ist deshalb
bewusst gesperrt, bis der Zwischenstand kontrolliert bewertet wurde.
