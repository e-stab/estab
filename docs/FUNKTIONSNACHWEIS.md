# Funktionsmatrix und Freigabenachweis

Diese Matrix verbindet die historischen eStab-Funktionen mit einem
reproduzierbaren Nachweis. Sie verhindert, dass eine grüne Readiness-Antwort
mit einer vollständigen fachlichen Freigabe verwechselt wird.

## Automatisierte Nachweise

| Funktion | Automatisierter Nachweis | Freigabekriterium |
| --- | --- | --- |
| SVN-/Release-Herkunft | `migration/verify_svn_refs.py`, `migration/verify_release_snapshot.py`, dokumentierte SHA-256-Werte | alle Git-/SVN-Refs und Release-Dateien stimmen bytegenau überein |
| PHP-8.5-Laufzeit | `tests/static/run.sh` | alle aktiven PHP-Dateien linten; Kompatibilitäts- und Sicherheitsassertionen sind grün |
| MariaDB-Basisschema | `migrate`, `docker/db/verify.sql`, `/health.php` | Migrator Exit 0; sämtliche 17 Schemafelder sind `1`; Readiness HTTP 200 |
| Fresh-Baseline und Upgrade eines Legacy-Schemas | `tests/integration/schema_migrator.sh` | ein mit passendem Checksum protokollierter Abbruch nach der ersten Basistabelle wird idempotent bis zu allen 14 Tabellen fortgesetzt; ein unprotokollierter `nv_*`-Teilbestand bleibt unverändert blockiert; Duplikate blockieren das Legacy-Upgrade; Zweitlauf, Engine, Zeichensatz und Inhalte dynamischer Tabellen bleiben korrekt; Checksum-Manipulation blockiert |
| Datumsmigration | `tests/integration/date_compatibility.php` | Zero Dates werden `NULL`; gültige Werte und SQL-Mode bleiben erhalten |
| dynamische Benutzer-/Funktionstabellen | `tests/integration/dynamic_tables.php` | sechs Tabellen, Daten, Duplikate, Engine, Collation und Indizes bleiben korrekt |
| Anmeldung, Navigation, Sitzungsanzeige und Rollenbindung | `tests/php/auth_security.php`, `tests/php/navigation_security.php`, `tests/php/session_ui_security.php`, `tests/php/root_menu_security.php`, `tests/php/workflow_security.php`, `tests/browser/headless_ui.py`, `tests/integration/http_surface_http.sh`, `tests/integration/http_smoke.sh`, `tests/integration/categories_http.sh`, `tests/integration/logbooks_http.sh` | Getrennte, voranmelde-CSRF-gebundene Konto-Flows funktionieren; acht kanonische Bereiche, sichere URL-Auflösung, genau eine aktive Route, öffentliche und vollständige Navigation, der dauerhaft sichtbare Sidebar-Modus, der separate kompakte BOS-Disclosure-Modus sowie ausschließlich erlaubte symbolische Loginziele sind statisch belegt; anonyme Modulkarten bleiben sichtbar, führen mit Zielbeibehaltung zur Anmeldung und interne Karten öffnen im selben Tab; der Nachrichtenarbeitsbereich enthält ausschließlich die vollhohe `vorgaben`-Sidebar und den `mainframe`; der echte Browser klickt Neuanlage, dauerhaft sichtbare Sidebar-Links, Übersicht, BOS-Disclosure, BOS- und ETB-Karten sowie Logout und weist bei 1440 × 1000, 1280 × 720 und 700 × 760 CSS-Pixeln Statuskarte, Identität, rollenabhängige Textbuttons, mindestens 44 Pixel große Ziele, eine einzige dokumentweite Sidebar-Scrollfläche und keine verschachtelten Scroller nach; der Statusfragment-Refresh bewahrt Fokus und Scrollposition; bis einschließlich 672 CSS-Pixeln stehen Sidebar und Inhalt als volle Viewport-Zeilen untereinander; der authentifizierte Browserlauf bei 390 × 844 CSS-Pixeln weist den automatischen Wechsel einer Rollenaktion zum Inhalt und die Rückkehr über den mindestens 44 Pixel großen Button „Menü“ nach, die öffentliche Übersicht bleibt dort ebenfalls bedienbar; markierte geänderte Formulare warnen vor globaler Navigation und Logout; Bestandslogin erzeugt kein Konto, Neuanlage übernimmt weder vorhandenes Kürzel noch Passwort-Hash; Name, Kürzel, Funktion, Rolle und genau ein Abmeldeformular erscheinen escaped, öffentliche Seiten geben keine Identität aus; GET sowie fehlendes/falsches Logout-CSRF werden abgewiesen; der erfolgreiche Logout löscht die Sitzung und eine alte SID deaktiviert keine neuere Anmeldung desselben Kontos; aktive Konten können Funktion/Rolle nicht per Request wechseln, inaktive Konten erst nach Logout; der explizit freigeschaltete historische Zwei-Kennwort-Pfad bleibt für Same-Site-Clients kompatibel und weist Cross-Site ab |
| Rollenabhängige Warteschlangenhinweise | `tests/php/sidebar_ui_security.php`, `tests/php/session_ui_security.php`, `tests/browser/headless_ui.py` | Die vollständige Fernmelder-/Si-/Stab-/FB-Profilmatrix, vorbereitete Matrix-/Queue-Abfragen, serverseitige `partial`-/`unavailable`-Zustände, begrenzter Poll-Timeout und ausschließlich gebündelte gleich-originige PCM-WAV-Dateien sind statisch belegt; Datenbankfehler erreichen nicht mehr die beendenden Legacy-Includes/-Zähler; die erste erfolgreiche Messung initialisiert genau den passenden `old_que_*`-Basiswert ohne Hinweis, spätere erfolgreiche Messungen schreiben ihn fort und nur eine Erhöhung markiert genau einmal eine Wiedergabe, während Gleichstand, Rückgang oder fehlender Messwert keine falsche Auslösung erzeugen; ein positiver Zähler bleibt dauerhaft hervorgehoben; der echte authentifizierte Browser weist bei 390 × 844 CSS-Pixeln sichtbaren 503-/Erholungspfad, Opt-in, `NotAllowedError`, ehrlichen Reload-Zustand, `StorageEvent`-Synchronisation, Race-Abbruch, automatischen Auslösemarker, Fokus-/Live-Knoten-Erhalt und dasselbe langlebige Audioelement nach; physische Hörbarkeit bleibt manuelles Freigabekriterium |
| Stab-Nachricht | `tests/php/message_security.php`, `tests/integration/http_smoke.sh` | Formular öffnet, rohe UTF-8-Nachricht wird gespeichert und wieder gelesen; Quotes, `&`, `<script>` und SQL-ähnlicher Text bleiben Daten; GET-Detail, fremde Objekte und ungültige IDs werden abgewiesen |
| Nachrichtenstatus und Parallelität | `tests/integration/message_concurrency.php`, `tests/integration/categories_http.sh` | parallele Nummern kollidieren nicht; fremde Sperrinhaber verlieren; beim Save-/Reset-Rennen gewinnt genau eine Änderung; parallele Read-State-Writes bleiben logisch eindeutig; die sichtbaren Aktionen „gelesen“ und „erledigt“ setzen und entfernen den Zustand über echte CSRF-geschützte POSTs idempotent, eine fremde Nachricht liefert HTTP 403 und der Erledigt-Filter blendet die markierte Nachricht aus beziehungsweise kontrolliert wieder ein |
| Anhang | `tests/php/attachment_security.php`, `tests/php/file_access_security.php`, `tests/integration/attachment_reservation.php`, HTTP-Smoke | parallele Reservierungen kollidieren nicht; ein nach erfolgreichem `execute()` erst beim Puffern gemeldeter MariaDB-Timeout 1205 behält seinen Fehlercode; ein echter, am InnoDB-Zähler und am betroffenen Worker beobachteter Deadlock 1213 durchläuft den begrenzten Produktions-Rollback/-Retrypfad und beide Worker schließen erfolgreich ab; Besitz, Upload und authentifizierter Download sind korrekt; ein realer A/W-Upload-/Auswahl-Roundtrip erhält direkt im zurückgegebenen Formular alle markanten Eingaben, Anschrift, Vermerk sowie blaue und grüne Empfängerzuordnung |
| Nachweisung und Übungsleitung | `tests/integration/http_smoke.sh` | gespeicherte Nachricht erscheint in beiden authentifizierten Ansichten |
| Nachrichtenvordruck-PDF | `tests/php/pdf_smoke.php`, `tests/integration/http_smoke.sh` | die echte historische Vordruckklasse erzeugt ein vollständiges PDF; ein abgeschlossener authentifizierter Gesprächsnotiz-Workflow stößt den realen Generator an, listet und lädt den geschützten Vordruck mit korrektem MIME-/Disposition-Header, PDF-Header und -Trailer und bindet seinen SHA-256-Wert an den Restore-Nachweis |
| ETB/TBB | `tests/php/logbook_security.php`, `tests/integration/logbooks_http.sh`, Restore-Zweig in `tests/integration/http_smoke.sh` | jeder angemeldete Benutzer darf beide Bücher lesen; nur S2/Red-Copy schreibt ETB und nur A/W mit Rolle Fernmelder schreibt TBB; Cross-Rollen-Schreibversuche liefern HTTP 403; nach dem Restore werden die zuvor geschriebenen Titel und Einträge ausschließlich lesend wiedergefunden und nicht durch den Prüflauf neu angelegt |
| Kategorien | `tests/php/category_security.php`, `tests/integration/categories_http.sh` | anonyme Zugriffe, GET-Mutationen, fehlendes CSRF, fremde Tabellenräume und fremde Meldungs-IDs werden abgewiesen; S1 verwaltet nur eigene Funktions-/Benutzerkategorien, S2/Rotkopie und Si verwalten Master; CRUD und Zuordnung sind atomar; Quotes, `&` und `<script>` bleiben roh in MariaDB und werden in HTML neutralisiert |
| Empfängermatrix | `tests/php/admin_operations_security.php`, `tests/integration/admin_workflows_http.sh` | exakt 20 eindeutige Positionen, genau eine belegte Rotkopie und atomarer DB-Roundtrip; bestehende Benutzerzuordnungen bleiben unverändert |
| Nachrichtenzähler und Grafikreset | `tests/php/admin_operations_security.php`, `tests/integration/message_concurrency.php`, `tests/integration/admin_workflows_http.sh` | GET und fehlendes CSRF sind inert; Admin-Reparatur und regulärer Writer teilen einen Lock; zwei konkurrierende Erhöhungen erzeugen genau eine Systemnachricht; Grafikflags werden nur nach bestätigtem POST zurückgesetzt; Audit ist vollständig |
| Administration und Export | `tests/php/export_security.php`, `tests/integration/http_smoke.sh`, `tests/browser/headless_ui.py` | anonymer Zugriff wird abgewiesen; Basic Auth und CSRF schützen die passenden Aktionen; reguläre ZIPs werden neueste zuerst mit validiertem Manifest gelistet und unverändert heruntergeladen; Traversal/Symlinks bleiben außerhalb; eine zweistufig bestätigte Einzellöschung entfernt nur das gewählte Verzeichnis-/ZIP-Paar und lässt einen zweiten Export unverändert |
| Backup/Wiederherstellung | CI-Roundtrips und `docs/BACKUP-UND-WIEDERHERSTELLUNG.md` | der fachliche guardierte Lauf löscht Container und alle drei benannten CI-Volumes, stellt sie leer neu bereit und weist danach Schema, Anmeldung/Konto, Nachricht, exakten Anhanginhalt, bytegleichen generierten PDF-Vordruck, vorhandene ETB-/TBB-Titel und -Einträge sowie genau den zuvor validierten Exportlauf mit unverändertem ZIP-SHA-256 nach; ein zweiter Pull-only-Roundtrip leert ausschließlich drei projekt- und pfadgebundene temporäre Host-Mounts und stellt Datenbank- sowie zwei Dateimarker mit unverändertem Inhalt und SHA-256 wieder her |
| Pull-only OCI-/NAS-Deployment | `tests/php/registry_deployment_contract.php`, `tests/integration/registry_compose.sh`, `.github/workflows/publish-images.yml` | Registry-Compose enthält keinen Build und keinen Host-Schema-Mount, verlangt explizite unveränderliche Image-Referenzen und akzeptiert kein stilles `latest`; ein frisches Zweitprojekt startet ausschließlich die angeforderten Image-IDs und ein weiteres Projekt verwendet nachweislich echte Bind-Mounts für DB, `4fdata` und Export, initialisiert das eingebettete Basisschema, verlangt vor und nach Restore Migrator Exit 0 und eine gesunde App und beweist vor dem grünen Ergebnis die Entfernung beider Projekte samt Containern, Volumes, Netzwerken und temporärem Hostbaum; der manuelle, global serialisierte Publish-Pfad ist Git-Tag-, Rechte-, Variablen- und Required-Reviewer-gebunden, verweigert Überschreiben, baut beide Kandidaten vor dem ersten Push und prüft deren amd64/arm64-Manifeste, SBOM, Provenance und Attestation |
| Angriffsfläche des Containers | HTTP-Smoke, Admin-HTTP-Integration und Apache-Konfiguration | interne Konfiguration/Datendateien, der alte Alle-Nachrichten-View, interne Print-/FPDF-Bibliotheken und gefährliche Uploadhelfer liefern 403; nur die expliziten Direktupload-Tombstones liefern 410; Security Header sind vorhanden |

Die CI-Orchestrierung liegt in `tests/integration/ci.sh`. Sie verwendet
ephemere Secrets, eigene Volumes, harte Zeitgrenzen, Fehlerartefakte und
entfernt den Teststack am Ende. PHP-Warnungen, Notices, Deprecations, Fatals
und ungefangene Fehler im App-Log sperren die Freigabe. GitHub Actions setzt
den Browsermodus auf `required`; lokal läuft er standardmäßig automatisch,
wenn Python 3 und Chrome/Chromium verfügbar sind. Ein lokales `SKIP` ist kein
vollständiger Freigabenachweis. Browserfehler sichern Screenshot und
kennwortfreien Frame-/Session-Zustand im CI-Diagnoseartefakt.

## Verpflichtende fachliche Bedienabnahme

Die folgenden Interaktionen hängen von der organisationsspezifischen
Empfängermatrix, Rollenbesetzung und fachlichen Bedeutung der Daten ab. Sie
werden vor dem ersten Produktiveinsatz und nach fachlich relevanten Änderungen
mit den tatsächlich verwendeten Funktionen im Browser abgenommen:

- Eingangs- und Ausgangsnachricht über Fernmelder, Sichter und Stab vollständig
  transportieren, sichten, quittieren, weiterleiten und erledigen,
- rote/grüne Kopien sowie Priorität, Gesprächsnotiz und Sperrverhalten prüfen,
- globale, funktionsbezogene und persönliche Kategorie anlegen, zuweisen,
  suchen und wieder entfernen,
- Empfängermatrix und Rollenbezeichnungen gegen den örtlichen Führungsaufbau
  prüfen; Änderung, Rotkopie und Autosichtung kontrollieren und sicherstellen,
  dass bereits angemeldete Funktionen organisatorisch neu zugeordnet werden,
- Nachrichtenzähler nach einer simulierten Papierrückfallebene ausschließlich
  erhöhen und Systemnachricht sowie Audit-Eintrag prüfen; Grafikreset bestätigen
  und die erneute PDF-Erzeugung beobachten,
- Hinweistöne für Fernmelder, Si und Stab/FB in jedem vorgesehenen Browser
  ausdrücklich aktivieren, den Testton und nach der stillen ersten Messung
  jeweils genau einen Hinweis bei realer Warteschlangenerhöhung physisch hören
  sowie die sichtbare Rückmeldung bei ausgeschaltetem oder blockiertem Ton
  kontrollieren; automatisierte Wiedergabeaufrufe ersetzen diese Hörprobe
  nicht,
- ETB-/TBB-Einträge fachlich beurteilen und chronologische Darstellung prüfen,
- erzeugten Vordruck drucken und auf einer realen Rückfallebene lesen,
- vollständiges Backup auf einem getrennten Host wiederherstellen und dort
  Nachricht, Anhang, Vordruck, ETB/TBB sowie Export stichprobenartig öffnen.

Ein Screenshot allein ist kein Nachweis. Für jeden Lauf werden mindestens
Commit, Image-Digests, MariaDB-Version, Browser, Zeitpunkt, Prüfer, verwendete
Funktionen und Ergebnis festgehalten.

## Freigabeprotokoll

```text
Git-Commit:
App-Image-Digest:
Migrate-Image-Digest:
MariaDB-Image-Digest/Version:
Testzeitpunkt und Zeitzone:
Testumgebung/Compose-Projekt:
Automatisierte Suite: PASS/FAIL, Log-/Artefaktpfad:
Browser-Akzeptanz: PASS/FAIL, Browser-Version, Artefaktpfad:
Restore-Roundtrip: PASS/FAIL, Backup-SHA-256:
Abgenommene Rollen/Funktionen:
Fachliche Bedienabnahme: PASS/FAIL, Prüfer:
Bekannte Abweichungen mit Ticket:
Freigabeentscheidung:
```

Eine Freigabe ist nur zulässig, wenn alle automatisierten Gates erfolgreich
sind und jede im Einsatz verwendete fachliche Funktion abgenommen wurde.
