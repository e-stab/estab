# Tests, Funktionsnachweis und Monitoring

Die Funktionssicherung besteht aus mehreren Ebenen. Keine einzelne
Health-Antwort beweist die vollständige Fachfunktion.

Die Zuordnung jeder Funktion zu ihrem automatisierten und fachlichen Gate
steht in der [Funktionsmatrix](FUNKTIONSNACHWEIS.md).

| Ebene | Nachweis |
| --- | --- |
| Quellprüfung | netzloser Herkunftsnachweis für 13 Git-Ref-Snapshots (Trunk, vier Branches, sechs SVN-Tags, zwei SourceForge-Release-Tags) und einen separaten Dokument-r85-Baum, GitHub-Workflow-Prüfung mit festgelegtem Actionlint 1.7.12, PHP-8.5-Lint, Kompatibilitäts-, Sicherheits-, Einsatz-, Benutzerverwaltungs-, amtlicher Nachrichtenvordruck-, Upload-, Export- und PDF-Regressionen |
| Image-Build | benötigte PHP-Erweiterungen und Apache-Konfiguration |
| Datenbank | echtes MariaDB-Schema, Einsatz-Singleton/Trigger, Kontosperre, Indizes, aktive und persistente Standardmatrix, Engines, Collations und Zero-Date-Freiheit |
| HTTP | Header, direkte Endpunktfläche, 303-Weiterleitung anonymer geschützter Aufrufe zum allowlist-gebundenen Bestandslogin samt sichtbarem Rückweg, 403-/400-/405-Grenzen, Registrierung, sichtbare Sitzungsidentität, CSRF-Abmeldung, erneute Anmeldung, verbindlicher Eingangs- und Ausgangslauf samt Rückgabe/Korrektur und einsatzgebundener Feldvorschläge, Dienstbesetzung/Hutwechsel, S6-Plan, Melderlauf, Kategorien- und ETB-/TBB-Rollengrenzen, reale Vordruckerzeugung/-auslieferung sowie Admin-Export |
| Echter Browser | öffentliche Übersicht, getrennte Konto-Flows, direkte ETB-/Nachrichten-/Anhang-/Kategorie-Anmeldung ohne Sackgasse oder verschachtelten Arbeitsbereich, sicherer Login-Abbruch, neun stabile Navigationsbereiche, aktive Markierung, reale Karten- und Bereichswechsel im selben Tab, überlappungsfreie Karten-Klickflächen und echter Hover bei sechs Breiten, genau zwei Anwendungs-`iframe`-Elemente, vollhohe Sidebar ohne verschachtelte Scrollflächen bei 1440 × 1000, 1280 × 720 und 700 × 760 CSS-Pixeln, fokuserhaltender Statusfragment-Refresh samt sichtbarem Fehler- und Erholungspfad, dauerhafte Warnstufe bei offenen Meldungen, gleich-originiges PCM-WAV, ausdrücklicher Hinweiston-Schalter samt Blockade-/Reload-/Synchronisations-/Race-Pfad und automatischem Signal, langlebiges Audioelement, A/W-Rufnamen-Listbox mit echtem Fokus, Filterung und Tastaturauswahl, Matrixstandard-Bestätigungen, BOS-Disclosure, Logout sowie öffentliche und authentifizierte mobile Bedienung bei exakt 390 × 844 CSS-Pixeln |
| Fachabnahme | kompletter Nachrichten-, Anhang-, PDF-, ETB-/TBB- und Restore-Ablauf |
| Betrieb | kontinuierliche Readiness, Logs, Restarts, Kapazität und Backup-Alter |

## Statische Tests

Die Workflow-Prüfung läuft im CI vor den übrigen Quelltests mit dem
festgelegten Multi-Arch-Index-Digest des offiziellen Actionlint-Images. Sie
prüft beide Dateien unter `.github/workflows/` einschließlich
Ausdruckskontexten, Matrixwerten, Jobabhängigkeiten, Aktionsparametern und den
eingebetteten Shell-/Python-Blöcken. Lokal ist derselbe read-only Lauf:

```console
podman run --rm \
  --volume "$PWD:/repo:ro" \
  --workdir /repo \
  docker.io/rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667
```

Für Docker wird nur `podman` durch `docker` ersetzt. Der
`registry_deployment_contract.php` bindet Name, Digest, schreibgeschützten
Mount und Arbeitsverzeichnis zusätzlich statisch, damit die Prüfung nicht
unbemerkt aus dem CI entfernt oder auf einen veränderlichen Tag umgestellt
wird.

Mit lokalem PHP 8.5:

```console
tests/static/run.sh
```

Oder mit der festgelegten PHP-Version und ihrem Multi-Arch-Digest im
CLI-Image:

```console
podman run --rm \
  --user 65534:65534 \
  --volume "$PWD:/workspace:ro" \
  --workdir /workspace \
  php:8.5.8-cli-trixie@sha256:58b996c35ce0511cdbaa1fc0476a194fd0221097d721ff7df5af0b6f1a3d0202 \
  tests/static/run.sh
```

CI führt diesen Quelltest ebenfalls mit der festen unprivilegierten
UID/GID `65534:65534` und schreibgeschütztem Workspace aus. Das ist Teil des
Deployment-Nachweises: Die Registry-Tests müssen ihren privaten
Non-root-Laufzeitzustand tatsächlich unter einem isolierten
`XDG_STATE_HOME` anlegen, schützen, wiederverwenden und bereinigen können,
ohne Rootrechte oder Schreibzugriff auf den Checkout. Der für einen
administrativen Synology-/Docker-Aufruf vorgesehene Rootpfad
`/var/lib/estab-deploy` bleibt zusätzlich im statischen Vertrag gebunden; die
Tests schreiben dafür nicht in das `/var/lib` des Testcontainers.

Die Suite lintet alle aktiven PHP-Dateien und führt die Prüfungen unter
`tests/php/` aus. Dazu gehören unter anderem:

- die versiegelten, deterministischen Provenienzmanifeste für 13
  Git-Ref-Snapshots – Trunk, vier historische Branches, sechs SVN-Tags und
  zwei SourceForge-Release-Tags – sowie alle 95 Dateien des einen separaten
  Dokument-r85-Baums einschließlich UTF-8-/Rohpfad-, Größen-, Modus- und
  SHA-256-Prüfung; bei 0.9.26b/c müssen aufgezeichnete Archividentität,
  annotierter Tag und Snapshot-Commit übereinstimmen, während negative
  Ref-, Manifest-, Releaseidentitäts- und Dokumentmanipulationen erkannt
  werden,
- PHP-8.5-Laufzeit- und Legacy-Konstruktor-Kompatibilität,
- `NULL`-/Zero-Date-Behandlung,
- Anmelde-, administrative Kontoanlage-, unveränderliche Funktionsbindungs-,
  Session- und
  Passwortregeln sowie die fail-closed Proxy-Peer-Vertrauensgrenze mit
  IPv4-/IPv6-CIDR-Allowlist,
- fehlende Laufzeit- und Umgebungsoptionen für Autosichtung oder eine
  Umgehung der verpflichtenden Ausgangssichtung,
- kanonische Reihenfolge, sichere URL-Auflösung, aktive Route, ausschließlich
  erlaubte symbolische Anmeldeziele und das rollenabhängige Ausblenden der
  spezialisierten Meldungsübersicht beziehungsweise Nachweisung; von neun
  Bereichen und zwei Diensten bleiben vor Hutauswahl nur die vier
  öffentlichen beziehungsweise separat geschützten Ziele und der
  Führungsstellen-Bootstrap, danach je nach ausgewähltem Hut neun oder zehn
  Links sichtbar,
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
- vollständige RIFF/WAVE- und PCM-16-Prüfung aller drei
  Warteschlangensignale einschließlich festem SHA-256, Kanalzahl, Abtastrate,
  Framezahl, Dauer, Signalspitze und Mindest-RMS; ein beschädigter,
  ausgetauschter oder stummer Ton beendet die Quellprüfung,
- gemeinsames responsives Werkzeuggestell für die erreichbaren
  Administrations-, Kategorien-, ETB-/TBB-, Nachweis- und
  Meldungsübersichtsseiten einschließlich beschrifteter Felder,
  Tastaturfokus, responsiver Tabellenkarten und begrenzter Scrollflächen für
  unvermeidbar breite historische Fachformulare,
- gemeinsame skalierbare Nachrichtenlisten für Meldungsübersicht und zweite
  Sichtung: immer sichtbare Suche, kombinierbare Filter, einzeln entfernbare
  Filterchips, stabile Sortierung und Paginierung, eine Detailaktion je Zeile,
  Prepared Statements, SQL-seitige Sichtbarkeitsgrenze vor `COUNT`/`LIMIT`
  sowie exakte Volltext- und Einsatzindizes,
- rollenabhängige Zuordnung der drei Hinweistondateien, validierte
  gleich-originige WAV-URLs, die einmalige Initialisierung und Fortschreibung
  der `old_que_*`-Basiswerte, Auslösung ausschließlich bei einer späteren
  Erhöhung, ausdrückliche Browserfreigabe, langlebiges Audioelement und
  sichtbare Status-/Fehlerrückmeldung,
- Nachrichten-IDs, ausgewählte aktive Dienstbesetzung, Rollen-/Objektregeln,
  Empfänger-Tokens, eigene Verfasser- und Verarbeitungsmarken, erlaubte
  Workflow-Aktionen, POST-/CSRF-Verträge, Prepared Statements, sichere
  UTF-8-/Legacy-Entity-Ausgabe und die inerten Payloads Quotes, Ampersand,
  `<script>` sowie SQL-ähnlicher Text,
- verpflichtende Rufnamen bei FM-Eingängen, nicht leere LdF-Übersetzungen,
  explizite LdF-Bestätigung des von A/W aufgenommenen Eingangswegs,
  begründungspflichtige atomare Korrektur bei unveränderlicher A/W-Zeit und
  unveränderlichem A/W-Zeichen,
  rollen- und richtungsgebundene Fokus-Vorschläge für Rufname und Absender
  ausschließlich aus dem aktuell aktiven Einsatz, weiterhin mögliche freie
  Eingaben bei ausgeschalteter Browser-Autovervollständigung, exakte
  Sperrfreigabe beim Abbrechen und die anschließende Rückkehr in die
  automatisch aktualisierte LdF-Warteschlange,
- Medium-Normalisierung für `Fe`, `Fu`, `Me`, `FAX`/`Fax`, `FS`, `@` und
  `DFÜ`, lesbare sowie HTML-sichere Nachweisausgabe, die Zusammenführung von
  Medium und Freitextweg ohne Dopplung und den richtungsabhängigen
  Listenvertrag „Eingangsmedium“ beziehungsweise „Beförderungsweg“,
- erlaubte Bildbutton-Typen, Farb-/Größen-/Textgrenzen, öffentliche
  Endpunktverträge, vollständige Apache-Sperrlisten und eine CSP ohne
  `unsafe-eval`,
- CSRF-Token,
- Empfängermatrix- und Standardmatrix-Validierung mit jeweils 20 eindeutigen
  Positionen, genau S2/Stab als Rotkopie-/Dokumentationsziel und ausschließlich
  falschen Autosichtungswerten sowie getrennten Laden-/Speichern-Aktionen,
  globalem Zuordnungslock, atomarer Matrix-/Kontenabstimmung,
  Rollen-Synchronisierung, Sitzungswiderruf, reparierbarem Waisenstatus,
  Zwei-Tabellen-Transaktion, lokalem Bestätigungsvertrag und ohne generierte
  PHP-Konfiguration; außerdem Nachrichtenzähler- und
  PDF-Vordruckreset-Validierung samt Prepared-Statement-, Transaktions-,
  Auth-/CSRF- und PRG-Vertrag,
- Kategorien-Typen, positive IDs, ausgewählte aktive Dienstbesetzung,
  sessionabgeleitete Tabellenräume, Master-Rechte, doppelte Auswahllisten,
  HTML-Ausgabe sowie den Prepared-Statement-, Transaktions-,
  Nachrichten-Objektberechtigungs- und PRG-Vertrag,
- globalen Einsatz-Singleton, revisionsgesicherte Aktivierung,
  No-active-Eingabesperre, Einsatzzuordnung sämtlicher operativer Tabellen und
  einheitliche Statusbanner,
- Kontosperre und Kennwortreset mit gemeinsamem Login-Lock,
  Sitzungswiderruf, auditgebundenem Rollback und ohne Klartextweitergabe,
- Upload- und Anhangpfadvalidierung,
- selected-hat- und objektgebundene Dateiauslieferung samt vererbter
  Nachrichtenrechte, begrenzter Rechte für freie Anhänge, erneuter Prüfung bei
  Auswahl und finalem Nachrichtenspeichern sowie Traversal-, Symlink- und
  Header-Injection-Schutz,
- portabler Tabellenexport,
- Compose-Startgate, private MariaDB-Optionsdatei, Migration-Ledger,
  Prüfsummenbindung und Runtime-Schemavertrag,
- selbsttragende Fresh-Schema-Initialisierung, pull-only Registry-Compose,
  netzlose Ableitung des Admin-Hashes ohne Klartextsecret im Webcontainer,
  persistente Storage-/Secret-Grenzen, vor Pull/Start geprüfte getrennte
  Produktivquellen, guardierte echte Host-Bind-Mounts samt engine-weiter
  Wartungssperre, Backup-/Restore-Vertrag und vollständiger
  Cleanup-Postcondition, manuell
  durch Rechtefreigaben gesperrter GHCR-Workflow, native amd64-/arm64-Läufe,
  inhaltlich gelesene SPDX-SBOM/Build-Provenance, gegen Quellcommit und
  Digest verifizierte OCI-Attestation aus GHCR, fail-closed
  High-/Critical-CVE-Gate und
  prüfsummengebundenes Digest-Installationspaket,
- Erzeugung lesbarer Nachrichtenvordrucke und eines durchsuchbaren
  PDF-Einsatzdossiers mit allen neun wählbaren Abschnitten und sicher
  eingebetteten, gegen ihren Eingangsnachweis geprüften Dateien; beide
  Nachrichtenausgabepfade werden ein- und mehrseitig mit Poppler pixelgleich
  verglichen und auf stabilen Folgeseiteneinzug, sichtbare historische
  Empfänger, fehlenden VS-NfD-Aufdruck, fehlendes Wappen sowie A4-Geometrie
  geprüft; ein eigener Maximalwert-Fall bindet beide gekürzten Kopfzeilen an
  ihre tatsächlichen Poppler-Bounding-Boxes.

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

Der am 31. Juli 2026 tatsächlich vollständig beendete Abschlusslauf verwendete
das isolierte Compose-Projekt `estab_ci_official_form_release`, Port `18382`
für die App und Port `18383` für das Pull-only-Registry-Projekt:

```console
COMPOSE_PROJECT_NAME=estab_ci_official_form_release \
ESTAB_CONTAINER_CLI=podman \
ESTAB_HTTP_PORT=18382 \
ESTAB_REGISTRY_HTTP_PORT=18383 \
ESTAB_BROWSER_TEST=required \
bash tests/integration/ci.sh
```

Er führte damit das Pflicht-Browser-Gate aus und endete erst nach dem
vollständigen destruktiven Backup-/Restore-Roundtrip mit
`CI integration: OK`. Dieser lokale Podman-Lauf fand nicht auf einem
nachgewiesenen SELinux-Enforcing-System statt und gilt daher ausdrücklich nicht
als SELinux-Relabel-Nachweis.

Bei Podman liegen die kurzlebigen Verzeichnisse unterhalb des ausgecheckten
Repositorys, damit Podman Desktop den Hostpfad sicher in seine VM einhängen
kann. `.gitignore` und `.dockerignore` schließen sie aus; der Exit-Trap entfernt
sie auch nach einem Fehler. Docker verwendet standardmäßig `RUNNER_TEMP` oder
`/tmp`. Ein anderer bereits vorhandener, schreibbarer Pfad kann für beide
Engines explizit mit `ESTAB_CI_TEMP_PARENT` gesetzt werden.

Die statischen Verträge und `compose config` prüfen zusätzlich, dass Secret-,
Daten-, Export- und Auth-Mounts ihre erforderlichen `z`/`Z`-Optionen behalten
und dass Backup/Restore nur `--volumes-from <exakte-ID>:z` verwenden. Ein
Linux-Lauf ohne aktives SELinux beweist jedoch kein tatsächliches Relabel.
Vor der Freigabe für Fedora/RHEL muss daher einmal auf einem dedizierten
Rootless-Podman-System mit `getenforce` = `Enforcing` das vollständige Gate
einschließlich Backup und Restore ausgeführt werden. Ein Ergebnis auf
`Permissive` oder `Disabled` ist nur der allgemeine Podman-Nachweis und darf
nicht als SELinux-Nachweis dokumentiert werden.

Der normale lokale und Standard-CI-Lauf baut die festgelegten Images frisch
und startet sie zusätzlich in einem zweiten, pull-only
Registry-Compose-Projekt ohne Build oder Host-Schema-Mount. Dort müssen der
selbsttragende Migrator mit Exitcode 0 und die App gesund enden. Derselbe Test
startet danach ein weiteres Pull-only-
Projekt mit drei echten temporären Host-Bind-Mounts für MariaDB, `4fdata` und
Exporte. Container-Inspect bindet Typ, Quellpfad und Containerziel an den
erwarteten Zufallspfad. Ein Datenbankmarker und zwei Dateimarker werden mit
SHA-256 gesichert. Danach wird der Datenbankmarker logisch verfälscht und in
den beiden Dateibereichen werden veraltete Testdaten ergänzt; der rohe
MariaDB-Bind-Mount wird nicht geleert. Der produktive Restore-Helfer muss den
gebundenen Zustand wiederherstellen. Migrator, Readiness und alle drei Marker müssen danach
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
Für die Einsatzfolge prüft er zusätzlich, dass Migration 45 einen
unvollständigen operativen Bestand vor jeder Einsatz-DDL unverändert
blockiert, die automatischen Zeitstempel während des Legacy-Backfills
deaktiviert und Migration 55 ihre kanonischen Definitionen auch nach einem
Wiederanlauf exakt wiederherstellt. Die veröffentlichte Migration 50 wird
dabei mit ihrer unveränderten SHA-256-Prüfsumme gebunden.
Migration 97 wird als zwölfte Migration separat geprüft: Sie darf nur die
exakt markierte nullable Spalte `fuehrungsstellenname`, ihren nicht-nullbaren
Sperrmarker und die eigenen DB-Schreibgrenzen ergänzen, muss historische
Namen unverändert `NULL` lassen und fremde gleichnamige Spalten, Routinen oder
Trigger fail-closed abweisen. Readiness und `verify.sql` verlangen danach die
exakten Spaltenpositionen/-attribute, gültige kanonische Werte, Marker,
Schreibgrenzen sowie die amtlichen Nachrichtenvordruckfelder aus Migration 98.
Der Schema-Test startet Migration 98 zweimal, prüft die exakt markierten
Spalten `11_rufnummer` und `12_betreff`, deren leere Bestandswerte und den
unveränderten historischen Nachrichteninhalt. Readiness und `verify.sql`
verlangen alle vierzehn Ledgerzeilen einschließlich Version 99 sowie die
exakten drei Such-/Listenindizes. Migration 99 wird vollständig, nach einem
simulierten phasenweisen Abbruch und nach einer fremden Indexkollision
ausgeführt; erst der bereinigte Wiederanlauf darf den Ledgerstand schreiben.
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
PDF-Testeinsatz. Dessen ETB, TBB, Nachricht und Anhang mit Eingangsnachweis müssen aus
einem konsistenten Read-only-Snapshot exportiert werden, während gleichartige
Daten des aktiven CI-Einsatzes ausgeschlossen bleiben. Der Test extrahiert
den realen `/EmbeddedFile`-Stream und vergleicht Bytes und SHA-256 mit dem
Original, bevor er Testdaten und aktiven Einsatz wiederherstellt.
Der vollständige Nachrichtenrollenlauf prüft genau den verbindlichen
DV-Statuspfad. Eine Laufzeitumschaltung oder Autosichtung existiert nicht;
damit kann die CI keine fachlich unzulässige Kurzstrecke versehentlich
freigeben.
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
erreichbaren Führungsstellen-Bootstrap. Der Login wählt ausdrücklich keine
Dienstfunktion: Ein Zugriff auf die privilegierte Vordruckliste bleibt bis zur
persönlichen Hutauswahl mit HTTP 403 geschlossen. Der Lauf endet über den
normalen CSRF-geschützten Logout. Anschließend stellt die CI den sicheren
Standard `false` wieder her, bevor Fachtests weiterlaufen.
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

## Publish-Gate für exakt gebaute Digest-Images

Der manuelle Publish-Workflow baut App und Migrator nicht erneut zwischen Test
und Veröffentlichung. Nach den Rechte-, Tag-, Ruleset- und Environment-Gates
baut und pusht er jeden Dockerfile genau einmal als Multi-Arch-Index mit
`push-by-digest=true`; es wird kein OCI-Tag erzeugt. Die zurückgegebenen beiden
Index-Digests werden als Stage-Outputs an die nachfolgenden Jobs gebunden.

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
Architektur oder schon der Build, entsteht kein GitHub-Release; OCI-Tags
entstehen in keinem Pfad.

Der Release-Job lädt zusätzlich vor der Sichtbarkeit ein 90 Tage aufbewahrtes
`publication-evidence-*`-Artefakt mit Git-Tag, Commit, beiden endgültigen
Digestreferenzen, Paket-SHA-256 und eigener `SHA256SUMS` hoch. Schlägt bereits
dieser Evidence-Upload fehl, bleibt das Draft-Release unsichtbar.

Erst der von beiden Architekturen abhängige Release-Job:

1. verlangt aktivierte Immutable Releases, ein aktives tagweites Ruleset ohne
   Bypass sowie den unveränderten Remote-Git-Tag auf `GITHUB_SHA`,
2. erzeugt ein verstecktes Draft-Release,
3. lädt Installations- und Evidence-Archiv samt äußerer SHA-256-Datei hoch und
   wieder herunter,
4. prüft die äußeren sowie alle inneren Paketprüfsummen,
5. prüft beide digestgenauen GHCR-Referenzen erneut,
6. veröffentlicht das Draft-Release erst nach erneuter Policy-, Asset-,
   Digest- und Attestationsprüfung und
7. verlangt danach mit begrenzten Wiederholungen `isImmutable=true`, exakt
   vier Assets, erfolgreiche `gh release verify`- und
   `gh release verify-asset`-Prüfungen sowie erneut Tag-Ruleset und
   Remote-Tag-Commit.

Die Digest-Pushes und GitHub Releases besitzen keine gemeinsame atomare
Transaktion. Ein fehlgeschlagener Lauf kann unbenannte Digestobjekte, aber
keine Candidate- oder Finaltags hinterlassen. Vor Sichtbarkeit versucht der
Workflow nach jedem späteren Jobfehler, ausschließlich das anhand seiner
numerischen Release-ID und vollständigen Metadaten wiedererkannte eigene
Draft-Release zu entfernen. Die Fehlerbereinigung verweigert sichtbare,
unveränderliche oder fremd veränderte Releases. Ein erfolgreicher
Veröffentlichungsaufruf kann trotz fehlgeschlagener Abschlussabfrage bereits
sichtbar sein. Diese Zwischenstände dürfen nie installiert oder blind erneut
bearbeitet werden;
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

Die Einsatzverträge decken zusätzlich den Führungsstellennamen ab: Ein neuer
Einsatz benötigt ihn, ein migrierter `NULL`-Wert muss vor Aktivierung oder
weiteren operativen Eingaben einmalig administrativ bestätigt werden, und ein
schon belegter Wert wird mit dem ersten operativen Datensatz durch einen
dauerhaften Marker unveränderlich. Direkte Legacy-Inserts ohne Namen,
Direktänderungen, Rand-Leerraum, reine Unicode-Leerraumwerte und
Insert→Delete→Umbenennen müssen scheitern.
Die Nachrichtengrenze muss die lokale Eingangsanschrift beziehungsweise
Ausgangs-Absendereinheit aus dem im selben Writer gesperrten Einsatz binden
und manipulierte Browser- oder Umgebungswerte ignorieren. Statusanzeige,
ETB/TBB, Nachweisung, Exportauswahl und PDF-Nachweis werden auf denselben
einsatzbezogenen Wert geprüft. Bei historischem `NULL` melden Statusleiste und
Administration den fehlenden beziehungsweise unvollständigen Zustand; das
historische PDF sagt „historisch nicht erfasst“ und der CSV-Export bewahrt
`NULL`.

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
Einsatz an, füllt in beiden Einsätzen je einen ETB-, TBB-, Nachrichten- und
Anhangdatensatz und macht den Export-Einsatz wieder historisch. Der
konsistente Read-only-Snapshot muss exakt dessen vier Datensätze liefern. Aus
der erzeugten PDF wird der tatsächliche `/EmbeddedFile`-Stream anhand seiner
deklarierten Länge extrahiert und bytegleich samt SHA-256 mit der beim Eingang
gebundenen Fixture verglichen; Marker und Datei des anderen Einsatzes dürfen
nicht vorkommen. Anschließend ersetzt der Test die Datei durch gleich viele
andere Bytes. Sowohl ein erneutes Laden als auch das Einbetten aus dem zuvor
geladenen Bundle müssen wegen des unveränderlichen SHA-256-/Größennachweises
scheitern. Derselbe Bytewechsel muss im produktiven Abschluss-Preflight als
Integritätsfehler erscheinen; auch der tatsächliche Aufruf zum formalen
Abschluss wird mit genau diesem Blocker zurückgewiesen.
Der Test stellt den benannten CI-Einsatz wieder aktiv, lässt den isolierten
historischen Nachweis für nachfolgende Export- und Restore-Prüfungen bestehen
und verweigert ohne
`ESTAB_INCIDENT_EXPORT_INTEGRATION=1` den Start.

Der authentifizierte HTTP-Lauf prüft den Anhangdownload zusätzlich an seiner
wirklichen Dateigrenze. Nach einem erfolgreichen Download mit
Integritätsstatus und SHA-256-Header ersetzt er ein Byte, ohne die Dateigröße
zu ändern. Download und Bildvorschau müssen vor `Content-Disposition`,
`image/png` und vor Nutzbytes mit HTTP 409 abbrechen. Eine private
Fixture-Sicherung wird auch bei vorzeitigem Testabbruch restauriert; erst der
danach wieder bytegleiche Download darf erneut HTTP 200 liefern. Später
verändert derselbe Lauf den Pflichtanhang nochmals unmittelbar vor dem echten
Admin-POST zum Schließen der letzten aktiven Dienstschicht. Die Antwort muss
HTTP 409 und genau den Anhang-Integritätsblocker enthalten; ein exakter
Vorher-/Nachher-Snapshot aus Schichtstatus, Schichtendzeit sowie ID, Status und
Ablösezeit jeder Funktionsbesetzung muss bytegleich bleiben.

Der davon unabhängige Rendervertrag erzeugt mit
`tests/php/pdf_template_render_fixture.php` aus exakt demselben
Nachrichtendatensatz und derselben 5×4-Empfängermatrix einen Einzelvordruck,
eine direkte Dossier-Nachrichtenseite, beide Varianten mit mehrseitigem Inhalt
und ein vollständiges Dossier mit den neun Abschnitten ETB, TBB,
Nachrichtenvordrucke, Anhänge, Nachrichtenereignisse, Dienstbetrieb,
S6-Fernmeldepläne, Melderläufe und Betriebsereignisse.
`tests/static/pdf_render.sh` verlangt A4 und mindestens zehn Seiten, extrahiert
Formulartext und Bounding Boxes, weist null Seitenbilder nach, prüft die
Überschriften und Nachweisstatus aller Abschnitte, den konstanten linken
Inhaltseinzug und rendert alle Varianten mit 144 dpi. Ein- und mehrseitige
Direktvarianten werden vollständig bytegenau verglichen; bei der
Nachrichtenseite im vollständigen Dossier bleibt nur der absichtlich
dossierglobale dynamische Seitenzähler ausgespart. Anschließend extrahiert
`pdfdetach` den eingebetteten, am Eingang gebundenen Anhang und `cmp` vergleicht
ihn mit der Quelldatei. Anlagenverzeichnis und Deckblatt müssen zusätzlich den
Integritätsstatus nennen; bei Legacy lautet er ausdrücklich
„Integrität beim Eingang nicht belegbar“. Die CI bewahrt PDFs, PNGs, Text- und
Werkzeuginformationen 14 Tage
als Nachweisartefakt auf.

Die Fixture ruft für den Einzelvordruck denselben produktiven
`render_message_form_document()`-Dienst auf wie der aktuelle Download unter
`/4fach/vordrucke.php`; der produktive Archivgenerator delegiert ebenfalls an
diese Methode. `tests/php/generated_form_security.php` bindet zusätzlich die
vollständige Matrixvalidierung, den abgeschlossenen und gedruckten
Aktiveinsatz-Datensatz, den strikt skalaren `layout=current`-Schalter und den
weiterhin getrennten Archivstream. Im echten HTTP-Smoke werden beide Pfade
abgerufen: Der aktuelle Abzug muss den Marker
`X-eStab-PDF-Layout: current` tragen, der parameterlose Archivpfad behält
seinen SHA-256 über den destruktiven Backup-/Restore-Roundtrip.

Diese drei Tests sind bewusst in `tests/integration/ci.sh` orchestriert.
Insbesondere der PDF-Test ist kein sicherer Einzelbefehl gegen einen
Produktivbestand: Er verlangt den fest benannten aktiven CI-Einsatz und gehört
ausschließlich in ein wegwerfbares Projekt `estab_ci` beziehungsweise
`estab_ci_*`.

### Datenbank-Integration der Nachrichtenlisten-Skalierung

`tests/integration/message_list_scale.php` läuft ausschließlich in der vom
Orchestrator angelegten Datenbank `estab_message_list_scale_ci_test`. Die
Datenbank-Cleanup-Allowlist nennt diesen Namen einzeln; jede andere Datenbank
wird vom Test und vom Orchestrator abgewiesen. Nach dem vollständigen
Migrationslauf werden 10.000 Meldungen des Zieleinsatzes und 257
verwechslungsfähige Meldungen eines zweiten Einsatzes über vorbereitete
Statements geschrieben.

Der Test baut `WHERE`, Parameter und stabile `ORDER BY`-Ausdrücke unmittelbar
mit `app/message_list.php` auf und bindet auch `LIMIT` und `OFFSET` als
Prepared-Statement-Parameter. Geprüft werden exakte Treffer und Reihenfolgen
für kombinierte Fachfilter, Volltextpräfix, zweistellige wörtliche Suche,
Nachweisnummer, erste/zweite/letzte Seite, Wiederholung derselben Seite und die
harte Einsatzgrenze. Zusätzlich muss echtes MariaDB-`EXPLAIN` die drei
kanonischen Suchindizes auswählen.

Die Messgrenzen sind bewusst keine Antwortzeitgarantie für eine konkrete
Installation. Als großzügiger CI-Regressionswächter gelten 180 Sekunden für
die 10.257 Fixture-Zeilen, 5 Sekunden für ein einzelnes vorbereitetes
Zähl-/Seitenpaar und 45 Sekunden für 15 wiederholte repräsentative Paare. Der
Gesamtorchestrator begrenzt den isolierten PHP-Prozess weiterhin auf fünf
Minuten und entfernt die Wegwerfdatenbank auch nach einem Fehler.

### Datenbank-Integration dynamischer Tabellen

Diese Regression erzeugt sechs typische Legacy-Tabellen für Benutzer,
Funktion und Kategorien als MyISAM/latin1, erhält repräsentative Daten und
Duplikate und ruft den gemeinsamen, advisory-lock-geschützten
Schema-Reconciler zweimal auf. Anschließend prüft sie InnoDB, `utf8mb4`,
nullable Datumswerte, Indizes, Strict Mode, Identifier-Angriffe und die
vollständige Fixture-Bereinigung. Der ergänzende
`tests/integration/dv_operations.php` beweist darüber hinaus, dass eine real
ausgewählte zusätzliche S3-Funktion dieselben sechs Tabellen vor dem
Sitzungswechsel erhält und dass der tabellenlose ETB-Hut keine davon erhält:

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

`tests/integration/attachment_reservation.php` verwendet einen zufällig
benannten, nur für diesen Test aktivierten Einsatz, eine darin vollständig
angenommene Dienstschicht, eine zufällig benannte InnoDB-Wegwerftabelle und
unabhängige MariaDB-Verbindungen. Im Cleanup wird die Testschicht geschlossen
und der zuvor aktive Einsatz wiederhergestellt. Damit hinterlässt der Test im
eigentlichen Abnahme-Einsatz insbesondere keine historische Erstschicht, die
eine spätere persönlich bestätigte Dienstaufnahme zu Recht sperren würde. Der
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
- die Zählerreparatur erzeugt ausschließlich ein hashverkettetes
  Betriebsereignis, keine unevidenzierte Status-0-Nachricht; der nächste echte
  Vordruck folgt unmittelbar auf den reparierten Papierwert und beide
  Nachweisketten bleiben gültig,
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
CI-Gate führt sowohl diesen Test als auch die parallele Anhangreservierung in
je einer frisch migrierten, anschließend vollständig gelöschten
Wegwerfdatenbank aus. Dadurch können deren absichtlich angelegte Konten,
Einsätze und historische Schichten den nachfolgenden echten HTTP-Erstdienst
nicht beeinflussen.

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
- HTTP 403 für `PATH_INFO` hinter einem PHP-Endpunkt; ein gültiger
  A/W-Bestandslogin mit CSRF darf über
  `mainindex.php/4fadm` weder eine administrative Ausnahme erhalten noch
  den vorbereiteten Nachrichtensperrbesitz verändern,
- Image-Button-Login, nachweislich gesperrte öffentliche Registrierung,
  authentifizierte Oberfläche und erneute Anmeldung über Kontenliste und
  gespeicherten Passwort-Hash,
- formulargebundene Beibehaltung eines erlaubten geschützten Zielbereichs
  durch Auswahl, Validierungsfehler und erfolgreiche Anmeldung,
- HTTP 303 für anonyme direkte GET-Aufrufe aller navigierbaren Fachseiten,
  Download-Tabs und mindestens einen Browserformular-POST mit abgelaufener
  Sitzung, jeweils mit explizitem Bestandslogin, exakt erwartetem
  allowlist-gebundenem `next`-Schlüssel, leerem Fehlertext sowie einem
  sichtbaren Rückweg zur Übersicht; ein POST-Inhalt wird nicht wiederholt,
  `Sec-Fetch-Dest: iframe` wählt das Content-Login, die intrinsisch
  mainframe-lokalen Anhangs- und Kategoriecontroller verwenden es auch ohne
  den Header und erzeugen keinen verschachtelten Arbeitsbereich,
  der frame-sichere Nachrichtenlogin zeigt den Verlust verständlich an und
  ein eindeutiger Datenbankmarker bleibt nach einem abgelaufenen POST
  unverändert,
- vollständiges Verwerfen einer abgelaufenen operativen GET-Query am
  Nachrichtencontroller und 303 ausschließlich zum erlaubten Ziel `messages`;
  GET-Zugangsdaten und die Login-Metadaten `login_flow`, `next` und
  `interrupted` bleiben harte Ablehnungen,
- `target="_self"` auf allen Loginformularen sowie der Top-Level-Abbruch zur
  öffentlichen Übersicht aus eigenständigem Dokument und `mainframe`,
- exakt eine escaped Sitzungsleiste mit Name, Kürzel, Funktion, Rolle und
  Abmeldebutton auf Root-Einstieg, Hauptansicht, vollhoher Anwendungs-Sidebar,
  Meldungsübersicht, Nachweisung, Anhängen, Vordrucken, Kategorien sowie
  ETB/TBB; jede operative Seite wird dabei mit der passenden ausgewählten,
  persönlich angenommenen aktiven Dienstfunktion aufgerufen, der rechte
  `mainframe` vermeidet Duplikate und anonyme Fachseiten bleiben ohne
  vorgetäuschte eStab-Identität,
- HTTP 303 zum Führungsstellenbetrieb für eine bloß angemeldete Sitzung ohne
  ausgewählten aktiven Hut sowie weiterhin HTTP 403 für S1 auf
  Meldungsübersicht und Nachweisung; die positiven Gegenproben verwenden S2
  beziehungsweise LdF/A/W,
- der eng begrenzte Pre-Hat-Führungsstellenpfad zeigt ausschließlich
  Einsatz-/Schichtgrunddaten und eigene Besetzungen und erlaubt nur
  persönliche Annahme, Übergabebestätigung und Auswahl einer eigenen aktiven,
  angenommenen Besetzungs-ID; Plan-, Melder-, Nachrichten-, Anhangs- und
  Logbuchzugriffe bleiben gesperrt,
- jeder normale operative Schreibpfad revalidiert die exakt ausgewählte
  Besetzungs-ID gegen Konto, Funktion, Rolle, aktiven Einsatz und aktive
  Schicht; eine fremde, abgelaufene oder nur funktionsgleiche ID scheitert,
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
  Nachrichtenvordruck und einem echten JPEG über der früheren 5-MiB-Grenze,
  dessen Browsername auf `.JPEG` endet; Datenbank und Download müssen die
  normalisierte Endung `.jpeg`, Benutzerkürzel, MD5, MIME-Typ und unveränderten
  Dateiinhalt nachweisen. Die Vorschau muss die Bildbytes tatsächlich
  dekodieren und auf 80 × 80 Pixel skalieren. Ein über 20 MiB großes JPEG und
  eine nur in `.JPEG` umbenannte Textdatei müssen mit ihrem konkreten Grund
  abgewiesen werden, ohne Datei, finale Metadaten oder aktive Reservierung zu
  hinterlassen. Der Chrome-Test muss den vorbereiteten Dialog auch ohne
  ausgewählte Datei abbrechen und die Reservierung freigeben können. Die
  direkte Rückgabe nach der Anhangsauswahl muss Anschrift,
  sämtliche markanten Eingaben, Vermerk sowie blaue und grüne
  Empfängerzuordnung als weiterhin absendbare Formularwerte enthalten,
- verknüpfte Anhänge übernehmen die Rechte ihrer exakt referenzierten
  Nachricht, freie Anhänge bleiben auf Uploader, S2, Si und LdF begrenzt;
  fremde Liste, Vorschau, Download, Auswahl und ein manipuliertes finales
  Speichern müssen geschlossen scheitern,
- Abschluss einer echten Gesprächsnotiz über den historischen Controller,
  anschließende Erzeugung durch den produktiven Vordruckgenerator, Auffinden in
  der geschützten Liste, aktuellen In-Memory-Download mit gemeinsamem
  Dossierrenderer und eigenem Layout-Header sowie getrennten Archivdownload
  mit PDF-Header/-Trailer und MIME-Headern; danach ersetzt der Test
  ausschließlich in seinem Wegwerfstack das Archiv durch ein gültiges,
  absichtlich veraltetes Marker-PDF. Der aktuelle Abzug darf diesen Marker
  nicht enthalten, der parameterlose Archivdownload muss ihn enthalten und
  dessen SHA-256 muss nach Backup und Restore unverändert sein. Leere,
  unbekannte, nichtskalare oder für Anhänge gesetzte Layoutparameter werden
  abgewiesen,
- Basic-Auth-Adminseite mit escaped technischem Benutzernamen, ausdrücklicher
  Trennung vom eStab-Funktionskonto; der Schließ-POST der letzten aktiven
  Dienstschicht prüft einen unmittelbar zuvor gleichlang manipulierten
  Pflichtanhang und liefert HTTP 409 mit unveränderter Schicht und
  unveränderten Funktionsbesetzungen; außerdem Exportverwaltung: zwei vollständige
  Exporte erzeugen, PRG-Rückleitungen und CSRF-Grenzen prüfen, Manifest und
  jede CSV-Prüfsumme in beiden heruntergeladenen ZIPs verifizieren, den
  Workflowmarker per CSV-Parser exakt in `nv_nachrichten.csv` nachweisen,
  Traversal und unbekannte IDs abweisen, genau einen Lauf löschen und den
  zweiten byteidentisch für den Backup-/Restore-Nachweis behalten,
- Restore-Prüfmodus ohne Neuanlage: Das Nachrichtenworkflow-Gate übergibt die
  Identität seines tatsächlich aktiven S1-Nachfolgers über eine private
  CI-Zustandsdatei. Genau dieses vorhandene Konto wird nach dem Restore erneut
  angemeldet und seine wiederhergestellte aktive Dienstbesetzung ausgewählt;
  anschließend exakten Anhanginhalt und PDF-SHA vergleichen, globalen Einsatzkopf sowie
  ETB-/TBB-Einträge nur lesen und Kennung, Manifest, Nachrichten-CSV und
  ZIP-SHA des überlebenden Exportlaufs im wiederhergestellten Volume prüfen.

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
- ausschließlich bekannte Hilfetextschlüssel sowie die Abwesenheit der
  früheren eigenständigen Status-/Zähler-Helfer aus der Runtime-Oberfläche.

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

Für eine rein lesende Kontrolle der anonymen Startseite ist kein Testkonto
erforderlich. Der gezielte Lauf prüft die korrekte TBB-Bezeichnung,
Anmeldeziele, Kartengeometrie und Hoverzustände im echten Browser:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:8080 \
python3 -B tests/browser/headless_ui.py --overview-only
```

Auch die öffentliche BOS-Infosammlung besitzt einen rein lesenden
Browserlauf. Er öffnet alle sieben historischen Dokumente bei Desktop- und
Mobilbreite, prüft die gemeinsame Darstellung, Navigation, Tabellen, Bilder
und Fokuszustände und vergleicht den sichtbaren Originalbereich mit dem
unveränderten Quelldokument:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
python3 -B tests/browser/headless_ui.py --bos-only
```

Zusätzlich schützt `tests/php/bos_info_ui_security.php` alle historischen
BOS-HTML-Dateien und Bilder durch feste SHA-256-Prüfsummen. Eine fachliche
Inhaltsänderung fällt dadurch bereits in der statischen Suite auf. Sobald der
Browser-Gate aktiviert ist, führt `tests/integration/ci.sh` den vollständigen
BOS-Lauf automatisch aus.

Der vollständige Akzeptanzlauf verwendet anschließend das eigens
provisionierte Testkonto:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_LOGIN_NAME='Browser Test' \
ESTAB_TEST_LOGIN_CODE=brw001 \
ESTAB_TEST_LOGIN_FUNCTION=S1 \
ESTAB_TEST_LOGIN_PASSWORD_FILE=secrets/test_login_password.txt \
python3 -B tests/browser/headless_ui.py
```

Das Kennwort muss zum zuvor provisionierten Testkonto passen. Fehlt sowohl
`ESTAB_TEST_LOGIN_PASSWORD` als auch
`ESTAB_TEST_LOGIN_PASSWORD_FILE`, bricht der vollständige Lauf verständlich
ab; die rein lesenden beziehungsweise separat per Basic Auth geschützten
Teilmodi benötigen es nicht. Zugangsdaten erscheinen weder in Logs noch
Diagnosen.

Der Ablauf klickt die getrennten Wege der Übersicht, öffnet den Bestandslogin
im Zwei-`iframe`-Nachrichtenarbeitsbereich, füllt das Formular aus und prüft
insbesondere:

- Anonyme Nutzer sehen alle neun Bereiche in stabiler Reihenfolge, genau eine
  aktive Markierung und sichere Anmeldeziele; direkte Zugriffe auf
  navigierbare Fachseiten und Download-Tabs landen per Redirect im
  Bestandslogin mit exakt passendem, symbolischem Ziel. Der Test bricht diesen
  Login über den sichtbaren Top-Level-Link ab und erreicht wieder die
  öffentliche Übersicht. Zusätzlich sendet er ein operatives Formular ohne
  gültige Sitzung ab, prüft den frame-sicheren Login samt Hinweis auf die
  nicht gespeicherte Eingabe und verlässt ihn ebenfalls ohne manuelle
  URL-Änderung. Eine abgelaufene operative GET-Query wird ohne Übernahme ihrer
  Filterwerte zum erlaubten Ziel `messages` geleitet. Der echte Browser prüft
  zudem intrinsische `mainframe`-Controller ohne verschachtelten
  Zwei-Frame-Arbeitsbereich, `target="_self"` auf den Loginformularen und den
  Top-Level-Abbruch aus dem Inhaltsframe. Zugangsdaten beziehungsweise
  Login-Metadaten in GET sowie nichtinteraktive Schutzendpunkte bleiben HTTP
  403.
- Der Arbeitsbereich enthält in stabiler Reihenfolge ausschließlich die
  vollhohe `vorgaben`-Sidebar und den `mainframe`. Der gemeinsame Refresh
  verwendet die richtige Origin und keinen schema-relativen Doppel-Slash. In
  der Sidebar ist genau eine sichtbare Sitzungsidentität mit Name, Kürzel,
  Funktion, Rolle und Logout vorhanden; der rechte Inhaltsframe dupliziert sie
  nicht.
- Statuskarte, Identität, Aktionen und Navigation folgen ohne Überlappung
  aufeinander. Arbeitszähler, Serverzeit, Onlinebelegung und die aktuelle
  Funktion sind vorhanden; die rollenabhängigen Fachaktionen sind echte,
  mindestens 44 Pixel große Textbuttons. Ein positiver Arbeitszähler besitzt
  eine dauerhaft sichtbare Warnstufe.
- Das Manifest enthält neun Bereiche und zwei Dienste. Nach der Anmeldung sind
  vor Hutauswahl nur Übersicht, Führungsstellenbetrieb, BOS-Info,
  Administration und Handbuch sichtbar. Nach der Auswahl erscheinen nur die
  für den ausgewählten Hut zulässigen neun beziehungsweise zehn Links ohne
  Disclosure dauerhaft; S2 erhält die Meldungsübersicht, LdF/A/W die
  Nachweisung, andere Hüte keines der beiden Spezialziele. Alle sichtbaren
  Links sind mindestens 44 Pixel groß und besitzen weder eine eigene
  horizontale noch vertikale Scrollfläche. Bei `1440 × 1000`, `1280 × 720` und
  `700 × 760` CSS-Pixeln ist das Sidebar-Dokument die einzige vertikale
  Scrollfläche und bleibt horizontal innerhalb seiner Breite.
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

Er weist nach, dass anonyme Lesezugriffe und eine bloß angemeldete Sitzung
ohne ausgewählten aktiven Hut HTTP 403 erhalten. Ein operativer
Schreibversuch einer angemeldeten Sitzung ohne persönlich ausgewählten Hut
wird dagegen ausdrücklich durch die zentrale Betriebssperre mit HTTP 423
abgewiesen. Jede ausgewählte, persönlich angenommene aktive Dienstfunktion
darf ETB und TBB des aktiven Einsatzes lesen. Beide Bücher zeigen den globalen
Einsatzkopf und besitzen kein lokales Titelformular mehr. Die jeweils
fachfremde ausgewählte Sitzung erhält HTTP 200 samt gespeichertem Inhalt,
aber kein Eintragsformular. Cross-Rollen-POSTs liefern HTTP 403. Nur S2 oder
ETB mit `EINSATZTAGEBUCH` darf ETB-Daten und nur A/W mit `BEFOERDERUNG`
TBB-Daten über POST und Session-CSRF-Token schreiben.
Der Datenbanktest der Führungsstelle ergänzt die reale Si→ETB-Hutwahl und
weist nach, dass Si dabei kein ETB-Schreibrecht erbt.
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

Nachgewiesen werden HTTP 403 ohne Sitzung oder ohne ausgewählten aktiven Hut,
positive IDs, inerte GET-Schreibparameter, Session-CSRF und HTTP-303-PRG. S1
darf mit seiner ausgewählten aktiven Dienstfunktion Funktions- und
Benutzerkategorien seines eigenen Tabellenraums verwalten, aber weder
Master-Kategorien ändern noch eine Master-Zuordnung einschleusen. S2/Rotkopie
und `Si` erreichen die Masterverwaltung. CRUD, Zuordnung und Linkbereinigung
werden direkt in MariaDB geprüft. Eine existierende Nachricht ohne exakten
S1-Objektzugriff liefert beim Zuordnungsversuch HTTP 403 und bleibt
unverändert. Kategorien mit SQL-ähnlichen Quotes sowie Beschreibungen mit
Quotes, `&` und `<script>` werden bytegenau roh gespeichert, im HTML jedoch
escaped und ohne ausführbares Script ausgegeben. Der Listenfilter wird mit der
positiven Kategorie-ID erfolgreich ausgeführt und beim Löschen dieser
Kategorie aus der Sitzung entfernt; der SQL-artige Name als Filterparameter
wird mit HTTP 403 abgewiesen.

Vor den Kategorieänderungen betätigt derselbe Test außerdem die tatsächlich
gerenderten Nachrichtenaktionen für „gelesen“ und „erledigt“. Fehlendes CSRF
und eine fremde Nachrichten-ID liefern HTTP 403 und verändern keine
Statustabelle. Wiederholtes Setzen beziehungsweise Entfernen bleibt
idempotent; die sichtbaren Gegenaktionen wechseln passend mit. Eine erledigte
Nachricht verschwindet aus der Standardliste, erscheint mit eingeschaltetem
Erledigt-Filter wieder und wird am Ende auf ihren ursprünglichen Zustand
zurückgesetzt.

Ein Trap entfernt sämtliche Testkategorien, Links und die fremde Nachricht.
Ein ausschließlich für den Einzellauf erzeugtes Si-Konto samt persönlicher
Tabellen wird ebenfalls entfernt; eine bereits dienstlich zugewiesene
CI-Besetzung bleibt bestehen. Die CI führt diesen Test
nach HTTP-Smoke und ETB/TBB, aber vor Admin-Workflow und Backup-/Restore aus.

### Nachrichtenrollen-HTTP-Integration

`tests/integration/message_workflow_http.sh` erzeugt echte Konten, Nachrichten,
dynamische Statustabellen und Vordrucke. Der Test startet deshalb nur mit
`ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION=true` und einem Compose-Projekt
namens `estab_ci` oder `estab_ci_*`. Das Projekt muss wegwerfbar,
mit deaktivierter Selbstregistrierung und mit der historischen Standardmatrix
initialisiert sein. Der vorangehende HTTP-Smoke aktiviert einen vollständigen
Erstdienst. Der Nachrichtenrollentest plant seine eigene Nachfolgeschicht,
lässt alle Konten persönlich annehmen und führt anschließend die zweistufige,
durch das eingehende S1-Konto bestätigte Übergabe über die Produktions-Domain
aus; er deaktiviert den zentralen Schreibguard zu keinem Zeitpunkt.

Ein einzelner Lauf kann beispielsweise so gestartet werden:

```console
COMPOSE_PROJECT_NAME=estab_ci_message \
ESTAB_TEST_COMPOSE_ENGINE=podman \
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_WORKFLOW_MARKER=MANUELLER_E2E_MARKER \
ESTAB_TEST_ROLE_PASSWORD_FILE=/absoluter/pfad/role_password.txt \
ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION=true \
tests/integration/message_workflow_http.sh
```

Der Test belegt die beiden nicht konfigurierbaren Abläufe:

- Eingang: A/W nimmt Rufname, Medium, Zeit und Aufnahmezeichen auf und
  registriert die Nachricht in Status 1. LdF übersetzt den Rufnamen in den
  Absender, muss den Eingangsweg ausdrücklich bestätigen und darf ihn nur mit
  Begründung korrigieren; danach übergibt LdF mit Status 4 an Si. Si wertet
  Inhalt und Zuständigkeit aus und schließt mit Status 8 ab.
- Ausgang: Die Stabsfunktion reicht in Status 4 bei Si ein. Si prüft nur die
  formale Richtigkeit und gibt mit Status 1 an LdF frei. LdF bestimmt Rufname
  der Gegenstelle und vorgesehenen Beförderungsweg und übergibt mit Status 2
  an A/W. A/W weist tatsächlichen Weg und Zeit nach und schließt mit Status 8
  ab.
- Rückgabe: Si gibt einen formal fehlerhaften Ausgang mit Pflichtgrund in
  Status 10 an den Verfasser zurück. Nur dieser korrigiert ihn und reicht
  erneut vollständig in Status 4 ein.

Die Vorschlagsfunktion besitzt zusätzlich drei aufeinander abgestimmte
Nachweise. `tests/php/message_suggestion_security.php` prüft den
Fail-closed-Vertrag, die zugängliche Combobox/Listbox samt nativer
No-Script-Rückfalloption, ausgeschaltetes Browser-Autocomplete und HTML-sichere
Ausgabe. Dazu gehören die festen LdF-Kontextfelder je Richtung, getrennte
Wert-/Herkunftsattribute sowie die erneute Prüfung von Status, Sperrbesitz und
ausgewählter Besetzung im selben Statement. Der isolierte MariaDB-Test
`tests/integration/message_suggestions.php` belegt die erneute Prüfung von
aktivem Einsatz und ausgewählter Dienstbesetzung im selben Abfrage-Statement,
die Rollen- und Richtungsgrenzen, akzentverschiedene Werte sowie, dass Werte
eines anderen Einsatzes nicht erscheinen. Er weist außerdem die Rangfolge
„häufige abgeschlossene Nachrichtenpaare vor aktuell gültigem
S6-Fernmeldeplan vor allgemeiner Historie“ für Eingang und Ausgang nach und
entzieht die Vorschläge unmittelbar nach einem Sperrverlust.
`tests/integration/message_workflow_http.sh` prüft schließlich an den echten
Formularen: A/W und LdF erhalten nur die zulässigen Rufnamenvorschläge, LdF bei
Eingängen die Absendervorschläge; LdF sieht zum gesperrten aktuellen Vordruck
die priorisierte, sichtbar gekennzeichnete Zuordnung; A/W-Eingang und Stab
erhalten kein Absender-Eingabefeld. Keine Liste wählt einen Wert automatisch
aus und freie Eingaben bleiben möglich. Die lokale Eingangsanschrift und
Ausgangs-Absendereinheit müssen dem per Join gelesenen Führungsstellennamen
des aktiven Einsatzes entsprechen; ein absichtlich gesetzter
`ESTAB_ORGANISATION`-Poisonwert darf weder in Datenbank noch Ausgabe
erscheinen.
`tests/browser/headless_ui.py --message-suggestions`
meldet ein echtes A/W-Konto an und beweist mit einem kurzlebigen,
einsatzgebundenen Marker Fokusöffnung, Filterung, Pfeiltaste/Eingabetaste,
Übernahme, freie Eingabe und Logout im echten Chrome; das Fixture wird auch
bei einem Testabbruch entfernt.

Der Lauf registriert isolierte LdF-, A/W-, Si-, S1-, S2-, S3- und
POL/FB-Konten über
die öffentliche Kontooberfläche. Er prüft die gerenderten Listen und Aktionen,
fehlende CSRF-Tokens mit HTTP 403, die erlaubten Stabsaktionen sowie verbotene
LdF-/Fernmelde-/Si-Aktionen des echten FB-Profils, stufengebundenen
LdF-/A/W-Sperrbesitz, Status und Abschluss direkt in MariaDB, den erzeugten
Ein-/Ausgangsvordruck sowie die exakten Empfängerfarben `S2_rt`, `S1_gn` und
`S3_bl`.

Die Nachweisung wird mit demselben Lauf fachlich geprüft: Eingänge müssen ihr
aufgenommenes Medium in übersetzter Langform anzeigen. Bei Ausgängen bleibt der
von LdF vorgesehene Weg bis zum gespeicherten Beförderungszeitpunkt als „Noch
nicht befördert“ gekennzeichnet; erst danach erscheinen Medium und
Freitextstrecke als tatsächlicher Beförderungsweg. Der statische Test
`tests/php/message_transport_security.php` ergänzt dafür alle historischen
Codes, unbekannte und nichtskalare Werte, Deduplizierung sowie HTML-Escaping.
Der Eingangsweg wird zusätzlich in drei Negativ-/Positivstufen belegt: Ohne
LdF-Bestätigung antwortet die Validierung mit HTTP 422 und die gesperrte
Nachricht bleibt unverändert in Status 1; ein
abweichendes Medium ohne Begründung liefert HTTP 409 und schreibt weder
Nachricht noch Ereignis; erst Bestätigung plus Begründung aktualisiert das
Medium und hängt Altwert, Neuwert, Korrekturgrund und authentifiziertes
LdF-Kürzel an die Hashkette. Aufnahmezeit und A/W-Zeichen werden vor und nach
diesem Übergang bytegenau verglichen; übermittelte Ersatzwerte werden schon
vor dem Legacy-Validator verworfen. Ein zweiter, inzwischen veralteter
Formularstand wird an der Objektberechtigung mit HTTP 403 abgewiesen und kann
weder den abgeschlossenen Übergang ändern noch ein doppeltes LdF-Ereignis
erzeugen.

Beim Öffnen vergleicht der Lauf die sichtbaren, editierbaren Felder für
Eingangs- und Beförderungszeit mit der PHP-Uhr im App-Container. Er sendet
anschließend jeweils bewusst eine fünf Minuten zurückgesetzte vollständige
taktische Zeit und vergleicht die gespeicherten `DATETIME`-Werte exakt mit
MariaDB. Damit sind sowohl die Vorbelegung als auch ihre manuelle
Überschreibbarkeit unabhängig von der Zeitzone des Testhosts belegt.

Zusätzlich manipuliert der Lauf Formulardaten und die historische
Empfängermatrix, um eine Autosichtung oder einen übersprungenen Zuständigen zu
erzwingen. Die Anwendung und die Datenbank müssen diese Abkürzung ablehnen;
bei nicht besetztem Si bleibt die Nachricht unverändert in dessen
Warteschlange.

Ein weiterer Negativpfad sendet beim A/W-Eingang verborgene `16_*`-
Empfängerfelder und simuliert zusätzlich einen bereits vorhandenen fremden
Empfängertoken. Vor dem terminalen Si-Abschluss müssen Liste, Detailaufruf und
Gelesen-/Erledigt-Aktion jeweils HTTP 403 beziehungsweise keine sichtbare
Zeile ergeben. Dieselbe Prüfung läuft für die S2-Rotkopie eines ausgehenden
Status-4-Entwurfs und einer Status-10-Rückgabe. Die Verfasserfunktion behält
den benötigten Zugriff; S2 erhält die Nachricht erst nach dem vollständigen
Status-8-Abschluss. Damit wird nicht nur der Formularhandler, sondern dieselbe
Zugriffsregel in Listen-SQL, Objekt-Gate und atomarem State-SQL geprüft.

`tests/php/read_authorization_security.php` und die HTTP-Gegenproben erweitern
diese Grenze auf alle Ausgabepfade. Eine Kontositzung ohne ausgewählte aktive
Dienstbesetzung darf keine operative Nachricht lesen. Normaler Stab/FB erhält
nur die terminale Empfängerkopie oder den eigenen Ausgang; Si, LdF und A/W nur
ihre aktuelle Warteschlange/Sperre oder eine Nachricht mit eigener
unveränderlicher Verarbeitungsmarke. Vordruckliste und beide Downloadvarianten
verwenden dieselbe Nachricht. Verknüpfte Anhänge erben die Objektregel über
exakte vollständige Dateinamens-Tokens; freie Anhänge bleiben auf Uploader,
S2, Si und LdF begrenzt. Liste, Vorschau, Download, Auswahl und finaler
Nachrichtensave werden getrennt negativ geprüft. Die Meldungsübersicht ist nur
für S2, die Nachweisung nur für LdF beziehungsweise A/W erreichbar.

Danach versucht der Lauf die historischen
`FM-Admin`-/`SI-Admin`-Zweitsichtungen gegen einen abgeschlossenen Datensatz.
Weder Navigation noch Controller dürfen einen solchen Bearbeitungspfad
bereitstellen. Manipulierte Requests müssen abgewiesen werden; fachliche
Felder, Quittierung, Empfängerfarben, Sichter- und Transportvermerk sowie der
bereits erzeugte PDF-Vordruck bleiben unverändert. Persönliche
Gelesen-/Erledigt-Markierungen werden davon getrennt geprüft.

Abschließend liest POL/FB die zugestellte Eingangsnachricht und betätigt die
tatsächlich gerenderten Aktionen „Antworten“ und „Weiterleiten“. Beide
abgeleiteten Formulare werden gespeichert und müssen zwei getrennte
Status-4-Ausgänge für Si mit korrektem Zitat, Ziel, Absender,
Funktionskennung und
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
  S2/Stab als einzigem Rotkopie-/Dokumentationsziel und ausschließlich
  falschen Autosichtungsflags; die dabei
  nicht betroffenen Benutzerkonten bleiben bytegenau unverändert, während der
  separate Zuordnungsrichtlinientest betroffene Konten abdeckt,
- getrennte Semantik der drei Matrixaktionen: aktives Speichern lässt den
  Standard unverändert; Standard laden verändert weder Datenbank noch Audit;
  gemeinsames Speichern kopiert alle 20 Zellen und normalisiert Rotkopie sowie
  das inaktive Legacy-Autosichtungsfeld,
- atomaren Rollback beider Matrixtabellen und des Audits: ein temporärer
  `BEFORE INSERT`-Trigger erzwingt einen Fehler in der Standardtabelle,
  anschließend stimmen die exakten Vorher-/Nachher-Snapshots aller Spalten
  beider Tabellen und die Auditanzahl überein,
- zwei parallele, getrennte Admin-Sitzungen: genau eine darf denselben
  Nachrichtenzähler erhöhen; die andere erhält HTTP 409,
- hashverketteter Zählernachweis ohne künstliche Fachnachricht, Audit und
  POST-only-PDF-Vordruckreset.

Ein Trap entfernt den temporären Fehlertrigger, stellt beide ursprünglichen
Matrizen, alle vorherigen Grafikflags und die Auto-Inkremente wieder her und
entfernt die ausschließlich im Wegwerfprojekt erzeugten Audit-/Testdaten. Das
ist eine zusätzliche
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

Insbesondere beweist die Automation keine physische Hörbarkeit. Sie bindet
alle drei Dateien per SHA-256, parst RIFF/WAVE und PCM-16, prüft Kanäle,
Abtastrate, Framezahl, Dauer, Signalspitze und Mindest-RMS sowie
Opt-in-Zustand, sichtbaren Rückfall und den angeforderten Wiedergabeaufruf.
Lautsprecher, Lautstärke, Betriebssystem-, Browser- und Geräteeinstellungen
müssen manuell abgenommen werden.

Mindestens zu prüfen:

- zwei Testeinsätze nacheinander aktivieren, die globale Statusanzeige auf
  allen Modulen auf Führungsstellenname, Kennung und Einsatzname kontrollieren
  und nach Deaktivierung die rote No-active-Warnung sowie gesperrte operative
  Formulare prüfen,
- einen offenen migrierten Testeinsatz ohne Führungsstellennamen in
  Statusleiste und Administration als fehlend/unvollständig anzeigen lassen,
  seine Aktivierung und operative Eingabe abweisen, im historischen PDF
  „historisch nicht erfasst“ prüfen, den tatsächlichen Namen einmalig
  administrativ bestätigen und nach der ersten operativen Eintragung jede
  weitere Änderung abweisen,
- neuen Benutzer für jede tatsächlich verwendete Funktion in der
  Benutzerverwaltung anlegen, abmelden und mit demselben Kennwort erneut
  anmelden; eine abweichende Funktion vor und nach dem Logout abweisen,
- ein Funktionskonto in der Administration sperren, die alte Sitzung und
  Neuanmeldung abweisen, anschließend entsperren und ein Kennwort
  zurücksetzen; altes Kennwort ablehnen und neues akzeptieren,
- Berechtigungs-/Rollenzuordnung aus der Empfängermatrix kontrollieren,
- jede verwendete Person ihre angenommene aktive Dienstfunktion auswählen
  lassen; dieselben operativen Lesepfade mit bloßer Kontoanmeldung ohne
  ausgewählten Hut abweisen,
- eingehende und ausgehende Nachricht mit Richtung, Gegenstelle,
  Prioritätsstufe, Empfängern und Inhalt erfassen,
- den Bildschirmvordruck bei Desktopbreite und bei 390 Pixeln direkt mit den
  beiden Referenzunterlagen vergleichen: Dreizonenraster, Zellfolge,
  Beschriftungen, Linien und Blauton müssen übereinstimmen; auf 390 Pixeln
  darf nur das Blatt horizontal scrollen,
- alle 20 Informationsdialoge einzeln per Maus und Tastatur öffnen, Inhalt und
  Zuordnung zum Feld prüfen sowie Schließen-Knopf, `Escape`, Außenklick und
  Fokus-Rückgabe kontrollieren; zusätzlich eine Bildschirmdruckprobe ohne
  Bedienleisten, Informationsdialoge, VS-NfD-Aufdruck und Wappen erstellen,
- Weiterleitung, Sichtung, Quittierung, Statuswechsel und Listenfilter über
  zwei unterschiedliche Funktionssitzungen nachvollziehen,
- in Meldungsübersicht und zweiter Sichtung Nummern-, Mehrwort- und
  Kurztextsuchen sowie Richtung, Vorrang, Stand, Zeitraum, Empfänger,
  Sortierung, Filterchips und erste/letzte Seite mit einem Bestand über mehrere
  Ergebnisseiten prüfen; während einer Eingabe darf keine automatische
  Aktualisierung den Suchtext verwerfen,
- mit S2 die Meldungsübersicht und mit LdF/A/W die Nachweisung öffnen; S1, Si
  und S6 an den jeweils fremden Spezialzielen mit HTTP 403 abweisen,
- für Fernmelder, Si und mindestens eine Stab-/FB-Sitzung den
  Hinweiston-Schalter im vorgesehenen Browser ausdrücklich aktivieren, den
  Testton tatsächlich anhören, nach der stillen ersten
  Warteschlangenmessung jeweils eine reale Erhöhung erzeugen und den passenden
  Hinweis genau einmal physisch hören; mit ausgeschaltetem oder browserseitig
  blockiertem Ton zusätzlich die sichtbare Rückmeldung kontrollieren,
- globale, funktionsbezogene und persönliche Kategorie anlegen, zuweisen,
  suchen und entfernen; dieselben Seiten ohne ausgewählten aktiven Hut und
  einen fremden Nachrichtenbezug abweisen,
- zulässigen Anhang hochladen, Vorschau/Download prüfen und eine unzulässige
  Datei ablehnen lassen; verknüpften und freien Anhang zusätzlich mit
  berechtigtem sowie fremdem Hut über Liste, Vorschau, Download, Auswahl und
  manipulierten finalen Nachrichtensave prüfen,
- Nachrichtenvordruck als PDF erzeugen und aus der geschützten
  Vordruckliste mit berechtigtem Hut abrufen und mit einem fremden Hut sowohl
  Liste als auch aktuellen und archivierten Download abweisen,
- für einen inzwischen historischen Einsatz ein PDF-Dossier mit allen neun
  Abschnitten ETB, TBB, Nachrichtenvordrucke, Anhänge,
  Nachrichtenereignisse, Dienstbetrieb, S6-Fernmeldepläne, Melderläufe und
  Betriebsereignisse erzeugen, die Anlagenansicht im vorgesehenen
  PDF-Programm öffnen und stichprobenartig eine eingebettete Datei samt
  dokumentierter SHA-256 gegen das Original prüfen; dabei
  Führungsstellenname, Einsatzkennung und Einsatzname getrennt kontrollieren,
- Einsatztagebuch einmal als S2 und einmal mit einer eigenständig zugewiesenen
  ETB-Funktion, das technische Betriebsbuch mit A/W in der Rolle Fernmelder
  beschreiben; bei einer ETB/Si-Mehrfachbesetzung ausdrücklich zwischen beiden
  Hüten wechseln und den Si-Hut beim ETB-POST mit HTTP 403 abweisen;
  anschließend beide Bücher mit der jeweils anderen ausgewählten
  aktiven Dienstfunktion vollständig, aber ohne Schreibformular lesen, eine
  bloß angemeldete Sitzung ohne ausgewählten Hut abweisen und
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
- alle Basistabellen sowie Einsatz-, Nachweis-, Dienstschicht-, S6- und
  Meldertabellen in ihrer kanonischen Form vorhanden sind,
- aktive und Standardmatrix jeweils genau 20 eindeutige 5x4-Positionen,
  genau S2/Stab als Rotkopie-/Dokumentationsziel und keinerlei aktive
  Autosichtung enthalten,
- die exakt definierten Benutzer- und Anhangindizes vorhanden sind,
- alle ausgelieferten versionierten Migrationen mit gültigem SHA-256 als
  angewendet protokolliert sind,
- der Singleton des globalen Einsatzstatus, alle Einsatz-Fremdschlüssel und
  -Trigger, append-only Ereignisketten, formaler Abschluss/Aufbewahrung sowie
  die dauerhafte Kontosperr-Spalte kanonisch vorhanden sind,
- Benutzer-, IP-, Anhang- und alle sechs Nachrichten-Kürzelfelder die
  erforderlichen Breiten besitzen,
- Anhang-, Vordruck- und Exportverzeichnis beschreibbar sind.

`docker/db/verify.sql` löst den aggregierten Schemacheck in benannte
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
