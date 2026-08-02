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

Eine Neuinstallation beginnt absichtlich ohne aktiven Einsatz. Anmeldung und
Administration bleiben erreichbar, operative Eingaben sind aber fail-closed
gesperrt. Eine Dienst- oder Zugangsschicht ist dagegen **keine fachliche
Schreibvoraussetzung**. Die erste Einrichtung erfolgt in dieser Reihenfolge:

Der in eStab geprüfte und unterstützte Produktumfang ist eine Führungsstelle
**mit eingerichteter Fernmeldebetriebsstelle**. Deshalb müssen persönliche
Konten mit den festen Funktionen LdF und Fernmelder vorhanden sein; eine
Schichtannahme ist nicht erforderlich. Je Einsatz wird genau ein TBB geführt. Eine
Führungsstelle ohne eigene Fernmeldebetriebsstelle, insbesondere ein reiner
ETB-Betrieb, gehört derzeit nicht zum unterstützten Produktumfang. Diese
Produktgrenze ist keine formale THW-Freigabe; deren gesonderter Vorbehalt ist
unten dokumentiert.

1. Unter `/4fadm/incidents.php` einen Einsatz mit Kennung, genauer
   Einsatzbezeichnung, Beginn, **Bedarfsträger**, **Namen der
   Führungsstelle**, verantwortlicher Einsatz-/Führungsleitung sowie
   Einsatzauftrag und Ausgangslage anlegen, den Berechtigungsmodus wählen und
   den Einsatz aktivieren. **Streng** ist der sichere Standard und erzwingt
   die bisherigen funktions- und rollenbezogenen Schreibrechte. **Locker**
   darf nur nach einer ausdrücklichen Warnungsbestätigung gewählt werden und
   lockert ausschließlich die festen Funktions-/Rollengates ausdrücklich
   angebotener Schreibschritte in Nachrichtenworkflow, ETB/TBB, S6-Planung
   und Melderlauf. Der
   Führungsstellenname ist die lokale Anschrift/Absendereinheit und darf nicht
   mit Einsatzname, Bedarfsträger oder Einsatzleitung verwechselt werden.
   Die Datenbank legt dabei bereits genau zwei leere, bei 1 beginnende
   Nummernköpfe an: einen für ETB und einen für TBB.
2. Unter `/4fadm/users.php` persönliche Konten mit genau einer festen Funktion
   anlegen. Im strengen Modus bestimmen diese Kontofunktion und die daraus
   serverseitig abgeleitete Rolle die fachlichen Schreibrechte; eine
   Funktions- oder Hutauswahl nach der Anmeldung gibt es in keinem Modus.
3. Optional unter `/4fadm/fuehrungsstelle.php` einsatzgebundene
   **Zugangsschichten** anlegen und Konten zuordnen. Damit lassen sich allein
   Kontozugang und laufende Sitzungen einer Gruppe gemeinsam steuern, nicht
   deren Fachrechte. Unzugeordnete Konten bleiben zugelassen; bei mehreren
   Zuordnungen genügt für den Kontozugang eine aktive Gruppe.
4. Die Personen melden sich mit ihren vorhandenen Konten an. Im empfohlenen
   strengen Regelbetrieb schreiben Konten mit `ETB/Stab` oder `S2/Stab` das
   ETB; das TTB führen Konten mit der festen Funktion `Fernmelder`.
5. Im strengen Regelbetrieb erstellt S6 einmalig den ersten Fernmeldeplan mit
   den vorgesehenen Wegen und veröffentlicht ihn. Für jede spätere Änderung
   erzeugt **Bearbeitung starten** eine vollständig vorbefüllte Kopie der
   aktiven Fassung. Kopfdaten und einzelne Wege lassen sich im Entwurf ändern,
   ergänzen oder entfernen; der bisherige Plan bleibt bis **Als Version … aktiv
   schalten** unverändert gültig. Freigegeben wird nur, wenn der aktuelle
   Datenbankzeitpunkt innerhalb des im Entwurf gespeicherten Gültigkeitsfensters
   liegt; andernfalls bleibt die bisherige Fassung aktiv und der Entwurf kann
   korrigiert werden. Anlage und jede Kopfdatenänderung werden mit initialem
   Zustand beziehungsweise Vorher-/Nachher-Snapshot in der einsatzgebundenen
   Auditkette nachgewiesen. Sichtbare, noch nicht gespeicherte Änderungen
   blockieren die Aktivierung und jede andere Aktion, die diese Browserwerte
   durch einen Seitenwechsel verlieren würde. Der Hinweis führt zum betroffenen
   Bereich. Sind mehrere Teilformulare geändert, kann die ursprünglich gewählte
   Aktion nur über die ausdrücklich als Datenverlust gekennzeichnete Schaltfläche
   fortgesetzt werden; ein stilles Verwerfen findet nicht statt. Ersetzte und
   verworfene Fassungen bleiben mit vollständigen Kopf- und Wegeangaben in der
   nur lesbaren Versionshistorie sichtbar. Ein nicht mehr benötigter oder
   veralteter Entwurf kann bewusst verworfen werden; er bleibt mit seinen Wegen
   unveränderlich nachgewiesen, blockiert aber keine neue Bearbeitung der aktiven
   Fassung. Die Medien werden als Fernsprecher, Funk,
   Melder, Telefax, Fernschreiber und Datenübertragung ausgeschrieben. Kanal
   beziehungsweise Rufgruppe und Bandlage erscheinen nur bei Funk; eine
   Verkehrsform oder besondere Behandlung gehört zu jedem Weg. Erst nach der
   Veröffentlichung kann die LdF-Stufe einen Ausgang auf den neuen
   verbindlichen Weg disponieren. Im lockeren Modus dürfen andere gültige
   Konten diese ausdrücklich angebotenen Schreibstufen übernehmen; Plan- und
   Workflowzustand bleiben unverändert verbindlich.

Der Modus gehört zum Einsatz und ist weder eine `.env`-Option noch eine
Eigenschaft des Kontos oder einer Zugangsschicht. Bestehende Einsätze erhalten
beim Upgrade **Streng**; neue Einsätze sind ebenfalls darauf voreingestellt.
Die Administration kann einen noch offenen Einsatz revisionsgesichert
umstellen. Der Wechsel nach **Locker** verlangt eine zusätzliche Bestätigung
und jeder echte Wechsel wird mit altem und neuem Modus auditiert. Die
Statusleiste zeigt den wirksamen Modus, damit eine gelockerte fachliche
Aufgabentrennung nicht unbemerkt bleibt.

Auch im lockeren Modus muss jede schreibende Person mit ihrem konkreten,
aktiven und ungesperrten Konto angemeldet sein. Ein gegebenenfalls durch eine
deaktivierte Zugangsschicht entzogener Zugang bleibt entzogen. Ebenso bleiben
aktiver offener Einsatz, Einsatzzuordnung, CSRF- und Eingabevalidierung,
Workflowzustände, Sperrinhaber, unveränderliche Nachweise, Anhangintegrität,
Audit und Aufbewahrung verbindlich. Der Modus erteilt keinen anonymen Zugriff,
ändert keine gespeicherte Funktion/Rolle und erweitert die allgemeinen
Leserechte nicht. Rollenstrenge reine Übersichten, Nachweisung,
Zweitsichtungsarchive, Kategorienverwaltung und administrative Rechte bleiben
auch in **Locker** unverändert. Nur für eine ausdrücklich gewählte schreibende
Workflowstufe erhält das Konto die dafür erforderliche Objektsicht. Eine
zurückgewiesene Ausgangsmeldung darf dann auch von einer anderen Funktion
übernommen werden; Ereignisnachweis und Fachdaten bewahren ursprüngliche und
neu verantwortliche Funktion. Status, Richtung und Sperrinhaber bleiben
verbindlich. Für den fachlichen Regelbetrieb wird **Streng** empfohlen;
**Locker** ist eine bewusst zu verantwortende Einsatzentscheidung.

Migration 97 lässt Führungsstellennamen bereits vorhandener Einsätze bewusst
`NULL`, statt einen Wert aus Einsatzname, Bedarfsträger, Einsatzleitung oder
Umgebung zu erfinden. Ein offener Alt-Einsatz muss vor Aktivierung oder
weiteren operativen Eingaben einmalig unter `/4fadm/incidents.php` mit dem
tatsächlichen Namen bestätigt werden. Diese Erstbestätigung ist auch bei
vorhandenen historischen Fachdaten möglich. Ein schon belegter Name kann nur
bis zur ersten operativen Eintragung korrigiert werden und ist danach
unveränderlich; dafür speichert der Einsatz einen dauerhaften
Erstschreib-Sperrmarker. Auch das spätere Löschen einzelner Fachdaten hebt
diese Sperre nicht auf. Formal abgeschlossene Alt-Einsätze bleiben
unverändert.

Zugangsschichten verändern keine Funktion und keine Rolle. Das Aktivieren
einer Gruppe meldet niemanden an; das Deaktivieren widerruft die Sitzungen der
zugeordneten Konten, sofern sie nicht zugleich einer anderen aktiven Gruppe
angehören. Die dauerhafte manuelle Kontosperre ist davon unabhängig und hat
immer Vorrang. Vor einer Deaktivierung zeigt die Administration die nach dem
aktuellen Stand betroffenen Zugänge und Sitzungen. Ändert sich bis zum
Bestätigen irgendein Schichtstatus, eine aktuelle Zuordnung oder der relevante
Konto-/Sitzungszustand, wird die alte Bestätigung verworfen. Auch das Entfernen
einer Zuordnung ist an genau ihr unveränderliches Zuordnungsintervall gebunden.
Historische formale Dienstschichten, Besetzungen und Übergaben
bleiben als exportierbare Evidenz erhalten, steuern aber weder Anmeldung noch
Fachrechte und sperren keine Eingabe.

Führungsstellenname, Einsatz, Berechtigungsmodus und feste Kontofunktion müssen
in Statusleiste beziehungsweise Führungsstellenansicht eindeutig erkennbar
sein. Ohne
gültigen Führungsstellennamen oder aktiven Einsatz nimmt eStab keine operative
Eingabe an. Funktionskonten lassen sich unter `/4fadm/users.php` sperren, entsperren
und mit einem neuen Kennwort versehen. Unter
`/4fadm/self_registration.php` kann die öffentliche Kontoanlage sofort
deaktiviert, dauerhaft aktiviert oder ab jetzt für 15 Minuten bis 24 Stunden
freigegeben werden. Eine befristete Freigabe endet anhand der Datenbankzeit
automatisch; auch ein vorher geöffnetes Formular wird beim Absenden erneut
geprüft. Aktivierungen verlangen die Bestätigung, dass die Anmeldeseite nur
in einem kontrollierten Netz und unter Aufsicht erreichbar ist, denn
erreichbare Personen können jede angebotene aktive Funktion auswählen.
Bestehende Konten und Sitzungen bleiben dabei unverändert. Unter
`/4fadm/password_policy.php` legt die Administration zentral fest, welche
Anforderungen für künftig gesetzte Kennwörter gelten: eine Mindestlänge von 8
bis 128 Unicode-Codepoints (Standard 12) sowie optional je mindestens ein
Unicode-Groß- oder Titlecase-Buchstabe, Unicode-Kleinbuchstabe, eine
Unicode-Ziffer und ein Sonderzeichen. Unicode-Steuerzeichen sind verboten;
Formatzeichen wie der ZWJ in Emoji-Sequenzen bleiben zulässig. Die Änderung betrifft Kontoanlage, Kennwortreset und eine
gegebenenfalls aktivierte Selbstregistrierung. Bestehende Kennwörter bleiben
anmeldbar. Klartextwerte und andere eindeutig verifizierbare Alt-Hashes werden
nach erfolgreichem Login auf Argon2id umgestellt. Ein bcrypt-Hash wird nur bei
einem eingegebenen Kennwort unter 72 UTF-8-Bytes automatisch migriert. Ab 72
Bytes bleibt er wegen der bcrypt-Suffixambiguität unverändert; für Argon2id ist
dann ein administrativer Kennwortreset erforderlich. Bereits stärkere oder
gemischte Argon2id-Kosten werden nie auf die Standardkosten zurückgestuft; nur
in allen Kostenparametern höchstens gleich starke und insgesamt schwächere
Profile werden hochgestuft. Sitzungen und das getrennte
HTTP-Basic-Administrationskennwort bleiben unverändert. Ein vollständiges
ETB-/TBB-/Nachrichten-/Nachweis-/Dienst-/S6-/Melder-/Anhang-Dossier für einen
aktiven oder historischen Einsatz erzeugt `/4fadm/incident_export.php`.
Die Anlagensektion zeigt JPEG, PNG, GIF und BMP direkt (bei animierten GIFs die
erste Bildebene) und rastert mehrseitige PDFs einschließlich ihrer Anmerkungen
seitenweise. Text erscheint nur, wenn
er sich verlustfrei mit dem Windows-1252-Basiszeichensatz darstellen lässt;
standardisierte E-Mail-Dateien im RFC-822-Format (`.eml`) erscheinen innerhalb
der PDF-Textgrenzen als passiv gerenderter Text ohne aktive HTML-Inhalte oder
nachgeladene Fremdressourcen. Für nicht verlustfrei darstellbare E-Mails sowie
TIFF, ZIP, Office, Video und andere nicht statisch darstellbare Inhalte
erscheint eine eindeutige Hinweisseite. Unabhängig von dieser Vorschau bleibt
jede Originaldatei bytegleich als PDF-Anlage eingebettet.
Die ETB-Seiten entsprechen der Struktur **Fb Fü 2** auf A4 hoch, die
TBB-Seiten **Fb Fü 44** auf A4 quer; Formkopf, buchlokale Seitenzahlen und
vorgesehene manuelle Unterschriftslinien werden auf jeder Seite wiederholt.
Bei neuen
strukturierten TBB-Zeilen wird nur die redundante Zusammenfassung aus
`tbb_aktion` unterdrückt; die eigenständige Bemerkung aus `tbb_bemerk`
erscheint genau einmal in der Betriebsspalte. Bei formal
geschlossenen Büchern wird der unbeschriebene Rest der letzten Formularseite
diagonal gestrichen; offene vorläufige Abzüge bleiben fortführbar. Der formale
Einsatzabschluss erzeugt die letzten Buchzeilen und setzt eine
Mindestaufbewahrung von zehn Jahren. Fehlende oder noch offene historische
Dienstschichten und fehlende schichtbezogene Eröffnungszeilen blockieren den
formalen Einsatzabschluss nicht.
Bei einem neuen Einsatz erzeugt bereits dessen Aktivierung die ersten ETB- und
TTB-Zeilen atomar und ohne Schichtbezug. Vorhandene Legacy-Bücher werden beim
Upgrade oder erneuten Aktivieren nicht umsortiert und nicht rückwirkend ergänzt.
Für operative Daten genügt eine Kontoanmeldung nicht: Zusätzlich muss ein
aktiver Einsatz bestehen. Jeder operative Schreibpfad gleicht konkrete
Kontenidentität, Kontosperre, Zugangsschichtwirkung, aktiven Einsatz und dessen
Berechtigungsmodus erneut in der Datenbank ab. Im strengen Modus werden
zusätzlich feste Kontofunktion und serverseitig abgeleitete Rolle erzwungen;
im lockeren Modus sind nur diese funktions-/rollenbezogenen Schreibprüfungen
für Nachrichtworkflow, ETB/TBB, S6-Planung und Melderlauf gelockert. Eine
optionale Zugangsschicht kann einen
zugeordneten Zugang gemeinsam entziehen, ist aber keine Eingabevoraussetzung.
Diese Grenze gilt auch für ETB, TBB, Kategorien, Nachrichten, Vordrucke und
Anhänge. Unter `/4fach/vordrucke.php` erscheinen nur abgeschlossene
Nachrichten des aktiven Einsatzes, die das angemeldete Konto nach der
Nachrichten-Objektregel lesen darf. Der aktuelle Abzug
verwendet das mit dem Dossier gemeinsame PDF-Layout. Die beim
Nachrichtenabschluss atomar gespeicherte Archivdatei wird dabei weder ersetzt
noch verändert und bleibt Bestandteil von Backup und Restore.
Neue Anlagen werden direkt im offenen Nachrichtenvordruck ausgewählt,
beschrieben, hochgeladen und diesem zugeordnet; der separate Anlagenbereich
ist nur noch als optionale Auswahl bereits hochgeladener Einsatzdateien
erforderlich. Formularkopf, Anlagenkarten sowie Meldungsübersicht und zweite
Sichtung machen Anzahl und Zuordnung unmittelbar durch Zähler-Badges sichtbar.
Die Anlagenkarten liegen unterhalb des amtlichen Blatts. Echte JPEG-, PNG-,
GIF- und BMP-Bilder erhalten bis 16 Megapixel und 24 MiB eine automatisch
erzeugte Miniatur; darüber beziehungsweise bei fehlgeschlagener Dekodierung
erscheint ein Platzhalter. PDF-Dateien werden erst beim ausdrücklichen
Aufklappen in einer Same-Origin-Browseransicht geladen. Nur serverseitig als
JPEG, PNG, GIF, BMP oder PDF erkannte Dateien dürfen inline geöffnet werden;
TIFF und alle übrigen zulässigen Formate bleiben reine Downloads. Jeder Abruf
prüft Berechtigung, MIME-Typ und Integrität erneut. „Vom Vordruck entfernen“
löst nur die Zuordnung im bearbeitbaren Entwurf und löscht die bereits
archivierte Datei nicht.

RFC-822-E-Mails mit der Endung `.eml` werden ebenfalls direkt am Vordruck
hochgeladen. Endung, der serverseitig erkannte Typ `message/rfc822` und die
MIME-Struktur müssen zusammenpassen; Outlook-`.msg` wird nicht unterstützt.
Die Anlagenkarte öffnet `/4fach/email.php` als ausschließlich passive
Textansicht: Mail-HTML, Skripte, Ereignisattribute, Formulare, eingebettete
Objekte und Remote-Ressourcen werden nicht ausgeführt oder nachgeladen.
Enthaltene Mail-Anlagen erscheinen dort nur mit Name, Typ und Größe. Die
angezeigten Kopfzeilen belegen weder Absender noch Authentizität; eStab führt
insbesondere keine DKIM- oder S/MIME-Verifikation durch. Die unveränderte
Originaldatei kann mit gültiger Anmeldung sowie nach erneuter
Objektberechtigungs- und Integritätsprüfung heruntergeladen werden. Sie kann weiterhin gefährliche
Inhalte oder Anlagen enthalten und ist deshalb nur in einer dafür geeigneten
Umgebung zu öffnen.

Der Upload arbeitet zweiphasig. Zuerst werden ein interner Name und die
Dateiendung als nicht lesbare, inhabergebundene Reservierung gespeichert;
anschließend werden die Bytes ohne langen Einsatz-Lock verschoben und gehasht.
Erst eine zweite kurze Einsatztransaktion beansprucht die Reservierung und
schaltet Datei, Metadaten, SHA-256, Bytezahl und Audit gemeinsam sichtbar. Bei
einem regulär behandelten Fehler wird der Datenbankstatus über eine neue
Verbindung erneut gelesen. Eine bereits finalisierte Datei wird nie gelöscht;
nur eine eigene unfertige Status-8-Reservierung darf bereinigt werden. Der
Dienst beansprucht sie unter Zeilensperre atomar als Status 2, bevor ein
validierter Zielpfad gelöscht werden kann. Erst nach bestätigtem Fehlen der
Bytes wird der interne Name als Status 4 freigegeben. Damit kann kein anderer
Upload die Zeile zwischen Prüfung und `unlink` wiederverwenden oder
finalisieren. Ein vor dieser Beanspruchung unklarer Zustand bleibt unverändert
fail-closed. Ein harter Abbruch danach oder fehlgeschlagenes Löschen lässt die
unsichtbare Status-2-Cleanup-Zeile gesperrt.

Jede Formularaktion trägt ein einmaliges, an Konto, Einsatz,
Bearbeitungsart und bei Korrekturen an den Datensatz gebundenes Token. Vor dem
Nachrichtenspeichern wird der Zwischenstand der Sitzung dauerhaft geschrieben.
Der Server speichert zusätzlich nur den SHA-256-Hash des Tokens im
unveränderlichen Nachrichtenereignis desselben Datenbank-Commits. Ein
tokenbezogener MariaDB-Advisory-Lock serialisiert Nachweisprüfung und
Nachrichtenspeicherung; ein Retry nach einem verlorenen Antwortpaket kann damit
den exakten Commit erkennen und erzeugt keine zweite Nachricht. Bei einem noch
nicht belegten Abschluss wird der Entwurf mit Anlage und Prüfanweisung wieder
angezeigt; eine Anlage an einer anderen Nachricht gilt nicht als
Speichernachweis für diesen Text.

Dateisystem, Datenbank und PHP-Sitzung besitzen jedoch keine gemeinsame
Transaktion. Bei einem harten Worker- oder Hostabbruch kann nach dem Verschieben
der Bytes eine unsichtbare Status-8-Reservierung samt Staging-Datei
zurückbleiben, weil die normale Fehlerbereinigung nicht mehr läuft. Ein Abbruch
nach deren atomarer Cleanup-Beanspruchung oder nicht löschbare Bytes können
stattdessen eine verborgene Status-2-Cleanup-Zeile mit oder ohne Staging-Datei
hinterlassen. Im noch engeren Zeitraum nach erfolgreicher
Anlagenfinalisierung, aber vor dem Session-Checkpoint, kann eine bereits
archivierte freie Datei ohne dauerhafte Token-Zuordnung verbleiben; ein Retry
kann dann eine zweite Archivdatei anlegen. Liegt der Nachrichten-Commit samt
unveränderlichem Aktionsnachweis vor, verhindern Advisory-Lock und dieser
persistente Nachweis auch nach einem Workerabbruch eine stille
Doppelnachricht. Die Anlage wird vor der Nachrichtenvalidierung
archiviert; bei einem verworfenen Entwurf bleibt sie deshalb als freie,
berechtigungsgeschützte Archivdatei erhalten. Die Standardgrenze der Anwendung
beträgt 20 MiB je Datei (`ESTAB_UPLOAD_MAX_BYTES`, maximal 50 MiB); der
Container setzt dafür `upload_max_filesize = 50M` und `post_max_size = 56M`.
Für `.eml` gilt unabhängig von einem höher gesetzten globalen Uploadlimit eine
feste Parsergrenze von 20 MiB. Damit bleibt die MIME-Verarbeitung insbesondere
auf kleineren NAS-Systemen begrenzt.
Die sichtbare Anlagendarstellung im PDF-Dossier verwendet eine strengere
12-Megapixel-/8.000-Pixel-Grenze. Die Anlagenzahl erscheint in
Meldungsübersicht, zweiter Sichtung und allen operativen Warteschlangen.

Direkter Upload und Entfernen stehen in den bearbeitbaren Eingangs-,
Stabsschreib-, Korrektur- und Gesprächsnotizvorgängen bereit; spätere
Prüf-/Beförderungsschritte zeigen die Karten lesend. Je Nachricht sind maximal
100 kanonische Anlagenreferenzen zulässig. Ein unvollständig übertragener
Upload erfordert eine erneute Dateiauswahl.

Der integrierte Upload unterstützt unter anderem echte JPEG-Bilder mit `.jpg`
und `.jpeg` sowie strukturell geprüfte RFC-822-E-Mails mit `.eml`, prüft den
MIME-Typ serverseitig und nennt das standardmäßige Uploadlimit von 20 MiB
direkt am Dateifeld. Ein ETB-Eintrag kann optional genau einen bereits
fertig hochgeladenen und noch keinem ETB-Eintrag zugeordneten Einsatzanhang
als Anlage aufnehmen. Beim Speichern wird daraus automatisch die eindeutige
ETB-Anlagennummer `ETB {einsatz_id}-{estab_book_lfd}-1` gebildet. eStab führt
genau ein ETB je Einsatz und behandelt jeden ausgewählten Upload als eine
zusammengehörige digitale Einheit; deshalb ist die letzte Komponente derzeit
immer `1`. Das vorhandene Kennzeichen wie `EL0001` bleibt davon getrennt das
Ablage-/FmZt-Kennzeichen. Anwendungssperre und eindeutiger Datenbankindex
verhindern eine zweite ETB-Zuordnung desselben Anhangs.

Die ETB-Seite besitzt eine kombinierbare Suche: Eine leere Suche zeigt alle
Einträge; Volltext, Art, „Nummer oder Bezug“ sowie „Zuordnung“ lassen sich
einzeln oder gemeinsam verwenden. Der Bezugsfilter findet lokale ETB- und
Korrekturbezüge, Nachrichten-/Anhangs-IDs, kanonische lokale ETB-Nummern und
historische Bestandsreferenzen,
Ablagekennzeichen, gespeicherte Dateinamen und die vollständige
ETB-Anlagennummer. Optional kann eine Bearbeitungszuordnung als Suchhilfe
gespeichert werden; sie erweitert keine Rechte. eStab prüft beim Speichern den
Berechtigungsmodus und ein konkret authentifiziertes, ungesperrtes Konto; im
strengen Modus zusätzlich die feste Kontofunktion. Der reine Online-/Präsenzstatus
und eine Schichtzuordnung sind dafür keine Gültigkeitsmerkmale. eStab friert die lesbare Angabe
`Funktion (Rolle): Name [Kürzel]` im Eintrag ein. Sie erscheint in Webliste und
Suche, aber bewusst nicht im amtlichen Fb-Fü-2-PDF.

Neue ETB-Referenzen folgen Kapitel 2.3.2 der Ausbildungsunterlage: Eingetragen
wird ausschließlich die positive lokale Nummer eines bereits vorhandenen
Eintrags desselben Einsatzes. Freitext, führende Nullen, globale technische
IDs und nicht vorhandene Nummern werden abgewiesen; historischer Freitext
bleibt les- und suchbar, wird aber nicht als nachträglich erfundene Kante
ausgewertet. Eine Korrektur referenziert intern direkt ihr unveränderliches
Original und zeigt nach außen dessen lokale ETB-Nummer.

Die Referenzauswertung verfolgt von einer Startnummer aus wahlweise vorwärts
alle referenzierenden, auch verzweigten Folgeeinträge oder rückwärts den
Bezugspfad. Die Tiefe ist auf 1 bis 25 begrenzt, ein abgeschnittener Pfad wird
sichtbar gemeldet und eine eigene Druckansicht kann geöffnet werden.

Neue ETB-/TBB-Zeilen dürfen die Legacy-Felder für Dienstschicht und
Dienstbesetzung `NULL` lassen. Die Felder werden nicht mit einer
Zugangsschicht befüllt: Sie bewahren ausschließlich belegte historische
Provenienz. Im strengen Modus dürfen ETB-Einträge nur Konten mit der festen
Funktion `ETB` oder `S2` und Rolle `Stab` schreiben; TTB-Einträge nur Konten
mit der festen Funktion `Fernmelder`. Im lockeren Modus entfällt diese
Funktions-/Rollenbedingung. Anwendung und Insert-Trigger prüfen in beiden Modi
die konkrete aktive und ungesperrte Kontenidentität sowie den aktiven Einsatz
unabhängig voneinander.

Beim PDF-Einsatzdossier ist für ETB/TBB **Gesamtbuch** oder – für historischen
Bestand mit belegter Provenienz – genau eine frühere formale Dienstschicht
auswählbar. Dieser Legacy-Filter filtert ausschließlich ETB und TBB; Zeilen
ohne belegte Schichtzuordnung erscheinen nur im Gesamtbuch. Alle weiteren
ausgewählten Dossierbereiche bleiben einsatzweit vollständig. Deckblatt und
einsatzgebundener `pdf_export`-Audit halten den gewählten Umfang samt
historischen Schichtmetadaten fest.

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
| `/4fach/index.php` | öffentlicher Einstieg in die vollständige Anwendung mit Kontoauswahl und Benutzeranmeldung | operative Daten erst mit eStab-Sitzung und aktivem Einsatz; feste Funktions-/Rollengates gelten im strengen Einsatzmodus und sind im ausdrücklich gewählten lockeren Modus nur für vorgesehene Schreibschritte in Nachrichtenworkflow, ETB/TBB, S6-Planung und Melderlauf gelockert |
| `/4fach/activity.php` | meldet echte Interaktion einer angemeldeten Browseroberfläche | ausschließlich POST mit eStab-Sitzung, exakter SID und Session-CSRF; Statuspolling ruft den Endpunkt nicht auf |
| `/4fach/logout.php` | zentrale Abmeldung aus Sitzungsleiste und Nachrichtenarbeitsbereich | eStab-Sitzung, ausschließlich POST mit Session-CSRF |
| `/4fach/katgoedt.php` | globale, Funktions- und persönliche Kategorien | feste Kontofunktion und Rollenprüfung in beiden Einsatzmodi, bei Nachrichtenbezug zusätzlich Objektprüfung, CSRF für Änderungen |
| `/4fach/fuehrungsstelle.php` | freigegebenen S6-Plan lesen sowie S6- und Melderabläufe bearbeiten | eStab-Sitzung, aktiver Einsatz und unveränderte Ablauf-/Objektregeln; Fachzuständigkeit der festen Kontofunktion im strengen Modus, im lockeren Modus nur diese Schreibprüfung gelockert; keine Hutauswahl |
| `/4fueltg/ue_ltg.php` | einsatzgebundene Meldungsübersicht | ausschließlich festes Konto `S2/Stab` mit `LAGE_DOKUMENTATION` |
| `/4fach/nachwea.php` | Nachweisung der aufgenommenen und beförderten Nachrichten | ausschließlich festes Konto mit Funktion `LdF` oder `Fernmelder` |
| `/4fach/vordrucke.php` | abgeschlossene Vordrucke des aktiven Einsatzes im aktuellen, mit dem Einsatzdossier gemeinsamen PDF-Layout öffnen | feste Kontofunktion; zugrunde liegende Nachricht, Abschluss- und Druckstatus werden erneut geprüft, das persistierte Archiv bleibt unverändert |
| `/4fach/anhang.php`, `/4fach/download.php`, `/4fach/showpic.php` | Anhänge auswählen, auflisten, herunterladen oder als Bildvorschau öffnen | feste Kontofunktion; verknüpfte Anhänge erben exakt die Leserechte mindestens einer verknüpften Nachricht, freie Anhänge sind nur für Uploader oder S2, Si und LdF sichtbar |
| `/4fach/email.php` | strukturell geprüfte `.eml`-Anlage als passive Textansicht öffnen und auf die getrennte Originaldatei verweisen | dieselbe feste Kontofunktion und Objektberechtigung wie beim Download; erneute Integritätsprüfung, keine aktive Mail-HTML-/Remote-Darstellung und keine Behauptung einer DKIM-/S/MIME-verifizierten Absenderidentität |
| `/stabetb/etb.php`, `/fmtbb/tbb.php` | einsatzlokal fortlaufendes ETB und TBB lesen, berichtigen und fachabhängig ergänzen; ETB mit kombinierbarer Volltext-/Art-/Nummer-/Bezugs-/Anlagensuche und optionaler eindeutiger Anlagenzuordnung | angemeldete Konten lesen nach Objektregel; im strengen Modus schreibt ETB nur `ETB/Stab` oder `S2/Stab` und TTB nur `Fernmelder`, im lockeren Modus entfällt ausschließlich diese Rollen-/Funktionsprüfung; aktiver Einsatz erforderlich, keine aktive Schicht erforderlich, gespeicherte Zeilen append-only |
| `/4fadm/admin.php` | Administration | separates HTTP Basic Auth |
| `/4fadm/incidents.php` | Einsätze samt Führungsstellennamen und Berechtigungsmodus anlegen, historische Fehlwerte einmalig bestätigen, aktivieren und deaktivieren | HTTP Basic Auth, Session-CSRF, revisionsgesicherter globaler Status; Standard `Streng`, Wechsel zu `Locker` nur nach ausdrücklicher Warnungsbestätigung und mit Audit; die erste operative Eintragung setzt atomar einen dauerhaften Sperrmarker für den bestätigten Führungsstellennamen |
| `/4fadm/fuehrungsstelle.php` | optionale einsatzgebundene Zugangsschichten anlegen, Konten zuordnen und Gruppen gemeinsam aktivieren/deaktivieren | HTTP Basic Auth, Session-CSRF; unzugeordnete Konten bleiben erlaubt, Mehrfachzuordnungen gelten per OR, Deaktivierung kann Sitzungen widerrufen und verändert keine Fachrechte |
| `/4fadm/users.php` | Benutzer anlegen, Funktionen fest zuweisen, sperren/entsperren und Kennwörter zurücksetzen | HTTP Basic Auth, Session-CSRF; Rollen werden serverseitig abgeleitet und aktive Sitzungen atomar widerrufen |
| `/4fadm/self_registration.php` | öffentliche Kontoanlage sofort deaktivieren, dauerhaft oder für 15 Minuten bis 24 Stunden aktivieren | HTTP Basic Auth, Session-CSRF, ausdrückliche Sicherheitsbestätigung, optimistische Revision, globaler Advisory-Lock und transaktionales Audit; befristete Freigaben enden automatisch nach Datenbank-UTC und werden direkt im Konto-INSERT nochmals geprüft |
| `/4fadm/password_policy.php` | globale Kennwortrichtlinie prüfen, als revisionsgebundene Änderung voranzeigen und anschließend ausdrücklich bestätigen | HTTP Basic Auth, Session-CSRF; konfigurierbare Mindestlänge 8–128 Unicode-Codepoints (Standard 12), höchstens 1024 UTF-8-Bytes, 1024 Browser-Eingabeeinheiten, optionale Unicode-Zeichenklassen, Argon2id und transaktionales Audit; nur künftig gesetzte Funktionskonto-Kennwörter werden gegen die Richtlinie geprüft |
| `/4fadm/incident_export.php` | neun wählbare PDF-Abschnitte: ETB, TBB, Nachrichtenvordrucke, Anhänge, Nachrichtenereignisse, Dienstorganisation, S6-Fernmeldepläne, Melderläufe und Betriebsereignisse; ETB/TBB als Gesamtbuch oder per Legacy-Dienstschicht | HTTP Basic Auth, Session-CSRF, einsatzgebundene Abfragen; Dienstorganisation enthält optionale Zugangsschichten samt aktuellen/entfernten Zuordnungen und getrennt gekennzeichnete historische `nv_dienst*`-Evidenz; der historische Schichtfilter betrifft nur ETB/TBB |
| `/4fadm/system_status.php` | ausführlicher Laufzeitstatus | HTTP Basic Auth |
| `/4fadm/export.php` | Einsatzexporte auflisten, erstellen, als ZIP herunterladen und einzeln löschen | HTTP Basic Auth; POST-Erstellung/-Löschung mit Session-CSRF; Download nur über validierte Exportkennung |
| `/4fadm/make_fkt.php` | aktive Empfängermatrix und einzelne Standardmatrix atomar bearbeiten | HTTP Basic Auth, Session-CSRF; Rollenabgleich und Sitzungswiderruf committen mit der aktiven Matrix |
| `/4fadm/set_number_after_crash.php` | Nachrichtenzähler nach Rückfallbetrieb erhöhen | HTTP Basic Auth und CSRF |
| `/4fach/resetpic.php` | Markierungen zur erneuten PDF-Vordruckerzeugung zurücksetzen | HTTP Basic Auth und CSRF |
| `/health.php` | knappe Readiness-Antwort für den Monitor | absichtlich ohne Anmeldung |
| `/handbuch/` | aktuelles, durchsuchbares Web-Handbuch für Bedienung, Rollen, Einsatzablauf, Administration und Betrieb | öffentlich |

Der Benutzername für den Administrationsbereich steht in
`ESTAB_ADMIN_USER`, das Kennwort in der durch
`ESTAB_ADMIN_PASSWORD_SECRET_FILE` referenzierten Datei. Diese
Administrationsanmeldung ist unabhängig von den eStab-Funktionsbenutzern.
Nur `admin-auth-init` erhält diese Klartextdatei; Apache liest im laufenden
App-Container ausschließlich den daraus abgeleiteten bcrypt-Hash.

Die für Kapitel 4.3 der THW-DV 1-101 und das Handbuch
„ETB-/und TBB-Führung in THW-Führungsstellen“, Version 1.0, Stand März 2022,
umgesetzten fachlichen Invarianten, Quellfassungen, SHA-256-Prüfsummen,
Aussagegrenzen und Abnahmeschritte stehen in
[docs/DV-1-101-UMSETZUNG.md](docs/DV-1-101-UMSETZUNG.md). Die geprüfte
ETB-/TBB-Datei hat den SHA-256
`2457d1deccd01892655bbc329b08885a0b3c8b3ebfb6372c79997d3427d1ae59`.
Die technische Umsetzung und die automatisierten Nachweise sind
**keine formale THW-Freigabe**. Die Referenz lässt ein elektronisches ETB/TBB
nur nach Freigabe durch die THW-Leitung zu; diese Freigabe muss vor einem
amtlichen urkundlichen Produktiveinsatz von der zuständigen Stelle schriftlich
erteilt und zusammen mit der örtlichen Abnahme dokumentiert werden. Die
formale Sichtung eines Ausgangs ist verbindlicher Bestandteil des
Nachrichtenlaufs und kann weder per Compose-Umgebung noch per
Legacy-Konfiguration abgeschaltet werden.

### Nachrichtenlauf und Nachweisung

Die Statuswerte bezeichnen eindeutig die aktuell zuständige Arbeitsstufe. Die
Spalte „Zuständig“ nennt zugleich das im strengen Modus erforderliche
Funktionskonto. Im lockeren Modus bleibt die Arbeitsstufe erhalten, während
ein anderes gültiges Konto den ausdrücklich angebotenen Schreibschritt
übernehmen darf:

| Status | Richtung | Zuständig | Bedeutung |
| --- | --- | --- | --- |
| `4` | Ausgang | Si | Anschrift, Verfasserzeichen und Verfasserfunktion formal prüfen; freigeben oder mit Pflichtgrund an den Verfasser zurückgeben |
| `10` | Ausgang | Verfasser | zurückgegebenen Entwurf korrigieren und erneut zur formalen Sichtung einreichen |
| `1` | Ausgang | LdF | Rufname der Gegenstelle und vorgesehenen Beförderungsweg festlegen |
| `2` | Ausgang | Fernmelder | den vorbereiteten Ausgang tatsächlich befördern |
| `1` | Eingang | LdF | aufgenommenen Rufnamen in einen Absender übersetzen und den vom Fernmelder erfassten Eingangsweg bestätigen oder begründet korrigieren |
| `4` | Eingang | Si | Inhalt auswerten und Empfänger festlegen |
| `8` | beide | abgeschlossen | Nachricht ist fertig bearbeitet und der Vordruck kann erzeugt werden |

Ein Eingang läuft immer über `1 → 4 → 8`. Ein Ausgang läuft über
`4 → 1 → 2 → 8`; nach einer formalen Rückgabe entsteht
`4 → 10 → 4 → 1 → 2 → 8`. Es gibt keine Autosichtung. Im strengen Modus
bleibt die Nachricht bei unbesetztem Si in dieser Warteschlange, ohne dass der
Fernmelder einen Sichtervermerk erzeugen kann. Im lockeren Modus darf ein
anderes Konto die angebotene Si-Stufe bearbeiten, die Stufe aber nicht
überspringen.
Beim Eingang erfasst im strengen Modus der Fernmelder den empfangenen
Rufnamen; im lockeren Modus kann ein anderes Konto diese Aufnahmestufe
übernehmen. In beiden Modi kann die Aufnahmestufe das Feld „Absender“ weder im
Formular noch über einen manipulierten Request schreiben. Sie erfasst außerdem
Medium, Aufnahmezeit und Aufnahmezeichen.
Im strengen Modus muss anschließend LdF daraus einen nicht leeren Absender
festlegen und den Eingangsweg ausdrücklich bestätigen. Im lockeren Modus darf
ein anderes aktives und ungesperrtes Konto genau diese angebotene
Workflowstufe übernehmen; Absenderpflicht, Richtung, Status und Sperrinhaber
bleiben dabei verbindlich. Ändert das handelnde Konto das Medium, ist eine
Begründung Pflicht; Aufnahmezeit und Aufnahmezeichen der Aufnahmestufe bleiben
unveränderlich.
Bestätigung, ursprüngliches und bestätigtes Medium, etwaige Begründung sowie
die tatsächliche Kontoidentität mit fester Funktion und Rolle werden atomar in
der Nachrichten-Ereigniskette nachgewiesen.
Die nummerierte Aufnahme erzeugt außerdem genau einen TBB-Eintrag des Typs
`nachricht`. Dieser Typ ist dem automatischen, mit der Nachricht verknüpften
Workflow vorbehalten und steht bei manuellen TBB-Einträgen nicht zur Auswahl;
historische Zeilen bleiben unverändert lesbar. Übersetzt LdF danach den Absender oder korrigiert begründet den
Eingangsweg, bleibt dieser Originaleintrag unverändert; eStab hängt einen
direkt darauf verweisenden TBB-Korrektureintrag mit eigener lokaler Nummer an.
Generator, Detailansicht und Dossier übernehmen als Nachweisnummer
ausschließlich die erste lokale Nummer des automatischen Typs `nachricht`,
nicht die Nummer eines späteren Nachtrags. Meldungsübersicht, zweite Sichtung
und Nachweislisten verwenden dieselbe kanonische TBB-Nummer für Anzeige,
numerische Suche und Sortierung. Solange etwa ein Ausgang noch nicht tatsächlich
befördert wurde, zeigen sie ehrlich „noch kein TBB-Nachweis“ statt der
technischen Archiv- oder globalen Datenbanknummer.
Beim Fokus auf „Rufname der Gegenstelle“ bietet das Formular den Funktionen
Fernmelder und LdF
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
erhalten. Aktiver Einsatz, feste Kontofunktion, serverseitig abgeleitete Rolle, Richtung und
Sperrbesitz werden im selben Datenbank-Statement wie die Zuordnungen erneut
geprüft; Werte anderer Einsätze werden nicht offengelegt. Der lokale Absender
eines Ausgangs bleibt davon unberührt: Er wird serverseitig aus dem
autoritativen Führungsstellennamen des in derselben Schreibtransaktion
gesperrten Einsatzes gesetzt. Eingänge werden an dieselbe lokale
Führungsstelle adressiert. Browserwerte, Einsatzname, Bedarfsträger,
Einsatzleitung und Umgebungsvariablen sind dafür keine Ersatzquelle.

Vorrangsstufen werden überall mit denselben fachlichen Bezeichnungen
angezeigt und verarbeitet: **keine**, **Sofort**, **Blitz** und
**Staatsnot**. Sofort, Blitz und Staatsnot werden direkt im Vorrangfeld des
Nachrichtenvordrucks ausgewählt und dort markiert; eine parallele Auswahl
außerhalb des Blatts gibt es nicht. Warteschlangen sortieren ausdrücklich in
dieser Reihenfolge von Staatsnot nach keine; sie verlassen sich nicht auf die
interne MariaDB-`SET`-Reihenfolge. Staatsnot darf nur auf ausdrückliche
Weisung einer hierzu berechtigten Stelle verwendet werden. eStab kann diese
externe Berechtigung nicht selbst feststellen und weist deshalb im Formular
darauf hin. Das historische interne Kürzel `eee` bleibt lesbar und bedeutet
wie ein leerer Wert „keine“, wird bei neuen Vordrucken aber nicht mehr
angeboten.
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
„Neues Konto anlegen“-Funktion ist standardmäßig ausgeschaltet. Die
Administration steuert sie unter `/4fadm/self_registration.php` dauerhaft oder
für einen festen Zeitraum. Vor dem Aktivieren muss bestätigt werden, dass die
Anmeldeseite nur in einem kontrollierten Netz und unter Aufsicht erreichbar
ist; während der Freigabe kann jede erreichende Person jede angebotene aktive
Funktion auswählen. Eine Freigabe verlangt weiterhin eine Kennwortbestätigung
und meldet niemals still ein vorhandenes Konto an. Für Kontoanlage, administrativen
Kennwortreset und Selbstregistrierung gilt dieselbe gespeicherte
Kennwortrichtlinie. Deren Mindestlänge ist zwischen 8 und 128 Unicode-Codepoints
konfigurierbar und beträgt nach Installation 12 Unicode-Codepoints;
Unicode-Groß- oder Titlecase-Buchstaben, Unicode-Kleinbuchstaben,
Unicode-Ziffern sowie Sonderzeichen können unabhängig als Pflicht aktiviert
werden. Unicode-Steuerzeichen sind ausgeschlossen, Formatzeichen einschließlich
ZWJ bleiben zulässig. Ein
Bestandslogin wird nicht nachträglich gegen eine
verschärfte Richtlinie geprüft. Neue und administrativ geänderte Kennwörter
werden mit Argon2id gespeichert; serverseitig sind höchstens 1024 UTF-8-Bytes
zulässig. Das Browser-JavaScript zählt die konfigurierbare Mindestlänge exakt
in Unicode-Codepoints und begrenzt die Eingabe auf 1024 Eingabeeinheiten; die
Serverprüfung bleibt verbindlich. Importierte Klartextwerte und andere
eindeutig verifizierbare Alt-Hashes werden nach erfolgreicher Anmeldung auf
Argon2id umgestellt. bcrypt wird nur bei einem eingegebenen Kennwort unter 72
UTF-8-Bytes automatisch migriert; ab 72 Bytes bleibt der ambivalente Alt-Hash
bis zu einem administrativen Reset bestehen. Bereits stärkere oder gemischte
Argon2id-Kosten werden nicht auf Standardwerte zurückgestuft. Beide
Browserabläufe sind bereits
vor der Anmeldung an ein Session-CSRF-Token gebunden.

Eine bestehende Anmeldung und die sichtbare Präsenz sind getrennte Zustände.
Nach 15 Minuten ohne echte Browserinteraktion zeigt die Aktivitätsübersicht
das Konto als „Inaktiv“; die Sitzung bleibt zunächst gültig. Nach 12 Stunden
ohne solche Interaktion widerruft die serverseitige Authentisierungsgrenze die
Sitzung und führt beim nächsten geschützten Aufruf zum Bestandslogin. Maus-,
Tastatur- und Formulareingaben werden höchstens einmal pro Minute über einen
SID- und CSRF-gebundenen POST gemeldet. Automatische Seitenaktualisierungen,
das regelmäßig geladene Statusfragment und ein bloß geöffnetes Browserfenster
verlängern die Fristen nicht. Der getrennte HTTP-Basic-Zugang der
Administration besitzt einen eigenen, vom Browser verwalteten Lebenszyklus.

Ändert der Administrator die aktive Empfängermatrix, werden geänderte Rollen
in derselben Transaktion in die betroffenen Konten übernommen und deren
Sitzungen widerrufen. Entfernte Funktionen werden nicht automatisch
umgedeutet: Das Konto erscheint in der Benutzerverwaltung als ungültig
zugeordnet, bleibt abgemeldet und muss dort einer gültigen Funktion neu
zugewiesen werden.

Auf der Übersicht führen zwei getrennte Schaltflächen unmittelbar zum
passenden Bestands- beziehungsweise Neuanlageformular. Ist
Selbstregistrierung deaktiviert, verschwindet die Neuanlage-Schaltfläche und
ein Hinweis verweist auf die Administration. Ist die Einstellung oder
Datenbank nicht sicher lesbar, bleibt die Kontoanlage ebenfalls geschlossen.
Ohne
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
Frame heraus den Arbeitsbereich auf Top-Level. Nach erfolgreicher Anmeldung
wird das vorgemerkte, für die feste Kontofunktion zulässige Ziel direkt
geöffnet; eine zusätzliche Hutauswahl ist nicht erforderlich.
Auch ein Browserformular, dessen Sitzung inzwischen abgelaufen ist, führt per
HTTP 303 zum Login; seine nicht verarbeiteten Eingaben werden dabei ausdrücklich
nicht erneut gesendet. Der Login weist sichtbar darauf hin, dass die Eingabe
nicht gespeichert wurde und erneut erfasst werden muss. Alte operative
GET-Querys des Nachrichtencontrollers werden ebenfalls vollständig verworfen
und führen ausschließlich zum erlaubten Ziel `messages`. Zugangsdaten oder
Login-Metadaten in einer solchen GET-Query bleiben dagegen eine harte
Ablehnung. Rollen-, Objekt-, CSRF- und Bildendpunkte behalten ihre knappen
403-Sicherheitsgrenzen. Das authentifizierte Statusfragment antwortet bei
fehlender oder abgelaufener Sitzung mit HTTP 401, damit die Sidebar den
Top-Level-Login öffnet. Die Administration ist als eigener technischer Zugang
markiert.

Das gemeinsame Manifest enthält in stabiler Reihenfolge neun operative
Bereiche: Übersicht, Nachrichtenvordruck, Führungsstellenbetrieb,
Meldungsübersicht, Vordrucke, ETB, TBB, Nachweisung und BOS-Info; hinzu kommen
Administration und Handbuch als zwei Dienste. Anonym sind damit elf Links
sichtbar. Nach der Anmeldung blendet die Navigation unzulässige Spezialziele
direkt anhand der festen Kontofunktion aus: Gewöhnliche
Stab-/FB-Funktionen, Si und S6 sehen einschließlich der beiden Dienste neun
Links; S2 sowie LdF und Fernmelder sehen jeweils den einen für sie freigegebenen
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
Kennwortrichtlinie sowie Systemstatus. Breite Fach- und Prüftabellen bleiben
auf kleinen Bildschirmen
als Karten lesbar oder werden in einem ausdrücklich begrenzten Tabellenbereich
gescrollt; sie erzeugen kein horizontales Scrollen des gesamten Dokuments.
Historische interne Generatoren und Installer besitzen keine direkte
Weboberfläche und werden vom Webserver abgewiesen.

Fachlich abgewiesene Browseraufrufe enden ebenfalls nicht als ungestalteter
Rohtext. HTTP-Status und begrenzte Fehlermeldung bleiben erhalten, erscheinen
aber in einer gemeinsamen, responsiven Fehlerseite mit Sitzungsidentität,
Abmeldung und allen für das Konto erlaubten Bereichen, sofern eine
Fachsitzung besteht, sowie einem festen Rückweg zur Übersicht. Statusfragmente,
Bilder, Downloads und andere Maschinenendpunkte
behalten dagegen ihre jeweiligen Datenformate.

Nach erfolgreicher Anmeldung zeigen der Einstieg, der
Nachrichtenarbeitsbereich, die Administrationsseiten und alle geschützten
eigenständigen HTML-Module die gemeinsame Leiste mit Name, Kürzel, Funktion und
abgeleiteter Rolle. Der Button „Abmelden“ beendet die lokale Sitzung auch bei
einer nachgelagerten Datenbankstörung, löscht die Anwendungscookies und führt
anschließend zum Anmeldeeinstieg zurück. Oberhalb von Identität, Logout,
Bereichslinks und rollenabhängigen Textbuttons bündelt die Sidebar den
passenden Arbeitszähler, Serverzeit und die Aktivitätsübersicht in einer
Statuskarte. Sie unterscheidet „Aktiv“, „Inaktiv (15 Min.)“, die eigene
Funktion und abgemeldete Funktionen; nur innerhalb der letzten 15 Minuten
bestätigte echte Interaktion zählt als aktiv.
Nur dieses Statusfragment wird regelmäßig aktualisiert; Fokus und
Scrollposition des Sidebar-Dokuments bleiben dabei auch am Hinweiston-Schalter
erhalten. Sein automatischer Abruf gilt ausdrücklich nicht als Aktivität und
verlängert weder die 15-Minuten-Anzeige noch das 12-Stunden-Sitzungsfenster.
Offene Meldungen bleiben unabhängig vom einmaligen Tonsignal
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

Für die Warteschlangen von Fernmelder, Si und Stab/FB bleiben die
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
Logout-Aktionen sind POST-/CSRF-gebunden und werden zusätzlich gegen feste
Kontofunktion, serverseitig abgeleitete Rolle, Empfänger, Objektstatus und gegebenenfalls
Sperrinhaber geprüft. Normale Stab-/FB-Funktionen lesen nur eine terminale
Empfängerkopie oder ihren eigenen Ausgang. Si, LdF und Fernmelder lesen nur ihre
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
Liste und Download verlangen eine gültige Kontositzung und einen aktiven
Einsatz und autorisieren den Vordruck über das Leserecht der festen Funktion am gedruckten
Nachrichtendatensatz des aktiven Einsatzes; ein bloß im Volume vorhandener
Dateiname genügt nicht. Verknüpfte Anhänge übernehmen dieselbe Objektgrenze
über vollständige, semikolongetrennte Dateinamens-Tokens. Freie Anhänge bleiben
auf den Uploader sowie S2, Si und LdF begrenzt; direkter Upload,
Archivauswahl, Lösen der Zuordnung und endgültiges Nachrichtenspeichern prüfen
die Berechtigung erneut. Bild-, PDF- und passive E-Mail-Browseransicht sowie
der Download
arbeiten erst nach erneuter Objekt-, MIME- und Integritätsprüfung auf einem
unveränderlichen Byte-Snapshot.
Einzelvordruck und Nachrichtenseiten des PDF-Einsatzdossiers verwenden
denselben A4-Formularrenderer. Neue Anhänge eines Dossiers werden beim Eingang
an SHA-256 und Bytezahl gebunden und vor dem Einbetten erneut geprüft; beim
Upgrade vorhandene Legacy-Dateien heißen ausdrücklich „Integrität beim Eingang
nicht belegbar“. JPEG, PNG, GIF und BMP werden
zusätzlich als sichtbare Anlagenseiten ausgegeben; mehrseitige PDFs erscheinen
einschließlich ihrer Anmerkungen seitenweise gerastert. Verlustfrei
Windows-1252-darstellbarer Text wird direkt ausgegeben. Standardisierte
`.eml`-Dateien werden innerhalb der PDF-Textgrenzen auch im Dossier nur passiv
als Kopfzeilen und Textkörper dargestellt; enthaltene Mail-Anlagen werden als
Metadaten nachgewiesen. Nicht verlustfrei darstellbare E-Mails, TIFF und andere nicht
statisch darstellbare Formate erhalten eine klare Hinweisseite, ihr
bytegleiches Original bleibt eingebettet. Ist eine historische
Empfängerfunktion in der heutigen Matrix nicht mehr vorhanden, bleibt sie mit
ihrem gespeicherten Kopiekennzeichen ausdrücklich im Inhaltsbereich sichtbar.
Führungsstellenname, Einsatzkennung und Einsatzname erscheinen getrennt in
Statusanzeige, Exportauswahl und PDF-Dossier. Bei einem vor Migration 97
angelegten Einsatz ohne bestätigten Führungsstellennamen erfindet der
PDF-Export keinen Ersatz, sondern kennzeichnet ihn ausdrücklich als
„historisch nicht erfasst“.

## Dokumentation

- [Abschluss-Audit vom 1. August 2026](docs/ABSCHLUSS-AUDIT.md)
- [Betrieb und Konfiguration](docs/BETRIEB.md)
- [Pull-only Registry- und Synology-Deployment](deploy/registry/README.md)
- [Migration und Upgrade](docs/MIGRATION-UND-UPGRADE.md)
- [Backup und Wiederherstellung](docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
- [Einsätze und Datenzuordnung](docs/EINSAETZE-UND-DATENZUORDNUNG.md)
- [THW-DV-1-101- und ETB-/TBB-Umsetzung samt Freigabevorbehalt](docs/DV-1-101-UMSETZUNG.md)
- [Amtlicher Nachrichtenvordruck und Ausfüllhilfen](docs/NACHRICHTENVORDRUCK.md)
- [Nachrichtenlisten suchen und filtern](docs/NACHRICHTENLISTEN.md)
- [PDF-Einsatzdossier](docs/PDF-EINSATZDOSSIER.md)
- [Benutzerverwaltung](docs/BENUTZERVERWALTUNG.md)
- [Tests, Funktionsnachweis und Monitoring](docs/TESTS-UND-MONITORING.md)
- [Echter Browser-Akzeptanztest](tests/browser/README.md)
- [Funktionsmatrix und Freigabeprotokoll](docs/FUNKTIONSNACHWEIS.md)
- [Architektur und Sicherheitsentscheidungen](docs/ARCHITEKTUR-UND-SICHERHEIT.md)
- [Nachweis der SVN- und Release-Migration](migration/README.md)
- [Index der unverändert übernommenen Originaldokumentation](docs/legacy/README.md)
- [Aktuelles Web-Handbuch](handbuch/)
- [Historisches Anwendungshandbuch Version 1.1 von 2011](doku/Handbuch_eStab.pdf)

Das unter `/handbuch/` ausgelieferte Web-Handbuch ist die aktuelle
Bedienreferenz und wird gemeinsam mit der Anwendung versioniert. Das PDF von
2011 bleibt ausschließlich als historische Quelle im Git-Bestand erhalten;
es beschreibt unter anderem veraltete XAMPP-, MySQL- und
Web-Installer-Verfahren und wird nicht mehr in das Laufzeitimage übernommen.
Für Installation, Sicherheit, Backup und Upgrade gelten das Web-Handbuch und
die heutigen Runbooks unter `docs/`.

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
