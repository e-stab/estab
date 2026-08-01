# Laufzeitbestand des App-Images

Das Git-Repository bewahrt den vollständigen historischen Quell- und
Dokumentationsbestand. Das ausgelieferte App-Image ist dagegen bewusst eine
kleinere, überprüfte Laufzeitsicht. Der `Dockerfile` kopiert deshalb keine
kompletten historischen Verzeichnisse, sondern nur aktive PHP-Module,
benötigte statische Assets und das aktuelle öffentliche Web-Handbuch.

## Enthaltene Laufzeitgruppen

- die aktiven Nachrichten-, Kategorie-, Anhang-, ETB-, TBB-, Übersichts- und
  Administrationscontroller;
- die zentralen PHP-Module aus `app/` und die tatsächlich eingebundenen
  Konfigurationsdateien aus `4fcfg/`;
- das konfigurierte Design `4fach/design/HS`, die drei aktiven Hinweistöne und
  das einzelne auf der Startseite verwendete Symbol aus
  `4fach/design/mr/folder_global.gif`;
- Bildsymbole aus `4fsym/`, die BOS-Informationsseiten und
  `handbuch/index.php`, `handbuch/handbuch.css` sowie
  `handbuch/handbuch.js` für die öffentliche Bedienreferenz unter
  `/handbuch/`;
- für PDF-Vordrucke ausschließlich `4fbak/backup.php`,
  `4fbak/backup_pdf.php`, der verwendete FPDF-Kern, dessen eingebaute
  Schriftmetriken und `4fbak/thw.png` als einfarbiges THW-Kopfzeichen der
  ETB-/TBB-Formblätter;
- `4fbak/fonts/georgiaz.ttf` als einzige TTF-Datei, weil die aktiven
  dynamischen Schaltflächen sie bei verfügbarer FreeType-Unterstützung
  verwenden.

## Bewusst nicht im Image

Nicht zur Laufzeit gehören insbesondere alte Web-Installer,
Konfigurationsschreiber, `phpinfo()`, Upload-/Druckbeispiele, nicht verlinkte
Controllerkopien, bearbeitbare ODT-/OTT-/NSD-/Mindmap-/Designquellen,
zusätzliche Designvarianten, FPDF-Beispiele, das eingebettete
`fpdf181.zip`-Archiv und `doku/Handbuch_eStab.pdf`. Das PDF von 2011 bleibt
ausschließlich als historische Quelle für Provenienz und Nachvollziehbarkeit
in Git erhalten; es ist keine aktuelle Bedienreferenz.

Auch `4fadm/00.htpasswd` wird niemals in das Image kopiert. Vor dem App-Start
erzeugt der netzlose One-shot-Dienst `admin-auth-init` aus dem separaten
Admin-Secret atomar eine bcrypt-Datei in einem eigenen Compose-Volume. Der
Webcontainer bindet ausschließlich diese abgeleitete Datei über
`/run/estab-auth/admin.htpasswd` schreibgeschützt ein. Das Klartext-Secret und
sein `/run/secrets`-Mount sind im laufenden Webcontainer weder vorhanden noch
lesbar; zugleich liegt kein historischer Passwort-Hash in einer OCI-Schicht.
Der Initialisierer akzeptiert genau eine Kennwortzeile mit 16 bis 72 Bytes und
erzeugt den Hash mit bcrypt-Kostenfaktor 12. Dadurch werden schwache
Kennwörter und die sonst stille bcrypt-Abschneidung nach 72 Bytes bereits vor
dem Webstart abgewiesen.

Eine Passwortrotation wird mit demselben One-shot-Pfad erzwungen:

```sh
podman compose up --detach --force-recreate admin-auth-init app
```

Der App-Entrypoint startet nur, wenn Benutzername, bcrypt-Format, Eigentümer
und Modus `0640` der abgeleiteten Datei stimmen. `compose run --no-deps` mit
überschriebenem Entrypoint (etwa für Backup und Restore) benötigt kein
Klartext-Admin-Secret; normale App-Kommandos verwenden das zuvor
initialisierte read-only Volume.

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
