# Bekannte Unterschiede der offiziellen Releases

## 0.9.26c: zurückgerollter Anhang-Dialog

Das am 2. Juli 2018 veröffentlichte Archiv `ver0.9.26c.zip` enthält für
`4fach/anhang.php` eine ältere Datei als `ver0.9.26b.zip`:

| Release | Dateigröße | ZIP-Zeitstempel | Verhalten |
| --- | ---: | --- | --- |
| 0.9.26b | 45.101 Byte | 11. Mai 2016 | Formularzustand aus `$_POST`; fehlende Werte werden initialisiert |
| 0.9.26c | 44.616 Byte | 20. Mai 2015 | Formularzustand wieder aus `$_GET`; mehrere Initialisierungen fehlen |

Der dokumentierte 0.9.26b-Fix „`store_formdata` von `$_GET` auf `$_POST`
umgestellt“ ging damit im späteren Paket verloren. Betroffen sind alle beim
Anhang-Upload zwischengespeicherten Nachrichtenfelder, die Empfängermatrix,
Grünkopie und Vermerke. Der getaggte Stand `ver0.9.26c` bleibt als Beleg exakt
erhalten. Der nachfolgende Arbeitsstand übernimmt ausschließlich
`4fach/anhang.php` aus dem ebenfalls verifizierten Tag `ver0.9.26b`; der
0.9.26c-Versionsmarker in `4fcfg/config.inc.php` bleibt bestehen.

Diese Entscheidung wird später durch einen Integrationstest abgesichert: Ein
ausgefüllter Nachrichtenvordruck muss einen Anhang-Upload durchlaufen, ohne
auch nur ein Formularfeld oder eine Empfängerzuordnung zu verlieren.
