-- Give every telecommunications route a durable identity of its own.
--
-- Until now a route was a row in one plan version and nothing else. The
-- version copy in app/dv_operations.php re-inserts every route, so each
-- version hands out fresh `fernmeldeplan_eintrag_id` values: "the same radio
-- route as in version 3" was not a question this schema could answer, and a
-- route could not point at another route across a version boundary.
--
-- The identity therefore becomes an object of its own. `nv_fernmeldewege`
-- hands out the identity, `nv_fernmeldeweg_zuordnung` says which row of which
-- plan version carries it. `weg_nummer` is the human-facing counterpart:
-- allocated per incident, never reused, so a plan can print "Weg 3".
--
-- The mapping deliberately lives beside the routes instead of inside them.
-- A column on `nv_fernmeldeplan_eintraege` would have to be filled for every
-- existing row, and `estab_dv94_fernmeldeplan_entry_update` refuses exactly
-- that: a route may only change while its plan is a draft of the currently
-- active, open incident. Every row of a released or superseded plan is
-- sealed. Rather than replacing that trigger for the duration of a
-- migration, this migration only ever READS the protected inventory and
-- writes into two new tables. The immutability promise stays literally true
-- instead of merely true in spirit.
--
-- No attempt is made to link equal routes across versions. The copy preserved
-- `sortierung`, so chaining by it looks correct -- and is not: deleting the
-- last route of a draft and adding a new one hands out the same number for a
-- different route, because `MAX + 1` is computed per plan version. Chaining
-- would silently merge two different routes into one identity. Inventing an
-- identity that was never recorded is worse than having none, so every
-- existing row receives its own.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- The ledger of the predecessor proves that migration 94 and everything after
-- it really ran on this database. Without that proof the tables this
-- migration reads may not exist at all.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE predecessor_ledger_rows INTEGER DEFAULT 0;
  DECLARE required_tables INTEGER DEFAULT 0;
  DECLARE required_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO predecessor_ledger_rows
    FROM `estab_schema_migrations`
   WHERE `version` = '121-transport-disposition-field-one.sql'
     AND `state` = 'applied'
     AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$';
  IF predecessor_ledger_rows <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Route identity migration blocked: predecessor ledger is missing';
  END IF;

  SELECT COUNT(*) INTO required_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_type = 'BASE TABLE'
     AND table_name IN (
       'nv_einsaetze',
       'nv_benutzer',
       'nv_fernmeldeplaene',
       'nv_fernmeldeplan_eintraege'
     );
  IF required_tables <> 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Route identity migration blocked: predecessor tables are missing';
  END IF;

  SELECT COUNT(*) INTO required_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND (
       (table_name = 'nv_fernmeldeplaene'
        AND column_name IN ('einsatz_id', 'erstellt_am', 'erstellt_von'))
       OR (table_name = 'nv_fernmeldeplan_eintraege'
           AND column_name IN ('fernmeldeplan_eintrag_id', 'fernmeldeplan_id',
                               'sortierung'))
     );
  IF required_columns <> 6 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Route identity migration blocked: predecessor columns are missing';
  END IF;
END//
DELIMITER ;

CREATE TABLE IF NOT EXISTS `nv_fernmeldewege` (
  `weg_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `weg_nummer` INT UNSIGNED NOT NULL,
  `angelegt_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `angelegt_von` VARCHAR(6) NOT NULL,
  PRIMARY KEY (`weg_id`),
  UNIQUE KEY `uq_fernmeldeweg_nummer` (`einsatz_id`, `weg_nummer`),
  CONSTRAINT `fk_fernmeldeweg_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`),
  CONSTRAINT `fk_fernmeldeweg_urheber`
    FOREIGN KEY (`angelegt_von`) REFERENCES `nv_benutzer` (`kuerzel`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:122-fernmeldeweg-identitaet:v1';

-- `fernmeldeplan_id` is carried along although it is derivable from the entry.
-- It is the reason this table can hold the unique key (fernmeldeplan_id,
-- weg_id): a route appears at most once per plan version, and the fallback
-- reference of a later migration needs exactly that key as its target.
CREATE TABLE IF NOT EXISTS `nv_fernmeldeweg_zuordnung` (
  `fernmeldeplan_eintrag_id` BIGINT UNSIGNED NOT NULL,
  `fernmeldeplan_id` BIGINT UNSIGNED NOT NULL,
  `weg_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`fernmeldeplan_eintrag_id`),
  UNIQUE KEY `uq_fernmeldeweg_zuordnung_plan`
    (`fernmeldeplan_id`, `weg_id`),
  KEY `idx_fernmeldeweg_zuordnung_weg` (`weg_id`),
  CONSTRAINT `fk_fernmeldeweg_zuordnung_eintrag`
    FOREIGN KEY (`fernmeldeplan_eintrag_id`)
      REFERENCES `nv_fernmeldeplan_eintraege` (`fernmeldeplan_eintrag_id`)
      ON DELETE CASCADE,
  CONSTRAINT `fk_fernmeldeweg_zuordnung_plan`
    FOREIGN KEY (`fernmeldeplan_id`)
      REFERENCES `nv_fernmeldeplaene` (`fernmeldeplan_id`),
  CONSTRAINT `fk_fernmeldeweg_zuordnung_weg`
    FOREIGN KEY (`weg_id`) REFERENCES `nv_fernmeldewege` (`weg_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:122-fernmeldeweg-identitaet:v1';

-- A mapping is never re-pointed. Whoever wants a different route for a plan
-- entry writes a different entry; the identity of an existing one is a fact,
-- not a setting.
DELIMITER //
CREATE OR REPLACE TRIGGER `estab_dv122_wegzuordnung_update`
BEFORE UPDATE ON `nv_fernmeldeweg_zuordnung`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Route identity assignments are immutable';
END//
DELIMITER ;

-- Backfill. Every existing plan entry receives its own identity, ordered so
-- the numbering is reproducible: incident, then plan version, then the
-- display order inside that version. The loop is restartable -- it only sees
-- entries that have no mapping yet.
DROP PROCEDURE IF EXISTS estab_migrate_122_backfill;
DELIMITER //
CREATE PROCEDURE estab_migrate_122_backfill()
BEGIN
  DECLARE done INTEGER DEFAULT 0;
  DECLARE entry_id BIGINT UNSIGNED;
  DECLARE plan_id BIGINT UNSIGNED;
  DECLARE incident_id BIGINT UNSIGNED;
  DECLARE created_at DATETIME(6);
  DECLARE created_by VARCHAR(6);
  DECLARE next_number INTEGER UNSIGNED;
  DECLARE new_weg_id BIGINT UNSIGNED;
  DECLARE pending CURSOR FOR
    SELECT entry.`fernmeldeplan_eintrag_id`,
           entry.`fernmeldeplan_id`,
           plan.`einsatz_id`,
           plan.`erstellt_am`,
           plan.`erstellt_von`
      FROM `nv_fernmeldeplan_eintraege` AS entry
      JOIN `nv_fernmeldeplaene` AS plan
        ON plan.`fernmeldeplan_id` = entry.`fernmeldeplan_id`
     WHERE NOT EXISTS (
       SELECT 1
         FROM `nv_fernmeldeweg_zuordnung` AS mapping
        WHERE mapping.`fernmeldeplan_eintrag_id`
              = entry.`fernmeldeplan_eintrag_id`
     )
     ORDER BY plan.`einsatz_id`, plan.`version`, entry.`sortierung`,
              entry.`fernmeldeplan_eintrag_id`;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN pending;
  read_loop: LOOP
    FETCH pending INTO entry_id, plan_id, incident_id, created_at, created_by;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;

    SELECT COALESCE(MAX(`weg_nummer`), 0) + 1 INTO next_number
      FROM `nv_fernmeldewege`
     WHERE `einsatz_id` = incident_id;

    INSERT INTO `nv_fernmeldewege`
      (`einsatz_id`, `weg_nummer`, `angelegt_am`, `angelegt_von`)
    VALUES
      (incident_id, next_number, created_at, created_by);
    SET new_weg_id = LAST_INSERT_ID();

    INSERT INTO `nv_fernmeldeweg_zuordnung`
      (`fernmeldeplan_eintrag_id`, `fernmeldeplan_id`, `weg_id`)
    VALUES
      (entry_id, plan_id, new_weg_id);
  END LOOP;
  CLOSE pending;
END//
DELIMITER ;

CALL estab_migrate_122_backfill();
DROP PROCEDURE estab_migrate_122_backfill;

-- Postflight. A route without an identity would silently break every
-- reference built on top of it, so the migration refuses to report success
-- while one exists.
DELIMITER //
BEGIN NOT ATOMIC
  DECLARE unmapped_entries INTEGER DEFAULT 0;
  DECLARE inconsistent_plans INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO unmapped_entries
    FROM `nv_fernmeldeplan_eintraege` AS entry
   WHERE NOT EXISTS (
     SELECT 1
       FROM `nv_fernmeldeweg_zuordnung` AS mapping
      WHERE mapping.`fernmeldeplan_eintrag_id`
            = entry.`fernmeldeplan_eintrag_id`
   );
  IF unmapped_entries <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Route identity migration failed: entries without an identity remain';
  END IF;

  SELECT COUNT(*) INTO inconsistent_plans
    FROM `nv_fernmeldeweg_zuordnung` AS mapping
    JOIN `nv_fernmeldeplan_eintraege` AS entry
      ON entry.`fernmeldeplan_eintrag_id`
         = mapping.`fernmeldeplan_eintrag_id`
   WHERE entry.`fernmeldeplan_id` <> mapping.`fernmeldeplan_id`;
  IF inconsistent_plans <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Route identity migration failed: mapping names the wrong plan';
  END IF;
END//
DELIMITER ;
