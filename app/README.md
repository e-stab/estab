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
`incident.php` und `incident_ui.php` bilden den globalen Einsatzstatus,
Eingabegate und Statusbanner. `attachment.php` hält Reservierung, Dateiablage,
Metadaten und Audit in demselben Einsatzkontext. `generated_form.php`
autorisiert die einzelnen einsatzbezogen benannten Nachrichtenvordrucke,
liefert deren vollständigen Nachrichtendatensatz und validiert die gemeinsame
5×4-Empfängermatrix für den aktuellen PDF-Abzug.
`incident_export.php` und `incident_pdf.php` erzeugen das PDF-Dossier eines
ausdrücklich ausgewählten Einsatzes; `user_admin.php` kapselt dauerhafte
Kontosperre und Kennwortreset. `message_evidence.php` speichert und prüft die
append-only Nachrichtenereigniskette einschließlich Terminalbindung.
`dv_operations.php` bildet Dienstschichten, Funktions-Hüte, S6-Planung,
Melderlauf und Betriebsereigniskette ab. `operational_guard.php` ist die
gemeinsame fail-closed Grenze für authentifizierte operative Schreibrequests.

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
  Status einer neueren Anmeldung desselben Kontos deaktiviert. Die
  strukturierten JSON-Audits für Bestandslogin, Sitzungsaktualisierung,
  Self-Registration und Logout speichern ausschließlich einen
  `sha256:`-Verweis und niemals die wiederverwendbare rohe Session-ID oder ein
  Kennwort.
- `REMOTE_ADDR` wird nur als gültiges IPv4-/IPv6-Literal gespeichert.
  `X-Forwarded-For` wird standardmäßig ignoriert. Nur mit dem strikt geparsten
  `ESTAB_TRUST_PROXY_HEADERS=true` **und** einem direkten Peer innerhalb der
  IP-/CIDR-Allowlist `ESTAB_TRUSTED_PROXIES` wird eine vollständig validierte
  IP-Kette akzeptiert und deren erster Eintrag als Auditwert gespeichert.
  Eine aktivierte Option ohne Allowlist ist ein Konfigurationsfehler;
  Hostnamen, ungültige Präfixe und Catch-all-Netze werden abgewiesen.
- `estab_validate_runtime_configuration()` prüft die requestunabhängigen
  Deploymentwerte zentral. Der Container ruft sie vor Apache auf; Readiness
  verwendet exakt dieselbe Grenze und meldet ungültige Ports, Identifikatoren,
  Größen, URLs, Bool-Werte oder Proxy-Netze als nicht betriebsbereit.
- Selbstregistrierung ist standardmäßig ausgeschaltet. Nur die bewusst gesetzte
  Kompatibilitätsoption `ESTAB_ALLOW_SELF_REGISTRATION=true` erlaubt die
  öffentliche Kontoanlage; regulär legt die Basic-Auth-geschützte
  Benutzerverwaltung Konten und deren feste Funktion an. Boolesche
  Umgebungswerte akzeptieren ausschließlich `1/0`, `true/false`, `yes/no` oder
  `on/off`; Tippfehler führen absichtlich zu einem Fehler statt zu implizitem
  Aktivieren.
- Ein Eingang beginnt nach der Aufnahme durch A/W in Status 1 bei LdF. LdF
  übersetzt den aufgenommenen Rufnamen in den Absender und bestätigt den von
  A/W erfassten Eingangsweg; danach wertet Si den Inhalt aus und legt die
  Empfänger fest. A/W besitzt kein schreibbares Absenderfeld, auch
  serverseitig werden Übertragungsversuche verworfen. Eine Änderung des
  Eingangsmediums durch LdF verlangt eine Begründung. Das Repository liest den
  ursprünglichen A/W-Wert unter derselben Einsatz-, Status- und Sperrbedingung
  `FOR UPDATE`, lässt Aufnahmezeit und Aufnahmezeichen unverändert und schreibt
  Bestätigung, Alt-/Neuwert, Begründung und LdF-Kürzel in das hashverkettete
  Übergabeereignis. Der feste Eingangslauf ist `1 → 4 → 8`.
- `read_authorization.php` liefert die serverseitig gerenderten
  Vorschlagslisten für „Rufname der Gegenstelle“ und „Absender“. Die Abfrage
  validiert den aktiven Einsatz und die exakt ausgewählte aktive
  Dienstbesetzung erneut: Rufnamen sind nur für A/W und LdF verfügbar,
  Absender nur für LdF bei Eingängen. Sie liest ausschließlich bisherige Werte
  desselben aktiven Einsatzes. Für die exakt ausgewählte LdF-Besetzung
  korreliert `estab_read_ldf_mapping_suggestions()` außerdem den
  Gegenstellenrufnamen eines gesperrten Eingangs mit früheren Absendern
  beziehungsweise die Anschrift eines gesperrten Ausgangs mit früheren
  Gegenstellenrufnamen. Abgeschlossene Nachrichtenpaare stehen nach Häufigkeit
  und Aktualität vor einem passenden aktuell gültigen S6-Fernmeldeplan; erst
  danach folgt die allgemeine Einsatzhistorie. Dieselbe UNION-Abfrage bindet
  Einsatz, Schicht, Besetzung, Konto, Funktionsfähigkeit, Richtung,
  Nachrichtenstatus und Sperrinhaber erneut, sodass ein zwischenzeitlicher
  Zuständigkeits- oder Sperrwechsel keine Daten freigibt. Der lokale Absender
  eines Ausgangs wird weiterhin serverseitig bestimmt. Die Felder verwenden
  `autocomplete="off"`; die zugängliche Listbox öffnet beim Fokus, ist mit
  Pfeiltasten, Eingabetaste und Escape bedienbar, trifft keine automatische
  Auswahl und schränkt freie Eingaben nicht ein. Herkunftskennzeichen werden
  getrennt vom einzusetzenden Wert HTML-sicher gerendert. Ohne JavaScript
  bleibt ein natives `datalist` als Rückfalloption erhalten.
- Ein Ausgang beginnt in Status 4 bei Si. Si kann die schreibgeschützten
  Inhaltsfelder formal freigeben oder mit Pflichtgrund an den ursprünglichen
  Verfasser in Status 10 zurückgeben. Nach jeder Korrektur folgt erneut
  Status 4. Erst die Freigabe führt über LdF in Status 1 und A/W in Status 2
  zum Abschluss in Status 8. Der feste Regellauf ist deshalb
  `4 → 1 → 2 → 8`; Autosichtung und ein konfigurierbarer Sichtungs-Bypass
  existieren nicht. Ist der disponierte Weg tatsächlich nicht verfügbar,
  gibt A/W die Nachricht mit Pflichtgrund an LdF zurück; eine neue
  Planwegentscheidung ist erforderlich und beide Dispositionen bleiben im
  Ereignisnachweis erhalten.
- `message_transport.php` normalisiert die schreibbaren SET-Werte und übersetzt
  `Fe`, `Fu`, `Me`, `FAX`/`Fax`, `FS`, `@` und `DFÜ` für die Anzeige in
  verständliche Langformen. Die Nachweisung zeigt bei Eingängen dieses
  Eingangsmedium. Bei Ausgängen kombiniert sie Medium und Freitextweg erst nach
  gesetztem Beförderungszeitpunkt als tatsächlichen Beförderungsweg; vorher
  lautet der Status „Noch nicht befördert“. Unbekannte Legacywerte bleiben
  ausdrücklich als unbekannt sichtbar und durchlaufen die gemeinsame
  HTML-Escaping-Grenze.
- Der sichtbare Einstieg verwendet native Textbuttons, zugeordnete Labels,
  eindeutige Kennwortfelder und Inline-Fehler. Die beiden Konto-Flows bleiben
  getrennte Formulare, sodass ein Moduswechsel keine Zugangsdaten mitsendet.
  Jede Anmeldung und Neuanlage aus der Browseroberfläche erfordert bereits vor
  der Authentisierung ein sitzungsgebundenes CSRF-Token.
- `navigation.php` liefert genau neun operative Bereiche in stabiler
  Reihenfolge. Alle URLs laufen durch den zentralen Anwendungs-URL-Builder,
  interne Links verwenden den Top-Level-Browserkontext statt neue Tabs und
  genau der aus dem Requestpfad abgeleitete Bereich erhält
  `aria-current="page"`. Öffentlich sichtbar bleiben Übersicht und BOS-Info;
  sieben geschützte Bereiche führen anonym mit ihrem symbolischen Ziel zum
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
  Onlinebelegung, Identität, Logout, die nach ausgewähltem Funktions-Hut
  gefilterten neun beziehungsweise zehn dauerhaft sichtbaren Bereichs- und
  Dienstlinks ohne Disclosure sowie rollenabhängige Fachaktionen. Der
  Hauptframe entfernt seine Standalone-Leiste vor der ersten Darstellung. Das
  regelmäßig ausgetauschte Statusfragment lässt Navigation, wiederhergestellten
  Fokus, Scrollposition und das langlebige PCM-WAV-Audioelement bestehen.
  Positive Zähler bleiben hervorgehoben; fehlgeschlagene vorbereitete Statusabfragen
  melden `partial`/`unavailable` und lassen die Navigation intakt. Ein
  begrenzter Poll markiert HTTP-/Netzfehler als `stale`; unveränderte
  Live-Regionen bleiben als DOM-Knoten erhalten. Hörbare Hinweise benötigen je
  Tab eine erfolgreiche Browserfreigabe; die browserweite Ein-/Aus-Absicht
  wird über `localStorage` synchronisiert und verspätete `play()`-Ergebnisse
  werden generationsgebunden verworfen.
- `dv_operations.php` trennt Personenkonto und einsatz-/schichtbezogene
  Dienstfunktion. S2, Si, S6, LdF und A/W müssen vor Aktivierung mindestens
  einmal persönlich angenommen sein; mehrere A/W sind erlaubt, andere
  Funktionen nur einmal je laufender Schicht. Ungesperrte Offline-Konten
  dürfen vorausgeplant werden; eine in der Websitzung persönlich gespeicherte
  Annahme zählt auch nach Logout, weil `aktiv` ausschließlich den momentanen
  Onlinezustand bezeichnet. Eine Übergabe wird administrativ initiiert und erst durch ein
  Konto der Nachfolgeschicht bestätigt oder mit Pflichtgrund storniert. Ein
  Konto darf mehrere ausdrücklich zugewiesene Hüte wählen. Die Session
  speichert dabei die konkrete Besetzungs-ID, die bei jeder geschützten
  Anfrage erneut geprüft wird. Ohne aktive Schicht bleibt jeder normale
  operative Schreibpfad geschlossen.
- `dynamic_schema.php` reconciliert die sechs historischen
  Nachrichten-/Status-/Kategorietabellen für zusätzlich gewählte Stabs- und
  FB-Funktionen unter einem datenbank-/funktionsgebundenen Advisory Lock.
  Hutauswahl und -annahme führen das Reconcile strikt vor ihrer
  Domänentransaktion aus; der Login-Wrapper verwendet dafür eine getrennte
  DDL-Verbindung. Die eigenständige ETB-Funktion benötigt diese Tabellen nicht:
  `EINSATZTAGEBUCH` ist eng auf S2 und ETB begrenzt, während
  `LAGE_DOKUMENTATION`, Rotkopie und Meldungsübersicht ausschließlich S2
  vorbehalten bleiben.
- `operational_guard.php` sitzt am gemeinsamen Datenbank-
  Konfigurationsübergang und behandelt unbekannte authentifizierte
  `POST`-/`PUT`-/`PATCH`-/`DELETE`-Requests als operative Eingaben. Es gibt nur
  enge Kontrollausnahmen für Administration, Wiederherstellung, Logout,
  Dienstannahme/-wahl und die eigene Melder-Rückkehrkette. Ein übernommener
  Melderauftrag blockiert bis zur Rückkehr insbesondere Nachrichten,
  Kategorien, Gelesen-/Erledigt-Zustände, ETB/TBB und Anhänge. Auch ohne
  aktiven Einsatz oder aktive Dienstschicht bleibt die Grenze fail-closed.
- `export.php` veröffentlicht jeden Datenbankexport atomar als streng benannten
  Lauf und ZIP außerhalb des DocumentRoot. Die Administrationsseite listet nur
  reguläre, nicht verlinkte Archive unmittelbar im konfigurierten Exportroot.
  Binäre Downloads werden vor der gemeinsamen HTML-Ausgabepufferung geöffnet
  und unverändert gestreamt. Erstellen und Löschen verwenden eine explizite
  Aktions-Allowlist, POST, Session-CSRF und HTTP 303; die Löschung akzeptiert
  nur die symbolische Laufkennung und entfernt ausschließlich das zugehörige
  flache Verzeichnis-/ZIP-Paar. Symlinks, Unterverzeichnisse und ausbrechende
  Pfade werden nicht verfolgt.
- Der Singleton in `incident.php` erlaubt systemweit höchstens einen aktiven
  Einsatz. Operative Writer halten ihn mit `SELECT ... FOR UPDATE` bis zum
  Commit; ohne aktiven Einsatz scheitern sie. Der PDF-Dossierreader ist die
  bewusste Ausnahme auf der Leseseite: Er verwendet einen konsistenten
  Read-only-Snapshot der explizit gewählten aktiven oder historischen
  Einsatz-ID.
- `attachment.php::estab_attachment_store_upload()` beansprucht die
  reservierte Kennung, verschiebt und prüft die Datei, finalisiert Metadaten
  und schreibt das Audit unter demselben Einsatz-Lock. Fehler rollen zum
  Savepoint vor dem Claim zurück und geben die Reservierung frei; der
  Controller entfernt eine bereits verschobene Datei aus dem validierten
  Ablageroot und gibt auch Uploads frei, die PHP bereits wegen fehlender oder
  zu großer Dateien vor dem Claim ablehnt. Die gemeinsame Endungs-Allowlist
  umfasst die gebräuchlichen
  Bildaliase `jpg`/`jpeg` und `tif`/`tiff`; der Legacy-Uploadvalidator bindet
  beide Schreibweisen weiterhin an den tatsächlich erkannten MIME-Typ. Das
  Formular nennt Formate und effektives Größenlimit, ohne interne Fehler oder
  ungefilterte Dateinamen auszugeben; „Abbrechen“ bleibt trotz verpflichtender
  Dateiauswahl jederzeit ohne Browservalidierung möglich.
- `attachment_integrity.php` ist die gemeinsame Grenze für PDF-Dossier,
  Tabellenexport, direkten Download, Bildvorschau und formalen
  Einsatzabschluss. Download und Vorschau kopieren den einmal autorisiert
  geöffneten Anhang unter Lesesperre in einen privaten temporären Datenstrom,
  prüfen genau diesen Datenstrom und verarbeiten ausschließlich dasselbe,
  zurückgespulte Handle. Neue Anhänge
  erhalten beim atomaren Finalisieren SHA-256, Bytezahl und Serverzeit;
  Migration 95
  verhindert neue Legacy-Markierungen sowie jede spätere Änderung oder
  Herabstufung des Nachweises. Exporte vergleichen die reale Datei erneut und
  brechen bei Abweichung ab. Der Admin-Controller übergibt beim Schließen der
  letzten aktiven Dienstschicht zwingend den konfigurierten Ablageroot, sodass
  der Abschluss-Preflight ebenfalls die realen Bytes prüft und Schicht samt
  Besetzungen bei Abweichung unverändert lässt. Beim Upgrade vorhandene Dateien bleiben ehrlich
  als „Integrität beim Eingang nicht belegbar“ gekennzeichnet, weil ein heute
  berechneter Hash ihre ursprünglichen Eingangsbytes nicht beweisen könnte.
- Einzelne Nachrichtenvordrucke werden als
  `<datenbank> Einsatz-<einsatz_id> <nummer> <E|A>.pdf` über eine temporäre
  Datei und atomisches `rename` veröffentlicht. Liste und Download leiten den
  Namen aus dem abgeschlossenen, gedruckten Nachrichtendatensatz des aktiven
  Einsatzes ab, statt dem gemeinsamen Verzeichnisinhalt zu vertrauen. Der
  sichtbare Link `layout=current` rendert nach derselben gesperrten
  Autorisierung den vollständigen Datensatz und die validierte Matrix mit
  `vordruckaspdf::render_message_form_document()` neu im Speicher. So entspricht
  auch ein vor einem Layout-Upgrade archivierter Vordruck dem aktuellen
  Dossierlayout, ohne dass der lesende Request die persistierte Archivdatei
  verändert. Der parameterlose interne Downloadpfad streamt diese
  Archivdatei weiterhin bytegleich für Backup-/Restore-Nachweise.
- `assignment.php` bildet die gemeinsame, datenbankbasierte
  Zuordnungsrichtlinie. Matrixspeichern, Login, Kontoanlage und Neuzuweisung
  teilen einen globalen MariaDB-Lock; Kontooperationen nehmen danach erst den
  kontospezifischen Lock. Dadurch wird die Rolle immer aus einem frischen,
  vollständigen Matrixstand abgeleitet.
- `user_admin.php` verwendet diese Richtlinie und denselben kontospezifischen
  MariaDB-Lock wie der Login. Kontoanlage, feste Funktionszuordnung, Sperren,
  Entsperren und Kennwortreset schreiben das kennwortfreie Audit in derselben
  Transaktion. Neuzuweisung, Sperre und Reset widerrufen bestehende
  Sitzungsdaten. Rollen stammen aus der serverseitigen Funktionsmatrix;
  `nv_benutzer.aktiv` bleibt reiner Onlinezustand und `estab_gesperrt` die
  dauerhafte administrative Sperre.
- Die Matrixadministration verwendet keine generierte oder eingebundene
  PHP-Konfiguration mehr. Aktive Matrix und die einzige gespeicherte
  Standardmatrix liegen in getrennten InnoDB-Tabellen und müssen jeweils
  vollständig aus 20 eindeutigen Zellen bestehen. S2/Stab ist das feste
  Rotkopie- und Dokumentationsziel. Autosichtung ist deaktiviert; das
  historische Datenbankfeld bleibt immer falsch. „Nur aktive Matrix speichern“ ändert nur
  die Laufzeitmatrix, „Standard laden“ ersetzt ausschließlich die noch
  ungespeicherten Editorwerte, und gemeinsames Speichern ersetzt beide
  Tabellen. Dieselbe Transaktion synchronisiert anschließend die
  serverabgeleitete Rolle aller weiterhin vorhandenen Funktionen und
  widerruft betroffene Sitzungen. Entfernte Funktionen werden nicht
  stillschweigend auf andere Funktionen umgebogen: Das Konto behält seine
  letzte Zuordnung als sichtbar zu reparierenden Waisenstatus, wird abgemeldet
  und kann sich bis zur administrativen Neuzuweisung nicht anmelden.
  Matrix-, Konto- und kennwortfreie Auditänderungen committen gemeinsam; jeder
  Fehler rollt den vollständigen Vorgang zurück.
- Abgeschlossene Nachrichtenvordrucke haben keinen nachgelagerten
  Bearbeitungspfad. Die früheren `FM-Admin`-/`SI-Admin`-Zweitsichtungen sind
  aus der Laufzeitoberfläche entfernt: Quittierung, Sichtervermerk,
  Empfängerfarben, Inhalt und Transportnachweis bleiben nach Status 8
  unveränderlich. Persönliche Gelesen-/Erledigt-Markierungen liegen getrennt
  vom Vordruck und verändern dessen fachlichen Nachweis nicht.
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
- Ein Konto behält seine administrativ gespeicherte Funktion unabhängig vom
  Onlinezustand. Weder ein aktives noch ein abgemeldetes Konto kann per
  Anmelderequest Funktion oder Rolle wechseln. Nur die getrennt geschützte
  Benutzerverwaltung darf neu zuweisen und widerruft dabei jede Sitzung.
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
