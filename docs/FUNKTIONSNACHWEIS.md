# Funktionsmatrix und Freigabenachweis

Diese Matrix verbindet die historischen eStab-Funktionen mit einem
reproduzierbaren Nachweis. Sie verhindert, dass eine grüne Readiness-Antwort
mit einer vollständigen fachlichen Freigabe verwechselt wird.

## Automatisierte Nachweise

| Funktion | Automatisierter Nachweis | Freigabekriterium |
| --- | --- | --- |
| SVN-/Release-Herkunft | `migration/verify_svn_refs.py`, `migration/verify_release_snapshot.py`, dokumentierte SHA-256-Werte | alle Git-/SVN-Refs und Release-Dateien stimmen bytegenau überein |
| PHP-8.5-Laufzeit | `tests/static/run.sh` | alle aktiven PHP-Dateien linten; Kompatibilitäts- und Sicherheitsassertionen sind grün |
| MariaDB-Basisschema | `migrate`, `docker/db/verify.sql`, `/health.php` | Migrator Exit 0; sämtliche 16 Schemafelder sind `1`; Readiness HTTP 200 |
| Upgrade eines Legacy-Schemas | `tests/integration/schema_migrator.sh` | Duplikate blockieren; Retry und Zweitlauf sind idempotent; Engine, Zeichensatz und Inhalte dynamischer Tabellen bleiben korrekt; Checksum-Manipulation blockiert |
| Datumsmigration | `tests/integration/date_compatibility.php` | Zero Dates werden `NULL`; gültige Werte und SQL-Mode bleiben erhalten |
| dynamische Benutzer-/Funktionstabellen | `tests/integration/dynamic_tables.php` | sechs Tabellen, Daten, Duplikate, Engine, Collation und Indizes bleiben korrekt |
| Anmeldung und Rollenbindung | `tests/php/auth_security.php`, `tests/integration/http_smoke.sh` | Registrierung, Passwort-Hash, zweite Sitzung, Session-Grenze und sechsstellige Kürzel funktionieren |
| Stab-Nachricht | `tests/php/message_security.php`, `tests/integration/http_smoke.sh` | Formular öffnet, rohe UTF-8-Nachricht wird gespeichert und wieder gelesen; Quotes, `&`, `<script>` und SQL-ähnlicher Text bleiben Daten; GET-Detail, fremde Objekte und ungültige IDs werden abgewiesen |
| Nachrichtenstatus und Parallelität | `tests/integration/message_concurrency.php` | parallele Nummern kollidieren nicht; fremde Sperrinhaber verlieren; beim Save-/Reset-Rennen gewinnt genau eine Änderung; parallele Read-State-Writes bleiben logisch eindeutig |
| Anhang | `tests/php/attachment_security.php`, `tests/php/file_access_security.php`, `tests/integration/attachment_reservation.php`, HTTP-Smoke | parallele Reservierungen kollidieren nicht; Besitz, Upload und authentifizierter Download sind korrekt |
| Nachweisung und Übungsleitung | `tests/integration/http_smoke.sh` | gespeicherte Nachricht erscheint in beiden authentifizierten Ansichten |
| Nachrichtenvordruck-PDF | `tests/php/pdf_smoke.php` | die echte historische Vordruckklasse erzeugt ein vollständiges PDF mit Header und Trailer |
| ETB/TBB | `tests/php/logbook_security.php`, `tests/integration/logbooks_http.sh` | jeder angemeldete Benutzer darf beide Bücher lesen; nur S2/Red-Copy schreibt ETB und nur A/W mit Rolle Fernmelder schreibt TBB; Cross-Rollen-Schreibversuche liefern HTTP 403 |
| Kategorien | `tests/php/category_security.php`, `tests/integration/categories_http.sh` | anonyme Zugriffe, GET-Mutationen, fehlendes CSRF, fremde Tabellenräume und fremde Meldungs-IDs werden abgewiesen; S1 verwaltet nur eigene Funktions-/Benutzerkategorien, S2/Rotkopie und Si verwalten Master; CRUD und Zuordnung sind atomar; Quotes, `&` und `<script>` bleiben roh in MariaDB und werden in HTML neutralisiert |
| Empfängermatrix | `tests/php/admin_operations_security.php`, `tests/integration/admin_workflows_http.sh` | exakt 20 eindeutige Positionen, genau eine belegte Rotkopie und atomarer DB-Roundtrip; bestehende Benutzerzuordnungen bleiben unverändert |
| Nachrichtenzähler und Grafikreset | `tests/php/admin_operations_security.php`, `tests/integration/message_concurrency.php`, `tests/integration/admin_workflows_http.sh` | GET und fehlendes CSRF sind inert; Admin-Reparatur und regulärer Writer teilen einen Lock; zwei konkurrierende Erhöhungen erzeugen genau eine Systemnachricht; Grafikflags werden nur nach bestätigtem POST zurückgesetzt; Audit ist vollständig |
| Administration und Export | `tests/php/export_security.php`, HTTP-Smoke | anonymer Zugriff wird abgewiesen; Basic Auth, CSRF und vollständiger Export funktionieren |
| Backup/Wiederherstellung | CI-Roundtrip und `docs/BACKUP-UND-WIEDERHERSTELLUNG.md` | Datenbank und Dateivolumes werden in einem leeren Stack wiederhergestellt und fachlich wiedergefunden |
| Angriffsfläche des Containers | HTTP-Smoke, Admin-HTTP-Integration und Apache-Konfiguration | interne Konfiguration/Datendateien, der alte Alle-Nachrichten-View, interne Print-/FPDF-Bibliotheken und gefährliche Uploadhelfer liefern 403; nur die expliziten Direktupload-Tombstones liefern 410; Security Header sind vorhanden |

Die CI-Orchestrierung liegt in `tests/integration/ci.sh`. Sie verwendet
ephemere Secrets, eigene Volumes, harte Zeitgrenzen, Fehlerartefakte und
entfernt den Teststack am Ende. PHP-Warnungen, Notices, Deprecations, Fatals
und ungefangene Fehler im App-Log sperren die Freigabe.

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
Restore-Roundtrip: PASS/FAIL, Backup-SHA-256:
Abgenommene Rollen/Funktionen:
Fachliche Bedienabnahme: PASS/FAIL, Prüfer:
Bekannte Abweichungen mit Ticket:
Freigabeentscheidung:
```

Eine Freigabe ist nur zulässig, wenn alle automatisierten Gates erfolgreich
sind und jede im Einsatz verwendete fachliche Funktion abgenommen wurde.
