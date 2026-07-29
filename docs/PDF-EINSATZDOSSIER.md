# PDF-Einsatzdossier

Über **Administration → PDF-Einsatzdossier** kann ein bestimmter Einsatz als
eine zusammenhängende, durchsuchbare PDF ausgegeben werden. Der ausgewählte
Einsatz muss dafür nicht aktiv sein; damit lassen sich auch abgeschlossene
Einsätze unverändert dokumentieren.

## Inhalt auswählen

Der Administrator wählt mindestens einen der folgenden Bereiche:

- Einsatztagebuch (ETB),
- Technisches Betriebsbuch (TBB),
- alle Nachrichtenvordrucke des Einsatzes,
- Originalanhänge zu den Nachrichtenvordrucken.

Originalanhänge können nur zusammen mit den Nachrichtenvordrucken ausgewählt
werden. ETB und TBB erscheinen als durchsuchbare Dossierseiten. Jede Nachricht
wird dagegen mit exakt demselben A4-Formularrenderer ausgegeben wie unter
**Generierte Vordrucke**. Dadurch stimmen Raster, Feldpositionen,
Empfängerkennzeichnung und mehrseitiger Inhaltsfluss in Einzel- und
Gesamtexport überein. Die gemeinsame Vorlage druckt weder eine
VS-NfD-Kennzeichnung noch das frühere Wappen.

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
Originaldatei wird unverändert in den PDF-Katalog eingebettet. Ihr sicherer
Dateiname steht zusätzlich im Nachrichtenvordruck. Bei einem historischen
Einsatz wird dort bewusst kein Link auf den Downloadbereich des aktuell
aktiven Einsatzes erzeugt. Das Anlagenverzeichnis nennt portablen Dateinamen,
Medientyp, Größe, zugehörige Nachrichtendatensätze und SHA-256-Prüfsumme.
Gängige PDF-Leser zeigen diese Dateien in ihrer Anlagenansicht an.

## Vollständigkeits- und Sicherheitsgrenzen

Alle Datenbankabfragen sind mit der ausgewählten `einsatz_id` gebunden und
werden in einem konsistenten MariaDB-Read-only-Snapshot gelesen. Ein
historischer Datensatz eines anderen Einsatzes oder eine während der
Erzeugung erst hinzugekommene Teilmenge kann daher nicht versehentlich in das
Dossier geraten. Für Anhänge gelten zusätzlich die zentrale
Dateinamen-Allowlist, die Ablagegrenze und ein stabiler Datei-Lesevorgang. Fehlt
eine als abgeschlossen geführte Datei, ist ein Nachrichtenvordruck mit einem
nicht abgeschlossenen Anhang verknüpft oder ändert sich eine Datei beim Lesen,
wird kein scheinbar vollständiger Export ausgeliefert.

Die Gesamtgröße eingebetteter Originaldateien ist standardmäßig auf
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
- vorbereitete, einsatzgebundene ETB-/TBB-/Nachrichten-/Anhangsabfragen,
- Traversal-, Symlink-, MIME-, Größen- und Duplikatgrenzen,
- eingebetteten PDF-1.7-Dateikatalog und SHA-256,
- echte Extraktion des unveränderten Beispielanhangs,
- durchsuchbaren Text für ETB, TBB und Nachrichten,
- dieselben Formularmarker in Einzel- und Gesamtexport,
- aktuelle In-Memory-Ausgabe trotz unverändert erhaltener Archivdatei,
- Abwesenheit von VS-NfD-Aufdruck, Wappen und Seitenbildern,
- verlustfreie Anzeige nicht mehr in der Matrix vorhandener Empfänger,
- pixelidentisches A4-Rendering beider Nachrichtenausgabepfade einschließlich
  mehrseitigem Inhaltsfluss und produktivem Wechsel von Deckblatt, ETB und TBB
  zur Formularseite.

Der echte MariaDB-Nachweis `tests/integration/incident_export.php` legt
ETB-, TBB-, Nachrichten- und Anhangdaten in zwei verschiedenen Einsätzen an,
macht den ausgewählten Einsatz vor dem Lesen historisch und verlangt für jede
Sektion exakt dessen Datensatz. Anschließend extrahiert er den tatsächlichen
`/EmbeddedFile`-Stream aus der erzeugten PDF, vergleicht ihn bytegenau mit der
Originaldatei und prüft sowohl deren SHA-256 als auch den SHA-256-Wert der
vollständigen PDF. Der Test läuft unmittelbar nach der Einsatzaktivierung im
vollständigen Container-CI-Gate.

Zusätzlich erzeugt `tests/php/pdf_template_render_fixture.php` aus identischen
Nachrichten- und Matrixdaten Einzelvordruck, direkte Dossier-Nachrichtenseite,
beide mehrseitigen Varianten sowie ein vollständiges Dossier in der
produktiven Folge Deckblatt, ETB, TBB, Nachricht und Anlagenverzeichnis.
`tests/static/pdf_render.sh` prüft sie mit Poppler: A4 und Seitenzahl über
`pdfinfo`, Text, historischen Empfänger-Fallback und verbotene Aufdrucke über
`pdftotext`, den konstanten linken Folgeseiteneinzug über dessen
Bounding-Box-Ausgabe, fehlende Rasterbilder über `pdfimages`, pixelgleiche
PNGs über `pdftoppm` und den unveränderten Originalanhang über `pdfdetach` und
`cmp`. Auf der produktiven Dossierseite wird ausschließlich das absichtlich
globale Seitenzahlfeld vom Pixelvergleich ausgenommen.
GitHub Actions lädt PDFs, Textauszüge, Prüfinformationen und Render-PNGs
14 Tage als `pdf-render-evidence-*` hoch. Eine sichtbare Verschiebung der
Vorlage oder ein erneut eingebundenes Wappen sperrt damit die CI.

Für die manuelle Abnahme sollte ein Dossier mit realistischen langen
Einsatznamen, mehrseitigen Einträgen und allen in der Organisation verwendeten
Anhangstypen erstellt, in der vorgesehenen PDF-Anwendung geöffnet und die
Anlagenansicht stichprobenartig gegen die Originaldateien geprüft werden.
