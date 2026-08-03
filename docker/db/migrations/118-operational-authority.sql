-- Restore duty-assignment authority in STRICT incidents while keeping LOOSE
-- incidents independent of duty shifts. LOOSE writes remain role-bound: an
-- active account must hold the required tuple either as its primary identity
-- or as an explicit additional function. Existing migrations remain immutable.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- A failed previous attempt may have left one of our temporary helpers behind.
-- Inspect all helper names before the first schema mutation: a same-name
-- routine without our exact ownership marker and execution shape belongs to
-- the operator and must never be replaced or dropped by this migration.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE existing_helper_routines INTEGER DEFAULT 0;
  DECLARE owned_helper_routines INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO existing_helper_routines
    FROM information_schema.routines
   WHERE routine_schema = DATABASE()
     AND routine_name IN (
       'estab_migrate_118_preflight',
       'estab_migrate_118_validate_table',
       'estab_migrate_118_postflight'
     );
  SELECT COUNT(*) INTO owned_helper_routines
    FROM information_schema.routines
   WHERE routine_schema = DATABASE()
     AND routine_type = 'PROCEDURE'
     AND sql_data_access = 'READS SQL DATA'
     AND security_type = 'INVOKER'
     AND (
       (
         routine_name = 'estab_migrate_118_preflight'
         AND routine_comment =
           'estab:migration:118:helper:preflight:v1'
         AND routine_definition LIKE
           '%Operational-authority migration blocked:%'
       )
       OR (
         routine_name = 'estab_migrate_118_validate_table'
         AND routine_comment =
           'estab:migration:118:helper:validate-table:v1'
         AND routine_definition LIKE
           '%Operational-authority migration blocked: grant table mismatch%'
       )
       OR (
         routine_name = 'estab_migrate_118_postflight'
         AND routine_comment =
           'estab:migration:118:helper:postflight:v1'
         AND routine_definition LIKE
           '%Operational-authority migration failed: role trigger mismatch%'
       )
     );
  IF existing_helper_routines <> owned_helper_routines THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration blocked: foreign helper routine collision';
  END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS estab_migrate_118_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_118_preflight()
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'estab:migration:118:helper:preflight:v1'
BEGIN
  DECLARE required_tables INTEGER DEFAULT 0;
  DECLARE existing_grant_tables INTEGER DEFAULT 0;
  DECLARE canonical_grant_tables INTEGER DEFAULT 0;
  DECLARE conflicting_constraint_names INTEGER DEFAULT 0;
  DECLARE named_role_triggers INTEGER DEFAULT 0;
  DECLARE compatible_role_triggers INTEGER DEFAULT 0;

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
       'nv_melderauftraege', 'nv_nachrichten', 'nv_anhang',
       'nv_empfmtx', 'nv_funktionsfaehigkeiten',
       'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
     );
  IF required_tables <> 17 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration blocked: predecessor table is missing';
  END IF;

  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND column_name = 'kuerzel'
       AND data_type = 'varchar'
       AND column_type = 'varchar(6)'
       AND character_set_name = 'utf8mb4'
       AND collation_name = 'utf8mb4_unicode_ci'
       AND is_nullable = 'NO'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration blocked: account key is incompatible';
  END IF;

  SELECT COUNT(*) INTO existing_grant_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen';
  SELECT COUNT(*) INTO canonical_grant_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment =
       'estab:migration:118:additional-user-functions:v1';
  IF existing_grant_tables <> canonical_grant_tables THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration blocked: foreign grant table collision';
  END IF;

  SELECT COUNT(*) INTO conflicting_constraint_names
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name = 'fk_benutzer_zusatzfunktion_benutzer'
     AND table_name <> 'nv_benutzer_zusatzfunktionen';
  IF conflicting_constraint_names <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration blocked: foreign constraint collision';
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

  SELECT COUNT(*) INTO compatible_role_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND (
       (
         trigger_name = 'estab_etb_bi_einsatz'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_etb'
         AND action_statement LIKE '%ETB entry type is not permitted%'
         AND action_statement LIKE '%ETB book number is allocated by the database%'
         AND (
           (
             action_statement NOT LIKE '%nv_benutzer_zusatzfunktionen%'
             AND action_statement LIKE
               '%ETB optional duty provenance must be complete%'
           )
           OR (
             action_statement LIKE '%nv_benutzer_zusatzfunktionen%'
             AND action_statement LIKE
               '%Manual ETB entry requires an active accepted duty assignment%'
             AND action_statement LIKE
               '%estab_logbook_system_write_incident_id%'
             AND action_statement LIKE '%primary_conflicting_matrix%'
           )
         )
       )
       OR (
         trigger_name = 'estab_tbb_bi_einsatz'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_tbb'
         AND action_statement LIKE '%TTB entry type is not permitted%'
         AND action_statement LIKE '%TTB book number is allocated by the database%'
         AND (
           (
             action_statement NOT LIKE '%nv_benutzer_zusatzfunktionen%'
             AND action_statement LIKE
               '%TTB optional duty provenance must be complete%'
           )
           OR (
             action_statement LIKE '%nv_benutzer_zusatzfunktionen%'
             AND action_statement LIKE
               '%Manual TTB entry requires an active accepted duty assignment%'
             AND action_statement LIKE
               '%estab_logbook_system_write_incident_id%'
             AND action_statement LIKE '%primary_conflicting_matrix%'
           )
         )
       )
       OR (
         trigger_name = 'estab_dv94_fernmeldeplan_insert'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_fernmeldeplaene'
         AND action_statement LIKE
           '%Telecommunications plan creator account is invalid%'
         AND (
           action_statement NOT LIKE '%nv_benutzer_zusatzfunktionen%'
           OR (
             action_statement LIKE '%creator_assignment%'
             AND action_statement LIKE '%estab_dv_actor_assignment_id%'
             AND action_statement LIKE '%primary_conflicting_matrix%'
           )
         )
       )
       OR (
         trigger_name = 'estab_dv94_fernmeldeplan_immutable'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND event_object_table = 'nv_fernmeldeplaene'
         AND action_statement LIKE
           '%Discarded telecommunications drafts are immutable evidence%'
         AND action_statement LIKE
           '%Invalid telecommunications plan status transition%'
         AND (
           action_statement NOT LIKE '%nv_benutzer_zusatzfunktionen%'
           OR (
             action_statement LIKE '%release_assignment%'
             AND action_statement LIKE '%estab_dv_actor_assignment_id%'
             AND action_statement LIKE '%primary_conflicting_matrix%'
           )
         )
       )
       OR (
         trigger_name = 'estab_dv94_messenger_insert'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_melderauftraege'
         AND action_statement LIKE
           '%Messenger assignment account functions are invalid%'
         AND (
           action_statement NOT LIKE '%nv_benutzer_zusatzfunktionen%'
           OR (
             action_statement LIKE '%messenger_assignment%'
             AND action_statement LIKE '%estab_dv_actor_assignment_id%'
             AND action_statement LIKE '%estab_dv_target_assignment_id%'
             AND action_statement LIKE '%primary_conflicting_matrix%'
           )
         )
       )
       OR (
         trigger_name = 'estab_dv94_messenger_update'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND event_object_table = 'nv_melderauftraege'
         AND action_statement LIKE '%Invalid messenger status transition%'
         AND action_statement LIKE
           '%Messenger report account function is invalid%'
         AND (
           action_statement NOT LIKE '%nv_benutzer_zusatzfunktionen%'
           OR (
             action_statement LIKE '%report_assignment%'
             AND action_statement LIKE '%estab_dv_actor_assignment_id%'
             AND action_statement LIKE '%primary_conflicting_matrix%'
           )
         )
       )
     );
  IF named_role_triggers <> compatible_role_triggers
     OR (canonical_grant_tables = 0 AND named_role_triggers <> 6) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration blocked: role trigger collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_118_preflight();
DROP PROCEDURE estab_migrate_118_preflight;

CREATE TABLE IF NOT EXISTS `nv_benutzer_zusatzfunktionen` (
  `benutzer_kuerzel` VARCHAR(6) NOT NULL
    COMMENT 'estab:migration:118:account-code:v1',
  `funktion` VARCHAR(10) NOT NULL
    COMMENT 'estab:migration:118:function:v1',
  `rolle` ENUM('Stab','FB','Fernmelder') NOT NULL
    COMMENT 'estab:migration:118:role:v1',
  `vergeben_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    COMMENT 'estab:migration:118:granted-at:v1',
  `vergeben_von` VARCHAR(128) NOT NULL
    COMMENT 'estab:migration:118:granted-by:v1',
  PRIMARY KEY (`benutzer_kuerzel`, `funktion`),
  KEY `idx_benutzer_zusatzfunktion_funktion_rolle` (`funktion`, `rolle`),
  CONSTRAINT `fk_benutzer_zusatzfunktion_benutzer`
    FOREIGN KEY (`benutzer_kuerzel`) REFERENCES `nv_benutzer` (`kuerzel`)
      ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:118:additional-user-functions:v1';

DROP PROCEDURE IF EXISTS estab_migrate_118_validate_table;
DELIMITER //
CREATE PROCEDURE estab_migrate_118_validate_table()
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'estab:migration:118:helper:validate-table:v1'
BEGIN
  DECLARE canonical_tables INTEGER DEFAULT 0;
  DECLARE total_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE total_index_parts INTEGER DEFAULT 0;
  DECLARE canonical_index_parts INTEGER DEFAULT 0;
  DECLARE total_constraints INTEGER DEFAULT 0;
  DECLARE canonical_foreign_keys INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment =
       'estab:migration:118:additional-user-functions:v1';

  SELECT COUNT(*) INTO total_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen';
  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen'
     AND is_nullable = 'NO'
     AND extra = ''
     AND (
       (
         column_name = 'benutzer_kuerzel'
         AND ordinal_position = 1
         AND data_type = 'varchar'
         AND column_type = 'varchar(6)'
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND column_default IS NULL
         AND column_comment = 'estab:migration:118:account-code:v1'
       )
       OR (
         column_name = 'funktion'
         AND ordinal_position = 2
         AND data_type = 'varchar'
         AND column_type = 'varchar(10)'
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND column_default IS NULL
         AND column_comment = 'estab:migration:118:function:v1'
       )
       OR (
         column_name = 'rolle'
         AND ordinal_position = 3
         AND data_type = 'enum'
         AND column_type = 'enum(''Stab'',''FB'',''Fernmelder'')'
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND column_default IS NULL
         AND column_comment = 'estab:migration:118:role:v1'
       )
       OR (
         column_name = 'vergeben_am'
         AND ordinal_position = 4
         AND data_type = 'datetime'
         AND datetime_precision = 6
         AND LOWER(column_default) = 'current_timestamp(6)'
         AND column_comment = 'estab:migration:118:granted-at:v1'
       )
       OR (
         column_name = 'vergeben_von'
         AND ordinal_position = 5
         AND data_type = 'varchar'
         AND column_type = 'varchar(128)'
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND column_default IS NULL
         AND column_comment = 'estab:migration:118:granted-by:v1'
       )
     );

  SELECT COUNT(*) INTO total_index_parts
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen';
  SELECT COUNT(*) INTO canonical_index_parts
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen'
     AND (
       (
         index_name = 'PRIMARY'
         AND non_unique = 0
         AND (
           (seq_in_index = 1 AND column_name = 'benutzer_kuerzel')
           OR (seq_in_index = 2 AND column_name = 'funktion')
         )
       )
       OR (
         index_name = 'idx_benutzer_zusatzfunktion_funktion_rolle'
         AND non_unique = 1
         AND (
           (seq_in_index = 1 AND column_name = 'funktion')
           OR (seq_in_index = 2 AND column_name = 'rolle')
         )
       )
     );

  SELECT COUNT(*) INTO total_constraints
    FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'nv_benutzer_zusatzfunktionen';
  SELECT COUNT(*) INTO canonical_foreign_keys
    FROM information_schema.referential_constraints AS reference_constraint
    JOIN information_schema.key_column_usage AS key_part
      ON key_part.constraint_schema = reference_constraint.constraint_schema
     AND key_part.constraint_name = reference_constraint.constraint_name
     AND key_part.table_name = 'nv_benutzer_zusatzfunktionen'
   WHERE reference_constraint.constraint_schema = DATABASE()
     AND reference_constraint.constraint_name =
       'fk_benutzer_zusatzfunktion_benutzer'
     AND reference_constraint.table_name = 'nv_benutzer_zusatzfunktionen'
     AND reference_constraint.referenced_table_name = 'nv_benutzer'
     AND reference_constraint.update_rule = 'RESTRICT'
     AND reference_constraint.delete_rule = 'CASCADE'
     AND key_part.ordinal_position = 1
     AND key_part.column_name = 'benutzer_kuerzel'
     AND key_part.referenced_table_name = 'nv_benutzer'
     AND key_part.referenced_column_name = 'kuerzel';

  IF canonical_tables <> 1
     OR total_columns <> 5
     OR canonical_columns <> 5
     OR total_index_parts <> 4
     OR canonical_index_parts <> 4
     OR total_constraints <> 2
     OR canonical_foreign_keys <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration blocked: grant table mismatch';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_118_validate_table();
DROP PROCEDURE estab_migrate_118_validate_table;

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
  DECLARE permission_mode VARCHAR(6) DEFAULT NULL;
  DECLARE access_memberships INTEGER DEFAULT 0;
  DECLARE enabled_access_memberships INTEGER DEFAULT 0;
  DECLARE assignee_shift BIGINT UNSIGNED DEFAULT NULL;
  DECLARE assignment_snapshot VARCHAR(255) DEFAULT NULL;

  SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
  SELECT `estab_permission_mode` INTO permission_mode
    FROM `nv_einsaetze`
   WHERE `einsatz_id` = NEW.`einsatz_id`;
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

  IF BINARY permission_mode = BINARY 'STRICT'
     AND NEW.`estab_shift_id` IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'STRICT ETB entry requires duty shift provenance';
  END IF;

  IF BINARY COALESCE(NEW.`etb_kuerzel`, '') = BINARY 'system'
     AND BINARY COALESCE(NEW.`etb_benutzer`, '') = BINARY 'eStab-System' THEN
    IF COALESCE(@estab_logbook_system_write_incident_id, 0) <>
         NEW.`einsatz_id`
       OR BINARY COALESCE(@estab_logbook_system_write_book, '') <>
          BINARY 'ETB'
       OR COALESCE(NEW.`etb_funktion`, '') <> ''
       OR NEW.`estab_writer_assignment_id` IS NOT NULL
       OR NEW.`estab_assignee_assignment_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'System ETB entry context is invalid';
    END IF;
  ELSE
    IF BINARY permission_mode = BINARY 'LOOSE' THEN
      SELECT COUNT(*), COALESCE(SUM(
               CASE WHEN access_shift.`zugang_aktiv` = 1 THEN 1 ELSE 0 END
             ), 0)
        INTO access_memberships, enabled_access_memberships
        FROM `nv_zugangsschicht_mitglieder` AS access_membership
        JOIN `nv_zugangsschichten` AS access_shift
          ON access_shift.`zugangsschicht_id` =
             access_membership.`zugangsschicht_id`
       WHERE access_shift.`einsatz_id` = NEW.`einsatz_id`
         AND BINARY access_membership.`benutzer_kuerzel` =
             BINARY NEW.`etb_kuerzel`
         AND access_membership.`entfernt_am` IS NULL
       FOR UPDATE;
      IF access_memberships > 0 AND enabled_access_memberships = 0 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'ETB writer access shift is inactive';
      END IF;
    END IF;
    IF (NEW.`estab_shift_id` IS NULL)
       <> (NEW.`estab_writer_assignment_id` IS NULL) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'ETB optional duty provenance must be complete';
    END IF;
    IF BINARY permission_mode = BINARY 'STRICT'
       AND (NEW.`estab_shift_id` IS NULL
         OR NEW.`estab_writer_assignment_id` IS NULL) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Manual ETB entry requires an active accepted duty assignment';
    END IF;

    SELECT COUNT(*) INTO writer_valid
      FROM `nv_benutzer` AS account
     WHERE BINARY account.`kuerzel` = BINARY NEW.`etb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`etb_benutzer`
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0
       AND (
         (
           BINARY permission_mode = BINARY 'STRICT'
           AND EXISTS (
             SELECT 1
               FROM `nv_dienstbesetzungen` AS strict_assignment
               JOIN `nv_dienstschichten` AS strict_shift
                 ON strict_shift.`dienstschicht_id` =
                    strict_assignment.`dienstschicht_id`
              WHERE strict_assignment.`dienstbesetzung_id` =
                    NEW.`estab_writer_assignment_id`
                AND strict_assignment.`dienstschicht_id` =
                    NEW.`estab_shift_id`
                AND strict_shift.`einsatz_id` = NEW.`einsatz_id`
                AND BINARY strict_shift.`status` = BINARY 'AKTIV'
                AND BINARY strict_assignment.`status` =
                    BINARY 'ANGENOMMEN'
                AND BINARY strict_assignment.`benutzer_kuerzel` =
                    BINARY account.`kuerzel`
                AND BINARY strict_assignment.`funktion` =
                    BINARY NEW.`etb_funktion`
                AND BINARY strict_assignment.`rolle` = BINARY 'Stab'
                AND (
                  BINARY strict_assignment.`funktion` = BINARY 'ETB'
                  OR BINARY strict_assignment.`funktion` = BINARY 'S2'
                )
           )
         )
         OR (
           BINARY permission_mode = BINARY 'LOOSE'
           AND (
             (
               BINARY account.`funktion` = BINARY NEW.`etb_funktion`
               AND BINARY account.`rolle` = BINARY 'Stab'
               AND (
                 BINARY account.`funktion` = BINARY 'ETB'
                 OR BINARY account.`funktion` = BINARY 'S2'
               )
               AND (
                 EXISTS (
                   SELECT 1
                     FROM `nv_funktionsfaehigkeiten` AS primary_capability
                    WHERE BINARY primary_capability.`funktion` =
                          BINARY account.`funktion`
                      AND BINARY primary_capability.`rolle` =
                          BINARY account.`rolle`
                 )
                 OR EXISTS (
                   SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                    WHERE primary_matrix.`mtx_typ` = 'cb'
                      AND primary_matrix.`mtx_fkt` <> ''
                      AND BINARY primary_matrix.`mtx_fkt` =
                          BINARY account.`funktion`
                      AND BINARY primary_matrix.`mtx_rolle` =
                          BINARY account.`rolle`
                 )
               )
               AND NOT EXISTS (
                 SELECT 1
                   FROM `nv_funktionsfaehigkeiten`
                        AS primary_conflicting_capability
                  WHERE BINARY primary_conflicting_capability.`funktion` =
                        BINARY account.`funktion`
                    AND BINARY primary_conflicting_capability.`rolle` <>
                        BINARY account.`rolle`
               )
               AND NOT EXISTS (
                 SELECT 1 FROM `nv_empfmtx` AS primary_conflicting_matrix
                  WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                    AND primary_conflicting_matrix.`mtx_fkt` <> ''
                    AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                        BINARY account.`funktion`
                    AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                        BINARY account.`rolle`
               )
             )
             OR EXISTS (
               SELECT 1
                 FROM `nv_benutzer_zusatzfunktionen` AS extra_function
                WHERE BINARY extra_function.`benutzer_kuerzel` =
                      BINARY account.`kuerzel`
                  AND BINARY extra_function.`funktion` =
                      BINARY NEW.`etb_funktion`
                  AND BINARY extra_function.`rolle` = BINARY 'Stab'
                  AND (
                    BINARY extra_function.`funktion` = BINARY 'ETB'
                    OR BINARY extra_function.`funktion` = BINARY 'S2'
                  )
                  AND (
                    EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten` AS canonical_capability
                       WHERE BINARY canonical_capability.`funktion` =
                             BINARY extra_function.`funktion`
                         AND BINARY canonical_capability.`rolle` =
                             BINARY extra_function.`rolle`
                    )
                    OR EXISTS (
                      SELECT 1
                        FROM `nv_empfmtx` AS canonical_matrix
                       WHERE canonical_matrix.`mtx_typ` = 'cb'
                         AND canonical_matrix.`mtx_fkt` <> ''
                         AND BINARY canonical_matrix.`mtx_fkt` =
                             BINARY extra_function.`funktion`
                         AND BINARY canonical_matrix.`mtx_rolle` =
                             BINARY extra_function.`rolle`
                    )
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_funktionsfaehigkeiten` AS conflicting_capability
                     WHERE BINARY conflicting_capability.`funktion` =
                           BINARY extra_function.`funktion`
                       AND BINARY conflicting_capability.`rolle` <>
                           BINARY extra_function.`rolle`
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_empfmtx` AS conflicting_matrix
                     WHERE conflicting_matrix.`mtx_typ` = 'cb'
                       AND conflicting_matrix.`mtx_fkt` <> ''
                       AND BINARY conflicting_matrix.`mtx_fkt` =
                           BINARY extra_function.`funktion`
                       AND BINARY conflicting_matrix.`mtx_rolle` <>
                           BINARY extra_function.`rolle`
                  )
             )
           )
         )
       );
    IF writer_valid <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ETB writer account function or status is invalid';
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
         AND BINARY assignment.`benutzer_kuerzel` =
             BINARY NEW.`etb_kuerzel`
         AND BINARY assignment.`funktion` = BINARY NEW.`etb_funktion`
         AND account.`aktiv` = 1
         AND account.`estab_gesperrt` = 0
         AND (
           (
             BINARY permission_mode = BINARY 'STRICT'
             AND BINARY duty_shift.`status` = BINARY 'AKTIV'
             AND BINARY assignment.`status` = BINARY 'ANGENOMMEN'
             AND BINARY assignment.`rolle` = BINARY 'Stab'
             AND (
               BINARY assignment.`funktion` = BINARY 'ETB'
               OR BINARY assignment.`funktion` = BINARY 'S2'
             )
           )
           OR (
             BINARY permission_mode = BINARY 'LOOSE'
             AND BINARY assignment.`rolle` = BINARY 'Stab'
             AND (
               BINARY assignment.`funktion` = BINARY 'ETB'
               OR BINARY assignment.`funktion` = BINARY 'S2'
             )
             AND (
               (
                 BINARY account.`funktion` = BINARY assignment.`funktion`
                 AND BINARY account.`rolle` = BINARY assignment.`rolle`
                 AND (
                   EXISTS (
                     SELECT 1
                       FROM `nv_funktionsfaehigkeiten`
                            AS primary_capability
                      WHERE BINARY primary_capability.`funktion` =
                            BINARY account.`funktion`
                        AND BINARY primary_capability.`rolle` =
                            BINARY account.`rolle`
                   )
                   OR EXISTS (
                     SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                      WHERE primary_matrix.`mtx_typ` = 'cb'
                        AND primary_matrix.`mtx_fkt` <> ''
                        AND BINARY primary_matrix.`mtx_fkt` =
                            BINARY account.`funktion`
                        AND BINARY primary_matrix.`mtx_rolle` =
                            BINARY account.`rolle`
                   )
                 )
                 AND NOT EXISTS (
                   SELECT 1
                     FROM `nv_funktionsfaehigkeiten`
                          AS primary_conflicting_capability
                    WHERE BINARY primary_conflicting_capability.`funktion` =
                          BINARY account.`funktion`
                      AND BINARY primary_conflicting_capability.`rolle` <>
                          BINARY account.`rolle`
                 )
                 AND NOT EXISTS (
                   SELECT 1 FROM `nv_empfmtx` AS primary_conflicting_matrix
                    WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                      AND primary_conflicting_matrix.`mtx_fkt` <> ''
                      AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                          BINARY account.`funktion`
                      AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                          BINARY account.`rolle`
                 )
               )
               OR EXISTS (
                 SELECT 1
                   FROM `nv_benutzer_zusatzfunktionen` AS extra_provenance
                  WHERE BINARY extra_provenance.`benutzer_kuerzel` =
                        BINARY account.`kuerzel`
                    AND BINARY extra_provenance.`funktion` =
                        BINARY assignment.`funktion`
                    AND BINARY extra_provenance.`rolle` =
                        BINARY assignment.`rolle`
                    AND (
                      EXISTS (
                        SELECT 1
                          FROM `nv_funktionsfaehigkeiten`
                               AS canonical_capability
                         WHERE BINARY canonical_capability.`funktion` =
                               BINARY extra_provenance.`funktion`
                           AND BINARY canonical_capability.`rolle` =
                               BINARY extra_provenance.`rolle`
                      )
                      OR EXISTS (
                        SELECT 1
                          FROM `nv_empfmtx` AS canonical_matrix
                         WHERE canonical_matrix.`mtx_typ` = 'cb'
                           AND canonical_matrix.`mtx_fkt` <> ''
                           AND BINARY canonical_matrix.`mtx_fkt` =
                               BINARY extra_provenance.`funktion`
                           AND BINARY canonical_matrix.`mtx_rolle` =
                               BINARY extra_provenance.`rolle`
                      )
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten`
                             AS conflicting_capability
                       WHERE BINARY conflicting_capability.`funktion` =
                             BINARY extra_provenance.`funktion`
                         AND BINARY conflicting_capability.`rolle` <>
                             BINARY extra_provenance.`rolle`
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_empfmtx` AS conflicting_matrix
                       WHERE conflicting_matrix.`mtx_typ` = 'cb'
                         AND conflicting_matrix.`mtx_fkt` <> ''
                         AND BINARY conflicting_matrix.`mtx_fkt` =
                             BINARY extra_provenance.`funktion`
                         AND BINARY conflicting_matrix.`mtx_rolle` <>
                             BINARY extra_provenance.`rolle`
                    )
               )
             )
           )
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
     WHERE assignment.`dienstbesetzung_id` =
           NEW.`estab_assignee_assignment_id`
       AND duty_shift.`einsatz_id` = NEW.`einsatz_id`
       AND (
         (
           BINARY permission_mode = BINARY 'STRICT'
           AND assignment.`dienstschicht_id` = NEW.`estab_shift_id`
           AND BINARY duty_shift.`status` = BINARY 'AKTIV'
           AND BINARY assignment.`status` = BINARY 'ANGENOMMEN'
           AND account.`estab_gesperrt` = 0
         )
         OR (
           BINARY permission_mode = BINARY 'LOOSE'
           AND BINARY assignment.`status` <> BINARY 'ZURUECKGEZOGEN'
         )
       );
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
  DECLARE permission_mode VARCHAR(6) DEFAULT NULL;
  DECLARE access_memberships INTEGER DEFAULT 0;
  DECLARE enabled_access_memberships INTEGER DEFAULT 0;

  SET NEW.`einsatz_id` = estab_incident_for_insert(NEW.`einsatz_id`);
  SELECT `estab_permission_mode` INTO permission_mode
    FROM `nv_einsaetze`
   WHERE `einsatz_id` = NEW.`einsatz_id`;
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

  IF BINARY permission_mode = BINARY 'STRICT'
     AND NEW.`estab_shift_id` IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'STRICT TTB entry requires duty shift provenance';
  END IF;

  IF BINARY COALESCE(NEW.`tbb_kuerzel`, '') = BINARY 'system'
     AND BINARY COALESCE(NEW.`tbb_benutzer`, '') = BINARY 'eStab-System' THEN
    IF COALESCE(@estab_logbook_system_write_incident_id, 0) <>
         NEW.`einsatz_id`
       OR BINARY COALESCE(@estab_logbook_system_write_book, '') <>
          BINARY 'TTB'
       OR COALESCE(NEW.`tbb_funktion`, '') <> ''
       OR NEW.`estab_writer_assignment_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'System TTB entry context is invalid';
    END IF;
  ELSE
    IF BINARY permission_mode = BINARY 'LOOSE' THEN
      SELECT COUNT(*), COALESCE(SUM(
               CASE WHEN access_shift.`zugang_aktiv` = 1 THEN 1 ELSE 0 END
             ), 0)
        INTO access_memberships, enabled_access_memberships
        FROM `nv_zugangsschicht_mitglieder` AS access_membership
        JOIN `nv_zugangsschichten` AS access_shift
          ON access_shift.`zugangsschicht_id` =
             access_membership.`zugangsschicht_id`
       WHERE access_shift.`einsatz_id` = NEW.`einsatz_id`
         AND BINARY access_membership.`benutzer_kuerzel` =
             BINARY NEW.`tbb_kuerzel`
         AND access_membership.`entfernt_am` IS NULL
       FOR UPDATE;
      IF access_memberships > 0 AND enabled_access_memberships = 0 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'TTB writer access shift is inactive';
      END IF;
    END IF;
    IF (NEW.`estab_shift_id` IS NULL)
       <> (NEW.`estab_writer_assignment_id` IS NULL) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'TTB optional duty provenance must be complete';
    END IF;
    IF BINARY permission_mode = BINARY 'STRICT'
       AND (NEW.`estab_shift_id` IS NULL
         OR NEW.`estab_writer_assignment_id` IS NULL) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Manual TTB entry requires an active accepted duty assignment';
    END IF;

    SELECT COUNT(*) INTO writer_valid
      FROM `nv_benutzer` AS account
     WHERE BINARY account.`kuerzel` = BINARY NEW.`tbb_kuerzel`
       AND BINARY account.`benutzer` = BINARY NEW.`tbb_benutzer`
       AND account.`aktiv` = 1
       AND account.`estab_gesperrt` = 0
       AND (
         (
           BINARY permission_mode = BINARY 'STRICT'
           AND EXISTS (
             SELECT 1
               FROM `nv_dienstbesetzungen` AS strict_assignment
               JOIN `nv_dienstschichten` AS strict_shift
                 ON strict_shift.`dienstschicht_id` =
                    strict_assignment.`dienstschicht_id`
              WHERE strict_assignment.`dienstbesetzung_id` =
                    NEW.`estab_writer_assignment_id`
                AND strict_assignment.`dienstschicht_id` =
                    NEW.`estab_shift_id`
                AND strict_shift.`einsatz_id` = NEW.`einsatz_id`
                AND BINARY strict_shift.`status` = BINARY 'AKTIV'
                AND BINARY strict_assignment.`status` =
                    BINARY 'ANGENOMMEN'
                AND BINARY strict_assignment.`benutzer_kuerzel` =
                    BINARY account.`kuerzel`
                AND BINARY strict_assignment.`funktion` =
                    BINARY NEW.`tbb_funktion`
                AND BINARY strict_assignment.`funktion` = BINARY 'A/W'
                AND BINARY strict_assignment.`rolle` =
                    BINARY 'Fernmelder'
           )
         )
         OR (
           BINARY permission_mode = BINARY 'LOOSE'
           AND (
             (
               BINARY account.`funktion` = BINARY NEW.`tbb_funktion`
               AND BINARY account.`funktion` = BINARY 'A/W'
               AND BINARY account.`rolle` = BINARY 'Fernmelder'
               AND (
                 EXISTS (
                   SELECT 1
                     FROM `nv_funktionsfaehigkeiten` AS primary_capability
                    WHERE BINARY primary_capability.`funktion` =
                          BINARY account.`funktion`
                      AND BINARY primary_capability.`rolle` =
                          BINARY account.`rolle`
                 )
                 OR EXISTS (
                   SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                    WHERE primary_matrix.`mtx_typ` = 'cb'
                      AND primary_matrix.`mtx_fkt` <> ''
                      AND BINARY primary_matrix.`mtx_fkt` =
                          BINARY account.`funktion`
                      AND BINARY primary_matrix.`mtx_rolle` =
                          BINARY account.`rolle`
                 )
               )
               AND NOT EXISTS (
                 SELECT 1
                   FROM `nv_funktionsfaehigkeiten`
                        AS primary_conflicting_capability
                  WHERE BINARY primary_conflicting_capability.`funktion` =
                        BINARY account.`funktion`
                    AND BINARY primary_conflicting_capability.`rolle` <>
                        BINARY account.`rolle`
               )
               AND NOT EXISTS (
                 SELECT 1 FROM `nv_empfmtx` AS primary_conflicting_matrix
                  WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                    AND primary_conflicting_matrix.`mtx_fkt` <> ''
                    AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                        BINARY account.`funktion`
                    AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                        BINARY account.`rolle`
               )
             )
             OR EXISTS (
               SELECT 1
                 FROM `nv_benutzer_zusatzfunktionen` AS extra_function
                WHERE BINARY extra_function.`benutzer_kuerzel` =
                      BINARY account.`kuerzel`
                  AND BINARY extra_function.`funktion` =
                      BINARY NEW.`tbb_funktion`
                  AND BINARY extra_function.`funktion` = BINARY 'A/W'
                  AND BINARY extra_function.`rolle` =
                      BINARY 'Fernmelder'
                  AND (
                    EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten` AS canonical_capability
                       WHERE BINARY canonical_capability.`funktion` =
                             BINARY extra_function.`funktion`
                         AND BINARY canonical_capability.`rolle` =
                             BINARY extra_function.`rolle`
                    )
                    OR EXISTS (
                      SELECT 1
                        FROM `nv_empfmtx` AS canonical_matrix
                       WHERE canonical_matrix.`mtx_typ` = 'cb'
                         AND canonical_matrix.`mtx_fkt` <> ''
                         AND BINARY canonical_matrix.`mtx_fkt` =
                             BINARY extra_function.`funktion`
                         AND BINARY canonical_matrix.`mtx_rolle` =
                             BINARY extra_function.`rolle`
                    )
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_funktionsfaehigkeiten` AS conflicting_capability
                     WHERE BINARY conflicting_capability.`funktion` =
                           BINARY extra_function.`funktion`
                       AND BINARY conflicting_capability.`rolle` <>
                           BINARY extra_function.`rolle`
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_empfmtx` AS conflicting_matrix
                     WHERE conflicting_matrix.`mtx_typ` = 'cb'
                       AND conflicting_matrix.`mtx_fkt` <> ''
                       AND BINARY conflicting_matrix.`mtx_fkt` =
                           BINARY extra_function.`funktion`
                       AND BINARY conflicting_matrix.`mtx_rolle` <>
                           BINARY extra_function.`rolle`
                  )
             )
           )
         )
       );
    IF writer_valid <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'TTB writer account function or status is invalid';
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
         AND BINARY assignment.`benutzer_kuerzel` =
             BINARY NEW.`tbb_kuerzel`
         AND BINARY assignment.`funktion` = BINARY NEW.`tbb_funktion`
         AND account.`aktiv` = 1
         AND account.`estab_gesperrt` = 0
         AND (
           (
             BINARY permission_mode = BINARY 'STRICT'
             AND BINARY duty_shift.`status` = BINARY 'AKTIV'
             AND BINARY assignment.`status` = BINARY 'ANGENOMMEN'
             AND BINARY assignment.`funktion` = BINARY 'A/W'
             AND BINARY assignment.`rolle` = BINARY 'Fernmelder'
           )
           OR (
             BINARY permission_mode = BINARY 'LOOSE'
             AND BINARY assignment.`funktion` = BINARY 'A/W'
             AND BINARY assignment.`rolle` = BINARY 'Fernmelder'
             AND (
               (
                 BINARY account.`funktion` = BINARY assignment.`funktion`
                 AND BINARY account.`rolle` = BINARY assignment.`rolle`
                 AND (
                   EXISTS (
                     SELECT 1
                       FROM `nv_funktionsfaehigkeiten`
                            AS primary_capability
                      WHERE BINARY primary_capability.`funktion` =
                            BINARY account.`funktion`
                        AND BINARY primary_capability.`rolle` =
                            BINARY account.`rolle`
                   )
                   OR EXISTS (
                     SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                      WHERE primary_matrix.`mtx_typ` = 'cb'
                        AND primary_matrix.`mtx_fkt` <> ''
                        AND BINARY primary_matrix.`mtx_fkt` =
                            BINARY account.`funktion`
                        AND BINARY primary_matrix.`mtx_rolle` =
                            BINARY account.`rolle`
                   )
                 )
                 AND NOT EXISTS (
                   SELECT 1
                     FROM `nv_funktionsfaehigkeiten`
                          AS primary_conflicting_capability
                    WHERE BINARY primary_conflicting_capability.`funktion` =
                          BINARY account.`funktion`
                      AND BINARY primary_conflicting_capability.`rolle` <>
                          BINARY account.`rolle`
                 )
                 AND NOT EXISTS (
                   SELECT 1 FROM `nv_empfmtx` AS primary_conflicting_matrix
                    WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                      AND primary_conflicting_matrix.`mtx_fkt` <> ''
                      AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                          BINARY account.`funktion`
                      AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                          BINARY account.`rolle`
                 )
               )
               OR EXISTS (
                 SELECT 1
                   FROM `nv_benutzer_zusatzfunktionen` AS extra_provenance
                  WHERE BINARY extra_provenance.`benutzer_kuerzel` =
                        BINARY account.`kuerzel`
                    AND BINARY extra_provenance.`funktion` =
                        BINARY assignment.`funktion`
                    AND BINARY extra_provenance.`rolle` =
                        BINARY assignment.`rolle`
                    AND (
                      EXISTS (
                        SELECT 1
                          FROM `nv_funktionsfaehigkeiten`
                               AS canonical_capability
                         WHERE BINARY canonical_capability.`funktion` =
                               BINARY extra_provenance.`funktion`
                           AND BINARY canonical_capability.`rolle` =
                               BINARY extra_provenance.`rolle`
                      )
                      OR EXISTS (
                        SELECT 1
                          FROM `nv_empfmtx` AS canonical_matrix
                         WHERE canonical_matrix.`mtx_typ` = 'cb'
                           AND canonical_matrix.`mtx_fkt` <> ''
                           AND BINARY canonical_matrix.`mtx_fkt` =
                               BINARY extra_provenance.`funktion`
                           AND BINARY canonical_matrix.`mtx_rolle` =
                               BINARY extra_provenance.`rolle`
                      )
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten`
                             AS conflicting_capability
                       WHERE BINARY conflicting_capability.`funktion` =
                             BINARY extra_provenance.`funktion`
                         AND BINARY conflicting_capability.`rolle` <>
                             BINARY extra_provenance.`rolle`
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_empfmtx` AS conflicting_matrix
                       WHERE conflicting_matrix.`mtx_typ` = 'cb'
                         AND conflicting_matrix.`mtx_fkt` <> ''
                         AND BINARY conflicting_matrix.`mtx_fkt` =
                             BINARY extra_provenance.`funktion`
                         AND BINARY conflicting_matrix.`mtx_rolle` <>
                             BINARY extra_provenance.`rolle`
                    )
               )
             )
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

DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_insert`
BEFORE INSERT ON `nv_fernmeldeplaene`
FOR EACH ROW
BEGIN
  DECLARE permission_mode VARCHAR(6) DEFAULT NULL;
  DECLARE access_memberships INTEGER DEFAULT 0;
  DECLARE enabled_access_memberships INTEGER DEFAULT 0;

  SELECT incident.`estab_permission_mode` INTO permission_mode
    FROM `nv_einsatz_status` AS active_incident
    JOIN `nv_einsaetze` AS incident
      ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
   WHERE active_incident.`singleton_id` = 1
     AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`;
  IF BINARY permission_mode = BINARY 'LOOSE' THEN
    SELECT COUNT(*), COALESCE(SUM(
             CASE WHEN access_shift.`zugang_aktiv` = 1 THEN 1 ELSE 0 END
           ), 0)
      INTO access_memberships, enabled_access_memberships
      FROM `nv_zugangsschicht_mitglieder` AS access_membership
      JOIN `nv_zugangsschichten` AS access_shift
        ON access_shift.`zugangsschicht_id` =
           access_membership.`zugangsschicht_id`
     WHERE access_shift.`einsatz_id` = NEW.`einsatz_id`
       AND BINARY access_membership.`benutzer_kuerzel` =
           BINARY NEW.`erstellt_von`
       AND access_membership.`entfernt_am` IS NULL
     FOR UPDATE;
    IF access_memberships > 0 AND enabled_access_memberships = 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Plan creator access shift is inactive';
    END IF;
  END IF;

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
            (
              BINARY incident.`estab_permission_mode` = BINARY 'STRICT'
              AND @estab_dv_actor_assignment_id IS NOT NULL
              AND @estab_dv_target_assignment_id IS NULL
              AND EXISTS (
                SELECT 1
                  FROM `nv_dienstbesetzungen` AS creator_assignment
                  JOIN `nv_dienstschichten` AS creator_shift
                    ON creator_shift.`dienstschicht_id` =
                       creator_assignment.`dienstschicht_id`
                 WHERE creator_shift.`einsatz_id` = NEW.`einsatz_id`
                   AND creator_assignment.`dienstbesetzung_id` =
                       @estab_dv_actor_assignment_id
                   AND BINARY creator_shift.`status` = BINARY 'AKTIV'
                   AND BINARY creator_assignment.`status` =
                       BINARY 'ANGENOMMEN'
                   AND BINARY creator_assignment.`benutzer_kuerzel` =
                       BINARY creator_account.`kuerzel`
                   AND BINARY creator_assignment.`funktion` = BINARY 'S6'
                   AND BINARY creator_assignment.`rolle` = BINARY 'Stab'
              )
            )
            OR (
              BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
              AND @estab_dv_actor_assignment_id IS NULL
              AND @estab_dv_target_assignment_id IS NULL
              AND (
                (
                  BINARY creator_account.`funktion` = BINARY 'S6'
                  AND BINARY creator_account.`rolle` = BINARY 'Stab'
                  AND (
                    EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten`
                             AS primary_capability
                       WHERE BINARY primary_capability.`funktion` =
                             BINARY creator_account.`funktion`
                         AND BINARY primary_capability.`rolle` =
                             BINARY creator_account.`rolle`
                    )
                    OR EXISTS (
                      SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                       WHERE primary_matrix.`mtx_typ` = 'cb'
                         AND primary_matrix.`mtx_fkt` <> ''
                         AND BINARY primary_matrix.`mtx_fkt` =
                             BINARY creator_account.`funktion`
                         AND BINARY primary_matrix.`mtx_rolle` =
                             BINARY creator_account.`rolle`
                    )
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_funktionsfaehigkeiten`
                           AS primary_conflicting_capability
                     WHERE BINARY primary_conflicting_capability.`funktion` =
                           BINARY creator_account.`funktion`
                       AND BINARY primary_conflicting_capability.`rolle` <>
                           BINARY creator_account.`rolle`
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_empfmtx` AS primary_conflicting_matrix
                     WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                       AND primary_conflicting_matrix.`mtx_fkt` <> ''
                       AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                           BINARY creator_account.`funktion`
                       AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                           BINARY creator_account.`rolle`
                  )
                )
                OR EXISTS (
                  SELECT 1
                    FROM `nv_benutzer_zusatzfunktionen` AS creator_extra
                   WHERE BINARY creator_extra.`benutzer_kuerzel` =
                         BINARY creator_account.`kuerzel`
                     AND BINARY creator_extra.`funktion` = BINARY 'S6'
                     AND BINARY creator_extra.`rolle` = BINARY 'Stab'
                     AND (
                       EXISTS (
                         SELECT 1 FROM `nv_funktionsfaehigkeiten` AS canonical_capability
                          WHERE BINARY canonical_capability.`funktion` =
                                BINARY creator_extra.`funktion`
                            AND BINARY canonical_capability.`rolle` =
                                BINARY creator_extra.`rolle`
                       )
                       OR EXISTS (
                         SELECT 1 FROM `nv_empfmtx` AS canonical_matrix
                          WHERE canonical_matrix.`mtx_typ` = 'cb'
                            AND canonical_matrix.`mtx_fkt` <> ''
                            AND BINARY canonical_matrix.`mtx_fkt` =
                                BINARY creator_extra.`funktion`
                            AND BINARY canonical_matrix.`mtx_rolle` =
                                BINARY creator_extra.`rolle`
                       )
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM `nv_funktionsfaehigkeiten` AS conflicting_capability
                        WHERE BINARY conflicting_capability.`funktion` =
                              BINARY creator_extra.`funktion`
                          AND BINARY conflicting_capability.`rolle` <>
                              BINARY creator_extra.`rolle`
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM `nv_empfmtx` AS conflicting_matrix
                        WHERE conflicting_matrix.`mtx_typ` = 'cb'
                          AND conflicting_matrix.`mtx_fkt` <> ''
                          AND BINARY conflicting_matrix.`mtx_fkt` =
                              BINARY creator_extra.`funktion`
                          AND BINARY conflicting_matrix.`mtx_rolle` <>
                              BINARY creator_extra.`rolle`
                     )
                )
              )
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
  DECLARE permission_mode VARCHAR(6) DEFAULT NULL;
  DECLARE access_memberships INTEGER DEFAULT 0;
  DECLARE enabled_access_memberships INTEGER DEFAULT 0;

  IF NOT (NEW.`fernmeldeplan_id` <=> OLD.`fernmeldeplan_id`)
     OR NOT (NEW.`einsatz_id` <=> OLD.`einsatz_id`)
     OR NOT (NEW.`version` <=> OLD.`version`)
     OR NOT (NEW.`erstellt_am` <=> OLD.`erstellt_am`)
     OR NOT (BINARY NEW.`erstellt_von` <=> BINARY OLD.`erstellt_von`)
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
    SELECT incident.`estab_permission_mode` INTO permission_mode
      FROM `nv_einsatz_status` AS active_incident
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
     WHERE active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = OLD.`einsatz_id`;
    IF BINARY permission_mode = BINARY 'LOOSE' THEN
      SELECT COUNT(*), COALESCE(SUM(
               CASE WHEN access_shift.`zugang_aktiv` = 1 THEN 1 ELSE 0 END
             ), 0)
        INTO access_memberships, enabled_access_memberships
        FROM `nv_zugangsschicht_mitglieder` AS access_membership
        JOIN `nv_zugangsschichten` AS access_shift
          ON access_shift.`zugangsschicht_id` =
             access_membership.`zugangsschicht_id`
       WHERE access_shift.`einsatz_id` = OLD.`einsatz_id`
         AND BINARY access_membership.`benutzer_kuerzel` =
             BINARY NEW.`freigegeben_von`
         AND access_membership.`entfernt_am` IS NULL
       FOR UPDATE;
      IF access_memberships > 0 AND enabled_access_memberships = 0 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Plan release access shift is inactive';
      END IF;
    END IF;
    IF NOT (BINARY OLD.`einsatzbezeichnung` <=>
          BINARY NEW.`einsatzbezeichnung`)
       OR NOT (BINARY OLD.`herkunft` <=> BINARY NEW.`herkunft`)
       OR NOT (OLD.`gueltig_ab` <=> NEW.`gueltig_ab`)
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR NOT (BINARY OLD.`betriebsleitung` <=>
          BINARY NEW.`betriebsleitung`)
       OR NOT (BINARY OLD.`bemerkungen` <=> BINARY NEW.`bemerkungen`)
       OR OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NULL
       OR NEW.`freigegeben_von` IS NULL
       OR OLD.`gueltig_ab` > CURRENT_TIMESTAMP
       OR (OLD.`gueltig_bis` IS NOT NULL
           AND OLD.`gueltig_bis` < CURRENT_TIMESTAMP)
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
              (
                BINARY incident.`estab_permission_mode` = BINARY 'STRICT'
                AND @estab_dv_actor_assignment_id IS NOT NULL
                AND @estab_dv_target_assignment_id IS NULL
                AND EXISTS (
                  SELECT 1
                    FROM `nv_dienstbesetzungen` AS release_assignment
                    JOIN `nv_dienstschichten` AS release_shift
                      ON release_shift.`dienstschicht_id` =
                         release_assignment.`dienstschicht_id`
                   WHERE release_shift.`einsatz_id` = OLD.`einsatz_id`
                     AND release_assignment.`dienstbesetzung_id` =
                         @estab_dv_actor_assignment_id
                     AND BINARY release_shift.`status` = BINARY 'AKTIV'
                     AND BINARY release_assignment.`status` =
                         BINARY 'ANGENOMMEN'
                     AND BINARY release_assignment.`benutzer_kuerzel` =
                         BINARY release_account.`kuerzel`
                     AND BINARY release_assignment.`funktion` = BINARY 'S6'
                     AND BINARY release_assignment.`rolle` = BINARY 'Stab'
                )
              )
              OR (
                BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
                AND @estab_dv_actor_assignment_id IS NULL
                AND @estab_dv_target_assignment_id IS NULL
                AND (
                  (
                    BINARY release_account.`funktion` = BINARY 'S6'
                    AND BINARY release_account.`rolle` = BINARY 'Stab'
                    AND (
                      EXISTS (
                        SELECT 1
                          FROM `nv_funktionsfaehigkeiten`
                               AS primary_capability
                         WHERE BINARY primary_capability.`funktion` =
                               BINARY release_account.`funktion`
                           AND BINARY primary_capability.`rolle` =
                               BINARY release_account.`rolle`
                      )
                      OR EXISTS (
                        SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                         WHERE primary_matrix.`mtx_typ` = 'cb'
                           AND primary_matrix.`mtx_fkt` <> ''
                           AND BINARY primary_matrix.`mtx_fkt` =
                               BINARY release_account.`funktion`
                           AND BINARY primary_matrix.`mtx_rolle` =
                               BINARY release_account.`rolle`
                      )
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten`
                             AS primary_conflicting_capability
                       WHERE BINARY primary_conflicting_capability.`funktion` =
                             BINARY release_account.`funktion`
                         AND BINARY primary_conflicting_capability.`rolle` <>
                             BINARY release_account.`rolle`
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_empfmtx` AS primary_conflicting_matrix
                       WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                         AND primary_conflicting_matrix.`mtx_fkt` <> ''
                         AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                             BINARY release_account.`funktion`
                         AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                             BINARY release_account.`rolle`
                    )
                  )
                  OR EXISTS (
                    SELECT 1
                      FROM `nv_benutzer_zusatzfunktionen` AS release_extra
                     WHERE BINARY release_extra.`benutzer_kuerzel` =
                           BINARY release_account.`kuerzel`
                       AND BINARY release_extra.`funktion` = BINARY 'S6'
                       AND BINARY release_extra.`rolle` = BINARY 'Stab'
                       AND (
                         EXISTS (
                           SELECT 1 FROM `nv_funktionsfaehigkeiten` AS canonical_capability
                            WHERE BINARY canonical_capability.`funktion` =
                                  BINARY release_extra.`funktion`
                              AND BINARY canonical_capability.`rolle` =
                                  BINARY release_extra.`rolle`
                         )
                         OR EXISTS (
                           SELECT 1 FROM `nv_empfmtx` AS canonical_matrix
                            WHERE canonical_matrix.`mtx_typ` = 'cb'
                              AND canonical_matrix.`mtx_fkt` <> ''
                              AND BINARY canonical_matrix.`mtx_fkt` =
                                  BINARY release_extra.`funktion`
                              AND BINARY canonical_matrix.`mtx_rolle` =
                                  BINARY release_extra.`rolle`
                         )
                       )
                       AND NOT EXISTS (
                         SELECT 1 FROM `nv_funktionsfaehigkeiten` AS conflicting_capability
                          WHERE BINARY conflicting_capability.`funktion` =
                                BINARY release_extra.`funktion`
                            AND BINARY conflicting_capability.`rolle` <>
                                BINARY release_extra.`rolle`
                       )
                       AND NOT EXISTS (
                         SELECT 1 FROM `nv_empfmtx` AS conflicting_matrix
                          WHERE conflicting_matrix.`mtx_typ` = 'cb'
                            AND conflicting_matrix.`mtx_fkt` <> ''
                            AND BINARY conflicting_matrix.`mtx_fkt` =
                                BINARY release_extra.`funktion`
                            AND BINARY conflicting_matrix.`mtx_rolle` <>
                                BINARY release_extra.`rolle`
                       )
                  )
                )
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
  ELSEIF OLD.`status` = 'ENTWURF' AND NEW.`status` = 'ERSETZT' THEN
    IF NOT (BINARY OLD.`einsatzbezeichnung` <=>
          BINARY NEW.`einsatzbezeichnung`)
       OR NOT (BINARY OLD.`herkunft` <=> BINARY NEW.`herkunft`)
       OR NOT (OLD.`gueltig_ab` <=> NEW.`gueltig_ab`)
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR NOT (BINARY OLD.`betriebsleitung` <=>
          BINARY NEW.`betriebsleitung`)
       OR NOT (BINARY OLD.`bemerkungen` <=> BINARY NEW.`bemerkungen`)
       OR OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NOT NULL
       OR NEW.`freigegeben_von` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Discarded telecommunications drafts are immutable evidence';
    END IF;
  ELSEIF OLD.`status` = 'AKTIV' AND NEW.`status` = 'ERSETZT' THEN
    IF NOT (BINARY OLD.`einsatzbezeichnung` <=>
          BINARY NEW.`einsatzbezeichnung`)
       OR NOT (BINARY OLD.`herkunft` <=> BINARY NEW.`herkunft`)
       OR NOT (OLD.`gueltig_ab` <=> NEW.`gueltig_ab`)
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR NOT (BINARY OLD.`betriebsleitung` <=>
          BINARY NEW.`betriebsleitung`)
       OR NOT (BINARY OLD.`bemerkungen` <=> BINARY NEW.`bemerkungen`)
       OR NOT (OLD.`freigegeben_am` <=> NEW.`freigegeben_am`)
       OR NOT (BINARY OLD.`freigegeben_von` <=>
          BINARY NEW.`freigegeben_von`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Activated telecommunications plans are immutable';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid telecommunications plan status transition';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_messenger_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_messenger_insert`
BEFORE INSERT ON `nv_melderauftraege`
FOR EACH ROW
BEGIN
  DECLARE permission_mode VARCHAR(6) DEFAULT NULL;
  DECLARE messenger_access_memberships INTEGER DEFAULT 0;
  DECLARE messenger_enabled_access INTEGER DEFAULT 0;
  DECLARE supervisor_access_memberships INTEGER DEFAULT 0;
  DECLARE supervisor_enabled_access INTEGER DEFAULT 0;

  SELECT incident.`estab_permission_mode` INTO permission_mode
    FROM `nv_einsatz_status` AS active_incident
    JOIN `nv_einsaetze` AS incident
      ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
   WHERE active_incident.`singleton_id` = 1
     AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`;
  IF BINARY permission_mode = BINARY 'LOOSE' THEN
    SELECT COUNT(*), COALESCE(SUM(
             CASE WHEN access_shift.`zugang_aktiv` = 1 THEN 1 ELSE 0 END
           ), 0)
      INTO messenger_access_memberships, messenger_enabled_access
      FROM `nv_zugangsschicht_mitglieder` AS access_membership
      JOIN `nv_zugangsschichten` AS access_shift
        ON access_shift.`zugangsschicht_id` =
           access_membership.`zugangsschicht_id`
     WHERE access_shift.`einsatz_id` = NEW.`einsatz_id`
       AND BINARY access_membership.`benutzer_kuerzel` =
           BINARY NEW.`melder_kuerzel`
       AND access_membership.`entfernt_am` IS NULL
     FOR UPDATE;
    SELECT COUNT(*), COALESCE(SUM(
             CASE WHEN access_shift.`zugang_aktiv` = 1 THEN 1 ELSE 0 END
           ), 0)
      INTO supervisor_access_memberships, supervisor_enabled_access
      FROM `nv_zugangsschicht_mitglieder` AS access_membership
      JOIN `nv_zugangsschichten` AS access_shift
        ON access_shift.`zugangsschicht_id` =
           access_membership.`zugangsschicht_id`
     WHERE access_shift.`einsatz_id` = NEW.`einsatz_id`
       AND BINARY access_membership.`benutzer_kuerzel` =
           BINARY NEW.`beauftragt_von`
       AND access_membership.`entfernt_am` IS NULL
     FOR UPDATE;
    IF messenger_access_memberships > 0
       AND messenger_enabled_access = 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Messenger access shift is inactive';
    END IF;
    IF supervisor_access_memberships > 0
       AND supervisor_enabled_access = 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Messenger supervisor access shift is inactive';
    END IF;
  END IF;

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
          AND messenger_account.`aktiv` = 1
          AND messenger_account.`estab_gesperrt` = 0
          AND supervisor_account.`aktiv` = 1
          AND supervisor_account.`estab_gesperrt` = 0
          AND (
            (
              BINARY incident.`estab_permission_mode` = BINARY 'STRICT'
              AND @estab_dv_actor_assignment_id IS NOT NULL
              AND @estab_dv_target_assignment_id IS NOT NULL
              AND EXISTS (
                SELECT 1
                  FROM `nv_dienstbesetzungen` AS messenger_assignment
                  JOIN `nv_dienstschichten` AS messenger_shift
                    ON messenger_shift.`dienstschicht_id` =
                       messenger_assignment.`dienstschicht_id`
                 WHERE messenger_assignment.`dienstbesetzung_id` =
                       @estab_dv_target_assignment_id
                   AND messenger_shift.`einsatz_id` = NEW.`einsatz_id`
                   AND BINARY messenger_shift.`status` = BINARY 'AKTIV'
                   AND BINARY messenger_assignment.`status` =
                       BINARY 'ANGENOMMEN'
                   AND BINARY messenger_assignment.`benutzer_kuerzel` =
                       BINARY messenger_account.`kuerzel`
                   AND BINARY messenger_assignment.`funktion` = BINARY 'A/W'
                   AND BINARY messenger_assignment.`rolle` =
                       BINARY 'Fernmelder'
              )
              AND EXISTS (
                SELECT 1
                  FROM `nv_dienstbesetzungen` AS supervisor_assignment
                  JOIN `nv_dienstschichten` AS supervisor_shift
                    ON supervisor_shift.`dienstschicht_id` =
                       supervisor_assignment.`dienstschicht_id`
                 WHERE supervisor_assignment.`dienstbesetzung_id` =
                       @estab_dv_actor_assignment_id
                   AND supervisor_shift.`einsatz_id` = NEW.`einsatz_id`
                   AND BINARY supervisor_shift.`status` = BINARY 'AKTIV'
                   AND BINARY supervisor_assignment.`status` =
                       BINARY 'ANGENOMMEN'
                   AND BINARY supervisor_assignment.`benutzer_kuerzel` =
                       BINARY supervisor_account.`kuerzel`
                   AND BINARY supervisor_assignment.`funktion` = BINARY 'LdF'
                   AND BINARY supervisor_assignment.`rolle` =
                       BINARY 'Fernmelder'
              )
            )
            OR (
              BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
              AND @estab_dv_actor_assignment_id IS NULL
              AND @estab_dv_target_assignment_id IS NULL
              AND (
                (
                  BINARY messenger_account.`funktion` = BINARY 'A/W'
                  AND BINARY messenger_account.`rolle` = BINARY 'Fernmelder'
                  AND (
                    EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten`
                             AS primary_capability
                       WHERE BINARY primary_capability.`funktion` =
                             BINARY messenger_account.`funktion`
                         AND BINARY primary_capability.`rolle` =
                             BINARY messenger_account.`rolle`
                    )
                    OR EXISTS (
                      SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                       WHERE primary_matrix.`mtx_typ` = 'cb'
                         AND primary_matrix.`mtx_fkt` <> ''
                         AND BINARY primary_matrix.`mtx_fkt` =
                             BINARY messenger_account.`funktion`
                         AND BINARY primary_matrix.`mtx_rolle` =
                             BINARY messenger_account.`rolle`
                    )
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_funktionsfaehigkeiten`
                           AS primary_conflicting_capability
                     WHERE BINARY primary_conflicting_capability.`funktion` =
                           BINARY messenger_account.`funktion`
                       AND BINARY primary_conflicting_capability.`rolle` <>
                           BINARY messenger_account.`rolle`
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_empfmtx` AS primary_conflicting_matrix
                     WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                       AND primary_conflicting_matrix.`mtx_fkt` <> ''
                       AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                           BINARY messenger_account.`funktion`
                       AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                           BINARY messenger_account.`rolle`
                  )
                )
                OR EXISTS (
                  SELECT 1
                    FROM `nv_benutzer_zusatzfunktionen` AS messenger_extra
                   WHERE BINARY messenger_extra.`benutzer_kuerzel` =
                         BINARY messenger_account.`kuerzel`
                     AND BINARY messenger_extra.`funktion` = BINARY 'A/W'
                     AND BINARY messenger_extra.`rolle` = BINARY 'Fernmelder'
                     AND (
                       EXISTS (
                         SELECT 1 FROM `nv_funktionsfaehigkeiten` AS canonical_capability
                          WHERE BINARY canonical_capability.`funktion` =
                                BINARY messenger_extra.`funktion`
                            AND BINARY canonical_capability.`rolle` =
                                BINARY messenger_extra.`rolle`
                       )
                       OR EXISTS (
                         SELECT 1 FROM `nv_empfmtx` AS canonical_matrix
                          WHERE canonical_matrix.`mtx_typ` = 'cb'
                            AND canonical_matrix.`mtx_fkt` <> ''
                            AND BINARY canonical_matrix.`mtx_fkt` =
                                BINARY messenger_extra.`funktion`
                            AND BINARY canonical_matrix.`mtx_rolle` =
                                BINARY messenger_extra.`rolle`
                       )
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM `nv_funktionsfaehigkeiten` AS conflicting_capability
                        WHERE BINARY conflicting_capability.`funktion` =
                              BINARY messenger_extra.`funktion`
                          AND BINARY conflicting_capability.`rolle` <>
                              BINARY messenger_extra.`rolle`
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM `nv_empfmtx` AS conflicting_matrix
                        WHERE conflicting_matrix.`mtx_typ` = 'cb'
                          AND conflicting_matrix.`mtx_fkt` <> ''
                          AND BINARY conflicting_matrix.`mtx_fkt` =
                              BINARY messenger_extra.`funktion`
                          AND BINARY conflicting_matrix.`mtx_rolle` <>
                              BINARY messenger_extra.`rolle`
                     )
                )
              )
              AND (
                (
                  BINARY supervisor_account.`funktion` = BINARY 'LdF'
                  AND BINARY supervisor_account.`rolle` =
                      BINARY 'Fernmelder'
                  AND (
                    EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten`
                             AS primary_capability
                       WHERE BINARY primary_capability.`funktion` =
                             BINARY supervisor_account.`funktion`
                         AND BINARY primary_capability.`rolle` =
                             BINARY supervisor_account.`rolle`
                    )
                    OR EXISTS (
                      SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                       WHERE primary_matrix.`mtx_typ` = 'cb'
                         AND primary_matrix.`mtx_fkt` <> ''
                         AND BINARY primary_matrix.`mtx_fkt` =
                             BINARY supervisor_account.`funktion`
                         AND BINARY primary_matrix.`mtx_rolle` =
                             BINARY supervisor_account.`rolle`
                    )
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_funktionsfaehigkeiten`
                           AS primary_conflicting_capability
                     WHERE BINARY primary_conflicting_capability.`funktion` =
                           BINARY supervisor_account.`funktion`
                       AND BINARY primary_conflicting_capability.`rolle` <>
                           BINARY supervisor_account.`rolle`
                  )
                  AND NOT EXISTS (
                    SELECT 1
                      FROM `nv_empfmtx` AS primary_conflicting_matrix
                     WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                       AND primary_conflicting_matrix.`mtx_fkt` <> ''
                       AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                           BINARY supervisor_account.`funktion`
                       AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                           BINARY supervisor_account.`rolle`
                  )
                )
                OR EXISTS (
                  SELECT 1
                    FROM `nv_benutzer_zusatzfunktionen` AS supervisor_extra
                   WHERE BINARY supervisor_extra.`benutzer_kuerzel` =
                         BINARY supervisor_account.`kuerzel`
                     AND BINARY supervisor_extra.`funktion` = BINARY 'LdF'
                     AND BINARY supervisor_extra.`rolle` =
                         BINARY 'Fernmelder'
                     AND (
                       EXISTS (
                         SELECT 1 FROM `nv_funktionsfaehigkeiten` AS canonical_capability
                          WHERE BINARY canonical_capability.`funktion` =
                                BINARY supervisor_extra.`funktion`
                            AND BINARY canonical_capability.`rolle` =
                                BINARY supervisor_extra.`rolle`
                       )
                       OR EXISTS (
                         SELECT 1 FROM `nv_empfmtx` AS canonical_matrix
                          WHERE canonical_matrix.`mtx_typ` = 'cb'
                            AND canonical_matrix.`mtx_fkt` <> ''
                            AND BINARY canonical_matrix.`mtx_fkt` =
                                BINARY supervisor_extra.`funktion`
                            AND BINARY canonical_matrix.`mtx_rolle` =
                                BINARY supervisor_extra.`rolle`
                       )
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM `nv_funktionsfaehigkeiten` AS conflicting_capability
                        WHERE BINARY conflicting_capability.`funktion` =
                              BINARY supervisor_extra.`funktion`
                          AND BINARY conflicting_capability.`rolle` <>
                              BINARY supervisor_extra.`rolle`
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM `nv_empfmtx` AS conflicting_matrix
                        WHERE conflicting_matrix.`mtx_typ` = 'cb'
                          AND conflicting_matrix.`mtx_fkt` <> ''
                          AND BINARY conflicting_matrix.`mtx_fkt` =
                              BINARY supervisor_extra.`funktion`
                          AND BINARY conflicting_matrix.`mtx_rolle` <>
                              BINARY supervisor_extra.`rolle`
                     )
                )
              )
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
  DECLARE permission_mode VARCHAR(6) DEFAULT NULL;
  DECLARE access_memberships INTEGER DEFAULT 0;
  DECLARE enabled_access_memberships INTEGER DEFAULT 0;

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
    SELECT incident.`estab_permission_mode` INTO permission_mode
      FROM `nv_einsatz_status` AS active_incident
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
     WHERE active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = OLD.`einsatz_id`;
    IF BINARY permission_mode = BINARY 'LOOSE' THEN
      SELECT COUNT(*), COALESCE(SUM(
               CASE WHEN access_shift.`zugang_aktiv` = 1 THEN 1 ELSE 0 END
             ), 0)
        INTO access_memberships, enabled_access_memberships
        FROM `nv_zugangsschicht_mitglieder` AS access_membership
        JOIN `nv_zugangsschichten` AS access_shift
          ON access_shift.`zugangsschicht_id` =
             access_membership.`zugangsschicht_id`
       WHERE access_shift.`einsatz_id` = OLD.`einsatz_id`
         AND BINARY access_membership.`benutzer_kuerzel` =
             BINARY NEW.`gemeldet_an`
         AND access_membership.`entfernt_am` IS NULL
       FOR UPDATE;
      IF access_memberships > 0 AND enabled_access_memberships = 0 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Messenger report access shift is inactive';
      END IF;
    END IF;
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
              (
                BINARY incident.`estab_permission_mode` = BINARY 'STRICT'
                AND @estab_dv_actor_assignment_id IS NOT NULL
                AND @estab_dv_target_assignment_id IS NULL
                AND EXISTS (
                  SELECT 1
                    FROM `nv_dienstbesetzungen` AS report_assignment
                    JOIN `nv_dienstschichten` AS report_shift
                      ON report_shift.`dienstschicht_id` =
                         report_assignment.`dienstschicht_id`
                   WHERE report_assignment.`dienstbesetzung_id` =
                         @estab_dv_actor_assignment_id
                     AND report_shift.`einsatz_id` = OLD.`einsatz_id`
                     AND BINARY report_shift.`status` = BINARY 'AKTIV'
                     AND BINARY report_assignment.`status` =
                         BINARY 'ANGENOMMEN'
                     AND BINARY report_assignment.`benutzer_kuerzel` =
                         BINARY report_account.`kuerzel`
                     AND BINARY report_assignment.`funktion` = BINARY 'LdF'
                     AND BINARY report_assignment.`rolle` =
                         BINARY 'Fernmelder'
                )
              )
              OR (
                BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
                AND @estab_dv_actor_assignment_id IS NULL
                AND @estab_dv_target_assignment_id IS NULL
                AND (
                  (
                    BINARY report_account.`funktion` = BINARY 'LdF'
                    AND BINARY report_account.`rolle` = BINARY 'Fernmelder'
                    AND (
                      EXISTS (
                        SELECT 1
                          FROM `nv_funktionsfaehigkeiten`
                               AS primary_capability
                         WHERE BINARY primary_capability.`funktion` =
                               BINARY report_account.`funktion`
                           AND BINARY primary_capability.`rolle` =
                               BINARY report_account.`rolle`
                      )
                      OR EXISTS (
                        SELECT 1 FROM `nv_empfmtx` AS primary_matrix
                         WHERE primary_matrix.`mtx_typ` = 'cb'
                           AND primary_matrix.`mtx_fkt` <> ''
                           AND BINARY primary_matrix.`mtx_fkt` =
                               BINARY report_account.`funktion`
                           AND BINARY primary_matrix.`mtx_rolle` =
                               BINARY report_account.`rolle`
                      )
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_funktionsfaehigkeiten`
                             AS primary_conflicting_capability
                       WHERE BINARY primary_conflicting_capability.`funktion` =
                             BINARY report_account.`funktion`
                         AND BINARY primary_conflicting_capability.`rolle` <>
                             BINARY report_account.`rolle`
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM `nv_empfmtx` AS primary_conflicting_matrix
                       WHERE primary_conflicting_matrix.`mtx_typ` = 'cb'
                         AND primary_conflicting_matrix.`mtx_fkt` <> ''
                         AND BINARY primary_conflicting_matrix.`mtx_fkt` =
                             BINARY report_account.`funktion`
                         AND BINARY primary_conflicting_matrix.`mtx_rolle` <>
                             BINARY report_account.`rolle`
                    )
                  )
                  OR EXISTS (
                    SELECT 1
                      FROM `nv_benutzer_zusatzfunktionen` AS report_extra
                     WHERE BINARY report_extra.`benutzer_kuerzel` =
                           BINARY report_account.`kuerzel`
                       AND BINARY report_extra.`funktion` = BINARY 'LdF'
                       AND BINARY report_extra.`rolle` =
                           BINARY 'Fernmelder'
                       AND (
                         EXISTS (
                           SELECT 1 FROM `nv_funktionsfaehigkeiten` AS canonical_capability
                            WHERE BINARY canonical_capability.`funktion` =
                                  BINARY report_extra.`funktion`
                              AND BINARY canonical_capability.`rolle` =
                                  BINARY report_extra.`rolle`
                         )
                         OR EXISTS (
                           SELECT 1 FROM `nv_empfmtx` AS canonical_matrix
                            WHERE canonical_matrix.`mtx_typ` = 'cb'
                              AND canonical_matrix.`mtx_fkt` <> ''
                              AND BINARY canonical_matrix.`mtx_fkt` =
                                  BINARY report_extra.`funktion`
                              AND BINARY canonical_matrix.`mtx_rolle` =
                                  BINARY report_extra.`rolle`
                         )
                       )
                       AND NOT EXISTS (
                         SELECT 1 FROM `nv_funktionsfaehigkeiten` AS conflicting_capability
                          WHERE BINARY conflicting_capability.`funktion` =
                                BINARY report_extra.`funktion`
                            AND BINARY conflicting_capability.`rolle` <>
                                BINARY report_extra.`rolle`
                       )
                       AND NOT EXISTS (
                         SELECT 1 FROM `nv_empfmtx` AS conflicting_matrix
                          WHERE conflicting_matrix.`mtx_typ` = 'cb'
                            AND conflicting_matrix.`mtx_fkt` <> ''
                            AND BINARY conflicting_matrix.`mtx_fkt` =
                                BINARY report_extra.`funktion`
                            AND BINARY conflicting_matrix.`mtx_rolle` <>
                                BINARY report_extra.`rolle`
                       )
                  )
                )
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

DROP PROCEDURE IF EXISTS estab_migrate_118_postflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_118_postflight()
READS SQL DATA
SQL SECURITY INVOKER
COMMENT 'estab:migration:118:helper:postflight:v1'
BEGIN
  DECLARE canonical_role_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_role_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND (
       (
         trigger_name = 'estab_etb_bi_einsatz'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_etb'
         AND action_statement LIKE
           '%Manual ETB entry requires an active accepted duty assignment%'
         AND action_statement LIKE '%strict_assignment.%status%%ANGENOMMEN%'
         AND action_statement LIKE '%strict_shift.%status%%AKTIV%'
         AND action_statement LIKE '%nv_benutzer_zusatzfunktionen%'
         AND action_statement LIKE '%canonical_capability%'
         AND action_statement LIKE '%conflicting_matrix%'
         AND action_statement LIKE '%primary_conflicting_matrix%'
         AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
         AND action_statement LIKE '%FOR UPDATE%'
         AND action_statement LIKE
           '%estab_logbook_system_write_incident_id%'
         AND action_statement LIKE '%estab_logbook_system_write_book%'
       )
       OR (
         trigger_name = 'estab_tbb_bi_einsatz'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_tbb'
         AND action_statement LIKE
           '%Manual TTB entry requires an active accepted duty assignment%'
         AND action_statement LIKE '%strict_assignment.%status%%ANGENOMMEN%'
         AND action_statement LIKE '%strict_shift.%status%%AKTIV%'
         AND action_statement LIKE '%nv_benutzer_zusatzfunktionen%'
         AND action_statement LIKE '%canonical_capability%'
         AND action_statement LIKE '%conflicting_matrix%'
         AND action_statement LIKE '%primary_conflicting_matrix%'
         AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
         AND action_statement LIKE '%FOR UPDATE%'
         AND action_statement LIKE
           '%estab_logbook_system_write_incident_id%'
         AND action_statement LIKE '%estab_logbook_system_write_book%'
       )
       OR (
         trigger_name = 'estab_dv94_fernmeldeplan_insert'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_fernmeldeplaene'
         AND action_statement LIKE '%creator_assignment%'
         AND action_statement LIKE '%creator_shift%'
         AND action_statement LIKE '%creator_extra%'
         AND action_statement LIKE '%canonical_capability%'
         AND action_statement LIKE '%conflicting_matrix%'
         AND action_statement LIKE '%primary_conflicting_matrix%'
         AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
         AND action_statement LIKE '%FOR UPDATE%'
         AND action_statement LIKE '%estab_dv_actor_assignment_id%'
         AND action_statement LIKE '%estab_dv_target_assignment_id%'
       )
       OR (
         trigger_name = 'estab_dv94_fernmeldeplan_immutable'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND event_object_table = 'nv_fernmeldeplaene'
         AND action_statement LIKE '%release_assignment%'
         AND action_statement LIKE '%release_shift%'
         AND action_statement LIKE '%release_extra%'
         AND action_statement LIKE '%canonical_capability%'
         AND action_statement LIKE '%conflicting_matrix%'
         AND action_statement LIKE '%primary_conflicting_matrix%'
         AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
         AND action_statement LIKE '%FOR UPDATE%'
         AND action_statement LIKE '%estab_dv_actor_assignment_id%'
         AND action_statement LIKE '%estab_dv_target_assignment_id%'
         AND action_statement LIKE
           '%Discarded telecommunications drafts are immutable evidence%'
         AND action_statement LIKE '%CURRENT_TIMESTAMP%'
       )
       OR (
         trigger_name = 'estab_dv94_messenger_insert'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'INSERT'
         AND event_object_table = 'nv_melderauftraege'
         AND action_statement LIKE '%messenger_assignment%'
         AND action_statement LIKE '%supervisor_assignment%'
         AND action_statement LIKE '%messenger_extra%'
         AND action_statement LIKE '%supervisor_extra%'
         AND action_statement LIKE '%canonical_capability%'
         AND action_statement LIKE '%conflicting_matrix%'
         AND action_statement LIKE '%primary_conflicting_matrix%'
         AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
         AND action_statement LIKE '%FOR UPDATE%'
         AND action_statement LIKE '%estab_dv_actor_assignment_id%'
         AND action_statement LIKE '%estab_dv_target_assignment_id%'
       )
       OR (
         trigger_name = 'estab_dv94_messenger_update'
         AND action_timing = 'BEFORE'
         AND event_manipulation = 'UPDATE'
         AND event_object_table = 'nv_melderauftraege'
         AND action_statement LIKE '%report_assignment%'
         AND action_statement LIKE '%report_shift%'
         AND action_statement LIKE '%report_extra%'
         AND action_statement LIKE '%canonical_capability%'
         AND action_statement LIKE '%conflicting_matrix%'
         AND action_statement LIKE '%primary_conflicting_matrix%'
         AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
         AND action_statement LIKE '%FOR UPDATE%'
         AND action_statement LIKE '%estab_dv_actor_assignment_id%'
         AND action_statement LIKE '%estab_dv_target_assignment_id%'
         AND action_statement LIKE '%Invalid messenger status transition%'
       )
     );
  IF canonical_role_triggers <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Operational-authority migration failed: role trigger mismatch';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_118_postflight();
DROP PROCEDURE estab_migrate_118_postflight;
