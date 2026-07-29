# PDF-Einsatzdossier

Über **Administration → PDF-Einsatzdossier** kann ein bestimmter Einsatz als
eine zusammenhängende, durchsuchbare PDF ausgegeben werden. Der ausgewählte
Einsatz muss dafür nicht aktiv sein; damit lassen sich auch abgeschlossene
Einsätze unverändert dokumentieren.

## Inhalt auswählen

Der Administrator wählt mindestens einen der folgenden Bereiche:

- Einsatztagebuch (ETB),
- Technisches Betriebsbuch (TTB),
- alle Nachrichtenvordrucke des Einsatzes,
- Originalanhänge zu den Nachrichtenvordrucken.

Originalanhänge können nur zusammen mit den Nachrichtenvordrucken ausgewählt
werden. ETB, TTB und Nachrichten werden als Textseiten in die PDF geschrieben
und bleiben dadurch durchsuchbar. Anhänge werden nicht verlustbehaftet in
Bilder umgewandelt: Jede abgeschlossene Originaldatei wird unverändert in den
PDF-Katalog eingebettet. Das Anlagenverzeichnis nennt portablen Dateinamen,
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
- vorbereitete, einsatzgebundene ETB-/TTB-/Nachrichten-/Anhangsabfragen,
- Traversal-, Symlink-, MIME-, Größen- und Duplikatgrenzen,
- eingebetteten PDF-1.7-Dateikatalog und SHA-256,
- echte Extraktion des unveränderten Beispielanhangs,
- durchsuchbaren Text für ETB, TTB und Nachrichten,
- A4-Rendering ohne abgeschnittene oder überlappende Beschriftungen.

Der echte MariaDB-Nachweis `tests/integration/incident_export.php` legt
ETB-, TTB-, Nachrichten- und Anhangdaten in zwei verschiedenen Einsätzen an,
macht den ausgewählten Einsatz vor dem Lesen historisch und verlangt für jede
Sektion exakt dessen Datensatz. Anschließend extrahiert er den tatsächlichen
`/EmbeddedFile`-Stream aus der erzeugten PDF, vergleicht ihn bytegenau mit der
Originaldatei und prüft sowohl deren SHA-256 als auch den SHA-256-Wert der
vollständigen PDF. Der Test läuft unmittelbar nach der Einsatzaktivierung im
vollständigen Container-CI-Gate.

Für die manuelle Abnahme sollte ein Dossier mit realistischen langen
Einsatznamen, mehrseitigen Einträgen und allen in der Organisation verwendeten
Anhangstypen erstellt, in der vorgesehenen PDF-Anwendung geöffnet und die
Anlagenansicht stichprobenartig gegen die Originaldateien geprüft werden.
