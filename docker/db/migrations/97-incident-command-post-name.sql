-- Store the command-post name on the incident instead of deriving it from
-- installation-wide environment configuration.
--
-- Existing incidents deliberately remain NULL: organisation, incident name
-- and command-post name are different facts, so inventing historical values
-- would make exports misleading. The application requires administrators to
-- confirm the missing value before further operational work.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_97_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_97_preflight()
BEGIN
  DECLARE owned_table INTEGER DEFAULT 0;
  DECLARE organisation_position INTEGER DEFAULT 0;
  DECLARE existing_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE existing_lock_columns INTEGER DEFAULT 0;
  DECLARE canonical_lock_columns INTEGER DEFAULT 0;
  DECLARE existing_owned_routines INTEGER DEFAULT 0;
  DECLARE canonical_owned_routines INTEGER DEFAULT 0;
  DECLARE existing_owned_triggers INTEGER DEFAULT 0;
  DECLARE canonical_owned_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO owned_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment = 'estab:migration:50-global-incidents:v1';
  IF owned_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration blocked: incident table is missing or foreign';
  END IF;

  SELECT COALESCE(MAX(ordinal_position), 0) INTO organisation_position
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'organisation'
     AND data_type = 'varchar'
     AND column_type = 'varchar(255)'
     AND character_maximum_length = 255
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'NO';
  IF organisation_position = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration blocked: incident predecessor is incompatible';
  END IF;

  SELECT COUNT(*) INTO existing_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'fuehrungsstellenname';

  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'fuehrungsstellenname'
     AND data_type = 'varchar'
     AND column_type = 'varchar(128)'
     AND character_maximum_length = 128
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'YES'
     AND (
       column_default IS NULL
       OR UPPER(column_default) = 'NULL'
     )
     AND ordinal_position = organisation_position + 1
     AND extra = ''
     AND column_comment =
       'estab:migration:97:incident-command-post-name:v1';
  IF existing_columns <> canonical_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration blocked: foreign column collision';
  END IF;

  SELECT COUNT(*) INTO existing_lock_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'fuehrungsstellenname_gesperrt';

  SELECT COUNT(*) INTO canonical_lock_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'fuehrungsstellenname_gesperrt'
     AND data_type = 'tinyint'
     AND column_type LIKE 'tinyint%unsigned'
     AND is_nullable = 'NO'
     AND column_default = '0'
     AND ordinal_position = organisation_position + 2
     AND extra = ''
     AND column_comment =
       'estab:migration:97:incident-command-post-lock:v1';
  IF existing_lock_columns <> canonical_lock_columns
     OR (existing_lock_columns = 1 AND existing_columns = 0) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration blocked: foreign lock column collision';
  END IF;

  SELECT COUNT(*) INTO existing_owned_routines
    FROM information_schema.routines
   WHERE routine_schema = DATABASE()
     AND routine_name = 'estab_incident_command_post_for_write';
  SELECT COUNT(*) INTO canonical_owned_routines
    FROM information_schema.routines
   WHERE routine_schema = DATABASE()
     AND routine_name = 'estab_incident_command_post_for_write'
     AND routine_type = 'FUNCTION'
     AND sql_data_access = 'MODIFIES SQL DATA'
     AND security_type = 'DEFINER'
     AND routine_comment =
       'estab:migration:97:incident-command-post-write-lock:v1'
     AND routine_definition LIKE
       '%Operational write requires a configured command-post name%'
     AND routine_definition LIKE
       '%fuehrungsstellenname_gesperrt%';
  IF existing_owned_routines <> canonical_owned_routines THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration blocked: foreign routine collision';
  END IF;

  SELECT COUNT(*) INTO existing_owned_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_command_post_incident_insert',
       'estab_command_post_incident_update'
     );
  SELECT COUNT(*) INTO canonical_owned_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'nv_einsaetze'
     AND action_timing = 'BEFORE'
     AND (
       (
         trigger_name = 'estab_command_post_incident_insert'
         AND event_manipulation = 'INSERT'
         AND action_statement LIKE
           '%New incident requires an unlocked valid command-post name%'
       )
       OR (
         trigger_name = 'estab_command_post_incident_update'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE
           '%Direct command-post name manipulation is blocked%'
         AND action_statement LIKE
           '%Invalid internal command-post lock transition%'
       )
     );
  IF existing_owned_triggers <> canonical_owned_triggers THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration blocked: foreign trigger collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_97_preflight();
DROP PROCEDURE estab_migrate_97_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_97_add_column;
DELIMITER //
CREATE PROCEDURE estab_migrate_97_add_column()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_einsaetze'
       AND column_name = 'fuehrungsstellenname'
  ) THEN
    ALTER TABLE `nv_einsaetze`
      ADD COLUMN `fuehrungsstellenname` VARCHAR(128)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NULL DEFAULT NULL
        COMMENT 'estab:migration:97:incident-command-post-name:v1'
        AFTER `organisation`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_97_add_column();
DROP PROCEDURE estab_migrate_97_add_column;

DROP PROCEDURE IF EXISTS estab_migrate_97_add_lock_column;
DELIMITER //
CREATE PROCEDURE estab_migrate_97_add_lock_column()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_einsaetze'
       AND column_name = 'fuehrungsstellenname_gesperrt'
  ) THEN
    ALTER TABLE `nv_einsaetze`
      ADD COLUMN `fuehrungsstellenname_gesperrt` TINYINT UNSIGNED
        NOT NULL DEFAULT 0
        COMMENT 'estab:migration:97:incident-command-post-lock:v1'
        AFTER `fuehrungsstellenname`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_97_add_lock_column();
DROP PROCEDURE estab_migrate_97_add_lock_column;

DROP PROCEDURE IF EXISTS estab_migrate_97_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_97_validate()
BEGIN
  IF (
       SELECT COUNT(*)
         FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_einsaetze'
          AND column_name = 'fuehrungsstellenname'
          AND data_type = 'varchar'
          AND column_type = 'varchar(128)'
          AND character_maximum_length = 128
          AND character_set_name = 'utf8mb4'
          AND collation_name = 'utf8mb4_unicode_ci'
          AND is_nullable = 'YES'
          AND (
            column_default IS NULL
            OR UPPER(column_default) = 'NULL'
          )
          AND extra = ''
          AND column_comment =
            'estab:migration:97:incident-command-post-name:v1'
     ) <> 1
     OR (
       SELECT COUNT(*)
         FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_einsaetze'
          AND column_name = 'fuehrungsstellenname_gesperrt'
          AND data_type = 'tinyint'
          AND column_type LIKE 'tinyint%unsigned'
          AND is_nullable = 'NO'
          AND column_default = '0'
          AND extra = ''
          AND column_comment =
            'estab:migration:97:incident-command-post-lock:v1'
     ) <> 1
     OR EXISTS (
       SELECT 1
         FROM `nv_einsaetze`
        WHERE `fuehrungsstellenname` IS NOT NULL
          AND (
            BINARY `fuehrungsstellenname`
              <> BINARY TRIM(`fuehrungsstellenname`)
            OR CHAR_LENGTH(`fuehrungsstellenname`) = 0
            OR `fuehrungsstellenname`
              REGEXP '^[[:space:]]|[[:space:]]$'
            OR `fuehrungsstellenname` NOT REGEXP '[^[:space:]]'
            OR `fuehrungsstellenname` REGEXP '\\p{C}'
          )
     )
     OR EXISTS (
       SELECT 1
         FROM `nv_einsaetze`
        WHERE `fuehrungsstellenname_gesperrt` NOT IN (0, 1)
           OR (
             `fuehrungsstellenname_gesperrt` = 1
             AND `fuehrungsstellenname` IS NULL
           )
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration failed: incident field is incomplete';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_97_validate();
DROP PROCEDURE estab_migrate_97_validate;

-- Persist the "first operational write" boundary for any valid value that may
-- already have been recorded between an interrupted ADD COLUMN and ledger
-- acknowledgement. Historical NULL rows deliberately remain unlocked so an
-- administrator can confirm them once despite existing data.
SET @estab_command_post_migration_write = 1;
UPDATE `nv_einsaetze` AS incident
   SET `fuehrungsstellenname_gesperrt` = 1
 WHERE incident.`fuehrungsstellenname` IS NOT NULL
   AND incident.`fuehrungsstellenname_gesperrt` = 0
   AND (
     EXISTS (
       SELECT 1 FROM `nv_nachrichten`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_anhang`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_etb`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_tbb`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_ubb`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_protokoll`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_bhp50`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_komplan`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_etbtitel`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_tbbtitel`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_dienstschichten`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_dienstuebergaben`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_dienstuebergabe_anfragen`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_fernmeldeplaene`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_melderauftraege`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_betriebsereignisse`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
     OR EXISTS (
       SELECT 1 FROM `nv_betriebsereignis_kopf`
        WHERE `einsatz_id` = incident.`einsatz_id`
     )
   );
SET @estab_command_post_migration_write = NULL;

DROP TRIGGER IF EXISTS `estab_command_post_incident_insert`;
DROP TRIGGER IF EXISTS `estab_command_post_incident_update`;
DELIMITER //
CREATE TRIGGER `estab_command_post_incident_insert`
BEFORE INSERT ON `nv_einsaetze` FOR EACH ROW
BEGIN
  IF NEW.`fuehrungsstellenname` IS NULL
     OR BINARY NEW.`fuehrungsstellenname`
       <> BINARY TRIM(NEW.`fuehrungsstellenname`)
     OR NEW.`fuehrungsstellenname`
       REGEXP '^[[:space:]]|[[:space:]]$'
     OR NEW.`fuehrungsstellenname` NOT REGEXP '[^[:space:]]'
     OR NEW.`fuehrungsstellenname` REGEXP '\\p{C}'
     OR NEW.`fuehrungsstellenname_gesperrt` <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'New incident requires an unlocked valid command-post name';
  END IF;
END//

CREATE TRIGGER `estab_command_post_incident_update`
BEFORE UPDATE ON `nv_einsaetze` FOR EACH ROW
BEGIN
  IF NOT (
       BINARY NEW.`fuehrungsstellenname`
       <=> BINARY OLD.`fuehrungsstellenname`
     )
     OR NEW.`fuehrungsstellenname_gesperrt`
       <> OLD.`fuehrungsstellenname_gesperrt` THEN
    IF COALESCE(@estab_command_post_migration_write, 0) = 1
       AND CURRENT_USER() LIKE 'root@%' THEN
      IF NEW.`fuehrungsstellenname_gesperrt` = 1
         AND NEW.`fuehrungsstellenname` IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Locked command-post name cannot be NULL';
      END IF;
    ELSEIF @estab_command_post_lock_write_id <=> OLD.`einsatz_id` THEN
      IF NOT (
           BINARY NEW.`fuehrungsstellenname`
           <=> BINARY OLD.`fuehrungsstellenname`
         )
         OR OLD.`fuehrungsstellenname_gesperrt` <> 0
         OR NEW.`fuehrungsstellenname_gesperrt` <> 1
         OR NEW.`fuehrungsstellenname` IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Invalid internal command-post lock transition';
      END IF;
    ELSEIF @estab_command_post_admin_write_id <=> OLD.`einsatz_id` THEN
      IF OLD.`estab_status` <> 'open'
         OR NEW.`estab_status` <> OLD.`estab_status`
         OR OLD.`fuehrungsstellenname_gesperrt` <> 0
         OR NEW.`fuehrungsstellenname_gesperrt` NOT IN (0, 1)
         OR NEW.`fuehrungsstellenname` IS NULL THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Invalid administrative command-post transition';
      END IF;
    ELSE
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Direct command-post name manipulation is blocked';
    END IF;

    IF NEW.`fuehrungsstellenname` IS NOT NULL
       AND (
         BINARY NEW.`fuehrungsstellenname`
           <> BINARY TRIM(NEW.`fuehrungsstellenname`)
         OR NEW.`fuehrungsstellenname`
           REGEXP '^[[:space:]]|[[:space:]]$'
         OR NEW.`fuehrungsstellenname` NOT REGEXP '[^[:space:]]'
         OR NEW.`fuehrungsstellenname` REGEXP '\\p{C}'
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Command-post name is not canonical';
    END IF;
  END IF;
END//
DELIMITER ;

DROP FUNCTION IF EXISTS estab_incident_command_post_for_write;
DELIMITER //
CREATE FUNCTION estab_incident_command_post_for_write(
  requested_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
MODIFIES SQL DATA
SQL SECURITY DEFINER
COMMENT 'estab:migration:97:incident-command-post-write-lock:v1'
BEGIN
  DECLARE configured_name VARCHAR(128) DEFAULT NULL;
  DECLARE command_post_locked TINYINT UNSIGNED DEFAULT NULL;
  DECLARE previous_internal_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    SET @estab_command_post_lock_write_id = previous_internal_id;
    RESIGNAL;
  END;

  IF requested_incident IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Operational write has no incident';
  END IF;

  SELECT incident.`fuehrungsstellenname`,
         incident.`fuehrungsstellenname_gesperrt`
    INTO configured_name, command_post_locked
    FROM `nv_einsatz_status` AS active_incident
    JOIN `nv_einsaetze` AS incident
      ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
   WHERE active_incident.`singleton_id` = 1
     AND active_incident.`active_einsatz_id` = requested_incident
     AND incident.`estab_status` = 'open'
   FOR UPDATE;

  IF configured_name IS NULL
     OR BINARY configured_name <> BINARY TRIM(configured_name)
     OR configured_name REGEXP '^[[:space:]]|[[:space:]]$'
     OR configured_name NOT REGEXP '[^[:space:]]'
     OR configured_name REGEXP '\\p{C}' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational write requires a configured command-post name';
  END IF;

  IF command_post_locked = 0 THEN
    SET previous_internal_id = @estab_command_post_lock_write_id;
    SET @estab_command_post_lock_write_id = requested_incident;
    UPDATE `nv_einsaetze`
       SET `fuehrungsstellenname_gesperrt` = 1
     WHERE `einsatz_id` = requested_incident
       AND `fuehrungsstellenname_gesperrt` = 0;
    SET @estab_command_post_lock_write_id = previous_internal_id;
  END IF;
  RETURN requested_incident;
END//
DELIMITER ;

-- Replace migration 50/80's legacy writer functions without weakening their
-- established incident/reassignment/legal-hold boundaries.
DROP FUNCTION IF EXISTS estab_incident_for_insert;
DROP FUNCTION IF EXISTS estab_incident_for_update;
DROP FUNCTION IF EXISTS estab_incident_for_delete;
DELIMITER //
CREATE FUNCTION estab_incident_for_insert(
  requested_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
MODIFIES SQL DATA
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
  RETURN estab_incident_command_post_for_write(active_incident);
END//

CREATE FUNCTION estab_incident_for_update(
  previous_incident BIGINT UNSIGNED,
  requested_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
MODIFIES SQL DATA
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
  RETURN estab_incident_command_post_for_write(previous_incident);
END//

CREATE FUNCTION estab_incident_for_delete(
  previous_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
MODIFIES SQL DATA
SQL SECURITY DEFINER
BEGIN
  DECLARE active_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE legal_hold TINYINT UNSIGNED DEFAULT 0;
  SELECT `active_einsatz_id` INTO active_incident
    FROM `nv_einsatz_status`
   WHERE `singleton_id` = 1;
  IF previous_incident IS NULL
     OR active_incident IS NULL
     OR previous_incident <> active_incident THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Operational delete targets inactive incident';
  END IF;
  SELECT `estab_legal_hold` INTO legal_hold
    FROM `nv_einsaetze`
   WHERE `einsatz_id` = previous_incident;
  IF legal_hold = 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Operational delete blocked by legal hold';
  END IF;
  RETURN estab_incident_command_post_for_write(previous_incident);
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_97_final_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_97_final_validate()
BEGIN
  IF (
    SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
         'estab_command_post_incident_insert',
         'estab_command_post_incident_update'
       )
  ) <> 2
  OR (
    SELECT COUNT(*)
      FROM information_schema.routines
     WHERE routine_schema = DATABASE()
       AND routine_type = 'FUNCTION'
       AND routine_name IN (
         'estab_incident_command_post_for_write',
         'estab_incident_for_insert',
         'estab_incident_for_update',
         'estab_incident_for_delete'
       )
  ) <> 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Command-post name migration failed: write boundary is incomplete';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_97_final_validate();
DROP PROCEDURE estab_migrate_97_final_validate;
