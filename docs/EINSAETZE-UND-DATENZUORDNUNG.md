# Globale Einsätze und Datenzuordnung

## Ziel und fachliche Invariante

Ein Einsatz ist der globale Datenraum für alle operativen Eingaben. Zu jedem
Zeitpunkt gibt es systemweit entweder genau einen aktiven Einsatz oder keinen
aktiven Einsatz. Der Zustand „kein Einsatz aktiv“ ist absichtlich gültig:
Anmeldung, öffentliche Ansichten und Administration bleiben möglich,
operative Lese- und Schreibpfade sind jedoch gesperrt.

Die ETB-/TBB-Grenzen bilden die bereitgestellte Ausbildungsunterlage technisch
ab. Sie stellen keine formale THW-Freigabe des elektronischen Verfahrens dar;
deren Vorbehalt und die gebundene Quellfassung sind in
[DV-1-101-UMSETZUNG.md](DV-1-101-UMSETZUNG.md) dokumentiert.

Die zentrale PHP-API steht in `app/incident.php`. Die additive, wiederholbare
Datenbankfolge steht in den Migrationen
`45-global-incidents-prepare.sql`, `50-global-incidents.sql`,
`55-global-incidents-finish.sql`, `80-dv-evidence-retention.sql`,
`94-dv-organisational-controls.sql`,
`95-attachment-ingest-integrity.sql` und
`96-etb-duty-function.sql` sowie
`97-incident-command-post-name.sql` und
`110-etb-tbb-rules.sql`, `111-logbook-shift-assignment.sql` sowie
`112-optional-access-shifts.sql` und
`115-incident-permission-mode.sql`. Die technischen
Administrationsoberflächen sind `4fadm/incidents.php` und
`4fadm/fuehrungsstelle.php`.

Die verbindlichen Regeln sind:

1. Eine dauerhaft eindeutige Kennung identifiziert einen Einsatz in Anzeige,
   Export und Archiv.
2. Jeder neue Einsatz besitzt einen eigenen Führungsstellennamen. Er ist die
   lokale Anschrift/Absendereinheit für Nachrichten und weder Einsatzname,
   Bedarfsträger noch Einsatzleitung.
3. Der Singleton `nv_einsatz_status` verweist auf höchstens einen aktiven
   Einsatz.
4. Aktivieren und Deaktivieren sperren den Singleton mit `SELECT ... FOR
   UPDATE`, prüfen die vom Browser gesehene Revision und schreiben Status plus
   Audit in einer Transaktion.
5. Ein vollständiger operativer Schreibvorgang verwendet
   `estab_incident_with_active_write()`. Dadurch kann der aktive Einsatz nicht
   zwischen Hauptdatensatz, Nebenstatus und Audit wechseln.
6. Datenbanktrigger bilden die letzte Sperre für noch nicht umgestellte
   Legacy-Schreiber: INSERT ohne aktiven Einsatz scheitert; UPDATE und DELETE
   sind nur für den unverändert zugeordneten, derzeit aktiven Einsatz
   zulässig. Ein Einsatz kann nie umgehängt werden.
7. Bestandsdaten werden ausschließlich während der Migration dem
   geschlossenen, reservierten Einsatz `LEGACY-IMPORT` zugeordnet. Sie werden
   nie still dem ersten später aktivierten Realeinsatz zugeschlagen.
8. Einsätze werden nicht gelöscht. Damit bleiben Referenzen, Exporte und
   Auditnachweise dauerhaft eindeutig.
9. Operative Eingaben benötigen immer einen aktiven offenen Einsatz und ein
   konkret authentifiziertes, aktives, ungesperrtes Konto mit wirksamem
   Zugang. Im Berechtigungsmodus `STRICT` müssen zusätzlich feste
   Kontofunktion und serverseitig abgeleitete Rolle fachlich passen. Im Modus
   `LOOSE` entfallen ausschließlich diese funktions-/rollenbezogenen
   Schreibprüfungen. Eine aktive Dienst- oder Zugangsschicht ist nicht
   erforderlich.
10. „Nicht aktiv“ und „formal abgeschlossen“ sind getrennte Zustände. Ein
    formaler Abschluss ist nur nach einer vollständigen Preflight-Prüfung
    möglich und sperrt sämtliche weiteren operativen Änderungen.
11. ETB und TBB bilden je Einsatz je einen lokalen, bei 1 beginnenden und nur
    anhängbaren Nummernstrom. Bei einem neuen, leeren Einsatz schreibt bereits
    die Aktivierung je eine schichtfreie Eröffnungszeile. Der formale
    Einsatzabschluss schreibt die Abschlusszeilen atomar mit dem Vorgang; eine
    frühere Schicht ist weder für Eröffnung noch Abschluss Voraussetzung.
12. Jeder Einsatz besitzt ab seiner Erzeugung exakt zwei leere,
    fremdschlüsselgebundene Nummernköpfe `ETB:1` und `TTB:1`. Ein fehlender
    Kopf wird beim Buchen nicht repariert, sondern sperrt den Schreibvorgang.
13. Ein formal abgeschlossener Einsatz bleibt mindestens zehn Jahre ab
    Abschluss erhalten; eine längere Frist oder ein Legal Hold wird nie
    verkürzt.
14. Jeder Einsatz besitzt genau einen Berechtigungsmodus. Neuinstallation,
    neue Einsätze und alle beim Upgrade vorhandenen Einsätze beginnen
    fail-closed mit `STRICT`. Nur die revisions- und auditgebundene
    Administration darf einen offenen Einsatz umstellen; `LOOSE` verlangt
    eine ausdrückliche Warnungsbestätigung. Der Modus ändert keine
    allgemeinen Lese-, Richtungs-, Status-, Sperr-, Integritäts- oder
    Aufbewahrungsregeln. Nur die für eine ausdrücklich gewählte Schreibstufe
    notwendige Workflow-Objektsicht und funktionsbezogene Zuständigkeit werden
    gelockert.

## Datenmodell

| Tabelle | Aufgabe |
| --- | --- |
| `nv_einsaetze` | Kennung, Einsatzname, Berechtigungsmodus `STRICT`/`LOOSE`, Zeitraum, Ort, Bedarfsträger in `organisation`, eigener Führungsstellenname, Einsatzleitung, Auftrag/Ausgangslage in `beschreibung`, formaler Abschluss, Mindestaufbewahrung, Legal Hold und erweiterbares JSON-Metadatenobjekt |
| `nv_einsatz_status` | Genau eine Singleton-Zeile mit aktiver Einsatz-ID, monotoner Revision, Zeitpunkt und Akteur |
| `nv_einsatz_ereignisse` | Unveränderliches Audit für Anlegen, Berechtigungsmodusänderungen, Führungsstellenänderungen, Aktivieren und Deaktivieren |
| `nv_nachrichtenereignis_kopf`, `nv_nachrichtenereignisse` | Pro Nachricht verketteter, unveränderlicher Zustands- und Terminalnachweis |
| `nv_zugangsschichten`, `nv_zugangsschicht_mitglieder` | Optionale einsatzbezogene Gruppen zum gemeinsamen Aktivieren und Deaktivieren von Zugängen; keine Fachrechtsquelle |
| `nv_dienstschichten`, `nv_dienstbesetzungen`, `nv_dienstuebergaben` | Historische formale Dienstbetriebs-, Besetzungs- und Übergabeevidenz; nicht mehr Autorisierungsquelle |
| `nv_fernmeldeplaene`, `nv_fernmeldeplan_eintraege` | Versionierte, nach Freigabe unveränderliche S6-Kommunikationsplanung |
| `nv_melderauftraege` | Vollständige Melderkette vom LdF-Auftrag bis zur Rückmeldung |
| `nv_betriebsereignis_kopf`, `nv_betriebsereignisse` | Einsatzbezogene Hashkette für Schicht-, Besetzungs-, Plan- und Melderereignisse |
| `nv_logbuch_koepfe` | Exakt zwei vorab durch den Einsatz-Insert-Trigger angelegte, sperrbare nächste lokale Zähler je Einsatz (`ETB`, `TTB`); technische globale Primärschlüssel bleiben davon getrennt |

### Einsatzname, Bedarfsträger und Führungsstelle

Die Stammdaten beantworten unterschiedliche Fragen und dürfen nicht
gegenseitig als Fallback verwendet werden:

| Feld | Bedeutung |
| --- | --- |
| Einsatzname | Bezeichnung des Ereignisses oder Auftrags |
| Bedarfsträger | Organisation oder Stelle, in deren Auftrag der Einsatz geführt wird; technisch historische Spalte `organisation` |
| Führungsstellenname | lokale Anschrift beziehungsweise Absendereinheit der digitalen Nachrichtenvordrucke |
| Einsatzleitung | leitende Person oder organisatorische Leitungsangabe |
| Beschreibung | Einsatzauftrag und Ausgangslage für den ETB-Eröffnungseintrag |

Neue Einsätze verlangen Kennung, Einsatzname, Beginn, Bedarfsträger,
Führungsstellenname, verantwortliche Einsatz-/Führungsleitung und
Einsatzauftrag/Ausgangslage. Sobald alle sieben Angaben vorliegen, kann der
Einsatz aktiviert und operativ verwendet werden. Bedarfsträger,
Leitungsangabe und Auftrag/Ausgangslage können bei einem vorbereiteten
Bestands-Einsatz nachgetragen werden; ab der ersten bereits vorhandenen
Buchzeile sind sie als Buchgrundlage unveränderlich. Eine Schichtaktivierung
ist dafür nicht erforderlich.

Neue Einsätze verlangen den Führungsstellennamen bereits beim Anlegen.
Migration 97 lässt bestehende Werte bewusst `NULL`. Ein offener historischer
Einsatz muss vor Aktivierung oder weiterer operativer Eingabe einmalig in der
Administration mit dem tatsächlichen Namen bestätigt werden; diese
Erstbestätigung ist auch bei bereits vorhandenen Fachdaten erlaubt. Danach
wird jede Änderung zusammen mit Alt-/Neuwert und Akteur auditiert. Ein schon
belegter Name kann nur bis zum ersten Datensatz in Nachrichten, Anhängen,
ETB, TBB, Dienstschichten, Fernmeldeplänen oder Melderaufträgen korrigiert
werden und ist anschließend über `fuehrungsstellenname_gesperrt` dauerhaft
unveränderlich. Die erste Fachänderung setzt den Marker in derselben
Transaktion; auch das spätere Löschen der Fachzeile hebt ihn nicht auf.
Formal abgeschlossene Einsätze werden nicht nachträglich verändert.

Statusleisten, Führungsstellenansicht, ETB/TBB, Nachweisung, Exportauswahl und
PDF-Dossier zeigen den Führungsstellennamen getrennt von Kennung und
Einsatzname. Bei einem aktiven historischen `NULL`-Wert melden Statusleiste
und Administration „Name fehlt“ beziehungsweise „Einsatz unvollständig“. Das
historische PDF sagt „historisch nicht erfasst“, während der Tabellenexport
den eindeutigen SQL-NULL-Marker bewahrt. Eine Umgebungseinstellung oder ein
Browserfeld ist niemals Quelle dieses Stammdatums.

Folgende Tabellen erhalten eine indizierte, fremdschlüsselgesicherte
`einsatz_id`:

| Tabelle | Einordnung | Datenbanksperre |
| --- | --- | --- |
| `nv_nachrichten` | Ein- und Ausgangsnachrichten sowie Gesprächsnotizen | strikt |
| `nv_anhang` | Anhang-Metadaten; die Datei wird über diesen Datensatz zugeordnet | strikt |
| `nv_etb` | Einsatztagebuch mit lokaler Buchnummer, Schicht-/Schreiberprovenienz, Ereignis-/Erfassungszeit, A/B/E/K/W-Art, Bezügen, optionaler Bearbeitungs- und eindeutiger Anhangszuordnung sowie direkter Korrekturbeziehung | strikt und append-only; ein `estab_attachment_id` darf höchstens einmal vorkommen |
| `nv_tbb` | Technisches Betriebsbuch mit lokaler Buchnummer, Schicht-/Schreiberprovenienz, strukturierten Fb-Fü-44-Inhaltsfeldern, Nachrichten- und direkter Korrekturbeziehung | strikt und append-only |
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
`kennung`, `name`, `estab_permission_mode`, `beginn`, `ende`, `ort`, `organisation`,
`fuehrungsstellenname`, `fuehrungsstellenname_gesperrt`, `einsatzleitung`,
`beschreibung` und `metadaten`.

Operative Writer:

```php
estab_incident_with_active_write(
    mysqli $db,
    callable $operation
): mixed;
```

Der Callback erhält den autoritativen Status inklusive
`active_einsatz_id`, Berechtigungsmodus und globaler Revision und verwendet
dieselbe Datenbankverbindung. Die API öffnet die Transaktion, sperrt den
Singleton, verwirft einen inzwischen gewechselten Einsatz-, Modus- oder
Revisionsstand, führt den Callback aus und committet oder rollt vollständig
zurück. Ohne aktiven Einsatz wird
`EstabNoActiveIncidentException` ausgelöst.

Der gemeinsame Request-Guard ergänzt diese Einsatzgrenze um den
Führungsstellenbetrieb: In beiden Modi scheitert eine normale operative
Eingabe, wenn das konkrete Konto nicht aktiv beziehungsweise gesperrt ist,
eine ausschließlich deaktivierte Zugangsschicht den Zugriff entzogen hat oder
ein als Melder übernommener Auftrag die Person bis zur Rückkehr bindet. Nur im
strengen Modus scheitern die modeabhängigen Workflow-, ETB-/TTB-, S6-Plan-
und Melder-Schreibpfade außerdem an einer fachlich unpassenden festen
Funktion/Rolle. Rollenstrenge Übersichten, Kategorien- und
Administrationsrechte bleiben in beiden Modi unverändert. Eine aktive Schicht
oder eine persönliche Schichtannahme wird
nicht verlangt. Ausgenommen sind nur die eng begrenzten
Kontrollschritte Anmeldung/Abmeldung, Melder-Rückkehrkette, Administration und
Wiederherstellung.

Administration:

```php
estab_incident_create(
    mysqli $db,
    array $input,
    string $actor,
    bool $activate,
    ?int $expectedRevision = null,
    bool $confirmedLoose = false
): array;

estab_incident_update_command_post_name(
    mysqli $db,
    int $id,
    mixed $value,
    mixed $expectedValue,
    string $actor
): array;

estab_incident_activate(
    mysqli $db,
    int $id,
    int $expectedRevision,
    string $actor,
    bool $confirmedLoose = false
): array;

estab_incident_deactivate(
    mysqli $db,
    int $expectedId,
    int $expectedRevision,
    string $actor
): array;

estab_incident_update_permission_mode(
    mysqli $db,
    int $id,
    mixed $mode,
    mixed $expectedMode,
    int $expectedRevision,
    string $actor,
    bool $confirmedLoose = false
): array;
```

Die Modusänderung sperrt zuerst denselben globalen Einsatzstatus wie Aktivieren
und operative Writer. Sie ist nur für offene Einsätze zulässig und bindet
Einsatz-ID, erwarteten alten Modus und globale Revision. `LOOSE` wird ohne
separate Bestätigung abgewiesen. Ein echter Wechsel erhöht die Revision und
schreibt das Ereignis `berechtigung_geaendert` samt Vorher-/Nachherwert; ein
unveränderter Wert erzeugt keinen Scheinwechsel.

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
neue Anhangdateien, offene Melderaufträge, Planentwürfe und ungültige
Nachrichten- oder Betriebsereignisketten. Historische formale Dienstschichten
und Besetzungen sowie fehlende schichtbezogene Bucheröffnungen blockieren den
Abschluss nicht; ein Einsatz kann auch ohne jemals angelegte Schicht formal
geschlossen werden. Beim
Upgrade vorhandene, erreichbare Anhänge blockieren nicht allein aufgrund
fehlender rückwirkender Beweiskraft; die Oberfläche zählt sie ausdrücklich als
„Integrität beim Eingang nicht belegbar“.
Beim Abschluss werden zunächst die letzten ETB-/TBB-Zeilen mit tatsächlichem
Einsatzende, Abschlussvermerk und letzter fachlicher Führung und anschließend
Zeitpunkt, Akteur sowie Vermerk des Einsatzabschlusses in derselben
Transaktion gespeichert. Das früheste Aufbewahrungsende liegt zehn Jahre nach
dem formalen Abschluss. Ein Legal Hold verlängert diese Grenze fachlich; er
verkürzt sie nie. eStab führt keinen automatischen Fachdaten-Purge aus.

## Umgesetzte Laufzeitgrenzen

Das Schema ist die letzte, aber nicht die einzige Schutzschicht. Die
produktiven Leser und Schreiber verwenden zusätzlich die zentrale
Einsatz-API:

- Die gemeinsame Status-/Sitzungsleiste zeigt Führungsstellenname, Kennung,
  Name und Berechtigungsmodus des aktiven Einsatzes. `LOOSE` wird als
  sichtbare Warnung dargestellt. Ohne aktiven Einsatz oder bei einem historischen
  Einsatz ohne bestätigten Führungsstellennamen erscheint ein roter Hinweis.
  Markierte operative Formulare werden im Browser deaktiviert; jeder
  POST-Controller prüft den Zustand zusätzlich serverseitig.
- Die Führungsstellenansicht zeigt die feste Kontofunktion, den
  Berechtigungsmodus und – sofern verwendet – die optionalen
  Zugangsschichten. Im strengen Modus stammen Schreibrechte aus Funktion und
  Rolle des Kontos; bei den vom lockeren Modus erfassten operativen
  Schreibstufen bleiben beide Angaben Identitäts- und Auditprovenienz, sperren
  den Vorgang aber nicht. Rollenstrenge Übersichten, Nachweisung,
  Zweitsichtungsarchive, Kategorien- und Administrationsrechte bleiben
  unverändert. Ohne aktive Schicht bleibt die Arbeit
  möglich; ohne aktiven Einsatz bleibt jeder operative POST serverseitig
  gesperrt.
- Nachrichten, Sperren, Sichtung, Transport, Gelesen-/Erledigt-Zustände und
  Kategoriezuordnungen prüfen die aktive Nachricht innerhalb einer
  `estab_incident_with_active_write()`-Transaktion. Listen und Zähler lesen nur
  den aktiven Einsatz. Beim Schreiben bindet dieselbe gesperrte
  Einsatztransaktion die lokale Anschrift eines Eingangs beziehungsweise die
  Absendereinheit eines Ausgangs an den Führungsstellennamen; ein Formularwert
  oder eine Umgebungsvorgabe kann ihn nicht ersetzen.
- ETB und TBB verwenden die globalen Einsatzstammdaten einschließlich
  Bedarfsträger, Auftrag/Ausgangslage, Leitung und Führungsstellenname. Der
  formale Abschluss schreibt seine Buchzeilen innerhalb der bereits
  gesperrten Fachtransaktion. Die alten lokalen Einsatz-anlegen-Formulare sind
  nicht mehr schreibend.
- Ein ETB-Eintrag kann optional genau einen finalisierten, einsatzgleichen und
  noch unbenutzten Anhang binden. Die lokale Buchnummer wird erst im selben
  Commit vergeben; daraus folgt deterministisch
  `ETB {einsatz_id}-{estab_book_lfd}-1`. Der Upload gilt als eine gebündelte
  digitale Einheit innerhalb des einzigen ETB des Einsatzes. Ein vorhandenes
  Ablage-/FmZt-Kennzeichen wie `EL0001` bleibt davon getrennt. Anwendung und
  `UNIQUE(estab_attachment_id)` blockieren einen Mehrfachlink; Webliste,
  Fb-Fü-2-PDF und Anlagenverzeichnis zeigen beide Kennzeichnungen.
- Die ETB-Liste ist ohne Filter die vollständige Liste des aktiven Einsatzes.
  Volltext, Art, Nummer/Bezug und Zuordnung sind kombinierbar; durchsucht werden auch
  Personen-/Kürzelangaben, lokale und historische Bestandsreferenzen, lokale Nummern, Nachrichten-,
  Korrektur- und Anhangsbezüge, Ablage-/Originaldateiname sowie die
  vollständige ETB-Anlagennummer. Eine optionale Bearbeitungszuordnung ist nur
  Such- und Anzeigehilfe und erweitert keine Rechte. Beim Speichern werden
  feste Kontofunktion, Rolle und Sperrstatus erneut geprüft; der lesbare
  Snapshot bleibt aus dem amtlichen PDF ausgeschlossen. Im lockeren Modus
  entfällt ausschließlich die funktions-/rollenbezogene Schreibprüfung;
  Konto-, Einsatz-, Objekt-, Validierungs- und Append-only-Grenzen bleiben.
- Neue ETB-Referenzen bestehen ausschließlich aus der positiven lokalen
  Buchnummer eines bereits vorhandenen Eintrags desselben Einsatzes. Freitext,
  führende Nullen, globale Primärschlüssel und unbekannte Nummern scheitern.
  Historischer Freitext bleibt les- und suchbar, erzeugt aber keine künstliche
  Referenzkante. Berichtigungen speichern intern die direkte unveränderliche
  Originalzeile und zeigen deren serverseitig ermittelte lokale Nummer. Eine
  einsatzgebundene Auswertung verfolgt von einer lokalen Startnummer aus
  vorwärts auch verzweigte Folgeeinträge oder rückwärts den Bezugspfad, jeweils
  mit wählbarer Tiefe 1 bis 25 und eigener Druckansicht.
- Die lokalen `estab_book_lfd`-Nummern werden je Einsatz und Buchart unter
  einem bereits beim Einsatz-Insert angelegten `nv_logbuch_koepfe`-Lock
  vergeben. Es bestehen immer exakt zwei Köpfe pro Einsatz; die ETB-/TBB-
  Insert-Trigger erzeugen einen fehlenden Kopf nicht nach. Listen und PDFs
  verwenden die lokale Nummer, nicht den globalen Alt-Primärschlüssel.
  Rückdatierte Ereignisse ändern die einmal vergebene Reihenfolge nicht. Die
  Zeilensperren funktionieren unter der unveränderten MariaDB-Standardisolation
  `REPEATABLE READ`; für den Dossierexport bleiben konsistente Read-only-
  Snapshots aktiviert.
- Im Nachrichtenworkflow kann `LOOSE` nur für eine ausdrücklich gewählte
  Schreibstufe die dafür nötige Objektsicht eröffnen. Richtung, Status und
  Sperrinhaber bleiben bindend. Eine zurückgewiesene Ausgangsmeldung darf eine
  andere Funktion übernehmen; die unveränderliche Evidenz bewahrt dabei
  ursprüngliche und neue Verantwortlichkeit. Reine Übersichten und
  Archivansichten erhalten dadurch keine zusätzliche Leseberechtigung.
- Im strengen Modus folgen die fachlichen Schreibrechte ausschließlich dem
  Konto: ETB schreiben `ETB/Stab` oder `S2/Stab`; das TTB schreibt die Funktion
  `Fernmelder`. Im lockeren Modus dürfen andere konkrete aktive und
  ungesperrte Konten schreiben. Anwendung und Insert-Trigger prüfen in beiden
  Modi Konto-/Kürzelidentität, Sperrstatus und aktiven Einsatz; Funktion und
  Rolle werden nur in `STRICT` als Schreibgrenze ausgewertet. Eine aktive
  Schicht oder Besetzungsannahme wird nicht verlangt.
  Neue Zeilen dürfen die Legacy-Provenienzfelder für Dienstschicht und
  Schreiberbesetzung `NULL` lassen; sie werden nicht mit einer Zugangsschicht
  befüllt. Historische belegte Werte bleiben unverändert. Beide Tabellen
  weisen `UPDATE` und `DELETE` ab; Berichtigungen sind neue, direkt auf den
  Originaleintrag verweisende Zeilen.
- Nummerierte Eingänge und tatsächlich beförderte Ausgänge schreiben
  automatisch einsatzgleiche TBB-Zeilen des exakten Typs `nachricht`.
  Generator, Detailansicht und Export übernehmen ausschließlich die erste
  lokale Nummer dieses Typs. Eine nachfolgende LdF-Absenderübersetzung oder
  begründete Wegkorrektur hängt einen neuen, direkt auf den unveränderten
  Nachrichtennachweis verweisenden TBB-Korrektureintrag an und verändert die
  ausgegebene Ursprungsnummer nicht.
- Zugangsschichten sind optionale Gruppen und keine Dienstbesetzungen. Ein
  unzugeordnetes Konto bleibt zugelassen; bei mehreren Zuordnungen genügt eine
  aktive Gruppe. Aktivieren erzeugt keine Anmeldung. Deaktivieren widerruft
  betroffene Sitzungen, wenn keine andere aktive Zuordnung verbleibt. Die
  dauerhafte Kontosperre bleibt unabhängig und vorrangig.
- Mutationen dieser Gruppen werden als `ZUGANGSSCHICHT` in der
  Betriebsereigniskette nachgewiesen. Die schichtfreie Nachrichtenzähler-
  Reparatur verwendet den Objekttyp `EINSATZ`.
- Historische Dienstschichten, Besetzungen und Übergaben bleiben unverändert
  als Betriebs- und Exportnachweis verfügbar. Der Dossierabschnitt
  Dienstorganisation stellt sie getrennt als Legacy-Nachweis dar und enthält
  zusätzlich alle aktuellen und entfernten Zugangsschichtzuordnungen. Die
  Altdaten bestimmen weder aktuelle Fachrechte noch den Einsatzabschluss und
  sperren keine Eingabe.
- Nachrichtenzähler-Reparatur und PDF-Vordruckreset benötigen einen aktiven
  Einsatz und ändern ausschließlich dessen Nachrichten. Login, Logout,
  Benutzer- und Konfigurationsverwaltung bleiben bewusst globale Vorgänge.
- Der PDF-Dossierexport wählt ausdrücklich eine Einsatz-ID und darf deshalb
  auch einen nicht aktiven historischen Einsatz lesen. Die
  Datenbankabfragen laufen in einem konsistenten Read-only-Snapshot und sind
  sämtlich auf diese ID vorbereitet. ETB und TBB können als Gesamtbuch oder
  bei historisch belegter Provenienz für eine frühere formale Dienstschicht
  ausgegeben werden. Dieser Legacy-Filter erfasst keine neuen Zeilen mit
  `NULL`-Provenienz und keine Zugangsschicht. Er filtert nur diese beiden
  Tabellen; alle anderen gewählten Dossierbereiche bleiben einsatzweit.
  Deckblatt und `pdf_export`-Audit speichern den aufgelösten Umfang samt
  historischen Schichtmetadaten.

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
technische Archivnummer und Richtung erneut. Die Liste selbst zeigt davon
getrennt die kanonische lokale TBB-Nachweisnummer oder ehrlich, dass noch kein
TBB-Nachweis vorliegt. Der in der Liste sichtbare aktuelle
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
Einsatzes. JPEG, PNG, GIF und BMP erscheinen als sichtbare Anlagenseiten,
PDF-Anlagen werden samt Anmerkungen geordnet seitenweise gerastert und verlustfrei
Windows-1252-darstellbarer Text erscheint durchsuchbar. TIFF, nicht
darstellbarer Text und andere nicht statisch darstellbare Formate erhalten eine
Hinweisseite. Unabhängig davon liegen alle Anhänge mit ihren geprüften,
bytegleichen Bytes im eingebetteten Dateikatalog des Dossiers. Fileinfo bindet
den MIME-Typ an genau diesen eingebetteten Byte-Snapshot. Bei neuen
Dateien belegt der beim Eingang gespeicherte SHA-256 samt Bytezahl die
Übereinstimmung; beim Upgrade vorhandene Legacy-Dateien werden
als „Integrität beim Eingang nicht belegbar“ ausgewiesen.
Da die Legacy-Datenbank keine Matrixhistorie pro Einsatz besitzt, werden
Empfängerfunktionen, die in der aktuellen Matrix fehlen, zusätzlich mit ihrem
gespeicherten Kopiekennzeichen im Inhaltsbereich ausgeschrieben.

## Migrations- und Testnachweis

Die checksum-gebundene Folge 45/50/55/80/94/95/96/97/110/111/112/115 ist additiv. Ein
harter Abbruch bleibt bis zur kontrollierten Prüfung des Migrationsledgers
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
`EINSATZTAGEBUCH`-Fähigkeit ein. Migration 97 ergänzt den nullable
Führungsstellennamen sowie dessen nicht-nullbaren dauerhaften Sperrmarker,
ohne aus anderen Stammdaten oder einer Umgebungsvorgabe historische Werte zu
erfinden. Migration 110 ergänzt die lokalen ETB-/TBB-Nummern, den sperrbaren
Kopf je Buchart und den Einsatz-Insert-Trigger für exakt zwei Köpfe, die
strukturierten TBB-Felder, TBB-Nachrichten-/Korrekturbezüge,
den eindeutigen ETB-Anhangsbezug, Append-only-Trigger für beide Bücher und die
zehnjährige Mindestaufbewahrung. Bereits vorhandene mehrfache Verknüpfungen
desselben Anhangs blockieren das Upgrade mit explizitem Fehler, statt eine
Zuordnung zu verwerfen oder umzudeuten.
Historische Buchzeilen werden innerhalb ihres Einsatzes nach Erfassungszeit
und stabilem globalem Schlüssel nummeriert; TBB-Aktion und -Bemerkung bleiben
als ausdrücklich importierter Bestand erhalten.
Migration 111 ergänzt die unveränderliche Dienstschicht-, Schreiber- und
optionale ETB-Bearbeitungszuordnung. Bei historischen Zeilen bleibt eine nicht
belegbare Herkunft ausdrücklich `NULL`. Die Migration ersetzt außerdem den
Triggervertrag für aktive Schichten: Eine neue oder nachträglich angenommene
ETB-Besetzung darf eine bereits akzeptierte S2-/ETB-Buchführung nicht
verdrängen; ein Schreiberwechsel ist nur über die bestätigte persönliche
Schichtübergabe zulässig. Eigene unterbrochene DDL-Zwischenstände werden
kontrolliert fortgesetzt, fremde Spalten-, Index-, Fremdschlüssel- oder
Triggerdefinitionen blockieren das Upgrade.

Migration 112 führt die optionalen Zugangsschichten und ihre
Kontenzuordnungen ein. Sie ersetzt den abschließenden Triggervertrag, ohne die
checksum-gebundenen Migrationen 94, 110 oder 111 umzuschreiben: Neue
ETB-/TBB-Zeilen benötigen einen aktiven Einsatz und die feste fachlich
zulässige Kontofunktion, aber keine aktive Dienstschicht oder Besetzungs-ID.
Nicht belegbare Legacy-Schicht- und Schreiberfelder bleiben `NULL`.

Migration 115 ergänzt `nv_einsaetze.estab_permission_mode` mit dem
kanonischen Standard `STRICT`; dadurch bleiben Bestands- und neue Einsätze
ohne administrative Entscheidung unverändert streng. Kollisionsgeprüfte
Guard-Trigger blockieren unmarkierte Legacy-DML, kombinierte Änderungen
weiterer Einsatzfelder und eine unbestätigte Neuanlage mit `LOOSE` im
Anwendungsweg. Die connection-lokalen Marker sind dabei ein
Konsistenzvertrag, keine Privileggrenze gegen einen Principal mit beliebigen
SQL-Rechten; Datenbank-Zugangsdaten gehören zur vertrauenswürdigen
Betriebsumgebung. Die Migration ersetzt nur die aktuell
rollenprüfenden ETB-/TTB-, S6-Plan- und Melder-Trigger durch modebewusste
Fassungen: `STRICT` bewahrt den Vertrag von Migration 112, `LOOSE` verlangt
weiterhin das konkrete aktive und ungesperrte Konto sowie sämtliche Einsatz-,
Zustands-, Beziehungs-, Nummern-, Provenienz- und Append-only-Regeln, verzichtet
aber auf die dortige Funktions-/Rollenprüfung. Veröffentlichte
Vorgängerdateien und historische Daten werden nicht umgeschrieben.

Migration 50 bleibt bytegenau auf der bereits im Ledger verwendeten
Prüfsumme. Vor- oder Nachbedingungen werden ausschließlich in neuen
Versionsdateien ergänzt; weder der Ledger noch eine veröffentlichte SQL-Datei
wird für ein Upgrade umgeschrieben.

Der fokussierte Quell- und Validierungsvertrag ist
`tests/php/incident_domain_security.php`. `tests/integration/incident_domain.php`
führt den Domänenvertrag in einer eigens migrierten MariaDB aus und prüft
Parallelaktivierung, No-active-INSERT, Update-/Delete-Sperre,
Reassignment-Versuch und konkurrierende Statusänderung. Der
Schema-Migratortest belegt Legacy-Backfill, lokale Buchnummern,
Wiederanlauffähigkeit und Wiederholbarkeit.
`tests/integration/dv_evidence.php` beweist Abschluss-Preflight,
Append-only-ETB/TBB, referenzierte Korrekturen, Hashketten, Terminalbindung,
Mindestaufbewahrung und Legal Hold. `tests/integration/dv_operations.php`
beweist Pflichtkopf, die im strengen Modus festen funktionsabhängigen Rechte,
S6-Versionierung, Melderbindung sowie die Schreibsperre ohne aktiven Einsatz
und die erlaubte Arbeit ohne aktive Schicht. Ergänzende statische und
integrative Berechtigungsmodustests müssen belegen, dass `LOOSE` nur
Rollen-/Funktions-Schreibprüfungen lockert und alle übrigen Grenzen in beiden
Modi identisch bleiben.
`tests/integration/incident_export.php` erzeugt zusätzlich ETB, TBB,
Nachricht und Anhang mit Eingangsnachweis in zwei Einsätzen, exportiert den inzwischen
historischen ausgewählten Einsatz und extrahiert dessen PDF-`EmbeddedFile`
bytegleich samt SHA-256. Der vollständige CI-Lauf schließt den
Backup-/Restore-Roundtrip an.
