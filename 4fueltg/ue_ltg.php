<?php

define ("debug", false);
session_start ();
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/message_priority.php";
require_once __DIR__ . "/../app/message_list.php";
require_once __DIR__ . "/../app/message_list_ui.php";
require_once __DIR__ . "/../app/message_timeline.php";
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
// Der Nachrichtenvordruck. Eine Fassung, siehe unten in der
// Erklaerung, warum es hier keine zweite gibt.
require_once __DIR__ . "/../4fach/4fachform.php";

$overviewReadIdentity = estab_read_session_identity ($_SESSION);
estab_navigation_require_selected_duty (
  $_SESSION,
  $overviewReadIdentity,
  "message-overview",
  $_SERVER
);
$overviewAccessConnection = null;
$overviewIncidentId = null;
$overviewActingIdentity = null;
$overviewAccessError = null;
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
  $overviewActingIdentity = $overviewReadScope ["identity"];
  /*
   * Dieselbe Kennung, unter der auch der Steuerlauf sie fuehrt.
   *
   * Der Nachrichtenvordruck leitet die Durchschriftenfarbe daraus ab --
   * sie sagt auf einen Blick, ob eine Meldung die eigene Funktion angeht.
   * Er liest dafuer $GLOBALS ["workflowSelectedIdentity"], so wie
   * mainindex.php sie setzt (dort Zeile 310, aus demselben Lesebereich).
   *
   * Ohne diese Zeile faende er nichts und faerbte jede Meldung neutral:
   * kein Sicherheitsloch, aber der Verlust genau der Angabe, wegen der die
   * Farbe da ist. Wichtig bleibt die Herkunft -- der vom Server geprueste
   * Lesebereich, nicht die rohe Sitzung.
   */
  $GLOBALS ["workflowSelectedIdentity"] = $overviewActingIdentity;
  /*
   * Und der Einsatz dazu -- aus demselben Lesebereich.
   *
   * Der Vordruck laedt den Bearbeitungsweg nur, wenn er weiss, in
   * welchem Einsatz er steht: Ohne diese Zeile faellt er auf "kann
   * derzeit nicht sicher angezeigt werden" zurueck. Das ist der
   * richtige Rueckfall -- lieber keine Auskunft als eine aus einem
   * anderen Einsatz --, aber hier gibt es die Auskunft.
   *
   * mainindex.php setzt beide Angaben genauso, aus demselben
   * Lesebereich (dort Zeilen 310 und 312).
   */
  $GLOBALS ["workflowIncidentId"] = $overviewIncidentId;
} catch (EstabNoActiveIncidentException) {
  $overviewAccessError = array (
    409,
    "Kein aktiver Einsatz",
    "Kein Einsatz aktiv.",
  );
} catch (EstabReadPermissionException) {
  $overviewAccessError = array (
    403,
    "Keine Berechtigung für die Meldungsübersicht",
    "Die Meldungsübersicht setzt einen angetretenen Dienst im aktiven "
      ."Einsatz voraus.",
  );
} catch (Throwable $exception) {
  error_log (
    "eStab message overview authorization failed: ".$exception->getMessage ()
  );
  $overviewAccessError = array (
    503,
    "Meldungsübersicht vorübergehend nicht verfügbar",
    "Die Leseberechtigung kann derzeit nicht geprüft werden.",
  );
} finally {
  if ($overviewAccessConnection instanceof mysqli) {
    estab_auth_close ($overviewAccessConnection);
  }
}
if (is_array ($overviewAccessError)) {
  estab_session_ui_abort (
    $_SESSION,
    $overviewAccessError [0],
    $overviewAccessError [1],
    $overviewAccessError [2],
    "message-overview"
  );
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

/*
 * Hier standen zwei Funktionen ohne Aufrufer:
 * estab_overview_row_start und estab_overview_recipient_cell.
 *
 * Sie stammen aus der Zeit, als die Meldungsuebersicht ihre Zeilen selbst
 * zeichnete. Seit sie aus dem Tabellenbauteil kommt, ruft sie niemand
 * mehr. Die erste trug dabei ein fest eingetragenes Farbpaar -- Gelb auf
 * Schwarz --, das kein Kontrastwaechter erreichte: Sie stand nicht im
 * Stylesheet und nicht in der Liste, die list_contrast_security absucht.
 *
 * Gefunden beim Nachzaehlen der Aufrufer, nicht durch eine Pruefung.
 */

/*
 * Auch estab_overview_empty_row hatte keinen Aufrufer mehr. Der Satz bei
 * leerer Trefferliste kommt seit der Umstellung aus dem Tabellenbauteil
 * ("leer"), das ihn ueber die richtige Spaltenzahl setzt -- die Zahl von
 * Hand mitzufuehren war genau die Fehlerquelle, gegen die der Zerleger
 * des Bauteils heute abbricht.
 */

function estab_overview_forbid () {
  estab_session_ui_abort (
    $_SESSION,
    403,
    "Aktion nicht erlaubt",
    "Aktion nicht erlaubt.",
    "message-overview"
  );
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
                      if (!$is_last_page){

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
    echo "</header>\n";
    /*
     * Die Filter der Uebersicht stehen *im* Band des Tabellenbauteils.
     *
     * Sie bringen kein eigenes Formular mehr mit -- ein Formular im Formular
     * wirft der Browser weg, und zwei Baender nebeneinander waeren genau die
     * Uneinheitlichkeit, die abgestellt werden sollte. Volltextsuche,
     * Seitengroesse, Ergebnisleiste und Blaetterer kommen vom Bauteil und
     * sprechen ueber die Adressfelder der Seite (ml_q, ml_page_size,
     * ml_page) weiterhin ihre Sprache.
     */
    ob_start ();
    estab_message_list_render_controls (
      $this->filters,
      $recipientFunctions,
      array (
        "action" => estab_overview_url (),
        "method" => "get",
        "target" => "_self",
        "dom_prefix" => "overview-message-list",
        "nur_felder" => true,
      )
    );
    $uebersichtFelder = (string) ob_get_clean ();
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
        },
        $uebersichtFelder,
        array (
          "treffer" => (int) ($this->pageWindow ["count"] ?? count ($result)),
          "gesamt" => (int) ($this->pageWindow ["count"] ?? count ($result)),
          "seite" => (int) ($this->pageWindow ["page"] ?? 1),
          "seiten" => (int) ($this->pageWindow ["page_count"] ?? 1),
        )
      );
    }
    // Blaetterer und Ergebnisleiste kommen aus dem Bauteil.
    echo "</section>\n<footer class=\"estab-tool-footer\">\n";
    echo "<a href=\"".estab_auth_html (estab_application_root ())."\">";
    echo "Zur eStab-Übersicht</a>\n";
    echo "<span>Es werden ausschließlich Daten des aktiven Einsatzes angezeigt.</span>\n";
    echo "</footer>\n</main>\n</body>\n</html>\n";

  }


} // class


/*****************************************************************************\
   Der Nachrichtenvordruck steht in 4fach/4fachform.php -- und nur dort.

   Hier stand eine zweite Klasse `nachrichten4fach` mit einer eigenen
   Fassung des Vordrucks. Sie wurde nie mitgezogen, und das war im Betrieb
   zu sehen:

   * Sie zeichnete `<body class="estab-tool-page">`. Der Druckblock des
     Stylesheets spricht ueber `body.estab-message-form-body`. Auf dieser
     Fassung griff deshalb keine einzige Druckregel: Der Knopf rief
     window.print(), und der Browser druckte den Bildschirm samt
     Menuespalte statt des Papierbildes.
   * Ihre Zugriffsregeln waren aelter -- get_access_by_task hatte hier 121
     Zeilen, in der gepflegten Fassung 161. Welche Felder ein
     Arbeitsschritt oeffnet, darf nicht zwei Antworten haben.
   * Sie kannte weder Ausfuellhilfen noch Vorschlaege noch
     Anlagenvorschau.

   Geloescht: 1233 Zeilen. Die Uebersicht baut den Vordruck jetzt aus
   derselben Klasse wie jede andere Stelle; sie wird oben hereingezogen.
\*****************************************************************************/

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
      /*
       * Den Bearbeitungsweg holt die Vordruckklasse selbst.
       *
       * Hier stand er zwanzig Zeilen weiter oben noch einmal, samt eigener
       * Fehlermeldung -- eine zweite Antwort auf dieselbe Frage. Die
       * gepflegte Klasse laedt ihn im Konstruktor (load_message_timeline)
       * und hat fuer den Fehlerfall message_timeline_unavailable.
       */
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
