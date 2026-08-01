-- Persistent self-registration policy with upgrade-compatible ENV fallback.
--
-- ENVIRONMENT preserves ESTAB_ALLOW_SELF_REGISTRATION until an administrator
-- explicitly saves DISABLED, PERMANENT or UNTIL. Timed deadlines are stored
-- and evaluated as database UTC; expiration therefore needs no background job.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_114_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_114_preflight()
BEGIN
  DECLARE protocol_tables INTEGER DEFAULT 0;
  DECLARE conflicting_tables INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO protocol_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_protokoll'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB';
  IF protocol_tables <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Self-registration migration blocked: audit table is missing';
  END IF;

  SELECT COUNT(*) INTO conflicting_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_selbstregistrierung'
     AND (
       table_type <> 'BASE TABLE'
       OR engine <> 'InnoDB'
       OR table_collation <> 'utf8mb4_unicode_ci'
       OR table_comment <>
          'estab:migration:114:self-registration-policy:v1'
     );
  IF conflicting_tables <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Self-registration migration blocked: foreign table collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_114_preflight();
DROP PROCEDURE estab_migrate_114_preflight;

CREATE TABLE IF NOT EXISTS `nv_selbstregistrierung` (
  `singleton_id` TINYINT UNSIGNED NOT NULL
    COMMENT 'estab:migration:114:singleton:v1',
  `mode` ENUM('ENVIRONMENT','DISABLED','PERMANENT','UNTIL')
    CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ENVIRONMENT'
    COMMENT 'estab:migration:114:mode:v1',
  `enabled_until_utc` DATETIME(6) NULL DEFAULT NULL
    COMMENT 'estab:migration:114:enabled-until-utc:v1',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:114:revision:v1',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    COMMENT 'estab:migration:114:updated-at:v1',
  `updated_by` VARCHAR(128) NOT NULL DEFAULT 'migration-114'
    COMMENT 'estab:migration:114:updated-by:v1',
  PRIMARY KEY (`singleton_id`),
  CONSTRAINT `chk_selbstregistrierung_singleton`
    CHECK (`singleton_id` = 1),
  CONSTRAINT `chk_selbstregistrierung_deadline`
    CHECK (
      (`mode` = 'UNTIL' AND `enabled_until_utc` IS NOT NULL)
      OR (`mode` <> 'UNTIL' AND `enabled_until_utc` IS NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:114:self-registration-policy:v1';

DROP PROCEDURE IF EXISTS estab_migrate_114_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_114_validate(IN require_row TINYINT UNSIGNED)
BEGIN
  DECLARE canonical_tables INTEGER DEFAULT 0;
  DECLARE total_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE total_constraints INTEGER DEFAULT 0;
  DECLARE canonical_primary_keys INTEGER DEFAULT 0;
  DECLARE canonical_checks INTEGER DEFAULT 0;
  DECLARE canonical_rows INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_selbstregistrierung'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment =
       'estab:migration:114:self-registration-policy:v1';

  SELECT COUNT(*) INTO total_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_selbstregistrierung';
  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_selbstregistrierung'
     AND extra = ''
     AND (
       (`column_name` = 'singleton_id'
        AND ordinal_position = 1
        AND is_nullable = 'NO'
        AND data_type = 'tinyint'
        AND column_type LIKE 'tinyint%unsigned'
        AND column_default IS NULL
        AND column_comment = 'estab:migration:114:singleton:v1')
       OR (`column_name` = 'mode'
        AND ordinal_position = 2
        AND is_nullable = 'NO'
        AND data_type = 'enum'
        AND column_type =
          'enum(''ENVIRONMENT'',''DISABLED'',''PERMANENT'',''UNTIL'')'
        AND character_set_name = 'ascii'
        AND collation_name = 'ascii_bin'
        AND column_default = '''ENVIRONMENT'''
        AND column_comment = 'estab:migration:114:mode:v1')
       OR (`column_name` = 'enabled_until_utc'
        AND ordinal_position = 3
        AND is_nullable = 'YES'
        AND data_type = 'datetime'
        AND datetime_precision = 6
        AND column_default = 'NULL'
        AND column_comment =
          'estab:migration:114:enabled-until-utc:v1')
       OR (`column_name` = 'revision'
        AND ordinal_position = 4
        AND is_nullable = 'NO'
        AND data_type = 'bigint'
        AND column_type LIKE 'bigint%unsigned'
        AND column_default = '0'
        AND column_comment = 'estab:migration:114:revision:v1')
       OR (`column_name` = 'updated_at'
        AND ordinal_position = 5
        AND is_nullable = 'NO'
        AND data_type = 'datetime'
        AND datetime_precision = 6
        AND LOWER(column_default) = 'current_timestamp(6)'
        AND column_comment = 'estab:migration:114:updated-at:v1')
       OR (`column_name` = 'updated_by'
        AND ordinal_position = 6
        AND is_nullable = 'NO'
        AND data_type = 'varchar'
        AND column_type = 'varchar(128)'
        AND character_set_name = 'utf8mb4'
        AND collation_name = 'utf8mb4_unicode_ci'
        AND column_default = '''migration-114'''
        AND column_comment = 'estab:migration:114:updated-by:v1')
     );

  SELECT COUNT(*) INTO total_constraints
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'nv_selbstregistrierung';
  SELECT COUNT(*) INTO canonical_primary_keys
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_selbstregistrierung'
     AND index_name = 'PRIMARY'
     AND non_unique = 0
     AND seq_in_index = 1
     AND column_name = 'singleton_id'
     AND (SELECT COUNT(*)
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_selbstregistrierung'
             AND index_name = 'PRIMARY') = 1;

  SELECT COUNT(*) INTO canonical_checks
    FROM information_schema.table_constraints AS table_constraint
    JOIN information_schema.check_constraints AS check_constraint
      ON check_constraint.constraint_schema =
           table_constraint.constraint_schema
     AND check_constraint.constraint_name =
           table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.table_name = 'nv_selbstregistrierung'
     AND table_constraint.constraint_type = 'CHECK'
     AND CONCAT(
       table_constraint.constraint_name,
       ':',
       SHA2(
         REPLACE(
           REPLACE(LOWER(check_constraint.check_clause), '`', ''),
           ' ',
           ''
         ),
         256
       )
     ) IN (
       'chk_selbstregistrierung_singleton:88d8e657608a68a0d7a33ff0ac962b4fab9455b1757c39014a936c02860da7b0',
       'chk_selbstregistrierung_deadline:fffe6017aa7f7ac8e796ce0cf73e1d20ab0f7499bf021107c9a824c00907eba4'
     );

  IF canonical_tables <> 1
     OR total_columns <> 6
     OR canonical_columns <> 6
     OR total_constraints <> 3
     OR canonical_primary_keys <> 1
     OR canonical_checks <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Self-registration migration blocked: foreign table collision';
  END IF;

  IF require_row = 1 THEN
    SELECT COUNT(*) INTO canonical_rows
      FROM `nv_selbstregistrierung`
     WHERE `singleton_id` = 1
       AND `mode` IN ('ENVIRONMENT','DISABLED','PERMANENT','UNTIL')
       AND (
         (`mode` = 'UNTIL' AND `enabled_until_utc` IS NOT NULL)
         OR (`mode` <> 'UNTIL' AND `enabled_until_utc` IS NULL)
       )
       AND CHAR_LENGTH(`updated_by`) BETWEEN 1 AND 128;
    IF canonical_rows <> 1
       OR (SELECT COUNT(*) FROM `nv_selbstregistrierung`) <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Self-registration migration failed canonical row validation';
    END IF;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_114_validate(0);

INSERT INTO `nv_selbstregistrierung`
  (`singleton_id`, `mode`, `enabled_until_utc`, `revision`,
   `updated_at`, `updated_by`)
VALUES
  (1, 'ENVIRONMENT', NULL, 0, UTC_TIMESTAMP(6), 'migration-114')
ON DUPLICATE KEY UPDATE `singleton_id` = VALUES(`singleton_id`);

CALL estab_migrate_114_validate(1);
DROP PROCEDURE estab_migrate_114_validate;
