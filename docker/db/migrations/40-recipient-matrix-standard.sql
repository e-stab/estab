-- Persistent single standard recipient matrix for the administrative editor.
--
-- The historical editor stored this preset as executable PHP. Keep the
-- released 10-schema.sql baseline checksum stable and add the preset through
-- this versioned database migration instead.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- This table did not exist in any released container schema. Never reinterpret
-- or empty an untracked table with the same name: an operator must first
-- inspect and resolve that collision from a backup. The table comment is an
-- ownership marker for the one safe exception: retrying this migration after
-- its non-transactional CREATE TABLE already succeeded.
DROP PROCEDURE IF EXISTS estab_migrate_40_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_40_preflight()
BEGIN
  DECLARE existing_tables BIGINT DEFAULT 0;
  DECLARE owned_tables BIGINT DEFAULT 0;
  DECLARE existing_rows BIGINT DEFAULT 0;
  DECLARE canonical_rows BIGINT DEFAULT 0;

  SELECT COUNT(*) INTO existing_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_empfmtx_standard';

  IF existing_tables > 0 THEN
    SELECT COUNT(*) INTO owned_tables
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_empfmtx_standard'
       AND table_type = 'BASE TABLE'
       AND engine = 'InnoDB'
       AND table_collation LIKE 'utf8mb4\_%'
       AND table_comment =
         'estab:migration:40-recipient-matrix-standard:v1';

    IF owned_tables <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Standard matrix migration blocked: pre-existing nv_empfmtx_standard table';
    END IF;

    SELECT
      COUNT(*),
      COALESCE(SUM(
        CONCAT_WS(
          '|',
          `mtx_x`, `mtx_y`, `mtx_typ`, `mtx_fkt`, `mtx_rolle`, `mtx_mode`,
          IF(`mtx_rc2` IN ('t','1'), '1', '0'),
          IF(`mtx_auto` IN ('t','1'), '1', '0')
        ) IN (
          '1|1|cb|LS|Stab|ro|0|0',  '1|2|cb|S5|Stab|ro|0|0',
          '1|3|t|||ro|0|0',         '1|4|t|||ro|0|0',
          '2|1|cb|S1|Stab|ro|0|0',  '2|2|cb|S6|Stab|ro|0|0',
          '2|3|t|||ro|0|0',         '2|4|t|||ro|0|0',
          '3|1|cb|S2|Stab|ro|1|0',  '3|2|cb|POL|FB|ro|0|0',
          '3|3|t|||ro|0|0',         '3|4|t|||ro|0|0',
          '4|1|cb|S3|Stab|ro|0|0',  '4|2|cb|THW|FB|ro|0|0',
          '4|3|t|||ro|0|0',         '4|4|t|||ro|0|0',
          '5|1|cb|S4|Stab|ro|0|0',  '5|2|cb|SAN|FB|ro|0|0',
          '5|3|t|||ro|0|0',         '5|4|t|||ro|0|0'
        )
      ), 0)
      INTO existing_rows, canonical_rows
      FROM `nv_empfmtx_standard`;

    IF existing_rows <> 0
       AND (existing_rows <> 20 OR canonical_rows <> 20) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Standard matrix migration blocked: owned table content is not resumable';
    END IF;
  ELSE
    SET @estab_migrate_40_create =
      'CREATE TABLE `nv_empfmtx_standard` (
        `mtx_lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `mtx_x` INT NOT NULL,
        `mtx_y` INT NOT NULL,
        `mtx_typ` SET(''cb'',''t'') NOT NULL DEFAULT '''',
        `mtx_fkt` VARCHAR(6) NOT NULL DEFAULT '''',
        `mtx_rolle` SET(''Stab'',''FB'') NOT NULL DEFAULT '''',
        `mtx_mode` SET(''ro'',''rw'') NOT NULL DEFAULT '''',
        `mtx_rc2` BINARY(1) NOT NULL DEFAULT ''f'',
        `mtx_auto` BINARY(1) NOT NULL DEFAULT ''f'',
        PRIMARY KEY (`mtx_lfd`),
        UNIQUE KEY `uq_empfmtx_standard_position` (`mtx_x`, `mtx_y`)
      ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT=''estab:migration:40-recipient-matrix-standard:v1''';
    PREPARE estab_migrate_40_create_statement
       FROM @estab_migrate_40_create;
    EXECUTE estab_migrate_40_create_statement;
    DEALLOCATE PREPARE estab_migrate_40_create_statement;
    SET @estab_migrate_40_create = NULL;
    SET existing_rows = 0;
  END IF;

  IF existing_rows = 0 THEN
    START TRANSACTION;
    INSERT INTO `nv_empfmtx_standard`
      (`mtx_x`, `mtx_y`, `mtx_typ`, `mtx_fkt`, `mtx_rolle`, `mtx_mode`, `mtx_rc2`, `mtx_auto`)
    VALUES
      (1,1,'cb','LS', 'Stab','ro','f','f'), (1,2,'cb','S5', 'Stab','ro','f','f'),
      (1,3,'t', '',   '',    'ro','f','f'), (1,4,'t', '',   '',    'ro','f','f'),
      (2,1,'cb','S1', 'Stab','ro','f','f'), (2,2,'cb','S6', 'Stab','ro','f','f'),
      (2,3,'t', '',   '',    'ro','f','f'), (2,4,'t', '',   '',    'ro','f','f'),
      (3,1,'cb','S2', 'Stab','ro','t','f'), (3,2,'cb','POL','FB',  'ro','f','f'),
      (3,3,'t', '',   '',    'ro','f','f'), (3,4,'t', '',   '',    'ro','f','f'),
      (4,1,'cb','S3', 'Stab','ro','f','f'), (4,2,'cb','THW','FB',  'ro','f','f'),
      (4,3,'t', '',   '',    'ro','f','f'), (4,4,'t', '',   '',    'ro','f','f'),
      (5,1,'cb','S4', 'Stab','ro','f','f'), (5,2,'cb','SAN','FB',  'ro','f','f'),
      (5,3,'t', '',   '',    'ro','f','f'), (5,4,'t', '',   '',    'ro','f','f');
    COMMIT;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_40_preflight();
DROP PROCEDURE estab_migrate_40_preflight;
