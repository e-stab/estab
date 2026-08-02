# Abschluss-Audit vom 2. August 2026

> Aktueller Nachweisstand: Am 2. August 2026 endete der vollständige
> server-, datenbank-, HTTP-, Browser-, Container- und Wiederherstellungslauf
> von `tests/integration/ci.sh` einschließlich Migration 117 mit Exitcode 0
> und `CI integration: OK`. Die anschließend separat mit der festgelegten
> unprivilegierten Containeridentität ausgeführte statische PHP-8.5-Suite
> endete ebenfalls mit Exitcode 0 und lintete 274 aktive PHP-Dateien.

> Browser-Geltungsgrenze: Chrome 150 bestand den allgemeinen UI-Lauf und die
> S6-Fernmeldeplan-Versionierung einschließlich mobiler Darstellung. Für die
> Umschaltung `STRICT`/`LOOSE`, die sichtbare Warnung und einen
> Cross-Rollen-Schreibfall existiert jedoch weiterhin kein eigenes
> Browserszenario. Diese Modusprüfung bleibt Bestandteil der manuellen
> Fachabnahme beziehungsweise einer noch zu ergänzenden dedizierten
> Automatisierung. Statische, MariaDB- und authentifizierte HTTP-Nachweise für
> den Modus waren Teil des erfolgreichen Laufs.

Dieses Audit beschreibt den am 2. August 2026 geprüften Arbeitsstand mit allen
23 Migrationen bis einschließlich Migration 117. Es trennt automatisiert
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
Kontozugangssteuerung von den nur noch als **historische Dienstschichtevidenz**
erhaltenen formalen Dienstschichten. Fachrechte stammen aus der festen
Kontofunktion; eine persönliche Schichtannahme ist keine Betriebsbedingung.

| Anforderung | Umsetzung | Nachweis |
| --- | --- | --- |
| Keine blockierende „Anmeldung erforderlich“-Textseite | Direkte geschützte GET-Aufrufe antworten mit HTTP 303 zum Bestandslogin. Nur symbolische Allowlist-Ziele werden als `next` übernommen. Abgelaufene POST-Inhalte werden nicht wiederholt. Der Login besitzt einen sichtbaren Abbruch zur öffentlichen Übersicht. Frame-lokale Abläufe verwenden ein Content-Login und erzeugen keinen verschachtelten Arbeitsbereich. | `tests/php/auth_security.php`, `tests/php/navigation_security.php`, HTTP-Surface, Auth-Smoke und allgemeiner UI-Lauf in Chrome 150 erfolgreich |
| Bedienbare, einheitliche Navigation | Gemeinsame Bereichsnavigation, Sitzungsanzeige, Logout, Sidebar, responsive Karten, BOS-Arbeitsbereich und Dirty-Guard | statische UI-Verträge, HTTP-Surface und allgemeiner UI-Lauf in Chrome 150 erfolgreich |
| Einsatzbezogener Berechtigungsmodus | `STRICT` bewahrt die bisherigen festen Funktions-/Rollengates. `LOOSE` lockert nur die vorgesehenen operativen Schreibwege von Nachrichtenworkflow, ETB/TBB, S6-Planung und Melderlauf; Konto-, Einsatz-, Objektstatus-, CSRF-, Audit- und Integritätsgrenzen bleiben bestehen. | Permission-Mode-Vertrag 80 Assertions, Anhang-Scope 29 Assertions, Single-Dispatch 222 Assertions, echte MariaDB-Matrizen in Einsatz-, DV-Operations- und Nachrichten-Parallelitätstests sowie authentifizierter HTTP-Nachrichtenlauf; Browser-Modusabnahme offen |
| Skalierbare Nachrichtensuche | Meldungsübersicht und beide Varianten der zweiten Sichtung teilen immer sichtbare Suche, kombinierbare Filter, Filterchips, eindeutige Treffer-/Seitenangaben, stabile Sortierung und responsive Ergebnisdarstellung. Zählung und Seite werden serverseitig nach Einsatz- und Rechtegrenze bestimmt. | 99 Parser-/SQL-Assertions, 108 UI-Assertions, authentifizierter HTTP-Lauf mit S2, Fernmelder und Si sowie 145 MariaDB-Assertions mit 10.000 Ziel- und 257 Fremdeinsatzmeldungen |
| Dienstvorschriftsgebundener Betrieb | Aktiver Einsatz, Führungsstellenname, feste persönliche Kontofunktionen, optionale Zugangsschichten, getrennte historische Dienstschichtevidenz, verbindlicher Eingangs-/Ausgangslauf, Sichtung, LdF-Entscheidung, Transportnachweis, S6-Plan, Melderlauf und unveränderliche Ereignisketten | MariaDB-Integrationen: Einsatz 55 Assertions, DV-Nachweis 51 Assertions, DV-Operations 171 Assertions und 99 Ereignisse sowie Zugangsschichten 25 Assertions; vollständiger HTTP-Nachrichtenlauf erfolgreich |
| Versionierter Fernmeldeplan | „Bearbeitung starten“ kopiert den vollständigen aktiven Plan mit neuen Wege-IDs in genau einen Entwurf. Kopf und Wege lassen sich ändern, ergänzen und löschen; die Auswahl schreibt `Fernsprecher`, `Funk`, `Melder`, `Telefax`, `Fernschreiber` und `Datenübertragung` aus und zeigt nur mediengerechte Felder. Dirty-Schutz verhindert unbeabsichtigten Verlust. Erst das Aktivieren veröffentlicht eine neue unveränderliche Version; alte und verworfene Fassungen bleiben mit Kopf, Wegen und Ereignissen lesbar. | `tests/php/telecom_plan_security.php`, echte MariaDB in `tests/integration/dv_operations.php` mit exaktem Kopiervergleich und konkurrierendem Bearbeitungsstart, authentifizierter HTTP-Lauf sowie `tests/browser/headless_ui.py --telecom-plan` in Chrome 150 einschließlich Edit/Add/Delete, Medienumschaltung, Dirty-Schutz, Publizieren, Historie und `390 × 844` CSS-Pixeln erfolgreich |
| Benutzer-, Rollen- und Kennwortschutz | Administrativ provisionierte Konten, Sperren, Entsperren, Kennwortreset, Sitzungswiderruf, feste serverseitige Funktion/Rolle sowie eine revisionsgesicherte prospektive Kennwortrichtlinie. Die konfigurierbare Mindestlänge liegt bei 8–128 Unicode-Codepoints (Standard 12); höchstens 1024 UTF-8-Bytes und optionale Unicode-Zeichenklassen gelten für Anlage, Reset und aktivierte Selbstregistrierung. Browserfelder erlauben 1024 Eingabeeinheiten und zählen die Mindestlänge exakt in Codepoints; die Serverprüfung bleibt verbindlich. Titlecase erfüllt die Großbuchstabenpflicht, Unicode-Steuerzeichen sind verboten, Formatzeichen einschließlich ZWJ erlaubt. Neue und geänderte Kennwörter werden mit Argon2id gespeichert; Klartext und eindeutige Alt-Hashes werden nach erfolgreichem Login migriert. bcrypt wird nur bei einem eingegebenen Kennwort unter 72 UTF-8-Bytes automatisch migriert, ein längerer ambivalenter Alt-Hash benötigt einen administrativen Reset. Stärkere oder gemischte Argon2id-Kosten werden nicht zurückgestuft. Sitzungen und das getrennte Basic-Auth-Secret bleiben unberührt. | Benutzerverwaltung 98 Assertions und Kennwortrichtlinie 66 echte MariaDB-Assertions; statische Kennwort-/Authentisierungsverträge und Admin-/Auth-HTTP-Läufe erfolgreich |
| Kontrollierte Selbstregistrierung | Die Administration kann die öffentliche Kontoanlage sofort deaktivieren, dauerhaft aktivieren oder ab jetzt für 15 Minuten bis 24 Stunden freigeben. Befristungen enden ohne Hintergrunddienst exakt nach Datenbank-UTC. Ein globaler Lock, Revisionen und der atomische Konto-INSERT verhindern, dass ein bereits geöffnetes Formular eine Deaktivierung oder Ablaufgrenze überholt. Bestehende Konten, Anmeldungen und Sitzungen bleiben unabhängig. | 31 echte MariaDB-Assertions, 28 Handler-Assertions und erfolgreicher Basic-Auth-/CSRF-geschützter HTTP-Lauf; aktuelle Browserabnahme offen |
| Belastbare Präsenzanzeige | Echte Browserinteraktion hält die Fachsitzung aktiv; nach 15 Minuten erscheint sie inaktiv, nach 12 Stunden wird sie serverseitig widerrufen. Statuspolls und automatische Refreshes zählen nicht. Der Aktivitätsendpunkt verlangt POST, gültige SID und Session-CSRF; PHP-GC ist auf 43.200 Sekunden angeglichen. HTTP Basic Auth bleibt separat. | Grenzwertmatrix in `tests/php/auth_security.php`; Monitorvertrag in `tests/php/session_ui_security.php`; Aktiv-/Inaktivdarstellung in `tests/php/sidebar_ui_security.php`; Endpoint- und Sitzungsablauf in `tests/integration/http_surface_http.sh` und `tests/integration/http_smoke.sh` |
| Einsatzbezogene Daten und Exporte | ETB, TTB, Nachrichten, Anhänge, Vordrucke, Tabellenexport und PDF-Dossier bleiben einsatzgebunden | HTTP-, Export-, PDF- und Restore-Integrationen; Incident-Export 35 Assertions |
| Einsatzbereite globale Kategorien | Eine vollständig leere globale Liste erhält einmalig die editier- und löschbaren Vorgaben `Allgemein` sowie `EA1` bis `EA6`. Bereits vorhandene Betreiberkataloge werden nicht ergänzt oder überschrieben; spätere Änderungen und Löschungen werden nicht wiederhergestellt. | Migration 116 im Schema-Migratortest für frische und leere Legacy-Bestände, nichtleeren Betreiberkatalog sowie idempotenten Zweitlauf; Kategorien-HTTP-Integration |
| Reproduzierbare Herkunft | 13 Git-Ref-Snapshots und ein separater Dokument-r85-Baum sind selbsttragend gebunden | `migration/verify_provenance.py --self-test`: 14 Subjects sowie beide Manipulationsfälle grün |
| Sicherer Containerstart | Gepinnte PHP-/MariaDB-Basen, erweiterte Schema-Prüfungen, 23 checksumgebundene Migrationen, Health-Gates und getrennte Netze | vollständiger server-/containerseitiger CI-Lauf, 42 Schema-Prüfungen und PHP-8.5-Suite |
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
| Migration und Schema | 23 Migrationen bis einschließlich Migration 117 angewendet; 42 Schema-Prüfungen |
| Einsatzdomäne | 55 Assertions |
| DV-Evidenz | 51 Assertions |
| DV-Operations einschließlich STRICT/LOOSE und Fernmeldeplan | 171 Assertions, 99 Ereignisse |
| Optionale Zugangsschichten | 25 Assertions |
| Benutzerverwaltung | 98 Assertions |
| Kennwortrichtlinie gegen MariaDB | 66 Assertions |
| Selbstregistrierung | 31 Datenbank- und 28 Handler-Assertions |
| Zuordnungs-/Empfängermatrix | 59 Assertions |
| PDF-Einsatzdossier | 35 Assertions |
| Sichtbare Anhangdarstellung | 11 Assertions |
| Einsatzbezogene Nachrichtenvorschläge | 29 Assertions |
| Nachrichtensuche mit 10.000 Zielzeilen | 145 Assertions |
| Echter Browser | Chrome 150: allgemeiner UI-Lauf und S6-Fernmeldeplan-Versionierung erfolgreich; Fernmeldeplan zusätzlich mobil mit `390 × 844` CSS-Pixeln geprüft |

HTTP-Surface, Selbstregistrierung, Auth-Smoke, Logbücher, Kategorien,
Nachrichtenworkflow, Administrationsworkflow sowie Backup und Restore endeten
ebenfalls erfolgreich. Pull-only- und Speicher-Roundtrips, Rollen-,
Parallelitäts-, Anhang-, Export- und Wiederherstellungsgrenzen waren damit im
server-/containerseitigen Gate enthalten.

Das verpflichtende Browser-Gate lief in Chrome 150 erfolgreich. Es deckte die
allgemeine UI und die S6-Fernmeldeplan-Versionierung ab. Ein dediziertes
Browserszenario für die `STRICT`/`LOOSE`-Umschaltung, ihre Warnanzeige und
einen Cross-Rollen-Schreibweg fehlt weiterhin; diese Modusabnahme bleibt
manuell beziehungsweise als eigene Automatisierung offen.

Dieser lokale Podman-Lauf wurde nicht auf einem nachgewiesenen
SELinux-Enforcing-System ausgeführt. Er ist deshalb kein Nachweis für das
tatsächliche Relabeling unter SELinux; die dafür im Testhandbuch beschriebene
Abnahme bleibt offen.

Die anschließend separat mit der festgelegten unprivilegierten
Containeridentität ausgeführte statische PHP-8.5-Suite endete mit Exitcode 0
und lintete 274 aktive PHP-Dateien. Darin bestanden insbesondere der
Berechtigungsmodusvertrag mit 80 Assertions, der exakte
LOOSE-Anhang-Scope mit 29 Assertions und der Single-Dispatch-Vertrag mit 222
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
Veröffentlichung neu zu prüfen. Der aktuelle lokale Stand bis Migration 117
besitzt den oben dokumentierten erfolgreichen CI-, Browser- und statischen
Nachweis; daraus folgt keine Aussage über einen noch nicht gelaufenen
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
sind, ist der technisch bis Migration 117 erfolgreich geprüfte Stand weder als
öffentliches Containerrelease noch als abschließend fachlich
produktionsfreigegeben zu bezeichnen.
