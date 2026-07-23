-- Runtime-critical schema alignment for persistent databases initialized by
-- older eStab container revisions. Back up database and 4fdata before use.
-- Every operation is idempotent so an interrupted run can be retried.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- A unique attachment filename is required for race-free reservations. Abort
-- before changing the schema when legacy duplicates need operator review.
DROP PROCEDURE IF EXISTS estab_migrate_30_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_30_preflight()
BEGIN
  DECLARE duplicate_groups BIGINT DEFAULT 0;
  DECLARE error_message VARCHAR(255);

  SELECT COUNT(*) INTO duplicate_groups
    FROM (
      SELECT `filename`
        FROM `nv_anhang`
       GROUP BY `filename`
      HAVING COUNT(*) > 1
    ) AS duplicate_attachment_names;

  IF duplicate_groups > 0 THEN
    SET error_message = CONCAT(
      'Runtime schema migration blocked: ',
      duplicate_groups,
      ' duplicate nv_anhang.filename group(s)'
    );
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = error_message;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_30_preflight();
DROP PROCEDURE estab_migrate_30_preflight;

-- The title tables were created lazily by the historical ETB/TBB pages. A
-- database that never opened one of those pages legitimately does not contain
-- them yet, so the upgrade must supply the same current baseline before the
-- application start gate verifies all 14 base tables.
CREATE TABLE IF NOT EXISTS `nv_etbtitel` (
  `lfd-nr` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz` VARCHAR(255) NOT NULL,
  `ort` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`lfd-nr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_tbbtitel` (
  `lfd-nr` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz` VARCHAR(255) NOT NULL,
  `ort` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`lfd-nr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `nv_benutzer`
  ADD COLUMN IF NOT EXISTS `fwdip` VARCHAR(45) NOT NULL DEFAULT '' AFTER `ip`,
  ADD COLUMN IF NOT EXISTS `password` VARCHAR(255) NOT NULL DEFAULT '' AFTER `aktiv`;

ALTER TABLE `nv_benutzer`
  MODIFY COLUMN `kuerzel` VARCHAR(6) NOT NULL DEFAULT '',
  MODIFY COLUMN `ip` VARCHAR(45) NOT NULL DEFAULT '',
  MODIFY COLUMN `fwdip` VARCHAR(45) NOT NULL DEFAULT '',
  MODIFY COLUMN `password` VARCHAR(255) NOT NULL DEFAULT '',
  ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `nv_nachrichten`
  MODIFY COLUMN `01_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  MODIFY COLUMN `02_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  MODIFY COLUMN `03_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  MODIFY COLUMN `14_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  MODIFY COLUMN `15_quitzeichen` VARCHAR(6) NOT NULL DEFAULT '',
  MODIFY COLUMN `x03_sperruser` VARCHAR(6) NOT NULL DEFAULT '',
  ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `nv_anhang`
  ADD COLUMN IF NOT EXISTS `fileext` VARCHAR(16) NOT NULL DEFAULT '' AFTER `filename`,
  ADD COLUMN IF NOT EXISTS `md5hash` VARCHAR(32) NOT NULL DEFAULT '' AFTER `comment`,
  ADD COLUMN IF NOT EXISTS `status` TINYINT NOT NULL DEFAULT 1 AFTER `kuerzel`,
  ADD COLUMN IF NOT EXISTS `id` VARCHAR(128) NOT NULL DEFAULT '' AFTER `status`;

ALTER TABLE `nv_anhang`
  MODIFY COLUMN `filename` VARCHAR(255) NOT NULL DEFAULT '',
  MODIFY COLUMN `fileext` VARCHAR(16) NOT NULL DEFAULT '',
  MODIFY COLUMN `org_filename` VARCHAR(255) NOT NULL DEFAULT '',
  MODIFY COLUMN `comment` VARCHAR(255) NOT NULL DEFAULT '',
  MODIFY COLUMN `md5hash` VARCHAR(32) NOT NULL DEFAULT '',
  MODIFY COLUMN `kuerzel` VARCHAR(6) NULL DEFAULT NULL,
  MODIFY COLUMN `status` TINYINT NOT NULL DEFAULT 1,
  MODIFY COLUMN `id` VARCHAR(128) NOT NULL DEFAULT '',
  ENGINE=InnoDB,
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP INDEX IF EXISTS `idx_benutzer_funktion_aktiv` ON `nv_benutzer`;
CREATE INDEX `idx_benutzer_funktion_aktiv`
  ON `nv_benutzer` (`funktion`, `aktiv`);

DROP INDEX IF EXISTS `uq_anhang_filename` ON `nv_anhang`;
CREATE UNIQUE INDEX `uq_anhang_filename`
  ON `nv_anhang` (`filename`);

DROP INDEX IF EXISTS `idx_anhang_filename_status` ON `nv_anhang`;
CREATE INDEX `idx_anhang_filename_status`
  ON `nv_anhang` (`filename`, `status`);

DROP INDEX IF EXISTS `idx_anhang_id` ON `nv_anhang`;
CREATE INDEX `idx_anhang_id`
  ON `nv_anhang` (`id`);

DROP INDEX IF EXISTS `idx_anhang_md5hash` ON `nv_anhang`;
CREATE INDEX `idx_anhang_md5hash`
  ON `nv_anhang` (`md5hash`);

-- Historic eStab installations created both base and per-user/per-function
-- tables as MyISAM/latin1. Their names are data-dependent, so a fixed ALTER
-- list cannot make an imported deployment satisfy the transactional runtime
-- contract. Enumerate only this database's eStab-prefixed base tables, quote
-- every identifier, and retain all rows while normalising engine/collation.
DROP PROCEDURE IF EXISTS estab_migrate_30_storage;
DELIMITER //
CREATE PROCEDURE estab_migrate_30_storage()
BEGIN
  DECLARE finished INTEGER DEFAULT 0;
  DECLARE candidate_table VARCHAR(64);
  DECLARE storage_cursor CURSOR FOR
    SELECT `table_name`
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND `table_type` = 'BASE TABLE'
       AND LEFT(`table_name`, 3) = 'nv_'
       AND (
         COALESCE(`engine`, '') <> 'InnoDB'
         OR COALESCE(`table_collation`, '') <> 'utf8mb4_unicode_ci'
       )
     ORDER BY `table_name`;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = 1;

  OPEN storage_cursor;
  storage_loop: LOOP
    FETCH storage_cursor INTO candidate_table;
    IF finished = 1 THEN
      LEAVE storage_loop;
    END IF;

    SET @estab_storage_statement = CONCAT(
      'ALTER TABLE `',
      REPLACE(candidate_table, '`', '``'),
      '` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 ',
      'COLLATE utf8mb4_unicode_ci'
    );
    PREPARE estab_storage_change FROM @estab_storage_statement;
    EXECUTE estab_storage_change;
    DEALLOCATE PREPARE estab_storage_change;
  END LOOP;
  CLOSE storage_cursor;
END//
DELIMITER ;
CALL estab_migrate_30_storage();
DROP PROCEDURE estab_migrate_30_storage;
SET @estab_storage_statement = NULL;
