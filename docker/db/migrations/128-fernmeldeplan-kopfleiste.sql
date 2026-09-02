-- The three-part header Fb Fü 76 asks for.
--
-- Q6, Allgemeines: the left box carries the issuing agency and, where
-- applicable, the function of whoever drew the sketch; the middle box the kind
-- of sketch and the area it is used for; the right box the validity note and
-- the classification note. Issuer and draughtsman sign for correctness with
-- their appointment (F.d.R.).
--
-- Three of those eStab already had under different names -- `herkunft`,
-- `einsatzbezeichnung`, `gueltig_ab`/`gueltig_bis`. Renaming a column to fix a
-- label would rewrite released rows for nothing, so the labels change in the
-- view and the columns keep their names. What was genuinely missing gets a
-- column here.
--
-- `vs_vermerk` is deliberately NULL-able and carries NO default.
--
-- A default would write "NfD" into every plan version already released --
-- versions that never said it. A released version must keep saying exactly
-- what it said; NULL is the honest record of "not stated". New drafts get the
-- pre-fill in the form, where a pre-fill belongs: the S6 sees it, can change
-- it, and what is stored is then his statement and not the migration's.
--
-- No password field. The PDV 800 communication-plan pattern (Annex 1) carries
-- one; Fb Fü 76 does not, and for the THW communication plan Fb Fü 76 is the
-- nearer document. Recorded here so the omission reads as a decision -- and
-- written without the column name on purpose, so the contract gate can prove
-- the absence by searching for the identifier itself.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '127-eingangsweg.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Header migration blocked: predecessor ledger is missing';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_128_kopfleiste;
DELIMITER //
CREATE PROCEDURE estab_migrate_128_kopfleiste()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplaene'
       AND column_name = 'verfasser_funktion'
  ) THEN
    ALTER TABLE `nv_fernmeldeplaene`
      ADD COLUMN `verfasser_funktion` VARCHAR(120) NULL
        AFTER `herkunft`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplaene'
       AND column_name = 'vs_vermerk'
  ) THEN
    ALTER TABLE `nv_fernmeldeplaene`
      ADD COLUMN `vs_vermerk` VARCHAR(40) NULL
        AFTER `gueltig_bis`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplaene'
       AND column_name = 'freigabe_dienststellung'
  ) THEN
    ALTER TABLE `nv_fernmeldeplaene`
      ADD COLUMN `freigabe_dienststellung` VARCHAR(120) NULL
        AFTER `freigegeben_von`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_128_kopfleiste();
DROP PROCEDURE estab_migrate_128_kopfleiste;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE header_columns INTEGER DEFAULT 0;
  DECLARE stamped_rows INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO header_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplaene'
     AND column_name IN (
       'verfasser_funktion',
       'vs_vermerk',
       'freigabe_dienststellung'
     )
     AND is_nullable = 'YES';
  IF header_columns <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Header migration failed: the header columns are incomplete';
  END IF;

  -- Keine freigegebene Fassung darf durch diese Migration eine Aussage
  -- bekommen, die sie nie getroffen hat.
  SELECT COUNT(*) INTO stamped_rows
    FROM `nv_fernmeldeplaene`
   WHERE `vs_vermerk` IS NOT NULL
      OR `verfasser_funktion` IS NOT NULL
      OR `freigabe_dienststellung` IS NOT NULL;
  IF stamped_rows <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Header migration failed: an existing plan version was given a value';
  END IF;
END//
DELIMITER ;
