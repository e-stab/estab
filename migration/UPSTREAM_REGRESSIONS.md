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

Diese Entscheidung ist durch einen echten HTTP-Integrationstest abgesichert:
Ein authentifizierter A/W-Benutzer füllt den Nachrichtenvordruck mit markanten
Werten, Vermerk sowie blauer und grüner Empfängerzuordnung, lädt einen Anhang
hoch und wählt ihn aus. Der Test prüft anschließend die zurückgelieferten
Formularfelder und ausgewählten Matrix-Controls selbst; er speist die Werte
nicht erst beim späteren Speichern erneut ein.

## 0.9.26b/c: fehlende BOS-Infosammlung

Beide späteren Release-Archive enthalten den im Hauptmenü weiterhin aktiven
Link `./stabinfo/index.php`, lassen aber den vollständigen Verzeichnisbaum
`stabinfo/` aus. Ein unverändertes Release liefert für den sichtbaren
Menüpunkt deshalb HTTP 404. Der letzte SVN-Trunk enthält alle 14 zugehörigen
HTML-, PHP- und Bilddateien; dieser belegte Bestand wird im heutigen
Arbeitsstand wieder aufgenommen.

In `stabinfo/l_index.php` werden zwei bereits historisch tote Ziele
korrigiert: Der DRK-Menüpunkt verweist auf die tatsächlich mitgelieferte Datei
`DRK Rufnamenschema.html`, und der Link auf das nie enthaltene Verzeichnis
`HB_abt_2009` entfällt. Der HTTP-Smoke-Test öffnet Startseite, Frameset und
Inhaltsseite der Infosammlung, damit der veröffentlichte Menüpunkt nicht erneut
unbemerkt verloren geht.

Der SVN-Bestand lud außerdem zwölf „kleiner oder gleich“-Symbole und zwei
bedeutungslose Zierbilder per unverschlüsseltem HTTP von fremden Servern.
Heute stehen die Vergleichszeichen semantisch als lokales `&le;` im Dokument;
die Zierbilder und ein entbehrlicher externer Begriff-Link sind entfernt.
Es wurden keine fremden Bilddateien heruntergeladen oder übernommen.
