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

Zwei weitere Beschlüsse sind während der Arbeit hinzugekommen:

6. Die nachgereichte PDV 800 (Q10) kennt die **Rückfallebene** als
   Planungsgegenstand — Beschluss B6, Abschnitt 9.
7. Auch der **Eingang** soll den Fernmeldeweg erfassen: vom Fernmelder gewählt,
   vom LdF geprüft — Beschluss B7, Abschnitt 10.

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
| **neu** | Q10 kennt die *Rückfallebene* als Planungsgegenstand → Beschluss **B6** (Abschnitt 9) |
| **verkleinert** | Lücken L1 und L2 (Abschnitt 17) |

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
Stelle.** Beides ist ohne Widerspruch möglich (Abschnitt 12).

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
| F12 | Der Eingang erfasst nur das **Mittel**, keinen Weg des Plans: Der Wegbezug ist mit `04_richtung = 'A'` auf den Ausgang verriegelt | [message_repository.php:1770](app/message_repository.php:1770) |

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

### 5.2 Die Stellenart gehört der Gegenstelle

> **Berichtigung vom 01.09.2026.** Dieser Abschnitt hat zunächst das Falsche
> verlangt, und der Betreiber hat es an der erzeugten Skizze bemerkt: die
> `stellenart` stand am **eigenen Weg**. Sie steht jetzt an der
> **Gegenstelle**. Migration 129 zieht sie um; die alte Spalte bleibt lesbar,
> damit freigegebene Fassungen weiter sagen können, was sie gesagt haben.

Der Fernmeldeplan legt die **eigenen** Erreichbarkeiten fest. Eine Planzeile
ist **eines unserer Mittel**, getragen von einer unserer eigenen
Betriebsstellen — die Fernmeldezentrale mit ihrem Digitalfunk, ihrem
Amtsanschluss, ihrem Fax; der Meldekopf mit seinem Melderdienst. Das
Verhältnis dieser Stelle zu uns ist immer „eigen"; zu fragen, ob sie uns
über- oder untergeordnet ist, hieße zu fragen, ob wir uns selbst
übergeordnet sind.

Über- und Unterstellung sind Eigenschaften der **anderen** Seite. Fb Fü 77
zeichnet genau das:

```
   übergeordnete Stellen    ┌──────────────────┐    nachgeordnete Stellen
           ◄────────────────┤  FÜHRUNGSSTELLE  ├────────────────►
                            │   Funkrufname    │
                            │  ── unsere ──    │
                            │      Mittel      │
                            └──────────────────┘
```

Deshalb trägt `nv_fernmeldeplan_gegenstellen` die Spalte:

| Wert | Bedeutung |
| --- | --- |
| `UEBER` | übergeordnete Stelle — steht in der Skizze **links** |
| `UNTER` | nachgeordnete Stelle — steht **rechts** |
| `NEBEN` | benachbarte Stelle, Partnerorganisation |
| *(leer)* | noch nicht eingeordnet; die Skizze setzt sie rechts und **sagt es** |

**Kein `EIGEN`.** Eine Gegenstelle ist per Begriff die andere Seite. Ein
Wert, den nichts je annehmen darf, gehört nicht in eine Aufzählung — er würde
irgendwann doch gesetzt und dann ausgewertet.

Die Spalte „Betriebsstellen-Klarbezeichnung" heißt künftig **„Stelle"** mit
der Ausfüllhilfe „**Ihre eigene** Betriebsstelle, die dieses Mittel führt:
Führungsstelle, Fernmeldezentrale, Meldekopf". Der Datenbankname
`betriebsstelle` bleibt.

### 5.3 Gegenstellen am Weg

**Das ist die Tabelle je Kommunikationsmittel**, die der Betreiber verlangt
hat: Zu jedem unserer Mittel steht, welche Stellen darüber erreichbar sind.
Hier — und nur hier — werden fremde Stellen gepflegt.

| Angabe | Was | Speist |
| --- | --- | --- |
| `name` | Klarbezeichnung der Stelle oder Einheit | **Feld 15** Absender (Eingang), Feld 10 Anschrift (Ausgang) |
| `erreichbarkeit` | Rufname, Rufnummer, Adresse — je nach Medium des Wegs | Feld 6 bzw. Feld 11 |
| `stellenart` | über-, nach- oder nebengeordnet (Abschnitt 5.2) | die Seite der Skizze |

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

| Medium des Wegs | Feld 6 (Rufname der Gegenstelle) | Feld 11 (Rufnummer) | Feld 15 / Feld 10 |
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
| V6 | Feld 11 (`11_rufnummer`) wird vorschlagsfähig — heute lässt die Politik nur Feld 6 und Feld 15 zu. |
| V7 | Freie Eingabe bleibt in allen Fällen möglich. Ein Vorschlag ist Hilfe, keine Auswahlliste. |
| V8 | Der **Stab** erhält beim Abfassen einer ausgehenden Nachricht Vorschläge für Feld 10 — **ausschließlich aus dem Plan**, nie aus der Historie. |
| V9 | Ein **eindeutiger exakter** Treffer aus dem Plan wird in das leere Feld **vorbelegt**, mit sichtbarem Hinweis auf die Herkunft. |
| V10 | Aus der Historie wird **nie** vorbelegt. Sie erscheint ausschließlich in der Auswahlliste. |

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

### 5.6 Vorbelegung aus dem Plan

Ein Vorschlag, den der Bediener anklicken muss, ist ein halber Schritt. Wenn
der Plan die Antwort **eindeutig** enthält, steht sie im Feld — und sagt, woher
sie kommt.

**Die Regel in einem Satz:** Der Plan belegt vor, die Historie schlägt vor.

| Lage | Verhalten |
| --- | --- |
| Genau **ein** exakter Treffer im aktiven Plan | Das Feld ist vorbelegt. Daneben steht „aus der Fernmeldeplanung übernommen", dazu der Weg, über den der Treffer gilt |
| **Mehrere** Treffer im Plan | Keine Vorbelegung. Das Feld bleibt leer, alle Treffer stehen zur Auswahl |
| Nur ein **ähnlicher** Treffer im Plan | Keine Vorbelegung. Ähnlichkeit ist ein Angebot, keine Behauptung |
| **Kein** Plantreffer | Das Feld bleibt leer. Die Auswahlliste bietet die Historie an, gekennzeichnet als solche |
| Das Feld trägt bereits einen Wert | Keine Vorbelegung. Ein vorhandener Wert wird nie überschrieben |
| Neudisposition nach einer Rückgabe | **Keine** Vorbelegung — siehe unten |

Vorbelegt werden die Ziele der Zuordnung: im Ausgang Feld 6 (Rufname der
Gegenstelle), im Eingang Feld 15 (Absender).

**Zur Feldnummer.** Feld 15 ist in der Q1-Zählung der **Absender**; die
Datenbank führt ihn unter `13_abseinheit`, weil dieser Spaltenname dem
Zugriffsindex folgt und nicht der gedruckten Nummer
([nv_field_numbers.php:69](app/nv_field_numbers.php:69)). Das ist die
Nummernbrücke aus `SPEC.md` Abschnitt 3 in freier Wildbahn — im Text dieser
Spec steht immer die **gedruckte** Nummer.

#### Der Eingang: eine ausgewählte Gegenstelle wird nicht noch einmal geraten

Der wichtigste Fall ist der Eingang, und er ist einfacher als die allgemeine
Regel oben:

1. **A/W füllt Feld 6.** Die Vorschlagsliste zeigt zuerst die Gegenstellen des
   aktiven Plans, dahinter die Historie — jede mit ihrer Kennzeichnung.
2. **Wählt A/W eine Gegenstelle des Plans aus**, wird diese Auswahl
   festgehalten: nicht ihr Text, sondern **welche** Gegenstelle es war.
3. **Der LdF bekommt Feld 15 vorbelegt** mit dem `name` genau dieser
   Gegenstelle, gekennzeichnet als „aus der Fernmeldeplanung übernommen".
4. **Tippt A/W frei**, gibt es keine Auswahl und keine Vorbelegung. Feld 15
   bleibt leer, und die Auswahlliste des LdF bietet die Historie an.

Der Unterschied zu Schritt 2 ist der Kern: Die Vorbelegung folgt einer
**getroffenen Auswahl**, nicht einem nachträglichen Textvergleich. Damit
entfällt hier die Eindeutigkeitsfrage von oben — es gibt nichts zu raten, weil
A/W die Antwort bereits gegeben hat. Zwei Gegenstellen mit gleichem Rufnamen
unter verschiedenen Wegen wären für ein Textverfahren mehrdeutig; für eine
Auswahl sind sie es nicht.

Festgehalten wird die Auswahl **außerhalb des Vordrucks**, in einer
`estab_`-Spalte (Abschnitt 10.4) — der Vordruck kennt kein Feld dafür.

#### Warum nur bei Eindeutigkeit

Bei zwei passenden Einträgen müsste die Anwendung einen auswählen. Sie hätte
dafür kein Kriterium außer Zufall — Häufigkeit und Jüngstes ordnen eine Liste,
sie begründen keine Entscheidung. Eine Vorbelegung, die rät, ist schlimmer als
keine: Sie sieht aus wie eine Auskunft.

#### Warum die Historie nie vorbelegt

Der Plan ist eine **freigegebene Unterlage** mit Stand und F.d.R.; ein
bestätigtes Nachrichtenpaar ist eine **Beobachtung**. Eine Beobachtung darf
erinnern, sie darf nicht behaupten. Q10 Nummer 2.6 sagt, wonach im Zweifel zu
verfahren ist: nach dem Kommunikationsplan.

#### Keine Vorbelegung nach einer Rückgabe

Gibt A/W eine Nachricht an den LdF zurück, verlangt der Bestand ausdrücklich
eine **andere** Disposition — „Nach einer Rückgabe ist ein anderes
Übermittlungsmittel oder ein anderer Beförderungsweg zu disponieren."
([message_repository.php:1905](app/message_repository.php:1905)). Eine
Vorbelegung schöbe genau den Wert zurück, der eben nicht funktioniert hat. Der
Zweig erkennt die Neudisposition bereits (`$redisposition`); die Vorbelegung
hält sich dort heraus.

#### Der Nachweis muss beides unterscheiden

Ein vorbelegter Wert, den der LdF ohne Änderung absendet, ist seine Eintragung
— dafür zeichnet er. Aber die Beweiskette muss sagen können, **wie** er dort
hingekommen ist: selbst gewählt oder vorbelegt übernommen. Ohne diesen
Unterschied lässt sich später nicht mehr feststellen, ob der LdF geprüft oder
durchgewinkt hat.

Die Ereigniszeile hält deshalb fest: die Herkunft (`plan`), die Version des
Plans und ob der Wert unverändert übernommen oder überschrieben wurde. Der
Ausgang führt für seine Disposition bereits eine solche Momentaufnahme
([message_repository.php:1860](app/message_repository.php:1860)); die
Vorbelegung reiht sich dort ein.

**Die Vorbelegung bindet nicht.** Das Feld bleibt frei überschreibbar, und ein
überschriebener Vorschlag wird nicht zurückgesetzt — V7 gilt unverändert.

### 5.7 Die Wegewahl spricht so

Die Auswahlliste bei der Disposition des LdF fragt nicht „an wen", sondern
**„über welchen Weg"**. Die Gegenstellen des Wegs stehen als Erläuterung
darunter — sie begründen die Wahl, sie sind nicht die Wahl.

### 5.8 Abgrenzung

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

**Analogfunk:**

| Feld | Pflicht | Form |
| --- | --- | --- |
| `band` | ja | `2m`, `4m` — Q6 druckt „Kanal 2 m" und „Kanal 2 / 4 m" |
| `kanal` | ja | Freitext |
| `bandlage` | ja | **Freitext**, Ausfüllhilfe nennt Oberband und Unterband |
| `verkehrsform` | ja | **Freitext**, Ausfüllhilfe nennt Wechsel-, Gegen-, bedingten Gegen- und Richtungsverkehr |
| `relaisstelle` | nein | Freitext |

**Bandlage und Verkehrsform werden nicht geprüft** (Entscheidung O12). Die
üblichen Werte stehen als Ausfüllhilfe am Feld, nicht als Auswahlliste und
nicht als Zurückweisung. Damit braucht die Anwendung für diese beiden Angaben
**keine Quelle** — was sie zulässt, entscheidet der S6, nicht das Programm.
Das schließt zugleich die letzte inhaltliche Lücke der Überarbeitung: Die
PDV 810.2 wird nicht mehr gebraucht (L2).

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

## 9. Beschluss B6 — Die Rückfallebene am Weg

### 9.1 Anlass

Q10 Anlage 20 definiert:

> Rückfallebene — Ersatz für eine IuK-Verbindung, ggf. auch unter Inkaufnahme
> einer Leistungsbeschränkung, z.B. Entfall der Verschlüsselung

und Q10 Nummer 3 verlangt, Kommunikationspläne „erforderlichenfalls unter
Berücksichtigung einer Rückfallebene" zu erstellen. Q10 Nummer 2.7 sagt, was
sie im Ernstfall leistet: Bei fehlender Verbindung ist die Information dennoch
zu übermitteln — durch Standortwechsel, **Nutzung anderer Dienste** oder
persönliche Weiterleitung.

### 9.2 Entscheidung

Ein Weg kann als Rückfallebene **für einen bestimmten anderen Weg desselben
Plans** gekennzeichnet werden. Im Formular ein Schalter, danach eine Auswahl:
*„Rückfallebene für …"*.

Damit beantwortet der Plan die Frage, die im Ausfall gestellt wird — nicht
„gibt es hier irgendwo einen Ersatz", sondern „was tritt **an die Stelle
dieses** Wegs".

### 9.3 Der Weg bekommt eine eigene, dauerhafte Kennung

Damit ein Weg auf einen anderen zeigen kann, muss der andere **eine Identität
haben, die den Versionswechsel überlebt**. Die hat er bisher nicht.

Heute ist ein Weg nur eine Zeile in einer Planversion. Die Versionskopie legt
alle Wege mit `INSERT … SELECT` neu an
([dv_operations.php:4829](app/dv_operations.php:4829)); sie erhalten dabei neue
`fernmeldeplan_eintrag_id`. Ein Verweis auf diese Kennung zeigte nach dem
Versionswechsel auf die Wege der **alten** Version. „Derselbe Funkweg wie in
Version 3" ist im Bestand heute gar keine beantwortbare Frage — die Anwendung
führt Zeilen, keine Wege.

**Deshalb wird der Weg zu einem eigenen Gegenstand.** Eine schmale Tabelle
vergibt die Identität, die Planversion trägt nur noch seinen jeweiligen
Zustand:

```
nv_fernmeldewege
  weg_id       BIGINT UNSIGNED  Schlüssel, dauerhaft, nie wiederverwendet
  einsatz_id   BIGINT UNSIGNED  Bindung an den Einsatz
  weg_nummer   INT UNSIGNED     laufende Nummer je Einsatz, für Menschen
  angelegt_am / angelegt_von    Nachweis
```

`weg_id` ist die Kennung für die Maschine, `weg_nummer` die für den Bediener:
Im Plan, im Ausdruck und in der Auswahlliste steht „Weg 3", nicht eine
fünfstellige Zahl. Beide werden **einmal** vergeben und nie wieder geändert
oder neu vergeben.

Die Zuordnung von Kennung zu Eintrag steht in einer **eigenen Tabelle**
`nv_fernmeldeweg_zuordnung`, nicht als Spalte am Eintrag (Entscheidung O20):

```
nv_fernmeldeweg_zuordnung
  fernmeldeplan_eintrag_id   Schlüssel, ein Eintrag hat eine Kennung
  fernmeldeplan_id           mitgeführt für den zusammengesetzten Schlüssel
  weg_id                     die Kennung
                             eindeutig je (fernmeldeplan_id, weg_id)
```

**Warum daneben und nicht daran.** Eine Spalte am Eintrag müsste für jede
Bestandszeile **gefüllt** werden, und genau das lässt
`estab_dv94_fernmeldeplan_entry_update`
([Migration 94, Zeile 971](docker/db/migrations/94-dv-organisational-controls.sql:971))
nicht zu: Eine Änderung an einem Planweg ist nur zulässig, solange sein Plan
`ENTWURF` des gerade aktiven, offenen Einsatzes ist. Jede Zeile eines
freigegebenen oder ersetzten Plans ist gesperrt.

Der Ausweg wäre gewesen, den Auslöser für die Dauer der Migration zu ersetzen.
Der Betreiber hat anders entschieden, und die Entscheidung ist die
konservativere: **Der geschützte Bestand wird nicht angefasst, sondern nur
gelesen.** Die Kennungen entstehen durch `INSERT` in zwei neue Tabellen; keine
Zeile eines freigegebenen Plans ändert sich, kein Auslöser wird berührt, und
die Unveränderlichkeitszusage bleibt wortwörtlich wahr statt nur sinngemäß.

Der Preis ist ein Verbund beim Lesen. Er trifft eine Tabelle mit einem
Schlüsselzugriff und fällt gegenüber dem Gewinn nicht ins Gewicht.

**Die Rückfallebene bleibt trotzdem eine Spalte am Eintrag.** Sie muss nicht
zurückgefüllt werden — Bestandszeilen haben keine —, und eine Spalte
*anzulegen* ist DDL, die keinen Zeilenauslöser feuert. Nur das Füllen wäre das
Problem gewesen, und das geschieht ausschließlich im Entwurf.

### 9.4 Was das nebenbei löst

Die Trennung von Identität und Reihenfolge ist nicht nur die Voraussetzung für
die Rückfallebene, sie räumt drei Dinge mit auf:

| Bisher | Danach |
| --- | --- |
| `sortierung` trug zwei Aufgaben: Reihenfolge **und** faktische Wiedererkennung über Versionen hinweg | `sortierung` ist nur noch Anzeigereihenfolge — und darf damit endlich geändert werden. Wege umsortieren ist heute nicht möglich, ohne die Wiedererkennung zu zerstören |
| „Seit wann besteht dieser Weg, was hat sich an ihm geändert?" ist nicht beantwortbar | Die Versionen eines Wegs sind über `weg_id` verkettet — ein echter Versionsvergleich, statt Zeilen nach Text zu paaren |
| Ein einmal gestrichener Weg ist weg | Die Identität bleibt. Der Entwurf kann anbieten, einen früher geführten Weg **wieder aufzunehmen** — mit seiner Geschichte |

**Der Nachweis bleibt unberührt.** Die Beweiskette der Nachricht zeigt weiter
auf die **Zeile**, nicht auf die Identität:
`estab_fernmeldeplan_eintrag_id`
([message_repository.php:1849](app/message_repository.php:1849)) hält fest, über
welchen Weg **in welchem Wortlaut** die Nachricht lief. Das ist richtig so und
wird nicht angefasst. Die Identität kommt hinzu, sie ersetzt nichts.

### 9.5 Der Verweis und seine Absicherung

Gespeichert wird **eine** Spalte am Eintrag:

```
rueckfallebene_fuer_weg   BIGINT UNSIGNED NULL
```

`NULL` heißt „keine Rückfallebene", ein Wert heißt „Rückfallebene für den Weg
mit dieser Kennung". Ein zusätzliches Wahrheitsfeld für den Schalter gäbe es
nur, damit es dem Verweis widersprechen kann — angehakt ohne Ziel, Ziel ohne
Haken. Diesen Zustand kann es so nicht geben; der Schalter ist Darstellung.

| Absicherung | Wie |
| --- | --- |
| Ziel liegt im selben Plan **und** in derselben Version | zusammengesetzter Fremdschlüssel `(fernmeldeplan_id, rueckfallebene_fuer_weg)` → `nv_fernmeldeweg_zuordnung (fernmeldeplan_id, weg_id)` |
| Kein Weg ist seine eigene Rückfallebene | Prüfbedingung `rueckfallebene_fuer_weg <> weg_id` |
| Keine Ringe | Die Anwendung geht die Kette beim Speichern ab und weist einen Ring zurück. **Ketten sind erlaubt**: Ein Ersatz darf selbst einen Ersatz haben |
| Ein Weg, auf den zurückgefallen wird, verschwindet nicht unbemerkt | Fremdschlüssel mit `RESTRICT`. Das Löschen nennt die Wege, die auf ihn zurückfallen, statt sie stillschweigend zu lösen |

Zu `RESTRICT`: Die bequeme Alternative wäre `SET NULL` — der Ersatz verliert
seinen Bezug und niemand merkt es. Genau das darf in einer Betriebsunterlage
nicht passieren. Wer den Hauptweg streicht, entscheidet auch über seinen
Ersatz.

Die Versionskopie nimmt beide Spalten unverändert mit — `SELECT ?, weg_id,
sortierung, rueckfallebene_fuer_weg, …`. Weil die Identitäten dieselben
bleiben, zeigt der Verweis in der neuen Version auf denselben Weg, **ohne eine
Zeile Umrechnungscode**.

### 9.6 Warum nicht die `sortierung`

Ein früherer Entwurf dieses Abschnitts ließ den Verweis auf die `sortierung`
zeigen. Sie ist im Bestand stabil — einmal als `MAX(sortierung) + 1` vergeben
([dv_operations.php:5036](app/dv_operations.php:5036)), von der Änderung nicht
angefasst ([dv_operations.php:5173](app/dv_operations.php:5173)), beim Löschen
nicht neu vergeben ([dv_operations.php:5298](app/dv_operations.php:5298)) und
von der Kopie wörtlich übernommen. Der Verweis hätte funktioniert.

Er wird trotzdem verworfen, aus drei Gründen:

1. **Sie ist keine Identität, sie sieht nur so aus.** Ihre Stabilität ist ein
   Nebeneffekt davon, dass heute niemand umsortieren kann. Wer morgen
   „Wege umsortieren" baut — eine naheliegende Bitte —, zerstört damit
   stillschweigend alle Rückfallverweise. Ein Merkmal, dessen Richtigkeit
   davon abhängt, dass eine andere Funktion nie gebaut wird, ist eine Falle.
2. **Die Nummer wird wiederverwendet.** `MAX + 1` wird **je Planversion**
   berechnet. Wer im Entwurf den letzten Weg löscht und einen neuen anlegt,
   bekommt dieselbe Nummer für einen anderen Weg. Über Versionen hinweg wäre
   das ein Identitätswechsel ohne Spur.
3. **Sie beantwortet die interessantere Frage nicht.** Eine echte Kennung
   liefert den Versionsvergleich aus Abschnitt 9.4 gratis mit; die
   `sortierung` liefert ihn nie.

Der Umweg ist hier festgehalten, weil er beim nächsten Lesen wieder verlockend
aussieht.

### 9.7 Bedienung

| Fall | Verhalten |
| --- | --- |
| Erster Weg eines Plans | Der Schalter ist abgeschaltet, mit Begründung: Es gibt noch keinen Weg, für den er einspringen könnte |
| Auswahlliste | Die übrigen Wege desselben Entwurfs, beschriftet wie der Bediener sie kennt: *Weg 3 · Stelle · Mittel · Erreichbar unter*. Ohne den Weg selbst und ohne die, die einen Ring schlössen |
| Taktische Ansicht | Der Ersatz steht **eingerückt unter** dem Weg, den er ersetzt — die Leserichtung des Ausfalls |
| Betriebliche Ansicht | Eigene Spalte „Rückfallebene für" |
| Wegewahl des LdF | Ein Ersatzweg bleibt wählbar — er ist ein richtiger Weg. Die Auswahl kennzeichnet ihn, damit der LdF weiß, dass er auf den Ersatz greift |
| Skizze | Dünnere, hellere Linie. Q10 sagt „ggf. unter Inkaufnahme einer Leistungsbeschränkung"; das Bild soll das nicht verschweigen |

---

## 10. Beschluss B7 — Der Eingangsweg

### 10.1 Befund: Das Muster gibt es, aber nur für das Mittel

Der Zweischritt „Fernmelder erfasst, LdF prüft" ist **bereits gebaut** — nur
nicht für den Weg:

| Schritt | Was heute geschieht | Fundstelle |
| --- | --- | --- |
| `FM-Eingang` | A/W erfasst Feld 1, das tatsächlich benutzte Übermittlungsmittel | [workflow.php:1972](app/workflow.php:1972) |
| `LdF-Eingang` | LdF **muss bestätigen**: ohne `incoming_transport_confirmed` wird zurückgewiesen mit „Bestätigen Sie den vom Fernmelder erfassten Eingangsweg." und „LdF muss den Eingangsweg bestätigen." | [message_repository.php:1588](app/message_repository.php:1588) |

Die Rollenteilung, die Sperre, die Beweiszeile — alles vorhanden. Was fehlt,
ist der **Gegenstand**: Bestätigt wird eines von sechs Ankreuzfeldern, kein Weg
des Fernmeldeplans. Die Spalte `estab_fernmeldeplan_eintrag_id` wird
ausschließlich im Ausgang geschrieben, und ihre Leseabfrage ist mit
`04_richtung = 'A'` verriegelt
([message_repository.php:1770](app/message_repository.php:1770)).

Der Eingang sagt heute „kam über Funk". Er sagt nicht „kam über Weg 3 — Funk
digital, Rufgruppe …, unser Rufname …". Das ist **F12**.

### 10.2 Warum das keine Kosmetik ist

**Der Plan bekommt keine Rückmeldung.** Beschluss B2 hat den Plan auf die
eigene Erreichbarkeit gestellt. Ohne Eingangsweg ist die Frage „welche unserer
Erreichbarkeiten wird tatsächlich benutzt" nicht beantwortbar — und genau das
ist die Auskunft, aus der der S6 die nächste Version baut. Der Plan bliebe eine
Behauptung, die nie zurückhört.

**Die Dokumentationspflicht gilt in beide Richtungen.** Q10 Nummer 2.8: „Der
IuK-Einsatz ist zu dokumentieren." Der Eingang ist die Hälfte davon.

**Ohne Eingangsweg lässt sich nicht steuern.** Q10 Nummer 2.5 verlangt, die
Kommunikation auf das notwendige Maß zu beschränken; Q12 warnt vor der
Auslastung des Zeitschlitzkontingents einer Basisstation. Wer nicht weiß, über
welchen Weg wie viel hereinkommt, kann weder das eine noch das andere.

### 10.3 Entscheidung

Der Eingang erfasst den Weg wie der Ausgang — mit der Rollenteilung, die dort
schon gilt, nur in der anderen Reihenfolge:

| | Ausgang | Eingang |
| --- | --- | --- |
| Wählt den Weg | LdF disponiert | **A/W** — er hat die Nachricht angenommen und weiß, worüber sie kam |
| Prüft | A/W führt aus | **LdF** bestätigt |

Das ist keine neue Mechanik. Es ist die vorhandene Bestätigung, vom Mittel auf
den Weg gehoben.

**Der Weg ist freiwillig.** Feld 1 bleibt Pflicht — das Mittel weiß der
Fernmelder immer. Den Weg weiß er meistens, aber nicht zwingend: Ein Anruf
über eine Nummer, die der Plan nicht führt, oder ein Funkspruch mitten in der
Annahme lässt sich nicht immer sofort zuordnen. Ein Pflichtfeld erzwänge dort
eine Angabe, und eine erzwungene Angabe ist eine erfundene.

Die Bestätigung des LdF richtet sich danach: Er bestätigt das Mittel immer und
den Weg, wenn einer da ist. **Trägt er selbst einen nach**, wo A/W keinen
angegeben hat, ist auch das eine Änderung und wird begründet.

### 10.4 Das Mittel steht im Vordruck, der Weg daneben

**Der Vordruck kennt keinen Fernmeldeweg.** Er hat Feld 1 für das benutzte
Übermittlungsmittel — sechs Kästchen — und sonst nichts dergleichen. Ein
Eingabefeld „Weg" zwischen die Vordruckfelder zu setzen, verstieße gegen
`UX-PAPIERBILD` und gegen die Regel aus `SPEC.md` W3: Der Ausdruck darf kein
Feld vortäuschen, das der Vordruck nicht hat.

Deshalb die Trennung:

| Angabe | Wo erfasst | Wo gespeichert |
| --- | --- | --- |
| **Mittel** (Feld 1) | **im Vordruck**, an seinem gedruckten Platz | `01_medium` |
| **Weg** | **außerhalb des Vordrucks**, in der Betriebsleiste der Station | `estab_fernmeldeplan_eintrag_id` |

Der Bestand kennt diese Trennung bereits und macht sie am Spaltennamen
sichtbar: Vordruckfelder tragen ihre Feldnummer (`01_medium`, `06_befweg`),
Angaben der Anwendung tragen das Präfix `estab_`
([Migration 94, Zeile 687](docker/db/migrations/94-dv-organisational-controls.sql:687)).
Der Weg ist eine Angabe der Anwendung und bleibt es.

**Die Anwendung leitet Feld 1 nicht ab, sie prüft es.** Der naheliegende Weg
wäre, das Mittel aus dem gewählten Weg zu setzen. Das wäre bequem und falsch:
Es verlegte die Eingabe eines **Vordruckfeldes** nach außerhalb des Vordrucks,
also genau in die Richtung, die diese Trennung vermeiden soll.

Stattdessen: A/W trägt Feld 1 im Vordruck ein und wählt den Weg daneben.
Stimmen Medium des Wegs und Feld 1 nicht überein, wird zurückgewiesen — die
Doppelangabe wird damit zur **Probe**, nicht zum Widerspruch. Der Ausdruck
zeigt weiterhin nur, was auf dem Papier steht.

**Warum der Ausgang es anders macht, und warum das richtig bleibt.** Dort
schreibt die Disposition Feld 1
([message_repository.php:1853](app/message_repository.php:1853)), statt es
abzufragen. Der Unterschied ist nicht technisch, sondern fachlich:

| | Ausgang | Eingang |
| --- | --- | --- |
| Der LdF bzw. A/W … | **entscheidet** das Mittel, indem er den Weg disponiert | **beobachtet** das Mittel, über das die Nachricht kam |
| Feld 1 ist damit … | die Aufzeichnung dieser Entscheidung — sie folgt aus dem Weg | eine eigene Feststellung — der Weg ist die Probe darauf |

Wo entschieden wird, schreibt die Entscheidung das Feld. Wo festgestellt wird,
steht die Feststellung im Vordruck und der Weg prüft sie. In beiden Fällen
bleibt der **Weg** außerhalb des Vordrucks.

### 10.5 Was am Bestand zu ändern ist

**Keine neue Spalte.** `estab_fernmeldeplan_eintrag_id` trägt den Weg in beiden
Richtungen; sie pinnt weiterhin die **Zeile**, also den Weg in dem Wortlaut, in
dem die Nachricht lief (Abschnitt 9.4).

| Ort | Änderung |
| --- | --- |
| [message_repository.php:1770](app/message_repository.php:1770) | Die Verriegelung `04_richtung = 'A'` fällt für den Wegbezug |
| [message_repository.php:1588](app/message_repository.php:1588) | Die Bestätigung des LdF erstreckt sich auf den Weg, nicht nur auf das Mittel |
| [workflow.php:1972](app/workflow.php:1972) | `FM-Eingang` gibt die Wegwahl frei |
| [read_authorization.php:270](app/read_authorization.php:270) | A/W bekommt die Wegauswahl mit denselben Vorschlägen wie der LdF (V5) |

### 10.6 Der Weg, den der Plan nicht führt

Eine Gegenstelle ruft auf einem Weg an, den niemand geplant hat. Das kommt vor,
und die Nachricht hört deswegen nicht auf zu existieren.

**A/W darf ihn erfassen**, in derselben freien Form, die eine Führungsstelle
ohne Stab schon heute benutzt (`FUEST-KLEIN-BEFOERDERUNG`, `SPEC.md`
Abschnitt 5.3). Der LdF bestätigt ihn als das, was er ist.

Und dann wird er **sichtbar gemacht**: Die Plantafel führt eine kurze Liste
„Wege, über die Verkehr lief und die der Plan nicht führt". Das ist der
Rückkanal aus Abschnitt 10.2 in seiner nützlichsten Form — Q10 Nummer 2.6
verlangt, Abweichungen vom Kommunikationsplan zu vermeiden, und der erste
Schritt dazu ist, sie zu sehen. Der S6 entscheidet dann, ob der Weg in die
nächste Version gehört oder ob die Gegenstelle umzugewöhnen ist.

### 10.7 Bedienung

Die Wegauswahl bekommt ihren Platz dort, wo der LdF sie schon hat: in einer
**Leiste über dem Vordruck**, nicht zwischen seinen Feldern. Der Bestand führt
dort bereits einen Auswahlkasten mit den Wegen des aktiven Plans, beschriftet
„Plan v3 · Funk · Betriebsstelle · Rufname · Kanal …"
([official_message_form.php:2545](4fach/official_message_form.php:2545)). Der
Eingang bekommt denselben Kasten — dieselbe Beschriftung, dieselbe Stelle,
andere Station.

**Beim Fernmelder (`FM-Eingang`), Leiste „Eingangsweg":**

| Bedienelement | Verhalten |
| --- | --- |
| Auswahl „Eingangsweg" | Die Wege des aktiven Plans, Beschriftung wie bei der Disposition. **Freiwillig** — die erste Wahl lautet „kein Weg angegeben" |
| Textfeld „Bemerkung zum Eingangsweg" | Freiwillig. Was der Fernmelder zum Weg zu sagen hat: schlechte Verständigung, Relaisbetrieb, Rückfrage nicht möglich |

**Beim LdF (`LdF-Eingang`), in der vorhandenen Leiste „Eingangsweg durch LdF
bestätigen"** ([official_message_form.php:2500](4fach/official_message_form.php:2500)):

| Bedienelement | Verhalten |
| --- | --- |
| „Vom Fernmelder erfasst: …" | zeigt jetzt **Mittel und Weg**, wie heute schon das Mittel |
| Bemerkung des Fernmelders | wird **angezeigt, nicht bearbeitbar** |
| Auswahl „Eingangsweg" | derselbe Kasten; der LdF kann den Weg ändern oder einen nachtragen |
| Häkchen „Eingangsweg geprüft und bestätigt" | unverändert Pflicht |
| „Begründung nur bei Änderung" | unverändert das **eigene** Feld des LdF |

### 10.8 Zwei Bemerkungen, zwei Urheber

Die Bemerkung des Fernmelders ist für den LdF **unveränderlich**. Das ist
keine Förmlichkeit, sondern die Trennlinie, auf der die ganze Station beruht:
Der eine stellt fest, der andere prüft. Könnte der Prüfende die Feststellung
umschreiben, wäre die Prüfung wertlos — und der Nachweis nicht mehr in der
Lage zu sagen, wer was behauptet hat.

| Feld | Urheber | Für den anderen |
| --- | --- | --- |
| Bemerkung zum Eingangsweg | A/W | nur lesbar |
| Begründung bei Änderung | LdF | vom A/W gar nicht erreichbar — er ist zu diesem Zeitpunkt fertig |

Technisch ist das kein neues Verfahren: Der Bestand weist bereits Werte
zurück, die eine Station nicht schreiben darf, indem er sie aus ihrer
Feldliste heraushält und einen abweichend gelieferten Wert verwirft
([workflow.php:1965](app/workflow.php:1965)). Die Bemerkung des Fernmelders
reiht sich dort ein: `LdF-Eingang` führt sie nicht in seinen schreibbaren
Feldern.

**Sie ersetzt die Bemerkung des Wegs im Plan nicht.** Der Plan sagt, was für
diesen Weg allgemein gilt; die Bemerkung des Fernmelders sagt, was bei **dieser
einen** Nachricht war. Das erste gehört dem S6, das zweite der Nachricht.

### 10.9 Was daraus folgt

Mit der dauerhaften Wegkennung aus Beschluss B6 wird die Auskunft erst
vollständig: **„Über diesen Weg liefen im Einsatz 14 Nachrichten — 9 herein,
5 hinaus"**, über alle Planversionen hinweg. Das ist die Zahl, aus der eine
Fernmeldeplanung besser wird.

---

## 11. Kopfleiste nach Q6

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

## 12. Zwei Ansichten auf denselben Bestand

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

## 13. Anforderungskatalog

Neue Regeln des Moduls **M7 `fuehrungsmittel`**. Vorschriftenregeln gehören
nach `app/dv_rules.php`, Bedienregeln nach `app/ux_rules.php`; beide Register
erzwingen einen Test je Regel.

### 13.1 Vorschriftenregeln (Q4, Q6, Q7, Q8)

| ID | Quelle | Soll | Abnahme |
| --- | --- | --- | --- |
| `FMP-MEDIUM-VORDRUCK` | Q1 Felder 1 und 7 | Die Medien des Plans sind die Ankreuzfelder des Vordrucks. Der Plan erfindet kein Medium hinzu. | Die Menge der planbaren Medien ist eine Teilmenge von `nv_nachrichten.01_medium`; ein disponierter Weg schreibt Feld 1 ohne Übersetzung |
| `FMP-EIGENE-ERREICHBARKEIT` | Q4 Kap. 6.1.1, Q6, Q10 Anlage 20 | Eine Betriebsstelle ist eine Stelle im eigenen IuK-Netz, bei der Nachrichten aufgenommen, befördert oder übermittelt werden. Der Plan führt deren Erreichbarkeit, nicht die Gegenstellen einzelner Nachrichten. | Die Plantafel benennt das; die Gegenstelle ist ausschließlich Feld 6 der Nachricht |
| `FMP-STELLENART` | Q4 Kap. 6.1.2, Q6 | Zu jeder Stelle ist erkennbar, ob die Verbindung vertikal nach oben, vertikal nach unten oder horizontal führt. | Jeder Eintrag trägt eine Stellenart; die taktische Ansicht zeigt sie |
| `FMP-STELLENBILD` | Q6 Aufbau des Vordrucks, Q10 Anlage 1 | Die taktische Darstellung gruppiert die Wege je Stelle, wie beide Muster-Kommunikationspläne sie in Kästen setzen. | Die taktische Ansicht zeigt je Stelle einen Block mit allen ihren Wegen |
| `FMP-FUNKART` | Q8 Rufgruppenbildung Folie 3 | Analogfunk wird über Kanäle geführt, Digitalfunk über Rufgruppen. Ein Weg trägt nur die Felder seiner Technik. | Für `ANALOG` sind Band, Kanal, Bandlage und Verkehrsform anzugeben und eine Rufgruppe unmöglich; für `DIGITAL` umgekehrt. Geprüft wird die **Anwesenheit** der Angabe, nicht ihr Wert (O12) |
| `FMP-DIGITAL-BETRIEBSART` | Q8 Grundlagen Folie 3 | Ein Digitalfunkweg nennt TMO (Netzbetrieb) oder DMO (Direktbetrieb). | Betriebsart ist Pflicht, sobald `funkart = DIGITAL` |
| `FMP-DIGITAL-KEINE-GERAETEKENNUNG` | Q12 Kap. 2.4, Q11 Kap. 2.2.1.3 | Verbindlich ist ausschließlich das gesprochene Wort; die OPTA erlaubt keine sichere Verifizierung der Identität. Die Teilnehmerverwaltung liegt bei der TTB-THW. | Es gibt kein Feld für OPTA oder ISSI; die Ausfüllhilfe der Bemerkung sagt, dass Gerätekennungen auch dort nicht hineingehören |
| `FMP-DIGITAL-KEINE-TELEFONIE` | Q12 Kap. 2.4.3 | Die Überleitung des Digitalfunks in das öffentliche Telefonnetz ist gesperrt. | Die Ausfüllhilfe des Digitalfunkwegs sagt, dass er keine Telefonverbindung trägt |
| `FMP-DIGITAL-REPEATER-ANTRAG` | Q12 Kap. 2.3.3, 2.3.4 | Die Schaltung eines Repeaters oder Gateways ist bei der TTB-THW zu beantragen und nur stationär zulässig. | Die Ausfüllhilfe des DMO-Wegs nennt Antragspflicht und stationären Betrieb |
| `FMP-DIGITAL-WIRKBEREICH` | Q12 Kap. 2.3.1 | Eine Rufgruppe ist nur innerhalb ihrer Gruppenrufzone verwendbar. | Die Ausfüllhilfe zu `rufgruppe` nennt den Wirkbereich und verweist auf den gültigen Rufgruppenplan |
| `FMP-DIGITAL-GRUPPENRUF` | Q12 Kap. 2.4.1, 2.4.2 | Der Gruppenruf ist das Standardverfahren; der Einzelruf ist nur im Wechselverkehr freigeschaltet und auf das taktisch Notwendige zu beschränken. | Der Digitalfunkweg fragt keine Verkehrsform ab; die Ausfüllhilfe nennt die drei zulässigen Fälle des Einzelrufs |
| `FMP-KOPFLEISTE` | Q6 Allgemeines | Der Kopf trägt herausgebende Dienststelle mit Funktion des Verfassers, Art und Verwendungsbereich, Gültigkeits- und Verschlusssachenvermerk sowie F.d.R. mit Dienststellung. | Alle sieben Angaben sind erfasst und werden im Kopf angezeigt und gedruckt |
| `FMP-BETRIEBSLEITUNG` | Q4 Kap. 6.1.1 | In den Einsatzunterlagen sind Betriebsleitungen/-aufsicht anzugeben. | Betriebsleitung ist Pflichtangabe des Kopfes — bereits erfüllt |
| `FMP-EINGANGSWEG` | Q10 Nr. 2.8, Q4 Kap. 6.6 | Der IuK-Einsatz ist zu dokumentieren — in beide Richtungen. Auch die eingehende Nachricht kann den Weg nennen, über den sie kam. | Eine eingehende Nachricht trägt den Wegbezug wie eine ausgehende; der Wegbezug ist nicht mehr auf `04_richtung = 'A'` verriegelt. Der Weg ist **freiwillig**, das Mittel bleibt Pflicht |
| `FMP-EINGANGSWEG-BEMERKUNG` | `SPEC.md` `LW-EINGANG-STATIONEN` | Der Fernmelder stellt fest, der LdF prüft. Was der Fernmelder festgestellt hat, kann der Prüfende nicht umschreiben. | Die Bemerkung des Fernmelders zum Eingangsweg steht nicht in den schreibbaren Feldern von `LdF-Eingang`; ein abweichend geliefertes Wert wird verworfen. Der LdF behält sein eigenes Begründungsfeld |
| `FMP-GEGENSTELLE-AUSWAHL` | Q10 Nr. 2.6 | Wählt der Fernmelder in Feld 6 eine Gegenstelle des Plans, folgt der Absender in Feld 15 daraus. | Die Auswahl wird als Verweis festgehalten, nicht als Text; Feld 15 ist beim LdF mit dem Namen genau dieser Gegenstelle vorbelegt und als aus dem Plan stammend gekennzeichnet. Ohne Auswahl bleibt Feld 15 leer |
| `FMP-WEG-AUSSERHALB-VORDRUCK` | Q1 Feld 1, `SPEC.md` W3 und `UX-PAPIERBILD` | Der Vordruck kennt kein Feld für den Fernmeldeweg. Das Mittel wird im Vordruck erfasst, der Weg daneben. | Die Wegwahl steht außerhalb der Vordruckfelder und wird in einer `estab_`-Spalte geführt; der Ausdruck zeigt kein Wegfeld. Widerspricht das Medium des Wegs dem Feld 1, wird zurückgewiesen |
| `FMP-EINGANGSWEG-ROLLEN` | Q4 Kap. 4.3.1.12, `SPEC.md` `LW-EINGANG-STATIONEN` | Der Fernmelder nimmt auf und wählt den Weg; der Leiter des Fernmeldebetriebs prüft ihn. | `FM-Eingang` gibt die Wegwahl frei, `LdF-Eingang` weist ohne Bestätigung des Wegs zurück; die Berichtigung durch den LdF hält beide Werte fest |
| `FMP-EINGANGSWEG-AUSSERPLAN` | Q10 Nr. 2.6 | Abweichungen vom Kommunikationsplan sind zu vermeiden — und dafür zuerst zu erkennen. | Ein Eingang über einen Weg außerhalb des Plans ist erfassbar und erscheint in der Plantafel als Abweichung, statt zurückgewiesen zu werden |
| `FMP-WEG-IDENTITAET` | Q10 Nr. 2.6, Q4 Kap. 6.6 | Eine Betriebsunterlage wird fortgeschrieben, nicht ersetzt. Ein Weg behält über alle Versionen eines Einsatzes dieselbe Kennung. | Jeder Weg trägt eine `weg_id`, die bei der Versionskopie unverändert bleibt; die Anwendung kann die Fassungen eines Wegs über die Versionen benennen |
| `FMP-RUECKFALLEBENE` | Q10 Nr. 3, Nr. 2.7, Anlage 20 | Ein Kommunikationsplan ist erforderlichenfalls unter Berücksichtigung einer Rückfallebene zu erstellen. Der Ersatz ist einem bestimmten Weg zugeordnet. | Ein Weg kann genau einen anderen Weg desselben Plans als seinen Hauptweg benennen; Selbstbezug und Ringe werden zurückgewiesen, und der Verweis überlebt den Versionswechsel unverändert |
| `FMP-VERMERK-EINFACH` | keine Quelle für zwei Felder | Ein Weg trägt **ein** Bemerkungsfeld. | Neue und geänderte Einträge schreiben nur `bemerkungen`; die Versionskopie führt Altbestand zusammen |
| `FMP-GEGENSTELLE-AM-WEG` | Q4 Kap. 6.1.2, Q6 | Eine Gegenstelle steht immer an einem Weg und erbt dessen Medium. Es gibt keine Gegenstelle ohne Weg. | Die Gegenstelle hängt am Eintrag, nicht am Plan; sie trägt kein eigenes Medium |
| `FMP-GEGENSTELLE-KEIN-ERSATZ` | Q1 Felder 6, 11, 15 | Der Plan liefert Vorschläge für die Gegenstellenfelder des Vordrucks, ersetzt sie aber nicht und schreibt sie nicht fest. | Freie Eingabe bleibt in Feld 6, 11 und 15 möglich; eine im Plan fehlende Gegenstelle führt zu keiner Zurückweisung |
| `FMP-GEGENSTELLE-FELDBEZUG` | Q1 Felder 6 und 11 | Welches Vordruckfeld eine Erreichbarkeit füllt, entscheidet das Medium des Wegs. | Über einen Funkweg füllt die Erreichbarkeit Feld 6, über einen Fernsprech- oder Faxweg Feld 11 und der Name Feld 6 |
| `FMP-BETRIEBSUNTERLAGE-AKTUELL` | Q10 Nr. 2.6, Q4 Kap. 6.6 | „Abweichungen von den im Kommunikationsplan festgelegten IuK-Verbindungen sind während des Einsatzes zu vermeiden." Die Betriebsunterlage geht der Erinnerung vor. | Ein Vorschlag aus dem Plan stammt ausschließlich aus der aktiven, zeitlich gültigen Version und steht über jedem Vorschlag aus der Historie |
| `FMP-VORSCHLAG-QUELLENSCHRANKE` | `SPEC.md` Leserechte, `TKM-FERNMELDEPLAN` | Der Plan ist für die gesamte Besetzung einsehbar; die Nachrichtenhistorie ist es nicht. Wer keine Historie lesen darf, bekommt daraus auch keinen Vorschlag. | Die Vorschlagspolitik nennt je Rolle und Feld die zulässigen Quellen; für den Stab an Feld 10 ist das ausschließlich der Plan |

### 13.2 Bedienregeln (P1)

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

## 14. Datenmodell (Soll)

### 14.1 `nv_fernmeldeplaene` — Ergänzungen

| Spalte | Typ | Pflicht | Herkunft |
| --- | --- | --- | --- |
| `verfasser_funktion` | `VARCHAR(64)` | ja | Q6 Kopfleiste links |
| `vs_vermerk` | `ENUM('OFFEN','NfD')` | ja, Vorbelegung `NfD` | Q6/Q7 Kopfleiste rechts |
| `freigabe_dienststellung` | `VARCHAR(128)` | ja bei Freigabe | Q6 F.d.R. |

### 14.2 `nv_fernmeldeplan_eintraege` — Soll

| Spalte | Typ | Gilt für | Änderung |
| --- | --- | --- | --- |
| `betriebsstelle` | `VARCHAR(255)` | alle | nur Beschriftung |
| `stellenart` | `ENUM('EIGEN','UEBER','UNTER','NEBEN')` | alle | **neu** |
| `erreichbarkeit` | `VARCHAR(255)` | alle | **umbenannt** aus `rufname`, verlängert |
| `medium` | `ENUM('Fe','Fu','Me','FAX','FS','@')` | alle | unverändert |
| `funkart` | `ENUM('ANALOG','DIGITAL')` | `Fu` | **neu** |
| `band` | `ENUM('2m','4m')` | `Fu`/analog | **neu** |
| `kanal` | `VARCHAR(64)` | `Fu`/analog | Bedeutung verengt |
| `bandlage` | `VARCHAR(64)` | `Fu`/analog | unverändert, **Freitext** — Geltung auf Analogfunk verengt |
| `verkehrsform` | `VARCHAR(128)` | `Fu`/analog | unverändert, **Freitext** — **nicht mehr für alle Medien** |
| `relaisstelle` | `VARCHAR(64)` | `Fu`/analog | **neu**, freiwillig |
| `betriebsart` | `ENUM('TMO','DMO')` | `Fu`/digital | **neu** |
| `rufgruppe` | `VARCHAR(64)` | `Fu`/digital | **neu** |
| `anschlussart` | `ENUM('AMT','NST','MOBIL','SONDER')` | `Fe`, `FAX` | **neu**, freiwillig |
| `datenart` | `ENUM('MAIL','MESSENGER','FACHANW','INTERNET')` | `@` | **neu** |
| `rueckfallebene_fuer_weg` | `BIGINT UNSIGNED NULL` | alle | **neu** — `weg_id` des Wegs, den dieser ersetzt |
| `sortierung` | `INT UNSIGNED` | alle | Bedeutung verengt: **nur noch Anzeigereihenfolge**, keine Identität mehr |
| `bemerkungen` | `TEXT` | alle | bleibt, einziges Vermerkfeld |
| `besondere_vermerke` | `TEXT NULL` | alle | **nur noch lesend** |

### 14.3 `nv_nachrichten` — Ergänzungen außerhalb des Vordrucks

Drei Spalten, alle mit `estab_`-Präfix, weil sie keine Vordruckfelder sind
(Abschnitt 10.4). `estab_fernmeldeplan_eintrag_id` besteht bereits und wird
nur entriegelt.

| Spalte | Typ | Pflicht | Zweck |
| --- | --- | --- | --- |
| `estab_fernmeldeplan_eintrag_id` | `BIGINT UNSIGNED NULL` | freiwillig | der Weg — **bestehend**, künftig auch im Eingang |
| `estab_eingangsweg_bemerkung` | `VARCHAR(2000) NULL` | freiwillig | **neu** — Bemerkung des Fernmelders zum Eingangsweg; für den LdF nur lesbar |
| `estab_gegenstelle_id` | `BIGINT UNSIGNED NULL` | freiwillig | **neu** — welche Gegenstelle des Plans A/W in Feld 6 ausgewählt hat; Grundlage der Vorbelegung von Feld 15 |

`estab_gegenstelle_id` verweist auf `nv_fernmeldeplan_gegenstellen` und damit
auf die Gegenstelle **einer bestimmten Planversion** — dieselbe Lesart wie beim
Weg (Abschnitt 9.4): Der Nachweis hält fest, was zum Zeitpunkt der Aufnahme im
Plan stand, nicht was heute dort steht.

### 14.4 `nv_fernmeldewege` und `nv_fernmeldeweg_zuordnung` — neu

Die Identität des Wegs, getrennt von seinem Zustand in einer Planversion
(Abschnitt 9.3). Zeilen dieser Tabelle werden angelegt und **nie geändert oder
gelöscht** — eine Kennung, die verschwindet, ist keine.

| Spalte | Typ | Pflicht | Zweck |
| --- | --- | --- | --- |
| `weg_id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Schlüssel | dauerhafte Kennung für die Maschine |
| `einsatz_id` | `BIGINT UNSIGNED` | ja, Fremdschlüssel | Bindung an den Einsatz |
| `weg_nummer` | `INT UNSIGNED` | ja, eindeutig je Einsatz | laufende Nummer für den Bediener: „Weg 3" |
| `angelegt_am` | `DATETIME(6)` | ja | Nachweis |
| `angelegt_von` | `VARCHAR(6)` | ja, Fremdschlüssel auf `nv_benutzer` | Nachweis |

`weg_nummer` wird als `MAX + 1` **je Einsatz** vergeben — nicht je Planversion.
Damit ist die Wiederverwendung ausgeschlossen, an der die `sortierung`
scheitert (Abschnitt 9.6).

**Die Zuordnung** verbindet Kennung und Eintrag, ohne den geschützten Bestand
zu ändern (Abschnitt 9.3):

| Spalte | Typ | Pflicht | Zweck |
| --- | --- | --- | --- |
| `fernmeldeplan_eintrag_id` | `BIGINT UNSIGNED` | Schlüssel, Fremdschlüssel | der Eintrag einer Planversion |
| `fernmeldeplan_id` | `BIGINT UNSIGNED` | ja | mitgeführt, damit der zusammengesetzte Schlüssel greift |
| `weg_id` | `BIGINT UNSIGNED` | ja, Fremdschlüssel | die dauerhafte Kennung |

Eindeutig ist `(fernmeldeplan_id, weg_id)` — ein Weg steht in einer Version
höchstens einmal. Eine Zuordnung wird **nie umgehängt**; ein Auslöser weist
jede Änderung zurück. Sie verschwindet nur mit ihrem Eintrag, wenn ein Entwurf
einen Weg wieder streicht.

**Eine Identität ohne Verwendung wird nicht aufgeräumt.** Ein Weg, der im
Entwurf angelegt und wieder gestrichen wurde, behält seine Nummer. Das ist
kein Müll, sondern die Auskunft, dass dieser Weg einmal erwogen wurde — und
die Voraussetzung dafür, ihn später mit seiner Geschichte wieder aufzunehmen.

### 14.5 `nv_fernmeldeplan_gegenstellen` — neu

Kindtabelle des Wegs. Sie erbt Medium und Technik vom Weg und trägt nur, was
die Gegenstelle unterscheidet.

| Spalte | Typ | Pflicht | Speist |
| --- | --- | --- | --- |
| `gegenstelle_id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Schlüssel | — |
| `fernmeldeplan_eintrag_id` | `BIGINT UNSIGNED` | ja, Fremdschlüssel | — |
| `sortierung` | `INT UNSIGNED` | ja, eindeutig je Eintrag | Reihenfolge in der Anzeige |
| `name` | `VARCHAR(255)` | ja | Feld 15 Absender (Eingang), Feld 10 Anschrift (Ausgang) |
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

### 14.6 Migration

Der Grundsatz ist **additiv**. Keine Migration schreibt eine Zeile eines
aktiven oder ersetzten Plans um; die Unveränderlichkeitsauslöser aus
Migration 94 und 117 bleiben unberührt.

1. Neue Spalten anlegen, alle `NULL`-fähig.
2. `rufname` → `erreichbarkeit` umbenennen (`ALTER TABLE … CHANGE`; kein
   Wertewechsel, damit kein Auslöser feuert).
2a. `nv_fernmeldewege` und `nv_fernmeldeweg_zuordnung` anlegen und **jede
   bestehende Eintragszeile mit einer eigenen, frischen Identität versehen** —
   ausschließlich durch `INSERT` in die beiden neuen Tabellen. Der geschützte
   Bestand wird gelesen, nicht geschrieben. Kein Versuch, gleiche Wege über
   Versionen hinweg zusammenzuführen — siehe unten.
2b. `rueckfallebene_fuer_weg` am Eintrag anlegen, dazu der zusammengesetzte
   Fremdschlüssel auf die Zuordnung und die Prüfbedingung gegen den
   Selbstbezug. Bestandszeilen bleiben `NULL`; das Anlegen einer Spalte ist
   DDL und feuert keinen Zeilenauslöser. Die Versionskopie in
   [dv_operations.php:4829](app/dv_operations.php:4829) schreibt die Zuordnung
   für die neuen Zeilen mit und nimmt `rueckfallebene_fuer_weg` unverändert
   mit.
3. Bestehende `Fu`-Einträge: `funkart` bleibt `NULL` = **unbestimmt**. Sie
   werden in der Oberfläche als Altbestand gekennzeichnet und beim nächsten
   Bearbeiten entschieden. Kein Raten.
4. `bandlage` und `verkehrsform` bleiben `VARCHAR` und ungeprüft (O12). Kein
   Schema-Eingriff, kein Altbestand, der außerhalb einer Werteliste liegen
   könnte — es gibt keine.
5. `nv_komplan` (F9) wird in dieser Überarbeitung **nicht** angefasst. Ihr
   Abbau ist entschieden, läuft aber als eigener Vorgang — Abschnitt 15.
5a. **Warum Altbestand keine gemeinsamen Identitäten bekommt.** Es liegt nahe,
   Wege gleicher `sortierung` über die Versionen eines Einsatzes zu einer
   Identität zu verketten — die Kopie hat die Nummer ja erhalten. Das wäre
   *fast* richtig und deshalb gefährlich: Wer in einem Entwurf den letzten Weg
   gelöscht und einen neuen angelegt hat, bekam dieselbe Nummer für einen
   **anderen** Weg (`MAX + 1` je Planversion). Die Verkettung verschmölze dann
   zwei verschiedene Wege zu einem, unbemerkt und unumkehrbar. Eine Identität
   zu erfinden, die nie erfasst wurde, ist schlimmer als keine zu haben. Der
   Versionsvergleich aus Abschnitt 9.4 beginnt deshalb mit dem nächsten Plan,
   nicht rückwirkend.
6. `nv_fernmeldeplan_gegenstellen` wird **leer** angelegt. Es findet keine
   Ableitung aus `betriebsstelle`/`rufname` bestehender Pläne statt: Diese
   Werte sind die eigene Erreichbarkeit, nicht die der Gegenstelle. Sie
   maschinell umzudeuten hieße, den Fehler aus F10 in Daten zu gießen.
   Bis der S6 die erste Version mit Gegenstellen freigibt, liefert der
   Plan-Zweig der Vorschläge nichts — und die Historie trägt allein, wie
   bisher.

---

## 15. Abbau der Alttabelle `nv_komplan` (Entscheidung O4)

### 15.1 Nachweis, dass sie ungenutzt ist

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

### 15.2 Abbau

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
Ansicht weiter (Abschnitt 12), nicht in der Tabelle.

---

## 16. Erzeugte Kommunikationsskizze (Entscheidung O5)

### 16.1 Umfang

Aus den Plandaten wird die **taktische** Kommunikationsskizze erzeugt: Stellen
als Kästen mit taktischem Zeichen, Verbindungen als Linien je Mittel,
beschriftet mit der Erreichbarkeit. Kopfleiste nach Abschnitt 11, Querformat,
mindestens DIN A4 — so verlangt es Q6 („Bewährt hat sich die Darstellung im
Querformat").

Sie ist **erzeugt, nicht gezeichnet.** Damit gilt für sie, was für den Plan
gilt: Sie trägt dessen Stand, dessen Version und dessen F.d.R.; es gibt keine
zweite Wahrheit, die auseinanderlaufen könnte.

**Nicht** erzeugt wird die **betriebliche** Skizze. Q6 verlangt dort
„sämtliche Einzelheiten technischer und betrieblicher Art" mit Schaltzeichen —
das ist eine Zeichnung, kein Bericht (Lücke L3).

### 16.2 Anordnung

> **Berichtigung vom 01.09.2026.** Hier stand ein Kreuz mit `EIGEN` in der
> Mitte und den anderen Stellen darum herum. Der Betreiber hat die Vorlage
> Fb Fü 77 nachgereicht; sie ordnet anders, und sie hat recht.

Die Anordnung folgt dem Vordruck, nicht einem Grafikalgorithmus:

```
   übergeordnete Stellen    ┌──────────────────┐    nachgeordnete Stellen
           ◄────────────────┤  FÜHRUNGSSTELLE  ├────────────────►
                            │   Funkrufname    │
                            │  ── unsere ──    │
                            │      Mittel      │
                            └──────────────────┘
```

**In der Mitte stehen wir.** Der Name der eigenen Führungsstelle, darunter
ihr Funkrufname, darunter jede Zeile des Plans: unser Mittel links, unsere
Erreichbarkeit darunter rechts. Das ist der Gegenstand der Fernmeldeplanung —
die eigenen Kommunikationsmittel und Erreichbarkeiten.

**Außen stehen die Gegenstellen.** Links, wen wir nach oben erreichen; rechts,
wen wir nach unten erreichen. Eine Verbindung beginnt an der **Zeile ihres
Mittels**, nicht an der Kastenmitte — so ist ablesbar, worüber eine Stelle
erreicht wird, ohne die Linie bis zum Ende zu verfolgen.

Der Funkrufname der Mitte ist die Erreichbarkeit des ersten **Funk**wegs.
Gibt es keinen, bleibt die Zeile leer; eine Telefonnummer als Funkrufname
auszugeben wäre falsch und sähe richtig aus.

Reicht der Platz einer Spalte nicht, wird **nicht** kleiner gesetzt, sondern
abgeschnitten und gesagt, wie viele fehlen. Eine unlesbare Skizze ist
schlechter als eine unvollständige, die ihre Unvollständigkeit nennt.

### 16.3 Linienart je Mittel — trägt auch ohne Zeichen

Die Skizze ist **ohne** die taktischen Zeichen baubar und wird mit ihnen
besser. Sie hängt nicht an ihnen:

| Mittel | Linie |
| --- | --- |
| `Fe`, `FAX`, `@` (leitergebunden) | durchgezogen |
| `Fu` analog | gestrichelt |
| `Fu` digital | gestrichelt, doppelt |
| `Me` Melder | gepunktet |
| Rückfallebene (jedes Mittel) | zusätzlich dünner und heller — Q10 Anlage 20: „unter Inkaufnahme einer Leistungsbeschränkung" |

### 16.4 Zeichensatz (Q9)

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
| `Fu` analog | Linienart nach Abschnitt 16.3 plus Textmarke „Kanal 2 m" / „Kanal 4 m" — wie auf dem Papier (Q6) |
| Relaisbetrieb analog | `Fernmeldewesen/RS2_2m-2m`, `/RS2_2m-4m`, `/RS2_4m-4m` |
| DMO-Repeater, DMO-TMO-Gateway | dieselben `RS2_*`-Zeichen: Q12 Kap. 2.3.3 und 2.3.4 stellen Repeater und Gateway ausdrücklich neben Rs1-Relais und Rs2-Überleiteinrichtung |
| `@` Datenübertragung | `Fernmeldewesen/Datenverbindung` |
| `Me` Melder | Linienart nach Abschnitt 16.3 |

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

### 16.5 Was die Übernahme kostet

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

**Entschieden (O11): aus den Vorlagen bauen.** Die Namensnennung kostet nichts — die
Anwendung führt ohnehin `THIRD_PARTY_NOTICES.md` —, und eine ausdrückliche
Lizenz ist tragfähiger als ein Satz im README über den Stand eines Archivs.
Gebaut wird **einmal**, die benötigten SVG werden als Bestand aufgenommen;
`j2cli` wird nicht Teil der Laufzeit. Die bewusst leere Laufzeit-Abhängigkeits-
liste in `requirements.txt` bleibt leer.

Aufzunehmen in `THIRD_PARTY_NOTICES.md`: Sammlung, Urheber, Fundstelle,
CC-BY-4.0 — und, falls Zeichen mit Text übernommen werden, „Roboto Slab Bold"
unter Apache-2.0.

---

## 17. Lücken

| Nr. | Lücke | Wirkung |
| --- | --- | --- |
| **L1** | **geschlossen.** NBHB THW (Q12) und THW-DV 1-820 (Q11) liegen vor; alle `FMP-DIGITAL-*`-Regeln zitieren jetzt die Vorschrift statt der Ausbildungsunterlage. | Offen bleibt allein die *Ergänzende Loseblattsammlung Digitalfunk BOS* mit den Rufgruppenplänen — siehe L5. |
| **L2** | **erledigt.** PDV 800 (Q10) liegt vor. Die Wertelisten des Analogfunks führt sie nicht — sie verweist auf die eingestufte PDV 810.2. | **Ohne Wirkung** (O12): Bandlage und Verkehrsform sind Freitext und werden nicht geprüft. Eine Quelle wird dafür nicht gebraucht. |
| **L3** | Q7 kennt zwei Skizzen: eine taktische und eine betriebliche mit sämtlichen Schaltzeichen. | Die **taktische** wird erzeugt (Abschnitt 16). Die **betriebliche** bleibt draußen — sie ist eine Zeichnung, kein Bericht. |
| **L4** | Kanal- und Frequenzverzeichnisse des Analogfunks sind landesrechtlich geregelt. | `kanal` bleibt Freitext; keine Prüfung gegen ein Verzeichnis. |
| **L5** | Rufgruppenübersichten TMO/DMO der Landesverbände stehen nach Q11 Kap. 2.2.1.4 in der Loseblattsammlung Digitalfunk BOS und sind nicht Teil der Anwendung. | `rufgruppe` bleibt Freitext; die Ausfüllhilfe verweist auf die Loseblattsammlung. |
| **L6** | Für den **Analogfunk** führt Q9 keine `Bedingung_*`-Zeichen (2 m, 4 m). | Kein Mangel: Q6 schreibt die Bandangabe als Text. Die Skizze setzt sie ebenso. |
| **L7** | **hingenommen** (Betreiberentscheidung). Die THW-Funkrufnamenregelung (THW-FuRnR), von Q11 Kap. 2.2.1.2 als zuständige Regelung benannt, liegt nicht vor. | Ohne Wirkung: Funkrufnamen werden ausdrücklich **nicht** geprüft. Der Plan trägt sie als Text. Keine Beschaffung nötig. |

---

## 18. Abnahme

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

- [ ] Jede Regel aus Abschnitt 13 steht im jeweiligen Register und hat einen Test.
- [ ] `tests/php/schema_migration_contract.php` kennt die neuen Spalten.
- [ ] Ein Digitalfunkweg lässt sich ohne Kanal und ohne Bandlage speichern, ein Analogfunkweg nicht ohne sie.
- [ ] Bandlage und Verkehrsform nehmen jeden Text an; kein Wert wird zurückgewiesen.
- [ ] Ein bestehender, aktiver Plan ist nach der Migration unverändert lesbar und trägt dieselbe Prüfsumme im Ausleitungsvergleich.
- [ ] Die taktische Ansicht zeigt einen Plan mit zwanzig Wegen als Stellenblöcke ohne Querlauf bei 916 Bildpunkten (`docs/GESTALTUNG.md`).
- [ ] Die Bedienprüfung (`tools/bedienpruefung/`) belegt mit Bildschirmabzug, dass Formular und Tabelle je Medium nur ihre Felder zeigen.
- [ ] Kein Vorschlag im Vordruck stammt mehr aus `betriebsstelle` oder `erreichbarkeit` eines Wegs (F10 ist geschlossen).
- [ ] Jeder Treffer aus dem Plan steht über jedem Treffer aus der Historie, und beide sind sichtbar (F11 ist geschlossen).
- [ ] Der Stab bekommt an Feld 10 Vorschläge aus dem Plan und **keinen einzigen** aus der Nachrichtenhistorie.
- [ ] Jede Option der Vorschlagsliste trägt eine Herkunftsangabe, auch bei `FM-Eingang`.
- [ ] Eine Gegenstelle, die im Plan fehlt, lässt sich in Feld 6, 11 und 15 frei eintragen.
- [ ] Eine eingehende Nachricht trägt nach dem Abschluss einen Wegbezug, und der `LdF-Eingang` weist ohne dessen Bestätigung zurück.
- [ ] Feld 1 wird im Vordruck eingetragen, die Wegwahl steht außerhalb; ein Widerspruch zwischen beiden wird zurückgewiesen.
- [ ] Der gedruckte Vordruck zeigt kein Feld für den Fernmeldeweg.
- [ ] Ein Eingang über einen Weg außerhalb des Plans lässt sich erfassen und erscheint in der Plantafel als Abweichung.
- [ ] Eine eingehende Nachricht lässt sich **ohne** Weg abschließen; das Mittel bleibt Pflicht.
- [ ] Der `LdF-Eingang` kann die Bemerkung des Fernmelders zum Eingangsweg nicht überschreiben; ein mitgeschickter abweichender Wert wird verworfen.
- [ ] Wählt A/W in Feld 6 eine Gegenstelle des Plans, ist Feld 15 beim LdF vorbelegt und gekennzeichnet; tippt A/W frei, ist es leer.
- [ ] Ein eindeutiger, exakter Plantreffer steht vorbelegt im Feld, sichtbar gekennzeichnet; bei zwei Plantreffern bleibt das Feld leer.
- [ ] Kein Wert aus der Historie belegt jemals ein Feld vor.
- [ ] Der Nachweis unterscheidet, ob ein Wert vorbelegt übernommen oder selbst gewählt wurde.
- [ ] Weder Formular noch Datenbank kennen ein Feld für OPTA oder ISSI.
- [ ] Die Skizze eines Plans mit vier Stellen und acht Wegen ist im Querformat ohne Überlappung lesbar und trägt Kopfleiste, Version und F.d.R. des Plans.
- [ ] Die Skizze entsteht auch ohne taktische Zeichen (Linienart je Mittel, Abschnitt 16.3).
- [ ] Die taktischen Zeichen behalten ihre Farben; im dunklen Erscheinungsbild stehen sie auf heller Fläche.
- [ ] `THIRD_PARTY_NOTICES.md` führt Q9 mit Urheber, Fundstelle und Lizenz CC-BY-4.0.
- [ ] Ein Weg lässt sich nicht als seine eigene Rückfallebene speichern, und ein Ring wird zurückgewiesen.
- [ ] Nach einem Versionswechsel zeigt jede Rückfallebene auf denselben Weg wie zuvor — geprüft an einem Plan mit einer Kette aus drei Wegen.
- [ ] Das Löschen eines Wegs, auf den zurückgefallen wird, wird zurückgewiesen und nennt die abhängigen Wege.
- [ ] Der Abbau von `nv_komplan` läuft als **eigener** Vorgang; seine Migration bricht bei nicht leerer Tabelle ab, statt Zeilen zu verwerfen.

---

## 19. Entscheidungen

### 19.1 Entschieden durch den Betreiber

| Nr. | Frage | Entscheidung | Steht in |
| --- | --- | --- | --- |
| O1 | Fernschreiber (`FS`) aus der Auswahlliste des Plans nehmen? | **ja** — der Vordruck behält sein Kästchen | Abschnitt 4.3 |
| O2 | OPTA und ISSI erfassen? | **nein**. Q12 Kap. 2.4 bestätigt es normativ: Verbindlich ist das gesprochene Wort, die OPTA erlaubt keine sichere Verifizierung | Abschnitt 8.2, Regel `FMP-DIGITAL-KEINE-GERAETEKENNUNG` |
| O3 | Verkehrskreis als eigenes Feld? | **nein** — nur Ausfüllhilfe der Bemerkung | Abschnitt 6.1 |
| O4 | Alttabelle `nv_komplan`? | **entfernen**, da nachweislich ungenutzt — als eigener Vorgang | Abschnitt 15 |
| O5 | Kommunikationsskizze erzeugen? | **ja**, die taktische, mit den Zeichen aus Q9 | Abschnitt 16 |
| O6 | Planvorschläge für den Stab an Feld 10 (Anschrift)? | **ja** — ausschließlich aus dem Plan, nie aus der Historie | Abschnitt 5.5 (V8), Regel `FMP-VORSCHLAG-QUELLENSCHRANKE` |
| O7 | Plan oder Historie zuerst? | **Plan zuerst**, beide sichtbar, beide gekennzeichnet. Q10 Nr. 2.6 bestätigt es normativ | Abschnitt 5.4 (F11), 5.5 (V2) |
| O8 | Feld für die Erreichbarkeitszeit einer Gegenstelle? | **nein** — nur Bemerkung | Abschnitt 5.3 |
| O9 | Rückfallebene? | **ja** — Schalter plus Auswahl, für welche Verbindung. Daraus wurde Beschluss B6 | Abschnitt 9 |
| O10 | Funkrufnamen gegen die THW-FuRnR prüfen? | **nein** — Freitext, keine Prüfung. Lücke L7 ist damit hingenommen, nicht offen | Abschnitt 17 |
| O11 | Bezugsweg der taktischen Zeichen? | **bestätigt**: aus den Vorlagen bauen, CC-BY-4.0 mit Namensnennung | Abschnitt 16.5 |
| O12 | Bandlage und Verkehrsform gegen eine Werteliste prüfen? | **nein** — Freitext mit Ausfüllhilfe. Damit entfällt der Bedarf an der PDV 810.2; Lücke L2 ist gegenstandslos | Abschnitt 8.2 |
| O13 | Woran hängt der Verweis der Rückfallebene? | **an einer dauerhaften Kennung des Wegs**, nicht an der `sortierung`. Daraus wurde die Tabelle `nv_fernmeldewege` | Abschnitt 9.3, 9.6 |
| O14 | Soll auch der **Eingang** einen Fernmeldeweg tragen? | **ja** — vom Fernmelder gewählt, vom LdF geprüft. Daraus wurde Beschluss B7 | Abschnitt 10 |
| O15 | Soll ein Plantreffer das Feld **vorbelegen**? | **ja**, bei eindeutigem exaktem Treffer, mit sichtbarem Hinweis auf die Herkunft. Ohne Plantreffer bleibt das Feld leer und die Historie steht nur zur Auswahl | Abschnitt 5.6 |
| O16 | Wo wird der Weg erfasst? | **außerhalb des Vordrucks**; im Vordruck steht das Mittel. Die Anwendung leitet Feld 1 nicht ab, sondern prüft es gegen den Weg | Abschnitt 10.4, Regel `FMP-WEG-AUSSERHALB-VORDRUCK` |
| O17 | Ist der Eingangsweg Pflicht? | **nein**, freiwillig. Das Mittel bleibt Pflicht | Abschnitt 10.3 |
| O18 | Darf der LdF die Bemerkung des Fernmelders ändern? | **nein** — sie ist für ihn nur lesbar; er behält sein eigenes Begründungsfeld | Abschnitt 10.8 |
| O20 | Wie bekommt der Bestand seine Wegkennungen, wenn der Auslöser jede Änderung an freigegebenen Plänen sperrt? | **Zuordnungstabelle statt Spalte.** Der geschützte Bestand wird nur gelesen; die Kennungen entstehen durch `INSERT`. Kein Auslöser wird angefasst | Abschnitt 9.3, 14.4 |
| O19 | Wie kommt Feld 15 zu seinem Wert? | Aus der **in Feld 6 ausgewählten** Gegenstelle des Plans — als Verweis festgehalten, nicht als Textvergleich | Abschnitt 5.6 |

**Damit ist jede Frage dieser Überarbeitung entschieden.**

### 19.2 Drei Folgen, die festgehalten gehören

**Aus O7.** Ein *ähnlicher* Treffer aus dem Plan steht über einem *exakten*
aus der Historie. Das ist gewollt: Q10 Nummer 2.6 verlangt, Abweichungen vom
Kommunikationsplan zu vermeiden. Die Kennzeichnung aus V3 ist das Gegengewicht
— sie macht sichtbar, dass der Plan oben steht, *weil* er der Plan ist.

**Aus O6.** Die Vorschlagspolitik entscheidet über zwei Achsen: Feld und
Quelle. Ohne die zweite wäre die Öffnung für den Stab ein Rückbau des Schutzes
vor Historienlecks; mit ihr ist sie keiner.

**Aus O13.** Ein früherer Entwurf ließ den Verweis auf die `sortierung`
zeigen. Sie ist im Bestand stabil, aber nur, weil niemand umsortieren kann —
eine Stabilität, die die erste Umsortierfunktion zerstört hätte, und eine
Nummer, die bei `MAX + 1` je Planversion wiederverwendet wird. Der Weg bekommt
deshalb eine eigene Kennung (Abschnitt 9.3). Das kostet eine schmale Tabelle
und liefert den Versionsvergleich gratis mit: „seit wann besteht dieser Weg,
was hat sich an ihm geändert" ist damit erstmals beantwortbar (Abschnitt 9.4).

### 19.3 Was an Vorschriften offenbleibt

**Nichts mit inhaltlicher Wirkung.**

| Fehlt | Wirkung |
| --- | --- |
| PDV 810.2 VS-NfD „Sprech- und Datenfunkverkehr" (L2) | keine — durch O12 gegenstandslos: Bandlage und Verkehrsform sind ungeprüfter Freitext |
| Ergänzende Loseblattsammlung Digitalfunk BOS (L5) | keine — `rufgruppe` ist bewusst Freitext |
| THW-Funkrufnamenregelung (L7) | keine — durch O10 hingenommen |

L1 ist mit Q11 und Q12 geschlossen, L3 und L6 durch die Entscheidung zur
Skizze erledigt, L4 ist eine bewusste Grenze.

Damit ist keine Beschaffung mehr nötig. Die drei verbliebenen Lücken sind
Entscheidungen, keine Hindernisse: An jeder Stelle, an der eine fehlende
Vorschrift eine Werteliste hätte liefern müssen, hat der Betreiber sich
bewusst für Freitext entschieden — und trägt die fachliche Verantwortung
dafür selbst, statt sie an eine Prüfung im Programm abzugeben.

### 19.4 Nächster Schritt

Die Spec ist entscheidungsreif. Als Nächstes gehören dazu — nach dem Muster
der Rückmeldungen — ein `tasks/fernmeldeplanung-plan.md` mit der Baureihenfolge
und ein `tasks/fernmeldeplanung-todo.md` mit den Einzelschritten.

Die Baureihenfolge ergibt sich bereits aus den Abhängigkeiten:

```
1  nv_fernmeldewege + weg_id          (Abschnitt 9.3)  ─┐
2  Feldtrennung analog/digital        (Abschnitt 8)     ├─ Schema
3  Gegenstellen                       (Abschnitt 5.3)   │
4  Rückfallebene                      (Abschnitt 9.5)  ─┘  hängt an 1
5  Kopfleiste                         (Abschnitt 11)
6  Zwei Ansichten                     (Abschnitt 12)      hängt an 2 und 3
7  Eingangsweg                        (Abschnitt 10)      hängt an 2
8  Vorschläge und Vorbelegung         (Abschnitt 5.5)     hängt an 3 und 7
9  Skizze                             (Abschnitt 16)      hängt an 6
10 Abbau nv_komplan                   (Abschnitt 15)      unabhängig
```

Schritt 1 steht vorn, weil vier der übrigen an ihm hängen — und weil er der
einzige ist, der eine Migration über **alle** Bestandszeilen führt.

Schritt 7 steht vor 8, weil der Eingangsweg dem A/W dieselbe Wegauswahl gibt,
die der LdF schon hat: Erst wenn beide Stationen aus dem Plan wählen, lohnt es,
Vorschläge und Vorbelegung an beiden Enden anzufassen.
