# TABELLEN — ein Bauteil für alle Listen

Sechs Rückmeldungen aus dem Betrieb haben eine gemeinsame Ursache: Jede
Tabelle der Anwendung wurde für sich gebaut. Der Nachweisung fehlt die Suche,
„Stab lesen" blättert nicht, Anhänge und Benutzerliste sehen anders aus — und
wer einen Eintrag sucht, muss bei jeder Tabelle neu überlegen, wie das hier
geht.

> **Ich möchte hier ein Tabellenframework das gut funktioniert und das dann
> immer wieder genutzt wird. So sind alle Tabellen gleich aufgebaut und die
> Funktionalität gleich.**

Dieses Dokument beschreibt dieses Bauteil: was es kann, wie eine Seite es
benutzt, und woran es gemessen wird.

Es steht **unter** `docs/GESTALTUNG.md`. Wie eine Tabelle aussieht, steht dort
(Abschnitt 6 und 7); hier steht, wie sie funktioniert und wie man sie bekommt.

---

## 1. Der Vertrag

Eine Seite sagt, **was** in der Tabelle steht. Alles andere kommt vom Bauteil.

```php
estab_tabelle_ausgeben([
    'id' => 'nachweisung-eingang',
    'spalten' => [
        ['schluessel' => 'nummer',  'kopf' => 'TBB-Nachweis', 'breite' => 12,
         'sortierbar' => true, 'suchbar' => true, 'art' => 'zahl'],
        ['schluessel' => 'zeit',    'kopf' => 'Zeit',         'breite' => 12,
         'sortierbar' => true, 'suchbar' => true, 'art' => 'zeit'],
        ['schluessel' => 'inhalt',  'kopf' => 'Inhalt',       'breite' => 40,
         'sortierbar' => false, 'suchbar' => true, 'art' => 'text',
         'zeilen' => 2],
    ],
    'zeilen' => $zeilen,          // list<array<string, string>>
    'aktion' => static fn (array $z): string => estab_tabelle_knopf(
        'Öffnen', 'mainindex.php', ['00_lfd' => $z['id']]
    ),
    'leer' => 'Keine Meldung entspricht den gesetzten Filtern.',
]);
```

Was die Seite **nicht** tut: Sortieren, Filtern, Blättern, Zählen, Markup
schreiben, Zustände in der Adresse führen. Wer das je Tabelle noch einmal
schreibt, baut die nächste Abweichung ein.

### Was die Seite bekommt

| | |
| --- | --- |
| **Aufbau** | Tafel, klebender Kopf, feste Spaltenbreiten, zwei Textzeilen je Zelle, Zeigekante — nach `docs/GESTALTUNG.md` Abschnitt 6 |
| **Sortierung** | jede als `sortierbar` benannte Spalte, durch Klick auf ihre Überschrift |
| **Spaltensuche** | jede als `suchbar` benannte Spalte bekommt ein eigenes Feld |
| **Volltextsuche** | über alle `suchbar`-Spalten |
| **Blättern** | Seitengröße wählbar, Blätterer nach Abschnitt 6.7 |
| **Aktive Filter** | als Marken, jede entfernt ihren eigenen Filter |
| **Ergebnisleiste** | Trefferzahl in `role="status"`, Sortierung im Klartext, Stand |
| **Leerzustand** | der Satz der Seite plus ein Knopf, der die Filter zurücksetzt |
| **Schmale Fenster** | Kartenumbruch unter 48rem Spaltenbreite |

---

## 2. Sortierung

Die Spaltenüberschrift ist ein Knopf. Ein Klick sortiert aufsteigend, der
nächste absteigend, der dritte hebt die Sortierung auf und stellt die
Grundordnung der Seite wieder her.

| Zustand | Zeichen | Für Vorleseprogramme |
| --- | --- | --- |
| nicht sortiert | ⇅ | `aria-sort="none"` |
| aufsteigend | ▲ | `aria-sort="ascending"` |
| absteigend | ▼ | `aria-sort="descending"` |

Das Zeichen steht neben dem Namen der Spalte, nicht statt seiner. Sortiert
wird nach der **Art** der Spalte, nicht nach ihrem Text:

| Art | Sortiert nach |
| --- | --- |
| `text` | deutscher Sortierfolge, Groß- und Kleinschreibung gleich |
| `zahl` | Zahlenwert, nicht Zeichenkette — 9 kommt vor 10 |
| `zeit` | Zeitpunkt |
| `vorrang` | Dringlichkeit: Staatsnot, Blitz, Sofort, ohne — nicht alphabetisch |

Die letzte Zeile ist der Grund, warum Sortierung ins Bauteil gehört und nicht
in jede Seite: Wer Vorrangstufen alphabetisch sortiert, stellt „Blitz" vor
„Staatsnot" und macht die Spalte wertlos.

---

## 3. Suche

Drei Wege, ein Formular.

**Volltext** über der Tabelle, ein Feld, sucht in allen `suchbar`-Spalten.
Darunter steht ein Satz, welche Spalten das sind — ohne ihn ist eine leere
Trefferliste nicht deutbar.

**Je Spalte** eine eigene Maske, direkt unter der Spaltenüberschrift. Sie
zeigt, was in **dieser** Spalte passt. Mehrere Spaltensuchen wirken zusammen
(und, nicht oder).

**Filter** für Spalten mit begrenztem Wertevorrat — Richtung, Vorrangstufe,
Bearbeitungsstand. Als Auswahlfeld, erster Eintrag immer eine Alle-Stufe mit
Namen („Alle Vorrangstufen", nicht „—").

Die Spaltenmasken stehen in einer eigenen Zeile unter dem Kopf und lassen
sich als Ganzes ausblenden. Sie sind eingeblendet, sobald eine davon gefüllt
ist.

---

## 4. Ohne JavaScript

`UX-OHNE-JAVASCRIPT` verlangt, dass Aufnehmen, Sichten und Befördern ohne
Skript funktionieren, und lässt zu, dass **Komfort** daran hängt. Daraus
folgt die Bauweise dieses Bauteils:

| | ohne JavaScript | mit JavaScript |
| --- | --- | --- |
| Liste rendert, Zeilen lesbar | ja | ja |
| Eintrag öffnen | ja | ja |
| Blättern | ja, als Formular | ja |
| Sortieren | ja, Seitenaufbau je Klick | sofort, ohne Neuladen |
| Spaltensuche, Filter | ja, auf „Anwenden" | während des Tippens |

**Der Server kann alles.** Das Skript macht dasselbe schneller und schickt
nur nach, was sich geändert hat. Fällt es aus, bleibt eine vollständig
bedienbare Tabelle stehen — keine leere Fläche.

Der Zustand steht in der Adresse (`method="get"`): Eine sortierte, gefilterte
Liste lässt sich weitergeben und als Lesezeichen ablegen.

---

## 5. Wo es eingesetzt wird

| Seite | Vorher | Jetzt |
| --- | --- | --- |
| Nachweisung Eingang | keine Suche, kein Filter | Bauteil, eigene Auswahl |
| Nachweisung Ausgang | keine Suche, kein Filter | Bauteil, eigene Auswahl |
| „Stab lesen" | Blättern ohne Wirkung | Bauteil, **fremde** Auswahl |
| Anhänge | eigene Gestaltung, keine Suche | Bauteil, eigene Auswahl |
| Benutzerliste | eigene Gestaltung, keine Suche | Bauteil, eigene Auswahl |
| Meldungsübersicht | sechs Bänder, aber eigener Aufbau | Bauteil, **fremde** Auswahl |
| Vordruckliste | eigene Gestaltung | Bauteil, eigene Auswahl |

„Eigene Auswahl" heißt: Das Bauteil siebt, sortiert und blättert selbst über
die übergebenen Zeilen. „Fremde Auswahl" heißt: Die Seite tut das in der
Datenbank und reicht ein Ergebnis herein — weil an ihrer Abfrage die
Berechtigungsprüfung hängt. Beide Fälle sehen gleich aus; nur die
Suchbänder bleiben im zweiten Fall die der Seite.

**Eine Tabelle, die das Bauteil nicht benutzt, ist ein Befund.** Die Prüfung
`tests/php/ges_tabelle_einheitlich.php` zählt die Datentabellen der
Anwendung. Sie ist beim Zählen auf 22 weitere gestoßen, die in diesem
Abschnitt nicht standen und in keiner Rückmeldung vorkamen: die Bücher ETB
und TBB, Führungsstellenübersicht und -verwaltung, der Systemstand, die
Empfängermatrix, die Kategorienpflege und die übrigen Listenarten in
`liste.php`. Sie stehen namentlich und mit Grund in der Prüfung; die Zahl
darf sinken, nicht steigen.

---

## 6. Was geprüft wird

| Kennung | Soll |
| --- | --- |
| `GES-TABELLE-EINHEITLICH` | Jede Tabelle der Anwendung kommt aus dem Bauteil. Keine Seite schreibt ihr eigenes Tabellenmarkup. |
| `GES-TABELLE-SORTIERUNG` | Jede als sortierbar benannte Spalte trägt einen Knopf mit `aria-sort`; Zahlen sortieren numerisch, Vorrangstufen nach Dringlichkeit. |
| `GES-TABELLE-SUCHE` | Volltext über alle suchbaren Spalten, dazu je Spalte eine eigene Maske; der Satz nennt, worin gesucht wird. |
| `GES-TABELLE-BLAETTERN` | Der Blätterer wechselt die Seite; die aktuelle Seite ist Text mit `aria-current`, nicht verfügbare Griffe sind gesperrt statt weggelassen. |
| `GES-TABELLE-OHNE-SKRIPT` | Ohne JavaScript rendert die Liste, Einträge lassen sich öffnen, Blättern und Filtern gehen über Formulare. |

Dazu gilt alles, was `docs/GESTALTUNG.md` für Tabellen und für Suche und
Filter schon verlangt.

---

## 7. Warum ein Bauteil und nicht eine Vorlage

Eine Vorlage wird kopiert und läuft auseinander — genau das ist passiert, und
das Ergebnis sind diese sechs Rückmeldungen. Ein Bauteil hat eine
Aufrufstelle und eine Prüfung.

Der Preis ist, dass eine Seite mit besonderen Wünschen sie nicht mehr einfach
hinschreiben kann; sie muss den Wunsch dem Bauteil beibringen. Das ist
Absicht: Ein Wunsch, der es nicht wert ist, für alle Tabellen zu gelten, ist
es meist auch für eine nicht.
