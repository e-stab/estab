-- Move the transport disposition from Feld 7 to Feld 1.
--
-- Feld 7 (`06_befwegausw`) carries the wish of the author and nothing else
-- from now on. The LdF states the disposed means in Feld 1 (`01_medium`) and
-- the way in Feld 6 (`06_befweg`). The three boundary triggers of migrations
-- 94 and 119 still compared the S6 route and the messenger dispatch against
-- Feld 7, so every plan-based disposition would fail with SQLSTATE 45000 as
-- soon as wish and disposed means differ. They are recreated here byte for
-- byte with that one comparison moved to Feld 1.
--
-- Migrations 94 and 119 are released and checksum-immutable, so their three
-- triggers are recreated here byte for byte with the one comparison moved.
--
-- Two things must happen to the data before that, and both are done below.
-- Feld 1 does not exist at all on a database grown from the historic runtime
-- schema: no migration ever added it, it only ever came with a fresh
-- baseline. Without the column the triggers below cannot even be created.
-- And where the column does exist, outgoing messages do not carry the
-- disposed means in it: until this migration the LdF wrote that means to
-- Feld 7 and left Feld 1 empty. Rows that provably passed the LdF stage
-- therefore receive the value once. Feld 7 keeps it, so nothing is erased.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CREATE OR REPLACE is one server-side DDL boundary. A retry can therefore
-- encounter either the exact predecessor or this migration's exact successor,
-- but never a missing or unrelated trigger.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE named_triggers INTEGER DEFAULT 0;
  DECLARE compatible_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '120-single-function-relief.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Transport disposition migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO named_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_dv94_message_route_insert',
       'estab_dv94_message_route_update',
       'estab_dv94_messenger_insert'
     );

  SELECT COUNT(*) INTO compatible_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_dv94_message_route_insert',
       'estab_dv94_message_route_update',
       'estab_dv94_messenger_insert'
     )
     AND (
       (
         action_statement LIKE '%`06_befwegausw`%'
         AND action_statement NOT LIKE '%`01_medium`%'
       )
       OR (
         action_statement LIKE '%`01_medium`%'
         AND action_statement NOT LIKE '%`06_befwegausw`%'
       )
     );
  IF named_triggers <> 3 OR compatible_triggers <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Transport disposition migration blocked: trigger collision';
  END IF;
END//
DELIMITER ;

-- Feld 1 und Feld 6 nachruesten. Auf einer Neuinstallation bringt das
-- Grundschema beide Spalten mit; eine gewachsene Datenbank hat sie nie
-- bekommen, weil keine Migration sie je ergaenzt hat. Diese Migration ist
-- die erste, die sie braucht: die Trigger unten lesen Feld 1, und der
-- Beforderungsweg steht ab hier in Feld 6.
DROP PROCEDURE IF EXISTS estab_migrate_121_add_medium;
DELIMITER //
CREATE PROCEDURE estab_migrate_121_add_medium()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = '01_medium'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `01_medium` SET('Fe','Fu','Me','FAX','FS','@')
        NOT NULL DEFAULT ''
        COMMENT 'estab:migration:121:transport-disposition:v1'
        AFTER `00_lfd`;
  END IF;
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = '06_befweg'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `06_befweg` VARCHAR(128)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT ''
        COMMENT 'estab:migration:121:transport-route:v1'
        AFTER `05_gegenstelle`;
  END IF;
END//
DELIMITER ;
CALL estab_migrate_121_add_medium();
DROP PROCEDURE estab_migrate_121_add_medium;

-- Der Einsatz-Trigger laesst nur Schreibzugriffe auf den aktiven Einsatz zu
-- und wuerde das einmalige Nachziehen abweisen. Er wird dafuer kurz entfernt
-- und unmittelbar danach wortgleich zu Migration 50 wiederhergestellt.
DROP TRIGGER IF EXISTS `estab_nachrichten_bu_einsatz`;
-- Der Beweis, dass der LdF disponiert hat, ist der Bearbeitungsstand: 2 (in
-- Befoerderung) und 8 (abgeschlossen) entstehen ausschliesslich durch seine
-- Disposition. Auf einer Datenbank, die Feld 6 bereits kennt, genuegt
-- zusaetzlich dessen Inhalt. Ein Entwurf mit blossem Wunsch in Feld 7 bleibt
-- unangetastet: Feld 7 trug bis hierher beides, Wunsch und Disposition.
UPDATE `nv_nachrichten`
   SET `01_medium` = `06_befwegausw`
 WHERE `04_richtung` = 'A'
   AND `01_medium` = ''
   AND `06_befwegausw` <> ''
   AND (`06_befweg` <> '' OR `x00_status` IN (2, 8));

-- Den Befoerderungsweg kennt ein gewachsener Bestand nicht: vor Feld 6 war
-- das Mittel selbst die einzige Aussage ueber den Weg. Genau das wird
-- eingetragen -- der Klarname des Mittels mit sichtbarem Vermerk seiner
-- Herkunft. Es wird nichts erfunden, und wer den Nachweis liest, erkennt,
-- woher die Angabe stammt. Ohne diesen Eintrag faende eine laufende
-- Ausgangsnachricht weder die Fernmelder- noch die LdF-Warteschlange
-- wieder: die Fernmelderstufe verlangt Feld 6, und der Weg zurueck zum LdF
-- verlangt eine noch leere Feld-2-Annahme, die hier laengst steht.
UPDATE `nv_nachrichten`
   SET `06_befweg` = CONCAT(
         CASE `01_medium`
           WHEN 'Fe' THEN 'Fernsprecher'
           WHEN 'Fu' THEN 'Funk'
           WHEN 'Me' THEN 'Melder'
           WHEN 'FAX' THEN 'Telefax'
           WHEN 'FS' THEN 'Fernschreiber'
           WHEN '@' THEN 'Datenuebertragung'
           ELSE `01_medium`
         END,
         ' (aus Feld 7 uebernommen)'
       )
 WHERE `04_richtung` = 'A'
   AND `06_befweg` = ''
   AND `01_medium` <> ''
   AND `x00_status` IN (2, 8);
CREATE TRIGGER `estab_nachrichten_bu_einsatz`
BEFORE UPDATE ON `nv_nachrichten` FOR EACH ROW
SET NEW.`einsatz_id` =
  estab_incident_for_update(OLD.`einsatz_id`, NEW.`einsatz_id`);

DELIMITER //
CREATE OR REPLACE TRIGGER `estab_dv94_message_route_insert`
BEFORE INSERT ON `nv_nachrichten`
FOR EACH ROW
BEGIN
  IF NEW.`estab_fernmeldeplan_eintrag_id` IS NOT NULL
     AND NOT EXISTS (
       SELECT 1
         FROM `nv_fernmeldeplan_eintraege` AS route_entry
         JOIN `nv_fernmeldeplaene` AS plan
           ON plan.`fernmeldeplan_id` = route_entry.`fernmeldeplan_id`
        WHERE route_entry.`fernmeldeplan_eintrag_id`
          = NEW.`estab_fernmeldeplan_eintrag_id`
          AND plan.`einsatz_id` = NEW.`einsatz_id`
          AND plan.`status` = 'AKTIV'
          AND plan.`gueltig_ab` <= NOW()
          AND (plan.`gueltig_bis` IS NULL OR plan.`gueltig_bis` >= NOW())
          AND BINARY route_entry.`medium` = BINARY NEW.`01_medium`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Outgoing route must reference the active S6 plan';
  END IF;
END//
DELIMITER ;

DELIMITER //
CREATE OR REPLACE TRIGGER `estab_dv94_message_route_update`
BEFORE UPDATE ON `nv_nachrichten`
FOR EACH ROW
BEGIN
  IF OLD.`estab_fernmeldeplan_eintrag_id` IS NOT NULL
     AND NOT (
       NEW.`estab_fernmeldeplan_eintrag_id`
         <=> OLD.`estab_fernmeldeplan_eintrag_id`
     ) THEN
    IF NEW.`estab_fernmeldeplan_eintrag_id` IS NULL
       OR NOT (
         OLD.`einsatz_id` = NEW.`einsatz_id`
         AND OLD.`04_richtung` = 'A'
         AND NEW.`04_richtung` = 'A'
         AND OLD.`x00_status` = 1
         AND NEW.`x00_status` = 2
         AND OLD.`02_zeit` IS NULL
         AND OLD.`02_zeichen` = ''
         AND NEW.`02_zeit` IS NOT NULL
         AND NEW.`02_zeichen` <> ''
         AND BINARY NEW.`02_zeichen` = BINARY OLD.`x03_sperruser`
         AND OLD.`03_datum` IS NULL
         AND OLD.`03_zeichen` = ''
         AND NEW.`03_datum` IS NULL
         AND NEW.`03_zeichen` = ''
         AND OLD.`15_quitdatum` IS NOT NULL
         AND OLD.`15_quitzeichen` <> ''
         AND NEW.`15_quitdatum` <=> OLD.`15_quitdatum`
         AND BINARY NEW.`15_quitzeichen`
           = BINARY OLD.`15_quitzeichen`
         AND OLD.`x01_abschluss` = 'f'
         AND NEW.`x01_abschluss` = 'f'
         AND OLD.`x02_sperre` = 't'
         AND OLD.`x03_sperruser` <> ''
         AND NEW.`x02_sperre` = 'f'
         AND NEW.`x03_sperruser` = ''
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'The disposed route can change only in locked LdF redisposition';
    END IF;
    IF NOT EXISTS (
      SELECT 1
        FROM `nv_fernmeldeplan_eintraege` AS replacement_entry
        JOIN `nv_fernmeldeplaene` AS replacement_plan
          ON replacement_plan.`fernmeldeplan_id`
            = replacement_entry.`fernmeldeplan_id`
       WHERE replacement_entry.`fernmeldeplan_eintrag_id`
         = NEW.`estab_fernmeldeplan_eintrag_id`
         AND replacement_plan.`einsatz_id` = NEW.`einsatz_id`
         AND replacement_plan.`status` = 'AKTIV'
         AND replacement_plan.`gueltig_ab` <= NOW()
         AND (
           replacement_plan.`gueltig_bis` IS NULL
           OR replacement_plan.`gueltig_bis` >= NOW()
         )
         AND BINARY replacement_entry.`medium`
           = BINARY NEW.`01_medium`
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Redisposition must reference another active S6 route';
    END IF;
  END IF;
  IF OLD.`estab_fernmeldeplan_eintrag_id` IS NULL
     AND NEW.`estab_fernmeldeplan_eintrag_id` IS NOT NULL
     AND NOT EXISTS (
       SELECT 1
         FROM `nv_fernmeldeplan_eintraege` AS route_entry
         JOIN `nv_fernmeldeplaene` AS plan
           ON plan.`fernmeldeplan_id` = route_entry.`fernmeldeplan_id`
        WHERE route_entry.`fernmeldeplan_eintrag_id`
          = NEW.`estab_fernmeldeplan_eintrag_id`
          AND plan.`einsatz_id` = NEW.`einsatz_id`
          AND plan.`status` = 'AKTIV'
          AND plan.`gueltig_ab` <= NOW()
          AND (plan.`gueltig_bis` IS NULL OR plan.`gueltig_bis` >= NOW())
          AND BINARY route_entry.`medium` = BINARY NEW.`01_medium`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Outgoing route must reference the active S6 plan';
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
          AND message_row.`01_medium` = 'Me'
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


DELIMITER //
BEGIN NOT ATOMIC
  DECLARE canonical_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_dv94_message_route_insert',
       'estab_dv94_message_route_update',
       'estab_dv94_messenger_insert'
     )
     AND action_statement LIKE '%`01_medium`%'
     AND action_statement NOT LIKE '%`06_befwegausw`%';
  IF canonical_triggers <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Transport disposition migration failed: trigger mismatch';
  END IF;

  SELECT COUNT(*) INTO canonical_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_dv94_messenger_insert'
     AND action_statement LIKE '%inactive_messenger_target_allowed%'
     AND action_statement LIKE '%nv_zugangsschicht_mitglieder%'
     AND action_statement LIKE '%estab_dv_actor_assignment_id%'
     AND action_statement LIKE '%estab_dv_target_assignment_id%'
     AND action_statement LIKE '%FOR UPDATE%';
  IF canonical_triggers <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Transport disposition migration failed: messenger guard lost';
  END IF;
END//
DELIMITER ;
