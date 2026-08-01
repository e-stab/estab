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
2. **Nachricht** mit gewünschtem TK-Mittel, Nachrichtenform, dem Vorrangfeld
   für Sofort, Blitz und Staatsnot, Anschrift, Rufnummer, Gesprächsnotiz,
   Betreff, Nachrichtentext, Absender, Abfassungszeit und Verfasserangaben,
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
Antwort, Weiterleitung, Sichtung, die Bearbeitung durch LdF und Fernmelder sowie
Abbruch behalten
damit ihre bisherigen Workflowgrenzen. Anlagen liegen als eigener digitaler
Bereich direkt unterhalb des amtlichen Blatts; sie verändern dessen feste
Geometrie und Druckdarstellung nicht.

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
| 9 | Sofort / Blitz / Staatsnot |
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

Die bisherigen Feldrechte gelten weiter. Insbesondere kann der Fernmelder bei einem
Eingang keinen Absender eintragen; LdF ergänzt ihn aus dem Rufnamen.
Verfasser-, Aufnahme-, LdF-, Beförderungs- und Sichterzeichen stammen in den
jeweiligen Schritten aus der geprüften Sitzungsidentität und sind dort nicht
als frei änderbare Browserwerte wirksam. Aufnahme-, Annahme-, LdF-,
Beförderungs- und Abfassungszeit sind dagegen fachlich editierbare Angaben.
Nur die Sichterzeit wird beim erfolgreichen Abschluss des Sichtungsschritts
serverseitig erzeugt. Der bei einer Rückgabe festgeschriebene
Korrekturvermerk kann beim erneuten Einreichen nicht über ein verborgenes
Browserfeld ersetzt werden.

Der einsatzbezogene Berechtigungsmodus unterscheidet dabei Zuständigkeit und
Formularsemantik. In **Streng** darf nur die jeweils vorgesehene feste
Funktion/Rolle einen Schreibschritt ausführen. In **Locker** darf ein anderes
konkret angemeldetes, aktives und ungesperrtes Konto einen bekannten
Schreibschritt übernehmen; dessen echte Identität wird gespeichert. Die
Feldregeln werden dadurch nicht gelockert: Wer den Fernmelder-Eingangsschritt
ausführt, kann weiterhin keinen Absender einschleusen, Si kann weiterhin
keinen Ausgangsinhalt umschreiben und LdF-/Transport-/Rückgabezustände bleiben
in ihrer Reihenfolge. Auch Einsatzgrenze, Objektbezug, Sperrinhaber, CSRF,
Validierung, Anlagenintegrität und Ereignisnachweis bleiben unverändert.
Allgemeine Leserechte, Nachweisung, Zweitsichtungsarchive, Kategorien- und
Administrationsrechte werden durch **Locker** nicht erweitert. Nur die für
eine ausdrücklich gewählte Schreibstufe nötige Workflow-Objektsicht wird
zugelassen. Bei einer zurückgewiesenen Ausgangsmeldung darf in **Locker** eine
andere Funktion die Korrektur übernehmen; der Ereignisnachweis bewahrt dabei
ursprüngliche und neue verantwortliche Funktion getrennt.

Bereits mit genau dieser zurückgewiesenen Nachricht verknüpfte Anlagen
bleiben während der Korrektur sichtbar und wiederverwendbar. Diese Ausnahme
ist an Einsatz-ID, Nachrichten-ID, aktuellen Status und das konkrete Konto
gebunden. Vorschau, E-Mail-Ansicht und Download lesen Nachricht, Modus und
Konto unmittelbar vor der Ausgabe erneut. Die im Link enthaltene
Nachrichten-ID ist nur ein Selektor und keine Berechtigung; fremde oder
unverknüpfte Archivdateien werden dadurch auch in **Locker** nicht sichtbar.

Die Vorrangstufe wird ausschließlich im dafür vorgesehenen Feld des
Nachrichtenvordrucks ausgewählt. **Staatsnot** steht dort unmittelbar neben
**Sofort** und **Blitz** und wird bei gespeicherten Nachrichten in genau
diesem Feld markiert. Der zugehörige Warnhinweis macht deutlich, dass
Staatsnot nur auf ausdrückliche Weisung einer hierzu berechtigten Stelle
verwendet werden darf. Eine zweite Vorrangauswahl außerhalb des Blatts gibt
es nicht. Betriebliche Ablaufangaben wie S6-Wegauswahl,
Beförderungsbestätigung und ein möglicher Rückgabegrund bleiben davon
getrennt und verändern das amtliche Blatt nicht.

Die optionale Archivauswahl für bereits hochgeladene Anlagen hält
ungespeicherte Formulardaten pro Browser-Tab in einem servergebundenen
Entwurf. Zulässig sind höchstens 16 parallele Vorgänge,
1 MiB tatsächlicher Session-Speicher pro Entwurf und 8 MiB insgesamt.
Unbekannte Felder, verschachtelte Werte, ungültiges UTF-8 und Überschreitungen
werden vor jeder Sessionänderung abgewiesen; bestehende gültige Entwürfe
bleiben dabei unverändert. Eine SHA-256-Revision bindet die übertragenen
5×4-Koordinaten zusätzlich an exakt die Empfängermatrix, die beim Öffnen des
Formulars angezeigt wurde. Diese Revision ist kein Berechtigungstoken: Konto,
Funktion, Rolle und Berechtigungsmodus bleiben vollständig serverseitig.
Ändert sich die Matrix
während eines offenen Formulars oder Anhangvorgangs, wird der veraltete
Vorgang mit einem ausdrücklichen Konflikt beendet und muss neu geöffnet
werden; eine Koordinate wird niemals still auf eine andere Funktion
umgedeutet.

## Anlagen am Nachrichtenvordruck

Neue Dateien werden ohne Seitenwechsel direkt im noch bearbeitbaren
Nachrichtenvordruck hochgeladen. Das Formular verwendet dafür
`multipart/form-data`; Dateiname, Browser-MIME, Browser-Größe, Kürzel,
Zeitstempel und Zielname gelten nicht als Autoritätswerte. Der gemeinsame
Uploaddienst prüft Endung und den mit Fileinfo erkannten MIME-Typ und speichert
zweiphasig:

1. Eine erste Transaktion reserviert den internen Namen mit Status 8. Die
   Reservierung ist an Inhaber und Einsatz gebunden und noch nicht lesbar. Eine
   weitere kurze Staging-Transaktion persistiert die erwartete Endung, bevor
   ein Zielpfad an den Uploader übergeben wird. Danach werden die Bytes ohne
   langen Einsatz-Lock verschoben sowie SHA-256 und Bytezahl ermittelt.
2. Die kurze Finalisierungstransaktion beansprucht die Reservierung mit
   Status 2, prüft aktiven Einsatz, Inhaber und Kontofunktion erneut und
   finalisiert Metadaten, Integritätsnachweis, Serverzeit, sichtbaren Status 1
   sowie Audit atomar. Bei einem Rollback bleibt die Status-8-Reservierung
   unsichtbar.

Bei einem regulär behandelten Fehler ermittelt eine neue Datenbankverbindung
zuerst den autoritativen Zustand. Eine bereits finalisierte Status-1-Datei wird
nie entfernt. Nur eine eigene unfertige Status-8-Reservierung darf nach Prüfung
von Einsatz, Inhaber und Endung bereinigt werden. Der Dienst beansprucht sie
unter Zeilensperre atomar als Status 2, bevor er den validierten Zielpfad
löscht. Erst nach bestätigtem Fehlen der Bytes wird der interne Name als
Status 4 freigegeben. Dadurch kann kein Upload die Zeile zwischen Prüfung und
Löschen wiederverwenden oder finalisieren. Ein vor der Beanspruchung unklarer
Zustand bleibt unverändert fail-closed; bei hartem Abbruch danach oder
fehlgeschlagenem Löschen bleibt die unsichtbare Status-2-Cleanup-Zeile
gesperrt. Wechselt der aktive Einsatz zwischen Formularanzeige und
Finalisierung, betrifft die Bereinigung ausschließlich die Reservierung des
ursprünglich erfassten Einsatzes; der Vorgang endet mit einem Konflikt.

Der Bedienablauf ist bewusst direkt:

1. Datei auswählen und optional eine Beschreibung bis 255 Zeichen angeben.
2. Mit **Datei hochladen** den offenen Entwurf samt Empfängermatrix erhalten
   und die Anlage sofort am Vordruck anzeigen lassen. Wird stattdessen mit
   ausgewählter Datei direkt die reguläre Formularaktion ausgelöst, wird die
   Anlage vor dem Nachrichtenschritt sicher gespeichert und zugeordnet.
3. Die Anlagenzahl erscheint im Formularkopf und in der Aktionsleiste als
   sichtbares Badge. Jede Karte zeigt Originaldateiname, optionale
   Beschreibung, soweit belegten Zeitpunkt und Größe sowie die interne
   Anlagen-ID.
   Meldungsübersicht, zweite Sichtung und operative Warteschlangen zeigen
   dieselbe kanonische Anzahl als Hinweis-Badge.
4. JPEG-, PNG-, GIF- und BMP-Bilder erhalten innerhalb der unten beschriebenen
   Grenzen eine Miniatur; andernfalls erscheint ein neutraler Platzhalter.
   PDF-Dateien lassen sich innerhalb der Karte aufklappen und laden dann erst
   die Same-Origin-Browseransicht. Standardisierte RFC-822-E-Mails lassen sich
   als passive Textansicht innerhalb der Karte aufklappen oder getrennt
   öffnen. Diese vier Bildformate und PDF können außerdem in einem neuen
   Browser-Tab geöffnet werden. Alle zulässigen Formate einschließlich TIFF
   und `.eml` bleiben herunterladbar.
5. **Vom Vordruck entfernen** löst im bearbeitbaren Entwurf ausschließlich
   das exakte Referenztoken. Die bereits archivierte Datei wird nicht gelöscht
   und bleibt nach ihrer Objektregel für eine spätere Auswahl erhalten.

Eine direkte Formularaktion trägt ein einmaliges, an Konto, Einsatz,
Bearbeitungsart und bei Korrekturen an den Datensatz gebundenes Token. Die
Sitzung merkt die serverseitig erzeugte Referenz und den Zwischenstand „zur
Nachrichtensendung vorgemerkt“. Vor dem Nachrichtenspeichern wird dieser Stand
durch Schließen und erneutes Öffnen der Sitzung dauerhaft geschrieben. Im
selben Commit wie der Nachrichten-INSERT beziehungsweise die Korrektur landet
nur der SHA-256-Hash des Tokens im unveränderlichen Workflowereignis. Ein
tokenbezogener MariaDB-Advisory-Lock serialisiert Aktionsnachweis und
Nachrichtenspeicherung auch über parallele Requests hinweg.

Nach einem Antwortverlust kann ein Retry den exakten Einsatz, Akteur, Vorgang
und gegebenenfalls Korrekturdatensatz aus diesem Ereignis erkennen. Ein so
belegter Commit wird weitergeleitet und nicht als zweite Nachricht ausgeführt.
Nach einem fachlichen Validierungsfehler wird eine bereits archivierte Anlage
auch ohne erneut übertragenen Dateiteil wieder an den Entwurf gehängt. Bleibt
der Nachrichtenabschluss dagegen unbelegt, zeigt eStab den vollständigen
Entwurf samt Anlage wieder an und fordert zur Prüfung der Meldungsliste auf.
Eine bloß irgendwo verwendete Anlagenreferenz gilt absichtlich nicht als
Beweis für diesen Nachrichtentext, weil Anlagen mehrfach verknüpft werden
dürfen. Die Datei wird vor der fachlichen Nachrichtenvalidierung archiviert.
Scheitert diese Validierung, bleibt die Zuordnung im erneut angezeigten Entwurf
erhalten. Wird der Entwurf danach bewusst verlassen oder die Anlage entfernt,
bleibt nur die freie, nach ihren eigenen Rechten sichtbare Archivdatei zurück;
es erfolgt keine unbemerkte Dateilöschung. Ein abgebrochener oder nur teilweise
übertragener Datei-Upload ist noch nicht finalisiert und muss deshalb erneut
ausgewählt werden.

Die Replay-Sicherung ist keine gemeinsame Transaktion von MariaDB,
Anlagenvolume und PHP-Sitzung. Ein harter Prozess- oder Hostabbruch kann nach
dem Verschieben, aber vor Finalisierung und `finally`, eine unsichtbare
Status-8-Reservierung samt Staging-Datei hinterlassen. Bricht die reguläre
Bereinigung nach ihrer atomaren Beanspruchung Status 8 → Status 2 ab oder sind
die Bytes nicht löschbar, kann stattdessen eine verborgene
Status-2-Cleanup-Zeile mit oder ohne Staging-Datei verbleiben. Im noch engeren
Fenster nach erfolgreicher Anlagenfinalisierung und vor dem Session-Checkpoint
kann eine freie Archivdatei ohne dauerhafte Token-Zuordnung verbleiben; ein
Retry kann dann eine zweite Archivdatei anlegen. Enthält der
Nachrichten-Commit bereits den unveränderlichen Aktionsnachweis, verhindern
dieser und der Advisory-Lock eine stille Doppelnachricht auch dann, wenn der
Worker vor der abschließenden Sessionaktualisierung ausfällt. Verbliebene
Status-8-, Status-2- oder freie Status-1-Reste sind betrieblich zu prüfen und
nach der in der Betriebsdokumentation beschriebenen Reihenfolge zu bereinigen;
sie gelten nie automatisch als gespeicherter Vordruck.

Direktes Hochladen und Entfernen ist in den bearbeitbaren Vorgängen
`FM-Eingang`, `FM-Eingang_Anhang`, `Stab_schreiben`, `Stab_korrigieren` und
`Stab_gesprnoti` möglich. Nachfolgende Arbeitsschritte von LdF, Si und
Fernmelder zeigen die Anlagenkarten nur lesend. Ein Vordruck kann höchstens 100 kanonische
Anlagenreferenzen tragen.

**Bereits hochgeladene Anlage auswählen** führt weiterhin in den bisherigen
Anlagenbereich. Dieser Kompatibilitätspfad dient der Wiederverwendung einer
bereits zum aktiven Einsatz gespeicherten Datei; für neue Anlagen ist kein
zweiter Upload an anderer Stelle mehr erforderlich. Auch dieser Pfad erhält
den servergebundenen Entwurf und wiederholt beim Zurückkehren die
Einsatz-, Konto-, Objekt- und Matrixprüfung.

Zulässig sind `jpg`, `jpeg`, `tif`, `tiff`, `gif`, `avi`, `png`, `bmp`,
`zip`, `pdf`, `doc`, `xls`, `odt`, `txt`, `xia` und `eml`;
Groß-/Kleinschreibung der Endung wird normalisiert. Die Oberfläche zeigt die
effektive Größengrenze,
standardmäßig 20 MiB. Der Anwendungswert `ESTAB_UPLOAD_MAX_BYTES` ist auf
50 MiB hart begrenzt. Die Container-Dateigrenze entspricht diesem Maximum mit
`upload_max_filesize = 50M`; `post_max_size = 56M` liegt für den gesamten
Request darüber. Dadurch kann die Anwendung eine Datei oberhalb ihres
fachlichen Limits mit erhaltenem Entwurf
ablehnen; nur ein insgesamt zu großer Multipart-Request endet früh mit HTTP
413.

Für `.eml` gelten zusätzliche feste Grenzen. Akzeptiert werden ausschließlich
standardisierte RFC-822-Dateien, bei denen Endung, der von Fileinfo erkannte
MIME-Typ `message/rfc822` und eine begrenzt geparste MIME-Struktur
zusammenpassen. Outlooks proprietäres `.msg`-Format wird nicht unterstützt.
Unabhängig von einem bis 50 MiB erhöhten globalen Uploadlimit liest der
E-Mail-Parser höchstens 20 MiB. Eine falsch benannte, strukturell ungültige
oder größere Datei wird mit erhaltenem Nachrichtentwurf abgewiesen.

`/4fach/email.php` zeigt die Mail bewusst nicht als aktives HTML-Dokument,
sondern ausschließlich als escaped/passiv erzeugten Text mit ausgewählten
Kopfzeilen und Nachrichtentext. Skripte, Ereignisattribute, Formulare,
eingebettete Objekte und Remote-Ressourcen aus der Mail werden weder ausgeführt
noch nachgeladen. In der Mail enthaltene Anlagen werden nicht automatisch
geöffnet oder in die Webseite eingebettet; die Ansicht nennt nur ihre
Metadaten wie Dateiname, Inhaltstyp und Größe. Ein sichtbarer Sicherheitshinweis
stellt klar, dass Kopfzeilen und Absenderangaben nicht verifiziert sind und
eStab weder DKIM noch S/MIME prüft.

Die Anlagenkarte bietet zusätzlich den authentifizierten Download der
bytegetreuen `.eml`-Originaldatei. Dafür gelten dieselbe Objektberechtigung und
Integritätsprüfung wie für jeden anderen Nachrichtenanhang. Die Originaldatei
kann gleichwohl gefährliche Mail-Inhalte oder enthaltene Dateien
transportieren; der Download ist kein Unbedenklichkeitsnachweis und sollte nur
in einer dafür geeigneten Umgebung geöffnet werden.

Wird der Anlagenabschnitt im PDF-Einsatzdossier gewählt, erscheint eine
gültige EML innerhalb der PDF-Text-/Zeichengrenzen ebenfalls als passive
Darstellung ausgewählter Kopfzeilen und des Textkörpers. Interne Mail-Anlagen
bleiben Metadaten; die bytegetreue EML-Originaldatei wird wie jede andere
Vordruckanlage getrennt in das Dossier eingebettet. Ist eine verlustfreie
Darstellung nicht möglich, weist eine gekennzeichnete Informationsseite den
Grund aus, statt Inhalte still auszulassen oder aktiv zu interpretieren.

Die normale Downloadantwort bleibt eine Datei zum Herunterladen. Eine
ausdrückliche Browseransicht wird nur für serverseitig als JPEG, PNG, GIF,
BMP oder PDF erkannte Inhalte freigegeben; TIFF und andere Formate bleiben auch
bei einem manipulierten Vorschauwunsch Downloads. Bildvorschau,
Browseransicht und Download wiederholen jeweils Objektberechtigung und
Eingangsintegritätsprüfung. Die PDF-Einbettung ist auf dieselbe Origin begrenzt
und wird erst beim Aufklappen geladen. Eine HTML-Sandbox wird dort bewusst
nicht gesetzt, weil sie Chromiums eingebauten PDF-Viewer sperrt; andere
Antworten bleiben für Einbettung gesperrt. Eine sichtbare PDF-Darstellung hängt
zusätzlich davon ab, dass der verwendete Browser einen PDF-Viewer bereitstellt.
Die davon getrennte E-Mail-Route liefert niemals rohe `message/rfc822`-Bytes
inline, sondern ausschließlich die oben beschriebene passive HTML-Seite; das
Original bleibt ein Download.
Die automatische Miniatur wird ausschließlich für JPEG, PNG, GIF und BMP
versucht. Es gelten 24 MiB maximale Eingabedatei, 16 Megapixel maximale
Dekodierfläche und 1.600 Pixel je angeforderter Ausgabeachse; die Karte fordert
640 Pixel Breite an. Größere oder nicht sicher dekodierbare Bilder zeigen einen
neutralen Platzhalter, bleiben aber herunterladbar und – soweit zulässig – in
der separaten Browseransicht verfügbar. Diese UI-Grenzen sind unabhängig von
der strengeren 12-Megapixel-/8.000-Pixel-Grenze für das sichtbare Rendern im
PDF-Einsatzdossier.

## Digitale Darstellung

Die Vierfach-Durchschreibefärbung des Papierblocks wird nicht simuliert; die
Bildschirmfassung verwendet den blauen Nachrichtenteil, während Kopien und
Verteiler als Daten erhalten bleiben.

## Automatisierter Nachweis

`tests/php/official_message_form_ui_security.php` bindet unter anderem:

- genau 20 nummerierte, eindeutig zugeordnete Ausfüllhilfen und ausgewählte
  verbindliche Inhalte,
- eindeutige und zugängliche Dialogbeziehungen,
- Beschriftungsreihenfolge und Dreiteilung des amtlichen Rasters,
- getrennte Rufnummer-, Betreff- und Textfelder,
- die drei Vorrangstufen Sofort, Blitz und Staatsnot im einzigen amtlichen
  Vorrangfeld sowie das Fehlen paralleler Vorrang- oder Erreichbarkeitsfelder,
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

Der statische Formularnachweis bindet außerdem den direkten
`multipart/form-data`-Upload, die einzige kanonische Referenzliste,
Anlagenzahl und -karten, HTML-escaped Metadaten, Bild-/PDF-/E-Mail-Vorschau sowie das
reine Lösen einer Zuordnung. Der HTTP-Lauf lädt eine echte Datei direkt im
Vordruck hoch, prüft Datenbank- und Dateiintegrität, erhält den Entwurf und
weist nach, dass Entfernen die Archivdatei nicht löscht.
Er deckt außerdem Upload plus reguläres Absenden, einen Validierungsfehler mit
anschließendem Retry ohne erneut gesendete Datei, den sicheren Hinweis bei
uneindeutigem Nachrichtenabschluss, den wiederholbaren Gesprächsnotizübergang
ohne Doppelnachricht/-datei sowie Bild-, PDF- und E-Mail-Karten mitsamt
Browseransicht ab. Die E-Mail-Fixture enthält codierte Unicode-Kopfzeilen,
verschachtelte MIME-Teile, interne Anlagen und absichtlich aktive HTML-Inhalte.
Parser-, HTTP- und Browsernachweise verlangen die decodierte passive
Textdarstellung, reine Metadaten der internen Anlagen und die Abwesenheit von
Skriptausführung oder Remote-Abrufen. Der Originaldownload wird bytegleich
verglichen.
Die normalen Fehler- und Replaypfade sind damit belegt; ein harter Prozess- oder
Hostabbruch an den ausdrücklich dokumentierten Dateisystem-/Sessiongrenzen wird
nicht als atomar gelöst behauptet.

`tests/browser/headless_ui.py` prüft das tatsächlich berechnete Layout in
Chrome zusätzlich: 896 Pixel feste Blattbreite, drei Zonen in amtlicher
Reihenfolge, schwarzes Raster, `rgb(162, 217, 247)` und keine zusätzlichen
Bilder. Er öffnet alle 20 Hilfen, prüft eindeutige Dialogbeziehungen,
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
gültiges, aber leeres PDF kann den Nachweis nicht erfüllen. Der gleiche reale
Browser öffnet außerdem die E-Mail-Anlagenkarte erst auf Benutzeraktion,
prüft die sichtbare passive Darstellung im Same-Origin-Frame und weist nach,
dass die präparierten aktiven Mail-Bestandteile weder DOM noch Netzwerk
erreichen.

Der echte Nachrichten-HTTP-Lauf prüft Persistenz, Anhang-Roundtrip,
Antwort-/Weiterleitungsableitung und manipulierte Browserwerte. PDF-Smoke und
Poppler-Rendervergleich prüfen Rufnummer und Betreff in Einzelvordruck und
Gesamtdossier, A4, Suchtext, Folgeseiten, identische Renderer sowie das Fehlen
unbeabsichtigter Zusatzinhalte. Die fachliche Freigabe erfordert zusätzlich den
vollständigen Rollenlauf, die Bedienprüfung aller 20 Hilfen und einen
visuellen Bildschirm-/Druckvergleich mit beiden Referenzunterlagen gemäß
[Funktionsnachweis](FUNKTIONSNACHWEIS.md).
