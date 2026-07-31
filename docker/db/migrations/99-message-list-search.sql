-- Canonical indexes for incident-scoped message lists and search.
--
-- MariaDB commits every ALTER TABLE independently. Each phase therefore
-- accepts a missing index or its exact canonical definition, while a foreign
-- object reusing one of the owned names blocks before any schema change. The
-- released single-column full-text index is removed only after the wider
-- replacement exists, so an interrupted upgrade remains safely resumable.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS estab_migrate_99_preflight;
DELIMITER //
CREATE PROCEDURE estab_migrate_99_preflight()
BEGIN
  DECLARE canonical_table INTEGER DEFAULT 0;
  DECLARE required_columns INTEGER DEFAULT 0;
  DECLARE searchable_columns INTEGER DEFAULT 0;
  DECLARE canonical_incident_column INTEGER DEFAULT 0;
  DECLARE existing_legacy_fulltext INTEGER DEFAULT 0;
  DECLARE canonical_legacy_fulltext INTEGER DEFAULT 0;
  DECLARE existing_search_fulltext INTEGER DEFAULT 0;
  DECLARE canonical_search_fulltext INTEGER DEFAULT 0;
  DECLARE existing_status_time INTEGER DEFAULT 0;
  DECLARE canonical_status_time INTEGER DEFAULT 0;
  DECLARE existing_direction_number INTEGER DEFAULT 0;
  DECLARE canonical_direction_number INTEGER DEFAULT 0;

  SELECT COUNT(*) INTO canonical_table
    FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND table_type = 'BASE TABLE'
     AND engine = 'InnoDB'
     AND table_collation = 'utf8mb4_unicode_ci';
  IF canonical_table <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: message table is missing or incompatible';
  END IF;

  SELECT COUNT(*) INTO required_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name IN (
       '00_lfd', '04_richtung', '04_nummer', '05_gegenstelle',
       '10_anschrift', '11_rufnummer', '12_betreff', '12_inhalt',
       '12_abfzeit', '13_abseinheit', '14_funktion', 'x00_status',
       'einsatz_id'
     );
  IF required_columns <> 13 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: required message column is missing';
  END IF;

  SELECT COUNT(*) INTO searchable_columns
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name IN (
       '05_gegenstelle', '10_anschrift', '11_rufnummer', '12_betreff',
       '12_inhalt', '13_abseinheit', '14_funktion'
     )
     AND data_type IN ('varchar', 'text', 'mediumtext', 'longtext')
     AND character_set_name = 'utf8mb4'
     AND collation_name = 'utf8mb4_unicode_ci';
  IF searchable_columns <> 7 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: searchable columns are incompatible';
  END IF;

  SELECT COUNT(*) INTO canonical_incident_column
    FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND column_name = 'einsatz_id'
     AND data_type = 'bigint'
     AND column_type LIKE '%unsigned%'
     AND is_nullable = 'YES';
  IF canonical_incident_column <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: incident column is incompatible';
  END IF;

  SELECT COUNT(*) INTO existing_legacy_fulltext
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'ft_nachrichten_inhalt';
  SELECT COUNT(*) INTO canonical_legacy_fulltext
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'ft_nachrichten_inhalt'
     AND index_type = 'FULLTEXT'
     AND non_unique = 1
     AND seq_in_index = 1
     AND column_name = '12_inhalt'
     AND sub_part IS NULL;
  IF existing_legacy_fulltext NOT IN (0, 1)
     OR canonical_legacy_fulltext <> existing_legacy_fulltext THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: foreign legacy full-text index collision';
  END IF;

  SELECT COUNT(*) INTO existing_search_fulltext
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'ft_nachrichten_suche';
  SELECT COUNT(*) INTO canonical_search_fulltext
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'ft_nachrichten_suche'
     AND index_type = 'FULLTEXT'
     AND non_unique = 1
     AND sub_part IS NULL
     AND (
       (seq_in_index = 1 AND column_name = '05_gegenstelle')
       OR (seq_in_index = 2 AND column_name = '10_anschrift')
       OR (seq_in_index = 3 AND column_name = '11_rufnummer')
       OR (seq_in_index = 4 AND column_name = '12_betreff')
       OR (seq_in_index = 5 AND column_name = '12_inhalt')
       OR (seq_in_index = 6 AND column_name = '13_abseinheit')
       OR (seq_in_index = 7 AND column_name = '14_funktion')
     );
  IF existing_search_fulltext NOT IN (0, 7)
     OR canonical_search_fulltext <> existing_search_fulltext THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: foreign search full-text index collision';
  END IF;

  SELECT COUNT(*) INTO existing_status_time
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'idx_nachrichten_einsatz_status_zeit';
  SELECT COUNT(*) INTO canonical_status_time
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'idx_nachrichten_einsatz_status_zeit'
     AND index_type = 'BTREE'
     AND non_unique = 1
     AND sub_part IS NULL
     AND (
       (seq_in_index = 1 AND column_name = 'einsatz_id')
       OR (seq_in_index = 2 AND column_name = 'x00_status')
       OR (seq_in_index = 3 AND column_name = '12_abfzeit')
       OR (seq_in_index = 4 AND column_name = '00_lfd')
     );
  IF existing_status_time NOT IN (0, 4)
     OR canonical_status_time <> existing_status_time THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: foreign status-time index collision';
  END IF;

  SELECT COUNT(*) INTO existing_direction_number
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'idx_nachrichten_einsatz_richtung_nummer';
  SELECT COUNT(*) INTO canonical_direction_number
    FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'nv_nachrichten'
     AND index_name = 'idx_nachrichten_einsatz_richtung_nummer'
     AND index_type = 'BTREE'
     AND non_unique = 1
     AND sub_part IS NULL
     AND (
       (seq_in_index = 1 AND column_name = 'einsatz_id')
       OR (seq_in_index = 2 AND column_name = '04_richtung')
       OR (seq_in_index = 3 AND column_name = '04_nummer')
       OR (seq_in_index = 4 AND column_name = '00_lfd')
     );
  IF existing_direction_number NOT IN (0, 4)
     OR canonical_direction_number <> existing_direction_number THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration blocked: foreign direction-number index collision';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_99_preflight();
DROP PROCEDURE estab_migrate_99_preflight;

DROP PROCEDURE IF EXISTS estab_migrate_99_add_search;
DELIMITER //
CREATE PROCEDURE estab_migrate_99_add_search()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'ft_nachrichten_suche'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD FULLTEXT INDEX `ft_nachrichten_suche` (
        `05_gegenstelle`, `10_anschrift`, `11_rufnummer`, `12_betreff`,
        `12_inhalt`, `13_abseinheit`, `14_funktion`
      );
  END IF;
END//
DELIMITER ;

CALL estab_migrate_99_add_search();
DROP PROCEDURE estab_migrate_99_add_search;

DROP PROCEDURE IF EXISTS estab_migrate_99_drop_legacy_search;
DELIMITER //
CREATE PROCEDURE estab_migrate_99_drop_legacy_search()
BEGIN
  IF EXISTS (
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'ft_nachrichten_inhalt'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      DROP INDEX `ft_nachrichten_inhalt`;
  END IF;
END//
DELIMITER ;

CALL estab_migrate_99_drop_legacy_search();
DROP PROCEDURE estab_migrate_99_drop_legacy_search;

DROP PROCEDURE IF EXISTS estab_migrate_99_add_status_time;
DELIMITER //
CREATE PROCEDURE estab_migrate_99_add_status_time()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'idx_nachrichten_einsatz_status_zeit'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD INDEX `idx_nachrichten_einsatz_status_zeit` (
        `einsatz_id`, `x00_status`, `12_abfzeit`, `00_lfd`
      );
  END IF;
END//
DELIMITER ;

CALL estab_migrate_99_add_status_time();
DROP PROCEDURE estab_migrate_99_add_status_time;

DROP PROCEDURE IF EXISTS estab_migrate_99_add_direction_number;
DELIMITER //
CREATE PROCEDURE estab_migrate_99_add_direction_number()
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'idx_nachrichten_einsatz_richtung_nummer'
  ) THEN
    ALTER TABLE `nv_nachrichten`
      ADD INDEX `idx_nachrichten_einsatz_richtung_nummer` (
        `einsatz_id`, `04_richtung`, `04_nummer`, `00_lfd`
      );
  END IF;
END//
DELIMITER ;

CALL estab_migrate_99_add_direction_number();
DROP PROCEDURE estab_migrate_99_add_direction_number;

DROP PROCEDURE IF EXISTS estab_migrate_99_validate;
DELIMITER //
CREATE PROCEDURE estab_migrate_99_validate()
BEGIN
  IF (
       SELECT COUNT(*)
         FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_nachrichten'
          AND index_name = 'ft_nachrichten_inhalt'
     ) <> 0
     OR (
       SELECT COUNT(*)
         FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_nachrichten'
          AND index_name = 'ft_nachrichten_suche'
          AND index_type = 'FULLTEXT'
          AND non_unique = 1
          AND sub_part IS NULL
          AND (
            (seq_in_index = 1 AND column_name = '05_gegenstelle')
            OR (seq_in_index = 2 AND column_name = '10_anschrift')
            OR (seq_in_index = 3 AND column_name = '11_rufnummer')
            OR (seq_in_index = 4 AND column_name = '12_betreff')
            OR (seq_in_index = 5 AND column_name = '12_inhalt')
            OR (seq_in_index = 6 AND column_name = '13_abseinheit')
            OR (seq_in_index = 7 AND column_name = '14_funktion')
          )
     ) <> 7
     OR (
       SELECT COUNT(*)
         FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_nachrichten'
          AND index_name = 'idx_nachrichten_einsatz_status_zeit'
          AND index_type = 'BTREE'
          AND non_unique = 1
          AND sub_part IS NULL
          AND (
            (seq_in_index = 1 AND column_name = 'einsatz_id')
            OR (seq_in_index = 2 AND column_name = 'x00_status')
            OR (seq_in_index = 3 AND column_name = '12_abfzeit')
            OR (seq_in_index = 4 AND column_name = '00_lfd')
          )
     ) <> 4
     OR (
       SELECT COUNT(*)
         FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_nachrichten'
          AND index_name = 'idx_nachrichten_einsatz_richtung_nummer'
          AND index_type = 'BTREE'
          AND non_unique = 1
          AND sub_part IS NULL
          AND (
            (seq_in_index = 1 AND column_name = 'einsatz_id')
            OR (seq_in_index = 2 AND column_name = '04_richtung')
            OR (seq_in_index = 3 AND column_name = '04_nummer')
            OR (seq_in_index = 4 AND column_name = '00_lfd')
          )
     ) <> 4 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration failed: final indexes are not canonical';
  END IF;

  IF (
       SELECT COUNT(*)
         FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'nv_nachrichten'
          AND index_name IN (
            'ft_nachrichten_suche',
            'idx_nachrichten_einsatz_status_zeit',
            'idx_nachrichten_einsatz_richtung_nummer'
          )
     ) <> 15 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Message-list index migration failed: unexpected index cardinality';
  END IF;
END//
DELIMITER ;

CALL estab_migrate_99_validate();
DROP PROCEDURE estab_migrate_99_validate;
