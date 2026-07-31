# eStab

Dieses Repository bewahrt den vollständigen historischen
eStab-Anwendungsverlauf bis zum belegten SVN-Ende r85 und den separat
versionierten Dokument-Endbestand von r85. Es stellt den letzten
veröffentlichten Stand als überprüfbaren PHP-/MariaDB-Container bereit. Die
fachlichen Module für Nachrichtenvordruck, Sichtung, Anhänge, Kategorien,
Einsatztagebuch, technisches Betriebsbuch und PDF-Vordrucke bleiben erhalten;
die Laufzeit wurde für PHP 8.5 und MariaDB 11.8 kompatibel gemacht.

Der Container ist standardmäßig nur über `127.0.0.1:8080` erreichbar. Für einen
Zugriff aus anderen Netzen gehört ein TLS-terminierender Reverse Proxy davor.
eStab enthält weiterhin umfangreichen Legacy-Code und sollte nicht ungeprüft
direkt ins Internet gestellt werden.

## Schnellstart für eine neue Installation

Vorausgesetzt werden Docker oder Podman mit einem Compose-Provider sowie
`openssl` und `curl`. Der Schnellstart setzt `engine=podman`; für Docker wird
dort nur `engine=docker` gewählt. Das Pull-only-Paket prüft die tatsächlich
benötigten Compose-Fähigkeiten, statt sich auf eine bloße Versionsangabe zu
verlassen.

```console
cp .env.example .env
mkdir -p secrets
chmod 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

Danach die bereitstellungs- und netzbezogenen Werte in `.env` prüfen und den
Stack starten:

```console
engine=podman
compose() { "$engine" compose "$@"; }

estab_http_port=$(
    LC_ALL=C awk '
        index($0, "ESTAB_HTTP_PORT=") == 1 {
            matches++
            value = substr($0, length("ESTAB_HTTP_PORT=") + 1)
        }
        END {
            if (matches != 1 || value !~ /^[0-9]+$/ ||
                value + 0 < 1 || value + 0 > 65535) {
                exit 1
            }
            print value
        }
    ' .env
) || {
    printf 'ESTAB_HTTP_PORT fehlt oder ist in .env ungültig\n' >&2
    exit 1
}

compose config >/dev/null
compose build --pull migrate app
compose up -d

estab_diagnostics()
{
    printf 'eStab ist nicht bereit: %s\n' "$1" >&2
    compose ps --all >&2 || true
    compose logs --tail 100 db migrate admin-auth-init app >&2 || true
}

wait_for_estab()
{
    estab_deadline=$(( $(date +%s) + 300 ))
    while :; do
        if estab_health=$(curl --fail --silent --max-time 5 \
            "http://127.0.0.1:$estab_http_port/health.php" 2>/dev/null) &&
            printf '%s\n' "$estab_health" |
                grep -Fq '"status":"ready"'; then
            printf '%s\n' "$estab_health"
            return 0
        fi

        for estab_service in db app; do
            estab_id=$(compose ps --all -q "$estab_service" 2>/dev/null ||
                true)
            [ -n "$estab_id" ] || continue
            estab_state=$("$engine" inspect --format \
                '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' \
                "$estab_id" 2>/dev/null || true)
            case "$estab_state" in
                exited\ * | dead\ * | *\ unhealthy)
                    estab_diagnostics \
                        "Service $estab_service ist $estab_state"
                    return 1
                    ;;
            esac
        done

        for estab_service in migrate admin-auth-init; do
            estab_id=$(compose ps --all -q "$estab_service" \
                2>/dev/null || true)
            if [ -n "$estab_id" ]; then
                estab_state=$("$engine" inspect --format \
                    '{{.State.Status}} {{.State.ExitCode}}' "$estab_id" \
                    2>/dev/null || true)
                case "$estab_state" in
                    exited\ 0) ;;
                    exited\ * | dead\ *)
                        estab_diagnostics \
                            "Service $estab_service endete als $estab_state"
                        return 1
                        ;;
                esac
            fi
        done

        if [ "$(date +%s)" -ge "$estab_deadline" ]; then
            estab_diagnostics 'Zeitlimit von 300 Sekunden erreicht'
            return 1
        fi
        sleep 3
    done
}

wait_for_estab
```

Nach der gesunden Datenbank läuft zuerst der einmalige Service `migrate`.
Er prüft und protokolliert alle SQL-Migrationen per SHA-256 und führt den
vollständigen Schematest aus. Der netzlose One-shot-Service
`admin-auth-init` liest getrennt davon das Admin-Klartextsecret und
veröffentlicht ausschließlich eine bcrypt-`htpasswd` im privaten
`estab_auth`-Volume. `app` mountet nur diese abgeleitete Datei
schreibgeschützt; das Admin-Klartextsecret ist im Webcontainer weder
eingehängt noch als Umgebungswert vorhanden. `app` darf erst starten, wenn
beide Initialisierungen erfolgreich mit Exitcode 0 beendet wurden. Vor dem
ersten Start einer neuen Version auf einem vorhandenen Datenvolume ist
deshalb immer ein Vollbackup Pflicht.
Die Compose-Mounts enthalten außerdem die standardisierten `z`/`Z`-Optionen,
damit Secrets und dedizierte Datenpfade auch mit rootlosem Podman unter
SELinux `Enforcing` erreichbar bleiben, ohne die Containerkennzeichnung
abzuschalten.

Die Schleife wartet höchstens fünf Minuten, bricht bei einem klar
fehlgeschlagenen Datenbank-, Authentisierungs-, Migrator- oder App-Service
vorzeitig mit Status und den letzten Logs ab und benötigt kein
providerabhängiges Compose-`--wait`. Sie liest `ESTAB_HTTP_PORT` exakt aus
`.env`, statt stillschweigend Port 8080 anzunehmen. Die lokale Abfrage über
`127.0.0.1` passt zum Standard-Bind und zu `ESTAB_HTTP_BIND=0.0.0.0`; bei
einer Bindung ausschließlich an eine andere konkrete Hostadresse muss diese
Adresse auch in der Health-URL verwendet werden.
Für Docker genügt `engine=docker`. Eine erfolgreiche Bereitschaftsprüfung
liefert HTTP 200 und `"status":"ready"`. Zusätzlich sollte nach
Erstinstallation, Upgrade und Wiederherstellung das vollständige Schema
geprüft werden:

```console
compose run --rm migrate
```

Der Runner gibt `Post-migration schema verification passed` aus. Er verwendet
das Root-Secret ausschließlich über eine private temporäre MariaDB-Optionsdatei;
das Kennwort erscheint weder in Argumentliste noch Log.

Eine Neuinstallation beginnt absichtlich ohne aktiven Einsatz und ohne aktive
Dienstschicht. Anmeldung und Administration bleiben erreichbar, operative
Eingaben sind aber fail-closed gesperrt. Die erste fachliche Einrichtung
erfolgt in dieser Reihenfolge:

1. Unter `/4fadm/incidents.php` einen Einsatz mit einem **Namen der
   Führungsstelle** anlegen und aktivieren. Diese lokale
   Anschrift/Absendereinheit ist ein eigenes Pflichtfeld und darf nicht mit
   Einsatzname, Trägerorganisation oder Einsatzleitung verwechselt werden.
2. Unter `/4fadm/users.php` persönliche Konten für mindestens S2, Si, S6,
   LdF und A/W anlegen. Eine Person darf mehrere Funktionen übernehmen;
   mehrere A/W-Besetzungen sind möglich.
3. Unter `/4fadm/fuehrungsstelle.php` eine geplante Dienstschicht anlegen und
   die Funktionen den tatsächlichen, ungesperrten Konten zuweisen. Konten
   dürfen dabei noch offline sein; der Online-Status ist im Auswahlfeld
   kenntlich gemacht.
4. Jede Person meldet sich an und nimmt ihre Zuweisungen unter
   `/4fach/fuehrungsstelle.php` selbst an.
5. Erst wenn S2, Si, S6, LdF und A/W angenommen sind, aktiviert die
   Administration die Schicht.
6. S6 wählt den angenommenen Funktions-Hut, erstellt einen Fernmeldeplan mit
   den vorgesehenen Wegen und veröffentlicht ihn. Erst danach kann LdF einen
   Ausgang auf einen verbindlichen Weg disponieren.

Migration 97 lässt Führungsstellennamen bereits vorhandener Einsätze bewusst
`NULL`, statt einen Wert aus Einsatzname, Organisation, Einsatzleitung oder
Umgebung zu erfinden. Ein offener Alt-Einsatz muss vor Aktivierung oder
weiteren operativen Eingaben einmalig unter `/4fadm/incidents.php` mit dem
tatsächlichen Namen bestätigt werden. Diese Erstbestätigung ist auch bei
vorhandenen historischen Fachdaten möglich. Ein schon belegter Name kann nur
bis zur ersten operativen Eintragung korrigiert werden und ist danach
unveränderlich; dafür speichert der Einsatz einen dauerhaften
Erstschreib-Sperrmarker. Auch das spätere Löschen einzelner Fachdaten hebt
diese Sperre nicht auf. Formal abgeschlossene Alt-Einsätze bleiben
unverändert.

Eine Schichtübergabe wird von der Administration nur angefordert. Erst ein
persönlich angemeldetes Konto mit angenommener Funktion der Nachfolgeschicht
bestätigt sie und löst den atomaren Schichtwechsel aus. Fehlanforderungen
bleiben als begründet stornierter Nachweis erhalten.

Führungsstellenname, Einsatz, aktive Arbeitsfunktion und gegebenenfalls
fehlender Dienstbetrieb müssen danach in Statusleiste beziehungsweise
Führungsstellenansicht eindeutig erkennbar sein. Ohne gültigen
Führungsstellennamen oder aktive Schicht nimmt eStab keine operative Eingabe
an. Funktionskonten lassen sich unter `/4fadm/users.php` sperren, entsperren
und mit einem neuen Kennwort versehen. Ein vollständiges
ETB-/TBB-/Nachrichten-/Nachweis-/Dienst-/S6-/Melder-/Anhang-Dossier für einen
aktiven oder historischen Einsatz erzeugt `/4fadm/incident_export.php`.
Für operative Daten genügt eine Kontoanmeldung nicht: Zusätzlich müssen ein
aktiver Einsatz und die exakte ID einer persönlich angenommenen, aktiven
Dienstbesetzung in der Sitzung ausgewählt sein. Jeder operative Schreibpfad
gleicht diese ID erneut mit Konto, Funktion, Rolle, aktivem Einsatz und aktiver
Schicht in der Datenbank ab. Diese Grenze gilt auch für ETB, TBB, Kategorien,
Nachrichten, Vordrucke und Anhänge. Unter `/4fach/vordrucke.php` erscheinen
nur abgeschlossene Nachrichten des aktiven Einsatzes, die der ausgewählte
Funktions-Hut nach der Nachrichten-Objektregel lesen darf. Der aktuelle Abzug
verwendet das mit dem Dossier gemeinsame PDF-Layout. Die beim
Nachrichtenabschluss atomar gespeicherte Archivdatei wird dabei weder ersetzt
noch verändert und bleibt Bestandteil von Backup und Restore.
Der Anhangdialog unterstützt echte JPEG-Bilder mit `.jpg` und `.jpeg`,
prüft den MIME-Typ serverseitig und nennt das standardmäßige Uploadlimit von
20 MiB direkt am Dateifeld.

### Ohne lokalen Image-Build

Für Docker, Podman und Synology Container Manager ist ein eigenständiges
[pull-only-Deploymentpaket](deploy/registry/README.md) vorbereitet. Es
referenziert ein gemeinsam versioniertes App-/Migrator-Imagepaar für
`linux/amd64` und `linux/arm64`; der Migrator trägt das Basisschema selbst, so
dass keine SQL-Datei aus einem Git-Checkout auf das Zielgerät gemountet wird.

Im Repository ist noch kein freigegebener Image-Stand mit Manifest-Digests
dokumentiert. Der Publish-Workflow ist manuell, akzeptiert ausschließlich
einen vorhandenen und gegen Update/Löschen geschützten Git-Tag und pusht jeden
Dockerfile-Build genau einmal digest-only ohne OCI-Tag. Native
`amd64`- und `arm64`-Jobs ziehen danach die exakten Index-Digests und
führen damit Fresh-/Restore-CI, Attestations-, SBOM-/Provenance- und
High-/Critical-CVE-Gates aus; der echte Browsertest ist auf `amd64`
verpflichtend. Erst wenn beide Architekturen grün sind, erzeugt ein separater
  Job ein verstecktes Draft-Release und lädt Installations- und dauerhaftes
  Evidence-Archiv samt ihrer beiden äußeren SHA-256-Dateien hoch und wieder
  herunter. Erst nach vollständiger Prüfung der vier Assets und des
  Tag-Rulesets macht er das Draft-Release ganz zuletzt sichtbar. Danach müssen
  GitHub `isImmutable=true`, exakt diese vier Assets
  und eine gültige Release-Attestation ausweisen. Der Ablauf bleibt zusätzlich
  durch Rechtebestätigung, drei Repositoryvariablen, ein nur in den
  Policy-Schritten verwendetes administratives Ruleset-Prüftoken und ein
  zwingend mit Required Reviewer geschütztes GitHub-Environment gesperrt.
  Selbst diese Freigaben
  genügen nicht: Der Workflow verlangt zusätzlich nicht leere, versionierte
  Dateien `LICENSE` und `THIRD_PARTY_NOTICES.md`. Solange die Rechteprüfung
  diese konkreten Auslieferungsartefakte nicht hervorgebracht hat, ist ein
  Publish technisch unmöglich.

Bloße GHCR-Digestobjekte oder ein verstecktes Draft-Release sind kein Release
und dürfen nicht installiert werden. Verwendbar sind nur die beiden Digests
aus einem sichtbaren, als unveränderlich und
  attestiert nachgewiesenen GitHub-Release mit exakt vier geprüften Assets.
  Das Installationspaket enthält `compose.yaml`, gebundene `.env.example`,
  Runbooks, atomaren Backup-Helfer und rein lesenden Backup-Verifier; das
  separate Evidence-Archiv bewahrt die nativen Tests, SBOM, Provenance,
  Schwachstellenscans und Attestationsnachweise dauerhaft.
Der [Recovery-Ablauf](deploy/registry/README.md#unvollständigen-publish-lauf-behandeln)
berücksichtigt, dass Digest-Pushes und GitHub Releases nicht atomar gemeinsam
entstehen. OCI-Tags – auch `latest` – gibt es absichtlich nicht.

## Zugänge

| Pfad | Zweck | Schutz |
| --- | --- | --- |
| `/` | Einstieg, direkter Anmeldebutton und Modulübersicht | öffentlich bis zur Modulanmeldung |
| `/4fach/index.php` | öffentlicher Einstieg in die vollständige Anwendung mit Kontoauswahl und Benutzeranmeldung | operative Daten erst mit eStab-Sitzung, aktivem Einsatz und ausgewählter, persönlich angenommener aktiver Dienstfunktion |
| `/4fach/logout.php` | zentrale Abmeldung aus Sitzungsleiste und Nachrichtenarbeitsbereich | eStab-Sitzung, ausschließlich POST mit Session-CSRF |
| `/4fach/katgoedt.php` | globale, Funktions- und persönliche Kategorien | ausgewählte aktive Dienstfunktion; Rollenprüfung, bei Nachrichtenbezug zusätzlich Objektprüfung, CSRF für Änderungen |
| `/4fach/fuehrungsstelle.php` | persönliche Dienstfunktionen annehmen/auswählen, freigegebenen S6-Plan lesen sowie S6- und Melderabläufe bearbeiten | eStab-Sitzung und aktiver Einsatz; vor Hutauswahl nur eigene Besetzungen, Annahme, Übergabebestätigung und Auswahl, danach exakte aktive Besetzungs-ID und Fachzuständigkeit |
| `/4fueltg/ue_ltg.php` | einsatzgebundene Meldungsübersicht | ausschließlich ausgewählte aktive S2-Funktion mit `LAGE_DOKUMENTATION` |
| `/4fach/nachwea.php` | Nachweisung der aufgenommenen und beförderten Nachrichten | ausschließlich ausgewählte aktive LdF- oder A/W-Funktion |
| `/4fach/vordrucke.php` | abgeschlossene Vordrucke des aktiven Einsatzes im aktuellen, mit dem Einsatzdossier gemeinsamen PDF-Layout öffnen | ausgewählte aktive Dienstfunktion; zugrunde liegende Nachricht, Abschluss- und Druckstatus werden erneut geprüft, das persistierte Archiv bleibt unverändert |
| `/4fach/anhang.php`, `/4fach/download.php`, `/4fach/showpic.php` | Anhänge auswählen, auflisten, herunterladen oder als Bildvorschau öffnen | ausgewählte aktive Dienstfunktion; verknüpfte Anhänge erben exakt die Leserechte mindestens einer verknüpften Nachricht, freie Anhänge sind nur für Uploader oder S2, Si und LdF sichtbar |
| `/stabetb/etb.php`, `/fmtbb/tbb.php` | ETB und TBB des aktiven Einsatzes lesen und fachabhängig ergänzen | jede ausgewählte aktive Dienstfunktion darf lesen; ETB-Schreiben nur als S2 oder ETB mit `EINSATZTAGEBUCH`, TBB-Schreiben nur als A/W mit `BEFOERDERUNG` |
| `/4fadm/admin.php` | Administration | separates HTTP Basic Auth |
| `/4fadm/incidents.php` | Einsätze samt Führungsstellennamen anlegen, historische Fehlwerte einmalig bestätigen, aktivieren und deaktivieren | HTTP Basic Auth, Session-CSRF, revisionsgesicherter globaler Status; die erste operative Eintragung setzt atomar einen dauerhaften Sperrmarker für den bestätigten Führungsstellennamen |
| `/4fadm/fuehrungsstelle.php` | Dienstschichten planen, Funktionen zuweisen, Schichten aktivieren/übergeben/schließen und Abschlussblocker prüfen | HTTP Basic Auth, Session-CSRF; Besetzungen und Übergaben werden einsatzgebunden und hashverkettet nachgewiesen |
| `/4fadm/users.php` | Benutzer anlegen, Funktionen fest zuweisen, sperren/entsperren und Kennwörter zurücksetzen | HTTP Basic Auth, Session-CSRF; Rollen werden serverseitig abgeleitet und aktive Sitzungen atomar widerrufen |
| `/4fadm/incident_export.php` | neun wählbare PDF-Abschnitte: ETB, TBB, Nachrichtenvordrucke, Anhänge, Nachrichtenereignisse, Dienstbetrieb, S6-Fernmeldepläne, Melderläufe und Betriebsereignisse | HTTP Basic Auth, Session-CSRF, einsatzgebundene Abfragen; neue Anhänge werden gegen ihren unveränderlichen SHA-256-/Größennachweis geprüft, Legacy wird als nicht belegbar ausgewiesen |
| `/4fadm/system_status.php` | ausführlicher Laufzeitstatus | HTTP Basic Auth |
| `/4fadm/export.php` | Einsatzexporte auflisten, erstellen, als ZIP herunterladen und einzeln löschen | HTTP Basic Auth; POST-Erstellung/-Löschung mit Session-CSRF; Download nur über validierte Exportkennung |
| `/4fadm/make_fkt.php` | aktive Empfängermatrix und einzelne Standardmatrix atomar bearbeiten | HTTP Basic Auth, Session-CSRF; Rollenabgleich und Sitzungswiderruf committen mit der aktiven Matrix |
| `/4fadm/set_number_after_crash.php` | Nachrichtenzähler nach Rückfallbetrieb erhöhen | HTTP Basic Auth und CSRF |
| `/4fach/resetpic.php` | Markierungen zur erneuten PDF-Vordruckerzeugung zurücksetzen | HTTP Basic Auth und CSRF |
| `/health.php` | knappe Readiness-Antwort für den Monitor | absichtlich ohne Anmeldung |
| `/doku/Handbuch_eStab.pdf` | historisches Anwendungshandbuch | öffentlich |

Der Benutzername für den Administrationsbereich steht in
`ESTAB_ADMIN_USER`, das Kennwort in der durch
`ESTAB_ADMIN_PASSWORD_SECRET_FILE` referenzierten Datei. Diese
Administrationsanmeldung ist unabhängig von den eStab-Funktionsbenutzern.
Nur `admin-auth-init` erhält diese Klartextdatei; Apache liest im laufenden
App-Container ausschließlich den daraus abgeleiteten bcrypt-Hash.

Die für Kapitel 4.3 der THW-DV 1-101 umgesetzten fachlichen Invarianten,
Quellfassung, Aussagegrenzen und Abnahmeschritte stehen in
[docs/DV-1-101-UMSETZUNG.md](docs/DV-1-101-UMSETZUNG.md). Die formale Sichtung
eines Ausgangs ist verbindlicher Bestandteil des Nachrichtenlaufs und kann
weder per Compose-Umgebung noch per Legacy-Konfiguration abgeschaltet werden.

### Nachrichtenlauf und Nachweisung

Die Statuswerte bezeichnen eindeutig die aktuell zuständige Arbeitsstufe:

| Status | Richtung | Zuständig | Bedeutung |
| --- | --- | --- | --- |
| `4` | Ausgang | Si | Anschrift, Verfasserzeichen und Verfasserfunktion formal prüfen; freigeben oder mit Pflichtgrund an den Verfasser zurückgeben |
| `10` | Ausgang | Verfasser | zurückgegebenen Entwurf korrigieren und erneut zur formalen Sichtung einreichen |
| `1` | Ausgang | LdF | Rufname der Gegenstelle und vorgesehenen Beförderungsweg festlegen |
| `2` | Ausgang | A/W | den vorbereiteten Ausgang tatsächlich befördern |
| `1` | Eingang | LdF | aufgenommenen Rufnamen in einen Absender übersetzen und den von A/W erfassten Eingangsweg bestätigen oder begründet korrigieren |
| `4` | Eingang | Si | Inhalt auswerten und Empfänger festlegen |
| `8` | beide | abgeschlossen | Nachricht ist fertig bearbeitet und der Vordruck kann erzeugt werden |

Ein Eingang läuft immer über `1 → 4 → 8`. Ein Ausgang läuft über
`4 → 1 → 2 → 8`; nach einer formalen Rückgabe entsteht
`4 → 10 → 4 → 1 → 2 → 8`. Es gibt keine Autosichtung. Ist Si nicht besetzt,
bleibt die Nachricht in dessen Warteschlange, ohne dass A/W einen
Sichtervermerk erzeugen kann.
Beim Eingang erfasst A/W zwingend den empfangenen Rufnamen, kann das Feld
„Absender“ aber weder im Formular noch über einen manipulierten Request
schreiben. A/W erfasst außerdem Medium, Aufnahmezeit und Aufnahmezeichen.
Erst LdF muss daraus einen nicht leeren Absender festlegen und den
Eingangsweg ausdrücklich bestätigen. Ändert LdF das Medium, ist eine
Begründung Pflicht; Aufnahmezeit und A/W-Zeichen bleiben unveränderlich.
Bestätigung, ursprüngliches und bestätigtes Medium, etwaige Begründung und
LdF-Identität werden atomar in der Nachrichten-Ereigniskette nachgewiesen.
Beim Fokus auf „Rufname der Gegenstelle“ bietet das Formular A/W und LdF
bisherige Rufnamen aus dem aktuell aktiven Einsatz in einer zugänglichen,
per Tastatur bedienbaren Auswahlliste an.
Für „Absender“ erhält ausschließlich LdF bei einem Eingang entsprechende
Vorschläge. Für LdF nutzt die Liste zusätzlich den gesperrten aktuellen
Vordruck als Kontext: Beim Eingang stehen bestätigte Zuordnungen vom
aufgenommenen Gegenstellenrufnamen zum Absender zuerst, beim Ausgang
bestätigte Zuordnungen von der Anschrift zum Gegenstellenrufnamen. Als
bestätigt zählen ausschließlich abgeschlossene Nachrichtenpaare desselben
aktiven Einsatzes; häufige und zuletzt verwendete Zuordnungen werden
bevorzugt. Danach folgen passende Einträge des aktuell gültigen, aktiven
S6-Fernmeldeplans und schließlich die allgemeine Einsatzhistorie. Die Herkunft
ist im Formular sichtbar.
Die Liste übernimmt nichts automatisch: Eine freie Eingabe bleibt jederzeit
möglich, die Browser-Autovervollständigung ist für diese Felder ausgeschaltet
und ohne JavaScript bleibt die native Browserliste als Rückfalloption
erhalten. Aktiver Einsatz, Dienstbesetzung, Funktion, Rolle, Richtung und
Sperrbesitz werden im selben Datenbank-Statement wie die Zuordnungen erneut
geprüft; Werte anderer Einsätze werden nicht offengelegt. Der lokale Absender
eines Ausgangs bleibt davon unberührt: Er wird serverseitig aus dem
autoritativen Führungsstellennamen des in derselben Schreibtransaktion
gesperrten Einsatzes gesetzt. Eingänge werden an dieselbe lokale
Führungsstelle adressiert. Browserwerte, Einsatzname, Organisation,
Einsatzleitung und Umgebungsvariablen sind dafür keine Ersatzquelle.

Vorrangsstufen werden überall mit denselben fachlichen Bezeichnungen
angezeigt und verarbeitet: **keine**, **Sofort**, **Blitz** und
**Staatsnot**. Warteschlangen sortieren ausdrücklich in dieser Reihenfolge
von Staatsnot nach keine; sie verlassen sich nicht auf die interne
MariaDB-`SET`-Reihenfolge. Staatsnot darf nur auf ausdrückliche Weisung einer
hierzu berechtigten Stelle verwendet werden. eStab kann diese externe
Berechtigung nicht selbst feststellen und weist deshalb im Formular darauf
hin. Das historische interne Kürzel `eee` bleibt lesbar und bedeutet wie ein
leerer Wert „keine“, wird bei neuen Vordrucken aber nicht mehr angeboten.
Die internen Kürzel bleiben für bestehende Ereignishashes und rohe
Tabellen-/CSV-Exporte unverändert; Bedienoberfläche und PDF drucken
ausschließlich die verständlichen Bezeichnungen, bei „keine“ bleibt das
Vorrangsfeld im PDF leer. Manipulierte Kombinationen oder unbekannte Werte
werden beim Speichern abgewiesen.

Aufnahme-, Verfasser-, Sichter-, LdF- und Beförderungszeichen stammen aus der
serverseitig geprüften Sitzung. Jeder erfolgreiche fachliche Übergang und sein
strukturierter Feldnachweis werden atomar in einer append-only Hashkette
gespeichert.

Die Nachweisung unterscheidet Aufnahme und Beförderung bewusst. Beim Eingang
zeigt sie das erfasste Eingangsmedium in Langform, etwa „Fernsprecher“, „Funk“,
„Melder“, „Fax“, „Fernschreiber“ oder „Datenübertragung“. Beim Ausgang
kombiniert sie das von LdF gewählte Medium mit dem ergänzenden Freitextweg.
Dieser Wert gilt erst mit gespeichertem Beförderungszeitpunkt als tatsächlich
befördert; zuvor steht dort eindeutig „Noch nicht befördert“. Leere Werte
erscheinen als „Nicht dokumentiert“, unbekannte historische Medien sichtbar
als „Unbekannt (…)“ und niemals als ausführbares HTML.

Der Anmeldeeinstieg trennt zwei Vorgänge ausdrücklich: „Mit bestehendem Konto
anmelden“ verlangt das bereits gespeicherte Kennwort und legt niemals ein Konto
an. Neue Installationen verwenden stattdessen die Benutzerverwaltung unter
`/4fadm/users.php`: Dort legt der technische Administrator Konten mit einer
festen Funktion und einem Startkennwort an. Die Rolle wird ausschließlich
serverseitig aus Funktion und Empfängermatrix abgeleitet. Die öffentliche
„Neues Konto anlegen“-Kompatibilitätsfunktion ist standardmäßig ausgeschaltet;
wird sie bewusst aktiviert, verlangt sie eine Kennwortbestätigung und meldet
niemals still ein vorhandenes Konto an. Beide Browserabläufe sind bereits vor
der Anmeldung an ein Session-CSRF-Token gebunden.

Ändert der Administrator die aktive Empfängermatrix, werden geänderte Rollen
in derselben Transaktion in die betroffenen Konten übernommen und deren
Sitzungen widerrufen. Entfernte Funktionen werden nicht automatisch
umgedeutet: Das Konto erscheint in der Benutzerverwaltung als ungültig
zugeordnet, bleibt abgemeldet und muss dort einer gültigen Funktion neu
zugewiesen werden.

Auf der Übersicht führen zwei getrennte Schaltflächen unmittelbar zum
passenden Bestands- beziehungsweise Neuanlageformular. Ist
Selbstregistrierung deaktiviert, verschwindet die Neuanlage-Schaltfläche und
ein Hinweis verweist auf Administration → Benutzerverwaltung. Ohne
eStab-Sitzung sind
geschützte Modulkarten sichtbar als „Anmeldung erforderlich“ gekennzeichnet
und führen zum Anmeldeeinstieg statt auf eine HTTP-403-Seite. Der dort
ausgewählte Bereich wird als fester, serverseitig erlaubter Schlüssel
in jedem Formular des jeweiligen Browser-Tabs beibehalten und nach
erfolgreicher Anmeldung direkt geöffnet. Freie Rücksprung-URLs werden nicht
akzeptiert. Auch direkt aufgerufene oder als Lesezeichen gespeicherte
Fachseiten sowie ein in einem neuen Tab geöffneter Download führen bei
fehlender Sitzung mit HTTP 303 in genau diesen Bestandslogin. Die
Weiterleitung berücksichtigt den Browserkontext: `Sec-Fetch-Dest` führt
eingebettete Aufrufe direkt in das Content-Login; die ausschließlich für den
rechten `mainframe` bestimmten Anhangs- und Kategoriecontroller verwenden
diesen Einstieg auch ohne den Header. Dadurch kann sich der komplette
Zwei-Frame-Arbeitsbereich nicht in seinem eigenen Inhaltsframe verschachteln.
Die Anmeldeformulare bleiben mit `target="_self"` im aktuellen Kontext. Die
Anmeldekarte nennt das vorgemerkte Ziel und bietet immer
„Anmeldung abbrechen · Zur Übersicht“; dieser Link verlässt auch aus einem
Frame heraus den Arbeitsbereich auf Top-Level. Eine angemeldete Sitzung ohne
ausgewählte Dienstfunktion wird direkt zum Führungsstellenbetrieb geleitet.
Auch ein Browserformular, dessen Sitzung inzwischen abgelaufen ist, führt per
HTTP 303 zum Login; seine nicht verarbeiteten Eingaben werden dabei ausdrücklich
nicht erneut gesendet. Der Login weist sichtbar darauf hin, dass die Eingabe
nicht gespeichert wurde und erneut erfasst werden muss. Alte operative
GET-Querys des Nachrichtencontrollers werden ebenfalls vollständig verworfen
und führen ausschließlich zum erlaubten Ziel `messages`. Zugangsdaten oder
Login-Metadaten in einer solchen GET-Query bleiben dagegen eine harte
Ablehnung. Rollen-, Objekt-, CSRF-, Polling- und Bildendpunkte behalten ihre
knappen 403-Sicherheitsgrenzen. Die Administration ist als eigener technischer
Zugang markiert.

Das gemeinsame Manifest enthält in stabiler Reihenfolge neun operative
Bereiche: Übersicht, Nachrichtenvordruck, Führungsstellenbetrieb,
Meldungsübersicht, Vordrucke, ETB, TBB, Nachweisung und BOS-Info; hinzu kommen
Administration und Handbuch als zwei Dienste. Anonym sind damit elf Links
sichtbar. Nach der Anmeldung, aber vor Auswahl eines Funktions-Huts, bleiben
nur Übersicht, Führungsstellenbetrieb, BOS-Info, Administration und Handbuch
sichtbar. Der Führungsstellenbetrieb ist dabei der einzige operative
Bootstrap: Er zeigt nur Einsatz-/Schichtgrunddaten und eigene Besetzungen und
erlaubt persönliche Annahme, Übergabebestätigung sowie Auswahl einer eigenen
aktiven, angenommenen Besetzungs-ID. ETB, TBB, Nachrichten, Anhänge,
Fernmeldepläne und Melderaufträge bleiben bis dahin gesperrt. Nach der Auswahl
blendet die Navigation unzulässige Spezialziele aus: Gewöhnliche
Stab-/FB-Funktionen, Si und S6 sehen einschließlich der beiden Dienste neun
Links; S2 sowie LdF und A/W sehen jeweils den einen für sie freigegebenen
Spezialbereich und damit zehn. Die Endpunkte prüfen die
Berechtigung unabhängig von dieser Navigation erneut. Der aktive Bereich wird
markiert, interne Ziele öffnen immer im selben Browserkontext. Der
Nachrichtenarbeitsbereich besteht aus genau zwei
modernen `iframe`-Elementen: links der vollhohen `vorgaben`-Sidebar und rechts
dem `mainframe` für die Fachansicht. Auf Status und Sitzungsidentität folgen
zuerst die rollenabhängigen Fachaktionen und danach sämtliche dauerhaft
sichtbaren Bereichslinks; es gibt weder die frühere aufklappbare Auswahl
„Bereich wechseln“ noch eine darin verschachtelte Scrollfläche. Nur das
vollständige Sidebar-Dokument scrollt bei Bedarf. Der BOS-Bereich verwendet
dieselbe sichtbare Navigationslogik in einem responsiven Zwei-Spalten-
Arbeitsbereich. Öffentliche Seiten zeigen zusätzlich den Zustand „Nicht
angemeldet“ und einen Anmeldebutton; geschützte Ziele leiten verständlich zum
Login. Bis einschließlich 672 CSS-Pixel Breite stehen Sidebar und Fachinhalt
als zwei jeweils viewporthohe Zeilen untereinander. Eine rollenabhängige
Fachaktion scrollt automatisch zum Inhalt und setzt den Tastaturfokus auf
dessen Frame; der dort eingeblendete, mindestens 44 Pixel große Button „Menü“
führt samt Fokus wieder zur Sidebar.

Neue Eingangs- und Beförderungsvermerke zeigen beim Öffnen bereits die
aktuelle, über `TZ` konfigurierte lokale Uhrzeit. Der Wert bleibt ein normales
Eingabefeld und kann vor dem Absenden auf die tatsächliche Aufnahme- oder
Beförderungszeit korrigiert werden. Akzeptiert werden `HHMM`, `DDHHMM` und die
vollständige taktische Zeit `DDHHMMmmmYYYY`.

Alle eigenständigen Fach- und Administrationsseiten verwenden darüber hinaus
dasselbe responsive Werkzeuggestell: ein klarer Seitenkopf, Status- und
Hinweisflächen, beschriftete Formularfelder, mindestens 44 Pixel große
Aktionen sowie eine eindeutige Rücknavigation. Dazu gehören insbesondere
Meldungsübersicht, Nachweisung, ETB, TBB, Vordrucke, Kategorienverwaltung,
Empfängermatrix, Einsatzverwaltung, Benutzerverwaltung, Exporte und
Systemstatus. Breite Fach- und Prüftabellen bleiben auf kleinen Bildschirmen
als Karten lesbar oder werden in einem ausdrücklich begrenzten Tabellenbereich
gescrollt; sie erzeugen kein horizontales Scrollen des gesamten Dokuments.
Historische interne Generatoren und Installer besitzen keine direkte
Weboberfläche und werden vom Webserver abgewiesen.

Nach erfolgreicher Anmeldung zeigen der Einstieg, der
Nachrichtenarbeitsbereich, die Administrationsseiten und alle geschützten
eigenständigen HTML-Module die gemeinsame Leiste mit Name, Kürzel, Funktion und
abgeleiteter Rolle. Der Button „Abmelden“ beendet die lokale Sitzung auch bei
einer nachgelagerten Datenbankstörung, löscht die Anwendungscookies und führt
anschließend zum Anmeldeeinstieg zurück. Oberhalb von Identität, Logout,
Bereichslinks und rollenabhängigen Textbuttons bündelt die Sidebar den
passenden Arbeitszähler, Serverzeit und Onlinebelegung in einer Statuskarte.
Nur dieses Statusfragment wird regelmäßig aktualisiert; Fokus und
Scrollposition des Sidebar-Dokuments bleiben dabei auch am Hinweiston-Schalter
erhalten. Offene Meldungen bleiben unabhängig vom einmaligen Tonsignal
dauerhaft farblich hervorgehoben. Ist die Statusdatenbank oder der globale
Einsatzstatus vorübergehend nicht erreichbar, bleiben Navigation, Abmeldung
und Administration bedienbar; operative Eingaben werden jedoch fail-closed
gesperrt. Der nicht messbare Zähler erscheint als „–“ und die Karte
kennzeichnet ihre Daten als unvollständig beziehungsweise nicht verfügbar.
Fehlgeschlagene oder hängende Browserabrufe wechseln sichtbar auf „Status
nicht aktuell“ und werden zeitlich begrenzt; der nächste erfolgreiche Abruf
meldet die Erholung. Die früheren eigenständigen Status-/Zähler-Helfer gehören
nicht mehr zur Runtime-Oberfläche. Formulare mit
ungespeicherten Fach- oder
Administrationseingaben warnen vor einem globalen Bereichswechsel oder
Logout. Hilfe- und Problemfenster prüfen dabei auch ungespeicherte Eingaben im
zugehörigen Hauptfenster und führen globale Navigation beziehungsweise Logout
dort aus. Auf Administrationsseiten wird der separate HTTP-Basic-Benutzer
sichtbar und klar von einem gegebenenfalls zusätzlich angemeldeten
eStab-Funktionskonto getrennt.

Für Fernmelder-, Si- und Stab-/FB-Warteschlangen bleiben die
rollenabhängigen Hinweise als mitgelieferte, gleich-originige PCM-WAV-Dateien
erhalten. Die erste erfolgreiche Messung setzt nur einmalig den jeweiligen
Sitzungsbasiswert `old_que_aw`, `old_que_si` oder `old_que_stab`; erst eine
spätere Erhöhung löst genau einen Hinweis aus. Hinweistöne sind pro Browser
zunächst ausgeschaltet und müssen über „Hinweistöne aktivieren“ ausdrücklich
freigegeben werden. Die Ein-/Aus-Entscheidung gilt browserweit; jeder neu
geladene Tab weist eine noch ausstehende Wiedergabefreigabe ehrlich aus, bis
eine Testwiedergabe dort erfolgreich war. Das langlebige Audioelement liegt
außerhalb des ausgetauschten Statusfragments. Ein sichtbarer Status samt
hervorgehobener Statuskarte bleibt als Rückmeldung erhalten, falls Audio
ausgeschaltet, blockiert oder nicht unterstützt ist. Der echte Browsertest
belegt Blockade, Reload, tabübergreifende Einstellung, automatische Auslösung
und das sichere Abbrechen noch laufender Wiedergabeversuche. Er belegt
außerdem den authentifizierten mobilen Ablauf bei 390 × 844 CSS-Pixeln; ob der
Ton auf dem Zielgerät physisch hörbar ist, bleibt Bestandteil der manuellen
Abnahme.

Interne PHP-Controller, Spracharrays, `*.inc.php` und der vollständige
`/4fbak/`-Baum sind keine direkten Webendpunkte und werden von Apache mit
HTTP 403 abgewiesen. Die öffentlich benötigten Bildbutton-Helfer akzeptieren
nur feste Typen, Farben und begrenzte skalare Maße beziehungsweise Texte;
gültige Antworten sind PNG, ungültige Parameter liefern HTTP 400. Details und
automatisierter Nachweis stehen unter
[Architektur und Sicherheitsentscheidungen](docs/ARCHITEKTUR-UND-SICHERHEIT.md)
und [Tests, Funktionsnachweis und Monitoring](docs/TESTS-UND-MONITORING.md).

Die aktive Nachrichtenablage speichert freie Texte als rohes UTF-8 und nutzt
Prepared Statements. Detail-, Status-, Sichtungs-, Transport-, Sperr- und
Logout-Aktionen sind POST-/CSRF-gebunden und werden zusätzlich gegen Rolle,
ausgewählte aktive Dienstbesetzung, Empfänger, Objektstatus und gegebenenfalls
Sperrinhaber geprüft. Normale Stab-/FB-Funktionen lesen nur eine terminale
Empfängerkopie oder ihren eigenen Ausgang. Si, LdF und A/W lesen nur ihre
aktuelle Warteschlange beziehungsweise Sperre oder Nachrichten mit ihrer
eigenen unveränderlichen Bearbeitungsmarke. Ein eigener MariaDB-Paralleltest
deckt Nummernvergabe, Admin-/Writer-Zähler-Lock, konkurrierendes Save/Reset und
deduplizierte Read-/Done-Zustände ab.

## Persistente Daten

Compose verwaltet drei fachliche Datenvolumes und zusätzlich das nur für den
abgeleiteten Admin-Hash verwendete Auth-Volume:

| Volume | Containerpfad | Inhalt |
| --- | --- | --- |
| `estab_db` | `/var/lib/mysql` | MariaDB-Datenbestand |
| `estab_data` | `/var/www/html/4fdata` | Anhänge und erzeugte Vordrucke |
| `estab_export` | `/var/lib/estab/export` | im Administrationsbereich manuell verwaltete Tabellenexporte |
| `estab_auth` | `/var/lib/estab/auth` im Initialisierer, `/run/estab-auth` in der App | aus dem Admin-Secret abgeleitete bcrypt-Datei |

`podman compose down` behält alle vier Volumes. `podman compose down --volumes`
löscht sie; insbesondere die drei fachlichen Datenvolumes dürfen nur nach
einem geprüften Backup oder für einen ausdrücklich wegwerfbaren Test-Stack
entfernt werden. `estab_auth` ist aus dem geschützten Admin-Secret
reproduzierbar und gehört nicht zum fachlichen Vollbackup.

Generierte einzelne Nachrichtenvordrucke liegen kollisionsfrei als
`<datenbank> Einsatz-<einsatz_id> <nummer> <E|A>.pdf` in `estab_data`.
Liste und Download verlangen eine ausgewählte aktive Dienstfunktion und
autorisieren den Vordruck über deren Leserecht am gedruckten
Nachrichtendatensatz des aktiven Einsatzes; ein bloß im Volume vorhandener
Dateiname genügt nicht. Verknüpfte Anhänge übernehmen dieselbe Objektgrenze
über vollständige, semikolongetrennte Dateinamens-Tokens. Freie Anhänge bleiben
auf den Uploader sowie S2, Si und LdF begrenzt; Auswahl und endgültiges
Nachrichtenspeichern prüfen die Berechtigung erneut.
Einzelvordruck und Nachrichtenseiten des PDF-Einsatzdossiers verwenden
denselben A4-Formularrenderer. Die Vorlage enthält weder eine
VS-NfD-Kennzeichnung noch das frühere Wappen. Neue Anhänge eines Dossiers
werden beim Eingang an SHA-256 und Bytezahl gebunden und vor dem Einbetten
erneut geprüft; beim Upgrade vorhandene Legacy-Dateien heißen ausdrücklich
„Integrität beim Eingang nicht belegbar“. Ist eine historische
Empfängerfunktion in der heutigen Matrix nicht mehr vorhanden, bleibt sie mit
ihrem gespeicherten Kopiekennzeichen ausdrücklich im Inhaltsbereich sichtbar.
Führungsstellenname, Einsatzkennung und Einsatzname erscheinen getrennt in
Statusanzeige, Exportauswahl und PDF-Dossier. Bei einem vor Migration 97
angelegten Einsatz ohne bestätigten Führungsstellennamen erfindet der
PDF-Export keinen Ersatz, sondern kennzeichnet ihn ausdrücklich als
„historisch nicht erfasst“.

## Dokumentation

- [Abschluss-Audit vom 31. Juli 2026](docs/ABSCHLUSS-AUDIT.md)
- [Betrieb und Konfiguration](docs/BETRIEB.md)
- [Pull-only Registry- und Synology-Deployment](deploy/registry/README.md)
- [Migration und Upgrade](docs/MIGRATION-UND-UPGRADE.md)
- [Backup und Wiederherstellung](docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
- [Einsätze und Datenzuordnung](docs/EINSAETZE-UND-DATENZUORDNUNG.md)
- [PDF-Einsatzdossier](docs/PDF-EINSATZDOSSIER.md)
- [Benutzerverwaltung](docs/BENUTZERVERWALTUNG.md)
- [Tests, Funktionsnachweis und Monitoring](docs/TESTS-UND-MONITORING.md)
- [Echter Browser-Akzeptanztest](tests/browser/README.md)
- [Funktionsmatrix und Freigabeprotokoll](docs/FUNKTIONSNACHWEIS.md)
- [Architektur und Sicherheitsentscheidungen](docs/ARCHITEKTUR-UND-SICHERHEIT.md)
- [Nachweis der SVN- und Release-Migration](migration/README.md)
- [Index der unverändert übernommenen Originaldokumentation](docs/legacy/README.md)
- [Anwendungshandbuch Version 1.1 von 2011](doku/Handbuch_eStab.pdf)

Die historische Dokumentation erklärt die Fachbedienung, beschreibt jedoch
veraltete XAMPP-, MySQL- und Web-Installer-Verfahren. Für Installation,
Sicherheit, Backup und Upgrade gelten ausschließlich die heutigen Runbooks
unter `docs/`.

## Lizenzhinweis

Die offizielle [SourceForge-Projektseite](https://sourceforge.net/projects/estab/)
führt eStab als GNU General Public License Version 3.0. In den importierten
SVN- und Release-Bäumen war keine eigenständige Lizenztextdatei enthalten;
dieses Repository erfindet deshalb keine darüber hinausgehende
Lizenzformulierung. Der Laufzeitbestand enthält außerdem historische
Schriften, Grafiken, Bibliotheken und Dokumente mit noch nicht vollständig
inventarisierten Drittanbieterhinweisen. Vor einer öffentlichen
Quell-/Binärweiterverteilung müssen Hauptlizenz, Drittanbieterzuordnung und
Weiterverteilungsrechte mit den ursprünglichen Rechteinhabern geklärt,
unklare nicht benötigte Assets ausgeschlossen beziehungsweise benötigte Assets
ersetzt und die erforderlichen Lizenztexte/Notices ergänzt werden. Der
vorbereitete GHCR-Workflow ist bis zu dieser Prüfung bewusst mehrstufig
gesperrt und verlangt zusätzlich die daraus hervorgehenden Dateien `LICENSE`
und `THIRD_PARTY_NOTICES.md`; das ist eine technische Risikogrenze und keine
Rechtsberatung.

## Herkunft

Die Anwendungshistorie geht bytegenau auf das SourceForge-SVN bis zum
Repository-Ende r85 zurück; der letzte Trunk-Inhalt wurde in r84 geändert,
während r85 nur die Repository-Struktur betraf. Vier historische
Entwicklungszweige und sechs SVN-Tags wurden erhalten. Die später
veröffentlichten Archive 0.9.26b und 0.9.26c sind als geprüfte
Snapshot-Commits und annotierte Git-Tags dokumentiert.

Die separat versionierten 95 Originaldokumente waren nie Teil des Trunks. Ihr
vollständiger r85-Endbestand liegt unverändert unter
`docs/legacy/svn-r85/`; eine nicht belegte Dokument-Einzelcommithistorie wird
nicht erfunden. Prüfsummen, Ref-/Tree-Identitäten, deterministische
Unicode-sichere Dateimanifeste und SVN-Properties liegen unter `migration/`.
Historische `svn:ignore`-Werte bleiben dort dokumentarisch vollständig
erhalten; nur heute passende Regeln wurden selektiv und Git-gerecht in
`.gitignore` übernommen. Die netzlose CI-Prüfung
`python3 migration/verify_provenance.py --self-test` vergleicht Trunk, vier
Branches, sechs SVN-Tags, beide SourceForge-Release-Tags und den
Dokument-Endbestand. Das sind 13 Git-Ref-Snapshots und ein Dokumentbaum. Die
Prüfung beweist zusätzlich, dass neu versiegelte Inhaltsmanipulationen am
SVN-Trunk und an einem späteren Release erkannt werden. Für 0.9.26b/c bindet
sie die beim ursprünglichen Download aufgezeichnete Archiv-SHA-256 aus
`sourceforge-releases.tsv` an annotierten Tag, Snapshot-Commit und Git-Inhalt.
CI lädt die externen Archive nicht erneut herunter und weist daher die
gebundene aufgezeichnete Archividentität, nicht die gegenwärtige
SourceForge-Auslieferung nach.
