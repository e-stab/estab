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

**Sachnähe für den Aufbau des Plans liegt bei Q6/Q7.** So wie Q1 für die
Felder des Nachrichtenvordrucks gilt, gilt Q6 für die Felder des
Kommunikationsplans: Es ist das Dokument, das der Ausbildung den Plan zeigt.
Q4 Kapitel 6 bleibt Auffangnorm für alles, was der Vordruck nicht bebildert.

**Q8 ist keine Vorschrift.** Die Folien zitieren durchgehend das *Nutzungs-
und Betriebshandbuch THW für den Digitalfunk BOS* (NBHB-THW), die *THW-DV
1-820* und die *Ergänzende Loseblattsammlung Digitalfunk BOS*. Keines dieser
drei Dokumente liegt vor (→ Lücke **L1**). Jede Regel, die unten aus Q8
abgeleitet wird, trägt diesen Vorbehalt.

**Q4 Kapitel 6 nennt seinerseits eine fremde Grundlage:** „Grundlage bildet
die PDV / DV 800 (Fernmeldeeinsatz) in der jeweils gültigen Fassung." Auch
diese liegt nicht vor (→ Lücke **L2**). Betroffen sind vor allem die
Wertelisten des Analogfunks (Bandlage, Verkehrsform).

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
Stelle.** Beides ist ohne Widerspruch möglich (Abschnitt 8).

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
| `FS` Fernschreiber | **entfällt im Plan**, bleibt im Vordruck | siehe unten |
| `@` Datenübertragung | bleibt, bekommt eine Unterart | Q3 5.1: E-Mail, Messenger, Internet |

**Zum Fernschreiber.** Das Fernschreibnetz ist abgeschaltet; keine THW-Stelle
ist über Telex erreichbar. Der Plan führt, was tatsächlich betrieben wird —
ein Medium ohne Gerät ist kein Weg. Der **Vordruck** behält sein Kästchen
unverändert, weil er es druckt und weil Altnachrichten es tragen; nur die
Auswahlliste des Plans bietet es nicht mehr an. Bestehende Einträge bleiben
lesbar und werden wie andere Altangaben gekennzeichnet. Die Rücknahme dieser
Entscheidung ist eine Zeile in `ESTAB_DV_PLAN_MEDIA`.

---

## 5. Beschluss B2 — Der Plan führt die eigene Erreichbarkeit

### 5.1 Die Trennung

Der Fernmeldeplan beantwortet: **Unter welchen Wegen sind die Stellen des
eigenen Verbundes erreichbar, und über welche Wege erreichen wir andere?**

Er ist **kein Verzeichnis von Gesprächspartnern.** Die Gegenstelle einer
einzelnen Nachricht steht im Vordruck und nirgends sonst:

| Feld (Q1) | Inhalt | Datenbank |
| --- | --- | --- |
| 6 | Rufname der Gegenstelle / Spruchkopf | `05_gegenstelle` |
| 11 | Rufnummer der Gegenstelle | — |

Das ist keine Auslegung, sondern die Bauart beider Unterlagen. Q6 zeigt Kästen
mit „FüSt:", „FuRN:", „Kanal:", „Tel.:", „Fax:" — die Stellen der eigenen
Führungsorganisation mit ihren Anschlüssen. Q4 Kapitel 6.1.1 verlangt in den
Einsatzunterlagen anzugeben: „Kommunikationsebenen, TK-Verbindungen,
Betriebsleitungen/-aufsicht" — Struktur, nicht Adressbuch.

### 5.2 Was daraus folgt

**B2.1 — Die Ansicht sagt es.** Der Vorspann der Plantafel trägt einen Satz,
der die Verwechslung ausschließt, sinngemäß:

> Der Fernmeldeplan sagt, wie die Stellen des eigenen Verbundes erreichbar
> sind. Wer im Einzelfall am anderen Ende ist, steht in Feld 6 der Nachricht.

**B2.2 — Die Spalte heißt anders.** „Betriebsstellen-Klarbezeichnung" wird zu
**„Stelle"** mit der Ausfüllhilfe „Stelle des eigenen Verbundes: Führungsstelle,
Fernmeldezentrale, Meldekopf, Einheit". Der Datenbankname `betriebsstelle`
bleibt.

**B2.3 — Die Stelle bekommt eine Art.** Q4 Kapitel 6.1.2 verlangt
TK-Verbindungen „vertikal und horizontal"; Q6 setzt „FüSt"-Kästen neben
„NSt"-Kästen. Ein neues Feld `stellenart` macht sichtbar, in welche Richtung
eine Verbindung zeigt:

| Wert | Bedeutung |
| --- | --- |
| `EIGEN` | die eigene Führungsstelle |
| `UEBER` | übergeordnete Stelle (vertikal nach oben) |
| `UNTER` | nachgeordnete Stelle (vertikal nach unten) |
| `NEBEN` | benachbarte Stelle, Partnerorganisation (horizontal) |

**B2.4 — Die Wegewahl des LdF spricht so.** Die Auswahlliste bei der
Disposition fragt nicht „an wen", sondern **„über welchen Weg"**.

### 5.3 Abgrenzung

Ob ein Eintrag missbräuchlich eine fremde Stelle als Adressbucheintrag führt,
prüft die Anwendung **nicht**. Eine Stelle einer Partnerorganisation, mit der
eine Verbindung *besteht*, ist ein zulässiger Eintrag (`NEBEN`) — Q4 Kapitel
6.8 regelt die Mitbenutzung fremder Netze ausdrücklich. Die Unterscheidung ist
fachlich, nicht maschinell prüfbar; sie wird durch Benennung und Ausfüllhilfe
geführt, nicht durch Zurückweisung.

---

## 6. Beschluss B3 — Ein Bemerkungsfeld statt zwei

### 6.1 Entscheidung

`besondere_vermerke` und `bemerkungen` werden zu **einem** Feld `bemerkungen`
zusammengeführt. Beschriftung: **„Bemerkungen"**, Ausfüllhilfe: „Betriebszeiten,
Einschränkungen, Ersatzweg, Besonderheiten der Bedienung".

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
| Funk digital | Funkrufname (OPTA) |
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
| `opta` | nein | operativ-taktische Adresse, 24 Stellen |
| `issi` | nein | Teilnehmerkennung für den Einzelruf |

**Keine Verkehrsform im Digitalfunk.** Q8, Rufgruppenbildung Folie 3:
„Gruppengespräch ist Standardform der BOS-Funkkommunikation (Einzelgespräch
ist die Ausnahme)." Es gibt nichts zu wählen. Der Einzelruf ist die Ausnahme
und steht mit seiner ISSI in `issi` und in der Bemerkung; Q8 (Technische
Grundlagen, Folie 30) verlangt ausdrücklich, ihn „auf das taktisch
Notwendigste zu beschränken" — die Ausfüllhilfe sagt das.

**Repeater und Gateway** (Q8, Technische Grundlagen, Folien 42 und 48) sind
keine eigenen Wege, sondern Eigenschaften eines DMO-Weges. Sie stehen in der
Bemerkung. Die Ausfüllhilfe nennt die Anzeige- bzw. Antragspflicht gegenüber
der TTB-THW, weil der S6 sie sonst übersieht.

### 8.3 Technische Felder je Medium — Gesamtübersicht

| Medium | Erreichbar unter | Technische Felder | Verkehrsform |
| --- | --- | --- | --- |
| `Fe` Fernsprecher | Rufnummer | `anschlussart` (Amt, Nebenstelle, Mobilfunk, Sondernetz) | — |
| `Fu` analog | Funkrufname | `band`, `kanal`, `bandlage`, `relaisstelle` | ja |
| `Fu` digital | Funkrufname (OPTA) | `betriebsart`, `rufgruppe`, `opta`, `issi` | — |
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
Erreichbarkeit. Keine Bandlage, keine ISSI, keine Betriebsart. Das ist die
Antwort auf Abschnitt 3.2.

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
| `FMP-EIGENE-ERREICHBARKEIT` | Q4 Kap. 6.1.1, Q6 | Der Plan führt die Erreichbarkeit der Stellen des eigenen Verbundes, nicht die Gegenstellen einzelner Nachrichten. | Die Plantafel benennt das; die Gegenstelle ist ausschließlich Feld 6 der Nachricht |
| `FMP-STELLENART` | Q4 Kap. 6.1.2, Q6 | Zu jeder Stelle ist erkennbar, ob die Verbindung vertikal nach oben, vertikal nach unten oder horizontal führt. | Jeder Eintrag trägt eine Stellenart; die taktische Ansicht zeigt sie |
| `FMP-STELLENBILD` | Q6, Aufbau des Vordrucks | Die taktische Darstellung gruppiert die Wege je Stelle, wie der Vordruck sie in Kästen setzt. | Die taktische Ansicht zeigt je Stelle einen Block mit allen ihren Wegen |
| `FMP-FUNKART` | Q8 Rufgruppenbildung Folie 3 | Analogfunk wird über Kanäle geführt, Digitalfunk über Rufgruppen. Ein Weg trägt nur die Felder seiner Technik. | Für `ANALOG` sind Band, Kanal, Bandlage, Verkehrsform Pflicht und Rufgruppe unmöglich; für `DIGITAL` umgekehrt |
| `FMP-DIGITAL-BETRIEBSART` | Q8 Grundlagen Folie 3 | Ein Digitalfunkweg nennt TMO (Netzbetrieb) oder DMO (Direktbetrieb). | Betriebsart ist Pflicht, sobald `funkart = DIGITAL` |
| `FMP-DIGITAL-GRUPPENRUF` | Q8 Rufgruppenbildung Folie 3, Techn. Grundlagen Folie 30 | Der Gruppenruf ist die Standardform; der Einzelruf ist die Ausnahme und zu beschränken. | Der Digitalfunkweg fragt keine Verkehrsform ab; die Ausfüllhilfe nennt die Beschränkung des Einzelrufs |
| `FMP-KOPFLEISTE` | Q6 Allgemeines | Der Kopf trägt herausgebende Dienststelle mit Funktion des Verfassers, Art und Verwendungsbereich, Gültigkeits- und Verschlusssachenvermerk sowie F.d.R. mit Dienststellung. | Alle sieben Angaben sind erfasst und werden im Kopf angezeigt und gedruckt |
| `FMP-BETRIEBSLEITUNG` | Q4 Kap. 6.1.1 | In den Einsatzunterlagen sind Betriebsleitungen/-aufsicht anzugeben. | Betriebsleitung ist Pflichtangabe des Kopfes — bereits erfüllt |
| `FMP-VERMERK-EINFACH` | keine Quelle für zwei Felder | Ein Weg trägt **ein** Bemerkungsfeld. | Neue und geänderte Einträge schreiben nur `bemerkungen`; die Versionskopie führt Altbestand zusammen |

### 11.2 Bedienregeln (P1)

| ID | Soll | Abnahme |
| --- | --- | --- |
| `FMP-UX-WORT-DES-MEDIUMS` | Das Formular beschriftet die Erreichbarkeit mit dem Begriff des gewählten Mediums, nicht mit einem Oberbegriff. | Die Beschriftung wechselt mit dem Medium; „Erreichbar unter" erscheint nur als Spaltenkopf |
| `FMP-UX-KEINE-TOTEN-FELDER` | Ein Feld, das für das gewählte Medium nicht gilt, ist nicht sichtbar — nicht nur nicht pflichtig. | Für jedes Medium enthält das Formular ausschließlich seine Felder |
| `FMP-UX-ZWEI-TIEFEN` | Die taktische Ansicht ist Vorgabe; die betriebliche Ansicht ist einen Klick entfernt und bleibt gewählt. | Umschaltung vorhanden, Auswahl überdauert den Seitenwechsel |
| `FMP-UX-ALTANGABE` | Ein übernommener Eintrag mit Angaben, die seine Technik nicht kennt, sagt das, bevor gespeichert wird. | Der bestehende Hinweis aus [fuehrungsstelle.php:270](4fach/fuehrungsstelle.php:270) deckt auch Funkart und Verkehrsform ab |
| `FMP-UX-WEGEWAHL` | Die Disposition des LdF fragt nach dem Weg, nicht nach dem Empfänger. | Beschriftung und Ausfüllhilfe der Wegewahl nennen den Weg |

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
| `opta` | `VARCHAR(24)` | `Fu`/digital | **neu**, freiwillig |
| `issi` | `VARCHAR(16)` | `Fu`/digital | **neu**, freiwillig |
| `anschlussart` | `ENUM('AMT','NST','MOBIL','SONDER')` | `Fe`, `FAX` | **neu**, freiwillig |
| `datenart` | `ENUM('MAIL','MESSENGER','FACHANW','INTERNET')` | `@` | **neu** |
| `bemerkungen` | `TEXT` | alle | bleibt, einziges Vermerkfeld |
| `besondere_vermerke` | `TEXT NULL` | alle | **nur noch lesend** |

### 12.3 Migration

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
5. `nv_komplan` (F9) wird in dieser Überarbeitung **nicht** angefasst — siehe
   Offene Frage O4.

---

## 13. Lücken

| Nr. | Lücke | Wirkung |
| --- | --- | --- |
| **L1** | NBHB-THW, THW-DV 1-820 und die Ergänzende Loseblattsammlung Digitalfunk BOS liegen nicht vor. Q8 ist Ausbildungsunterlage, nicht Vorschrift. | Alle `FMP-DIGITAL-*`-Regeln zitieren eine Ausbildungsunterlage. Bei Vorlage der Vorschrift sind sie gegenzuprüfen. |
| **L2** | PDV/DV 800 (Fernmeldeeinsatz) liegt nicht vor, obwohl Q4 Kapitel 6 sie als Grundlage nennt. | Die Wertelisten für Bandlage und Verkehrsform sind aus der allgemeinen BOS-Praxis abgeleitet und **nicht quellengestützt**. Deshalb Schritt 4 der Migration. |
| **L3** | Q7 (Kommunikationsskizze) ist eine **Zeichnung** mit fernmeldetaktischen Zeichen und Schaltzeichen. | Bleibt außerhalb dieser Überarbeitung. eStab führt den Plan, nicht die Skizze. |
| **L4** | Kanal- und Frequenzverzeichnisse des Analogfunks sind landesrechtlich geregelt. | `kanal` bleibt Freitext; keine Prüfung gegen ein Verzeichnis. |
| **L5** | Rufgruppenübersichten TMO/DMO der Landesverbände sind nicht Teil der Anwendung. | `rufgruppe` bleibt Freitext; die Ausfüllhilfe verweist auf die Loseblattsammlung. |

---

## 14. Abnahme

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

---

## 15. Offene Fragen an den Betreiber

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| **O1** | Fernschreiber (`FS`) aus der Auswahlliste des Plans nehmen? | ja — das Netz ist abgeschaltet; der Vordruck behält sein Kästchen |
| **O2** | Sollen OPTA und ISSI überhaupt erfasst werden? Sie sind personen- bzw. gerätescharf und der Plan trägt den Vermerk „N f D". | ja, aber freiwillig; die ISSI nur, wo ein Einzelruf tatsächlich vorgesehen ist |
| **O3** | Soll `verkehrskreis` (Führung, Einsatz, Versorgung — Q4 Kap. 6.5) als eigenes Feld geführt werden, oder reicht die Bemerkung? | zunächst Bemerkung; ein Feld erst, wenn ein Plan groß genug wird, dass danach gefiltert wird |
| **O4** | Was geschieht mit der Alttabelle `nv_komplan`? Sie hat keine Oberfläche mehr, wird aber von `readiness.php` weiter geprüft. | eigener Vorgang: entweder Altbestand übernehmen oder Tabelle mit Migration entfernen |
| **O5** | Soll aus den Plandaten eine einfache Kommunikationsskizze erzeugt werden (Stellen als Kästen, Wege als Linien je Mittel)? | reizvoll, aber Q7 verlangt taktische Zeichen — als eigener Vorgang nach dieser Überarbeitung |
