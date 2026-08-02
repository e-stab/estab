# Architektur und Sicherheitsentscheidungen

eStab ist eine modern gekapselte Legacy-Anwendung. Die fachlichen PHP-Module
bleiben nah am letzten Upstream-Release; eine kleine Laufzeit- und
Sicherheitsschicht stellt die Ausführung unter PHP 8.5 und MariaDB 11.8 her.

## Laufzeitarchitektur

```text
Browser
   |
   | HTTPS
   v
Reverse Proxy / Zugriffskontrolle
   |
   | HTTP auf Loopback oder privatem Frontend-Netz
   v
Apache 2.4 + PHP 8.5  ----  estab_data / estab_export
   ^       ^
   |       +---- estab_auth (nur bcrypt, read-only)
   |                         ^
   |                         +-- admin-auth-init
   |                             (netzlos, Klartextsecret nur hier)
   |
   +--- Start erst nach Exit 0 von migrate und admin-auth-init
        migrate (MariaDB-Client, SHA-256, Schema-Verify)
                              |
                              | internes Netz "database"
                              v
MariaDB 11.8  ------------------------  estab_db
```

Der App-Service ist mit dem `frontend`- und dem internen `database`-Netz
verbunden. MariaDB und der kurzlebige Migrator hängen ausschließlich im
internen Netz; `admin-auth-init` besitzt überhaupt kein Netzwerk. Der
Datenbankport wird nicht veröffentlicht. Standardmäßig wird auch Apache nur
an `127.0.0.1:8080` gebunden.

## Verzeichnisverantwortung

| Bereich | Verantwortung |
| --- | --- |
| `4fach/` | Nachrichtenvordruck, Anmeldung, Sichtung, Kategorien, Anhänge, passive E-Mail-Ansicht und Fachoberfläche |
| `4fadm/` | Basic-Auth-geschützte Administration, Systemstatus und Einsatzexport |
| `4fbak/` | aktive dateisysteminterne PDF-Erzeugung, historische FPDF-Komponente und der bereits im letzten Upstream-Release deaktivierte Bildgenerator |
| `stabetb/`, `fmtbb/`, `ubltg/`, `sammlung/` | Einsatztagebuch, technisches Betriebsbuch und Zusatzmodule |
| `app/` | Bootstrap, PHP-/MySQL-Kompatibilität, Authentisierung, feste Kontofunktion, optionale Zugangsschichten, objektbezogene Leseberechtigung, Navigation, Sitzung, CSRF, Datum, Nachrichten-/Kategoriezugriff, Anhang einschließlich begrenztem RFC-822-Parser, Export und transaktionale Admin-Operationen |
| `4fcfg/` | historische Konfigurationsschnittstelle, heute aus validierten Umgebungswerten gespeist |
| `docker/` | Apache-/PHP-Härtung, Entrypoint, Datenbankschema und Migrationen |
| `tests/` | statische, sicherheitsbezogene, Datenbank- und HTTP-Nachweise |
| `migration/` | unveränderlicher SVN-/Release-Provenienznachweis |
| `docs/legacy/` und `doku/` | historische Fach- und Entwicklungsdokumentation |

Der Anwendungscode liegt unveränderlich im Image. Ausschließlich
`/var/www/html/4fdata`, `/var/lib/estab/export`, `/var/lib/mysql` und die
Container-Laufzeitpfade sind beschreibbar beziehungsweise persistent.

## Kompatibilitätsgrenze

`app/bootstrap.php` wird durch PHPs `auto_prepend_file` vor jedem Webskript
geladen. Es:

- erzwingt zentrale Laufzeit-, Session- und Zeichensatzvorgaben,
- validiert Umgebungswerte und öffentliche URLs,
- stellt kontrollierte Ersatzfunktionen für entfernte PHP- und `mysql_*`-APIs
  bereit,
- wertet Proxy-Header nur nach expliziter Freigabe aus.

Die Kompatibilitätsschicht begrenzt die notwendige Änderung am historischen
Fachcode. Neue sicherheitskritische Pfade verwenden direkt `mysqli` mit
Prepared Statements; die Legacy-SQL-Schicht ist keine Empfehlung für neue
Funktionen.

## Authentisierungsgrenzen

Es existieren zwei unabhängige Identitäten:

- Die Anwendungssitzung speichert Name, Kürzel, Funktion und Rolle nach einer
  POST-basierten Anmeldung. Funktionen und Rollen stammen serverseitig aus der
  Empfängermatrix. Sitzungs-IDs werden nach erfolgreichem Login erneuert,
  Cookies sind `HttpOnly`, `SameSite=Lax` und bei erkanntem HTTPS zusätzlich
  `Secure`.
- Der gesamte Pfad `/4fadm` sowie der historisch unter `/4fach/resetpic.php`
  liegende PDF-Vordruckreset werden vom Apache mit einer vor dem Webstart
  atomar erzeugten bcrypt-`htpasswd` geschützt. Das Admin-Kennwort stammt aus
  einem separaten schreibgeschützten Secret-Dateimount, den ausschließlich der
  netzlose `admin-auth-init`-Service erhält. Der App-Service mountet die
  abgeleitete Datei schreibgeschützt; PHP kann das Klartextsecret weder sehen
  noch öffnen.
  Schreibende Admin-Formulare verlangen zusätzlich einen an die PHP-Sitzung
  gebundenen CSRF-Token.

Für die Anwendungssitzung sind Sitzungsbestand und Präsenz bewusst getrennt.
`nv_benutzer.aktiv` und die exakte gespeicherte SID bilden weiterhin die
widerrufbare Anmeldegrenze. Das von Migration 100 ergänzte UTC-Feld
`estab_letzte_aktivitaet DATETIME(6)` bestimmt zusätzlich den sichtbaren
Aktivitätszustand und das serverseitige Leerlaufende:

- weniger als 15 Minuten ohne echte Interaktion: `online`,
- ab 15 Minuten: `inactive`, wobei die Fachsitzung noch gültig bleibt,
- ab 12 Stunden: `expired`; die SID und ihre IP-Metadaten werden widerrufen.

Fehlende, syntaktisch ungültige oder zukünftige Aktivitätswerte werden nicht
geschätzt, sondern fail-closed als abgelaufen behandelt. Jede geschützte
Anfrage validiert Konto, Sperre, SID und 12-Stunden-Grenze erneut gegen die
Datenbankzeile. Die periodische Bereinigung der Benutzerlisten entfernt
zusätzlich abgelaufene SIDs, auch wenn der zugehörige Browser keinen weiteren
Request sendet. `session.gc_maxlifetime` ist in Bootstrap und Container-PHP
auf dieselben 43.200 Sekunden gesetzt; maßgeblich für den fachlichen Widerruf
bleibt dennoch der Datenbankzeitstempel und nicht die probabilistische
PHP-Session-Garbage-Collection.

Die gemeinsame HTML-Sitzungsleiste registriert ausschließlich echte
Zeiger-, Tastatur-, Formular-, Rad- und Touchinteraktion sowie die bewusste
Rückkehr in ein sichtbares Fenster. Sie sendet höchstens einmal pro Minute
einen gleich-originigen POST an `/4fach/activity.php`. Dieser Request verlangt
Session-CSRF, Kontokürzel und exakt die in der Datenbank gespeicherte SID; er
kann weder eine abgelaufene noch eine gesperrte Sitzung wiederbeleben. Es gibt
bewusst keinen Intervall- oder Seitenlade-Heartbeat. Statuspolls, automatische
Refreshes und bloße Hintergrundtabs verlängern weder die 15-Minuten-Anzeige
noch die 12-Stunden-Sitzung. Antwortet der Aktivitätsendpunkt mit HTTP 401,
führt der Monitor den Top-Level-Kontext zum Bestandslogin.

Der vorgelagerte HTTP-Basic-Administrationszugang ist von diesem Mechanismus
unabhängig. Seine Browser-Credentials werden weder in
`estab_letzte_aktivitaet` geschrieben noch durch das 12-Stunden-Fachsession-
Timeout widerrufen; eine zugleich bestehende eStab-Fachsitzung bleibt auch auf
Administrationsseiten separat erkennbar.

Apache lehnt `PATH_INFO` hinter ausführbaren PHP-Dateien generell ab. Die
zentrale operative Schreibgrenze leitet Ausnahmen ausschließlich aus dem
tatsächlich ausgeführten `SCRIPT_NAME` ab, nie aus der frei wählbaren
Request-URI. Ein Suffix wie `mainindex.php/4fadm` kann dadurch weder die
Basic-Auth-Grenze noch eine administrative Schreibausnahme vortäuschen.

Die Anwendungssitzung weist das Konto mit dessen fester Funktion und
serverseitig abgeleiteter Rolle nach. Für jeden operativen Schreibzugriff muss
zusätzlich ein Einsatz aktiv sein. Jeder normale Schreibpfad revalidiert
konkretes Konto, Sperrstatus, Zugangsschichtwirkung, aktiven Einsatz und dessen
Berechtigungsmodus. In den vom Modus erfassten operativen Workflow-,
ETB-/TTB-, S6-Plan- und Melderpfaden werden Funktion und Rolle in `STRICT`
ebenfalls als Schreibgrenze geprüft; in `LOOSE` entfällt ausschließlich diese
Prüfung. Rollenstrenge Übersichten, Kategorien- und Administrationsrechte sind
davon ausgenommen.
Eine aktive Dienst- oder
Zugangsschicht, persönliche Besetzungsannahme oder Hutauswahl ist keine
Bedingung. Die fachlichen Controller prüfen diese Grenze serverseitig erneut;
ausgeblendete Links oder bereits geladene Seiten gelten nicht als
Berechtigungsnachweis.

Optionale Zugangsschichten bilden ausschließlich einsatzbezogene
Kontengruppen. Unzugeordnete Konten bleiben zugelassen, Mehrfachzuordnungen
gelten per OR. Aktivierung erzeugt keine Sitzung; Deaktivierung widerruft eine
Sitzung, sofern kein anderer aktiver Gruppenzugang verbleibt. Die dauerhafte
manuelle Kontosperre bleibt davon unabhängig und vorrangig.

### Einsatzbezogener Berechtigungsmodus

`nv_einsaetze.estab_permission_mode` speichert genau einen Modus je Einsatz:

- `STRICT` ist der Standard für neue und beim Upgrade bereits vorhandene
  Einsätze. Er bewahrt die bisherigen funktions- und rollenbezogenen
  Schreibrechte einschließlich ihrer Datenbank-Trigger.
- `LOOSE` erlaubt einem konkret angemeldeten, aktiven und ungesperrten Konto
  die dafür vorgesehenen operativen Workflow-, ETB-/TTB-, S6-Plan- und
  Melder-Schreibschritte unabhängig von seiner gespeicherten Funktion/Rolle.
  Das Konto wird dabei nicht umbenannt oder umklassifiziert;
  seine echte Identität, Funktion und Rolle bleiben in Datensatzprovenienz und
  Audit sichtbar.

`LOOSE` ist kein globaler Kompatibilitäts- oder Debugschalter. Der Modus kommt
ausschließlich aus der unter Transaktionssperre gelesenen Einsatzzeile und wird
in der gemeinsamen Statusanzeige sichtbar gemacht. Ein während eines offenen
Requests geänderter Einsatz-, Modus- oder Revisionsstand lässt den Writer
konfliktbehaftet scheitern, statt unter einer veralteten Berechtigung zu
committen. Fehlt ein eindeutiger Moduskontext, gilt fail-closed `STRICT`.

Die Lockerung betrifft nur funktions-/rollenbezogene **Schreib**prüfungen.
Authentisierung, exakte SID und Kontenidentität, Kontosperre,
Zugangsschichtentzug, aktiver offener Einsatz und Einsatzscoping, CSRF,
Eingabevalidierung, Einsatz-/Nachrichtenbezug, Workflow- und Transportzustände,
Sperrinhaberschaft, Nummerierung, Append-only-Regeln, Anhangintegrität,
Ereignisketten, Audit und Aufbewahrung gelten unverändert. Allgemeine
Leserechte werden durch den Modus nicht erweitert. Bei einem Melderauftrag
bleiben insbesondere Eignung des beauftragten Kontos, Auftragszustand und
Rückkehrbindung erhalten; nur die funktionsbezogene Zuständigkeit des
disponierenden oder bestätigenden Kontos kann im lockeren Modus entfallen.
Rollenstrenge Übersichten, Nachweisung, Zweitsichtungsarchive,
Kategorienverwaltung und administrative Rechte sind ausdrücklich nicht Teil
der Lockerung. Die begrenzte Objektsicht, die zum Bearbeiten einer explizit
gewählten Workflowstufe nötig ist, gilt nur in diesem Schreibkontext.
Bei einer funktionsübergreifenden Korrektur umfasst sie ausschließlich
Anlagen, die mit genau der autorisierten Status-10-Nachricht verknüpft sind.
Karten, Vorschau, passiv gerenderte E-Mail und Download bauen den Scope aus
der aktuellen Datenbankzeile neu auf und widerrufen ihn bei Einsatz-, Modus-
oder Statuswechsel. Ein originloser Archivanhang oder eine andere Nachrichten-
ID erfüllt diese Grenze nicht.

Anlegen oder Umstellen auf `LOOSE` erfordert im Basic-Auth-/CSRF-geschützten
Administrationspfad eine ausdrückliche Warnungsbestätigung. Moduswechsel sind
nur bei offenen Einsätzen möglich, binden erwartete globale Revision und
erwarteten Altmodus und schreiben Vorher-/Nachherwert in das unveränderliche
Einsatz-Audit. Datenbanktrigger blockieren unmarkierte beziehungsweise
versehentliche direkte Legacy-Updates sowie eine unbestätigte Neuanlage mit
`LOOSE`. Der dafür verwendete Sessionmarker ist ein Kohärenzvertrag für den
vertrauenswürdigen Anwendungs-Principal, keine Privileggrenze gegen einen
SQL-Principal mit beliebigen Schreibrechten. Für Regelbetrieb und formale
Funktionstrennung bleibt `STRICT` die empfohlene Einstellung.

### Einsatzgebundene Führungsstellenidentität

`nv_einsaetze.fuehrungsstellenname` ist die autoritative lokale
Anschrift/Absendereinheit eines Einsatzes. Einsatzname, Bedarfsträger und
Einsatzleitung sind getrennte Tatsachen; weder diese Felder noch Browserdaten
oder eine Umgebungsvariable dürfen als Ersatz dienen. Neue Einsätze verlangen
den Wert bereits beim Anlegen.

Migration 97 lässt bestehende Einsätze absichtlich `NULL`. Ein offener
Alt-Einsatz bleibt bis zur einmaligen administrativen Bestätigung für weitere
operative Eingaben gesperrt und kann ohne Namen nicht neu aktiviert werden.
Die `NULL`-zu-Wert-Erstbestätigung ist trotz vorhandener historischer
Fachdaten möglich. Ein bereits belegter Name darf nur vor dem ersten
operativen Datensatz korrigiert werden; danach sowie bei formal
abgeschlossenen Einsätzen ist er unveränderlich.
`fuehrungsstellenname_gesperrt` hält diese Grenze dauerhaft fest; Löschen
einzelner Fachdaten kann sie nicht zurücksetzen. Der zentrale
Anwendungs-Writer und die Legacy-DB-Trigger validieren den Namen und setzen
den Marker atomar in derselben Transaktion wie die erste Fachänderung.
Direkte Änderungen an Name oder Marker werden durch DB-Trigger blockiert.
Erwarteter Altwert, Transaktionssperre und Einsatz-Audit schützen die
Adminänderung zusätzlich gegen veraltete parallele Formulare.

Die Status- und Führungsstellenansichten zeigen den Namen als eigene
HTML-escaped Identität. Fehlt er bei einem historischen aktiven Einsatz,
erscheint der rote unvollständige Zustand und der zentrale Schreibguard
schlägt geschlossen fehl. PDF- und Tabellenexporte bleiben für historische
Nachweise lesbar, kennzeichnen den Fehlwert aber ausdrücklich, statt einen
Fallback zu erfinden.

Neue Anwendungspasswörter werden zuerst gegen die von Migration 113 in der
Singleton-Tabelle `nv_kennwortrichtlinie` gespeicherte Richtlinie geprüft und
danach mit Argon2id gehasht. Die Mindestlänge darf 8 bis 128
Unicode-Codepoints betragen und ist initial 12. Serverseitig sind höchstens
1024 UTF-8-Bytes zulässig; das Browserfeld erlaubt 1024 Eingabeeinheiten.
Das Browser-JavaScript zählt die konfigurierbare Mindestlänge exakt in
Unicode-Codepoints; verbindlich bleibt die Serverprüfung. Unicode-Groß- und
Titlecase-Buchstaben,
Unicode-Kleinbuchstaben, Unicode-Ziffern sowie Interpunktions-/Symbolzeichen
können unabhängig verpflichtend sein. Unicode-Steuerzeichen (`\p{Cc}`)
bleiben immer unzulässig; Formatzeichen (`\p{Cf}`), insbesondere ZWJ in
Emoji-Sequenzen, sind erlaubt. Die Datenbankspalte für den resultierenden Hash ist auf 255
Zeichen erweitert.

Die Richtlinie gilt ausschließlich beim Erzeugen eines neuen Hashes durch
administrative Kontoanlage, Reset oder aktivierte Selbstregistrierung.
Historische Klartextwerte und andere eindeutig verifizierbare Alt-Hashes werden
erst nach einer erfolgreichen Anmeldung durch Argon2id ersetzt. Ein
bcrypt-Hash wird nur bei einem eingegebenen Kennwort unter 72 UTF-8-Bytes
automatisch migriert. Bei 72 oder mehr Bytes bleibt er wegen der
Suffixambiguität unverändert; für Argon2id ist ein administrativer Reset nötig,
damit kein erstmals präsentierter Suffix neu gebunden wird. Ein Argon2id-Profil
wird nur hochgestuft, wenn alle Kostenparameter höchstens dem Ziel entsprechen
und mindestens einer niedriger ist. Stärkere oder gemischte Profile werden nie
auf Standardkosten zurückgestuft. Ein Bestandslogin und dieser Rehash prüfen
die aktuelle Richtlinie bewusst nicht rückwirkend;
eine Verschärfung widerruft daher keine vorhandenen Kennwörter oder Sitzungen.

Selbstregistrierung ist standardmäßig ausgeschaltet. Konten werden über den
unabhängig per HTTP Basic Auth geschützten Administrationsbereich mit einer
festen Funktion angelegt. Migration 114 übernimmt den bisherigen ENV-Schalter
nur als Upgrade-Startzustand. Bei einem neuen Baseline-Lauf bindet der Migrator
dagegen einen zweiten, mit der SHA-256 von Migration 114 gesicherten Marker und
setzt nach allen Migrationen die pristine Richtlinienzeile und diesen Marker
in einer atomaren InnoDB-Anweisung auf `DISABLED` beziehungsweise `applied`.
Markerlose Bestandsinstallationen werden nicht rückwirkend umklassifiziert.
Sobald die Administration unter
`/4fadm/self_registration.php` deaktiviert, dauerhaft oder befristet aktiviert,
ist die revisionsgesicherte Singleton-Zeile die einzige Wahrheit. Eine
Freigabe ist kein Ersatz für Netzsegmentierung oder organisatorische
Benutzerfreigabe. Ist sie aktiviert, verwendet sie dieselbe
gespeicherte Kennwortrichtlinie wie die Basic-Auth-geschützte
Benutzerverwaltung und schlägt bei fehlendem oder ungültigem Richtlinienstand
geschlossen fehl. Das getrennte Apache-Basic-Auth-Secret ist keine
Funktionskonto-Anmeldeinformation und wird von dieser Richtlinie nicht
gelesen oder verändert.

Der anonyme Einstieg bindet die fachliche Absicht an streng validierte
Auswahlwerte: Ein exakter, zustandsfreier `GET` darf von der Übersicht
`existing` oder `new` zur Anzeige sowie einen bekannten geschützten
Zielschlüssel vorwählen. Das anschließende `POST`
`existing` darf ausschließlich einen vorhandenen Datensatz
authentisieren, `new` ausschließlich einen noch nicht vergebenen Datensatz
anlegen. Widersprüchliche Modus-/Kennwortbestätigungswerte werden vor dem
Legacy-Controller abgewiesen. Jeder Browser-POST mit Zugangsdaten benötigt
bereits vor der Authentisierung das CSRF-Token der anonymen Sitzung. Tokenlose
historische Ein-/Zwei-Kennwort-Clients sind standardmäßig deaktiviert; die
explizite Ausnahme `ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=true` akzeptiert nur
den alten Request ohne `login_flow` und weist erkannte Cross-Site-Metadaten
weiterhin ab. Auswahlfehler erscheinen im Hauptframe statt in
einem Popup, damit auch eingebettete Browser und assistive Technik die
Rückmeldung zuverlässig darstellen. Die öffentliche Benutzerliste dient nur
zur Vorbelegung; pro Zeile wird genau eine Auswahlaktion übertragen.
Bereits authentifizierte Sitzungen müssen sich vor einer anderen Anmeldung
oder Kontoerstellung abmelden. Bei jedem Bestandskonto muss die übermittelte
Funktion unabhängig vom Sitzungs- oder Präsenzzustand exakt der gespeicherten
administrativen Zuordnung entsprechen. Nur `/4fadm/users.php` darf Funktion
und daraus abgeleitete Rolle unter gemeinsamem Konto-Lock ändern; die
Änderung setzt Sitzungs- und Präsenzstatus samt Sitzungsmetadaten atomar
zurück und wird
auditiert.

`app/navigation.php` ist das kanonische Manifest für die neun operativen
Bereiche. Es definiert Schlüssel, Beschriftung, Reihenfolge, Zielpfad und
Zugriffsklasse einschließlich der erforderlichen Dienstfähigkeit einmalig und
löst den aktiven Bereich aus dem konfigurationsbereinigten Requestpfad auf.
Alle Ziele passieren `estab_application_url()`, interne Links verwenden
`target="_top"` und kein operativer Link öffnet einen neuen Tab.
Administration und Handbuch bleiben als zwei Dienste getrennt von den neun
operativen Bereichen. Das Handbuchziel ist die öffentliche, rein lesende
Weboberfläche `/handbuch/`, die über dieselben URL- und Ausgabebegrenzungen
wie die übrige Anwendung ausgeliefert wird. Das historische PDF von 2011 ist
nur eine im Git-Bestand bewahrte Quelle und kein Laufzeitdienst. Anonym
erscheinen alle elf Einstiege mit
Anmeldehinweis. Nach der Anmeldung zeigt die Navigation anhand der festen
Kontofunktion unmittelbar neun beziehungsweise zehn Links:
Meldungsübersicht ist ausschließlich S2/`LAGE_DOKUMENTATION`, Nachweisung
ausschließlich LdF/`FERNMELDEBETRIEB` oder Fernmelder/`BEFOERDERUNG`
zugeordnet.

Das Root-Menü klassifiziert jedes Ziel als öffentlich, Anwendung oder
Administration und bindet passende Karten an dasselbe Navigationsmanifest.
Geschützte Anwendungsmodule sind anonym als „Anmeldung erforderlich“
gekennzeichnet und mit dem Anmeldeeinstieg verbunden. Dabei wird ausschließlich
ein fester symbolischer `next`-Schlüssel für einen bekannten geschützten
Bereich übertragen und als escaped Hidden-Feld in jedem Schritt genau dieses
Login-Tabs bis zum erfolgreichen Login bewahrt. Ein globaler Sessionwert wird
dazu bewusst nicht verwendet, damit parallele Tabs ihre Ziele nicht
überschreiben. Freie URLs, unbekannte Schlüssel sowie öffentliche oder
administrative Rücksprungziele werden abgewiesen; der Komfortpfad ist daher
kein Open Redirect. Icon und Text bilden gemeinsam genau einen Link und
interne Karten bleiben im selben Browserkontext.

`estab_navigation_require_session()` setzt dieselbe Grenze auch vor direkt
aufrufbare Fachseiten und Downloads. Anonyme GET-/HEAD-Anfragen und
Browserformular-POSTs mit abgelaufener Sitzung erhalten eine 303-Weiterleitung
zum expliziten Bestandslogin; übernommen wird ausschließlich der feste, erneut
validierte Bereichsschlüssel. Die ursprüngliche URL, beliebige Queryparameter
und der Referer werden nie zum Rücksprungziel. Ein nicht verarbeiteter
POST-Inhalt wird durch 303 ausdrücklich verworfen und nicht am Login
wiederholt. Der feste Querywert `interrupted=1` enthält keine Nutzdaten,
aktiviert aber den sichtbaren Hinweis, dass die Eingabe erneut erfasst werden
muss.

`estab_navigation_login_redirect_url()` wählt das Dokument passend zum
Browserkontext. Meldet Fetch Metadata für `Sec-Fetch-Dest` einen `frame` oder
`iframe`, verweist die 303-Antwort direkt auf das Content-Login in
`4fach/mainindex.php`. Die intrinsisch mainframe-lokalen Controller für
Anhänge und Kategorien setzen denselben Modus ausdrücklich als Fallback, auch
wenn ein älterer Client keinen Fetch-Metadata-Header sendet. Ein Top-Level-
Aufruf einer anderen Fachseite erhält dagegen den vollständigen
Zwei-Frame-Arbeitsbereich aus `4fach/index.php`. So wird der Arbeitsbereich
niemals in seinem eigenen rechten Inhaltsframe verschachtelt. `Vary: Cookie,
Sec-Fetch-Dest` hält die kontextabhängigen Redirects auch an HTTP-Caches
getrennt.

Der historische Nachrichtencontroller erkennt außerdem ausschließlich
anonyme, operative GETs mit nichtleerer Query als abgelaufene Seitennavigation.
Er verarbeitet oder übernimmt keinen dieser Querywerte, sondern verwirft sie
vollständig und leitet auf das Content-Login mit dem festen, allowlist-
gebundenen Ziel `messages`. GET-Anfragen mit Zugangsdaten oder den
Login-Metadaten `login_flow`, `next` beziehungsweise `interrupted` werden
bewusst nicht als operative Wiederherstellung eingestuft und bleiben auf der
harten Ablehnungsgrenze. Andere HTTP-Methoden bleiben 403. Eine gültige
Kontositzung verwendet unmittelbar ihre feste Funktion; eine zusätzliche
Auswahlweiterleitung gibt es nicht. Rollen-, Objekt-, CSRF- und
Bildberechtigungen bleiben davon unberührt. Das authentifizierte
Statusfragment verwendet HTTP 401 für eine fehlende oder abgelaufene
Fachsitzung, damit ausschließlich der Sidebar-Poller zum Login wechseln kann.

Die ausgewählten HTML-Controller verwenden
`app/session_ui.php` als gemeinsame Ausgabegrenze. Ohne Fachsitzung zeigt die
Leiste „Nicht angemeldet“, einen Anmeldebutton und dieselbe Bereichsnavigation;
geschützte Links verwenden den sicheren Zielschlüssel. Mit gültiger Sitzung
zeigt sie HTML-escaped Name, Kürzel, Funktion und die serverseitig abgeleitete
Rolle und stellt genau ein CSRF-geschütztes POST-Formular zum Abmelden bereit.
Die ausgewählten Administrationscontroller verwenden die Leiste ebenfalls;
ihre vorgelagerte HTTP-Basic-Authentisierung bleibt unabhängig von der
eStab-Fachsitzung. Der vom Webserver gesetzte `REMOTE_USER` wird auf
Administrationsrouten ausschließlich escaped als technischer Kontext
angezeigt und nie in eine Fachrolle übersetzt.

Dieselbe Grenze stellt `estab_session_ui_abort()` für fachlich abgewiesene
Browserseiten bereit. Ein fehlender Einsatz, eine unzulässige Kontofunktion,
ein abgelaufener Formularvorgang oder eine vorübergehend nicht mögliche
Berechtigungsprüfung behält seinen echten HTTP-Status 4xx beziehungsweise 5xx
und den begrenzten fachlichen Meldungstext. Statt einer ungestalteten
`text/plain`-Sackgasse erscheint jedoch eine responsive Werkzeugseite mit
persistenter `role="alert"`-Meldung, bei bestehender Fachsitzung deren
Identität, Abmeldung und erlaubten Bereichslinks sowie einem festen
Top-Level-Rückweg zur Übersicht. Der Rückweg stammt ausschließlich aus einem
symbolischen Eintrag
des Navigationsmanifests; Request-URL und Referer werden weder reflektiert noch
als Redirectziel übernommen. Innerhalb des Nachrichten-`mainframe` bleibt die
äußere Sidebar maßgeblich und die dort redundante Leiste wird wie bei normalen
Inhaltsseiten unterdrückt. API-, Statusfragment-, Bild-, Download- und andere
Binärendpunkte behalten bewusst ihre knappen maschinenlesbaren
Antwortverträge.

Der Login nennt ein erlaubtes vorgemerktes Fachziel, hält es in jedem
Loginformular tablokal und sendet alle Formulare mit `target="_self"` im
aktuellen Browsing-Kontext ab. Unabhängig vom Zustand bietet er den
Top-Level-Link „Anmeldung abbrechen · Zur Übersicht“. Damit verlassen Nutzer
auch ein Content-Login im `mainframe` zuverlässig zur öffentlichen Übersicht,
und bei einem direkt geöffneten Lesezeichen oder Download-Tab entsteht keine
Navigationssackgasse.

Die Leiste wird nicht aus dem globalen Bootstrap ausgegeben: Binärdownloads,
PNG-Renderer, Readiness und Fehlerantworten bleiben dadurch unverändert. Im
Nachrichtenarbeitsbereich bilden genau zwei moderne `iframe`-Elemente in einem
CSS-Grid: die vollhohe linke `vorgaben`-Sidebar und der rechte `mainframe`.
Die früheren separaten Counter- und Statusframes werden nicht mehr eingebettet.
Der rechte Inhaltsframe entfernt seine für Standalone-Aufrufe vorgesehene
Leiste weiterhin unmittelbar per statischem Inline-Guard, sodass genau eine
sichtbare Identität und ein Logout in der Sidebar verbleiben. Eigenständige
Fachmodule und der Root-Einstieg erhalten die vollständige Variante. Die
historischen eigenständigen Status- und Zählerhelfer gehören nicht mehr zur
erreichbaren Laufzeitoberfläche.

Die Sidebar besitzt bewusst einen eigenen Navigationsmodus. Sie rendert zuerst
eine Statuskarte mit rollenabhängigem Arbeitszähler, Serverzeit und
Aktivitätsübersicht, danach Identität und CSRF-geschützten Logout, anschließend die
rollenabhängigen Fachaktionen und zuletzt die nach fester Kontofunktion
gefilterten neun beziehungsweise zehn Bereichs- und Dienstlinks. Diese Links
stehen ohne `<details>` oder
„Bereich wechseln“-Disclosure dauerhaft bereit. Die Bereichsnavigation und
Aktionsgruppen erzeugen keine eigenen Scrollcontainer; bei geringer
Viewport-Höhe ist das vollständige Sidebar-Dokument die einzige vertikale
Scrollfläche. Der BOS-Bereich behält davon getrennt seinen kompakten,
aufklappbaren Disclosure-Modus im persistenten Navigationsframe. Aktive
Hilfe-/Problem-Popups verwenden weiterhin dieselbe Ausgabegrenze.

Bis einschließlich `42rem` beziehungsweise 672 CSS-Pixel wechselt das
Workspace-Grid auf zwei untereinanderliegende Zeilen mit jeweils `100dvh`:
zuerst die Sidebar, danach der Fachinhalt. Nach einer rollenabhängigen
Fachaktion sendet die gleich-originige Sidebar ausschließlich das feste
Signal `estab:show-content` an den Elternkontext. Dieser prüft Origin, Quelle
und Signalwert, scrollt den Inhaltsframe in den Viewport, setzt den Fokus auf
ihn und blendet dort den mindestens 44 Pixel großen Button „Menü“ ein. Der
Button scrollt die Sidebar wieder vollständig in den Viewport und setzt den
Fokus auf ihren Frame.

`app/sidebar.php` erzeugt die semantische Statuskarte und den begrenzten
Aktualisierungscode. Der GET
`/4fach/vorgaben.php?fragment=status` liefert ausschließlich bei gültiger
Anmeldung, aktivem Einsatz und zulässiger fester Kontofunktion das neue
Statusfragment. Die Sidebar ersetzt nur diesen Knoten
und lädt weder Identität, Navigation noch Aktionsformular neu.
Der Renderer klassifiziert angemeldete Konten zentral als innerhalb von
15 Minuten aktiv oder danach inaktiv; nach 12 Stunden erscheinen sie nicht
mehr als angemeldet. Der GET aktualisiert den Aktivitätszeitstempel nicht und
ruft den Aktivitätsendpunkt nicht auf. Ein erfolgreicher Poll hält deshalb keine
unbeaufsichtigte Sitzung künstlich am Leben. HTTP 401 bedeutet, dass die
Fachsitzung nicht mehr gültig ist, und führt den Top-Level-Kontext zum Login.
Dadurch bleiben Dokument-Scrollposition und Tastaturfokus bei der regelmäßigen
Aktualisierung erhalten; wird der mitersetzte Hinweiston-Schalter fokussiert,
stellt die Aktualisierungslogik seinen Fokus gezielt am neuen Knoten wieder
her. Die Statusabfragen verwenden vorbereitete `mysqli`-Operationen statt der
beendenden Legacy-Helfer. Verbindungs-, Belegungs- oder Queue-Fehler werden
isoliert behandelt, sodass die dauerhafte Navigation weiter gerendert wird.
Der Renderer unterscheidet deshalb serverseitig `current`, `partial` und
`unavailable`. HTTP-, Parser- und Netzwerkfehler des Pollers setzen zusätzlich
den clientseitigen Zustand `stale` mit dem Zeitpunkt des letzten erfolgreichen
Abrufs. Ein `AbortController` beendet jeden Abruf vor dem nächsten Poll
beziehungsweise spätestens nach 15 Sekunden. Unveränderte Queue-, Freshness-
und Sound-Live-Regionen werden in das neue Fragment übernommen, statt
periodisch neu angesagt zu werden; nur echte Zustandswechsel ändern ihren Text.

Die rollenabhängigen Warteschlangenprofile ordnen Fernmelder
`old_que_aw`/`notify_aw.wav`, Si `old_que_si`/`notify_si.wav` und Stab
beziehungsweise FB `old_que_stab`/`notify_stab.wav` zu. Es werden nur
validierte, gleich-originige PCM-WAV-Assets unter `/4fach/audio/` verwendet.
Die erste erfolgreiche Messung initialisiert den betreffenden
`old_que_*`-Sitzungswert, ohne einen Hinweis zu erzeugen. Jede spätere
erfolgreiche Messung ersetzt den Basiswert; nur eine Erhöhung markiert das
Antwortfragment genau einmal. Gleichstand und Rückgang bleiben still, bei
nicht verfügbarer Messung bleibt der letzte erfolgreiche Basiswert erhalten.
Die Basis wird auch bei serverseitig deaktivierten Tönen fortgeschrieben,
damit ein späteres Einschalten keine alten Zuwächse nachmeldet. Ein positiver
Zähler trägt unabhängig vom einmaligen Zuwachsmarker einen dauerhaften
`has-work`-Zustand als nichtakustische Handlungsanzeige.

Der Browser beginnt ohne lokale Freigabe mit ausgeschalteten Hinweistönen.
Erst der explizite Schalter in der Statuskarte startet eine Testwiedergabe und
speichert die Ein-/Aus-Entscheidung originbezogen in `localStorage`. Offene
Tabs übernehmen Änderungen über das `storage`-Ereignis. Eine gespeicherte
Einschaltabsicht wird nach einem Reload zunächst als blockiert dargestellt;
erst eine in diesem Dokument tatsächlich erfolgreiche Wiedergabe setzt den
lokalen Zustand auf bereit. Das einzige
`audio`-Element liegt außerhalb des austauschbaren Statusfragments und bleibt
deshalb über alle Statusaktualisierungen hinweg erhalten. Das neue Fragment
liefert nur den Auslösemarker; die langlebige Browserlogik spielt ihn
ausschließlich bei aktivierter Freigabe ab. Ein sichtbarer, per
`aria-live` ausgegebener Zustands- beziehungsweise Fehlertext und die
hervorgehobene Statuskarte sind der nichtakustische Rückfall, wenn Ton
ausgeschaltet, vom Browser blockiert oder nicht unterstützt wird. Ein
monotoner Generationszähler verwirft asynchrone `play()`-Ergebnisse, sobald der
Nutzer oder ein anderer Tab den Zustand inzwischen geändert hat. Automatische
Tests binden die drei Dateien per SHA-256, parsen RIFF/WAVE und PCM-16 und
prüfen Kanäle, Abtastrate, Framezahl, Dauer, Signalspitze und Mindest-RMS.
Zustandswechsel, parallele beziehungsweise spät auflösende
Wiedergabeversuche und die angeforderte Wiedergabe sind ebenfalls belegt;
nicht automatisierbar bleibt die physische Hörbarkeit auf Lautsprecher oder
Endgerät.

Schreibformulare, bei denen ein globaler Wechsel Daten verlieren kann,
aktivieren explizit `data-estab-dirty-guard`. Die gemeinsame Leiste vergleicht
dann Eingaben, Auswahl- und Dateifelder mit ihrem initialen Zustand und
bestätigt nur Navigation, Markenlink oder Logout. Lokale Submit-, Abbrechen-
und Fachaktionen werden nicht abgefangen. Nach einer serverseitigen
Validierungsantwort markieren Nachrichtenformular, Empfängermatrix und
Zählerreparatur erneut angezeigte, noch nicht gespeicherte Werte mit
`data-estab-dirty-initial`. Die Matrix rendert auch nach einem transaktionalen
Persistenzfehler die validierten POST-Werte erneut, statt sie durch den alten
Datenbankstand zu ersetzen.
Nur ausdrücklich als Anwendungspopup gestartete, gleich-originige Hilfe- und
Problemfenster beziehen zusätzlich das Hauptfenster samt Frames ein. Globale
Links navigieren dort das Hauptfenster; ein bestätigter Logout wird per POST
in dessen Browsing-Kontext ausgeführt, damit keine optisch angemeldete,
tatsächlich bereits ungültige Hauptansicht zurückbleibt. Ein gewöhnlich in
einem neuen Fenster geöffnetes Fachmodul verändert seinen Opener nicht.
Explizite Nicht-HTML-Antworten und Redirects passieren den Ausgabehandler
unverändert.

Bestandslogin, erneute Anmeldung eines bereits aktiven Kontos und die
standardmäßig deaktivierte Self-Registration schreiben ein strukturiertes
JSON-Audit. Es enthält nur die explizit ausgewählten Identitätsfelder, die
validierte direkte IP und `sha256:<64 Hexzeichen>` als korrelierbare
Sitzungsreferenz. Die rohe Session-ID und das Kennwort werden nicht in den
Audit-Builder übernommen. Kontoaktivierung und Audit committen in derselben
Transaktion.

Änderungen der Kennwortrichtlinie durchlaufen unter
`/4fadm/password_policy.php` zwei CSRF-geschützte Schritte: Vorschau und
ausdrückliche Bestätigung. Eine monotone Revision verhindert verlorene
Änderungen aus einem veralteten Browserformular; ein globaler Advisory-Lock
serialisiert Richtlinienänderung, Kontoanlage, Reset und positive
Selbstregistrierung. Die feste Reihenfolge ist bei der Kontoanlage
Zuordnungsrichtlinie → Selbstregistrierungsfreigabe → Kennwortrichtlinie →
Konto → Transaktion. Administration und Registrierung teilen den globalen
Freigabe-Lock. Ein befristetes Konto-INSERT enthält zusätzlich die
DB-UTC-Bedingung im selben SQL-Statement, sodass auch eine exakt während des
Absendevorgangs ablaufende Frist kein Konto mehr erzeugt. Die
Richtlinienzeile und das kennwortfreie Audit `password_policy_updated` mit
Vorher-/Nachher-Konfiguration, Basic-Auth-Akteur und validierter IP committen
gemeinsam. Ein Auditfehler oder Revisionskonflikt lässt den vorherigen Stand
vollständig wirksam. Eine unveränderte Bestätigung erzeugt weder Revision noch
Schein-Audit.

`4fach/logout.php` akzeptiert nur einen angemeldeten POST mit gültigem
Session-CSRF und leitet nach Erfolg mit HTTP 303 zum Anmeldeeinstieg weiter.
Die lokale Sitzung und alle Anwendungs-Cookies werden vor der
Datenbanknachführung beendet. Das Setzen des Benutzerstatus auf abgemeldet ist an
Kürzel und die in der Datenbank gespeicherte SID gebunden. So kann ein später
abgeschickter Logout einer alten Sitzung eine neuere Anmeldung desselben
Kontos nicht deaktivieren. Audit- oder Datenbankfehler lassen die lokale
Sitzung nicht wieder aufleben. Auch hier wird für die historische
Audit-Korrelation lediglich ein SHA-256-Verweis statt der rohen Session-ID
gespeichert.

## Webserver-Härtung

Die Apache-Konfiguration:

- deaktiviert Verzeichnislisten, CGI-Ausführung, Signatur und
  `X-Powered-By`,
- sperrt `app/`, `docker/`, `4fcfg/`, Konfigurationsdateien, Include-Dateien
  einschließlich `*.inc.php`, SQL, Shell- und YAML-Dateien,
- sperrt historische Web-Installer, Konfigurationsschreiber, `phpinfo()`,
  Beispiel- und Backup-Endpunkte; dazu gehören der gesamte URL-Baum
  `/4fbak/`, der alte `/4fach/all_msg.php`-View, alle internen
  `/4fach/Print/`-Ressourcen sowie sämtliche Dateien unter `/4fach/upload/`
  außer dem absichtlich mit HTTP 410 antwortenden Tombstone `upload.php`,
- sperrt direkt aufrufbare Controller und Bibliotheken unter `4fach/`, darunter
  Daten-, Listen-, Menü-, Protokoll-, Logout- und Statushelfer, die internen
  Spracharrays, die alte JavaScript-Demonstration sowie obsolete
  Benutzer-/Datenbankgeneratoren; ihre kontrollierten Dateisystem-Includes
  funktionieren weiterhin,
- verweigert jeden direkten HTTP-Zugriff auf den persistenten `4fdata`-Baum;
  Anhänge und Vordrucke werden ausschließlich nach gültiger
  Anwendungssitzung, aktivem Einsatz, passender fester Kontofunktion und
  erneuter Objektberechtigung durch die geprüften Download-Endpunkte
  ausgeliefert,
- trennt beim Vordruckdownload den unveränderten Archivstream vom ausdrücklich
  gewählten aktuellen Layout-Abzug; beide verlangen denselben abgeschlossenen,
  gedruckten Datensatz des aktiven Einsatzes, der aktuelle Abzug validiert
  zusätzlich die vollständige Empfängermatrix und schreibt keine Datei,
- setzt `nosniff`, `SAMEORIGIN`, eine Same-Origin-Referrer-Policy,
  eingeschränkte Browser-Berechtigungen und eine Content Security Policy.

Die Content Security Policy erlaubt derzeit wegen der historischen Oberfläche
noch Inline-Styles und Inline-Skripte. Der Refresh des
Zwei-`iframe`-Arbeitsbereichs löst die benannten Ziele ohne `eval()` über
`parent[...]` auf; `script-src` benötigt deshalb kein `unsafe-eval` mehr. Die
Richtlinie reduziert Angriffsfläche, ist aber kein vollständiger XSS-Schutz.

Historische `stabinfo`-Seiten benötigen keine Fremdressourcen mehr. Zwölf
extern geladene „kleiner oder gleich“-Grafiken sind durch semantischen lokalen
Text ersetzt; zwei bedeutungslose Zierbilder und ein externer Begriff-Link
wurden ohne Download oder Übernahme fremder Dateien entfernt.

Die wenigen öffentlichen Darstellungshelfer besitzen eine eigene
Eingabegrenze. `button.php` akzeptiert nur die weiterhin verwendeten Typen
`icon`, `push` und `menue`; die beiden Kategorie-Renderer akzeptieren nur
skalare UTF-8-Beschriftungen und eine feste Farbpalette. Schriftgrößen,
Wunschbreiten und Textlängen sind begrenzt, unbekannte oder historische
unbenutzte Varianten liefern HTTP 400. Erfolgreiche Antworten sind immer
syntaktische PNG-Dateien mit `nosniff`.

`info.php` escaped die beiden begrenzten Textparameter als HTML.
`language/german/helptext.php` liefert nur vorhandene Schlüssel aus dem
intern eingebundenen Hilfetextarray; das Array selbst ist per HTTP gesperrt.
Die historischen eigenständigen Status- und Zählercontroller sind für direkte
HTTP-Aufrufe gesperrt beziehungsweise aus der Laufzeitoberfläche entfernt.
Aktueller Datenbank-, Einsatz-, Rollen- und Warteschlangenstatus wird
ausschließlich über das geschützte Sidebar-Fragment von `vorgaben.php`
ausgegeben.

Alte direkte Upload-Endpunkte sind mit HTTP 410 deaktiviert. Der aktive
Anhangpfad liegt direkt im schreibenden Nachrichtenvordruck; der bisherige
Anlagenbereich bleibt als optionale Auswahl bereits hochgeladener Dateien
erreichbar. Beide Controller verwenden denselben Uploaddienst. Er validiert
Dateiname, MIME-Typ, Größe und Metadaten, reserviert Namen transaktional und
verwendet für schreibende Formulare ein Session-CSRF-Token. Der Store ist
bewusst zweiphasig: Phase 1 reserviert den internen Namen in einer ersten
Transaktion als inhabergebundene, nicht lesbare Status-8-Zeile. Eine weitere
kurze Staging-Transaktion hält vor jeder Dateibewegung die erwartete Endung in
dieser Zeile fest. Danach werden die Bytes verschoben sowie SHA-256 und
Bytezahl ohne operativen Einsatz-Lock ermittelt, sodass langsame NAS-I/O keine
Einsatztransaktion hält. Phase 2
beansprucht die Reservierung innerhalb einer kurzen Transaktion vorübergehend
mit Status 2, prüft erwarteten aktiven Einsatz, Reservierungsinhaber und
Kontofunktion erneut und persistiert SHA-256, Bytezahl, Serverzeit, Status 1
und Audit atomar. Ein Rollback dieser zweiten Phase fällt auf die weiterhin
eigene und unsichtbare Status-8-Reservierung zurück.

Die Fehlerbereinigung schließt die ursprüngliche Verbindung und ermittelt den
autoritativen Zustand über eine neue Verbindung; damit wird auch ein aus Sicht
des Clients uneindeutiger `COMMIT` sicher behandelt. Status 1 gilt als
finalisiert und seine Bytes werden nie entfernt. Nur Status 8 mit passendem
Inhaber und Einsatz darf bereinigt werden. Unter `SELECT ... FOR UPDATE` prüft
der Dienst Besitzer und gespeicherte Endung und beansprucht die Zeile atomar
als Status 2, bevor die Transaktion freigegeben und ein Zielpfad gelöscht wird.
Neue Reservierungen verwenden ausschließlich Status 8; deshalb kann kein
Uploader die geprüfte Zeile zwischen Entscheidung und `unlink` wiederverwenden
oder finalisieren. Nach validiertem Pfad werden die Bytes gelöscht und deren
Fehlen bestätigt; erst anschließend setzt eine neue Verbindung die
Cleanup-Zeile auf den freien Status 4. Bei unbekanntem Zustand, unerwartetem
Pfad, hartem Abbruch nach der Beanspruchung oder fehlgeschlagenem `unlink`
wird fail-closed nicht freigegeben: Ein vor der Beanspruchung unbekannter
Zustand bleibt unverändert, nach der Beanspruchung bleibt die
Status-2-Cleanup-Zeile unsichtbar gesperrt. Migration 95 markiert
nur die zu diesem Zeitpunkt bereits vorhandenen Zeilen als Legacy;
Datenbank-Trigger verbieten
für spätere Datensätze diesen Marker, einen finalen Status ohne vollständigen
Nachweis, eine Herabstufung und jede Änderung des einmal gesicherten
Nachweises.

Ein an Konto, aktiven Einsatz, Bearbeitungsart und bei Korrekturen an den
Datensatz gebundenes Einmal-Token schützt die normale Direktupload- und
Nachrichtenfolge vor Replays. Es bewahrt in der Sitzung den vom Server erzeugten
Referenzwert und kennt zusätzlich den Zwischenstand „zur Nachrichtensendung
vorgemerkt“. Dieser Zwischenstand wird durch Schließen und erneutes Öffnen der
Sitzung vor der Nachrichtentransaktion explizit persistiert. Der
Nachrichten-INSERT beziehungsweise die Korrektur speichert im selben Commit
den SHA-256-Hash des Tokens im unveränderlichen Workflowereignis. Ein aus dem
Token abgeleiteter MariaDB-Advisory-Lock serialisiert Nachweissuche und Commit
auch über parallele Worker hinweg. Ein Retry löst das Ereignis nur für exakt
denselben Einsatz, Akteur, Vorgang und gegebenenfalls Korrekturdatensatz auf;
nach erfolgreichem Save folgt eine Weiterleitung statt einer zweiten
Nachricht. Bleibt der Abschluss unbelegt, rendert der Controller den gebundenen
Entwurf mit Prüfanweisung, statt aus einer irgendwo im Einsatz verknüpften
Anlagenreferenz fälschlich einen erfolgreichen Save abzuleiten. Weil die Anlage
vor der fachlichen Nachrichtenvalidierung dauerhaft archiviert wird, bleibt
sie bei einem Validierungsfehler am erneut gerenderten Entwurf. Ein danach
aufgegebener oder gelöster Entwurf entfernt nur dessen Referenz und nicht die
Archivdatei.

Diese Sicherung ist keine verteilte Transaktion über MariaDB, Dateisystem und
PHP-Sitzung. Ein harter Prozess- oder Hostabbruch nach dem Verschieben der
Staging-Bytes, aber vor Finalisierung beziehungsweise `finally`, kann eine
unsichtbare Status-8-Reservierung samt Datei zurücklassen. Wird die reguläre
Bereinigung zwar begonnen, aber nach ihrem atomaren Wechsel von Status 8 auf
Status 2 hart beendet, kann eine verborgene Status-2-Cleanup-Zeile mit noch
vorhandenen oder bereits entfernten Bytes bestehen bleiben; nicht löschbare
Bytes führen absichtlich zum selben gesperrten Zustand. Noch enger ist das
Fenster nach erfolgreicher Anlagenfinalisierung und vor dem persistierten
Session-Checkpoint: Dann kann eine freie Archivdatei ohne dauerhafte
Token-Zuordnung bestehen und ein Retry eine weitere Datei archivieren. Sobald
der Nachrichten-Commit den unveränderlichen Aktionsnachweis enthält, schützt
dieses Ereignis in Verbindung mit dem Advisory-Lock den
Nachrichtenspeicherpfad auch bei einem Workerabbruch zwischen Datenbank-Commit
und abschließender Sessionaktualisierung vor einer Doppelnachricht.

Die Upload- und Auslieferungsgrenzen behandeln `jpg`/`jpeg` sowie `tif`/`tiff`
konsistent; Groß-/Kleinschreibung wird normalisiert, der Dateiinhalt aber mit
Fileinfo erneut geprüft. Die Image-Erstellung verlangt außerdem ausdrücklich
Fileinfo sowie JPEG-Unterstützung in GD. Ablehnungen wegen Endung, erkanntem
Typ oder Größe erscheinen als feste, HTML-escaped Benutzerhinweise. Auch wenn
PHP eine Datei bereits vor dem atomaren Store wegen seiner Größenbegrenzung
abweist, gibt der Controller die sitzungs- und einsatzgebundene Reservierung
gezielt frei.

Für `.eml` verlangt der gemeinsame Uploaddienst zusätzlich den von Fileinfo
erkannten Typ `message/rfc822` und eine erfolgreich begrenzt geparste
RFC-822-/MIME-Struktur. Eine bloß umbenannte Datei oder Outlooks proprietäres
`.msg`-Format wird nicht akzeptiert. Der Parser arbeitet unabhängig vom
globalen Uploadlimit mit einer festen Eingabegrenze von 20 MiB, auch wenn
`ESTAB_UPLOAD_MAX_BYTES` auf bis zu 50 MiB erhöht wurde. Damit bleibt der
zusätzliche Speicher- und CPU-Aufwand verschachtelter MIME-Nachrichten auf
kleineren NAS-Installationen begrenzt.

Auch erst beim Abruf oder Lesen eines vorbereiteten Resultsets gemeldete
MariaDB-Deadlocks und Lock-Timeouts werden als Datenbankfehler normalisiert,
zurückgerollt und innerhalb der begrenzten Reservierungsversuche erneut
ausgeführt; sie können daher weder als PHP-Fatal enden noch eine falsche
Folgenummer erzeugen.
Die Dateiauslieferung akzeptiert nur freigegebene Bereiche, Basenames und
Dateitypen, löst Pfade unterhalb des erwarteten Wurzelverzeichnisses auf und
verwirft ausbrechende Symlinks. Direkter Zugriff auf hochgeladene Bytes bleibt
auf Apache-Ebene eine zweite Schutzlinie. Die ausdrückliche Inline-Ausgabe ist
nach Fileinfo-Prüfung auf PDF, JPEG, PNG, GIF und BMP begrenzt; TIFF und alle
anderen zulässigen Formate bleiben Downloads. Normale und Inline-Ausgabe
verwenden denselben autorisierten, integritätsgeprüften Snapshot sowie
`no-store`, `nosniff` und eine restriktive Content-Security-Policy. Die
PDF-Karte bettet eine verifizierte PDF-Antwort ausschließlich Same-Origin und
erst nach ausdrücklichem Aufklappen ein; ob sie sichtbar dargestellt werden
kann, hängt zusätzlich vom PDF-Viewer des Browsers ab. Eine CSP- oder
Iframe-Sandbox wäre hier kein zusätzlicher Schutz, sondern würde Chromiums
eingebauten PDF-Viewer technisch sperren; alle Nicht-PDF-Antworten bleiben für
Einbettung gesperrt. Die automatische Bildminiatur wird nur für JPEG, PNG, GIF
und BMP versucht. Sie liest höchstens 24 MiB, dekodiert höchstens 16
Megapixel und erzeugt maximal 1.600 Pixel je Ausgabeachse; die Karte fordert
konkret 640 Pixel Breite an. Bei größeren oder nicht dekodierbaren Bildern
bleibt die Karte mit neutralem Platzhalter, Download und zulässiger separater
Browseransicht verfügbar. Diese interaktive Grenze ist eigenständig und nicht
die 12-Megapixel-/8.000-Pixel-Grenze des PDF-Dossier-Renderers.

Die E-Mail-Anzeige ist kein rohes Inline-Streaming. Der eigene Controller
`/4fach/email.php` akzeptiert ausschließlich GET und HEAD für eine kanonische
`.eml`-Anlagenreferenz. Er gibt den Session-Lock vor der Dateiarbeit frei,
autorisiert das Anlagenobjekt vor und nach dem stabilen Integritätssnapshot und
liefert nur eine neu erzeugte, escaped UTF-8-Seite. HTML-Teile werden zu
passivem Text: Skripte, Ereignisattribute, Formulare, Objekte, eingebettete
Medien und Remote-Ressourcen der Mail werden weder in die Antwort übernommen
noch nachgeladen. Enthaltene MIME-Anlagen erscheinen ausschließlich als
Metadaten. `no-store`, `nosniff`, `SAMEORIGIN`, `no-referrer`, `noindex`, eine
restriktive Content-Security-Policy und der Antwortmarker
`X-eStab-Email-Rendering: passive-text` begrenzen die Ansicht zusätzlich.

Mail-Kopfzeilen sind nicht vertrauenswürdiger Inhalt und werden nicht als
Identitätsnachweis behandelt. Die Oberfläche nennt ausdrücklich, dass Absender
und Authentizität nicht verifiziert werden und keine DKIM- oder S/MIME-Prüfung
erfolgt. Der getrennte Originaldownload verwendet die normale zweifache
Objektprüfung und den integritätsgebundenen Byte-Snapshot, bleibt aber eine
Download-Disposition. Bytegleichheit macht die Originalmail nicht
ungefährlich; sie kann aktive Inhalte oder riskante interne Anlagen enthalten.

Die Dateiberechtigung wird getrennt von dieser Pfad- und Integritätsprüfung
ermittelt. Ein verknüpfter Anhang erbt die Leseberechtigung mindestens einer
exakt über ein vollständiges, semikolongetrenntes Dateinamens-Token
referenzierten Nachricht. Ein freier Anhang ist ausschließlich für seinen
Uploader oder ein angemeldetes Konto mit der festen Funktion S2, Si
beziehungsweise LdF sichtbar. Direkter Upload, Liste, Download,
Browseransicht, passive E-Mail-Ansicht, Bildvorschau, Archivauswahl im
Nachrichtenvordruck und der
abschließende Nachrichtenspeicherpfad prüfen diese Berechtigung jeweils
erneut. Das Entfernen im bearbeitbaren Vordruck löst lediglich ein exaktes
Referenztoken; es löscht weder Dateizeile noch Archivbytes. Eine
Teilzeichenfolgensuche oder ein lediglich zuvor angezeigter Dateiname genügt
nicht.

`app/attachment_integrity.php` liest reguläre Dateien inode- und
größenstabil und vergleicht bei neuen Anhängen den Inhalt mit dem
persistierten Eingangsnachweis. PDF-Dossier, administrativer Tabellenexport,
produktiver Abschluss-Preflight, authentifizierter Direktdownload,
Bildvorschau und passive E-Mail-Ansicht verwenden dieselbe Grenze. Download
und Vorschau kopieren nach
der Identitätsprüfung die Sitzungsdaten und geben den PHP-Session-Lock frei.
Sie autorisieren zunächst kurz gegen die Datenbank, schließen diese
Transaktion vor Hashing und Kopieren der Datei und autorisieren unmittelbar
vor dem Start der Ausgabe das unveränderte Anlagenobjekt samt
Berechtigungsversion mit aktuellen, sperrenden Lesezugriffen erneut.
Dadurch blockiert ein großer NAS-Abruf weder die Sitzung noch Datenbankzeilen;
eine bis zu dieser Abschlussprüfung wirksame Änderung von Objekt oder Rechten
verhindert die Auslieferung. Nach dem Abschluss-Commit gilt die übliche Grenze
einer bereits freigegebenen HTTP-Antwort: Eine spätere Sperrung kann schon
begonnene Antwortbytes nicht rückwirkend zurücknehmen. Für Download und
Vorschau wird die autorisierte Quelldatei nur einmal geöffnet, unter
gemeinsamer Dateisperre in
einen privaten temporären Stream kopiert und genau dieser Stream geprüft,
zurückgespult und ausgeliefert beziehungsweise dekodiert. Eine
Pfadsubstitution oder spätere Änderung der Quelldatei kann deshalb nicht die
bereits geprüften Antwortbytes ersetzen. Eine fehlende oder abweichende neue
Datei schlägt vor den Binär-/Bild-Headern geschlossen fehl. Bei
Upgrade-Altbeständen
wird nur die Verfügbarkeit geprüft und ausdrücklich
„Integrität beim Eingang nicht belegbar“ ausgegeben; ein Hash der heutigen
Bytes wird nicht als rückwirkender Eingangsbeweis umgedeutet. Auch der
administrative POST zum formalen Einsatzabschluss reicht den konfigurierten
Ablageroot zwingend an denselben Preflight weiter. Ein formal gültiger
Datenbanknachweis bei fehlenden oder gleichlang manipulierten Bytes führt
deshalb zu HTTP 409, bevor der Einsatz abgeschlossen wird.

Das einsatzbezogene PDF-Dossier besitzt neun unabhängig wählbare Abschnitte:
ETB, TBB, Nachrichtenvordrucke, Anhänge, Nachrichtenereignisse,
Dienstbetrieb, S6-Fernmeldepläne, Melderläufe und Betriebsereignisse. Es liest
den ausdrücklich gewählten aktiven oder historischen Einsatz aus einem
konsistenten Read-only-Snapshot. Die Anwendung belässt MariaDB bei der
Standardisolation `REPEATABLE READ`; der Export startet ausdrücklich eine
read-only Transaktion mit konsistentem Snapshot. Nachrichtenseiten verwenden denselben
Vordruckrenderer wie der Einzelabruf; neue Anhänge werden vor dem Laden und
vor dem Einbetten erneut gegen SHA-256 und Bytezahl geprüft.
Führungsstellenname, Einsatzkennung und Einsatzname werden getrennt in
Auswahl, Deckblatt und Seitenkopf geführt. Ein historischer `NULL`-Wert heißt
sichtbar „historisch nicht erfasst“.

Der anschließend ausschließlich aus diesem geprüften Byte-Snapshot gebildete
Anlagenabschnitt gibt JPEG, PNG, GIF und BMP sichtbar aus; mehrseitige PDFs
werden mit festen Poppler-Binärdateien ohne Shell und ohne Ausblenden von
Anmerkungen einzeln seitenweise gerastert. Text erscheint nur bei verlustfreier
Windows-1252-Darstellbarkeit, sonst als eindeutige Hinweisseite.
Strukturell gültige RFC-822-E-Mails werden innerhalb der PDF-Text- und
Zeichengrenzen auch hier nur passiv mit ausgewählten Kopfzeilen und Textkörper
dargestellt; ihre internen MIME-Anlagen werden als Metadaten aufgelistet und
nicht als eigenständige Dossierinhalte ausgeführt. Überschreitung oder nicht
verlustfrei darstellbarer Text führt zu einer eindeutigen Hinweisseite.
TIFF und andere
nicht statisch darstellbare Formate erhalten ebenfalls eine Hinweisseite. In
allen Fällen bleibt der geprüfte Byte-Snapshot zusätzlich als bytegleiches
`EmbeddedFile` im PDF-Katalog erhalten.

Fileinfo ermittelt den MIME-Typ atomar aus genau dem bereits stabil gelesenen
und später eingebetteten Byte-Snapshot. Deklaration, Snapshot-MIME und bei
darstellbaren Formaten die Dateiendung müssen zusammenpassen. Private
Temporärverzeichnisse und feste Grenzen von 50 MiB Originalsumme, 24 MiB
Rasterdaten, 8 MiB je isoliertem PDF-Seitenprozess, 12 Megapixel je Bild,
8.000 Pixel je Achse, 60 Sekunden Gesamtzeit und höchstens 15 Sekunden je
`pdfinfo`-Aufruf beziehungsweise PDF-Seitenprozess begrenzen Decoder und
Kindprozesse.

Der normale `finally`-Pfad entfernt den privaten Arbeitsbereich sofort. Ein
Startup-Janitor behandelt ausschließlich den Absturzfall und löscht nur mehr
als 24 Stunden alte, kanonisch benannte, `www-data` gehörende
`0700`-Verzeichnisse, nachdem er jedes flache Kind auf erlaubten Namen,
regulären Dateityp, Linkzahl eins sowie Modus `0600`/`0640` geprüft hat. Bei
jedem unerwarteten Objekt bleibt der gesamte Kandidat unangetastet.

Zusätzlich wählt die Administration den ETB-/TTB-Umfang als Gesamtbuch oder
als eine zum Einsatz gehörende Dienstschicht. Die Auswahl wird innerhalb des
Snapshots nochmals auf Einsatzzugehörigkeit geprüft und als vorbereiteter
`estab_shift_id`-Filter ausschließlich auf ETB und TBB angewendet. Alle anderen
gewählten Sektionen bleiben einsatzweit. Der aufgelöste Umfang samt
Schichtmetadaten fließt in Deckblatt und `pdf_export`-Audit ein.

Der Administrations-Export erzeugt CSV-Dateien anwendungsseitig; der
MariaDB-Benutzer benötigt dafür kein globales `FILE`-Privileg. Die Oberfläche
listet die atomar veröffentlichten ZIP-Läufe neueste zuerst und zeigt das
begrenzt validierte Manifest, Datensatzanzahlen und Prüfsummen. Download ist
eine Basic-Auth-geschützte, ausschließlich lesende GET-Aktion mit streng
validierter symbolischer Laufkennung; die Datei wird vor der HTML-Pufferung als
reguläres, nicht verlinktes Root-Kind geöffnet. Erstellung und irreversible
Einzellöschung verwenden dagegen POST, Session-CSRF, eine feste
Aktions-Allowlist und anschließend HTTP 303. Löschen entfernt nur das exakt
zugehörige flache Laufverzeichnis und ZIP; Symlinks, Unterverzeichnisse,
Traversal und fremde Dateien werden abgewiesen.

## Nachrichtenablage und Workflow-Grenzen

`app/message_repository.php` ist die gemeinsame Datenbankgrenze der aktiven
Nachrichtenpfade. Frei eingegebener Inhalt und Vermerke werden als rohe
UTF-8-Daten gespeichert; SQL-Werte gelangen ausschließlich als gebundene
Parameter in vorbereitete Statements. Nur fest erlaubte Nachrichtenspalten
und syntaktisch validierte Tabellennamen dürfen Identifier werden. Audittexte
enthalten bei diesen Vorgängen nur die positive `message_id`, nicht den
Nachrichteninhalt.

Beim Anlegen und bei den betroffenen Übergängen sperrt dieselbe
Schreibtransaktion den aktiven Einsatz und bindet die lokale
Nachrichtenidentität an dessen Führungsstellennamen: Ein Eingang erhält ihn
als Anschrift, ein Ausgang einschließlich einer neu angelegten Gesprächsnotiz
als Absendereinheit. Manipulierte Formfelder, ein veralteter
Formularstandard oder eine Prozessumgebung können diese Werte nicht
überschreiben. Fehlt der Name, bricht der gesamte fachliche Schreibvorgang
ohne Teilcommit ab.

Gesprächsnotizen entstehen als offene Ausgänge. Der Verfasser kann keine
Sichter-, LdF- oder Fernmelderwerte vorgeben: Si ergänzt die formale Prüfung,
LdF Rufname und freigegebenen S6-Weg, der Fernmelder den tatsächlichen
Beförderungsnachweis. Erst der letzte Übergang erzeugt TTB-Verknüpfung und
PDF-Vordruck. Historische/importierte Gesprächsnotizen in der alten
Eingangsform bleiben lesbar, erhalten aber keinen rückwirkend erfundenen
Transportnachweis.

Rufname und aktive S6-Plan-ID sind nicht nur Formularpflichtfelder. Die
Repository-Transaktion validiert beide erneut, normalisiert den Rufnamen als
einzeiligen UTF-8-Wert und bricht bei einem fehlenden oder ungültigen Wert ohne
Statuswechsel oder Teilpersistenz ab.

Bestandsdaten aus älteren Releases können bereits HTML-Entities enthalten.
Die Ausgabeschicht decodiert diese Kompatibilitätsdarstellung genau einmal und
escaped anschließend für den jeweiligen HTML-Kontext. Quotes, gewöhnliche
Ampersands, Markup und SQL-ähnliche Zeichenfolgen werden weder zu SQL noch zu
ausführbarem HTML. Listen, Detailformular, Einsatzleitungsübersicht und
PDF-Erzeugung verwenden diese Grenze; PDF-Text wird nach dem Decode als Text
und nicht als HTML behandelt.

Diese Kompatibilität hat eine unvermeidbare Mehrdeutigkeit: Das bestehende
Schema besitzt keinen Encoding-Marker pro Nachricht. Ein alter, codierter Wert
`&amp;` ist deshalb nicht von derselben, in einer neuen Nachricht wörtlich
eingegebenen Zeichenfolge zu unterscheiden; Gleiches gilt etwa für `&lt;`.
Solche entity-förmigen Literale werden als Legacy-Codierung interpretiert. Eine
verlustfreie Unterscheidung setzt eine spätere, versionierte Datenmigration mit
einem Formatmerkmal voraus.

Der zentrale Controller akzeptiert Detailansichten, Statusänderungen,
Sichtungs-/Transport-Saves, Sperränderungen und Logout nur über POST mit
Session-CSRF. Record- und Kategorie-IDs sind kanonische positive Ganzzahlen.
Vor jedem Nachrichtenpfad werden aktiver Einsatz, konkrete Kontoidentität samt
gespeicherter Funktion/Rolle und Objekt gemeinsam revalidiert. Bei
Schreibwegen entscheidet der einsatzbezogene Modus ausschließlich darüber, ob
die feste Funktion/Rolle zusätzlich als Zuständigkeitsgate wirkt; die
nachfolgenden Leseregeln bleiben in beiden Modi unverändert. Ein normales
Stabs- oder Fachberaterkonto darf
eine Nachricht lesen, wenn seine feste Funktion nach fachlichem Abschluss als vollständiger
Empfänger-Token eingetragen ist oder sie die Nachricht selbst ausgehend
erstellt hat. Si, LdF und Fernmelder dürfen zusätzlich ihre aktuelle Warteschlange
beziehungsweise Sperre sowie
Nachrichten mit ihrer eigenen unveränderlichen Verarbeitungsmarke lesen.
Vordruckliste, aktueller In-Memory-Abzug und Archivdownload erben exakt diese
Objektregel. Ein Sperrinhaber wird über sein validiertes Kürzel gebunden.
Historische GET-Detail- und GET-Mutationsaufrufe werden abgewiesen. Im
lockeren Modus dürfen bekannte Schreibaufgaben von einer anderen festen
Kontofunktion übernommen werden. Die ausdrücklich gewählte Schreibstufe darf
dazu genau die erforderliche Workflow-Objektsicht erhalten; Richtung, Status
und Sperrinhaber bleiben dennoch verbindlich. Bei einer zurückgewiesenen
Ausgangsmeldung entfällt in `LOOSE` auch die Bindung an die ursprüngliche
Verfasserfunktion. Der Ereignisnachweis bewahrt ursprüngliche und neu
verantwortliche Funktion, statt den Wechsel als identische Zuständigkeit
auszugeben. Rein lesende Aufrufe behalten ihre normale Objektregel.

Die einsatzbezogene Meldungsübersicht ist in beiden Modi ausschließlich für
ein festes Konto `S2/Stab` mit `LAGE_DOKUMENTATION` bestimmt. Die Nachweisung
bleibt ausschließlich für die Funktion `LdF` mit `FERNMELDEBETRIEB` oder
`Fernmelder` mit `BEFOERDERUNG` bestimmt. Im strengen Modus schreiben ETB
`ETB/Stab` oder `S2/Stab`, TTB schreibt `Fernmelder`. Im lockeren Modus sperren diese
Funktion-/Rollenmerkmale die entsprechenden Schreibaktionen nicht; die
konkrete Kontenidentität wird weiterhin gespeichert. Anwendung und
Insert-Trigger prüfen in beiden Modi den aktiven Einsatz und ein ungesperrtes
Konto. Eine aktive Schicht oder Besetzungs-ID wird nicht verlangt. Die getrennte
`LAGE_DOKUMENTATION`-Fähigkeit und damit die Meldungsübersicht bleiben
als normales Leserecht ausschließlich S2 vorbehalten.

Die manuelle Kontosperre und ein gegebenenfalls deaktivierter Gruppenzugang
blockieren das betroffene Konto. Sie führen weder zu einem Funktionswechsel
noch zu einer stillen Rechteübertragung auf ein anderes Konto.

Lokale ETB-/TBB-Nummern werden durch genau zwei je Einsatz vorab angelegte
Datenbankköpfe vergeben. Der Einsatz-Insert-Trigger erzeugt atomar `ETB:1` und
`TTB:1`; jeder Buch-Insert sperrt und erhöht nur seinen vorhandenen Kopf. Ein
fehlender Kopf wird nicht im konkurrierenden Buchtrigger repariert, sondern
fail-closed abgewiesen. Globale Legacy-Primärschlüssel sind keine fachlichen
Nummern. Der formale Abschluss hängt seine Buchzeilen innerhalb der
Fachtransaktion an. Eine frühere Schichtaktivierung und schichtbezogene
Eröffnungszeilen sind keine Abschlussbedingung. `UPDATE` und `DELETE`
beider Bücher sind durch Trigger gesperrt; eine Korrektur ist eine neue,
direkt auf das Original verweisende Zeile. Die zehnjährige
Mindestaufbewahrung wird ebenfalls in der Datenbank geprüft. Diese technischen
Grenzen ersetzen keine formale THW-Freigabe des elektronischen
ETB-/TBB-Verfahrens.

Der erste ETB-Eintrag enthält den gespeicherten Einsatzbeginn ausdrücklich im
Text. Eine spätere manuelle ETB-Zeile darf optional genau einen finalisierten,
einsatzgleichen und noch unbenutzten Anhang binden. Seine sichtbare Nummer
`ETB {einsatz_id}-{estab_book_lfd}-1` wird aus Einsatz und erst im Commit
vergebener lokaler Buchnummer abgeleitet; der Upload gilt als eine gebündelte
digitale Einheit des einzigen ETB-Buchstroms. Ein Ablagekennzeichen wie
`EL0001` bleibt getrennt. Der Anwendungswriter sperrt Anhang und bestehenden
Bezug mit `FOR UPDATE`; `UNIQUE(estab_attachment_id)` und der ETB-Insert-
Trigger bilden die zweite Grenze. Die Oberfläche bietet nur finalisierte,
unbenutzte Kandidaten an und durchsucht Volltext, Art, lokale Nummern, Bezüge,
ETB-Anlagennummer sowie Ablage-/Originaldateinamen stets einsatzgebunden.

Migration 111 ergänzte nullable Legacy-Provenienzfelder. Migration 112 ersetzt
den damaligen Schicht-Triggervertrag: Neue manuelle und automatische
ETB-/TTB-Zeilen dürfen Schicht und Schreiberbesetzung `NULL` lassen. Eine
Zugangsschicht wird dort nie eingetragen. Belegte formale Altprovenienz bleibt
unverändert und exportierbar.

Migration 115 ersetzt die sechs zuletzt rollenprüfenden ETB-/TTB-, S6-Plan-
und Melder-Trigger durch modebewusste Fassungen. `STRICT` ist semantisch
identisch zum Stand nach Migration 112. `LOOSE` verlangt weiterhin einen
aktiven offenen Einsatz, ein konkretes aktives und ungesperrtes Konto sowie
alle Zustands-, Identitäts-, Beziehungs- und Unveränderlichkeitsregeln; nur die
Funktions-/Rollenprädikate der schreibenden Konten werden ausgelassen. Die
neue Einsatzspalte selbst ist durch eigene Insert-/Update-Guard-Trigger gegen
unmarkierte oder versehentliche Legacy-DML geschützt. Ein SQL-Principal mit
beliebigen Schreibrechten gilt dagegen als vertrauenswürdig und kann den
Sessionmarker selbst setzen; die Trigger ersetzen deshalb keine getrennten
Datenbankprivilegien.

Migration 113 ergänzt davon unabhängig die globale Kennwortrichtlinie. Sie
akzeptiert nur die eigene InnoDB-/`utf8mb4`-Tabelle mit genau einer
Singleton-Zeile, neun kanonischen Spalten und begrenzten Boolean-/Längenwerten.
Die Auslieferungsvorgabe 12 ohne verpflichtende Zeichenklasse bewahrt das
bisherige Verhalten; die Migration verändert keinen vorhandenen Hash und
meldet keinen Benutzer ab. Readiness und der externe SQL-Verifier prüfen
denselben kanonischen Zustand.

Eine optionale ETB-Bearbeitungszuordnung ist nur Such- und Anzeigehilfe. Sie
erweitert keine Rechte. Webliste, Volltext- und Zuordnungsfilter verwenden den
unveränderlichen Snapshot, der amtliche PDF-Renderer dagegen ausdrücklich
nicht.

Die öffentliche ETB-Referenz ist die einsatzlokale Buchnummer, nicht der
globale Primärschlüssel. Neue Werte werden als kanonische positive
Dezimalzahl ohne führende Nullen validiert und unter dem Einsatz-Lock auf einen
bereits vorhandenen Eintrag desselben Einsatzes aufgelöst. Bei Korrekturen
bleibt intern die direkte Originalzeile gebunden; ihre sichtbare Referenz wird
serverseitig aus deren lokaler Nummer abgeleitet. Historischer Freitext bleibt
unverändert les- und suchbar, wird aber nicht als Graphkante interpretiert.
Die nur lesende Auswertung traversiert ausschließlich den aktiven Einsatz,
merkt bereits besuchte Zeilen und begrenzt die gewünschte Vorwärts- oder
Rückwärtstiefe auf 1 bis 25. Vorwärts bleiben Verzweigungen erhalten;
rückwärts folgt sie der gespeicherten direkten Referenz.

Die optionalen Zugangsschichten erlauben der Administration, Konten eines
Einsatzes in Gruppen zu aktivieren und zu deaktivieren. Die Gruppe verändert
keine Funktion und schreibt keine ETB-/TBB-Personalzeile. Historische formale
Dienstschichten, Besetzungen und Übergaben bleiben davon unberührt als
Legacy-Evidenz erhalten. Nur die PDF-Linien sind für eine anschließende
manuelle Unterschrift vorgesehen; Webaktionen sind keine digitale Signatur.
Schichtmutationen teilen einen globalen Advisory Lock mit der Anmeldung und
zusätzliche kontobezogene Locks mit der Benutzerverwaltung. Deaktivieren und
Entfernen prüfen unter diesen Sperren den Hash genau der bestätigten
Richtlinienwirkung erneut; Entfernen adressiert zusätzlich die konkrete
append-only Mitgliedsintervall-ID. Damit kann weder eine Änderung einer
anderen Gruppe die Vorschau überholen noch ein alter Browserdialog eine
zwischenzeitlich neu angelegte Zuordnung treffen.

Automatische Nachrichtenbeförderung schreibt den exakten TBB-Typ
`nachricht`. Generator, Nachrichtendetail und Dossier ermitteln nur aus diesem
Typ die erste lokale Nachweisnummer. Der Typ ist für automatisch erzeugte,
einsatzgleich verknüpfte Transporte reserviert; PHP-Domäne und Datenbanktrigger
weisen neue manuelle beziehungsweise unverknüpfte Zeilen ab, ohne historischen
Bestand umzuschreiben. Meldungsübersicht, zweite Sichtung und Nachweislisten
verwenden dieselbe Nummer für Anzeige, numerische Suche und Sortierung und
kennzeichnen einen noch ausstehenden Transport ohne Ersatznummer. Eine
LdF-Absenderübersetzung oder
begründete Eingangswegkorrektur erzeugt daneben einen append-only Eintrag des
Typs `korrektur` mit direktem Originalbezug; sie ersetzt die gedruckte
Ursprungsnummer nicht. Der TBB-PDF-Renderer verteilt neue strukturierte Fakten
jeweils nur in ihre Fachspalte und ignoriert dabei die redundante
Kompatibilitätszusammenfassung aus `tbb_aktion`. `tbb_bemerk` ist dagegen eine
eigenständige Bemerkung und erscheint auch bei strukturierten Zeilen genau
einmal in der Betriebsspalte. Nur vollständig unstrukturierter Legacy-Bestand
verwendet `tbb_aktion` als Inhaltsrückfall. ETB druckt die Erfassungszeit, TBB
die fachliche Vorgangszeit. Nur bei formal geschlossenen
Büchern streicht der Renderer den unbeschriebenen Rest der letzten
Formularseite diagonal; ein offener vorläufiger Abzug bleibt fortführbar.
Beide Buchabschnitte können als Gesamtbuch oder für genau eine Dienstschicht
ausgegeben werden. Das Gesamtbuch nimmt auch historische Zeilen mit
unbekannter Schichtprovenienz auf; eine Schichtausgabe enthält nur Zeilen mit
der exakt gespeicherten Schicht-ID. Deckblatt, Seitenzahlen und Buchsignaturen
werden aus dem tatsächlich ausgegebenen Satz neu berechnet, ohne die lokalen
Buchnummern umzunummerieren.

Die einmal autorisierte Einsatz-ID wird bis in jede Warteschlangen-,
Nachweis- und Detailabfrage explizit weitergereicht. Ein paralleler
administrativer Einsatzwechsel kann daher höchstens eine bereits geladene
Ansicht veralten lassen, aber niemals Daten des neuen Einsatzes unter der
alten Berechtigung oder Überschrift anzeigen.

Die fachlichen Zustandsübergänge prüfen ihre Vorbedingung nochmals im
ändernden SQL-Statement. Insbesondere kann nur der aktuelle Sperrinhaber der
Fernmelderstufe
einen weiterhin offenen Ausgang speichern; Sichtung und Sperrreset verlieren
bei einem konkurrierenden Übergang. Read-/Done-Zeilen werden pro
Statustabelle und Meldungs-ID durch einen MariaDB-Advisory-Lock serialisiert.
Der bedingte Insert prüft im selben Statement erneut den exakten Empfänger.
Da historische Statustabellen keinen Unique Constraint garantieren, liefert
die Lesegrenze zusätzlich `DISTINCT`; die Migration löscht vorhandene
Altduplikate nicht stillschweigend.

Die Vergabe der Nachweisnummer hält während `MAX(...)` und Insert einen
datenbank-/tabellenbezogenen Advisory-Lock und eine InnoDB-Transaktion. Dieselbe
Lock-Namensbildung verwendet die administrative Zählerreparatur. Damit kann
eine Reparatur nicht parallel an einem regulären Writer vorbeilaufen. Der
Wiederanlaufwert liegt nicht als unvollständige System-„Nachricht“ in
`nv_nachrichten`, sondern als hashverkettetes
`message_counter_repaired`-Betriebsereignis mit Objekttyp `EINSATZ` vor; eine
aktive Schicht ist dafür nicht erforderlich.
Normale Nummernvergabe verwendet das Maximum aus echten Nachrichten und diesem
unveränderlichen Nachweis. Dadurch erzeugt die Reparatur weder einen
Status-0-Datensatz noch einen dauerhaften Einsatzabschluss-Blocker.
Idempotente Updates und Sperrfreigaben gelten nur nach einer expliziten
Abfrage des erwarteten Zielzustands als erfolgreich; fehlende, fremde oder
inzwischen weitergeschaltete Datensätze schlagen geschlossen fehl.

Die jeweilige Nachrichtenmutation ist damit gegen konkurrierende
Zustandswechsel geschützt, bildet aber nicht in jedem historischen Workflow
eine gemeinsame Transaktion mit `protokolleintrag()` oder einem anschließend
gesetzten Read-/Done-State. Diese Legacy-Operationen verwenden teilweise eigene
Datenbankverbindungen. Ein Fehler im nachgelagerten Audit oder State rollt eine
bereits erfolgreiche Fachmutation daher nicht zurück; Anwendungsmeldungen und
`nv_protokoll` müssen im Betrieb gemeinsam überwacht werden.

## Administrative Datenänderungen

`app/admin_operations.php` ist die gemeinsame Schreibgrenze für drei aktive
Altwerkzeuge. Konfigurierte Tabellennamen werden als Identifier validiert,
alle Werte gebunden und jeder Vorgang verwendet InnoDB-Transaktionen:

- Die Empfängermatrix wird serverseitig als genau fünf mal vier Positionen
  validiert. Funktionen sind leer oder höchstens sechs alphanumerische
  Zeichen/Unterstriche lang; `Si`, `Fernmelder` und `LdF` bleiben reserviert. Rollen
  sind leer, `Stab` oder `FB`. S2/Stab ist die feste Fähigkeit für Lage,
  Dokumentation und den roten Durchschlag. Autosichtung ist fachlich
  unzulässig; das historische Feld `mtx_auto` wird beim Speichern und bei der
  Migration immer auf falsch gesetzt. Ein transaktionales `DELETE`
  plus 20 Prepared Inserts ersetzt die aktive Matrix. Eine zweite
  DB-gespeicherte 20-Zellen-Tabelle hält genau eine Standardmatrix; Laden ist
  nur ein CSRF-geschützter Editor-Read, während das Ersetzen von aktivem und
  Standardstand in derselben Transaktion erfolgt. Es wird keine ausführbare
  PHP-Konfigurationsdatei mehr erzeugt.
  `app/assignment.php` serialisiert aktives Matrixspeichern, Login,
  Kontoanlage und Neuzuweisung mit einem globalen MariaDB-Advisory-Lock.
  Unter diesem Lock synchronisiert dieselbe Matrixtransaktion geänderte
  serverabgeleitete Rollen und widerruft betroffene Sitzungen. Entfernte
  Funktionen behalten ihre letzte Kontozuordnung als sichtbaren
  Waisenstatus, verlieren jedoch sämtliche Sitzungsmetadaten und können sich
  erst nach administrativer Neuzuweisung wieder anmelden. Matrix, Konten und
  Audit rollen bei jedem Fehler gemeinsam zurück; dynamische Legacy-Tabellen
  werden nicht umbenannt oder gelöscht.
- Die Reparatur des Nachrichtenzählers akzeptiert nur positive Ganzzahlen bis
  `999999999`. Ein MariaDB-Advisory-Lock und `FOR UPDATE` serialisieren
  parallele Admin-Anforderungen und reguläre Nachrichtenschreiber; Werte
  müssen sowohl im gemeinsamen als auch im getrennten Modus strikt über dem
  aktuellen Maximum liegen.
  Ein hashverkettetes `message_counter_repaired`-Betriebsereignis und der
  Audit-Eintrag werden gemeinsam committed; es entsteht keine unevidenzierte
  Systemnachricht.
- Der PDF-Vordruckreset setzt ausschließlich nach einem CSRF-geprüften POST die
  validierte Spalte `x04_druck` zurück und auditiert die Zahl betroffener
  Nachrichten.
- `app/shift_access.php` schreibt optionale Zugangsschichtmutationen als
  hashverkettete Betriebsereignisse mit Objekttyp `ZUGANGSSCHICHT`. Die
  Gruppenmutation, der gegebenenfalls nötige Sitzungswiderruf und das Audit
  committen gemeinsam; Funktion, Rolle und manuelle Sperre bleiben unverändert.

Erfolg wird per HTTP 303 auf eine GET-Ansicht umgeleitet. Validierungs-,
Konflikt- oder Datenbankfehler führen nicht zu Teiländerungen.

## Kategorien und Nachrichtenzuordnung

`app/category.php` ist die gemeinsame Daten- und Berechtigungsgrenze für die
aktive Verwaltung `4fach/katgoedt.php`, die Auswahllisten in
`4fach/katego.php` und das Kategorienband der Meldungsliste. Der Endpunkt
verlangt immer eine gültige eStab-Sitzung, einen aktiven Einsatz und eine
passende feste Kontofunktion mit serverseitig abgeleiteter Rolle. Diese
Kategorien-/Administrationsgrenze bleibt in beiden Einsatzmodi rollenstreng.
`dbtyp` akzeptiert ausschließlich `master`, `fkt` oder `user`:

- Master-Kategorien liegen in den fest konfigurierten Tabellen und dürfen nur
  von der aktuellen Rotkopie oder `Si` verwaltet werden.
- Funktions- und Benutzerkategorien werden ausschließlich aus Funktion und
  Kürzel der validierten Sitzung abgeleitet. Ein Request kann keinen fremden
  Tabellenraum benennen.
- Kategorien und Beschreibungen werden als rohe UTF-8-Daten gespeichert.
  HTML-Escaping geschieht erst beim Ausgeben von Text, Attributen und
  Auswahloptionen.

GET listet Kategorien oder öffnet ein Bearbeitungsformular, verändert aber
keine Daten. Anlegen, Ändern, Löschen und Zuordnen verlangen POST plus
Session-CSRF und enden mit HTTP 303. Alle Werte sind gebundene Parameter,
dynamische Identifier werden strikt validiert, und Kategorie-/Linkänderungen
laufen in InnoDB-Transaktionen. Vor einer Nachrichtenzuordnung prüft die
Nachrichtenablage zusätzlich die vollständige aktuelle Nachrichten-Objektregel:
terminale exakte Empfängerkopie oder eigener Ausgang für normale
Stabs-/FB-Besetzungen, eigene Warteschlange/Sperre oder eigene unveränderliche
Verarbeitungsmarke für Si, LdF und Fernmelder. Eine fremde, lediglich positive
Meldungs-ID reicht deshalb nicht aus. Das Löschen einer Kategorie entfernt
ihre Zuordnungen in derselben Transaktion.

Auch die Kategorienavigation der Meldungsliste verwendet ausschließlich die
positive `lfd`, nicht den frei vergebenen Kategorienamen. Der Controller
akzeptiert nur `alle` oder eine kanonische positive ID; die vorbereitete
Listenabfrage bindet diese ID. Damit bleiben Kategorienamen mit Quotes reine
Daten und können nicht in den SQL-Filter gelangen.

`katgoedt.php` bleibt ein aktiver, vom Apache erreichbarer Fachendpunkt; die
Session-, Einsatz-, Festfunktions-, Rollen-, CSRF- und Objektgrenzen liegen in
PHP und werden durch `LOOSE` nicht gelockert. Nur interne Implementierungs- und
Konfigurationsverzeichnisse werden auf Webserver-Ebene gesperrt.

## Container- und Datengrenzen

- Alle drei Services verwenden `no-new-privileges`.
- Der Datenbankport ist nicht veröffentlicht.
- Secrets werden als Dateien eingebunden und sind aus Git und Build-Kontext
  ausgeschlossen. Die schreibgeschützten Bind-Mounts und alle gemeinsam
  verwendeten Datenvolumes tragen die standardisierte SELinux-Option `z`;
  ausschließlich verwendete Mounts tragen `Z`. Dadurch funktionieren Docker
  und Podman auch bei `Enforcing`, ohne `privileged` oder eine deaktivierte
  Containerkennzeichnung. Der produktive Helfer lässt nur dedizierte,
  ACL-geprüfte Hostpfade zu, bevor Compose ein Relabel durchführen darf.
- Nur Datenbank und One-shot-Migrator erhalten das MariaDB-Root-Secret. Der
  Migrator übergibt es dem Client durch eine private temporäre Optionsdatei,
  nicht als Prozessargument.
- Das Migrationsimage enthält das kanonische Basisschema. Bei leerem
  `nv_*`-Namensraum bindet es dessen SHA-256 vor dem ersten DDL an
  `estab_schema_baselines`; derselbe Checksum darf einen unterbrochenen,
  idempotenten Fresh-Lauf fortsetzen. Ein unprotokollierter Teilbestand ohne
  Kerntabelle blockiert eine vermeintliche Neuinitialisierung; das Deployment
  benötigt keinen Host-Schema-Mount.
- Derselbe Fresh-Lauf legt einmalig den checksumgebundenen Marker
  `114-self-registration-fresh-default` an. Nach Migration 114 schließen ein
  gemeinsames Multi-Table-Update die pristine Selbstregistrierungszeile und den
  Marker; Prüfsummenabweichung oder ein unmöglicher Zwischenzustand verhindern
  Verifikation und App-Start. Ein echtes Upgrade ohne Marker behält den
  kompatiblen Modus `ENVIRONMENT`.
- Jede SQL-Migration ist versioniert und per SHA-256 in
  `estab_schema_migrations` gebunden. Abweichung, SQL-Fehler oder negativer
  Post-Migrations-Schematest verhindern den App-Start.
- Die durch Migration 40 angelegte Standardmatrix trägt eine eindeutige
  Eigentumsmarkierung. Nach einem Abbruch darf nur eine leere oder exakt
  kanonisch gesetzte eigene Tabelle weiterlaufen; manipulierte Inhalte und
  fremde Namenskollisionen bleiben unverändert gesperrt.
- Der App-Entrypoint prüft erforderliche Secrets und Identifier, bevor Apache
  startet.
- Datenverzeichnisse werden mit restriktiver `umask` und ohne
  Script-Ausführung betrieben.
- Der Readiness-Endpunkt prüft nicht nur Prozesse, sondern auch Datenbank,
  Schemakern und beschreibbaren Speicher.
- PHP- und MariaDB-Basen sind auf konkrete Versionen und Multi-Arch-Digests
  festgelegt und werden nur nach einem geprüften Upgrade geändert. Wegen der
  beim App-Build bezogenen Debian-Pakete gilt das Ergebnis dennoch nicht als
  garantiert byteidentisch reproduzierbar; die resultierenden Digests sind
  Teil des Freigabenachweises.
- Die pull-only Distribution verlangt zwei explizite, gemeinsam freigegebene
  App-/Migrator-Referenzen. Der Publish-Workflow ist manuell, global
  serialisiert, an einen geschützten Git-Tag, drei Repositoryvariablen und
  ein Required-Reviewer-Environment gebunden. Er baut und pusht beide
  Indizes jeweils genau einmal digest-only, erzeugt oder überschreibt keine
  OCI-Tags und führt das komplette
  Laufzeit-/Restore-Gate nativ auf amd64 und arm64 aus. Für beide
  Plattformmanifeste werden SPDX-SBOM und Build-Provenance angefordert und
  nach dem Push inhaltlich eingelesen; zusätzlich wird die separat
  veröffentlichte GitHub-Attestation verifiziert. App, Migrator und
  MariaDB-Basis durchlaufen auf beiden Architekturen einen auf vollständigen
  Commit gepinnten Trivy-Lauf, der behebbare hohe oder kritische Befunde
  blockiert. Ausnahmen sind auf den konkreten Binärpfad begrenzt, begründet und
  mit Ablaufdatum versehen. Das erfolgreiche Imagepaar wird zusammen mit
  Compose, digestgebundener Konfigurationsvorlage, Runbooks und Backup-Verifier
  als prüfsummengebundenes Installationspaket veröffentlicht. Ein separates,
  ebenfalls prüfsummengebundenes dauerhaftes Evidence-Asset enthält beide
  nativen Testergebnisse, OCI-Metadaten, SBOM, Provenance,
  Schwachstellenscans, Attestationsbundles und den zum Releasezeitpunkt
  bezogenen Trusted Root. Sichtbarkeit genügt nicht: Der Workflow verlangt
  anschließend `isImmutable=true`, exakt die vier Installations-/Evidence-
  Assets und eine gültige GitHub-Release-Attestation. Für die netzlose
  Vorsorge exportiert der gebundene Helfer App, Migrator und MariaDB-Basis als
  digesttreue Multi-Arch-OCI-Archive und prüft eine administrativ befüllte
  kontrollierte Registry ausschließlich über Digestreferenzen. Der Helfer
  selbst schreibt keine Registry-Tags. `latest` wird weder publiziert noch als
  Deploymentstandard akzeptiert. Eine öffentliche Veröffentlichung bleibt bis
  zur separaten Rechteprüfung des historischen Gesamtbestands gesperrt.

Benannte Volumes sind keine Sicherung. Schutz vor Hostverlust, Fehlbedienung
oder beschädigten Daten bietet nur das getrennte Verfahren unter
[Backup und Wiederherstellung](BACKUP-UND-WIEDERHERSTELLUNG.md).

## Reverse-Proxy-Vertrauen

Ohne `ESTAB_TRUST_PROXY_HEADERS=true` ignoriert die Anwendung
`X-Forwarded-For` für Audit-IP-Adressen und verwendet nur den direkten Peer.
Bei aktivierter Option ist zusätzlich eine nichtleere IP-/CIDR-Allowlist in
`ESTAB_TRUSTED_PROXIES` zwingend. Erst wenn `REMOTE_ADDR` zu einer Regel passt,
werden vollständig syntaktisch gültige IP- und Protokollketten ausgewertet.
Eine fehlende oder ungültige Allowlist bricht fail-closed ab; für nicht
freigegebene direkte Peers bleiben die Header wirkungslos. Catch-all-Netze und
DNS-Namen sind nicht zulässig.

Zusätzlich darf der App-Port bei aktiviertem Vertrauen nur vom eigenen Proxy
erreichbar sein. Der Proxy überschreibt eingehende
`X-Forwarded-For`-/`X-Forwarded-Proto`-Header, terminiert TLS und setzt
Zugriffslimits. Details stehen unter
[Betrieb und Konfiguration](BETRIEB.md#reverse-proxy-und-tls).

## CSRF-Nachweis der Laufzeitoberfläche

Die paketierte Schreiboberfläche besitzt ein geschlossen geprüftes
Controller-Inventar. `tests/php/csrf_security.php` entdeckt jeden direkten
Aufruf der gemeinsamen POST-/CSRF-Grenze und verlangt für abgelehnte oder
abgelaufene Tokens einen spezifischen, mutationsfreien Fehlerpfad, bevor ein
generischer Fehlerhandler erreicht werden kann. Die Controllergrenze
antwortet dabei mit HTTP 403; nur der darin eingebettete historische
Uploaddialog bewertet die insgesamt ungültige Uploadanforderung bewusst mit
HTTP 400. Der Vertrag umfasst:

- `4fach/anhang.php`, `4fach/fuehrungsstelle.php`,
  `4fach/katgoedt.php`, `4fach/logout.php`, `4fach/mainindex.php` und
  `4fach/resetpic.php`,
- `4fadm/export.php`, `4fadm/fuehrungsstelle.php`,
  `4fadm/incident_export.php`, `4fadm/incidents.php`,
  `4fadm/make_fkt.php`, `4fadm/set_number_after_crash.php` und
  `4fadm/users.php`.

ETB und TBB verwenden zusätzlich den gemeinsamen Logbuch-Wrapper; dessen
Delegation an dieselbe CSRF-Grenze und die HTTP-403-Antwort sind im
Logbuch-Sicherheitsvertrag geprüft. Nicht paketierte historische
Formularartefakte sind im unterstützten Containerbetrieb keine
Laufzeitoberfläche: Das Dockerfile kopiert ausschließlich die positive
Runtime-Allowlist, der Runtime-Surface-Vertrag weist verbotene Altpfade zurück,
und notwendige interne Include-Dateien sind über Apache nicht direkt
erreichbar. Die positive Allowlist enthält `/4fach/email.php` als
authentifizierten Darstellungscontroller und `app/email_attachment.php` nur als
per HTTP gesperrte Parserbibliothek.

## Verbleibende Risiken

Die Containerisierung macht aus dem historischen Code keine vollständig neu
entwickelte Anwendung. Für die Freigabe sind insbesondere zu berücksichtigen:

- große Teile der Fachoberfläche bleiben Legacy-PHP und verwenden die
  kontrollierte MySQL-Kompatibilitätsschicht,
- die CSP benötigt für die historische Oberfläche weiterhin `unsafe-inline`,
- eStab besitzt kein anwendungsinternes Rate Limiting. Der vorgeschaltete,
  allein zum App-Port zugelassene Reverse Proxy muss deshalb insbesondere
  Anmeldung und Basic-Auth-Administration begrenzen und Fehlversuche
  überwachen; fehlt diese Betriebsgrenze, bleibt das Risiko automatisierter
  Kennwortversuche bestehen,
- die Read-/Done-Statushelper werden nur hinter dem übergeordneten exakten
  Request-Guard aufgerufen und serialisieren ihre Statusänderung. Die feste
  Kontofunktion wird im übergeordneten Guard geprüft. Diese
  Defense-in-Depth-Annahme muss bei jeder Änderung
  des Aufrufgraphen durch die Guard- und Integrationstests erhalten bleiben,
- es gibt keine Mehrfaktor-Authentisierung; die zentrale Verwaltung kann
  Funktionskonten sperren, entsperren und Kennwörter zurücksetzen, bietet aber
  keine abgestuften Administratorrollen oder externe Identity-Provider,
- HTTP Basic Auth schützt Administration nur zusammen mit TLS ausreichend,
- Einsatz-, Kommunikations- und gegebenenfalls Gesundheitsdaten erfordern
  strenge Zugriffs-, Protokollierungs-, Aufbewahrungs- und Löschregeln,
- der öffentliche Health-Endpunkt verrät nur boolesche Zustände, sollte aber
  trotzdem über Monitoring-Netze beziehungsweise den Proxy begrenzt werden,
- ein beliebiges historisches Datenbankschema besitzt keinen automatischen
  One-Click-Upgrader.

Deshalb lautet das vorgesehene Betriebsmodell: isolierter oder kontrollierter
Netzzugang, TLS-Reverse-Proxy, minimale Benutzerfreigabe, getestete Backups,
kontinuierliche Readiness und eine dokumentierte Fachabnahme jeder
bereitgestellten Version.

## Begründete Schemaentscheidungen

Das aktuelle Basisschema verwendet:

- InnoDB für Transaktionen und Crash-Recovery,
- `utf8mb4_unicode_ci` für einheitliche vollständige Unicode-Speicherung,
- SQL `NULL` statt ungültiger Zero Dates,
- einen durch Compose erzwungenen Strict Mode einschließlich
  `NO_ZERO_DATE`, `NO_ZERO_IN_DATE` und `NO_ENGINE_SUBSTITUTION`,
- einen eindeutigen Anhang-Dateinamen für race-freie Reservierung,
- längere Session-, Passwort-, IPv6- und Dateiendungsfelder,
- einen fail-closed auf `STRICT` voreingestellten, einsatzgebundenen
  Berechtigungsmodus mit Guard- und modebewussten Fachtriggern,
- eine revisionsgesicherte Singleton-Tabelle für die prospektive
  Funktionskonto-Kennwortrichtlinie,
- idempotente InnoDB-/`utf8mb4`-Migration der dynamischen Benutzer- und
  Funktionstabellen bei ihrer Aktivierung,
- Foreign Keys für die unmittelbar einsatzgebundenen operativen Tabellen;
  abgeleitete dynamische Status-/Kategorietabellen bleiben wegen ihrer
  historischen Löschpfade ohne zusätzliche Cascade-Beziehungen.

Die detaillierte Gegenüberstellung zum Legacy-Schema steht in
[`docker/db/README.md`](../docker/db/README.md). Provenienz und unveränderte
Originaldokumentation sind unter
[`migration/README.md`](../migration/README.md) beziehungsweise
[`docs/legacy/README.md`](legacy/README.md) nachgewiesen.
