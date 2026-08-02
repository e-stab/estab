-- Editable global categories used by the established incident workflow.
--
-- Category definitions are reusable global configuration, not incident data.
-- This one-time migration seeds the historical default catalogue only when
-- the global category table is completely empty. Any existing catalogue is
-- operator-owned and remains byte-for-byte untouched.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_116_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_116_preflight()
BEGIN
  DECLARE canonical_tables INTEGER DEFAULT 0;
  DECLARE total_columns INTEGER DEFAULT 0;
  DECLARE canonical_columns INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_tables
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_masterkatego'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci';

  SELECT COUNT(*) INTO total_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_masterkatego';

  SELECT COUNT(*) INTO canonical_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_masterkatego'
     AND (
       (`column_name` = 'lfd'
        AND ordinal_position = 1
        AND is_nullable = 'NO'
        AND data_type = 'bigint'
        AND column_type LIKE 'bigint%unsigned'
        AND extra = 'auto_increment')
       OR (`column_name` = 'kategorie'
        AND ordinal_position = 2
        AND is_nullable = 'NO'
        AND data_type = 'varchar'
        AND character_maximum_length = 10
        AND character_set_name = 'utf8mb4'
        AND collation_name = 'utf8mb4_unicode_ci')
       OR (`column_name` = 'beschreibung'
        AND ordinal_position = 3
        AND is_nullable = 'YES'
        AND data_type = 'varchar'
        AND character_maximum_length = 254
        AND character_set_name = 'utf8mb4'
        AND collation_name = 'utf8mb4_unicode_ci')
     );

  IF canonical_tables <> 1
     OR total_columns <> 3
     OR canonical_columns <> 3 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Standard-category migration blocked: invalid master category schema';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_116_preflight();
DROP PROCEDURE estab_migrate_116_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_116_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_116_validate(IN catalogue_was_empty TINYINT)
BEGIN
  DECLARE canonical_defaults INTEGER DEFAULT 0;
  DECLARE total_categories BIGINT DEFAULT 0;

  IF catalogue_was_empty = 1 THEN
    SELECT COUNT(*) INTO total_categories FROM `nv_masterkatego`;
    SELECT COUNT(*) INTO canonical_defaults
      FROM `nv_masterkatego`
     WHERE (`kategorie` = 'Allgemein'
            AND `beschreibung` =
              'Allgemeine Meldungen ohne Zuordnung zu einem Einsatzabschnitt.')
        OR (`kategorie` = 'EA1'
            AND `beschreibung` =
              'Einsatzabschnitt 1 – Bezeichnung an die Einsatzorganisation anpassen.')
        OR (`kategorie` = 'EA2'
            AND `beschreibung` =
              'Einsatzabschnitt 2 – Bezeichnung an die Einsatzorganisation anpassen.')
        OR (`kategorie` = 'EA3'
            AND `beschreibung` =
              'Einsatzabschnitt 3 – Bezeichnung an die Einsatzorganisation anpassen.')
        OR (`kategorie` = 'EA4'
            AND `beschreibung` =
              'Einsatzabschnitt 4 – Bezeichnung an die Einsatzorganisation anpassen.')
        OR (`kategorie` = 'EA5'
            AND `beschreibung` =
              'Einsatzabschnitt 5 – Bezeichnung an die Einsatzorganisation anpassen.')
        OR (`kategorie` = 'EA6'
            AND `beschreibung` =
              'Einsatzabschnitt 6 – Bezeichnung an die Einsatzorganisation anpassen.');

    IF total_categories <> 7 OR canonical_defaults <> 7 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Standard-category migration blocked: defaults are incomplete';
    END IF;
  END IF;
END//
DELIMITER ;

SET @estab_migrate_116_catalogue_was_empty :=
  (SELECT COUNT(*) = 0 FROM `nv_masterkatego`);

START TRANSACTION;

INSERT INTO `nv_masterkatego` (`kategorie`, `beschreibung`)
SELECT standard_categories.`kategorie`, standard_categories.`beschreibung`
  FROM (
    SELECT
      'Allgemein' AS `kategorie`,
      'Allgemeine Meldungen ohne Zuordnung zu einem Einsatzabschnitt.' AS `beschreibung`
    UNION ALL SELECT
      'EA1',
      'Einsatzabschnitt 1 – Bezeichnung an die Einsatzorganisation anpassen.'
    UNION ALL SELECT
      'EA2',
      'Einsatzabschnitt 2 – Bezeichnung an die Einsatzorganisation anpassen.'
    UNION ALL SELECT
      'EA3',
      'Einsatzabschnitt 3 – Bezeichnung an die Einsatzorganisation anpassen.'
    UNION ALL SELECT
      'EA4',
      'Einsatzabschnitt 4 – Bezeichnung an die Einsatzorganisation anpassen.'
    UNION ALL SELECT
      'EA5',
      'Einsatzabschnitt 5 – Bezeichnung an die Einsatzorganisation anpassen.'
    UNION ALL SELECT
      'EA6',
      'Einsatzabschnitt 6 – Bezeichnung an die Einsatzorganisation anpassen.'
  ) AS standard_categories
 WHERE @estab_migrate_116_catalogue_was_empty = 1;

CALL estab_migrate_116_validate(
  @estab_migrate_116_catalogue_was_empty
);

COMMIT;

DROP PROCEDURE estab_migrate_116_validate;
SET @estab_migrate_116_catalogue_was_empty := NULL;
