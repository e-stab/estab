# Backup und Wiederherstellung

Ein Einsatzexport ist kein Backup. Ein Vollbackup enthält:

- MariaDB-Dump,
- den vollständigen Inhalt von `4fdata`,
- sämtliche administrativen Exporte,
- Metadaten zu Projekt, Speicherquellen und Images,
- getrennt und verschlüsselt: `.env` und das Verzeichnis `secrets/`.

## Backup erstellen

Der Stack muss laufen und gesund sein. Das Ziel ist ein noch nicht
existierendes absolutes Verzeichnis.

```console
mkdir -p "$(pwd -P)/backups"
chmod 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"

ESTAB_CONTAINER_CLI=podman \
  sh deploy/registry/backup.sh "$backup_target"
```

Für Docker `ESTAB_CONTAINER_CLI=docker` verwenden. Das Werkzeug hält die App
kurz an, erstellt einen konsistenten Dump und Archive, schreibt
`SHA256SUMS`, prüft das Ergebnis und startet die App wieder.

Das Zielverzeichnis und sein Elternverzeichnis müssen dem ausführenden
Benutzer gehören, Modus `0700` besitzen und dürfen keine erweiterten ACLs
haben.

## Backup prüfen

```console
sh deploy/registry/verify-backup.sh "$backup_target" estab
```

Die Prüfung kontrolliert Format, erwartete Dateien, Prüfsummen,
Datenbankkennung und Archive. Ein Backup gilt erst nach erfolgreicher Prüfung
und einer regelmäßigen Restore-Probe als verwendbar.

## Wiederherstellen

Die Wiederherstellung ersetzt Datenbank, `4fdata` und Exportbestand des
bestätigten Projekts. Vorher:

1. vorhandenen Stack, `.env` und Secrets bereitstellen,
2. Zielprojekt und Speicherquellen prüfen,
3. aktuelles beschädigtes System bei Bedarf separat sichern,
4. Backup erneut verifizieren.

```console
restore_dir="$(pwd -P)/backups/20260804-120000"

ESTAB_CONTAINER_CLI=podman \
  sh deploy/registry/restore.sh \
    --confirm-project estab \
    "$restore_dir"
```

Das Werkzeug stoppt die App, leert ausschließlich die drei bestätigten
Fachdatenbereiche, stellt Daten wieder her, führt den Migrator aus und wartet
auf einen gesunden Stack. Bei einem Fehler mit `RECOVERY REQUIRED` nicht
weiterarbeiten; Logs und den Sicherungssatz erhalten.

## Wiederherstellung auf anderem Ziel

Weichen Projektname oder Speicherquellen ab, müssen sie ausdrücklich
zugeordnet werden:

```console
sh deploy/registry/restore.sh \
  --confirm-project neues-projekt \
  --remap-project altes-projekt=neues-projekt \
  --remap-storage database:estab_db=estab_db_neu \
  --remap-storage application:estab_data=estab_data_neu \
  --remap-storage export:estab_export=estab_export_neu \
  "$restore_dir"
```

Die Werte links vom Gleichheitszeichen müssen exakt den im Backup
gespeicherten Quellen entsprechen; die Werte rechts bezeichnen das Ziel.

Bei einem Wechsel zwischen Named Volume und Bind-Mount ist zusätzlich
`--remap-mount-type` erforderlich. `--allow-runtime-image-id-change` nur
verwenden, wenn das Ziel bewusst mit einem anderen, vorher geprüften
Image-Digest läuft.

## Aufbewahrung

- mindestens eine Kopie außerhalb des Containerhosts,
- Secrets nur verschlüsselt und getrennt vom Datenbackup,
- Aufbewahrungsfristen nach Betreiberanforderung,
- regelmäßige automatische Prüfsummenprüfung,
- wiederkehrende Restore-Probe auf einem isolierten Testsystem,
- vor jedem Upgrade ein neues Vollbackup.

Die Wartungswerkzeuge serialisieren Backup, Restore und Deployment über den
projektbezogenen Container-Lock `estab-maintenance-lock-<Projektname>`.
Parallel laufende Wartungsoperationen sind nicht zulässig.
