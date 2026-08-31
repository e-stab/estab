# Umsetzungsplan — Fernmeldeplanung nach Fb Fü 76

Grundlage ist `tasks/fernmeldeplanung-spec.md`. Dieser Plan ordnet die sieben
Beschlüsse und neunzehn Entscheidungen in Phasen, Aufgaben und Prüfpunkte.

Er steht unter `SPEC.md` (Vorschrift und Bedienung) und `docs/GESTALTUNG.md`
(Gestaltung). Die Aufgabenliste führt `tasks/fernmeldeplanung-todo.md`.

---

## 1. Ausgangslage

Die S6-Fernmeldeplanung ist gebaut, versioniert und unveränderlich — aber sie
beschreibt die falsche Sache und in der falschen Gestalt.

| Woran es liegt | Wirkung |
| --- | --- |
| Der Plan wird stillschweigend als Adressbuch gelesen | Der Vorschlagsmechanismus schlägt dem LdF **die eigene Erreichbarkeit als Gegenstelle** vor (F10) |
| Ein Weg ist nur eine Zeile in einer Version | „Derselbe Funkweg wie in Version 3" ist keine beantwortbare Frage |
| Analog und digital teilen sich ein Feld „Kanal oder Rufgruppe" | Ein Digitalfunkweg trägt eine Bandlage, die es nicht gibt (F1, F2) |
| Der Eingang erfasst nur das Mittel | Der Plan bekommt nie eine Rückmeldung, welche Erreichbarkeit tatsächlich benutzt wird (F12) |
| Die Darstellung ist eine flache Liste | Drei Vorlagen — Fb Fü 76, PDV 800 Anlage 1 und die eigene Alttabelle `nv_komplan` — zeigen alle **einen Kasten je Stelle** |

Zwölf Befunde, F1 bis F12, stehen in der Spec, Abschnitt 3.3.

**Was gut ist und bleibt.** Die Versionierung, die Unveränderlichkeit
freigegebener Pläne, die Freigabe mit Nachweis, die Bindung an den Einsatz und
die Wegdisposition des LdF im Ausgang. Nichts davon wird angefasst; alles
Folgende baut darauf.

---

## 2. Architekturentscheidungen

### 2.1 Die Kennung zuerst, alles andere danach

Vier der zehn Schritte hängen an der dauerhaften Wegkennung
(`nv_fernmeldewege`), und sie ist der einzige, der eine Migration über
**alle** Bestandszeilen führt. Sie steht deshalb vorn — nicht weil sie
dringend ist, sondern weil jede spätere Einführung teurer wird.

### 2.2 Additiv migrieren, nie umschreiben

Freigegebene Pläne sind per Auslöser unveränderlich („Activated
telecommunications plans are immutable", Migration 94 und 117). Der Plan hält
das für **normativ**, nicht für ein Hindernis:

- Keine Migration ändert eine Zeile eines `AKTIV`- oder `ERSETZT`-Plans.
- `besondere_vermerke` bleibt lesend im Schema; die Zusammenführung geschieht
  beim **Versionswechsel**, nicht rückwirkend.
- `funkart` bleibt bei Altbeständen `NULL` = unbestimmt. Kein Raten.
- Wegkennungen werden **nicht** über Versionen hinweg verkettet.

Der Satz dahinter: Eine Angabe zu erfinden, die nie erfasst wurde, ist
schlimmer, als keine zu haben.

### 2.3 Die Grenze des Vordrucks ist eine Spaltennamenskonvention

Vordruckfelder tragen ihre Feldnummer (`01_medium`, `05_gegenstelle`), Angaben
der Anwendung das Präfix `estab_`. Diese Grenze wird nicht verschoben: Weg,
Wegbemerkung und ausgewählte Gegenstelle sind `estab_`-Spalten und erscheinen
nie zwischen den Vordruckfeldern (Spec 10.4, `FMP-WEG-AUSSERHALB-VORDRUCK`).

### 2.4 Die Vorschlagspolitik bekommt eine zweite Achse

Heute entscheidet `estab_read_message_suggestion_policy()` nur, **welches
Feld** eine Rolle bezuschlagen darf. Künftig auch, **aus welchen Quellen**.
Ohne diese zweite Achse wäre die Öffnung für den Stab (O6) ein Rückbau des
Schutzes vor Historienlecks; mit ihr ist sie keiner.

Das ist die einzige Änderung an einer Sicherheitsentscheidung in diesem Plan
und bekommt deshalb einen eigenen Prüfpunkt.

### 2.5 Ein Datenbestand, zwei Ansichten

Fb Fü 76 beschreibt zwei Leserkreise mit zwei Tiefen: den taktischen Führer
und das Betriebspersonal. eStab führt **einen** Bestand und zeigt ihn zweimal.
Es entsteht keine zweite Wahrheit, die auseinanderlaufen könnte.

### 2.6 Die Skizze wird erzeugt, nicht gezeichnet

Damit trägt sie Stand, Version und F.d.R. des Plans. Sie ist ohne die
taktischen Zeichen baubar (Linienart je Mittel) und wird mit ihnen
vollständig — die Zeichen sind kein blockierender Vorgang.

### 2.7 Zwei Vorgänge laufen getrennt

Der Abbau von `nv_komplan` und die Erzeugung der Skizze sind eigene Vorgänge.
Sie stehen in dieser Spec, weil sie hier gefunden bzw. entschieden wurden,
nicht weil sie zur Fernmeldeplanung gehören. Beide sind von allem anderen
unabhängig.

---

## 3. Phasen

```
P1  Fundament          A01–A05   Kennung, Schema, Migration
P2  Inhalt des Plans   A06–A13   Felder, Gegenstellen, Rückfallebene, Kopf
P3  Nutzung            A14–A20   Eingangsweg, Vorschläge, Vorbelegung
P4  Darstellung        A21–A25   Zwei Ansichten, Skizze
P5  Aufräumen          A26–A28   nv_komplan, Handbuch
```

### P1 — Fundament

`nv_fernmeldewege` mit `weg_id` und `weg_nummer`, die Spalte am Eintrag, die
Migration über alle Bestandszeilen, die Versionskopie. Am Ende dieser Phase
hat jeder Weg eine Kennung, die den Versionswechsel überlebt — und noch nichts
sieht anders aus.

**Warum zuerst:** A08 (Rückfallebene) und A19 (Wegstatistik) hängen daran, und
die Migration wird mit jedem weiteren Bestandsplan teurer.

### P2 — Inhalt des Plans

Die Trennung analog/digital, das eine Bemerkungsfeld, „Erreichbar unter", die
Stellenart, die Gegenstellenliste, die Rückfallebene, die Kopfleiste. Am Ende
dieser Phase kann der S6 den Plan erfassen, den die Vorlage vorsieht.

**Reihenfolge innerhalb der Phase:** erst die Felder am Weg (A06, A07), dann
die Kinder (A09 Gegenstellen, A08 Rückfallebene), zuletzt der Kopf (A12).
Grund: Die Kinder brauchen die Versionskopie, und die ist in A06 ohnehin
anzufassen.

### P3 — Nutzung

Der Eingangsweg mit seiner Bemerkung, die Berichtigung des Plan-Zweigs der
Vorschläge (F10), die Rangfolge (F11), die Quellenachse, die Vorbelegung von
Feld 15. Am Ende dieser Phase gibt der Plan Antworten, statt nur beschrieben
zu werden.

**A16 vor A17:** Der Plan-Zweig muss auf die Gegenstellen zeigen, bevor die
Rangfolge ihn nach oben holt. Sonst steht der falsche Vorschlag oben.

### P4 — Darstellung

Die taktische Stellenansicht, die betriebliche Tabelle, die Umschaltung, die
erzeugte Skizze, die Zeichen.

### P5 — Aufräumen

Abbau von `nv_komplan`, Nachführung von `docs/BEDIENUNG.md` und dem Handbuch.

---

## 4. Prüfpunkte

Nach jedem Prüfpunkt wird nicht weitergebaut, bevor er bestanden ist.

| Nr. | Nach | Woran er misst |
| --- | --- | --- |
| **C1** | A05 | Ein Bestandsplan ist nach der Migration **unverändert** lesbar; die Ausleitung liefert dasselbe Ergebnis wie zuvor. Ein Versionswechsel erhält alle Wegkennungen |
| **C2** | A08 | Eine Kette aus drei Wegen überlebt zwei Versionswechsel; Selbstbezug und Ring werden zurückgewiesen; das Löschen eines Hauptwegs nennt seine Ersatzwege |
| **C3** | A13 | Der S6 legt einen vollständigen Plan nach Fb Fü 76 an — vier Stellen, acht Wege, Gegenstellen, eine Rückfallebene — **ohne Rückfrage an die Entwicklung**. Bedienprüfung mit Bildschirmabzug |
| **C4** | A18 | **Sicherheitsprüfpunkt.** Der Stab bekommt an Feld 10 Vorschläge aus dem Plan und **keinen einzigen** aus der Historie. Die bestehenden Leserechtsprüfungen bleiben grün |
| **C5** | A20 | Eine eingehende Nachricht trägt Weg und Bemerkung; der LdF kann die Bemerkung nicht überschreiben; Feld 15 ist nach einer Gegenstellenauswahl vorbelegt und gekennzeichnet |
| **C6** | A25 | Die Skizze eines Plans mit vier Stellen und acht Wegen ist im Querformat ohne Überlappung lesbar und trägt Kopfleiste, Version und F.d.R. |

---

## 5. Risiken

| Risiko | Was dagegen hilft |
| --- | --- |
| **Die Migration berührt freigegebene Pläne.** Die Auslöser feuern und die Migration bricht mitten im Lauf ab | A03 wird gegen eine Kopie eines Bestandsdatenbestands gefahren, bevor sie ausgeliefert wird. `ALTER TABLE … CHANGE` ohne Wertewechsel feuert keinen Zeilenauslöser — das ist zu **prüfen**, nicht zu glauben |
| **Die Quellenachse öffnet ein Historienleck.** Eine Rolle bekommt Vorschläge, die sie nicht sehen darf | C4 ist ein eigener Prüfpunkt. Die Politik wird **erweitert, nicht gelockert**: Der Standard bleibt Zurückweisung, jede Quelle ist einzeln zu nennen |
| **Der Plan-Zweig zeigt zwischenzeitlich ins Leere.** Nach A16 liest er die neue Tabelle, die bis zur ersten Freigabe leer ist | Beabsichtigt und in der Spec festgehalten: Bis der S6 die erste Version mit Gegenstellen freigibt, trägt die Historie allein — wie bisher. Kein Zwischenzustand ist schlechter als heute |
| **Die Feldtrennung analog/digital stolpert über Altbestand.** Ein alter `Fu`-Weg hat weder Funkart noch die neuen Pflichtfelder | `funkart` bleibt `NULL`; der bestehende Altangaben-Hinweis wird erweitert. Ein Altweg blockiert nichts, bis ihn jemand bearbeitet |
| **Die Skizze wird ein Grafikprojekt.** Kantenrouting, Überlappungsvermeidung, Zoom | Die Anordnung folgt der Stellenart, nicht einem Algorithmus. Wenn die Skizze bei acht Wegen nicht ohne Handarbeit lesbar ist, ist der Entwurf falsch — nicht der Algorithmus zu klein |
| **Der Umfang wächst.** Neunzehn Entscheidungen laden dazu ein, „das eine noch" mitzunehmen | Jede Aufgabe nennt ihre Regel-ID. Was keine Regel hat, gehört nicht in diesen Vorgang |

---

## 6. Nicht in diesem Plan

| Was | Warum |
| --- | --- |
| Betriebliche Kommunikationsskizze mit Schaltzeichen | Eine Zeichnung, kein Bericht (Lücke L3) |
| Prüfung von Funkrufnamen, Bandlage, Verkehrsform, Rufgruppen | Freitext, Betreiberentscheidung O10 und O12 |
| Rufgruppen- und Kanalverzeichnisse | Nicht Teil der Anwendung (L4, L5) |
| Änderungen am Nachweis der Nachricht | `estab_fernmeldeplan_eintrag_id` bleibt zeilengebunden (Spec 9.4) |
| „Kennwort" aus PDV 800 Anlage 1 | Fb Fü 76 kennt es nicht; Sachnähe entscheidet (Spec 11) |

---

## 7. Kommandos

```console
# statische Suite mit Regelkatalog-Nachweis
tests/static/run.sh

# einzelner Nachweis
PHP_BIN=php php tests/php/dv_rule_registry.php

# Schema und Laufzeit
podman compose build --pull migrate app
podman compose up -d
curl --fail --silent --show-error http://127.0.0.1:8080/health.php

# Browser- und Datenbanktests
ESTAB_CONTAINER_CLI=podman ESTAB_BROWSER_TEST=auto tests/static/run.sh
ESTAB_CONTAINER_CLI=podman npm run test:e2e
```

Auf dieser Maschine steht kein lokales `php` und kein lokales `node` zur
Verfügung; alles läuft über den Container. `nohup` zerstört die Signalprüfung
der Suite und ist nicht zu verwenden.

Neue Migrationen beginnen bei **122** — die höchste vorhandene ist
`121-transport-disposition-field-one.sql`.
