-- Configurable password policy for application accounts.
--
-- The canonical defaults reproduce the released behaviour exactly: at least
-- 12 Unicode characters, without mandatory composition classes. The policy is
-- prospective; existing password hashes remain valid until an administrator
-- creates or resets a credential.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_113_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_113_preflight()
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
        'Password-policy migration blocked: audit table is missing';
  END IF;

  SELECT COUNT(*) INTO conflicting_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_kennwortrichtlinie'
     AND (
       table_type <> 'BASE TABLE'
       OR engine <> 'InnoDB'
       OR table_collation <> 'utf8mb4_unicode_ci'
       OR table_comment <>
          'estab:migration:113:password-policy:v1'
     );
  IF conflicting_tables <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Password-policy migration blocked: foreign table collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_113_preflight();
DROP PROCEDURE estab_migrate_113_preflight;

CREATE TABLE IF NOT EXISTS `nv_kennwortrichtlinie` (
  `singleton_id` TINYINT UNSIGNED NOT NULL
    COMMENT 'estab:migration:113:singleton:v1',
  `minimum_length` SMALLINT UNSIGNED NOT NULL DEFAULT 12
    COMMENT 'estab:migration:113:minimum-length:v1',
  `require_uppercase` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:113:require-uppercase:v1',
  `require_lowercase` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:113:require-lowercase:v1',
  `require_digit` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:113:require-digit:v1',
  `require_symbol` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:113:require-symbol:v1',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:113:revision:v1',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    COMMENT 'estab:migration:113:updated-at:v1',
  `updated_by` VARCHAR(128) NOT NULL DEFAULT 'migration-113'
    COMMENT 'estab:migration:113:updated-by:v1',
  PRIMARY KEY (`singleton_id`),
  CONSTRAINT `chk_kennwortrichtlinie_singleton`
    CHECK (`singleton_id` = 1),
  CONSTRAINT `chk_kennwortrichtlinie_minimum`
    CHECK (`minimum_length` BETWEEN 8 AND 128),
  CONSTRAINT `chk_kennwortrichtlinie_uppercase`
    CHECK (`require_uppercase` IN (0, 1)),
  CONSTRAINT `chk_kennwortrichtlinie_lowercase`
    CHECK (`require_lowercase` IN (0, 1)),
  CONSTRAINT `chk_kennwortrichtlinie_digit`
    CHECK (`require_digit` IN (0, 1)),
  CONSTRAINT `chk_kennwortrichtlinie_symbol`
    CHECK (`require_symbol` IN (0, 1)),
  CONSTRAINT `chk_kennwortrichtlinie_actor`
    CHECK (CHAR_LENGTH(`updated_by`) BETWEEN 1 AND 128)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:113:password-policy:v1';

DROP PROCEDURE IF EXISTS estab_migrate_113_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_113_validate(IN require_row TINYINT UNSIGNED)
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
     AND table_name = 'nv_kennwortrichtlinie'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment =
       'estab:migration:113:password-policy:v1';

  SELECT COUNT(*) INTO total_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_kennwortrichtlinie';
  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_kennwortrichtlinie'
     AND is_nullable = 'NO'
     AND extra = ''
     AND (
       (`column_name` = 'singleton_id'
        AND ordinal_position = 1
        AND data_type = 'tinyint'
        AND column_type LIKE 'tinyint%unsigned'
        AND column_default IS NULL
        AND column_comment = 'estab:migration:113:singleton:v1')
       OR (`column_name` = 'minimum_length'
        AND ordinal_position = 2
        AND data_type = 'smallint'
        AND column_type LIKE 'smallint%unsigned'
        AND column_default = '12'
        AND column_comment = 'estab:migration:113:minimum-length:v1')
       OR (`column_name` = 'require_uppercase'
        AND ordinal_position = 3
        AND data_type = 'tinyint'
        AND column_type LIKE 'tinyint%unsigned'
        AND column_default = '0'
        AND column_comment = 'estab:migration:113:require-uppercase:v1')
       OR (`column_name` = 'require_lowercase'
        AND ordinal_position = 4
        AND data_type = 'tinyint'
        AND column_type LIKE 'tinyint%unsigned'
        AND column_default = '0'
        AND column_comment = 'estab:migration:113:require-lowercase:v1')
       OR (`column_name` = 'require_digit'
        AND ordinal_position = 5
        AND data_type = 'tinyint'
        AND column_type LIKE 'tinyint%unsigned'
        AND column_default = '0'
        AND column_comment = 'estab:migration:113:require-digit:v1')
       OR (`column_name` = 'require_symbol'
        AND ordinal_position = 6
        AND data_type = 'tinyint'
        AND column_type LIKE 'tinyint%unsigned'
        AND column_default = '0'
        AND column_comment = 'estab:migration:113:require-symbol:v1')
       OR (`column_name` = 'revision'
        AND ordinal_position = 7
        AND data_type = 'bigint'
        AND column_type LIKE 'bigint%unsigned'
        AND column_default = '0'
        AND column_comment = 'estab:migration:113:revision:v1')
       OR (`column_name` = 'updated_at'
        AND ordinal_position = 8
        AND data_type = 'datetime'
        AND datetime_precision = 6
        AND LOWER(column_default) = 'current_timestamp(6)'
        AND column_comment = 'estab:migration:113:updated-at:v1')
       OR (`column_name` = 'updated_by'
        AND ordinal_position = 9
        AND data_type = 'varchar'
        AND column_type = 'varchar(128)'
        AND character_set_name = 'utf8mb4'
        AND collation_name = 'utf8mb4_unicode_ci'
        AND column_default = '''migration-113'''
        AND column_comment = 'estab:migration:113:updated-by:v1')
     );

  SELECT COUNT(*) INTO total_constraints
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'nv_kennwortrichtlinie';

  SELECT COUNT(*) INTO canonical_primary_keys
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_kennwortrichtlinie'
     AND index_name = 'PRIMARY'
     AND non_unique = 0
     AND seq_in_index = 1
     AND column_name = 'singleton_id'
     AND (SELECT COUNT(*)
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'nv_kennwortrichtlinie'
             AND index_name = 'PRIMARY') = 1;

  -- A migration-owned name alone is not proof of ownership. MariaDB exposes
  -- CHECK clauses canonically; compare hashes after removing only identifier
  -- quotes and spaces so a foreign constraint cannot masquerade as ours.
  SELECT COUNT(*) INTO canonical_checks
    FROM information_schema.table_constraints AS table_constraint
    JOIN information_schema.check_constraints AS check_constraint
      ON check_constraint.constraint_schema =
           table_constraint.constraint_schema
     AND check_constraint.constraint_name =
           table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.table_name = 'nv_kennwortrichtlinie'
     AND table_constraint.constraint_type = 'CHECK'
     AND (
       (table_constraint.constraint_name =
          'chk_kennwortrichtlinie_singleton'
        AND SHA2(REPLACE(REPLACE(
              LOWER(check_constraint.check_clause), '`', ''), ' ', ''), 256) =
          '88d8e657608a68a0d7a33ff0ac962b4fab9455b1757c39014a936c02860da7b0')
       OR (table_constraint.constraint_name =
             'chk_kennwortrichtlinie_minimum'
        AND SHA2(REPLACE(REPLACE(
              LOWER(check_constraint.check_clause), '`', ''), ' ', ''), 256) =
          'd891c2a2c3207579bdd7250dbe1be18004071d459d407e8c25fa548ef218737b')
       OR (table_constraint.constraint_name =
             'chk_kennwortrichtlinie_uppercase'
        AND SHA2(REPLACE(REPLACE(
              LOWER(check_constraint.check_clause), '`', ''), ' ', ''), 256) =
          'e59fa8c23e29f1518377e8f0af1efda61c2b18eab331c022ad564af71c851918')
       OR (table_constraint.constraint_name =
             'chk_kennwortrichtlinie_lowercase'
        AND SHA2(REPLACE(REPLACE(
              LOWER(check_constraint.check_clause), '`', ''), ' ', ''), 256) =
          '7e3867c98f272ca14b63ed3b662cf871d1ea87d523647150708f1328d50d9ffd')
       OR (table_constraint.constraint_name =
             'chk_kennwortrichtlinie_digit'
        AND SHA2(REPLACE(REPLACE(
              LOWER(check_constraint.check_clause), '`', ''), ' ', ''), 256) =
          'df346c79167b8ec0ea4a56b4bb3881917bc40498456e298e2719fa747da100b2')
       OR (table_constraint.constraint_name =
             'chk_kennwortrichtlinie_symbol'
        AND SHA2(REPLACE(REPLACE(
              LOWER(check_constraint.check_clause), '`', ''), ' ', ''), 256) =
          '53b28b592d7ff74397ccec21df0b48202d16872dd4f5fcfa5e00cbdef4023f95')
       OR (table_constraint.constraint_name =
             'chk_kennwortrichtlinie_actor'
        AND SHA2(REPLACE(REPLACE(
              LOWER(check_constraint.check_clause), '`', ''), ' ', ''), 256) =
          'ae6394da3dd78dde0b7007b20b1a305efe47cee502be7c57ebd44812f3214338')
     );

  IF canonical_tables <> 1
     OR total_columns <> 9
     OR canonical_columns <> 9
     OR total_constraints <> 8
     OR canonical_primary_keys <> 1
     OR canonical_checks <> 7 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Password-policy migration blocked: foreign table collision';
  END IF;

  IF require_row = 1 THEN
    SELECT COUNT(*) INTO canonical_rows
      FROM `nv_kennwortrichtlinie`
     WHERE `singleton_id` = 1
       AND `minimum_length` BETWEEN 8 AND 128
       AND `require_uppercase` IN (0, 1)
       AND `require_lowercase` IN (0, 1)
       AND `require_digit` IN (0, 1)
       AND `require_symbol` IN (0, 1)
       AND CHAR_LENGTH(`updated_by`) BETWEEN 1 AND 128;

    IF canonical_rows <> 1
       OR (SELECT COUNT(*) FROM `nv_kennwortrichtlinie`) <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Password-policy migration failed canonical row validation';
    END IF;
  END IF;
END//
DELIMITER ;

-- CREATE TABLE is atomic, but the process can stop before the seed INSERT.
-- Validate the exact owned table first, then seed only an absent singleton.
CALL estab_migrate_113_validate(0);

INSERT INTO `nv_kennwortrichtlinie`
  (`singleton_id`, `minimum_length`, `require_uppercase`,
   `require_lowercase`, `require_digit`, `require_symbol`, `revision`,
   `updated_at`, `updated_by`)
VALUES
  (1, 12, 0, 0, 0, 0, 0, UTC_TIMESTAMP(6), 'migration-113')
ON DUPLICATE KEY UPDATE `singleton_id` = VALUES(`singleton_id`);

CALL estab_migrate_113_validate(1);
DROP PROCEDURE estab_migrate_113_validate;
