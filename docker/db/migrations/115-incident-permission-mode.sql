-- Per-incident operational permission mode.
--
-- STRICT preserves the function/role boundaries installed by migration 112.
-- LOOSE accepts every real, active and unblocked account while retaining the
-- exact stored identity, singleton active/open incident, provenance and state
-- machine boundaries. Existing incidents deliberately default to STRICT.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_115_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_115_preflight()
BEGIN
  DECLARE required_tables INTEGER DEFAULT 0;
  DECLARE created_by_position INTEGER DEFAULT 0;
  DECLARE existing_mode_columns INTEGER DEFAULT 0;
  DECLARE canonical_mode_columns INTEGER DEFAULT 0;
  DECLARE named_role_triggers INTEGER DEFAULT 0;
  DECLARE canonical_role_triggers INTEGER DEFAULT 0;
  DECLARE canonical_predecessor_triggers INTEGER DEFAULT 0;
  DECLARE named_guard_triggers INTEGER DEFAULT 0;
  DECLARE canonical_guard_triggers INTEGER DEFAULT 0;

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
        'Permission-mode migration blocked: predecessor table is missing';
  END IF;

  SELECT COALESCE(MAX(ordinal_position), 0) INTO created_by_position
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'erstellt_von'
     AND column_type = 'varchar(128)'
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci'
     AND is_nullable = 'NO';
  IF created_by_position = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration blocked: incident predecessor is incompatible';
  END IF;

  SELECT COUNT(*) INTO existing_mode_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'estab_permission_mode';
  SELECT COUNT(*) INTO canonical_mode_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'estab_permission_mode'
     AND ordinal_position = created_by_position + 1
     AND data_type = 'enum'
     AND column_type = 'enum(''STRICT'',''LOOSE'')'
     AND character_set_name = 'ascii'
     AND collation_name = 'ascii_bin'
     AND is_nullable = 'NO'
     AND column_default = '''STRICT'''
     AND extra = ''
     AND column_comment =
       'estab:migration:115:incident-permission-mode:v1';
  IF existing_mode_columns <> canonical_mode_columns THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration blocked: foreign mode column collision';
  END IF;

  SELECT COUNT(*) INTO named_role_triggers
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

  -- Accept only migration 112's exact terminal generation or this migration's
  -- mode-aware generation. Once the column exists it is the durable phase
  -- marker, so a hard stop between DROP and CREATE can safely converge.
  SELECT COUNT(*) INTO canonical_role_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND (
       (trigger_name = 'estab_etb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_etb'
        AND action_statement LIKE
          '%ETB optional duty provenance must be complete%'
        AND action_statement LIKE
          '%ETB writer account function or status is invalid%'
        AND (action_statement NOT LIKE '%estab_permission_mode%'
          OR action_statement LIKE
             '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'))
       OR (trigger_name = 'estab_tbb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_tbb'
        AND action_statement LIKE
          '%TTB optional duty provenance must be complete%'
        AND action_statement LIKE
          '%TTB writer account function or status is invalid%'
        AND (action_statement NOT LIKE '%estab_permission_mode%'
          OR action_statement LIKE
             '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'))
       OR (trigger_name = 'estab_dv94_fernmeldeplan_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_fernmeldeplaene'
        AND action_statement LIKE
          '%Telecommunications plan creator account is invalid%'
        AND (action_statement NOT LIKE '%estab_permission_mode%'
          OR action_statement LIKE
             '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'))
       OR (trigger_name = 'estab_dv94_fernmeldeplan_immutable'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_fernmeldeplaene'
        AND action_statement LIKE
          '%Telecommunications plan release account is invalid%'
        AND (action_statement NOT LIKE '%estab_permission_mode%'
          OR action_statement LIKE
             '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'))
       OR (trigger_name = 'estab_dv94_messenger_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_melderauftraege'
        AND action_statement LIKE
          '%Messenger assignment account functions are invalid%'
        AND (action_statement NOT LIKE '%estab_permission_mode%'
          OR action_statement LIKE
             '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'))
       OR (trigger_name = 'estab_dv94_messenger_update'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_melderauftraege'
        AND action_statement LIKE
          '%Messenger report account function is invalid%'
        AND (action_statement NOT LIKE '%estab_permission_mode%'
          OR action_statement LIKE
             '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'))
     );
  IF named_role_triggers <> canonical_role_triggers THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration blocked: role trigger collision';
  END IF;

  SELECT COUNT(*) INTO canonical_predecessor_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_etb_bi_einsatz',
       'estab_tbb_bi_einsatz',
       'estab_dv94_fernmeldeplan_insert',
       'estab_dv94_fernmeldeplan_immutable',
       'estab_dv94_messenger_insert',
       'estab_dv94_messenger_update'
     )
     AND action_statement NOT LIKE '%estab_permission_mode%';
  IF canonical_mode_columns = 0
     AND canonical_predecessor_triggers <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration blocked: predecessor trigger is missing';
  END IF;

  SELECT COUNT(*) INTO named_guard_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_permission_mode_incident_insert',
       'estab_permission_mode_incident_update'
     );
  SELECT COUNT(*) INTO canonical_guard_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'nv_einsaetze'
     AND action_timing = 'BEFORE'
     AND (
       (trigger_name = 'estab_permission_mode_incident_insert'
        AND event_manipulation = 'INSERT'
        AND action_statement LIKE
          '%Loose incident creation requires the audited application path%')
       OR (trigger_name = 'estab_permission_mode_incident_update'
        AND event_manipulation = 'UPDATE'
        AND action_statement LIKE
          '%Direct incident permission-mode manipulation is blocked%'
        AND action_statement LIKE
          '%estab_permission_mode_admin_write_id%')
     );
  IF named_guard_triggers <> canonical_guard_triggers
     OR (canonical_mode_columns = 0 AND named_guard_triggers <> 0) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration blocked: incident guard collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_115_preflight();
DROP PROCEDURE estab_migrate_115_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_115_add_mode;
DELIMITER //
CREATE PROCEDURE estab_migrate_115_add_mode()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_einsaetze'
       AND column_name = 'estab_permission_mode'
  ) THEN
    ALTER TABLE `nv_einsaetze`
      ADD COLUMN `estab_permission_mode` ENUM('STRICT','LOOSE')
        CHARACTER SET ascii COLLATE ascii_bin
        NOT NULL DEFAULT 'STRICT'
        COMMENT 'estab:migration:115:incident-permission-mode:v1'
        AFTER `erstellt_von`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_115_add_mode();
DROP PROCEDURE estab_migrate_115_add_mode;

-- The narrow application marker prevents accidental or old unmarked DML from
-- bypassing the incident-domain transaction. It is a coherence guard, not a
-- privilege boundary against a principal that already holds arbitrary SQL
-- rights and can set connection-local variables. Database credentials remain
-- a trusted deployment secret. STRICT stays the safe provisioning default.
DROP TRIGGER IF EXISTS `estab_permission_mode_incident_insert`;
DROP TRIGGER IF EXISTS `estab_permission_mode_incident_update`;
DELIMITER //
CREATE TRIGGER `estab_permission_mode_incident_insert`
BEFORE INSERT ON `nv_einsaetze` FOR EACH ROW
BEGIN
  IF NOT (
       BINARY NEW.`estab_permission_mode` = BINARY 'STRICT'
       OR BINARY NEW.`estab_permission_mode` = BINARY 'LOOSE'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Incident permission mode is invalid';
  END IF;
  IF BINARY NEW.`estab_permission_mode` = BINARY 'LOOSE'
     AND COALESCE(@estab_permission_mode_create_write, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Loose incident creation requires the audited application path';
  END IF;
END//

CREATE TRIGGER `estab_permission_mode_incident_update`
BEFORE UPDATE ON `nv_einsaetze` FOR EACH ROW
BEGIN
  IF NOT (
       BINARY NEW.`estab_permission_mode` = BINARY 'STRICT'
       OR BINARY NEW.`estab_permission_mode` = BINARY 'LOOSE'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Incident permission mode is invalid';
  END IF;

  IF BINARY NEW.`estab_permission_mode` <>
       BINARY OLD.`estab_permission_mode` THEN
    IF NOT (@estab_permission_mode_admin_write_id <=> OLD.`einsatz_id`)
       OR NOT (NEW.`einsatz_id` <=> OLD.`einsatz_id`)
       OR NOT (NEW.`kennung` <=> OLD.`kennung`)
       OR NOT (NEW.`name` <=> OLD.`name`)
       OR NOT (NEW.`beginn` <=> OLD.`beginn`)
       OR NOT (NEW.`ende` <=> OLD.`ende`)
       OR NOT (NEW.`ort` <=> OLD.`ort`)
       OR NOT (NEW.`organisation` <=> OLD.`organisation`)
       OR NOT (NEW.`fuehrungsstellenname`
               <=> OLD.`fuehrungsstellenname`)
       OR NOT (NEW.`fuehrungsstellenname_gesperrt`
               <=> OLD.`fuehrungsstellenname_gesperrt`)
       OR NOT (NEW.`einsatzleitung` <=> OLD.`einsatzleitung`)
       OR NOT (NEW.`beschreibung` <=> OLD.`beschreibung`)
       OR NOT (NEW.`metadaten` <=> OLD.`metadaten`)
       OR NOT (NEW.`erstellt_am` <=> OLD.`erstellt_am`)
       OR NOT (NEW.`erstellt_von` <=> OLD.`erstellt_von`)
       OR BINARY OLD.`estab_status` <> BINARY 'open'
       OR BINARY NEW.`estab_status` <> BINARY 'open'
       OR NOT (NEW.`estab_closed_at` <=> OLD.`estab_closed_at`)
       OR NOT (NEW.`estab_closed_by` <=> OLD.`estab_closed_by`)
       OR NOT (NEW.`estab_close_note` <=> OLD.`estab_close_note`)
       OR NOT (NEW.`estab_retain_until` <=> OLD.`estab_retain_until`)
       OR NEW.`estab_legal_hold` <> OLD.`estab_legal_hold`
       OR NOT (NEW.`estab_legal_hold_reason`
               <=> OLD.`estab_legal_hold_reason`)
       OR NOT (NEW.`estab_legal_hold_at` <=> OLD.`estab_legal_hold_at`)
       OR NOT (NEW.`estab_legal_hold_by` <=> OLD.`estab_legal_hold_by`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Direct incident permission-mode manipulation is blocked';
    END IF;
  END IF;
END//
DELIMITER ;

-- ETB keeps exact account identity and optional provenance in both modes.
-- Only STRICT additionally requires the ETB/S2 Stab function boundary.
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
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = NEW.`einsatz_id`
     WHERE BINARY account.`kuerzel` = BINARY NEW.`etb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`etb_benutzer`
       AND BINARY account.`funktion` = BINARY NEW.`etb_funktion`
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0
       AND (
         BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
         OR (
           BINARY account.`rolle` = BINARY 'Stab'
           AND (BINARY account.`funktion` = BINARY 'ETB'
             OR BINARY account.`funktion` = BINARY 'S2')
         )
       );
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
        JOIN `nv_einsaetze` AS incident
          ON incident.`einsatz_id` = duty_shift.`einsatz_id`
       WHERE assignment.`dienstbesetzung_id` =
             NEW.`estab_writer_assignment_id`
         AND assignment.`dienstschicht_id` = NEW.`estab_shift_id`
         AND duty_shift.`einsatz_id` = NEW.`einsatz_id`
         AND BINARY assignment.`benutzer_kuerzel` = BINARY NEW.`etb_kuerzel`
         AND BINARY assignment.`funktion` = BINARY NEW.`etb_funktion`
         AND BINARY assignment.`rolle` = BINARY account.`rolle`
         AND BINARY account.`funktion` = BINARY NEW.`etb_funktion`
         AND (
           BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
           OR BINARY account.`rolle` = BINARY 'Stab'
         );
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

-- Plan state and immutability rules are unchanged. LOOSE only broadens the
-- creator/releaser account function while keeping a real active account.
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
          AND creator_account.`aktiv` = 1
          AND creator_account.`estab_gesperrt` = 0
          AND (
            BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
            OR (
              BINARY creator_account.`funktion` = BINARY 'S6'
              AND BINARY creator_account.`rolle` = BINARY 'Stab'
            )
          )
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
           JOIN `nv_einsaetze` AS incident
             ON incident.`einsatz_id` = OLD.`einsatz_id`
          WHERE BINARY release_account.`kuerzel` =
                BINARY NEW.`freigegeben_von`
            AND release_account.`aktiv` = 1
            AND release_account.`estab_gesperrt` = 0
            AND (
              BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
              OR (
                BINARY release_account.`funktion` = BINARY 'S6'
                AND BINARY release_account.`rolle` = BINARY 'Stab'
              )
            )
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

-- Selecting a messenger remains a professional suitability decision in both
-- modes. Only the assigning LdF function is relaxed in LOOSE mode.
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
          AND supervisor_account.`aktiv` = 1
          AND supervisor_account.`estab_gesperrt` = 0
          AND (
            BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
            OR (
              BINARY supervisor_account.`funktion` = BINARY 'LdF'
              AND BINARY supervisor_account.`rolle` = BINARY 'Fernmelder'
            )
          )
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
           JOIN `nv_einsaetze` AS incident
             ON incident.`einsatz_id` = OLD.`einsatz_id`
          WHERE BINARY report_account.`kuerzel` = BINARY NEW.`gemeldet_an`
            AND report_account.`aktiv` = 1
            AND report_account.`estab_gesperrt` = 0
            AND (
              BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
              OR (
                BINARY report_account.`funktion` = BINARY 'LdF'
                AND BINARY report_account.`rolle` = BINARY 'Fernmelder'
              )
            )
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


-- TTB likewise retains account name/code/function identity in LOOSE mode;
-- STRICT additionally requires the A/W Fernmelder function boundary.
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
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = NEW.`einsatz_id`
     WHERE BINARY account.`kuerzel` = BINARY NEW.`tbb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`tbb_benutzer`
       AND BINARY account.`funktion` = BINARY NEW.`tbb_funktion`
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0
       AND (
         BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
         OR (
           BINARY account.`funktion` = BINARY 'A/W'
           AND BINARY account.`rolle` = BINARY 'Fernmelder'
         )
       );
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
        JOIN `nv_einsaetze` AS incident
          ON incident.`einsatz_id` = duty_shift.`einsatz_id`
       WHERE assignment.`dienstbesetzung_id` =
             NEW.`estab_writer_assignment_id`
         AND assignment.`dienstschicht_id` = NEW.`estab_shift_id`
         AND duty_shift.`einsatz_id` = NEW.`einsatz_id`
         AND BINARY assignment.`benutzer_kuerzel` = BINARY NEW.`tbb_kuerzel`
         AND BINARY assignment.`funktion` = BINARY NEW.`tbb_funktion`
         AND BINARY assignment.`rolle` = BINARY account.`rolle`
         AND BINARY account.`funktion` = BINARY NEW.`tbb_funktion`
         AND (
           BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
           OR (
             BINARY account.`funktion` = BINARY 'A/W'
             AND BINARY account.`rolle` = BINARY 'Fernmelder'
           )
         );
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

DROP PROCEDURE IF EXISTS estab_migrate_115_postflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_115_postflight()
BEGIN
  DECLARE created_by_position INTEGER DEFAULT 0;
  DECLARE canonical_mode_columns INTEGER DEFAULT 0;
  DECLARE canonical_guard_triggers INTEGER DEFAULT 0;
  DECLARE canonical_role_triggers INTEGER DEFAULT 0;

  SELECT COALESCE(MAX(ordinal_position), 0) INTO created_by_position
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'erstellt_von';
  SELECT COUNT(*) INTO canonical_mode_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_einsaetze'
     AND column_name = 'estab_permission_mode'
     AND ordinal_position = created_by_position + 1
     AND data_type = 'enum'
     AND column_type = 'enum(''STRICT'',''LOOSE'')'
     AND character_set_name = 'ascii'
     AND collation_name = 'ascii_bin'
     AND is_nullable = 'NO'
     AND column_default = '''STRICT'''
     AND extra = ''
     AND column_comment =
       'estab:migration:115:incident-permission-mode:v1';
  IF canonical_mode_columns <> 1
     OR EXISTS (
       SELECT 1
         FROM `nv_einsaetze`
        WHERE `estab_permission_mode` NOT IN ('STRICT', 'LOOSE')
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration failed: incident mode is not canonical';
  END IF;

  SELECT COUNT(*) INTO canonical_guard_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND event_object_table = 'nv_einsaetze'
     AND action_timing = 'BEFORE'
     AND (
       (trigger_name = 'estab_permission_mode_incident_insert'
        AND event_manipulation = 'INSERT'
        AND action_statement LIKE
          '%estab_permission_mode_create_write%'
        AND action_statement LIKE
          '%Loose incident creation requires the audited application path%'
        AND action_statement LIKE '%BINARY ''STRICT''%'
        AND action_statement LIKE '%BINARY ''LOOSE''%')
       OR (trigger_name = 'estab_permission_mode_incident_update'
        AND event_manipulation = 'UPDATE'
        AND action_statement LIKE
          '%estab_permission_mode_admin_write_id%'
        AND action_statement LIKE
          '%Direct incident permission-mode manipulation is blocked%'
        AND action_statement LIKE '%estab_closed_at%'
        AND action_statement LIKE '%estab_retain_until%'
        AND action_statement LIKE '%estab_legal_hold_by%')
     );
  IF canonical_guard_triggers <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration failed: incident guards are incomplete';
  END IF;

  SELECT COUNT(*) INTO canonical_role_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND (
       (trigger_name = 'estab_etb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_etb'
        AND action_statement LIKE
          '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'
        AND action_statement LIKE
          '%account.`benutzer` = BINARY NEW.`etb_benutzer`%'
        AND action_statement LIKE
          '%account.`funktion` = BINARY NEW.`etb_funktion`%'
        AND action_statement LIKE
          '%account.`funktion` = BINARY ''ETB''%'
        AND action_statement LIKE
          '%ETB optional duty provenance must be complete%')
       OR (trigger_name = 'estab_tbb_bi_einsatz'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_tbb'
        AND action_statement LIKE
          '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'
        AND action_statement LIKE
          '%account.`benutzer` = BINARY NEW.`tbb_benutzer`%'
        AND action_statement LIKE
          '%account.`funktion` = BINARY NEW.`tbb_funktion`%'
        AND action_statement LIKE
          '%account.`funktion` = BINARY ''A/W''%'
        AND action_statement LIKE
          '%TTB optional duty provenance must be complete%')
       OR (trigger_name = 'estab_dv94_fernmeldeplan_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_fernmeldeplaene'
        AND action_statement LIKE
          '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'
        AND action_statement LIKE
          '%creator_account.`funktion` = BINARY ''S6''%'
        AND action_statement LIKE
          '%creator_account.`estab_gesperrt` = 0%')
       OR (trigger_name = 'estab_dv94_fernmeldeplan_immutable'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_fernmeldeplaene'
        AND action_statement LIKE
          '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'
        AND action_statement LIKE
          '%release_account.`funktion` = BINARY ''S6''%'
        AND action_statement LIKE
          '%Invalid telecommunications plan status transition%')
       OR (trigger_name = 'estab_dv94_messenger_insert'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'INSERT'
        AND event_object_table = 'nv_melderauftraege'
        AND action_statement LIKE
          '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'
        AND action_statement LIKE
          '%messenger_account.`funktion` = BINARY ''A/W''%'
        AND action_statement LIKE
          '%supervisor_account.`funktion` = BINARY ''LdF''%'
        AND action_statement LIKE
          '%Messenger assignment account functions are invalid%')
       OR (trigger_name = 'estab_dv94_messenger_update'
        AND action_timing = 'BEFORE'
        AND event_manipulation = 'UPDATE'
        AND event_object_table = 'nv_melderauftraege'
        AND action_statement LIKE
          '%incident.`estab_permission_mode` = BINARY ''LOOSE''%'
        AND action_statement LIKE
          '%report_account.`funktion` = BINARY ''LdF''%'
        AND action_statement LIKE
          '%Invalid messenger status transition%'
        AND action_statement LIKE
          '%Messenger report account function is invalid%')
     );
  IF canonical_role_triggers <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Permission-mode migration failed: mode-aware trigger mismatch';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_115_postflight();
DROP PROCEDURE estab_migrate_115_postflight;
