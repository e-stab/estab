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

- [x] **A07** `!` **M** Ein Bemerkungsfeld, ein Begriff, eine Stellenart

      **Bei A06 vorweggenommen**, weil die Tabelle sonst mit alten Köpfen über
      neuen Daten gestanden hätte: Die Spaltenköpfe heißen „Stelle" und
      „Erreichbar unter", das Formular beschriftet die Erreichbarkeit mit dem
      Begriff des Mediums, und die Tabelle zeigt die Wegnummer aus A04.
      Offen bleiben die Datenbankumbenennung, die Stellenart und die
      Zusammenführung der beiden Vermerkfelder.

      → `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [x] „Betriebsstellen-Klarbezeichnung" → **„Stelle"**, mit Ausfüllhilfe
      - [x] Spalte `rufname` → `erreichbarkeit` (Migration 124), auf 255 Zeichen
            verbreitert; Spaltenkopf „Erreichbar unter", im Formular der Begriff
            der Wegart
      - [x] `stellenart` (`EIGEN`, `UEBER`, `UNTER`, `NEBEN`), freiwillig
      - [x] `besondere_vermerke` wird nur noch gelesen; die Versionskopie führt
            beide Werte in `bemerkungen` zusammen — die Migration fasst keine
            Zeile an
      - [ ] Vorspann der Plantafel: „Der Fernmeldeplan sagt, wie die Stellen des eigenen Verbundes erreichbar sind…" — steht noch aus
      - [x] Die Ausleitung nach PDF führt jetzt vierzehn statt sechs Wegfelder
      - **Prüfung:** Bedienprüfung mit Bildschirmabzug; ein Versionswechsel führt Altvermerke zusammen und lässt Altversionen unberührt
      - **Regel:** `FMP-VERMERK-EINFACH`, `FMP-EIGENE-ERREICHBARKEIT`, `FMP-STELLENART`, `FMP-UX-WORT-DES-MEDIUMS`

- [x] **A08** `!` **M** Rückfallebene
      → `docker/db/migrations/124-fernmeldeweg-rueckfallebene.sql`, `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [x] `rueckfallebene_fuer_weg`, zusammengesetzter Fremdschlüssel auf die
            Zuordnung, `RESTRICT` — Migration 125
      - [x] Selbstbezug und Ring in der **Anwendung** — ein Spalten-`CHECK` sieht
            die eigene Kennung nicht, sie steht in der Zuordnung. Ketten erlaubt
      - [x] Auswahl „Rückfallebene für …" mit den übrigen Wegen des Entwurfs;
            ohne anderen Weg abgeschaltet mit Begründung
      - [x] Kein zweites Wahrheitsfeld — `NULL` ist der Schalter
      - [x] Tabellenspalte „Rückfallebene"
      - **Prüfung:** Kette aus drei Wegen über zwei Versionswechsel; Ring zurückgewiesen; Löschen des Hauptwegs nennt die Ersatzwege
      - **Abhängigkeit:** A04 · **Regel:** `FMP-RUECKFALLEBENE` · **Spec:** 9

> **Prüfpunkt C2.** Kette, Ring, Selbstbezug, Löschsperre.

- [x] **A09** `!!` **L** Gegenstellen am Weg
      → `docker/db/migrations/125-fernmeldeplan-gegenstellen.sql`, `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [x] `nv_fernmeldeplan_gegenstellen`: `name`, `erreichbarkeit`,
            `sortierung`, `bemerkungen` — Migration 126
      - [x] Drei eigene Unveränderlichkeitsauslöser — neu geschrieben, weil eine
            ausgelieferte Migration prüfsummengebunden ist
      - [x] Die Versionskopie kopiert die Gegenstellen mit
      - [x] Anlegen und Entfernen im Entwurf, Anzeige in der Plantafel
      - [x] **Kein eigenes Medium** — es ist das des Wegs; das Vertragstor prüft es
      - [x] Ausfüllhilfe der Bemerkung nennt die Betriebszeiten (O8)
      - **Prüfung:** `schema_migration_contract.php`; ein Versionswechsel überträgt Gegenstellen vollständig
      - **Abhängigkeit:** A04 · **Regel:** `FMP-GEGENSTELLE-AM-WEG` · **Spec:** 5.3

- [x] **A10** `·` **XS** Ausleitung nachführen
      → `app/incident_export.php`, `app/incident_pdf.php`
      - [x] Wege mit allen neuen Spalten, Wegkennung und Rückfallebene
      - [x] Gegenstellen als **eigene** Liste `s6_plan_counterparts`, nicht
            verschachtelt — eine Ausleitung führt jede Tabelle so, wie sie in
            der Datenbank steht, damit sich jede Zeile über einen Schlüssel
            zurückverfolgen lässt
      - [x] Der Druck weist eine Gegenstelle ohne Weg zurück, statt sie
            stillschweigend fallen zu lassen
      - **Prüfung:** `incident_export_security.php`, `incident_pdf_security.php`
      - **Abhängigkeit:** A06, A08, A09

- [x] **A11** `·` **XS** Bestandsprüfung nachführen
      → `app/readiness.php`
      - [x] Neue Tabellen, Spalten, Fremdschlüssel und Auslöser in die Prüfung —
            laufend mit jeder Migration mitgezogen
      - **Prüfung:** `readiness` meldet vollständig
      - **Abhängigkeit:** A09

- [x] **A12** `·` **S** Kopfleiste nach Fb Fü 76
      → `docker/db/migrations/128-fernmeldeplan-kopfleiste.sql`,
        `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [x] `verfasser_funktion`, `vs_vermerk`, `freigabe_dienststellung`
      - [x] **Keine** Vorbelegung in der Datenbank. Ein `DEFAULT 'NfD'`
            schriebe die Angabe in jede bereits freigegebene Fassung — eine
            Aussage, die sie nie getroffen hat. `NULL` heißt „nicht
            angegeben"; die Vorbelegung steht in der Maske. Die Nachprüfung
            der Migration bricht ab, wenn eine bestehende Fassung doch einen
            Wert bekäme
      - [x] Beschriftungen: „Herausgebende Dienststelle", „Verwendungsbereich",
            „Stand", „Verschlusssachenvermerk", Anzeige „F.d.R.: Name,
            Dienststellung"
      - [x] **Kein** Kennwort-Feld (Spec 11); das Vertragstor beweist die
            Abwesenheit über den Spaltennamen
      - [x] Die Kopfleiste geht in den Planstand ein — ein Plan, dessen
            VS-Vermerk sich unbemerkt ändert, ist so gefährlich wie einer mit
            unbemerkt geändertem Kanal
      - **Prüfung:** `schema_migration_contract.php` (977 assertions),
        `telecom_plan_security.php`, Bildschirmabzug
      - **Regel:** `FMP-KOPFLEISTE`

- [ ] **A13** `!` **S** Bedienprüfung: ein vollständiger Plan
      → `tools/bedienpruefung/`
      - [ ] Saat für einen Plan nach Fb Fü 76 mit gesetzter **Stellenart** —
            der Vollbestand setzt sie noch nicht, deshalb steht die Skizze
            beim Prüfen mit allen Stellen seitlich
      - [ ] Bildschirmabzüge je Medium
      - **Regel:** `FMP-UX-KEINE-TOTEN-FELDER`

> **Prüfpunkt C3.** Der S6 legt den Plan ohne Rückfrage an die Entwicklung an.

---

## P3 — Nutzung im Vordruck

- [x] **A14** `!` **M** Eingangsweg: Schema und Laufweg

      **Zwei Rücknahmen unterwegs, beide durch Wächter gefunden:**
      Die Felder gehörten nicht in `official_message_required_fields()` —
      das ist die **Pflicht**liste, meine Felder sind freiwillig. Und der
      Terminalnachweis darf nicht nebenbei erweitert werden: `V1` ist mit
      einer Prüfsumme festgenagelt, eine Erweiterung ist ein eigener,
      versionierter Schritt. Aus demselben Grund trägt die Aufnahme den Weg
      **nicht** in ihren Beweis ein.

      → `docker/db/migrations/127-eingangsweg.sql`, `app/message_repository.php`,
        `app/workflow.php`, `4fach/data_hndl.php`, `4fach/4fachform.php`,
        `4fach/official_message_form.php`
      - [x] `estab_eingangsweg_bemerkung`, `estab_gegenstelle_id` an `nv_nachrichten`
      - [x] Verriegelung `04_richtung = 'A'` fällt für den Wegbezug
      - [x] Der Weg ist **freiwillig**, das Mittel bleibt Pflicht (O17)
      - [x] `FM-Eingang` gibt Wegwahl, Wegbemerkung und Gegenstellenauswahl frei
      - [x] `estab_message_resolve_incoming_route()` prüft den Weg gegen den
            **aktiven** Plan und die Gegenstelle gegen **diesen** Weg — in
            derselben Transaktion, in der die Zeile entsteht
      - [x] Die Betriebsangaben hängen nicht mehr an Feld 7: der Eingangsweg
            wird erfasst, ob durchgesprochen wurde oder nicht
      - **Prüfung:** `tkm_telecom_plan.php` (42 assertions) und der Lauf mit
        neuen Daten in der Prüfumgebung
      - **Regel:** `FMP-EINGANGSWEG` · **Spec:** 10

- [x] **A15** `!` **S** Eingangsweg: Bestätigung und Unveränderlichkeit
      → `app/message_repository.php`, `app/workflow.php`, `4fach/data_hndl.php`,
        `4fach/official_message_form.php`
      - [x] Die Bestätigung des LdF erstreckt sich auf den Weg
      - [x] Die Bemerkung des Fernmelders steht **nicht** in den schreibbaren
            Feldern von `LdF-Eingang`; `estab_workflow_route_allowed()` weist
            einen Aufruf, der sie mitliefert, vollständig ab — auch den, der
            die Maske umgeht
      - [x] Der LdF sieht Weg und Bemerkung als **Text**, nicht als Eingabefeld,
            und kann den Weg über einen eigenen Kasten richtigstellen
      - [x] Feld 1 wird **geprüft**, nicht abgeleitet
      - [x] Ein leerer Weg des LdF wird nach einem Formularfehler nicht
            stillschweigend aus der Zeile wiederhergestellt — geprüft wird auf
            Abwesenheit des Feldes, nicht auf Leere
      - **Prüfung:** `tkm_telecom_plan.php`; Lauf mit neuen Daten
      - **Abhängigkeit:** A14 · **Regel:** `FMP-EINGANGSWEG-BEMERKUNG`,
        `FMP-WEG-AUSSERHALB-VORDRUCK`

- [x] **A16** `!!` **M** Der Plan-Zweig liest die Gegenstellen (F10)
      → `app/read_authorization.php`
      - [x] `estab_read_ldf_mapping_policy()` paart `name` ↔ `erreichbarkeit` aus
            `nv_fernmeldeplan_gegenstellen`
      - [x] Abfrageform bleibt; der Planzweig verbindet über den Weg auf die
            Gegenstelle, `recency` zählt Gegenstellen
      - **Prüfung:** Kein Vorschlag stammt mehr aus `betriebsstelle` oder `erreichbarkeit` eines Wegs. Zwischenzustand ist beabsichtigt: bis zur ersten Freigabe liefert der Zweig nichts
      - **Risiko `!!`:** Berührt eine Abfrage mit Berechtigungsprädikaten in beiden Zweigen der UNION
      - **Abhängigkeit:** A09 · **Spec:** 5.4

- [x] **A17** `·` **XS** Rangfolge: Güte hinter Herkunft, Plan zuerst (F11)
      → `app/read_authorization.php`
      - [x] `source_priority` getauscht: `plan` = 0, `message` = 1
      - **Prüfung:** Ein Plantreffer steht über jedem Historientreffer; beide sichtbar
      - **Abhängigkeit:** A16 · **Regel:** `FMP-BETRIEBSUNTERLAGE-AKTUELL`

- [x] **A18** `!!` **M** Quellenachse der Vorschlagspolitik
      → `app/read_authorization.php`
      - [x] `estab_read_message_suggestion_policy()` nennt je Rolle und Feld die
            zulässigen **Quellen**
      - [x] Stab an Feld 10: `['plan']`. A/W und LdF: `['message', 'plan']`
      - [x] **Eigener Leser** `estab_read_plan_counterpart_suggestions()` mit
            schwächerer Voraussetzung — der Stab hat keine Fernmelde-Fähigkeit
            und wäre sonst schon an der Berechtigungsprüfung gescheitert
      - [x] Feld 11 wird vorschlagsfähig
      - [x] Der Standard bleibt **Zurückweisung**; die Historienfunktion **wirft**,
            wenn eine Rolle sie nicht lesen darf, statt leer zu liefern
      - **Prüfung:** `tests/integration/message_suggestions.php`; die bestehenden Leserechtsprüfungen bleiben grün
      - **Risiko `!!`:** einzige Änderung an einer Sicherheitsentscheidung in diesem Plan
      - **Regel:** `FMP-VORSCHLAG-QUELLENSCHRANKE`

> **Prüfpunkt C4 — Sicherheit.** Der Stab bekommt an Feld 10 keinen einzigen
> Vorschlag aus der Historie.

- [x] **A19** `!` **M** Vorbelegung von Feld 15 aus der Auswahl
      → `app/message_repository.php`, `4fach/4fachform.php`, `4fach/official_message_form.php`
      - [x] Wählt A/W in Feld 6 eine Gegenstelle des Plans, wird der
            **Verweis** festgehalten — `estab_gegenstelle_id`, nicht der Text
      - [x] Feld 15 ist beim LdF mit deren `name` vorbelegt und gekennzeichnet
      - [x] Ohne Auswahl bleibt Feld 15 leer; die Historie steht im Dropdown
      - [x] Keine Vorbelegung in ein belegtes Feld
      - [x] Die Ereigniszeile hält Herkunft, Planfassung und eines von vier
            Ergebnissen fest: keine Auswahl, unverändert übernommen,
            überschrieben, oder — wenn die Gegenstelle inzwischen aus dem Plan
            gestrichen wurde — zurückgezogen. Gelesen wird unter derselben
            Sperre wie das Mittel, nie aus dem Browser
      - **Prüfung:** `ldf_ui_flow_security.php`; Lauf mit neuen Daten in der
        Prüfumgebung, vier Fälle
      - **Abhängigkeit:** A16, A18 · **Regel:** `FMP-GEGENSTELLE-AUSWAHL` · **Spec:** 5.6

- [x] **A20** `·` **S** Herkunft an jedem Vorschlag
      → `4fach/4fachform.php`, `app/read_authorization.php`
      - [x] Auch bei `FM-Eingang` trägt jede Option eine Herkunftsangabe —
            „Aktiver S6-Fernmeldeplan" vor den Werten aus der Historie
      - [x] Ein Planvorschlag nennt den Weg, über den er gilt: „Erreichbar
            über: Funk (digital) · Führungsstelle". Steht derselbe Wert an
            mehreren Wegen, werden alle genannt — das ist der Regelfall bei
            einer Stelle mit Rückfallebene
      - [x] Zwei Wörter für zwei Herkünfte: der ähnliche Treffer aus der
            Historie nennt seinen **Bezug**, der Planvorschlag den **Weg**.
            Ein gemeinsames Wort wäre für einen von beiden falsch
      - [x] Die Wegewahl fragt „Über welchen Weg kam die Nachricht herein?"
      - **Prüfung:** `message_suggestion_security.php` (91 assertions)
      - **Regel:** `FMP-UX-VORSCHLAG-HERKUNFT`, `FMP-UX-VORSCHLAG-WEG`, `FMP-UX-WEGEWAHL`

> **Prüfpunkt C5.** Eingangsweg, Bemerkung, Vorbelegung.

---

## P4 — Darstellung

- [x] **A21** `!` **M** Taktische Ansicht: ein Kasten je Stelle
      → `4fach/fuehrungsstelle.php`, `estab-ui.css`
      - [x] Je Stelle ein Block: Stelle, Stellenart, darunter je Weg Mittel
            und Erreichbarkeit, darunter eingerückt die Gegenstellen
      - [x] Der Ersatzweg steht eingerückt unter dem Weg, den er ersetzt —
            und **nicht** noch einmal für sich; sonst stünde derselbe Weg
            zweimal im Bild und man müsste raten, welcher gilt
      - [x] Keine Bandlage, keine Rufgruppe, keine Betriebsart
      - **Regel:** `FMP-STELLENBILD`

- [x] **A22** `·` **S** Betriebliche Ansicht und Umschaltung
      → `4fach/fuehrungsstelle.php`
      - [x] Die vorhandene Tabelle behält Suche, Sortierung und Filter
      - [x] Umschaltung taktisch/betrieblich als **Verweis**, nicht als
            Schaltfläche — ohne JavaScript bedienbar
      - [x] Die Wahl steht in der Sitzung und überdauert den Seitenwechsel:
            wer umgeschaltet hat, findet nach dem Speichern eines Weges
            dieselbe Ansicht wieder
      - **Abhängigkeit:** A21 · **Regel:** `FMP-UX-ZWEI-TIEFEN`

- [x] **A23** `·` **S** Wege außerhalb des Plans sichtbar machen
      → `4fach/fuehrungsstelle.php`, `app/read_authorization.php`
      - [x] Zwei Hälften: Eingänge **ohne** Wegangabe und Eingänge über einen
            Weg, den die **aktive** Fassung nicht mehr führt
      - [x] Gezählt, nicht zitiert — Mittel, Anzahl, letzte Zeit; kein
            Rufname, kein Betreff. Der S6 ist eine Stabsfunktion: er soll aus
            dem Verkehr **lernen** dürfen, ohne fremde Nachrichten zu **lesen**
      - **Abhängigkeit:** A14 · **Regel:** `FMP-EINGANGSWEG-AUSSERPLAN`

- [x] **A24** `·` **S** Taktische Zeichen aufnehmen
      → `4fsym/taktische-zeichen/`, `THIRD_PARTY_NOTICES.md`
      - [x] 22 Zeichen aus den Vorlagen gebaut (Stellen und Verbindungen nach
            Spec 16.4), einmalig; das Bauwerkzeug ist **nicht** Teil der
            Laufzeit
      - [x] Schriftbindung entfernt — der Text erbt die Schrift der
            Anwendung; das spart je Zeichen rund 25 KB
      - [x] `THIRD_PARTY_NOTICES.md`: Urheber, Fundstelle, CC-BY-4.0 und die
            zwei vorgenommenen Änderungen
      - [x] Keine externen Verweise; die Quelltafel-Prüfung lässt das
            Verzeichnis **nur für SVG** zu, damit ein eingeschmuggeltes
            Binärbild weiter auffällt
      - **Prüfung:** `third_party_notice_contract.php`, `source_tree_hygiene.sh`

- [x] **A25** `!` **M** Erzeugte Kommunikationsskizze
      → `app/telecom_sketch.php`, `4fach/fuehrungsstelle.php`
      - [x] Anordnung **nach dem Vordruck Fb Fü 77**: in der Mitte die eigene
            Führungsstelle mit Funkrufname und unseren Mitteln, links die
            übergeordneten, rechts die nachgeordneten Gegenstellen
      - [x] Eine Linie beginnt an der **Zeile ihres Mittels**, nicht an der
            Kastenmitte — so ist ablesbar, worüber eine Stelle erreicht wird
      - [x] Linienart je Mittel; die Skizze trägt **ohne** die taktischen
            Zeichen
      - [x] Rückfallebene dünner, heller und benannt
      - [x] Kopfleiste, Fassung und F.d.R. — keine zweite Wahrheit
      - [x] Reicht der Platz nicht, wird abgeschnitten und **gesagt**, wie
            viele fehlen; kleiner gesetzt wird nicht

      **Berichtigung vom 01.09.2026 — die Anordnung war falsch.** Ich hatte
      ein Kreuz gebaut: `EIGEN` in der Mitte, die anderen Stellen oben, unten
      und seitlich, und die `stellenart` am **eigenen Weg**. Der Betreiber hat
      die Vorlage Fb Fü 77 nachgereicht und die Sache klargestellt: Der Plan
      erfasst die **eigenen** Kommunikationsmittel und Erreichbarkeiten. Eine
      Planzeile ist eines **unserer** Mittel; über- und untergeordnet sind
      Eigenschaften der **Gegenstelle**. Migration 129 zieht die Spalte um,
      die Skizze ist neu gebaut, Spec 5.2 und 16.2 sind berichtigt.

      **Drei weitere Mängel erst im Bild gefunden:** Beschriftungen auf der
      Linienmitte überdruckten sich; die eigene Stelle wurde vor den Linien
      gezeichnet und von ihnen überschrieben; zwei Stellen in einer Spalte
      zogen über die ganze Blatthöhe auseinander. Nichts davon fällt in einem
      Zahlenvergleich auf.
      - **Prüfung:** `tests/php/fmp_skizze.php` (28 assertions)
      - **Abhängigkeit:** A21, A24

> **Prüfpunkt C6.** Skizze lesbar, vollständig beschriftet.

---

## P5 — Aufräumen

- [x] **A26** `!` **S** Abbau der Alttabelle `nv_komplan`
      → `docker/db/migrations/130-komplan-abbau.sql`, `app/readiness.php`,
        `app/incident.php`, `4fcfg/dbcfg.inc.php`, `docker/db/verify.sql`,
        `docker/db/migrate.sh`
      - [x] Die Migration **bricht ab**, wenn die Tabelle nicht leer ist, und
            sagt, was zu tun ist
      - [x] Tabelle, Fremdschlüssel und die drei Auslöser
      - [x] Sechs Fundstellen in `readiness.php`, sechs in `verify.sql`, der
            `EXISTS`-Zweig in `incident.php` samt Platzhalterzahl, die
            Konfigurationszeile in `dbcfg.inc.php`
      - [x] `docker/db/init/10-schema.sql` **behält** die Tabelle — die
            Grundfassung ist in `estab_schema_baselines` prüfsummengebunden;
            sie zu ändern ließe jede bestehende Installation mit „Checksum
            mismatch for fresh schema baseline" scheitern. Die Aufgabenliste
            hatte hier zunächst das Gegenteil verlangt

      **Beim Einspielen gefunden, nicht statisch:** `docker/db/migrate.sh`
      zählt bei **jedem** Start die vierzehn Tabellen der Grundfassung nach —
      also auch lange nach dem Abbau. Es zählt jetzt die dreizehn, die noch
      gelten; sonst fährt nach dem Abbau keine Installation mehr hoch.
      - **Prüfung:** Migration gegen die gefüllte Datenbank eingespielt,
        42 Nachprüfungen bestanden, Anwendung wieder erreichbar

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
