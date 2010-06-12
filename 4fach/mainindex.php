<?php

/*****************************************************************************\
   Datei: mainindex.php

   benoetigte Dateien: config.inc.php, protokoll.php, db_operation.php,
                      4fachform.php, liste.php, data_hndl.php, menue.php
   Beschreibung:
           HAUPTSTEUERUNGSDATEI

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

define ("debug",false);
define ("create_vordrucke", true);

session_start ();

if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}

if (debug){
error_reporting(E_ALL);
} else {
error_reporting(FATAL | ERROR | WARNING);
}

include ("../config.inc.php");    // Konfigurationseinstellungen und Vorgaben
include ("../dbcfg.inc.php");    // Datenbankparameter
include ("../fkt_rolle.inc.php"); // Mitspieler
include ("protokoll.php");            // Protokolllierung in der Datenbank
include ("../db_operation.php");  // Datenbank operationen
include ("4fachform.php");            // Formular Behandlung 4fach Vordruck
include ("liste.php");                // erzeuge Ausgabelisten
include ("data_hndl.php");            // Schnittstelle zur Datenbank
include ("menue.php");                // erzeuge Men

/**********************************************************************\
  Es gab noch keinen Kontakt ==> Begruessung
\**********************************************************************/
if (isset ( $_GET ["m2_parameter_x"] )) {
/*  echo "<html><head><title>Parameter einstellen</title>";
  echo "<script type=\"text/javascript\">";
  echo "ParaFenster = window.open(\"./edt_para.php\", \"ParaFenster\", \"width=500,height=500,left=50,top=50\");";
  echo "ParaFenster.focus();";
  echo "</script>";
*/
  include ("./edt_para.php");
  $para = new parametrierung ();
}


/*******************************************************************
ANTWORT % WEITERLEITUNG
*******************************************************************/
$weiterantwort = false;
  if ( ( (isset($_GET["weiterleiten_x"])) or
         (isset($_GET["antwort_x"])) ) and
         ( ( $_GET["task"] == "FM-Ausgang" ) or
         ( $_GET["task"] == "FM-Ausgang_Sichter" ) ) )  {
    $weiterantwort = true;
    $_SESSION ["sw_data"] = $_GET ;
  } elseif (  (isset($_GET ["abbrechen_x"]) and (isset ($_SESSION["sw_data"]))) and
              ( ( $_GET["task"] == "FM-Eingang" ) or ( $_GET["task"] == "FM-Eingang_Sichter" ) ) ) {
    unset ($_SESSION["sw_data"]);
  }


  /**********************************************************************\
    Überprüfe ob die Listendarstellung geaendert werden soll

  ["filter_darstellung"]=> string(2) "on"
  ["filter_anzahl"]=> string(2) "10"
  ["filter_gelesen"]=> string(2) "on"
  ["filter_erledigt"]=> string(2) "on"
  ["filter_submit"]=> string(10) "einstellen" }

  ["flt_start_x"]=>  string(2) "23" ["flt_start_y"]=>  string(1) "6"
  ["flt_back_x"]=>  string(2) "18" ["flt_back_y"]=>  string(2) "12"
  ["flt_for_x"]=>  string(2) "16" ["flt_for_y"]=>  string(2) "12"
  ["flt_end_x"]=>  string(1) "9" ["flt_end_y"]=>  string(1) "7"

  ["flt_search"]=>  string(4) "test"
  ["filter_suche"]=>  string(6) "suchen" } #

  \**********************************************************************/
  if (isset($_GET["filter_suche_reset"])){ unset ($_SESSION["flt_search"]); }
  if (isset($_GET["filter_suche"])){ 
    if ($_SESSION["flt_search"] != $_GET ["flt_search"]){
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;      
    }
    $_SESSION["flt_search"] = $_GET ["flt_search"]; 
  }

  if ( (isset ($_GET["filter_submit"])) OR
       (isset($_GET["flt_start_x"])) OR
       (isset($_GET["flt_back_x"])) OR
       (isset($_GET["flt_for_x"])) OR
       (isset($_GET["flt_end_x"]))
     ) { // es soll was geändert werden

    if (!isset ( $_SESSION["filter_darstellung"] )){
      $_SESSION["filter_darstellung"] = 1;
      $_SESSION["filter_gelesen"] = 0;
      $_SESSION["filter_erledigt"] = 0;
      $_SESSION["filter_anzahl"] = $_GET["filter_anzahl"];
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;

    } else {

      if ($_GET["filter_darstellung"] == "on") { $_SESSION["filter_darstellung"] = 1;

        if ($_GET["filter_gelesen"] == "on") {
          $_SESSION["filter_gelesen"] = 1;
        } else {
          $_SESSION["filter_gelesen"] = 0;
        }
        if ($_GET["filter_erledigt"] == "on") {
          $_SESSION["filter_erledigt"] = 1;
        } else {
          $_SESSION["filter_erledigt"] = 0;
        }

        if (isset ($_GET["filter_anzahl"])) {
          $_SESSION["filter_anzahl"] = $_GET["filter_anzahl"]; }
        else {
          $_SESSION["filter_anzahl"] = 5;
        }
      } else {
        unset ($_SESSION["filter_darstellung"]);
        unset ($_SESSION["filter_anzahl"]);
      }
    } //else if (!isset ( $_SESSION["filter_darstellung"] )){
    /*          $_SESSION[flt_navi]
            if (isset($_GET[flt_navi])) {  }
            if (isset($_GET[flt_back_x])) {
            if (isset($_GET[flt_for_x])) {
            if (isset($_GET[flt_end_x])) {
    */
    if (isset($_GET[flt_start_x])) { $_SESSION[flt_navi] = "start";}
    if (isset($_GET[flt_back_x]))  { $_SESSION[flt_navi] = "back";}
    if (isset($_GET[flt_for_x]))   { $_SESSION[flt_navi] = "for";}
    if (isset($_GET[flt_end_x]))   { $_SESSION[flt_navi] = "end";}

    header("Location: ".$conf_4f ["MainURL"]);
    exit;
  } // Listendarstellung aendern

  /************************************************************************\

  \************************************************************************/
  if ( isset ($_GET ["action"]) ){
    // gelesen
    if ($_GET [action] == "gelesen")
      if ($_GET [todo] == "set"){
        set_msg_read ( $_GET["00_lfd"] );
      } else {
        unset_msg_read ( $_GET["00_lfd"] );
      }
    // erledigt
        if ($_GET [action] == "erledigt")
      if ($_GET [todo] == "set"){
        set_msg_done ( $_GET["00_lfd"] );
      } else {
        unset_msg_done ( $_GET["00_lfd"] );
      }
  }



  /**********************************************************************\
    Es gab noch keinen Kontakt ==> Begruessung
  \**********************************************************************/
  if (!isset ( $_SESSION ["menue"] ))
     { $_SESSION ['menue'] = "WELCOME"; }

  /**********************************************************************\
    Der Anmelde Button wurde gedrueckt
  \**********************************************************************/
  if ( $_GET["login"] == "Anmelden" )  {
    $_SESSION ['menue'] = "LOGIN"; }

  /**********************************************************************\
    Es kommen Anmeldedaten die geprueft und gespeichert werden muessen
  \**********************************************************************/
  if ((isset ($_GET["benutzer"])) AND
      (isset ($_GET["kuerzel"] )) AND
      (isset ($_GET["funktion"])) and
      ($_SESSION["menue"] == "LOGIN")){
    $error = check_save_user ();
//    unset ($_GET);
    if (!$error) {
      $_SESSION["menue"] = "ROLLE";  //   führt zu fehlern bei der menüdarstellung
//      header("Location: ".$conf_4f ["MainURL"]);
    }
    // Wenn Benutzer OK ==> SESSION [menue]=ROLLE ; $_SESSION [ROLLE]= Stab, Fernmelder...
  }

  $gesprnotizsichter = false ; // Voreinstellung fuer dieses Skript

/**********************************************************************
  Daten kommen vom Formular zurueck und koennen gespeichert bzw.
  verarbeitet werden.
  checkandsave befindet sich in data_hndl.php
***********************************************************************/

  // Abbruch der Gesprächsnotiz beim Sichten
  if ( ( $_GET["abbrechen_x"] ) and ( $_SESSION ["gesprnoti"] ) ){
    unset ( $_SESSION ['gesprnoti'] );
  }


  if ( ( ( $_GET["task"] == "Stab_schreiben" ) or
         ( $_GET["task"] == "Stab_gesprnoti" ) or
         ( $_GET["task"] == "FM-Ausgang" ) or
         ( $_GET["task"] == "FM-Ausgang_Sichter" ) or
         ( $_GET["task"] == "FM-Admin" ) or
         ( $_GET["task"] == "FM-Eingang" ) or
         ( $_GET["task"] == "FM-Eingang_Sichter" ) or
         ( $_GET["task"] == "FM-Eingang_Anhang" ) or
         ( $_GET["task"] == "FM-Eingang_Anhang_Sichter" ) or
         ( $_GET["task"] == "Stab_sichten" ) or
         ( $_GET["task"] == "SI-Admin" ) )  and (
         ( !isset ($_GET["abbrechen_x"])) or isset ($_GET["antworten_x"]) or isset ($_GET["weiterleiten_x"])
         )
         ) {
    $returndata = $_GET;

    if ( debug == true ){ echo "### 143 Daten kommen vom Formular und können gespeichert werden";  echo "<br>\n";}

    if ( ( $_GET ["11_gesprnotiz"] == "on" ) and ( !$_SESSION ['gesprnoti'] ) ){
        // Bei Gesprächsnotiz 2. Vorlage für Sichtung

        if ( debug == true ){ echo "### Gesprächsnotiz == 2. Sichtung";  echo "<br>\n";}

        $formdata = $_GET ;
        $formdata ["01_zeichen"]      = $_SESSION ["vStab_kuerzel"];
        $formdata ["11_gesprnotiz"]   = "t";
        $formdata ["16_empf"]         = $redcopy2."_rt,".$_SESSION ["vStab_funktion"]."_gn" ;
        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
        $form = new nachrichten4fach ($formdata, "Stab_gesprnoti", "");
        $_SESSION ['gesprnoti'] = true;
        $gesprnotizsichter = true ;
    } else {
      if (isset ($_SESSION ['gesprnoti'])) { unset ( $_SESSION ['gesprnoti'] ); }

      if ( debug == true ){ echo "### 161 check and save";  echo "<br>\n";}

      check_and_save ($returndata);
      // verhindert das erneute Speichern bei Betaetigung von F5

      if (create_vordrucke){
        include ("../bak/backup.php");
      }

      if ( !$weiterantwort ){
        header("Location: ".$conf_4f ["MainURL"]);
      }
    }
  } elseif ( ($_GET["task"] == "FM-Ausgang_Sichter") and ($_GET ["abbrechen_x"]) ) {

      /************************************************************************\

      \************************************************************************/

       if ( debug == true ){ echo "### 175 ( ($_GET[task] == FM-Ausgang_Sichter) and ($_GET [abbrechen_x]) )";  echo "<br>\n";}

       $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
       $query = "SELECT `x02_sperre`,`x03_sperruser` FROM `".$conf_4f_tbl ["nachrichten"]."`
                 where `00_lfd` = ".$_GET["00_lfd"];
       $result = $dbaccess->query_table ($query);

       if ( ($result [1]['x02_sperre'] == "f") OR // Ist der Satz schon in Bearbeitung (sperre == FALSE)?
           (($result [1]['x02_sperre'] == "t" ) AND // Satz gesperrt
            ($_SESSION ["vStab_kuerzel"] == $result[1][x03_sperruser])) ) { // Du bist der Bearbeiter

         $query = "UPDATE ".$conf_4f_tbl ["nachrichten"]."
                   SET x02_sperre = \"f\", x03_sperruser = \"\" where 00_lfd = ".$_GET["00_lfd"];
         $result = $dbaccess->query_table_iu ($query);
       }
  }

/**********************************************************************\
Daten kommen vom Formular und sollen als Antwort dienen.
\**********************************************************************/
    // A N T W O R T
  if ( ( isset ($_GET["antwort_x"]) ) and ( $_GET["task"] == "Stab_lesen" ) ) {
      //  A N T W O R T  --  "Stab_lesen"

    if ( debug == true ){ echo "### 199 ANTWORT - Stab_lesen";  echo "<br>\n";}

    $formdata = $_GET ;
    $aushilfe = $formdata ["10_anschrift"];
    $formdata ["10_anschrift"] =  $formdata ["13_abseinheit"]."  ".$formdata["14_funktion"];
    $formdata ["13_abseinheit"] = $aushilfe ;
    $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"]; //$_SESSION["vStab_rolle"];
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\"".$formdata ["12_inhalt"]."\"\n";
    $formdata ["04_richtung"] = "";
    $formdata ["04_nummer"] = "";
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }


  if ( (isset ($_GET["weiterleiten_x"])) and
       ($_GET["task"] == "Stab_lesen") and
     ( ($_GET ["04_richtung"] == "E") or
       ($_GET ["04_richtung"] == "A") ) ) {

    if ( debug == true ){ echo "### 225 WEITERLEITUNG - Stab_lesen";  echo "<br>\n";}

    $formdata = $_GET ;
    // W E I T E R L E I T U N G  --  "Stab_lesen" ---- "E" Anschrift frei; Absender normal
    $formdata ["10_anschrift"] = "";
    $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\"".$formdata ["12_inhalt"]."\"\n";
    $formdata ["04_richtung"] = "";
    $formdata ["04_nummer"] = "";
    $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"];
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }

  if (isset ($_SESSION ["sw_data"] )) {

    if ( debug == true ){ echo "### 236 _SESSION [sw_data]";  echo "<br>\n";}

    $formdata = $_GET ;
    $formdata = $_SESSION ["sw_data"] ;
    if  (( isset ($formdata["antwort_x"]) ) and
        ( ( $formdata["task"] == "FM-Ausgang" ) or
          ( $formdata["task"] == "FM-Ausgang_Sichter" ) ) ) {

      if ( debug == true ){ echo "### 244 antwort_x und FM_Ausgang(_Sichter) ";  echo "<br>\n";}

        //  A N T W O R T  --  "FM-Ausgang"  or "FM-Ausgang_Sichter"
      $aushilfe = $formdata ["10_anschrift"];
      $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
      $formdata ["10_anschrift"] =  $formdata ["13_abseinheit"]."  ".$formdata["14_funktion"];
      $formdata ["13_abseinheit"] = $aushilfe ;

      $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\"".$formdata ["12_inhalt"]."\"\n";
      $formdata ["04_richtung"] = "";
      $formdata ["04_nummer"] = "";

      $formdata ["02_zeit"] = "";
      $formdata ["02_zeichen"] = "";
      $formdata ["03_datum"] = "";
      $formdata ["03_zeichen"] = "";

      unset ($_SESSION ["sw_data"]);

      if (sichter_online()) {
        $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
      } else {
        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
        $formdata ["16_empf"]         = "";
        $form = new nachrichten4fach ($formdata, "FM-Eingang_Sichter", "");
      }

    }

    if (   (isset ($formdata["weiterleiten_x"])) and ( ($_GET["task"] == "FM-Ausgang" ) or ($_GET["task"] == "FM-Ausgang_Sichter") ) ) {

          //  W E I T E R L E I T U N G  --  "FM-Ausgang"  or "FM-Ausgang_Sichter"

      if ( debug == true ){ echo "### 277 WEITERLEITUNG - FM-Ausgang(_Sichter)";  echo "<br>\n";}

      $aushilfe = $formdata ["10_anschrift"];
      $formdata ["10_anschrift"] =  $formdata ["13_abseinheit"]."  ".$formdata["14_funktion"];
      $formdata ["13_abseinheit"] = $aushilfe ;

      $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\n\"".$formdata ["12_inhalt"]."\"\n\n";
      $formdata ["04_richtung"] = "";
      $formdata ["04_nummer"] = "";

      unset ($_SESSION ["sw_data"]);

      if (sichter_online()) {
        $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
      } else {
        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
        $formdata ["16_empf"]         = "";
        $form = new nachrichten4fach ($formdata, "FM-Eingang_Sichter", "");
      }
    }
//    unset ($_SESSION ["sw_data"]);
  }
/*++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++*/



/**********************************************************************
 Voreinstellung fuer das Menue
***********************************************************************/
if  ((isset ($_SESSION ["vStab_benutzer"])) AND
   (!(isset ($_GET["m2auswahl"]))) ) {
   $mode = 2;
}

$formdata = ""; // setze die Formulardaten zurck

/**********************************************************************\
  --- S T A B  s c h r e i b e n ---
  Hier werden die Angaben:
    Abfasszeit, Absendeeinheit, Zeichen des Verfassers, Funktion
  der Stabsfunktion im Formular voreingestellt.
\**********************************************************************/

  if ( (isset ( $_GET["stab_schreiben_x"] )) and !$gesprnotizsichter ) {

    if ( debug == true ){ echo "### 322 _GET[stab_schreiben_x] )) and !$gesprnotizsichter ";  echo "<br>\n";}

  //  $formdata ["12_abfzeit"]     = convtodatetime (date("dm"), date ("Hi"));
    $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"]; //$_SESSION["vStab_rolle"];
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }

/**********************************************************************\
  --- S T A B   s c h r e i b e n   m i t  A n h a n g ---

  Oeffnet ein Fenster in dem Anhaenge ausgewaehlt werdn koennen
\**********************************************************************/
  if (
       ( isset ( $_GET["stab_anhang_x"] ) or
         isset ( $_GET["fm_anhang_x"] )
       ) and

       ( !isset( $_GET["ah_auswahl_x"] )
       )
     )
  {
      if ( debug == true ){ echo "### 345 ( $_GET[stab_anhang_x] ) or isset ( $_GET[fm_anhang_x] ) ) and ( !isset( $_GET[ah_auswahl_x] ) ";  echo "<br>\n";}
  //  include ("anhang.php");
    $menue1 = "anhang";
  }

/**********************************************************************\
  --- S T A B   s c h r e i b e n   m i t  A n h a n g ---

  Anhang ausgewaehlt und kann in Vordruck uebernommen werde
\**********************************************************************/
  if ( ($_SESSION ["vStab_rolle"]== "Stab") and
       (isset ($_GET["ah_auswahl_x"])) ){

    if ( debug == true ){ echo "### 358 Anhang ausgewaehlt und kann in Vordruck uebernommen werden ";  echo "<br>\n";}

    $keys = array_keys ($_GET);
    $ahkey = array ();
    foreach ($keys as $key){
      list($lfd, $num) = split("_", $key);
      if ($lfd == "lfd") { $ahkey [] = "lfd_".$num;}
    }
    $anhang = "";
    foreach ($ahkey as $anh){
      $anhang .= $_GET [$anh].";";
    }
    $formdata ["12_anhang"]      = $anhang;
    $formdata ["12_inhalt"]      .= $anhang;
    // $formdata ["12_abfzeit"]     = convtodatetime (date("dm"), date ("Hi"));
    $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"]; // $_SESSION["vStab_rolle"];
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }

/**********************************************************************\
  --- S T A B   l e s e n  ---

  Menue und Liste
\**********************************************************************/
//echo "\n<!-- Vor Menue und liste -->";
   if ( ( ($_SESSION ["vStab_rolle"] == "Stab" ) or
          ($_SESSION ["ROLLE"] == "Stab" ) or
          ($_SESSION ["vStab_rolle"] == "FB" ) or
          ($_SESSION ["ROLLE"] == "FB" )
         ) and
        (
          ( $_SESSION ["vStab_funktion"] != "Si"
           ) and
          ( $_GET ["stab"] != "meldung"
           ) and
          ( !(isset ($_GET ["m2_benutzer_x"]
           )
         ) and
        (
          ( !(isset ($_GET ["stab_schreiben_x"] ) ) ) and
          ( !$gesprnotizsichter ) and
          ( !(isset ($_GET ["stab_anhang_x"] ) ) ) and
          ( !(isset ($_GET ["fm_anhang_x"] ) ) ) and
          ( !(isset ($_GET ["ah_auswahl_x"] ) ) ) and
          ( !(isset ($_GET["m2_abmelden_x"] ) ) ) and
          ( !(isset ($_GET["antwort_x"] ) ) ) and
          ( !(isset ($_GET["weiterleiten_x"] ) ) ) and
          ( !(isset ($_SESSION["sw_data"] ) ) ) and
          ( $_SESSION ["UPLOAD"] != "fileupload" ) and
          ( $_SESSION ["UPLOAD"] != "upload" )
         )
))       ) {

    if ( debug == true ){ echo "### 413 Stab_lesen - Menue und Liste ";  echo "<br>\n";}
/*
     $css = "a:link    { color:#000000; text-decoration:none; font-weight:bolder ; font-size:normal; }\n".
            "a:visited { color:#000000; text-decoration:none; font-weight:lighter; font-size:small ; }\n".
            "a:hover   { color:#000000; text-decoration:none; }\n".  //font-weight:lighter; }\n".
            "a:active  { color:#0000EE; background-color:#FFFF99;}\n"; // font-weight:lighter  ; }\n".
            "a:focus   { color:#0000EE; background-color:#FFFF99;}\n"; // font-weight:lighter ; }";
*/
     $css = "a:link    { color:#000000; text-decoration:none; font-weight:bolder ; font-size:normal; }\n".
            "a:visited { color:#000000; text-decoration:none; font-weight:bolder ; font-size:normal; }\n".
            "a:hover   { color:#000000; text-decoration:none; }\n".  //font-weight:lighter; }\n".
            "a:active  { color:#0000EE; background-color:#FFFF99;}\n"; // font-weight:lighter  ; }\n".
            "a:focus   { color:#0000EE; background-color:#FFFF99;}\n"; // font-weight:lighter ; }";




      pre_html ("stabliste","Stab lesen ".$conf_4f ["NameVersion"],$css); // Normaler Seitenaufbau mit Auffrischung
      echo "<body>";
//echo "\n<!-- Vor Menueaufruf und liste -->";
      menue ();
      $list = new listen ("Stab_lesen", "");
      $list->createlist ();
//echo "\n<!-- danach Menue und liste -->";
   }

/**********************************************************************\
  --- S T A B   M e l d u n g   l e s e n ---

  Darstellung der Meldung ber die laufende Nummer
\**********************************************************************/
  if (( $_GET["stab"] == "meldung")){

    if ( debug == true ){ echo "### 441 Stab Meldung lesen - Darstellung der Meldung ber die laufende Nummer ";  echo "<br>\n";}

    set_msg_read ($_GET["00_lfd"]);
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["nachrichten"]."` where 00_lfd = ".$_GET["00_lfd"];
  //echo "query===".$query."<br>";
    $result = $dbaccess->query_table ($query);
  //var_dump ($result); echo "<br>";
    $formdata = $result [1];
    $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
  }


/**********************************************************************\
  --- S i c h t e r   M e l d u n g   s i c h t e n ---

  Darstellung der Meldung ueber die laufende Nummer
\**********************************************************************/
  if (( $_GET["sichter"] == "meldung")){

    if ( debug == true ){ echo "### 458 Stab Meldung sichten - Darstellung der Meldung ber die laufende Nummer ";  echo "<br>\n";}

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["nachrichten"]."` where 00_lfd = ".$_GET["00_lfd"];
    $result = $dbaccess->query_table ($query);
    $formdata = $result [1];
    $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
    $form = new nachrichten4fach ($formdata, "Stab_sichten", "");
  }



/**********************************************************************\
  --- S i c h t e r   l e s e n  ---

  Menue und Liste
\**********************************************************************/
   if ( ( ($_SESSION ["vStab_rolle"] == "Stab") or
          ($_SESSION ["ROLLE"] == "Stab")
        ) and
        ( $_SESSION ["vStab_funktion"] == "Si"
        ) and
        ( !($_GET["sichter"] == "meldung")
        ) and
        ( !(isset($_GET["si_admin_x"]))
        ) and
        ( !(isset ($_GET ["m2_abmelden_x"])
        ) and ( $_GET["fm"] != "SI-Adminmeldung" ) )
      )
      {

    if ( debug == true ){ echo "### 488 Sichter lesen - Menue und liste ";  echo "<br>\n";}

     $css = "a:link { color:#000000; text-decoration:none; font-weight:normal; }\n".
            "a:visited { color:#EE0000; text-decoration:none; font-weight:normal; }\n".
            "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:normal; }\n".
            "a:active { color:#0000EE; background-color:#FFFF99; }\n".
            "a:focus { color:#0000EE; background-color:#FFFF99;  }";
        pre_html ("siliste","Sichterliste ".$conf_4f ["NameVersion"],$css); // Normaler Seitenaufbau mit Auffrischung
        echo "<body>";

        menue ();
        $list = new listen ("Stab_sichten", "STSI");
        $list->createlist ();
   }

/**********************************************************************\
  --- F e r n m e l d e r   E i n g a n g  ---

\**********************************************************************/
  if (isset ($_GET["fm_eingang_x"])){

    if ( debug == true ){ echo "### 509 Fernmelder Eingang ";  echo "<br>\n";}

    $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
    $formdata ["10_anschrift"]  = $conf_4f ["anschrift"];
    if (sichter_online()) {
     $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
    } else {
     $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
     $formdata ["16_empf"]         = "";
     $form = new nachrichten4fach ($formdata, "FM-Eingang_Sichter", "");
    }
  }


/**********************************************************************\
  --- F M   A u s g a n g  ---

  Menue und Liste
\**********************************************************************/
 if ( ( ($_SESSION ["vStab_rolle"] == "Fernmelder") or
          ($_SESSION ["ROLLE"] == "Fernmelder")
        ) and
        ( $_SESSION ["vStab_funktion"] == "A/W"
        ) and
        !( ( isset ($_GET ["fm_anhang_x"])  ) OR
           ( isset ($_GET ["ah_upload_x"])  ) OR
           ( isset ($_GET ["ah_auswahl_x"]) )
        ) and
        (!isset ($_GET["m2_abmelden_x"])) and
        (!isset ($_GET["fm_eingang_x"]) ) and
        (!isset ($_GET["fm_admin_x"])   )and
        (!isset ($_GET["etb_eintrag_x"])) and
        (!isset ($_GET["antwort_x"])) and
        (!(isset ($_SESSION["sw_data"]))) and
        ( ($_GET["fm"] != "FM-Adminmeldung") )and
        ( ($_GET["fm"] != "SI-Adminmeldung") ) and
        ( $_GET["fm"] != "meldung"
        )
      ) {

    if ( debug == true ){ echo "### 548 FM Ausgang - Menue und Liste ";  echo "<br>\n";}

    $css = "a:link { color:#000000; text-decoration:none; font-weight:normal; }\n".
          "a:visited { color:#EE0000; text-decoration:none; font-weight:normal; }\n".
          "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:normal; }\n".
          "a:active { color:#0000EE; background-color:#FFFF99; }\n".
          "a:focus { color:#0000EE; background-color:#FFFF99;  }";
    pre_html ("fmdliste","FMD Ausgang ".$conf_4f ["Titelkurz"]."".$conf_4f ["Version"],$css); // Normaler Seitenaufbau mit Auffrischung
    echo "<body>";
    menue ();
    $list = new listen ("FMA", "");
    $list->createlist ();
  }


/**********************************************************************\


\**********************************************************************/
  if ( $_GET["fm"] == "meldung" ){

    if ( debug == true ){ echo "### 569 FM - Ausgang  ";  echo "<br>\n";}

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT `x02_sperre`,`x03_sperruser` FROM `".$conf_4f_tbl ["nachrichten"]."` where 00_lfd = ".$_GET["00_lfd"];
    $result = $dbaccess->query_table ($query);

    if ( ($result [1]['x02_sperre'] == "f") OR // Ist der Satz schon in Bearbeitung (sperre == FALSE)?
     (($result [1]['x02_sperre'] == "t" ) AND // Satz gesperrt
      ($_SESSION ["vStab_kuerzel"] == $result[1][x03_sperruser])) ) { // Du bist der Bearbeiter

      // Setze den Eintrag auf " G E S P E R R T "
      $query = "UPDATE ".$conf_4f_tbl ["nachrichten"]." SET x02_sperre = \"t\", x03_sperruser = \"".$_SESSION [vStab_kuerzel]."\" where 00_lfd = ".$_GET["00_lfd"];
      $result = $dbaccess->query_table_iu ($query);
      // Jetzt holen wir uns den kompletten, gesperrten Eintrag
      $query = "SELECT * FROM ".$conf_4f_tbl ["nachrichten"]." where 00_lfd = ".$_GET["00_lfd"];
      $result = $dbaccess->query_table ($query);
      $formdata = $result [1]; // Das [1] enthaelt die Daten
      // Voreinstellungen fuer den Befoerderungsvermerk
      $formdata ["03_zeichen"]  = $_SESSION ["vStab_kuerzel"];

      if (sichter_online()) {
        $form = new nachrichten4fach ($formdata, "FM-Ausgang", "");
      } else {
        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
      //          $formdata ["16_empf"]     = "";
        $form = new nachrichten4fach ($formdata, "FM-Ausgang_Sichter", "");
      }
      } else {
      if (( $_SESSION ["vStab_kuerzel"] != $result[1][x03_sperruser] )){
       // Kruezel sind gleich
       echo "<a><big><big><big>Datensatz ist im Zugriff von <b>".$result[1][x03_sperruser]."!</b><br></big></big></big>";;
      }
    }
  }


/**********************************************************************\


\**********************************************************************/
  if ( isset ( $_GET["fm_admin_x"] ) ) {

    if ( debug == true ){ echo "### 611 FM Admin ";  echo "<br>\n";}

        $css = "a:link { color:#000000; text-decoration:none; font-weight:bold; }\n".
              "a:visited { color:#000000; text-decoration:none; font-weight:bold; }\n".
              "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }\n".
              "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }\n".
              "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
        pre_html ("si2liste","Stab lesen ".$conf_4f ["NameVersion"],$css); // Normaler Seitenaufbau mit Auffrischung
        echo "<body>";

        menue ();
        $list = new listen ("FMADMIN", "");
        $list->createlist ();
  }

  if ( isset ( $_GET["si_admin_x"] ) )  {

    if ( debug == true ){ echo "### 628 Sichter Admin ";  echo "<br>\n";}

        $css = "a:link { color:#000000; text-decoration:none; font-weight:bold; }\n".
              "a:visited { color:#000000; text-decoration:none; font-weight:bold; }\n".
              "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }\n".
              "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }\n".
              "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
        pre_html ("si2liste","Stab lesen ".$conf_4f ["NameVersion"],$css); // Normaler Seitenaufbau mit Auffrischung
        echo "<body>";

        menue ();
        $list = new listen ("SIADMIN", "");
        $list->createlist ();
  }


/**********************************************************************\


\**********************************************************************/
  if ( ( $_GET["fm"] == "FM-Adminmeldung" ) OR
       ( $_GET["fm"] == "SI-Adminmeldung" ) ) {

    if ( debug == true ){ echo "### 651 FM & Si Adminmeldung ";  echo "<br>\n";}

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["nachrichten"]."` where 00_lfd = ".$_GET["00_lfd"];
    $result = $dbaccess->query_table ($query);
    $formdata = $result [1];
    switch  ($_GET["fm"]) {
      case "FM-Adminmeldung" :
        $form = new nachrichten4fach ($formdata, "FM-Admin", "");
      break;

      case "SI-Adminmeldung" :
        $form = new nachrichten4fach ($formdata, "SI-Admin", "");
      break;
    }
  }

/**********************************************************************\

\**********************************************************************/
    // Anhang auswaehlen
  if ( ( isset ($_GET["fm_anhang_x"]) ) or ( isset ($_GET["stab_anhang_x"]) )  or
       ( $_SESSION ["UPLOAD"] == "fileselect" ) ){
    if ( debug == true ){
      echo "Zeile 429 Auswahlanhang<br>";
    }
    if ($_SESSION ["UPLOAD"] == "fileselect"){
      $_SESSION ["UPLOAD"] = "upload"; }
    include ("anhang.php");
    $menue1 = "anhang";
  }

/**********************************************************************\

\**********************************************************************/
    // Anhang auswaehlen
  if ( isset ( $_GET["ah_upload_x"] ) ){

    $_SESSION ["UPLOAD"] = "fileselect";

    if ( debug == true ){ echo "### 691 FM Admin - Anhang auswaehlen ";  echo "<br>\n";}

/*
    echo "<script type=\"text/javascript\">\n";
    echo "var Neufenster = window.open(\"./upload/upload.php\",\"AnderesFenster\",\"width=900,height=600, resizable=yes, menubar=no, scrollbars=yes\");\n";
    echo "</script>\n";
*/
//  include ("./upload/upload.php");

 header("Location: upload/upload.php");
  }


/**********************************************************************\

\**********************************************************************/
    // Anhang ausgewaelt und kann in Vordruck uebernommen werden
  if ( ($_SESSION ["vStab_rolle"]== "Fernmelder") and
       (isset ($_GET["ah_auswahl_x"])) ){

    if ( debug == true ){ echo "### 711 Anhang in Vordruck uebernehmen ";  echo "<br>\n";}


    $keys = array_keys ($_GET);
    $ahkey = array ();
    foreach ($keys as $key){
      list($lfd, $num) = split("_", $key);
      if ($lfd == "lfd") { $ahkey [] = "lfd_".$num;}
    }
    $anhang = "";
    foreach ($ahkey as $anh){
      $anhang .= $_GET [$anh].";";
    }
    $formdata ["12_anhang"]   = $anhang;
    $formdata ["12_inhalt"]  .= $anhang;
    $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
    $formdata ["10_anschrift"]  = $conf_4f ["anschrift"];
    if (sichter_online()) {
      $form = new nachrichten4fach ($formdata, "FM-Eingang_Anhang", "");
    } else {
      $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
      $formdata ["16_empf"]         = "";
      $form = new nachrichten4fach ($formdata, "FM-Eingang_Anhang_Sichter", "");
    }
  }


/**********************************************************************\

\**********************************************************************/
  if (isset ($_GET["m2_abmelden_x"])) {

    if ( debug == true ){ echo "### 743 m2_abmelden_x ";  echo "<br>\n";}

     $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
     //$query = "DELETE FROM `benutzer` WHERE `kuerzel` = \"".$_SESSION[vStab_kuerzel]."\"";
     $query = "UPDATE ".$conf_4f_tbl ["benutzer"]." SET
                   `aktiv` = \"0\",
                   `sid`   = \"\",
                   `ip`    = \"\"
               WHERE `kuerzel` = \"".$_SESSION["vStab_kuerzel"]."\";";
//  echo "<br>query===".$query."<br>";
     $result = $dbaccess->query_table_iu ($query);

     protokolleintrag ("Abmelden", $_SESSION[vStab_benutzer].";".$_SESSION[vStab_kuerzel].";".$_SESSION[vStab_funktion].";".$_SESSION[vStab_rolle].";".session_id().";".$_SERVER[REMOTE_ADDR]);

     // Session beenden - SESSION zurcksetzen -
     $_SESSION = array();
     if (isset($_COOKIE[session_name()])) {
       setcookie(session_name(), '', time()-42000, '/');
     }
     session_destroy ();
     //$_SESSION [menue] = "WELCOME";
     header("Location: ".$conf_4f ["MainURL"]);
     exit;
  } // isset ($_GET["m2auswahl"]


/**********************************************************************\

\**********************************************************************/
if ( ( !isset($form) ) and ( $menue1 != "anhang" ) and ( $menue1 != "upload" ) ) {
//echo "mainindex-menue<br>";

/*
  $css = "a:link { color:#000000; text-decoration:none; font-weight:bold; }\n".
        "a:visited { color:#EE0000; text-decoration:none; font-weight:bold; }\n".
        "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }\n".
        "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }\n".
        "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
  pre_html ("U_Liste60","Stab lesen ".$conf_4f ["NameVersion"],$css); // Normaler Seitenaufbau mit Auffrischung
  echo "<body>";*/

  menue ();
}

/**********************************************************************\

\**********************************************************************/
if ( ( isset ($_GET["m2_benutzer_x"])) OR
     ( $_SESSION ["menue"] == "WELCOME" ) OR
     ( $_SESSION ["menue"] == "LOGIN" ) )
  { benutzerstatus ("anzeigen"); }


if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}
/*


[16:23:20] Marc Rawer (FK EM S1/4) : -------- die übersicht von S1 wird nur beim drücken des buttons "lesen" aktualisiert - sollte automatisch geschehen
[16:23:55] Marc Rawer (FK EM S1/4) : -------- Visuelle oder besser noch akustische information über neue nachricht im eingang wäre nötig
[16:24:28] Marc Rawer (FK EM S1/4) : -------- farben sollten den farben des papierblocks an der entsprechenden Stelle entsprechen (FeMe Eingang)
[16:24:45] Marc Rawer (FK EM S1/4) : -------- Farben sollten konfigurierbar sein (thema 1- seite grün oder blau?)
[16:25:23] Marc Rawer (FK EM S1/4) : - Vorrangstufen: sollten irgendwo erklärt sein (linker frame, klein?) und konfigurierbar sein (bei uns: sofort/blitz)
[16:25:52] Marc Rawer (FK EM S1/4) : - sichter: blauen zettel beim S2 ankreuzen macht keinen Sinn - sollte nicht möglich sein
[16:26:58] Marc Rawer (FK EM S1/4) : - sichter: der radiobutton für grün sollte std-mässig beim S2 sein, damit grün (="kümmerer") auf jeden Fall vergeben wird. Sonst kann es zum Vergessen einer Meldung führen
[16:27:47] Marc Rawer (FK EM S1/4) : - Vorschlag für Sichter ansicht: rechten frame 2teilen:
oben: Neue Nachrichten (zum sichten)
unten: zweite Sichtung
[16:28:06] Marc Rawer (FK EM S1/4) : das wär mal alles soweit. sag mir welche ich davon ins forum aufnehmen soll, dann mach ich das heute abend


*/





?>
</body>
</html>
