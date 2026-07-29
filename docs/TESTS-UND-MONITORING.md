# Tests, Funktionsnachweis und Monitoring

Die Funktionssicherung besteht aus mehreren Ebenen. Keine einzelne
Health-Antwort beweist die vollständige Fachfunktion.

Die Zuordnung jeder Funktion zu ihrem automatisierten und fachlichen Gate
steht in der [Funktionsmatrix](FUNKTIONSNACHWEIS.md).

| Ebene | Nachweis |
| --- | --- |
| Quellprüfung | PHP-8.5-Lint, Kompatibilitäts-, Sicherheits-, Einsatz-, Benutzerverwaltungs-, Upload-, Export- und PDF-Regressionen |
| Image-Build | benötigte PHP-Erweiterungen und Apache-Konfiguration |
| Datenbank | echtes MariaDB-Schema, Einsatz-Singleton/Trigger, Kontosperre, Indizes, aktive und persistente Standardmatrix, Engines, Collations und Zero-Date-Freiheit |
| HTTP | Header, direkte Endpunktfläche, 403-/400-/405-Grenzen, PNG-Antworten, Registrierung, sichtbare Sitzungsidentität, CSRF-Abmeldung, erneute Anmeldung, vollständige A/W-/Si-/S1-/S2-/S3-/POL-FB-Nachrichtenläufe in beiden Ausgangssichtungsmodi, Autosichtung, Zweitprüfung, Antworten/Weiterleiten, Kategorien- und ETB-/TBB-Rollengrenzen, reale Vordruckerzeugung/-auslieferung sowie optional Admin-Export |
| Echter Browser | öffentliche Übersicht, getrennte Konto-Flows, acht stabile Navigationsbereiche, aktive Markierung, reale Karten- und Bereichswechsel im selben Tab, überlappungsfreie Karten-Klickflächen und echter Hover bei sechs Breiten, genau zwei Anwendungs-`iframe`-Elemente, vollhohe Sidebar ohne verschachtelte Scrollflächen bei 1440 × 1000, 1280 × 720 und 700 × 760 CSS-Pixeln, fokuserhaltender Statusfragment-Refresh samt sichtbarem Fehler- und Erholungspfad, dauerhafte Warnstufe bei offenen Meldungen, gleich-originiges PCM-WAV, ausdrücklicher Hinweiston-Schalter samt Blockade-/Reload-/Synchronisations-/Race-Pfad und automatischem Signal, langlebiges Audioelement, Matrixstandard-Bestätigungen, BOS-Disclosure, Logout sowie öffentliche und authentifizierte mobile Bedienung bei exakt 390 × 844 CSS-Pixeln |
| Fachabnahme | kompletter Nachrichten-, Anhang-, PDF-, ETB-/TBB- und Restore-Ablauf |
| Betrieb | kontinuierliche Readiness, Logs, Restarts, Kapazität und Backup-Alter |

## Statische Tests

Mit lokalem PHP 8.5:

```console
tests/static/run.sh
```

Oder mit der festgelegten PHP-Version und ihrem Multi-Arch-Digest im
CLI-Image:

```console
podman run --rm \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  php:8.5.8-cli-trixie@sha256:58b996c35ce0511cdbaa1fc0476a194fd0221097d721ff7df5af0b6f1a3d0202 \
  tests/static/run.sh
```

Die Suite lintet alle aktiven PHP-Dateien und führt die Prüfungen unter
`tests/php/` aus. Dazu gehören unter anderem:

- PHP-8.5-Laufzeit- und Legacy-Konstruktor-Kompatibilität,
- `NULL`-/Zero-Date-Behandlung,
- Anmelde-, administrative Kontoanlage-, unveränderliche Funktionsbindungs-,
  Session- und
  Passwortregeln sowie die fail-closed Proxy-Peer-Vertrauensgrenze mit
  IPv4-/IPv6-CIDR-Allowlist,
- strikt boolescher Legacy-Fallback und Umgebungsoverride für die optionale
  Ausgangssichtung sowie fehlersicheres Verhalten bei ungültigen Werten,
- kanonische Reihenfolge, sichere URL-Auflösung, aktive Route und ausschließlich
  erlaubte symbolische Anmeldeziele der gemeinsamen Navigation,
- zustandsabhängige Root-Menükarten mit genau einem Tastaturziel, sicherem
  Escaping, gleichem Browserkontext, sicherer Zielbeibehaltung und
  verständlicher Trennung von Anwendung, Administration und öffentlichen
  Inhalten,
- HTML-escaping und Base-Path-Auflösung der gemeinsamen Sitzungsleiste,
  öffentliche und authentifizierte Navigation, aktive Markierung, dauerhaft
  sichtbarer Sidebar-Modus, separater kompakter BOS-Disclosure-Modus,
  Zwei-`iframe`-Vertrag, mobile Vollviewport-Zeilen, Statusfragment,
  rollenabhängige Textaktionen und sichere
  `estab:show-content`-Kommunikation, Dirty-Form-Guard, eindeutige
  Abmeldeformulare, POST-/CSRF-Vertrag, lokale Session-Zerstörung bei
  DB-Fehlern, unveränderte Nicht-HTML-Antworten sowie SID-gebundene
  Statusänderung,
- gemeinsames responsives Werkzeuggestell für die erreichbaren
  Administrations-, Kategorien-, ETB-/TTB-, Nachweis- und
  Meldungsübersichtsseiten einschließlich beschrifteter Felder,
  Tastaturfokus, responsiver Tabellenkarten und begrenzter Scrollflächen für
  unvermeidbar breite historische Fachformulare,
- rollenabhängige Zuordnung der drei Hinweistondateien, validierte
  gleich-originige WAV-URLs, die einmalige Initialisierung und Fortschreibung
  der `old_que_*`-Basiswerte, Auslösung ausschließlich bei einer späteren
  Erhöhung, ausdrückliche Browserfreigabe, langlebiges Audioelement und
  sichtbare Status-/Fehlerrückmeldung,
- Nachrichten-IDs, Rollen-/Objektregeln, Empfänger-Tokens, erlaubte
  Workflow-Aktionen, POST-/CSRF-Verträge, Prepared Statements, sichere
  UTF-8-/Legacy-Entity-Ausgabe und die inerten Payloads Quotes, Ampersand,
  `<script>` sowie SQL-ähnlicher Text,
- erlaubte Bildbutton-Typen, Farb-/Größen-/Textgrenzen, öffentliche
  Endpunktverträge, vollständige Apache-Sperrlisten und eine CSP ohne
  `unsafe-eval`,
- CSRF-Token,
- Empfängermatrix- und Standardmatrix-Validierung mit jeweils 20 eindeutigen
  Positionen, genau einer gültigen Rotkopie und nur gültigen
  Autosichtungszielen sowie getrennten Laden-/Speichern-Aktionen,
  globalem Zuordnungslock, atomarer Matrix-/Kontenabstimmung,
  Rollen-Synchronisierung, Sitzungswiderruf, reparierbarem Waisenstatus,
  Zwei-Tabellen-Transaktion, lokalem Bestätigungsvertrag und ohne generierte
  PHP-Konfiguration; außerdem Nachrichtenzähler- und
  PDF-Vordruckreset-Validierung samt Prepared-Statement-, Transaktions-,
  Auth-/CSRF- und PRG-Vertrag,
- Kategorien-Typen, positive IDs, sessionabgeleitete Tabellenräume,
  Master-Rechte, doppelte Auswahllisten, HTML-Ausgabe sowie den
  Prepared-Statement-, Transaktions-, Objektberechtigungs- und PRG-Vertrag,
- globalen Einsatz-Singleton, revisionsgesicherte Aktivierung,
  No-active-Eingabesperre, Einsatzzuordnung sämtlicher operativer Tabellen und
  einheitliche Statusbanner,
- Kontosperre und Kennwortreset mit gemeinsamem Login-Lock,
  Sitzungswiderruf, auditgebundenem Rollback und ohne Klartextweitergabe,
- Upload- und Anhangpfadvalidierung,
- authentifizierte Dateiauslieferung samt Traversal-, Symlink- und
  Header-Injection-Schutz,
- portabler Tabellenexport,
- Compose-Startgate, private MariaDB-Optionsdatei, Migration-Ledger,
  Prüfsummenbindung und Runtime-Schemavertrag,
- selbsttragende Fresh-Schema-Initialisierung, pull-only Registry-Compose,
  persistente Storage-/Secret-Grenzen, guardierte echte Host-Bind-Mounts samt
  Backup-/Restore-Vertrag und vollständiger Cleanup-Postcondition, manuell
  durch Rechtefreigaben gesperrter GHCR-Workflow, native amd64-/arm64-Läufe,
  inhaltlich gelesene SPDX-SBOM/Build-Provenance, gegen Quellcommit und
  Digest verifizierte OCI-Attestation aus GHCR, fail-closed
  High-/Critical-CVE-Gate und
  prüfsummengebundenes Digest-Installationspaket,
- Erzeugung lesbarer Nachrichtenvordrucke und eines durchsuchbaren
  PDF-Einsatzdossiers mit sicher eingebetteten Originaldateien.

Ein Prozess-Exitcode ungleich null sperrt die Freigabe.

## Vollständiges lokales CI-Gate

Der gleiche Orchestrator wie in GitHub Actions lässt sich mit Docker oder
Podman ausführen. Er akzeptiert für seine absichtliche Volume-Löschung nur
Compose-Projektnamen `estab_ci` oder `estab_ci_*` und erzeugt alle Secrets in
einem privaten temporären Verzeichnis:

```console
COMPOSE_PROJECT_NAME=estab_ci_local_01 \
ESTAB_CONTAINER_CLI=podman \
ESTAB_HTTP_PORT=18080 \
ESTAB_BROWSER_TEST=required \
tests/integration/ci.sh
```

Bei Podman liegen die kurzlebigen Verzeichnisse unterhalb des ausgecheckten
Repositorys, damit Podman Desktop den Hostpfad sicher in seine VM einhängen
kann. `.gitignore` und `.dockerignore` schließen sie aus; der Exit-Trap entfernt
sie auch nach einem Fehler. Docker verwendet standardmäßig `RUNNER_TEMP` oder
`/tmp`. Ein anderer bereits vorhandener, schreibbarer Pfad kann für beide
Engines explizit mit `ESTAB_CI_TEMP_PARENT` gesetzt werden.

Der normale lokale und Standard-CI-Lauf baut die festgelegten Images frisch
und startet sie zusätzlich in einem zweiten, pull-only
Registry-Compose-Projekt ohne Build oder Host-Schema-Mount. Dort müssen der
selbsttragende Migrator mit Exitcode 0 und die App gesund enden. Derselbe Test
startet danach ein weiteres Pull-only-
Projekt mit drei echten temporären Host-Bind-Mounts für MariaDB, `4fdata` und
Exporte. Container-Inspect bindet Typ, Quellpfad und Containerziel an den
erwarteten Zufallspfad. Ein Datenbankmarker und zwei Dateimarker werden mit
SHA-256 gesichert, ausschließlich nach erfolgreicher Prüfung von Projektname,
temporärem Pfad und Guard-Datei vollständig geleert und aus dem Backup
wiederhergestellt. Migrator, Readiness und alle drei Marker müssen danach
exakt übereinstimmen. Vor dem grünen Ergebnis müssen beide Registry-Projekte,
ihre Container, Volumes und Netzwerke sowie der temporäre Bind-Baum entfernt
sein.

Der Schema-Migratortest simuliert außerdem einen unterbrochenen Fresh-Lauf nach
dem ersten DDL mit vorab gespeichertem Baseline-Checksum, setzt genau diesen
Lauf fort und weist separat nach, dass ein unprotokollierter
`nv_*`-Teilbestand blockiert und unverändert bleibt. Für Migration 40 entfernt
er anschließend kontrolliert den Ledgerabschluss und beweist beide
MariaDB-Abbruchpunkte: Die markierte eigene Standardmatrixtabelle wird leer
neu befüllt oder mit bereits vollständigem kanonischem Seed ohne Abweichung
abgeschlossen. Eine veränderte markierte Tabelle und eine fremde Tabelle
gleichen Namens bleiben jeweils unverändert blockiert.
Anschließend migriert der Hauptlauf ein leeres Schema,
führt PHP-, Datenbank-, Rollen-, HTTP- und Administrationsnachweise aus, prüft
die Containerlogs und stellt Datenbank, Anhang-/Vordruckdaten sowie Exporte aus
einem prüfsummengebundenen Backup in neue Volumes wieder her. Ein exakt
sechszeiliger Zustandsvertrag bindet dabei Nachricht, Anhang, generierten
Vordruck samt SHA-256 sowie Kennung und SHA-256 des zuvor vollständig
validierten Export-ZIP an den zweiten, ausschließlich prüfenden HTTP-Lauf.
Dieser findet außerdem den globalen Einsatzkopf und die vor dem Backup
angelegten ETB-/TBB-Einträge nur lesend wieder. Am Ende werden nur die
guardierten CI-Container,
CI-Volumes, temporären Bind-Mounts und Secrets entfernt. Mit
Docker wird `ESTAB_CONTAINER_CLI=docker` gesetzt oder die Variable weggelassen.
Vor den allgemeinen Schreibtests aktiviert die CI über die Domänen-API den
fest benannten Einsatz `CI-INTEGRATION`. Danach belegt sie die
Benutzerverwaltung gegen MariaDB und erzeugt einen separaten historischen
PDF-Testeinsatz. Dessen ETB, TTB, Nachricht und Originalanhang müssen aus
einem konsistenten Read-only-Snapshot exportiert werden, während gleichartige
Daten des aktiven CI-Einsatzes ausgeschlossen bleiben. Der Test extrahiert
den realen `/EmbeddedFile`-Stream und vergleicht Bytes und SHA-256 mit dem
Original, bevor er Testdaten und aktiven Einsatz wiederherstellt.
Der vollständige Nachrichtenrollenlauf arbeitet zunächst mit
`ESTAB_REVIEW_OUTGOING_MESSAGES=false`, erstellt dann nur den App-Container
mit `true` neu, prüft beide Statuspfade und stellt anschließend den
Standardwert `false` wieder her.
Die HTTP-Stufe beweist dabei den Übersichts-Anmeldebutton, den
Bestandskonto-Flow, die sichtbare Kontenauswahl, die standardmäßig fehlende
öffentliche Kontoanlage, unveränderte Kontenzahlen und Passwort-Hashes bei
blockierten Neuanlageversuchen, unveränderliche Funktionsbindung auch nach
Logout, die sichere Wiederaufnahme eines zuvor gewählten geschützten Bereichs,
die gemeinsame Sitzungsanzeige und CSRF-geschützte Abmeldung. Die Fachtests
provisionieren ihre Konten über dieselbe transaktionale
Benutzerverwaltungs-API. Zusätzlich steuert der Browser-Akzeptanztest einen
echten Chrome-/Chromium-Prozess.
Der Hauptstack läuft dabei durchgehend mit
`ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false` und weist auch für angeblich
gleich-originige tokenlose Zugangsdaten HTTP 403 nach. Erst danach wird nur
der App-Container kurz mit dem expliziten Wert `true` neu erstellt:
`tests/integration/legacy_login_http.sh` belegt genau einen historischen
Ein-Kennwort-Login, die weiterhin geschlossene Cross-Site-Grenze und den
CSRF-geschützten Logout. Anschließend stellt die CI den sicheren Standard
`false` wieder her, bevor Fachtests weiterlaufen.
Das Gate verwendet standardmäßig die auch für Laptop, LAN und Reverse Proxy
empfohlene root-relative Einstellung `ESTAB_PUBLIC_URL=/`; absolute
Basis-URLs und zusätzliche Pfade bleiben durch parametrisierte HTTP- und
statische URL-Tests abgedeckt.

`ESTAB_BROWSER_TEST` kennt drei Betriebsarten:

- `auto` ist der lokale Standard und führt den Test aus, wenn Python 3 und
  Chrome/Chromium gefunden werden; andernfalls meldet der Lauf ausdrücklich
  `SKIP`.
- `required` macht den Browser zum Freigabe-Gate und bricht bei fehlender
  Laufzeit ab. GitHub Actions verwendet diesen Modus im nativen amd64-Lauf.
- `skip` deaktiviert den Test bewusst und ist kein vollständiger
  Freigabenachweis. Nur der zusätzliche native arm64-Lauf verwendet ihn; alle
  server- und containerseitigen Gates bleiben dort verpflichtend.

Die Standard-CI läuft auf Push, Pull Request, manuelle Anforderung und jeden
Montag um 03:23 UTC. Damit werden auch neue Schwachstellenmeldungen und
zeitabhängiger Infrastrukturdrift sichtbar, wenn am Quellstand nichts geändert
wurde. Beide Architekturläufe laden das jeweilige Diagnose- und
Evidenzverzeichnis unabhängig vom Ergebnis für 14 Tage als
`compose-evidence-*` hoch. Ein grüner Lauf bleibt dadurch genauso
nachvollziehbar wie ein fehlgeschlagener; Secrets dürfen in diesem Verzeichnis
nie abgelegt werden.

## Publish-Gate für exakt gebaute Candidate-Images

Der manuelle Publish-Workflow baut App und Migrator nicht erneut zwischen Test
und Veröffentlichung. Nach den Rechte-, Tag- und Environment-Gates baut und
pusht er jeden Dockerfile genau einmal als Multi-Arch-Index unter einem
laufbezogenen `candidate-*`-Tag. Die zurückgegebenen beiden Index-Digests und
der tatsächliche Candidate-Tag werden als Stage-Outputs an die nachfolgenden
Jobs gebunden.

Je ein nativer Runner auf `amd64` und `arm64` führt anschließend
`tests/integration/verify_release_candidate.sh` aus. Der Nachweis bindet:

1. die Rohbytes des Indexes an den angeforderten SHA-256-Digest,
2. den plattformspezifischen Descriptor an das native Manifest,
3. dessen Config-Digest an die tatsächlich lokal gezogene Image-ID,
4. SPDX-SBOM und SLSA-Provenance an `linux/amd64` beziehungsweise
   `linux/arm64`, und
5. die aus GHCR geladene OCI-Attestation an Repository und Git-Commit.

Danach erhält derselbe vollständige Orchestrator die beiden Referenzen intern
als `ESTAB_PREBUILT_APP_IMAGE` und `ESTAB_PREBUILT_MIGRATE_IMAGE`. Er akzeptiert
sie nur gemeinsam und nur in der Form `Image@sha256:…`, baut App und Migrator
in diesem Modus nicht neu und prüft über Container-Inspect sowohl vor den
Fachtests als auch nach dem Restore, dass genau die erwarteten Image-IDs
liefen. Der Browser ist auf `amd64` verpflichtend und nur im zusätzlichen
`arm64`-Lauf bewusst deaktiviert. Trivy scannt App, Migrator und die
MariaDB-Basis auf beiden nativen Architekturen über ihre exakten
Index-Digests.

Jeder erfolgreiche Architekturlauf lädt für 90 Tage ein
`publish-evidence-*`-Artefakt hoch. Es enthält Index und natives Manifest,
Image-IDs, SBOM, Provenance, strukturierte Attestationsprüfung,
Trivy-Ausgaben, CI-/Browser-Evidence und eine `SHA256SUMS`. Bei einem Fehler
wird stattdessen die bis dahin vorhandene separate
`publish-diagnostics-*`-Sammlung für sieben Tage gesichert. Scheitert eine
Architektur oder schon der Candidate-Build, entstehen weder Finaltags noch
ein GitHub-Release.

Der Release-Job lädt zusätzlich vor der Sichtbarkeit ein 90 Tage aufbewahrtes
`publication-evidence-*`-Artefakt mit Git-Tag, Commit, beiden endgültigen
Digestreferenzen, Paket-SHA-256 und eigener `SHA256SUMS` hoch. Schlägt bereits
dieser Evidence-Upload fehl, bleibt das Draft-Release unsichtbar.

Erst der von beiden Architekturen abhängige Release-Job:

1. erzeugt ein verstecktes Draft-Release,
2. lädt Archiv und äußere SHA-256-Datei hoch und wieder herunter,
3. prüft die äußere sowie alle inneren Paketprüfsummen,
4. promotet exakt die getesteten App-/Migrator-Digests ohne Rebuild per
   `imagetools create`,
5. vergleicht beide Finaltags erneut mit den Stage-Digests und
6. veröffentlicht das Draft-Release erst nach erneuter Asset-, Digest- und
   Attestationsprüfung.

Die beiden GHCR-Repositories und GitHub Releases besitzen keine gemeinsame
atomare Transaktion. Candidate-Tags bleiben deshalb als nicht-finale Evidence
erhalten. Vor Promotion versucht der Workflow ein selbst erzeugtes
fehlerhaftes Draft-Release zu entfernen; ab Promotionsbeginn bleibt ein
verstecktes Draft-Release absichtlich als Recovery-Anker bestehen, weil ein
oder beide Finaltags schon gesetzt sein können. Ein erfolgreicher
Veröffentlichungsaufruf kann außerdem trotz fehlgeschlagener Abschlussabfrage
bereits sichtbar sein. Diese
Zwischenstände dürfen nie installiert oder blind erneut überschrieben werden;
der kontrollierte Ablauf steht unter
[Unvollständigen Publish-Lauf behandeln](../deploy/registry/README.md#unvollständigen-publish-lauf-behandeln).

## Wegwerfbarer Integrations-Stack

Integrations- und HTTP-Tests verändern Daten. Sie gehören nicht auf die
Produktionsinstanz, sondern in ein eigenes Compose-Projekt mit eigenen
Volumes:

```console
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance up -d --build

ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance ps

curl --fail --silent --show-error \
  http://127.0.0.1:18080/health.php
```

Das Schema dieses Testprojekts wird so geprüft:

```console
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm migrate
```

Die Ausgabe muss `Post-migration schema verification passed` enthalten. Der
Runner prüft alle Ergebnisfelder und übergibt das Root-Secret nicht in der
Prozessargumentliste.

### One-shot-Migrator und bestehendes Volume

Der gezielte Test erzeugt in derselben MariaDB eine isolierte
Legacy-Testdatenbank. Zuerst muss ein doppelter Anhangname die
Runtime-Migration blockieren. Danach prüft der Test Bereinigung/Retry,
idempotenten Zweitlauf, Zero-Date-Konvertierung, Benutzer-/IP-/Kürzelbreiten,
Indizes, Datenwerterhalt und einen absichtlich manipulierten
Migration-Checksum. Zusätzlich muss eine bereits vorhandene, nicht im Ledger
erfasste Tabelle `nv_empfmtx_standard` die neue Migration blockieren, ohne
ihren Marker zu verändern; in einem kollisionsfreien Lauf werden alle 20
normalisierten Standardzellen exakt gegen
`tests/fixtures/recipient-matrix-standard.txt` verglichen:

```console
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --entrypoint /workspace/tests/integration/schema_migrator.sh \
  migrate
```

Die Fixture-Datenbank trägt einen pro Prozess abgegrenzten Namen und wird per
Trap wieder entfernt. Der Test setzt die Produktions-Schema-Verifikation nur
für diese unvollständige Vier-Tabellen-Fixture aus; Migration, Ledger und
Prüfsummenlogik laufen unverändert aus dem gebauten Migrationsimage.

### Datenbank-Integration der Datumsmigration

Der Test verwendet nur temporäre Tabellen, wendet die echte
Migrationsdatei zweimal an und prüft SQL-Mode, gültige Altwerte, Zero-Date-
Konvertierung und Warteschlangenprädikate:

```console
ESTAB_TEST_DB_PASSWORD="$(tr -d '\r\n' < secrets/db_password.txt)" \
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  --env ESTAB_TEST_DB_PASSWORD \
  --env ESTAB_TEST_DB_HOST=db \
  --entrypoint php \
  app tests/integration/date_compatibility.php
```

### Einsatzdomäne, Benutzerverwaltung und PDF-Dossier

`tests/integration/incident_domain.php` läuft gegen eine eigens angelegte und
vollständig migrierte Datenbank. Er startet nur mit
`ESTAB_INCIDENT_INTEGRATION=1` und einem Namen
`estab_incident_*test`. Dadurch können No-active-Trigger,
Parallelaktivierung, Update-/Delete-Sperren und ein harter
MariaDB-Lock-Timeout belegt werden, ohne den Hauptbestand umzuschalten.

Im Haupt-CI-Bestand prüft `tests/integration/user_admin.php` zunächst
Kontoanlage, serverseitig abgeleitete feste Funktionszuordnung,
Legacy-Reparatur, Neuzuweisung, Kontosperre, Entsperren, Kennwortreset,
Sitzungswiderruf, Login-Lock und Audit gegen die echte MariaDB und den
produktiven Legacy-Login. Der Test führt sowohl einen echten Bestandslogin als
auch die ausdrücklich aktivierte Self-Registration aus und beweist für beide
Auditzeilen die exakte `sha256:`-Referenz der rotierten Sitzung sowie die
Abwesenheit von Klartext-SID und Kennwort. Das funktioniert bewusst auch ohne
aktiven Einsatz.
`tests/integration/assignment_policy.php` verwendet mehrere Verbindungen für
den globalen Matrix-/Login-/Benutzerverwaltungs-Lock, gleicht geänderte Rollen
und entfernte Funktionen ab, erlaubt einem inaktiven Waisenkonto den
auditierten Kennwortreset, weist seine Anmeldung mit dem neuen Kennwort
weiterhin ab, erzwingt Rollback nach einem Auditfehler und prüft die
fail-closed Readiness eines ungültig aktiven Kontos. Danach aktiviert
`tests/integration/incident_ci_bootstrap.php` idempotent genau den Einsatz
`CI-INTEGRATION`.

Direkt danach legt `tests/integration/incident_export.php` einen separaten
Einsatz an, füllt in beiden Einsätzen je einen ETB-, TTB-, Nachrichten- und
Anhangdatensatz und macht den Export-Einsatz wieder historisch. Der
konsistente Read-only-Snapshot muss exakt dessen vier Datensätze liefern. Aus
der erzeugten PDF wird der tatsächliche `/EmbeddedFile`-Stream anhand seiner
deklarierten Länge extrahiert und bytegleich samt SHA-256 mit dem Original
verglichen; Marker und Datei des anderen Einsatzes dürfen nicht vorkommen.
Der Test stellt den benannten CI-Einsatz wieder aktiv, entfernt seine
operativen Fixtures und verweigert ohne
`ESTAB_INCIDENT_EXPORT_INTEGRATION=1` den Start.

Diese drei Tests sind bewusst in `tests/integration/ci.sh` orchestriert.
Insbesondere der PDF-Test ist kein sicherer Einzelbefehl gegen einen
Produktivbestand: Er verlangt den fest benannten aktiven CI-Einsatz und gehört
ausschließlich in ein wegwerfbares Projekt `estab_ci` beziehungsweise
`estab_ci_*`.

### Datenbank-Integration dynamischer Tabellen

Diese Regression erzeugt sechs typische Legacy-Tabellen für Benutzer,
Funktion und Kategorien als MyISAM/latin1, erhält repräsentative Daten und
Duplikate und ruft die echte Bestandsmigration zweimal auf. Anschließend prüft
sie InnoDB, `utf8mb4`, nullable Datumswerte, Indizes, Strict Mode,
Identifier-Angriffe und die vollständige Fixture-Bereinigung:

```console
ESTAB_TEST_DB_PASSWORD="$(tr -d '\r\n' < secrets/db_password.txt)" \
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  --env ESTAB_TEST_DB_PASSWORD \
  --env ESTAB_TEST_DB_HOST=db \
  --env ESTAB_TEST_DB_NAME=estab \
  --env ESTAB_TEST_DB_USER=estab \
  --entrypoint php \
  app tests/integration/dynamic_tables.php
```

Wurden `ESTAB_DB_NAME` oder `ESTAB_DB_USER` in `.env` geändert, müssen die
beiden `ESTAB_TEST_DB_*`-Werte im Befehl identisch angepasst werden. Der Test
verwendet fest abgegrenzte Tabellennamen und entfernt sie am Anfang, bei
Prozessende und nach erfolgreichem Lauf; er gehört trotzdem ausschließlich in
den Wegwerf-Stack.

### Datenbank-Integration der Anhangreservierung

`tests/integration/attachment_reservation.php` verwendet eine zufällig
benannte InnoDB-Wegwerftabelle und unabhängige MariaDB-Verbindungen. Der
Einzelaufruf entspricht dem Muster der übrigen PHP-Datenbanktests:

```console
ESTAB_TEST_DB_PASSWORD="$(tr -d '\r\n' < secrets/db_password.txt)" \
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  --env ESTAB_TEST_DB_PASSWORD \
  --env ESTAB_TEST_DB_HOST=db \
  --env ESTAB_TEST_DB_NAME=estab \
  --env ESTAB_TEST_DB_USER=estab \
  --entrypoint php \
  app -d auto_prepend_file= tests/integration/attachment_reservation.php
```

Der Test liefert drei voneinander getrennte Belege:

- Eine Verbindung sperrt die zweite von zwei Ergebniszeilen. Auf der anderen
  Verbindung beendet `execute()` erfolgreich, erst das echte
  `mysqli_stmt::get_result()` meldet Timeout 1205. Der Produktionshelfer muss
  genau diesen Code erhalten und darf `false` nicht als Resultat verwenden.
- Zwei echte Worker rufen gleichzeitig
  `estab_attachment_reserve()` auf. Der Lauf gilt nur dann als Deadlockbeleg,
  wenn der globale InnoDB-Zähler steigt **und** mindestens der betroffene
  Produktionsworker über den optionalen Beobachter einen ausgeführten Retry
  mit Code 1213 meldet. Beide Worker müssen anschließend erfolgreich
  unterschiedliche fortlaufende Namen besitzen.
- Besitzgrenzen, Claim, Finalisierung, Freigabe, Wiederverwendung,
  Idempotenz und der eindeutige Dateinamenindex werden danach auf derselben
  Produktionsschnittstelle geprüft.

Transaktionen, Session-Timeout, Worker, Barrieren, Zeilen und Fixture-Tabelle
werden auch im Fehlerfall bereinigt. Der Gesamtorchestrator begrenzt den
Einzeltest zusätzlich zeitlich.

### Datenbank-Integration der Nachrichten-Parallelität

`tests/integration/message_concurrency.php` erzeugt zufällig benannte
InnoDB-Wegwerftabellen und startet unabhängige PHP-Prozesse mit eigenen
MariaDB-Verbindungen:

```console
ESTAB_TEST_DB_PASSWORD="$(tr -d '\r\n' < secrets/db_password.txt)" \
ESTAB_HTTP_PORT=18080 \
podman compose -p estab-acceptance run --rm --no-deps -T \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  --env ESTAB_TEST_DB_PASSWORD \
  --env ESTAB_TEST_DB_HOST=db \
  --env ESTAB_TEST_DB_NAME=estab \
  --env ESTAB_TEST_DB_USER=estab \
  --entrypoint php \
  app tests/integration/message_concurrency.php
```

Der Test beweist für die implementierte Repository-Grenze:

- zwei gleichzeitige Writer auf einer leeren Tabelle erhalten verschiedene,
  fortlaufende Nachweisnummern,
- der administrative Zähler-Lock blockiert einen regulären Writer und beide
  verwenden damit denselben Namespace,
- ein fremdes A/W-Kürzel kann weder Sperre noch Save übernehmen und beim
  parallelen Save-/Reset-Rennen gewinnt genau eine konditionale Änderung,
- zwei parallele Read-State-Inserts erzeugen trotz fehlendem Schema-Unique-Key
  nur eine Zeile; Empfänger-Substring und fremde Funktion werden abgewiesen,
- idempotente Updates werden vom verlorenen beziehungsweise gelöschten Ziel
  unterschieden.

Der Nachweis endet bewusst an dieser Repository-Grenze. Er beweist keine
gemeinsame Transaktion zwischen jeder Nachrichtenmutation, dem separaten
Legacy-`protokolleintrag()` und einem nachgelagerten Read-/Done-State. Bei der
Abnahme sind deshalb sowohl fehlgeschlagene Anwendungsanforderungen als auch
korrespondierende `nv_protokoll`-Einträge zu kontrollieren.

Auch die statische Legacy-Entity-Prüfung kann ohne Encoding-Marker pro
Nachrichtenzeile nicht unterscheiden, ob beispielsweise `&amp;` ein alter
codierter Wert oder ein neu wörtlich eingegebenes Literal ist. Sie belegt die
sichere HTML-Ausgabe beider Speicherformen, nicht den verlustfreien
Roundtrip entity-förmiger Literale.

Die Fixture-Tabellen werden beim Prozessende entfernt. Das vollständige
CI-Gate führt diesen Test nach der parallelen Anhangreservierung und vor den
HTTP-Abläufen aus.

### HTTP-Smoke-Test

Der Test legt den deterministischen Funktionsbenutzer `e2e001` an, startet
eine zweite Sitzung zur Prüfung des gespeicherten Passwort-Hashes und kann
zusätzlich einen echten Admin-Export erzeugen:

```console
COMPOSE_PROJECT_NAME=estab-acceptance \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_ADMIN_USER=estab-admin \
ESTAB_TEST_ADMIN_PASSWORD="$(tr -d '\r\n' < secrets/admin_password.txt)" \
tests/integration/http_smoke.sh
```

`COMPOSE_PROJECT_NAME` muss auf den gestarteten, ausschließlich für die
Abnahme vorgesehenen Teststack zeigen. `ESTAB_TEST_COMPOSE_ENGINE` ist
verpflichtend; ohne die direkte MariaDB-Verbindung bricht der Test ab, damit
Kontenzahl, Passwort-Hash und Rollenbindung niemals nur vermeintlich geprüft
werden.

Der Einzelaufruf erwartet standardmäßig root-relative Browserlinks. Verwendet
das Test-Deployment eine absolute öffentliche URL oder einen Base-Path, wird
der vollständige erwartete Anwendungsroot zusätzlich angegeben, zum Beispiel
`ESTAB_TEST_EXPECTED_APP_ROOT=https://test.example/estab`. Alternativ können
`ESTAB_PUBLIC_URL` und `ESTAB_BASE_PATH` in dieselbe Shell exportiert werden.

Falls `ESTAB_ADMIN_USER` in `.env` geändert wurde, muss
`ESTAB_TEST_ADMIN_USER` denselben Wert erhalten. Der Test prüft unter anderem:

- Readiness und Security Header,
- Übersicht, BOS-Infos und historisches PDF-Handbuch,
- HTTP 403 für interne beziehungsweise abgeschaltete Provisionierungspfade,
- HTTP 410 für unsichere historische Direkt-Upload-Endpunkte,
- HTTP 401 für den anonymen Administrationszugriff,
- Image-Button-Login, Registrierung, authentifizierte Oberfläche und erneute
  Anmeldung über Kontenliste und gespeicherten Passwort-Hash,
- formulargebundene Beibehaltung eines erlaubten geschützten Zielbereichs
  durch Auswahl, Validierungsfehler und erfolgreiche Anmeldung,
- exakt eine escaped Sitzungsleiste mit Name, Kürzel, Funktion, Rolle und
  Abmeldebutton auf Root-Einstieg, Hauptansicht, vollhoher Anwendungs-Sidebar,
  direkten Status-/Zählerseiten, Nachrichtenübersicht, Nachweisung,
  Übungsleitung, Anhängen, Vordrucken, Kategorien sowie ETB/TBB; der rechte
  `mainframe` vermeidet Duplikate und anonyme Fachseiten bleiben ohne
  vorgetäuschte eStab-Identität,
- HTTP 405 für Logout per GET, HTTP 403 bei fehlendem oder falschem CSRF,
  Cookie-/Sitzungsende und 303-Rückleitung bei Erfolg sowie die
  SID-Grenze, durch die eine alte Sitzung eine neuere Anmeldung desselben
  Kontos nicht deaktiviert,
- sitzungsgebundene Voranmelde-CSRF-Tokens, HTTP 403 ohne Token im Browserflow
  und HTTP 403 für erkannte Cross-Site-Requests im explizit aktivierten
  Legacy-Kompatibilitätsmodus,
- direkte MariaDB-Nachweise, dass Fehlversuche kein Konto erzeugen und eine
  Neuanlage-Kollision den vorhandenen Passwort-Hash nicht verändert,
- unveränderte DB-Zuordnung beim blockierten Funktions-/Rollenwechsel für
  aktive Konten, erlaubte Neuzuordnung erst nach erfolgreichem Logout und
  blockierte Zweitanmeldung aus einer bereits authentifizierten Sitzung,
- Speichern und Suchen einer Nachricht mit Quotes, Ampersand, `<script>` und
  SQL-ähnlichem Text bei nachweislich inertem HTML sowie HTTP 403 für den
  historischen GET-Detailaufruf,
- Durchlaufen des echten A/W-Anhang-Uploads mit zuvor ausgefülltem
  Nachrichtenvordruck; die direkte Rückgabe nach der Anhangsauswahl muss
  Anschrift, sämtliche markanten Eingaben, Vermerk sowie blaue und grüne
  Empfängerzuordnung als weiterhin absendbare Formularwerte enthalten,
- Abschluss einer echten Gesprächsnotiz über den historischen Controller,
  anschließende Erzeugung durch den produktiven Vordruckgenerator, Auffinden in
  der geschützten Liste und Download mit PDF-Header/-Trailer, MIME-Headern und
  gespeichertem SHA-256 für den Restore-Vergleich,
- Basic-Auth-Adminseite mit escaped technischem Benutzernamen, ausdrücklicher
  Trennung vom eStab-Funktionskonto sowie Exportverwaltung: zwei vollständige
  Exporte erzeugen, PRG-Rückleitungen und CSRF-Grenzen prüfen, Manifest und
  jede CSV-Prüfsumme in beiden heruntergeladenen ZIPs verifizieren, den
  Workflowmarker per CSV-Parser exakt in `nv_nachrichten.csv` nachweisen,
  Traversal und unbekannte IDs abweisen, genau einen Lauf löschen und den
  zweiten byteidentisch für den Backup-/Restore-Nachweis behalten,
- Restore-Prüfmodus ohne Neuanlage: vorhandenes Konto und Nachricht erneut
  öffnen, exakten Anhanginhalt und PDF-SHA vergleichen, globalen Einsatzkopf
  sowie ETB-/TBB-Einträge nur lesen und Kennung, Manifest, Nachrichten-CSV
  und ZIP-SHA des überlebenden Exportlaufs im wiederhergestellten Volume
  prüfen.

### Direkte HTTP-Oberfläche

Der separate Oberflächentest verändert weder Datenbank noch Sitzung und kann
gegen jeden dafür vorgesehenen Test-Stack laufen:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
tests/integration/http_surface_http.sh
```

Auch dieser Einzelaufruf nimmt standardmäßig root-relative Browserlinks an.
Für eine abweichende öffentliche URL gilt derselbe
`ESTAB_TEST_EXPECTED_APP_ROOT`-Override wie beim HTTP-Smoke-Test.

Er weist nach:

- vollständige Erreichbarkeit des sichtbaren Root-Menüs, seiner lokalen
  Piktogramme, des aus `vorgaben` und `mainframe` bestehenden
  Nachrichtenvordruck-Arbeitsbereichs sowie aller verlinkten
  `stabinfo`-Frames, Inhaltsseiten und lokalen Bilder,
- HTTP 403 für dateisysteminterne Vierfach-Controller, den vollständigen
  `/4fbak/`-Baum, Spracharrays, alte Admin-Generatoren,
  `js_windowtwar.php` und direkte `*.inc.php`-Aufrufe,
- weiterhin HTTP 410 für die beiden absichtlichen Upload-Tombstones,
- HTML-Escaping sowie Array-/Parameterablehnung in `info.php`,
- HTTP 200, `image/png` und die acht Byte lange PNG-Signatur für
  repräsentative aktive Icon-, Push-, Menü- und Kategoriebuttons,
- HTTP 400 für entfernte Buttonarten, Arrays, unbekannte Farben sowie
  übergroße Schrift-, Breiten- und Textwerte,
- ausschließlich bekannte Hilfetextschlüssel und einen anonym neutralen
  Statusendpunkt ohne Rollenbelegung.

Der Test und der statische Vertrag stellen zusätzlich sicher, dass unter
`stabinfo/` keine `http://`-Fremdressource verblieben ist und alle zwölf
ersetzten Vergleichszeichen weiterhin semantisch ausgegeben werden.

`tests/php/http_surface_security.php` prüft dieselben Validator- und
Apache-Verträge ohne Webserver. Das vollständige CI-Gate führt zuerst diesen
read-only HTTP-Test und danach die zustandsverändernden Fachabläufe aus.

### Echter Browser-Akzeptanztest

`tests/browser/headless_ui.py` verwendet ausschließlich die
Python-Standardbibliothek und steuert Chrome oder Chromium direkt über das
Chrome DevTools Protocol. Das Testkonto muss vorher mit der in
`docs/BENUTZERVERWALTUNG.md` beschriebenen Administration angelegt und der
Funktion `S1` zugewiesen sein. Im CI übernimmt das der eng auf
`estab_ci`-Projekte begrenzte Provisionierer; öffentliche Selbstregistrierung
bleibt ausgeschaltet:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_LOGIN_NAME='Browser Test' \
ESTAB_TEST_LOGIN_CODE=brw001 \
ESTAB_TEST_LOGIN_FUNCTION=S1 \
python3 -B tests/browser/headless_ui.py
```

Ohne Kennwortvariable erzeugt der Test intern ein starkes ephemeres Kennwort
und gibt es weder in Logs noch Diagnosen aus. Das Kürzel muss bei manuellen
Wiederholungsläufen neu und höchstens sechs Zeichen lang sein.

Der Ablauf klickt die getrennten Wege der Übersicht, öffnet die Neuanlage im
Zwei-`iframe`-Nachrichtenarbeitsbereich, füllt das Formular aus und prüft
insbesondere:

- Anonyme Nutzer sehen alle acht Bereiche in stabiler Reihenfolge, genau eine
  aktive Markierung und sichere Anmeldeziele; direkte Zugriffe auf geschützte
  Endpunkte bleiben mit HTTP 403 geschützt.
- Der Arbeitsbereich enthält in stabiler Reihenfolge ausschließlich die
  vollhohe `vorgaben`-Sidebar und den `mainframe`. Der gemeinsame Refresh
  verwendet die richtige Origin und keinen schema-relativen Doppel-Slash. In
  der Sidebar ist genau eine sichtbare Sitzungsidentität mit Name, Kürzel,
  Funktion, Rolle und Logout vorhanden; der rechte Inhaltsframe dupliziert sie
  nicht.
- Statuskarte, Identität, Navigation und Aktionen folgen ohne Überlappung
  aufeinander. Arbeitszähler, Serverzeit, Onlinebelegung und die aktuelle
  Funktion sind vorhanden; die rollenabhängigen Fachaktionen sind echte,
  mindestens 44 Pixel große Textbuttons. Ein positiver Arbeitszähler besitzt
  eine dauerhaft sichtbare Warnstufe.
- Alle zehn Bereichs- und Dienstlinks sind ohne Disclosure dauerhaft sichtbar,
  mindestens 44 Pixel groß und besitzen weder eine eigene horizontale noch
  vertikale Scrollfläche. Bei `1440 × 1000`, `1280 × 720` und `700 × 760`
  CSS-Pixeln ist das Sidebar-Dokument die einzige vertikale Scrollfläche und
  bleibt horizontal innerhalb seiner Breite.
- Ein echter Statusfragment-Refresh ersetzt genau eine Statuskarte und bewahrt
  sowohl die Scrollposition des Sidebar-Dokuments als auch den Tastaturfokus
  auf einem externen Aktionsbutton und auf dem ersetzten Hinweiston-Schalter.
  Ein simulierter HTTP-503-Abruf markiert die weiter bedienbare Karte als nicht
  aktuell; der folgende erfolgreiche Abruf meldet die Erholung. Statisch sind
  zusätzlich der begrenzte `AbortController`-Timeout sowie serverseitige
  `partial`-/`unavailable`-Zustände abgesichert.
  Der Link „Übersicht“ verlässt den Zwei-`iframe`-Arbeitsbereich weiterhin im
  Top-Level-Kontext.
- Die eingebundene Audioquelle ist gleich-originig, besitzt einen
  RIFF-/WAVE-Container und meldet PCM als Format. Der Hinweiston-Schalter
  startet deaktiviert, zeigt stets eine sichtbare Rückmeldung und fordert erst
  nach einem echten Klick die Wiedergabe an. Der Lauf simuliert eine
  `NotAllowedError`-Blockade, prüft den ehrlichen Zustand nach Reload,
  synchronisiert Ein/Aus über ein `StorageEvent` und injiziert einen echten
  Statusrefresh mit Auslösemarker. Zwei verzögert aufgelöste Wiedergaben werden
  währenddessen per erneutem Klick beziehungsweise `StorageEvent` abgebrochen
  und dürfen den ausgeschalteten Zustand nicht zurückdrehen. Dabei bleibt
  dasselbe langlebige Audioelement erhalten. Der Test ersetzt
  `HTMLMediaElement.play()` kontrolliert durch Fehler, auflösbare Promises
  beziehungsweise einen Zähler und belegt damit den Wiedergabeaufruf, nicht
  physische Hörbarkeit.
- Ein geändertes Nachrichtenfeld löst beim globalen Bereichswechsel den
  nativen Bestätigungsdialog aus: Ablehnen bewahrt Seite und Wert,
  anschließendes Bestätigen öffnet die Übersicht.
- Im Matrixeditor lösen „Ungespeicherte Eingaben verwerfen und Standard laden“
  und „Aktive Matrix speichern und bisherigen Standard ersetzen“ jeweils
  einen eigenen nativen Bestätigungsdialog aus. Der Browser lehnt beide
  Dialoge ab und weist nach, dass Testwert und Matrixseite erhalten bleiben.
- Reale Klicks auf die BOS-Karte und eine statische BOS-Unterseite behalten
  den separaten kompakten Disclosure-Modus; das reale Öffnen seiner
  Bereichsauswahl und der Rückweg zur Übersicht funktionieren.
- Reale Klicks auf die ETB-Karte öffnen ohne neuen Tab den richtigen Pfad und
  markieren `incident-log` als aktiven Bereich.
- Ein echter Mausklick auf `Abmelden` im ETB beendet die Sitzung und stellt
  den öffentlichen Navigationszustand wieder her.
- Bei 1440, 1120, 800, 700, 672 und 390 CSS-Pixel Breite bleiben alle
  Kartenlinks vollständig innerhalb ihrer Karten, keine Klickfläche oder
  sichtbare Hovermarkierung überdeckt eine Nachbarkarte und echter Hover
  verändert keine Geometrie.
- Mit einem über das DevTools-Protokoll exakt auf 390 × 844 CSS-Pixel
  gesetzten Viewport bleiben zusätzlich Leiste, Kopf, Login-Karte und
  Einspalten-Karten innerhalb der Breite; Navigationslinks sind erreichbar und
  zentrale Login-Schaltflächen mindestens 44 Pixel hoch. Im authentifizierten
  Nachrichtenarbeitsbereich stehen Sidebar und Inhalt als zwei volle
  Viewport-Zeilen untereinander. Eine echte Rollenaktion scrollt vollständig
  zum geladenen Inhalt; der sichtbare, mindestens 44 × 44 Pixel große Button
  „Menü“ führt vollständig zur Sidebar zurück.

Soweit Chrome bereits steuerbar ist, legt der Test bei Fehlern `failure.png`
und ein kennwortfreies `state.json` an. Bei Browser-Startfehlern oder einem
harten CI-Timeout bleiben die normalen CI-/Compose-Logs. Im CI-Lauf landen
vorhandene Browserdiagnosen unter dem bestehenden Diagnoseverzeichnis und
werden vom Fehler-Artefakt hochgeladen. Alle Variablen und die Browsererkennung
sind in
[`tests/browser/README.md`](../tests/browser/README.md) dokumentiert.

### ETB-/TBB-HTTP-Integration

Der getrennte, idempotente Logbuchtest registriert je eine S2- und
A/W-Funktionssitzung und verwendet bei einem Wiederholungslauf dieselben
Testkennungen:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
tests/integration/logbooks_http.sh
```

Er weist nach, dass anonyme Zugriffe HTTP 403 erhalten, während jeder gültig
angemeldete Benutzer ETB und TBB lesen kann. Beide Bücher zeigen den globalen
Einsatzkopf und besitzen kein lokales Titelformular mehr. Die jeweils
fachfremde Sitzung erhält HTTP 200 samt gespeichertem Inhalt, aber kein
Eintragsformular. Cross-Rollen-POSTs liefern HTTP 403. Nur die aktuell als
Rotkopie markierte Funktion (Fresh-Standard: S2) darf ETB-Daten und nur A/W
mit der Rolle Fernmelder TBB-Daten über POST und Session-CSRF-Token schreiben.
Zusätzlich prüft der Test serverseitige Längengrenzen, inerte historische
GET-Schreibparameter und HTML-Escaping.

### Kategorien-HTTP-Integration

Der Kategorien-Test baut auf der S1-Nachricht des HTTP-Smoke-Tests und dem
S2-Benutzer des Logbuchtests auf. Zusätzlich registriert er eine isolierte
`Si`-Sitzung. Weil er echte Kategorien, Links und eine absichtlich fremde
Nachrichten-Fixture erzeugt, startet er nur mit Sicherheitsvariable und einem
Compose-Projektnamen `estab_ci` beziehungsweise `estab_ci_*`:

```console
COMPOSE_PROJECT_NAME=estab_ci_category \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_WORKFLOW_MARKER=MARKER_AUS_HTTP_SMOKE \
ESTAB_CATEGORY_HTTP_TEST_ALLOW_MUTATION=true \
tests/integration/categories_http.sh
```

Nachgewiesen werden HTTP 403 ohne Sitzung, positive IDs, inerte
GET-Schreibparameter, Session-CSRF und HTTP-303-PRG. S1 darf Funktions- und
Benutzerkategorien seines eigenen Tabellenraums verwalten, aber weder
Master-Kategorien ändern noch eine Master-Zuordnung einschleusen. S2/Rotkopie
und `Si` erreichen die Masterverwaltung. CRUD, Zuordnung und Linkbereinigung
werden direkt in MariaDB geprüft. Eine existierende Nachricht ohne exakten
S1-Empfänger-Token liefert beim Zuordnungsversuch HTTP 403 und bleibt
unverändert. Kategorien mit SQL-ähnlichen Quotes sowie Beschreibungen mit
Quotes, `&` und `<script>` werden bytegenau roh gespeichert, im HTML jedoch
escaped und ohne ausführbares Script ausgegeben. Der Listenfilter wird mit der
positiven Kategorie-ID erfolgreich ausgeführt und beim Löschen dieser Kategorie
aus der Sitzung entfernt; der SQL-artige Name als Filterparameter wird mit
HTTP 403 abgewiesen.

Vor den Kategorieänderungen betätigt derselbe Test außerdem die tatsächlich
gerenderten Nachrichtenaktionen für „gelesen“ und „erledigt“. Fehlendes CSRF
und eine fremde Nachrichten-ID liefern HTTP 403 und verändern keine
Statustabelle. Wiederholtes Setzen beziehungsweise Entfernen bleibt
idempotent; die sichtbaren Gegenaktionen wechseln passend mit. Eine erledigte
Nachricht verschwindet aus der Standardliste, erscheint mit eingeschaltetem
Erledigt-Filter wieder und wird am Ende auf ihren ursprünglichen Zustand
zurückgesetzt.

Ein Trap entfernt sämtliche Testkategorien, Links, die fremde Nachricht und
das isolierte Si-Konto samt persönlicher Tabellen. Die CI führt diesen Test
nach HTTP-Smoke und ETB/TBB, aber vor Admin-Workflow und Backup-/Restore aus.

### Nachrichtenrollen-HTTP-Integration

`tests/integration/message_workflow_http.sh` erzeugt echte Konten, Nachrichten,
dynamische Statustabellen und Vordrucke. Der Test startet deshalb nur mit
`ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION=true` und einem Compose-Projekt
namens `estab_ci` oder `estab_ci_*`. Das Projekt muss wegwerfbar,
selbstregistrierungsfähig und mit der historischen Standardmatrix initialisiert
sein.

Ein einzelner Lauf gegen den Standardmodus kann beispielsweise so gestartet
werden:

```console
COMPOSE_PROJECT_NAME=estab_ci_message \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_WORKFLOW_MARKER=MANUELLER_E2E_MARKER \
ESTAB_TEST_ROLE_PASSWORD_FILE=/absoluter/pfad/role_password.txt \
ESTAB_TEST_WORKFLOW_VARIANT=default \
ESTAB_TEST_EXPECT_OUTGOING_REVIEW=disabled \
ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION=true \
tests/integration/message_workflow_http.sh
```

Für den zweiten Modus erstellt die CI ausschließlich den App-Dienst mit
`ESTAB_REVIEW_OUTGOING_MESSAGES=true` neu und startet denselben Test mit einer
neuen Variantenkennung sowie `ESTAB_TEST_EXPECT_OUTGOING_REVIEW=enabled`.
Dadurch sind beide Konfigurationen getrennt belegt:

- Eingang: A/W legt Status 4 an; Si sichtet auf Status 8.
- Ausgang im Standardmodus `false`: S1 legt Status 2 an; A/W transportiert
  direkt auf Status 8.
- Ausgang im optionalen Modus `true`: S1 legt Status 2 an; A/W transportiert
  auf Status 4; Si sichtet anschließend auf Status 8.

Der Lauf registriert isolierte A/W-, Si-, S1-, S2-, S3- und POL/FB-Konten über
die öffentliche Kontooberfläche. Er prüft die gerenderten Listen und Aktionen,
fehlende CSRF-Tokens mit HTTP 403, die erlaubten Stabsaktionen sowie verbotene
Fernmelde-/Si-Aktionen des echten FB-Profils, A/W-Sperrbesitz, Status und
Abschluss direkt in MariaDB, den erzeugten Ein-/Ausgangsvordruck sowie die
exakten Empfängerfarben `S2_rt`, `S1_gn` und `S3_bl`.

Für die reale Autosichtung markiert der Test POL/FB ausschließlich in seiner
wegwerfbaren Matrix als Ziel und schaltet das einzige isolierte Si-Konto
offline. Das vom echten Eingangsformular automatisch ausgewählte Kontrollfeld
wird unverändert abgesendet; Status 8, Quittierung, POL-Empfänger, Vordruck und
sichtbare FB-Liste müssen daraus tatsächlich entstehen. Matrix und Si-Zustand
werden noch im Lauf zurückgesetzt und im Cleanup erneut abgesichert.

Danach öffnen A/W und Si jeweils ihren eigenen gerenderten
FM-/SI-Admin-Zweitprüfpfad. Speichern und Abbrechen müssen vorhanden sein; nur
Quittierungszeichen, Empfängerfarben und Vermerk dürfen bearbeitbar sein. Der
ursprüngliche Quittierungszeitpunkt erscheint ausdrücklich schreibgeschützt.
Echte CSRF-geschützte Änderungen mit manipuliertem Zeitwert dürfen den
SHA-256-Fingerabdruck der Felder 1–14, den gespeicherten Zeitpunkt sowie
Transport-, Sperr- und Abschlussbelege nicht verändern. Auch der bereits
erzeugte PDF-Vordruck muss erhalten bleiben.

Abschließend liest POL/FB die zugestellte Eingangsnachricht und betätigt die
tatsächlich gerenderten Aktionen „Antworten“ und „Weiterleiten“. Beide
abgeleiteten Formulare werden gespeichert und müssen zwei getrennte
Status-2-Ausgänge mit korrektem Zitat, Ziel, Absender, Funktionskennung und
Empfängern erzeugen. Der persönliche Lesestatus wird gesetzt, während der
Fingerabdruck der Quellnachricht unverändert bleibt.

Vor der Bereinigung weist der Test nach, dass ein Vordruck mit derselben
Nachweisnummer nicht schon vor dem eigenen Abschluss existierte. Der Trap
entfernt daher nur nachweislich selbst erzeugte Vordrucke sowie die eigenen
Konten, dynamischen Tabellen, Nachrichten und Auditdaten. Er ist dennoch
ausdrücklich kein Test für Produktionsdaten.

### Admin-Workflow-HTTP-Integration

Der Test verändert aktive Empfängermatrix, persistente Standardmatrix,
Nachrichtenzähler, Grafikflags und Audit. Er verweigert deshalb ohne die
ausdrückliche Sicherheitsvariable den Start und darf ausschließlich gegen das
eigene Wegwerfprojekt laufen:

```console
COMPOSE_PROJECT_NAME=estab_ci_acceptance \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_ADMIN_USER=estab-admin \
ESTAB_TEST_ADMIN_PASSWORD_FILE=secrets/admin_password.txt \
ESTAB_ADMIN_HTTP_TEST_ALLOW_MUTATION=true \
tests/integration/admin_workflows_http.sh
```

Der Test verwendet das bereits im Datenbankcontainer gemountete Root-Secret
über eine private temporäre Clientdatei. Er prüft:

- HTTP 401 ohne Admin-Basic-Auth und HTTP 403 für `all_msg.php`, die
  gefährlichen Uploadhelfer sowie die direkt aufrufbaren Print-/FPDF-Bäume,
- inerte historische GET-Schreibparameter und HTTP 403 ohne Session-CSRF,
- vollständigen 5x4-Roundtrip für aktive und persistente Standardmatrix mit
  exakt einer belegten Rotkopie und gültigen Autosichtungsflags; die dabei
  nicht betroffenen Benutzerkonten bleiben bytegenau unverändert, während der
  separate Zuordnungsrichtlinientest betroffene Konten abdeckt,
- getrennte Semantik der drei Matrixaktionen: aktives Speichern lässt den
  Standard unverändert; Standard laden verändert weder Datenbank noch Audit;
  gemeinsames Speichern kopiert alle 20 Zellen samt Rotkopie und Autosichtung,
- atomaren Rollback beider Matrixtabellen und des Audits: ein temporärer
  `BEFORE INSERT`-Trigger erzwingt einen Fehler in der Standardtabelle,
  anschließend stimmen die exakten Vorher-/Nachher-Snapshots aller Spalten
  beider Tabellen und die Auditanzahl überein,
- zwei parallele, getrennte Admin-Sitzungen: genau eine darf denselben
  Nachrichtenzähler erhöhen; die andere erhält HTTP 409,
- Systemnachricht, Audit und POST-only-PDF-Vordruckreset.

Ein Trap entfernt den temporären Fehlertrigger, stellt beide ursprünglichen
Matrizen, alle vorherigen Grafikflags und die Auto-Inkremente wieder her und
entfernt synthetische Nachrichten/Audits. Das ist eine zusätzliche
Schutzschicht, keine Freigabe für den Lauf gegen Produktionsdaten. In der CI
läuft dieser Test nach HTTP-Smoke, ETB/TBB, Kategorien- und
Nachrichtenrollentest, aber vor dem eigenständigen
Backup-/Restore-Roundtrip.

Nach abgeschlossener Abnahme wird ausschließlich das explizite Testprojekt
mitsamt seinen Testvolumes entfernt:

```console
ESTAB_HTTP_PORT=18080 \
podman compose -p estab_ci_acceptance down --volumes
```

Vor diesem destruktiven Befehl muss der Projektname `estab_ci_acceptance` sichtbar
im Kommando stehen.

## Fachliche Abnahme

Der automatisierte Browser-Akzeptanztest belegt die technische Bedienmechanik
von Menü, Zwei-`iframe`-Arbeitsbereich, Sidebar, Sitzung und Logout. Er ersetzt
nicht die nachfolgende fachliche Abnahme mit der organisationsspezifischen
Empfängermatrix. Als fachliche Referenz dient das
[historische Anwendungshandbuch](../doku/Handbuch_eStab.pdf); seine alten
Installations- und Sicherheitskapitel gelten nicht.

Insbesondere beweist die Automation keine physische Hörbarkeit. Sie prüft
PCM-WAV-Daten, Opt-in-Zustand, sichtbaren Rückfall und den angeforderten
Wiedergabeaufruf; Lautsprecher, Lautstärke, Betriebssystem-, Browser- und
Geräteeinstellungen müssen manuell abgenommen werden.

Mindestens zu prüfen:

- zwei Testeinsätze nacheinander aktivieren, die globale Statusanzeige auf
  allen Modulen kontrollieren und nach Deaktivierung die rote
  No-active-Warnung sowie gesperrte operative Formulare prüfen,
- neuen Benutzer für jede tatsächlich verwendete Funktion in der
  Benutzerverwaltung anlegen, abmelden und mit demselben Kennwort erneut
  anmelden; eine abweichende Funktion vor und nach dem Logout abweisen,
- ein Funktionskonto in der Administration sperren, die alte Sitzung und
  Neuanmeldung abweisen, anschließend entsperren und ein Kennwort
  zurücksetzen; altes Kennwort ablehnen und neues akzeptieren,
- Berechtigungs-/Rollenzuordnung aus der Empfängermatrix kontrollieren,
- eingehende und ausgehende Nachricht mit Richtung, Gegenstelle,
  Prioritätsstufe, Empfängern und Inhalt erfassen,
- Weiterleitung, Sichtung, Quittierung, Statuswechsel und Listenfilter über
  zwei unterschiedliche Funktionssitzungen nachvollziehen,
- für Fernmelder, Si und mindestens eine Stab-/FB-Sitzung den
  Hinweiston-Schalter im vorgesehenen Browser ausdrücklich aktivieren, den
  Testton tatsächlich anhören, nach der stillen ersten
  Warteschlangenmessung jeweils eine reale Erhöhung erzeugen und den passenden
  Hinweis genau einmal physisch hören; mit ausgeschaltetem oder browserseitig
  blockiertem Ton zusätzlich die sichtbare Rückmeldung kontrollieren,
- globale, funktionsbezogene und persönliche Kategorie anlegen, zuweisen,
  suchen und entfernen,
- zulässigen Anhang hochladen, Vorschau/Download prüfen und eine unzulässige
  Datei ablehnen lassen,
- Nachrichtenvordruck als PDF erzeugen und aus der geschützten
  Vordruckliste abrufen,
- für einen inzwischen historischen Einsatz ein PDF-Dossier mit ETB, TTB,
  Nachrichtenvordrucken und Originalanhängen erzeugen, die Anlagenansicht im
  vorgesehenen PDF-Programm öffnen und stichprobenartig eine eingebettete
  Datei samt dokumentierter SHA-256 gegen das Original prüfen,
- Einsatztagebuch und technisches Betriebsbuch mit der aktuell als Rotkopie
  markierten Funktion (Fresh-Standard: S2) sowie A/W in der Rolle Fernmelder
  beschreiben; anschließend beide Bücher mit der jeweils anderen angemeldeten
  Funktion vollständig, aber ohne Schreibformular lesen und
  Cross-Rollen-Schreibversuche mit HTTP 403 abweisen; Kommunikationsplan und
  alle lokal benötigten Zusatzmodule öffnen und je einen repräsentativen
  Datensatz anlegen/lesen,
- administrative Exportübersicht am Desktop und bei 390 Pixel Breite öffnen,
  Export erzeugen, ZIP herunterladen, `manifest.json`-Hashes gegen die
  CSV-Dateien prüfen und die zweistufige Löschbestätigung ohne horizontales
  Überlaufen bedienen; anschließend einen Lauf löschen und einen zweiten
  unverändert behalten,
- Vollbackup in ein leeres Testprojekt zurückspielen und denselben
  Nachrichten-/Anhangdatensatz, den erzeugten Vordruck, beide Bücher und den
  ausgewählten Exportlauf wiederfinden; für die betriebliche Freigabe muss
  diese Probe zusätzlich auf einem getrennten Host erfolgen.

Für jeden Abnahmelauf werden Commit, Image-Digest, Datenbankversion, Browser,
Zeitpunkt, Prüfer und Ergebnis protokolliert. Screenshots allein reichen nicht:
Die maschinenlesbaren Testausgaben und Backup-Prüfsummen gehören zum
Freigabenachweis.

## Multi-Arch- und Schwachstellen-Gate

Die Compose-Integration wird in GitHub Actions nativ auf
`ubuntu-24.04`/amd64 und `ubuntu-24.04-arm`/arm64 ausgeführt. Beide Läufe
umfassen Migration, HTTP-Rollenpfade, Parallelität, pull-only Deployment,
Bind-Mount- und Volume-Restore sowie vollständigen Cleanup. Der echte
Headless-Browser ist im amd64-Lauf verpflichtend; auf arm64 bleiben sämtliche
server- und containerseitigen Gates verpflichtend. Die zusätzliche manuelle
Browserabnahme auf dem tatsächlichen NAS ist davon unberührt.

Nach dem Containerlauf scannt der auf einen vollständigen Action-Commit und
Trivy 0.70.0 festgelegte Gate jeweils App, Migrator und das exakt gepinnte
MariaDB-Image. Jeder behebbare Befund der Stufe `HIGH` oder `CRITICAL` beendet
den Lauf. Unbehebbare Befunde werden angezeigt, blockieren aber erst, sobald
der Lieferant eine korrigierte Version bereitstellt. `.trivyignore.yaml`
enthält ausschließlich konkret begründete, pfadgebundene Ausnahmen mit
Ablaufdatum. Aktuell betrifft dies Standardbibliotheksbefunde im von der
offiziellen MariaDB-Entrypoint benötigten lokalen UID/GID-Helfer `gosu`; der
Migrator entfernt den dort unbenötigten Helfer vollständig. Nach Ablauf muss
die Ausnahme erneuert begründet oder durch ein aktualisiertes Basisimage
entfernt werden. Freie, globale oder unbefristete CVE-Ausnahmen sind kein
zulässiger Freigabenachweis.

## Readiness und Containerzustand

`/health.php` liefert HTTP 200 nur, wenn alle folgenden Prüfungen erfolgreich
sind:

- PHP ist mindestens Version 8.5,
- `gd`, `mbstring`, `mysqli`, `Zend OPcache` und `zip` sind geladen,
- DB-Name und -Port, Uploadgrenze, öffentliche URL, Basispfad, boolesche
  Schalter sowie eine gegebenenfalls aktivierte Proxy-Allowlist sind
  syntaktisch und in ihren erlaubten Wertebereichen gültig,
- Datenbankverbindung und `SELECT 1` funktionieren,
- 18 Runtime-Tabellen vorhanden sind: die 14 Basistabellen, die persistente
  Standardmatrix und die drei Tabellen der globalen Einsatzdomäne,
- aktive und Standardmatrix jeweils genau 20 eindeutige 5x4-Positionen,
  genau ein belegtes Stab-/FB-Rotkopieziel und keine Autosichtung auf leeren
  oder reinen Textzellen enthalten,
- die exakt definierten Benutzer- und Anhangindizes vorhanden sind,
- alle fünf versionierten Migrationen mit gültigem SHA-256 als angewendet
  protokolliert sind,
- der Singleton des globalen Einsatzstatus, alle Einsatz-Fremdschlüssel und
  -Trigger sowie die dauerhafte Kontosperr-Spalte kanonisch vorhanden sind,
- Benutzer-, IP-, Anhang- und alle sechs Nachrichten-Kürzelfelder die
  erforderlichen Breiten besitzen,
- Anhang-, Vordruck- und Exportverzeichnis beschreibbar sind.

`docker/db/verify.sql` löst den aggregierten Schemacheck in 25 benannte
`*_ok`-Ergebnisfelder auf. Für einen gültigen Stand müssen alle den Wert `1`
haben; die anschließende Abfrage nach abweichender Engine oder Collation darf
keine Zeile liefern.

Der Speichercheck legt kurzzeitig eine kleine Probe-Datei an und entfernt sie
wieder. Bei Konfigurations- oder Laufzeitfehlern liefert der Endpunkt HTTP 503
und nur boolesche Teilergebnisse, keine Zugangsdaten. Dieselbe
Konfigurationsprüfung läuft bereits im Container-Entrypoint; ein eindeutig
ungültiger Wert startet Apache daher gar nicht erst.

```console
curl --fail --silent --show-error \
  http://127.0.0.1:8080/health.php
podman compose ps
```

Die Compose-Healthchecks verwenden denselben App-Endpunkt beziehungsweise
MariaDBs `healthcheck.sh --connect --innodb_initialized`. Ein externer Monitor
soll auf HTTP-Status, JSON-Status, Container-Restarts und Antwortzeit
alarmieren.

`/health.php` ist bewusst knapp und ersetzt nicht `docker/db/verify.sql`.
Der ausführlichere `/4fadm/system_status.php` zeigt hinter Admin-Authentisierung
PHP-Module, Tabellenanzahl, beschreibbare Speicher und Konfigurationsstatus.
Beide Endpunkte rufen dieselbe zentrale Readiness-Prüfung auf. Der HTTP-Test
benennt die Standardmatrixtabelle kontrolliert um und beweist dabei gleichzeitig
HTTP 503 sowie „Prüfung erforderlich“; erst nach exakter Wiederherstellung
melden beide Ansichten wieder Bereitschaft. Eine leere oder nur lesbare
Datenbank kann dadurch in der Adminansicht nicht mehr fälschlich grün werden.

## Logs und Kapazität

```console
podman compose logs --since=10m migrate app db
podman compose logs --follow --tail=100 migrate app db

podman compose exec -T app \
  du -sh /var/www/html/4fdata /var/lib/estab/export
podman compose exec -T db du -sh /var/lib/mysql
```

Zu alarmieren sind mindestens:

- `unhealthy`, HTTP 503 oder wiederholte Container-Restarts,
- PHP `Fatal error`, `Uncaught`, Datenbank- oder Schreibfehler,
- fehlgeschlagene Login-/Export-/Upload-Abläufe in auffälliger Häufung,
- knapper Datenträger oder unerwartetes Wachstum,
- fehlendes beziehungsweise zu altes Backup,
- fehlgeschlagene Schema- oder Restore-Probe.

Container-Logs gehen standardmäßig an die Laufzeit. Rotation, zentrale
Sammlung und Aufbewahrung müssen dort konfiguriert werden; Logs können IP- und
einsatzbezogene Informationen enthalten.

## Prüfzeitpunkte

| Zeitpunkt | Mindestprüfung |
| --- | --- |
| jeder Commit | statische Suite |
| jeder Image-Build | erfolgreicher Build, Apache `configtest` und High-/Critical-CVE-Gate |
| wöchentlich | vollständiges natives CI-Gate und erneuter CVE-/Infrastrukturdrift-Check |
| CI/Freigabekandidat | vollständiges natives amd64-/arm64-Gate; echter Browser auf amd64 verpflichtend |
| Erstinstallation | Readiness, `verify.sql`, HTTP-Smoke, Browser-Akzeptanz und Fachabnahme |
| Upgrade/Migration | Restore-Kopie, SQL-Migrationen, alle Testebenen |
| laufender Betrieb | Readiness, Logs, Restarts, Speicher, Backup-Alter |
| regelmäßig | vollständige Restore-Probe und repräsentative Fachabnahme |

Apache kann im gestarteten Test-Stack separat geprüft werden:

```console
podman compose exec -T app apache2ctl configtest
```
