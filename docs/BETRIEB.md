# Betrieb und Konfiguration

Dieses Runbook beschreibt den regulären Containerbetrieb. Eine Übernahme
vorhandener Daten ist kein Erststart und wird separat unter
[Migration und Upgrade](MIGRATION-UND-UPGRADE.md) behandelt.

## Voraussetzungen

- Docker mit Compose v2 oder Podman mit einem Compose-Provider
- ausreichend persistenter Speicher für Datenbank, Anhänge, Vordrucke und
  Exporte
- `openssl` oder ein gleichwertiger kryptographischer Passwortgenerator
- `curl` für die Bereitschaftsprüfung
- bei Zugriff außerhalb des Hosts: eigener Reverse Proxy mit gültigem
  TLS-Zertifikat

PHP- und MariaDB-Basis sind nicht nur auf
`php:8.5.8-apache-trixie` beziehungsweise `mariadb:11.8.8`, sondern auch auf
deren geprüfte Multi-Arch-Index-Digests festgelegt. Änderungen daran sind
bewusste Upgrades. Da der App-Build Pakete aus den Debian-Repositories
installiert, wird dennoch keine byteidentische Reproduzierbarkeit behauptet;
entscheidend sind Commit, resultierende Image-Digests und Attestationsnachweis.

## Source-Build oder pull-only Images

Das Root-`compose.yaml` baut `app` und `migrate` aus dem ausgecheckten
Quellstand und ist der Referenzweg für Entwicklung und Freigabetests. Für
Geräte ohne lokalen Build – insbesondere Synology Container Manager – liegt
unter [`deploy/registry/`](../deploy/registry/README.md) ein eigenständiges
Compose-Paket. Es verwendet dieselben Secrets, Netze, Healthchecks und
Startbedingungen, referenziert aber ausschließlich fertige App- und
Migrator-Images.

Die technischen GHCR- und Multi-Arch-Dateien sind vorbereitet; im Repository
ist noch kein freigegebener Image-Stand samt Manifest-Digests dokumentiert.
Eine öffentliche Binärverteilung bleibt bis zur dokumentierten Rechteklärung
des historischen Gesamtbestands gesperrt. Der manuelle Workflow verlangt zwei
Freigabevariablen, eine ausdrückliche Bestätigung, einen bestehenden
tagweit gegen Update/Löschen geschützten Git-Tag und das Environment
`container-publish`. Dieses
Environment muss vor Aktivierung einen Required Reviewer besitzen. Zusätzlich
verweigert der Preflight jede Veröffentlichung, solange die aus der
Rechteprüfung hervorgehenden, versionierten Dateien `LICENSE` und
`THIRD_PARTY_NOTICES.md` fehlen oder leer sind; Repositoryvariablen und
Checkbox allein können diese konkrete Grenze nicht übergehen.

Der Workflow veröffentlicht die Multi-Arch-Indizes ausschließlich digest-only
ohne OCI-Tags. Die exakten Digests werden anschließend nativ auf `amd64` und
`arm64` ausgeführt und geprüft. Für Installationen gilt ausschließlich ein sichtbares
GitHub-Release als freigegeben, das GitHub als unveränderlich ausweist, dessen
Release-Attestation erfolgreich geprüft wurde und das exakt vier
prüfsummengebundene Assets enthält: Installations- und dauerhaftes
Evidence-Archiv samt je einer äußeren SHA-256-Datei. Bloße Digestobjekte, ein
verstecktes Draft-Release oder ein unvollständiger Assetsatz sind ausdrücklich
kein installierbarer Stand. Die kontrollierte
Behandlung solcher Zwischenstände beschreibt das
[Registry-Runbook](../deploy/registry/README.md#unvollständigen-publish-lauf-behandeln).

## Erstinstallation

### 1. Konfiguration und Secrets anlegen

```console
cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

`.env` und `secrets/` sind von Git und vom Image-Build ausgeschlossen. Die
Secret-Dateien müssen jeweils genau ein starkes Kennwort enthalten und dürfen
nicht in Tickets, Logs oder unverschlüsselte Sicherungen kopiert werden.

Wichtige Werte in `.env`:

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `ESTAB_DB_PASSWORD_SECRET_FILE` | `./secrets/db_password.txt` | Hostdatei mit dem Kennwort des DB-Anwendungsbenutzers |
| `ESTAB_DB_ROOT_PASSWORD_SECRET_FILE` | `./secrets/db_root_password.txt` | Hostdatei mit dem MariaDB-Root-Kennwort |
| `ESTAB_ADMIN_PASSWORD_SECRET_FILE` | `./secrets/admin_password.txt` | Hostdatei mit dem Kennwort für `/4fadm` |
| `ESTAB_DB_NAME` | `estab` | Datenbankname und Unterverzeichnis in `4fdata`; nur Buchstaben, Ziffern und `_` |
| `ESTAB_DB_USER` | `estab` | Anwendungsbenutzer der Datenbank |
| `ESTAB_ADMIN_USER` | `estab-admin` | Benutzer für HTTP Basic Auth unter `/4fadm` |
| `ESTAB_HTTP_BIND` | `127.0.0.1` | veröffentlichte Hostadresse |
| `ESTAB_HTTP_PORT` | `8080` | veröffentlichter Hostport |
| `ESTAB_PUBLIC_URL` | `/` | portable Browser-Basis; nur bei garantiert einer externen Adresse auf eine absolute HTTP(S)-URL setzen |
| `ESTAB_BASE_PATH` | leer | historischer Installationspfad im Document Root; im gelieferten Root-Image leer lassen |
| `ESTAB_AUTHORITY_CODE` | `EL` | Organisationskürzel |
| `ESTAB_ALLOW_SELF_REGISTRATION` | `false` | optionale öffentliche Kompatibilitätsregistrierung; reguläre Konten werden administrativ angelegt |
| `ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF` | `false` | erlaubt ausdrücklich benötigten direkten Legacy-Clients tokenlose Anmeldung; nicht für Browserbetrieb aktivieren |
| `ESTAB_TRUST_PROXY_HEADERS` | `false` | erlaubt dem zusätzlich freigegebenen direkten Proxy validierte `X-Forwarded-*`-Ketten |
| `ESTAB_TRUSTED_PROXIES` | leer | verpflichtende, kommaseparierte IP-/CIDR-Allowlist, sobald Proxy-Header aktiviert werden |
| `ESTAB_UPLOAD_MAX_BYTES` | `20971520` | anwendungsseitige maximale Uploadgröße |
| `ESTAB_PDF_ATTACHMENT_MAX_BYTES` | `52428800` | maximale Gesamtsumme der Originaldateien, die je PDF-Einsatzdossier eingebettet und in der Anlagensektion verarbeitet werden; harte Obergrenze 50 MiB, `0` lässt einen Export mit gewählter Anhangsektion fail-closed abbrechen |
| `TZ` | `Europe/Berlin` | Zeitzone von Anwendung und Datenbank |

Der Name der Führungsstelle ist ausdrücklich **keine** Umgebungsvariable.
Die Administration speichert ihn für jeden Einsatz getrennt. Er bezeichnet
die lokale Anschrift beziehungsweise Absendereinheit auf
Nachrichtenvordrucken und ist weder der Einsatzname noch die
Bedarfsträger oder die Einsatzleitung. Ein alter
`ESTAB_ORGANISATION`-Eintrag in einer bestehenden `.env` ist obsolet und
sollte entfernt werden; er wird nicht als Führungsstellenname übernommen.

Die formale Sichtung jedes Ausgangs vor den Bearbeitungsstufen LdF und
Fernmelder ist eine feste
fachliche Invariante. Sie besitzt bewusst keine Umgebungsvariable und kann in
einer Installation nicht abgeschaltet werden.

Die fachliche Uploadgrenze ist `ESTAB_UPLOAD_MAX_BYTES` (Standard 20 MiB,
hartes Maximum 50 MiB). PHPs Dateitransportgrenze entspricht diesem Maximum
mit `upload_max_filesize = 50M`; die Grenze für den gesamten Request liegt mit
`post_max_size = 56M` darüber. Dadurch kann
die Anwendung einen zu großen Anhang ablehnen und trotzdem den übrigen,
ungespeicherten Nachrichtenvordruck vollständig wieder anzeigen. JavaScript
warnt bereits vor dem Senden; überschreitet der gesamte Request dennoch die
PHP-Grenze, antwortet der Controller ausdrücklich mit HTTP 413.

Für RFC-822-E-Mail-Anlagen (`.eml`) gilt davon unabhängig ein nicht
konfigurierbares Parserlimit von 20 MiB. Auch wenn
`ESTAB_UPLOAD_MAX_BYTES` auf bis zu 50 MiB erhöht wird, werden größere
E-Mail-Dateien vor der MIME-Verarbeitung abgewiesen. Diese feste Grenze
verhindert, dass verschachtelte MIME-Strukturen auf ressourcenbegrenzten
NAS-Systemen den PHP-Worker unverhältnismäßig belasten.

Der direkt in den Nachrichtenvordruck integrierte Upload und die optionale
Archivauswahl akzeptieren unter anderem `.jpg` und `.jpeg` sowie `.tif` und
`.tiff` unabhängig von Groß-/Kleinschreibung. Standardisierte `.eml`-Dateien
sind ebenfalls zulässig; Outlook-`.msg` ist nicht unterstützt. Bei `.eml`
müssen zusätzlich zur Endung sowohl der serverseitig erkannte Typ
`message/rfc822` als auch die begrenzt geprüfte MIME-Struktur passen.
Dateiendung und serverseitig
erkannter MIME-Typ müssen zusammenpassen; eine nur in `.jpeg` umbenannte
Fremddatei bleibt deshalb gesperrt. Formular und Fehlermeldung zeigen die
effektive Anwendungsgrenze verständlich an. Bestehende Installationen, deren
`.env` noch ausdrücklich `ESTAB_UPLOAD_MAX_BYTES=5242880` enthält, behalten
das alte 5-MiB-Limit, bis der Wert bewusst auf `20971520` angehoben und der
App-Container neu erzeugt wird.

Der gemeinsame Uploaddienst verarbeitet eine neue Datei zweiphasig im selben
Request. Zuerst reserviert eine Transaktion den internen Namen mit Status 8;
diese inhaber- und einsatzgebundene Zeile ist nicht lesbar. Eine weitere kurze
Staging-Transaktion speichert anschließend die erwartete Dateiendung, noch
bevor ein Zielpfad an den Uploader übergeben wird. Danach werden die Bytes
verschoben und ohne langen Einsatz-Lock gehasht, damit eine langsame NAS-Ablage
keine operativen Datenbankzeilen hält. Die Finalisierungstransaktion
beansprucht die Zeile mit Status 2, prüft erwarteten aktiven Einsatz und
Kontofunktion erneut und schreibt Metadaten, SHA-256, Bytezahl, Serverzeit,
sichtbaren Status 1 sowie Audit atomar. Ein Fehler in dieser Transaktion rollt
vollständig auf die unsichtbare Status-8-Reservierung zurück.

Die normale Fehlerbereinigung prüft den Zustand anschließend über eine neue
Datenbankverbindung. Das schützt auch vor einem aus Clientsicht uneindeutigen
Commit: Eine inzwischen als Status 1 bestätigte Datei wird niemals gelöscht.
Nur eine unfertige Status-8-Zeile mit exakt passendem Inhaber und Einsatz darf
bereinigt werden. Der Dienst validiert unter einer Zeilensperre Besitzer und
gespeicherte Endung und beansprucht diese Zeile atomar als Status 2. Erst nach
diesem Commit validiert er den Zielpfad, löscht die Staging-Datei, bestätigt
deren Fehlen und gibt den internen Namen als Status 4 frei. Neue Uploads
verwenden nur Status-8-Reservierungen; deshalb kann niemand die Zeile zwischen
Prüfung und Löschen wiederverwenden oder finalisieren. Schlägt das Entfernen
auf einer NAS-Ablage fehl oder wird die Bereinigung hart unterbrochen, bleibt
die Status-2-Cleanup-Zeile absichtlich unsichtbar gesperrt und der Fehler wird
protokolliert; derselbe interne Name kann dann nicht über verwaiste Bytes
gelegt werden. Bei einem schon vor der Beanspruchung unklaren Zustand bleibt
die vorhandene Zeile unverändert gesperrt. Der
historische Archiv-Upload verwendet denselben Dienst mit seinem bereits
reservierten internen Namen; die optionale Archivauswahl verknüpft dagegen nur
eine bestehende Datei. „Vom Vordruck entfernen“ löscht keine Datei aus
`estab_data`,
sondern entfernt nur die exakte Referenz aus dem noch bearbeitbaren Entwurf;
die dadurch freie Datei bleibt nach den strengeren Freianlagenrechten
verfügbar. Eine tatsächliche Archivlöschung ist damit nicht verbunden.

Jede direkte Formularaktion erhält zudem ein einmaliges, an Konto, Einsatz,
Bearbeitungsart und bei Korrekturen an den Datensatz gebundenes Aktionstoken.
Nach einem Upload merkt die Sitzung die Referenz vor; unmittelbar vor der
Nachrichtentransaktion wird dieser Zwischenstand durch
`session_write_close()`/`session_start()` dauerhaft geschrieben. Der
Nachrichten-INSERT beziehungsweise die Korrektur nimmt nur den SHA-256-Hash des
Tokens in das unveränderliche Nachrichtenereignis desselben Commits auf. Ein
tokenbezogener MariaDB-Advisory-Lock serialisiert die Suche nach diesem
Aktionsnachweis und den Nachrichtenspeicherpfad. Ein Retry nach einem
Antwortverlust kann deshalb Einsatz, Akteur, Vorgang und gegebenenfalls
Korrekturdatensatz exakt wiedererkennen und erzeugt nach einem belegten Commit
keine zweite Nachricht. Ein Retry nach einem Validierungsfehler kann die
bereits archivierte Anlage auch ohne erneut gesendeten Dateiteil an den offenen
Entwurf hängen.

Ist ein Nachrichtenabschluss nicht durch das unveränderliche Ereignis belegt,
wird der Entwurf mit Anlage und einer Aufforderung zur Prüfung der
Meldungsliste angezeigt. Die Anwendung wertet eine anderweitig verknüpfte
Anlage nie automatisch als Beweis für das Speichern dieses Entwurfs. Die
Anlage wird vor der fachlichen Nachrichtenvalidierung archiviert; bleibt ein
danach fehlerhafter Entwurf liegen oder wird bewusst verlassen, bleibt die
Datei als freie, berechtigungsgeschützte Archivdatei erhalten und wird nicht
automatisch gelöscht.

#### Verbleibende Crashgrenze bei direkten Anlagen

MariaDB, Anlagenvolume und PHP-Sitzung besitzen keine gemeinsame verteilte
Transaktion. Bei regulären Exceptions läuft die oben beschriebene sichere
Bereinigung; ein harter Worker-, Container- oder Hostabbruch kann `finally`
jedoch überspringen. Erfolgt er nach dem Verschieben, aber vor der
Finalisierung, können eine unsichtbare Status-8-Reservierung und die zugehörige
Staging-Datei liegen bleiben. Wird die reguläre Bereinigung nach ihrer atomaren
Beanspruchung Status 8 → Status 2 hart beendet, kann stattdessen eine verborgene
Status-2-Cleanup-Zeile mit noch vorhandener oder bereits entfernter Datei
liegen bleiben. Nicht löschbare Bytes führen absichtlich ebenfalls zu diesem
Status-2-Zustand. Erfolgt der Abbruch nach der erfolgreichen
Anlagenfinalisierung, aber vor dem persistierten Session-Checkpoint, kann eine
freie Archivdatei ohne dauerhafte Aktionstoken-Zuordnung verbleiben; ein
erneuter Upload kann dann eine zusätzliche Archivdatei erzeugen. Enthält der
Nachrichten-Commit bereits den unveränderlichen Aktionsnachweis, verhindern
dieser und der Advisory-Lock auch bei einem Abbruch vor der letzten
Sessionaktualisierung eine stille Doppelnachricht.

Solche Reste sind eine Betriebsstörung und werden nicht automatisch als
erfolgreicher Vordruck interpretiert. Zuerst müssen Datenbankstatus, Einsatz,
Inhaber, gespeicherte Endung und tatsächlicher Dateipfad zusammen geprüft
werden. Für eine manuelle Bereinigung gilt zwingend dieselbe Reihenfolge wie im
Dienst: finalisierte Status-1-Dateien nicht entfernen; während einer
kontrollierten Wartung ohne parallele Uploads eine eindeutig zuordenbare
Status-8-Reservierung zuerst mit derselben bedingten Zeilenprüfung atomar auf
Status 2 beanspruchen, danach ausschließlich den validierten Pfad löschen und
das Fehlen der Datei bestätigen und erst dann Status 2 auf 4 freigeben. Eine
zurückgebliebene Status-2-Zeile darf erst nach derselben Zuordnungs- und
Pfadprüfung fortgesetzt werden; bei nicht entfernten Bytes bleibt sie
gesperrt. Vor manuellen Eingriffen sind Datenbank und `estab_data` gemeinsam zu
sichern. Eine zusätzliche freie Status-1-Datei wird ausschließlich nach den
normalen Archiv- und Freianlagenrechten behandelt und darf nicht wegen eines
vermuteten Retries ungeprüft gelöscht werden.

Für das Monitoring sind insbesondere die Logmarker
`eStab attachment cleanup state is unavailable`,
`eStab staged attachment cleanup failed; reservation retained`,
`eStab staged attachment cleanup rejected an unexpected path`,
`eStab attachment cleanup retained an unsafe reservation state` und
`eStab attachment reservation cleanup failed` relevant. Status 2 ist während
Finalisierung und Cleanup kurzzeitig normal; Status-8- oder Status-2-Zeilen,
die in einem kontrollierten Zeitraum ohne laufende Uploads bestehen bleiben,
müssen dagegen zusammen mit dem Anlagenvolume geprüft werden. Es gibt bewusst
keinen blinden Janitor, der solche Zeilen oder freie Status-1-Dateien allein
aufgrund ihres Status löscht.

Direktes Hochladen und Entfernen ist auf die bearbeitbaren Vorgänge
`FM-Eingang`, `FM-Eingang_Anhang`, `Stab_schreiben`, `Stab_korrigieren` und
`Stab_gesprnoti` begrenzt. Spätere Arbeitsschritte von LdF, Si und Fernmelder
sehen die Karten nur lesend. Pro Nachricht sind höchstens 100 kanonische Anlagenreferenzen
zulässig.

Der authentifizierte Inline-Abruf ist ausschließlich für serverseitig als
JPEG, PNG, GIF, BMP oder PDF erkannte Anhänge zulässig. TIFF und andere Formate
behalten auch bei angeforderter Browseransicht eine Download-Disposition.
Download und Inline-Abruf verwenden denselben autorisierten,
integritätsgeprüften Byte-Snapshot und liefern `no-store`, `nosniff` sowie
eine restriktive Content-Security-Policy. Verifizierte PDF-Inlineantworten
dürfen ausschließlich von derselben Origin eingebettet werden. Eine
HTML-Sandbox wird dort bewusst nicht gesetzt, weil sie Chromiums eingebauten
PDF-Viewer sperrt; alle übrigen Antworten bleiben für Einbettung gesperrt. Die
PDF-Karte lädt den Viewer erst beim Aufklappen. Ob der Inhalt tatsächlich
angezeigt wird, hängt zusätzlich von der PDF-Unterstützung des Browsers ab.

E-Mail-Anlagen verwenden nicht diesen rohen Inline-Abruf. Der positive
Runtime-Pfad `/4fach/email.php` liest ausschließlich `.eml` und erzeugt daraus
eine passive UTF-8-Textansicht; das Containerimage und der Runtime-Surface-
Vertrag führen diesen Endpunkt sowie den zentralen E-Mail-Parser ausdrücklich
in ihrer Allowlist. Der Controller erlaubt nur GET und HEAD, löst eine fehlende
Fachsitzung über den normalen Anmeldeweg und wiederholt Objektberechtigung und
Integritätsprüfung unmittelbar vor der Darstellung. Erfolgreiche Antworten
tragen unter anderem `no-store`, `nosniff`, `SAMEORIGIN`, `no-referrer`, eine
restriktive Content-Security-Policy und einen Marker für passive Darstellung.
Rohe Mail-HTML-Inhalte, Skripte, Ereignisattribute, Formulare, eingebettete
Objekte und Remote-Ressourcen werden nicht ausgeführt oder nachgeladen.
Interne Mail-Anlagen werden nur mit Metadaten aufgelistet.

Die Ansicht weist sichtbar darauf hin, dass angezeigte From-/To-/Cc-/Datums-
und Betreffzeilen keine verifizierte Identität belegen. eStab führt weder eine
DKIM- noch eine S/MIME-Authentizitätsprüfung durch. Der getrennte Link zur
Originaldatei läuft über den normalen authentifizierten Downloadpfad, liefert
die integritätsgeprüften Bytes unverändert und erzwingt weiterhin eine
Download-Disposition. Diese Bytegleichheit ist kein Malware-Nachweis: Die
Originalmail kann aktive oder anderweitig riskante Inhalte und enthaltene
Dateien transportieren und darf betrieblich nur in einer geeigneten
Prüfumgebung geöffnet werden.

Eine Bildkarte versucht nur bei JPEG, PNG, GIF und BMP bis 16 Megapixel und
höchstens 24 MiB Dateigröße eine GD-Miniatur zu dekodieren. Der Endpunkt
begrenzt jede angeforderte Ausgabeachse auf 1.600 Pixel; die Kartenansicht
fordert 640 Pixel Breite an. Größere oder nicht sicher dekodierbare Originale
erhalten einen neutralen Platzhalter; Download und zulässige separate
Browseransicht bleiben möglich. Diese Schutzgrenzen für die interaktive
Vorschau sind unabhängig von der 12-Megapixel-/8.000-Pixel-Grenze des
PDF-Dossier-Renderers und dürfen nicht mit ihr gleichgesetzt werden.

Download und Bildvorschau übernehmen nach der Identitätsprüfung die
Sitzungsdaten und geben den PHP-Session-Lock sofort frei. Sie autorisieren
zunächst kurz gegen die Datenbank, beenden diese Transaktion vor dem
zeitaufwändigen Hashen/Kopieren und autorisieren das unveränderte
Anlagenobjekt unmittelbar vor dem Start der Ausgabe mit aktuellen, sperrenden
Lesezugriffen erneut. Damit blockiert ein großer Download auf einem NAS weder
andere Requests noch hält er während der Dateiarbeit Datenbankzeilen fest;
eine bis zu dieser Abschlussprüfung wirksame Rechte- oder Objektänderung
unterbindet die Ausgabe. Wie bei jeder bereits begonnenen HTTP-Antwort kann
eine erst nach dem Abschluss-Commit eintretende Sperrung bereits freigegebene
Antwortbytes nicht rückwirkend zurückholen.

Im PDF-Einsatzdossier werden JPEG, PNG, GIF und BMP sichtbar ausgegeben;
mehrseitige PDFs werden mit Poppler einschließlich ihrer Anmerkungen
seitenweise gerastert. Textdateien erscheinen nur, wenn ihr Inhalt verlustfrei
mit Windows-1252 darstellbar ist. Strukturell gültige `.eml`-Dateien erscheinen
innerhalb derselben PDF-Text-/Zeichengrenzen als passive E-Mail-Darstellung mit
ausgewählten Kopfzeilen und Textkörper; enthaltene Mail-Anlagen werden nur als
Metadaten aufgeführt. Andernfalls folgt eine gekennzeichnete Hinweisseite.
Andere zulässige Uploadformate wie TIFF,
ZIP, Office oder Video sowie nicht darstellbarer Text erhalten eine klare
Hinweisseite und bleiben als bytegleiches Original eingebettet.

Der App-Entrypoint validiert vor dem Apache-Start DB-Name und -Port,
Uploadgrenzen, URL/Basispfad, alle booleschen Schalter sowie die
Proxy-Allowlist. Ports außerhalb `1` bis `65535`, Uploadgrenzen außerhalb
`1` Byte bis 50 MiB, PDF-Anhangsgrenzen außerhalb `0` bis 50 MiB und
syntaktisch ungültige Werte beenden den Container mit einem klaren
Konfigurationsfehler. `/health.php` und der administrative Systemstatus
verwenden dieselbe Prüfung.
Zusätzlich normalisiert er ausschließlich die sechs dokumentierten
schreibbaren App-/Export-/Sitzungsverzeichnisse auf `www-data:www-data`,
Modus `0770` und eine leere erweiterte beziehungsweise Default-POSIX-ACL.
Damit kann ein vorhandener Named-User-ACL-Eintrag nicht durch das Setzen der
Gruppenmaske unbemerkt aktiviert werden; eine nicht prüfbare ACL beendet den
Start.

Die Datenbank-Initialisierung wertet Datenbankname, Benutzer und
Datenbank-Secrets nur aus, wenn `/var/lib/mysql` leer ist. Nach dem ersten
Start dürfen diese Werte nicht einfach geändert werden. Eine
Datenbankkennwort-Rotation muss mit `ALTER USER` in MariaDB koordiniert werden;
eine reine Änderung der Secret-Datei trennt sonst die Anwendung von der
Datenbank.

### 2. Konfiguration prüfen und Image bauen

```console
podman compose config >/dev/null
podman compose build --pull migrate app
```

Der App-Build bricht ab, wenn eine der benötigten PHP-Erweiterungen `gd`,
`mbstring`, `mysqli`, `Zend OPcache` oder `zip` fehlt. Das separate
Migrationsimage enthält den MariaDB-Client, das kanonische Basisschema, den
checksum-prüfenden Runner, die versionierten SQL-Dateien und die vollständige
Schema-Verifikation.

### 3. Stack starten

```console
podman compose up -d
podman compose ps
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Beim ersten Start legt der offizielle MariaDB-Entrypoint nur Datenbank und
DB-Benutzer an. Danach läuft der einmalige Service `migrate`. Ist der
`nv_*`-Namensraum vollständig leer, bindet er das im Image eingebettete
`docker/db/init/10-schema.sql` vor dem ersten DDL per SHA-256 an den Zustand
`applying` in `estab_schema_baselines`. Nach allen 14 Tabellen wird derselbe
Datensatz auf `applied` gesetzt. Ein harter Abbruch lässt dadurch einen
prüfbaren Zustand zurück; der nächste Lauf akzeptiert ausschließlich dieselbe
Prüfsumme und ergänzt das idempotente Basisschema. Unprotokollierte einzelne
`nv_*`-Tabellen ohne die Kerntabelle bleiben als unklare Teilinstallation
gesperrt. Ein vorhandenes Legacy-Schema mit Kerntabelle wird nicht mit der
Fresh-Baseline überschrieben. Anschließend verarbeitet der Runner jede noch
nicht protokollierte Datei unter `docker/db/migrations/` in
Dateinamenreihenfolge, speichert Version, SHA-256, Status und Zeitpunkt in
`estab_schema_migrations` und führt `docker/db/verify.sql` aus.

Migration 40 erkennt ihre eigene Standardmatrixtabelle an einer eindeutigen
Tabellenmarkierung. Nach einem Abbruch zwischen `CREATE TABLE`, Seed und
Ledgerabschluss wird nur eine leere oder exakt kanonisch gesetzte eigene
Tabelle automatisch fortgesetzt. Eine fremde gleichnamige Tabelle oder
abweichende Inhalte bleiben unverändert gesperrt und müssen anhand von Backup,
Tabellenkommentar und Migrationsledger geprüft werden.

Die vorbereitende Migration 45 verlangt vor der unverändert veröffentlichten
Einsatzmigration 50 alle zehn einsatzrelevanten operativen Basistabellen.
Fehlt in einem erkannten Legacy-Schema beispielsweise ETB, TBB oder Protokoll,
bricht sie mit
`Incident migration blocked: required operational table is missing` ab und
legt weder Einsatz-Tabellen noch einen erfolgreichen Ledgerdatensatz an. Ein
solcher beschädigter Bestand wird nicht durch leere Ersatztabellen
umgedeutet; er muss aus einem geprüften Backup beziehungsweise gegen die
historische Schemaquelle vollständig rekonstruiert werden. Migration 45
entfernt außerdem für die Dauer des Backfills die beiden
`ON UPDATE CURRENT_TIMESTAMP`-Attribute von
`nv_nachrichten.99_lstacc` und `nv_bhp50.sich1_zeit`. Migration 55 stellt
deren kanonische Definition nach Migration 50 wieder her. Dadurch bleiben
historische Zeitwerte erhalten, ohne die bereits angewendete und
checksum-gebundene Migration 50 nachträglich zu verändern.

Der App-Service hängt mit `service_completed_successfully` sowohl von diesem
Lauf als auch vom netzlosen One-shot-Service `admin-auth-init` ab. Dieser
liest als einziger App-Image-Prozess das Admin-Klartextsecret und ersetzt die
abgeleitete bcrypt-`htpasswd` atomar im privaten `estab_auth`-Volume. Der
Webcontainer mountet dieses Volume schreibgeschützt und erhält weder das
Admin-Secret-Mount noch einen entsprechenden Umgebungswert. Bei SQL-Fehler,
doppeltem Anhangnamen, geänderter Prüfsumme, fehlgeschlagener
Schema-Verifikation oder ungültiger Authentisierungsdatei bleibt die
Anwendung aus. Erst danach legt der App-Entrypoint die beschreibbaren
Verzeichnisse für Anhänge, Vordrucke, Exporte und Sitzungen an.

Das Admin-Secret muss aus genau einer Zeile mit 16 bis 72 Bytes bestehen.
`admin-auth-init` verwendet einen expliziten bcrypt-Kostenfaktor von 12 und
bricht vor dem Webstart ab, wenn das Kennwort zu kurz wäre oder von bcrypt
stillschweigend abgeschnitten würde. Die oben gezeigte
`openssl rand -base64 36`-Erzeugung erfüllt diese Grenze.

MariaDB läuft explizit mit
`STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO`
und `NO_ENGINE_SUBSTITUTION`. Damit werden ungültige Legacy-Defaults oder
stilles Abschneiden nicht durch lockere Servervorgaben verdeckt.
Bestandsmigrationen lockern den Modus nur für ihre eigene Sitzung und stellen
ihn anschließend wieder her.

### 4. Schema freigeben

```console
podman compose run --rm migrate
```

Ein bereits aktueller Bestand meldet alle neunzehn Migrationen einschließlich
`113-password-policy.sql` als vorhanden und
führt trotzdem den vollständigen Read-only-Schematest aus. Die Ausgabe muss
`Post-migration schema verification passed` und anschließend
`All schema migrations are applied` enthalten. Erst danach sollte der Stack
fachlich freigegeben werden.

Migration 100 ergänzt den UTC-Zeitstempel und Index für die
Aktivitätsübersicht. Da vor dem Upgrade bereits aktive SIDs keine verlässliche
letzte Browserinteraktion besitzen, werden sie einmalig abgemeldet. Dieser
erwartete Sicherheitsübergang betrifft ausschließlich eStab-Funktionskonten:
Nach dem Upgrade müssen sich diese Benutzer neu anmelden. Der separate
HTTP-Basic-Administrationszugang bleibt verfügbar.

Migration 110 ergänzt die einsatzlokalen ETB-/TBB-Nummern, exakt zwei
sperrbare Buchköpfe pro Einsatz, strukturierte TBB-Felder,
einen eindeutigen ETB-Anhangsbezug, Append-only-/Korrekturgrenzen und die
zehnjährige Mindestaufbewahrung. Bereits
geführte Bücher werden deterministisch nummeriert und als Legacy-Bestand
erhalten; neue Eröffnungstatsachen werden nicht rückwirkend erfunden. Neue
Einsätze erhalten ihre leeren Köpfe `ETB:1` und `TTB:1` unmittelbar durch den
Einsatz-Insert-Trigger. Ein fehlender Kopf wird bei einer Eintragung nicht
neu erzeugt, sondern als Datenbankfehler gemeldet. Die MariaDB-
Standardisolation `REPEATABLE READ` bleibt unverändert; insbesondere bleiben
die konsistenten Read-only-Export-Snapshots aktiv.
Der grüne technische Stand ersetzt nicht die von der ETB-/TBB-Unterlage
verlangte formale THW-Freigabe. Quelle, Hash und Abnahmevorbehalt stehen in
[DV-1-101-UMSETZUNG.md](DV-1-101-UMSETZUNG.md).

### 5. Ersten Einsatz aktivieren und Zugänge optional gruppieren

Eine Neuinstallation startet absichtlich ohne aktiven Einsatz. Anmeldung,
öffentliche Ansichten und Administration bleiben erreichbar; operative Lese-
und Schreibpfade sind geschlossen. Vor der ersten
Nachricht, dem ersten Anhang oder einem ETB-/TBB-Eintrag:

1. `/4fadm/admin.php` mit dem separaten Basic-Auth-Zugang öffnen,
2. unter **Einsätze** Kennung, genaue Einsatzbezeichnung, Beginn,
   Bedarfsträger, Name der Führungsstelle, verantwortliche
   Einsatz-/Führungsleitung sowie Einsatzauftrag/Ausgangslage anlegen und den
   Einsatz aktivieren; beim Anlegen müssen genau die beiden noch leeren
   Nummernköpfe `ETB:1` und `TTB:1` entstehen,
3. unter **Kennwortrichtlinie** die Auslieferungsvorgabe prüfen und bei Bedarf
   nach einer Vorher-/Nachher-Vorschau revisionsgesichert anpassen,
4. unter **Benutzerverwaltung** persönliche Konten mit der jeweils festen
   Funktion anlegen; die Rolle wird serverseitig abgeleitet,
5. optional unter **Führungsstellenbetrieb** Zugangsschichten anlegen, Konten
   zuordnen und gewünschte Gruppen aktivieren,
6. jede Person regulär anmelden und Einsatz sowie feste Funktion in der
   Oberfläche prüfen.

Eine Dienst- oder Zugangsschicht ist keine fachliche Voraussetzung für
operative Eingaben. Ist ein Konto keiner Gruppe zugeordnet, bleibt sein Zugang
erlaubt. Bei mehreren Zuordnungen genügt für den Kontozugang eine aktive
Gruppe. Das Aktivieren meldet niemanden an; das Deaktivieren widerruft
Sitzungen der betroffenen Konten, sofern keine andere aktive Gruppe verbleibt.
Eine Zugangsschicht ändert niemals Funktion, Rolle oder Schreibrecht. Die
manuelle Kontosperre bleibt ein unabhängiger, vorrangiger Mechanismus.

Historische formale Dienstschichten, Besetzungen und Übergaben werden nicht
gelöscht. Im Abschnitt **Dienstorganisation** des Exports erscheinen sie
getrennt als historischer Legacy-Nachweis. Derselbe Abschnitt enthält die
optionalen Zugangsschichten samt aktuellen und entfernten Kontenzuordnungen.
Die Altwerte steuern weder heutige Berechtigungen noch den Einsatzabschluss.

Eine ETB-Anlage wird optional beim Erfassen des ETB-Eintrags aus den
finalisierten, noch unbenutzten Anhängen des aktiven Einsatzes ausgewählt.
Nach dem Speichern zeigt eStab
`ETB {einsatz_id}-{estab_book_lfd}-1`; ein Ablagekennzeichen wie `EL0001`
bleibt getrennt. Dieselbe Datei darf nicht erneut als ETB-Anlage angeboten
oder verknüpft werden. Über die ETB-Suche lassen sich leere Gesamtliste,
Volltext, Art, lokale Nummer/Bezug, ETB-Anlagennummer, Ablage-/Dateiname und
Bearbeitungszuordnung kombinieren. Eine optionale Zuordnung bleibt reine
Suchhilfe, erweitert keine Rechte und wird als unveränderlicher Snapshot
abgelegt. Sie erscheint nicht im amtlichen Fb-Fü-2-PDF.

Das Feld **Referenz auf ETB-Nr.** nimmt bei neuen Einträgen ausschließlich die
positive lokale Nummer eines bereits vorhandenen ETB-Eintrags desselben
Einsatzes an. Freitext, führende Nullen, technische Primärschlüssel und
unbekannte Nummern werden abgewiesen. Historische freie Bestandsbezüge bleiben
les- und suchbar, werden aber nicht als nachträglich konstruierte Verknüpfung
ausgewertet. Bei einer Berichtigung ermittelt der Server die sichtbare lokale
Nummer aus dem unveränderlichen Original; sie wird nicht vom Browser
übernommen.

Unter **ETB-Referenzen auswerten** lässt sich zu einer lokalen Startnummer der
vorwärts gerichtete, gegebenenfalls verzweigte Nachweis aller referenzierenden
Einträge oder rückwärts der Bezugspfad anzeigen. Die Auswertungstiefe ist auf
1 bis 25 begrenzt. Eine abgeschnittene Auswertung wird ausdrücklich markiert;
**Druckansicht öffnen** erzeugt eine auf diesen Referenznachweis beschränkte
Ansicht.

Neue ETB-/TTB-Zeilen dürfen die Legacy-Felder für aktive Dienstschicht und
schreibende Dienstbesetzung `NULL` lassen. Sie werden nicht mit einer
Zugangsschicht befüllt; historische belegte Provenienz bleibt unverändert.
ETB schreiben Konten mit `ETB/Stab` oder `S2/Stab`; das TTB führen Konten mit
der festen Funktion `Fernmelder`. Anwendung und Datenbank prüfen feste Funktion, Rolle,
ungesperrtes Konto und aktiven Einsatz, aber keine aktive Schicht.
Die Aktivierung eines neuen, noch leeren Einsatzes eröffnet ETB und TTB
atomar mit je einer Systemzeile; beide Provenienzfelder bleiben dabei `NULL`.
Enthält ein übernommener Alt-Einsatz bereits Buchzeilen, wird seine belegte
Reihenfolge nicht durch eine nachträgliche Eröffnungszeile verändert.

Der Dossierexport weist ETB-Anlagennummer und Ablagekennzeichen im
Fb-Fü-2-Abzug beziehungsweise Anlagenverzeichnis aus. Für ETB/TBB wählt die
Administration das Gesamtbuch oder bei historischem Bestand eine frühere
formale Dienstschicht. Dieser Legacy-Filter umfasst keine Zugangsschicht und
keine neue Zeile mit `NULL`-Provenienz. Er filtert nur diese beiden
Buchabschnitte; Nachrichtenvordrucke, Anhänge und alle
weiteren ausgewählten Abschnitte bleiben einsatzweit. Deckblatt und
`pdf_export`-Audit dokumentieren den aufgelösten Umfang.

Ein formaler Einsatzabschluss verlangt weder eine frühere Schichtaktivierung
noch schichtbezogene Eröffnungszeilen. Offene historische formale Schichten
blockieren ihn ebenfalls nicht; fachlich offene Nachrichten, Melderaufträge
und andere echte Abschlussblocker bleiben wirksam.

Ein Einsatzwechsel gilt systemweit für alle angemeldeten Browser. Er darf nur
koordiniert erfolgen, wenn keine ungespeicherten Fachvorgänge offen sind.
Historische Daten bleiben ihrem vorherigen Einsatz zugeordnet und werden
nicht in den neuen Statusraum umgehängt.

Migration 97 lässt den Führungsstellennamen bestehender Einsätze bewusst
`NULL`: Einsatzname, Bedarfsträger und Einsatzleitung wären keine belegbaren
historischen Ersatzwerte. Ein offener Alt-Einsatz muss deshalb unter
**Einsätze** einmalig mit seinem tatsächlichen Führungsstellennamen bestätigt
werden, bevor er aktiviert oder weiter operativ beschrieben werden kann. Die
einmalige Bestätigung eines solchen Fehlwerts ist auch dann zulässig, wenn
bereits historische Fachdaten vorhanden sind. Ein schon belegter Name kann
nur bis zur ersten operativen Eintragung korrigiert werden; danach ist er
durch den dauerhaften Erstschreib-Sperrmarker unveränderlich. Das Löschen
einzelner Fachdaten entsperrt ihn nicht. Formal abgeschlossene Alt-Einsätze
bleiben unverändert. Bei einem aktiven offenen Alt-Einsatz melden Statusleiste
und Administration „Name fehlt“ beziehungsweise „Einsatz unvollständig“; die
historische PDF-Ausgabe kennzeichnet den Wert als „historisch nicht erfasst“.

## Normaler Lebenszyklus

```console
# Zustand
podman compose ps

# laufende Logs einschließlich letztem Migrationslauf
podman compose logs --follow --tail=100 migrate admin-auth-init app db

# Dienste geordnet anhalten; unverändertes, erfolgreiches Gate wiederverwenden
podman compose stop
podman compose up -d

# vollständige Migration-/Schemaprüfung bewusst erneut ausführen
podman compose stop app
podman compose up --force-recreate migrate
podman compose up -d app

# App nach einer Änderung an nicht geheimen Laufzeitwerten neu erzeugen
podman compose up -d --force-recreate app

# neue Version nur nach Vollbackup bauen und kontrolliert ausrollen
podman compose build --pull migrate app
podman compose stop app
podman compose up --force-recreate migrate
podman compose up -d app
```

Der Vordergrundlauf von `migrate` muss mit Exitcode 0 enden. Schlägt er fehl,
bleibt `app` gestoppt; zuerst Logs und Datenbestand klären, nicht das
Compose-Gate umgehen.

OPcache prüft im laufenden Produktionscontainer keine Datei-Zeitstempel.
Änderungen am Quellcode werden deshalb ausschließlich durch einen neuen
Image-Build und Container-Recreate ausgerollt, nicht durch Änderungen in einem
laufenden Container.

`podman compose down` entfernt Container und Netzwerke, behält aber die drei
benannten Volumes. `podman compose down --volumes` entfernt auch die
persistenten Daten und ist ein destruktiver Neuaufbau.

## Benutzer und Administration

Die Anwendung hat zwei getrennte Anmeldebereiche:

1. Funktionsbenutzer wählen auf `/` unmittelbar „Mit bestehendem Konto
   anmelden“ oder – sofern freigeschaltet – „Neues Konto anlegen“. Das
   Nachrichtenvordruck-Modul öffnet das passende Formular direkt in seinem
   rechten `iframe` namens `mainframe`. Neue und geänderte Kennwörter werden
   mit Argon2id gespeichert. Ein beim Altimport vorhandenes Klartextkennwort
   oder ein anderer eindeutig verifizierbarer Alt-Hash wird erst nach
   erfolgreichem Login auf Argon2id umgestellt. bcrypt wird nur bei einem
   eingegebenen Kennwort unter 72 UTF-8-Bytes automatisch migriert; ab 72 Bytes
   ist wegen der Suffixambiguität ein administrativer Kennwortreset nötig.
2. `/4fadm` verwendet den in `.env` konfigurierten Admin-Benutzer und das
   separate Admin-Secret als HTTP Basic Auth. Der im historischen Pfad
   verbliebene Vordruckreset `/4fach/resetpic.php` liegt hinter demselben Schutz.

Unter `/4fadm/users.php` legt dieser technische Administrator die
eStab-Funktionskonten mit Name, eindeutigem Kürzel, Startkennwort und fester
Funktion an. Die Rolle wird aus der aktiven Empfängermatrix abgeleitet und ist
nicht frei wählbar. Eine administrative Neuzuweisung ersetzt die bisherige
Funktion und beendet eine aktive Fachsitzung. „Konto sperren“ beendet ebenfalls
eine aktive Fachsitzung und verhindert neue Anmeldungen; „Konto entsperren“
erzeugt keine Sitzung. Ein Kennwortreset verlangt das neue Kennwort zweimal,
lässt eine bestehende Sperre unverändert und widerruft jede alte Sitzung. Der
Klartext wird weder angezeigt noch protokolliert. Der technische
Basic-Auth-Zugang selbst wird
weiterhin ausschließlich über `ESTAB_ADMIN_USER` und die
`admin_password.txt`-Secret-Datei geändert. Details und Auditvertrag stehen in
[Benutzerverwaltung](BENUTZERVERWALTUNG.md).

### Kennwortrichtlinie für Funktionskonten

Unter `/4fadm/password_policy.php` legt der technische Administrator die
Richtlinie für **künftig gesetzte** Funktionskonto-Kennwörter fest. Die
konfigurierbare Mindestlänge kann zwischen 8 und 128 Unicode-Codepoints liegen;
nach Installation beträgt sie 12 Unicode-Codepoints. Zusätzlich lassen sich je
mindestens ein
Unicode-Groß- oder Titlecase-Buchstabe, Unicode-Kleinbuchstabe, eine
Unicode-Ziffer und ein Interpunktions- oder Symbolzeichen unabhängig
verpflichtend machen.
Leerzeichen bleiben in Passphrasen zulässig, gelten aber nicht als
Sonderzeichen. Unicode-Steuerzeichen sind immer unzulässig; Formatzeichen wie
der ZWJ in Emoji-Sequenzen sind erlaubt.

Die Oberfläche zeigt zunächst den aktuellen Revisionsstand und anschließend
eine Vorher-/Nachher-Vorschau. Erst eine ausdrückliche Bestätigung speichert
die Änderung. Eine Abschwächung wird sichtbar markiert. Ein zwischenzeitlich
geänderter Revisionsstand ergibt HTTP 409, statt eine neuere Einstellung zu
überschreiben. Richtlinie und Audit committen gemeinsam; ein fehlgeschlagenes
Audit lässt den alten Stand wirksam.

Die Richtlinie gilt für administrative Kontoanlage, Kennwortreset und die
gegebenenfalls mit `ESTAB_ALLOW_SELF_REGISTRATION=true` freigegebene
Selbstregistrierung. Sie wird serverseitig unter einem globalen Lock gelesen,
bevor ein neuer Hash und Kontozustand geschrieben werden. Eine Änderung prüft
oder sperrt vorhandene Kennwörter nicht nachträglich und widerruft keine
Sitzung. Auch das getrennte technische HTTP-Basic-Kennwort bleibt unberührt;
es wird weiterhin nur über die Secret-Datei und `admin-auth-init` rotiert.

Neue und geänderte Funktionskonto-Kennwörter werden mit Argon2id gespeichert.
Die konfigurierbare Mindestlänge beträgt 8 bis 128 Unicode-Codepoints;
zusätzlich gilt eine serverseitige Grenze von 1024 UTF-8-Bytes. Das
Browserfeld erlaubt 1024 Eingabeeinheiten; sein JavaScript zählt die
konfigurierbare Mindestlänge exakt in Unicode-Codepoints, die Serverprüfung
bleibt verbindlich. Importierte Klartextwerte und andere eindeutig
verifizierbare Alt-Hashes bleiben anmeldbar und werden erst nach erfolgreicher
Anmeldung auf Argon2id umgestellt. bcrypt wird nur bei einem eingegebenen
Kennwort unter 72 UTF-8-Bytes automatisch migriert. Bei 72 oder mehr Bytes
bleibt der ambivalente Alt-Hash unverändert; ein administrativer Reset ist für
Argon2id erforderlich. Bereits stärkere oder gemischte Argon2id-Kosten werden
nicht auf die Standardkosten zurückgestuft; nur vollständig schwächere Profile
werden hochgestuft.

Migration `113-password-policy.sql`, `/health.php`, der administrative
Systemstatus und `docker/db/verify.sql` erwarten genau eine kanonische
Richtlinienzeile. Ist sie nicht eindeutig oder ungültig, werden neue
Kennwörter fail-closed nicht gesetzt und der Schema-/Readiness-Nachweis
schlägt fehl.

### Präsenz und Leerlaufabmeldung

Die Aktivitätsübersicht wertet ausschließlich echte Browserinteraktion aus.
Ohne Zeiger-, Tastatur-, Formular-, Rad- oder Touchinteraktion beziehungsweise
bewusste Rückkehr in das sichtbare Fenster wechselt ein angemeldeter Benutzer
nach 15 Minuten zu „Inaktiv“. Das ist zunächst nur ein Präsenzzustand: Die
Fachsitzung und die feste Kontofunktion bleiben gültig.

Nach 12 Stunden ohne echte Interaktion widerruft die Anwendung die
Fachsitzung serverseitig. Der nächste geschützte Seitenaufruf führt zum
Bestandslogin; ein nicht verarbeitetes Formular wird nicht automatisch erneut
gesendet. Die Browseroberfläche meldet Aktivität über einen
Session-CSRF-geschützten POST an `/4fach/activity.php`, geteilt über Tabs und
Frames und höchstens einmal pro Minute. Der automatische Statusabruf unter
`/4fach/vorgaben.php?fragment=status`, andere Refreshes und ein bloß offener
Tab gelten nicht als Aktivität. Eine unbeaufsichtigte Statusanzeige hält eine
Sitzung daher nicht unbegrenzt online.

Die PHP-Laufzeit verwendet dazu in `docker/php/estab.ini` und im Bootstrap
`session.gc_maxlifetime = 43200`. Der autoritative Ablauf wird trotzdem bei
jeder Authentisierungsprüfung aus dem UTC-Datenbankzeitstempel ermittelt; die
PHP-Garbage-Collection allein ist kein Sicherheitsnachweis. Fehlende,
ungültige oder zukünftige Zeitwerte laufen fail-closed ab. Diese Fristen sind
keine `.env`-Option und gelten für alle Fachkonten gleich. HTTP Basic Auth für
`/4fadm` wird vom Browser separat verwaltet und unterliegt nicht diesem
Fachsession-Timeout.

Unter `/4fadm/incident_export.php` kann derselbe Administrator einen aktiven
oder historischen Einsatz als PDF-Dossier ausgeben. ETB, TBB,
Nachrichtenvordrucke und Anhänge sind einzeln wählbar; Anhänge
erfordern die Nachrichten. Die Gesamtsumme eingebetteter Dateien
begrenzt `ESTAB_PDF_ATTACHMENT_MAX_BYTES`. `0` deaktiviert nicht den
PDF-Export ohne Anhänge, verhindert aber einen Export mit gewählter
Anhangsektion; dieser bricht sichtbar ab, statt Dateien auszulassen.
Werte über 50 MiB sind auch auf speicherstarken Hosts nicht zulässig.
Nach dem Anlagenverzeichnis folgen JPEG-, PNG-, GIF- und BMP-Bilder sowie jede
Seite einer PDF-Anlage als sichtbare Dossierseiten. Verlustfrei
Windows-1252-darstellbare Textdateien erscheinen durchsuchbar. RFC-822-E-Mails
erscheinen innerhalb der PDF-Text-/Zeichengrenzen ausschließlich als passive
Kopfzeilen-/Textdarstellung; ihre internen Anlagen werden als Metadaten
genannt. Ist das nicht verlustfrei möglich, erhalten auch sie eine
Hinweisseite. TIFF und andere
nicht statisch darstellbare Formate sowie nicht verlustfrei darstellbarer Text
erhalten eine Hinweisseite; ihr bytegleiches Original bleibt wie bei allen
Formaten über die Anlagenansicht des PDF-Lesers extrahierbar. Jede Anlage nennt
am Beginn ihrer Darstellung Dateiname/Endung, erkannten MIME-Typ, Größe und
SHA-256. PDF-Anmerkungen werden nicht ausgeblendet.

Die Darstellung arbeitet ausschließlich auf dem bereits gegen Eingangsgröße
und -hash geprüften Byte-Snapshot und öffnet den Quellpfad nicht erneut.
Fileinfo erkennt den MIME-Typ atomar aus exakt diesen eingebetteten Bytes; eine
Abweichung vom zuvor ermittelten MIME-Typ oder von der für sichtbare Formate
erlaubten Endung beendet den Export fail-closed.

Die festen Rendergrenzen betragen 100 Seiten je PDF-Anlage, 200 sichtbare
Anlagenseiten je Dossier, 24 MiB Rasterdaten insgesamt, 8 MiB je isoliertem
PDF-Seitenprozess beziehungsweise Rasterseite, 12 Megapixel je Bild und 8.000
Pixel je Achse für JPEG/PNG/GIF/BMP sowie 512 KiB für sichtbaren Text.
Die gesamte Anlagendarstellung besitzt ein gemeinsames 60-Sekunden-Budget;
`pdfinfo` und jeder einzelne PDF-Seitenprozess erhalten davon höchstens 15
Sekunden. Beschädigte, verschlüsselte, falsch benannte oder zu große
darstellbare Anlagen lassen den Export fail-closed abbrechen.

Der App-Container liefert dazu GD mit
JPEG-/PNG-/GIF-Lese-/BMP-Unterstützung sowie `pdfinfo`, `pdftoppm` und
`prlimit`; diese Werkzeuge werden bereits beim Image-Build geprüft. Ein selbst
gebautes alternatives Laufzeit-Image muss denselben Vertrag erfüllen.

Jeder normale Lauf entfernt seinen privaten Render-Arbeitsbereich selbst. Für
einen hart beendeten PHP-Prozess startet der App-Entrypoint zusätzlich den
fail-closed Janitor `estab-cleanup-pdf-render-tmp /tmp 1440 www-data`. Er
löscht nur mehr als 24 Stunden alte, kanonisch benannte, `www-data` gehörende
Verzeichnisse mit Modus `0700`, flachem Inhalt und ausschließlich erwarteten
regulären Dateien in Modus `0600` oder `0640` mit Linkzahl eins. Schon ein
unerwarteter Name, Typ, Link, Eigentümer oder Modus bewahrt das ganze
Verzeichnis unverändert; `/`, Symlink-Wurzeln und ungültige Parameter werden
abgewiesen.

Der erfolgreiche `pdf_export`-Audit nennt neben Originalanzahl und -bytes die
Zähler `attachment_visible_count`, `attachment_visible_pages`,
`attachment_rendered_count`, `attachment_rendered_pages` und
`attachment_information_pages`. Damit lässt sich nachträglich unterscheiden,
wie viele Anhänge sichtbar aufgenommen, inhaltlich dargestellt oder nur auf
einer ehrlichen Hinweisseite nachgewiesen wurden.

Führungsstellenname, Einsatzkennung und Einsatzname werden getrennt
ausgewiesen. Ein historisch fehlender Führungsstellenname wird niemals aus
einer Umgebungsvariable oder einem anderen Stammdatum ergänzt.

Angemeldete Funktionsbenutzer öffnen unter `/4fach/vordrucke.php` die
abgeschlossenen Vordrucke des aktiven Einsatzes. Der sichtbare Download wird
aus Nachrichtendatensatz und aktueller Empfängermatrix im Speicher erzeugt und
verwendet dieselbe Vorlage wie das PDF-Dossier. Das ist insbesondere nach
einem Vorlagen-Upgrade wichtig: Bereits persistierte Archiv-PDFs werden nicht
automatisch überschrieben, der Benutzer erhält trotzdem sofort das aktuelle
Layout. Die Spalten **Archivgröße** und **Archivdatei geändert** beziehen sich
ausdrücklich auf die unveränderte Datei im `estab_data`-Volume.

Die Selbstregistrierung ist standardmäßig deaktiviert. Neue Konten legt die
zuständige Stelle unter Administration → Benutzerverwaltung an.
„Mit bestehendem Konto anmelden“ erzeugt auch bei einem unbekannten Kürzel
niemals einen neuen Datensatz. „Neues Konto anlegen“ verlangt das Kennwort
zweimal, zeigt die aktuelle Kennwortrichtlinie und weist bereits vergebene
Kürzel ab, erscheint aber nur nach der
bewussten Kompatibilitätsfreigabe
`ESTAB_ALLOW_SELF_REGISTRATION=true`. Name, eindeutiges Kürzel mit
höchstens sechs Buchstaben, Ziffern oder `_` sowie die organisatorisch
zugeteilte Funktion sind Pflichtangaben; die Rolle ist nicht frei wählbar.
Die öffentliche Kontenliste übernimmt bei Auswahl nur Name, Kürzel und
Funktion, niemals das Kennwort.
Die Richtlinie gilt ausschließlich für den Neuanlagepfad. Ein vorhandenes
Konto darf sich auch nach einer späteren Verschärfung weiterhin mit seinem
gespeicherten Kennwort anmelden; beim Bestandslogin wird deshalb weder eine
neue Mindestlänge noch eine Zeichenklasse browser- oder serverseitig
erzwungen.
Ein Konto kann unabhängig von seinem Sitzungs- oder Präsenzzustand nicht durch
eine abweichende Funktionsauswahl die Rolle wechseln. Einen vorgesehenen
Funktionswechsel führt der technische Administrator in der
Benutzerverwaltung aus; dadurch endet eine vorhandene Sitzung und die nächste
Anmeldung muss die neue Funktion verwenden.

Das gemeinsame Manifest führt in stabiler Reihenfolge durch neun operative
Bereiche: Übersicht, Nachrichtenvordruck, Führungsstellenbetrieb,
Meldungsübersicht, Vordrucke, Einsatztagebuch, Technisches Betriebsbuch,
Nachweisung und BOS-Info. Administration und Handbuch sind zwei getrennte
Dienste. Das aktuelle, öffentliche und ohne Funktionsanmeldung erreichbare
Web-Handbuch liegt unter `/handbuch/`; das historische PDF von 2011 gehört
nicht zum Laufzeitbestand. Nach der Anmeldung zeigt die Navigation je nach
fester Kontofunktion neun oder zehn Bereichs- und Dienstlinks;
Meldungsübersicht ist ausschließlich S2, Nachweisung ausschließlich LdF und
Fernmelder zugeordnet. Der aktuelle Bereich ist hervorgehoben; alle internen Ziele
ersetzen die aktuelle Ansicht und erzeugen keine zusätzlichen Tabs. Der
Rahmen des Nachrichtenarbeitsbereichs verwendet genau zwei moderne
Anwendungs-`iframe`-Elemente: die vollhohe linke `vorgaben`-Sidebar und den
rechten `mainframe`. Eine vom Benutzer bewusst aufgeklappte PDF-Anlage kann
innerhalb des Inhaltsdokuments zusätzlich in einer isolierten
Vorschau-Einbettung erscheinen. In der Sidebar folgen auf die Statuskarte die Sitzungsidentität
mit Logout, die zur angemeldeten Rolle passenden Textbuttons für Fachaktionen
und danach die für die feste Kontofunktion sichtbaren Bereichs- und Dienstlinks.
Die frühere aufklappbare Auswahl „Bereich wechseln“ und ihre kleine eigene
Scrollfläche entfallen. Bei geringer Höhe scrollt ausschließlich das gesamte
Sidebar-Dokument, sodass Status, Navigation und Aktionen in einer durchgehenden
Reihenfolge erreichbar bleiben.
Der BOS-Bereich verwendet dieselbe sichtbare Navigationslogik in einem
responsiven Zwei-Spalten-Arbeitsbereich; es gibt dort ebenfalls kein
aufklappbares Kleinmenü mit eigener Scrollfläche. Bis einschließlich 672
CSS-Pixel Breite werden Sidebar und Fachinhalt als zwei jeweils viewporthohe
Zeilen angeordnet. Das Ausführen einer rollenabhängigen Fachaktion wechselt
automatisch zur Inhaltszeile und setzt den Tastaturfokus auf den Inhaltsframe.
Der dort sichtbare, mindestens 44 Pixel große Button „Menü“ führt samt Fokus
zurück zur Sidebar.

Beim erstmaligen Öffnen eines Eingangs- oder Beförderungsvermerks ist das
Zeitfeld mit der aktuellen lokalen App-Zeit vorbelegt. Die Anwendung verwendet
dafür `TZ` aus der Containerkonfiguration, standardmäßig `Europe/Berlin`. Das
Feld bleibt editierbar; für eine Korrektur oder Rückdatierung sind `HHMM`,
`DDHHMM` und `DDHHMMmmmYYYY` zulässig. Bereits eingegebene Werte bleiben bei
Validierungs- und Anhangsrückkehr erhalten.

Eigenständige Fach- und Administrationsseiten verwenden dasselbe
Werkzeuggestell mit Seitenkopf, Status-/Hinweisflächen, beschrifteten
Formularfeldern, responsiven Tabellen und eindeutiger Rücknavigation. Das gilt
unter anderem für Meldungsübersicht, Nachweisung, ETB, TBB,
Kategorienverwaltung, Empfängermatrix, Exporte und Systemstatus. Breite
historische Fachformulare liegen in einem sichtbar begrenzten, horizontal
scrollbaren Inhaltsbereich; das Gesamtdokument bleibt auf schmalen Geräten
innerhalb des Viewports. Direkt nicht benötigte Altgeneratoren wie
`4fbak/backup.php` und die Web-Installer sind keine Benutzeroberflächen und
werden durch die Webserver-Regeln mit HTTP 403 abgewiesen.

Vor der Anmeldung zeigt die Leiste „Nicht angemeldet“ und den Anmeldebutton.
Geschützte Bereiche und Karten bleiben zur Orientierung sichtbar, tragen die
Kennzeichnung „Anmeldung erforderlich“ und führen zum Anmeldeeinstieg statt
auf eine 403-Fehlerseite. Nach einer erfolgreichen Anmeldung wird der zuvor
gewählte geschützte Bereich direkt geöffnet. Die Auswahl bleibt pro
Browser-Tab erhalten; parallele Anmeldefenster überschreiben ihr Ziel nicht.
Direkte GET-/HEAD-Aufrufe einer Fachseite, abgelaufene Download-Tabs und
Browserformular-POSTs mit abgelaufener Sitzung antworten mit HTTP 303 und
öffnen denselben Bestandslogin mit einem serverseitig erlaubten
Zielschlüssel. In einem Frame erkennt die Anwendung den Kontext über
`Sec-Fetch-Dest` und öffnet unmittelbar das Content-Login. Anhangs- und
Kategorieansicht tun dies als intrinsische `mainframe`-Controller auch bei
älteren Browsern ohne diesen Header; so erscheint im rechten Inhaltsbereich
kein zweiter, verschachtelter Arbeitsbereich. Ein nicht verarbeitetes Formular
wird dabei nicht erneut gesendet; die Anmeldekarte meldet ausdrücklich „Die
Eingabe wurde nicht gespeichert“ und fordert zur erneuten Erfassung nach dem
Login auf. Abgelaufene operative Filter-/Navigationsparameter des
Nachrichtencontrollers werden ebenso verworfen und führen nur zum erlaubten
Ziel `messages`. GET-Querys mit Zugangsdaten oder Login-Metadaten werden nicht
als Wiederherstellung akzeptiert, sondern weiterhin hart abgewiesen.

Alle Loginformulare bleiben mit `target="_self"` im gerade sichtbaren
Browserkontext. Die Anmeldekarte zeigt das Ziel und hat mit „Anmeldung
abbrechen · Zur Übersicht“ stets einen Top-Level-Ausgang; weder ein
eingebettetes Login noch ein direkt geöffneter Tab erfordern eine manuelle
Änderung der Browseradresse. Nach erfolgreicher Anmeldung wird das vorgemerkte
und für die feste Kontofunktion zulässige Ziel direkt geöffnet; eine
zusätzliche Hutauswahl entfällt.
Rollen-, Objekt-, CSRF- und Subresource-Anfragen werden nicht umgelenkt und
behalten ihre 403-Grenzen. Das Statusfragment liefert bei fehlender oder
abgelaufener Fachsitzung stattdessen HTTP 401, damit die Sidebar den
Top-Level-Login öffnet.
Übersicht und BOS-Info bleiben öffentlich; die Administration ist als
separater technischer Zugang markiert.

Nach erfolgreicher Anmeldung erscheint auf der Übersicht, im
Nachrichtenarbeitsbereich, auf den Administrationsseiten und auf allen
ausgewählten eigenständigen HTML-Modulen die Sitzungsleiste. Sie nennt Name,
Kürzel, Funktion und Rolle, damit vor jeder fachlichen Aktion sichtbar ist, in
welchem Kontext gearbeitet wird. Der Button „Abmelden“ sendet einen
CSRF-geschützten POST, beendet die gesamte eStab-Browsersitzung und führt mit
HTTP 303 zum Anmeldeeinstieg zurück. Ist die lokale Sitzung bereits
abgelaufen, räumt derselbe Button den lokalen Sitzungsrest idempotent auf und
führt zur öffentlichen Übersicht. Mehrere Tabs teilen sich dieselbe
Browsersitzung und sind danach gemeinsam abgemeldet. Direkt geöffnete
Fachseiten zeigen die Leiste selbst; im Nachrichtenarbeitsbereich befindet sich
die einzige sichtbare Identität in der Sidebar. Die früheren eigenständigen
Status-/Zählerhelfer gehören nicht mehr zur Runtime-Oberfläche. Hilfe- und
Problem-Popups zeigen die Leiste im jeweiligen Fenster.

Die Statuskarte am Anfang der Sidebar vereint den rollenabhängigen
Arbeitszähler, Datum und Serverzeit sowie die Aktivitätsübersicht aller
konfigurierten Funktionen. „Aktiv“ bedeutet eine bestätigte echte Interaktion
innerhalb der letzten 15 Minuten; ältere noch gültige Fachsitzungen erscheinen
als „Inaktiv“. Die Anwendung lädt regelmäßig ausschließlich das
authentifizierte Statusfragment
`/4fach/vorgaben.php?fragment=status` nach und ersetzt nur diese Karte. Das
Sidebar-Dokument, die Bereichslinks und die Aktionsbuttons werden dabei nicht
neu geladen; Tastaturfokus und Scrollposition bleiben auch dann erhalten, wenn
der Hinweiston-Schalter fokussiert ist. Dieser GET aktualisiert den
Aktivitätszeitstempel nicht und zählt nicht als Interaktion. Antwortet er nach
dem 12-Stunden-Ablauf mit
HTTP 401, öffnet die Oberfläche den Bestandslogin im Top-Level-Kontext. Ein
Zähler größer null bleibt bis zur
Abarbeitung dauerhaft kontrastreich markiert. Schlägt die Datenbankabfrage
fehl, zeigt der Zähler „–“; Identität, Navigation und Aktionen bleiben
verfügbar, der letzte erfolgreiche `old_que_*`-Basiswert bleibt unverändert und
die Karte meldet „Statusdaten unvollständig“ beziehungsweise „Statusdaten nicht
verfügbar“. Ein HTTP-, Parse- oder Netzwerkfehler kennzeichnet die bestehende
Karte sichtbar als „Status nicht aktuell“ mit Uhrzeit des letzten erfolgreichen
Abrufs. Jeder Abruf wird spätestens nach 4,5 bis 15 Sekunden abgebrochen, damit
ein hängender Request spätere Aktualisierungen nicht dauerhaft blockiert. Der
nächste vollständige Abruf entfernt die Warnung und meldet die Erholung.

Ist `conf_4f["sounds"]` aktiviert, bietet die Statuskarte für Fernmelder, Si
und Stab/FB den Schalter „Hinweistöne aktivieren“ an. Die Zustimmung ist pro
Browser ausdrücklich erforderlich und wird lokal im Browser gespeichert; ein
frischer Browser startet mit ausgeschaltetem Ton. Ausschalten wird sofort mit
anderen offenen Tabs derselben Origin synchronisiert. Nach einem Reload wird
eine gespeicherte Einschaltabsicht so lange als „erneut freigeben“ angezeigt,
bis in diesem Tab wirklich eine Wiedergabe gelungen ist. Die Anwendung
verwendet die mitgelieferten gleich-originigen PCM-WAV-Dateien
`4fach/audio/notify_aw.wav`, `notify_si.wav` und `notify_stab.wav`. Das
langlebige Audioelement liegt außerhalb des regelmäßig ersetzten
Statusfragments und bleibt deshalb über Aktualisierungen hinweg erhalten.
Browserblockaden, ein nicht unterstütztes Format sowie der Ein-/Aus-Zustand
werden direkt unter dem Schalter sichtbar gemeldet. Eine Zunahme lässt die
Statuskarte zusätzlich aufleuchten; solange Meldungen offen sind, bleibt
bereits der Zähler selbst hervorgehoben. Ausschalten oder eine Änderung aus
einem anderen Tab verwirft auch einen noch laufenden Wiedergabeversuch; dessen
spätes Ergebnis kann den Ton nicht unbemerkt wieder aktivieren.

Die Warteschlangenerkennung führt pro Sitzung genau einen Basiswert
`old_que_aw`, `old_que_si` beziehungsweise `old_que_stab`. Die erste
erfolgreiche Messung initialisiert ihn ohne Hinweis; jede weitere erfolgreiche
Messung aktualisiert ihn, aber nur eine Erhöhung fordert genau einmal die
rollenabhängige Wiedergabe an. Ein unveränderter oder kleinerer Wert löst
nichts aus. Ist die Warteschlange vorübergehend nicht messbar, bleibt der
letzte erfolgreiche Basiswert erhalten. Diese Auslöse- und Browsermechanik ist
automatisiert geprüft. Zusätzlich bindet die Quellprüfung jeden Ton per
SHA-256 und parst RIFF/WAVE, PCM-16, Kanäle, Abtastrate, Framezahl und Dauer;
Signalspitze und Mindest-RMS schließen eine stumme Datei aus. Für die
Betriebsabnahme muss der Ton nach ausdrücklicher Aktivierung auf jedem
vorgesehenen Browser und Endgerät trotzdem tatsächlich angehört werden; die
Automation kann physische Hörbarkeit nicht beweisen.

Wurde in einem dafür markierten Formular ein Wert geändert oder eine Datei
ausgewählt, fragt die Oberfläche vor einem globalen Bereichswechsel oder
Logout nach. Bei Nachrichtenformular, Empfängermatrix und Zählerreparatur gilt
das auch für serverseitig wegen eines Fehlers erneut angezeigte, noch
ungespeicherte Eingaben. Die Matrix behält validierte Eingaben selbst dann
sichtbar, wenn die Datenbanktransaktion fehlschlägt. Die von eStab geöffneten
Hilfe- und Problemfenster prüfen die Formulare ihres zugehörigen
Hauptfensters; bestätigte globale Navigation und Logout werden dort
ausgeführt. Lokale Speichern-, Abbrechen- und Fachaktionen lösen diesen
generischen Dialog nicht aus. Davon getrennt besitzen „Standard laden“ und
„Standard ersetzen“ im Matrixeditor wegen des gezielten Verwerfens
beziehungsweise Überschreibens eigene Bestätigungsdialoge. Die Warnungen
verhindern keinen Verlust durch Browserabsturz oder das Schließen eines Tabs;
wichtige Eingaben weiterhin zeitnah speichern.

Diese Schaltfläche beendet ausschließlich die eStab-Funktionssitzung. Die
Administration nutzt HTTP Basic Auth; dessen Zugangsdaten verwaltet der
Browser separat und sie besitzen deshalb keine verlässliche
Anwendungs-Abmeldeschaltfläche. Für einen vollständigen Admin-Benutzerwechsel
ist je nach Browser ein privates Fenster oder das Schließen aller betreffenden
Browserfenster erforderlich. Die Leiste nennt auf Administrationsseiten den
technischen Basic-Auth-Benutzer und unterscheidet ihn ausdrücklich vom
eStab-Funktionskonto. Der eStab-Button „Abmelden“ beendet nur die
Funktionssitzung.

Browserformulare für Bestandsanmeldung und Kontoanlage tragen ein
sitzungsgebundenes CSRF-Token. Direkte historische Clients, die dieses Token
nicht beziehen können, funktionieren nur nach der bewussten Ausnahme
`ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=true`. Diese Ausnahme bleibt für
erkannte Cross-Site-Browserrequests gesperrt, ist aber schwächer als der
normale Tokenfluss und darf nur in einem kontrollierten Netz sowie für die
Dauer der Migration solcher Clients aktiviert werden.

`ESTAB_ALLOW_SELF_REGISTRATION=false` ist der sichere Auslieferungsstandard.
Soll die historische öffentliche Kontoanlage ausnahmsweise vorübergehend
verwendet werden, muss sie ausdrücklich mit `true` aktiviert und nach der
kontrollierten Anlage wieder deaktiviert werden. Die Benutzerverwaltung bleibt
von dieser Option unabhängig erreichbar. Falls die Ausnahme aktiviert ist,
verwendet auch sie ausnahmslos die in der Administration gespeicherte
Kennwortrichtlinie; eine nicht lesbare Richtlinie verhindert die Neuanlage.

Das Admin-Kennwort kann ohne Datenbankänderung rotiert werden:

1. neue starke Zeichenfolge atomar in die konfigurierte
   `admin_password.txt` schreiben,
2. Dateirecht `0600` sicherstellen,
3. `podman compose up -d --force-recreate admin-auth-init app` ausführen und
   prüfen, dass `admin-auth-init` mit Exitcode 0 beendet wurde,
4. den neuen Zugang über TLS prüfen und nach einem vollständig neuen
   Browserkontext bestätigen, dass das alte Kennwort nicht mehr akzeptiert
   wird.

Eine alleinige Neuerstellung von `app` ändert den Hash absichtlich nicht:
Der Webcontainer darf das Klartextsecret nicht lesen. `estab_auth` enthält
nur die jederzeit aus Secret und Benutzername neu ableitbare
Authentisierungsdatei und gehört daher nicht zum fachlichen Vollbackup.

### Empfängermatrix, Zählerreparatur und PDF-Vordruckreset

Die drei aktiven Maßnahmen sind über die Administrationsübersicht erreichbar
und schreiben nur nach POST plus Session-CSRF:

- **Empfängermatrix:** Vorher Datenbanksicherung erstellen und die örtliche
  Rollen-/Rotkopieplanung festhalten. „Nur aktive Matrix speichern“ ersetzt
  genau die 20 Laufzeitpositionen. „Standard laden“ übernimmt die einzige
  gespeicherte Vorlage nur in den Editor; erst ein anschließendes Speichern
  ändert die Laufzeit. „Aktive Matrix speichern und bisherigen Standard
  ersetzen“ schreibt beide Tabellen gemeinsam in einer Transaktion. Laden
  verwirft aktuelle Editorwerte, Ersetzen überschreibt die vorherige Vorlage;
  beide Aktionen verlangen deshalb einen nativen Bestätigungsdialog. Im
  read-only Image wird keine PHP-Konfigurationsdatei geschrieben. S2/Stab ist
  in aktiver und Standardmatrix das feste Rotkopie-/Dokumentationsziel;
  Autosichtung ist deaktiviert und kann im Editor nicht gesetzt werden. Das
  Speichern gleicht bestehende Konten atomar mit der neuen
  Richtlinie ab: Rollenänderungen werden serverseitig übernommen und
  betroffene Sitzungen beendet. Für entfernte Funktionen bleiben Funktion und
  letzte Rolle als „Zuordnung nicht mehr gültig“ erhalten; das Konto wird
  abgemeldet und muss in der Benutzerverwaltung einer gültigen Funktion
  zugewiesen werden. Es findet keine automatische Ersatzzuordnung und keine
  Umbenennung oder Löschung dynamischer Legacy-Tabellen statt. Login,
  Kontoanlage, Neuzuweisung und Matrixspeichern teilen dafür einen globalen
  Lock; ein Konflikt antwortet mit HTTP 409. Nach dem Speichern müssen
  Neuanmeldung, Sichtung und S2-Rotkopie mit den betroffenen Funktionen
  fachlich geprüft werden.
- **Nachrichtenzähler:** Ausschließlich nach dokumentiertem Systemausfall und
  mit dem betroffenen Einsatz als aktivem Einsatz die
  letzte tatsächlich auf Papier verwendete Nummer eintragen. Der Zielwert muss
  strikt größer als der angezeigte Höchstwert sein. Gemeinsame und getrennte
  Nachweisung werden getrennt behandelt; ein Absenken oder Teilupdate ist
  ausgeschlossen. Ein aktiver Einsatz ist Voraussetzung, eine aktive Schicht
  nicht. Die Maßnahme
  erzeugt keine fingierte Fachnachricht und keine erfundenen
  Bearbeitungszeichen des Fernmelders, von LdF oder Si. Stattdessen schreibt sie
  den Zielwert als dediziertes,
  unveränderliches `message_counter_repaired`-Ereignis mit Objekttyp `EINSATZ`
  in die verkettete einsatzbezogene Betriebsspur sowie in `nv_protokoll`.
  Zugangsschichtmutationen verwenden dort `ZUGANGSSCHICHT`. Die nächste echte
  Nachricht erhält die Nummer nach dem größeren Wert aus Fachbestand und
  diesem Wiederanlaufnachweis.
- **PDF-Vordruckreset:** Die GET-Seite zeigt nur die Auswirkung für den
  aktiven Einsatz. Erst die bestätigte POST-Anforderung setzt dessen
  `x04_druck` zurück; historische Einsätze bleiben unverändert. Danach erzeugt
  der Abschlusslauf die einsatzbezogen benannten PDF-Vordrucke erneut;
  Fortschritt wird weiterhin nicht separat angezeigt.

Jede Maßnahme schreibt einen Audit-Eintrag. Datenbank- oder
Validierungsfehler dürfen deshalb nicht durch wiederholtes Browser-Refresh
„korrigiert“ werden; Ursache in App-/DB-Log klären und den aktuellen Zustand
neu laden.

### Kategorienverwaltung

Die Ordnersymbole im Nachrichtenvordruck öffnen den aktiven Endpunkt
`/4fach/katgoedt.php`. Jede Sitzung verwaltet nur ihre aus Funktion und Kürzel
abgeleiteten Funktions-/Benutzertabellen. Globale Kategorien sind der aktuell
in der Empfängermatrix markierten Rotkopie und `Si` vorbehalten. Sämtliche
Änderungen und Nachrichtenzuordnungen erfolgen über POST mit Session-CSRF;
Browser-Refresh wiederholt deshalb keine Änderung. Ein Rotkopiewechsel wirkt
bei der nächsten Anfrage; nach einer Änderung von Funktion oder Rolle müssen
sich die betroffenen Benutzer neu anmelden. Die Kategorienrechte sind danach
im fachlichen Abnahmelauf erneut zu prüfen.

Kategorie und Beschreibung dürfen Quotes, Ampersands und internationalen Text
enthalten und werden als UTF-8 gespeichert. Sie dürfen nicht vorab als
HTML-Entities eingegeben oder importiert werden; die Anwendung escaped erst
bei der Ausgabe. Eine gelöschte Kategorie verliert ihre Zuordnungen atomar.

## Reverse Proxy und TLS

Der Stack stellt selbst kein TLS-Zertifikat bereit. Bei einem Host-Reverse-Proxy
bleibt `ESTAB_HTTP_BIND=127.0.0.1`; nur der Proxy lauscht auf externen
Adressen. Für eine Veröffentlichung unter `https://estab.example.org/` gelten:

```dotenv
ESTAB_HTTP_BIND=127.0.0.1
ESTAB_PUBLIC_URL=https://estab.example.org/
ESTAB_BASE_PATH=
ESTAB_TRUST_PROXY_HEADERS=true
ESTAB_TRUSTED_PROXIES=192.0.2.10/32
```

`192.0.2.10/32` ist hier nur eine Dokumentationsadresse und muss durch die
tatsächliche direkte Peer-Adresse des Reverse Proxys aus Sicht des
App-Containers ersetzt werden. Bei einem stabilen Container-Bridge-Netz kann
statt einer Einzeladresse dessen enges CIDR verwendet werden.

Minimales Nginx-Prinzip:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $remote_addr;
}
```

Zertifikate, sichere TLS-Protokolle, Zugriffsbeschränkung, Rate Limiting und
Logrotation liegen in der Verantwortung des Reverse Proxys. HTTP Basic Auth
darf außerhalb eines isolierten Testhosts niemals ohne TLS verwendet werden.

Für einen Unterpfad, beispielsweise `https://example.org/estab/`, muss der
Proxy `/estab/` vor der Weiterleitung entfernen und die URL so gesetzt werden:

```dotenv
ESTAB_PUBLIC_URL=https://example.org/estab/
ESTAB_BASE_PATH=
```

`ESTAB_BASE_PATH` bleibt beim gelieferten Image leer, weil die Anwendung im
Container direkt unter `/var/www/html` liegt. Die Variable ist nur für ein
bewusst angepasstes Image gedacht, das Code und Daten tatsächlich in einem
Unterverzeichnis des Document Roots installiert.

`ESTAB_TRUST_PROXY_HEADERS=true` ohne nichtleere
`ESTAB_TRUSTED_PROXIES`-Allowlist ist ein fail-closed Konfigurationsfehler.
Akzeptiert werden ausschließlich IP-Literale und präzise IPv4-/IPv6-CIDRs;
Hostnamen, ungültige Präfixe, leere Listenelemente sowie `0.0.0.0/0` und
`::/0` sind verboten. Nur wenn `REMOTE_ADDR` zu einer Regel passt, wertet die
Anwendung die vollständig syntaktisch gültige `X-Forwarded-For`-Kette aus und
verwendet deren erste Adresse für Auditzwecke. Für alle anderen direkten Peers
werden Forwarded-Header ignoriert. `X-Forwarded-Proto: https` aktiviert nur
innerhalb derselben Vertrauensgrenze das `Secure`-Attribut des
Session-Cookies.

Der Proxy muss eingehende Forwarded-Header überschreiben, wie im Beispiel mit
`$remote_addr`, und darf sie nicht ungeprüft um vom Client gelieferte Werte
ergänzen. Die Allowlist ist Defense-in-Depth; der App-Port bleibt zusätzlich
per Bind-Adresse und Firewall ausschließlich für den kontrollierten Proxy
erreichbar.

## Speicher und Kapazität

```console
podman compose exec -T app \
  du -sh /var/www/html/4fdata /var/lib/estab/export
podman compose exec -T db du -sh /var/lib/mysql
```

Anhänge und Vordrucke liegen pro Datenbank unter
`/var/www/html/4fdata/$ESTAB_DB_NAME/`. Exporte wachsen unabhängig davon im
Export-Volume und werden nicht automatisch gelöscht. Vollständige einzelne
Läufe können unter `/4fadm/export.php` nach einer ausdrücklichen zweiten
Bestätigung gelöscht werden. Das ersetzt keine Aufbewahrungsregel: Für beide
Bereiche sind weiterhin Frist, Verantwortlichkeit und Kapazitätsalarm
festzulegen. Unabhängig von der Exportdatei sperrt die Einsatzdomäne ETB, TBB
und übrige Fachdaten eines formal geschlossenen Einsatzes mindestens zehn
Jahre ab Abschluss; ein Legal Hold kann die Frist verlängern.

Eine Sicherung und ein regelmäßig geprobter Restore sind in
[Backup und Wiederherstellung](BACKUP-UND-WIEDERHERSTELLUNG.md) beschrieben.
