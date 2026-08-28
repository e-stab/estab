# Aufgabenliste — Rückmeldungen aus dem Betrieb

Begründung und Prüfpunkte stehen in `tasks/rueckmeldungen-plan.md`, die
Anforderungen in `tasks/rueckmeldungen-spec.md` und `docs/TABELLEN.md`.

Risiko: `·` niedrig · `!` mittel · `!!` hoch
Umfang: **XS** 1 Datei · **S** 1–2 · **M** 3–5

Reihenfolge: **Test zuerst, dann die Änderung, dann das Bild ansehen.**

---

## P1 — Blocker: was gerade an der Arbeit hindert

- [x] **R01** `·` **XS** Weiße Schrift bei BOS-Info
      → `estab-ui.css`
      - [x] `.estab-bos-document-header` und sein `h1` tragen `--tinte` auf hellem Grund
      - [x] Der Bereichsmarker darüber trägt `--tinte-neben`, nicht `--marke-standort` (Gold auf Hell erreicht 1.67)
      - **Prüfung:** `ges_kontrast.php`; Bild der BOS-Info-Seite
      - **Abhängigkeit:** keine — **Fehler der Gestaltungsumstellung, nicht des Bestands**

- [x] **R02** `!` **S** `GES-TINTE-BRAUCHT-GRUND`
      → `tests/php/ges_kontrast.php`, `app/ux_rules.php`, `docs/GESTALTUNG.md`
      - [x] `--tinte-spalte` und `--tinte-spalte-neben` nur dort, wo ein dunkler Grund gesetzt ist — auf derselben Regel oder unter einem benannten dunklen Bereich
      - [x] Selbstprobe: eine Regel mit heller Fläche und Spaltentinte muss auffallen
      - [x] Spec Abschnitt 2.4 nennt die Regel
      - **Prüfung:** volle Suite; R01 rückgängig gemacht muss rot werden

> **Prüfpunkt D0 — bestanden.** Der Wächter fand den Fehler beim ersten Lauf
> und zwar **fünfmal**: Nicht nur der Dokumentkopf der Infosammlung, sondern
> auch die Köpfe von Verwaltung, Ausgabe, Werkzeugseiten und E-Mail-Ansicht
> trugen weiße Tinte ohne ihren Grund. Sie hatten alle dasselbe entfernte
> Banner. Gemeldet war einer. Dazu trägt er eine Selbstprobe.

- [x] **R03** `!!` **M** Der LdF trägt die Fernmelder-Tätigkeiten
      → `app/sidebar.php`, `app/workflow.php`, `app/read_authorization.php`
      - [x] Der LdF bekommt die Arbeitsschritte Eingang, Ausgang, 2. Sichtung und Anhänge
      - [x] Und den Leseumfang, den er dafür braucht
      - [x] **Er bleibt dabei LdF** — er wird nicht zu einem zweiten A/W
      - **Prüfung:** bestehende Berechtigungstests bleiben grün, insbesondere die strikte Trennung
      - **Risiko heraufgesetzt auf `!!`.** Der erste Anlauf ist zurückgenommen

      **Was der erste Anlauf ergeben hat.** Ich hatte den LdF die Funktion A/W
      mittragen lassen — eine Zeile in `estab_auth_effective_function_roles`.
      Das gibt ihm in einem Zug Arbeitsschritte und Sicht und war deshalb
      verlockend. Es bricht aber **sieben** Prüfungen, und zwei davon sind
      keine Formsache:

      - `workflow_security` verlangt namentlich, dass **A/W- und
        LdF-Identitäten strikt getrennt** sind. `estab_workflow_is_telecommunications($ldf)`
        muss falsch bleiben. Das ist ein benannter Invariant, keine
        Nebenwirkung.
      - `FUEST-DOPPELFUNKTION` ist eine **Dienstvorschriftenregel** aus DV
        1-101: Wer mehrere Funktionen trägt, bekommt je Funktion eine
        Warteschlange. Ein LdF, der A/W mitträgt, bekäme im Cockpit zwei
        Warteschlangen — eine Anzeigeänderung, die niemand verlangt hat, und
        sie verwischt den Unterschied zwischen „leitet den Betrieb" und „ist
        an der Annahmestelle eingeteilt".

      Der richtige Weg ist der schmalere: **nicht** eine zweite Funktion,
      sondern eine erweiterte Erlaubnis der einen. Der LdF bleibt LdF, bekommt
      aber die Arbeitsschritte des A/W freigegeben und den Leseumfang dazu.
      Das berührt drei Stellen statt einer und lässt beide Invarianten stehen.

- [x] **R04** `!!` **S** Die Meldungsübersicht steht allen offen
      → `4fueltg/ue_ltg.php`, `app/read_authorization.php`
      - [x] Lesenden Zugriff hat jede angemeldete Funktion im aktiven Einsatz
      - [x] **Jede schreibende Prüfung bleibt unverändert** — der Test verlangt das ausdrücklich
      - [x] Die Abweisung „ist der aktiven Lage/Dokumentation vorbehalten" entfällt
      - **Prüfung:** Übersicht für jede Funktion aufrufen, keine wird abgewiesen; Schreibtests unverändert grün
      - **Risiko:** höchstes in P1 — eine zu weit geöffnete Leseberechtigung zeigt Meldungen fremder Funktionen

- [x] **R05** `!` **S** TBB schreibt der LdF, ETB der S2
      → `app/logbook.php`, `app/dv_operations.php`
      - [ ] TBB: **nur** die Funktion LdF schreibt
      - [ ] ETB: **nur** S2 oder ETB-Führer
      - [ ] Die Meldung „besitzen aber nicht die Fachzuständigkeit" erscheint dem LdF nicht mehr
      - **Prüfung:** je Buch ein Test über alle Funktionen — einer schreibt, alle anderen werden abgewiesen

> **Prüfpunkt D1** — Kann der LdF ins TBB? Liest jede Funktion die Übersicht?
> Und ist **nichts** aufgegangen, was zu bleiben hatte?

---

## P2 — Der Vordruck: Vorschriftentreue

- [x] **R06** `!!` **M** Durchsage und Spruch sind optional
      → `app/dv_rules.php`, `4fach/official_message_form.php`, `tests/php/…`
      - [ ] `NV-08-DURCHSAGE-SPRUCH` neu gefasst: beide optional, keines vorbelegt
      - [ ] **Erst der Test, dann die Regel** — der bestehende Test verlangt heute die Vorbelegung
      - [ ] Ein leerer Vordruck trägt kein gesetztes Kästchen
      - [ ] Die Ausfüllhilfe sagt, was ein Spruch ist (1:1 im Wortlaut) und was eine Durchsage (an eine Gruppe)
      - **Prüfung:** Regeltest; leeren Vordruck öffnen; Bild
      - **Risiko:** eine Vorschriftenregel zu ändern ist der schwerste Eingriff der Liste. Begründung steht in der Spec

- [x] **R07** `!` **S** Die Vorrangstufe hat kein Kästchen „keine"
      → `4fach/official_message_form.php`, `app/message_priority.php`
      - [ ] Kein Kästchen mit dem Wert „keine"; ohne besondere Stufe ist nichts angewählt
      - [ ] `NV-09-VORRANGSTUFE` verlangt das bereits — **hier wird ein Verstoß behoben, keine Regel geändert**
      - **Prüfung:** Regeltest; leeren Vordruck öffnen

- [x] **R08** `·` **S** Der Aufnahmevermerk bekommt sein Datum
      → `4fach/4fachform.php`
      - [ ] Die Vorbelegung ist `date("Hi")` — nur Stunde und Minute. Sie bekommt die taktische Zeitgruppe mit Tag, wie Annahme- und Beförderungsvermerk
      - [ ] Das Feld bleibt frei korrigierbar
      - **Prüfung:** Eingang erfassen, Bild des Aufnahmevermerks

- [x] **R09** `·` **S** Das Infofähnchen liegt obenauf
      → `estab-ui.css`
      - [ ] Ein geöffnetes Fähnchen überlagert alle anderen Infopunkte und wird nicht angeschnitten
      - **Prüfung:** Messung im Browser — kein Infopunkt überdeckt das offene Fähnchen

- [x] **R10** `!` **S** Der Fernmelder darf den Absender ausfüllen
      → `4fach/4fachform.php`
      - [ ] Feld **Absender** ist für die Station Fernmelder freigegeben, optional
      - [ ] Was der Fernmelder ausgefüllt hat, prüft der LdF nur noch
      - **Prüfung:** Feldfreigabe **aller** Stationen — keine darf verlieren, was sie hatte

- [x] **R11** `·` **S** Zeichen nur beim Ausgang
      → `4fach/4fachform.php`
      - [ ] Feld **Zeichen** ist beim Eingang für den Fernmelder gesperrt; es füllt der Verfasser beim Ausgang
      - **Prüfung:** wie R10

- [ ] **R12** `·` **S** Abfassungszeit nur beim Ausgang
      → `4fach/4fachform.php`
      - [ ] Feld **Abfassungszeit** ebenso
      - [ ] Prüfen, ob `LW-NUR-BLAUER-TEIL` nachzuziehen ist
      - **Prüfung:** wie R10

> **Prüfpunkt D2** — Öffnet ein leerer Vordruck ohne Vorbelegung? Trägt der
> Aufnahmevermerk ein Datum? Sind Zeichen und Abfassungszeit beim Eingang
> gesperrt und beim Ausgang frei?

---

## P3 — Eine Rückweisung wird gesehen

- [ ] **R13** `!` **M** Die Meldung kommt ins Blickfeld
      → `estab-ui.css`, `4fach/official_message_form.php`, `app/ux_rules.php`
      - [ ] Eine Rückweisung liegt über dem Inhalt, mittig, ohne Scrollen sichtbar
      - [ ] `role="alert"`, Fokus beim Laden, bleibt bis sie weggenommen wird oder die Handlung gelingt
      - [ ] **Ohne JavaScript ebenso** — als Kasten am Dokumentanfang, der überlagert, nicht als Skriptfenster
      - [ ] Neue Regel `UX-RUECKWEISUNG-SICHTBAR`
      - **Prüfung:** Rückgabe ohne Grund auslösen; die Meldung ist ohne Scrollen im Bild; einmal ohne Skript

---

## P4 — Das Tabellenbauteil

- [ ] **R14** `!!` **M** Das Bauteil
      → `app/tabelle.php` (neu), `estab-ui.css`, `tests/php/ges_tabelle_bauteil.php`
      - [ ] Vertrag nach `docs/TABELLEN.md` Abschnitt 1
      - [ ] Sortierung je Spalte nach **Art**, nicht nach Text — Zahlen numerisch, Vorrangstufen nach Dringlichkeit
      - [ ] Spaltensuche, Volltextsuche, Filter, Blättern, aktive Marken, Ergebnisleiste, Leerzustand
      - [ ] Serverseitig vollständig; ohne JavaScript bedienbar
      - [ ] Regeln `GES-TABELLE-SORTIERUNG`, `GES-TABELLE-SUCHE`, `GES-TABELLE-BLAETTERN`, `GES-TABELLE-OHNE-SKRIPT`
      - **Prüfung:** an **einer** Tabelle fertig gebaut (R15), erst dann verallgemeinert
      - **Risiko:** ein Bauteil, das alles kann, kann nichts gut

- [ ] **R15** `!` **M** Nachweisung Ein- und Ausgang
      → `4fach/vordrucke.php` oder die Nachweisungsseite, `app/tabelle.php`
      - [ ] Die erste Tabelle aus dem Bauteil. Suche und Filter je Spalte, die heute ganz fehlen
      - **Prüfung:** Sortieren, Spaltensuche, Blättern — je einmal mit und ohne Skript

> **Prüfpunkt D3** — Trägt das Bauteil? Eine Tabelle daraus mit Sortierung,
> Spaltensuche und Blättern, und ohne JavaScript noch lesbar und bedienbar.

- [ ] **R16** `!!` **M** „Stab lesen" — und das Blättern reparieren
      → `4fach/liste.php`
      - [ ] Das Blättern funktioniert. **Erst den Fehler finden, dann umbauen** — ein Umbau, der einen Fehler mitnimmt, versteckt ihn
      - [ ] Die Liste kommt aus dem Bauteil
      - **Prüfung:** mehr Zeilen als eine Seite fasst, jede Seite erreichbar
      - **Risiko:** 2 346 Zeilen Bestand

- [ ] **R17** `·` **M** Anhänge
      → `4fach/anhang.php`
      - [ ] Tabelle aus dem Bauteil, durchsuchbar wie alle anderen
      - **Prüfung:** `ges_tabelle_bauteil.php`; Sortieren und Spaltensuche einmal mit, einmal ohne Skript; Bild
      - **Abhängigkeit:** R14

- [ ] **R18** `·` **M** Benutzerliste
      → `4fadm/users.php`
      - [ ] dito
      - **Prüfung:** wie R17
      - **Abhängigkeit:** R14

- [ ] **R19** `!` **M** Meldungsübersicht und Vordruckliste
      → `app/message_list_ui.php`, `4fach/vordrucke.php`
      - [ ] Beide auf das Bauteil. Die Meldungsübersicht hat heute sechs Bänder in eigenem Aufbau — sie werden die des Bauteils
      - [ ] `ges_filter.php` und `ges_tabelle.php` prüfen danach dieselbe Sache am Bauteil statt an der Seite
      - **Prüfung:** wie R17; zusätzlich bleiben `ges_tabelle.php` und `ges_filter.php` grün
      - **Abhängigkeit:** R14

- [ ] **R20** `!` **S** Jede Tabelle kommt aus dem Bauteil
      → `tests/php/ges_tabelle_bauteil.php`, `app/ux_rules.php`
      - [ ] `GES-TABELLE-EINHEITLICH`: Die Prüfung zählt die Tabellen der Anwendung; eine, die das Bauteil nicht benutzt, ist ein Befund
      - [ ] Die Ausnahmeliste ist leer
      - **Prüfung:** volle Suite

> **Prüfpunkt D4** — Kommt **jede** Tabelle aus dem Bauteil, oder gibt es noch
> eine, die ihr eigenes Markup schreibt?

---

## P5 — Handbuch und Abschluss

- [ ] **R21** `!` **M** Das Handbuch bekommt die Hülle
      → `handbuch/index.php`, `handbuch/handbuch.css`
      - [ ] Steht in der Hülle wie jede andere Seite
      - [ ] Seitenkopf ist eine Zeile nach `GES-SEITENKOPF` — kein Balken, der die halbe Höhe frisst
      - [ ] Das Inhaltsverzeichnis ist die bereichseigene Auswahl in der Menüspalte, keine zweite Spalte im Inhalt
      - [ ] `handbuch.css` benutzt die Marken; Fließtext auf 34rem
      - [ ] **Kein Satz wird umgeschrieben** — ein Test hält die Textmenge vor und nach der Umstellung gegeneinander
      - **Prüfung:** Wächter greifen auf `handbuch.css`; Höhenbudget; Bild bei 768 px

- [ ] **R22** `·` **S** Dokumentation nachziehen
      → `docs/GESTALTUNG.md`, `docs/TABELLEN.md`, `SPEC.md`, `tasks/rueckmeldungen-spec.md`
      - [ ] Geänderte Vorschriftenregeln vermerkt, mit Begründung
      - [ ] Neue Regeln in der Regelübersicht
      - [ ] Was offen blieb, steht mit Grund da
      - **Prüfung:** volle Suite grün; Registry grün; Fundstellen stimmen
      - **Abhängigkeit:** R21

> **Prüfpunkt D5** — Abschluss. Suite grün, alle Wächter scharf, Bilder
> angesehen, offene Punkte benannt.

---

## Parallelisierbar

**P1 ist sequenziell** (R02 setzt R01 voraus), **P2 vollständig parallel** —
sieben Aufgaben in getrennten Feldern des Vordrucks.

**In P4 ist R14 die Grundlage**; R15 gehört zu ihr. R16 bis R19 laufen danach
unabhängig, teilen sich aber die Ausnahmeliste des Wächters.
