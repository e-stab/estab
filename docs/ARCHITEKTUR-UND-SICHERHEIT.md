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
   ^                            Start erst nach Exit 0
   |                            des One-shot-Migrators
   +--- migrate (MariaDB-Client, SHA-256, Schema-Verify)
                              |
                              | internes Netz "database"
                              v
MariaDB 11.8  ------------------------  estab_db
```

Der App-Service ist mit dem `frontend`- und dem internen `database`-Netz
verbunden. MariaDB und der kurzlebige Migrator hängen ausschließlich im
internen Netz; der Datenbankport wird nicht veröffentlicht. Standardmäßig wird
auch Apache nur an `127.0.0.1:8080` gebunden.

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
  liegende PDF-Vordruckreset werden vom Apache mit einer zur Startzeit erzeugten
  bcrypt-`htpasswd` geschützt. Das Admin-Kennwort stammt aus einem separaten
  Compose-Secret. Schreibende Admin-Formulare verlangen zusätzlich einen an
  die PHP-Sitzung gebundenen CSRF-Token.

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
Funktion unabhängig vom Onlinezustand exakt der gespeicherten administrativen
Zuordnung entsprechen. Nur `/4fadm/users.php` darf Funktion und daraus
abgeleitete Rolle unter gemeinsamem Konto-Lock ändern; die Änderung setzt
Onlinezustand und Sitzungsmetadaten atomar zurück und wird auditiert.

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
Onlinebelegung, danach Identität und CSRF-geschützten Logout, anschließend die
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
Tests können Dateiformat, Zustandswechsel, parallele beziehungsweise spät
auflösende Wiedergabeversuche und angeforderte Wiedergabe, nicht aber die
physische Hörbarkeit auf Lautsprecher oder Endgerät belegen.

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
Datenbanknachführung beendet. Das Setzen des Benutzerstatus auf inaktiv ist an
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
konsistenten Read-only-Snapshot. Nachrichtenseiten verwenden denselben
Vordruckrenderer wie der Einzelabruf; neue Anhänge werden vor dem Laden und
vor dem Einbetten erneut gegen SHA-256 und Bytezahl geprüft.

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
eine S2- oder ETB-Besetzung mit `EINSATZTAGEBUCH`, TBB-Schreiben eine
A/W-Besetzung mit `BEFOERDERUNG`. Die getrennte
`LAGE_DOKUMENTATION`-Fähigkeit und damit die Meldungsübersicht bleiben
ausschließlich S2 vorbehalten.

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
  ausgeschlossen.
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
  serialisiert, an einen gleichnamigen Git-Tag, zwei Repositoryvariablen und
  ein Required-Reviewer-Environment gebunden. Er baut beide Kandidaten vor dem
  ersten Push, überschreibt keine vorhandenen OCI-Tags und führt das komplette
  Laufzeit-/Restore-Gate nativ auf amd64 und arm64 aus. Für beide
  Plattformmanifeste werden SPDX-SBOM und Build-Provenance angefordert und
  nach dem Push inhaltlich eingelesen; zusätzlich wird die separat
  veröffentlichte GitHub-Attestation verifiziert. App, Migrator und
  MariaDB-Basis durchlaufen auf beiden Architekturen einen auf vollständigen
  Commit gepinnten Trivy-Lauf, der behebbare hohe oder kritische Befunde
  blockiert. Ausnahmen sind auf den konkreten Binärpfad begrenzt, begründet und
  mit Ablaufdatum versehen. Das erfolgreiche Imagepaar wird zusammen mit
  Compose, digestgebundener Konfigurationsvorlage, Runbooks und Backup-Verifier
  als prüfsummengebundenes unveränderliches GitHub-Releasepaket veröffentlicht.
  `latest` wird weder publiziert noch als Deploymentstandard akzeptiert. Eine
  öffentliche Veröffentlichung bleibt bis zur separaten Rechteprüfung des
  historischen Gesamtbestands gesperrt.

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
