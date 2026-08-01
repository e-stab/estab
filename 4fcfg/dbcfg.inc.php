<?php
require_once __DIR__ . '/../app/bootstrap.php';
/******************************************************************************\ 
              Definitionen fuer den Datenbankzugriff                              
\******************************************************************************/ 
$conf_4f_db   ["server"]        = estab_env("ESTAB_DB_HOST", "db");
$conf_4f_db   ["user"]          = estab_env("ESTAB_DB_USER", "estab");
$conf_4f_db   ["password"]      = estab_env("ESTAB_DB_PASSWORD", "");
$conf_4f_db   ["datenbank"]     = estab_env_identifier("ESTAB_DB_NAME", "estab");
$conf_4f_tbl  ["prefix"]        = "nv_" ;
$conf_4f_tbl  ["benutzer"]      = "nv_benutzer"; 
$conf_4f_tbl  ["masterkatego"]  = "nv_masterkatego"; 
$conf_4f_tbl  ["masterkategolk"]= "nv_masterkategolink"; 
$conf_4f_tbl  ["nachrichten"]   = "nv_nachrichten"; 
$conf_4f_tbl  ["empfmtx"]       = "nv_empfmtx"; 
$conf_4f_tbl  ["protokoll"]     = "nv_protokoll"; 
$conf_4f_tbl  ["anhang"]        = "nv_anhang"; 
$conf_4f_tbl  ["usrtblprefix"]  = "usr_"; 
$conf_tbl     ["bhp50"]         = "nv_bhp50"; 
$conf_tbl     ["komplan"]       = "nv_komplan"; 
$conf_tbl     ["etb"]           = "nv_etb"; 
$conf_tbl     ["tbb"]           = "nv_tbb"; 
$conf_tbl     ["ubb"]           = "nv_ubb";

require_once __DIR__ . '/../app/operational_guard.php';
estab_operational_write_enforce(
    $conf_4f_db,
    isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [],
    $_SERVER,
    $_POST,
    $_GET
);

?>
