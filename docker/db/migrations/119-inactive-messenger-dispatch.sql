-- Permit an authorised, unblocked Fernmelder to be selected for a messenger
-- assignment while their browser-presence flag is inactive. The assigning
-- LdF remains presence-bound. STRICT duty assignments and LOOSE function and
-- optional access-shift gates remain unchanged. Migration 118 is immutable.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CREATE OR REPLACE is one server-side DDL boundary. A retry can therefore
-- encounter either migration 118's exact predecessor or this migration's
-- exact successor, but never needs to accept a missing or unrelated trigger.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE named_triggers INTEGER DEFAULT 0;
  DECLARE compatible_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '118-operational-authority.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Inactive messenger migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO named_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_dv94_messenger_insert';

  SELECT COUNT(*) INTO compatible_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_dv94_messenger_insert'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'INSERT'
     AND event_object_table = 'nv_melderauftraege'
     AND action_statement LIKE
       '%Messenger assignment account functions are invalid%'
     AND action_statement LIKE '%messenger_assignment%'
     AND action_statement LIKE '%supervisor_assignment%'
     AND action_statement LIKE '%messenger_extra%'
     AND action_statement LIKE '%supervisor_extra%'
     AND action_statement LIKE '%estab_permission_mode%'
     AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
     AND action_statement LIKE '%FOR UPDATE%'
     AND action_statement LIKE '%estab_dv_actor_assignment_id%'
     AND action_statement LIKE '%estab_dv_target_assignment_id%'
     AND action_statement LIKE
       '%messenger_account.`estab_gesperrt` = 0%'
     AND action_statement LIKE '%supervisor_account.`aktiv` = 1%'
     AND action_statement LIKE
       '%supervisor_account.`estab_gesperrt` = 0%'
     AND (
       (
         action_statement LIKE '%messenger_account.`aktiv` = 1%'
         AND action_statement NOT LIKE '%inactive_messenger_target_allowed%'
       )
       OR (
         action_statement LIKE '%inactive_messenger_target_allowed%'
         AND action_statement NOT LIKE
           '%messenger_account.`aktiv` = 1%'
       )
     );
  IF named_triggers <> compatible_triggers OR compatible_triggers <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Inactive messenger migration blocked: trigger collision';
  END IF;
END//
DELIMITER ;

DELIMITER //
CREATE OR REPLACE TRIGGER `estab_dv94_messenger_insert`
BEFORE INSERT ON `nv_melderauftraege`
FOR EACH ROW
BEGIN
  DECLARE permission_mode VARCHAR(6) DEFAULT NULL;
  DECLARE messenger_access_memberships INTEGER DEFAULT 0;
  DECLARE messenger_enabled_access INTEGER DEFAULT 0;
  DECLARE supervisor_access_memberships INTEGER DEFAULT 0;
  DECLARE supervisor_enabled_access INTEGER DEFAULT 0;
  DECLARE inactive_messenger_target_allowed TINYINT UNSIGNED DEFAULT 1;

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
          AND inactive_messenger_target_allowed = 1
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

-- Refuse to acknowledge the migration unless the final trigger makes the
-- intended asymmetry explicit: the target may be absent, the LdF may not.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE canonical_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_dv94_messenger_insert'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'INSERT'
     AND event_object_table = 'nv_melderauftraege'
     AND action_statement LIKE '%inactive_messenger_target_allowed%'
     AND action_statement NOT LIKE '%messenger_account.`aktiv` = 1%'
     AND action_statement LIKE
       '%messenger_account.`estab_gesperrt` = 0%'
     AND action_statement LIKE '%supervisor_account.`aktiv` = 1%'
     AND action_statement LIKE
       '%supervisor_account.`estab_gesperrt` = 0%'
     AND action_statement LIKE '%messenger_assignment%'
     AND action_statement LIKE '%messenger_shift%'
     AND action_statement LIKE '%supervisor_assignment%'
     AND action_statement LIKE '%supervisor_shift%'
     AND action_statement LIKE '%messenger_extra%'
     AND action_statement LIKE '%supervisor_extra%'
     AND action_statement LIKE '%estab_permission_mode%'
     AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
     AND action_statement LIKE '%FOR UPDATE%'
     AND action_statement LIKE '%estab_dv_actor_assignment_id%'
     AND action_statement LIKE '%estab_dv_target_assignment_id%';
  IF canonical_triggers <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Inactive messenger migration failed: trigger mismatch';
  END IF;
END//
DELIMITER ;
