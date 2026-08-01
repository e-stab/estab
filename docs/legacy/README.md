# Historische Originaldokumentation

Dieser Bereich bewahrt die mit dem SourceForge-SVN ausgelieferte
Projektdokumentation unverändert. Sie ist eine wichtige fachliche Referenz,
beschreibt aber Installation und Sicherheitsannahmen aus den Jahren 2010/2011
und ist **keine Betriebsanleitung für den heutigen Container**.

## Bestände

- [`svn-r85/`](svn-r85/) enthält alle 95 Dateien aus
  `/eStab_0.9/docu` der letzten SVN-Revision: die bearbeitbaren ODT-Quellen,
  Bedien-Screenshots, Symbolausschnitte, Fotos und Programmierungsnotizen.
- [`Handbuch_eStab.pdf`](../../doku/Handbuch_eStab.pdf) ist das 31-seitige
  Anwendungshandbuch Version 1.1 vom 23. Juli 2011, das auch den offiziellen
  0.9.26c-Release begleitete. Es bleibt im Git-Archiv, wird aber nicht mehr in
  das App-Image kopiert oder als Bedienreferenz verlinkt.
- [`Tests.odt`](../../doku/Tests.odt) und
  [`Tests.ott`](../../doku/Tests.ott) sind die historische Testbeschreibung
  beziehungsweise Dokumentvorlage aus dem Programmstand.
- [`suhosin.odt`](../../doku/suhosin.odt) hält die damaligen Hinweise zur
  nicht mehr eingesetzten Suhosin-Erweiterung fest und dient nur der
  Provenienz, nicht der heutigen Härtung.
- [`Handbuch0001.odt`](svn-r85/Quellen_Handbuch/Handbuch0001.odt) und
  [`Dokumentation.odt`](svn-r85/Quellen_Handbuch/Dokumentation.odt) sind die
  ursprünglichen, umfangreichen Handbuchquellen.
- [`Systemstatus.nsd`](svn-r85/Programmierung/Systemstatus.nsd) dokumentiert
  einen historischen Ablaufentwurf im Structorizer-Format.

### Historische Notiz im Laufzeitbaum

[`4fcfg/todo.txt`](../../4fcfg/todo.txt) ist eine unverändert übernommene
Entwicklerunterhaltung aus dem SVN-Bestand über eine damals erwogene
Farbkonfiguration. Sie ist kein aktueller Projekt-Backlog und beschreibt keine
für den heutigen Container zugesagte Funktion. Zeichensatzartefakte bleiben als
Teil des belegten Originalstands erhalten; der gesamte Konfigurationsbaum
`4fcfg/` ist im Container per HTTP gesperrt.

Die Screenshots sind nach Fachbereich gegliedert:

| Verzeichnis | Inhalt |
| --- | --- |
| `Quellen_Handbuch/FM` | Fernmelder, Ein-/Ausgang, Anhänge und zweite Sichtung |
| `Quellen_Handbuch/Si` | Sichter-Ansichten und Prioritäten |
| `Quellen_Handbuch/S-Funk` | Listen, Filter, Status und Navigation der Stabsfunktionen |
| `Quellen_Handbuch/Katagorien` | globale, funktionsbezogene und persönliche Kategorien |
| `Quellen_Handbuch/ETB` | Einsatzstammdaten und Einsatztagebuch-Einträge |
| `Quellen_Handbuch/Bilder` | historische Einsatzleitwagen-Fotos für das Vorwort |

## Integritätsnachweis

Das ursprüngliche sortierte SHA-256-Manifest liegt unter
[`migration/svn-documentation-r85.sha256`](../../migration/svn-documentation-r85.sha256).
Der CI-taugliche, Unicode-sichere Nachweis ist zusätzlich als
[`migration/provenance/legacy-documentation-r85.jsonl`](../../migration/provenance/legacy-documentation-r85.jsonl)
in den versiegelten Provenienzindex eingebunden. Beim Import wurden alle 95
Quelldateien verglichen; es fehlte keine Datei und kein Inhalt wich ab.
SVN-Verwaltungsdaten und lokale `.DS_Store`-Dateien sind nicht Teil des
Dokumentbestands.

Wiederholbare Prüfung:

```console
python3 migration/verify_provenance.py --self-test
```

Diese Prüfung benötigt keine SVN-Working-Copy. Der erneute direkte
Quellvergleich mit der lokalen SVN-Kopie ist unter
[`migration/README.md`](../../migration/README.md) dokumentiert.

## Gültigkeit

Die aktuelle Bedienreferenz ist das gemeinsam mit eStab ausgelieferte
[Web-Handbuch](../../handbuch/) unter `/handbuch/`. Die modernen, getesteten
Betriebsunterlagen liegen eine Ebene höher unter `docs/` und im
Haupt-`README.md`. Aussagen des Alt-Handbuchs zu XAMPP, PHP-Konfiguration,
leeren MySQL-Root-Passwörtern, Web-basiertem Setup und Dateirechten sind nur
historischer Kontext und dürfen nicht für einen neuen Betrieb übernommen
werden.
