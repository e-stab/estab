<?php

include_once ("katego.php");
include_once ("../config.inc.php");
include ("../fkt_rolle.inc.php");  

define ("debug", false);

session_start ();

if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}


    $berechtigt = ($_SESSION ["vStab_funktion"] == $redcopy2) OR
                  ($_SESSION ["vStab_funktion"] == "Si");

    if ( ( isset ( $_GET ["4fachkatego_absenden_x"] )) ) {
      if ($berechtigt) {
        if (debug) echo "21--<br>";
        $katego = new kategorien ("master");
          // kategorien_master in Tabelle suchen
        $result=$katego->db_get_kategobymsg ( $_GET["msglfd"] );
        if (debug) {echo "025 RESULT="; var_dump ($result); echo"<br>";}
        if ($result == false) {
          // für die Nachrichtennummer gibt es keinen Kategorieeintrag
          if (debug) echo "30-- db_get_kategobymsg ( _GET[msglfd] ) == false<br>";
          // insert da es noch keinen Eintrag gibt
          if ($_GET ["kategorien_master"] != "") {
            if (debug) echo "33-- _GET [kategorie_master] !=<br>";
            $katego->dblk_neu ( $_GET["msglfd"], $_GET["kategorien_master"] );
          }
        } ELSE {
            // Kategorie muss geändert werden
          if (debug) echo "37-- db_get_kategobymsg ( _GET[msglfd] ) == TRUE<br>";
          // insert da es noch keinen Eintrag gibt
          if ($_GET ["kategorien_master"] != "") {
            $katego->dblk_aendern ( $_GET["msglfd"], $_GET["kategorien_master"] );
          } ELSE {
            $katego->dblk_loeschen ( $_GET["msglfd"] );
          }
        }
      }
      if (debug) echo "46--<br>";
      $katego = new kategorien ("user");
        // kategorien_user in Tabelle suchen
      $result=$katego->db_get_kategobymsg ( $_GET["msglfd"] );
      if (debug) {echo "050 RESULT="; var_dump ($result); echo"<br>";}
      if ($result == false) {
        // für die Nachrichtennummer gibt es keinen Kategorieeintrag
        if (debug) echo "53-- db_get_kategobymsg ( _GET[msglfd] ) == false<br>";
        // insert da es noch keinen Eintrag gibt
        if ($_GET ["kategorien_user"] != "") {
          $katego->dblk_neu ( $_GET["msglfd"], $_GET["kategorien_user"] );
        }
      } ELSE {
          // Kategorie muss geändert werden
        if (debug) echo "60-- db_get_kategobymsg ( _GET[msglfd] ) == TRUE<br>";
        // insert da es noch keinen Eintrag gibt
        if ($_GET ["kategorien_user"] != "") {
          $katego->dblk_aendern ( $_GET["msglfd"], $_GET["kategorien_user"] );
        }
      }

      include_once ("4fachform.php"); // Formular Behandlung 4fach Vordruck
      include_once ("../db_operation.php"); // Datenbank operationen
      include_once ("data_hndl.php"); // Schnittstelle zur Datenbank

      $formdata = get_msg_by_lfd ($_GET["msglfd"]);

      $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
       // $_SESSION löschen
      unset ($_SESSION ["kat_msgno"]);
      unset ($_SESSION ["kat_tbl"]);
      unset ($_SESSION ["kat_no"]);

    }


    /*GET=array(3) {
      ["dbtyp"] =>  string(6) "master"
      ["fkt"]   =>  string(4) "edit"
      ["msgno"] =>  string(1) "1" } #*/
    if ( ( isset ( $_GET ["dbtyp"] )) AND
         ( isset ( $_GET ["msgno"] )) AND
         ( $_GET ["fkt"] == "edit" )) {
      // Liste der Kategorien bzw Eingabemöglichkeit einer neuen Kategorie
      if (debug) echo "28--<br>";
      $_SESSION ["kat_msgno"] = $_GET ["msgno"];
      $_SESSION ["kat_tbl"]   = $_GET["dbtyp"];
      $katego = new kategorien ($_SESSION ["kat_tbl"]);
      $katego->liste_kategorien ();
      $katego->eingabezeile ("neu","","","");

    }

    /*GET=array(3) {
      ["kate_todo"] =>  string(10) "editrecord"
      ["lfd"]       =>  string(1) "1"
      ["dbtyp"]     =>  string(6) "master" } #*/
    if ( ( isset ( $_GET ["dbtyp"] )) AND
         ( isset ( $_GET ["lfd"] )) AND
         ( $_GET ["kate_todo"] == "editrecord" )) {
      if (debug) echo "44--<br>";
      $_SESSION ["kat_no"] = $_GET ["lfd"];
      $katego = new kategorien ($_SESSION ["kat_tbl"]);
      $katego->db_get ( $_SESSION ["kat_no"] );
      $katego->eingabezeile ("update",
                             $katego->result ["lfd"],
                             $katego->result ["kategorie"],
                             $katego->result ["beschreibung"]);
    }


    /*GET=array(8) {
      ["kate_todo"]=>  string(6) "update"
      ["kate_tbl"]=>  string(15) "nv_masterkatego"
      ["kate_dbtbl"]=>  string(6) "master"
      ["lfd"]=>  string(1) "1"
      ["kategorie"]=>  string(3) "EA1"
      ["beschreibung"]=>  string(18) "Einsatzabschnitt 1"
      ["katego_absenden_x"]=>  string(2) "20"
      ["katego_absenden_y"]=>  string(2) "11" } #*/
    if ( ( isset ( $_GET ["kate_dbtbl"] )) AND
         ( isset ( $_GET ["lfd"] )) AND
         ( $_GET ["kate_todo"] == "update" ) AND
         ( isset ( $_GET ["lfd"] )) AND
         ( isset ( $_GET ["kategorie"] )) AND
         ( isset ( $_GET ["beschreibung"] )) AND
         ( isset ( $_GET ["katego_absenden_x"] )) ) {
      if (debug) echo "131--<br>";
      $katego = new kategorien ($_SESSION ["kat_tbl"]);
      $katego->db_aendern ($_GET ["lfd"], $_GET["kategorie"], $_GET["beschreibung"] );

       // Liste mit eingabemöglichkeit
      $katego->liste_kategorien ();
      $katego->eingabezeile ("neu","","","");
//          if (!debug) header("Location: ".$_SERVER['PHP_SELF']);
    }


    //OK und leer
    /*GET=array(8) {
      ["kate_todo"]=>  string(3) "neu"
      ["kate_tbl"]=>  string(15) "nv_masterkatego"
      ["kate_dbtbl"]=>  string(6) "master"
      ["lfd"]=>  string(0) ""
      ["kategorie"]=>  string(0) ""
      ["beschreibung"]=>  string(0) ""

      ["katego_absenden_x"]=>  string(2) "16"
      ["katego_absenden_y"]=>  string(2) "14" } #*/

    // abbrechen
    /*GET=array(8) {
      ["kate_todo"]=>  string(3) "neu"
      ["kate_tbl"]=>  string(15) "nv_masterkatego"
      ["kate_dbtbl"]=>  string(6) "master"
      ["lfd"]=>  string(0) ""
      ["kategorie"]=>  string(0) ""
      ["beschreibung"]=>  string(0) ""

      ["katego_abbrechen_x"]=>  string(2) "36"
      ["katego_abbrechen_y"]=>  string(1) "5" } #*/
    if ( ( isset ( $_GET ["katego_abbrechen_x"]  )) OR
         ( ( isset ( $_GET ["katego_absenden_x"] )) AND
           ( $_GET ["lfd"] == "" ) AND
           ( $_GET ["kategorie"] == "" ) AND
           ( $_GET ["beschreibung"] == "" ) ) ) {
      if (debug) echo "110--<br>";
      // 4fachVordruck
      include_once ("4fachform.php"); // Formular Behandlung 4fach Vordruck
      include_once ("../db_operation.php"); // Datenbank operationen
      include_once ("data_hndl.php"); // Schnittstelle zur Datenbank

      $formdata = get_msg_by_lfd ($_SESSION ["kat_msgno"]);

      $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
       // $_SESSION löschen
      unset ($_SESSION ["kat_msgno"]);
      unset ($_SESSION ["kat_tbl"]);
      unset ($_SESSION ["kat_no"]);


    }

    /* GET=array(8) {
      ["kate_todo"]=>  string(3) "neu"
      ["kate_tbl"]=>  string(15) "nv_masterkatego"
      ["kate_dbtbl"]=>  string(6) "master"
      ["lfd"]=>  string(0) ""
      ["kategorie"]=>  string(3) "EA4"
      ["beschreibung"]=>  string(45) "Einsatzabschnitt 4 Instandsetzung, Abstützung"
      ["katego_absenden_x"]=>  string(2) "26"
      ["katego_absenden_y"]=>  string(1) "7" } #*/
    if ( ( isset ( $_GET ["kate_dbtbl"] )) AND
         ( $_GET ["kate_todo"] == "neu" ) AND
         ( isset ( $_GET ["lfd"] )) AND
         ( $_GET ["kategorie"] != "" ) AND
         ( isset ( $_GET ["beschreibung"] )) AND
         ( isset ( $_GET ["katego_absenden_x"] )) ) {
       if (debug) echo "202--<br>";
            // INSERT
       if ( $_GET ["kategorie"] != "") {
         $katego = new kategorien ($_SESSION ["kat_tbl"]);
         $katego->db_neu($_GET ["kategorie"], $_GET ["beschreibung"] );
       }
/*
      // 4fachVordruck
      include_once ("4fachform.php"); // Formular Behandlung 4fach Vordruck
      include_once ("../db_operation.php"); // Datenbank operationen
      include_once ("data_hndl.php"); // Schnittstelle zur Datenbank

      $formdata = get_msg_by_lfd ($_SESSION ["kat_msgno"]);

      $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
       // $_SESSION löschen
      unset ($_SESSION ["kat_msgno"]);
      unset ($_SESSION ["kat_tbl"]);
      unset ($_SESSION ["kat_no"]);
*/

      if (!debug) header("Location: ".$_SERVER['PHP_SELF']."?dbtyp=".$_SESSION ["kat_tbl"]."&fkt=edit&msgno=".$_SESSION ["kat_msgno"]);
    }

    /*GET=array(3) {
      ["kate_todo"]=>  string(12) "deleterecord"
      ["lfd"]=>  string(1) "2"
      ["dbtyp"]=>  string(6) "master" } #
    */
    if ( ( isset ( $_GET ["dbtyp"] )) AND
         ( $_GET ["kate_todo"] == "deleterecord" ) AND
         ( isset ( $_GET ["lfd"] )) ) {
       if (debug) echo "233 --<br>";
            // DELETE
       $katego = new kategorien ($_GET ["dbtyp"]);
       $katego->db_delete ( $_GET ["lfd"] );
       if (!debug) header("Location: ".$_SERVER['PHP_SELF']."?dbtyp=".$_GET ["dbtyp"]."&fkt=edit&msgno=".$_SESSION ["kat_msgno"]);
    }

?>
