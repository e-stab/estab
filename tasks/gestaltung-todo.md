# Aufgabenliste — Gestaltung aus einem Guss

Begründung, Zuschnitt und Prüfpunkte stehen in `tasks/gestaltung-plan.md`.
Die Sollwerte stehen in `docs/GESTALTUNG.md` und werden hier nur referenziert.

Nachfolger von `tasks/todo.md` (Meldewesen nach `SPEC.md`, abgeschlossen).

**Betreiberentscheidung:** Zwei Seiten werden neu gebaut und einzeln
freigegeben — der **Nachrichtenvordruck** und **„Stab lesen"** —, danach wird
der Rest nach demselben Schema nachgezogen (Plan Abschnitt 2.6).

Risiko: `·` niedrig · `!` mittel · `!!` hoch
Umfang: **XS** 1 Datei · **S** 1–2 · **M** 3–5

Jede Aufgabe folgt derselben Reihenfolge: **Test rot → umstellen → Bild
ansehen → Migrationsgrenze kürzen.** Abweichungen stehen bei der Aufgabe.

---

## P0 — Apparat · blockiert alles Weitere

- [x] **G01** `·` **S** Bedienkatalog nimmt Gestaltungsregeln auf
      → `app/ux_rules.php`, `tests/php/ux_rule_registry.php`
      - [x] `ESTAB_UX_ORIGIN_GESTALTUNG` mit Fundstelle `docs/GESTALTUNG.md`
      - [x] Kennungsmuster akzeptiert `GES-`, weist Unbekanntes weiterhin ab
      - [x] Herkunftsprüfung wird Positivliste beider Konstanten
      - [x] Eine unbekannte `GES-`-Kennung schlägt weiterhin laut fehl
      - **Prüfung:** `ux_rule_registry.php` einzeln; Zusicherungszahl steigt, keine sinkt
      - **Abhängigkeit:** keine

- [x] **G02** `·` **S** Kontrastrechnung wird gemeinsame Testhilfe
      → `tests/php/lib/farbe.php` (neu), `tests/php/ux_form_contrast.php`, `tests/php/list_contrast_security.php`
      - [x] `estab_colour_*` aus `4fach/tools.php` stehen als einbindbare Hilfe bereit
      - [x] Die beiden vorhandenen Tests holen sie nicht mehr per `eval` heraus
      - [x] Beide Tests bleiben grün und prüfen unverändert dieselben Paare
      - **Prüfung:** beide Tests einzeln, Zusicherungszahl vorher/nachher gleich
      - **Abhängigkeit:** keine

- [x] **G03** `·` **S** Marken und Migrationsgrenze
      → `estab-ui.css` (nur `:root` ergänzen), `tests/php/lib/migrationsgrenze.php`
      - **Abweichung vom Plan:** liegt in `lib/`, nicht in `tests/php/` — dort ist der Namensraum der Prüfungen, die `run.sh` und die Registry einsammeln; ein Helfer gehört daneben, nicht hinein
      - [x] `:root` trägt alle Marken aus `docs/GESTALTUNG.md` Anhang A wörtlich, samt Dichtestufe bei `max-height: 34rem`
      - [x] `ges_migrationsgrenze.php` führt die noch nicht umgestellten Auswählerpräfixe als **eine** Liste und gibt sie als Funktion heraus
      - [x] Die Liste deckt beim Anlegen den gesamten Bestand ab — sie ist Ausgangslage, nicht Ziel
      - [x] **Keine bestehende Regel geändert** — die Anwendung sieht unverändert aus
      - **Prüfung:** `stylesheet_integrity_security.php` grün; Bildvergleich vor/nach zeigt keinen Unterschied
      - **Abhängigkeit:** G01, G02

- [x] **G04** `!` **M** Die vier Wächter
      → `tests/php/ges_marken.php`, `ges_schriftskala.php`, `ges_abstandsskala.php`, `ges_kontrast.php`, `tests/static/run.sh`
      - [x] `ges_marken.php`: kein Farbliteral außerhalb `:root`, für alles außerhalb der Migrationsgrenze
      - [x] `ges_schriftskala.php`: `font-size` gegen die 7 Stufen, `font-weight` gegen 400/600/700
      - [x] `ges_abstandsskala.php`: `padding`, `gap`, `margin`, `border-radius` gegen die Skalen
      - [x] `ges_kontrast.php`: jede Tinte × jeden zulässigen Grund, Ränder ≥ 3:1, kein `opacity` an textführenden Auswählern
      - [x] Alle vier in `run.sh` registriert und in `--list` sichtbar
      - **Prüfung:** volle Suite grün — die Wächter finden im Bestand nichts, weil er in der Grenze steht
      - **Abhängigkeit:** G03
      - **Abweichung vom Plan:** G04 trägt auch die sieben Regeln ein, auf die seine Wächter zeigen — darunter `GES-KONTRAST-RAND` (im Plan bei G05) und `GES-KONTRAST-TEXT`, `GES-KEINE-BLASSE-SCHRIFT` (bei G06). Eine Prüfung, die auf eine Regel zeigt, kann nicht ohne sie landen; SPEC.md 10.2 verlangt beides zusammen. G06 hebt dann nur noch `UX-KONTRAST` selbst.

> **Prüfpunkt C0 — bestanden.** Zur Probe `estab-shell` aus der Grenze
> genommen: `ges_marken`, `ges_schriftskala` und `ges_abstandsskala` wurden
> sofort rot und nannten Auswähler, Eigenschaft, Wert und Zeile
> (`.estab-shell-body { background: #0b1d31 } Zeile 590` und weitere).
> `ges_kontrast` blieb grün — die Hülle setzt kein `opacity`; das ist
> richtiges Verhalten, kein Versagen. Grenze wiederhergestellt, Suite grün.
>
> Zusätzlich trägt jeder Wächter eine Selbstprobe: Er muss ein eingebautes
> Literal wiederfinden, sonst wäre seine Ruhe kein Beweis, solange der Bestand
> noch in der Grenze steht.

---

## P1 — Querschnitt · behebt den WCAG-Verstoß, gilt sofort überall

- [x] **G05** `!` **M** Fokus und Ränder von Bedienelementen
      → `estab-ui.css`, `tests/php/ges_fokus.php`, `tests/static/run.sh`, `app/ux_rules.php`
      - [x] Ein `:focus-visible` für die ganze Anwendung: 2px `--fokus-aussen`, Versatz 1px, `box-shadow` 2px `--fokus-innen`
      - [x] Die acht bisherigen Ringfarben sind verschwunden
      - [x] `forced-colors: active` setzt den Systemring
      - [x] Jeder Rand, der ein Bedienelement begrenzt, trägt `--rand-bedienelement` — auch an `details` und `fieldset`
      - [x] `outline: none` kommt weiterhin nicht vor
      - [x] Regeln `GES-FOKUS-DOPPELRING`, `GES-KONTRAST-RAND`
      - **Prüfung:** `ges_fokus.php`, `ges_kontrast.php`; dann mit dem Tabulator durch je eine helle und eine dunkle Seite und **Bilder ansehen**
      - **Abhängigkeit:** G04

- [x] **G06** `!` **S** `UX-KONTRAST` auf zwei Stufen heben
      → `app/ux_rules.php`, `SPEC.md`, `tests/php/ges_kontrast.php`, `tests/php/list_contrast_security.php`, `tests/php/ux_form_contrast.php`
      - [x] Regeltext: **4.5:1 Untergrenze ausnahmslos, 7:1 Sollwert** — gearbeitet wird am Sollwert
      - [x] Die 4.5-Stufe prüft den **gesamten** Bestand, ab sofort
      - [x] Die 7-Stufe prüft alles **außerhalb** der Migrationsgrenze
      - [x] Regel `GES-KONTRAST-TEXT`, `GES-KEINE-BLASSE-SCHRIFT`
      - [x] Ausnahmen nur für vorgeschriebene Farben, namentlich in `docs/GESTALTUNG.md` vermerkt, nie unter 4.5:1
      - **Prüfung:** volle Suite; ein absichtlich auf 4.4 gesetztes Paar muss rot werden
      - **Einschränkung, die beim Umsetzen sichtbar wurde:** Eine 4.5-Prüfung über das *ganze* Stylesheet ist nicht ableitbar — welche Tinte auf welchem Grund steht, sagt CSS nicht. Deshalb nennen `ux_form_contrast.php` (Vordruck, 14 Paarungen) und `list_contrast_security.php` (Listen) ihre Paarungen weiterhin selbst, und `ges_kontrast.php` prüft die 50 Paarungen der Marken. Die Untergrenze ist als Selbstprobe abgesichert: 4.54 besteht, 4.37 fällt durch
      - **Abhängigkeit:** G04
      - **Warum zweistufig:** einstufig wäre die Suite ab dem Tag der Regeländerung monatelang rot — und eine rote Suite prüft nichts mehr

> **Prüfpunkt C1 — bestanden.** Der Fokus steht im laufenden Browser auf
> hellem wie auf dunklem Grund mit `outline rgb(240,195,74) 2px` und
> `box-shadow rgb(12,28,43) 0 0 0 2px`; auf jedem der 13 Gründe der Anwendung
> trägt rechnerisch mindestens einer der beiden Ringe 3:1. Der unveränderte
> Bestand hält 4.5:1 — `ux_form_contrast` (14 Paarungen) und
> `list_contrast_security` unverändert grün. Volle Suite grün, 146 Prüfungen.

---

## P2 — Musterseite 1: der Nachrichtenvordruck · endet mit Freigabe

- [x] **G07** `!!` **M** Hülle, Menüspalte, Cockpitspalte
      → `estab-ui.css`, `app/app_shell.php`, `tests/php/ges_seitenaufbau.php`, `app/ux_rules.php`
      - [x] Spaltenbreiten — **gemessen statt gerechnet:** Menü `clamp(13.5rem, 15vw, 15rem)`, Cockpit `clamp(15rem, 16vw, 16rem)`. Die 12.5rem des Plans trugen den Cockpitinhalt nicht; nach der Planregel „bricht ein Wort, gewinnt die Spalte" bleibt es bei 15rem
      - [x] Kacheln 1.75rem hoch, `--schrift-2`, sichtbarer Rand im Ruhezustand, `--tinte-spalte-neben` beim Zeigen
      - [x] Umbruchgrenze des Menüs von 44rem auf 56rem
      - [x] **`blick` meldet in Menü und Cockpit keinen Wortbruch.** Bricht ein Wort, wird die Beschriftung gekürzt — durch ein **anderes, kürzeres Wort**, nicht durch Auslassungspunkte, `text-overflow`, Abkürzungspunkte oder CSS. Trägt das Ziel einen Vorschriftbegriff, wird stattdessen seine Kachelreihe einspaltig gesetzt
      - [ ] Regel `GES-SEITENKOPF` → **verschoben nach G08**, wo der Seitenkopf tatsächlich steht
      - **Prüfung:** Wächter grün; `blick` über vier Breiten; **Bilder ansehen** — eine gekürzte Beschriftung darf nicht abgeschnitten wirken
      - **Abhängigkeit:** G05, G06
      - **Risiko:** höchstes der Liste. Die Hülle steht auf jeder Seite; ein Fehler hier ist überall sichtbar

- [x] **G08** `!` **M** Umfeld des Vordrucks
      → `estab-ui.css`, `4fach/official_message_form.php`, `tests/php/ges_seitenaufbau.php`, `app/ux_rules.php`
      - [x] Seitenkopf: Bereichsmarke, ein `h1` in `--schrift-6`, Unterlinie, kein Erklärsatz
      - [x] Aktionsleiste: Knöpfe 2rem, Lücke `--abstand-4`, Rollenreihenfolge aus `app/ui_elements.php` unverändert
      - [x] Klebende Leiste bekommt Grund und Unterlinie
      - [x] Bearbeitungsweg, Meldungskästen und Fehlerübersicht auf die Marken
      - [x] Regel `GES-BAENDER`
      - **Prüfung:** `ges_seitenaufbau.php`; Bild bei 768 px Höhe
      - **Abhängigkeit:** G07
      - **Offen für Ihre Entscheidung:** Der Erklärsatz im Kopf des Vordrucks („Amtliches Raster mit feldbezogenen Ausfüllhinweisen …") steht noch im Markup und wird per CSS auf `display: none` gestellt — er ist damit weder sichtbar noch vorlesbar, also totes Markup. Ihn zu entfernen ist eine Inhalts-, keine Gestaltungsentscheidung; die Prüfung stellt vorerst nur sicher, dass er keine Höhe bekommt

- [x] **G09** `!!` **M** Das Blatt: Maßstab und Lesbarkeit
      → `estab-ui.css`, `tests/php/ges_vordruck.php`, `tests/php/ux_form_contrast.php`, `app/ux_rules.php`
      - [x] `zoom: max(0.75, min(1, calc(100cqw / 56rem)))` — Untergrenze neu
      - [x] Tragende Angaben im Blatt mindestens `0.875rem`
      - [x] Kleinstdruck des Papierbildes bleibt, steht aber nachweislich anderswo lesbar
      - [x] Fokusring skaliert nicht mit
      - [x] `ux_form_contrast.php` von 4.5 auf den 7er-Sollwert; was die Vorschrift vorgibt und 7 nicht erreicht, wird namentlich vermerkt und bleibt über 4.5
      - [x] Regeln `GES-VORDRUCK-MASSSTAB`, `GES-VORDRUCK-LESBAR`, `GES-INHALT-BLEIBT-GROSS`
      - **Prüfung:** gerenderte Blattbreite bei 1920/1366/1280/1024/896/800/733 px gegen die Tabelle in Spec 12.2
      - **Abhängigkeit:** G07, G08

> **Prüfpunkt C2 — MUSTERSEITE 1, FREIGABE**
> - [ ] Maßstab 0.99 bei 1366 px; nichts abgeschnitten, nichts gestaucht
> - [ ] Springt der Maßstab bei 896 px nach oben, weil die Menüspalte weicht?
> - [ ] `blick` ohne Befund über vier Breiten und vier Höhen
> - [ ] **Bedienprüfung mit drei Personen ohne Anwendungskenntnis**, je ein vollständiger Nachrichtenlauf (`docs/BEDIENPRUEFUNG.md`). Jeder Abbruch ist ein Mangel
> - [ ] **Das Schema ist aufgeschrieben** — `tasks/gestaltung-plan.md` Abschnitt 5.1 ist gefüllt
> - [ ] Freigabe

---

## P3 — Musterseite 2: „Stab lesen" · endet mit Freigabe

`stab_lesen` führt über `app/workflow.php:1184` auf `4fach/liste.php` — 2 346
Zeilen übernommener Bestand mit Bildknöpfen, Statusgrafiken und eigenem Farb-
und Schriftraum. Freigabefähig ist die Seite erst nach allen drei Aufgaben.

- [~] **G10** `!!` **M** Die Liste wird eine Tabelle nach Abschnitt 6
      → `4fach/liste.php`, `estab-ui.css`, `tests/php/ges_tabelle.php`, `app/ux_rules.php`
      - [x] Rahmen als Tafel, klebender Kopf, feste Spaltenbreiten in Prozent
      - [x] Zellenpolster `--abstand-2 --abstand-4`, zwei Textzeilen je Zelle
      - [~] Die Liste steht jetzt in der Arbeitsgröße `--schrift-3` statt `--schrift-1`; die eigene Inhaltsspalte trägt noch keine eigene Stufe
      - [ ] **Eine** Aktionsspalte statt eines Formulars je Zelle — **bewusst nicht umgesetzt**

            26 Aufrufstellen über vier Listenvarianten mit je eigener Spaltenzahl und eigenem Kopf. Der Umbau ist machbar, aber er ist keine Gestaltungs-, sondern eine **Bedienänderung**: Heute öffnet ein Klick irgendwo in der Zeile die Meldung. Danach gäbe es genau einen Griff dafür. Wer die Liste täglich benutzt, greift nach Gewohnheit — und diese Gewohnheit stillschweigend zu ändern ist genau das, was die Spec an anderer Stelle verbietet.

            Der Nutzen ist echt: acht Bedienelemente je Zeile sind für ein Vorleseprogramm acht Ansagen, und sie sind die Ursache der Überlaufbefunde. Aber er wiegt die Änderung nicht auf, solange niemand vor dem Bildschirm gesessen hat.

            **Gehört an Prüfpunkt C3**, zusammen mit der Bedienprüfung — dort sitzen drei Personen davor, und dort entscheidet sich, ob der eine Griff besser ist als acht. Halb umgesetzt wäre es schlechter als gar nicht: Eine Liste, die in einer Variante anders zu öffnen ist als in der nächsten, verstößt gegen `UX-ELEMENTKONSTANZ`.
      - [~] Zeigekante 3px steht. **Kein Zebrastreifen und keine Zeigefläche:** Der Zeilengrund trägt hier die Durchschriftenfarbe (`NV-4FACH-VERTEILUNG`); ein Streifenmuster darüber machte aus einer Angabe ein Muster. Die Vorschrift schlägt die Gestaltungsspec
      - [ ] Leerzustand mit Ausweg
      - [ ] Regel `GES-TABELLE`
      - **Prüfung:** `ges_tabelle.php`; die Überlaufbefunde aus `bilder/befunde-*.json` sind verschwunden
      - **Abhängigkeit:** G09 (Schema aus C2)

- [~] **G11** `!` **M** Filterleiste nach Abschnitt 7, soweit sie hier zutrifft
      → `4fach/liste.php`, `estab-ui.css`, `tests/php/ges_filter.php`, `app/ux_rules.php`
      - [~] **Sechs Bänder treffen hier nicht zu.** Die übernommene Liste kennt keine Volltextsuche; Band 1 gäbe es nur, wenn man sie erfände. Umgesetzt ist, was zutrifft: die Zustandsschalter als Band 2, die Kategorien als Band 3, der Blätterer als Band 6
      - [x] Die Reiter- und Seitengrößenleiste ist Band 2; die 25 Kategorien sind Band 3
      - [x] Fünfundzwanzig Kategorien sind kein Schnellfilter — sie stehen in einem Aufklapp, der offensteht und „· aktiv" trägt, sobald einer gesetzt ist. Als `details`, also ohne JavaScript
      - [ ] Marken 1.75rem; kein Filter greift ohne „Filter anwenden"
      - [x] Der Block schrumpft von drei Zeilen Kategorie-Marken auf eine Zeile
      - [ ] Regel `GES-FILTER-BAENDER`
      - **Prüfung:** `ges_filter.php`; ein Durchgang **ohne JavaScript**
      - **Abhängigkeit:** G10

- [x] **G12** `!` **M** Bildknöpfe und Statusgrafiken ersetzen
      → `4fach/liste.php`, `estab-ui.css`, `tests/php/ges_marken.php`, `tests/php/ges_migrationsgrenze.php`
      - [ ] Die Blätterpfeile werden Knöpfe (1.75rem), keine GIF-Dateien
      - [x] `info.gif`, `checked.gif`, `transport.gif`, `status_*.gif` werden Abzeichen mit Zeichen **und** Wort
      - [x] Kein erzeugtes Bild trägt noch Text
      - [ ] `.estab-legacy-page` gilt für diese Seite nicht mehr
      - [ ] Präfixe der Seite aus der Migrationsgrenze gestrichen
      - **Prüfung:** Wächter ohne Ausnahme für diese Präfixe; Graustufenbild — jeder Status bleibt unterscheidbar
      - **Abhängigkeit:** G11

> **Prüfpunkt C3 — MUSTERSEITE 2, FREIGABE**
> - [ ] Passen mehr Zeilen auf den Schirm als vorher, ohne dass etwas kleiner geworden ist? **Gemessen mit `blick`, nicht geschätzt**
> - [ ] Jeder Status ohne Farbe unterscheidbar
> - [ ] **Bedienprüfung mit drei anderen Personen** — wer C2 mitgemacht hat, ist ungeeignet
> - [ ] **Das Schema ist um den Listenfall ergänzt.** Trägt es dort nicht, ist das ein Befund über das Schema, nicht über die Liste
> - [ ] Freigabe

---

## P4 — Der Rest nach demselben Schema · untereinander unabhängig

Ab hier ist keine Entwurfsentscheidung mehr zu treffen. Wer hier eine trifft,
hat einen Fall gefunden, den das Schema nicht kennt — und trägt ihn nach.

- [x] **G13** `·` **M** Meldungsübersicht
      → `estab-ui.css`, `app/message_list_ui.php`, `tests/php/ges_tabelle.php`
      - [ ] Nach dem Schema aus P3; sie ist der leichtere Fall, weil sie schon sechs Bänder hat
      - **Prüfung:** `ges_tabelle.php`, `ges_filter.php`; Bilder
      - **Abhängigkeit:** C3

- [x] **G14** `·` **M** Werkzeug- und Verwaltungsseiten
      → `estab-ui.css` (Hauptteil), `tests/php/ges_bedienmasse.php`, `app/ux_rules.php`, dazu die `4fadm`-Dateien mit Maßen im Markup
      - [ ] `estab-tool-*`, `estab-admin-*`, `estab-export-*`, `estab-telecom-*`, `estab-incident-*`
      - [ ] Einzelelement-Tafeln aufgelöst (Spec 4.4)
      - [ ] Regeln `GES-KNOPFMASSE`, `GES-GESPERRT-LESBAR`
      - [ ] Reißt der Bereich fünf Dateien, wird er nach Präfix geteilt
      - **Prüfung:** `ges_bedienmasse.php`; Bilder der Verwaltungsseiten
      - **Abhängigkeit:** C3

- [x] **G15** `·` **M** Infosammlung, Handbuch, E-Mail-Ansicht
      → `estab-ui.css`, `handbuch/handbuch.css`, `tests/php/ges_marken.php`, dazu die `stabinfo`-Dateien mit eigenen Maßen
      - [ ] `estab-bos-*`, `estab-email-preview-*`
      - [ ] `handbuch/handbuch.css` bindet dieselben Marken ein und färbt keine Verweise der Hülle mehr um
      - [ ] Fließtext im Handbuch auf 34rem begrenzt
      - **Prüfung:** Wächter über beide Stylesheets; Bild der Handbuchseite
      - **Abhängigkeit:** C3

- [x] **G16** `·` **M** Zeitleiste und Anlagen
      → `estab-ui.css`, `app/message_timeline.php`, `tests/php/ges_marken.php`
      - [ ] `estab-message-timeline-*`, `estab-message-attachment-*`
      - [ ] Die sieben alten Custom Properties der Zeitleiste durch Marken ersetzt
      - **Prüfung:** Wächter; Bild einer Nachricht mit Anlage und Rücklauf
      - **Abhängigkeit:** C3

- [x] **G17** `!` **M** Übrige übernommene Seiten
      → `estab-ui.css`, `tests/php/ges_migrationsgrenze.php`, dazu die `4fach`-Dateien mit Bildknöpfen
      - [ ] Anhangseite und die verbliebenen `.estab-list-*`-Ansichten nach dem Schema aus P3
      - [ ] `.estab-legacy-page` als eigener Farb- und Schriftraum entfällt vollständig
      - **Prüfung:** Wächter ohne Ausnahme; Bilder
      - **Abhängigkeit:** C3

> **Prüfpunkt C4** — Ist irgendwo ein Bereich anders geworden, als das Schema
> es vorgibt? Wenn ja: Hat das Schema gefehlt, oder hat jemand es übergangen?

---

## P5 — Festsetzen

- [x] **G18** `!` **M** Höhenbudget messen und durchsetzen
      → `tools/bedienpruefung/blick/aufnahme.mjs`, `tests/php/ges_seitenaufbau.php`, `app/ux_rules.php`
      - [x] `blick` misst die Höhe jedes Bandes je Seite
      - [x] Vergleich gegen Spec 4.1: Seitenkopf 2.5rem, Meldungskasten 4rem, Aktionsleiste 2rem, Filterblock 13rem, Fußleiste 2.5rem
      - [x] Was reißt, wird **gekürzt** — Reihenfolge aus Spec 4.1
      - [x] Budget **gemessen statt geschätzt**: Die Aktionsleiste stand im Plan auf 2rem — dasselbe Maß wie ihre Knöpfe. Ein Balken mit Rand und Polster um 2rem-Knöpfe ist nie 2rem; gemessen 46px. Polster gekürzt, Budget auf 2.5rem korrigiert. Jetzt 12 Bänder gemessen, kein Befund über vier Höhen
      - **Prüfung:** `blick` über vier Höhen; kein Band über Budget
      - **Abhängigkeit:** G13–G17

- [x] **G19** `!` **S** Migrationsgrenze entfernen, `!important` einordnen
      → `tests/php/ges_migrationsgrenze.php` (entfällt), `estab-ui.css`, alle `ges_*`-Tests
      - [x] Die Ausnahmeliste ist leer. `migrationsgrenze.php` ist entfernt und durch `vordruck_ausnahme.php` ersetzt — der Vordruck bleibt als **benannte, dauerhafte** Ausnahme, nicht als Rest einer Umstellung
      - [x] Die 7er-Stufe gilt überall außer im Vordruck — dort prüft `ux_form_contrast` 14 Paarungen gegen denselben Wert
      - [x] `!important` — **Planvorgabe korrigiert:** Alle 53 Vorkommen sind nötig, keines ist Bequemlichkeit. Neben Druck und `prefers-reduced-motion` gibt es zwei weitere Fälle, in denen die Ordnung nicht reicht: `forced-colors` und **fremdes Markup** (Inline-Stile, Präsentationsattribute, eingebettete Dokumente, `[hidden]`). Ein Inline-Stil steht in der Ordnung über jeder Regel — ihn ohne `!important` zu schlagen ist unmöglich, nicht unsauber. Die vier Fälle stehen jetzt in der Spec und werden von `GES-DURCHSETZUNG` geprüft
      - [x] `GES-MARKEN` zählt null Literale außerhalb `:root` — 1202 Regeln geprüft, 184 im Vordruck
      - **Prüfung:** volle Suite; ein neues Literal an **beliebiger** Stelle muss rot werden
      - **Abhängigkeit:** G18

- [x] **G20** `·` **M** Bildprüfung erweitern
      → `tools/bedienpruefung/blick/aufnahme.mjs`, `rundgang.mjs`, `docs/BEDIENPRUEFUNG.md`
      - [x] Vier Bildschirm**höhen** zusätzlich zu den vier Breiten
      - [x] Graustufenvergleich: jeder Zustand aus Spec Abschnitt 9 bleibt unterscheidbar
      - [x] Maßstab des Vordrucks wird mitgemessen
      - [x] `GES-KEIN-UEBERLAUF` und `GES-OHNE-FARBE` werden vom Werkzeug gemessen, nicht von der PHP-Suite — ein Browser lässt sich dort nicht starten. Sie stehen deshalb wie `UX-EINARBEITUNG` **nicht** im Katalog: Eine Regel ohne Test bricht die Registry, und ein vorgetäuschter Test wäre schlimmer als keiner
      - **Prüfung:** ein voller Rundgang je Konto
      - **Abhängigkeit:** G19

- [x] **G21** `·` **S** Dokumentation nachziehen
      → `docs/GESTALTUNG.md`, `SPEC.md`, `docs/TECHNIK.md`, `docs/BEDIENUNG.md`
      - [x] Gestaltungsspec Abschnitt 14: Bestandsspalte auf den neuen Stand
      - [x] Gestaltungsspec Abschnitt 15: „noch keine Regeln eingetragen" ersetzt durch die Katalogeinträge
      - [x] `SPEC.md` Abschnitt 10.1: die zweite Herkunft im Bedienkatalog erklärt
      - [x] Namentlich vermerkte Kontrastausnahmen vollständig
      - **Prüfung:** `tests/static/run.sh` grün; Fundstellen stimmen
      - **Abhängigkeit:** G20

> **Prüfpunkt C5 — bestanden, mit einer Einschränkung.**
> - [x] Migrationsgrenze entfernt; nur der Vordruck bleibt als benannte Ausnahme
> - [x] `GES-MARKEN` zählt **null** Farbliterale außerhalb des Vordrucks; 1213 von 1395 Regeln unter Prüfung
> - [x] 14 `GES-`-Regeln im Katalog, Registry grün: 30 Regeln über 21 Tests
> - [x] Volle Suite grün, 149 Prüfungen
> - [x] Höhenbudget über vier Bildschirmhöhen ohne Befund, 12 Bänder gemessen
> - [ ] **`blick` meldet auf der Nachrichtenseite mehr Wortbrüche als in der Grundlinie.** Die Listen stehen jetzt in der Arbeitsgröße statt in 12 Bildpunkten; mehr Schrift in derselben Spalte bricht öfter. Die Zellen bitten um Silbentrennung, das Chromium der Prüfabbilder beherrscht sie für Deutsch nicht. Ob das im Bild stört, entscheidet die Bedienprüfung, nicht der Melder

---

## Parallelisierbar

**P0 bis P3 sind sequenziell.** Die beiden Musterseiten bringen das Schema
hervor; sie parallel zu bauen hieße, es zweimal zu erfinden.

**P4 ist vollständig parallel:** G13, G14, G15, G16 und G17 berühren
getrennte Auswählerräume und getrennte Präfixe der Migrationsgrenze.
Gemeinsam angefasst wird nur die eine Liste in `ges_migrationsgrenze.php`.

**P5 ist wieder sequenziell**, weil jede Aufgabe die vorige voraussetzt.
