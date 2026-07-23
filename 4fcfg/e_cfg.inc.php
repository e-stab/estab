<?php
require_once __DIR__ . '/../app/bootstrap.php';
/******************************************************************************\ 
              Definitionen fuer den Einsatz                                        
\******************************************************************************/ 
$conf_4f_db ["datenbank"]     = estab_env_identifier("ESTAB_DB_NAME", "estab");
$conf_4f     ["anschrift"]    = estab_env("ESTAB_ORGANISATION", "Einsatzleitung");
$conf_4f     ["hoheit"]       = estab_env("ESTAB_AUTHORITY_CODE", "EL");



?>
