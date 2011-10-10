<?php
/*****************************************************************************\
   Datei: config.inc.php

   benoetigte Dateien:

   Beschreibung:
     Zentrale Konfigurationsdatei

   (C) 2006-2008 Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

/******************************************************************************\
   Allgemeine Einstellunge

\******************************************************************************/

    if (file_exists ( "d_cfg.inc.php" )) REQUIRE "d_cfg.inc.php" ;
    if (file_exists ( "./4fcfg/d_cfg.inc.php" )) REQUIRE "./4fcfg/d_cfg.inc.php" ;
    if (file_exists ( "../4fcfg/d_cfg.inc.php" )) REQUIRE "../4fcfg/d_cfg.inc.php" ;
    /*
      Sollen Klänge übermittelt und abgespielt werden können ?
        true  : Klänge werden als objekt übertragen und können abgespielt werden.
        false : es werden keine Klänge übermittelt;
    */
    $conf_4f["sounds"] = true ;

    /* getrennt : Eingang und Ausgang zählen für sich
      gemeinsam : Eingang und Ausgang zählen zusammen
    */
    if (defined("Nachweisung") == false ) define ("Nachweisung", "gemeinsam");

    /*
    */
    if (defined("posttakzeit") == false ) define ("posttakzeit","true");

      // URL des Servers
    $conf_urlroot  = "http://".$_SERVER ["SERVER_NAME"].":".$_SERVER ["SERVER_PORT"]."/";

      // Wurzelverzeichnis fr den Webserver
      // unter Linux /srv/www/htdocs
      // unter Windows C:\Progra~1\Apache\htdocs
      // u.U. muss mit dir /X der Kurzname 8.3 ermittelt und eingetragen werden
    $conf_web ["srvroot"]  = $_SERVER ["DOCUMENT_ROOT"];

      // URL bis zum Kats-Verzeichnis
      // z.B. http://localhost/Einsatzleitung
      // http://192.168.100.1/Einsatzlt
      // http://www.leitstelle.de/ELStab
      // u.s.w. */
    $pre_url = $conf_urlroot.$conf_web["pre_path"] ;

      // Design fuer Buttons
    $conf_design           = "HS";


    $conf_menue ["symbole"] = $conf_urlroot.$conf_web["pre_path"]."4fsym/";

/*******************   nicht aendern ; do not change !!! ***********************/

    $conf_design_path      = $conf_urlroot. // $conf_web ["srvroot"]. // $conf_urlroot.
                             $conf_web ["pre_path"].
                               "4fach/design/".$conf_design ;

//echo "<big>".$conf_design_path."</big>";

    $conf_design_URI       = $pre_url.
                               $conf_web ["pre_path"].
                               "4fach/design/".$conf_design;

/********** Die nachfolgenden Zeilen duerfen nicht geaendert werden !!!  *********/
/*********               Do not change the folowing lines               **********/
    $conf_4f ["Titelkurz"]        =  "eStab";
    $conf_4f ["SubTitel"]["env"]  =  " - elektronischer Nachrichtenvordruck";
    $conf_4f ["SubTitel"]["etb"]  =  "Einsatztagebuch";
    $conf_4f ["Version"]          =  "v0.9.20 23.07.2011 nach FKT-Katego";
      // Hier kann die eigene Dienststelle eingetragen werden Zeilenumbruch mit <br>
    $conf_4f ["Stelle"]           =  "Einsatzleitung Kreis Heinsberg" ;
      // Programm information und Versionsnummer
    $conf_4f ["NameVersion"][0]   = "<big><big><b>".$conf_4f ["Titelkurz"]." ".
                                    $conf_4f ["SubTitel"]["env"]."</b><br>".
                                    $conf_4f ["Version"]."</big></big><br><br><b><big><big><big>".
                                    $conf_4f ["Stelle"]."</big></big></big></b><br><br>\n";


    $conf_4f ["NameVersion"][1]   = "<b>Ein Programm der IuK Kreis Heinsberg</b><br>\n";
    $conf_4f ["NameVersion"][2]   = "Nachrichtenvordruck Stab-Modul      <br>\n";
    $conf_4f ["NameVersion"][3]   = "Nachrichtenvordruck Fernmelde-Modul <br>\n";
    $conf_4f ["NameVersion"][4]   = "Nachrichtenvordruck Sichter-Modul   <br>\n";
    $conf_4f ["NameVersion"][5]   = "Administrationsmodul                <br>\n";
    $conf_4f ["NameVersion"][6]   = "Editor Empf&auml;ngermatix          <br>\n";
    $conf_4f ["NameVersion"][7]   = "Nachweisung Eingang / Ausgang       <br>\n";
    $conf_4f ["NameVersion"][8]   = "ETB Einsatztagebuch                 <br>\n";
    $conf_4f ["NameVersion"][9]   = "Kategorisierung                     <br>\n";
    $conf_4f ["NameVersion"][10]  = "Nachrichtenvordrucke als PDF-Datei  <br>\n";
    $conf_4f ["NameVersion"][11]  = "lade-/speicherbare Funktionsmatrix Teil 1 <br>\n";

    $conf_4f ["NameVersion"][12]  = "(C) 2005-2011 HaJo Landmesser<br>eMail: info@eStab.de <br>\n";
    $conf_4f ["NameVersion"][13]  = "Infos, Forum unter  http://www.eStab.de <br>\n";


/*******************************************************************************/
      // Datenverzeichnis
    $conf_4f ["data"]     = "4fdata";

    $conf_4f ["anhang"]   = "/anhang";
    $conf_4f ["vordruck"] = "/vordruck";

/*******************************************************************************/

    $conf_4f ["MainURL"]    = $conf_urlroot.$conf_web ["pre_path"]."4fach/mainindex.php";

//  include "dbcfg.inc.php"; // wegen des Datenbanknamens  $conf_4f_db ["datenbank"]

    if (file_exists ( "e_cfg.inc.php" )) REQUIRE "e_cfg.inc.php" ;
    if (file_exists ( "./4fcfg/e_cfg.inc.php" )) REQUIRE "./4fcfg/e_cfg.inc.php" ;
    if (file_exists ( "../4fcfg/e_cfg.inc.php" )) REQUIRE "../4fcfg/e_cfg.inc.php" ;

    $conf_4f ["ablage_dir"] = $conf_web ["srvroot"]."/".
                              $conf_web ["pre_path"].
                              $conf_4f ["data"]."/".
                              $conf_4f_db ["datenbank"].
                              $conf_4f ["anhang"];

    $conf_4f ["ablage_uri"] = $conf_urlroot.
                              $conf_web ["pre_path"].
                              $conf_4f ["data"]."/".
                              $conf_4f_db ["datenbank"].
                              $conf_4f ["anhang"];

/*******************************************************************************/

    $conf_4f ["vordruck_dir"] = $conf_web ["srvroot"]."/".
                              $conf_web ["pre_path"].
                              $conf_4f ["data"]."/".
                              $conf_4f_db ["datenbank"].
                              $conf_4f ["vordruck"];

    $conf_4f ["einsatzende_dir"] = $conf_web ["srvroot"]."/".
                              $conf_web ["pre_path"].
                              $conf_4f ["data"]."/".
                              $conf_4f_db ["datenbank"] ;


    // Listendarstellungen Darstellung des Inhaltes
    $conf_4f_liste ["inhalt"] = 50 ; // Zeichen des Inhaltes


/*******************************************************************************/



    $tak_monate = array (
         "01" => 'jan',
         "02" => 'feb',
         "03" => 'mar',
         "04" => 'apr',
         "05" => 'mai',
         "06" => 'jun',
         "07" => 'jul',
         "08" => 'aug',
         "09" => 'sep',
         "10" => 'oct',
         "11" => 'nov',
         "12" => 'dec'
    );



    $rew_tak_monate = array (
         "jan" => '01',
         "feb" => '02',
         "mar" => '03',
         "apr" => '04',
         "mai" => '05',
         "may" => '05',
         "jun" => '06',
         "jul" => '07',
         "aug" => '08',
         "sep" => '09',
         "okt" => '10',
         "oct" => '10',
         "nov" => '11',
         "dez" => '12',
         "dec" => '12'
    );
/*****************************************************************/
//    Tabellenfarben
/*****************************************************************/

$color_data_table = "#E0E0E0";
$color_button     = "#E0E0E0";
$color_button_ok  = "#A0FFA0"; // auch für "Absenden"
$color_button_nok = "#FFA0A0";

?>
