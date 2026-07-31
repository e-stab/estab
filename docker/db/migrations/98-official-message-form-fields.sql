-- Persist the two independent fields required by the official message form:
-- the counterparty telephone number and the subject. Historical messages keep
-- every existing value and receive only the explicit empty defaults.
--
-- The migration accepts either a missing field or its exact migration-owned
-- definition. This makes every autocommitted ADD COLUMN phase resumable while
-- refusing to adopt or rewrite a same-name foreign column.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_98_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_98_preflight()
BEGIN
  DECLARE canonical_table INTEGER DEFAULT 0;
  DECLARE required_anchor_columns INTEGER DEFAULT 0;
  DECLARE existing_number_columns INTEGER DEFAULT 0;
  DECLARE canonical_number_columns INTEGER DEFAULT 0;
  DECLARE existing_subject_columns INTEGER DEFAULT 0;
  DECLARE canonical_subject_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci';
  IF canonical_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Official message fields migration blocked: message table is missing or incompatible';
  END IF;

  SELECT COUNT(*) INTO required_anchor_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name IN (
       '10_anschrift',
       '11_gesprnotiz',
       '12_anhang'
     );
  IF required_anchor_columns <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Official message fields migration blocked: required legacy form column is missing';
  END IF;

  SELECT COUNT(*) INTO existing_number_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = '11_rufnummer';

  SELECT COUNT(*) INTO canonical_number_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = '11_rufnummer'
     AND data_type = 'varchar'
     AND column_type = 'varchar(128)'
     AND character_maximum_length = 128
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'NO'
     AND HEX(column_default) = '2727'
     AND extra = ''
     AND column_comment =
       'estab:migration:98:message-counterparty-number:v1';
  IF existing_number_columns <> canonical_number_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Official message fields migration blocked: foreign counterparty-number column collision';
  END IF;

  SELECT COUNT(*) INTO existing_subject_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = '12_betreff';

  SELECT COUNT(*) INTO canonical_subject_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = '12_betreff'
     AND data_type = 'varchar'
     AND column_type = 'varchar(255)'
     AND character_maximum_length = 255
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'NO'
     AND HEX(column_default) = '2727'
     AND extra = ''
     AND column_comment = 'estab:migration:98:message-subject:v1';
  IF existing_subject_columns <> canonical_subject_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Official message fields migration blocked: foreign subject column collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_98_preflight();
DROP PROCEDURE estab_migrate_98_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_98_add_counterparty_number;
DELIMITER //
CREATE PROCEDURE estab_migrate_98_add_counterparty_number()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = '11_rufnummer'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `11_rufnummer` VARCHAR(128)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT ''
        COMMENT 'estab:migration:98:message-counterparty-number:v1'
        AFTER `10_anschrift`;
  ELSE
    ALTER TABLE `nv_nachrichten`
      MODIFY COLUMN `11_rufnummer` VARCHAR(128)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT ''
        COMMENT 'estab:migration:98:message-counterparty-number:v1'
        AFTER `10_anschrift`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_98_add_counterparty_number();
DROP PROCEDURE estab_migrate_98_add_counterparty_number;

DROP PROCEDURE IF EXISTS estab_migrate_98_add_subject;
DELIMITER //
CREATE PROCEDURE estab_migrate_98_add_subject()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = '12_betreff'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `12_betreff` VARCHAR(255)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT ''
        COMMENT 'estab:migration:98:message-subject:v1'
        AFTER `11_gesprnotiz`;
  ELSE
    ALTER TABLE `nv_nachrichten`
      MODIFY COLUMN `12_betreff` VARCHAR(255)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT ''
        COMMENT 'estab:migration:98:message-subject:v1'
        AFTER `11_gesprnotiz`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_98_add_subject();
DROP PROCEDURE estab_migrate_98_add_subject;

DROP PROCEDURE IF EXISTS estab_migrate_98_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_98_validate()
BEGIN
  IF (
       SELECT COUNT(*)
         FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_nachrichten'
          AND column_name = '11_rufnummer'
          AND data_type = 'varchar'
          AND column_type = 'varchar(128)'
          AND character_maximum_length = 128
          AND character_set_name = 'utf8mb4'
          AND collation_name = 'utf8mb4_unicode_ci'
          AND is_nullable = 'NO'
          AND HEX(column_default) = '2727'
          AND extra = ''
          AND column_comment =
            'estab:migration:98:message-counterparty-number:v1'
     ) <> 1
     OR (
       SELECT COUNT(*)
         FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_nachrichten'
          AND column_name = '12_betreff'
          AND data_type = 'varchar'
          AND column_type = 'varchar(255)'
          AND character_maximum_length = 255
          AND character_set_name = 'utf8mb4'
          AND collation_name = 'utf8mb4_unicode_ci'
          AND is_nullable = 'NO'
          AND HEX(column_default) = '2727'
          AND extra = ''
          AND column_comment = 'estab:migration:98:message-subject:v1'
     ) <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Official message fields migration failed: final schema is not canonical';
  END IF;

  IF (
       SELECT COUNT(*)
         FROM information_schema.columns AS address_column
         JOIN information_schema.columns AS number_column
           ON number_column.table_schema = address_column.table_schema
          AND number_column.table_name = address_column.table_name
          AND number_column.column_name = '11_rufnummer'
          AND number_column.ordinal_position =
              address_column.ordinal_position + 1
         JOIN information_schema.columns AS note_column
           ON note_column.table_schema = address_column.table_schema
          AND note_column.table_name = address_column.table_name
          AND note_column.column_name = '11_gesprnotiz'
          AND note_column.ordinal_position = number_column.ordinal_position + 1
         JOIN information_schema.columns AS subject_column
           ON subject_column.table_schema = address_column.table_schema
          AND subject_column.table_name = address_column.table_name
          AND subject_column.column_name = '12_betreff'
          AND subject_column.ordinal_position = note_column.ordinal_position + 1
         JOIN information_schema.columns AS attachment_column
           ON attachment_column.table_schema = address_column.table_schema
          AND attachment_column.table_name = address_column.table_name
          AND attachment_column.column_name = '12_anhang'
          AND attachment_column.ordinal_position =
              subject_column.ordinal_position + 1
        WHERE address_column.table_schema = DATABASE()
          AND address_column.table_name = 'nv_nachrichten'
          AND address_column.column_name = '10_anschrift'
     ) <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Official message fields migration failed: physical column order differs from baseline';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_98_validate();
DROP PROCEDURE estab_migrate_98_validate;
