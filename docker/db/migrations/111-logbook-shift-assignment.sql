-- Persist the duty shift behind every new ETB/TBB row and the optional ETB
-- assignee used exclusively as a search aid.
-- Historic rows deliberately stay NULL: inventing a shift for evidence
-- created before duty rosters existed, or rewriting an older unlinked
-- `nachricht` classification, would be less reliable than marking unavailable
-- provenance explicitly and retaining the original evidence unchanged.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_111_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_111_preflight()
BEGIN
  DECLARE existing_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE existing_indexes INTEGER DEFAULT 0;
  DECLARE canonical_indexes INTEGER DEFAULT 0;
  DECLARE existing_foreign_keys INTEGER DEFAULT 0;
  DECLARE canonical_foreign_keys INTEGER DEFAULT 0;
  DECLARE existing_triggers INTEGER DEFAULT 0;
  DECLARE canonical_triggers INTEGER DEFAULT 0;

  IF (SELECT COUNT(*) FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
         AND engine = 'InnoDB'
         AND table_name IN (
           'nv_etb', 'nv_tbb', 'nv_dienstschichten',
           'nv_dienstbesetzungen', 'nv_benutzer',
           'nv_dienstuebergaben', 'nv_dienstuebergabe_anfragen'
         )) <> 7 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook shift migration blocked: required table is missing';
  END IF;

  SELECT COUNT(*) INTO existing_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND ((table_name = 'nv_etb' AND column_name IN (
            'estab_shift_id', 'estab_writer_assignment_id',
            'estab_assignee_assignment_id', 'estab_assignment'
          ))
       OR (table_name = 'nv_tbb' AND column_name IN (
            'estab_shift_id', 'estab_writer_assignment_id'
          )));
  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'nv_etb' AND column_name = 'estab_shift_id'
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:111:etb-shift:v1')
       OR (table_name = 'nv_etb'
         AND column_name = 'estab_writer_assignment_id'
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:111:etb-writer:v1')
       OR (table_name = 'nv_etb'
         AND column_name = 'estab_assignee_assignment_id'
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:111:etb-assignee:v1')
       OR (table_name = 'nv_etb' AND column_name = 'estab_assignment'
         AND data_type = 'varchar' AND character_maximum_length = 255
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:111:etb-assignment-snapshot:v1')
       OR (table_name = 'nv_tbb' AND column_name = 'estab_shift_id'
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:111:tbb-shift:v1')
       OR (table_name = 'nv_tbb'
         AND column_name = 'estab_writer_assignment_id'
         AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
         AND is_nullable = 'YES'
         AND column_comment = 'estab:migration:111:tbb-writer:v1')
     );
  IF existing_columns <> canonical_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook shift migration blocked: foreign column collision';
  END IF;

  SELECT COUNT(*) INTO existing_indexes
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND index_name IN (
       'idx_etb_einsatz_shift_book', 'idx_etb_writer_assignment',
       'idx_etb_assignee_assignment', 'idx_tbb_einsatz_shift_book',
       'idx_tbb_writer_assignment'
     );
  SELECT COUNT(*) INTO canonical_indexes
    FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND sub_part IS NULL
     AND (
       (table_name = 'nv_etb' AND index_name = 'idx_etb_einsatz_shift_book'
         AND non_unique = 1 AND index_type = 'BTREE'
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
           OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
       OR (table_name = 'nv_etb' AND index_name = 'idx_etb_writer_assignment'
         AND non_unique = 1 AND seq_in_index = 1
         AND column_name = 'estab_writer_assignment_id')
       OR (table_name = 'nv_etb' AND index_name = 'idx_etb_assignee_assignment'
         AND non_unique = 1 AND seq_in_index = 1
         AND column_name = 'estab_assignee_assignment_id')
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_einsatz_shift_book'
         AND non_unique = 1 AND index_type = 'BTREE'
         AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
           OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
           OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
       OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_writer_assignment'
         AND non_unique = 1 AND seq_in_index = 1
         AND column_name = 'estab_writer_assignment_id')
     );
  IF existing_indexes <> canonical_indexes THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook shift migration blocked: foreign index collision';
  END IF;

  SELECT COUNT(*) INTO existing_foreign_keys
    FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name IN (
       'fk_etb_shift', 'fk_etb_writer_assignment',
       'fk_etb_assignee_assignment', 'fk_tbb_shift',
       'fk_tbb_writer_assignment'
     );
  SELECT COUNT(*) INTO canonical_foreign_keys
    FROM information_schema.referential_constraints AS relation
    JOIN information_schema.key_column_usage AS key_column
      ON key_column.constraint_schema = relation.constraint_schema
     AND key_column.table_name = relation.table_name
     AND key_column.constraint_name = relation.constraint_name
   WHERE relation.constraint_schema = DATABASE()
     AND relation.update_rule = 'RESTRICT'
     AND relation.delete_rule = 'RESTRICT'
     AND (
       (relation.table_name = 'nv_etb'
         AND relation.constraint_name = 'fk_etb_shift'
         AND relation.referenced_table_name = 'nv_dienstschichten'
         AND key_column.column_name = 'estab_shift_id'
         AND key_column.referenced_column_name = 'dienstschicht_id')
       OR (relation.table_name = 'nv_etb'
         AND relation.constraint_name = 'fk_etb_writer_assignment'
         AND relation.referenced_table_name = 'nv_dienstbesetzungen'
         AND key_column.column_name = 'estab_writer_assignment_id'
         AND key_column.referenced_column_name = 'dienstbesetzung_id')
       OR (relation.table_name = 'nv_etb'
         AND relation.constraint_name = 'fk_etb_assignee_assignment'
         AND relation.referenced_table_name = 'nv_dienstbesetzungen'
         AND key_column.column_name = 'estab_assignee_assignment_id'
         AND key_column.referenced_column_name = 'dienstbesetzung_id')
       OR (relation.table_name = 'nv_tbb'
         AND relation.constraint_name = 'fk_tbb_shift'
         AND relation.referenced_table_name = 'nv_dienstschichten'
         AND key_column.column_name = 'estab_shift_id'
         AND key_column.referenced_column_name = 'dienstschicht_id')
       OR (relation.table_name = 'nv_tbb'
         AND relation.constraint_name = 'fk_tbb_writer_assignment'
         AND relation.referenced_table_name = 'nv_dienstbesetzungen'
         AND key_column.column_name = 'estab_writer_assignment_id'
         AND key_column.referenced_column_name = 'dienstbesetzung_id')
     );
  IF existing_foreign_keys <> canonical_foreign_keys THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook shift migration blocked: foreign constraint collision';
  END IF;

  SELECT COUNT(*) INTO existing_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_etb_bi_einsatz', 'estab_tbb_bi_einsatz',
       'estab_dv94_hat_update',
       'estab_log111_handover_insert_time',
       'estab_log111_handover_confirm_time'
     );
  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_timing = 'BEFORE'
     AND ((trigger_name = 'estab_etb_bi_einsatz'
           AND event_manipulation = 'INSERT'
           AND event_object_table = 'nv_etb'
           AND action_statement LIKE '%ETB entry type is not permitted%'
           AND action_statement LIKE
                 '%ETB book number is allocated by the database%'
           AND action_statement LIKE '%nv_logbuch_koepfe%'
           AND (action_statement NOT LIKE '%requires a duty shift%'
             OR (action_statement LIKE '%ETB entry requires a duty shift%'
               AND action_statement LIKE
                     '%ETB writer does not belong to its duty shift%'
               AND action_statement LIKE
                     '%ETB writer identity or status is invalid%'
               AND action_statement LIKE
                     '%ETB assignee does not belong to its duty shift%'
               AND action_statement LIKE
                     '%ETB assignee identity or status is invalid%'
               AND action_statement LIKE
                     '%ETB reference must be a canonical local number%'
               AND action_statement LIKE
                     '%ETB correction requires canonical local reference%')))
       OR (trigger_name = 'estab_tbb_bi_einsatz'
           AND event_manipulation = 'INSERT'
           AND event_object_table = 'nv_tbb'
           AND action_statement LIKE '%TTB entry type is not permitted%'
           AND action_statement LIKE
                 '%TTB book number is allocated by the database%'
           AND action_statement LIKE '%nv_logbuch_koepfe%'
           AND (action_statement NOT LIKE '%requires a duty shift%'
             OR (action_statement LIKE '%TTB entry requires a duty shift%'
               AND action_statement LIKE
                     '%TTB writer does not belong to its duty shift%'
               AND action_statement LIKE
                     '%TTB writer identity or status is invalid%')))
       OR (trigger_name = 'estab_dv94_hat_update'
           AND event_manipulation = 'UPDATE'
           AND event_object_table = 'nv_dienstbesetzungen'
           AND action_statement LIKE
                 '%Invalid duty assignment acceptance%'
           AND action_statement LIKE
                 '%Invalid relieved duty assignment evidence%'
           AND action_statement LIKE '%estab_gesperrt%')
       OR (trigger_name = 'estab_log111_handover_insert_time'
           AND event_manipulation = 'INSERT'
           AND event_object_table = 'nv_dienstuebergaben'
           AND action_statement LIKE
                 '%Duty handover completion times are inconsistent%'
           AND action_statement LIKE
                 '%request.`initiiert_am` <= NEW.`uebergeben_am`%')
       OR (trigger_name = 'estab_log111_handover_confirm_time'
           AND event_manipulation = 'UPDATE'
           AND event_object_table = 'nv_dienstuebergabe_anfragen'
           AND action_statement LIKE
                 '%Duty handover confirmation times are inconsistent%'
           AND action_statement LIKE
                 '%OLD.`initiiert_am` <= NEW.`bestaetigt_am`%'));
  IF existing_triggers <> canonical_triggers THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Logbook shift migration blocked: foreign trigger collision';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_111_preflight();
DROP PROCEDURE estab_migrate_111_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_111_add_columns;
DELIMITER //
CREATE PROCEDURE estab_migrate_111_add_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
       AND column_name = 'estab_shift_id'
  ) THEN
    ALTER TABLE `nv_etb`
      ADD COLUMN `estab_shift_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'estab:migration:111:etb-shift:v1'
        AFTER `estab_book_lfd`;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
       AND column_name = 'estab_writer_assignment_id'
  ) THEN
    ALTER TABLE `nv_etb`
      ADD COLUMN `estab_writer_assignment_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'estab:migration:111:etb-writer:v1'
        AFTER `estab_shift_id`;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
       AND column_name = 'estab_assignee_assignment_id'
  ) THEN
    ALTER TABLE `nv_etb`
      ADD COLUMN `estab_assignee_assignment_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'estab:migration:111:etb-assignee:v1'
        AFTER `estab_writer_assignment_id`;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
       AND column_name = 'estab_assignment'
  ) THEN
    ALTER TABLE `nv_etb`
      ADD COLUMN `estab_assignment` VARCHAR(255)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
        COMMENT 'estab:migration:111:etb-assignment-snapshot:v1'
        AFTER `estab_assignee_assignment_id`;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
       AND column_name = 'estab_shift_id'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD COLUMN `estab_shift_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'estab:migration:111:tbb-shift:v1'
        AFTER `estab_book_lfd`;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
       AND column_name = 'estab_writer_assignment_id'
  ) THEN
    ALTER TABLE `nv_tbb`
      ADD COLUMN `estab_writer_assignment_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'estab:migration:111:tbb-writer:v1'
        AFTER `estab_shift_id`;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_111_add_columns();
DROP PROCEDURE estab_migrate_111_add_columns;

DROP PROCEDURE IF EXISTS estab_migrate_111_add_indexes;
DELIMITER //
CREATE PROCEDURE estab_migrate_111_add_indexes()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
        AND index_name = 'idx_etb_einsatz_shift_book') THEN
    ALTER TABLE `nv_etb` ADD INDEX `idx_etb_einsatz_shift_book`
      (`einsatz_id`, `estab_shift_id`, `estab_book_lfd`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
        AND index_name = 'idx_etb_writer_assignment') THEN
    ALTER TABLE `nv_etb` ADD INDEX `idx_etb_writer_assignment`
      (`estab_writer_assignment_id`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'nv_etb'
        AND index_name = 'idx_etb_assignee_assignment') THEN
    ALTER TABLE `nv_etb` ADD INDEX `idx_etb_assignee_assignment`
      (`estab_assignee_assignment_id`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
        AND index_name = 'idx_tbb_einsatz_shift_book') THEN
    ALTER TABLE `nv_tbb` ADD INDEX `idx_tbb_einsatz_shift_book`
      (`einsatz_id`, `estab_shift_id`, `estab_book_lfd`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 'nv_tbb'
        AND index_name = 'idx_tbb_writer_assignment') THEN
    ALTER TABLE `nv_tbb` ADD INDEX `idx_tbb_writer_assignment`
      (`estab_writer_assignment_id`);
  END IF;
END//
DELIMITER ;
CALL estab_migrate_111_add_indexes();
DROP PROCEDURE estab_migrate_111_add_indexes;

DROP PROCEDURE IF EXISTS estab_migrate_111_add_foreign_keys;
DELIMITER //
CREATE PROCEDURE estab_migrate_111_add_foreign_keys()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_etb_shift') THEN
    ALTER TABLE `nv_etb` ADD CONSTRAINT `fk_etb_shift`
      FOREIGN KEY (`estab_shift_id`) REFERENCES `nv_dienstschichten`
        (`dienstschicht_id`) ON UPDATE RESTRICT ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE()
        AND constraint_name = 'fk_etb_writer_assignment') THEN
    ALTER TABLE `nv_etb` ADD CONSTRAINT `fk_etb_writer_assignment`
      FOREIGN KEY (`estab_writer_assignment_id`) REFERENCES `nv_dienstbesetzungen`
        (`dienstbesetzung_id`) ON UPDATE RESTRICT ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE()
        AND constraint_name = 'fk_etb_assignee_assignment') THEN
    ALTER TABLE `nv_etb` ADD CONSTRAINT `fk_etb_assignee_assignment`
      FOREIGN KEY (`estab_assignee_assignment_id`) REFERENCES `nv_dienstbesetzungen`
        (`dienstbesetzung_id`) ON UPDATE RESTRICT ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_tbb_shift') THEN
    ALTER TABLE `nv_tbb` ADD CONSTRAINT `fk_tbb_shift`
      FOREIGN KEY (`estab_shift_id`) REFERENCES `nv_dienstschichten`
        (`dienstschicht_id`) ON UPDATE RESTRICT ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE()
        AND constraint_name = 'fk_tbb_writer_assignment') THEN
    ALTER TABLE `nv_tbb` ADD CONSTRAINT `fk_tbb_writer_assignment`
      FOREIGN KEY (`estab_writer_assignment_id`) REFERENCES `nv_dienstbesetzungen`
        (`dienstbesetzung_id`) ON UPDATE RESTRICT ON DELETE RESTRICT;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_111_add_foreign_keys();
DROP PROCEDURE estab_migrate_111_add_foreign_keys;

-- A planned ETB assignment may be accepted before its shift starts. Once the
-- shift is active, accepting it must never silently replace the already
-- designated ETB/S2 writer; that change belongs to the confirmed handover.
DROP TRIGGER IF EXISTS `estab_dv94_hat_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_hat_update`
BEFORE UPDATE ON `nv_dienstbesetzungen`
FOR EACH ROW
BEGIN
  IF NEW.`dienstschicht_id` <> OLD.`dienstschicht_id`
     OR BINARY NEW.`benutzer_kuerzel` <> BINARY OLD.`benutzer_kuerzel`
     OR BINARY NEW.`funktion` <> BINARY OLD.`funktion`
     OR BINARY NEW.`rolle` <> BINARY OLD.`rolle`
     OR NEW.`zugewiesen_am` <> OLD.`zugewiesen_am`
     OR BINARY NEW.`zugewiesen_von` <> BINARY OLD.`zugewiesen_von`
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_dienstschichten` AS shift_row
         JOIN `nv_einsatz_status` AS active_incident
           ON active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = shift_row.`einsatz_id`
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = shift_row.`einsatz_id`
        WHERE shift_row.`dienstschicht_id` = OLD.`dienstschicht_id`
          AND shift_row.`status` IN ('GEPLANT','AKTIV','UEBERGEBEN')
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid or inactive-incident duty assignment transition';
  END IF;
  IF NEW.`status` = OLD.`status` THEN
    IF NOT (NEW.`angenommen_am` <=> OLD.`angenommen_am`)
       OR NOT (NEW.`abgeloest_am` <=> OLD.`abgeloest_am`)
       OR NOT (NEW.`nachfolger_id` <=> OLD.`nachfolger_id`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Duty assignment evidence fields are immutable';
    END IF;
  ELSEIF OLD.`status` = 'ZUGEWIESEN'
      AND NEW.`status` = 'ANGENOMMEN' THEN
    IF BINARY OLD.`funktion` = BINARY 'ETB'
       AND EXISTS (
         SELECT 1
           FROM `nv_dienstschichten` AS active_shift
           JOIN `nv_dienstbesetzungen` AS current_writer
             ON current_writer.`dienstschicht_id` =
                active_shift.`dienstschicht_id`
          WHERE active_shift.`dienstschicht_id` = OLD.`dienstschicht_id`
            AND BINARY active_shift.`status` = BINARY 'AKTIV'
            AND current_writer.`dienstbesetzung_id` <>
                OLD.`dienstbesetzung_id`
            AND BINARY current_writer.`status` = BINARY 'ANGENOMMEN'
            AND (BINARY current_writer.`funktion` = BINARY 'ETB'
              OR BINARY current_writer.`funktion` = BINARY 'S2')
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Active shift ETB writer change requires confirmed handover';
    END IF;
    IF OLD.`angenommen_am` IS NOT NULL
       OR NEW.`angenommen_am` IS NULL
       OR NEW.`abgeloest_am` IS NOT NULL
       OR NEW.`nachfolger_id` IS NOT NULL
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_benutzer` AS accepting_account
          WHERE BINARY accepting_account.`kuerzel` =
                BINARY OLD.`benutzer_kuerzel`
            AND accepting_account.`estab_gesperrt` = 0
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid duty assignment acceptance';
    END IF;
  ELSEIF OLD.`status` = 'ZUGEWIESEN'
      AND NEW.`status` = 'ZURUECKGEZOGEN' THEN
    IF NEW.`angenommen_am` IS NOT NULL
       OR OLD.`abgeloest_am` IS NOT NULL
       OR NEW.`abgeloest_am` IS NULL
       OR NEW.`nachfolger_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid duty assignment withdrawal';
    END IF;
  ELSEIF OLD.`status` = 'ANGENOMMEN'
      AND NEW.`status` = 'ABGELOEST' THEN
    IF OLD.`angenommen_am` IS NULL
       OR NEW.`angenommen_am` <> OLD.`angenommen_am`
       OR OLD.`abgeloest_am` IS NOT NULL
       OR NEW.`abgeloest_am` IS NULL
       OR (
         NEW.`nachfolger_id` IS NOT NULL
         AND NOT EXISTS (
           SELECT 1
             FROM `nv_dienstbesetzungen` AS successor_assignment
             JOIN `nv_dienstschichten` AS successor_shift
               ON successor_shift.`dienstschicht_id` =
                  successor_assignment.`dienstschicht_id`
             JOIN `nv_benutzer` AS successor_account
               ON BINARY successor_account.`kuerzel` =
                  BINARY successor_assignment.`benutzer_kuerzel`
            WHERE successor_assignment.`dienstbesetzung_id` =
                  NEW.`nachfolger_id`
              AND BINARY successor_assignment.`funktion` =
                  BINARY OLD.`funktion`
              AND BINARY successor_assignment.`rolle` = BINARY OLD.`rolle`
              AND successor_shift.`vorgaenger_id` =
                  OLD.`dienstschicht_id`
              AND successor_shift.`status` = 'AKTIV'
              AND successor_account.`estab_gesperrt` = 0
              AND successor_assignment.`status` = 'ANGENOMMEN'
              AND successor_assignment.`angenommen_am` IS NOT NULL
         )
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid relieved duty assignment evidence';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty assignment status transition';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_etb_bi_einsatz`;
DROP TRIGGER IF EXISTS `estab_tbb_bi_einsatz`;
DELIMITER //
CREATE TRIGGER `estab_etb_bi_einsatz`
BEFORE INSERT ON `nv_etb` FOR EACH ROW
BEGIN
  DECLARE linked_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE linked_event_type VARCHAR(32) DEFAULT NULL;
  DECLARE linked_correction INT DEFAULT NULL;
  DECLARE linked_book_lfd BIGINT UNSIGNED DEFAULT NULL;
  DECLARE assigned_lfd BIGINT UNSIGNED DEFAULT NULL;
  DECLARE shift_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE writer_shift BIGINT UNSIGNED DEFAULT NULL;
  DECLARE writer_valid INTEGER DEFAULT 0;
  DECLARE assignee_shift BIGINT UNSIGNED DEFAULT NULL;
  DECLARE assignment_snapshot VARCHAR(255) DEFAULT NULL;

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

  IF NEW.`estab_shift_id` IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB entry requires a duty shift';
  END IF;
  SELECT `einsatz_id` INTO shift_incident
    FROM `nv_dienstschichten`
   WHERE `dienstschicht_id` = NEW.`estab_shift_id`;
  IF shift_incident IS NULL OR shift_incident <> NEW.`einsatz_id` THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB duty shift targets another incident';
  END IF;

  IF BINARY COALESCE(NEW.`etb_kuerzel`, '') = BINARY 'system'
     AND BINARY COALESCE(NEW.`etb_benutzer`, '') = BINARY 'eStab-System' THEN
    IF NEW.`estab_writer_assignment_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'System ETB entry cannot claim a human writer';
    END IF;
  ELSE
    IF NEW.`estab_writer_assignment_id` IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Manual ETB entry requires its duty assignment';
    END IF;
    SELECT `dienstschicht_id` INTO writer_shift
      FROM `nv_dienstbesetzungen`
     WHERE `dienstbesetzung_id` = NEW.`estab_writer_assignment_id`;
    IF writer_shift IS NULL OR writer_shift <> NEW.`estab_shift_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB writer does not belong to its duty shift';
    END IF;
    SELECT COUNT(*) INTO writer_valid
      FROM `nv_dienstbesetzungen` AS assignment
      JOIN `nv_dienstschichten` AS duty_shift
        ON duty_shift.`dienstschicht_id` = assignment.`dienstschicht_id`
      JOIN `nv_benutzer` AS account
        ON BINARY account.`kuerzel` = BINARY assignment.`benutzer_kuerzel`
     WHERE assignment.`dienstbesetzung_id` =
           NEW.`estab_writer_assignment_id`
       AND assignment.`dienstschicht_id` = NEW.`estab_shift_id`
       AND BINARY assignment.`status` = BINARY 'ANGENOMMEN'
       AND BINARY duty_shift.`status` = BINARY 'AKTIV'
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0
       AND BINARY assignment.`benutzer_kuerzel` = BINARY NEW.`etb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`etb_benutzer`
       AND BINARY assignment.`funktion` = BINARY NEW.`etb_funktion`
       AND (BINARY assignment.`funktion` = BINARY 'ETB'
         OR BINARY assignment.`funktion` = BINARY 'S2');
    IF writer_valid <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB writer identity or status is invalid';
    END IF;
  END IF;

  IF NEW.`estab_assignee_assignment_id` IS NULL THEN
    IF NULLIF(TRIM(COALESCE(NEW.`estab_assignment`, '')), '') IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB assignee snapshot requires an assignment';
    END IF;
    SET NEW.`estab_assignment` = NULL;
  ELSE
    SELECT assignment.`dienstschicht_id` INTO assignee_shift
      FROM `nv_dienstbesetzungen` AS assignment
     WHERE assignment.`dienstbesetzung_id` =
           NEW.`estab_assignee_assignment_id`;
    IF assignee_shift IS NULL OR assignee_shift <> NEW.`estab_shift_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB assignee does not belong to its duty shift';
    END IF;
    SET assignee_shift = NULL;
    SELECT assignment.`dienstschicht_id`, CONCAT(
             assignment.`funktion`, ' (', assignment.`rolle`, '): ',
             account.`benutzer`, ' [', assignment.`benutzer_kuerzel`, ']'
           )
      INTO assignee_shift, assignment_snapshot
      FROM `nv_dienstbesetzungen` AS assignment
      JOIN `nv_dienstschichten` AS duty_shift
        ON duty_shift.`dienstschicht_id` = assignment.`dienstschicht_id`
      JOIN `nv_benutzer` AS account
        ON BINARY account.`kuerzel` = BINARY assignment.`benutzer_kuerzel`
     WHERE assignment.`dienstbesetzung_id` =
           NEW.`estab_assignee_assignment_id`
       AND BINARY assignment.`status` = BINARY 'ANGENOMMEN'
       AND BINARY duty_shift.`status` = BINARY 'AKTIV'
       AND account.`estab_gesperrt` = 0;
    IF assignee_shift IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB assignee identity or status is invalid';
    END IF;
    SET NEW.`estab_assignment` = assignment_snapshot;
  END IF;

  IF NEW.`estab_message_id` IS NOT NULL THEN
    SET linked_incident = NULL;
    SELECT `einsatz_id` INTO linked_incident FROM `nv_nachrichten`
     WHERE `00_lfd` = NEW.`estab_message_id`;
    IF linked_incident IS NULL OR linked_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB message link targets another incident';
    END IF;
  END IF;
  IF NEW.`estab_attachment_id` IS NOT NULL THEN
    SET linked_incident = NULL;
    SELECT `einsatz_id` INTO linked_incident FROM `nv_anhang`
     WHERE `lfd-nr` = NEW.`estab_attachment_id`;
    IF linked_incident IS NULL OR linked_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB attachment link targets another incident';
    END IF;
  END IF;
  IF NEW.`estab_reference` IS NOT NULL THEN
    SET NEW.`estab_reference` = TRIM(NEW.`estab_reference`);
    IF NEW.`estab_reference` NOT REGEXP '^[1-9][0-9]{0,9}$'
       OR CAST(NEW.`estab_reference` AS UNSIGNED) > 4294967295
       OR BINARY NEW.`estab_reference` <>
          BINARY CAST(CAST(NEW.`estab_reference` AS UNSIGNED) AS CHAR) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB reference must be a canonical local number';
    END IF;
    SET linked_incident = NULL;
    SET linked_book_lfd = NULL;
    SELECT `einsatz_id`, `estab_book_lfd`
      INTO linked_incident, linked_book_lfd
      FROM `nv_etb`
     WHERE `einsatz_id` = NEW.`einsatz_id`
       AND `estab_book_lfd` = CAST(NEW.`estab_reference` AS UNSIGNED);
    IF linked_incident IS NULL OR linked_incident <> NEW.`einsatz_id`
       OR linked_book_lfd IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB reference target is not an earlier incident entry';
    END IF;
  END IF;
  IF NEW.`estab_correction_of` IS NOT NULL THEN
    IF NEW.`etb_lfd-nr` IS NOT NULL AND NEW.`etb_lfd-nr` <> 0
       AND NEW.`estab_correction_of` = NEW.`etb_lfd-nr` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB correction cannot reference itself';
    END IF;
    SET linked_incident = NULL;
    SET linked_event_type = NULL;
    SET linked_correction = NULL;
    SET linked_book_lfd = NULL;
    SELECT `einsatz_id`, `estab_event_type`, `estab_correction_of`,
           `estab_book_lfd`
      INTO linked_incident, linked_event_type, linked_correction,
           linked_book_lfd
      FROM `nv_etb` WHERE `etb_lfd-nr` = NEW.`estab_correction_of`;
    IF linked_incident IS NULL OR linked_incident <> NEW.`einsatz_id`
       OR BINARY NEW.`estab_event_type` <> BINARY 'korrektur'
       OR BINARY linked_event_type = BINARY 'korrektur'
       OR linked_correction IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB correction target is invalid';
    END IF;
    IF NEW.`estab_reference` IS NULL
       OR BINARY NEW.`estab_reference` <>
          BINARY CAST(linked_book_lfd AS CHAR) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB correction requires canonical local reference';
    END IF;
  ELSEIF BINARY NEW.`estab_event_type` = BINARY 'korrektur' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB correction requires an original entry';
  END IF;

  IF COALESCE(NEW.`estab_book_lfd`, 0) <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB book number is allocated by the database';
  END IF;
  UPDATE `nv_logbuch_koepfe`
     SET `next_lfd` = LAST_INSERT_ID(`next_lfd` + 1)
   WHERE `einsatz_id` = NEW.`einsatz_id` AND `buchart` = 'ETB';
  IF ROW_COUNT() <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ETB book head is missing';
  END IF;
  SET assigned_lfd = LAST_INSERT_ID() - 1;
  IF assigned_lfd IS NULL OR assigned_lfd < 1 OR assigned_lfd > 4294967295 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ETB book number range is exhausted';
  END IF;
  SET NEW.`estab_book_lfd` = assigned_lfd;
END//

CREATE TRIGGER `estab_tbb_bi_einsatz`
BEFORE INSERT ON `nv_tbb` FOR EACH ROW
BEGIN
  DECLARE linked_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE linked_entry_type VARCHAR(32) DEFAULT NULL;
  DECLARE linked_correction INT DEFAULT NULL;
  DECLARE assigned_lfd BIGINT UNSIGNED DEFAULT NULL;
  DECLARE shift_incident BIGINT UNSIGNED DEFAULT NULL;
  DECLARE writer_shift BIGINT UNSIGNED DEFAULT NULL;
  DECLARE writer_valid INTEGER DEFAULT 0;

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
  IF BINARY NEW.`estab_entry_type` = BINARY 'nachricht'
     AND NEW.`estab_message_id` IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB message entry requires canonical message link';
  END IF;
  IF NEW.`estab_event_time` IS NULL THEN
    SET NEW.`estab_event_time` = NOW(6);
  END IF;
  SET NEW.`estab_recorded_at` = NOW(6);
  SET NEW.`tbb_time` = NEW.`estab_event_time`;

  IF NEW.`estab_shift_id` IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB entry requires a duty shift';
  END IF;
  SELECT `einsatz_id` INTO shift_incident FROM `nv_dienstschichten`
   WHERE `dienstschicht_id` = NEW.`estab_shift_id`;
  IF shift_incident IS NULL OR shift_incident <> NEW.`einsatz_id` THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB duty shift targets another incident';
  END IF;
  IF BINARY COALESCE(NEW.`tbb_kuerzel`, '') = BINARY 'system'
     AND BINARY COALESCE(NEW.`tbb_benutzer`, '') = BINARY 'eStab-System' THEN
    IF NEW.`estab_writer_assignment_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'System TTB entry cannot claim a human writer';
    END IF;
  ELSE
    IF NEW.`estab_writer_assignment_id` IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Manual TTB entry requires its duty assignment';
    END IF;
    SELECT `dienstschicht_id` INTO writer_shift FROM `nv_dienstbesetzungen`
     WHERE `dienstbesetzung_id` = NEW.`estab_writer_assignment_id`;
    IF writer_shift IS NULL OR writer_shift <> NEW.`estab_shift_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB writer does not belong to its duty shift';
    END IF;
    SELECT COUNT(*) INTO writer_valid
      FROM `nv_dienstbesetzungen` AS assignment
      JOIN `nv_dienstschichten` AS duty_shift
        ON duty_shift.`dienstschicht_id` = assignment.`dienstschicht_id`
      JOIN `nv_benutzer` AS account
        ON BINARY account.`kuerzel` = BINARY assignment.`benutzer_kuerzel`
     WHERE assignment.`dienstbesetzung_id` =
           NEW.`estab_writer_assignment_id`
       AND assignment.`dienstschicht_id` = NEW.`estab_shift_id`
       AND BINARY assignment.`status` = BINARY 'ANGENOMMEN'
       AND BINARY duty_shift.`status` = BINARY 'AKTIV'
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0
       AND BINARY assignment.`benutzer_kuerzel` = BINARY NEW.`tbb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`tbb_benutzer`
       AND BINARY assignment.`funktion` = BINARY NEW.`tbb_funktion`
       AND BINARY assignment.`funktion` = BINARY 'A/W';
    IF writer_valid <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB writer identity or status is invalid';
    END IF;
  END IF;

  IF NEW.`estab_message_id` IS NOT NULL THEN
    IF BINARY NEW.`estab_entry_type` <> BINARY 'nachricht' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB message link requires canonical message entry';
    END IF;
    IF BINARY COALESCE(NEW.`tbb_kuerzel`, '') <> BINARY 'system'
       OR BINARY COALESCE(NEW.`tbb_benutzer`, '') <> BINARY 'eStab-System' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'TTB message link requires system-generated evidence';
    END IF;
    SELECT `einsatz_id` INTO linked_incident FROM `nv_nachrichten`
     WHERE `00_lfd` = NEW.`estab_message_id`;
    IF linked_incident IS NULL OR linked_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB message link targets another incident';
    END IF;
  END IF;
  IF NEW.`estab_correction_of` IS NOT NULL THEN
    IF NEW.`tbb_lfd-nr` IS NOT NULL AND NEW.`tbb_lfd-nr` <> 0
       AND NEW.`estab_correction_of` = NEW.`tbb_lfd-nr` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB correction cannot reference itself';
    END IF;
    SET linked_incident = NULL;
    SET linked_entry_type = NULL;
    SET linked_correction = NULL;
    SELECT `einsatz_id`, `estab_entry_type`, `estab_correction_of`
      INTO linked_incident, linked_entry_type, linked_correction
      FROM `nv_tbb` WHERE `tbb_lfd-nr` = NEW.`estab_correction_of`;
    IF linked_incident IS NULL OR linked_incident <> NEW.`einsatz_id`
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
  UPDATE `nv_logbuch_koepfe`
     SET `next_lfd` = LAST_INSERT_ID(`next_lfd` + 1)
   WHERE `einsatz_id` = NEW.`einsatz_id` AND `buchart` = 'TTB';
  IF ROW_COUNT() <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TTB book head is missing';
  END IF;
  SET assigned_lfd = LAST_INSERT_ID() - 1;
  IF assigned_lfd IS NULL OR assigned_lfd < 1 OR assigned_lfd > 4294967295 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'TTB book number range is exhausted';
  END IF;
  SET NEW.`estab_book_lfd` = assigned_lfd;
END//
DELIMITER ;

-- The legacy completed-handover column `uebergeben_am` is the timestamp at
-- which the two-sided transition is completed. The request retains the
-- earlier handover/initiation timestamp separately. Keep all immutable
-- completion evidence on one database-clock value even for direct SQL.
DROP TRIGGER IF EXISTS `estab_log111_handover_insert_time`;
DELIMITER //
CREATE TRIGGER `estab_log111_handover_insert_time`
BEFORE INSERT ON `nv_dienstuebergaben`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM `nv_dienstschichten` AS old_shift
      JOIN `nv_dienstschichten` AS new_shift
        ON new_shift.`dienstschicht_id` = NEW.`an_dienstschicht_id`
       AND new_shift.`einsatz_id` = old_shift.`einsatz_id`
      JOIN `nv_dienstuebergabe_anfragen` AS request
        ON request.`einsatz_id` = old_shift.`einsatz_id`
       AND request.`von_dienstschicht_id` = old_shift.`dienstschicht_id`
       AND request.`an_dienstschicht_id` = new_shift.`dienstschicht_id`
       AND request.`status` = 'INITIIERT'
     WHERE old_shift.`dienstschicht_id` = NEW.`von_dienstschicht_id`
       AND old_shift.`einsatz_id` = NEW.`einsatz_id`
       AND old_shift.`status` = 'UEBERGEBEN'
       AND new_shift.`status` = 'AKTIV'
       AND old_shift.`beendet_am` = NEW.`uebergeben_am`
       AND new_shift.`aktiviert_am` = NEW.`uebergeben_am`
       AND request.`initiiert_am` <= NEW.`uebergeben_am`
       AND BINARY request.`initiiert_von` =
           BINARY NEW.`uebergeben_von`
       AND BINARY request.`zusammenfassung` =
           BINARY NEW.`zusammenfassung`
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Duty handover completion times are inconsistent';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_log111_handover_confirm_time`;
DELIMITER //
CREATE TRIGGER `estab_log111_handover_confirm_time`
BEFORE UPDATE ON `nv_dienstuebergabe_anfragen`
FOR EACH ROW
BEGIN
  IF NEW.`status` = 'BESTAETIGT'
     AND NOT EXISTS (
       SELECT 1
         FROM `nv_dienstuebergaben` AS completed_handover
         JOIN `nv_dienstschichten` AS old_shift
           ON old_shift.`dienstschicht_id` =
              completed_handover.`von_dienstschicht_id`
          AND old_shift.`einsatz_id` = completed_handover.`einsatz_id`
         JOIN `nv_dienstschichten` AS new_shift
           ON new_shift.`dienstschicht_id` =
              completed_handover.`an_dienstschicht_id`
          AND new_shift.`einsatz_id` = completed_handover.`einsatz_id`
        WHERE completed_handover.`dienstuebergabe_id` =
              NEW.`dienstuebergabe_id`
          AND completed_handover.`einsatz_id` = NEW.`einsatz_id`
          AND completed_handover.`von_dienstschicht_id` =
              NEW.`von_dienstschicht_id`
          AND completed_handover.`an_dienstschicht_id` =
              NEW.`an_dienstschicht_id`
          AND NEW.`bestaetigt_am` = completed_handover.`uebergeben_am`
          AND NEW.`bestaetigt_am` = old_shift.`beendet_am`
          AND NEW.`bestaetigt_am` = new_shift.`aktiviert_am`
          AND OLD.`initiiert_am` <= NEW.`bestaetigt_am`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Duty handover confirmation times are inconsistent';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_111_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_111_validate()
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.columns
       WHERE table_schema = DATABASE()
         AND (
           (table_name = 'nv_etb' AND column_name = 'estab_shift_id'
             AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
             AND is_nullable = 'YES'
             AND column_comment = 'estab:migration:111:etb-shift:v1')
           OR (table_name = 'nv_etb'
             AND column_name = 'estab_writer_assignment_id'
             AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
             AND is_nullable = 'YES'
             AND column_comment = 'estab:migration:111:etb-writer:v1')
           OR (table_name = 'nv_etb'
             AND column_name = 'estab_assignee_assignment_id'
             AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
             AND is_nullable = 'YES'
             AND column_comment = 'estab:migration:111:etb-assignee:v1')
           OR (table_name = 'nv_etb' AND column_name = 'estab_assignment'
             AND data_type = 'varchar' AND character_maximum_length = 255
             AND character_set_name = 'utf8mb4'
             AND collation_name = 'utf8mb4_unicode_ci'
             AND is_nullable = 'YES'
             AND column_comment =
                   'estab:migration:111:etb-assignment-snapshot:v1')
           OR (table_name = 'nv_tbb' AND column_name = 'estab_shift_id'
             AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
             AND is_nullable = 'YES'
             AND column_comment = 'estab:migration:111:tbb-shift:v1')
           OR (table_name = 'nv_tbb'
             AND column_name = 'estab_writer_assignment_id'
             AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
             AND is_nullable = 'YES'
             AND column_comment = 'estab:migration:111:tbb-writer:v1')
         )) <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Logbook shift migration validation failed: columns';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.statistics
       WHERE table_schema = DATABASE() AND sub_part IS NULL
         AND (
           (table_name = 'nv_etb'
             AND index_name = 'idx_etb_einsatz_shift_book'
             AND non_unique = 1 AND index_type = 'BTREE'
             AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
               OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
               OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
           OR (table_name = 'nv_etb'
             AND index_name = 'idx_etb_writer_assignment'
             AND non_unique = 1 AND seq_in_index = 1
             AND column_name = 'estab_writer_assignment_id')
           OR (table_name = 'nv_etb'
             AND index_name = 'idx_etb_assignee_assignment'
             AND non_unique = 1 AND seq_in_index = 1
             AND column_name = 'estab_assignee_assignment_id')
           OR (table_name = 'nv_tbb'
             AND index_name = 'idx_tbb_einsatz_shift_book'
             AND non_unique = 1 AND index_type = 'BTREE'
             AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
               OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
               OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
           OR (table_name = 'nv_tbb'
             AND index_name = 'idx_tbb_writer_assignment'
             AND non_unique = 1 AND seq_in_index = 1
             AND column_name = 'estab_writer_assignment_id')
         )) <> 9 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Logbook shift migration validation failed: indexes';
  END IF;
  IF (SELECT COUNT(*)
        FROM information_schema.referential_constraints AS relation
        JOIN information_schema.key_column_usage AS key_column
          ON key_column.constraint_schema = relation.constraint_schema
         AND key_column.table_name = relation.table_name
         AND key_column.constraint_name = relation.constraint_name
       WHERE relation.constraint_schema = DATABASE()
         AND relation.update_rule = 'RESTRICT'
         AND relation.delete_rule = 'RESTRICT'
         AND (
           (relation.table_name = 'nv_etb'
             AND relation.constraint_name = 'fk_etb_shift'
             AND relation.referenced_table_name = 'nv_dienstschichten'
             AND key_column.column_name = 'estab_shift_id'
             AND key_column.referenced_column_name = 'dienstschicht_id')
           OR (relation.table_name = 'nv_etb'
             AND relation.constraint_name = 'fk_etb_writer_assignment'
             AND relation.referenced_table_name = 'nv_dienstbesetzungen'
             AND key_column.column_name = 'estab_writer_assignment_id'
             AND key_column.referenced_column_name = 'dienstbesetzung_id')
           OR (relation.table_name = 'nv_etb'
             AND relation.constraint_name = 'fk_etb_assignee_assignment'
             AND relation.referenced_table_name = 'nv_dienstbesetzungen'
             AND key_column.column_name = 'estab_assignee_assignment_id'
             AND key_column.referenced_column_name = 'dienstbesetzung_id')
           OR (relation.table_name = 'nv_tbb'
             AND relation.constraint_name = 'fk_tbb_shift'
             AND relation.referenced_table_name = 'nv_dienstschichten'
             AND key_column.column_name = 'estab_shift_id'
             AND key_column.referenced_column_name = 'dienstschicht_id')
           OR (relation.table_name = 'nv_tbb'
             AND relation.constraint_name = 'fk_tbb_writer_assignment'
             AND relation.referenced_table_name = 'nv_dienstbesetzungen'
             AND key_column.column_name = 'estab_writer_assignment_id'
             AND key_column.referenced_column_name = 'dienstbesetzung_id')
         )) <> 5 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Logbook shift migration validation failed: constraints';
  END IF;
  IF (SELECT COUNT(*) FROM information_schema.triggers
       WHERE trigger_schema = DATABASE()
         AND ((trigger_name = 'estab_etb_bi_einsatz'
               AND event_object_table = 'nv_etb'
               AND action_timing = 'BEFORE'
               AND event_manipulation = 'INSERT'
               AND action_statement LIKE '%ETB entry requires a duty shift%'
               AND action_statement LIKE
                     '%ETB writer does not belong to its duty shift%'
               AND action_statement LIKE
                     '%ETB writer identity or status is invalid%'
               AND action_statement LIKE
                     '%ETB assignee does not belong to its duty shift%'
               AND action_statement LIKE
                     '%ETB assignee identity or status is invalid%'
               AND action_statement LIKE
                     '%ETB reference must be a canonical local number%'
               AND action_statement LIKE
                     '%ETB correction requires canonical local reference%')
           OR (trigger_name = 'estab_tbb_bi_einsatz'
               AND event_object_table = 'nv_tbb'
               AND action_timing = 'BEFORE'
               AND event_manipulation = 'INSERT'
               AND action_statement LIKE '%TTB entry requires a duty shift%'
               AND action_statement LIKE
                     '%TTB writer does not belong to its duty shift%'
               AND action_statement LIKE
                     '%TTB writer identity or status is invalid%'
               AND action_statement LIKE
                     '%TTB message entry requires canonical message link%')
           OR (trigger_name = 'estab_dv94_hat_update'
               AND event_object_table = 'nv_dienstbesetzungen'
               AND action_timing = 'BEFORE'
               AND event_manipulation = 'UPDATE'
               AND action_statement LIKE
                     '%Active shift ETB writer change requires confirmed handover%'
               AND action_statement LIKE
                     '%Invalid duty assignment acceptance%')
           OR (trigger_name = 'estab_log111_handover_insert_time'
               AND event_object_table = 'nv_dienstuebergaben'
               AND action_timing = 'BEFORE'
               AND event_manipulation = 'INSERT'
               AND action_statement LIKE
                     '%Duty handover completion times are inconsistent%'
               AND action_statement LIKE
                     '%request.`initiiert_am` <= NEW.`uebergeben_am`%')
           OR (trigger_name = 'estab_log111_handover_confirm_time'
               AND event_object_table = 'nv_dienstuebergabe_anfragen'
               AND action_timing = 'BEFORE'
               AND event_manipulation = 'UPDATE'
               AND action_statement LIKE
                     '%Duty handover confirmation times are inconsistent%'
               AND action_statement LIKE
                     '%OLD.`initiiert_am` <= NEW.`bestaetigt_am`%'))) <> 5 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Logbook shift migration validation failed: triggers';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_111_validate();
DROP PROCEDURE estab_migrate_111_validate;
