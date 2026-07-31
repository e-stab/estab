# Bekannte Unterschiede der offiziellen Releases

## 0.9.26c: zurückgerollter Anhang-Dialog

Das am 2. Juli 2018 veröffentlichte Archiv `ver0.9.26c.zip` enthält für
`4fach/anhang.php` eine ältere Datei als `ver0.9.26b.zip`:

| Release | Dateigröße | ZIP-Zeitstempel | Verhalten |
| --- | ---: | --- | --- |
| 0.9.26b | 45.101 Byte | 11. Mai 2016 | Formularzustand aus `$_POST`; fehlende Werte werden initialisiert |
| 0.9.26c | 44.616 Byte | 20. Mai 2015 | Formularzustand wieder aus `$_GET`; mehrere Initialisierungen fehlen |

Der dokumentierte 0.9.26b-Fix „`store_formdata` von `$_GET` auf `$_POST`
umgestellt“ ging damit im späteren Paket verloren. Betroffen sind alle beim
Anhang-Upload zwischengespeicherten Nachrichtenfelder, die Empfängermatrix,
Grünkopie und Vermerke. Der getaggte Stand `ver0.9.26c` bleibt als Beleg exakt
erhalten. Der nachfolgende Arbeitsstand übernimmt ausschließlich
`4fach/anhang.php` aus dem ebenfalls verifizierten Tag `ver0.9.26b`; der
0.9.26c-Versionsmarker in `4fcfg/config.inc.php` bleibt bestehen.

Diese Entscheidung ist durch einen echten HTTP-Integrationstest abgesichert:
Ein authentifizierter A/W-Benutzer füllt den Nachrichtenvordruck mit markanten
Werten, Vermerk sowie blauer und grüner Empfängerzuordnung, lädt einen Anhang
hoch und wählt ihn aus. Der Test prüft anschließend die zurückgelieferten
Formularfelder und ausgewählten Matrix-Controls selbst; er speist die Werte
nicht erst beim späteren Speichern erneut ein.

## 0.9.26b/c: ausführbares Standardpreset der Empfängermatrix

Beide Releases enthalten genau ein wiederverwendbares Matrixpreset in der
absichtlich so benannten Datei `4fcfg/deault.fkt.php`. Der historische
Matrixeditor `4fadm/make_fkt.php` bietet vier Bildaktionen:

- „absenden“ schreibt nur die aktive Datenbankmatrix,
- „laden“ bindet `deault.fkt.php` als ausführbaren PHP-Code ein,
- „speichern“ überschreibt dieselbe Datei und schreibt zusätzlich die aktive
  Datenbankmatrix,
- „abbrechen“ verlässt den Editor.

Mehrere Vorlagen oder eine Löschfunktion gab es nicht. Alle Aktionen liefen
über `GET`; der Speichercode erzeugte PHP aus Requestwerten, schrieb es in den
Webbestand und ersetzte die aktive Tabelle mit `TRUNCATE` plus unvorbereitetem
SQL.

Der heutige Stand erhält bewusst das fachliche Modell „genau ein Standard“,
aber nicht dessen ausführbare Speicherform. Die additive Migration
`40-recipient-matrix-standard.sql` legt `nv_empfmtx_standard` als eigene
InnoDB-Tabelle an und befüllt sie mit der exakt normalisierten historischen
20-Zellen-Belegung einschließlich Rotkopie- und Autosichtungsflags. Existiert
eine nicht vom Migrationsledger verwaltete Tabelle dieses Namens bereits,
bricht die Migration vor jeder Änderung ab. Die eigene Tabelle besitzt eine
eindeutige Eigentumsmarkierung, damit ein Abbruch nach dem nicht
transaktionalen `CREATE TABLE` sicher fortgesetzt werden kann: Zulässig sind
nur eine leere oder die exakt kanonisch gesetzte eigene Tabelle; abweichende
Inhalte werden nicht überschrieben.

Der neue Editor trennt drei CSRF-geschützte POST-Aktionen: nur aktive Matrix
speichern, Standard ohne Datenbankänderung in den Editor laden sowie aktive
Matrix und Standard gemeinsam speichern. Die beiden Aktionen, die
ungespeicherte Editorwerte oder den bisherigen Standard verwerfen, verlangen
zusätzlich eine ausdrückliche Browserbestätigung. Prepared Statements und eine
gemeinsame Transaktion ersetzen PHP-Generierung, `include` und `TRUNCATE`.
Ein HTTP-Test erzwingt beim gemeinsamen Speichern einen Insert-Fehler in der
Standardtabelle und vergleicht danach beide Matrixtabellen und das Audit exakt
mit ihrem Ausgangszustand. Historische GET-Schreibparameter bleiben inert.

## 0.9.26b/c: umgehbare beziehungsweise wirkungslos konfigurierte Ausgangssichtung

Der Kommentar in `4fcfg/config.inc.php` beschreibt bereits beide vorgesehenen
Modi von `si_in_out`: Bei `true` sollen Ein- und Ausgänge durch Si laufen, bei
`false` nur Eingänge. Der Laufzeitcode enthält auch beide Statusübergänge.
Die Konfigurationsdatei lädt jedoch zuerst eine optionale `m_cfg.inc.php` und
setzt `si_in_out` anschließend bedingungslos auf `false`. Ein dort gesetzter
lokaler Wert konnte den dokumentierten Ausgangssichtungszweig deshalb nicht
aktivieren.

Der heutige Stand kennt bewusst keinen zweiten Modus mehr. Die formale
Sichtung jedes Ausgangs ist verpflichtend und läuft vom Verfasser über Si,
LdF und A/W bis zum Abschluss (`4 → 1 → 2 → 8`). Eine Rückgabe durch Si führt
begründungspflichtig über `4 → 10 → 4` erneut in dieselbe Sichtung. Der
historische Schlüssel `si_in_out` bleibt ausschließlich als immer wahrer
Kompatibilitätswert vorhanden. Weder `m_cfg.inc.php` noch die frühere
Umgebungsvariable `ESTAB_REVIEW_OUTGOING_MESSAGES` können den Prüfschritt
abschalten; Autosichtung ist ebenfalls ausgeschlossen.

Statische Negativtests versuchen den Kompatibilitätswert mit booleschen,
falsch typisierten und unbekannten Umgebungs-/Legacy-Werten zu verändern.
Der echte Nachrichtenrollenlauf manipuliert zusätzlich Formular und
Empfängermatrix, um Si zu überspringen. Ohne besetzte Si bleibt die Nachricht
unverändert in deren Warteschlange; nur der vollständige verbindliche Weg
wird als Abschluss akzeptiert.

## 0.9.26b/c: irreführend bearbeitbare FM-Admin-Zweitprüfung

Der historische A/W-Menüpfad öffnet `FM-Admin` für eine zweite Prüfung. In
beiden Releases markiert das Formular dabei alle Felder 1–17 als bearbeitbar,
obwohl der zugehörige Datenhandler ausschließlich Quittierung,
Empfängerzuordnung und Vermerk in den Feldern 15–17 speichert. Zugleich fehlt
`FM-Admin` im Schalter, der Speichern- und Abbrechen-Buttons rendert. Die
angezeigte Bearbeitungsmaske hatte damit keine passende Abschlussaktion und
versprach Änderungen, die der Handler nicht persistierte.

Der heutige Stand behandelt eine abgeschlossene Nachricht als
unveränderlichen fachlichen Nachweis. Die rollenbezogenen historischen
`FM-Adminmeldung`-/`SI-Adminmeldung`-Aufrufe öffnen höchstens eine
schreibgeschützte Belegansicht. Sie enthält keine Speichern-Aktion; der
Controller dispatcht keine `FM-Admin`-/`SI-Admin`-Mutation und die alten
Handler sind vom erreichbaren Schreibpfad getrennt. Manipulierte POST-Requests
werden bereits an Workflow-, Rollen- und Objektgrenze abgewiesen.

Der echte Nachrichtenrollenlauf versucht beide Zweitsichtungen gegen einen
abgeschlossenen Datensatz. Er vergleicht danach den vollständigen
Nachrichtenfingerabdruck einschließlich Quittierung, Empfängerfarben,
Sichter-, LdF- und Transportnachweis sowie den bereits erzeugten
PDF-Vordruck. Alles bleibt bytegenau unverändert. Davon getrennte persönliche
Gelesen-/Erledigt-Markierungen dürfen weiterhin gesetzt werden, weil sie nicht
den abgeschlossenen Nachrichtennachweis umschreiben.

## 0.9.26b/c: fehlende BOS-Infosammlung

Beide späteren Release-Archive enthalten den im Hauptmenü weiterhin aktiven
Link `./stabinfo/index.php`, lassen aber den vollständigen Verzeichnisbaum
`stabinfo/` aus. Ein unverändertes Release liefert für den sichtbaren
Menüpunkt deshalb HTTP 404. Der letzte SVN-Trunk enthält alle 14 zugehörigen
HTML-, PHP- und Bilddateien; dieser belegte Bestand wird im heutigen
Arbeitsstand wieder aufgenommen.

In `stabinfo/l_index.php` werden zwei bereits historisch tote Ziele
korrigiert: Der DRK-Menüpunkt verweist auf die tatsächlich mitgelieferte Datei
`DRK Rufnamenschema.html`, und der Link auf das nie enthaltene Verzeichnis
`HB_abt_2009` entfällt. Der HTTP-Smoke-Test öffnet Startseite, Frameset und
Inhaltsseite der Infosammlung, damit der veröffentlichte Menüpunkt nicht erneut
unbemerkt verloren geht.

Der SVN-Bestand lud außerdem zwölf „kleiner oder gleich“-Symbole und zwei
bedeutungslose Zierbilder per unverschlüsseltem HTTP von fremden Servern.
Heute stehen die Vergleichszeichen semantisch als lokales `&le;` im Dokument;
die Zierbilder und ein entbehrlicher externer Begriff-Link sind entfernt.
Es wurden keine fremden Bilddateien heruntergeladen oder übernommen.
