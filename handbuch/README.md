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
selben Commit angepasst werden. Besonders zu prüfen sind feste und zusätzliche
Kontofunktionen, ausgewählte formale Dienstbesetzungen, der pro Einsatz
gewählte Berechtigungsmodus und Nachrichtenlauf, Einsatz-, Dienst- und
Zugangsschichtgrenzen, Anhänge, ETB/TBB, Korrekturen, Abschluss,
Exporte, Präsenz/Logout sowie die getrennte HTTP-Basic-Administration. Bei
„Locker“ müssen feste Kontofunktion und explizite Zusatzfunktionen als
Rechtsquelle beschrieben werden; eine pauschale Freigabe fachfremder Konten
ist falsch. Bei „Streng“ sind aktive, angenommene und ausgewählte formale
Dienstbesetzungen verbindlich. Eine echte Modusänderung ist nur vor jeder
operativen oder formalen Eintragung zulässig; danach bleibt der Einsatzmodus
dauerhaft unveränderlich, während das Speichern desselben Werts idempotent
bleibt. Bei Melderaufträgen ist außerdem sauber zwischen fachlicher Eignung
und aktueller Browserpräsenz zu unterscheiden: LdF darf eine geeignete,
ungesperrte Fernmelderperson auch inaktiv oder abgemeldet auswählen, muss sie
dann aber separat informieren; Übernahme und weitere Schritte bestätigt die
Person später authentisiert selbst. Interne
Links müssen über die zentrale Anwendungs-URL-API entstehen und auch bei einem
konfigurierten Base-Path funktionieren.

Änderungen am Handbuch gehören in den statischen PHP-Nachweis, den
HTTP-Oberflächentest, den echten Browser-Akzeptanztest und die Prüfung des
Laufzeitimage-Inhalts. Die historische PDF-Datei wird dabei weder geändert
noch wieder in das App-Image aufgenommen.

Für die Melderauswahl muss der dedizierte Browsermodus
`tests/browser/headless_ui.py --inactive-messenger` einen abgemeldeten,
fachlich geeigneten Fernmelder mit sichtbarem Status in der LdF-Auswahl und
nach seiner Auswahl den Hinweis zur separaten Information prüfen. Ein
vorheriger allgemeiner Browserlauf gilt dafür nicht als Ersatz.
