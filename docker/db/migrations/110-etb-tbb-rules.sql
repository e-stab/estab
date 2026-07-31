-- Incident-local ETB/TBB numbering and the operational logbook evidence model.
--
-- Global primary keys remain stable technical identifiers.  The additional
-- `estab_book_lfd` is the official consecutive number within one incident and
-- one book.  Existing rows are numbered deterministically by recording time
-- and global primary key.  Every incident owns both book heads from the moment
-- it is created. New numbers are allocated only by atomically advancing that
-- existing head row, so concurrent writers cannot receive the same number.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_110_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_110_preflight()
BEGIN
  DECLARE required_tables INTEGER DEFAULT 0;
  DECLARE required_incident_columns INTEGER DEFAULT 0;
  DECLARE required_etb_columns INTEGER DEFAULT 0;
  DECLARE required_tbb_columns INTEGER DEFAULT 0;
  DECLARE existing_head_table INTEGER DEFAULT 0;
  DECLARE canonical_head_table INTEGER DEFAULT 0;
  DECLARE canonical_head_columns INTEGER DEFAULT 0;
  DECLARE canonical_head_primary INTEGER DEFAULT 0;
  DECLARE canonical_head_foreign_key INTEGER DEFAULT 0;
  DECLARE existing_etb_columns INTEGER DEFAULT 0;
  DECLARE canonical_etb_columns INTEGER DEFAULT 0;
  DECLARE existing_tbb_columns INTEGER DEFAULT 0;
  DECLARE canonical_tbb_columns INTEGER DEFAULT 0;
  DECLARE existing_indexes INTEGER DEFAULT 0;
  DECLARE canonical_indexes INTEGER DEFAULT 0;
  DECLARE existing_foreign_keys INTEGER DEFAULT 0;
  DECLARE canonical_foreign_keys INTEGER DEFAULT 0;
  DECLARE existing_triggers INTEGER DEFAULT 0;
  DECLARE canonical_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO required_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_name IN ('nv_einsaetze', 'nv_nachrichten', 'nv_etb', 'nv_tbb');
  IF required_tables <> 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: required table is missing or incompatible';
  END IF;

  SELECT COUNT(*) INTO required_incident_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND (
       (column_name = 'einsatz_id' AND data_type = 'bigint'
         AND column_type LIKE '%unsigned%' AND is_nullable = 'NO')
       OR (column_name = 'estab_status'
         AND column_type = 'enum(''open'',''closed'')'
         AND is_nullable = 'NO')
       OR (column_name IN ('estab_closed_at', 'estab_retain_until')
         AND data_type = 'datetime' AND datetime_precision = 6
         AND is_nullable = 'YES')
     );
  IF required_incident_columns <> 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: incident evidence schema is incompatible';
  END IF;

  SELECT COUNT(*) INTO required_etb_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_etb'
     AND (
       (column_name = 'etb_lfd-nr' AND data_type = 'int'
         AND is_nullable = 'NO' AND extra LIKE '%auto_increment%')
       OR (column_name = 'einsatz_id' AND data_type = 'bigint'
         AND column_type LIKE '%unsigned%')
       OR (column_name IN ('estab_event_time', 'estab_recorded_at')
         AND data_type = 'datetime' AND datetime_precision = 6
         AND is_nullable = 'NO')
       OR (column_name = 'estab_event_type' AND data_type = 'varchar'
         AND character_maximum_length = 32 AND is_nullable = 'NO')
       OR (column_name = 'estab_correction_of' AND data_type = 'int'
         AND is_nullable = 'YES')
     );
  IF required_etb_columns <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: ETB predecessor schema is incompatible';
  END IF;

  SELECT COUNT(*) INTO required_tbb_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_tbb'
     AND column_name IN (
       'tbb_lfd-nr', 'einsatz_id', 'tbb_time', 'tbb_aktion', 'tbb_bemerk'
     );
  IF required_tbb_columns <> 5 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: TTB predecessor schema is incompatible';
  END IF;

  SELECT COUNT(*) INTO existing_head_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_logbuch_koepfe';
  SELECT COUNT(*) INTO canonical_head_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_logbuch_koepfe'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment = 'estab:migration:110:logbook-heads:v1';
  IF existing_head_table NOT IN (0, 1)
     OR canonical_head_table <> existing_head_table THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: foreign logbook-head table collision';
  END IF;

  IF existing_head_table = 1 THEN
    SELECT COUNT(*) INTO canonical_head_columns
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_logbuch_koepfe'
       AND (
         (column_name = 'einsatz_id' AND ordinal_position = 1
           AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
           AND is_nullable = 'NO')
         OR (column_name = 'buchart' AND ordinal_position = 2
           AND data_type = 'enum'
           AND column_type = 'enum(''ETB'',''TTB'')'
           AND character_set_name = 'ascii' AND collation_name = 'ascii_bin'
           AND is_nullable = 'NO')
         OR (column_name = 'next_lfd' AND ordinal_position = 3
           AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
           AND is_nullable = 'NO')
       );
    SELECT COUNT(*) INTO canonical_head_primary
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_logbuch_koepfe'
       AND index_name = 'PRIMARY'
       AND non_unique = 0
       AND (
         (seq_in_index = 1 AND column_name = 'einsatz_id')
         OR (seq_in_index = 2 AND column_name = 'buchart')
       );
    SELECT COUNT(*) INTO canonical_head_foreign_key
      FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'nv_logbuch_koepfe'
       AND constraint_name = 'fk_logbuch_koepfe_einsatz'
       AND referenced_table_name = 'nv_einsaetze'
       AND update_rule = 'RESTRICT'
       AND delete_rule = 'RESTRICT';
    IF (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'nv_logbuch_koepfe') <> 3
       OR canonical_head_columns <> 3
       OR (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'nv_logbuch_koepfe') <> 2
       OR canonical_head_primary <> 2
       OR canonical_head_foreign_key <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Logbook rules migration blocked: foreign logbook-head definition';
    END IF;
  END IF;

  SELECT COUNT(*) INTO existing_etb_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_etb'
     AND column_name = 'estab_book_lfd';
  SELECT COUNT(*) INTO canonical_etb_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_etb'
     AND column_name = 'estab_book_lfd'
     AND data_type = 'int'
     AND column_type LIKE 'int%unsigned'
     AND is_nullable IN ('YES', 'NO')
     AND extra = ''
     AND column_comment = 'estab:migration:110:etb-book-number:v1';
  IF existing_etb_columns NOT IN (0, 1)
     OR canonical_etb_columns <> existing_etb_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: foreign ETB book-number column collision';
  END IF;

  SELECT COUNT(*) INTO existing_tbb_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_tbb'
     AND column_name IN (
       'estab_book_lfd', 'estab_event_time', 'estab_recorded_at',
       'estab_entry_type', 'estab_message_id',
       'estab_personnel_duty', 'estab_channel',
       'estab_message_route', 'estab_operations', 'estab_receipt',
       'estab_correction_of'
     );
  SELECT COUNT(*) INTO canonical_tbb_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_tbb'
     AND (
       (column_name = 'estab_book_lfd' AND data_type = 'int'
         AND column_type LIKE 'int%unsigned' AND is_nullable IN ('YES', 'NO')
         AND extra = ''
         AND column_comment = 'estab:migration:110:tbb-book-number:v1')
       OR (column_name = 'estab_event_time' AND data_type = 'datetime'
         AND datetime_precision = 6 AND is_nullable IN ('YES', 'NO')
         AND extra = ''
         AND column_comment = 'estab:migration:110:tbb-event-time:v1')
       OR (column_name = 'estab_recorded_at' AND data_type = 'datetime'
         AND datetime_precision = 6 AND is_nullable IN ('YES', 'NO')
         AND extra = ''
         AND column_comment = 'estab:migration:110:tbb-recorded-at:v1')
       OR (column_name = 'estab_entry_type' AND data_type = 'varchar'
         AND character_maximum_length = 32
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND is_nullable IN ('YES', 'NO') AND extra = ''
         AND column_comment = 'estab:migration:110:tbb-entry-type:v1')
       OR (column_name = 'estab_message_id' AND data_type = 'bigint'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-message:v1')
       OR (column_name = 'estab_personnel_duty' AND data_type = 'text'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-personnel-duty:v1')
       OR (column_name = 'estab_channel' AND data_type = 'text'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-channel:v1')
       OR (column_name = 'estab_message_route' AND data_type = 'text'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-message-route:v1')
       OR (column_name = 'estab_operations' AND data_type = 'text'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-operations:v1')
       OR (column_name = 'estab_receipt' AND data_type = 'text'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-receipt:v1')
       OR (column_name = 'estab_correction_of' AND data_type = 'int'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-correction:v1')
     );
  IF existing_tbb_columns NOT IN (0, 11)
     OR canonical_tbb_columns <> existing_tbb_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: foreign or partial TTB column collision';
  END IF;

  SELECT COUNT(*) INTO existing_indexes
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND index_name IN (
       'uq_etb_einsatz_book_lfd', 'uq_tbb_einsatz_book_lfd',
       'uq_etb_attachment_id', 'idx_tbb_einsatz_event_time',
       'idx_tbb_message', 'idx_tbb_correction'
     );
  SELECT COUNT(*) INTO canonical_indexes
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND sub_part IS NULL
     AND (
       (table_name = 'nv_etb' AND index_name = 'uq_etb_einsatz_book_lfd'
         AND index_type = 'BTREE' AND non_unique = 0
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_book_lfd')))
       OR (table_name = 'nv_etb' AND index_name = 'uq_etb_attachment_id'
         AND index_type = 'BTREE' AND non_unique = 0
         AND seq_in_index = 1 AND column_name = 'estab_attachment_id')
       OR (table_name = 'nv_tbb' AND index_name = 'uq_tbb_einsatz_book_lfd'
         AND index_type = 'BTREE' AND non_unique = 0
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_book_lfd')))
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_einsatz_event_time'
         AND index_type = 'BTREE' AND non_unique = 1
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_event_time')
           OR (seq_in_index = 3 AND column_name = 'tbb_lfd-nr')))
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_correction'
         AND index_type = 'BTREE' AND non_unique = 1
         AND seq_in_index = 1 AND column_name = 'estab_correction_of')
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_message'
         AND index_type = 'BTREE' AND non_unique = 0
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_message_id')))
     );
  IF canonical_indexes <> existing_indexes
     OR existing_indexes NOT IN (0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: foreign logbook index collision';
  END IF;

  SELECT COUNT(*) INTO existing_foreign_keys
    FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name IN ('fk_tbb_message', 'fk_tbb_correction');
  SELECT COUNT(*) INTO canonical_foreign_keys
    FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'nv_tbb'
     AND ((constraint_name = 'fk_tbb_message'
           AND referenced_table_name = 'nv_nachrichten')
       OR (constraint_name = 'fk_tbb_correction'
           AND referenced_table_name = 'nv_tbb'))
     AND update_rule = 'RESTRICT'
     AND delete_rule = 'RESTRICT';
  IF existing_foreign_keys NOT IN (0, 1, 2)
     OR canonical_foreign_keys <> existing_foreign_keys THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: foreign TTB logbook constraint collision';
  END IF;

  -- Existing names may be the released predecessor, an exact migration-110
  -- trigger from an interrupted final phase, or absent after an interrupted
  -- drop/recreate phase. Anything else is a foreign collision.
  SELECT COUNT(*) INTO existing_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_einsaetze_bu_evidence', 'estab_einsaetze_bu_logbook_retention',
       'estab_einsaetze_ai_logbook_heads',
       'estab_etb_bi_einsatz', 'estab_etb_bu_einsatz',
       'estab_etb_bd_einsatz', 'estab_tbb_bi_einsatz',
       'estab_tbb_bu_einsatz', 'estab_tbb_bd_einsatz',
       'estab_dv94_hat_insert'
     );
  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'estab_einsaetze_bu_evidence'
         AND event_object_table = 'nv_einsaetze'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE '%Formal incident close is irreversible%'
         AND action_statement LIKE '%Formal incident close evidence is immutable%'
         AND action_statement LIKE '%Active incident must be deactivated before close%')
       OR (trigger_name = 'estab_einsaetze_bu_logbook_retention'
         AND event_object_table = 'nv_einsaetze'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE '%Closed incident requires ten-year retention%')
       OR (trigger_name = 'estab_einsaetze_ai_logbook_heads'
         AND event_object_table = 'nv_einsaetze'
         AND action_timing = 'AFTER'
         AND event_manipulation = 'INSERT'
         AND action_statement LIKE '%nv_logbuch_koepfe%'
         AND action_statement LIKE '%ETB%'
         AND action_statement LIKE '%TTB%')
       OR (trigger_name = 'estab_etb_bi_einsatz'
         AND event_object_table = 'nv_etb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND ((action_statement LIKE '%ETB message link targets another incident%'
               AND action_statement NOT LIKE '%ETB entry type is not permitted%')
           OR (action_statement LIKE '%ETB entry type is not permitted%'
               AND action_statement LIKE '%nv_logbuch_koepfe%')))
       OR (trigger_name = 'estab_etb_bu_einsatz'
         AND event_object_table = 'nv_etb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE '%ETB entries are append-only; write a correction%')
       OR (trigger_name = 'estab_etb_bd_einsatz'
         AND event_object_table = 'nv_etb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'DELETE'
         AND action_statement LIKE '%ETB entries are protected by retention policy%')
       OR (trigger_name = 'estab_tbb_bi_einsatz'
         AND event_object_table = 'nv_tbb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND (action_statement LIKE '%estab_incident_for_insert%'
           OR (action_statement LIKE '%TTB entry type is not permitted%'
               AND action_statement LIKE '%nv_logbuch_koepfe%')))
       OR (trigger_name = 'estab_tbb_bu_einsatz'
         AND event_object_table = 'nv_tbb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND (action_statement LIKE '%estab_incident_for_update%'
           OR action_statement LIKE '%TTB entries are append-only; write a correction%'))
       OR (trigger_name = 'estab_tbb_bd_einsatz'
         AND event_object_table = 'nv_tbb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'DELETE'
         AND (action_statement LIKE '%estab_incident_for_delete%'
           OR action_statement LIKE '%TTB entries are protected by retention policy%'))
       OR (trigger_name = 'estab_dv94_hat_insert'
         AND event_object_table = 'nv_dienstbesetzungen'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND (action_statement LIKE
                '%Duty assignment insert requires a planned active-incident shift%'
           OR (action_statement LIKE
                '%Duty assignment insert requires a planned or active-incident shift%'
               AND action_statement LIKE
                '%Active duty shift function was already assigned%'
               AND action_statement LIKE '%A/W%')))
     );
  IF canonical_triggers <> existing_triggers THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: foreign trigger collision';
  END IF;

  IF EXISTS (
    SELECT 1 FROM `nv_etb`
     WHERE `estab_attachment_id` IS NOT NULL
     GROUP BY `estab_attachment_id`
    HAVING COUNT(*) > 1
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: duplicate ETB attachment link';
  END IF;

  IF EXISTS (
    SELECT 1 FROM `nv_einsaetze`
     WHERE `estab_status` = 'closed' AND `estab_closed_at` IS NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: closed incident has no close time';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_110_preflight();
DROP PROCEDURE estab_migrate_110_preflight;

CREATE TABLE IF NOT EXISTS `nv_logbuch_koepfe` (
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `buchart` ENUM('ETB','TTB') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `next_lfd` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`einsatz_id`, `buchart`),
  CONSTRAINT `fk_logbuch_koepfe_einsatz`
    FOREIGN KEY (`einsatz_id`)
    REFERENCES `nv_einsaetze` (`einsatz_id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:110:logbook-heads:v1';

DROP PROCEDURE IF EXISTS estab_migrate_110_add_etb_number;
DELIMITER //
CREATE PROCEDURE estab_migrate_110_add_etb_number()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_etb'
       AND column_name = 'estab_book_lfd'
  ) THEN
    ALTER TABLE `nv_etb`
      ADD COLUMN `estab_book_lfd` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'estab:migration:110:etb-book-number:v1'
        AFTER `einsatz_id`;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_110_add_etb_number();
DROP PROCEDURE estab_migrate_110_add_etb_number;

DROP PROCEDURE IF EXISTS estab_migrate_110_add_tbb_columns;
DELIMITER //
CREATE PROCEDURE estab_migrate_110_add_tbb_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_tbb'
       AND column_name = 'estab_book_lfd'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD COLUMN `estab_book_lfd` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-book-number:v1'
        AFTER `einsatz_id`,
      ADD COLUMN `estab_event_time` DATETIME(6) NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-event-time:v1'
        AFTER `tbb_time`,
      ADD COLUMN `estab_recorded_at` DATETIME(6) NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-recorded-at:v1'
        AFTER `estab_event_time`,
      ADD COLUMN `estab_entry_type` VARCHAR(32)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-entry-type:v1'
        AFTER `estab_recorded_at`,
      ADD COLUMN `estab_message_id` BIGINT NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-message:v1'
        AFTER `estab_entry_type`,
      ADD COLUMN `estab_personnel_duty` TEXT NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-personnel-duty:v1'
        AFTER `estab_message_id`,
      ADD COLUMN `estab_channel` TEXT NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-channel:v1'
        AFTER `estab_personnel_duty`,
      ADD COLUMN `estab_message_route` TEXT NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-message-route:v1'
        AFTER `estab_channel`,
      ADD COLUMN `estab_operations` TEXT NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-operations:v1'
        AFTER `estab_message_route`,
      ADD COLUMN `estab_receipt` TEXT NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-receipt:v1'
        AFTER `estab_operations`,
      ADD COLUMN `estab_correction_of` INT NULL DEFAULT NULL
        COMMENT 'estab:migration:110:tbb-correction:v1'
        AFTER `estab_receipt`;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_110_add_tbb_columns();
DROP PROCEDURE estab_migrate_110_add_tbb_columns;

-- Every optional object now exists, so row-level resumability can be checked
-- without making the preflight procedure reference a not-yet-created table or
-- column. A backfill statement is atomic: each book is therefore either wholly
-- unnumbered or wholly numbered, never a silently adopted mixture.
DROP PROCEDURE IF EXISTS estab_migrate_110_validate_prefix;
DELIMITER //
CREATE PROCEDURE estab_migrate_110_validate_prefix()
BEGIN
  DECLARE invalid_rows BIGINT DEFAULT 0;
  DECLARE total_rows BIGINT DEFAULT 0;
  DECLARE null_rows BIGINT DEFAULT 0;

  SELECT COUNT(*), COALESCE(SUM(`estab_book_lfd` IS NULL), 0)
    INTO total_rows, null_rows
    FROM `nv_etb`;
  IF null_rows NOT IN (0, total_rows)
     OR EXISTS (
       SELECT 1 FROM `nv_etb`
        WHERE `estab_book_lfd` IS NOT NULL AND `estab_book_lfd` = 0
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: invalid or partial ETB number backfill';
  END IF;

  SELECT COUNT(*), COALESCE(SUM(`estab_book_lfd` IS NULL), 0)
    INTO total_rows, null_rows
    FROM `nv_tbb`;
  IF null_rows NOT IN (0, total_rows)
     OR EXISTS (
       SELECT 1 FROM `nv_tbb`
        WHERE (`estab_book_lfd` IS NOT NULL AND `estab_book_lfd` = 0)
           OR (`estab_entry_type` IS NOT NULL AND `estab_entry_type` NOT IN (
             'betrieb_personal', 'kanal', 'nachricht', 'betriebsereignis',
             'quittung', 'korrektur', 'legacy_import'
           ))
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: invalid or partial TTB backfill';
  END IF;

  SELECT COUNT(*) INTO invalid_rows
    FROM `nv_logbuch_koepfe` AS head_row
    LEFT JOIN (
      SELECT incident_row.`einsatz_id`, 'ETB' AS `buchart`,
             COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1 AS `next_lfd`
        FROM `nv_einsaetze` AS incident_row
        LEFT JOIN `nv_etb` AS entry_row
          ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
       GROUP BY incident_row.`einsatz_id`
      UNION ALL
      SELECT incident_row.`einsatz_id`, 'TTB' AS `buchart`,
             COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1 AS `next_lfd`
        FROM `nv_einsaetze` AS incident_row
        LEFT JOIN `nv_tbb` AS entry_row
          ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
       GROUP BY incident_row.`einsatz_id`
    ) AS expected
      ON expected.`einsatz_id` = head_row.`einsatz_id`
     AND BINARY expected.`buchart` = BINARY head_row.`buchart`
   WHERE expected.`einsatz_id` IS NULL
      OR expected.`next_lfd` <> head_row.`next_lfd`;
  IF invalid_rows <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration blocked: non-canonical logbook head';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_110_validate_prefix();
DROP PROCEDURE estab_migrate_110_validate_prefix;

-- Migration 80 already made ETB append-only; migration 50 still permits TTB
-- updates while an incident is active.  Remove only those guards required for
-- the deterministic one-time backfill.  Canonical replacements are installed
-- below before the migration can be acknowledged.
DROP TRIGGER IF EXISTS `estab_etb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bu_einsatz`;

UPDATE `nv_tbb`
   SET `estab_event_time` = `tbb_time`
 WHERE `estab_event_time` IS NULL;
UPDATE `nv_tbb`
   SET `estab_recorded_at` = `tbb_time`
 WHERE `estab_recorded_at` IS NULL;
UPDATE `nv_tbb`
   SET `estab_entry_type` = 'legacy_import'
 WHERE `estab_entry_type` IS NULL;
UPDATE `nv_tbb`
   SET `estab_operations` = CONCAT_WS(
         CHAR(10),
         CASE
           WHEN TRIM(`tbb_aktion`) <> ''
             THEN CONCAT('Betriebsvorgang: ', `tbb_aktion`)
           ELSE NULL
         END,
         CASE
           WHEN TRIM(`tbb_bemerk`) <> ''
             THEN CONCAT('Bemerkung: ', `tbb_bemerk`)
           ELSE NULL
         END
       )
 WHERE `estab_operations` IS NULL;

UPDATE `nv_etb` AS target
JOIN (
  SELECT `etb_lfd-nr` AS global_id,
         ROW_NUMBER() OVER (
           PARTITION BY `einsatz_id`
           ORDER BY `estab_recorded_at`, `etb_lfd-nr`
         ) AS incident_lfd
    FROM `nv_etb`
) AS ranked ON ranked.global_id = target.`etb_lfd-nr`
   SET target.`estab_book_lfd` = ranked.incident_lfd
 WHERE target.`estab_book_lfd` IS NULL;

UPDATE `nv_tbb` AS target
JOIN (
  SELECT `tbb_lfd-nr` AS global_id,
         ROW_NUMBER() OVER (
           PARTITION BY `einsatz_id`
           ORDER BY `estab_recorded_at`, `tbb_lfd-nr`
         ) AS incident_lfd
    FROM `nv_tbb`
) AS ranked ON ranked.global_id = target.`tbb_lfd-nr`
   SET target.`estab_book_lfd` = ranked.incident_lfd
 WHERE target.`estab_book_lfd` IS NULL;

ALTER TABLE `nv_etb`
  MODIFY COLUMN `estab_book_lfd` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:110:etb-book-number:v1';
ALTER TABLE `nv_tbb`
  MODIFY COLUMN `estab_book_lfd` INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'estab:migration:110:tbb-book-number:v1',
  MODIFY COLUMN `estab_event_time` DATETIME(6) NOT NULL
    COMMENT 'estab:migration:110:tbb-event-time:v1',
  MODIFY COLUMN `estab_recorded_at` DATETIME(6) NOT NULL
    DEFAULT CURRENT_TIMESTAMP(6)
    COMMENT 'estab:migration:110:tbb-recorded-at:v1',
  MODIFY COLUMN `estab_entry_type` VARCHAR(32)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
    COMMENT 'estab:migration:110:tbb-entry-type:v1';

INSERT INTO `nv_logbuch_koepfe` (`einsatz_id`, `buchart`, `next_lfd`)
SELECT incident_row.`einsatz_id`, 'ETB',
       COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1
  FROM `nv_einsaetze` AS incident_row
  LEFT JOIN `nv_etb` AS entry_row
    ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
 GROUP BY incident_row.`einsatz_id`
ON DUPLICATE KEY UPDATE `next_lfd` = VALUES(`next_lfd`);
INSERT INTO `nv_logbuch_koepfe` (`einsatz_id`, `buchart`, `next_lfd`)
SELECT incident_row.`einsatz_id`, 'TTB',
       COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1
  FROM `nv_einsaetze` AS incident_row
  LEFT JOIN `nv_tbb` AS entry_row
    ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
 GROUP BY incident_row.`einsatz_id`
ON DUPLICATE KEY UPDATE `next_lfd` = VALUES(`next_lfd`);

DROP PROCEDURE IF EXISTS estab_migrate_110_add_indexes;
DELIMITER //
CREATE PROCEDURE estab_migrate_110_add_indexes()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
       AND index_name = 'uq_etb_einsatz_book_lfd'
  ) THEN
    ALTER TABLE `nv_etb`
      ADD UNIQUE INDEX `uq_etb_einsatz_book_lfd`
        (`einsatz_id`, `estab_book_lfd`);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
       AND index_name = 'uq_etb_attachment_id'
  ) THEN
    ALTER TABLE `nv_etb`
      ADD UNIQUE INDEX `uq_etb_attachment_id` (`estab_attachment_id`);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
       AND index_name = 'uq_tbb_einsatz_book_lfd'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD UNIQUE INDEX `uq_tbb_einsatz_book_lfd`
        (`einsatz_id`, `estab_book_lfd`);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
       AND index_name = 'idx_tbb_einsatz_event_time'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD INDEX `idx_tbb_einsatz_event_time`
        (`einsatz_id`, `estab_event_time`, `tbb_lfd-nr`);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
       AND index_name = 'idx_tbb_message'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD UNIQUE INDEX `idx_tbb_message` (`einsatz_id`, `estab_message_id`);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
       AND index_name = 'idx_tbb_correction'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD INDEX `idx_tbb_correction` (`estab_correction_of`);
  END IF;
END//
DELIMITER ;
CALL estab_migrate_110_add_indexes();
DROP PROCEDURE estab_migrate_110_add_indexes;

DROP PROCEDURE IF EXISTS estab_migrate_110_add_foreign_key;
DELIMITER //
CREATE PROCEDURE estab_migrate_110_add_foreign_key()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name = 'fk_tbb_message'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD CONSTRAINT `fk_tbb_message`
        FOREIGN KEY (`estab_message_id`)
        REFERENCES `nv_nachrichten` (`00_lfd`)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name = 'fk_tbb_correction'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD CONSTRAINT `fk_tbb_correction`
        FOREIGN KEY (`estab_correction_of`)
        REFERENCES `nv_tbb` (`tbb_lfd-nr`)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_110_add_foreign_key();
DROP PROCEDURE estab_migrate_110_add_foreign_key;

-- Closed incident evidence is immutable during normal operation. Temporarily
-- remove only that released trigger, extend the minimum retention without ever
-- shortening a longer deadline, then restore every released rule verbatim.
DROP TRIGGER IF EXISTS `estab_einsaetze_bu_evidence`;
DROP TRIGGER IF EXISTS `estab_einsaetze_bu_logbook_retention`;
DROP TRIGGER IF EXISTS `estab_einsaetze_ai_logbook_heads`;
UPDATE `nv_einsaetze`
   SET `estab_retain_until` = DATE_ADD(`estab_closed_at`, INTERVAL 10 YEAR)
 WHERE `estab_status` = 'closed'
   AND (
     `estab_retain_until` IS NULL
     OR `estab_retain_until`
          < DATE_ADD(`estab_closed_at`, INTERVAL 10 YEAR)
   );

DROP TRIGGER IF EXISTS `estab_etb_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_etb_bd_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bu_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bd_einsatz`;
DELIMITER //
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

CREATE TRIGGER `estab_einsaetze_bu_logbook_retention`
BEFORE UPDATE ON `nv_einsaetze` FOR EACH ROW
BEGIN
  IF NEW.`estab_status` = 'closed'
     AND (
       NEW.`estab_closed_at` IS NULL
       OR NEW.`estab_retain_until` IS NULL
       OR NEW.`estab_retain_until`
            < DATE_ADD(NEW.`estab_closed_at`, INTERVAL 10 YEAR)
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Closed incident requires ten-year retention';
  END IF;
END//

CREATE TRIGGER `estab_einsaetze_ai_logbook_heads`
AFTER INSERT ON `nv_einsaetze` FOR EACH ROW
BEGIN
  INSERT INTO `nv_logbuch_koepfe` (`einsatz_id`, `buchart`, `next_lfd`)
  VALUES
    (NEW.`einsatz_id`, 'ETB', 1),
    (NEW.`einsatz_id`, 'TTB', 1);
END//

CREATE TRIGGER `estab_etb_bi_einsatz`
BEFORE INSERT ON `nv_etb` FOR EACH ROW
BEGIN
  DECLARE linked_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE linked_event_type VARCHAR(32) DEFAULT NULL;
  DECLARE linked_correction INT DEFAULT NULL;
  DECLARE assigned_lfd BIGINT UNSIGNED DEFAULT NULL;

  SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
  IF NEW.`estab_event_type` IS NULL OR NOT (
       BINARY NEW.`estab_event_type` = BINARY 'ohne'
       OR BINARY NEW.`estab_event_type` = BINARY 'A'
       OR BINARY NEW.`estab_event_type` = BINARY 'B'
       OR BINARY NEW.`estab_event_type` = BINARY 'E'
       OR BINARY NEW.`estab_event_type` = BINARY 'K'
       OR BINARY NEW.`estab_event_type` = BINARY 'W'
       OR BINARY NEW.`estab_event_type` = BINARY 'korrektur'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB entry type is not permitted';
  END IF;
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
       OR BINARY NEW.`estab_event_type` <> BINARY 'korrektur'
       OR BINARY linked_event_type = BINARY 'korrektur'
       OR linked_correction IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB correction target is invalid';
    END IF;
  ELSEIF BINARY NEW.`estab_event_type` = BINARY 'korrektur' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB correction requires an original entry';
  END IF;

  IF COALESCE(NEW.`estab_book_lfd`, 0) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB book number is allocated by the database';
  END IF;
  -- The incident INSERT trigger creates this row before the incident can be
  -- observed. Updating it takes the per-book write lock under MariaDB's
  -- default isolation semantics.
  UPDATE `nv_logbuch_koepfe`
     SET `next_lfd` = LAST_INSERT_ID(`next_lfd` + 1)
   WHERE `einsatz_id` = NEW.`einsatz_id`
     AND `buchart` = 'ETB';
  IF ROW_COUNT() <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB book head is missing';
  END IF;
  SET assigned_lfd = LAST_INSERT_ID() - 1;
  IF assigned_lfd IS NULL OR assigned_lfd < 1 OR assigned_lfd > 4294967295 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB book number range is exhausted';
  END IF;
  SET NEW.`estab_book_lfd` = assigned_lfd;
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

CREATE TRIGGER `estab_tbb_bi_einsatz`
BEFORE INSERT ON `nv_tbb` FOR EACH ROW
BEGIN
  DECLARE linked_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE linked_entry_type VARCHAR(32) DEFAULT NULL;
  DECLARE linked_correction INT DEFAULT NULL;
  DECLARE assigned_lfd BIGINT UNSIGNED DEFAULT NULL;

  SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
  IF NEW.`estab_entry_type` IS NULL OR NOT (
       BINARY NEW.`estab_entry_type` = BINARY 'betrieb_personal'
       OR BINARY NEW.`estab_entry_type` = BINARY 'kanal'
       OR BINARY NEW.`estab_entry_type` = BINARY 'nachricht'
       OR BINARY NEW.`estab_entry_type` = BINARY 'betriebsereignis'
       OR BINARY NEW.`estab_entry_type` = BINARY 'quittung'
       OR BINARY NEW.`estab_entry_type` = BINARY 'korrektur'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB entry type is not permitted';
  END IF;
  IF COALESCE(
       NULLIF(TRIM(NEW.`estab_personnel_duty`), ''),
       NULLIF(TRIM(NEW.`estab_channel`), ''),
       NULLIF(TRIM(NEW.`estab_message_route`), ''),
       NULLIF(TRIM(NEW.`estab_operations`), ''),
       NULLIF(TRIM(NEW.`estab_receipt`), '')
     ) IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB entry requires at least one content area';
  END IF;
  IF NEW.`estab_event_time` IS NULL THEN
    SET NEW.`estab_event_time` = NOW(6);
  END IF;
  SET NEW.`estab_recorded_at` = NOW(6);
  SET NEW.`tbb_time` = NEW.`estab_event_time`;

  IF NEW.`estab_message_id` IS NOT NULL THEN
    IF BINARY NEW.`estab_entry_type` <> BINARY 'nachricht' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'TTB message link requires canonical message entry';
    END IF;
    IF BINARY COALESCE(NEW.`tbb_kuerzel`, '') <> BINARY 'system'
       OR BINARY COALESCE(NEW.`tbb_benutzer`, '') <> BINARY 'eStab-System' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'TTB message link requires system-generated evidence';
    END IF;
    SELECT `einsatz_id` INTO linked_incident
      FROM `nv_nachrichten`
     WHERE `00_lfd` = NEW.`estab_message_id`;
    IF linked_incident IS NULL
       OR linked_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB message link targets another incident';
    END IF;
  END IF;

  IF NEW.`estab_correction_of` IS NOT NULL THEN
    IF NEW.`tbb_lfd-nr` IS NOT NULL
       AND NEW.`tbb_lfd-nr` <> 0
       AND NEW.`estab_correction_of` = NEW.`tbb_lfd-nr` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB correction cannot reference itself';
    END IF;
    SET linked_incident = NULL;
    SET linked_entry_type = NULL;
    SET linked_correction = NULL;
    SELECT `einsatz_id`, `estab_entry_type`, `estab_correction_of`
      INTO linked_incident, linked_entry_type, linked_correction
      FROM `nv_tbb`
     WHERE `tbb_lfd-nr` = NEW.`estab_correction_of`;
    IF linked_incident IS NULL
       OR linked_incident <> NEW.`einsatz_id`
       OR BINARY NEW.`estab_entry_type` <> BINARY 'korrektur'
       OR BINARY linked_entry_type = BINARY 'korrektur'
       OR linked_correction IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB correction target is invalid';
    END IF;
  ELSEIF BINARY NEW.`estab_entry_type` = BINARY 'korrektur' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB correction requires an original entry';
  END IF;

  IF COALESCE(NEW.`estab_book_lfd`, 0) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB book number is allocated by the database';
  END IF;
  -- See the ETB trigger above for the pre-created head-row lock.
  UPDATE `nv_logbuch_koepfe`
     SET `next_lfd` = LAST_INSERT_ID(`next_lfd` + 1)
   WHERE `einsatz_id` = NEW.`einsatz_id`
     AND `buchart` = 'TTB';
  IF ROW_COUNT() <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB book head is missing';
  END IF;
  SET assigned_lfd = LAST_INSERT_ID() - 1;
  IF assigned_lfd IS NULL OR assigned_lfd < 1 OR assigned_lfd > 4294967295 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB book number range is exhausted';
  END IF;
  SET NEW.`estab_book_lfd` = assigned_lfd;
END//

CREATE TRIGGER `estab_tbb_bu_einsatz`
BEFORE UPDATE ON `nv_tbb` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'TTB entries are append-only; write a correction';
END//

CREATE TRIGGER `estab_tbb_bd_einsatz`
BEFORE DELETE ON `nv_tbb` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'TTB entries are protected by retention policy';
END//
DELIMITER ;

-- An active shift may be extended with a function that has never been
-- assigned in that shift. A/W is explicitly multi-staffed; its existing
-- active-person unique key still prevents the same person/function duplicate.
-- Lock the shift before any consistent read so a concurrent waiter sees a
-- predecessor insert when it checks the history of every other function.
DROP TRIGGER IF EXISTS `estab_dv94_hat_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_hat_insert`
BEFORE INSERT ON `nv_dienstbesetzungen`
FOR EACH ROW
BEGIN
  DECLARE active_shift_status VARCHAR(16) DEFAULT NULL;
  DECLARE previous_assignment_id BIGINT UNSIGNED DEFAULT NULL;

  IF NEW.`status` <> 'ZUGEWIESEN'
     OR NEW.`angenommen_am` IS NOT NULL
     OR NEW.`abgeloest_am` IS NOT NULL
     OR NEW.`nachfolger_id` IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty assignment insert evidence';
  END IF;

  SELECT shift_row.`status` INTO active_shift_status
    FROM `nv_dienstschichten` AS shift_row
    JOIN `nv_einsatz_status` AS active_incident
      ON active_incident.`singleton_id` = 1
     AND active_incident.`active_einsatz_id` = shift_row.`einsatz_id`
    JOIN `nv_einsaetze` AS incident
      ON incident.`einsatz_id` = shift_row.`einsatz_id`
   WHERE shift_row.`dienstschicht_id` = NEW.`dienstschicht_id`
     AND shift_row.`status` IN ('GEPLANT','AKTIV')
     AND incident.`estab_status` = 'open'
   FOR UPDATE;
  IF active_shift_status IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Duty assignment insert requires a planned or active-incident shift';
  END IF;

  IF NOT EXISTS (
    SELECT 1
      FROM `nv_benutzer` AS assigned_account
     WHERE BINARY assigned_account.`kuerzel` =
           BINARY NEW.`benutzer_kuerzel`
       AND assigned_account.`estab_gesperrt` = 0
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty assignment insert evidence';
  END IF;

  IF BINARY active_shift_status = BINARY 'AKTIV'
     AND BINARY NEW.`funktion` <> BINARY 'A/W' THEN
    SELECT existing_assignment.`dienstbesetzung_id`
      INTO previous_assignment_id
      FROM `nv_dienstbesetzungen` AS existing_assignment
     WHERE existing_assignment.`dienstschicht_id` = NEW.`dienstschicht_id`
       AND BINARY existing_assignment.`funktion` = BINARY NEW.`funktion`
     ORDER BY existing_assignment.`dienstbesetzung_id`
     LIMIT 1;
    IF previous_assignment_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Active duty shift function was already assigned';
    END IF;
  END IF;
END//
DELIMITER ;

-- Migration 97 is released and checksum-immutable. Replace only its three
-- operational boundary functions here so their first incident lookup is a
-- current locking read. Otherwise MariaDB snapshot isolation can reject a
-- concurrent first write after another session locks the command-post name.
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
   WHERE `singleton_id` = 1
   FOR UPDATE;
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
   WHERE `singleton_id` = 1
   FOR UPDATE;
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
   WHERE `singleton_id` = 1
   FOR UPDATE;
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

DROP PROCEDURE IF EXISTS estab_migrate_110_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_110_validate()
BEGIN
  DECLARE invalid_rows BIGINT DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE canonical_indexes INTEGER DEFAULT 0;
  DECLARE canonical_foreign_keys INTEGER DEFAULT 0;
  DECLARE canonical_triggers INTEGER DEFAULT 0;
  DECLARE canonical_locking_routines INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'nv_etb' AND column_name = 'estab_book_lfd'
         AND data_type = 'int' AND column_type LIKE 'int%unsigned'
         AND is_nullable = 'NO' AND column_default = '0'
         AND column_comment = 'estab:migration:110:etb-book-number:v1')
       OR (table_name = 'nv_tbb' AND column_name = 'estab_book_lfd'
         AND data_type = 'int' AND column_type LIKE 'int%unsigned'
         AND is_nullable = 'NO' AND column_default = '0'
         AND column_comment = 'estab:migration:110:tbb-book-number:v1')
       OR (table_name = 'nv_tbb'
         AND column_name IN ('estab_event_time', 'estab_recorded_at')
         AND data_type = 'datetime' AND datetime_precision = 6
         AND is_nullable = 'NO')
       OR (table_name = 'nv_tbb' AND column_name = 'estab_entry_type'
         AND data_type = 'varchar' AND character_maximum_length = 32
         AND is_nullable = 'NO')
       OR (table_name = 'nv_tbb' AND column_name = 'estab_message_id'
         AND data_type = 'bigint' AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:110:tbb-message:v1')
       OR (table_name = 'nv_tbb' AND column_name IN (
           'estab_personnel_duty', 'estab_channel', 'estab_message_route',
           'estab_operations', 'estab_receipt'
         ) AND data_type = 'text' AND is_nullable = 'YES')
       OR (table_name = 'nv_tbb' AND column_name = 'estab_correction_of'
         AND data_type = 'int' AND is_nullable = 'YES')
     );
  IF canonical_columns <> 12 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration failed: canonical columns are incomplete';
  END IF;

  SELECT COUNT(*) INTO canonical_indexes
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND sub_part IS NULL
     AND (
       (table_name = 'nv_etb' AND index_name = 'uq_etb_einsatz_book_lfd'
         AND non_unique = 0
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_book_lfd')))
       OR (table_name = 'nv_etb' AND index_name = 'uq_etb_attachment_id'
         AND non_unique = 0 AND seq_in_index = 1
         AND column_name = 'estab_attachment_id')
       OR (table_name = 'nv_tbb' AND index_name = 'uq_tbb_einsatz_book_lfd'
         AND non_unique = 0
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_book_lfd')))
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_einsatz_event_time'
         AND non_unique = 1
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_event_time')
           OR (seq_in_index = 3 AND column_name = 'tbb_lfd-nr')))
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_correction'
         AND non_unique = 1 AND seq_in_index = 1
         AND column_name = 'estab_correction_of')
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_message'
         AND non_unique = 0
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_message_id')))
     );
  IF canonical_indexes <> 11 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration failed: canonical indexes are incomplete';
  END IF;

  SELECT COUNT(*) INTO canonical_foreign_keys
    FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND ((table_name = 'nv_logbuch_koepfe'
           AND constraint_name = 'fk_logbuch_koepfe_einsatz'
           AND referenced_table_name = 'nv_einsaetze')
       OR (table_name = 'nv_tbb'
           AND constraint_name = 'fk_tbb_message'
           AND referenced_table_name = 'nv_nachrichten')
       OR (table_name = 'nv_tbb'
           AND constraint_name = 'fk_tbb_correction'
           AND referenced_table_name = 'nv_tbb'))
     AND update_rule = 'RESTRICT'
     AND delete_rule = 'RESTRICT';
  IF canonical_foreign_keys <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration failed: canonical constraints are incomplete';
  END IF;

  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_orientation = 'ROW'
     AND (
       (trigger_name = 'estab_einsaetze_bu_evidence'
         AND event_object_table = 'nv_einsaetze'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE '%Formal incident close is irreversible%'
         AND action_statement LIKE '%Formal incident close evidence is immutable%'
         AND action_statement LIKE '%Active incident must be deactivated before close%')
       OR (trigger_name = 'estab_einsaetze_bu_logbook_retention'
         AND event_object_table = 'nv_einsaetze'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE '%Closed incident requires ten-year retention%')
       OR (trigger_name = 'estab_einsaetze_ai_logbook_heads'
         AND event_object_table = 'nv_einsaetze'
         AND action_timing = 'AFTER'
         AND event_manipulation = 'INSERT'
         AND action_statement LIKE '%nv_logbuch_koepfe%'
         AND action_statement LIKE '%ETB%'
         AND action_statement LIKE '%TTB%')
       OR (trigger_name = 'estab_etb_bi_einsatz'
         AND event_object_table = 'nv_etb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND action_statement LIKE '%ETB entry type is not permitted%'
         AND action_statement LIKE '%ETB book head is missing%')
       OR (trigger_name = 'estab_etb_bu_einsatz'
         AND event_object_table = 'nv_etb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE '%ETB entries are append-only; write a correction%')
       OR (trigger_name = 'estab_etb_bd_einsatz'
         AND event_object_table = 'nv_etb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'DELETE'
         AND action_statement LIKE '%ETB entries are protected by retention policy%')
       OR (trigger_name = 'estab_tbb_bi_einsatz'
         AND event_object_table = 'nv_tbb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND action_statement LIKE '%TTB entry type is not permitted%'
         AND action_statement LIKE '%TTB message link requires canonical message entry%'
         AND action_statement LIKE '%TTB message link requires system-generated evidence%'
         AND action_statement LIKE '%TTB book head is missing%')
       OR (trigger_name = 'estab_tbb_bu_einsatz'
         AND event_object_table = 'nv_tbb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND action_statement LIKE '%TTB entries are append-only; write a correction%')
       OR (trigger_name = 'estab_tbb_bd_einsatz'
         AND event_object_table = 'nv_tbb'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'DELETE'
         AND action_statement LIKE '%TTB entries are protected by retention policy%')
       OR (trigger_name = 'estab_dv94_hat_insert'
         AND event_object_table = 'nv_dienstbesetzungen'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND action_statement LIKE
              '%Duty assignment insert requires a planned or active-incident shift%'
         AND action_statement LIKE
              '%Active duty shift function was already assigned%'
         AND action_statement LIKE '%A/W%'
         AND action_statement LIKE '%FOR UPDATE%')
     );
  IF canonical_triggers <> 10 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration failed: canonical triggers are incomplete';
  END IF;

  SELECT COUNT(*) INTO canonical_locking_routines
    FROM information_schema.routines
   WHERE routine_schema = DATABASE()
     AND routine_type = 'FUNCTION'
     AND routine_name IN (
       'estab_incident_for_insert', 'estab_incident_for_update',
       'estab_incident_for_delete'
     )
     AND sql_data_access = 'MODIFIES SQL DATA'
     AND security_type = 'DEFINER'
     AND routine_definition LIKE '%FOR UPDATE%';
  IF canonical_locking_routines <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration failed: operational locking reads are incomplete';
  END IF;

  SELECT COUNT(*) INTO invalid_rows
    FROM (
      SELECT incident_row.`einsatz_id`, 'ETB' AS `buchart`,
             COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1 AS `next_lfd`
        FROM `nv_einsaetze` AS incident_row
        LEFT JOIN `nv_etb` AS entry_row
          ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
       GROUP BY incident_row.`einsatz_id`
      UNION ALL
      SELECT incident_row.`einsatz_id`, 'TTB' AS `buchart`,
             COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1 AS `next_lfd`
        FROM `nv_einsaetze` AS incident_row
        LEFT JOIN `nv_tbb` AS entry_row
          ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
       GROUP BY incident_row.`einsatz_id`
    ) AS expected
    LEFT JOIN `nv_logbuch_koepfe` AS head_row
      ON head_row.`einsatz_id` = expected.`einsatz_id`
     AND BINARY head_row.`buchart` = BINARY expected.`buchart`
   WHERE head_row.`einsatz_id` IS NULL
      OR head_row.`next_lfd` <> expected.`next_lfd`;
  SELECT invalid_rows + ABS(
           (SELECT COUNT(*) FROM `nv_logbuch_koepfe`)
           - (2 * (SELECT COUNT(*) FROM `nv_einsaetze`))
         ) INTO invalid_rows;
  IF invalid_rows <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration failed: logbook heads do not match entries';
  END IF;

  IF EXISTS (
    SELECT 1 FROM `nv_etb`
     WHERE `einsatz_id` IS NULL OR `estab_book_lfd` < 1
       OR `estab_event_type` NOT IN (
         'ohne', 'A', 'B', 'E', 'K', 'W', 'korrektur', 'legacy_import',
         'ereignis', 'entscheidung', 'lagebesprechung', 'auftrag', 'information'
       )
  ) OR EXISTS (
    SELECT 1 FROM `nv_tbb`
     WHERE `einsatz_id` IS NULL OR `estab_book_lfd` < 1
       OR `estab_event_time` IS NULL OR `estab_recorded_at` IS NULL
       OR BINARY `estab_entry_type` NOT IN (
         BINARY 'betrieb_personal', BINARY 'kanal', BINARY 'nachricht',
         BINARY 'betriebsereignis', BINARY 'quittung', BINARY 'korrektur',
         BINARY 'legacy_import'
       )
       OR (BINARY `estab_entry_type` <> BINARY 'legacy_import' AND COALESCE(
         NULLIF(TRIM(`estab_personnel_duty`), ''),
         NULLIF(TRIM(`estab_channel`), ''),
         NULLIF(TRIM(`estab_message_route`), ''),
         NULLIF(TRIM(`estab_operations`), ''),
         NULLIF(TRIM(`estab_receipt`), '')
       ) IS NULL)
       OR (`estab_message_id` IS NOT NULL AND (
         BINARY `estab_entry_type` <> BINARY 'nachricht'
         OR BINARY COALESCE(`tbb_kuerzel`, '') <> BINARY 'system'
         OR BINARY COALESCE(`tbb_benutzer`, '') <> BINARY 'eStab-System'
       ))
  ) OR EXISTS (
    SELECT 1 FROM `nv_tbb` AS entry_row
    LEFT JOIN `nv_nachrichten` AS message_row
      ON message_row.`00_lfd` = entry_row.`estab_message_id`
   WHERE entry_row.`estab_message_id` IS NOT NULL
     AND (message_row.`00_lfd` IS NULL
       OR message_row.`einsatz_id` <> entry_row.`einsatz_id`)
  ) OR EXISTS (
    SELECT 1 FROM `nv_tbb` AS correction
    LEFT JOIN `nv_tbb` AS original
      ON original.`tbb_lfd-nr` = correction.`estab_correction_of`
   WHERE (correction.`estab_entry_type` = 'korrektur'
          AND (original.`tbb_lfd-nr` IS NULL
            OR original.`einsatz_id` <> correction.`einsatz_id`
            OR original.`estab_entry_type` = 'korrektur'
            OR original.`estab_correction_of` IS NOT NULL))
      OR (correction.`estab_entry_type` <> 'korrektur'
          AND correction.`estab_correction_of` IS NOT NULL)
  ) OR EXISTS (
    SELECT 1 FROM `nv_einsaetze`
     WHERE `estab_status` = 'closed'
       AND (`estab_closed_at` IS NULL OR `estab_retain_until` IS NULL
         OR `estab_retain_until`
              < DATE_ADD(`estab_closed_at`, INTERVAL 10 YEAR))
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook rules migration failed: canonical evidence data is incomplete';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_110_validate();
DROP PROCEDURE estab_migrate_110_validate;
