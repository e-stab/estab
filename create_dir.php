<?php


  include "config.inc.php";


  // Hauptverzeichnis Datenbankname = Verzeichnisname
  $mainpath = $conf_web ["srvroot"].$conf_web ["pre_path"]."/".$conf_4f_db ["datenbank"] ;
  if ( ( $conf_web ["srvroot"]  != "" ) and
       ( $conf_web ["pre_path"] != "")  and
       ( $conf_4f_db ["datenbank"] != "" ) ){
    // Ist das Verzeichnis schon vorhanden?
    $isdir  = is_dir  ( $mainpath );
    $isfile = is_file ( $mainpath );
/*
    if ( $isdir ){ echo "isdir<br>"; } else { echo "!isdir<br>"; }
    if ( $isfile){ echo "isfile<br>"; } else { echo "!isfile<br>"; }
*/
    if ( !$isdir and !$isfile ){
      $success = mkdir ( $mainpath ) ;
      if ( $success ) {
        chmod ( $mainpath, 0777);
        echo "-Einsatzverzeichnis wurde angelegt(\"".$mainpath."\")";
        echo "<br>";
      }
    } else {
      echo "<br><b>FEHLER:</b> Verzeichnis oder Datei mit dem Namen \"".$conf_4f_db ["datenbank"]."\" schon vorhanden!<br>";
      echo "Bitte prüfen und gegebenenfalls löschen! <br>";

    }
    // Da das mit dem Verzeichnis geklapp hat, nun die Unterverzeichnisse

    // Anhang

    $anhangpath = $conf_4f ["ablage_dir"] ;

    $isdir  = is_dir  ( $anhangpath );
    $isfile = is_file ( $anhangpath );
/*
    if ( $isdir ){ echo "isdir<br>"; } else { echo "!isdir<br>"; }
    if ( $isfile){ echo "isfile<br>"; } else { echo "!isfile<br>"; }
*/
    if ( !$isdir and !$isfile ){
      $success = mkdir ( $anhangpath ) ;
      if ( $success ) {
        chmod ( $anhangpath, 0777);
        echo "-Einsatzverzeichnis wurde angelegt(\"".$anhangpath."\")";
        echo "<br>";
      }
    } else {
      echo "<br><b>FEHLER:</b> Verzeichnis oder Datei mit dem Namen <br>\"".$anhangpath."\"<br> schon vorhanden!<br>";
      echo "Bitte prüfen und gegebenenfalls löschen! <br>";
    }

    // Vordruck
    $vordruckpath = $conf_4f ["vordruck_dir"] ;


    $isdir  = is_dir  ( $vordruckpath );
    $isfile = is_file ( $vordruckpath );
/*
    if ( $isdir ){ echo "isdir<br>"; } else { echo "!isdir<br>"; }
    if ( $isfile){ echo "isfile<br>"; } else { echo "!isfile<br>"; }
*/
    if ( !$isdir and !$isfile ){
      $success = mkdir ( $vordruckpath, "0777"  ) ;
      if ( $success ) {
        chmod ( $vordruckpath, 0777);
        echo "-Einsatzverzeichnis wurde angelegt(\"".$vordruckpath."\")";
        echo "<br>";
      }
    } else {
      echo "<br><b>FEHLER:</b> Verzeichnis oder Datei mit dem Namen <br>\"".$vordruckpath."\"<br> schon vorhanden!<br>";
      echo "Bitte prüfen und gegebenenfalls löschen! <br>";
    }


  }



?>
