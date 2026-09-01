-- Let an incoming message say which way it came in on.
--
-- The two-step "Fernmelder records, LdF checks" is already built: A/W states
-- Feld 1, and `LdF-Eingang` refuses to save without
-- `incoming_transport_confirmed`. What was missing is the SUBJECT. Confirmed
-- was one of six tick boxes, not a route of the telecommunications plan --
-- `estab_fernmeldeplan_eintrag_id` was only ever written on outgoing
-- messages, and its read was locked to `04_richtung = 'A'`.
--
-- Without it the plan never hears back. Beschluss B2 put the plan on the own
-- reachability; "which of our reachabilities is actually used" is the question
-- the S6 builds the next version from, and it was unanswerable.
--
-- Three columns, all carrying the `estab_` prefix, because none of them is a
-- field of the message form. The form has Feld 1 for the means and nothing
-- resembling a route; putting an input for one between its fields would break
-- UX-PAPIERBILD. Column names say which side of that line they stand on:
-- form fields carry their field number, application data carry `estab_`.
--
-- `estab_eingangsweg_bemerkung` belongs to the Fernmelder and stays readable
-- but unwritable for the LdF. That is not a formality: the one states, the
-- other checks. If the checker could rewrite the statement, the check would be
-- worthless and the evidence could no longer say who claimed what.
--
-- `estab_gegenstelle_id` records WHICH counterpart of the plan A/W picked in
-- Feld 6 -- not its text. The LdF's Feld 15 is then prefilled from exactly
-- that counterpart, with nothing to guess: two counterparts with the same
-- callsign on different routes would be ambiguous for a text comparison and
-- are not for a selection.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE route_column INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '126-fernmeldeplan-gegenstellen.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incoming-route migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO route_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = 'estab_fernmeldeplan_eintrag_id';
  IF route_column <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incoming-route migration blocked: the route reference is missing';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_127_spalten;
DELIMITER //
CREATE PROCEDURE estab_migrate_127_spalten()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = 'estab_eingangsweg_bemerkung'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `estab_eingangsweg_bemerkung` VARCHAR(2000) NULL
        AFTER `estab_fernmeldeplan_eintrag_id`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = 'estab_gegenstelle_id'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `estab_gegenstelle_id` BIGINT UNSIGNED NULL
        AFTER `estab_eingangsweg_bemerkung`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'idx_nachricht_gegenstelle'
  ) THEN
    CREATE INDEX `idx_nachricht_gegenstelle`
      ON `nv_nachrichten` (`estab_gegenstelle_id`);
  END IF;
END//
DELIMITER ;

CALL estab_migrate_127_spalten();
DROP PROCEDURE estab_migrate_127_spalten;

-- Kein Fremdschluessel auf die Gegenstelle, und das ist Absicht.
--
-- Der Nachweis haelt fest, WAS zum Zeitpunkt der Aufnahme im Plan stand. Ein
-- Fremdschluessel mit RESTRICT verboete dem S6, eine Gegenstelle je wieder aus
-- einem Entwurf zu streichen, sobald eine Nachricht sie einmal benannt hat;
-- einer mit SET NULL loeschte den Nachweis. Beides ist schlechter als ein
-- Verweis, der ins Leere zeigen darf und dann sagt: die Gegenstelle stand
-- damals im Plan und steht heute nicht mehr darin.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE added INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO added
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name IN (
       'estab_eingangsweg_bemerkung',
       'estab_gegenstelle_id'
     )
     AND is_nullable = 'YES';
  IF added <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incoming-route migration failed: the incoming route columns are incomplete';
  END IF;
END//
DELIMITER ;
