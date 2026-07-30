# Globale Einsätze und Datenzuordnung

## Ziel und fachliche Invariante

Ein Einsatz ist der globale Datenraum für alle operativen Eingaben. Zu jedem
Zeitpunkt gibt es systemweit entweder genau einen aktiven Einsatz oder keinen
aktiven Einsatz. Der Zustand „kein Einsatz aktiv“ ist absichtlich gültig:
Anmeldung, öffentliche Ansichten und Administration bleiben möglich,
operative Lese- und Schreibpfade sind jedoch gesperrt.

Die zentrale PHP-API steht in `app/incident.php`. Die additive, wiederholbare
Datenbankfolge steht in den Migrationen
`45-global-incidents-prepare.sql`, `50-global-incidents.sql`,
`55-global-incidents-finish.sql`, `80-dv-evidence-retention.sql`,
`94-dv-organisational-controls.sql`,
`95-attachment-ingest-integrity.sql` und
`96-etb-duty-function.sql`. Die technischen
Administrationsoberflächen sind `4fadm/incidents.php` und
`4fadm/fuehrungsstelle.php`.

Die verbindlichen Regeln sind:

1. Eine dauerhaft eindeutige Kennung identifiziert einen Einsatz in Anzeige,
   Export und Archiv.
2. Der Singleton `nv_einsatz_status` verweist auf höchstens einen aktiven
   Einsatz.
3. Aktivieren und Deaktivieren sperren den Singleton mit `SELECT ... FOR
   UPDATE`, prüfen die vom Browser gesehene Revision und schreiben Status plus
   Audit in einer Transaktion.
4. Ein vollständiger operativer Schreibvorgang verwendet
   `estab_incident_with_active_write()`. Dadurch kann der aktive Einsatz nicht
   zwischen Hauptdatensatz, Nebenstatus und Audit wechseln.
5. Datenbanktrigger bilden die letzte Sperre für noch nicht umgestellte
   Legacy-Schreiber: INSERT ohne aktiven Einsatz scheitert; UPDATE und DELETE
   sind nur für den unverändert zugeordneten, derzeit aktiven Einsatz
   zulässig. Ein Einsatz kann nie umgehängt werden.
6. Bestandsdaten werden ausschließlich während der Migration dem
   geschlossenen, reservierten Einsatz `LEGACY-IMPORT` zugeordnet. Sie werden
   nie still dem ersten später aktivierten Realeinsatz zugeschlagen.
7. Einsätze werden nicht gelöscht. Damit bleiben Referenzen, Exporte und
   Auditnachweise dauerhaft eindeutig.
8. Operative Eingaben benötigen zusätzlich eine aktive Dienstschicht und eine
   vom betreffenden Konto angenommene, fachlich passende Dienstfunktion.
9. „Nicht aktiv“ und „formal abgeschlossen“ sind getrennte Zustände. Ein
   formaler Abschluss ist nur nach einer vollständigen Preflight-Prüfung
   möglich und sperrt sämtliche weiteren operativen Änderungen.

## Datenmodell

| Tabelle | Aufgabe |
| --- | --- |
| `nv_einsaetze` | Kennung, Name, Zeitraum, Ort, Organisation, Einsatzleitung, Beschreibung, formaler Abschluss, Mindestaufbewahrung, Legal Hold und erweiterbares JSON-Metadatenobjekt |
| `nv_einsatz_status` | Genau eine Singleton-Zeile mit aktiver Einsatz-ID, monotoner Revision, Zeitpunkt und Akteur |
| `nv_einsatz_ereignisse` | Unveränderliches Audit für Anlegen, Aktivieren und Deaktivieren |
| `nv_nachrichtenereignis_kopf`, `nv_nachrichtenereignisse` | Pro Nachricht verketteter, unveränderlicher Zustands- und Terminalnachweis |
| `nv_dienstschichten`, `nv_dienstbesetzungen`, `nv_dienstuebergaben` | Einsatzbezogener Dienstbetrieb mit persönlicher Annahme, Mehrfachfunktion und Ablösung |
| `nv_fernmeldeplaene`, `nv_fernmeldeplan_eintraege` | Versionierte, nach Freigabe unveränderliche S6-Kommunikationsplanung |
| `nv_melderauftraege` | Vollständige Melderkette vom LdF-Auftrag bis zur Rückmeldung |
| `nv_betriebsereignis_kopf`, `nv_betriebsereignisse` | Einsatzbezogene Hashkette für Schicht-, Besetzungs-, Plan- und Melderereignisse |

Folgende Tabellen erhalten eine indizierte, fremdschlüsselgesicherte
`einsatz_id`:

| Tabelle | Einordnung | Datenbanksperre |
| --- | --- | --- |
| `nv_nachrichten` | Ein- und Ausgangsnachrichten sowie Gesprächsnotizen | strikt |
| `nv_anhang` | Anhang-Metadaten; die Datei wird über diesen Datensatz zugeordnet | strikt |
| `nv_etb` | Einsatztagebuch | strikt |
| `nv_tbb` | Technisches Betriebsbuch | strikt |
| `nv_ubb` | Übungsbetriebsbuch | strikt |
| `nv_bhp50` | BHP-50-/Patientendaten | strikt |
| `nv_komplan` | einsatzbezogener Kommunikationsplan | strikt |
| `nv_etbtitel`, `nv_tbbtitel` | historische ETB-/TBB-Kopfdaten | strikt |
| `nv_protokoll` | fachliche und globale Systemereignisse | bewusst optional |

`nv_protokoll.einsatz_id` bleibt für globale Ereignisse `NULL`. Anmeldung,
Abmeldung, Benutzerverwaltung, Konfigurationsänderung und Einsatzaktivierung
müssen auch ohne aktiven Einsatz funktionieren und auditierbar bleiben.
Operative Protokolleinträge binden dagegen die aktive Einsatz-ID ausdrücklich
im selben Transaktionskontext wie ihre Fachdaten. Deshalb besitzt nur diese
Tabelle bewusst keinen generischen No-active-Trigger.

## Abgeleitete und globale Daten

Nicht jede Tabelle benötigt eine redundante Einsatz-ID:

- `nv_masterkategolink` sowie dynamische `*_kategolink`-Tabellen verweisen über
  `msg` auf eine Nachricht und erben deren Einsatz.
- Dynamische `*_read`- und `*_erl`-Tabellen verweisen über `nachnum` auf eine
  Nachricht und erben deren Einsatz.
- Die Anhangnamen in `nv_nachrichten.12_anhang` müssen beim Lesen und Export
  ausschließlich gegen Anhänge desselben Einsatzes aufgelöst werden.
- Kategorienamen, Benutzer-/Funktionskategorien und Empfängerzuordnungen sind
  wiederverwendbare Konfiguration. `nv_masterkatego`, dynamische `*_katego`,
  `nv_empfmtx` und `nv_empfmtx_standard` bleiben global.
- `nv_benutzer`, Rollen, Sitzungsstatus und Kennwörter sind globale
  Systemidentitäten. Sie werden nicht mit jedem Einsatz dupliziert.
  `nv_dienstbesetzungen` bindet diese Personen dagegen ausdrücklich an die
  konkrete Einsatzschicht und Funktion.
- `estab_schema_baselines` und `estab_schema_migrations` sind technischer
  Systemzustand.

Diese Grenze verhindert widersprüchliche doppelte Einsatz-IDs in Linktabellen,
ohne operative Datensätze aus ihrem Einsatzkontext zu lösen.

## Öffentliche PHP-API

Reader und Statusanzeige:

```php
estab_incident_status(mysqli $db, bool $forUpdate = false): array;
estab_incident_active(mysqli $db): ?array;
estab_incident_require_active(mysqli $db, bool $forUpdate = false): array;
estab_incident_find(mysqli $db, int $id): ?array;
estab_incident_list(mysqli $db): array;
```

Ein Status-/Active-Array enthält mindestens `active_einsatz_id`, `revision`,
`kennung`, `name`, `beginn`, `ende`, `ort`, `organisation`,
`einsatzleitung`, `beschreibung` und `metadaten`.

Operative Writer:

```php
estab_incident_with_active_write(
    mysqli $db,
    callable $operation
): mixed;
```

Der Callback erhält den autoritativen Status inklusive
`active_einsatz_id` und verwendet dieselbe Datenbankverbindung. Die API öffnet
die Transaktion, sperrt den Singleton, führt den Callback aus und committet
oder rollt vollständig zurück. Ohne aktiven Einsatz wird
`EstabNoActiveIncidentException` ausgelöst.

Der gemeinsame Request-Guard ergänzt diese Einsatzgrenze um den
Führungsstellenbetrieb: Auch bei aktivem Einsatz scheitert eine normale
operative Eingabe, wenn keine Schicht aktiv ist, die angemeldete Person ihre
Funktion nicht angenommen hat oder ein als Melder übernommener Auftrag sie bis
zur Rückkehr bindet. Ausgenommen sind nur die eng begrenzten Kontrollschritte
Anmeldung/Abmeldung, persönliche Dienstannahme und -auswahl,
Melder-Rückkehrkette, Administration und Wiederherstellung.

Administration:

```php
estab_incident_create(
    mysqli $db,
    array $input,
    string $actor,
    bool $activate,
    ?int $expectedRevision = null
): array;

estab_incident_activate(
    mysqli $db,
    int $id,
    int $expectedRevision,
    string $actor
): array;

estab_incident_deactivate(
    mysqli $db,
    int $expectedId,
    int $expectedRevision,
    string $actor
): array;
```

Formaler Abschluss und Aufbewahrung:

```php
estab_incident_close_preflight(mysqli $db, int $id): array;
estab_incident_close(
    mysqli $db,
    int $id,
    int $expectedRevision,
    string $actor,
    array $input
): array;
estab_incident_set_legal_hold(
    mysqli $db,
    int $id,
    bool $enabled,
    string $reason,
    string $actor
): array;
```

Der Preflight blockiert unter anderem offene Nachrichten, Sperren,
unvollständige Anhänge, fehlende oder vom SHA-256-/Größennachweis abweichende
neue Anhangdateien, offene Dienstschichten/Besetzungen/Melderaufträge,
Planentwürfe und ungültige Nachrichten- oder Betriebsereignisketten. Beim
Upgrade vorhandene, erreichbare Anhänge blockieren nicht allein aufgrund
fehlender rückwirkender Beweiskraft; die Oberfläche zählt sie ausdrücklich als
„Integrität beim Eingang nicht belegbar“.
Abschluss werden Zeitpunkt, Akteur, Vermerk und ein frühestes
Aufbewahrungsende von einem Jahr gespeichert. Ein Legal Hold verlängert diese
Grenze fachlich; er verkürzt sie nie. eStab führt keinen automatischen
Fachdaten-Purge aus.

## Umgesetzte Laufzeitgrenzen

Das Schema ist die letzte, aber nicht die einzige Schutzschicht. Die
produktiven Leser und Schreiber verwenden zusätzlich die zentrale
Einsatz-API:

- Die gemeinsame Status-/Sitzungsleiste zeigt Kennung und Name des aktiven
  Einsatzes. Ohne aktiven Einsatz erscheint ein roter Hinweis. Markierte
  operative Formulare werden im Browser deaktiviert; jeder POST-Controller
  prüft den Zustand zusätzlich serverseitig.
- Die Führungsstellenansicht zeigt geplante beziehungsweise aktive Schicht,
  persönliche Funktionszuweisungen und die aktuell gewählte Arbeitsfunktion.
  Ohne aktive Schicht oder passende angenommene Besetzung bleibt jeder
  normale operative POST serverseitig gesperrt.
- Nachrichten, Sperren, Sichtung, Transport, Gelesen-/Erledigt-Zustände und
  Kategoriezuordnungen prüfen die aktive Nachricht innerhalb einer
  `estab_incident_with_active_write()`-Transaktion. Listen und Zähler lesen nur
  den aktiven Einsatz.
- ETB und TBB verwenden die globalen Einsatzstammdaten als Kopf, schreiben
  unter dem gesperrten Singleton und filtern ihre chronologischen Listen nach
  Einsatz. Die alten lokalen Einsatz-anlegen-Formulare sind nicht mehr
  schreibend.
- Nachrichtenzähler-Reparatur und PDF-Vordruckreset benötigen einen aktiven
  Einsatz und ändern ausschließlich dessen Nachrichten. Login, Logout,
  Benutzer- und Konfigurationsverwaltung bleiben bewusst globale Vorgänge.
- Der PDF-Dossierexport wählt ausdrücklich eine Einsatz-ID und darf deshalb
  auch einen nicht aktiven historischen Einsatz lesen. Die
  Datenbankabfragen laufen in einem konsistenten Read-only-Snapshot und sind
  sämtlich auf diese ID vorbereitet.

Historische, nicht mehr geroutete Duplikate und Beispiel-Uploader sind keine
Laufzeitendpunkte. Werden lokal zusätzliche operative Module reaktiviert,
müssen deren Leser und Schreiber denselben API-, Trigger- und Testvertrag
erfüllen.

### Atomarer Anhang-Upload

`estab_attachment_store_upload()` hält den Singleton-Lock vom Claim der
vorher reservierten Kennung über das Schreiben und Prüfen der Datei bis zur
Finalisierung der Metadaten und zum einsatzgebundenen Audit. Claim,
Metadatenstatus und Audit werden auf derselben MariaDB-Verbindung committet.
Ein Administrator kann den aktiven Einsatz deshalb nicht zwischen diesen
Teilschritten wechseln.

Scheitern Dateiablage, Metadatenvalidierung, Finalisierung oder Audit, rollt
die Funktion zum Savepoint vor dem Claim zurück, setzt die Reservierung auf
den abgebrochenen Zustand und committet nur diese Freigabe. Der
HTTP-Controller entfernt eine gegebenenfalls bereits verschobene Datei aus
dem nachweislich erlaubten Ablageverzeichnis. Eine Status-2-Zwischenzeile wird
nicht als fertiger Anhang sichtbar. Listen, Auswahl und Download bestimmen
den Einsatz immer über `nv_anhang.einsatz_id`; der physische Dateiname allein
erteilt keine Berechtigung.

### Einsatzbezogene generierte Nachrichtenvordrucke

Die Nachrichtennummern beginnen je Einsatz erneut. Ein nur aus Nummer und
Richtung gebildeter Dateiname würde deshalb kollidieren. Der kanonische Name
lautet:

```text
<datenbank> Einsatz-<einsatz_id> <nachweisnummer> <E|A>.pdf
```

Beispiel:

```text
estab Einsatz-17 42 E.pdf
```

Der Generator sperrt den aktiven Einsatz, liest nur abgeschlossene,
noch nicht gedruckte Nachrichten dieses Einsatzes, erzeugt das vollständige
PDF und markiert genau denselben Datensatz als gedruckt. Die Veröffentlichung
erfolgt über eine temporäre Datei auf demselben Dateisystem und anschließendes
atomisches `rename`; ein abgebrochener Lauf ersetzt kein vollständiges PDF
durch Teildaten.

Vordruckliste und Download scannen nicht vertrauensvoll das gemeinsame
Verzeichnis. Sie leiten den erwarteten Namen aus einem abgeschlossenen,
gedruckten Datenbankdatensatz des aktiven Einsatzes ab und prüfen ID,
Nachweisnummer und Richtung erneut. Der in der Liste sichtbare aktuelle
PDF-Abzug liest danach den vollständigen Datensatz und die validierte
Empfängermatrix innerhalb derselben Transaktion und rendert nur im Speicher.
Damit erscheinen auch vor einem Vorlagenwechsel archivierte Vordrucke im
aktuellen Layout des Gesamtexports. Die persistierte Archivdatei wird durch
diesen GET weder ersetzt noch neu datiert; ihr parameterloser interner
Download bleibt für den bytegleichen Backup-/Restore-Nachweis erhalten.

Die Dateien historischer Einsätze bleiben im persistenten Volume
kollisionsfrei erhalten, sind aber nicht über die aktive Vordruckliste eines
anderen Einsatzes abrufbar. Das
[PDF-Einsatzdossier](PDF-EINSATZDOSSIER.md) erzeugt für ausgewählte
historische Einsätze eine eigene zusammenhängende Darstellung. Seine
Nachrichtenseiten verwenden denselben A4-Formularrenderer wie die
Einzelvordrucke, jedoch ohne Links in den Downloadbereich des aktuell aktiven
Einsatzes. Die gemeinsame Vorlage enthält weder VS-NfD-Aufdruck noch Wappen;
Anhänge liegen mit ihren gelesenen Bytes im eingebetteten Dateikatalog des
Dossiers. Bei neuen Dateien belegt der beim Eingang gespeicherte SHA-256 samt
Bytezahl die Übereinstimmung; beim Upgrade vorhandene Legacy-Dateien werden
als „Integrität beim Eingang nicht belegbar“ ausgewiesen.
Da die Legacy-Datenbank keine Matrixhistorie pro Einsatz besitzt, werden
Empfängerfunktionen, die in der aktuellen Matrix fehlen, zusätzlich mit ihrem
gespeicherten Kopiekennzeichen im Inhaltsbereich ausgeschrieben.

## Migrations- und Testnachweis

Die checksum-gebundene Folge 45/50/55/80/94/95/96 ist additiv. Ein harter
Abbruch bleibt bis zur kontrollierten Prüfung des Migrationsledgers
fail-closed; anschließend werden ausschließlich exakt erkannte,
migrationseigene Zwischenstände fortgesetzt. Die ersten drei Migrationen:

1. prüft in Migration 45 vor jeder Einsatz-DDL alle zehn operativen
   Basistabellen und beide historischen Zeitspalten,
2. deaktiviert deren automatische `ON UPDATE`-Änderung für die Dauer des
   Backfills,
3. verweigert in der unveränderten Migration 50 fremde Tabellen, Spalten,
   Routinen oder Trigger im reservierten Namensraum,
4. erstellt das Einsatzmodell und den Singleton und ergänzt die
   Einsatzspalten,
5. erzeugt bei vorhandenen Daten genau einen geschlossenen
   `LEGACY-IMPORT`,
6. weist nur die vorgefundenen `NULL`-Bestandszeilen diesem Einsatz zu,
7. ergänzt Indexe und Fremdschlüssel und installiert erst danach die strikten
   Trigger,
8. stellt in Migration 55 die kanonischen automatischen Zeitattribute wieder
   her.

Migration 80 ergänzt ohne Umschreiben historischer Fachdaten den
Nachrichten-/ETB-Nachweis, den formalen Abschluss und die
Aufbewahrungsgrenzen. Migration 94 ergänzt persönliche Dienstbesetzungen,
S6-Planversionen, Melderaufträge und den verketteten Betriebsnachweis. Neue
abgeschlossene Nachrichten werden an einen kanonischen Terminal-Snapshot
gebunden. Ein historischer Import ohne einen damals nicht vorhandenen
Terminal-Snapshot bleibt ausdrücklich als „nicht nachträglich belegbar“
sichtbar; seine vorhandene Ereigniskette wird nicht erfunden oder umgedeutet.
Migration 95 ergänzt den nicht rückwirkenden Anhang-Eingangsnachweis.
Migration 96 führt die getrennte ETB-Dienstfunktion und ihre eng begrenzte
`EINSATZTAGEBUCH`-Fähigkeit ein.

Migration 50 bleibt bytegenau auf der bereits im Ledger verwendeten
Prüfsumme. Vor- oder Nachbedingungen werden ausschließlich in neuen
Versionsdateien ergänzt; weder der Ledger noch eine veröffentlichte SQL-Datei
wird für ein Upgrade umgeschrieben.

Der fokussierte Quell- und Validierungsvertrag ist
`tests/php/incident_domain_security.php`. `tests/integration/incident_domain.php`
führt den Domänenvertrag in einer eigens migrierten MariaDB aus und prüft
Parallelaktivierung, No-active-INSERT, Update-/Delete-Sperre,
Reassignment-Versuch und konkurrierende Statusänderung. Der
Schema-Migratortest belegt Legacy-Backfill und Wiederholbarkeit.
`tests/integration/dv_evidence.php` beweist Abschluss-Preflight,
Append-only-ETB, Hashketten, Terminalbindung, Mindestaufbewahrung und Legal
Hold. `tests/integration/dv_operations.php` beweist Pflichtbesetzung,
Mehrfachfunktion, Schichtübergabe, S6-Versionierung, Melderbindung und die
Schreibsperre ohne aktive Schicht.
`tests/integration/incident_export.php` erzeugt zusätzlich ETB, TBB,
Nachricht und Anhang mit Eingangsnachweis in zwei Einsätzen, exportiert den inzwischen
historischen ausgewählten Einsatz und extrahiert dessen PDF-`EmbeddedFile`
bytegleich samt SHA-256. Der vollständige CI-Lauf schließt den
Backup-/Restore-Roundtrip an.
