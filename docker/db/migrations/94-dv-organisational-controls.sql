-- Organisational command-post controls derived from DV 1-101 chapter 4.3.
--
-- The migration adds explicit duty shifts and function "hats", a versioned
-- S6 telecommunications plan, and a traceable messenger lifecycle.  Existing
-- message and logbook rows are deliberately not rewritten.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Some historic databases lack the message lock flag even though the
-- surrounding dispatch fields exist. The LdF redisposition trigger needs the
-- flag, so add it once before validating the complete predecessor contract.
DROP PROCEDURE IF EXISTS estab_migrate_94_message_lock_flag;
DELIMITER //
CREATE PROCEDURE estab_migrate_94_message_lock_flag()
BEGIN
  IF EXISTS (
    SELECT 1
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND table_type = 'BASE TABLE'
  )
  AND EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = 'x01_abschluss'
  )
  AND NOT EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = 'x02_sperre'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `x02_sperre` BINARY(1) NOT NULL DEFAULT 'f'
      AFTER `x01_abschluss`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_94_message_lock_flag();
DROP PROCEDURE estab_migrate_94_message_lock_flag;

DROP PROCEDURE IF EXISTS estab_migrate_94_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_94_preflight()
BEGIN
  DECLARE conflicting_tables INTEGER DEFAULT 0;
  DECLARE required_tables INTEGER DEFAULT 0;
  DECLARE required_message_columns INTEGER DEFAULT 0;
  DECLARE standard_matrix_columns INTEGER DEFAULT 0;
  DECLARE active_matrix_objects INTEGER DEFAULT 0;
  DECLARE active_matrix_columns INTEGER DEFAULT 0;
  DECLARE standard_s2_targets INTEGER DEFAULT 0;

  -- Migrations 40, 50 and 80 own these prerequisites.  Check them through
  -- information_schema before referring to their tables so an incomplete
  -- imported namespace fails with one actionable error instead of an
  -- "unknown table/column" half-upgrade.
  SELECT COUNT(*) INTO required_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_type = 'BASE TABLE'
     AND table_name IN (
       'nv_einsaetze',
       'nv_einsatz_status',
       'nv_benutzer',
       'nv_nachrichten',
       'nv_empfmtx_standard'
     );
  IF required_tables <> 5 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: required predecessor table is missing';
  END IF;

  SELECT COUNT(*) INTO required_message_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name IN (
       '00_lfd',
       'einsatz_id',
       '04_richtung',
       '06_befwegausw',
       '02_zeit',
       '02_zeichen',
       '03_datum',
       '03_zeichen',
       '15_quitdatum',
       '15_quitzeichen',
       'x00_status',
       'x01_abschluss',
       'x02_sperre',
       'x03_sperruser'
     );
  IF required_message_columns <> 14 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: required legacy message column is missing';
  END IF;

  SELECT COUNT(*) INTO standard_matrix_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_empfmtx_standard'
     AND column_name IN (
       'mtx_lfd',
       'mtx_x',
       'mtx_y',
       'mtx_typ',
       'mtx_fkt',
       'mtx_rolle',
       'mtx_mode',
       'mtx_rc2',
       'mtx_auto'
     );
  IF standard_matrix_columns <> 9 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: incompatible standard recipient matrix';
  END IF;

  SELECT COUNT(*) INTO active_matrix_objects
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_empfmtx';
  IF active_matrix_objects > 1 OR EXISTS (
    SELECT 1
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_empfmtx'
       AND (
         table_type <> 'BASE TABLE'
         OR engine <> 'InnoDB'
       )
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: incompatible active recipient matrix';
  END IF;
  IF active_matrix_objects = 1 THEN
    SELECT COUNT(*) INTO active_matrix_columns
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_empfmtx'
       AND column_name IN (
         'mtx_lfd',
         'mtx_x',
         'mtx_y',
         'mtx_typ',
         'mtx_fkt',
         'mtx_rolle',
         'mtx_mode',
         'mtx_rc2',
         'mtx_auto'
       );
    IF active_matrix_columns <> 9 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'DV organisational migration blocked: incompatible active recipient matrix';
    END IF;
  END IF;

  SELECT COUNT(*) INTO conflicting_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN (
       'nv_funktionsfaehigkeiten',
       'nv_betriebsereignis_kopf',
       'nv_betriebsereignisse',
       'nv_dienstschichten',
       'nv_dienstbesetzungen',
       'nv_dienstuebergabe_anfragen',
       'nv_dienstuebergaben',
       'nv_fernmeldeplaene',
       'nv_fernmeldeplan_eintraege',
       'nv_melderauftraege'
     )
     AND (
       table_type <> 'BASE TABLE'
       OR engine <> 'InnoDB'
       OR table_comment <> 'estab:migration:94-dv-organisational-controls:v1'
     );

  IF conflicting_tables <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: incompatible pre-existing table';
  END IF;

  SELECT COUNT(*) INTO standard_s2_targets
    FROM `nv_empfmtx_standard`
   WHERE `mtx_typ` = 'cb'
     AND BINARY `mtx_fkt` = BINARY 'S2'
     AND `mtx_rolle` = 'Stab';

  IF standard_s2_targets <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: standard matrix requires exactly one S2/Stab position';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_94_preflight();
DROP PROCEDURE estab_migrate_94_preflight;

-- Some old installations created the active recipient matrix lazily from the
-- PHP preset.  Migration 40 already guarantees an independently owned
-- standard matrix, while this migration can safely restore a wholly missing
-- active matrix.  A pre-existing active matrix is never overwritten or
-- silently completed.
SET @estab_dv94_active_matrix_was_missing = (
  SELECT COUNT(*) = 0
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_empfmtx'
);

CREATE TABLE IF NOT EXISTS `nv_empfmtx` (
  `mtx_lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mtx_x` INT NOT NULL,
  `mtx_y` INT NOT NULL,
  `mtx_typ` SET('cb','t') NOT NULL DEFAULT '',
  `mtx_fkt` VARCHAR(6) NOT NULL DEFAULT '',
  `mtx_rolle` SET('Stab','FB') NOT NULL DEFAULT '',
  `mtx_mode` SET('ro','rw') NOT NULL DEFAULT '',
  `mtx_rc2` BINARY(1) NOT NULL DEFAULT 'f',
  `mtx_auto` BINARY(1) NOT NULL DEFAULT 'f',
  PRIMARY KEY (`mtx_lfd`),
  UNIQUE KEY `uq_empfmtx_position` (`mtx_x`, `mtx_y`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_94_seed_active_matrix;
DELIMITER //
CREATE PROCEDURE estab_migrate_94_seed_active_matrix()
BEGIN
  IF @estab_dv94_active_matrix_was_missing = 1 THEN
    INSERT INTO `nv_empfmtx`
      (`mtx_x`, `mtx_y`, `mtx_typ`, `mtx_fkt`, `mtx_rolle`, `mtx_mode`,
       `mtx_rc2`, `mtx_auto`)
    VALUES
      (1,1,'cb','LS', 'Stab','ro','f','f'),
      (1,2,'cb','S5', 'Stab','ro','f','f'),
      (1,3,'t', '',   '',    'ro','f','f'),
      (1,4,'t', '',   '',    'ro','f','f'),
      (2,1,'cb','S1', 'Stab','ro','f','f'),
      (2,2,'cb','S6', 'Stab','ro','f','f'),
      (2,3,'t', '',   '',    'ro','f','f'),
      (2,4,'t', '',   '',    'ro','f','f'),
      (3,1,'cb','S2', 'Stab','ro','t','f'),
      (3,2,'cb','POL','FB',  'ro','f','f'),
      (3,3,'t', '',   '',    'ro','f','f'),
      (3,4,'t', '',   '',    'ro','f','f'),
      (4,1,'cb','S3', 'Stab','ro','f','f'),
      (4,2,'cb','THW','FB',  'ro','f','f'),
      (4,3,'t', '',   '',    'ro','f','f'),
      (4,4,'t', '',   '',    'ro','f','f'),
      (5,1,'cb','S4', 'Stab','ro','f','f'),
      (5,2,'cb','SAN','FB',  'ro','f','f'),
      (5,3,'t', '',   '',    'ro','f','f'),
      (5,4,'t', '',   '',    'ro','f','f');
  END IF;
END//
DELIMITER ;
CALL estab_migrate_94_seed_active_matrix();
DROP PROCEDURE estab_migrate_94_seed_active_matrix;
SET @estab_dv94_active_matrix_was_missing = NULL;

DROP PROCEDURE IF EXISTS estab_migrate_94_validate_matrices;
DELIMITER //
CREATE PROCEDURE estab_migrate_94_validate_matrices()
BEGIN
  DECLARE active_cells INTEGER DEFAULT 0;
  DECLARE active_positions INTEGER DEFAULT 0;
  DECLARE active_s2_targets INTEGER DEFAULT 0;

  SELECT COUNT(*),
         COUNT(DISTINCT `mtx_x`, `mtx_y`),
         SUM(
           `mtx_typ` = 'cb'
           AND BINARY `mtx_fkt` = BINARY 'S2'
           AND `mtx_rolle` = 'Stab'
         )
    INTO active_cells, active_positions, active_s2_targets
    FROM `nv_empfmtx`;

  IF active_cells <> 20
     OR active_positions <> 20
     OR active_s2_targets <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: active matrix requires 20 unique cells and exactly one S2/Stab position';
  END IF;
END//
DELIMITER ;
CALL estab_migrate_94_validate_matrices();
DROP PROCEDURE estab_migrate_94_validate_matrices;

-- DV 1-101 assigns the complete red-copy information flow and operational
-- documentation to S2. Automatic sighting cannot replace the qualified
-- Sichter function and is therefore normalised away for both presets.
UPDATE `nv_empfmtx`
   SET `mtx_rc2` = IF(BINARY `mtx_fkt` = BINARY 'S2', 't', 'f'),
       `mtx_auto` = 'f';
UPDATE `nv_empfmtx_standard`
   SET `mtx_rc2` = IF(BINARY `mtx_fkt` = BINARY 'S2', 't', 'f'),
       `mtx_auto` = 'f';

CREATE TABLE IF NOT EXISTS `nv_funktionsfaehigkeiten` (
  `funktion` VARCHAR(6) NOT NULL,
  `rolle` ENUM('Stab','FB','Fernmelder') NOT NULL,
  `faehigkeit` ENUM(
    'LAGE_DOKUMENTATION',
    'SICHTUNG',
    'FERNMELDEPLANUNG',
    'FERNMELDEBETRIEB',
    'BEFOERDERUNG'
  ) NOT NULL,
  `bezeichnung` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`funktion`, `faehigkeit`),
  UNIQUE KEY `uq_funktionsfaehigkeit_eindeutig` (`faehigkeit`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

INSERT IGNORE INTO `nv_funktionsfaehigkeiten`
  (`funktion`, `rolle`, `faehigkeit`, `bezeichnung`)
VALUES
  ('S2',  'Stab',       'LAGE_DOKUMENTATION', 'Lage und Dokumentation'),
  ('Si',  'Stab',       'SICHTUNG',            'Sichter'),
  ('S6',  'Stab',       'FERNMELDEPLANUNG',    'Fernmeldeplanung'),
  ('LdF', 'Fernmelder', 'FERNMELDEBETRIEB',    'Leiter der Fernmeldezentrale'),
  ('A/W', 'Fernmelder', 'BEFOERDERUNG',        'Aufnahme und Weitergabe');

CREATE TABLE IF NOT EXISTS `nv_betriebsereignis_kopf` (
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `letzte_sequenz` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `letzter_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
    DEFAULT '0000000000000000000000000000000000000000000000000000000000000000',
  PRIMARY KEY (`einsatz_id`),
  CONSTRAINT `fk_betriebsereignis_kopf_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_betriebsereignisse` (
  `betriebsereignis_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `sequenz` BIGINT UNSIGNED NOT NULL,
  `objekttyp` ENUM(
    'DIENSTSCHICHT',
    'DIENSTBESETZUNG',
    'DIENSTUEBERGABE',
    'FERNMELDEPLAN',
    'MELDERAUFTRAG'
  ) NOT NULL,
  `objekt_id` BIGINT UNSIGNED NOT NULL,
  `aktion` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `akteur_kuerzel` VARCHAR(128) NOT NULL,
  `akteur_funktion` VARCHAR(6) NULL,
  `ereigniszeit` DATETIME(6) NOT NULL,
  `details` JSON NOT NULL,
  `vorheriger_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ereignis_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`betriebsereignis_id`),
  UNIQUE KEY `uq_betriebsereignis_sequenz` (`einsatz_id`, `sequenz`),
  KEY `idx_betriebsereignis_objekt`
    (`einsatz_id`, `objekttyp`, `objekt_id`, `sequenz`),
  CONSTRAINT `fk_betriebsereignis_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_dienstschichten` (
  `dienstschicht_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `nummer` INT UNSIGNED NOT NULL,
  `bezeichnung` VARCHAR(100) NOT NULL,
  `status` ENUM('GEPLANT','AKTIV','UEBERGEBEN','GESCHLOSSEN')
    NOT NULL DEFAULT 'GEPLANT',
  `vorgaenger_id` BIGINT UNSIGNED NULL,
  `erstellt_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `erstellt_von` VARCHAR(128) NOT NULL,
  `aktiviert_am` DATETIME(6) NULL,
  `beendet_am` DATETIME(6) NULL,
  `aktive_einsatz_id` BIGINT UNSIGNED
    AS (CASE WHEN `status` = 'AKTIV' THEN `einsatz_id` ELSE NULL END) STORED,
  PRIMARY KEY (`dienstschicht_id`),
  UNIQUE KEY `uq_dienstschicht_nummer` (`einsatz_id`, `nummer`),
  UNIQUE KEY `uq_dienstschicht_aktiv` (`aktive_einsatz_id`),
  KEY `idx_dienstschicht_status` (`einsatz_id`, `status`),
  KEY `idx_dienstschicht_vorgaenger` (`vorgaenger_id`),
  CONSTRAINT `fk_dienstschicht_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`),
  CONSTRAINT `fk_dienstschicht_vorgaenger`
    FOREIGN KEY (`vorgaenger_id`)
      REFERENCES `nv_dienstschichten` (`dienstschicht_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_dienstbesetzungen` (
  `dienstbesetzung_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dienstschicht_id` BIGINT UNSIGNED NOT NULL,
  `benutzer_kuerzel` VARCHAR(6) NOT NULL,
  `funktion` VARCHAR(6) NOT NULL,
  `rolle` ENUM('Stab','FB','Fernmelder') NOT NULL,
  `status` ENUM('ZUGEWIESEN','ANGENOMMEN','ABGELOEST','ZURUECKGEZOGEN')
    NOT NULL DEFAULT 'ZUGEWIESEN',
  `zugewiesen_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `zugewiesen_von` VARCHAR(128) NOT NULL,
  `angenommen_am` DATETIME(6) NULL,
  `abgeloest_am` DATETIME(6) NULL,
  `nachfolger_id` BIGINT UNSIGNED NULL,
  `aktive_besetzung` VARCHAR(112)
    AS (
      CASE
        WHEN `status` IN ('ZUGEWIESEN','ANGENOMMEN')
          THEN CONCAT(
            `dienstschicht_id`, ':',
            BINARY `benutzer_kuerzel`, ':',
            BINARY `funktion`
          )
        ELSE NULL
      END
    ) STORED,
  `aktive_funktion` VARCHAR(96)
    AS (
      CASE
        WHEN `status` IN ('ZUGEWIESEN','ANGENOMMEN')
             AND BINARY `funktion` <> BINARY 'A/W'
          THEN CONCAT(
            `dienstschicht_id`, ':',
            BINARY `funktion`
          )
        ELSE NULL
      END
    ) STORED,
  PRIMARY KEY (`dienstbesetzung_id`),
  UNIQUE KEY `uq_dienstbesetzung_aktive_besetzung` (`aktive_besetzung`),
  UNIQUE KEY `uq_dienstbesetzung_aktive_funktion` (`aktive_funktion`),
  KEY `idx_dienstbesetzung_benutzer` (`benutzer_kuerzel`, `status`),
  KEY `idx_dienstbesetzung_schicht` (`dienstschicht_id`, `status`),
  KEY `idx_dienstbesetzung_nachfolger` (`nachfolger_id`),
  CONSTRAINT `fk_dienstbesetzung_schicht`
    FOREIGN KEY (`dienstschicht_id`)
      REFERENCES `nv_dienstschichten` (`dienstschicht_id`),
  CONSTRAINT `fk_dienstbesetzung_benutzer`
    FOREIGN KEY (`benutzer_kuerzel`) REFERENCES `nv_benutzer` (`kuerzel`),
  CONSTRAINT `fk_dienstbesetzung_nachfolger`
    FOREIGN KEY (`nachfolger_id`)
      REFERENCES `nv_dienstbesetzungen` (`dienstbesetzung_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_dienstuebergaben` (
  `dienstuebergabe_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `von_dienstschicht_id` BIGINT UNSIGNED NOT NULL,
  `an_dienstschicht_id` BIGINT UNSIGNED NOT NULL,
  `zusammenfassung` TEXT NOT NULL,
  `uebergeben_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `uebergeben_von` VARCHAR(128) NOT NULL,
  `angenommen_von` VARCHAR(128) NOT NULL,
  PRIMARY KEY (`dienstuebergabe_id`),
  UNIQUE KEY `uq_dienstuebergabe_von` (`von_dienstschicht_id`),
  UNIQUE KEY `uq_dienstuebergabe_an` (`an_dienstschicht_id`),
  KEY `idx_dienstuebergabe_einsatz` (`einsatz_id`, `uebergeben_am`),
  CONSTRAINT `fk_dienstuebergabe_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`),
  CONSTRAINT `fk_dienstuebergabe_von`
    FOREIGN KEY (`von_dienstschicht_id`)
      REFERENCES `nv_dienstschichten` (`dienstschicht_id`),
  CONSTRAINT `fk_dienstuebergabe_an`
    FOREIGN KEY (`an_dienstschicht_id`)
      REFERENCES `nv_dienstschichten` (`dienstschicht_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_dienstuebergabe_anfragen` (
  `dienstuebergabe_anfrage_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `von_dienstschicht_id` BIGINT UNSIGNED NOT NULL,
  `an_dienstschicht_id` BIGINT UNSIGNED NOT NULL,
  `zusammenfassung` TEXT NOT NULL,
  `status` ENUM('INITIIERT','BESTAETIGT','STORNIERT')
    NOT NULL DEFAULT 'INITIIERT',
  `initiiert_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `initiiert_von` VARCHAR(128) NOT NULL,
  `bestaetigt_am` DATETIME(6) NULL,
  `bestaetigt_von` VARCHAR(6) NULL,
  `bestaetigt_mit_besetzung_id` BIGINT UNSIGNED NULL,
  `dienstuebergabe_id` BIGINT UNSIGNED NULL,
  `storniert_am` DATETIME(6) NULL,
  `storniert_von` VARCHAR(128) NULL,
  `stornierungsgrund` TEXT NULL,
  `offene_von_dienstschicht_id` BIGINT UNSIGNED
    AS (
      CASE
        WHEN `status` = 'INITIIERT' THEN `von_dienstschicht_id`
        ELSE NULL
      END
    ) STORED,
  `offene_an_dienstschicht_id` BIGINT UNSIGNED
    AS (
      CASE
        WHEN `status` = 'INITIIERT' THEN `an_dienstschicht_id`
        ELSE NULL
      END
    ) STORED,
  PRIMARY KEY (`dienstuebergabe_anfrage_id`),
  UNIQUE KEY `uq_dienstuebergabe_anfrage_offene_von`
    (`offene_von_dienstschicht_id`),
  UNIQUE KEY `uq_dienstuebergabe_anfrage_offene_an`
    (`offene_an_dienstschicht_id`),
  UNIQUE KEY `uq_dienstuebergabe_anfrage_abschluss`
    (`dienstuebergabe_id`),
  KEY `idx_dienstuebergabe_anfrage_einsatz`
    (`einsatz_id`, `status`, `initiiert_am`),
  CONSTRAINT `fk_dienstuebergabe_anfrage_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`),
  CONSTRAINT `fk_dienstuebergabe_anfrage_von`
    FOREIGN KEY (`von_dienstschicht_id`)
      REFERENCES `nv_dienstschichten` (`dienstschicht_id`),
  CONSTRAINT `fk_dienstuebergabe_anfrage_an`
    FOREIGN KEY (`an_dienstschicht_id`)
      REFERENCES `nv_dienstschichten` (`dienstschicht_id`),
  CONSTRAINT `fk_dienstuebergabe_anfrage_besetzung`
    FOREIGN KEY (`bestaetigt_mit_besetzung_id`)
      REFERENCES `nv_dienstbesetzungen` (`dienstbesetzung_id`),
  CONSTRAINT `fk_dienstuebergabe_anfrage_abschluss`
    FOREIGN KEY (`dienstuebergabe_id`)
      REFERENCES `nv_dienstuebergaben` (`dienstuebergabe_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_fernmeldeplaene` (
  `fernmeldeplan_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `status` ENUM('ENTWURF','AKTIV','ERSETZT') NOT NULL DEFAULT 'ENTWURF',
  `einsatzbezeichnung` VARCHAR(255) NOT NULL,
  `herkunft` VARCHAR(255) NOT NULL,
  `gueltig_ab` DATETIME NOT NULL,
  `gueltig_bis` DATETIME NULL,
  `betriebsleitung` VARCHAR(255) NOT NULL,
  `bemerkungen` TEXT NULL,
  `erstellt_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `erstellt_von` VARCHAR(6) NOT NULL,
  `freigegeben_am` DATETIME(6) NULL,
  `freigegeben_von` VARCHAR(6) NULL,
  `aktive_einsatz_id` BIGINT UNSIGNED
    AS (CASE WHEN `status` = 'AKTIV' THEN `einsatz_id` ELSE NULL END) STORED,
  PRIMARY KEY (`fernmeldeplan_id`),
  UNIQUE KEY `uq_fernmeldeplan_version` (`einsatz_id`, `version`),
  UNIQUE KEY `uq_fernmeldeplan_aktiv` (`aktive_einsatz_id`),
  KEY `idx_fernmeldeplan_status` (`einsatz_id`, `status`),
  CONSTRAINT `fk_fernmeldeplan_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`),
  CONSTRAINT `fk_fernmeldeplan_ersteller`
    FOREIGN KEY (`erstellt_von`) REFERENCES `nv_benutzer` (`kuerzel`),
  CONSTRAINT `fk_fernmeldeplan_freigabe`
    FOREIGN KEY (`freigegeben_von`) REFERENCES `nv_benutzer` (`kuerzel`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_fernmeldeplan_eintraege` (
  `fernmeldeplan_eintrag_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fernmeldeplan_id` BIGINT UNSIGNED NOT NULL,
  `sortierung` INT UNSIGNED NOT NULL,
  `betriebsstelle` VARCHAR(255) NOT NULL,
  `rufname` VARCHAR(128) NOT NULL,
  `medium` ENUM('Fe','Fu','Me','FAX','FS','@') NOT NULL,
  `kanal` VARCHAR(64) NOT NULL,
  `bandlage` VARCHAR(64) NOT NULL,
  `verkehrsform` VARCHAR(128) NOT NULL,
  `besondere_vermerke` TEXT NULL,
  `bemerkungen` TEXT NULL,
  PRIMARY KEY (`fernmeldeplan_eintrag_id`),
  UNIQUE KEY `uq_fernmeldeplan_sortierung`
    (`fernmeldeplan_id`, `sortierung`),
  KEY `idx_fernmeldeplan_route`
    (`fernmeldeplan_id`, `medium`, `rufname`),
  CONSTRAINT `fk_fernmeldeplan_eintrag`
    FOREIGN KEY (`fernmeldeplan_id`)
      REFERENCES `nv_fernmeldeplaene` (`fernmeldeplan_id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

CREATE TABLE IF NOT EXISTS `nv_melderauftraege` (
  `melderauftrag_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz_id` BIGINT UNSIGNED NOT NULL,
  `nachricht_id` BIGINT NOT NULL,
  `melder_kuerzel` VARCHAR(6) NOT NULL,
  `ziel` VARCHAR(255) NOT NULL,
  `status` ENUM(
    'BEAUFTRAGT',
    'UEBERNOMMEN',
    'UEBERGEBEN',
    'RUECKWEG',
    'ZURUECK',
    'GEMELDET',
    'ABGEBROCHEN'
  ) NOT NULL DEFAULT 'BEAUFTRAGT',
  `beauftragt_am` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `beauftragt_von` VARCHAR(6) NOT NULL,
  `uebernommen_am` DATETIME(6) NULL,
  `tatsaechlicher_empfaenger` VARCHAR(255) NULL,
  `uebergeben_am` DATETIME(6) NULL,
  `ruecknachricht_vorhanden` TINYINT(1) NULL,
  `ruecknachricht` TEXT NULL,
  `rueckweg_am` DATETIME(6) NULL,
  `zurueck_am` DATETIME(6) NULL,
  `abschlussvermerk` TEXT NULL,
  `gemeldet_am` DATETIME(6) NULL,
  `gemeldet_an` VARCHAR(6) NULL,
  `abgebrochen_am` DATETIME(6) NULL,
  `abbruchgrund` TEXT NULL,
  `offener_melder` VARCHAR(6)
    AS (
      CASE
        WHEN `status` NOT IN ('GEMELDET','ABGEBROCHEN')
          THEN `melder_kuerzel`
        ELSE NULL
      END
    ) STORED,
  `offener_nachrichtenauftrag` BIGINT
    AS (
      CASE
        WHEN `status` NOT IN ('GEMELDET','ABGEBROCHEN')
          THEN `nachricht_id`
        ELSE NULL
      END
    ) STORED,
  PRIMARY KEY (`melderauftrag_id`),
  UNIQUE KEY `uq_melderauftrag_offener_melder` (`offener_melder`),
  UNIQUE KEY `uq_melderauftrag_offene_nachricht`
    (`offener_nachrichtenauftrag`),
  KEY `idx_melderauftrag_einsatz` (`einsatz_id`, `status`),
  KEY `idx_melderauftrag_nachricht` (`nachricht_id`),
  CONSTRAINT `fk_melderauftrag_einsatz`
    FOREIGN KEY (`einsatz_id`) REFERENCES `nv_einsaetze` (`einsatz_id`),
  CONSTRAINT `fk_melderauftrag_nachricht`
    FOREIGN KEY (`nachricht_id`) REFERENCES `nv_nachrichten` (`00_lfd`),
  CONSTRAINT `fk_melderauftrag_melder`
    FOREIGN KEY (`melder_kuerzel`) REFERENCES `nv_benutzer` (`kuerzel`),
  CONSTRAINT `fk_melderauftrag_beauftragt`
    FOREIGN KEY (`beauftragt_von`) REFERENCES `nv_benutzer` (`kuerzel`),
  CONSTRAINT `fk_melderauftrag_gemeldet`
    FOREIGN KEY (`gemeldet_an`) REFERENCES `nv_benutzer` (`kuerzel`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='estab:migration:94-dv-organisational-controls:v1';

-- Legacy/imported messages keep NULL. Every newly disposed outgoing message
-- can reference the exact immutable route version used by LdF.
DROP PROCEDURE IF EXISTS estab_migrate_94_message_route_reference;
DELIMITER //
CREATE PROCEDURE estab_migrate_94_message_route_reference()
BEGIN
  DECLARE matching_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;
  DECLARE matching_foreign_keys INTEGER DEFAULT 0;
  DECLARE canonical_foreign_keys INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO matching_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = 'estab_fernmeldeplan_eintrag_id';

  IF matching_columns = 0 THEN
    ALTER TABLE `nv_nachrichten`
      ADD COLUMN `estab_fernmeldeplan_eintrag_id` BIGINT UNSIGNED NULL
        AFTER `06_befwegausw`,
      ADD KEY `idx_nachrichten_fernmeldeplan_eintrag`
        (`estab_fernmeldeplan_eintrag_id`);
  ELSE
    SELECT COUNT(*) INTO canonical_columns
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = 'estab_fernmeldeplan_eintrag_id'
       AND data_type = 'bigint'
       AND column_type LIKE '%unsigned%'
       AND is_nullable = 'YES'
       AND extra = '';
    IF matching_columns <> 1 OR canonical_columns <> 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'DV organisational migration blocked: incompatible message route reference';
    END IF;
  END IF;

  SELECT COUNT(*) INTO matching_foreign_keys
    FROM information_schema.referential_constraints
   WHERE constraint_schema = DATABASE()
     AND constraint_name = 'fk_nachrichten_fernmeldeplan_eintrag';
  SELECT COUNT(*) INTO canonical_foreign_keys
    FROM information_schema.key_column_usage
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND constraint_name = 'fk_nachrichten_fernmeldeplan_eintrag'
     AND column_name = 'estab_fernmeldeplan_eintrag_id'
     AND referenced_table_name = 'nv_fernmeldeplan_eintraege'
     AND referenced_column_name = 'fernmeldeplan_eintrag_id';

  IF matching_foreign_keys = 0 THEN
    ALTER TABLE `nv_nachrichten`
      ADD CONSTRAINT `fk_nachrichten_fernmeldeplan_eintrag`
      FOREIGN KEY (`estab_fernmeldeplan_eintrag_id`)
        REFERENCES `nv_fernmeldeplan_eintraege`
          (`fernmeldeplan_eintrag_id`);
  ELSEIF matching_foreign_keys <> 1 OR canonical_foreign_keys <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'DV organisational migration blocked: incompatible message route foreign key';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_94_message_route_reference();
DROP PROCEDURE estab_migrate_94_message_route_reference;

DROP TRIGGER IF EXISTS `estab_dv94_message_route_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_message_route_insert`
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
          AND BINARY route_entry.`medium` = BINARY NEW.`06_befwegausw`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Outgoing route must reference the active S6 plan';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_message_route_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_message_route_update`
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
           = BINARY NEW.`06_befwegausw`
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
          AND BINARY route_entry.`medium` = BINARY NEW.`06_befwegausw`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Outgoing route must reference the active S6 plan';
  END IF;
END//
DELIMITER ;

-- An activated/superseded plan is evidence and must never be edited in place.
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
      SET MESSAGE_TEXT = 'Telecommunications plan update requires the active open incident';
  END IF;

  IF OLD.`status` = 'ENTWURF' AND NEW.`status` = 'ENTWURF' THEN
    IF OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NOT NULL
       OR NEW.`freigegeben_von` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Draft telecommunications plan has invalid release evidence';
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
           FROM `nv_dienstschichten` AS release_shift
           JOIN `nv_dienstbesetzungen` AS release_hat
             ON release_hat.`dienstschicht_id` =
                release_shift.`dienstschicht_id`
           JOIN `nv_benutzer` AS release_account
             ON BINARY release_account.`kuerzel` =
                BINARY release_hat.`benutzer_kuerzel`
          WHERE release_shift.`einsatz_id` = OLD.`einsatz_id`
            AND release_shift.`status` = 'AKTIV'
            AND release_hat.`status` = 'ANGENOMMEN'
            AND BINARY release_hat.`funktion` = BINARY 'S6'
            AND BINARY release_hat.`rolle` = BINARY 'Stab'
            AND BINARY release_hat.`benutzer_kuerzel` =
                BINARY NEW.`freigegeben_von`
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
        SET MESSAGE_TEXT = 'Invalid telecommunications plan release';
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

DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_entry_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_entry_insert`
BEFORE INSERT ON `nv_fernmeldeplan_eintraege`
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
      SET MESSAGE_TEXT = 'Only draft plans of the active open incident can be edited';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_entry_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_entry_update`
BEFORE UPDATE ON `nv_fernmeldeplan_eintraege`
FOR EACH ROW
BEGIN
  IF NEW.`fernmeldeplan_eintrag_id` <>
       OLD.`fernmeldeplan_eintrag_id`
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
      SET MESSAGE_TEXT = 'Activated telecommunications plan entries are immutable';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_entry_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_entry_delete`
BEFORE DELETE ON `nv_fernmeldeplan_eintraege`
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
      SET MESSAGE_TEXT = 'Activated telecommunications plan entries are immutable';
  END IF;
END//
DELIMITER ;

-- Every operational row is writable only while its incident is the singleton
-- active, open incident. Historic duty/evidence rows cannot be deleted.
DROP TRIGGER IF EXISTS `estab_dv94_shift_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_shift_insert`
BEFORE INSERT ON `nv_dienstschichten`
FOR EACH ROW
BEGIN
  IF NEW.`status` <> 'GEPLANT'
     OR NEW.`aktiviert_am` IS NOT NULL
     OR NEW.`beendet_am` IS NOT NULL
     OR (
       NEW.`vorgaenger_id` IS NOT NULL
       AND NOT EXISTS (
         SELECT 1
           FROM `nv_dienstschichten` AS predecessor_shift
          WHERE predecessor_shift.`dienstschicht_id` = NEW.`vorgaenger_id`
            AND predecessor_shift.`einsatz_id` = NEW.`einsatz_id`
            AND predecessor_shift.`status` IN ('GEPLANT','AKTIV')
       )
     )
     OR NOT EXISTS (
    SELECT 1
      FROM `nv_einsatz_status` AS active_incident
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
     WHERE active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`
       AND incident.`estab_status` = 'open'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Duty shift insert requires the active open incident';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_shift_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_shift_update`
BEFORE UPDATE ON `nv_dienstschichten`
FOR EACH ROW
BEGIN
  IF NEW.`einsatz_id` <> OLD.`einsatz_id`
     OR NEW.`nummer` <> OLD.`nummer`
     OR BINARY NEW.`bezeichnung` <> BINARY OLD.`bezeichnung`
     OR NOT (NEW.`vorgaenger_id` <=> OLD.`vorgaenger_id`)
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
      SET MESSAGE_TEXT = 'Invalid or inactive-incident duty shift transition';
  END IF;
  IF NEW.`status` = OLD.`status` THEN
    IF NOT (NEW.`aktiviert_am` <=> OLD.`aktiviert_am`)
       OR NOT (NEW.`beendet_am` <=> OLD.`beendet_am`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Duty shift evidence fields are immutable';
    END IF;
  ELSEIF OLD.`status` = 'GEPLANT' AND NEW.`status` = 'AKTIV' THEN
    IF OLD.`aktiviert_am` IS NOT NULL
       OR NEW.`aktiviert_am` IS NULL
       OR OLD.`beendet_am` IS NOT NULL
       OR NEW.`beendet_am` IS NOT NULL
       OR (
         NOT EXISTS (
           SELECT 1
             FROM `nv_dienstuebergabe_anfragen` AS handover_request
            WHERE handover_request.`einsatz_id` = OLD.`einsatz_id`
              AND handover_request.`an_dienstschicht_id` =
                  OLD.`dienstschicht_id`
              AND handover_request.`status` = 'INITIIERT'
         )
         AND (
           OLD.`vorgaenger_id` IS NOT NULL
           OR EXISTS (
             SELECT 1
               FROM `nv_dienstschichten` AS historic_shift
              WHERE historic_shift.`einsatz_id` = OLD.`einsatz_id`
                AND historic_shift.`dienstschicht_id` <>
                    OLD.`dienstschicht_id`
                AND historic_shift.`aktiviert_am` IS NOT NULL
           )
         )
       )
       OR (
         SELECT COUNT(DISTINCT assignment.`funktion`)
           FROM `nv_dienstbesetzungen` AS assignment
           JOIN `nv_benutzer` AS account
             ON BINARY account.`kuerzel` =
                BINARY assignment.`benutzer_kuerzel`
          WHERE assignment.`dienstschicht_id` = OLD.`dienstschicht_id`
            AND assignment.`status` = 'ANGENOMMEN'
            AND assignment.`funktion` IN ('S2','Si','S6','LdF','A/W')
            AND account.`estab_gesperrt` = 0
       ) <> 5 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid initial or handover duty shift activation';
    END IF;
  ELSEIF OLD.`status` = 'GEPLANT' AND NEW.`status` = 'GESCHLOSSEN' THEN
    IF NEW.`aktiviert_am` IS NOT NULL
       OR OLD.`beendet_am` IS NOT NULL
       OR NEW.`beendet_am` IS NULL
       OR EXISTS (
         SELECT 1
           FROM `nv_dienstuebergabe_anfragen` AS handover_request
          WHERE handover_request.`einsatz_id` = OLD.`einsatz_id`
            AND handover_request.`status` = 'INITIIERT'
            AND (
              handover_request.`von_dienstschicht_id` =
                OLD.`dienstschicht_id`
              OR handover_request.`an_dienstschicht_id` =
                OLD.`dienstschicht_id`
            )
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid planned duty shift close';
    END IF;
  ELSEIF OLD.`status` = 'AKTIV' AND NEW.`status` = 'UEBERGEBEN' THEN
    IF OLD.`aktiviert_am` IS NULL
       OR NEW.`aktiviert_am` <> OLD.`aktiviert_am`
       OR OLD.`beendet_am` IS NOT NULL
       OR NEW.`beendet_am` IS NULL
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_dienstuebergabe_anfragen` AS handover_request
          WHERE handover_request.`einsatz_id` = OLD.`einsatz_id`
            AND handover_request.`von_dienstschicht_id` =
                OLD.`dienstschicht_id`
            AND handover_request.`status` = 'INITIIERT'
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Active duty shift requires a pending handover';
    END IF;
  ELSEIF OLD.`status` = 'AKTIV' AND NEW.`status` = 'GESCHLOSSEN' THEN
    IF OLD.`aktiviert_am` IS NULL
       OR NEW.`aktiviert_am` <> OLD.`aktiviert_am`
       OR OLD.`beendet_am` IS NOT NULL
       OR NEW.`beendet_am` IS NULL
       OR EXISTS (
         SELECT 1
           FROM `nv_dienstschichten` AS next_shift
          WHERE next_shift.`einsatz_id` = OLD.`einsatz_id`
            AND next_shift.`dienstschicht_id` <> OLD.`dienstschicht_id`
            AND next_shift.`status` = 'GEPLANT'
       )
       OR EXISTS (
         SELECT 1
           FROM `nv_dienstuebergabe_anfragen` AS handover_request
          WHERE handover_request.`einsatz_id` = OLD.`einsatz_id`
            AND handover_request.`status` = 'INITIIERT'
       )
       OR EXISTS (
         SELECT 1
           FROM `nv_nachrichten` AS open_message
          WHERE open_message.`einsatz_id` = OLD.`einsatz_id`
            AND (
              open_message.`x00_status` <> 8
              OR open_message.`x02_sperre` IN ('t','1')
              OR open_message.`x03_sperruser` <> ''
            )
       )
       OR EXISTS (
         SELECT 1
           FROM `nv_anhang` AS unfinished_attachment
          WHERE unfinished_attachment.`einsatz_id` = OLD.`einsatz_id`
            AND (
              unfinished_attachment.`status` IN (2, 8)
              OR (
                unfinished_attachment.`status` = 1
                AND unfinished_attachment.`integrity_required` = 1
                AND (
                  unfinished_attachment.`ingest_sha256` IS NULL
                  OR unfinished_attachment.`ingest_sha256`
                     NOT REGEXP BINARY '^[0-9a-f]{64}$'
                  OR unfinished_attachment.`ingest_size` IS NULL
                  OR unfinished_attachment.`integrity_captured_at` IS NULL
                )
              )
            )
       )
       OR EXISTS (
         SELECT 1
           FROM `nv_melderauftraege` AS open_messenger
          WHERE open_messenger.`einsatz_id` = OLD.`einsatz_id`
            AND open_messenger.`status`
                NOT IN ('GEMELDET','ABGEBROCHEN')
       )
       OR EXISTS (
         SELECT 1
           FROM `nv_fernmeldeplaene` AS draft_plan
          WHERE draft_plan.`einsatz_id` = OLD.`einsatz_id`
            AND draft_plan.`status` = 'ENTWURF'
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Active duty shift has unfinished incident work';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty shift status transition';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_shift_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_shift_delete`
BEFORE DELETE ON `nv_dienstschichten`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Duty shift evidence cannot be deleted';
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_hat_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_hat_insert`
BEFORE INSERT ON `nv_dienstbesetzungen`
FOR EACH ROW
BEGIN
  IF NEW.`status` <> 'ZUGEWIESEN'
     OR NEW.`angenommen_am` IS NOT NULL
     OR NEW.`abgeloest_am` IS NOT NULL
     OR NEW.`nachfolger_id` IS NOT NULL
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_benutzer` AS assigned_account
        WHERE BINARY assigned_account.`kuerzel` =
              BINARY NEW.`benutzer_kuerzel`
          AND assigned_account.`estab_gesperrt` = 0
     )
     OR NOT EXISTS (
    SELECT 1
      FROM `nv_dienstschichten` AS shift_row
      JOIN `nv_einsatz_status` AS active_incident
        ON active_incident.`singleton_id` = 1
       AND active_incident.`active_einsatz_id` = shift_row.`einsatz_id`
      JOIN `nv_einsaetze` AS incident
        ON incident.`einsatz_id` = shift_row.`einsatz_id`
     WHERE shift_row.`dienstschicht_id` = NEW.`dienstschicht_id`
       AND shift_row.`status` = 'GEPLANT'
       AND incident.`estab_status` = 'open'
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Duty assignment insert requires a planned active-incident shift';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_hat_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_hat_update`
BEFORE UPDATE ON `nv_dienstbesetzungen`
FOR EACH ROW
BEGIN
  IF NEW.`dienstschicht_id` <> OLD.`dienstschicht_id`
     OR BINARY NEW.`benutzer_kuerzel` <> BINARY OLD.`benutzer_kuerzel`
     OR BINARY NEW.`funktion` <> BINARY OLD.`funktion`
     OR BINARY NEW.`rolle` <> BINARY OLD.`rolle`
     OR NEW.`zugewiesen_am` <> OLD.`zugewiesen_am`
     OR BINARY NEW.`zugewiesen_von` <> BINARY OLD.`zugewiesen_von`
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_dienstschichten` AS shift_row
         JOIN `nv_einsatz_status` AS active_incident
           ON active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = shift_row.`einsatz_id`
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = shift_row.`einsatz_id`
        WHERE shift_row.`dienstschicht_id` = OLD.`dienstschicht_id`
          AND shift_row.`status` IN ('GEPLANT','AKTIV','UEBERGEBEN')
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid or inactive-incident duty assignment transition';
  END IF;
  IF NEW.`status` = OLD.`status` THEN
    IF NOT (NEW.`angenommen_am` <=> OLD.`angenommen_am`)
       OR NOT (NEW.`abgeloest_am` <=> OLD.`abgeloest_am`)
       OR NOT (NEW.`nachfolger_id` <=> OLD.`nachfolger_id`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Duty assignment evidence fields are immutable';
    END IF;
  ELSEIF OLD.`status` = 'ZUGEWIESEN'
      AND NEW.`status` = 'ANGENOMMEN' THEN
    IF OLD.`angenommen_am` IS NOT NULL
       OR NEW.`angenommen_am` IS NULL
       OR NEW.`abgeloest_am` IS NOT NULL
       OR NEW.`nachfolger_id` IS NOT NULL
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_benutzer` AS accepting_account
          WHERE BINARY accepting_account.`kuerzel` =
                BINARY OLD.`benutzer_kuerzel`
            AND accepting_account.`estab_gesperrt` = 0
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid duty assignment acceptance';
    END IF;
  ELSEIF OLD.`status` = 'ZUGEWIESEN'
      AND NEW.`status` = 'ZURUECKGEZOGEN' THEN
    IF NEW.`angenommen_am` IS NOT NULL
       OR OLD.`abgeloest_am` IS NOT NULL
       OR NEW.`abgeloest_am` IS NULL
       OR NEW.`nachfolger_id` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid duty assignment withdrawal';
    END IF;
  ELSEIF OLD.`status` = 'ANGENOMMEN'
      AND NEW.`status` = 'ABGELOEST' THEN
    IF OLD.`angenommen_am` IS NULL
       OR NEW.`angenommen_am` <> OLD.`angenommen_am`
       OR OLD.`abgeloest_am` IS NOT NULL
       OR NEW.`abgeloest_am` IS NULL
       OR (
         NEW.`nachfolger_id` IS NOT NULL
         AND NOT EXISTS (
           SELECT 1
             FROM `nv_dienstbesetzungen` AS successor_assignment
             JOIN `nv_dienstschichten` AS successor_shift
               ON successor_shift.`dienstschicht_id` =
                  successor_assignment.`dienstschicht_id`
             JOIN `nv_benutzer` AS successor_account
               ON BINARY successor_account.`kuerzel` =
                  BINARY successor_assignment.`benutzer_kuerzel`
            WHERE successor_assignment.`dienstbesetzung_id` =
                  NEW.`nachfolger_id`
              AND BINARY successor_assignment.`funktion` =
                  BINARY OLD.`funktion`
              AND BINARY successor_assignment.`rolle` = BINARY OLD.`rolle`
              AND successor_shift.`vorgaenger_id` =
                  OLD.`dienstschicht_id`
              AND successor_shift.`status` = 'AKTIV'
              AND successor_account.`estab_gesperrt` = 0
              AND successor_assignment.`status` = 'ANGENOMMEN'
              AND successor_assignment.`angenommen_am` IS NOT NULL
         )
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid relieved duty assignment evidence';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty assignment status transition';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_hat_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_hat_delete`
BEFORE DELETE ON `nv_dienstbesetzungen`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Duty assignment evidence cannot be deleted';
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_handover_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_handover_insert`
BEFORE INSERT ON `nv_dienstuebergaben`
FOR EACH ROW
BEGIN
  IF NEW.`von_dienstschicht_id` = NEW.`an_dienstschicht_id`
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_dienstschichten` AS old_shift
         JOIN `nv_dienstschichten` AS new_shift
           ON new_shift.`dienstschicht_id` = NEW.`an_dienstschicht_id`
          AND new_shift.`einsatz_id` = old_shift.`einsatz_id`
         JOIN `nv_einsatz_status` AS active_incident
           ON active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = old_shift.`einsatz_id`
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = old_shift.`einsatz_id`
        WHERE old_shift.`dienstschicht_id` = NEW.`von_dienstschicht_id`
          AND old_shift.`einsatz_id` = NEW.`einsatz_id`
          AND old_shift.`status` = 'UEBERGEBEN'
          AND new_shift.`status` = 'AKTIV'
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid or inactive-incident duty handover';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_handover_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_handover_update`
BEFORE UPDATE ON `nv_dienstuebergaben`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Duty handover evidence is immutable';
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_handover_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_handover_delete`
BEFORE DELETE ON `nv_dienstuebergaben`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Duty handover evidence cannot be deleted';
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_handover_request_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_handover_request_insert`
BEFORE INSERT ON `nv_dienstuebergabe_anfragen`
FOR EACH ROW
BEGIN
  IF NEW.`status` <> 'INITIIERT'
     OR NEW.`von_dienstschicht_id` = NEW.`an_dienstschicht_id`
     OR NEW.`bestaetigt_am` IS NOT NULL
     OR NEW.`bestaetigt_von` IS NOT NULL
     OR NEW.`bestaetigt_mit_besetzung_id` IS NOT NULL
     OR NEW.`dienstuebergabe_id` IS NOT NULL
     OR NEW.`storniert_am` IS NOT NULL
     OR NEW.`storniert_von` IS NOT NULL
     OR NEW.`stornierungsgrund` IS NOT NULL
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_dienstschichten` AS old_shift
         JOIN `nv_dienstschichten` AS new_shift
           ON new_shift.`dienstschicht_id` = NEW.`an_dienstschicht_id`
          AND new_shift.`einsatz_id` = old_shift.`einsatz_id`
         JOIN `nv_einsatz_status` AS active_incident
           ON active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = old_shift.`einsatz_id`
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = old_shift.`einsatz_id`
        WHERE old_shift.`dienstschicht_id` = NEW.`von_dienstschicht_id`
          AND old_shift.`einsatz_id` = NEW.`einsatz_id`
          AND old_shift.`status` = 'AKTIV'
          AND new_shift.`status` = 'GEPLANT'
          AND new_shift.`vorgaenger_id` = old_shift.`dienstschicht_id`
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty handover initiation';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_handover_request_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_handover_request_update`
BEFORE UPDATE ON `nv_dienstuebergabe_anfragen`
FOR EACH ROW
BEGIN
  IF NEW.`einsatz_id` <> OLD.`einsatz_id`
     OR NEW.`von_dienstschicht_id` <> OLD.`von_dienstschicht_id`
     OR NEW.`an_dienstschicht_id` <> OLD.`an_dienstschicht_id`
     OR BINARY NEW.`zusammenfassung` <> BINARY OLD.`zusammenfassung`
     OR NEW.`initiiert_am` <> OLD.`initiiert_am`
     OR BINARY NEW.`initiiert_von` <> BINARY OLD.`initiiert_von`
     OR OLD.`status` <> 'INITIIERT' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty handover confirmation';
  END IF;
  IF NEW.`status` = 'BESTAETIGT' THEN
    IF NEW.`bestaetigt_am` IS NULL
       OR NEW.`bestaetigt_von` IS NULL
       OR NEW.`bestaetigt_mit_besetzung_id` IS NULL
       OR NEW.`dienstuebergabe_id` IS NULL
       OR NEW.`storniert_am` IS NOT NULL
       OR NEW.`storniert_von` IS NOT NULL
       OR NEW.`stornierungsgrund` IS NOT NULL
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_dienstuebergaben` AS completed_handover
           JOIN `nv_dienstbesetzungen` AS confirming_assignment
             ON confirming_assignment.`dienstbesetzung_id` =
                NEW.`bestaetigt_mit_besetzung_id`
           JOIN `nv_benutzer` AS confirming_account
             ON BINARY confirming_account.`kuerzel` =
                BINARY confirming_assignment.`benutzer_kuerzel`
          WHERE completed_handover.`dienstuebergabe_id` =
                NEW.`dienstuebergabe_id`
            AND completed_handover.`einsatz_id` = NEW.`einsatz_id`
            AND completed_handover.`von_dienstschicht_id` =
                NEW.`von_dienstschicht_id`
            AND completed_handover.`an_dienstschicht_id` =
                NEW.`an_dienstschicht_id`
            AND BINARY completed_handover.`uebergeben_von` =
                BINARY NEW.`initiiert_von`
            AND BINARY completed_handover.`angenommen_von` =
                BINARY NEW.`bestaetigt_von`
            AND confirming_assignment.`dienstschicht_id` =
                NEW.`an_dienstschicht_id`
            AND confirming_assignment.`angenommen_am` IS NOT NULL
            AND BINARY confirming_assignment.`benutzer_kuerzel` =
                BINARY NEW.`bestaetigt_von`
            AND confirming_account.`aktiv` = 1
            AND confirming_account.`estab_gesperrt` = 0
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid duty handover confirmation';
    END IF;
  ELSEIF NEW.`status` = 'STORNIERT' THEN
    IF NEW.`bestaetigt_am` IS NOT NULL
       OR NEW.`bestaetigt_von` IS NOT NULL
       OR NEW.`bestaetigt_mit_besetzung_id` IS NOT NULL
       OR NEW.`dienstuebergabe_id` IS NOT NULL
       OR NEW.`storniert_am` IS NULL
       OR NEW.`storniert_von` IS NULL
       OR NEW.`stornierungsgrund` IS NULL
       OR CHAR_LENGTH(TRIM(NEW.`stornierungsgrund`)) = 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid duty handover cancellation';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid duty handover request transition';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_handover_request_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_handover_request_delete`
BEFORE DELETE ON `nv_dienstuebergabe_anfragen`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Duty handover request evidence cannot be deleted';
END//
DELIMITER ;

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
           ON incident.`einsatz_id` =
              active_incident.`active_einsatz_id`
         JOIN `nv_dienstschichten` AS creator_shift
           ON creator_shift.`einsatz_id` = incident.`einsatz_id`
          AND creator_shift.`status` = 'AKTIV'
         JOIN `nv_dienstbesetzungen` AS creator_hat
           ON creator_hat.`dienstschicht_id` =
              creator_shift.`dienstschicht_id`
          AND creator_hat.`status` = 'ANGENOMMEN'
         JOIN `nv_benutzer` AS creator_account
           ON BINARY creator_account.`kuerzel` =
              BINARY creator_hat.`benutzer_kuerzel`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`
          AND incident.`estab_status` = 'open'
          AND BINARY creator_hat.`funktion` = BINARY 'S6'
          AND BINARY creator_hat.`rolle` = BINARY 'Stab'
          AND BINARY creator_hat.`benutzer_kuerzel` =
              BINARY NEW.`erstellt_von`
          AND creator_account.`aktiv` = 1
          AND creator_account.`estab_gesperrt` = 0
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Telecommunications plan insert requires the active open incident';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_delete`
BEFORE DELETE ON `nv_fernmeldeplaene`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Telecommunications plan evidence cannot be deleted';
END//
DELIMITER ;

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
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`
          AND incident.`estab_status` = 'open'
          AND message_row.`04_richtung` = 'A'
          AND message_row.`06_befwegausw` = 'Me'
          AND message_row.`x00_status` = 2
          AND message_row.`x01_abschluss` IN ('f','0')
          AND NOT EXISTS (
            SELECT 1
              FROM `nv_melderauftraege` AS completed_job
             WHERE completed_job.`einsatz_id` = NEW.`einsatz_id`
               AND completed_job.`nachricht_id` = NEW.`nachricht_id`
               AND completed_job.`status` = 'GEMELDET'
          )
          AND EXISTS (
            SELECT 1
              FROM `nv_dienstschichten` AS messenger_shift
              JOIN `nv_dienstbesetzungen` AS messenger_hat
                ON messenger_hat.`dienstschicht_id` =
                   messenger_shift.`dienstschicht_id`
              JOIN `nv_benutzer` AS messenger_user
                ON BINARY messenger_user.`kuerzel` =
                   BINARY messenger_hat.`benutzer_kuerzel`
             WHERE messenger_shift.`einsatz_id` = NEW.`einsatz_id`
               AND messenger_shift.`status` = 'AKTIV'
               AND messenger_hat.`status` = 'ANGENOMMEN'
               AND BINARY messenger_hat.`funktion` = BINARY 'A/W'
               AND BINARY messenger_hat.`rolle` = BINARY 'Fernmelder'
               AND BINARY messenger_hat.`benutzer_kuerzel` =
                   BINARY NEW.`melder_kuerzel`
               AND messenger_user.`aktiv` = 1
               AND messenger_user.`estab_gesperrt` = 0
          )
          AND EXISTS (
            SELECT 1
              FROM `nv_dienstschichten` AS supervisor_shift
              JOIN `nv_dienstbesetzungen` AS supervisor_hat
                ON supervisor_hat.`dienstschicht_id` =
                   supervisor_shift.`dienstschicht_id`
             WHERE supervisor_shift.`einsatz_id` = NEW.`einsatz_id`
               AND supervisor_shift.`status` = 'AKTIV'
               AND supervisor_hat.`status` = 'ANGENOMMEN'
               AND BINARY supervisor_hat.`funktion` = BINARY 'LdF'
               AND BINARY supervisor_hat.`rolle` = BINARY 'Fernmelder'
               AND BINARY supervisor_hat.`benutzer_kuerzel` =
                   BINARY NEW.`beauftragt_von`
          )
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Messenger assignment requires an active-incident outgoing Me message';
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
           FROM `nv_dienstschichten` AS report_shift
           JOIN `nv_dienstbesetzungen` AS report_hat
             ON report_hat.`dienstschicht_id` =
                report_shift.`dienstschicht_id`
           JOIN `nv_benutzer` AS report_account
             ON BINARY report_account.`kuerzel` =
                BINARY report_hat.`benutzer_kuerzel`
          WHERE report_shift.`einsatz_id` = OLD.`einsatz_id`
            AND report_shift.`status` = 'AKTIV'
            AND report_hat.`status` = 'ANGENOMMEN'
            AND BINARY report_hat.`funktion` = BINARY 'LdF'
            AND BINARY report_hat.`rolle` = BINARY 'Fernmelder'
            AND BINARY report_hat.`benutzer_kuerzel` =
                BINARY NEW.`gemeldet_an`
            AND report_account.`aktiv` = 1
            AND report_account.`estab_gesperrt` = 0
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid messenger report evidence';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid messenger status transition';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_messenger_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_messenger_delete`
BEFORE DELETE ON `nv_melderauftraege`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Messenger evidence cannot be deleted';
END//
DELIMITER ;

-- Hash-chained command-post event ledger. The head may advance only to an
-- already inserted, cryptographically linked next event; event rows themselves
-- are immutable.
DROP TRIGGER IF EXISTS `estab_dv94_event_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_event_insert`
BEFORE INSERT ON `nv_betriebsereignisse`
FOR EACH ROW
BEGIN
  DECLARE expected_sequence BIGINT UNSIGNED;
  DECLARE expected_previous_hash CHAR(64);
  DECLARE expected_event_hash CHAR(64);

  SELECT `letzte_sequenz` + 1, `letzter_hash`
    INTO expected_sequence, expected_previous_hash
    FROM `nv_betriebsereignis_kopf`
   WHERE `einsatz_id` = NEW.`einsatz_id`;

  SET expected_event_hash = SHA2(
    CONCAT(
      NEW.`einsatz_id`, '|',
      NEW.`sequenz`, '|',
      NEW.`objekttyp`, '|',
      NEW.`objekt_id`, '|',
      NEW.`aktion`, '|',
      NEW.`akteur_kuerzel`, '|',
      COALESCE(NEW.`akteur_funktion`, ''), '|',
      DATE_FORMAT(NEW.`ereigniszeit`, '%Y-%m-%d %H:%i:%s.%f'), '|',
      CAST(NEW.`details` AS CHAR), '|',
      NEW.`vorheriger_hash`
    ),
    256
  );

  IF expected_sequence IS NULL
     OR NEW.`sequenz` <> expected_sequence
     OR BINARY NEW.`vorheriger_hash` <> BINARY expected_previous_hash
     OR BINARY NEW.`ereignis_hash` <> BINARY expected_event_hash
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_einsatz_status` AS active_incident
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid command-post event hash chain';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_event_head_insert`;
DELIMITER //
CREATE TRIGGER `estab_dv94_event_head_insert`
BEFORE INSERT ON `nv_betriebsereignis_kopf`
FOR EACH ROW
BEGIN
  IF NEW.`letzte_sequenz` <> 0
     OR BINARY NEW.`letzter_hash` <> BINARY
       '0000000000000000000000000000000000000000000000000000000000000000'
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_einsatz_status` AS active_incident
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = NEW.`einsatz_id`
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Command-post event head requires the active open incident';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_event_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_event_update`
BEFORE UPDATE ON `nv_betriebsereignisse`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Command-post events are append-only';
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_event_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_event_delete`
BEFORE DELETE ON `nv_betriebsereignisse`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Command-post events are append-only';
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_event_head_update`;
DELIMITER //
CREATE TRIGGER `estab_dv94_event_head_update`
BEFORE UPDATE ON `nv_betriebsereignis_kopf`
FOR EACH ROW
BEGIN
  IF NEW.`einsatz_id` <> OLD.`einsatz_id`
     OR NEW.`letzte_sequenz` <> OLD.`letzte_sequenz` + 1
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_betriebsereignisse` AS event_row
        WHERE event_row.`einsatz_id` = OLD.`einsatz_id`
          AND event_row.`sequenz` = NEW.`letzte_sequenz`
          AND BINARY event_row.`vorheriger_hash`
            = BINARY OLD.`letzter_hash`
          AND BINARY event_row.`ereignis_hash`
            = BINARY NEW.`letzter_hash`
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid command-post event head advance';
  END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS `estab_dv94_event_head_delete`;
DELIMITER //
CREATE TRIGGER `estab_dv94_event_head_delete`
BEFORE DELETE ON `nv_betriebsereignis_kopf`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Command-post event heads cannot be deleted';
END//
DELIMITER ;
