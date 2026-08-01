<?php

define ("debug", false);
session_start ();
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/message_priority.php";
require_once __DIR__ . "/../app/message_list.php";
require_once __DIR__ . "/../app/message_list_ui.php";
require_once __DIR__ . "/../app/navigation.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/session_ui.php";
estab_navigation_require_session (
  $_SESSION,
  "message-overview",
  $_SERVER
);

include ("../4fcfg/config.inc.php");            // Konfigurationseinstellungen und Vorgaben
include ("../4fach/db_operation.php");          // Datenbank operationen
include ("../4fach/tools.php");                 // diverse Funktionen
include ("../4fach/data_hndl.php");             // propritÃ¤re  Datenbankoperationen
include ("../4fcfg/para.inc.php");              //
// The request parser needs the authoritative recipient allowlist before the
// list object is constructed. The detail/form code loads the same legacy
// matrix in its own method scope where required.
include ("../4fcfg/fkt_rolle.inc.php");

$overviewReadIdentity = estab_read_session_identity ($_SESSION);
$overviewAccessConnection = null;
$overviewIncidentId = null;
try {
  $overviewAccessConnection = estab_auth_connect ($conf_4f_db);
  $overviewReadScope = estab_read_require_area (
    $overviewAccessConnection,
    $overviewReadIdentity,
    "message-overview"
  );
  $overviewIncidentId = (int) (
    $overviewReadScope ["incident"]["active_einsatz_id"]
  );
} catch (EstabNoActiveIncidentException) {
  http_response_code (409);
  header ("Content-Type: text/plain; charset=UTF-8");
  header ("Cache-Control: no-store");
  echo "Kein Einsatz aktiv.";
  exit;
} catch (EstabReadPermissionException) {
  http_response_code (403);
  header ("Content-Type: text/plain; charset=UTF-8");
  header ("Cache-Control: no-store");
  echo "Die Meldungsübersicht ist der aktiven Lage/Dokumentation vorbehalten.";
  exit;
} catch (Throwable $exception) {
  error_log (
    "eStab message overview authorization failed: ".$exception->getMessage ()
  );
  http_response_code (503);
  header ("Content-Type: text/plain; charset=UTF-8");
  header ("Cache-Control: no-store");
  echo "Die Leseberechtigung kann derzeit nicht geprüft werden.";
  exit;
} finally {
  if ($overviewAccessConnection instanceof mysqli) {
    estab_auth_close ($overviewAccessConnection);
  }
}
estab_session_ui_start ($_SESSION);

define ("inhalt_limit",true); // VerkÃ¼rzte Darstellung der Meldung ergÃ¤nzt mit  "..."

function estab_overview_url (array $query = array ()) {
  $url = estab_application_url ("4fueltg/ue_ltg.php");
  if ($query !== array ()) {
    $url .= "?".http_build_query ($query, "", "&", PHP_QUERY_RFC3986);
  }
  return $url;
}

function estab_overview_detail_link ($recordId, $label) {
  $recordId = estab_message_positive_id ($recordId);
  $url = estab_overview_url (array (
    "ueb_fm" => "ueb",
    "00_lfd" => $recordId,
  ));
  echo "<a href=\"".estab_message_html ($url)."\" target=\"_self\">".
       estab_message_html ($label)."</a>\n";
}

function estab_overview_row_start ($priority) {
  return $priority
    ? "<tr style=\"background-color: rgb(255,255,0); color: #000000; font-weight:bold;\">\n"
    : "<tr>\n";
}

function estab_overview_recipient_cell ($copyColor, array $backgroundColors) {
  return estab_recipient_copy_cell_html (
    $copyColor,
    $backgroundColors,
    "<p><img src=\"null.gif\" alt=\"leer\"></p>"
  );
}

function estab_overview_empty_row ($columnCount) {
  $columnCount = filter_var (
    $columnCount,
    FILTER_VALIDATE_INT,
    array ("options" => array ("min_range" => 1, "max_range" => 100))
  );
  if (!is_int ($columnCount)) {
    throw new InvalidArgumentException ("Ungültige Spaltenanzahl");
  }
  return "<tr><td colspan=\"".$columnCount."\">Keine Meldungen vorhanden.</td></tr>\n";
}

function estab_overview_forbid () {
  http_response_code (403);
  header ("Content-Type: text/plain; charset=UTF-8");
  header ("Cache-Control: no-store");
  echo "Aktion nicht erlaubt.";
  exit;
}

/*****************************************************************************\
   Datei: ue_ltg.php

   benÃ¶tigte Dateien:  keine

   Beschreibung:

   Erzeugt eine Liste aller Meldungen mit Sichtung.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

class Listen {
/******************************************************************************\
   $welche ~= Art der Liste die Ausggeben werden soll. MÃ¶glich sind:
     FMA    - Fernmeldeausgangsliste
     STUSER - Stabbenutzer
     STSI   - Stab Sichter
     FMNWE  - Fernmelde Nachweis Eingang
     FMNWA  - Fernmelde Nachweis Ausgang
     ADMIN  - Administrative Liste
\******************************************************************************/


  var $listenart;
  var $benutzer;
  var $incidentId;
  var $filters;
  var $pageWindow;

  // Listengestaltung

  function __construct ($welche, $user, $incidentId = null, $filters = null){
    $this->listen ($welche, $user, $incidentId, $filters);
  }

/******************************************************************************\

\******************************************************************************/


  function explodereceiver ( $empf){
    return estab_recipient_copy_map ($empf);
  }


/******************************************************************************\

  SESSION=Array (

     [flt_gelesen] => 1    zeige gelesene
     [flt_erledigt] => 1   zeige erledigte
     [ueb_flt_start] => 1
     [flt_position] => 1
     [ueb_flt_darstellung] => 1
     [ueb_flt_anzahl] => 5 ) #

\******************************************************************************/
  function get_list (){
    include ("../4fcfg/config.inc.php");
    require __DIR__ . "/../4fcfg/dbcfg.inc.php";
    $messageTable = estab_message_table ($conf_4f_tbl ["nachrichten"]);
    $incidentId = $this->required_incident_id ();
    $filter = estab_message_list_filter_sql ($this->filters, "m");
    $where = "m.`einsatz_id` = ?".
      ($filter ["sql"] === "" ? "" : " AND ".$filter ["sql"]);
    $queryParameters = array_merge (array ($incidentId), $filter ["params"]);
    $messageConnection = estab_message_connect ($conf_4f_db);
    try {
      $count = estab_message_query_int (
        $messageConnection,
        "SELECT COUNT(*) FROM ".$messageTable." AS m WHERE ".$where,
        $queryParameters
      );
      $this->pageWindow = estab_message_list_page_window (
        $count,
        $this->filters
      );
      $this->filters ["page"] = $this->pageWindow ["page"];
      $query = "SELECT m.`00_lfd`,m.`04_richtung`,".
        estab_message_list_tbb_number_select_sql ("m").",".
        "m.`05_gegenstelle`,m.`09_vorrangstufe`,m.`10_anschrift`,".
        "m.`11_rufnummer`,m.`12_anhang`,m.`12_betreff`,m.`12_inhalt`,".
        "m.`12_abfzeit`,m.`13_abseinheit`,m.`14_funktion`,".
        "m.`16_empf`,m.`x00_status` FROM ".$messageTable." AS m".
        " WHERE ".$where." ORDER BY ".
        estab_message_list_order_sql ($this->filters, "m").
        " LIMIT ? OFFSET ?";
      return estab_message_query_rows (
        $messageConnection,
        $query,
        array_merge ($queryParameters, array (
          $this->pageWindow ["page_size"],
          $this->pageWindow ["offset"],
        ))
      );
    } finally {
      estab_auth_close ($messageConnection);
    }
  }

  /** Historical implementation retained only as migration reference. */
  function legacy_get_list (){
    echo "\n\n\n<!-- ANFANG file:liste.php fkt:createlist -->";
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

	if ((isset($_SESSION["vStab_funktion"])) and (isset($_SESSION["vStab_kuerzel"])))
       $tblusername   = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"]).
                        "_".strtolower ($_SESSION["vStab_kuerzel"]);

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
    $messageAlias = estab_message_list_alias (
      (string) $conf_4f_tbl ["nachrichten"]
    );
    $query_select_arg = $conf_4f_tbl ["nachrichten"].".`00_lfd`, ".
                        $conf_4f_tbl ["nachrichten"].".`09_vorrangstufe`, ".
                        $conf_4f_tbl ["nachrichten"].".`04_richtung`, ".
                        estab_message_list_tbb_number_select_sql (
                          $messageAlias
                        ).", ".
                        $conf_4f_tbl ["nachrichten"].".`10_anschrift`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_abfzeit`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_inhalt`, ".
                        $conf_4f_tbl ["nachrichten"].".`13_abseinheit`, ".
                        $conf_4f_tbl ["nachrichten"].".`14_funktion`, ".
                        $conf_4f_tbl ["nachrichten"].".`16_empf`, ".
                        $conf_4f_tbl ["nachrichten"].".`X00_status`, ".
                        $conf_4f_tbl ["nachrichten"].".`x01_abschluss` ";

    $query_from_arg   = $conf_4f_tbl ["nachrichten"]; //.", ".$tblusername."_read , ".$tblusername."_erl ";

    $incidentId = $this->required_incident_id ();
    $query_where_arg1 =
      $conf_4f_tbl ["nachrichten"].".`einsatz_id` = ?";

//    if ($_SESSION [flt_gelesen]  != 1){$readwhat = " NOT ";} else {$readwhat = " ";}

    if ((isset($_SESSION["vStab_kuerzel"])) and 
	    (isset($_SESSION["flt_erledigt"])) and 
		($_SESSION ['flt_erledigt'] != 1))
	 {$donewhat = " NOT ";} else {$donewhat = " ";}

/*
    if ($_SESSION["ueb_flt_darstellung"] == "1" ){
      $query_where_arg3 = "";
    } else {
      $query_where_arg2 = "";
    }
*/
    $query_orderby_arg =
      estab_message_priority_order_sql ("`09_vorrangstufe`").
      " DESC, COALESCE(".
      estab_message_list_tbb_number_sql ($messageAlias).
      ", 0) DESC ";
    $queryParameters = array ($incidentId);

    if (isset ($_SESSION["ueb_flt_search"])) {
      $searchPattern = "%".(string) $_SESSION["ueb_flt_search"]."%";
      $query_search = "(".
          "(CAST(".estab_message_list_tbb_number_sql ($messageAlias).
          " AS CHAR) LIKE ?) OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`10_anschrift` LIKE ?) OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_abfzeit` LIKE ?) OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_inhalt` LIKE ?) OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`13_abseinheit` LIKE ?) )";
      $queryParameters = array_merge (
        $queryParameters,
        array_fill (0, 5, $searchPattern)
      );


      $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." AND ".$query_search.";" ;

      $query = "SELECT ".$query_select_arg." FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." AND ".$query_search." ORDER BY ".$query_orderby_arg ;

//      unset ($_SESSION["flt_search"]);

    } else {
      $query_search = "";
	  $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
               $query_where_arg1; //." ".$query_where_arg2." ".$query_where_arg3.";" ;

      $query = "SELECT ".$query_select_arg." FROM ".$query_from_arg." WHERE ".
            $query_where_arg1." ORDER BY ".$query_orderby_arg ;  //." ".$query_where_arg2." ".$query_where_arg3." ORDER BY ".$query_orderby_arg ;
    }

    if ( debug == true ){  echo "<br><br>QUERYCOUNT [get_list] =".$querycount."<br>";echo "<br><br>";}

    if ( $_SESSION["ueb_flt_darstellung"] == "1" ){
      $messageConnection = estab_message_connect ($conf_4f_db);
      try {
        $anzahl = estab_message_query_int ($messageConnection, $querycount, $queryParameters);
      } finally {
        estab_auth_close ($messageConnection);
      }
      $_SESSION["ueb_flt_rescount"] = $anzahl ;

        // Anzahl, Meldungen pro Seite ==> Meldungen der letzten Seite
      if ( debug == true ){ echo "<br>ANZAHL ===".$anzahl."<br>";}

        // Listennavigation 

      $anz_meld_seite = filter_var (
        $_SESSION["ueb_flt_anzahl"] ?? 15,
        FILTER_VALIDATE_INT,
        array ("options" => array ("min_range" => 1, "max_range" => 100))
      );
      if (!is_int ($anz_meld_seite)) { $anz_meld_seite = 15; }
      $pageStart = filter_var (
        $_SESSION["ueb_flt_start"] ?? 0,
        FILTER_VALIDATE_INT,
        array ("options" => array ("min_range" => 0, "max_range" => PHP_INT_MAX))
      );
      if (!is_int ($pageStart)) { $pageStart = 0; }
      $_SESSION["ueb_flt_anzahl"] = $anz_meld_seite;
      $_SESSION["ueb_flt_start"] = $pageStart;
      $pageoffirst = ceil ($_SESSION['ueb_flt_start'] / $anz_meld_seite) +1 ;
      $pagecount   = ceil ($anzahl / $anz_meld_seite) ;
      $is_last_page = ($pageoffirst == $pagecount);
/*
echo "Meldung pro Seite        =".$anz_meld_seite."<br>";
echo "Seite der ersten Meldung =".$pageoffirst."<br>";
echo "Startmeldung             =".$_SESSION['ueb_flt_start']."<br>" ;
echo "Gesamtanzahl der Seiten  =".$pagecount."<br>";
echo "Ist es die letzte Seite  ="; if ($is_last_page){echo "Ja";} else {echo "Nein";} echo "<br>";
*/
      if (isset($_SESSION['ueb_flt_navi'])) {
        switch ($_SESSION['ueb_flt_navi']) {
           // ANFANG
          case "start":
                  $_SESSION["ueb_flt_start"] = 0;
          break;
           // Eine Seite zurÃ¼ck
          case "back":
                  $_SESSION["ueb_flt_start"] -= $_SESSION['ueb_flt_anzahl'];
                  if ($_SESSION["ueb_flt_start"] < 0){
                      $_SESSION["ueb_flt_start"]=0;}
          break;
           // Eine Seite vor
          case "for":
//                  if (!$is_last_page){ // Es ist nicht die letzte Seite
                    if ($anzahl < $_SESSION['ueb_flt_anzahl']){ // Es ist nur eine Seite
                      $_SESSION['ueb_flt_start'] = 0; 
                    } else {
                       // Schon auf der letzten Seite?
                      if ($is_last_page){
                      } else {
 
                        $_SESSION["ueb_flt_start"] += $_SESSION['ueb_flt_anzahl']; // eine Seite weiter
                        if ($_SESSION["ueb_flt_start"] >= $anzahl){
                          $_SESSION["ueb_flt_start"] = $anzahl-1;}
  //                    }
                    }
                  }
//exit;
          break;
          // Letzte Seite
          case "end":
                  $_SESSION["ueb_flt_start"] = $anzahl > 0
                    ? intdiv ($anzahl - 1, $anz_meld_seite) * $anz_meld_seite
                    : 0;
          break;
        }
        unset ($_SESSION ['ueb_flt_navi']);
      }
      if ($anzahl === 0) {
        $_SESSION["ueb_flt_start"] = 0;
      } elseif ($_SESSION["ueb_flt_start"] >= $anzahl) {
        $_SESSION["ueb_flt_start"] =
          intdiv ($anzahl - 1, $anz_meld_seite) * $anz_meld_seite;
      }
      $query .= " LIMIT ".$_SESSION["ueb_flt_start"].",".$anz_meld_seite;
    }
/*	
      $anz_rest_meld_seite = $_SESSION["ueb_flt_anzahl"];
      $pageoffirst = ceil ($_SESSION['ueb_flt_start'] / $anz_meld_seite) + 1 ;
      $pagecount   = ceil ($anzahl / $anz_meld_seite) ;
      $is_last_page = ($pageoffirst == $pagecount);
echo "***************************************************************************<br>";
echo "Rest Meldung pro Seite   =".$anz_rest_meld_seite."<br>";
echo "Seite der ersten Meldung =".$pageoffirst."<br>";
echo "Startmeldung             =".$_SESSION['ueb_flt_start']."<br>" ;
echo "Gesamtanzahl der Seiten  =".$pagecount."<br>";
echo "Ist es die letzte Seite  ="; if ($is_last_page){echo "Ja";} else {echo "Nein";} echo "<br>";
*/


	
//    $query = $query_select.$query;

    if ( debug == true ){  echo "QUERY [get_list]227=".$query."<br>";echo "<br><br>";}
  
    $messageConnection = estab_message_connect ($conf_4f_db);
    try {
      $result = estab_message_query_rows ($messageConnection, $query, $queryParameters);
    } finally {
      estab_auth_close ($messageConnection);
    }

//    if ( debug == true ){ echo "RESULT [get_list] ="; var_dump ($result); echo "<br><br>"; }

    return ($result === array () ? "" : $result);

  }


/******************************************************************************\

\******************************************************************************/
  function listen ($welche, $user, $incidentId = null, $filters = null){
    $this->listenart = $welche;
    $this->benutzer  = $user;
    $this->incidentId = $incidentId === null
      ? null
      : estab_message_positive_id ($incidentId);
    $this->filters = is_array ($filters)
      ? $filters
      : estab_message_list_default_filters ();
    $this->pageWindow = estab_message_list_page_window (0, $this->filters);
//    echo "listenart =".$this->listenart."- benutzer = ".$this->benutzer."<br>";
  }

  function required_incident_id (){
    if ($this->incidentId === null) {
      throw new RuntimeException (
        "Für die Übersicht wurde kein autorisierter Einsatz übergeben"
      );
    }
    return estab_message_positive_id ($this->incidentId);
  }


/******************************************************************************\
  Funktion:  listen_navi ()
SELECT * FROM `nv_nachrichten` WHERE `00_lfd` IN

(SELECT msg FROM `nv_masterkategolink` WHERE `katego` = (

SELECT lfd FROM `nv_masterkatego` WHERE `kategorie` = "2m"));

\******************************************************************************/

  function  listen_navi (){
    include ("../4fcfg/config.inc.php");
    echo "<form action=\"".estab_message_html (estab_overview_url ()).
         "\" method=\"get\" target=\"_self\">\n";
    echo "<input type=\"image\" name=\"ueb_flt_start\" src=\"".$conf_design_path."/go_start.gif\" alt=\"Anfang\">\n";
    echo "<input type=\"image\" name=\"ueb_flt_back\"  src=\"".$conf_design_path."/go_back.gif\" alt=\"zurueck\">\n";
    echo "<input type=\"image\" name=\"ueb_flt_for\"   src=\"".$conf_design_path."/go_forward.gif\" alt=\"vor\">\n";
    echo "<input type=\"image\" name=\"ueb_flt_end\"   src=\"".$conf_design_path."/go_end.gif\" alt=\"Ende\">\n";
    echo "</form>\n";
  }


/******************************************************************************\

\******************************************************************************/
  function darstellungs_art ( ){

    include ("../4fcfg/config.inc.php");

    if ( debug ) { echo "\n\n\n<!-- ANFANG file:liste.php fkt:darstellungsart -->"; }

    echo "\n<form action=\"".estab_message_html (estab_overview_url ()).
         "\" method=\"get\" target=\"_self\">\n";
    echo "<table><tbody>";
    echo "<tr>";

    echo "<td>";
    echo "<big><b>".($_SESSION["ueb_flt_start"]+1)."|".($_SESSION["ueb_flt_start"]+$_SESSION["ueb_flt_anzahl"])."|<big>".($_SESSION["ueb_flt_rescount"])."</big></b></big>";
    echo "<br><img src=\"".$conf_design_path."/timer.gif\">";
    echo "</td>";
    echo "<td>";
    echo "Meldung/Seite:<br>\n";

      // Voreinstellung fÃ¼r die Meldungen pro Seite
    if ( !(isset ($_SESSION["ueb_flt_anzahl"])) OR
        ( $_SESSION["ueb_flt_anzahl"] == "" )
     ){$_SESSION["ueb_flt_anzahl"] = 5; }

    echo "<table border=\"0\" ><tbody>";
    echo "<tr>";

    echo "<td>";
    echo "<div  style=\"border-top-color:#DCDCFF; border-left-color:#DCDCFF; border-right-color:#DCDCFF; border-bottom-color:#000000; border-width:1px; border-style:solid; padding:0px\">";
      for ($pps=5; $pps <=25; $pps+=5){
        $pageSizeUrl = estab_overview_url (array (
          "ueb_flt_anzahl_x" => 1,
          "ueb_flt_anzahl" => $pps,
        ));
        if ( $_SESSION["ueb_flt_anzahl"] == $pps )  {
          echo "<a href=\"".estab_message_html ($pageSizeUrl)."\"><img src=\"../4fach/button.php?type=icon&status=AUS&text=".$pps."&bg=blue\" border=\"0\" alt=\"Anzahl".$pps."EIN\"></a>";
        } else {
          echo "<a href=\"".estab_message_html ($pageSizeUrl)."\"><img src=\"../4fach/button.php?type=icon&status=EIN&text=".$pps."&bg=lighterblue\" border=\"0\" alt=\"Anzahl".$pps."AUS\"></a>";
        }
      }
    echo "</div>";
    echo "</td>";

    echo "</tr>";
    echo "</tbody></table>";
    echo "</td>";
/*
    echo "<td>";

    if ($_SESSION ["ueb_flt_unerl"] == 0)  {
      echo "<div>";
      echo "<input type=\"image\" name=\"ueb_flt_unerledigt_ein\" src=\"../4fach/button.php?type=push&textpos=buttom&status=AUS&text=un-\" alt=\"unerledigte\">\n";
      echo "</div>";
    } else {
      echo "<div>";
      echo "<input type=\"image\" name=\"ueb_flt_unerledigt_aus\" src=\"../4fach/button.php?type=push&textpos=buttom&status=EIN&text=un-\" alt=\"unerledigte\">\n";
      echo "</div>";
    }
    echo "</td>";

    echo "<td>";
    if ($_SESSION ["ueb_flt_erl"] == 0)  {
      echo "<div>";
      echo "<input type=\"image\" name=\"ueb_flt_erledigt_ein\" src=\"../4fach/button.php?type=push&textpos=buttom&status=AUS&text=erledigt\" alt=\"erledigte\">\n";
      echo "</div>";
    } else {
      echo "<div>";
      echo "<input type=\"image\" name=\"ueb_flt_erledigt_aus\" src=\"../4fach/button.php?type=push&textpos=buttom&status=EIN&text=erledigt\" alt=\"erledigte\">\n";
      echo "</div>";
    }
    echo "</td>";
*/
    echo "<td>";
    if ((isset($_SESSION ["ueb_flt_find_mask"])) and($_SESSION ["ueb_flt_find_mask"] == 0))  {
      echo "<div>";
      echo "<input type=\"image\" name=\"ueb_flt_find_mask_ein\" src=\"../4fach/button.php?type=push&textpos=buttom&status=AUS&text=finden\" alt=\"finden\">\n";
      echo "</div>";
    } else {
      echo "<div>";
      echo "<input type=\"image\" name=\"ueb_flt_find_mask_aus\" src=\"../4fach/button.php?type=push&textpos=buttom&status=EIN&text=finden\" alt=\"finden\">\n";
      echo "</div>";
    }
    echo "</td>";


    echo "<!-- ue_ltg.php 426 -->";

    echo "<td>";

    if ((isset($_SESSION ["ueb_flt_find_mask"])) and ($_SESSION["ueb_flt_find_mask"] == 1)){
      echo "<table><tbody>";
      echo "<tr>";
      echo "<td>";
      if (isset ($_SESSION ["ueb_flt_search"]) ) { $defvalue = $_SESSION ["ueb_flt_search"] ;}
      else {$defvalue = "";}
      echo "<div>";
      echo "<p>Suchbegriff: <input name=\"ueb_flt_search\" value=\"".
           estab_message_html ($defvalue)."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
      echo "</div>";
      echo "</td>";
      echo "<td>";
      echo "<input name=\"ueb_flt_suche\" value=\"suchen\" type=\"submit\">\n";
      echo "</td>";
      echo "</tr>";
      echo "</tbody></table>";
    }


    echo "</td>";

    echo "</tr>";
    echo "</tbody></table>";
    echo "</form>\n";
  }



/******************************************************************************\

\******************************************************************************/
  function createlist (){
//  include ("../4fcfg/config.inc.php");
//  include ("../4fach/tools.php");                 // diverse Funktionen
    echo "\n\n\n<!-- ANFANG file:ue_ltg.php fkt:createlist -->";
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");

    $recipientFunctions = array ();
    foreach ($conf_empf as $recipientDefinition) {
      $recipientFunction = (string) ($recipientDefinition ["fkt"] ?? "");
      if ($recipientFunction !== "" && !in_array (
        $recipientFunction,
        $recipientFunctions,
        true
      )) {
        $recipientFunctions [] = $recipientFunction;
      }
    }
    $result = $this->get_list ();
    $_SESSION ["estab_message_overview_filters"] = $this->filters;

    echo "<!doctype html>\n";
    echo "<html lang=\"de\">\n<head>\n";
    echo "<meta charset=\"UTF-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<title>eStab Meldungsübersicht</title>\n";
    echo estab_session_ui_stylesheet ()."\n";
    echo "</head>\n<body class=\"estab-tool-page\">\n";
    echo "<main class=\"estab-tool-main estab-tool-main-wide\" ";
    echo "data-estab-message-overview data-estab-message-list>\n";
    echo "<header class=\"estab-tool-hero\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Übungsleitung · Nachrichten</p>\n";
    echo "<h1>Meldungsübersicht</h1>\n";
    echo "<p>Durchsuchen und filtern Sie alle Nachrichtenvordrucke des aktiven ";
    echo "Einsatzes. Auch große Lagen bleiben durch serverseitige Seiten und ";
    echo "stabile Sortierung schnell bedienbar.</p>\n</header>\n";
    echo "<section class=\"estab-tool-panel\" ";
    echo "aria-labelledby=\"message-overview-list-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"message-overview-list-title\">Nachrichtenvordrucke</h2>\n";
    echo "<p>Suche und Filter werden miteinander kombiniert. Ein Klick auf ";
    echo "„Vordruck öffnen“ zeigt die vollständige, unveränderte Nachricht.</p>\n";
    echo "</header>\n";
    estab_message_list_render_controls (
      $this->filters,
      $recipientFunctions,
      array (
        "action" => estab_overview_url (),
        "method" => "get",
        "target" => "_self",
        "dom_prefix" => "overview-message-list",
      )
    );
    estab_message_list_render_resultbar (
      $this->filters,
      $this->pageWindow
    );
    if ($result === array ()) {
      estab_message_list_render_empty ($this->filters);
    } else {
      estab_message_list_render_table (
        $result,
        static function (array $row): void {
          $recordId = estab_message_positive_id ($row ["00_lfd"] ?? null);
          $label = "Vordruck ".
            estab_message_list_direction_label ($row ["04_richtung"] ?? "").
            " – ".estab_message_list_tbb_evidence_label ($row)." öffnen";
          echo "<a class=\"estab-button estab-button-primary ".
            "estab-message-list-open\" href=\"".
            estab_message_html (estab_overview_url (array (
              "ueb_fm" => "ueb",
              "00_lfd" => $recordId,
            )))."\">".estab_message_html ($label)."</a>";
        }
      );
    }
    estab_message_list_render_pager (
      $this->filters,
      $this->pageWindow,
      array (
        "action" => estab_overview_url (),
        "method" => "get",
        "target" => "_self",
        "hidden" => array (),
      )
    );
    echo "</section>\n<footer class=\"estab-tool-footer\">\n";
    echo "<a href=\"".estab_auth_html (estab_application_root ())."\">";
    echo "Zur eStab-Übersicht</a>\n";
    echo "<span>Es werden ausschließlich Daten des aktiven Einsatzes angezeigt.</span>\n";
    echo "</footer>\n</main>\n</body>\n</html>\n";

  }


} // class


/*****************************************************************************\
   Klasse: nachrichten4fach

   konstruktor :

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
class nachrichten4fach {

    function __construct ($formulardaten, $task, $errorselect){
      $this->nachrichten4fach ($formulardaten, $task, $errorselect);
    }

    function safe_message_value ($field) {
      return estab_message_html ($this->formdata[$field] ?? "");
    }

    function nachrichten4fach ($formulardaten, $task, $errorselect){
      $this->task = $task ;
      $this->formdata = $formulardaten ;
      $this->lfd = $this->formdata ["00_lfd"];
      foreach (array ("01_datum", "02_zeit", "03_datum", "12_abfzeit", "15_quitdatum") as $dateField) {
        if (estab_datetime_is_unset ($this->formdata [$dateField] ?? null)) {
          $this->formdata [$dateField] = "";
        }
      }
      if ($this->formdata ["11_gesprnotiz"] == "t") {
        $this->formdata   ["11_gesprnotiz"] = true;
      } else {
        $this->formdata ["11_gesprnotiz"] = false;
      }
/*
echo "<br><br> ***" ;
echo " TASK = ".$this->task."<br>";;
var_dump ($this->formdata); echo "<br>";
*/
//      $this->init_vf () ; // setze die Farben des 4fach Vordrucks
      $this->plot_form () ;
    }

    var $task;        // text , Fuer welche Funktion ist der Vordruck
    var $formdata ;   // array, Formulardaten
    var $lfd ;        // integer, laufende Nummer der Nachricht
    var $errorselect; // array, Felder die falsch eingegeben wurden.

  // aktive und Inaktive Darstellungsfarben

  var $fktmsgbgcolor ;  // Hintergrundfarbe
  var $bg_color_fm_a   = "rgb(255, 224, 200)"; // Fernmelder aktiv
  var $bg_color_fmp_a  = "rgb(100, 255, 100)"; // Fernmelderpflichtfeld  aktiv
  var $bg_color_nw_a   = "rgb(255, 204, 51)";
  var $bg_color_tx_a   = "rgb(224, 255, 255)";
  var $bg_color_si_a   = "rgb(255, 224, 255)";
  var $bg_color_inaktv = "rgb(255, 255, 255)";  // "rgb(210, 210, 150)";
  var $bg_color_aktv   = "rgb(255, 255, 255)";
  var $rbl_bg_color    = "rgb(255, 255, 255)";
  var $bg_color_aktv_must = "rgb(240, 20, 20)";

  var $feldbg ;
  var $redcopy2;

  /****************************************************************************\
    Hintergrundfarben der Felder aktiv und inaktiv
  \****************************************************************************/
  function feldbgcolor (){
    if ( ( $this->task == "FM-Eingang") or
         ( $this->task == "FM-Eingang_Sichter" ) ) {
      $this->feldbg [ 1]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [10]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [12]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [13]["a"] = $this->bg_color_fmp_a;
    } else {
       $this->feldbg [ 1]["a"] = $this->bg_color_tx_a;
       $this->feldbg [10]["a"] = $this->bg_color_tx_a;
       $this->feldbg [12]["a"] = $this->bg_color_tx_a;
       $this->feldbg [13]["a"] = $this->bg_color_tx_a;
    }

//    $this->feldbg [ 1]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 1]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 2]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 2]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 3]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 3]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 4]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 4]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 5]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 5]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 6]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 6]["i"] = $this->bg_color_inaktv;

    $this->feldbg [ 7]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 7]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 8]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 8]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 9]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 9]["i"] = $this->bg_color_inaktv;

    $this->feldbg [10]["i"] = $this->bg_color_inaktv;
    $this->feldbg [11]["a"] = $this->bg_color_tx_a;
    $this->feldbg [11]["i"] = $this->bg_color_inaktv;

    $this->feldbg [12]["i"] = $this->bg_color_inaktv;

    $this->feldbg [13]["i"] = $this->bg_color_inaktv;
    $this->feldbg [14]["a"] = $this->bg_color_tx_a;
    $this->feldbg [14]["i"] = $this->bg_color_inaktv;

    $this->feldbg [15]["a"] = $this->bg_color_si_a;
    $this->feldbg [15]["i"] = $this->bg_color_inaktv;
    $this->feldbg [16]["a"] = $this->bg_color_si_a;
    $this->feldbg [16]["i"] = $this->bg_color_inaktv;
    $this->feldbg [17]["a"] = $this->bg_color_si_a;
    $this->feldbg [17]["i"] = $this->bg_color_inaktv;
  }

  // Zuordnung der notwendigen Farben
  var $bg;
  var $feld ;

/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function get_access_by_task (){
    // Alle Felder auf inaktiv setzen
    for ( $i = 1; $i <= 17; $i++ ){
      $this->bg [$i] = $this->feldbg [$i]["i"] ;
      $this->feld [$i] = false;
    }

    switch ($this->task) {
      // Annahme einer Meldung durch Fernmelder
      case "FM-Eingang" :
      case "FM-Eingang_Anhang" :

        $this->bg [1] = $this->feldbg [1]["a"] ;
        $this->feld [1] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        for ($i=7;$i<=14;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;
      case "FM-Eingang_Sichter" :
      case "FM-Eingang_Anhang_Sichter"  :
        $this->bg [1] = $this->feldbg [1]["a"] ;
        $this->feld [1] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        for ($i=7;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
        // Ausser Gespraechsnotiz
        $this->bg [11] = $this->feldbg [11]["i"] ;
        $this->feld [11] = true;

      break;
      // Weitergabe einer Meldung durch den Fernmelder
      case "FM-Ausgang" :
        $this->bg [2] = $this->feldbg [2]["a"] ;
        $this->feld [2] = true;
        $this->bg [3] = $this->feldbg [3]["a"] ;
        $this->feld [3] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        $this->bg [6] = $this->feldbg [6]["a"] ;
        $this->feld [6] = true;
      break;

      // Weitergabe einer Meldung durch den Fernmelder mit Sichterfunktion
      case "FM-Ausgang_Sichter" :
        $this->bg [2] = $this->feldbg [2]["a"] ;
        $this->feld [2] = true;
        $this->bg [3] = $this->feldbg [3]["a"] ;
        $this->feld [3] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        $this->bg [6] = $this->feldbg [6]["a"] ;
        $this->feld [6] = true;
        for ($i=15;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "Stab_schreiben" :
        for ($i=7;$i<=14;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "Stab_lesen" :
   /*   for ($i=7;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["i"] ;
          $this->feld [$i] = false;
        } */
        for ($i=1;$i<=17;$i++){
          $this->bg [$i] = $this->formbgcolor ;
          $this->feld [$i] = false;
        }

      break;

      case "Stab_sichten" :
      case "Stab_gesprnoti":
        for ($i=15;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "FM-Admin" :
        for ($i=1;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "SI-Admin" :
        for ($i=15;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
      }
      break;

      default :
        for ($i=1;$i<=17;$i++){
          $this->feld [$i] = false;
        }
    } // switch $rolle
  }

  var $empfarray ;

/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function ziele (){
  include ("../4fcfg/fkt_rolle.inc.php");

    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        $this->empfarray [$i][$j]["checked"] = false;
        $this->empfarray [$i][$j]["cpycol"]  = "";
        $this->empfarray [$i][$j]["typ"]     = $empf_matrix [$i][$j]["typ"];
        $this->empfarray [$i][$j]["fkt"]     = $empf_matrix [$i][$j]["fkt"];
        $this->empfarray [$i][$j]["rolle"]   = $empf_matrix [$i][$j]["rolle"];
      }
    }
    $empf_text  = $this->formdata ["16_empf"] ; // Zeile mit den Empfaengern aus der DB
      // Wandel die Textzeile mit den Empfaengern in ein ARRAY um
    $empf_array_color = explode (",",$empf_text);

    for ( $i=0; $i < count ( $empf_array_color )-1; $i++ ) {
        //  die Farbe der Kopie
      if (isset($empf_array_color [$i])){
	    list ( $fkt, $cpycol ) = explode ("_", $empf_array_color [$i]);
	    if ( $fkt != "" ){
          $empf_array [$i]['fkt'] = $fkt ;
          $empf_array [$i]['cpy'] = $cpycol ;
          if ((isset ($_SESSION ['vStab_funktion'])) and ($fkt == $_SESSION ['vStab_funktion'])) { 
            $this->fktmsgbgcolor = $cpycol ;
          }
        }
	  }	
    }
    $sonstcount = 2;
    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        if (isset ($empf_array)){
          foreach ($empf_array as $empfaenger){
            if ( ( strtoupper ( $empfaenger['fkt'] ) ==  strtoupper ( $empf_matrix [$i][$j]["fkt"]) ) and
                 ( $empf_matrix [$i][$j]["fkt"] != "" ) ){
              $this->empfarray [$i][$j]["checked"] = true;
              $this->empfarray [$i][$j]["cpycol"] = $empfaenger['cpy'];
            }
          }
        }
      }
    }
  $this->redcopy2 = $redcopy2;
  }


/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
    // Listet unter Inhalt eventuelle Anhangsdateien als href auf
  function list_anhang (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
      // in 12_anhang stehen die Anhangdateien mit ";" getrennt.
    echo "<br>";
    $anhaenge = preg_split("/;/", $this->formdata ["12_anhang"]);
    foreach ($anhaenge as $anhang){
      if ($anhang != "") {
        try {
          $anhang = estab_file_validate_name ("attachment", $anhang);
          $downloadUrl = estab_file_download_url ($conf_4f ["download_uri"], "attachment", $anhang);
        } catch (InvalidArgumentException) {
          continue;
        }
        if (strtolower (pathinfo ($anhang, PATHINFO_EXTENSION)) === "eml") {
          $emailUrl = dirname ((string) $conf_4f ["download_uri"]).
                      "/email.php?".
                      http_build_query (
                        array ("file" => $anhang),
                        "",
                        "&",
                        PHP_QUERY_RFC3986
                      );
          echo "<span data-estab-email-attachment>".
               "<a style=\"font-size:18px; font-weight:900;\" href=\"".
               estab_auth_html ($emailUrl)."\" target=\"_blank\" rel=\"noopener\">".
               estab_auth_html ($anhang)." · E-Mail ansehen</a> ".
               "<a href=\"".estab_auth_html ($downloadUrl)."\" download=\"".
               estab_auth_html ($anhang)."\">Originaldatei herunterladen</a>".
               "</span><br>";
        } else {
          echo "<a style=\"font-size:18px; font-weight:900;\" href=\"".
               estab_auth_html ($downloadUrl)."\" target=\"_blank\" rel=\"noopener\">".
               estab_auth_html ($anhang)."</a><br>";
        }
      }
    }
  } // list_anhang ()


  var $formbgcolor ; // Hintergrundfarbe

/*****************************************************************************\
   Funktion     :  plot_form

   Beschreibung :  Ausgabe des Formulars

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function plot_form (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $this->ziele (); // Ziele und Farben   $fktmsgbgcolor

    switch ($this->fktmsgbgcolor) {
      case "rt": $this->formbgcolor =  $cfg ["vbg"]  ["rt"] ; break;
      case "gn": $this->formbgcolor =  $cfg ["vbg"]  ["gn"] ; break;
      case "bl": $this->formbgcolor =  $cfg ["vbg"]  ["bl"] ; break;
      case "ge": $this->formbgcolor =  $cfg ["vbg"]  ["ge"] ; break;
      default  : $this->formbgcolor =  $cfg ["vbg"]  ["default"] ;
    }
    $this->feldbgcolor ();
    $this->get_access_by_task ($this->task);

    pre_html ("N","Formular ".$this->task." ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"], ""); // Normaler Seitenaufbau ohne Auffrischung

    switch ($this->task){
      case "FM-Eingang"         : $ueberschrift = "* * *   A N N A H M E  * * *"; break;
      case "FM-Eingang_Sichter" : $ueberschrift = "* * *   A N N A H M E / Sichtung  * * *"; break;
      case "FM-Eingang_Anhang"  : $ueberschrift = "* * *   A N N A H M E  * * *"; break;
      case "FM-Ausgang"         : $ueberschrift = "* * *   W E I T E R G A B E   * * *"; break;
      case "FM-Admin"           : $ueberschrift = "* * *   A D M I N I S T R A T I O N   * * *"; break;
      case "Stab_schreiben"     : $ueberschrift = "* * *   T E X T verfassen.   * * *"; break;
      case "Stab_lesen"         : $ueberschrift = "* * *   N A C H R I C H T lesen   * * *"; break;
      case "Sichter"            : $ueberschrift = "* * *   S I C H T U N G   * * *"; break;
      case "Nachweis"           : $ueberschrift = "* * *   N A C H W E I S U N G   * * *"; break;
      default                   : $ueberschrift = "Nachrichtenvordruck"; break;
    }
    echo "<body class=\"estab-tool-page\">\n";
    echo "<main class=\"estab-tool-main estab-tool-main-wide\" ";
    echo "data-estab-message-detail>\n";
    echo "<header class=\"estab-tool-hero\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Übungsleitung · Nachrichtendetail</p>\n";
    echo "<h1>".estab_message_html ($ueberschrift)."</h1>\n";
    echo "<p>Vollständiger Nachrichtenvordruck des aktiven Einsatzes. ";
    echo "Farbkennzeichnungen innerhalb des Vordrucks bleiben fachlich erhalten.</p>\n";
    echo "</header>\n";
    echo "<section class=\"estab-tool-panel\" ";
    echo "aria-labelledby=\"message-detail-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"message-detail-title\">Nachrichtenvordruck</h2>\n";
    echo "<div class=\"estab-tool-actions\">\n";
    echo "<button class=\"estab-button\" type=\"button\" ";
    echo "onclick=\"window.print()\">Diese Seite drucken</button>\n";
    echo "</div>\n</header>\n";
    echo "<div class=\"estab-tool-legacy-content\">\n";
    echo "<form method=\"get\" action=\"".
         estab_message_html (estab_overview_url ()).
         "\" name=\"4fach\" data-estab-requires-incident>";
    echo "\n\n<!-- ********** TABLE   001 Gesamte Tabelle *********** -->\n";

    echo "<!-- H A U P T T A B E L L E  -->";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 800px;\" border=\"1\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";

    echo "<tr><!-- 1. Zeile der Tabelle -->\n";
    echo "<td style=\"height: 113px; width: 800px;\">\n";

    echo "\n\n<!-- ********** TABLE   Eingang | Ausgang | TBB-Nachweis  *********** -->\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; height: 32px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /***************************************************************************************
                              F M  -  B E T R I E B S S T E L L E
    */
    // Zeile, Spalte 1,1    EINGANG    1  1   Eingang
    echo "<td style=\"width: 230px; background-color: ".$this->bg[1].";\"><!--002-->\n";
    echo "<div style=\"text-align: center; width: 200px;\">EINGANG</div>\n";
    echo "</td><!--002-->\n";
    // Zeile, Spalte 1,2    AUSGANG    2  2   Ausgang Annahmevermerk Befoerderungsvermerk
    echo "<td style=\"text-align: center; background-color: ".$this->bg[2]."; width: 427px;\"><!--003-->\nAUSGANG</td><!--003-->\n";
    // Zeile, Spalte 1,3    incident-local TBB evidence number and direction
    echo "<td style=\"text-align: center; width: 150px; background-color: ".$this->bg[4].";\"><!--004-->\nTBB-Nachweis</td><!--004-->\n";
    echo "</tr><!--002-->\n";

    echo "<tr><!--003-->\n";
    /****************************************************************************\
    |  Zeile, Spalte 2 , 1   Aufnahmevermerk  1   1   Eingang                    |
    \****************************************************************************/
     if (!$this->feld [1]){
      $param = " disabled ";
    // Radio Button die deaktiviert sind liefern keinen Wert zurueck !!!
      echo "<input type=\"hidden\" name=\"01_medium\" value=\"".$this->safe_message_value ("01_medium")."\">\n";
    }
    else {
      $param = "";
    }

    if  ($this->formdata["01_datum"] != "" ) {
      $arr = convdatetimeto ($this->formdata["01_datum"]);
      $this->formdata["01_datum"] = $arr ['datum'];
      $this->formdata["01_zeit"] = $arr ['zeit'];
    } else {
        $this->formdata["01_datum"] ="";
        $this->formdata["01_zeit"] = "";
    }

    echo "<td style=\"background-color: ".$this->bg[1]."; width: 230px; text-align: center; vertical-align: top;\"><!--005-->\n";
    echo "<div style=\"text-align: center;\">Aufnahmevermerk<br></div>\n";
    if ($this->formdata["01_medium"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["01_medium"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["01_medium"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if ($this->formdata["01_medium"]=="Fax") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Fax\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["01_medium"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS";
    echo "<br>\n";
/*468*/
    if (!$this->feld [1]){
      if ( ( $this->formdata["01_datum"] != "") or
           ( $this->formdata["01_zeit"]  != "" ) or
           ( $this->formdata["01_zeichen"] != "" ) ) {
        if ( posttakzeit ) {
          echo "<div style=\"text-align: center;\"><b>";
          $takzeit = konv_datetime_taktime (convtodatetime ($this->formdata["01_datum"], $this->formdata["01_zeit"]) );
          echo estab_message_html ($takzeit)."&nbsp; &nbsp;".$this->safe_message_value ("01_zeichen");
          echo "</b></div>";
        } else {
        echo "<div style=\"text-align: center;\"><b>";
        echo $this->safe_message_value ("01_datum")."&nbsp; &nbsp;".$this->safe_message_value ("01_zeit")."&nbsp; &nbsp;".$this->safe_message_value ("01_zeichen");
        echo "</b></div>";
        }
      } else {
        echo "<br>";
      }
    } else {
      echo "<input maxlength=\"4\" size=\"4\" name=\"01_datum\" value=\"".$this->safe_message_value ("01_datum")."\">\n";
      echo "<input maxlength=\"4\" size=\"4\" name=\"01_zeit\" value=\"".$this->safe_message_value ("01_zeit")."\">\n";
      echo "<input maxlength=\"3\" size=\"3\" name=\"01_zeichen\" value=\"".$this->safe_message_value ("01_zeichen")."\">\n";
    }
//    echo "<br>\n";
    echo "<div style=\"text-align: center;\">";
    echo "Datum &nbsp; &nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td><!--005-->\n";
    echo "</div>";

    /****************************************************************************\
    | Zeile, Spalte 2 , 2+3  2   2   Ausgang Annahmevermerk +
    |                         4  3   Ausgang BefÃ¶rderungsvermerk
    02_zeit
    02_zeichen
    \****************************************************************************/

    if ($this->formdata["02_zeit"] != "" ) {
      $arr = convdatetimeto ($this->formdata["02_zeit"]);
      $this->formdata["02_zeit"] = $arr ['zeit'];
    }   else {
      $this->formdata["02_zeit"] = "";
    }

    echo "<td style=\"width: 427px; background-color: ".$this->bg[2].";\"><!--006-->\n";
    echo "\n\n<!-- ********** TABLE   AUSGANG  *********** -->\n";
    echo "<table style=\"text-align: \"center\"; background-color: ".$this->rbl_bg_color."; width: 400px; height: 80px; margin-left: auto; margin-right: auto;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody><!--table + tbody 003-->\n";
    echo "<tr>\n";

    echo "<td style=\"height: 80px; width: 150px; background-color: ".$this->bg[2]."; text-align: center; vertical-align: top;\">\n";
    echo "<div style=\"text-align: center;\">Annahmevermerk<br></div>\n";

    if (!$this->feld[2]) {
      if ( ( $this->formdata["02_zeit"] != "" ) or
           ( $this->formdata["02_zeichen"] != "" ) ) {
        echo "<div style=\"text-align: center;\"><b>";
        echo $this->safe_message_value ("02_zeit")."&nbsp; &nbsp;".$this->safe_message_value ("02_zeichen");
        echo "</b></div>";
      } else {
        echo "<br>";
      }
    } else {
    echo "<input maxlength=\"4\" size=\"4\" name=\"02_zeit\" value=\"".$this->safe_message_value ("02_zeit")."\">&nbsp;\n
          <input maxlength=\"3\" size=\"3\" name=\"02_zeichen\" value=\"".$this->safe_message_value ("02_zeichen")."\"><br>\n";
    }
    echo "<div style=\"text-align: center;\">";
    echo "&nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</div>";

      if  ($this->formdata["03_datum"] != "" ) {
        $arr = convdatetimeto ($this->formdata["03_datum"]);
        $this->formdata["03_datum"] = $arr ['datum'];
        $this->formdata["03_zeit"] = $arr ['zeit'];
      }   else {
        $this->formdata["03_datum"] ="";
        $this->formdata["03_zeit"] = "";
     }

    echo "<td style=\"height: 80px; width: 220px; background-color: ".$this->bg[3]."; text-align: center; vertical-align: top;\">\n";
    echo "<div style=\"text-align: center;\">Bef&ouml;rderungsvermerk<br></div>\n";


    if (!$this->feld [3]){
      if ( ( $this->formdata["03_datum"]   != "") or
           ( $this->formdata["03_zeit"]    != "" ) or
           ( $this->formdata["03_zeichen"] != "" ) ) {
        if ( posttakzeit ) {
          echo "<div style=\"text-align: center;\"><b>";
          $takzeit = konv_datetime_taktime (convtodatetime ($this->formdata["03_datum"], $this->formdata["03_zeit"]) );
          echo estab_message_html ($takzeit)."&nbsp; &nbsp;".$this->safe_message_value ("03_zeichen");
          echo "</b></div>";
        } else {
          echo "<div style=\"text-align: center;\"><b>";
          echo $this->safe_message_value ("03_datum")."&nbsp; &nbsp;".$this->safe_message_value ("03_zeit")."&nbsp; &nbsp;".$this->safe_message_value ("03_zeichen");
          echo "</b></div>";
        }
      }else {
        echo "<br>";
      }
    } else {
      echo "<input maxlength=\"4\" size=\"4\" name=\"03_datum\" value=\"".$this->safe_message_value ("03_datum")."\">\n";
      echo "<input maxlength=\"4\" size=\"4\" name=\"03_zeit\" value=\"".$this->safe_message_value ("03_zeit")."\">\n";
      echo "<input maxlength=\"3\" size=\"3\" name=\"03_zeichen\" value=\"".$this->safe_message_value ("03_zeichen")."\"><br>\n";
    }

    echo "<div style=\"text-align: center;\">";
    echo "Datum &nbsp; &nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</div>";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "\n<!-- E N D E ********** TABLE   AUSGANG  *********** -->\n\n";

    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 2 , 4    incident-local TBB evidence number and direction
    04_richtung;
    04_nummer;
    \****************************************************************************/
    echo "<td style=\"width: 150px; background-color: ".$this->bg[4]."; text-align: left; vertical-align: top;\">TBB-Nachweis";
    $ttbEvidenceLabel = estab_message_list_tbb_evidence_label (array (
      "estab_tbb_book_lfd" => $this->formdata ["estab_ttb_lfd"] ?? null,
    ));
    echo "<div style=\"text-align: center;\"><b>".
      estab_message_html ($ttbEvidenceLabel)."<br>".
      estab_message_html (estab_message_list_direction_label (
        $this->formdata ["04_richtung"] ?? ""
      ))."</b></div>";

    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "\n<!-- ********** E N D E    TABLE  Eingang | Ausgang | Nachweisung   *********** -->\n\n";

    echo "</td>\n";
    echo "</tr>\n";
    // Zeile 3

    echo "<tr>\n";

    // Zeile, Spalte 3 , 1  Rufname der Gegenst. 16   5   Rufname der Gegenstelle
    echo "<td>\n";

    echo "\n\n<!-- ********** TABLE   Rufnahme Gegenstelle *********** -->\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 821px; height: 52px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"width: 227px; background-color: ".$this->bg[5].";\">Rufname der Gegenstelle/<br>\n";
    echo "Spruchkopf</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 3 , 2   16   5   Rufname der Gegenstelle
    05_gegenstelle
    \****************************************************************************/

    echo "<td style=\"text-align: center; background-color: ".$this->bg[5]."; width: 580px;\">\n";
    if  (!$this->feld[5]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->safe_message_value ("05_gegenstelle");
      echo "</b></div>";
    } else {
       echo "<input maxlength=\"80\" size=\"80\" name=\"05_gegenstelle\" value=\"".$this->safe_message_value ("05_gegenstelle")."\">\n";
    }
    echo "</td>";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "\n<!-- ********** E N D E     TABLE  Rufname Gegenstelle  *********** -->\n\n";


    echo "</td>\n";
    echo "</tr>\n";

    // Zeile 4
    echo "<tr> ";
    echo "<td style=\"width: 821px; height: 40px;\">"; // align=\"center\" valign=\"MIDDLE\">\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 821px; height: 46px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";

    echo "<tbody>\n";
    echo "<tr>\n";
    // Zeile, Spalte 4 , 1   32   6   BefÃ¶rderungsweg
    echo "<td style=\"width: 131px; background-color: ".$this->bg[6].";\">Bef&ouml;rderungsweg:</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 4 , 2   32   6   BefÃ¶rderungsweg
    06_befweg
    \****************************************************************************/

    echo "<td style=\"text-align: center; width: 446px; background-color: ".$this->bg[6].";\">\n";
    if (!$this->feld[6]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->safe_message_value ("06_befweg");
      echo "</b></div>";
    } else {
      echo "<input maxlength=\"50\" size=\"50\" name=\"06_befweg\" value=\"".$this->safe_message_value ("06_befweg")."\">\n";
    }

    echo "</td>";

    /****************************************************************************\
    // Zeile, Spalte 4 , 3   32   6   BefÃ¶rderungsweg
    06_befwegausw
    \****************************************************************************/
    if (!$this->feld[6]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input type=\"hidden\" name=\"06_befwegausw\" value=\"".$this->safe_message_value ("06_befwegausw")."\">\n";
    }
    else {
      $param = "";
    }

    echo "<td style=\"width: 230px; background-color: ".$this->bg[6].";\">";

    if ($this->formdata["06_befwegausw"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["06_befwegausw"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["06_befwegausw"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if ($this->formdata["06_befwegausw"]=="Fax") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Fax\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["06_befwegausw"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    /*                          F M  -  B E T R I E B S S T E L L E
    ********************************************************************************************
    ********************************************************************************************
                                            I N H A L T
    */

    echo "<tr>\n";

    echo "<td style=\"width: 831px; height: 0px;\" align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 821px; height: 64px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 5,1   64 7   Durchsage / Spruch
    \****************************************************************************/
    if (!$this->feld[7]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input type=\"hidden\" name=\"07_durchspruch\" value=\"".$this->safe_message_value ("07_durchspruch")."\">\n";
    }
    else {
      $param = "";}

    echo "<td style=\"width: 126px; background-color: ".$this->bg[7].";\">\n";
    if ($this->formdata["07_durchspruch"]=="D") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"07_durchspruch\" value=\"D\" type=\"radio\" ".$param.$sel.">DURCHSAGE<br>\n";
    if ($this->formdata["07_durchspruch"]=="S") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"07_durchspruch\" value=\"S\" type=\"radio\" ".$param.$sel.">Spruch</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 5,2   128    8   BefÃ¶rderungshinweis
    \****************************************************************************/

    echo "<td style=\"text-align: left; width: 140px; background-color: ".$this->bg[8].";\">Bef&ouml;rderungshinweis:<br>Tel.</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 5,3   128    8   BefÃ¶rderungshinweis
    08_befhinweis
    \****************************************************************************/
    echo "<td style=\"width: 294px; background-color: ".$this->bg[8].";\">\n";
    if  (!$this->feld[8]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->safe_message_value ("08_befhinweis");
      echo "</b></div>";
    } else {
      echo "<input maxlength=\"40\" size=\"40\" name=\"08_befhinweis\" value=\"".$this->safe_message_value ("08_befhinweis")."\">";
    }
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 5,4   128    8   BefÃ¶rderungshinweis
    08_befhinwausw
    \****************************************************************************/


    if  (!$this->feld[8]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input type=\"hidden\" name=\"08_befhinwausw\" value=\"".$this->safe_message_value ("08_befhinwausw")."\">\n";
    }
    else {
      $param = "";
    }
    echo "<td style=\"width: 225px; background-color: ".$this->bg[8].";\">\n";

    if ($this->formdata["08_befhinwausw"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["08_befhinwausw"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["08_befhinwausw"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if ($this->formdata["08_befhinwausw"]=="Fax") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Fax\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["08_befhinwausw"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS";
    echo "</td>\n";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";

echo "<!-- BIS HIER BIN ICH GEKOMMEN !!! *************+++++++++++++*********************************************-->";

    echo "<tr>\n";
    echo "<td style=\"text-align: left; background-color: ".$this->rbl_bg_color."\" align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 819px; height: 100px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 6,1   Vorrangstufe     256   9   VORRANGSTUFE !!!
    09_vorrangstufe;
    \****************************************************************************/
    echo "<td style=\"width: 90px; background-color: ".$this->bg[9].";\">Vorrangstufe<br>\n";

    if (((($this->formdata["09_vorrangstufe"]) != "" )) or (!$this->feld[9])) {
      echo "<div style=\"text-align: center; font-size:24px; font-weight:900;\"><big><big><b>";
      echo estab_message_html (
        estab_message_priority_label ($this->formdata["09_vorrangstufe"])
      );
      echo "</big></big></b></div>";
    } else {
      echo "<select ".$param." name=\"09_vorrangstufe\" aria-describedby=\"estab-priority-warning\">\n";
      foreach (estab_message_priority_options () as $priorityOption) {
        $selected = $this->formdata["09_vorrangstufe"] === $priorityOption["value"]
          ? " selected"
          : "";
        echo "<option value=\"".estab_message_html ($priorityOption["value"])."\"".$selected.">".
             estab_message_html ($priorityOption["label"])."</option>\n";
      }
      echo "</select>\n";
      echo "<small id=\"estab-priority-warning\">".
           estab_message_html (estab_message_priority_warning ("aaa")).
           "</small>\n";
    }
    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 6,2   Anschrift      512 10  Anschrift
    10_anschrift
    \****************************************************************************/
    echo "<td style=\"width: 600px; background-color: ".$this->bg[10].";\">Anschrift<br>\n";

    if (!$this->feld[10]) {
      echo "<div style=\"text-align: center; font-size:24px; font-weight:900;\">";
      echo $this->safe_message_value ("10_anschrift") ;
      echo "</div>\n";

    } else {
      echo "<div style=\"text-align: center;\">";
      echo "<textarea style=\"font-size:18px; font-weight:900;\" cols=\"40\" rows=\"2\" name=\"10_anschrift\">".$this->safe_message_value ("10_anschrift") ;
      echo "</textarea></div>\n";
    }


    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 6,3   GesprÃ¤chsnotiz    1024 11  GesprÃ¤chsnotiz
    11_gesprnotiz
    \****************************************************************************/
    if (((($this->formdata["11_gesprnotiz"]) != "" )) or (!$this->feld[11])) {
      $param = " disabled ";}
    else {
      $param = "";}

    echo "<td style=\"width: 110px; background-color: ".$this->bg[11].";\">Gespr&auml;chsnotiz<br>\n";
    echo "<div style=\"text-align: center;\">";

    if ($this->formdata["11_gesprnotiz"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"11_gesprnotiz\" type=\"checkbox\" ".$param.$sel."><br>\n";

    echo "</div>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"TOP\">\n";

    /****************************************************************************\
    // Zeile, Spalte 7,1  Inhalt   2048   12  Inhalt, Abfassungszeit
    12_inhalt
    \****************************************************************************/
    echo "<table style=\"text-align: left; width: 820px; height: 216px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    if  (!$this->feld[12]) {
      $param = " readonly ";}
    else {
      $param = "";}
    echo "<td valign=\"TOP\" style=\"background-color: ".$this->bg[12].";\">Inhalt/Text:<br>\n";
    if  ($this->feld[12]) {
      echo "<div style=\"text-align: center;\">";
      echo "<textarea style=\"font-size:18px; font-weight:900;\" cols=\"65\" rows=\"10\" name=\"12_inhalt\"".$param.">".$this->safe_message_value ("12_inhalt");
      echo "</textarea></div>\n";
    } else {
      echo "<div style=\"text-align: left; font-size:18px; font-weight:900;\">";
      echo "<input type=\"hidden\" name=\"12_inhalt\" value=\"".$this->safe_message_value ("12_inhalt")."\">\n";
      echo nl2br ($this->safe_message_value ("12_inhalt"));
      echo "</div>";
    }
      // Sind Anhge definiert? Wenn ja, anzeigen.
    if ($this->formdata["12_anhang"] != ""){
      echo "<input type=\"hidden\" name=\"12_anhang\" value=\"".$this->safe_message_value ("12_anhang")."\">\n";
      $this->list_anhang ();
    }
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr>\n";
    echo "<td style=\"text-align: left; background-color: ".$this->rbl_bg_color."; align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 817px; height: 34px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 8,1     2048 12  Inhalt, Abfassungszeit
    \****************************************************************************/

    echo "<td style=\"width: 135px; background-color: ".$this->bg[12].";\">Abfassungszeit:</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 8,2     4096 13  Absender, Einheit
    12_abfzeit
    \****************************************************************************/
    if  ($this->formdata["12_abfzeit"] != "" ) {
        $this->formdata["12_abfzeit"] = konv_datetime_taktime ($this->formdata["12_abfzeit"]);
    }   else {
        $this->formdata["12_abfzeit"] = "";
    }

    echo "<td style=\"width: 600px; background-color: ".$this->bg[13].";\">\n";

    if (!$this->feld [12]){
      echo "<div style=\"text-align: left; font-size:24px; font-weight:900;\">";
      echo $this->safe_message_value ("12_abfzeit") ;
      echo "<input type=\"hidden\" name=\"12_abfzeit\" value=\"".$this->safe_message_value ("12_abfzeit")."\">\n";
      echo "</div>\n";
    } else {
      echo "<input maxlength=\"4\" size=\"4\" name=\"12_abfzeit\" value=\"".$this->safe_message_value ("12_abfzeit")."\">";
    }

    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"top\">\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 817px; height: 54px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    // Zeile, Spalte 9,1    4096  13  Absender, Einheit
    echo "<td style=\"width: 100px; background-color: ".$this->bg[13].";\">Absender</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 9,2    8192  14  Zeichen Funktion
    13_abseinheit
    \****************************************************************************/
    echo "<td style=\"text-align: left; width: 200px; background-color: ".$this->bg[13].";\">\n";

    if (!$this->feld [13]){
      echo "<b><big>".$this->safe_message_value ("13_abseinheit")."</big></b>" ;
      echo "<input type=\"hidden\" name=\"13_abseinheit\" value=\"".$this->safe_message_value ("13_abseinheit")."\">\n";
    }
    else {
      echo "<div style=\"text-align: left;\" >";
      echo "<input style=\"font-size:16px; font-weight:900;\" maxlength=\"15\" size=\"15\"
              name=\"13_abseinheit\" value=\"".$this->safe_message_value ("13_abseinheit")."\">";
      echo "</div>\n";
    }
    echo "<br>\n";
    echo "Einheit/Einrichtung/Stelle";
    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 9,3 Zeichen     8192 14  Zeichen Funktion
    14_zeichen
    \****************************************************************************/
    echo "<td style=\"width: 100px; background-color: ".$this->bg[14].";\">\n";
    if (!$this->feld [14]){
//      echo "<div style=\"text-align: left; font-size:24px; font-weight:900;\">";
      echo "<b><big>".$this->safe_message_value ("14_zeichen")."</big></b><br>" ;
      echo "<input type=\"hidden\" name=\"14_zeichen\" value=\"".$this->safe_message_value ("14_zeichen")."\">\n";
//      echo "</div>\n";
    } else {
      echo "<input maxlength=\"25\" size=\"10\" name=\"14_zeichen\" value=\"".$this->safe_message_value ("14_zeichen")."\"><br>\n";
    }
    echo "Zeichen</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 9,4 Funktion    8192 14  Zeichen Funktion
    14_funktion
    \****************************************************************************/
    echo "<td style=\"width: 100px; background-color: ".$this->bg[14].";\">\n";
    if (!$this->feld [14]){
//      echo "<div style=\"text-align: left; font-size:24px; font-weight:900;\">";
      echo "<b><big>".$this->safe_message_value ("14_funktion")."</big></b><br>" ;
      echo "<input type=\"hidden\" name=\"14_funktion\" value=\"".$this->safe_message_value ("14_funktion")."\">\n";
//      echo "</div>\n";
    } else {
      echo "<input maxlength=\"25\" size=\"10\" name=\"14_funktion\" value=\"".$this->safe_message_value ("14_funktion")."\"".$param."><br>\n";
    }
    echo "Funktion</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    /*                                   I N H A L T
    ********************************************************************************************
    ********************************************************************************************
                                        S I C H T E R
    */
    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; width: 820px; height: 229px; background-color: ".$this->rbl_bg_color.";\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"width: 415px; background-color: ".$this->bg[15].";\">\n";
    echo "<table style=\"text-align: left; width: 418px; height: 65px;\" border=\"0\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 10,1 Quittung     16384  15  Quittung Sichter
    15_quitdatum
    15_quitzeichen
    \****************************************************************************/
    echo "<td style=\"width: 109px; background-color: ".$this->bg[15].";\">Quittung:<br></td>\n";
    echo "<td style=\"width: 289px; background-color: ".$this->bg[15].";\">\n";

    if  ($this->formdata["15_quitdatum"] != "" ) {
        $arr = convdatetimeto ($this->formdata["15_quitdatum"]);

        $this->formdata["15_quitdatum"] = $arr ['zeit'];
    }   else {
        $this->formdata["15_quitdatum"] = "";
    }

    if (!$this->feld [15]){
      echo "<div style=\"text-align: left;\">";
      echo $this->safe_message_value ("15_quitdatum")."&nbsp;&nbsp;".$this->safe_message_value ("15_quitzeichen");
      echo "</div>\n";

    } else {
    echo "<input maxlength=\"4\" size=\"4\" name=\"15_quitdatum\" value=\"".$this->safe_message_value ("15_quitdatum")."\">&nbsp;\n";
    echo "<input maxlength=\"3\" size=\"3\" name=\"15_quitzeichen\" value=\"".$this->safe_message_value ("15_quitzeichen")."\"><br>\n";
    }

    echo "&nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";


    echo "<table style=\"text-align: left; width: 450px; height: 144px; background-color: ".$this->rbl_bg_color.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";

    /****************************************************************************\
    // Zeile, Spalte 11,1   32768 16  Ziele
    16_empf
    \****************************************************************************/



    if ((!$this->feld[16])) {
      $param = " disabled ";}
    else {
      $param = "";}

    switch ($this->task) {
      case "SI-Admin":
      case "FM-Eingang_Sichter":
      case "Stab_sichten":
      case "Stab_gesprnoti":
      case "FM-Eingang_Anhang_Sichter":
      case "FM-Admin":
      case "FM-Ausgang_Sichter":
      case "SI-Admin":

        for ($m=1; $m<=5; $m++){ // Zeilen
          echo "<tr>";
          for ($n=1; $n<=4; $n++){  // Spalten
            // rote Kopie geht an...
            if ( ( $this->empfarray [$m][$n]["fkt"] == $this->redcopy2 ) and
                 ( $this->feld[16]) ) { // Wenn Sichter aktiv und rote Kopie
              echo "<td style=\"width: 75px; background-color: rgb(255,0,0);\">";
            }else{
              echo "<td style=\"width: 75px; background-color: ".$this->bg[16].";\">";
            }

// echo "empfarray ===>"; print_r(  $this->empfarray [$m][$n] ); echo "<br>";

            switch ($this->empfarray [$m][$n]["typ"]){

              case "cb":
                if ( ( $this->empfarray [$m][$n]["checked"]) and
                     ( $this->empfarray [$m][$n]["cpycol"] == "gn" ) ) {
                  $selcbgn = "checked=\"checked\"";} else {$selcbgn = "";}

                if ( ( $this->empfarray [$m][$n]["checked"]) and
                     ( $this->empfarray [$m][$n]["cpycol"] == "bl" ) ) {
                  $selcbbl = "checked=\"checked\"";} else {$selcbbl = "";}

                echo "<a style=\"background-color:#00B000;\">
                      <input name=\"16_gncopy\" type=\"radio\" ".$selcbgn." value=\"16_".$m.$n."_gn\">\n";

                echo "<a style=\"background-color:#0303FD;\">
                      <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."_bl\" type=\"checkbox\" ".$param.$selcbbl.">\n</a>";

	                echo estab_message_html ($this->empfarray [$m][$n]["fkt"] ?? "");
              break;

              case "t":
                if ($this->empfarray [$m][$n]["fkt"] != ""){
	                  echo estab_message_html ($this->empfarray [$m][$n]["fkt"] ?? "");
                } else {
                  echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
                }
              break;

              case "cbt":
                if ($this->empfarray [$m][$n]["checked"]) {$selcb = "checked=\"checked\"";} else {$selcb = "";}
                echo "<a style=\"background-color:#00B000;\">
                      <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel."></a>\n";
	                echo "<input maxlength=\"8\" size=\"8\" value=\"".
	                  estab_message_html ($this->empfarray [$m][$n]["fkt"] ?? "").
	                  "\" name=\"16_empf_sonst_".$m.$n."\" ".$param."></td>\n";
              break;
            }

            echo "</td>\n";
          } // for $n
          echo "</tr>\n";
        } // for $m
    break;

      case "Stab_lesen":
      case "Stab_schreiben":
      case "FM-Ausgang":
      case "FM-Eingang":
      case "FM-Eingang_Anhang":

    for ($m=1; $m<=5; $m++){ // Zeilen
      echo "<tr>";
      for ($n=1; $n<=4; $n++){  // Spalten
        echo "<td style=\"width: 75px; background-color: ".$this->bg[16].";\">";
        switch ($this->empfarray [$m][$n]["typ"]){

          case "cb":
            if ($this->empfarray [$m][$n]["checked"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
            echo "<input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel.">\n";
	            echo estab_message_html ($this->empfarray [$m][$n]["fkt"] ?? "");
          break;

          case "t":
            if ($this->empfarray [$m][$n]["fkt"] != ""){
	              echo estab_message_html ($this->empfarray [$m][$n]["fkt"] ?? "");
            } else {
              echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
            }
          break;

          case "cbt":
            if ($this->empfarray [$m][$n]["checked"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
            echo "<a style=\"background-color:#00B000;\">
                  <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel."></a>\n";
	            echo "<input maxlength=\"8\" size=\"8\" value=\"".
	              estab_message_html ($this->empfarray [$m][$n]["fkt"] ?? "").
	              "\" name=\"16_empf_sonst_".$m.$n."\" ".$param."></td>\n";
          break;
        }

        echo "</td>\n";
      } // for $n
      echo "</tr>\n";
    } // for $m
       break;

    } // switsch $this->task

    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 10,2  Vermerke      65536    17  Vermerke
    17_vermerke
    \****************************************************************************/
    echo "<td  valign=\"TOP\" style=\"text-align: left; width: 350px; background-color: ".$this->bg[17].";\">Vermerke:<br>\n";

    if (((($this->formdata["17_vermerke"]) != "" )) or (!$this->feld[17])) {
      echo $this->safe_message_value ("17_vermerke");
    } else {
      echo "<textarea cols=\"40\" rows=\"10\" name=\"17_vermerke\" ".$param.">".$this->safe_message_value ("17_vermerke")."</textarea>";
    }

    echo "</td>\n";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "<table style=\"text-align: left; background-color: rgb(255, 255, 255);\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";

    if ($this->task == "Stab_lesen"){
      echo "<tr><td>\n";
      echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".estab_message_html ($this->lfd)."\">\n";
      echo "<input type=\"hidden\" name=\"task\" value=\"".estab_message_html ($this->task)."\">\n";
      echo "<input type=\"image\" name=\"ablesen\" src=\"".$conf_design_path."/isread.gif\" alt=\"Zur Meldungsübersicht\">\n";
      echo "</td></tr>\n";
    } else {
      echo "<tr><td>\n";
      echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".estab_message_html ($this->lfd)."\">\n";
      echo "<input type=\"hidden\" name=\"task\" value=\"".estab_message_html ($this->task)."\">\n";
      echo "<input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/send.gif\" alt=\"Meldung übernehmen\">\n";
      echo "</td><td>\n";
      echo "<input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\" alt=\"Abbrechen und zur Meldungsübersicht\">\n";
      echo "</td></tr>\n";
    }
    echo "</tbody>\n</table>\n";
    echo "<br>\n";
    echo "</form>\n";
    echo "</div>\n</section>\n";
    echo "<footer class=\"estab-tool-footer\">\n";
    echo "<a href=\"".estab_message_html (estab_overview_url ()).
         "\">Zurück zur Meldungsübersicht</a>\n";
    echo "<span>Der Vordruck gehört zum aktuell aktiven Einsatz.</span>\n";
    echo "</footer>\n</main>\n";
    echo "</body>\n";
    echo "</html>\n";
  } // function plot_form

} // class

/*############################################################################
##############################################################################*/

$returnValue = array (); // first set returnValue to a defined state
if (count($_GET)>0)  { $returnValue = $_GET; }   // GET Daten, wenn vorhanden speichern
if (count($_POST)>0) { $returnValue = $_POST; }  // POST Daten, wenn vorhanden speichern
  

  if ( debug == true ){
    echo "<br><br>\n";
    echo "returnValue="; var_dump ($returnValue);    echo "#<br><br>\n";	
    echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
    echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
    echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
    //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
    echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
  }

  /**********************************************************************\
    ÃberprÃ¼fe ob die Listendarstellung geaendert werden soll
  \**********************************************************************/
  if (!isset ( $_SESSION["ueb_flt_darstellung"])){
    $_SESSION["ueb_flt_darstellung"] = 1;
    $_SESSION["ueb_flt_erledigt"]    = 0;
    $_SESSION["ueb_flt_unerledigt"]  = 1;
    $_SESSION["ueb_flt_anzahl"]      = 15;
    $_SESSION["ueb_flt_start"]       = 0 ;
    $_SESSION["ueb_flt_position"]    = 0;
  }
/*
  // filtern EIN / AUS
  if ( (isset ($returnValue["ueb_flt_darstellung_aus_x"])) or
       (isset ($returnValue["ueb_flt_darstellung_ein_x"])) ){

    if ( ($_SESSION["ueb_flt_darstellung"] == 1) and (isset ($returnValue["ueb_flt_darstellung_aus_x"])) ) {
      $_SESSION["ueb_flt_darstellung"] = 0;
    } elseif ( ($_SESSION["ueb_flt_darstellung"] == 0) and (isset ($returnValue["ueb_flt_darstellung_ein_x"])) ){
      $_SESSION["ueb_flt_darstellung"] = 1;
    }
  }

  // erledigte SICHTAR UNSICHTBAR
  if ( (isset ($returnValue["ueb_flt_erledigt_aus_x"])) or
       (isset ($returnValue["ueb_flt_erledigt_ein_x"])) ){

    if ( ($_SESSION["ueb_flt_erledigt"] == 1) and (isset($returnValue["ueb_flt_erledigt_aus_x"])) ) {
      $_SESSION["ueb_flt_erledigt"] = 0;
    } elseif ( ($_SESSION["ueb_flt_erledigt"] == 0) and (isset ($returnValue["ueb_flt_erledigt_ein_x"])) ){
      $_SESSION["ueb_flt_erledigt"] = 1;
    }
  }
  // unerledigte SICHTBAR UNSICHTBAR
  if ( (isset ($returnValue["ueb_flt_unerledigt_aus_x"])) or
       (isset ($returnValue["ueb_flt_unerledigt_ein_x"])) ){

    if ( ($_SESSION["ueb_flt_unerledigt"] == 1) and (isset($returnValue["ueb_flt_unerledigt_aus_x"])) ) {
      $_SESSION["ueb_flt_unerledigt"] = 0;
    } elseif ( ($_SESSION["ueb_flt_unerledigt"] == 0) and (isset ($returnValue["ueb_flt_unerledigt_ein_x"])) ){
      $_SESSION["ueb_flt_unerledigt"] = 1;
    }
  }
*/
  // finde MenÃ¼
  if (!isset ($_SESSION["ueb_flt_find_mask"])) {
    $_SESSION["ueb_flt_find_mask"] = 0;
  }

  if ( (isset ($returnValue["ueb_flt_find_mask_aus_x"])) or
       (isset ($returnValue["ueb_flt_find_mask_ein_x"])) ){

	   if ( ($_SESSION["ueb_flt_find_mask"] == 1) and (isset($returnValue["ueb_flt_find_mask_aus_x"])) ) {
        unset ($_SESSION["ueb_flt_search"]);
		unset ($returnValue["ueb_flt_search"]);
        $_SESSION["ueb_flt_find_mask"] = 0;
	
      } elseif ( ($_SESSION["ueb_flt_find_mask"] == 0) and (isset ($returnValue["ueb_flt_find_mask_ein_x"])) ){
        $_SESSION["ueb_flt_find_mask"] = 1;
		$_SESSION["ueb_flt_start"] = 0 ;
        $_SESSION["ueb_flt_position"] = 0;
      }
    
  }

  if (isset($returnValue["ueb_flt_suche_reset"])){ unset ($_SESSION["ueb_flt_search"]); }

  if (isset($returnValue["ueb_flt_search"])){
    if (
      !is_string ($returnValue ["ueb_flt_search"])
      || strlen ($returnValue ["ueb_flt_search"]) > 120
      || str_contains ($returnValue ["ueb_flt_search"], "\0")
    ) {
      estab_overview_forbid ();
    }
    if (isset($_SESSION["ueb_flt_search"]) AND ( $_SESSION["ueb_flt_search"] != $returnValue ["ueb_flt_search"])){
      $_SESSION["ueb_flt_start"] = 0 ;
      $_SESSION["ueb_flt_position"] = 0;
    }
    $_SESSION["ueb_flt_search"] = $returnValue ["ueb_flt_search"];
  }

  // Listennavigation
  if (isset ($returnValue["ueb_flt_anzahl_x"])) {
    $requestedPageSize = filter_var (
      $returnValue["ueb_flt_anzahl"] ?? null,
      FILTER_VALIDATE_INT,
      array ("options" => array ("min_range" => 5, "max_range" => 25))
    );
    if (!is_int ($requestedPageSize) || ($requestedPageSize % 5) !== 0) {
      estab_overview_forbid ();
    }
    $_SESSION["ueb_flt_anzahl"] = $requestedPageSize;
  }

  $overviewRecipientFunctions = array ();
  foreach ($conf_empf as $recipientDefinition) {
    $recipientFunction = (string) ($recipientDefinition ["fkt"] ?? "");
    if ($recipientFunction !== "" && !in_array (
      $recipientFunction,
      $overviewRecipientFunctions,
      true
    )) {
      $overviewRecipientFunctions [] = $recipientFunction;
    }
  }
  $overviewAllowedListKeys = array_fill_keys (array (
    "ml_q", "ml_direction", "ml_priority", "ml_status", "ml_from",
    "ml_to", "ml_recipient", "ml_sort", "ml_page", "ml_page_size",
    "ml_apply", "ml_reset", "ml_remove",
  ), true);
  foreach (array_keys ($returnValue) as $requestKey) {
    if (
      is_string ($requestKey)
      && str_starts_with ($requestKey, "ml_")
      && !isset ($overviewAllowedListKeys [$requestKey])
    ) {
      estab_overview_forbid ();
    }
  }
  try {
    $overviewFilters = estab_message_list_apply_request (
      is_array ($_SESSION ["estab_message_overview_filters"] ?? null)
        ? $_SESSION ["estab_message_overview_filters"]
        : estab_message_list_default_filters (),
      $returnValue,
      $overviewRecipientFunctions
    );
  } catch (Throwable $exception) {
    estab_overview_forbid ();
  }
  $_SESSION ["estab_message_overview_filters"] = $overviewFilters;

  if (isset($returnValue['ueb_flt_start_x'])) { $_SESSION['ueb_flt_navi'] = "start";}
  if (isset($returnValue['ueb_flt_back_x']))  { $_SESSION['ueb_flt_navi'] = "back";}
  if (isset($returnValue['ueb_flt_for_x']))   { $_SESSION['ueb_flt_navi'] = "for";}
  if (isset($returnValue['ueb_flt_end_x']))   { $_SESSION['ueb_flt_navi'] = "end";}



  /**********************************************************************\
    ÃberprÃ¼fe ob die Listendarstellung geaendert werden soll
  \**********************************************************************/


/**********************************************************************\
  ---  M e l d u n g   l e s e n ---

  Darstellung der Meldung ber die laufende Nummer
\**********************************************************************/
   if ((isset($returnValue["ueb_fm"])) and ( $returnValue["ueb_fm"] == "ueb")){
      try {
        $overviewRecordId = estab_message_positive_id ($returnValue ["00_lfd"] ?? null);
      } catch (Throwable $exception) {
        estab_overview_forbid ();
      }
      $messageConnection = estab_message_connect ($conf_4f_db);
      try {
        $formdata = estab_message_fetch_for_incident_by_id (
          $messageConnection,
          $conf_4f_tbl ["nachrichten"],
          $overviewRecordId,
          $overviewIncidentId
        );
      } finally {
        estab_auth_close ($messageConnection);
      }
      if (!is_array ($formdata)) {
        estab_overview_forbid ();
      }
      $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
   }



  if ( !isset( $returnValue["ueb_fm"])) {

    if ( isset ($returnValue["ueb_flt_submit"])) { // es soll was geÃ¤ndert werden
      if ($returnValue["ueb_flt_darstellung"] == "on") {$_SESSION["ueb_flt_darstellung"] = 1;
        if (isset ($returnValue["ueb_flt_anzahl"])) {$_SESSION["ueb_flt_anzahl"] = $returnValue["ueb_flt_anzahl"]; }
        else {
          $_SESSION["ueb_flt_anzahl"] = 5;
        }
      } else {
        $_SESSION["ueb_flt_darstellung"] = 0;
        unset ($_SESSION["ueb_flt_anzahl"]);
      }
    }


    $list = new listen (
      "SIADMIN",
      "",
      $overviewIncidentId,
      $overviewFilters
    );
    $list->createlist ();
  }

?>
