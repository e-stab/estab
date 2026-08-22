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

### Content-Security-Policy

Jede von PHP erzeugte Seite sendet ihre eigene Richtlinie mit einer Nonce je
Anfrage (`app/csp.php`, gesendet aus `app/bootstrap.php`). Ein Webserver kann
keine unratbare Nonce erzeugen, deshalb entsteht sie in der Anwendung.

Jedes eingebettete Skript muss `estab_csp_script_attribute()` tragen:

```php
echo '<script' . estab_csp_script_attribute() . ' data-estab-beispiel>';
```

Ereignisattribute im Markup (`onclick=` und Verwandte) laufen unter dieser
Richtlinie nicht mehr; eine Nonce deckt sie nicht ab. Stattdessen trägt das
Bedienelement ein Datenmerkmal, und ein Skript bindet den Zuhörer.

`docker/apache/estab.conf` setzt die Richtlinie nur für Antworten, die noch
keine haben: statische Auslieferungen und Apache-eigene Fehlerseiten. Dort gilt
`script-src 'none'`. Die Bedingung `expr=-z %{resp:Content-Security-Policy}`
ist notwendig -- ohne sie hängt der Webserver eine zweite Richtlinie an die
PHP-Antwort, und mehrere Richtlinien gelten gemeinsam.

`style-src` behält `'unsafe-inline'`: die Listen färben eine Zeile nach der
Durchschrift, die die lesende Funktion erreicht. Das ist Datum, nicht Markup,
und Stilattribute lassen sich mit einer Nonce ohnehin nicht freigeben.

`tests/php/csp_nonce_security.php` hält all das fest.

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

Die statische Suite registriert ihre Prüfungen programmatisch und führt sie
nebenläufig aus. Sie bricht nicht beim ersten Fehler ab, sondern meldet am Ende
jede fehlgeschlagene Prüfung mit ihrer Ausgabe. Die Zahl der gleichzeitigen
Läufe folgt der Kernzahl und lässt sich mit `ESTAB_TEST_JOBS` setzen. Welche
Prüfungen registriert sind, beantwortet:

```console
tests/static/run.sh --list
```

Den Lint des gesamten Baums erledigt `tools/lint_sources.php` in einem einzigen
Prozess. Über `opcache_compile_file()` meldet es dieselben zwei Fehlerklassen
wie `php -l` -- Kompilierfehler und Kompilierzeit-Deprecations -- und fällt auf
hosts ohne nutzbare OPcache auf `php -l` je Datei zurück:

```console
php -d opcache.enable_cli=1 tools/lint_sources.php
```

### Regeln der Dienstvorschriften

`app/dv_rules.php` führt die Regeln, denen die Anwendung entsprechen muss, mit
Quelle, Fundstelle und Anforderung. Ein Test benennt die Regel, die er
absichert, über die Fehlermeldung:

```php
require_once $root . '/app/dv_rules.php';
$assert($bedingung, estab_dv_requirement('NV-19-VERTEILER-EINGANG', 'was fehlt'));
```

`tests/php/dv_rule_registry.php` führt die abgedeckten Regeln zur Laufzeit mit
und schlägt fehl, sobald eine Regel ohne Test im Katalog steht oder ein Test
eine Regel benennt, die es nicht gibt. Eine Regel, deren Durchsetzung an einem
Datenbank-Trigger hängt, muss ihre Migration ausdrücklich mitprüfen -- sonst
gilt sie als erfüllt, während der Betrieb sie abweist.

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

Vollständiger Playwright-Nachrichtenablauf in einem eigenen, anschließend
gelöschten Compose-Projekt:

```console
npm ci --ignore-scripts
npm run test:e2e:install
ESTAB_CONTAINER_CLI=podman npm run test:e2e
```

Der Lauf öffnet die Selbstregistrierung zeitlich begrenzt, registriert die
persönlichen Konten A/W, LdF, Si, S1, S2, S3 und S6, lässt alle Personen ihre
strenge Dienstfunktion annehmen, aktiviert die Schicht und veröffentlicht
einen S6-Fernmeldeweg. Danach prüft er einen Eingang bis S1/S2/S3 samt
S2-geführtem ETB-Eintrag sowie je einen Ausgang von S1, S2 und S3 bis zum
Sichter, LdF, Fernmelder und TBB. Screenshots, Videos und Traces fehlgeschlagener
Schritte liegen unter `test-results/`; der HTML-Bericht unter
`playwright-report/`.

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
| JavaScript | `package.json` | `package-lock.json` | lokale Browserskripte, Lint und Playwright-E2E-Test |

Leere erfundene Laufzeitabhängigkeiten werden nicht angelegt. Die
eStab-eigenen Python-Werkzeuge verwenden nur die Standardbibliothek; dies ist in
`requirements.txt` ausdrücklich festgehalten. Der nichtleere, vollständig
aufgelöste Werkzeuggraph in `requirements-audit.txt` sorgt dafür, dass
pip-audit trotzdem reale Pakete prüft. Playwright ist ausschließlich eine
Entwicklungsabhängigkeit und gelangt nicht in das PHP-Laufzeitimage.

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
