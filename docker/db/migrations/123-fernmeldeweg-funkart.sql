-- Separate analogue and digital radio on a telecommunications route.
--
-- "Analogfunk nutzt Kanäle. Digitalfunk nutzt Rufgruppen." (Bereichsausbildung
-- Sprechfunk, Rufgruppenbildung, Folie 3; the regulation behind it is THW-DV
-- 1-820 with the NBHB THW.) Until now both shared one field labelled "Kanal
-- oder Rufgruppe", and `bandlage` applied to every radio route although a
-- digital one has no band position at all.
--
-- The transmission medium itself stays exactly as the message form prints it:
-- Fe, Fu, Me, FAX, FS, @. It is the tick-box row of Feld 1, and a disposed
-- route writes that field without a translation table in between. Everything
-- finer therefore sits BESIDE the medium, never inside it -- `funkart` decides
-- which technical fields a radio route carries.
--
-- All columns are nullable, and that is deliberate for the existing stock:
-- an old `Fu` route keeps `funkart` NULL, which reads as "undetermined". The
-- application marks such a route as legacy and asks the S6 the next time it is
-- edited. Guessing whether a route from last year was analogue or digital
-- would put an invention into a released operating document.
--
-- Adding a column is DDL and fires no row trigger, so
-- `estab_dv94_fernmeldeplan_entry_update` stays untouched: no row of a
-- released plan changes here. See migration 122 for the same reasoning applied
-- to the route identity.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE required_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '122-fernmeldeweg-identitaet.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Radio-kind migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO required_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name IN ('medium', 'kanal', 'bandlage', 'verkehrsform');
  IF required_columns <> 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Radio-kind migration blocked: predecessor columns are missing';
  END IF;
END//
DELIMITER ;

-- Idempotent per column: a retry after a partial run must not fail on the
-- columns it already created.
DROP PROCEDURE IF EXISTS estab_migrate_123_columns;
DELIMITER //
CREATE PROCEDURE estab_migrate_123_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'funkart'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `funkart` ENUM('ANALOG','DIGITAL') NULL AFTER `medium`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'band'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `band` ENUM('2m','4m') NULL AFTER `funkart`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'relaisstelle'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `relaisstelle` VARCHAR(64) NULL AFTER `verkehrsform`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'betriebsart'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `betriebsart` ENUM('TMO','DMO') NULL AFTER `relaisstelle`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'rufgruppe'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `rufgruppe` VARCHAR(64) NULL AFTER `betriebsart`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'anschlussart'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `anschlussart` ENUM('AMT','NST','MOBIL','SONDER') NULL
        AFTER `rufgruppe`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_fernmeldeplan_eintraege'
       AND column_name = 'datenart'
  ) THEN
    ALTER TABLE `nv_fernmeldeplan_eintraege`
      ADD COLUMN `datenart`
        ENUM('MAIL','MESSENGER','FACHANW','INTERNET') NULL
        AFTER `anschlussart`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_123_columns();
DROP PROCEDURE estab_migrate_123_columns;

-- Postflight. `bandlage` and `verkehrsform` stay VARCHAR and unchecked: the
-- operator decided that both are free text with a filling aid, so the
-- application never rejects a value there. The regulation that would carry
-- the value lists (PDV 810.2) is classified and not available; inventing one
-- in the schema would be worse than the freedom.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE new_columns INTEGER DEFAULT 0;
  DECLARE free_text_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO new_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name IN (
       'funkart', 'band', 'relaisstelle', 'betriebsart',
       'rufgruppe', 'anschlussart', 'datenart'
     )
     AND is_nullable = 'YES';
  IF new_columns <> 7 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Radio-kind migration failed: technical route columns are incomplete';
  END IF;

  SELECT COUNT(*) INTO free_text_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_eintraege'
     AND column_name IN ('bandlage', 'verkehrsform')
     AND data_type = 'varchar';
  IF free_text_columns <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Radio-kind migration failed: band position or traffic form is no longer free text';
  END IF;
END//
DELIMITER ;
