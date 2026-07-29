# eStab

Dieses Repository bewahrt den vollständigen historischen eStab-Verlauf und
stellt den letzten veröffentlichten Stand als überprüfbaren
PHP-/MariaDB-Container bereit. Die fachlichen Module für Nachrichtenvordruck,
Sichtung, Anhänge, Kategorien, Einsatztagebuch, technisches Betriebsbuch und
PDF-Vordrucke bleiben erhalten; die Laufzeit wurde für PHP 8.5 und MariaDB 11.8
kompatibel gemacht.

Der Container ist standardmäßig nur über `127.0.0.1:8080` erreichbar. Für einen
Zugriff aus anderen Netzen gehört ein TLS-terminierender Reverse Proxy davor.
eStab enthält weiterhin umfangreichen Legacy-Code und sollte nicht ungeprüft
direkt ins Internet gestellt werden.

## Schnellstart für eine neue Installation

Vorausgesetzt werden Docker mit Compose v2 oder Podman mit einem
Compose-Provider sowie `openssl` und `curl`. Der Schnellstart setzt
`engine=podman`; für Docker wird dort nur `engine=docker` gewählt.

```console
cp .env.example .env
install -d -m 0700 secrets
openssl rand -base64 36 > secrets/db_password.txt
openssl rand -base64 36 > secrets/db_root_password.txt
openssl rand -base64 36 > secrets/admin_password.txt
chmod 0600 secrets/*.txt
```

Danach die organisations- und netzbezogenen Werte in `.env` prüfen und den
Stack starten:

```console
engine=podman
compose() { "$engine" compose "$@"; }

compose config >/dev/null
compose build --pull migrate app
compose up -d

estab_diagnostics()
{
    printf 'eStab ist nicht bereit: %s\n' "$1" >&2
    compose ps --all >&2 || true
    compose logs --tail 100 db migrate app >&2 || true
}

wait_for_estab()
{
    estab_deadline=$(( $(date +%s) + 300 ))
    while :; do
        if estab_health=$(curl --fail --silent --max-time 5 \
            http://127.0.0.1:8080/health.php 2>/dev/null) &&
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

        estab_id=$(compose ps --all -q migrate 2>/dev/null || true)
        if [ -n "$estab_id" ]; then
            estab_state=$("$engine" inspect --format \
                '{{.State.Status}} {{.State.ExitCode}}' "$estab_id" \
                2>/dev/null || true)
            case "$estab_state" in
                exited\ 0) ;;
                exited\ * | dead\ *)
                    estab_diagnostics \
                        "Service migrate endete als $estab_state"
                    return 1
                    ;;
            esac
        fi

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
vollständigen Schematest aus. `app` darf erst starten, wenn dieser Container
erfolgreich mit Exitcode 0 beendet wurde. Vor dem ersten Start einer neuen
Version auf einem vorhandenen Volume ist deshalb immer ein Vollbackup Pflicht.

Die Schleife wartet höchstens fünf Minuten, bricht bei einem klar
fehlgeschlagenen Datenbank-, Migrator- oder App-Service vorzeitig mit Status
und den letzten Logs ab und benötigt kein providerabhängiges Compose-`--wait`.
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

Eine Neuinstallation beginnt absichtlich ohne aktiven Einsatz. Dadurch sind
Anmeldung und Administration erreichbar, operative Eingaben aber
fail-closed gesperrt. Als erster fachlicher Schritt wird unter
`/4fadm/incidents.php` ein Einsatz angelegt und aktiviert; Kennung und Name
müssen danach in der globalen Statusleiste erscheinen. Funktionskonten lassen
sich unter `/4fadm/users.php` sperren, entsperren und mit einem neuen Kennwort
versehen. Ein vollständiges ETB-/TTB-/Nachrichten-/Anhang-Dossier für einen
aktiven oder historischen Einsatz erzeugt `/4fadm/incident_export.php`.

### Ohne lokalen Image-Build

Für Docker, Podman und Synology Container Manager ist ein eigenständiges
[pull-only-Deploymentpaket](deploy/registry/README.md) vorbereitet. Es
referenziert ein gemeinsam versioniertes App-/Migrator-Imagepaar für
`linux/amd64` und `linux/arm64`; der Migrator trägt das Basisschema selbst, so
dass keine SQL-Datei aus einem Git-Checkout auf das Zielgerät gemountet wird.

Im Repository ist noch kein freigegebener Image-Stand mit Manifest-Digests
dokumentiert. Der Publish-Workflow ist manuell, akzeptiert ausschließlich
einen gleichnamigen vorhandenen Git-/OCI-Tag und pusht jeden Dockerfile-Build
genau einmal unter einem ausdrücklich nicht finalen Candidate-Tag. Native
`amd64`- und `arm64`-Jobs ziehen danach die exakten Candidate-Index-Digests und
führen damit Fresh-/Restore-CI, Attestations-, SBOM-/Provenance- und
High-/Critical-CVE-Gates aus; der echte Browsertest ist auf `amd64`
verpflichtend. Erst wenn beide Architekturen grün sind, erzeugt ein separater
Job ein verstecktes Draft-Release, lädt beide Paketdateien hoch und wieder
herunter, prüft ihre Checksummen, promotet genau die getesteten Digests ohne
Rebuild und macht das Draft-Release ganz zuletzt sichtbar. Der Ablauf bleibt
zusätzlich durch Rechtebestätigung, zwei Repositoryvariablen und ein zwingend
mit Required Reviewer geschütztes GitHub-Environment gesperrt.

Candidate-Tags, ein verstecktes Draft-Release oder nur teilweise vorhandene
Finaltags sind kein Release und dürfen nicht installiert werden. Verwendbar
sind nur die beiden Digests aus einem sichtbaren, vollständig geprüften
GitHub-Releasepaket mit `compose.yaml`, gebundener `.env.example`, Runbooks und
atomarem Backup-Helfer sowie rein lesendem Backup-Verifier.
Der [Recovery-Ablauf](deploy/registry/README.md#unvollständigen-publish-lauf-behandeln)
berücksichtigt, dass zwei GHCR-Repositories und GitHub Releases nicht atomar
gemeinsam geändert werden können. Einen `latest`-Pfad gibt es absichtlich
nicht.

## Zugänge

| Pfad | Zweck | Schutz |
| --- | --- | --- |
| `/` | Einstieg, direkter Anmeldebutton und Modulübersicht | öffentlich bis zur Modulanmeldung |
| `/4fach/index.php` | öffentlicher Einstieg in die vollständige Anwendung mit Kontoauswahl und Benutzeranmeldung | Fachfunktionen erst mit eStab-Sitzung |
| `/4fach/logout.php` | zentrale Abmeldung aus Sitzungsleiste und Nachrichtenarbeitsbereich | eStab-Sitzung, ausschließlich POST mit Session-CSRF |
| `/4fach/status.php` | eigenständige Statusansicht für Kompatibilitätsaufrufe | anonym nur neutraler Hinweis, Rollenbelegung erst mit eStab-Sitzung |
| `/4fach/katgoedt.php` | globale, Funktions- und persönliche Kategorien | eStab-Sitzung, Rollen-/Objektprüfung und CSRF für Änderungen |
| `/4fadm/admin.php` | Administration | separates HTTP Basic Auth |
| `/4fadm/incidents.php` | Einsätze anlegen, aktivieren und deaktivieren | HTTP Basic Auth, Session-CSRF und revisionsgesicherter globaler Status |
| `/4fadm/users.php` | Benutzer anlegen, Funktionen fest zuweisen, sperren/entsperren und Kennwörter zurücksetzen | HTTP Basic Auth, Session-CSRF; Rollen werden serverseitig abgeleitet und aktive Sitzungen atomar widerrufen |
| `/4fadm/incident_export.php` | ETB, TTB, Nachrichtenvordrucke und Originalanhänge eines Einsatzes als PDF-Dossier | HTTP Basic Auth, Session-CSRF, einsatzgebundene Abfragen und protokollierte SHA-256 |
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

Mit `ESTAB_REVIEW_OUTGOING_MESSAGES=false` bleibt das veröffentlichte
Standardverhalten erhalten: Nur Eingänge gehen nach dem Fernmelder zum
Sichter; transportierte Ausgänge werden direkt abgeschlossen. Der strikt
geparste Wert `true` aktiviert auch für Ausgänge den vollständigen Pfad
Stab → A/W → Sichter. Nach einer Änderung muss der App-Container neu erzeugt
und der Rollen-Workflow fachlich abgenommen werden.

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
akzeptiert. Die Administration ist als eigener technischer Zugang markiert.

Eine gemeinsame Navigation führt in stabiler Reihenfolge durch Übersicht,
Nachrichtenvordruck, Meldungsübersicht, Vordrucke, ETB, TTB, Nachweisung und
BOS-Info. Sie markiert den aktiven Bereich und öffnet interne Ziele immer im
selben Browserkontext. Der Nachrichtenarbeitsbereich besteht aus genau zwei
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

Alle eigenständigen Fach- und Administrationsseiten verwenden darüber hinaus
dasselbe responsive Werkzeuggestell: ein klarer Seitenkopf, Status- und
Hinweisflächen, beschriftete Formularfelder, mindestens 44 Pixel große
Aktionen sowie eine eindeutige Rücknavigation. Dazu gehören insbesondere
Meldungsübersicht, Nachweisung, ETB, TTB, Vordrucke, Kategorienverwaltung,
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
meldet die Erholung. Direkt geöffnete Status-/Zählerseiten erhalten weiterhin
eine kompakte Anzeige. Formulare mit
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
Empfänger, Objektstatus und gegebenenfalls Sperrinhaber geprüft. Ein eigener
MariaDB-Paralleltest deckt Nummernvergabe, Admin-/Writer-Zähler-Lock,
konkurrierendes Save/Reset und deduplizierte Read-/Done-Zustände ab.

## Persistente Daten

Compose verwaltet drei benannte Volumes:

| Volume | Containerpfad | Inhalt |
| --- | --- | --- |
| `estab_db` | `/var/lib/mysql` | MariaDB-Datenbestand |
| `estab_data` | `/var/www/html/4fdata` | Anhänge und erzeugte Vordrucke |
| `estab_export` | `/var/lib/estab/export` | im Administrationsbereich manuell verwaltete Tabellenexporte |

`podman compose down` behält diese Volumes. `podman compose down --volumes`
löscht sie und darf nur nach einem geprüften Backup oder für einen ausdrücklich
wegwerfbaren Test-Stack verwendet werden.

Generierte einzelne Nachrichtenvordrucke liegen kollisionsfrei als
`<datenbank> Einsatz-<einsatz_id> <nummer> <E|A>.pdf` in `estab_data`.
Liste und Download autorisieren sie über den gedruckten Nachrichtendatensatz
des aktiven Einsatzes; ein bloß im Volume vorhandener Dateiname genügt nicht.

## Dokumentation

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
gesperrt; das ist eine technische Risikogrenze und keine Rechtsberatung.

## Herkunft

Der Git-Verlauf geht bytegenau auf das SourceForge-SVN bis r85 zurück. Vier
historische Entwicklungszweige und sechs SVN-Tags wurden erhalten; die später
veröffentlichten Archive 0.9.26b und 0.9.26c sind als geprüfte Snapshot-Commits
und annotierte Git-Tags dokumentiert. Prüfsummen, Ref-Vergleiche und
SVN-Properties liegen unter `migration/`. Die separat versionierten 95
Originaldokumente liegen unverändert unter `docs/legacy/svn-r85/`.
