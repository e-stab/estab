# Laufzeit- und Anmeldeschicht

`bootstrap.php` stellt die kontrollierte PHP-8.5-Laufzeit sowie die noch
benötigten Legacy-Kompatibilitätsfunktionen bereit. `auth.php` bildet die
Sicherheitsgrenze der Benutzeranmeldung. `navigation.php` definiert Bereiche,
Reihenfolge, Zugriffsklassen, aktive Routen und sichere Anmeldeziele zentral.
`session_ui.php` injiziert die gemeinsame, escaped Navigation und
Sitzungsanzeige ausschließlich in ausgewählte HTML-Controller; `logout.php`
kapselt das Beenden und Auditieren der Sitzung. `sidebar.php` rendert die
zusammengefasste Status-, Belegungs- und Hinweistonkarte des
Nachrichtenarbeitsbereichs. `image_button.php` validiert und rendert die
weiterhin öffentlich benötigten Legacy-Bildbuttons. `admin_operations.php`
bildet die vorbereitete, transaktionale Persistenzgrenze für aktive
Empfängermatrix, Standardmatrix, Nachrichtenzähler und Grafikreset.

## Sicherheits- und Kompatibilitätsentscheidungen

- Name, Kürzel, Funktion und Kennwort werden beim Login nur aus `POST` gelesen.
  Die Übersicht darf ausschließlich den darstellenden Konto-Flow per streng
  begrenztem `GET` als `existing` oder `new` vorwählen. Zusätzlich darf `next`
  nur einen bekannten, geschützten Navigationsschlüssel enthalten; eine freie
  URL oder ein öffentliches beziehungsweise administratives Ziel wird
  abgewiesen. Der Schlüssel wird escaped in jedem Formular des betreffenden
  Tabs mitgeführt und nicht in einem tabübergreifenden Sessionwert
  zwischengespeichert. Zugangsdaten und jede Zustandsänderung bleiben `POST`
  plus Session-CSRF. Ein unbekanntes Bestandskonto wird nicht mehr implizit
  registriert; der Neuanlage-Flow meldet kein vorhandenes Konto an.
  Historische Ein-Kennwort-POSTs bleiben reine Bestandsanmeldungen. Der alte
  Zwei-Kennwort-Request mit `2teskennwort=Yes` behält für direkte Legacy-Clients
  sein bisheriges „anmelden oder anlegen“-Verhalten; auch dabei gilt
  `ESTAB_ALLOW_SELF_REGISTRATION`. Tokenlose Legacy-Anmeldungen sind
  standardmäßig gesperrt und müssen bei tatsächlichem Bedarf ausdrücklich mit
  `ESTAB_ALLOW_LEGACY_LOGIN_WITHOUT_CSRF=true` freigeschaltet werden; als
  browserseitig `cross-site` erkennbare Requests bleiben trotzdem gesperrt.
  Die neue Oberfläche verwendet diesen mehrdeutigen Pfad nicht, sondern sendet
  immer einen ausdrücklichen Flow mit sitzungsgebundenem CSRF-Token.
  Die anklickbare Benutzerliste sendet ebenfalls per `POST`; ihr kodierter Wert
  dient ausschließlich zum Vorbefüllen und wird nicht als Authentisierungsnachweis
  betrachtet.
- Namen und Kürzel werden serverseitig validiert. Die Funktion muss exakt in
  `$conf_empf` vorkommen; die Rolle wird ausschließlich aus diesem Eintrag
  abgeleitet. Damit kann ein Client keine Rolle frei mitsenden.
- Alle `SELECT`-, `INSERT`- und `UPDATE`-Operationen des Anmeldepfads sind
  `mysqli`-Prepared-Statements. Der konfigurierbare Tabellenname wird separat
  als SQL-Identifier validiert.
- Neue Kennwörter werden mit `password_hash(PASSWORD_DEFAULT)` gespeichert.
  Ein vorhandenes Klartextkennwort wird nur noch für eine erfolgreiche
  Anmeldung akzeptiert und in demselben Update transparent durch einen Hash
  ersetzt. Moderne Hashes werden bei Bedarf ebenfalls neu gehasht.
- Die Session-ID wird nach erfolgreicher Prüfung und vor dem Speichern der SID
  mit `session_regenerate_id(true)` erneuert; ein Voranmelde-CSRF-Token wird
  dabei verworfen und für die authentifizierte Sitzung neu erzeugt. Beim
  Logout werden Sessiondaten, Session-Cookie und historische `vStab_*`-Cookies
  entfernt; die lokale Session endet auch dann, wenn die anschließende
  DB-Aktualisierung fehlschlägt. Das Datenbank-Update ist an Kürzel und
  gespeicherte Session-ID gebunden, damit eine alte Browser-Sitzung nicht den
  Status einer neueren Anmeldung desselben Kontos deaktiviert. Das Audit
  speichert dafür nur einen SHA-256-Verweis und niemals die wiederverwendbare
  rohe Session-ID.
- `REMOTE_ADDR` wird nur als gültiges IPv4-/IPv6-Literal gespeichert.
  `X-Forwarded-For` wird standardmäßig ignoriert. Nur mit dem strikt geparsten
  `ESTAB_TRUST_PROXY_HEADERS=true` wird eine vollständig validierte IP-Kette
  akzeptiert und deren erster Eintrag als Auditwert gespeichert.
- Selbstregistrierung bleibt für bestehende Installationen standardmäßig an.
  `ESTAB_ALLOW_SELF_REGISTRATION=false` schaltet sie ab. Boolesche
  Umgebungswerte akzeptieren ausschließlich `1/0`, `true/false`, `yes/no` oder
  `on/off`; Tippfehler führen absichtlich zu einem Fehler statt zu implizitem
  Aktivieren.
- `ESTAB_REVIEW_OUTGOING_MESSAGES=false` ist der Containerstandard: Ein vom
  Stab erstellter Ausgang wechselt nach dem Transport durch A/W direkt von
  Status 2 auf Status 8. Mit dem strikt geparsten Wert `true` läuft derselbe
  Ausgang von Status 2 zunächst auf Status 4 und muss anschließend durch Si
  auf Status 8 gesichtet werden. Ist die Umgebungsvariable nicht gesetzt,
  bleibt ein boolescher Legacy-Wert `si_in_out` aus der optionalen
  `4fcfg/m_cfg.inc.php` wirksam; ein nicht boolescher Wert bricht absichtlich
  ab.
- Der sichtbare Einstieg verwendet native Textbuttons, zugeordnete Labels,
  eindeutige Kennwortfelder und Inline-Fehler. Die beiden Konto-Flows bleiben
  getrennte Formulare, sodass ein Moduswechsel keine Zugangsdaten mitsendet.
  Jede Anmeldung und Neuanlage aus der Browseroberfläche erfordert bereits vor
  der Authentisierung ein sitzungsgebundenes CSRF-Token.
- `navigation.php` liefert genau acht operative Bereiche in stabiler
  Reihenfolge. Alle URLs laufen durch den zentralen Anwendungs-URL-Builder,
  interne Links verwenden den Top-Level-Browserkontext statt neue Tabs und
  genau der aus dem Requestpfad abgeleitete Bereich erhält
  `aria-current="page"`. Öffentlich sichtbar bleiben Übersicht und BOS-Info;
  sechs geschützte Bereiche führen anonym mit ihrem symbolischen Ziel zum
  Login. Administration und Handbuch sind getrennte Dienste.
- Nach der Anmeldung rendert `session_ui.php` Name, Kürzel, Funktion und
  serverseitig abgeleitete Rolle HTML-escaped. Vor der Anmeldung rendert
  dieselbe Schicht „Nicht angemeldet“, den Anmeldebutton und die gemeinsame
  Navigation, ohne eine Identität vorzutäuschen. Das Abmelden ist ein
  eigenständiges POST-Formular mit dem CSRF-Token der erneuerten Sitzung und
  `target="_top"` für den übergeordneten Arbeitsbereich beziehungsweise
  Browserkontext. Der zentrale Endpunkt akzeptiert weder GET
  noch fehlende oder falsche Tokens und antwortet nach Erfolg mit HTTP 303.
  Binärantworten und Health-Endpunkte werden nicht durch automatische globale
  Ausgabe verändert. Die ausgewählten Administrationscontroller erhalten die
  gemeinsame Navigation, ihre technische HTTP-Basic-Authentisierung bleibt
  jedoch eine separate Sicherheitsgrenze. Dort wird `REMOTE_USER` ausschließlich
  escaped als Administrationskontext angezeigt und niemals als eStab-Rolle
  übernommen. Der Ausgabehandler lässt explizite Plain-Text-/JSON-Fehler und
  Redirects unverändert; ein normaler Nutztext kann den eindeutigen
  HTML-Marker der Leiste nicht unterdrücken. Der Nachrichtenarbeitsbereich
  besteht aus der durchgehenden `vorgaben`-Sidebar und dem rechten `mainframe`.
  Die Sidebar bündelt Arbeitszähler, Serverzeit,
  Onlinebelegung, Identität, Logout, zehn dauerhaft sichtbare Bereichslinks
  ohne Disclosure sowie rollenabhängige Fachaktionen. Der Hauptframe entfernt
  seine Standalone-Leiste vor der ersten Darstellung. Das regelmäßig
  ausgetauschte Statusfragment lässt Navigation, wiederhergestellten Fokus,
  Scrollposition und das langlebige PCM-WAV-Audioelement bestehen. Positive
  Zähler bleiben hervorgehoben; fehlgeschlagene vorbereitete Statusabfragen
  melden `partial`/`unavailable` und lassen die Navigation intakt. Ein
  begrenzter Poll markiert HTTP-/Netzfehler als `stale`; unveränderte
  Live-Regionen bleiben als DOM-Knoten erhalten. Hörbare Hinweise benötigen je
  Tab eine erfolgreiche Browserfreigabe; die browserweite Ein-/Aus-Absicht
  wird über `localStorage` synchronisiert und verspätete `play()`-Ergebnisse
  werden generationsgebunden verworfen.
- `export.php` veröffentlicht jeden Datenbankexport atomar als streng benannten
  Lauf und ZIP außerhalb des DocumentRoot. Die Administrationsseite listet nur
  reguläre, nicht verlinkte Archive unmittelbar im konfigurierten Exportroot.
  Binäre Downloads werden vor der gemeinsamen HTML-Ausgabepufferung geöffnet
  und unverändert gestreamt. Erstellen und Löschen verwenden eine explizite
  Aktions-Allowlist, POST, Session-CSRF und HTTP 303; die Löschung akzeptiert
  nur die symbolische Laufkennung und entfernt ausschließlich das zugehörige
  flache Verzeichnis-/ZIP-Paar. Symlinks, Unterverzeichnisse und ausbrechende
  Pfade werden nicht verfolgt.
- Die Matrixadministration verwendet keine generierte oder eingebundene
  PHP-Konfiguration mehr. Aktive Matrix und die einzige gespeicherte
  Standardmatrix liegen in getrennten InnoDB-Tabellen und müssen jeweils
  vollständig aus 20 eindeutigen Zellen bestehen. Genau eine belegte
  Stab-/FB-Funktion ist Rotkopie; Rotkopie und Autosichtung sind auf leeren
  oder reinen Textzellen verboten. „Nur aktive Matrix speichern“ ändert nur
  die Laufzeitmatrix, „Standard laden“ ersetzt ausschließlich die noch
  ungespeicherten Editorwerte, und gemeinsames Speichern ersetzt beide
  Tabellen und schreibt den zugehörigen Auditdatensatz in derselben
  Transaktion. Ein Fehler in einer der beiden Tabellen rollt die gesamte
  Änderung zurück; Benutzerkonten oder deren aktuelle Funktionszuordnung
  werden dabei nicht umgeschrieben.
- Der belegte A/W-Zweitprüfpfad `FM-Admin` ist auf ein autorisiertes
  Nachrichtenobjekt und die Fernmelderrolle gebunden. Das Formular bietet
  controllerkompatible Speichern-/Abbrechen-Aktionen und lässt ausschließlich
  Quittierungszeichen, Empfängerfarben und Vermerk bearbeiten. Der
  ursprüngliche Quittierungszeitpunkt ist sichtbar schreibgeschützt und wird
  auch bei manipuliertem POST unverändert aus der Datenbank übernommen;
  Nachrichteninhalt und Transportbelege (Felder 1–14) bleiben ebenfalls
  unveränderlich.
- Bearbeitungsformulare markieren sich ausdrücklich mit
  `data-estab-dirty-guard`. Nur bei geändertem Zustand bestätigt der Browser
  einen globalen Bereichswechsel oder Logout; lokale Speichern-, Abbrechen-
  und Fachaktionen lösen diesen generischen Dialog nicht aus. Die beiden
  destruktiven Matrixaktionen „Standard laden“ und „Standard ersetzen“
  besitzen stattdessen eigene, feste Bestätigungstexte und brechen bei
  Ablehnung ohne Seiten- oder Wertverlust ab. Nachrichtenformular,
  Empfängermatrix und Zählerreparatur markieren serverseitig nach einem Fehler
  erneut angezeigte, noch ungespeicherte Werte zusätzlich mit
  `data-estab-dirty-initial`; die Matrix behält validierte Eingaben auch nach
  einem Persistenzfehler. Nur ausdrücklich markierte Hilfe-/Problem-Popups
  beziehen gleich-originige Formulare ihres Hauptfensters ein und führen
  globale Navigation oder Logout in diesem Hauptfenster aus.
- `root_menu.php` wandelt die historischen Menüdefinitionen in valides,
  escaped Karten-Markup mit genau einem Tastaturziel pro Modul um. Geschützte
  Ziele führen anonym unter Beibehaltung ihres erlaubten Navigationsschlüssels
  zur Anmeldung. Interne Karten öffnen im selben Browserkontext, während
  Administration und öffentliche Dokumentation sichtbar getrennte
  Zugriffsklassen behalten.
- Ein aktives Konto behält seine gespeicherte Funktion. Erst nach dem Abmelden
  darf die historische „Funktion Ummelden“-Logik einem inaktiven Konto eine
  andere Funktion zuweisen; ein Request kann daher nicht die Sitzungsrolle
  eines aktiven Kontos wechseln.
- Die Bitmap-Renderer akzeptieren ausschließlich skalare UTF-8-Werte,
  geschlossene Typ-/Form-/Farbvokabulare und enge Größen- sowie Textgrenzen.
  Die drei Webskripte sind nur dünne Wrapper; Parameterfehler liefern HTTP 400,
  erfolgreiche Renderings immer PNG. Dadurch ist die Validierung ohne
  GD-Erweiterung separat testbar.

Die DB-freien Sicherheitsprüfungen laufen mit:

```sh
php tests/php/auth_security.php
php tests/php/navigation_security.php
php tests/php/session_ui_security.php
php tests/php/root_menu_security.php
php tests/php/workflow_security.php
php tests/php/http_surface_security.php
```
