<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Return the one canonical runtime-readiness report used by both the public
 * health endpoint and the authenticated administration view.
 *
 * @return array{
 *   ready: bool,
 *   checks: array{php: bool, extensions: bool, configuration: bool, database: bool, schema: bool, storage: bool},
 *   extensions: array<string, bool>,
 *   database_tables: int|null,
 *   storage: array<string, bool>
 * }
 */
function estab_readiness_report(): array
{
    $extensions = [];
    foreach (['gd', 'mbstring', 'mysqli', 'Zend OPcache', 'zip'] as $extension) {
        $extensions[$extension] = extension_loaded($extension);
    }

    $checks = [
        'php' => PHP_VERSION_ID >= 80500,
        'extensions' => !in_array(false, $extensions, true),
        'configuration' => false,
        'database' => false,
        'schema' => false,
        'storage' => false,
    ];
    $databaseTables = null;

    try {
        estab_validate_runtime_configuration();
        $checks['configuration'] = true;
    } catch (Throwable) {
        $checks['configuration'] = false;
    }

    if ($extensions['mysqli']) {
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $database = mysqli_init();
            if ($database !== false) {
                $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
                $connected = @$database->real_connect(
                    estab_env('ESTAB_DB_HOST', 'db') ?? 'db',
                    estab_env('ESTAB_DB_USER', 'estab') ?? 'estab',
                    estab_env('ESTAB_DB_PASSWORD', '') ?? '',
                    estab_env_identifier('ESTAB_DB_NAME', 'estab'),
                    estab_env_integer('ESTAB_DB_PORT', 3306, 1, 65535)
                );
                if ($connected) {
                    $readResult = @$database->query('SELECT 1');
                    $checks['database'] = $readResult instanceof mysqli_result
                        && $readResult->num_rows === 1;
                    if ($readResult instanceof mysqli_result) {
                        $readResult->free();
                    }

                    $tableResult = @$database->query(
                        'SELECT COUNT(*) FROM information_schema.tables '
                        . 'WHERE table_schema = DATABASE() '
                        . 'AND table_type = \'BASE TABLE\''
                    );
                    if ($tableResult instanceof mysqli_result) {
                        $tableRow = $tableResult->fetch_row();
                        $databaseTables = isset($tableRow[0])
                            ? (int) $tableRow[0]
                            : null;
                        $tableResult->free();
                    }

                    $schemaResult = @$database->query(
                        estab_readiness_schema_query()
                    );
                    if ($schemaResult instanceof mysqli_result) {
                        $schemaRow = $schemaResult->fetch_row();
                        $checks['schema'] = isset($schemaRow[0])
                            && (int) $schemaRow[0] === 1;
                        $schemaResult->free();
                    }
                    $database->close();
                }
            }
        } catch (Throwable) {
            $checks['database'] = false;
            $checks['schema'] = false;
        }
    }

    $storage = [];
    try {
        $databaseName = estab_env_identifier('ESTAB_DB_NAME', 'estab');
        $storageRoot = dirname(__DIR__) . '/4fdata/' . $databaseName;
        $storagePaths = [
            'Anwendungsdaten' => $storageRoot,
            'Anhangsspeicher' => $storageRoot . '/anhang',
            'Vordruckspeicher' => $storageRoot . '/vordruck',
            'Einsatzexport' => estab_env(
                'ESTAB_EXPORT_DIR',
                '/var/lib/estab/export'
            ) ?? '/var/lib/estab/export',
        ];
        foreach ($storagePaths as $label => $storagePath) {
            $storage[$label] = estab_readiness_storage_path($storagePath);
        }
        $checks['storage'] = !in_array(false, $storage, true);
    } catch (Throwable) {
        $storage = ['Konfiguration' => false];
    }

    return [
        'ready' => !in_array(false, $checks, true),
        'checks' => $checks,
        'extensions' => $extensions,
        'database_tables' => $databaseTables,
        'storage' => $storage,
    ];
}

/** Test one storage path with the same real write probe as the container check. */
function estab_readiness_storage_path(string $storagePath): bool
{
    if (!is_dir($storagePath) || !is_writable($storagePath)) {
        return false;
    }

    $probe = @tempnam($storagePath, '.health-');
    if ($probe === false) {
        return false;
    }
    $written = @file_put_contents($probe, 'ok', LOCK_EX);
    $removed = @unlink($probe);
    return $written === 2 && $removed;
}

/** Keep the exact post-migration database contract in one location. */
function estab_readiness_schema_query(): string
{
    return "SELECT "
        . "((SELECT COUNT(*) FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name IN ("
        . "'nv_nachrichten','nv_empfmtx','nv_empfmtx_standard',"
        . "'nv_benutzer','nv_masterkatego',"
        . "'nv_masterkategolink','nv_protokoll','nv_anhang','nv_etb',"
        . "'nv_tbb','nv_ubb','nv_komplan','nv_bhp50','nv_etbtitel','nv_tbbtitel',"
        . "'nv_einsaetze','nv_einsatz_status','nv_einsatz_ereignisse',"
        . "'nv_nachrichten_ereignisse','nv_nachrichten_nachweiskopf',"
        . "'nv_funktionsfaehigkeiten','nv_betriebsereignis_kopf',"
        . "'nv_betriebsereignisse','nv_dienstschichten',"
        . "'nv_dienstbesetzungen','nv_dienstuebergabe_anfragen',"
        . "'nv_dienstuebergaben',"
        . "'nv_fernmeldeplaene','nv_fernmeldeplan_eintraege',"
        . "'nv_melderauftraege')) = 30) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx) = 20) "
        . "AND ((SELECT COUNT(DISTINCT mtx_x, mtx_y) FROM nv_empfmtx) = 20) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_x BETWEEN 1 AND 5 AND mtx_y BETWEEN 1 AND 4) = 20) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_rc2 IN ('t','1')) = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_rc2 IN ('t','1') AND mtx_typ = 'cb' "
        . "AND BINARY mtx_fkt = BINARY 'S2' "
        . "AND BINARY mtx_rolle = BINARY 'Stab') = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_auto IN ('t','1')) = 0) "
        . "AND ((SELECT COUNT(*) FROM nv_benutzer AS assignment_user "
        . "WHERE assignment_user.aktiv = 1 AND NOT ("
        . "(BINARY assignment_user.funktion = BINARY 'Si' "
        . "AND BINARY assignment_user.rolle = BINARY 'Stab') OR "
        . "(BINARY assignment_user.funktion = BINARY 'A/W' "
        . "AND BINARY assignment_user.rolle = BINARY 'Fernmelder') OR "
        . "(BINARY assignment_user.funktion = BINARY 'LdF' "
        . "AND BINARY assignment_user.rolle = BINARY 'Fernmelder') OR "
        . "EXISTS (SELECT 1 FROM nv_empfmtx AS assignment_matrix "
        . "WHERE assignment_matrix.mtx_typ = 'cb' "
        . "AND BINARY assignment_matrix.mtx_fkt "
        . "= BINARY assignment_user.funktion "
        . "AND BINARY assignment_matrix.mtx_rolle "
        . "= BINARY assignment_user.rolle))) = 0) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx_standard) = 20) "
        . "AND ((SELECT COUNT(DISTINCT mtx_x, mtx_y) "
        . "FROM nv_empfmtx_standard) = 20) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx_standard "
        . "WHERE mtx_x BETWEEN 1 AND 5 AND mtx_y BETWEEN 1 AND 4) = 20) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx_standard "
        . "WHERE mtx_rc2 IN ('t','1')) = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx_standard "
        . "WHERE mtx_rc2 IN ('t','1') AND mtx_typ = 'cb' "
        . "AND BINARY mtx_fkt = BINARY 'S2' "
        . "AND BINARY mtx_rolle = BINARY 'Stab') = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx_standard "
        . "WHERE mtx_auto IN ('t','1')) = 0) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' "
        . "AND index_name = 'uq_anhang_filename' AND non_unique = 0 "
        . "AND seq_in_index = 1 AND column_name = 'filename') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' "
        . "AND index_name = 'uq_anhang_filename') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND character_maximum_length = 6 AND ("
        . "(table_name = 'nv_benutzer' AND column_name = 'kuerzel') OR "
        . "(table_name = 'nv_anhang' AND column_name = 'kuerzel') OR "
        . "(table_name = 'nv_nachrichten' AND column_name IN ("
        . "'01_zeichen','02_zeichen','03_zeichen','14_zeichen',"
        . "'15_quitzeichen','x03_sperruser')))) = 8) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_benutzer' AND ("
        . "(column_name = 'password' AND character_maximum_length = 255) OR "
        . "(column_name IN ('ip','fwdip') AND character_maximum_length = 45))) = 3) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' AND ("
        . "(column_name = 'fileext' AND character_maximum_length = 16) OR "
        . "(column_name = 'id' AND character_maximum_length = 128))) = 2) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' AND ("
        . "(column_name = 'integrity_required' AND data_type = 'tinyint' "
        . "AND is_nullable = 'NO' AND column_default = '1') OR "
        . "(column_name = 'ingest_sha256' AND data_type = 'char' "
        . "AND character_maximum_length = 64 "
        . "AND character_set_name = 'ascii' "
        . "AND collation_name = 'ascii_bin' AND is_nullable = 'YES') OR "
        . "(column_name = 'ingest_size' AND data_type = 'bigint' "
        . "AND column_type LIKE '%unsigned%' AND is_nullable = 'YES') OR "
        . "(column_name = 'integrity_captured_at' AND data_type = 'datetime' "
        . "AND datetime_precision = 6 AND is_nullable = 'YES'))) = 4) "
        . "AND ((SELECT COUNT(*) FROM information_schema.triggers "
        . "WHERE trigger_schema = DATABASE() AND trigger_name IN ("
        . "'estab_attachment_integrity_bi',"
        . "'estab_attachment_integrity_bu')) = 2) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_benutzer' "
        . "AND index_name = 'idx_benutzer_funktion_aktiv') = 2) "
        . "AND ((SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') "
        . "FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_benutzer' "
        . "AND index_name = 'idx_benutzer_funktion_aktiv') = 'funktion,aktiv') "
        . "AND ((SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') "
        . "FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' "
        . "AND index_name = 'idx_anhang_filename_status') = 'filename,status') "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' "
        . "AND index_name = 'idx_anhang_id') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' "
        . "AND index_name = 'idx_anhang_id' AND seq_in_index = 1 "
        . "AND column_name = 'id') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' "
        . "AND index_name = 'idx_anhang_md5hash') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_anhang' "
        . "AND index_name = 'idx_anhang_md5hash' AND seq_in_index = 1 "
        . "AND column_name = 'md5hash') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_benutzer' "
        . "AND column_name = 'estab_gesperrt' AND data_type = 'tinyint' "
        . "AND column_type LIKE 'tinyint%unsigned' AND is_nullable = 'NO' "
        . "AND column_default = '0') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND column_name = 'einsatz_id' "
        . "AND data_type = 'bigint' AND column_type LIKE '%unsigned%' "
        . "AND is_nullable = 'YES' AND table_name IN ("
        . "'nv_nachrichten','nv_anhang','nv_etb','nv_tbb','nv_ubb',"
        . "'nv_protokoll','nv_bhp50','nv_komplan','nv_etbtitel','nv_tbbtitel')) = 10) "
        . "AND ((SELECT COUNT(*) FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name IN ("
        . "'nv_einsaetze','nv_einsatz_status','nv_einsatz_ereignisse') "
        . "AND table_comment = 'estab:migration:50-global-incidents:v1') = 3) "
        . "AND ((SELECT COUNT(*) FROM information_schema.referential_constraints "
        . "WHERE constraint_schema = DATABASE() AND constraint_name IN ("
        . "'fk_einsatz_status_active','fk_einsatz_ereignisse_einsatz',"
        . "'fk_nachrichten_einsatz','fk_anhang_einsatz','fk_etb_einsatz',"
        . "'fk_tbb_einsatz','fk_ubb_einsatz','fk_protokoll_einsatz',"
        . "'fk_bhp50_einsatz','fk_komplan_einsatz','fk_etbtitel_einsatz',"
        . "'fk_tbbtitel_einsatz')) = 12) "
        . "AND ((SELECT COUNT(*) FROM nv_einsatz_status "
        . "WHERE singleton_id = 1 AND revision >= 0) = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_einsatz_status) = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.triggers "
        . "WHERE trigger_schema = DATABASE() AND action_timing = 'BEFORE' "
        . "AND event_manipulation IN ('INSERT','UPDATE','DELETE') "
        . "AND event_object_table IN ("
        . "'nv_nachrichten','nv_anhang','nv_etb','nv_tbb','nv_ubb',"
        . "'nv_bhp50','nv_komplan','nv_etbtitel','nv_tbbtitel') "
        . "AND trigger_name LIKE 'estab\\_%\\_einsatz') = 27) "
        . "AND ((SELECT COUNT(DISTINCT CONCAT(event_object_table,':',"
        . "event_manipulation)) FROM information_schema.triggers "
        . "WHERE trigger_schema = DATABASE() AND action_timing = 'BEFORE' "
        . "AND event_manipulation IN ('INSERT','UPDATE','DELETE') "
        . "AND event_object_table IN ("
        . "'nv_nachrichten','nv_anhang','nv_etb','nv_tbb','nv_ubb',"
        . "'nv_bhp50','nv_komplan','nv_etbtitel','nv_tbbtitel') "
        . "AND trigger_name LIKE 'estab\\_%\\_einsatz') = 27) "
        . "AND ((SELECT COUNT(*) FROM information_schema.triggers "
        . "WHERE trigger_schema = DATABASE() "
        . "AND event_object_table = 'nv_protokoll' "
        . "AND trigger_name LIKE 'estab\\_%\\_einsatz') = 0) "
        . "AND ((SELECT COUNT(*) FROM information_schema.routines "
        . "WHERE routine_schema = DATABASE() AND routine_type = 'FUNCTION' "
        . "AND routine_name IN ('estab_incident_for_insert',"
        . "'estab_incident_for_update','estab_incident_for_delete')) = 3) "
        . "AND ((SELECT COUNT(*) FROM nv_nachrichten WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_anhang WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_etb WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_tbb WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_ubb WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_bhp50 WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_komplan WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_etbtitel WHERE einsatz_id IS NULL) "
        . "+ (SELECT COUNT(*) FROM nv_tbbtitel WHERE einsatz_id IS NULL) = 0) "
        . "AND ((SELECT COUNT(*) FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name IN ("
        . "'nv_nachrichten_ereignisse','nv_nachrichten_nachweiskopf') "
        . "AND table_comment = "
        . "'estab:migration:80-dv-evidence-retention:v1') = 2) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_einsaetze' "
        . "AND column_name IN ('estab_status','estab_closed_at',"
        . "'estab_closed_by','estab_close_note','estab_retain_until',"
        . "'estab_legal_hold','estab_legal_hold_reason',"
        . "'estab_legal_hold_at','estab_legal_hold_by')) = 9) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_etb' "
        . "AND column_name IN ('estab_event_time','estab_recorded_at',"
        . "'estab_event_type','estab_message_id','estab_attachment_id',"
        . "'estab_reference','estab_correction_of')) = 7) "
        . "AND ((SELECT COUNT(*) FROM information_schema.triggers "
        . "WHERE trigger_schema = DATABASE() AND trigger_name IN ("
        . "'estab_einsatz_status_bi_open','estab_einsatz_status_bu_open',"
        . "'estab_einsaetze_bu_evidence','estab_einsaetze_bd_retention',"
        . "'estab_etb_bi_einsatz','estab_etb_bu_einsatz',"
        . "'estab_etb_bd_einsatz','estab_message_events_bi_evidence',"
        . "'estab_message_events_bu_append_only',"
        . "'estab_message_events_bd_append_only',"
        . "'estab_message_evidence_heads_bd_protected',"
        . "'estab_incident_events_bu_append_only',"
        . "'estab_incident_events_bd_append_only')) = 13) "
        . "AND ((SELECT COUNT(*) FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name IN ("
        . "'nv_funktionsfaehigkeiten','nv_betriebsereignis_kopf',"
        . "'nv_betriebsereignisse','nv_dienstschichten',"
        . "'nv_dienstbesetzungen','nv_dienstuebergabe_anfragen',"
        . "'nv_dienstuebergaben',"
        . "'nv_fernmeldeplaene','nv_fernmeldeplan_eintraege',"
        . "'nv_melderauftraege') AND table_comment = "
        . "'estab:migration:94-dv-organisational-controls:v1') = 10) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'nv_nachrichten' "
        . "AND column_name = 'estab_fernmeldeplan_eintrag_id' "
        . "AND data_type = 'bigint' AND column_type LIKE '%unsigned%' "
        . "AND is_nullable = 'YES') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_melderauftraege' AND ("
        . "(column_name = 'ruecknachricht_vorhanden' "
        . "AND data_type = 'tinyint' AND is_nullable = 'YES') OR "
        . "(column_name = 'offener_nachrichtenauftrag' "
        . "AND data_type = 'bigint' "
        . "AND extra LIKE '%STORED GENERATED%'))) = 2) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_melderauftraege' "
        . "AND index_name = 'uq_melderauftrag_offene_nachricht' "
        . "AND column_name = 'offener_nachrichtenauftrag' "
        . "AND non_unique = 0) = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_funktionsfaehigkeiten "
        . "WHERE (funktion, rolle, faehigkeit, bezeichnung) IN ("
        . "('S2','Stab','LAGE_DOKUMENTATION','Lage und Dokumentation'),"
        . "('S2','Stab','EINSATZTAGEBUCH','Einsatztagebuchführung'),"
        . "('ETB','Stab','EINSATZTAGEBUCH','Einsatztagebuchführung'),"
        . "('Si','Stab','SICHTUNG','Sichter'),"
        . "('S6','Stab','FERNMELDEPLANUNG','Fernmeldeplanung'),"
        . "('LdF','Fernmelder','FERNMELDEBETRIEB',"
        . "'Leiter der Fernmeldezentrale'),"
        . "('A/W','Fernmelder','BEFOERDERUNG',"
        . "'Aufnahme und Weitergabe'))) = 7) "
        . "AND ((SELECT COUNT(*) FROM nv_funktionsfaehigkeiten) = 7) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_funktionsfaehigkeiten' "
        . "AND column_name = 'faehigkeit' AND data_type = 'enum' "
        . "AND column_type = 'enum(''LAGE_DOKUMENTATION'',"
        . "''EINSATZTAGEBUCH'',''SICHTUNG'',''FERNMELDEPLANUNG'',"
        . "''FERNMELDEBETRIEB'',''BEFOERDERUNG'')' "
        . "AND is_nullable = 'NO') = 1) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_funktionsfaehigkeiten') = 4) "
        . "AND ((SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_funktionsfaehigkeiten' "
        . "AND character_set_name = 'utf8mb4' "
        . "AND collation_name = 'utf8mb4_unicode_ci' "
        . "AND is_nullable = 'NO' AND column_default IS NULL "
        . "AND extra = '' AND ("
        . "(column_name = 'funktion' AND ordinal_position = 1 "
        . "AND data_type = 'varchar' AND column_type = 'varchar(6)' "
        . "AND character_maximum_length = 6) OR "
        . "(column_name = 'rolle' AND ordinal_position = 2 "
        . "AND data_type = 'enum' "
        . "AND column_type = 'enum(''Stab'',''FB'',''Fernmelder'')') OR "
        . "(column_name = 'faehigkeit' AND ordinal_position = 3 "
        . "AND data_type = 'enum' "
        . "AND column_type = 'enum(''LAGE_DOKUMENTATION'',"
        . "''EINSATZTAGEBUCH'',''SICHTUNG'',''FERNMELDEPLANUNG'',"
        . "''FERNMELDEBETRIEB'',''BEFOERDERUNG'')') OR "
        . "(column_name = 'bezeichnung' AND ordinal_position = 4 "
        . "AND data_type = 'varchar' AND column_type = 'varchar(100)' "
        . "AND character_maximum_length = 100))) = 4) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_funktionsfaehigkeiten' "
        . "AND index_name = 'PRIMARY') = 2) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_funktionsfaehigkeiten' "
        . "AND index_name = 'PRIMARY' AND non_unique = 0 AND ("
        . "(seq_in_index = 1 AND column_name = 'funktion') OR "
        . "(seq_in_index = 2 AND column_name = 'faehigkeit'))) = 2) "
        . "AND ((SELECT COUNT(*) FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() "
        . "AND table_name = 'nv_funktionsfaehigkeiten' "
        . "AND index_name <> 'PRIMARY') = 0) "
        . "AND ((SELECT COUNT(*) FROM information_schema.triggers "
        . "WHERE trigger_schema = DATABASE() AND trigger_name IN ("
        . "'estab_dv94_message_route_update',"
        . "'estab_dv94_fernmeldeplan_immutable',"
        . "'estab_dv94_fernmeldeplan_entry_insert',"
        . "'estab_dv94_fernmeldeplan_entry_update',"
        . "'estab_dv94_fernmeldeplan_entry_delete',"
        . "'estab_dv94_shift_insert','estab_dv94_shift_update',"
        . "'estab_dv94_shift_delete','estab_dv94_hat_insert',"
        . "'estab_dv94_hat_update','estab_dv94_hat_delete',"
        . "'estab_dv94_handover_insert','estab_dv94_handover_update',"
        . "'estab_dv94_handover_delete',"
        . "'estab_dv94_handover_request_insert',"
        . "'estab_dv94_handover_request_update',"
        . "'estab_dv94_handover_request_delete',"
        . "'estab_dv94_fernmeldeplan_insert',"
        . "'estab_dv94_fernmeldeplan_delete',"
        . "'estab_dv94_messenger_insert','estab_dv94_messenger_update',"
        . "'estab_dv94_messenger_delete','estab_dv94_event_insert',"
        . "'estab_dv94_event_head_insert','estab_dv94_event_update',"
        . "'estab_dv94_event_delete','estab_dv94_event_head_update',"
        . "'estab_dv94_event_head_delete')) = 28) "
        . "AND ((SELECT COUNT(*) FROM estab_schema_migrations) = 11) "
        . "AND ((SELECT COUNT(*) FROM estab_schema_migrations "
        . "WHERE version IN ('20-nullable-dates.sql','30-runtime-schema.sql',"
        . "'40-recipient-matrix-standard.sql','45-global-incidents-prepare.sql',"
        . "'50-global-incidents.sql','55-global-incidents-finish.sql',"
        . "'70-user-account-blocking.sql','80-dv-evidence-retention.sql',"
        . "'94-dv-organisational-controls.sql',"
        . "'95-attachment-ingest-integrity.sql',"
        . "'96-etb-duty-function.sql') "
        . "AND state = 'applied' "
        . "AND checksum REGEXP BINARY '^[0-9a-f]{64}$') = 11)";
}
