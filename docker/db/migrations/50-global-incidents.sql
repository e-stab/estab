-- Global incident domain and fail-closed operational data boundary.
--
-- Existing operational rows are assigned exactly once to the reserved,
-- closed LEGACY-IMPORT incident before any trigger is installed. New inserts
-- receive only the currently active incident. Updates and deletes can neither
-- reassign a row nor touch data from an inactive incident.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Do not silently adopt tables, columns, routines, or triggers created by an
-- unrelated extension. Migration-owned tables carry an exact comment so an
-- interrupted, non-transactional DDL run can be resumed safely.
DROP PROCEDURE IF EXISTS estab_migrate_50_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_50_preflight()
BEGIN
  DECLARE owned_tables INTEGER DEFAULT 0;
  DECLARE conflicting_tables INTEGER DEFAULT 0;
  DECLARE existing_scope_columns INTEGER DEFAULT 0;
  DECLARE existing_routines INTEGER DEFAULT 0;
  DECLARE existing_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO owned_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_einsaetze',
       'nv_einsatz_status',
       'nv_einsatz_ereignisse'
     )
     AND table_comment = 'estab:migration:50-global-incidents:v1';

  SELECT COUNT(*) INTO conflicting_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_einsaetze',
       'nv_einsatz_status',
       'nv_einsatz_ereignisse'
     )
     AND table_comment <> 'estab:migration:50-global-incidents:v1';

  IF conflicting_tables > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incident migration blocked: conflicting incident table';
  END IF;

  SELECT COUNT(*) INTO existing_scope_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND column_name = 'einsatz_id'
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

  SELECT COUNT(*) INTO existing_routines
    FROM information_schema.routines
   WHERE routine_schema = DATABASE()
     AND routine_name IN (
       'estab_incident_for_insert',
       'estab_incident_for_update',
       'estab_incident_for_delete'
     );

  SELECT COUNT(*) INTO existing_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_nachrichten_bi_einsatz',
       'estab_nachrichten_bu_einsatz',
       'estab_nachrichten_bd_einsatz',
       'estab_anhang_bi_einsatz',
       'estab_anhang_bu_einsatz',
       'estab_anhang_bd_einsatz',
       'estab_etb_bi_einsatz',
       'estab_etb_bu_einsatz',
       'estab_etb_bd_einsatz',
       'estab_tbb_bi_einsatz',
       'estab_tbb_bu_einsatz',
       'estab_tbb_bd_einsatz',
       'estab_ubb_bi_einsatz',
       'estab_ubb_bu_einsatz',
       'estab_ubb_bd_einsatz',
       'estab_protokoll_bi_einsatz',
       'estab_protokoll_bu_einsatz',
       'estab_protokoll_bd_einsatz',
       'estab_bhp50_bi_einsatz',
       'estab_bhp50_bu_einsatz',
       'estab_bhp50_bd_einsatz',
       'estab_komplan_bi_einsatz',
       'estab_komplan_bu_einsatz',
       'estab_komplan_bd_einsatz',
       'estab_etbtitel_bi_einsatz',
       'estab_etbtitel_bu_einsatz',
       'estab_etbtitel_bd_einsatz',
       'estab_tbbtitel_bi_einsatz',
       'estab_tbbtitel_bu_einsatz',
       'estab_tbbtitel_bd_einsatz'
     );

  IF owned_tables = 0
     AND (
       existing_scope_columns > 0
       OR existing_routines > 0
       OR existing_triggers > 0
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incident migration blocked: conflicting incident schema';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_50_preflight();
DROP PROCEDURE estab_migrate_50_preflight;

CREATE TABLE IF NOT EXISTS `nv_einsaetze` (
  `einsatz_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kennung` VARCHAR(64) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `beginn` DATETIME NOT NULL,
  `ende` DATETIME NULL DEFAULT NULL,
  `ort` VARCHAR(255) NOT NULL DEFAULT '',
  `organisation` VARCHAR(255) NOT NULL DEFAULT '',
  `einsatzleitung` VARCHAR(255) NOT NULL DEFAULT '',
  `beschreibung` TEXT NOT NULL,
  `metadaten` LONGTEXT NOT NULL,
  `erstellt_am` DATETIME(6) NOT NULL,
  `erstellt_von` VARCHAR(128) NOT NULL,
  PRIMARY KEY (`einsatz_id`),
  UNIQUE KEY `uq_einsaetze_kennung` (`kennung`),
  KEY `idx_einsaetze_zeitraum` (`beginn`, `ende`),
  CONSTRAINT `chk_einsaetze_zeitraum`
    CHECK (`ende` IS NULL OR `ende` >= `beginn`),
  CONSTRAINT `chk_einsaetze_metadaten`
    CHECK (JSON_VALID(`metadaten`))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:50-global-incidents:v1';

CREATE TABLE IF NOT EXISTS `nv_einsatz_status` (
  `singleton_id` TINYINT UNSIGNED NOT NULL,
  `active_einsatz_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `geaendert_am` DATETIME(6) NOT NULL,
  `geaendert_von` VARCHAR(128) NOT NULL DEFAULT 'schema-migration-50',
  PRIMARY KEY (`singleton_id`),
  UNIQUE KEY `uq_einsatz_status_active` (`active_einsatz_id`),
  CONSTRAINT `chk_einsatz_status_singleton`
    CHECK (`singleton_id` = 1),
  CONSTRAINT `fk_einsatz_status_active`
    FOREIGN KEY (`active_einsatz_id`)
    REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:50-global-incidents:v1';

CREATE TABLE IF NOT EXISTS `nv_einsatz_ereignisse` (
  `ereignis_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `aktion` VARCHAR(32) NOT NULL,
  `zeitpunkt` DATETIME(6) NOT NULL,
  `akteur` VARCHAR(128) NOT NULL,
  `status_revision` BIGINT UNSIGNED NULL DEFAULT NULL,
  `details` LONGTEXT NOT NULL,
  PRIMARY KEY (`ereignis_id`),
  KEY `idx_einsatz_ereignisse_einsatz_zeit`
    (`einsatz_id`, `zeitpunkt`),
  CONSTRAINT `chk_einsatz_ereignisse_details`
    CHECK (JSON_VALID(`details`)),
  CONSTRAINT `fk_einsatz_ereignisse_einsatz`
    FOREIGN KEY (`einsatz_id`)
    REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:50-global-incidents:v1';

INSERT INTO `nv_einsatz_status`
  (`singleton_id`, `active_einsatz_id`, `revision`,
   `geaendert_am`, `geaendert_von`)
VALUES
  (1, NULL, 0, NOW(6), 'schema-migration-50')
ON DUPLICATE KEY UPDATE `singleton_id` = VALUES(`singleton_id`);

-- Nullable is intentional for schema compatibility and for the singleton
-- status relation. Operational triggers below make NULL impossible for new
-- domain rows while still allowing an interrupted migration to resume.
ALTER TABLE `nv_nachrichten`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `00_lfd`;
ALTER TABLE `nv_anhang`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `lfd-nr`;
ALTER TABLE `nv_etb`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `etb_lfd-nr`;
ALTER TABLE `nv_tbb`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `tbb_lfd-nr`;
ALTER TABLE `nv_ubb`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `ubb_lfd-nr`;
ALTER TABLE `nv_protokoll`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `p_lfd`;
ALTER TABLE `nv_bhp50`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `lfd-nr`;
ALTER TABLE `nv_komplan`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `lfd`;
ALTER TABLE `nv_etbtitel`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `lfd-nr`;
ALTER TABLE `nv_tbbtitel`
  ADD COLUMN IF NOT EXISTS `einsatz_id`
    BIGINT UNSIGNED NULL DEFAULT NULL AFTER `lfd-nr`;

-- A partial retry must not accept a differently typed column.
DROP PROCEDURE IF EXISTS estab_migrate_50_validate_columns;
DELIMITER //
CREATE PROCEDURE estab_migrate_50_validate_columns()
BEGIN
  DECLARE valid_columns INTEGER DEFAULT 0;
  SELECT COUNT(*) INTO valid_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND column_name = 'einsatz_id'
     AND data_type = 'bigint'
     AND column_type LIKE '%unsigned%'
     AND is_nullable = 'YES'
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
  IF valid_columns <> 10 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Incident migration blocked: invalid incident scope column';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_50_validate_columns();
DROP PROCEDURE estab_migrate_50_validate_columns;

-- Existing rows predate the incident model. They must never be assigned to a
-- newly activated real incident, so migration owns one closed reserved scope.
DROP PROCEDURE IF EXISTS estab_migrate_50_backfill;
DELIMITER //
CREATE PROCEDURE estab_migrate_50_backfill()
BEGIN
  DECLARE legacy_rows BIGINT DEFAULT 0;
  DECLARE legacy_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE legacy_owner VARCHAR(128) DEFAULT NULL;
  DECLARE legacy_start DATETIME DEFAULT CURRENT_TIMESTAMP;

  SELECT
      (SELECT COUNT(*) FROM `nv_nachrichten` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_anhang` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_ubb` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_protokoll` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_bhp50` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_komplan` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_etbtitel` WHERE `einsatz_id` IS NULL)
    + (SELECT COUNT(*) FROM `nv_tbbtitel` WHERE `einsatz_id` IS NULL)
    INTO legacy_rows;

  IF legacy_rows > 0 THEN
    SELECT LEAST(
      COALESCE(MIN(candidate_time), CURRENT_TIMESTAMP),
      CURRENT_TIMESTAMP
    ) INTO legacy_start
      FROM (
        SELECT `01_datum` AS candidate_time
          FROM `nv_nachrichten` WHERE `01_datum` IS NOT NULL
        UNION ALL
        SELECT `date` FROM `nv_anhang` WHERE `date` IS NOT NULL
        UNION ALL
        SELECT `etb_time` FROM `nv_etb`
        UNION ALL
        SELECT `tbb_time` FROM `nv_tbb`
        UNION ALL
        SELECT `ubb_time` FROM `nv_ubb`
        UNION ALL
        SELECT `p_zeit` FROM `nv_protokoll`
      ) AS legacy_times;

    SELECT `einsatz_id`, `erstellt_von`
      INTO legacy_id, legacy_owner
      FROM `nv_einsaetze`
     WHERE `kennung` = 'LEGACY-IMPORT'
     LIMIT 1;

    IF legacy_id IS NOT NULL
       AND legacy_owner <> 'schema-migration-50' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Incident migration blocked: LEGACY-IMPORT is not migration-owned';
    END IF;

    IF legacy_id IS NULL THEN
      INSERT INTO `nv_einsaetze`
        (`kennung`, `name`, `beginn`, `ende`, `ort`, `organisation`,
         `einsatzleitung`, `beschreibung`, `metadaten`,
         `erstellt_am`, `erstellt_von`)
      VALUES
        ('LEGACY-IMPORT',
         'Bestandsdaten vor Einführung der Einsatzzuordnung',
         legacy_start,
         CURRENT_TIMESTAMP,
         '',
         '',
         '',
         'Automatisch abgegrenzter, geschlossener Datenbestand.',
         '{"classification":"legacy","source":"migration-50"}',
         NOW(6),
         'schema-migration-50');
      SET legacy_id = LAST_INSERT_ID();
    END IF;

    UPDATE `nv_nachrichten` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_anhang` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_etb` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_tbb` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_ubb` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_protokoll` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_bhp50` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_komplan` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_etbtitel` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
    UPDATE `nv_tbbtitel` SET `einsatz_id` = legacy_id
     WHERE `einsatz_id` IS NULL;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_50_backfill();
DROP PROCEDURE estab_migrate_50_backfill;

CREATE INDEX IF NOT EXISTS `idx_nachrichten_einsatz`
  ON `nv_nachrichten` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_anhang_einsatz`
  ON `nv_anhang` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_etb_einsatz`
  ON `nv_etb` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_tbb_einsatz`
  ON `nv_tbb` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_ubb_einsatz`
  ON `nv_ubb` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_protokoll_einsatz`
  ON `nv_protokoll` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_bhp50_einsatz`
  ON `nv_bhp50` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_komplan_einsatz`
  ON `nv_komplan` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_etbtitel_einsatz`
  ON `nv_etbtitel` (`einsatz_id`);
CREATE INDEX IF NOT EXISTS `idx_tbbtitel_einsatz`
  ON `nv_tbbtitel` (`einsatz_id`);

ALTER TABLE `nv_nachrichten`
  DROP FOREIGN KEY IF EXISTS `fk_nachrichten_einsatz`;
ALTER TABLE `nv_nachrichten`
  ADD CONSTRAINT `fk_nachrichten_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_anhang`
  DROP FOREIGN KEY IF EXISTS `fk_anhang_einsatz`;
ALTER TABLE `nv_anhang`
  ADD CONSTRAINT `fk_anhang_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_etb`
  DROP FOREIGN KEY IF EXISTS `fk_etb_einsatz`;
ALTER TABLE `nv_etb`
  ADD CONSTRAINT `fk_etb_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_tbb`
  DROP FOREIGN KEY IF EXISTS `fk_tbb_einsatz`;
ALTER TABLE `nv_tbb`
  ADD CONSTRAINT `fk_tbb_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_ubb`
  DROP FOREIGN KEY IF EXISTS `fk_ubb_einsatz`;
ALTER TABLE `nv_ubb`
  ADD CONSTRAINT `fk_ubb_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_protokoll`
  DROP FOREIGN KEY IF EXISTS `fk_protokoll_einsatz`;
ALTER TABLE `nv_protokoll`
  ADD CONSTRAINT `fk_protokoll_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_bhp50`
  DROP FOREIGN KEY IF EXISTS `fk_bhp50_einsatz`;
ALTER TABLE `nv_bhp50`
  ADD CONSTRAINT `fk_bhp50_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_komplan`
  DROP FOREIGN KEY IF EXISTS `fk_komplan_einsatz`;
ALTER TABLE `nv_komplan`
  ADD CONSTRAINT `fk_komplan_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_etbtitel`
  DROP FOREIGN KEY IF EXISTS `fk_etbtitel_einsatz`;
ALTER TABLE `nv_etbtitel`
  ADD CONSTRAINT `fk_etbtitel_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_tbbtitel`
  DROP FOREIGN KEY IF EXISTS `fk_tbbtitel_einsatz`;
ALTER TABLE `nv_tbbtitel`
  ADD CONSTRAINT `fk_tbbtitel_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

-- Shared trigger functions keep all strict operational table boundaries
-- byte-identical.
-- They deliberately read the singleton for every legacy write: omitting an
-- explicit einsatz_id is allowed only on INSERT and can never assign a row
-- when no incident is active.
--
-- nv_protokoll is the deliberate exception. It also records login/logout,
-- user administration, configuration, and incident administration while no
-- incident is active. Those global events retain NULL; operational protocol
-- writers must bind the active incident through the PHP API.
DROP TRIGGER IF EXISTS `estab_nachrichten_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_nachrichten_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_nachrichten_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_anhang_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_anhang_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_anhang_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_ubb_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_ubb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_ubb_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_protokoll_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_protokoll_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_protokoll_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_bhp50_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_bhp50_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_bhp50_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_komplan_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_komplan_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_komplan_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_etbtitel_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_etbtitel_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_etbtitel_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbbtitel_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbbtitel_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbbtitel_bd_einsatz`;

DROP FUNCTION IF EXISTS estab_incident_for_insert;
DROP FUNCTION IF EXISTS estab_incident_for_update;
DROP FUNCTION IF EXISTS estab_incident_for_delete;

DELIMITER //
CREATE FUNCTION estab_incident_for_insert(
  requested_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY DEFINER
BEGIN
  DECLARE active_incident BIGINT UNSIGNED DEFAULT NULL;
  SELECT `active_einsatz_id` INTO active_incident
    FROM `nv_einsatz_status`
   WHERE `singleton_id` = 1;
  IF active_incident IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No active incident: operational insert blocked';
  END IF;
  IF requested_incident IS NOT NULL
     AND requested_incident <> active_incident THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Operational insert targets inactive incident';
  END IF;
  RETURN active_incident;
END//

CREATE FUNCTION estab_incident_for_update(
  previous_incident BIGINT UNSIGNED,
  requested_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY DEFINER
BEGIN
  DECLARE active_incident BIGINT UNSIGNED DEFAULT NULL;
  SELECT `active_einsatz_id` INTO active_incident
    FROM `nv_einsatz_status`
   WHERE `singleton_id` = 1;
  IF previous_incident IS NULL
     OR requested_incident IS NULL
     OR requested_incident <> previous_incident THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Operational incident reassignment blocked';
  END IF;
  IF active_incident IS NULL OR previous_incident <> active_incident THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Operational update targets inactive incident';
  END IF;
  RETURN previous_incident;
END//

CREATE FUNCTION estab_incident_for_delete(
  previous_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
READS SQL DATA
SQL SECURITY DEFINER
BEGIN
  DECLARE active_incident BIGINT UNSIGNED DEFAULT NULL;
  SELECT `active_einsatz_id` INTO active_incident
    FROM `nv_einsatz_status`
   WHERE `singleton_id` = 1;
  IF previous_incident IS NULL
     OR active_incident IS NULL
     OR previous_incident <> active_incident THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Operational delete targets inactive incident';
  END IF;
  RETURN previous_incident;
END//
DELIMITER ;

CREATE TRIGGER `estab_nachrichten_bi_einsatz`
BEFORE INSERT ON `nv_nachrichten` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_nachrichten_bu_einsatz`
BEFORE UPDATE ON `nv_nachrichten` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_nachrichten_bd_einsatz`
BEFORE DELETE ON `nv_nachrichten` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_anhang_bi_einsatz`
BEFORE INSERT ON `nv_anhang` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_anhang_bu_einsatz`
BEFORE UPDATE ON `nv_anhang` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_anhang_bd_einsatz`
BEFORE DELETE ON `nv_anhang` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_etb_bi_einsatz`
BEFORE INSERT ON `nv_etb` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_etb_bu_einsatz`
BEFORE UPDATE ON `nv_etb` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_etb_bd_einsatz`
BEFORE DELETE ON `nv_etb` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_tbb_bi_einsatz`
BEFORE INSERT ON `nv_tbb` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_tbb_bu_einsatz`
BEFORE UPDATE ON `nv_tbb` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_tbb_bd_einsatz`
BEFORE DELETE ON `nv_tbb` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_ubb_bi_einsatz`
BEFORE INSERT ON `nv_ubb` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_ubb_bu_einsatz`
BEFORE UPDATE ON `nv_ubb` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_ubb_bd_einsatz`
BEFORE DELETE ON `nv_ubb` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_bhp50_bi_einsatz`
BEFORE INSERT ON `nv_bhp50` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_bhp50_bu_einsatz`
BEFORE UPDATE ON `nv_bhp50` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_bhp50_bd_einsatz`
BEFORE DELETE ON `nv_bhp50` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_komplan_bi_einsatz`
BEFORE INSERT ON `nv_komplan` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_komplan_bu_einsatz`
BEFORE UPDATE ON `nv_komplan` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_komplan_bd_einsatz`
BEFORE DELETE ON `nv_komplan` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_etbtitel_bi_einsatz`
BEFORE INSERT ON `nv_etbtitel` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_etbtitel_bu_einsatz`
BEFORE UPDATE ON `nv_etbtitel` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_etbtitel_bd_einsatz`
BEFORE DELETE ON `nv_etbtitel` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;

CREATE TRIGGER `estab_tbbtitel_bi_einsatz`
BEFORE INSERT ON `nv_tbbtitel` FOR EACH ROW
SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
CREATE TRIGGER `estab_tbbtitel_bu_einsatz`
BEFORE UPDATE ON `nv_tbbtitel` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);
DELIMITER //
CREATE TRIGGER `estab_tbbtitel_bd_einsatz`
BEFORE DELETE ON `nv_tbbtitel` FOR EACH ROW
BEGIN
  DECLARE checked_incident BIGINT UNSIGNED;
  SET checked_incident = estab_incident_for_delete(OLD.`einsatz_id`);
END//
DELIMITER ;
