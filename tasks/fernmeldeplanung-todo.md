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

- [ ] **A01** `·` **S** Regeln in die Register eintragen
      → `app/dv_rules.php`, `app/ux_rules.php`
      - [ ] Vorschriftenregeln: `FMP-MEDIUM-VORDRUCK`, `FMP-EIGENE-ERREICHBARKEIT`, `FMP-STELLENART`, `FMP-STELLENBILD`, `FMP-FUNKART`, `FMP-DIGITAL-BETRIEBSART`, `FMP-DIGITAL-GRUPPENRUF`, `FMP-DIGITAL-KEINE-GERAETEKENNUNG`, `FMP-DIGITAL-KEINE-TELEFONIE`, `FMP-DIGITAL-REPEATER-ANTRAG`, `FMP-DIGITAL-WIRKBEREICH`, `FMP-KOPFLEISTE`, `FMP-BETRIEBSLEITUNG`, `FMP-VERMERK-EINFACH`, `FMP-GEGENSTELLE-AM-WEG`, `FMP-GEGENSTELLE-KEIN-ERSATZ`, `FMP-GEGENSTELLE-FELDBEZUG`, `FMP-GEGENSTELLE-AUSWAHL`, `FMP-BETRIEBSUNTERLAGE-AKTUELL`, `FMP-VORSCHLAG-QUELLENSCHRANKE`, `FMP-WEG-IDENTITAET`, `FMP-RUECKFALLEBENE`, `FMP-EINGANGSWEG`, `FMP-EINGANGSWEG-ROLLEN`, `FMP-EINGANGSWEG-AUSSERPLAN`, `FMP-EINGANGSWEG-BEMERKUNG`, `FMP-WEG-AUSSERHALB-VORDRUCK`
      - [ ] Bedienregeln: `FMP-UX-WORT-DES-MEDIUMS`, `FMP-UX-KEINE-TOTEN-FELDER`, `FMP-UX-ZWEI-TIEFEN`, `FMP-UX-ALTANGABE`, `FMP-UX-WEGEWAHL`, `FMP-UX-VORSCHLAG-HERKUNFT`, `FMP-UX-VORSCHLAG-WEG`
      - [ ] Neue Quellen: PDV 800 (2017), THW-DV 1-820, NBHB THW in die Quellenliste
      - **Prüfung:** `dv_rule_registry.php` und `ux_rule_registry.php` müssen **rot** werden — jede Regel ohne Test
      - **Abhängigkeit:** keine. Steht bewusst vorn: Die roten Register sind ab jetzt die Fortschrittsanzeige

- [ ] **A02** `!` **S** Schema: `nv_fernmeldewege`
      → `docker/db/migrations/122-fernmeldeweg-identitaet.sql`, `docker/db/init/10-schema.sql`
      - [ ] `weg_id` (Schlüssel), `einsatz_id` (Fremdschlüssel), `weg_nummer` (eindeutig je Einsatz), `angelegt_am`, `angelegt_von`
      - [ ] `weg_nummer` als `MAX + 1` **je Einsatz**, nie wiederverwendet
      - [ ] Zeilen werden nie geändert und nie gelöscht — Auslöser wie bei den übrigen Nachweistabellen
      - **Prüfung:** `tests/php/schema_migration_contract.php`; `docker/db/verify.sql`
      - **Regel:** `FMP-WEG-IDENTITAET` · **Spec:** 9.3, 14.4

- [ ] **A03** `!!` **M** Migration: jede Bestandszeile bekommt eine Kennung
      → `docker/db/migrations/122-…`, `app/readiness.php`
      - [ ] `weg_id` an `nv_fernmeldeplan_eintraege`, Pflicht, Fremdschlüssel
      - [ ] Eindeutiger Schlüssel `(fernmeldeplan_id, weg_id)`
      - [ ] **Jede** Bestandszeile bekommt eine **eigene** Kennung — keine Verkettung über Versionen (Spec 14.6, Schritt 5a)
      - [ ] `rufname` → `erreichbarkeit` umbenennen (`CHANGE`, kein Wertewechsel)
      - **Prüfung:** Lauf gegen eine Kopie eines Bestandsdatenbestands **vor** der Auslieferung. Zu belegen, nicht zu glauben: Ein `ALTER TABLE … CHANGE` ohne Wertewechsel darf keinen Unveränderlichkeitsauslöser feuern
      - **Risiko `!!`:** Die einzige Migration über alle Bestandszeilen. Sie berührt Tabellen mit Unveränderlichkeitsauslösern aus Migration 94 und 117

- [ ] **A04** `!` **S** Versionskopie trägt die Kennung
      → `app/dv_operations.php`
      - [ ] Die Kopie in [dv_operations.php:4829](app/dv_operations.php:4829) nimmt `weg_id` unverändert mit
      - [ ] Neue Wege im Entwurf holen sich eine frische Kennung
      - **Prüfung:** Ein Versionswechsel erhält alle Kennungen; ein neuer Weg bekommt eine neue
      - **Abhängigkeit:** A03

- [ ] **A05** `·` **S** `sortierung` ist nur noch Reihenfolge
      → `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [ ] Kommentar an der Spalte: keine Identität mehr, Lücken sind zulässig
      - [ ] Der Bedienung wird **kein** Umsortieren hinzugefügt — das ist ein eigener Wunsch. Aber es ist ab jetzt gefahrlos möglich
      - **Prüfung:** volle Suite

> **Prüfpunkt C1.** Bestandsplan unverändert lesbar, Ausleitung identisch,
> Versionswechsel erhält alle Kennungen.

---

## P2 — Inhalt des Plans

- [ ] **A06** `!` **M** Analog und digital trennen
      → `docker/db/migrations/123-fernmeldeweg-funkart.sql`, `app/dv_operations.php`, `4fach/fuehrungsstelle.php`
      - [ ] `funkart`, `band`, `relaisstelle`, `betriebsart`, `rufgruppe`, `anschlussart`, `datenart`
      - [ ] `bandlage` und `verkehrsform` bleiben `VARCHAR` und **ungeprüft** (O12); Geltung auf Analogfunk verengt
      - [ ] `ESTAB_DV_MEDIA_DEFINITIONS` wird je Funkart aufgelöst; `FS` verschwindet aus der Planauswahl (O1)
      - [ ] Altbestand: `funkart` bleibt `NULL`, der Altangaben-Hinweis aus [fuehrungsstelle.php:270](4fach/fuehrungsstelle.php:270) deckt ihn ab
      - [ ] Kein Feld für OPTA oder ISSI (O2); die Ausfüllhilfe der Bemerkung sagt, dass sie auch dort nicht hineingehören
      - **Prüfung:** Digitalfunkweg ohne Kanal und Bandlage speicherbar, Analogfunkweg nicht ohne sie; Bandlage nimmt jeden Text an
      - **Regel:** `FMP-FUNKART`, `FMP-DIGITAL-*` · **Spec:** 8

- [ ] **A07** `·` **S** Ein Bemerkungsfeld, ein Begriff, eine Stellenart
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
