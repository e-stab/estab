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
         'nv_logbuch_koepfe',
         'nv_funktionsfaehigkeiten', 'nv_betriebsereignis_kopf',
         'nv_betriebsereignisse', 'nv_dienstschichten',
         'nv_dienstbesetzungen', 'nv_dienstuebergabe_anfragen',
         'nv_dienstuebergaben',
         'nv_fernmeldeplaene', 'nv_fernmeldeplan_eintraege',
         'nv_melderauftraege', 'nv_selbstregistrierung'
       )) = 32) AS `base_tables_ok`,
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
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND column_name = 'estab_letzte_aktivitaet'
       AND data_type = 'datetime'
       AND datetime_precision = 6
       AND is_nullable = 'YES'
       AND extra = ''
       AND column_comment =
         'estab:migration:100:last-browser-activity-utc:v1') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND index_name = 'idx_benutzer_presence'
       AND index_type = 'BTREE'
       AND non_unique = 1
       AND sub_part IS NULL) = 3
   AND
   (SELECT GROUP_CONCAT(
             column_name ORDER BY seq_in_index SEPARATOR ','
           )
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_benutzer'
       AND index_name = 'idx_benutzer_presence') =
     'aktiv,estab_gesperrt,estab_letzte_aktivitaet')
       AS `user_presence_schema_ok`,
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
      FROM information_schema.routines
     WHERE routine_schema = DATABASE()
       AND routine_type = 'FUNCTION'
       AND sql_data_access = 'MODIFIES SQL DATA'
       AND security_type = 'DEFINER'
       AND routine_definition LIKE '%FOR UPDATE%'
       AND routine_name IN (
         'estab_incident_for_insert',
         'estab_incident_for_update',
         'estab_incident_for_delete'
       )) = 3
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
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name = 'estab_dv94_hat_insert'
       AND action_timing = 'BEFORE'
       AND event_manipulation = 'INSERT'
       AND action_statement LIKE '%GEPLANT%AKTIV%'
       AND action_statement LIKE
             '%Active duty shift function was already assigned%'
       AND action_statement LIKE '%A/W%'
       AND action_statement LIKE '%FOR UPDATE%') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name = 'estab_dv94_hat_update'
       AND action_timing = 'BEFORE'
       AND event_manipulation = 'UPDATE'
       AND action_statement LIKE
             '%Active shift ETB writer change requires confirmed handover%'
       AND action_statement LIKE '%Invalid duty assignment acceptance%') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name = 'estab_log111_handover_insert_time'
       AND action_timing = 'BEFORE'
       AND event_manipulation = 'INSERT'
       AND action_statement LIKE
             '%Duty handover completion times are inconsistent%'
       AND action_statement LIKE
             '%request.`initiiert_am` <= NEW.`uebergeben_am`%') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name = 'estab_log111_handover_confirm_time'
       AND action_timing = 'BEFORE'
       AND event_manipulation = 'UPDATE'
       AND action_statement LIKE
             '%Duty handover confirmation times are inconsistent%'
       AND action_statement LIKE
             '%OLD.`initiiert_am` <= NEW.`bestaetigt_am`%') = 1
   AND
   (SELECT COUNT(*) FROM `nv_betriebsereignisse` AS event_row
      LEFT JOIN `nv_betriebsereignis_kopf` AS head_row
       ON head_row.`einsatz_id` = event_row.`einsatz_id`
     WHERE head_row.`einsatz_id` IS NULL) = 0)
       AS `dv_organisation_boundary_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_betriebsereignisse'
       AND column_name = 'objekttyp'
       AND ordinal_position = 4
       AND data_type = 'enum'
       AND column_type =
         'enum(''DIENSTSCHICHT'',''DIENSTBESETZUNG'',''DIENSTUEBERGABE'',''FERNMELDEPLAN'',''MELDERAUFTRAG'',''EINSATZ'',''ZUGANGSSCHICHT'')'
       AND character_set_name = 'utf8mb4'
       AND collation_name = 'utf8mb4_unicode_ci'
       AND is_nullable = 'NO'
       AND column_default IS NULL
       AND extra = ''
       AND column_comment =
         'estab:migration:112:event-object-types:v1') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN (
         'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
       )
       AND table_type = 'BASE TABLE'
       AND engine = 'InnoDB'
       AND table_collation = 'utf8mb4_unicode_ci'
       AND table_comment =
         'estab:migration:112:optional-access-shifts:v1') = 2
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name IN (
         'nv_zugangsschichten', 'nv_zugangsschicht_mitglieder'
       )) = 18
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND (
         (table_name = 'nv_zugangsschichten' AND (
           (column_name = 'zugangsschicht_id' AND ordinal_position = 1
            AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
            AND is_nullable = 'NO' AND extra = 'auto_increment')
           OR (column_name = 'einsatz_id' AND ordinal_position = 2
            AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
            AND is_nullable = 'NO')
           OR (column_name = 'bezeichnung' AND ordinal_position = 3
            AND data_type = 'varchar' AND character_maximum_length = 100
            AND character_set_name = 'utf8mb4'
            AND collation_name = 'utf8mb4_unicode_ci'
            AND is_nullable = 'NO')
           OR (column_name IN ('beginn', 'ende')
            AND ordinal_position IN (4, 5) AND data_type = 'datetime'
            AND datetime_precision = 6 AND is_nullable = 'YES')
           OR (column_name = 'zugang_aktiv' AND ordinal_position = 6
            AND data_type = 'tinyint' AND column_type LIKE '%unsigned%'
            AND is_nullable = 'NO' AND column_default = '0')
           OR (column_name IN ('erstellt_am', 'geaendert_am')
            AND ordinal_position IN (7, 9) AND data_type = 'datetime'
            AND datetime_precision = 6 AND is_nullable = 'NO')
           OR (column_name IN ('erstellt_von', 'geaendert_von')
            AND ordinal_position IN (8, 10) AND data_type = 'varchar'
            AND character_maximum_length = 128
            AND character_set_name = 'utf8mb4'
            AND collation_name = 'utf8mb4_unicode_ci'
            AND is_nullable = 'NO')
         ))
         OR (table_name = 'nv_zugangsschicht_mitglieder' AND (
           (column_name = 'zugangsschicht_mitglied_id'
            AND ordinal_position = 1 AND data_type = 'bigint'
            AND column_type LIKE '%unsigned%' AND is_nullable = 'NO'
            AND extra = 'auto_increment')
           OR (column_name = 'zugangsschicht_id' AND ordinal_position = 2
            AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
            AND is_nullable = 'NO')
           OR (column_name = 'benutzer_kuerzel' AND ordinal_position = 3
            AND data_type = 'varchar' AND character_maximum_length = 6
            AND character_set_name = 'utf8mb4'
            AND collation_name = 'utf8mb4_unicode_ci'
            AND is_nullable = 'NO')
           OR (column_name = 'zugeordnet_am' AND ordinal_position = 4
            AND data_type = 'datetime' AND datetime_precision = 6
            AND is_nullable = 'NO')
           OR (column_name = 'zugeordnet_von' AND ordinal_position = 5
            AND data_type = 'varchar' AND character_maximum_length = 128
            AND character_set_name = 'utf8mb4'
            AND collation_name = 'utf8mb4_unicode_ci'
            AND is_nullable = 'NO')
           OR (column_name = 'entfernt_am' AND ordinal_position = 6
            AND data_type = 'datetime' AND datetime_precision = 6
            AND is_nullable = 'YES')
           OR (column_name = 'entfernt_von' AND ordinal_position = 7
            AND data_type = 'varchar' AND character_maximum_length = 128
            AND character_set_name = 'utf8mb4'
            AND collation_name = 'utf8mb4_unicode_ci'
            AND is_nullable = 'YES')
           OR (column_name = 'aktives_benutzer_kuerzel'
            AND ordinal_position = 8 AND data_type = 'varchar'
            AND character_maximum_length = 6
            AND character_set_name = 'utf8mb4'
            AND collation_name = 'utf8mb4_unicode_ci'
            AND is_nullable = 'YES'
            AND extra LIKE '%STORED GENERATED%'
            AND generation_expression LIKE '%entfernt_am%'
            AND generation_expression LIKE '%benutzer_kuerzel%')
         ))
       )) = 18
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND sub_part IS NULL
       AND (
         (table_name = 'nv_zugangsschichten'
          AND index_name = 'uq_zugangsschicht_bezeichnung'
          AND non_unique = 0
          AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
            OR (seq_in_index = 2 AND column_name = 'bezeichnung')))
         OR (table_name = 'nv_zugangsschichten'
          AND index_name = 'idx_zugangsschicht_aktiv'
          AND non_unique = 1
          AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
            OR (seq_in_index = 2 AND column_name = 'zugang_aktiv')
            OR (seq_in_index = 3 AND column_name = 'zugangsschicht_id')))
         OR (table_name = 'nv_zugangsschicht_mitglieder'
          AND index_name = 'uq_zugangsschicht_aktives_mitglied'
          AND non_unique = 0
          AND ((seq_in_index = 1 AND column_name = 'zugangsschicht_id')
            OR (seq_in_index = 2
              AND column_name = 'aktives_benutzer_kuerzel')))
         OR (table_name = 'nv_zugangsschicht_mitglieder'
          AND index_name = 'idx_zugangsschicht_mitglied_benutzer'
          AND non_unique = 1
          AND ((seq_in_index = 1 AND column_name = 'benutzer_kuerzel')
            OR (seq_in_index = 2 AND column_name = 'entfernt_am')))
       )) = 9
   AND
   (SELECT COUNT(*)
      FROM information_schema.referential_constraints AS relation
      JOIN information_schema.key_column_usage AS key_column
        ON key_column.constraint_schema = relation.constraint_schema
       AND key_column.table_name = relation.table_name
       AND key_column.constraint_name = relation.constraint_name
     WHERE relation.constraint_schema = DATABASE()
       AND relation.update_rule = 'RESTRICT'
       AND relation.delete_rule = 'RESTRICT'
       AND (
         (relation.table_name = 'nv_zugangsschichten'
          AND relation.constraint_name = 'fk_zugangsschicht_einsatz'
          AND relation.referenced_table_name = 'nv_einsaetze'
          AND key_column.column_name = 'einsatz_id'
          AND key_column.referenced_column_name = 'einsatz_id')
         OR (relation.table_name = 'nv_zugangsschicht_mitglieder'
          AND relation.constraint_name =
            'fk_zugangsschicht_mitglied_schicht'
          AND relation.referenced_table_name = 'nv_zugangsschichten'
          AND key_column.column_name = 'zugangsschicht_id'
          AND key_column.referenced_column_name = 'zugangsschicht_id')
         OR (relation.table_name = 'nv_zugangsschicht_mitglieder'
          AND relation.constraint_name =
            'fk_zugangsschicht_mitglied_benutzer'
          AND relation.referenced_table_name = 'nv_benutzer'
          AND key_column.column_name = 'benutzer_kuerzel'
          AND key_column.referenced_column_name = 'kuerzel')
       )) = 3
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND (
         (trigger_name = 'estab_dv94_fernmeldeplan_insert'
          AND action_statement LIKE
            '%Telecommunications plan creator account is invalid%'
          AND action_statement NOT LIKE '%creator_shift%')
         OR (trigger_name = 'estab_dv94_fernmeldeplan_immutable'
          AND action_statement LIKE
            '%Telecommunications plan release account is invalid%'
          AND action_statement NOT LIKE '%release_shift%')
         OR (trigger_name = 'estab_dv94_messenger_insert'
          AND action_statement LIKE
            '%Messenger assignment account functions are invalid%'
          AND action_statement NOT LIKE '%messenger_shift%')
         OR (trigger_name = 'estab_dv94_messenger_update'
          AND action_statement LIKE
            '%Messenger report account function is invalid%'
          AND action_statement NOT LIKE '%report_shift%')
       )) = 4)
       AS `optional_access_shifts_ok`,
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
  ((SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_logbuch_koepfe'
       AND table_type = 'BASE TABLE'
       AND engine = 'InnoDB'
       AND table_collation = 'utf8mb4_unicode_ci'
       AND table_comment = 'estab:migration:110:logbook-heads:v1') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND (
         (table_name = 'nv_etb' AND column_name = 'estab_book_lfd'
           AND data_type = 'int' AND column_type LIKE 'int%unsigned'
           AND is_nullable = 'NO' AND column_default = '0'
           AND column_comment =
             'estab:migration:110:etb-book-number:v1')
         OR (table_name = 'nv_tbb' AND column_name = 'estab_book_lfd'
           AND data_type = 'int' AND column_type LIKE 'int%unsigned'
           AND is_nullable = 'NO' AND column_default = '0'
           AND column_comment =
             'estab:migration:110:tbb-book-number:v1')
         OR (table_name = 'nv_tbb'
           AND column_name IN ('estab_event_time', 'estab_recorded_at')
           AND data_type = 'datetime' AND datetime_precision = 6
           AND is_nullable = 'NO')
         OR (table_name = 'nv_tbb' AND column_name = 'estab_entry_type'
           AND data_type = 'varchar' AND character_maximum_length = 32
           AND is_nullable = 'NO')
         OR (table_name = 'nv_tbb' AND column_name = 'estab_message_id'
           AND data_type = 'bigint' AND is_nullable = 'YES'
           AND column_comment = 'estab:migration:110:tbb-message:v1')
         OR (table_name = 'nv_tbb' AND column_name IN (
             'estab_personnel_duty', 'estab_channel',
             'estab_message_route', 'estab_operations', 'estab_receipt'
           ) AND data_type = 'text' AND is_nullable = 'YES')
         OR (table_name = 'nv_tbb' AND column_name = 'estab_correction_of'
           AND data_type = 'int' AND is_nullable = 'YES')
       )) = 12
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND (
         (table_name = 'nv_etb' AND column_name = 'estab_shift_id'
           AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
           AND is_nullable = 'YES'
           AND column_comment = 'estab:migration:111:etb-shift:v1')
         OR (table_name = 'nv_etb'
           AND column_name = 'estab_writer_assignment_id'
           AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
           AND is_nullable = 'YES'
           AND column_comment = 'estab:migration:111:etb-writer:v1')
         OR (table_name = 'nv_etb'
           AND column_name = 'estab_assignee_assignment_id'
           AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
           AND is_nullable = 'YES'
           AND column_comment = 'estab:migration:111:etb-assignee:v1')
         OR (table_name = 'nv_etb' AND column_name = 'estab_assignment'
           AND data_type = 'varchar' AND character_maximum_length = 255
           AND character_set_name = 'utf8mb4'
           AND collation_name = 'utf8mb4_unicode_ci'
           AND is_nullable = 'YES'
           AND column_comment =
             'estab:migration:111:etb-assignment-snapshot:v1')
         OR (table_name = 'nv_tbb' AND column_name = 'estab_shift_id'
           AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
           AND is_nullable = 'YES'
           AND column_comment = 'estab:migration:111:tbb-shift:v1')
         OR (table_name = 'nv_tbb'
           AND column_name = 'estab_writer_assignment_id'
           AND data_type = 'bigint' AND column_type LIKE '%unsigned%'
           AND is_nullable = 'YES'
           AND column_comment = 'estab:migration:111:tbb-writer:v1')
       )) = 6)
       AS `logbook_schema_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND sub_part IS NULL
       AND (
         (table_name = 'nv_etb'
           AND index_name = 'uq_etb_einsatz_book_lfd'
           AND non_unique = 0
           AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
             OR (seq_in_index = 2 AND column_name = 'estab_book_lfd')))
         OR (table_name = 'nv_etb'
           AND index_name = 'uq_etb_attachment_id'
           AND non_unique = 0 AND seq_in_index = 1
           AND column_name = 'estab_attachment_id')
         OR (table_name = 'nv_tbb'
           AND index_name = 'uq_tbb_einsatz_book_lfd'
           AND non_unique = 0
           AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
             OR (seq_in_index = 2 AND column_name = 'estab_book_lfd')))
         OR (table_name = 'nv_tbb'
           AND index_name = 'idx_tbb_einsatz_event_time'
           AND non_unique = 1
           AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
             OR (seq_in_index = 2 AND column_name = 'estab_event_time')
             OR (seq_in_index = 3 AND column_name = 'tbb_lfd-nr')))
         OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_correction'
           AND non_unique = 1 AND seq_in_index = 1
           AND column_name = 'estab_correction_of')
         OR (table_name = 'nv_tbb' AND index_name = 'idx_tbb_message'
           AND non_unique = 0
           AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
             OR (seq_in_index = 2 AND column_name = 'estab_message_id')))
       )) = 11
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND sub_part IS NULL
       AND (
         (table_name = 'nv_etb'
           AND index_name = 'idx_etb_einsatz_shift_book'
           AND non_unique = 1 AND index_type = 'BTREE'
           AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
             OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
             OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
         OR (table_name = 'nv_etb'
           AND index_name = 'idx_etb_writer_assignment'
           AND non_unique = 1 AND index_type = 'BTREE'
           AND seq_in_index = 1
           AND column_name = 'estab_writer_assignment_id')
         OR (table_name = 'nv_etb'
           AND index_name = 'idx_etb_assignee_assignment'
           AND non_unique = 1 AND index_type = 'BTREE'
           AND seq_in_index = 1
           AND column_name = 'estab_assignee_assignment_id')
         OR (table_name = 'nv_tbb'
           AND index_name = 'idx_tbb_einsatz_shift_book'
           AND non_unique = 1 AND index_type = 'BTREE'
           AND ((seq_in_index = 1 AND column_name = 'einsatz_id')
             OR (seq_in_index = 2 AND column_name = 'estab_shift_id')
             OR (seq_in_index = 3 AND column_name = 'estab_book_lfd')))
         OR (table_name = 'nv_tbb'
           AND index_name = 'idx_tbb_writer_assignment'
           AND non_unique = 1 AND index_type = 'BTREE'
           AND seq_in_index = 1
           AND column_name = 'estab_writer_assignment_id')
       )) = 9
   AND
   (SELECT COUNT(*)
      FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND update_rule = 'RESTRICT' AND delete_rule = 'RESTRICT'
       AND ((table_name = 'nv_logbuch_koepfe'
             AND constraint_name = 'fk_logbuch_koepfe_einsatz'
             AND referenced_table_name = 'nv_einsaetze')
         OR (table_name = 'nv_tbb'
             AND constraint_name = 'fk_tbb_message'
             AND referenced_table_name = 'nv_nachrichten')
         OR (table_name = 'nv_tbb'
             AND constraint_name = 'fk_tbb_correction'
             AND referenced_table_name = 'nv_tbb'))) = 3
   AND
   (SELECT COUNT(*)
      FROM information_schema.referential_constraints AS relation
      JOIN information_schema.key_column_usage AS key_column
        ON key_column.constraint_schema = relation.constraint_schema
       AND key_column.table_name = relation.table_name
       AND key_column.constraint_name = relation.constraint_name
     WHERE relation.constraint_schema = DATABASE()
       AND relation.update_rule = 'RESTRICT'
       AND relation.delete_rule = 'RESTRICT'
       AND (
         (relation.table_name = 'nv_etb'
           AND relation.constraint_name = 'fk_etb_shift'
           AND relation.referenced_table_name = 'nv_dienstschichten'
           AND key_column.column_name = 'estab_shift_id'
           AND key_column.referenced_column_name = 'dienstschicht_id')
         OR (relation.table_name = 'nv_etb'
           AND relation.constraint_name = 'fk_etb_writer_assignment'
           AND relation.referenced_table_name = 'nv_dienstbesetzungen'
           AND key_column.column_name = 'estab_writer_assignment_id'
           AND key_column.referenced_column_name = 'dienstbesetzung_id')
         OR (relation.table_name = 'nv_etb'
           AND relation.constraint_name = 'fk_etb_assignee_assignment'
           AND relation.referenced_table_name = 'nv_dienstbesetzungen'
           AND key_column.column_name = 'estab_assignee_assignment_id'
           AND key_column.referenced_column_name = 'dienstbesetzung_id')
         OR (relation.table_name = 'nv_tbb'
           AND relation.constraint_name = 'fk_tbb_shift'
           AND relation.referenced_table_name = 'nv_dienstschichten'
           AND key_column.column_name = 'estab_shift_id'
           AND key_column.referenced_column_name = 'dienstschicht_id')
         OR (relation.table_name = 'nv_tbb'
           AND relation.constraint_name = 'fk_tbb_writer_assignment'
           AND relation.referenced_table_name = 'nv_dienstbesetzungen'
           AND key_column.column_name = 'estab_writer_assignment_id'
           AND key_column.referenced_column_name = 'dienstbesetzung_id')
       )) = 5)
       AS `logbook_indexes_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND trigger_name IN (
         'estab_einsaetze_bu_evidence',
         'estab_einsaetze_bu_logbook_retention',
         'estab_einsaetze_ai_logbook_heads',
         'estab_etb_bi_einsatz', 'estab_etb_bu_einsatz',
         'estab_etb_bd_einsatz', 'estab_tbb_bi_einsatz',
         'estab_tbb_bu_einsatz', 'estab_tbb_bd_einsatz'
       )) = 9
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND action_timing = 'BEFORE'
       AND event_manipulation = 'INSERT'
       AND ((trigger_name = 'estab_etb_bi_einsatz'
             AND action_statement LIKE '%ETB optional duty provenance must be complete%'
             AND action_statement LIKE
                   '%ETB writer account function or status is invalid%'
             AND action_statement LIKE
                   '%ETB writer duty provenance is invalid%'
             AND action_statement LIKE
                   '%ETB assignee duty provenance is invalid%'
             AND action_statement LIKE
                   '%ETB reference must be a canonical local number%'
             AND action_statement LIKE
                   '%ETB correction requires canonical local reference%'
             AND action_statement LIKE
                   '%SET NEW.`estab_assignment` = assignment_snapshot%'
             AND action_statement NOT LIKE '%duty_shift.`status`%'
             AND action_statement LIKE
                   '%assignment.`status` <> BINARY ''ZURUECKGEZOGEN''%'
             AND action_statement NOT LIKE
                   '%assignment.`status` = BINARY ''ANGENOMMEN''%')
         OR (trigger_name = 'estab_tbb_bi_einsatz'
             AND action_statement LIKE '%TTB optional duty provenance must be complete%'
             AND action_statement LIKE
                   '%TTB writer account function or status is invalid%'
             AND action_statement LIKE
                   '%TTB writer does not belong to its duty shift%'
             AND action_statement LIKE
                   '%TTB writer duty provenance is invalid%'
             AND action_statement LIKE
                   '%TTB message entry requires canonical message link%'
             AND action_statement NOT LIKE '%duty_shift.`status`%'
             AND action_statement NOT LIKE '%assignment.`status`%'))) = 2
   AND
   (SELECT COUNT(*) FROM `nv_etb`
     WHERE `einsatz_id` IS NULL OR `estab_book_lfd` < 1) = 0
   AND
   (SELECT COUNT(*) FROM `nv_tbb`
     WHERE `einsatz_id` IS NULL OR `estab_book_lfd` < 1
       OR `estab_event_time` IS NULL OR `estab_recorded_at` IS NULL
       OR BINARY `estab_entry_type` NOT IN (
         BINARY 'betrieb_personal', BINARY 'kanal', BINARY 'nachricht',
         BINARY 'betriebsereignis', BINARY 'quittung', BINARY 'korrektur',
         BINARY 'legacy_import'
       )
       OR (BINARY `estab_entry_type` <> BINARY 'legacy_import' AND COALESCE(
         NULLIF(TRIM(`estab_personnel_duty`), ''),
         NULLIF(TRIM(`estab_channel`), ''),
         NULLIF(TRIM(`estab_message_route`), ''),
         NULLIF(TRIM(`estab_operations`), ''),
         NULLIF(TRIM(`estab_receipt`), '')
       ) IS NULL)
       OR (`estab_message_id` IS NOT NULL AND (
         BINARY `estab_entry_type` <> BINARY 'nachricht'
         OR BINARY COALESCE(`tbb_kuerzel`, '') <> BINARY 'system'
         OR BINARY COALESCE(`tbb_benutzer`, '') <> BINARY 'eStab-System'
       ))) = 0
   AND
   (SELECT COUNT(*) FROM `nv_tbb` AS entry_row
      LEFT JOIN `nv_nachrichten` AS message_row
        ON message_row.`00_lfd` = entry_row.`estab_message_id`
     WHERE entry_row.`estab_message_id` IS NOT NULL
       AND (message_row.`00_lfd` IS NULL
         OR message_row.`einsatz_id` <> entry_row.`einsatz_id`)) = 0
   AND
   (SELECT COUNT(*)
      FROM (
        SELECT incident_row.`einsatz_id`, 'ETB' AS `buchart`,
               COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1 AS `next_lfd`
          FROM `nv_einsaetze` AS incident_row
          LEFT JOIN `nv_etb` AS entry_row
            ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
         GROUP BY incident_row.`einsatz_id`
        UNION ALL
        SELECT incident_row.`einsatz_id`, 'TTB' AS `buchart`,
               COALESCE(MAX(entry_row.`estab_book_lfd`), 0) + 1 AS `next_lfd`
          FROM `nv_einsaetze` AS incident_row
          LEFT JOIN `nv_tbb` AS entry_row
            ON entry_row.`einsatz_id` = incident_row.`einsatz_id`
         GROUP BY incident_row.`einsatz_id`
      ) AS expected
      LEFT JOIN `nv_logbuch_koepfe` AS head_row
        ON head_row.`einsatz_id` = expected.`einsatz_id`
       AND BINARY head_row.`buchart` = BINARY expected.`buchart`
     WHERE head_row.`einsatz_id` IS NULL
        OR head_row.`next_lfd` <> expected.`next_lfd`) = 0
   AND
   (SELECT COUNT(*) FROM `nv_logbuch_koepfe`)
     = (2 * (SELECT COUNT(*) FROM `nv_einsaetze`)))
       AS `logbook_boundary_ok`,
  ((SELECT COUNT(*) FROM `nv_einsaetze`
     WHERE `estab_status` = 'closed'
       AND (`estab_closed_at` IS NULL OR `estab_retain_until` IS NULL
         OR `estab_retain_until`
              < DATE_ADD(`estab_closed_at`, INTERVAL 10 YEAR))) = 0)
       AS `logbook_retention_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_kennwortrichtlinie'
       AND table_type = 'BASE TABLE'
       AND engine = 'InnoDB'
       AND table_collation = 'utf8mb4_unicode_ci'
       AND table_comment =
         'estab:migration:113:password-policy:v1') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_kennwortrichtlinie') = 9
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_kennwortrichtlinie'
       AND is_nullable = 'NO'
       AND extra = ''
       AND (
         (`column_name` = 'singleton_id'
          AND ordinal_position = 1
          AND data_type = 'tinyint'
          AND column_type LIKE 'tinyint%unsigned'
          AND column_default IS NULL
          AND column_comment = 'estab:migration:113:singleton:v1')
         OR (`column_name` = 'minimum_length'
          AND ordinal_position = 2
          AND data_type = 'smallint'
          AND column_type LIKE 'smallint%unsigned'
          AND column_default = '12'
          AND column_comment =
            'estab:migration:113:minimum-length:v1')
         OR (`column_name` = 'require_uppercase'
          AND ordinal_position = 3
          AND data_type = 'tinyint'
          AND column_type LIKE 'tinyint%unsigned'
          AND column_default = '0'
          AND column_comment =
            'estab:migration:113:require-uppercase:v1')
         OR (`column_name` = 'require_lowercase'
          AND ordinal_position = 4
          AND data_type = 'tinyint'
          AND column_type LIKE 'tinyint%unsigned'
          AND column_default = '0'
          AND column_comment =
            'estab:migration:113:require-lowercase:v1')
         OR (`column_name` = 'require_digit'
          AND ordinal_position = 5
          AND data_type = 'tinyint'
          AND column_type LIKE 'tinyint%unsigned'
          AND column_default = '0'
          AND column_comment = 'estab:migration:113:require-digit:v1')
         OR (`column_name` = 'require_symbol'
          AND ordinal_position = 6
          AND data_type = 'tinyint'
          AND column_type LIKE 'tinyint%unsigned'
          AND column_default = '0'
          AND column_comment = 'estab:migration:113:require-symbol:v1')
         OR (`column_name` = 'revision'
          AND ordinal_position = 7
          AND data_type = 'bigint'
          AND column_type LIKE 'bigint%unsigned'
          AND column_default = '0'
          AND column_comment = 'estab:migration:113:revision:v1')
         OR (`column_name` = 'updated_at'
          AND ordinal_position = 8
          AND data_type = 'datetime'
          AND datetime_precision = 6
          AND LOWER(column_default) = 'current_timestamp(6)'
          AND column_comment = 'estab:migration:113:updated-at:v1')
         OR (`column_name` = 'updated_by'
          AND ordinal_position = 9
          AND data_type = 'varchar'
          AND column_type = 'varchar(128)'
          AND character_set_name = 'utf8mb4'
          AND collation_name = 'utf8mb4_unicode_ci'
          AND column_default = '''migration-113'''
          AND column_comment = 'estab:migration:113:updated-by:v1')
       )) = 9
   AND
   (SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'nv_kennwortrichtlinie') = 8
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_kennwortrichtlinie'
       AND index_name = 'PRIMARY') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_kennwortrichtlinie'
       AND index_name = 'PRIMARY'
       AND non_unique = 0
       AND seq_in_index = 1
       AND column_name = 'singleton_id') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.table_constraints AS table_constraint
      JOIN information_schema.check_constraints AS check_constraint
        ON check_constraint.constraint_schema =
             table_constraint.constraint_schema
       AND check_constraint.constraint_name =
             table_constraint.constraint_name
     WHERE table_constraint.constraint_schema = DATABASE()
       AND table_constraint.table_name = 'nv_kennwortrichtlinie'
       AND table_constraint.constraint_type = 'CHECK'
       AND CONCAT(
             table_constraint.constraint_name,
             ':',
             SHA2(
               REPLACE(
                 REPLACE(LOWER(check_constraint.check_clause), '`', ''),
                 ' ',
                 ''
               ),
               256
             )
           ) IN (
         'chk_kennwortrichtlinie_singleton:88d8e657608a68a0d7a33ff0ac962b4fab9455b1757c39014a936c02860da7b0',
         'chk_kennwortrichtlinie_minimum:d891c2a2c3207579bdd7250dbe1be18004071d459d407e8c25fa548ef218737b',
         'chk_kennwortrichtlinie_uppercase:e59fa8c23e29f1518377e8f0af1efda61c2b18eab331c022ad564af71c851918',
         'chk_kennwortrichtlinie_lowercase:7e3867c98f272ca14b63ed3b662cf871d1ea87d523647150708f1328d50d9ffd',
         'chk_kennwortrichtlinie_digit:df346c79167b8ec0ea4a56b4bb3881917bc40498456e298e2719fa747da100b2',
         'chk_kennwortrichtlinie_symbol:53b28b592d7ff74397ccec21df0b48202d16872dd4f5fcfa5e00cbdef4023f95',
         'chk_kennwortrichtlinie_actor:ae6394da3dd78dde0b7007b20b1a305efe47cee502be7c57ebd44812f3214338'
       )) = 7
   AND
   (SELECT COUNT(*) FROM `nv_kennwortrichtlinie`) = 1
   AND
   (SELECT COUNT(*) FROM `nv_kennwortrichtlinie`
     WHERE `singleton_id` = 1
       AND `minimum_length` BETWEEN 8 AND 128
       AND `require_uppercase` IN (0, 1)
       AND `require_lowercase` IN (0, 1)
       AND `require_digit` IN (0, 1)
       AND `require_symbol` IN (0, 1)
       AND CHAR_LENGTH(`updated_by`) BETWEEN 1 AND 128) = 1)
       AS `password_policy_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_selbstregistrierung'
       AND table_type = 'BASE TABLE'
       AND engine = 'InnoDB'
       AND table_collation = 'utf8mb4_unicode_ci'
       AND table_comment =
         'estab:migration:114:self-registration-policy:v1') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_selbstregistrierung') = 6
   AND
   (SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_selbstregistrierung'
       AND extra = ''
       AND (
         (`column_name` = 'singleton_id'
          AND ordinal_position = 1
          AND is_nullable = 'NO'
          AND data_type = 'tinyint'
          AND column_type LIKE 'tinyint%unsigned'
          AND column_default IS NULL
          AND column_comment = 'estab:migration:114:singleton:v1')
         OR (`column_name` = 'mode'
          AND ordinal_position = 2
          AND is_nullable = 'NO'
          AND data_type = 'enum'
          AND column_type =
            'enum(''ENVIRONMENT'',''DISABLED'',''PERMANENT'',''UNTIL'')'
          AND character_set_name = 'ascii'
          AND collation_name = 'ascii_bin'
          AND column_default = '''ENVIRONMENT'''
          AND column_comment = 'estab:migration:114:mode:v1')
         OR (`column_name` = 'enabled_until_utc'
          AND ordinal_position = 3
          AND is_nullable = 'YES'
          AND data_type = 'datetime'
          AND datetime_precision = 6
          AND column_default = 'NULL'
          AND column_comment =
            'estab:migration:114:enabled-until-utc:v1')
         OR (`column_name` = 'revision'
          AND ordinal_position = 4
          AND is_nullable = 'NO'
          AND data_type = 'bigint'
          AND column_type LIKE 'bigint%unsigned'
          AND column_default = '0'
          AND column_comment = 'estab:migration:114:revision:v1')
         OR (`column_name` = 'updated_at'
          AND ordinal_position = 5
          AND is_nullable = 'NO'
          AND data_type = 'datetime'
          AND datetime_precision = 6
          AND LOWER(column_default) = 'current_timestamp(6)'
          AND column_comment = 'estab:migration:114:updated-at:v1')
         OR (`column_name` = 'updated_by'
          AND ordinal_position = 6
          AND is_nullable = 'NO'
          AND data_type = 'varchar'
          AND column_type = 'varchar(128)'
          AND character_set_name = 'utf8mb4'
          AND collation_name = 'utf8mb4_unicode_ci'
          AND column_default = '''migration-114'''
          AND column_comment = 'estab:migration:114:updated-by:v1')
       )) = 6
   AND
   (SELECT COUNT(*)
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'nv_selbstregistrierung') = 3
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_selbstregistrierung'
       AND index_name = 'PRIMARY') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_selbstregistrierung'
       AND index_name = 'PRIMARY'
       AND non_unique = 0
       AND seq_in_index = 1
       AND column_name = 'singleton_id') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.table_constraints AS table_constraint
      JOIN information_schema.check_constraints AS check_constraint
        ON check_constraint.constraint_schema =
             table_constraint.constraint_schema
       AND check_constraint.constraint_name =
             table_constraint.constraint_name
     WHERE table_constraint.constraint_schema = DATABASE()
       AND table_constraint.table_name = 'nv_selbstregistrierung'
       AND table_constraint.constraint_type = 'CHECK'
       AND CONCAT(
             table_constraint.constraint_name,
             ':',
             SHA2(
               REPLACE(
                 REPLACE(LOWER(check_constraint.check_clause), '`', ''),
                 ' ',
                 ''
               ),
               256
             )
           ) IN (
         'chk_selbstregistrierung_singleton:88d8e657608a68a0d7a33ff0ac962b4fab9455b1757c39014a936c02860da7b0',
         'chk_selbstregistrierung_deadline:fffe6017aa7f7ac8e796ce0cf73e1d20ab0f7499bf021107c9a824c00907eba4'
       )) = 2
   AND
   (SELECT COUNT(*) FROM `nv_selbstregistrierung`) = 1
   AND
   (SELECT COUNT(*) FROM `nv_selbstregistrierung`
     WHERE `singleton_id` = 1
       AND `mode` IN ('ENVIRONMENT','DISABLED','PERMANENT','UNTIL')
       AND ((`mode` = 'UNTIL' AND `enabled_until_utc` IS NOT NULL)
         OR (`mode` <> 'UNTIL' AND `enabled_until_utc` IS NULL))
       AND `revision` <= 9223372036854775807
       AND CHAR_LENGTH(`updated_by`) BETWEEN 1 AND 128
       AND `updated_by` NOT REGEXP _utf8mb4'(*UCP)\\p{C}') = 1)
       AS `self_registration_policy_ok`,
  ((SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'nv_einsaetze'
       AND column_name = 'estab_permission_mode'
       AND data_type = 'enum'
       AND column_type = 'enum(''STRICT'',''LOOSE'')'
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
       AND is_nullable = 'NO'
       AND column_default = '''STRICT'''
       AND extra = ''
       AND column_comment =
         'estab:migration:115:incident-permission-mode:v1') = 1
   AND
   (SELECT COUNT(*)
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND (
         (trigger_name = 'estab_permission_mode_incident_insert'
          AND event_object_table = 'nv_einsaetze'
          AND action_timing = 'BEFORE'
          AND event_manipulation = 'INSERT'
          AND action_statement LIKE
            '%Loose incident creation requires the audited application path%')
         OR
         (trigger_name = 'estab_permission_mode_incident_update'
          AND event_object_table = 'nv_einsaetze'
          AND action_timing = 'BEFORE'
          AND event_manipulation = 'UPDATE'
          AND action_statement LIKE
            '%Direct incident permission-mode manipulation is blocked%')
         OR
         (trigger_name IN (
            'estab_etb_bi_einsatz',
            'estab_tbb_bi_einsatz',
            'estab_dv94_fernmeldeplan_insert',
            'estab_dv94_fernmeldeplan_immutable',
            'estab_dv94_messenger_insert',
            'estab_dv94_messenger_update'
          )
          AND action_statement LIKE '%estab_permission_mode%')
       )) = 8
   AND
   (SELECT COUNT(*) FROM `nv_einsaetze`
     WHERE BINARY `estab_permission_mode` NOT IN (
       BINARY 'STRICT', BINARY 'LOOSE'
     )) = 0)
       AS `incident_permission_mode_ok`,
  ((SELECT COUNT(*) FROM `estab_schema_migrations`) = 21
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
       '99-message-list-search.sql',
       '100-session-presence.sql',
       '110-etb-tbb-rules.sql',
       '111-logbook-shift-assignment.sql',
       '112-optional-access-shifts.sql',
       '113-password-policy.sql',
       '114-self-registration-policy.sql',
       '115-incident-permission-mode.sql'
     )
       AND `state` = 'applied'
       AND `checksum` REGEXP BINARY '^[0-9a-f]{64}$') = 21)
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
