-- Say who is reachable over a route, and under which address.
--
-- The plan carries the reachability of the own command post -- that decision
-- came first, and it stands. A counterpart never appears on its own: it hangs
-- on exactly one route, and it inherits that route's medium. The statement is
-- "over THIS way, THAT station answers at THIS address", which is what an
-- address book cannot say and what the message form needs.
--
-- The counterpart of a single message stays in the form, in Feld 6 and 11 and
-- 15. The plan supplies the suggestion; it does not replace the field, and it
-- never rejects a station the plan does not list. A counterpart that is not
-- planned still calls.
--
-- Deliberately not normalised across routes. The same station appears once per
-- route it is reachable on, and that is the regular case, not a duplicate: a
-- shared master record could not be versioned, because a plan is immutable
-- from ACTIV onwards while a shared record would not be. The copy per version
-- is the price for a released version still saying the same thing years later.
--
-- The immutability triggers mirror those of migration 94 for plan entries: a
-- counterpart may only change while its plan is a draft of the currently
-- active, open incident. They are written here rather than reused because a
-- released migration is checksum-bound and must not be touched.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '125-fernmeldeweg-rueckfallebene.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Counterpart migration blocked: predecessor ledger is missing';
  END IF;
END//
DELIMITER ;

CREATE TABLE IF NOT EXISTS `nv_fernmeldeplan_gegenstellen` (
  `gegenstelle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fernmeldeplan_eintrag_id` BIGINT UNSIGNED NOT NULL,
  `sortierung` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `erreichbarkeit` VARCHAR(255) NOT NULL,
  `bemerkungen` TEXT NULL,
  PRIMARY KEY (`gegenstelle_id`),
  UNIQUE KEY `uq_gegenstelle_sortierung`
    (`fernmeldeplan_eintrag_id`, `sortierung`),
  KEY `idx_gegenstelle_name` (`fernmeldeplan_eintrag_id`, `name`),
  CONSTRAINT `fk_gegenstelle_eintrag`
    FOREIGN KEY (`fernmeldeplan_eintrag_id`)
      REFERENCES `nv_fernmeldeplan_eintraege` (`fernmeldeplan_eintrag_id`)
      ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:126-fernmeldeplan-gegenstellen:v1';

-- Eine Gegenstelle ist nur im Entwurf des aktiven, offenen Einsatzes
-- veraenderlich -- wie der Weg, an dem sie haengt.
DELIMITER //
CREATE OR REPLACE TRIGGER `estab_dv126_gegenstelle_insert`
BEFORE INSERT ON `nv_fernmeldeplan_gegenstellen`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM `nv_fernmeldeplan_eintraege` AS entry
      JOIN `nv_fernmeldeplaene` AS plan
        ON plan.`fernmeldeplan_id` = entry.`fernmeldeplan_id`
      JOIN `nv_einsatz_status` AS active_incident
        ON active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = plan.`einsatz_id`
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = plan.`einsatz_id`
     WHERE entry.`fernmeldeplan_eintrag_id`
           = NEW.`fernmeldeplan_eintrag_id`
       AND plan.`status` = 'ENTWURF'
       AND incident.`estab_status` = 'open'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Activated telecommunications counterparts are immutable';
  END IF;
END//

CREATE OR REPLACE TRIGGER `estab_dv126_gegenstelle_update`
BEFORE UPDATE ON `nv_fernmeldeplan_gegenstellen`
FOR EACH ROW
BEGIN
  IF NEW.`gegenstelle_id` <> OLD.`gegenstelle_id`
     OR NEW.`fernmeldeplan_eintrag_id` <> OLD.`fernmeldeplan_eintrag_id`
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_fernmeldeplan_eintraege` AS entry
         JOIN `nv_fernmeldeplaene` AS plan
           ON plan.`fernmeldeplan_id` = entry.`fernmeldeplan_id`
         JOIN `nv_einsatz_status` AS active_incident
           ON active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = plan.`einsatz_id`
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = plan.`einsatz_id`
        WHERE entry.`fernmeldeplan_eintrag_id`
              = OLD.`fernmeldeplan_eintrag_id`
          AND plan.`status` = 'ENTWURF'
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Activated telecommunications counterparts are immutable';
  END IF;
END//

CREATE OR REPLACE TRIGGER `estab_dv126_gegenstelle_delete`
BEFORE DELETE ON `nv_fernmeldeplan_gegenstellen`
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM `nv_fernmeldeplan_eintraege` AS entry
      JOIN `nv_fernmeldeplaene` AS plan
        ON plan.`fernmeldeplan_id` = entry.`fernmeldeplan_id`
      JOIN `nv_einsatz_status` AS active_incident
        ON active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = plan.`einsatz_id`
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = plan.`einsatz_id`
     WHERE entry.`fernmeldeplan_eintrag_id`
           = OLD.`fernmeldeplan_eintrag_id`
       AND plan.`status` = 'ENTWURF'
       AND incident.`estab_status` = 'open'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Activated telecommunications counterparts are immutable';
  END IF;
END//
DELIMITER ;

DELIMITER //
BEGIN NOT ATOMIC
  DECLARE counterpart_table INTEGER DEFAULT 0;
  DECLARE counterpart_triggers INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO counterpart_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_fernmeldeplan_gegenstellen'
     AND engine = 'InnoDB'
     AND table_comment = 'estab:migration:126-fernmeldeplan-gegenstellen:v1';
  IF counterpart_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Counterpart migration failed: the table is missing';
  END IF;

  SELECT COUNT(*) INTO counterpart_triggers
    FROM information_schema.triggers
   WHERE trigger_schema = DATABASE()
     AND trigger_name IN (
       'estab_dv126_gegenstelle_insert',
       'estab_dv126_gegenstelle_update',
       'estab_dv126_gegenstelle_delete'
     );
  IF counterpart_triggers <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Counterpart migration failed: a counterpart may change outside a draft';
  END IF;
END//
DELIMITER ;
