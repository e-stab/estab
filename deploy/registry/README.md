# Pull-only-Deployment

Dieses Paket startet eStab aus bereits gebauten OCI-Images. Auf dem Zielgerät
werden weder PHP-Quellen noch Datenbankskripte gebaut. Der mitgelieferte
One-shot-Migrator enthält das Basisschema, initialisiert ausschließlich eine
leere eStab-Datenbank, führt danach die versionierten Migrationen aus und
blockiert den App-Start bei einer negativen Schema-Prüfung.

## Wichtiger Veröffentlichungsstatus

Die technischen Publish- und Multi-Arch-Dateien sind vorbereitet. Dieses
Repository dokumentiert aber noch keinen freigegebenen Image-Stand mit beiden
Manifest-Digests. Vor einer Installation muss deshalb anhand der
GitHub-Releaseinformationen und GHCR-Pakete geprüft werden, ob ein vollständiges
Imagepaar tatsächlich veröffentlicht und freigegeben wurde. GHCR legt erstmals
veröffentlichte Pakete standardmäßig privat an. Eine öffentliche Sichtbarkeit
darf erst nach der im Root-README beschriebenen Rechte- und Lizenzklärung für
den historischen Quell-, Schrift-, Grafik- und Dokumentationsbestand gesetzt
werden. Bis dahin benötigt jedes Zielgerät eine eigene GHCR-Anmeldung mit
minimalem Leserecht.

Der manuelle Workflow bleibt zusätzlich gesperrt, bis

- `ESTAB_CONTAINER_PUBLISH_ENABLED=true` und
  `ESTAB_CONTAINER_PUBLISH_REVIEWER_CONFIGURED=true` als
  Repositoryvariablen gesetzt sind,
- die Rechteprüfung im Workflowformular bestätigt ist,
- der Workflow ausdrücklich vom bereits vorhandenen und durch ein aktives
  Tag-Ruleset geschützten Git-Tag gestartet wird, und
- das GitHub-Environment `container-publish` tatsächlich einen
  `Required Reviewer` besitzt.

Diese Schalter ersetzen keine tatsächliche Rechteklärung. Der Workflow ist
global serialisiert, veröffentlicht die OCI-Indizes ausschließlich unter
ihren `@sha256:`-Digests und unterstützt bewusst kein `latest`. GitHub
Actions, das privilegierte binfmt-Hilfsimage,
BuildKit sowie PHP-/MariaDB-Basen sind auf Commit- beziehungsweise
Multi-Arch-Digests festgelegt; ihre Aktualisierung ist ein eigener geprüfter
Änderungsschritt.

Der Publish-Pfad trennt Build und Freigabe bewusst. App und Migrator werden
jeweils genau einmal als Multi-Arch-Index digest-only gebaut und mit BuildKits
`push-by-digest=true` ohne OCI-Tag gepusht. Native Runner für `linux/amd64`
und `linux/arm64` ziehen anschließend genau diese Index-Digests, binden sie
kryptographisch an Plattformmanifest, Config und lokale Runtime-Image-ID und
führen das vollständige Fresh-/Restore-Gate aus.
SPDX-SBOM, SLSA-Provenance, die in GHCR gespeicherte Attestation und Trivy
werden ebenfalls gegen diese Digests geprüft; Chrome ist auf `amd64`
verpflichtend. Jeder grüne Architekturlauf hinterlegt zunächst ein
prüfsummengebundenes Actions-Artefakt, Fehlerläufe behalten ihre separate
Diagnostik.

Erst nach beiden grünen Architekturen erstellt ein nachgelagerter Job ein
verstecktes Draft-Release. Er lädt das Installationsarchiv und ein separates
Evidence-Archiv jeweils mit äußerer SHA-256-Datei hoch und lädt alle vier
Assets zur erneuten Prüfung wieder herunter. Das dauerhafte Evidence-Asset
enthält beide nativen Testergebnisse, SBOM, Provenance, Scanprotokolle,
Attestationsbundles, Online- und Offline-Prüfergebnisse sowie den beim Release
bezogenen Trusted-Root; das 90-Tage-Actions-Artefakt ist nur eine zusätzliche
Kopie. Das Draft-Release wird erst nach einem letzten Asset-, Digest-,
Tag-Ruleset- und Attestationscheck sichtbar. Es benennt das getestete
Digestpaar; es entstehen weder Candidate- noch Final-OCI-Tags.

Vor dem ersten Publish müssen **Immutable releases** sowie ein Repository-
Ruleset für `target=tag` aktiv sein. Das Ruleset umfasst exakt `~ALL`, hat
keine Ausnahmen oder Bypass-Akteure, verbietet Update und Löschen, aber nicht
das Erzeugen neuer Tags. Seine positive ID steht in
`ESTAB_RELEASE_TAG_RULESET_ID`. Das environmentgeschützte Secret
`ESTAB_RELEASE_POLICY_TOKEN` benötigt für genau dieses Repository
**Administration: write**, weil GitHub die entscheidende vollständige
`bypass_actors`-Liste beim Ruleset-GET nur einem Aufrufer mit Ruleset-
Schreibzugriff liefert. Das hochprivilegierte Token wird ausschließlich an
die drei Policy-Prüfschritte gereicht, nie jobweit exportiert, und das
Environment verlangt einen Required Reviewer. Der Prüfer bindet zusätzlich
den Remote-Git-Tag immer wieder an `GITHUB_SHA`. Fehlendes Secret, fehlende
Berechtigung, API-Fehler, ein beweglicher Tag oder deaktivierte Immutability
schließen die Freigabe. Nach Veröffentlichung verlangt der Workflow
`isImmutable=true`, exakt die vier erwarteten Assets, eine gültige
`gh release verify`-Attestation, für alle vier lokalen Originaldateien eine
erfolgreiche `gh release verify-asset`-Prüfung und erneut dasselbe
Tag-/Ruleset-Ergebnis.

Die verpflichtende aktuelle Attestationsprüfung auf einer
Admin-Workstation sowie der ausführbare Export in Multi-Arch-OCI-Archive und
die Wiederherstellung in eine Registry sind in
[`RELEASE-EVIDENCE.md`](RELEASE-EVIDENCE.md) dokumentiert. Das mitgelieferte
`offline-images.sh` verwendet dafür `skopeo copy --all --preserve-digests`,
prüft die Indexdigests von App, Migrator und der in `compose.yaml`
digestgebundenen MariaDB-Basis und verlangt je ein `linux/amd64`- und
`linux/arm64`-Manifest.

## Installation mit Docker oder Podman

Das vom Workflow veröffentlichte Archiv wird vor dem Entpacken und danach
nochmals dateiweise geprüft. `RELEASE` zeigt anschließend den exakt gebundenen
Tag, Commit und beide Image-Digests:

```console
bundle=estab-RELEASE
sha256sum --check "$bundle.tar.gz.sha256"
tar -xzf "$bundle.tar.gz"
cd "$bundle"
sha256sum --check SHA256SUMS
```

`RELEASE` wird dabei durch den veröffentlichten Releasenamen ersetzt. Auf
macOS wird jeweils `shasum -a 256 --check` statt `sha256sum --check`
verwendet. Bei einer abweichenden Prüfsumme darf das Paket nicht gestartet
werden.

Anschließend im geprüften Paketverzeichnis:

```console
cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

`deploy.sh` akzeptiert diese drei lokalen Quelldateien nur als lesbare,
nicht ausführbare reguläre Dateien ohne Symlink. Eigentümer muss `root`, der
ausführende Benutzer oder bei einem echten `sudo`-Aufruf dessen ursprünglicher
Benutzer sein; ausschließlich der Eigentümer darf Rechte besitzen und er muss
das Leserecht haben. Erweiterte POSIX-, macOS- oder DSM-ACLs sind nicht
zulässig, weil ein numerischer Modus wie `0600` zusätzliche Freigaben nicht
abbildet. Auf Synology bedeutet die eindeutige Ausgabe `It's Linux mode` von
`synoacltool -get`, dass keine DSM-ACL aktiv ist; gemeldete DSM-ACL-Einträge
werden abgewiesen. Fehlt auf einem System jede verlässliche ACL-Abfrage, bricht
der Helfer geschlossen ab. Diese Hostregel gilt bewusst nicht für die vom
Container-Provider erzeugten Dateien unter `/run/secrets`: Dort ist ein
schreibgeschützter Modus wie `0444` zulässig, weil die Engine den Mount
bereitstellt und die Container ihn nur lesen.

Nur die Quellvorlage dieses Repositorys enthält leere Image-Werte, damit ein
ungebundener Checkout geschlossen fehlschlägt. Das veröffentlichte
Releasepaket bindet `.env.example` und `RELEASE` immer an genau dieses
kanonische Paar:

- `ghcr.io/e-stab/estab@sha256:<64 Kleinhexzeichen>`
- `ghcr.io/e-stab/estab-migrate@sha256:<64 Kleinhexzeichen>`

Tags, `latest`, andere Registries und manuell übertragene Digests sind im
Releasepaket unzulässig. Nach `cp .env.example .env` dürfen ausschließlich die
Nicht-Image-Einstellungen wie Port, Speicherquellen oder Proxykonfiguration
angepasst werden. Die Zeilen `ESTAB_APP_IMAGE` und `ESTAB_MIGRATE_IMAGE`
bleiben bytegenau unverändert; `verify-release.sh` vergleicht sie mit der
prüfsummengebundenen Vorlage und `RELEASE`. `deploy.sh` weist
Prozessvariablen für sämtliche von Compose verwendeten Laufzeiteinstellungen
sowie sämtliche geerbten `COMPOSE_*`-Steuervariablen zurück. Dazu zählt
insbesondere das destruktive `COMPOSE_REMOVE_ORPHANS`; die geprüfte `.env`
ist zusammen mit den expliziten Compose-Argumenten die einzige
Konfigurationsquelle für Image-, Projekt-, Speicher- und Laufzeitidentität. Sie
muss genau die 24 von `.env.example` vorgegebenen Zuweisungen enthalten.
Unbekannte oder doppelte Namen sowie Leerraum, `$`, `#`, Anführungszeichen oder
Backslashes in Werten sind unzulässig; damit kann die von Compose effektiv
verwendete Konfiguration nicht durch Interpolation von der zuvor geprüften
Zeichenfolge abweichen. Nur `ESTAB_BASE_PATH` und
`ESTAB_TRUSTED_PROXIES` dürfen leer sein.
Automatisch geladene
Dateien wie `compose.override.yaml` oder `docker-compose.yml` sind im
Releaseordner ebenfalls verboten. Nach der Prüfung erstellt der Helfer
private, checksum- und inhaltsgebundene Snapshot-Kopien von `.env`,
`compose.yaml` und den Secrets und übergibt ausschließlich diese Kopien mit
explizitem `--env-file` und `--file` an Compose.

Die Secret-Dateien sind absichtlich als kurze, schreibgeschützte Bind-Mounts
mit `ro,z` beziehungsweise beim ausschließlich vom Initialisierer gelesenen
Admin-Secret mit `ro,Z` formuliert. Nur diese Compose-Form wird von den
unterstützten Docker- und Podman-Providern zuverlässig bis zur
SELinux-Relabel-Option der Engine übertragen. Gemeinsam verwendete Daten- und
Authentifizierungsvolumes tragen ebenfalls `z`, das nur vom Datenbankdienst
verwendete Datenvolume `Z`. Backup und Restore übernehmen App-Mounts mit
`--volumes-from <exakte-ID>:z`. Es werden weder `privileged` noch
`label=disable` eingesetzt. Auf Systemen ohne SELinux sind diese Optionen
wirkungslos. Auf Fedora/RHEL mit `Enforcing` kann das erstmalige Relabeln
größerer Datenverzeichnisse dauern; deshalb dürfen auch dort nur die von
`deploy.sh` zugelassenen dedizierten Speicher- und Secretpfade verwendet
werden. Ein direkter Compose-Start umgeht diese Hostpfadprüfung und gehört
nicht zum produktiven Releaseweg.

Einzelne Compose-Frontends melden bei den standardmäßig verwendeten benannten
Volumes sinngemäß `mount of type volume should not define bind option`. Diese
Warnung ist für den Named-Volume-Fall nicht fatal: Die Engine verwaltet dessen
Kennzeichnung selbst. `z`/`Z` bleiben an den drei konfigurierbaren
Daten-Mappings dennoch absichtlich erhalten, weil dieselben Zeilen durch
`ESTAB_DB_DATA_SOURCE`, `ESTAB_APP_DATA_SOURCE` und
`ESTAB_EXPORT_DATA_SOURCE` zu echten Host-Bind-Mounts werden können. Dort
würde ein Entfernen der Optionen auf SELinux-Enforcing-Systemen das sichere
automatische Relabeln verlieren.

`LICENSE` und `THIRD_PARTY_NOTICES.md` gehören ebenso zum gebundenen Paket.
Fehlt eine dieser Dateien oder eine andere in `SHA256SUMS` genannte Datei,
darf das Release nicht installiert werden.

### Nur bei privaten GHCR-Images: Anmeldung

Dieser Abschnitt ist ausschließlich nötig, solange die beiden GHCR-Images
privat sind. Öffentliche Images können ohne Registry-Anmeldung gezogen werden.
Für private Images braucht das verwendete GitHub-Konto Leserechte auf beide
Pakete und einen [Personal Access Token (classic) mit ausschließlich
`read:packages`](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry#authenticating-with-a-personal-access-token-classic).
`repo`, `write:packages` und `delete:packages` sind für ein Zielgerät nicht
erforderlich. Eine Organisation mit SAML-SSO muss den Token zusätzlich für
diese Organisation autorisieren. Ablaufdatum und Kontozugriff so eng wie
betrieblich möglich wählen.

Den Token in einer interaktiven Shell aus einem Passwortmanager in eine nicht
exportierte Shellvariable einlesen und per Standard-Eingabe übergeben; den
Tokenwert nicht als Kommandoargument eingeben:

```console
GHCR_USER='github-benutzername'
printf 'GHCR-Token: ' >&2
stty -echo
trap 'stty echo' 0 1 2 15
IFS= read -r GHCR_TOKEN
stty echo
trap - 0 1 2 15
printf '\n' >&2
printf '%s' "$GHCR_TOKEN" |
    docker login ghcr.io --username "$GHCR_USER" --password-stdin
unset GHCR_TOKEN
```

Mit Podman lautet der letzte Befehl entsprechend `podman login`. Der Token
gehört weder in `.env`, `compose.yaml`, Projektdateien, Logs noch
Shell-History. `docker login` beziehungsweise `podman login` speichert
Anmeldedaten im Credential Store oder in der Konfiguration des ausführenden
Betriebssystemkontos; auch diese Datei ist wie ein Secret zu schützen. Nach
einem erfolgreichen `deploy.sh pull` kann die gespeicherte Anmeldung mit
`docker logout ghcr.io` entfernt werden. Token bei Verlust sofort widerrufen
und ansonsten regelmäßig rotieren.

Danach:

```console
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
```

Mit Podman wird in allen drei Zeilen ausschließlich `docker` durch `podman`
ersetzt. `check` prüft `SHA256SUMS`, die vier Identitätszeilen in `RELEASE`,
das kanonische Digestpaar, die unveränderten Imagewerte in `.env.example` und
`.env`, die Metadaten der drei lokalen Secret-Quelldateien, die sichere
Trennung der drei produktiven Speicherquellen sowie die effektive
Compose-Konfiguration. Zusätzlich führt es die nicht mutierenden Befehle
`compose config` und `compose ps --all -q` aus. Damit werden die tatsächlich
benötigten Provider-Fähigkeiten geprüft; eine bloße, möglicherweise
irreführende Mindestversionsangabe ist kein Ersatz. `pull` wiederholt dieses
Gate, zieht die beiden gebundenen Anwendungsimages und die digestgebundene
MariaDB-Basis und verlangt danach für App und Migrator passende OCI-Labels für
Git-Tag und Git-Commit. `up` führt dieselben Prüfungen aus, schützt bestehende
Produktivmounts unter der Wartungssperre und erstellt danach alle Dienste
explizit neu. Das ist nötig, weil Compose Änderungen an den privaten
Secret-Dateipfaden nicht für jeden Dienst als Konfigurationsänderung erkennt;
persistente Volumes bleiben dabei erhalten. Anschließend prüft der Helfer die
Produktivmounts ein zweites Mal und wartet höchstens 300 Sekunden auf `healthy`
für `db` und `app` sowie Exitcode
0 von `migrate` und `admin-auth-init`. Vor der Bereitschaftsmeldung muss
Container-Inspect außerdem beweisen, dass jeder der fünf Secret-Mounts der vier
Dienste schreibgeschützt auf genau den aktuellen privaten Konfigurations-
Snapshot zeigt.
`ESTAB_DEPLOY_HEALTH_TIMEOUT_SECONDS` kann diese Grenze auf 1 bis 3600
Sekunden setzen.
Erst die Ausgabe `eStab deployment: ready` bestätigt, dass beide One-shots
Exitcode 0 und beide langlebigen Dienste den Status `healthy` erreicht haben.

Um eine Änderung der Release-Dateien zwischen Prüfung und Providerzugriff
auszuschließen, verwendet Compose nicht direkt die veränderliche `.env`,
`compose.yaml` oder die drei Secret-Quelldateien. `deploy.sh` bildet aus
Compose-Prüfsumme, kanonischer Konfiguration, kanonischen Speicherquellen und
Secret-Inhaltsprüfsummen eine deterministische private Snapshot-ID. Als `root`
liegt sie unter
`/var/lib/estab-deploy/<Projekt>/snapshots/snapshot-<SHA-256>`, sonst unter
`${XDG_STATE_HOME:-$HOME/.local/state}/estab-deploy/<Projekt>/snapshots/`.
Verzeichnisse haben Modus `0700`, Dateien einschließlich der drei
Klartext-Secretkopien `0400`; auch dort sind erweiterte ACLs unzulässig.

Identische Aufrufe verwenden denselben Snapshot und erzeugen keine weiteren
Klartextkopien. Ein nur für `check` oder `pull` neu erzeugter, noch von keinem
Container verwendeter Snapshot wird beim Beenden wieder entfernt. Bei
Secret-Inhaltsänderung entsteht eine neue ID. Das ist noch keine automatische
Datenbankkennwort-Rotation: Bei einer bestehenden MariaDB muss insbesondere
das Anwendungskennwort koordiniert per `ALTER USER` geändert werden, bevor App
und Secret gemeinsam umgestellt werden; eine bloße Dateiänderung schlägt
korrekt am Health-Gate fehl. Nach erfolgreichem `up` werden zuerst die neuen
Live-Mounts nachgewiesen und danach ausschließlich alte Snapshots gelöscht,
die von keinem zum Compose-Projekt gehörenden Container mehr referenziert
werden. Ein gestoppter oder verwaister Container kann noch von einem alten
Snapshot abhängen; dieser bleibt deshalb mit einer
`retaining referenced private snapshot`-Meldung erhalten und wird bei einem
späteren erfolgreichen `up` entfernt, sobald der Container nicht mehr
existiert. Den privaten Zustandsordner niemals manuell löschen, solange
Projektcontainer bestehen.

Nur zur Diagnose nach einem fehlgeschlagenen `deploy.sh up`, nicht als
alternativer Startweg:

```console
container_cli=docker
"$container_cli" compose ps --all
"$container_cli" compose logs --tail 200 db migrate admin-auth-init app
```

Standardmäßig sind `estab_db`, `estab_data`, `estab_export` und das nur für
den abgeleiteten Admin-Hash verwendete `estab_auth` benannte Volumes.
`COMPOSE_PROJECT_NAME=estab` und der gleichlautende Compose-Standard sind
absichtlich versionsunabhängig: Auch wenn ein neues Releasepaket in ein
anderes Verzeichnis entpackt wird, bleiben dadurch die Volume-Namen
`estab_estab_db`, `estab_estab_data`, `estab_estab_export` und
`estab_estab_auth` stabil. Diesen Wert nach der Erstinstallation nicht ändern.
`deploy.sh check` prüft die konfigurierte Projekt- und Speicheridentität und
die erforderliche read-only-Compose-Abfrage, setzt aber keinen vorhandenen
Stack voraus. `deploy.sh up` liest die `.env` nach Erwerb der globalen
Wartungssperre erneut. Gibt es noch kein `db`-/`app`-Containerpaar, ist die
Neuinstallation erlaubt. Andernfalls müssen genau ein Datenbank- und genau ein
App-Container vorhanden sein. Ihre produktiven Mounts müssen in Typ,
kanonischer Bind-Quelle beziehungsweise logischem
`<COMPOSE_PROJECT_NAME>_<Volume>`-Namen, Ziel und Read/Write-Modus exakt den
gesperrt gelesenen Werten entsprechen. Der providerabhängige interne
Engine-Pfad eines benannten Volumes wird nicht mit einem bestimmten
Docker-/Podman-Speicherlayout gleichgesetzt, muss aber eine sichere absolute
Quelle unterhalb der Dateisystemwurzel sein; zusätzliche überlappende
Mountgrenzen werden abgewiesen. Ein partieller, mehrdeutiger oder abweichender
Bestand bricht vor `compose up` geschlossen ab.

Alternativ akzeptieren `ESTAB_DB_DATA_SOURCE`,
`ESTAB_APP_DATA_SOURCE` und `ESTAB_EXPORT_DATA_SOURCE` absolute oder relative
Hostverzeichnisse. Sie müssen vor `deploy.sh check` als echte Verzeichnisse
angelegt sein, dürfen keine symbolischen Links sein und müssen getrennt
voneinander liegen. Der Helfer verweigert `/`, breite Verzeichnisse direkt
unterhalb der Dateisystemwurzel, den Releaseordner und dessen Vorfahren sowie
gleiche oder ineinander verschachtelte Quellen. Für benannte Volumes ist je
Rolle ausschließlich `estab_db`, `estab_data` beziehungsweise `estab_export`
zulässig; dadurch kann insbesondere das nur für den bcrypt-Hash bestimmte
`estab_auth` nicht als beschreibbarer Datenspeicher wiederverwendet werden.
Die Prüfung erfolgt vor `pull` und unter der engine-weiten Wartungssperre
erneut vor `up`.

Für das dokumentierte Bind-Mount-Layout:

```console
mkdir -p data data/db data/4fdata data/export
chmod 0700 data data/db data/4fdata data/export
```

`0700` ist der sichere Ausgangszustand vor dem ersten Containerstart. Beim
Start übernimmt der als `root` initialisierte App-EntryPoint ausschließlich
die beiden App-Mountwurzeln `data/4fdata` und `data/export`: Er fordert
`www-data:www-data` an, setzt Modus `0770` und erzeugt die benötigten
Anwendungsunterverzeichnisse ebenso. Vor der Freigabe entfernt er auf jedem
dieser Verzeichnisse erweiterte und Default-POSIX-ACLs und beweist mit
`getfacl`, dass ausschließlich Eigentümer, Gruppe und `other::---` verbleiben.
Kann das Dateisystem diese Normalisierung nicht nachweisbar ausführen, bleibt
der Container geschlossen. Der übergeordnete Ordner `data` bleibt
`0700`; `data/db` wird ausdrücklich nicht durch die App verändert, sondern
vom offiziellen MariaDB-EntryPoint verwaltet. Es erfolgt kein rekursives
Umschreiben beliebiger Bestandsdaten. Vor und nach jeder Anlage muss jeder
einzelne benötigte Pfad ein echtes Verzeichnis sein; ein Symlink an einer
Mountwurzel oder an `4fdata/<Datenbank>`, `anhang`, `vordruck` beziehungsweise
dem PHP-Sitzungsverzeichnis beendet den Start. Erst anschließend beweist eine
unter UID/GID von `www-data` ausgeführte Anlegen-/Schreiben-/Lesen-/Löschen-
Probe die tatsächliche Nutzbarkeit. Rootful Docker auf Synology bildet den
Containerbenutzer üblicherweise als UID/GID 33 auf dem Host ab.
Rootless-Podman- und Desktop-Dateifreigaben können dieselbe wirksame
Berechtigung dagegen als gemappte oder virtualisierte Host-ID darstellen.
Der EntryPoint prüft deshalb zuerst die native Darstellung als
`www-data:www-data`; falls der Provider eine andere gemappte Eigentümer-ID
zeigt, akzeptiert er sie nicht ungeprüft. Modus `0770` bleibt zwingend und ein
auf UID/GID von `www-data` heruntergestufter Prozess muss in jeder Wurzel eine
private Datei anlegen, schreiben, lesen und wieder entfernen können. Dieselbe
echte Schreibprobe wird im Integrationstest wiederholt. Die beiden
App-Wurzeln während des Betriebs nicht wieder auf `0700` zurücksetzen, weil
Apache sonst weder Health-Schreibprobe noch Anhänge und Exporte schreiben
kann.

Docker Desktop auf macOS meldet Bind-Quellen je nach Backend mit dem
Enginepräfix `/host_mnt` oder `/run/desktop/mnt/host`. `deploy.sh` akzeptiert
diese beiden dokumentierten Aliasdarstellungen ausschließlich bei
`ESTAB_CONTAINER_CLI=docker` auf einem Darwin-Host und vergleicht den
restlichen Pfad weiterhin bytegenau. Auf Linux, Podman und für alle anderen
Abweichungen bleibt die Mountprüfung strikt.

Diese Daten sind produktiv. `compose down --volumes`, „Clean“ beziehungsweise
das Löschen eines Container-Manager-Projekts darf nur nach einem geprüften
Vollbackup erfolgen.

Die formale Ausgangssichtung ist auch im Pull-only-/Synology-Paket
verbindlich: Verfasser → Si → LdF → Fernmelder. Sie ist nicht konfigurierbar.
Fehlt ein zugelassenes, angemeldetes Konto mit der festen Funktion Si, bleibt
der Ausgang in der Warteschlange und wird nicht automatisch oder durch den
Fernmelder freigegeben.

`ESTAB_PDF_ATTACHMENT_MAX_BYTES` begrenzt die Gesamtsumme der unverändert in
ein PDF-Einsatzdossier eingebetteten Originalanhänge. Der Standard sind
`52428800` Byte (50 MiB), erlaubt sind `0` bis `52428800` Byte; 50 MiB sind
zugleich die harte, auch auf größeren NAS-Systemen nicht aufweitbare
Obergrenze. Bei Überschreitung bricht der Export sichtbar ab und lässt keine
Datei stillschweigend weg. Nach einer Änderung ist der App-Container neu zu
erzeugen. Einzelne Uploads bleiben zusätzlich durch
`ESTAB_UPLOAD_MAX_BYTES`, PHP `upload_max_filesize` und `post_max_size`
begrenzt. Der Auslieferungsstandard für `ESTAB_UPLOAD_MAX_BYTES` beträgt
`20971520` Byte (20 MiB). Der Anhangdialog unterstützt sowohl `.jpg` als auch
`.jpeg` und zeigt das wirksame Limit an; nur Endung und serverseitig erkannter
MIME-Typ gemeinsam autorisieren die Datei.

Im Dossier erscheinen JPEG, PNG, GIF und BMP sichtbar; PDF-Anlagen werden
einschließlich ihrer Anmerkungen seitenweise gerastert. Verlustfrei
Windows-1252-darstellbarer Text wird durchsuchbar ausgegeben. TIFF, Archive,
Office, Video und nicht verlustfrei darstellbarer Text erhalten eine ehrliche
Hinweisseite. Das bytegleiche Original bleibt in jedem Fall eingebettet.
Fileinfo verifiziert den MIME-Typ atomar aus genau diesem eingebetteten
Byte-Snapshot; eine Abweichung beendet den Export fail-closed.
Die sichtbare Wiedergabe ist fest auf 24 MiB Rasterdaten insgesamt, 8 MiB je
isoliertem PDF-Seitenprozess beziehungsweise Rasterseite, 12 Megapixel je Bild
und 8.000 Pixel je Achse begrenzt. Für alle Anlagen zusammen gelten 60
Sekunden; `pdfinfo` und jeder einzelne PDF-Seitenprozess erhalten davon
höchstens 15 Sekunden.
Das ausgelieferte App-Image enthält dafür GD, Poppler und `prlimit`. Beim Start
entfernt ein fail-closed Janitor ausschließlich streng validierte, mehr als 24
Stunden alte Render-Arbeitsverzeichnisse aus `/tmp`; fremde oder unerwartete
Einträge bleiben unangetastet.

Eine frische Datenbank besitzt absichtlich keinen aktiven Einsatz. Nach dem
ersten gesunden Start muss der technische Administrator unter
`/4fadm/incidents.php` einen Einsatz anlegen und aktivieren; bis dahin bleiben
alle operativen Eingaben gesperrt. Unter `/4fadm/users.php` legt er
Funktionskonten mit fester Funktion an, weist Funktionen neu zu, sperrt oder
entsperrt Konten und setzt deren Kennwort zurück. Diese Konten sind unabhängig
vom Basic-Auth-Konto aus `ESTAB_ADMIN_USER` und `admin_password.txt`.
Selbstregistrierung ist standardmäßig deaktiviert. Der technische
Administrator kann sie unter `/4fadm/self_registration.php` dauerhaft oder
für 15 Minuten bis 24 Stunden freigeben und jederzeit vorzeitig beenden. Der
ENV-Wert ist nach Migration 114 nur der Upgrade-Startwert, bis dort erstmals
eine administrative Auswahl gespeichert wurde.

Der Hostverzeichnis-Pfad ist Bestandteil des automatisierten Release-Gates:
Ein zusätzliches Pull-only-Projekt startet mit drei echten temporären
Bind-Mounts. Der Test prüft die effektiven Mount-Typen und -Quellen, erzeugt
einen Datenbank- und zwei Dateimarker, erstellt ein prüfsummengebundenes
Vollbackup, verfälscht den Datenbankmarker logisch und ergänzt kontrollierte
veraltete Testdaten in den beiden Dateibereichen. Der Restore stellt beides
wieder her;
der rohe MariaDB-Bind-Mount wird in diesem Test nicht geleert. Migrator,
`/health.php`, Markerinhalt und Marker-SHA-256 müssen danach unverändert sein.
Erst nach vollständiger Entfernung von Containern, Volumes, Netzwerken und
temporärem Hostpfad wird der Lauf grün. Das belegt die technische
Backup-/Restore-Mechanik des NAS-Layouts; die regelmäßige Restore-Probe des
echten Betriebsbestands bleibt dennoch Pflicht.

## Synology Container Manager

Vorausgesetzt werden ein Synology-Modell mit Container Manager und eine
CPU-Architektur, für die das Release-Manifest ein Image enthält. Die
Publish-Pipeline baut `linux/amd64` und `linux/arm64`; ältere 32-Bit-NAS sind
nicht abgedeckt. Das vollständige Container-/Migrations-/Restore-Gate läuft im
normalen CI- und im Publish-Workflow nativ auf beiden Zielarchitekturen. Der
echte Browserlauf ist in GitHub Actions auf `amd64` verpflichtend; die
Bedienabnahme auf dem tatsächlichen NAS-Endgerät bleibt zusätzlich nötig.

1. Das äußerlich geprüfte Releasearchiv vollständig in einen geschützten
   Projektordner wie `/volume1/docker/estab` entpacken. Keine Einzelauswahl
   aus `compose.yaml` und Skripten treffen: `RELEASE`, `SHA256SUMS`,
   `.env.example`, `deploy.sh`, `verify-release.sh`, `offline-images.sh`,
   `RELEASE-EVIDENCE.md`, die Backup-/Restore-Helfer sowie Lizenz- und
   Hinweisdateien bilden gemeinsam die Vertrauensgrenze. `.env.example` nach
   `.env` kopieren; die beiden
   Imagezeilen nicht verändern. Zusätzlich `data/db`, `data/4fdata`,
   `data/export`, `backups` und `secrets` anlegen. In `.env` nur die drei
   `ESTAB_*_DATA_SOURCE`-Werte auf diese relativen `./data/...`-Pfade setzen.
   `data`, `data/db`, `data/4fdata` und `data/export` zunächst auf `0700`
   setzen. Secret-Dateien als echte, nicht ausführbare Dateien mit `0600`
   anlegen und dem administrativen NAS-Benutzer beziehungsweise `root`
   zuordnen. Beim ersten App-Start werden nur `data/4fdata` und `data/export`
   wie oben beschrieben kontrolliert für `www-data` mit `0770` vorbereitet.
2. Für LAN-Zugriff `ESTAB_HTTP_BIND=0.0.0.0` setzen und den gewählten
   `ESTAB_HTTP_PORT` in DSM-Firewall und Reverse Proxy ausschließlich für die
   vorgesehenen Netze freigeben. eStab nicht ungeprüft direkt aus dem Internet
   erreichbar machen. Falls Forwarded-Header ausgewertet werden sollen,
   `ESTAB_TRUST_PROXY_HEADERS=true` und
   `ESTAB_TRUSTED_PROXIES` auf die direkte Proxy-IP beziehungsweise ein enges
   Proxy-CIDR setzen; eine fehlende Allowlist sperrt diese Konfiguration.
3. **Nur für private GHCR-Images:** Da Synologys
   [offizielle Registry-Hilfe](https://kb.synology.com/de-de/DSM/help/ContainerManager/docker_registry?version=7)
   das Hinzufügen einer Registry-URL, aber keinen GHCR-PAT-Ablauf dokumentiert,
   wird hier kein nicht belegtes GUI-Credential-Feld vorausgesetzt. Stattdessen
   einmal per administrativer SSH-Sitzung am NAS anmelden und die oben
   beschriebene verdeckte Token-Eingabe verwenden:

   ```console
   GHCR_USER='github-benutzername'
   printf 'GHCR-Token: ' >&2
   stty -echo
   trap 'stty echo' 0 1 2 15
   IFS= read -r GHCR_TOKEN
   stty echo
   trap - 0 1 2 15
   printf '\n' >&2
   sudo -v
   printf '%s' "$GHCR_TOKEN" |
       sudo docker login ghcr.io --username "$GHCR_USER" --password-stdin
   unset GHCR_TOKEN
   ```

   Den PAT niemals in den Projekteditor oder die `.env` kopieren.
4. Im vollständig entpackten Projektordner ausschließlich den gebundenen
   Deployment-Helfer mit Docker ausführen:

   ```console
   sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
   sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
   sudo env ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
   ```

   Dadurch werden vor dem Pull und Start Paket, Digestpaar,
   Secret-Quelldateien, Speichertrennung und die benötigten read-only
   Compose-Fähigkeiten sowie nach dem Pull die OCI-Identität geprüft. Unter
   der globalen Sperre gleicht `up` einen vorhandenen Stack zusätzlich exakt
   mit den Live-Mounts ab. Der private Runtime-Snapshot liegt bei diesem
   `sudo`-Ablauf unter `/var/lib/estab-deploy`; seine Rechte, Secret-Mounts und
   sichere Bereinigung werden automatisch geprüft. Ein lokaler Build findet
   nicht statt. Nach einem privaten Pull kann die Registry-Anmeldung mit
   `sudo docker logout ghcr.io` entfernt werden.
5. Container Manager dient anschließend für Status- und Logeinsicht. `db` und
   `app` müssen gesund sein; `migrate` und `admin-auth-init` müssen mit
   Exitcode 0 beendet sein. Danach `/health.php` und die Administration
   prüfen, den ersten Einsatz anlegen und aktivieren, benötigte Funktionskonten
   in der Benutzerverwaltung anlegen und deren feste Zuordnung kontrollieren.
   Die fachliche Abnahme kann danach unmittelbar mit den festen Funktionen der
   Konten beginnen; eine Dienstschicht oder Funktions-Hut-Auswahl ist nicht
   erforderlich. Optional können Konten unter **Optionale Schichten** zu
   Zugangsgruppen zusammengefasst und gemeinsam freigegeben oder abgemeldet
   werden. Diese Gruppen verändern keine Fachberechtigung.

Synologys
[Projektassistent](https://kb.synology.com/de-de/DSM/help/ContainerManager/docker_project?version=7)
kann Compose-Dateien importieren, ersetzt aber weder
`verify-release.sh` noch die Digest-/OCI-Prüfungen von `deploy.sh`. Der
unterstützte Erststart und jedes Upgrade laufen deshalb über den Helfer; die
GUI darf die gebundenen Paketdateien und Imagezeilen nicht umschreiben. Der
optionale Web-Station-Portalweg ersetzt nicht die TLS-, Proxy- und
Firewall-Prüfung.

## Backup und Wiederherstellung auf dem NAS

Das Releasepaket enthält `backup.sh`, den rein lesenden `verify-backup.sh` und
den destruktiv guardierten `restore.sh`. Im Projektordner wird ein Vollbackup
mit einem neuen, ausdrücklich absoluten Ziel gestartet:

```console
mkdir -p "$(pwd -P)/backups"
chmod 0700 "$(pwd -P)/backups"
backup_target="$(pwd -P)/backups/$(date +%Y%m%d-%H%M%S)"
ESTAB_CONTAINER_CLI=docker sh ./backup.sh "$backup_target"
```

Der Helfer verlangt einen eindeutig zugeordneten Stack mit erfolgreichem
Migrator und nachweislich gesunden `app`- und `db`-Containern. Er stoppt nur
die App, erzeugt einen transaktionalen MariaDB-Dump sowie Archive von
`4fdata` und Exportdaten, startet die App wieder und wartet mit begrenzter
Laufzeit auf ihren echten `healthy`-Status. Auch der Fehlerpfad gibt einen
Neustart erst nach dieser Health-Prüfung als erfolgreich frei.
Das Elternverzeichnis des Backupziels muss dem ausführenden Benutzer gehören
und exakt Modus `0700` haben. `backup.sh` bindet den reservierten Zielordner
zusätzlich an Geräte-, Inode-, Eigentümer- und Modusidentität und verweigert
Veröffentlichung sowie Cleanup, falls diese Identität wechselt.

Der atomar erworbene engine-weite Containername
`estab-maintenance-lock-<COMPOSE_PROJECT_NAME>` schließt Backup, Restore und
`deploy.sh up` auch über verschiedene Releaseordner hinweg gegenseitig aus.
Der Lock läuft netzlos mit dem verifizierten App-Image und ohne
Restart-Policy; nach einem Host-/Engine-Absturz bleibt sein Name fail-closed
als Diagnoseobjekt bestehen.
`backups/.estab-backup.lock` schützt zusätzlich die Veröffentlichung gegen
parallele Backup-Prozesse im selben Elternverzeichnis. Projekt, Datenbank,
effektive Speicherquellen, die drei architekturunabhängigen
`Config.Image`-Referenzen und die drei diagnostischen Runtime-Image-IDs werden
im Format 3 durch `SHA256SUMS` gebunden. Nach interner Verifikation reserviert
ein atomares `mkdir TARGET` den endgültigen Namen ohne Überschreiben. Erzeugt
ein anderer Prozess das Ziel in derselben Race, schlägt die Reservierung fehl
und dessen Inhalt bleibt unangetastet. Nur in das selbst reservierte,
geschützte Ziel werden die exakt bekannten Dateien aus dem privaten
Geschwister-Staging verschoben; danach wird der Satz erneut vollständig
verifiziert. Die No-Clobber-Garantie hängt damit nicht vom unterschiedlichen
Zielverzeichnisverhalten von GNU-, BSD- oder BusyBox-`mv` ab.
Docker-IDs mit `sha256:` und Podmans nackte 64-stellige Kleinhex-IDs werden
dabei im Metadatensatz kanonisch als `sha256:<64 Kleinhexzeichen>` gespeichert;
jedes andere Runtime-ID-Format beendet den Lauf vor dem App-Stopp.
Manifestgebundene `Config.Image`-Referenzen werden ebenfalls
providerunabhängig geschrieben: Redundante Tags vor `@sha256:` entfallen,
kurze Docker-Hub-Namen werden zu `docker.io/library/…` beziehungsweise
`docker.io/…` erweitert und ein ausdrücklicher Registry-Port bleibt erhalten.
Veränderliche Tags und lokale Referenzen bleiben unverändert und eignen sich
nur für einen exakten Same-Runtime-Restore.

Bei einem Fehler oder Signal werden Staging, ein über die Publikationssperre
als selbst reserviert bewiesenes unvollständiges Ziel und nur ein über exakte
ID, Labels, Image und Status als eigener Lock bewiesener Container entfernt.
Ein unerwartet verändertes oder fremdes Ziel bleibt fail-closed stehen.
Ein fremder oder nach Hostabsturz gestoppter Lock bleibt bestehen; die
Diagnose nennt ID, Projekt, Operation, Eigentümerkennung, Startzeit und
Status. Erst nach dem Beweis, dass keine Wartungsoperation mehr läuft, darf
exakt diese ID mit `docker container rm --force LOCK_ID` beziehungsweise dem
Podman-Pendant entfernt werden. `ESTAB_BACKUP_HEALTH_TIMEOUT_SECONDS` begrenzt Health-Waits auf
standardmäßig 240 Sekunden und akzeptiert Werte von 1 bis 3600.

Der Zugriff über Containerpfade funktioniert unverändert mit den drei
`./data/...`-Bind-Mounts und mit benannten Volumes. Das laufende
MariaDB-Verzeichnis `data/db` darf nicht als Ersatz für den logischen Dump
kopiert werden. Details, Format-3-Metadaten, Format-2-Kompatibilität,
Legacy-Verifikation und die
kontrollierte Wiederherstellung stehen in
[`docs/BACKUP-UND-WIEDERHERSTELLUNG.md`](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md).

Für den Restore wird das vollständig geprüfte Releasepaket verwendet, dessen
gebundene Image-Referenzen exakt den Sicherungsmetadaten entsprechen. Die
Imagezeilen in `.env` werden auch dafür nicht manuell geändert. Dieser
Pull-only-Weg verwendet keinen lokalen Build. Der Restore akzeptiert die
vollständig gebundenen Formate 2 und 3 und verlangt den Compose-Projektnamen
als ausdrückliche Bestätigung:

```console
restore_dir="$(pwd -P)/backups/20260723-120000"
confirmed_project=estab
ESTAB_CONTAINER_CLI=docker \
  sh ./restore.sh \
    --confirm-project "$confirmed_project" \
    "$restore_dir"
```

Ohne weitere Optionen gilt der exakte Same-Host-Vertrag: Projekt, Mounttyp,
Volume-Name, Speicherquelle, Image-Referenz und Runtime-ID dürfen nicht
abweichen. Es gibt keine implizite Umbenennung. Ein portabler Format-3-Restore
auf ein anderes Projekt oder Speicherlayout muss jede Abweichung
rollenbezogen als `alt=neu` mit `--remap-project`,
`--remap-mount-type`, `--remap-volume` und `--remap-storage` bestätigen. Bei
Named-Volume→Bind sind Typ (`volume=bind`), Name (`ALTER_NAME=-`) und
effektiver Pfad getrennt anzugeben. Eine architekturbedingt andere Runtime-ID
benötigt zusätzlich `--allow-runtime-image-id-change`; diese Ausnahme gilt nur
bei bytegleichen `Config.Image`-Referenzen aller Rollen, die jeweils auf die
kanonische Release-Identität `@sha256:<64 Kleinhexzeichen>` zeigen.
Veränderliche oder lokale Tags können eine Runtime-ID-Abweichung nie
autorisieren. Format 2 bleibt auf den exakten Same-Host-Restore beschränkt;
alle Projekt-, Speicher-, Mounttyp- und Volume-Remaps sowie
`--allow-runtime-image-id-change` sind dafür verboten.
Der vollständige Aufruf und alle Negativregeln stehen im verlinkten Runbook.

Vor jedem Überschreiben vergleicht der Helfer Backup, Datenbank, Projekt,
Mounts und die drei Runtime-Images. Zusätzliche Mountgrenzen unter oder über
den produktiven Zielen sowie gleiche oder verschachtelte Host-/Volume-Quellen
werden abgewiesen. Backupziel beziehungsweise Restore-Quellverzeichnis dürfen
ebenfalls weder in einer produktiven Quelle liegen noch diese umschließen.
Bei jedem Projekt-Remap oder rollenbezogenen Speicheridentitäts-Remap
(`--remap-mount-type`, `--remap-storage` oder `--remap-volume`) durchsucht
`restore.sh` zusätzlich alle Container derselben Engine einschließlich
gestoppter Instanzen. Das gilt auch bei unverändertem Compose-Projektnamen.
Eine gleiche, übergeordnete oder untergeordnete Bind-/Volume-Quelle eines
fremden Containers blockiert den Restore; bloßes Stoppen des alten Containers
genügt nicht. Diese Prüfung wird vor dem privaten Snapshot und nochmals direkt
vor Datenbank- und Dateiwiederherstellung wiederholt.

Vor dem App-Stopp kopiert der Helfer den exakten verifizierten
Format-2-/Format-3-Satz in einen privaten Snapshot und verwendet danach
ausschließlich diese Kopie. Standardmäßig liegt der zufällig benannte Ordner
im Elternverzeichnis des Backups. Für ein schreibgeschütztes Backupmedium oder
einen getrennten Speicher wird ein vorhandenes absolutes, echtes und
beschreibbares Verzeichnis vorgegeben:

```console
snapshot_parent=/volume1/estab-restore-snapshots
mkdir -p "$snapshot_parent"
chmod 0700 "$snapshot_parent"
ESTAB_RESTORE_SNAPSHOT_PARENT="$snapshot_parent" \
ESTAB_CONTAINER_CLI=docker \
  sh ./restore.sh \
    --confirm-project "$confirmed_project" \
    "$restore_dir"
```

Das Elternverzeichnis muss `root` oder dem ausführenden Benutzer gehören;
`0700` ist der empfohlene Modus. Vor dem Restore muss dort Platz für einen
vollständigen zusätzlichen Sicherungssatz plus betriebliche Reserve
vorhanden sein. Der Snapshot erhält `0700`, seine Dateien `0600`; Pfad,
Inventar, Manifest und Prüfsummen werden vor jeder Mutation erneut geprüft.
Bei Erfolg oder einem Abbruch vor der ersten Mutation wird nur dieser
nachgewiesene Snapshot sicher entfernt. Nach einem destruktiven Fehler bleibt
er verifiziert erhalten und sein absoluter Recovery-Pfad wird ausgegeben.

Alle drei produktiven Mounts müssen ausdrücklich Read/Write sein. Nach dem App-Stopp
beweist ein netzloser Hilfscontainer mit exakt dem geprüften App-Image durch
privates Anlegen, Lesen und Entfernen je einer Probe ihre effektive
Schreibbarkeit. Auch `admin-auth-init` muss schon vor dem Datenbankstart
Exitcode 0 und das geprüfte App-Image besitzen. Erst danach importiert der Helfer die
Datenbank, leert ausschließlich die zwei fest geprüften Container-Mounts,
spielt beide Archive ein, verlangt einen erfolgreichen Migrator und startet
die App erst nach bestandenem Health-Gate. `admin-auth-init` muss ebenfalls
mit Exitcode 0 beendet sein und das geprüfte App-Image verwenden. Nach einem
Fehler ab Beginn des Imports bleibt die App mit einer klaren Meldung
`RECOVERY REQUIRED` gestoppt. Der globale Lock bleibt dann absichtlich
bestehen. Nach Ursachenbehebung muss bewiesen werden, dass kein Restore mehr
läuft, exakt die gemeldete Lock-ID entfernt und der Restore mit dem
ausgegebenen verifizierten Snapshot-Pfad erneut ausgeführt werden. Dieser
Recovery-Snapshot wird als ausdrücklich übergebene Quelle nicht automatisch
gelöscht; nach erfolgreicher fachlicher Abnahme wird er gemäß
Aufbewahrungsregel archiviert oder kontrolliert entfernt.
Es gibt kein rekursives Löschen eines aus einer Hostvariable übernommenen
Pfads.

## Kontrolliertes Upgrade

Vor jedem Imagewechsel wird ein Vollbackup nach
[`docs/BACKUP-UND-WIEDERHERSTELLUNG.md`](../../docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
erstellt und testweise wiederhergestellt. Das neue Archiv und seine äußere
Prüfsumme werden unabhängig geprüft und vollständig in ein neues
Versionsverzeichnis entpackt. Von der neuen, digestgebundenen `.env.example`
wird eine neue `.env` erzeugt; ausschließlich die bisherigen
Nicht-Image-Einstellungen werden übernommen. `COMPOSE_PROJECT_NAME`, Secrets
und die drei `ESTAB_*_DATA_SOURCE`-Werte müssen exakt beim bisherigen Stand
bleiben. Die beiden Imagezeilen stammen unverändert aus dem neuen Paket.

```console
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh check
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh pull
ESTAB_CONTAINER_CLI=docker sh ./deploy.sh up
```

Das Vollbackup muss dieselben effektiven Live-Speicherquellen binden.
`deploy.sh check` belegt dafür die benötigte nicht mutierende
Compose-Abfrage; `up` erwirbt denselben projektweiten Lock wie Backup und
Restore und vergleicht erst darunter das eindeutige vorhandene
`db`-/`app`-Paar samt produktiven Live-Mounts mit der erneut gelesenen `.env`.
Migrator und `admin-auth-init` müssen mit Exitcode 0 enden. Ein
fehlgeschlagener Lauf wird nicht umgangen; zuerst bleiben Datenbank und Logs
unverändert zu untersuchen.

## Unvollständigen Publish-Lauf behandeln

Die Digest-Pushes und die Veröffentlichung eines GitHub-Releases sind
technisch nicht atomar. Es werden jedoch zu keiner Zeit OCI-Tags angelegt oder
überschrieben. Ein abgebrochener Build kann deshalb nur adressierbare,
unbenannte Digestobjekte in GHCR hinterlassen. In jedem Fall gilt:

- Ein bloß vorhandener GHCR-Digest ist keine Freigabe.
- Ein verstecktes Draft-Release ist nicht freigegeben.
- Installierbar ist erst das Digestpaar aus einem sichtbaren, unveränderlichen
  und attestierten GitHub-Release, dessen Installations- und Evidence-Archiv
  samt beiden äußeren SHA-256-Dateien vollständig vorhanden und geprüft sind.

Je nach Abbruchzeitpunkt ist unterschiedlich vorzugehen:

1. **Build- oder Plattform-Gate fehlgeschlagen:** Es entsteht kein
   Draft-Release. Workflowlog, Architektur-Evidence und Digests sichern,
   Ursache beheben und nur den kontrollierten Workflow erneut ausführen.
2. **Draft-/Asset-Prüfung fehlgeschlagen:** Der Workflow versucht,
   in einem jobweiten Fehlerabschluss ausschließlich die beim Erzeugen
   aufgezeichnete numerische Release-ID wieder zu löschen. Vorher müssen
   Identität, Tag, Ziel-Commit, Titel, Beschreibung und der weiterhin
   unveröffentlichte, veränderliche Draft-Zustand exakt stimmen. Ein bereits
   sichtbares, unveränderliches oder fremd verändertes Release wird niemals
   gelöscht. Schlägt diese sichere Best-effort-Bereinigung fehl, das Draft
   keinesfalls veröffentlichen; Kennung, Assets und Logs durch zwei Personen
   prüfen und kontrolliert entfernen.
3. **Veröffentlichungsaufruf unklar beendet:** Wenn `draft=false` bereits
   angenommen wurde, kann das Release sichtbar sein, obwohl die abschließende
   Statusabfrage fehlschlug. Vor jedem Retry zuerst Sichtbarkeit, alle vier
   Assets, die beiden Digestreferenzen, Tag-Ruleset und Remote-Tag-Commit
   prüfen; niemals von einem roten Workflowstatus allein auf einen
   unveröffentlichten Stand schließen.
4. **Release sichtbar, Abschlussprüfung rot:** `isImmutable=true`, die vier
   Assets, `gh release verify` sowie Tag-Ruleset und Remote-Tag-Commit getrennt
   prüfen. Ein immutable Release darf weder editiert noch durch Asset-Austausch
   „repariert“ werden; ein fehlender Nachweis ist ein Freigabefehler.

Ein vorhandenes Release wird nie still überschrieben. Ein Retry verwendet
denselben geschützten Git-Tag, erzeugt aber weiterhin keine OCI-Tags.
