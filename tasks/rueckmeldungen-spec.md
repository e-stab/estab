# SPEC — Rückmeldungen aus dem Betrieb

Neunzehn Rückmeldungen des Betreibers nach der Gestaltungsumstellung. Dieses
Dokument schneidet sie in Module, entscheidet die Konflikte und legt je Punkt
fest, woran das Ergebnis gemessen wird.

Es steht **unter** `SPEC.md` (Vorschrift und Bedienung) und `docs/GESTALTUNG.md`
(Gestaltung). Wo es einer der beiden widerspricht, wird die dortige Regel
geändert und die Änderung hier begründet — nicht umgangen.

---

## 1. Fähigkeitskarte

Sechs Module. Die Reihenfolge ist die Baureihenfolge und folgt der
Betreiberentscheidung: erst was den Betrieb blockiert, dann die
Vorschriftentreue, dann das Große.

| Modul | Was | Umfang | Hängt ab von |
| --- | --- | --- | --- |
| **R1 — kontrast** | Weiße Schrift auf hellem Grund bei BOS-Info, und der Wächter, der das künftig findet | 2 Punkte | — |
| **R2 — berechtigung** | LdF trägt Fernmelder-Funktionen; Meldungsübersicht für alle; TBB schreibend für LdF | 3 Punkte | — |
| **R3 — vordruck** | Sieben fachliche Korrekturen am Nachrichtenvordruck | 7 Punkte | — |
| **R4 — rueckmeldung** | Eine Rückweisung wird gesehen, ohne dass man sie sucht | 1 Punkt | — |
| **R5 — tabellen** | Ein Tabellenframework: Suche je Spalte, Sortierung, Filter, Paginierung — einmal gebaut, überall benutzt | 6 Punkte | R1 |
| **R6 — handbuch** | Das Handbuch bekommt die Hülle und die Maße der Anwendung | 1 Punkt | R1 |

R5 hängt an R1, weil beide dieselbe Wächterlücke betreffen: Eine Tinte ohne
ihren Grund. Alles andere ist voneinander unabhängig und könnte parallel
laufen.

---

## 2. Getroffene Entscheidungen

**NV-08 wird korrigiert.** Die Regel sagte: „Der Spruch ist ausdrücklich die
Ausnahme; die Durchsage ist deshalb vorbelegt." Der Betreiber sagt: Eine
normale Meldung hat weder das eine noch das andere. Ein Spruch ist eine
Nachricht, die 1:1 im selben Wortlaut aufzuschreiben ist; eine Durchsage geht
an eine Gruppe von Empfängern statt an jeden einzeln. Beides sind Sonderfälle,
und Sonderfälle werden nicht vorbelegt.

Der Regeltext war eine **Auslegung** der Ausfüllanleitung, nicht deren
Wortlaut. Die Fachautorität liegt beim Betreiber; die Regel wird geändert und
die Änderung im Commit begründet.

**Tabellen mit JavaScript** — und der Nachrichtenlauf trotzdem ohne.
`UX-OHNE-JAVASCRIPT` verlangt, dass Aufnehmen, Sichten und Befördern ohne
Skript funktionieren, und sagt dazu: *„Komfort darf davon abhängen, der
Nachrichtenlauf nicht."* Daraus folgt die Bauweise:

| Schicht | Ohne JavaScript | Mit JavaScript |
| --- | --- | --- |
| Die Liste rendert, Zeilen sind lesbar | ja | ja |
| Eine Meldung lässt sich öffnen | ja | ja |
| Blättern | ja (Formular) | ja |
| Sortieren, Spaltensuche, Filter | ja (Formular, Seitenaufbau) | sofort im Browser, ohne Neuladen |

Der Server kann alles; JavaScript macht es schnell. Das ist die dritte der
angebotenen Möglichkeiten, nicht die zweite — die zweite hätte die Liste ohne
Skript unbrauchbar gemacht, und „Stab lesen" ist Sichten.

**Das Handbuch behält seinen Text.** Neu sind Hülle, Seitenkopf, Marken, Maße
und das Inhaltsverzeichnis in der Menüspalte. Kein Satz wird umgeschrieben.

---

## 3. R1 — kontrast

### R1.1 Weiße Schrift auf hellem Grund

**Befund.** `.estab-bos-document-header` und `.estab-bos-document-header h1`
tragen `color: var(--tinte-spalte)` — Weiß. Ihr dunkler Verlaufsgrund wurde
bei der Gestaltungsumstellung entfernt, weil die Kopfzeilenregel ihn ohnehin
auf `none` setzt. Die weiße Tinte blieb stehen. **Das ist ein Fehler der
Umstellung, kein Altbestand.**

| Soll | Abnahme |
| --- | --- |
| Der Dokumentkopf der Infosammlung trägt `--tinte` auf hellem Grund und erreicht 7:1 | `ges_kontrast.php`; Bild der BOS-Info-Seite |

### R1.2 Der Wächter muss das finden

**Befund.** `ges_kontrast.php` prüft die Paarungen, die ihm aufgezählt sind.
Eine Regel, die eine Spaltentinte setzt, ohne einen dunklen Grund zu setzen,
fällt ihm nicht auf — er kann den Grund nicht sehen.

Ableitbar ist es trotzdem: **Wer die Farben der dunklen Spalte als Schrift
benutzt, muss auf derselben Regel oder einem benannten dunklen Vorfahren
stehen.**

| Soll | Abnahme |
| --- | --- |
| `--tinte-spalte` und `--tinte-spalte-neben` erscheinen nur dort, wo ein dunkler Grund gesetzt ist — auf derselben Regel oder unter einem Auswähler, der zu einem benannten dunklen Bereich gehört | Neue Regel `GES-TINTE-BRAUCHT-GRUND`, geprüft in `ges_kontrast.php`, samt Selbstprobe |

**Zusätzlicher Befund derselben Art**, beim Schreiben dieser Spec gefunden und
bereits behoben: Drei Schatten im Vordruck waren von einem globalen Durchgang
auf die Goldmarke gezogen worden, darunter die **rote Pflichtfeldkante**. Der
Vordruck weicht wieder in 0 von 637 Angaben vom Bestand ab.

---

## 4. R2 — berechtigung

### R2.1 Der LdF trägt die Fernmelder-Funktionen

**Soll.** Wer die Funktion LdF trägt, kann alles, was ein Fernmelder kann.
Der LdF ist die Betriebsleitung des Fernmeldedienstes; ihm die Handgriffe
seiner eigenen Leute zu verwehren, ist eine Sperre ohne Zweck.

**Abnahme.** Für jede Fähigkeit, die die Rolle Fernmelder eröffnet, gilt sie
auch für die Funktion LdF. Ein Test führt beide Mengen gegeneinander und
verlangt Enthaltung.

### R2.2 Die Meldungsübersicht steht allen offen

**Befund.** Die Übersicht ist heute der Lage- und Dokumentationsfunktion
vorbehalten. Der Betreiber: Jeder soll die Meldungen ansehen können.

**Soll.** Lesenden Zugriff auf die Meldungsübersicht hat jede angemeldete
Funktion im aktiven Einsatz. Schreibende Handlungen bleiben, wo sie sind.

**Abnahme.** Ein Test ruft die Übersicht für jede Funktion auf und verlangt,
dass keine abgewiesen wird. Die bestehenden Schreibprüfungen bleiben grün.

### R2.3 TBB schreibt der LdF, ETB der S2

**Befund.** Der LdF bekommt: „TBB schreibgeschützt. Ihre aktuell wirksamen
Funktionen erlauben das Lesen, besitzen aber nicht die Fachzuständigkeit für
TTB-Einträge."

**Soll.** Zwei Bücher, zwei Zuständigkeiten, und keine dritte:

| Buch | Schreibt |
| --- | --- |
| Einsatztagebuch (ETB) | S2 oder ETB-Führer |
| Technisches Betriebsbuch (TBB) | **LdF** |

Sonst niemand.

**Abnahme.** Ein Test schreibt als LdF ins TBB und erwartet Erfolg; als jede
andere Funktion und erwartet Abweisung. Für das ETB spiegelbildlich.

---

## 5. R3 — vordruck

Sieben fachliche Korrekturen. Jede ändert, was der Vordruck verlangt oder
freigibt — keine ist eine Gestaltungsfrage.

| Nr | Soll | Abnahme |
| --- | --- | --- |
| R3.1 | Durchsage und Spruch sind **optional** und **nicht vorbelegt**. Eine normale Meldung trägt keines von beidem. | `NV-08-DURCHSAGE-SPRUCH` neu gefasst; ein Test öffnet den leeren Vordruck und verlangt, dass kein Kästchen gesetzt ist |
| R3.2 | Die Vorrangstufe hat **kein Kästchen „keine"**. Ohne besondere Stufe ist nichts angewählt. | `NV-09-VORRANGSTUFE` verlangt das bereits — der Vordruck erfüllt es nicht. Test: kein Kästchen mit dem Wert „keine", nichts vorbelegt |
| R3.3 | Der Aufnahmevermerk trägt **Datum und Uhrzeit**, wie Annahme- und Beförderungsvermerk. | Test über die Felder des Aufnahmevermerks; Bild der Eingangserfassung |
| R3.4 | Ein geöffnetes Info-Fähnchen liegt **über** allen anderen Infopunkten und wird nicht angeschnitten. | Messung im Browser: kein Infopunkt überdeckt das offene Fähnchen |
| R3.5 | Der Fernmelder darf **Absender** ausfüllen — optional. Was er ausgefüllt hat, prüft der LdF nur noch. | Feldfreigabe für die Station Fernmelder; Test über alle Stationen |
| R3.6 | **Zeichen** wird beim Eingang **nicht** vom Fernmelder ausgefüllt — nur beim Ausgang vom Verfasser. | Feldfreigabe je Station und Richtung; Test |
| R3.7 | **Abfassungszeit** ebenso: nur beim Ausgang vom Verfasser. | wie R3.6 |

R3.1 und R3.2 ändern Vorschriftenregeln beziehungsweise decken einen Verstoß
gegen eine auf. R3.5 bis R3.7 ändern die Feldfreigabe des Laufwegs und
berühren `LW-NUR-BLAUER-TEIL` — dort ist zu prüfen, ob die Regel nachzuziehen
ist.

---

## 6. R4 — rueckmeldung

**Befund.** „Für die Rückgabe an LdF ist ein Grund erforderlich." erscheint an
einer Stelle, die man suchen muss. Der Betreiber hat sie lange nicht gefunden.

**Soll.** Eine Rückweisung erscheint **in der Mitte des Bildschirms**, über
dem Inhalt, und nimmt den Fokus. Sie verschwindet erst, wenn sie
weggenommen wird oder die Handlung gelingt.

Das ergänzt `UX-RUECKWEISUNG`, das Feld, Grund und Fokus schon verlangt —
aber nicht, dass die Meldung ins Blickfeld kommt.

**Abnahme.** Neue Bedienregel `UX-RUECKWEISUNG-SICHTBAR`. Test: Die Meldung
liegt über dem Inhalt, ist ohne Scrollen sichtbar, trägt `role="alert"` und
den Fokus. Ohne JavaScript ebenso — als Kasten am Anfang des Dokuments, der
die Seite überlagert, nicht als Skriptfenster.

---

## 7. R5 — tabellen

Sechs Rückmeldungen, eine Ursache: **Jede Tabelle wurde für sich gebaut.**
Deshalb fehlt der Nachweisung die Suche, blättert „Stab lesen" nicht, sind
Anhänge und Benutzerliste nicht angeglichen, und man muss bei jeder Tabelle
neu überlegen, wie man einen Eintrag findet.

Das Modul baut **ein** Framework und setzt es überall ein. Der Aufbau, die
Bedienung und die Abnahme stehen in `docs/TABELLEN.md`; hier steht nur, was
es leisten muss und wo es hin soll.

| Nr | Soll | Abnahme |
| --- | --- | --- |
| R5.1 | Jede Spalte ist **sortierbar** durch Klick auf ihre Überschrift | Test über jede eingesetzte Tabelle |
| R5.2 | Jede Spalte hat eine **eigene Suchmaske**; die Tabelle zeigt, was in dieser Spalte passt | dito |
| R5.3 | **Blättern funktioniert** — auch bei „Stab lesen" | Test mit mehr Zeilen als eine Seite fasst |
| R5.4 | Eine **Volltextsuche** über alle Spalten | dito |
| R5.5 | Alle Tabellen tragen **denselben Aufbau und dieselbe Bedienung** | ein Test hält alle eingesetzten Tabellen gegen dieselbe Zusicherungsliste |
| R5.6 | Ohne JavaScript bleibt die Liste lesbar und Einträge lassen sich öffnen | Durchgang mit abgeschaltetem Skript |

**Einzusetzen in:** Nachweisung (Ein- und Ausgang), Anhänge, Benutzerliste,
„Stab lesen", Meldungsübersicht, Vordruckliste.

---

## 8. R6 — handbuch

**Befund.** Die Überschrift nimmt auf einem Laptopbildschirm die halbe Höhe.
Das Inhaltsverzeichnis steht in eigener Gestaltung neben dem Text.

**Soll.**

- Das Handbuch steht in der Hülle wie jede andere Seite.
- Sein Seitenkopf ist eine Zeile nach `GES-SEITENKOPF`.
- Sein Inhaltsverzeichnis ist die bereichseigene Auswahl in der Menüspalte,
  nicht eine zweite Spalte im Inhalt.
- `handbuch/handbuch.css` benutzt die Marken; Fließtext auf 34rem.
- **Kein Satz wird umgeschrieben.**

**Abnahme.** Die Wächter greifen auf `handbuch/handbuch.css`; das Höhenbudget
des Seitenkopfs hält; ein Bild bei 768 px Höhe zeigt Text statt Balken.

---

## 9. Abgrenzung

Nicht Gegenstand dieser Spec:

- Die **eine Aktionsspalte** in der übernommenen Liste. Sie steht weiter
  offen und gehört zur Bedienprüfung.
- Neue Fachfunktionen. Jede Rückmeldung ist eine Korrektur, keine Erweiterung.
- Die Bedienprüfungen selbst.
