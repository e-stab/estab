<?php
/*****************************************************************************\
   Datei: config.inc.php

   benoetigte Dateien:

   Beschreibung:
     Zentrale Konfigurationsdatei

   (C) 2006-2007 Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

/******************************************************************************\
   Allgemeine Einstellunge

\******************************************************************************/

    /* getrennt : Eingang und Ausgang zählen für sich
      gemeinsam : Eingang und Ausgang zählen zusammen */
    define ("Nachweisung", "gemeinsam");

    /*
    */
    define ("posttakzeit","true");

     /* Zwischen dem Documentroot des Webservers (htdocs) und dem Kats-
        Verzeichnis eventuell vorhandenes Verzeichnis.*/

    $conf_pre_dir =  ""; // mit führendem /

      // URL des Servers
    $conf_urlroot  = "http://".$_SERVER ["SERVER_NAME"]; //"http://1service.no-ip.org";

      // Wurzelverzeichnis fuer den Webserver
      // unter Linux /srv/www/htdocs
      // unter Windows C:\Progra~1\Apache\htdocs
      // u.U. muss mit dir /X der Kurzname 8.3 ermittelt und eingetragen werden
    $conf_web ["srvroot"]  = $_SERVER ["DOCUMENT_ROOT"];

      // URL bis zum Kats-Verzeichnis
      // z.B. http://localhost/Einsatzleitung
      // http://192.168.100.1/Einsatzlt
      // http://www.leitstelle.de/ELStab
      // u.s.w. */
    $pre_url = $conf_urlroot."/".$conf_pre_dir;

      // Design fuer Buttons
    $conf_design           = "second";

      // Pfad zur Einsatzstabssoftware
    $conf_web ["pre_path"] = $conf_pre_dir."/kats";

    $conf_menue ["symbole"] = "./symbole";

/*******************   nicht aendern ; do not change !!! ***********************/

    $conf_design_path      = $conf_urlroot.$conf_web ["pre_path"].
                               "/4fach/design/".$conf_design ;

    $conf_design_URI       = $pre_url.$conf_web ["pre_path"].
                               "/4fach/design/".$conf_design;

/********** Die nachfolgenden Zeilen duerfen nicht geaendert werden !!!  *********/
/*********               Do not change the folowing lines               **********/
    $conf_4f ["Titelkurz"]        =  "eStab";
    $conf_4f ["SubTitel"]["env"]  =  " - elektronischer Nachrichtenvordruck";
    $conf_4f ["SubTitel"]["etb"]  =  "Einsatztagebuch";
    $conf_4f ["Version"]          =  "v0.9.10 15jun2008";

      // Programm information und Versionsnummer
    $conf_4f ["NameVersion"][0]   = "<big><big><b>".$conf_4f ["Titelkurz"]." ".
                                    $conf_4f ["SubTitel"]["env"]."</b><br>".
                                    $conf_4f ["Version"]."</big></big><br>\n";

      // Hier kann die eigene Dienststelle eingetragen werden
    $conf_4f ["NameVersion"][1]   = "<b>Ein Programm der IuK Kreis Heinsberg</b><br>\n";


    $conf_4f ["NameVersion"][2]   = "Nachrichtenvordruck Stab-Modul      <br>\n";
    $conf_4f ["NameVersion"][3]   = "Nachrichtenvordruck Fernmelde-Modul <br>\n";
    $conf_4f ["NameVersion"][4]   = "Nachrichtenvordruck Sichter-Modul   <br>\n";
    $conf_4f ["NameVersion"][5]   = "Administrationsmodul                <br>\n";
    $conf_4f ["NameVersion"][6]   = "Editor Empf&auml;ngermatix          <br>\n";
    $conf_4f ["NameVersion"][7]   = "Nachweisung Eingang / Ausgang       <br>\n";
    $conf_4f ["NameVersion"][8]   = "ETB Einsatztagebuch                 <br>\n";
    $conf_4f ["NameVersion"][9]   = "(C) 2006-2008 HaJo Landmesser<br>eMail: 4fach-info@IuK-Heinsberg.de <br>\n";
    $conf_4f ["NameVersion"][10]   = "Infos, Forum unter  http://KatS.IuK-Heinsberg.de <br>\n";



/*******************************************************************************/

    $conf_4f ["anhang"] = "/anhang";
    $conf_4f ["vordruck"] = "/vordruck";

/*******************************************************************************/

    $conf_4f ["MainURL"]         = $conf_urlroot.$conf_web ["pre_path"]."/4fach/mainindex.php";

  include "dbcfg.inc.php"; // wegen des Datenbanknamens  $conf_4f_db ["datenbank"]
  include "e_cfg.inc.php";

    $conf_4f ["ablage_dir"] = $conf_web ["srvroot"].
                              $conf_web ["pre_path"]."/".
                              $conf_4f_db ["datenbank"].
                              $conf_4f ["anhang"];

    $conf_4f ["ablage_uri"] = $conf_urlroot.
                              $conf_web ["pre_path"]."/".
                              $conf_4f_db ["datenbank"].
                              $conf_4f ["anhang"];

/*******************************************************************************/

    $conf_4f ["vordruck_dir"] = $conf_web ["srvroot"].
                              $conf_web ["pre_path"]."/".
                              $conf_4f_db ["datenbank"].
                              $conf_4f ["vordruck"];

    $conf_4f ["einsatzende_dir"] = $conf_web ["srvroot"].
                              $conf_web ["pre_path"]."/".
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

?>
