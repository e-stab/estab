-- Persistent administrative account blocking.
--
-- `aktiv` is legacy online/session state and cannot represent an operator
-- decision: logout changes it back to zero. The namespaced column below keeps
-- the durable block decision separate while minimising collisions with
-- customised legacy schemas.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_70_user_account_blocking;
DELIMITER //
CREATE PROCEDURE estab_migrate_70_user_account_blocking()
BEGIN
  DECLARE matching_columns BIGINT DEFAULT 0;
  DECLARE canonical_columns BIGINT DEFAULT 0;
  DECLARE invalid_values BIGINT DEFAULT 0;

  SELECT COUNT(*) INTO matching_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND column_name = 'estab_gesperrt';

  IF matching_columns = 0 THEN
    SET @estab_migrate_70_add =
      'ALTER TABLE `nv_benutzer`
         ADD COLUMN `estab_gesperrt`
           TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `aktiv`';
    PREPARE estab_migrate_70_add_statement
       FROM @estab_migrate_70_add;
    EXECUTE estab_migrate_70_add_statement;
    DEALLOCATE PREPARE estab_migrate_70_add_statement;
    SET @estab_migrate_70_add = NULL;
  ELSE
    SELECT COUNT(*) INTO canonical_columns
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND column_name = 'estab_gesperrt'
       AND data_type = 'tinyint'
       AND column_type LIKE 'tinyint%unsigned'
       AND is_nullable = 'NO'
       AND column_default = '0'
       AND extra = '';

    IF matching_columns <> 1 OR canonical_columns <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'User blocking migration blocked: incompatible pre-existing nv_benutzer.estab_gesperrt';
    END IF;

    SELECT COUNT(*) INTO invalid_values
      FROM `nv_benutzer`
     WHERE `estab_gesperrt` NOT IN (0, 1);
    IF invalid_values <> 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'User blocking migration blocked: invalid pre-existing block values';
    END IF;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_70_user_account_blocking();
DROP PROCEDURE estab_migrate_70_user_account_blocking;
