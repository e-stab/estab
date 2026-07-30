-- Revision-safe operational evidence, formal incident closure, and retention.
--
-- This migration deliberately extends the checksum-pinned incident model
-- instead of changing migration 50.  New message workflow events and ETB
-- records are append-only.  A formal close is distinct from merely pausing an
-- incident and establishes the minimum one-year retention boundary required
-- for the official incident log.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_80_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_80_preflight()
BEGIN
  DECLARE required_tables INTEGER DEFAULT 0;
  DECLARE conflicting_evidence_tables INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO required_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_type = 'BASE TABLE'
     AND table_name IN (
       'nv_einsaetze',
       'nv_einsatz_status',
       'nv_einsatz_ereignisse',
       'nv_nachrichten',
       'nv_anhang',
       'nv_etb'
     );
  IF required_tables <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: required incident table is missing';
  END IF;

  SELECT COUNT(*) INTO conflicting_evidence_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_nachrichten_ereignisse',
       'nv_nachrichten_nachweiskopf'
     )
     AND (
       table_type <> 'BASE TABLE'
       OR engine <> 'InnoDB'
       OR table_comment <>
         'estab:migration:80-dv-evidence-retention:v1'
     );
  IF conflicting_evidence_tables <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: conflicting message event table';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_80_preflight();
DROP PROCEDURE estab_migrate_80_preflight;

ALTER TABLE `nv_einsaetze`
  ADD COLUMN IF NOT EXISTS `estab_status`
    ENUM('open','closed') NOT NULL DEFAULT 'open' AFTER `erstellt_von`,
  ADD COLUMN IF NOT EXISTS `estab_closed_at`
    DATETIME(6) NULL DEFAULT NULL AFTER `estab_status`,
  ADD COLUMN IF NOT EXISTS `estab_closed_by`
    VARCHAR(128) NULL DEFAULT NULL AFTER `estab_closed_at`,
  ADD COLUMN IF NOT EXISTS `estab_close_note`
    TEXT NULL DEFAULT NULL AFTER `estab_closed_by`,
  ADD COLUMN IF NOT EXISTS `estab_retain_until`
    DATETIME(6) NULL DEFAULT NULL AFTER `estab_close_note`,
  ADD COLUMN IF NOT EXISTS `estab_legal_hold`
    TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `estab_retain_until`,
  ADD COLUMN IF NOT EXISTS `estab_legal_hold_reason`
    VARCHAR(1000) NULL DEFAULT NULL AFTER `estab_legal_hold`,
  ADD COLUMN IF NOT EXISTS `estab_legal_hold_at`
    DATETIME(6) NULL DEFAULT NULL AFTER `estab_legal_hold_reason`,
  ADD COLUMN IF NOT EXISTS `estab_legal_hold_by`
    VARCHAR(128) NULL DEFAULT NULL AFTER `estab_legal_hold_at`;

DROP PROCEDURE IF EXISTS estab_migrate_80_validate_incident_columns;
DELIMITER //
CREATE PROCEDURE estab_migrate_80_validate_incident_columns()
BEGIN
  DECLARE canonical_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND (
       (column_name = 'estab_status'
         AND column_type = 'enum(''open'',''closed'')'
         AND is_nullable = 'NO'
         AND REPLACE(column_default, '''', '') = 'open')
       OR
       (column_name IN ('estab_closed_at', 'estab_retain_until',
                        'estab_legal_hold_at')
         AND data_type = 'datetime' AND datetime_precision = 6
         AND is_nullable = 'YES')
       OR
       (column_name IN ('estab_closed_by', 'estab_legal_hold_by')
         AND data_type = 'varchar' AND character_maximum_length = 128
         AND is_nullable = 'YES')
       OR
       (column_name = 'estab_close_note'
         AND data_type = 'text' AND is_nullable = 'YES')
       OR
       (column_name = 'estab_legal_hold'
         AND data_type = 'tinyint' AND column_type LIKE 'tinyint%unsigned'
         AND is_nullable = 'NO' AND column_default = '0')
       OR
       (column_name = 'estab_legal_hold_reason'
         AND data_type = 'varchar' AND character_maximum_length = 1000
         AND is_nullable = 'YES')
     );
  IF canonical_columns <> 9 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: incompatible incident evidence column';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_80_validate_incident_columns();
DROP PROCEDURE estab_migrate_80_validate_incident_columns;

-- Existing migration-owned legacy data is already closed.  Real incidents
-- remain open until an administrator performs the new formal-close action;
-- a historic/planned `ende` value alone is intentionally not a formal close.
UPDATE `nv_einsaetze`
   SET `estab_status` = 'closed',
       `estab_closed_at` = COALESCE(`ende`, `erstellt_am`),
       `estab_closed_by` = 'schema-migration-80',
       `estab_close_note` =
         'Formal geschlossener Bestandsdatenraum aus Migration 50.',
       `estab_retain_until` =
         DATE_ADD(COALESCE(`ende`, `erstellt_am`), INTERVAL 1 YEAR)
 WHERE `kennung` = 'LEGACY-IMPORT'
   AND `erstellt_von` = 'schema-migration-50'
   AND `estab_status` = 'open';

ALTER TABLE `nv_etb`
  ADD COLUMN IF NOT EXISTS `estab_event_time`
    DATETIME(6) NULL DEFAULT NULL AFTER `etb_time`,
  ADD COLUMN IF NOT EXISTS `estab_recorded_at`
    DATETIME(6) NULL DEFAULT NULL AFTER `estab_event_time`,
  ADD COLUMN IF NOT EXISTS `estab_event_type`
    VARCHAR(32) NOT NULL DEFAULT 'ereignis' AFTER `estab_recorded_at`,
  ADD COLUMN IF NOT EXISTS `estab_message_id`
    BIGINT NULL DEFAULT NULL AFTER `estab_event_type`,
  ADD COLUMN IF NOT EXISTS `estab_attachment_id`
    BIGINT NULL DEFAULT NULL AFTER `estab_message_id`,
  ADD COLUMN IF NOT EXISTS `estab_reference`
    VARCHAR(255) NULL DEFAULT NULL AFTER `estab_attachment_id`,
  ADD COLUMN IF NOT EXISTS `estab_correction_of`
    INT NULL DEFAULT NULL AFTER `estab_reference`;

-- Migration 50 allowed updates while an incident was active.  Drop that
-- guard before the one-time legacy backfill; the append-only replacement is
-- installed below before application startup can proceed.
DROP TRIGGER IF EXISTS `estab_etb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bd_einsatz`;

UPDATE `nv_etb`
   SET `estab_event_time` = `etb_time`
 WHERE `estab_event_time` IS NULL;
UPDATE `nv_etb`
   SET `estab_recorded_at` = `etb_time`
 WHERE `estab_recorded_at` IS NULL;
UPDATE `nv_etb`
   SET `estab_event_type` = 'legacy_import'
 WHERE `estab_event_type` = 'ereignis';

ALTER TABLE `nv_etb`
  MODIFY COLUMN `estab_event_time`
    DATETIME(6) NOT NULL,
  MODIFY COLUMN `estab_recorded_at`
    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);

CREATE INDEX IF NOT EXISTS `idx_etb_einsatz_event_time`
  ON `nv_etb` (`einsatz_id`, `estab_event_time`, `etb_lfd-nr`);
CREATE INDEX IF NOT EXISTS `idx_etb_message`
  ON `nv_etb` (`estab_message_id`);
CREATE INDEX IF NOT EXISTS `idx_etb_attachment`
  ON `nv_etb` (`estab_attachment_id`);
CREATE INDEX IF NOT EXISTS `idx_etb_correction`
  ON `nv_etb` (`estab_correction_of`);

ALTER TABLE `nv_etb`
  DROP FOREIGN KEY IF EXISTS `fk_etb_message`;
ALTER TABLE `nv_etb`
  ADD CONSTRAINT `fk_etb_message`
    FOREIGN KEY (`estab_message_id`)
    REFERENCES `nv_nachrichten` (`00_lfd`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_etb`
  DROP FOREIGN KEY IF EXISTS `fk_etb_attachment`;
ALTER TABLE `nv_etb`
  ADD CONSTRAINT `fk_etb_attachment`
    FOREIGN KEY (`estab_attachment_id`)
    REFERENCES `nv_anhang` (`lfd-nr`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE `nv_etb`
  DROP FOREIGN KEY IF EXISTS `fk_etb_correction`;
ALTER TABLE `nv_etb`
  ADD CONSTRAINT `fk_etb_correction`
    FOREIGN KEY (`estab_correction_of`)
    REFERENCES `nv_etb` (`etb_lfd-nr`)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

DROP FUNCTION IF EXISTS estab_message_event_hash;
DELIMITER //
CREATE FUNCTION estab_message_event_hash(
  incident_id BIGINT UNSIGNED,
  message_id BIGINT,
  event_type VARCHAR(32),
  occurred_at DATETIME(6),
  recorded_at DATETIME(6),
  actor_user VARCHAR(128),
  actor_code VARCHAR(6),
  actor_function VARCHAR(32),
  from_status SMALLINT,
  to_status SMALLINT,
  snapshot_sha256 CHAR(64),
  previous_event_sha256 CHAR(64)
) RETURNS CHAR(64) CHARACTER SET ascii
DETERMINISTIC
NO SQL
SQL SECURITY INVOKER
RETURN LOWER(SHA2(CONCAT(
  'estab-message-event-v1', CHAR(10),
  CAST(incident_id AS CHAR), CHAR(10),
  CAST(message_id AS CHAR), CHAR(10),
  event_type, CHAR(10),
  DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:%s.%f'), CHAR(10),
  DATE_FORMAT(recorded_at, '%Y-%m-%d %H:%i:%s.%f'), CHAR(10),
  actor_user, CHAR(10),
  actor_code, CHAR(10),
  actor_function, CHAR(10),
  IFNULL(CAST(from_status AS CHAR), 'null'), CHAR(10),
  IFNULL(CAST(to_status AS CHAR), 'null'), CHAR(10),
  snapshot_sha256, CHAR(10),
  IFNULL(previous_event_sha256, '')
), 256))//
DELIMITER ;

CREATE TABLE IF NOT EXISTS `nv_nachrichten_ereignisse` (
  `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT NOT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `recorded_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `actor_user` VARCHAR(128) NOT NULL,
  `actor_code` VARCHAR(6) NOT NULL,
  `actor_function` VARCHAR(32) NOT NULL,
  `from_status` SMALLINT NULL DEFAULT NULL,
  `to_status` SMALLINT NULL DEFAULT NULL,
  `field_snapshot` LONGTEXT NOT NULL,
  `snapshot_sha256` CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `previous_event_sha256` CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `event_sha256` CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_message_events_message` (`message_id`, `event_id`),
  KEY `idx_message_events_incident_time`
    (`einsatz_id`, `occurred_at`, `event_id`),
  CONSTRAINT `chk_message_events_snapshot`
    CHECK (JSON_VALID(`field_snapshot`)),
  CONSTRAINT `chk_message_events_hashes`
    CHECK (
      `snapshot_sha256` REGEXP BINARY '^[0-9a-f]{64}$'
      AND `event_sha256` REGEXP BINARY '^[0-9a-f]{64}$'
      AND (
        `previous_event_sha256` IS NULL
        OR `previous_event_sha256` REGEXP BINARY '^[0-9a-f]{64}$'
      )
    ),
  CONSTRAINT `fk_message_events_incident`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_message_events_message`
    FOREIGN KEY (`message_id`) REFERENCES `nv_nachrichten` (`00_lfd`)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:80-dv-evidence-retention:v1';

CREATE TABLE IF NOT EXISTS `nv_nachrichten_nachweiskopf` (
  `message_id` BIGINT NOT NULL,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_event_sha256` CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`message_id`),
  KEY `idx_message_evidence_heads_incident` (`einsatz_id`, `message_id`),
  CONSTRAINT `chk_message_evidence_head_hash`
    CHECK (
      (`event_count` = 0 AND `last_event_sha256` IS NULL)
      OR
      (`event_count` > 0
       AND `last_event_sha256` REGEXP BINARY '^[0-9a-f]{64}$')
    ),
  CONSTRAINT `fk_message_evidence_heads_incident`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_message_evidence_heads_message`
    FOREIGN KEY (`message_id`) REFERENCES `nv_nachrichten` (`00_lfd`)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:80-dv-evidence-retention:v1';

-- Start every pre-existing message chain with a deterministic snapshot.  A
-- retry skips messages already seeded and then rebuilds all chain heads from
-- the immutable event table.
DROP TEMPORARY TABLE IF EXISTS estab_migrate_80_message_status;
CREATE TEMPORARY TABLE estab_migrate_80_message_status (
  `message_id` BIGINT NOT NULL PRIMARY KEY,
  `message_status` SMALLINT NULL
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS estab_migrate_80_capture_message_status;
DELIMITER //
CREATE PROCEDURE estab_migrate_80_capture_message_status()
BEGIN
  DECLARE status_column_present INTEGER DEFAULT 0;
  SELECT COUNT(*) INTO status_column_present
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = 'x00_status';
  IF status_column_present = 1 THEN
    SET @estab_migrate_80_capture_status =
      'INSERT INTO estab_migrate_80_message_status
         (`message_id`, `message_status`)
       SELECT `00_lfd`, `x00_status` FROM `nv_nachrichten`';
    PREPARE estab_migrate_80_capture_status_statement
       FROM @estab_migrate_80_capture_status;
    EXECUTE estab_migrate_80_capture_status_statement;
    DEALLOCATE PREPARE estab_migrate_80_capture_status_statement;
    SET @estab_migrate_80_capture_status = NULL;
  ELSE
    INSERT INTO estab_migrate_80_message_status
      (`message_id`, `message_status`)
    SELECT `00_lfd`, NULL FROM `nv_nachrichten`;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_80_capture_message_status();
DROP PROCEDURE estab_migrate_80_capture_message_status;

INSERT INTO `nv_nachrichten_ereignisse`
  (`einsatz_id`, `message_id`, `event_type`, `occurred_at`, `recorded_at`,
   `actor_user`, `actor_code`, `actor_function`, `from_status`, `to_status`,
   `field_snapshot`, `snapshot_sha256`, `previous_event_sha256`,
   `event_sha256`)
SELECT
  seed.`einsatz_id`,
  seed.`message_id`,
  'legacy_import',
  seed.`evidence_time`,
  seed.`evidence_time`,
  'schema-migration-80',
  '',
  'Migration',
  NULL,
  seed.`message_status`,
  seed.`snapshot_json`,
  LOWER(SHA2(seed.`snapshot_json`, 256)),
  NULL,
  estab_message_event_hash(
    seed.`einsatz_id`,
    seed.`message_id`,
    'legacy_import',
    seed.`evidence_time`,
    seed.`evidence_time`,
    'schema-migration-80',
    '',
    'Migration',
    NULL,
    seed.`message_status`,
    LOWER(SHA2(seed.`snapshot_json`, 256)),
    NULL
  )
  FROM (
  SELECT
    n.`einsatz_id`,
    n.`00_lfd` AS `message_id`,
    captured_status.`message_status`,
    COALESCE(
      n.`01_datum`,
      n.`12_abfzeit`,
      n.`02_zeit`,
      CAST('1970-01-01 00:00:00' AS DATETIME)
    ) AS `evidence_time`,
    JSON_OBJECT(
      'legacy_import', TRUE,
      'last_access',
        IF(n.`99_lstacc` IS NULL, NULL,
          DATE_FORMAT(n.`99_lstacc`, '%Y-%m-%d %H:%i:%s')),
      'message_status', captured_status.`message_status`
    ) AS `snapshot_json`
  FROM `nv_nachrichten` AS n
  JOIN estab_migrate_80_message_status AS captured_status
    ON captured_status.`message_id` = n.`00_lfd`
) AS seed
WHERE NOT EXISTS (
  SELECT 1
    FROM `nv_nachrichten_ereignisse` AS existing
   WHERE existing.`message_id` = seed.`message_id`
);

DROP TEMPORARY TABLE estab_migrate_80_message_status;

INSERT INTO `nv_nachrichten_nachweiskopf`
  (`message_id`, `einsatz_id`, `event_count`, `last_event_sha256`,
   `updated_at`)
SELECT
  latest.`message_id`,
  latest.`einsatz_id`,
  latest.`event_count`,
  event_row.`event_sha256`,
  event_row.`recorded_at`
FROM (
  SELECT
    `message_id`,
    MIN(`einsatz_id`) AS `einsatz_id`,
    COUNT(*) AS `event_count`,
    MAX(`event_id`) AS `last_event_id`
  FROM `nv_nachrichten_ereignisse`
  GROUP BY `message_id`
) AS latest
JOIN `nv_nachrichten_ereignisse` AS event_row
  ON event_row.`event_id` = latest.`last_event_id`
ON DUPLICATE KEY UPDATE
  `einsatz_id` = VALUES(`einsatz_id`),
  `event_count` = VALUES(`event_count`),
  `last_event_sha256` = VALUES(`last_event_sha256`),
  `updated_at` = VALUES(`updated_at`);

-- Validate all additive shapes before replacing or adding runtime guards.
DROP PROCEDURE IF EXISTS estab_migrate_80_validate_schema;
DELIMITER //
CREATE PROCEDURE estab_migrate_80_validate_schema()
BEGIN
  DECLARE canonical_event_tables INTEGER DEFAULT 0;
  DECLARE canonical_etb_columns INTEGER DEFAULT 0;
  DECLARE canonical_event_columns INTEGER DEFAULT 0;
  DECLARE canonical_head_columns INTEGER DEFAULT 0;
  DECLARE invalid_etb_references BIGINT DEFAULT 0;
  DECLARE invalid_event_rows BIGINT DEFAULT 0;
  DECLARE invalid_chain_links BIGINT DEFAULT 0;
  DECLARE invalid_chain_heads BIGINT DEFAULT 0;

  SELECT COUNT(*) INTO canonical_event_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_nachrichten_ereignisse',
       'nv_nachrichten_nachweiskopf'
     )
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation LIKE 'utf8mb4\_%'
     AND table_comment =
       'estab:migration:80-dv-evidence-retention:v1';
  IF canonical_event_tables <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: invalid message evidence table';
  END IF;

  SELECT COUNT(*) INTO canonical_event_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten_ereignisse'
     AND (
       (column_name IN ('event_id', 'einsatz_id')
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'NO')
       OR
       (column_name = 'message_id'
         AND data_type = 'bigint' AND is_nullable = 'NO')
       OR
       (column_name = 'event_type'
         AND data_type = 'varchar' AND character_maximum_length = 32
         AND is_nullable = 'NO')
       OR
       (column_name IN ('occurred_at', 'recorded_at')
         AND data_type = 'datetime' AND datetime_precision = 6
         AND is_nullable = 'NO')
       OR
       (column_name = 'actor_user'
         AND data_type = 'varchar' AND character_maximum_length = 128
         AND is_nullable = 'NO')
       OR
       (column_name = 'actor_code'
         AND data_type = 'varchar' AND character_maximum_length = 6
         AND is_nullable = 'NO')
       OR
       (column_name = 'actor_function'
         AND data_type = 'varchar' AND character_maximum_length = 32
         AND is_nullable = 'NO')
       OR
       (column_name IN ('from_status', 'to_status')
         AND data_type = 'smallint' AND is_nullable = 'YES')
       OR
       (column_name = 'field_snapshot'
         AND data_type = 'longtext' AND is_nullable = 'NO')
       OR
       (column_name IN ('snapshot_sha256', 'event_sha256')
         AND data_type = 'char' AND character_maximum_length = 64
         AND is_nullable = 'NO')
       OR
       (column_name = 'previous_event_sha256'
         AND data_type = 'char' AND character_maximum_length = 64
         AND is_nullable = 'YES')
     );
  IF canonical_event_columns <> 15 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: incompatible message event column';
  END IF;

  SELECT COUNT(*) INTO canonical_head_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten_nachweiskopf'
     AND (
       (column_name = 'message_id'
         AND data_type = 'bigint' AND is_nullable = 'NO')
       OR
       (column_name = 'einsatz_id'
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'NO')
       OR
       (column_name = 'event_count'
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'NO')
       OR
       (column_name = 'last_event_sha256'
         AND data_type = 'char' AND character_maximum_length = 64
         AND is_nullable = 'YES')
       OR
       (column_name = 'updated_at'
         AND data_type = 'datetime' AND datetime_precision = 6
         AND is_nullable = 'NO')
     );
  IF canonical_head_columns <> 5 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: incompatible evidence head column';
  END IF;

  SELECT COUNT(*) INTO canonical_etb_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_etb'
     AND (
       (column_name IN ('estab_event_time', 'estab_recorded_at')
         AND data_type = 'datetime' AND datetime_precision = 6
         AND is_nullable = 'NO')
       OR
       (column_name = 'estab_event_type'
         AND data_type = 'varchar' AND character_maximum_length = 32
         AND is_nullable = 'NO')
       OR
       (column_name IN ('estab_message_id', 'estab_attachment_id')
         AND data_type = 'bigint' AND is_nullable = 'YES')
       OR
       (column_name = 'estab_reference'
         AND data_type = 'varchar' AND character_maximum_length = 255
         AND is_nullable = 'YES')
       OR
       (column_name = 'estab_correction_of'
         AND data_type = 'int' AND is_nullable = 'YES')
     );
  IF canonical_etb_columns <> 7 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: incompatible ETB evidence column';
  END IF;

  SELECT COUNT(*) INTO invalid_etb_references
    FROM `nv_etb` AS entry_row
    LEFT JOIN `nv_nachrichten` AS linked_message
      ON linked_message.`00_lfd` = entry_row.`estab_message_id`
    LEFT JOIN `nv_anhang` AS linked_attachment
      ON linked_attachment.`lfd-nr` = entry_row.`estab_attachment_id`
    LEFT JOIN `nv_etb` AS original_entry
      ON original_entry.`etb_lfd-nr` = entry_row.`estab_correction_of`
   WHERE (
       entry_row.`estab_message_id` IS NOT NULL
       AND (
         linked_message.`00_lfd` IS NULL
         OR linked_message.`einsatz_id` <> entry_row.`einsatz_id`
       )
     )
      OR (
       entry_row.`estab_attachment_id` IS NOT NULL
       AND (
         linked_attachment.`lfd-nr` IS NULL
         OR linked_attachment.`einsatz_id` <> entry_row.`einsatz_id`
       )
     )
      OR (
       entry_row.`estab_correction_of` IS NULL
       AND entry_row.`estab_event_type` = 'korrektur'
     )
      OR (
       entry_row.`estab_correction_of` IS NOT NULL
       AND (
         entry_row.`estab_event_type` <> 'korrektur'
         OR entry_row.`estab_correction_of` = entry_row.`etb_lfd-nr`
         OR original_entry.`etb_lfd-nr` IS NULL
         OR original_entry.`einsatz_id` <> entry_row.`einsatz_id`
         OR original_entry.`estab_event_type` = 'korrektur'
         OR original_entry.`estab_correction_of` IS NOT NULL
       )
     );
  IF invalid_etb_references <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: invalid ETB incident reference';
  END IF;

  SELECT COUNT(*) INTO invalid_event_rows
    FROM `nv_nachrichten_ereignisse`
   WHERE BINARY `snapshot_sha256`
           <> BINARY LOWER(SHA2(`field_snapshot`, 256))
      OR BINARY `event_sha256` <> BINARY estab_message_event_hash(
        `einsatz_id`, `message_id`, `event_type`, `occurred_at`, `recorded_at`,
        `actor_user`, `actor_code`, `actor_function`,
        `from_status`, `to_status`, `snapshot_sha256`,
        `previous_event_sha256`
      );
  IF invalid_event_rows <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: invalid message event hash';
  END IF;

  SELECT COUNT(*) INTO invalid_chain_links
    FROM (
      SELECT
        `previous_event_sha256`,
        LAG(`event_sha256`) OVER (
          PARTITION BY `message_id` ORDER BY `event_id`
        ) AS `expected_previous`
      FROM `nv_nachrichten_ereignisse`
    ) AS chain_rows
   WHERE NOT (`previous_event_sha256` <=> `expected_previous`);
  IF invalid_chain_links <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: broken message event chain';
  END IF;

  SELECT COUNT(*) INTO invalid_chain_heads
    FROM `nv_nachrichten_nachweiskopf` AS head
    LEFT JOIN (
      SELECT
        events.`message_id`,
        COUNT(*) AS `event_count`,
        SUBSTRING_INDEX(
          GROUP_CONCAT(
            events.`event_sha256`
            ORDER BY events.`event_id` DESC SEPARATOR ','
          ),
          ',',
          1
        ) AS `last_event_sha256`
      FROM `nv_nachrichten_ereignisse` AS events
      GROUP BY events.`message_id`
    ) AS actual
      ON actual.`message_id` = head.`message_id`
   WHERE actual.`message_id` IS NULL
      OR head.`event_count` <> actual.`event_count`
      OR head.`last_event_sha256` <> actual.`last_event_sha256`;
  IF invalid_chain_heads <> 0
     OR (
       SELECT COUNT(*) FROM `nv_nachrichten_nachweiskopf`
     ) <> (
       SELECT COUNT(DISTINCT `message_id`)
         FROM `nv_nachrichten_ereignisse`
     )
     OR (
       SELECT COUNT(*) FROM `nv_nachrichten_nachweiskopf`
     ) <> (
       SELECT COUNT(*) FROM `nv_nachrichten`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Evidence migration blocked: invalid message evidence head';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_80_validate_schema();
DROP PROCEDURE estab_migrate_80_validate_schema;

-- Extend migration 50's shared delete boundary: a legal hold also protects an
-- incident that is still active.  Closed incidents remain protected because
-- they can never be the active incident.
DELIMITER //
CREATE OR REPLACE FUNCTION estab_incident_for_delete(
  previous_incident BIGINT UNSIGNED
) RETURNS BIGINT UNSIGNED
NOT DETERMINISTIC
READS SQL DATA
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
  RETURN previous_incident;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_einsatz_status_bi_open`;
DROP TRIGGER IF EXISTS `estab_einsatz_status_bu_open`;
DROP TRIGGER IF EXISTS `estab_einsaetze_bu_evidence`;
DROP TRIGGER IF EXISTS `estab_einsaetze_bd_retention`;
DROP TRIGGER IF EXISTS `estab_etb_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_message_events_bi_evidence`;
DROP TRIGGER IF EXISTS `estab_message_events_bu_append_only`;
DROP TRIGGER IF EXISTS `estab_message_events_bd_append_only`;
DROP TRIGGER IF EXISTS `estab_message_evidence_heads_bd_protected`;
DROP TRIGGER IF EXISTS `estab_incident_events_bu_append_only`;
DROP TRIGGER IF EXISTS `estab_incident_events_bd_append_only`;

DELIMITER //
CREATE TRIGGER `estab_einsatz_status_bi_open`
BEFORE INSERT ON `nv_einsatz_status` FOR EACH ROW
BEGIN
  IF NEW.`active_einsatz_id` IS NOT NULL
     AND NOT EXISTS (
       SELECT 1 FROM `nv_einsaetze`
        WHERE `einsatz_id` = NEW.`active_einsatz_id`
          AND `estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Closed incident cannot become active';
  END IF;
END//

CREATE TRIGGER `estab_einsatz_status_bu_open`
BEFORE UPDATE ON `nv_einsatz_status` FOR EACH ROW
BEGIN
  IF NEW.`active_einsatz_id` IS NOT NULL
     AND NOT EXISTS (
       SELECT 1 FROM `nv_einsaetze`
        WHERE `einsatz_id` = NEW.`active_einsatz_id`
          AND `estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Closed incident cannot become active';
  END IF;
END//

CREATE TRIGGER `estab_einsaetze_bu_evidence`
BEFORE UPDATE ON `nv_einsaetze` FOR EACH ROW
BEGIN
  IF OLD.`estab_status` = 'closed'
     AND NEW.`estab_status` <> 'closed' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Formal incident close is irreversible';
  END IF;
  IF OLD.`estab_status` = 'closed'
     AND (
       NOT (NEW.`estab_closed_at` <=> OLD.`estab_closed_at`)
       OR NOT (NEW.`estab_closed_by` <=> OLD.`estab_closed_by`)
       OR NOT (NEW.`estab_close_note` <=> OLD.`estab_close_note`)
       OR NOT (NEW.`estab_retain_until` <=> OLD.`estab_retain_until`)
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Formal incident close evidence is immutable';
  END IF;
  IF NEW.`estab_status` = 'closed'
     AND EXISTS (
       SELECT 1 FROM `nv_einsatz_status`
        WHERE `singleton_id` = 1
          AND `active_einsatz_id` = OLD.`einsatz_id`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Active incident must be deactivated before close';
  END IF;
END//

CREATE TRIGGER `estab_einsaetze_bd_retention`
BEFORE DELETE ON `nv_einsaetze` FOR EACH ROW
BEGIN
  IF OLD.`estab_legal_hold` = 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Incident deletion blocked by legal hold';
  END IF;
  IF OLD.`estab_retain_until` IS NULL
     OR NOW(6) < OLD.`estab_retain_until` THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Incident deletion blocked by retention policy';
  END IF;
END//

CREATE TRIGGER `estab_etb_bi_einsatz`
BEFORE INSERT ON `nv_etb` FOR EACH ROW
BEGIN
  DECLARE linked_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE linked_event_type VARCHAR(32) DEFAULT NULL;
  DECLARE linked_correction INT DEFAULT NULL;
  SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
  IF NEW.`estab_event_time` IS NULL THEN
    SET NEW.`estab_event_time` = NOW(6);
  END IF;
  SET NEW.`estab_recorded_at` = NOW(6);
  SET NEW.`etb_time` = NEW.`estab_event_time`;

  IF NEW.`estab_message_id` IS NOT NULL THEN
    SELECT `einsatz_id` INTO linked_incident
      FROM `nv_nachrichten`
     WHERE `00_lfd` = NEW.`estab_message_id`;
    IF linked_incident IS NULL
       OR linked_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB message link targets another incident';
    END IF;
  END IF;

  IF NEW.`estab_attachment_id` IS NOT NULL THEN
    SET linked_incident = NULL;
    SELECT `einsatz_id` INTO linked_incident
      FROM `nv_anhang`
     WHERE `lfd-nr` = NEW.`estab_attachment_id`;
    IF linked_incident IS NULL
       OR linked_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB attachment link targets another incident';
    END IF;
  END IF;

  IF NEW.`estab_correction_of` IS NOT NULL THEN
    IF NEW.`etb_lfd-nr` IS NOT NULL
       AND NEW.`etb_lfd-nr` <> 0
       AND NEW.`estab_correction_of` = NEW.`etb_lfd-nr` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB correction cannot reference itself';
    END IF;
    SET linked_incident = NULL;
    SET linked_event_type = NULL;
    SET linked_correction = NULL;
    SELECT `einsatz_id`, `estab_event_type`, `estab_correction_of`
      INTO linked_incident, linked_event_type, linked_correction
      FROM `nv_etb`
     WHERE `etb_lfd-nr` = NEW.`estab_correction_of`;
    IF linked_incident IS NULL
       OR linked_incident <> NEW.`einsatz_id`
       OR NEW.`estab_event_type` <> 'korrektur'
       OR linked_event_type = 'korrektur'
       OR linked_correction IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB correction target is invalid';
    END IF;
  ELSEIF NEW.`estab_event_type` = 'korrektur' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB correction requires an original entry';
  END IF;
END//

CREATE TRIGGER `estab_etb_bu_einsatz`
BEFORE UPDATE ON `nv_etb` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ETB entries are append-only; write a correction';
END//

CREATE TRIGGER `estab_etb_bd_einsatz`
BEFORE DELETE ON `nv_etb` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ETB entries are protected by retention policy';
END//

CREATE TRIGGER `estab_message_events_bi_evidence`
BEFORE INSERT ON `nv_nachrichten_ereignisse` FOR EACH ROW
BEGIN
  DECLARE linked_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE head_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE head_hash CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;
  DECLARE expected_hash CHAR(64)
    CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;
  SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
  SELECT `einsatz_id` INTO linked_incident
    FROM `nv_nachrichten`
   WHERE `00_lfd` = NEW.`message_id`;
  IF linked_incident IS NULL
     OR linked_incident <> NEW.`einsatz_id` THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Message event targets another incident';
  END IF;
  IF BINARY NEW.`snapshot_sha256`
       <> BINARY LOWER(SHA2(NEW.`field_snapshot`, 256)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Message event snapshot hash is invalid';
  END IF;
  SET expected_hash = estab_message_event_hash(
    NEW.`einsatz_id`,
    NEW.`message_id`,
    NEW.`event_type`,
    NEW.`occurred_at`,
    NEW.`recorded_at`,
    NEW.`actor_user`,
    NEW.`actor_code`,
    NEW.`actor_function`,
    NEW.`from_status`,
    NEW.`to_status`,
    NEW.`snapshot_sha256`,
    NEW.`previous_event_sha256`
  );
  IF BINARY NEW.`event_sha256` <> BINARY expected_hash THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Message event hash is invalid';
  END IF;

  INSERT IGNORE INTO `nv_nachrichten_nachweiskopf`
    (`message_id`, `einsatz_id`, `event_count`, `last_event_sha256`,
     `updated_at`)
  VALUES
    (NEW.`message_id`, NEW.`einsatz_id`, 0, NULL, NEW.`recorded_at`);

  SELECT `einsatz_id`, `last_event_sha256`
    INTO head_incident, head_hash
    FROM `nv_nachrichten_nachweiskopf`
   WHERE `message_id` = NEW.`message_id`
   FOR UPDATE;
  IF head_incident IS NULL OR head_incident <> NEW.`einsatz_id` THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Message evidence head targets another incident';
  END IF;
  IF NOT (NEW.`previous_event_sha256` <=> head_hash) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Message event previous hash is invalid';
  END IF;
  UPDATE `nv_nachrichten_nachweiskopf`
     SET `event_count` = `event_count` + 1,
         `last_event_sha256` = NEW.`event_sha256`,
         `updated_at` = NEW.`recorded_at`
   WHERE `message_id` = NEW.`message_id`;
END//

CREATE TRIGGER `estab_message_events_bu_append_only`
BEFORE UPDATE ON `nv_nachrichten_ereignisse` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Message workflow events are append-only';
END//

CREATE TRIGGER `estab_message_events_bd_append_only`
BEFORE DELETE ON `nv_nachrichten_ereignisse` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Message workflow events are protected evidence';
END//

CREATE TRIGGER `estab_message_evidence_heads_bd_protected`
BEFORE DELETE ON `nv_nachrichten_nachweiskopf` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Message evidence heads are protected evidence';
END//

CREATE TRIGGER `estab_incident_events_bu_append_only`
BEFORE UPDATE ON `nv_einsatz_ereignisse` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Incident lifecycle events are append-only';
END//

CREATE TRIGGER `estab_incident_events_bd_append_only`
BEFORE DELETE ON `nv_einsatz_ereignisse` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Incident lifecycle events are protected evidence';
END//
DELIMITER ;
