# Playwright-E2E-Test

`message-workflow.spec.js` prüft den fachlichen Hauptablauf im echten Browser.
Der Runner `run.sh` erstellt dafür ein frisches Compose-Projekt mit zufälligen
Secrets und einer eigenen Portnummer. Er akzeptiert ausschließlich Projektnamen
mit dem Präfix `estab_e2e` und löscht beim Abschluss nur dessen Container und
Volumes.

Der Ablauf umfasst:

1. zeitlich begrenzte Selbstregistrierung und persönliche Konten,
2. strenge Schicht mit A/W (Fernmelder), LdF, Si, S1, S2, S3 und S6,
3. persönliche Annahme und Auswahl jeder Dienstfunktion,
4. aktiven S6-Fernmeldeplan,
5. Eingang A/W → LdF → Si → S1/S2/S3,
6. verknüpften ETB-Eintrag durch die bestimmte S2-ETB-Führung,
7. je einen Ausgang von S1, S2 und S3 über Si → LdF → A/W,
8. abschließende Datenbankprüfung von Status, Ereignisfolge, ETB und TBB.

Lokal:

```console
npm ci --ignore-scripts
npm run test:e2e:install
ESTAB_CONTAINER_CLI=podman npm run test:e2e
```

Für einen bereits ausdrücklich vorbereiteten E2E-Stack kann der reine
Playwright-Lauf mit `npm run test:e2e:prepared` gestartet werden. Die dafür
benötigten `ESTAB_E2E_*`-Variablen setzt normalerweise `run.sh`.
