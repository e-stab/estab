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
         'nv_bhp50', 'nv_etbtitel', 'nv_tbbtitel'
       )) = 15) AS `base_tables_ok`,
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
  ((SELECT COUNT(*) FROM `estab_schema_migrations`) = 3
   AND
   (SELECT COUNT(*)
      FROM `estab_schema_migrations`
     WHERE `version` IN (
       '20-nullable-dates.sql',
       '30-runtime-schema.sql',
       '40-recipient-matrix-standard.sql'
     )
       AND `state` = 'applied'
       AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$') = 3)
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
