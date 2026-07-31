<?php

if (!defined ("debug")) { define ("debug", false); }
if (ob_get_level () === 0) { ob_start (); }
/******************************************************************************\
technisches Betriebsbuch

  Szenario "Kein globaler Einsatz aktiv."

    + Roter Sperrhinweis mit Verweis auf die Einsatzverwaltung
    + Keine fachlichen Eingaben

  Szenario "Globaler Einsatz aktiv."

    + Anzeige des globalen Einsatzkopfs
    + Lesender Zugriff nur mit ausgewaehlter, aktiver Dienstfunktion
    + Eintragsfunktion fuer A/W in der Rolle Fernmelder

  Szenario "Schaltflaeche TBB-Eintrag wird betaetigt"

    + Anzeige des globalen Einsatzkopfs
    + Anzeige des Menues zur Eingabe eines TBB Eintrags

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/
class tbb_liste {


  var $tbb_titel_tbl     = false ;
  var $tbb_titel_gesetzt = false;
  var $tbb_einsatz_aktiv = false;
  var $tbb_art ;
  var $tbb_ort ;
  var $tbb_fuehrungsstelle ;

  var $tbb_funktion ;
  var $tbb_kuerzel ;
  var $tbb_benutzer ;
  var $tbb_rolle ;
  var $tbb_authorized ;
  public int $tbb_duty_assignment_id = 0;

/*****************************************************************************\

\*****************************************************************************/
  // Klassenkonstruktor
  function __construct (){
    $this->tbb_liste ();
  }

  function tbb_liste (){
    $this->read_out_tbbtitel ();
      if (debug == true){    echo "tbb_liste 2 ->"; var_dump ($this->tbb_titel_gesetzt); echo "<br>";}
    $conf_tbb [0] = "Lfd.-Nr.";
    $conf_tbb [1] = "Datum/Zeit";
    $conf_tbb [2] = "Darstellung der Ereignisse";
    $conf_tbb [3] = "Bemerkung";
    $conf_tbb [4] = "Kürzel";
    $this->spaltenanzahl = count ($conf_tbb);
    $this->spaltenkoepfe = $conf_tbb;
  }


  var $db_server ;
  var $db_name ;
  var $db_table ;
  var $db_user ;
  var $db_pw ;

  var $db_sqlquery;
  var $db_result;
  var $sqlquery;
  var $result = "";
  var $resultcount = 0;


/*****************************************************************************\

\*****************************************************************************/
  function tbb_ueberschrift(){
    echo "<header class=\"estab-tool-hero\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Einsatzdokumentation · TBB</p>\n";
    echo "<h1>Technisches Betriebsbuch</h1>\n";
    echo "<p>Chronologische Dokumentation des technischen Betriebs im aktiven ";
    echo "Einsatz. ";
    echo $this->tbb_authorized
      ? "Ihre Funktion darf neue Einträge anlegen."
      : "Ihre Funktion hat lesenden Zugriff.";
    echo "</p>\n</header>\n";
  }


/*****************************************************************************\

\*****************************************************************************/
  function set_db_para ($newdb_server, $newdb_name, $newdb_table, $newdb_user, $newdb_pw){
    $this->db_server = $newdb_server ;
    $this->db_name   = $newdb_name ;
    $this->db_table  = $newdb_table ;
    $this->db_user   = $newdb_user ;
    $this->db_pw     = $newdb_pw ;
// echo "Datenbankparameter = ".$this->db_server." - ".$this->db_name." - ".$this->db_table." - ".$this->db_user." - ".$this->db_pw."<br>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function query_table ($query){
    $this->result = array ();
    $this->sqlquery = $query ;

    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");
    mysql_query('SET NAMES utf8mb4');
    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table] 103-".mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);

    for ($i=1;$i<=$this->resultcount;$i++){
      $this->result[$i] = mysql_fetch_assoc($query_result);
    }
    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->resultcount > 0 ? $this->result : "");
  } // function read_table


/*****************************************************************************\

\*****************************************************************************/
  function speichen_tbbtitel ($daten){
    unset ($daten);
    throw new LogicException (
      "Einsatzdaten werden ausschließlich in der Administration verwaltet."
    );
  }


/*****************************************************************************\

\*****************************************************************************/
  function create_tbbtitel_tbl(){
    // Legacy compatibility hook. Global incidents are migration-owned.
  }


/*****************************************************************************\

\*****************************************************************************/
  function tbb_tableexist () {
    $this->tbb_titel_tbl = true;
if (debug == true){ echo "tbb_tableexist==>"; var_dump($this->tbb_titel_tbl); echo "<br>"; }
    return true;
  }


/*****************************************************************************\

\*****************************************************************************/
  function read_out_tbbtitel (){
      if (debug == true){echo "read_out_tbbtitel<br>";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $incident = estab_logbook_active_incident ($conf_4f_db);
    if (is_array ($incident)){
      $this->tbb_einsatz_aktiv = true;
      $this->tbb_art =
        (string) ($incident ["kennung"] ?? "")." · ".
        (string) ($incident ["name"] ?? "");
      $this->tbb_ort = (string) ($incident ["ort"] ?? "") ;
      try {
        $this->tbb_fuehrungsstelle =
          estab_incident_command_post_name ($incident);
        $this->tbb_titel_gesetzt = true;
      } catch (EstabIncidentConfigurationException) {
        $this->tbb_fuehrungsstelle = "";
        $this->tbb_titel_gesetzt = false;
      }
      $this->tbb_titel_tbl = true;
    } else {
      $this->tbb_einsatz_aktiv = false;
      $this->tbb_art = "";
      $this->tbb_ort = "";
      $this->tbb_fuehrungsstelle = "";
      $this->tbb_titel_gesetzt = false;
      $this->tbb_titel_tbl = true;
    }
  }

  var $spaltenanzahl ;
  var $spaltenkoepfe ; // Array mit den Bezeichnungen der Spalten

/*****************************************************************************\

\*****************************************************************************/
  function tbb_pre_html (){
    echo "<!doctype html>\n";
    echo "<html lang=\"de\">\n";
    echo "<head>\n";
    echo "  <meta charset=\"UTF-8\">\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    if (!$this->tbb_authorized) {
      echo "<meta http-equiv=\"refresh\" content=\"10\">\n";
    }
    echo "  <title>eStab Technisches Betriebsbuch</title>\n";
    echo estab_session_ui_stylesheet ()."\n";
    echo "</head>\n";
    echo "<body class=\"estab-tool-page\">\n";
    echo "<main class=\"estab-tool-main estab-tool-main-wide\" ";
    echo "data-estab-logbook=\"ttb\">\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_post_html () {
    echo "<footer class=\"estab-tool-footer\">\n";
    echo "<a href=\"".estab_auth_html (estab_application_root ()).
         "\">Zur eStab-Übersicht</a>\n";
    echo "<span>Die Ansicht aktualisiert sich bei lesendem Zugriff automatisch.</span>\n";
    echo "</footer>\n";
    echo "</main>\n";
    echo "</body>\n";
    echo "</html>\n";
  }


/*****************************************************************************\

\*****************************************************************************/
  function konv_datetime_taktime ($datetime){
    include ("../4fcfg/config.inc.php");
    // Datenbankzeit konvertiert in taktische Zeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    list ($datum, $zeit) = explode (" ",$datetime);
    list ($yyyy, $MM, $tt) = explode ("-", $datum);
    list ($hh, $mm, $ss) = explode (":", $zeit);
    return ($tt.$hh.$mm.$tak_monate[$MM].$yyyy);
  }


/*****************************************************************************\

\*****************************************************************************/
  function convtodatetime ($datum, $zeit){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $jahr   = date ("Y");
    $datetime = $jahr."-".$monat."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }



/*****************************************************************************\

\*****************************************************************************/
  function tbb_menue (){
    $action = estab_auth_html (estab_application_url ("fmtbb/tbb.php"));
    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"ttb-action-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"ttb-action-title\">Neuer Betriebsbucheintrag</h2>\n";
    echo "<p>Erfassen Sie ein technisches Ereignis im aktiven Einsatz.</p>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-actions\" action=\"".$action."\" method=\"get\">\n";
    echo "<button class=\"estab-button estab-button-primary\" ";
    echo "type=\"submit\" name=\"tbb_menue\" value=\"eintrag\">";
    echo "Neuen TBB-Eintrag anlegen</button>\n";
    echo "</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_getdate ( ){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $result = estab_logbook_entries (
      $conf_4f_db,
      $conf_tbl ["tbb"],
      "tbb"
    );
  if (debug == true){echo "tbb_getdate-->"; var_dump($result);echo "<br>";}
    return $result === array () ? "" : $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_tbb_eintrag ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_entry (is_array ($daten) ? $daten : array ());
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültiger TBB-Eintrag");
    }
    estab_logbook_insert_entry (
      $conf_4f_db,
      $conf_tbl ["tbb"],
      "tbb",
      $validation ["data"],
      array (
        "funktion" => (string) $this->tbb_funktion,
        "kuerzel" => (string) $this->tbb_kuerzel,
        "benutzer" => (string) $this->tbb_benutzer,
        "rolle" => (string) $this->tbb_rolle,
        "duty_assignment_id" => $this->tbb_duty_assignment_id,
      )
    );
  }

/*****************************************************************************\

\*****************************************************************************/
var $lfd ;
var $task ;

  function tbb_eintragsmenue ($data) {
    unset ($data);
    $action = estab_auth_html (estab_application_url ("fmtbb/tbb.php"));

    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"ttb-entry-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"ttb-entry-title\">TBB-Eintrag erfassen</h2>\n";
    echo "<p>Die Ereignisdarstellung ist verpflichtend; eine Bemerkung ist optional.</p>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-form\" method=\"post\" action=\"".$action.
         "\" name=\"tbbeintrag\" data-estab-dirty-guard ".
         "data-estab-requires-incident>\n";
    echo estab_csrf_field ()."\n";
    echo "<input type=\"hidden\" name=\"logbook_action\" value=\"save_entry\">\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"ttb-event\">Darstellung der Ereignisse</label>\n";
    echo "<textarea id=\"ttb-event\" maxlength=\"10000\" required ";
    echo "name=\"event\" autofocus></textarea>\n";
    echo "<small>Höchstens 10.000 Zeichen.</small>\n</div>\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"ttb-comment\">Bemerkung</label>\n";
    echo "<textarea id=\"ttb-comment\" maxlength=\"10000\" ";
    echo "name=\"comment\"></textarea>\n";
    echo "<small>Optional, höchstens 10.000 Zeichen.</small>\n</div>\n";
    echo "<div class=\"estab-tool-actions\">\n";
    echo "<button class=\"estab-button estab-button-primary\" type=\"submit\">";
    echo "TBB-Eintrag speichern</button>\n";
    echo "<a class=\"estab-button\" href=\"".$action."\">Abbrechen</a>\n";
    echo "</div>\n</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_einsatzdaten (){
    echo "<section class=\"estab-tool-status estab-tool-status-active\" ";
    echo "aria-label=\"Aktiver Einsatz\">\n<div>\n";
    echo "<span>Aktiver Einsatz</span>\n";
    echo "<strong>".estab_auth_html ($this->tbb_art)."</strong>\n";
    echo "<span>Führungsstelle: ".estab_auth_html (
      $this->tbb_fuehrungsstelle !== ""
        ? $this->tbb_fuehrungsstelle
        : "nicht festgelegt"
    )."</span>\n";
    echo "<span>Ort: ".estab_auth_html (
      $this->tbb_ort !== "" ? $this->tbb_ort : "nicht angegeben"
    )."</span>\n";
    echo "</div>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function headline (){
    echo "<tr>\n";
    for ($i=0; $i<$this->spaltenanzahl; $i++){
      echo "<th scope=\"col\">".estab_auth_html ($this->spaltenkoepfe [$i]).
           "</th>\n";
    }
    echo "</tr>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function inputeinsatzstammdaten (){
    echo "<aside class=\"estab-tool-notice estab-tool-notice-warning\">\n";
    echo "<strong>Einsatzdaten werden global verwaltet.</strong>\n";
    echo "<p>Legen Sie Einsätze ausschließlich in der Administration an und ";
    echo "aktivieren Sie dort den gewünschten Einsatz.</p>\n</aside>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function printlist ($daten){
    // Schreibe die Liste
    if ( $daten != "" ) {
      echo "<section class=\"estab-tool-panel\" aria-labelledby=\"ttb-list-title\">\n";
      echo "<header class=\"estab-tool-panel-heading\">\n";
      echo "<h2 id=\"ttb-list-title\">Einträge des aktiven Einsatzes</h2>\n";
      echo "<p>Jeder Eintrag weist zusätzlich das verantwortliche Kürzel aus.</p>\n";
      echo "</header>\n";
      echo "<div class=\"estab-tool-table-wrap estab-tool-table-responsive\">\n";
      echo "<table class=\"estab-tool-table estab-tool-logbook-table\">\n";
      echo "<caption class=\"estab-visually-hidden\">";
      echo "Einträge im Technischen Betriebsbuch</caption>\n<thead>\n";

      $this->headline ();
      echo "</thead>\n<tbody>\n";

      foreach ( $daten as $line ){
        echo "<tr>";
        echo "<td class=\"estab-tool-table-number\" data-label=\"Lfd.-Nr.\">\n";
        echo (int) $line ["tbb_lfd-nr"];
        echo "</td>\n";
        echo "<td data-label=\"Datum/Zeit\"><time>";
        echo estab_auth_html ($this->konv_datetime_taktime ($line ["tbb_time"]));
        echo "</time>";
        echo "</td>\n";
        echo "<td data-label=\"Darstellung der Ereignisse\">";
        echo $line ["tbb_aktion"] != ""
          ? nl2br (estab_auth_html ($line ["tbb_aktion"]), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        echo "</td>\n";
        echo "<td data-label=\"Bemerkung\">";
        echo $line ["tbb_bemerk"] != ""
          ? nl2br (estab_auth_html ($line ["tbb_bemerk"]), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        echo "</td>\n";
        echo "<td data-label=\"Kürzel\">";
        echo $line ["tbb_kuerzel"] != ""
          ? estab_auth_html ($line ["tbb_kuerzel"])
          : "<span aria-label=\"keine Angabe\">—</span>";
        echo "</td>\n";
        echo "</tr>\n";
      }

      echo "</tbody>\n";
      echo "</table>\n";
      echo "</div>\n</section>\n";
    } else {
      echo "<section class=\"estab-tool-panel\" aria-label=\"Keine TBB-Einträge\">\n";
      echo "<p class=\"estab-tool-empty\">Noch keine TBB-Einträge vorhanden.</p>\n";
      echo "</section>\n";
    }
  }

} // class tbb_liste
/**************************************************************************************************************************/

if (session_status () !== PHP_SESSION_ACTIVE) {
  session_start ();
}
require_once __DIR__ . "/../app/logbook.php";
require_once __DIR__ . "/../app/navigation.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/session_ui.php";
estab_navigation_require_session ($_SESSION, "technical-log", $_SERVER);

include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");

$identity = estab_read_session_identity ($_SESSION);
if (
  !is_array ($identity)
  || estab_read_duty_assignment_id (
    $identity ["duty_assignment_id"] ?? null
  ) === null
) {
  estab_navigation_select_duty ($_SERVER);
}
estab_session_ui_start ($_SESSION);

$berechtigt = false;
$dutyAssignmentId = null;
try {
  $readConnection = estab_auth_connect ($conf_4f_db);
  $readScope = estab_read_require_operational_scope (
    $readConnection,
    $identity
  );
  $identity = $readScope ["identity"];
  $dutyAssignmentId = estab_read_duty_assignment_id (
    $identity ["duty_assignment_id"] ?? null
  );
  if ($dutyAssignmentId === null) {
    throw new EstabReadPermissionException (
      "Wählen Sie zuerst eine persönlich angenommene Dienstfunktion."
    );
  }
  $berechtigt = estab_dv_has_selected_capability (
    $readConnection,
    (int) $readScope ["incident"]["active_einsatz_id"],
    $identity,
    "BEFOERDERUNG"
  );
} catch (EstabNoActiveIncidentException $exception) {
  estab_logbook_abort (
    409,
    "Kein Einsatz ist aktiv. Das technische Betriebsbuch enthält derzeit ".
    "keine freigegebenen Einsatzdaten."
  );
} catch (EstabReadPermissionException $exception) {
  estab_logbook_abort (403, $exception->getMessage ());
} catch (Throwable $exception) {
  error_log ("TBB read authorization failed: ".$exception->getMessage ());
  estab_logbook_abort (
    503,
    "Die Leseberechtigung für das technische Betriebsbuch kann derzeit nicht ".
    "geprüft werden."
  );
} finally {
  if (isset ($readConnection) && $readConnection instanceof mysqli) {
    estab_auth_close ($readConnection);
  }
}

$requestMethod = isset ($_SERVER ["REQUEST_METHOD"]) && is_string ($_SERVER ["REQUEST_METHOD"])
  ? strtoupper ($_SERVER ["REQUEST_METHOD"])
  : "GET";
if (!in_array ($requestMethod, array ("GET", "POST"), true)) {
  header ("Allow: GET, POST");
  estab_logbook_abort (405, "Nicht unterstützte Anfragemethode.");
}

try {
  $tbbobj = new tbb_liste;
} catch (Throwable $exception) {
  error_log ("TBB initialization failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das technische Betriebsbuch ist vorübergehend nicht verfügbar.");
}
$tbbobj->tbb_authorized =
  $berechtigt && $tbbobj->tbb_titel_gesetzt;
$tbbobj->tbb_funktion = $identity ["funktion"];
$tbbobj->tbb_kuerzel = $identity ["kuerzel"];
$tbbobj->tbb_benutzer = $identity ["benutzer"];
$tbbobj->tbb_rolle = $identity ["rolle"];
$tbbobj->tbb_duty_assignment_id = $dutyAssignmentId;

if ($requestMethod === "POST") {
  if (!$berechtigt) {
    estab_logbook_abort (
      403,
      "Nur eine aktive A/W-Funktion darf TBB-Einträge schreiben."
    );
  }
  estab_logbook_require_csrf ($_SERVER, $_POST);
  $action = isset ($_POST ["logbook_action"]) && is_string ($_POST ["logbook_action"])
    ? $_POST ["logbook_action"]
    : "";

  try {
    if ($action === "save_entry") {
      if (!$tbbobj->tbb_titel_gesetzt) {
        if ($tbbobj->tbb_einsatz_aktiv) {
          estab_logbook_abort (
            409,
            "Der aktive Einsatz ist unvollständig. TBB-Eingaben sind ".
            "gesperrt; ergänzen Sie zuerst den Namen der Führungsstelle in ".
            "der Administration."
          );
        }
        estab_logbook_abort (
          409,
          "Kein Einsatz ist aktiv. TBB-Eingaben sind gesperrt; aktivieren Sie ".
          "zuerst einen Einsatz in der Administration."
        );
      }
      $validation = estab_logbook_validate_entry ($_POST);
      if (!$validation ["valid"]) {
        estab_logbook_abort (422, "Der TBB-Eintrag ist leer oder überschreitet 10000 Zeichen.");
      }
      $tbbobj->speichen_tbb_eintrag ($validation ["data"]);
    } else {
      estab_logbook_abort (400, "Unbekannte TBB-Aktion.");
    }
  } catch (EstabIncidentConfigurationException $exception) {
    error_log ("TBB write blocked by incident configuration: ".
      $exception->getMessage ());
    estab_logbook_abort (
      409,
      "Der aktive Einsatz ist unvollständig. TBB-Eingaben sind gesperrt; ".
      "ergänzen Sie zuerst den Namen der Führungsstelle in der Administration."
    );
  } catch (EstabNoActiveIncidentException $exception) {
    error_log ("TBB write blocked: ".$exception->getMessage ());
    estab_logbook_abort (
      409,
      "Kein Einsatz ist aktiv. TBB-Eingaben sind gesperrt; aktivieren Sie ".
      "zuerst einen Einsatz in der Administration."
    );
  } catch (Throwable $exception) {
    error_log ("TBB write failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Der TBB-Eintrag konnte nicht gespeichert werden.");
  }

  estab_logbook_redirect (estab_application_url ("fmtbb/tbb.php"));
}

$tbbobj->tbb_pre_html ();
$tbbobj->tbb_ueberschrift ();

if (!$tbbobj->tbb_titel_gesetzt) {
  echo "<section class=\"estab-tool-status estab-tool-status-danger\" ";
  echo "role=\"alert\" ";
  if ($tbbobj->tbb_einsatz_aktiv) {
    echo "data-estab-incident-incomplete><div>";
    echo "<strong>Aktiver Einsatz unvollständig – TBB-Eingaben sind ";
    echo "gesperrt.</strong>";
    echo "<span>Ergänzen Sie in der Administration zuerst den Namen der ";
    echo "Führungsstelle.</span></div></section>\n";
  } else {
    echo "data-estab-no-active-incident><div>";
    echo "<strong>Kein Einsatz aktiv – TBB-Eingaben sind gesperrt.</strong>";
    echo "<span>";
    echo "Legen Sie in der Administration einen Einsatz an oder aktivieren Sie ";
    echo "einen vorhandenen Einsatz.</span></div></section>\n";
  }
} else {
  $tbbobj->tbb_einsatzdaten ();
  $entryFormRequested = isset ($_GET ["tbb_eintrag_x"])
    || (
      isset ($_GET ["tbb_menue"])
      && is_string ($_GET ["tbb_menue"])
      && $_GET ["tbb_menue"] === "eintrag"
    );
  if ($tbbobj->tbb_authorized && $entryFormRequested) {
    $tbbobj->tbb_eintragsmenue ("");
  } elseif ($tbbobj->tbb_authorized) {
    $tbbobj->tbb_menue ();
  }
  $tbbobj->printlist ($tbbobj->tbb_getdate ());
}

$tbbobj->tbb_post_html ();
?>
