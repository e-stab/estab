# GESTALTUNG — Aufbau und Aussehen jeder Seite im eStab

Diese Spec sagt, wie eine Seite im eStab aufgebaut ist: welche Bänder sie in
welcher Reihenfolge trägt, was als Überschrift daran steht, wie groß ein Knopf
ist, welche Größe Fließtext hat, wie eine Tabelle aussieht und wie Suche und
Filter über einer Tabelle stehen.

Sie ist **Sollvorgabe, kein Bestandsbericht.** Der heutige Bestand weicht ab —
Abschnitt 14 sagt wie und wie viel.

---

## 1. Zweck und Vorrang

### 1.1 Warum es diese Spec gibt

`SPEC.md` Abschnitt 5.10 (M9 — bedienung) sagt, **was** die Bedienung leisten
muss: Ortskonstanz, Elementkonstanz, Papierbild, Kontrast, Tastatur,
Bedienbarkeit auf flachen Bildschirmen. Sie sagt nicht, **wie groß** ein Knopf
ist. Genau daran scheitert `UX-ELEMENTKONSTANZ` in der Praxis: Zwei Knöpfe
gleicher Bedeutung sehen gleich aus, solange derselbe Mensch sie am selben Tag
schreibt — und danach nicht mehr.

Der Bestand zeigt, was daraus wird. In `estab-ui.css` stehen heute

- **359 verschiedene Hexfarben**,
- **61 verschiedene Schriftgrößen**,
- **15 verschiedene Eckradien**,
- **7 Custom Properties** — alle sieben für die Zeitleiste, sonst keine.

Das ist kein Gestaltungsfehler einzelner Stellen, sondern das erwartbare
Ergebnis, wenn jede Stelle ihre Werte selbst wählt. Diese Spec zieht die Werte
auf wenige benannte Stufen zusammen, damit „gleiche Bedeutung heißt gleiches
Aussehen" nachprüfbar wird statt gutgemeint.

### 1.2 Die zwei Leitsätze

Zwei Forderungen bestimmen jede Entscheidung in dieser Spec. Sie ziehen
gegeneinander, und **welche wann gewinnt, ist die eigentliche Aussage dieses
Dokuments.**

> **L1 — So klein wie möglich.** Eine Führungsstelle arbeitet auf
> Laptopbildschirmen. Auf die Fläche, die es gibt, soll so viel Inhalt passen
> wie möglich. Jeder Bildpunkt, den ein Rahmen, ein Polster oder eine
> Überschrift verbraucht, ist eine Zeile Lage, die jemand nicht sieht.

> **L2 — Alles muss lesbar sein.** Eine Angabe, die schlecht lesbar ist, geht
> unter. Im Meldewesen kann das große Folgen haben: eine überlesene
> Vorrangstufe, ein falsch gelesener Rufname, eine übersehene Rückweisung.
> Kontrast und Lesbarkeit sind keine Feinheit, sie sind die Funktion.

#### Wie der Konflikt aufgelöst wird

Nicht durch einen Kompromiss in der Mitte. Durch eine **Trennung nach
Gegenstand**: Es schrumpft, was den Inhalt umgibt — nicht der Inhalt.

| Schrumpft | Schrumpft nicht |
| --- | --- |
| Polster, Lücken, Innenabstände | Nachrichteninhalt, Betreff, Rufname |
| Knopf- und Feldhöhen | Kontrast — Untergrenze 7:1 statt 4.5:1 |
| Überschriften | Fokusring |
| Rahmenbreiten, Radien, Schatten | Klickflächen unter 1.75rem |
| Spaltenbreiten der Hülle | Zeichen und Wörter, die einen Zustand tragen |
| der **Maßstab** des Vordrucks | die **Verhältnisse** des Vordrucks |
| Erklärsätze, die niemand zweimal liest | Zeilenhöhe unter 1.35 |

**Die Rechnung dahinter**, aus den Werten des Stylesheets und dieser Spec
gerechnet — nicht im Browser gemessen:

Eine Tabellenzeile trägt im Bestand `padding: 0.8rem 0.75rem`, also
**25.6 px** Polster senkrecht plus 1 px Trennlinie. Diese Spec setzt
`--abstand-2`, also **8 px** plus 1 px. Ersparnis: **rund 18 px je Zeile, ohne
dass ein Zeichen kleiner wird.**

Zum Vergleich der andere Weg: Schrift von 16 px auf 14 px senkt die Textzeile
um etwa 3 px — ein Sechstel des Gewinns, und es kostet genau das, was L2
schützt.

Eine Tafel trägt im Bestand `clamp(1rem, 2.5vw, 1.5rem)` Innenabstand, auf
einem breiten Schirm also **48 px** senkrecht. Diese Spec setzt `--abstand-5`,
also **24 px**.

**Die Fläche steckt im Beiwerk, nicht in der Schrift.** Deshalb holt diese
Spec sie dort und lässt die Schrift in Ruhe.

Wie viele Zeilen dabei tatsächlich hinzukommen, sagt keine Rechnung, sondern
die Messung: `tools/bedienpruefung/blick` über vier Bildschirmhöhen, vor und
nach jeder Stufe der Lückenliste.

#### Wo L2 gegen L1 gewinnt — ohne Ausnahme

1. **Der Nachrichteninhalt** ist 1rem groß und bleibt es auf jedem Bildschirm.
   Er ist das, worum es geht.
2. **Jeder Text erreicht 7:1** gegen seinen tatsächlichen Grund. Es gibt keine
   gedämpfte Schrift in dieser Anwendung (Abschnitt 2.4).
3. **Kein Text unter 0.75rem.** Auch nicht in einer Marke, auch nicht in einer
   Fußzeile.
4. **Keine Klickfläche unter 1.75rem** (28 px).
5. **Schreibgeschützte Felder behalten vollen Kontrast.** Ein gesperrtes Feld
   ist im Vordruck nicht unwichtig — es trägt den Aufnahmevermerk der
   vorherigen Station, den die nächste lesen muss.

### 1.3 Geltungsbereich

Gilt für **jede Oberfläche der Anwendung**: Hülle, Menüspalte, Cockpitspalte,
Seitenköpfe, Tafeln, Knöpfe, Formulare, Tabellen, Filter, Rückmeldungen,
Anmeldung, Administration, Handbuch, Infosammlung.

Zwei Bereiche haben Sonderregeln, und beide sind ausdrücklich beschrieben:

- **Der Nachrichtenvordruck** (Abschnitt 12) ist ein Papierfaksimile. Die
  Schrift-, Radien- und Knopfmaße dieser Spec gelten **innerhalb des Blattes
  nicht**. Alles um das Blatt herum gehorcht ihr. Die Kontrastregeln gelten
  auch dort.
- **Übernommene Seiten** (Abschnitt 13) erfüllen die Spec noch nicht
  vollständig. Sie erhalten eine Angleichungspflicht, keine Ausnahme.

### 1.4 Vorrang

1. **Dienstvorschrift** (Q1–Q5, Katalog in `app/dv_rules.php`). Wo die
   Vorschrift eine Darstellung verlangt — die Feldfolge des Vordrucks, die
   Farben der Durchschriften —, schlägt sie diese Spec.
2. **Bedienkatalog** (P1, `app/ux_rules.php`). Wo eine Bedienregel eine
   Eigenschaft verlangt — Tastaturbedienbarkeit, Bedienbarkeit ohne
   JavaScript —, schlägt sie diese Spec.
3. **L2 vor L1.** Wo Dichte und Lesbarkeit sich widersprechen, gewinnt die
   Lesbarkeit. Eine Zeile mehr auf dem Schirm ist wertlos, wenn sie niemand
   entziffert.
4. **Diese Spec.** Sie füllt aus, was 1 bis 3 offenlassen.

Ein Konflikt zwischen 1 oder 2 und dieser Spec ist ein Fehler dieser Spec und
wird hier behoben, nicht dort umgangen.

### 1.5 Wie mit dieser Spec gearbeitet wird

Alle Werte stehen als **Marken** (CSS Custom Properties) in einem `:root`-Block
am Kopf von `estab-ui.css`. Jede Regel weiter unten benutzt Marken, keine
Zahlen. Eine neue Zahl im Stylesheet ist ein Befund, kein Detail: Entweder es
gibt schon eine Stufe dafür, oder die Stufe fehlt und gehört in diese Spec.

Namen der Marken sind deutsch, wie Feldbeschriftungen und Regeltexte
(`SPEC.md` Abschnitt 9). Bezeichner im PHP folgen weiterhin der Umgebung ihrer
Datei.

---

## 2. Grundlagen

### 2.1 Maß

Alle Maße in `rem`. `1 rem = 16 px`, die Schriftgröße des Wurzelelements wird
**nicht** überschrieben — wer sie im Browser vergrößert, vergrößert die
Anwendung mit, und genau das ist auf einem Laptop im Einsatzraum der Griff,
den jemand tut. Eine Anwendung, die sich dagegen sperrt, zwingt zum Zoom, und
Zoom bringt den waagerechten Scrollbalken zurück.

`px` ist nur für Linienstärken zulässig (Abschnitt 2.5). Eine Linie, die mit
der Schrift wächst, wird zum Balken.

### 2.2 Abstandsskala

Grundraster 2 px, weil die Anwendung dicht gesetzt ist und 4 px als kleinste
Stufe zu grob wäre. Sieben Stufen, mehr gibt es nicht.

| Marke | Wert | px | Einsatz |
| --- | --- | --- | --- |
| `--abstand-1` | 0.125rem | 2 | zwischen Zeichen und Wort in einer Marke, zwischen Beschriftung und Feld |
| `--abstand-2` | 0.25rem | 4 | Zellenpolster senkrecht, Lücke zwischen Marken einer Reihe |
| `--abstand-3` | 0.375rem | 6 | Polster im Bedienelement senkrecht, Lücke zwischen Feldern |
| `--abstand-4` | 0.5rem | 8 | Zellenpolster waagerecht, Lücke zwischen Knöpfen einer Leiste |
| `--abstand-5` | 0.75rem | 12 | Innenabstand einer Tafel, Polster im Knopf waagerecht |
| `--abstand-6` | 1rem | 16 | Lücke zwischen Tafeln, Innenabstand der Inhaltsspalte quer |
| `--abstand-7` | 1.5rem | 24 | Abstand zwischen Abschnitten, Freiraum unter dem letzten Element |

Zwischenwerte sind nicht zulässig.

Der Bestand setzt heute Tafeln mit `clamp(1rem, 2.5vw, 1.5rem)` Innenabstand
und Zellen mit `0.8rem`. Diese Spec halbiert beides. Der Gewinn ist keine
Kleinigkeit: Acht Tafeln auf einer Seite sparen zusammen rund 100 px Höhe —
vier zusätzliche Zeilen.

### 2.3 Schrift

#### Schriftsatz

```
--schriftsatz: Arial, Helvetica, "Liberation Sans", sans-serif;
```

Ein Satz für die ganze Anwendung, Vordruck eingeschlossen.

Ein `system-ui`-Stapel wurde erwogen und **verworfen**: Er sieht auf jedem
Gerät anders aus, und die Spaltenmaße dieser Anwendung sind eng. Ein Menü, das
auf dem einen Laptop passt und auf dem nächsten „Führungsstelle" mitten
durchbricht, ist im Einsatz ein Mangel. Arial ist auf Windows, macOS und in den
Prüfabbildern vorhanden; `Liberation Sans` deckt Linux metrisch gleich ab.

Keine Webschrift. Die Anwendung lädt keine fremden Hosts (`csp.php`,
`tests/static/offline_images.sh`), und eine nachgeladene Schrift wäre im
Einsatz genau dann weg, wenn die Leitung weg ist.

#### Schriftskala

Sieben Stufen. Zeilenhöhe gehört zur Stufe und wird nicht einzeln gesetzt.

| Marke | Wert | px | Zeilenhöhe | Einsatz |
| --- | --- | --- | --- | --- |
| `--schrift-1` | 0.75rem | 12 | 1.3 | **Untergrenze.** Nur Tabellenkopf, Bereichsmarke, Feldnummer im Vordruck |
| `--schrift-2` | 0.8125rem | 13 | 1.35 | Hilfstext unter einem Feld, Abzeichen, Menükachel, kleiner Knopf, Zeitstempel |
| `--schrift-3` | 0.875rem | 14 | 1.35 | **Arbeitsgröße.** Tabellenzelle, Feldbeschriftung, Eingabefeld, Knopf |
| `--schrift-4` | 1rem | 16 | 1.5 | **Nachrichteninhalt, Betreff, Fließtext, Meldungskasten** |
| `--schrift-5` | 1.125rem | 18 | 1.3 | h2 — Tafelüberschrift |
| `--schrift-6` | 1.25rem | 20 | 1.25 | h1 — Seitentitel |
| `--schrift-7` | 1.5rem | 24 | 1.2 | Sonderfall: Name der Führungsstelle auf der Übersicht, Anmeldeseite |

**Die Arbeitsgröße ist 0.875rem (14 px), nicht 1rem.** Das ist die Größe, in
der Tabellen, Formulare und Bedienelemente gesetzt sind — dieselbe Dichte, die
jede Anwendung auf dem Schreibtisch hat, an der im Stab ohnehin gearbeitet
wird. 14 px Arial sind auf einem Laptopbildschirm bei 100 % einwandfrei
lesbar, und der Gewinn gegenüber 16 px ist über eine ganze Liste erheblich.

**Der Nachrichteninhalt ist die eine Stelle, die davon ausgenommen ist.**
Betreff, Nachrichtentext, Rufname und Anschrift stehen in `--schrift-4`. Das
ist der Gegenstand der Anwendung; wer ihn verkleinert, spart an der falschen
Stelle. Dasselbe gilt für jeden Meldungskasten (Abschnitt 8): Eine Rückweisung,
die man überliest, hat ihren Zweck verfehlt.

**Kein Text ist kleiner als `--schrift-1`.** Der Bestand setzt Text bis
herunter auf `0.52rem` (gut 8 px) — das ist auf einem Laptopbildschirm im
Tageslicht nicht lesbar und ist ein Mangel, keine Dichte. Wo `--schrift-1` zu
groß erscheint, ist nicht die Schrift das Problem, sondern dass die Angabe
überhaupt dort steht.

#### Zeilenhöhe

Zeilenhöhe unter **1.3** ist verboten, auch wenn sie Platz spart: Bei engeren
Zeilen greift das Auge beim Zeilenwechsel daneben, und in einer achtspaltigen
Liste ist genau das der Fehlgriff, der eine Nachricht der falschen Zeile
zuordnet.

Fließtext in `--schrift-4` steht auf 1.5 — der Nachrichteninhalt wird gelesen,
nicht überflogen.

#### Schriftstärken

| Marke | Wert | Einsatz |
| --- | --- | --- |
| `--stark-normal` | 400 | Fließtext, Tabellenzellen |
| `--stark-halb` | 600 | Feldbeschriftung, Hervorhebung in einer Zelle, ungelesene Zeile |
| `--stark-voll` | 700 | Überschriften, Knöpfe, Tabellenkopf, Marken |

Mehr als 700 gibt es nicht. Der Bestand setzt 56-mal `800` und 8-mal `900`;
Arial hat keinen solchen Schnitt, der Browser rundet auf 700 ab, und die
Angabe suggeriert einen Unterschied, den niemand sehen kann.

**Stärke ersetzt Größe.** Wo eine Angabe hervorstechen soll, wird sie fetter,
nicht größer — das kostet keine Höhe. Wo sie zurücktreten soll, wird sie
schmaler oder kleiner, **nie blasser** (Abschnitt 2.4).

#### Zahlen

Zeitangaben, TBB-Nachweisnummern, Zähler und Laufzeiten setzen
`font-variant-numeric: tabular-nums`. Untereinander stehende Zahlen müssen
untereinander stehen — bei dichtem Satz noch mehr als bei lockerem.

#### Umbruch

Deutsche Fachwörter sind lang, und die Spalten sind schmal. Für jeden Kasten
mit begrenzter Breite gilt:

```
hyphens: auto;            /* braucht lang="de" am <html> */
overflow-wrap: anywhere;
```

`hyphens: auto` ist eine Bitte, keine Zusage: Nicht jeder Browser beherrscht
deutsche Silbentrennung — das Chromium der Prüfabbilder tut es nicht. Deshalb
steht `overflow-wrap: anywhere` daneben, und deshalb prüft
`tools/bedienpruefung/blick` auf **Wortbruch ohne Trennstrich** statt sich auf
die Zusage zu verlassen.

Ein Wort, das ohne Trennstrich über zwei Zeilen bricht, ist ein Mangel — bei
dichtem Satz die häufigste Art, wie L1 gegen L2 verstößt.

### 2.4 Farben

37 Farbmarken über 28 Werte statt 359 Hexfarben.

#### Die Kontrastuntergrenze ist 7:1

**7:1 ist der Sollwert, 4.5:1 die Untergrenze** — für jeden Text auf jedem
Grund, auf dem er vorkommen kann.

`UX-KONTRAST` verlangte ursprünglich nur den AA-Wert 4.5:1. Der Betreiber hat
die Regel auf zwei Stufen gehoben; gearbeitet wird am Sollwert:

| Stufe | Gilt | Bedeutung |
| --- | --- | --- |
| **7:1** | jeder Text dieser Spec | der Wert, auf den entworfen wird |
| **4.5:1** | ausnahmslos jeder Text | wird nie unterschritten, auch nicht in einer Ausnahme |

Der Grund ist L2. Ein Laptopbildschirm im Einsatzraum steht unter
Deckenbeleuchtung oder im Tageslicht, oft schräg im Blick, oft mit
Fingerabdrücken. Der gemessene Kontrast ist der beste Fall, nicht der
tatsächliche. 4.5:1 im Labor ist auf einem verspiegelten Schirm um 14 Uhr
weniger. 7:1 hat Luft.

**Ausnahmen nur für vorgeschriebene Farben.** Wo eine Farbe der
Dienstvorschrift 7:1 nicht zulässt — die Durchschriftenfarben des Vordrucks
sind der Kandidat —, gilt die Vorschrift (Abschnitt 1.4), die Paarung wird
**namentlich in dieser Spec vermerkt** und bleibt über 4.5:1. Eine
stillschweigende Ausnahme gibt es nicht: Was nicht hier steht, ist keine.

Die Folge ist eine harte Regel: **Es gibt keine gedämpfte Schrift in dieser
Anwendung.** Kein Grau für „weniger wichtig", kein `opacity` auf Text, keine
blasse Fußzeile. Rangfolge entsteht über Größe, Stärke und Ort — nie über
Blässe. Was zu unwichtig ist, um lesbar zu sein, ist zu unwichtig, um zu
stehen.

Jede Paarung unten ist gegen ihren **tatsächlichen** Grund gerechnet; die
Verhältnisse stehen dabei. Der niedrigste Wert im ganzen Farbsystem ist
**7.02**.

#### Helle Flächen und Tinte

| Marke | Wert | Einsatz |
| --- | --- | --- |
| `--grund-seite` | `#f4f7fa` | Grund der Inhaltsspalte |
| `--grund-tafel` | `#ffffff` | Grund jeder Tafel, jedes Feldes |
| `--grund-gedaempft` | `#e6ecf3` | Tabellenkopf, schreibgeschütztes Feld |
| `--grund-zebra` | `#f4f7fa` | jede zweite Tabellenzeile |
| `--tinte` | `#243547` | Fließtext, Nachrichteninhalt, Überschriften |
| `--tinte-neben` | `#3d4c5c` | zweite Tinte: Hilfstext, Platzhalter, Zeitangaben |

**`--tinte-neben` ist keine gedämpfte Tinte, sondern eine zweite.** Sie ist
kühler und eine Spur dunkler gehalten, damit sie auf jedem Grund über 7:1
bleibt. Der Unterschied zur Haupttinte trägt keine Bedeutung; er ordnet nur.

Gemessen auf allen acht hellen Gründen der Anwendung:

| Grund | `--tinte` | `--tinte-neben` |
| --- | --- | --- |
| Tafel `#ffffff` | 12.54 | 8.80 |
| Seite / Zebra `#f4f7fa` | 11.66 | 8.18 |
| Tabellenkopf `#e6ecf3` | 10.54 | 7.40 |
| Zeigezeile / Hinweis `#e2edfa` | 10.58 | 7.43 |
| Sofort / Achtung `#fdf2d8` | 11.26 | 7.91 |
| Blitz `#fdeadb` | 10.73 | 7.53 |
| Staatsnot / Fehler `#fdeceb` | 10.97 | 7.70 |
| Erledigt `#e6f4ea` | 11.04 | 7.75 |

Auf der Goldfläche der Standortmarke ist **nur `--tinte` zulässig** (7.53);
`--tinte-neben` erreicht dort nur 5.28.

#### Linien und Ränder

| Marke | Wert | Einsatz |
| --- | --- | --- |
| `--linie` | `#d2dbe5` | Trennlinie zwischen Zeilen, innerhalb einer Tafel |
| `--linie-kraeftig` | `#b0bccb` | Rand einer Tafel, Unterlinie des Tabellenkopfs |
| `--rand-bedienelement` | `#717f92` | **jeder** Rand, der ein Bedienelement begrenzt |

Die Trennung ist keine Feinheit. `--linie` und `--linie-kraeftig` erreichen
gegen Weiß nur **1.40** und **1.93** — als Rand eines Eingabefeldes wären sie
ein Verstoß gegen WCAG 1.4.11 (Nichttext-Kontrast, 3:1).
`--rand-bedienelement` erreicht **4.08** auf Tafel, **3.79** auf Seitengrund,
**3.43** auf Tabellenkopf, **3.44** auf Zeigezeile und **3.66** auf einer
Vorrangzeile — auf jedem Grund, auf dem ein Feld stehen kann.

Merksatz: **Was man anfassen kann, hat `--rand-bedienelement`. Was nur trennt,
hat `--linie`.** `--rand-bedienelement` ist ausschließlich Randfarbe; als Text
erreicht sie 7:1 nicht.

Bei dichtem Satz übernimmt die Linie zusätzlich Arbeit: Wo das Polster
schrumpft, muss die Trennung zwischen zwei Zeilen sichtbar bleiben. Eine
Tabelle ohne Zeilenlinien ist bei 4 px Polster nicht mehr zu lesen.

#### Dunkle Spalten (Menü und Cockpit)

| Marke | Wert | Einsatz |
| --- | --- | --- |
| `--grund-spalte` | `#0c1c2b` | Grund von Menü- und Cockpitspalte |
| `--grund-kachel` | `#152738` | Kachel, Menüziel, Kasten in der Spalte |
| `--grund-kachel-zeigen` | `#1f3a57` | dieselbe Kachel beim Zeigen, bei Fokus, als aktuelle Seite |
| `--tinte-spalte` | `#ffffff` | Text in der dunklen Spalte |
| `--tinte-spalte-neben` | `#c9d3de` | Abschnittsüberschrift, Nebenangabe, Rand beim Zeigen |
| `--linie-spalte` | `#717f92` | Rand einer Kachel im Ruhezustand |
| `--erledigt-spalte` | `#8ce8bd` | „besetzt", „aktiv" — Zustandstinte in der Spalte |
| `--erledigt-spalte-flaeche` | `#163e35` | Fläche dazu |
| `--fehler-spalte` | `#ffc2c2` | „unbesetzt", „Vorrang offen" |
| `--fehler-spalte-flaeche` | `#5a1a1f` | Fläche dazu |
| `--marke-standort-flaeche` | `#3d341d` | Fläche unter der Standortmarke |

**Warum es diese fünf zusätzlich gibt:** Die hellen Zustandsfarben aus der
Tabelle weiter unten sind für Meldungskästen auf der Tafel gerechnet. Das
Cockpit zeigt Besetzung und Vorrang aber auf dunklem Grund, und dort trägt
`#155c2d` nichts. Sie sind beim Umsetzen der Hülle aufgefallen und
nachgetragen worden — geprüft: Zustandstinte mindestens **7.63** auf allen
drei Kachelgründen, weiße Schrift auf den Flächen mindestens **11.83**, Gold
auf seiner Fläche **7.39**.

Geprüft: Weiß auf Spaltengrund **17.26**, auf Kachel **15.23**, auf
Zeigekachel **11.66**. Nebentinte `#c9d3de` auf Spaltengrund **11.39**, auf
Kachel **10.05**, auf Zeigekachel **7.69** — alle drei über 7:1.

**Eine Kachel in der dunklen Spalte trägt immer einen sichtbaren Rand.** Ihre
Fläche unterscheidet sich vom Grund nur um **1.13** — wer den Rand weglässt,
lässt die Kachel verschwinden. Im Zeigezustand wird der Rand auf
`--tinte-spalte-neben` gehoben (**7.69**); `--linie-spalte` erreicht dort nur
2.86.

#### Handlung

| Marke | Wert | Einsatz |
| --- | --- | --- |
| `--handlung` | `#1d5687` | **nur als Fläche**: gefüllte Hauptaktion |
| `--handlung-dunkel` | `#153f66` | **als Schrift**: Verweis, Tinte des Zweitknopfs; Fläche beim Drücken |
| `--handlung-sanft` | `#e2edfa` | Fläche des Zweitknopfs, Zeigezeile einer Tabelle |
| `--handlung-kante` | `#2b6ea8` | linke Kante der Zeigezeile |

**Blau als Fläche und Blau als Schrift sind zwei verschiedene Werte.**
`--handlung` erreicht als Schriftfarbe auf den dunkleren hellen Gründen nur
6.46 bis 6.90 und verfehlt damit 7:1. Als Fläche trägt es Weiß mit **7.68**.
Ein Verweis wird deshalb in `--handlung-dunkel` gesetzt — **9.13** im
schlechtesten Fall, **10.85** auf einer Tafel.

#### Standort und Fokus

| Marke | Wert | Einsatz |
| --- | --- | --- |
| `--marke-standort` | `#f0c34a` | „Sie sind hier": linke Kante der aktuellen Menükachel |
| `--fokus-aussen` | `#f0c34a` | äußerer Ring des Fokus |
| `--fokus-innen` | `#0c1c2b` | innerer Ring des Fokus |

**Gold ist nie allein Träger einer Bedeutung auf hellem Grund.** Gegen Weiß
erreicht `#f0c34a` nur **1.67** — als Rand oder Marke auf einer Tafel wäre es
unsichtbar. Zulässig ist Gold auf dunklem Grund (**10.36** auf Spaltengrund),
als äußerer Fokusring (Abschnitt 2.6) und als Fläche unter `--tinte`
(**7.53**).

#### Zustände

| Zustand | Fläche | Rand und Kante | Tinte | geprüft |
| --- | --- | --- | --- | --- |
| Hinweis | `#e2edfa` | `#1d5687` | `#153f66` | 9.16 |
| Erledigt | `#e6f4ea` | `#1f7a3d` | `#155c2d` | 7.11 |
| Achtung | `#fdf2d8` | `#a97b0b` | `#6b4d05` | 7.02 |
| Nicht ausgeführt | `#fdeceb` | `#a4231c` | `#7d1a14` | 9.05 |
| Gefahrknopf | `#a4231c` | `#a4231c` | `#ffffff` | 7.41 |

#### Vorrang

Die Vorrangstufe wird **nie allein durch Farbe** getragen. Jede Stufe hat
Fläche, Rand, Tinte **und ein eigenes Zeichen**.

| Stufe | Fläche | Rand | Tinte | Zeichen | Tinte auf Fläche |
| --- | --- | --- | --- | --- | --- |
| kein Vorrang | `#e6ecf3` | `#717f92` | `#243547` | keines | 10.54 |
| Sofort | `#fdf2d8` | `#a97b0b` | `#6b4d05` | ▲ Dreieck | 7.02 |
| Blitz | `#fdeadb` | `#b25a12` | `#733806` | ◆ Raute | 7.82 |
| Staatsnot | `#fdeceb` | `#a4231c` | `#7d1a14` | ■ Quadrat | 9.05 |

Das Zeichen steht als CSS-`::before` mit `aria-hidden`; die Stufe steht
daneben als Wort. Ein Schwarzweißausdruck und ein Bildschirm im Sonnenlicht
zeigen dann immer noch drei unterscheidbare Marken.

Fließtext `--tinte` auf allen drei Vorranggründen: **11.26**, **10.73**,
**10.97**.

#### Verbotene Farben

Alles, was nicht in diesen Tabellen steht. Insbesondere:

- `#000000` als Textfarbe — reines Schwarz auf Weiß flimmert auf schlechten
  Bildschirmen; `--tinte` erreicht mit 12.54 mehr als genug.
- **Jedes `opacity` auf Text.** Es senkt den Kontrast unkontrolliert und
  entzieht ihn der Messung. Der Bestand setzt an mehreren Stellen
  `opacity: .78` auf Hilfstexte — das ist genau die Blässe, die L2 verbietet.
- Lila, Serifengrund und Browservorgaben der übernommenen Listen
  (Abschnitt 13).
- Jede Farbe, die eine Bedeutung allein trägt (Abschnitt 9).

### 2.5 Radien, Linien, Schatten

Bei dichtem Satz sind die Radien kleiner: Ein 12-px-Radius an einem 32 px
hohen Knopf frisst die halbe Kante.

| Marke | Wert | Einsatz |
| --- | --- | --- |
| `--radius-1` | 2px | Ankreuzfeld, Marke im Vordruck |
| `--radius-2` | 4px | Knopf, Eingabefeld, Auswahlfeld, Meldungskasten |
| `--radius-3` | 6px | Tafel, Tabellenrahmen, Aufklapp |
| `--radius-pille` | 999px | Marke (Chip), Abzeichen |

| Linie | Stärke | Einsatz |
| --- | --- | --- |
| Trennlinie | 1px | zwischen Tabellenzeilen, innerhalb einer Tafel, Tafelrand |
| Bedienrand | 1px | Rand jedes Knopfs, jedes Feldes |
| Fokusring | 2px | Abschnitt 2.6 |
| Zuständigkeitskante | 3px | linke Kante: Zeigezeile, Meldungskasten, aktuelle Menükachel |
| Vorrangkante | 4px | linke Kante einer Vorrangzeile — dicker als 3px, damit sie die Zeigekante schlägt |

Der Bedienrand ist **1px statt 2px**. Zwei Pixel Rand kosten an einem Feld
4 px Höhe und an einer Formularreihe mit acht Feldern nichts weniger als eine
Zeile. Die 3:1-Forderung an `--rand-bedienelement` bleibt davon unberührt —
sie gilt für die Farbe, nicht für die Dicke.

Ein Feld im Fehlerzustand bekommt 2px, weil dort die Auffälligkeit zählt.

#### `!important`

`!important` setzt die Ordnung der Stylesheets außer Kraft, und wer es einmal
benutzt, braucht es beim nächsten Mal wieder, um das erste zu schlagen. Am
Ende gilt nicht mehr, was zuletzt geschrieben steht, sondern wer lauter
gerufen hat.

Vier Fälle sind zulässig, und alle vier haben denselben Grund — dort reicht
die normale Ordnung nicht:

| Fall | Warum |
| --- | --- |
| `@media print` | Der Druckblock muss den Bildschirmstil schlagen |
| `prefers-reduced-motion` | Bewegung abzuschalten muss jede Regel schlagen, die sie einschaltet |
| `forced-colors` | Was das Betriebssystem setzt, darf nichts übermalen |
| **gegen fremdes Markup** | Ein Inline-Stil steht in der Ordnung über jeder Regel. Dasselbe gilt für Präsentationsattribute (`align`, `bgcolor`, `font`), eingebettete Fremddokumente und `[hidden]` gegen ein `display: flex` |

Alles andere ist ein Befund. Geprüft von `GES-DURCHSETZUNG`.

Zwei Schatten, mehr nicht:

```
--schatten-tafel:     0 1px 2px rgba(12,28,43,.07);
--schatten-schwebend: 0 4px 14px rgba(12,28,43,.24);
```

`--schatten-tafel` ist bewusst flach: Ein weicher, weit auslaufender Schatten
täuscht Abstand vor, der nicht da ist, und lässt eine dicht gesetzte Seite
unruhig wirken. **Knöpfe und Felder haben keinen Schatten.**

### 2.6 Fokus

Ein Fokus muss auf hellem **und** auf dunklem Grund sichtbar sein, und die
Anwendung hat beides nebeneinander. Ein einfarbiger Ring kann das nicht: Gold
erreicht auf Weiß nur 1.67, Dunkelblau auf der Menüspalte nur 1.00.

Deshalb **zwei Ringe**:

```
:focus-visible {
    outline: 2px solid var(--fokus-aussen);   /* gold */
    outline-offset: 1px;
    box-shadow: 0 0 0 2px var(--fokus-innen); /* dunkel, direkt am Element */
}
```

Der dunkle Ring liegt in der 1 px breiten Lücke, der goldene außen. Zusammen
5 px statt der 7 px eines dicken Rings — bei 32-px-Knöpfen nebeneinander ist
das der Unterschied zwischen einem Ring und einem Fleck.

Auf jedem Grund der Anwendung trägt mindestens einer der beiden:

| Grund | innen | außen | besserer Ring |
| --- | --- | --- | --- |
| Tafel weiß | 17.26 | 1.67 | **17.26** |
| Seitengrund | 16.05 | 1.55 | **16.05** |
| Tabellenkopf | 14.51 | 1.40 | **14.51** |
| Zeigezeile | 14.57 | 1.41 | **14.57** |
| Hauptaktion blau | 2.25 | 4.61 | **4.61** |
| Gefahrknopf rot | 2.33 | 4.45 | **4.45** |
| Menükachel | 1.13 | 9.14 | **9.14** |
| Menügrund | 1.00 | 10.36 | **10.36** |

Alle ≥ 3:1 (WCAG 1.4.11 und 2.4.11).

Regeln dazu:

- `:focus-visible`, nicht `:focus` — ein Mausklick auf einen Knopf soll keinen
  Ring hinterlassen, ein Tabulatorsprung schon.
- `outline: none` ohne Ersatz ist verboten. Ausnahmslos.
- **Der Fokusring schrumpft nicht mit der Dichte.** Er ist auf jedem
  Bildschirm 2 px plus 2 px. Er ist die einzige Rückmeldung, die ein
  Tastaturbediener bekommt.
- Bei erzwungenen Farben tritt der Systemring an die Stelle:
  ```
  @media (forced-colors: active) { :focus-visible { outline: 2px solid CanvasText; } }
  ```
- Der Ring darf nicht abgeschnitten werden. Ein Kasten mit `overflow: hidden`,
  in dem ein fokussierbares Element sitzt, braucht innen 4 px Luft — bei
  dichtem Satz die Stelle, an der es am ehesten schiefgeht.

### 2.7 Dichte

**Dichter Satz ist der Normalfall, nicht der Sonderfall.** Die Werte der
Abschnitte 2.2 bis 2.5 gelten auf jedem Bildschirm; es gibt keine lockere
Grundeinstellung, die auf kleinen Schirmen zusammengezogen wird. Der Bestand
macht es umgekehrt und braucht dafür zwei Höhen-Media-Queries und ein Dutzend
Sonderregeln.

Für sehr flache Bildschirme gibt es **eine** weitere Stufe.

**Ab `@media (max-height: 34rem)`** (544 px):

| Was | normal | sehr flach |
| --- | --- | --- |
| `--abstand-5` | 0.75rem | 0.5rem |
| `--abstand-6` | 1rem | 0.75rem |
| `--abstand-7` | 1.5rem | 1rem |
| h1 (`--schrift-6`) | 1.25rem | 1.125rem |
| Sonderfall (`--schrift-7`) | 1.5rem | 1.25rem |

Was sich **nie** ändert — die Umsetzung von L2:

- **Nachrichteninhalt und Fließtext.** `--schrift-4` bleibt 1rem.
- **Arbeitsgröße.** `--schrift-3` bleibt 0.875rem.
- **Kontrast.** Kein Grund wird heller, keine Tinte blasser.
- **Fokusring.**
- **Klickflächen.** Kein Knopf unter 1.75rem.
- **Zeilenhöhen.**

Die Anpassung geschieht durch Umsetzen der Marken im Media Query, nicht durch
Überschreiben einzelner Regeln. Wer eine Seite gestaltet, schreibt keine
Höhen-Media-Query.

#### Wo die Höhe hingeht

Die Dichte muss nachrechenbar bleiben, damit nicht am falschen Ende gespart
wird. Die vollständige Gegenüberstellung — Wert aus dem Bestand gegen Wert
dieser Spec, senkrecht gerechnet — steht in **Abschnitt 14.2** und wird dort
gepflegt, damit es sie nur einmal gibt.

Die beiden Posten, die den Ausschlag geben:

- **Zellenpolster einer Tabellenzeile:** 25.6 px im Bestand gegen 8 px hier —
  rund 18 px je Zeile.
- **Innenabstand einer Tafel:** bis 48 px gegen 24 px — 24 px je Tafel.

**Keine dieser Ersparnisse stammt aus kleinerer Schrift.** Die Arbeitsgröße
dieser Spec ist 14 px — die Größe, in der der Bestand seine Tabellen ohnehin
schon setzt (`0.78rem` bis `0.9rem`). Für den Nachrichteninhalt geht diese
Spec sogar **hinauf**.

Der tatsächliche Gewinn an sichtbaren Zeilen wird gemessen, nicht behauptet:
`tools/bedienpruefung/blick` läuft über vier Bildschirmhöhen, vor und nach
jeder Stufe der Lückenliste (Abschnitt 14.5).

### 2.8 Bewegung

Übergänge nur für Farbe und Schatten, höchstens 140 ms, `ease`. Keine
Bewegung von Position oder Größe: Eine Zeile, die beim Zeigen wandert, ist
unter Last ein Fehlgriff.

```
@media (prefers-reduced-motion: reduce) { *, *::before, *::after {
    animation-duration: .01ms !important; transition-duration: .01ms !important;
} }
```

---

## 3. Die Hülle

Jede Seite der Anwendung steht in derselben Hülle (`app/app_shell.php`). Drei
Spalten, zwei davon stehen still.

```
┌──────────────┬───────────────────────────────┬──────────────┐
│  Menüspalte  │        Inhaltsspalte          │ Cockpitspalte│
│  dunkel      │        hell                   │ dunkel       │
│  steht still │        ändert sich            │ steht still  │
└──────────────┴───────────────────────────────┴──────────────┘
```

| Spalte | Breite | Grund |
| --- | --- | --- |
| Menü | `clamp(13.5rem, 15vw, 15rem)` | `--grund-spalte` |
| Inhalt | `minmax(0, 1fr)` | `--grund-seite` |
| Cockpit | `clamp(15rem, 16vw, 16rem)` | `--grund-spalte` |

**Diese Werte sind gemessen, nicht gerechnet.** Ein früherer Entwurf setzte
beide Spalten auf rund 3rem schmaler an; der Wortbruch-Melder aus
`tools/bedienpruefung/blick` hat das widerlegt, und die Regel dieser Spec —
bricht ein Wort, gewinnt die Spalte — hat entschieden:

- **Die Menüspalte trägt die Verkleinerung** von 15.5rem auf 13.5rem. Sie
  gibt 182 Bildpunkte für die Kachelliste her. Zwei Kacheln nebeneinander
  bräuchten 210, weil „Führungsstelle" allein 89 Bildpunkte Text ist. Die
  Liste steht deshalb **einspaltig** — und das ist kein Verlust: Die Spalte
  scrollt für sich, ihre Höhe steht in keinem Wettbewerb mit dem Inhalt, und
  eine Kachel über die volle Breite ist der sicherere Griff. Gewonnen sind
  2rem Breite für die Mitte.
- **Die Cockpitspalte trägt sie nicht.** Bei 12.5rem fiel das
  Anwesenheitsraster auf eine Spalte zusammen, die Bezeichnungen liefen über,
  und „Führungsstelle" brach mitten durch. Sie bleibt deshalb bei 15rem.
  Gegenüber dem Bestand ist das ein halbes rem — die Ersparnis liegt hier
  nicht in der Breite, sondern im Polster.

**Vor jeder Änderung an diesen Breiten ist der Wortbruch-Melder über alle
vier Bildschirmbreiten zu fahren.** Eine gerechnete Spaltenbreite ist eine
Vermutung; die Metrik von Arial ist keine, die sich abschätzen lässt.

Höhe `100dvh`. **Jede Spalte scrollt für sich** (`overflow-y: auto;
overscroll-behavior: contain`). Das ist keine Bequemlichkeit: Solange alles in
einem Scrollbereich lag, musste die Navigation mit `position: sticky`
festgehalten werden und geriet dabei über ihre Nachbarn. Was nicht klebt,
überlagert nichts.

**`position: sticky` ist nur an zwei Stellen zulässig:** am Tabellenkopf
innerhalb seines Rahmens und an der Aktionsleiste über dem Vordruck. Beide
kleben innerhalb eines eigenen Scrollbereichs, nicht in der Seite.

### 3.1 Menüspalte

Von oben nach unten, immer in dieser Reihenfolge:

1. **Wortmarke** „eStab" — `--schrift-5`, `--stark-voll`, `--tinte-spalte`,
   Verweis auf die Übersicht.
2. **Bereiche** — Abschnittsüberschrift `--schrift-1`, `--stark-voll`,
   Versalien, Sperrung `.08em`, `--tinte-spalte-neben`. Darunter die Ziele als
   Kacheln, zweispaltig, Lücke `--abstand-2`.
3. **Dienst** — dieselbe Bauweise.
4. **Bereichseigene Auswahl** (optional) — z. B. die Dokumentliste der
   Infosammlung, getrennt durch eine 1px-Linie in `--linie-spalte`.
5. **Arbeitsschritte** (nur im Nachrichtenbereich) — füllt den Rest und
   scrollt für sich.

**Menükachel:** Höhe mind. 1.75rem, Polster `--abstand-2 --abstand-4`,
`--schrift-2`, `--stark-voll`, Radius `--radius-2`, Grund `--grund-kachel`,
Rand 1px `--linie-spalte`, links 3px durchsichtig.

1.75rem ist die Untergrenze für Klickflächen aus L2 und wird hier erreicht,
nicht unterschritten. Eine Menükachel trägt genau eine Zeile; bricht die
Beschriftung um, wächst die Kachel mit.

| Zustand | Merkmal |
| --- | --- |
| Ruhe | Grund `--grund-kachel`, Rand `--linie-spalte` |
| Zeigen / Fokus | Grund `--grund-kachel-zeigen`, Rand `--tinte-spalte-neben`, Fokusring |
| aktuelle Seite | Grund `--grund-kachel-zeigen`, linke Kante 3px `--marke-standort`, `aria-current="page"` |

**Jeder Eintrag ist immer sichtbar und immer anklickbar** (`UX-MENUE-ORTSKONSTANZ`).
Ein Ziel, das die eigene Funktion gerade nicht ansteuern darf, erklärt das auf
seiner eigenen Seite — nie im Menü. Es gibt keine ausgegrauten Menüeinträge und
keine Erklärsätze in der Menüspalte.

### 3.2 Cockpitspalte

Kästen untereinander, Lücke `--abstand-4`, Innenabstand `--abstand-4`, Grund
`--grund-kachel`, Rand 1px `--linie-spalte`, Radius `--radius-2`.

Reihenfolge fest: Uhr und Zähler · Angemeldet als · Aktive Führungsstelle ·
Besetzung · Primärfunktion.

Was sich während eines Einsatzes nicht ändert — Einsatzkennung, Beginn, Ort,
Betriebsart — steht **nicht** dauerhaft in der Spalte (`UX-STANDORT`). Es
bleibt als Merkmal für Auswertung und Vorleseprogramme erhalten, aber eine
Spalte, die immer offensteht, wird bei jedem Blick mitgelesen.

### 3.3 Schmale Fenster

| Fensterbreite | Verhalten |
| --- | --- |
| ≥ 64rem (1024 px) | drei Spalten |
| < 64rem | Cockpit rückt unter den Inhalt, volle Breite |
| < 56rem (896 px) | Menü wird ein Aufklapp über dem Inhalt; Reihenfolge und Beschriftung der Ziele bleiben unverändert |

Die Reihenfolge der Ziele ändert sich in keiner Stufe. Ortskonstanz gilt auch
quer über Bildschirmbreiten.

**Warum das Menü schon bei 56rem weicht und nicht erst später:** Der
Nachrichtenvordruck wird auf die Breite der Inhaltsspalte skaliert
(Abschnitt 12.1) und hört bei Maßstab 0.75 damit auf. Bliebe das Menü bis
44rem stehen, fiele die Spalte zwischen 704 px und 960 px Fensterbreite unter
diesen Maßstab, und der Vordruck müsste in einem breiten Band gängiger
Fenstergrößen waagerecht gescrollt werden. Mit der Grenze bei 56rem beginnt
das erst unter 733 px. Die Menüspalte kostet 216 px, die der Vordruck
unmittelbar in Maßstab umsetzt — auf einem schmalen Schirm ist das ein
schlechtes Geschäft.

---

## 4. Aufbau einer Seite

### 4.1 Die Bänder der Inhaltsspalte

Jede Seite trägt dieselben Bänder in derselben Reihenfolge. Ein Band, das eine
Seite nicht braucht, entfällt — die übrigen rücken auf, sie tauschen nie den
Platz.

```
┌─ Inhaltsspalte ──────────────────────────────────────────────┐
│                                                              │
│  1  Seitenkopf            Pflicht, genau einer               │
│     ────────────────────────────────────────────             │
│  2  Rückmeldung           nur wenn es eine gibt              │
│  3  Aktionsleiste         nur wenn die Seite Aktionen hat    │
│  4  Inhalt                eine oder mehrere Tafeln           │
│  5  Fuß                   optional: Stand, Quelle            │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

Innenabstand der Spalte: `--abstand-4` oben, `--abstand-6` quer,
`--abstand-7` unten. Der Freiraum unten ist Absicht: Der letzte Knopf einer
Seite darf nicht am Rand des Scrollbereichs kleben.

Lücke zwischen den Bändern: `--abstand-6`.

#### Höhenbudget

L1 lässt sich nicht durch Sorgfalt allein einhalten — jedes Band wächst für
sich betrachtet aus guten Gründen. Deshalb bekommt jedes wiederkehrende Band
eine Obergrenze. Sie gilt im **Ruhezustand**: nichts aufgeklappt, kein Filter
gesetzt, keine Rückweisung.

| Band | Höchstens | Was das Budget füllt |
| --- | --- | --- |
| Seitenkopf | 2.5rem | Bereichsmarke, Titel, Unterlinie |
| Meldungskasten | 4rem | Wort, ein bis zwei Sätze |
| Aktionsleiste | 2.5rem | eine Zeile Knöpfe von 2rem, plus Rand und knappes Polster |
| Filterblock über einer Liste | 13rem | Bänder 1 bis 6 aus Abschnitt 7 |
| Fußleiste einer Liste | 2.5rem | Trefferzahl, Sortierung, Blätterer |
| Aufklappband (zu) | 2.5rem | Zeile mit Dreieck und Namen |

**Die Werte sind gemessen.** Ein früherer Entwurf setzte die Aktionsleiste auf
2rem — dasselbe Maß wie ihre Knöpfe. Ein Balken mit Rand und Polster um
2rem-Knöpfe ist aber nie 2rem; die Messung über vier Bildschirmhöhen ergab
46 px. Gekürzt wurde das Polster, korrigiert das Budget: 2.5rem ist, was ein
richtig gebauter Balken braucht.

**Was das Budget reißt, wird gekürzt, nicht verkleinert.** Die Reihenfolge, in
der gestrichen wird, steht fest: zuerst erklärende Sätze, dann Überschriften
von Bändern, dann Angaben, die im Cockpit ohnehin stehen. Erst danach — und
nur nach ausdrücklicher Entscheidung — Schriftgrößen, und der
Nachrichteninhalt nie.

Das Budget ist messbar und gehört in die Prüfung (`GES-HOEHENBUDGET`,
Abschnitt 15).

Warum die Rückmeldung **über** der Aktionsleiste steht: Wer gerade etwas
ausgelöst hat, sucht zuerst die Antwort und dann den nächsten Griff. Stünde
sie darunter, läge sie unter dem Element, das sie erklärt.

### 4.2 Seitenkopf

Der Seitenkopf ist **eine Zeile mit Unterlinie**, kein Banner, keine Fläche.
Ein Balken mit Kicker, Titel und Erklärsatz aß auf einem flachen Bildschirm
ein Viertel der Höhe für eine Angabe, die nach dem zweiten Aufruf niemand mehr
liest.

```
NACHRICHTENWESEN                                    [Modus Locker] [Eingang]
Nachrichtenvordruck
──────────────────────────────────────────────────────────────────────────────
```

| Teil | Pflicht | Maße |
| --- | --- | --- |
| Bereichsmarke | ja | `--schrift-1`, `--stark-voll`, Versalien, Sperrung `.08em`, `--tinte-neben` |
| Titel (`h1`) | ja | `--schrift-6`, `--stark-voll`, `--tinte`, Zeilenhöhe 1.25 |
| Marken rechts | nein | Abzeichen nach Abschnitt 5.7, `margin-left: auto` |
| Unterlinie | ja | 1px `--linie-kraeftig`, Polster darunter `--abstand-3` |

Regeln:

- **Genau ein `h1` je Dokument.** Ein Rahmen (`iframe`) ist ein eigenes
  Dokument und hat seinen eigenen.
- Der Titel nennt **den Ort, nicht die Handlung**: „Nachrichtenvordruck",
  nicht „Nachricht bearbeiten". Wer denselben Ort zweimal ansteuert, soll
  denselben Titel lesen, gleich in welchem Arbeitsschritt.
- Die Bereichsmarke nennt den Bereich aus der Menüspalte, wörtlich. Sie ist
  die Antwort auf „wo bin ich" und muss deshalb mit dem übereinstimmen, was im
  Menü hervorgehoben ist.
- **Kein Erklärsatz im Seitenkopf.** Ein Satz, der beschreibt, was der Bereich
  tut, gehört an die Stelle, die ihn braucht, oder in das Handbuch. Ein Satz,
  der einen Zustand nennt („Ihre Funktion hat lesenden Zugriff"), gehört in
  einen Meldungskasten nach Abschnitt 8.
- Der Titel steht auch dann, wenn die Seite abweist. Eine Seite ohne
  Berechtigung ist keine leere Seite, sondern dieselbe Seite mit einer
  Nichtausgeführt-Meldung.

### 4.3 Überschriften

| Ebene | Größe | Stärke | Einsatz |
| --- | --- | --- | --- |
| `h1` | `--schrift-6` | 700 | Seitentitel. Genau einer. |
| `h2` | `--schrift-5` | 700 | Tafelüberschrift |
| `h3` | `--schrift-4` | 700 | Abschnitt innerhalb einer Tafel |
| `h4` | `--schrift-3` | 700, Versalien, Sperrung `.05em` | Untergruppe; tiefer geht es nicht |

Die Ebenen werden nicht übersprungen. Eine Überschrift ist eine Überschrift,
kein fett gesetzter Absatz — und ein fett gesetzter Absatz ist keine
Überschrift.

### 4.4 Tafel

Die Tafel ist der einzige Behälter für Inhalt. Es gibt keine zweite Art von
Kasten.

| Eigenschaft | Wert |
| --- | --- |
| Grund | `--grund-tafel` |
| Rand | 1px `--linie-kraeftig` |
| Radius | `--radius-3` |
| Schatten | `--schatten-tafel` |
| Innenabstand | `--abstand-5` |
| Abstand zur nächsten Tafel | `--abstand-6` |

Tafelkopf: `h2`, optional eine Bereichsmarke darüber (`--schrift-1`,
Versalien), Abstand darunter `--abstand-4`. Kein eigener Grund, keine
Trennlinie — die Tafel ist schon abgegrenzt.

Tafeln nebeneinander: `repeat(auto-fit, minmax(20rem, 1fr))`, Lücke
`--abstand-6`. Unterhalb von 20rem Spaltenbreite stehen sie untereinander.

**Eine Tafel, die nur ein Element enthält, ist keine Tafel.** Rahmen, Radius
und Innenabstand kosten 26 px Höhe und 2 px Breite für nichts. Ein einzelner
Knopf, eine einzelne Zeile Text oder eine einzelne Meldung stehen ohne
Behälter im Band.

### 4.5 Textbreite

Fließtext in einer Tafel wird auf **34rem** begrenzt (`max-width`). Das sind
rund 60 bis 70 Zeichen je Zeile. Eine Zeile über die volle Breite eines
28-Zoll-Bildschirms wird nicht gelesen, sie wird überflogen.

Tabellen und Formularraster nehmen die volle Breite. Sie sind kein Fließtext.

Der Vordruck nimmt die Breite, die sein Maßstab ergibt (Abschnitt 12.1) — er
wird weder auf 34rem gebracht noch auf die volle Breite gezogen.

---

## 5. Bedienelemente

### 5.1 Knopf

Zwei Größen, mehr nicht.

| Größe | Höhe | Polster | Schrift | Rand | Einsatz |
| --- | --- | --- | --- | --- | --- |
| **normal** | 2rem (32px) | `0 --abstand-5` | `--schrift-3` / 700 | 1px | Standard. Jede Aktionsleiste, jedes Formular |
| **klein** | 1.75rem (28px) | `0 --abstand-4` | `--schrift-2` / 700 | 1px | nur: Leiste über einer Tabelle, Zelle einer Tabellenzeile, Blätterer |

Beide: Radius `--radius-2`, `min-width` gleich der Höhe, Text mittig, kein
Schatten, `cursor: pointer`.

**1.75rem (28 px) ist die absolute Untergrenze.** WCAG 2.5.8 verlangt 24 px;
darunter liegt keine Fläche dieser Anwendung, und darüber liegt sie nur so
weit, wie L1 es zulässt. Der Bestand kennt heute fünfzehn Höhen zwischen
1.55rem und 3.5rem — 1.55rem (24.8 px) reißt die Grenze fast, 2.9rem (46.4 px)
verschenkt eine halbe Zeile.

Ein Knopf nur mit Zeichen ist quadratisch und trägt seinen Namen in
`aria-label` **und** `title`. Ein Zeichen ohne Namen ist ein Rätsel.

#### Aussehen je Rolle

Die Rollen und ihre Reihenfolge stehen in `app/ui_elements.php` und werden von
dort genommen, nicht neu erfunden. Diese Spec sagt, wie sie aussehen.

| Rolle | Rang | Fläche | Rand | Tinte |
| --- | --- | --- | --- | --- |
| `drucken` | 10 | `--handlung-sanft` | 2px `--handlung` | `--handlung-dunkel` |
| `nebenaktion` | 20 | `--handlung-sanft` | 2px `--handlung` | `--handlung-dunkel` |
| `hauptaktion` | 30 | `--handlung` | 2px `--handlung` | `#ffffff` |
| `rueckgabe` | 40 | `#a4231c` | 2px `#a4231c` | `#ffffff` |
| `abbrechen` | 50 | durchsichtig | 2px `--rand-bedienelement` | `--tinte` |
| `zurueck` | 60 | durchsichtig | 2px `--rand-bedienelement` | `--tinte` |
| `hinweis` | 70 | kein Knopf — eine Feststellung nach Abschnitt 5.7 | | |

**Genau eine Hauptaktion je Leiste.** Zwei blaue Knöpfe nebeneinander heißen
„such dir was aus", und das ist genau die Entscheidung, die unter Last
schiefgeht.

#### Zustände

| Zustand | Merkmal |
| --- | --- |
| Ruhe | wie oben |
| Zeigen | Hauptaktion und Rückgabe: Fläche eine Stufe dunkler. Zweitrangig und still: Fläche `--handlung-sanft` |
| Fokus | Doppelring nach Abschnitt 2.6, Fläche unverändert |
| Gedrückt | wie Zeigen, zusätzlich `translateY(1px)` — die einzige erlaubte Bewegung |
| Gesperrt | Fläche `--grund-gedaempft`, Rand 2px `--linie-kraeftig`, Tinte `--tinte-neben`, `cursor: not-allowed`, `disabled` |

**Ein gesperrter Knopf steht nie allein.** Neben ihm steht ein Satz, warum er
gesperrt ist. Ein Knopf, der nichts tut und nichts sagt, ist eine Sackgasse.

#### Beschriftung

- Ein Verb im Infinitiv oder ein Begriff der Vorschrift: „Absenden",
  „Anlage hinzufügen", „Alle Filter zurücksetzen".
- Die Begriffe der Ausfüllanleitung, nicht Anwendungsjargon
  (`UX-SPRACHE-VORSCHRIFT`).
- **Gleiche Bedeutung heißt überall gleiche Beschriftung.** „Absenden" ist
  nicht an einer Stelle „Senden" und an der nächsten „Abschicken".

### 5.2 Aktionsleiste

Eine Zeile, Lücke `--abstand-4`, Ausrichtung links, `flex-wrap: wrap`.

- Reihenfolge **immer** nach Rang aus `app/ui_elements.php`, links nach
  rechts. Fehlt eine Rolle, rückt nichts nach: Die Stelle ergibt sich aus dem
  Rang, nicht aus der Reihenfolge der Ausgabe. Genau deshalb sind die Ränge
  gespreizt (10, 20, 30 …) — eine neue Rolle passt dazwischen, ohne dass sich
  eine bestehende bewegt.
- **Die Knöpfe brechen nicht um, die Leiste bricht um.** `white-space: nowrap`
  am Knopf, `flex-wrap: wrap` an der Leiste.
- Über einem langen Formular klebt die Leiste am oberen Rand ihres
  Scrollbereichs (`position: sticky; top: 0`). Am Fuß des Formulars steht
  dieselbe Leiste ein zweites Mal, ohne Kleben — wer unten fertig wird, soll
  nicht hochscrollen.
- Die klebende Leiste bekommt den Grund der Seite und eine 1px-Unterlinie in
  `--linie-kraeftig`, sonst schiebt sich der Inhalt bei dichtem Satz sichtbar
  darunter durch.

### 5.3 Verweis

Im Fließtext: `--handlung-dunkel`, unterstrichen,
`text-underline-offset: 0.15em`. Beim Zeigen zusätzlich `--handlung-sanft` als
Fläche.

**Nicht `--handlung`.** Das ist die Flächenfarbe; als Schrift verfehlt sie auf
den dunkleren hellen Gründen die 7:1-Grenze (Abschnitt 2.4).

In einer Tabellenzelle: Farbe geerbt, **nicht** unterstrichen — eine Tabelle,
in der jede Zelle einen Strich trägt, ist unlesbar. Der Strich kommt beim
Zeigen und bei Fokus.

Ein Verweis, der wie ein Knopf aussieht, ist ein Knopf im Aussehen und ein
Verweis im Verhalten: Er trägt `href`, öffnet mit der Eingabetaste und lässt
sich in einem neuen Reiter öffnen. Wer eine Handlung auslöst, nimmt ein
`<button>` in einem `<form>` — nie einen Verweis.

### 5.4 Eingabefeld, Auswahlfeld, Textfeld

| Eigenschaft | Wert |
| --- | --- |
| Höhe | 2rem |
| Polster | `--abstand-3 --abstand-4` |
| Rand | 1px `--rand-bedienelement` |
| Radius | `--radius-2` |
| Grund | `--grund-tafel` |
| Schrift | `--schrift-3` |
| Farbe | `--tinte` |
| Breite | `width: 100%` in seiner Gruppe |

**Ausnahme nach L2:** Die Felder für Betreff und Nachrichteninhalt tragen
`--schrift-4`. Was man später lesen muss, schreibt man in lesbarer Größe.

Textfeld (`textarea`): mind. 3 Zeilen, `resize: vertical`.

**Aufbau einer Feldgruppe**, von oben nach unten:

```
Beschriftung                        --schrift-3 / 600 / --tinte
[ Feld                            ] Höhe 2rem
Ein Satz, was einzutragen ist.      --schrift-2 / --tinte-neben
```

Abstand Beschriftung → Feld: `--abstand-1`. Feld → Hilfstext:
`--abstand-1`. Abstand zur nächsten Feldgruppe: `--abstand-5`.

- Die Beschriftung ist ein `<label for>`. Immer. Ein Platzhalter ersetzt keine
  Beschriftung — er verschwindet beim Tippen, und dann steht dort nichts mehr.
- Der Hilfstext hängt per `aria-describedby` am Feld und sagt, **was**
  einzutragen ist, nicht **wie das Bedienelement funktioniert**
  (`UX-INFOPOINTER`).
- Der Hilfstext ist die Stelle, an der bei dichtem Satz am ehesten gespart
  wird. Er bleibt: Er ist billiger als eine Rückweisung.
- **Kein `opacity` unter 1 auf Text.** Ein blasser Platzhalter oder Hilfstext
  ist der häufigste Verstoß gegen L2, und er trifft ausgerechnet die Stelle,
  an der jemand nicht weiß, was einzutragen ist.
- Platzhalter in `--tinte-neben`, `opacity: 1`. Ein Beispiel, kein Befehl:
  „z. B. TBB-Nachweis 142".

#### Pflicht, Fehler, Sperre

| Zustand | Merkmal ohne Farbe | Farbe | Auszeichnung |
| --- | --- | --- | --- |
| Pflicht | Marke „Pflicht" hinter der Beschriftung | — | `required` |
| Zuständig | Rand 1px durchgezogen, Beschriftung 600 | — | — |
| Fremd / gesperrt | Rand 1px **gestrichelt**, Beschriftung 400, **Tinte bleibt `--tinte`**, Wort „gesperrt" | Grund `--grund-gedaempft` | `readonly` oder `disabled` + `aria-disabled` |
| Fehler | Rand **2px** statt 1px, Marke „Fehler" vor dem Satz | Rand `#a4231c`, Satz `#7d1a14` | `aria-invalid="true"`, `aria-describedby` |

Das ist die Umsetzung von `UX-MEINE-FELDER-OHNE-FARBE`: Rahmenstärke,
Rahmenart und Schriftstärke unterscheiden die Zustände auch dann, wenn keine
Farbe ankommt.

### 5.5 Ankreuzfeld und Auswahlknopf

Kästchen 1.125rem, Rand 1px `--rand-bedienelement`, Radius `--radius-1`
(Auswahlknopf rund). Gesetzt: Fläche `--handlung`, Haken bzw. Punkt in Weiß.

Die **Beschriftung ist Teil der Klickfläche** und diese ist mind. 1.75rem hoch.
Ein 1.125rem großes Kästchen ist mit der Maus im Einsatz nicht sicher zu
treffen; das Wort daneben ist es. Das ist der Grund, warum das Kästchen selbst
klein sein darf und die Klickfläche nicht.

Mehrere Ankreuzfelder stehen in einem `<fieldset>` mit `<legend>`.

### 5.6 Feldraster

```
display: grid;
grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
gap: --abstand-5;
```

Ein Feld, das mehr Platz braucht (Nachrichteninhalt, Anschrift), bekommt
`grid-column: 1 / -1`. Feste Spaltenzahlen gibt es nicht — sie brechen genau
dann, wenn die Spalte schmaler wird als angenommen.

**Alles, was ein Arbeitsschritt auszufüllen verlangt, steht auf einer Seite**
(`UX-EINE-SEITE`). Kein Assistent, keine Reiter, kein Nachladen, kein
Weiterblättern zum Absenden.

### 5.7 Marke und Abzeichen

**Abzeichen** (Badge) — sagt einen Zustand, ist nicht anklickbar:

| Eigenschaft | Wert |
| --- | --- |
| Höhe | 1.25rem |
| Polster | `--abstand-1 --abstand-3` |
| Schrift | `--schrift-2` / 700 |
| Radius | `--radius-pille` |
| Rand | 1px |
| Fläche, Rand, Tinte | aus der Zustands- oder Vorrangtabelle (Abschnitt 2.4) |

Ein Abzeichen, das eine Vorrangstufe trägt, führt sein Zeichen (▲ ◆ ■) als
`::before` mit `aria-hidden` und die Stufe als Wort.

**Marke** (Chip) — sagt einen gesetzten Filter und **entfernt ihn beim Klick**:

| Eigenschaft | Wert |
| --- | --- |
| Höhe | 1.75rem |
| Polster | `--abstand-2 --abstand-4` |
| Schrift | `--schrift-2` / 700 |
| Radius | `--radius-pille` |
| Fläche / Rand / Tinte | `--handlung-sanft` / 1px `--handlung-kante` / `--handlung-dunkel` |
| Zeichen | `×` als `<span aria-hidden="true">` |
| Name | `aria-label="<Feld> <Wert> entfernen"` |

Die Marke ist ein `<button type="submit">` in demselben Formular wie die
Filter — sie funktioniert damit ohne JavaScript.

1.75rem ist die Untergrenze aus L2 und wird hier genau erreicht: Eine Reihe
aus acht Marken zu je 44 px fräße auf einem 600-px-Schirm ein Sechstel der
Höhe; zu je 28 px ist es ein Zehntel. Tiefer geht es nicht — WCAG 2.5.8 setzt
24 px, und die Marke ist ein Griff, kein Schild.

**Feststellung** (Rolle `hinweis` der Aktionsleiste) — kein Knopf, sondern der
Satz „Hier gibt es nichts zu tun": `--schrift-3`, `--tinte-neben`, kein Rand,
keine Fläche. Sie steht an der Stelle, an der sonst ein Knopf stünde, damit
die Leiste nicht leer wirkt.

### 5.8 Aufklapp

`<details>`/`<summary>`. Grund `--grund-tafel`, Rand 1px `--linie-kraeftig`,
Radius `--radius-3`, Polster `--abstand-4 --abstand-5`.

Der `summary` ist 2rem hoch, `--schrift-3` / 700, `--handlung-dunkel`,
`cursor: pointer`, und trägt ein Dreieck, das sich beim Öffnen dreht. Ein
zugeklappter Aufklapp ist damit 2rem hoch und nicht mehr — er ist die
günstigste Art, Platz zu sparen, ohne etwas zu verstecken.

**Ein Aufklapp steht offen, wenn darin etwas gesetzt ist**, und sagt es im
Namen („Weitere Filter und Sortierung · aktiv"). Ein zugeklappter Kasten, in
dem ein wirksamer Filter versteckt liegt, erzeugt genau die Frage „warum sehe
ich meine Nachricht nicht".

Pflichtfelder liegen nie in einem Aufklapp.

---

## 6. Tabelle

Eine Tabelle im eStab ist eine Liste von Vorgängen, keine Tapete aus Zahlen.
Sie hat immer denselben Aufbau.

```
┌─ Rahmen: Tafel, Radius --radius-3, eigener Scrollbereich ───────────┐
│ TBB-NACHWEIS │ ZEIT  │ VON/AN      │ INHALT        │ STAND │ AKTION │ ← Kopf klebt
├──────────────┼───────┼─────────────┼───────────────┼───────┼────────┤
│ 1079         │ 251528│ S4 → Presse │ Rückmeldung … │ offen │ Öffnen │
│ 1074         │ 251516│ THW → …     │ Meldung …     │ offen │ Öffnen │ ← Zebra
└──────────────┴───────┴─────────────┴───────────────┴───────┴────────┘
  168 Treffer · Sortierung: Vorrang, dann neueste      ◀ 1 2 3 ▶
```

### 6.1 Rahmen

| Eigenschaft | Wert |
| --- | --- |
| Grund | `--grund-tafel` |
| Rand | 1px `--linie-kraeftig` |
| Radius | `--radius-3` |
| Schatten | `--schatten-tafel` |
| Höhe | `max-height: min(74vh, 54rem)`, `overflow: auto`, `overscroll-behavior: contain` |

Die Tabelle selbst: `border-collapse: separate; border-spacing: 0;
table-layout: fixed; width: 100%; min-width: 68rem`.

`table-layout: fixed` ist Pflicht. Ohne sie berechnet der Browser die
Spaltenbreiten aus dem Inhalt, und dieselbe Liste sieht auf der nächsten Seite
anders aus — die Spalten wandern, sobald ein längerer Rufname auftaucht.

Der Rahmen scrollt quer, nicht die Seite. **Die Inhaltsspalte scrollt nie
waagerecht.**

### 6.2 Kopfzeile

| Eigenschaft | Wert |
| --- | --- |
| Grund | `--grund-gedaempft` |
| Tinte | `--tinte` |
| Schrift | `--schrift-1` / 700, Versalien, Sperrung `.045em` |
| Polster | `--abstand-2 --abstand-4` |
| Unterlinie | 2px `--linie-kraeftig` |
| Position | `position: sticky; top: 0; z-index: 2` |

Jede Kopfzelle ist ein `<th scope="col">`. Wer nach einer Spalte sortieren
kann, findet im `<th>` einen Knopf mit `aria-sort`; Sortieren geschieht über
das Filterformular (Abschnitt 7), nicht über eine eigene Mechanik.

### 6.3 Spaltenbreiten

Als Prozentwerte am `<th>`, Summe 100. Die Inhaltsspalte bekommt den Rest.
Beispiel Meldungsliste:

| Spalte | Breite |
| --- | --- |
| TBB-Nachweis | 12% |
| Zeit | 12% |
| Von/An | 14% |
| **Inhalt** | **25%** |
| Stand | 10% |
| Kenntnis | 9% |
| Empfänger | 10% |
| Aktion | 8% |

Gleich breite Spalten sind verboten: „Aktion" braucht so viel Platz wie das
Wort „Öffnen", „Inhalt" so viel wie möglich.

### 6.4 Zellen

| Eigenschaft | Wert |
| --- | --- |
| Polster | `--abstand-2 --abstand-4` |
| Ausrichtung | `text-align: left; vertical-align: top` |
| Schrift | `--schrift-3`; Betreff und Nachrichteninhalt `--schrift-4` |
| Umbruch | `overflow-wrap: anywhere` |
| Trennlinie | 1px `--linie` unten; die letzte Zeile hat keine |

Zahlen, Zeiten und Nachweisnummern: `tabular-nums`, rechtsbündig.

**Text in einer Zelle steht auf höchstens zwei Zeilen** (`-webkit-line-clamp:
2`). Der Rest kommt über einen Aufklapp in derselben Zelle. Eine Zeile, die
zehn Zeilen hoch ist, macht die Liste unbrauchbar — und der ganze Text steht
ohnehin im Vordruck, den ein Klick öffnet.

Zwei Zeilen statt drei ist die dichteste Stelle, an der L1 und L2 sich
berühren, und die Auflösung ist hier keine Verkleinerung, sondern eine
Kürzung: Der Betreff steht vollständig, der Nachrichtentext angeschnitten.
Angeschnittener Text in lesbarer Größe schlägt vollständigen Text in
unlesbarer.

Eine Zelle enthält **kein** Formular je Zelle. Der Öffnen-Griff steht **einmal**
in der Aktionsspalte als kleiner Knopf. Der Bestand baut heute jede Zelle als
eigenes Formular mit Knopf — das erzeugt pro Zeile acht Bedienelemente, die
ein Vorleseprogramm alle ansagt, und ist die Ursache der Überlaufbefunde in
`tools/bedienpruefung/blick/bilder/befunde-*.json`.

### 6.5 Zeilenzustände

| Zustand | Merkmal ohne Farbe | Fläche |
| --- | --- | --- |
| Ruhe | — | `--grund-tafel` |
| jede zweite | — | `--grund-zebra` |
| Zeigen / Fokus in der Zeile | linke Kante 3px `--handlung-kante` | `--handlung-sanft` |
| Vorrang | linke Kante **4px** + Zeichen im Abzeichen | Vorrangfläche (Abschnitt 2.4) |

Die Vorrangkante ist 4px und schlägt damit die Zeigekante: Eine Zeile mit
Staatsnot bleibt auch dann als solche erkennbar, wenn der Zeiger darüber steht.

`:focus-within` verhält sich wie `:hover`. Wer sich mit dem Tabulator durch die
Liste bewegt, sieht dieselbe Zeile hervorgehoben wie mit der Maus.

### 6.6 Leere Liste

Kein leerer Rahmen. Eine Tafel mit

1. einem Satz, **was** fehlt („Keine Nachricht entspricht den gesetzten
   Filtern."),
2. einem Satz, **woran** es liegen kann, wenn Filter gesetzt sind,
3. einem Knopf, der den nächsten Griff anbietet: „Alle Filter zurücksetzen".

Eine leere Liste ohne Ausweg ist die häufigste Stelle, an der jemand die
Anwendung verlässt.

### 6.7 Fußleiste und Blätterer

Eine Zeile unter dem Rahmen, `--abstand-3` Abstand, `flex-wrap: wrap`,
`justify-content: space-between`.

Links: Trefferzahl in einem `<p role="status">` — „168 Nachrichten, Seite 2
von 7". Daneben die Sortierung im Klartext und der Stand („Zuletzt
aktualisiert: 26.08.2026 03:21").

Rechts: der Blätterer als `<nav aria-label="Ergebnisseiten">`. Kleine Knöpfe
(1.75rem), Reihenfolge fest: erste · zurück · Seitenangabe · vor · letzte. Die
aktuelle Seite ist Text mit `aria-current="page"`, kein Knopf. Nicht
verfügbare Griffe sind `disabled`, nicht weggelassen — sonst wandern die
übrigen.

### 6.8 Schmale Fenster

Unter **48rem** Spaltenbreite wird die Tabelle zu Karten: eine Karte je Zeile,
jede Zelle mit ihrer Kopfbezeichnung davor.

Gemessen wird die **Spalte, nicht das Fenster** — über eine Container-Anfrage
am Rahmen, wie beim Vordruck (Abschnitt 12.1). Das ist der Unterschied, an dem
sonst Verwechslungen entstehen: Ein 1366-px-Fenster hat eine Inhaltsspalte von
rund 59rem, ein 1024-px-Fenster mit weggerücktem Cockpit noch rund 50rem. Die
Tabelle bleibt in beiden Fällen eine Tabelle; zu Karten wird sie erst unter
etwa 800 px Fensterbreite.

```
td::before { content: attr(data-label) ": "; font-weight: 700; }
```

Jede Zelle trägt dafür `data-label` mit demselben Text wie ihr `<th>`. Die
Reihenfolge der Angaben bleibt die Reihenfolge der Spalten.

---

## 7. Suche und Filter über einer Tabelle

Jede filterbare Liste im eStab trägt denselben Aufbau. Sechs Bänder, immer in
dieser Reihenfolge, immer in **einem** `<form>`.

```
┌─ 1  Suchzeile ─────────────────────────────────────────────────────┐
│  Nachrichten durchsuchen                                           │
│  [ z. B. Überschrift, TBB-Nachweis 142 oder Rufname ]  [ Suchen ]  │
│  Durchsucht Überschrift, Nachweisnummer, Rufname, Von, An, Text.   │
├─ 2  Schnellfilter ─────────────────────────────────────────────────┤
│  Richtung [Alle ▾]  Vorrang [Alle ▾]  Stand [Alle ▾]  Kenntnis [▾] │
├─ 3  Weitere Filter und Sortierung · aktiv ────────────────────  ▾  │
│  Von [    ] Bis [    ] Empfänger [▾] Sortierung [▾] Pro Seite [▾]  │
├─ 4  Leiste ────────────────────────────────────────────────────────┤
│  [ Filter anwenden ]  [ Alle Filter zurücksetzen ]                 │
├─ 5  Aktive Filter ─────────────────────────────────────────────────┤
│  ( Vorrang: Blitz × ) ( Richtung: Ausgang × )                      │
├─ 6  Ergebnisleiste ────────────────────────────────────────────────┤
│  168 Nachrichten · Sortierung: Vorrang, dann neueste · Stand 03:21 │
└────────────────────────────────────────────────────────────────────┘
```

Lücke zwischen den Bändern: `--abstand-5`.

Der ganze Block ist im Ruhezustand **höchstens 13rem** hoch (Höhenbudget,
Abschnitt 4.1). Er steht über der Liste und nimmt ihr Höhe weg; er ist die
teuerste Stelle der Seite und wird entsprechend eng gehalten.

### Band 1 — Suchzeile

```
display: grid;
grid-template-columns: minmax(14rem, 1fr) auto;
gap: --abstand-4;
align-items: end;
```

- **Ein** Suchfeld, `type="search"`, volle Breite, Höhe 2rem,
  `maxlength="120"`, `autocomplete="off"`, `enterkeyhint="search"`.
- Beschriftung darüber, sichtbar. Platzhalter als Beispiel.
- Rechts der Knopf „Suchen" als Hauptaktion, Höhe 2rem, bricht nicht um.
- Darunter ein Satz in `--schrift-2` / `--tinte-neben`, der **aufzählt, welche
  Felder durchsucht werden**, per `aria-describedby` am Feld. Ohne diesen Satz
  ist eine leere Trefferliste nicht deutbar: Niemand weiß, ob er das falsche
  Wort oder das falsche Feld gesucht hat.

### Band 2 — Schnellfilter

`<fieldset>` mit `<legend class="estab-visually-hidden">Schnellfilter</legend>`.

Die Überschrift ist **nur für Vorleseprogramme sichtbar**. Sie kostete eine
volle Zeile, um zu benennen, was vier beschriftete Auswahlfelder ohnehin
zeigen — das ist genau die Art Höhe, die L1 holt, ohne dass L2 etwas verliert.

```
display: flex; flex-wrap: wrap; gap: --abstand-4; align-items: end;
```

Jede Auswahl: `flex: 1 1 9rem; min-width: 8rem`, Beschriftung darüber, Feld
2rem hoch.

- **Höchstens fünf** Schnellfilter. Was darüber hinausgeht, gehört in Band 3.
- Der erste Eintrag jeder Auswahl ist eine Alle-Stufe und heißt sie auch:
  „Alle Vorrangstufen", nicht „—".
- Nur Auswahlfelder. Ein Datumsfeld ist kein Schnellfilter.

### Band 3 — Weitere Filter und Sortierung

`<details>` nach Abschnitt 5.8. Steht offen und trägt „· aktiv" im Namen,
sobald darin etwas gesetzt ist.

```
display: grid;
grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
gap: --abstand-4;
```

Hier stehen Zeitraum, Empfängerfunktion, Sortierung und Nachrichten pro Seite.

Zugeklappt ist dieses Band 2rem hoch. Es steht in derselben Zeile wie die
Leiste aus Band 4, solange die Spalte dafür breit genug ist.

### Band 4 — Leiste

„Filter anwenden" (Hauptaktion) und „Alle Filter zurücksetzen" (still), beide
2rem. Die Leiste teilt sich eine Zeile mit dem zugeklappten Band 3.

**Ein Filter greift erst auf „Filter anwenden".** Kein Absenden beim Ändern
einer Auswahl. Ein Formular, das sich beim Tippen selbst abschickt, ist ohne
JavaScript nicht bedienbar (`UX-OHNE-JAVASCRIPT`) und mit JavaScript
unvorhersehbar — es reißt die Seite unter dem weg, der gerade den zweiten
Filter setzt.

### Band 5 — Aktive Filter

Überschrift „Aktive Filter" (`--schrift-3` / 700), daneben die Marken nach
Abschnitt 5.7. Jede Marke nennt **Feld und Wert** („Vorrang: Blitz") und
entfernt beim Klick genau diesen einen Filter.

Das Band entfällt vollständig, wenn kein Filter gesetzt ist — keine leere
Zeile, keine Überschrift ohne Inhalt.

### Band 6 — Ergebnisleiste

- Trefferzahl in `<p role="status">`, damit ein Vorleseprogramm die neue Zahl
  nach dem Filtern ansagt.
- Sortierung im Klartext: „Sortierung: Vorrang, dann neueste".
- Stand mit Datum und Uhrzeit.

### Was für alle Bänder gilt

- **Ein Formular, `method="get"`.** Der Zustand einer gefilterten Liste steht
  damit in der Adresse und lässt sich weitergeben und als Lesezeichen ablegen.
- Jedes Feld hat ein sichtbares `<label for>`.
- Die Tabulatorreihenfolge läuft Band für Band von oben nach unten.
- Nach dem Absenden steht der Fokus auf der Trefferzahl, nicht am Seitenanfang.

---

## 8. Rückmeldungen und Meldungskästen

Vier Arten, ein Aufbau.

| Art | Wort im Kasten | Fläche | Kante links | Tinte |
| --- | --- | --- | --- | --- |
| Hinweis | „Hinweis" | `#e2edfa` | 6px `#1d5687` | `#153f66` |
| Erledigt | „Erledigt" | `#e6f4ea` | 6px `#1f7a3d` | `#155c2d` |
| Achtung | „Achtung" | `#fdf2d8` | 6px `#a97b0b` | `#6b4d05` |
| Nicht ausgeführt | „Nicht ausgeführt" | `#fdeceb` | 6px `#a4231c` | `#7d1a14` |

| Eigenschaft | Wert |
| --- | --- |
| Rand | 1px in der Kantenfarbe, links 3px |
| Radius | `--radius-2` |
| Polster | `--abstand-4 --abstand-5` |
| Überschrift | `--schrift-3` / 700 — das Wort aus der Tabelle |
| Text | `--schrift-4` / 400 |
| Höhe | höchstens 4rem im Ruhezustand |

**Der Text bleibt `--schrift-4`.** Ein Meldungskasten ist die Stelle, an der
die Anwendung sagt, was schiefgegangen ist oder was als Nächstes ansteht — L2
gilt hier ohne Abzug. Gespart wird am Kasten: schmalerer Rand, kleineres
Polster, das Wort eine Stufe kleiner als der Satz.

Der Kasten trägt **keine eigene Tafel** um sich. Er ist selbst der Behälter.

**Das Wort ist Pflicht.** Es ist der Träger der Bedeutung, wenn keine Farbe
ankommt — auf einem Schwarzweißausdruck, bei Farbfehlsichtigkeit, bei
erzwungenen Farben.

Der Kasten steht in Band 2 der Seite (Abschnitt 4.1), trägt `role="status"`
(Hinweis, Erledigt) bzw. `role="alert"` (Achtung, Nicht ausgeführt) und erhält
nach dem Laden den Fokus, wenn er eine Rückweisung meldet.

### 8.1 Rückmeldung nach einem Arbeitsschritt

`UX-RUECKMELDUNG` verlangt zwei Sätze, und beide sind Pflicht:

1. **Was geschehen ist.** „Die Nachricht ist an den Sichter gegangen."
2. **Was als Nächstes ansteht.** „Der nächste Schritt ist die Sichtung durch
   Si. Sie können den nächsten Eingang aufnehmen."

Darunter die Griffe, die jetzt sinnvoll sind, als Aktionsleiste.

### 8.2 Rückweisung eines Formulars

`UX-RUECKWEISUNG` verlangt Feld und Grund im Klartext, eine Übersicht und den
Fokus auf dem ersten betroffenen Bedienelement.

Der Kasten „Nicht ausgeführt" steht am Seitenkopf und enthält:

- einen Satz, wie viele Felder betroffen sind,
- eine Liste, ein Eintrag je Feld, jeder Eintrag ein Verweis auf die **Kennung
  des Bedienelements**, nicht auf die Zelle,
- je Eintrag einen vollen Satz: „Feld 9 Vorrangstufe: Bitte wählen Sie eine
  Vorrangstufe, auch wenn es keine gibt."

Am Feld selbst steht derselbe Satz noch einmal, mit `aria-describedby` und
`aria-invalid="true"`. Der Fokus springt beim Laden auf das erste betroffene
Bedienelement.

Eine Rückweisung nennt **nie** eine technische Meldung, eine Feldnummer der
Datenbank oder einen Klassennamen.

---

## 9. Zustände ohne Farbe

`UX-MEINE-FELDER-OHNE-FARBE` verlangt, dass Farbfehlsichtigkeit, Sonnenlicht
und ein Schwarzweißausdruck die Zuordnung nicht zerstören. Diese Tabelle ist
die vollständige Liste. **Jeder Zustand der Anwendung steht darin, und jeder
hat einen Träger, der keine Farbe ist.**

| Zustand | Träger ohne Farbe | Farbe (zusätzlich) |
| --- | --- | --- |
| Aktuelle Seite im Menü | linke Kante 3px + `aria-current="page"` | Gold |
| Fokus | 4px Doppelring (2px innen, 2px außen) | Gold und Dunkel |
| Hauptaktion | 700, gefüllte Fläche, Rang 30 in der Leiste | Blau |
| Gefahr (Rückgabe) | Rang 40, Wort im Namen („zurück an …") | Rot |
| Gesperrter Knopf | `disabled` + Satz daneben | grau |
| Zuständiges Feld | Rand 1px durchgezogen, Beschriftung 600 | — |
| Fremdes Feld | Rand 1px **gestrichelt**, Wort „gesperrt", Tinte unverändert | grauer Grund |
| Pflichtfeld | Marke „Pflicht" hinter der Beschriftung | — |
| Feld mit Fehler | Rand 2px, Marke „Fehler", voller Satz | Rot |
| Vorrang Sofort | ▲ + Wort „Sofort" | Gelb |
| Vorrang Blitz | ◆ + Wort „Blitz" | Orange |
| Vorrang Staatsnot | ■ + Wort „Staatsnot" | Rot |
| Vorrangzeile | linke Kante 4px | Vorrangfläche |
| Zeigezeile | linke Kante 3px | Blau |
| Gelesen / ungelesen | Wort + Schriftstärke 700 bei ungelesen | — |
| Erledigt / offen | Wort | — |
| Meldungskasten | das Wort im Kasten | Fläche und Kante |
| Erzwungene Farben | Ränder und Ringe bleiben, weil `outline` und `border` benutzt werden, nicht `box-shadow` allein | — |

**Prüfregel:** Ein Bildschirmfoto in Graustufen muss jeden dieser Zustände
noch unterscheidbar zeigen. Was dabei verschwindet, ist ein Mangel.

---

## 10. Zugänglichkeit

Diese Regeln sind nicht verhandelbar; sie stehen als Bedienregeln in
`SPEC.md` M9 E.

- **Kontrast.** Text ≥ **7:1** gegen seinen **tatsächlichen** Grund, nicht
  gegen den Grund der Seite; **4.5:1 wird nie unterschritten**. `UX-KONTRAST`
  trägt beide Stufen. Der Grund steht in L2: Der gemessene Wert ist der beste
  Fall, der Bildschirm im Einsatzraum nicht. Ränder von Bedienelementen und
  Fokusringe ≥ 3:1. Abschnitt 2.4 ist durchgerechnet; jede neue Farbe wird
  ebenso gerechnet, **bevor** sie im Stylesheet steht.
- **Keine gedämpfte Schrift.** Kein `opacity` auf Text, kein Grau für
  „weniger wichtig". Rangfolge über Größe, Stärke und Ort.
- **Schreibgeschützt heißt nicht unwichtig.** Ein `readonly`-Feld behält
  vollen Textkontrast. Im Vordruck trägt es die Vermerke der vorherigen
  Station, die die nächste lesen muss. Nur ein `disabled`-Knopf, der keinen
  Inhalt trägt, darf gedämpft sein.
- **Tastatur.** Jedes Bedienelement erreichbar und auslösbar. Die
  Tabulatorreihenfolge folgt der Feldfolge des Vordrucks (`UX-TASTATUR`).
  `tabindex` größer 0 ist verboten.
- **Ohne JavaScript.** Aufnehmen, Sichten und Befördern einer Nachricht
  funktionieren ohne JavaScript (`UX-OHNE-JAVASCRIPT`). Filter, Blätterer,
  Aufklapp und Marken sind Formulare und Verweise. JavaScript darf Komfort
  hinzufügen — Vorschlagslisten, Zähler —, nie eine Funktion tragen.
- **Bereiche.** Genau ein `<main>`, die Menüspalte als `<nav>`, jede Tafel mit
  eigener Überschrift. Ein Sprungverweis „Zum Inhalt" als erstes fokussierbares
  Element, sichtbar bei Fokus.
- **Sprache.** `lang="de"` am `<html>`. Ohne sie liest ein Vorleseprogramm
  deutsche Texte englisch, und `hyphens: auto` greift nicht.
- **Namen.** Jedes Bedienelement hat einen zugänglichen Namen. Ein Zeichen
  ohne `aria-label` gilt als Mangel.
- **Bewegung.** `prefers-reduced-motion` wird beachtet (Abschnitt 2.8).
- **Erzwungene Farben.** `forced-colors: active` erhält Ränder und Ringe.
  Bedeutung, die nur an einer Fläche hängt, geht dort verloren — deshalb
  Abschnitt 9.

---

## 11. Drucken

Ein Ausdruck ist im Stab ein Arbeitsmittel, kein Nebenprodukt.

- Menüspalte und Cockpitspalte werden **nicht** gedruckt.
- Die Inhaltsspalte druckt ohne Höhenbegrenzung und ohne eigenen
  Scrollbereich; eine Tabelle druckt vollständig, nicht nur die sichtbaren
  Zeilen.
- Kein Schatten, kein Farbverlauf. Flächen, die Bedeutung tragen, drucken als
  Rand.
- Tabellenkopf wiederholt sich auf jeder Seite: `thead { display: table-header-group; }`.
- Zeilen brechen nicht um: `tr, .estab-tafel { break-inside: avoid; }`.
- Verweise drucken ihre Adresse nicht mit — im Stab wird das Blatt gelesen,
  nicht angeklickt.
- Der Nachrichtenvordruck druckt in einem **festen** Maßstab, der ihn auf A4
  bringt (Abschnitt 12.6) — nicht in dem, der gerade auf dem Bildschirm gilt.
- **Auf Papier gilt L1 nicht.** Ein Blatt kostet nichts an Bildschirmhöhe.
  Zellenpolster und Zeilenhöhen dürfen im Druck eine Stufe größer stehen; die
  Lesbarkeit eines Ausdrucks, der im Einsatzraum herumgereicht wird, ist mehr
  wert als eine gesparte Seite.

---

## 12. Ausnahme: der Nachrichtenvordruck

`UX-PAPIERBILD` verlangt, dass die Oberfläche den Vordruck so zeigt, wie er
auf Papier aussieht. Ein Papierfaksimile ist kein Formular im Sinne dieser
Spec, sondern ein **Raster**, und ein Raster fließt nicht.

### 12.1 Das Blatt wird skaliert, nicht gestaucht

Wenn ein Blatt nicht in die Spalte passt, gibt es drei Möglichkeiten. Nur eine
davon erhält das Papierbild:

| Was passieren könnte | Folge | Zulässig |
| --- | --- | --- |
| **Abschneiden** — das Blatt steht in voller Größe da, der Rest liegt hinter dem Rand | Wer die rechte Hälfte sehen will, schiebt seitwärts. Der Laufzettel ist unsichtbar, bis jemand ihn sucht. | nein |
| **Stauchen** — Spalten schrumpfen ungleich, Felder wandern, Zeilen brechen um | Die Feldfolge bleibt, das Bild geht. Wer den Papiervordruck kennt, erkennt ihn nicht wieder. | nein |
| **Skalieren** — das ganze Blatt wird gleichmäßig kleiner gezogen | Alle Verhältnisse bleiben, kein Feld wandert, nichts wird abgeschnitten. So, wie man ein Blatt Papier weiter weghält. | **ja** |

**Das Blatt wird also als Ganzes skaliert.** Es behält sein inneres Raster von
56rem; was sich ändert, ist allein der Maßstab, in dem dieses Raster dargestellt
wird.

Der Maßstab kommt aus der Breite des umgebenden Kastens, nicht aus der des
Fensters — der Kasten misst sich selbst:

```css
.estab-message-form-scroll {          /* der Rahmen um das Blatt */
    container-type: inline-size;
}

.estab-official-message-form {        /* das Blatt */
    width: 56rem;
    min-width: 56rem;
    zoom: min(1, calc(100cqw / 56rem));
}
```

Zwei Eigenschaften dieser Regel sind Absicht und keine Nebensache:

- **Nie größer als 1.** Auf einem breiten Bildschirm wird das Blatt nicht
  aufgeblasen. Ein Vordruck in Übergröße sieht falsch aus und gewinnt nichts —
  die freie Fläche geht an den Bearbeitungsweg daneben.
- **Der Maßstab hängt am Kasten, nicht am Fenster.** Nur so stimmt er auch
  dann, wenn neben dem Blatt der Bearbeitungsweg steht oder die Cockpitspalte
  eingeklappt ist.

### 12.2 Untergrenze des Maßstabs

Hier stößt L1 an L2, und der Konflikt ist echt: **Wer das Blatt skaliert,
skaliert seine Schrift mit.** Das Blatt trägt heute Schriftgrößen bis herunter
zu `0.43rem` — bei Maßstab 1 sind das 6.9 px, bei Maßstab 0.6 noch 4.1 px.
Das ist nicht mehr lesbar, und damit verstößt eine unbegrenzte Skalierung
gegen L2.

Deshalb zwei Regeln, die zusammengehören:

1. **Tragende Angaben im Blatt sind mindestens `0.875rem`.** Feldinhalte,
   Feldbeschriftungen, Vermerke, Vorrangstufen — alles, was jemand lesen muss,
   um zu handeln. Der Bestand unterschreitet das an mehreren Stellen und ist
   dort zu heben.
2. **Der Maßstab fällt nicht unter 0.75.** Damit bleibt eine tragende Angabe
   auch im kleinsten Maßstab bei 10.5 px. Reicht die Spalte für Maßstab 0.75
   nicht mehr, hört das Skalieren auf und **der Rahmen scrollt waagerecht**.

```css
zoom: max(0.75, min(1, calc(100cqw / 56rem)));
```

Das Scrollen ist der letzte Ausweg und tritt spät ein. Maßstab 0.75 entspricht
672 nutzbaren Bildpunkten im Rahmen. Aus den Spaltenbreiten aus Abschnitt 3
und den Innenabständen dieser Spec gerechnet:

| Fensterbreite | Hülle | Rahmen | Maßstab |
| --- | --- | --- | --- |
| 1920 px | drei Spalten | 1443 px | 1 (gedeckelt) |
| 1366 px | drei Spalten | 889 px | **0.99** |
| 1280 px | drei Spalten | 803 px | 0.90 |
| 1024 px | Cockpit unten | 747 px | 0.83 |
| 896 px | Menü als Aufklapp | 835 px | 0.93 |
| 800 px | Menü als Aufklapp | 739 px | 0.83 |
| 733 px | Menü als Aufklapp | 672 px | **0.75** — Untergrenze |
| darunter | Menü als Aufklapp | — | 0.75, Rahmen scrollt |

**Auf einem 1366-px-Laptop — dem Gerät, um das es hier geht — steht das Blatt
praktisch in Maßstab 1.** Das ist eine Folge von L1: Weil die Menü- und
Cockpitspalte 3rem und 6rem schmaler geworden sind, bleibt der Inhaltsspalte
genug Breite für das ungeschrumpfte Raster. Die Dichte der Hülle bezahlt sich
unmittelbar im Vordruck aus.

Der Sprung bei 896 px — der Maßstab **steigt** von 0.83 auf 0.93 — ist kein
Fehler: Dort weicht die Menüspalte (Abschnitt 3.3) und gibt ihre 216 px an das
Blatt ab.

**Kleinstschrift, die zum Papierbild gehört, ist davon ausgenommen** — die
Feldnummern und der senkrechte Streifen mit der Durchschriftenzuordnung
(„Blatt 1 (blau) Sachgebiet …"). Sie sind auf dem Papier ebenfalls
Kleinstdruck. Bedingung: **Was dort steht, muss an einer lesbaren Stelle noch
einmal stehen** — die Durchschriftenzuordnung in der Ausfüllhilfe, die
Feldnummer im zugänglichen Namen des Feldes. Eine Angabe, die es **nur** im
Kleinstdruck gibt, ist ein Mangel.

### 12.3 Was innerhalb des Blattes gilt und was nicht

**Nicht:** Schriftskala, Radien, Knopfmaße, Feldmaße, Abstandsskala — und
damit **auch L1 nicht**. Das Blatt wird nicht enger gesetzt, um Höhe zu
sparen. Es wird skaliert, und das ist etwas anderes: Skalieren erhält alle
Verhältnisse, Verdichten ändert sie. Wer im Blatt Polster kürzt, zerstört das
Papierbild — und dann hat die Anwendung ihren wichtigsten Vorteil gegenüber
dem Papiervordruck verloren.

**Doch:**

- **L2 uneingeschränkt.** Der Vordruck ist die Stelle, an der eine überlesene
  Angabe die größten Folgen hat: eine Vorrangstufe in Feld 9, ein Rufname in
  Feld 6, ein Vermerk der Fm-Zentrale in Feld 2. Jede Farbangabe des Blattes
  wird gegen ihren **tatsächlichen** Grund gemessen — auch dort, wo der Grund
  die Farbe einer Durchschrift trägt — und muss 7:1 erreichen.
- Fokusregeln (Abschnitt 2.6). **Der Fokusring skaliert nicht mit**: Er wird
  außerhalb des skalierten Blattes gezeichnet oder gegen den Maßstab
  ausgeglichen, damit er auch bei Maßstab 0.75 zwei Bildpunkte breit bleibt.
- Zustände ohne Farbe (Abschnitt 9), Tastaturregeln (Abschnitt 10).

### 12.4 Feste Größen des Rasters

| Eigenschaft | Wert | Grund |
| --- | --- | --- |
| Rasterbreite | 56rem (896 px) | das amtliche Raster. Nicht die Darstellungsbreite — die ergibt sich aus dem Maßstab |
| Maßstab | `max(0.75, min(1, 100cqw / 56rem))` | skaliert statt abgeschnitten oder gestaucht; nie größer als 1, nie kleiner als 0.75 |
| Zeilenhöhe im Raster | 2.2rem | so hoch ist die Zeile auf dem Papier |
| Schrift | `--schriftsatz`; tragende Angaben mind. `0.875rem` | L2 |
| Feldfolge | Q1-Nummernfolge | `UX-PAPIERBILD`, `NV-*` |
| Gliederung | drei Teile: Bearbeitungsvermerke der Fm-Zentrale · Nachricht · Laufzettel | `UX-PAPIERBILD` |

### 12.5 Um das Blatt herum

**Alles um das Blatt herum gehorcht dieser Spec:** Seitenkopf, Aktionsleiste
über und unter dem Blatt, der Bearbeitungsweg daneben, Meldungskästen,
Anlagenliste. Sie skalieren **nicht** mit — der Maßstab gilt nur für das Blatt.

Der Bearbeitungsweg steht rechts neben dem Blatt, sobald die Inhaltsspalte
**70rem** breit ist; darunter darüber, als flache Reihe kleiner Marken statt
als Reihe großer Karten.

Die 70rem sind kein runder Wert, sondern eine Rechnung: Der Weg nimmt dem
Blatt Breite weg, und weniger Breite heißt kleinerer Maßstab. Erst ab 70rem
bleiben dem Blatt genug Spalte für Maßstab 1 **und** dem Weg seine 14rem.
Darunter sieht man mit Weg daneben weniger vom Vordruck statt mehr.

Der Wechsel der Station ändert das Bild des Vordrucks nicht, sondern nur,
welche Felder bedienbar sind (`UX-KEIN-BRUCH-IM-LAUFWEG`). Feldfolge und
Gruppierung sind für alle Stationen desselben Vorgangs identisch — und der
Maßstab ebenfalls, sonst sähe dieselbe Nachricht an zwei Stationen
verschieden aus.

### 12.6 Auf Papier

Im Druck gilt ein eigener, **fester** Maßstab, der das Blatt auf A4 bringt —
im Bestand `zoom: 0.78`. Er hängt nicht von der Fensterbreite ab: Ein
Ausdruck, dessen Größe davon abhinge, wie breit gerade das Fenster stand, wäre
kein Vordruck, sondern ein Zufall.

---

## 13. Übernommene Seiten

Die Meldungslisten und die Anhangseite stammen aus dem Bestand. Sie bauen ihre
Oberfläche aus verschachtelten Tabellen und erzeugten Bildknöpfen und trugen
ursprünglich lila Grund, Serifenschrift und Browservorgaben — neben der
übrigen Anwendung sah das aus wie ein zweites Programm.

Sie bekommen **keine Ausnahme, sondern eine Angleichungspflicht**:

| Stufe | Was | Ist |
| --- | --- | --- |
| 1 | Grund, Schrift, Abstände, Feld- und Knopfmaße dieser Spec | teilweise — über `.estab-legacy-page` aufgesetzt |
| 2 | Bildknöpfe durch Knöpfe und Verweise ersetzen | teilweise — die Leiste ist ersetzt, Blätterpfeile sind noch Bilder |
| 3 | Aufbau nach Abschnitt 6 und 7 | offen |
| 4 | `.estab-legacy-page` entfällt | offen |

Solange Stufe 4 nicht erreicht ist, gilt: **Eine übernommene Seite darf keine
neue Abweichung hinzufügen.** Wer dort etwas ändert, ändert es in Richtung
dieser Spec.

Erzeugte Bilder mit Text sind verboten. Text in einem Bild lässt sich nicht
umfärben, nicht vergrößern und nicht vorlesen — und die einzige mitgelieferte
Schrift der Bilderzeugung ist eine kursive Serifenschrift.

---

## 14. Was sich gegenüber dem Bestand geändert hat

Diese Spec war zuerst eine Sollvorgabe. Sie ist umgesetzt; der Abschnitt
führt jetzt, was daraus geworden ist. Gemessen an `estab-ui.css`.

### 14.1 Ordnung

| Gegenstand | vorher | jetzt |
| --- | --- | --- |
| Farbliterale außerhalb des Vordrucks | **359** | **0** |
| Marken (Custom Properties) | 7, alle für die Zeitleiste | **73** im `:root`-Block |
| Schriftgrößen | **61** verschiedene Werte | **7** Stufen |
| Schriftstärken | 400 – 900, davon 56 × `800` und 8 × `900` | **3** |
| Eckradien | **15** Werte | **4** |
| Regeln unter Prüfung | 0 | **1213** von 1395; die übrigen 182 sind der Vordruck |
| Fokusringe | **8** Farben, vier davon unter 2.5:1 auf hellem Grund | **einer**, ≥ 3:1 auf jedem Grund |
| `!important` | 53, ungeordnet | 53, alle in einem der **vier** benannten Fälle (Abschnitt 2.5) |

### 14.2 Dichte (L1)

| Gegenstand | vorher | jetzt |
| --- | --- | --- |
| Zellenpolster senkrecht | `0.8rem` × 2 = 25.6 px | `--abstand-2` × 2 = 8 px |
| Tafel-Innenabstand | bis `1.5rem` × 2 = 48 px | `--abstand-5` × 2 = 24 px |
| Knopfhöhe | 15 Werte zwischen 1.55rem und 3.5rem | 2rem und 1.75rem |
| Menüspalte | bis 18rem, zweispaltige Kacheln | 13.5–15rem, einspaltig |
| Cockpitspalte | bis 20rem | 15–16rem |
| Textzeilen je Tabellenzelle | 3 | 2 |
| Kategorien über der Liste | 3 Zeilen Marken | 1 Zeile Aufklapp |

Gemessen über vier Bildschirmhöhen: **kein Band über Budget.** Seitenkopf
29 px, Aktionsleiste 38 px, Kategorienband 38 px.

### 14.3 Lesbarkeit (L2)

| Gegenstand | vorher | jetzt |
| --- | --- | --- |
| Kontrast für Text | 4.5:1 als einzige Stufe | **7:1** Sollwert, 4.5:1 Untergrenze; niedrigster Wert im System 7.02 |
| Kleinste Schrift | `0.52rem` (gut 8 px) | `0.75rem`, und nur für Tabellenkopf, Bereichsmarke, Feldnummer |
| Listen | `0.78rem` (12.5 px) | **Arbeitsgröße** 0.875rem |
| Nachrichteninhalt | 0.78 – 0.9rem | **1rem**, schrumpft auf keinem Bildschirm |
| `opacity` auf Text | mehrfach, bis `.78` | keines |
| Kontrast im Vordruck | 4.5:1 über 14 Paarungen | **7:1** über dieselben 14 |
| Bilder mit Bedeutung in Listen | 7 GIFs, eine Ampel aus drei Farben | Zeichen **und** Wort je Zustand |
| Maßstab des Vordrucks | nach unten unbegrenzt | zwischen **0.75 und 1**; gemessen 0.969 |

### 14.4 Was die Umsetzung an der Spec korrigiert hat

Sechs Stellen, an denen die Messung den Entwurf widerlegt hat. Sie stehen
hier, weil eine Spec, die ihre eigenen Irrtümer verschweigt, beim nächsten
Mal dieselben produziert.

1. **Die Cockpitspalte trägt keine 12.5rem.** Das Anwesenheitsraster fiel auf
   eine Spalte zusammen, die Bezeichnungen liefen über, „Führungsstelle"
   brach mitten durch. Sie bleibt bei 15rem — die Ersparnis liegt dort im
   Polster, nicht in der Breite.
2. **Die Menükacheln stehen einspaltig.** Zwei nebeneinander bräuchten 210
   Bildpunkte, die Spalte gibt 182 her.
3. **Drei Angaben im Vordruck bleiben unter der Lesbarkeitsgrenze.** Sie
   stehen im festen Raster und sprengen es in Arbeitsgröße. Dort schlägt die
   Dienstvorschrift diese Spec (Abschnitt 1.4).
4. **In der übernommenen Liste gibt es keinen Zebrastreifen.** Der Zeilengrund
   trägt dort die Durchschriftenfarbe; ein Streifenmuster darüber machte aus
   einer Angabe ein Muster.
5. **`!important` hat vier zulässige Fälle, nicht zwei.** Ein Inline-Stil
   steht in der Ordnung über jeder Regel; ihn ohne `!important` zu schlagen
   ist unmöglich, nicht unsauber.
6. **Die Aktionsleiste braucht 2.5rem, nicht 2rem.** Ein Balken mit Rand und
   Polster um 2rem-Knöpfe ist nie 2rem.

---

## 15. Die Regeln im Bedienkatalog

Diese Spec ist festgesetzt. Der Abschnitt hieß einmal „Regeln **für** den
Bedienkatalog" und listete Vorschläge; er führt jetzt, was eingetragen ist.

Herkunft: `docs/GESTALTUNG.md`, Kennung `GES-`, Konstante
`ESTAB_UX_ORIGIN_GESTALTUNG` in `app/ux_rules.php`. Sie teilen sich den
Katalog mit den Bedienregeln, weil sie dieselbe Autorität haben — beide sind
die Entscheidung des Betreibers und dürfen geändert werden. Der
Vorschriftenkatalog bleibt unberührt. Die Registry erzwingt, dass Präfix und
Herkunft zusammenpassen.

| Kennung | Prüfung |
| --- | --- |
| `GES-MARKEN` | `tests/php/ges_marken.php` — null Farbliterale außerhalb `:root`, keine Marke, die es nicht gibt |
| `GES-SCHRIFTSKALA` | `tests/php/ges_schriftskala.php` — sieben Stufen, keine unter 0.75rem |
| `GES-SCHRIFTSTAERKE` | dieselbe Datei — 400, 600, 700 |
| `GES-ABSTANDSSKALA` | `tests/php/ges_abstandsskala.php` — sieben Stufen, vier Radien, auch im zweiten Wert einer Kurzschreibweise |
| `GES-KONTRAST-TEXT` | `tests/php/ges_kontrast.php` — 50 Paarungen gegen 7:1, Untergrenze 4.5:1 |
| `GES-KONTRAST-RAND` | dieselbe Datei — Ränder und Fokusringe ≥ 3:1 auf jedem Grund |
| `GES-KEINE-BLASSE-SCHRIFT` | dieselbe Datei — kein `opacity` unter 1 |
| `GES-FOKUS-DOPPELRING` | `tests/php/ges_fokus.php` — eine Regel, zwei Ringe, Systemring bei erzwungenen Farben |
| `GES-DURCHSETZUNG` | `tests/php/ges_durchsetzung.php` — `!important` nur in den vier Fällen aus Abschnitt 2.5 |
| `GES-SEITENKOPF` | `tests/php/ges_seitenaufbau.php` — eine Zeile mit Unterlinie, ein `h1`, Bereichsmarke darüber |
| `GES-BAENDER` | dieselbe Datei — die klebende Leiste deckt und trägt eine Unterlinie |
| `GES-VORDRUCK-MASSSTAB` | `tests/php/ges_vordruck.php` — skaliert, nie abgeschnitten, zwischen 0.75 und 1 |
| `GES-VORDRUCK-LESBAR` | dieselbe Datei — tragende Angaben ≥ 0.875rem, jede Ausnahme begründet |
| `GES-INHALT-BLEIBT-GROSS` | dieselbe Datei — Nachrichteninhalt auf 1rem |

**Jeder Wächter trägt eine Selbstprobe.** Er muss ein eingebautes Literal,
eine zu kleine Größe, eine falsche Stärke, den zweiten Wert einer
Kurzschreibweise, ein `opacity` oder ein grundloses `!important`
wiederfinden. Ein Wächter, der nicht beißt, ist schlimmer als keiner — er
beruhigt.

### Was nicht im Katalog steht, und warum

Drei Anforderungen dieser Spec brauchen einen Browser, und die PHP-Suite kann
keinen starten:

| Anforderung | Gemessen von |
| --- | --- |
| Höhenbudget (Abschnitt 4.1) | `tools/bedienpruefung/blick/budget.mjs` über vier Bildschirmhöhen |
| Kein Überlauf, kein Wortbruch | `tools/bedienpruefung/blick/aufnahme.mjs` über vier Breiten |
| Zustände ohne Farbe (Abschnitt 9) | Graustufenbilder desselben Werkzeugs |

Sie stehen deshalb **nicht** im Katalog. Die Registry verlangt zu jeder Regel
einen Test; ein vorgetäuschter Test wäre schlimmer als keine Regel. Sie
werden gehandhabt wie `UX-EINARBEITUNG`: gemessen, protokolliert, vor jeder
Freigabe.

Das Werkzeug zählt dabei, wie viel es gefunden hat, und sagt es laut, wenn es
nichts war. Der erste Lauf des Höhenbudgets meldete null Befunde, weil er auf
der falschen Seite maß — eine Ruhe, die kein Beweis ist, ist schlimmer als
ein Befund.

---

## Anhang A — Marken auf einen Blick

```css
:root {
    /* Schrift */
    --schriftsatz: Arial, Helvetica, "Liberation Sans", sans-serif;
    --schrift-1: 0.75rem;    --zeile-1: 1.3;   /* Untergrenze */
    --schrift-2: 0.8125rem;  --zeile-2: 1.35;
    --schrift-3: 0.875rem;   --zeile-3: 1.35;  /* Arbeitsgröße */
    --schrift-4: 1rem;       --zeile-4: 1.5;   /* Nachrichteninhalt */
    --schrift-5: 1.125rem;   --zeile-5: 1.3;
    --schrift-6: 1.25rem;    --zeile-6: 1.25;
    --schrift-7: 1.5rem;     --zeile-7: 1.2;
    --stark-normal: 400;  --stark-halb: 600;  --stark-voll: 700;

    /* Abstände — Raster 2 px */
    --abstand-1: 0.125rem;  --abstand-2: 0.25rem;  --abstand-3: 0.375rem;
    --abstand-4: 0.5rem;    --abstand-5: 0.75rem;  --abstand-6: 1rem;
    --abstand-7: 1.5rem;

    /* Helle Flächen und Tinte — Untergrenze 7:1 */
    --grund-seite: #f4f7fa;
    --grund-tafel: #ffffff;
    --grund-gedaempft: #e6ecf3;
    --grund-zebra: #f4f7fa;
    --tinte: #243547;        /* min 10.54 */
    --tinte-neben: #3d4c5c;  /* min  7.40; nicht auf Goldfläche */

    /* Linien */
    --linie: #d2dbe5;
    --linie-kraeftig: #b0bccb;
    --rand-bedienelement: #717f92;  /* min 3.43 */

    /* Dunkle Spalten */
    --grund-spalte: #0c1c2b;
    --grund-kachel: #152738;
    --grund-kachel-zeigen: #1f3a57;
    --tinte-spalte: #ffffff;
    --tinte-spalte-neben: #c9d3de;  /* min 7.69 */
    --linie-spalte: #717f92;

    /* Handlung — Fläche und Schrift sind zwei Werte */
    --handlung: #1d5687;         /* nur Fläche; Weiß darauf 7.68 */
    --handlung-dunkel: #153f66;  /* Schrift und Verweis; min 9.13 */
    --handlung-sanft: #e2edfa;
    --handlung-kante: #2b6ea8;


    /* Zustaende in den dunklen Spalten.
       Beim Umsetzen der Huelle sichtbar geworden: Das Cockpit zeigt
       Besetzung und Vorrang auf dunklem Grund, und die hellen
       Zustandsfarben oben tragen dort nicht. */
    --erledigt-spalte: #8ce8bd;          /* min 7.99 auf den drei Kachelgruenden */
    --erledigt-spalte-flaeche: #163e35;  /* weiss darauf 11.83 */
    --fehler-spalte: #ffc2c2;            /* min 7.63 */
    --fehler-spalte-flaeche: #5a1a1f;    /* weiss darauf 13.11 */
    --marke-standort-flaeche: #3d341d;   /* Gold darauf 7.39 */

    /* Standort und Fokus */
    --marke-standort: #f0c34a;
    --fokus-aussen: #f0c34a;
    --fokus-innen: #0c1c2b;

    /* Zustände: Fläche / Kante / Tinte */
    --hinweis-flaeche: #e2edfa;  --hinweis-kante: #1d5687;  --hinweis-tinte: #153f66;
    --erledigt-flaeche: #e6f4ea; --erledigt-kante: #1f7a3d; --erledigt-tinte: #155c2d;
    --achtung-flaeche: #fdf2d8;  --achtung-kante: #a97b0b;  --achtung-tinte: #6b4d05;
    --fehler-flaeche: #fdeceb;   --fehler-kante: #a4231c;   --fehler-tinte: #7d1a14;

    /* Vorrang „Blitz" — Sofort und Staatsnot teilen achtung/fehler */
    --blitz-flaeche: #fdeadb;    --blitz-kante: #b25a12;    --blitz-tinte: #733806;

    /* Radien */
    --radius-1: 2px;  --radius-2: 4px;  --radius-3: 6px;  --radius-pille: 999px;

    /* Schatten */
    --schatten-tafel: 0 1px 2px rgba(12,28,43,.07);
    --schatten-schwebend: 0 4px 14px rgba(12,28,43,.24);
}

/* Die einzige Dichtestufe. Schrift-3, Schrift-4, Kontrast,
   Fokusring und Klickflächen stehen bewusst nicht darin. */
@media (max-height: 34rem) {
    :root {
        --abstand-5: 0.5rem;  --abstand-6: 0.75rem;  --abstand-7: 1rem;
        --schrift-6: 1.125rem;  --schrift-7: 1.25rem;
    }
}
```

---

## Anhang B — Die Maße, die am häufigsten gebraucht werden

| Frage | Antwort |
| --- | --- |
| Wie groß ist die Arbeitsgröße? | 0.875rem (14 px), Zeilenhöhe 1.35 — Tabellen, Felder, Knöpfe, Beschriftungen |
| Wie groß ist der Nachrichteninhalt? | 1rem (16 px), Zeilenhöhe 1.5 — und er schrumpft auf keinem Bildschirm |
| Was ist die kleinste zulässige Schrift? | 0.75rem (12 px), nur Tabellenkopf, Bereichsmarke, Feldnummer |
| Wie groß ist ein Knopf? | 2rem hoch (32 px), Polster 0 0.75rem, Schrift 0.875rem/700, Rand 1px, Radius 4px |
| Wie groß ist ein kleiner Knopf? | 1.75rem hoch (28 px), Polster 0 0.5rem, Schrift 0.8125rem/700 |
| Was ist die kleinste Klickfläche? | 1.75rem (28 px). Darunter nichts. |
| Wie groß ist ein Eingabefeld? | 2rem hoch, Polster 0.375rem 0.5rem, Rand 1px `#717f92`, Radius 4px |
| Was steht als Überschrift an einer Seite? | Bereichsmarke (0.75rem, Versalien) über einem `h1` (1.25rem/700), darunter eine 1px-Linie |
| Wie groß ist eine Tafelüberschrift? | `h2`, 1.125rem/700 |
| Wie sieht eine Tabellenzelle aus? | Polster 0.25rem 0.5rem, Schrift 0.875rem, oben ausgerichtet, höchstens zwei Zeilen |
| Wie sieht ein Tabellenkopf aus? | 0.75rem/700, Versalien, Grund `#e6ecf3`, klebt oben, 2px Unterlinie |
| Wie sieht der Fokus aus? | 2px Gold außen mit 1px Versatz, 2px Dunkel direkt am Element |
| Welchen Kontrast muss Text haben? | 7:1 gegen seinen tatsächlichen Grund. Immer. |
| Welche Farbe hat ein Verweis? | `--handlung-dunkel` `#153f66` — nicht `--handlung` |
| Wie breit ist die Menüspalte? | `clamp(13.5rem, 15vw, 15rem)` |
| Wie breit darf Fließtext werden? | 34rem |
| Wie hoch darf ein Filterblock sein? | 13rem im Ruhezustand |
| Wie breit ist der Vordruck? | Sein **Raster** ist 56rem. Dargestellt wird er im Maßstab `max(0.75, min(1, 100cqw / 56rem))` — skaliert, nie abgeschnitten, nie gestaucht |
| Wird der Vordruck je größer als sein Raster? | Nein. Maßstab höchstens 1. |
| Was passiert unter Maßstab 0.75? | Das Skalieren hört auf, der Rahmen scrollt waagerecht |
