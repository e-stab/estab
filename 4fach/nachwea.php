<?php

include ("../config.inc.php");  // Konfigurationseinstellungen und Vorgaben
include ("../db_operation.php");        // Datenbank operationen
include ("liste.php");          // erzeuge Ausgabelisten
include ("data_hndl.php");      // propritäre  Datenbankoperationen
include ("menue.php");          // erzeuge Menüs


  if ( isset ($_GET) ) {
    if ( isset ($_GET["nwe"]) ) {
      $list = new listen ("FmNwE", "");
      $list->createlist ();
    }
    if ( isset ($_GET["nwa"]) ) {
      $list = new listen ("FmNwA", "");
      $list->createlist ();
    }
    if ( isset ($_GET["nwalle"]) ) {
      if (Nachweisung == "gemeinsam"){
        $list = new listen ("FmNw", "");
        $list->createlist ();
      } elseif (Nachweisung == "getrennt") {
        $list = new listen ("FmNwE", "");
        $list->createlist ();
        $list = new listen ("FmNwA", "");
        $list->createlist ();

      }

    }
  }

?>
