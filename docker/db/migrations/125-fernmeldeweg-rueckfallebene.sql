-- Let a route say which other route it stands in for.
--
-- PDV 800 Anlage 20 defines the Rückfallebene as the "Ersatz für eine
-- IuK-Verbindung, ggf. auch unter Inkaufnahme einer Leistungsbeschränkung",
-- and number 3 requires communication plans to be drawn up "erforderlichenfalls
-- unter Berücksichtigung einer Rückfallebene". Number 2.7 says what it is for:
-- when a connection fails, the information still has to get through.
--
-- The plan therefore answers the question that is actually asked during an
-- outage -- not "is there a substitute somewhere" but "what takes the place of
-- THIS route".
--
-- One column, no separate flag. NULL means no fallback; a value means fallback
-- for the route with that identity. A second boolean would exist only so it
-- could contradict the reference -- ticked without a target, target without a
-- tick. That state cannot arise here.
--
-- The reference points at the durable route identity of migration 122, not at
-- a row: the version copy re-inserts every route, so a row reference would aim
-- at the previous version after the first revision. The composite foreign key
-- carries `fernmeldeplan_id` along, so a route can only fall back to a route
-- of the SAME plan version -- the database refuses to look across versions.
--
-- RESTRICT, not SET NULL. The comfortable variant lets the substitute lose its
-- reference and nobody notices. Whoever deletes the main route decides about
-- its substitute too.
--
-- Self-reference and cycles are refused by the application: the entry does not
-- carry its own identity (it lives in the mapping table), so a column CHECK
-- cannot see it. Chains stay allowed -- a substitute may have a substitute.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE target_key INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '124-fernmeldeweg-erreichbarkeit.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Fallback migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO target_key
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeweg_zuordnung'
     AND index_name = 'uq_fernmeldeweg_zuordnung_plan';
  IF target_key <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Fallback migration blocked: the identity key is missing';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_125_rueckfallebene;
DELIMITER //
CREATE PROCEDURE estab_migrate_125_rueckfallebene()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'rueckfallebene_fuer_weg'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `rueckfallebene_fuer_weg` BIGINT UNSIGNED NULL
        AFTER `erreichbarkeit`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.table_constraints
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND constraint_name = 'fk_fernmeldeweg_rueckfallebene'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD CONSTRAINT `fk_fernmeldeweg_rueckfallebene`
        FOREIGN KEY (`fernmeldeplan_id`, `rueckfallebene_fuer_weg`)
        REFERENCES `nv_fernmeldeweg_zuordnung`
          (`fernmeldeplan_id`, `weg_id`)
        ON DELETE RESTRICT;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_125_rueckfallebene();
DROP PROCEDURE estab_migrate_125_rueckfallebene;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE fallback_column INTEGER DEFAULT 0;
  DECLARE fallback_key INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO fallback_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name = 'rueckfallebene_fuer_weg'
     AND data_type = 'bigint'
     AND is_nullable = 'YES';
  IF fallback_column <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Fallback migration failed: the fallback reference is missing';
  END IF;

  SELECT COUNT(*) INTO fallback_key
    FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name = 'fk_fernmeldeweg_rueckfallebene'
     AND delete_rule = 'RESTRICT';
  IF fallback_key <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Fallback migration failed: the reference does not refuse deletion';
  END IF;
END//
DELIMITER ;
