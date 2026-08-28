# Umsetzungsplan — Rückmeldungen aus dem Betrieb

Grundlage sind `tasks/rueckmeldungen-spec.md` und `docs/TABELLEN.md`. Dieser
Plan ordnet die neunzehn Rückmeldungen in Aufgaben, Phasen und Prüfpunkte.

Nachfolger von `tasks/gestaltung-plan.md`. Dort bleibt eine Aufgabe offen —
die eine Aktionsspalte der übernommenen Liste; sie gehört zur Bedienprüfung
und nicht hierher.

---

## 1. Ausgangslage

Neunzehn Rückmeldungen, sechs Module, drei davon Betriebsblocker:

| Blockiert | Was |
| --- | --- |
| Der LdF | kann nicht ins TBB schreiben, obwohl er es führt |
| Jede Funktion außer S2 | kann die Meldungsübersicht nicht ansehen |
| Wer BOS-Info liest | sieht eine weiße Überschrift auf hellem Grund |

Der dritte ist **meiner**: Die Gestaltungsumstellung hat den dunklen Grund des
Dokumentkopfs entfernt und die weiße Tinte stehen lassen.

Sechs weitere Rückmeldungen haben dieselbe Ursache: Jede Tabelle wurde für
sich gebaut. Sieben Tabellen in sieben Dateien, sieben Bedienungen.

---

## 2. Architekturentscheidungen

### 2.1 Erst die Blocker, dann die Vorschrift, dann das Große

Betreiberentscheidung. Die Reihenfolge ist nicht nach Aufwand sortiert,
sondern danach, was Menschen gerade an der Arbeit hindert.

### 2.2 Zwei Prüfungen fehlen, nicht zwei Regeln

Die weiße Schrift ist kein Einzelfall, sondern eine **Lücke im Wächter**.
`ges_kontrast.php` prüft Paarungen, die ihm aufgezählt sind; eine Regel, die
eine Tinte setzt und ihren Grund verliert, sieht er nicht.

Ableitbar ist es trotzdem: Wer die Farben der dunklen Spalte als Schrift
benutzt, muss auf einem dunklen Grund stehen. Daraus wird
`GES-TINTE-BRAUCHT-GRUND`. Ohne diese Regel behebt R1 einen Fall und lässt
den nächsten zu.

Ebenso bei `UX-RUECKWEISUNG`: Die Regel verlangt Feld, Grund und Fokus, aber
nicht, dass die Meldung **gesehen** wird. Daraus wird
`UX-RUECKWEISUNG-SICHTBAR`.

### 2.3 Das Tabellenbauteil ist ein Bauteil, keine Vorlage

Eine Vorlage wird kopiert und läuft auseinander — genau das ist passiert. Das
Bauteil hat eine Aufrufstelle und eine Prüfung, und die Prüfung zählt: **Eine
Tabelle, die es nicht benutzt, ist ein Befund.**

Der Einbau geht Seite für Seite. Jede umgestellte Seite verschwindet aus der
Ausnahmeliste des Wächters — dieselbe Mechanik wie die Migrationsgrenze der
Gestaltung, die sich dort bewährt hat.

### 2.4 Vorschriftenregeln werden geändert, nicht umgangen

Zwei Rückmeldungen berühren den Vorschriftenkatalog. `NV-08` wird neu gefasst
(Betreiberentscheidung, Begründung in der Spec). `NV-09` bleibt, wie sie ist —
der Vordruck verstößt gegen sie und wird korrigiert.

Wer eine Vorschriftenregel ändert, ändert zuerst den Test.

### 2.5 Feldfreigabe ist Laufweg, nicht Gestaltung

R3.5 bis R3.7 ändern, welche Station welches Feld ausfüllt. Das berührt
`LW-NUR-BLAUER-TEIL` und die Stationsfolge. Jede dieser Aufgaben prüft
**alle** Stationen, nicht nur die geänderte — eine Freigabe, die an einer
Station dazukommt, darf an keiner anderen verschwinden.

---

## 3. Abhängigkeitsgraph

```
R01  Weisse Schrift bei BOS-Info                 ← Blocker, mein Fehler
  │
R02  GES-TINTE-BRAUCHT-GRUND                     ← damit es nicht wiederkommt
  │
R03  LdF traegt die Fernmelder-Funktionen        ┐
R04  Meldungsuebersicht fuer alle                ├─ Blocker, unabhaengig
R05  TBB schreibt der LdF, ETB der S2            ┘
  │
R06  Durchsage und Spruch optional               ┐
R07  Vorrangstufe ohne "keine"                   │
R08  Aufnahmevermerk bekommt sein Datum          ├─ Vordruck, unabhaengig
R09  Infofaehnchen liegt obenauf                 │
R10  Absender: Fernmelder darf                   │
R11  Zeichen nur beim Ausgang                    │
R12  Abfassungszeit nur beim Ausgang             ┘
  │
R13  Rueckweisung wird gesehen
  │
R14  Das Tabellenbauteil                         ← Grundlage fuer R15-R19
  ├── R15  Nachweisung Ein- und Ausgang
  ├── R16  "Stab lesen" (Blaettern reparieren)
  ├── R17  Anhaenge
  ├── R18  Benutzerliste
  └── R19  Meldungsuebersicht und Vordruckliste
  │
R20  Waechter: jede Tabelle kommt aus dem Bauteil
  │
R21  Handbuch: Huelle, Kopf, Marken, Inhaltsverzeichnis
  │
R22  Dokumentation nachziehen
```

---

## 4. Prüfpunkte

| Punkt | Nach | Frage |
| --- | --- | --- |
| **D0** | R02 | Findet der neue Wächter die weiße Schrift, wenn man sie wieder einbaut? |
| **D1** | R05 | Kann der LdF ins TBB schreiben, jede Funktion die Übersicht lesen — und ist sonst nichts aufgegangen, was zu bleiben hatte? |
| **D2** | R12 | Öffnet ein leerer Vordruck ohne Vorbelegung? Trägt der Aufnahmevermerk ein Datum? Sind Zeichen und Abfassungszeit beim Eingang gesperrt? |
| **D3** | R14 | Trägt das Bauteil? Eine Tabelle daraus, mit Sortierung, Spaltensuche und Blättern — und ohne JavaScript noch lesbar. |
| **D4** | R20 | Kommt **jede** Tabelle der Anwendung aus dem Bauteil? |
| **D5** | R22 | Abschluss: Suite grün, Wächter scharf, Bilder angesehen. |

---

## 5. Risiken

| Risiko | Wirkung | Gegenmaßnahme |
| --- | --- | --- |
| **Berechtigung zu weit geöffnet** | Wer die Übersicht sehen darf, sieht Meldungen fremder Funktionen | R04 öffnet **nur** das Lesen der Übersicht. Jede Schreibprüfung bleibt, und der Test verlangt das ausdrücklich |
| **Feldfreigabe kippt an anderer Station** | Ein Feld, das dazukommt, verschwindet woanders | Jede Aufgabe in R10–R12 prüft alle Stationen, nicht nur die geänderte |
| **`NV-08` ändern** | Eine Vorschriftenregel umzuschreiben ist der schwerste Eingriff dieser Liste | Erst den Test ändern, dann die Regel, Begründung in den Commit. Die Entscheidung steht in der Spec |
| **Das Bauteil wird zu allgemein** | Ein Bauteil, das alles kann, kann nichts gut | R14 baut es an **einer** Tabelle fertig (R15) und verallgemeinert erst dann |
| **`4fach/liste.php` hat 2 346 Zeilen** | Der Einbau in „Stab lesen" ist der schwerste Fall | Er kommt als zweiter, nicht als erster — nach der Nachweisung, die einfacher ist |
| **JavaScript-Schicht verdeckt Serverfehler** | Eine Tabelle, die nur mit Skript funktioniert, fällt erst im Einsatz auf | Jede eingebaute Tabelle wird **einmal ohne Skript** durchlaufen, bevor sie als fertig gilt |
| **Handbuch verliert Inhalt** | Ein Make-Over, das Text verliert | Ein Test hält die Textmenge vor und nach der Umstellung gegeneinander |

---

## 6. Aufgaben

Die Liste steht in `tasks/rueckmeldungen-todo.md`.
