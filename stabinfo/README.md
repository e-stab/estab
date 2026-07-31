# BOS-Infosammlung

Der Bereich stellt sieben historische BOS-Referenzdokumente in einer
einheitlichen, responsiven eStab-Oberfläche dar.

## Aufbau

- `index.php` ist der gemeinsame Arbeitsbereich mit Dokumentnavigation und
  Inhaltsansicht.
- `l_index.php` erzeugt die Seitenleiste.
- `f_info.php` ist die Startansicht des Inhaltsbereichs.
- `documents.php` enthält ausschließlich Titel und Kurzbeschreibungen für die
  Navigation und den gemeinsamen Dokumentkopf.
- `estab-ui.css` vereinheitlicht Typografie, Abstände, Tabellen und Bilder.

Die historischen HTML-Dateien und ihre Bilder bleiben die unveränderte
fachliche Quelle. Beim Laden setzt der Arbeitsbereich nur eine zusätzliche
Darstellungshülle um den bestehenden DOM-Inhalt. Bedeutungstragende Farben,
Tabellenwerte, Anker, Texte, Reihenfolgen und Bilder werden nicht ersetzt.

## Nachweis der Inhaltsintegrität

`tests/php/bos_info_ui_security.php` prüft SHA-256-Prüfsummen aller historischen
HTML- und Bilddateien. Dadurch schlägt die statische Testsuite fehl, sobald eine
dieser Quellen auch nur byteweise verändert wird.

Der reale Browsertest

```sh
python3 tests/browser/headless_ui.py --bos-only
```

prüft die öffentliche Navigation und die responsive Darstellung mit
Headless-Chrome auf Desktop- und Mobilbreite.
