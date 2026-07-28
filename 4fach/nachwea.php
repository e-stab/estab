<?php
define("debug", false);
session_start ();
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/session_ui.php";
estab_auth_require_session ($_SESSION);
estab_session_ui_start ($_SESSION);
include ("../4fcfg/config.inc.php");  // Konfigurationseinstellungen und Vorgaben
include ("db_operation.php");        // Datenbank operationen
include ("liste.php");          // erzeuge Ausgabelisten
include ("data_hndl.php");      // propritÃ¤re  Datenbankoperationen
//include ("menue.php");          // erzeuge MenÃ¼s

header ("Content-Type: text/html; charset=UTF-8");
header ("Cache-Control: private, no-store, max-age=0");
echo "<!doctype html>\n";
echo "<html lang=\"de\"><head><meta charset=\"UTF-8\">\n";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
echo "<title>Nachweisung Eingang / Ausgang</title>\n";
echo estab_session_ui_stylesheet ()."\n";
echo "</head><body bgcolor=\"#DCDCFF\"><main>\n";


  if ( isset ($_GET) ) {
    if ( isset ($_GET["nwe"]) ) {
      $list = new listen ("FmNwE", "");
      $list->createlist ();
    }
    if ( isset ($_GET["nwa"]) ) {
      $list = new listen ("FmNwA", "");
      $list->createlist ();
    }
    if ( isset ($_GET["nwalle"]) ) {
      if (Nachweisung == "gemeinsam"){
        $list = new listen ("FmNw", "");
        $list->createlist ();
      } elseif (Nachweisung == "getrennt") {
        $list = new listen ("FmNwE", "");
        $list->createlist ();
        $list = new listen ("FmNwA", "");
        $list->createlist ();
      }
    }
  }

echo "</main></body></html>\n";

?>
