-- The extensions inside our own command post.
--
-- Fb Fü 77 draws them in the middle of the sketch, in the box under the
-- command post: a table with "Technik", "NSt-Nr." and "Teilnehmer", one row
-- per line of the internal exchange -- C Wähl, Mobilfunk, ISDN S0, Analog a/b.
-- They are not counterparts and not routes: nobody outside is reached over
-- them. They say who inside the command post sits behind which extension, and
-- that is exactly what somebody standing in front of the sketch needs when
-- the Lagedienst has to be reached and only the room is known.
--
-- They hang on the PLAN, not on a route.
--
-- A route is one means with one reachability; an extension belongs to the
-- exchange as a whole and would otherwise have to be hung on an arbitrary
-- route. Fb Fü 77 draws the table beside the equipment, not under one of the
-- devices.
--
-- Same immutability as everything else in a released plan: an extension may
-- only change while its plan is a draft of the currently active, open
-- incident. Written out again rather than reused, because a released
-- migration is checksum-bound and must not be touched.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '130-komplan-abbau.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Extension migration blocked: predecessor ledger is missing';
  END IF;
END//
DELIMITER ;

CREATE TABLE IF NOT EXISTS `nv_fernmeldeplan_nebenstellen` (
  `nebenstelle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fernmeldeplan_id` BIGINT UNSIGNED NOT NULL,
  `sortierung` INT UNSIGNED NOT NULL,
  `technik` ENUM(
    'WAEHL','MOBILFUNK','ISDN','ANALOG','IP','SONDER'
  ) NOT NULL,
  `nummer` VARCHAR(40) NOT NULL,
  `teilnehmer` VARCHAR(255) NOT NULL,
  `bemerkungen` TEXT NULL,
  PRIMARY KEY (`nebenstelle_id`),
  UNIQUE KEY `uq_nebenstelle_sortierung`
    (`fernmeldeplan_id`, `sortierung`),
  KEY `idx_nebenstelle_nummer` (`fernmeldeplan_id`, `nummer`),
  CONSTRAINT `fk_nebenstelle_plan`
    FOREIGN KEY (`fernmeldeplan_id`)
      REFERENCES `nv_fernmeldeplaene` (`fernmeldeplan_id`)
      ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:131-fernmeldeplan-nebenstellen:v1';

-- Eine Nebenstelle ist nur im Entwurf des aktiven, offenen Einsatzes
-- veraenderlich -- wie alles andere an einem freigegebenen Plan.
DELIMITER //
CREATE OR REPLACE TRIGGER `estab_dv131_nebenstelle_insert`
BEFORE INSERT ON `nv_fernmeldeplan_nebenstellen`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM `nv_fernmeldeplaene` AS plan
      JOIN `nv_einsatz_status` AS active_incident
        ON active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = plan.`einsatz_id`
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = plan.`einsatz_id`
     WHERE plan.`fernmeldeplan_id` = NEW.`fernmeldeplan_id`
       AND plan.`status` = 'ENTWURF'
       AND incident.`estab_status` = 'open'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Activated telecommunications extensions are immutable';
  END IF;
END//

CREATE OR REPLACE TRIGGER `estab_dv131_nebenstelle_update`
BEFORE UPDATE ON `nv_fernmeldeplan_nebenstellen`
FOR EACH ROW
BEGIN
  IF NEW.`nebenstelle_id` <> OLD.`nebenstelle_id`
     OR NEW.`fernmeldeplan_id` <> OLD.`fernmeldeplan_id`
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_fernmeldeplaene` AS plan
         JOIN `nv_einsatz_status` AS active_incident
           ON active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = plan.`einsatz_id`
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = plan.`einsatz_id`
        WHERE plan.`fernmeldeplan_id` = OLD.`fernmeldeplan_id`
          AND plan.`status` = 'ENTWURF'
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Activated telecommunications extensions are immutable';
  END IF;
END//

CREATE OR REPLACE TRIGGER `estab_dv131_nebenstelle_delete`
BEFORE DELETE ON `nv_fernmeldeplan_nebenstellen`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM `nv_fernmeldeplaene` AS plan
      JOIN `nv_einsatz_status` AS active_incident
        ON active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = plan.`einsatz_id`
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = plan.`einsatz_id`
     WHERE plan.`fernmeldeplan_id` = OLD.`fernmeldeplan_id`
       AND plan.`status` = 'ENTWURF'
       AND incident.`estab_status` = 'open'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Activated telecommunications extensions are immutable';
  END IF;
END//
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE extension_table INTEGER DEFAULT 0;
  DECLARE extension_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO extension_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_nebenstellen'
     AND engine = 'InnoDB'
     AND table_comment = 'estab:migration:131-fernmeldeplan-nebenstellen:v1';
  IF extension_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Extension migration failed: the table is missing';
  END IF;

  SELECT COUNT(*) INTO extension_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_dv131_nebenstelle_insert',
       'estab_dv131_nebenstelle_update',
       'estab_dv131_nebenstelle_delete'
     );
  IF extension_triggers <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Extension migration failed: an extension may change outside a draft';
  END IF;
END//
DELIMITER ;
