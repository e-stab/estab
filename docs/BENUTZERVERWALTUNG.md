# Benutzerverwaltung

Die technische Benutzerverwaltung ist unter
`/4fadm/users.php` erreichbar und wird – wie alle Seiten unter `/4fadm` –
durch den unabhängigen HTTP-Basic-Administrationszugang geschützt. Außerhalb
eines isolierten Testsystems darf dieser Zugang nur über TLS bereitgestellt
werden.

## Kontostatus

Die historische Spalte `nv_benutzer.aktiv` beschreibt ausschließlich, ob eine
Anwendungssitzung aktiv ist. Sie kann deshalb keine dauerhafte administrative
Sperre abbilden: Eine normale Abmeldung setzt sie ebenfalls auf `0`.

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
Onlinezustands. Deshalb kann ein Konto sie weder während einer aktiven Sitzung
noch nach dem Abmelden durch eine andere Auswahl im Loginformular ändern. Eine
Neuzuweisung ist ausschließlich in der Benutzerverwaltung möglich. Sie setzt
`funktion` und die daraus abgeleitete `rolle` gemeinsam, löscht `aktiv`, `sid`,
`ip` sowie `fwdip` und beendet damit eine vorhandene Sitzung.

Importierte Legacy-Zeilen mit leerer Funktion oder Rolle bleiben reparierbar.
Die alte leere Zuordnung und die neue gültige Zuordnung werden dabei
kontrolliert im Audit festgehalten.

### Konto und konkrete Dienstfunktion

Die Kontofunktion ist die administrative Grundzuordnung. Für einen operativen
Einsatz reicht sie allein nicht: Unter
`/4fadm/fuehrungsstelle.php` weist die Administration dasselbe persönliche
Konto einer geplanten Schicht und einer oder mehreren konkreten Funktionen zu.
Die Person nimmt jede Zuweisung anschließend selbst unter
`/4fach/fuehrungsstelle.php` an. Erst eine aktive Schicht mit passender
angenommener Besetzung erlaubt operative Eingaben.

Eine Person kann damit beispielsweise S2/S3 oder ETB/Si wahrnehmen, ohne
mehrere Konten oder geteilte Kennwörter zu benötigen. Der Funktionswechsel
speichert die genaue angenommene Besetzungs-ID in der serverseitigen Sitzung
und wird bei jeder geschützten Anfrage erneut gegen aktive Schicht, Konto,
Funktion und Rolle geprüft. Eine abgelöste oder geschlossene Besetzung
verliert dadurch unmittelbar ihre Schreibberechtigung.

## Empfängermatrix als Zuordnungsrichtlinie

Die aktive Empfängermatrix ist die autoritative Quelle für alle
Stab-/FB-Funktionen und deren Rolle; `Si → Stab` sowie
`A/W → Fernmelder` und `LdF → Fernmelder` sind fest definiert. Login,
Kontoanlage, Neuzuweisung und
aktives Matrixspeichern verwenden deshalb denselben globalen
MariaDB-Advisory-Lock. Kontooperationen nehmen erst danach den
kontospezifischen Login-Lock. Diese feste Reihenfolge

```text
Zuordnungsrichtlinie → Konto → Transaktion/Zeilensperren
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
  revisionsfähige Zuordnung erhalten. SID, IP-Metadaten und Onlinezustand
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

Die Verwaltungsaktionen und der Legacy-Login verwenden denselben
kontospezifischen MariaDB-Advisory-Lock. Ein paralleler Login kann daher weder
eine gerade gesetzte Sperre noch einen Kennwortwechsel überholen.

## Kennwort zurücksetzen

Das neue Kennwort wird ausschließlich in einem CSRF-geschützten POST-Formular
zweimal eingegeben. Es muss 12 bis 255 Zeichen lang und gültiges UTF-8 ohne
Steuerzeichen sein. Es wird unverändert mit `password_hash()` und dem
aktuellen `PASSWORD_DEFAULT` gehasht.

Der Klartext erscheint weder

- in einer URL,
- in einem Redirect oder Flash-Datensatz,
- in einem Audit-Eintrag,
- noch in einer Logmeldung oder als erneut ausgegebener Formularwert.

Kennworthash, Sitzungswiderruf und Audit werden gemeinsam transaktional
gespeichert. Ein Reset lässt eine bestehende Kontosperre unverändert; bei
einem freigegebenen Konto ist anschließend eine neue Anmeldung mit dem neuen
Kennwort erforderlich.

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

## Nachweis

Der fokussierte Vertragstest
`tests/php/user_admin_security.php` prüft Eingabegrenzen, die serverseitige
Funktions-/Rollenableitung, Kontoanlage und Neuzuweisung, Auditdaten,
gemeinsamen Login-Lock, sofortige Session-Ungültigkeit, POST/CSRF/PRG,
Nichtweitergabe des Klartextkennworts, die kollisionsbewusste Migration und
die Aufnahme beider neuen PHP-Dateien in die Container-Laufzeit.

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
`/4fadm/users.php` erforderlich: Konto anlegen, zugewiesene Funktion anmelden,
abweichende Funktion abweisen, neu zuweisen, Sperren, Entsperren und
Kennwortreset im vorgesehenen Browser ausführen und die verständlichen
Rückmeldungen sowie die erneute Anmeldung kontrollieren.
