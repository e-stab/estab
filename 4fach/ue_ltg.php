<?php

include ("../4fcfg/config.inc.php");            // Konfigurationseinstellungen und Vorgaben
include ("../4fach/db_operation.php");          // Datenbank operationen
include ("../4fach/data_hndl.php");             // propritäre  Datenbankoperationen
include ("../4fcfg/para.inc.php");              //


define ("inhalt_limit",true);

/*****************************************************************************\
   Datei: ue_ltg.php

   benötigte Dateien:  keine

   Beschreibung:

   Erzeugt eine Liste aller Meldungen mit Sichtung.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

class Listen {
/******************************************************************************\
   $welche ~= Art der Liste die Ausggeben werden soll. Möglich sind:
     FMA    - Fernmeldeausgangsliste
     STUSER - Stabbenutzer
     STSI   - Stab Sichter
     FMNWE  - Fernmelde Nachweis Eingang
     FMNWA  - Fernmelde Nachweis Ausgang
     ADMIN  - Administrative Liste
\******************************************************************************/


  var $listenart;
  var $benutzer;

  // Listengestaltung

/******************************************************************************\

\******************************************************************************/


  function explodereceiver ( $empf){
    $receiver = explode (",",$empf);
    for ($i=0; $i < count( $receiver ); $i++ ) {
      $hilfeaus = explode ( "_", $receiver [$i] ) ;
      $fktcopycolor[$hilfeaus[0]] = $hilfeaus [1] ;
    }
    return $fktcopycolor;
  }


/******************************************************************************\

  SESSION=Array (

     [flt_gelesen] => 1    zeige gelesene
     [flt_erledigt] => 1   zeige erledigte
     [ueb_flt_start] => 1
     [flt_position] => 1
     [ueb_flt_darstellung] => 1
     [ueb_flt_anzahl] => 5 ) #

\******************************************************************************/
  function get_list (){
    echo "\n\n\n<!-- ANFANG file:liste.php fkt:createlist -->";
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $tblusername   = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"]).
                     "_".strtolower ($_SESSION["vStab_kuerzel"]);

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
    $query_select_arg = $conf_4f_tbl ["nachrichten"].".`00_lfd`, ".
                        $conf_4f_tbl ["nachrichten"].".`09_vorrangstufe`, ".
                        $conf_4f_tbl ["nachrichten"].".`04_richtung`, ".
                        $conf_4f_tbl ["nachrichten"].".`04_nummer`, ".
                        $conf_4f_tbl ["nachrichten"].".`10_anschrift`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_abfzeit`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_inhalt`, ".
                        $conf_4f_tbl ["nachrichten"].".`13_abseinheit`, ".
                        $conf_4f_tbl ["nachrichten"].".`14_funktion`, ".
                        $conf_4f_tbl ["nachrichten"].".`16_empf`, ".
                        $conf_4f_tbl ["nachrichten"].".`X00_status`, ".
                        $conf_4f_tbl ["nachrichten"].".`x01_abschluss` ";

    $query_from_arg   = $conf_4f_tbl ["nachrichten"]; //.", ".$tblusername."_read , ".$tblusername."_erl ";

    $query_where_arg1 = "1"; // "(( `16_empf` like \"%".$_SESSION["vStab_funktion"]."%\" ) OR ( `16_empf` like \"%alle%\" ))";

//    if ($_SESSION [flt_gelesen]  != 1){$readwhat = " NOT ";} else {$readwhat = " ";}

    if ($_SESSION [flt_erledigt] != 1){$donewhat = " NOT ";} else {$donewhat = " ";}


    if ($_SESSION["ueb_flt_darstellung"] == "1" ){
      $query_where_arg3 = ""; //" AND (`".$conf_4f_tbl ["nachrichten"]."`.`04_nummer` ".$donewhat." IN                          ( select `".$tblusername."_erl`.`nachnum` from `".$tblusername."_erl` where 1))";
    } else {
      $query_where_arg2 = "";
    }

    $query_orderby_arg = "`04_nummer` DESC, `09_vorrangstufe` DESC ";

    if (isset ($_SESSION["ueb_flt_search"])) {
      $query_search = "(".
          "(".$conf_4f_tbl ["nachrichten"].".`04_nummer` LIKE \"%".$_SESSION["ueb_flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`10_anschrift` LIKE \"%".$_SESSION["ueb_flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_abfzeit` LIKE \"%".$_SESSION["ueb_flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_inhalt` LIKE \"%".htmlentities ($_SESSION["ueb_flt_search"])."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`13_abseinheit` LIKE \"%".$_SESSION["ueb_flt_search"]."%\") )";


      $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." AND ".$query_search.";" ;

      $query = "SELECT ".$query_select_arg." FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." AND ".$query_search." ORDER BY ".$query_orderby_arg ;

//      unset ($_SESSION["flt_search"]);

    } else {
      $query_search = "";
      $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." ".$query_where_arg2." ".$query_where_arg3.";" ;

      $query = "SELECT ".$query_select_arg." FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." ".$query_where_arg2." ".$query_where_arg3." ORDER BY ".$query_orderby_arg ;
    }

    if ( debug == true ){  echo "<br><br>QUERYCOUNT [get_list] =".$querycount."<br>";echo "<br><br>";}

    if ( $_SESSION["ueb_flt_darstellung"] == "1" ){
      $tmp = $dbaccess->query_table_wert ($querycount);
      $anzahl = $tmp[0];
      $_SESSION["ueb_flt_rescount"] = $anzahl ;

      if ( debug == true ){ echo "<br>ANZAHL ===".$anzahl."<br>";}

      if (isset($_SESSION[ueb_flt_navi])) {

        switch ($_SESSION[ueb_flt_navi]) {
           // ANFANG
          case "start":
                  $_SESSION["ueb_flt_start"] = 0;
          break;
           // Eine Seite zurück
          case "back":
                  $_SESSION["ueb_flt_start"] -= $_SESSION[ueb_flt_anzahl];
                  if ($_SESSION["ueb_flt_start"] < 0){
                    $_SESSION["ueb_flt_start"]=0;}
          break;
           // Eine Seite vor
          case "for":
                  if ($anzahl < $_SESSION[ueb_flt_anzahl]){ $_SESSION[ueb_flt_start] = 0;
                  } else {
                    $_SESSION["ueb_flt_start"] += $_SESSION[ueb_flt_anzahl];
                    if ($_SESSION["ueb_flt_start"] >= $anzahl){
                      $_SESSION["ueb_flt_start"] = $anzahl-1;}
                  }
          break;
          // Letzte Seite
          case "end":
                  if ($anzahl < $_SESSION[ueb_flt_anzahl]){ $_SESSION[ueb_flt_start] = 0;
                  } else {
                    $seiten = floor ($anzahl / $_SESSION[ueb_flt_anzahl])-1 ;
                    $_SESSION["ueb_flt_start"] = $seiten * $_SESSION["ueb_flt_anzahl"];
                  }
          break;
        }
        unset ($_SESSION [ueb_flt_navi]);
      }
      $query .= " LIMIT ".$_SESSION["ueb_flt_start"].",".$_SESSION["ueb_flt_anzahl"];
    }


    $query = $query_select.$query;

    if ( debug == true ){  echo "QUERY [get_list] =".$query."<br>";echo "<br><br>";}

    $result = $dbaccess->query_table ($query);

//    if ( debug == true ){ echo "RESULT [get_list] ="; var_dump ($result); echo "<br><br>"; }

    return ($result);

  }


/******************************************************************************\

\******************************************************************************/
  function listen ($welche, $user){
    $this->listenart = $welche;
    $this->benutzer  = $user;
//    echo "listenart =".$this->listenart."- benutzer = ".$this->benutzer."<br>";
  }


/******************************************************************************\
  Funktion:  listen_navi ()
SELECT * FROM `nv_nachrichten` WHERE `00_lfd` IN

(SELECT msg FROM `nv_masterkategolink` WHERE `katego` = (

SELECT lfd FROM `nv_masterkatego` WHERE `kategorie` = "2m"));

\******************************************************************************/

  function  listen_navi (){
    include ("../4fcfg/config.inc.php");
    echo "<input type=\"image\" name=\"ueb_flt_start\" src=\"".$conf_design_path."/go_start.gif\" alt=\"Anfang\">\n";
    echo "<input type=\"image\" name=\"ueb_flt_back\"  src=\"".$conf_design_path."/go_back.gif\" alt=\"zurueck\">\n";
    echo "<input type=\"image\" name=\"ueb_flt_for\"   src=\"".$conf_design_path."/go_forward.gif\" alt=\"vor\">\n";
    echo "<input type=\"image\" name=\"ueb_flt_end\"   src=\"".$conf_design_path."/go_end.gif\" alt=\"Ende\">\n";
  }


/******************************************************************************\

\******************************************************************************/
  function darstellungs_art ( ){

    include ("../4fcfg/config.inc.php");

    if ( debug ) { echo "\n\n\n<!-- ANFANG file:liste.php fkt:darstellungsart -->"; }

    echo "\n<form action=\"".$_SERVER ["PHP_SELF"]."\" method=\"get\" target=\"_self\">\n";
    echo "<table><tbody>";
    echo "<tr>";

      echo "<td>";
      echo "<big><b>".($_SESSION["ueb_flt_start"]+1)."|".($_SESSION["ueb_flt_start"]+$_SESSION["ueb_flt_anzahl"])."|<big>".($_SESSION["ueb_flt_rescount"])."</big></b></big>";
      echo "</td>";
      echo "<td>";
      echo "Meldung/Seite:<br>\n";

        // Voreinstellung für die Meldungen pro Seite
      if ( !(isset ($_SESSION["ueb_flt_anzahl"])) OR
          ( $_SESSION["ueb_flt_anzahl"] == "" )
       ){$_SESSION["ueb_flt_anzahl"] = 5; }

      echo "<table border=\"0\" ><tbody>";
      echo "<tr>";

      echo "<td>";
      echo "<div  style=\"border-top-color:#DCDCFF; border-left-color:#DCDCFF; border-right-color:#DCDCFF; border-bottom-color:#000000; border-width:1px; border-style:solid; padding:0px\">";
        for ($pps=5; $pps <=25; $pps+=5){
          if ( $_SESSION["ueb_flt_anzahl"] == $pps )  {
            echo "<a href=\"".$_SERVER ["PHP_SELF"]."?ueb_flt_anzahl_x=1&ueb_flt_anzahl=".$pps."\"><img src=\"button.php?type=icon&status=AUS&text=".$pps."&bg=blue\" border=\"0\" alt=\"Anzahl".$pps."EIN\"></a>";
          } else {
            echo "<a href=\"".$_SERVER ["PHP_SELF"]."?ueb_flt_anzahl_x=1&ueb_flt_anzahl=".$pps."\"><img src=\"button.php?type=icon&status=EIN&text=".$pps."&bg=lighterblue\" border=\"0\" alt=\"Anzahl".$pps."AUS\"></a>";
          }
        }
      echo "</div>";
      echo "</td>";

      echo "</tr>";
      echo "</tbody></table>";
      echo "</td>";
/*
      echo "<td>";

      if ($_SESSION ["ueb_flt_unerl"] == 0)  {
        echo "<div>";
        echo "<input type=\"image\" name=\"ueb_flt_unerledigt_ein\" src=\"button.php?type=push&textpos=buttom&status=AUS&text=un-\" alt=\"unerledigte\">\n";
        echo "</div>";
      } else {
        echo "<div>";
        echo "<input type=\"image\" name=\"ueb_flt_unerledigt_aus\" src=\"button.php?type=push&textpos=buttom&status=EIN&text=un-\" alt=\"unerledigte\">\n";
        echo "</div>";
      }
      echo "</td>";

      echo "<td>";
      if ($_SESSION ["ueb_flt_erl"] == 0)  {
        echo "<div>";
        echo "<input type=\"image\" name=\"ueb_flt_erledigt_ein\" src=\"button.php?type=push&textpos=buttom&status=AUS&text=erledigt\" alt=\"erledigte\">\n";
        echo "</div>";
      } else {
        echo "<div>";
        echo "<input type=\"image\" name=\"ueb_flt_erledigt_aus\" src=\"button.php?type=push&textpos=buttom&status=EIN&text=erledigt\" alt=\"erledigte\">\n";
        echo "</div>";
      }
      echo "</td>";
*/
      echo "<td>";
      if ($_SESSION ["ueb_flt_find_mask"] == 0)  {
        echo "<div>";
        echo "<input type=\"image\" name=\"ueb_flt_find_mask_ein\" src=\"button.php?type=push&textpos=buttom&status=AUS&text=finden\" alt=\"finden\">\n";
        echo "</div>";
      } else {
        echo "<div>";
        echo "<input type=\"image\" name=\"ueb_flt_find_mask_aus\" src=\"button.php?type=push&textpos=buttom&status=EIN&text=finden\" alt=\"finden\">\n";
        echo "</div>";
      }
      echo "</td>";


    echo "<!-- ue_ltg.php 426 -->";
    //        echo "</form>";

    echo "<td>";


    echo "<table><tbody>";
    echo "<tr>";


    if ($_SESSION["ueb_flt_find_mask"] == 1){
      echo "\n<form action=\"".$_SERVER ["PHP_SELF"]."\" method=\"get\" target=\"_self\">\n";
      echo "<td>";
      if (isset ($_SESSION ["ueb_flt_search"]) ) { $defvalue = $_SESSION ["ueb_flt_search"] ;}
      else {$defvalue = "";}
      echo "<div>";
      echo "<p>Suchbegriff: <input name=\"ueb_flt_search\" value=\"".$defvalue."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
      echo "<div>";
      echo "</td>";
      echo "<td>";
      echo "<input name=\"ueb_flt_suche\" value=\"suchen\" type=\"submit\">\n";
      echo "</td>";
    //          echo "</form>";
      echo "</div>";
    }

    echo "</tr>";
    echo "</tbody></table>";


    echo "</td>";

    echo "</tr>";
    echo "</tbody></table>";
  }



/******************************************************************************\

\******************************************************************************/
  function createlist (){
    echo "\n\n\n<!-- ANFANG file:ue_ltg.php fkt:createlist -->";
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "</head>\n";

    echo "<body bgcolor=\"#DCDCFF\">";

    $result = $this->get_list ();
    $this->darstellungs_art ( "Stab_lesen" );

    $this->listen_navi ();

    if  ($result != ""){
      echo "<table style=\"text-align: center; background-color: rgb(250,250, 250); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
      echo "<tr style=\"background-color: rgb(240,240,200); color:fm=meldung&0000FF; font-weight:bold;\">\n";
      echo "<td>Vorst</td>\n";
      echo "<td>E/A</td>\n";
      echo "<td>Nw-Nr.</td>\n";
      echo "<td>Von</td>";
      echo "<td>An</td>";
      echo "<td>Abfasszeit</td>\n";
      // Funktionen und Farben
      for ( $i=1; $i<= count ($conf_empf); $i++ ) {
        if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
          echo "<td>";
          echo $conf_empf [$i]["fkt"];
          echo "</td>\n";
        }
      }
      echo "<td>Inhalt</td>\n";
      echo "</tr>";

      foreach ($result as $row){
         // VORRANGSTUFE
         if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ){
           echo "<tr style=\"background-color: rgb(255,255,0); color:fm=meldung&FFFFFF; font-weight:bold;\">\n";
         }
         echo "<td>";
         if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
           echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ;
         } else {
           echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
         }
         echo "</td>\n";

         // RICHTUNG Eingang / Ausgang
         echo "<td>";
         if (($row["04_richtung"] != "")) {
           echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_richtung"]."</a>\n";
         } else {
           echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
         }
         echo "</td>\n";

         // N a c h w e i s n u m m e r
         echo "<td>";
         if (($row["04_richtung"] != "")) {
           echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_nummer"]."</a>\n";
         } else {
           echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
         }
         echo "</td>\n";

/*

         if ($row["04_richtung"] == "A" ) {
           echo "<td>";
           if (($row["10_anschrift"] != "")) {
             echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";
           } else {
             echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
           }
           echo "</td>\n";
         } else {
           echo "<td>";

           // Absender / Einheit / Stelle / ...
           if (($row["13_abseinheit"] != "")) {
             echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["13_abseinheit"]."</a>\n";
           } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
           }
           echo "</td>\n";
         }
*/
//---------------------------
           echo "<td>";

           // Absender / Einheit / Stelle / ...
           if (($row["13_abseinheit"] != "")) {
             echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["13_abseinheit"]."</a>\n";
           } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
           }
           echo "</td>\n";

           echo "<td>";

           // Anschrift
           if (($row["10_anschrift"] != "")) {
             echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";
           } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
           }
           echo "</td>\n";
//-------------------------------------------------


         echo "<td>";
         // Abfassungs Z E I T
         if (($row["12_abfzeit"] != "")) {
           $abfzeit = convdatetimeto ($row["12_abfzeit"]);
           echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit[stak]."</a>\n";
         } else {
           echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
         }
         echo "</td>\n";

         // Funktionen und Farben
         $empfcolor = extraiereempfaenger ( $row ["16_empf"] ) ;
         for ( $i=1; $i<= count ($conf_empf); $i++ ) {
           if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
             switch ($empfcolor [$conf_empf [$i][fkt]]) {
               case "rt":
                                 echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["rt"]."; \">";
                                 echo "X";
               break;
               case "gn":
                 echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["gn"]."; \">";
                                 echo "X";
                                break;
               case "bl":
                 echo "<td style=\"text-align: center; background-color: ".$cfg["vbg"]["bl"]."; \">";
                         echo "X";
                           break;
               default:
                 echo "<td style=\"text-align: center; background-color: rgb(250, 250, 250); \">";
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>";
           }
         }

         // I N H A L T !
         echo "<td align=\"left\">";
         if (($row["12_inhalt"] != "")) {
           echo "<a href=\"ue_ltg.php?ueb_fm=ueb&00_lfd=".
                    $row["00_lfd"]."\" target=\"_self\">";
                    if ( inhalt_limit ){
                      echo substr($row["12_inhalt"], 0, $conf_4f_liste ["inhalt"])." ..."."</a>\n";
                    } else {
                      echo $row["12_inhalt"];
                    }
         } else {
           echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
         }
         echo "</td>\n";
         echo "</tr>";
      }
    }
    echo "</tbody></table>";

    $this->listen_navi ();

    echo "<!-- ENDE file:ue_ltg.php fkt:createlist -->";

  }


} // class


/*****************************************************************************\
   Klasse: nachrichten4fach

   konstruktor :

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
class nachrichten4fach {

    function nachrichten4fach ($formulardaten, $task, $errorselect){
      $this->task = $task ;
      $this->formdata = $formulardaten ;
      $this->lfd = $this->formdata ["00_lfd"];
      if ($this->formdata ["01_datum"] == "0000-00-00 00:00:00") { $this->formdata["01_datum"] = ""; }
      if ($this->formdata ["02_zeit"] == "0000-00-00 00:00:00") { $this->formdata ["02_zeit"] = ""; }
      if ($this->formdata ["03_datum"] == "0000-00-00 00:00:00") { $this->formdata ["03_datum"] = ""; }
      if ($this->formdata ["12_abfzeit"] == "0000-00-00 00:00:00") { $this->formdata ["12_abfzeit"] = ""; }
      if ($this->formdata ["15_quitdatum"] == "0000-00-00 00:00:00") { $this->formdata ["15_quitdatum"] = ""; }
      if ($this->formdata ["11_gesprnotiz"] == "t") {
        $this->formdata   ["11_gesprnotiz"] = true;
      } else {
        $this->formdata ["11_gesprnotiz"] = false;
      }
/*
echo "<br><br> ***" ;
echo " TASK = ".$this->task."<br>";;
var_dump ($this->formdata); echo "<br>";
*/
//      $this->init_vf () ; // setze die Farben des 4fach Vordrucks
      $this->plot_form () ;
    }

    var $task;        // text , Fuer welche Funktion ist der Vordruck
    var $formdata ;   // array, Formulardaten
    var $lfd ;        // integer, laufende Nummer der Nachricht
    var $errorselect; // array, Felder die falsch eingegeben wurden.

  // aktive und Inaktive Darstellungsfarben

  var $fktmsgbgcolor ;  // Hintergrundfarbe
  var $bg_color_fm_a   = "rgb(255, 224, 200)"; // Fernmelder aktiv
  var $bg_color_fmp_a  = "rgb(100, 255, 100)"; // Fernmelderpflichtfeld  aktiv
  var $bg_color_nw_a   = "rgb(255, 204, 51)";
  var $bg_color_tx_a   = "rgb(224, 255, 255)";
  var $bg_color_si_a   = "rgb(255, 224, 255)";
  var $bg_color_inaktv = "rgb(255, 255, 255)";  // "rgb(210, 210, 150)";
  var $bg_color_aktv   = "rgb(255, 255, 255)";
  var $rbl_bg_color    = "rgb(255, 255, 255)";
  var $bg_color_aktv_must = "rgb(240, 20, 20)";

  var $feldbg ;
  var $redcopy2;

  /****************************************************************************\
    Hintergrundfarben der Felder aktiv und inaktiv
  \****************************************************************************/
  function feldbgcolor (){
    if ( ( $this->task == "FM-Eingang") or
         ( $this->task == "FM-Eingang_Sichter" ) ) {
      $this->feldbg [ 1]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [10]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [12]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [13]["a"] = $this->bg_color_fmp_a;
    } else {
       $this->feldbg [ 1]["a"] = $this->bg_color_tx_a;
       $this->feldbg [10]["a"] = $this->bg_color_tx_a;
       $this->feldbg [12]["a"] = $this->bg_color_tx_a;
       $this->feldbg [13]["a"] = $this->bg_color_tx_a;
    }

//    $this->feldbg [ 1]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 1]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 2]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 2]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 3]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 3]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 4]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 4]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 5]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 5]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 6]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 6]["i"] = $this->bg_color_inaktv;

    $this->feldbg [ 7]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 7]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 8]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 8]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 9]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 9]["i"] = $this->bg_color_inaktv;

    $this->feldbg [10]["i"] = $this->bg_color_inaktv;
    $this->feldbg [11]["a"] = $this->bg_color_tx_a;
    $this->feldbg [11]["i"] = $this->bg_color_inaktv;

    $this->feldbg [12]["i"] = $this->bg_color_inaktv;

    $this->feldbg [13]["i"] = $this->bg_color_inaktv;
    $this->feldbg [14]["a"] = $this->bg_color_tx_a;
    $this->feldbg [14]["i"] = $this->bg_color_inaktv;

    $this->feldbg [15]["a"] = $this->bg_color_si_a;
    $this->feldbg [15]["i"] = $this->bg_color_inaktv;
    $this->feldbg [16]["a"] = $this->bg_color_si_a;
    $this->feldbg [16]["i"] = $this->bg_color_inaktv;
    $this->feldbg [17]["a"] = $this->bg_color_si_a;
    $this->feldbg [17]["i"] = $this->bg_color_inaktv;
  }

  // Zuordnung der notwendigen Farben
  var $bg;
  var $feld ;

/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function get_access_by_task (){
    // Alle Felder auf inaktiv setzen
    for ( $i = 0; $i <= 17; $i++ ){
      $this->bg [$i] = $this->feldbg [$i]["i"] ;
      $this->feld [$i] = false;
    }

    switch ($this->task) {
      // Annahme einer Meldung durch Fernmelder
      case "FM-Eingang" :
      case "FM-Eingang_Anhang" :

        $this->bg [1] = $this->feldbg [1]["a"] ;
        $this->feld [1] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        for ($i=7;$i<=14;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;
      case "FM-Eingang_Sichter" :
      case "FM-Eingang_Anhang_Sichter"  :
        $this->bg [1] = $this->feldbg [1]["a"] ;
        $this->feld [1] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        for ($i=7;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
        // Ausser Gespraechsnotiz
        $this->bg [11] = $this->feldbg [11]["i"] ;
        $this->feld [11] = true;

      break;
      // Weitergabe einer Meldung durch den Fernmelder
      case "FM-Ausgang" :
        $this->bg [2] = $this->feldbg [2]["a"] ;
        $this->feld [2] = true;
        $this->bg [3] = $this->feldbg [3]["a"] ;
        $this->feld [3] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        $this->bg [6] = $this->feldbg [6]["a"] ;
        $this->feld [6] = true;
      break;

      // Weitergabe einer Meldung durch den Fernmelder mit Sichterfunktion
      case "FM-Ausgang_Sichter" :
        $this->bg [2] = $this->feldbg [2]["a"] ;
        $this->feld [2] = true;
        $this->bg [3] = $this->feldbg [3]["a"] ;
        $this->feld [3] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        $this->bg [6] = $this->feldbg [6]["a"] ;
        $this->feld [6] = true;
        for ($i=15;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "Stab_schreiben" :
        for ($i=7;$i<=14;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "Stab_lesen" :
   /*   for ($i=7;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["i"] ;
          $this->feld [$i] = false;
        } */
        for ($i=1;$i<=17;$i++){
          $this->bg [$i] = $this->formbgcolor ;
          $this->feld [$i] = false;
        }

      break;

      case "Stab_sichten" :
      case "Stab_gesprnoti":
        for ($i=15;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "FM-Admin" :
        for ($i=1;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
      break;

      case "SI-Admin" :
        for ($i=15;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
      }
      break;

      default :
        for ($i=1;$i<=17;$i++){
          $this->feld [$i] = false;
        }
    } // switch $rolle
  }

  var $empfarray ;

/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function ziele (){
  include ("../4fcfg/fkt_rolle.inc.php");

    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        $this->empfarray [$i][$j]["checked"] = false;
        $this->empfarray [$i][$j]["cpycol"]  = "";
        $this->empfarray [$i][$j]["typ"]     = $empf_matrix [$i][$j]["typ"];
        $this->empfarray [$i][$j]["fkt"]     = $empf_matrix [$i][$j]["fkt"];
        $this->empfarray [$i][$j]["rolle"]   = $empf_matrix [$i][$j]["rolle"];
      }
    }
    $empf_text  = $this->formdata ["16_empf"] ; // Zeile mit den Empfaengern aus der DB
      // Wandel die Textzeile mit den Empfaengern in ein ARRAY um
    $empf_array_color = explode (",",$empf_text);

    for ( $i=0; $i <= count ( $empf_array_color ); $i++ ) {
        //  die Farbe der Kopie
      list ( $fkt, $cpycol ) = explode ("_", $empf_array_color [$i]);
      if ( $fkt != "" ){
        $empf_array [$i]['fkt'] = $fkt ;
        $empf_array [$i]['cpy'] = $cpycol ;
        if ($fkt == $_SESSION ['vStab_funktion']) {
          $this->fktmsgbgcolor = $cpycol ;
        }
      }
    }
    $sonstcount = 2;
    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        if (isset ($empf_array)){
          foreach ($empf_array as $empfaenger){
            if ( ( strtoupper ( $empfaenger['fkt'] ) ==  strtoupper ( $empf_matrix [$i][$j]["fkt"]) ) and
                 ( $empf_matrix [$i][$j]["fkt"] != "" ) ){
              $this->empfarray [$i][$j]["checked"] = true;
              $this->empfarray [$i][$j]["cpycol"] = $empfaenger['cpy'];
            }
          }
        }
      }
    }
  $this->redcopy2 = $redcopy2;
  }


/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
    // Listet unter Inhalt eventuelle Anhangsdateien als href auf
  function list_anhang (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
        include ("../4fcfg/e_cfg.inc.php");
      // in 12_anhang stehen die Anhangdateien mit ";" getrennt.
    echo "<br>";
    $anhaenge = split(";", $this->formdata ["12_anhang"]);
    foreach ($anhaenge as $anhang){
      if ($anhang != "") {
        echo "<a style=\"font-size:18px; font-weight:900;\" href=\"";
        echo $conf_4f ["ablage_uri"]."/".$anhang;
        echo "\" target=\"_blank\">";
        echo $anhang;
        echo "</a><br>";
      }
    }
  } // list_anhang ()


  var $formbgcolor ; // Hintergrundfarbe

/*****************************************************************************\
   Funktion     :  plot_form

   Beschreibung :  Ausgabe des Formulars

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function plot_form (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $this->ziele (); // Ziele und Farben   $fktmsgbgcolor

    switch ($this->fktmsgbgcolor) {
      case "rt": $this->formbgcolor =  $cfg ["vbg"]  ["rt"] ; break;
      case "gn": $this->formbgcolor =  $cfg ["vbg"]  ["gn"] ; break;
      case "bl": $this->formbgcolor =  $cfg ["vbg"]  ["bl"] ; break;
      case "ge": $this->formbgcolor =  $cfg ["vbg"]  ["ge"] ; break;
      default  : $this->formbgcolor =  $cfg ["vbg"]  ["default"] ;
    }
    $this->feldbgcolor ();
    $this->get_access_by_task ($this->task);

    pre_html ("N","Formular ".$this->task." ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"], ""); // Normaler Seitenaufbau ohne Auffrischung

    echo "<body style=\"text-align: left; background-color: rgb(255,255,255); \">\n"; //".$this->formbgcolor.";\">\n";
    echo "<body style=\"text-align: left; background-color: ".$formbgcolor.";\">\n";

    echo "<form style=\"\" method=\"get\" action=\"".$_SERVER ["PHP_SELF"]."\" name=\"4fach\">";

    echo "<a href=\"javascript:window.print()\">Diese Seite drucken</a>";

    switch ($this->task){
      case "FM-Eingang"         : $ueberschrift = "* * *   A N N A H M E  * * *"; break;
      case "FM-Eingang_Sichter" : $ueberschrift = "* * *   A N N A H M E / Sichtung  * * *"; break;
      case "FM-Eingang_Anhang"  : $ueberschrift = "* * *   A N N A H M E  * * *"; break;
      case "FM-Ausgang"         : $ueberschrift = "* * *   W E I T E R G A B E   * * *"; break;
      case "FM-Admin"           : $ueberschrift = "* * *   A D M I N I S T R A T I O N   * * *"; break;
      case "Stab_schreiben"     : $ueberschrift = "* * *   T E X T verfassen.   * * *"; break;
      case "Stab_lesen"         : $ueberschrift = "* * *   N A C H R I C H T lesen   * * *"; break;
      case "Sichter"            : $ueberschrift = "* * *   S I C H T U N G   * * *"; break;
      case "Nachweis"           : $ueberschrift = "* * *   N A C H W E I S U N G   * * *"; break;
    }
    echo "<big><big><big><b>".$ueberschrift."</b></big></big></big><br>\n";
    echo "\n\n<!-- ********** TABLE   001 Gesamte Tabelle *********** -->\n";

    echo "<!-- H A U P T T A B E L L E  -->";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 800px;\" border=\"1\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";

    echo "<tr><!-- 1. Zeile der Tabelle -->\n";
    echo "<td style=\"height: 113px; width: 800px;\">\n";

    echo "\n\n<!-- ********** TABLE   Eingang | Ausgang | Nachweisnummer  *********** -->\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; height: 32px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /***************************************************************************************
                              F M  -  B E T R I E B S S T E L L E
    */
    // Zeile, Spalte 1,1    EINGANG    1  1   Eingang
    echo "<td style=\"width: 230px; background-color: ".$this->bg[1].";\"><!--002-->\n";
    echo "<div style=\"text-align: center; width: 200px;\">EINGANG</div>\n";
    echo "</td><!--002-->\n";
    // Zeile, Spalte 1,2    AUSGANG    2  2   Ausgang Annahmevermerk Befoerderungsvermerk
    echo "<td style=\"text-align: center; background-color: ".$this->bg[2]."; width: 427px;\"><!--003-->\nAUSGANG</td><!--003-->\n";
    // Zeile, Spalte 1,3    Nachweisung   8   4   Nachweis Nummer E A
    echo "<td style=\"text-align: center; width: 150px; background-color: ".$this->bg[4].";\"><!--004-->\nNachweisnummer</td><!--004-->\n";
    echo "</tr><!--002-->\n";

    echo "<tr><!--003-->\n";
    /****************************************************************************\
    |  Zeile, Spalte 2 , 1   Aufnahmevermerk  1   1   Eingang                    |
    \****************************************************************************/
     if (!$this->feld [1]){
      $param = " disabled ";
    // Radio Button die deaktiviert sind liefern keinen Wert zurueck !!!
      echo "<input type=\"hidden\" name=\"01_medium\" value=\"".$this->formdata["01_medium"]."\">\n";
    }
    else {
      $param = "";
    }

    if  ($this->formdata["01_datum"] != "" ) {
      $arr = convdatetimeto ($this->formdata["01_datum"]);
      $this->formdata["01_datum"] = $arr [datum];
      $this->formdata["01_zeit"] = $arr [zeit];
    } else {
        $this->formdata["01_datum"] ="";
        $this->formdata["01_zeit"] = "";
    }

    echo "<td style=\"background-color: ".$this->bg[1]."; width: 230px; text-align: center; vertical-align: top;\"><!--005-->\n";
    echo "<div style=\"text-align: center;\">Aufnahmevermerk<br></div>\n";
    if ($this->formdata["01_medium"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["01_medium"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["01_medium"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if ($this->formdata["01_medium"]=="Fax") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"Fax\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["01_medium"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"01_medium\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS";
    echo "<br>\n";
/*468*/
    if (!$this->feld [1]){
      if ( ( $this->formdata["01_datum"] != "") or
           ( $this->formdata["01_zeit"]  != "" ) or
           ( $this->formdata["01_zeichen"] != "" ) ) {
        if ( posttakzeit ) {
          echo "<div style=\"text-align: center;\"><b>";
          $takzeit = konv_datetime_taktime (convtodatetime ($this->formdata["01_datum"], $this->formdata["01_zeit"]) );
          echo $takzeit."&nbsp; &nbsp;".$this->formdata["01_zeichen"];
          echo "</b></div>";
        } else {
        echo "<div style=\"text-align: center;\"><b>";
        echo $this->formdata["01_datum"]."&nbsp; &nbsp;".$this->formdata["01_zeit"]."&nbsp; &nbsp;".$this->formdata["01_zeichen"];
        echo "</b></div>";
        }
      } else {
        echo "<br>";
      }
    } else {
      echo "<input maxlength=\"4\" size=\"4\" name=\"01_datum\" value=\"".$this->formdata["01_datum"]."\">\n";
      echo "<input maxlength=\"4\" size=\"4\" name=\"01_zeit\" value=\"".$this->formdata["01_zeit"]."\">\n";
      echo "<input maxlength=\"3\" size=\"3\" name=\"01_zeichen\" value=\"".$this->formdata["01_zeichen"]."\">\n";
    }
//    echo "<br>\n";
    echo "<div style=\"text-align: center;\">";
    echo "Datum &nbsp; &nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td><!--005-->\n";
    echo "</div>";

    /****************************************************************************\
    | Zeile, Spalte 2 , 2+3  2   2   Ausgang Annahmevermerk +
    |                         4  3   Ausgang Beförderungsvermerk
    02_zeit
    02_zeichen
    \****************************************************************************/

    if ($this->formdata["02_zeit"] != "" ) {
      $arr = convdatetimeto ($this->formdata["02_zeit"]);
      $this->formdata["02_zeit"] = $arr [zeit];
    }   else {
      $this->formdata["02_zeit"] = "";
    }

    echo "<td style=\"width: 427px; background-color: ".$this->bg[2].";\"><!--006-->\n";
    echo "\n\n<!-- ********** TABLE   AUSGANG  *********** -->\n";
    echo "<table style=\"text-align: \"center\"; background-color: ".$this->rbl_bg_color."; width: 400px; height: 80px; margin-left: auto; margin-right: auto;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody><!--table + tbody 003-->\n";
    echo "<tr>\n";

    echo "<td style=\"height: 80px; width: 150px; background-color: ".$this->bg[2]."; text-align: center; vertical-align: top;\">\n";
    echo "<div style=\"text-align: center;\">Annahmevermerk<br></div>\n";

    if (!$this->feld[2]) {
      if ( ( $this->formdata["02_zeit"] != "" ) or
           ( $this->formdata["02_zeichen"] != "" ) ) {
        echo "<div style=\"text-align: center;\"><b>";
        echo $this->formdata["02_zeit"]."&nbsp; &nbsp;".$this->formdata["02_zeichen"];
        echo "</b></div>";
      } else {
        echo "<br>";
      }
    } else {
    echo "<input maxlength=\"4\" size=\"4\" name=\"02_zeit\" value=\"".$this->formdata["02_zeit"]."\">&nbsp;\n
          <input maxlength=\"3\" size=\"3\" name=\"02_zeichen\" value=\"".$this->formdata["02_zeichen"]."\"><br>\n";
    }
    echo "<div style=\"text-align: center;\">";
    echo "&nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</div>";

      if  ($this->formdata["03_datum"] != "" ) {
        $arr = convdatetimeto ($this->formdata["03_datum"]);
        $this->formdata["03_datum"] = $arr [datum];
        $this->formdata["03_zeit"] = $arr [zeit];
      }   else {
        $this->formdata["03_datum"] ="";
        $this->formdata["03_zeit"] = "";
     }

    echo "<td style=\"height: 80px; width: 220px; background-color: ".$this->bg[3]."; text-align: center; vertical-align: top;\">\n";
    echo "<div style=\"text-align: center;\">Bef&ouml;rderungsvermerk<br></div>\n";


    if (!$this->feld [3]){
      if ( ( $this->formdata["03_datum"]   != "") or
           ( $this->formdata["03_zeit"]    != "" ) or
           ( $this->formdata["03_zeichen"] != "" ) ) {
        if ( posttakzeit ) {
          echo "<div style=\"text-align: center;\"><b>";
          $takzeit = konv_datetime_taktime (convtodatetime ($this->formdata["03_datum"], $this->formdata["03_zeit"]) );
          echo $takzeit."&nbsp; &nbsp;".$this->formdata["03_zeichen"];
          echo "</b></div>";
        } else {
          echo "<div style=\"text-align: center;\"><b>";
          echo $this->formdata["03_datum"]."&nbsp; &nbsp;".$this->formdata["03_zeit"]."&nbsp; &nbsp;".$this->formdata["03_zeichen"];
          echo "</b></div>";
        }
      }else {
        echo "<br>";
      }
    } else {
      echo "<input maxlength=\"4\" size=\"4\" name=\"03_datum\" value=\"".$this->formdata["03_datum"]."\">\n";
      echo "<input maxlength=\"4\" size=\"4\" name=\"03_zeit\" value=\"".$this->formdata["03_zeit"]."\">\n";
      echo "<input maxlength=\"3\" size=\"3\" name=\"03_zeichen\" value=\"".$this->formdata["03_zeichen"]."\"><br>\n";
    }

    echo "<div style=\"text-align: center;\">";
    echo "Datum &nbsp; &nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</div>";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "\n<!-- E N D E ********** TABLE   AUSGANG  *********** -->\n\n";

    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 2 , 4    8   4   Nachweis Nummer E A
    04_richtung;
    04_nummer;
    \****************************************************************************/
    echo "<td style=\"width: 150px; background-color: ".$this->bg[4]."; text-align: left; vertical-align: top;\">Nachweis Nr.";

    if (!$this->feld[4]) {
        echo "<div style=\"text-align: center;\"><b><big><big><big>";
        echo $this->formdata["04_richtung"]."&nbsp; &nbsp;".$this->formdata["04_nummer"];
        echo "</big></big></big></b></div>";
    } else {
      echo "<input maxlength=\"6\" size=\"6\" name=\"04_nummer\" value=\"".$this->formdata["04_nummer"]."\"><br>\n";
      if (!$this->feld[4]) {
        $param = " disabled ";
        // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
        echo "<input type=\"hidden\" name=\"04_richtung\" value=\"".$this->formdata["04_richtung"]."\">\n";
      }
      else {
        $param = "";
      }

      if ($this->formdata["04_richtung"]=="E") {$sel = "checked=\"checked\"";} else {$sel = "";}
      echo "<input name=\"04_richtung\" value=\"E\" type=\"radio\" ".$param.$sel.">E<br>\n";
      if ($this->formdata["04_richtung"]=="A") {$sel = "checked=\"checked\"";} else {$sel = "";}
      echo "<input name=\"04_richtung\" value=\"A\" type=\"radio\" ".$param.$sel.">A<br>\n";
    }

    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "\n<!-- ********** E N D E    TABLE  Eingang | Ausgang | Nachweisung   *********** -->\n\n";

    echo "</td>\n";
    echo "</tr>\n";
    // Zeile 3

    echo "<tr>\n";

    // Zeile, Spalte 3 , 1  Rufname der Gegenst. 16   5   Rufname der Gegenstelle
    echo "<td>\n";

    echo "\n\n<!-- ********** TABLE   Rufnahme Gegenstelle *********** -->\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 821px; height: 52px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"width: 227px; background-color: ".$this->bg[5].";\">Rufname der Gegenstelle/<br>\n";
    echo "Spruchkopf</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 3 , 2   16   5   Rufname der Gegenstelle
    05_gegenstelle
    \****************************************************************************/

    echo "<td style=\"text-align: center; background-color: ".$this->bg[5]."; width: 580px;\">\n";
    if  (!$this->feld[5]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->formdata["05_gegenstelle"];
      echo "</b></div>";
    } else {
       echo "<input maxlength=\"80\" size=\"80\" name=\"05_gegenstelle\" value=\"".$this->formdata["05_gegenstelle"]."\">\n";
    }
    echo "</td>";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "\n<!-- ********** E N D E     TABLE  Rufname Gegenstelle  *********** -->\n\n";


    echo "</td>\n";
    echo "</tr>\n";

    // Zeile 4
    echo "<tr> ";
    echo "<td style=\"width: 821px; height: 40px;\">"; // align=\"center\" valign=\"MIDDLE\">\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 821px; height: 46px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";

    echo "<tbody>\n";
    echo "<tr>\n";
    // Zeile, Spalte 4 , 1   32   6   Beförderungsweg
    echo "<td style=\"width: 131px; background-color: ".$this->bg[6].";\">Bef&ouml;rderungsweg:</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 4 , 2   32   6   Beförderungsweg
    06_befweg
    \****************************************************************************/

    echo "<td style=\"text-align: center; width: 446px; background-color: ".$this->bg[6].";\">\n";
    if (!$this->feld[6]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->formdata["06_befweg"];
      echo "</b></div>";
    } else {
      echo "<input maxlength=\"50\" size=\"50\" name=\"06_befweg\" value=\"".$this->formdata["06_befweg"]."\">\n";
    }

    echo "</td>";

    /****************************************************************************\
    // Zeile, Spalte 4 , 3   32   6   Beförderungsweg
    06_befwegausw
    \****************************************************************************/
    if (!$this->feld[6]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input type=\"hidden\" name=\"06_befwegausw\" value=\"".$this->formdata["06_befwegausw"]."\">\n";
    }
    else {
      $param = "";
    }

    echo "<td style=\"width: 230px; background-color: ".$this->bg[6].";\">";

    if ($this->formdata["06_befwegausw"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["06_befwegausw"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["06_befwegausw"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if ($this->formdata["06_befwegausw"]=="Fax") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"Fax\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["06_befwegausw"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"06_befwegausw\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    /*                          F M  -  B E T R I E B S S T E L L E
    ********************************************************************************************
    ********************************************************************************************
                                            I N H A L T
    */

    echo "<tr>\n";

    echo "<td style=\"width: 831px; height: 0px;\" align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 821px; height: 64px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 5,1   64 7   Durchsage / Spruch
    \****************************************************************************/
    if (!$this->feld[7]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input type=\"hidden\" name=\"07_durchspruch\" value=\"".$this->formdata["07_durchspruch"]."\">\n";
    }
    else {
      $param = "";}

    echo "<td style=\"width: 126px; background-color: ".$this->bg[7].";\">\n";
    if ($this->formdata["07_durchspruch"]=="D") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"07_durchspruch\" value=\"D\" type=\"radio\" ".$param.$sel.">DURCHSAGE<br>\n";
    if ($this->formdata["07_durchspruch"]=="S") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"07_durchspruch\" value=\"S\" type=\"radio\" ".$param.$sel.">Spruch</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 5,2   128    8   Beförderungshinweis
    \****************************************************************************/

    echo "<td style=\"text-align: left; width: 140px; background-color: ".$this->bg[8].";\">Bef&ouml;rderungshinweis:<br>Tel.</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 5,3   128    8   Beförderungshinweis
    08_befhinweis
    \****************************************************************************/
    echo "<td style=\"width: 294px; background-color: ".$this->bg[8].";\">\n";
    if  (!$this->feld[8]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->formdata["08_befhinweis"];
      echo "</b></div>";
    } else {
      echo "<input maxlength=\"40\" size=\"40\" name=\"08_befhinweis\" value=\"".$this->formdata["08_befhinweis"]."\">";
    }
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 5,4   128    8   Beförderungshinweis
    08_befhinwausw
    \****************************************************************************/


    if  (!$this->feld[8]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input type=\"hidden\" name=\"08_befhinwausw\" value=\"".$this->formdata["08_befhinwausw"]."\">\n";
    }
    else {
      $param = "";
    }
    echo "<td style=\"width: 225px; background-color: ".$this->bg[8].";\">\n";

    if ($this->formdata["08_befhinwausw"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["08_befhinwausw"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["08_befhinwausw"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if ($this->formdata["08_befhinwausw"]=="Fax") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"Fax\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["08_befhinwausw"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"08_befhinwausw\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS";
    echo "</td>\n";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";

echo "<!-- BIS HIER BIN ICH GEKOMMEN !!! *************+++++++++++++*********************************************-->";

    echo "<tr>\n";
    echo "<td style=\"text-align: left; background-color: ".$this->rbl_bg_color."\" align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 819px; height: 100px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 6,1   Vorrangstufe     256   9   VORRANGSTUFE !!!
    09_vorrangstufe;
    \****************************************************************************/
    echo "<td style=\"width: 90px; background-color: ".$this->bg[9].";\">Vorrangstufe<br>\n";

    if (((($this->formdata["09_vorrangstufe"]) != "" )) or (!$this->feld[9])) {
      echo "<div style=\"text-align: center; font-size:24px; font-weight:900;\"><big><big><b>";
      echo $this->formdata["09_vorrangstufe"];
      echo "</big></big></b></div>";
    } else {
      echo "<select ".$param." name=\"09_vorrangstufe\">\n";
      if ($this->formdata["09_vorrangstufe"]=="") {$sel = " selected ";} else {$sel = "";}
      echo "<option ".$sel."></option>\n";
      if ($this->formdata["09_vorrangstufe"]=="eee") {$sel = " selected ";} else {$sel = "";}
      echo "<option ".$sel.">eee</option>\n";
      if ($this->formdata["09_vorrangstufe"]=="sss") {$sel = " selected ";} else {$sel = "";}
      echo "<option ".$sel.">sss</option>\n";
      if ($this->formdata["09_vorrangstufe"]=="bbb") {$sel = " selected ";} else {$sel = "";}
      echo "<option ".$sel.">bbb</option>\n";
      if ($this->formdata["09_vorrangstufe"]=="aaa") {$sel = " selected ";} else {$sel = "";}
      echo "<option ".$sel.">aaa</option>\n";
    }
    echo "</select></td>\n";

    /****************************************************************************\
    // Zeile, Spalte 6,2   Anschrift      512 10  Anschrift
    10_anschrift
    \****************************************************************************/
    echo "<td style=\"width: 600px; background-color: ".$this->bg[10].";\">Anschrift<br>\n";

    if (!$this->feld[10]) {
      echo "<div style=\"text-align: center; font-size:24px; font-weight:900;\">";
      echo $this->formdata["10_anschrift"] ;
      echo "</div>\n";

    } else {
      echo "<div style=\"text-align: center;\">";
      echo "<textarea style=\"font-size:18px; font-weight:900;\" cols=\"40\" rows=\"2\" name=\"10_anschrift\">".$this->formdata["10_anschrift"] ;
      echo "</textarea></div>\n";
    }


    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 6,3   Gesprächsnotiz    1024 11  Gesprächsnotiz
    11_gesprnotiz
    \****************************************************************************/
    if (((($this->formdata["11_gesprnotiz"]) != "" )) or (!$this->feld[11])) {
      $param = " disabled ";}
    else {
      $param = "";}

    echo "<td style=\"width: 110px; background-color: ".$this->bg[11].";\">Gespr&auml;chsnotiz<br>\n";
    echo "<div style=\"text-align: center;\">";

    if ($this->formdata["11_gesprnotiz"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"11_gesprnotiz\" type=\"checkbox\" ".$param.$sel."><br>\n";

    echo "</div>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"TOP\">\n";

    /****************************************************************************\
    // Zeile, Spalte 7,1  Inhalt   2048   12  Inhalt, Abfassungszeit
    12_inhalt
    \****************************************************************************/
    echo "<table style=\"text-align: left; width: 820px; height: 216px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    if  (!$this->feld[12]) {
      $param = " readonly ";}
    else {
      $param = "";}
    echo "<td valign=\"TOP\" style=\"background-color: ".$this->bg[12].";\">Inhalt/Text:<br>\n";
    if  ($this->feld[12]) {
      echo "<div style=\"text-align: center;\">";
      echo "<textarea style=\"font-size:18px; font-weight:900;\" cols=\"65\" rows=\"10\" name=\"12_inhalt\"".$param.">".$this->formdata["12_inhalt"];
      echo "</textarea></div>\n";
    } else {
      echo "<div style=\"text-align: left; font-size:18px; font-weight:900;\">";
      echo "<input type=\"hidden\" name=\"12_inhalt\" value=\"".$this->formdata["12_inhalt"]."\">\n";
      echo nl2br ( $this->formdata["12_inhalt"]) ;
      echo "</div>";
    }
      // Sind Anhge definiert? Wenn ja, anzeigen.
    if ($this->formdata["12_anhang"] != ""){
      echo "<input type=\"hidden\" name=\"12_anhang\" value=\"".$this->formdata["12_anhang"]."\">\n";
      $this->list_anhang ();
    }
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr>\n";
    echo "<td style=\"text-align: left; background-color: ".$this->rbl_bg_color."; align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 817px; height: 34px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 8,1     2048 12  Inhalt, Abfassungszeit
    \****************************************************************************/

    echo "<td style=\"width: 135px; background-color: ".$this->bg[12].";\">Abfassungszeit:</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 8,2     4096 13  Absender, Einheit
    12_abfzeit
    \****************************************************************************/
    if  ($this->formdata["12_abfzeit"] != "" ) {
        $this->formdata["12_abfzeit"] = konv_datetime_taktime ($this->formdata["12_abfzeit"]);
    }   else {
        $this->formdata["12_abfzeit"] = "";
    }

    echo "<td style=\"width: 600px; background-color: ".$this->bg[13].";\">\n";

    if (!$this->feld [12]){
      echo "<div style=\"text-align: left; font-size:24px; font-weight:900;\">";
      echo $this->formdata["12_abfzeit"] ;
      echo "<input type=\"hidden\" name=\"12_abfzeit\" value=\"".$this->formdata["12_abfzeit"]."\">\n";
      echo "</div>\n";
    } else {
      echo "<input maxlength=\"4\" size=\"4\" name=\"12_abfzeit\" value=\"".$this->formdata["12_abfzeit"]."\">";
    }

    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";

    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"top\">\n";

    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 817px; height: 54px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    // Zeile, Spalte 9,1    4096  13  Absender, Einheit
    echo "<td style=\"width: 100px; background-color: ".$this->bg[13].";\">Absender</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 9,2    8192  14  Zeichen Funktion
    13_abseinheit
    \****************************************************************************/
    echo "<td style=\"text-align: left; width: 200px; background-color: ".$this->bg[13].";\">\n";

    if (!$this->feld [13]){
      echo "<b><big>".$this->formdata["13_abseinheit"]."</big></b>" ;
      echo "<input type=\"hidden\" name=\"13_abseinheit\" value=\"".$this->formdata["13_abseinheit"]."\">\n";
    }
    else {
      echo "<div style=\"text-align: left;\" >";
      echo "<input style=\"font-size:16px; font-weight:900;\" maxlength=\"15\" size=\"15\"
              name=\"13_abseinheit\" value=\"".$this->formdata["13_abseinheit"]."\">";
      echo "</div>\n";
    }
    echo "<br>\n";
    echo "Einheit/Einrichtung/Stelle";
    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 9,3 Zeichen     8192 14  Zeichen Funktion
    14_zeichen
    \****************************************************************************/
    echo "<td style=\"width: 100px; background-color: ".$this->bg[14].";\">\n";
    if (!$this->feld [14]){
//      echo "<div style=\"text-align: left; font-size:24px; font-weight:900;\">";
      echo "<b><big>".$this->formdata["14_zeichen"]."</big></b><br>" ;
      echo "<input type=\"hidden\" name=\"14_zeichen\" value=\"".$this->formdata["14_zeichen"]."\">\n";
//      echo "</div>\n";
    } else {
      echo "<input maxlength=\"25\" size=\"10\" name=\"14_zeichen\" value=\"".$this->formdata["14_zeichen"]."\"><br>\n";
    }
    echo "Zeichen</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 9,4 Funktion    8192 14  Zeichen Funktion
    14_funktion
    \****************************************************************************/
    echo "<td style=\"width: 100px; background-color: ".$this->bg[14].";\">\n";
    if (!$this->feld [14]){
//      echo "<div style=\"text-align: left; font-size:24px; font-weight:900;\">";
      echo "<b><big>".$this->formdata["14_funktion"]."</big></b><br>" ;
      echo "<input type=\"hidden\" name=\"14_funktion\" value=\"".$this->formdata["14_funktion"]."\">\n";
//      echo "</div>\n";
    } else {
      echo "<input maxlength=\"25\" size=\"10\" name=\"14_funktion\" value=\"".$this->formdata["14_funktion"]."\"".$param."><br>\n";
    }
    echo "Funktion</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    /*                                   I N H A L T
    ********************************************************************************************
    ********************************************************************************************
                                        S I C H T E R
    */
    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; width: 820px; height: 229px; background-color: ".$this->rbl_bg_color.";\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"width: 415px; background-color: ".$this->bg[15].";\">\n";
    echo "<table style=\"text-align: left; width: 418px; height: 65px;\" border=\"0\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";

    /****************************************************************************\
    // Zeile, Spalte 10,1 Quittung     16384  15  Quittung Sichter
    15_quitdatum
    15_quitzeichen
    \****************************************************************************/
    echo "<td style=\"width: 109px; background-color: ".$this->bg[15].";\">Quittung:<br></td>\n";
    echo "<td style=\"width: 289px; background-color: ".$this->bg[15].";\">\n";

    if  ($this->formdata["15_quitdatum"] != "" ) {
        $arr = convdatetimeto ($this->formdata["15_quitdatum"]);

        $this->formdata["15_quitdatum"] = $arr [zeit];
    }   else {
        $this->formdata["15_quitdatum"] = "";
    }

    if (!$this->feld [15]){
      echo "<div style=\"text-align: left;\">";
      echo $this->formdata["15_quitdatum"]."&nbsp;&nbsp;".$this->formdata["15_quitzeichen"];
      echo "</div>\n";

    } else {
    echo "<input maxlength=\"4\" size=\"4\" name=\"15_quitdatum\" value=\"".$this->formdata["15_quitdatum"]."\">&nbsp;\n";
    echo "<input maxlength=\"3\" size=\"3\" name=\"15_quitzeichen\" value=\"".$this->formdata["15_quitzeichen"]."\"><br>\n";
    }

    echo "&nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";


    echo "<table style=\"text-align: left; width: 450px; height: 144px; background-color: ".$this->rbl_bg_color.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";

    /****************************************************************************\
    // Zeile, Spalte 11,1   32768 16  Ziele
    16_empf
    \****************************************************************************/



    if ((!$this->feld[16])) {
      $param = " disabled ";}
    else {
      $param = "";}

    switch ($this->task) {
      case "SI-Admin":
      case "FM-Eingang_Sichter":
      case "Stab_sichten":
      case "Stab_gesprnoti":
      case "FM-Eingang_Anhang_Sichter":
      case "FM-Admin":
      case "FM-Ausgang_Sichter":
      case "SI-Admin":

        for ($m=1; $m<=5; $m++){ // Zeilen
          echo "<tr>";
          for ($n=1; $n<=4; $n++){  // Spalten
            // rote Kopie geht an...
            if ( ( $this->empfarray [$m][$n]["fkt"] == $this->redcopy2 ) and
                 ( $this->feld[16]) ) { // Wenn Sichter aktiv und rote Kopie
              echo "<td style=\"width: 75px; background-color: rgb(255,0,0);\">";
            }else{
              echo "<td style=\"width: 75px; background-color: ".$this->bg[16].";\">";
            }

// echo "empfarray ===>"; print_r(  $this->empfarray [$m][$n] ); echo "<br>";

            switch ($this->empfarray [$m][$n]["typ"]){

              case "cb":
                if ( ( $this->empfarray [$m][$n]["checked"]) and
                     ( $this->empfarray [$m][$n]["cpycol"] == "gn" ) ) {
                  $selcbgn = "checked=\"checked\"";} else {$selcbgn = "";}

                if ( ( $this->empfarray [$m][$n]["checked"]) and
                     ( $this->empfarray [$m][$n]["cpycol"] == "bl" ) ) {
                  $selcbbl = "checked=\"checked\"";} else {$selcbbl = "";}

                echo "<a style=\"background-color:#00B000;\">
                      <input name=\"16_gncopy\" type=\"radio\" ".$selcbgn." value=\"16_".$m.$n."_gn\">\n";

                echo "<a style=\"background-color:#0303FD;\">
                      <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."_bl\" type=\"checkbox\" ".$param.$selcbbl.">\n</a>";

                echo $this->empfarray [$m][$n]["fkt"] ;
              break;

              case "t":
                if ($this->empfarray [$m][$n]["fkt"] != ""){
                  echo $this->empfarray [$m][$n]["fkt"] ;
                } else {
                  echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
                }
              break;

              case "cbt":
                if ($this->empfarray [$m][$n]["checked"]) {$selcb = "checked=\"checked\"";} else {$selcb = "";}
                echo "<a style=\"background-color:#00B000;\">
                      <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel."></a>\n";
                echo "<input maxlength=\"8\" size=\"8\" value=\"".$this->empfarray [$m][$n]["fkt"]."\" name=\"16_empf_sonst_".$m.$n."\" ".$param."></td>\n";
              break;
            }

            echo "</td>\n";
          } // for $n
          echo "</tr>\n";
        } // for $m
    break;

      case "Stab_lesen":
      case "Stab_schreiben":
      case "FM-Ausgang":
      case "FM-Eingang":
      case "FM-Eingang_Anhang":

    for ($m=1; $m<=5; $m++){ // Zeilen
      echo "<tr>";
      for ($n=1; $n<=4; $n++){  // Spalten
        echo "<td style=\"width: 75px; background-color: ".$this->bg[16].";\">";
        switch ($this->empfarray [$m][$n]["typ"]){

          case "cb":
            if ($this->empfarray [$m][$n]["checked"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
            echo "<input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel.">\n";
            echo $this->empfarray [$m][$n]["fkt"] ;
          break;

          case "t":
            if ($this->empfarray [$m][$n]["fkt"] != ""){
              echo $this->empfarray [$m][$n]["fkt"] ;
            } else {
              echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
            }
          break;

          case "cbt":
            if ($this->empfarray [$m][$n]["checked"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
            echo "<a style=\"background-color:#00B000;\">
                  <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel."></a>\n";
            echo "<input maxlength=\"8\" size=\"8\" value=\"".$this->empfarray [$m][$n]["fkt"]."\" name=\"16_empf_sonst_".$m.$n."\" ".$param."></td>\n";
          break;
        }

        echo "</td>\n";
      } // for $n
      echo "</tr>\n";
    } // for $m
       break;

    } // switsch $this->task

    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 10,2  Vermerke      65536    17  Vermerke
    17_vermerke
    \****************************************************************************/
    echo "<td  valign=\"TOP\" style=\"text-align: left; width: 350px; background-color: ".$this->bg[17].";\">Vermerke:<br>\n";

    if (((($this->formdata["17_vermerke"]) != "" )) or (!$this->feld[17])) {
      echo $this->formdata["17_vermerke"];
    } else {
      echo "<textarea cols=\"40\" rows=\"10\" name=\"17_vermerke\" ".$param.">".$this->formdata["17_vermerke"]."</textarea>";
    }

    echo "</td>\n";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "<table style=\"text-align: left; background-color: rgb(255, 255, 255);\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";

    if ($this->task == "Stab_lesen"){
      echo "<tr><td>\n";
      echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
      echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
      echo "<input type=\"image\" name=\"ablesen\" src=\"".$conf_design_path."/isread.gif\">\n";
      echo "</td></tr>\n";
    } else {
      echo "<tr><td>\n";
      echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
      echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
      echo "<input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/send.gif\">\n";
      echo "</td><td>\n";
      echo "<input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\">\n";
      echo "</td></tr>\n";
    }
    echo "</tbody>\n</table>\n";
    echo "<br>\n";
    echo "</form>\n";
    //echo "TASK=".$this->task."<br>";
    echo "</body>\n";
    echo "</html>\n";
  } // function plot_form

} // class

/*############################################################################
##############################################################################*/

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
  if (!isset ( $_SESSION["ueb_flt_darstellung"])){
    $_SESSION["ueb_flt_darstellung"] = 1;
    $_SESSION["ueb_flt_erledigt"]    = 0;
    $_SESSION["ueb_flt_unerledigt"]  = 1;
    $_SESSION["ueb_flt_anzahl"]      = 5;
    $_SESSION["ueb_flt_start"]       = 0 ;
    $_SESSION["ueb_flt_position"]    = 0;
  }
/*
  // filtern EIN / AUS
  if ( (isset ($_GET["ueb_flt_darstellung_aus_x"])) or
       (isset ($_GET["ueb_flt_darstellung_ein_x"])) ){

    if ( ($_SESSION["ueb_flt_darstellung"] == 1) and (isset ($_GET["ueb_flt_darstellung_aus_x"])) ) {
      $_SESSION["ueb_flt_darstellung"] = 0;
    } elseif ( ($_SESSION["ueb_flt_darstellung"] == 0) and (isset ($_GET["ueb_flt_darstellung_ein_x"])) ){
      $_SESSION["ueb_flt_darstellung"] = 1;
    }
  }

  // erledigte SICHTAR UNSICHTBAR
  if ( (isset ($_GET["ueb_flt_erledigt_aus_x"])) or
       (isset ($_GET["ueb_flt_erledigt_ein_x"])) ){

    if ( ($_SESSION["ueb_flt_erledigt"] == 1) and (isset($_GET["ueb_flt_erledigt_aus_x"])) ) {
      $_SESSION["ueb_flt_erledigt"] = 0;
    } elseif ( ($_SESSION["ueb_flt_erledigt"] == 0) and (isset ($_GET["ueb_flt_erledigt_ein_x"])) ){
      $_SESSION["ueb_flt_erledigt"] = 1;
    }
  }
  // unerledigte SICHTBAR UNSICHTBAR
  if ( (isset ($_GET["ueb_flt_unerledigt_aus_x"])) or
       (isset ($_GET["ueb_flt_unerledigt_ein_x"])) ){

    if ( ($_SESSION["ueb_flt_unerledigt"] == 1) and (isset($_GET["ueb_flt_unerledigt_aus_x"])) ) {
      $_SESSION["ueb_flt_unerledigt"] = 0;
    } elseif ( ($_SESSION["ueb_flt_unerledigt"] == 0) and (isset ($_GET["ueb_flt_unerledigt_ein_x"])) ){
      $_SESSION["ueb_flt_unerledigt"] = 1;
    }
  }
*/
  // finde Menü
  if ( (isset ($_GET["ueb_flt_find_mask_aus_x"])) or
       (isset ($_GET["ueb_flt_find_mask_ein_x"])) ){

    if ( ($_SESSION["ueb_flt_find_mask"] == 1) and (isset($_GET["ueb_flt_find_mask_aus_x"])) ) {
      unset ($_SESSION["ueb_flt_search"]);
      $_SESSION["ueb_flt_find_mask"] = 0;
    } elseif ( ($_SESSION["ueb_flt_find_mask"] == 0) and (isset ($_GET["ueb_flt_find_mask_ein_x"])) ){
      $_SESSION["ueb_flt_find_mask"] = 1;
    }
  }

  if (isset($_GET["ueb_flt_suche_reset"])){ unset ($_SESSION["ueb_flt_search"]); }

  if (isset($_GET["ueb_flt_suche"])){
    if ($_SESSION["ueb_flt_search"] != $_GET ["ueb_flt_search"]){
      $_SESSION["ueb_flt_start"] = 0 ;
      $_SESSION["ueb_flt_position"] = 0;
    }
    $_SESSION["ueb_flt_search"] = $_GET ["ueb_flt_search"];
  }

  if (isset ($_GET["ueb_flt_anzahl_x"])) {
    $_SESSION["ueb_flt_anzahl"] = $_GET["ueb_flt_anzahl"]; }

  if (isset($_GET[ueb_flt_start_x])) { $_SESSION[ueb_flt_navi] = "start";}
  if (isset($_GET[ueb_flt_back_x]))  { $_SESSION[ueb_flt_navi] = "back";}
  if (isset($_GET[ueb_flt_for_x]))   { $_SESSION[ueb_flt_navi] = "for";}
  if (isset($_GET[ueb_flt_end_x]))   { $_SESSION[ueb_flt_navi] = "end";}



  /**********************************************************************\
    Überprüfe ob die Listendarstellung geaendert werden soll
  \**********************************************************************/


/**********************************************************************\
  ---  M e l d u n g   l e s e n ---

  Darstellung der Meldung ber die laufende Nummer
\**********************************************************************/
   if (( $_GET["ueb_fm"] == "ueb")){
      $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
      $query = "SELECT * FROM `".$conf_4f_tbl ["nachrichten"]."` where 00_lfd = ".$_GET["00_lfd"];
      $result = $dbaccess->query_table ($query);
      $formdata = $result [1];
      $form = new nachrichten4fach ($formdata, "Stab_lesen", "");
   }



  if ( !isset( $_GET["ueb_fm"])) {

    if ( isset ($_GET["ueb_flt_submit"])) { // es soll was geändert werden
      if ($_GET["ueb_flt_darstellung"] == "on") {$_SESSION["ueb_flt_darstellung"] = 1;
        if (isset ($_GET["ueb_flt_anzahl"])) {$_SESSION["ueb_flt_anzahl"] = $_GET["ueb_flt_anzahl"]; }
        else {
          $_SESSION["ueb_flt_anzahl"] = 5;
        }
      } else {
        $_SESSION["ueb_flt_darstellung"] = 0;
        unset ($_SESSION["ueb_flt_anzahl"]);
      }
    }


    $list = new listen ("SIADMIN", "");
    $list->createlist ();
  }

?>
