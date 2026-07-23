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
  0.9.26c-Release begleitete.
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

Das sortierte SHA-256-Manifest liegt unter
[`migration/svn-documentation-r85.sha256`](../../migration/svn-documentation-r85.sha256).
Beim Import wurden alle 95 Quelldateien verglichen; es fehlte keine Datei und
kein Inhalt wich ab. SVN-Verwaltungsdaten und lokale `.DS_Store`-Dateien sind
nicht Teil des Dokumentbestands.

Wiederholbare Prüfung:

```console
python3 migration/verify_release_snapshot.py \
  /Users/adrianboende/git/estab-svn/eStab_0.9/docu \
  docs/legacy/svn-r85
```

## Gültigkeit

Die moderne, getestete Dokumentation liegt eine Ebene höher unter `docs/` und
im Haupt-`README.md`. Aussagen des Alt-Handbuchs zu XAMPP, PHP-Konfiguration,
leeren MySQL-Root-Passwörtern, Web-basiertem Setup und Dateirechten sind nur
historischer Kontext und dürfen nicht für einen neuen Betrieb übernommen
werden.
