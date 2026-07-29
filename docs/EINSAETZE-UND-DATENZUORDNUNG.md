# Globale Einsätze und Datenzuordnung

## Ziel und fachliche Invariante

Ein Einsatz ist der globale Datenraum für alle operativen Eingaben. Zu jedem
Zeitpunkt gibt es systemweit entweder genau einen aktiven Einsatz oder keinen
aktiven Einsatz. Der Zustand „kein Einsatz aktiv“ ist absichtlich gültig:
Lesen, Anmeldung und Administration bleiben möglich, operative Eingaben sind
jedoch gesperrt.

Die zentrale PHP-API steht in `app/incident.php`. Die additive, wiederholbare
Datenbankfolge steht in den Migrationen
`45-global-incidents-prepare.sql`, `50-global-incidents.sql` und
`55-global-incidents-finish.sql`. Die technische Administrationsseite ist
`4fadm/incidents.php`.

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

## Datenmodell

| Tabelle | Aufgabe |
| --- | --- |
| `nv_einsaetze` | Kennung, Name, Zeitraum, Ort, Organisation, Einsatzleitung, Beschreibung und erweiterbares JSON-Metadatenobjekt |
| `nv_einsatz_status` | Genau eine Singleton-Zeile mit aktiver Einsatz-ID, monotoner Revision, Zeitpunkt und Akteur |
| `nv_einsatz_ereignisse` | Unveränderliches Audit für Anlegen, Aktivieren und Deaktivieren |

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
| `nv_etbtitel`, `nv_tbbtitel` | historische ETB-/TTB-Kopfdaten | strikt |
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

## Umgesetzte Laufzeitgrenzen

Das Schema ist die letzte, aber nicht die einzige Schutzschicht. Die
produktiven Leser und Schreiber verwenden zusätzlich die zentrale
Einsatz-API:

- Die gemeinsame Status-/Sitzungsleiste zeigt Kennung und Name des aktiven
  Einsatzes. Ohne aktiven Einsatz erscheint ein roter Hinweis. Markierte
  operative Formulare werden im Browser deaktiviert; jeder POST-Controller
  prüft den Zustand zusätzlich serverseitig.
- Nachrichten, Sperren, Sichtung, Transport, Gelesen-/Erledigt-Zustände und
  Kategoriezuordnungen prüfen die aktive Nachricht innerhalb einer
  `estab_incident_with_active_write()`-Transaktion. Listen und Zähler lesen nur
  den aktiven Einsatz.
- ETB und TTB verwenden die globalen Einsatzstammdaten als Kopf, schreiben
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
Originalanhänge liegen unverändert im eingebetteten Dateikatalog des Dossiers.
Da die Legacy-Datenbank keine Matrixhistorie pro Einsatz besitzt, werden
Empfängerfunktionen, die in der aktuellen Matrix fehlen, zusätzlich mit ihrem
gespeicherten Kopiekennzeichen im Inhaltsbereich ausgeschrieben.

## Migrations- und Testnachweis

Die checksum-gebundene Folge 45/50/55 ist additiv und für eine unterbrochene
DDL-Ausführung wiederaufnehmbar. Sie:

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
`tests/integration/incident_export.php` erzeugt zusätzlich ETB, TTB,
Nachricht und Originalanhang in zwei Einsätzen, exportiert den inzwischen
historischen ausgewählten Einsatz und extrahiert dessen PDF-`EmbeddedFile`
bytegleich samt SHA-256. Der vollständige CI-Lauf schließt den
Backup-/Restore-Roundtrip an.
