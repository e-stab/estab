# SPEC — Fernmeldeplanung nach Fb Fü 76

Überarbeitung der S6-Fernmeldeplanung. Ziel ist, dass eine Person, die den
Kommunikationsplan (Fb Fü 76) und die Kommunikationsskizze (Fb Fü 77) aus der
Ausbildung kennt, die Bildschirmfassung ohne Umlernen bedient.

Dieses Dokument steht **unter** `SPEC.md` (Vorschrift und Bedienung) und
`docs/GESTALTUNG.md` (Gestaltung). Es vertieft dort das Modul **M7
`fuehrungsmittel`** und ändert an den übrigen Modulen nichts. Wo es einer der
beiden Spezifikationen widerspricht, wird die dortige Regel geändert und die
Änderung hier begründet — nicht umgangen.

---

## 1. Anlass

Fünf Rückmeldungen des Betreibers, in der Reihenfolge, in der sie unten
entschieden werden:

1. Die Herkunft der Übertragungsmedien (`Fe`, `Fu`, `Me`, `FAX`, `FS`, `@`)
   ist unklar. Woher kommen sie, was ist ihr Sinn?
2. Der Plan beschreibt keine Gegenstellen, sondern die **eigene**
   Erreichbarkeit. Das kommt in der Oberfläche nicht an.
3. Zwei Freitextfelder — „Besondere Vermerke" und „Bemerkungen" — ohne
   erkennbaren Unterschied. Zusammenführen.
4. „Rufname" trägt nicht, sobald das Medium Telefon oder E-Mail ist. Ein
   Begriff, der alle Fälle einschließt, fehlt.
5. Digitalfunk und Analogfunk stehen unter einem Medium und teilen sich
   dieselben technischen Felder. Sie sind zu trennen.

---

## 2. Normative Quellen

Ergänzend zu `SPEC.md` Abschnitt 2.1. Die Vorrangregel aus `SPEC.md`
Abschnitt 2.2 (Sachnähe → Alter → Auffangnorm) gilt unverändert weiter.

| Kürzel | Dokument | Stand | Art |
| --- | --- | --- | --- |
| Q4 | THW-DV 1-101, Handbuch Führen im THW, Kapitel 6 | 01.01.2006 | Vorschrift |
| Q6 | Fb Fü 76, Kommunikationsplan (Vordruck und Allgemeines) | — | Vordruck |
| Q7 | Fb Fü 77, Kommunikationsskizze 1–5 (Vordruck) | — | Vordruck |
| Q8 | Bereichsausbildung Sprechfunk, Digitalfunk BOS — Technische Grundlagen, Grundlagen, Rufgruppenbildung, Sprechfunkgeräte | 10.2020 ff. | Ausbildungsunterlage |
| Q9 | Taktische Zeichen für Hilfsorganisationen, Jonas Köritz, <https://github.com/jonas-koeritz/Taktische-Zeichen> | fortlaufend | Zeichensammlung |
| Q10 | PDV 800, Informations- und Kommunikationstechnik im Einsatz | Ausgabe 2017 | Vorschrift |
| Q11 | THW-DV 1-820, Dienstvorschrift für den Digitalfunk BOS in der BA THW | 01.08.2016 | Vorschrift |
| Q12 | NBHB THW, Nutzungs- und Betriebshandbuch für den Digitalfunk BOS in der BA THW | 09.01.2025 | Betriebshandbuch |

**Sachnähe für den Aufbau des Plans liegt bei Q6/Q7.** So wie Q1 für die
Felder des Nachrichtenvordrucks gilt, gilt Q6 für die Felder des
Kommunikationsplans: Es ist das Dokument, das der Ausbildung den Plan zeigt.
Q4 Kapitel 6 bleibt Auffangnorm für alles, was der Vordruck nicht bebildert.

**Q8 ist keine Vorschrift**, sondern Ausbildungsunterlage. Sie zitiert das
NBHB THW und die THW-DV 1-820 — beide liegen nun als **Q12** und **Q11** vor.
Wo Q8 und Q11/Q12 dasselbe sagen, zitiert dieses Dokument die Vorschrift; wo
Q8 allein steht, ist das gekennzeichnet.

**Q10 ist der Nachfolger, den Q4 selbst benennt.** Q4 Kapitel 6 sagt:
„Grundlage bildet die PDV / DV 800 (Fernmeldeeinsatz) in der jeweils gültigen
Fassung oder ein Nachfolger dieser Vorschrift." Die vorliegende PDV 800 trägt
die Ausgabe **2017** und heißt heute „Informations- und Kommunikationstechnik
im Einsatz". Nach der Vorrangregel (Sachnähe, dann Alter) verdrängt sie
Q4 Kapitel 6 überall dort, wo beide dieselbe Frage regeln — Q4 bleibt
Auffangnorm für die THW-Besonderheiten, die eine Polizeidienstvorschrift nicht
kennt.

**Zum Umgang mit Q10.** Die Vorschrift ist „nur für den internen Gebrauch bei
der Polizei bestimmt", trägt aber ausdrücklich keine Einstufung nach der
Verschlusssachenanweisung. Dieses Dokument zitiert daraus Anforderungen und
gibt sie nicht wieder; die Anwendung liefert sie nicht aus.

### 2.1 Was die nachgereichten Vorschriften geändert haben

Q10 bis Q12 wurden nachgereicht, nachdem die Beschlüsse B1 bis B5 standen. Sie
haben **keinen** Beschluss umgestoßen. Drei Einzelheiten sind zu berichtigen,
vier Regeln bekommen eine bessere Quelle, eine Frage kommt neu hinzu.

| Wirkung | Betrifft |
| --- | --- |
| **bestätigt** | Q10 Anlage 1 ist ein Kommunikationsplan-Muster und **je Stelle gegliedert** — eine zweite, unabhängige Quelle für `FMP-STELLENBILD` (Abschnitt 3.2) |
| **bestätigt** | Q10 Anlage 20 definiert *Betriebsstelle* als „Stelle im IuK-Netz, bei der Nachrichten aufgenommen, befördert oder übermittelt werden" — genau die Lesart aus Beschluss B2 |
| **bestätigt** | Q10 Anlage 20 definiert *Relaisstelle* — das Feld aus Abschnitt 8.2 steht damit in einer Vorschrift |
| **verstärkt** | Q12 Kapitel 2.4 begründet Entscheidung O2 normativ (unten) |
| **verstärkt** | Q10 Nummer 2.6 begründet Entscheidung O7 normativ (unten) |
| **berichtigt** | Repeater und Gateway sind zu **beantragen**, nicht anzuzeigen (Q12 Kap. 2.3.3) |
| **berichtigt** | Die Verkehrsform des Digitalfunks ist nicht „nicht vorgesehen", sondern **netzseitig festgelegt** (Q12 Kap. 2.4.2) |
| **berichtigt** | Telefonie über den Digitalfunk ist **gesperrt** (Q12 Kap. 2.4.3) — ein Weg, den niemand planen darf |
| **neu** | Q10 kennt die *Rückfallebene* als Planungsgegenstand → offene Frage **O9** |
| **verkleinert** | Lücken L1 und L2 (Abschnitt 15) |

---

## 3. Befund am Bestand

### 3.1 Was heute existiert

| Ort | Rolle |
| --- | --- |
| `nv_fernmeldeplaene` | Kopf: Version, Status `ENTWURF`/`AKTIV`/`ERSETZT`, Einsatzbezeichnung, Herkunft, Gültigkeit, Betriebsleitung, Bemerkungen, Freigabe |
| `nv_fernmeldeplan_eintraege` | Wege: Betriebsstelle, Rufname, Medium, Kanal, Bandlage, Verkehrsform, Besondere Vermerke, Bemerkungen |
| [dv_operations.php:47](app/dv_operations.php:47) | `ESTAB_DV_MEDIA` und `ESTAB_DV_MEDIA_DEFINITIONS` — Medienkatalog mit Feldsteuerung |
| [fuehrungsstelle.php:190](4fach/fuehrungsstelle.php:190) | Eintragsformular |
| [fuehrungsstelle.php:1185](4fach/fuehrungsstelle.php:1185) | Wegetabelle des aktiven Plans |
| [message_repository.php:1765](app/message_repository.php:1765) | LdF disponiert einen Weg; der Weg schreibt Feld 1 |
| [incident_export.php:1359](app/incident_export.php:1359) | Ausleitung der Versionen und Einträge |
| `nv_komplan` | **Alttabelle** ohne Oberfläche: `stelle, orga, rufname, kanal4, kanal2, tel1, tel2, mobil1, mobil2, fax1, fax2, e-mail, ftphttp` |

### 3.2 Der aufschlussreiche Befund

`nv_komplan` bildet **Q6 eins zu eins ab**: eine Zeile je Stelle, darin
`kanal4` und `kanal2` — die „Kanal 4 m"- und „Kanal 2 m"-Zeilen des Vordrucks
— sowie zwei Telefon-, zwei Mobil- und zwei Faxfelder. Das ist die Gestalt des
Papiers: **ein Kasten je Stelle, darin alle ihre Wege**.

Das vorschriftenkonforme Nachfolgemodell hat diese Gestalt verloren. Es führt
**eine Zeile je Weg** und die Stelle nur noch als Textspalte. Fachlich ist das
richtiger — ein Weg hat einen eigenen Zustand, eine eigene Verkehrsform, einen
eigenen Vermerk. Für den Wiedererkennungswert ist es ein Rückschritt: Wer den
Vordruck kennt, sucht den Kasten seiner Stelle und findet eine flache Liste.

Daraus folgt der Kern der Überarbeitung: **Speicherung je Weg, Darstellung je
Stelle.** Beides ist ohne Widerspruch möglich (Abschnitt 10).

### 3.3 Was schiefsteht

| Nr. | Befund | Ort |
| --- | --- | --- |
| F1 | `kanal` ist mit „Kanal oder Rufgruppe" beschriftet und trägt beide Techniken in einem Feld | [fuehrungsstelle.php:236](4fach/fuehrungsstelle.php:236) |
| F2 | `bandlage` gibt es nur für `Fu`, aber ohne Unterscheidung analog/digital — im Digitalfunk existiert keine Bandlage | [dv_operations.php:57](app/dv_operations.php:57) |
| F3 | `verkehrsform` ist für **alle** Medien Pflicht und heißt bei Nicht-Funk „Verkehrsform oder besondere Behandlung" — ein Sammelfeld ohne Quelle | [dv_operations.php:54](app/dv_operations.php:54) |
| F4 | `besondere_vermerke` und `bemerkungen` stehen ohne Abgrenzung nebeneinander; die Wegetabelle klebt sie ohnehin wieder zusammen | [fuehrungsstelle.php:1177](4fach/fuehrungsstelle.php:1177) |
| F5 | `rufname` ist Pflicht, auch wenn das Medium keinen Rufnamen kennt | [fuehrungsstelle.php:214](4fach/fuehrungsstelle.php:214) |
| F6 | Der Kopf kennt keinen Verschlusssachenvermerk; Q6/Q7 drucken „N f D" | `nv_fernmeldeplaene` |
| F7 | Der Kopf trennt Herausgeber und F.d.R. nicht nach Q6; die Dienststellung fehlt | `nv_fernmeldeplaene` |
| F8 | Nichts in der Oberfläche sagt, dass der Plan die **eigene** Erreichbarkeit führt | [fuehrungsstelle.php:1113](4fach/fuehrungsstelle.php:1113) |
| F9 | `nv_komplan` liegt tot im Schema und wird von `readiness.php` weiter geprüft | [readiness.php:150](app/readiness.php:150) |
| F10 | Der Plan-Zweig der Vorschlagszuordnung schlägt die **eigene** Erreichbarkeit als Gegenstelle vor: `betriebsstelle` → `rufname` landet in Feld 6 | [read_authorization.php:270](app/read_authorization.php:270) |
| F11 | Die Vorschläge sortieren nach Herkunft **vor** Güte; ein ähnlicher Historientreffer schlägt einen exakten Plantreffer | [read_authorization.php:695](app/read_authorization.php:695) |

---

## 4. Beschluss B1 — Herkunft und Sinn der Übertragungsmedien

### 4.1 Die Antwort

`Fe`, `Fu`, `Me`, `FAX`, `FS`, `@` ist **keine Techniksystematik**. Es ist die
Ankreuzzeile **Feld 1 und Feld 7 des Nachrichtenvordrucks** (Q1): Fernsprecher,
Funk, Melder, Telefax, Fernschreiber, Datenübertragung. Dieselbe Wertemenge
steht in `nv_nachrichten.01_medium` und wird in
[message_transport.php:90](app/message_transport.php:90) auf ihre Klartexte
abgebildet.

Der Fernmeldeplan hat diese Menge **absichtlich übernommen**, und darin liegt
ihr ganzer Sinn: Wenn der LdF einen Weg des Plans disponiert, schreibt dieser
Weg unmittelbar Feld 1 — das tatsächlich benutzte Übermittlungsmittel — ohne
Übersetzungstabelle dazwischen
([message_repository.php:1853](app/message_repository.php:1853)). Eine eigene,
„modernere" Medienliste im Plan hieße, an genau dieser Stelle wieder zu
übersetzen, und jede Übersetzung ist eine Fehlerquelle im Nachweis.

**Daraus folgt die harte Regel:** Die Wertemenge des Mediums wird **nicht
erweitert**. Alles Feinere — analog gegen digital, TMO gegen DMO, E-Mail gegen
Messenger — steht **neben** dem Medium, nicht **in** ihm.

### 4.2 Was die Vorschrift stattdessen kennt

Q4 Kapitel 6.3 zählt TK-Verbindungen ganz anders auf:

> Telefonverbindungen, Telefaxverbindungen, Sprechfunkverbindungen,
> Richtfunkverbindungen, Datenverbindungen.

Q3 Kapitel 5.1 ergänzt Internet, E-Mail und Messenger als Datenübertragung
(bereits entschieden als `TKM-KATALOG` in `SPEC.md` Abschnitt 5.8).

Die beiden Listen decken sich nicht. Der Vordruck kennt den **Melder**, den
Q4 Kapitel 6.3 nicht als TK-Verbindung führt (ein Melder ist keine
Verbindung, sondern ein Mensch). Q4 kennt den **Richtfunk**, den der Vordruck
nicht ankreuzt. Beides bleibt so: Der Plan folgt dem Vordruck, weil an ihm
Feld 1 hängt; Richtfunk ist eine Trägertechnik unter `Fe`, `FAX` oder `@` und
gehört in die Bemerkung.

### 4.3 Entscheidungen je Medium

| Medium | Entscheidung | Begründung |
| --- | --- | --- |
| `Fe` Fernsprecher | bleibt | Q4 6.3 Telefonverbindungen |
| `Fu` Funk | bleibt, wird intern in analog/digital geteilt | Beschluss B5 |
| `Me` Melder | bleibt, **ohne** technische Felder | Q4 6.4; keine Verbindung, sondern ein Laufweg |
| `FAX` Telefax | bleibt | Q4 6.3 Telefaxverbindungen |
| `FS` Fernschreiber | **entfällt im Plan**, bleibt im Vordruck (Entscheidung O1) | siehe unten |
| `@` Datenübertragung | bleibt, bekommt eine Unterart | Q3 5.1: E-Mail, Messenger, Internet |

**Zum Fernschreiber.** Das Fernschreibnetz ist abgeschaltet; keine THW-Stelle
ist über Telex erreichbar. Der Plan führt, was tatsächlich betrieben wird —
ein Medium ohne Gerät ist kein Weg. Der **Vordruck** behält sein Kästchen
unverändert, weil er es druckt und weil Altnachrichten es tragen; nur die
Auswahlliste des Plans bietet es nicht mehr an. Bestehende Einträge bleiben
lesbar und werden wie andere Altangaben gekennzeichnet. Die Rücknahme dieser
Entscheidung ist eine Zeile in `ESTAB_DV_PLAN_MEDIA`.

---

## 5. Beschluss B2 — Eigene Erreichbarkeit als Gerüst, Gegenstellen daran

### 5.1 Zwei Fragen, eine Struktur

Der Fernmeldeplan beantwortet zwei Fragen, und die zweite hängt an der ersten:

1. **Wie sind die Stellen des eigenen Verbundes erreichbar?** → der **Weg**
2. **Wen erreiche ich über diesen Weg, und unter welcher Erreichbarkeit?** →
   die **Gegenstellen am Weg**

Der Unterschied zum Adressbuch liegt in der Richtung: Ein Adressbuch führt
Namen und fragt hinterher nach dem Weg. Der Fernmeldeplan führt **Wege** und
sagt, wen sie erreichen. Eine Gegenstelle steht deshalb nie für sich, sondern
immer an genau einem Weg. Wer keinen Weg hat, hat auch keine Gegenstelle.

Das deckt sich mit Q4 Kapitel 6.1.2: Befehlsstellen sind so auszustatten, dass
„alle eingesetzten Kräfte über TK-Verbindungen zu erreichen sind" — die
Erreichbarkeit aller Kräfte ist Gegenstand der Planung, aber **über eine
Verbindung**, nicht daneben.

Was weiterhin **nicht** in den Plan gehört: die Gegenstelle *einer einzelnen
Nachricht*. Die wird im Vordruck geführt und nirgends sonst:

| Feld (Q1) | Inhalt | Datenbank |
| --- | --- | --- |
| 6 | Rufname der Gegenstelle / Spruchkopf | `05_gegenstelle` |
| 11 | Rufnummer der Gegenstelle | `11_rufnummer` |
| 13 (Q2) | Einheit/Einrichtung/Stelle | `13_abseinheit` |

Der Plan **liefert den Vorschlag** für diese Felder. Er ersetzt sie nicht, und
er schreibt sie nicht fest.

### 5.2 Die Stelle bekommt eine Art

Q4 Kapitel 6.1.2 verlangt TK-Verbindungen „vertikal und horizontal"; Q6 setzt
„FüSt"-Kästen neben „NSt"-Kästen. Ein neues Feld `stellenart` macht sichtbar,
in welche Richtung eine Verbindung zeigt:

| Wert | Bedeutung |
| --- | --- |
| `EIGEN` | die eigene Führungsstelle |
| `UEBER` | übergeordnete Stelle (vertikal nach oben) |
| `UNTER` | nachgeordnete Stelle (vertikal nach unten) |
| `NEBEN` | benachbarte Stelle, Partnerorganisation (horizontal) |

Die Spalte „Betriebsstellen-Klarbezeichnung" heißt künftig **„Stelle"** mit der
Ausfüllhilfe „Stelle des eigenen Verbundes: Führungsstelle, Fernmeldezentrale,
Meldekopf, Einheit". Der Datenbankname `betriebsstelle` bleibt.

### 5.3 Gegenstellen am Weg

Jeder Weg trägt eine geordnete Liste von Gegenstellen. Eine Gegenstelle hat
**zwei** Angaben, weil der Vordruck zwei braucht:

| Angabe | Was | Speist |
| --- | --- | --- |
| `name` | Klarbezeichnung der Stelle oder Einheit | Feld 13 (Eingang), Feld 10 (Ausgang) |
| `erreichbarkeit` | Rufname, Rufnummer, Adresse — je nach Medium des Wegs | Feld 6 bzw. Feld 11 |

Die Bemerkung der Gegenstelle nimmt auch die **Betriebszeiten** auf (Q4
Kapitel 6.6, „Regeln der Betriebszeiten"). Ein eigenes Feld bekommt sie nicht
(Entscheidung O8).

**Kein eigenes Medium.** Das Medium ist das des Wegs — genau das ist die
Aussage: „über *diesen* Weg ist *diese* Stelle unter *dieser* Erreichbarkeit zu
haben." Dieselbe Stelle darf unter mehreren Wegen stehen (Funk **und**
Fernsprecher); das ist kein Dubletten-, sondern der Regelfall.

Die Beschriftung folgt Beschluss B4: Im Formular steht der Begriff des
Mediums, in der Tabelle „Erreichbar unter".

**Welches Vordruckfeld gefüllt wird, entscheidet das Medium des Wegs:**

| Medium des Wegs | Feld 6 (Rufname der Gegenstelle) | Feld 11 (Rufnummer) | Feld 13 / Feld 10 |
| --- | --- | --- | --- |
| `Fu` Funk | `erreichbarkeit` (Funkrufname) | — | `name` |
| `Fe`, `FAX` | `name` | `erreichbarkeit` (Rufnummer) | `name` |
| `@` Datenübertragung | `erreichbarkeit` (Adresse, Kennung) | — | `name` |
| `Me` Melder | `name` | — | `name` |

Feld 6 nimmt bei `@` die Adresse, weil `TKM-KATALOG` (`SPEC.md` Abschnitt 5.8)
bereits entschieden hat, dass bei Datenübertragung „der genaue Weg in Feld 6
gehört".

### 5.4 Befund: Die heutige Zuordnung liest das Falsche

Eine Vorschlagsmechanik mit Herkunftsunterscheidung existiert **bereits** —
`estab_read_ldf_mapping_suggestions()`
([read_authorization.php:514](app/read_authorization.php:514)) liefert je
Vorschlag ein `source` von `message` oder `plan`, und
[4fachform.php:535](4fach/4fachform.php:535) beschriftet sie mit
„Bestätigtes Nachrichtenpaar" bzw. „Aktiver S6-Fernmeldeplan", dazu die Güte
„Exakt"/„Ähnlich".

Nur: Der Plan-Zweig liest die **falsche Spalte**.
`estab_read_ldf_mapping_policy()`
([read_authorization.php:270](app/read_authorization.php:270)) paart für den
Ausgang `betriebsstelle` → `rufname` und schreibt das Ergebnis nach
`05_gegenstelle` — also **Feld 6, den Rufnamen der Gegenstelle**. Der Wert
stammt aber aus der Zeile, die nach Beschluss B2 die **eigene** Erreichbarkeit
trägt.

Solange der Plan stillschweigend als Adressbuch gelesen wurde, ging das auf.
Mit der Trennung wird es falsch: eStab schlägt dem LdF den eigenen Rufnamen als
Gegenstelle vor. Das ist **F10** und der eigentliche Grund, warum diese
Überarbeitung die Gegenstellenliste braucht — sie ist keine Zutat, sie ist die
fehlende Tabelle, auf die die vorhandene Abfrage schon immer zeigen wollte.

**F11 — die Rangfolge steht falsch herum.** Die Abfrage sortiert
`ORDER BY source_priority, match_priority, …`
([read_authorization.php:695](app/read_authorization.php:695)), und
`source_priority` vergibt heute **0 an die Historie** und 1 an den Plan. Der
freigegebene Plan steht damit unter den Nachrichtenpaaren.

Der Betreiber hat das gedreht (Entscheidung O7): **Der Plan steht oben.** Es
werden weiterhin beide Quellen angezeigt, und jede trägt ihre Kennzeichnung.
Die Herkunft bleibt vor der Güte — nur mit vertauschten Rängen. Die Folge ist
gewollt und wird hier festgehalten, damit sie später niemand für einen Fehler
hält: Ein **ähnlicher** Treffer aus dem Plan steht über einem **exakten** aus
der Historie. Beide sind sichtbar, beide sind beschriftet; die Wahl bleibt
beim Bediener.

### 5.5 Was daraus im Vordruck wird

| Nr. | Soll |
| --- | --- |
| V1 | Der Plan-Zweig der Zuordnung liest `nv_fernmeldeplan_gegenstellen` (`name` ↔ `erreichbarkeit`) statt `betriebsstelle` ↔ `rufname`. Die Abfrageform bleibt; nur die Tabelle wechselt. |
| V2 | Sortierung: erst Herkunft (**Plan vor Historie**), dann Güte (`exact` vor `related`), dann Häufigkeit und Jüngstes. `source_priority` wird getauscht, nicht die Struktur. |
| V3 | Die Herkunft ist **an jedem** Vorschlag sichtbar, nicht nur bei den LdF-Zuordnungen. Ein Vorschlag ohne Etikett ist ein Vorschlag aus der Historie und wird als solcher beschriftet. |
| V4 | Ein Vorschlag aus dem Plan nennt den Weg, über den er gilt, im vorhandenen Bezugsfeld: „Aktiver S6-Fernmeldeplan · über Funk (digital), Rufgruppe …". |
| V5 | A/W erhält bei `FM-Eingang` ebenfalls Plan-Vorschläge für Feld 6. Heute gibt es dort nur Historie ([read_authorization.php:205](app/read_authorization.php:205)). |
| V6 | Feld 11 (`11_rufnummer`) wird vorschlagsfähig — heute lässt die Politik nur Feld 6 und Feld 13 zu. |
| V7 | Freie Eingabe bleibt in allen Fällen möglich. Ein Vorschlag ist Hilfe, keine Auswahlliste. |
| V8 | Der **Stab** erhält beim Abfassen einer ausgehenden Nachricht Vorschläge für Feld 10 — **ausschließlich aus dem Plan**, nie aus der Historie. |

V7 ist keine Höflichkeit: Eine Gegenstelle, die im Plan fehlt, meldet sich
trotzdem. Der Vordruck darf sie nicht abweisen.

**Zu V8 (Entscheidung O6).** Die heutige Beschränkung auf A/W und LdF
([read_authorization.php:205](app/read_authorization.php:205)) schützt vor
einem konkreten Leck: Die Historie führt Werte aus **fremden** Nachrichten
desselben Einsatzes: Wer sie vorgeschlagen bekommt, erfährt etwas über
Vorgänge, die ihn nichts angehen. Der **Plan** hat dieses Problem nicht — er
ist eine veröffentlichte, für die gesamte Besetzung einsehbare
Betriebsunterlage (`TKM-FERNMELDEPLAN`).

Die Politik bekommt deshalb keine gelockerte Rolle, sondern eine **zweite
Achse**: Sie sagt künftig nicht nur, *welches Feld* eine Rolle bezuschlagen
darf, sondern auch, *aus welchen Quellen*. Für den Stab an Feld 10 lautet die
Antwort `['plan']`, für A/W und LdF `['message', 'plan']`. Ohne diese Trennung
wäre O6 ein Rückbau des Schutzes; mit ihr ist es keiner.

### 5.5.1 Warum die Herkunft sichtbar sein muss

Beide Quellen sagen Verschiedenes, und nur der Bediener kann entscheiden:

- **Aktiver S6-Fernmeldeplan** — freigegeben, mit Stand und F.d.R., aber
  möglicherweise überholt. Q4 Kapitel 6.6 verlangt „Verwendung einheitlicher
  und aktueller Betriebsunterlagen".
- **Bestätigtes Nachrichtenpaar** — empirisch belegt, aber vielleicht ein
  einmaliger Sonderfall oder ein alter Fehler, der sich fortpflanzt.

Ein Vorschlag ohne erkennbare Herkunft nimmt diese Entscheidung weg, ohne sie
zu treffen.

Mit Entscheidung O7 ist die Kennzeichnung kein Schmuck mehr, sondern das
**Gegengewicht zur Rangfolge**: Weil der Plan oben steht, muss erkennbar sein,
dass er oben steht, *weil* er der Plan ist — und nicht, weil er besser passt.

### 5.6 Die Wegewahl spricht so

Die Auswahlliste bei der Disposition des LdF fragt nicht „an wen", sondern
**„über welchen Weg"**. Die Gegenstellen des Wegs stehen als Erläuterung
darunter — sie begründen die Wahl, sie sind nicht die Wahl.

### 5.7 Abgrenzung

Ob ein Eintrag missbräuchlich eine fremde Stelle als Adressbucheintrag führt,
prüft die Anwendung **nicht**. Eine Stelle einer Partnerorganisation, mit der
eine Verbindung besteht, ist ein zulässiger Eintrag (`NEBEN`) — Q4 Kapitel 6.8
regelt die Mitbenutzung fremder Netze ausdrücklich. Die Unterscheidung ist
fachlich, nicht maschinell prüfbar; sie wird durch Benennung und Ausfüllhilfe
geführt, nicht durch Zurückweisung.

---

## 6. Beschluss B3 — Ein Bemerkungsfeld statt zwei

### 6.1 Entscheidung

`besondere_vermerke` und `bemerkungen` werden zu **einem** Feld `bemerkungen`
zusammengeführt. Beschriftung: **„Bemerkungen"**, Ausfüllhilfe: „Betriebszeiten,
Einschränkungen, Ersatzweg, Verkehrskreis (Führung, Einsatz, Versorgung),
Besonderheiten der Bedienung".

Der **Verkehrskreis** aus Q4 Kapitel 6.5 steht damit in der Ausfüllhilfe und
bekommt kein eigenes Feld (Entscheidung O3). Er wird geschrieben, wo er
gebraucht wird, und erzeugt keine Pflichtangabe, die bei kleinen Lagen
leerläuft.

Weder Q6 noch Q7 noch Q4 Kapitel 6 kennen zwei getrennte Vermerkfelder. Die
Trennung stammt aus keiner Quelle, und die Wegetabelle klebt beide Werte
ohnehin wieder zu einer Spalte zusammen
([fuehrungsstelle.php:1177](4fach/fuehrungsstelle.php:1177)). Zwei
Felder ohne Abgrenzungsregel erzeugen nur die Frage, in welches man schreibt.

### 6.2 Wie ohne Geschichtsfälschung

Aktive Pläne sind **unveränderlich** — dafür sorgen Auslöser aus
Migration 94 und 117 („Activated telecommunications plans are immutable"). Eine
Migration, die alte Zeilen umschreibt, würde genau die Zusage brechen, die die
Nachweisführung trägt.

Deshalb:

1. Die Spalte `besondere_vermerke` **bleibt im Schema**, wird aber
   `NULL`-fähig und **nur noch gelesen**.
2. Neue und geänderte Einträge schreiben ausschließlich `bemerkungen`.
3. Beim **Versionswechsel** — wenn ein Entwurf aus dem aktiven Plan
   vorbefüllt wird — führt die Kopie beide Werte in `bemerkungen` zusammen
   (`besondere_vermerke`, Leerzeile, `bemerkungen`) und lässt
   `besondere_vermerke` in der neuen Version leer.
4. Alte Versionen zeigen beide Werte wie bisher, mit dem Hinweis, dass es
   sich um die frühere Zweiteilung handelt.

So verschwindet die Zweiteilung mit der nächsten Version von selbst, ohne dass
ein einziges freigegebenes Blatt nachträglich anders lautet.

---

## 7. Beschluss B4 — „Erreichbar unter" statt „Rufname"

### 7.1 Entscheidung

| Ort | Begriff |
| --- | --- |
| Spaltenkopf der Tabelle, Datenfeldname | **Erreichbar unter** / `erreichbarkeit` |
| Beschriftung im Formular | der **konkrete** Begriff des gewählten Mediums |

Das Formular sagt nie „Erreichbar unter". Es sagt, was auf dem Papier steht:

| Medium | Beschriftung im Formular |
| --- | --- |
| Fernsprecher | Rufnummer |
| Funk analog | Funkrufname |
| Funk digital | Funkrufname |
| Telefax | Faxnummer |
| Datenübertragung, E-Mail | E-Mail-Adresse |
| Datenübertragung, Messenger | Kennung im Dienst |
| Melder | Meldekopf oder Sammelstelle |

### 7.2 Begründung

Q6 braucht **kein** Oberwort: Der Vordruck beschriftet jedes Feld einzeln
(„FuRN:", „Tel.:", „Fax:"), weil er je Medium eine eigene Zeile druckt. Ein
Oberwort wird erst nötig, wo eine **Tabelle** alle Medien in eine Spalte legt
— also genau an einer Stelle, die das Papier nicht hat. Dort ist der Betreiber
frei (`SPEC.md` Abschnitt 2.2: wo die Vorschrift schweigt, entscheidet P1).

„Erreichbar unter" ist gewählt, weil es die Sache aus **Beschluss B2** benennt
— den Blick von außen auf die eigene Stelle — und in jedem Medium ohne
Verrenkung liest.

### 7.3 Verworfene Alternativen

| Wort | Warum nicht |
| --- | --- |
| Anschluss | leitergebunden gedacht; ein Funkrufname ist kein Anschluss |
| Kennung | technisch, kein Wort der Ausbildungsunterlagen |
| Adresse | im Vordruck belegt — Feld 10 ist die Anschrift des Empfängers |
| Rufname/Nummer/Adresse | eine Aufzählung, die mit jedem Medium länger wird |
| Rufzeichen | Amateurfunk, nicht BOS |

---

## 8. Beschluss B5 — Analogfunk und Digitalfunk trennen

### 8.1 Der Satz, der die Sache entscheidet

Q8, Rufgruppenbildung, Folie 3:

> Analogfunk nutzt Kanäle. Digitalfunk nutzt Rufgruppen.

Damit ist ein gemeinsames Feld „Kanal oder Rufgruppe" (F1) fachlich falsch.
Ein Digitalfunkweg hat keinen Kanal und keine Bandlage; ein Analogfunkweg hat
keine Rufgruppe und keine Betriebsart.

### 8.2 Modell

Das Medium bleibt `Fu` (Beschluss B1). Ein neues Feld `funkart` mit den Werten
`ANALOG` und `DIGITAL` entscheidet, welche technischen Felder gelten. In der
Auswahlliste erscheinen **zwei Einträge** — „Funk (analog)" und „Funk
(digital)" —, die beide `Fu` speichern. Der Vordruck merkt davon nichts.

**Analogfunk** (Werte vorbehaltlich L2):

| Feld | Pflicht | Werte |
| --- | --- | --- |
| `band` | ja | `2m`, `4m` — Q6 druckt „Kanal 2 m" und „Kanal 2 / 4 m" |
| `kanal` | ja | Kanalnummer, frei |
| `bandlage` | ja | `O` Oberband, `U` Unterband |
| `verkehrsform` | ja | `W` Wechselverkehr, `G` Gegenverkehr, `bG` bedingter Gegenverkehr, `R` Richtungsverkehr |
| `relaisstelle` | nein | Bezeichnung der Relaisstelle |

**Digitalfunk** (Q8):

| Feld | Pflicht | Werte / Form |
| --- | --- | --- |
| `betriebsart` | ja | `TMO` Netzbetrieb, `DMO` Direktbetrieb |
| `rufgruppe` | ja | Rufgruppenname, z. B. `T_STD-OBUX-1`, `726_B*`, `TBZ_301_BOS` |

**Keine OPTA, keine ISSI** (Entscheidung O2). Die Entscheidung war eine
Betreiberentscheidung; Q12 macht eine Vorschriftenregel daraus. Q12
Kapitel 2.4:

> „Zur Identifizierung der Nutzerinnen und Nutzer ist ausschließlich das
> gesprochene Wort verbindlich. Die Operativ-Taktische Adresse (OPTA) […]
> erlaubt keine sichere Verifizierung der Identität."

Ein Plan, der die OPTA führte, führte also eine Angabe, die die Vorschrift
selbst für **nicht verbindlich** erklärt. Was verbindlich ist — das gesprochene
Wort —, ist der **Funkrufname**, und genau den führt der Plan. Die ISSI
kennzeichnet nach Q12 Kapitel 3 den Teilnehmer über die BOS-Sicherheitskarte;
ihre Verwaltung liegt nach Q11 Kapitel 2.2.1.3 bei der TTB-THW, nicht bei der
Führungsstelle.

Damit deckt sich Beschluss B2: Der Plan sagt, **wie** eine Stelle erreichbar
ist, nicht **welches Gerät** dort steht.

**Die Bemerkung ist kein Hintereingang.** Eine ISSI oder OPTA gehört auch dort
nicht hinein. Q12 Kapitel 2.4.2 nennt drei Fälle, in denen ein Einzelruf
zulässig ist — darunter „Kommunikation mit anderen BOS, wenn keine gemeinsame
Rufgruppe existiert", also durchaus ein Planungsfall. Der Plan hält ihn als
Absicht fest („Einzelruf vorgesehen"); die Ziel-ISSI wird nach Q12 am Gerät
eingegeben und im Sprechfunkverkehr ausgetauscht, nicht im Plan hinterlegt.

**Keine Verkehrsform im Digitalfunk — weil das Netz sie festlegt.** Nicht,
weil es sie nicht gäbe: Nach Q12 Kapitel 2.4.2 ist der Einzelruf bei den BOS
des Bundes „nur im Wechselverkehr/-sprechen freigeschaltet"; Voll-Duplex ist
gesperrt. Der Gruppenruf ist nach Q12 Kapitel 2.4.1 das Standardverfahren. Die
Verkehrsform ist damit für jeden Digitalfunkweg dieselbe und netzseitig
vorgegeben — ein Auswahlfeld hätte genau einen Wert.

**Telefonie über den Digitalfunk gibt es nicht.** Q12 Kapitel 2.4.3: Die
Überleitung ins öffentliche Telefonnetz „wird aus Kapazitäts- und
Sicherheitsgründen nicht verwendet und ist in der Funktionalität gesperrt".
Ein Weg mit Medium `Fe` ist deshalb nie ein Digitalfunkweg. Die Ausfüllhilfe
sagt das, damit niemand eine Verbindung plant, die das Netz nicht herstellt.

**Repeater und Gateway sind zu beantragen.** Q12 Kapitel 2.3.3 und 2.3.4 —
nicht anzuzeigen, wie Q8 (Folie 42) es formuliert. Sie sind ausschließlich
stationär zu betreiben. Beide sind Eigenschaften eines DMO-Wegs und stehen in
der Bemerkung. Q12 stellt sie ausdrücklich neben den Analogfunk: der Repeater
„ähnelt der Verwendung eines Rs1-Relais", das Gateway „ähnelt der
Überleiteinrichtung (Rs2)". Damit tragen Analog- und Digitalfunk denselben
Gedanken an zwei Stellen — dort das Feld `relaisstelle`, hier die Bemerkung.

**Der Wirkbereich gehört zur Rufgruppe.** Q12 Kapitel 2.3.1.1 nennt die
Gruppenrufzone „das Gebiet, in dem die Rufgruppe verwendet werden kann". Eine
Rufgruppe, deren Wirkbereich den Einsatzraum nicht deckt, ist eine Verbindung
auf dem Papier. Die Ausfüllhilfe zu `rufgruppe` nennt den Wirkbereich und die
Rufgruppenarten aus Q12 Kapitel 2.3.1 (OV, RSt, LV, Leitung, Sonder,
Ausbildungszentren, TBZ, Cross-Border, TMOa) sowie den Hinweis, dass der
gültige Rufgruppenplan nach Q11 Kapitel 2.2.1.4 in der Loseblattsammlung
steht.

**Repeater und Gateway** (Q8, Technische Grundlagen, Folien 42 und 48) sind
keine eigenen Wege, sondern Eigenschaften eines DMO-Weges. Sie stehen in der
Bemerkung. Die Ausfüllhilfe nennt die Anzeige- bzw. Antragspflicht gegenüber
der TTB-THW, weil der S6 sie sonst übersieht.

### 8.3 Technische Felder je Medium — Gesamtübersicht

| Medium | Erreichbar unter | Technische Felder | Verkehrsform |
| --- | --- | --- | --- |
| `Fe` Fernsprecher | Rufnummer | `anschlussart` (Amt, Nebenstelle, Mobilfunk, Sondernetz) | — |
| `Fu` analog | Funkrufname | `band`, `kanal`, `bandlage`, `relaisstelle` | ja |
| `Fu` digital | Funkrufname | `betriebsart`, `rufgruppe` | — |
| `FAX` | Faxnummer | `anschlussart` | — |
| `Me` Melder | Meldekopf | — | — |
| `@` Datenübertragung | Adresse oder Kennung | `datenart` (E-Mail, Messenger, Fachanwendung, Internet) | — |

`verkehrsform` ist damit **kein Pflichtfeld für alle Medien mehr** (F3). Was
dort bisher als „besondere Behandlung" landete, gehört nach Beschluss B3 in
`bemerkungen`.

---

## 9. Kopfleiste nach Q6

Q6, Allgemeines, verlangt eine dreigeteilte Kopfleiste:

> Das linke Feld enthält die herausgebende Dienststelle und ggf. die Funktion
> des Verfassers der Skizze, im mittleren Teil ist die Art der Skizze und ihr
> Verwendungsbereich anzugeben, in das rechte Feld ist der Gültigkeitsvermerk
> und der Verschlusssachenvermerk einzutragen.

Und weiter: Zeichner und Herausgeber unterschreiben mit Angabe der
Dienststellung für die Richtigkeit (F.d.R.).

| Q6 | eStab heute | Soll |
| --- | --- | --- |
| links: herausgebende Dienststelle | `herkunft` | umbenennen in **Herausgebende Dienststelle** |
| links: Funktion des Verfassers | — | **neu**: `verfasser_funktion` |
| Mitte: Art | fest | fest „Kommunikationsplan" |
| Mitte: Verwendungsbereich („für …") | `einsatzbezeichnung` | umbenennen in **Verwendungsbereich** |
| rechts: Gültigkeitsvermerk („Stand:") | `gueltig_ab`, `gueltig_bis` | bleibt, Beschriftung **Stand / gültig ab–bis** |
| rechts: Verschlusssachenvermerk | — | **neu**: `vs_vermerk`, Vorbelegung `NfD` (Q7 druckt „N f D") |
| F.d.R. mit Dienststellung | `freigegeben_von` | ergänzen um `freigabe_dienststellung`, Anzeige „F.d.R.: …, …" |
| Betriebsleitung/-aufsicht | `betriebsleitung` | bleibt — Q4 6.1.1 verlangt sie |

Die Spaltennamen der Datenbank bleiben unverändert, wo nur die Beschriftung
falsch war; neu sind `verfasser_funktion`, `vs_vermerk` und
`freigabe_dienststellung`.

**Was Q10 zusätzlich kennt und hier nicht übernommen wird.** Das
Kommunikationsplan-Muster der PDV 800 (Anlage 1) führt im Kopf ein Feld
**„Kennwort"**. Fb Fü 76 hat es nicht, und für den THW-Kommunikationsplan hat
Q6 die Sachnähe. Es wird deshalb nicht aufgenommen — festgehalten wird es,
damit die Auslassung als Entscheidung erkennbar bleibt und nicht als Übersehen.

---

## 10. Zwei Ansichten auf denselben Bestand

Q6, Allgemeines, beschreibt zwei Leserkreise mit zwei Tiefen:

> Telekommunikationsskizzen dienen taktischen Führern […] als
> Arbeitsunterlage. Technische Einzelheiten werden in diese Skizzen nur
> insoweit aufgenommen, als sie für den taktischen Führer von Bedeutung sind.

> Telekommunikationsskizzen dienen dem Betriebspersonal als Arbeitsunterlage.
> In den Telekommunikationsskizzen werden sämtliche Einzelheiten technischer
> und betrieblicher Art aufgenommen.

Das ist kein Widerspruch im Vordruck, sondern die Beschreibung zweier
Unterlagen. eStab führt **einen** Bestand und **zwei** Ansichten darauf:

**Taktische Ansicht** — Vorgabe für alle. Je Stelle ein Block in der Gestalt
des Q6-Kastens: Stelle, Stellenart, darunter je Weg das Mittel und die
Erreichbarkeit, darunter eingerückt die Gegenstellen dieses Wegs. Keine
Bandlage, keine Rufgruppe, keine Betriebsart. Das ist die Antwort auf
Abschnitt 3.2 — und die Ansicht, die die Frage „wen erreiche ich womit"
beantwortet, ohne dass jemand eine Tabelle filtern muss.

**Betriebliche Ansicht** — für S6, LdF und A/W. Die heutige flache Tabelle mit
allen technischen Feldern, Suche und Filter je Spalte über das
Tabellenbauteil, wie sie [fuehrungsstelle.php:1185](4fach/fuehrungsstelle.php:1185)
bereits aufbaut.

Der Wechsel ändert **nur die Tiefe**, nicht die Reihenfolge und nicht die
Benennung — `SPEC.md` `UX-KEIN-BRUCH-IM-LAUFWEG` sinngemäß auch hier.

---

## 11. Anforderungskatalog

Neue Regeln des Moduls **M7 `fuehrungsmittel`**. Vorschriftenregeln gehören
nach `app/dv_rules.php`, Bedienregeln nach `app/ux_rules.php`; beide Register
erzwingen einen Test je Regel.

### 11.1 Vorschriftenregeln (Q4, Q6, Q7, Q8)

| ID | Quelle | Soll | Abnahme |
| --- | --- | --- | --- |
| `FMP-MEDIUM-VORDRUCK` | Q1 Felder 1 und 7 | Die Medien des Plans sind die Ankreuzfelder des Vordrucks. Der Plan erfindet kein Medium hinzu. | Die Menge der planbaren Medien ist eine Teilmenge von `nv_nachrichten.01_medium`; ein disponierter Weg schreibt Feld 1 ohne Übersetzung |
| `FMP-EIGENE-ERREICHBARKEIT` | Q4 Kap. 6.1.1, Q6, Q10 Anlage 20 | Eine Betriebsstelle ist eine Stelle im eigenen IuK-Netz, bei der Nachrichten aufgenommen, befördert oder übermittelt werden. Der Plan führt deren Erreichbarkeit, nicht die Gegenstellen einzelner Nachrichten. | Die Plantafel benennt das; die Gegenstelle ist ausschließlich Feld 6 der Nachricht |
| `FMP-STELLENART` | Q4 Kap. 6.1.2, Q6 | Zu jeder Stelle ist erkennbar, ob die Verbindung vertikal nach oben, vertikal nach unten oder horizontal führt. | Jeder Eintrag trägt eine Stellenart; die taktische Ansicht zeigt sie |
| `FMP-STELLENBILD` | Q6 Aufbau des Vordrucks, Q10 Anlage 1 | Die taktische Darstellung gruppiert die Wege je Stelle, wie beide Muster-Kommunikationspläne sie in Kästen setzen. | Die taktische Ansicht zeigt je Stelle einen Block mit allen ihren Wegen |
| `FMP-FUNKART` | Q8 Rufgruppenbildung Folie 3 | Analogfunk wird über Kanäle geführt, Digitalfunk über Rufgruppen. Ein Weg trägt nur die Felder seiner Technik. | Für `ANALOG` sind Band, Kanal, Bandlage, Verkehrsform Pflicht und Rufgruppe unmöglich; für `DIGITAL` umgekehrt |
| `FMP-DIGITAL-BETRIEBSART` | Q8 Grundlagen Folie 3 | Ein Digitalfunkweg nennt TMO (Netzbetrieb) oder DMO (Direktbetrieb). | Betriebsart ist Pflicht, sobald `funkart = DIGITAL` |
| `FMP-DIGITAL-KEINE-GERAETEKENNUNG` | Q12 Kap. 2.4, Q11 Kap. 2.2.1.3 | Verbindlich ist ausschließlich das gesprochene Wort; die OPTA erlaubt keine sichere Verifizierung der Identität. Die Teilnehmerverwaltung liegt bei der TTB-THW. | Es gibt kein Feld für OPTA oder ISSI; die Ausfüllhilfe der Bemerkung sagt, dass Gerätekennungen auch dort nicht hineingehören |
| `FMP-DIGITAL-KEINE-TELEFONIE` | Q12 Kap. 2.4.3 | Die Überleitung des Digitalfunks in das öffentliche Telefonnetz ist gesperrt. | Die Ausfüllhilfe des Digitalfunkwegs sagt, dass er keine Telefonverbindung trägt |
| `FMP-DIGITAL-REPEATER-ANTRAG` | Q12 Kap. 2.3.3, 2.3.4 | Die Schaltung eines Repeaters oder Gateways ist bei der TTB-THW zu beantragen und nur stationär zulässig. | Die Ausfüllhilfe des DMO-Wegs nennt Antragspflicht und stationären Betrieb |
| `FMP-DIGITAL-WIRKBEREICH` | Q12 Kap. 2.3.1 | Eine Rufgruppe ist nur innerhalb ihrer Gruppenrufzone verwendbar. | Die Ausfüllhilfe zu `rufgruppe` nennt den Wirkbereich und verweist auf den gültigen Rufgruppenplan |
| `FMP-DIGITAL-GRUPPENRUF` | Q12 Kap. 2.4.1, 2.4.2 | Der Gruppenruf ist das Standardverfahren; der Einzelruf ist nur im Wechselverkehr freigeschaltet und auf das taktisch Notwendige zu beschränken. | Der Digitalfunkweg fragt keine Verkehrsform ab; die Ausfüllhilfe nennt die drei zulässigen Fälle des Einzelrufs |
| `FMP-KOPFLEISTE` | Q6 Allgemeines | Der Kopf trägt herausgebende Dienststelle mit Funktion des Verfassers, Art und Verwendungsbereich, Gültigkeits- und Verschlusssachenvermerk sowie F.d.R. mit Dienststellung. | Alle sieben Angaben sind erfasst und werden im Kopf angezeigt und gedruckt |
| `FMP-BETRIEBSLEITUNG` | Q4 Kap. 6.1.1 | In den Einsatzunterlagen sind Betriebsleitungen/-aufsicht anzugeben. | Betriebsleitung ist Pflichtangabe des Kopfes — bereits erfüllt |
| `FMP-VERMERK-EINFACH` | keine Quelle für zwei Felder | Ein Weg trägt **ein** Bemerkungsfeld. | Neue und geänderte Einträge schreiben nur `bemerkungen`; die Versionskopie führt Altbestand zusammen |
| `FMP-GEGENSTELLE-AM-WEG` | Q4 Kap. 6.1.2, Q6 | Eine Gegenstelle steht immer an einem Weg und erbt dessen Medium. Es gibt keine Gegenstelle ohne Weg. | Die Gegenstelle hängt am Eintrag, nicht am Plan; sie trägt kein eigenes Medium |
| `FMP-GEGENSTELLE-KEIN-ERSATZ` | Q1 Felder 6, 11 und Q2 Feld 13 | Der Plan liefert Vorschläge für die Gegenstellenfelder des Vordrucks, ersetzt sie aber nicht und schreibt sie nicht fest. | Freie Eingabe bleibt in Feld 6, 11 und 13 möglich; eine im Plan fehlende Gegenstelle führt zu keiner Zurückweisung |
| `FMP-GEGENSTELLE-FELDBEZUG` | Q1 Felder 6 und 11 | Welches Vordruckfeld eine Erreichbarkeit füllt, entscheidet das Medium des Wegs. | Über einen Funkweg füllt die Erreichbarkeit Feld 6, über einen Fernsprech- oder Faxweg Feld 11 und der Name Feld 6 |
| `FMP-BETRIEBSUNTERLAGE-AKTUELL` | Q10 Nr. 2.6, Q4 Kap. 6.6 | „Abweichungen von den im Kommunikationsplan festgelegten IuK-Verbindungen sind während des Einsatzes zu vermeiden." Die Betriebsunterlage geht der Erinnerung vor. | Ein Vorschlag aus dem Plan stammt ausschließlich aus der aktiven, zeitlich gültigen Version und steht über jedem Vorschlag aus der Historie |
| `FMP-VORSCHLAG-QUELLENSCHRANKE` | `SPEC.md` Leserechte, `TKM-FERNMELDEPLAN` | Der Plan ist für die gesamte Besetzung einsehbar; die Nachrichtenhistorie ist es nicht. Wer keine Historie lesen darf, bekommt daraus auch keinen Vorschlag. | Die Vorschlagspolitik nennt je Rolle und Feld die zulässigen Quellen; für den Stab an Feld 10 ist das ausschließlich der Plan |

### 11.2 Bedienregeln (P1)

| ID | Soll | Abnahme |
| --- | --- | --- |
| `FMP-UX-WORT-DES-MEDIUMS` | Das Formular beschriftet die Erreichbarkeit mit dem Begriff des gewählten Mediums, nicht mit einem Oberbegriff. | Die Beschriftung wechselt mit dem Medium; „Erreichbar unter" erscheint nur als Spaltenkopf |
| `FMP-UX-KEINE-TOTEN-FELDER` | Ein Feld, das für das gewählte Medium nicht gilt, ist nicht sichtbar — nicht nur nicht pflichtig. | Für jedes Medium enthält das Formular ausschließlich seine Felder |
| `FMP-UX-ZWEI-TIEFEN` | Die taktische Ansicht ist Vorgabe; die betriebliche Ansicht ist einen Klick entfernt und bleibt gewählt. | Umschaltung vorhanden, Auswahl überdauert den Seitenwechsel |
| `FMP-UX-ALTANGABE` | Ein übernommener Eintrag mit Angaben, die seine Technik nicht kennt, sagt das, bevor gespeichert wird. | Der bestehende Hinweis aus [fuehrungsstelle.php:270](4fach/fuehrungsstelle.php:270) deckt auch Funkart und Verkehrsform ab |
| `FMP-UX-WEGEWAHL` | Die Disposition des LdF fragt nach dem Weg, nicht nach dem Empfänger. | Beschriftung und Ausfüllhilfe der Wegewahl nennen den Weg |
| `FMP-UX-VORSCHLAG-HERKUNFT` | Jeder Vorschlag sagt, woher er kommt: aus dem aktiven S6-Fernmeldeplan oder aus einem bestätigten Nachrichtenpaar. Ein Vorschlag ohne Etikett gibt es nicht. | Jede Option der Vorschlagsliste trägt eine Herkunftsangabe — auch bei `FM-Eingang`, wo heute nur unbeschriftete Historie erscheint |
| `FMP-UX-VORSCHLAG-WEG` | Ein Vorschlag aus dem Plan nennt den Weg, über den er gilt. | Die Herkunftsangabe eines Planvorschlags trägt Mittel und technische Kurzangabe des Wegs |

---

## 12. Datenmodell (Soll)

### 12.1 `nv_fernmeldeplaene` — Ergänzungen

| Spalte | Typ | Pflicht | Herkunft |
| --- | --- | --- | --- |
| `verfasser_funktion` | `VARCHAR(64)` | ja | Q6 Kopfleiste links |
| `vs_vermerk` | `ENUM('OFFEN','NfD')` | ja, Vorbelegung `NfD` | Q6/Q7 Kopfleiste rechts |
| `freigabe_dienststellung` | `VARCHAR(128)` | ja bei Freigabe | Q6 F.d.R. |

### 12.2 `nv_fernmeldeplan_eintraege` — Soll

| Spalte | Typ | Gilt für | Änderung |
| --- | --- | --- | --- |
| `betriebsstelle` | `VARCHAR(255)` | alle | nur Beschriftung |
| `stellenart` | `ENUM('EIGEN','UEBER','UNTER','NEBEN')` | alle | **neu** |
| `erreichbarkeit` | `VARCHAR(255)` | alle | **umbenannt** aus `rufname`, verlängert |
| `medium` | `ENUM('Fe','Fu','Me','FAX','FS','@')` | alle | unverändert |
| `funkart` | `ENUM('ANALOG','DIGITAL')` | `Fu` | **neu** |
| `band` | `ENUM('2m','4m')` | `Fu`/analog | **neu** |
| `kanal` | `VARCHAR(64)` | `Fu`/analog | Bedeutung verengt |
| `bandlage` | `ENUM('O','U')` | `Fu`/analog | Typ verengt |
| `verkehrsform` | `ENUM('W','G','bG','R')` | `Fu`/analog | Typ verengt, **nicht mehr für alle Medien** |
| `relaisstelle` | `VARCHAR(64)` | `Fu`/analog | **neu**, freiwillig |
| `betriebsart` | `ENUM('TMO','DMO')` | `Fu`/digital | **neu** |
| `rufgruppe` | `VARCHAR(64)` | `Fu`/digital | **neu** |
| `anschlussart` | `ENUM('AMT','NST','MOBIL','SONDER')` | `Fe`, `FAX` | **neu**, freiwillig |
| `datenart` | `ENUM('MAIL','MESSENGER','FACHANW','INTERNET')` | `@` | **neu** |
| `bemerkungen` | `TEXT` | alle | bleibt, einziges Vermerkfeld |
| `besondere_vermerke` | `TEXT NULL` | alle | **nur noch lesend** |

### 12.3 `nv_fernmeldeplan_gegenstellen` — neu

Kindtabelle des Wegs. Sie erbt Medium und Technik vom Weg und trägt nur, was
die Gegenstelle unterscheidet.

| Spalte | Typ | Pflicht | Speist |
| --- | --- | --- | --- |
| `gegenstelle_id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Schlüssel | — |
| `fernmeldeplan_eintrag_id` | `BIGINT UNSIGNED` | ja, Fremdschlüssel | — |
| `sortierung` | `INT UNSIGNED` | ja, eindeutig je Eintrag | Reihenfolge in der Anzeige |
| `name` | `VARCHAR(255)` | ja | Feld 13 (Eingang), Feld 10 (Ausgang) |
| `erreichbarkeit` | `VARCHAR(255)` | ja | Feld 6 oder Feld 11, je Medium des Wegs |
| `bemerkungen` | `TEXT` | nein | — |

**Bewusst nicht normalisiert.** Dieselbe Stelle steht unter mehreren Wegen
mehrfach. Eine gemeinsame Gegenstellentabelle über alle Wege hinweg würde die
Versionierung brechen: Ein Plan ist ab `AKTIV` unveränderlich, ein geteilter
Stammsatz wäre es nicht. Die Kopie je Version ist der Preis dafür, dass eine
freigegebene Fassung Jahre später noch dasselbe sagt.

**Was mitzuziehen ist:**

| Ort | Was |
| --- | --- |
| [dv_operations.php:4829](app/dv_operations.php:4829) | die Versionskopie (`INSERT … SELECT`) kopiert die Gegenstellen mit |
| Migration | eigene Unveränderlichkeitsauslöser nach dem Muster `estab_dv94_fernmeldeplan_entry_*` |
| [incident_export.php:1371](app/incident_export.php:1371) | Ausleitung |
| [readiness.php:158](app/readiness.php:158) | Tabellen- und Auslöserprüfung |
| [read_authorization.php:270](app/read_authorization.php:270) | Plan-Zweig der Zuordnung zeigt hierher (V1) |

### 12.4 Migration

Der Grundsatz ist **additiv**. Keine Migration schreibt eine Zeile eines
aktiven oder ersetzten Plans um; die Unveränderlichkeitsauslöser aus
Migration 94 und 117 bleiben unberührt.

1. Neue Spalten anlegen, alle `NULL`-fähig.
2. `rufname` → `erreichbarkeit` umbenennen (`ALTER TABLE … CHANGE`; kein
   Wertewechsel, damit kein Auslöser feuert).
3. Bestehende `Fu`-Einträge: `funkart` bleibt `NULL` = **unbestimmt**. Sie
   werden in der Oberfläche als Altbestand gekennzeichnet und beim nächsten
   Bearbeiten entschieden. Kein Raten.
4. `bandlage` und `verkehrsform` erhalten die engen Typen erst, wenn kein
   Altbestand mehr außerhalb der Werteliste liegt; bis dahin bleiben sie
   `VARCHAR` mit Prüfung in der Anwendung. Die Verengung ist ein eigener,
   späterer Schritt.
5. `nv_komplan` (F9) wird in dieser Überarbeitung **nicht** angefasst. Ihr
   Abbau ist entschieden, läuft aber als eigener Vorgang — Abschnitt 13.
6. `nv_fernmeldeplan_gegenstellen` wird **leer** angelegt. Es findet keine
   Ableitung aus `betriebsstelle`/`rufname` bestehender Pläne statt: Diese
   Werte sind die eigene Erreichbarkeit, nicht die der Gegenstelle. Sie
   maschinell umzudeuten hieße, den Fehler aus F10 in Daten zu gießen.
   Bis der S6 die erste Version mit Gegenstellen freigibt, liefert der
   Plan-Zweig der Vorschläge nichts — und die Historie trägt allein, wie
   bisher.

---

## 13. Abbau der Alttabelle `nv_komplan` (Entscheidung O4)

### 13.1 Nachweis, dass sie ungenutzt ist

Die Prüfung ist vollständig: Kein Anwendungspfad liest oder schreibt
`nv_komplan`.

| Fundstelle | Art | Bewertung |
| --- | --- | --- |
| [dbcfg.inc.php:20](4fcfg/dbcfg.inc.php:20) | `$conf_tbl["komplan"]` wird **gesetzt** | nirgends gelesen — einziger Anwendungsbezug |
| [readiness.php:150](app/readiness.php:150), 360, 425, 435, 443, 459 | Bestandsprüfung: Tabellenliste, Anzahl, Fremdschlüssel, Auslöser, Waisenzählung | prüft nur ihr Vorhandensein |
| [incident.php:781](app/incident.php:781) | `EXISTS`-Prüfung „Einsatz trägt Daten" | liest keine Spalte, nur die Existenz einer Zeile |
| `docker/db/init/10-schema.sql:195`, `verify.sql`, Migrationen 45, 50, 97 | Anlage, Einsatzbindung, Auslöser | Bestandsgeschichte |
| `tests/php/permission_mode_security.php:655`, `tests/php/incident_domain_security.php:385`, `tests/fixtures/legacy-runtime-schema.sql:132` | Erwartungswerte | folgen dem Schema |

Damit ist F9 kein Verdacht mehr, sondern ein Befund: eine Tabelle, die
ausschließlich sich selbst begründet.

### 13.2 Abbau

Eigener Vorgang, **nicht** Teil der Fernmeldeplanung — er berührt sie nur,
weil er hier gefunden wurde. Reihenfolge:

1. **Migration, die sich weigert, Daten zu vernichten.** Sie prüft
   `SELECT COUNT(*) FROM nv_komplan`. Ist die Tabelle nicht leer, bricht sie
   mit einer Meldung ab, die zum Sichern auffordert (`docs/BACKUP-UND-
   WIEDERHERSTELLUNG.md`). Erst eine leere Tabelle wird verworfen — mitsamt
   Fremdschlüssel `fk_komplan_einsatz` und den Auslösern
   `estab_komplan_bi_einsatz`, `_bu_`, `_bd_`.
2. `docker/db/init/10-schema.sql` und `docker/db/verify.sql` verlieren die Tabelle.
3. [readiness.php](app/readiness.php): sechs Fundstellen, dabei die
   Anzahlprüfung `= 10` auf `= 9` und die Waisenzählung.
4. [incident.php:781](app/incident.php:781): der `EXISTS`-Zweig entfällt.
5. [dbcfg.inc.php:20](4fcfg/dbcfg.inc.php:20): die Konfigurationszeile entfällt.
6. `tests/php/schema_migration_contract.php`, `permission_mode_security.php`,
   `incident_domain_security.php` und `tests/fixtures/legacy-runtime-schema.sql`
   ziehen nach. Die Alt-Fixture behält die Tabelle — sie bildet den
   **früheren** Stand ab, den die Migration vorfinden muss.

Der Gestaltvorwurf aus Abschnitt 3.2 bleibt davon unberührt: Was
`nv_komplan` richtig machte — ein Kasten je Stelle — lebt in der taktischen
Ansicht weiter (Abschnitt 10), nicht in der Tabelle.

---

## 14. Erzeugte Kommunikationsskizze (Entscheidung O5)

### 14.1 Umfang

Aus den Plandaten wird die **taktische** Kommunikationsskizze erzeugt: Stellen
als Kästen mit taktischem Zeichen, Verbindungen als Linien je Mittel,
beschriftet mit der Erreichbarkeit. Kopfleiste nach Abschnitt 9, Querformat,
mindestens DIN A4 — so verlangt es Q6 („Bewährt hat sich die Darstellung im
Querformat").

Sie ist **erzeugt, nicht gezeichnet.** Damit gilt für sie, was für den Plan
gilt: Sie trägt dessen Stand, dessen Version und dessen F.d.R.; es gibt keine
zweite Wahrheit, die auseinanderlaufen könnte.

**Nicht** erzeugt wird die **betriebliche** Skizze. Q6 verlangt dort
„sämtliche Einzelheiten technischer und betrieblicher Art" mit Schaltzeichen —
das ist eine Zeichnung, kein Bericht (Lücke L3).

### 14.2 Anordnung

Die Anordnung folgt der Stellenart aus Abschnitt 5.2, nicht einem
Grafikalgorithmus. Q4 Kapitel 6.1.2 gibt die Achsen vor: vertikal von oben
nach unten, horizontal zur Seite.

```
                    UEBER
                      |
        NEBEN  ---  EIGEN  ---  NEBEN
                      |
        UNTER      UNTER      UNTER
```

Damit ist die Skizze bei jeder Größe vorhersehbar und ohne Handarbeit lesbar.
Verbindungen laufen von `EIGEN` zu jeder anderen Stelle, eine Linie je Weg.

### 14.3 Linienart je Mittel — trägt auch ohne Zeichen

Die Skizze ist **ohne** die taktischen Zeichen baubar und wird mit ihnen
besser. Sie hängt nicht an ihnen:

| Mittel | Linie |
| --- | --- |
| `Fe`, `FAX`, `@` (leitergebunden) | durchgezogen |
| `Fu` analog | gestrichelt |
| `Fu` digital | gestrichelt, doppelt |
| `Me` Melder | gepunktet |

### 14.4 Zeichensatz (Q9)

Q6 sagt: „Fernmeldestellen werden nur mit dem taktischen Zeichen für die
Einheit / Einrichtung eingezeichnet." Die Sammlung von Jonas Köritz (Q9) deckt
das vollständig ab — und genauer, als eine eigene Liste es täte. Sie enthält
allein für das Fernmeldewesen **108 Zeichen**.

**Stellen** — die Anordnung sagt über- oder untergeordnet, das Zeichen sagt die
Art:

| `stellenart` / Rolle | Zeichen aus Q9 |
| --- | --- |
| Führungsstelle | `Führungsstellen/Führungsstelle` |
| Abschnittsführungsstelle | `Führungsstellen/Abschnittsführungsstelle` |
| TEL, EL, EAL, UEAL | `Führungsstellen/TEL`, `/EL`, `/EAL`, `/UEAL` |
| Meldekopf | `Einrichtungen/Meldekopf` |
| Leitstelle | `Einrichtungen/Leitstelle` |
| Stelle, allgemein | `Einrichtungen/Stelle`, `/Stelle_Betrieb` |
| Fernmeldetrupp, FGr FK | `THW_Einheiten/FGr_Führung-Kommunikation_Fernmeldetrupp`, `/Fachzug_Führung_Kommunikation` |
| Melder | `Personen/Melder` |

Die vier Zeichen `TEL`, `EL`, `EAL`, `UEAL` sind ein unerwarteter Gewinn: Es
sind genau die Verteilerstufen aus **Feld 19 des Nachrichtenvordrucks**
(`SPEC.md` Abschnitt 3). Plan und Vordruck sprechen damit dieselbe Bildsprache.

**Verbindungen.** Q9 führt eine eigene Gattung `Bedingung_*`, die mit
`preserveAspectRatio` an einer Linie mitwächst — also genau das, was eine
Verbindungsbeschriftung braucht:

| Weg | Zeichen aus Q9 |
| --- | --- |
| `Fe` Fernsprecher | `Fernmeldewesen/Bedingung_Telefon` |
| `Fe` über Nebenstelle | `Fernmeldewesen/Bedingung_Nebenstelle` |
| `FAX` Telefax | `Fernmeldewesen/Bedingung_Fax` |
| `Fu` digital, TMO | `Fernmeldewesen/Bedingung_TMO` |
| `Fu` digital, DMO | `Fernmeldewesen/Bedingung_DMO` |
| `Fu` analog | Linienart nach Abschnitt 14.3 plus Textmarke „Kanal 2 m" / „Kanal 4 m" — wie auf dem Papier (Q6) |
| Relaisbetrieb analog | `Fernmeldewesen/RS2_2m-2m`, `/RS2_2m-4m`, `/RS2_4m-4m` |
| DMO-Repeater, DMO-TMO-Gateway | dieselben `RS2_*`-Zeichen: Q12 Kap. 2.3.3 und 2.3.4 stellen Repeater und Gateway ausdrücklich neben Rs1-Relais und Rs2-Überleiteinrichtung |
| `@` Datenübertragung | `Fernmeldewesen/Datenverbindung` |
| `Me` Melder | Linienart nach Abschnitt 14.3 |

Für den Analogfunk gibt es kein `Bedingung_2m`/`Bedingung_4m`. Das ist kein
Mangel: Q6 schreibt die Bandangabe als Text („Kanal 2 / 4 m"), und genau so
wird sie gesetzt. Wer die Zeichen dennoch will, kann sie beim Urheber als
Issue anregen — die Skizze hängt nicht daran.

**Für die betriebliche Ansicht liegt bereit**, falls sie später mehr als Text
zeigen soll: `Wähltelefon_analog`, `Wähltelefon_ISDN`, `Wähltelefon_IP`,
`Wähltelefon_Satellit` (Q7: „Analog a/b", „ISDN S₀", „C Wähl"),
`Handfunkgerät_digital`, `Fahrzeugfunkgerät_digital`, `Kofferfunkgerät_digital`,
`Funk_Feststation_digital` (Q8: HRT, MRT, MRT-K, FRT), `Repeaterstation`,
`Netzübergang` (Gateway), `RS2_2m-2m`, `RS2_2m-4m`, `RS2_4m-4m`
(Relaisfunkbetrieb), `Abholpunkt` und `Anschlusspunkt` (Q4 Kapitel 6.1.2
nennt beide ausdrücklich). Gruppe C aus der ursprünglichen Anfrage ist damit
erledigt, bevor sie gestellt wurde.

### 14.5 Was die Übernahme kostet

Die Zeichen sind **nicht** so gebaut, wie ich sie zuerst angefragt hatte. Drei
Punkte sind zu klären, keiner davon blockierend.

**Farbe statt `currentColor`.** Die Zeichen tragen feste Farben —
`Führungsstelle` ist gelb (`#ffff00`), Linien schwarz, Flächen weiß. Das ist
**richtig so**: Die Farbe ist bei taktischen Zeichen bedeutungstragend
(gelb = Katastrophenschutz), sie darf nicht umgefärbt werden. Für
`docs/GESTALTUNG.md` folgt daraus eine Ausnahme: Die Skizze setzt die Zeichen
auf eine **helle Fläche**, auch im dunklen Erscheinungsbild. Nicht die Zeichen
passen sich dem Bild an, sondern das Bild den Zeichen.

**Schrift.** Die Vorlagen binden „Roboto Slab Bold" (Apache-2.0) über
`fonts/roboto_slab_bold.j2` ein — rund 25 kB je erzeugter Datei. In der Skizze
werden die Zeichen **einmal** in ein SVG-Dokument eingebettet; die
Schriftbindung entfällt dabei und der Text erbt die Schrift der Anwendung. Das
spart die Einbettung, vermeidet einen weiteren Lizenzträger und bleibt
inhaltssicherheitskonform. Die Geometrie der Zeichen ist normativ, ihre
Schriftart nicht.

**Bezugsweg und Lizenz.** Zwei Wege, mit unterschiedlichem Preis:

| Weg | Lizenz | Nachteil |
| --- | --- | --- |
| `release.zip` (v2.0.0, 24.06.2024) | gemeinfrei, CC0-1.0 laut README | zwei Jahre alt; neuere Zeichen fehlen |
| Aus den Vorlagen bauen (`make svg`, benötigt `j2cli`) | CC-BY-4.0 — Namensnennung nötig | ein einmaliger Entwicklerschritt |

**Vorschlag: aus den Vorlagen bauen.** Die Namensnennung kostet nichts — die
Anwendung führt ohnehin `THIRD_PARTY_NOTICES.md` —, und eine ausdrückliche
Lizenz ist tragfähiger als ein Satz im README über den Stand eines Archivs.
Gebaut wird **einmal**, die benötigten SVG werden als Bestand aufgenommen;
`j2cli` wird nicht Teil der Laufzeit. Die bewusst leere Laufzeit-Abhängigkeits-
liste in `requirements.txt` bleibt leer.

Aufzunehmen in `THIRD_PARTY_NOTICES.md`: Sammlung, Urheber, Fundstelle,
CC-BY-4.0 — und, falls Zeichen mit Text übernommen werden, „Roboto Slab Bold"
unter Apache-2.0.

---

## 15. Lücken

| Nr. | Lücke | Wirkung |
| --- | --- | --- |
| **L1** | **geschlossen.** NBHB THW (Q12) und THW-DV 1-820 (Q11) liegen vor; alle `FMP-DIGITAL-*`-Regeln zitieren jetzt die Vorschrift statt der Ausbildungsunterlage. | Offen bleibt allein die *Ergänzende Loseblattsammlung Digitalfunk BOS* mit den Rufgruppenplänen — siehe L5. |
| **L2** | **verkleinert.** PDV 800 (Q10) liegt vor, führt die Wertelisten des Analogfunks aber nicht: Sie verweist für Sprech- und Datenfunk auf die **PDV 810.2 VS-NfD „Sprech- und Datenfunkverkehr"**. Diese ist eingestuft und liegt nicht vor. | Bandlage und Verkehrsform des Analogfunks bleiben **nicht quellengestützt**. Schritt 4 der Migration bleibt bestehen: keine Typverengung, bevor die Werte belegt sind. |
| **L3** | Q7 kennt zwei Skizzen: eine taktische und eine betriebliche mit sämtlichen Schaltzeichen. | Die **taktische** wird erzeugt (Abschnitt 14). Die **betriebliche** bleibt draußen — sie ist eine Zeichnung, kein Bericht. |
| **L4** | Kanal- und Frequenzverzeichnisse des Analogfunks sind landesrechtlich geregelt. | `kanal` bleibt Freitext; keine Prüfung gegen ein Verzeichnis. |
| **L5** | Rufgruppenübersichten TMO/DMO der Landesverbände stehen nach Q11 Kap. 2.2.1.4 in der Loseblattsammlung Digitalfunk BOS und sind nicht Teil der Anwendung. | `rufgruppe` bleibt Freitext; die Ausfüllhilfe verweist auf die Loseblattsammlung. |
| **L6** | Für den **Analogfunk** führt Q9 keine `Bedingung_*`-Zeichen (2 m, 4 m). | Kein Mangel: Q6 schreibt die Bandangabe als Text. Die Skizze setzt sie ebenso. |
| **L7** | Die THW-Funkrufnamenregelung (THW-FuRnR), von Q11 Kap. 2.2.1.2 als zuständige Regelung benannt, liegt nicht vor. | Ohne Wirkung auf das Schema: Der Plan trägt den Funkrufnamen als Text und prüft ihn nicht gegen eine Regel. |

---

## 16. Abnahme

```console
# statische Suite mit Regelkatalog-Nachweis (kein lokales php auf dieser Maschine)
tests/static/run.sh

# Regelregister: keine Regel ohne Test
PHP_BIN=php php tests/php/dv_rule_registry.php
PHP_BIN=php php tests/php/ux_rule_registry.php

# Schema- und Betriebsnachweis
podman compose build --pull migrate app
podman compose up -d
curl --fail --silent --show-error http://127.0.0.1:8080/health.php

# Browser- und Datenbanktests
ESTAB_CONTAINER_CLI=podman ESTAB_BROWSER_TEST=auto tests/static/run.sh
ESTAB_CONTAINER_CLI=podman npm run test:e2e
```

Fertig ist die Überarbeitung, wenn zusätzlich zur `Definition of Done` aus
`SPEC.md` Abschnitt 12 gilt:

- [ ] Jede Regel aus Abschnitt 11 steht im jeweiligen Register und hat einen Test.
- [ ] `tests/php/schema_migration_contract.php` kennt die neuen Spalten.
- [ ] Ein Digitalfunkweg lässt sich ohne Kanal und ohne Bandlage speichern, ein Analogfunkweg nicht ohne sie.
- [ ] Ein bestehender, aktiver Plan ist nach der Migration unverändert lesbar und trägt dieselbe Prüfsumme im Ausleitungsvergleich.
- [ ] Die taktische Ansicht zeigt einen Plan mit zwanzig Wegen als Stellenblöcke ohne Querlauf bei 916 Bildpunkten (`docs/GESTALTUNG.md`).
- [ ] Die Bedienprüfung (`tools/bedienpruefung/`) belegt mit Bildschirmabzug, dass Formular und Tabelle je Medium nur ihre Felder zeigen.
- [ ] Kein Vorschlag im Vordruck stammt mehr aus `betriebsstelle` oder `erreichbarkeit` eines Wegs (F10 ist geschlossen).
- [ ] Jeder Treffer aus dem Plan steht über jedem Treffer aus der Historie, und beide sind sichtbar (F11 ist geschlossen).
- [ ] Der Stab bekommt an Feld 10 Vorschläge aus dem Plan und **keinen einzigen** aus der Nachrichtenhistorie.
- [ ] Jede Option der Vorschlagsliste trägt eine Herkunftsangabe, auch bei `FM-Eingang`.
- [ ] Eine Gegenstelle, die im Plan fehlt, lässt sich in Feld 6, 11 und 13 frei eintragen.
- [ ] Weder Formular noch Datenbank kennen ein Feld für OPTA oder ISSI.
- [ ] Die Skizze eines Plans mit vier Stellen und acht Wegen ist im Querformat ohne Überlappung lesbar und trägt Kopfleiste, Version und F.d.R. des Plans.
- [ ] Die Skizze entsteht auch ohne taktische Zeichen (Linienart je Mittel, Abschnitt 14.3).
- [ ] Die taktischen Zeichen behalten ihre Farben; im dunklen Erscheinungsbild stehen sie auf heller Fläche.
- [ ] `THIRD_PARTY_NOTICES.md` führt Q9 mit Urheber, Fundstelle und Lizenz.
- [ ] Der Abbau von `nv_komplan` läuft als **eigener** Vorgang; seine Migration bricht bei nicht leerer Tabelle ab, statt Zeilen zu verwerfen.

---

## 17. Entscheidungen und offene Fragen

### 17.1 Entschieden durch den Betreiber

| Nr. | Frage | Entscheidung | Steht in |
| --- | --- | --- | --- |
| O1 | Fernschreiber (`FS`) aus der Auswahlliste des Plans nehmen? | **ja** — der Vordruck behält sein Kästchen | Abschnitt 4.3 |
| O2 | OPTA und ISSI erfassen? | **nein** — personen- bzw. gerätescharf, und der Plan trägt „N f D". Der Plan führt den Funkrufnamen. Auch die Bemerkung ist kein Hintereingang | Abschnitt 8.2, Regel `FMP-DIGITAL-KEINE-GERAETEKENNUNG` |
| O3 | Verkehrskreis als eigenes Feld? | **nein** — nur Ausfüllhilfe der Bemerkung | Abschnitt 6.1 |
| O4 | Alttabelle `nv_komplan`? | **entfernen**, da nachweislich ungenutzt — als eigener Vorgang | Abschnitt 13 |
| O5 | Kommunikationsskizze erzeugen? | **ja**, die taktische. Die Zeichen kommen aus Q9 und decken den Bedarf vollständig | Abschnitt 14 |
| O6 | Planvorschläge für den Stab an Feld 10? | **ja** — aber ausschließlich aus dem Plan, nie aus der Historie. Die Vorschlagspolitik bekommt eine Quellenachse | Abschnitt 5.5 (V8), Regel `FMP-VORSCHLAG-QUELLENSCHRANKE` |
| O7 | Plan oder Historie zuerst? | **Plan zuerst**, beide werden angezeigt, beide gekennzeichnet | Abschnitt 5.4 (F11), 5.5 (V2) |
| O8 | Feld für die Erreichbarkeitszeit einer Gegenstelle? | **nein** — nur Bemerkung | Abschnitt 5.3 |

Damit ist keine Frage dieser Überarbeitung mehr offen.

### 17.2 Neu aufgeworfen durch Q10 — bitte entscheiden

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| **O9** | Q10 kennt die **Rückfallebene** — „Ersatz für eine IuK-Verbindung, ggf. auch unter Inkaufnahme einer Leistungsbeschränkung" (Anlage 20) — und verlangt in Nummer 3, Kommunikationspläne „erforderlichenfalls unter Berücksichtigung einer Rückfallebene" zu erstellen. Soll ein Weg als Rückfallebene **gekennzeichnet** werden können? | ja, als **Schalter** `rueckfallebene` am Weg, nicht als Verweis auf einen anderen Weg. Der Schalter beantwortet die Frage, die im Einsatz gestellt wird — „was habe ich noch, wenn dieser Weg ausfällt" —, ohne ein Beziehungsgeflecht zu pflegen, das keiner nachführt. |

Das ist die einzige Frage, die Q10 bis Q12 neu aufgeworfen haben. Sie ist
bewusst als Frage gestellt und nicht entschieden: Zweimal — bei O3 und O8 —
hat der Betreiber der Bemerkung vor einem neuen Feld den Vorzug gegeben. Hier
spricht dagegen, dass die Rückfallebene **strukturell** ist: Man will nach ihr
filtern, nicht in ihr lesen.

### 17.3 Zwei Folgen, die festgehalten gehören

**Aus O7.** Ein *ähnlicher* Treffer aus dem Plan steht über einem *exakten*
aus der Historie. Das ist gewollt: Die freigegebene Betriebsunterlage geht der
Erinnerung vor (Q4 Kapitel 6.6). Die Kennzeichnung aus V3 ist das Gegengewicht
— sie macht sichtbar, dass der Plan oben steht, *weil* er der Plan ist.

**Aus O6.** Die Vorschlagspolitik entscheidet künftig über zwei Achsen: Feld
und Quelle. Ohne die zweite wäre die Öffnung für den Stab ein Rückbau des
Schutzes vor Historienlecks; mit ihr ist sie keiner.

### 17.4 Was noch fehlt

**Vom Betreiber:** die Antwort auf O9 und die Bestätigung des Bezugswegs für
die taktischen Zeichen (Abschnitt 14.5 — Vorlagen bauen unter CC-BY statt
gemeinfreies Altarchiv).

**An Vorschriften** bleiben drei Stücke offen, alle drei nicht entscheidbar,
sondern nur zu beschaffen:

| Fehlt | Wirkung |
| --- | --- |
| PDV 810.2 VS-NfD „Sprech- und Datenfunkverkehr" (L2) | die einzige inhaltliche Lücke: Bandlage und Verkehrsform des Analogfunks bleiben unbelegt |
| Ergänzende Loseblattsammlung Digitalfunk BOS (L5) | keine — `rufgruppe` ist bewusst Freitext |
| THW-Funkrufnamenregelung (L7) | keine — der Funkrufname wird nicht geprüft |

L1 ist mit Q11 und Q12 geschlossen, L3 und L6 durch die Entscheidung zur
Skizze erledigt, L4 ist eine bewusste Grenze.
