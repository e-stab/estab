# Technik und Entwicklung

## Laufzeitarchitektur

| Service | Aufgabe |
| --- | --- |
| `db` | MariaDB 11.8 und persistente Fachdaten |
| `migrate` | einmalige, prüfsummengebundene Schemaaktualisierung und Prüfung |
| `admin-auth-init` | erzeugt aus dem Admin-Secret eine bcrypt-`htpasswd` |
| `app` | Apache und PHP 8.5 auf Port 8080 |

`app` startet erst nach gesunder Datenbank, erfolgreichem Migrator und
erfolgreicher Admin-Initialisierung. Nur `app` besitzt ein Frontend-Netz;
das Datenbanknetz ist intern.

## Verzeichnisstruktur

| Pfad | Inhalt |
| --- | --- |
| `app/` | gemeinsame Authentisierung, Autorisierung und Fachdienste |
| `4fach/` | Nachrichtenvordruck und operativer Arbeitsbereich |
| `4fadm/` | Administration |
| `4fueltg/` | Meldungsübersicht |
| `stabetb/`, `fmtbb/` | ETB und TTB |
| `4fbak/` | PDF-Erzeugung |
| `docker/` | Images, Schema und Laufzeitkonfiguration |
| `deploy/registry/` | Pull-only-Deployment, Backup und Restore |
| `tests/` | statische, Datenbank-, HTTP-, Browser- und PDF-Tests |

Der Docker-Build verwendet eine positive Kopierliste. Historische Dateien im
Repository gelangen dadurch nicht automatisch in das Laufzeitimage.

## Daten und Migrationen

MariaDB ist die fachliche Quelle. Dateien unter `4fdata` enthalten Anhänge
und erzeugte Vordrucke; Exporte liegen getrennt unter
`/var/lib/estab/export`.

SQL-Migrationen liegen unter `docker/db/migrations/`. Der Migrator speichert
Version und SHA-256. Bereits veröffentlichte Migrationen werden nicht
verändert; jede Schemaänderung erhält eine neue Datei.

Operative Datensätze sind einsatzgebunden. ETB, TTB und wesentliche
Betriebsereignisse werden append-only geführt. Korrekturen erzeugen neue,
referenzierte Einträge.

## Authentisierung und Rechte

Die Administration verwendet HTTP Basic mit einem separaten technischen
Secret. Operative Benutzer verwenden persönliche eStab-Sitzungen.

Jeder Endpunkt prüft seine Berechtigung unabhängig von der Navigation:

- aktives und ungesperrtes Konto,
- aktiver, offener Einsatz,
- im strengen Modus ausgewählte Besetzung einer aktiven Dienstschicht,
- im lockeren Modus feste oder zusätzliche Funktion,
- CSRF, Objektzustand und Einsatzzuordnung.

Die Sidebar blendet unzulässige Ziele aus, ist aber keine Sicherheitsgrenze.

## Dateien und PDF

Uploads werden nach Größe, Endung, MIME-Typ und Integrität geprüft. E-Mail-
HTML wird nicht aktiv gerendert. Downloads und Vorschauen wiederholen die
Objektberechtigung.

Das PDF-Einsatzdossier verwendet denselben Nachrichtenrenderer wie der
Einzelvordruck. Darstellbare Anlagen werden zusätzlich sichtbar gerendert;
Originale werden als eingebettete Dateien mitgeführt.

Die aktive PDF-Bibliothek ist derzeit als FPDF 1.6 im Quellbaum eingebettet.
Vor einer öffentlichen Weiterverteilung muss sie auf eine gepflegte Version
aktualisiert und der gesamte PDF-Testpfad erneut ausgeführt werden. Weil diese
historische Kopie nicht über Composer bezogen wird, ist sie nicht Bestandteil
des Composer-Abhängigkeitsgraphen.

## Lokale Tests

Mit lokalem PHP 8.5:

```console
tests/static/run.sh
```

Workflow-Lint:

```console
podman run --rm \
  --volume "$PWD:/repo:ro" \
  --workdir /repo \
  docker.io/rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667
```

Vollständige Containerintegration:

```console
ESTAB_CONTAINER_CLI=podman ESTAB_BROWSER_TEST=auto \
  bash tests/integration/ci.sh
```

Der Integrationslauf verwendet eigene Testprojekte und umfasst Neuinstallation,
Migration, MariaDB, HTTP, Browser, PDF sowie Backup/Restore.

## CI

Die Workflows unter `.github/workflows/` prüfen:

- PHP-Syntax und statische Anwendungstests,
- frische Compose-Installationen auf amd64 und arm64,
- Semgrep, Gitleaks, PHPStan, PHPCS, ShellCheck, Ruff und Bandit,
- CodeQL, Dependency Review, OSV und Container-Scans,
- PDF-Rendering und Releasepakete.

Die Dependency-Prüfungen arbeiten fail-closed: PHP-, Python- und
JavaScript-Quellcode, die zugehörigen Manifeste und Lockdateien sowie ein
nichtleerer Audit-Werkzeuggraph müssen vorhanden sein. Ein erwartetes
Teilergebnis mit dem Zustand `skipped` lässt den Job ebenso fehlschlagen wie
ein Scannerfehler oder ein Vulnerability-Fund. ShellCheck schlägt fehl, wenn
es entgegen dem Repositoryvertrag keine Eingabedatei findet. Vor OSV wird
zusätzlich geprüft, ob alle deklarierten Ökosysteme und die nichtleeren
Composer-/Python-Graphen vorhanden sind.

## Abhängigkeiten und Lockdateien

Die Manifeste haben getrennte Aufgaben:

| Ökosystem | Quelldeklaration | Reproduzierbarer Stand | Inhalt |
| --- | --- | --- | --- |
| PHP | `composer.json` | `composer.lock` | PHP 8.5, benötigte Extensions, PHPStan und PHPCS |
| Python | `requirements.txt`, `requirements-audit.in` | `requirements-audit.txt` | Standardbibliothek für eStab-Skripte sowie gehashte Auditwerkzeuge |
| JavaScript | `package.json` | `package-lock.json` | zwei lokale Browserskripte, Lint-Befehl, keine externen Pakete |

Leere erfundene Laufzeitabhängigkeiten werden nicht angelegt. Die
eStab-eigenen Python-Werkzeuge verwenden nur die Standardbibliothek; dies ist in
`requirements.txt` ausdrücklich festgehalten. Der nichtleere, vollständig
aufgelöste Werkzeuggraph in `requirements-audit.txt` sorgt dafür, dass
pip-audit trotzdem reale Pakete prüft. Die beiden JavaScript-Dateien haben
keine Drittanbieterimporte. Deshalb verarbeitet npm ihr Lockfile, ohne dass
eine unnötige Bibliothek in Browser oder Container gelangt.

Nach einer beabsichtigten Änderung müssen Quelldeklaration und Lockdatei
gemeinsam aktualisiert und committet werden:

```console
composer update --no-scripts

uv pip compile --python-version 3.13 --universal --generate-hashes \
  --output-file requirements-audit.txt requirements-audit.in

npm install --package-lock-only --ignore-scripts
```

Die wesentlichen lokalen Dependency-Gates sind:

```console
composer validate --strict
composer install --no-scripts
composer audit --locked --no-interaction
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpcs

python -m pip install --require-hashes -r requirements-audit.txt
python -m pip check
ruff check .
bandit --recursive . --exclude './vendor,./node_modules,./.git,./tests' \
  --severity-level medium --confidence-level medium
pip-audit --requirement requirements.txt
pip-audit --requirement requirements-audit.txt

npm ci --ignore-scripts
npm audit --audit-level=high
npm run lint

osv-scanner scan source --recursive .
```

Dependabot pflegt Composer, pip, npm und GitHub Actions. Ein Update darf nicht
nur die Quelldeklaration verändern; die zugehörige reproduzierbare Auflösung
muss die oben genannten Installations-, Quell- und Vulnerability-Prüfungen
erneut bestehen.

## Änderungen

Vor einem Commit mindestens:

1. betroffene statische Tests ausführen,
2. bei Datenbankänderungen eine neue Migration und den MariaDB-Lauf ergänzen,
3. bei UI-Änderungen den relevanten Browsermodus ausführen,
4. bei PDF-Änderungen Render- und PDF-Smoke-Tests ausführen,
5. bei Containeränderungen beide Architekturen berücksichtigen.

Tests sollen dauerhafte fachliche oder technische Verträge prüfen. Datierte
Abschlussprotokolle und manuelle Freigabeerzählungen gehören nicht in den
aktuellen Dokumentationsbestand.
