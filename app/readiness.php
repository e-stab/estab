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
        . "'nv_einsaetze','nv_einsatz_status','nv_einsatz_ereignisse')) = 18) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx) = 20) "
        . "AND ((SELECT COUNT(DISTINCT mtx_x, mtx_y) FROM nv_empfmtx) = 20) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_x BETWEEN 1 AND 5 AND mtx_y BETWEEN 1 AND 4) = 20) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_rc2 IN ('t','1')) = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_rc2 IN ('t','1') AND mtx_typ = 'cb' "
        . "AND mtx_fkt <> '' AND mtx_rolle IN ('Stab','FB')) = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx "
        . "WHERE mtx_auto IN ('t','1') AND NOT (mtx_typ = 'cb' "
        . "AND mtx_fkt <> '' AND mtx_rolle IN ('Stab','FB'))) = 0) "
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
        . "AND mtx_fkt <> '' AND mtx_rolle IN ('Stab','FB')) = 1) "
        . "AND ((SELECT COUNT(*) FROM nv_empfmtx_standard "
        . "WHERE mtx_auto IN ('t','1') AND NOT (mtx_typ = 'cb' "
        . "AND mtx_fkt <> '' AND mtx_rolle IN ('Stab','FB'))) = 0) "
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
        . "AND ((SELECT COUNT(*) FROM estab_schema_migrations) = 7) "
        . "AND ((SELECT COUNT(*) FROM estab_schema_migrations "
        . "WHERE version IN ('20-nullable-dates.sql','30-runtime-schema.sql',"
        . "'40-recipient-matrix-standard.sql','45-global-incidents-prepare.sql',"
        . "'50-global-incidents.sql','55-global-incidents-finish.sql',"
        . "'70-user-account-blocking.sql') "
        . "AND state = 'applied' "
        . "AND checksum REGEXP BINARY '^[0-9a-f]{64}$') = 7)";
}
