# Tests, Funktionsnachweis und Monitoring

Die Funktionssicherung besteht aus mehreren Ebenen. Keine einzelne
Health-Antwort beweist die vollständige Fachfunktion.

Die Zuordnung jeder Funktion zu ihrem automatisierten und fachlichen Gate
steht in der [Funktionsmatrix](FUNKTIONSNACHWEIS.md).

Der aktuelle Autorisierungsvertrag ist in allen Ebenen gleich: Operative
Eingaben benötigen in beiden Berechtigungsmodi einen aktiven offenen Einsatz
und ein konkretes aktives, ungesperrtes Konto. Im Modus `STRICT` sind eine
aktive formale Dienstschicht sowie eine persönlich angenommene und aktuell
ausgewählte fachlich passende Besetzung Pflicht. Im Modus `LOOSE` ist keine
formale Dienstschicht erforderlich; feste Kontofunktion oder explizit
vergebene globale Zusatzfunktion müssen den Arbeitsschritt weiterhin erlauben.
Authentisierung, Einsatz-, Funktions- und Objektgrenzen, CSRF, Validierung,
Integrität, Audit, Append-only und Aufbewahrung bleiben unverändert.
Zusatzfunktionen werden in `STRICT` ignoriert. Optionale Zugangsschichten
werden nur in `LOOSE` getrennt mit unzugeordnetem
Konto, OR-Semantik bei Mehrfachzuordnung, Aktivierung ohne Anmeldung,
Deaktivierung mit Sitzungswiderruf und Vorrang der manuellen Kontosperre
geprüft. Nach ihrem Ende bleiben formale Schichtdaten Export-/Evidenzdaten.
„Aktives Konto“ bezeichnet dabei stets die schreibende Person. Beim
Melderauftrag bleibt LdF aktiv und authentisiert; ausschließlich der fachlich
geeignete und ungesperrte Ziel-Fernmelder darf bei der Beauftragung inaktiv
oder abgemeldet sein. Seine spätere persönliche Übernahme bleibt
authentisierungspflichtig.
Der PDF-Abschnitt Dienstorganisation muss formale Dienstschichten samt
Besetzungen und Übergaben als aktuelle oder historische Evidenz sowie getrennt
die Zugangsschichten einschließlich aktueller und entfernter Zuordnungen
enthalten. Hashkettentests erwarten Objekttyp `ZUGANGSSCHICHT` für
Gruppenmutationen. Die Nachrichtenzähler-Reparatur muss in `STRICT` eine
aktive formale Dienstschicht verlangen und `DIENSTSCHICHT` schreiben; in
`LOOSE` läuft sie ohne formale Dienstschicht mit Objekttyp `EINSATZ`.

Die Kennwortrichtlinie wird als eigener globaler Sicherheitsvertrag geprüft:
konfigurierbare Mindestlänge 8–128 Unicode-Codepoints, Default 12, höchstens
1024 UTF-8-Bytes, 1024 Browser-Eingabeeinheiten, exakte browserseitige
Codepointzählung bei verbindlicher Serverprüfung, optionale
Unicode-Groß-/Titlecase-/Kleinbuchstaben, Unicode-Ziffern und Sonderzeichen,
verbotene Unicode-Steuerzeichen bei erlaubten Formatzeichen einschließlich ZWJ,
revisionsgebundene Vorschau/Bestätigung und atomarer Audit. Kontoanlage, Reset
und aktivierte Selbstregistrierung müssen denselben Stand verwenden und
Argon2id speichern. Bestandslogin und vorhandene Sitzungen bleiben unberührt;
Klartextwerte und eindeutig verifizierbare Alt-Hashes werden erst nach
erfolgreicher Anmeldung auf Argon2id umgestellt. bcrypt wird nur bei einem
eingegebenen Kennwort unter 72 UTF-8-Bytes automatisch migriert; bei 72 oder
mehr Bytes bleibt er bis zum administrativen Reset unverändert. Stärkere oder
gemischte Argon2id-Kosten werden nicht zurückgestuft. Das separate
HTTP-Basic-Secret bleibt unabhängig.

Die Selbstregistrierungsfreigabe besitzt einen eigenen Sicherheitsvertrag:
`DISABLED`, `PERMANENT` und `UNTIL`, feste Zeiträume von 15 Minuten bis 24
Stunden, halb-offene UTC-Grenzen, optimistische Revision, gemeinsamer
Advisory-Lock von Administration und Kontoanlage sowie ein atomischer
`INSERT ... SELECT`-Zeitguard. 107 statische Assertions, 31 MariaDB-Assertions,
28 echte Handler-Assertions und der Admin-HTTP-Test beweisen, dass ein
abgelaufenes oder vorzeitig geschlossenes Fenster kein Konto und kein
Anmelde-Audit erzeugt, während Bestandslogin und Sitzungen unberührt bleiben.

| Ebene | Nachweis |
| --- | --- |
| Quellprüfung | GitHub-Workflow-Prüfung mit festgelegtem Actionlint 1.7.12, PHP-8.5-Lint, Container-Allowlist sowie Kompatibilitäts-, Sicherheits-, Einsatz-, Benutzerverwaltungs-, amtlicher Nachrichtenvordruck-, Upload-, Export- und PDF-Regressionen |
| Image-Build | benötigte PHP-Erweiterungen und Apache-Konfiguration |
| Datenbank | echtes MariaDB-Schema, Einsatz-Singleton/Trigger, Kontosperre, revisionsgesicherte Kennwort- und Selbstregistrierungs-Singletonzeilen, Indizes, aktive und persistente Standardmatrix, Engines, Collations und Zero-Date-Freiheit |
| HTTP | Header, direkte Endpunktfläche, 303-Weiterleitung anonymer geschützter Aufrufe zum allowlist-gebundenen Bestandslogin samt sichtbarem Rückweg, 403-/400-/405-Grenzen, dauerhafte und befristete Selbstregistrierungssteuerung, sichtbare Sitzungsidentität, Präsenz/Leerlaufende, feste Funktions-/Rollenbindung, optionaler Gruppenzugang, Kennwortrichtlinien-Vorschau/-Bestätigung, verbindlicher Nachrichtenlauf, E-Mail-Anhang mit passiver Ansicht und bytegleichem Originaldownload, S6-Plan, Melderlauf, Kategorien- und ETB-/TBB-Rollengrenzen, Vordruckerzeugung sowie Admin-Export |
| Echter Browser | öffentliche Übersicht, getrennte Konto-Flows, direkte ETB-/Nachrichten-/Anhang-/Kategorie-Anmeldung ohne Sackgasse oder verschachtelten Arbeitsbereich, sicherer Login-Abbruch, neun stabile Navigationsbereiche, aktive Markierung, reale Karten- und Bereichswechsel im selben Tab, überlappungsfreie Karten-Klickflächen und echter Hover bei sechs Breiten, genau zwei Anwendungs-`iframe`-Elemente, vollhohe Sidebar ohne verschachtelte Scrollflächen bei 1440 × 1000, 1280 × 720 und 700 × 760 CSS-Pixeln, fokuserhaltender Statusfragment-Refresh samt sichtbarem Fehler- und Erholungspfad, dauerhafte Warnstufe bei offenen Meldungen, gleich-originiges PCM-WAV, ausdrücklicher Hinweiston-Schalter samt Blockade-/Reload-/Synchronisations-/Race-Pfad und automatischem Signal, langlebiges Audioelement, passive E-Mail-Anlagenkarte ohne aktive Mail-DOM-/Remote-Inhalte, Rufnamen-Auswahlliste des Fernmelders mit echtem Fokus, Filterung und Tastaturauswahl, inaktiver Melderauftrag mit abgemeldetem fachlich berechtigtem Ziel in der LdF-Auswahl, sichtbarer Status-/Informationswarnung und echtem POST/PRG-Erfolg, Matrixstandard- und Kennwortrichtlinien-Bestätigungen, responsive Adminübersicht mit elf Karten und acht Selbstregistrierungszeitfenstern, BOS-Disclosure, Logout, eine mobil nicht über dem Arbeitsbereich klebende vollständige Status-/Navigationsleiste sowie Erstellen, Download und zweistufiges Löschen eines Exports ohne horizontalen Seitenüberlauf bei exakt 390 × 844 CSS-Pixeln |
| Fachabnahme | kompletter Nachrichten-, Anhang-, PDF-, ETB-/TBB- und Restore-Ablauf |
| Betrieb | kontinuierliche Readiness, Logs, Restarts, Kapazität und Backup-Alter |
| Manuelle Herkunft | bei Bedarf bewusst gestarteter SVN-/Release-Manifestsabgleich unter `migration/`; kein CI- oder Produktfreigabegate |

Der HTTP- und Browsernachweis unterscheidet ausdrücklich drei Zustände:
Anonyme Fachaufrufe führen per 303 zum Bestandslogin, ein authentifiziertes
Konto ohne Fachberechtigung erhält weiterhin den echten Status 403 und ein
berechtigtes Konto die Fachseite. Für den mittleren Fall prüft das S1-Konto
die Nachweisung als vollständige, datenfreie Fehlerseite. Exakter
Berechtigungstext, `role="alert"`, genau eine Sitzungsleiste mit Logout, das
funktionsgefilterte Menü, der feste Übersichtslink sowie ein überlauffreies
Layout bei 1440 × 1000 und 390 × 844 CSS-Pixeln werden automatisiert geprüft;
ein echter Klick muss anschließend wieder zur angemeldeten Übersicht führen.
Maschinenantworten von Status-, Bild- und Downloadendpunkten bleiben davon
getrennt und werden weiterhin gegen ihre jeweiligen Protokolltypen geprüft.

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

Die Suite lintet den aktiven PHP-Bestand und führt die Prüfungen unter
`tests/php/` aus. Dazu gehören unter anderem:

- PHP-8.5-Laufzeit- und Legacy-Konstruktor-Kompatibilität,
- den positiven Containerbestand aus `Dockerfile` und
  `docker/app/verify-runtime-surface.sh`: Das vollständige dynamisch
  verwendete HS-Design, aus `design/mr` ausschließlich `folder_global.gif`,
  genau zwölf freigegebene `4fsym`-Assets sowie die enge aktive
  PDF-/Schrift-Teilmenge; `tests/static/runtime_image_surface.sh` beweist
  zusätzlich negative Altpfad-, Archiv-, Dokumentquell-, Passwortdatei- und
  Schriftfälle,
- `NULL`-/Zero-Date-Behandlung,
- Anmelde-, administrative Kontoanlage-, unveränderliche Funktionsbindungs-,
  Session- und Passwortregeln einschließlich exakter Aktivitätsgrenzen bei
  15 Minuten beziehungsweise 12 Stunden, fail-closed Behandlung fehlender,
  ungültiger und zukünftiger Zeitwerte sowie die Proxy-Peer-Vertrauensgrenze mit
  IPv4-/IPv6-CIDR-Allowlist,
- fehlende Laufzeit- und Umgebungsoptionen für Autosichtung oder eine
  Umgehung der verpflichtenden Ausgangssichtung,
- kanonische Reihenfolge, sichere URL-Auflösung, aktive Route, ausschließlich
  erlaubte symbolische Anmeldeziele und das rollenabhängige Ausblenden der
  spezialisierten Meldungsübersicht beziehungsweise Nachweisung; nach der
  Anmeldung sind nur die Links der modeabhängig wirksamen Funktionsmenge sichtbar,
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
  Statusänderung; der Aktivitätsmonitor reagiert nur auf echte
  Browserinteraktion, drosselt gemeinsam über Tabs und Frames auf einen POST
  pro Minute, verwendet CSRF und `keepalive`, besitzt keinen Intervall- oder
  Seitenlade-Heartbeat und leitet bei HTTP 401 zum Login,
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
- Nachrichten-IDs, modeabhängig wirksame Funktion, Rollen-/Objektregeln,
  Empfänger-Tokens, eigene Verfasser- und Verarbeitungsmarken, erlaubte
  Workflow-Aktionen, POST-/CSRF-Verträge, Prepared Statements, sichere
  UTF-8-/Legacy-Entity-Ausgabe und die inerten Payloads Quotes, Ampersand,
  `<script>` sowie SQL-ähnlicher Text,
- verpflichtende Rufnamen bei FM-Eingängen, nicht leere LdF-Übersetzungen,
  explizite LdF-Bestätigung des vom Fernmelder aufgenommenen Eingangswegs,
  begründungspflichtige atomare Korrektur bei unveränderlicher Aufnahmezeit und
  unveränderlichem Aufnahmezeichen des Fernmelders,
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
- Kategorien-Typen, positive IDs, modeabhängig wirksame Funktion,
  sessionabgeleitete Tabellenräume, Master-Rechte, doppelte Auswahllisten,
  HTML-Ausgabe sowie den Prepared-Statement-, Transaktions-,
  Nachrichten-Objektberechtigungs- und PRG-Vertrag,
- globalen Einsatz-Singleton, revisionsgesicherte Aktivierung,
  No-active-Eingabesperre, Einsatzzuordnung sämtlicher operativer Tabellen und
  einheitliche Statusbanner,
- Kontosperre und Kennwortreset mit gemeinsamem Login-Lock,
  Sitzungswiderruf, auditgebundenem Rollback und ohne Klartextweitergabe,
- Default, Grenzwerte und Unicode-Zeichenklassen der Kennwortrichtlinie,
  Argon2id für alle neuen und geänderten Kennwörter, die Grenze von 1024
  UTF-8-Bytes sowie zwei Werte mit gleichem 72-Byte-Präfix und unterschiedlichen
  Suffixen, die nur bei vollständiger Hashauswertung verschieden bleiben,
  globale Lockreihenfolge, optimistische Revision, zweistufige
  Adminbestätigung, prospektive Anwendung auf Anlage/Reset/Selbstregistrierung
  und ausdrücklich unveränderten Bestandslogin,
- Selbstregistrierungsmodi, feste Zeitfenster, exakte DB-UTC-Ablaufgrenze,
  Revision, kennwortfreies Audit, CSRF-/Basic-Auth-Adminsteuerung, gemeinsame
  Lockreihenfolge und den atomischen Konto-INSERT-Guard,
- Upload- und Anhangpfadvalidierung einschließlich strikt als
  `message/rfc822` erkannter und strukturell gültiger `.eml`-Dateien, fester
  20-MiB-Parsergrenze, Ablehnung umbenannter beziehungsweise fehlerhafter Mails
  sowie weiterhin nicht unterstütztem Outlook-`.msg`,
- funktions- und objektgebundene Dateiauslieferung samt vererbter
  Nachrichtenrechte, begrenzter Rechte für freie Anhänge, erneuter Prüfung bei
  Auswahl und finalem Nachrichtenspeichern sowie Traversal-, Symlink- und
  Header-Injection-Schutz; die passive E-Mail-Route ist zusätzlich an
  GET/HEAD, zweifache Objektprüfung, Integritätssnapshot, restriktive
  Sicherheitsheader, reine Metadaten interner Mail-Anlagen und einen
  authentifizierten Originaldownload gebunden,
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
  PDF-Einsatzdossiers mit allen neun wählbaren Abschnitten, sichtbaren
  JPEG-/PNG-Anlagen, verlustfrei Windows-1252-darstellbarem Text und
  seitenweise gerasterten PDF-Anlagen sowie passiv dargestellten
  RFC-822-E-Mails. Der Containervertrag prüft zusätzlich
  die GD-Leseunterstützung für GIF und BMP. Alle Originaldateien bleiben sicher
  eingebettet und gegen ihren Eingangsnachweis geprüft; beide
  Nachrichtenausgabepfade werden ein- und mehrseitig mit Poppler pixelgleich
  verglichen und auf stabilen Folgeseiteneinzug, sichtbare historische
  Empfänger, die festgelegte Bildbelegung sowie A4-Geometrie geprüft; ein
  eigener Maximalwert-Fall bindet beide gekürzten Kopfzeilen an
  ihre tatsächlichen Poppler-Bounding-Boxes.

`tests/php/email_attachment_security.php` prüft den begrenzten RFC-822-/MIME-
Parser unabhängig von Webserver und Datenbank. Die kanonische Fixture
`tests/fixtures/email-multipart-xss-utf8.eml` kombiniert RFC-2047-/RFC-2231-
Unicode, gefaltete Kopfzeilen, verschachteltes Multipart, HTML ohne
Plaintext-Alternative, zwei interne Anlagen sowie präparierte Skript-,
Ereignis-, JavaScript-URL- und Remote-Ressourcen. Akzeptiert werden nur die
decodierten/escaped Kopfzeilen, der passive Text und Name, MIME-Typ und Größe
der internen Anlagen. Aktive oder entfernte Inhalte dürfen nicht in die
Darstellung gelangen. Upload- und Dateizugriffstests ergänzen MIME-/Endungs-
und Strukturfehler, die feste 20-MiB-Grenze sowie die Beschränkung des
Originals auf eine Downloadantwort.

Ein Prozess-Exitcode ungleich null sperrt die Freigabe.

## Manueller Herkunftsnachweis

Die SVN-/Release-Manifeste unter `migration/` gehören bewusst nicht zur
statischen Suite und nicht zum regulären CI- oder Release-Gate. Bei einer
Herkunftsprüfung können sie ausdrücklich manuell geprüft werden:

```console
python3 migration/verify_provenance.py --self-test
```

Der heutige Produktnachweis entsteht aus statischer Suite,
Runtime-Surface-, Container-, Datenbank-, HTTP-, Browser-, Backup- und
Restore-Prüfung. Die Trennung und die Retention-Entscheidungen stehen unter
[Gepflegter Quellbestand](QUELLBESTAND.md).

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

Der am 3. August 2026 vollständig beendete Podman-Abschlusslauf führte
`bash tests/integration/ci.sh` mit allen 25 Migrationen bis einschließlich
Migration 119 aus und endete nach dem vollständigen Backup-/Restore-Roundtrip
mit Exitcode 0 und `CI integration: OK`. Sein protokollierter Kernstand lautet:

| Teilnachweis | Ergebnis |
| --- | --- |
| Schema | 42 Prüfungen |
| Einsatzdomäne | 69 Assertions |
| DV-Evidenz | 52 Assertions |
| DV-Operations einschließlich `STRICT`/`LOOSE`, Fernmeldeplan und inaktiver Melderziele | 243 Assertions, 95 Ereignisse |
| Optionale Zugangsschichten | 29 Assertions |
| Benutzerverwaltung | 98 Assertions |
| Kennwortrichtlinie gegen MariaDB | 66 Assertions |
| Selbstregistrierung | 31 Datenbank- und 28 Handler-Assertions |
| Zuordnungs-/Empfängermatrix | 74 Assertions |
| PDF-Einsatzdossier | 35 Assertions |
| Sichtbare Anhangdarstellung | 11 Assertions |
| Einsatzbezogene Nachrichtenvorschläge | 32 Assertions |
| Nachrichtensuche mit 10.000 Zielzeilen | 145 Assertions |
| Echter Browser | Chrome 150: öffentliche BOS- und Handbuchläufe, allgemeiner zwölfstufiger UI-Lauf, Auswahl eines abgemeldeten fachlich berechtigten Fernmelders mit Status und sichtbarem Informationshinweis, S6-Fernmeldeplan-Versionierung, Meldungsüberschrift und Nachrichtenvorschläge erfolgreich; mobile Exportverwaltung einschließlich nichtklebender Vollleiste und Löschung bei `390 × 844` CSS-Pixeln geprüft |

HTTP-Surface, Selbstregistrierung, Auth-Smoke, Logbücher, Kategorien,
Nachrichtenworkflow, Administrationsworkflow sowie Backup und Restore endeten
ebenfalls erfolgreich. Das verpflichtende Browser-Gate lief in Chrome 150.

Migration `119-inactive-messenger-dispatch.sql`, ihre Schema-/DV-Assertions
und die sichtbare Auswahl eines abgemeldeten Fernmelders sind Teil dieses
grünen Abschlussstands. Der Modus
`tests/browser/headless_ui.py --inactive-messenger` bestätigte im echten
Chrome einen fachlich berechtigten Fernmelder mit Status im LdF-Auswahlfeld
und nach Auswahl den sichtbaren Hinweis zur separaten Information.
Sein allgemeines authentifiziertes Fixture verwendet einen `LOOSE`-Einsatz
und genau eine fest zugewiesene S1-Kontofunktion; die getrennten S2-, S6- und
Fernmelder-Läufe verwenden ebenfalls jeweils genau eine feste Funktion. Damit
sind Navigation und Fachzugang für diese Einzelfunktionsfälle belegt. Ein
dediziertes Browserszenario für eine persönlich angenommene und ausgewählte
`STRICT`-Dienstbesetzung, die Bedienung der Modusumschaltung samt Warnanzeige
oder ein `LOOSE`-Konto mit mehreren festen/zusätzlichen Funktionen und
expliziter Auswahl „Schreiben als“ beziehungsweise „Lesen als“ enthält der
Lauf noch nicht. Diese Bedienpfade bleiben manuell beziehungsweise als eigene
Browserautomatisierung offen; Domänen-, Datenbank- und HTTP-Gates prüfen ihre
Autorisierungsgrenzen bereits automatisiert.

Die separate statische PHP-8.5-Suite endete mit Exitcode 0, lintete 229 aktive
PHP-Dateien und bleibt ein eigenständiger Abschlussnachweis. Der lokale
Podman-Integrationslauf fand nicht auf einem
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
verlangen alle fünfundzwanzig Ledgerzeilen einschließlich Version 119, die
kanonische Berechtigungsmodusspalte samt Guard-/Fachtriggern, die
Zusatzfunktionstabelle sowie die exakten drei Such-/Listenindizes. Migration 99 wird vollständig, nach einem
simulierten phasenweisen Abbruch und nach einer fremden Indexkollision
ausgeführt; erst der bereinigte Wiederanlauf darf den Ledgerstand schreiben.
Migration 100 wird gegen einen zuvor aktiv markierten Legacy-Benutzer
ausgeführt. Der Test belegt die exakt markierte nullable
`DATETIME(6)`-Spalte, den dreispaltigen Präsenzindex, die einmalige Löschung
der unbelegbaren SID-/IP-Metadaten und das Fehlen zurückgelassener
Hilfsroutinen.
Migration 110 wird mit historischen ETB-/TBB-Zeilen geprüft, deren globale
Primärschlüsselreihenfolge bewusst von ihrer Erfassungszeit abweicht. Der Test
verlangt je Einsatz und Buch die deterministische lokale Reihenfolge,
unveränderte Legacy-Texte, exakt zwei korrekt fortgesetzte Buchköpfe je
Einsatz, den `AFTER INSERT`-Trigger für neue Einsätze, die strukturierten
TBB-Spalten, eindeutige lokale Indexe, Nachrichten-/Korrekturbezüge,
den eindeutigen ETB-Anhangsindex, Append-only-Trigger und eine auf zehn Jahre
angehobene Abschlussfrist. Ein historischer Mehrfachbezug desselben Anhangs
muss das Upgrade explizit blockieren, ohne Daten oder Migrationsledger zu
verändern. Ein neu
angelegter Einsatz muss schon vor seinem ersten Buchsatz `ETB:1` und `TTB:1`
besitzen; konkurrierende erste ETB-/TBB-Inserts müssen unter der unveränderten
MariaDB-Standardisolation `REPEATABLE READ` lückenlos fortsetzen. Wird ein
Kopf kontrolliert entfernt, muss der Buch-Insert explizit scheitern, ohne ihn
selbst neu anzulegen oder eine Nummer zu verbrauchen. Ein
simulierter eigener DDL-Zwischenstand muss konvergieren; eine gleichnamige
fremde Spalte bleibt unverändert blockiert und darf keinen Ledgerabschluss
erzeugen. Der Zweitlauf muss vollständig idempotent sein.
Der gleiche MariaDB-Lauf prüft die Triggergrenze für aktive Schichten: eine
neue Funktion und mehrere unterschiedliche Besetzungen der Fernmelderfunktion
sind zulässig, eine bereits vorhandene andere Funktion bleibt dagegen bis zur geordneten
Übergabe gesperrt.

Migration 111 wird als historischer, durch Migration 112 abgelöster
Zwischenstand mit vorhandenem Legacy-Bestand, neuen manuellen und
automatischen ETB-/TTB-Zeilen sowie einer optionalen ETB-Zuordnung geprüft.
Historische Herkunft muss in allen neuen Feldern `NULL` bleiben. Neue manuelle
Zeilen müssen Schicht und Schreiberbesetzung derselben aktiven Schicht tragen;
der Trigger weist nicht angenommene Besetzungen, inaktive Schichten,
abweichende Benutzer-/Kürzel-/Funktionsidentitäten sowie inaktive oder
gesperrte Konten ab. Systemzeilen müssen eine Schicht, aber eine leere
menschliche Schreiber-ID besitzen. Der Zuordnungssnapshot muss aus einer
angenommenen Besetzung derselben aktiven Schicht mit ungesperrtem Konto
stammen; Abmeldung und Präsenzstatus dürfen die angenommene Besetzung nicht
fachlich entwerten. Der Snapshot muss einen gefälschten Browsertext
verdrängen. Neue
ETB-Referenzen müssen als kanonische lokale Nummer auf einen vorhandenen
einsatzgleichen Eintrag zeigen; bei Korrekturen müssen öffentliche Nummer und
internes Original übereinstimmen. Der Test simuliert eigene
DDL-Zwischenstände und fremde Kollisionen für Spalten, Indexe,
Fremdschlüssel und Trigger; nur der kanonische Wiederanlauf darf ihre
Ledgerzeile schreiben.

Migration 112 wird anschließend als strenger Vorgängervertrag geprüft. Sie legt die
optionalen Zugangsschicht- und Mitgliedstabellen kollisionssicher an und
ersetzt die letzten ETB-/TBB-Trigger. Positive Schreibfälle verlangen aktiven
Einsatz und feste Kontofunktion `ETB/Stab` oder `S2/Stab` für ETB sowie
die Funktion `Fernmelder` für TTB. Sie funktionieren ohne aktive Schicht und mit
`NULL` in den Legacy-Provenienzfeldern. Falsche Funktion/Rolle, gesperrtes
Konto und fehlender aktiver Einsatz scheitern. Der Zugangsvertrag prüft
unzugeordnetes Konto, Mehrfachzuordnung per OR, Aktivierung ohne Sitzung,
Deaktivierung mit Sitzungswiderruf sowie Vorrang der manuellen Sperre. Nur der
kanonische Wiederanlauf darf die achtzehnte Ledgerzeile schreiben. Zweitlauf,
`verify.sql`-Prüfung und vollständiger Schema-Migrator-Integrationstest sind
grün.

Migration 113 ergänzt danach den aktuellen Kennwortrichtlinien-Vertrag. Der
Schema-Test verlangt die exakt markierte InnoDB-/`utf8mb4_unicode_ci`-
Singleton-Tabelle, neun kanonische Spalten, alle CHECK-Constraints, genau die
eine Defaultzeile `12/0/0/0/0` und eine monotone Revision. Fremde
gleichnamige Tabellen, abweichende Spalten oder Werte blockieren, ohne einen
Ledgerabschluss zu erzeugen. Nur der kanonische Wiederanlauf darf die
neunzehnte Ledgerzeile schreiben; Zweitlauf, `verify.sql` und Laufzeit-
Readiness müssen denselben Zustand bestätigen. Bestehende Kontohashes,
Sitzungen und die bisherigen achtzehn Ledgerzeilen bleiben byte- und
wertgleich.

Migration 114 ergänzt anschließend den Selbstregistrierungsvertrag. Der
Schema-Test verlangt die exakt markierte InnoDB-Singleton-Tabelle, sechs
kanonische Spalten, beide exakten CHECK-Klauseln und die Upgrade-Startzeile
`ENVIRONMENT/NULL/0`. Fremde gleichnamige Tabellen oder manipulierte
Constraints blockieren ohne Ledgerabschluss. Nur der kanonische Wiederanlauf
darf die zwanzigste Ledgerzeile schreiben; Zweitlauf, `verify.sql` und
Readiness bestätigen denselben Zustand. Die bisherigen neunzehn Ledgerzeilen
und vorhandenen Konten bleiben unverändert.

Der Migratortest trennt diesen SQL-Vertrag ausdrücklich vom
Neuinstallationsabschluss des Runners: Eine vollständig leere Datenbank startet
selbst mit `ESTAB_ALLOW_SELF_REGISTRATION=true` als
`DISABLED/NULL/1/fresh-install`. Ein nach Migration 114 stehen gebliebener
checksumgebundener Marker auf `applying` wird gemeinsam mit der pristine Zeile
idempotent abgeschlossen. Manipulierte Marker-Prüfsummen und die unmögliche
Kombination aus `applied`-Marker und pristine `ENVIRONMENT`-Zeile blockieren
unverändert. Das aus dem Legacy-Fixture migrierte Schema besitzt keinen
Fresh-Marker und behält dagegen nachweislich `ENVIRONMENT/NULL/0/migration-114`.

Migration 115 ergänzt als einundzwanzigste Ledgerzeile den
Berechtigungsmodus. Der Schematest verlangt die exakt markierte
`ascii_bin`-Spalte mit `STRICT`-Default, ausschließlich gültige Werte, beide
Guard-Trigger und die sechs modebewussten Fachtrigger. Alle vorhandenen
Einsätze müssen nach Upgrade `STRICT` sein. Eigene DDL-Zwischenstände dürfen
konvergieren; eine fremde Spalte, ein fremder Guard-Trigger oder ein nicht
eindeutig erkannter Vorgängertrigger muss ohne Ledgerabschluss blockieren.
Der Integrationsteil prüft in getrennten, modefesten Fixtures den
unveränderten strengen Vertrag und den lockeren Positivfall bei weiterhin
aktivem, ungesperrtem Konto sowie die
in beiden Modi negativen Einsatz-, Zustands-, Identitäts-, Referenz- und
Append-only-Grenzen. Ein Zweitlauf darf weder Modus noch Fachbestand ändern.

Migration 116 ergänzt als zweiundzwanzigste Ledgerzeile die einmalige
Standardbefüllung der globalen Kategorien. Der Schematest verlangt für eine
frische und eine migrierte leere Liste exakt `Allgemein` sowie `EA1` bis
`EA6`, bindet ihre Beschreibungen und beweist den unveränderten Zweitlauf. Ein
Legacy-Katalog mit bereits vorhandenen eigenen Kategorien und Zuordnungen muss
dagegen vollständig unverändert bleiben; es dürfen insbesondere keine
scheinbar fehlenden Vorgaben ergänzt werden. Weil die Saat nur mit der
checksumgebundenen Einmalmigration ausgeführt wird, bleiben auch spätere
Änderungen oder Löschungen nach weiteren Migrator- und Containerstarts
erhalten.

Migration 117 ergänzt als dreiundzwanzigste Ledgerzeile die ausschließlich
inhaltserhaltende Transition `ENTWURF -> ERSETZT` für Fernmeldepläne. Der
Schematest verlangt unveränderte Kopf- und leere Freigabefelder, weist jede
kombinierte Mutation ab und prüft, dass die vorhandenen Eintragstrigger die
archivierten Wege anschließend weder ändern noch löschen lassen. Zweitlauf,
Readiness und `verify.sql` müssen denselben Trigger- und Ledgerstand bestätigen.

Migration 118 ergänzt als vierundzwanzigste Ledgerzeile den modeabhängigen
Autorisierungsvertrag. Der Schematest muss die
kollisionsgeprüfte Tabelle `nv_benutzer_zusatzfunktionen`, ihren eindeutigen
Kontofunktionsschlüssel und den ersetzten ETB-/TTB-, Fernmeldeplan- und
Meldertriggerstand belegen. In `STRICT` müssen manuelle Schreibvorgänge ohne
aktive, persönlich angenommene und ausgewählte Besetzung scheitern; die
passende Besetzung muss funktionieren und Buchzeilen mit Schicht- und
Schreiberprovenienz erzeugen. In `LOOSE` muss dasselbe ohne formale Schicht,
aber nur mit passender fester oder expliziter Zusatzfunktion gelingen. Ein
fachfremdes Konto und eine in `STRICT` nur als Zusatzfunktion vorhandene
Fähigkeit müssen scheitern. Systembuchzeilen dürfen weiterhin ohne
persönlichen Schreiber entstehen. Fremde Tabellen-/Triggerkollisionen,
unterbrochener Wiederanlauf und Zweitlauf sind fail-closed beziehungsweise
idempotent zu prüfen.

Migration 119 ergänzt als fünfundzwanzigste Ledgerzeile die gezielte
Entkopplung von fachlicher Melder-Eignung und Live-Sitzung des Zielkontos. Der
Schematest muss den eindeutig erkannten Trigger aus Migration 118 und den
kanonischen Zieltrigger akzeptieren, einen fehlenden oder fremden
gleichnamigen Trigger aber ohne Ledgerabschluss blockieren. `STRICT` muss
weiter eine persönlich angenommene Fernmelder-Besetzung der aktiven
Dienstschicht für das Ziel verlangen; `LOOSE` weiterhin feste beziehungsweise
zusätzliche Fernmelderfunktion und ein gegebenenfalls aktives
Zugangsschicht-Gate. In beiden Modi muss die Beauftragung eines ungesperrten,
fachlich geeigneten Zielkontos auch mit inaktiver Präsenz oder ohne Sitzung
gelingen. Gesperrte, fachfremde und modefremde Ziele bleiben negativ. Die
persönliche Übernahme ohne eigene Sitzung muss weiterhin scheitern. Zweitlauf,
unterbrochener Wiederanlauf, `verify.sql` und Laufzeit-Readiness müssen den
25-zeiligen Ledger und exakt den neuen Triggerstand bestätigen.

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
Benutzerverwaltungs-API. Sie prüft außerdem die Basic-Auth-geschützte
Kennwortrichtlinien-Seite, CSRF, Vorher-/Nachher-Vorschau, ausdrückliche
Bestätigung, Konflikt bei veralteter Revision und dass ein abgewiesenes
Kennwort weder Konto, Hash, Sitzung noch Audit verändert. Zusätzlich steuert
der Browser-Akzeptanztest einen
echten Chrome-/Chromium-Prozess.
Der Hauptstack läuft dabei durchgehend mit
`ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=false` und weist auch für angeblich
gleich-originige tokenlose Zugangsdaten HTTP 403 nach. Erst danach wird nur
der App-Container kurz mit dem expliziten Wert `true` neu erstellt:
`tests/integration/legacy_login_http.sh` belegt genau einen historischen
Ein-Kennwort-Login, die weiterhin geschlossene Cross-Site-Grenze und den
erreichbaren Führungsstellenbereich. Der Login übernimmt ausschließlich die
feste Kontofunktion. Im lockeren Modus ist keine Hutauswahl nötig; ein
strenger operativer Lauf muss nach dem Login zusätzlich die angenommene
Dienstbesetzung auswählen. Der Lauf endet über den
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

### Einsatzdomäne, Benutzerverwaltung, Kennwortrichtlinie und PDF-Dossier

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

Die Berechtigungsmodustests erweitern diesen Vertrag für
`nv_einsaetze.estab_permission_mode`. Sie müssen mindestens nachweisen:

- Neuinstallation, Legacy-Upgrade und normale Einsatzanlage ergeben ohne
  ausdrückliche Auswahl `STRICT`.
- Unmarkierte direkte SQL-Änderungen und kombinierte Änderungen anderer
  Einsatzfelder werden von den Guard-Triggern abgewiesen; der Anwendungsweg
  kann `LOOSE` nicht ohne Warnungsbestätigung anlegen oder setzen. Ein fremder
  Spalten-/Triggerzustand blockiert Migration 115 ohne stilles Umschreiben.
  Ein Principal mit beliebigen SQL-Rechten und der Fähigkeit, Sessionvariablen
  selbst zu setzen, liegt ausdrücklich außerhalb dieser Triggergrenze und muss
  als vertrauenswürdiger Betreiberzugang geschützt werden.
- Die Basic-Auth-/CSRF-geschützte Administration bindet Einsatz-ID,
  erwarteten Altmodus und globale Revision. Ein veraltetes Formular, ein
  Parallelwechsel oder ein zwischenzeitlicher Einsatzwechsel liefert einen
  Konflikt. Nur ein echter Wechsel erhöht die Revision und schreibt den
  Vorher-/Nachher-Auditdatensatz.
- Eine echte Modusänderung gelingt nur in einem offenen Einsatz ohne jede
  operative oder formale Eintragung. Die erste solche Eintragung sperrt den
  Modus dauerhaft; auch das spätere Löschen einzelner Daten darf ihn nicht
  wieder freigeben. Das erneute Speichern desselben Modus bleibt ohne neue
  Revision und ohne Auditereignis idempotent. Dafür werden ein vollständig
  leerer Umschalt-/Sperr-Fixture sowie getrennte, danach unveränderliche
  `STRICT`- und `LOOSE`-Fixtures verwendet.
- Im strengen Modus muss ein fachlicher Positivfall eine aktive formale
  Dienstschicht, persönliche Annahme und die exakte Auswahl der passenden
  Besetzung belegen. Feste Kontofunktion oder Zusatzfunktion allein dürfen
  ihn nicht autorisieren. Im lockeren Modus funktionieren dieselben
  Workflow-, ETB-/TTB-, S6-Plan- und Melderaktionen ohne formale Schicht nur,
  wenn die benötigte Funktion fest oder als explizite Zusatzfunktion
  zugewiesen ist. Ein lediglich aktives fachfremdes Konto bleibt negativ.
- In beiden Modi bleiben fehlende/abgelaufene Sitzung, manuelle Kontosperre,
  kein aktiver oder geschlossener Einsatz,
  fremde Einsatz-ID, falsches CSRF, ungültige Eingabe, falscher
  Workflowzustand, fremder Sperrinhaber, ungültige Beziehungen,
  Anhangintegrität, Append-only- und Aufbewahrungsgrenzen negative Fälle. Ein
  allein deaktivierter Gruppenzugang ist nur in `LOOSE` negativ; `STRICT`
  verwendet stattdessen den Dienstbesetzungsstatus.
- Lese-, Menü- und Schreibrechte behalten ihre Funktions-/Objektregeln. Ein
  zurückgewiesener Ausgang darf nur durch eine im jeweiligen Modus wirksame
  passende Funktion übernommen werden; Ereignisnachweis und Datensatz müssen
  ursprüngliche und neue Verantwortlichkeit unterscheidbar bewahren. Statusleiste,
  Führungsstellenansicht, Administration und PDF-Dossier zeigen den
  gespeicherten Modus; `LOOSE` ist dabei als Warnzustand erkennbar.
- Vorhandene Anlagen einer mit passender Zusatzfunktion übernommenen Korrektur müssen
  über die exakte Einsatz-/Nachrichtenbindung sichtbar, vorschau- und
  herunterladbar bleiben. Fremde Nachrichten, originlose Archivdateien, ein
  anderes Konto sowie der Entzug der maßgeblichen Zusatzfunktion müssen
  denselben Scope an Formular-, Final-Write- und Streaming-Grenze verwerfen.
  Ein getrennter `STRICT`-Fixture belegt, dass dieselbe Zusatzfunktion dort
  keine Berechtigung erzeugt.

Ein Modustest darf nicht nur einen ausgeblendeten Link prüfen. Er muss den
Controller beziehungsweise Domänendienst und die modebewussten
Datenbanktrigger jeweils positiv und negativ erreichen. Rollenbezogene
Integrationstests verwenden getrennte, unveränderliche `STRICT`- und
`LOOSE`-Einsätze; kein bereits betriebener Fixture wird für den nächsten Test
zurückgesetzt.

Im finalen Lauf bis Migration 119 stand Chrome 150 zur Verfügung. Der
allgemeine UI-Lauf verwendete einen `LOOSE`-Einsatz mit genau einer festen
S1-Kontofunktion; auch die getrennten S2-, S6- und Fernmelder-Fixtures besaßen
jeweils nur eine feste Funktion. Der zusätzliche Melder-Fixture belegte einen
abgemeldeten, fachlich berechtigten Fernmelder mit Statusanzeige und sichtbarem
Informationshinweis. Dieser Lauf enthält weiterhin keinen
dedizierten Browsernachweis für eine ausgewählte `STRICT`-Dienstbesetzung, die
Umschaltung zwischen `STRICT` und `LOOSE` samt sichtbarer Moduswarnung oder die
explizite Auswahl einer festen beziehungsweise zusätzlichen Funktion bei
einem `LOOSE`-Mehrfunktionskonto. Die statischen Verträge, die echte MariaDB
und authentifizierte HTTP-Anforderungen waren erfolgreich; diese
Modusbedienung gehört bis zu einem eigenen automatisierten Szenario in die
manuelle Fachabnahme.

Im Haupt-CI-Bestand prüft `tests/integration/user_admin.php` zunächst
Kontoanlage, serverseitig abgeleitete feste Funktionszuordnung,
Legacy-Reparatur, Neuzuweisung, Kontosperre, Entsperren, Kennwortreset,
Sitzungswiderruf, Login-Lock und Audit gegen die echte MariaDB und den
produktiven Legacy-Login. Der Test führt sowohl einen echten Bestandslogin als
auch die ausdrücklich aktivierte Self-Registration aus und beweist für beide
Auditzeilen die exakte `sha256:`-Referenz der rotierten Sitzung sowie die
Abwesenheit von Klartext-SID und Kennwort. Das funktioniert bewusst auch ohne
aktiven Einsatz.
`tests/integration/password_policy.php` verwendet mehrere echte
MariaDB-Verbindungen. Der Test liest den Default 12 ohne Zeichenklassen,
ändert die Richtlinie revisions- und auditgebunden, weist ungültige sowie
veraltete Änderungen ab und erzwingt Rollback bei Auditfehler. Unter einer
verschärften Richtlinie müssen schwache Kontoanlage, Reset und aktivierte
Selbstregistrierung ohne Konto-, Hash-, Sitzungs- oder Auditänderung scheitern;
gültige Werte werden ausschließlich mit Argon2id gespeichert. Zwei Kennwörter
mit gleichem 72-Byte-Präfix und unterschiedlichen Suffixen bleiben getrennt und
belegen damit, dass der vollständige Wert statt einer bcrypt-Kürzung in den
Hash eingeht. Serverseitig werden die Grenze von 1024 UTF-8-Bytes und eine
konfigurierbare Mindestlänge von 8 bis 128 Unicode-Codepoints geprüft; das
Browserfeld erlaubt 1024 Eingabeeinheiten und zählt die Mindestlänge exakt in
Unicode-Codepoints; die Serverprüfung bleibt verbindlich. Titlecase erfüllt
die Großbuchstabenpflicht, Unicode-Steuerzeichen werden abgewiesen und
Formatzeichen einschließlich ZWJ bleiben zulässig. Ein bereits vorher
vorhandenes kürzeres Kennwort bleibt beim Bestandslogin gültig. Klartext und
eindeutig verifizierbare Alt-Hashes werden nach erfolgreicher Anmeldung auf
Argon2id umgestellt. bcrypt wird nur bei einem eingegebenen Kennwort unter 72
UTF-8-Bytes automatisch migriert; ab 72 Bytes bleibt der ambivalente Hash bis
zu einem administrativen Reset unverändert. Stärkere und gemischte
Argon2id-Kosten werden nicht zurückgestuft, vollständig schwächere Profile
werden hochgestuft. Ein
konkurrierender Richtlinienwriter darf Kontoanlage oder Reset nicht mit einem
überholten Stand überholt werden. Die Testbereinigung stellt die kanonische
Auslieferungsrichtlinie wieder her.

`tests/integration/self_registration.php` prüft mit 31 Assertions die drei
persistenten Modi, exakte Datenbank-UTC-Grenzen, Revision, Advisory-Lock und
atomaren Audit. `tests/integration/self_registration_handler.php` führt den
produktiven Konto-Handler mit 28 Assertions aus. Dazu gehören ein während der
Verarbeitung ablaufendes und ein parallel deaktiviertes Zeitfenster; in beiden
Fällen bleiben Konto, Kennworthash, Sitzung, Audit und dynamische Tabellen aus.
`tests/integration/self_registration_http.sh` ergänzt Basic Auth, Session-CSRF,
Bestätigung, feste Zeitfenster, Revision und den sichtbaren Verwaltungszustand.

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
Anhangdatensatz und macht den Export-Einsatz wieder historisch. Die Anlage des
ausgewählten Einsatzes ist ein zweiblättriges PDF. Der
konsistente Read-only-Snapshot muss exakt dessen vier Datensätze liefern. Aus
der erzeugten PDF wird der tatsächliche `/EmbeddedFile`-Stream anhand seiner
deklarierten Länge extrahiert und bytegleich samt SHA-256 mit der beim Eingang
gebundenen Fixture verglichen; Marker und Datei des anderen Einsatzes dürfen
nicht vorkommen. Beide Originalseiten müssen zusätzlich in gleicher
Reihenfolge sichtbar sein und die Renderstatistik muss zwei sichtbare,
inhaltlich gerenderte Seiten sowie keine Hinweisseite ausweisen. Anschließend
ersetzt der Test die Datei durch gleich viele
andere Bytes. Sowohl ein erneutes Laden als auch das Einbetten aus dem zuvor
geladenen Bundle müssen wegen des unveränderlichen SHA-256-/Größennachweises
scheitern. Derselbe Bytewechsel muss im produktiven Abschluss-Preflight als
Integritätsfehler erscheinen; auch der tatsächliche Aufruf zum formalen
Abschluss wird mit genau diesem Blocker zurückgewiesen.
Der Exporttest lädt denselben historischen Einsatz außerdem einmal als
Gesamtbuch und einmal für eine einzelne Dienstschicht. Im zweiten Lauf dürfen
nur ETB und TBB auf die gespeicherte Schicht-ID eingeschränkt sein;
Nachrichten, Anhänge, Nachweise, Dienstbetrieb, S6-Pläne, Melderläufe und
Betriebsereignisse müssen einsatzweit unverändert bleiben. Deckblatt und
Auditdetail werden gegen Nummer, Bezeichnung, Status und Zeiten der gewählten
Schicht geprüft; eine Schicht eines anderen Einsatzes sowie nicht kanonische
Auswahlwerte müssen scheitern.
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
Admin-POST zum formalen Einsatzabschluss. Die Antwort muss HTTP 409 und genau
den Anhang-Integritätsblocker enthalten; Einsatzstatus und alle fachlichen
Nachweisdaten müssen dabei unverändert bleiben.

Der davon unabhängige Rendervertrag erzeugt mit
`tests/php/pdf_template_render_fixture.php` aus exakt demselben
Nachrichtendatensatz und derselben 5×4-Empfängermatrix einen Einzelvordruck,
eine direkte Dossier-Nachrichtenseite, beide Varianten mit mehrseitigem Inhalt,
mehrseitige eigenständige ETB-/TBB-Formularfixtures und ein vollständiges
Dossier mit den neun Abschnitten ETB, TBB, Nachrichtenvordrucke, Anhänge,
Nachrichtenereignisse, Dienstbetrieb, S6-Fernmeldepläne, Melderläufe und
Betriebsereignisse. Das vollständige Dossier enthält zusätzlich eine
durchsuchbare Textanlage und eine sichtbare JPEG-Anlage; beide Originale
bleiben separat eingebettet.

Sieben zusätzliche Nachrichtenvordrucke bilden eine leere Medienauswahl sowie
`Fu`, `Fe`, `FAX`, `FS`, `@` und `Me` ab. `tests/static/pdf_render.sh` rendert
für den tatsächlichen Weg im Aufnahmevermerk und den vorgesehenen
Beförderungsweg jedes der fünf sichtbaren Felder einzeln. Gegen die leere
Referenz darf nur das zugehörige Feld abweichen; `FS` und `@` müssen beide im
DFÜ-Feld erscheinen. Schmale Poppler-Halos auf allen vier Seiten beweisen
zusätzlich, dass das Kreuz einschließlich Strichstärke vollständig innerhalb
des quadratischen Rahmens bleibt.

`tests/integration/pdf_attachment_render.php` läuft im produktiven
App-Container und bindet damit die tatsächlichen GD-, Poppler- und
`prlimit`-Abhängigkeiten. Er erzeugt eine zweiblättrige PDF-Anlage und ein
transparentes PNG, verlangt daraus genau drei geordnete Rasterseiten sowie
passende Werte für `attachment_visible_count`, `attachment_visible_pages`,
`attachment_rendered_count`, `attachment_rendered_pages` und
`attachment_information_pages`. Beide Eingangsdateien werden aus dem
`EmbeddedFile`-Katalog wieder bytegleich verglichen. Vor und nach dem Lauf
muss die Menge privater `estab-pdf-render-*`-Arbeitsverzeichnisse identisch
sein. Auch ein absichtlich beschädigtes, aber korrekt gehashtes PDF muss ohne
Restdatei fail-closed abgewiesen werden.

`tests/php/incident_pdf_security.php` ergänzt die Dateigrenze: Der aus den
stabil gelesenen und später eingebetteten Bytes per Fileinfo erkannte MIME-Typ
muss mit der Deklaration übereinstimmen. Der Test belegt eine sichtbare
verlustfreie Textanlage und die ehrliche Hinweisseite eines Binärformats;
MIME-/Endungsfehler, ein Text mit mehr als 200 erforderlichen Seiten und
fehlende eindeutige EmbeddedFile-Zuordnungen scheitern vor unkontrollierter
Seitenerzeugung. Der statische Runtime-Vertrag verlangt GD-Leseunterstützung
für JPEG, PNG, GIF und BMP sowie Poppler und `prlimit` im App-Image.

`tests/static/pdf_temp_cleanup.sh` beweist den Startup-Janitor getrennt. Nur
vollständig kanonische, alte, flache und korrekt besessene Arbeitsverzeichnisse
werden entfernt. Aktuelle Verzeichnisse sowie Kandidaten mit unerwarteter
Datei, Unterverzeichnis, unsicherem Modus, Symlink, Hardlink, ungültigem Namen
oder falscher Wurzel bleiben vollständig erhalten beziehungsweise werden
abgewiesen. `tests/static/runtime_image_surface.sh` bindet `/tmp`, 1.440 Minuten
und `www-data` zusätzlich an den produktiven Entrypoint.

`tests/static/pdf_render.sh` verlangt für jede ETB-Seite A4 hoch, „Fb Fü 2“,
vier feste Spalten, lokalen Seitenzähler sowie die Linien für Leiter/-in
Führungsstelle und ETB-Führer/-in. Für jede TBB-Seite verlangt er A4 quer,
„Fb Fü 44“, sieben feste Spalten, Fernmeldebetriebsstelle/Arbeitsplatz, lokalen
Seitenzähler und die LdF-Linie. Textmarker und Bounding Boxes müssen in ihrer
vorgesehenen Spalte liegen; globale Legacy-Primärschlüssel und ungelöste
Seitenplatzhalter dürfen nicht erscheinen. Lange Einträge müssen Formkopf,
lokale Nummer und „Fortsetzung“ auf Folgeseiten wiederholen. Legacy-Aktion und
-Bemerkung bleiben sichtbar, ohne fehlende strukturierte Inhalte zu erfinden.
Bei neuen strukturierten Zeilen muss jeder Inhalt exakt einmal in seiner
Fachspalte stehen. Die redundante Kompatibilitätszusammenfassung aus
`tbb_aktion` darf nicht zusätzlich in der Betriebsspalte erscheinen;
`tbb_bemerk` muss als eigenständige Bemerkung auch bei einer strukturierten
Zeile genau einmal dort stehen. Der ETB-Zeitwert muss aus
`estab_recorded_at`, der TBB-Zeitwert
aus `estab_event_time` stammen. Zusätzliche geschlossene ETB-/TBB-Fixtures
müssen den diagonal gestrichenen, als „Nicht beschriebener Bereich“
markierten Rest der letzten Formularseite enthalten; offene Fixtures dürfen
diese Abschlussmarkierung nicht erhalten.

Die Prüfung weist Nachrichtenvordruck-Seiten ohne Bilder nach. Außerhalb der
ausdrücklich sichtbaren Anlagenseiten ist im Gesamtdossier ausschließlich das
bestehende 400-x-396-Pixel-THW-Kopfzeichen der ETB-/TBB-Formblätter zulässig;
eine Textanlage bleibt bildfrei und die JPEG-Seite muss exakt ein Bildobjekt
mit den erwarteten Abmessungen enthalten. Sie rendert alle Varianten mit
144 dpi. Jede eigenständige ETB-/TBB-Seite muss pixelgenau mit ihrer
entsprechenden Seite im vollständigen Dossier übereinstimmen. Ein- und mehrseitige
Nachrichtenvordruckvarianten werden ebenfalls vollständig bytegenau
verglichen; bei der Nachrichtenseite im vollständigen Dossier bleibt nur der
absichtlich dossierglobale dynamische Seitenzähler ausgespart. Anschließend
extrahiert `pdfdetach` die eingebetteten, am Eingang gebundenen Text- und
JPEG-Originale und `cmp` vergleicht sie mit den Quelldateien. Die sichtbaren
Anlagenseiten müssen Dateiname, MIME-Typ, Größe und SHA-256 nennen;
Anlagenverzeichnis und Deckblatt zusätzlich den Integritätsstatus. Bei Legacy
lautet er
ausdrücklich „Integrität beim Eingang nicht belegbar“. Die CI bewahrt PDFs,
PNGs, Text- und Werkzeuginformationen 14 Tage als Nachweisartefakt auf.
Ein ETB-Anhang muss dabei im Fb Fü 2 und Anlagenverzeichnis die abgeleitete
Nummer `ETB {einsatz_id}-{estab_book_lfd}-1` tragen, während sein separates
Ablagekennzeichen erhalten bleibt. Ein mehrfach verknüpfter Anhang muss den
Export als mehrdeutigen Datenbestand blockieren.
Eine nur für Websuche und Arbeitssteuerung gespeicherte
ETB-Bearbeitungszuordnung ist als Negativmarker im Fixture enthalten und darf
in keinem amtlichen Fb-Fü-2-Text erscheinen. Der Gesamtbuch-/Schichtumfang muss
hingegen auf dem Deckblatt sichtbar und in der Schichtvariante mit neu
berechneten ETB-/TTB-Seitenzahlen konsistent sein.

Der gezielte Stand dieser Verträge umfasst zusätzlich den Containerlauf
`PDF attachment render integration: OK`. Die Sicherheits- und
Template-Fixtures sowie der
Poppler-Rendervergleich sind ebenfalls grün. Der Renderfall enthält eine
schichtübergreifende Korrektur: Ein einsatzgebundener Self-Join muss deren
globale Original-ID zur lokalen Buchnummer auflösen, ohne globale
Primärschlüssel als laufende oder Korrekturbezugnummer zu drucken. Die
zugehörigen PHP-Dateien linten. Der MariaDB-Integrationstest ist mit 35
Assertions im frischen Gesamt-Compose-Lauf grün; die statischen und visuellen
Teilläufe ersetzen diesen Lauf nicht.

Die Fixture ruft für den Einzelvordruck denselben produktiven
`render_message_form_document()`-Dienst auf wie der aktuelle Download unter
`/4fach/vordrucke.php`; der produktive Archivgenerator delegiert ebenfalls an
diese Methode. `tests/php/generated_form_security.php` bindet zusätzlich die
vollständige Matrixvalidierung, den abgeschlossenen und gedruckten
Aktiveinsatz-Datensatz, den strikt skalaren `layout=current`-Schalter und den
weiterhin getrennten Archivstream. Seine TBB-Unterabfrage, das
Nachrichtendetail und der Dossierexport akzeptieren für die gedruckte lokale
Nummer ausschließlich den exakten automatischen Typ `nachricht`; ein
referenzierter Korrektureintrag darf die Nummer nicht ersetzen. Im echten
HTTP-Smoke werden beide Pfade
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
`tests/integration/dv_operations.php` ergänzt diesen Vertrag um die
modeabhängige operative Identität: Die feste Kontofunktion wird nicht
umgeschrieben, während in `STRICT` eine ausgewählte Dienstbesetzung und in
`LOOSE` eine Zusatzfunktion die wirksamen Fachbereiche erweitern kann. Ein
Konto ohne wirksame Nachrichtenfunktion darf keine fachfremden Tabellenräume
nutzen:

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
  Betriebsereignis, in `STRICT` nach aktiver formaler Dienstschicht mit
  Objekttyp `DIENSTSCHICHT`, in `LOOSE` ohne formale Dienstschicht mit
  Objekttyp `EINSATZ`, und keine unevidenzierte Status-0-Nachricht; der nächste echte
  Vordruck folgt unmittelbar auf den reparierten Papierwert und beide
  Nachweisketten bleiben gültig,
- ein fremdes Fernmelderkürzel kann weder Sperre noch Save übernehmen und beim
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
- Übersicht, BOS-Infos und das öffentliche Web-Handbuch unter `/handbuch/`,
- HTTP 403 für interne beziehungsweise abgeschaltete Provisionierungspfade,
- HTTP 410 für unsichere historische Direkt-Upload-Endpunkte,
- HTTP 401 für den anonymen Administrationszugriff,
- HTTP 403 für `PATH_INFO` hinter einem PHP-Endpunkt; ein gültiger
  Bestandslogin eines Fernmelderkontos mit CSRF darf über
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
  ETB/TBB; jede operative Seite wird dabei mit der nach Einsatzmodus
  wirksamen Funktion aufgerufen, der rechte
  `mainframe` vermeidet Duplikate und anonyme Fachseiten bleiben ohne
  vorgetäuschte eStab-Identität,
- in `STRICT` Auswahl einer angenommenen Besetzung der aktiven Dienstschicht,
  in `LOOSE` direkter Einstieg mit fester und zusätzlicher Kontofunktion;
  weiterhin HTTP 403 für eine wirksame S1-Funktion auf Meldungsübersicht und
  Nachweisung, positive Gegenproben mit S2, LdF beziehungsweise Fernmelder,
- jeder normale operative Schreibpfad revalidiert Konto, wirksame Funktion,
  serverseitig abgeleitete Rolle, Sperrstatus und aktiven Einsatz; positive
  `STRICT`-Fälle erfordern die ausgewählte aktive Besetzung, `LOOSE`-Fälle
  funktionieren ohne formale Schicht nur mit fester oder Zusatzfunktion,
- HTTP 405 für Logout per GET, HTTP 403 bei fehlendem oder falschem CSRF,
  Cookie-/Sitzungsende und 303-Rückleitung bei Erfolg sowie die
  SID-Grenze, durch die eine alte Sitzung eine neuere Anmeldung desselben
  Kontos nicht deaktiviert,
- HTTP 405 für den Aktivitätsendpunkt per GET, HTTP 401 ohne gültige
  Fachsitzung und HTTP 403 bei fehlendem oder falschem CSRF; ein gültiger
  SID-gebundener POST aktualisiert ausschließlich den eigenen UTC-Zeitstempel,
  der Statusfragment-Poll lässt ihn dagegen bytegleich, nach 15 Minuten zeigt
  die Übersicht „Inaktiv“ und nach 12 Stunden widerruft die nächste
  Authentisierungsprüfung SID sowie Online-Metadaten und verlangt den Login,
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
- Durchlaufen des gemeinsamen Uploaddiensts sowohl im bisherigen Archivpfad
  des Fernmelders als auch direkt im ausgefüllten Nachrichtenvordruck mit
  einem echten JPEG
  über der früheren 5-MiB-Grenze, dessen Browsername auf `.JPEG` endet;
  Datenbank und Download müssen die normalisierte Endung `.jpeg`,
  Benutzerkürzel, MD5, SHA-256, Bytezahl, MIME-Typ und unveränderten
  Dateiinhalt nachweisen. Die Vorschau muss die Bildbytes tatsächlich
  dekodieren und auf 80 × 80 Pixel skalieren. Ein über 20 MiB großes JPEG und
  eine nur in `.JPEG` umbenannte Textdatei müssen mit ihrem konkreten Grund
  abgewiesen werden. Die zweiphasige Fehlerbereinigung muss finalisierte Zeilen
  erhalten, eine eigene Status-8-Zeile vor dem Löschen atomar als Status 2
  beanspruchen, unfertige Bytes vor Freigabe des internen Namens entfernen und
  bei fehlgeschlagenem Löschen die unsichtbare Status-2-Cleanup-Zeile
  zurückbehalten. Der
  Chrome-Test muss den vorbereiteten Archivdialog auch ohne ausgewählte Datei
  abbrechen und die Reservierung freigeben können. Die direkte Rückgabe nach
  Upload oder Archivauswahl muss Anschrift, sämtliche markanten Eingaben,
  Vermerk sowie alle über Kästchen gewählten Empfänger als weiterhin
  absendbare Formularwerte enthalten; eine zusätzliche
  Durchschriftenauswahl darf weder gespeichert noch wiederhergestellt werden,
- bedingte Übermittlungsart im Stab: Ohne aktivierte **Gesprächsnotiz** müssen
  Telefon, Funk, Telefax, DFÜ und Kurier/Melder gesperrt bleiben. Nach dem
  Aktivieren sind sie direkt im Vordruck auswählbar; genau eine Auswahl ist
  für das Speichern als offene Ausgangsnachricht Pflicht und bleibt über
  Gesprächsnotiz- sowie Anlagenrückwege erhalten. Das Formular erklärt den
  anschließenden Weg über Si, LdF und Fernmelder sichtbar,
- direkter Upload über **Datei hochladen** und Upload mit der regulären
  **Absenden**-Aktion; dabei müssen Formularkopf, Aktionsleiste und Listen die
  kanonische Anlagenzahl anzeigen, Karten Bildminiatur beziehungsweise lazy
  Same-Origin-PDF-Vorschau anbieten und das Entfernen nur die Entwurfsreferenz
  lösen. Retry ohne erneuten Dateiteil, fachlicher Validierungsfehler und der
  zweistufige Gesprächsnotizpfad dürfen im normalen Requestablauf weder Datei
  noch Nachricht duplizieren. Der statische Sicherheitsvertrag bindet darüber
  hinaus Session-Checkpoint, SHA-256-Aktionsnachweis im unveränderlichen
  Nachrichtenereignis und tokenbezogenen MariaDB-Advisory-Lock,
- direkter Upload einer realen multipart/verschachtelten `.eml`-Fixture am
  Vordruck, sichtbares E-Mail-Badge und lazy passive Ansicht. Der
  authentifizierte GET muss decodierte Unicode-Kopfzeilen, den Textkörper und
  ausschließlich Metadaten der internen Anlagen liefern; präparierte Skripte,
  Ereignisattribute und Remote-URLs dürfen weder in der Antwort noch als
  Browserabruf erscheinen. HEAD liefert dieselben Schutzheader ohne Body, POST
  endet mit HTTP 405 und `Allow`, anonyme beziehungsweise objektfremde Abrufe
  bleiben gesperrt. Der Originaldownload und sein erzwungener
  Attachment-Disposition-Pfad werden bytegleich mit der Fixture verglichen;
  `no-store`, `nosniff`, `SAMEORIGIN`, restriktive CSP,
  `X-eStab-Email-Rendering: passive-text` und der Integritätsnachweis sind
  Pflicht,
- die harten Prozess-/Hostabbruchfenster zwischen Staging und Finalisierung,
  nach der atomaren Status-2-Beanspruchung einer Cleanup-Zeile sowie zwischen
  Anlagenfinalisierung und Session-Checkpoint sind ausdrücklich verbleibende
  Betriebsgrenzen. Möglich bleiben Status-8-Staging- und
  Status-2-Cleanup-Reste sowie eine zusätzliche freie Status-1-Archivdatei.
  Die Tests belegen die regulären Exception-, Cleanup- und Replaypfade,
  behaupten aber keine gemeinsame Transaktion über MariaDB, Volume und
  Sitzung. Nach einem Nachrichten-Commit mit unveränderlichem
  Aktionsnachweis darf dagegen keine stille Doppelnachricht entstehen,
- verknüpfte Anhänge übernehmen die Rechte ihrer exakt referenzierten
  Nachricht, freie Anhänge bleiben auf Uploader, S2, Si und LdF begrenzt;
  fremde Liste, Vorschau, Download, Auswahl und ein manipuliertes finales
  Speichern müssen geschlossen scheitern,
- Anlage einer echten Gesprächsnotiz über den produktiven Controller als
  offener Ausgang in Status 4. Der Rollenlauf beweist anschließend die formale
  Si-Prüfung einschließlich Rückgabe und typwahrender Korrektur, die
  LdF-Auswahl von Rufname und aktivem S6-Beförderungsweg sowie den
  Beförderungsnachweis durch den Fernmelder. Fehlender Rufname oder fehlender
  Weg liefert HTTP 422 und darf auch keine Teilwerte persistieren.
  Ursprüngliche Gesprächsart und disponierter Weg werden absichtlich
  verschieden gewählt und bleiben getrennt; TTB-Eintrag und generierter
  Vordruck dürfen erst beim Abschluss in Status 8 entstehen. Eine daraus
  abgeleitete Antwort muss als frische gewöhnliche Nachricht ohne geerbte
  Aufnahme- oder Rollenbelege beginnen,
- unabhängiger PDF-Archivnachweis über eine plausible terminale
  Wegwerfstack-Fixture: geschützte Liste, aktueller In-Memory-Download mit
  gemeinsamem Dossierrenderer und eigenem Layout-Header sowie getrennter
  Archivdownload mit PDF-Header/-Trailer und MIME-Headern. Danach ersetzt der
  Test ausschließlich sein Archiv durch ein gültiges, absichtlich veraltetes
  Marker-PDF. Der aktuelle Abzug darf diesen Marker nicht enthalten, der
  parameterlose Archivdownload muss ihn enthalten und dessen SHA-256 muss nach
  Backup und Restore unverändert sein. Leere, unbekannte, nichtskalare oder für
  Anhänge gesetzte Layoutparameter werden abgewiesen,
- Basic-Auth-Adminseite mit escaped technischem Benutzernamen, ausdrücklicher
  Trennung vom eStab-Funktionskonto; der Einsatzabschluss prüft einen
  unmittelbar zuvor gleichlang manipulierten Pflichtanhang und liefert HTTP
  409; der modeabhängige Abschlussvertrag weist in `STRICT` fehlende
  Bucheröffnung oder offene formale Dienstorganisation ab und erlaubt in
  `LOOSE` den Abschluss ohne formale Schicht. Außerdem
  Exportverwaltung: zwei vollständige
  Exporte erzeugen, PRG-Rückleitungen und CSRF-Grenzen prüfen, Manifest und
  jede CSV-Prüfsumme in beiden heruntergeladenen ZIPs verifizieren, den
  Workflowmarker per CSV-Parser exakt in `nv_nachrichten.csv` nachweisen,
  Traversal und unbekannte IDs abweisen, genau einen Lauf löschen und den
  zweiten byteidentisch für den Backup-/Restore-Nachweis behalten,
- Restore-Prüfmodus ohne Neuanlage: Das Nachrichtenworkflow-Gate übergibt die
  Identität seines tatsächlich aktiven S1-Nachfolgers über eine private
  CI-Zustandsdatei. Genau dieses vorhandene Konto wird nach dem Restore erneut
  mit seiner festen Funktion angemeldet;
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

Das öffentliche Web-Handbuch wird ebenfalls ohne Testkonto und ohne
Änderungen an Anwendungsdaten geprüft. Der Modus kontrolliert alle 19 Kapitel,
die öffentliche Navigation, die lokale Mehrwortsuche einschließlich Löschen
und URL-Abfrage sowie ein überlauffreies Desktop- und Mobil-Layout:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
python3 -B tests/browser/headless_ui.py --handbook-only
```

Bei aktiviertem Browser-Gate führt `tests/integration/ci.sh` auch diesen Lauf
mit einem eigenen Zeitlimit von drei Minuten automatisch aus.

Die Meldungsübersicht wird zusätzlich mit einem fest provisionierten
S2-Testkonto geprüft. Der fokussierte Lauf sucht einen bekannten, nur als
Betreff gespeicherten UTF-8-Wert und verlangt ihn als sichtbare
Vordruck-Überschrift in der Trefferzeile. Chrome vermisst die Liste bei
Desktop- und Mobilbreite auf Überlauf und Überschneidungen:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
ESTAB_TEST_LOGIN_NAME='Browser Meldungsübersicht' \
ESTAB_TEST_LOGIN_CODE=brw002 \
ESTAB_TEST_LOGIN_FUNCTION=S2 \
ESTAB_TEST_LOGIN_PASSWORD_FILE=secrets/test_login_password.txt \
ESTAB_TEST_MESSAGE_OVERVIEW_SUBJECT='Erwartete Überschrift' \
python3 -B tests/browser/headless_ui.py --message-overview
```

Im vollständigen CI-Gate stammt der bekannte Betreff aus dem zuvor angelegten
HTTP-Sicherheitsvordruck. Der Browsertest verändert weder Nachricht noch
Einsatz.

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
  aufeinander. Arbeitszähler, Serverzeit, Aktivitätsübersicht mit getrennten
  Aktiv-/Inaktivzuständen und die aktuelle Funktion sind vorhanden; die
  rollenabhängigen Fachaktionen sind echte,
  mindestens 44 Pixel große Textbuttons. Ein positiver Arbeitszähler besitzt
  eine dauerhaft sichtbare Warnstufe.
- Das Manifest enthält neun Bereiche und zwei Dienste. Nach der Anmeldung
  erscheinen nur die für die wirksamen Funktionen zulässigen Links ohne
  Disclosure dauerhaft; in `STRICT` liefert sie die ausgewählte
  Dienstbesetzung, in `LOOSE` feste und zusätzliche Kontofunktionen. S2 erhält
  die Meldungsübersicht, LdF und Fernmelder die
  Nachweisung, andere Funktionen keines der beiden Spezialziele. Alle sichtbaren
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
  `partial`-/`unavailable`-Zustände abgesichert. Der Refresh selbst löst keine
  Aktivitätsmeldung aus; HTTP 401 führt den Top-Level-Kontext zum Login.
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
- Mit separaten ephemeren Admin-Testdaten bleiben alle elf Admin-Karten bei
  `1280 × 800` und `390 × 844` CSS-Pixeln sichtbar, überlappungsfrei und
  bedienbar. Die Selbstregistrierungsseite zeigt alle acht festen Zeitfenster,
  getrennte Aktionen und drei revisionsgebundene Formulare, ohne die
  gespeicherte Freigabe zu ändern. Im Einsatzarchiv vermisst Chrome zusätzlich
  jede Einsatzkarte: Der vollständige Kopf muss oberhalb des responsiven
  Aktionsrasters liegen, die Kennung darf nicht zeichenweise kollabieren,
  Aktionsfelder dürfen sich nicht überdecken und alle aktiven Controls müssen
  innerhalb ihrer Karte fokussierbar und mindestens 44 × 44 Pixel groß sein.
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

### ETB-/TBB-HTTP-Integration und Lebenszyklus

Der getrennte, idempotente Logbuchtest registriert je eine Sitzung für S2 und
Fernmelder und verwendet bei einem Wiederholungslauf dieselben
Testkennungen:

```console
ESTAB_TEST_BASE_URL=http://127.0.0.1:18080 \
tests/integration/logbooks_http.sh
```

Er weist nach, dass anonyme Lesezugriffe HTTP 403 erhalten und operative
Schreibversuche ohne aktiven Einsatz gesperrt sind. Beide Bücher zeigen den
globalen Einsatzkopf und besitzen kein lokales Titelformular mehr. Im strengen
Modus schreiben ETB ausschließlich ausgewählte aktive Besetzungen `ETB/Stab`
oder `S2/Stab` mit `EINSATZTAGEBUCH`; das TTB eine ausgewählte
Fernmelder-Besetzung mit `BEFOERDERUNG`. Ohne aktive Schicht, Annahme oder
Auswahl liefern die POSTs HTTP 403. Ein getrennter lockerer Lauf muss dieselben
Schreibaktionen ohne formale Dienstschicht nur mit fester oder ausdrücklich
vergebener Zusatzfunktion zulassen; ein fachfremdes Konto bleibt HTTP 403.
Kontenidentität, Einsatzbezug, Nummerierung, Referenzen und
Append-only-Trigger bleiben unverändert. Gesperrte Konten liefern in beiden
Modi HTTP 403.
Zusätzlich prüft der Test lokale statt globaler Nummern, A/B/E/K/W-Arten,
strukturierte TBB-Inhaltsfelder, direkte Korrekturbezüge, serverseitige
Längengrenzen, inerte historische GET-Schreibparameter und HTML-Escaping.
Der statische UI-/Abfragevertrag ergänzt eine ungefilterte Gesamtliste sowie
einzeln beziehungsweise kombiniert gesetzten Volltext-, Art- und
Nummer-/Bezugsfilter. Er bindet lokale Nummer, Korrektur-/Nachrichten-/
Anhangsbezug, kanonische lokale und historische Bestandsreferenz,
Ablage-/Originaldateiname und die vollständige ETB-Anlagennummer. Weitere
statische und MariaDB-Verträge verlangen, dass die
Eingabe nur finalisierte, unbenutzte Anhänge des aktiven Einsatzes anbietet,
eine optionale Auswahl `ETB {einsatz_id}-{estab_book_lfd}-1` erhält und eine
zweite Verknüpfung in Anwendung beziehungsweise Datenbank abgewiesen wird.

Neue ETB-Referenzen werden mit vorhandener positiver lokaler Nummer desselben
Einsatzes akzeptiert; leerer Wert bleibt optional. Freitext, führende Nullen,
globale technische IDs, unbekannte Nummern und einsatzfremde Ziele müssen
scheitern. Historische Freitextwerte bleiben les- und suchbar, zählen aber
nicht als Graphkante. Die Tests bauen eine verzweigte Vorwärtskette und einen
Rückwärtspfad auf, prüfen Tiefen 1 bis 25, Abbruchmarkierung,
Zyklen-/Mehrfachbesuchsschutz und die referenzbeschränkte Druckansicht. Bei
einer Korrektur darf der Request keine öffentliche Referenz erfinden: Der
Server bindet direkt das unveränderliche Original und leitet dessen lokale
Nummer kanonisch ab.

Eine optionale ETB-Bearbeitungszuordnung bleibt reine Such- und Anzeigehilfe;
sie darf keine Rechte erweitern. Volltext- und separater Zuordnungsfilter
müssen ihren Snapshot finden, die Webliste muss ihn HTML-neutral anzeigen und
der amtliche PDF-Renderer darf ihn nicht enthalten. Neue manuelle
ETB-/TTB-Zeilen müssen in `STRICT` Schicht und Schreiberbesetzung speichern;
in `LOOSE` dürfen diese Felder `NULL` bleiben. Automatische Systemzeilen
dürfen in beiden Modi ohne persönlichen Schreiber entstehen. Belegte
historische Werte bleiben unverändert exportierbar; eine Zugangsschicht darf
niemals in diese Felder geschrieben werden.

Der Einsatz-Lebenszyklustest verlangt einen vollständigen Pflichtkopf. In
`STRICT` darf die Einsatzaktivierung allein noch keine Buchzeile erzeugen;
erst die Aktivierung der ersten formalen Dienstschicht eröffnet ETB und TTB
mit lokaler Nummer 1 und Schichtprovenienz. In `LOOSE` darf die
Einsatzaktivierung beide Bücher ohne Schichtvoraussetzung und mit
`NULL`-Schichtprovenienz eröffnen. Der formale Einsatzabschluss muss in
`STRICT` fehlende Bucheröffnung oder offene formale Dienstorganisation
abweisen, in `LOOSE` auch ohne frühere Schicht gelingen und nach sauberem
Preflight je eine letzte Zeile mit tatsächlichem Ende sowie die zehnjährige
Mindestaufbewahrung erzeugen.

Der Zugangsschichttest legt in `LOOSE` optionale Gruppen an und prüft unzugeordnete
Konten, Mehrfachzuordnung per OR, Aktivierung ohne automatische Anmeldung,
Deaktivierung mit Widerruf der betroffenen Sitzungen sowie eine andere aktive
Gruppe als verbleibenden Zugang. Funktion und Rolle müssen unverändert bleiben;
eine manuelle Kontosperre hat immer Vorrang. Zusätzlich simuliert der Test eine
zwischenzeitliche Änderung einer anderen Schicht sowie
Entfernen→Neuzuordnen→Absenden des alten Dialogs. Der serverseitige
Bestätigungs-Hash beziehungsweise die konkrete Intervall-ID müssen beide
veralteten Aktionen konfliktfrei abweisen.

Nummerierte Nachrichteneingänge und erst tatsächlich beförderte Ausgänge
müssen in derselben Transaktion einen strukturierten TBB-Nachweis mit
einsatzgleichem Nachrichtenbezug und dem exakten Typ `nachricht` erzeugen.
Ein manueller TBB-Write mit diesem reservierten Typ muss sowohl im
Anwendungsdienst als auch im Datenbanktrigger scheitern; historische Zeilen
bleiben lesbar. Die Fachfelder müssen auch ohne Öffnen einer Anlage in den
Grundzügen verständlich sein.
Eine LdF-Absenderübersetzung oder begründete Wegänderung muss einen neuen,
direkt auf diesen Originaleintrag verweisenden TBB-Nachtrag des Typs
`korrektur` anhängen. Generator, Nachrichtendetail und Export übernehmen
trotzdem die erste lokale Nummer des Typs `nachricht`. `UPDATE` und `DELETE`
beider Bücher werden zusätzlich direkt auf Datenbankebene negativ geprüft.

Die fachliche Referenz ist
`ETB_TBB_Fuehrung_in_THW_FueSt.pdf`, Handbuch ETB/TBB Version 1.0, Stand März
2022, SHA-256
`2457d1deccd01892655bbc329b08885a0b3c8b3ebfb6372c79997d3427d1ae59`.
Alle automatisierten Ergebnisse belegen nur die technische Umsetzung. Sie
ersetzen nicht die von der Unterlage vorausgesetzte formale Freigabe des
elektronischen ETB/TBB durch die THW-Leitung.

### Kategorien-HTTP-Integration

Die Vorbedingung für den HTTP-Test kommt aus dem Schema-Migratortest: Nur eine
vollständig leere globale Kategorienliste wird einmalig mit `Allgemein` sowie
`EA1` bis `EA6` vorbelegt. Eine bereits gepflegte Liste, ihre Zuordnungen sowie
spätere Bearbeitungen und Löschungen bleiben unverändert. Der HTTP-Test prüft
darauf aufbauend die normalen, weiterhin editier- und löschbaren Kategorien;
die Vorgaben besitzen keine Sonderrechte und werden von keinem Laufzeitpfad
erneut angelegt.

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

Nachgewiesen werden HTTP 403 ohne Sitzung oder mit unpassender nach
Einsatzmodus wirksamer Funktion, positive IDs, inerte GET-Schreibparameter,
Session-CSRF und HTTP-303-PRG. In `STRICT` stammt die Kategorienfunktion aus
der ausgewählten Dienstbesetzung; in `LOOSE` aus fester oder expliziter
Zusatzfunktion. S1 darf Funktions- und Benutzerkategorien seines eigenen
Tabellenraums verwalten, aber weder
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
Tabellen wird ebenfalls entfernt. Die CI führt diesen Test
nach HTTP-Smoke und ETB/TBB, aber vor Admin-Workflow und Backup-/Restore aus.

### Nachrichtenrollen-HTTP-Integration

`tests/integration/message_workflow_http.sh` erzeugt echte Konten, Nachrichten,
dynamische Statustabellen und Vordrucke. Der Test startet deshalb nur mit
`ESTAB_MESSAGE_WORKFLOW_HTTP_TEST_ALLOW_MUTATION=true` und einem Compose-Projekt
namens `estab_ci` oder `estab_ci_*`. Das Projekt muss wegwerfbar,
mit deaktivierter Selbstregistrierung und mit der historischen Standardmatrix
initialisiert sein. Der vorangehende HTTP-Smoke aktiviert einen Einsatz. Der
Nachrichtenrollentest verwendet in `STRICT` aktive angenommene und ausgewählte
Dienstbesetzungen, in `LOOSE` feste Kontofunktionen und gezielt vergebene
Zusatzfunktionen; er deaktiviert den zentralen Schreibguard zu keinem
Zeitpunkt.

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

- Eingang: Der Fernmelder nimmt Rufname, Medium, Zeit und Aufnahmezeichen auf und
  registriert die Nachricht in Status 1. LdF übersetzt den Rufnamen in den
  Absender, muss den Eingangsweg ausdrücklich bestätigen und darf ihn nur mit
  Begründung korrigieren; danach übergibt LdF mit Status 4 an Si. Si wertet
  Inhalt und Zuständigkeit aus und schließt mit Status 8 ab.
- Ausgang: Die Stabsfunktion reicht in Status 4 bei Si ein. Si prüft nur die
  formale Richtigkeit und gibt mit Status 1 an LdF frei. LdF bestimmt Rufname
  der Gegenstelle und vorgesehenen Beförderungsweg und übergibt mit Status 2
  an den Fernmelder. Dieser weist tatsächlichen Weg und Zeit nach und schließt
  mit Status 8 ab.
- Rückgabe: Si gibt einen formal fehlerhaften Ausgang mit Pflichtgrund in
  Status 10 an den Verfasser zurück. Nur dieser korrigiert ihn und reicht
  erneut vollständig in Status 4 ein. LdF kann einen fachlich nicht
  disponierbaren Ausgang ebenfalls nur mit Pflichtgrund in Status 10
  zurückgeben; nach der Korrektur werden Si und LdF erneut durchlaufen. Ein
  nicht nutzbarer disponierter Weg führt vom Fernmelder mit Pflichtgrund zu
  LdF zurück, ohne die vorherige Runde zu überschreiben.

`tests/php/message_timeline_security.php` baut gerade und wiederholte Ein- und
Ausgangswege als reines Modell nach. Der Test belegt insbesondere, dass die
Reihenfolge aus `event_id` und jede Laufzeit ausschließlich aus der
serverseitigen Datenbankzeit `recorded_at` stammen; rückdatierte fachliche
`occurred_at`-Werte verändern die Messung nicht. Sichter-, LdF- und
Fernmelder-Rückgaben erscheinen als eigene Stationsbesuche mit HTML-sicherem
Grund. Entwürfe zeigen nur den geplanten Weg, Legacy-Nachrichten benennen eine
nicht rekonstruierbare Vorgeschichte und negative oder widersprüchliche
Nachweise werden nicht als plausibler Verlauf ausgegeben.

`tests/php/message_timeline_integration_security.php` bindet denselben Renderer
an den gemeinsamen Nachrichtenvordruck und die Meldungsübersichts-Detailseite.
Damit bleibt die Stationsleiste auch bei Validierungsrückwegen oberhalb des
Formulars erhalten, verwendet in beiden Ansichten das gemeinsame responsive
Layout und fällt bei einem nicht sicher prüfbaren Ereignisnachweis auf einen
gestalteten Hinweis zurück, ohne den übrigen Vordruck unbedienbar zu machen.

Die Vorschlagsfunktion besitzt zusätzlich drei aufeinander abgestimmte
Nachweise. `tests/php/message_suggestion_security.php` prüft den
Fail-closed-Vertrag, die zugängliche Combobox/Listbox samt nativer
No-Script-Rückfalloption, ausgeschaltetes Browser-Autocomplete und HTML-sichere
Ausgabe. Dazu gehören die festen LdF-Kontextfelder je Richtung, getrennte
Wert-/Herkunftsattribute sowie die erneute Prüfung von Status, Sperrbesitz und
modeabhängig wirksamer Funktion. Der isolierte MariaDB-Test
`tests/integration/message_suggestions.php` belegt die erneute Prüfung von
aktivem Einsatz sowie modeabhängig wirksamer Funktion und Rolle,
die Rollen- und Richtungsgrenzen, akzentverschiedene Werte sowie, dass Werte
eines anderen Einsatzes nicht erscheinen. Er weist außerdem die Rangfolge
„häufige abgeschlossene Nachrichtenpaare vor aktuell gültigem
S6-Fernmeldeplan vor allgemeiner Historie“ für Eingang und Ausgang nach und
entzieht die Vorschläge unmittelbar nach einem Sperrverlust.
`tests/integration/message_workflow_http.sh` prüft schließlich an den echten
Formularen: Die Funktionen Fernmelder und LdF erhalten nur die zulässigen
Rufnamenvorschläge, LdF bei Eingängen die Absendervorschläge; LdF sieht zum
gesperrten aktuellen Vordruck die priorisierte, sichtbar gekennzeichnete
Zuordnung. Bei der Eingangserfassung des Fernmelders und beim Schreiben durch
den Stab gibt es kein Absender-Eingabefeld. Keine Liste wählt einen Wert automatisch
aus und freie Eingaben bleiben möglich. Die lokale Eingangsanschrift und
Ausgangs-Absendereinheit müssen dem per Join gelesenen Führungsstellennamen
des aktiven Einsatzes entsprechen; ein absichtlich gesetzter
`ESTAB_ORGANISATION`-Poisonwert darf weder in Datenbank noch Ausgabe
erscheinen.
`tests/browser/headless_ui.py --message-suggestions`
meldet ein echtes Fernmelderkonto an und beweist mit einem kurzlebigen,
einsatzgebundenen Marker Fokusöffnung, Filterung, Pfeiltaste/Eingabetaste,
Übernahme, freie Eingabe und Logout im echten Chrome; das Fixture wird auch
bei einem Testabbruch entfernt.

Der versionierte Fernmeldeplan hat einen eigenen mehrstufigen Nachweis.
`tests/php/telecom_plan_security.php` fixiert die sechs ausgeschriebenen
Medien, die Funk-spezifischen Kanal-/Bandlagefelder, die allgemeine
Verkehrsform sowie die serverseitige Normalisierung manipulierter, für das
Medium unpassender Felder. Der Test prüft außerdem die Controlleraktionen,
CSRF-Reihenfolge, HTML-sichere Ausgabe, Zustandswert, Entwurfsdarstellung und
Langtexte in beiden Nachrichteneditoren. Die UI-Abnahme
`tests/browser/headless_ui.py --telecom-plan` lief erfolgreich in Chrome 150.
Sie vergleicht nach dem Bearbeitungsstart alle sichtbaren Kopfwerte und
sämtliche Wege mit der aktiven Quelle, weist für den unberührten
Medien-Platzhalter eine ausbleibende falsche Verlustwarnung nach, öffnet einen
übernommenen Weg sichtbar, speichert ihn mit Positionsrückkehr, legt einen
weiteren Weg an und entfernt ihn nach Bestätigung. Sie schaltet zwischen Funk
und Melder um und prüft, dass Kanal/Gruppe und Bandlage nur für Funk erscheinen.
Eine Aktivierung bei ungespeicherten Teilformularen bleibt mit einer Meldung
im Entwurf stehen. Sind Kopf und Weg gleichzeitig geändert, belegt der Browser
zusätzlich den ausdrücklich bestätigten Auflösungsweg: nur die gewählte
Wegeaktion wird gespeichert und der anderswo ungespeicherte Browserwert
bewusst verworfen. Nach der Veröffentlichung bleiben Kopf- und
Wegebemerkungen in aktiver Fassung und nur lesbarer Historie sichtbar. Bei
`390 × 844` CSS-Pixeln bleiben Editor und Historie bedienbar und ohne
horizontalen Seitenüberlauf.
`tests/integration/dv_operations.php`
veröffentlicht eine erste Fassung mit zwei verschiedenartigen Wegen, kopiert
alle Kopf- und Wegefelder in neue IDs, ändert Kopf
und Weg, ergänzt und entfernt einen Weg, weist einen veralteten Zustandswert
ab und veröffentlicht die Folgefassung. Dabei bleiben die alte Plan- und
Nachrichtenzuordnung unverändert; nur Wege der neuen aktiven Version sind für
neue Dispositionen wählbar. Ein aufgegebener Entwurf wird samt Wegen
unveränderlich archiviert und blockiert danach keinen neuen Klon. Sichere
Legacy-Entwürfe benötigen eine eindeutige Ereignisreihenfolge zur noch aktiven
Quelle. Zulässige große UTF-8-Vermerke bleiben vollständig im verketteten
Betriebsereignis; das Legacy-Protokoll enthält bei Überschreitung seiner
TEXT-Grenze einen prüfbaren kompakten Verweis. Im Modus `LOOSE` wird die
tatsächlich wirksame feste oder zusätzliche Funktion auditiert. Der erfolgreiche
Lauf umfasst 243 Assertions und 95 verkettete Betriebsereignisse.

Migration 119 erweitert diesen DV-Lauf um getrennte Zielzustände. Aktive, seit
mindestens 15 Minuten inaktive und vollständig abgemeldete Fernmelder bleiben
bei unveränderter fachlicher Eignung auswählbar. In `STRICT` bleibt die
angenommene Ziel-Besetzung der aktiven Dienstschicht, in `LOOSE` die feste
beziehungsweise zusätzliche Fernmelderfunktion samt Zugangsschicht-Gate
Pflicht. Kontosperre, fremde Funktion, deaktivierte einzige Zugangsschicht und
fehlende Ziel-Besetzung bleiben Negativfälle. Der Test bestätigt außerdem,
dass nur die Beauftragung keine Ziel-Sitzung benötigt: Übernahme und
Folgeschritte ohne authentisierte Sitzung des beauftragten Kontos werden
weiter abgewiesen. Die dedizierte Chrome-Abnahme bestätigt Statusbeschriftung
und den Hinweis, dass LdF eine inaktive oder abgemeldete Person separat
informieren muss.

`tests/integration/message_workflow_http.sh`
belegt denselben Ablauf über echte CSRF-geschützte HTTP-Formulare einschließlich
Vorbelegung, 409-Konfliktseite und Erhalt der nicht gespeicherten Eingabe.

Der Lauf registriert isolierte LdF-, Fernmelder-, Si-, S1-, S2-, S3- und
POL/FB-Konten über
die öffentliche Kontooberfläche. Er prüft die gerenderten Listen und Aktionen,
fehlende CSRF-Tokens mit HTTP 403, die erlaubten Stabsaktionen sowie verbotene
Aktionen für LdF, Fernmelder und Si des echten FB-Profils, stufengebundenen
Sperrbesitz von LdF und Fernmelder, Status und Abschluss direkt in MariaDB,
den erzeugten
Ein-/Ausgangsvordruck sowie die serverseitige Lagezuordnung `S2_rt` und die
über das Verteilerkästchen gewählte Zuordnung `S3_bl`. Automatisch aus der
angemeldeten Identität abgeleitete Autorenzuordnungen bleiben davon getrennt.

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
LdF-Kürzel an die Hashkette. Aufnahmezeit und Aufnahmezeichen des Fernmelders
werden vor und nach diesem Übergang bytegenau verglichen; übermittelte Ersatzwerte werden schon
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

Ein weiterer Negativpfad sendet bei der Eingangserfassung durch den
Fernmelder verborgene `16_*`-Empfängerfelder und simuliert zusätzlich einen bereits vorhandenen fremden
Empfängertoken. Vor dem terminalen Si-Abschluss müssen Liste, Detailaufruf und
Gelesen-/Erledigt-Aktion jeweils HTTP 403 beziehungsweise keine sichtbare
Zeile ergeben. Dieselbe Prüfung läuft für die S2-Rotkopie eines ausgehenden
Status-4-Entwurfs und einer Status-10-Rückgabe. Die Verfasserfunktion behält
den benötigten Zugriff; S2 erhält die Nachricht erst nach dem vollständigen
Status-8-Abschluss. Damit wird nicht nur der Formularhandler, sondern dieselbe
Zugriffsregel in Listen-SQL, Objekt-Gate und atomarem State-SQL geprüft.

`tests/php/read_authorization_security.php` und die HTTP-Gegenproben erweitern
diese Grenze auf alle Ausgabepfade. Eine Kontositzung mit unpassender wirksamer
Funktion darf keine operative Nachricht lesen. Normaler Stab/FB erhält
nur die terminale Empfängerkopie oder den eigenen Ausgang; Si, LdF und
Fernmelder nur ihre aktuelle Warteschlange/Sperre oder eine Nachricht mit eigener
unveränderlicher Verarbeitungsmarke. Vordruckliste und beide Downloadvarianten
verwenden dieselbe Nachricht. Verknüpfte Anhänge erben die Objektregel über
exakte vollständige Dateinamens-Tokens; freie Anhänge bleiben auf Uploader,
S2, Si und LdF begrenzt. Liste, Vorschau, Download, Auswahl und finaler
Nachrichtensave werden getrennt negativ geprüft. Die Meldungsübersicht ist nur
für S2, die Nachweisung nur für LdF beziehungsweise Fernmelder erreichbar.

Danach versucht der Lauf die historischen
`FM-Admin`-/`SI-Admin`-Zweitsichtungen gegen einen abgeschlossenen Datensatz.
Weder Navigation noch Controller dürfen einen solchen Bearbeitungspfad
bereitstellen. Manipulierte Requests müssen abgewiesen werden; fachliche
Felder, Quittierung, Empfängerzuordnung, Sichter- und Transportvermerk sowie der
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
- modeabhängige Zählergrenze mit Pflicht zur aktiven formalen Dienstschicht
  und Objekttyp `DIENSTSCHICHT` in `STRICT` sowie schichtfreiem Objekttyp
  `EINSATZ` in `LOOSE`, hashverketteter Nachweis ohne künstliche Fachnachricht, Audit und
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
Empfängermatrix. Als aktuelle Bedienreferenz dient das gemeinsam mit der
Anwendung ausgelieferte [Web-Handbuch](../handbuch/) unter `/handbuch/`.
Frühere Handbuchstände sind ausschließlich über die Git-Historie und Tags
nachvollziehbar; sie sind kein Sollzustand der heutigen Laufzeit.

Insbesondere beweist die Automation keine physische Hörbarkeit. Sie bindet
alle drei Dateien per SHA-256, parst RIFF/WAVE und PCM-16, prüft Kanäle,
Abtastrate, Framezahl, Dauer, Signalspitze und Mindest-RMS sowie
Opt-in-Zustand, sichtbaren Rückfall und den angeforderten Wiedergabeaufruf.
Lautsprecher, Lautstärke, Betriebssystem-, Browser- und Geräteeinstellungen
müssen manuell abgenommen werden.

Mindestens zu prüfen:

- zwei Testeinsätze nacheinander aktivieren, die globale Statusanzeige auf
  allen Modulen auf Führungsstellenname, Kennung, Einsatzname und
  Berechtigungsmodus kontrollieren
  und nach Deaktivierung die rote No-active-Warnung sowie gesperrte operative
  Formulare prüfen,
- einen offenen migrierten Testeinsatz ohne Führungsstellennamen in
  Statusleiste und Administration als fehlend/unvollständig anzeigen lassen,
  seine Aktivierung und operative Eingabe abweisen, im historischen PDF
  „historisch nicht erfasst“ prüfen, den tatsächlichen Namen einmalig
  administrativ bestätigen und nach der ersten operativen Eintragung jede
  weitere Änderung abweisen,
- die Berechtigungsmodi in getrennten Einsätzen abnehmen: In einem eigenen
  Einsatz im Standardmodus **Streng** ohne ausgewählte Besetzung alle
  operativen Schritte abweisen, danach eine aktive formale Dienstschicht sowie
  persönlich angenommene passende Besetzungen auswählen und den Rollenablauf
  positiv prüfen; Zusatzfunktionen bleiben dort wirkungslos. In einem eigenen
  Einsatz im Modus **Locker** muss ein fremdes aktives Konto weiter scheitern;
  erst die gezielte Vergabe einer passenden Zusatzfunktion darf den Schritt
  freigeben. Ein vollständig leerer dritter Fixture prüft bestätigten
  Moduswechsel, Audit, idempotentes Speichern desselben Werts und die
  dauerhafte Sperre nach der ersten operativen oder formalen Eintragung.
  Anonyme/abgelaufene Sitzung, Kontosperre, deaktivierten alleinigen
  Gruppenzugang, fehlenden aktiven Einsatz, falsches CSRF, fremden
  Sperrinhaber, falschen Workflowzustand und einsatzfremdes Objekt in den
  jeweils passenden Fixtures weiterhin abweisen,
- neuen Benutzer für jede tatsächlich verwendete Funktion in der
  Benutzerverwaltung anlegen, abmelden und mit demselben Kennwort erneut
  anmelden; eine abweichende Funktion vor und nach dem Logout abweisen,
- ein Funktionskonto in der Administration sperren, die alte Sitzung und
  Neuanmeldung abweisen, anschließend entsperren und ein Kennwort
  zurücksetzen; altes Kennwort ablehnen und neues akzeptieren,
- die Kennwortrichtlinie mit Mindestlänge und mehreren Unicode-Zeichenklassen
  voranzeigen und bestätigen, eine Abschwächungswarnung prüfen, schwache
  Kontoanlage und schwachen Reset abweisen und danach belegen, dass ein vor der
  Verschärfung vorhandenes Konto sowie der getrennte Basic-Auth-Zugang weiter
  funktionieren,
- Berechtigungs-/Rollenzuordnung aus der Empfängermatrix kontrollieren,
- jede verwendete Person mit ihrem persönlichen Konto anmelden; in `STRICT`
  die vorgesehene angenommene Dienstbesetzung auswählen, in `LOOSE` feste und
  zusätzliche Funktionen prüfen; abweichende Funktion/Rolle und fehlenden
  aktiven Einsatz abweisen,
- mit LdF nacheinander einen aktiven, einen mindestens 15 Minuten inaktiven und
  einen abgemeldeten, aber fachlich geeigneten Fernmelder für einen
  Melderauftrag auswählen; Statusbeschriftung und den Hinweis auf die separate
  Information außerhalb von eStab prüfen. In `STRICT` muss das Ziel eine
  angenommene Fernmelder-Besetzung der aktiven Dienstschicht besitzen, in
  `LOOSE` feste oder zusätzliche Fernmelderfunktion und wirksamen
  Zugangsschicht-Zugang. Gesperrte und fachfremde Konten dürfen nicht
  disponiert werden. Anschließend muss ausschließlich das erneut selbst
  angemeldete Zielkonto die persönliche Übernahme und den weiteren Lauf
  quittieren können,
- eingehende und ausgehende Nachricht mit Richtung, Gegenstelle,
  Prioritätsstufe, Empfängern und Inhalt erfassen,
- den Bildschirmvordruck bei Desktopbreite und bei 390 Pixeln direkt mit den
  beiden Referenzunterlagen vergleichen: Dreizonenraster, Zellfolge,
  Beschriftungen, Linien und Blauton müssen übereinstimmen; auf 390 Pixeln
  darf nur das Blatt horizontal scrollen,
- alle 20 Informationsdialoge einzeln per Maus und Tastatur öffnen, Inhalt und
  Zuordnung zum Feld prüfen sowie Schließen-Knopf, `Escape`, Außenklick und
  Fokus-Rückgabe kontrollieren; zusätzlich eine Bildschirmdruckprobe ohne
  Bedienleisten und Informationsdialoge erstellen,
- Weiterleitung, Sichtung, Quittierung, Statuswechsel und Listenfilter über
  zwei unterschiedliche Funktionssitzungen nachvollziehen,
- in Meldungsübersicht und zweiter Sichtung Nummern-, Mehrwort- und
  Kurztextsuchen sowie Richtung, Vorrang, Stand, Zeitraum, Empfänger,
  Sortierung, Filterchips und erste/letzte Seite mit einem Bestand über mehrere
  Ergebnisseiten prüfen; während einer Eingabe darf keine automatische
  Aktualisierung den Suchtext verwerfen,
- mit S2 die Meldungsübersicht und mit LdF beziehungsweise Fernmelder die Nachweisung öffnen; S1, Si
  und S6 an den jeweils fremden Spezialzielen mit HTTP 403 abweisen,
- für Fernmelder, Si und mindestens eine Stab-/FB-Sitzung den
  Hinweiston-Schalter im vorgesehenen Browser ausdrücklich aktivieren, den
  Testton tatsächlich anhören, nach der stillen ersten
  Warteschlangenmessung jeweils eine reale Erhöhung erzeugen und den passenden
  Hinweis genau einmal physisch hören; mit ausgeschaltetem oder browserseitig
  blockiertem Ton zusätzlich die sichtbare Rückmeldung kontrollieren,
- globale, funktionsbezogene und persönliche Kategorie anlegen, zuweisen,
  suchen und entfernen; dieselben Seiten mit unpassender wirksamer Funktion
  und einem fremden Nachrichtenbezug abweisen,
- zulässigen Anhang hochladen, Vorschau/Download prüfen und eine unzulässige
  Datei ablehnen lassen; verknüpften und freien Anhang zusätzlich mit
  berechtigter sowie fachfremder wirksamer Funktion über Liste, Vorschau, Download, Auswahl und
  manipulierten finalen Nachrichtensave prüfen,
- eine standardisierte `.eml` direkt am Nachrichtenvordruck hochladen, die
  passive Ansicht im echten Browser auf decodierte Kopfzeilen, lesbaren
  Nachrichtentext und reine Metadaten interner Anlagen prüfen und den
  sichtbaren Hinweis auf nicht verifizierten Absender sowie fehlenden
  DKIM-/S/MIME-Nachweis kontrollieren. Die Originaldatei herunterladen,
  bytegleich vergleichen und ausschließlich in einer geeigneten Prüfumgebung
  öffnen; `.msg`, umbenannte oder strukturell ungültige Mail-Dateien ablehnen,
- Nachrichtenvordruck als PDF erzeugen und aus der geschützten
  Vordruckliste mit berechtigtem Konto abrufen und mit einem fremden Konto sowohl
  Liste als auch aktuellen und archivierten Download abweisen,
- für einen inzwischen historischen Einsatz ein PDF-Dossier mit allen neun
  Abschnitten ETB, TBB, Nachrichtenvordrucke, Anhänge,
  Nachrichtenereignisse, Dienstbetrieb, S6-Fernmeldepläne, Melderläufe und
  Betriebsereignisse erzeugen; JPEG, PNG, GIF, BMP, verlustfrei darstellbaren
  Text, eine passiv dargestellte RFC-822-E-Mail und eine mehrseitige PDF-Anlage
  mit sichtbarer Anmerkung im Seitenstrom prüfen; die E-Mail darf keine aktiven
  Inhalte oder Remote-Ressourcen ausführen und nennt interne Anlagen nur als
  Metadaten. TIFF sowie Text mit einem nicht Windows-1252-darstellbaren Zeichen
  müssen eine Hinweisseite erhalten. Danach die Anlagenansicht im vorgesehenen
  PDF-Programm öffnen und jede eingebettete Datei samt dokumentierter SHA-256
  gegen das Original prüfen; dabei
  Führungsstellenname, Einsatzkennung, Einsatzname und Berechtigungsmodus
  getrennt kontrollieren,
- einen strengen Einsatz mit vollständigem ETB-/TTB-Pflichtkopf anlegen,
  unmittelbar nach Einsatzaktivierung noch keine Buchzeile und nach
  Aktivierung der ersten formalen Dienstschicht genau die schichtbezogenen
  Eröffnungszeilen sowie die Köpfe `ETB:1` und `TTB:1` prüfen; danach nur mit
  aktiver, angenommener und ausgewählter Besetzung ETB
  mit `ETB/Stab` oder `S2/Stab` und TTB mit `Fernmelder` schreiben. In einem
  lockeren Gegenlauf die schichtfreie Eröffnung bei Einsatzaktivierung sowie
  feste und zusätzliche Funktionen positiv, fachfremde Konten negativ prüfen;
  außerdem einen bereits aktiven, ungeöffneten und weiterhin vollständig von
  operativen sowie formalen Eintragungen freien `STRICT`-Einsatz bestätigt auf
  `LOOSE` umstellen und die atomare schichtfreie Bucheröffnung nachweisen,
- im ETB ohne/A/B/E/K/W sowie Nachricht, Anhang, eine Referenz auf eine
  vorhandene lokale ETB-Nummer und eine Berichtigung als neue Zeile erfassen;
  Freitext, führende Nullen und unbekannte Nummern abweisen, Referenzketten
  vorwärts/rückwärts mit unterschiedlicher Tiefe anzeigen und die gesonderte
  Druckansicht öffnen; im TBB jeden der fünf offiziellen
  Inhaltsbereiche, einen Nachrichteneingang, einen tatsächlich beförderten
  Ausgang sowie LdF-Absender-/Wegkorrektur als referenzierten Nachtrag prüfen;
  Vordruck und Detail müssen weiterhin die ursprüngliche lokale
  `nachricht`-Nummer zeigen; globale technische IDs dürfen weder Anzeige noch
  PDF-Nummer ersetzen; ETB zunächst ohne Filter vollständig, danach über
  Volltext, Art, lokale Nummer/Bezug, ETB-Anlagennummer und Ablage-/Dateiname
  suchen; optional einen finalisierten unbenutzten Anhang zuordnen,
  `ETB {einsatz_id}-{estab_book_lfd}-1` in UI/PDF/Anlagenverzeichnis und das
  getrennte Kennzeichen wie `EL0001` prüfen sowie den Mehrfachlink abweisen,
- in `LOOSE` optionale Zugangsschichten anlegen, ein unzugeordnetes Konto weiter zulassen,
  ein Konto mehreren Gruppen zuordnen und OR-Semantik prüfen; Aktivierung darf
  niemanden anmelden, Deaktivierung muss Sitzungen ohne anderen aktiven Zugang
  widerrufen und Funktion/Rolle unverändert lassen; manuelle Sperre muss
  Vorrang behalten,
- neue manuelle ETB-/TTB-Zeilen in `STRICT` mit belegter
  Schicht-/Schreiberprovenienz, in `LOOSE` mit zulässigem `NULL` prüfen und
  belegte historische Werte weiter exportieren; in `STRICT` den Abschluss
  ohne Bucheröffnung beziehungsweise mit offener Dienstorganisation abweisen,
  in `LOOSE` einen Einsatz ohne frühere Schicht formal schließen;
  automatische Abschlusszeilen, Schreibsperre und zehnjährige
  Mindestaufbewahrung prüfen,
- das PDF-Dossier öffnen und Fb Fü 2 im A4-Hochformat sowie Fb Fü 44 im
  A4-Querformat ausdrucken; Spalten, buchlokale Seitenzähler,
  Fortsetzungszeilen, ETB-Erfassungszeit, TBB-Vorgangszeit und alle manuellen
  Unterschriftslinien prüfen; bei strukturiertem TBB darf `tbb_aktion` nicht
  doppelt erscheinen und `tbb_bemerk` muss genau einmal stehen; beim formal
  geschlossenen Einsatz muss nur der
  unbeschriebene Rest der jeweils letzten Buchseite diagonal gestrichen sein;
  die organisatorische Zeichnung festlegen und die formale THW-Freigabe
  separat dokumentieren; denselben Einsatz als Gesamtbuch und bei belegter
  Provenienz als formale Dienstschicht exportieren, nur
  ETB/TBB gefiltert sowie Umfang auf Deckblatt und im Audit nachweisen und alle
  übrigen Sektionen einsatzweit vergleichen,
- bei getrennten ETB- und Si-Konten den Si-ETB-POST in **Streng** mit HTTP 403
  abweisen, in **Locker** zunächst ebenfalls abweisen, dann die Zusatzfunktion
  ETB gezielt vergeben und bei unveränderten Konto-/Einsatz-/Append-only-
  Grenzen zulassen; nach Rückkehr zu **Streng** muss die Zusatzfunktion
  wirkungslos sein;
  Kommunikationsplan und lokal benötigte Zusatzmodule je repräsentativ
  anlegen/lesen,
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
- alle Basistabellen sowie Einsatz-, Nachweis-, Dienstschicht-, S6-, Melder-
  und Logbuchkopftabellen in ihrer kanonischen Form vorhanden sind,
- aktive und Standardmatrix jeweils genau 20 eindeutige 5x4-Positionen,
  genau S2/Stab als Rotkopie-/Dokumentationsziel und keinerlei aktive
  Autosichtung enthalten,
- die exakt definierten Benutzer-, Zusatzfunktions-, Präsenz- und
  Anhangindizes, die kanonische Tabelle `nv_benutzer_zusatzfunktionen` sowie
  der UTC-Aktivitätszeitstempel vorhanden sind,
- alle ausgelieferten versionierten Migrationen mit gültigem SHA-256 als
  angewendet protokolliert sind,
- der Singleton des globalen Einsatzstatus, alle Einsatz-Fremdschlüssel und
  -Trigger, die `STRICT`/`LOOSE`-Modusspalte, beide Modus-Guard-Trigger und
  sechs aktuelle modebewusste Fachtrigger mit Dienstbesetzungsautorität in
  `STRICT` sowie Fest-/Zusatzfunktionsautorität in `LOOSE`, append-only Ereignisketten, formaler
  Abschluss/Aufbewahrung sowie die dauerhafte Kontosperr-Spalte kanonisch
  vorhanden sind; der Melderauftrag-Insert muss dabei die Live-Sitzung nur für
  das disponierende LdF, nicht für das weiterhin fachlich gebundene und
  ungesperrte Zielkonto verlangen,
- pro Einsatz exakt zwei Buchkopfstände samt kanonischem Einsatz-Insert-
  Trigger, lokale ETB-/TBB-Nummern, strukturierte TBB-Felder,
  Nachrichten-/Korrekturbezüge, der eindeutige ETB-Anhangsindex, die übrigen
  Unique-Indexe und Append-only-Trigger kanonisch sind, keine ungültige
  TBB-Zeile existiert und jeder formal
  geschlossene Einsatz mindestens zehn Jahre Aufbewahrung besitzt,
- Benutzer-, IP-, Anhang- und alle sechs Nachrichten-Kürzelfelder die
  erforderlichen Breiten besitzen,
- genau eine kanonische Kennwortrichtlinienzeile mit Mindestlänge 8–128,
  booleschen Zeichenklassen und gültiger Revision vorhanden ist,
- Anhang-, Vordruck- und Exportverzeichnis beschreibbar sind.

`docker/db/verify.sql` löst den aggregierten Schemacheck in benannte
`*_ok`-Ergebnisfelder auf. Für einen gültigen Stand müssen alle den Wert `1`
haben; die anschließende Abfrage nach abweichender Engine oder Collation darf
keine Zeile liefern.

Ein regulärer „Inaktiv“-Status nach 15 Minuten ist kein Readiness-Fehler. Die
Präsenzanzeige ist eine fachliche Laufzeitsicht, während Readiness nur ihre
kanonische Timestamp-/Indexgrundlage prüft. Ein Konto, dessen
12-Stunden-Grenze erreicht ist, wird bei der nächsten Authentisierungs- oder
Benutzerlistenprüfung widerrufen. Der Monitor darf dafür keinen künstlichen
Heartbeat senden; insbesondere ist ein wiederholter GET auf das
Statusfragment kein zulässiger Sitzungsnachweis.

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
