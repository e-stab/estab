<?php
/*****************************************************************************\
   Datei: protokoll.php

   benötigte Dateien:

   Beschreibung:



   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
/**********************************************************************************\
  function protokolleintrag ();
       p_zeit,         - Zeitstempel
       p_was,        -  Art des Ereignis
       p_ereignis  -  Daten des Ereignis

Aufruf:


\***********************************************************************************/

  function protokolleintrag ($was, $daten){
     include ("../4fcfg/dbcfg.inc.php");
     include ("../4fcfg/e_cfg.inc.php");
     require_once ("../4fach/tools.php");
     include ("../4fcfg/config.inc.php");

     $query = "INSERT INTO ".$conf_4f_tbl["protokoll"]." SET
                   p_zeit          = \"".convtodatetime (date("dm"), date ("Hi"))."\",
                   p_was        = \"".$was."\",
                   p_ereignis = \"".mysql_escape_string ($daten)."\"";
//echo "protokoll".$query."<br>";
      $result = "";
      $db = mysql_connect($conf_4f_db["server"],$conf_4f_db["user"], $conf_4f_db["password"])
            or die ("900 - Konnte keine Verbindung zur Protokolldatenbank herstellen");

      $db_check = mysql_select_db ($conf_4f_db ["datenbank"] )
           or die ("901 - Auswahl der Protokolldatenbank fehlgeschlagen");

      $query_result = mysql_query ($query,  $db)
           or die("902 - ".mysql_error()." ".mysql_errno());

      mysql_close ($db);
  } // function protokolleintrag
?>
