# PDF-Einsatzdossier

Über **Administration → PDF-Einsatzdossier** kann ein bestimmter Einsatz als
zusammenhängende, durchsuchbare DV-1-101-Einsatzdokumentation ausgegeben
werden. Der ausgewählte Einsatz muss dafür nicht aktiv sein; damit lassen sich
auch historische oder formal abgeschlossene Einsätze unverändert
dokumentieren.

Die Einsatz-Auswahl nennt Führungsstellenname, Einsatzkennung und
Einsatzname getrennt. Im Dossier stehen diese Angaben ebenfalls getrennt auf
dem Deckblatt; der Seitenkopf führt Führungsstelle und Einsatzidentität in
zwei getrennten, auf die verfügbare A4-Breite gekürzten Zeilen.
Ein vor Migration 97 angelegter Einsatz ohne bestätigten
Führungsstellennamen wird ausdrücklich mit **Führungsstelle historisch nicht
erfasst** beziehungsweise **historisch nicht erfasst** gekennzeichnet.
Einsatzname, Organisation, Einsatzleitung und Umgebung werden nicht als
scheinbarer Ersatz verwendet.

## Inhalt auswählen

Der Administrator wählt mindestens einen der folgenden neun Bereiche. Beim
Aufruf sind alle neun Bereiche ausgewählt:

- Einsatztagebuch (ETB),
- Technisches Betriebsbuch (TBB),
- alle Nachrichtenvordrucke des Einsatzes,
- Anhänge zu den Nachrichtenvordrucken samt Integritätsstatus,
- Nachrichtenereignisse und Nachrichtennachweisköpfe,
- Dienstschichten, Dienstbesetzungen, sämtliche Übergabeanforderungen
  (`INITIIERT`, `STORNIERT`, `BESTAETIGT`) und abgeschlossene
  Dienstübergaben,
- S6-Fernmeldeplanversionen mit sämtlichen Planeinträgen,
- Melderaufträge,
- Betriebsereignisse und Betriebsnachweiskopf.

Anhänge können nur zusammen mit den Nachrichtenvordrucken ausgewählt
werden. Der Server verwirft unbekannte, mehrfachdeutige oder nicht kanonische
Auswahlwerte; eine leere Auswahl wird nicht als scheinbar vollständiges
Dossier akzeptiert.

Das ETB weist neben Ereignis und Bemerkung auch Ereigniszeit,
Erfassungszeit, Ereignistyp, Nachrichten- und Anhangsbezug, freien Bezug sowie
eine etwaige Korrekturbeziehung aus. Dienstorganisation, S6-Planung und
Melderbeförderung enthalten jeweils alle persistierten Status-, Zeit-,
Gültigkeits-, Freigabe-, Empfänger-, Rückweg- und Abschlussfelder. Keine
ausgewählte leere Sektion verschwindet still: Das Dossier sagt ausdrücklich,
dass für den Einsatz keine entsprechenden Datensätze vorhanden sind.

ETB und TBB erscheinen als durchsuchbare Dossierseiten. Jede Nachricht wird
dagegen mit exakt demselben A4-Formularrenderer ausgegeben wie unter
**Generierte Vordrucke**. Dadurch stimmen Raster, Feldpositionen,
Empfängerkennzeichnung und mehrseitiger Inhaltsfluss in Einzel- und
Gesamtexport überein. Die gemeinsame Vorlage druckt weder eine
VS-NfD-Kennzeichnung noch das frühere Wappen.

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

Anhänge werden nicht verlustbehaftet in Bilder umgewandelt: Jede abgeschlossene
Datei wird mit ihren aktuell gelesenen Bytes in den PDF-Katalog eingebettet.
Ihr sicherer
Dateiname steht zusätzlich im Nachrichtenvordruck. Bei einem historischen
Einsatz wird dort bewusst kein Link auf den Downloadbereich des aktuell
aktiven Einsatzes erzeugt. Das Anlagenverzeichnis nennt portablen Dateinamen,
Medientyp, Größe, zugehörige Nachrichtendatensätze und den SHA-256 der
eingebetteten Datei.
Gängige PDF-Leser zeigen diese Dateien in ihrer Anlagenansicht an.

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
Abschlussvermerk, Mindestaufbewahrung bis, Legal-Hold-Status, Hold-Grund,
Hold-Zeit und verantwortliche Identität. Ein historisch gesetztes `ende`
ersetzt den formalen Abschluss nicht.

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
`ESTAB_PDF_ATTACHMENT_MAX_BYTES` zwischen 0 und 104857600 Byte eingestellt
werden. Die Grenze schützt den 256-MiB-PHP-Prozess, weil die alte FPDF-Laufzeit
das Dokument im Speicher aufbaut. Bei Überschreitung bricht die Erzeugung
sichtbar ab; Dateien werden niemals stillschweigend weggelassen.

Der Download ist ausschließlich über den separat mit HTTP Basic Auth
geschützten Administrationsbereich und einen POST mit Session-CSRF möglich.
Die Antwort wird mit `no-store`, `nosniff` und einer Sandbox-CSP ausgeliefert.
Nach erfolgreicher Erzeugung schreibt eStab einen `pdf_export`-Eintrag in das
Einsatzprotokoll. Er enthält Auswahl, Datensatzanzahlen, PDF-Größe,
Anhangsgröße und SHA-256 der vollständigen PDF, aber weder Kennwörter noch
interne Dateipfade.

## Funktionsnachweis

Die automatisierten Tests prüfen unter anderem:

- strikte Einsatz- und Abschnittsauswahl,
- getrennte Darstellung von Führungsstellenname, Einsatzkennung und
  Einsatzname sowie die ehrliche Kennzeichnung eines historischen
  `NULL`-Werts,
- vorbereitete, einsatzgebundene Abfragen aller neun Bereiche,
- neue ETB-Zeit-, Typ-, Referenz- und Korrekturfelder,
- vollständige Dienst-, S6- und Melderketten,
- Neuberechnung von Nachrichten- und Betriebsereignishashes samt Kopfvergleich,
- Status-8-Terminalbindung einschließlich sichtbarer Legacy-Ausnahme,
- Vorläufig-/Formal-Banner, Retention und Legal Hold,
- Traversal-, Symlink-, MIME-, Größen- und Duplikatgrenzen,
- eingebetteten PDF-1.7-Dateikatalog und SHA-256,
- echte Extraktion des unveränderten, am Eingang gebundenen Beispielanhangs,
- Ablehnung einer nach dem Laden oder vor dem Export gleich groß manipulierten
  Datei sowie ehrliche Legacy-Kennzeichnung ohne erfundenen Eingangshash,
- durchsuchbaren Text für ETB, TBB und Nachrichten,
- Rufnummer und Betreff in der amtlichen Reihenfolge vor dem Nachrichtentext,
- dieselben Formularmarker in Einzel- und Gesamtexport,
- aktuelle In-Memory-Ausgabe trotz unverändert erhaltener Archivdatei,
- Abwesenheit von VS-NfD-Aufdruck, Wappen und Seitenbildern,
- verlustfreie Anzeige nicht mehr in der Matrix vorhandener Empfänger,
- pixelidentisches A4-Rendering beider Nachrichtenausgabepfade einschließlich
  mehrseitigem Inhaltsfluss und produktivem Wechsel zwischen allen
  Dossierabschnitten und der Formularseite.

Der echte MariaDB-Nachweis `tests/integration/incident_export.php` legt
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
beide mehrseitigen Varianten sowie ein repräsentatives vollständiges Dossier
in der produktiven Folge Deckblatt, ETB, TBB, Nachricht, Nachrichtennachweis,
Dienstorganisation, S6-Planung, Melderauftrag, Betriebsnachweis und
Anlagenverzeichnis.
`tests/static/pdf_render.sh` prüft sie mit Poppler: A4 und Seitenzahl über
`pdfinfo`, Text, historischen Empfänger-Fallback und verbotene Aufdrucke über
`pdftotext`, den konstanten linken Folgeseiteneinzug über dessen
Bounding-Box-Ausgabe sowie einen eigenen Maximalwert-Fall mit 128 Zeichen
Führungsstelle, 64 Zeichen Kennung und 255 Zeichen Einsatzname. Fehlende
Rasterbilder werden über `pdfimages`, pixelgleiche PNGs über `pdftoppm` und
der am Eingang gebundene Anhang über `pdfdetach` und `cmp` geprüft. Die zehn
Seiten der repräsentativen Fixture werden vollständig zu PNG gerendert. Auf
der produktiven Nachrichtenseite wird ausschließlich das absichtlich globale
Seitenzahlfeld vom Pixelvergleich ausgenommen.
GitHub Actions lädt PDFs, Textauszüge, Prüfinformationen und Render-PNGs
14 Tage als `pdf-render-evidence-*` hoch. Eine sichtbare Verschiebung der
Vorlage oder ein erneut eingebundenes Wappen sperrt damit die CI.

Für die manuelle Abnahme sollte ein Dossier mit realistischen langen
Einsatznamen, mehrseitigen Einträgen und allen in der Organisation verwendeten
Anhangstypen erstellt, in der vorgesehenen PDF-Anwendung geöffnet und die
Anlagenansicht stichprobenartig gegen die Eingangsdateien sowie den angezeigten
Integritätsstatus geprüft werden.
