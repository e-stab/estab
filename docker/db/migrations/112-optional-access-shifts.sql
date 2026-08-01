-- Optional access-shift groups and function-based operational evidence.
--
-- A group can enable or disable the accounts assigned to it as one unit, but
-- it is deliberately not an operational permission boundary.  Operational
-- database writes continue to require the singleton active, open incident and
-- the authoritative account function/role.  Presence (`nv_benutzer.aktiv`)
-- and the durable administrative block (`estab_gesperrt`) remain independent
-- account facts and are never used as group state.
--
-- Migrations 94, 110 and 111 remain immutable.  This migration replaces only
-- their final triggers whose active-duty requirement is superseded.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_112_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_112_preflight()
BEGIN
  DECLARE conflicting_tables INTEGER DEFAULT 0;
  DECLARE required_tables INTEGER DEFAULT 0;
  DECLARE canonical_phase_tables INTEGER DEFAULT 0;
  DECLARE named_trigger_sources INTEGER DEFAULT 0;
  DECLARE canonical_predecessor_triggers INTEGER DEFAULT 0;
  DECLARE canonical_trigger_sources INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO required_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_name IN (
       'nv_einsaetze', 'nv_einsatz_status', 'nv_benutzer',
       'nv_dienstschichten', 'nv_dienstbesetzungen',
       'nv_etb', 'nv_tbb', 'nv_logbuch_koepfe',
       'nv_fernmeldeplaene', 'nv_fernmeldeplan_eintraege',
       'nv_melderauftraege', 'nv_nachrichten', 'nv_anhang'
     );
  IF required_tables <> 13 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: predecessor table is missing';
  END IF;

  SELECT COUNT(*) INTO conflicting_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
     )
     AND (
       table_type <> 'BASE TABLE'
       OR engine <> 'InnoDB'
       OR table_collation <> 'utf8mb4_unicode_ci'
       OR table_comment <>
          'estab:migration:112:optional-access-shifts:v1'
     );
  IF conflicting_tables <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: foreign table collision';
  END IF;

  -- Both owned tables are the durable phase marker. Before this marker exists,
  -- all predecessor triggers must still be present. After it exists, a missing
  -- owned trigger can only mean that process loss occurred between its DROP
  -- and CREATE statements, so the trigger phase may safely converge.
  SELECT COUNT(*) INTO canonical_phase_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
     )
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment =
       'estab:migration:112:optional-access-shifts:v1';

  SELECT COUNT(*) INTO named_trigger_sources
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_etb_bi_einsatz',
       'estab_tbb_bi_einsatz',
       'estab_dv94_fernmeldeplan_insert',
       'estab_dv94_fernmeldeplan_immutable',
       'estab_dv94_messenger_insert',
       'estab_dv94_messenger_update'
     );

  -- Every name below is owned by an applied predecessor migration.  Accept
  -- either its predecessor marker or this migration's marker so a hard stop
  -- after any trigger DDL is safely resumable without accepting an unrelated
  -- same-name trigger.
  SELECT COUNT(*) INTO canonical_trigger_sources
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND (
       (trigger_name = 'estab_etb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_etb' AND (
          action_statement LIKE '%ETB entry requires a duty shift%'
          OR action_statement LIKE
             '%ETB optional duty provenance must be complete%'))
       OR (trigger_name = 'estab_tbb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_tbb' AND (
          action_statement LIKE '%TTB entry requires a duty shift%'
          OR action_statement LIKE
             '%TTB optional duty provenance must be complete%'))
       OR (trigger_name = 'estab_dv94_fernmeldeplan_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_fernmeldeplaene' AND (
          action_statement LIKE '%creator_shift%'
          OR action_statement LIKE
             '%Telecommunications plan creator account is invalid%'))
       OR (trigger_name = 'estab_dv94_fernmeldeplan_immutable'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_fernmeldeplaene' AND (
          action_statement LIKE '%release_shift%'
          OR action_statement LIKE
             '%Telecommunications plan release account is invalid%'))
       OR (trigger_name = 'estab_dv94_messenger_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_melderauftraege' AND (
          action_statement LIKE '%messenger_shift%'
          OR action_statement LIKE
             '%Messenger assignment account functions are invalid%'))
       OR (trigger_name = 'estab_dv94_messenger_update'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_melderauftraege' AND (
          action_statement LIKE '%report_shift%'
          OR action_statement LIKE
             '%Messenger report account function is invalid%'))
     );

  SELECT COUNT(*) INTO canonical_predecessor_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND action_timing = 'BEFORE'
     AND (
       (trigger_name = 'estab_etb_bi_einsatz'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_etb'
        AND action_statement LIKE '%ETB entry requires a duty shift%'
        AND action_statement LIKE
          '%ETB writer identity or status is invalid%')
       OR (trigger_name = 'estab_tbb_bi_einsatz'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_tbb'
        AND action_statement LIKE '%TTB entry requires a duty shift%'
        AND action_statement LIKE
          '%TTB writer identity or status is invalid%')
       OR (trigger_name = 'estab_dv94_fernmeldeplan_insert'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_fernmeldeplaene'
        AND action_statement LIKE '%creator_shift%'
        AND action_statement LIKE
          '%Telecommunications plan insert requires the active open incident%')
       OR (trigger_name = 'estab_dv94_fernmeldeplan_immutable'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_fernmeldeplaene'
        AND action_statement LIKE '%release_shift%'
        AND action_statement LIKE '%Invalid telecommunications plan release%')
       OR (trigger_name = 'estab_dv94_messenger_insert'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_melderauftraege'
        AND action_statement LIKE '%messenger_shift%'
        AND action_statement LIKE
          '%Messenger assignment requires an active-incident outgoing Me message%')
       OR (trigger_name = 'estab_dv94_messenger_update'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_melderauftraege'
        AND action_statement LIKE '%report_shift%'
        AND action_statement LIKE '%Invalid messenger report evidence%')
     );
  -- A present same-name trigger is never repairable unless its body carries
  -- one of the exact predecessor/final ownership markers above. This remains
  -- a hard collision even after the table phase marker has been committed.
  IF named_trigger_sources <> canonical_trigger_sources THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: trigger collision';
  END IF;
  IF canonical_phase_tables <> 2 AND canonical_predecessor_triggers <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: predecessor trigger is missing';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_112_preflight();
DROP PROCEDURE estab_migrate_112_preflight;

-- Append-only enum extension: adding values at the end preserves the stored
-- representation and meaning of every existing hash-chained event row.
DROP PROCEDURE IF EXISTS estab_migrate_112_event_object_types;
DELIMITER //
CREATE PROCEDURE estab_migrate_112_event_object_types()
BEGIN
  DECLARE legacy_column INTEGER DEFAULT 0;
  DECLARE canonical_column INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO legacy_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_betriebsereignisse'
     AND column_name = 'objekttyp'
     AND ordinal_position = 4
     AND data_type = 'enum'
     AND column_type =
       'enum(''DIENSTSCHICHT'',''DIENSTBESETZUNG'',''DIENSTUEBERGABE'',''FERNMELDEPLAN'',''MELDERAUFTRAG'')'
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'NO'
     AND column_default IS NULL
     AND extra = ''
     AND column_comment = '';
  SELECT COUNT(*) INTO canonical_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_betriebsereignisse'
     AND column_name = 'objekttyp'
     AND ordinal_position = 4
     AND data_type = 'enum'
     AND column_type =
       'enum(''DIENSTSCHICHT'',''DIENSTBESETZUNG'',''DIENSTUEBERGABE'',''FERNMELDEPLAN'',''MELDERAUFTRAG'',''EINSATZ'',''ZUGANGSSCHICHT'')'
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'NO'
     AND column_default IS NULL
     AND extra = ''
     AND column_comment = 'estab:migration:112:event-object-types:v1';

  IF legacy_column = 0 AND canonical_column = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: foreign event object type';
  END IF;
  IF canonical_column = 0 THEN
    ALTER TABLE `nv_betriebsereignisse`
      MODIFY COLUMN `objekttyp` ENUM(
        'DIENSTSCHICHT',
        'DIENSTBESETZUNG',
        'DIENSTUEBERGABE',
        'FERNMELDEPLAN',
        'MELDERAUFTRAG',
        'EINSATZ',
        'ZUGANGSSCHICHT'
      ) NOT NULL
      COMMENT 'estab:migration:112:event-object-types:v1'
      AFTER `sequenz`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_112_event_object_types();
DROP PROCEDURE estab_migrate_112_event_object_types;

CREATE TABLE IF NOT EXISTS `nv_zugangsschichten` (
  `zugangsschicht_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `bezeichnung` VARCHAR(100) NOT NULL,
  `beginn` DATETIME(6) NULL,
  `ende` DATETIME(6) NULL,
  `zugang_aktiv` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `erstellt_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `erstellt_von` VARCHAR(128) NOT NULL,
  `geaendert_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `geaendert_von` VARCHAR(128) NOT NULL,
  PRIMARY KEY (`zugangsschicht_id`),
  UNIQUE KEY `uq_zugangsschicht_bezeichnung` (`einsatz_id`, `bezeichnung`),
  KEY `idx_zugangsschicht_aktiv`
    (`einsatz_id`, `zugang_aktiv`, `zugangsschicht_id`),
  CONSTRAINT `fk_zugangsschicht_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
      ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_zugangsschicht_bezeichnung`
    CHECK (CHAR_LENGTH(TRIM(`bezeichnung`)) > 0),
  CONSTRAINT `ck_zugangsschicht_zeitraum`
    CHECK (`beginn` IS NULL OR `ende` IS NULL OR `beginn` <= `ende`),
  CONSTRAINT `ck_zugangsschicht_aktiv`
    CHECK (`zugang_aktiv` IN (0, 1)),
  CONSTRAINT `ck_zugangsschicht_audit`
    CHECK (CHAR_LENGTH(TRIM(`erstellt_von`)) > 0
      AND CHAR_LENGTH(TRIM(`geaendert_von`)) > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:112:optional-access-shifts:v1';

CREATE TABLE IF NOT EXISTS `nv_zugangsschicht_mitglieder` (
  `zugangsschicht_mitglied_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `zugangsschicht_id` BIGINT UNSIGNED NOT NULL,
  `benutzer_kuerzel` VARCHAR(6) NOT NULL,
  `zugeordnet_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `zugeordnet_von` VARCHAR(128) NOT NULL,
  `entfernt_am` DATETIME(6) NULL,
  `entfernt_von` VARCHAR(128) NULL,
  `aktives_benutzer_kuerzel` VARCHAR(6)
    GENERATED ALWAYS AS (
      CASE
        WHEN `entfernt_am` IS NULL THEN `benutzer_kuerzel`
        ELSE NULL
      END
    ) STORED,
  PRIMARY KEY (`zugangsschicht_mitglied_id`),
  UNIQUE KEY `uq_zugangsschicht_aktives_mitglied`
    (`zugangsschicht_id`, `aktives_benutzer_kuerzel`),
  KEY `idx_zugangsschicht_mitglied_benutzer`
    (`benutzer_kuerzel`, `entfernt_am`),
  CONSTRAINT `fk_zugangsschicht_mitglied_schicht`
    FOREIGN KEY (`zugangsschicht_id`)
      REFERENCES `nv_zugangsschichten` (`zugangsschicht_id`)
      ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_zugangsschicht_mitglied_benutzer`
    FOREIGN KEY (`benutzer_kuerzel`) REFERENCES `nv_benutzer` (`kuerzel`)
      ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `ck_zugangsschicht_mitglied_status`
    CHECK (
      (`entfernt_am` IS NULL AND `entfernt_von` IS NULL)
      OR (`entfernt_am` IS NOT NULL
        AND `entfernt_am` >= `zugeordnet_am`
        AND CHAR_LENGTH(TRIM(COALESCE(`entfernt_von`, ''))) > 0)
    ),
  CONSTRAINT `ck_zugangsschicht_mitglied_audit`
    CHECK (CHAR_LENGTH(TRIM(`zugeordnet_von`)) > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:112:optional-access-shifts:v1';

DROP PROCEDURE IF EXISTS estab_migrate_112_tables_postflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_112_tables_postflight()
BEGIN
  DECLARE canonical_tables INTEGER DEFAULT 0;
  DECLARE total_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE canonical_index_parts INTEGER DEFAULT 0;
  DECLARE canonical_foreign_keys INTEGER DEFAULT 0;
  DECLARE canonical_event_type INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_event_type
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_betriebsereignisse'
     AND column_name = 'objekttyp'
     AND ordinal_position = 4
     AND data_type = 'enum'
     AND column_type =
       'enum(''DIENSTSCHICHT'',''DIENSTBESETZUNG'',''DIENSTUEBERGABE'',''FERNMELDEPLAN'',''MELDERAUFTRAG'',''EINSATZ'',''ZUGANGSSCHICHT'')'
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'NO'
     AND column_default IS NULL
     AND extra = ''
     AND column_comment = 'estab:migration:112:event-object-types:v1';
  IF canonical_event_type <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: event object type mismatch';
  END IF;

  SELECT COUNT(*) INTO canonical_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
     )
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment =
       'estab:migration:112:optional-access-shifts:v1';
  IF canonical_tables <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: table ownership mismatch';
  END IF;

  SELECT COUNT(*) INTO total_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
     );
  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'nv_zugangsschichten' AND (
         (column_name = 'zugangsschicht_id' AND ordinal_position = 1
          AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
          AND is_nullable = 'NO'
          AND extra = 'auto_increment')
         OR (column_name = 'einsatz_id' AND ordinal_position = 2
          AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
          AND is_nullable = 'NO')
         OR (column_name = 'bezeichnung' AND ordinal_position = 3
          AND column_type = 'varchar(100)' AND is_nullable = 'NO')
         OR (column_name = 'beginn' AND ordinal_position = 4
          AND column_type = 'datetime(6)' AND is_nullable = 'YES')
         OR (column_name = 'ende' AND ordinal_position = 5
          AND column_type = 'datetime(6)' AND is_nullable = 'YES')
         OR (column_name = 'zugang_aktiv' AND ordinal_position = 6
          AND data_type = 'tinyint' AND column_type LIKE '%unsigned%'
          AND is_nullable = 'NO'
          AND column_default = '0')
         OR (column_name = 'erstellt_am' AND ordinal_position = 7
          AND column_type = 'datetime(6)' AND is_nullable = 'NO')
         OR (column_name = 'erstellt_von' AND ordinal_position = 8
          AND column_type = 'varchar(128)' AND is_nullable = 'NO')
         OR (column_name = 'geaendert_am' AND ordinal_position = 9
          AND column_type = 'datetime(6)' AND is_nullable = 'NO')
         OR (column_name = 'geaendert_von' AND ordinal_position = 10
          AND column_type = 'varchar(128)' AND is_nullable = 'NO')
       ))
       OR (table_name = 'nv_zugangsschicht_mitglieder' AND (
         (column_name = 'zugangsschicht_mitglied_id' AND ordinal_position = 1
          AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
          AND is_nullable = 'NO'
          AND extra = 'auto_increment')
         OR (column_name = 'zugangsschicht_id' AND ordinal_position = 2
          AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
          AND is_nullable = 'NO')
         OR (column_name = 'benutzer_kuerzel' AND ordinal_position = 3
          AND column_type = 'varchar(6)' AND is_nullable = 'NO')
         OR (column_name = 'zugeordnet_am' AND ordinal_position = 4
          AND column_type = 'datetime(6)' AND is_nullable = 'NO')
         OR (column_name = 'zugeordnet_von' AND ordinal_position = 5
          AND column_type = 'varchar(128)' AND is_nullable = 'NO')
         OR (column_name = 'entfernt_am' AND ordinal_position = 6
          AND column_type = 'datetime(6)' AND is_nullable = 'YES')
         OR (column_name = 'entfernt_von' AND ordinal_position = 7
          AND column_type = 'varchar(128)' AND is_nullable = 'YES')
         OR (column_name = 'aktives_benutzer_kuerzel'
          AND ordinal_position = 8
          AND column_type = 'varchar(6)' AND is_nullable = 'YES'
          AND extra LIKE '%STORED GENERATED%'
          AND generation_expression LIKE '%entfernt_am%'
          AND generation_expression LIKE '%benutzer_kuerzel%')
       ))
     );
  IF total_columns <> 18 OR canonical_columns <> 18 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: incompatible columns';
  END IF;

  SELECT COUNT(*) INTO canonical_index_parts
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND sub_part IS NULL
     AND (
       (table_name = 'nv_zugangsschichten'
        AND index_name = 'uq_zugangsschicht_bezeichnung'
        AND non_unique = 0
        AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
          OR (seq_in_index = 2 AND column_name = 'bezeichnung')))
       OR (table_name = 'nv_zugangsschichten'
        AND index_name = 'idx_zugangsschicht_aktiv'
        AND non_unique = 1
        AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
          OR (seq_in_index = 2 AND column_name = 'zugang_aktiv')
          OR (seq_in_index = 3 AND column_name = 'zugangsschicht_id')))
       OR (table_name = 'nv_zugangsschicht_mitglieder'
        AND index_name = 'uq_zugangsschicht_aktives_mitglied'
        AND non_unique = 0
        AND ((seq_in_index = 1 AND column_name = 'zugangsschicht_id')
          OR (seq_in_index = 2
            AND column_name = 'aktives_benutzer_kuerzel')))
       OR (table_name = 'nv_zugangsschicht_mitglieder'
        AND index_name = 'idx_zugangsschicht_mitglied_benutzer'
        AND non_unique = 1
        AND ((seq_in_index = 1 AND column_name = 'benutzer_kuerzel')
          OR (seq_in_index = 2 AND column_name = 'entfernt_am')))
     );
  IF canonical_index_parts <> 9 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: incompatible indexes';
  END IF;

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
       (relation.table_name = 'nv_zugangsschichten'
        AND relation.constraint_name = 'fk_zugangsschicht_einsatz'
        AND relation.referenced_table_name = 'nv_einsaetze'
        AND key_column.column_name = 'einsatz_id'
        AND key_column.referenced_column_name = 'einsatz_id')
       OR (relation.table_name = 'nv_zugangsschicht_mitglieder'
        AND relation.constraint_name = 'fk_zugangsschicht_mitglied_schicht'
        AND relation.referenced_table_name = 'nv_zugangsschichten'
        AND key_column.column_name = 'zugangsschicht_id'
        AND key_column.referenced_column_name = 'zugangsschicht_id')
       OR (relation.table_name = 'nv_zugangsschicht_mitglieder'
        AND relation.constraint_name = 'fk_zugangsschicht_mitglied_benutzer'
        AND relation.referenced_table_name = 'nv_benutzer'
        AND key_column.column_name = 'benutzer_kuerzel'
        AND key_column.referenced_column_name = 'kuerzel')
     );
  IF canonical_foreign_keys <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: incompatible foreign keys';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_112_tables_postflight();
DROP PROCEDURE estab_migrate_112_tables_postflight;

-- ETB writes are authorized by the account function.  A manual row may carry
-- no duty provenance at all.  If the old provenance columns are populated,
-- they remain an exact immutable relation between incident, account, shift
-- and assignment, independent of the historic shift/acceptance status.
DROP TRIGGER IF EXISTS `estab_etb_bi_einsatz`;
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
  DECLARE provenance_valid INTEGER DEFAULT 0;
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

  IF NEW.`estab_shift_id` IS NOT NULL THEN
    SELECT `einsatz_id` INTO shift_incident
      FROM `nv_dienstschichten`
     WHERE `dienstschicht_id` = NEW.`estab_shift_id`;
    IF shift_incident IS NULL OR shift_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB duty shift targets another incident';
    END IF;
  END IF;

  IF BINARY COALESCE(NEW.`etb_kuerzel`, '') = BINARY 'system'
     AND BINARY COALESCE(NEW.`etb_benutzer`, '') = BINARY 'eStab-System' THEN
    IF NEW.`estab_writer_assignment_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'System ETB entry cannot claim a human writer';
    END IF;
  ELSE
    SELECT COUNT(*) INTO writer_valid
      FROM `nv_benutzer` AS account
     WHERE BINARY account.`kuerzel` = BINARY NEW.`etb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`etb_benutzer`
       AND BINARY account.`funktion` = BINARY NEW.`etb_funktion`
       AND BINARY account.`rolle` = BINARY 'Stab'
       AND (BINARY account.`funktion` = BINARY 'ETB'
         OR BINARY account.`funktion` = BINARY 'S2')
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0;
    IF writer_valid <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB writer account function or status is invalid';
    END IF;

    IF (NEW.`estab_shift_id` IS NULL)
       <> (NEW.`estab_writer_assignment_id` IS NULL) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'ETB optional duty provenance must be complete';
    END IF;
    IF NEW.`estab_writer_assignment_id` IS NOT NULL THEN
      SELECT `dienstschicht_id` INTO writer_shift
        FROM `nv_dienstbesetzungen`
       WHERE `dienstbesetzung_id` = NEW.`estab_writer_assignment_id`;
      IF writer_shift IS NULL OR writer_shift <> NEW.`estab_shift_id` THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'ETB writer does not belong to its duty shift';
      END IF;
      SELECT COUNT(*) INTO provenance_valid
        FROM `nv_dienstbesetzungen` AS assignment
        JOIN `nv_dienstschichten` AS duty_shift
          ON duty_shift.`dienstschicht_id` = assignment.`dienstschicht_id`
        JOIN `nv_benutzer` AS account
          ON BINARY account.`kuerzel` = BINARY assignment.`benutzer_kuerzel`
       WHERE assignment.`dienstbesetzung_id` =
             NEW.`estab_writer_assignment_id`
         AND assignment.`dienstschicht_id` = NEW.`estab_shift_id`
         AND duty_shift.`einsatz_id` = NEW.`einsatz_id`
         AND BINARY assignment.`benutzer_kuerzel` = BINARY NEW.`etb_kuerzel`
         AND BINARY assignment.`funktion` = BINARY NEW.`etb_funktion`
         AND BINARY assignment.`rolle` = BINARY account.`rolle`
         AND BINARY account.`funktion` = BINARY NEW.`etb_funktion`
         AND BINARY account.`rolle` = BINARY 'Stab';
      IF provenance_valid <> 1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'ETB writer duty provenance is invalid';
      END IF;
    END IF;
  END IF;

  IF NEW.`estab_assignee_assignment_id` IS NULL THEN
    IF NULLIF(TRIM(COALESCE(NEW.`estab_assignment`, '')), '') IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB assignee snapshot requires an assignment';
    END IF;
    SET NEW.`estab_assignment` = NULL;
  ELSE
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
       AND BINARY account.`funktion` = BINARY assignment.`funktion`
       AND BINARY account.`rolle` = BINARY assignment.`rolle`
     WHERE assignment.`dienstbesetzung_id` =
           NEW.`estab_assignee_assignment_id`
       AND duty_shift.`einsatz_id` = NEW.`einsatz_id`
       AND BINARY assignment.`status` <> BINARY 'ZURUECKGEZOGEN';
    IF assignee_shift IS NULL OR assignment_snapshot IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB assignee duty provenance is invalid';
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
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_tbb_bi_einsatz`;
DELIMITER //
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
  DECLARE provenance_valid INTEGER DEFAULT 0;

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

  IF NEW.`estab_shift_id` IS NOT NULL THEN
    SELECT `einsatz_id` INTO shift_incident FROM `nv_dienstschichten`
     WHERE `dienstschicht_id` = NEW.`estab_shift_id`;
    IF shift_incident IS NULL OR shift_incident <> NEW.`einsatz_id` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB duty shift targets another incident';
    END IF;
  END IF;

  IF BINARY COALESCE(NEW.`tbb_kuerzel`, '') = BINARY 'system'
     AND BINARY COALESCE(NEW.`tbb_benutzer`, '') = BINARY 'eStab-System' THEN
    IF NEW.`estab_writer_assignment_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'System TTB entry cannot claim a human writer';
    END IF;
  ELSE
    SELECT COUNT(*) INTO writer_valid
      FROM `nv_benutzer` AS account
     WHERE BINARY account.`kuerzel` = BINARY NEW.`tbb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`tbb_benutzer`
       AND BINARY account.`funktion` = BINARY NEW.`tbb_funktion`
       AND BINARY account.`funktion` = BINARY 'A/W'
       AND BINARY account.`rolle` = BINARY 'Fernmelder'
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0;
    IF writer_valid <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB writer account function or status is invalid';
    END IF;

    IF (NEW.`estab_shift_id` IS NULL)
       <> (NEW.`estab_writer_assignment_id` IS NULL) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'TTB optional duty provenance must be complete';
    END IF;
    IF NEW.`estab_writer_assignment_id` IS NOT NULL THEN
      SELECT `dienstschicht_id` INTO writer_shift
        FROM `nv_dienstbesetzungen`
       WHERE `dienstbesetzung_id` = NEW.`estab_writer_assignment_id`;
      IF writer_shift IS NULL OR writer_shift <> NEW.`estab_shift_id` THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'TTB writer does not belong to its duty shift';
      END IF;
      SELECT COUNT(*) INTO provenance_valid
        FROM `nv_dienstbesetzungen` AS assignment
        JOIN `nv_dienstschichten` AS duty_shift
          ON duty_shift.`dienstschicht_id` = assignment.`dienstschicht_id`
        JOIN `nv_benutzer` AS account
          ON BINARY account.`kuerzel` = BINARY assignment.`benutzer_kuerzel`
       WHERE assignment.`dienstbesetzung_id` =
             NEW.`estab_writer_assignment_id`
         AND assignment.`dienstschicht_id` = NEW.`estab_shift_id`
         AND duty_shift.`einsatz_id` = NEW.`einsatz_id`
         AND BINARY assignment.`benutzer_kuerzel` = BINARY NEW.`tbb_kuerzel`
         AND BINARY assignment.`funktion` = BINARY NEW.`tbb_funktion`
         AND BINARY assignment.`rolle` = BINARY account.`rolle`
         AND BINARY account.`funktion` = BINARY 'A/W'
         AND BINARY account.`rolle` = BINARY 'Fernmelder';
      IF provenance_valid <> 1 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'TTB writer duty provenance is invalid';
      END IF;
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

-- S6 plan creation and release are account-function decisions.  Their
-- version and immutability rules are unchanged.
DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_insert`
BEFORE INSERT ON `nv_fernmeldeplaene`
FOR EACH ROW
BEGIN
  IF NEW.`status` <> 'ENTWURF'
     OR NEW.`freigegeben_am` IS NOT NULL
     OR NEW.`freigegeben_von` IS NOT NULL
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_einsatz_status` AS active_incident
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
         JOIN `nv_benutzer` AS creator_account
           ON BINARY creator_account.`kuerzel` = BINARY NEW.`erstellt_von`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`
          AND incident.`estab_status` = 'open'
          AND BINARY creator_account.`funktion` = BINARY 'S6'
          AND BINARY creator_account.`rolle` = BINARY 'Stab'
          AND creator_account.`aktiv` = 1
          AND creator_account.`estab_gesperrt` = 0
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Telecommunications plan creator account is invalid';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_immutable`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_immutable`
BEFORE UPDATE ON `nv_fernmeldeplaene`
FOR EACH ROW
BEGIN
  IF NEW.`fernmeldeplan_id` <> OLD.`fernmeldeplan_id`
     OR NEW.`einsatz_id` <> OLD.`einsatz_id`
     OR NEW.`version` <> OLD.`version`
     OR NEW.`erstellt_am` <> OLD.`erstellt_am`
     OR BINARY NEW.`erstellt_von` <> BINARY OLD.`erstellt_von`
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_einsatz_status` AS active_incident
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = OLD.`einsatz_id`
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Telecommunications plan update requires the active open incident';
  END IF;

  IF OLD.`status` = 'ENTWURF' AND NEW.`status` = 'ENTWURF' THEN
    IF OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NOT NULL
       OR NEW.`freigegeben_von` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Draft telecommunications plan has invalid release evidence';
    END IF;
  ELSEIF OLD.`status` = 'ENTWURF' AND NEW.`status` = 'AKTIV' THEN
    IF BINARY OLD.`einsatzbezeichnung` <>
          BINARY NEW.`einsatzbezeichnung`
       OR BINARY OLD.`herkunft` <> BINARY NEW.`herkunft`
       OR OLD.`gueltig_ab` <> NEW.`gueltig_ab`
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR BINARY OLD.`betriebsleitung` <> BINARY NEW.`betriebsleitung`
       OR NOT (OLD.`bemerkungen` <=> NEW.`bemerkungen`)
       OR OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NULL
       OR NEW.`freigegeben_von` IS NULL
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_benutzer` AS release_account
          WHERE BINARY release_account.`kuerzel` =
                BINARY NEW.`freigegeben_von`
            AND BINARY release_account.`funktion` = BINARY 'S6'
            AND BINARY release_account.`rolle` = BINARY 'Stab'
            AND release_account.`aktiv` = 1
            AND release_account.`estab_gesperrt` = 0
       )
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_fernmeldeplan_eintraege` AS release_entry
          WHERE release_entry.`fernmeldeplan_id` =
                OLD.`fernmeldeplan_id`
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Telecommunications plan release account is invalid';
    END IF;
  ELSEIF OLD.`status` = 'AKTIV' AND NEW.`status` = 'ERSETZT' THEN
    IF BINARY OLD.`einsatzbezeichnung` <>
          BINARY NEW.`einsatzbezeichnung`
       OR BINARY OLD.`herkunft` <> BINARY NEW.`herkunft`
       OR OLD.`gueltig_ab` <> NEW.`gueltig_ab`
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR BINARY OLD.`betriebsleitung` <> BINARY NEW.`betriebsleitung`
       OR NOT (OLD.`bemerkungen` <=> NEW.`bemerkungen`)
       OR NOT (OLD.`freigegeben_am` <=> NEW.`freigegeben_am`)
       OR BINARY OLD.`freigegeben_von` <>
          BINARY NEW.`freigegeben_von` THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Activated telecommunications plans are immutable';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid telecommunications plan status transition';
  END IF;
END//
DELIMITER ;

-- The messenger state machine remains unchanged.  Only assignment and final
-- report authority now come directly from the two account functions.
DROP TRIGGER IF EXISTS `estab_dv94_messenger_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_messenger_insert`
BEFORE INSERT ON `nv_melderauftraege`
FOR EACH ROW
BEGIN
  IF NEW.`status` <> 'BEAUFTRAGT'
     OR CHAR_LENGTH(TRIM(NEW.`ziel`)) = 0
     OR NEW.`uebernommen_am` IS NOT NULL
     OR NEW.`tatsaechlicher_empfaenger` IS NOT NULL
     OR NEW.`uebergeben_am` IS NOT NULL
     OR NEW.`ruecknachricht_vorhanden` IS NOT NULL
     OR NEW.`ruecknachricht` IS NOT NULL
     OR NEW.`rueckweg_am` IS NOT NULL
     OR NEW.`zurueck_am` IS NOT NULL
     OR NEW.`abschlussvermerk` IS NOT NULL
     OR NEW.`gemeldet_am` IS NOT NULL
     OR NEW.`gemeldet_an` IS NOT NULL
     OR NEW.`abgebrochen_am` IS NOT NULL
     OR NEW.`abbruchgrund` IS NOT NULL
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_einsatz_status` AS active_incident
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
         JOIN `nv_nachrichten` AS message_row
           ON message_row.`00_lfd` = NEW.`nachricht_id`
          AND message_row.`einsatz_id` = incident.`einsatz_id`
         JOIN `nv_benutzer` AS messenger_account
           ON BINARY messenger_account.`kuerzel` =
              BINARY NEW.`melder_kuerzel`
         JOIN `nv_benutzer` AS supervisor_account
           ON BINARY supervisor_account.`kuerzel` =
              BINARY NEW.`beauftragt_von`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`
          AND incident.`estab_status` = 'open'
          AND message_row.`04_richtung` = 'A'
          AND message_row.`06_befwegausw` = 'Me'
          AND message_row.`x00_status` = 2
          AND message_row.`x01_abschluss` IN ('f','0')
          AND BINARY messenger_account.`funktion` = BINARY 'A/W'
          AND BINARY messenger_account.`rolle` = BINARY 'Fernmelder'
          AND messenger_account.`aktiv` = 1
          AND messenger_account.`estab_gesperrt` = 0
          AND BINARY supervisor_account.`funktion` = BINARY 'LdF'
          AND BINARY supervisor_account.`rolle` = BINARY 'Fernmelder'
          AND supervisor_account.`aktiv` = 1
          AND supervisor_account.`estab_gesperrt` = 0
          AND NOT EXISTS (
            SELECT 1
              FROM `nv_melderauftraege` AS completed_job
             WHERE completed_job.`einsatz_id` = NEW.`einsatz_id`
               AND completed_job.`nachricht_id` = NEW.`nachricht_id`
               AND completed_job.`status` = 'GEMELDET'
          )
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Messenger assignment account functions are invalid';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_messenger_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_messenger_update`
BEFORE UPDATE ON `nv_melderauftraege`
FOR EACH ROW
BEGIN
  IF NEW.`einsatz_id` <> OLD.`einsatz_id`
     OR NEW.`nachricht_id` <> OLD.`nachricht_id`
     OR BINARY NEW.`melder_kuerzel` <> BINARY OLD.`melder_kuerzel`
     OR BINARY NEW.`ziel` <> BINARY OLD.`ziel`
     OR NEW.`beauftragt_am` <> OLD.`beauftragt_am`
     OR BINARY NEW.`beauftragt_von` <> BINARY OLD.`beauftragt_von`
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_einsatz_status` AS active_incident
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = OLD.`einsatz_id`
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid or incomplete messenger status transition';
  END IF;

  IF OLD.`status` = 'BEAUFTRAGT'
     AND NEW.`status` = 'UEBERNOMMEN' THEN
    IF OLD.`uebernommen_am` IS NOT NULL
       OR NEW.`uebernommen_am` IS NULL
       OR NOT (NEW.`tatsaechlicher_empfaenger`
               <=> OLD.`tatsaechlicher_empfaenger`)
       OR NOT (NEW.`uebergeben_am` <=> OLD.`uebergeben_am`)
       OR NOT (NEW.`ruecknachricht_vorhanden`
               <=> OLD.`ruecknachricht_vorhanden`)
       OR NOT (NEW.`ruecknachricht` <=> OLD.`ruecknachricht`)
       OR NOT (NEW.`rueckweg_am` <=> OLD.`rueckweg_am`)
       OR NOT (NEW.`zurueck_am` <=> OLD.`zurueck_am`)
       OR NOT (NEW.`abschlussvermerk` <=> OLD.`abschlussvermerk`)
       OR NOT (NEW.`gemeldet_am` <=> OLD.`gemeldet_am`)
       OR NOT (NEW.`gemeldet_an` <=> OLD.`gemeldet_an`)
       OR NOT (NEW.`abgebrochen_am` <=> OLD.`abgebrochen_am`)
       OR NOT (NEW.`abbruchgrund` <=> OLD.`abbruchgrund`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid messenger acceptance evidence';
    END IF;
  ELSEIF OLD.`status` = 'BEAUFTRAGT'
      AND NEW.`status` = 'ABGEBROCHEN' THEN
    IF NEW.`abgebrochen_am` IS NULL
       OR COALESCE(NEW.`abbruchgrund`, '') = ''
       OR NOT (NEW.`uebernommen_am` <=> OLD.`uebernommen_am`)
       OR NOT (NEW.`tatsaechlicher_empfaenger`
               <=> OLD.`tatsaechlicher_empfaenger`)
       OR NOT (NEW.`uebergeben_am` <=> OLD.`uebergeben_am`)
       OR NOT (NEW.`ruecknachricht_vorhanden`
               <=> OLD.`ruecknachricht_vorhanden`)
       OR NOT (NEW.`ruecknachricht` <=> OLD.`ruecknachricht`)
       OR NOT (NEW.`rueckweg_am` <=> OLD.`rueckweg_am`)
       OR NOT (NEW.`zurueck_am` <=> OLD.`zurueck_am`)
       OR NOT (NEW.`abschlussvermerk` <=> OLD.`abschlussvermerk`)
       OR NOT (NEW.`gemeldet_am` <=> OLD.`gemeldet_am`)
       OR NOT (NEW.`gemeldet_an` <=> OLD.`gemeldet_an`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid messenger cancellation evidence';
    END IF;
  ELSEIF OLD.`status` = 'UEBERNOMMEN'
      AND NEW.`status` = 'UEBERGEBEN' THEN
    IF NOT (NEW.`uebernommen_am` <=> OLD.`uebernommen_am`)
       OR NEW.`uebergeben_am` IS NULL
       OR COALESCE(NEW.`tatsaechlicher_empfaenger`, '') = ''
       OR NOT (NEW.`ruecknachricht_vorhanden`
               <=> OLD.`ruecknachricht_vorhanden`)
       OR NOT (NEW.`ruecknachricht` <=> OLD.`ruecknachricht`)
       OR NOT (NEW.`rueckweg_am` <=> OLD.`rueckweg_am`)
       OR NOT (NEW.`zurueck_am` <=> OLD.`zurueck_am`)
       OR NOT (NEW.`abschlussvermerk` <=> OLD.`abschlussvermerk`)
       OR NOT (NEW.`gemeldet_am` <=> OLD.`gemeldet_am`)
       OR NOT (NEW.`gemeldet_an` <=> OLD.`gemeldet_an`)
       OR NOT (NEW.`abgebrochen_am` <=> OLD.`abgebrochen_am`)
       OR NOT (NEW.`abbruchgrund` <=> OLD.`abbruchgrund`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid messenger delivery evidence';
    END IF;
  ELSEIF OLD.`status` = 'UEBERGEBEN'
      AND NEW.`status` = 'RUECKWEG' THEN
    IF NOT (NEW.`uebernommen_am` <=> OLD.`uebernommen_am`)
       OR NOT (NEW.`tatsaechlicher_empfaenger`
               <=> OLD.`tatsaechlicher_empfaenger`)
       OR NOT (NEW.`uebergeben_am` <=> OLD.`uebergeben_am`)
       OR NEW.`rueckweg_am` IS NULL
       OR NEW.`ruecknachricht_vorhanden` IS NULL
       OR NEW.`ruecknachricht_vorhanden` NOT IN (0, 1)
       OR (
         NEW.`ruecknachricht_vorhanden` = 1
         AND COALESCE(NEW.`ruecknachricht`, '') = ''
       )
       OR (
         NEW.`ruecknachricht_vorhanden` = 0
         AND COALESCE(NEW.`ruecknachricht`, '') <> ''
       )
       OR NOT (NEW.`zurueck_am` <=> OLD.`zurueck_am`)
       OR NOT (NEW.`abschlussvermerk` <=> OLD.`abschlussvermerk`)
       OR NOT (NEW.`gemeldet_am` <=> OLD.`gemeldet_am`)
       OR NOT (NEW.`gemeldet_an` <=> OLD.`gemeldet_an`)
       OR NOT (NEW.`abgebrochen_am` <=> OLD.`abgebrochen_am`)
       OR NOT (NEW.`abbruchgrund` <=> OLD.`abbruchgrund`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid messenger return-path evidence';
    END IF;
  ELSEIF OLD.`status` = 'RUECKWEG'
      AND NEW.`status` = 'ZURUECK' THEN
    IF NOT (NEW.`uebernommen_am` <=> OLD.`uebernommen_am`)
       OR NOT (NEW.`tatsaechlicher_empfaenger`
               <=> OLD.`tatsaechlicher_empfaenger`)
       OR NOT (NEW.`uebergeben_am` <=> OLD.`uebergeben_am`)
       OR NOT (NEW.`ruecknachricht_vorhanden`
               <=> OLD.`ruecknachricht_vorhanden`)
       OR NOT (NEW.`ruecknachricht` <=> OLD.`ruecknachricht`)
       OR NOT (NEW.`rueckweg_am` <=> OLD.`rueckweg_am`)
       OR NEW.`zurueck_am` IS NULL
       OR NOT (NEW.`abschlussvermerk` <=> OLD.`abschlussvermerk`)
       OR NOT (NEW.`gemeldet_am` <=> OLD.`gemeldet_am`)
       OR NOT (NEW.`gemeldet_an` <=> OLD.`gemeldet_an`)
       OR NOT (NEW.`abgebrochen_am` <=> OLD.`abgebrochen_am`)
       OR NOT (NEW.`abbruchgrund` <=> OLD.`abbruchgrund`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid messenger return evidence';
    END IF;
  ELSEIF OLD.`status` = 'ZURUECK'
      AND NEW.`status` = 'GEMELDET' THEN
    IF NOT (NEW.`uebernommen_am` <=> OLD.`uebernommen_am`)
       OR NOT (NEW.`tatsaechlicher_empfaenger`
               <=> OLD.`tatsaechlicher_empfaenger`)
       OR NOT (NEW.`uebergeben_am` <=> OLD.`uebergeben_am`)
       OR NOT (NEW.`ruecknachricht_vorhanden`
               <=> OLD.`ruecknachricht_vorhanden`)
       OR NOT (NEW.`ruecknachricht` <=> OLD.`ruecknachricht`)
       OR NOT (NEW.`rueckweg_am` <=> OLD.`rueckweg_am`)
       OR NOT (NEW.`zurueck_am` <=> OLD.`zurueck_am`)
       OR NEW.`gemeldet_am` IS NULL
       OR COALESCE(NEW.`gemeldet_an`, '') = ''
       OR COALESCE(NEW.`abschlussvermerk`, '') = ''
       OR NOT (NEW.`abgebrochen_am` <=> OLD.`abgebrochen_am`)
       OR NOT (NEW.`abbruchgrund` <=> OLD.`abbruchgrund`)
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_benutzer` AS report_account
          WHERE BINARY report_account.`kuerzel` = BINARY NEW.`gemeldet_an`
            AND BINARY report_account.`funktion` = BINARY 'LdF'
            AND BINARY report_account.`rolle` = BINARY 'Fernmelder'
            AND report_account.`aktiv` = 1
            AND report_account.`estab_gesperrt` = 0
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Messenger report account function is invalid';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid messenger status transition';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_112_trigger_postflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_112_trigger_postflight()
BEGIN
  DECLARE canonical_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND (
       (trigger_name = 'estab_etb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND action_statement LIKE
          '%ETB optional duty provenance must be complete%'
        AND action_statement LIKE
          '%ETB writer account function or status is invalid%'
        AND action_statement NOT LIKE '%duty_shift.`status`%'
        AND action_statement LIKE
          '%assignment.`status` <> BINARY ''ZURUECKGEZOGEN''%'
        AND action_statement NOT LIKE
          '%assignment.`status` = BINARY ''ANGENOMMEN''%')
       OR (trigger_name = 'estab_tbb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND action_statement LIKE
          '%TTB optional duty provenance must be complete%'
        AND action_statement LIKE
          '%TTB writer account function or status is invalid%'
        AND action_statement NOT LIKE '%duty_shift.`status`%'
        AND action_statement NOT LIKE '%assignment.`status`%')
       OR (trigger_name = 'estab_dv94_fernmeldeplan_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND action_statement LIKE
          '%Telecommunications plan creator account is invalid%'
        AND action_statement NOT LIKE '%creator_shift%'
        AND action_statement NOT LIKE '%creator_hat%')
       OR (trigger_name = 'estab_dv94_fernmeldeplan_immutable'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND action_statement LIKE
          '%Telecommunications plan release account is invalid%'
        AND action_statement NOT LIKE '%release_shift%'
        AND action_statement NOT LIKE '%release_hat%')
       OR (trigger_name = 'estab_dv94_messenger_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND action_statement LIKE
          '%Messenger assignment account functions are invalid%'
        AND action_statement NOT LIKE '%messenger_shift%'
        AND action_statement NOT LIKE '%supervisor_shift%')
       OR (trigger_name = 'estab_dv94_messenger_update'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND action_statement LIKE
          '%Messenger report account function is invalid%'
        AND action_statement NOT LIKE '%report_shift%'
        AND action_statement NOT LIKE '%report_hat%')
     );
  IF canonical_triggers <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Optional access-shift migration blocked: final trigger mismatch';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_112_trigger_postflight();
DROP PROCEDURE estab_migrate_112_trigger_postflight;
