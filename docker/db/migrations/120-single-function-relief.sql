-- Let one duty function be re-staffed while its shift keeps running. DV 1-101
-- expects a command post to stay able to work when a single person drops out,
-- and relieving one station is not a shift handover. The insert trigger of
-- migration 94 rejected every function that had ever been assigned in an
-- active shift, without looking at the status of that earlier assignment, so
-- a relieved station could not be filled again. Single occupancy stays
-- enforced by the generated column `aktive_funktion` and its unique key.
-- Migrations 94 and 110 are released and checksum-immutable.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CREATE OR REPLACE is one server-side DDL boundary. A retry can therefore
-- encounter either migration 110's exact predecessor or this migration's
-- exact successor, but never a missing or unrelated trigger.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE named_triggers INTEGER DEFAULT 0;
  DECLARE compatible_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '119-inactive-messenger-dispatch.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Single-function relief migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO named_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_dv94_hat_insert';

  SELECT COUNT(*) INTO compatible_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_dv94_hat_insert'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'INSERT'
     AND event_object_table = 'nv_dienstbesetzungen'
     AND action_statement LIKE '%Invalid duty assignment insert evidence%'
     AND action_statement LIKE
       '%Active duty shift function was already assigned%'
     AND action_statement LIKE '%FOR UPDATE%'
     AND action_statement LIKE '%previous_assignment_id%'
     AND (
       (
         action_statement NOT LIKE '%relieved_predecessor_ignored%'
         AND action_statement NOT LIKE
           '%existing_assignment.`status` IN%'
       )
       OR (
         action_statement LIKE '%relieved_predecessor_ignored%'
         AND action_statement LIKE
           '%existing_assignment.`status` IN%'
       )
     );
  IF named_triggers <> compatible_triggers OR compatible_triggers <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Single-function relief migration blocked: trigger collision';
  END IF;
END//
DELIMITER ;

DELIMITER //
CREATE OR REPLACE TRIGGER `estab_dv94_hat_insert`
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
    -- relieved_predecessor_ignored: a station whose holder was relieved or
    -- whose assignment was declined is free again. Only a currently assigned
    -- or accepted row still occupies the function, exactly as the generated
    -- column `aktive_funktion` and its unique key define occupancy.
    SELECT existing_assignment.`dienstbesetzung_id`
      INTO previous_assignment_id
      FROM `nv_dienstbesetzungen` AS existing_assignment
     WHERE existing_assignment.`dienstschicht_id` = NEW.`dienstschicht_id`
       AND BINARY existing_assignment.`funktion` = BINARY NEW.`funktion`
       AND existing_assignment.`status` IN ('ZUGEWIESEN','ANGENOMMEN')
     ORDER BY existing_assignment.`dienstbesetzung_id`
     LIMIT 1;
    IF previous_assignment_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Active duty shift function was already assigned';
    END IF;
  END IF;
END//
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE canonical_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_dv94_hat_insert'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'INSERT'
     AND event_object_table = 'nv_dienstbesetzungen'
     AND action_statement LIKE '%relieved_predecessor_ignored%'
     AND action_statement LIKE '%existing_assignment.`status` IN%'
     AND action_statement LIKE '%Invalid duty assignment insert evidence%'
     AND action_statement LIKE
       '%Active duty shift function was already assigned%'
     AND action_statement LIKE '%FOR UPDATE%';
  IF canonical_triggers <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Single-function relief migration failed: trigger mismatch';
  END IF;
END//
DELIMITER ;
