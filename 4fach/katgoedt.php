<?php
/*****************************************************************************\
   Datei: katgoedt.php

   benoetigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

include_once ("../4fach/katego.php");
include_once ("../4fcfg/config.inc.php");
include      ("../4fcfg/fkt_rolle.inc.php");

session_start ();

 define ("debug", false);

if ( debug == true ){
  echo "<br>12 In KATEGOEDT.PHP<br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}
    $berechtigt = ($_SESSION ["vStab_funktion"] == $redcopy2) OR
                  ($_SESSION ["vStab_funktion"] == "Si");
    if ( ( isset ( $_GET ["4fachkatego_absenden_x"] )) ) {
        // So als erstes muessen wir mal rauskriegen was überhaupt geändert worden ist.
        $om  = $_GET [kategorien_master];
        $ou  = $_GET [kategorien_user];

        $kmo = $_GET [kategorien_master_oben];
        $kuo = $_GET [kategorien_user_oben];

        $kmu = $_GET [kategorien_master_unten];
        $kuu = $_GET [kategorien_user_unten];
        $neu_m = "";
        $neu_u = "";

        if (( $kmo != $om ) OR ( $kmu != $om )) { // Master wurde geändert
          if ( $kmo != $om ) { $neu_m = $kmo; }
          elseif ( $kmu != $om ) { $neu_m = $kmu; }
        } else { $neu_m = $om; }

        if (( $kuo != $ou ) OR ( $kuu != $ou )) { // User wurde geändert
          if ( $kuo != $ou ) { $neu_u = $kuo; }
          elseif ( $kuu != $ou ) { $neu_u = $kuu; }
        } else { $neu_u = $ou; }


        if (debug){
          echo "om    =".$om."<br>" ;
          echo "ou    =".$ou."<br>" ;
          echo "kmo   =".$kmo."<br>" ;
          echo "kuo   =".$kuo."<br>" ;
          echo "kmu   =".$kmu."<br>" ;
          echo "kuu   =".$kuu."<br>" ;
          echo "neu_m =".$neu_m."<br>" ;
          echo "neu_u =".$neu_u."<br>" ;
        }

      if ($berechtigt) {
        if (debug) echo "26--<br>";
        $katego = new kategorien ("master");
          // kategorien_master in Tabelle suchen
        $result=$katego->db_get_kategobymsg ( $_GET["msglfd"] );
        if (debug) {echo "030 RESULT="; var_dump ($result); echo"<br>";}
        if ($result == false) {
          // für die Nachrichtennummer gibt es keinen Kategorieeintrag
          if (debug) echo "33-- db_get_kategobymsg ( _GET[msglfd] ) == false<br>";
          // insert da es noch keinen Eintrag gibt
          if ($neu_m != "") {
            if (debug) echo "36-- _GET [kategorie_master] !=<br>";
            $katego->dblk_neu ( $_GET["msglfd"], $neu_m );
          }
        } ELSE {
            // Kategorie muss geändert werden
          if (debug) echo "41 -- db_get_kategobymsg ( _GET[msglfd] ) == TRUE<br>";
          // insert da es noch keinen Eintrag gibt
          if ($neu_m != "") {
            $katego->dblk_aendern ( $_GET["msglfd"], $neu_m );
          } ELSE {
            $katego->dblk_loeschen ( $_GET["msglfd"] );
          }
        }
      }
      if (debug) echo "50--<br>";
      $katego = new kategorien ("user");
        // kategorien_user in Tabelle suchen
      $result=$katego->db_get_kategobymsg ( $_GET["msglfd"] );
      if (debug) {echo "054 RESULT="; var_dump ($result); echo"<br>";}
      if ($result == false) {
        // für die Nachrichtennummer gibt es keinen Kategorieeintrag
        if (debug) echo "57-- db_get_kategobymsg ( _GET[msglfd] ) == false<br>";
        // insert da es noch keinen Eintrag gibt
        if ($neu_u != "") {
          $katego->dblk_neu ( $_GET["msglfd"], $neu_u );
        }
      } ELSE {
        // Kategorie muss geändert werden
        // insert da es noch keinen Eintrag gibt
        if ($neu_u != "") {
          if (debug) echo "66-- db_get_kategobymsg ( _GET[msglfd] ) == TRUE<br>";
          $katego->dblk_aendern ( $_GET["msglfd"], $neu_u );
        } ELSE {
            $katego->dblk_loeschen ( $_GET["msglfd"] );
        }
      }

      include_once ("4fachform.php"); // Formular Behandlung 4fach Vordruck
      include_once ("db_operation.php"); // Datenbank operationen
      include_once ("data_hndl.php"); // Schnittstelle zur Datenbank

      $formdata = get_msg_by_lfd ($_GET["msglfd"]);

      $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
       // $_SESSION löschen
      unset ($_SESSION ["kat_msgno"]);
      unset ($_SESSION ["kat_tbl"]);
      unset ($_SESSION ["kat_no"]);
    }

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
//          if (!debug)
      header("Location: ".$_SERVER['PHP_SELF']);
    }

    if ( ( isset ( $_GET ["katego_abbrechen_x"]  )) OR
         ( ( isset ( $_GET ["katego_absenden_x"] )) AND
           ( $_GET ["lfd"] == "" ) AND
           ( $_GET ["kategorie"] == "" ) AND
           ( $_GET ["beschreibung"] == "" ) ) ) {
      if (debug) echo "110--<br>";
      // 4fachVordruck
      include_once ("4fachform.php"); // Formular Behandlung 4fach Vordruck
      include_once ("db_operation.php"); // Datenbank operationen
      include_once ("data_hndl.php"); // Schnittstelle zur Datenbank

      $formdata = get_msg_by_lfd ($_SESSION ["kat_msgno"]);

      $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
       // $_SESSION löschen
      unset ($_SESSION ["kat_msgno"]);
      unset ($_SESSION ["kat_tbl"]);
      unset ($_SESSION ["kat_no"]);


    }
    if ( ( isset ( $_GET ["kate_dbtbl"] )) AND
         ( $_GET ["kate_todo"] == "neu" ) AND
         ( isset ( $_GET ["lfd"] )) AND
         ( $_GET ["kategorie"] != "" ) AND
         ( isset ( $_GET ["beschreibung"] )) AND
         ( isset ( $_GET ["katego_absenden_x"] )) ) {
       if (debug) echo "245--<br>";
            // INSERT
       if ( $_GET ["kategorie"] != "") {
         $katego = new kategorien ($_SESSION ["kat_tbl"]);
         $katego->db_neu($_GET ["kategorie"], $_GET ["beschreibung"] );
       }
      header("Location: ".$_SERVER['PHP_SELF']."?dbtyp=".$_SESSION ["kat_tbl"]."&fkt=edit&msgno=".$_SESSION ["kat_msgno"]);
    }
    if ( ( isset ( $_GET ["dbtyp"] )) AND
         ( $_GET ["kate_todo"] == "deleterecord" ) AND
         ( isset ( $_GET ["lfd"] )) ) {
       if (debug) echo "233 --<br>";
            // DELETE
       $katego = new kategorien ($_GET ["dbtyp"]);
       $katego->db_delete ( $_GET ["lfd"] );
       switch ($_GET ["dbtyp"]) {
         case "master":
           if (isset ($_SESSION["ma_katego"])) {
             unset ($_SESSION["ma_katego"]);
             unset ($_SESSION["ma_kategotyp"]);
           }
         break;
         case "user":
           if (isset ($_SESSION["us_katego"])) {
             unset ($_SESSION["us_katego"]);
             unset ($_SESSION["us_kategotyp"]);
           }
         breake;
       }
       //if (!debug)
       header("Location: ".$_SERVER['PHP_SELF']."?dbtyp=".$_GET ["dbtyp"]."&fkt=edit&msgno=".$_SESSION ["kat_msgno"]);
    }

?>
