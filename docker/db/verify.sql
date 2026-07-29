-- Read-only structural verification. A healthy fresh database returns 1 for
-- every *_ok column and zero rows from the second query.

SELECT
  ((SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN (
         'nv_nachrichten', 'nv_empfmtx', 'nv_empfmtx_standard', 'nv_benutzer',
         'nv_masterkatego', 'nv_masterkategolink', 'nv_protokoll',
         'nv_anhang', 'nv_etb', 'nv_tbb', 'nv_ubb', 'nv_komplan',
         'nv_bhp50', 'nv_etbtitel', 'nv_tbbtitel', 'nv_einsaetze',
         'nv_einsatz_status', 'nv_einsatz_ereignisse'
       )) = 18) AS `base_tables_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name LIKE 'nv\_%'
       AND engine <> 'InnoDB') = 0) AS `all_nv_tables_innodb_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name LIKE 'nv\_%'
       AND LOWER(COALESCE(column_default, '')) LIKE '%0000-00-00%') = 0)
       AS `no_zero_date_defaults_ok`,
  ((SELECT COUNT(*) FROM `nv_nachrichten`
      WHERE CAST(`01_datum` AS CHAR) LIKE '0000-%'
         OR CAST(`02_zeit` AS CHAR) LIKE '0000-%'
         OR CAST(`03_datum` AS CHAR) LIKE '0000-%'
         OR CAST(`12_abfzeit` AS CHAR) LIKE '0000-%'
         OR CAST(`15_quitdatum` AS CHAR) LIKE '0000-%'
         OR CAST(`x05_druck_d` AS CHAR) LIKE '0000-%'
         OR CAST(`99_lstacc` AS CHAR) LIKE '0000-%') = 0
   AND (SELECT COUNT(*) FROM `nv_anhang`
      WHERE CAST(`date` AS CHAR) LIKE '0000-%') = 0
   AND (SELECT COUNT(*) FROM `nv_bhp50`
      WHERE CAST(`sich1_zeit` AS CHAR) LIKE '0000-%'
         OR CAST(`sich2_zeit` AS CHAR) LIKE '0000-%'
         OR CAST(`sich3_zeit` AS CHAR) LIKE '0000-%'
         OR CAST(`sich4_zeit` AS CHAR) LIKE '0000-%'
         OR CAST(`trans_start` AS CHAR) LIKE '0000-%') = 0)
       AS `no_zero_date_values_ok`,
  ((SELECT COUNT(*) FROM `nv_empfmtx`) = 20) AS `matrix_row_count_ok`,
  ((SELECT COUNT(DISTINCT `mtx_x`, `mtx_y`) FROM `nv_empfmtx`) = 20)
       AS `matrix_positions_unique_ok`,
  ((SELECT COUNT(*) FROM `nv_empfmtx`
      WHERE `mtx_x` BETWEEN 1 AND 5 AND `mtx_y` BETWEEN 1 AND 4) = 20)
       AS `matrix_dimensions_ok`,
  ((SELECT COUNT(*) FROM `nv_empfmtx`
      WHERE `mtx_rc2` IN ('t', '1')) = 1
   AND
   (SELECT COUNT(*) FROM `nv_empfmtx`
      WHERE `mtx_rc2` IN ('t', '1')
        AND `mtx_typ` = 'cb'
        AND `mtx_fkt` <> ''
        AND `mtx_rolle` IN ('Stab', 'FB')) = 1
   AND
   (SELECT COUNT(*) FROM `nv_empfmtx`
      WHERE `mtx_auto` IN ('t', '1')
        AND NOT (
          `mtx_typ` = 'cb'
          AND `mtx_fkt` <> ''
          AND `mtx_rolle` IN ('Stab', 'FB')
        )) = 0)
       AS `matrix_flag_targets_ok`,
  ((SELECT COUNT(*)
      FROM `nv_benutzer` AS assignment_user
     WHERE assignment_user.`aktiv` = 1
       AND NOT (
         (BINARY assignment_user.`funktion` = BINARY 'Si'
           AND BINARY assignment_user.`rolle` = BINARY 'Stab')
         OR
         (BINARY assignment_user.`funktion` = BINARY 'A/W'
           AND BINARY assignment_user.`rolle` = BINARY 'Fernmelder')
         OR
         EXISTS (
           SELECT 1
             FROM `nv_empfmtx` AS assignment_matrix
            WHERE assignment_matrix.`mtx_typ` = 'cb'
              AND BINARY assignment_matrix.`mtx_fkt`
                  = BINARY assignment_user.`funktion`
              AND BINARY assignment_matrix.`mtx_rolle`
                  = BINARY assignment_user.`rolle`
         )
       )) = 0)
       AS `active_user_assignments_valid_ok`,
  ((SELECT COUNT(*) FROM `nv_empfmtx_standard`) = 20)
       AS `standard_matrix_row_count_ok`,
  ((SELECT COUNT(DISTINCT `mtx_x`, `mtx_y`) FROM `nv_empfmtx_standard`) = 20)
       AS `standard_matrix_positions_unique_ok`,
  ((SELECT COUNT(*) FROM `nv_empfmtx_standard`
      WHERE `mtx_x` BETWEEN 1 AND 5 AND `mtx_y` BETWEEN 1 AND 4) = 20)
       AS `standard_matrix_dimensions_ok`,
  ((SELECT COUNT(*) FROM `nv_empfmtx_standard`
      WHERE `mtx_rc2` IN ('t', '1')) = 1
   AND
   (SELECT COUNT(*) FROM `nv_empfmtx_standard`
      WHERE `mtx_rc2` IN ('t', '1')
        AND `mtx_typ` = 'cb'
        AND `mtx_fkt` <> ''
        AND `mtx_rolle` IN ('Stab', 'FB')) = 1
   AND
   (SELECT COUNT(*) FROM `nv_empfmtx_standard`
      WHERE `mtx_auto` IN ('t', '1')
        AND NOT (
          `mtx_typ` = 'cb'
          AND `mtx_fkt` <> ''
          AND `mtx_rolle` IN ('Stab', 'FB')
        )) = 0)
       AS `standard_matrix_flag_targets_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'uq_anhang_filename') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'uq_anhang_filename'
       AND non_unique = 0
       AND seq_in_index = 1
       AND column_name = 'filename') = 1)
       AS `attachment_filename_unique_ok`,
  ((SELECT character_maximum_length
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND column_name = 'kuerzel') = 6)
       AS `attachment_code_width_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND character_maximum_length = 6
       AND (
         (table_name = 'nv_benutzer' AND column_name = 'kuerzel')
         OR
         (table_name = 'nv_anhang' AND column_name = 'kuerzel')
         OR
         (table_name = 'nv_nachrichten' AND column_name IN (
           '01_zeichen', '02_zeichen', '03_zeichen',
           '14_zeichen', '15_quitzeichen', 'x03_sperruser'
         ))
       )) = 8)
       AS `runtime_code_widths_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND (
         (column_name = 'password' AND character_maximum_length = 255)
         OR
         (column_name IN ('ip', 'fwdip') AND character_maximum_length = 45)
       )) = 3)
       AS `runtime_user_widths_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND (
         (column_name = 'fileext' AND character_maximum_length = 16)
         OR
         (column_name = 'id' AND character_maximum_length = 128)
       )) = 2)
       AS `runtime_attachment_widths_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND index_name = 'idx_benutzer_funktion_aktiv') = 2
   AND
   (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND index_name = 'idx_benutzer_funktion_aktiv') = 'funktion,aktiv')
       AS `runtime_user_index_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_filename_status') = 2
   AND
   (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_filename_status') = 'filename,status'
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_id') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_id'
       AND seq_in_index = 1
       AND column_name = 'id') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_md5hash') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND index_name = 'idx_anhang_md5hash'
       AND seq_in_index = 1
       AND column_name = 'md5hash') = 1)
       AS `runtime_attachment_indexes_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND column_name = 'estab_gesperrt'
       AND data_type = 'tinyint'
       AND column_type LIKE 'tinyint%unsigned'
       AND is_nullable = 'NO'
       AND column_default = '0') = 1)
       AS `user_blocking_schema_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN (
         'nv_einsaetze', 'nv_einsatz_status', 'nv_einsatz_ereignisse'
       )
       AND table_comment = 'estab:migration:50-global-incidents:v1') = 3
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND column_name = 'einsatz_id'
       AND data_type = 'bigint'
       AND column_type LIKE '%unsigned%'
       AND is_nullable = 'YES'
       AND table_name IN (
         'nv_nachrichten', 'nv_anhang', 'nv_etb', 'nv_tbb', 'nv_ubb',
         'nv_protokoll', 'nv_bhp50', 'nv_komplan',
         'nv_etbtitel', 'nv_tbbtitel'
       )) = 10
   AND
   (SELECT COUNT(*)
      FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name IN (
         'fk_einsatz_status_active',
         'fk_einsatz_ereignisse_einsatz',
         'fk_nachrichten_einsatz',
         'fk_anhang_einsatz',
         'fk_etb_einsatz',
         'fk_tbb_einsatz',
         'fk_ubb_einsatz',
         'fk_protokoll_einsatz',
         'fk_bhp50_einsatz',
         'fk_komplan_einsatz',
         'fk_etbtitel_einsatz',
         'fk_tbbtitel_einsatz'
       )) = 12)
       AS `incident_schema_ok`,
  ((SELECT COUNT(*) FROM `nv_einsatz_status`
      WHERE `singleton_id` = 1 AND `revision` >= 0) = 1
   AND
   (SELECT COUNT(*) FROM `nv_einsatz_status`) = 1
   AND
   (SELECT COUNT(*) FROM `nv_einsatz_status` AS s
      LEFT JOIN `nv_einsaetze` AS e
        ON e.`einsatz_id` = s.`active_einsatz_id`
     WHERE s.`active_einsatz_id` IS NOT NULL
       AND e.`einsatz_id` IS NULL) = 0)
       AS `incident_status_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND action_timing = 'BEFORE'
       AND event_manipulation IN ('INSERT', 'UPDATE', 'DELETE')
       AND event_object_table IN (
         'nv_nachrichten', 'nv_anhang', 'nv_etb', 'nv_tbb', 'nv_ubb',
         'nv_bhp50', 'nv_komplan', 'nv_etbtitel', 'nv_tbbtitel'
       )
       AND trigger_name LIKE 'estab\\_%\\_einsatz') = 27
   AND
   (SELECT COUNT(DISTINCT CONCAT(
        event_object_table, ':', event_manipulation
      ))
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND action_timing = 'BEFORE'
       AND event_manipulation IN ('INSERT', 'UPDATE', 'DELETE')
       AND event_object_table IN (
         'nv_nachrichten', 'nv_anhang', 'nv_etb', 'nv_tbb', 'nv_ubb',
         'nv_bhp50', 'nv_komplan', 'nv_etbtitel', 'nv_tbbtitel'
       )
       AND trigger_name LIKE 'estab\\_%\\_einsatz') = 27
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND event_object_table = 'nv_protokoll'
       AND trigger_name LIKE 'estab\\_%\\_einsatz') = 0
   AND
   (SELECT COUNT(*)
      FROM information_schema.routines
     WHERE routine_schema = DATABASE()
       AND routine_type = 'FUNCTION'
       AND routine_name IN (
         'estab_incident_for_insert',
         'estab_incident_for_update',
         'estab_incident_for_delete'
       )) = 3)
       AS `incident_trigger_boundary_ok`,
  ((SELECT COUNT(*) FROM `nv_nachrichten` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_anhang` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_etb` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_tbb` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_ubb` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_bhp50` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_komplan` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_etbtitel` WHERE `einsatz_id` IS NULL)
   + (SELECT COUNT(*) FROM `nv_tbbtitel` WHERE `einsatz_id` IS NULL) = 0)
       AS `incident_assignment_ok`,
  ((SELECT COUNT(*) FROM `estab_schema_migrations`) = 5
   AND
   (SELECT COUNT(*)
      FROM `estab_schema_migrations`
     WHERE `version` IN (
       '20-nullable-dates.sql',
       '30-runtime-schema.sql',
       '40-recipient-matrix-standard.sql',
       '50-global-incidents.sql',
       '70-user-account-blocking.sql'
     )
       AND `state` = 'applied'
       AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$') = 5)
       AS `schema_migrations_ok`;

SELECT `table_name`, `engine`, `table_collation`
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name LIKE 'nv\_%'
   AND (
     engine <> 'InnoDB'
     OR table_collation NOT LIKE 'utf8mb4\_%'
   )
 ORDER BY `table_name`;
