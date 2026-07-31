<?php
require_once __DIR__ . '/../app/bootstrap.php';
/******************************************************************************\ 
              Definitionen fuer den Einsatz                                        
\******************************************************************************/ 
$conf_4f_db ["datenbank"]     = estab_env_identifier("ESTAB_DB_NAME", "estab");
// Request-local compatibility key. Authenticated controllers replace it with
// the authoritative name of the active incident before rendering legacy forms.
$conf_4f     ["anschrift"]    = "";
$conf_4f     ["hoheit"]       = estab_env("ESTAB_AUTHORITY_CODE", "EL");



?>
