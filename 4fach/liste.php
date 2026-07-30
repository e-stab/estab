<?php
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Liste</big></big><br>";  }
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/message_repository.php";
require_once __DIR__ . "/../app/message_priority.php";
require_once __DIR__ . "/../app/message_transport.php";
require_once __DIR__ . "/../app/read_authorization.php";
include ("katego.php");
include ("../4fcfg/e_cfg.inc.php");

function estab_list_state_action ($action, $recordId, $todo, $image, $alt) {
  $safeAction = estab_auth_html ($action);
  $safeRecordId = estab_auth_html ($recordId);
  $safeTodo = estab_auth_html ($todo);
  $safeImage = estab_auth_html ($image);
  $safeAlt = estab_auth_html ($alt);
  echo "<form method=\"post\" action=\"mainindex.php\" target=\"_self\" style=\"display:inline\">";
  echo estab_csrf_field ();
  echo "<input type=\"hidden\" name=\"action\" value=\"".$safeAction."\">";
  echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$safeRecordId."\">";
  echo "<input type=\"hidden\" name=\"todo\" value=\"".$safeTodo."\">";
  echo "<button type=\"submit\" style=\"border:0;background:transparent;padding:0\">";
  echo "<img src=\"".$safeImage."\" alt=\"".$safeAlt."\" border=\"0\"></button></form>";
}

/** Render a detail navigation as a CSRF-protected POST control. */
function estab_list_detail_action ($route, $value, $recordId, $label, $large = false) {
  if (!in_array ($route, array ("stab", "sichter", "fm", "ldf"), true)) {
    throw new InvalidArgumentException ("Unbekannte Detailroute");
  }
  $safeRoute = estab_auth_html ($route);
  $safeValue = estab_auth_html ($value);
  $safeRecordId = estab_auth_html (estab_message_positive_id ($recordId));
  $safeLabel = estab_message_html ($label);
  echo "<form method=\"post\" action=\"mainindex.php\" target=\"_self\" style=\"display:inline\">";
  echo estab_csrf_field ();
  echo "<input type=\"hidden\" name=\"".$safeRoute."\" value=\"".$safeValue."\">";
  echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$safeRecordId."\">";
  echo "<button type=\"submit\" style=\"border:0;background:transparent;padding:0;".
       "color:inherit;text-decoration:underline;cursor:pointer;font:inherit\">";
  if ($large) { echo "<big>"; }
  echo $safeLabel;
  if ($large) { echo "</big>"; }
  echo "</button></form>";
}

/** Build and escape a category navigation URL without mixing data into HTML. */
function estab_list_category_url ($baseUrl, array $parameters) {
  $separator = strpos ((string) $baseUrl, "?") === false ? "?" : "&";
  return estab_message_html (
    (string) $baseUrl.$separator.http_build_query ($parameters, "", "&", PHP_QUERY_RFC3986)
  );
}

/** Build and escape the fixed local category-button URL. */
function estab_list_category_icon ($label, $color) {
  return estab_message_html (
    "./kategobutton.php?".http_build_query (array (
      "icontext" => (string) $label,
      "color" => (string) $color,
    ), "", "&", PHP_QUERY_RFC3986)
  );
}

/** Clamp and advance the legacy list pager without putting request data in SQL. */
function estab_list_page_window ($resultCount) {
  $pageSize = filter_var ($_SESSION["filter_anzahl"] ?? 15, FILTER_VALIDATE_INT, array (
    "options" => array ("min_range" => 1, "max_range" => 200),
  ));
  if (!is_int ($pageSize)) { $pageSize = 15; }
  $start = filter_var ($_SESSION["filter_start"] ?? 0, FILTER_VALIDATE_INT, array (
    "options" => array ("min_range" => 0, "max_range" => PHP_INT_MAX),
  ));
  if (!is_int ($start)) { $start = 0; }

  switch ((string) ($_SESSION["flt_navi"] ?? "")) {
    case "start":
      $start = 0;
    break;
    case "back":
      $start = max (0, $start - $pageSize);
    break;
    case "for":
      if ($start + $pageSize < $resultCount) { $start += $pageSize; }
    break;
    case "end":
      $start = $resultCount > 0
        ? intdiv ($resultCount - 1, $pageSize) * $pageSize
        : 0;
    break;
  }
  unset ($_SESSION["flt_navi"]);

  if ($resultCount === 0) {
    $start = 0;
  } elseif ($start >= $resultCount) {
    $start = intdiv ($resultCount - 1, $pageSize) * $pageSize;
  }
  $_SESSION["filter_anzahl"] = $pageSize;
  $_SESSION["filter_start"] = $start;
  $_SESSION["filter_rescount"] = $resultCount;
  return array ($start, $pageSize);
}
/*****************************************************************************\
   Datei: liste.php

   benötigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }

class Listen extends kategorien {
/******************************************************************************\
   $welche ~= Art der Liste die Ausggeben werden soll. Möglich sind:
     FMA    - Fernmeldeausgangsliste
     STUSER - Stabbenutzer
     STSI   - Stab Sichter
     FMNWE  - Fernmelde Nachweis Eingang
     FMNWA  - Fernmelde Nachweis Ausgang
     ADMIN  - Administrative Liste
\******************************************************************************/

  var $listenart;
  var $benutzer;
  var $flt_status;
  var $flt_msg_pro_seite ;
  var $flt_start_msg;
  var $flt_gelesen ;
  var $flt_erledigt;


  // Listengestaltung

  function __construct ($welche, $user){
    $this->listen ($welche, $user);
  }

/******************************************************************************\

\******************************************************************************/
  function explodereceiver ( $empf) {
    $fktcopycolor = array ();
    $receiver = explode (",",$empf);
    for ($i=0; $i < count( $receiver ); $i++ ) {
      $hilfeaus = explode ( "_", $receiver [$i] ) ;
      if (($hilfeaus[0] ?? "") !== "") {
        $fktcopycolor[$hilfeaus[0]] = $hilfeaus [1] ?? "";
      }
    }
    return $fktcopycolor;
  }



/******************************************************************************\

\******************************************************************************/
  function listen ($welche, $user){
    $this->listenart = $welche;
    $this->benutzer  = $user;
    if (isset($_SESSION["filter_darstellung"])) { $this->flt_status   = $_SESSION["filter_darstellung"]; } else { $this->flt_status = NULL; } ;
    if (isset($_SESSION["filter_anzahl"])) { $this->flt_msg_pro_seite = $_SESSION["filter_anzahl"] ;     } else { $this->flt_msg_pro_seite = NULL; } ;
    if (isset($_SESSION["startmit"])) { $this->flt_start_msg          = $_SESSION["startmit"];           } else { $this->flt_start_msg = NULL; } ;
    if (isset($_SESSION["gelesene"])) { $this->flt_gelesen            = $_SESSION["gelesene"] ;          } else { $this->flt_gelesen  = NULL; } ;
    if (isset($_SESSION["erledigte"])) { $this->flt_erledigt          = $_SESSION["erledigte"] ;         } else { $this->flt_erledigt = NULL; } ;
  }

/******************************************************************************\

\******************************************************************************/
  function darstellungs_art ( $what ){

    include ("../4fcfg/config.inc.php");

    switch ($this->listenart){
      /*************************************************************************\
                               FFFFF M   M  AAA
                               F     MM MM A   A
                               FFF   M M M AAAAA
                               F     M   M A   A
                               F     M   M A   A
      \*************************************************************************/
      case "FMA":           /***** F M A ****/
      case "LDF":           /***** L d F ****/
      break;

      /*************************************************************************\
        SSSSS  TTTTT   AAA  BBBB   l
        S        T    A   A B   B  l
        SSSSS    T    AAAAA BBBBB  l 
            S    T    A   A B   B  l
        SSSSS    T    A   A BBBB   l esen
      \*************************************************************************/
      case "Stab_lesen":  // ******  S T A B    l e s e n *****
          if ( debug ) { echo "<b>file:liste.php:_92 fkt:darstellungsart - switch (this->listenart):Stab_lesen </b><br>"; }
          echo "\n<form action=\"".estab_message_html ($conf_4f ["MainURL"])."\" method=\"POST\" target=\"mainframe\" data-estab-list-filter>\n";
          echo "<table><tbody>";
          echo "<tr>";
          echo "<td>";
          echo "<big><b>".($_SESSION['filter_start']+1)."|".($_SESSION['filter_start']+$_SESSION['filter_anzahl'])."|<big>".($_SESSION["filter_rescount"])."</big></b></big>";
          echo "</td>";
          echo "<td>";
          echo "Meldung/Seite:<br>\n";

            // Voreinstellung fÃ¼r die Meldungen pro Seite
          if ( !(isset ($_SESSION["filter_anzahl"])) OR
              ( $_SESSION["filter_anzahl"] == "" )
           ){$_SESSION["filter_anzahl"] = 5; }

          echo "<table border=\"0\" ><tbody>";
          echo "<tr>";

          echo "<td>";
          echo "<div  style=\"border-top-color:#DCDCFF; border-left-color:#DCDCFF; border-right-color:#DCDCFF; border-bottom-color:#000000; border-width:1px; border-style:solid; padding:0px\">";
            for ($pps=5; $pps <=25; $pps+=5){
              if ( $_SESSION["filter_anzahl"] == $pps )  {
                echo "<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
                  "filter_anzahl_x" => 1, "filter_anzahl" => $pps,
                ))."\"><img src=\"button.php?type=icon&amp;status=AUS&amp;text=".$pps."&amp;bg=blue\" border=\"0\" alt=\"Anzahl".$pps."EIN\"></a>";
              } else {
                echo "<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
                  "filter_anzahl_x" => 1, "filter_anzahl" => $pps,
                ))."\"><img src=\"button.php?type=icon&amp;status=EIN&amp;text=".$pps."&amp;bg=lighterblue\" border=\"0\" alt=\"Anzahl".$pps."AUS\"></a>";
              }
            }
          echo "</div>";
          echo "</td>";
          echo "</tr>";
          echo "</tbody></table>";
          echo "</td>";
          echo "<td>";
          if ($_SESSION ['filter_unerledigt'] == 0)  {
            echo "<div>";
            echo "<input type=\"image\" name=\"filter_unerledigt_ein\" src=\"button.php?type=push&textpos=buttom&status=AUS&text=un-\" alt=\"unerledigte\">\n";
            echo "</div>";
          } else {
            echo "<div>";
            echo "<input type=\"image\" name=\"filter_unerledigt_aus\" src=\"button.php?type=push&textpos=buttom&status=EIN&text=un-\" alt=\"unerledigte\">\n";
            echo "</div>";
          }
          echo "</td>";
          echo "<td>";
          if ($_SESSION ['filter_erledigt'] == 0)  {
            echo "<div>";
            echo "<input type=\"image\" name=\"filter_erledigt_ein\" src=\"button.php?type=push&textpos=buttom&status=AUS&text=erledigt\" alt=\"erledigte\">\n";
            echo "</div>";
          } else {
            echo "<div>";
            echo "<input type=\"image\" name=\"filter_erledigt_aus\" src=\"button.php?type=push&textpos=buttom&status=EIN&text=erledigt\" alt=\"erledigte\">\n";
            echo "</div>";
          }
          echo "</td>";
          echo "<td>";
          if ($_SESSION ['flt_find_mask'] == 0)  {
            echo "<div>";
            echo "<input type=\"image\" name=\"flt_find_mask_ein\" src=\"button.php?type=push&textpos=buttom&status=AUS&text=finden\" alt=\"finden\">\n";
            echo "</div>";
          } else {
            echo "<div>";
            echo "<input type=\"image\" name=\"flt_find_mask_aus\" src=\"button.php?type=push&textpos=buttom&status=EIN&text=finden\" alt=\"finden\">\n";
            echo "</div>";
          }
          echo "</td>";

        echo "<!-- liste.php 233 -->";
        echo "<td>";
        echo "<table><tbody>";
        echo "<tr>";
        if ($_SESSION["flt_find_mask"] == 1){
          echo "<td>";
          if (isset ($_SESSION ["flt_search"]) ) { $defvalue = $_SESSION ["flt_search"] ;}
          else {$defvalue = "";}
          echo "<p>Suchbegriff: <input name=\"flt_search\" value=\"".estab_message_html ($defvalue)."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
          echo "</td>";
          echo "<td>";
          echo "<input name=\"filter_suche\" value=\"suchen\" type=\"submit\">\n";
          echo "</td>";
        }
        echo "</tr>";
        echo "</tbody></table>";
        echo "</td>";
        echo "</tr>";
        echo "</tbody></table>";
        echo "</form>\n";
      break;


      case "Stab_sichten":   /*********** S t a b   s i c h t e n ************/
      break;
      /*************************************************************************\
               SSSSS III  AAA  DDDD  M   M III N   N
               S      I  A   A D   D MM MM  I  NN  N
               SSSSS  I  AAAAA D   D M M M  I  N N N
                   S  I  A   A D   D M   M  I  N  NN
               SSSSS III A   A DDDD  M   M III N   N
      \*************************************************************************/
      case "SIADMIN":  // ***************  SICHTER ADMINISTRATOR  *********************
      case "FMADMIN":
          if ( debug ) { echo "<b>file:liste.php:194 fkt:darstellungsart - switch (this->listenart):SIADMIN/FMADMIN </b><br>"; }
          echo "\n<form action=\"".estab_message_html ($conf_4f ["MainURL"])."\" method=\"POST\" target=\"mainframe\" data-estab-list-filter>\n";
          echo "<table><tbody>";
          echo "<tr>";
          echo "<td>";
          echo "<big><b>".($_SESSION['filter_start']+1)."|".($_SESSION['filter_start']+$_SESSION['filter_anzahl'])."|<big>".($_SESSION["filter_rescount"])."</big></b></big>";
          echo "</td>";
          echo "<td>";
          echo "Meldung/Seite:<br>\n";

            // Voreinstellung fÃ¼r die Meldungen pro Seite
          if ( !(isset ($_SESSION["filter_anzahl"])) OR
              ( $_SESSION["filter_anzahl"] == "" )
           ){$_SESSION["filter_anzahl"] = 5; }

          echo "<table border=\"0\" ><tbody>";
          echo "<tr>";

          echo "<td>";
          echo "<div  style=\"border-top-color:#DCDCFF; border-left-color:#DCDCFF; border-right-color:#DCDCFF; border-bottom-color:#000000; border-width:1px; border-style:solid; padding:0px\">";
            for ($pps=5; $pps <=25; $pps+=5){
              if ( $_SESSION["filter_anzahl"] == $pps )  {
                echo "<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
                  "filter_anzahl_x" => 1, "filter_anzahl" => $pps,
                ))."\"><img src=\"button.php?type=icon&amp;status=AUS&amp;text=".$pps."&amp;bg=blue\" border=\"0\" alt=\"Anzahl".$pps."EIN\"></a>";
              } else {
                echo "<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
                  "filter_anzahl_x" => 1, "filter_anzahl" => $pps,
                ))."\"><img src=\"button.php?type=icon&amp;status=EIN&amp;text=".$pps."&amp;bg=lighterblue\" border=\"0\" alt=\"Anzahl".$pps."AUS\"></a>";
              }
            }
          echo "</div>";
          echo "</td>";
          echo "</tr>";
          echo "</tbody></table>";
          echo "</td>";

		  
          echo "<td>";
          if ($_SESSION ['flt_find_mask'] == 0)  {
            echo "<div>";
            echo "<input type=\"image\" name=\"flt_find_mask_ein\" src=\"button.php?type=push&textpos=buttom&status=AUS&text=finden\" alt=\"finden\">\n";
            echo "</div>";
          } else {
            echo "<div>";
            echo "<input type=\"image\" name=\"flt_find_mask_aus\" src=\"button.php?type=push&textpos=buttom&status=EIN&text=finden\" alt=\"finden\">\n";
            echo "</div>";
          }
          echo "</td>";

        echo "<!-- liste.php 233 -->";
        echo "<td>";
        echo "<table><tbody>";
        echo "<tr>";
        if ($_SESSION["flt_find_mask"] == 1){
          echo "<td>";
          if (isset ($_SESSION ["flt_search"]) ) { $defvalue = $_SESSION ["flt_search"] ;}
          else {$defvalue = "";}
          echo "<p>Suchbegriff: <input name=\"flt_search\" value=\"".estab_message_html ($defvalue)."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
          echo "</td>";
          echo "<td>";
          echo "<input name=\"filter_suche\" value=\"suchen\" type=\"submit\">\n";
          echo "</td>";
        }
        echo "</tr>";
        echo "</tbody></table>";
        echo "</td>";
        echo "</tr>";
        echo "</tbody></table>";
        echo "</form>\n";


      break;
    }
    if ( debug ) { echo "<b>file:liste.php:279 fkt:darstellungsart_ENDE </b><br>"; }
  }

  /******************************************************************************\
    Funktion:  listen_navi ()
  SELECT * FROM `nv_nachrichten` WHERE `00_lfd` IN

  (SELECT msg FROM `nv_masterkategolink` WHERE `katego` = (

  SELECT lfd FROM `nv_masterkatego` WHERE `kategorie` = "2m"));

  \******************************************************************************/
  function  listen_navi (){
    include ("../4fcfg/config.inc.php");
    echo "<input type=\"image\" name=\"flt_start\" src=\"".$conf_design_path."/go_start.gif\" alt=\"Anfang\">\n";
    echo "<input type=\"image\" name=\"flt_back\" src=\"".$conf_design_path."/go_back.gif\" alt=\"zurueck\">\n";
    echo "<input type=\"image\" name=\"flt_for\" src=\"".$conf_design_path."/go_forward.gif\" alt=\"vor\">\n";
    echo "<input type=\"image\" name=\"flt_end\" src=\"".$conf_design_path."/go_end.gif\" alt=\"Ende\">\n";
  }


  /******************************************************************************\
    Funktion:  kategoliste
  SELECT * FROM `nv_nachrichten` WHERE `00_lfd` IN

  (SELECT msg FROM `nv_masterkategolink` WHERE `katego` = (

  SELECT lfd FROM `nv_masterkatego` WHERE `kategorie` = "2m"));

  \******************************************************************************/

  var $db_server;
  var $db_benutzer;
  var $db_passwort;
  var $db_name ;
  var $db_tablname ;
  var $db_tablnamelk;

  var $db_master_katego ;

  var $sqlquery;
  var $db_hndl ;
  var $masterresult ;
  var $fktresult ;
  var $userresult ;
  var $resultcount ;

  var $redcopy2 ;
  var $dbtyp ;

  var $grundkatego;

  function set_katego_para ($table){

    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");
    $this->redcopy2  = $redcopy2 ;

    $identity = estab_auth_session_identity ($_SESSION);
    if ($identity === null) {
      throw new EstabCategoryAuthorizationException ("Anmeldung erforderlich.");
    }
    $this->categoryIdentity = $identity;
    $this->categoryScope = estab_category_scope ((string) $table, $identity, $conf_4f_tbl);
    $this->stab_fkt = $identity ["funktion"];
    $this->dbtyp = $this->categoryScope ["type"];
    $this->db_tablname = $this->categoryScope ["category_table"];
    $this->db_tablnamelk = $this->categoryScope ["link_table"];
    $this->db_tbl = str_ends_with ($this->db_tablname, "_katego")
      ? substr ($this->db_tablname, 0, -7)
      : $this->db_tablname;
    if ($this->dbtyp === "master") {
      $this->db_master_katego = $this->db_tablname;
    }

    $this->db_server   = $conf_4f_db ["server"];
    $this->db_benutzer = $conf_4f_db ["user"];
    $this->db_passwort = $conf_4f_db ["password"];
    $this->db_name     = $conf_4f_db ["datenbank"];
    $this->grundkatego = array (
          1 => array ("kategorie"    => "Alle",
                      "beschreibung" => "ohne Berücksichtigung der Kategorien"),
          2 => array ("kategorie"    => "ohne",
                      "beschreibung" => "Ohne Kategorie"));

    if (!$this->categoryConnection instanceof mysqli) {
      $this->categoryConnection = estab_auth_connect ($conf_4f_db);
    }
    // Preserve the historical public handle for callers outside this class.
    $this->db_hndl = $this->categoryConnection;
  }

  /** Preserve the one-based result arrays consumed by the legacy renderer. */
  function category_rows_one_based (){
    $rows = estab_category_fetch_all ($this->connection (), $this->categoryScope);
    $result = array ();
    foreach ($rows as $index => $row) {
      $result [$index + 1] = $row;
    }
    $this->resultcount = count ($result);
    return $result;
  }

  /***********************************************************************************

  ***********************************************************************************/
  function kategoliste (){
    if ($_SESSION["filter_darstellung"] == "1" ){
      include ("../4fcfg/config.inc.php");

      $this->set_katego_para ("master");   // MASTER
      $this->masterresult = $this->category_rows_one_based ();

      $this->set_katego_para ("fkt");     // FUNKTION
      $this->fktresult = $this->category_rows_one_based ();

      $this->set_katego_para ("user");     // USER
      $this->userresult = $this->category_rows_one_based ();

      $mastercount = COUNT($this->masterresult) ;
      $fktcount    = COUNT($this->fktresult) ;
      $usercount   = COUNT($this->userresult) ;

      if (isset ($_SESSION ['global_katego'])) {
        $kategoselected = $_SESSION ['global_katego'];
      }

        // MASTER KATEGORIE
      if ($mastercount != 0){
        echo "<div style=\"height:20px;
                           background-color:#FFC8C8;
                           border-top-color:#FFC8C8;
                           border-left-color:#FFC8C8;
                           border-right-color:#FFC8C8;
                           border-bottom-color:#000000;
                           border-width:2px;
                           border-style:solid;
                           padding-top:10px;
                           padding-bottom:0px;\">\n";
        if (!isset($_SESSION ['ma_katego'])){$color = "red";}else{$color = "lightred";}
            echo"<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
              "ma_ktgotyp" => "global",
              "ma_ktgo" => "alle",
            ))."\">
              <img src=\"".estab_list_category_icon ("ALLE", $color)."\"
                   alt=\"Alle\"
                   border=\"0\"
                   title=\"Alle\"></a>\n";

        for ($i=1; $i<= $mastercount; $i++) {
          if ( $this->masterresult[$i]["kategorie"] == "" ) {
            echo "<a><img src=\"null.gif\" alt=\"leer\"></a>\n";
          } else {
            if ( (($_SESSION ['ma_katego'] ?? "") == $this->masterresult[$i]["lfd"]) AND
                 (($_SESSION ['ma_kategotyp'] ?? "") == "global") ){$color = "red";}else{$color = "lightred";}
            echo"<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
              "ma_ktgotyp" => "global",
              "ma_ktgo" => $this->masterresult[$i]["lfd"],
            ))."\">
              <img src=\"".estab_list_category_icon ($this->masterresult[$i]["kategorie"], $color)."\"
                   alt=\"".estab_message_html ($this->masterresult[$i]["beschreibung"] ?? "")."\"
                   border=\"0\"
                   title=\"".estab_message_html ($this->masterresult[$i]["beschreibung"] ?? "")."\"></a>\n";
          }
        }
        echo "</div>";
      }

        // FUNKTIONS KATEGORIE
      if ($fktcount != 0){
        echo "<div style=\"height:20px;
                           background-color:#C8C8FF;
                           border-top-color:#C8C8FF;
                           border-left-color:#C8C8FF;
                           border-right-color:#C8C8FF;
                           border-bottom-color:#000000;
                           border-width:2px;
                           border-style:solid;
                           padding-top:10px;
                           padding-bottom:1px;
                           margin-bottom:0px;\">\n";
        if (!isset($_SESSION ['fk_katego'])){$color = "blue";}else{$color = "lightblue";}

        echo "<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
          "fk_ktgotyp" => "fkt",
          "fk_ktgo" => "alle",
        ))."\">";
        echo "<img src=\"".estab_list_category_icon ("ALLE", $color)."\"
                   alt=\"Alle\"
                   border=\"0\"
                   title=\"Alle\">"; //</a>";
        for ($i=1; $i<= $fktcount; $i++) {
          if ( $this->fktresult[$i]["kategorie"] == "" ) {
            echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
          } else {
            if ( (($_SESSION ['fk_katego'] ?? "") == $this->fktresult[$i]["lfd"]) AND
                 (($_SESSION ['fk_kategotyp'] ?? "") == "fkt" ) ){$color = "blue";}else{$color = "lightblue";}
              echo"<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
                "fk_ktgotyp" => "fkt",
                "fk_ktgo" => $this->fktresult[$i]["lfd"],
              ))."\">
              <img src=\"".estab_list_category_icon ($this->fktresult[$i]["kategorie"], $color)."\"
                   alt=\"".estab_message_html ($this->fktresult[$i]["beschreibung"] ?? "")."\"
                   border=\"0\"
                   title=\"".estab_message_html ($this->fktresult[$i]["beschreibung"] ?? "")."\"></a>";
          }
        }
        echo "</div>";   //echo "<p>";
      }

        // USER KATEGORIE
      if ($usercount != 0){
        echo "<div style=\"height:20px; background-color:#C8FFC8; border-top-color:#C8FFC8; border-left-color:#C8FFC8; border-right-color:#C8FFC8; border-bottom-color:#000000; border-width:2px; border-style:solid; padding-top:10px; padding-bottom:1px; margin-bottom:10px;\">\n";
        if (!isset($_SESSION ['us_katego'])){$color = "green";}else{$color = "lightgreen";}

        echo "<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
          "us_ktgotyp" => "user",
          "us_ktgo" => "alle",
        ))."\">";
        echo "<img src=\"".estab_list_category_icon ("ALLE", $color)."\"
                   alt=\"Alle\"
                   border=\"0\"
                   title=\"Alle\">"; //</a>";
        for ($i=1; $i<= $usercount; $i++) {
          if ( $this->userresult[$i]["kategorie"] == "" ) {
            echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
          } else {
            if ( (($_SESSION ['us_katego'] ?? "") == $this->userresult[$i]["lfd"]) AND
                 (($_SESSION ['us_kategotyp'] ?? "") == "user" ) ){$color = "green";}else{$color = "lightgreen";}
            echo"<a href=\"".estab_list_category_url ($conf_4f ["MainURL"], array (
              "us_ktgotyp" => "user",
              "us_ktgo" => $this->userresult[$i]["lfd"],
            ))."\">
              <img src=\"".estab_list_category_icon ($this->userresult[$i]["kategorie"], $color)."\"
                   alt=\"".estab_message_html ($this->userresult[$i]["beschreibung"] ?? "")."\"
                   border=\"0\"
                   title=\"".estab_message_html ($this->userresult[$i]["beschreibung"] ?? "")."\"></a>";
          }
        }
        echo "</div>";   //echo "<p>";
      }


    }
  }



  function get_list ($listenart){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $messageConnection = estab_message_connect ($conf_4f_db);
    try {
      $identity = estab_read_session_identity ($_SESSION);
      if ($identity === null) {
        throw new EstabReadPermissionException ("Anmeldung erforderlich.");
      }
      $scope = estab_read_require_operational_scope (
        $messageConnection,
        $identity
      );
      $identity = $scope ["identity"];
      $incidentId = (int) $scope ["incident"] ["active_einsatz_id"];

      $messageTable = estab_message_table (
        (string) $conf_4f_tbl ["nachrichten"]
      );
      $parameters = array ($incidentId);
      $where = array ("m.`einsatz_id` = ?");
      $joins = array ();
      $displayFilters =
        (int) ($_SESSION["filter_darstellung"] ?? 1) === 1;

      switch ($listenart) {
        case "Stab_lesen":
        case "global":
          // Before the terminal workflow state, only the author may inspect
          // their outgoing draft. Recipient copies use an exact token.
          $where[] = estab_message_staff_access_sql ("m");
          $parameters[] = estab_message_recipient_pattern (
            $identity ["funktion"]
          );
          $parameters[] = $identity ["funktion"];

          $prefix = (string) $conf_4f_tbl ["usrtblprefix"];
          $readTable = estab_message_table (estab_message_state_table (
            $prefix, $identity ["funktion"], $identity ["kuerzel"], "read"
          ));
          $doneTable = estab_message_table (estab_message_state_table (
            $prefix, $identity ["funktion"], $identity ["kuerzel"], "done"
          ));
          $functionBase =
            $prefix."_fkt_".strtolower ($identity ["funktion"]);
          $userBase = $prefix.strtolower ($identity ["funktion"]).
            "_".$identity ["kuerzel"];
          $searchActive = isset ($_SESSION["flt_search"]);
          if ($displayFilters && !$searchActive) {
            if ((int) ($_SESSION["filter_gelesen"] ?? 0) === 1) {
              $where[] =
                "m.`00_lfd` IN (SELECT `nachnum` FROM ".$readTable.")";
            }

            $showDone = (int) ($_SESSION["filter_erledigt"] ?? 0) === 1;
            $showOpen = (int) ($_SESSION["filter_unerledigt"] ?? 0) === 1;
            if ($showDone xor $showOpen) {
              $where[] = "m.`00_lfd` ".($showDone ? "" : "NOT ").
                "IN (SELECT `nachnum` FROM ".$doneTable.")";
            } elseif (!$showDone && !$showOpen) {
              $where[] = "1 = 0";
            }

            $categorySpecs = array (
              array ("ma_katego", $conf_4f_tbl ["masterkatego"], $conf_4f_tbl ["masterkategolk"], "ma"),
              array ("fk_katego", $functionBase."_katego", $functionBase."_kategolink", "fk"),
              array ("us_katego", $userBase."_katego", $userBase."_kategolink", "us"),
            );
            foreach ($categorySpecs as $spec) {
              if (!isset ($_SESSION[$spec[0]])) { continue; }
              $categoryId = estab_message_positive_id (
                $_SESSION[$spec[0]]
              );
              $categoryTable = estab_message_table ((string) $spec[1]);
              $linkTable = estab_message_table ((string) $spec[2]);
              $alias = $spec[3];
              $joins[] = "INNER JOIN ".$linkTable." AS ".$alias."l".
                " ON ".$alias."l.`msg` = m.`00_lfd`";
              $joins[] = "INNER JOIN ".$categoryTable." AS ".$alias."c".
                " ON ".$alias."c.`lfd` = ".$alias."l.`katego`";
              $where[] = $alias."c.`lfd` = ?";
              $parameters[] = $categoryId;
            }
          }

          if ($searchActive) {
            $searchPattern = "%".(string) $_SESSION["flt_search"]."%";
            $where[] =
              "(m.`04_nummer` LIKE ? OR m.`10_anschrift` LIKE ? OR ".
              "m.`12_abfzeit` LIKE ? OR m.`12_inhalt` LIKE ? OR ".
              "m.`13_abseinheit` LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
              $parameters[] = $searchPattern;
            }
          }
        break;

        case "FMADMIN":
        case "SIADMIN":
          if (isset ($_SESSION["flt_search"])) {
            $searchPattern = "%".(string) $_SESSION["flt_search"]."%";
            $where[] =
              "(m.`04_nummer` LIKE ? OR m.`10_anschrift` LIKE ? OR ".
              "m.`12_abfzeit` LIKE ? OR m.`12_inhalt` LIKE ? OR ".
              "m.`13_abseinheit` LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
              $parameters[] = $searchPattern;
            }
          }
        break;

        default:
          throw new InvalidArgumentException ("Unbekannte Listenart");
      }

      $from = " FROM ".$messageTable." AS m ".implode (" ", $joins);
      $whereSql = implode (" AND ", $where);
      $query = "SELECT DISTINCT m.*".$from." WHERE ".$whereSql.
        " ORDER BY ".
        estab_message_priority_order_sql ("m.`09_vorrangstufe`").
        " DESC, m.`04_nummer` DESC";
      $result = estab_message_query_rows (
        $messageConnection,
        $query,
        $parameters
      );
      $result = estab_read_filter_messages ($result, $identity);
      if ($displayFilters) {
        list ($start, $pageSize) = estab_list_page_window (count ($result));
        $result = array_slice ($result, $start, $pageSize);
      }
    } finally {
      estab_auth_close ($messageConnection);
    }
    return $result === array () ? "" : $result;
  }

/******************************************************************************\

\******************************************************************************/
  function createlist (){
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>".$this->listenart."<br>";  }
    switch ($this->listenart){
      case "LDF":
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`04_richtung`,`04_nummer`,`05_gegenstelle`,
                         `09_vorrangstufe`,`10_anschrift`,`12_abfzeit`,
                         `12_inhalt`,`13_abseinheit`
                    FROM `".$conf_4f_tbl ["nachrichten"]."`
                   WHERE `einsatz_id` = (SELECT `active_einsatz_id` FROM `nv_einsatz_status` WHERE `singleton_id` = 1)
                     AND `x00_status` = 1
                     AND `02_zeit` IS NULL AND `02_zeichen` = \"\"
                     AND `03_datum` IS NULL AND `03_zeichen` = \"\"
                     AND (
                       (`04_richtung` = \"E\"
                         AND `15_quitdatum` IS NULL
                         AND `15_quitzeichen` = \"\")
                       OR
                       (`04_richtung` = \"A\"
                         AND `15_quitdatum` IS NOT NULL
                         AND `15_quitzeichen` != \"\")
                     )
                     AND `x01_abschluss` = \"f\"
                ORDER BY ".
                estab_message_priority_order_sql ("`09_vorrangstufe`").
                " DESC, `12_abfzeit`;";
        $result = $dbaccess->query_table ($query);
        echo "<p align=\"center\"><big><big><b>LdF: Rufnamen und Beförderungswege</b></big></big></p>";
        if ($result != "") {
          echo "<table style=\"text-align:center;background-color:#fff\" border=\"1\" cellpadding=\"8\" cellspacing=\"1\"><tbody>";
          echo "<tr style=\"background-color:#000;color:#fff;font-weight:bold\">";
          echo "<th>E/A</th><th>Zeit</th><th>Vorrang</th><th>Rufname</th><th>Von/An</th><th>Inhalt</th></tr>";
          foreach ($result as $row) {
            $abfzeit = convdatetimeto ($row ["12_abfzeit"]);
            $direction = (string) $row ["04_richtung"];
            $address = $direction === "E"
              ? ((string) $row ["13_abseinheit"] !== ""
                ? $row ["13_abseinheit"]
                : "Absender zu übersetzen")
              : $row ["10_anschrift"];
            echo "<tr>";
            echo "<td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $direction);
            echo "</td><td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $abfzeit ["stak"] ?? "");
            echo "</td><td>";
            estab_list_detail_action (
              "ldf",
              "meldung",
              $row ["00_lfd"],
              estab_message_priority_label ($row ["09_vorrangstufe"])
            );
            echo "</td><td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $row ["05_gegenstelle"] ?: "noch offen");
            echo "</td><td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $address);
            echo "</td><td style=\"text-align:left\">";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $row ["12_inhalt"]);
            echo "</td></tr>";
          }
          echo "</tbody></table>";
        } else {
          echo "<big><big>Zur Zeit keine Nachricht für die LdF</big></big>";
        }
      break;

      case "FMA":           /***** F M A ****/
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - fkt:createlist - switch(listenart) -- case (FMA)<br>";} 	  
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`07_durchspruch`, `08_befhinweis`, `08_befhinwausw`,`09_vorrangstufe`, `10_anschrift`, `12_abfzeit`, `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE `einsatz_id` = (SELECT `active_einsatz_id` FROM `nv_einsatz_status` WHERE `singleton_id` = 1)
                  AND `x00_status` = 2
                  AND `02_zeit` IS NOT NULL AND `02_zeichen` != \"\"
                  AND `06_befwegausw` != \"\"
                  AND `15_quitdatum` IS NOT NULL
                  AND `15_quitzeichen` != \"\"
                  AND ((`04_richtung` = \"A\") AND (`03_datum` IS NULL) AND (`03_zeichen` = \"\")) order by ".
                  estab_message_priority_order_sql ("`09_vorrangstufe`").
                  " DESC, `12_abfzeit` ; ";
        $result = $dbaccess->query_table ($query);
        if ($result != "" ){
          echo "<table style=\"text-align: center; background-color: rgb(255, 255, 255); \" border=\"1\" cellpadding=\"10\" cellspacing=\"1\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(0,0,0); color:#FFFFFF; font-weight:bold;\">\n";
          echo "<td>ZEIT</td>\n";
          echo "<td>Vorst</td>\n";
          echo "<td>Anschr</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          foreach ($result as $row){
            $priorityStyle = estab_message_priority_requires_attention (
              $row["09_vorrangstufe"]
            )
                ? " style=\"background-color: rgb(255,255,100); color:#000000; font-weight:bold;\""
                : "";
            echo "<tr".$priorityStyle.">\n";
            $abfzeit = convdatetimeto ($row["12_abfzeit"]);
            echo "<td>"; if (($row["12_abfzeit"] != "")) { estab_list_detail_action ("fm", "meldung", $row["00_lfd"], $abfzeit["stak"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
            echo "<td>";
            estab_list_detail_action (
              "fm",
              "meldung",
              $row["00_lfd"],
              estab_message_priority_label ($row["09_vorrangstufe"])
            );
            echo "</td>\n";
            echo "<td>"; if (($row["10_anschrift"] != "")) { estab_list_detail_action ("fm", "meldung", $row["00_lfd"], $row["10_anschrift"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
            echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { estab_list_detail_action ("fm", "meldung", $row["00_lfd"], $row["12_inhalt"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";           echo "</tr>";
          }
          echo "</tbody></table>";
        } else {// if $result != ""
          echo "<big><big><big>Zur Zeit keine Meldung im Ausgang</big></big></big><br>";
        }
      break;
      case "Stab_lesen":  // ******  S T A B    l e s e n *****
        if (debug) {echo "<b>file:liste.php:749 fkt:createlist - switch(listenart) -- case (Stab_lesen) ></b><br>";}
        /*
          Hole die Liste der gelesenen und der erledigten Nachrichten
        */
        $result = $this->get_list ("global");
        $this->darstellungs_art ( $this->listenart );
        $dbschongelesen = list_of_readed_msg () ;
        $dbschonerledigt = list_of_done_msg () ;
        $this->kategoliste ();
        $this->listen_navi () ;  //Navigationsbutton
        if  ($result != "") {
          echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
            // gelesen ?
          echo "<th align=\"center\">";
          echo "<p><img src=\"".$conf_design_path."/info.gif\" alt=\"Vorrang/gelesen\"></p>";
          echo "</th>\n";
            // erledigt
          echo "<th align=\"center\">";
          echo "<p><img src=\"".$conf_design_path."/checked.gif\" alt=\"gepr&uuml;ft/erledigt\"></p>";
          echo "</th>\n";
            // Transport
          echo "<th align=\"center\">";
          echo "<p><img src=\"".$conf_design_path."/transport.gif\" alt=\"Transportstatus\"></p>";
          echo "</th>\n";
          echo "<th>Vorrang</th>\n";
          echo "<th>E/A</th>\n";
          echo "<th>Num</th>\n";
          echo "<th>Von</th>";
          echo "<th>An</th>";
          echo "<th>Abfasszeit</th>\n";
          echo "<th>Inhalt</th>\n";
          echo "</tr>";
          // zeilenweise Anzeige der Datenbankanfrage
          foreach ($result as $row){
             $hilf = $this->explodereceiver ( $row ["16_empf"] );
             $receivercolor = $hilf [ $_SESSION ['vStab_funktion'] ] ?? ""; // Empfaenger dieser Zeile
             switch ($receivercolor){
               case "rt":  $receiverbackground = $cfg ["lbg"] ["rt"];   break;
               case "gn":  $receiverbackground = $cfg ["lbg"] ["gn"];   break;
               case "bl":  $receiverbackground = $cfg ["lbg"] ["bl"];   break;
               case "ge":  $receiverbackground = $cfg ["lbg"] ["ge"];   break;
               default:    $receiverbackground = $cfg ["lbg"] ["dflt"];
             }
             echo "<tr style=\"background-color: ".$receiverbackground."; color:#FFFFFF; font-weight:bold;\">\n";
             // Liegt eine Vorrangstufe vor!!!
             $vorrang = estab_message_priority_requires_attention (
               $row["09_vorrangstufe"]
             );
             // 1. Spalte schon gelesen?
             $schongelesen = false;
             if ($dbschongelesen != "") {
               if ( in_array ( $row["00_lfd"], $dbschongelesen ) ) {
                 $schongelesen = true;
               }
             }

             if ( $vorrang ){
               if ( $schongelesen ){
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "unset", $conf_design_path."/000.gif", "lesen");
                 echo "</td>\n";
               } else {
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "set", $conf_design_path."/mail_prio_unread.gif", "lesen");
                 echo "</td>\n";
               }
             } else {
               if ( $schongelesen ){  // ==> wurde schon gelesen
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "unset", $conf_design_path."/000.gif", "lesen");
                 echo "</td>\n";
               } else {
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "set", $conf_design_path."/mail_unread.gif", "Neu/new");
                 echo "</td>\n";
               }
             }

             $schonerledigt = false;

             if ($dbschonerledigt != "") {
               if ( in_array ( $row["00_lfd"], $dbschonerledigt ) ) {
                 $schonerledigt = true;
               }
             }


             if ( $schonerledigt ){  // ==> wurde schon erledigt
                 echo "<td style=\"text-align: center; vertical-align: middle;\">";
                 estab_list_state_action ("erledigt", $row ["00_lfd"], "unset", $conf_design_path."/task_done.gif", "erledigt");
                 echo "</td>\n";
             } else {
                 echo "<td style=\"text-align: center; vertical-align: middle;\">";
                 estab_list_state_action ("erledigt", $row ["00_lfd"], "set", $conf_design_path."/task_due.gif", "NICHT erledigt");
                 echo "</td>\n";
             }

               // Status für ausgehende Nachrichten
             echo "<td align=\"center\">";
             if ( ( $row["04_richtung"] == "A") ){
               switch ( $row["x00_status"] ) {
                 case 1:
                   echo "<p><img src=\"".$conf_design_path."/status_yellow.gif\" alt=\"liegt bei LdF: Rufname und Beförderungsweg festlegen\"></p>";
                 break;
                 case 2: // liegt vor dem Fernmelder
                   echo "<p><img src=\"".$conf_design_path."/status_yellow.gif\" alt=\"liegt vorm Fernmelder\"></p>";
                 break;
                 case 4: // liegt vor dem Sichter ==> gelb
                   echo "<p><img src=\"".$conf_design_path."/status_red.gif\" alt=\"liegt vorm Sichter\"></p>";
                 break;
                 case 8: // fertig == gruen
                   echo "<p><img src=\"".$conf_design_path."/status_green.gif\" alt=\"Transport abgeschlossen!\"></p>";
                 break;
                 default:
                   echo "<p><img src=\"".$conf_design_path."/null.gif\" alt=\"fremd\"></p>";
                 break;
               }
             } else { // ist ein Eingang, damit eine NULL nummer
               echo "<p><img src=\"".$conf_design_path."/null.gif\" alt=\"fremd\"></p>";
             }
             echo "</td>\n";

             echo "<td>";
              // Vorrangstufe
             estab_list_detail_action (
               "stab",
               "meldung",
               $row["00_lfd"],
               estab_message_priority_label ($row["09_vorrangstufe"])
             );
             echo "</td>\n";
              // Eingang / Ausgang
             echo "<td>"; if (($row["04_richtung"] != "")) { estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["04_richtung"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
              // Nachweisnummer
             echo "<td>"; if (($row["04_nummer"] != "")) { estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["04_nummer"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
              // Muss der Absender oder die Absendende Einheit unter von / an
             if ($row["04_richtung"] == "A" ) { // von = 14_funktion an=10_anschrift
                // Ausgang VON
               echo "<td>";
               if (($row["14_funktion"] != "")) {
                 estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["14_funktion"]);
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
               echo "</td>\n";

                // Ausgang AN
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["10_anschrift"]);
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
               echo "</td>\n";

             }

             if ($row["04_richtung"] == "E" ) {  // von = 13_abseinheit/14_funktion an=10_anschrift
               echo "<td>";
               if ( ($row["13_abseinheit"] != "") ) {
                 estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["13_abseinheit"], true);
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";


               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["10_anschrift"], true);
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             if (($row["12_abfzeit"] != "")) {
               $arr    = convdatetimeto ($row["12_abfzeit"]);
               $abzeit = $arr ["stak"];
               estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $abzeit, true);
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["12_inhalt"], true);
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
             echo "</tr>";
          }  // foreach $result
        }
        echo "</tbody></table>";
        $this->listen_navi () ;  //Navigationsbutton
      break;

      case "Stab_sichten":   /*********** S t a b   s i c h t e n ************/
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>fkt:createlist - switch(listenart) -- case (Stab_sichten) </b><br>";}
			$dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );

			/*
			 * Status 4 ist die verbindliche Sichter-Warteschlange:
			 * Eingang nach der LdF-Disposition, Ausgang vor LdF und A/W.
			 * Ausgehende Vordrucke dürfen nicht mehr per Konfiguration an
			 * dieser formalen Prüfung vorbeigeführt werden.
			 */
			$WHERE_inout = "WHERE `einsatz_id` = (SELECT `active_einsatz_id` FROM `nv_einsatz_status` WHERE `singleton_id` = 1) AND `x00_status` = 4 AND ( ( `15_quitdatum` IS NULL ) AND ( `15_quitzeichen` = \"\" ) ) AND ( `04_richtung` IN (\"E\", \"A\") )";

//order by `09_vorrangstufe` DESC, `12_abfzeit`; 

			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### Ein- und Ausgänge sichten<br>"; }
			$query = "SELECT `00_lfd`,`07_durchspruch`,
                         `08_befhinweis`,
                         `08_befhinwausw`,
                         `09_vorrangstufe`,
                         `10_anschrift`,
                         `12_abfzeit`,
                         `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."` ".$WHERE_inout."
                       order by ".
                       estab_message_priority_order_sql ("`09_vorrangstufe`").
                       " DESC, `12_abfzeit`; ";
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>Stab_sichten:".$query." </b><br>";}
        $result = $dbaccess->query_table ($query);
        if ($result != "" ){
          echo "<table style=\"text-align: center; background-color: rgb(255, 255, 255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
          echo "<td>ZEIT</td>\n";
          echo "<td>Vorst</td>\n";
          echo "<td>Anschrift</td>\n";
          echo "<td>Inhalt / Text</td>\n";
          echo "</tr>";
          foreach ($result as $row){
           $priorityStyle = estab_message_priority_requires_attention (
             $row["09_vorrangstufe"]
           )
               ? " style=\"background-color: rgb(220,0,0); color:#FFFFFF; font-weight:bold;\""
               : "";
           echo "<tr".$priorityStyle.">\n";
           $abfzeit = convdatetimeto ($row["12_abfzeit"]);
           echo "<td>"; if (($row["12_abfzeit"] != "")) { estab_list_detail_action ("sichter", "meldung", $row["00_lfd"], $abfzeit["stak"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td>";
           estab_list_detail_action (
             "sichter",
             "meldung",
             $row["00_lfd"],
             estab_message_priority_label ($row["09_vorrangstufe"])
           );
           echo "</td>\n";
           echo "<td>"; if (($row["10_anschrift"] != "")) { estab_list_detail_action ("sichter", "meldung", $row["00_lfd"], $row["10_anschrift"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { estab_list_detail_action ("sichter", "meldung", $row["00_lfd"], $row["12_inhalt"]); } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "</tr>";
          } // foreach result row
          echo "</tbody></table>";
        } else {// if isset $result
          echo "<big><big><big>Zur Zeit keine Meldung zu sichten</big></big></big>";
        }
		
		
      break;

      /*************************************************************************\
               SSSSS III  AAA  DDDD  M   M III N   N
               S      I  A   A D   D MM MM  I  NN  N
               SSSSS  I  AAAAA D   D M M M  I  N N N
                   S  I  A   A D   D M   M  I  N  NN
               SSSSS III A   A DDDD  M   M III N   N
      \*************************************************************************/
      case "SIADMIN":  // ***************  SICHTER ADMINISTRATOR  *********************
      case "FMADMIN":
	    if (debug) {echo "<b>file:liste.php:999 fkt:createlist - switch(listenart) -- case (SIADMIN & FMADMIN) ></b><br>";}
include ("../4fcfg/fkt_rolle.inc.php");

        /*
          Hole die Liste der gelesenen und der erledigten Nachrichten
        */
        $result = $this->get_list ($this->listenart);
        $adminMessageRoute = $this->listenart == "FMADMIN"
          ? "FM-Adminmeldung"
          : "SI-Adminmeldung";
        $this->darstellungs_art ( $this->listenart );
        $this->listen_navi () ;  //Navigationsbutton
		
/*******************************************************************************************************************************/
        if  ($result != ""){
          echo "<table style=\"text-align: center; background-color: rgb(250,250, 250); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:fm=meldung&0000FF; font-weight:bold;\">\n";
          echo "<td>Vorst</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Nw-Nr.</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          // Funktionen und Farben
          for ( $i=1; $i<= count ($conf_empf); $i++ ) {
            if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
              echo "<td>";
              echo $conf_empf [$i]["fkt"];
              echo "</td>\n";
            }
          }
          echo "<td>Inhalt</td>\n";
          echo "</tr>";

          foreach ($result as $row){
             // VORRANGSTUFE
             $priorityStyle = estab_message_priority_requires_attention (
               $row["09_vorrangstufe"]
             )
                 ? " style=\"background-color: rgb(255,255,0); color:#000000; font-weight:bold;\""
                 : "";
             echo "<tr".$priorityStyle.">\n";
             echo "<td>";
             estab_list_detail_action (
               "fm",
               $adminMessageRoute,
               $row["00_lfd"],
               estab_message_priority_label ($row["09_vorrangstufe"])
             );
             echo "</td>\n";

             // RICHTUNG Eingang / Ausgang
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               estab_list_detail_action ("fm", $adminMessageRoute, $row["00_lfd"], $row["04_richtung"]);
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // N a c h w e i s n u m m e r
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               estab_list_detail_action ("fm", $adminMessageRoute, $row["00_lfd"], $row["04_nummer"]);
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             if ($row["04_richtung"] == "A" ) {
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 estab_list_detail_action ("fm", $adminMessageRoute, $row["00_lfd"], $row["10_anschrift"]);
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             } else {
               echo "<td>";

             // Absender / Einheit / Stelle / ...
             if (($row["13_abseinheit"] != "")) {
               estab_list_detail_action ("fm", $adminMessageRoute, $row["00_lfd"], $row["13_abseinheit"]);
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             // Abfassungs Z E I T
             if (($row["12_abfzeit"] != "")) {
               $abfzeit = convdatetimeto ($row["12_abfzeit"]);
               estab_list_detail_action ("fm", $adminMessageRoute, $row["00_lfd"], $abfzeit["stak"]);
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
 
             // Funktionen und Farben
             $empfcolor = extraiereempfaenger ( $row ["16_empf"] ) ;
             for ( $i=1; $i<= count ($conf_empf); $i++ ) {
               if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
                 $recipientFunction = $conf_empf [$i]['fkt'];
                 switch ($empfcolor [$recipientFunction] ?? '') {
                  case "rt":
                   echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["rt"]."; \">";
                   echo "X";
                  break;
                  case "gn":
                   echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["gn"]."; \">";
                   echo "X";
                  break;
                  case "bl":
                   echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["bl"]."; \">";
                   echo "X";
                  break;
                  default:
                   echo "<td style=\"text-align: center; background-color: rgb(250, 250, 250); \">";
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
                 }
                 echo "</td>";
               }
             }

             // I N H A L T !
             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               estab_list_detail_action (
                 "fm",
                 $adminMessageRoute,
                 $row["00_lfd"],
                 estab_message_excerpt ($row["12_inhalt"], (int) $conf_4f_liste ["inhalt"])." ..."
               );
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
             echo "</tr>";
          }
        }
        echo "</tbody></table>";
/*********************************************************************************************************************************/		

        $this->listen_navi () ;  //Navigationsbutton		
		
		
/******************************************************************************************************************************************
        $this->darstellungs_art ( $this->listenart );
		$this->listen_navi () ;  //Navigationsbutton
        include ("../4fcfg/fkt_rolle.inc.php");
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,
                         `04_richtung`,
                         `04_nummer`,
                         `09_vorrangstufe`,
                         `10_anschrift`,
                         `12_abfzeit`,
                         `13_abseinheit`,
                         `12_inhalt`,
                         `16_empf`
                   FROM `".$conf_4f_tbl ["nachrichten"]."`
                               WHERE 1 order by 04_nummer DESC, 09_vorrangstufe DESC ; ";          //

        $result = $dbaccess->query_table ($query);
        if  ($result != ""){
          echo "<table style=\"text-align: center; background-color: rgb(250,250, 250); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:fm=meldung&0000FF; font-weight:bold;\">\n";
          echo "<td>Vorst</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Nw-Nr.</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          // Funktionen und Farben
          for ( $i=1; $i<= count ($conf_empf); $i++ ) {
            if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
              echo "<td>";
              echo $conf_empf [$i]["fkt"];
              echo "</td>\n";
            }
          }
          echo "<td>Inhalt</td>\n";
          echo "</tr>";

          foreach ($result as $row){
             // VORRANGSTUFE
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ){
               echo "<tr style=\"background-color: rgb(255,255,0); color:fm=meldung&FFFFFF; font-weight:bold;\">\n";
             }
             echo "<td>";
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ;
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // RICHTUNG Eingang / Ausgang
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_richtung"]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // N a c h w e i s n u m m e r
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_nummer"]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             if ($row["04_richtung"] == "A" ) {
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             } else {
               echo "<td>";

             // Absender / Einheit / Stelle / ...
             if (($row["13_abseinheit"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["13_abseinheit"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             // Abfassungs Z E I T
             if (($row["12_abfzeit"] != "")) {
               $abfzeit = convdatetimeto ($row["12_abfzeit"]);
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit['stak']."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // Funktionen und Farben
             $empfcolor = extraiereempfaenger ( $row ["16_empf"] ) ;
             for ( $i=1; $i<= count ($conf_empf); $i++ ) {
               if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
                 $recipientFunction = $conf_empf [$i]['fkt'];
                 switch ($empfcolor [$recipientFunction] ?? '') {
                  case "rt":
                   echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["rt"]."; \">";
                   echo "X";
                  break;
                  case "gn":
                   echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["gn"]."; \">";
                   echo "X";
                  break;
                  case "bl":
                   echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["bl"]."; \">";
                   echo "X";
                  break;
                  default:
                   echo "<td style=\"text-align: center; background-color: rgb(250, 250, 250); \">";
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
                 }
                 echo "</td>";
               }
             }

             // I N H A L T !
             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".
                        $row["00_lfd"]."\" target=\"_self\">".
                        substr($row["12_inhalt"], 0, $conf_4f_liste ["inhalt"])." ..."."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
             echo "</tr>";
          }
        }
        echo "</tbody></table>";
************************************************************************************************************************************/

      break; // case SIADMIN

      case "FmNwE":  // *****  F M N W E ingang ******
	    if (debug) {echo "<b>file:liste.php:714 fkt:createlist - switch(listenart) -- case (FmNwE) ></b><br>";}
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`01_medium`,`09_vorrangstufe`,`04_richtung`, `04_nummer`, `10_anschrift`,
                         `12_abfzeit`, `12_inhalt`, `13_abseinheit`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE `einsatz_id` = (SELECT `active_einsatz_id` FROM `nv_einsatz_status` WHERE `singleton_id` = 1)
                  AND 04_richtung = \"E\" order by 04_nummer ASC ; ";
        $result = $dbaccess->query_table ($query);
        echo "<p align=\"center\"><big><big><big><b>Nachweisung Eingang</b></big></big></big></p>";
        if ( $result != "" ){
          echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Num</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Eingangsmedium</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ( $result != "" ) {
            foreach ($result as $row){
               echo "<tr>\n";
               echo "<td>";
               echo "<a>".estab_message_html (
                 estab_message_priority_label ($row["09_vorrangstufe"])
               )."</a>\n" ;
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".estab_message_html ($row["04_richtung"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".estab_message_html ($row["04_nummer"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".estab_message_html ($row["10_anschrift"])."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".estab_message_html ($row["13_abseinheit"])."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td>";
               $incomingMedium = estab_message_medium_text ($row["01_medium"] ?? "");
               if ($incomingMedium !== "") {
                 echo "<a>".estab_message_html ($incomingMedium)."</a>\n";
               } else {
                 echo "Nicht dokumentiert";
               }
               echo "</td>\n";
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".estab_message_html ($row["12_inhalt"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          } // if 2. result == ""
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

      case "FmNwA":  // *****  F M N W A usgang ******
        if (debug) {echo "<b>file:liste.php:714 fkt:createlist - switch(listenart) -- case (FmNwA) ></b><br>";}
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`03_datum`,`06_befweg`,`06_befwegausw`,
                         `09_vorrangstufe`,`04_richtung`, `04_nummer`, `10_anschrift`,
                         `12_abfzeit`, `12_inhalt`, `13_abseinheit`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE `einsatz_id` = (SELECT `active_einsatz_id` FROM `nv_einsatz_status` WHERE `singleton_id` = 1)
                  AND 04_richtung = \"A\" order by 04_nummer ASC ; ";
        $result = $dbaccess->query_table ($query);
        echo "<p align=\"center\"><big><big><big><b>Nachweisung Ausgang</b></big></big></big></p>";
        if ( $result != "" ){
          echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Num</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Beförderungsweg</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ($result != "") {
            foreach ($result as $row){
               echo "<tr>\n";
               echo "<td>";
               echo "<a>".estab_message_html (
                 estab_message_priority_label ($row["09_vorrangstufe"])
               )."</a>\n" ;
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".estab_message_html ($row["04_richtung"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".estab_message_html ($row["04_nummer"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".estab_message_html ($row["10_anschrift"])."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".estab_message_html ($row["13_abseinheit"])."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td>";
               if (estab_datetime_is_unset ($row["03_datum"] ?? null)) {
                 echo "Noch nicht befördert";
               } else {
                 $transportPath = estab_message_transport_text (
                   $row["06_befwegausw"] ?? "",
                   $row["06_befweg"] ?? ""
                 );
                 echo $transportPath !== ""
                   ? estab_message_html ($transportPath)
                   : "Nicht dokumentiert";
               }
               echo "</td>\n";
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".estab_message_html ($row["12_inhalt"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          }
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

      case "FmNw":  // *****  F M N W  ******
        if (debug) {echo "<b>file:liste.php:714 fkt:createlist - switch(listenart) -- case (FmNw) ></b><br>";}
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`01_medium`,`01_datum`,`01_zeichen`,`02_zeit`,`03_datum`,
                         `06_befweg`,`06_befwegausw`, `09_vorrangstufe`,`04_richtung`,
                         `04_nummer`, `10_anschrift`, `12_inhalt`, `13_abseinheit`,`14_zeichen`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE `einsatz_id` = (SELECT `active_einsatz_id` FROM `nv_einsatz_status` WHERE `singleton_id` = 1)
                  order by 04_nummer ASC ; ";
        $result = $dbaccess->query_table ($query);
        echo "<p align=\"center\"><big><big><big><b>Einsatz ".$conf_4f_db ["datenbank"]." - ". $conf_4f ["anschrift"]. "</big><br>Nachweisung Eingang / Ausgang</b></big></big></p>";
        if ( $result != "" ){
          echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Num</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Aufnahme</td>\n";
//          echo "<td>Aufn.Z</td>\n";
          echo "<td>Annahme</td>\n";
          echo "<td>Beförderzeit</td>\n";
          echo "<td>Übermittlungsweg</td>\n";
//          echo "<td>Verfasser</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ($result != "") {
            foreach ($result as $row){
               echo "<tr>\n";
               echo "<td>";
               echo "<a>".estab_message_html (
                 estab_message_priority_label ($row["09_vorrangstufe"])
               )."</a>\n" ;
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".estab_message_html ($row["04_richtung"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".estab_message_html ($row["04_nummer"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".estab_message_html ($row["10_anschrift"])."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".estab_message_html ($row["13_abseinheit"])."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }


               echo "<td>";
               if (!estab_datetime_is_unset ($row["01_datum"])) {
                 $arr    = convdatetimeto ($row["01_datum"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>";
// Aufn. Zeichen
                 // Aufn. Zeichen
/*              echo "<td>";
               if (($row["01_zeichen"] != "")) {
                 echo "<a>".$row["01_zeichen"]."</a>\n"; }
               else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
*/
/*
               echo "<td>";
               if (($row["01_zeichen"] != "")) {
                 echo "<a>".$row["01_zeichen"]."</a>\n"; }
               else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
*/
               echo "<td>";
               if (!estab_datetime_is_unset ($row["02_zeit"])) {
                 $arr    = convdatetimeto ($row["02_zeit"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>";

               echo "<td>";
               if (!estab_datetime_is_unset ($row["03_datum"])) {
                 $arr    = convdatetimeto ($row["03_datum"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td>";
               if (($row["04_richtung"] ?? "") === "E") {
                 $trackingPath = estab_message_medium_text ($row["01_medium"] ?? "");
                 echo $trackingPath !== ""
                   ? estab_message_html ($trackingPath)
                   : "Nicht dokumentiert";
               } elseif (estab_datetime_is_unset ($row["03_datum"] ?? null)) {
                 echo "Noch nicht befördert";
               } else {
                 $trackingPath = estab_message_transport_text (
                   $row["06_befwegausw"] ?? "",
                   $row["06_befweg"] ?? ""
                 );
                 echo $trackingPath !== ""
                   ? estab_message_html ($trackingPath)
                   : "Nicht dokumentiert";
               }
               echo "</td>\n";
                 // Verfasser
/*               echo "<td>";
               if (($row["14_zeichen"] != "")) {
                 echo "<a>".$row["14_zeichen"]."</a>\n"; }
               else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
*/
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".estab_message_html ($row["12_inhalt"])."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          }
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;
    } // switch
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - fkt:createlist - switch_ENDE(listenart) ></b><br>";}  
  }
} // class

?>
