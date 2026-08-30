# Installation und Betrieb

## Voraussetzungen

- Docker oder Podman mit Compose,
- `openssl` zum Erzeugen der Secrets,
- `curl` für die Bereitschaftsprüfung,
- rund 1 GiB freier Arbeitsspeicher für einen kleinen Einzelbetrieb
  (die Container sind auf 256 MiB für die Datenbank und 448 MiB für
  die Anwendung begrenzt; gemessen belegen sie zusammen rund 280 MiB),
- für Netzwerkzugriff ein TLS-Reverse-Proxy und eine Firewall.

Die Installation aus dem Checkout baut die Images lokal. Für eine
Produktivinstallation auf einer NAS ist ein veröffentlichtes Releasepaket
vorgesehen; siehe [Registry und Synology](../deploy/registry/README.md).

## Installation aus dem Checkout

```console
cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

Danach `.env` prüfen und starten:

```console
podman compose config
podman compose build --pull migrate app
podman compose up -d
podman compose ps
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
podman compose run --rm migrate
```

Für Docker wird `podman` durch `docker` ersetzt. Erwartet werden eine
Health-Antwort mit `"status":"ready"` und beim Migrator
`Post-migration schema verification passed`.

## Konfiguration

| Variable | Bedeutung | Standard |
| --- | --- | --- |
| `ESTAB_DB_NAME` | Datenbankname | `estab` |
| `ESTAB_DB_USER` | Anwendungsbenutzer der Datenbank | `estab` |
| `ESTAB_ADMIN_USER` | HTTP-Basic-Benutzer der Administration | `estab-admin` |
| `ESTAB_HTTP_BIND` | Hostadresse des veröffentlichten Ports | `127.0.0.1` |
| `ESTAB_HTTP_PORT` | Hostport | `8080` |
| `ESTAB_PUBLIC_URL` | öffentliche Basis-URL | `/` |
| `ESTAB_BASE_PATH` | URL-Unterpfad; beim gelieferten Image leer lassen | leer |
| `ESTAB_AUTHORITY_CODE` | Präfix für lokale Kennungen | `EL` |
| `ESTAB_ALLOW_SELF_REGISTRATION` | Startwert der Selbstregistrierung | `false` |
| `ESTAB_TRUST_PROXY_HEADERS` | Proxy-Header auswerten | `false` |
| `ESTAB_TRUSTED_PROXIES` | erlaubte direkte Proxy-IP oder CIDR | leer |
| `ESTAB_UPLOAD_MAX_BYTES` | maximale Uploadgröße | `20971520` |
| `ESTAB_PDF_ATTACHMENT_MAX_BYTES` | Gesamtgrenze der PDF-Anlagen | `52428800` |
| `TZ` | Zeitzone | `Europe/Berlin` |

Die drei `*_SECRET_FILE`-Variablen zeigen auf Dateien mit jeweils genau
einem Kennwort. Das Verzeichnis muss Modus `0700`, die Dateien Modus `0600`
besitzen. Secrets gehören weder in `.env` noch in Git.

Der Führungsstellenname ist keine Umgebungsvariable. Er wird beim Einsatz
gespeichert.

## Administration

Die Administration liegt unter `/4fadm/` und verwendet den separaten
HTTP-Basic-Zugang. Benutzername und Kennwort stammen aus
`ESTAB_ADMIN_USER` und `secrets/admin_password.txt`.

Nach einer Änderung des Admin-Secrets werden Hash und App neu erzeugt:

```console
podman compose up -d --force-recreate admin-auth-init app
```

Normale eStab-Konten werden anschließend in der Benutzerverwaltung angelegt.

## Reverse Proxy

Die sicherste Konfiguration lässt eStab auf Loopback gebunden:

```dotenv
ESTAB_HTTP_BIND=127.0.0.1
ESTAB_PUBLIC_URL=https://estab.example.org/
ESTAB_TRUST_PROXY_HEADERS=true
ESTAB_TRUSTED_PROXIES=127.0.0.1
```

Minimales nginx-Beispiel:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $remote_addr;
}
```

`ESTAB_TRUSTED_PROXIES` muss die direkte Proxy-Adresse eng begrenzen. Einen
auf `0.0.0.0` gebundenen Port nur für vorgesehene Netze in der Firewall
freigeben.

## Aktualisierung

Vor jeder Aktualisierung ein Vollbackup erstellen.

```console
git pull --ff-only
podman compose build --pull migrate app
podman compose stop app
podman compose up --force-recreate migrate
podman compose up -d app
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Migrationen laufen versions- und prüfsummengebunden. Eine veröffentlichte
SQL-Migrationsdatei darf nicht nachträglich geändert werden.

## Betrieb

```console
podman compose ps
podman compose logs --since=10m db migrate admin-auth-init app
podman compose logs --follow --tail=100 db migrate admin-auth-init app
podman compose exec -T app du -sh /var/www/html/4fdata /var/lib/estab/export
podman compose exec -T db du -sh /var/lib/mysql
```

Überwacht werden sollten:

- `/health.php` und der Container-Healthstatus,
- unerwartete Neustarts,
- PHP-Fatal- und Datenbankfehler,
- freier Speicherplatz,
- Alter und Prüfbarkeit des letzten Backups.

Der ausführliche Anwendungsstatus steht unter
`/4fadm/system_status.php`.

## Fehlerdiagnose

```console
podman compose ps --all
podman compose logs --tail=250 db migrate admin-auth-init app
podman compose config
```

Typische Ursachen sind nicht lesbare Secret-Dateien, ein bereits belegter
Port, ein fehlgeschlagener Migrator oder ein inkonsistentes vorhandenes
Datenvolume. Bei einem fehlgeschlagenen Upgrade nicht weiterarbeiten, sondern
Logs sichern und aus dem letzten geprüften Vollbackup wiederherstellen.
