-- Authoritative application-session presence and idle expiry.
--
-- `aktiv` remains the revocable legacy session flag. The UTC timestamp records
-- genuine browser interaction, allowing the UI to distinguish recent activity
-- from an idle login and the authentication boundary to expire a session after
-- twelve idle hours. Existing active rows cannot provide a trustworthy
-- timestamp and are therefore revoked once during this migration.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_100_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_100_preflight()
BEGIN
  DECLARE canonical_table INTEGER DEFAULT 0;
  DECLARE required_columns INTEGER DEFAULT 0;
  DECLARE existing_activity_column INTEGER DEFAULT 0;
  DECLARE canonical_activity_column INTEGER DEFAULT 0;
  DECLARE existing_presence_index INTEGER DEFAULT 0;
  DECLARE canonical_presence_index INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci';
  IF canonical_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Session-presence migration blocked: user table is missing or incompatible';
  END IF;

  SELECT COUNT(*) INTO required_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND (
       (column_name = 'sid' AND data_type = 'varchar'
         AND character_maximum_length = 50 AND is_nullable = 'NO')
       OR (column_name IN ('ip', 'fwdip') AND data_type = 'varchar'
         AND character_maximum_length = 45 AND is_nullable = 'NO')
       OR (column_name = 'aktiv' AND data_type = 'smallint'
         AND is_nullable = 'NO')
       OR (column_name = 'estab_gesperrt' AND data_type = 'tinyint'
         AND column_type LIKE 'tinyint%unsigned' AND is_nullable = 'NO')
     );
  IF required_columns <> 5 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Session-presence migration blocked: required user column is missing or incompatible';
  END IF;

  SELECT COUNT(*) INTO existing_activity_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND column_name = 'estab_letzte_aktivitaet';
  SELECT COUNT(*) INTO canonical_activity_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND column_name = 'estab_letzte_aktivitaet'
     AND data_type = 'datetime'
     AND datetime_precision = 6
     AND is_nullable = 'YES'
     AND extra = ''
     AND column_comment =
       'estab:migration:100:last-browser-activity-utc:v1';
  IF existing_activity_column NOT IN (0, 1)
     OR canonical_activity_column <> existing_activity_column THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Session-presence migration blocked: foreign activity column collision';
  END IF;

  SELECT COUNT(*) INTO existing_presence_index
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND index_name = 'idx_benutzer_presence';
  SELECT COUNT(*) INTO canonical_presence_index
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND index_name = 'idx_benutzer_presence'
     AND index_type = 'BTREE'
     AND non_unique = 1
     AND sub_part IS NULL
     AND (
       (seq_in_index = 1 AND column_name = 'aktiv')
       OR (seq_in_index = 2 AND column_name = 'estab_gesperrt')
       OR (seq_in_index = 3 AND column_name = 'estab_letzte_aktivitaet')
     );
  IF existing_presence_index NOT IN (0, 3)
     OR canonical_presence_index <> existing_presence_index THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Session-presence migration blocked: foreign presence index collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_100_preflight();
DROP PROCEDURE estab_migrate_100_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_100_add_activity;
DELIMITER //
CREATE PROCEDURE estab_migrate_100_add_activity()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND column_name = 'estab_letzte_aktivitaet'
  ) THEN
    ALTER TABLE `nv_benutzer`
      ADD COLUMN `estab_letzte_aktivitaet` DATETIME(6) NULL DEFAULT NULL
        COMMENT 'estab:migration:100:last-browser-activity-utc:v1'
        AFTER `estab_gesperrt`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_100_add_activity();
DROP PROCEDURE estab_migrate_100_add_activity;

-- Legacy sessions have no reliable age. Requiring a fresh login is safer than
-- silently granting each stale SID another twelve-hour window.
UPDATE `nv_benutzer`
   SET `aktiv` = 0, `sid` = '', `ip` = '', `fwdip` = ''
 WHERE `aktiv` = 1
   AND `estab_letzte_aktivitaet` IS NULL;

DROP PROCEDURE IF EXISTS estab_migrate_100_add_index;
DELIMITER //
CREATE PROCEDURE estab_migrate_100_add_index()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND index_name = 'idx_benutzer_presence'
  ) THEN
    ALTER TABLE `nv_benutzer`
      ADD INDEX `idx_benutzer_presence` (
        `aktiv`, `estab_gesperrt`, `estab_letzte_aktivitaet`
      );
  END IF;
END//
DELIMITER ;

CALL estab_migrate_100_add_index();
DROP PROCEDURE estab_migrate_100_add_index;

DROP PROCEDURE IF EXISTS estab_migrate_100_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_100_validate()
BEGIN
  DECLARE canonical_activity_column INTEGER DEFAULT 0;
  DECLARE canonical_presence_index INTEGER DEFAULT 0;
  DECLARE invalid_active_rows BIGINT DEFAULT 0;

  SELECT COUNT(*) INTO canonical_activity_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND column_name = 'estab_letzte_aktivitaet'
     AND data_type = 'datetime'
     AND datetime_precision = 6
     AND is_nullable = 'YES'
     AND extra = ''
     AND column_comment =
       'estab:migration:100:last-browser-activity-utc:v1';
  SELECT COUNT(*) INTO canonical_presence_index
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer'
     AND index_name = 'idx_benutzer_presence'
     AND index_type = 'BTREE'
     AND non_unique = 1
     AND sub_part IS NULL
     AND (
       (seq_in_index = 1 AND column_name = 'aktiv')
       OR (seq_in_index = 2 AND column_name = 'estab_gesperrt')
       OR (seq_in_index = 3 AND column_name = 'estab_letzte_aktivitaet')
     );
  SELECT COUNT(*) INTO invalid_active_rows
    FROM `nv_benutzer`
   WHERE `aktiv` = 1
     AND (`sid` = '' OR `estab_letzte_aktivitaet` IS NULL);

  IF canonical_activity_column <> 1
     OR canonical_presence_index <> 3
     OR invalid_active_rows <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Session-presence migration failed: canonical schema or active rows are incomplete';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_100_validate();
DROP PROCEDURE estab_migrate_100_validate;
