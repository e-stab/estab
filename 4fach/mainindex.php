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

define ("debug", false);              // true = gibt debuginformationen aus

define ("create_vordrucke", true);   // Erstellt PDF oder/und PNG Dokumente fÃ¼r die RÃ¼ckfallebene

if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Steuerungsdatei</big></big><br>";  }

session_start ();
 
$returnValue = null ; // first set returnValue to a defined stat
if (count($_GET)>0)  { $returnValue = $_GET; }   // GET Daten, wenn vorhanden speichern
if (count($_POST)>0) { $returnValue = $_POST; }  // POST Daten, wenn vorhanden speichern

/*
$pre_01medium = "Fu";

Für Marc beim Southside Festival zum schnelleren Eintragen von Nachrichten
Weiter unten wird bei einem Nachrichteneingang das Medium Fe Fu Me Fax @
*/

if ( debug){
  echo "<br><br>\n";
  echo "returnValue="; var_dump ($returnValue);    echo "#<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}
// exit;
if (debug){
  error_reporting(E_ALL ^ E_NOTICE);
} else {
  error_reporting(E_ERROR | E_WARNING);
}

include ("../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben
include ("../4fcfg/dbcfg.inc.php");     // Datenbankparameter
include ("../4fcfg/fkt_rolle.inc.php"); // Mitspieler
include ("protokoll.php");              // Protokolllierung in der Datenbank
include ("db_operation.php");           // Datenbank operationen
include ("4fachform.php");              // Formular Behandlung 4fach Vordruck
include ("liste.php");                  // erzeuge Ausgabelisten
include ("data_hndl.php");              // Schnittstelle zur Datenbank

  $db = mysql_connect($conf_4f_db   ["server"],$conf_4f_db   ["user"], $conf_4f_db   ["password"] );
  mysql_query('SET NAMES utf8');
  $result = mysql_ping  ($db);

  if ($result == false){
    echo "<h1>Es besteht keine Verbindung zur Datenbank.</h1>";
    echo "<big><b>MÃ¶gliche Ursachen:<br></b>";
    echo " 1. Datenbankserver ist nicht erreichbar weil aus.<br>";
    echo " 2. Netzwerkfehler, wenn DB-Server auf anderem Server.<br>";
    echo " 3. Benutzer oder Passwort stimmen nicht.<br><br>";
    echo "Bitte unter \"administrative Massnahme\" - \"Datenbankparameter eingeben\" die Parameter einstellen.";
    echo "</big>";
    exit;
  }
  if (isset($db)){
    mysql_close($db);
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Close DB</big></big><br>";  }
  }

/**********************************************************************\
\**********************************************************************/
  function resetframeset ($rootpath) {
    pre_html ("reset","Framereset ".$conf_4f ["NameVersion"],""); // Normaler Seitenaufbau mit Auffrischung
    echo "<body onLoad=\"FramesVeraendern('".$rootpath."/4fach/counter.php','counter','".$rootpath."/4fach/vorgaben.php','vorgaben','".$rootpath."/4fach/mainindex.php','mainframe')\">";
  }


  if (isset ($returnValue ["reset_record"])){
    reset_record_lock ($returnValue ["reset_record"]);
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>eset Recordlock</big></big><br>";  }
  }


/**********************************************************************\
  Es gab noch keinen Kontakt ==> Begruessung
\**********************************************************************/
if (isset ( $returnValue ["m2_parameter_x"] )) {
  include ("./edt_para.php");
  $para = new parametrierung ();
}

/*******************************************************************
ANTWORT % WEITERLEITUNG
*******************************************************************/
  $weiterantwort = false;
  if ( ( (isset($returnValue["weiterleiten_x"])) or
         (isset($returnValue["antwort_x"])) ) and
         ( ( $returnValue["task"] == "FM-Ausgang" ) or
         ( $returnValue["task"] == "FM-Ausgang_Sichter" ) ) )  {
    $weiterantwort = true;
    $_SESSION ["sw_data"] = $returnValue ;
  } elseif (  (isset($returnValue ["abbrechen_x"]) and (isset ($_SESSION["sw_data"]))) and
              ( ( $returnValue["task"] == "FM-Eingang" ) or ( $returnValue["task"] == "FM-Eingang_Sichter" ) ) ) {
    unset ($_SESSION["sw_data"]);
  }

  /****************************************************************************\
    Kategorien
  \****************************************************************************/
    // Kategorie Master
  if (isset($returnValue ["ma_ktgotyp"])){
    if ( $returnValue ["ma_ktgo"] == "alle") {
      unset ($_SESSION ["ma_kategotyp"]);
      unset ($_SESSION ["ma_katego"]);
    } else {
      $_SESSION ["ma_kategotyp"] = $returnValue ["ma_ktgotyp"];
      $_SESSION ["ma_katego"]    = $returnValue ["ma_ktgo"];
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }
    // Kategorie FUNKTION
  if (isset($returnValue ["fk_ktgotyp"])){
    if ( $returnValue ["fk_ktgo"] == "alle") {
      unset ($_SESSION ["fk_kategotyp"]);
      unset ($_SESSION ["fk_katego"]);
    } else {
      $_SESSION ["fk_kategotyp"] = $returnValue ["fk_ktgotyp"];
      $_SESSION ["fk_katego"]    = $returnValue ["fk_ktgo"];
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }
    // Kategorie USER
  if (isset($returnValue ["us_ktgotyp"])){
    if ( $returnValue ["us_ktgo"] == "alle") {
      unset ($_SESSION ["us_kategotyp"]);
      unset ($_SESSION ["us_katego"]);
    } else {
      $_SESSION ["us_kategotyp"] = $returnValue ["us_ktgotyp"];
      $_SESSION ["us_katego"]    = $returnValue ["us_ktgo"];
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }


  if (isset ( $returnValue ["4fachkatego_absenden_x"])) {
    include ("../4fach/katgoedt.php");
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>katgoedt.php</big></big><br>";  }
  }


  // Aufruf von Anhang vom 4fach Vordruck aus ==> es könnte schon Inhalt im Formular vorhanden sein
  if ( isset ($returnValue["anhang_plus_x"])) {
    $_SESSION ["anhang_menue"] = "100";
    include ("anhang.php");
    exit;
  }

  /**********************************************************************\
    --- S T A B und F M Z   s c h r e i b e n   m i t  A n h a n g ---

    Oeffnet ein Fenster in dem Anhaenge ausgewaehlt werden koennen
  \**********************************************************************/
  if ( ( isset ( $returnValue["stab_anhang_x"] ) or  isset ( $returnValue["fm_anhang_x"] )
       ) and  ( !isset( $returnValue["ah_auswahl_x"] ) ) )  {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";} 
    $_SESSION ["anhang_menue"] = 100;
    include ("anhang.php");
    $menue1 = "anhang";
    exit;
  }




  /**********************************************************************\
    Überprüfe ob die Listendarstellung geändert werden soll
  \**********************************************************************/
  if (!isset ( $_SESSION["filter_darstellung"])){
    $_SESSION["filter_darstellung"] = 1;
    $_SESSION["filter_erledigt"]    = 0;
    $_SESSION["filter_unerledigt"]  = 1;
    $_SESSION["filter_anzahl"]      = 15;
    $_SESSION["filter_start"]       = 0 ;
    $_SESSION["filter_position"]    = 0;
  }
  // filtern EIN / AUS
  if ( (isset ($returnValue["filter_darstellung_aus_x"])) or
       (isset ($returnValue["filter_darstellung_ein_x"])) ){

    if ( ($_SESSION["filter_darstellung"] == 1) and (isset ($returnValue["filter_darstellung_aus_x"])) ) {
      $_SESSION["filter_darstellung"] = 0;
    } elseif ( ($_SESSION["filter_darstellung"] == 0) and (isset ($returnValue["filter_darstellung_ein_x"])) ){
      $_SESSION["filter_darstellung"] = 1;
    }
  }

  // erledigte SICHTBAR UNSICHTBAR
  if (( (isset ($returnValue["filter_erledigt_aus_x"])) or
       (isset ($returnValue["filter_erledigt_ein_x"])) ) AND !isset($returnValue["flt_search"])){

    if ( ($_SESSION["filter_erledigt"] == 1) and (isset($returnValue["filter_erledigt_aus_x"])) ) {
      $_SESSION["filter_erledigt"] = 0;
    } elseif ( ($_SESSION["filter_erledigt"] == 0) and (isset ($returnValue["filter_erledigt_ein_x"])) ){
      $_SESSION["filter_erledigt"] = 1;
    }
  }
  // unerledigte SICHTBAR UNSICHTBAR
  if (( (isset ($returnValue["filter_unerledigt_aus_x"])) or
       (isset ($returnValue["filter_unerledigt_ein_x"])) ) AND !isset($returnValue["flt_search"])){

    if ( ($_SESSION["filter_unerledigt"] == 1) and (isset($returnValue["filter_unerledigt_aus_x"])) ) {
      $_SESSION["filter_unerledigt"] = 0;
    } elseif ( ($_SESSION["filter_unerledigt"] == 0) and (isset ($returnValue["filter_unerledigt_ein_x"])) ){
      $_SESSION["filter_unerledigt"] = 1;
    }
  }

  // finde Menü Ein und Ausschalten des Findemenüs
  if ( (isset ($returnValue["flt_find_mask_aus_x"])) or
       (isset ($returnValue["flt_find_mask_ein_x"])) ){
    if ( ($_SESSION["flt_find_mask"] == 1) and (isset($returnValue["flt_find_mask_aus_x"])) ) {
      $_SESSION["flt_search"] = NULL;
      unset ($_SESSION["flt_search"]);
      $_SESSION["flt_find_mask"] = 0;
    } elseif ( ($_SESSION["flt_find_mask"] == 0) and (isset ($returnValue["flt_find_mask_ein_x"])) ){
      $_SESSION["flt_find_mask"] = 1;
    }
  }

  if (isset($returnValue["filter_suche_reset"])){ unset ($_SESSION["flt_search"]); }

  if (isset($returnValue["flt_search"]) AND ($_SESSION["flt_find_mask"] == 1)){
    if ($_SESSION["flt_search"] != $returnValue ["flt_search"]){
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
	  $_SESSION["flt_search"] = $returnValue ["flt_search"];
    } else {
      $_SESSION["flt_search"] = $returnValue ["flt_search"];
	}
  }

  if (isset($returnValue['filter_anzahl_x'])) { $_SESSION['filter_anzahl'] = $returnValue['filter_anzahl']; }
  if (isset($returnValue['flt_start_x'])) { $_SESSION['flt_navi'] = "start";}
  if (isset($returnValue['flt_back_x']))  { $_SESSION['flt_navi'] = "back";}
  if (isset($returnValue['flt_for_x']))   { $_SESSION['flt_navi'] = "for";}
  if (isset($returnValue['flt_end_x']))   { $_SESSION['flt_navi'] = "end";}

  /************************************************************************\

  \************************************************************************/
  if ( isset ($returnValue  ["action"]) ){
    // gelesen
    if ($returnValue  ["action"] == "gelesen")
      if ($returnValue ["todo"] == "set"){
        set_msg_read ( $returnValue["00_lfd"] );
      } else {
        unset_msg_read ( $returnValue["00_lfd"] );
      }
    // erledigt
    if ($returnValue ["action"] == "erledigt")
      if ($returnValue ["todo"] == "set"){
         set_msg_done ( $returnValue["00_lfd"] );
      } else {
        unset_msg_done ( $returnValue["00_lfd"] );
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
  if ( $returnValue["login"] == "Anmelden" )  {
    $_SESSION ["menue"] = "LOGIN"; }




  /**********************************************************************\
    Es kommen Anmeldedaten die geprueft und gespeichert werden muessen
  \**********************************************************************/
  $doppelkennwort = true;
  if (
     ((isset ($returnValue["benutzer"])) AND
      (isset ($returnValue["kuerzel"] )) AND
      (isset ($returnValue["funktion"])) AND
      ($_SESSION["menue"] == "LOGIN")) )
    // Es wurden beide Kennworte gesetzt
    if (
         ( ($returnValue["2teskennwort"] == "Yes") and
           (isset ($returnValue["kennwort1"])) and
           (isset ($returnValue["kennwort2"])) and
           ($returnValue["kennwort1"] != "") and
           ($returnValue["kennwort2"] != "") and
           ($returnValue["kennwort1"] == $returnValue["kennwort2"]) ) OR

         ( ($returnValue["2teskennwort"] != "Yes") AND
           (isset ($returnValue["kennwort1"])) )
       )  {
      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
      $error = check_save_user ();
      if (!$error) {
        $_SESSION["menue"] = "ROLLE";  //   führt zu Fehlern bei der Menüdarstellung
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
        resetframeset ($conf_urlroot.$conf_web["pre_path"]);
      }
    // Wenn Benutzer OK ==> SESSION ["menue"]="ROLLE" ; $_SESSION ["ROLLE"]= "Stab", Fernmelder...
  } else {
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
    if (  ($returnValue["2teskennwort"] == "Yes") and
          (isset ($returnValue["kennwort1"])) and
          (isset ($returnValue["kennwort2"])) and
          ($returnValue["kennwort1"] != "") and
          ($returnValue["kennwort2"] != "") and
          ($returnValue["kennwort1"] != $returnValue["kennwort2"]) ) {
          // Kennwort1 ungleich Kennwort2
      if (isset ($returnValue["benutzer"])) { $menuename     = $returnValue["benutzer"]; }
      if (isset ($returnValue["kuerzel"] )) { $menuekuerzel  = $returnValue["kuerzel"]; }
      if (isset ($returnValue["funktion"])) { $menuefunktion = $returnValue["funktion"]; }
      $doppelkennwort = true;
      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
    } else {
        if (isset ($returnValue["benutzer"])) { $menuename     = $returnValue["benutzer"]; }
        if (isset ($returnValue["kuerzel"] )) { $menuekuerzel  = $returnValue["kuerzel"]; }
        if (isset ($returnValue["funktion"])) { $menuefunktion = $returnValue["funktion"]; }
        $doppelkennwort = false;
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
    }
  }

  $gesprnotizsichter = false ; // false Voreinstellung fuer dieses Skript

/**********************************************************************
  Daten kommen vom Formular zurueck und koennen gespeichert bzw.
  verarbeitet werden.
  checkandsave befindet sich in data_hndl.php
***********************************************************************/

  // Abbruch der Gesprächsnotiz beim Sichten
  if ( ( $returnValue["abbrechen_x"] ) and ( $_SESSION ["gesprnoti"] ) ){
    unset ( $_SESSION ['gesprnoti'] );
  }


  if ( ( ( $returnValue["task"] == "Stab_schreiben" ) or
         ( $returnValue["task"] == "Stab_gesprnoti" ) or
         ( $returnValue["task"] == "FM-Ausgang" ) or
         ( $returnValue["task"] == "FM-Ausgang_Sichter" ) or
         ( $returnValue["task"] == "FM-Admin" ) or
         ( $returnValue["task"] == "FM-Eingang" ) or
         ( $returnValue["task"] == "FM-Eingang_Sichter" ) or
         ( $returnValue["task"] == "FM-Eingang_Anhang" ) or
         ( $returnValue["task"] == "FM-Eingang_Anhang_Sichter" ) or
         ( $returnValue["task"] == "Stab_sichten" ) or
         ( $returnValue["task"] == "SI-Admin" ) )  and (
         ( !isset ($returnValue["abbrechen_x"])) or 
            isset ($returnValue["antworten_x"]) or 
            isset ($returnValue["weiterleiten_x"])
         )
         ) {
    $returndata = $returnValue;

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> Daten kommen vom Formular und können gespeichert werden";  echo "<br>\n";}

    if ( ( $returnValue ["11_gesprnotiz"] == "on" ) and
         ( !$_SESSION ["gesprnoti"] ) and
         ( $returnValue ["task"] != "SI-Admin" ) and
         ( $returnValue ["task"] != "Stab_sichten" ) ){
        // Bei GesprÃ¤chsnotiz 2. Vorlage beim Verfasser fÃ¼r Sichtung

        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> ### Gesprächsnotiz == 2. Sichtung<br>\n";}

        $formdata = $returnValue ;
        $formdata ["01_zeichen"]      = $_SESSION ["vStab_kuerzel"];
        $formdata ["11_gesprnotiz"]   = "t";
        $formdata ["16_empf"]         = $redcopy2."_rt,".$_SESSION ["vStab_funktion"]."_gn" ;
        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
        $formdata ["task"]            = "Stab_gesprnoti";
        $form = new nachrichten4fach ($formdata, "Stab_gesprnoti", "");
        $_SESSION ["gesprnoti"] = true;
        $gesprnotizsichter = true ;
    } else {

      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### 369 check and save";  echo "<br>\n";}

      check_and_save ($returndata);

      // verhindert das erneute Speichern bei Betätigung von F5
      if (isset ($_SESSION ['gesprnoti'])) { unset ( $_SESSION ['gesprnoti'] ); }
      if (create_vordrucke){
      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> ";}
        include ("../4fbak/backup.php");
      }

      if ( !$weiterantwort ){
        resetframeset ($conf_urlroot.$conf_web ["pre_path"]);
      }
    }
  } elseif ( ( ($returnValue["task"] == "FM-Ausgang_Sichter") OR
               ($returnValue["task"] == "FM-Ausgang")
             ) and
             ($returnValue ["abbrechen_x"]) ) {

      /************************************************************************\
FM-Ausgang (Sichter) abgebrochen
      \************************************************************************/

       if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> ".(($returnValue[task] == FM-Ausgang_Sichter) and ($returnValue [abbrechen_x])) ;  echo "<br>\n";}

       $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
       $query = "SELECT `x02_sperre`,`x03_sperruser` FROM `".$conf_4f_tbl ["nachrichten"]."`
                 where `00_lfd` = ".$returnValue["00_lfd"];
       $result = $dbaccess->query_table ($query);

       if ( ($result [1]['x02_sperre'] == "f") OR // Ist der Satz schon in Bearbeitung (sperre == FALSE)?
           (($result [1]['x02_sperre'] == "t" ) AND // Satz gesperrt
            ($_SESSION ["vStab_kuerzel"] == $result[1][x03_sperruser])) ) { // Du bist der Bearbeiter

         $query = "UPDATE ".$conf_4f_tbl ["nachrichten"]."
                   SET x02_sperre = \"f\", x03_sperruser = \"\" where 00_lfd = ".$returnValue["00_lfd"];
         $result = $dbaccess->query_table_iu ($query);
       }
  }

/**********************************************************************\
Daten kommen vom Formular und sollen als Antwort dienen.
\**********************************************************************/

// A N T W O R T
  if ( ( isset ($returnValue["antwort_x"]) ) and ( $returnValue["task"] == "Stab_lesen" ) ) {
//  A N T W O R T  --  "Stab_lesen"

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### Antwort <br> ";}

    $formdata = $returnValue ;
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

// Weiterleitung
  if ( (isset ($returnValue["weiterleiten_x"])) and
       ($returnValue["task"] == "Stab_lesen") and
     ( ($returnValue ["04_richtung"] == "E") or
       ($returnValue ["04_richtung"] == "A") ) ) {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> ### 225 WEITERLEITUNG - Stab_lesen";  echo "<br>\n";}

    $formdata = $returnValue ;
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

     $formdata = $_SESSION ["sw_data"] ;
    if  (( isset ($formdata["antwort_x"]) ) and
        ( ( $formdata["task"] == "FM-Ausgang" ) or
          ( $formdata["task"] == "FM-Ausgang_Sichter" ) ) ) {

      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### antwort_x und FM_Ausgang(_Sichter) <br>\n";}
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
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";} 

      if (sichter_online()) {
        $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
      } else {
      	if ($conf_4f["si_in_out"] == true) { // Sichter alles oder nur Eingänge
        		$formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
        		$formdata ["16_empf"]         = "";
        		$form = new nachrichten4fach ($formdata, "FM-Eingang_Sichter", "");
    		} else {  // Sichter nur Eingänge
				$form = new nachrichten4fach ($formdata, "FM-Eingang", "");    		
    		} 
      }
    }

	 if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### Weiterleitung <br>";} 

    if ( (isset ($formdata["weiterleiten_x"])) and ( ($returnValue["task"] == "FM-Ausgang" ) or ($returnValue["task"] == "FM-Ausgang_Sichter") ) ) {

// W E I T E R L E I T U N G  --  "FM-Ausgang"  or "FM-Ausgang_Sichter"

      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> WEITERLEITUNG - FM-Ausgang(_Sichter)";  echo "<br>\n";}

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
  } //  if (isset ($_SESSION ["sw_data"] ))


/**********************************************************************
 Voreinstellung fuer das Menue
***********************************************************************/
if  ((isset ($_SESSION ["vStab_benutzer"])) AND
   (!(isset ($returnValue["m2auswahl"]))) ) {
   $mode = 2;
}

$formdata = ""; // setze die Formulardaten zurck

/**********************************************************************\
  --- S T A B  s c h r e i b e n ---
  Hier werden die Angaben:
    Abfasszeit, Absendeeinheit, Zeichen des Verfassers, Funktion
  der Stabsfunktion im Formular voreingestellt.
\**********************************************************************/

  if ( (isset ( $returnValue["stab_schreiben_x"] )) and !$gesprnotizsichter ) {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>  _GET[stab_schreiben_x] )) and !gesprnotizsichter ";  echo "<br>\n";}
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
          ( $returnValue ["stab"] != "meldung"
           ) and
          ( !(isset ($returnValue ["m2_benutzer_x"]
           )
         ) and
        (
          ( !(isset ($returnValue ["stab_schreiben_x"] ) ) ) and
          ( !$gesprnotizsichter ) and
          ( !(isset ($returnValue ["stab_anhang_x"] ) ) ) and
          ( !(isset ($returnValue ["fm_anhang_x"] ) ) ) and
          ( !(isset ($returnValue ["ah_auswahl_x"] ) ) ) and
          ( !(isset ($returnValue["m2_abmelden_x"] ) ) ) and
          ( !(isset ($returnValue["antwort_x"] ) ) ) and
          ( !(isset ($returnValue["weiterleiten_x"] ) ) ) and
          ( !(isset ($_SESSION["sw_data"] ) ) ) and
          ( $_SESSION ["UPLOAD"] != "fileupload" ) and
          ( $_SESSION ["UPLOAD"] != "upload" )
         )))
         ) {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>  Stab_lesen - Menue und Liste ";  echo "<br>\n";}

     $csskatego = "html { font-size: 100%; }
                a:link, a:visited, a:active {    text-decoration:    none;    color:              #333399; }
                a:hover { text-decoration:    underline; color:              #cc0000; }
                a:img {   border:             0; } 
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
  if (( $returnValue["stab"] == "meldung")){

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> Stab Meldung lesen - Darstellung der Meldung Ã¼ber die laufende Nummer ";  echo "<br>\n";}

    set_msg_read ($returnValue["00_lfd"]);
    $formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
    $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
  }


/**********************************************************************\
  --- S i c h t e r   M e l d u n g   s i c h t e n ---

  Darstellung der Meldung ueber die laufende Nummer
\**********************************************************************/
  if (( $returnValue["sichter"] == "meldung")){

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
    $formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
    $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
    $form = new nachrichten4fach ($formdata, "Stab_sichten", "");
  }

/***************************************************************************************************/
// 2. Sichtungsliste zurücksetzen, wenn sichten, FM_Ausgang,  angeklickt wurde
   if (((isset($returnValue['stab_sichten_x'])) AND 
            ($_SESSION ['si_zweite_sichtung'] == 1 )))			{ 
	  if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> reset SI 2. sichtung </b><br>";  }	
	  $_SESSION ["si_zweite_sichtung"] = 0 ;
	}
	
	if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }	
	if (((isset($returnValue['fm_ausgang_x'])) AND 
            ($_SESSION ['fm_zweite_sichtung'] == 1 )))			{ 
	  if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ." reset FM 2. sichtung </b><br>";  }	
	  $_SESSION ["fm_zweite_sichtung"] = 0 ;
	}
	
/***************************************************************************************************/  


/**********************************************************************\
  --- S i c h t e r   l e s e n  ---

  Menue und Liste
\**********************************************************************/
   if ( ( ($_SESSION ["vStab_rolle"] == "Stab") or
          ($_SESSION ["ROLLE"] == "Stab") ) and
        ( $_SESSION ["vStab_funktion"] == "Si" ) and
        ( !($returnValue["sichter"] == "meldung") ) and
        ( !(isset($returnValue["si_admin_x"])) ) and
        ( !(isset ($returnValue ["m2_abmelden_x"]) ) and 
        ( ($returnValue["fm"] != "SI-Adminmeldung") AND ($returnValue["fm"] != "SI-Adminmeldung") ) )
		and ($_SESSION ['si_zweite_sichtung'] == 0 )
      )
      {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ." Sichter lesen - Menue und liste </b><br>\n";}

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
      Es wurde der "Eingang"-Button beim Fernmelder betÃ¤tigt.
\**********************************************************************/
  if (isset ($returnValue["fm_eingang_x"])){
    if ($_SESSION["fm_zweite_sichtung"] == 1){$_SESSION["fm_zweite_sichtung"] = 0;}
    if ( debug == true ){ echo "### 730 Fernmelder Eingang ";  echo "<br>\n";}

//    if ($pre_01medium != "") { $formdata ["01_medium"]   = $pre_01medium;}

    $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
    $formdata ["10_anschrift"]  = $conf_4f ["anschrift"];

    if (sichter_online()) {
     $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
    } else {
     $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
     $formdata ["16_empf"]         = get_autosichter_targets("");
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
        !( ( isset ($returnValue ["fm_anhang_x"])  ) OR
           ( isset ($returnValue ["ah_upload_x"])  ) OR
           ( isset ($returnValue ["ah_auswahl_x"]) ) OR
		   ($_SESSION ["fm_zweite_sichtung"] == 1)
        ) and
        (!isset ($returnValue["m2_abmelden_x"])) and
        (!isset ($returnValue["fm_eingang_x"]) ) and
        (!isset ($returnValue["fm_admin_x"])   )and
        (!isset ($returnValue["etb_eintrag_x"])) and
        (!isset ($returnValue["antwort_x"])) and
        (!(isset ($_SESSION["sw_data"]))) and
        ( ($returnValue["fm"] != "FM-Adminmeldung") )and
        ( ($returnValue["fm"] != "SI-Adminmeldung") ) and
        ( ($returnValue["fm"]  != "FM-Adminmeldung") )and
        ( ($returnValue["fm"]  != "SI-Adminmeldung") ) and
        ( $returnValue["fm"]  != "meldung"
        )
      ) {
    
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### FM Ausgang - Menue und Liste <br>\n";}

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
  FM-Ausgang-Sichter
if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### FM Ausgang - Menue und Liste <br>\n";}
\**********************************************************************/
  if ( $returnValue["fm"] == "meldung" ){
  	
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ### FM - Ausgang  ";  echo "<br>\n";}

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT `x02_sperre`,`x03_sperruser` FROM `".$conf_4f_tbl ["nachrichten"]."` where 00_lfd = ".$returnValue["00_lfd"];
    $result = $dbaccess->query_table ($query);

    if ( ($result [1]['x02_sperre'] == "f") OR // Ist der Satz schon in Bearbeitung (sperre == FALSE)?
     (($result [1]['x02_sperre'] == "t" ) AND // Satz gesperrt
      ($_SESSION ["vStab_kuerzel"] == $result[1][x03_sperruser])) ) { // Du bist der Bearbeiter

      // Setze den Eintrag auf " G E S P E R R T "
      $query = "UPDATE ".$conf_4f_tbl ["nachrichten"]." SET x02_sperre = \"t\", x03_sperruser = \"".$_SESSION ["vStab_kuerzel"]."\" where 00_lfd = ".$returnValue["00_lfd"];
      $result = $dbaccess->query_table_iu ($query);
      // Jetzt holen wir uns den kompletten, gesperrten Eintrag
      $formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
      // Voreinstellungen fuer den Befoerderungsvermerk
      $formdata ["03_zeichen"]  = $_SESSION ["vStab_kuerzel"];

		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### <BR>";var_dump ($conf_4f);echo " <br>\n";}

      if (sichter_online()) {
        $form = new nachrichten4fach ($formdata, "FM-Ausgang", "");
      } else {  // Sichter nicht online
      	if ($conf_4f["si_in_out"] == true) { // Sichter alles oder nur Eingänge
	        $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
   	     $formdata ["16_empf"]  .= ",".get_autosichter_targets($formdata["14_funktion"]);
			  if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
  			  $form = new nachrichten4fach ($formdata, "FM-Ausgang_Sichter", "");
    		} else {  // Sichter nur Eingänge
				$form = new nachrichten4fach ($formdata, "FM-Ausgang", "");    		
    		}   			  
      }
    } else {
      if (( $_SESSION ["vStab_kuerzel"] != $result[1][x03_sperruser] )){
        // Kruezel sind gleich
        echo "<big><big><big>Datensatz ist im Zugriff von <b>".$result[1][x03_sperruser]."!</b><br></big></big></big>";
        echo "<br><br><br><br><br><br>";
        echo "!!!Achtung!!!<br>";
        echo "Datensatzfreischaltung nur auf Anordnung des Betriebsstellenleiters.<br>";
        echo"<a href=\"./mainindex.php?reset_record=".$returnValue["00_lfd"]."\">
             <img src=\"./createbutton.php?icontext=Datensatz freigeben&color=red\" alt=\"Datensatz freigeben\"></a>";
      }
    }
  }  //  if ( $returnValue["fm"] == "meldung" 

 
/**********************************************************************\
   2. SICHTUNG

\**********************************************************************/
	
  	if ( (isset ( $returnValue["fm_admin_x"] ) OR ( $_SESSION ["fm_zweite_sichtung"] == 1 ) )
   	and ($returnValue ["fm"] != "SI-Adminmeldung" ) ) {

		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ###  FM Admin <br>";  }
		
		$_SESSION ["fm_zweite_sichtung"] = 1 ;
    	$css = "a:link { color:#000000; text-decoration:none; font-weight:bold; }\n".
           "a:visited { color:#000000; text-decoration:none; font-weight:bold; }\n".
           "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }\n".
           "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }\n".
           "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
    	pre_html ("si2liste","Stab lesen ".$conf_4f ["NameVersion"],$css); // Normaler Seitenaufbau mit Auffrischung
    	echo "<body bgcolor=\"#DCDCFF\"\n><!-- 2. Sichtung -->\n";
    	$list = new listen ("FMADMIN", "");
    	$list->createlist ();
  }

  	if ( (isset ( $returnValue["si_admin_x"] ) OR ( $_SESSION ["si_zweite_sichtung"] == 1 ) )
    	and ($returnValue ["fm"] != "SI-Adminmeldung" ) )  {
    	
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ###  Sichter Admin ";  echo "<br>\n";}

    	$_SESSION ["si_zweite_sichtung"] = 1 ;
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
Nachricht als Sichtung anzeigen

\**********************************************************************/
  if ( ( $returnValue["fm"] == "FM-Adminmeldung" ) OR
       ( $returnValue["fm"] == "SI-Adminmeldung" )) {

		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ###  FM & Si Adminmeldung <br>\n";}

		$formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
		if (isset ($returnValue["fm"])) {$fm = $returnValue["fm"]; }
		if (isset ($returnValue ["fm"])) {$fm = $returnValue ["fm"]; }
    	switch  ($fm) {
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
  if (isset ($returnValue["m2_abmelden_x"])) {
    if ( debug == true ){ echo "### 907 m2_abmelden_x ";  echo "<br>\n";}

     include_once ("./logoff.php");

     $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
     $query = "UPDATE ".$conf_4f_tbl ["benutzer"]." SET
                   `aktiv` = \"0\",
                   `sid`   = \"\",
                   `ip`    = \"\"
               WHERE `kuerzel` = \"".$_SESSION["vStab_kuerzel"]."\";";
     $result = $dbaccess->query_table_iu ($query);
     protokolleintrag ("Abmelden", $_SESSION["vStab_benutzer"].";".$_SESSION["vStab_kuerzel"].";".$_SESSION["vStab_funktion"].";".$_SESSION["vStab_rolle"].";".session_id().";".$_SERVER["REMOTE_ADDR"]);

     // Session beenden - SESSION zurcksetzen -
     $_SESSION = array();
     if (isset($_COOKIE[session_name()])) {
       setcookie(session_name(), '', time()-42000, '/');
     }
     session_destroy ();

     $_SESSION ["menue"] = "WELCOME";
     resetframeset ($conf_urlroot.$conf_web ["pre_path"]);

  } // isset ($returnValue["m2_abmelden_x"]


  /**********************************************************************\

  \**********************************************************************/
  if ($_SESSION ["menue"] == "LOGIN" or $_SESSION ["menue"] == "WELCOME" ) {

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>";
    echo "<meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\" />\n";
    echo "</head>\n";

    echo "<body bgcolor=\"#DCDCFF\">";
    echo "<form action=\"".$conf_4f ["MainURL"]."\" method=\"GET\" target=\"mainframe\">\n";
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
              echo "</tr>\n<tr>\n";
              echo "<td>\nKürzel:</td>\n<td>\n<input style=\"font-size:20px; font-weight:900;\" type=\"text\" size=\"3\" maxlength=\"3\" value=\"".$menuekuerzel."\" name=\"kuerzel\"></td>\n";
              echo "</tr>\n<tr>\n";
              echo "<td>\nFunktion:</td>\n<td>\n<select style=\"font-size:20px; font-weight:900;\" name=\"funktion\">\n";
              for ($i=1; $i <= count ($conf_empf); $i++ ){
                if ($menuefunktion == $conf_empf[$i]["fkt"]){ $sel = "selected"; }else{ $sel = ""; }
                echo "<option ".$sel.">".$conf_empf[$i]["fkt"]."</option>\n";
              }
              echo "</select>\n";
              echo "</td>\n";
              echo "</tr>\n<tr>\n";

              echo "<td>Kennwort:</td>";
              echo "<td><input name=\"kennwort1\" type=\"password\" size=\"32\" maxlength=\"32\"></td>\n";
              if ( $doppelkennwort ) {
                echo "<input type=\"hidden\" name=\"2teskennwort\" value=\"Yes\">\n";
                echo "</tr>\n";
                echo "<tr>\n";
                echo "<td>Kennwort:</td>" ;
                echo "<td><input name=\"kennwort2\" type=\"password\" size=\"32\" maxlength=\"32\"></td>\n";
              }
              echo "<tr>";
                          echo "<td align=\"center\" bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/ok.gif\"></td>";
                          echo "<td>\n<a><img src=\"null.gif\" alt=\"leer\"></a>\n</td>\n";
                          echo "</tr>\n";

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

  }


/**********************************************************************\

\**********************************************************************/
if ( ( isset ($returnValue["m2_benutzer_x"])) OR
     ( $_SESSION ["menue"] == "WELCOME" ) OR
     ( $_SESSION ["menue"] == "LOGIN" ) )
  {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
  	 	benutzerstatus ("verlinkt");
	  
   }


if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}


?>
</body>
</html>