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
         'nv_einsatz_status', 'nv_einsatz_ereignisse',
         'nv_nachrichten_ereignisse', 'nv_nachrichten_nachweiskopf',
         'nv_funktionsfaehigkeiten', 'nv_betriebsereignis_kopf',
         'nv_betriebsereignisse', 'nv_dienstschichten',
         'nv_dienstbesetzungen', 'nv_dienstuebergabe_anfragen',
         'nv_dienstuebergaben',
         'nv_fernmeldeplaene', 'nv_fernmeldeplan_eintraege',
         'nv_melderauftraege'
       )) = 30) AS `base_tables_ok`,
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
        AND BINARY `mtx_fkt` = BINARY 'S2'
        AND BINARY `mtx_rolle` = BINARY 'Stab') = 1
   AND
   (SELECT COUNT(*) FROM `nv_empfmtx`
      WHERE `mtx_auto` IN ('t', '1')) = 0)
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
         (BINARY assignment_user.`funktion` = BINARY 'LdF'
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
        AND BINARY `mtx_fkt` = BINARY 'S2'
        AND BINARY `mtx_rolle` = BINARY 'Stab') = 1
   AND
   (SELECT COUNT(*) FROM `nv_empfmtx_standard`
      WHERE `mtx_auto` IN ('t', '1')) = 0)
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
       AND table_name = 'nv_nachrichten'
       AND character_set_name = 'utf8mb4'
       AND collation_name = 'utf8mb4_unicode_ci'
       AND is_nullable = 'NO'
       AND HEX(column_default) = '2727'
       AND extra = ''
       AND (
         (
           column_name = '11_rufnummer'
           AND data_type = 'varchar'
           AND column_type = 'varchar(128)'
           AND character_maximum_length = 128
           AND column_comment =
             'estab:migration:98:message-counterparty-number:v1'
         )
         OR
         (
           column_name = '12_betreff'
           AND data_type = 'varchar'
           AND column_type = 'varchar(255)'
           AND character_maximum_length = 255
           AND column_comment = 'estab:migration:98:message-subject:v1'
         )
       )) = 2
   AND
   ((SELECT CONCAT(
       GROUP_CONCAT(
         column_name ORDER BY ordinal_position SEPARATOR ','
       ),
       ':',
       MAX(ordinal_position) - MIN(ordinal_position)
     )
       FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'nv_nachrichten'
        AND column_name IN (
          '10_anschrift', '11_rufnummer', '11_gesprnotiz',
          '12_betreff', '12_anhang'
        )) =
        '10_anschrift,11_rufnummer,11_gesprnotiz,12_betreff,12_anhang:4'))
       AS `official_message_fields_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'ft_nachrichten_inhalt') = 0
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'ft_nachrichten_suche'
       AND index_type = 'FULLTEXT'
       AND non_unique = 1
       AND sub_part IS NULL) = 7
   AND
   (SELECT GROUP_CONCAT(
             column_name ORDER BY seq_in_index SEPARATOR ','
           )
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'ft_nachrichten_suche') =
     '05_gegenstelle,10_anschrift,11_rufnummer,12_betreff,12_inhalt,13_abseinheit,14_funktion'
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'idx_nachrichten_einsatz_status_zeit'
       AND index_type = 'BTREE'
       AND non_unique = 1
       AND sub_part IS NULL) = 4
   AND
   (SELECT GROUP_CONCAT(
             column_name ORDER BY seq_in_index SEPARATOR ','
           )
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'idx_nachrichten_einsatz_status_zeit') =
     'einsatz_id,x00_status,12_abfzeit,00_lfd'
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'idx_nachrichten_einsatz_richtung_nummer'
       AND index_type = 'BTREE'
       AND non_unique = 1
       AND sub_part IS NULL) = 4
   AND
   (SELECT GROUP_CONCAT(
             column_name ORDER BY seq_in_index SEPARATOR ','
           )
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND index_name = 'idx_nachrichten_einsatz_richtung_nummer') =
     'einsatz_id,04_richtung,04_nummer,00_lfd')
       AS `message_list_indexes_ok`,
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
       AND table_name = 'nv_einsaetze'
       AND column_name = 'fuehrungsstellenname'
       AND data_type = 'varchar'
       AND column_type = 'varchar(128)'
       AND character_set_name = 'utf8mb4'
       AND collation_name = 'utf8mb4_unicode_ci'
       AND is_nullable = 'YES'
       AND (
         column_default IS NULL
         OR UPPER(column_default) = 'NULL'
       )
       AND ordinal_position = (
         SELECT ordinal_position + 1
           FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'nv_einsaetze'
            AND column_name = 'organisation'
       )
       AND extra = ''
       AND column_comment =
         'estab:migration:97:incident-command-post-name:v1') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_einsaetze'
       AND column_name = 'fuehrungsstellenname_gesperrt'
       AND data_type = 'tinyint'
       AND column_type LIKE 'tinyint%unsigned'
       AND is_nullable = 'NO'
       AND column_default = '0'
       AND ordinal_position = (
         SELECT ordinal_position + 2
           FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'nv_einsaetze'
            AND column_name = 'organisation'
       )
       AND extra = ''
       AND column_comment =
         'estab:migration:97:incident-command-post-lock:v1') = 1
   AND
   (SELECT COUNT(*) FROM `nv_einsaetze`
     WHERE `fuehrungsstellenname` IS NOT NULL
       AND (
         BINARY `fuehrungsstellenname`
           <> BINARY TRIM(`fuehrungsstellenname`)
         OR CHAR_LENGTH(`fuehrungsstellenname`) = 0
         OR `fuehrungsstellenname`
           REGEXP '^[[:space:]]|[[:space:]]$'
         OR `fuehrungsstellenname` NOT REGEXP '[^[:space:]]'
         OR `fuehrungsstellenname` REGEXP '\\p{C}'
       )) = 0
   AND
   (SELECT COUNT(*) FROM `nv_einsaetze`
     WHERE `fuehrungsstellenname_gesperrt` NOT IN (0, 1)
        OR (
          `fuehrungsstellenname_gesperrt` = 1
          AND `fuehrungsstellenname` IS NULL
        )) = 0
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
         'estab_command_post_incident_insert',
         'estab_command_post_incident_update'
       )) = 2
   AND
   (SELECT COUNT(*)
      FROM information_schema.routines
     WHERE routine_schema = DATABASE()
       AND routine_type = 'FUNCTION'
       AND routine_name IN (
         'estab_incident_command_post_for_write',
         'estab_incident_for_insert',
         'estab_incident_for_update',
         'estab_incident_for_delete'
       )) = 4
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
  ((SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN (
         'nv_nachrichten_ereignisse', 'nv_nachrichten_nachweiskopf'
       )
       AND table_comment =
         'estab:migration:80-dv-evidence-retention:v1') = 2
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_einsaetze'
       AND column_name IN (
         'estab_status', 'estab_closed_at', 'estab_closed_by',
         'estab_close_note', 'estab_retain_until', 'estab_legal_hold',
         'estab_legal_hold_reason', 'estab_legal_hold_at',
         'estab_legal_hold_by'
       )) = 9
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_etb'
       AND column_name IN (
         'estab_event_time', 'estab_recorded_at', 'estab_event_type',
         'estab_message_id', 'estab_attachment_id', 'estab_reference',
         'estab_correction_of'
       )) = 7
   AND
   (SELECT COUNT(*)
      FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name IN (
         'fk_etb_message', 'fk_etb_attachment', 'fk_etb_correction',
         'fk_message_events_incident', 'fk_message_events_message',
         'fk_message_evidence_heads_incident',
         'fk_message_evidence_heads_message'
       )) = 7
   AND
   (SELECT COUNT(*)
      FROM information_schema.routines
     WHERE routine_schema = DATABASE()
       AND routine_type = 'FUNCTION'
       AND routine_name = 'estab_message_event_hash') = 1)
       AS `dv_evidence_schema_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
         'estab_einsatz_status_bi_open',
         'estab_einsatz_status_bu_open',
         'estab_einsaetze_bu_evidence',
         'estab_einsaetze_bd_retention',
         'estab_etb_bi_einsatz',
         'estab_etb_bu_einsatz',
         'estab_etb_bd_einsatz',
         'estab_message_events_bi_evidence',
         'estab_message_events_bu_append_only',
         'estab_message_events_bd_append_only',
         'estab_message_evidence_heads_bd_protected',
         'estab_incident_events_bu_append_only',
         'estab_incident_events_bd_append_only'
       )) = 13
   AND
   (SELECT COUNT(*) FROM `nv_nachrichten_ereignisse` AS event_row
      LEFT JOIN `nv_nachrichten` AS message_row
        ON message_row.`00_lfd` = event_row.`message_id`
       AND message_row.`einsatz_id` = event_row.`einsatz_id`
     WHERE message_row.`00_lfd` IS NULL) = 0
   AND
   (SELECT COUNT(*) FROM `nv_nachrichten_nachweiskopf` AS head_row
      LEFT JOIN `nv_nachrichten` AS message_row
        ON message_row.`00_lfd` = head_row.`message_id`
       AND message_row.`einsatz_id` = head_row.`einsatz_id`
     WHERE message_row.`00_lfd` IS NULL) = 0)
       AS `dv_evidence_boundary_ok`,
  ((SELECT COUNT(*)
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
       AND table_comment =
         'estab:migration:94-dv-organisational-controls:v1') = 10
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_nachrichten'
       AND column_name = 'estab_fernmeldeplan_eintrag_id'
       AND data_type = 'bigint'
       AND column_type LIKE '%unsigned%'
       AND is_nullable = 'YES') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_melderauftraege'
       AND (
         (
           column_name = 'ruecknachricht_vorhanden'
           AND data_type = 'tinyint'
           AND is_nullable = 'YES'
         )
         OR (
           column_name = 'offener_nachrichtenauftrag'
           AND data_type = 'bigint'
           AND extra LIKE '%STORED GENERATED%'
         )
       )) = 2
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_melderauftraege'
       AND index_name = 'uq_melderauftrag_offene_nachricht'
       AND column_name = 'offener_nachrichtenauftrag'
       AND non_unique = 0) = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND constraint_name IN (
         'fk_betriebsereignis_kopf_einsatz',
         'fk_betriebsereignis_einsatz',
         'fk_dienstschicht_einsatz',
         'fk_dienstschicht_vorgaenger',
         'fk_dienstbesetzung_schicht',
         'fk_dienstbesetzung_benutzer',
         'fk_dienstbesetzung_nachfolger',
         'fk_dienstuebergabe_anfrage_einsatz',
         'fk_dienstuebergabe_anfrage_von',
         'fk_dienstuebergabe_anfrage_an',
         'fk_dienstuebergabe_anfrage_besetzung',
         'fk_dienstuebergabe_anfrage_abschluss',
         'fk_dienstuebergabe_einsatz',
         'fk_dienstuebergabe_von',
         'fk_dienstuebergabe_an',
         'fk_fernmeldeplan_einsatz',
         'fk_fernmeldeplan_ersteller',
         'fk_fernmeldeplan_freigabe',
         'fk_fernmeldeplan_eintrag',
         'fk_melderauftrag_einsatz',
         'fk_melderauftrag_nachricht',
         'fk_melderauftrag_melder',
         'fk_melderauftrag_beauftragt',
         'fk_melderauftrag_gemeldet',
         'fk_nachrichten_fernmeldeplan_eintrag'
       )) = 25
   AND
   (SELECT COUNT(*) FROM `nv_funktionsfaehigkeiten`
     WHERE (`funktion`, `rolle`, `faehigkeit`, `bezeichnung`) IN (
       ('S2', 'Stab', 'LAGE_DOKUMENTATION',
         'Lage und Dokumentation'),
       ('S2', 'Stab', 'EINSATZTAGEBUCH',
         'Einsatztagebuchführung'),
       ('ETB', 'Stab', 'EINSATZTAGEBUCH',
         'Einsatztagebuchführung'),
       ('Si', 'Stab', 'SICHTUNG', 'Sichter'),
       ('S6', 'Stab', 'FERNMELDEPLANUNG', 'Fernmeldeplanung'),
       ('LdF', 'Fernmelder', 'FERNMELDEBETRIEB',
         'Leiter der Fernmeldezentrale'),
       ('A/W', 'Fernmelder', 'BEFOERDERUNG',
         'Aufnahme und Weitergabe')
     )) = 7
   AND
   (SELECT COUNT(*) FROM `nv_funktionsfaehigkeiten`) = 7
   AND
   (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_funktionsfaehigkeiten'
       AND column_name = 'faehigkeit'
       AND data_type = 'enum'
       AND column_type =
         'enum(''LAGE_DOKUMENTATION'',''EINSATZTAGEBUCH'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')'
       AND is_nullable = 'NO') = 1
   AND
   (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_funktionsfaehigkeiten') = 4
   AND
   (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_funktionsfaehigkeiten'
       AND character_set_name = 'utf8mb4'
       AND collation_name = 'utf8mb4_unicode_ci'
       AND is_nullable = 'NO'
       AND column_default IS NULL
       AND extra = ''
       AND (
         (column_name = 'funktion' AND ordinal_position = 1
           AND data_type = 'varchar' AND column_type = 'varchar(6)'
           AND character_maximum_length = 6)
         OR (column_name = 'rolle' AND ordinal_position = 2
           AND data_type = 'enum'
           AND column_type = 'enum(''Stab'',''FB'',''Fernmelder'')')
         OR (column_name = 'faehigkeit' AND ordinal_position = 3
           AND data_type = 'enum'
           AND column_type =
             'enum(''LAGE_DOKUMENTATION'',''EINSATZTAGEBUCH'',''SICHTUNG'',''FERNMELDEPLANUNG'',''FERNMELDEBETRIEB'',''BEFOERDERUNG'')')
         OR (column_name = 'bezeichnung' AND ordinal_position = 4
           AND data_type = 'varchar' AND column_type = 'varchar(100)'
           AND character_maximum_length = 100)
       )) = 4
   AND
   (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_funktionsfaehigkeiten'
       AND index_name = 'PRIMARY') = 2
   AND
   (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_funktionsfaehigkeiten'
       AND index_name = 'PRIMARY'
       AND non_unique = 0
       AND (
         (seq_in_index = 1 AND column_name = 'funktion')
         OR (seq_in_index = 2 AND column_name = 'faehigkeit')
       )) = 2
   AND
   (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_funktionsfaehigkeiten'
       AND index_name <> 'PRIMARY') = 0)
       AS `dv_organisation_schema_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
         'estab_dv94_message_route_update',
         'estab_dv94_fernmeldeplan_immutable',
         'estab_dv94_fernmeldeplan_entry_insert',
         'estab_dv94_fernmeldeplan_entry_update',
         'estab_dv94_fernmeldeplan_entry_delete',
         'estab_dv94_shift_insert',
         'estab_dv94_shift_update',
         'estab_dv94_shift_delete',
         'estab_dv94_hat_insert',
         'estab_dv94_hat_update',
         'estab_dv94_hat_delete',
         'estab_dv94_handover_insert',
         'estab_dv94_handover_update',
         'estab_dv94_handover_delete',
         'estab_dv94_handover_request_insert',
         'estab_dv94_handover_request_update',
         'estab_dv94_handover_request_delete',
         'estab_dv94_fernmeldeplan_insert',
         'estab_dv94_fernmeldeplan_delete',
         'estab_dv94_messenger_insert',
         'estab_dv94_messenger_update',
         'estab_dv94_messenger_delete',
         'estab_dv94_event_insert',
         'estab_dv94_event_head_insert',
         'estab_dv94_event_update',
         'estab_dv94_event_delete',
         'estab_dv94_event_head_update',
         'estab_dv94_event_head_delete'
       )) = 28
   AND
   (SELECT COUNT(*) FROM `nv_betriebsereignisse` AS event_row
      LEFT JOIN `nv_betriebsereignis_kopf` AS head_row
        ON head_row.`einsatz_id` = event_row.`einsatz_id`
     WHERE head_row.`einsatz_id` IS NULL) = 0)
       AS `dv_organisation_boundary_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_anhang'
       AND (
         (column_name = 'integrity_required'
           AND data_type = 'tinyint' AND is_nullable = 'NO'
           AND column_default = '1')
         OR
         (column_name = 'ingest_sha256'
           AND data_type = 'char'
           AND character_maximum_length = 64
           AND character_set_name = 'ascii'
           AND collation_name = 'ascii_bin'
           AND is_nullable = 'YES')
         OR
         (column_name = 'ingest_size'
           AND data_type = 'bigint'
           AND column_type LIKE '%unsigned%'
           AND is_nullable = 'YES')
         OR
         (column_name = 'integrity_captured_at'
           AND data_type = 'datetime'
           AND datetime_precision = 6
           AND is_nullable = 'YES')
       )) = 4
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
         'estab_attachment_integrity_bi',
         'estab_attachment_integrity_bu'
       )) = 2
   AND
   (SELECT COUNT(*) FROM `nv_anhang`
     WHERE `integrity_required` = 1
       AND `status` = 1
       AND (
         `ingest_sha256` IS NULL
         OR `ingest_sha256`
              NOT REGEXP BINARY '^[0-9a-f]{64}$'
         OR `ingest_size` IS NULL
         OR `integrity_captured_at` IS NULL
       )) = 0)
       AS `attachment_integrity_schema_ok`,
  ((SELECT COUNT(*) FROM `estab_schema_migrations`) = 14
   AND
   (SELECT COUNT(*)
      FROM `estab_schema_migrations`
     WHERE `version` IN (
       '20-nullable-dates.sql',
       '30-runtime-schema.sql',
       '40-recipient-matrix-standard.sql',
       '45-global-incidents-prepare.sql',
       '50-global-incidents.sql',
       '55-global-incidents-finish.sql',
       '70-user-account-blocking.sql',
       '80-dv-evidence-retention.sql',
       '94-dv-organisational-controls.sql',
       '95-attachment-ingest-integrity.sql',
       '96-etb-duty-function.sql',
       '97-incident-command-post-name.sql',
       '98-official-message-form-fields.sql',
       '99-message-list-search.sql'
     )
       AND `state` = 'applied'
       AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$') = 14)
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
