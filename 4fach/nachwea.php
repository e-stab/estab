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
echo "</head><body class=\"estab-tool-page\">\n";
echo "<main class=\"estab-tool-main estab-tool-main-wide\" ";
echo "data-estab-tracking-overview>\n";
echo "<header class=\"estab-tool-hero\">\n";
echo "<p class=\"estab-tool-eyebrow\">Nachrichten · Nachweisung</p>\n";
echo "<h1>Nachweisung Eingang und Ausgang</h1>\n";
echo "<p>Diese Übersicht zeigt die Nachweislisten des aktuell aktiven Einsatzes. ";
echo "Filter und Detailaufrufe verändern keine Nachricht.</p>\n";
echo "</header>\n";
echo "<section class=\"estab-tool-panel\" aria-labelledby=\"tracking-list-title\">\n";
echo "<header class=\"estab-tool-panel-heading\">\n";
echo "<h2 id=\"tracking-list-title\">Nachrichtenübersicht</h2>\n";
echo "<p>Breite Tabellen können innerhalb dieses Bereichs horizontal ";
echo "gescrollt werden.</p>\n</header>\n";
echo "<div class=\"estab-tool-legacy-content\">\n";


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

echo "</div>\n</section>\n";
echo "<footer class=\"estab-tool-footer\">\n";
echo "<a href=\"".estab_auth_html (estab_application_root ())."\">";
echo "Zur eStab-Übersicht</a>\n";
echo "<span>Es werden ausschließlich Daten des aktiven Einsatzes angezeigt.</span>\n";
echo "</footer>\n</main>\n</body></html>\n";

?>
