-- The TBB is kept by the LdF, and the trigger now says so too.
--
-- Two layers disagreed, and the disagreement made manual TBB entries
-- impossible for everybody:
--
--   * `app/logbook.php` demands the capability FERNMELDEBETRIEB, which
--     migration 94 gives to `LdF` / `Fernmelder` and to nobody else.
--   * This trigger, unchanged since migration 112, demanded that the writing
--     account be `A/W` / `Fernmelder`.
--
-- No account can be both. Every manual TBB entry failed with "TTB writer
-- account function or status is invalid" -- in the operating seed, and in the
-- application. It went unnoticed because the seed aborted earlier, on the
-- capability check, and the earlier abort hid the later one.
--
-- The operator decided which side is right: the Leiter des Fernmeldebetriebes
-- keeps the Technische Betriebsbuch. The application stays as it is; the
-- trigger gives way.
--
-- Recreated in full rather than patched, because a trigger has no ALTER: the
-- body below is the one from migration 118 with `A/W` replaced by `LdF` in
-- the five places that name the WRITING function. Nothing else is touched --
-- not the entry types, not the duty provenance, not the book numbering, not
-- the system-write path. A released migration is checksum-bound and must not
-- be edited, so the whole body is repeated here; that is the price of the
-- rule that keeps applied migrations honest.
--
-- Existing rows written by an A/W are NOT touched. They stay as they are and
-- keep saying who wrote them; the trigger fires on new rows.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '131-fernmeldeplan-nebenstellen.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'TTB writer migration blocked: predecessor ledger is missing';
  END IF;
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
                AND BINARY strict_assignment.`funktion` = BINARY 'LdF'
                AND BINARY strict_assignment.`rolle` =
                    BINARY 'Fernmelder'
           )
         )
         OR (
           BINARY permission_mode = BINARY 'LOOSE'
           AND (
             (
               BINARY account.`funktion` = BINARY NEW.`tbb_funktion`
               AND BINARY account.`funktion` = BINARY 'LdF'
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
                  AND BINARY extra_function.`funktion` = BINARY 'LdF'
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
             AND BINARY assignment.`funktion` = BINARY 'LdF'
             AND BINARY assignment.`rolle` = BINARY 'Fernmelder'
           )
           OR (
             BINARY permission_mode = BINARY 'LOOSE'
             AND BINARY assignment.`funktion` = BINARY 'LdF'
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

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE writer_rule INTEGER DEFAULT 0;
  DECLARE alter_rule INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO writer_rule
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_tbb_bi_einsatz'
     AND action_timing = 'BEFORE'
     AND event_manipulation = 'INSERT'
     AND action_statement LIKE '%BINARY ''LdF''%';
  IF writer_rule <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'TTB writer migration failed: the trigger does not accept the LdF';
  END IF;

  -- Und die alte Regel ist wirklich weg, nicht nur ergaenzt.
  SELECT COUNT(*) INTO alter_rule
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name = 'estab_tbb_bi_einsatz'
     AND action_statement LIKE '%BINARY ''A/W''%';
  IF alter_rule <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'TTB writer migration failed: the trigger still names the A/W';
  END IF;
END//
DELIMITER ;
