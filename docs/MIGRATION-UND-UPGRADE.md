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

Während einer erstmaligen oder aus `applying` wiederaufgenommenen Baseline
entsteht zusätzlich der Datensatz
`114-self-registration-fresh-default` in `estab_schema_baselines`. Sein
Checksum ist exakt die SHA-256 der unveränderten Migration 114. Der Marker
bleibt bis hinter allen dreiundzwanzig Migrationen auf `applying`. Dann setzt ein
einziges atomisches InnoDB-Multi-Table-Update ausschließlich die noch pristine
Richtlinienzeile `ENVIRONMENT/NULL/0/migration-114` auf
`DISABLED/NULL/1/fresh-install` und gleichzeitig den Marker auf `applied`.
Schema-Verifikation und App-Start liegen danach. Ein Prozessabbruch davor ist
idempotent wiederaufnehmbar; eine abweichende Marker-Prüfsumme, ein Marker ohne
zugehörige angewendete Baseline oder eine nicht pristine Zeile bei
`applying` blockieren fail-closed.

Die Abwesenheit dieses Markers wird absichtlich nicht nachträglich
„repariert“: Ein echtes Legacy-Upgrade und eine frühere Neuinstallation ohne
Marker behalten den Upgrade-Kompatibilitätsmodus `ENVIRONMENT`. So ändert das
neue Sicherheitsverhalten keine bestehende Installation und
`ESTAB_ALLOW_SELF_REGISTRATION` kann nur dort noch den dokumentierten
Upgrade-Startwert liefern.

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
| `docker/db/migrations/80-dv-evidence-retention.sql` | ergänzte ursprünglich den unveränderbaren Nachrichten- und ETB-Nachweis, den formalen Einsatzabschluss, eine Mindestaufbewahrung von einem Jahr und eine zusätzliche Aufbewahrungssperre; Migration 110 hebt diese Untergrenze anschließend verbindlich auf zehn Jahre an |
| `docker/db/migrations/94-dv-organisational-controls.sql` | ergänzt Dienstschichten und Mehrfachfunktionen, den versionierten S6-Fernmeldeplan, den vollständigen Melderlauf sowie eine verkettete Ereignisspur dieser Betriebsabläufe; zugleich wird Autosichtung verbindlich deaktiviert und S2 als Rotkopie-/Dokumentationsfähigkeit normalisiert |
| `docker/db/migrations/95-attachment-ingest-integrity.sql` | markiert beim Upgrade vorhandene Anhänge ausdrücklich als nicht rückwirkend belegbaren Legacy-Bestand und verlangt für jeden danach finalisierten Anhang einen unveränderlichen SHA-256-/Größen-/Serverzeit-Nachweis; Trigger verhindern neue Legacy-Markierungen, Herabstufung und nachträgliche Beweisänderung |
| `docker/db/migrations/96-etb-duty-function.sql` | führt die eigenständig auswählbare ETB-Dienstfunktion ein, ordnet die Fähigkeit `EINSATZTAGEBUCH` sowohl S2 als auch ETB über den zusammengesetzten Schlüssel `(funktion, faehigkeit)` zu und belässt Rotkopie sowie `LAGE_DOKUMENTATION` ausschließlich bei S2 |
| `docker/db/migrations/97-incident-command-post-name.sql` | ergänzt den einsatzbezogenen, nullable Führungsstellennamen mit eindeutiger Eigentumsmarkierung; vorhandene Einsätze bleiben absichtlich `NULL`, weil Einsatzname, Organisation und Einsatzleitung keine belegbaren historischen Ersatzwerte sind |
| `docker/db/migrations/98-official-message-form-fields.sql` | ergänzt die amtlichen, getrennt persistierten Nachrichtenvordruckfelder `11_rufnummer` und `12_betreff`; Bestandsnachrichten behalten alle vorhandenen Werte und erhalten für beide neuen Angaben ausschließlich den leeren Standardwert |
| `docker/db/migrations/99-message-list-search.sql` | ersetzt den früheren Inhalts-Volltextindex durch den siebenfeldrigen Suchindex und ergänzt die beiden einsatzgebundenen BTREE-Indizes für skalierbare Meldungslisten |
| `docker/db/migrations/100-session-presence.sql` | ergänzt den UTC-Zeitstempel der letzten echten Browserinteraktion und den Präsenzindex; bereits aktive Legacy-SIDs ohne belegbaren Zeitpunkt werden beim Upgrade einmalig widerrufen |
| `docker/db/migrations/110-etb-tbb-rules.sql` | ergänzt einsatzlokale fortlaufende ETB-/TBB-Nummern samt exakt zwei vorab angelegten und gesperrten Buchköpfen je Einsatz, strukturierte TBB-Felder und Bezüge, den eindeutigen ETB-Anhangsbezug, Append-only-/Korrekturregeln, Erweiterungen aktiver Schichten mit Mehrfachbesetzung der Fernmelderfunktion, die zehnjährige Aufbewahrungsuntergrenze und den deterministischen Legacy-Backfill |
| `docker/db/migrations/111-logbook-shift-assignment.sql` | ergänzt nullable Schicht- und Schreiberprovenienz für ETB/TBB sowie die optionale ETB-Bearbeitungszuordnung mit unveränderlichem Snapshot; neue Zeilen werden durch Fremdschlüssel und Insert-Trigger geprüft, der Besetzungs-Update-Trigger verhindert eine verspätete ETB-Annahme mit Schreiberwechsel in der aktiven Schicht, historische Zeilen bleiben mangels belegbarer Herkunft `NULL` |
| `docker/db/migrations/112-optional-access-shifts.sql` | ergänzt optionale einsatzgebundene Zugangsschichten und Kontenzuordnungen; ersetzt den abschließenden ETB-/TBB-Triggervertrag durch festen Funktions-/Rollenbezug und aktiven Einsatz ohne Pflicht zu Dienstschicht oder Besetzungs-ID; ältere formale Schichtdaten bleiben historische Evidenz |
| `docker/db/migrations/113-password-policy.sql` | ergänzt genau eine revisionsgesicherte globale Kennwortrichtlinie für künftig gesetzte Funktionskonto-Kennwörter; Standard sind mindestens 12 Unicode-Codepoints ohne verpflichtende Zeichenklasse, die konfigurierbare Mindestlänge liegt zwischen 8 und 128 Unicode-Codepoints und optionale Unicode-Groß-/Titlecase-/Kleinbuchstaben, Ziffern und Sonderzeichen können verlangt werden; Unicode-Steuerzeichen sind verboten, Formatzeichen einschließlich ZWJ erlaubt; neue und geänderte Kennwörter werden mit Argon2id gespeichert, serverseitig gelten höchstens 1024 UTF-8-Bytes, im Browserfeld 1024 Eingabeeinheiten und eine exakte JavaScript-Codepointzählung bei verbindlicher Serverprüfung; Klartext und eindeutig verifizierbare Alt-Hashes werden nach erfolgreichem Login migriert, bcrypt nur bei einem eingegebenen Kennwort unter 72 UTF-8-Bytes, während ein ambivalenter längerer bcrypt-Alt-Hash bis zum administrativen Reset unverändert bleibt; stärkere oder gemischte Argon2id-Kosten werden nicht zurückgestuft, vorhandene Sitzungen bleiben unverändert |
| `docker/db/migrations/114-self-registration-policy.sql` | ergänzt die revisionsgesicherte Singleton-Freigabe für öffentliche Kontoanlage mit `DISABLED`, `PERMANENT` und DB-UTC-befristetem `UNTIL`; die unveränderte SQL-Datei setzt aus Upgrade-Kompatibilität zunächst `ENVIRONMENT`; nur ein im selben neuen Baseline-Lauf checksumgebunden angelegter Fresh-Marker autorisiert anschließend die atomare Umstellung der pristine Zeile auf `DISABLED/NULL/1/fresh-install`; echte Upgrades und frühere markerlose Neuinstallationen behalten `ENVIRONMENT`, bis die erste administrative Auswahl die Datenbank autoritativ macht; bestehende Konten, Kennwörter und Sitzungen bleiben unverändert |
| `docker/db/migrations/115-incident-permission-mode.sql` | ergänzt den pro Einsatz gespeicherten Modus `STRICT`/`LOOSE` mit `STRICT` als Default für Bestand und Neuanlage; Guard-Trigger erkennen unmarkierte Legacy-DML und kombinierte Einsatzänderungen, während der bestätigte Anwendungsweg Revision und Audit bindet; sechs modebewusste Fachtrigger bewahren in `STRICT` die bisherigen Funktions-/Rollengrenzen und lockern in `LOOSE` ausschließlich diese Schreibprüfungen, während konkrete aktive und ungesperrte Kontenidentität, aktiver offener Einsatz, Zustands-, Beziehungs-, Integritäts- und Append-only-Regeln bestehen bleiben |
| `docker/db/migrations/116-standard-categories.sql` | ergänzt ausschließlich bei einer vollständig leeren globalen Kategorienliste die editier- und löschbaren Vorgaben `Allgemein` sowie `EA1` bis `EA6`; ein bereits vorhandener Betreiberkatalog bleibt vollständig unverändert |
| `docker/db/migrations/117-telecom-draft-discard.sql` | erweitert den Fernmeldeplan-Zustandsautomaten um das kontrollierte Archivieren eines inhaltlich unveränderten Entwurfs als `ERSETZT`; Freigabedaten bleiben leer und vorhandene Wege werden nicht gelöscht; unveränderliche Felder werden NULL-sicher verglichen und eine Planfreigabe ist nur im gespeicherten Gültigkeitsfenster zulässig |

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
ausschließlich den zweispaltigen Primärschlüssel und alle dreiundzwanzig
angewendeten Migrationen einschließlich Version 117.

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

Migration 110 verlangt die von den Einsatz-, ETB-Nachweis- und
Dienstschichtmigrationen erzeugten Vorgängerstrukturen. Sie reserviert den
Namensraum für `nv_logbuch_koepfe`, lokale Buchnummern, strukturierte
TBB-Spalten, Indexe, Fremdschlüssel und Trigger und bricht bei einer
gleichnamigen fremden oder nur teilweise passenden Definition vor der nächsten
Änderung ab. Wiederanläufe akzeptieren ausschließlich fehlende, klar als eigen
markierte Zwischenstände oder den vollständig kanonischen Endstand.

Beim Daten-Backfill gilt:

- ETB und TBB erhalten je Einsatz getrennte `estab_book_lfd`-Nummern ab 1.
  Die Reihenfolge wird deterministisch nach der vorhandenen Erfassungszeit und
  danach dem stabilen globalen Legacy-Primärschlüssel gebildet.
- Der jeweils nächste Wert wird in `nv_logbuch_koepfe` rekonstruiert. Für
  jeden vorhandenen Einsatz müssen danach exakt zwei Zeilen bestehen, `ETB`
  und `TTB`; zusätzliche oder fehlende Köpfe blockieren den Abschluss der
  Migration.
- Der neue `AFTER INSERT`-Trigger auf `nv_einsaetze` legt für jeden später
  erzeugten Einsatz im selben Statement `ETB:1` und `TTB:1` an. ETB-/TBB-
  Inserts sperren und erhöhen nur den vorhandenen Kopf. Sie besitzen bewusst
  keinen konkurrierenden „falls fehlt: anlegen“-Pfad; ein beschädigter Kopf
  führt zu einem expliziten Fehler, ohne Eintrag oder Nummernverbrauch.
- Diese Zeilensperre ist mit der unveränderten MariaDB-Standardisolation
  `REPEATABLE READ` ausgelegt. Die Migration schaltet Snapshot-Isolation nicht
  ab; der Export verwendet weiterhin eine explizite konsistente
  Read-only-Snapshot-Transaktion.
- Historische TBB-Zeilen werden als `legacy_import` gekennzeichnet. Ihre
  bisherige Aktion und Bemerkung bleiben sichtbar und werden zusätzlich ohne
  fachliche Umdeutung in den strukturierten Betriebsbereich übernommen.
- TBB-Ereignis- und Erfassungszeit werden aus der vorhandenen `tbb_time`
  initialisiert. Die Migration erfindet keine damals nicht gespeicherten
  Personal-, Kanal-, Nachrichten- oder Quittungsdaten.
- Bereits vorhandene ETB-Anhangsbezüge werden vor der Indexphase geprüft.
  Verweist derselbe Anhang auf mehrere ETB-Zeilen, bricht Migration 110 mit
  `duplicate ETB attachment link` ab. Sie löscht keine Verknüpfung und wählt
  keinen vermeintlichen Gewinner. Der anschließende eindeutige Index
  `uq_etb_attachment_id` erzwingt dieselbe Regel für alle neuen Einträge.
- Der Dienstbesetzungs-Trigger erlaubt bei einer aktiven Schicht eine bisher
  nicht belegte Funktion und mehrere unterschiedliche Besetzungen der
  Fernmelderfunktion. Für
  jede andere Funktion blockiert bereits eine frühere Zuweisung in derselben
  aktiven Schicht eine vermeintliche Ersetzung; dafür ist die
  Schichtübergabe vorgesehen.

Nach dem Backfill erzwingen eindeutige `(einsatz_id, estab_book_lfd)`-Indexe
die lokale Nummer, Fremdschlüssel einsatzsichere Nachrichten- und
Korrekturbezüge und Trigger die zulässigen ETB-Kennzeichen beziehungsweise
TBB-Eintragsarten. `UPDATE` und `DELETE` werden für beide Bücher abgewiesen;
eine Berichtigung wird als neuer, direkt auf den Originaleintrag verweisender
Datensatz angelegt. Bereits formal geschlossene Einsätze werden auf mindestens
zehn Jahre ab `estab_closed_at` verlängert, ohne ein späteres bestehendes Ende
zu verkürzen. Der neue Abschluss-Trigger verhindert anschließend jede kürzere
Frist.

Der aktuelle Anwendungs-Preflight verlangt keine frühere Schichtaktivierung
und keine schichtbezogenen Eröffnungszeilen. Historische formale
Dienstschichten oder Besetzungen blockieren den Einsatzabschluss nicht; echte
fachliche Blocker wie offene Nachrichten, Melderläufe oder fehlerhafte Anhänge
bleiben wirksam.

Die Anwendung ergänzt keine historischen Tatsachen. Neue Bücher können ohne
Dienstschicht mit ihrer ersten fachlichen Zeile beginnen. Bestandszeilen
erhalten lokale Nummern, aber keinen nachträglich erfundenen Pflichtkopf, keine
Besetzung und keine Quittung.

Migration 111 ergänzt an `nv_etb` die nullable Spalten `estab_shift_id`,
`estab_writer_assignment_id`, `estab_assignee_assignment_id` und
`estab_assignment`; `nv_tbb` erhält `estab_shift_id` und
`estab_writer_assignment_id`. Einsatz-/Schicht- und
Besetzungsfremdschlüssel verwenden `RESTRICT`, die einsatz- und
schichtbezogenen Buchindexe tragen die PDF-Auswahl. Gleichnamige fremde
Spalten, Indexe, Fremdschlüssel oder nicht kanonische Trigger blockieren vor
der Übernahme; eigene DDL-Zwischenstände sind wiederaufnehmbar.

Zwei zusätzliche Trigger binden bei neuen Übergaben den Abschlussdatensatz,
die Bestätigungsanfrage und beide Schichtwechsel an denselben von der
Anwendung einmal gelesenen Datenbankzeitpunkt. Die frühere Initiierungszeit
bleibt davon getrennt als tatsächliche Übergabezeit erhalten. Ein fremder
gleichnamiger Zeittrigger blockiert die Migration ebenso wie die übrigen
Objektkollisionen.

Der folgende Absatz beschreibt den von Migration 111 damals installierten und
durch Migration 112 ersetzten Zwischenstand: Neue manuelle Zeilen benötigen Schicht und Schreiberbesetzung derselben
Schicht. Der Insert-Trigger verlangt zusätzlich eine aktive Schicht, eine
angenommene Schreiberbesetzung, übereinstimmende Benutzer-/Kürzel-/
Funktionsidentität und ein aktives, ungesperrtes Konto; für ETB ist die
Funktion ETB oder S2, für TBB die Funktion Fernmelder. Automatische Zeilen benötigen ebenfalls die
Schicht, müssen die menschliche Schreiber-ID aber leer lassen. Eine optionale
ETB-Zuordnung darf nur auf eine angenommene Besetzung derselben aktiven Schicht
mit ungesperrtem Konto zeigen. Ihr Online-/Präsenzstatus ist kein fachliches
Gültigkeitsmerkmal. Der Trigger ignoriert einen behaupteten Browsertext und
erzeugt den Snapshot
`Funktion (Rolle): Name [Kürzel]` aus den referenzierten Daten. Die Migration
führt bewusst kein historisches Provenienz-Backfill aus: Alle neuen Felder
bleiben bei vorhandenen ETB-/TTB-Zeilen `NULL`, weil weder Dienstschicht noch
schreibende Person rückwirkend beweisbar sind.

Migration 112 ersetzt diese abschließenden Insert-Trigger, ohne Migration 111
zu verändern. Aktuell benötigen neue Zeilen einen aktiven Einsatz, ein
aktives ungesperrtes Konto und die feste fachlich passende Funktion/Rolle:
ETB durch `ETB/Stab` oder `S2/Stab`, TTB durch die Funktion `Fernmelder`. Eine aktive
Dienstschicht, angenommene Besetzung und Besetzungs-ID sind nicht erforderlich;
die Legacy-Provenienzfelder dürfen `NULL` bleiben. Zugangsschichten werden
nicht als Logbuchprovenienz verwendet.

Die beiden neuen Tabellen bilden optionale Zugangsgruppen. Unzugeordnete
Konten sind erlaubt, Mehrfachzuordnungen folgen OR-Semantik. Aktivieren erzeugt
keine Sitzung, Deaktivieren kann Sitzungen widerrufen. Die manuelle
Kontosperre bleibt unabhängig und vorrangig. Historische Tabellen für formale
Dienstschichten, Besetzungen und Übergaben bleiben unverändert exportierbar.

Migration 113 legt `nv_kennwortrichtlinie` nur an, wenn kein fremdes Objekt
diesen Namen belegt. Die eigene InnoDB-/`utf8mb4_unicode_ci`-Tabelle besitzt
genau eine Zeile mit `singleton_id = 1`, einer Mindestlänge zwischen 8 und 128,
vier booleschen Zeichenklassen, monotoner Revision, UTC-Änderungszeit und
Basic-Auth-Akteur. Der kanonische Anfangswert `12/0/0/0/0` übernimmt den
bisherigen Kontoanlage- und Resetvertrag ohne eine künstliche Verschärfung.
Ein idempotenter Wiederanlauf akzeptiert ausschließlich die vollständig
markierte eigene Tabelle und Zeile; abweichende Spalten, Constraints,
Tabellenkommentare oder Werte blockieren.

Die Migration schreibt und prüft keine bestehenden Kennwörter. Ihre
Richtlinie wirkt erst, wenn ein Administrator danach ein Konto anlegt oder ein
Kennwort zurücksetzt beziehungsweise wenn die ausdrücklich aktivierte
Selbstregistrierung einen neuen Hash erzeugt. Ein Bestandslogin prüft die neue
Richtlinie nicht rückwirkend. Ein Klartextwert oder anderer eindeutig
verifizierbarer Alt-Hash wird erst nach erfolgreicher Anmeldung auf Argon2id
umgestellt. bcrypt wird nur bei einem eingegebenen Kennwort unter 72 UTF-8-Bytes
automatisch migriert; bei 72 oder mehr Bytes bleibt er wegen der
Suffixambiguität unverändert und benötigt für Argon2id einen administrativen
Reset. Bereits stärkere oder gemischte Argon2id-Kosten werden nicht auf
Standardwerte zurückgestuft; nur vollständig schwächere Profile werden
hochgestuft.
Sitzungsstatus und separates HTTP-Basic-Secret bleiben unverändert.
`verify.sql` und die Laufzeit-Readiness prüfen Tabelle,
Singleton-Zeile und Grenzen.

Migration 114 ergänzt entsprechend die eigene, kollisionsgeprüfte Tabelle
`nv_selbstregistrierung`. Ihr Startmodus `ENVIRONMENT` wahrt eine bisher
ausdrücklich geöffnete Installation beim Upgrade. Nach der ersten Adminaktion
sind nur `DISABLED`, `PERMANENT` oder `UNTIL` samt UTC-Endzeit, Revision und
Audit-Akteur maßgeblich. `verify.sql` und die Laufzeit-Readiness prüfen beide
Singleton-Tabellen und alle dreiundzwanzig Ledgerzeilen gemeinsam.

Bei einer vom aktuellen Runner selbst begonnenen Neuinstallation ist dagegen
der checksumgebundene Baseline-Marker maßgeblich: Solange Migration 114 noch
pristine ist, werden Marker und Richtlinie nach der letzten Migration in
derselben Datenbankanweisung auf `applied` beziehungsweise `DISABLED`
gestellt. Ein Zweitlauf akzeptiert den angewendeten Marker nur zusammen mit
einem nicht mehr auf `ENVIRONMENT` stehenden, revisionsfortgeschrittenen
Richtlinienzustand. Die SQL-Datei und ihre zwanzigste Ledger-Prüfsumme werden
dabei nicht verändert.

Migration 115 ergänzt danach die Einsatzzeile um
`estab_permission_mode ENUM('STRICT','LOOSE') CHARACTER SET ascii COLLATE
ascii_bin NOT NULL DEFAULT 'STRICT'`. Weil der Default beim `ADD COLUMN`
materialisiert wird, bleiben alle historischen Einsätze sowie neue Einsätze
ohne ausdrückliche Auswahl streng. Die Migration schreibt keine bestehenden
Fachdaten um. Gleichnamige fremde Spalten, Guard-Trigger oder nicht eindeutig
erkannte Vorgängertrigger blockieren vor einer Übernahme; migrationseigene
DDL-Zwischenstände bleiben wiederanlauffähig.

Zwei Guard-Trigger weisen unmarkierte Legacy-DML und eine mit anderen
Einsatzfeldern kombinierte Modusänderung ab: `LOOSE` darf bei der
Einsatzanlage nur innerhalb des eng markierten Anwendungswegs gesetzt werden,
und ein bestehender Modus darf dort nur für die gesperrte konkrete Einsatz-ID
geändert werden. Die Anwendung verlangt dafür
Basic Auth, Session-CSRF, erwarteten Altmodus, unveränderte globale Revision
und bei `LOOSE` eine ausdrückliche Bestätigung; die Änderung und ihr
Vorher-/Nachher-Audit committen gemeinsam.

Die connection-lokalen SQL-Marker sind ein Datenkonsistenzvertrag zwischen
Anwendung und Triggern, keine Authentisierungsgrenze für einen Principal mit
beliebigen SQL-Rechten: Ein solcher Principal kann Sessionvariablen selbst
setzen und gilt deshalb als Teil der vertrauenswürdigen Betriebsumgebung.
Datenbank-Zugangsdaten dürfen nicht an Bedienkonten oder Drittprozesse
weitergegeben werden. Fachliche Modusänderungen erfolgen ausschließlich über
die Administration; wer absichtlich mit privilegierten SQL-Rechten eingreift,
verlässt den nachgewiesenen Betriebs- und Auditpfad.

Migration 116 prüft vor der ersten Datenänderung fail-closed, dass
`nv_masterkatego` als erwartete InnoDB-/`utf8mb4_unicode_ci`-Basistabelle mit
genau den drei kanonischen Spalten vorliegt. Nur wenn die Tabelle vollständig
leer ist, schreibt eine Transaktion `Allgemein` sowie `EA1` bis `EA6`; die
Beschreibungen erläutern `EA` als Einsatzabschnitt und verlangen die Anpassung
an die konkrete Einsatzorganisation. `Allgemein` ist für Meldungen ohne
Zuordnung zu einem Einsatzabschnitt vorgesehen. Schon eine vorhandene
Kategorie macht den gesamten Katalog betreibergeführt, sodass weder Zeilen
ergänzt noch Beschreibungen überschrieben werden. Die Vorgaben bleiben
normale globale Kategorien und können von den dafür berechtigten Funktionen
geändert oder gelöscht werden. Da die Saat nur in der checksumgebundenen
Einmalmigration liegt, werden gelöschte Vorgaben weder bei einer
Einsatzanlage noch bei Anmeldung oder Seitenaufruf erneut erzeugt.

Migration 117 ersetzt ausschließlich den geschützten Update-Trigger des
Fernmeldeplankopfs. Sie erlaubt zusätzlich `ENTWURF -> ERSETZT`, jedoch nur wenn
sämtliche fachlichen Kopf- und Freigabefelder unverändert bleiben. Die
Anwendung schreibt dazu ein hashverkettetes `plan_draft_discarded`-Ereignis;
die bestehenden Eintragstrigger verhindern anschließend jede Änderung oder
Löschung der archivierten Wege. So blockiert ein aufgegebener oder sicher als
veraltet erkannter Entwurf keine neue Bearbeitung des aktiven Plans. Alle
Unveränderlichkeitsvergleiche verwenden NULL-sichere Gleichheit; insbesondere
kann ein gesetztes `freigegeben_von` beim Ersetzen einer aktiven Fassung nicht
durch `NULL` aus der Evidenz entfernt werden. Nullable Bemerkungen werden dabei
binär statt mit der sprachabhängigen Tabellencollation verglichen, sodass auch
eine reine Änderung von Groß-/Kleinschreibung oder Akzenten scheitert. Auch eine direkte
`ENTWURF -> AKTIV`-Änderung scheitert vor dem Zustandswechsel, wenn die
Datenbankzeit vor `gueltig_ab` oder nach `gueltig_bis`
liegt. Der Anwendungsweg prüft dieselbe Grenze vor dem Ersetzen der bisherigen
Fassung und gibt einen korrigierbaren deutschen Hinweis aus. Weil beide
Gültigkeitsspalten `DATETIME(0)` sind, vergleichen Anwendung und Trigger mit
derselben vollen Datenbanksekunde; die gespeicherte Endsekunde bleibt damit
vollständig inklusive. Plananlage und
Kopfdatenänderung protokollieren ausschließlich die bereits einsatzbezogen
gespeicherten Kopfdaten als Initial- beziehungsweise Vorher-/Nachher-Snapshot;
Zugangsdaten und Sitzungswerte werden nicht in die Ereignisdetails übernommen.

Sechs Fachtrigger werden modebewusst ersetzt: ETB- und TBB-Insert,
Fernmeldeplan-Insert/-Freigabe sowie Melderauftrag-Insert/-Update. `STRICT`
erhält die bis dahin geltenden Funktions-/Rollenbedingungen byteinhaltlich
weiter. `LOOSE` lässt nur diese Prädikate aus und verlangt weiterhin die
konkrete aktive und ungesperrte Kontenidentität, den aktiven offenen Einsatz,
einsatzgleiche Beziehungen, zulässige Workflowzustände, Melder-Eignung,
Nummerierung, Referenzen, Provenienz und Unveränderlichkeit. Ein Upgrade ist
deshalb kein implizites Öffnen bestehender Schreibrechte.

Der erneuerte ETB-Insert-Trigger akzeptiert neue Referenzen nur als
kanonische, positive, bereits vorhandene lokale ETB-Nummer desselben Einsatzes.
Für Korrekturen muss diese öffentliche Nummer exakt zur intern gebundenen
direkten Originalzeile passen. Historischer Freitext wird durch die Migration
nicht umgedeutet oder überschrieben.

Fachgrundlage für diese Migration ist das bereitgestellte Handbuch ETB/TBB,
Version 1.0, Stand März 2022, SHA-256
`2457d1deccd01892655bbc329b08885a0b3c8b3ebfb6372c79997d3427d1ae59`.
Migration und grüner Schemacheck stellen keine formale THW-Freigabe des
elektronischen Verfahrens dar; der vollständige Vorbehalt ist in
[DV-1-101-UMSETZUNG.md](DV-1-101-UMSETZUNG.md) festgehalten.

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
- ist die durch Migration 113 erzeugte `nv_kennwortrichtlinie` eine kanonische
  Singleton-Tabelle und enthält sie ausschließlich gültige Grenzwerte,
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
- Nachweis, dass alle übernommenen Einsätze nach Migration 115 weiterhin
  `STRICT` sind; jede später bewusst auf `LOOSE` gesetzte Einsatz-ID samt
  Warnungsbestätigung und Auditentscheidung wird getrennt dokumentiert,
- Name der freigebenden Person.

Ein Rückrollen nur des App-Images ist ausschließlich zulässig, wenn die
Schemaänderungen nachweislich rückwärtskompatibel sind. Andernfalls besteht der
Rollback aus altem Image **und** vollständiger Wiederherstellung des unmittelbar
vor dem Upgrade erzeugten Datenbank-/Dateibackups.

## Historische Nachweise

Die Originalquellen werden nicht dupliziert:

- [SVN-/Git-Migrationsnachweis](../migration/README.md)
- [Index der 95 unverändert erhaltenen Dokumente](legacy/README.md)
- [historisches Anwendungshandbuch von 2011](../doku/Handbuch_eStab.pdf)

Das PDF bleibt ausschließlich eine historische Quelle. Die dort beschriebenen
Web-Installer, XAMPP-Konfigurationen und leeren MySQL-Root-Kennwörter sind nur
historischer Kontext und kein zulässiger Containerbetrieb. Die aktuelle,
gemeinsam mit der Anwendung ausgelieferte Bedienreferenz ist das öffentliche
[Web-Handbuch](../handbuch/) unter `/handbuch/`; für Upgrade und Rollback
bleiben die Vorgaben dieses Runbooks verbindlich.
