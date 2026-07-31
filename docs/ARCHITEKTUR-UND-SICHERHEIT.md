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
| `4fach/` | Nachrichtenvordruck, Anmeldung, Sichtung, Kategorien, Anhänge und Fachoberfläche |
| `4fadm/` | Basic-Auth-geschützte Administration, Systemstatus und Einsatzexport |
| `4fbak/` | aktive dateisysteminterne PDF-Erzeugung, historische FPDF-Komponente und der bereits im letzten Upstream-Release deaktivierte Bildgenerator |
| `stabetb/`, `fmtbb/`, `ubltg/`, `sammlung/` | Einsatztagebuch, technisches Betriebsbuch und Zusatzmodule |
| `app/` | Bootstrap, PHP-/MySQL-Kompatibilität, Authentisierung, ausgewählte Dienstbesetzung, objektbezogene Leseberechtigung, gemeinsame Bereichsnavigation, Sitzungsanzeige und Abmeldung, CSRF, Datum, Nachrichten-/Kategoriezugriff, begrenzte PNG-Renderer, Anhang, Export und transaktionale Admin-Operationen |
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

Die Anwendungssitzung weist nur das Konto nach. Für jeden operativen Lese- und
Schreibzugriff müssen zusätzlich ein aktiver Einsatz und genau eine in der
Sitzung ausgewählte, vom Benutzer persönlich angenommene aktive
Dienstbesetzung bestehen. `select_hat` akzeptiert nur die positive ID einer
eigenen, aktiven und angenommenen Besetzung und speichert exakt diese
serverseitig in der PHP-Sitzung. Jeder normale operative Schreibpfad
revalidiert diese ID gegen Konto, Funktion, Rolle, aktiven Einsatz und aktive
Schicht; eine fremde, abgelaufene oder nur funktionsgleiche Besetzung genügt
nicht. Eine Anmeldung ohne diesen ausgewählten Funktions-Hut bleibt für
operative Daten wirkungslos. Die fachlichen Controller prüfen diese Grenze
serverseitig erneut; ausgeblendete Links oder bereits geladene Seiten gelten
nicht als Berechtigungsnachweis.

Die einzige operative Pre-Hat-Ausnahme ist der Führungsstellen-Bootstrap. Er
zeigt nur Einsatz-/Schichtgrunddaten sowie eigene Besetzungen und erlaubt
persönliche Annahme, persönliche Übergabebestätigung und Auswahl der eigenen
aktiven, angenommenen Besetzung. ETB, TBB, Nachrichten, Anhänge,
Telekommunikationspläne und Melderaufträge bleiben bis zur Auswahl gesperrt.
Öffentliche und unabhängig administrativ geschützte Bereiche folgen weiterhin
ihren eigenen Grenzen.

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

Neue Anwendungspasswörter werden über PHPs `PASSWORD_DEFAULT` gehasht.
Historische Klartextwerte werden nur bei einer erfolgreichen Anmeldung
akzeptiert und dabei transparent durch einen Hash ersetzt. Die Datenbankspalte
ist dafür auf 255 Zeichen erweitert.

Selbstregistrierung ist standardmäßig ausgeschaltet. Konten werden über den
unabhängig per HTTP Basic Auth geschützten Administrationsbereich mit einer
festen Funktion angelegt. `ESTAB_ALLOW_SELF_REGISTRATION=true` ist nur eine
bewusste Kompatibilitätsausnahme und kein Ersatz für Netzsegmentierung oder
organisatorische Benutzerfreigabe.

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
operativen Bereichen. Anonym erscheinen alle elf Einstiege mit
Anmeldehinweis. Nach der Anmeldung, aber vor Hutauswahl, zeigt sie nur die vier
öffentlichen beziehungsweise separat geschützten Ziele und den
Führungsstellen-Bootstrap. Mit ausgewähltem aktivem Funktions-Hut zeigt die
Navigation neun beziehungsweise zehn Links:
Meldungsübersicht ist ausschließlich S2/`LAGE_DOKUMENTATION`, Nachweisung
ausschließlich LdF/`FERNMELDEBETRIEB` oder A/W/`BEFOERDERUNG` zugeordnet.

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
harten Ablehnungsgrenze. Andere HTTP-Methoden bleiben 403. Ist zwar eine
Kontositzung vorhanden, aber noch keine persönlich ausgewählte
Dienstfunktion, führt ein separater 303 bei GET/HEAD ausschließlich zum
Führungsstellenbetrieb; Schreibversuche bleiben 403. Rollen-, Objekt-, CSRF-
und Bildberechtigungen bleiben davon unberührt. Das authentifizierte
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
rollenabhängigen Fachaktionen und zuletzt die nach ausgewähltem Funktions-Hut
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
Anmeldung, aktivem Einsatz und ausgewählter, persönlich angenommener aktiver
Dienstbesetzung das neue Statusfragment. Die Sidebar ersetzt nur diesen Knoten
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
  Anwendungssitzung, aktivem Einsatz, ausgewählter aktiver Dienstbesetzung und
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
Anhangpfad validiert Dateiname, MIME-Typ, Größe und Metadaten, reserviert Namen
transaktional und verwendet für schreibende Formulare ein Session-CSRF-Token.
Beim Finalisieren werden SHA-256, Bytezahl und Serverzeit zusammen mit dem
Statuswechsel atomar persistiert. Migration 95 markiert nur die zu diesem
Zeitpunkt bereits vorhandenen Zeilen als Legacy; Datenbank-Trigger verbieten
für spätere Datensätze diesen Marker, einen finalen Status ohne vollständigen
Nachweis, eine Herabstufung und jede Änderung des einmal gesicherten
Nachweises.
Die Upload- und Auslieferungsgrenzen behandeln `jpg`/`jpeg` sowie `tif`/`tiff`
konsistent; Groß-/Kleinschreibung wird normalisiert, der Dateiinhalt aber mit
Fileinfo erneut geprüft. Die Image-Erstellung verlangt außerdem ausdrücklich
Fileinfo sowie JPEG-Unterstützung in GD. Ablehnungen wegen Endung, erkanntem
Typ oder Größe erscheinen als feste, HTML-escaped Benutzerhinweise. Auch wenn
PHP eine Datei bereits vor dem atomaren Store wegen seiner Größenbegrenzung
abweist, gibt der Controller die sitzungs- und einsatzgebundene Reservierung
gezielt frei.
Auch erst beim Abruf oder Lesen eines vorbereiteten Resultsets gemeldete
MariaDB-Deadlocks und Lock-Timeouts werden als Datenbankfehler normalisiert,
zurückgerollt und innerhalb der begrenzten Reservierungsversuche erneut
ausgeführt; sie können daher weder als PHP-Fatal enden noch eine falsche
Folgenummer erzeugen.
Die Dateiauslieferung akzeptiert nur freigegebene Bereiche, Basenames und
Dateitypen, löst Pfade unterhalb des erwarteten Wurzelverzeichnisses auf und
verwirft ausbrechende Symlinks. Direkter Zugriff auf hochgeladene Bytes bleibt
auf Apache-Ebene eine zweite Schutzlinie.

Die Dateiberechtigung wird getrennt von dieser Pfad- und Integritätsprüfung
ermittelt. Ein verknüpfter Anhang erbt die Leseberechtigung mindestens einer
exakt über ein vollständiges, semikolongetrenntes Dateinamens-Token
referenzierten Nachricht. Ein freier Anhang ist ausschließlich für seinen
Uploader oder eine ausgewählte aktive S2-, Si- beziehungsweise LdF-Besetzung
sichtbar. Liste, Download, Bildvorschau, Auswahl im Nachrichtenvordruck und
der abschließende Nachrichtenspeicherpfad prüfen diese Berechtigung jeweils
erneut. Eine Teilzeichenfolgensuche oder ein lediglich zuvor angezeigter
Dateiname genügt nicht.

`app/attachment_integrity.php` liest reguläre Dateien inode- und
größenstabil und vergleicht bei neuen Anhängen den Inhalt mit dem
persistierten Eingangsnachweis. PDF-Dossier, administrativer Tabellenexport,
produktiver Abschluss-Preflight, authentifizierter Direktdownload und
Bildvorschau verwenden dieselbe Grenze. Für Download und Vorschau wird die
autorisierte Quelldatei nur einmal geöffnet, unter gemeinsamer Dateisperre in
einen privaten temporären Stream kopiert und genau dieser Stream geprüft,
zurückgespult und ausgeliefert beziehungsweise dekodiert. Eine
Pfadsubstitution oder spätere Änderung der Quelldatei kann deshalb nicht die
bereits geprüften Antwortbytes ersetzen. Eine fehlende oder abweichende neue
Datei schlägt vor den Binär-/Bild-Headern geschlossen fehl. Bei
Upgrade-Altbeständen
wird nur die Verfügbarkeit geprüft und ausdrücklich
„Integrität beim Eingang nicht belegbar“ ausgegeben; ein Hash der heutigen
Bytes wird nicht als rückwirkender Eingangsbeweis umgedeutet. Auch der
administrative POST zum Schließen der letzten aktiven Dienstschicht reicht den
konfigurierten Ablageroot zwingend an denselben Preflight weiter. Ein formal
gültiger Datenbanknachweis bei fehlenden oder gleichlang manipulierten Bytes
führt deshalb zu HTTP 409, bevor Schicht oder Besetzungen beendet werden.

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
als Anschrift, ein Ausgang als Absendereinheit; eine interne Gesprächsnotiz
wird entsprechend lokal gebunden. Manipulierte Formfelder, ein veralteter
Formularstandard oder eine Prozessumgebung können diese Werte nicht
überschreiben. Fehlt der Name, bricht der gesamte fachliche Schreibvorgang
ohne Teilcommit ab.

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
Vor jedem Nachrichtenpfad werden aktiver Einsatz, ausgewählte persönlich
angenommene aktive Dienstbesetzung und Objekt gemeinsam geprüft. Eine normale
Stabs- oder Fachberaterbesetzung darf eine Nachricht lesen, wenn ihre
ausgewählte Funktion nach fachlichem Abschluss als vollständiger
Empfänger-Token eingetragen ist oder sie die Nachricht selbst ausgehend
erstellt hat. Si, LdF und A/W dürfen zusätzlich ihre aktuelle Warteschlange
beziehungsweise Sperre sowie
Nachrichten mit ihrer eigenen unveränderlichen Verarbeitungsmarke lesen.
Vordruckliste, aktueller In-Memory-Abzug und Archivdownload erben exakt diese
Objektregel. Ein Sperrinhaber wird über sein validiertes Kürzel gebunden.
Historische GET-Detail- und GET-Mutationsaufrufe werden abgewiesen.

Die einsatzbezogene Meldungsübersicht ist ausschließlich für eine ausgewählte
aktive S2-Besetzung mit `LAGE_DOKUMENTATION` bestimmt. Die Nachweisung ist
ausschließlich für eine ausgewählte aktive LdF-Besetzung mit
`FERNMELDEBETRIEB` oder A/W-Besetzung mit `BEFOERDERUNG` bestimmt. ETB und TBB
dürfen alle ausgewählten aktiven Funktions-Hüte lesen; ETB-Schreiben verlangt
zusätzlich genau die als Schreiber bestimmte Besetzung. Die zuerst
zugewiesene angenommene ETB-Besetzung hat Vorrang, ersatzweise die erste
angenommene S2-Besetzung mit `EINSATZTAGEBUCH`; beim TBB ist es die erste
angenommene A/W-Besetzung mit `BEFOERDERUNG`. Weitere fachlich fähige
Besetzungen bleiben lesend. Die getrennte
`LAGE_DOKUMENTATION`-Fähigkeit und damit die Meldungsübersicht bleiben
ausschließlich S2 vorbehalten.

Der Kontozustand beeinflusst die Designation bewusst nicht: Eine Sperrung oder
Deaktivierung der bestimmten Person blockiert den Writer, statt still die
nächste geeignete Besetzung vorzuziehen. Die Anwendung sperrt und prüft die
konkrete Designation und die Sitzungsidentität erneut. Der Insert-Trigger
bildet die unabhängige Persistenzgrenze und verlangt angenommene Besetzung,
aktive einsatzgleiche Schicht, passende Benutzer-/Kürzel-/Funktionsidentität
sowie ein aktives, ungesperrtes Konto. Erst eine fachlich dokumentierte
Ablösung darf die Schreiberherkunft ändern.

Lokale ETB-/TBB-Nummern werden durch genau zwei je Einsatz vorab angelegte
Datenbankköpfe vergeben. Der Einsatz-Insert-Trigger erzeugt atomar `ETB:1` und
`TTB:1`; jeder Buch-Insert sperrt und erhöht nur seinen vorhandenen Kopf. Ein
fehlender Kopf wird nicht im konkurrierenden Buchtrigger repariert, sondern
fail-closed abgewiesen. Globale Legacy-Primärschlüssel sind keine fachlichen
Nummern. Eröffnung bei erster Schichtaktivierung, bestätigte Übergabe und
formaler Abschluss hängen ihre Buchzeilen innerhalb der jeweiligen
Fachtransaktion an. Ein Abschluss vor mindestens einer aktivierten Schicht und
exakt je einer Eröffnungszeile Nummer 1 ist gesperrt. `UPDATE` und `DELETE`
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

Migration 111 bindet jede neue manuelle ETB-/TTB-Zeile an die gesperrte aktive
Dienstschicht und an die serverseitig ermittelte schreibende
Dienstbesetzungs-ID. Automatische Systemzeilen tragen dieselbe
Schichtprovenienz, müssen ihre menschliche Schreiberzuordnung aber `NULL`
lassen. Bestandszeilen werden nicht rückwirkend einer vermuteten Schicht oder
Person zugeordnet und behalten dort `NULL`.

Ein ETB-Eintrag darf zusätzlich optional eine angenommene Besetzung derselben
aktiven Schicht als Bearbeitungs- und Suchhilfe referenzieren. Die Auswahl-ID
wird unter `FOR UPDATE` erneut geprüft; der Insert-Trigger erzeugt daraus den
unveränderlichen Snapshot `Funktion (Rolle): Name [Kürzel]`. Freier
Browsertext kann ihn nicht ersetzen. Webliste, Volltext- und Zuordnungsfilter
verwenden den Snapshot, der amtliche PDF-Renderer dagegen ausdrücklich nicht.

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

Eine aktive Schicht kann administrativ nur um eine noch nicht belegte Funktion
ergänzt werden; A/W ist die bewusst mehrfach besetzbare Ausnahme. Erst die
persönliche Annahme durch das zugewiesene Konto macht die Erweiterung wirksam
und hängt atomar eine ETB-Zeile sowie für LdF/A/W zusätzlich eine
TBB-Zeile an. Der Austausch einer anderen bereits belegten Funktion bleibt
dem Übergabeverfahren vorbehalten.
Eine ETB-Ergänzung, die eine angenommene ETB- oder S2-Besetzung als bestimmten
Schreiber verdrängen würde, ist in der aktiven Schicht schon bei der
Zuweisung gesperrt. Der Annahmepfad und der Datenbank-Trigger prüfen dies
erneut, damit auch eine noch während der Planung zugewiesene ETB-Funktion nach
Aktivierung nicht still übernehmen kann. Ein Schreiberwechsel erfolgt nur über
die dokumentierte und bestätigte Schichtübergabe.

Die zweistufige Schichtübergabe bindet authentifizierten Initiator und
bestätigende Nachfolgebesetzung: Nur eine persönlich angemeldete, ausgewählte
und angenommene Besetzung der aktiven Schicht initiiert; nur eine persönlich
angenommene Besetzung der Nachfolgeschicht bestätigt. Initiierungs- und
Bestätigungszeit stammen getrennt aus der Datenbankuhr und erscheinen als
Übergabe- beziehungsweise Übernahmezeit in beiden Büchern. Zwei zusätzliche
Datenbanktrigger erzwingen, dass Übernahmebestätigung, abgeschlossener
Übergabenachweis sowie Ende und Beginn der beiden Schichten denselben
Bestätigungszeitpunkt tragen und nicht vor der Initiierung liegen. In die ETB-/TBB-
Zeilen gelangen nach dem Statuswechsel ausschließlich `ABGELOEST`-Besetzungen
der alten und `ANGENOMMEN`-Besetzungen der neuen Schicht, keine bloßen
Planungszeilen. Nur die PDF-Linien sind für eine anschließende manuelle
Unterschrift vorgesehen; die Webaktionen sind keine digitale Signatur.

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
ändernden SQL-Statement. Insbesondere kann nur der aktuelle A/W-Sperrinhaber
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
`message_counter_repaired`-Betriebsereignis der aktiven Dienstschicht vor.
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
  Zeichen/Unterstriche lang, `Si`, `A/W` und `LdF` bleiben reserviert, Rollen
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

Erfolg wird per HTTP 303 auf eine GET-Ansicht umgeleitet. Validierungs-,
Konflikt- oder Datenbankfehler führen nicht zu Teiländerungen.

## Kategorien und Nachrichtenzuordnung

`app/category.php` ist die gemeinsame Daten- und Berechtigungsgrenze für die
aktive Verwaltung `4fach/katgoedt.php`, die Auswahllisten in
`4fach/katego.php` und das Kategorienband der Meldungsliste. Der Endpunkt
verlangt immer eine gültige eStab-Sitzung, einen aktiven Einsatz und eine
ausgewählte, persönlich angenommene aktive Dienstbesetzung.
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
Verarbeitungsmarke für Si, LdF und A/W. Eine fremde, lediglich positive
Meldungs-ID reicht deshalb nicht aus. Das Löschen einer Kategorie entfernt
ihre Zuordnungen in derselben Transaktion.

Auch die Kategorienavigation der Meldungsliste verwendet ausschließlich die
positive `lfd`, nicht den frei vergebenen Kategorienamen. Der Controller
akzeptiert nur `alle` oder eine kanonische positive ID; die vorbereitete
Listenabfrage bindet diese ID. Damit bleiben Kategorienamen mit Quotes reine
Daten und können nicht in den SQL-Filter gelangen.

`katgoedt.php` bleibt ein aktiver, vom Apache erreichbarer Fachendpunkt; die
Session-, Einsatz-, ausgewählte-Dienstbesetzungs-, Rollen-, CSRF- und
Objektgrenzen liegen in PHP. Nur interne Implementierungs- und
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
erreichbar.

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
  Request-Guard aufgerufen und serialisieren ihre Statusänderung, revalidieren
  die ausgewählte Dienstbesetzungs-ID aber nicht nochmals innerhalb derselben
  Helper-Transaktion. Diese Defense-in-Depth-Annahme muss bei jeder Änderung
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
