# SPEC — Meldewesen der THW-Führungsstelle

Konformitätsspezifikation für die digitale Nachrichtenübermittlung in einer
THW-Führungsstelle. Die Anforderungen dieses Dokuments sind nicht aus dem
Bedarf der Anwendung abgeleitet, sondern aus den geltenden Vorschriften. Wo
eine Vorschrift schweigt, weist dieses Dokument die Lücke aus; es füllt sie
nicht.

---

## 1. Zweck und Geltungsbereich

### 1.1 Objective

eStab verfolgt **zwei** Ziele, die beide erfüllt sein müssen.

**Konformität.** Der digitale Vorgang entspricht dem Nachrichtenvordruck und
dem Meldeweg der Vorschrift, und dies bleibt **nachweisbar**. Nachweisbar
heißt: Zu jeder Anforderung existiert eine Regel mit Quelle und Fundstelle,
und zu jeder Regel existiert ein Test. Ein Konformitätsverlust bricht damit
die Testsuite, statt unbemerkt zu bleiben.

**Bedienbarkeit.** Die Anwendung wird von Menschen bedient, die den
Papiervordruck beherrschen, aber nicht die Anwendung. Sie muss ohne lange
Einarbeitung bedienbar sein. Der Maßstab ist unbequem, aber eindeutig:

> Wer im Einsatz zum Papier zurückgreift, weil die Anwendung im Weg steht,
> hat einen Fehler der Anwendung aufgedeckt — keinen Fehler der Bedienung.

Beide Ziele stehen gleichrangig. Eine Umsetzung, die die Vorschrift erfüllt
und dabei unbedienbar ist, ist nicht fertig; eine bequeme Umsetzung, die von
der Vorschrift abweicht, ist unzulässig. Abschnitt 2.2 regelt, was bei
Kollision gilt.

### 1.2 Zielgruppe

Die Besetzung einer THW-Führungsstelle im Einsatz: Fernmelder (A/W) in der
Fernmeldezentrale, Leiter des Fernmeldebetriebes (LdF), Sichter, die
Sachgebiete S 1 bis S 6, Fachberater und Verbindungspersonen sowie der Leiter
der Führungsstelle. Nicht Zielgruppe ist die Verwaltung im Regelbetrieb.

### 1.3 Geltungsbereich

Gegenstand ist das **Meldewesen**: der Weg einer Nachricht von ihrer Abfassung
oder Aufnahme bis zu ihrer Erledigung, ihre Dokumentation in ETB und
Technischem Betriebsbuch sowie die Führungsorganisation, soweit der
Nachrichtenlauf sie voraussetzt.

### 1.4 Nicht Gegenstand

Ausdrücklich außerhalb dieser Spec, auch wenn die DV sie regelt:

| Bereich | Fundstelle | Grund |
| --- | --- | --- |
| Rechtsgrundlagen, Haftung, Helferrecht | DV 1-101 Kap. 1 | kein Softwarebezug |
| Führungspersönlichkeit, Stress, Führungsstile | DV 1-101 Kap. 2 | kein Softwarebezug |
| Führungsvorgang, Lagebeurteilung, Entschluss | DV 1-101 Kap. 5 | eigener Umfang |
| Lagekartenführung, Lagevortrag, Stabsbesprechung | DV 1-101 Kap. 8.3, 8.5, 8.6 | eigener Umfang |
| Öffentlichkeitsarbeit | DV 1-101 Kap. 8.7 | eigener Umfang |
| Logistikdurchführung, Alarmierung, Bereitstellungsraum | DV 1-101 Kap. 7, 9 | eigener Umfang |

Die Lagemeldung bleibt **im** Geltungsbereich, obwohl sie an die Lagearbeit
grenzt: Die Lernunterlage „Grundlagen des Meldewesens" führt sie in Kap. 4
ausdrücklich als besondere Form der Meldung, die denselben Meldewegen folgt.

---

## 2. Normative Quellen und Vorrang

### 2.1 Quellen

| Kürzel | Dokument | Stand | Konstante im Katalog |
| --- | --- | --- | --- |
| Q1 | Ausfüllanleitung Nachrichtenvordruck | April 2022 | `ESTAB_DV_SOURCE_AUSFUELLANLEITUNG` |
| Q2 | Unterlage Nachrichtenvordruck, Ausfüllanweisungen für den Stab | April 2022 | `ESTAB_DV_SOURCE_UNTERLAGE` |
| Q3 | Grundlagen des Meldewesens, Version 1.1 | Mai 2024 | `ESTAB_DV_SOURCE_MELDEWESEN` |
| Q4 | THW-DV 1-101, Handbuch Führen im THW | 01.01.2006 | `ESTAB_DV_SOURCE_DV_1_101` |
| Q5 | Handbuch ETB/TBB, Führung in der THW-Führungsstelle | März 2022 | `ESTAB_DV_SOURCE_HANDBUCH` |
| P1 | Bedienanforderungen des Betreibers, Abschnitt 5.10 dieses Dokuments | — | eigener Katalog, siehe Abschnitt 10 |

Q1 bis Q5 sind **fremdbestimmt**: Sie stammen aus Vorschriften und
Ausbildungsunterlagen des THW und sind nicht verhandelbar. P1 ist
**selbstbestimmt**: Der Betreiber legt diese Anforderungen fest und kann sie
ändern. Beide werden gleich streng nachgewiesen, aber getrennt geführt — sonst
lässt sich die Frage einer Prüfung, was die Dienstvorschrift verlangt, nicht
mehr beantworten.

### 2.2 Vorrangregel

Bei Widerspruch gilt in dieser Reihenfolge:

1. **Sachnähe.** Es gilt die Quelle, deren eigener Gegenstand die Frage ist.
   Q1 regelt die Felder des Vordrucks, Q2 den Laufweg im Stab, Q5 die Bücher.
2. **Alter.** Bei gleicher Sachnähe gilt die jüngere Quelle.
3. **Auffangnorm.** Q4 gilt, soweit die jüngeren Quellen schweigen.

Diese Reihenfolge setzt die Entscheidung um, dass die neueren Unterlagen der
DV 1-101 (2006) vorgehen. Jede Regel, die aus einem verdrängten Satz einer
älteren Quelle abweicht, nennt die verdrängte Fundstelle mit.

**Vorschrift gegen Bedienung.** Kollidieren eine Anforderung aus Q1–Q5 und
eine aus P1, gilt die Vorschrift. Die Bedienung gestaltet den Spielraum, den
die Vorschrift lässt — und dieser Spielraum ist groß, weil die Vorschriften
den Vordruck, die Felder und den Laufweg regeln, nicht aber Navigation,
Rückmeldung, Hilfe, Fokusführung, Kontrast oder Gerätetauglichkeit. Wo die
Vorschrift schweigt, entscheidet P1 allein.

Eine echte Kollision ist selten und meist ein Denkfehler: Der Wunsch, Felder
umzusortieren oder zusammenzufassen, kollidiert; der Wunsch, sie besser zu
erklären, zu markieren oder erreichbar zu machen, kollidiert nicht. Wer eine
Kollision behauptet, trägt sie in Abschnitt 5.10 mit beiden Fundstellen ein.

### 2.3 Aufgelöste Widersprüche

**W1 — Feldnummerierung.** Q1 beziffert den Vordruck mit **20** Feldern,
Q2 denselben Vordruck mit **17**. Nach Sachnähe (Regel 1) gilt für die
Bezifferung Q1: Q1 ist das Dokument, das jedem Feld eine Nummer *und* eine
zugehörige Ausfüllhilfe zuordnet. Q2 gilt unverändert für den Laufweg. Die
Übersetzung erfolgt ausschließlich über die Tabelle in Abschnitt 3.

**W2 — Melder und Kurier.** Q3 Kap. 5.2 trennt zwei Rollen: „Der Melder kennt
den Inhalt der Meldung und kann ggf. auf Rückfragen antworten" gegenüber
„Die Kurierin kennt den Inhalt der Meldung nicht". Q1 und Q2 führen im
Vordruck nur ein gemeinsames Ankreuzfeld `Kurier/Melder`; Q4 Kap. 4.3.1.11
kennt nur den Melder. Nach Regel 2 gilt Q3: Die Anwendung führt die Rolle
getrennt. Das Ankreuzfeld des Vordrucks bleibt unverändert; die Rolle wird
zusätzlich geführt, weil an ihr hängt, ob eine Rückfrage beantwortet werden
kann.

**W3 — Übermittlungsmittel.** Q1 Feld 1 und Feld 7 kennen Funk, Telefon,
Telefax, DFÜ und Kurier/Melder. Q3 Kap. 5.1 nennt zusätzlich „Internet,
E-Mail, Messenger". Nach Regel 2 gilt Q3: Der Katalog der Übermittlungsmittel
darf über den Vordruck hinausgehen. Der Ausdruck darf dabei kein Ankreuzfeld
vortäuschen, das der Vordruck nicht hat.

---

## 3. Feldnummern-Mapping

Die Tabelle ist normativ. Sie ist die einzige zulässige Stelle, an der
zwischen den Zählungen übersetzt wird.

| Q1 (Vordruck, sichtbar) | Q2 (Stab-Unterlage) | Feld |
| --- | --- | --- |
| 1 | – | Übermittlungsmittel, tatsächlich benutzt (FmZt) |
| 2 | 1 | Aufnahmevermerk |
| 3 | 2 | Annahmevermerk |
| 4 | 3 | Beförderungsvermerk |
| 5 | 4 | Technisches Betriebsbuch, Eingang/Ausgang und Nr. |
| 6 | 5 | Rufname der Gegenstelle / Spruchkopf |
| 7 | 6 | Übermittlungsmittel, gewünscht (Verfasser) |
| 8 | 7 | Durchsage / Spruch |
| 9 | 8 | Vorrangstufe |
| 10 | 9 | Anschrift |
| 11 | – | Rufnummer der Gegenstelle |
| 12 | 10 | Gesprächsnotiz |
| 13 | 11 | Inhalt (Betreff) |
| 14 | – | Nachricht / Text |
| 15 | 12 | Absender |
| 16 | 12 | Abfassungszeit |
| 17 | 14 | Zeichen / Funktion |
| 18 | 15 | Quittung |
| 19 | 16 | Verteiler TEL/EL/EAL/UEAL, S 1–S 6, FaBe, VerbP |
| 20 | 17 | Vermerke / Erledigung |
| – | 13 | Einheit/Einrichtung/Stelle |

**Befund im Bestand.** Beide Zählungen sind heute im Code gleichzeitig
lebendig, und an einer Stelle stehen sie unkommentiert nebeneinander: In
`4fach/4fachform.php` trägt der Kommentar „Feld 19 gilt für ein- und
ausgehende Nachrichten" die Q1-Nummer, während die darunter gesetzte Variable
`$this->bg[16]` die Q2-Nummer verwendet. Beides ist für sich richtig. Die
Datenbankschlüssel folgen keiner der beiden durchgängig: `10_anschrift` zählt
nach Q1, `05_gegenstelle` nach Q2. Daraus folgt **NV-NUMMERNBRUECKE**.

---

## 4. Capability Map

Module in Abhängigkeitsreihenfolge. Jedes Modul setzt die darüberstehenden
voraus; die Reihenfolge ist zugleich die Baureihenfolge.

```
M1 regelwerk
 ├── M2 fuehrungsorganisation
 │    ├── M3 nachrichtenvordruck
 │    │    ├── M4 laufweg
 │    │    │    ├── M5 meldearten
 │    │    │    └── M6 dokumentation
 │    │    └── M7 fuehrungsmittel
 │    └──────────┘
 └── M8 lagemeldung  (setzt M5 und M6 voraus)

M9 bedienung  ── querschnittlich: bindet die Darstellung von M2 bis M8
```

M9 steht bewusst neben dem Baum. Es ist kein Bauabschnitt, der irgendwann
abgeschlossen wäre, sondern eine Bedingung, die jede Oberfläche der Module
M2 bis M8 erfüllen muss. Ein Modul gilt erst als fertig, wenn es **auch** die
Anforderungen aus M9 erfüllt.

| Modul | Gegenstand | Quellen |
| --- | --- | --- |
| M1 `regelwerk` | Regelkatalog, Quellenvorrang, erzwungener Nachweis | alle |
| M2 `fuehrungsorganisation` | Stationen des Laufwegs, Besetzung, Aufwuchs, Doppelfunktion | Q4 Kap. 3, 4.3 |
| M3 `nachrichtenvordruck` | Felder 1–20, Pflichtfelder, Zeitformate, Vorrangstufen | Q1, Q2 |
| M4 `laufweg` | Stationsfolge Eingang/Ausgang, Verteiler, vier Durchschriften | Q2, Q4 Kap. 4.3.1.10 |
| M5 `meldearten` | Meldung, Orientierung, Antrag und ihre Richtungen | Q3 Kap. 1–3, Q4 Kap. 8.2 |
| M6 `dokumentation` | ETB, Technisches Betriebsbuch, Aufbewahrung | Q5, Q4 Kap. 8.4 |
| M7 `fuehrungsmittel` | Übermittlungsmittel, Melder/Kurier, Fernmeldeplan | Q3 Kap. 5, Q4 Kap. 6 |
| M8 `lagemeldung` | Aufbau und Anlass der Lagemeldung | Q3 Kap. 4, Q4 Kap. 4.3.1.4 |
| M9 `bedienung` | Menüführung, Papierbild, Feldhilfen, Feldkennzeichnung, Konstanz | P1, Q2 Aufbau |

---

## 5. Anforderungskatalog

### 5.1 Legende Ist-Stand

| Zeichen | Bedeutung |
| --- | --- |
| `erfüllt` | Verhalten vorhanden **und** durch eine Katalogregel nachgewiesen |
| `ohne Regel` | Verhalten vorhanden, aber kein Eintrag im Regelkatalog — ein Rückschritt bliebe unbemerkt |
| `teilweise` | Verhalten teilweise vorhanden |
| `offen` | nicht vorhanden |
| `kein Nachweis` | Anforderung ist nicht maschinell prüfbar, gilt als Bedienhinweis |

### 5.2 M1 — regelwerk

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `REG-NACHWEISPFLICHT` | dieses Dokument | Keine Regel im Katalog ohne Test. | `tests/php/dv_rule_registry.php` schlägt fehl, sobald eine Regel ohne Test existiert. | `erfüllt` |
| `REG-UNBEKANNTE-REGEL` | dieses Dokument | Eine unbekannte Regel-ID schlägt laut fehl, statt still durchzugehen. | `estab_dv_rule()` wirft bei unbekannter Kennung. | `erfüllt` |
| `REG-QUELLE-MELDEWESEN` | Q3 | Der Katalog kennt die Lernunterlage „Grundlagen des Meldewesens" als eigene Quelle. | `estab_dv_sources()` führt die Quelle; die Registry weist eine Regel mit unbekannter Quelle weiterhin ab. | `erfüllt` |
| `REG-VORRANGVERMERK` | Abschnitt 2.2 | Eine Regel, die eine ältere Quelle verdrängt, nennt die verdrängte Fundstelle. | `estab_dv_rule_displacement()` gibt eine vollständige Angabe zurück und weist jede unvollständige laut ab. | `erfüllt` |

### 5.3 M2 — fuehrungsorganisation

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `FUEST-KLEIN-BEFOERDERUNG` | Q4, FüSt ohne Stab | Eine Führungsstelle ohne S 6 befördert ausgehende Nachrichten auch ohne veröffentlichten Fernmeldeplan. | vorhandene Regel | `erfüllt` |
| `FUEST-KLEIN-ABLOESUNG` | Q4, Besetzung | Eine ausgefallene Funktion wird einzeln neu besetzt, ohne Übergabe der ganzen Schicht. | vorhandene Regel | `erfüllt` |
| `FUEST-AUFWUCHS` | Q4 Kap. 3.5, Führungsstufen A–D | Die Führungsstelle wächst im laufenden Einsatz von „ohne Stab" auf „mit Stab" auf; der Berechtigungsmodus folgt. | vorhandene Regel | `erfüllt` |
| `FUEST-BESETZUNG-VOLLSTAENDIG` | Q4, Besetzung | Vor Freigabe des Einsatzes benennt die Anwendung unbesetzte Stationen des Nachrichtenlaufs. | vorhandene Regel | `erfüllt` |
| `FUEST-DOPPELFUNKTION` | Q4, Mehrfachbesetzung | Trägt eine Person mehrere Funktionen, weist die Anwendung die Warteschlange jeder Funktion aus. | vorhandene Regel | `erfüllt` |
| `FUEST-SICHTER-BINDEGLIED` | Q4 Kap. 4.3.1.10 | Der Sichter ist Bindeglied zwischen Stab und Fernmeldezentrale und dem Leiter Stab unterstellt. Die Aufgabe darf in Doppelfunktion mit der ETB-Führung wahrgenommen werden. | Station `Si` liegt im Laufweg zwischen FmZt und Sachgebieten; Doppelfunktion Sichter/ETB ist zulässig. | `erfüllt` |
| `FUEST-SICHTER-AUSGANG-FORMAL` | Q4 Kap. 4.3.1.10 | Bei ausgehenden Nachrichten prüft der Sichter **nur** die formale Richtigkeit — Anschrift, Unterschrift, Funktion. „Eine inhaltliche Prüfung der Nachricht entfällt." | Die Rückweisung durch den Sichter im Ausgang benennt ausschließlich Feld 10, 15 und 17 als Grund. | `offen` |
| `FUEST-LDF-BETRIEB` | Q4 Kap. 4.3.1.12 | Der LdF verantwortet den Fernmeldebetrieb und die nachgeordnete Betriebsleitung. | Beförderung und Melderauftrag laufen über die Station `LdF`. | `erfüllt` |

### 5.4 M3 — nachrichtenvordruck

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `NV-FELDNUMMERN` | Q1 Felder 1–20 | Sichtbare Feldnummer und Nummer der Ausfüllhilfe sind gleich; jedes der zwanzig Felder trägt eine Nummer. | vorhandene Regel | `erfüllt` |
| `NV-NUMMERNBRUECKE` | Abschnitt 3 | Die Übersetzung zwischen Q1- und Q2-Zählung liegt an genau einer Stelle. Kein Kommentar nennt eine Nummer der einen Zählung neben einem Bezeichner der anderen. | Eine Abbildungsfunktion ist die einzige Stelle mit beiden Zählungen; ein Test prüft Kommentar und Bezeichner auf Zählungsbruch. | `offen` |
| `NV-PFLICHTFELDER` | Q2 „Immer ausfüllen" | Die Felder 10, 13, 14, 15, 16 und 17 sind Pflicht. Eine Rückweisung benennt Feld und Grund. | vorhandene Regel | `erfüllt` |
| `NV-01-TKM-TATSAECHLICH` | Q1 Feld 1 | Feld 1 nimmt das **tatsächlich** benutzte Übermittlungsmittel auf und ist von Feld 7 getrennt. | Feld 1 ist im Ausgang erst nach der Beförderung setzbar und übernimmt Feld 7 nicht automatisch. | `erfüllt` |
| `NV-02-AUFNAHMEVERMERK` | Q1 Feld 2 | Datum mindestens zweistellig, Uhrzeit vierstellig, Aufnahme mit Namenszeichen bestätigt. | Aufnahme ohne Handzeichen ist nicht abschließbar. | `erfüllt` |
| `NV-03-ANNAHMEVERMERK` | Q1 Feld 3 | Eine zur Beförderung angenommene Nachricht erhält von der FmZt Uhrzeit und Namenszeichen. | Annahme ohne Handzeichen ist nicht abschließbar. | `erfüllt` |
| `NV-04-BEFOERDERUNGSVERMERK` | Q1 Feld 4 | Datum mindestens zweistellig, Uhrzeit der Quittung durch die Gegenstelle vierstellig, Beförderung mit Namenszeichen bestätigt. | Beförderung ohne Quittungszeit und Handzeichen ist nicht abschließbar. | `erfüllt` |
| `NV-05-TBB-NUMMER` | Q1 Feld 5 | Feld 5 kennzeichnet Eingang oder Ausgang und trägt die laufende Nummer aus dem Technischen Betriebsbuch — keine unabhängige Formularnummer. | Die im Feld gezeigte Nummer stammt aus dem TBB desselben Einsatzes. | `erfüllt` |
| `NV-06-RUFNAME` | Q1 Feld 6 | Feld 6 trägt den Funkrufnamen der Gegenstelle, nicht Eigennamen oder Anschrift. | Ausfüllhilfe weist dies aus. | `erfüllt` |
| `NV-07-TKM-WUNSCH` | Q1 Feld 7 | Feld 7 nimmt das gewünschte Übermittlungsmittel auf und ist vom Verfasser ausfüllbar. | vorhandene Regel | `erfüllt` |
| `NV-08-DURCHSAGE-SPRUCH` | Q1 Feld 8 | Der Verfasser bestimmt die Form der Nachricht. Der Spruch ist ausdrücklich die Ausnahme. | Durchsage ist die Vorbelegung; die Wahl des Spruchs ist eine bewusste Abweichung. | `teilweise` |
| `NV-09-VORRANGSTUFE` | Q1 Feld 9 | Feld 9 nimmt die gewünschte oder erhaltene Vorrangstufe auf. Ohne besondere Stufe bleibt es frei. | Ohne Vorrangstufe bleibt das Feld leer; Stufen jenseits von Sofort und Blitz erscheinen im Ausdruck nicht als Ankreuzfeld des Vordrucks. | `teilweise` |
| `NV-10-ANSCHRIFT-DIENSTSTELLE` | Q1 Feld 10 | Nur Dienststellen-, Teileinheits- oder Einheitsbezeichnungen; keine Eigennamen. | Ausfüllhilfe und Rückweisungsgrund benennen die Bezeichnungsart. | `teilweise` |
| `NV-11-RUFNUMMER` | Q1 Feld 11 | Feld 11 trägt die Rufnummer der Gegenstelle, in der Regel bei Gesprächsnotizen. | Ausfüllhilfe weist dies aus; das Feld darf frei bleiben. | `erfüllt` |
| `NV-GESPRAECHSNOTIZ-LAUFWEG` | Q2 Feld 12 | Eine Gesprächsnotiz hält ein geführtes Gespräch fest, durchläuft die Sichtung und ist damit abgeschlossen; Disposition und Beförderung entfallen. | vorhandene Regel | `erfüllt` |
| `NV-13-INHALT-BETREFF` | Q1 Feld 13 | Feld 13 trägt den kurzen Betreff; der ausführliche Text gehört in Feld 14. | Ausfüllhilfe trennt Betreff und Text; beide sind Pflicht. | `erfüllt` |
| `NV-14-5W` | Q4 Kap. 8.2.1.1, Q3 Kap. 3 | Der Vordruck trägt die Leitfragen Wo, Wann, Was, Wie, Wer am Textfeld — so wie der gedruckte Vordruck sie als Merke ausweist. | Die Leitfragen sind am Feld 14 sichtbar und werden für Vorleseprogramme ausgegeben. | `offen` |
| `NV-14-TATSACHE-VERMUTUNG` | Q4 Kap. 8.2.1, Q3 Kap. 3 | Die Nachricht macht unterscheidbar, was selbst festgestellt, was von anderen berichtet und was vermutet ist. | Der Vordruck bietet dafür eine Kennzeichnung oder einen festen Hinweis am Textfeld. | `offen` |
| `NV-15-ABSENDER` | Q1 Feld 15 | Absender als Dienststellen-, Teileinheits- oder Einheitsbezeichnung; keine Eigennamen. | Ausfüllhilfe und Rückweisungsgrund benennen die Bezeichnungsart. | `teilweise` |
| `NV-16-ABFASSUNGSZEIT` | Q1 Feld 16 | Feld 16 trägt die Abfassungszeit. Der Server setzt keine Erfassungszeit ein, ohne dies auszuweisen. | vorhandene Regel | `erfüllt` |
| `NV-17-ZEICHEN-FUNKTION` | Q1 Feld 17 | Der Verfasser beglaubigt die Nachricht mit Namenszeichen **und** Funktion. | Beide Angaben stammen aus der Anmeldung und sind nicht frei überschreibbar. | `erfüllt` |
| `NV-18-QUITTUNG` | Q1 Feld 18 | Der Sichter quittiert den Erhalt mit vierstelliger Uhrzeit und Namenszeichen. | Ohne Quittung ist die Sichtung nicht abschließbar. | `erfüllt` |
| `NV-19-VERTEILER-EINGANG` | Q2 Feld 19 | Die Sichtung einer eingehenden Nachricht benennt mindestens einen Empfänger. | vorhandene Regel | `erfüllt` |
| `NV-19-VERTEILER-AUSGANG` | Q2 Feld 19 | Der Verteiler gilt auch im Ausgang und ist vom Verfasser ausfüllbar. | vorhandene Regel | `erfüllt` |
| `NV-20-VERMERKE-ERHALT` | Q2 Feld 20 | Eine spätere Eintragung ergänzt die vorhandenen Vermerke und löscht sie nicht. | vorhandene Regel | `erfüllt` |
| `NV-ZEIT-FORMAT` | Q1 Felder 2, 3, 4, 16, 18 | Uhrzeiten vierstellig, Datumsangaben mindestens zweistellig. | vorhandene Regel | `erfüllt` |
| `NV-DATUM-MONATSKUERZEL` | Q4 Kap. 8.2.1.3 | Droht eine Verwechslung des Datums, wird es zweistellig mit dem Monatskürzel verbunden (`021234jan`), Kürzel nach englischer Schreibweise. | Die Anwendung stellt diese Datum-Uhrzeit-Gruppe dort her, wo sie einsatzübergreifend gelesen wird. | `offen` |
| `NV-4FACH-VERTEILUNG` | Q4 Kap. 4.1.2.1, Q2 Blattfarben | Ab Stufe 2 ist der Vordruck vierfach zu führen. Digital heißt das: jede Nachricht erreicht die vier Empfänger der Durchschriften. | Blau und grün gehen an Verfasser bzw. Sachgebiet, rot an S 2 für ETB und Lage, gelb an das Technische Betriebsbuch — rot und grün unabwählbar. | `erfüllt` |

### 5.5 M4 — laufweg

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `LW-EINGANG-STATIONEN` | Q2 Laufweg Eingang | Fernmeldezentrale nimmt auf → Sichter quittiert und kennzeichnet → Verteilung an Bearbeiter, Lage und Betriebsbuch. | Der Eingang durchläuft A/W → LdF → Si → Abschluss; ein Überspringen ist nicht möglich. | `erfüllt` |
| `LW-AUSGANG-STATIONEN` | Q2 Laufweg Ausgang | Ausfüllen im Stab → Sichter → Beförderung → Verbleib im Betriebsbuch. | Der Ausgang durchläuft Verfasser → Si → LdF → Beförderung → A/W → Abschluss. | `erfüllt` |
| `LW-NUR-BLAUER-TEIL` | Q2 „Nur den blauen Teil ausfüllen!" | Der Stab füllt nur den Nachrichtenteil aus. Die Felder 1 bis 5 gehören der Fernmeldezentrale. | Der Verfasser erhält die Felder 1–5 nicht zur Eingabe. | `erfüllt` |
| `LW-KORREKTURSCHLEIFE` | Q2, Q4 Kap. 8.2 | Eine zurückgewiesene Nachricht bleibt dieselbe Nachricht und kehrt zu ihrem Verfasser zurück. | Der Stand „Zur Korrektur" führt zurück zum Verfasser, ohne den Objekttyp zu ändern. | `erfüllt` |

### 5.6 M5 — meldearten

Dieses Modul ist im Bestand **nicht vorhanden**. Die Begriffe Orientierung,
Antrag und Lagemeldung kommen im Code nicht vor.

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `MW-MELDEART` | Q3 Kap. 1–2, Q4 Kap. 8.2 | Eine Nachricht trägt ihre Art: Meldung, Orientierung oder Antrag. | Die Art ist am Vordruck sichtbar und in ETB und Betriebsbuch nachvollziehbar. | `offen` |
| `MW-MELDEWEG-RICHTUNG` | Q3 Kap. 1 | „Der Meldeweg führt immer von unten nach oben." | Eine Meldung an eine nachgeordnete Stelle wird zurückgewiesen oder als Orientierung geführt. | `offen` |
| `MW-ORIENTIERUNG-RICHTUNG` | Q3 Kap. 2, Q4 Kap. 8.2.2 | Die Orientierung geht von oben nach unten oder wird zwischen Gleichgestellten ausgetauscht. | Der Verteiler einer Orientierung lässt nachgeordnete und gleichgestellte Stellen zu. | `offen` |
| `MW-ANTRAG-RICHTUNG` | Q3 Kap. 2, Q4 Kap. 8.2.3 | Der Antrag geht von unten nach oben oder an Nachbarn. | Ein Antrag erreicht die vorgesetzte Führungsstelle oder eine benachbarte Stelle. | `offen` |
| `MW-SOFORTMELDUNG` | Q3 Kap. 3, Q4 Kap. 8.2.1.1 | Sofort und ohne Aufforderung zu melden sind Gefahrstoffe und Gefahrgüter, der Abschluss des Auftrages und jede Abweichung vom Auftrag. | Die Anwendung bietet für diese drei Anlässe einen unmittelbaren Weg, der die Vorrangstufe vorbelegt. | `offen` |
| `MW-ANTRAG-SCHEMA` | Q3 Kap. 3 | Ein Antrag beantwortet: wo, wann, was und warum, wie viele beziehungsweise wie beschaffen, wer fordert an. | Die Leitfragen sind am Antrag sichtbar. | `offen` |

### 5.7 M6 — dokumentation

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `ETB-FBFUE2-NACHRICHTENBEZUG` | Q5 Fb Fü 2 | Der ETB-Ausdruck weist zu jedem Eintrag den zugehörigen Nachrichtenvordruck aus. | vorhandene Regel | `erfüllt` |
| `TBB-QUITTUNG-AUSHAENDIGUNG` | Q5 Fb Fü 44 Spalte 7 | Die Spalte Quittung/Empfänger/Ausgehändigt wird mit der Aushändigung ergänzt und enthält keine anwendungsinternen Kennungen. | vorhandene Regel | `erfüllt` |
| `ETB-APPEND-ONLY` | Q4 Kap. 8.4 | ETB und Betriebsbuch werden fortschreibend geführt. Eine Korrektur erzeugt einen neuen, referenzierten Eintrag. | Kein Pfad ändert oder löscht einen bestehenden Eintrag. | `erfüllt` |
| `ETB-AUFBEWAHRUNG` | Q4 Kap. 4.3.1.4 | „Das ETB ist 1 Jahr lang aufzubewahren." | Die Aufbewahrungsfrist eines abgeschlossenen Einsatzes unterschreitet ein Jahr nicht. | `erfüllt` — der Bestand hält zehn Jahre und übererfüllt damit |
| `ETB-ENTSCHEIDUNGEN` | Q4 Kap. 4.3.1.4 | Entscheidungen sind als Eintrag oder als Anlage zum ETB zu dokumentieren. | Eine Entscheidung im Nachrichtenlauf erzeugt einen ETB-Eintrag. | `teilweise` |

### 5.8 M7 — fuehrungsmittel

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `TKM-KATALOG` | Q3 Kap. 5.1, Q1 Felder 1 und 7 | Der Katalog der Übermittlungsmittel umfasst Funk, Telefon, Telefax, DFÜ, Kurier/Melder sowie Internet, E-Mail und Messenger. | Alle Mittel sind wählbar; die über den Vordruck hinausgehenden erscheinen im Ausdruck nicht als dessen Ankreuzfeld. | `teilweise` |
| `TKM-MELDER-KURIER` | Q3 Kap. 5.2 (verdrängt Q4 Kap. 4.3.1.11) | Melder und Kurier werden unterschieden: der Melder kennt den Inhalt und kann Rückfragen beantworten, der Kurier kennt ihn nicht. | Der Melderauftrag führt die Rolle; eine Rückfrage wird nur an einen Melder gerichtet. | `offen` |
| `TKM-MELDER-PFLICHTEN` | Q3 Kap. 5.2, Q4 Kap. 4.3.1.11 | Der Melder ändert den Inhalt nicht, überbringt schnellstmöglich, meldet sich beim Auftraggeber zurück und teilt mit, wem er übergeben hat. Bis zur Rückkehr nimmt er keine anderen Aufträge an. | Der Melderauftrag verlangt die Rückmeldung mit Empfänger; ein zweiter Auftrag ist vor der Rückkehr nicht übernehmbar. | `teilweise` |
| `TKM-FERNMELDEPLAN` | Q4 Kap. 6.1.1 | Führungskräfte müssen über die zur Verfügung stehenden Verbindungen informiert sein. | Der Fernmeldeplan ist versioniert und für die Besetzung einsehbar. | `erfüllt` |

### 5.9 M8 — lagemeldung

| ID | Quelle | Soll | Abnahme | Ist |
| --- | --- | --- | --- | --- |
| `LM-AUFBAU` | Q3 Kap. 4, Q4 Kap. 4.3.1.4 | Die Lagemeldung folgt acht Punkten: allgemeine Lage, Schaden- und Gefahrenlage, eigene Lage, Lageentwicklung, Presse- und Öffentlichkeitsarbeit, besondere Vorkommnisse, Anforderungen, Sonstiges. Angaben nur zu relevanten Punkten. | Die Lagemeldung bietet die acht Punkte in dieser Reihenfolge; leere Punkte entfallen im Ausdruck. | `offen` |
| `LM-ANLASS` | Q3 Kap. 4 | Eine Lagemeldung ergeht auf Anforderung, regelmäßig auf Anordnung, bei umfassender Lageänderung, als Lageinformation nach unten und als Lageorientierung zur Seite. | Die Anwendung führt den Anlass mit. | `offen` |
| `LM-MELDEWEG` | Q3 Kap. 4 | Die Lagemeldung folgt denselben Meldewegen wie eine Meldung. | Sie durchläuft denselben Laufweg. | `offen` |

---

### 5.10 M9 — bedienung

Herkunft ist P1, sofern nicht anders angegeben. Diese Anforderungen gelten
**querschnittlich** für jede Oberfläche der Module M2 bis M8.

#### Leitsatz

Die Anwendung wird von Menschen bedient, die den Papiervordruck sicher
beherrschen und die Anwendung nicht. Jede Bedienentscheidung wird daran
gemessen, ob sie diesen Menschen näher an den vertrauten Vorgang bringt oder
weiter davon weg. Komplexität, die eine Einarbeitung erzwingt, ist kein
Mehrwert, sondern der Grund, warum im Einsatz wieder Papier benutzt wird.

#### A — Menüführung und Ortskonstanz

| ID | Soll | Abnahme | Ist |
| --- | --- | --- | --- |
| `UX-MENUE-ORTSKONSTANZ` | Die Navigation steht auf jeder Seite an derselben Stelle, mit denselben Einträgen in derselben Reihenfolge. Ein Ziel, das die eigene Funktion gerade nicht ansteuern darf, bleibt **sichtbar und inaktiv mit Grund** — es verschwindet nicht. | Ein Test rendert die Navigation für jede Kombination aus Modus, Schicht und Funktion und hält Menge und Reihenfolge der Einträge konstant; unzulässige Einträge tragen einen Grund. | `offen` — die Navigation blendet unzulässige Ziele heute aus (siehe K1) |
| `UX-MENUE-EIN-WEG` | Zu jedem Ziel führt genau ein Weg. Es gibt keine zwei Einstiege, die sich unterschiedlich verhalten. | Kein Ziel ist über zwei Wege mit abweichendem Verhalten erreichbar. | `offen` |
| `UX-STANDORT` | Der Anwender erkennt auf jeder Seite, wo er ist, in welcher Funktion er handelt und für welchen Einsatz. | Jede Seite weist Einsatz, Funktion und aktuellen Bereich aus. | `ohne Regel` |

#### B — Der Vordruck als Papierbild

| ID | Soll | Abnahme | Ist |
| --- | --- | --- | --- |
| `UX-EINE-SEITE` | Alles, was ein Arbeitsschritt auszufüllen verlangt, steht auf **einer** Seite. Kein Assistent, keine Reiter, kein Nachladen, kein Weiterblättern zum Absenden. | Kein Arbeitsschritt verteilt seine Pflichtfelder auf mehr als ein Dokument. | `ohne Regel` |
| `UX-PAPIERBILD` (P1 + Q2 Aufbau) | Die Oberfläche zeigt den Vordruck so, wie er auf Papier aussieht: dieselbe Feldfolge und die drei Teile der Unterlage — oben die Bearbeitungsvermerke der Fernmeldezentrale, in der Mitte die Nachricht, unten der Laufzettel. | Die gerenderte Feldfolge entspricht der Q1-Nummernfolge; die drei Teile sind als Gruppen ausgezeichnet und benannt. | `ohne Regel` |
| `UX-SPRACHE-VORSCHRIFT` | Feldbeschriftungen und Schaltflächen benutzen die Begriffe der Vorschrift, nicht Anwendungsjargon. Wer den Vordruck kennt, erkennt das Feld an seinem Namen wieder. | Jede Feldbeschriftung stimmt mit der Benennung der Ausfüllanleitung überein. | `ohne Regel` |

#### C — Führung im Feld

| ID | Soll | Abnahme | Ist |
| --- | --- | --- | --- |
| `UX-INFOPOINTER` | Jedes Feld trägt eine abrufbare Hilfe, die in einem Satz sagt, was einzutragen ist — nicht, wie das Bedienelement funktioniert. | Zu jedem der zwanzig Felder existiert eine Hilfe; keine Hilfe ist leer oder beschreibt nur die Bedienung. | `ohne Regel` — Hilfen zu allen zwanzig Feldern und zur digitalen Bearbeitung sind vorhanden |
| `UX-MEINE-FELDER` | Der Anwender erkennt ohne Erklärung, welche Felder er **in seiner Funktion, in diesem Schritt** auszufüllen hat. Fremde Felder bleiben sichtbar — der Vordruck ist einer —, sind aber nicht bedienbar. | Für jede Station des Laufwegs sind genau die zuständigen Felder bedienbar; alle übrigen sind schreibgeschützt und als solche benannt. | `teilweise` |
| `UX-MEINE-FELDER-OHNE-FARBE` | Die Zuordnung darf nicht allein über Farbe laufen. Farbfehlsichtigkeit, Sonnenlicht auf dem Laptop und ein Schwarzweißausdruck dürfen sie nicht zerstören. | Ohne jede Farbinformation bleibt erkennbar, welche Felder zuständig, welche fremd und welche Pflicht sind. | `teilweise` — fremde Felder sind schreibgeschützt und damit auch ohne Farbe erkennbar; die Unterscheidung der aktiven Feldgruppen läuft allein über Hintergrundfarben |
| `UX-PFLICHT-VORHER` | Pflichtfelder sind **vor** dem Absenden erkennbar, nicht erst durch die Rückweisung. | Der Vordruck markiert Pflichtfelder beim Öffnen des Arbeitsschritts. | `erfüllt` über `NV-PFLICHTFELDER` |
| `UX-RUECKWEISUNG` | Eine Rückweisung nennt Feld und Grund im Klartext, listet alle Fehler in einer Übersicht und setzt den Fokus auf das erste betroffene Bedienelement. | Rückweisung nennt je Feld eine eigene Marke und einen vollen Satz; der Fokus springt zum Bedienelement, nicht zur Zelle. | `erfüllt` über `NV-PFLICHTFELDER` |
| `UX-RUECKMELDUNG` | Nach jeder abgeschlossenen Handlung sagt die Anwendung, was geschehen ist und was als Nächstes ansteht. | Jeder Abschluss eines Arbeitsschritts erzeugt eine benannte Rückmeldung mit dem nächsten Schritt. | `ohne Regel` |

#### D — Konstanz über den Laufweg

| ID | Soll | Abnahme | Ist |
| --- | --- | --- | --- |
| `UX-ELEMENTKONSTANZ` | Gleiche Bedeutung heißt gleiches Bedienelement, gleiche Beschriftung, gleiche Stelle. Absenden, Abbrechen, Zurück und Hilfe sehen überall gleich aus und liegen überall gleich. | Ein Katalog der wiederkehrenden Elemente wird gegen alle Oberflächen gehalten. | `offen` |
| `UX-KEIN-BRUCH-IM-LAUFWEG` | Der Wechsel der Station ändert das **Bild** des Vordrucks nicht, sondern nur, welche Felder bedienbar sind. Wer die Nachricht als Fernmelder gesehen hat, erkennt sie als Sichter wieder. | Für alle Stationen desselben Vorgangs sind Feldfolge und Gruppierung identisch. | `teilweise` |

#### E — Umfeld und Zugänglichkeit

| ID | Soll | Abnahme | Ist |
| --- | --- | --- | --- |
| `UX-KONTRAST` | Text erfüllt WCAG AA gegen seinen tatsächlichen Hintergrund — auch dort, wo der Hintergrund die Farbe einer Durchschrift trägt. | Jede gerenderte Vorder-/Hintergrundpaarung erreicht das AA-Kontrastverhältnis. | `teilweise` — für die Nachrichtenlisten geprüft, für den Vordruck nicht |
| `UX-FLACHE-BILDSCHIRME` | Bedienbar auf den Geräten einer Führungsstelle, einschließlich flacher Laptopbildschirme mit etwa 600 nutzbaren Bildpunkten Höhe. | Höhenabhängige Regeln existieren, verkleinern die überschriebenen Werte tatsächlich, und die Bereichsnavigation bleibt erreichbar. | `ohne Regel` |
| `UX-TASTATUR` | Die Anwendung ist vollständig mit der Tastatur bedienbar, einschließlich der Feldhilfen. Im Stab wird getippt, nicht gezeigt. | Jedes Bedienelement ist per Tastatur erreichbar und auslösbar; die Reihenfolge folgt der Feldfolge des Vordrucks. | `offen` |
| `UX-OHNE-JAVASCRIPT` | Aufnehmen, Sichten und Befördern einer Nachricht funktionieren ohne JavaScript. Komfort darf davon abhängen, der Nachrichtenlauf nicht. | Der vollständige Laufweg ist ohne JavaScript durchlaufbar. | `teilweise` |

#### F — Einarbeitung

| ID | Soll | Abnahme | Ist |
| --- | --- | --- | --- |
| `UX-EINARBEITUNG` | Eine Person, die den Papiervordruck beherrscht und die Anwendung nicht kennt, nimmt eine Nachricht auf, sichtet sie und schließt sie ab — ohne Schulung und ohne fremde Hilfe. | Nicht maschinell prüfbar. Vor jeder Freigabe: Bedienprüfung mit mindestens drei Personen ohne Anwendungskenntnis, je Person ein vollständiger Nachrichtenlauf. Protokolliert werden Abbrüche, Rückfragen und Stellen, an denen zum Handbuch gegriffen wurde. Jeder Abbruch ist ein Mangel. | `kein Nachweis` |

#### Kollisionen

**K1 — Ortskonstanz gegen Ausblenden.** `UX-MENUE-ORTSKONSTANZ` verlangt eine
Navigation, die sich nicht verändert. Der Bestand blendet unzulässige Ziele
aus (`docs/TECHNIK.md`). Das ist **keine** Kollision mit einer Vorschrift und
auch keine mit der Sicherheit: Die Navigation ist ausdrücklich keine
Sicherheitsgrenze, jeder Endpunkt prüft seine Berechtigung selbst. Es ist eine
Bedienentscheidung, die gegen P1 steht. Auflösung: Die Einträge bleiben
sichtbar und werden inaktiv mit Grund geführt. Das erhält die Ortskenntnis und
erklärt zugleich, warum ein Ziel gerade nicht offensteht — was heute niemand
erfährt.

---

## 6. Lückenliste

Offene und nur teilweise erfüllte Anforderungen in Baureihenfolge. Regeln
ohne Katalogeintrag stehen gesondert, weil dort das Verhalten bereits stimmt
und nur der Nachweis fehlt.

**Stufe 1 — Nachweis nachziehen** (Verhalten vorhanden, Regel fehlt).
`FUEST-SICHTER-BINDEGLIED`, `FUEST-LDF-BETRIEB`, `NV-01-TKM-TATSAECHLICH`,
`NV-02-AUFNAHMEVERMERK`, `NV-03-ANNAHMEVERMERK`, `NV-04-BEFOERDERUNGSVERMERK`,
`NV-05-TBB-NUMMER`, `NV-06-RUFNAME`, `NV-11-RUFNUMMER`, `NV-13-INHALT-BETREFF`,
`NV-17-ZEICHEN-FUNKTION`, `NV-18-QUITTUNG`, `NV-4FACH-VERTEILUNG`,
`LW-EINGANG-STATIONEN`, `LW-AUSGANG-STATIONEN`, `LW-NUR-BLAUER-TEIL`,
`LW-KORREKTURSCHLEIFE`, `ETB-APPEND-ONLY`, `ETB-AUFBEWAHRUNG`,
`TKM-FERNMELDEPLAN`.

Diese Stufe ändert kein Verhalten. Sie schließt die Lücke, dass zwanzig
vorschriftsgebundene Eigenschaften heute unbemerkt verloren gehen könnten.

**Stufe 2 — M1 vervollständigen.** `REG-QUELLE-MELDEWESEN`,
`REG-VORRANGVERMERK`. Voraussetzung für jede Regel, die sich auf die
Lernunterlage stützt.

**Stufe 3 — M3 schärfen.** `NV-NUMMERNBRUECKE`, `NV-08-DURCHSAGE-SPRUCH`,
`NV-09-VORRANGSTUFE`, `NV-10-ANSCHRIFT-DIENSTSTELLE`, `NV-15-ABSENDER`,
`NV-14-5W`, `NV-14-TATSACHE-VERMUTUNG`, `NV-DATUM-MONATSKUERZEL`.

**Stufe 4 — M2 und M7.** `FUEST-SICHTER-AUSGANG-FORMAL`, `TKM-KATALOG`,
`TKM-MELDER-KURIER`, `TKM-MELDER-PFLICHTEN`.

**Stufe 5 — M5 neu.** Die Meldearten sind der größte geschlossene Block:
`MW-MELDEART`, `MW-MELDEWEG-RICHTUNG`, `MW-ORIENTIERUNG-RICHTUNG`,
`MW-ANTRAG-RICHTUNG`, `MW-SOFORTMELDUNG`, `MW-ANTRAG-SCHEMA`.

**Stufe 6 — M8 neu.** `LM-AUFBAU`, `LM-ANLASS`, `LM-MELDEWEG`. Setzt M5
voraus, weil die Lagemeldung eine besondere Form der Meldung ist.

**Stufe 7 — Rest M6.** `ETB-ENTSCHEIDUNGEN`.

### Parallelstrang Bedienung (M9)

M9 ist querschnittlich und folgt nicht den Stufen 1 bis 7. Der Strang läuft
daneben, weil jede Oberfläche, die in den Stufen entsteht, ihn ohnehin
erfüllen muss — es ist billiger, die Bedienregeln vor den neuen Modulen M5
und M8 stehen zu haben als danach.

**B1 — Nachweis nachziehen.** `UX-STANDORT`, `UX-EINE-SEITE`, `UX-PAPIERBILD`,
`UX-SPRACHE-VORSCHRIFT`, `UX-INFOPOINTER`, `UX-RUECKMELDUNG`,
`UX-FLACHE-BILDSCHIRME`. Verhaltensneutral; sichert, was heute schon stimmt.

**B2 — Konstanz herstellen.** `UX-MENUE-ORTSKONSTANZ` samt Auflösung von K1,
`UX-MENUE-EIN-WEG`, `UX-ELEMENTKONSTANZ`, `UX-KEIN-BRUCH-IM-LAUFWEG`. Dies ist
der Block mit der größten Wirkung auf die Einarbeitungszeit und der einzige,
der sichtbares Verhalten ändert.

**B3 — Zugänglichkeit.** `UX-MEINE-FELDER`, `UX-MEINE-FELDER-OHNE-FARBE`,
`UX-KONTRAST`, `UX-TASTATUR`, `UX-OHNE-JAVASCRIPT`.

**B4 — laufend.** `UX-EINARBEITUNG`: Bedienprüfung vor jeder Freigabe. Beginnt
sofort, endet nie, und liefert die Belege, an denen sich B2 und B3 messen
lassen.

---

## 7. Kommandos

```console
# statische PHP-Suite, führt auch den Regelkatalog-Nachweis aus
tests/static/run.sh
tests/static/run.sh --list

# einzelner Nachweis
PHP_BIN=php php tests/php/dv_rule_registry.php

# Laufzeit
podman compose build --pull migrate app
podman compose up -d
curl --fail --silent --show-error http://127.0.0.1:8080/health.php

# Browser- und Datenbanktests
ESTAB_CONTAINER_CLI=podman ESTAB_BROWSER_TEST=auto tests/static/run.sh
ESTAB_CONTAINER_CLI=podman npm run test:e2e
```

Auf dieser Maschine steht kein lokales `php` zur Verfügung; die Suite läuft
über den Container. `nohup` zerstört die Signalprüfung der Suite und ist
nicht zu verwenden.

---

## 8. Projektstruktur

| Pfad | Rolle in dieser Spec |
| --- | --- |
| `app/dv_rules.php` | **Vorschriftenkatalog** (Q1–Q5). Einzige Stelle für Quelle, Fundstelle und Anforderung. |
| `tests/php/dv_rule_registry.php` | erzwingt, dass keine Vorschriftenregel ohne Test bleibt |
| `app/ux_rules.php` | **Bedienkatalog** (P1). Einzige Stelle für Herkunft, Fundstelle und Anforderung. |
| `tests/php/ux_rule_registry.php` | erzwingt dasselbe für Bedienregeln |
| `app/dv_operations.php` | Regelanwendung im Betrieb |
| `4fach/official_message_form.php` | Vordruck: Felder, Ausfüllhilfen, Rückweisungsgründe |
| `4fach/4fachform.php` | Feldfreigabe je Arbeitsschritt, Durchschriften |
| `4fach/vali_data.php` | Feldprüfung |
| `app/message_status.php` | Stationsfolge Eingang, Ausgang, Gesprächsnotiz |
| `app/message_priority.php` | Vorrangstufen |
| `app/message_transport.php` | Übermittlungsmittel |
| `stabetb/`, `fmtbb/` | ETB und Technisches Betriebsbuch |
| `app/sidebar.php`, `app/navigation.php`, `app/root_menu.php` | Menüführung |
| `estab-ui.css` | Papierbild, Kontrast, Gerätetauglichkeit |
| `docs/BEDIENUNG.md` | Bedienung; folgt dieser Spec, führt sie nicht |

---

## 9. Stil

- Dokumentation, Feldbeschriftungen, Rückweisungsgründe und Regeltexte auf
  Deutsch. Bezeichner und Codekommentare folgen der Umgebung der Datei.
- Ein Regeltext benennt die Anforderung der Vorschrift, nicht die Umsetzung.
  Ein fehlgeschlagener Test soll die Vorschrift zitieren, nicht ein
  Implementierungsdetail.
- Feldnummern im Text sind immer Q1-Nummern. Wer eine Q2-Nummer meint, sagt
  es dazu.
- Keine Eigennamen in fachlichen Beispielen; Dienststellen-, Teileinheits-
  oder Einheitsbezeichnungen, wie die Vorschrift es für den Vordruck verlangt.

---

## 10. Teststrategie und Nachweisführung

### 10.1 Zwei Kataloge, ein Verfahren

Anforderungen aus Q1–Q5 und aus P1 werden **gleich streng**, aber **getrennt**
nachgewiesen:

| | Vorschrift | Bedienung |
| --- | --- | --- |
| Katalog | `app/dv_rules.php` | `app/ux_rules.php` |
| Aufruf im Test | `estab_dv_requirement('<ID>')` | `estab_ux_requirement('<ID>')` |
| Registry | `tests/php/dv_rule_registry.php` | `tests/php/ux_rule_registry.php` |
| Kennungen | `NV-`, `ETB-`, `TBB-`, `FUEST-`, `LW-`, `MW-`, `TKM-`, `LM-`, `REG-` | `UX-` |

Die Trennung ist kein Selbstzweck. Der Vorschriftenkatalog beantwortet die
Frage einer Prüfung — *was verlangt die Dienstvorschrift und wo steht das?* —
und diese Antwort wird unbrauchbar, sobald Produktentscheidungen darin
mitlaufen. Umgekehrt darf eine Bedienregel geändert werden, wenn sich die
Bedienung als schlecht erweist; eine Vorschriftenregel darf das nicht.

Der Bedienkatalog übernimmt Aufbau und Mechanik des vorhandenen
Vorschriftenkatalogs unverändert: Herkunft, Fundstelle, Anforderungstext,
lautes Scheitern bei unbekannter Kennung, erzwungene Testabdeckung.

### 10.2 Ablauf

Der Nachweis ist zweistufig und beides ist Pflicht:

1. **Katalogeintrag.** Jede Anforderung dieser Spec wird eine Regel im
   zuständigen Katalog mit Herkunft, Fundstelle und Anforderungstext.
2. **Test.** Jede Regel wird von mindestens einem Test benannt. Die Registry
   schlägt fehl, sobald eine Regel ohne Test existiert; die Auflösung schlägt
   fehl, sobald ein Test eine unbekannte Regel benennt.

Daraus folgt die Reihenfolge beim Umsetzen: **erst der Test, dann die
Regel.** Wer die Regel zuerst einträgt, bricht die Suite, bis der Test
existiert — was gewollt ist, aber nur kurz gelten darf.

Ein Test, der eine Regel benennt, prüft das Verhalten, nicht die Existenz
einer Zeichenkette.

### 10.3 Grenze des maschinellen Nachweises

Zwei Dinge lassen sich nicht durch einen Test sichern, und die Spec behauptet
das auch nicht:

- ob eine Hilfe **verständlich** ist — prüfbar ist nur, dass sie existiert und
  vom Feld handelt,
- ob die Anwendung ohne Einarbeitung bedienbar ist — dafür steht
  `UX-EINARBEITUNG` mit der Bedienprüfung als ausdrücklich manuellem Nachweis.

Für diese beiden gilt: Das Protokoll der Bedienprüfung ist der Nachweis. Es
gehört zur Freigabe wie ein grüner Testlauf.

---

## 11. Grenzen

**Immer:**

- Jede neue vorschriftsgebundene Eigenschaft erhält Regel und Test.
- Jede Regel nennt Herkunft und Fundstelle und steht im zuständigen Katalog.
- Bei Widerspruch zwischen Quellen wird die Vorrangregel aus Abschnitt 2.2
  angewandt und die verdrängte Fundstelle vermerkt.
- Feldnummern werden über die Tabelle in Abschnitt 3 übersetzt.
- Ein neues Bedienelement bekommt die Beschriftung, die Form und den Platz,
  den seine Bedeutung anderswo schon hat.
- Eine neue Oberfläche erfüllt M9, bevor sie als fertig gilt.

**Vorher fragen:**

- Eine Anforderung strenger auslegen, als die Vorschrift sie formuliert.
- Eine Anforderung der Vorschrift bewusst nicht umsetzen.
- Den Geltungsbereich aus Abschnitt 1.3 erweitern.
- Bestehende Regel-IDs umbenennen — sie sind der Anker zwischen Test und
  Vorschrift.
- Eine Bedienregel aus P1 aufweichen, weil die Umsetzung aufwendig ist.
- Einen Arbeitsschritt auf mehrere Seiten verteilen.

**Nie:**

- Eine Regel aus dem Katalog entfernen, um einen Test grün zu bekommen.
- Die Registry-Prüfung lockern, um Regeln ohne Test einzutragen.
- Vorschriftsanforderungen erfinden, die in keiner der fünf Quellen stehen.
- Eine append-only geführte Eintragung ändern oder löschen.
- Einen Ausdruck erzeugen, der ein Ankreuzfeld des amtlichen Vordrucks
  vortäuscht, das dieser nicht hat.
- Eine Bedienregel in den Vorschriftenkatalog eintragen oder umgekehrt.
- Eine Bedeutung allein über Farbe transportieren.
- Einen Navigationseintrag verschwinden lassen, statt ihn inaktiv mit Grund
  zu zeigen.
- Fachbegriffe der Vorschrift durch eigene Wörter ersetzen, weil sie
  moderner klingen.

---

## 12. Definition of Done

Eine Anforderung dieser Spec gilt als erledigt, wenn:

1. das Verhalten umgesetzt ist,
2. eine Regel mit Herkunft und Fundstelle im zuständigen Katalog steht,
3. mindestens ein Test die Regel benennt und das Verhalten prüft,
4. `tests/static/run.sh` vollständig durchläuft,
5. die Zeile in Abschnitt 5 auf `erfüllt` steht,
6. `docs/BEDIENUNG.md` die Änderung trägt, sofern sie im Betrieb sichtbar ist,
7. die Anforderungen aus M9 für jede berührte Oberfläche erfüllt sind.

Zusätzlich gilt für jede **Freigabe**: Die Bedienprüfung nach
`UX-EINARBEITUNG` ist durchgeführt und protokolliert. Ein Abbruch in dieser
Prüfung wiegt so schwer wie ein fehlgeschlagener Test — beide bedeuten, dass
die Anwendung im Einsatz nicht trägt.
