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

Die Einsatzmigration bildet deshalb eine unveränderliche Dreierfolge:

1. `45-global-incidents-prepare.sql` verlangt alle zehn operativen
   Basistabellen und die beiden erwarteten Zeitspalten. Anschließend entfernt
   sie vorübergehend deren `ON UPDATE CURRENT_TIMESTAMP`-Attribute und prüft
   den vorbereiteten Zustand.
2. `50-global-incidents.sql` bleibt bytegenau bei der bereits
   checksum-gebundenen veröffentlichten Fassung. Sie erzeugt die
   Einsatzdomäne und ordnet vorhandene operative Zeilen dem geschlossenen
   `LEGACY-IMPORT` zu. Ihr verbindlicher SHA-256 lautet
   `6732e9c87f0532fce41ee9a58658bf4888fdf7c2ced1ed6bad75a756d6e08edf`.
3. `55-global-incidents-finish.sql` akzeptiert für einen crash-sicheren
   Wiederanlauf nur die kanonisch vorbereiteten oder bereits
   wiederhergestellten Definitionen. Sie aktiviert die beiden automatischen
   Zeitattribute wieder und validiert den Endzustand.

Dadurch kann ein Legacy-Backfill weder einen unvollständigen operativen
Tabellenraum ergänzen noch `nv_nachrichten.99_lstacc` oder
`nv_bhp50.sich1_zeit` auf den Migrationszeitpunkt setzen. Eine Datenbank, die
Migration 50 bereits angewendet hat, führt beim nächsten Upgrade nur die noch
fehlenden Schritte 45 und 55 aus; 50 wird ausschließlich bei identischem
SHA-256 übersprungen. Readiness und `verify.sql` verlangen alle fünfzehn
Ledgerdatensätze.

`96-etb-duty-function.sql` löst die frühere globale Eindeutigkeit der
Fähigkeit auf und verwendet den bereits vorhandenen Primärschlüssel
`(funktion, faehigkeit)`. Dadurch besitzen S2 und die eigenständig
auswählbare Funktion ETB jeweils einen eigenen Schlüssel für
`EINSATZTAGEBUCH`; `LAGE_DOKUMENTATION` und die Rotkopie-Zuständigkeit bleiben
ausschließlich bei S2. Die Migration prüft Tabelle, vier Spalten,
Spaltenreihenfolge, altes oder neues ENUM, Primärschlüssel, Indizes und
Katalogzeilen exakt. Sie akzeptiert nur den kanonischen fünfzeiligen
Ausgangszustand, die von ihr selbst erzeugbaren DDL-Zwischenstände oder den
kanonischen siebenzeiligen Endzustand. Nach kontrollierter Behandlung eines
unterbrochenen Ledgerlaufs sind diese Zwischenstände idempotent
wiederaufnehmbar; gemischte Daten, fremde Indizes und ein abweichender
Primärschlüssel bleiben unverändert gesperrt. Die Schema-Verifikation verlangt
abschließend das vollständige neue ENUM, genau sieben Katalogzeilen, keinen
zusätzlichen Index und Version 96 unter insgesamt fünfzehn angewendeten
Migrationen.

Bei `Checksum mismatch for applied migration` werden zuerst Containerlog,
Image-Digest, die lokale Dateiprüfsumme und der betroffene Datensatz aus
`estab_schema_migrations` ausschließlich lesend verglichen. Der Ledger darf
weder gelöscht noch umgeschrieben und das Volume nicht durch eine
Neuinstallation ersetzt werden. Die bereits angewendete SQL-Datei wird aus
dem freigegebenen Commit beziehungsweise Image bytegenau wiederhergestellt;
jede gewünschte Änderung kommt in eine neue Versionsdatei. Der ausführliche
Ablauf steht unter
[`docs/MIGRATION-UND-UPGRADE.md`](../../docs/MIGRATION-UND-UPGRADE.md#prüfsummenabweichung-sicher-untersuchen).

Ausführen beziehungsweise erneut prüfen:

```sh
podman compose run --rm migrate
```

Vor dem ersten Lauf einer neuen Version auf einem gefüllten Volume ist ein
konsistentes Datenbank-/`4fdata`-Backup Pflicht.

Für eine davon getrennte, ausschließlich lesende Kontrolle gegen den laufenden
Datenbankcontainer kann die Repository-Datei direkt eingespielt werden. Das
temporäre Clientprofil verhindert, dass das Root-Kennwort in der Argumentliste
erscheint:

```sh
podman compose exec -T db sh -eu -c '
  umask 077
  option_file=$(mktemp /tmp/estab-verify.XXXXXX)
  cleanup() { rm -f -- "$option_file"; }
  trap cleanup EXIT HUP INT TERM
  password=$(tr -d "\r\n" < /run/secrets/estab_db_root_password)
  escaped_password=$(printf "%s" "$password" |
    sed -e "s/\\\\/\\\\\\\\/g" -e "s/\"/\\\\\"/g")
  unset password
  printf "[client]\nuser=root\npassword=\"%s\"\n" \
    "$escaped_password" > "$option_file"
  unset escaped_password
  mariadb --defaults-extra-file="$option_file" \
    --batch --skip-column-names --raw "$MARIADB_DATABASE"
' < docker/db/verify.sql
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
- Migration 98: getrennte, amtliche Felder für Rufnummer der Gegenstelle
  (`11_rufnummer`) und Betreff (`12_betreff`); vorhandene Nachrichtenwerte
  werden nicht umgeschrieben und beide neuen Felder starten leer,
- Migration 99: der bisherige Volltextindex nur über `12_inhalt` wird durch
  `ft_nachrichten_suche` über Gegenstelle, Anschrift, Rufnummer, Betreff,
  Inhalt, Absendereinheit und Absenderfunktion ersetzt. Zwei
  einsatzgebundene zusammengesetzte Indizes beschleunigen Status-/Zeit- sowie
  Richtungs-/Nachweisnummernfilter. Jeder gleichnamige Fremdindex mit
  abweichender Art, Spaltenfolge oder Präfixdefinition blockiert vor der
  ersten Änderung; unterbrochene kanonische DDL-Phasen sind wiederaufnehmbar.
  Die unveränderliche Fresh-Baseline behält ihren veröffentlichten
  Einspaltenindex; Migration 99 ersetzt ihn auch bei einer Neuinstallation.
  Die beiden Einsatzindizes entstehen ebenfalls erst dort, nachdem Migration
  50 die Spalte `einsatz_id` bereitgestellt hat,
- Migration 100: `nv_benutzer.estab_letzte_aktivitaet` speichert als nullable
  `DATETIME(6)` in UTC die letzte echte Browserinteraktion. Der Index
  `idx_benutzer_presence (aktiv, estab_gesperrt,
  estab_letzte_aktivitaet)` trägt die zentrale Aktiv-/Inaktivübersicht und
  die Bereinigung abgelaufener Sitzungen. Gleichnamige fremde Spalten oder
  Indizes blockieren fail-closed. Bereits aktive Legacy-SIDs besitzen keinen
  belegbaren Zeitpunkt und werden beim Upgrade einmalig samt IP-Metadaten
  widerrufen; neue Logins setzen den Zeitstempel regulär,
- `nv_anhang`: Kürzel 6, Dateiendung 16 und Session-ID 128 Zeichen,
- Migration 95: neue Anhangreservierungen sind
  `integrity_required=1`; der Übergang zum finalen Status verlangt
  unveränderlichen SHA-256, Bytezahl und Erfassungszeit. Nur beim Upgrade
  bereits vorhandene Zeilen tragen den Legacy-Marker `0`. Dieser Wert wird
  beim `ADD COLUMN` materialisiert; anschließend wechselt nur der
  Spalten-Standard auf `1`. Ein operatives Backfill-`UPDATE`, das den
  Einsatz-Guard für geschlossene Importdaten umgehen müsste, findet nicht
  statt. Da MariaDB Schemaänderungen einzeln festschreibt, besitzt jede der
  vier Spalten eine eindeutige Migrationsmarkierung. Ein Wiederanlauf
  akzeptiert ausschließlich die kanonischen Spaltenpräfixe, den vollständigen
  Constraint-Satz und eigene Triggerdefinitionen; fremde Namenskollisionen
  blockieren. Der Integrationstest unterbricht die Migration absichtlich nach
  der ersten Spalte und beweist die vollständige, idempotente Fortsetzung,
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

`migrations/45-global-incidents-prepare.sql`,
`migrations/50-global-incidents.sql` und
`migrations/55-global-incidents-finish.sql` führen die globale
Einsatzzuordnung als unveränderliche Vorbereitungs-, Domänen- und
Abschlussfolge ein. Migration 45 blockiert fehlende operative Tabellen vor
der ersten Einsatz-DDL und deaktiviert die zwei automatischen
Zeitstempeländerungen. Migration 50 bleibt mit ihrer erstmals angewendeten
Prüfsumme unverändert. Migration 55 stellt die kanonischen Attribute wieder
her. `migrations/70-user-account-blocking.sql` ergänzt anschließend die
dauerhafte, kollisionsgeprüfte Kontosperre.

Der aktuelle Ledger umfasst siebzehn checksumgebundene Migrationen bis
`migrations/111-logbook-shift-assignment.sql`. Migration 110 führt die
einsatzlokalen ETB-/TTB-Nummern, Buchköpfe, strukturierten TBB-Inhalt,
Append-only-Regeln und zehnjährige Aufbewahrungsuntergrenze ein. Migration 111
ergänzt nullable Schicht-/Schreiberfremdschlüssel für beide Bücher und die
optionale ETB-Bearbeitungszuordnung. Sie reserviert außerdem neue TBB-Zeilen
des Typs `nachricht` für systemgenerierte, kanonisch verknüpfte Transporte und
erzwingt einen gemeinsamen Abschlusszeitpunkt für bestätigte Übergabe,
abgeschlossenen Übergabenachweis und Schichtwechsel; Initiierungszeit und Bestätigungszeit bleiben
getrennt. Neue manuelle Zeilen müssen Schicht und
menschliche Schreiberbesetzung belegen. Der Trigger verlangt eine aktive
einsatzgleiche Schicht, eine angenommene Besetzung, passende Konto-/Kürzel-/
Funktionsidentität und ein aktives, ungesperrtes Konto. Automatische
Systemzeilen tragen die Schicht ohne menschlichen Schreiber. Der
ETB-Zuordnungssnapshot wird nur aus einer angenommenen Besetzung derselben
aktiven Schicht mit ungesperrtem Konto erzeugt; Online-/Sitzungsstatus sind
dafür keine fachlichen Gültigkeitsmerkmale. Er kann nicht als freier
Browsertext eingeschleust werden. Neue ETB-Referenzen sind kanonische
positive lokale Nummern vorhandener Einträge desselben Einsatzes;
Korrekturreferenz und intern gebundenes Original müssen übereinstimmen.
Historische Zeilen bleiben in den neuen Provenienzfeldern bewusst `NULL`, weil
die Migration keine nicht belegbare Herkunft erfindet und freie
Bestandsreferenzen nicht umdeutet.

`verify.sql` und die Laufzeit-Readiness verlangen alle siebzehn Ledgerzeilen,
die sechs neuen Spalten, ihre kanonischen Indexe und Fremdschlüssel sowie die
erweiterten ETB-/TTB-Insert-Trigger. Ein aktueller Migratorlauf endet erst nach
`Post-migration schema verification passed` und
`All schema migrations are applied` erfolgreich.

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
Prioritäten) bleiben unverändert. Für `nv_nachrichten.09_vorrangstufe`
übersetzt die Anwendung deshalb weiterhin die internen Werte `sss`, `bbb` und
`aaa` nach **Sofort**, **Blitz** und **Staatsnot**; leer sowie das historische
`eee` bedeuten **keine**. Neue Formulare bieten `eee` nicht mehr an. Die
unveränderte Speicherung ist erforderlich, damit vorhandene
Nachrichtenereignishashes und rohe Tabellen-/CSV-Exporte reproduzierbar
bleiben. `nv_masterkategolink.msg` bleibt absichtlich
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
