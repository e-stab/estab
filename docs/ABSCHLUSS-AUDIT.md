# Abschluss-Audit vom 1. August 2026

> Aktueller Nachweisstand: Dieser Audit protokolliert den vollständigen Lauf
> einschließlich Migration 111 und der ETB-/TBB-Regeln. Der geprüfte Stand
> umfasst siebzehn Ledgerzeilen und 38 benannte Schema-Prüfungen.

Dieses Audit beschreibt den lokalen Repository-Stand einschließlich des
Commits, der diese Datei enthält. Es trennt automatisiert nachgewiesene
Eigenschaften von noch ausstehenden externen Freigaben und manuellen
Abnahmen.

## Ergebnis

Der lokale Quell-, Container-, Browser- und Wiederherstellungsstand ist
technisch grün. Der abschließende unabhängige Review enthält keine offenen
technischen Befunde der Stufen Critical, High oder Medium.

Eine öffentliche OCI-Veröffentlichung ist trotzdem nicht freigegeben:
`LICENSE`, `THIRD_PARTY_NOTICES.md` und die abgeschlossene Rechteinventur des
historischen Bestands fehlen bewusst. Der Publish-Workflow verweigert deshalb
fail-closed jede Veröffentlichung. Es existiert kein in diesem Repository
dokumentiertes, freigegebenes App-/Migrator-Digestpaar.

## Anforderung und Nachweis

| Anforderung | Umsetzung | Nachweis |
| --- | --- | --- |
| Keine blockierende „Anmeldung erforderlich“-Textseite | Direkte geschützte GET-Aufrufe antworten mit HTTP 303 zum Bestandslogin. Nur symbolische Allowlist-Ziele werden als `next` übernommen. Abgelaufene POST-Inhalte werden nicht wiederholt. Der Login besitzt einen sichtbaren Abbruch zur öffentlichen Übersicht. Frame-lokale Abläufe verwenden ein Content-Login und erzeugen keinen verschachtelten Arbeitsbereich. | `tests/php/auth_security.php`, `tests/php/navigation_security.php`, `tests/integration/http_smoke.sh`, echter Chrome-Lauf mit allen zwölf Navigationsschritten |
| Bedienbare, einheitliche Navigation | Gemeinsame Bereichsnavigation, Sitzungsanzeige, Logout, Sidebar, responsive Karten, BOS-Arbeitsbereich und Dirty-Guard | `tests/browser/headless_ui.py`, vollständiger Browserlauf mit Chrome 150 |
| Skalierbare Nachrichtensuche | Meldungsübersicht und beide Varianten der zweiten Sichtung teilen immer sichtbare Suche, kombinierbare Filter, Filterchips, eindeutige Treffer-/Seitenangaben, stabile Sortierung und responsive Ergebnisdarstellung. Zählung und Seite werden serverseitig nach Einsatz- und Rechtegrenze bestimmt. | 99 Parser-/SQL-Assertions, 108 UI-Assertions, authentifizierter S2-/A/W-/Si-HTTP-Lauf sowie 145 MariaDB-Assertions mit 10.000 Ziel- und 257 Fremdeinsatzmeldungen |
| Dienstvorschriftsgebundener Betrieb | Aktiver Einsatz, Führungsstellenname, Dienstschichten, persönliche Funktionsbesetzung, verbindlicher Eingangs-/Ausgangslauf, Sichtung, LdF-Entscheidung, Transportnachweis, S6-Plan, Melderlauf und unveränderliche Ereignisketten | MariaDB-Integrationen: Einsatz 40 Assertions, DV-Nachweis 38 Assertions, ETB-/TBB-DV-Betrieb 130 Assertions; vollständiger HTTP-Nachrichtenlauf |
| Benutzer- und Rollenschutz | Administrativ provisionierte Konten, Sperren, Entsperren, Kennwortreset, Sitzungswiderruf, feste serverseitige Funktion/Rolle | Benutzerverwaltung 95 Assertions; Zuweisungsrichtlinie 59 Assertions |
| Belastbare Präsenzanzeige | Echte Browserinteraktion hält die Fachsitzung aktiv; nach 15 Minuten erscheint sie inaktiv, nach 12 Stunden wird sie serverseitig widerrufen. Statuspolls und automatische Refreshes zählen nicht. Der Aktivitätsendpunkt verlangt POST, gültige SID und Session-CSRF; PHP-GC ist auf 43.200 Sekunden angeglichen. HTTP Basic Auth bleibt separat. | Grenzwertmatrix in `tests/php/auth_security.php`; Monitorvertrag in `tests/php/session_ui_security.php`; Aktiv-/Inaktivdarstellung in `tests/php/sidebar_ui_security.php`; Endpoint- und Sitzungsablauf in `tests/integration/http_surface_http.sh` und `tests/integration/http_smoke.sh` |
| Einsatzbezogene Daten und Exporte | ETB, TTB, Nachrichten, Anhänge, Vordrucke, Tabellenexport und PDF-Dossier bleiben einsatzgebunden | HTTP-, Export-, PDF- und Restore-Integrationen; Incident-Export 33 Assertions |
| Reproduzierbare Herkunft | 13 Git-Ref-Snapshots und ein separater Dokument-r85-Baum sind selbsttragend gebunden | `migration/verify_provenance.py --self-test`: 14 Subjects sowie beide Manipulationsfälle grün |
| Sicherer Containerstart | Gepinnte PHP-/MariaDB-Basen, 38 Schema-Prüfungen, siebzehn checksumgebundene Migrationen, Health-Gates und getrennte Netze | vollständiger Podman-CI-Lauf und PHP-8.5-Suite |
| Isoliertes Admin-Kennwort | Nur der netzlose One-shot `admin-auth-init` liest das Klartextsecret. Die App erhält ausschließlich eine bcrypt-Datei mit Kostenfaktor 12 schreibgeschützt. | 11 Secret-Isolationsassertionen sowie Container-Inspect und HTTP 401/200 |
| NAS-/Registry-Betrieb | Pull-only Compose, digestgebundene Releaseidentität, private Konfigurations-/Secret-Snapshots, bestehende und getrennte Speicherquellen, engine-weite Wartungssperre sowie fail-closed Backup/Restore | Registry-Deployment-Vertrag 57 Assertions; Release-, Backup- und Restore-Operator-Tests; echter Named-Volume- und Bind-Mount-Lauf |
| Wiederherstellbarkeit | Logischer MariaDB-Dump und beide Dateibereiche werden im aktuellen Format 3 verifiziert und kontrolliert wiederhergestellt. Archivdaten werden über interaktive Standardeingabe in netzlose Hilfscontainer übertragen; Format 2 bleibt nur für den exakten Same-Host-Kompatibilitätsfall lesbar. | Format-3-Bind-Restore, anschließender vollständiger benannter Volume-Roundtrip, Marker-/SHA-256-, Login-, Export- und Schemanachweis |

## Abschließende automatische Läufe

Ausgeführt wurde:

```console
COMPOSE_PROJECT_NAME=estab_ci_etb_tbb_final9 \
ESTAB_CONTAINER_CLI=podman \
ESTAB_HTTP_PORT=18090 \
ESTAB_BROWSER_TEST=required \
bash tests/integration/ci.sh
```

Der Orchestrator leitete daraus für das Pull-only-Registry-Projekt Port
`18091` ab.

Der Lauf endete nach dem Pflicht-Browser-Gate und dem vollständigen
destruktiven Backup-/Restore-Roundtrip mit `CI integration: OK`.

Der Lauf umfasste unter anderem:

- frischen Image-Build und vollständige Migration auf MariaDB 11.8,
- 38 Schema-Prüfungen,
- Pull-only-Start mit benannten Volumes,
- Pull-only-Start mit drei echten Bind-Mounts,
- Format-3-Backup, kontrollierte Verfälschung und produktiven Restore,
- echten Chrome-Lauf mit zwölf Anmelde-/Navigationsschritten,
- HTTP-Fachläufe für Nachricht, Kategorie, ETB/TBB und Administration,
- HTTP-Präsenznachweis mit inertem Statuspoll, Aktivitäts-POST,
  15-Minuten-Inaktivzustand und serverseitigem 12-Stunden-Widerruf,
- authentifizierte Suche, kombinierte Filter, Leertreffer und Reset in der
  S2-Meldungsübersicht sowie der A/W- und Si-Zweitsichtung,
- 10.000 einsatzgebundene und 257 verwechslungsfähige Fremdeinsatzmeldungen
  mit exakten Treffern, Prepared Pagination und nachgewiesener Indexnutzung,
- Rollen-, Parallelitäts-, Anhang-, Einsatz-, PDF- und Exportnachweise,
- vollständige Löschung und Neuerstellung der CI-Volumes mit anschließendem
  Login-, Datei-, PDF-, Export- und Datenbanknachweis.

Dieser lokale Podman-Lauf wurde nicht auf einem nachgewiesenen
SELinux-Enforcing-System ausgeführt. Er ist deshalb kein Nachweis für das
tatsächliche Relabeling unter SELinux; die dafür im Testhandbuch beschriebene
Abnahme bleibt offen.

Die abschließende statische PHP-8.5-Suite lintete 246 aktive PHP-Dateien.
Alle Sicherheitsverträge, 57 Registry-Assertions, 72 Assertions zur
WAV-/Signalintegrität, die Operator-Shelltests, der Provenienznachweis und der
PDF-Smoke-Test mit 14.335 Byte waren grün. Zusätzlich bestanden beide
GitHub-Actions-Workflows die vollständige Prüfung mit dem festgelegten
Actionlint-1.7.12-Index-Digest. Shell-Syntax und `git diff --check` waren
ebenfalls fehlerfrei.

## Git- und Remote-Stand

Vor dem Abschlusscommit lag `main` elf lokale Commits vor
`origin/main` (`aa32d1f`). Der Abschlusscommit erhöht diesen Abstand auf zwölf.
Die fachlichen GitHub-Issues 1 bis 5 werden durch
`97cf705 feat(messages): complete priority and LdF issue workflows` mit
`Closes #1` bis `Closes #5` geschlossen, sobald dieser Commit tatsächlich auf
GitHub ankommt.

Lokal vorhanden, aber noch zu veröffentlichen, sind:

- `svn-r85`,
- die sechs historischen SVN-Tags `ver0.9.09`, `ver0.9.10`, `ver0.9.11`,
  `ver0.9.12`, `ver0.9.20` und `ver0.9.20b`,
- die beiden separat gebundenen SourceForge-Tags `ver0.9.26b` und
  `ver0.9.26c`.

Beim letzten Remote-Abruf waren keine dieser Tags auf `origin` vorhanden.
Die öffentlich sichtbaren GitHub-Actions gehören noch zum älteren
`origin/main` und waren rot; der hier dokumentierte aktuelle Stand ist lokal
grün. Ohne GitHub-/SSH-Anmeldung wurden weder Commits noch Tags gepusht und
keine Issues manuell geschlossen.

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

Bis diese Punkte erledigt sind, ist der lokale technische Stand belastbar
getestet, aber weder als öffentliches Containerrelease noch als abschließend
fachlich produktionsfreigegeben zu bezeichnen.
