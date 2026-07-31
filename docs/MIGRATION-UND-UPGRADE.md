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
| `docker/db/migrations/40-recipient-matrix-standard.sql` | ersetzt die frühere ausführbare `deault.fkt.php` durch genau eine persistente 20-Zellen-Standardmatrix; das historische Autosichtungsfeld wird nur noch als inaktives Kompatibilitätsfeld weitergeführt |
| `docker/db/migrations/45-global-incidents-prepare.sql` | prüft vor dem Einsatz-Backfill alle zehn operativen Basistabellen und setzt die beiden betroffenen Zeitspalten vorübergehend ohne automatisches `ON UPDATE` |
| `docker/db/migrations/50-global-incidents.sql` | führt die globale Einsatzdomäne, den geschlossenen `LEGACY-IMPORT`, die Einsatzzuordnung und die Datenbank-Schreibgrenzen ein; diese veröffentlichte Fassung bleibt bytegenau unverändert |
| `docker/db/migrations/55-global-incidents-finish.sql` | prüft den vorbereiteten beziehungsweise bereits fertigen Zustand und stellt die kanonischen `ON UPDATE CURRENT_TIMESTAMP`-Definitionen nach dem Einsatz-Backfill wieder her |
| `docker/db/migrations/70-user-account-blocking.sql` | ergänzt die kollisionsgeprüfte dauerhafte Kontosperre |
| `docker/db/migrations/80-dv-evidence-retention.sql` | ergänzt den unveränderbaren Nachrichten- und ETB-Nachweis, den formalen Einsatzabschluss, die Mindestaufbewahrung von einem Jahr und eine zusätzliche Aufbewahrungssperre |
| `docker/db/migrations/94-dv-organisational-controls.sql` | ergänzt Dienstschichten und Mehrfachfunktionen, den versionierten S6-Fernmeldeplan, den vollständigen Melderlauf sowie eine verkettete Ereignisspur dieser Betriebsabläufe; zugleich wird Autosichtung verbindlich deaktiviert und S2 als Rotkopie-/Dokumentationsfähigkeit normalisiert |
| `docker/db/migrations/95-attachment-ingest-integrity.sql` | markiert beim Upgrade vorhandene Anhänge ausdrücklich als nicht rückwirkend belegbaren Legacy-Bestand und verlangt für jeden danach finalisierten Anhang einen unveränderlichen SHA-256-/Größen-/Serverzeit-Nachweis; Trigger verhindern neue Legacy-Markierungen, Herabstufung und nachträgliche Beweisänderung |
| `docker/db/migrations/96-etb-duty-function.sql` | führt die eigenständig auswählbare ETB-Dienstfunktion ein, ordnet die Fähigkeit `EINSATZTAGEBUCH` sowohl S2 als auch ETB über den zusammengesetzten Schlüssel `(funktion, faehigkeit)` zu und belässt Rotkopie sowie `LAGE_DOKUMENTATION` ausschließlich bei S2 |
| `docker/db/migrations/97-incident-command-post-name.sql` | ergänzt den einsatzbezogenen, nullable Führungsstellennamen mit eindeutiger Eigentumsmarkierung; vorhandene Einsätze bleiben absichtlich `NULL`, weil Einsatzname, Organisation und Einsatzleitung keine belegbaren historischen Ersatzwerte sind |
| `docker/db/migrations/98-official-message-form-fields.sql` | ergänzt die amtlichen, getrennt persistierten Nachrichtenvordruckfelder `11_rufnummer` und `12_betreff`; Bestandsnachrichten behalten alle vorhandenen Werte und erhalten für beide neuen Angaben ausschließlich den leeren Standardwert |
| `docker/db/migrations/99-message-list-search.sql` | ersetzt den früheren Inhalts-Volltextindex durch den siebenfeldrigen Suchindex und ergänzt die beiden einsatzgebundenen BTREE-Indizes für skalierbare Meldungslisten |
| `docker/db/migrations/100-session-presence.sql` | ergänzt den UTC-Zeitstempel der letzten echten Browserinteraktion und den Präsenzindex; bereits aktive Legacy-SIDs ohne belegbaren Zeitpunkt werden beim Upgrade einmalig widerrufen |

Migration 95 klassifiziert vorhandene Zeilen bereits beim Hinzufügen der
Spalte mit dem einmaligen Anfangswert `integrity_required=0` und stellt danach
den Standardwert rein als Tabellenmetadatum auf `1`. Sie führt bewusst kein
operatives `UPDATE nv_anhang` aus. Dadurch bleibt die allgemeine
Einsatz-Schreibsperre unverändert wirksam und ein historischer, bereits
geschlossener Import lässt sich trotzdem ehrlich als nicht rückwirkend
belegbar kennzeichnen. Ihre nicht transaktionalen DDL-Phasen sind
wiederanlauffähig: Eigentumsmarkierungen und exakte Formprüfungen akzeptieren
nur die von der Migration selbst erzeugbaren Spaltenpräfixe, Constraints und
Trigger. Gleichnamige fremde oder abweichende Objekte werden vor jeder
weiteren Änderung abgewiesen. Der Schema-Integrationstest stellt gezielt nur
die erste autocommittete Spalte her, startet den Migrator zweimal und weist
anschließend Standardwert, alle vier Markierungen, beide Constraints, beide
Trigger, den unveränderten Legacy-Datensatz und ein leeres
Hilfsprozedur-Namensfeld nach.

Migration 96 akzeptiert ausschließlich drei exakt geprüfte Zustände der
migrationseigenen Fähigkeitstabelle: den fünfzeiligen Vorgänger mit altem
ENUM und noch vorhandenem oder bereits entferntem globalen Unique-Index, den
fünfzeiligen DDL-Zwischenstand mit erweitertem ENUM ohne diesen Index sowie
den siebenzeiligen Endzustand. Spalten, Reihenfolge, Datentypen,
Zeichensatz, Primärschlüssel `(funktion, faehigkeit)`, Indizes, ENUM-Werte und
alle fachlichen Zeilen werden dabei vollständig verglichen. Die Migration
entfernt den früheren global eindeutigen Fähigkeitsschlüssel, erweitert das
ENUM um `EINSATZTAGEBUCH` und ergänzt genau die Schlüssel
`S2/EINSATZTAGEBUCH` und `ETB/EINSATZTAGEBUCH`.

Nach einem Prozessverlust zwischen den nicht transaktionalen DDL-Phasen wird
zunächst das Migrationsledger nach dem unten beschriebenen Verfahren
abgeglichen. Ein anschließender Wiederanlauf konvergiert nur aus einem dieser
eigenen Zwischenstände. Gemischte Katalogdaten, ein abweichender
Primärschlüssel oder fremde Indizes blockieren vor der nächsten Änderung und
bleiben zur Untersuchung erhalten. `verify.sql` und die Laufzeit-Readiness
verlangen danach exakt sieben Katalogzeilen, das vollständige neue ENUM,
ausschließlich den zweispaltigen Primärschlüssel und alle fünfzehn
angewendeten Migrationen einschließlich Version 100.

Migration 97 fügt `nv_einsaetze.fuehrungsstellenname` als
`VARCHAR(128) NULL` unmittelbar hinter `organisation` und
`fuehrungsstellenname_gesperrt` als `TINYINT UNSIGNED NOT NULL DEFAULT 0`
direkt dahinter ein. Der Name bleibt für Bestandszeilen bewusst leer; weder
Einsatzname, Organisation, Einsatzleitung noch Umgebung werden als Ersatz
übernommen. Damit wird keine historische lokale Anschrift/Absendereinheit
erfunden. Die DDL ist wiederanlauffähig und akzeptiert nur fehlende oder exakt
markierte eigene Spalten, Routinen und Trigger; gleichnamige fremde Objekte
blockieren vor jeder Übernahme.

Die Migration erweitert außerdem die DB-Schreibgrenze: Ein Legacy-Writer muss
den Namen des aktiven Einsatzes validieren und setzt beim ersten operativen
Schreiben den Sperrmarker in derselben Transaktion. Direkte Manipulationen von
Name oder Marker werden blockiert. Historische `NULL`-Zeilen bleiben zunächst
entsperrt, damit der belegte Wert trotz Altdaten einmalig ergänzt werden kann;
diese Ergänzung setzt den Marker sofort.

Nach dem Upgrade verlangt die Anwendung für jeden **neuen** Einsatz einen
gültigen Führungsstellennamen. Ein offener Bestands-Einsatz mit `NULL` muss in
der Administration einmalig mit dem belegten tatsächlichen Namen bestätigt
werden, bevor er aktiviert oder weiter operativ beschrieben werden kann.
Diese einmalige `NULL`-zu-Wert-Bestätigung ist trotz vorhandener historischer
Fachdaten zulässig. Ein bereits belegter Wert kann nur vor der ersten
operativen Eintragung korrigiert werden und ist danach unveränderlich; ein
formal abgeschlossener Einsatz bleibt unverändert. Der dauerhafte Marker
bleibt auch nach dem Löschen einzelner Fachdaten gesetzt. Historische Exporte
bleiben auch ohne Nachtrag möglich; die menschenlesbare PDF-Ausgabe
kennzeichnet den Fehlwert ausdrücklich als „historisch nicht erfasst“.

Migration 98 ergänzt `nv_nachrichten.11_rufnummer` als
`VARCHAR(128) NOT NULL DEFAULT ''` und `nv_nachrichten.12_betreff` als
`VARCHAR(255) NOT NULL DEFAULT ''`. Beide Spalten tragen eine eindeutige
Eigentumsmarkierung. Jede DDL-Phase ist wiederanlauffähig; fehlende Spalten
werden ergänzt, exakt kanonische eigene Spalten werden akzeptiert und
gleichnamige fremde Definitionen blockieren den Start. Die Migration führt
kein Daten-`UPDATE` aus. Dadurch bleiben sämtliche bestehenden
Nachrichtenwerte unverändert und die neuen Felder sind bei historischen
Nachrichten eindeutig leer.

Migration 99 ersetzt den früheren reinen Inhalts-Volltextindex durch den
siebenspaltigen Suchindex `ft_nachrichten_suche` und ergänzt zwei
einsatzgebundene BTREE-Indizes für Stand/Zeit sowie Richtung/Nachweisnummer.
Sie prüft vor der ersten DDL-Phase alle eigenen Indexnamen, legt zuerst den
breiteren Volltextindex an und entfernt erst danach den alten Index. Bereits
vollständig ausgeführte Phasen werden beim Wiederanlauf akzeptiert; fremde
gleichnamige Definitionen blockieren unverändert.

Migration 100 verlangt die kanonische Benutzertabelle samt bestehender
Kontosperre und ergänzt ausschließlich
`estab_letzte_aktivitaet DATETIME(6) NULL` mit eindeutiger
Eigentumsmarkierung sowie den BTREE-Index
`idx_benutzer_presence (aktiv, estab_gesperrt, estab_letzte_aktivitaet)`.
Fehlende oder exakt eigene Zwischenstände sind wiederaufnehmbar; gleichnamige
fremde Spalten- oder Indexdefinitionen blockieren vor einer weiteren
Änderung. Hilfsprozeduren werden nach jeder Phase entfernt.

Bereits vor dem Upgrade mit `aktiv = 1` gespeicherte Zeilen besitzen keinen
vertrauenswürdigen Aktivitätszeitpunkt. Die Migration setzt sie deshalb
einmalig auf abgemeldet und leert SID sowie IP-Metadaten, statt ihnen
stillschweigend ein neues 12-Stunden-Fenster zu gewähren. Nach dem Upgrade ist
eine Neuanmeldung aller zuvor aktiven eStab-Funktionskonten erforderlich. Der
separate HTTP-Basic-Administrationszugang ist davon nicht betroffen. Neue
Logins schreiben den UTC-Zeitstempel; echte Browserinteraktion aktualisiert
ihn später ausschließlich über den Session-CSRF- und SID-gebundenen
Aktivitätsendpunkt. Automatische Statuspolls aktualisieren ihn nicht.

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

Die Migrationsfolge 45/50/55 ist ein bewusstes Beispiel für diese
Unveränderlichkeitsregel. Migration 50 wurde bereits mit ihrer SHA-256-
Prüfsumme protokolliert und wird deshalb nicht um nachträgliche
Vorbereitungslogik erweitert. Migration 45 prüft den Legacy-Bestand und
deaktiviert die beiden automatischen Aktualisierungsattribute vor dem
Backfill. Migration 55 akzeptiert für einen sicheren Wiederanlauf nur die
kanonisch vorbereitete oder bereits wiederhergestellte Spaltendefinition und
aktiviert die Attribute abschließend wieder. Die App bleibt durch das
Compose-Startgate während der gesamten Folge aus. In einer Datenbank, die
Migration 50 bereits kennt, werden die fehlenden Migrationen 45 und 55 beim
nächsten Upgrade angewendet, während 50 nur bei bytegleicher Prüfsumme
übersprungen wird.

Der verbindliche SHA-256 der unveränderten Datei
`50-global-incidents.sql` ist:

```text
6732e9c87f0532fce41ee9a58658bf4888fdf7c2ced1ed6bad75a756d6e08edf
```

### Prüfsummenabweichung sicher untersuchen

Die Meldung

```text
Checksum mismatch for applied migration: <datei>.sql
```

ist eine Schutzfunktion und kein Hinweis darauf, den Ledger zu bereinigen.
Zuerst werden ausschließlich lesend Protokoll und lokale Prüfsumme erfasst:

```console
podman compose logs --no-color migrate
sha256sum docker/db/migrations/<datei>.sql
```

Der zugehörige Ledgerdatensatz kann über die im
[`docker/db/README.md`](../docker/db/README.md) dokumentierte private
MariaDB-Optionsdatei mit folgender Abfrage gelesen werden:

```sql
SELECT `version`, `checksum`, `state`, `started_at`, `applied_at`
FROM `estab_schema_migrations`
WHERE `version` = '<datei>.sql';
```

Danach werden Git-Commit, Image-Digest und die unveränderte Migrationsdatei des
zuletzt erfolgreichen Releases miteinander verglichen. Wurde eine
veröffentlichte Datei im Arbeitsstand verändert, ist die sichere Korrektur:
die bereits angewendete Fassung bytegenau wiederherstellen und die beabsichtigte
Änderung als neue Versionsdatei formulieren. Genau deshalb liegen
Vorbereitung und Abschluss der Einsatzmigration in 45 und 55, während 50
unverändert bleibt.

Folgende vermeintliche Abkürzungen sind nicht zulässig:

- keinen `checksum`-Wert in `estab_schema_migrations` umschreiben,
- keinen Ledgerdatensatz löschen, damit eine DDL-Migration erneut läuft,
- kein Datenbank-Volume löschen oder durch eine leere Neuinstallation
  ersetzen,
- keine allgemeine Checksum-Ausnahme in den Runner einbauen.

Vor einer korrigierten Wiederholung wird ein konsistentes Backup erzeugt.
Anschließend werden ausschließlich das geprüfte Migrationsimage und der
One-shot-Service neu erstellt. Ein verbliebener Zustand `applying` ist von
einer reinen Prüfsummenabweichung zu unterscheiden und erfordert die
gesonderte Prüfung der bereits ausgeführten, nicht transaktionalen
DDL-Schritte.

Die SQL-Dateien sind wiederholbar. Bei einem regulären SQL-Fehler entfernt der
Runner ausschließlich seinen eigenen `applying`-Datensatz, sodass nach
fachlicher Korrektur erneut gestartet werden kann. Ein harter Prozessabbruch
kann bewusst einen `applying`-Datensatz hinterlassen; dieser Zustand ist
fail-closed und muss vor einer manuellen Änderung anhand von Backup,
Containerlog und bereits ausgeführten DDL-Schritten geprüft werden.

Migration 40 ist absichtlich fail-closed, berücksichtigt aber die
Nicht-Transaktionalität von `CREATE TABLE`: Ihre selbst angelegte Tabelle
trägt eine eindeutige Eigentumsmarkierung im Tabellenkommentar. Fehlt nach
einem Abbruch nur der Ledgerabschluss, darf ausschließlich eine leere
markierte Tabelle oder die exakt kanonisch gesetzte 20-Zellen-Matrix
weiterlaufen. Bereits abweichende Inhalte werden nicht zurückgesetzt. Eine
gleichnamige Tabelle ohne diese Markierung löst vor jeder Änderung eine
eindeutige Namenskollisionsmeldung aus. Vor einer manuellen Bereinigung müssen
Tabelleninhalt, Kommentar, Migrationstabelle und Backup gemeinsam geprüft
werden.

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

Ein Image-Upgrade und das Öffnen im aktuellen Layout schreiben persistierte
Vordrucke nicht um. Die geschützte Seite
`/4fach/vordrucke.php` öffnet abgeschlossene Nachrichten des aktiven Einsatzes
dennoch mit der Vorlage des neuen Images: Nach erneuter Einsatz- und
Datensatzprüfung entsteht ein aktueller PDF-Abzug im Speicher, während das
übernommene Archiv-PDF für Backup und Restore bytegleich bleibt. Ein
PDF-Vordruckreset ist für einen reinen Layoutwechsel deshalb nicht nötig. Nur
der ausdrücklich bestätigte administrative Vordruckreset kann die
Druckmarkierungen des aktiven Einsatzes zurücksetzen und dadurch bei einer
erneuten Erzeugung dessen Archivdateien ersetzen.

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
