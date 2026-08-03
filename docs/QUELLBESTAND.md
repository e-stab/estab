# Gepflegter Quellbestand

Der aktuelle Arbeitsbaum enthält nur Dateien, die mindestens eine der
folgenden Aufgaben erfüllen:

- produktiver Anwendungscode oder ein zur Laufzeit benötigtes Asset,
- Containerbau, Datenbankschema, Betrieb, Backup oder Deployment,
- automatisierter Funktions-, Sicherheits- oder Darstellungsnachweis,
- aktuelle Bedien- und Betriebsdokumentation,
- bewusst aufbewahrter manueller Herkunftsnachweis,
- ausdrücklich dokumentierte Rückstellung nach konservativer Prüfung.

Eindeutig entbehrliche frühere Stände werden nicht zusätzlich als Kopien,
Archive, alte Designvarianten oder überholte Handbücher im aktuellen
Arbeitsbaum mitgeführt. Wenige noch nicht sicher eingeordnete Ausnahmen sind
unten ausdrücklich benannt. Entfernte Stände bleiben über die Git-Historie,
die historischen Branches und die vorhandenen Tags nachvollziehbar. Die
Bereinigung schreibt diese Historie nicht um.

## Allowlist des aktuellen Arbeitsbaums

Die folgenden Gruppen sind bewusst Bestandteil des gepflegten Bestands:

| Gruppe | Inhalt und Aufbewahrungsgrund |
| --- | --- |
| Anwendung | Einstiegspunkte im Repositorywurzelverzeichnis, die aktiven Controller unter `4fach/`, `4fadm/`, `4fueltg/`, `fmtbb/`, `stabetb/` und die zentralen Module unter `app/` |
| Laufzeitkonfiguration | die tatsächlich eingebundenen Dateien unter `4fcfg/`, Apache-/PHP-Konfiguration, Entrypoint und Containerdefinitionen unter `docker/` |
| Aktive Assets | das wegen dynamischer Kompatibilität vollständig ausgelieferte Design `4fach/design/HS/`, aus `4fach/design/mr/` ausschließlich `folder_global.gif`, die browserfähigen Hinweistöne unter `4fach/audio/`, die unten aufgeführten Menübilder, die aktive PDF-Teilmenge unter `4fbak/` und die benötigten BOS-Dokumente unter `stabinfo/` |
| Bedienung und Betrieb | das aktuelle Web-Handbuch unter `handbuch/`, die Runbooks unter `docs/`, Registry-/NAS-Helfer unter `deploy/` sowie die zentrale `README.md` |
| Nachweise | automatisierte Prüfungen unter `tests/`, dafür benötigte Fixtures, Werkzeuge unter `tools/`, Analysekonfigurationen und GitHub-Workflows |
| Herkunft | `migration/` als bewusst erhaltener, manueller SVN-/Release-Nachweis; dieser Bestand gehört weder zum Laufzeitimage noch zum regulären CI-Freigabegate |
| Rückgestellt | unten einzeln benannte FPDF-/`4fsym`-Artefakte mit noch offener fachlicher oder lizenzrechtlicher Einordnung; ausschließlich Quellbaum, keine Runtime-Allowlist |

Dateien außerhalb dieser Gruppen benötigen einen aktuellen Referenz- oder
Nachweisgrund. Ein bloßer historischer Ursprung genügt nicht für die
Aufbewahrung im Arbeitsbaum.

## Allowlist des App-Containers

Der Container übernimmt nicht pauschal den Arbeitsbaum. Der `Dockerfile`
kopiert Anwendungscode und Assets über eine positive Liste. Ergänzend prüft
`docker/app/verify-runtime-surface.sh`:

- alle verpflichtenden Laufzeitpfade,
- ausdrücklich verbotene Alt- und Quellpfade,
- verbotene Archiv-, Dokumentquell- und Metadatenendungen,
- die enge Allowlist der mitgelieferten Schriften.

`tests/static/runtime_image_surface.sh` prüft den Vertrag einschließlich
negativer Fälle. HTTP-, Integrations- und Browsertests belegen anschließend,
dass die erlaubte Oberfläche vollständig erreichbar und die gesperrten
Altpfade nicht ausführbar sind. Eine Datei gilt daher nicht allein deshalb
als benötigt, weil sie irgendwann im Upstream-Bestand lag.

Für `4fsym/` ist die Containerliste bewusst auf diese zwölf Dateien begrenzt:
`4fach_aktiv.png`, `adm_aktiv.png`, `all_msg.png`, `el80.gif`,
`etb_aktiv.png`, `icon_handbuch.gif`, `iuk_80.jpg`, `iuk_hs80.png`,
`merke32.gif`, `null.gif`, `nw.png` und `tbb_aktiv.png`. Die aktive
PDF-Teilmenge besteht
aus `backup.php`, `backup_pdf.php`, `fpdf.php`, `thw.png`, den verwendeten
FPDF-Schriftmetriken und `fonts/georgiaz.ttf`. Der `Dockerfile` und
`docker/app/verify-runtime-surface.sh` bleiben die kanonischen maschinellen
Listen; diese Dokumentation erklärt die Entscheidung, ersetzt den Vertrag
aber nicht.

## Retention-Entscheidungen

Bewusst erhalten bleiben:

- die für die aktive Oberfläche benötigten Legacy-Controller und
  Kompatibilitätsmodule,
- kleine HTTP-410-Tombstones, wenn ein früherer unsicherer Endpunkt eine
  eindeutige, geprüfte Antwort statt eines stillen Verhaltens benötigt,
- die bytegenau geschützten BOS-Fachinhalte,
- veröffentlichte Datenbankmigrationen, deren Prüfsummen Teil des
  Upgradevertrags sind,
- `migration/` als manueller Herkunftsnachweis.

Nicht im aktuellen Arbeitsbaum aufbewahrt werden:

- alte Installer, Konfigurationsschreiber, Diagnose- und Beispielendpunkte,
- unbenutzte Controllerkopien und unerreichbarer Code hinter Tombstones,
- frühere Designvarianten, Quellgrafiken, eindeutig doppelte Bilder und
  eindeutig nicht benötigte Schriften,
- eindeutig entbehrliche Drittanbieterarchive, Beispiele und alte
  Entwicklungsunterlagen,
- überholte Bedienhandbücher,
- lokale Renderausgaben, Testprotokolle, Caches, Secrets und andere
  generierte Zustände.

Die letzte Gruppe wird durch `.gitignore` und `.dockerignore` vom Commit- und
Buildkontext getrennt. Persistente Fachdaten in den Compose-Volumes sind keine
Altlasten und dürfen nie durch eine Quellbestandsbereinigung entfernt werden.

Bewusst zurückgestellt ist die Entfernung der FPDF-Beispiele und alten
Schriftensammlung sowie von `4fsym/br.jpg` und dem dortigen Icon-Archiv. Die
Garrison-Schriftnamen erscheinen in `backup_pdf.php` nur in
auskommentierten Kompatibilitätszeilen; das ist kein belegter Laufzeitbedarf.
Die fachliche beziehungsweise lizenzrechtliche Einordnung des Gesamtbestands
ist jedoch noch nicht hinreichend sicher. Diese Dateien bleiben deshalb
vorerst nur im Quellbaum und sind durch die positive Containerliste vom Image
ausgeschlossen. Eine spätere Bereinigung benötigt einen separaten,
dokumentierten Nachweis; diese Rückstellung ist keine Aufnahme in die
Runtime-Allowlist.

## Herkunftsnachweis ist kein CI-Gate

Die Dateien unter `migration/` dokumentieren weiterhin den damaligen
SVN-/Git-Import und ermöglichen eine bewusst gestartete manuelle
Provenienzprüfung. Der reguläre CI- und Release-Nachweis stützt sich dagegen
auf die heutigen statischen, Container-, Datenbank-, HTTP-, Browser-, Backup-
und Restore-Prüfungen. Ein Fehler oder eine fehlende lokale Voraussetzung der
manuellen Herkunftsprüfung entscheidet daher nicht über die Freigabe des
aktuellen Produkts.

Diese Trennung verhindert, dass historische Beweisdateien in die
Produktionsoberfläche oder das Containerimage gelangen, ohne den
nachvollziehbaren Ursprung des Projekts zu verwerfen.

## Bereinigungsnachweis vom 3. August 2026

Gegenüber dem vorherigen `main`-Stand wurden 331 versionierte Dateien
entfernt und zwei neue Dateien für Quellbaumprüfung und Dokumentation
aufgenommen. Damit sank der aktuelle Snapshot netto von 915 auf 586 Dateien;
sein versionierter Inhalt wurde um rund 3,88 MB kleiner. Die Git-Historie und
ihre Tags wurden nicht umgeschrieben,
sodass jeder entfernte Stand weiterhin wiederherstellbar bleibt.

Für den bereinigten Endstand waren anschließend folgende Nachweise grün:

- `tests/static/source_tree_hygiene.sh`,
- `tests/static/runtime_image_surface.sh`,
- die unprivilegierte PHP-8.5-Suite mit 229 gelinteten aktiven PHP-Dateien,
- den vollständigen Container-, MariaDB-, HTTP- und Chrome-Lauf mit
  `CI integration: OK`,
- den darin enthaltenen destruktiven Backup-/Restore-Roundtrip.

Der vollständige Lauf deckt insbesondere Neuinstallation, wiederholbare
Migrationen, Einsatz- und Berechtigungslogik, Nachrichtenworkflow, ETB/TBB,
Anhänge und E-Mail-Ansicht, PDF-Gesamtexport, 10.000 Meldungen, administrative
Abläufe sowie Desktop- und Mobildarstellung ab.

## Prüfung bei künftigen Bereinigungen

Vor dem Entfernen einer weiteren Datei sind mindestens diese Punkte zu
prüfen:

1. Referenzen in PHP, CSS, JavaScript, Containerdefinitionen, Dokumentation
   und Tests.
2. Dynamisch zusammengesetzte Pfade, insbesondere Design-, Sprach-, PDF- und
   Downloadpfade.
3. Aufnahme in die positive Containerliste oder in den
   Runtime-Surface-Vertrag.
4. Verwendung als Fixture oder als negativer Sicherheitsnachweis.
5. Wiederherstellbarkeit über Git-Historie oder Tag, falls ausschließlich
   historische Bedeutung besteht.

Die Bereinigung ist erst abgeschlossen, wenn statische Suite,
Runtime-Surface-Test, Containerintegration und die betroffenen Browser- oder
PDF-Nachweise erneut erfolgreich sind.
