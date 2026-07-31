# Nachrichtenvordruck

Die Eingabeseite unter **Nachrichtenvordruck** bildet das amtliche
THW-Raster aus den bereitgestellten Unterlagen mit Stand April 2022 ab. Das
Raster ist kein frei gestaltetes Webformular: Reihenfolge, Dreiteilung,
Beschriftungen, schwarze Linien und der amtliche Blauton `#A2D9F7` bleiben
fest. Modernisiert wurden ausschließlich die Bedienung um das Blatt herum und
die Eingabeelemente innerhalb der vorgegebenen Zellen.

Als fachliche Grundlage dienten:

- *Unterlage Nachrichtenvordruck*,
- *Ausfüllanleitung Nachrichtenvordruck*, Stand April 2022.

Die Quelldokumente werden nicht in dieses Repository kopiert. Diese Datei
dokumentiert die daraus umgesetzten Regeln und den reproduzierbaren
Funktionsnachweis.

## Referenzbindung

Damit ein späterer Vergleich nicht versehentlich gegen eine andere Fassung
erfolgt, ist die tatsächlich verwendete lokale Referenz über SHA-256
gebunden:

| Referenz | Seitenformat | SHA-256 |
| --- | --- | --- |
| `Unterlage Nachrichtenvordruck.pdf` | 1 Seite, A3 quer | `05df16e955f008aa99a9102342ed3a49c22b72663e367e82acf97ac334deecbf` |
| `Ausfuellanleitung Nachrichtenvordruck.pdf` | 1 Seite, A4 hoch, Stand April 2022 | `b3321cb295616310e6d8292343e3ba4a46676483aa2eb46299c0782f3a8bfcd0` |

Die Hashes identifizieren nur die vom Anwender bereitgestellten Unterlagen;
die PDFs selbst bleiben außerhalb des Repositorys.

## Aufbau und Bedienung

Der Vordruck besteht auch am Bildschirm aus den drei amtlichen Bereichen:

1. **Fm-Zentrale** mit tatsächlichem TK-Mittel, Aufnahme-, Annahme- und
   Beförderungsvermerk, TTB-Nummer sowie Gegenstellenrufname,
2. **Nachricht** mit gewünschtem TK-Mittel, Nachrichtenform, Vorrang,
   Anschrift, Rufnummer, Gesprächsnotiz, Betreff, Nachrichtentext, Absender,
   Abfassungszeit und Verfasserangaben,
3. **Sichter** mit Quittung, Verteiler und Vermerken.

Alle drei Hauptgitter beginnen an derselben amtlichen Stegkante. Aufnahme-,
Annahme- und Beförderungsvermerk zeigen jeweils getrennte Zellen für
**Datum**, **Uhrzeit** und **Hdz.**; Datum und Uhrzeit bleiben dabei bewusst an
das eine bestehende Zeitfeld des jeweiligen Vorgangs gebunden. Eine
zugängliche Feldbeschreibung macht diese gemeinsame Eingabe auch ohne die
rein visuelle Zellteilung eindeutig. Die Anzeige zerlegt bekannte Bestands-
und SQL-Zeitformate in die beiden sichtbaren Zellen; ein alleiniger Zeitwert
steht ausschließlich unter **Uhrzeit** und wird nicht als Datum ausgegeben.

Der linke Steg des Nachrichtenteils enthält wie die Referenz die
Durchschriftenfolge blau, grün, rot und gelb mit ihrem jeweiligen Verbleib.
Steg und Nachrichtenteil besitzen durchgehend denselben amtlichen Blauton;
die sichtbare Legende übernimmt die amtlichen Abkürzungen und die Bezeichnung
`Verbindungsstelle` aus der Unterlage.
Auch die zwei Lochmarken liegen an den proportional aus der Referenz
übernommenen Positionen. Diese Druckmerkmale erzeugen keine zusätzlichen
Geschäftsdaten.

Das feste amtliche Verteilerfeld zeigt **Leiter sowie S1, S2, S3, S4, S5 und
S6**. S5 gehört damit ausdrücklich zum amtlichen Raster. Der historische
Matrixwert `LS` wird dabei nur in der Anzeige als `Leiter` bezeichnet; seine
gespeicherte Koordinate ändert sich nicht. Unter den beiden weiteren
amtlichen Überschriften stehen jeweils genau sechs Zeilen für
**Fachberater** und sechs Zeilen für **Verbindungsstellen**. Nicht besetzte
Zeilen bleiben als Teil der unveränderten Formulargeometrie sichtbar, ohne
einen nicht vorhandenen Empfänger vorzutäuschen.

Lokal angelegte oder darüber hinausgehende Matrixempfänger werden weder
verworfen noch in das amtliche Raster gedrängt. Sie erscheinen in der
digitalen Ergänzung außerhalb des amtlichen Blatts und verwenden weiterhin
ihre vorhandenen Auswahl- und Speichernamen. Dort wird auch die eine grüne
Durchschrift zugeordnet: Eine gemeinsame Auswahl erlaubt keinen oder genau
einen Empfänger, niemals mehrere grüne Durchschriften. Die blauen
Verteilerwahlen bleiben davon unabhängig. In schreibgeschützten Schritten
werden vorhandene Verteilerdaten mit eindeutigen zugänglichen Namen
angezeigt. Wurde eine früher verwendete Funktion inzwischen aus der Matrix
entfernt, bleibt sie im historischen Nachweis mit allen belegten
Durchschriften sichtbar, ohne wieder auswählbar zu werden.

Für Gesprächsnotizen gilt die fachliche Ausnahme aus dem Nachrichtenlauf:
Die einzige grüne Durchschrift gehört immer der angemeldeten
Verfasserfunktion. Deshalb zeigt dieses Formular keine frei wählbare grüne
Kopie; der Server ergänzt die Autorenkopie zusammen mit der vorgeschriebenen
roten Lage-/Dokumentationskopie. Ein Browserwert kann weder den Verfasser
ersetzen noch eine zweite grüne Kopie erzeugen.

Das Blatt wird auf kleinen Bildschirmen nicht umsortiert. Stattdessen bleibt
das amtliche Raster unverändert und kann innerhalb eines ausdrücklich
beschrifteten Bereichs horizontal verschoben werden. Der restliche
Arbeitsbereich bleibt bei 390 CSS-Pixeln ohne horizontalen Seitenüberlauf.
Beim Drucken verschwinden Kopf, Aktionsleisten, Hilfen und digitale
Zusatzfelder; Farbe und Raster werden druckgetreu ausgegeben. Der mobile
Wischhinweis wird im Druckmedium ausdrücklich unterdrückt. Das gesamte Blatt
ist gegen Seitenumbrüche geschützt, wird proportional skaliert und passt in
Chrome nachweislich auf genau eine A4-Hochformatseite. Der Druckmaßstab
`0,78` ergibt für das 896-Pixel-Blatt rund 184,9 mm Breite; druckspezifisch
verdichtete Zeilenhöhen verhindern dabei einen Überlauf, ohne das Blatt auf
den zuvor deutlich kleineren Maßstab zu schrumpfen.

Die Aktionsleiste steht ober- und unterhalb des langen Formulars zur
Verfügung. Sie verwendet die bestehenden serverseitigen Aktionsschlüssel;
Antwort, Weiterleitung, Sichtung, LdF-/A/W-Bearbeitung, Anhänge und Abbruch
behalten damit ihre bisherigen Workflowgrenzen.

## Ausfüllhilfen

Jede der 20 Angaben aus der Ausfüllanleitung besitzt unmittelbar im
zugehörigen Feld eine mit **i** gekennzeichnete Hilfe:

| Nr. | Hilfe |
| ---: | --- |
| 1 | tatsächlich verwendetes TK-Mittel |
| 2 | Aufnahmevermerk |
| 3 | Annahmevermerk |
| 4 | Beförderungsvermerk |
| 5 | Technisches Betriebsbuch |
| 6 | Rufname der Gegenstelle / Spruchkopf |
| 7 | gewünschtes TK-Mittel |
| 8 | DURCHSAGE / Spruch |
| 9 | Sofort / Blitz |
| 10 | Anschrift |
| 11 | Rufnummer |
| 12 | Gesprächsnotiz |
| 13 | Inhalt – Betreff |
| 14 | Nachrichtentext |
| 15 | Absender |
| 16 | Abfassungszeit |
| 17 | Zeichen / Funktion |
| 18 | Quittung |
| 19 | Verteiler TEL / EL / EAL / UEAL |
| 20 | Vermerke / Erledigung |

Die Hilfen folgen den Formulierungen der bereitgestellten Anleitung. Dazu
gehört insbesondere bei Aufnahme- und Beförderungsvermerk ein mindestens
zweistelliges Datum sowie beim Nachrichtentext Blockschrift, wenn dies für die
Lesbarkeit erforderlich ist. **DURCHSAGE/Spruch (Ausnahme)** wird als solche
bezeichnet. Die Anleitung `Immer ausfüllen` ist bei Anschrift, Inhalt
(Betreff und Nachrichtentext), Absender, Abfassungszeit sowie Zeichen und
Funktion ausdrücklich in der jeweils zugehörigen Hilfe enthalten.

Eine Hilfe öffnet genau einen Dialog. Sie ist mit Maus, Touch und Tastatur
bedienbar und bleibt im sichtbaren Browserfenster. Beim Öffnen erhält der
Dialog selbst den Fokus. Schließen-Knopf und
`Escape` geben den Fokus an die auslösende Hilfe zurück; ein Klick außerhalb
schließt den Dialog und lässt den Fokus am angeklickten Ziel. Schaltfläche,
Überschrift und Text sind über `aria-controls`, `aria-labelledby` und
`aria-describedby` miteinander verbunden.

## Daten und Rollen

`11_rufnummer` und `12_betreff` sind eigenständige, einsatzgebundene
Nachrichtenfelder. Migration
`docker/db/migrations/98-official-message-form-fields.sql` ergänzt sie ohne
Änderung vorhandener Nachrichten; historische Datensätze erhalten für beide
Felder einen leeren Wert.

- Die Rufnummer ist optional, einzeilig und auf 128 Zeichen begrenzt.
- Der Betreff ist für neue schreibende Nachrichtenvorgänge erforderlich,
  einzeilig und auf 255 Zeichen begrenzt.
- Beide Werte werden bei Anhangsauswahl erhalten, in
  Nachrichtennachweis V2 einbezogen und in Einsatzexport, Einzel-PDF und
  PDF-Einsatzdossier ausgegeben.
- Historische V1-Terminalnachweise bleiben nur dann als vollständig gültiger
  Altbestand lesbar, wenn beide nachträglich ergänzten Felder leer sind.
  Nichtleere Rufnummern oder Betreffe können von V1 nicht belegt werden und
  lassen die Vollprüfung deshalb fail-closed scheitern.
- Eine Antwort übernimmt die belegte Rufnummer serverseitig und erhält einen
  kanonischen Betreff mit `AW:`.
- Eine Weiterleitung beginnt ohne Gegenstellenrufnummer und erhält
  serverseitig `WG:`. Verdeckte Browserfelder dürfen weder Ausgangsdaten noch
  Zitat oder Ableitung bestimmen.

Die bisherigen Feldrechte gelten weiter. Insbesondere kann A/W bei einem
Eingang keinen Absender eintragen; LdF ergänzt ihn aus dem Rufnamen.
Verfasser-, Aufnahme-, LdF-, Beförderungs- und Sichterzeichen stammen in den
jeweiligen Schritten aus der geprüften Sitzungsidentität und sind dort nicht
als frei änderbare Browserwerte wirksam. Aufnahme-, Annahme-, LdF-,
Beförderungs- und Abfassungszeit sind dagegen fachlich editierbare Angaben.
Nur die Sichterzeit wird beim erfolgreichen Abschluss des Sichtungsschritts
serverseitig erzeugt. Der bei einer Rückgabe festgeschriebene
Korrekturvermerk kann beim erneuten Einreichen nicht über ein verborgenes
Browserfeld ersetzt werden.

Der ältere zusätzliche Beförderungshinweis bleibt als Bestandsfunktion
erhalten. Da er nicht zum amtlichen Raster von 2022 gehört, erscheint er
klar getrennt unter **Betriebliche Ergänzungen**. Dort liegen auch die
S6-Wegauswahl, Beförderungsbestätigung, ein möglicher Rückgabegrund und die
systemseitig unterstützte Vorrangstufe Staatsnot. Diese Angaben verändern das
amtliche Blatt nicht.

Die Anhangsauswahl hält ungespeicherte Formulardaten pro Browser-Tab in einem
servergebundenen Entwurf. Zulässig sind höchstens 16 parallele Vorgänge,
1 MiB tatsächlicher Session-Speicher pro Entwurf und 8 MiB insgesamt.
Unbekannte Felder, verschachtelte Werte, ungültiges UTF-8 und Überschreitungen
werden vor jeder Sessionänderung abgewiesen; bestehende gültige Entwürfe
bleiben dabei unverändert. Eine SHA-256-Revision bindet die übertragenen
5×4-Koordinaten zusätzlich an exakt die Empfängermatrix, die beim Öffnen des
Formulars angezeigt wurde. Diese Revision ist kein Berechtigungstoken:
Funktion und Rolle bleiben vollständig serverseitig. Ändert sich die Matrix
während eines offenen Formulars oder Anhangvorgangs, wird der veraltete
Vorgang mit einem ausdrücklichen Konflikt beendet und muss neu geöffnet
werden; eine Koordinate wird niemals still auf eine andere Funktion
umgedeutet.

## Bewusste Abweichungen

Auf vorherige Projektvorgabe enthält die digitale Vorlage weder
VS-NfD-Aufdruck noch Wappen; auch ein unsichtbarer Wappen-Platzhalter wird
nicht erzeugt. Die amtliche Vierfach-Durchschreibefärbung des
Papierblocks wird nicht simuliert; die Bildschirmfassung verwendet den
blauen Nachrichtenteil, während Kopien und Verteiler als Daten erhalten
bleiben.

## Automatisierter Nachweis

`tests/php/official_message_form_ui_security.php` bindet unter anderem:

- genau 20 nummerierte, eindeutig zugeordnete Ausfüllhilfen und ausgewählte
  verbindliche Inhalte,
- eindeutige und zugängliche Dialogbeziehungen,
- Beschriftungsreihenfolge und Dreiteilung des amtlichen Rasters,
- getrennte Rufnummer-, Betreff- und Textfelder,
- den erhaltenen zusätzlichen Beförderungshinweis,
- servergebundene Sichteridentität,
- exakten Blauton, feste Rasterbreite, den durchgängigen linken Steg,
  getrennte Stempelwerte, den amtlichen Verteiler mit Leiter und S1–S6,
  jeweils sechs Fachberater- und Verbindungsstellenzeilen, erhaltene
  dynamische sowie historische Empfänger, mehrfarbige Bestandskopien, die
  einzelne grüne Durchschrift und deren Gesprächsnotiz-Ausnahme, Legende und
  Lochmarken,
- die Bindung jeder editierbaren Empfängerkoordinate an die versteckte
  Matrixrevision sowie die konfliktbehaftete Ablehnung veralteter Formulare,
- mobilen Scrollhinweis, dessen sichere Druckunterdrückung,
  Fokusdarstellung und Einseiten-Druckregeln,
- Einbindung des Renderers in Controller und Containerimage.

`tests/browser/headless_ui.py` prüft das tatsächlich berechnete Layout in
Chrome zusätzlich: 896 Pixel feste Blattbreite, drei Zonen in amtlicher
Reihenfolge, schwarzes Raster, `rgb(162, 217, 247)`, keine Bilder und keinen
VS-NfD-Text. Er öffnet alle 20 Hilfen, prüft eindeutige Dialogbeziehungen,
Viewportgrenzen, Außenklick, `Escape` samt Fokus und bei 390 × 844 Pixeln den
ausschließlich blattinternen horizontalen Scrollbereich. Zusätzlich bindet er
die berechneten Zell-, Steg-, Legenden-, Loch- und Adress-/Rufnummernmaße.
Aus genau dem im Browser gerenderten Formular erzeugt Chrome mit
`Page.printToPDF` einen echten Drucknachweis; akzeptiert werden genau ein
Seitenobjekt und eine A4-MediaBox von rund 595 × 842 Punkt. Berechnete
Blatthöhe, Seitenumbruchschutz und fehlender Wischhinweis werden vor dem
PDF-Aufruf ebenfalls geprüft. Anschließend werden genau diese von Chrome
erzeugten PDF-Bytes erneut in Chromes integriertem PDF-Renderer geöffnet.
Der daraus erzeugte sichtbare Nachweis muss die amtliche blaue Formularfläche
und das schwarze Raster enthalten. Damit prüft der Browserlauf nicht nur das
DOM vor dem Druck, sondern den tatsächlich erzeugten PDF-Inhalt; ein formal
gültiges, aber leeres PDF kann den Nachweis nicht erfüllen.

Der echte Nachrichten-HTTP-Lauf prüft Persistenz, Anhang-Roundtrip,
Antwort-/Weiterleitungsableitung und manipulierte Browserwerte. PDF-Smoke und
Poppler-Rendervergleich prüfen Rufnummer und Betreff in Einzelvordruck und
Gesamtdossier, A4, Suchtext, Folgeseiten, identische Renderer sowie das Fehlen
von VS-NfD und Wappen. Die fachliche Freigabe erfordert zusätzlich den
vollständigen Rollenlauf, die Bedienprüfung aller 20 Hilfen und einen
visuellen Bildschirm-/Druckvergleich mit beiden Referenzunterlagen gemäß
[Funktionsnachweis](FUNKTIONSNACHWEIS.md).
