<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$checks = [
    'php' => PHP_VERSION_ID >= 80500,
    'extensions' => !array_filter(
        ['gd', 'mbstring', 'mysqli', 'Zend OPcache', 'zip'],
        static fn (string $extension): bool => !extension_loaded($extension)
    ),
    'database' => false,
    'schema' => false,
    'storage' => false,
];

try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $database = mysqli_init();
    if ($database !== false) {
        $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        $connected = @$database->real_connect(
            getenv('ESTAB_DB_HOST') ?: 'db',
            getenv('ESTAB_DB_USER') ?: 'estab',
            getenv('ESTAB_DB_PASSWORD') ?: '',
            getenv('ESTAB_DB_NAME') ?: 'estab',
            (int) (getenv('ESTAB_DB_PORT') ?: 3306)
        );
        if ($connected) {
            $result = @$database->query('SELECT 1');
            $checks['database'] = $result instanceof mysqli_result && $result->num_rows === 1;
            if ($result instanceof mysqli_result) {
                $result->free();
            }

            $schemaResult = @$database->query(
                "SELECT "
                . "((SELECT COUNT(*) FROM information_schema.tables "
                . "WHERE table_schema = DATABASE() AND table_name IN ("
                . "'nv_nachrichten','nv_empfmtx','nv_empfmtx_standard',"
                . "'nv_benutzer','nv_masterkatego',"
                . "'nv_masterkategolink','nv_protokoll','nv_anhang','nv_etb',"
                . "'nv_tbb','nv_ubb','nv_komplan','nv_bhp50','nv_etbtitel','nv_tbbtitel')) = 15) "
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
                . "AND ((SELECT COUNT(*) FROM estab_schema_migrations) = 3) "
                . "AND ((SELECT COUNT(*) FROM estab_schema_migrations "
                . "WHERE version IN ('20-nullable-dates.sql','30-runtime-schema.sql',"
                . "'40-recipient-matrix-standard.sql') "
                . "AND state = 'applied' "
                . "AND checksum REGEXP BINARY '^[0-9a-f]{64}$') = 3)"
            );
            if ($schemaResult instanceof mysqli_result) {
                $schemaRow = $schemaResult->fetch_row();
                $checks['schema'] = isset($schemaRow[0]) && (int) $schemaRow[0] === 1;
                $schemaResult->free();
            }
            $database->close();
        }
    }
} catch (Throwable) {
    $checks['database'] = false;
}

try {
    $databaseName = estab_env_identifier('ESTAB_DB_NAME', 'estab');
    $storageRoot = __DIR__ . '/4fdata/' . $databaseName;
    $storagePaths = [
        $storageRoot,
        $storageRoot . '/anhang',
        $storageRoot . '/vordruck',
        getenv('ESTAB_EXPORT_DIR') ?: '/var/lib/estab/export',
    ];
    $checks['storage'] = true;

    foreach ($storagePaths as $storagePath) {
        if (!is_dir($storagePath) || !is_writable($storagePath)) {
            $checks['storage'] = false;
            break;
        }
        $probe = @tempnam($storagePath, '.health-');
        if ($probe === false || @file_put_contents($probe, 'ok', LOCK_EX) !== 2) {
            if (is_string($probe) && is_file($probe)) {
                @unlink($probe);
            }
            $checks['storage'] = false;
            break;
        }
        @unlink($probe);
    }
} catch (Throwable) {
    $checks['storage'] = false;
}

$ready = !in_array(false, $checks, true);
http_response_code($ready ? 200 : 503);

echo json_encode(
    [
        'status' => $ready ? 'ready' : 'unavailable',
        'checks' => $checks,
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
