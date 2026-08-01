# Benutzerverwaltung

Die technische Benutzerverwaltung ist unter
`/4fadm/users.php` erreichbar und wird – wie alle Seiten unter `/4fadm` –
durch den unabhängigen HTTP-Basic-Administrationszugang geschützt. Außerhalb
eines isolierten Testsystems darf dieser Zugang nur über TLS bereitgestellt
werden. Die für alle Funktionskonten geltende Kennwortrichtlinie wird getrennt
unter `/4fadm/password_policy.php` konfiguriert.

## Kontostatus

Die historische Spalte `nv_benutzer.aktiv` beschreibt ausschließlich, ob eine
Anwendungssitzung noch besteht. Sie beschreibt weder die aktuelle
Browseraktivität noch eine dauerhafte administrative Sperre: Eine normale oder
automatische Abmeldung setzt sie ebenfalls auf `0`, während ein seit mehr als
15 Minuten untätiger, aber noch gültiger Benutzer zunächst `aktiv = 1` bleibt.

Die Migration `70-user-account-blocking.sql` ergänzt stattdessen das
namespaced Feld

```text
nv_benutzer.estab_gesperrt TINYINT UNSIGNED NOT NULL DEFAULT 0
```

Der Präfix `estab_` minimiert Kollisionen mit lokal erweiterten
Legacy-Schemata. Findet die Migration bereits ein Feld mit diesem Namen,
akzeptiert sie ausschließlich die kanonische Definition und die Werte `0`
oder `1`; eine abweichende Fremddefinition blockiert den Start, statt Daten
umzudeuten. Das veröffentlichte Basisschema `10-schema.sql` bleibt
unverändert, damit dessen bereits protokollierte Prüfsumme für bestehende
Installationen stabil bleibt.

Die hier verwalteten Funktionskonten sind nicht die HTTP-Basic-Konten der
Administration. Deshalb existiert weder eine unsichere „eigenes Konto“-Ausnahme
noch eine „letzter Administrator“-Sonderregel: Das Sperren sämtlicher
eStab-Funktionskonten sperrt den getrennten technischen
Administrationszugang nicht.

### Präsenz und automatisches Sitzungsende

Migration `100-session-presence.sql` ergänzt das nullable UTC-Feld
`nv_benutzer.estab_letzte_aktivitaet DATETIME(6)` und den Index
`idx_benutzer_presence (aktiv, estab_gesperrt, estab_letzte_aktivitaet)`. Die
zentrale Authentisierungsgrenze leitet daraus fünf voneinander getrennte
Zustände ab: gesperrt, abgemeldet, abgelaufen, inaktiv und aktiv.

- Bis 15 Minuten nach der letzten echten Browserinteraktion erscheint das
  Konto als „Aktiv“.
- Ab 15 Minuten erscheint es als „Inaktiv“, bleibt aber bis zur
  12-Stunden-Grenze angemeldet und arbeitsfähig.
- Ab 12 Stunden ohne echte Interaktion ist die Sitzung serverseitig
  abgelaufen. `aktiv`, `sid`, `ip` und `fwdip` werden SID-gebunden
  beziehungsweise durch die zentrale Bereinigung widerrufen; ein geschützter
  Aufruf verlangt danach eine neue Anmeldung.

Die gemeinsame Sitzungsleiste meldet Zeiger-, Tastatur-, Formular-, Rad- und
Touchinteraktionen sowie eine bewusste Rückkehr in das sichtbare Fenster an
`/4fach/activity.php`. Der Endpunkt akzeptiert ausschließlich POST mit
gültiger eStab-Sitzung, exakter gespeicherter SID und Session-CSRF. Ein
gemeinsamer Browsermarker begrenzt die Datenbankaktualisierung über Tabs und
Frames auf höchstens einmal pro Minute. Seitenaufrufe allein, automatische
Refreshes und das regelmäßig geladene Statusfragment zählen nicht als
Aktivität. Fehlende, ungültige oder in der Zukunft liegende Zeitwerte werden
fail-closed als abgelaufen behandelt.

Beim Upgrade können bereits als aktiv markierte Legacy-Sitzungen keine
verlässliche letzte Interaktion nachweisen. Migration 100 widerruft sie daher
einmalig; alle betroffenen Funktionsbenutzer melden sich nach dem Upgrade neu
an. Die Migration errät keinen Zeitpunkt und gewährt alten SIDs kein neues
12-Stunden-Fenster. Der unabhängige HTTP-Basic-Administrationszugang wird
davon nicht abgemeldet und ist nicht Teil dieser Präsenzanzeige.

## Konten anlegen und Funktionen zuweisen

Neue Installationen haben die öffentliche Selbstregistrierung
`ESTAB_ALLOW_SELF_REGISTRATION=false`. Die zuständige Stelle legt Konten unter
`/4fadm/users.php` mit folgenden Angaben an:

- Name,
- eindeutigem, kleingeschriebenem Kürzel aus höchstens sechs Buchstaben,
  Ziffern oder `_`,
- fester Funktion,
- zweimal eingegebenem Startkennwort.

Zuweisbar sind `A/W`, `LdF`, `Si` und die Stab-/FB-Funktionen der aktiven
Empfängermatrix. Der Browser übermittelt nur die Funktion; die Rolle wird
serverseitig aus dieser autoritativen Zuordnung abgeleitet. Ein neues Konto
startet abgemeldet, ungesperrt und ohne SID oder IP-Metadaten.

Die Funktion ist eine administrative Berechtigungszuordnung und nicht Teil des
Sitzungs- oder Präsenzzustands. Deshalb kann ein Konto sie weder während einer
aktiven Sitzung noch nach dem Abmelden durch eine andere Auswahl im
Loginformular ändern. Eine
Neuzuweisung ist ausschließlich in der Benutzerverwaltung möglich. Sie setzt
`funktion` und die daraus abgeleitete `rolle` gemeinsam, löscht `aktiv`, `sid`,
`ip` sowie `fwdip` und beendet damit eine vorhandene Sitzung.

Importierte Legacy-Zeilen mit leerer Funktion oder Rolle bleiben reparierbar.
Die alte leere Zuordnung und die neue gültige Zuordnung werden dabei
kontrolliert im Audit festgehalten.

### Festes Funktionskonto und optionale Zugangsschichten

Die Kontofunktion ist nicht nur eine Grundzuordnung, sondern die alleinige
Quelle der Fachrechte. Die daraus serverseitig abgeleitete Rolle gehört fest
zum Konto. Nach der Anmeldung gibt es keine Auswahl eines Funktions-Huts und
keine Schichtbesetzung, welche Funktion oder Rolle überschreiben könnte. Für
operative Eingaben muss ein Einsatz aktiv sein; eine aktive Dienst- oder
Zugangsschicht ist nicht erforderlich.

Unter `/4fadm/fuehrungsstelle.php` kann die Administration optional
einsatzgebundene Zugangsschichten anlegen und Konten zuordnen. Sie dienen nur
dazu, Zugänge einer Gruppe gemeinsam zu aktivieren oder zu deaktivieren:

- Ein Konto ohne Zuordnung bleibt zugelassen.
- Bei Zuordnung zu mehreren Gruppen genügt mindestens eine aktive Gruppe
  (OR-Semantik).
- Das Aktivieren einer Gruppe erzeugt keine Sitzung und meldet niemanden an.
- Das Deaktivieren widerruft Sitzungen der betroffenen Konten, sofern keine
  andere zugeordnete Gruppe aktiv bleibt.
- Eine Zugangsschicht verleiht niemals zusätzliche Fachrechte und sperrt
  keine Eingabe allein deshalb, weil keine Schicht angelegt ist.
- Deaktivieren und Entfernen verwenden eine wirkungsbezogene Bestätigung. Eine
  zwischenzeitliche Änderung an Schichtstatus, Zuordnungen oder betroffenen
  Konten führt zu einem Konflikt statt zu einer nicht mehr angezeigten
  Abmeldung. Entfernte und später neu angelegte Zuordnungen bleiben getrennte,
  unveränderliche Intervalle; ein alter Dialog kann das neue Intervall nicht
  entfernen.

Die dauerhafte manuelle Sperre `estab_gesperrt` ist von Zugangsschichten
unabhängig und hat Vorrang. Historische formale Dienstschichten,
Dienstbesetzungen und Übergaben bleiben als revisionsfähige und exportierbare
Evidenz im Datenbestand, sind aber keine aktuelle Autorisierungsquelle.

## Empfängermatrix als Zuordnungsrichtlinie

Die aktive Empfängermatrix ist die autoritative Quelle für alle
Stab-/FB-Funktionen und deren Rolle; `Si → Stab` sowie
`A/W → Fernmelder` und `LdF → Fernmelder` sind fest definiert. Login,
Kontoanlage, Neuzuweisung und
aktives Matrixspeichern verwenden deshalb denselben globalen
MariaDB-Advisory-Lock. Kontooperationen nehmen erst danach den
kontospezifischen Login-Lock. Diese feste Reihenfolge

```text
Zuordnungsrichtlinie → Kennwortrichtlinie → Konto → Transaktion/Zeilensperren
```

verhindert, dass eine Anmeldung oder administrative Zuordnung noch mit einem
überholten Matrixstand erfolgreich wird. Ist die Richtlinie gerade belegt,
antworten Benutzer- und Matrixverwaltung gezielt mit HTTP 409; der Benutzer
erhält eine erneute Versuchsmöglichkeit statt einer Teiländerung.

Beim Speichern der aktiven Matrix werden Konten in derselben Transaktion
abgeglichen:

- Bleibt eine Funktion erhalten, ändert sich aber ihre Rolle, wird nur die
  serverabgeleitete Rolle korrigiert. Eine vorhandene Sitzung wird widerrufen.
- Wird eine Funktion entfernt, bleiben Funktion und bisherige Rolle als
  revisionsfähige Zuordnung erhalten. SID, IP-Metadaten und Sitzungsstatus
  werden entfernt; eine neue Anmeldung ist nicht möglich.
- Die Benutzerverwaltung kennzeichnet eine solche Zeile als
  „Zuordnung nicht mehr gültig“. Der Administrator weist dort eine aktuell
  gültige Funktion zu. Ein Kennwortreset bleibt bewusst möglich, damit
  Kontowiederherstellung und Funktionsreparatur unabhängig voneinander
  vorbereitet werden können; er beendet weiterhin jede Sitzung und ändert
  weder Funktion noch Rolle. Die fachliche Anmeldung bleibt trotz neuem
  Kennwort bis zur gültigen Neuzuweisung gesperrt.
- Dynamische historische Funktions- oder Benutzertabellen werden weder
  gelöscht noch automatisch umbenannt oder einer Ersatzfunktion zugeordnet.

Auch die Benutzerliste und die zugehörige Rollenmatrix werden unter einem
gemeinsamen Richtlinien-Snapshot gelesen. So kann die Oberfläche keinen
gemischten Vorher-/Nachher-Stand anzeigen.

Readiness bleibt bei einem abgemeldeten Waisenkonto verfügbar, damit die
Administration die Zuordnung reparieren kann. Ein als aktiv markiertes Konto
mit ungültigem Funktions-/Rollenpaar macht `/health.php`,
`/4fadm/system_status.php` und `docker/db/verify.sql` dagegen absichtlich
ungesund.

## Sperren und Entsperren

„Konto sperren“ setzt in einer Datenbanktransaktion:

- `estab_gesperrt = 1`,
- `aktiv = 0`,
- `sid`, `ip` und `fwdip` auf leere Werte,
- einen vorbereiteten Audit-Eintrag in `nv_protokoll`.

Die Sitzung ist damit auf der nächsten geschützten Anfrage unwirksam.
Zusätzlich prüft die zentrale Authentifizierungsgrenze
`estab_gesperrt` gegen die aktuelle Datenbankzeile; ein gesperrtes Konto kann
sich auch mit korrekten Anmeldedaten nicht erneut anmelden.

„Konto entsperren“ entfernt nur die dauerhafte Sperre. Es erzeugt keine
Sitzung und setzt kein Konto künstlich auf „angemeldet“. Der Benutzer muss
sich danach regulär authentifizieren.

Das Entsperren überstimmt keine deaktivierte Zugangsschicht. Umgekehrt hebt
das Aktivieren einer Zugangsschicht keine manuelle Sperre auf. Die beiden
Mechanismen bleiben absichtlich getrennt; bei einer Mehrfachzuordnung erlaubt
eine andere aktive Zugangsschicht den Zugang nur, wenn das Konto nicht manuell
gesperrt ist.

Die Verwaltungsaktionen und der Legacy-Login verwenden denselben
kontospezifischen MariaDB-Advisory-Lock. Ein paralleler Login kann daher weder
eine gerade gesetzte Sperre noch einen Kennwortwechsel überholen.

## Kennwortrichtlinie

Migration `113-password-policy.sql` ergänzt die InnoDB-Singleton-Tabelle
`nv_kennwortrichtlinie`. Sie enthält die Mindestlänge, vier optionale
Zeichenklassen, eine monotone Revision sowie UTC-Änderungszeit und den
Basic-Auth-Akteur. Kollidiert der Tabellenname mit einer nicht vollständig
kanonischen Fremdtabelle, fehlt die einzige Zeile oder liegt ein Wert außerhalb
seiner CHECK-Grenze, blockieren Migration und Readiness den Betrieb, statt eine
Richtlinie zu erraten.

Die Installation beginnt mit der bisherigen sicheren Voreinstellung:

- mindestens 12 Zeichen,
- kein verpflichtender Großbuchstabe,
- kein verpflichtender Kleinbuchstabe,
- keine verpflichtende Ziffer,
- kein verpflichtendes Sonderzeichen.

Die Administration kann die Mindestlänge unter
`/4fadm/password_policy.php` auf einen ganzzahligen Wert zwischen 8 und 128
Unicode-Codepoints setzen und jede Zusatzanforderung unabhängig aktivieren.
Groß- und Titlecase-Buchstaben sowie Kleinbuchstaben und Ziffern werden als
Unicode-Klassen geprüft;
Interpunktions- und Symbolzeichen zählen als Sonderzeichen. Leerzeichen sind
für Passphrasen erlaubt, erfüllen die Sonderzeichenpflicht aber nicht.
Unicode-Steuerzeichen (`\p{Cc}`) bleiben unabhängig von der Konfiguration
verboten. Formatzeichen (`\p{Cf}`), insbesondere ZWJ in Emoji-Sequenzen, sind
zulässig. Das Kennwort wird weder getrimmt noch normalisiert.

Eine Änderung wird zunächst als Vorher-/Nachher-Vorschau angezeigt. Schwächt
sie mindestens eine Anforderung ab, weist die Oberfläche ausdrücklich darauf
hin. Erst eine zweite, bestätigte POST-Aktion darf die Richtlinie speichern.
Beide Schritte verlangen HTTP Basic Auth und Session-CSRF. Die im Formular
mitgeführte Revision und ein globaler MariaDB-Advisory-Lock verhindern, dass
ein alter Browserstand eine zwischenzeitliche Änderung überschreibt. Eine
echte Änderung, die neue Revision und das kennwortfreie Audit committen in
derselben Transaktion; bei einem Auditfehler bleibt die alte Richtlinie
vollständig erhalten.

Die Richtlinie wirkt ausschließlich prospektiv auf

- administrativ angelegte Startkennwörter,
- administrative Kennwortresets,
- und die nur bei `ESTAB_ALLOW_SELF_REGISTRATION=true` sichtbare
  Selbstregistrierung.

Ein Bestandslogin und die transparente Umwandlung eines eindeutig
verifizierbaren Altwerts wenden die neue Richtlinie bewusst nicht rückwirkend
an. Klartextwerte und andere eindeutige Alt-Hashes werden nach erfolgreicher
Anmeldung auf Argon2id umgestellt. bcrypt wird nur bei einem eingegebenen
Kennwort unter 72 UTF-8-Bytes automatisch migriert. Bei 72 oder mehr Bytes
bleibt der ambivalente Alt-Hash unverändert und benötigt für Argon2id einen
administrativen Reset; so wird kein erstmals präsentierter Suffix neu an das
Konto gebunden. Argon2id-Profile werden nur hochgestuft, wenn alle
Kostenparameter höchstens dem Ziel entsprechen und mindestens einer niedriger
ist. Bereits stärkere oder gemischte Profile werden nie auf die Standardkosten
zurückgestuft. Eine Verschärfung
widerruft daher weder die Anmeldbarkeit des Kennworts noch laufende Sitzungen.
Das separate HTTP-Basic-Kennwort für
`/4fadm` stammt weiterhin ausschließlich aus dem Admin-Secret und ist weder
Inhalt noch Ziel dieser Tabelle.

Kontoanlage und Selbstregistrierung lesen die aktuelle Richtlinie unter dem
globalen Richtlinien-Lock vor dem kontospezifischen Login-Lock. Ein
Kennwortreset verwendet dieselbe Reihenfolge ab der Kennwortrichtlinie. So kann
weder eine parallele Verschärfung mit einem alten Prüfstand überholt noch ein
halb gespeicherter Kontozustand sichtbar werden.

## Kennwort zurücksetzen

Das neue Kennwort wird ausschließlich in einem CSRF-geschützten POST-Formular
zweimal eingegeben. Die Benutzerverwaltung zeigt die aktuell wirksame
Mindestlänge und alle aktivierten Zeichenklassen direkt über den Feldern an.
Die serverseitige Prüfung ist verbindlich: Zulässig sind höchstens 1024
UTF-8-Bytes und das Kennwort muss gültiges UTF-8 ohne Unicode-Steuerzeichen
sein; Formatzeichen wie ZWJ bleiben zulässig. Das Browserfeld erlaubt 1024
Eingabeeinheiten. Sein JavaScript zählt die konfigurierbare Mindestlänge exakt
in Unicode-Codepoints, die Serverprüfung bleibt jedoch verbindlich. Nach
erfolgreicher Prüfung wird das Kennwort unverändert mit Argon2id gehasht.

Der Klartext erscheint weder

- in einer URL,
- in einem Redirect oder Flash-Datensatz,
- in einem Audit-Eintrag,
- noch in einer Logmeldung oder als erneut ausgegebener Formularwert.

Kennworthash, Sitzungswiderruf und Audit werden gemeinsam transaktional
gespeichert. Ein Reset lässt eine bestehende Kontosperre unverändert; bei
einem freigegebenen Konto ist anschließend eine neue Anmeldung mit dem neuen
Kennwort erforderlich. Verfehlt das neue Kennwort die Richtlinie oder ändert
sich diese parallel, bleiben Hash, Konto- und Sitzungszustand sowie Audit
unverändert; beide Kennwortfelder werden nicht erneut ausgegeben.

## Audit

Schreibende Aktionen erzeugen `p_was = Benutzerverwaltung` mit einem
strukturierten JSON-Datensatz. Er enthält:

- Aktionsart (`create`, `reassign`, `block`, `unblock` oder
  `reset_password`; bei einem Matrixabgleich außerdem `matrix_role_sync` oder
  `matrix_orphan`),
- validierte Basic-Auth-Administratoridentität,
- Zielkürzel,
- Information, ob eine aktive Sitzung widerrufen wurde,
- validierte direkte Administrator-IP.

Bei Kontoanlage enthält er zusätzlich neue Funktion und Rolle, bei
Neuzuweisung alte und neue Funktion sowie Rolle. Leere historische Altwerte
sind erlaubt; neue Werte niemals. Das zusätzliche Matrixaudit enthält die
Anzahlen synchronisierter Rollen, verwaister Zuordnungen und widerrufener
Sitzungen.

Kennwort, Kennworthash und Session-ID werden nicht protokolliert. Scheitert
der Audit-Schreibvorgang, wird auch die Kontoänderung zurückgerollt.

Richtlinienänderungen verwenden getrennt `p_was = Kennwortrichtlinie` und die
Aktion `password_policy_updated`. Der JSON-Datensatz enthält ausschließlich
Vorher-/Nachher-Konfiguration, validierte Basic-Auth-Identität und direkte IP;
er enthält weder ein Kennwort noch einen Hash oder eine Session-ID. Eine
unveränderte Bestätigung erhöht die Revision nicht und erzeugt keinen
Schein-Auditeintrag.

## Nachweis

Der fokussierte Vertragstest
`tests/php/user_admin_security.php` prüft Eingabegrenzen, die serverseitige
Funktions-/Rollenableitung, Kontoanlage und Neuzuweisung, Auditdaten,
gemeinsamen Login-Lock, sofortige Session-Ungültigkeit, POST/CSRF/PRG,
Nichtweitergabe des Klartextkennworts, die kollisionsbewusste Migration und
die Aufnahme beider neuen PHP-Dateien in die Container-Laufzeit.

`tests/php/password_policy_security.php` prüft mit 63 Assertions Default und
Eingabegrenzen, exakte browserseitige Codepointzählung, Titlecase und ZWJ,
Unicode-Zeichenklassen, Argon2id samt vollständiger Unterscheidung von Werten
mit gleichem 72-Byte-Präfix, getrennte
Bestätigungsfehler, Vorschau/Bestätigung, CSRF/PRG, optimistische Revision,
kennwortfreies Audit, Containeroberfläche und dass Bestandslogins nicht
rückwirkend an die Richtlinie gebunden werden. Der Authentisierungsvertrag
ergänzt 106 Assertions einschließlich der eindeutigen bcrypt-Migration unter
72 UTF-8-Bytes, des bewusst unveränderten ambivalenten Alt-Hashes ab 72 Bytes
und monotoner Argon2id-Kosten. `tests/integration/password_policy.php` belegt mit 63
Assertions gegen MariaDB Singleton-Schema, Revision, Lockkonkurrenz und
Rollback sowie schwache und gültige Kontoanlagen, Resets und
Selbstregistrierungen. Die Admin-HTTP- und
Browserprüfungen kontrollieren zusätzlich die verständliche Anzeige auf
Desktop und Mobilansicht und stellen am Ende die Standardrichtlinie wieder her.

`tests/php/assignment_policy_security.php` prüft zusätzlich globale
Lockreihenfolge, Matrix-/Kontotransaktion, generische Waisenanzeige,
HTTP-409-Konfliktpfade, einen einzigen konsistenten Matrixread und den
Readiness-Vertrag.

`tests/integration/user_admin.php` führt den Datenvertrag zusätzlich gegen die
wirklich migrierte MariaDB und den produktiven Legacy-Login aus. Er:

1. legt ein inaktives Konto mit gehashtem Startkennwort und fester Zuordnung
   an, weist eine Kollision ab und repariert eine leere Legacy-Zuordnung,
2. sperrt einen aktiven Benutzer und weist die alte Sitzung sowie korrekte
   Anmeldedaten ab,
3. entsperrt das Konto ohne eine alte Sitzung wiederzubeleben,
4. setzt das Kennwort zurück und weist alte Sitzung und altes Kennwort ab,
5. beweist, dass auch ein abgemeldetes Konto keine andere Funktion wählen
   kann,
6. weist die Funktion administrativ neu zu, widerruft die Sitzung und
   akzeptiert danach ausschließlich die neue Funktion,
7. erzwingt den gemeinsamen Login-/Admin-Lock und vollständigen Rollback bei
   Auditfehler,
8. verlangt alle Auditaktionen genau einmal in Reihenfolge und beweist, dass
   Kennwörter, Hashes und Session-IDs darin nicht vorkommen.

`tests/integration/assignment_policy.php` verwendet mehrere echte
MariaDB-Verbindungen und beweist ergänzend:

1. der globale Lock blockiert parallel Login, Kontoanlage, Neuzuweisung und
   Matrixspeichern,
2. Rollenänderung, Sitzungswiderruf und Audit committen gemeinsam,
3. ein erzwungener Auditfehler rollt Matrix, Konto, Sitzung und Audit
   vollständig zurück,
4. entfernte Funktionen bleiben als inaktive Waisenzuordnung sichtbar und
   können ihr Kennwort zurückgesetzt bekommen, sich aber auch mit diesem neuen
   Kennwort nicht anmelden,
5. nur eine künstlich wieder aktivierte ungültige Zuordnung lässt Readiness
   geschlossen fehlschlagen.

Für die fachliche Freigabe bleibt zusätzlich die Bedienprobe über
`/4fadm/password_policy.php` und `/4fadm/users.php` erforderlich: Richtlinie
voranzeigen und bestätigen, Konto anlegen, zugewiesene Funktion anmelden,
abweichende Funktion abweisen, neu zuweisen, Sperren, Entsperren und
Kennwortreset im vorgesehenen Browser ausführen. Dabei sind verständliche
Rückmeldungen, die erneute Anmeldung und die weiterhin mögliche Anmeldung mit
einem vor der Verschärfung gültigen Bestandskennwort zu kontrollieren.
