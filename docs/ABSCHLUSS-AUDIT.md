# Abschluss-Audit vom 3. August 2026

> Vollständig abgeschlossener lokaler Nachweisstand: Am 3. August 2026 endete der
> server-, datenbank-, HTTP-, Browser-, Container- und Wiederherstellungslauf
> von `tests/integration/ci.sh` einschließlich Migration 118 mit Exitcode 0
> und `CI integration: OK`. Die separat mit der festgelegten
> unprivilegierten Containeridentität ausgeführte statische PHP-8.5-Suite
> endete ebenfalls mit Exitcode 0 und lintete 277 aktive PHP-Dateien.

> Browser-Geltungsgrenze: Chrome 150 bestand den allgemeinen UI-Lauf und die
> S6-Fernmeldeplan-Versionierung einschließlich mobiler Darstellung. Für die
> Umschaltung `STRICT`/`LOOSE`, die sichtbare Warnung und einen
> Mehrfunktions-Schreibfall mit expliziter Wahl der handelnden Funktion
> existiert jedoch weiterhin kein eigenes
> Browserszenario. Diese Modusprüfung bleibt Bestandteil der manuellen
> Fachabnahme beziehungsweise einer noch zu ergänzenden dedizierten
> Automatisierung. Statische, MariaDB- und authentifizierte HTTP-Nachweise für
> den mit Migration 118 geltenden Modusvertrag waren Teil des erfolgreichen
> lokalen Laufs.

Dieses Audit beschreibt den am 3. August 2026 lokal geprüften Quellstand mit
Migrationen bis einschließlich Migration 118. Es trennt automatisiert
nachgewiesene Eigenschaften von noch ausstehenden externen Freigaben und
manuellen Abnahmen.

## Ergebnis

Quell-, Datenbank-, HTTP-, Browser-, Container- und Wiederherstellungsstand
sind für den unten protokollierten Lauf technisch grün. Der Browsernachweis
deckt den allgemeinen UI-Lauf und den Fernmeldeplan ab, nicht jedoch die
Bedienung des Berechtigungsmodus. Dies ist keine formale fachliche oder
öffentliche Produktfreigabe.

Eine öffentliche OCI-Veröffentlichung ist trotzdem nicht freigegeben:
`LICENSE`, `THIRD_PARTY_NOTICES.md` und die abgeschlossene Rechteinventur des
historischen Bestands fehlen bewusst. Der Publish-Workflow verweigert deshalb
fail-closed jede Veröffentlichung. Es existiert kein in diesem Repository
dokumentiertes, freigegebenes App-/Migrator-Digestpaar.

## Anforderung und Nachweis

Die Nachweismatrix trennt optionale **Zugangsschichten** zur gemeinsamen
Kontozugangssteuerung in `LOOSE` von formalen **Dienstschichten** in `STRICT`.
Im strengen Modus stammen operative Identität und Rechte aus der persönlich
angenommenen und ausgewählten Besetzung einer aktiven Dienstschicht. Im
lockeren Modus stammen sie aus fester Kontofunktion und expliziten globalen
Zusatzfunktionen; es gibt keine pauschale Freigabe.

| Anforderung | Umsetzung | Nachweis |
| --- | --- | --- |
| Keine blockierende „Anmeldung erforderlich“-Textseite | Direkte geschützte GET-Aufrufe antworten mit HTTP 303 zum Bestandslogin. Nur symbolische Allowlist-Ziele werden als `next` übernommen. Abgelaufene POST-Inhalte werden nicht wiederholt. Der Login besitzt einen sichtbaren Abbruch zur öffentlichen Übersicht. Frame-lokale Abläufe verwenden ein Content-Login und erzeugen keinen verschachtelten Arbeitsbereich. | `tests/php/auth_security.php`, `tests/php/navigation_security.php`, HTTP-Surface, Auth-Smoke und allgemeiner UI-Lauf in Chrome 150 erfolgreich |
| Bedienbare, einheitliche Navigation | Gemeinsame Bereichsnavigation, Sitzungsanzeige, Logout, Sidebar, responsive Karten, BOS-Arbeitsbereich und Dirty-Guard | statische UI-Verträge, HTTP-Surface und allgemeiner UI-Lauf in Chrome 150 erfolgreich |
| Einsatzbezogener Berechtigungsmodus | `STRICT` verlangt die angenommene und ausgewählte Besetzung einer aktiven formalen Dienstschicht. `LOOSE` benötigt keine formale Dienstschicht, erzwingt aber feste Kontofunktion oder explizite Zusatzfunktion. Objekt-, Stufen-, Konto-, Einsatz-, CSRF-, Audit- und Integritätsgrenzen bleiben bestehen. | Permission-Mode-Vertrag 99 Assertions, Anhang-Scope 30 Assertions, Single-Dispatch 249 Assertions sowie die MariaDB-/HTTP-Matrizen im vollständigen Lauf bis Migration 118; die dedizierte Browser-Modusabnahme bleibt offen |
| Skalierbare Nachrichtensuche | Meldungsübersicht und beide Varianten der zweiten Sichtung teilen immer sichtbare Suche, kombinierbare Filter, Filterchips, eindeutige Treffer-/Seitenangaben, stabile Sortierung und responsive Ergebnisdarstellung. Zählung und Seite werden serverseitig nach Einsatz- und Rechtegrenze bestimmt. | 99 Parser-/SQL-Assertions, 108 UI-Assertions, authentifizierter HTTP-Lauf mit S2, Fernmelder und Si sowie 145 MariaDB-Assertions mit 10.000 Ziel- und 257 Fremdeinsatzmeldungen |
| Dienstvorschriftsgebundener Betrieb | Aktiver Einsatz, Führungsstellenname, persönliche Dienstbesetzungen in `STRICT`, feste und zusätzliche Kontofunktionen in `LOOSE`, optionale lockere Zugangsschichten, verbindlicher Eingangs-/Ausgangslauf, Sichtung, LdF-Entscheidung, Transportnachweis, S6-Plan, Melderlauf und unveränderliche Ereignisketten | Aktueller MariaDB-/HTTP-Nachweis bis Migration 118: DV-Evidenz 52 Assertions sowie DV-Operations 236 Assertions und 95 Ereignisse |
| Versionierter Fernmeldeplan | „Bearbeitung starten“ kopiert den vollständigen aktiven Plan mit neuen Wege-IDs in genau einen Entwurf. Kopf und Wege lassen sich ändern, ergänzen und löschen; die Auswahl schreibt `Fernsprecher`, `Funk`, `Melder`, `Telefax`, `Fernschreiber` und `Datenübertragung` aus und zeigt nur mediengerechte Felder. Dirty-Schutz verhindert unbeabsichtigten Verlust. Erst das Aktivieren veröffentlicht eine neue unveränderliche Version; alte und verworfene Fassungen bleiben mit Kopf, Wegen und Ereignissen lesbar. | `tests/php/telecom_plan_security.php`, echte MariaDB in `tests/integration/dv_operations.php` mit exaktem Kopiervergleich und konkurrierendem Bearbeitungsstart, authentifizierter HTTP-Lauf sowie `tests/browser/headless_ui.py --telecom-plan` in Chrome 150 einschließlich Edit/Add/Delete, Medienumschaltung, Dirty-Schutz, Publizieren, Historie und `390 × 844` CSS-Pixeln erfolgreich |
| Benutzer-, Rollen- und Kennwortschutz | Administrativ provisionierte Konten, Sperren, Entsperren, Kennwortreset, Sitzungswiderruf, feste serverseitige Funktion/Rolle und explizite globale Zusatzfunktionen ausschließlich für `LOOSE` sowie eine revisionsgesicherte prospektive Kennwortrichtlinie. Die konfigurierbare Mindestlänge liegt bei 8–128 Unicode-Codepoints (Standard 12); höchstens 1024 UTF-8-Bytes und optionale Unicode-Zeichenklassen gelten für Anlage, Reset und aktivierte Selbstregistrierung. Browserfelder erlauben 1024 Eingabeeinheiten und zählen die Mindestlänge exakt in Codepoints; die Serverprüfung bleibt verbindlich. Titlecase erfüllt die Großbuchstabenpflicht, Unicode-Steuerzeichen sind verboten, Formatzeichen einschließlich ZWJ erlaubt. Neue und geänderte Kennwörter werden mit Argon2id gespeichert; Klartext und eindeutige Alt-Hashes werden nach erfolgreichem Login migriert. bcrypt wird nur bei einem eingegebenen Kennwort unter 72 UTF-8-Bytes automatisch migriert, ein längerer ambivalenter Alt-Hash benötigt einen administrativen Reset. Stärkere oder gemischte Argon2id-Kosten werden nicht zurückgestuft. Sitzungen und das getrennte Basic-Auth-Secret bleiben unberührt. | Aktueller Lauf bis Migration 118: 98 Assertions zur Benutzerverwaltung und Zusatzfunktionsänderung, 66 Kennwortrichtlinien-Assertions sowie erfolgreicher Administrationsworkflow über HTTP |
| Kontrollierte Selbstregistrierung | Die Administration kann die öffentliche Kontoanlage sofort deaktivieren, dauerhaft aktivieren oder ab jetzt für 15 Minuten bis 24 Stunden freigeben. Befristungen enden ohne Hintergrunddienst exakt nach Datenbank-UTC. Ein globaler Lock, Revisionen und der atomische Konto-INSERT verhindern, dass ein bereits geöffnetes Formular eine Deaktivierung oder Ablaufgrenze überholt. Bestehende Konten, Anmeldungen und Sitzungen bleiben unabhängig. | 31 echte MariaDB-Assertions, 28 Handler-Assertions und erfolgreicher Basic-Auth-/CSRF-geschützter HTTP-Lauf; aktuelle Browserabnahme offen |
| Belastbare Präsenzanzeige | Echte Browserinteraktion hält die Fachsitzung aktiv; nach 15 Minuten erscheint sie inaktiv, nach 12 Stunden wird sie serverseitig widerrufen. Statuspolls und automatische Refreshes zählen nicht. Der Aktivitätsendpunkt verlangt POST, gültige SID und Session-CSRF; PHP-GC ist auf 43.200 Sekunden angeglichen. HTTP Basic Auth bleibt separat. | Grenzwertmatrix in `tests/php/auth_security.php`; Monitorvertrag in `tests/php/session_ui_security.php`; Aktiv-/Inaktivdarstellung in `tests/php/sidebar_ui_security.php`; Endpoint- und Sitzungsablauf in `tests/integration/http_surface_http.sh` und `tests/integration/http_smoke.sh` |
| Einsatzbezogene Daten und Exporte | ETB, TTB, Nachrichten, Anhänge, Vordrucke, Tabellenexport und PDF-Dossier bleiben einsatzgebunden | HTTP-, Export-, PDF- und Restore-Integrationen; Incident-Export 35 Assertions |
| Einsatzbereite globale Kategorien | Eine vollständig leere globale Liste erhält einmalig die editier- und löschbaren Vorgaben `Allgemein` sowie `EA1` bis `EA6`. Bereits vorhandene Betreiberkataloge werden nicht ergänzt oder überschrieben; spätere Änderungen und Löschungen werden nicht wiederhergestellt. | Migration 116 im Schema-Migratortest für frische und leere Legacy-Bestände, nichtleeren Betreiberkatalog sowie idempotenten Zweitlauf; Kategorien-HTTP-Integration |
| Reproduzierbare Herkunft | 13 Git-Ref-Snapshots und ein separater Dokument-r85-Baum sind selbsttragend gebunden | `migration/verify_provenance.py --self-test`: 14 Subjects sowie beide Manipulationsfälle grün |
| Sicherer Containerstart | Gepinnte PHP-/MariaDB-Basen, erweiterte Schema-Prüfungen, checksumgebundene aktuelle Migrationsfolge, Health-Gates und getrennte Netze | vollständiger server-/containerseitiger CI-Lauf und PHP-8.5-Suite |
| Isoliertes Admin-Kennwort | Nur der netzlose One-shot `admin-auth-init` liest das Klartextsecret. Die App erhält ausschließlich eine bcrypt-Datei mit Kostenfaktor 12 schreibgeschützt. | 11 Secret-Isolationsassertionen sowie Container-Inspect und HTTP 401/200 |
| NAS-/Registry-Betrieb | Pull-only Compose, digestgebundene Releaseidentität, private Konfigurations-/Secret-Snapshots, bestehende und getrennte Speicherquellen, engine-weite Wartungssperre sowie fail-closed Backup/Restore | Registry-Deployment-Vertrag 57 Assertions; Release-, Backup- und Restore-Operator-Tests; echter Named-Volume- und Bind-Mount-Lauf |
| Wiederherstellbarkeit | Logischer MariaDB-Dump und beide Dateibereiche werden im aktuellen Format 3 verifiziert und kontrolliert wiederhergestellt. Archivdaten werden über interaktive Standardeingabe in netzlose Hilfscontainer übertragen; Format 2 bleibt nur für den exakten Same-Host-Kompatibilitätsfall lesbar. | Format-3-Bind-Restore, anschließender vollständiger benannter Volume-Roundtrip, Marker-/SHA-256-, Login-, Export- und Schemanachweis |

## Abschließende automatische Läufe

Ausgeführt wurden beide Repository-Entrypoints:

```console
bash tests/integration/ci.sh
tests/static/run.sh
```

Der Integrationsorchestrator endete nach dem vollständigen
Backup-/Restore-Roundtrip mit Exitcode 0 und `CI integration: OK`. Der
protokollierte Kernstand lautet:

| Teilnachweis | Ergebnis |
| --- | --- |
| Migration und Schema | 24 Migrationen bis einschließlich Migration 118 angewendet; 42 Schema-Prüfungen |
| Einsatzdomäne | 69 Assertions |
| DV-Evidenz | 52 Assertions |
| DV-Operations einschließlich STRICT/LOOSE und Fernmeldeplan | 236 Assertions, 95 Ereignisse |
| Optionale Zugangsschichten | 29 Assertions |
| Benutzerverwaltung | 98 Assertions |
| Kennwortrichtlinie gegen MariaDB | 66 Assertions |
| Selbstregistrierung | 31 Datenbank- und 28 Handler-Assertions |
| Zuordnungs-/Empfängermatrix | 74 Assertions |
| PDF-Einsatzdossier | 35 Assertions |
| Sichtbare Anhangdarstellung | 11 Assertions |
| Einsatzbezogene Nachrichtenvorschläge | 32 Assertions |
| Nachrichtensuche mit 10.000 Zielzeilen | 145 Assertions |
| Echter Browser | Chrome 150: öffentliche BOS-Informationen, Web-Handbuch, allgemeiner 12-Schritt-UI-Lauf, S6-Fernmeldeplan-Versionierung, Meldungsüberschriften und Nachrichtenvorschläge erfolgreich; Fernmeldeplan, Meldungsübersicht und allgemeine UI zusätzlich mobil geprüft |

HTTP-Surface, Selbstregistrierung, Auth-Smoke, Logbücher, Kategorien,
Nachrichtenworkflow, Administrationsworkflow sowie Backup und Restore endeten
ebenfalls erfolgreich. Pull-only- und Speicher-Roundtrips, Rollen-,
Parallelitäts-, Anhang-, Export- und Wiederherstellungsgrenzen waren damit im
server-/containerseitigen Gate enthalten.

Das verpflichtende Browser-Gate lief in Chrome 150 erfolgreich. Es deckte die
öffentlichen BOS-Informationen, das Web-Handbuch, den allgemeinen
12-Schritt-UI-Lauf, die S6-Fernmeldeplan-Versionierung,
Meldungsüberschriften und Nachrichtenvorschläge ab. Ein dediziertes
Browserszenario für die `STRICT`/`LOOSE`-Umschaltung, ihre Warnanzeige und
einen Mehrfunktions-Schreibweg mit expliziter Wahl der handelnden Funktion
fehlt weiterhin; diese Modusabnahme bleibt manuell beziehungsweise als eigene
Automatisierung offen.

Dieser lokale Podman-Lauf wurde nicht auf einem nachgewiesenen
SELinux-Enforcing-System ausgeführt. Er ist deshalb kein Nachweis für das
tatsächliche Relabeling unter SELinux; die dafür im Testhandbuch beschriebene
Abnahme bleibt offen.

Die anschließend separat mit der festgelegten unprivilegierten
Containeridentität ausgeführte statische PHP-8.5-Suite endete mit Exitcode 0
und lintete 277 aktive PHP-Dateien. Darin bestanden insbesondere der
Berechtigungsmodusvertrag mit 99 Assertions, der exakte
LOOSE-Anhang-Scope mit 30 Assertions und der Single-Dispatch-Vertrag mit 249
Assertions. Die übrigen statischen Sicherheits-, Workflow-, Shell-,
Provenienz-, PDF- und GitHub-Actions-Verträge waren Bestandteil desselben
erfolgreichen Gesamtlaufs.

## Git- und Remote-Geltungsgrenze

Dieses Audit schreibt bewusst weder einen flüchtigen HEAD-Hash noch einen
Abstand von `main` zu `origin/main` fest. Vor einer Veröffentlichung sind
Commit, Branchstatus, Tags und Remote-Workflow unmittelbar mit Git
beziehungsweise GitHub zu verifizieren. Der hier dokumentierte erfolgreiche
Lauf ist ein lokaler Nachweis und allein keine Aussage über einen Remote-Lauf
oder den Veröffentlichungsstand von Issues.

Für die Remote-Veröffentlichung erneut zu prüfen sind:

- `svn-r85`,
- die sechs historischen SVN-Tags `ver0.9.09`, `ver0.9.10`, `ver0.9.11`,
  `ver0.9.12`, `ver0.9.20` und `ver0.9.20b`,
- die beiden separat gebundenen SourceForge-Tags `ver0.9.26b` und
  `ver0.9.26c`.

Ob diese Tags bereits auf `origin` vorhanden sind, ist unmittelbar vor der
Veröffentlichung neu zu prüfen. Der lokal geprüfte Quellstand bis Migration
118 besitzt den oben dokumentierten erfolgreichen CI-, Browser- und
statischen Nachweis. Daraus folgt nichts über einen noch nicht gelaufenen
Remote-Workflow.

## Noch ausstehende Freigaben

Vor einer öffentlichen oder produktiven Freigabe bleiben zwingend:

1. Historische Rechte inventarisieren und echte, geprüfte Dateien
   `LICENSE` und `THIRD_PARTY_NOTICES.md` bereitstellen.
2. GitHub-/SSH-Zugang herstellen, `main` und sämtliche historischen Tags
   pushen und den dann laufenden Remote-CI-Stand beobachten.
3. Erst nach der Rechtefreigabe ein Release aus vorhandenem Git-Tag über das
   geschützte Environment veröffentlichen und beide finalen OCI-Digests
   protokollieren.
4. Auf dem tatsächlichen Zielgerät beziehungsweise Synology-NAS installieren,
   Backup und Restore proben sowie Port-, TLS-, Benutzer- und
   Dateiberechtigungen abnehmen.
5. Physische Hörbarkeit der Warteschlangensignale prüfen; RIFF/WAVE,
   PCM-16, SHA-256, Dauer, Signalspitze und Mindest-RMS sind bereits
   automatisiert belegt.
6. Repräsentative PDFs in den tatsächlich eingesetzten PDF-Viewern prüfen.
7. Die historische Funktions-, Rollen-, Formular- und Empfängermatrix mit
   fachkundigen Anwendern vollständig abnehmen.

Bis diese Punkte einschließlich der manuellen Browser-Modusabnahme erledigt
sind, ist der aktuelle Quellstand weder als öffentliches Containerrelease noch
als abschließend fachlich produktionsfreigegeben zu bezeichnen.
