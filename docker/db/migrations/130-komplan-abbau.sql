-- Retire `nv_komplan`, the communication plan eStab never used.
--
-- The table came with the inherited schema and was meant to hold what
-- Fb Fü 76 holds: stations, call signs, 4 m and 2 m channels, two telephone
-- numbers, two mobiles, two fax numbers, an e-mail address and an FTP/HTTP
-- link -- one flat row per station. Nothing in the application ever wrote a
-- row into it. Migrations 45 and 50 dutifully gave it an incident reference,
-- a foreign key and three triggers, and migration 97 counted it among the
-- tables a command post rename has to walk. All of that guarded an empty
-- table for years.
--
-- The rework replaces the idea, not the rows: `nv_fernmeldeplaene` with its
-- versioned entries, route identities, counterparts and fallback levels says
-- everything the flat row could and several things it could not. Keeping the
-- unused table beside the used one leaves two answers to "where does the
-- communication plan live", and the wrong one looks plausible.
--
-- THE MIGRATION REFUSES TO RUN ON A NON-EMPTY TABLE.
--
-- Not because a row is expected -- none should exist -- but because a DROP is
-- the one operation this schema cannot take back. An installation that did
-- put data there, by whatever route, must notice before the data is gone and
-- not afterwards. The message says what to do: export the rows, then run
-- again.
--
-- `docker/db/init/10-schema.sql` KEEPS the table, and that is not an
-- oversight. The baseline is checksum-bound in `estab_schema_baselines`;
-- editing it would fail every existing installation with "Checksum mismatch
-- for fresh schema baseline". A fresh install therefore creates the table,
-- migrations 45, 50 and 97 find it where they expect it, and this migration
-- removes it at the end -- the same sequence an existing installation walks.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE legacy_rows INTEGER DEFAULT 0;
  DECLARE legacy_present INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '129-gegenstelle-stellenart.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Legacy plan removal blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO legacy_present
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_komplan';
  IF legacy_present = 1 THEN
    SET @estab_komplan_rows = 0;
    PREPARE estab_komplan_probe FROM
      'SELECT COUNT(*) INTO @estab_komplan_rows FROM `nv_komplan`';
    EXECUTE estab_komplan_probe;
    DEALLOCATE PREPARE estab_komplan_probe;
    SET legacy_rows = @estab_komplan_rows;
    IF legacy_rows <> 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Legacy plan removal blocked: nv_komplan still holds rows. Export them, then run the migration again.';
    END IF;
  END IF;
END//
DELIMITER ;

-- Erst die Waechter, dann die Tabelle. Ein Ausloeser auf einer geloeschten
-- Tabelle waere kein Fehler, aber ein Rest -- und Reste werden spaeter fuer
-- Absicht gehalten.
DROP TRIGGER IF EXISTS `estab_komplan_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_komplan_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_komplan_bd_einsatz`;
DROP TABLE IF EXISTS `nv_komplan`;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE legacy_present INTEGER DEFAULT 0;
  DECLARE legacy_triggers INTEGER DEFAULT 0;
  DECLARE current_plan INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO legacy_present
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_komplan';
  IF legacy_present <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Legacy plan removal failed: nv_komplan still exists';
  END IF;

  SELECT COUNT(*) INTO legacy_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_komplan_bi_einsatz',
       'estab_komplan_bu_einsatz',
       'estab_komplan_bd_einsatz'
     );
  IF legacy_triggers <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Legacy plan removal failed: a trigger of the retired table remains';
  END IF;

  -- Der Ersatz muss stehen, bevor das Alte geht. Sonst hinterliesse die
  -- Migration eine Anwendung ganz ohne Fernmeldeplan.
  SELECT COUNT(*) INTO current_plan
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_fernmeldeplaene',
       'nv_fernmeldeplan_eintraege',
       'nv_fernmeldeplan_gegenstellen',
       'nv_fernmeldewege',
       'nv_fernmeldeweg_zuordnung'
     );
  IF current_plan <> 5 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Legacy plan removal failed: the current plan schema is incomplete';
  END IF;
END//
DELIMITER ;
