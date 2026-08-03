# Funktionsmatrix und Freigabenachweis

Diese Matrix verbindet die historischen eStab-Funktionen mit einem
reproduzierbaren Nachweis. Sie verhindert, dass eine grüne Readiness-Antwort
mit einer vollständigen fachlichen Freigabe verwechselt wird.

## Automatisierte Nachweise

| Funktion | Automatisierter Nachweis | Freigabekriterium |
| --- | --- | --- |
| Dauerhafte Modusbindung | `tests/php/permission_mode_security.php`, `tests/php/incident_ui_security.php`, `tests/integration/incident_domain.php` | Eine echte Modusänderung ist nur in einem offenen Einsatz ohne jede operative oder formale Eintragung zulässig. Die erste solche Eintragung sperrt weitere Änderungen dauerhaft, auch wenn einzelne Daten später gelöscht würden. Das erneute Speichern desselben Werts bleibt ohne Revision und Audit idempotent. Die Abnahme verwendet einen leeren Umschalt-/Sperr-Fixture sowie getrennte, danach unveränderliche `STRICT`- und `LOOSE`-Einsätze. |
| Einsatzbezogener Berechtigungsmodus | `tests/php/permission_mode_security.php`, `tests/php/permission_mode_attachment_scope_security.php`, `tests/php/incident_ui_security.php`, `tests/integration/schema_migrator.sh`, `tests/integration/incident_domain.php`, `tests/integration/dv_operations.php`, `tests/integration/message_concurrency.php`, `tests/integration/message_workflow_http.sh` | Bestand und Neuanlage bleiben standardmäßig `STRICT`; Modus und Warnung sind im Einsatzstatus sichtbar. Anlegen, Aktivieren oder Umstellen auf `LOOSE` verlangt die ausdrückliche Bestätigung, erwarteten Altmodus und unveränderte globale Revision; jeder echte Wechsel wird Vorher/Nachher auditiert. `STRICT` verlangt für operative Rechte eine persönlich angenommene und ausgewählte Besetzung der aktiven formalen Dienstschicht. `LOOSE` benötigt keine formale Dienstschicht, erzwingt aber weiterhin die passende feste Kontofunktion oder eine explizit in der Benutzerverwaltung vergebene globale Zusatzfunktion. Ein fachfremdes Konto bleibt gesperrt; Menü, Lesen und Schreiben werden niemals pauschal freigegeben. Authentisierung, aktiver offener Einsatz/Scoping, CSRF/Validierung, Richtung, Workflow- und Objektzustände, Sperrinhaber, Integrität, Ereignisketten, Append-only und Retention bleiben verbindlich. Zusatzfunktionen sind in `STRICT` wirkungslos, Zugangsschichten werden nur in `LOOSE` ausgewertet und Administrationsrechte nie erweitert. Unmarkierte Legacy-DML, kombinierte Einsatzänderungen und ein Modus-/Revisionswechsel während eines Writers scheitern für den Anwendungsweg fail-closed. Die connection-lokalen Marker sichern dabei Kohärenz, sind aber keine Privileggrenze: Ein SQL-Principal mit beliebigen Schreibrechten ist eine ausdrücklich vertrauenswürdige Betreibergrenze. |
| GitHub-Actions-Workflows | offizielles Actionlint 1.7.12 aus dem festgelegten Multi-Arch-Index-Digest, `.github/workflows/{ci,publish-images}.yml`, `tests/php/registry_deployment_contract.php` | beide Workflows bestehen YAML-, Schema-, Ausdrucks-, Matrix-, Abhängigkeits-, Aktionsparameter-, Shellcheck- und Pyflakes-Prüfung; das Repository erzwingt den schreibgeschützten Checkout-Mount und denselben Digest im CI-Vertrag, damit insbesondere ungültige Ausdruckskontexte wie ein `runner.temp` außerhalb eines Steps nicht erneut unbemerkt bleiben |
| Gepflegter Containerbestand | `Dockerfile`, `docker/app/verify-runtime-surface.sh`, `tests/static/runtime_image_surface.sh` | Der Container entsteht aus einer positiven Kopierliste. Das vollständige dynamisch verwendete HS-Design, aus `design/mr` nur `folder_global.gif`, genau zwölf `4fsym`-Assets sowie die aktive PDF-/Schrift-Teilmenge sind freigegeben; alte Controller, Installer, Passwortdateien, Archive, Dokumentquellen und nicht freigegebene Schriften bleiben draußen. Pflichtpfade und negative Fälle brechen bei Abweichung geschlossen ab. |
| PHP-8.5-Laufzeit | `tests/static/run.sh` | Der aktive PHP-Bestand einschließlich `app/permission_mode.php` lintet; Kompatibilitäts- und Sicherheitsassertionen sind grün |
| MariaDB-Basisschema und konsistente Readiness | `migrate`, `docker/db/verify.sql`, `/health.php`, `/4fadm/system_status.php`, `tests/integration/http_smoke.sh`, `tests/integration/assignment_policy.php` | Migrator Exit 0 und sämtliche Ergebnisfelder der read-only Schema-Verifikation sind `1`; Health und Adminstatus verwenden denselben Prüfdienst; neben dem Legacy-Schema werden Nachrichtennachweis, Such-/Listenindizes, UTC-Präsenz, exakt zwei ETB-/TBB-Buchköpfe je Einsatz samt Einsatz-Insert-Trigger, lokale Nummern, strukturierte TBB-Felder/Bezüge, Append-only-Trigger, zehnjährige Aufbewahrung, Dienstbetrieb, S6-Planung, Melderlauf, Anhang-Eingangsintegrität, die kanonische Kennwort- und Selbstregistrierungsrichtlinie, Berechtigungsmodusspalte und modebewusste Guard-/Fachtrigger sowie beide Hashketten geprüft; eine kontrolliert fehlende Standardmatrixtabelle, eine ungültige Richtlinienzeile oder ein aktiv markiertes Konto mit ungültigem Funktions-/Rollenpaar ergibt HTTP 503 beziehungsweise „Prüfung erforderlich“, während ein inaktives Waisenkonto zur administrativen Reparatur betriebsbereit bleibt |
| Fresh-Baseline und Upgrade eines Legacy-Schemas | `tests/php/schema_migration_contract.php`, `tests/integration/schema_migrator.sh` | Ein checksumgebundener Abbruch wird idempotent bis zur aktuellen Migrationsfolge fortgesetzt; unprotokollierte Teilbestände, Duplikate, fremde Objektkollisionen und Checksum-Manipulation blockieren ohne stilles Umschreiben. Migrationen 110 bis 118 bleiben als historische Entwicklung checksumstabil. Migration 118 ergänzt die kollisionsgeprüfte Tabelle `nv_benutzer_zusatzfunktionen` und stellt den modeabhängigen Autorisierungsvertrag her. Migration 119 ersetzt gezielt nur die Melderauftrag-Triggergrenze: Die fachliche Eignung des Ziel-Fernmelders bleibt modusabhängig Pflicht, seine laufende Sitzung ist bei der Beauftragung dagegen nicht erforderlich. Veröffentlichte ältere Migrationen und Fachdaten werden nicht umgeschrieben. |
| Fernmeldeplan-Entwurfsmigration | `docker/db/migrations/117-telecom-draft-discard.sql`, `tests/php/schema_migration_contract.php`, `tests/integration/schema_migrator.sh` | Migration 117 erlaubt ausschließlich das unveränderte Archivieren eines Planentwurfs als `ERSETZT`; Freigabedaten bleiben leer und die bestehenden Eintragstrigger schützen Kopf und Wege danach vor Änderung oder Löschung. Der ersetzte Trigger vergleicht nullable Freigabefelder NULL-sicher und Textbemerkungen binär, sodass auch reine Groß-/Kleinschreibungsänderungen abgewiesen werden. Eine direkte Freigabe außerhalb des sekundengenauen gespeicherten Gültigkeitsfensters scheitert; die Endsekunde bleibt eingeschlossen. |
| Fernmeldeplan-Entwurfslebenszyklus | `tests/php/telecom_plan_security.php`, `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh`, `tests/browser/headless_ui.py --telecom-plan` | Chrome 150 vergleicht nach dem Bearbeitungsstart alle sichtbaren Kopfwerte und sämtliche Wege mit der aktiven Quelle, öffnet und ändert einen übernommenen Weg, ergänzt und löscht einen weiteren Weg und liest Kopf- sowie Wegehinweise in der unveränderlichen Versionshistorie. Die Auswahl schreibt `Fernsprecher`, `Funk`, `Melder`, `Telefax`, `Fernschreiber` und `Datenübertragung` aus; bei Funk erscheinen Kanal/Gruppe und Bandlage, bei anderen Medien werden unpassende Felder ausgeblendet und serverseitig geleert. Ungespeicherte Teilformulare stoppen eine Aktivierung oder andere verlustbehaftete Aktion sichtbar; zwei gleichzeitig geänderte Bereiche besitzen einen ausdrücklich als Verwerfen der anderen Browserwerte gekennzeichneten Auflösungsweg. Nach der Veröffentlichung bleibt die neue Fassung aktiv; die Bedienung ist zusätzlich bei `390 × 844` CSS-Pixeln überlappungsfrei geprüft. Eine Aktivierung ist nur im aktuellen Gültigkeitsfenster möglich; bei einem zukünftigen oder abgelaufenen Entwurf bleibt die bisherige Version aktiv. Zwei echte, zunächst gemeinsam am Einsatz-Lock wartende Datenbankverbindungen belegen, dass genau ein Bearbeitungsstart einen Entwurf erzeugt und der zweite danach fachlich geschlossen scheitert. Der MariaDB-Test kopiert zwei verschiedenartige Wege mit allen Kopf-, Technik-, Vermerk- und Bemerkungsfeldern in neue, quellenfremde IDs. Anlage und Kopfdatenänderung schreiben zweckgebundene Initial- beziehungsweise Vorher-/Nachher-Snapshots ohne Zugangsdaten in die Hashkette. Ein verworfener Entwurf bleibt samt Wegen unveränderlich nachgewiesen und gibt die nächste Bearbeitung des aktiven Plans frei. Sichere Legacy-Entwürfe werden nur akzeptiert, wenn ihre Ereignisreihenfolge den noch aktiven Quellplan eindeutig belegt. Große zulässige Auditdetails bleiben vollständig in der Hashkette; das größenbegrenzte Legacy-Protokoll erhält einen prüfbaren kompakten Verweis. Im Modus `LOOSE` wird die tatsächlich wirksame feste oder zusätzliche Kontofunktion protokolliert. Der vollständige DV-Operations-Lauf bestätigt diesen Verbund mit 243 Assertions und 95 Ereignissen. |
| Datumsmigration | `tests/integration/date_compatibility.php` | Zero Dates werden `NULL`; gültige Werte und SQL-Mode bleiben erhalten |
| dynamische Benutzer-/Funktionstabellen | `tests/integration/dynamic_tables.php`, `tests/integration/dv_operations.php` | der gemeinsame, advisory-lock-geschützte Schema-Reconciler hält die sechs Legacy-Tabellen samt Daten, Duplikaten, Engine, Collation und Indizes korrekt; die feste Stabs-/FB-Kontofunktion bestimmt den benötigten Tabellenraum, während die reine ETB-Funktion absichtlich keine Nachrichten-/Kategorietabellen erhält |
| Globaler Einsatz und Eingabesperre | `tests/php/incident_domain_security.php`, `tests/php/incident_ui_security.php`, `tests/php/operational_incident_scope.php`, `tests/integration/incident_domain.php`, `tests/integration/incident_ci_bootstrap.php` | genau ein revisionsgesicherter aktiver Einsatz; ohne aktiven Einsatz oder bei historisch fehlendem Führungsstellennamen blockieren operative Eingaben, während Anmeldung und Administration möglich bleiben; neue Einsätze verlangen einen getrennten Führungsstellennamen, die einmalige Bestätigung eines migrierten `NULL`-Werts bleibt trotz Altdaten möglich und die erste Fachänderung setzt atomar einen dauerhaften Sperrmarker; direkter Legacy-SQL, Direktänderungen sowie Insert→Delete→Umbenennen bleiben fail-closed; Aktivierung kann keinen laufenden Writer überholen; Insert, Update, Delete, Listen, Zähler, Nebenstatus, ETB/TBB und Audit bleiben in ihrem Einsatz; Bestandsdaten landen ausschließlich in `LEGACY-IMPORT` |
| Benutzerverwaltung | `tests/php/user_admin_security.php`, `tests/integration/user_admin.php` | Kontoanlage speichert nur einen Hash und eine serverseitig abgeleitete feste Funktion/Rolle; Zusatzfunktionen werden als eindeutige, serverseitig abgeleitete Funktions-/Rollenpaare vergeben und entzogen, gelten global nur in `LOOSE` und widerrufen bei Änderung die Sitzung. Leere Legacy-Zuordnungen bleiben reparierbar; Neuzuweisung, Sperren, Entsperren und Kennwortreset teilen den Login-Lock, rollen bei Auditfehler vollständig zurück und protokollieren niemals Kennwort, Hash oder SID. |
| Konfigurierbare Kennwortrichtlinie | `tests/php/password_policy_security.php`, `tests/integration/password_policy.php`, `tests/integration/admin_workflows_http.sh`, `tests/integration/http_smoke.sh`, `tests/browser/headless_ui.py` | 63 statische Richtlinien-, 111 Authentisierungs- und 66 MariaDB-Assertions belegen: `/4fadm/password_policy.php` verlangt Basic Auth, Session-CSRF, Vorher-/Nachher-Vorschau, ausdrückliche Bestätigung und unveränderte Revision. Die konfigurierbare Mindestlänge liegt bei 8–128 Unicode-Codepoints, Standard 12, und unabhängige Unicode-Pflichten für Groß-/Titlecase-/Kleinbuchstaben, Ziffern und Sonderzeichen sind möglich. Unicode-Steuerzeichen sind verboten, Formatzeichen einschließlich ZWJ erlaubt. Der Server akzeptiert höchstens 1024 UTF-8-Bytes; das Browserfeld erlaubt 1024 Eingabeeinheiten und zählt die Mindestlänge exakt in Codepoints, während der Server verbindlich bleibt. Kontoanlage, Reset und aktivierte Selbstregistrierung prüfen denselben unter Lock gelesenen Stand und speichern Argon2id; Fehler ändern weder Konto noch Sitzung oder Audit. Ein Test mit gleichem 72-Byte-Präfix und unterschiedlichen Suffixen belegt, dass Argon2id den vollständigen Wert unterscheidet. Bestandslogin und Sitzungen bleiben unberührt. Klartext und eindeutig verifizierbare Alt-Hashes werden nach erfolgreicher Anmeldung migriert. bcrypt wird nur bei einem eingegebenen Kennwort unter 72 UTF-8-Bytes automatisch migriert; ab 72 Bytes bleibt der ambivalente Alt-Hash bis zum administrativen Reset unverändert. Stärkere oder gemischte Argon2id-Kosten werden nicht zurückgestuft, nur vollständig schwächere Profile hochgestuft. Das getrennte Basic-Auth-Secret bleibt unabhängig. Änderung und kennwortfreies Vorher-/Nachher-Audit committen gemeinsam; Konkurrenz, fremdes Schema und Auditfehler schlagen geschlossen fehl. |
| Zeitgesteuerte Selbstregistrierung | `tests/php/self_registration_security.php`, `tests/integration/self_registration.php`, `tests/integration/self_registration_handler.php`, `tests/integration/self_registration_http.sh`, `tests/browser/headless_ui.py` | 107 statische, 31 MariaDB- und 28 echte Handler-Assertions belegen: Die Basic-Auth-/CSRF-geschützte Administration schaltet die öffentliche Kontoanlage aus, dauerhaft oder für 15 Minuten bis 24 Stunden ein. Revision, globaler Advisory-Lock und transaktionales Vorher-/Nachher-Audit verhindern verlorene oder halbe Änderungen. Befristungen verwenden ausschließlich Datenbank-UTC, gelten am exakten Endzeitpunkt bereits als abgelaufen und werden im atomischen Konto-INSERT erneut geprüft. Der reale Konto-Handler weist ein während der Verarbeitung ablaufendes oder parallel deaktiviertes Fenster ohne Konto-, Hash-, Sitzungs-, Audit- oder DDL-Artefakte ab. Ein vorher geöffnetes Formular kann Ablauf oder Deaktivierung nicht überholen; Bestandslogin, Konten und laufende Sitzungen bleiben unverändert. Der echte Browser prüft elf Admin-Karten, acht feste Zeitfenster und die responsive Richtlinienseite ohne Zustandsänderung. |
| Anmeldung, Navigation, Sitzungsanzeige und Rollenbindung | `tests/php/auth_security.php`, `tests/php/navigation_security.php`, `tests/php/read_authorization_security.php`, `tests/php/session_ui_security.php`, `tests/php/root_menu_security.php`, `tests/php/workflow_security.php`, `tests/browser/headless_ui.py`, `tests/integration/user_admin.php`, `tests/integration/http_surface_http.sh`, `tests/integration/http_smoke.sh`, `tests/integration/legacy_login_http.sh`, `tests/integration/categories_http.sh`, `tests/integration/logbooks_http.sh` | Der voranmelde-CSRF-gebundene Bestandskonto-Flow und die standardmäßig deaktivierte öffentliche Registrierung funktionieren. Administrativ provisionierte Konten können Funktion/Rolle nicht per Request wechseln. In `STRICT` verwenden Navigation, Lesen und Schreiben die persönlich angenommene und ausgewählte Besetzung der aktiven Dienstschicht. In `LOOSE` entfällt ausschließlich diese formale Dienstbesetzungswahl; wirksam sind weiterhin nur die feste Kontofunktion und ausdrücklich vergebene Zusatzfunktionen. Bei mehreren wirksamen gewöhnlichen Stabs-/FB-Funktionen bindet jede getrennte Aktion `Schreiben als …` beziehungsweise `Lesen als …` einen expliziten `acting_function`-Kontext. Im `LOOSE`-Modus löst der Server ihn ausschließlich gegen die aktuell wirksame feste oder zusätzliche Funktion auf, reicht ihn über Formular-, Listen-, Status- und Detailpfade weiter und weist manipulierte, mehrdeutige oder inzwischen entzogene Kontexte fail-closed ab. Anonyme direkte Fachseiten und abgelaufene Sitzungen antworten navigierbar mit HTTP 303 zum Bestandslogin. Authentifizierte, aber fachlich unzulässige Browserseiten behalten HTTP 403 und zeigen eine responsive, datenfreie Fehlerseite mit Identität, Logout, erlaubtem Menü und sicherer Übersichtsrückkehr statt Rohtext. Gemeinsame Navigation, Zielbeibehaltung, Sessionanzeige, Logout-/SID-Widerruf und responsive Bedienziele sind automatisiert belegt. Unzulässige Spezialziele fehlen für die jeweils wirksame Funktionsmenge. |
| Aktuelles Web-Handbuch | `tests/php/handbook_ui_security.php`, `tests/php/navigation_security.php`, `tests/php/root_menu_security.php`, `tests/php/http_surface_security.php`, `tests/static/runtime_image_surface.sh`, `tests/integration/http_surface_http.sh`, `tests/integration/http_smoke.sh`, `tests/browser/headless_ui.py --handbook-only` | `/handbuch/` ist ohne Funktionsanmeldung und zugleich sitzungsbewusst erreichbar, akzeptiert nur GET/HEAD und verwendet ausschließlich zentral gebaute interne Links. Exakt 19 Kapitel dokumentieren den aktuellen Einsatz-, Rollen-, Nachrichten-, ETB/TBB-, S6/Melder-, Export-, Administrations- und Containerstand; alte XAMPP-/Datenbank-pro-Einsatz-Anweisungen bleiben ausgeschlossen. Die lokale diakritiktolerante Mehrwortsuche verändert keine Serverdaten, kündigt Treffer zugänglich an und wird zusammen mit aktivem Navigationspunkt, 19 eindeutigen Ankern sowie überlauffreiem Desktop-/Mobil- und Drucklayout statisch, über HTTP und in echtem Chrome geprüft. Frühere Handbuchstände bleiben über Git-Historie und Tags nachvollziehbar; überholte Kopien gehören weder zum aktuellen Quellbestand noch zum Laufzeitimage. |
| Sitzungspräsenz und Leerlaufende | `tests/php/auth_security.php`, `tests/php/session_ui_security.php`, `tests/php/sidebar_ui_security.php`, `tests/integration/http_surface_http.sh`, `tests/integration/http_smoke.sh` | Die zentrale Grenze unterscheidet exakt: unter 15 Minuten aktiv, ab 15 Minuten inaktiv bei weiterhin gültiger Sitzung und ab 12 Stunden serverseitig abgelaufen. Der Session-CSRF- und SID-gebundene Aktivitäts-POST akzeptiert ausschließlich eine gültige Fachsitzung und wird nur durch echte Browserinteraktion ausgelöst; Statuspolls, automatische Refreshes und Seitenladen verändern den UTC-Zeitstempel nicht. GET, anonymer POST und falsches CSRF werden mit 405, 401 beziehungsweise 403 abgewiesen. Fehlende, ungültige und zukünftige Zeitwerte laufen fail-closed ab; PHP-GC ist auf 43.200 Sekunden angeglichen. Der separate HTTP-Basic-Administrationszugang bleibt unabhängig. |
| Reverse-Proxy-Vertrauen | `tests/php/runtime_compatibility.php`, `tests/php/auth_security.php`, `tests/php/registry_deployment_contract.php` | Forwarded-Header sind standardmäßig wirkungslos; aktiviertes Vertrauen verlangt eine nichtleere, in beiden Compose-Pfaden ausgelieferte IPv4-/IPv6-IP-/CIDR-Allowlist, lehnt Hostnamen, ungültige Präfixe und Catch-all-Netze ab und gilt pro Request nur bei passender direkter `REMOTE_ADDR`; erst innerhalb dieser Grenze werden vollständig valide Proto-/Adressketten für Secure-Cookie und Audit ausgewertet |
| Rollenabhängige Warteschlangenhinweise | `tests/php/sidebar_ui_security.php`, `tests/php/session_ui_security.php`, `tests/php/audio_asset_security.php`, `tests/browser/headless_ui.py` | Die vollständige Profilmatrix für Fernmelder, Si, Stab und FB, vorbereitete Matrix-/Queue-Abfragen, serverseitige `partial`-/`unavailable`-Zustände, begrenzter Poll-Timeout und ausschließlich gebündelte gleich-originige PCM-WAV-Dateien sind statisch belegt; die drei fest gebundenen Töne werden zusätzlich vollständig als RIFF/WAVE PCM-16 geparst und gegen SHA-256, Kanäle, Abtastrate, Framezahl, Dauer, Signalspitze und Mindest-RMS geprüft, sodass beschädigte, ausgetauschte oder stumme Assets die Freigabe sperren; Datenbankfehler erreichen nicht mehr die beendenden Legacy-Includes/-Zähler; die erste erfolgreiche Messung initialisiert genau den passenden `old_que_*`-Basiswert ohne Hinweis, spätere erfolgreiche Messungen schreiben ihn fort und nur eine Erhöhung markiert genau einmal eine Wiedergabe, während Gleichstand, Rückgang oder fehlender Messwert keine falsche Auslösung erzeugen; ein positiver Zähler bleibt dauerhaft hervorgehoben; der echte authentifizierte Browser weist bei 390 × 844 CSS-Pixeln sichtbaren 503-/Erholungspfad, Opt-in, `NotAllowedError`, ehrlichen Reload-Zustand, `StorageEvent`-Synchronisation, Race-Abbruch, automatischen Auslösemarker, Fokus-/Live-Knoten-Erhalt und dasselbe langlebige Audioelement nach; physische Hörbarkeit bleibt manuelles Freigabekriterium |
| Stab-Nachricht und Vorrang | `tests/php/message_security.php`, `tests/php/message_priority_security.php`, `tests/php/official_message_form_ui_security.php`, `tests/php/read_authorization_security.php`, `tests/integration/http_smoke.sh`, `tests/browser/headless_ui.py` | Formular öffnet mit aktivem Einsatz und fachlich berechtigter wirksamer Funktion; `STRICT` verlangt die ausgewählte Besetzung einer aktiven Dienstschicht, `LOOSE` feste oder zusätzliche Kontofunktion; rohe UTF-8-Nachricht wird gespeichert und wieder gelesen; Quotes, `&`, `<script>` und SQL-ähnlicher Text bleiben Daten; einsatzfremde sowie nach Richtung, Status oder Sperrinhaber unzulässige Objekte werden immer und unpassende wirksame Funktionen in beiden Modi abgewiesen; Objekt- und Workflow-Sicht folgen stets der tatsächlich autorisierten Funktion. Vorrang wird zentral als keine, Sofort, Blitz oder Staatsnot dargestellt und streng validiert. Sofort, Blitz und Staatsnot liegen gemeinsam im einzigen Vorrangfeld des Nachrichtenvordrucks; es gibt weder eine zweite Vorrangauswahl noch ein paralleles sichtbares Erreichbarkeitsfeld außerhalb des amtlichen Blatts. |
| Rollenübergreifender Nachrichtenlauf | `tests/php/config_security.php`, `tests/php/official_message_form_ui_security.php`, `tests/php/message_form_fields_security.php`, `tests/php/ldf_validation_security.php`, `tests/php/ldf_ui_flow_security.php`, `tests/php/ldf_return_security.php`, `tests/php/read_authorization_security.php`, `tests/php/message_suggestion_security.php`, `tests/integration/message_suggestions.php`, `tests/integration/message_workflow_http.sh`, `tests/browser/headless_ui.py`, orchestriert durch `tests/integration/ci.sh` | Getrennte Funktionskonten für LdF, Fernmelder, Si, Stab und FB durchlaufen den verbindlichen Ablauf: Eingang `Fernmelder → LdF → Si → abgeschlossen`, Ausgang `Verfasser → Si → LdF → Fernmelder → abgeschlossen`. Si und LdF können einen Ausgang nur mit Pflichtgrund an den Verfasser zurückgeben; danach folgen erneut Si und LdF. Ein nicht nutzbarer Transportweg führt vom Fernmelder begründet zu LdF zurück. Bedieneridentität, Funktion und Rolle stammen in `STRICT` aus der ausgewählten Besetzung der aktiven Dienstschicht und in `LOOSE` aus der festen oder einer expliziten Zusatzfunktion; ein Browser kann sie nicht durch Formularwerte ersetzen. Rufnamen-/Absendervorschläge, Statusfolge, Sperrbesitz und Einsatzgrenze werden serverseitig geprüft. Ein aktiver Einsatz ist immer zwingend; nur in `LOOSE` entfällt die formale Dienstschichtpflicht. |
| Nachrichtenstatus, Stationsleiste, Ereigniskette und Parallelität | `tests/php/message_timeline_security.php`, `tests/php/message_timeline_integration_security.php`, `tests/php/dv_evidence_security.php`, `tests/integration/message_concurrency.php`, `tests/integration/categories_http.sh` | parallele Nummern kollidieren nicht; fremde Sperrinhaber verlieren; beim Save-/Reset-Rennen gewinnt genau eine Änderung. Jede kanonische Nachrichtenerzeugung und jeder Zuständigkeitswechsel schreibt im selben Commit ein strukturiertes Ereignis. MariaDB verhindert Änderung und Löschung, prüft Snapshot- und Verkettungshash und hält einen serialisierten Kopf je Nachricht; der Verifikator rekonstruiert die vollständige Kette. Oberhalb jeder Bearbeitungs- und Detailansicht zeigt die gemeinsame Leiste aktuellen, erledigten und geplanten Weg. Wiederholte Si-, LdF- und Fernmelderstationen samt Rückgabegrund bleiben sichtbar. Laufzeiten stammen ausschließlich aus `recorded_at`, Entwürfe und Legacy-Bestand erfinden keine Zeiten oder Übergänge. Read-/Done-Zustände bleiben getrennt idempotent. |
| Optionale Zugangsschichten | `tests/php/dv_operations_security.php`, `tests/integration/shift_access.php`, `tests/integration/http_smoke.sh` | Zugangsschichten werden ausschließlich in `LOOSE` einsatzgebunden angelegt und enthalten Kontenzuordnungen, aber keine Funktions-Hüte. Unzugeordnete Konten bleiben erlaubt; bei Mehrfachzuordnung genügt eine aktive Gruppe. Aktivierung erzeugt keine Sitzung. Deaktivierung widerruft betroffene Sitzungen, wenn keine andere aktive Gruppe verbleibt. Funktion und Rolle stammen aus fester Kontofunktion und expliziten Zusatzfunktionen und bleiben durch Zugangsschichten unverändert; eine manuelle Kontosperre ist unabhängig und vorrangig. In `STRICT` werden stattdessen formale Dienstschichten und ausgewählte persönliche Besetzungen erzwungen. Ein unter globaler Richtlinien- und Kontosperre neu berechneter Bestätigungs-Hash weist zwischenzeitlich geänderte Wirkungen ab; die konkrete Mitgliedsintervall-ID verhindert Entfernen/Neuanlegen-ABA. Formale Dienstschichten und Besetzungen bleiben aktuelle `STRICT`-Autorisierungs- und exportierbare Evidenz; offene formale Dienstorganisation beziehungsweise fehlende Bucheröffnung blockiert dort den Abschluss. |
| S6-Fernmeldeplan und Melderlauf | `tests/php/dv_operations_security.php`, `tests/php/telecom_plan_security.php`, `tests/php/schema_migration_contract.php`, `tests/integration/schema_migrator.sh`, `tests/integration/dv_operations.php`, `tests/integration/message_workflow_http.sh`, `tests/browser/headless_ui.py --inactive-messenger` | In `STRICT` erstellt und veröffentlicht nur eine ausgewählte S6-Besetzung der aktiven Dienstschicht Planversionen; in `LOOSE` ist `S6` als feste oder zusätzliche Kontofunktion erforderlich. Eine Bearbeitung kopiert den vollständigen aktiven Plan in genau einen Entwurf mit neuen Wege-IDs. Der Datenbanknachweis vergleicht dabei zwei unterschiedlich parametrierte Wege einschließlich optionaler Vermerke und Bemerkungen sowie sämtliche kopierten Kopffelder wertgleich mit der Quelle. Kopf und Wege sind dort einzeln bearbeitbar; Zustandswert und Quellversionsprüfung verhindern stille oder veraltete Freigaben. Medienfelder werden nach ausgeschriebenem Medium auch serverseitig normalisiert. Planidentität und veröffentlichte Fassungen bleiben unveränderbar. Für einen Melderauftrag darf LdF ein ungesperrtes und fachlich geeignetes Fernmelderkonto auch bei inaktiver Präsenz oder ohne laufende Sitzung auswählen. `STRICT` verlangt für das Ziel weiterhin eine persönlich angenommene Fernmelder-Besetzung der aktiven Dienstschicht; `LOOSE` weiterhin feste beziehungsweise zusätzliche Fernmelderfunktion und ein gegebenenfalls wirksames Zugangsschicht-Gate. Oberfläche und HTTP-Antwort kennzeichnen den Status und fordern bei fehlender Aktivität zur separaten Information auf; der dedizierte Chrome-Lauf bestätigte genau diesen Bedienpfad. Ein nach dem jeweiligen Modus wirksam als Fernmelder autorisiertes Konto bestätigt später selbst und authentisiert Übernahme, Übergabe mit Empfänger, Rückweg und Rückkehr; danach bestätigt in `STRICT` eine ausgewählte LdF-Besetzung und in `LOOSE` ein Konto mit fester oder zusätzlicher Funktion LdF die Rückmeldung an die FmZt. Jeder Übergang wird hashverkettet nachgewiesen. |
| Anhang | `tests/php/upload_security.php`, `tests/php/attachment_security.php`, `tests/php/file_access_security.php`, `tests/php/official_message_form_ui_security.php`, `tests/php/message_list_ui_security.php`, `tests/php/read_authorization_security.php`, `tests/integration/attachment_reservation.php`, `tests/integration/http_smoke.sh`, `tests/browser/headless_ui.py` | Der Nachrichtenvordruck nimmt neue Anlagen ohne Seitenwechsel entgegen und zeigt Anzahl-Badges, Metadatenkarten sowie rollenabhängige Aktionen; dieselbe kanonische Anzahl steht in allen operativen Listen. Der zweiphasige Store hält Name und Endung zunächst als inhabergebundene Status-8-Reservierung unsichtbar, verschiebt und hasht ohne langen Einsatz-Lock und finalisiert erst danach in einer kurzen Transaktion Status 1, SHA-256, Bytezahl, Serverzeit und Audit. Bei regulären Fehlern prüft eine neue Verbindung den Zustand: Status 1 wird nie gelöscht; eine eigene Status-8-Zeile wird unter Zeilensperre atomar als Status 2 beansprucht, bevor ihr validierter Pfad gelöscht und sie erst nach bestätigtem Fehlen der Bytes als Status 4 freigegeben wird. So kann kein Upload sie zwischen Prüfung und `unlink` wiederverwenden oder finalisieren. Ein vorher unklarer Zustand bleibt unverändert fail-closed; harte Abbrüche nach der Beanspruchung oder nicht löschbare Bytes hinterlassen eine verborgene Status-2-Cleanup-Zeile. Ein vor dem Nachrichtencommit persistierter Session-Zwischenstand, der SHA-256-Tokenhash im unveränderlichen Ereignis desselben Commits und ein tokenbezogener MariaDB-Advisory-Lock verhindern eine Doppelnachricht nach belegtem Commit. Ein unbelegter Abschluss rendert den vollständigen Entwurf samt Prüfanweisung, statt eine anderweitig verknüpfte Anlage als falschen Save-Beweis zu verwenden; eine vor der Validierung archivierte Datei bleibt bei Fehler am Entwurf und beim Verlassen als freie, berechtigungsgeschützte Archivdatei erhalten. Ein harter Prozess-/Hostabbruch kann außerhalb der regulären Bereinigung dennoch einen unsichtbaren Status-8-Staging-Rest, nach der Cleanup-Beanspruchung einen Status-2-Cleanup-Rest oder – zwischen Anlagenfinalisierung und Session-Checkpoint – eine zusätzliche freie Archivdatei hinterlassen; diese engen Grenzen sind betrieblich zu prüfen, nicht als gespeicherter Vordruck zu werten. Nach dem Nachrichten-Commit mit unveränderlichem Aktionsnachweis entsteht auch bei Workerverlust keine stille Doppelnachricht. JPEG, PNG, GIF und BMP erhalten nur bis 24 MiB und 16 Megapixel eine automatische Miniatur, andernfalls einen Platzhalter; die Kartenanforderung nutzt 640 Pixel Breite und der Endpunkt begrenzt jede Ausgabeachse auf 1.600 Pixel. PDF wird erst beim Aufklappen Same-Origin geladen und benötigt einen PDF-fähigen Browser. Nur diese vier Bildformate und PDF sind nach erneuter MIME-/Integritätsprüfung inline erlaubt; TIFF und andere zulässige Formate bleiben Downloads. Das Entfernen löst nur die Referenz und erhält die Archivdatei; die bisherige Archivauswahl bleibt als Kompatibilitätspfad. Verknüpfte Anhänge erben die Nachrichtenrechte, freie Anhänge bleiben auf Uploader sowie wirksame Funktionen S2, Si und LdF begrenzt. Direkter Upload, Liste, Download, Vorschau, Auswahl und Nachrichtensave wiederholen die modeabhängige Funktions-/Objektprüfung; die finale Prüfung verwendet eine einzige gelockte Nachrichtenmap für bis zu 100 Anlagen. Download/Vorschau geben Session- und DB-Locks vor der Dateiarbeit frei und autorisieren unmittelbar vor dem Ausgabestart mit aktuellen, sperrenden Reads erneut. In `STRICT` ist für operative Zugriffe eine ausgewählte aktive Dienstbesetzung erforderlich; in `LOOSE` nicht. MIME-, Größen-, Integritäts- und Konkurrenzgrenzen bleiben automatisiert belegt. |
| Nachweisung und Meldungsübersicht | `tests/php/message_transport_security.php`, `tests/php/message_list_filter_security.php`, `tests/php/message_list_ui_security.php`, `tests/php/read_authorization_security.php`, `tests/integration/message_list_scale.php`, `tests/integration/http_smoke.sh`, `tests/integration/message_workflow_http.sh` | Beide Ansichten sind einsatzgebunden und funktionsgebunden: Meldungsübersicht ausschließlich `S2/Stab` mit `LAGE_DOKUMENTATION`, Nachweisung ausschließlich `LdF` oder `Fernmelder`. In `STRICT` muss die Funktion aus der ausgewählten Besetzung einer aktiven Dienstschicht stammen, in `LOOSE` aus fester oder zusätzlicher Kontofunktion. Suche, Filter, Pagination, Einsatztrennung und Objektgrenzen werden serverseitig geprüft. |
| Nachrichtenvordruck-PDF | `tests/php/pdf_smoke.php`, `tests/php/generated_form_security.php`, `tests/php/read_authorization_security.php`, `tests/php/pdf_template_render_fixture.php`, `tests/static/pdf_render.sh`, `tests/integration/http_smoke.sh` | Die Vordruckklasse erzeugt ein vollständiges durchsuchbares A4-PDF im festgelegten Formularlayout. Einzelvordruck und Dossier-Nachrichtenseite verwenden denselben Renderer. Der tatsächliche und vorgesehene Übertragungsweg werden exakt einem Feld in amtlicher Reihenfolge zugeordnet. Liste, aktueller Abzug und Archivdownload verlangen aktiven Einsatz, eine nach dem Einsatzmodus wirksame Funktion und Leserecht an der zugrunde liegenden Nachricht; `STRICT` bezieht die Funktion aus der ausgewählten aktiven Dienstbesetzung, `LOOSE` aus fester oder zusätzlicher Kontofunktion. Das persistierte Archiv bleibt bytegleich nachweisbar. |
| PDF-Einsatzdossier | `tests/php/incident_pdf_security.php`, `tests/php/incident_export_security.php`, `tests/php/pdf_template_render_fixture.php`, `tests/static/pdf_render.sh`, `tests/static/pdf_temp_cleanup.sh`, `tests/static/runtime_image_surface.sh`, `tests/integration/incident_export.php`, `tests/integration/pdf_attachment_render.php` | Deckblatt und Einsatzdaten nennen den gespeicherten Berechtigungsmodus und machen eine lockere Schreibrechtssituation nachvollziehbar. Die neun wählbaren Abschnitte werden unter unveränderter MariaDB-Standardisolation aus einer ausdrücklich gestarteten konsistenten Read-only-Snapshot-Transaktion des gewählten aktiven oder historischen Einsatzes gelesen; jeder neue Anhang muss vor sichtbarer Darstellung und Einbetten gegen seinen unveränderlichen SHA-256-/Größennachweis bestehen, während Fileinfo den MIME-Typ atomar aus exakt den später eingebetteten Snapshot-Bytes verifiziert. JPEG, PNG, GIF und BMP werden sichtbar ausgegeben, verlustfrei Windows-1252-darstellbarer Text bleibt durchsuchbar und mehrseitige PDFs werden samt Anmerkungen mit Poppler geordnet einzeln seitenweise gerastert. TIFF, nicht darstellbarer Text, ZIP, Office, Video und andere nicht statisch darstellbare Formate erhalten eine eindeutige Hinweisseite. Das bytegleiche Original bleibt immer als `EmbeddedFile` enthalten. MIME-Typ und Endung müssen für darstellbare Formate übereinstimmen; harte Grenzen von 50 MiB Originalsumme, 24 MiB Rasterdaten, 8 MiB je PDF-Seitenprozess, 12 Megapixel/8.000 Pixel je Bildachse, 60 Sekunden Gesamtbudget und 15 Sekunden je Poppler-Prozess sperren beschädigte oder übergroße Eingaben fail-closed. Ein Startup-Janitor entfernt ausschließlich vollständig validierte, mehr als 24 Stunden alte Renderer-Arbeitsverzeichnisse; unsichere Kandidaten bleiben unangetastet. Sichtbarkeits-, Render- und Hinweiszähler gelangen in den Export-Audit. ETB erscheint als mehrseitiges Fb Fü 2 auf A4 hoch mit vier Spalten, Erfassungszeit, lokalem Seitenzähler und zwei Unterschriftslinien; TBB als Fb Fü 44 auf A4 quer mit sieben Spalten, fachlicher Vorgangszeit, lokalem Seitenzähler und LdF-Linie. Neue strukturierte TBB-Fakten erscheinen exakt einmal in ihrer Fachspalte; die redundante Kompatibilitätszusammenfassung wird nur bei vollständig unstrukturiertem Legacy-Bestand verwendet. Formkopf, lokale Nummer und Fortsetzungskennzeichnung wiederholen sich bei langen Zeilen. Der unbeschriebene Rest der letzten Seite wird nur bei formal geschlossenen Büchern diagonal gestrichen und beschriftet. Poppler belegt Ausrichtung, spaltenrichtige Bounding Boxes, lokale statt globaler Nummern, aufgelöste Seitenplatzhalter, das blaue ETB- und violette TBB-Kopfband, feine Schreibraster sowie die vorhandene THW-Zahnradmarke als einziges Bild in den Buchköpfen; Nachrichtenvordruckseiten folgen dem festgelegten bildfreien Layout. Direkter und Dossier-Ausdruck beider Bücher werden seitenweise pixelgleich verglichen; die Container-, Runtime-, Janitor- und echten MariaDB-Tests ergänzen sichtbare PDF-/PNG-Anlagen, bytegleiche Extraktion, harte Einsatztrennung, Anhangmanipulationsabwehr sowie Datei- und Gesamt-PDF-SHA-256. Die Linien sind nur für manuelle Unterschriften und stellen keine digitale Signatur dar. |
| ETB/TBB | `tests/php/logbook_security.php`, `tests/php/generated_form_security.php`, `tests/php/read_authorization_security.php`, `tests/php/schema_migration_contract.php`, `tests/integration/schema_migrator.sh`, `tests/integration/logbooks_http.sh`, `tests/integration/dv_operations.php`, `tests/integration/dv_evidence.php`, `tests/integration/incident_export.php`, Restore-Zweig in `tests/integration/http_smoke.sh` | Beide Bücher sind einsatzgebunden und append-only. ETB erfordert `ETB/Stab` oder `S2/Stab` mit `EINSATZTAGEBUCH`, TTB `Fernmelder` mit `BEFOERDERUNG`. In `STRICT` sind eine aktive, angenommene und ausgewählte Dienstbesetzung sowie Schicht-/Schreiberprovenienz für manuelle Zeilen Pflicht. In `LOOSE` ist keine formale Dienstschicht nötig, aber feste oder explizite Zusatzfunktion bleibt Pflicht; Schichtprovenienz darf dort `NULL` sein. Systemzeilen dürfen schreiberlos bleiben, tragen in `STRICT` jedoch weiterhin die aktive Schicht als Provenienz. Der Einsatz-Insert erzeugt exakt die Köpfe `ETB:1` und `TTB:1`; lokale Nummern bleiben unter Konkurrenz eindeutig. Korrekturen sind neue, direkt referenzierte Zeilen; Mindestaufbewahrung zehn Jahre. |
| Kategorien | `tests/php/category_security.php`, `tests/integration/categories_http.sh`, `tests/integration/schema_migrator.sh` | anonyme Zugriffe, GET-Mutationen, fehlendes CSRF, fremde Tabellenräume und fremde Meldungs-IDs werden abgewiesen; S1 verwaltet in beiden Einsatzmodi nur eigene Funktions-/Benutzerkategorien, S2/Rotkopie und Si verwalten Master; der lockere Modus erweitert keine Kategorien- oder Administrationsrechte; CRUD und Zuordnung sind atomar; Quotes, `&` und `<script>` bleiben roh in MariaDB und werden in HTML neutralisiert. Eine leere globale Liste erhält einmalig die editier- und löschbaren Vorgaben `Allgemein` sowie `EA1` bis `EA6`; ein nichtleerer Betreiberkatalog wird weder ergänzt noch überschrieben, und ein Zweitlauf stellt später geänderte oder gelöschte Vorgaben nicht wieder her. |
| Empfängermatrix und Standard | `tests/php/assignment_policy_security.php`, `tests/php/admin_operations_security.php`, `tests/integration/assignment_policy.php`, `tests/integration/admin_workflows_http.sh`, `tests/browser/headless_ui.py` | aktive Matrix und persistente Standardmatrix enthalten jeweils exakt 20 eindeutige Positionen; S2/Stab ist in beiden die unveränderliche Lage-/Dokumentationsfähigkeit und der einzige Rotkopie-Empfänger, alle Autosichtungswerte bleiben falsch. Laden und Speichern sind getrennt, atomar und CSRF-geschützt; ein globaler Richtlinienlock serialisiert Login, Kontoanlage, Neuzuweisung und Matrixspeichern. Rollenänderungen synchronisieren Konten und widerrufen Sitzungen, entfernte Funktionen bleiben administrativ reparierbar; ein erzwungener Auditfehler lässt Matrix, Konten, Sitzungen und Audit unverändert. |
| Nachrichtenzähler und PDF-Vordruckreset | `tests/php/admin_operations_security.php`, `tests/integration/message_concurrency.php`, `tests/integration/admin_workflows_http.sh` | GET und fehlendes CSRF sind inert; Admin-Reparatur und regulärer Writer teilen den aktiven Einsatz-/Zähler-Lock. In `STRICT` verlangt die Reparatur die aktive formale Dienstschicht und schreibt genau ein hashverkettetes `message_counter_repaired`-Betriebsereignis mit Objekttyp `DIENSTSCHICHT`; in `LOOSE` ist keine formale Dienstschicht nötig und der Objekttyp lautet `EINSATZ`. Zwei konkurrierende Erhöhungen erzeugen genau einen Nachweis und keine Status-0-Fachnachricht. Zugangsschichtmutationen verwenden `ZUGANGSSCHICHT`. Vordruckflags werden nur für den aktiven Einsatz nach bestätigtem POST zurückgesetzt. |
| Administration und Export | `tests/php/export_security.php`, `tests/integration/http_smoke.sh`, `tests/browser/headless_ui.py` | anonymer Zugriff wird abgewiesen; Basic Auth und CSRF schützen die passenden Aktionen; die Administrationsübersicht führt verständlich zu Einsatz samt sichtbarem, bestätigungs- und auditgebundenem Berechtigungsmodus, Konten, Kennwortrichtlinie, der modeabhängigen Schichtverwaltung und Datenwerkzeugen; im Modus `STRICT` führt diese verbindliche formale Dienstschichten und Funktionsbesetzungen, im Modus `LOOSE` optionale Zugangsschichten. Vor einem Tabellenexport werden alle neuen Anhangdateien gegen ihren Eingangsnachweis geprüft, das validierte Manifest zählt verifizierte und ausdrücklich nicht belegbare Legacy-Dateien; die Einsatz-CSV enthält den getrennten Führungsstellennamen und Berechtigungsmodus und bewahrt historische Fehlwerte als `NULL`; reguläre ZIPs werden neueste zuerst gelistet und unverändert heruntergeladen; Traversal/Symlinks bleiben außerhalb; eine zweistufig bestätigte Einzellöschung entfernt nur das gewählte Verzeichnis-/ZIP-Paar und lässt einen zweiten Export unverändert |
| Backup/Wiederherstellung | CI-Roundtrips und `docs/BACKUP-UND-WIEDERHERSTELLUNG.md` | der fachliche guardierte Lauf löscht Container und alle drei benannten CI-Volumes, stellt sie leer neu bereit und weist danach Schema, Anmeldung/Konto, Nachricht, exakten Anhanginhalt, bytegleichen persistierten PDF-Vordruck, globalen Einsatzkopf samt Führungsstellenname, vorhandene ETB-/TBB-Einträge sowie genau den zuvor validierten Exportlauf mit unverändertem ZIP-SHA-256 nach; ein historischer `NULL`-Wert wird nicht erfunden; ein zweiter Pull-only-Roundtrip verfälscht den Datenbankmarker logisch, ergänzt veraltete Testdaten in `4fdata` und Export und stellt alle Marker mit unverändertem Inhalt und SHA-256 wieder her, ohne den rohen DB-Bind-Mount zu leeren; die Archive gelangen bei der Wiederherstellung ausdrücklich über interaktive Standardeingabe in die netzlosen Hilfscontainer |
| Pull-only OCI-/NAS-Deployment | `tests/php/registry_deployment_contract.php`, `tests/integration/registry_compose.sh`, `.github/workflows/{ci,publish-images}.yml` | Registry-Compose enthält keinen Build und keinen Host-Schema-Mount; der gebundene Release-Verifier und `deploy.sh` verlangen explizite unveränderliche Image-Referenzen und akzeptieren kein stilles `latest`; vor Pull und erneut unter der engine-weiten Wartungssperre vor Start werden dedizierte Named Volumes sowie bestehende, nicht symbolische, ACL-geprüfte und paarweise getrennte Bind-Verzeichnisse erzwungen, während Root-/Top-Level-/Release-Ahnenpfade und eine Aliasierung auf `estab_auth` scheitern; nur der netzlose `admin-auth-init` sieht das Klartextsecret, die App erhält ausschließlich den bcrypt-Hash mit Kostenfaktor 12 schreibgeschützt; ein frisches Zweitprojekt startet ausschließlich die angeforderten Image-IDs und ein weiteres Projekt verwendet nachweislich echte Bind-Mounts für DB, `4fdata` und Export, initialisiert das eingebettete Basisschema, verlangt vor und nach Restore Migrator Exit 0 und eine gesunde App und beweist vor dem grünen Ergebnis die Entfernung beider Projekte samt Containern, Volumes, Netzwerken und temporärem Hostbaum; normales CI und manuell freizugebender Publish-Pfad führen das komplette Container-/Restore-Gate nativ auf amd64 und arm64 aus, der Publish-Pfad bleibt geschützt-Git-Tag-, Ruleset-, Rechte-, Variablen-, Immutable-Policy- und Required-Reviewer-gebunden, pusht App und Migrator ausschließlich digest-only, liest SPDX-SBOM sowie Build-Provenance beider Plattformen, verifiziert Image- und Release-Attestationen, blockiert behebbare hohe/kritische CVEs in App, Migrator und DB und veröffentlicht exakt vier gebundene Assets: Installations- und dauerhaftes Evidence-Archiv samt äußerer SHA-256-Datei; ein Offline-Helfer bewahrt die drei digesttreuen Multi-Arch-OCI-Indizes und prüft eine administrativ bereitgestellte Registry ausschließlich über Digestreferenzen ohne selbst Registry-Tags zu schreiben |
| Angriffsfläche des Containers | HTTP-Smoke, Admin-HTTP-Integration und Apache-Konfiguration | interne Konfiguration/Datendateien, der alte Alle-Nachrichten-View, interne Print-/FPDF-Bibliotheken und gefährliche Uploadhelfer liefern 403; nur die expliziten Direktupload-Tombstones liefern 410; Security Header sind vorhanden |

## Manueller Herkunftsnachweis

`migration/verify_provenance.py --self-test` und die weiteren Werkzeuge unter
`migration/` können die beim SVN-/Release-Import aufgezeichneten Manifeste
bewusst manuell prüfen. Sie sind kein automatisierter Produktnachweis und
kein reguläres CI- oder Release-Gate. Für die heutige Freigabe sind die
Nachweise der vorstehenden Matrix maßgeblich; die Retention-Entscheidung ist
unter [Gepflegter Quellbestand](QUELLBESTAND.md) dokumentiert.

## Stand des Abschlusslaufs vom 3. August 2026

Der vollständige Lauf von `tests/integration/ci.sh` einschließlich Migration
119 endete mit Exitcode 0 und `CI integration: OK`. Er bestätigte alle 25
Migrationen, 42 Schema-Prüfungen, 69 Einsatz-, 52 DV-Evidenz- und 243
DV-Operations-Assertions mit 95 Ereignissen, 29 Zugangsschicht-, 98
Benutzerverwaltungs-, 66 MariaDB-Kennwortrichtlinien-, 31
Selbstregistrierungs-Datenbank- plus 28 Handler-, 74 Zuordnungs-, 35
Einsatz-PDF-, 11 Anhangdarstellungs-, 32 Nachrichtenvorschlags- und 145
Nachrichtensuch-Assertions. HTTP-Surface, Selbstregistrierung, Auth-Smoke,
Logbücher, Kategorien, Nachrichtenworkflow, Administrationsworkflow sowie der
destruktive Backup-/Restore-Roundtrip endeten ebenfalls erfolgreich.

Migration `119-inactive-messenger-dispatch.sql` und die Auswahl eines
abgemeldeten, fachlich berechtigten Fernmelders sind damit vollständig
bestätigt. Der dedizierte Modus
`tests/browser/headless_ui.py --inactive-messenger` bestand in Chrome 150 und
belegte Statusdarstellung sowie den sichtbaren Hinweis zur separaten
Information nach der Auswahl.

Die getrennte statische PHP-8.5-Suite endete mit Exitcode 0 und lintete 229
aktive PHP-Dateien.

Chrome 150 bestand im selben vollständigen Lauf die öffentlichen BOS- und
Handbuch-Abnahmen, den allgemeinen zwölfstufigen UI-Lauf sowie die gesonderten
Abnahmen für die inaktive Melderauswahl, Fernmeldeplan-Versionierung,
Meldungsüberschriften und Nachrichtenvorschläge. Der S6-Browsernachweis umfasst den vollständigen Klon,
Edit/Add/Delete, medienabhängige Felder, Dirty-Schutz, Publizieren, die
unveränderliche Historie und die mobile Darstellung mit `390 × 844`
CSS-Pixeln.

Nachweisgrenze für den Berechtigungsmodus: Der vollständige Lauf belegt den
Vertrag der Migration 118 statisch, über MariaDB und über authentifizierte
HTTP-Abläufe. Die echten Browserläufe enthalten jedoch weiterhin kein
dediziertes Szenario für die bestätigte Modusumschaltung samt sichtbarer
Warnung, die Auswahl und Annahme einer `STRICT`-Dienstbesetzung oder ein
`LOOSE`-Konto mit mehreren festen/zusätzlichen Stabs-/FB-Funktionen und dessen
expliziter `acting_function`-Auswahl. Diese Bedienpfade bleiben Teil der
verpflichtenden manuellen Fachabnahme; die grünen Einzelfunktions-Browserläufe
ersetzen sie nicht.

Ergänzend bindet der aktuelle ETB-/TTB-Nachweis in beiden Modi die fachlichen
Schreibrechte: `ETB/Stab` und `S2/Stab` schreiben ETB; die Funktion
`Fernmelder` schreibt TTB. In `STRICT` müssen aktive Dienstschicht,
persönliche Annahme und Auswahl der passenden Besetzung belegt sein. In
`LOOSE` genügt die feste oder eine explizite Zusatzfunktion ohne formale
Dienstschicht. Migration 118 setzt diesen aktuellen Vertrag auf, ohne die
checksum-gebundenen Vorgängermigrationen umzuschreiben. Die ETB-Oberfläche beweist leere
Gesamtliste und kombinierte Volltext-/Art-/Nummer-/Bezugs-/Anlagensuche. Neue
Referenzen akzeptieren ausschließlich die kanonische lokale Nummer eines
vorhandenen Eintrags desselben Einsatzes; historischer Freitext bleibt lesbar,
ohne als Referenzkante zu gelten. Die Auswertung zeigt von einer lokalen
Startnummer vorwärts auch verzweigte Folgeeinträge oder rückwärts den
Bezugspfad mit Tiefe 1 bis 25 und eigener Druckansicht. Berichtigungen binden
intern direkt das Original und zeigen dessen serverseitig abgeleitete lokale
Nummer.
Optional verknüpft sie genau einen finalisierten, unbenutzten Anhang und zeigt
die automatisch abgeleitete Nummer
`ETB {einsatz_id}-{estab_book_lfd}-1`; ein Kennzeichen wie `EL0001` bleibt
getrennt. Statische, Migrations-, HTTP- und Exporttests belegen Auswahlgrenze,
`UNIQUE(estab_attachment_id)`, Upgrade-Block bei Bestandsduplikat,
Mehrfachlink-Abweisung sowie Darstellung in Webliste, Fb Fü 2 und
Anlagenverzeichnis. Im TBB-PDF wird nur die redundante Zusammenfassung
`tbb_aktion` unterdrückt; `tbb_bemerk` erscheint genau einmal. Die Anlage ist
optional. Der Dossierdialog bietet ETB/TBB als Gesamtbuch oder für eine
aktuelle beziehungsweise historische formale Dienstschicht mit belegter
Provenienz an. Dieser Dienstschichtfilter umfasst keine Zugangsschicht und
keine Zeile mit `NULL`-Provenienz. Er filtert
ausschließlich diese beiden Buchabschnitte; alle anderen gewählten Sektionen
bleiben einsatzweit. Deckblatt und `pdf_export`-Audit enthalten den
aufgelösten Umfang samt Schichtmetadaten.

Neue manuelle ETB-/TTB-Zeilen müssen in `STRICT` Dienstschicht und schreibende
Dienstbesetzung speichern. Automatische Systemzeilen dürfen dort ohne
menschliche Schreiberbesetzung entstehen, müssen aber dieselbe aktive
Dienstschicht als Provenienz tragen. Nur in `LOOSE` dürfen Schicht und
Schreiberbesetzung `NULL` bleiben; vorhandene belegte Werte bleiben erhalten. Eine
optionale Bearbeitungszuordnung ist ausschließlich Such- und Anzeigehilfe und
erweitert keine Rechte. Webliste und Suche verwenden ihren Snapshot, das
amtliche PDF nicht.
Der TBB-Typ `nachricht` ist neuen automatisch verknüpften Transportzeilen
vorbehalten. Meldungsübersicht, zweite Sichtung und Nachweislisten zeigen,
suchen und sortieren ausschließlich dessen lokale TBB-Nummer; technische
Archivnummer und globale Datenbank-ID werden nicht als fachlicher Nachweis
ausgegeben. Fehlt die Transportzeile, erscheint „noch kein TBB-Nachweis“.

Der gezielte aktuelle Exportnachweis besteht aus grünen
`incident export security`-/`incident PDF security`-Verträgen,
PDF-Template-Fixtures, dem Containerlauf
`PDF attachment render integration: OK` und grünem Poppler-Rendervergleich.
Dabei sind Default-Gesamtbuch,
Legacy-Dienstschichtfilter, Fremdschichtabweisung, Umfang auf Deckblatt/Audit,
einsatzweite Begleitsektionen und das Fehlen der Suchzuordnung im Formblatt
gebunden. Auch eine schichtübergreifende ETB-/TTB-Korrektur druckt die lokale
Nummer ihres Originals per einsatzgebundenem Self-Join; der globale
Primärschlüssel erscheint weder als laufende noch als Korrekturbezugnummer.
Die beteiligten PHP-Dateien linten und `git diff --check` ist grün. Der echte
MariaDB-Exporttest ist ebenso grün wie der frische
Gesamt-Compose-Lauf; der gezielte PDF-Lauf allein ist kein Ersatz dafür.

Die ETB-/TBB-Kriterien beziehen sich auf die bereitgestellte Datei
`ETB_TBB_Fuehrung_in_THW_FueSt.pdf`, Handbuch ETB/TBB Version 1.0, Stand März
2022, SHA-256
`2457d1deccd01892655bbc329b08885a0b3c8b3ebfb6372c79997d3427d1ae59`.
Ein grüner technischer Nachweis ist ausdrücklich **keine formale
THW-Freigabe**. Die Referenz lässt nur ein durch die THW-Leitung freigegebenes
elektronisches ETB/TBB zu. Diese externe Freigabe sowie die örtliche fachliche
Abnahme müssen vor einem amtlichen urkundlichen Produktiveinsatz separat und
schriftlich vorliegen.

Die CI-Orchestrierung liegt in `tests/integration/ci.sh`. Sie verwendet
ephemere Secrets, eigene Volumes, harte Zeitgrenzen, Fehlerartefakte und
entfernt den Teststack am Ende. PHP-Warnungen, Notices, Deprecations, Fatals
und ungefangene Fehler im App-Log sperren die Freigabe. GitHub Actions setzt
den Browsermodus auf `required`; lokal läuft er standardmäßig automatisch,
wenn Python 3 und Chrome/Chromium verfügbar sind. Ein lokales `SKIP` ist kein
vollständiger Freigabenachweis. Browserfehler sichern Screenshot und
kennwortfreien Frame-/Session-Zustand im CI-Diagnoseartefakt. Der
Nachrichtenrollenlauf verwendet ausschließlich den verbindlichen DV-Laufweg.
Eine zweite Konfigurationsvariante, Autosichtung oder ein Bypass werden nicht
mehr getestet, weil die Anwendung diese Abkürzungen nicht mehr anbietet.

## Verpflichtende fachliche Bedienabnahme

Die folgenden Interaktionen hängen von der organisationsspezifischen
Empfängermatrix, Rollenbesetzung und fachlichen Bedeutung der Daten ab. Sie
werden vor dem ersten Produktiveinsatz und nach fachlich relevanten Änderungen
mit den tatsächlich verwendeten Funktionen im Browser abgenommen:

- ein Funktionskonto anmelden, 15 Minuten trotz laufender Statusaktualisierung
  ohne Browserinteraktion offen lassen und den Wechsel zu „Inaktiv“ prüfen;
  eine echte Eingabe muss wieder „Aktiv“ melden. Den 12-Stunden-Widerruf
  ausschließlich im isolierten Testsystem beziehungsweise über den
  automatisierten, kontrolliert zurückdatierten HTTP-Nachweis prüfen und
  anschließend eine reguläre Neuanmeldung verlangen,
- einen Eingang über Fernmelder, LdF und Si sowie einen Ausgang über Verfasser, Si,
  LdF und Fernmelder vollständig bearbeiten; zusätzlich einen formal zurückgegebenen
  Ausgang korrigieren und erneut einreichen,
- mit getrennten festen Funktionskonten positive und negative
  Lesetests durchführen: Meldungsübersicht nur für S2, Nachweisung nur für LdF
  und Fernmelder, terminale Empfängerkopie, eigener Ausgang,
  Verarbeitungsmarken von Si, LdF und Fernmelder sowie ein jeweils fremdes Objekt,
- die Modi im Browser mit getrennten Einsätzen abnehmen: Ein vollständig
  leerer Umschalt-/Sperr-Fixture prüft bestätigten Wechsel, Audit,
  idempotentes Speichern desselben Werts und die dauerhafte Sperre nach der
  ersten operativen oder formalen Eintragung. Ein eigener `STRICT`-Einsatz
  muss ohne ausgewählte Besetzung abweisen und erst die persönlich angenommene
  Funktion einer aktiven Dienstschicht zulassen. In einem eigenen
  `LOOSE`-Einsatz muss ein fachfremdes Konto scheitern und erst die
  administrativ vergebene passende Zusatzfunktion denselben Schritt erlauben.
  Zusatzfunktionen bleiben im getrennten `STRICT`-Fixture wirkungslos.
  Anmeldung, Kontosperre, Einsatzbindung, CSRF, Objektstatus, Sperrinhaber,
  Audit und Integritätsgrenzen müssen in allen Fixtures greifen,
- einen `LOOSE`-Mehrfunktionsfall mit fester Kontofunktion und mindestens zwei
  ausdrücklich vergebenen Zusatzfunktionen abnehmen: Das Menü darf nur die
  Vereinigung dieser Funktionen zeigen und niemals weitere Fachrechte. Für
  mehrere gewöhnliche Stabs-/FB-Funktionen müssen getrennte `Schreiben als …`-
  und `Lesen als …`-Aktionen jeweils genau den gewählten `acting_function`-
  Kontext durch Formular, Liste, Status, Detail, Objektberechtigung und Audit
  tragen. Nach dem administrativen Entzug einer Zusatzfunktion müssen ihre
  Aktion, ein bereits geöffneter Fortsetzungsaufruf und ein manipulierter oder
  veralteter `acting_function`-Wert fail-closed scheitern; feste Funktion und
  übrige Zusatzfunktionen dürfen dadurch nicht erweitert oder vertauscht
  werden,
- einen verknüpften und einen freien Anhang über Liste, Vorschau, Download,
  Auswahl und endgültiges Nachrichtenspeichern mit berechtigter und
  fachfremder wirksamer Funktion prüfen,
- rote/grüne Kopien sowie Priorität, Gesprächsnotiz und Sperrverhalten prüfen,
- globale, funktionsbezogene und persönliche Kategorie anlegen, zuweisen,
  suchen und wieder entfernen,
- Empfängermatrix und Rollenbezeichnungen gegen den örtlichen Führungsaufbau
  prüfen; S2-Rotkopie und ausgeschlossene Autosichtung kontrollieren und
  sicherstellen, dass bereits angemeldete Funktionen organisatorisch neu
  zugeordnet werden,
- die Kennwortrichtlinie von der Voreinstellung 12 aus voranzeigen,
  revisionsgebunden bestätigen und mit einer Kombination aus Mindestlänge und
  Unicode-Zeichenklassen prüfen; schwache Neuanlage und schwachen Reset
  abweisen, einen gültigen Reset durchführen und danach sicherstellen, dass
  ein vor der Verschärfung vorhandenes Konto weiterhin regulär anmelden kann
  und das getrennte Basic-Auth-Kennwort unverändert bleibt,
- Nachrichtenzähler nach einer simulierten Papierrückfallebene ausschließlich
  erhöhen und verketteten Zählernachweis sowie Audit-Eintrag prüfen;
  bestätigen, dass keine Fachnachricht entstand und die nächste echte Nummer
  folgt; PDF-Vordruckreset bestätigen und die erneute PDF-Erzeugung beobachten,
- Hinweistöne für Fernmelder, Si und Stab/FB in jedem vorgesehenen Browser
  ausdrücklich aktivieren, den Testton und nach der stillen ersten Messung
  jeweils genau einen Hinweis bei realer Warteschlangenerhöhung physisch hören
  sowie die sichtbare Rückmeldung bei ausgeschaltetem oder blockiertem Ton
  kontrollieren; automatisierte Wiedergabeaufrufe ersetzen diese Hörprobe
  nicht,
- im lockeren Modus optionale Zugangsschichten anlegen, ein Konto mehrfach zuordnen, OR-Semantik,
  Gruppenaktivierung ohne Anmeldung, Gruppendeaktivierung mit Sitzungswiderruf
  und den Vorrang einer manuellen Kontosperre prüfen; ein unzugeordnetes Konto
  muss weiter arbeiten können,
- einen S6-Fernmeldeplan versionieren und einen Melderauftrag von der
  Beauftragung bis zur Rückmeldung vollständig durchlaufen,
- vollständigen Pflichtkopf anlegen; in einem eigenen `STRICT`-Einsatz ETB und TTB erst mit der
  ersten aktivierten formalen Dienstschicht eröffnen und deren Schichtprovenienz
  prüfen, in einem getrennten `LOOSE`-Einsatz eine schichtfreie Eröffnung mit
  lokaler Nummer 1 prüfen; zusätzlich einen bereits aktiven, ungeöffneten und
  weiterhin vollständig von operativen sowie formalen Eintragungen freien
  `STRICT`-Einsatz bestätigt nach `LOOSE` umstellen und die atomare schichtfreie
  Eröffnung dabei prüfen; danach manuelle ETB-/TTB-Einträge in
  `STRICT` nur mit passender ausgewählter Dienstbesetzung und in `LOOSE` nur
  mit fester oder zusätzlicher Funktion prüfen; fachfremde Konten dürfen nicht
  schreiben,
- ETB-Kennzeichen A/B/E/K/W, getrennte Ereignis-/Erfassungszeit, Nachricht,
  Anhang, kanonischen Bezug auf eine vorhandene lokale ETB-Nummer,
  vorwärts/rückwärts auswertbare Referenzketten samt Druckansicht und
  Berichtigung als neuen direkt referenzierten Eintrag prüfen; im TBB alle
  fünf Inhaltsbereiche, Pflichtinhalt,
  Nachrichteneingang/-ausgang und Korrekturbegründung prüfen,
- verpflichtende Schicht-/Schreiberprovenienz manueller `STRICT`-Zeilen,
  verpflichtende Schicht- bei nullable Schreiberprovenienz für
  `STRICT`-Systemzeilen sowie nullable Schicht-/Schreiberprovenienz in
  `LOOSE`, Abschluss-Preflight ohne Schichtblocker, automatische
  Abschlusszeilen, formalen Einsatzabschluss,
  zehnjährige Mindestfrist und Aufbewahrungssperre prüfen,
- erzeugten Vordruck drucken und auf einer realen Rückfallebene lesen; das
  PDF-Dossier einmal mit allen neun Abschnitten öffnen; Fb Fü 2 und Fb Fü 44
  einschließlich Ausrichtung, Spalten, Fortsetzung, lokalen Seitenzählern und
  allen manuellen Unterschriftslinien sowie Nachrichten-/Betriebsnachweisketten
  prüfen; JPEG, PNG, GIF, BMP, verlustfrei Windows-1252-darstellbaren Text und
  eine mehrseitige PDF-Anlage samt Anmerkung im Seitenstrom kontrollieren,
  TIFF-/ZIP-/Office-/Video- sowie nicht darstellbare Text-Hinweisseiten
  bewerten und jedes bytegleiche Original über die Anlagenansicht wieder
  extrahieren,
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
Formale THW-Freigabe ETB/TBB: Aktenzeichen/Stand oder NICHT VORHANDEN:
Bekannte Abweichungen mit Ticket:
Freigabeentscheidung:
```

Eine interne Softwarefreigabe ist nur zulässig, wenn alle automatisierten
Gates erfolgreich sind und jede im Einsatz verwendete fachliche Funktion
abgenommen wurde. Für den amtlichen urkundlichen ETB-/TBB-Betrieb genügt sie
nicht; dafür muss zusätzlich die formale THW-Freigabe dokumentiert sein.
