# Datenbank-Basis

`init/10-schema.sql` bildet das in eStab 0.9.26c verwendete Basisschema für
MariaDB 11.8 ab. Das Migrationsimage kopiert genau diese kanonische Datei nach
`/opt/estab/schema/10-schema.sql`. Bei einem leeren `nv_*`-Namensraum bindet
der Runner ihre SHA-256-Prüfsumme vor dem ersten DDL an einen
`applying`-Datensatz in `estab_schema_baselines`. Ein harter Abbruch ist damit
von einem fremden Teilbestand unterscheidbar: nur dieselbe Baseline darf die
idempotente Initialisierung fortsetzen und nach allen 14 Tabellen auf
`applied` wechseln. Ein unprotokollierter teilweise vorhandener
`nv_*`-Namensraum ohne `nv_nachrichten` blockiert weiterhin fail-closed. Ein
Legacy-Schema mit Kerntabelle wird nicht durch die Fresh-Baseline ergänzt. Der
offizielle MariaDB-Container muss die Zieldatenbank vorher über
`MARIADB_DATABASE` angelegt haben.

Die Datei kann für isolierte Schemaarbeit weiterhin direkt gegen eine bereits
ausgewählte leere Datenbank ausgeführt werden. Compose bindet sie aber nicht
mehr nach `/docker-entrypoint-initdb.d`; damit enthält das veröffentlichbare
Migrationsimage sämtliche für eine pull-only Neuinstallation benötigten
Schemaartefakte.

Compose erzwingt im Server
`STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO`
und `NO_ENGINE_SUBSTITUTION`. Abweichende Daten werden damit nicht durch
lockere Defaults oder stillen Engine-Ersatz verdeckt.

Das Skript kann auf derselben, von ihm angelegten Datenbank mehrfach ausgeführt
werden: Tabellen werden mit `CREATE TABLE IF NOT EXISTS` angelegt, die 20 Felder
der Empfängermatrix (fünf Zeilen, vier Spalten) besitzen einen eindeutigen
Positionsschlüssel und werden mit `INSERT IGNORE` nur ergänzt. Die Belegung
entspricht der mit 0.9.26c ausgelieferten Standardmatrix einschließlich S2 als
Rotkopie-Ziel. Es ist jedoch bewusst **kein** allgemeines
Upgrade-Skript für beliebige vorhandene Altschemata; `IF NOT EXISTS` ändert
bereits existierende MyISAM-Tabellen nicht.

## Automatischer One-shot-Migrator

Compose startet `migrate` nach dem gesunden Datenbankdienst und vor `app`.
Der App-Service besitzt die Abhängigkeit
`condition: service_completed_successfully`; ein fehlerhafter oder
unvollständiger Migrationslauf lässt Apache deshalb aus.

Das eigene Image aus `Dockerfile.migrate` enthält den MariaDB-Client, das
Basisschema, `migrate.sh`, alle Dateien unter `migrations/` und `verify.sql`.
Der Runner:

1. liest das Root-Kennwort aus dem Compose-Secret in eine temporäre Datei mit
   Modus `0600`,
2. verbindet den Client über `--defaults-extra-file`, sodass kein Kennwort in
   Argumentliste oder Log erscheint,
3. prüft den `nv_*`-Namensraum, speichert bei einer leeren Installation
   Baseline-Dateiname, SHA-256 und Zustand vor dem ersten DDL und setzt einen
   unterbrochenen Lauf ausschließlich mit derselben Prüfsumme fort,
4. verarbeitet unveränderliche SQL-Dateien in sortierter
   Dateinamenreihenfolge,
5. speichert Dateiname, SHA-256, Zustand, Lauf-ID sowie Zeitpunkte in
   `estab_schema_migrations`,
6. überspringt nur exakt dieselbe bereits angewendete Prüfsumme,
7. blockiert bei Prüfsummenabweichung oder einem fremden/unterbrochenen
   `applying`-Zustand,
8. führt abschließend `verify.sql` aus und akzeptiert ausschließlich eine
   Ergebniszeile voller `1` ohne nachfolgende Abweichungszeile.

Ein regulär fehlgeschlagenes, idempotentes Upgrade-Skript entfernt nur seinen
eigenen `applying`-Datensatz und kann nach fachlicher Korrektur erneut laufen.
Ein harter Abbruch einer versionierten Upgrade-Migration bleibt absichtlich
fail-closed und erfordert Operatorprüfung. Der getrennte Fresh-Baseline-Zweig
ist dagegen aufgrund seines vorab gespeicherten Checksums und seiner
idempotenten SQL-Anweisungen automatisch wiederaufnehmbar. Bereits
veröffentlichte SQL-Dateien dürfen nie editiert werden; jede Änderung erhält
die nächste Versionsdatei.

Ausführen beziehungsweise erneut prüfen:

```sh
podman compose run --rm migrate
```

Vor dem ersten Lauf einer neuen Version auf einem gefüllten Volume ist ein
konsistentes Datenbank-/`4fdata`-Backup Pflicht.

Zur Kontrolle kann anschließend ausgeführt werden:

```sh
mariadb --database="$MARIADB_DATABASE" < /docker/db/verify.sql
```

Alle Spalten der ersten Ergebniszeile müssen `1` sein, die zweite Abfrage darf
keine Zeile liefern. `verify.sql` liest ausschließlich Metadaten und Daten.

Die Fassung wird mit dem festgelegten Multi-Arch-Digest von
`mariadb:11.8.8` validiert: Der selbsttragende Migrator initialisiert ein
leeres Datenbank-Volume. Der Integrationstest simuliert zusätzlich einen
harten Abbruch nach der ersten Basistabelle mit bereits gespeichertem
Baseline-Checksum, weist den erfolgreichen Wiederanlauf nach und prüft, dass
ein unprotokollierter fremder `nv_*`-Teilbestand blockiert bleibt. Ein
unmittelbar folgender Lauf verarbeitet nur die checksum-gebundenen
Migrationen. Sämtliche Struktur- und Matrixprüfwerte müssen jeweils `1` sein.
Repräsentative Teil-Inserts für `nv_nachrichten` sowie die dreispaltige
Anhang-Reservierung liefen unter `STRICT_TRANS_TABLES` erfolgreich und wurden
innerhalb einer Testtransaktion zurückgerollt.

## Versionierte Basismigrationen

`migrations/20-nullable-dates.sql` konvertiert alte
MySQL-Platzhalter `0000-00-00 00:00:00` verlustfrei zu `NULL` und macht die
betroffenen Spalten nullable. `migrations/30-runtime-schema.sql` gleicht
persistente ältere Containerdatenbanken an die Laufzeitanforderungen an:

- `nv_benutzer`: Kürzel 6, Passwort 255 sowie `ip`/`fwdip` 45 Zeichen,
- sechs Absender-/Bearbeiterfelder in `nv_nachrichten`: 6 Zeichen,
- `nv_anhang`: Kürzel 6, Dateiendung 16 und Session-ID 128 Zeichen,
- benannte Benutzer- und Anhangindizes einschließlich eindeutigem Dateinamen,
- InnoDB/`utf8mb4` für die unmittelbar migrierten Laufzeittabellen.

Vor dem eindeutigen Anhangindex zählt die Migration doppelte `filename`-
Gruppen. Jede Gruppe löst ein explizites SQL-Signal aus; es wird nichts
automatisch gelöscht oder umbenannt.

`migrations/40-recipient-matrix-standard.sql` legt zusätzlich genau eine
persistente Standard-Empfängermatrix mit 20 eindeutigen Positionen an. Sie
bewahrt Funktion, Rolle, Rotkopie und Autosichtung ohne generierten PHP-Code.
Die Migration markiert ausschließlich ihre eigene Tabelle per
Tabellenkommentar. Dadurch kann sie nach dem nicht transaktionalen
`CREATE TABLE` sowohl eine noch leere eigene Tabelle als auch den bereits
vollständig gesetzten kanonischen Seed sicher wiederaufnehmen. Abweichende
Inhalte werden niemals überschrieben. Eine fremde Tabelle gleichen Namens
ohne exakte Eigentumsmarkierung blockiert vor jeder Änderung. Der
Integrationstest beweist beide Wiederaufnahmepunkte, die unveränderte
Blockade manipulierter Inhalte und einer fremden Namenskollision sowie den
zellgenauen Vergleich aller 20 normalisierten Zellen mit der historischen
Sollbelegung.

Die Datumsmigration ist wiederholbar. Sie deaktiviert die Zero-Date-Modi nur
für ihre eigene Sitzung und stellt den vorherigen SQL-Modus danach wieder
her. Alle gültigen Zeitstempel bleiben bytegleich; auch `99_lstacc` wird
explizit zugewiesen, damit dessen `ON UPDATE`-Attribut keine historischen
Zeitwerte durch den Migrationszeitpunkt ersetzt. `verify.sql` prüft neben den
Defaults auch, dass in Nachrichten, Anhängen und BHP-50-Daten keine Zero Dates
mehr vorhanden sind.

## Begründete Abweichungen vom Legacy-Schema

| Legacy | Docker-Schema | Begründung |
| --- | --- | --- |
| MyISAM | InnoDB | Transaktionen, Crash-Recovery und konsistentes Container-Backup |
| `utf8` / teilweise `latin1` | `utf8mb4` mit `utf8mb4_unicode_ci` | Einheitliche, vollständige Unicode-Speicherung |
| `0000-00-00 00:00:00` | `NULL` | Mit `NO_ZERO_DATE` und Strict Mode gültig; `NULL` bedeutet fachlich „noch nicht gesetzt“ |
| `nv_nachrichten.00_lfd` nur als nicht-eindeutiger Key | Primary Key | Eindeutige Identität der Nachricht; für InnoDB die sinnvolle Cluster-ID |
| Anhänge ohne Defaults | Explizite Leerwerte bzw. `date = NULL` | Die Anwendung reserviert einen Dateinamen zunächst nur mit `filename`, `status` und `id`; dieses Legacy-Insertemuster muss unter Strict Mode weiter funktionieren |
| Anhang-Dateiname nur nicht-eindeutig indiziert | `UNIQUE uq_anhang_filename` | Atomare Reservierung und Retry verhindern, dass parallele Uploads denselben Namen wie `EL0001` erhalten |
| `fileext VARCHAR(3)`, `id VARCHAR(32)` | 16 bzw. 128 Zeichen | Mehrteilige Dateiendungen und heutige Session-IDs dürfen nicht still abgeschnitten werden |
| IPv4-Felder mit 15 Zeichen | 45 Zeichen | Rückwärtskompatibel und ausreichend für IPv6-Textdarstellung |
| `password VARCHAR(32)` mit Klartextwerten | `VARCHAR(255)` mit `password_hash()` | Ausreichend für aktuelle und künftige PHP-Standardhashes; Klartext wird bei erfolgreichem Login transparent migriert |
| ETB-/TBB-Titeltabellen erst bei Benutzung | Bereits initialisiert | Verhindert, dass der Legacy-Code nachträglich MyISAM-Tabellen ohne expliziten Zeichensatz erzeugt |

Die Namen und fachlichen Wertebereiche (`SET`, Statusfelder, Richtungen,
Prioritäten) bleiben unverändert. `nv_masterkategolink.msg` bleibt absichtlich
eindeutig: Der bestehende Code aktualisiert und löscht ausschließlich anhand
von `msg` und bildet damit genau eine Master-Kategorie je Nachricht ab. Es
werden keine Foreign Keys ergänzt, weil die Legacy-Löschpfade keine definierte
Cascade-Behandlung besitzen.

Das Schema erstellt weder Benutzer noch `GRANT`s und benötigt insbesondere
kein globales `FILE`-Recht. Der vom offiziellen Image über
`MARIADB_USER`/`MARIADB_PASSWORD` angelegte Benutzer benötigt nur Rechte auf
`MARIADB_DATABASE`.

Die Passwortspalte wird nach `CREATE TABLE IF NOT EXISTS` zusätzlich mit einem
idempotenten `ALTER TABLE ... MODIFY` verbreitert. Damit kann dasselbe Skript
auch eine bereits vorhandene 32-Zeichen-Spalte für die transparente
Passwortmigration vorbereiten. Anmeldeabfragen selbst stehen in `app/auth.php`
und verwenden ausschließlich `mysqli`-Prepared-Statements.

Anhangnamen werden in `app/attachment.php` innerhalb einer InnoDB-Transaktion
reserviert. Eine abgebrochene Reservierung kann unter Zeilensperre wiederverwendet
werden; andernfalls wird die höchste numerische Sequenz ermittelt und der nächste
Name eingefügt. Der eindeutige Index ist die letzte Race-Condition-Sperre;
Duplicate-Key-, Deadlock- und Lock-Timeout-Fälle werden mit einer neuen
Transaktion wiederholt. Vor dem Verschieben der Uploadbytes muss die aktuelle
Session ihre aktive Reservierung atomar beanspruchen. Nur genau dieser Claim
kann anschließend die validierten Metadaten finalisieren. Die unreferenzierten
historischen Direktendpunkte `4fach/upload.php` und `4fach/upload/upload.php`
sind deaktiviert; das vollständige Kernfeature läuft über `4fach/anhang.php`.
Die Zustandsfolge lautet:
`4 (frei) -> 8 (reserviert) -> 2 (beansprucht) -> 1 (fertig)`.
Abbruch oder Fehler setzen ausschließlich eigene unfertige Zustände `8`/`2`
auf `4` zurück; nach erfolgreichem Abschluss wird die nicht mehr benötigte
Session-ID aus dem Anhangdatensatz entfernt.

Die datenbankunabhängige Sicherheitsregression kann separat ausgeführt werden:

```sh
php tests/php/attachment_security.php
```

Sie prüft unter anderem Namens- und Metadatenvalidierung, HTML-Escaping, die
Retry-Klassifikation sowie als Quellcode-Invarianten Transaktion/Zeilensperren,
den besitzgebundenen Finalisierungsfilter, Prepared-Statement-only im aktiven
Anhangpfad und den eindeutigen Schemaschlüssel. `tests/static/run.sh` führt
diesen Test ebenfalls aus. Der tatsächliche Schlüssel im gestarteten
MariaDB-Container wird zusätzlich durch `attachment_filename_unique_ok` in
`verify.sql` geprüft; ohne den Wert `1` darf der parallele Uploadbetrieb nicht
freigegeben werden.

## PHP-Code und dynamische Tabellen

Ein Initialisierungsschema kann die benutzer- und funktionsbezogenen
`usr_*`-Tabellen eines vorhandenen Datenbestands nicht vorab aufzählen.
`4fach/db_operation.php` legt deshalb bei Anlage beziehungsweise Aktivierung
eines Funktionsbenutzers dessen sechs dynamische Tabellen neu an oder migriert
sie idempotent:

- MyISAM/latin1 wird zu InnoDB/`utf8mb4`,
- Zero-Date-Werte in `gelesen` und `erledigt` werden zu `NULL`,
- gültige Zeitstempel, Kategorien, doppelte Links und Mehrfachzuordnungen
  bleiben erhalten,
- Linktabellen erhalten einen Surrogat-Primärschlüssel,
- benannte Suchindizes werden nur bei Bedarf ergänzt,
- dynamische Identifier werden validiert und gequotet,
- der vorherige Session-SQL-Mode wird auch bei Fehlern wiederhergestellt.

Der echte MariaDB-Test `tests/integration/dynamic_tables.php` erzeugt dafür
bewusst sechs Legacy-Tabellen samt repräsentativen Daten, ruft die Migration
zweimal auf und prüft Engine, Collation, Defaults, Indizes, Daten- und
Duplikaterhalt, Strict Mode sowie abgewiesene Identifier-Angriffe. Seine
Fixture-Tabellen werden am Anfang, beim Shutdown und nach Erfolg entfernt.
Der vollständige Aufruf im wegwerfbaren Compose-Stack steht unter
[`docs/TESTS-UND-MONITORING.md`](../../docs/TESTS-UND-MONITORING.md).

Die Datums-Konvertierung in `app/datetime.php` behandelt sowohl SQL `NULL` als
auch importierte Zero Dates als „nicht gesetzt“. Warteschlangen verwenden
fachlich eindeutige `IS NULL`-/`IS NOT NULL`-Prädikate; PDF- und PNG-Backups
reichen leere Werte nicht mehr an String-Konverter weiter. Neben der
datenbankfreien Regression `tests/php/date_compatibility.php` wendet
`tests/integration/date_compatibility.php` die echte SQL-Migration zweimal auf
repräsentative temporäre Tabellen an.

Die historischen, im Webserver gesperrten Provisionierungsdateien
`4fadm/create_db.php` und `4fach/create_db.php` sind nicht autoritativ. Ihre
Datumsdefinitionen sind dennoch Strict-Mode-tauglich; im Container bleibt
ausschließlich die durch `migrate.sh` kontrollierte eingebettete Fassung von
`init/10-schema.sql` der freigegebene Initialisierungsweg.

Historische Anwendungspfade verwenden weiterhin `mysql_*`; im Container
werden sie durch die getestete `mysqli`-Kompatibilitätsschicht abgebildet. Der
sicherheitskritische Anmeldepfad nutzt direkt Prepared Statements und hängt
nicht von String-interpolierten Legacy-Abfragen ab.

Außerdem enthalten ältere Diagnosekommentare fest codierte Tabellennamen; die
ausgeführten Abfragen nutzen überwiegend die Namen aus `4fcfg/dbcfg.inc.php`.
Das Schema übernimmt genau diese konfigurierten `nv_*`-Namen.
