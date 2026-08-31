-- Name the route field for what it holds, and say where a station stands.
--
-- `rufname` was the right word while a plan only carried radio. It is wrong
-- for a telephone number and misleading for an e-mail address, and the plan
-- now carries both. The column becomes `erreichbarkeit`: the heading a table
-- can put over every medium at once. The form keeps saying the concrete word
-- the operator knows -- Rufnummer, Funkrufname, Faxnummer -- because the paper
-- prints one line per medium and never needed a generic term.
--
-- `stellenart` answers the other half of the same question. THW-DV 1-101
-- chapter 6.1.2 requires connections vertically and horizontally; the form
-- Fb Fü 76 puts "FüSt" boxes beside "NSt" boxes. Without the field a plan
-- lists stations but not the direction they stand in.
--
-- Both are DDL. `ALTER TABLE ... CHANGE` renames without touching a row, and
-- adding a column fires no row trigger either, so
-- `estab_dv94_fernmeldeplan_entry_update` stays out of the way and no row of a
-- released plan changes. Migration 122 carries the full reasoning.
--
-- `besondere_vermerke` is NOT merged here. Neither Fb Fü 76 nor PDV 800 knows
-- two note fields, so the plan keeps one from now on -- but a released version
-- must keep saying what it said. The column stays, read-only; the version copy
-- in app/dv_operations.php folds both values into `bemerkungen` when the next
-- draft is prepared. The split disappears with the next version instead of
-- being rewritten into the last one.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE source_column INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '123-fernmeldeweg-funkart.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Reachability migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO source_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name IN ('rufname', 'erreichbarkeit');
  IF source_column <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Reachability migration blocked: the route field is ambiguous';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_124_felder;
DELIMITER //
CREATE PROCEDURE estab_migrate_124_felder()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'rufname'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      CHANGE COLUMN `rufname` `erreichbarkeit`
        VARCHAR(255) NOT NULL DEFAULT '';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'stellenart'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `stellenart` ENUM('EIGEN','UEBER','UNTER','NEBEN') NULL
        AFTER `betriebsstelle`;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'besondere_vermerke'
       AND is_nullable = 'NO'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      MODIFY COLUMN `besondere_vermerke` TEXT NULL;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_124_felder();
DROP PROCEDURE estab_migrate_124_felder;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE renamed INTEGER DEFAULT 0;
  DECLARE station_kind INTEGER DEFAULT 0;
  DECLARE legacy_notes INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO renamed
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name = 'erreichbarkeit'
     AND data_type = 'varchar'
     AND character_maximum_length = 255;
  IF renamed <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Reachability migration failed: the route field was not renamed';
  END IF;

  SELECT COUNT(*) INTO station_kind
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name = 'stellenart'
     AND is_nullable = 'YES';
  IF station_kind <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Reachability migration failed: the station kind is missing';
  END IF;

  -- Der Altbestand behaelt seinen zweiten Vermerk. Wer ihn hier
  -- zusammenfuehrte, schriebe eine freigegebene Fassung um.
  SELECT COUNT(*) INTO legacy_notes
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name = 'besondere_vermerke';
  IF legacy_notes <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Reachability migration failed: the legacy note column was removed';
  END IF;
END//
DELIMITER ;
