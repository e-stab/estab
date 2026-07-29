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
install -d -m 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

Die beiden Image-Werte in der Repository-Vorlage `.env.example` sind
absichtlich leer: `compose config` muss scheitern, bis eine ausdrückliche
Auswahl getroffen wurde. In `.env` werden `ESTAB_APP_IMAGE` und
`ESTAB_MIGRATE_IMAGE` auf denselben vollständig veröffentlichten Release-Stand
gesetzt. Bevorzugt werden die beiden von GHCR gemeldeten Manifest-Digests, zum
Beispiel `ghcr.io/e-stab/estab@sha256:…`. Ein Tag allein reicht nur, wenn er
laut Releaseprotokoll unveränderlich ist und beide zugehörigen Digests
dokumentiert sind.

Bei einem durch den Publish-Workflow erzeugten GitHub-Releasepaket sind beide
Werte in der enthaltenen `.env.example` bereits auf die nach Manifest-,
Attestations-, SBOM-/Provenance- und CVE-Prüfung ermittelten Digests gebunden.
Die daneben veröffentlichte SHA-256-Datei wird vor dem Entpacken geprüft.
`RELEASE` im Paket hält Tag, Commit und beide vollständigen Imagereferenzen
zusätzlich menschenlesbar fest. Eigenhändiges Heraussuchen oder Übertragen der
Digests entfällt damit.

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
einem einmaligen manuellen Pull kann die gespeicherte Anmeldung mit
`docker logout ghcr.io` entfernt werden. Token bei Verlust sofort widerrufen
und ansonsten regelmäßig rotieren.

Danach:

```console
engine=docker
compose() { "$engine" compose "$@"; }

compose config >/dev/null
compose pull
compose up -d

estab_diagnostics()
{
    printf 'eStab ist nicht bereit: %s\n' "$1" >&2
    compose ps --all >&2 || true
    compose logs --tail 100 db migrate app >&2 || true
}

wait_for_estab()
{
    estab_deadline=$(( $(date +%s) + 300 ))
    while :; do
        if estab_health=$(curl --fail --silent --max-time 5 \
            http://127.0.0.1:8080/health.php 2>/dev/null) &&
            printf '%s\n' "$estab_health" |
                grep -Fq '"status":"ready"'; then
            printf '%s\n' "$estab_health"
            return 0
        fi

        for estab_service in db app; do
            estab_id=$(compose ps --all -q "$estab_service" 2>/dev/null ||
                true)
            [ -n "$estab_id" ] || continue
            estab_state=$("$engine" inspect --format \
                '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' \
                "$estab_id" 2>/dev/null || true)
            case "$estab_state" in
                exited\ * | dead\ * | *\ unhealthy)
                    estab_diagnostics \
                        "Service $estab_service ist $estab_state"
                    return 1
                    ;;
            esac
        done

        estab_id=$(compose ps --all -q migrate 2>/dev/null || true)
        if [ -n "$estab_id" ]; then
            estab_state=$("$engine" inspect --format \
                '{{.State.Status}} {{.State.ExitCode}}' "$estab_id" \
                2>/dev/null || true)
            case "$estab_state" in
                exited\ 0) ;;
                exited\ * | dead\ *)
                    estab_diagnostics \
                        "Service migrate endete als $estab_state"
                    return 1
                    ;;
            esac
        fi

        if [ "$(date +%s)" -ge "$estab_deadline" ]; then
            estab_diagnostics 'Zeitlimit von 300 Sekunden erreicht'
            return 1
        fi
        sleep 3
    done
}

wait_for_estab
```

Die Schleife wartet höchstens fünf Minuten, beendet sich bei einem klar
fehlgeschlagenen Datenbank-, Migrator- oder App-Service vorzeitig und zeigt
Status sowie die letzten Logs. Sie benötigt kein providerabhängiges
Compose-`--wait`. Mit Podman genügt `engine=podman`.

Standardmäßig sind `estab_db`, `estab_data` und `estab_export` benannte
Volumes. `COMPOSE_PROJECT_NAME=estab` und der gleichlautende Compose-Standard
sind absichtlich versionsunabhängig: Auch wenn ein neues Releasepaket in ein
anderes Verzeichnis entpackt wird, bleiben dadurch die Volume-Namen
`estab_estab_db`, `estab_estab_data` und `estab_estab_export` stabil. Diesen
Wert nach der Erstinstallation nicht ändern. Vor jedem Upgrade mit
`docker compose config` kontrollieren, dass dieselben Volume-Namen und
Speicherquellen wie im laufenden Projekt erscheinen.

Alternativ akzeptieren `ESTAB_DB_DATA_SOURCE`,
`ESTAB_APP_DATA_SOURCE` und `ESTAB_EXPORT_DATA_SOURCE` absolute oder relative
Hostverzeichnisse. Diese Daten sind produktiv. `compose down --volumes`,
„Clean“ beziehungsweise das Löschen eines Container-Manager-Projekts darf nur
nach einem geprüften Vollbackup erfolgen.

`ESTAB_REVIEW_OUTGOING_MESSAGES=false` erhält auch im Pull-only-/Synology-Paket
den kompatiblen Direktabschluss transportierter Ausgänge. Mit `true` bleiben
sie nach A/W-Transport offen, erscheinen beim Sichter und werden erst dort
abgeschlossen. Der Wert wird strikt geparst; nach jeder Änderung ist nur der
App-Container neu zu erzeugen und anschließend der vollständige Rollenpfad zu
prüfen.

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
Vollbackup, leert ausschließlich seinen per Projektkennung und Guard-Datei
abgesicherten temporären Speicher und stellt ihn wieder her. Migrator,
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

1. Einen geschützten Projektordner wie
   `/volume1/docker/estab` anlegen und `compose.yaml`, eine aus
   `.env.example` erzeugte `.env` sowie die drei Secret-Dateien im Unterordner
   `secrets` dort ablegen. Zusätzlich `data/db`, `data/4fdata`,
   `data/export` und `backups` anlegen. In `.env` die drei
   `ESTAB_*_DATA_SOURCE`-Werte auf diese relativen `./data/...`-Pfade setzen.
   Dadurch liegen die Nutzdaten sichtbar im Projektordner und werden nicht
   versehentlich als versteckte projektverwaltete Volumes behandelt.
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
   einmal per administrativer SSH-Sitzung am NAS anmelden, die oben beschriebene
   verdeckte Token-Eingabe verwenden und die exakt im geprüften `RELEASE`
   angegebenen Digest-Referenzen vorab ziehen:

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
   sudo docker pull 'ghcr.io/e-stab/estab@sha256:APP-DIGEST-AUS-RELEASE'
   sudo docker pull 'ghcr.io/e-stab/estab-migrate@sha256:MIGRATOR-DIGEST-AUS-RELEASE'
   sudo docker logout ghcr.io
   unset GHCR_TOKEN
   ```

   Die beiden Platzhalter müssen exakt durch die vollständigen Referenzen aus
   `RELEASE` ersetzt werden. Bei jedem Upgrade zuerst die neuen Digests so
   vorab ziehen. Den PAT niemals in den Projekteditor oder die `.env` kopieren.
4. In Container Manager unter „Project/Projekt“ ein neues Projekt erstellen,
   als unveränderten Projektnamen `estab` verwenden, den Projektordner als Pfad
   wählen und die vorhandene `compose.yaml` als Quelle laden. Diese Felder und
   der Upload beziehungsweise Editor für die Compose-Datei entsprechen
   Synologys
   [dokumentiertem Projektassistenten](https://kb.synology.com/de-de/DSM/help/ContainerManager/docker_project?version=7).
   Es ist kein lokaler Image-Build nötig. Bei späteren Paketwechseln kein
   zweites Projekt mit neuem Namen anlegen, sondern das bestehende Projekt nach
   Vollbackup und Kontrolle der effektiven Speicherquellen aktualisieren.
5. Nach dem Start in den Projektdetails kontrollieren, dass `db` und `app`
   gesund sind und `migrate` erfolgreich mit Exitcode 0 beendet ist. Danach
   `/health.php` und die Administration prüfen, den ersten Einsatz anlegen und
   aktivieren, benötigte Funktionskonten in der Benutzerverwaltung anlegen und
   deren feste Zuordnung kontrollieren und erst dann die fachliche Abnahme
   beginnen.

Synology beschreibt denselben Projektassistenten als Kombination aus
Projektname, Arbeitsverzeichnis und hochgeladener oder editierter
`docker-compose.yml`. Der optionale Web-Station-Portalweg ersetzt nicht die
TLS-, Proxy- und Firewall-Prüfung.

## Backup und Wiederherstellung auf dem NAS

Das Releasepaket enthält `backup.sh` und den rein lesenden
`verify-backup.sh`. Im Projektordner wird ein Vollbackup mit einem neuen,
ausdrücklich absoluten Ziel gestartet:

```console
install -d -m 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
ESTAB_CONTAINER_CLI=docker sh ./backup.sh "$backup_target"
```

Der Helfer verlangt einen eindeutig zugeordneten Stack mit erfolgreichem
Migrator und nachweislich gesunden `app`- und `db`-Containern. Er stoppt nur
die App, erzeugt einen transaktionalen MariaDB-Dump sowie Archive von
`4fdata` und Exportdaten, startet die App wieder und wartet mit begrenzter
Laufzeit auf ihren echten `healthy`-Status. Auch der Fehlerpfad gibt einen
Neustart erst nach dieser Health-Prüfung als erfolgreich frei.

Ein exklusives, atomar per `mkdir` erworbenes
`backups/.estab-backup.lock` schützt den gesamten Lauf und die Veröffentlichung
gegen parallele Backup-Prozesse im selben Elternverzeichnis. Projekt,
Datenbank, effektive Speicherquellen und alle drei Runtime-Image-Digests werden
in `SHA256SUMS` gebunden. Der fertige Zielordner erscheint erst nach interner
Verifikation durch die portable Umbenennung des privaten
Geschwister-Stagings; dafür genügt das normale `mv SOURCE TARGET` von
GNU-, BSD- und BusyBox-Systemen. Unter dem Lock wird unmittelbar vor dem
Rename erneut bewiesen, dass das Ziel nicht existiert. Ein vorhandenes Ziel
wird nie überschrieben, danach wird der veröffentlichte Satz noch einmal
vollständig verifiziert.
Docker-IDs mit `sha256:` und Podmans nackte 64-stellige Kleinhex-IDs werden
dabei im Metadatensatz kanonisch als `sha256:<64 Kleinhexzeichen>` gespeichert;
jedes andere Runtime-ID-Format beendet den Lauf vor dem App-Stopp.

Bei einem Fehler oder Signal werden Staging und eigener Lock sauber entfernt.
Bleibt etwa nach Hostabsturz ein Lock zurück, bricht der nächste Lauf
absichtlich ab. `owner.txt` nennt PID, Ziel und Startzeit. Erst nachdem
außerhalb des Skripts sicher bewiesen wurde, dass kein Backup-Prozess mehr
läuft, darf der Administrator genau diesen verwaisten Lockordner manuell
entfernen. `ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS` begrenzt Health-Waits auf
standardmäßig 240 Sekunden und akzeptiert Werte von 1 bis 3600.

Der Zugriff über Containerpfade funktioniert unverändert mit den drei
`./data/...`-Bind-Mounts und mit benannten Volumes. Das laufende
MariaDB-Verzeichnis `data/db` darf nicht als Ersatz für den logischen Dump
kopiert werden. Details, Format-2-Metadaten, Legacy-Verifikation und die
bewusst manuelle Wiederherstellung stehen in
[`docs/BACKUP-UND-WIEDERHERSTELLUNG.md`](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md).

Für den Restore werden die in der Sicherung protokollierten beiden
Image-Digests in `.env` eingetragen und mit `docker compose pull` geladen.
Dieser Pull-only-Weg verwendet kein `docker compose build`. Vor jeder
destruktiven Operation müssen Projektordner und die drei effektiven
`ESTAB_*_DATA_SOURCE`-Werte sichtbar auf `./data/db`, `./data/4fdata` und
`./data/export` zeigen. Anschließend werden Dump und Dateiarchive wie im
Runbook über die Containergrenzen eingespielt, der Migrator separat mit
Exitcode 0 abgeschlossen und erst danach die App freigegeben.

## Kontrolliertes Upgrade

Vor jedem Imagewechsel wird ein Vollbackup nach
[`docs/BACKUP-UND-WIEDERHERSTELLUNG.md`](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
erstellt und testweise wiederhergestellt. Das neue Paket darf in einem neuen
Versionsverzeichnis liegen, aber `COMPOSE_PROJECT_NAME` sowie die drei
`ESTAB_*_DATA_SOURCE`-Werte müssen exakt beim bisherigen Stand bleiben.
`docker compose config` muss vor dem Start dieselben effektiven Volume-Namen
beziehungsweise Bind-Pfade ausgeben. Danach beide Image-Referenzen in `.env`
auf denselben neuen Release-Stand setzen:

```console
docker compose pull
docker compose stop app
docker compose up --force-recreate migrate
docker compose up -d --force-recreate app
docker compose ps
```

Der Migrator muss mit Exitcode 0 enden. Ein fehlgeschlagener Lauf wird nicht
umgangen; zuerst bleiben Datenbank und Logs unverändert zu untersuchen.

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
