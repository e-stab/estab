# PDF-Einsatzdossier

Über **Administration → PDF-Einsatzdossier** kann ein bestimmter Einsatz als
zusammenhängende, durchsuchbare DV-1-101-Einsatzdokumentation ausgegeben
werden. Der ausgewählte Einsatz muss dafür nicht aktiv sein; damit lassen sich
auch historische oder formal abgeschlossene Einsätze unverändert
dokumentieren.

Die ETB-/TBB-Seiten orientieren sich an der bereitgestellten Unterlage
„ETB-/und TBB-Führung in THW-Führungsstellen“, Handbuch ETB/TBB Version 1.0,
Stand März 2022, SHA-256
`2457d1deccd01892655bbc329b08885a0b3c8b3ebfb6372c79997d3427d1ae59`.
Diese Layoutannäherung ist **keine formale THW-Freigabe** des elektronischen
Verfahrens. Die Referenz verlangt eine Freigabe durch die THW-Leitung. Vor der
Nutzung als amtlicher urkundlicher Nachweis muss diese Freigabe für eStab
schriftlich vorliegen; die PDF-Prüfung in diesem Projekt belegt nur die
technische Darstellung.

Die Einsatz-Auswahl nennt Führungsstellenname, Einsatzkennung und
Einsatzname getrennt. Im Dossier stehen diese Angaben ebenfalls getrennt auf
dem Deckblatt; der Seitenkopf führt Führungsstelle und Einsatzidentität in
zwei getrennten, auf die verfügbare A4-Breite gekürzten Zeilen.
Ein vor Migration 97 angelegter Einsatz ohne bestätigten
Führungsstellennamen wird ausdrücklich mit **Führungsstelle historisch nicht
erfasst** beziehungsweise **historisch nicht erfasst** gekennzeichnet.
Einsatzname, Bedarfsträger, Einsatzleitung und Umgebung werden nicht als
scheinbarer Ersatz verwendet.

## Inhalt auswählen

Der Administrator wählt mindestens einen der folgenden neun Bereiche. Beim
Aufruf sind alle neun Bereiche ausgewählt:

- Einsatztagebuch (ETB),
- Technisches Betriebsbuch (TBB),
- alle Nachrichtenvordrucke des Einsatzes,
- Anhänge zu den Nachrichtenvordrucken samt Integritätsstatus,
- Nachrichtenereignisse und Nachrichtennachweisköpfe,
- Dienstorganisation: optionale Zugangsschichten mit allen aktuellen und
  entfernten Kontenzuordnungen sowie davon getrennt die historischen formalen
  Dienstschichten, Dienstbesetzungen und Übergaben,
- S6-Fernmeldeplanversionen mit sämtlichen Planeinträgen,
- Melderaufträge,
- Betriebsereignisse und Betriebsnachweiskopf.

Anhänge können nur zusammen mit den Nachrichtenvordrucken ausgewählt
werden. Der Server verwirft unbekannte, mehrfachdeutige oder nicht kanonische
Auswahlwerte; eine leere Auswahl wird nicht als scheinbar vollständiges
Dossier akzeptiert.

Die Dienstorganisation kennzeichnet optionale Zugangsschichten als aktuellen
Zugangsmechanismus und `nv_dienst*`-Daten ausdrücklich als historischen
Legacy-Nachweis. S6-Planung und Melderbeförderung enthalten jeweils alle
persistierten Status-, Zeit-, Gültigkeits-, Freigabe-, Empfänger-, Rückweg-
und Abschlussfelder. Keine ausgewählte leere Sektion verschwindet still: Das
Dossier sagt ausdrücklich, dass für den Einsatz keine entsprechenden
Datensätze vorhanden sind.

## ETB- und TBB-Formblätter

Das ETB wird als **Fb Fü 2** auf A4 hoch ausgegeben. Der Ausdruck enthält
genau die vier Formblattspalten:

- laufende lokale Nummer,
- Datum/Uhrzeit,
- Darstellung der Ereignisse,
- Bemerkungen.

Einsatzbezeichnung, Kopfdatum und „Seite n von N“ werden auf jeder ETB-Seite
wiederholt. Unter jeder Seite stehen die Linien für Leiter/-in Führungsstelle
und ETB-Führer/-in. Die internen Kennzeichen A/B/E/K/W, Referenzen,
Korrekturbeziehungen, die fachliche Ereigniszeit und eine optionale
Bearbeitungszuordnung bleiben im Datenbestand und in der Webansicht verfügbar,
werden aber nicht als formularfremde Zusatzspalten gedruckt. Die Zuordnung ist
nur eine Suchhilfe und kein Bestandteil des amtlichen Formblatts.

Bei einer Berichtigung wird im Formular ausschließlich die lokale ETB- oder
TBB-Nummer des unveränderlichen Originals ausgegeben. Auch wenn Original und
Berichtigung aus verschiedenen Dienstschichten stammen, löst der Export den
Bezug einsatzgebunden auf; ein globaler Datenbank-Primärschlüssel wird nie als
Buch- oder Korrekturbezugsnummer gedruckt.
Datum/Uhrzeit im Formblatt stammt aus der
unveränderlichen Erfassungszeit `estab_recorded_at`; bei Altdaten wird auf
`etb_time` und danach auf die Ereigniszeit zurückgefallen.

Ist einem ETB-Eintrag optional ein fertig hochgeladener Anhang zugeordnet,
druckt die Bemerkungsspalte dessen automatisch abgeleitete Nummer
`ETB {einsatz_id}-{estab_book_lfd}-1`. Die Anwendung führt genau ein ETB je
Einsatz und behandelt den einzelnen Upload als eine gebündelte digitale
Einheit; die letzte Komponente ist deshalb derzeit immer `1`. Das bereits bei
der Ablage vergebene Kennzeichen wie `EL0001` bleibt davon getrennt. Im
Anlagenverzeichnis stehen ETB-Anlagennummer und Ablagekennzeichen nebeneinander;
ein nicht als ETB-Anlage zugeordneter Anhang wird ehrlich als solcher
gekennzeichnet. Eine Anhangszuordnung ist nicht verpflichtend.

Das TBB wird als **Fb Fü 44** auf A4 quer ausgegeben. Seine sieben Spalten
bilden folgende Inhalte ab:

- laufende lokale Nummer,
- Datum/Uhrzeit,
- Einsatz-/Betriebsbereitschaft, Personal, Ablösung und Übergabe,
- Kanal/Rufgruppe, Bedienung und Wechsel,
- Nachricht von/an,
- Betriebsablauf/Ereignis sowie Störung/-beseitigung,
- Quittung, Empfänger und Aushändigung.

Datum/Uhrzeit im TBB bezeichnet dagegen die fachliche Vorgangszeit
`estab_event_time`; nur wenn sie im Altbestand nicht verfügbar ist, folgen
`tbb_time` und schließlich die Erfassungszeit.

Fernmeldebetriebsstelle, Arbeitsplatz und „Seite n von N“ werden auf jeder
TBB-Seite wiederholt; darunter steht die Unterschriftslinie für den/die
Leiter/-in Fernmeldebetrieb (LdF). Die Führungsstelle liefert die
Fernmeldebetriebsstelle. Das aktuelle Einsatzdatenmodell besitzt kein eigenes
Arbeitsplatzfeld; dieser Kopf bleibt deshalb leer, statt einen Wert zu
erfinden. Das entspricht dem in der Referenz beschriebenen Regelfall eines
einzigen TBB je Fernmeldebetriebsstelle, muss aber bei der örtlichen Abnahme
ausdrücklich geprüft werden. Strukturierte Altdaten werden nicht erfunden:
Legacy-Aktion und -Bemerkung bleiben sichtbar, nicht belegbare Fachspalten
bleiben leer.

Bei neuen TBB-Zeilen werden ausschließlich die fünf strukturierten
Fachfelder in ihre jeweilige Formblattspalte gesetzt. Die aus
Kompatibilitätsgründen zusätzlich gespeicherte Textzusammenfassung in
`tbb_aktion` wird dann nicht nochmals in der Betriebsspalte gedruckt.
`tbb_bemerk` bleibt dagegen ein eigenständiger Zusatz-, Korrektur- oder
Übergabenachweis und wird genau einmal in dieser Spalte ausgegeben. Nur ein
echter `legacy_import` ohne irgendeinen strukturierten Inhalt fällt vollständig
auf die historischen Texte zurück. Dadurch erscheint keine Tatsache doppelt
und zugleich geht Altbestand nicht verloren.

Lange ETB- und TBB-Einträge werden innerhalb derselben Tabellenzeile über
mehrere Seiten geteilt. Jede Folgeseite wiederholt den Formkopf, die lokale
Nummer und die Kennzeichnung „Fortsetzung“. Die Seitenzähler gelten jeweils
nur für das betreffende Buch, auch wenn es Bestandteil eines größeren
Dossiers ist. Die Unterschriftslinien sind für eine manuelle Zeichnung
vorgesehen. eStab erzeugt keine kryptografische, fortgeschrittene oder
qualifizierte elektronische Signatur. Beim formal geschlossenen Einsatz wird
der nicht beschriebene Restbereich des letzten ETB- und TBB-Formblatts mit
sichtbarem Spaltenraster diagonal gestrichen und als „Nicht beschriebener
Bereich“ gekennzeichnet. Bei einem noch offenen, ausdrücklich vorläufigen
Abzug bleibt der Bereich für die Fortführung dagegen ungestrichen.

Der PDF-Dialog bietet für die beiden Buchabschnitte zwei ausdrückliche
Umfänge: **Gesamtbuch** oder einen Legacy-Filter **Historische Dienstschicht**
mit Nummer, Bezeichnung und Status. Die ausgewählte formale Altschicht wird innerhalb des
konsistenten Exportsnapshots erneut gegen den gewählten Einsatz geprüft. Eine
Schichtausgabe enthält nur ETB-/TTB-Zeilen mit genau dieser gespeicherten
`estab_shift_id`; Gesamtbuch umfasst auch neue und historische Zeilen ohne
belegbare Schichtzuordnung. Zugangsschichten sind keine Logbuchprovenienz.
Nachrichtenvordrucke, Anhänge, Nachrichtenereignisse, Dienstorganisation,
S6-Pläne, Melderläufe und Betriebsereignisse bleiben unabhängig
davon einsatzweit. Die Auswahl beginnt also kein neues Buch und verändert
weder lokale Nummern noch den Datenbestand.

Das Deckblatt nennt den gewählten ETB-/TTB-Umfang. Bei einer Dienstschicht
werden zusätzlich Nummer, Bezeichnung, Status sowie vorhandene Planungs-,
Aktivierungs- und Endzeiten wiedergegeben; beim Gesamtbuch wird kenntlich
gemacht, dass auch historische Zeilen ohne nachweisbare Schicht enthalten sein
können.

Im historischen Legacy-Unterabschnitt stehen Initiierungs- und Bestätigungszeit der
Übergabeanforderung getrennt. Das historisch benannte Datenbankfeld
`nv_dienstuebergaben.uebergeben_am` bezeichnet den Abschlusszeitpunkt der
zweistufigen Übergabe und wird deshalb im Dossier eindeutig als „Übernahme
bestätigt am“ beschriftet; als fachliche Übergabezeit gilt `initiiert_am`.

Jede Nachricht wird mit exakt demselben A4-Formularrenderer ausgegeben wie
unter **Generierte Vordrucke**. Dadurch stimmen Raster, Feldpositionen,
Empfängerkennzeichnung und mehrseitiger Inhaltsfluss in Einzel- und
Gesamtexport überein.
Die Nachweisnummer des Vordrucks stammt in Einzelgenerator, Detailansicht und
Dossier ausschließlich aus dem ersten verknüpften TBB-Eintrag mit dem exakten
Typ `nachricht`. Ein späterer append-only LdF-Nachtrag des Typs `korrektur`
ersetzt diese ursprüngliche Nummer nicht.
Eine interne Gesprächsnotiz ohne TBB-Nachweis lässt dieses Formularfeld leer,
statt eine fachlich andere Nummer vorzutäuschen. Davon getrennt verwendet der
kanonische Archivdateiname stets die positive einsatzlokale Nachrichtennummer
als technische Identität. Diese Nummer wird nicht ersatzweise in das sichtbare
Nachweisfeld gedruckt; dadurch bleiben Download, Archiv und Neu-Rendering auch
dann eindeutig, wenn Nachrichten- und TBB-Nummer voneinander abweichen.
Auch die interne globale Datenbank-ID einer Nachricht bleibt ausschließlich
Audit-/Relationsmetadatum: Sie wird weder als „Nachricht #…“ in die
Bemerkungsspalte des Fb Fü 2 noch in „Nachricht von/an“ des Fb Fü 44 gedruckt.

Bei nach Migration 97 neu erfassten Nachrichten stammt die lokale Anschrift
eines Eingangs beziehungsweise die lokale Absendereinheit eines Ausgangs aus
dem Führungsstellennamen des in derselben Schreibtransaktion gesperrten
Einsatzes. Der PDF-Renderer gibt diese gespeicherten Nachrichtendaten wieder;
ein Browserwert oder eine globale Konfiguration kann beim Export keine andere
Identität einschleusen.

Auch die Vorrangsstufe durchläuft denselben zentralen Übersetzer:
`sss`, `bbb` und `aaa` erscheinen im Einzelvordruck und im Dossier als
**Sofort**, **Blitz** beziehungsweise **Staatsnot**. Ein leerer Wert und das
historische `eee` lassen das Feld im PDF leer. Die gespeicherten internen
Kürzel werden dafür nicht verändert; dadurch bleiben Nachrichtennachweise,
Hashketten und rohe Tabellen-/CSV-Exporte reproduzierbar.

Die Seite **Generierte Vordrucke** unterscheidet dabei bewusst Quelle und
Darstellung: Beim Nachrichtenabschluss bleibt ein vollständiges PDF atomar im
persistenten Einsatzspeicher archiviert. Der sichtbare Button **PDF im
aktuellen Layout öffnen** prüft erneut aktiven Einsatz, Abschluss- und
Druckstatus und rendert anschließend aus dem aktuell gespeicherten
Nachrichtendatensatz und der aktuellen Matrix einen nur lesenden PDF-Abzug im
Speicher. Alte Archivdateien müssen nach einem Vorlagen-Upgrade deshalb weder
überschrieben noch manuell zurückgesetzt werden. Das Archiv bleibt bytegleich
für Backup und Restore erhalten; der geöffnete Abzug verwendet immer denselben
Renderer wie die Nachrichtenseiten eines neu erzeugten Dossiers.

Die Legacy-Datenbank speichert die Empfängermatrix nicht historisch pro
Einsatz. Das Dossier liest deshalb die zum Exportzeitpunkt gültige Matrix im
selben Datenbank-Snapshot. Eine im Nachrichtendatensatz gespeicherte Funktion,
die darin nicht mehr vorkommt, wird nicht verschluckt oder in eine erfundene
Rasterposition gesetzt: Unter dem Nachrichteninhalt erscheint einmal
**Empfänger außerhalb aktueller Matrix** mit Funktion und gespeichertem
Kopiekennzeichen, zum Beispiel `ALT_1 [gn]`.

## Sichtbare Anlagen und bytegleiches Original

Jede abgeschlossene Datei erscheint nach dem Anlagenverzeichnis in einer
eigenen, geordneten Anlagensektion. Die Darstellung hängt vom gegen die
Dateiendung geprüften MIME-Typ ab:

- JPEG, PNG, GIF und BMP werden proportional, zentriert und ohne Beschnitt auf
  einer eigenen Seite dargestellt. Transparenz wird für den Seitenabzug auf
  weißem Hintergrund normalisiert; das jeweilige Original bleibt davon
  unberührt. Bei animierten GIFs dient die erste Bildebene als statische
  Vorschau; das vollständige GIF bleibt bytegleich eingebettet.
- Eine Textdatei (`text/plain`, `.txt`) wird nur dann vollständig als
  durchsuchbarer Text ausgegeben, wenn ihr Inhalt verlustfrei mit dem
  Windows-1252-Basiszeichensatz der PDF-Kernschrift darstellbar ist. Nicht
  darstellbare Unicode- oder Steuerzeichen führen zu einer eindeutigen
  Hinweisseite, nicht zu still ersetzten Zeichen.
- Eine PDF-Anlage wird mit Poppler seitenweise gerastert. Jede Originalseite
  erhält in derselben Reihenfolge eine eigene sichtbare Dossierseite mit der
  Beschriftung **Originalseite n von N**. PDF-Anmerkungen werden nicht
  ausgeblendet und gehören damit zum sichtbaren Abzug.
- TIFF, ZIP-Archive, Office-Dokumente, Videos und andere nicht verlässlich
  statisch darstellbare Formate erhalten eine eindeutige Hinweisseite. eStab
  behauptet für diese Formate keine unvollständige oder verfälschte Vorschau.

Jede Anlage wird am Beginn ihrer sichtbaren Darstellung mit Dateiname und
Endung, erkanntem MIME-Typ, Bytezahl und SHA-256 ausgewiesen. Eine für die
sichtbaren Formate reservierte Endung mit unpassendem MIME-Typ wird nicht als
Anlage ausgegeben; der gesamte Export bricht stattdessen fail-closed ab. Der
sichere Dateiname
steht zusätzlich im Nachrichtenvordruck. Bei einem historischen Einsatz wird
dort bewusst kein Link auf den Downloadbereich des aktuell aktiven Einsatzes
erzeugt. Das Anlagenverzeichnis nennt außerdem den portablen Dateinamen,
zugehörige Nachrichtendatensätze und gegebenenfalls ETB-Anlagennummer sowie
Ablagekennzeichen.

Die sichtbare Darstellung ist nur ein Leseabzug. Unabhängig davon wird jede
Datei als **bytegleiches Original** in den PDF-1.7-Katalog eingebettet;
gängige PDF-Leser zeigen sie in ihrer Anlagenansicht an. Bei Bild- und
PDF-Anlagen darf der sichtbare Abzug deshalb technisch andere Bytes besitzen,
ohne den Originalnachweis zu verändern.

Für Darstellung und Einbettung verwendet der Renderer ausschließlich den
einmal vollständig und stabil gelesenen Byte-Snapshot. Fileinfo bestimmt den
MIME-Typ atomar aus exakt diesen später eingebetteten Bytes; er muss mit dem
zuvor ermittelten Typ übereinstimmen. Der Quellpfad wird danach nicht erneut
geöffnet. Temporäre Renderdateien liegen in einem privaten Verzeichnis mit
Modus `0700`, werden mit Modus `0600` beziehungsweise für Poppler-Ausgaben
`0640` geschrieben und nach Erfolg wie Fehler entfernt; Pfade daraus gelangen
nicht in das Dossier.

Wird der PHP-Prozess hart beendet, kann dieser `finally`-Abbau nicht mehr
laufen. Deshalb bereinigt der Container beim nächsten Start ausschließlich
mehr als 24 Stunden alte Arbeitsverzeichnisse unter `/tmp`, die vollständig
dem strengen Renderer-Vertrag entsprechen: kanonischer Name, Eigentümer
`www-data`, Verzeichnismodus `0700`, flacher Inhalt, erlaubte Dateinamen,
reguläre Einzeldateien mit genau einem Link und Modus `0600` oder `0640`.
Sobald ein unerwarteter Eintrag, Link, Eigentümer oder Modus vorkommt, bleibt
das gesamte Verzeichnis als Beweismittel unangetastet.

Für nach Migration 95 eingegangene Dateien berechnet eStab beim atomaren
Finalisieren SHA-256 und Bytezahl und speichert beide zusammen mit der
Serverzeit unveränderlich in `nv_anhang`. Vor dem Laden und nochmals beim
Einbetten vergleicht der Export die reale Datei mit diesem Eingangsnachweis.
Fehlender Nachweis, abweichende Größe oder auch eine gleich große Datei mit
anderem Inhalt bricht den Export ab. Eine nachträglich veränderte Datei wird
damit nie als eingegangene Originaldatei zertifiziert.

Migration 95 markiert ausschließlich die beim Upgrade bereits vorhandenen
Zeilen als Legacy. Für sie kann ein heute berechneter Hash nicht beweisen,
welche Bytes ursprünglich eingingen. Deckblatt und Anlagenverzeichnis nennen
deshalb ausdrücklich **Integrität beim Eingang nicht belegbar** und zeigen
einen Hash allenfalls als Prüfsumme der jetzt eingebetteten Datei, nie als
nachträglich erfundenen Eingangsnachweis. Neue Datensätze können durch
Datenbank-Trigger weder als Legacy angelegt noch nach der Finalisierung
herabgestuft oder mit einem anderen Eingangsnachweis versehen werden.

## Deckblatt, Abschluss und Nachweisstatus

Das Deckblatt kennzeichnet den Rechtsstand unübersehbar:

- Ein offener Einsatz trägt den roten Banner
  **VORLÄUFIG – Einsatz nicht formal abgeschlossen**.
- Ein formal geschlossener Einsatz trägt den grünen Banner
  **FORMAL ABGESCHLOSSEN**.

Zusätzlich nennt das Deckblatt Abschlusszeit, abschließende Identität,
Abschlussvermerk, die mindestens zehn Jahre ab formalem Abschluss reichende
Aufbewahrung, Legal-Hold-Status, Hold-Grund, Hold-Zeit und verantwortliche
Identität. Ein historisch gesetztes `ende` ersetzt den formalen Abschluss
nicht.

Der Nachrichten-Nachweis wird nicht aus dem gespeicherten Kopfstatus
übernommen. Innerhalb desselben konsistenten Snapshots berechnet eStab für
jede Ereigniszeile erneut:

- SHA-256 des unveränderten Feldsnapshots,
- Vorgängerbeziehung innerhalb der jeweiligen Nachrichtenkette,
- kanonischen Ereignis-Hash,
- Ereignisanzahl und letzten Hash jedes Nachrichtennachweiskopfs,
- einen stabilen Summenhash über alle Nachrichtennachweisköpfe.

Für neue terminale Status-8-Ereignisse vergleicht der Export außerdem den
vollständigen kanonischen Nachrichtensnapshot mit der im Snapshot gelesenen
Livezeile. Druck-, Sperr- und Lesemarker gehören bewusst nicht zu dieser
fachlichen Bindung. Eine fehlende oder abweichende neue Terminalbindung macht
den Nachweis ungültig. Ein vor Einführung dieses Vertrags erzeugter
`legacy_import` bleibt als gültig verketteter historischer Import sichtbar,
wird aber getrennt als **historischer Import – keine Live-Bindung belegbar**
gezählt; das Dossier behauptet dann nicht „vollständig gültig“.

Auch die einsatzweite Betriebsereigniskette wird aus Sequenz, Objekttyp,
Objekt-ID, Aktion, Akteur, Ereigniszeit, vollständigem JSON-Detail und
Vorgänger-Hash neu berechnet. Der berechnete Endhash wird mit Sequenz und Hash
des persistierten Betriebsnachweiskopfs verglichen. Das Deckblatt zeigt
Nachrichten-Head-Summenhash und Betriebs-Head-Hash; die Detailsektionen weisen
sämtliche Einzelköpfe, Hashes, Snapshots und Prüfergebnisse aus.
Zugangsschichtmutationen erscheinen mit Objekttyp `ZUGANGSSCHICHT`.
Nachrichtenzähler-Reparaturen erscheinen ohne Schichtpflicht mit Objekttyp
`EINSATZ`; beide bleiben dadurch einsatzgebunden hashverkettet nachweisbar.

## Vollständigkeits- und Sicherheitsgrenzen

Alle exportierten Fachdaten werden ausschließlich über vorbereitete SELECTs
mit der ausgewählten `einsatz_id` gebunden und in einem konsistenten
MariaDB-Read-only-Snapshot gelesen. Tabellen ohne eigene Einsatzspalte
(`nv_dienstbesetzungen` und `nv_fernmeldeplan_eintraege`) werden über ihre
einsatzgebundene Dienstschicht beziehungsweise Planversion eingegrenzt. Ein
historischer Datensatz eines anderen Einsatzes oder eine während der
Erzeugung erst hinzugekommene Teilmenge kann daher nicht versehentlich in das
Dossier geraten. Für Anhänge gelten zusätzlich die zentrale
Dateinamen-Allowlist, die Ablagegrenze, die gespeicherte
SHA-256-/Größenbindung für neue Anhänge und ein stabiler Datei-Lesevorgang. Fehlt
eine als abgeschlossen geführte Datei, ist ein Nachrichtenvordruck mit einem
nicht abgeschlossenen Anhang verknüpft oder ändert sich eine Datei beim Lesen,
wird kein scheinbar vollständiger Export ausgeliefert.

Die Gesamtgröße eingebetteter Dateien ist standardmäßig auf
`52428800` Byte (50 MiB) begrenzt. Sie kann in `.env` mit
`ESTAB_PDF_ATTACHMENT_MAX_BYTES` nur zwischen 0 und 52428800 Byte eingestellt
werden; 50 MiB sind zugleich die unveränderliche harte Obergrenze. Die Grenze
schützt den 256-MiB-PHP-Prozess, weil die alte FPDF-Laufzeit das Dokument im
Speicher aufbaut. Bei Überschreitung bricht die Erzeugung sichtbar ab; Dateien
werden niemals stillschweigend weggelassen.

Zusätzlich gelten für die sichtbare Darstellung feste, nicht per Request
aufweitbare Sicherheitsgrenzen:

- höchstens 100 Originalseiten je PDF-Anlage und höchstens 200 sichtbare
  Anlagenseiten im gesamten Dossier,
- höchstens 24 MiB erzeugte Rasterdaten insgesamt und 8 MiB je
  PDF-Seitenprozess beziehungsweise Rasterseite,
- höchstens 12 Megapixel je Bild und 8.000 Pixel je Achse für direkt gelieferte
  JPEG-/PNG-/GIF-/BMP-Bilder,
- höchstens 512 KiB für die sichtbare Ausgabe einer Textdatei,
- höchstens 60 Sekunden gemeinsames Laufzeitbudget für die gesamte sichtbare
  Anlagensektion sowie höchstens 15 Sekunden für `pdfinfo` und jeden einzelnen
  PDF-Seitenprozess.

PDF-Seiten werden mit 150 dpi und höchstens 2.000 Pixeln an der langen Achse
einzeln gerastert; Anmerkungen bleiben dabei sichtbar. Dadurch gilt das
8-MiB-Dateilimit schon für jeden isolierten Poppler-Seitenprozess. Poppler
läuft ohne Shell in einem festen privaten Arbeitsbereich und zusätzlich unter
Betriebssystemgrenzen für CPU, Adressraum, Dateigröße, Prozesse, Core-Dumps und
offene Dateien. Eine beschädigte, verschlüsselte, unvollständig lesbare oder
ein Limit überschreitende darstellbare Anlage bricht den Dossierexport ab; sie
wird weder still ausgelassen noch durch eine irreführende Hinweisseite ersetzt.

Der Download ist ausschließlich über den separat mit HTTP Basic Auth
geschützten Administrationsbereich und einen POST mit Session-CSRF möglich.
Die Antwort wird mit `no-store`, `nosniff` und einer Sandbox-CSP ausgeliefert.
Nach erfolgreicher Erzeugung schreibt eStab einen `pdf_export`-Eintrag in das
Einsatzprotokoll. Er enthält Abschnittsauswahl, den aufgelösten
ETB-/TTB-Umfang samt Schichtmetadaten, Datensatzanzahlen, PDF-Größe,
Anhangsgröße und SHA-256 der vollständigen PDF. Zusätzlich protokolliert er
mit `attachment_visible_count`, `attachment_visible_pages`,
`attachment_rendered_count`, `attachment_rendered_pages` und
`attachment_information_pages`, wie viele Dateien und Seiten sichtbar
ausgegeben, inhaltlich gerendert oder nur ehrlich erläutert wurden. Der Audit
enthält weder Kennwörter noch interne Dateipfade.

## Funktionsnachweis

Die automatisierten Tests prüfen unter anderem:

- strikte Einsatz- und Abschnittsauswahl,
- Gesamtbuch- und Dienstschichtauswahl mit erneuter Einsatzzuordnung,
  ausschließlicher ETB-/TTB-Filterung sowie Umfang auf Deckblatt und im Audit,
- getrennte Darstellung von Führungsstellenname, Einsatzkennung und
  Einsatzname sowie die ehrliche Kennzeichnung eines historischen
  `NULL`-Werts,
- vorbereitete, einsatzgebundene Abfragen aller neun Bereiche,
- einsatzlokale ETB-/TBB-Nummern, strukturierte TBB-Felder sowie unveränderte
  Legacy-Inhalte,
- gespeicherte Dienstschichtprovenienz, ehrliche historische `NULL`-Werte und
  das Fehlen der ETB-Bearbeitungszuordnung im amtlichen PDF,
- vollständige Dienst-, S6- und Melderketten,
- Neuberechnung von Nachrichten- und Betriebsereignishashes samt Kopfvergleich,
- Status-8-Terminalbindung einschließlich sichtbarer Legacy-Ausnahme,
- Vorläufig-/Formal-Banner, Retention und Legal Hold,
- Traversal-, Symlink-, MIME-, Größen- und Duplikatgrenzen,
- eingebetteten PDF-1.7-Dateikatalog und SHA-256,
- echte Extraktion des unveränderten, am Eingang gebundenen Beispielanhangs,
- vollständige sichtbare JPEG-/PNG- und verlustfreie Textfixtures, eine
  seitenweise geordnete Wiedergabe mehrseitiger PDF-Anlagen sowie den
  Containervertrag für GD-Leseunterstützung von JPEG, PNG, GIF und BMP,
- eindeutige Hinweisseiten für nicht statisch darstellbare Binärformate,
  passende Sichtbarkeits-/Render-/Hinweiszähler und die Abweisung von
  MIME-/Endungsabweichungen, beschädigten PDFs und überschrittenen Limits,
- atomare MIME-Erkennung aus dem eingebetteten Byte-Snapshot,
- private, auch nach einem Renderfehler vollständig entfernte
  Temporärbereiche ohne Pfadleck in der Ausgabedatei,
- den fail-closed Startup-Janitor für ausschließlich vollständig validierte,
  mehr als 24 Stunden alte Render-Arbeitsverzeichnisse,
- Ablehnung einer nach dem Laden oder vor dem Export gleich groß manipulierten
  Datei sowie ehrliche Legacy-Kennzeichnung ohne erfundenen Eingangshash,
- durchsuchbaren Text für ETB, TBB und Nachrichten,
- Fb Fü 2 auf jeder A4-Hochformatseite mit vier festen Spalten, lokalem
  Seitenzähler und beiden Unterschriftslinien,
- Fb Fü 44 auf jeder A4-Querformatseite mit sieben festen Spalten, lokalem
  Seitenzähler und LdF-Unterschriftslinie,
- spaltenrichtige Bounding Boxes, Fortsetzungsseiten, wiederholte Formköpfe,
  aufgelöste Seitenzahl-Platzhalter und ausschließlich lokale Buchnummern,
- ETB-Zeit aus Erfassungszeit und TBB-Zeit aus fachlicher Vorgangszeit,
- diagonal gestrichene, beschriftete Restbereiche ausschließlich bei formal
  geschlossenen ETB-/TBB-Formularen,
- genau einmal ausgegebene strukturierte TBB-Inhalte ohne Duplikat aus
  `tbb_aktion`, die eigenständige Bemerkung `tbb_bemerk` genau einmal in der
  Betriebsspalte sowie den Legacy-Fallback nur bei vollständig fehlenden
  strukturierten Feldern,
- automatisch gebildete ETB-Anlagennummern in Fb Fü 2 und
  Anlagenverzeichnis, getrennte Ablagekennzeichen und die Abweisung eines
  mehrdeutigen Mehrfachlinks,
- Rufnummer und Betreff in der amtlichen Reihenfolge vor dem Nachrichtentext,
- dieselben Formularmarker in Einzel- und Gesamtexport,
- aktuelle In-Memory-Ausgabe trotz unverändert erhaltener Archivdatei,
- das festgelegte, bildfreie Layout der Nachrichtenvordruck-Seiten; außerhalb
  der ausdrücklich sichtbaren Anlagenseiten ist ausschließlich das vorhandene
  400-x-396-Pixel-THW-Kopfzeichen der amtlichen ETB-/TBB-Formköpfe zulässig,
- verlustfreie Anzeige nicht mehr in der Matrix vorhandener Empfänger,
- pixelidentisches A4-Rendering der direkten und im Dossier enthaltenen
  ETB-, TBB- und Nachrichtenseiten einschließlich mehrseitigem Inhaltsfluss
  und produktivem Wechsel zwischen Hoch- und Querformat.

Der echte MariaDB-Nachweis `tests/integration/incident_export.php` legt
mehrere Schichten an und lädt Gesamtbuch sowie eine einzelne Dienstschicht
getrennt. Nur ETB/TBB dürfen sich dabei verkleinern; alle weiteren ausgewählten
Dossierabschnitte müssen unverändert einsatzweit bleiben. Schichtmetadaten auf
dem Deckblatt und der im `pdf_export`-Audit gespeicherte Umfang werden
mitgeprüft. Außerdem legt der Test
ETB-, TBB-, Nachrichten-, Terminalnachweis- und Anhangdaten in zwei
verschiedenen Einsätzen an, macht den ausgewählten Einsatz vor dem Lesen
historisch und verlangt für jede Sektion ausschließlich dessen Datensätze.
Er prüft die neu berechnete Kette, den Nachrichtennachweiskopf und die
Status-8-Livebindung. Leere Organisationssektionen werden explizit als leer
geliefert und mit gültigem leeren Betriebsnachweis dargestellt. Anschließend
extrahiert der Test den tatsächlichen `/EmbeddedFile`-Stream aus der erzeugten
PDF, vergleicht ihn bytegenau mit der am Eingang gehashten Fixture und prüft
sowohl deren SHA-256 als auch den SHA-256-Wert der vollständigen PDF. Danach
ersetzt er die Datei durch gleich viele andere Bytes: Sowohl erneutes Laden
als auch Einbetten aus dem schon geladenen Bundle müssen fail-closed
scheitern. Der Test läuft
unmittelbar nach der Einsatzaktivierung im vollständigen Container-CI-Gate.

Zusätzlich erzeugt `tests/php/pdf_template_render_fixture.php` aus identischen
Nachrichten- und Matrixdaten Einzelvordruck, direkte Dossier-Nachrichtenseite,
beide mehrseitigen Varianten, eigenständige mehrseitige Fb-Fü-2-/Fb-Fü-44-
Fixtures sowie ein repräsentatives vollständiges Dossier in der produktiven
Folge Deckblatt, ETB, TBB, Nachricht, Nachrichtennachweis,
Dienstorganisation, S6-Planung, Melderauftrag, Betriebsnachweis,
Anlagenverzeichnis und sichtbare Text-/JPEG-Anlagenseiten.
`tests/static/pdf_render.sh` prüft sie mit Poppler: A4 und Seitenzahl über
`pdfinfo`, Formulartexte und historischen Empfänger-Fallback über
`pdftotext`, vollständige Textanlagen und sichtbare Anlagenmetadaten, den
konstanten linken Folgeseiteneinzug über dessen
Bounding-Box-Ausgabe sowie einen eigenen Maximalwert-Fall mit 128 Zeichen
Führungsstelle, 64 Zeichen Kennung und 255 Zeichen Einsatzname. Fehlende
beziehungsweise unerlaubte Rasterbilder werden seitenbezogen über `pdfimages`,
pixelgleiche PNGs über `pdftoppm` und beide am Eingang gebundenen Originale
über `pdfdetach` und `cmp` geprüft. Sämtliche Seiten der repräsentativen
Fixture werden vollständig zu PNG gerendert. Die
direkten ETB-/TBB-Formularseiten müssen dabei seitenweise bytegleich mit den
entsprechenden Seiten im Gesamtdossier sein. Auf der produktiven
Nachrichtenseite wird ausschließlich das absichtlich globale Seitenzahlfeld
vom Pixelvergleich ausgenommen.

Der Container-Integrationstest
`tests/integration/pdf_attachment_render.php` erzeugt zusätzlich eine
zweiblättrige PDF-Anlage und ein transparentes PNG. Er verlangt drei sichtbare
Rasterseiten in Quellreihenfolge, passende Audit-Zähler, zwei bytegleiche
`EmbeddedFile`-Streams und einen nach Erfolg unveränderten temporären
Verzeichnisbestand. Ein absichtlich beschädigtes, aber korrekt gehashtes PDF
muss fail-closed scheitern und ebenfalls keinen Arbeitsbereich zurücklassen.
`tests/static/pdf_temp_cleanup.sh` belegt zusätzlich, dass der Startup-Janitor
nur alte, vollständig kanonische Arbeitsbereiche entfernt und aktuelle oder
mit unerwartetem Inhalt, Modus, Eigentümer, Symlink beziehungsweise Hardlink
versehene Kandidaten vollständig bewahrt.
GitHub Actions lädt PDFs, Textauszüge, Prüfinformationen und Render-PNGs
14 Tage als `pdf-render-evidence-*` hoch. Eine sichtbare Verschiebung der
Vorlage oder ein unerwartetes Seitenbild sperrt damit die CI.

Für die manuelle Abnahme sollte ein Dossier mit realistischen langen
Einsatznamen, mehrseitigen Einträgen und allen in der Organisation verwendeten
Anhangstypen erstellt werden. In der vorgesehenen PDF-Anwendung sind sowohl
die sichtbaren Seiten beziehungsweise Hinweisseiten als auch die
Anlagenansicht stichprobenartig gegen Reihenfolge, Seitenzahl, Eingangsdateien
und angezeigten Integritätsstatus zu prüfen. Der Fall muss JPEG, PNG, GIF und
BMP, eine PDF-Anlage mit Anmerkung, verlustfrei Windows-1252-darstellbaren
Text sowie TIFF und nicht darstellbaren Unicode-Text als Hinweisseiten
umfassen. Fb Fü 2 und Fb Fü 44 müssen zusätzlich auf dem tatsächlich
eingesetzten Drucker geprüft und die manuelle Zeichnung aller
vorgesehenen Unterschriftslinien organisatorisch festgelegt werden. Diese
Abnahme ersetzt die formale THW-Freigabe nicht. Zusätzlich ist derselbe
Einsatz einmal als Gesamtbuch und einmal für eine einzelne Dienstschicht zu
exportieren. Umfangsangabe, ETB-/TTB-Auswahl und unverändert einsatzweite
Begleitsektionen sind dabei gegeneinander zu prüfen.
