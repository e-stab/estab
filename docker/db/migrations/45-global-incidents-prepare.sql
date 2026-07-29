-- Prepare the legacy runtime for the immutable global-incident migration.
--
-- Migration 50 backfills einsatz_id on two tables whose historic timestamp
-- columns update automatically whenever another column changes. Temporarily
-- removing ON UPDATE keeps both NULL and real historic values byte-for-byte
-- stable during that backfill. Migration 55 restores the runtime definitions.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Fail before the first ALTER when the operational namespace is incomplete or
-- either timestamp column is foreign. Accepting both canonical ON UPDATE
-- states makes a manually audited retry safe after non-transactional DDL was
-- interrupted between the two ALTER statements.
DROP PROCEDURE IF EXISTS estab_migrate_45_prepare_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_45_prepare_preflight()
BEGIN
  DECLARE required_operational_tables INTEGER DEFAULT 0;
  DECLARE timestamp_columns INTEGER DEFAULT 0;
  DECLARE compatible_timestamp_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO required_operational_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_type = 'BASE TABLE'
     AND table_name IN (
       'nv_nachrichten',
       'nv_anhang',
       'nv_etb',
       'nv_tbb',
       'nv_ubb',
       'nv_protokoll',
       'nv_bhp50',
       'nv_komplan',
       'nv_etbtitel',
       'nv_tbbtitel'
     );

  IF required_operational_tables <> 10 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incident migration blocked: required operational table is missing';
  END IF;

  SELECT COUNT(*) INTO timestamp_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'nv_nachrichten' AND column_name = '99_lstacc')
       OR
       (table_name = 'nv_bhp50' AND column_name = 'sich1_zeit')
     );

  SELECT COUNT(*) INTO compatible_timestamp_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'nv_nachrichten' AND column_name = '99_lstacc')
       OR
       (table_name = 'nv_bhp50' AND column_name = 'sich1_zeit')
     )
     AND data_type = 'timestamp'
     AND column_type = 'timestamp'
     AND is_nullable = 'YES'
     AND column_default = 'NULL'
     AND datetime_precision = 0
     AND LOWER(extra) IN ('', 'on update current_timestamp()')
     AND COALESCE(generation_expression, '') = '';

  IF timestamp_columns <> 2
     OR compatible_timestamp_columns <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incident prepare migration blocked: incompatible timestamp column';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_45_prepare_preflight();
DROP PROCEDURE estab_migrate_45_prepare_preflight;

ALTER TABLE `nv_nachrichten`
  MODIFY COLUMN `99_lstacc` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `nv_bhp50`
  MODIFY COLUMN `sich1_zeit` TIMESTAMP NULL DEFAULT NULL;

DROP PROCEDURE IF EXISTS estab_migrate_45_prepare_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_45_prepare_validate()
BEGIN
  DECLARE prepared_timestamp_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO prepared_timestamp_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'nv_nachrichten' AND column_name = '99_lstacc')
       OR
       (table_name = 'nv_bhp50' AND column_name = 'sich1_zeit')
     )
     AND data_type = 'timestamp'
     AND column_type = 'timestamp'
     AND is_nullable = 'YES'
     AND column_default = 'NULL'
     AND datetime_precision = 0
     AND extra = ''
     AND COALESCE(generation_expression, '') = '';

  IF prepared_timestamp_columns <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incident prepare migration incomplete: timestamp protection was not applied';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_45_prepare_validate();
DROP PROCEDURE estab_migrate_45_prepare_validate;
