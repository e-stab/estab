<?php

include ("katego.php");
/*****************************************************************************\
   Datei: liste.php

   benötigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

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

/******************************************************************************\

\******************************************************************************/
  function explodereceiver ( $empf) {
    $receiver = explode (",",$empf);
    for ($i=0; $i < count( $receiver ); $i++ ) {
      $hilfeaus = explode ( "_", $receiver [$i] ) ;
      $fktcopycolor[$hilfeaus[0]] = $hilfeaus [1] ;
    }
    return $fktcopycolor;
  }



/******************************************************************************\

\******************************************************************************/
  function listen ($welche, $user){
    $this->listenart = $welche;
    $this->benutzer  = $user;
    $this->flt_status        = $_SESSION["filter_darstellung"] ;
    $this->flt_msg_pro_seite = $_SESSION["filter_anzahl"] ;
    $this->flt_start_msg     = $_SESSION["startmit"];
    $this->flt_gelesen       = $_SESSION["gelesene"] ;
    $this->flt_erledigt      = $_SESSION["erledigte"] ;
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
      break;

      /*************************************************************************\
        SSSSS  TTTTT   AAA  BBBB   l
        S        T    A   A B   B  l
        SSSSS    T    AAAAA BBBBB  l esen
            S    T    A   A B   B  l
        SSSSS    T    A   A BBBB   l
      \*************************************************************************/
      case "Stab_lesen":  // ******  S T A B    l e s e n *****
          if ( debug ) { echo "\n\n\n<!-- ANFANG file:liste.php fkt:darstellungsart -->"; }

          echo "\n<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
          echo "<table><tbody>";
          echo "<tr>";
          echo "<td>";
          echo "<big><b>".($_SESSION[filter_start]+1)."|".($_SESSION[filter_start]+$_SESSION[filter_anzahl])."|<big>".($_SESSION["filter_rescount"])."</big></b></big>";
          echo "</td>";
          echo "<td>";
          echo "Meldung/Seite:<br>\n";

            // Voreinstellung für die Meldungen pro Seite
          if ( !(isset ($_SESSION["filter_anzahl"])) OR
              ( $_SESSION["filter_anzahl"] == "" )
           ){$_SESSION["filter_anzahl"] = 5; }

          echo "<table border=\"0\" ><tbody>";
          echo "<tr>";

          echo "<td>";
          echo "<div  style=\"border-top-color:#DCDCFF; border-left-color:#DCDCFF; border-right-color:#DCDCFF; border-bottom-color:#000000; border-width:1px; border-style:solid; padding:0px\">";
            for ($pps=5; $pps <=25; $pps+=5){
              if ( $_SESSION["filter_anzahl"] == $pps )  {
                echo "<a href=\"".$conf_4f ["MainURL"]."?filter_anzahl_x=1&filter_anzahl=".$pps."\"><img src=\"button.php?type=icon&status=AUS&text=".$pps."&bg=blue\" border=\"0\" alt=\"Anzahl".$pps."EIN\"></a>";
              } else {
                echo "<a href=\"".$conf_4f ["MainURL"]."?filter_anzahl_x=1&filter_anzahl=".$pps."\"><img src=\"button.php?type=icon&status=EIN&text=".$pps."&bg=lighterblue\" border=\"0\" alt=\"Anzahl".$pps."AUS\"></a>";
              }
            }
          echo "</div>";
          echo "</td>";
          echo "</tr>";
          echo "</tbody></table>";
          echo "</td>";
          echo "<td>";
          if ($_SESSION [filter_unerledigt] == 0)  {
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
          if ($_SESSION [filter_erledigt] == 0)  {
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
          if ($_SESSION [flt_find_mask] == 0)  {
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
          echo "\n<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
          echo "<td>";
          if (isset ($_SESSION ["flt_search"]) ) { $defvalue = $_SESSION ["flt_search"] ;}
          else {$defvalue = "";}
          echo "<div>";
          echo "<p>Suchbegriff: <input name=\"flt_search\" value=\"".$defvalue."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
          echo "<div>";
          echo "</td>";
          echo "<td>";
          echo "<input name=\"filter_suche\" value=\"suchen\" type=\"submit\">\n";
          echo "</td>";
          echo "</div>";
        }
        echo "</tr>";
        echo "</tbody></table>";
        echo "</td>";
        echo "</tr>";
        echo "</tbody></table>";
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
        if ( debug ) { echo "\n\n\n<!--  210 file:liste.php fkt:darstellungsart -->"; }
        echo "\n<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
        echo "<table><tbody>";
        echo "<tr>";
        echo "<td>";
        if ( !(isset ($_SESSION["filter_anzahl"])) OR
              ( $_SESSION["filter_anzahl"] == "" )
           ){$_SESSION["filter_anzahl"] = 5;}
        if ($_SESSION [filter_darstellung] == 0)  {
          echo "<input name=\"filter_darstellung\" type=\"checkbox\">\n";
          echo "filtern" ;
        } else {
          echo "<input name=\"filter_darstellung\" type=\"checkbox\" checked=\"checked\">\n";

          echo "filtern" ;
          echo "</td>";
          echo "<td>";
          echo "<big><b>".($_SESSION[filter_start]+1)."|".($_SESSION[filter_start]+$_SESSION[filter_anzahl])."|<big>".($_SESSION["filter_rescount"])."</big></b></big>";
          echo "</td>";
          echo "<td>";
          echo "Meldung/Seite:\n";
          echo "</td>";
          echo "<td>";
          echo "<select size=\"1\" name=\"filter_anzahl\">";

          if ( ( $_SESSION["filter_anzahl"] == "5"))
            {$sel = "selected";} else {$sel = "";}
          echo "<option $sel>5</option>";

          if ( ( $_SESSION["filter_anzahl"] == "10"))
            {$sel = "selected";} else {$sel = "";}
          echo "<option $sel>10</option>";

          if ( ( $_SESSION["filter_anzahl"] == "15"))
            {$sel = "selected";} else {$sel = "";}
          echo "<option $sel>15</option>";

          if ( ( $_SESSION["filter_anzahl"] == "20"))
            {$sel = "selected";} else {$sel = "";}
          echo "<option $sel>20</option>";

          if ( ( $_SESSION["filter_anzahl"] == "25"))
            {$sel = "selected";} else {$sel = "";}
          echo "<option $sel>25</option>";
          echo "</select>";
          echo "</td>";

          echo "<td>";
          echo "<input type=\"image\" name=\"flt_start\" src=\"".$conf_design_path."/go_start.gif\" alt=\"Anfang\">\n";
          echo "<input type=\"image\" name=\"flt_back\" src=\"".$conf_design_path."/go_back.gif\" alt=\"zurueck\">\n";
          echo "<input type=\"image\" name=\"flt_for\" src=\"".$conf_design_path."/go_forward.gif\" alt=\"vor\">\n";
          echo "<input type=\"image\" name=\"flt_end\" src=\"".$conf_design_path."/go_end.gif\" alt=\"Ende\">\n";
          echo "</td>";
          echo "<td>";
          if ($_SESSION [filter_erledigt] == 0)  {
            echo "<input name=\"filter_erledigt\" type=\"checkbox\">\n";
          } else {
            echo "<input name=\"filter_erledigt\" type=\"checkbox\" checked=\"checked\">\n";
          }
          echo "erledigte&nbsp;\n";
          echo "</td>";
        }
        echo "<td>";
        echo "<input name=\"filter_submit\" value=\"einstellen\" type=\"submit\">\n";
        echo "</td>";
        echo "\n<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
        echo "<td>";
        if (isset ($_SESSION ["flt_search"]) ) { $defvalue = $_SESSION ["flt_search"] ;}
        else {$defvalue = "";}
        echo "<p>Suchbegriff: <input name=\"flt_search\" value=\"".$defvalue."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
        echo "</td>";
        echo "<td>";
        echo "<input name=\"filter_suche\" value=\"suchen\" type=\"submit\">\n";
        echo "</td>";

        echo "<td>";
        echo "<input name=\"filter_suche_reset\" value=\"reset\" type=\"submit\">\n";
        echo "</td>";

        echo "</tr>";
        echo "</tbody></table>";
      break;
    }
    if ( debug ) { echo "<!-- ENDE file:liste.php fkt:darstellungsart -->\n"; }
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

    if (!isset ($_SESSION ["vStab_funktion"])) session_start ();

    $this->stab_fkt  = $_SESSION ["vStab_funktion"] ;
    $this->dbtyp = $table;

    switch  ($table) {
       case "master":
            $this->db_master_katego = $conf_4f_tbl ["masterkatego"] ;
            $this->db_tablname      = $conf_4f_tbl ["masterkatego"] ;
            $this->db_tablnamelk    = $conf_4f_tbl ["masterkategolk"];
       break;
       case "fkt":
        $this->db_tbl = $conf_4f_tbl ["usrtblprefix"]."_fkt_".
                              strtolower ($_SESSION["vStab_funktion"]);
        $this->db_tablname   = $this->db_tbl."_katego";
        $this->db_tablnamelk = $this->db_tbl."_kategolink";
       break;
       case "user":
            $this->db_tbl = $conf_4f_tbl ["usrtblprefix"].
                          strtolower ($_SESSION["vStab_funktion"])."_".
                          strtolower ($_SESSION["vStab_kuerzel"]) ;
            $this->db_tablname   = $this->db_tbl."_katego";
            $this->db_tablnamelk = $this->db_tbl."_kategolink";
       break;

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

    $this->db_hndl = mysql_connect($this->db_server,$this->db_benutzer, $this->db_passwort)
       or die ("[connection] katego.php 73 Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name, $this->db_hndl)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");
  }

  /***********************************************************************************

  ***********************************************************************************/
  function kategoliste (){
    if ($_SESSION["filter_darstellung"] == "1" ){
      include ("../4fcfg/config.inc.php");

      $this->set_katego_para ("master");   // MASTER
      $this->sqlquery = "SELECT * FROM `".$this->db_tablname."` WHERE 1  ORDER BY `kategorie`;";
      $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
         die("[query_table_MASTER] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
      $this->resultcount = mysql_num_rows($query_result);
      $this->masterresult = NULL;
      for ($i=1; $i<=$this->resultcount; $i++){
        $this->masterresult[$i] = mysql_fetch_assoc($query_result);
      }
      mysql_free_result($query_result);


      $this->set_katego_para ("fkt");     // FUNKTION
      $this->sqlquery = "SELECT * FROM `".$this->db_tablname."` WHERE 1  ORDER BY `kategorie`;";

      $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
         die("[query_table_FUNKTION405] <br>$this->sqlquery<br>-".mysql_error()." ".mysql_errno());
      $this->resultcount = mysql_num_rows($query_result);
      $this->fktresult = NULL ;
      for ($i=1; $i<=$this->resultcount; $i++){
        $this->fktresult[$i] = mysql_fetch_assoc($query_result);
      }
      mysql_free_result($query_result);


      $this->set_katego_para ("user");     // USER
      $this->sqlquery = "SELECT * FROM `".$this->db_tablname."` WHERE 1  ORDER BY `kategorie`;";
      $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
         die("[query_table_USER] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
      $this->resultcount = mysql_num_rows($query_result);
      $this->userresult = NULL ;
      for ($i=1; $i<=$this->resultcount; $i++){
        $this->userresult[$i] = mysql_fetch_assoc($query_result);
      }
      mysql_free_result($query_result);


      $mastercount = COUNT($this->masterresult) ;
      $fktcount    = COUNT($this->fktresult) ;
      $usercount   = COUNT($this->userresult) ;

      if (isset ($_SESSION [global_katego])) {
        $kategoselected = $_SESSION [global_katego];
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
        if (!isset($_SESSION [ma_katego])){$color = "red";}else{$color = "lightred";}
            echo"<a href=\"".$conf_4f ["MainURL"]."?ma_ktgotyp=global&ma_ktgo=alle\">
              <img src=\"./kategobutton.php?icontext=ALLE&color=".$color."\"
                   alt=\"Alle\"
                   border=\"0\"
                   title=\"Alle\"></a>\n";

        for ($i=1; $i<= $mastercount; $i++) {
          if ( $this->masterresult[$i]["kategorie"] == "" ) {
            echo "<a><img src=\"null.gif\" alt=\"leer\"></a>\n";
          } else {
            if ( ($_SESSION [ma_katego] == $this->masterresult[$i]["kategorie"]) AND
                 ($_SESSION [ma_kategotyp] == "global") ){$color = "red";}else{$color = "lightred";}
            echo"<a href=\"".$conf_4f ["MainURL"]."?ma_ktgotyp=global&ma_ktgo=".$this->masterresult[$i]["kategorie"]."\">
              <img src=\"./kategobutton.php?icontext=".$this->masterresult[$i]["kategorie"]."&color=".$color."\"
                   alt=\"".$this->masterresult[$i]["beschreibung"]."\"
                   border=\"0\"
                   title=\"".$this->masterresult[$i]["beschreibung"]."\"></a>\n";
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
        if (!isset($_SESSION [fk_katego])){$color = "blue";}else{$color = "lightblue";}

        echo "<a href=\"".$conf_4f ["MainURL"]."?fk_ktgotyp=fkt&fk_ktgo=alle\">";
        echo "<img src=\"./kategobutton.php?icontext=ALLE&color=".$color."\"
                   alt=\"Alle\"
                   border=\"0\"
                   title=\"Alle\">"; //</a>";
        for ($i=1; $i<= $fktcount; $i++) {
          if ( $this->fktresult[$i]["kategorie"] == "" ) {
            echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
          } else {
            if ( ($_SESSION [fk_katego] == $this->fktresult[$i]["kategorie"]) AND
                 ($_SESSION [fk_kategotyp] == "fkt" ) ){$color = "blue";}else{$color = "lightblue";}
              echo"<a href=\"".$conf_4f ["MainURL"]."?fk_ktgotyp=fkt&fk_ktgo=".$this->fktresult[$i]["kategorie"]."\">
              <img src=\"./kategobutton.php?icontext=".$this->fktresult[$i]["kategorie"]."&color=".$color."\"
                   alt=\"".$this->fktresult[$i]["beschreibung"]."\"
                   border=\"0\"
                   title=\"".$this->fktresult[$i]["beschreibung"]."\"></a>";
          }
        }
        echo "</div>";   //echo "<p>";
      }

        // USER KATEGORIE
      if ($usercount != 0){
        echo "<div style=\"height:20px; background-color:#C8FFC8; border-top-color:#C8FFC8; border-left-color:#C8FFC8; border-right-color:#C8FFC8; border-bottom-color:#000000; border-width:2px; border-style:solid; padding-top:10px; padding-bottom:1px; margin-bottom:10px;\">\n";
        if (!isset($_SESSION [us_katego])){$color = "green";}else{$color = "lightgreen";}

        echo "<a href=\"".$conf_4f ["MainURL"]."?us_ktgotyp=user&us_ktgo=alle\">";
        echo "<img src=\"./kategobutton.php?icontext=ALLE&color=".$color."\"
                   alt=\"Alle\"
                   border=\"0\"
                   title=\"Alle\">"; //</a>";
        for ($i=1; $i<= $usercount; $i++) {
          if ( $this->userresult[$i]["kategorie"] == "" ) {
            echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
          } else {
            if ( ($_SESSION [us_katego] == $this->userresult[$i]["kategorie"]) AND
                 ($_SESSION [us_kategotyp] == "user" ) ){$color = "green";}else{$color = "lightgreen";}
            echo"<a href=\"".$conf_4f ["MainURL"]."?us_ktgotyp=user&us_ktgo=".$this->userresult[$i]["kategorie"]."\">
              <img src=\"./kategobutton.php?icontext=".$this->userresult[$i]["kategorie"]."&color=".$color."\"
                   alt=\"".$this->userresult[$i]["beschreibung"]."\"
                   border=\"0\"
                   title=\"".$this->userresult[$i]["beschreibung"]."\"></a>";
          }
        }
        echo "</div>";   //echo "<p>";
      }


    }
  }



  function get_list (){
    echo "\n\n\n<!-- ANFANG file:liste.php fkt:createlist -->";
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $tblusername = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"]).
                     "_".strtolower ($_SESSION["vStab_kuerzel"]);

    $tblfktname  = $conf_4f_tbl ["usrtblprefix"]."_fkt_".strtolower ($_SESSION["vStab_funktion"]);

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );

    $query_select_arg = $conf_4f_tbl ["nachrichten"].".`00_lfd`, ".
                        $conf_4f_tbl ["nachrichten"].".`09_vorrangstufe`, ".
                        $conf_4f_tbl ["nachrichten"].".`04_richtung`, ".
                        $conf_4f_tbl ["nachrichten"].".`04_nummer`, ".
                        $conf_4f_tbl ["nachrichten"].".`10_anschrift`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_abfzeit`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_inhalt`, ".
                        $conf_4f_tbl ["nachrichten"].".`13_abseinheit`, ".
                        $conf_4f_tbl ["nachrichten"].".`14_funktion`, ".
                        $conf_4f_tbl ["nachrichten"].".`16_empf`, ".
                        $conf_4f_tbl ["nachrichten"].".`X00_status`, ".
                        $conf_4f_tbl ["nachrichten"].".`x01_abschluss` ";

    $query_from_arg   = $conf_4f_tbl ["nachrichten"] ;
      // Kategorien Master und User die im Query erscheinen müssen

    if (  ($_SESSION["filter_darstellung"] == "1" ) AND !(isset($_SESSION[flt_search])) ) {

      if ( isset ($_SESSION["ma_katego"]) ) {
          $query_select_ma_arg .= ",".$conf_4f_tbl ["masterkatego"].".`kategorie`";
          $query_from_ma_arg .= ",".$conf_4f_tbl ["masterkatego"].",".$conf_4f_tbl ["masterkategolk"];
      }
      if ( isset($_SESSION[fk_katego])) {
          $query_select_fk_arg .= ",".$tblfktname."_katego.`kategorie`";
          $query_from_fk_arg .= ",".$tblfktname."_katego, ".$tblfktname."_kategolink ";
      }
      if ( isset($_SESSION[us_katego])) {
          $query_select_us_arg .= ",".$tblusername."_katego.`kategorie`";
          $query_from_us_arg .= ",".$tblusername."_katego, ".$tblusername."_kategolink ";
      }
    }
      // Für wenn sind die Meldungen bestimmt
    $query_where_arg1 = "(( `".$conf_4f_tbl ["nachrichten"]."`.`16_empf` like \"%".$_SESSION["vStab_funktion"]."%\" ) OR
                          ( `".$conf_4f_tbl ["nachrichten"]."`.`16_empf` like \"%alle%\" ))";
    if ($_SESSION["filter_darstellung"] == "1" ){
      if ($_SESSION [filter_gelesen]  == 1){
        $query_where_arg2 = " AND (`".$conf_4f_tbl ["nachrichten"]."`.`00_lfd` ".$readwhat." IN
                              ( select `".$tblusername."_read`.`nachnum` from `".$tblusername."_read` where 1))";
      } else {
        $query_where_arg2 = "";
      }
      if ( ($_SESSION [filter_erledigt] == 1) OR ($_SESSION [filter_unerledigt] == 1) ) {
        $query_where_arg3 = " AND ((";
        if ($_SESSION [filter_erledigt] == 1){
          $query_where_arg3 .= "(`".$conf_4f_tbl ["nachrichten"]."`.`00_lfd` ".$donewhat." IN
                    ( select `".$tblfktname."_erl`.`nachnum` from `".$tblfktname."_erl` where 1))";}
        if ( ($_SESSION [filter_erledigt] == 1) AND
             ($_SESSION [filter_unerledigt] == 1) ) { $query_where_arg3 .= ") OR ("; }
        if ($_SESSION [filter_unerledigt] == 1){
          $query_where_arg3 .= "(`".$conf_4f_tbl ["nachrichten"]."`.`00_lfd` ".$donewhat." NOT IN
                    ( select `".$tblfktname."_erl`.`nachnum` from `".$tblfktname."_erl` where 1))";}
        $query_where_arg3 .= " ))";
      } else {$query_where_arg3 = " ";}
      $query_where_arg4 = "";
      if (isset($_SESSION[ma_katego])){
        $query_where_arg4 = " AND (".$conf_4f_tbl ["nachrichten"].".`00_lfd` = `".$conf_4f_tbl ["masterkategolk"]."`.`msg`)".
                            " AND (".$conf_4f_tbl ["masterkatego"].".`lfd` = `".$conf_4f_tbl ["masterkategolk"]."`.`katego`)".
                            " AND (".$conf_4f_tbl ["masterkatego"].".`kategorie` = \"".$_SESSION["ma_katego"]."\")";
      }
      $query_where_arg5 = "";
      if (isset($_SESSION[fk_katego])){
        $query_where_arg5 = " AND (`".$conf_4f_tbl ["nachrichten"]."`.`00_lfd` = `".$tblfktname."_kategolink`.`msg`)".
                            " AND (`".$tblfktname."_katego`.`lfd` = `".$tblfktname."_kategolink`.`katego`)".
                            " AND  (`".$tblfktname."_katego`.`kategorie` = \"".$_SESSION["fk_katego"]."\")";
      }
      $query_where_arg6 = "";
      if (isset($_SESSION[us_katego])){
        $query_where_arg6 = " AND (`".$conf_4f_tbl ["nachrichten"]."`.`00_lfd` = `".$tblusername."_kategolink`.`msg`)".
                            " AND (`".$tblusername."_katego`.`lfd` = `".$tblusername."_kategolink`.`katego`)".
                            " AND  (`".$tblusername."_katego`.`kategorie` = \"".$_SESSION["us_katego"]."\")";
      }
    } else {
     $query_where_arg2 = "";  $query_where_arg3 ="";   $query_where_arg4 = "";  $query_where_arg5 = "";   $query_where_arg6 = "";
    }
    $query_orderby_arg = "`04_nummer` DESC, `09_vorrangstufe` DESC ";
    if (isset ($_SESSION["flt_search"])) {
      $query_search = "(".
          "(".$conf_4f_tbl ["nachrichten"].".`04_nummer` LIKE \"%".$_SESSION["flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`10_anschrift` LIKE \"%".$_SESSION["flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_abfzeit` LIKE \"%".$_SESSION["flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_inhalt` LIKE \"%".htmlentities ($_SESSION["flt_search"])."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`13_abseinheit` LIKE \"%".$_SESSION["flt_search"]."%\") )";

      $querycount = "SELECT COUNT(*) FROM ".$query_from_arg.$query_from_ma_arg.$query_from_fk_arg.$query_from_us_arg." WHERE ".
               $query_where_arg1." AND ".$query_search.";" ;

      $query = "SELECT ".$query_select_arg.$query_select_ma_arg.$query_select_fk_arg.$query_select_us_arg." FROM ".$query_from_arg.$query_from_ma_arg.$query_from_fk_arg.$query_from_us_arg." WHERE ".
               $query_where_arg1." AND ".$query_search." ORDER BY ".$query_orderby_arg ;
    } else {
      $query_search = "";
      $querycount = "SELECT COUNT(*) FROM ".$query_from_arg.$query_from_ma_arg.$query_from_fk_arg.$query_from_us_arg." WHERE ".
               $query_where_arg1." ".$query_where_arg2." ".$query_where_arg3." ".$query_where_arg4.$query_where_arg6.";" ;

      $query = "SELECT ".$query_select_arg.$query_select_ma_arg.$query_select_fk_arg.$query_select_us_arg." FROM ".$query_from_arg.$query_from_ma_arg.$query_from_fk_arg.$query_from_us_arg." WHERE ".
               $query_where_arg1." ".$query_where_arg2." ".$query_where_arg3." ".$query_where_arg4.$query_where_arg5.$query_where_arg6." ORDER BY ".$query_orderby_arg ;
    }

    if ( debug == true ){  echo "<br><br>QUERYCOUNT [get_list] =".$querycount."<br>".$tblfktname;echo "<br><br>";}

    if ( $_SESSION["filter_darstellung"] == "1" ){
      $tmp = $dbaccess->query_table_wert ($querycount);
      $anzahl = $tmp[0];

      $_SESSION["filter_rescount"] = $anzahl ;

      if ( debug == true ){ echo "<br>ANZAHL ===".$anzahl."<br>";}

      if (isset($_SESSION[flt_navi])) {

        switch ($_SESSION[flt_navi]) {
           // ANFANG
          case "start":
                  $_SESSION["filter_start"] = 0;
          break;
           // Eine Seite zurück
          case "back":
                  $_SESSION["filter_start"] -= $_SESSION[filter_anzahl];
                  if ($_SESSION["filter_start"] < 0){
                    $_SESSION["filter_start"]=0;}
          break;
           // Eine Seite vor
          case "for":
                  if ($anzahl < $_SESSION[filter_anzahl]){ $_SESSION[filter_start] = 0;
                  } else {
                    $_SESSION["filter_start"] += $_SESSION[filter_anzahl];
                    if ($_SESSION["filter_start"] >= $anzahl){
                      $_SESSION["filter_start"] = $anzahl-1;}
                  }
          break;
          // Letzte Seite
          case "end":
                  if ($anzahl < $_SESSION[filter_anzahl]){ $_SESSION[filter_start] = 0;
                  } else {
                    $seiten = floor ($anzahl / $_SESSION[filter_anzahl]);
                    $_SESSION["filter_start"] = $seiten * $_SESSION["filter_anzahl"];
                  }
          break;
        }
        unset ($_SESSION [flt_navi]);
      }
      $query .= " LIMIT ".$_SESSION["filter_start"].",".$_SESSION["filter_anzahl"];
    }
      // Zunächst holen wir alls Meldungen mit den entsprechenden Kriterien
    $query = $query_select.$query;
    if ( debug == true ){ echo "<big>"; echo "QUERY [get_list] =:=".$query;echo "</big><br><br>"; }
    $result = $dbaccess->query_table ($query);
    return ($result);
  }


/******************************************************************************\

\******************************************************************************/
  function createlist (){
    echo "\n\n\n<!-- ANFANG file:liste.php fkt:createlist -->";
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    switch ($this->listenart){

      case "FMA":           /***** F M A ****/
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`07_durchspruch`, `08_befhinweis`, `08_befhinwausw`,`09_vorrangstufe`, `10_anschrift`, `12_abfzeit`, `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE ((`04_richtung` = \"A\") AND (`03_datum` = 0) AND (`03_zeichen` = \"\")) order by `09_vorrangstufe` DESC, `12_abfzeit` ; ";
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
            if ( ( $row["09_vorrangstufe"] != "" ) and ($row["09_vorrangstufe"] != "eee")){
              echo "<tr style=\"background-color: rgb(255,255,100); color:#FFFFFF; font-weight:bold;\">\n";
            }
            $abfzeit = convdatetimeto ($row["12_abfzeit"]);
            echo "<td>"; if (($row["12_abfzeit"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit["stak"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
            echo "<td>"; if (($row["09_vorrangstufe"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
            echo "<td>"; if (($row["10_anschrift"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
            echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";           echo "</tr>";
          }
          echo "</tbody></table>";
        } else {// if $result != ""
          echo "<big><big><big>Zur Zeit keine Meldung im Ausgang</big></big></big>";
        }
      break;

      case "Stab_lesen":  // ******  S T A B    l e s e n *****
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
          echo "</td>\n";
            // Transport
          echo "<th align=\"center\">";
          echo "<p><img src=\"".$conf_design_path."/transport.gif\" alt=\"Transportstatus\"></p>";
          echo "</td>\n";
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
             $receivercolor = $hilf [ $_SESSION [vStab_funktion] ]; // Empfaenger dieser Zeile
             switch ($receivercolor){
               case "rt":  $receiverbackground = $cfg ["lbg"] ["rt"];   break;
               case "gn":  $receiverbackground = $cfg ["lbg"] ["gn"];   break;
               case "bl":  $receiverbackground = $cfg ["lbg"] ["bl"];   break;
               case "ge":  $receiverbackground = $cfg ["lbg"] ["ge"];   break;
               default:    $receiverbackground = $cfg ["lbg"] ["dflt"];
             }
             echo "<tr style=\"background-color: ".$receiverbackground."; color:#FFFFFF; font-weight:bold;\">\n";
             // Liegt eine Vorrangstufe vor!!!
             $vorrang = ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) );
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
                 echo "<a href=\"mainindex.php?action=gelesen&00_lfd=".$row["00_lfd"]."&todo=unset\" target=\"_self\">\n";
                 echo "<img src=\"".$conf_design_path."/000.gif\" alt=\"lesen\" border=\"0\"></a>";
                 echo "</td>\n";
               } else {
                 echo "<td align=\"center\">";
                 echo "<a href=\"mainindex.php?action=gelesen&00_lfd=".$row["00_lfd"]."&todo=set\" target=\"_self\">\n";
                 echo "<img src=\"".$conf_design_path."/mail_prio_unread.gif\" alt=\"lesen\" border=\"0\"></a>";
                 echo "</td>\n";
               }
             } else {
               if ( $schongelesen ){  // ==> wurde schon gelesen
                 echo "<td align=\"center\">";
                 echo "<a href=\"mainindex.php?action=gelesen&00_lfd=".$row["00_lfd"]."&todo=unset\" target=\"_self\">\n";
                 echo "<img src=\"".$conf_design_path."/000.gif\" alt=\"lesen\" border=\"0\"></a>";
                 echo "</td>\n";
               } else {
                 echo "<td align=\"center\">";
                 echo "<a href=\"mainindex.php?action=gelesen&00_lfd=".$row["00_lfd"]."&todo=set\" target=\"_self\">\n";
                 echo "<img src=\"".$conf_design_path."/mail_unread.gif\" alt=\"Neu/new\" border=\"0\"></a>";
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
                 echo "<a href=\"mainindex.php?action=erledigt&00_lfd=".$row["00_lfd"]."&todo=unset\" target=\"_self\">\n";
                 echo "<img src=\"".$conf_design_path."/task_done.gif\" alt=\"erledigt\" border=\"0\"></a>";
                 echo "</td>\n";
             } else {
                 echo "<td style=\"text-align: center; vertical-align: middle;\">";
                 echo "<a href=\"mainindex.php?action=erledigt&00_lfd=".$row["00_lfd"]."&todo=set\" target=\"_self\">\n";
                 echo "<p><img src=\"".$conf_design_path."/task_due.gif\" alt=\"NICHT erledigt\" border=\"0\"></p>";
                 echo "</td>\n";
             }

               // Status für ausgehende Nachrichten
             echo "<td align=\"center\">";
             if ( ( $row["04_richtung"] == "A") ){
               switch ( $row["X00_status"] ) {
                 case 2: // liegt vor dem Fernmelder ==> rot
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
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
               echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ;
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
             echo "</td>\n";
              // Eingang / Ausgang
             echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_richtung"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
              // Nachweisnummer
             echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_nummer"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
              // Muss der Absender oder die Absendende Einheit unter von / an
             if ($row["04_richtung"] == "A" ) { // von = 14_funktion an=10_anschrift
                // Ausgang VON
               echo "<td>";
               if (($row["14_funktion"] != "")) {
                 echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["14_funktion"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
               echo "</td>\n";

                // Ausgang AN
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
               echo "</td>\n";

             }

             if ($row["04_richtung"] == "E" ) {  // von = 13_abseinheit/14_funktion an=10_anschrift
               echo "<td>";
               if ( ($row["13_abseinheit"] != "") ) {
                 echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\"><big>".$row["13_abseinheit"]."</big></a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";


               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\"><big>".$row["10_anschrift"]."</big></a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             if (($row["12_abfzeit"] != "")) {
               $arr    = convdatetimeto ($row["12_abfzeit"]);
               $abzeit = $arr ["stak"];
               echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\"><big>".$abzeit."</big></a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\"><big>".$row["12_inhalt"]."</big></a>\n";
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
        if (debug){echo "<big><b>---Stab_sichten---850</b></big><br>";}
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`07_durchspruch`,
                         `08_befhinweis`,
                         `08_befhinwausw`,
                         `09_vorrangstufe`,
                         `10_anschrift`,
                         `12_abfzeit`,
                         `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE ( (  `15_quitdatum`    = 0 ) AND
                          (  `15_quitzeichen`  = 0 ) )  AND

                        ( (  `04_richtung`     =\"E\") OR
                          (  `03_datum`       != 0 ) AND
                          (  `03_zeichen`     != \"\" ) )
                  order by `09_vorrangstufe` DESC, `12_abfzeit`; ";

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
           if ( ( $row["09_vorrangstufe"] != "" ) and ($row["09_vorrangstufe"] != "eee")){
              echo "<tr style=\"background-color: rgb(220,0,0); color:#FFFFFF; font-weight:bold;\">\n";
           }
           $abfzeit = convdatetimeto ($row["12_abfzeit"]);
           echo "<td>"; if (($row["12_abfzeit"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit[stak]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td>"; if (($row["09_vorrangstufe"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td>"; if (($row["10_anschrift"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
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
        if (debug){echo "<big><b>---SIADMIN/FMADMIN---924</b></big><br>";}
        $this->darstellungs_art ( $this->listenart );

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
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit[stak]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // Funktionen und Farben
             $empfcolor = extraiereempfaenger ( $row ["16_empf"] ) ;
             for ( $i=1; $i<= count ($conf_empf); $i++ ) {
               if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
                 switch ($empfcolor [$conf_empf [$i][fkt]]) {
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
      break; // case SIADMIN

      case "FmNwE":  // *****  F M N W E ingang ******
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`09_vorrangstufe`,`04_richtung`, `04_nummer`, `10_anschrift`,
                         `12_abfzeit`, `12_inhalt`, `13_abseinheit`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE 04_richtung = \"E\" order by 04_nummer ASC ; ";
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
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ( $result != "" ) {
            foreach ($result as $row){
               echo "<td>";
               if ( ( $row["09_vorrangstufe"] != "") and
                    ( $row["09_vorrangstufe"] != "eee" ) ) {
                 echo "<a>".$row["09_vorrangstufe"]."</a>\n" ;
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".$row["04_richtung"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".$row["04_nummer"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".$row["10_anschrift"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".$row["13_abseinheit"]."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr [stak];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          } // if 2. result == ""
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

      case "FmNwA":  // *****  F M N W A usgang ******

        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`09_vorrangstufe`,`04_richtung`, `04_nummer`, `10_anschrift`,
                         `12_abfzeit`, `12_inhalt`, `13_abseinheit`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE 04_richtung = \"A\" order by 04_nummer ASC ; ";
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
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ($result != "") {
            foreach ($result as $row){
               echo "<td>";
               if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
                 echo "<a>".$row["09_vorrangstufe"]."</a>\n" ;
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".$row["04_richtung"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".$row["04_nummer"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".$row["10_anschrift"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".$row["13_abseinheit"]."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr [stak];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          }
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

      case "FmNw":  // *****  F M N W  ******

        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`01_datum`,`01_zeichen`,`02_zeit`,`03_datum`, `09_vorrangstufe`,`04_richtung`,
                         `04_nummer`, `10_anschrift`, `12_inhalt`, `13_abseinheit`,`14_zeichen`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE 1 order by 04_nummer ASC ; ";
        $result = $dbaccess->query_table ($query);
        echo "<p align=\"center\"><big><big><big><b>Nachweisung Eingang / Ausgang</b></big></big></big></p>";
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
          echo "<td>Bef&ouml;rder</td>\n";
//          echo "<td>Verfasser</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ($result != "") {
            foreach ($result as $row){
               echo "<td>";
               if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
                 echo "<a>".$row["09_vorrangstufe"]."</a>\n" ;
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".$row["04_richtung"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".$row["04_nummer"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".$row["10_anschrift"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".$row["13_abseinheit"]."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }


               echo "<td>";
               if (($row["01_datum"] != "") and ( $row["01_datum"] != "0000-00-00 00:00:00" )) {
                 $arr    = convdatetimeto ($row["01_datum"]);
                 $abzeit = $arr [stak];
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
               if (($row["02_zeit"] != "") and ( $row["02_zeit"] != "0000-00-00 00:00:00" )) {
                 $arr    = convdatetimeto ($row["02_zeit"]);
                 $abzeit = $arr [stak];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>";

               echo "<td>";
               if (($row["03_datum"] != "") and ( $row["03_datum"] != "0000-00-00 00:00:00" )) {
                 $arr    = convdatetimeto ($row["03_datum"]);
                 $abzeit = $arr [stak];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
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
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          }
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;
    } // switch
  echo "<!-- ENDE file:liste.php fkt:createlist -->";
  }
//echo "</form>\n";

} // class

?>
