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
Compose-Provider sowie `openssl` und `curl`. Alle Beispiele verwenden Podman;
mit Docker wird `podman compose` durch `docker compose` ersetzt.

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
podman compose config >/dev/null
podman compose build --pull migrate app
podman compose up -d
podman compose ps
curl --fail --silent --show-error http://127.0.0.1:8080/health.php
```

Nach der gesunden Datenbank läuft zuerst der einmalige Service `migrate`.
Er prüft und protokolliert alle SQL-Migrationen per SHA-256 und führt den
vollständigen Schematest aus. `app` darf erst starten, wenn dieser Container
erfolgreich mit Exitcode 0 beendet wurde. Vor dem ersten Start einer neuen
Version auf einem vorhandenen Volume ist deshalb immer ein Vollbackup Pflicht.

Eine erfolgreiche Bereitschaftsprüfung liefert HTTP 200 und
`"status":"ready"`. Zusätzlich sollte nach Erstinstallation, Upgrade und
Wiederherstellung das vollständige Schema geprüft werden:

```console
podman compose run --rm migrate
```

Der Runner gibt `Post-migration schema verification passed` aus. Er verwendet
das Root-Secret ausschließlich über eine private temporäre MariaDB-Optionsdatei;
das Kennwort erscheint weder in Argumentliste noch Log.

## Zugänge

| Pfad | Zweck | Schutz |
| --- | --- | --- |
| `/` | Einstieg, direkter Anmeldebutton und Modulübersicht | öffentlich bis zur Modulanmeldung |
| `/4fach/index.php` | öffentlicher Einstieg in die vollständige Anwendung mit Kontoauswahl und Benutzeranmeldung | Fachfunktionen erst mit eStab-Sitzung |
| `/4fach/logout.php` | zentrale Abmeldung aus Sitzungsleiste und Frameset | eStab-Sitzung, ausschließlich POST mit Session-CSRF |
| `/4fach/status.php` | Statusframe | anonym nur neutraler Hinweis, Rollenbelegung erst mit eStab-Sitzung |
| `/4fach/katgoedt.php` | globale, Funktions- und persönliche Kategorien | eStab-Sitzung, Rollen-/Objektprüfung und CSRF für Änderungen |
| `/4fadm/admin.php` | Administration | separates HTTP Basic Auth |
| `/4fadm/system_status.php` | ausführlicher Laufzeitstatus | HTTP Basic Auth |
| `/4fadm/export.php` | manueller CSV-/ZIP-Einsatzexport | HTTP Basic Auth und CSRF |
| `/4fadm/make_fkt.php` | Empfängermatrix atomar bearbeiten | HTTP Basic Auth und CSRF |
| `/4fadm/set_number_after_crash.php` | Nachrichtenzähler nach Rückfallbetrieb erhöhen | HTTP Basic Auth und CSRF |
| `/4fach/resetpic.php` | Grafik-/PDF-Erzeugungsmarkierungen zurücksetzen | HTTP Basic Auth und CSRF |
| `/health.php` | knappe Readiness-Antwort für den Monitor | absichtlich ohne Anmeldung |
| `/doku/Handbuch_eStab.pdf` | historisches Anwendungshandbuch | öffentlich |

Der Benutzername für den Administrationsbereich steht in
`ESTAB_ADMIN_USER`, das Kennwort in der durch
`ESTAB_ADMIN_PASSWORD_SECRET_FILE` referenzierten Datei. Diese
Administrationsanmeldung ist unabhängig von den eStab-Funktionsbenutzern.

Der Anmeldeeinstieg trennt zwei Vorgänge ausdrücklich: „Mit bestehendem Konto
anmelden“ verlangt das bereits gespeicherte Kennwort und legt niemals ein Konto
an. „Neues Konto anlegen“ verlangt eine Kennwortbestätigung, funktioniert nur
bei aktivierter Selbstregistrierung und meldet niemals still ein vorhandenes
Konto an. Die Rolle wird in beiden Fällen ausschließlich aus der ausgewählten
Funktion und der Empfängermatrix abgeleitet. Beide Browserabläufe sind bereits
vor der Anmeldung an ein Session-CSRF-Token gebunden.

Nach erfolgreicher Anmeldung zeigen der Einstieg, das Haupt-Frameset und alle
geschützten eigenständigen HTML-Module eine gemeinsame Sitzungsleiste mit Name,
Kürzel, Funktion und abgeleiteter Rolle. Der dortige Button „Abmelden“ beendet
die lokale Sitzung auch bei einer nachgelagerten Datenbankstörung, löscht die
Anwendungscookies und führt anschließend zum Anmeldeeinstieg zurück. Direkt
geöffnete Status-/Zählerseiten erhalten dieselbe kompakte Anzeige; innerhalb
des Framesets bleibt sie bewusst einmalig in der Navigation sichtbar. Die
separate HTTP-Basic-Identität der Administration wird davon nicht berührt.

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
| `estab_export` | `/var/lib/estab/export` | manuell erzeugte Tabellenexporte |

`podman compose down` behält diese Volumes. `podman compose down --volumes`
löscht sie und darf nur nach einem geprüften Backup oder für einen ausdrücklich
wegwerfbaren Test-Stack verwendet werden.

## Dokumentation

- [Betrieb und Konfiguration](docs/BETRIEB.md)
- [Migration und Upgrade](docs/MIGRATION-UND-UPGRADE.md)
- [Backup und Wiederherstellung](docs/BACKUP-UND-WIEDERHERSTELLUNG.md)
- [Tests, Funktionsnachweis und Monitoring](docs/TESTS-UND-MONITORING.md)
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
Lizenzformulierung. Vor einer Weiterverteilung sollte die konkrete
Lizenzkennzeichnung mit den ursprünglichen Rechteinhabern geklärt werden.

## Herkunft

Der Git-Verlauf geht bytegenau auf das SourceForge-SVN bis r85 zurück. Vier
historische Entwicklungszweige und sechs SVN-Tags wurden erhalten; die später
veröffentlichten Archive 0.9.26b und 0.9.26c sind als geprüfte Snapshot-Commits
und annotierte Git-Tags dokumentiert. Prüfsummen, Ref-Vergleiche und
SVN-Properties liegen unter `migration/`. Die separat versionierten 95
Originaldokumente liegen unverändert unter `docs/legacy/svn-r85/`.
