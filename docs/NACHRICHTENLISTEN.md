# Nachrichtenlisten suchen und filtern

Die Meldungsübersicht der Lage/Dokumentation und die „2. Sichtung“ im
Nachrichtenvordruck-Arbeitsbereich verwenden dieselbe Such- und
Ergebnisoberfläche. Der fachliche Inhalt, der aktive Einsatz und die
rollenabhängigen Leserechte bleiben dabei unverändert.

## Bedienung

Die Suche ist immer sichtbar. Ein Suchbegriff kann eine vollständige lokale
TBB-Nachweisnummer oder Text aus Rufname, Von, An, Rufnummer, Betreff,
Nachrichtentext und Verfasserfunktion sein. Die historische technische
Nachrichtennummer und der globale Datenbankschlüssel werden weder als
Nachweisnummer angezeigt noch über die Nummernsuche ersatzweise gefunden.
Mehrere normale Wörter werden gemeinsam gesucht; Wortanfänge genügen. Kurze
Bestandteile wie `S1` werden dabei tokenweise als wörtlicher Teiltext mit den
längeren, indexgestützten Wörtern kombiniert. Eingaben mit Sonderzeichen
werden als zusammenhängender wörtlicher Teiltext behandelt. Die Richtung wird
nicht in den Suchtext geschrieben, sondern über
den danebenliegenden Schnellfilter gewählt. Für Nachricht `E 142` wird daher
nach `142` gesucht und bei Bedarf „Eingang“ ausgewählt.

Direkt sichtbar sind:

- Richtung: Eingang, Ausgang oder beide,
- Vorrang: keiner, Sofort, Blitz oder Staatsnot,
- Bearbeitungsstand.

Unter „Weitere Filter und Sortierung“ stehen Zeitraum, Empfängerfunktion,
Sortierung und 25, 50 oder 100 Nachrichten je Seite zur Verfügung. „Filter
anwenden“ übernimmt die Auswahl. Jeder wirksame Filter erscheint anschließend
als beschrifteter Chip und kann dort einzeln entfernt werden; „Alle Filter
zurücksetzen“ stellt den Ausgangszustand wieder her. Trefferbereich,
Gesamtanzahl, Sortierung und aktuelle Ergebnisseite sind immer ausgeschrieben
und werden nicht nur über Farbe vermittelt.

Jede Ergebniszeile enthält genau eine eindeutige Aktion zum Öffnen des
Vordrucks. Auf breiten Bildschirmen bleibt der Tabellenkopf beim Scrollen
sichtbar. Auf schmalen Bildschirmen wird jede Nachricht zu einer beschrifteten
Karte, sodass das Gesamtdokument nicht horizontal gescrollt werden muss.

Die frühere automatische Aktualisierung der zweiten Sichtung ist abgeschaltet:
Sie konnte eine gerade eingegebene Suche verwerfen. Neue Daten werden durch
erneutes Anwenden der Suche beziehungsweise der Filter kontrolliert geladen.

## Geltungsbereiche und Rechte

| Ansicht | Zugriff | Detailaktion |
| --- | --- | --- |
| `/4fueltg/ue_ltg.php` | ausgewählte aktive S2-Dienstfunktion mit `LAGE_DOKUMENTATION` | einsatzgebundener Lesevordruck |
| „2. Sichtung“ als Si | ausgewählte aktive Si-Dienstfunktion | geschützter `SI-Adminmeldung`-POST |
| „2. Sichtung“ als A/W | ausgewählte aktive A/W-Dienstfunktion | geschützter `FM-Adminmeldung`-POST |

Alle Abfragen sind an die beim Berechtigungsgate erfasste ID des aktiven
Einsatzes gebunden. In der zweiten Sichtung wird zusätzlich schon vor Zählung
und Paginierung dieselbe persönliche Objektregel angewandt wie beim Öffnen:
aktuelle Warteschlange, eigener Sperrbesitz oder eigener unveränderlicher
Bearbeitungsvermerk. Die maximal 100 ausgewählten Zeilen werden danach erneut
mit der unabhängigen PHP-Objektentscheidung geprüft. Die Detailaktion bleibt
POST- und CSRF-geschützt. Filterzustände der drei Ansichten werden getrennt in
der Sitzung gehalten und sind keine Berechtigungsdaten.

## Verhalten bei vielen Nachrichten

Die Anwendung lädt nicht mehr den gesamten Einsatz in PHP, um anschließend
einen kleinen Ausschnitt anzuzeigen. MariaDB führt zuerst eine vorbereitete
`COUNT(*)`-Abfrage mit Einsatz-, Rechte- und Filtergrenze aus und liefert dann
nur die angeforderte Seite über ein stabiles `LIMIT`/`OFFSET`. Eine Seite wird
bei zwischenzeitlich kleiner gewordener Treffermenge auf die letzte vorhandene
Seite zurückgesetzt.

Jede Zeile zeigt die erste lokale Nummer des exakt verknüpften automatischen
TBB-Typs `nachricht`. Fehlt dieser Nachweis – etwa vor der tatsächlichen
Beförderung eines Ausgangs –, lautet der Wert „noch kein TBB-Nachweis“.
Numerische Suche und Nummernsortierung verwenden genau denselben Wert.

Migration `99-message-list-search.sql` stellt für Nachrichtentext und
Grundfilter drei Indizes bereit:

- `ft_nachrichten_suche` über den sieben Textfeldern,
- `idx_nachrichten_einsatz_status_zeit` für Einsatz, Stand und Zeitpunkt,
- `idx_nachrichten_einsatz_richtung_nummer` für Einsatz, Richtung und
  historische technische Nachrichtennummer.

Die kanonische TBB-Nummer wird dagegen über den eindeutigen
`idx_tbb_message` auf Einsatz und internen Nachrichtenbezug ermittelt; ihre
einsatzlokale Eindeutigkeit sichert `uq_tbb_einsatz_book_lfd`.

Die Migration ist wiederholbar und bricht bei einer fremden, gleichnamigen
Indexdefinition ab. Readiness und `docker/db/verify.sql` verlangen die exakte
Spaltenreihenfolge und das Entfernen des früheren reinen Inhaltsindex. Dadurch
kann ein Image mit unvollständigem Schema nicht als bereit gemeldet werden.

## Automatischer und manueller Nachweis

`tests/php/message_list_filter_security.php` prüft Parser, Zustandswechsel,
Prepared-Statement-Parameter, wörtliche LIKE-Suche, Volltextpräfixe,
Sortierungen und Seitengrenzen. `tests/php/message_list_ui_security.php` prüft
die gemeinsame semantische Oberfläche, HTML-Inertheit, genau eine Öffnen-Aktion
und die responsiven Bedienverträge. Die Autorisierungs- und Workflowtests
decken rollenfremde, unselektierte und CSRF-freie Zugriffe ab. Schema-Vertrag,
Migrator-Integration und Readiness prüfen die Indizes auf einer echten
MariaDB-Instanz.

`tests/integration/message_list_scale.php` ergänzt den echten Mengennachweis
in einer ausschließlich dafür erlaubten Wegwerfdatenbank. Der Test migriert
das Schema, schreibt 10.000 Nachrichten in den Zieleinsatz und weitere 257
bewusst gleichartige Nachrichten in einen fremden Einsatz und führt danach die
produktiven Filter-, Sortier- und Seitenhelfer mit nativen vorbereiteten
MariaDB-Statements aus. Er prüft exakte Treffer für strukturierte
Kombinationsfilter, Volltext, einen kurzen wörtlichen Suchbegriff und eine
Nachweisnummer, Einsatztrennung, wiederholbar stabile Seiten sowie das
Zurücksetzen auf die letzte vorhandene Seite. `EXPLAIN` muss dabei die
Volltext-, Einsatz/Status/Zeit-, Richtungs- und TBB-Verknüpfungsindizes
tatsächlich verwenden.

Die Zeitgrenzen sind absichtlich großzügige Regressionswächter und keine
Produktions-SLA: maximal 180 Sekunden zum Schreiben der 10.257 Zeilen, maximal
5 Sekunden für ein vorbereitetes `COUNT(*)`-/Seitenauswahl-Paar und maximal
45 Sekunden für 15 wiederholte repräsentative Paare. So bleibt der Test auch
auf emulierten ARM64-Runnern und Rootless-Podman-VMs belastbar, blockiert aber
ein versehentliches Laden des Gesamtbestands in PHP oder offensichtliche
Volltabellen-Regressionen. `tests/integration/ci.sh` erstellt und entfernt die
exakt freigegebene Datenbank `estab_message_list_scale_ci_test`; ein direkter
Aufruf gegen jeden anderen Datenbanknamen bricht vor dem ersten Schreibzugriff
ab.

Für die fachliche Freigabe mit großen Beständen zusätzlich:

1. als S2 und anschließend als Si beziehungsweise A/W mindestens mehrere
   Ergebnisseiten öffnen,
2. eine Nummer, zwei gemeinsam vorkommende Wörter und einen kurzen Teiltext
   suchen,
3. jeden Schnellfilter, einen Zeitraum und einen Empfänger einzeln sowie
   kombiniert prüfen,
4. einen Filter-Chip entfernen und danach alle Filter zurücksetzen,
5. erste, mittlere und letzte Ergebnisseite prüfen,
6. dieselbe Liste bei Desktopbreite und 390 CSS-Pixeln bedienen und dabei
   Tastaturfokus, Kartenbeschriftungen und fehlendes Dokument-Überlaufen
   kontrollieren,
7. im Systemstatus beziehungsweise mit dem Migrationsservice den vollständigen
   Schemanachweis bestätigen.
