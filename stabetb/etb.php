<?php

if (!defined ("debug")) { define ("debug", false); }
if (ob_get_level () === 0) { ob_start (); }
/******************************************************************************\
Einsatz Tage Buch

  Szenario "Kein globaler Einsatz aktiv."

    + Roter Sperrhinweis mit Verweis auf die Einsatzverwaltung
    + Keine fachlichen Eingaben

  Szenario "Globaler Einsatz aktiv."

    + Anzeige des globalen Einsatzkopfs
    + Lesender Zugriff fuer jede angemeldete Funktion
    + Eintragsfunktion fuer die aktuell als Rotkopie markierte Funktion

  Szenario "Schaltflaeche ETB-Eintrag wird betaetigt"

    + Anzeige des globalen Einsatzkopfs
    + Anzeige des Menues zur Eingabe eines ETB Eintrags

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/
class etb_liste {


  var $etb_titel_tbl     = false ;
  var $etb_titel_gesetzt = false;
  var $etb_art ;
  var $etb_ort ;

  var $etb_funktion ;
  var $etb_kuerzel ;
  var $etb_benutzer ;
  var $etb_authorized ;

/*****************************************************************************\

\*****************************************************************************/
  // Klassenkonstruktor
  function __construct (){
    $this->etb_liste ();
  }

  function etb_liste (){
    $this->read_out_etbtitel ();
      if (debug == true){    echo "etb_liste 2 ->"; var_dump ($this->etb_titel_gesetzt); echo "<br>";}
    $conf_etb [0] = "Lfd.-Nr.";
    $conf_etb [1] = "Datum/Zeit";
    $conf_etb [2] = "Darstellung der Ereignisse";
    $conf_etb [3] = "Bemerkung";

    $this->spaltenanzahl = count ($conf_etb);
    $this->spaltenkoepfe = $conf_etb;
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
  function etb_ueberschrift(){
    echo "<header class=\"estab-tool-hero\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Einsatzdokumentation · ETB</p>\n";
    echo "<h1>Einsatztagebuch</h1>\n";
    echo "<p>Chronologische Dokumentation des aktiven Einsatzes. ";
    echo $this->etb_authorized
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
  function speichen_etbtitel ($daten){
    unset ($daten);
    throw new LogicException (
      "Einsatzdaten werden ausschließlich in der Administration verwaltet."
    );
  }


/*****************************************************************************\

\*****************************************************************************/
  function create_etbtitel_tbl(){
    // Legacy compatibility hook. Global incidents are migration-owned.
  }


/*****************************************************************************\

\*****************************************************************************/
  function etb_tableexist () {
    $this->etb_titel_tbl = true;
if (debug == true){ echo "etb_tableexist==>"; var_dump($this->etb_titel_tbl); echo "<br>"; }
    return true;
  }


/*****************************************************************************\

\*****************************************************************************/
  function read_out_etbtitel (){
      if (debug == true){echo "read_out_etbtitel<br>";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $incident = estab_logbook_active_incident ($conf_4f_db);
    if (is_array ($incident)){
      $this->etb_art =
        (string) ($incident ["kennung"] ?? "")." · ".
        (string) ($incident ["name"] ?? "");
      $this->etb_ort = (string) ($incident ["ort"] ?? "") ;
      $this->etb_titel_gesetzt = true;
      $this->etb_titel_tbl = true;
    } else {
      $this->etb_art = "";
      $this->etb_ort = "";
      $this->etb_titel_gesetzt = false;
      $this->etb_titel_tbl = true;
    }
  }

  var $spaltenanzahl ;
  var $spaltenkoepfe ; // Array mit den Bezeichnungen der Spalten

/*****************************************************************************\

\*****************************************************************************/
  function etb_pre_html (){
    echo "<!doctype html>\n";
    echo "<html lang=\"de\">\n";
    echo "<head>\n";
    echo "  <meta charset=\"UTF-8\">\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    if (!$this->etb_authorized) {
      echo "<meta http-equiv=\"refresh\" content=\"10\">\n";
    }
    echo "  <title>eStab Einsatztagebuch</title>\n";
    echo estab_session_ui_stylesheet ()."\n";
    echo "</head>\n";
    echo "<body class=\"estab-tool-page\">\n";
    echo "<main class=\"estab-tool-main estab-tool-main-wide\" ";
    echo "data-estab-logbook=\"etb\">\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_post_html () {
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
  function etb_menue (){
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));
    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-action-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"etb-action-title\">Neuer Tagebucheintrag</h2>\n";
    echo "<p>Erfassen Sie ein Ereignis oder eine Entscheidung im aktiven Einsatz.</p>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-actions\" action=\"".$action."\" method=\"get\">\n";
    echo "<button class=\"estab-button estab-button-primary\" ";
    echo "type=\"submit\" name=\"etb_menue\" value=\"eintrag\">";
    echo "Neuen ETB-Eintrag anlegen</button>\n";
    echo "</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_getdate ( ){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $result = estab_logbook_entries (
      $conf_4f_db,
      $conf_tbl ["etb"],
      "etb"
    );
  if (debug == true){echo "etb_getdate-->"; var_dump($result);echo "<br>";}
    return $result === array () ? "" : $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_etb_eintrag ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_entry (is_array ($daten) ? $daten : array ());
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültiger ETB-Eintrag");
    }
    estab_logbook_insert_entry (
      $conf_4f_db,
      $conf_tbl ["etb"],
      "etb",
      $validation ["data"],
      array (
        "funktion" => (string) $this->etb_funktion,
        "kuerzel" => (string) $this->etb_kuerzel,
        "benutzer" => (string) $this->etb_benutzer,
      )
    );
  }

/*****************************************************************************\

\*****************************************************************************/
var $lfd ;
var $task;

  function etb_eintragsmenue ($data) {
    unset ($data);
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));

    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-entry-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"etb-entry-title\">ETB-Eintrag erfassen</h2>\n";
    echo "<p>Die Ereignisdarstellung ist verpflichtend; eine Bemerkung ist optional.</p>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-form\" method=\"post\" action=\"".$action.
         "\" name=\"etbeintrag\" data-estab-dirty-guard ".
         "data-estab-requires-incident>\n";
    echo estab_csrf_field ()."\n";
    echo "<input type=\"hidden\" name=\"logbook_action\" value=\"save_entry\">\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-event\">Darstellung der Ereignisse</label>\n";
    echo "<textarea id=\"etb-event\" maxlength=\"10000\" required ";
    echo "name=\"event\" autofocus></textarea>\n";
    echo "<small>Höchstens 10.000 Zeichen.</small>\n</div>\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-comment\">Bemerkung</label>\n";
    echo "<textarea id=\"etb-comment\" maxlength=\"10000\" ";
    echo "name=\"comment\"></textarea>\n";
    echo "<small>Optional, höchstens 10.000 Zeichen.</small>\n</div>\n";
    echo "<div class=\"estab-tool-actions\">\n";
    echo "<button class=\"estab-button estab-button-primary\" type=\"submit\">";
    echo "ETB-Eintrag speichern</button>\n";
    echo "<a class=\"estab-button\" href=\"".$action."\">Abbrechen</a>\n";
    echo "</div>\n</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_einsatzdaten (){
    echo "<section class=\"estab-tool-status estab-tool-status-active\" ";
    echo "aria-label=\"Aktiver Einsatz\">\n<div>\n";
    echo "<span>Aktiver Einsatz</span>\n";
    echo "<strong>".estab_auth_html ($this->etb_art)."</strong>\n";
    echo "<span>Ort: ".estab_auth_html (
      $this->etb_ort !== "" ? $this->etb_ort : "nicht angegeben"
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
      echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-list-title\">\n";
      echo "<header class=\"estab-tool-panel-heading\">\n";
      echo "<h2 id=\"etb-list-title\">Einträge des aktiven Einsatzes</h2>\n";
      echo "<p>Die neuesten Einträge werden entsprechend der fachlichen ";
      echo "Datenbankreihenfolge angezeigt.</p>\n</header>\n";
      echo "<div class=\"estab-tool-table-wrap estab-tool-table-responsive\">\n";
      echo "<table class=\"estab-tool-table estab-tool-logbook-table\">\n";
      echo "<caption class=\"estab-visually-hidden\">";
      echo "Einträge im Einsatztagebuch</caption>\n<thead>\n";

      $this->headline ();
      echo "</thead>\n<tbody>\n";

      foreach ( $daten as $line ){
        echo "<tr>";
        echo "<td class=\"estab-tool-table-number\" data-label=\"Lfd.-Nr.\">\n";
        echo (int) $line ["etb_lfd-nr"];
        echo "</td>\n";
        echo "<td data-label=\"Datum/Zeit\"><time>";
        echo estab_auth_html ($this->konv_datetime_taktime ($line ["etb_time"]));
        echo "</time>";
        echo "</td>\n";
        echo "<td data-label=\"Darstellung der Ereignisse\">";
        echo $line ["etb_aktion"] != ""
          ? nl2br (estab_auth_html ($line ["etb_aktion"]), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        echo "</td>\n";
        echo "<td data-label=\"Bemerkung\">";
        echo $line ["etb_bemerk"] != ""
          ? nl2br (estab_auth_html ($line ["etb_bemerk"]), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        echo "</td>\n";
        echo "</tr>\n";
      }

      echo "</tbody>\n";
      echo "</table>\n";
      echo "</div>\n</section>\n";
    } else {
      echo "<section class=\"estab-tool-panel\" aria-label=\"Keine ETB-Einträge\">\n";
      echo "<p class=\"estab-tool-empty\">Noch keine ETB-Einträge vorhanden.</p>\n";
      echo "</section>\n";
    }
  }

} // class etb_liste

/***************************************************************************************************************************/


if (session_status () !== PHP_SESSION_ACTIVE) {
  session_start ();
}
require_once __DIR__ . "/../app/logbook.php";
require_once __DIR__ . "/../app/session_ui.php";
estab_auth_require_session ($_SESSION);

include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");

$identity = estab_auth_session_identity ($_SESSION);
if (!is_array ($identity)) {
  estab_logbook_abort (403, "Anmeldung erforderlich.");
}
estab_session_ui_start ($_SESSION);

try {
  // Older databases used "1"; current matrices store the red-copy flag as "t".
  $redcopyFunction = estab_logbook_redcopy_function (
    $conf_4f_db,
    $conf_4f_tbl ["empfmtx"]
  );
} catch (Throwable $exception) {
  error_log ("ETB authorization lookup failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das Einsatztagebuch ist vorübergehend nicht verfügbar.");
}

$berechtigt = is_string ($redcopyFunction)
  && strcasecmp ($identity ["funktion"], $redcopyFunction) === 0;

$requestMethod = isset ($_SERVER ["REQUEST_METHOD"]) && is_string ($_SERVER ["REQUEST_METHOD"])
  ? strtoupper ($_SERVER ["REQUEST_METHOD"])
  : "GET";
if (!in_array ($requestMethod, array ("GET", "POST"), true)) {
  header ("Allow: GET, POST");
  estab_logbook_abort (405, "Nicht unterstützte Anfragemethode.");
}

try {
  $etbobj = new etb_liste;
} catch (Throwable $exception) {
  error_log ("ETB initialization failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das Einsatztagebuch ist vorübergehend nicht verfügbar.");
}
$etbobj->etb_authorized = $berechtigt;
$etbobj->etb_funktion = $identity ["funktion"];
$etbobj->etb_kuerzel = $identity ["kuerzel"];
$etbobj->etb_benutzer = $identity ["benutzer"];

if ($requestMethod === "POST") {
  if (!$berechtigt) {
    estab_logbook_abort (403, "Nur die Red-Copy-Funktion darf ETB-Einträge schreiben.");
  }
  estab_logbook_require_csrf ($_SERVER, $_POST);
  $action = isset ($_POST ["logbook_action"]) && is_string ($_POST ["logbook_action"])
    ? $_POST ["logbook_action"]
    : "";

  try {
    if ($action === "save_entry") {
      if (!$etbobj->etb_titel_gesetzt) {
        estab_logbook_abort (
          409,
          "Kein Einsatz ist aktiv. ETB-Eingaben sind gesperrt; aktivieren Sie ".
          "zuerst einen Einsatz in der Administration."
        );
      }
      $validation = estab_logbook_validate_entry ($_POST);
      if (!$validation ["valid"]) {
        estab_logbook_abort (422, "Der ETB-Eintrag ist leer oder überschreitet 10000 Zeichen.");
      }
      $etbobj->speichen_etb_eintrag ($validation ["data"]);
    } else {
      estab_logbook_abort (400, "Unbekannte ETB-Aktion.");
    }
  } catch (EstabNoActiveIncidentException $exception) {
    error_log ("ETB write blocked: ".$exception->getMessage ());
    estab_logbook_abort (
      409,
      "Kein Einsatz ist aktiv. ETB-Eingaben sind gesperrt; aktivieren Sie ".
      "zuerst einen Einsatz in der Administration."
    );
  } catch (Throwable $exception) {
    error_log ("ETB write failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Der ETB-Eintrag konnte nicht gespeichert werden.");
  }

  estab_logbook_redirect (estab_application_url ("stabetb/etb.php"));
}

$etbobj->etb_pre_html ();
$etbobj->etb_ueberschrift ();

if (!$etbobj->etb_titel_gesetzt) {
  echo "<section class=\"estab-tool-status estab-tool-status-danger\" ";
  echo "role=\"alert\" data-estab-no-active-incident><div>";
  echo "<strong>Kein Einsatz aktiv – ETB-Eingaben sind gesperrt.</strong>";
  echo "<span>";
  echo "Legen Sie in der Administration einen Einsatz an oder aktivieren Sie ";
  echo "einen vorhandenen Einsatz.</span></div></section>\n";
} else {
  $etbobj->etb_einsatzdaten ();
  $entryFormRequested = isset ($_GET ["etb_eintrag_x"])
    || (
      isset ($_GET ["etb_menue"])
      && is_string ($_GET ["etb_menue"])
      && $_GET ["etb_menue"] === "eintrag"
    );
  if ($berechtigt && $entryFormRequested) {
    $etbobj->etb_eintragsmenue ("");
  } elseif ($berechtigt) {
    $etbobj->etb_menue ();
  }
  $etbobj->printlist ($etbobj->etb_getdate ());
}

$etbobj->etb_post_html ();
?>
