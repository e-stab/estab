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
| `4fbak/` | nur dateisystemintern verwendete PDF-/Bild-Erzeugung und historische FPDF-Komponente |
| `stabetb/`, `fmtbb/`, `ubltg/`, `sammlung/` | Einsatztagebuch, technisches Betriebsbuch und Zusatzmodule |
| `app/` | Bootstrap, PHP-/MySQL-Kompatibilität, Authentisierung, gemeinsame Sitzungsanzeige und Abmeldung, CSRF, Datum, Nachrichten-/Kategoriezugriff, begrenzte PNG-Renderer, Anhang, Export und transaktionale Admin-Operationen |
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
  liegende Grafikreset werden vom Apache mit einer zur Startzeit erzeugten
  bcrypt-`htpasswd` geschützt. Das Admin-Kennwort stammt aus einem separaten
  Compose-Secret. Schreibende Admin-Formulare verlangen zusätzlich einen an
  die PHP-Sitzung gebundenen CSRF-Token.

Neue Anwendungspasswörter werden über PHPs `PASSWORD_DEFAULT` gehasht.
Historische Klartextwerte werden nur bei einer erfolgreichen Anmeldung
akzeptiert und dabei transparent durch einen Hash ersetzt. Die Datenbankspalte
ist dafür auf 255 Zeichen erweitert.

Selbstregistrierung bleibt aus Fachkompatibilität standardmäßig aktiv und kann
mit `ESTAB_ALLOW_SELF_REGISTRATION=false` abgeschaltet werden. Sie ist eine
bewusste Betriebsentscheidung und kein Ersatz für Netzsegmentierung oder
organisatorische Benutzerfreigabe.

Der anonyme Einstieg bindet die fachliche Absicht an einen streng validierten
Modus: Ein exakter, zustandsfreier `GET` darf von der Startseite lediglich
`existing` oder `new` zur Anzeige vorwählen. Das anschließende `POST`
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
oder Kontoerstellung abmelden. Bei aktiven Konten muss die übermittelte
Funktion außerdem der gespeicherten Zuordnung entsprechen; nur die bestehende
Ummeldelogik für inaktive Konten darf Funktion und daraus abgeleitete Rolle
ändern.

Das Root-Menü klassifiziert jedes Ziel als öffentlich, Anwendung oder
Administration. Geschützte Anwendungsmodule sind anonym nicht direkt
verlinkt, sondern als „Anmeldung erforderlich“ gekennzeichnet und mit dem
Anmeldeeinstieg verbunden. Dadurch bleibt die Funktionsübersicht sichtbar,
ohne Benutzer auf technisch korrekte, aber unverständliche 403-Antworten zu
schicken. Icon und Text bilden gemeinsam genau einen Link.

Die ausgewählten geschützten HTML-Controller verwenden
`app/session_ui.php` als gemeinsame Ausgabegrenze. Die Leiste zeigt
HTML-escaped Name, Kürzel, Funktion und die serverseitig abgeleitete Rolle und
stellt genau ein CSRF-geschütztes POST-Formular zum Abmelden bereit. Sie wird
nicht aus dem globalen Bootstrap ausgegeben: Binärdownloads, PNG-Renderer,
Readiness und Fehlerantworten bleiben dadurch unverändert. Im Frameset wird die
Leiste kompakt in der Navigation gerendert; eigenständige Fachmodule und der
bereits authentifizierte Root-Einstieg erhalten dieselbe Identität. Direkt
aufgerufene Status-/Zählerframes zeigen sie ebenfalls kompakt; ihre vom
Frameset explizit als eingebettet markierten Aufrufe verzichten auf Duplikate.
Der rechte Hauptframe entfernt seine für Standalone-Aufrufe vorgesehene Leiste
im zusammengesetzten Frameset unmittelbar per statischem Inline-Guard, sodass
genau eine sichtbare Identität und ein Logout verbleiben. Der BOS-Bereich
behält eine kompakte Leiste im persistenten Navigationsframe; aktive
Hilfe-/Problem-Popups verwenden dieselbe Ausgabegrenze.
Explizite Nicht-HTML-Antworten und Redirects passieren den Ausgabehandler
unverändert.

`4fach/logout.php` akzeptiert nur einen angemeldeten POST mit gültigem
Session-CSRF und leitet nach Erfolg mit HTTP 303 zum Anmeldeeinstieg weiter.
Die lokale Sitzung und alle Anwendungs-Cookies werden vor der
Datenbanknachführung beendet. Das Setzen des Benutzerstatus auf inaktiv ist an
Kürzel und die in der Datenbank gespeicherte SID gebunden. So kann ein später
abgeschickter Logout einer alten Sitzung eine neuere Anmeldung desselben
Kontos nicht deaktivieren. Audit- oder Datenbankfehler lassen die lokale
Sitzung nicht wieder aufleben. Für die historische Audit-Korrelation wird
lediglich ein SHA-256-Verweis statt der rohen Session-ID gespeichert.

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
  Anhänge und Vordrucke werden ausschließlich nach einer gültigen
  Anwendungssitzung durch den geprüften Download-Endpunkt ausgeliefert,
- setzt `nosniff`, `SAMEORIGIN`, eine Same-Origin-Referrer-Policy,
  eingeschränkte Browser-Berechtigungen und eine Content Security Policy.

Die Content Security Policy erlaubt derzeit wegen der historischen Oberfläche
noch Inline-Styles und Inline-Skripte. Die aktive Frame-Navigation löst Ziele
ohne `eval()` über `parent[...]` auf; `script-src` benötigt deshalb kein
`unsafe-eval` mehr. Die Richtlinie reduziert Angriffsfläche, ist aber kein
vollständiger XSS-Schutz.

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
Der Frame `/4fach/status.php` bleibt für den Anmeldebildschirm erreichbar,
zeigt anonym aber nur einen neutralen Hinweis. Erst eine vollständig
validierte Anwendungssitzung lädt Datenbank- und Rollenstatus.

Alte direkte Upload-Endpunkte sind mit HTTP 410 deaktiviert. Der aktive
Anhangpfad validiert Dateiname, MIME-Typ, Größe und Metadaten, reserviert Namen
transaktional und verwendet für schreibende Formulare ein Session-CSRF-Token.
Die Dateiauslieferung akzeptiert nur freigegebene Bereiche, Basenames und
Dateitypen, löst Pfade unterhalb des erwarteten Wurzelverzeichnisses auf und
verwirft ausbrechende Symlinks. Direkter Zugriff auf hochgeladene Bytes bleibt
auf Apache-Ebene eine zweite Schutzlinie.

Der neue Administrations-Export verwendet ebenfalls POST plus CSRF und erzeugt
CSV-Dateien anwendungsseitig. Der MariaDB-Benutzer benötigt dafür kein
globales `FILE`-Privileg.

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
Vor einem Nachrichtenpfad werden Rolle und Objekt gemeinsam geprüft:
Stabsfunktionen sehen und markieren nur exakte Empfänger-Tokens, der Sichter
nur weiterhin offene Sichtungsobjekte und A/W nur noch nicht transportierte
Ausgänge. Ein Sperrinhaber wird über sein validiertes Kürzel gebunden.
Historische GET-Detail- und GET-Mutationsaufrufe werden abgewiesen.

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
eine Reparatur nicht parallel an einem regulären Writer vorbeilaufen.
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
  Zeichen/Unterstriche lang, `Si` und `A/W` bleiben reserviert, Rollen sind
  leer, `Stab` oder `FB`, und genau eine belegte Funktion erhält die Rotkopie.
  Ein transaktionales `DELETE` plus 20 Prepared Inserts ersetzt die Matrix.
  Es wird keine PHP-Konfigurationsdatei mehr erzeugt und die Benutzertabelle
  wird nicht verändert.
- Die Reparatur des Nachrichtenzählers akzeptiert nur positive Ganzzahlen bis
  `999999999`. Ein MariaDB-Advisory-Lock und `FOR UPDATE` serialisieren
  parallele Admin-Anforderungen und reguläre Nachrichtenschreiber; Werte
  müssen sowohl im gemeinsamen als auch im getrennten Modus strikt über dem
  aktuellen Maximum liegen.
  Systemnachricht(en) und Audit-Eintrag werden gemeinsam committed.
- Der Grafikreset setzt ausschließlich nach einem CSRF-geprüften POST die
  validierte Spalte `x04_druck` zurück und auditiert die Zahl betroffener
  Nachrichten.

Erfolg wird per HTTP 303 auf eine GET-Ansicht umgeleitet. Validierungs-,
Konflikt- oder Datenbankfehler führen nicht zu Teiländerungen.

## Kategorien und Nachrichtenzuordnung

`app/category.php` ist die gemeinsame Daten- und Berechtigungsgrenze für die
aktive Verwaltung `4fach/katgoedt.php`, die Auswahllisten in
`4fach/katego.php` und das Kategorienband der Meldungsliste. Der Endpunkt
verlangt immer eine gültige eStab-Sitzung.
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
Nachrichtenablage zusätzlich objektbezogen einen exakten Empfänger-Token; eine
fremde, lediglich positive Meldungs-ID reicht deshalb nicht aus. Das Löschen
einer Kategorie entfernt ihre Zuordnungen in derselben Transaktion.

Auch die Kategorienavigation der Meldungsliste verwendet ausschließlich die
positive `lfd`, nicht den frei vergebenen Kategorienamen. Der Controller
akzeptiert nur `alle` oder eine kanonische positive ID; die vorbereitete
Listenabfrage bindet diese ID. Damit bleiben Kategorienamen mit Quotes reine
Daten und können nicht in den SQL-Filter gelangen.

`katgoedt.php` bleibt ein aktiver, vom Apache erreichbarer Fachendpunkt; die
Session-, Rollen-, CSRF- und Objektgrenzen liegen in PHP. Nur interne
Implementierungs- und Konfigurationsverzeichnisse werden auf Webserver-Ebene
gesperrt.

## Container- und Datengrenzen

- Alle drei Services verwenden `no-new-privileges`.
- Der Datenbankport ist nicht veröffentlicht.
- Secrets werden als Dateien eingebunden und sind aus Git und Build-Kontext
  ausgeschlossen.
- Nur Datenbank und One-shot-Migrator erhalten das MariaDB-Root-Secret. Der
  Migrator übergibt es dem Client durch eine private temporäre Optionsdatei,
  nicht als Prozessargument.
- Jede SQL-Migration ist versioniert und per SHA-256 in
  `estab_schema_migrations` gebunden. Abweichung, SQL-Fehler oder negativer
  Post-Migrations-Schematest verhindern den App-Start.
- Der App-Entrypoint prüft erforderliche Secrets und Identifier, bevor Apache
  startet.
- Datenverzeichnisse werden mit restriktiver `umask` und ohne
  Script-Ausführung betrieben.
- Der Readiness-Endpunkt prüft nicht nur Prozesse, sondern auch Datenbank,
  Schemakern und beschreibbaren Speicher.
- Images sind auf konkrete PHP-/MariaDB-Versionen festgelegt und werden nur
  nach einem geprüften Upgrade geändert.

Benannte Volumes sind keine Sicherung. Schutz vor Hostverlust, Fehlbedienung
oder beschädigten Daten bietet nur das getrennte Verfahren unter
[Backup und Wiederherstellung](BACKUP-UND-WIEDERHERSTELLUNG.md).

## Reverse-Proxy-Vertrauen

Ohne `ESTAB_TRUST_PROXY_HEADERS=true` ignoriert die Anwendung
`X-Forwarded-For` für Audit-IP-Adressen und verwendet nur den direkten Peer.
Bei aktivierter Option muss jedes Element der weitergereichten IP- und
Protokollketten syntaktisch gültig sein. Eine Herkunfts-Allowlist wird jedoch
nicht implementiert.

Daraus folgt: Bei aktiviertem Vertrauen darf der App-Port nur vom eigenen
Proxy erreichbar sein. Der Proxy überschreibt eingehende
`X-Forwarded-For`-/`X-Forwarded-Proto`-Header, terminiert TLS und setzt
Zugriffslimits. Details stehen unter
[Betrieb und Konfiguration](BETRIEB.md#reverse-proxy-und-tls).

## Verbleibende Risiken

Die Containerisierung macht aus dem historischen Code keine vollständig neu
entwickelte Anwendung. Für die Freigabe sind insbesondere zu berücksichtigen:

- große Teile der Fachoberfläche bleiben Legacy-PHP und verwenden die
  kontrollierte MySQL-Kompatibilitätsschicht,
- CSRF-Schutz ist für modernisierte schreibende Pfade vorhanden, aber nicht
  pauschal für jede historische Formularaktion bewiesen,
- die CSP benötigt für die historische Oberfläche weiterhin `unsafe-inline`,
- es gibt in eStab selbst kein Rate Limiting, keine Mehrfaktor-Authentisierung
  und keine zentrale Benutzerverwaltung,
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
- keine neuen Foreign Keys, weil historische Löschpfade keine definierte
  Cascade-Semantik besitzen.

Die detaillierte Gegenüberstellung zum Legacy-Schema steht in
[`docker/db/README.md`](../docker/db/README.md). Provenienz und unveränderte
Originaldokumentation sind unter
[`migration/README.md`](../migration/README.md) beziehungsweise
[`docs/legacy/README.md`](legacy/README.md) nachgewiesen.
