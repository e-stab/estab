# eStab

eStab unterstützt die Arbeit einer Führungsstelle mit Einsatzverwaltung,
Nachrichtenvordrucken, Sichtung und Beförderungsnachweis, Anhängen, ETB, TTB,
Fernmeldeplan, Melderaufträgen und Einsatzexport.

Die Anwendung läuft als PHP-8.5-/MariaDB-Stack mit Docker Compose oder Podman
Compose. Standardmäßig ist sie nur unter `127.0.0.1:8080` erreichbar.

## Funktionen

- genau ein aktiver Einsatz; historische Einsätze bleiben lesbar,
- persönliche Konten, sperrbare Zugänge und konfigurierbare Kennwortrichtlinie,
- strenger Betrieb mit Dienstschichten oder lockerer Betrieb mit festen und
  zusätzlichen Funktionen,
- vollständiger Lauf für Eingangs- und Ausgangsnachrichten,
- Anhänge einschließlich Bildern, PDF und `.eml` direkt am Vordruck,
- append-only geführtes ETB und TTB,
- versionierte Fernmeldepläne und dokumentierte Melderaufträge,
- PDF-Einsatzdossier und maschinenlesbarer Einsatzexport.

## Schnellstart

Vorausgesetzt werden Docker oder Podman mit Compose, `openssl` und `curl`.
Das folgende Beispiel verwendet Podman; für Docker wird `podman` durch
`docker` ersetzt.

```console
cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt

podman compose config
podman compose build --pull migrate app
podman compose up -d
podman compose ps
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Die Health-Antwort muss `"status":"ready"` enthalten. Der vollständige
Schematest lässt sich anschließend erneut ausführen:

```console
podman compose run --rm migrate
```

Bei Problemen:

```console
podman compose ps --all
podman compose logs --tail=200 db migrate admin-auth-init app
```

## Erste Einrichtung

1. `http://127.0.0.1:8080/4fadm/` öffnen.
2. Mit dem Benutzer aus `ESTAB_ADMIN_USER` – standardmäßig
   `estab-admin` – und dem Inhalt von `secrets/admin_password.txt`
   anmelden.
3. Einen Einsatz anlegen, den Führungsstellennamen und den
   Berechtigungsmodus festlegen und den Einsatz aktivieren.
4. Persönliche Benutzerkonten anlegen.
5. Im Modus **Streng** eine Dienstschicht aktivieren, Funktionen besetzen und
   die Besetzungen durch die Benutzer annehmen lassen.
6. Im Modus **Locker** bei Bedarf Zusatzfunktionen und Zugangsschichten
   konfigurieren.
7. Einen Fernmeldeplan anlegen und veröffentlichen.

Eine Neuinstallation besitzt absichtlich keinen aktiven Einsatz. Bis zur
Einrichtung bleiben operative Eingaben gesperrt.

## Starten und Stoppen

```console
podman compose up -d
podman compose stop
podman compose down
```

`compose down` behält die Daten. `compose down --volumes` löscht die
Volumes und darf nur bei einem wegwerfbaren Testsystem oder nach geprüftem
Vollbackup verwendet werden.

## Persistente Daten

| Volume | Inhalt |
| --- | --- |
| `estab_db` | MariaDB-Daten |
| `estab_data` | Anhänge und erzeugte Vordrucke |
| `estab_export` | administrative Exporte |
| `estab_auth` | abgeleiteter HTTP-Basic-Hash für die Administration |

Zum fachlichen Vollbackup gehören Datenbank, `estab_data` und
`estab_export`. Die Secret-Dateien und `.env` müssen getrennt verschlüsselt
gesichert werden.

## Netzwerk und Sicherheit

- Für einen einzelnen Rechner `ESTAB_HTTP_BIND=127.0.0.1` beibehalten.
- Für andere Geräte einen TLS-Reverse-Proxy und eine Firewall verwenden.
- Proxy-Header nur mit `ESTAB_TRUST_PROXY_HEADERS=true` und einer engen
  `ESTAB_TRUSTED_PROXIES`-Allowlist akzeptieren.
- Selbstregistrierung ist standardmäßig deaktiviert und wird in der
  Administration nur kontrolliert oder zeitlich begrenzt geöffnet.
- Datenbank- und Admin-Kennwörter niemals committen.
- eStab enthält modernisierten Legacy-Code und sollte nicht direkt aus dem
  Internet erreichbar sein.

## Dokumentation

- [Installation und Betrieb](docs/INSTALLATION.md)
- [Bedienung](docs/BEDIENUNG.md)
- [Technik und Entwicklung](docs/TECHNIK.md)
- [Backup und Wiederherstellung](docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
- [Registry- und Synology-Installation](deploy/registry/README.md)
- Das aktuelle Benutzerhandbuch ist außerdem direkt unter `/handbuch/`
  erreichbar.

## Veröffentlichungsstatus

Der lokale Source-Build ist nutzbar. Das vorbereitete Registry-Release ist
solange nicht zur Weiterverteilung freigegeben, bis `LICENSE`,
`THIRD_PARTY_NOTICES.md` und ein veröffentlichtes App-/Migrator-Digestpaar
vorliegen. Keine Image-Referenzen erfinden oder `latest` verwenden.
