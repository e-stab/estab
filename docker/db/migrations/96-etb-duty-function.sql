-- Model ETB recording as its own selectable command-post duty hat.
--
-- S2 remains the sole red-copy target and a mandatory shift function. A new
-- EINSATZTAGEBUCH capability is shared by S2 and ETB, allowing the DV 1-101
-- ETB/Si double function without granting ETB the S2-only
-- LAGE_DOKUMENTATION, red-copy delivery or ordinary S2 recipient rights.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_96_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_96_preflight()
BEGIN
  DECLARE owned_table INTEGER DEFAULT 0;
  DECLARE exact_columns INTEGER DEFAULT 0;
  DECLARE total_columns INTEGER DEFAULT 0;
  DECLARE exact_primary_parts INTEGER DEFAULT 0;
  DECLARE total_primary_parts INTEGER DEFAULT 0;
  DECLARE expected_unique_parts INTEGER DEFAULT 0;
  DECLARE total_index_parts INTEGER DEFAULT 0;
  DECLARE old_enum INTEGER DEFAULT 0;
  DECLARE new_enum INTEGER DEFAULT 0;
  DECLARE base_rows INTEGER DEFAULT 0;
  DECLARE final_rows INTEGER DEFAULT 0;
  DECLARE total_rows INTEGER DEFAULT 0;
  DECLARE known_state INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO owned_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci'
     AND table_comment =
       'estab:migration:94-dv-organisational-controls:v1';
  IF owned_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'ETB duty migration blocked: capability table is missing or foreign';
  END IF;

  SELECT COUNT(*) INTO total_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten';
  SELECT COUNT(*) INTO exact_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten'
     AND (
       (
         column_name = 'funktion'
         AND ordinal_position = 1
         AND data_type = 'varchar'
         AND column_type = 'varchar(6)'
         AND character_maximum_length = 6
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND is_nullable = 'NO'
         AND column_default IS NULL
         AND extra = ''
       )
       OR (
         column_name = 'rolle'
         AND ordinal_position = 2
         AND data_type = 'enum'
         AND column_type = 'enum(''Stab'',''FB'',''Fernmelder'')'
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND is_nullable = 'NO'
         AND column_default IS NULL
         AND extra = ''
       )
       OR (
         column_name = 'faehigkeit'
         AND ordinal_position = 3
         AND data_type = 'enum'
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND is_nullable = 'NO'
         AND column_default IS NULL
         AND extra = ''
         AND column_type IN (
           'enum(''LAGE_DOKUMENTATION'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')',
           'enum(''LAGE_DOKUMENTATION'',''EINSATZTAGEBUCH'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')'
         )
       )
       OR (
         column_name = 'bezeichnung'
         AND ordinal_position = 4
         AND data_type = 'varchar'
         AND column_type = 'varchar(100)'
         AND character_maximum_length = 100
         AND character_set_name = 'utf8mb4'
         AND collation_name = 'utf8mb4_unicode_ci'
         AND is_nullable = 'NO'
         AND column_default IS NULL
         AND extra = ''
       )
     );
  IF total_columns <> 4 OR exact_columns <> 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'ETB duty migration blocked: capability columns are incompatible';
  END IF;

  SELECT COUNT(*) INTO total_primary_parts
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten'
     AND index_name = 'PRIMARY';
  SELECT COUNT(*) INTO exact_primary_parts
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten'
     AND index_name = 'PRIMARY'
     AND non_unique = 0
     AND (
       (seq_in_index = 1 AND column_name = 'funktion')
       OR (seq_in_index = 2 AND column_name = 'faehigkeit')
     );
  IF total_primary_parts <> 2 OR exact_primary_parts <> 2 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'ETB duty migration blocked: capability primary key is incompatible';
  END IF;

  SELECT COUNT(*) INTO expected_unique_parts
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten'
     AND index_name = 'uq_funktionsfaehigkeit_eindeutig'
     AND non_unique = 0
     AND seq_in_index = 1
     AND column_name = 'faehigkeit';
  SELECT COUNT(*) INTO total_index_parts
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten';
  IF expected_unique_parts NOT IN (0, 1)
     OR total_index_parts <> 2 + expected_unique_parts THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'ETB duty migration blocked: capability indexes are foreign';
  END IF;

  SELECT COUNT(*) INTO old_enum
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten'
     AND column_name = 'faehigkeit'
     AND column_type =
       'enum(''LAGE_DOKUMENTATION'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')';
  SELECT COUNT(*) INTO new_enum
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_funktionsfaehigkeiten'
     AND column_name = 'faehigkeit'
     AND column_type =
       'enum(''LAGE_DOKUMENTATION'',''EINSATZTAGEBUCH'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')';

  SELECT COUNT(*) INTO total_rows
    FROM `nv_funktionsfaehigkeiten`;
  SELECT COUNT(*) INTO base_rows
    FROM `nv_funktionsfaehigkeiten`
   WHERE (`funktion`, `rolle`, `faehigkeit`, `bezeichnung`) IN (
     ('S2',  'Stab',       'LAGE_DOKUMENTATION',
       'Lage und Dokumentation'),
     ('Si',  'Stab',       'SICHTUNG', 'Sichter'),
     ('S6',  'Stab',       'FERNMELDEPLANUNG', 'Fernmeldeplanung'),
     ('LdF', 'Fernmelder', 'FERNMELDEBETRIEB',
       'Leiter der Fernmeldezentrale'),
     ('A/W', 'Fernmelder', 'BEFOERDERUNG', 'Aufnahme und Weitergabe')
   );
  SELECT COUNT(*) INTO final_rows
    FROM `nv_funktionsfaehigkeiten`
   WHERE (`funktion`, `rolle`, `faehigkeit`, `bezeichnung`) IN (
     ('S2',  'Stab',       'LAGE_DOKUMENTATION',
       'Lage und Dokumentation'),
     ('S2',  'Stab',       'EINSATZTAGEBUCH',
       'Einsatztagebuchführung'),
     ('ETB', 'Stab',       'EINSATZTAGEBUCH',
       'Einsatztagebuchführung'),
     ('Si',  'Stab',       'SICHTUNG', 'Sichter'),
     ('S6',  'Stab',       'FERNMELDEPLANUNG', 'Fernmeldeplanung'),
     ('LdF', 'Fernmelder', 'FERNMELDEBETRIEB',
       'Leiter der Fernmeldezentrale'),
     ('A/W', 'Fernmelder', 'BEFOERDERUNG', 'Aufnahme und Weitergabe')
   );

  -- These are the only safe states: original migration 94, either exact
  -- autocommitted prefix of this migration after process loss, or the exact
  -- final state before its ledger row was marked applied.
  SET known_state = (
       total_rows = 5
       AND base_rows = 5
       AND (
         (old_enum = 1 AND expected_unique_parts IN (0, 1))
         OR (new_enum = 1 AND expected_unique_parts = 0)
       )
     )
     OR (
       total_rows = 7
       AND final_rows = 7
       AND new_enum = 1
       AND expected_unique_parts = 0
     );
  IF known_state <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'ETB duty migration blocked: capability catalogue is not canonical';
  END IF;

  IF expected_unique_parts = 1 THEN
    ALTER TABLE `nv_funktionsfaehigkeiten`
      DROP INDEX `uq_funktionsfaehigkeit_eindeutig`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_96_preflight();
DROP PROCEDURE estab_migrate_96_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_96_extend_enum;
DELIMITER //
CREATE PROCEDURE estab_migrate_96_extend_enum()
BEGIN
  IF EXISTS (
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_funktionsfaehigkeiten'
       AND column_name = 'faehigkeit'
       AND column_type =
         'enum(''LAGE_DOKUMENTATION'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')'
  ) THEN
    ALTER TABLE `nv_funktionsfaehigkeiten`
      MODIFY `faehigkeit` ENUM(
        'LAGE_DOKUMENTATION',
        'EINSATZTAGEBUCH',
        'SICHTUNG',
        'FERNMELDEPLANUNG',
        'FERNMELDEBETRIEB',
        'BEFOERDERUNG'
      ) NOT NULL;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_96_extend_enum();
DROP PROCEDURE estab_migrate_96_extend_enum;

INSERT INTO `nv_funktionsfaehigkeiten`
  (`funktion`, `rolle`, `faehigkeit`, `bezeichnung`)
VALUES
  ('S2',  'Stab', 'EINSATZTAGEBUCH', 'Einsatztagebuchführung'),
  ('ETB', 'Stab', 'EINSATZTAGEBUCH', 'Einsatztagebuchführung')
ON DUPLICATE KEY UPDATE
  `rolle` = VALUES(`rolle`),
  `bezeichnung` = VALUES(`bezeichnung`);

DROP PROCEDURE IF EXISTS estab_migrate_96_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_96_validate()
BEGIN
  IF (
       SELECT COUNT(*) FROM `nv_funktionsfaehigkeiten`
     ) <> 7
     OR (
       SELECT COUNT(*) FROM `nv_funktionsfaehigkeiten`
        WHERE (`funktion`, `rolle`, `faehigkeit`, `bezeichnung`) IN (
          ('S2',  'Stab',       'LAGE_DOKUMENTATION',
            'Lage und Dokumentation'),
          ('S2',  'Stab',       'EINSATZTAGEBUCH',
            'Einsatztagebuchführung'),
          ('ETB', 'Stab',       'EINSATZTAGEBUCH',
            'Einsatztagebuchführung'),
          ('Si',  'Stab',       'SICHTUNG', 'Sichter'),
          ('S6',  'Stab',       'FERNMELDEPLANUNG',
            'Fernmeldeplanung'),
          ('LdF', 'Fernmelder', 'FERNMELDEBETRIEB',
            'Leiter der Fernmeldezentrale'),
          ('A/W', 'Fernmelder', 'BEFOERDERUNG',
            'Aufnahme und Weitergabe')
        )
     ) <> 7
     OR NOT EXISTS (
       SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_funktionsfaehigkeiten'
          AND column_name = 'faehigkeit'
          AND data_type = 'enum'
          AND column_type =
            'enum(''LAGE_DOKUMENTATION'',''EINSATZTAGEBUCH'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')'
          AND is_nullable = 'NO'
     )
     OR EXISTS (
       SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_funktionsfaehigkeiten'
          AND index_name <> 'PRIMARY'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'ETB duty migration failed: capability catalogue is incomplete';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_96_validate();
DROP PROCEDURE estab_migrate_96_validate;
