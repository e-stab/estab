-- The station kind belongs to the counterpart, not to our own route.
--
-- This corrects a misreading of Fb Fü 77 that ran through migration 124.
--
-- The plan records OUR OWN reachability. A plan row is one of our means --
-- our 4 m set, our mobile, our fax, our line -- carried by one of our own
-- operating positions, and `betriebsstelle` names that position. Its relation
-- to us is therefore always "our own"; asking whether it is superior or
-- subordinate is asking whether we are superior to ourselves.
--
-- Superior and subordinate are properties of the OTHER end. Fb Fü 77 draws it
-- exactly so: our command post with its call sign in the centre, the stations
-- we reach upward on the left, the stations we reach downward on the right.
-- The kind therefore moves to `nv_fernmeldeplan_gegenstellen`, where the
-- other end lives.
--
-- `nv_fernmeldeplan_eintraege`.`stellenart` is NOT dropped.
--
-- Released plan versions carry values in it, and a released version must keep
-- saying what it said -- even where what it said rested on a wrong idea. The
-- column stays readable and stops being written; it disappears the way the
-- second remark field disappears, with the next version, not by rewriting the
-- last one. Dropping it here would also fire no trigger and quietly alter
-- rows that migration 94 protects on purpose.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE counterpart_table INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '128-fernmeldeplan-kopfleiste.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Counterpart kind migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO counterpart_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_gegenstellen';
  IF counterpart_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Counterpart kind migration blocked: the counterpart table is missing';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_129_stellenart;
DELIMITER //
CREATE PROCEDURE estab_migrate_129_stellenart()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_gegenstellen'
       AND column_name = 'stellenart'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_gegenstellen`
      ADD COLUMN `stellenart` ENUM('UEBER','UNTER','NEBEN') NULL
        AFTER `name`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_129_stellenart();
DROP PROCEDURE estab_migrate_129_stellenart;

-- Die Aufzaehlung kennt die eigene Stelle nicht.
--
-- Eine Gegenstelle ist per Begriff die andere Seite; sich selbst als
-- Gegenstelle zu fuehren waere ein Widerspruch. Ein Wert, den nichts je
-- annehmen darf, gehoert nicht in eine Aufzaehlung -- er wuerde irgendwann
-- doch gesetzt und dann ausgewertet. Das Vertragstor beweist die Abwesenheit,
-- indem es nach dem Bezeichner sucht; deshalb steht er hier nicht im Text.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE counterpart_kind INTEGER DEFAULT 0;
  DECLARE own_kept INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO counterpart_kind
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_gegenstellen'
     AND column_name = 'stellenart'
     AND is_nullable = 'YES'
     AND column_type = "enum('UEBER','UNTER','NEBEN')";
  IF counterpart_kind <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Counterpart kind migration failed: the counterpart kind is missing';
  END IF;

  -- Der Weg behaelt seine Spalte. Eine freigegebene Fassung muss weiter
  -- sagen koennen, was sie gesagt hat.
  SELECT COUNT(*) INTO own_kept
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name = 'stellenart';
  IF own_kept <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Counterpart kind migration failed: a released version lost a value';
  END IF;
END//
DELIMITER ;
