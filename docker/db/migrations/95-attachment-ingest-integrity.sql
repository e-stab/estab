-- Strong attachment-ingest evidence.
--
-- Rows present before this migration remain explicit legacy records. Their
-- current bytes must never be presented as proof of the bytes originally
-- received. Every reservation inserted after this migration is integrity
-- required and may only become final together with immutable SHA-256, size
-- and server capture time.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_95_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_95_preflight()
BEGIN
  DECLARE attachment_tables INTEGER DEFAULT 0;
  DECLARE required_columns INTEGER DEFAULT 0;
  DECLARE md5_position INTEGER DEFAULT 0;
  DECLARE existing_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE column_mask INTEGER DEFAULT 0;
  DECLARE integrity_default VARCHAR(64) DEFAULT NULL;
  DECLARE existing_constraints INTEGER DEFAULT 0;
  DECLARE canonical_constraints INTEGER DEFAULT 0;
  DECLARE existing_triggers INTEGER DEFAULT 0;
  DECLARE canonical_triggers INTEGER DEFAULT 0;
  DECLARE invalid_rows INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO attachment_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_anhang'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB';
  IF attachment_tables <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: nv_anhang is missing or incompatible';
  END IF;

  SELECT COUNT(*) INTO required_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_anhang'
     AND column_name IN (
       'lfd-nr', 'einsatz_id', 'filename', 'fileext', 'status', 'md5hash'
     );
  IF required_columns <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: required column is missing';
  END IF;

  SELECT `ordinal_position` INTO md5_position
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_anhang'
     AND column_name = 'md5hash';

  SELECT
      COUNT(*),
      COALESCE(SUM(
        CASE column_name
          WHEN 'integrity_required' THEN 1
          WHEN 'ingest_sha256' THEN 2
          WHEN 'ingest_size' THEN 4
          WHEN 'integrity_captured_at' THEN 8
          ELSE 0
        END
      ), 0)
    INTO existing_columns, column_mask
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_anhang'
     AND column_name IN (
       'integrity_required', 'ingest_sha256', 'ingest_size',
       'integrity_captured_at'
     );

  -- ADD COLUMN is autocommitted by MariaDB. Only prefixes which this
  -- migration itself can have produced are resumable. Each owned column has
  -- both an exact shape and an explicit ownership marker; a same-name foreign
  -- column therefore fails closed instead of being silently adopted.
  IF column_mask NOT IN (0, 1, 3, 7, 15) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: non-canonical column prefix';
  END IF;

  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_anhang'
     AND (
       (
         column_name = 'integrity_required'
         AND data_type = 'tinyint'
         AND column_type LIKE 'tinyint%unsigned'
         AND is_nullable = 'NO'
         AND (
           (existing_columns < 4 AND column_default = '0')
           OR
           (existing_columns = 4 AND column_default IN ('0', '1'))
         )
         AND ordinal_position = md5_position + 1
         AND column_comment =
           'estab:migration:95:integrity-required:v1'
       )
       OR
       (
         column_name = 'ingest_sha256'
         AND data_type = 'char'
         AND character_maximum_length = 64
         AND character_set_name = 'ascii'
         AND collation_name = 'ascii_bin'
         AND is_nullable = 'YES'
         AND (
           column_default IS NULL
           OR UPPER(column_default) = 'NULL'
         )
         AND ordinal_position = md5_position + 2
         AND column_comment =
           'estab:migration:95:ingest-sha256:v1'
       )
       OR
       (
         column_name = 'ingest_size'
         AND data_type = 'bigint'
         AND column_type LIKE 'bigint%unsigned'
         AND is_nullable = 'YES'
         AND (
           column_default IS NULL
           OR UPPER(column_default) = 'NULL'
         )
         AND ordinal_position = md5_position + 3
         AND column_comment =
           'estab:migration:95:ingest-size:v1'
       )
       OR
       (
         column_name = 'integrity_captured_at'
         AND data_type = 'datetime'
         AND datetime_precision = 6
         AND is_nullable = 'YES'
         AND (
           column_default IS NULL
           OR UPPER(column_default) = 'NULL'
         )
         AND ordinal_position = md5_position + 4
         AND column_comment =
           'estab:migration:95:captured-at:v1'
       )
     );
  IF canonical_columns <> existing_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: foreign column collision';
  END IF;
  IF existing_columns <> 0 THEN
    SELECT column_default INTO integrity_default
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND column_name = 'integrity_required';
  END IF;

  SELECT COUNT(*) INTO existing_constraints
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name IN (
       'chk_anhang_integrity_required',
       'chk_anhang_integrity_shape'
     );
  IF existing_constraints NOT IN (0, 2) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: partial constraint set';
  END IF;

  -- MariaDB serialises CHECK clauses canonically in information_schema. The
  -- hashes below cover that serialisation after removing only spaces and
  -- identifier quotes. This distinguishes our two constraints from a foreign
  -- object which merely reused a migration-owned name.
  SELECT COUNT(*) INTO canonical_constraints
    FROM information_schema.table_constraints AS table_constraint
    JOIN information_schema.check_constraints AS check_constraint
      ON check_constraint.constraint_schema =
           table_constraint.constraint_schema
     AND check_constraint.constraint_name =
           table_constraint.constraint_name
   WHERE table_constraint.constraint_schema = DATABASE()
     AND table_constraint.table_name = 'nv_anhang'
     AND table_constraint.constraint_type = 'CHECK'
     AND (
       (
         table_constraint.constraint_name =
           'chk_anhang_integrity_required'
         AND SHA2(
           REPLACE(
             REPLACE(LOWER(check_constraint.check_clause), '`', ''),
             ' ',
             ''
           ),
           256
         ) =
           '4bb60a2b12a22b132bcb9f214c9ae3d7a12a591f8175063d7a3ad6cd3223de6e'
       )
       OR
       (
         table_constraint.constraint_name =
           'chk_anhang_integrity_shape'
         AND SHA2(
           REPLACE(
             REPLACE(LOWER(check_constraint.check_clause), '`', ''),
             ' ',
             ''
           ),
           256
         ) =
           'b69c414cc2ce0f7fc405e6e28f7fefd5333e0ba8e1bb320a5ee9f9a0ecf2c6ca'
       )
     );
  IF canonical_constraints <> existing_constraints THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: foreign constraint collision';
  END IF;

  SELECT COUNT(*) INTO existing_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_attachment_integrity_bi',
       'estab_attachment_integrity_bu'
     );
  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'nv_anhang'
     AND action_timing = 'BEFORE'
     AND action_orientation = 'ROW'
     AND action_condition IS NULL
     AND (
       (
         trigger_name = 'estab_attachment_integrity_bi'
         AND event_manipulation = 'INSERT'
         AND SHA2(action_statement, 256) =
           'd99ab6f95e7e4b7b25b4fd6b7f39aa918a155e4e8032aadba10de3eef5ea2547'
       )
       OR
       (
         trigger_name = 'estab_attachment_integrity_bu'
         AND event_manipulation = 'UPDATE'
         AND SHA2(action_statement, 256) =
           '45d0783e0f104ff510ba8a90d1dbd4aef73a9eb04b56d342b64dcbeb387e854d'
       )
     );
  IF canonical_triggers <> existing_triggers THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: foreign trigger collision';
  END IF;

  -- Constraints and triggers can only occur after the four-column phase and
  -- after the default was switched. A trigger can only occur after the
  -- atomic two-constraint phase. These are the exact durable phase boundaries
  -- of this migration.
  IF existing_columns < 4
     AND (existing_constraints <> 0 OR existing_triggers <> 0) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: invalid phase ordering';
  END IF;
  IF existing_columns = 4
     AND integrity_default <> '1'
     AND (existing_constraints <> 0 OR existing_triggers <> 0) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: invalid default phase';
  END IF;
  IF existing_triggers <> 0 AND existing_constraints <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Attachment integrity migration blocked: trigger before constraints';
  END IF;

  IF existing_columns = 4 THEN
    SELECT COUNT(*) INTO invalid_rows
      FROM `nv_anhang`
     WHERE `integrity_required` NOT IN (0, 1)
        OR (
          `integrity_required` = 0
          AND (
            `ingest_sha256` IS NOT NULL
            OR `ingest_size` IS NOT NULL
            OR `integrity_captured_at` IS NOT NULL
          )
        )
        OR (
          `integrity_required` = 1
          AND NOT (
            (
              `status` = 1
              AND `ingest_sha256`
                    REGEXP BINARY '^[0-9a-f]{64}$'
              AND `ingest_size` IS NOT NULL
              AND `integrity_captured_at` IS NOT NULL
            )
            OR
            (
              `status` <> 1
              AND `ingest_sha256` IS NULL
              AND `ingest_size` IS NULL
              AND `integrity_captured_at` IS NULL
            )
          )
        );
    IF invalid_rows <> 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Attachment integrity migration blocked: non-canonical row evidence';
    END IF;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_95_preflight();
DROP PROCEDURE estab_migrate_95_preflight;

ALTER TABLE `nv_anhang`
  -- MariaDB materialises this initial default for rows which already exist.
  -- Starting at 0 therefore classifies the imported bytes honestly without
  -- firing the operational UPDATE guard installed by migration 50.
  ADD COLUMN IF NOT EXISTS
    `integrity_required` TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:95:integrity-required:v1'
    AFTER `md5hash`,
  ADD COLUMN IF NOT EXISTS `ingest_sha256`
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL
    COMMENT 'estab:migration:95:ingest-sha256:v1'
    AFTER `integrity_required`,
  ADD COLUMN IF NOT EXISTS
    `ingest_size` BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'estab:migration:95:ingest-size:v1'
    AFTER `ingest_sha256`,
  ADD COLUMN IF NOT EXISTS
    `integrity_captured_at` DATETIME(6) NULL DEFAULT NULL
    COMMENT 'estab:migration:95:captured-at:v1'
    AFTER `ingest_size`;

-- Only the rows materialised by the preceding ALTER retain the legacy value.
-- Switch the default before the application can start so every later
-- reservation is integrity-required. This is metadata-only and deliberately
-- leaves all existing rows at 0.
ALTER TABLE `nv_anhang`
  ALTER COLUMN `integrity_required` SET DEFAULT 1;

-- The two checks are one atomic ALTER phase. Skip that phase only when the
-- preflight proved that both existing constraints are byte-for-byte the
-- canonical MariaDB representation.
DROP PROCEDURE IF EXISTS estab_migrate_95_add_constraints;
DELIMITER //
CREATE PROCEDURE estab_migrate_95_add_constraints()
BEGIN
  DECLARE constraint_count INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO constraint_count
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'nv_anhang'
     AND constraint_name IN (
       'chk_anhang_integrity_required',
       'chk_anhang_integrity_shape'
     );

  IF constraint_count = 0 THEN
    ALTER TABLE `nv_anhang`
      ADD CONSTRAINT `chk_anhang_integrity_required`
        CHECK (`integrity_required` IN (0, 1)),
      ADD CONSTRAINT `chk_anhang_integrity_shape`
        CHECK (
          (
            `integrity_required` = 0
            AND `ingest_sha256` IS NULL
            AND `ingest_size` IS NULL
            AND `integrity_captured_at` IS NULL
          )
          OR
          (
            `integrity_required` = 1
            AND (
              (
                `status` = 1
                AND `ingest_sha256`
                    REGEXP BINARY '^[0-9a-f]{64}$'
                AND `ingest_size` IS NOT NULL
                AND `integrity_captured_at` IS NOT NULL
              )
              OR
              (
                `status` <> 1
                AND `ingest_sha256` IS NULL
                AND `ingest_size` IS NULL
                AND `integrity_captured_at` IS NULL
              )
            )
          )
        );
  END IF;
END//
DELIMITER ;

CALL estab_migrate_95_add_constraints();
DROP PROCEDURE estab_migrate_95_add_constraints;

-- Trigger DDL is also autocommitted. Existing names reached here only after
-- exact preflight validation. Recreating both makes every durable subset
-- (none, INSERT only, or both) converge to one canonical trigger pair.
DROP TRIGGER IF EXISTS `estab_attachment_integrity_bi`;
DROP TRIGGER IF EXISTS `estab_attachment_integrity_bu`;
DELIMITER //
CREATE TRIGGER `estab_attachment_integrity_bi`
BEFORE INSERT ON `nv_anhang` FOR EACH ROW
BEGIN
  IF NEW.`integrity_required` <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'New attachment cannot be marked as unverifiable legacy data';
  END IF;
  IF NEW.`status` = 1 THEN
    IF NEW.`ingest_sha256` IS NULL
       OR NOT (
         NEW.`ingest_sha256`
           REGEXP BINARY '^[0-9a-f]{64}$'
       )
       OR NEW.`ingest_size` IS NULL
       OR NEW.`integrity_captured_at` IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Final attachment requires SHA-256, size and capture time';
    END IF;
  ELSEIF NEW.`ingest_sha256` IS NOT NULL
     OR NEW.`ingest_size` IS NOT NULL
     OR NEW.`integrity_captured_at` IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Unfinished attachment cannot carry final integrity evidence';
  END IF;
END//

CREATE TRIGGER `estab_attachment_integrity_bu`
BEFORE UPDATE ON `nv_anhang` FOR EACH ROW
BEGIN
  IF OLD.`integrity_required` = 0 THEN
    IF NEW.`integrity_required` = 1 THEN
      IF OLD.`status` = 1 OR NEW.`status` <> 1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT =
            'Legacy attachment cannot gain retroactive integrity evidence';
      END IF;
    ELSEIF NEW.`integrity_required` <> 0
       OR NEW.`ingest_sha256` IS NOT NULL
       OR NEW.`ingest_size` IS NOT NULL
       OR NEW.`integrity_captured_at` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Legacy attachment integrity marker is immutable';
    END IF;
  ELSE
    IF NEW.`integrity_required` <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Required attachment integrity cannot be downgraded';
    END IF;
    IF OLD.`status` = 1
       AND (
         NOT (NEW.`ingest_sha256` <=> OLD.`ingest_sha256`)
         OR NOT (NEW.`ingest_size` <=> OLD.`ingest_size`)
         OR NOT (
           NEW.`integrity_captured_at`
             <=> OLD.`integrity_captured_at`
         )
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Final attachment integrity evidence is immutable';
    END IF;
  END IF;

  IF NEW.`status` = 1 AND NEW.`integrity_required` = 1 THEN
    IF NEW.`ingest_sha256` IS NULL
       OR NOT (
         NEW.`ingest_sha256`
           REGEXP BINARY '^[0-9a-f]{64}$'
       )
       OR NEW.`ingest_size` IS NULL
       OR NEW.`integrity_captured_at` IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Final attachment requires SHA-256, size and capture time';
    END IF;
  ELSEIF NEW.`integrity_required` = 1
     AND (
       NEW.`ingest_sha256` IS NOT NULL
       OR NEW.`ingest_size` IS NOT NULL
       OR NEW.`integrity_captured_at` IS NOT NULL
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Unfinished attachment cannot carry final integrity evidence';
  END IF;
END//
DELIMITER ;
