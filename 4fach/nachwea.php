<?php
define("debug", false);
session_start ();
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/navigation.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/session_ui.php";
require_once __DIR__ . "/../app/nachweisung.php";
estab_navigation_require_session ($_SESSION, "tracking", $_SERVER);
include ("../4fcfg/config.inc.php");  // Konfigurationseinstellungen und Vorgaben
include ("db_operation.php");        // Datenbank operationen
include ("liste.php");          // erzeuge Ausgabelisten
include ("data_hndl.php");      // propritÃ¤re  Datenbankoperationen
//include ("menue.php");          // erzeuge MenÃ¼s

$trackingReadIdentity = estab_read_session_identity ($_SESSION);
estab_navigation_require_selected_duty (
    $_SESSION,
    $trackingReadIdentity,
    "tracking",
    $_SERVER
);
$trackingAccessConnection = null;
$trackingError = null;
try {
    $trackingAccessConnection = estab_auth_connect ($conf_4f_db);
    $trackingScope = estab_read_require_area (
        $trackingAccessConnection,
        $trackingReadIdentity,
        "tracking"
    );
    $trackingIncidentId = (int) (
        $trackingScope ["incident"]["active_einsatz_id"]
    );
    estab_incident_command_post_name ($trackingScope ["incident"]);
} catch (EstabNoActiveIncidentException) {
    $trackingError = array (
        409,
        "Kein aktiver Einsatz",
        "Kein Einsatz aktiv.",
    );
} catch (EstabIncidentConfigurationException) {
    $trackingError = array (
        409,
        "Einsatz noch nicht vollständig eingerichtet",
        "Für den aktiven Einsatz fehlt der Führungsstellenname. "
            ."Legen Sie ihn zuerst in der Einsatzverwaltung fest.",
    );
} catch (EstabReadPermissionException) {
    $trackingError = array (
        403,
        "Keine Berechtigung für die Nachweisung",
        "Die Nachweisung ist nur für eine aktive LdF- oder "
            ."Fernmelder-Funktion verfügbar.",
    );
} catch (Throwable $exception) {
    error_log (
        "eStab tracking authorization failed: ".$exception->getMessage ()
    );
    $trackingError = array (
        503,
        "Nachweisung vorübergehend nicht verfügbar",
        "Die Leseberechtigung kann derzeit nicht geprüft werden.",
    );
} finally {
    if ($trackingAccessConnection instanceof mysqli) {
        estab_auth_close ($trackingAccessConnection);
    }
}
if (is_array ($trackingError)) {
    estab_session_ui_abort (
        $_SESSION,
        $trackingError [0],
        $trackingError [1],
        $trackingError [2],
        "tracking"
    );
}
estab_session_ui_start ($_SESSION);

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
// aria-labelledby zeigte auf eine Ueberschrift, die es nicht mehr gibt. Ein
// Verweis ins Leere benennt nichts.
echo "<section class=\"estab-tool-panel\" aria-label=\"Nachweisung\">\n";
/*
 * Hier stand eine zweite Ueberschrift "Nachrichtenuebersicht" und der Satz,
 * breite Tabellen liessen sich in diesem Bereich waagerecht scrollen. Die
 * Ueberschrift stand neben der der Seite und benannte dasselbe; der Satz
 * beschrieb eine Bauweise, die das Tabellenbauteil selbst mitbringt.
 */
echo "<div class=\"estab-tool-legacy-content\">\n";

/*
 * Die Nachweisung kommt aus dem Tabellenbauteil (app/tabelle.php).
 *
 * Sie hatte weder Suche noch Filter: Wer einen bestimmten Nachweis suchte,
 * blaetterte. Was hier steht, ist nur noch die Frage, welche Liste gemeint
 * ist -- Aufbau, Sortierung, Suche und Blaettern kommen von dort.
 */
try {
  if ( isset ($_GET) ) {
    if ( isset ($_GET["nwe"]) ) {
      estab_nachweisung_ausgeben (
        $conf_4f_db, $trackingIncidentId, "E", "Nachweisung Eingang"
      );
    }
    if ( isset ($_GET["nwa"]) ) {
      estab_nachweisung_ausgeben (
        $conf_4f_db, $trackingIncidentId, "A", "Nachweisung Ausgang"
      );
    }
    if ( isset ($_GET["nwalle"]) ) {
      if (Nachweisung == "gemeinsam"){
        estab_nachweisung_ausgeben (
          $conf_4f_db, $trackingIncidentId, "", "Nachweisung Eingang und Ausgang"
        );
      } elseif (Nachweisung == "getrennt") {
        estab_nachweisung_ausgeben (
          $conf_4f_db, $trackingIncidentId, "E", "Nachweisung Eingang"
        );
        estab_nachweisung_ausgeben (
          $conf_4f_db, $trackingIncidentId, "A", "Nachweisung Ausgang"
        );
      }
    }
  }
} catch (EstabIncidentConfigurationException) {
  if (ob_get_level () > 0) {
    @ob_clean ();
  }
  estab_session_ui_abort (
    $_SESSION,
    409,
    "Einsatz noch nicht vollständig eingerichtet",
    "Für den aktiven Einsatz fehlt der Führungsstellenname. ".
      "Legen Sie ihn zuerst in der Einsatzverwaltung fest.",
    "tracking"
  );
}

echo "</div>\n</section>\n";
echo "<footer class=\"estab-tool-footer\">\n";
echo "<a href=\"".estab_auth_html (estab_application_root ())."\">";
echo "Zur eStab-Übersicht</a>\n";
echo "<span>Es werden ausschließlich Daten des aktiven Einsatzes angezeigt.</span>\n";
echo "</footer>\n</main>\n</body></html>\n";

?>
