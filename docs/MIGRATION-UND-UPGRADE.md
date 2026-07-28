# Migration und Upgrade

Bei eStab sind drei unterschiedliche Vorgänge auseinanderzuhalten:

1. Die bereits abgeschlossene Überführung der Quellhistorie von SVN nach Git
   ist unter [`migration/README.md`](../migration/README.md) mit Prüfsummen,
   Ref-Vergleichen und SVN-Metadaten belegt.
2. Eine frische Containerinstallation legt ein neues MariaDB-Schema an und
   übernimmt keine Altdaten.
3. Die Übernahme einer vorhandenen Einsatzdatenbank und ihres `4fdata`-Baums
   ist eine eigene, sicherungs- und prüfpflichtige Datenmigration.

## Frische Installation

Bei leerem Volume `estab_db` legt der offizielle MariaDB-Entrypoint zunächst
nur die konfigurierte Datenbank und den Anwendungsbenutzer an. Anschließend
erkennt der One-shot-Migrator den vollständig leeren `nv_*`-Namensraum und
führt seine eingebettete kanonische Fassung von
`docker/db/init/10-schema.sql` aus. Das Skript:

- legt die 14 Basistabellen als InnoDB/`utf8mb4` an,
- initialisiert die 20 Positionen der Empfängermatrix,
- verwendet `NULL` statt ungültiger Zero Dates,
- dimensioniert Passwort-, IP-, Session- und Anhangfelder für die moderne
  Laufzeit,
- legt die für atomare Anhangreservierungen erforderlichen Indizes an.

Der Dateibaum wird nicht aus dem Image vorbelegt. Der App-Entrypoint erzeugt
die benötigten Verzeichnisse im persistenten `estab_data`-Volume.

Das Basisschema wird erstmals angelegt, wenn der `nv_*`-Namensraum leer ist.
Vor dem ersten DDL speichert der Runner Dateiname, SHA-256 und Zustand
`applying` in `estab_schema_baselines`. Nach einem harten Abbruch darf nur
dieselbe Baseline erneut laufen; ihre `IF NOT EXISTS`-/`INSERT IGNORE`-Schritte
ergänzen die fehlenden Tabellen, bevor der Datensatz auf `applied` wechselt.
Ein unprotokollierter teilinitialisierter Namensraum ohne
`nv_nachrichten` wird weiterhin mit eindeutiger Fehlermeldung blockiert. Ein
vorhandenes Legacy-Schema mit Kerntabelle überspringt die Fresh-Baseline und
durchläuft ausschließlich die versionierten Upgrade-Migrationen. Erst
erfolgreicher Exit und Post-Migrations-Schematest geben die App frei. Bei
einem bloßen Neustart mit unverändertem Image verwendet Compose den bereits
erfolgreich beendeten One-shot-Container; für eine erneute Prüfung wird er
explizit mit `--force-recreate` gestartet.

## Upgrade einer bestehenden Containerinstallation

Vor jedem Upgrade:

1. verwendeten Git-Commit und Image-Digest notieren,
2. Anwendung für Schreibzugriffe anhalten,
3. konsistentes Datenbank-, `4fdata`- und Export-Backup erzeugen,
4. das Backup in einem separaten Test-Stack wiederherstellen,
5. den One-shot-Migrator dort ausführen und seine Prüfsummen-/Schemaausgabe
   sichern,
6. Schema-, Integrations- und fachliche Abnahmetests bestehen,
7. erst danach das Produktionsfenster durchführen.

Die vollständigen Sicherungsbefehle stehen unter
[Backup und Wiederherstellung](BACKUP-UND-WIEDERHERSTELLUNG.md).

Derzeit sind folgende explizite Migrationen vorhanden:

| Datei | Zweck |
| --- | --- |
| `docker/db/migrations/20-nullable-dates.sql` | konvertiert historische `0000-00-00 00:00:00`-Werte zu `NULL` und macht die betroffenen Spalten nullable |
| `docker/db/migrations/30-runtime-schema.sql` | erweitert Benutzer-, IP-, Nachrichtenkürzel- und Anhangfelder, stellt Laufzeitindizes und ETB-/TBB-Titeltabellen her, erzwingt eindeutige Anhangnamen und normalisiert vorhandene `nv_*`-Tabellen auf InnoDB/`utf8mb4` |

Das freigegebene Upgradeverfahren lautet:

```console
podman compose build --pull migrate app
podman compose stop app
podman compose up --force-recreate migrate
podman compose up -d app
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Der Migrator nutzt das Root-Secret über eine nur für den Prozess lesbare,
temporäre MariaDB-Optionsdatei. Es steht nicht in Kommandoargumenten oder
Logs. Für jede Datei wird vor Ausführung SHA-256 berechnet. Die Tabelle
`estab_schema_migrations` enthält Dateiname, Prüfsumme, Zustand, Lauf-ID sowie
Start- und Abschlusszeit. Ein bereits angewendeter Dateiname mit anderer
Prüfsumme blockiert den Start: veröffentlichte Migrationen dürfen nie
nachträglich geändert werden, sondern erhalten eine neue Versionsdatei.

Die SQL-Dateien sind wiederholbar. Bei einem regulären SQL-Fehler entfernt der
Runner ausschließlich seinen eigenen `applying`-Datensatz, sodass nach
fachlicher Korrektur erneut gestartet werden kann. Ein harter Prozessabbruch
kann bewusst einen `applying`-Datensatz hinterlassen; dieser Zustand ist
fail-closed und muss vor einer manuellen Änderung anhand von Backup,
Containerlog und bereits ausgeführten DDL-Schritten geprüft werden.

`30-runtime-schema.sql` prüft Anhangnamen vor jeder Strukturänderung. Gibt es
mehrere Zeilen mit demselben `nv_anhang.filename`, signalisiert MariaDB einen
Fehler und die App bleibt aus. Solche Zeilen dürfen nicht pauschal gelöscht
werden; Datenbankreferenz und Datei müssen fachlich gemeinsam aufgelöst
werden. Nach erfolgreicher Migration prüft der Runner alle `*_ok`-Werte aus
`verify.sql`; zusätzlich sind die Integrations- und Fachtests aus
[Tests und Monitoring](TESTS-UND-MONITORING.md) auszuführen.

## Übernahme einer historischen MySQL-/MariaDB-Datenbank

Ein altes MySQL-Datenverzeichnis darf nicht als `/var/lib/mysql` in den
MariaDB-11.8-Container eingehängt werden. Unterschiede bei
Systemtabellen, Storage-Engine und Serverformat machen eine solche
Dateikopie unzuverlässig. Die Quelle muss mit ihrem kompatiblen alten Server
gestartet und logisch per `mysqldump`/`mariadb-dump` exportiert werden.

`docker/db/init/10-schema.sql` ist idempotent für sein eigenes aktuelles
Schema, aber ausdrücklich **kein allgemeines Upgradeprogramm für beliebige
Altschemata**. `CREATE TABLE IF NOT EXISTS` ersetzt keine vorhandene
MyISAM-Tabelle, keine alte Collation und keinen abweichenden Index. Ein
vollständiger Alt-Dump mit DDL kann umgekehrt das moderne, frisch angelegte
Schema wieder durch die alten Definitionen ersetzen.

Deshalb erfolgt eine Altdatenübernahme zuerst in einer isolierten
Staging-Kopie. Dort ist mindestens zu prüfen und gegebenenfalls kontrolliert
anzupassen:

- stimmen die Basistabellen mit `docker/db/init/10-schema.sql` überein,
- sind sämtliche eStab-Tabellen InnoDB und `utf8mb4`,
- wurden Zero Dates mit `20-nullable-dates.sql` zu `NULL` konvertiert,
- ist `nv_benutzer.password` breit genug für `password_hash()`-Werte,
- gibt es doppelte oder leere `nv_anhang.filename`-Werte, bevor der eindeutige
  Anhangindex angelegt wird,
- sind sechsstellige Benutzer-/Anhangkürzel verlustfrei möglich,
- sind dynamische Benutzer- und Funktionstabellen samt Daten und Indizes
  vorhanden,
- verwenden Installation und SQL-Skripte die erwarteten `nv_*`-Namen.

Der Migrator entdeckt alle vorhandenen Basistabellen mit dem konfigurierten
historischen `nv_`-Präfix und konvertiert ihre Engine und Collation auf
InnoDB/`utf8mb4_unicode_ci`; dabei bleiben die Tabelleninhalte erhalten. Die
Anwendung modernisiert beim Anlegen beziehungsweise Aktivieren eines
Funktionsbenutzers zusätzlich die konkrete Struktur und die benannten Indizes
dessen sechs dynamischer Tabellen. Zero Dates werden `NULL`; Daten, doppelte
Kategoriezuordnungen und gültige Zeitstempel bleiben erhalten. Vor der Freigabe
müssen trotzdem alle tatsächlich benötigten Funktionen aktiviert und die
separate Regression `tests/integration/dynamic_tables.php` bestanden werden,
weil ein beliebiges Altschema weitere strukturelle Abweichungen besitzen kann.

Duplikate bei Anhangnamen können vor einer Indexänderung gelesen werden:

```sql
SELECT `filename`, COUNT(*) AS `anzahl`
FROM `nv_anhang`
GROUP BY `filename`
HAVING COUNT(*) > 1;
```

Sie dürfen nicht automatisch gelöscht oder umbenannt werden, weil
Nachrichtenreferenzen und Dateien gemeinsam aufgelöst werden müssen. Bei
Schemaabweichungen ist ein für genau diesen Quellstand geprüftes
Migrationsskript zu erstellen; eine manuelle Änderung direkt in Produktion ist
kein freigegebener Pfad.

## Dateiübernahme

Zur Datenbank gehören die historischen Anhänge und Vordrucke. Im Container
liegen sie unter:

```text
/var/www/html/4fdata/$ESTAB_DB_NAME/anhang
/var/www/html/4fdata/$ESTAB_DB_NAME/vordruck
```

Die Dateien müssen gemeinsam mit der passenden Datenbankversion in das
`estab_data`-Volume übernommen werden. Namen, Groß-/Kleinschreibung und
Unterverzeichnisse bleiben unverändert. Ausführbare Uploadtypen werden vom
Apache im Datenverzeichnis nicht interpretiert und sind zusätzlich gesperrt.
Nach der Kopie müssen Verzeichnisse für `www-data` beschreibbar sein; ein
Neustart des App-Containers legt fehlende Laufzeitverzeichnisse mit den
vorgesehenen Rechten an.

Der administrative CSV-/ZIP-Export enthält keine Anhänge, Vordrucke oder
Tabellendefinitionen und besitzt keinen automatischen Importer. Er ist daher
weder eine Dateiübernahme noch ein vollständiges Migrationsformat.

## Upgrade-Abnahme und Rollback

Für jede Abnahme werden festgehalten:

- Git-Commit und Image-Digest,
- Quell- und Zielversion der Datenbank,
- Hash und Zeitpunkt des verwendeten Backups,
- ausgeführte Migrationsdateien,
- vollständige Ausgabe von `docker/db/verify.sql`,
- Ergebnisse der statischen, HTTP-, Datenbank- und fachlichen Tests,
- Name der freigebenden Person.

Ein Rückrollen nur des App-Images ist ausschließlich zulässig, wenn die
Schemaänderungen nachweislich rückwärtskompatibel sind. Andernfalls besteht der
Rollback aus altem Image **und** vollständiger Wiederherstellung des unmittelbar
vor dem Upgrade erzeugten Datenbank-/Dateibackups.

## Historische Nachweise

Die Originalquellen werden nicht dupliziert:

- [SVN-/Git-Migrationsnachweis](../migration/README.md)
- [Index der 95 unverändert erhaltenen Dokumente](legacy/README.md)
- [historisches Anwendungshandbuch](../doku/Handbuch_eStab.pdf)

Die dort beschriebenen Web-Installer, XAMPP-Konfigurationen und leeren
MySQL-Root-Kennwörter sind nur historischer Kontext und kein zulässiger
Containerbetrieb.
