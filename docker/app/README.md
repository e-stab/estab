# Laufzeitbestand des App-Images

Das Git-Repository führt den gepflegten Quell- und Dokumentationsbestand.
Frühere Stände bleiben über die Git-Historie und Tags nachvollziehbar;
wenige ausdrücklich zurückgestellte Prüfobjekte sind unten benannt. Das
ausgelieferte App-Image ist eine nochmals kleinere, überprüfte Laufzeitsicht:
Der `Dockerfile` kopiert nur aktive PHP-Module, benötigte statische Assets und
das aktuelle öffentliche Web-Handbuch.

## Enthaltene Laufzeitgruppen

- die aktiven Nachrichten-, Kategorie-, Anhang-, ETB-, TBB-, Übersichts- und
  Administrationscontroller;
- die zentralen PHP-Module aus `app/` und die tatsächlich eingebundenen
  Konfigurationsdateien aus `4fcfg/`;
- das vollständige konfigurierte Design `4fach/design/HS`, weil
  Design-/Kompatibilitätspfade Dateien daraus dynamisch auflösen, die
  browserfähigen Hinweistöne unter `4fach/audio/` und das einzelne auf der
  Startseite verwendete Symbol
  `4fach/design/mr/folder_global.gif`;
- aus `4fsym/` genau `4fach_aktiv.png`, `adm_aktiv.png`, `all_msg.png`,
  `el80.gif`, `etb_aktiv.png`, `icon_handbuch.gif`, `iuk_80.jpg`,
  `iuk_hs80.png`, `merke32.gif`, `null.gif`, `nw.png` und `tbb_aktiv.png`;
  außerdem die
  BOS-Informationsseiten und
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
Controllerkopien, bearbeitbare Dokument- und Designquellen, zusätzliche
Designvarianten, FPDF-Beispiele, eingebettete Drittanbieterarchive und
überholte Handbuchkopien. Der bereinigte Arbeitsbaum führt entfernte
historische Kopien nicht bloß für den Imagebau weiter; bei Bedarf sind sie
über die Git-Historie und Tags nachvollziehbar.

FPDF-Beispiele und alte Schriften sowie `4fsym/br.jpg` und das dortige
Icon-Archiv bleiben nach konservativer Prüfung vorerst nur im
Quellbaum. Ihre fachliche beziehungsweise lizenzrechtliche Einordnung ist
noch offen; der positive `Dockerfile`-Vertrag hält sie zuverlässig aus dem
Image heraus. `migration/` bleibt als manueller Herkunftsnachweis erhalten,
ist aber ebenfalls aus Buildkontext und Image ausgeschlossen.

Eine statische `htpasswd`-Datei gehört weder zum aktuellen Quellbestand noch
zum Image. Vor dem App-Start erzeugt der netzlose One-shot-Dienst
`admin-auth-init` aus dem separaten Admin-Secret atomar eine bcrypt-Datei in
einem eigenen Compose-Volume. Der Webcontainer bindet ausschließlich diese
abgeleitete Datei über
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

Die verbindliche positive Kopierliste steht im `Dockerfile`; die
verpflichtenden und verbotenen Zielpfade stehen im Runtime-Prüfer. Die
Retention-Entscheidungen und die gegenüber dem Container bewusst größere
Allowlist des Quellbaums sind unter
[Gepflegter Quellbestand](../../docs/QUELLBESTAND.md) dokumentiert.

Lokal:

```sh
sh tests/static/runtime_image_surface.sh
podman build -t localhost/estab:runtime-surface .
podman run --rm \
  --entrypoint /usr/local/bin/estab-verify-runtime-surface \
  localhost/estab:runtime-surface /var/www/html
```
