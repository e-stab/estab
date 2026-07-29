# Laufzeitbestand des App-Images

Das Git-Repository bewahrt den vollständigen historischen Quell- und
Dokumentationsbestand. Das ausgelieferte App-Image ist dagegen bewusst eine
kleinere, überprüfte Laufzeitsicht. Der `Dockerfile` kopiert deshalb keine
kompletten historischen Verzeichnisse, sondern nur aktive PHP-Module,
benötigte statische Assets und die öffentlich verlinkte Handbuch-PDF.

## Enthaltene Laufzeitgruppen

- die aktiven Nachrichten-, Kategorie-, Anhang-, ETB-, TBB-, Übersichts- und
  Administrationscontroller;
- die zentralen PHP-Module aus `app/` und die tatsächlich eingebundenen
  Konfigurationsdateien aus `4fcfg/`;
- das konfigurierte Design `4fach/design/HS`, die drei aktiven Hinweistöne und
  das einzelne auf der Startseite verwendete Symbol aus
  `4fach/design/mr/folder_global.gif`;
- Bildsymbole aus `4fsym/`, die BOS-Informationsseiten und
  `doku/Handbuch_eStab.pdf`;
- für PDF-Vordrucke ausschließlich `4fbak/backup.php`,
  `4fbak/backup_pdf.php`, der verwendete FPDF-Kern, dessen eingebaute
  Schriftmetriken; das nicht mehr gedruckte historische Wappen bleibt nur zur
  Provenienz in Git und wird nicht in das Laufzeitimage kopiert;
- `4fbak/fonts/georgiaz.ttf` als einzige TTF-Datei, weil die aktiven
  dynamischen Schaltflächen sie bei verfügbarer FreeType-Unterstützung
  verwenden.

## Bewusst nicht im Image

Nicht zur Laufzeit gehören insbesondere alte Web-Installer,
Konfigurationsschreiber, `phpinfo()`, Upload-/Druckbeispiele, nicht verlinkte
Controllerkopien, bearbeitbare ODT-/OTT-/NSD-/Mindmap-/Designquellen,
zusätzliche Designvarianten, FPDF-Beispiele und das eingebettete
`fpdf181.zip`-Archiv. Diese Dateien bleiben für Provenienz und historische
Nachvollziehbarkeit in Git erhalten.

Auch `4fadm/00.htpasswd` wird niemals in das Image kopiert. Der Entrypoint
erzeugt bei jedem Containerstart unter `/run/estab/admin.htpasswd` eine neue
bcrypt-Datei aus dem separaten Admin-Secret. Damit liegt kein historischer
Passwort-Hash in einer OCI-Schicht.

## Automatischer Nachweis

`docker/app/verify-runtime-surface.sh` enthält die verpflichtenden und
verbotenen Pfade sowie die verbotenen Quell-/Archivendungen. Der Image-Build
führt diesen Prüfer nach dem Kopieren aus und bricht bei einer Abweichung ab.
`tests/static/runtime_image_surface.sh` beweist zusätzlich, dass ein altes
`htpasswd`, eine verbotene Dokumentquelle und eine nicht freigegebene
TTF-Datei tatsächlich erkannt werden. Persistente Benutzerdaten unter
`4fdata/` sind von der Endungsprüfung ausgenommen.

Lokal:

```sh
sh tests/static/runtime_image_surface.sh
podman build -t localhost/estab:runtime-surface .
podman run --rm \
  --entrypoint /usr/local/bin/estab-verify-runtime-surface \
  localhost/estab:runtime-surface /var/www/html
```
