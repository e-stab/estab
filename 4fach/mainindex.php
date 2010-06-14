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

define ("debug", false);
define ("create_vordrucke", true);

session_start ();

if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}

if (debug){
  error_reporting(E_ALL ^ E_NOTICE); //E_ALL);
} else {
  error_reporting(FATAL | ERROR | WARNING);
}

include ("../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben
include ("../4fcfg/dbcfg.inc.php");    // Datenbankparameter
include ("../4fcfg/fkt_rolle.inc.php"); // Mitspieler
include ("protokoll.php");            // Protokolllierung in der Datenbank
include ("db_operation.php");  // Datenbank operationen
include ("4fachform.php");            // Formular Behandlung 4fach Vordruck
include ("liste.php");                // erzeuge Ausgabelisten
include ("data_hndl.php");            // Schnittstelle zur Datenbank

  $db = mysql_connect($conf_4f_db   ["server"],$conf_4f_db   ["user"], $conf_4f_db   ["password"] );
  $result = mysql_ping  ($db);

  if ($result == false){
    echo "<h1>Es besteht keine Verbindung zur Datenbank.</h1>";
    echo "<big><b>M&ouml;gliche Ursachen:<br></b>";
    echo " 1. Datenbankserver ist nicht erreichbar weil aus.<br>";
    echo " 2. Netzwerkfehler, wenn DB-Server auf anderem Server.<br>";
    echo " 3. Benutzer oder Passwort stimmen nicht.<br><br>";
    echo "Bitte unter \"administrative Massnahme\" - \"Datenbankparameter eingeben\" die Parameter einstellen.";
    echo "</big>";
    exit;
  }
  if (isset($db)){
    mysql_close($db);
  }

/**********************************************************************\
\**********************************************************************/


  function resetframeset ($rootpath) {
    pre_html ("reset","Framereset ".$conf_4f ["NameVersion"],""); // Normaler Seitenaufbau mit Auffrischung
    echo "<body onLoad=\"FramesVeraendern('".$rootpath."/4fach/counter.php','counter','".$rootpath."/4fach/vorgaben.php','vorgaben','".$rootpath."/4fach/mainindex.php','mainframe')\">";
    exit;
  }


  if (isset ($_GET ["reset_record"])){
    reset_record_lock ($_GET ["reset_record"]);
  }


/**********************************************************************\
  Es gab noch keinen Kontakt ==> Begruessung
\**********************************************************************/
if (isset ( $_GET ["m2_parameter_x"] )) {
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

  /****************************************************************************\
    Für die Listendarstellung erforderliche Einstellungen
  \****************************************************************************/
  if (isset($_GET ["ma_ktgotyp"])){
    if ( $_GET ["ma_ktgo"] == "alle") {
      unset ($_SESSION ["ma_kategotyp"]);
      unset ($_SESSION ["ma_katego"]);
    } else {
      $_SESSION ["ma_kategotyp"] = $_GET ["ma_ktgotyp"];
      $_SESSION ["ma_katego"]    = $_GET ["ma_ktgo"];
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }
  if (isset($_GET ["us_ktgotyp"])){
    if ( $_GET ["us_ktgo"] == "alle") {
      unset ($_SESSION ["us_kategotyp"]);
      unset ($_SESSION ["us_katego"]);
    } else {
      $_SESSION ["us_kategotyp"] = $_GET ["us_ktgotyp"];
      $_SESSION ["us_katego"]    = $_GET ["us_ktgo"];
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }


  if (isset ( $_GET ["4fachkatego_absenden_x"])) {
    include ("../4fach/katgoedt.php");
    exit;
  }


  // Aufruf von Anhang vom 4fach Vordruck aus ==> es könnte schon Inhalt im Vormular vorhanden sein
  if ( isset ($_GET["anhang_plus_x"])) {
    $_SESSION ["anhang_menue"] = "100";
    // header("Location: ".$_SERVER ["DOCUMENT_ROOT"]."/4fach/anhang.php");
    include ("anhang.php");
    exit;
  }

  /**********************************************************************\
    --- S T A B und F M Z   s c h r e i b e n   m i t  A n h a n g ---

    Oeffnet ein Fenster in dem Anhaenge ausgewaehlt werdn koennen
  \**********************************************************************/
  if ( ( isset ( $_GET["stab_anhang_x"] ) or  isset ( $_GET["fm_anhang_x"] )
       ) and  ( !isset( $_GET["ah_auswahl_x"] ) ) )  {

      if ( debug == true ){ echo "### mainindex 547 ( _GET[stab_anhang_x] ) or isset ( _GET[fm_anhang_x] ) ) and ( !isset( _GET[ah_auswahl_x] ) ";  echo "<br>\n";}
    $_SESSION [anhang_menue] = 100;
    include ("anhang.php");
    $menue1 = "anhang";
    exit;
  }

  /**********************************************************************\
    Überprüfe ob die Listendarstellung geaendert werden soll
  \**********************************************************************/
  if (!isset ( $_SESSION["filter_darstellung"])){
    $_SESSION["filter_darstellung"] = 1;
    $_SESSION["filter_erledigt"]    = 0;
    $_SESSION["filter_unerledigt"]  = 1;
    $_SESSION["filter_anzahl"]      = 5;
    $_SESSION["filter_start"]       = 0 ;
    $_SESSION["filter_position"]    = 0;
  }
  // filtern EIN / AUS
  if ( (isset ($_GET["filter_darstellung_aus_x"])) or
       (isset ($_GET["filter_darstellung_ein_x"])) ){

    if ( ($_SESSION["filter_darstellung"] == 1) and (isset ($_GET["filter_darstellung_aus_x"])) ) {
      $_SESSION["filter_darstellung"] = 0;
    } elseif ( ($_SESSION["filter_darstellung"] == 0) and (isset ($_GET["filter_darstellung_ein_x"])) ){
      $_SESSION["filter_darstellung"] = 1;
    }
  }

  // erledigte SICHTAR UNSICHTBAR
  if ( (isset ($_GET["filter_erledigt_aus_x"])) or
       (isset ($_GET["filter_erledigt_ein_x"])) ){

    if ( ($_SESSION["filter_erledigt"] == 1) and (isset($_GET["filter_erledigt_aus_x"])) ) {
      $_SESSION["filter_erledigt"] = 0;
    } elseif ( ($_SESSION["filter_erledigt"] == 0) and (isset ($_GET["filter_erledigt_ein_x"])) ){
      $_SESSION["filter_erledigt"] = 1;
    }
  }
  // unerledigte SICHTBAR UNSICHTBAR
  if ( (isset ($_GET["filter_unerledigt_aus_x"])) or
       (isset ($_GET["filter_unerledigt_ein_x"])) ){

    if ( ($_SESSION["filter_unerledigt"] == 1) and (isset($_GET["filter_unerledigt_aus_x"])) ) {
      $_SESSION["filter_unerledigt"] = 0;
    } elseif ( ($_SESSION["filter_unerledigt"] == 0) and (isset ($_GET["filter_unerledigt_ein_x"])) ){
      $_SESSION["filter_unerledigt"] = 1;
    }
  }

  // finde Menü
  if ( (isset ($_GET["flt_find_mask_aus_x"])) or
       (isset ($_GET["flt_find_mask_ein_x"])) ){

    if ( ($_SESSION["flt_find_mask"] == 1) and (isset($_GET["flt_find_mask_aus_x"])) ) {
      unset ($_SESSION["flt_search"]);
      $_SESSION["flt_find_mask"] = 0;
    } elseif ( ($_SESSION["flt_find_mask"] == 0) and (isset ($_GET["flt_find_mask_ein_x"])) ){
      $_SESSION["flt_find_mask"] = 1;
    }
  }


  if (isset($_GET["filter_suche_reset"])){ unset ($_SESSION["flt_search"]); }

  if (isset($_GET["filter_suche"])){
    if ($_SESSION["flt_search"] != $_GET ["flt_search"]){
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
    $_SESSION["flt_search"] = $_GET ["flt_search"];
  }

  if (isset ($_GET["filter_anzahl_x"])) {
    $_SESSION["filter_anzahl"] = $_GET["filter_anzahl"]; }
    if (isset($_GET[flt_start_x])) { $_SESSION[flt_navi] = "start";}
    if (isset($_GET[flt_back_x]))  { $_SESSION[flt_navi] = "back";}
    if (isset($_GET[flt_for_x]))   { $_SESSION[flt_navi] = "for";}
    if (isset($_GET[flt_end_x]))   { $_SESSION[flt_navi] = "end";}


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
        //echo "!!! erledigt set !!!<br>";
        set_msg_done ( $_GET["00_lfd"] );
      } else {
        //echo "!!! erledigt unset !!!<br>";
        unset_msg_done ( $_GET["00_lfd"] );
      }
  }



  /**********************************************************************\
    Es gab noch keinen Kontakt ==> Begruessung
  \**********************************************************************/
  if (!isset ( $_SESSION ["menue"] ))
     { $_SESSION ["menue"] = "WELCOME"; }

  /**********************************************************************\
    Der Anmelde Button wurde gedrueckt
  \**********************************************************************/
  if ( $_GET["login"] == "Anmelden" )  {
    $_SESSION ["menue"] = "LOGIN"; }

  /**********************************************************************\
    Es kommen Anmeldedaten die geprueft und gespeichert werden muessen
  \**********************************************************************/
  $doppelkennwort = true;
  if (
     ((isset ($_GET["benutzer"])) AND
      (isset ($_GET["kuerzel"] )) AND
      (isset ($_GET["funktion"])) AND
      ($_SESSION["menue"] == "LOGIN")) )
    // Es wurden beide Kennworte gesetzt
    if (
         ( ($_GET["2teskennwort"] == "Yes") and
           (isset ($_GET["kennwort1"])) and
           (isset ($_GET["kennwort2"])) and
           ($_GET["kennwort1"] != "") and
           ($_GET["kennwort2"] != "") and
           ($_GET["kennwort1"] == $_GET["kennwort2"]) ) OR

         ( ($_GET["2teskennwort"] != "Yes") AND
           (isset ($_GET["kennwort1"])) )
       )  {
      if (debug) echo "mainindex 320 <br> ";
      $error = check_save_user ();
      if (!$error) {
        $_SESSION["menue"] = "ROLLE";  //   führt zu fehlern bei der menüdarstellung
        if (debug) echo "mainindex 324 <br> ";
        resetframeset ($conf_urlroot.$conf_web ["pre_path"]);
      }
    // Wenn Benutzer OK ==> SESSION [menue]=ROLLE ; $_SESSION [ROLLE]= Stab, Fernmelder...
  } else {
    if (debug) echo "mainindex 330 <br> ";
    if (  ($_GET["2teskennwort"] == "Yes") and
          (isset ($_GET["kennwort1"])) and
          (isset ($_GET["kennwort2"])) and
          ($_GET["kennwort1"] != "") and
          ($_GET["kennwort2"] != "") and
          ($_GET["kennwort1"] != $_GET["kennwort2"]) ) {
          // Kennwort1 ungleich Kennwort2
      if (isset ($_GET["benutzer"])) { $menuename     = $_GET["benutzer"]; }
      if (isset ($_GET["kuerzel"] )) { $menuekuerzel  = $_GET["kuerzel"]; }
      if (isset ($_GET["funktion"])) { $menuefunktion = $_GET["funktion"]; }
      $doppelkennwort = true;
      if (debug) echo "mainindex 342 <br> ";
    } else {
        if (isset ($_GET["benutzer"])) { $menuename     = $_GET["benutzer"]; }
        if (isset ($_GET["kuerzel"] )) { $menuekuerzel  = $_GET["kuerzel"]; }
        if (isset ($_GET["funktion"])) { $menuefunktion = $_GET["funktion"]; }
        $doppelkennwort = false;
        if (debug) echo "mainindex 349 <br> ";
    }
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

    if ( debug == true ){ echo "### 388 Daten kommen vom Formular und können gespeichert werden";  echo "<br>\n";}

    if ( ( $_GET ["11_gesprnotiz"] == "on" ) and
         ( !$_SESSION ["gesprnoti"] ) and
         ( $_GET ["task"] != "SI-Admin" ) and
         ( $_GET ["task"] != "Stab_sichten" ) ){
        // Bei Gesprächsnotiz 2. Vorlage für Sichtung

        if ( debug == true ){ echo "### Gesprächsnotiz == 2. Sichtung";  echo "<br>\n";}

        $formdata = $_GET ;
        $formdata ["01_zeichen"]      = $_SESSION ["vStab_kuerzel"];
        $formdata ["11_gesprnotiz"]   = "t";
        $formdata ["16_empf"]         = $redcopy2."_rt,".$_SESSION ["vStab_funktion"]."_gn" ;
        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
        $formdata ["task"]            = "Stab_gesprnoti";
        $form = new nachrichten4fach ($formdata, "Stab_gesprnoti", "");
        $_SESSION ["gesprnoti"] = true;
        $gesprnotizsichter = true ;
    } else {

      if ( debug == true ){ echo "### 369 check and save";  echo "<br>\n";}

      check_and_save ($returndata);

      // verhindert das erneute Speichern bei Betaetigung von F5
      if (isset ($_SESSION ['gesprnoti'])) { unset ( $_SESSION ['gesprnoti'] ); }
      if (create_vordrucke){
        if ( debug == true ){ echo "### 376 create vordruck";  echo "<br>\n";}
        include ("../4fbak/backup.php");
      }

      if ( !$weiterantwort ){
        resetframeset ($conf_urlroot.$conf_web ["pre_path"]);
      }
    }
  } elseif ( ( ($_GET["task"] == "FM-Ausgang_Sichter") OR
               ($_GET["task"] == "FM-Ausgang")
             ) and
             ($_GET ["abbrechen_x"]) ) {

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
    $formdata ["12_abfzeit"] = "" ;
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
    $formdata ["11_gesprnotiz"] = "";
    $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"];
    $formdata ["12_abfzeit"] = "" ;
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }

  if (isset ($_SESSION ["sw_data"] )) {

    if ( debug == true ){ echo "### 461 _SESSION [sw_data]";  echo "<br>\n";}
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

      $formdata ["12_abfzeit"] = "" ;

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

      $formdata ["12_abfzeit"] = "" ;

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
  }


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
    $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"];
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }




/**********************************************************************\
  --- S T A B   l e s e n  ---

  Menue und Liste
\**********************************************************************/
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
         )))
         ) {

    if ( debug == true ){ echo "### 665 Stab_lesen - Menue und Liste ";  echo "<br>\n";}

     $csskatego = "html { font-size: 100%; }
                a:link, a:visited, a:active {    text-decoration:    none;    color:              #333399; }
                a:hover { text-decoration:    underline; color:              #cc0000; }
                a img {   border:             0; }
        /******************************************************************************/
        /* specific elements */
        /* topmenu */
        ul#topmenu { font-weight:bold; list-style-type:none; margin:0; padding:0; }
        ul#topmenu li { float:left; margin:0; padding:0; vertical-align: middle; }
          #topmenu img {vertical-align:middle; margin-right:0.1em; }

        /* default tab styles */
        .tab,
        .tabcaution,
        .tabactive {display: block; margin: 0.2em 0.2em 0 0.2em; padding: 0.2em 0.2em 0 0.2em; white-space: nowrap; }

        /* disabled tabs */
        span.tab {color: #666666; }

        /* disabled drop/empty tabs */
        span.tabcaution { color: #ff6666; }

        /* enabled drop/empty tabs */
        a.tabcaution {color:  #FF0000;  }
        a.tabcaution:hover { color: #FFFFFF; background-color:   #FF0000; }

        #topmenu { margin-top: 0.5em; padding: 0.1em 0.3em 0.1em 0.3em; }

ul#topmenu li {
    border-bottom:      1pt solid black;
}

/* default tab styles */
.tab, .tabcaution, .tabactive {
    background-color:   #E5E5E5;
    border:             1pt solid #D5D5D5;
    border-bottom:      0;
    border-top-left-radius: 0.4em;
    border-top-right-radius: 0.4em;
}

/* enabled hover/active tabs */
a.tab:hover,
a.tabcaution:hover,
.tabactive,
.tabactive:hover {
    margin:             0;
    padding:            0.2em 0.4em 0.2em 0.4em;
    text-decoration:    none;
}

a.tab:hover,
.tabactive {
    background-color:   #ffffff;
}

/* to be able to cancel the bottom border, use <li class=\"active\"> */
ul#topmenu li.active {
     border-bottom:      1pt solid #ffffff;
}";


      pre_html ("stabliste","Stab lesen ".$conf_4f ["NameVersion"],$css.$csskatego); // Normaler Seitenaufbau mit Auffrischung
      echo "<body bgcolor=\"#DCDCFF\">";
      $list = new listen ("Stab_lesen", "");
      $list->createlist ();
   }

/**********************************************************************\
  --- S T A B   M e l d u n g   l e s e n ---

  Darstellung der Meldung ber die laufende Nummer
\**********************************************************************/
  if (( $_GET["stab"] == "meldung")){

    if ( debug == true ){ echo "### 753 Stab Meldung lesen - Darstellung der Meldung ber die laufende Nummer ";  echo "<br>\n";}

    set_msg_read ($_GET["00_lfd"]);
    $formdata = get_msg_by_lfd ($_GET["00_lfd"]);
    $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
  }


/**********************************************************************\
  --- S i c h t e r   M e l d u n g   s i c h t e n ---

  Darstellung der Meldung ueber die laufende Nummer
\**********************************************************************/
  if (( $_GET["sichter"] == "meldung")){

    if ( debug == true ){ echo "### 458 Stab Meldung sichten - Darstellung der Meldung ber die laufende Nummer ";  echo "<br>\n";}
    $formdata = get_msg_by_lfd ($_GET["00_lfd"]);
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
        echo "<body bgcolor=\"#DCDCFF\">";
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
    echo "<body bgcolor=\"#DCDCFF\">";
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
      $formdata = get_msg_by_lfd ($_GET["00_lfd"]);

      // Voreinstellungen fuer den Befoerderungsvermerk
      $formdata ["03_zeichen"]  = $_SESSION ["vStab_kuerzel"];

      if (sichter_online()) {
        $form = new nachrichten4fach ($formdata, "FM-Ausgang", "");
      } else {
        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
        $form = new nachrichten4fach ($formdata, "FM-Ausgang_Sichter", "");
      }
      } else {
      if (( $_SESSION ["vStab_kuerzel"] != $result[1][x03_sperruser] )){
        // Kruezel sind gleich
        echo "<big><big><big>Datensatz ist im Zugriff von <b>".$result[1][x03_sperruser]."!</b><br></big></big></big>";
        echo "<br><br><br><br><br><br>";
        echo "!!!Achtung!!!<br>";
        echo "Datensatzfreischaltung nur auf Anordnung des Betriebsstellenleiters.<br>";
        echo"<a href=\"./mainindex.php?reset_record=".$_GET["00_lfd"]."\">
             <img src=\"./createbutton.php?icontext=Datensatz freigeben&color=red\" alt=\"Datensatz freigeben\"></a>";
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
        echo "<body bgcolor=\"#DCDCFF\">";
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
        echo "<body bgcolor=\"#DCDCFF\">";
        $list = new listen ("SIADMIN", "");
        $list->createlist ();
  }



/**********************************************************************\


\**********************************************************************/
  if ( ( $_GET["fm"] == "FM-Adminmeldung" ) OR
       ( $_GET["fm"] == "SI-Adminmeldung" ) ) {

    if ( debug == true ){ echo "### 651 FM & Si Adminmeldung ";  echo "<br>\n";}

    $formdata = get_msg_by_lfd ($_GET["00_lfd"]);
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
   A B M E L D E N
\**********************************************************************/
  if (isset ($_GET["m2_abmelden_x"])) {
    if ( debug == true ){ echo "### 743 m2_abmelden_x ";  echo "<br>\n";}

     include_once ("./logoff.php");

     $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
     $query = "UPDATE ".$conf_4f_tbl ["benutzer"]." SET
                   `aktiv` = \"0\",
                   `sid`   = \"\",
                   `ip`    = \"\"
               WHERE `kuerzel` = \"".$_SESSION["vStab_kuerzel"]."\";";
     $result = $dbaccess->query_table_iu ($query);
     protokolleintrag ("Abmelden", $_SESSION[vStab_benutzer].";".$_SESSION[vStab_kuerzel].";".$_SESSION[vStab_funktion].";".$_SESSION[vStab_rolle].";".session_id().";".$_SERVER[REMOTE_ADDR]);

     // Session beenden - SESSION zurcksetzen -
     $_SESSION = array();
     if (isset($_COOKIE[session_name()])) {
       setcookie(session_name(), '', time()-42000, '/');
     }
     session_destroy ();

     $_SESSION [menue] = "WELCOME";
     resetframeset ($conf_urlroot.$conf_web ["pre_path"]);

  } // isset ($_GET["m2auswahl"]


  /**********************************************************************\

  \**********************************************************************/
  if ($_SESSION ["menue"] == "LOGIN" or $_SESSION ["menue"] == "WELCOME" ) {

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "</head>\n";

    echo "<body bgcolor=\"#DCDCFF\">";
    echo "<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
    echo "<!-- Formularelemente und andere Elemente innerhalb des Formulars -->\n";

    echo "<table border=\"1\" cellspacing=\"1\" cellpeding=\"1\">\n";
    echo "<tbody>";
    echo "<tr>\n";
    echo "<td>\n";

    echo "<table border=\"1\" cellspacing=\"1\" cellpeding=\"1\">\n";
    echo "<tbody>";
    echo "<tr>\n";
    switch ($_SESSION ["menue"]) {
      case "WELCOME" : // nicht angemeldet ==> nur login Button
               echo "<td>\n";
               foreach ( $conf_4f ['NameVersion'] as $titel ) {
                 echo $titel;
               }
               echo "</td>\n";
               echo "</tr>\n<tr>\n";
      break;
      case "LOGIN" : // Anmeldeformular
              echo "<td>\nName, Vorname:</td>\n<td>\n<input style=\"font-size:20px; font-weight:900;\" type=\"text\" size=\"32\" value=\"".$menuename."\" maxlength=\"32\" name=\"benutzer\"></td>\n";
              echo "<td>\n<a><img src=\"null.gif\" alt=\"leer\"></a>\n</td>\n";
              echo "</tr>\n<tr>\n";
              echo "<td>\nK&uuml;rzel:</td>\n<td>\n<input style=\"font-size:20px; font-weight:900;\" type=\"text\" size=\"3\" maxlength=\"3\" value=\"".$menuekuerzel."\" name=\"kuerzel\"></td>\n";
              echo "<td>\n<a><img src=\"null.gif\" alt=\"leer\"></a>\n</td>\n";
              echo "</tr>\n<tr>\n";
              echo "<td>\nFunktion:</td>\n<td>\n<select style=\"font-size:20px; font-weight:900;\" name=\"funktion\">\n";
              for ($i=1; $i <= count ($conf_empf); $i++ ){
                if ($menuefunktion == $conf_empf[$i]["fkt"]){ $sel = "selected"; }else{ $sel = ""; }
                echo "<option ".$sel.">".$conf_empf[$i]["fkt"]."</option>\n";
              }
              echo "</select>\n";
              echo "</td>\n";
              echo "<td>\n<a><img src=\"null.gif\" alt=\"leer\"></a>\n</td>\n";
              echo "</tr>\n<tr>\n";

              echo "<td>";
              echo "Kennwort:" ;
              echo "</td><td>";
              echo "<input name=\"kennwort1\" type=\"password\" size=\"32\" maxlength=\"32\">";
              echo "</td>\n";
              if (  //($menuename == "") and ($menuekuerzel == "") and ($menuefunktion == "")){
                   $doppelkennwort ) {
                echo "<td>\n<a><img src=\"null.gif\" alt=\"leer\"></a>\n</td>\n";

                echo "<input type=\"hidden\" name=\"2teskennwort\" value=\"Yes\">\n";
                echo "</tr>\n<tr>\n";
                echo "<td>";
                echo "Kennwort:" ;
                echo "</td><td>";
                echo "<input name=\"kennwort2\" type=\"password\" size=\"32\" maxlength=\"32\">";
                echo "</td>\n";
              }
              echo "<td>\n<input type=\"submit\" name=\"anmelden\" value=\"Anmelden\">\n";
              echo "</td>\n";

      break;
    }
    echo "</tr>\n";
    echo "</tbody>";
    echo "</table>";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>";
    echo "</table>";
    echo "</form>";

//    $_SESSION["menue"] = "NORMAL";

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

?>
</body>
</html>
