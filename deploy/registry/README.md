# Registry- und Synology-Installation

Dieser Ordner ist für ein Pull-only-Releasepaket vorgesehen. Es baut auf dem
Zielgerät keine Images und verwendet ausschließlich fest an einen
`sha256`-Digest gebundene App- und Migrator-Images.

## Aktueller Status

Im Repository ist derzeit kein freigegebenes App-/Migrator-Digestpaar
veröffentlicht. Die Projektlizenz liegt als `LICENSE` vor. Bis zusätzlich
die Rechte und Hinweise aller mitgelieferten Inhalte in
`THIRD_PARTY_NOTICES.md` geprüft und ein vollständiges Releasepaket mit
`RELEASE` und `SHA256SUMS` verfügbar ist, wird für Tests die Installation
aus dem Checkout verwendet. Keine leeren Imagewerte ergänzen und kein
`latest` einsetzen.

Ein Release unterstützt `linux/amd64` und `linux/arm64`.

## Releasepaket prüfen

```console
sha256sum --check estab-RELEASE.tar.gz.sha256
tar -xzf estab-RELEASE.tar.gz
cd estab-RELEASE
sha256sum --check SHA256SUMS
sh ./verify-release.sh
```

Die Zeilen `ESTAB_APP_IMAGE` und `ESTAB_MIGRATE_IMAGE` in
`.env.example` sind Bestandteil des geprüften Pakets. Sie dürfen nicht
manuell ersetzt werden.

## Konfiguration

```console
cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

Danach in `.env` mindestens Hostadresse, Port, öffentliche URL,
Speicherquellen, Datenbankname, Admin-Benutzer und Zeitzone prüfen.
Laufzeitwerte gehören ausschließlich in diese Datei; `deploy.sh` lehnt
gleichnamige Prozessvariablen und automatische Compose-Overrides ab.

## Start

Mit Docker:

```console
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
```

Mit Podman:

```console
ESTAB_CONTAINER_CLI=podman sh ./deploy.sh check
ESTAB_CONTAINER_CLI=podman sh ./deploy.sh pull
ESTAB_CONTAINER_CLI=podman sh ./deploy.sh up
```

Erst die Meldung `eStab deployment: ready` bestätigt den erfolgreichen
Start. Die Anwendung ist danach standardmäßig unter
`http://127.0.0.1:8080/` erreichbar.

Private GHCR-Images erfordern einen klassischen Zugriffstoken ausschließlich
mit `read:packages`:

```console
printf '%s' "$GHCR_TOKEN" |
  docker login ghcr.io --username GITHUB-BENUTZER --password-stdin
```

Der Token gehört nicht in `.env`.

## Synology Container Manager

Das Paket beispielsweise nach `/volume1/docker/estab` entpacken und über
SSH vorbereiten:

```console
cd /volume1/docker/estab
mkdir -p data/db data/4fdata data/export backups secrets
chmod 0700 data data/db data/4fdata data/export backups secrets
```

In `.env`:

```dotenv
ESTAB_DB_DATA_SOURCE=./data/db
ESTAB_APP_DATA_SOURCE=./data/4fdata
ESTAB_EXPORT_DATA_SOURCE=./data/export
```

Dann:

```console
sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
```

Container Manager eignet sich anschließend für Status und Logs. Installation
und Updates erfolgen weiter über `deploy.sh`, damit Vorprüfung, Digestbindung
und Migration nicht umgangen werden.

Für direkten LAN-Zugriff kann `ESTAB_HTTP_BIND=0.0.0.0` gesetzt werden.
Den Port dann per DSM-Firewall auf vorgesehene Netze begrenzen und einen
TLS-Reverse-Proxy verwenden.

## Backup

```console
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
sudo env ESTAB_CONTAINER_CLI=docker sh ./backup.sh "$backup_target"
sh ./verify-backup.sh "$backup_target" estab
```

Die Secret-Dateien und `.env` zusätzlich getrennt verschlüsselt sichern.
Der vollständige Ablauf steht unter
[Backup und Wiederherstellung](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md).

## Update

1. Vollbackup erstellen und prüfen.
2. Neues Release in ein neues Verzeichnis entpacken.
3. Neue `.env` aus der mitgelieferten `.env.example` erzeugen.
4. Projektname, Secrets und Speicherquellen unverändert übernehmen.
5. Die beiden Imagezeilen aus dem neuen Paket unverändert lassen.
6. `check`, `pull` und `up` ausführen.

Ein fehlgeschlagenes Upgrade nicht durch manuelle Datenbankeingriffe
fortsetzen. Logs sichern und bei Bedarf das geprüfte Vollbackup
wiederherstellen.

## Offline-Images

Mit `skopeo` und `jq` lassen sich die drei gebundenen Multi-Arch-Images
exportieren:

```console
sh ./offline-images.sh export /absoluter/pfad/estab-images
sh ./offline-images.sh verify /absoluter/pfad/estab-images
```

Ein internes Registry-Ziel wird ohne veränderliche Tags geprüft:

```console
sh ./offline-images.sh check-mirror \
  /absoluter/pfad/estab-images registry.example.org/estab
```

Beim Spiegeln müssen alle Plattformen und Digests erhalten bleiben, etwa mit
`skopeo copy --all --preserve-digests`.

## Diagnose

```console
docker compose --env-file .env -f compose.yaml ps --all
docker compose --env-file .env -f compose.yaml \
  logs --tail=200 db migrate admin-auth-init app
curl --fail http://127.0.0.1:8080/health.php
```

Admin-Benutzer und Admin-Kennwort stehen in `.env` beziehungsweise
`secrets/admin_password.txt`. Normale Benutzerkonten werden anschließend in
der Anwendung angelegt.
