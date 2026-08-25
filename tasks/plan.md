# Umsetzungsplan — Meldewesen der THW-Führungsstelle

Grundlage ist `SPEC.md`. Dieser Plan ordnet die dort offenen Anforderungen in
Aufgaben, Phasen und Prüfpunkte. Er beschreibt **wie** gearbeitet wird, nicht
**was** gefordert ist — das steht in der Spec und wird hier nur referenziert.

---

## 1. Ausgangslage

Die Spec führt 81 Anforderungen. Davon sind 20 erfüllt und nachgewiesen; die
übrigen 61 sind Gegenstand dieses Plans.

| Ist-Stand | Anzahl | Bedeutung für den Plan |
| --- | --- | --- |
| `erfüllt` | 20 | nichts zu tun |
| `ohne Regel` | 27 | Verhalten stimmt, Nachweis fehlt — verhaltensneutral |
| `teilweise` | 12 | Verhalten unvollständig |
| `offen` | 21 | Verhalten fehlt |
| `kein Nachweis` | 1 | nur manuell prüfbar, laufende Aufgabe |

Der größte Block sind die 27 Anforderungen ohne Regel. Sie verlangen keine
Verhaltensänderung, sondern nur den fehlenden Nachweis. Sie stehen deshalb
vorn: Sie sind risikoarm, und jede spätere Verhaltensänderung arbeitet danach
gegen ein abgesichertes Fundament statt gegen Vermutungen.

---

## 2. Wie geschnitten wird

### 2.1 Vertikal, nicht nach Schichten

Eine Aufgabe liefert **einen vollständigen Weg** von der Vorschrift bis zur
grünen Suite: Test, Regel, gegebenenfalls Verhalten, Dokumentation. Keine
Aufgabe liefert „alle Tests" oder „alle Regeln" oder „die Oberfläche" als
eigene Schicht.

Das ist hier keine Stilfrage. Der Nachweisapparat erzwingt es: Eine Regel ohne
Test bricht `dv_rule_registry.php`, ein Test ohne Regel bricht bei der
Auflösung der Kennung. Wer die Schichten trennt, hinterlässt eine rote Suite
zwischen den Aufgaben.

### 2.2 Zwei Arbeitsmuster

Jede Aufgabe folgt einem der beiden Muster. Die Aufgabenbeschreibungen nennen
nur, was davon abweicht.

**Muster A — Nachweis nachziehen** (verhaltensneutral):

1. Test schreiben, der das **vorhandene** Verhalten festnagelt. Er ist sofort
   grün — das ist hier richtig und keine Nachlässigkeit: Der Test sichert, was
   schon stimmt.
2. Regel im zuständigen Katalog eintragen, mit Herkunft, Fundstelle und
   Anforderungssatz.
3. Testdatei in der Liste in `tests/static/run.sh` registrieren.
4. Zeile in `SPEC.md` Abschnitt 5 auf `erfüllt` setzen.
5. Suite grün.

Gegenprobe zu Schritt 1: Der Test muss rot werden, wenn man das Verhalten
probeweise entfernt. Ein Test, der immer grün ist, sichert nichts.

**Muster B — Verhalten ändern:**

1. Test schreiben, der das **geforderte** Verhalten prüft. Er ist rot.
2. Verhalten umsetzen, bis der Test grün ist.
3. Regel eintragen, Testdatei registrieren.
4. `docs/BEDIENUNG.md` nachziehen, sofern im Betrieb sichtbar.
5. Zeile in `SPEC.md` auf `erfüllt` setzen.
6. Suite grün.

### 2.3 Was jede Aufgabe berührt

| Ort | Wann |
| --- | --- |
| `tests/php/<name>.php` | immer — neue oder erweiterte Testdatei |
| `tests/static/run.sh` | bei **neuer** Testdatei — Eintrag in die Liste ab Zeile 185 |
| `app/dv_rules.php` bzw. `app/ux_rules.php` | immer — die Regel |
| `SPEC.md` Abschnitt 5 | immer — Ist-Stand auf `erfüllt` |
| `docs/BEDIENUNG.md` | bei sichtbarer Änderung |
| `docker/db/migrations/` | nur bei Schemaänderung, nächste freie Nummer ab 122 |

Die Registry findet abdeckende Tests selbst per Glob über `tests/php/*.php`;
die Registrierung in `run.sh` ist davon unabhängig und sorgt dafür, dass der
Test **als eigener Prüfschritt** läuft. Beides ist nötig.

---

## 3. Abhängigkeitsgraph

```
T01 Quelle Meldewesen ─────┬──> T19  Leitfragen
                           ├──> T30  Übermittlungsmittel
                           ├──> T31  Melder/Kurier
                           └──> T33  Meldeart ──┬──> T34 Meldung
T02 Verdrängungsvermerk ───┘                    ├──> T35 Orientierung
                                                ├──> T36 Antrag
                                                ├──> T37 Sofortmeldung
                                                └──> T38 Lagemeldung ──> T39 Anlass

T03 Bedienkatalog ─────────> alle T13 bis T28 (jede UX-Regel)

T31 Melder/Kurier ─────────> T32 Pflichten des Melders

T16 Nummernbrücke ─────────> empfohlen vor T17 bis T20 (kein Zwang)
P1 + P2 (Nachweise) ───────> empfohlen vor jeder Verhaltensänderung
```

Nur vier harte Abhängigkeiten:

1. **T01** blockiert jede Regel, die sich auf die Lernunterlage stützt. Die
   Registry führt eine Positivliste erlaubter Quellen; eine Regel mit
   unbekannter Quelle bricht sie.
2. **T02** blockiert `TKM-MELDER-KURIER`, weil diese Regel eine Aussage der
   DV 1-101 ausdrücklich verdrängt und die Fundstelle mitführen muss.
3. **T03** blockiert jede Bedienregel — ohne Katalog kein Eintrag.
4. **T33** blockiert alles Übrige in P7 und P8, weil Orientierung, Antrag und
   Lagemeldung Ausprägungen der Meldeart sind.

Die weiche Abhängigkeit P1/P2 vor allem Weiteren ist keine Formalie: Ohne sie
verändert man Verhalten, für das kein Test existiert.

---

## 4. Phasen und Aufgaben

Legende der Risikostufe: **niedrig** = verhaltensneutral · **mittel** =
sichtbare Änderung, umkehrbar · **hoch** = sichtbare Änderung für alle
Anwender oder nicht umkehrbar.

### P0 — Nachweisapparat

Blockiert alles Weitere. Drei Aufgaben, keine davon ändert Verhalten.

**T01 — Quelle „Grundlagen des Meldewesens" aufnehmen**
Anforderung: `REG-QUELLE-MELDEWESEN` · Risiko: niedrig
Neue Konstante `ESTAB_DV_SOURCE_MELDEWESEN` in `app/dv_rules.php`, ergänzt um
den Eintrag in der Quellen-Positivliste in `tests/php/dv_rule_registry.php`.
*Abnahme:* Eine Regel mit dieser Quelle wird von der Registry angenommen; eine
Regel mit einer nicht gelisteten Quelle wird weiterhin abgewiesen.

**T02 — Verdrängungsvermerk einführen**
Anforderung: `REG-VORRANGVERMERK` · Risiko: niedrig
Optionales Feld `verdraengt` je Regel, das Quelle und Fundstelle der
verdrängten Aussage trägt. Die Registry prüft: Ist das Feld gesetzt, ist es
vollständig.
*Abnahme:* Eine Regel mit unvollständigem Verdrängungsvermerk bricht die
Registry.

**T03 — Bedienkatalog anlegen**
Apparat, keine Einzelanforderung · Risiko: niedrig
`app/ux_rules.php` mit `estab_ux_rules()`, `estab_ux_rule()` und
`estab_ux_requirement()` als Spiegel des Vorschriftenkatalogs; dazu
`tests/php/ux_rule_registry.php` und der Eintrag in `run.sh`.
*Abnahme:* Der leere Katalog bricht die Registry nicht; eine Regel ohne Test
bricht sie; eine unbekannte Kennung wird laut abgewiesen.

> **Prüfpunkt C0** — siehe Abschnitt 5.

### P1 — Nachweise nachziehen: Vorschrift

20 Anforderungen, alle Muster A, alle verhaltensneutral. Die Aufgaben sind
nach Themen geschnitten, damit je Aufgabe eine lesbare Testdatei entsteht.
Untereinander unabhängig — parallelisierbar.

| ID | Aufgabe | Anforderungen |
| --- | --- | --- |
| T04 | Vermerke der Fernmeldezentrale | `NV-02-AUFNAHMEVERMERK`, `NV-03-ANNAHMEVERMERK`, `NV-04-BEFOERDERUNGSVERMERK`, `NV-05-TBB-NUMMER` |
| T05 | Felder ohne eigene Prüfung | `NV-01-TKM-TATSAECHLICH`, `NV-06-RUFNAME`, `NV-11-RUFNUMMER`, `NV-13-INHALT-BETREFF` |
| T06 | Beglaubigung und Quittung | `NV-17-ZEICHEN-FUNKTION`, `NV-18-QUITTUNG` |
| T07 | Stationen des Laufwegs | `LW-EINGANG-STATIONEN`, `LW-AUSGANG-STATIONEN` |
| T08 | Feldhoheit und Korrekturschleife | `LW-NUR-BLAUER-TEIL`, `LW-KORREKTURSCHLEIFE` |
| T09 | Die vier Durchschriften | `NV-4FACH-VERTEILUNG` |
| T10 | Sichter und LdF im Laufweg | `FUEST-SICHTER-BINDEGLIED`, `FUEST-LDF-BETRIEB` |
| T11 | Fortschreibung und Aufbewahrung | `ETB-APPEND-ONLY`, `ETB-AUFBEWAHRUNG` |
| T12 | Fernmeldeplan | `TKM-FERNMELDEPLAN` |

Alle Risiko niedrig. Besonderheiten:

- **T09** ist die inhaltlich dichteste: Die rote und die grüne Durchschrift
  werden serverseitig unabwählbar ergänzt (`4fach/4fachform.php`). Der Test
  muss belegen, dass beide auch dann entstehen, wenn der Verfasser sie
  abzuwählen versucht.
- **T11** hält `ETB-AUFBEWAHRUNG` gegen die Vorschriftenfrist von einem Jahr,
  nicht gegen die zehn Jahre des Bestands. Der Test prüft die Untergrenze, so
  dass eine spätere Verkürzung auf unter ein Jahr auffällt.

> **Prüfpunkt C1**

### P2 — Nachweise nachziehen: Bedienung

7 Anforderungen, Muster A, verhaltensneutral. Setzt T03 voraus.

| ID | Aufgabe | Anforderungen |
| --- | --- | --- |
| T13 | Das Papierbild des Vordrucks | `UX-EINE-SEITE`, `UX-PAPIERBILD`, `UX-SPRACHE-VORSCHRIFT` |
| T14 | Ausfüllhilfen und Rückmeldung | `UX-INFOPOINTER`, `UX-RUECKMELDUNG` |
| T15 | Standort und flache Bildschirme | `UX-STANDORT`, `UX-FLACHE-BILDSCHIRME` |

- **T13** prüft die Feldfolge gegen die Q1-Nummernfolge und die drei Teile der
  Unterlage. Die Feldbeschriftungen werden gegen die Benennungen der
  Ausfüllanleitung gehalten — die Prüfliste dafür gehört in den Test, nicht in
  den Anwendungscode.
- **T15** übernimmt für `UX-FLACHE-BILDSCHIRME` die vorhandene Prüfung aus
  `tests/php/viewport_density_security.php`; neu ist nur die Regel und der
  Aufruf von `estab_ux_requirement()`.

> **Prüfpunkt C2**

### P3 — Vordruck schärfen

8 Anforderungen, Muster B. Ab hier ändert sich Verhalten.

**T16 — Feldnummern auf eine Brücke ziehen**
Anforderung: `NV-NUMMERNBRUECKE` · Risiko: mittel
Eine Abbildungsfunktion wird die einzige Stelle, an der zwischen der Zählung
der Ausfüllanleitung (20 Felder) und der der Stab-Unterlage (17 Felder)
übersetzt wird. Bestehende Stellen werden darauf umgestellt, einschließlich
des Kommentars in `4fach/4fachform.php`, der heute eine Nummer der einen
Zählung neben einem Bezeichner der anderen führt.
*Abnahme:* Ein Test findet keine zweite Übersetzungsstelle und keinen
Zählungsbruch zwischen Kommentar und Bezeichner.
*Warum zuerst in dieser Phase:* T17 bis T20 fassen Felder an. Ohne die Brücke
arbeitet jede dieser Aufgaben mit zwei Zählungen im Kopf.

| ID | Aufgabe | Anforderungen | Risiko |
| --- | --- | --- | --- |
| T17 | Dienststelle statt Eigenname | `NV-10-ANSCHRIFT-DIENSTSTELLE`, `NV-15-ABSENDER` | mittel |
| T18 | Form und Vorrangstufe | `NV-08-DURCHSAGE-SPRUCH`, `NV-09-VORRANGSTUFE` | mittel |
| T19 | Leitfragen und Tatsachenkennzeichnung | `NV-14-5W`, `NV-14-TATSACHE-VERMUTUNG` | mittel |
| T20 | Datum-Uhrzeit-Gruppe mit Monatskürzel | `NV-DATUM-MONATSKUERZEL` | niedrig |

- **T17** braucht eine Entscheidung im Zuschnitt: Eine Prüfung, die Eigennamen
  zuverlässig erkennt, gibt es nicht. Die Aufgabe setzt deshalb auf Führung
  statt Zurückweisung — Beschriftung, Ausfüllhilfe und Rückweisungsgrund
  benennen die geforderte Bezeichnungsart. Wer mehr will, entscheidet das an
  Prüfpunkt C3.
- **T18** enthält den Umgang mit den Vorrangstufen jenseits des Vordrucks
  (`Staatsnot`, `einfach`): Sie bleiben wählbar, dürfen im Ausdruck aber kein
  Ankreuzfeld vortäuschen, das der amtliche Vordruck nicht hat.
- **T19** setzt T01 voraus.

> **Prüfpunkt C3**

### P4 — Konstanz der Bedienung

4 Anforderungen. Die Phase mit der größten sichtbaren Wirkung.

**T21 — Navigation bleibt stehen**
Anforderung: `UX-MENUE-ORTSKONSTANZ` · Risiko: **hoch**
Löst Kollision K1 der Spec: Unzulässige Navigationsziele werden nicht mehr
ausgeblendet, sondern sichtbar und inaktiv mit Grund geführt.
*Abnahme:* Für jede Kombination aus Modus, Schicht und Funktion sind Menge und
Reihenfolge der Einträge identisch; jeder inaktive Eintrag trägt einen Grund.
*Sicherheitslage:* Unverändert. Die Navigation ist ausdrücklich keine
Sicherheitsgrenze; jeder Endpunkt prüft seine Berechtigung selbst. Der Test
muss das mitprüfen, damit die Sichtbarkeit nicht als Freigabe missverstanden
wird.

| ID | Aufgabe | Anforderungen | Risiko |
| --- | --- | --- | --- |
| T22 | Ein Weg je Ziel | `UX-MENUE-EIN-WEG` | mittel |
| T23 | Katalog der wiederkehrenden Elemente | `UX-ELEMENTKONSTANZ` | mittel |
| T24 | Kein Bruch beim Stationswechsel | `UX-KEIN-BRUCH-IM-LAUFWEG` | mittel |

> **Prüfpunkt C4** — erste vollständige Bedienprüfung nach `UX-EINARBEITUNG`

### P5 — Zugänglichkeit

| ID | Aufgabe | Anforderungen | Risiko |
| --- | --- | --- | --- |
| T25 | Zuständigkeit ohne Farbe | `UX-MEINE-FELDER`, `UX-MEINE-FELDER-OHNE-FARBE` | mittel |
| T26 | Kontrast im Vordruck | `UX-KONTRAST` | niedrig |
| T27 | Tastaturbedienung | `UX-TASTATUR` | mittel |
| T28 | Laufweg ohne JavaScript | `UX-OHNE-JAVASCRIPT` | mittel |

- **T25** ergänzt die heutige Kennzeichnung über Hintergrundfarben um ein
  zweites, farbunabhängiges Merkmal. Die Prüfung entfernt jede Farbinformation
  und verlangt, dass zuständig, fremd und Pflicht weiterhin unterscheidbar
  sind.
- **T26** dehnt die vorhandene Kontrastprüfung von den Nachrichtenlisten auf
  den Vordruck aus, einschließlich der Felder, die die Farbe einer
  Durchschrift tragen.

> **Prüfpunkt C5**

### P6 — Führungsstelle und Führungsmittel

**T29 — Sichtung im Ausgang auf die Form begrenzen**
Anforderung: `FUEST-SICHTER-AUSGANG-FORMAL` · Risiko: **hoch**
Die DV 1-101 Kap. 4.3.1.10 beschränkt die Prüfung des Sichters im Ausgang auf
Anschrift, Unterschrift und Funktion: „eine inhaltliche Prüfung der Nachricht
entfällt." Heute kann der Sichter im Ausgang aus beliebigem Grund
zurückweisen.
*Abnahme:* Die Rückweisung im Ausgang lässt nur Gründe zu, die sich auf Feld
10, 15 oder 17 beziehen.
*Vorbehalt:* Diese Aufgabe nimmt einer Funktion eine heute vorhandene
Möglichkeit. Sie wird erst nach ausdrücklicher Bestätigung an Prüfpunkt C5
begonnen.

| ID | Aufgabe | Anforderungen | Risiko | hängt ab von |
| --- | --- | --- | --- | --- |
| T30 | Übermittlungsmittel erweitern | `TKM-KATALOG` | mittel | T01 |
| T31 | Melder und Kurier trennen | `TKM-MELDER-KURIER` | mittel | T01, T02 |
| T32 | Pflichten des Melders | `TKM-MELDER-PFLICHTEN` | mittel | T31 |

- **T31** setzt die Vorrangentscheidung der Spec um: Die Lernunterlage (2024)
  verdrängt die DV 1-101 (2006). Das Ankreuzfeld des Vordrucks bleibt
  unverändert `Kurier/Melder`; die Rolle wird zusätzlich geführt. Die Regel
  trägt den Verdrängungsvermerk aus T02.
- **T32** prüft die Pflichten am Melderauftrag: Rückmeldung mit Empfänger,
  kein zweiter Auftrag vor der Rückkehr.

> **Prüfpunkt C6**

### P7 — Meldearten

6 Anforderungen, neues Modul M5. Der größte geschlossene Block.

**T33 — Die Meldeart als Merkmal**
Anforderung: `MW-MELDEART` · Risiko: **hoch** · hängt ab von T01
Eine Nachricht trägt ihre Art. Umfasst Schemaänderung (neue Migration ab 122),
Feld im Vordruck, Anzeige in Liste, ETB und Technischem Betriebsbuch.
Bestandsnachrichten erhalten „Meldung"; die Migration ist nach der Regel des
Repositorys nach Veröffentlichung nicht mehr änderbar.
*Abnahme:* Jede Nachricht trägt eine Art; die Art ist am Vordruck sichtbar und
in beiden Büchern nachvollziehbar; `schema_migration_contract` bleibt grün.
*Vertikaler Schnitt:* Diese Aufgabe liefert den vollständigen Weg für **eine**
Art — die Meldung. Orientierung und Antrag folgen als eigene Wege.

| ID | Aufgabe | Anforderungen | Risiko |
| --- | --- | --- | --- |
| T34 | Meldung läuft nach oben | `MW-MELDEWEG-RICHTUNG` | mittel |
| T35 | Orientierung | `MW-ORIENTIERUNG-RICHTUNG` | mittel |
| T36 | Antrag | `MW-ANTRAG-RICHTUNG`, `MW-ANTRAG-SCHEMA` | mittel |
| T37 | Sofortmeldung ohne Aufforderung | `MW-SOFORTMELDUNG` | mittel |

Alle vier hängen von T33 ab, untereinander sind sie unabhängig.

- **T34** schränkt bestehendes Verhalten ein: Eine Meldung an eine
  nachgeordnete Stelle ist keine Meldung. Der Zuschnitt muss entscheiden, ob
  die Anwendung zurückweist oder in die Orientierung umlenkt — Vorschlag:
  umlenken mit Hinweis, weil eine Zurückweisung im Einsatz Zeit kostet und der
  Anwender die Unterscheidung nicht kennen muss.
- **T37** belegt drei Anlässe, die ohne Aufforderung zu melden sind:
  Gefahrstoffe und Gefahrgüter, Abschluss des Auftrages, Abweichung vom
  Auftrag.

> **Prüfpunkt C7**

### P8 — Lagemeldung

| ID | Aufgabe | Anforderungen | Risiko | hängt ab von |
| --- | --- | --- | --- | --- |
| T38 | Lagemeldung mit Acht-Punkte-Aufbau | `LM-AUFBAU`, `LM-MELDEWEG` | mittel | T33 |
| T39 | Anlass der Lagemeldung | `LM-ANLASS` | niedrig | T38 |

- **T38** setzt die acht Punkte in der vorgeschriebenen Reihenfolge um; leere
  Punkte entfallen im Ausdruck, weil die Vorschrift Angaben „nur zu relevanten
  Punkten" verlangt.

> **Prüfpunkt C8**

### P9 — Rest

| ID | Aufgabe | Anforderungen | Risiko |
| --- | --- | --- | --- |
| T40 | Entscheidungen im ETB | `ETB-ENTSCHEIDUNGEN` | mittel |

> **Abschlussprüfpunkt**

---

## 5. Prüfpunkte

Ein Prüfpunkt ist ein Halt. Er wird nicht überschritten, ohne dass ein Mensch
die Fragen beantwortet hat.

| Punkt | nach | Vorlage | Frage |
| --- | --- | --- | --- |
| **C0** | P0 | beide Kataloge, beide Registries grün | Trägt der Apparat? Ist die Trennung von Vorschrift und Bedienung an den Kennungen erkennbar? |
| **C1** | P1 | 20 neue Regeln, Suite grün | Halten die Tests wirklich Verhalten fest, oder prüfen sie Zeichenketten? Stichprobe an drei Tests: Verhalten probeweise entfernen, Test muss rot werden. |
| **C2** | P2 | 7 Bedienregeln | Beschreiben die Bedienregeln, was der Anwender erlebt — oder was die Umsetzung tut? |
| **C3** | P3 | Vordruck geschärft | Ist die Führung bei Anschrift und Absender ausreichend, oder soll die Anwendung Eigennamen zurückweisen? Entscheidung zu T17. |
| **C4** | P4 | Navigation geändert | **Erste Bedienprüfung nach `UX-EINARBEITUNG`:** drei Personen ohne Anwendungskenntnis, je ein vollständiger Nachrichtenlauf. Protokoll über Abbrüche, Rückfragen, Griffe zum Handbuch. Jeder Abbruch ist ein Mangel und wird vor C5 behoben. |
| **C5** | P5 | Zugänglichkeit | Freigabe für **T29** — nimmt dem Sichter eine heute vorhandene Möglichkeit. Ohne ausdrückliche Bestätigung wird P6 ohne T29 begonnen. |
| **C6** | P6 | Melder/Kurier getrennt | Trägt die Rollentrennung im Betrieb, oder erzeugt sie eine Frage, die im Einsatz niemand beantworten will? |
| **C7** | P7 | Meldearten vollständig | Zweite Bedienprüfung. Die Meldearten sind der größte Zuwachs an Begriffen — genau hier droht der Rückgriff aufs Papier. |
| **C8** | P8 | Lagemeldung | Ist der Acht-Punkte-Aufbau im Einsatz ausfüllbar, oder ist er ein Formular, das niemand füllt? |
| **Abschluss** | P9 | 81 von 81 | Bedienprüfung, vollständiger Testlauf, Spec ohne offene Zeile. |

---

## 6. Nachweis je Aufgabe

```console
# Vollständige statische Suite — Pflicht vor jedem Abschluss einer Aufgabe
tests/static/run.sh

# Einzelner Nachweis während der Arbeit
php tests/php/<neue-testdatei>.php
php tests/php/dv_rule_registry.php
php tests/php/ux_rule_registry.php

# Bei Schemaänderung zusätzlich
php tests/php/schema_migration_contract.php
podman compose run --rm migrate

# Bei Oberflächenänderung zusätzlich
ESTAB_CONTAINER_CLI=podman ESTAB_BROWSER_TEST=auto tests/static/run.sh
ESTAB_CONTAINER_CLI=podman npm run test:e2e
```

Auf dieser Maschine steht kein lokales `php` zur Verfügung; die Aufrufe laufen
über den Container. `nohup` zerstört die Signalprüfung der Suite und ist nicht
zu verwenden.

Eine Aufgabe ist erst fertig, wenn zusätzlich die Definition of Done aus
`SPEC.md` Abschnitt 12 erfüllt ist — einschließlich Punkt 7, den Anforderungen
aus M9 für jede berührte Oberfläche.

---

## 7. Risiken

| ID | Risiko | Betrifft | Umgang |
| --- | --- | --- | --- |
| R1 | Navigation ändert sich für alle Anwender gleichzeitig | T21 | Bedienprüfung an C4 unmittelbar danach; Rücknahme durch Umkehr einer abgegrenzten Änderung an der Navigation |
| R2 | T29 nimmt dem Sichter eine Möglichkeit, die er heute nutzt | T29 | Freigabe an C5 abwarten; ohne Bestätigung entfällt die Aufgabe |
| R3 | Schemaänderung ist nach Veröffentlichung nicht mehr änderbar | T33 | Migration vor der Veröffentlichung gegen echte Bestandsdaten prüfen; Bestandsnachrichten erhalten „Meldung" als Vorbelegung |
| R4 | Umbau der Feldnummern übersieht eine Stelle in einer Datei mit über 3000 Zeilen | T16 | erst nach P1 und P2 beginnen, damit das Verhalten des Vordrucks abgesichert ist |
| R5 | Bedienprüfung braucht Menschen, die die Anwendung nicht kennen | C4, C7 | Teilnehmende früh anfragen; wer die Prüfung einmal gemacht hat, ist für die nächste ungeeignet |
| R6 | Meldearten verdreifachen die Begriffe im Vordruck | P7 | Vorbelegung „Meldung", damit der gewohnte Fall ohne Zusatzentscheidung bleibt |

---

## 8. Was dieser Plan nicht enthält

- **Termine und Aufwände.** Der Plan ordnet und begründet die Reihenfolge; er
  schätzt nicht.
- **Die 20 erfüllten Anforderungen.** Sie sind nachgewiesen und werden nur
  berührt, wenn eine Aufgabe sie ohnehin anfasst.
- **Alles außerhalb des Geltungsbereichs** nach `SPEC.md` Abschnitt 1.4 —
  Lagekarte, Lagevortrag, Führungsvorgang, Öffentlichkeitsarbeit, Logistik,
  Alarmierung.
- **Eine Freigabeentscheidung.** Der Plan endet an Aufgaben und Prüfpunkten.
