# Aufgabenliste — Fernmeldeplanung nach Fb Fü 76

Begründung und Prüfpunkte stehen in `tasks/fernmeldeplanung-plan.md`, die
Anforderungen in `tasks/fernmeldeplanung-spec.md`.

Risiko: `·` niedrig · `!` mittel · `!!` hoch
Umfang: **XS** 1 Datei · **S** 1–2 · **M** 3–5 · **L** mehr als 5

Reihenfolge: **Test zuerst, dann die Änderung, dann das Bild ansehen.**

Jede Aufgabe nennt ihre Regel-ID. Eine Aufgabe ohne Regel gehört nicht in
diesen Vorgang.

---

## P1 — Fundament: die dauerhafte Wegkennung

- [x] **A01** `·` **XS** Die drei neuen Quellen in den Vorschriftenkatalog
      → `app/dv_rules.php`
      - [x] `ESTAB_DV_SOURCE_PDV_800` (Ausgabe 2017), `ESTAB_DV_SOURCE_DV_1_820`
            (01.08.2016), `ESTAB_DV_SOURCE_NBHB_THW` (09.01.2025)
      - [x] In `estab_dv_sources()` eintragen
      - [x] `SPEC.md` Abschnitt 2.1 führt Q10 bis Q12 mit ihren Konstanten — die
            Tabelle behauptet, die Quellenliste zu sein, und wäre sonst falsch
      - **Prüfung:** `dv_rule_registry.php` bleibt **grün** — eine Quelle ohne
        zitierende Regel ist zulässig, eine Regel ohne Quelle nicht
      - **Abhängigkeit:** keine

      **Korrektur gegenüber dem ersten Entwurf dieser Liste.** A01 lautete
      ursprünglich: alle 34 Regeln auf einmal eintragen, damit die roten
      Register die Fortschrittsanzeige sind. Das ist nach dem Lesen von
      [dv_rule_registry.php:135](tests/php/dv_rule_registry.php:135) falsch.

      Die Abdeckung wird nicht aus dem Quelltext gelesen, sondern durch
      **Ausführung** bewiesen: Der Nachweis führt jeden Test aus, der
      `estab_dv_requirement()` aufruft, und sammelt die tatsächlich
      aufgelösten Kennungen. Eine katalogisierte Regel ohne Test lässt den
      Nachweis hart scheitern.

      34 Regeln vorab einzutragen hieße deshalb: die Suite ist über
      **siebenundzwanzig Commits hinweg rot**. Damit verliert der Bauablauf
      seinen wichtigsten Sinn — gegen eine dauerhaft rote Suite lässt sich
      kein neuer Rückschritt mehr erkennen, und kein Commit ist mehr ein
      sauberer Rücksprungpunkt.

      **Stattdessen trägt jede Aufgabe ihre eigenen Regeln ein, zusammen mit
      ihren Tests.** Die Regel-IDs stehen bei jeder Aufgabe unten unter
      **Regel:**; die vollständige Liste steht in der Spec, Abschnitt 13.
      Die Suite bleibt nach jeder Aufgabe grün.

      Nur die drei Quellen kommen vorab, weil sie für sich harmlos sind: Der
      Nachweis verlangt zu jeder Regel eine Quelle, aber nicht zu jeder Quelle
      eine Regel.

- [x] **A02+A03** `!!` **L** Die dauerhafte Wegkennung — Schema und Rückfüllung
      → `docker/db/migrations/122-fernmeldeweg-identitaet.sql`,
        `docker/db/verify.sql`, `app/readiness.php`,
        `tests/php/schema_migration_contract.php`
      - [x] `nv_fernmeldewege`: `weg_id`, `einsatz_id`, `weg_nummer`
            (eindeutig je Einsatz), `angelegt_am`, `angelegt_von`
      - [x] `nv_fernmeldeweg_zuordnung`: Eintrag → Kennung, eindeutig
            `(fernmeldeplan_id, weg_id)`, Auslöser gegen jedes Umhängen
      - [x] Rückfüllung als Prozedur mit Zeiger: jede Bestandszeile bekommt
            eine **eigene** Kennung, geordnet nach Einsatz, Version,
            Sortierung. Wiederanlauffähig — sie sieht nur Einträge ohne
            Zuordnung
      - [x] Vorprüfung auf das Migrationsbuch des Vorgängers, Nachprüfung auf
            „kein Eintrag ohne Kennung"
      - [x] Vertragstor hält die **Bauart** fest: Die Migration darf kein
            `UPDATE` auf `nv_fernmeldeplan_eintraege` enthalten
      - **Prüfung:** `schema_migration_contract.php` grün (971 assertions);
        Laufzeitnachweis bei Prüfpunkt C1
      - **Risiko `!!`** bestätigt sich, aber anders als gedacht — siehe unten

      **Zwei Aufgaben wurden eine.** A02 und A03 teilten sich eine
      Migrationsdatei. Getrennt festzuschreiben hieße, eine bereits
      festgeschriebene Migration später zu ändern — und eine angewandte
      Migration ist prüfsummengebunden. Wer sie nach dem Anwenden anfasst,
      bekommt „Checksum mismatch for applied migration" und kommt nicht mehr
      weiter. Eine Migrationsdatei ist mit ihrem Commit endgültig.

      **Der Bauplan hat sich durch O20 geändert.** Ursprünglich: eine Spalte
      `weg_id` am Eintrag. Das scheitert an
      `estab_dv94_fernmeldeplan_entry_update` — jede Zeile eines freigegebenen
      Plans ist gesperrt, und das Rückfüllen ist ein `UPDATE`. Der Betreiber
      hat die konservativere Bauart gewählt: eine Zuordnungstabelle, die nur
      beschrieben wird. Der geschützte Bestand wird gelesen, nicht angefasst.
      Das Vertragstor hält genau das fest, damit es niemand später
      „vereinfacht".

- [x] **A04** `!` **S** Versionskopie trägt die Kennung
      → `app/dv_operations.php`
      - [x] Die Kopie nimmt die Kennung unverändert mit und bricht ab, wenn dabei
            ein Weg ohne Kennung entstünde
      - [x] Neue Wege im Entwurf holen sich eine frische Kennung
      - [x] Die Leseabfrage gibt `weg_id` und `weg_nummer` heraus
      - [x] Integrationstest: Zeilenkennungen neu, Wegkennungen gleich; eine
            Zuordnung lässt sich nicht umhängen
      - **Prüfung:** Ein Versionswechsel erhält alle Kennungen; ein neuer Weg bekommt eine neue
      - **Abhängigkeit:** A03

- [x] **A05** `·` **S** `sortierung` ist nur noch Reihenfolge
      → `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [x] Kommentar an der Vergabestelle: keine Identität mehr, mit beiden
            Gründen, warum sie nur so aussah
      - [x] Der Bedienung wird **kein** Umsortieren hinzugefügt — das ist ein eigener Wunsch. Aber es ist ab jetzt gefahrlos möglich
      - **Prüfung:** volle Suite

> **Prüfpunkt C1.** Bestandsplan unverändert lesbar, Ausleitung identisch,
> Versionswechsel erhält alle Kennungen.

---

## P2 — Inhalt des Plans

- [x] **A06** `!!` **L** Analog und digital trennen
      → `docker/db/migrations/123-fernmeldeweg-funkart.sql`, `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [x] `funkart`, `band`, `relaisstelle`, `betriebsart`, `rufgruppe`, `anschlussart`, `datenart` — Migration 123
      - [x] `bandlage` und `verkehrsform` bleiben `VARCHAR` und **ungeprüft** (O12); Geltung auf Analogfunk verengt
      - [x] `ESTAB_DV_TELECOM_ROUTE_KINDS` löst je Funkart auf; `FS` verschwindet
            aus `ESTAB_DV_PLAN_MEDIA` (O1)
      - [x] Der Auswahlkasten führt **Wegarten**, nicht Medien; beide Funkarten
            speichern `Fu`, damit Feld 1 unberührt bleibt
      - [x] Altbestand: `funkart` bleibt `NULL` = **unbestimmt**; der Hinweis sagt
            das jetzt auch so, statt „für dieses Medium nicht vorgesehen"
      - [x] Kein Feld für OPTA oder ISSI (O2); die Ausfüllhilfe der Bemerkung sagt,
            dass sie auch dort nicht hineingehören
      - **Prüfung:** Digitalfunkweg ohne Kanal und Bandlage speicherbar, Analogfunkweg nicht ohne sie; Bandlage nimmt jeden Text an
      - **Regel:** `FMP-FUNKART`, `FMP-DIGITAL-*` · **Spec:** 8

- [ ] **A07** `·` **S** Ein Bemerkungsfeld, ein Begriff, eine Stellenart

      **Bei A06 vorweggenommen**, weil die Tabelle sonst mit alten Köpfen über
      neuen Daten gestanden hätte: Die Spaltenköpfe heißen „Stelle" und
      „Erreichbar unter", das Formular beschriftet die Erreichbarkeit mit dem
      Begriff des Mediums, und die Tabelle zeigt die Wegnummer aus A04.
      Offen bleiben die Datenbankumbenennung, die Stellenart und die
      Zusammenführung der beiden Vermerkfelder.

      → `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [ ] „Betriebsstellen-Klarbezeichnung" → **„Stelle"**, mit Ausfüllhilfe
      - [ ] `rufname` → **„Erreichbar unter"** als Spaltenkopf; im Formular der Begriff des Mediums
      - [ ] `stellenart` (`EIGEN`, `UEBER`, `UNTER`, `NEBEN`)
      - [ ] `besondere_vermerke` wird nur noch gelesen; die Versionskopie führt beide Werte in `bemerkungen` zusammen
      - [ ] Vorspann der Plantafel: „Der Fernmeldeplan sagt, wie die Stellen des eigenen Verbundes erreichbar sind…"
      - **Prüfung:** Bedienprüfung mit Bildschirmabzug; ein Versionswechsel führt Altvermerke zusammen und lässt Altversionen unberührt
      - **Regel:** `FMP-VERMERK-EINFACH`, `FMP-EIGENE-ERREICHBARKEIT`, `FMP-STELLENART`, `FMP-UX-WORT-DES-MEDIUMS`

- [ ] **A08** `!` **M** Rückfallebene
      → `docker/db/migrations/124-fernmeldeweg-rueckfallebene.sql`, `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [ ] `rueckfallebene_fuer_weg`, zusammengesetzter Fremdschlüssel auf `(fernmeldeplan_id, weg_id)`, `RESTRICT`
      - [ ] Prüfbedingung gegen den Selbstbezug; Ringprüfung in der Anwendung, **Ketten erlaubt**
      - [ ] Schalter plus Auswahl „Rückfallebene für …"; beim ersten Weg abgeschaltet mit Begründung
      - [ ] Kein zweites Wahrheitsfeld — `NULL` ist der Schalter
      - **Prüfung:** Kette aus drei Wegen über zwei Versionswechsel; Ring zurückgewiesen; Löschen des Hauptwegs nennt die Ersatzwege
      - **Abhängigkeit:** A04 · **Regel:** `FMP-RUECKFALLEBENE` · **Spec:** 9

> **Prüfpunkt C2.** Kette, Ring, Selbstbezug, Löschsperre.

- [ ] **A09** `!` **M** Gegenstellen am Weg
      → `docker/db/migrations/125-fernmeldeplan-gegenstellen.sql`, `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [ ] `nv_fernmeldeplan_gegenstellen`: `name`, `erreichbarkeit`, `sortierung`, `bemerkungen`
      - [ ] Eigene Unveränderlichkeitsauslöser nach dem Muster `estab_dv94_fernmeldeplan_entry_*`
      - [ ] Die Versionskopie kopiert die Gegenstellen mit
      - [ ] **Kein eigenes Medium** — es ist das des Wegs
      - [ ] Ausfüllhilfe der Bemerkung nennt die Betriebszeiten (O8)
      - **Prüfung:** `schema_migration_contract.php`; ein Versionswechsel überträgt Gegenstellen vollständig
      - **Abhängigkeit:** A04 · **Regel:** `FMP-GEGENSTELLE-AM-WEG` · **Spec:** 5.3

- [ ] **A10** `·` **XS** Ausleitung nachführen
      → `app/incident_export.php`
      - [ ] Wege mit allen neuen Spalten, Gegenstellen, Wegkennungen
      - **Prüfung:** `tests/integration/incident_export.php`
      - **Abhängigkeit:** A06, A08, A09

- [ ] **A11** `·` **XS** Bestandsprüfung nachführen
      → `app/readiness.php`
      - [ ] Neue Tabellen, Spalten, Fremdschlüssel und Auslöser in die Prüfung
      - **Prüfung:** `readiness` meldet vollständig
      - **Abhängigkeit:** A09

- [ ] **A12** `·` **S** Kopfleiste nach Fb Fü 76
      → `docker/db/migrations/126-fernmeldeplan-kopfleiste.sql`, `4fach/fuehrungsstelle.php`
      - [ ] `verfasser_funktion`, `vs_vermerk` (Vorbelegung `NfD`), `freigabe_dienststellung`
      - [ ] Beschriftungen: „Herausgebende Dienststelle", „Verwendungsbereich", „Stand"
      - [ ] Anzeige „F.d.R.: Name, Dienststellung"
      - [ ] **Kein** Kennwort-Feld (Spec 11)
      - **Prüfung:** Bedienprüfung mit Bildschirmabzug; alle sieben Angaben sichtbar und im Druck
      - **Regel:** `FMP-KOPFLEISTE`

- [ ] **A13** `!` **S** Bedienprüfung: ein vollständiger Plan
      → `tools/bedienpruefung/`
      - [ ] Saat für einen Plan nach Fb Fü 76: vier Stellen, acht Wege, Gegenstellen, eine Rückfallebene
      - [ ] Bildschirmabzüge je Medium: nur die Felder der jeweiligen Technik sind sichtbar
      - **Regel:** `FMP-UX-KEINE-TOTEN-FELDER`

> **Prüfpunkt C3.** Der S6 legt den Plan ohne Rückfrage an die Entwicklung an.

---

## P3 — Nutzung im Vordruck

- [ ] **A14** `!` **M** Eingangsweg: Schema und Laufweg
      → `docker/db/migrations/127-eingangsweg.sql`, `app/message_repository.php`, `app/workflow.php`
      - [ ] `estab_eingangsweg_bemerkung`, `estab_gegenstelle_id` an `nv_nachrichten`
      - [ ] Verriegelung `04_richtung = 'A'` fällt für den Wegbezug ([message_repository.php:1770](app/message_repository.php:1770))
      - [ ] Der Weg ist **freiwillig**, das Mittel bleibt Pflicht (O17)
      - [ ] `FM-Eingang` gibt Wegwahl, Wegbemerkung und Gegenstellenauswahl frei
      - **Prüfung:** Eine eingehende Nachricht lässt sich mit und ohne Weg abschließen
      - **Regel:** `FMP-EINGANGSWEG` · **Spec:** 10

- [ ] **A15** `!` **S** Eingangsweg: Bestätigung und Unveränderlichkeit
      → `app/message_repository.php`, `app/workflow.php`, `4fach/official_message_form.php`
      - [ ] Die Bestätigung des LdF erstreckt sich auf den Weg ([message_repository.php:1588](app/message_repository.php:1588))
      - [ ] Die Bemerkung des Fernmelders steht **nicht** in den schreibbaren Feldern von `LdF-Eingang`; ein abweichend gelieferter Wert wird verworfen
      - [ ] Die Leiste „Eingangsweg" über dem Vordruck, Kasten wie bei der Disposition ([official_message_form.php:2545](4fach/official_message_form.php:2545))
      - [ ] Feld 1 wird **geprüft**, nicht abgeleitet: Widerspruch zum Medium des Wegs wird zurückgewiesen
      - **Prüfung:** Der LdF kann die Bemerkung nicht überschreiben; der Druck zeigt kein Wegfeld
      - **Abhängigkeit:** A14 · **Regel:** `FMP-EINGANGSWEG-BEMERKUNG`, `FMP-WEG-AUSSERHALB-VORDRUCK`

- [ ] **A16** `!!` **M** Der Plan-Zweig liest die Gegenstellen (F10)
      → `app/read_authorization.php`
      - [ ] `estab_read_ldf_mapping_policy()` paart `name` ↔ `erreichbarkeit` aus `nv_fernmeldeplan_gegenstellen` statt `betriebsstelle` ↔ `rufname`
      - [ ] Abfrageform bleibt; nur die Tabelle wechselt
      - **Prüfung:** Kein Vorschlag stammt mehr aus `betriebsstelle` oder `erreichbarkeit` eines Wegs. Zwischenzustand ist beabsichtigt: bis zur ersten Freigabe liefert der Zweig nichts
      - **Risiko `!!`:** Berührt eine Abfrage mit Berechtigungsprädikaten in beiden Zweigen der UNION
      - **Abhängigkeit:** A09 · **Spec:** 5.4

- [ ] **A17** `·` **XS** Rangfolge: Güte hinter Herkunft, Plan zuerst (F11)
      → `app/read_authorization.php`
      - [ ] `source_priority` getauscht: `plan` = 0, `message` = 1
      - **Prüfung:** Ein Plantreffer steht über jedem Historientreffer; beide sichtbar
      - **Abhängigkeit:** A16 · **Regel:** `FMP-BETRIEBSUNTERLAGE-AKTUELL`

- [ ] **A18** `!!` **S** Quellenachse der Vorschlagspolitik
      → `app/read_authorization.php`
      - [ ] `estab_read_message_suggestion_policy()` nennt je Rolle und Feld die zulässigen **Quellen**
      - [ ] Stab an Feld 10: `['plan']`. A/W und LdF: `['message', 'plan']`
      - [ ] Feld 11 wird vorschlagsfähig
      - [ ] Der Standard bleibt **Zurückweisung** — jede Quelle ist einzeln zu nennen
      - **Prüfung:** `tests/integration/message_suggestions.php`; die bestehenden Leserechtsprüfungen bleiben grün
      - **Risiko `!!`:** einzige Änderung an einer Sicherheitsentscheidung in diesem Plan
      - **Regel:** `FMP-VORSCHLAG-QUELLENSCHRANKE`

> **Prüfpunkt C4 — Sicherheit.** Der Stab bekommt an Feld 10 keinen einzigen
> Vorschlag aus der Historie.

- [ ] **A19** `!` **M** Vorbelegung von Feld 15 aus der Auswahl
      → `app/message_repository.php`, `4fach/4fachform.php`, `4fach/official_message_form.php`
      - [ ] Wählt A/W in Feld 6 eine Gegenstelle des Plans, wird der **Verweis** festgehalten
      - [ ] Feld 15 ist beim LdF mit deren `name` vorbelegt und gekennzeichnet
      - [ ] Ohne Auswahl bleibt Feld 15 leer; die Historie steht im Dropdown
      - [ ] Keine Vorbelegung: bei mehreren Plantreffern, bei nur ähnlichem Treffer, in ein belegtes Feld, nach einer Rückgabe (`$redisposition`)
      - [ ] Die Ereigniszeile hält fest: Herkunft, Planversion, unverändert übernommen oder überschrieben
      - **Prüfung:** Vier Fälle je einmal; der Nachweis unterscheidet übernommen und selbst gewählt
      - **Abhängigkeit:** A16, A18 · **Regel:** `FMP-GEGENSTELLE-AUSWAHL` · **Spec:** 5.6

- [ ] **A20** `·` **S** Herkunft an jedem Vorschlag
      → `4fach/4fachform.php`, `estab-ui.css`
      - [ ] Auch bei `FM-Eingang` trägt jede Option eine Herkunftsangabe
      - [ ] Ein Planvorschlag nennt den Weg, über den er gilt
      - [ ] Die Wegewahl fragt „über welchen Weg", nicht „an wen"
      - **Prüfung:** Bedienprüfung mit Bildschirmabzug
      - **Regel:** `FMP-UX-VORSCHLAG-HERKUNFT`, `FMP-UX-VORSCHLAG-WEG`, `FMP-UX-WEGEWAHL`

> **Prüfpunkt C5.** Eingangsweg, Bemerkung, Vorbelegung.

---

## P4 — Darstellung

- [ ] **A21** `!` **M** Taktische Ansicht: ein Kasten je Stelle
      → `4fach/fuehrungsstelle.php`, `estab-ui.css`
      - [ ] Je Stelle ein Block: Stelle, Stellenart, darunter je Weg Mittel und Erreichbarkeit, darunter eingerückt die Gegenstellen
      - [ ] Der Ersatzweg steht eingerückt unter dem Weg, den er ersetzt
      - [ ] Keine Bandlage, keine Rufgruppe, keine Betriebsart
      - **Prüfung:** Ein Plan mit zwanzig Wegen läuft bei 916 Bildpunkten nicht quer (`docs/GESTALTUNG.md`)
      - **Regel:** `FMP-STELLENBILD`

- [ ] **A22** `·` **S** Betriebliche Ansicht und Umschaltung
      → `4fach/fuehrungsstelle.php`
      - [ ] Die vorhandene Tabelle behält Suche, Sortierung und Filter, bekommt die neuen Spalten
      - [ ] Umschaltung taktisch/betrieblich; die Wahl überdauert den Seitenwechsel
      - **Prüfung:** Bedienprüfung; Reihenfolge und Benennung sind in beiden Tiefen gleich
      - **Abhängigkeit:** A21 · **Regel:** `FMP-UX-ZWEI-TIEFEN`

- [ ] **A23** `·` **S** Wege außerhalb des Plans sichtbar machen
      → `4fach/fuehrungsstelle.php`, `app/read_authorization.php`
      - [ ] Die Plantafel führt „Wege, über die Verkehr lief und die der Plan nicht führt"
      - **Prüfung:** Ein Eingang über einen ungeplanten Weg erscheint dort
      - **Abhängigkeit:** A14 · **Regel:** `FMP-EINGANGSWEG-AUSSERPLAN`

- [ ] **A24** `·` **S** Taktische Zeichen aufnehmen
      → `4fsym/`, `THIRD_PARTY_NOTICES.md`
      - [ ] Aus den Vorlagen bauen (`make svg`, benötigt `j2cli`), einmalig; `j2cli` wird **nicht** Teil der Laufzeit
      - [ ] Übernommen werden die Zeichen aus Spec 16.4, Stellen und Verbindungen
      - [ ] Schriftbindung entfernen — der Text erbt die Schrift der Anwendung
      - [ ] `THIRD_PARTY_NOTICES.md`: Sammlung, Urheber, Fundstelle, CC-BY-4.0
      - **Prüfung:** `requirements.txt` bleibt leer; keine externen Verweise in den Dateien

- [ ] **A25** `!` **M** Erzeugte Kommunikationsskizze
      → `4fach/fuehrungsstelle.php` oder eigene Ansicht, `estab-ui.css`
      - [ ] Anordnung nach Stellenart: `UEBER` oben, `UNTER` unten, `NEBEN` seitlich
      - [ ] Linienart je Mittel; Rückfallebene dünner und heller
      - [ ] Kopfleiste, Version und F.d.R. des Plans
      - [ ] Zeichen behalten ihre Farben; im dunklen Bild stehen sie auf heller Fläche — benannte Ausnahme in `docs/GESTALTUNG.md`
      - [ ] Ohne die Zeichen baubar
      - **Prüfung:** Vier Stellen, acht Wege, Querformat, keine Überlappung
      - **Abhängigkeit:** A21, A24

> **Prüfpunkt C6.** Skizze lesbar, vollständig beschriftet.

---

## P5 — Aufräumen

- [ ] **A26** `!` **S** Abbau der Alttabelle `nv_komplan`
      → `docker/db/migrations/128-komplan-abbau.sql`, `app/readiness.php`, `app/incident.php`, `4fcfg/dbcfg.inc.php`, `docker/db/init/10-schema.sql`, `docker/db/verify.sql`
      - [ ] Die Migration **bricht ab**, wenn die Tabelle nicht leer ist, und fordert zum Sichern auf
      - [ ] Erst dann: Tabelle, Fremdschlüssel `fk_komplan_einsatz`, Auslöser `estab_komplan_b{i,u,d}_einsatz`
      - [ ] Sechs Fundstellen in `readiness.php`, darunter die Anzahlprüfung `= 10` → `= 9`
      - [ ] `EXISTS`-Zweig in [incident.php:781](app/incident.php:781), Konfigurationszeile in [dbcfg.inc.php:20](4fcfg/dbcfg.inc.php:20)
      - [ ] `tests/fixtures/legacy-runtime-schema.sql` **behält** die Tabelle — sie bildet den früheren Stand ab
      - **Prüfung:** Migration gegen eine nicht leere Tabelle muss abbrechen
      - **Abhängigkeit:** keine · **Spec:** 15

- [ ] **A27** `·` **S** Handbuch und Bedienung nachführen
      → `docs/BEDIENUNG.md`, `handbuch/`
      - [ ] Der Plan als eigene Erreichbarkeit; Gegenstellen; Rückfallebene; Eingangsweg
      - [ ] Die zwei Ansichten und wofür sie gedacht sind

- [ ] **A28** `·` **XS** Spec fortschreiben
      → `tasks/fernmeldeplanung-spec.md`
      - [ ] Ist-Stand je Regel eintragen
      - [ ] Was sich beim Bauen anders ergab, wird **hier** begründet, nicht stillschweigend anders gebaut

---

## Was nicht in dieser Liste steht

| Was | Warum |
| --- | --- |
| Wege umsortieren | Nach A05 gefahrlos möglich, aber ein eigener Wunsch |
| Einen gestrichenen Weg wieder aufnehmen | Durch die Kennung möglich, nicht verlangt |
| Wegstatistik „14 Nachrichten über diesen Weg" | Folgt aus A03 und A14, eigener Vorgang |
| Betriebliche Skizze mit Schaltzeichen | Lücke L3 |
