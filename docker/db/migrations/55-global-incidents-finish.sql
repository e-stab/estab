-- Finish the immutable global-incident migration.
--
-- Migration 45 temporarily removed ON UPDATE from the two legacy timestamp
-- columns so migration 50 could backfill einsatz_id without changing historic
-- timestamps. Restore the exact runtime definitions only after that backfill.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Both the prepared and the final canonical states are accepted so a manually
-- audited retry is safe when non-transactional DDL stopped between ALTERs.
-- Any other definition remains fail-closed before the first change.
DROP PROCEDURE IF EXISTS estab_migrate_55_finish_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_55_finish_preflight()
BEGIN
  DECLARE timestamp_columns INTEGER DEFAULT 0;
  DECLARE compatible_timestamp_columns INTEGER DEFAULT 0;

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
        'Incident finish migration blocked: incompatible timestamp column';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_55_finish_preflight();
DROP PROCEDURE estab_migrate_55_finish_preflight;

ALTER TABLE `nv_nachrichten`
  MODIFY COLUMN `99_lstacc`
    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `nv_bhp50`
  MODIFY COLUMN `sich1_zeit`
    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

DROP PROCEDURE IF EXISTS estab_migrate_55_finish_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_55_finish_validate()
BEGIN
  DECLARE restored_timestamp_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO restored_timestamp_columns
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
     AND LOWER(extra) = 'on update current_timestamp()'
     AND COALESCE(generation_expression, '') = '';

  IF restored_timestamp_columns <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incident finish migration incomplete: ON UPDATE definition was not restored';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_55_finish_validate();
DROP PROCEDURE estab_migrate_55_finish_validate;
