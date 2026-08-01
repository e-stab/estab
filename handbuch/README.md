# Web-Handbuch pflegen

Das öffentliche Handbuch wird unter `/handbuch/` gemeinsam mit der Anwendung
ausgeliefert. Es beschreibt den aktuellen Bedienstand und ist damit die
maßgebliche Nutzerreferenz. Das frühere PDF unter
`doku/Handbuch_eStab.pdf` bleibt ausschließlich als historische Quelle im
Git-Bestand; seine XAMPP-, MySQL- und Web-Installer-Anweisungen dürfen nicht in
den heutigen Betrieb übernommen werden.

Die Laufzeitoberfläche besteht aus:

- `index.php` für Struktur, Inhalte und sichere interne Links,
- `handbuch.css` für das responsive Bildschirm- und Drucklayout,
- `handbuch.js` für die lokale Suche ohne externe Abhängigkeiten.

Bei fachlichen oder sichtbaren Änderungen müssen die betroffenen Kapitel im
selben Commit angepasst werden. Besonders zu prüfen sind Rollen und
Nachrichtenlauf, Einsatz- und Dienstschichtgrenzen, Anhänge, ETB/TBB,
Korrekturen, Abschluss, Exporte, Präsenz/Logout sowie die getrennte
HTTP-Basic-Administration. Interne Links müssen über die zentrale
Anwendungs-URL-API entstehen und auch bei einem konfigurierten Base-Path
funktionieren.

Änderungen am Handbuch gehören in den statischen PHP-Nachweis, den
HTTP-Oberflächentest, den echten Browser-Akzeptanztest und die Prüfung des
Laufzeitimage-Inhalts. Die historische PDF-Datei wird dabei weder geändert
noch wieder in das App-Image aufgenommen.
