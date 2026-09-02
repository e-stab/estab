# Umsetzungsplan — Gestaltung aus einem Guss

Grundlage ist `docs/GESTALTUNG.md`. Dieser Plan ordnet die dort beschriebene
Sollgestaltung in Aufgaben, Phasen und Prüfpunkte. Er beschreibt **wie**
gearbeitet wird; **was** gefordert ist, steht in der Gestaltungsspec und wird
hier nur referenziert.

Er ist der Nachfolger von `tasks/plan.md` (Meldewesen nach `SPEC.md`, 40 von 40
Aufgaben abgearbeitet). Jener Durchlauf bleibt unangetastet.

---

## 1. Ausgangslage

`estab-ui.css` hat 9 887 Zeilen und 1 375 Regelblöcke. Darin stehen

| Gegenstand | heute | Soll |
| --- | --- | --- |
| Hexfarben | 359 | 37 Marken über 28 Werte |
| Schriftgrößen | 61 | 7 Stufen |
| Angaben für `padding`, `gap`, `margin` | 240 | 7 Stufen |
| Eckradien | 15 | 4 |
| Höhen von Bedienelementen | 15 zwischen 1.55rem und 3.5rem | 2rem und 1.75rem |
| Custom Properties | 7, alle für die Zeitleiste | alle Werte der Spec |
| `!important` | 53 | nur Druck und `prefers-reduced-motion` |

Zwei davon sind **Mängel, keine Unordnung** und bestimmen deshalb die
Reihenfolge:

- Vier der acht Fokusringfarben erreichen auf hellem Grund nur 1.86 bis 2.48
  und verstoßen gegen WCAG 1.4.11.
- Die Ränder von Aufklappkästen und Feldgruppen (`#c5d1dd` 1.55, `#bdcad8`
  1.67) verstoßen gegen dieselbe Regel.

### Was schon da ist und getragen wird

Der Nachweisapparat muss nicht erfunden werden:

| Vorhanden | Rolle im Plan |
| --- | --- |
| `app/ux_rules.php` + `tests/php/ux_rule_registry.php` | Katalogmechanik samt erzwungener Testabdeckung |
| `tests/php/stylesheet_integrity_security.php` | liest das Stylesheet wie ein Parser — Vorlage für alle Marken-Tests |
| `tests/php/ux_form_contrast.php`, `list_contrast_security.php` | rechnen bereits WCAG-Kontraste über CSS-Farbpaare |
| `estab_colour_relative_luminance`, `estab_colour_contrast_ratio` in `4fach/tools.php` | Kontrastrechnung; die Tests ziehen die Funktionen heute per `eval` heraus |
| `tests/static/run.sh` mit `register_php` | Registrierung neuer Prüfungen ist eine Zeile |
| `tools/bedienpruefung/blick/aufnahme.mjs` | misst Überlauf und Wortbruch über vier Breiten, legt Bilder ab |

---

## 2. Architekturentscheidungen

### 2.1 Die Regeln kommen in den vorhandenen Bedienkatalog, mit eigener Herkunft

`tests/php/ux_rule_registry.php` erzwingt heute zweierlei, das dem
`GES-`-Vorschlag aus `docs/GESTALTUNG.md` Abschnitt 15 im Weg steht:

```php
preg_match('~\AUX(?:-[A-Z0-9ÄÖÜ]+)+\z~u', $id) === 1
$rule['origin'] === ESTAB_UX_ORIGIN_BETREIBER
```

**Entscheidung:** `app/ux_rules.php` bekommt eine zweite Herkunftskonstante
`ESTAB_UX_ORIGIN_GESTALTUNG`, die Registry eine Positivliste beider Herkünfte
und ein Muster, das `GES-` zulässt.

**Warum nicht ein dritter Katalog:** `SPEC.md` Abschnitt 10.1 trennt die
Kataloge nach **Autorität**, nicht nach Thema — die Dienstvorschrift bindet
von außen, eine Bedienanforderung ist die Entscheidung des Betreibers und darf
geändert werden. Die Gestaltungsspec ist ebenfalls die Entscheidung des
Betreibers, nur ein anderes Dokument. Sie gehört damit in denselben Katalog.
Die Kennung `GES-` hält die beiden Körper trotzdem auf einen Blick
auseinander, und der Vorschriftenkatalog bleibt unberührt — die Frage einer
Prüfung („was verlangt die Dienstvorschrift und wo steht das") behält ihre
Antwort.

### 2.2 Vertikal je Oberflächenbereich, nicht je Eigenschaft

Die Lückenliste der Spec (G1–G8) ist nach **Eigenschaft** geordnet: erst alle
Farben, dann alle Abstände. Das ist als Übersicht richtig und als Arbeitsplan
falsch. Wer eine Eigenschaft quer durch 1 375 Regelblöcke zieht, hinterlässt
nach jeder Stufe eine Anwendung, in der jeder Bereich halb umgestellt ist —
und genau das ist der Zustand, den „aus einem Guss" ausschließen soll.

**Dieser Plan schneidet nach Bereich.** Eine Aufgabe bringt **einen** Bereich
vollständig auf die Spec: Farben, Schrift, Abstände, Radien, Höhen, Fokus,
Höhenbudget — und den Test dazu. Nach jeder Aufgabe ist ein Bereich fertig und
bleibt es.

Zwei Ausnahmen, die querschnittlich bleiben müssen:

- **Die Marken selbst** (Phase 0). Sie sind das Fundament; ohne sie hat keine
  Scheibe etwas, worauf sie zeigen kann.
- **Fokus und Ränder** (Phase 1). Ein Fokusring, der in der halben Anwendung
  anders aussieht, ist schlechter als jeder der beiden Zustände. Das ist eine
  kleine Änderung — zwei Regeln und ein Suchen-und-Ersetzen — und behebt den
  WCAG-Verstoß, also steht sie vorn.

### 2.3 Die Migrationsgrenze — wie „aus einem Guss" von Tag eins erzwungen wird

Ein Test, der „kein Farbliteral außerhalb von `:root`" verlangt, kann erst nach
der letzten Aufgabe grün werden. Bis dahin prüft nichts, und in der Zwischenzeit
kommen neue Abweichungen dazu.

**Deshalb bekommt jede Prüfung eine schrumpfende Ausnahmeliste**, die
`tests/php/ges_migrationsgrenze.php` führt: die Auswählerpräfixe, die noch
nicht umgestellt sind. Die Prüfung ist ab Phase 0 scharf für alles, was **nicht**
in der Liste steht. Jede Aufgabe streicht ihre Präfixe daraus; die letzte
Aufgabe entfernt die Liste.

Das dreht die übliche Reihenfolge um: Statt am Ende zu prüfen, ob alles passt,
ist von Anfang an alles gesperrt, was schon umgestellt ist. Ein neuer Bereich,
der irgendwann dazukommt, steht nicht in der Liste und wird sofort geprüft.

### 2.4 Verhaltensneutral vor sichtbar

Die Reihenfolge innerhalb jeder Scheibe ist fest:

1. **Test schreiben**, der die Sollwerte für diesen Bereich verlangt. Er ist
   rot.
2. **Stylesheet umstellen**, bis er grün ist.
3. **Bild ansehen.** `tools/bedienpruefung/blick` über vier Breiten und vier
   Höhen; die Bilder werden angesehen, nicht nur die Befundzahl gelesen.
4. **Präfixe aus der Migrationsgrenze streichen.**

Schritt 3 ist nicht verhandelbar. Ein Test, der Zeichen zählt, sieht keine
zusammengefallene Kachel und keinen abgeschnittenen Knopf.

### 2.5 Lesbarkeit vor Dichte — innerhalb jeder Scheibe

`docs/GESTALTUNG.md` Abschnitt 14.5 begründet, warum G3 (Lesbarkeit) vor G4
(Dichte) steht: Die Dichtestufe soll auf lesbarem Satz aufsetzen. Das gilt
weiterhin, aber **innerhalb** einer Scheibe statt über den ganzen Bestand:
Erst die Farben und Größen des Bereichs heben, dann seine Abstände ziehen.

### 2.6 Zwei Musterseiten zuerst, dann nach Schema nachziehen

**Betreiberentscheidung.** Nicht alle Bereiche werden nacheinander umgestellt
und am Ende freigegeben. Stattdessen werden **zwei Seiten neu gebaut** und
freigegeben, und der Rest wird danach nach demselben Schema nachgezogen.

| Musterseite | Warum diese |
| --- | --- |
| **Nachrichtenvordruck** | Der Gegenstand der Anwendung, und der einzige Bereich mit eigenen Regeln (Maßstab, Papierbild). Was hier trägt, trägt in der Ausnahme. |
| **„Stab lesen"** (`4fach/liste.php`) | Die härteste Tabellenseite: 2 346 Zeilen übernommener Bestand mit GIF-Bildknöpfen, Statusgrafiken und Serifenschrift. Was hier trägt, trägt in jeder Liste. |

Das dreht die Reihenfolge gegenüber einer Bereichsmigration um, und zwar aus
einem guten Grund: **Das Schema entsteht am schwersten Fall, nicht am
leichtesten.** Wer mit den Werkzeugseiten anfinge, hätte nach fünf Aufgaben
ein Schema, das an der ersten übernommenen Liste zerbricht.

**Jede Musterseite endet mit einer Freigabe.** Zwischen den beiden Freigaben
und danach ist die Anwendung sichtbar gemischt — zwei Seiten neu, der Rest
alt. Das ist hingenommen und keine Übergangslösung, die versteckt werden
müsste: Die Hülle ist ab der ersten Musterseite überall neu, und damit ist der
Rahmen einheitlich, auch wo der Inhalt noch alt ist.

**Das Schema wird aufgeschrieben.** Prüfpunkte C2 und C3 sind erst bestanden,
wenn der Weg, auf dem die Musterseite entstanden ist, als nachvollziehbare
Abfolge in diesem Plan steht — Reihenfolge der Griffe, welche Tests zuerst,
woran man merkt, dass eine Seite fertig ist. Ohne das ist „nach demselben
Schema" eine Absichtserklärung und keine Anweisung.

---

## 3. Abhängigkeitsgraph

```
P0  G01  Katalogapparat: UX-Registry nimmt GES- an
      │
    G02  Kontrastrechnung als Testhilfe herausziehen
      │
    G03  :root-Marken + Migrationsgrenze         ← verhaltensneutral
      │
    G04  Die vier Waechter                       ← ab hier ist Neues gesperrt
      │
P1  G05  Fokus und Raender querschnittlich       ← behebt WCAG 1.4.11
    G06  UX-KONTRAST auf zwei Stufen (4.5 Untergrenze / 7 Sollwert)
      │
P2  G07  Huelle, Menue, Cockpit        ┐
    G08  Umfeld des Vordrucks          ├─ MUSTERSEITE 1 → FREIGABE
    G09  Der Vordruck: Massstab        ┘
      │
P3  G10  "Stab lesen" als Tabelle      ┐
    G11  Suche und Filter              ├─ MUSTERSEITE 2 → FREIGABE
    G12  Bildknoepfe und Statusgrafik  ┘
      │
P4  G13  Meldungsuebersicht        ┐
    G14  Werkzeug- und Verwaltung  │  nach dem Schema aus P2 und P3,
    G15  Infosammlung, Handbuch    ├─ untereinander unabhaengig
    G16  Zeitleiste und Anlagen    │
    G17  Uebrige uebernommene Seiten┘
      │
P5  G18  Hoehenbudget durchsetzen
    G19  Migrationsgrenze entfernen, !important raeumen
    G20  Bildpruefung erweitern
    G21  Dokumentation nachziehen
```

---

## 4. Was womit geprüft wird

| Regel | Prüfung | Art |
| --- | --- | --- |
| `GES-MARKEN` | `tests/php/ges_marken.php` | liest CSS, zählt Literale außerhalb `:root` |
| `GES-SCHRIFTSKALA` | `tests/php/ges_schriftskala.php` | alle `font-size` gegen die 7 Stufen |
| `GES-SCHRIFTSTAERKE` | dieselbe Datei | alle `font-weight` gegen 400/600/700 |
| `GES-ABSTANDSSKALA` | `tests/php/ges_abstandsskala.php` | `padding`, `gap`, `margin`, `border-radius` |
| `GES-KONTRAST-TEXT` | `tests/php/ges_kontrast.php` | jede Tinte × jeden zulässigen Grund ≥ 7:1 |
| `GES-KONTRAST-RAND` | dieselbe Datei | jeder Bedienrand × jeden Grund ≥ 3:1 |
| `GES-KEINE-BLASSE-SCHRIFT` | dieselbe Datei | kein `opacity` an textführenden Auswählern |
| `GES-FOKUS-DOPPELRING` | `tests/php/ges_fokus.php` | jeder `:focus-visible` trägt beide Ringe |
| `GES-KNOPFMASSE` | `tests/php/ges_bedienmasse.php` | `min-height` an Bedienelementen ≥ 1.75rem |
| `GES-INHALT-BLEIBT-GROSS` | `tests/php/ges_bedienmasse.php` | Inhaltsfelder auf `--schrift-4` |
| `GES-GESPERRT-LESBAR` | `tests/php/ges_kontrast.php` | `readonly` ohne gedämpfte Tinte |
| `GES-VORDRUCK-MASSSTAB` | `tests/php/ges_vordruck.php` | `zoom`-Ausdruck, Ober- und Untergrenze |
| `GES-VORDRUCK-LESBAR` | `tests/php/ges_vordruck.php` | tragende Angaben ≥ 0.875rem |
| `GES-SEITENKOPF` | `tests/php/ges_seitenaufbau.php` | genau ein `h1`, Bereichsmarke, keine Erklärsätze |
| `GES-BAENDER` | `tests/php/ges_seitenaufbau.php` | Reihenfolge der Bänder im Markup |
| `GES-TABELLE` | `tests/php/ges_tabelle.php` | klebender Kopf, feste Breiten, zwei Zeilen, Aktionsspalte |
| `GES-FILTER-BAENDER` | `tests/php/ges_filter.php` | sechs Bänder, ein Formular, kein Auto-Absenden |
| `GES-HOEHENBUDGET` | `blick/aufnahme.mjs` | gemessene Bandhöhen gegen Abschnitt 4.1 |
| `GES-KEIN-UEBERLAUF` | `blick/aufnahme.mjs` | vorhanden, wird auf vier Höhen erweitert |
| `GES-OHNE-FARBE` | `blick/aufnahme.mjs` | Graustufenvergleich der Zustände |
| `GES-DRUCK` | `tests/php/ges_druck.php` | Druckblock: keine dunklen Spalten, fester Maßstab |

**18 der 21 Regeln sind ohne Browser nachweisbar** — aus dem
Stylesheet oder aus dem gerenderten Markup — und laufen in
`tests/static/run.sh` mit. Nur `GES-HOEHENBUDGET`, `GES-KEIN-UEBERLAUF`, `GES-OHNE-FARBE`
brauchen einen Browser und laufen über `blick`.

Das ist der Grund, warum die Gestaltung überhaupt festsetzbar ist: Der
weitaus größte Teil steht als Zahl im Stylesheet und muss nicht angesehen,
sondern nur gelesen werden.

---

## 5. Prüfpunkte

| Punkt | Nach | Frage |
| --- | --- | --- |
| **C0** | G04 | Trägt der Apparat? Ein absichtlich in einen umgestellten Bereich gesetztes Literal muss die Suite rot machen. Findet sie es nicht, ist die Migrationsgrenze wirkungslos und alles Weitere ungeprüft. |
| **C1** | G06 | Sieht der Fokus auf hellem **und** dunklem Grund gleich aus, auch auf dem blauen Vordruck und dem roten Gefahrknopf? Hält der Bestand weiterhin die 4.5:1-Untergrenze? |
| **C2** | G09 | **Musterseite 1 und Freigabe.** Bedienprüfung mit drei Personen ohne Anwendungskenntnis, je ein vollständiger Nachrichtenlauf. Maßstab 0.99 bei 1366 px, nichts abgeschnitten, nichts gestaucht. **Und: Ist das Schema aufgeschrieben?** |
| **C3** | G12 | **Musterseite 2 und Freigabe.** Bedienprüfung mit drei **anderen** Personen. Passen mehr Zeilen auf den Schirm als vorher, ohne dass etwas kleiner geworden ist? Gemessen, nicht geschätzt. **Und: Ist das Schema um den Listenfall ergänzt?** |
| **C4** | G17 | Alle Bereiche nachgezogen. Ist irgendwo ein Bereich anders geworden als das Schema es vorgibt? |
| **C5** | G21 | Abschluss: Migrationsgrenze entfernt, `GES-MARKEN` zählt null Literale, alle Regeln im Katalog, jede mit Test, `blick` ohne Befund über vier Breiten und vier Höhen. |

**C2 und C3 sind Freigaben, keine Zwischenstände.** Was dort hinausgeht,
steht im Einsatz. Die Bedienprüfung nach `docs/BEDIENPRUEFUNG.md` ist an
beiden Punkten Pflicht — mit je drei Personen, und wer die eine mitgemacht
hat, ist für die andere ungeeignet: Beim zweiten Mal misst man Erinnerung,
nicht Bedienbarkeit.

### 5.1 Das Schema — entsteht an C2, wird an C3 geprüft

Der Kern der Betreiberentscheidung ist, dass P4 kein Nachdenken mehr
verlangt. Dafür muss an C2 stehen, **wie** eine Seite umgestellt wird. Der
Platz dafür ist dieser Abschnitt; er ist bis C2 leer und wird dort gefüllt.

Was hineingehört, mindestens:

- Die Reihenfolge der Griffe innerhalb einer Seite — welche Eigenschaft zuerst.
- Welcher Test vor welcher Änderung geschrieben wird.
- Wie eine übernommene Seite aus ihrem eigenen Farb- und Schriftraum geholt
  wird, ohne dass ihr Verhalten sich ändert.
- Woran man merkt, dass eine Seite fertig ist — die Liste der Prüfungen, die
  grün sein müssen, und was auf den Bildern zu sehen sein muss.
- Was **nicht** mitgemacht wird, weil es die Seite nicht betrifft.

An C3 wird das Schema am Listenfall geprüft und ergänzt, nicht neu erfunden.
Trägt es dort nicht, ist das ein Befund über das Schema und nicht über die
Liste.

---

## 6. Risiken

| Risiko | Wirkung | Gegenmaßnahme |
| --- | --- | --- |
| **Kein lokales `php`/`node`** | jede Prüfung läuft über podman | `PHP_BIN`-Hülle nach `tasks/plan.md`; Repo unter identischem absolutem Pfad einhängen |
| **`opcache.validate_timestamps => Off` im Prüfabbild** | eingespielte PHP-Datei wirkt erst nach Neustart des Containers; CSS wirkt sofort | Nach jeder PHP-Änderung Container neu starten. Wer das übersieht, prüft gegen alten Code |
| **`nohup` zerstört die Signalprüfung** | `offline_images.sh` schlägt reproduzierbar fehl und sieht wie ein Codefehler aus | nie mit `nohup` starten |
| **Schmalere Menüspalte bricht „Führungsstelle"** | Wortbruch ohne Trennstrich, sichtbarer Mangel | G07 ist erst fertig, wenn `blick` über vier Breiten keinen Wortbruch meldet. Bricht es, wird die Beschriftung gekürzt — nach den Grenzen aus Abschnitt 7, Entscheidung 3 |
| **240 Abstandsangaben mechanisch ziehen** | Tests bleiben grün, das Bild fällt auseinander | Schritt 3 jeder Scheibe: Bilder ansehen, nicht Befundzahl lesen |
| **`zoom` und Container-Anfragen** | Maßstabsregel des Vordrucks hängt an beidem | beides ist im Bestand bereits in Gebrauch; G09 prüft die gerenderte Breite, nicht die Deklaration |
| **Die Anwendung sieht danach anders aus** | Wiedererkennung im Einsatz | C2 und C3 sind echte Bedienprüfungen mit je drei Personen **vor** der Freigabe, kein Häkchen |
| **Scheibe wird zu groß** | Aufgabe bleibt liegen, halb umgestellter Bereich | Musterseite 2 ist bereits dreigeteilt (G10–G12). Reißt eine andere die 5-Dateien-Grenze, wird sie geteilt statt gestreckt |
| **`4fach/liste.php` hat 2 346 Zeilen** | Musterseite 2 ist die größte Einzelaufgabe des Plans | in drei Aufgaben geschnitten: Tabelle, Filter, Bildknöpfe. Freigabefähig ist die Seite erst nach allen dreien |
| **Zwei Freigaben, dazwischen gemischtes Bild** | halb neu, halb alt im Einsatz | die Hülle ist ab G07 überall neu — der Rahmen ist einheitlich, auch wo der Inhalt noch alt ist |
| **Das Schema bleibt ungeschrieben** | P4 wird zu fünf Einzelentscheidungen statt zu Fleißarbeit | C2 und C3 sind ohne geschriebenes Schema nicht bestanden (Abschnitt 5.1) |

---

## 7. Getroffene Entscheidungen

Die vier offenen Fragen sind vom Betreiber beantwortet. Sie stehen hier, weil
jede von ihnen den Plan geformt hat.

**1. Katalog — Erweiterung, kein dritter Katalog.**
`app/ux_rules.php` bekommt die zweite Herkunft, die Registry das erweiterte
Kennungsmuster (Abschnitt 2.1). Das ist G01.

**2. Freigabe — zwei Musterseiten, dann nach Schema.**
Vordruckseite und „Stab lesen" werden neu gebaut und einzeln freigegeben; der
Rest wird danach nach demselben Schema nachgezogen (Abschnitt 2.6). Das
bestimmt den gesamten Phasenschnitt.

**3. Menüspalte — Beschriftungen kürzen, aber nicht abgeschnitten wirken.**
Meldet `blick` bei 13.5rem Wortbruch, wird die Beschriftung gekürzt, nicht die
Spalte verbreitert. Bedingung: **Das Ergebnis darf nicht wie abgeschnitten
aussehen.** Damit sind ausgeschlossen: Auslassungspunkte, `text-overflow:
ellipsis`, harte Abkürzungen mit Punkt („Fü.stelle"), und jedes Kürzen durch
CSS statt durch Wortwahl. Zulässig ist allein ein **anderes, kürzeres Wort**,
das für sich gelesen vollständig ist.

Dabei ist eine Grenze zu beachten: `UX-SPRACHE-VORSCHRIFT` verlangt die
Begriffe der Vorschrift für **Feldbeschriftungen und Schaltflächen**. Menüziele
sind Bereichsnamen und fallen nicht darunter — ein Bereich darf „Meldungen"
heißen, wo das Feld „Nachrichteninhalt" heißen muss. Wo ein Menüziel dennoch
einen Vorschriftbegriff trägt, wird nicht gekürzt, sondern die zweispaltige
Kachelreihe für diesen einen Eintrag auf eine Spalte gesetzt.

**4. Kontrast — `UX-KONTRAST` wird hochgezogen, zweistufig.**
4.5:1 ist das absolute Minimum, 7:1 der Sollwert, und gearbeitet wird am
Sollwert. Umgesetzt als **zwei Stufen in einer Regel** (G06):

| Stufe | Gilt | Wirkung bei Verstoß |
| --- | --- | --- |
| 4.5:1 | überall, ausnahmslos, ab sofort | Suite rot |
| 7:1 | überall außerhalb der Migrationsgrenze | Suite rot |

Die Zweistufigkeit ist kein Aufweichen, sondern das, was die Umstellung
überhaupt durchführbar macht: Ohne sie wäre die Suite vom Tag der
Regeländerung an rot, bis der letzte Bereich umgestellt ist — und eine rote
Suite über Monate prüft nichts mehr.

**Ausnahmen nur für vorgeschriebene Farben.** Wo eine Farbe der
Dienstvorschrift 7:1 nicht zulässt — die Durchschriftenfarben des Vordrucks
sind der Kandidat —, gilt die Vorschrift (Abschnitt 1.3 der Gestaltungsspec),
die Paarung wird namentlich in `docs/GESTALTUNG.md` vermerkt und darf 4.5:1
nicht unterschreiten. Eine stillschweigende Ausnahme gibt es nicht.

---

## 8. Aufgaben

Die Liste steht in `tasks/gestaltung-todo.md`. Abnahmekriterien und
Prüfschritte je Aufgabe stehen dort.
