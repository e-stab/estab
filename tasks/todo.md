# Aufgabenliste — Meldewesen der THW-Führungsstelle

Begründung, Zuschnitt und Abnahmekriterien stehen in `tasks/plan.md`.
Anforderungstexte stehen in `SPEC.md` Abschnitt 5.

Stand: 61 offene Anforderungen in 40 Aufgaben. 20 Anforderungen sind bereits
erfüllt und stehen nicht in dieser Liste.

Risiko: `·` niedrig · `!` mittel · `!!` hoch

---

## P0 — Nachweisapparat · blockiert alles Weitere

- [x] **T01** `·` Quelle „Grundlagen des Meldewesens" aufnehmen — `REG-QUELLE-MELDEWESEN`
      → Konstante in `app/dv_rules.php` **und** Positivliste in `tests/php/dv_rule_registry.php`
- [x] **T02** `·` Verdrängungsvermerk einführen — `REG-VORRANGVERMERK`
- [x] **T03** `·` Bedienkatalog anlegen — Apparat
      → `app/ux_rules.php`, `tests/php/ux_rule_registry.php`, Eintrag in `run.sh`

> **Prüfpunkt C0** — Trägt der Apparat? Ist die Trennung an den Kennungen erkennbar?

---

## P1 — Nachweise nachziehen: Vorschrift · verhaltensneutral · parallelisierbar

- [x] **T04** `·` Vermerke der Fernmeldezentrale — `NV-02-AUFNAHMEVERMERK`, `NV-03-ANNAHMEVERMERK`, `NV-04-BEFOERDERUNGSVERMERK`, `NV-05-TBB-NUMMER`
- [x] **T05** `·` Felder ohne eigene Prüfung — `NV-01-TKM-TATSAECHLICH`, `NV-06-RUFNAME`, `NV-11-RUFNUMMER`, `NV-13-INHALT-BETREFF`
- [x] **T06** `·` Beglaubigung und Quittung — `NV-17-ZEICHEN-FUNKTION`, `NV-18-QUITTUNG`
- [x] **T07** `·` Stationen des Laufwegs — `LW-EINGANG-STATIONEN`, `LW-AUSGANG-STATIONEN`
- [x] **T08** `·` Feldhoheit und Korrekturschleife — `LW-NUR-BLAUER-TEIL`, `LW-KORREKTURSCHLEIFE`
- [x] **T09** `·` Die vier Durchschriften — `NV-4FACH-VERTEILUNG`
      → belegen, dass rote und grüne Durchschrift auch bei Abwahlversuch entstehen
- [x] **T10** `·` Sichter und LdF im Laufweg — `FUEST-SICHTER-BINDEGLIED`, `FUEST-LDF-BETRIEB`
- [x] **T11** `·` Fortschreibung und Aufbewahrung — `ETB-APPEND-ONLY`, `ETB-AUFBEWAHRUNG`
      → Untergrenze ein Jahr prüfen, nicht die zehn Jahre des Bestands
- [x] **T12** `·` Fernmeldeplan — `TKM-FERNMELDEPLAN`

> **Prüfpunkt C1** — Stichprobe an drei Tests: Verhalten entfernen, Test muss rot werden.

---

## P2 — Nachweise nachziehen: Bedienung · setzt T03 voraus

- [x] **T13** `·` Das Papierbild des Vordrucks — `UX-EINE-SEITE`, `UX-PAPIERBILD`, `UX-SPRACHE-VORSCHRIFT`
- [x] **T14** `·` Ausfüllhilfen und Rückmeldung — `UX-INFOPOINTER`, `UX-RUECKMELDUNG`
- [x] **T15** `·` Standort und flache Bildschirme — `UX-STANDORT`, `UX-FLACHE-BILDSCHIRME`

> **Prüfpunkt C2** — Beschreiben die Bedienregeln, was der Anwender erlebt?

---

## P3 — Vordruck schärfen · ab hier ändert sich Verhalten

- [x] **T16** `!` Feldnummern auf eine Brücke ziehen — `NV-NUMMERNBRUECKE`
      → zuerst in dieser Phase; danach arbeitet T17–T20 mit einer Zählung
- [x] **T17** `!` Dienststelle statt Eigenname — `NV-10-ANSCHRIFT-DIENSTSTELLE`, `NV-15-ABSENDER`
      → Führung statt Zurückweisung; Entscheidung an C3
- [x] **T18** `!` Form und Vorrangstufe — `NV-08-DURCHSAGE-SPRUCH`, `NV-09-VORRANGSTUFE`
      → Stufen jenseits des Vordrucks dürfen kein Ankreuzfeld vortäuschen
- [x] **T19** `!` Leitfragen und Tatsachenkennzeichnung — `NV-14-5W`, `NV-14-TATSACHE-VERMUTUNG` *(braucht T01)*
- [x] **T20** `·` Datum-Uhrzeit-Gruppe mit Monatskürzel — `NV-DATUM-MONATSKUERZEL`

> **Prüfpunkt C3** — Reicht Führung bei Anschrift und Absender, oder zurückweisen?

---

## P4 — Konstanz der Bedienung · größte sichtbare Wirkung

- [x] **T21** `!!` Navigation bleibt stehen — `UX-MENUE-ORTSKONSTANZ`
      → Kollision K1: sichtbar und inaktiv mit Grund statt ausgeblendet
      → Test muss mitprüfen, dass Sichtbarkeit keine Freigabe ist
- [x] **T22** `!` Ein Weg je Ziel — `UX-MENUE-EIN-WEG`
- [x] **T23** `!` Katalog der wiederkehrenden Elemente — `UX-ELEMENTKONSTANZ`
- [x] **T24** `!` Kein Bruch beim Stationswechsel — `UX-KEIN-BRUCH-IM-LAUFWEG`

> **Prüfpunkt C4** — **Erste Bedienprüfung** nach `UX-EINARBEITUNG`: drei Personen
> ohne Anwendungskenntnis, je ein vollständiger Nachrichtenlauf. Jeder Abbruch
> ist ein Mangel und wird vor C5 behoben.

---

## P5 — Zugänglichkeit

- [x] **T25** `!` Zuständigkeit ohne Farbe — `UX-MEINE-FELDER`, `UX-MEINE-FELDER-OHNE-FARBE`
- [x] **T26** `·` Kontrast im Vordruck — `UX-KONTRAST`
- [x] **T27** `!` Tastaturbedienung — `UX-TASTATUR`
- [x] **T28** `!` Laufweg ohne JavaScript — `UX-OHNE-JAVASCRIPT`

> **Prüfpunkt C5** — **Freigabe für T29 erforderlich.** Ohne Bestätigung wird P6
> ohne T29 begonnen.

---

## P6 — Führungsstelle und Führungsmittel

- [x] **T29** `!!` Sichtung im Ausgang auf die Form begrenzen — `FUEST-SICHTER-AUSGANG-FORMAL`
      → nimmt dem Sichter eine heute vorhandene Möglichkeit · nur nach Freigabe an C5
- [x] **T30** `!` Übermittlungsmittel erweitern — `TKM-KATALOG` *(braucht T01)*
- [x] **T31** `!` Melder und Kurier trennen — `TKM-MELDER-KURIER` *(braucht T01, T02)*
      → Ankreuzfeld bleibt `Kurier/Melder`; Rolle wird zusätzlich geführt
- [x] **T32** `!` Pflichten des Melders — `TKM-MELDER-PFLICHTEN` *(braucht T31)*

> **Prüfpunkt C6** — Trägt die Rollentrennung im Betrieb?

---

## P7 — Meldearten als Merkhilfe · keine Änderung am Vordruck

- [x] **T33** `·` Die drei Meldearten — `MW-MELDEART` *(braucht T01)*
      → keine Migration · kein neues Feld · kein Merkmal am Datensatz
- [x] **T34** `·` Richtung der Meldung — `MW-MELDEWEG-RICHTUNG` *(braucht T33)*
      → erinnern, nicht hindern: keine Zurückweisung, keine Umlenkung
- [x] **T35** `·` Richtung der Orientierung — `MW-ORIENTIERUNG-RICHTUNG` *(braucht T33)*
- [x] **T36** `·` Antrag: Richtung und Leitfragen — `MW-ANTRAG-RICHTUNG`, `MW-ANTRAG-SCHEMA` *(braucht T33)*
- [x] **T37** `·` Sofort und ohne Aufforderung — `MW-SOFORTMELDUNG` *(braucht T33)*
      → Gefahrstoffe und Gefahrgüter, Abschluss des Auftrages, Abweichung vom Auftrag

> **Prüfpunkt C7** — **Zweite Bedienprüfung.** Größter Zuwachs an Begriffen.

---

## P8 — Lagemeldung

- [x] **T38** `·` Acht Punkte als Merkhilfe — `LM-AUFBAU`, `LM-MELDEWEG` *(braucht T33)*
- [ ] **T39** `·` Anlass der Lagemeldung — `LM-ANLASS` *(braucht T38)*

> **Prüfpunkt C8** — Ist der Acht-Punkte-Aufbau im Einsatz ausfüllbar?

---

## P9 — Rest

- [ ] **T40** `!` Entscheidungen im ETB — `ETB-ENTSCHEIDUNGEN`

> **Abschlussprüfpunkt** — Bedienprüfung, vollständiger Testlauf, `SPEC.md` ohne
> offene Zeile.

---

## Laufend

- [ ] **UX-EINARBEITUNG** — Bedienprüfung vor jeder Freigabe. Beginnt an C4,
      endet nie. Wer die Prüfung einmal gemacht hat, ist für die nächste
      ungeeignet.
