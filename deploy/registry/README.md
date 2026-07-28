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

Danach:

```console
docker compose config
docker compose pull
docker compose up -d
docker compose ps
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Mit Podman wird `docker compose` durch `podman compose` ersetzt. Ein privates
GHCR-Paket wird vorher einmal am Zielgerät angemeldet; Zugangstoken gehören
nicht in `.env` oder die Compose-Datei.

Standardmäßig sind `estab_db`, `estab_data` und `estab_export` benannte
Volumes. Alternativ akzeptieren `ESTAB_DB_DATA_SOURCE`,
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
   erreichbar machen.
3. In Container Manager unter „Project/Projekt“ ein neues Projekt erstellen,
   den Projektordner als Pfad wählen und die vorhandene `compose.yaml` als
   Quelle laden. Es ist kein lokaler Image-Build nötig.
4. Nach dem Start in den Projektdetails kontrollieren, dass `db` und `app`
   gesund sind und `migrate` erfolgreich mit Exitcode 0 beendet ist. Danach
   `/health.php`, Administration, Anmeldung und die fachliche Abnahme prüfen.

Synology beschreibt denselben Projektassistenten als Kombination aus
Projektname, Arbeitsverzeichnis und hochgeladener oder editierter
`docker-compose.yml`. Der optionale Web-Station-Portalweg ersetzt nicht die
TLS-, Proxy- und Firewall-Prüfung.

## Backup und Wiederherstellung auf dem NAS

Das Vollbackup wird im Projektordner mit den Befehlen aus
[`docs/BACKUP-UND-WIEDERHERSTELLUNG.md`](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
erstellt. Diese Befehle lesen Datenbank, `4fdata` und Exporte über ihre
Containerpfade und funktionieren dadurch unverändert mit den drei
`./data/...`-Bind-Mounts. Das laufende MariaDB-Verzeichnis `data/db` darf nicht
als Ersatz für den logischen Dump kopiert werden.

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
erstellt und testweise wiederhergestellt. Danach beide Image-Referenzen in
`.env` auf denselben neuen Release-Stand setzen:

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

Zwei Registry-Repositories können technisch nicht atomar in einer einzigen
Operation geändert werden. Deshalb baut der Workflow App und Migrator für
beide Architekturen vollständig, bevor der erste Push beginnt, und verwendet
ausschließlich einen neuen unveränderlichen Release-Tag. Scheitert der Lauf
nach dem ersten Push oder vor erfolgreicher Manifest-/Attestationsprüfung,
gilt der Tag ausdrücklich **nicht** als Release:

1. weder App- noch Migrator-Tag auf einem Zielgerät verwenden,
2. Workflowlog und bereits erzeugte Digests im Störungsprotokoll sichern,
3. in GHCR beide Paketversionen dieses Tags prüfen und alle unvollständigen
   Versionen nach Vier-Augen-Freigabe entfernen,
4. Ursache beheben und den Workflow vom unveränderten Git-Tag erneut starten,
5. erst nach grünem Plattform- und Attestationsnachweis beide Digests gemeinsam
   in Releaseinformationen und Deployment-`.env` übernehmen.

Ohne das Entfernen eines Teilstands verweigert der Workflow die Wiederholung,
damit ein vorhandener Tag niemals still überschrieben wird.
