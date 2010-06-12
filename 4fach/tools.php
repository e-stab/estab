<?php
/*****************************************************************************\
   Datei: tools.php

   benoetigte Dateien:

   Beschreibung:


   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
                              
include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
include ("../para.inc.php");

  function pre_html ($art, $titel, $cssstr){
    include ("../para.inc.php");
       echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
       echo "<html>\n";
       echo "<head>\n";
       echo "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=iso\">";
   switch ($art){
     case "N":
     break;
     case "status":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo "<meta http-equiv=\"refresh\" content=\"".$cfg ["itv"] ["status"]."\">\n";
     break;
     case "fmdliste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo "<meta http-equiv=\"refresh\" content=\"".$cfg ["itv"] ["fmdliste"]."\">\n";
     break;
     case "stabliste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo "<meta http-equiv=\"refresh\" content=\"".$cfg ["itv"] ["stabliste"]."\">\n";
     break;
     case "siliste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo "<meta http-equiv=\"refresh\" content=\"".$cfg ["itv"] ["siliste"]."\">\n";
     break;
     case "si2liste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo "<meta http-equiv=\"refresh\" content=\"".$cfg ["itv"] ["si2liste"]."\">\n";
     break;
     default:
echo "<big><big><big>DEFAULT PRE_HTML !!!</big></big></big><br>";
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
     break;
   }//switch
   echo "<title>".$titel." ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"]."</title>\n";
   echo "<style type=\"text/css\">";
   echo $cssstr."\n";
   echo "</style>";
   echo "</head>\n";
  }

  function convtodate ($datum){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $jahr   = date ("Y");
    $date = $jahr."-".$monat."-".$tag ;
    return $date ;
  }

  function convtotime ($zeit){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $time = $stunde.":".$minute.":00";
    return $time;
  }

  function convtodatetime ($datum, $zeit){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $jahr   = date ("Y");
    $datetime = $jahr."-".$monat."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }

  /****************************************************************************\
  | Umwandlung von Datum und Zeit ==> Datetimeformat
  | Formateingang: YYYY-MM-TT HH:mm:ss
  \****************************************************************************/
  function convdatetimeto ($datetime){
    list ($datum, $zeit) = explode (" ",$datetime);
    list ($jahr, $monat, $tag) = explode ("-", $datum);
    list ($stunde, $minute, $sekunde) = explode (":", $zeit);
    $arr ["datum"] = $tag.$monat;
    $arr ["zeit"]  = $stunde.$minute;
    $arr ["stak"]  = $tag.$stunde.$minute;
    return $arr;
  }


  function convdbdatetimeto ($datetime){
    list ($datum, $zeit) = explode (" ",$datetime);
    list ($jahr, $monat, $tag) = explode ("-", $datum);
    list ($stunde, $minute, $sekunde) = explode (":", $zeit);
    $arr [datum] = $datum;
    $arr [zeit]  = $zeit;
    return $arr;
  }


  function getoutqueuecount (){
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."` WHERE ((`04_richtung` = \"A\") AND
                                                  (`03_datum` = 0) AND
                                                  (`03_zeichen` = \"\"));";
   $result = $dbaccess->query_table_wert ($query);
    return $result[0];
  }

  function getviewerqueuecount (){
//    include ("../config.inc.php");
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."`
              WHERE ( (  `15_quitdatum`    = 0 ) AND
                      (  `15_quitzeichen`  = 0 ) )  AND
                    ( (  `04_richtung`     =\"E\") OR
                      (  `03_datum`       != 0 ) AND
                      (  `03_zeichen`     != \"\" ) );";
   $result = $dbaccess->query_table_wert ($query);
    return $result[0];
  }



  function getreadedcount (){
    include ("../config.inc.php");
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."`
              WHERE ( `16_empf` like '%".$_SESSION ["vStab_funktion"]."%' ) ;";

    $result = $dbaccess->query_table_wert ($query);
    $gesamtmeldungen = $result[0];

    $tblusername = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"])."_".strtolower ($_SESSION["vStab_kuerzel"]);
    $query = "SELECT count(*) FROM `".$tblusername."_read"."`
              WHERE 1 ;";
    $result = $dbaccess->query_table_wert ($query);
    $gelesenemeldungen = $result[0];

    return $gesamtmeldungen-$gelesenemeldungen;
  }



  function showsrvtime ($dir){
    echo "<table align=\"center\" style=\"text-align:center; background-color: \"\"; height: 52px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"2\">\n";
    echo "<tbody>";
      $hour = date ("H");
      $min  = date ("i");
      $day  = date ("d");
      $mom  = date ("m");
      $year = date ("Y");
    if ($dir == "vertikal"){
      echo "<tr><td style=\"text-align:center;\">";
      echo "<span style=\"font-size:1.2em\">$day.$mom</span>";
      echo "</td></tr>";
      echo "<tr><td style=\"text-align:center;\">";
      echo "<span style=\"font-size:1.2em\">$year</span>";
      echo "</td></tr>";
      echo "<tr><td style=\"text-align:center;\">";
      echo "<span style=\"font-size:1.2em\">$hour:$min</span>";
      echo "</td></tr>";
    }// if direction
    echo "</tbody>";
    echo "</table>";
  }

  function sichter_online (){
//    include ("../config.inc.php");
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["benutzer"]."` WHERE ( ( `funktion` = \"Si\" ) AND ( `aktiv` = 1 ));";
    $result = $dbaccess->query_table_wert ($query);
    return ($result[0] > 0);
  }


/******************************************************************************
Gibt eine Tabelle aus in der alle angemeldeten Rollen und Funktionen
bersichtlich dargestellt werden.
******************************************************************************/
  function systemstatus ($direction){

    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    include ("../fkt_rolle.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["benutzer"]."` where 1";
    $result = $dbaccess->query_table ($query);
    $benutzer = $result ;
    $aktiv    = " rgb(100, 250,  20); color:&000000; ";
    $inaktiv  = " rgb(250,  60,  30); color:&FFFF00; ";
    $self     = " rgb( 50, 180, 220); color:&ffffff; ";

    $fernm_aw = 0;

    for ($i=1; $i <= count ($conf_empf); $i++){
      $userstatus [ ($conf_empf[$i]["rolle"]) ]  [ ($conf_empf[$i]["fkt"]) ]  = $inaktiv;
    }

    if ((count ($benutzer) > 0) and ($benutzer != "")){
      foreach ($benutzer as $user){
        if ($user[aktiv] == 1 ) {
          $userstatus [$user[rolle]]  [$user[funktion]]  = $aktiv;}

        if ( ($user ["funktion"] == "A/W") AND ($user["aktiv"] == 1) ){ $fernm_aw ++;}
      }
    }
    $userstatus [$_SESSION["vStab_rolle"]][$_SESSION["vStab_funktion"]] = $self;
    if ($direction == "horizontal"){
      echo "<fieldset>";
      echo "<legend><b><big>Funktionsbersicht</big></b></legend>\n";
      echo "<table style=\"text-align:left; background-color: rgb(150, 150, 150); height: 32px; font-size:9pt; border=\"3\" cellpadding=\"5\" cellspacing=\"5\">\n";
      echo "<tbody>\n";
      echo "<tr>\n"; // Zeilen begin

      echo "<td style=\"background-color: ".$userstatus ["Stab"]["LS"]." font-weight:bold;\">LS</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S1"]." font-weight:bold;\">S1</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S2"]." font-weight:bold;\">S2</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S3"]." font-weight:bold;\">S3</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S4"]." font-weight:bold;\">S4</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S5"]." font-weight:bold;\">S5</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S6"]." font-weight:bold;\">S6</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["Si"]." font-weight:bold;\">Si</td>\n";

      echo "<td style=\"background-color: ".$userstatus ["FB"]["BS"]." font-weight:bold;\">BS</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["Fm"] ." font-weight:bold;\">Fm</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["ABC"]." font-weight:bold;\">ABC</td>\n";

      echo "<td style=\"background-color: ".$userstatus ["FB"]["THW"] ." font-weight:bold;\">THW</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["Bt"]." font-weight:bold;\">Bt</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["San"] ." font-weight:bold;\">San</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["Vers"]." font-weight:bold;\">Vers</td>\n";


      echo "<td style=\"background-color: ".$userstatus ["FB"]["Pol"] ." font-weight:bold;\">Pol</td>\n";

      // Zeige wenigstens einen inaktiven Fermelder an
      if ($fernm_aw > 0) {
        for ($i=1; $i <= $fernm_aw; $i++) {
           echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">A/W</td>\n";
        }
      } else {  // keiner aktiv ==> einer inaktiv
         echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">A/W</td>\n";
      }

      echo "</tr>";
      echo "</tbody></table>\n";
      echo "</fieldset>\n";

    }


    if ($direction == "vertikal") {
      $zellenbreite = "50";
      $zellenhoehe  = "20";
/*
      echo "<table align=\"center\" style=\"text-align:center; width:$width; background-color: rgb(150, 150, 150);  font-size:9pt; height: 32px;\" border=\"0\" cellpadding=\"1\" cellspacing=\"1\">\n";
      echo "<tbody>\n";
*/

/******************************************************************************\
  1. erste Zeile doppelt oder einfach

\******************************************************************************/


      $i = 1;
      $doppel = false;  // zweisymbole nebeneinander ja/nein
      $prefdoppelt = false; // vorgaenger doppelt

      while ( $i <= count ($conf_empf) ){

        if ($i == 1) {
           echo "<!-- 001 list.php -->\n";
           echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite.";
                  height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);
                  font-size:9pt; border=\"1\" cellpadding=\"1\" cellspacing=\"1\">\n";
           echo "<tbody>\n";
           $tableisset = true;
        }

        if ( ( strlen( $conf_empf [$i]["fkt"] ) <= 2 ) and
             ( strlen( $conf_empf [$i+1]["fkt"] ) <= 2 )and
             ( $i <= count ($conf_empf) -1 ) ) { // die naechsten zwei sind max zweistellig
          if (!$statusalt){ $absneu = true; }  // dann
            $doppel = true;
          } else {
            $doppel = false;
          }

        if (($prefdoppelt != $doppel) and ($i >1) )  {
           echo "<!-- 002 list.php -->\n";
           echo "</tbody>"; echo "</table>\n";
           $tableisset = false;
        }
        if ( ( $doppel != $prefdoppelt) and !($tableisset) ) {
           echo "<!-- 003 liste.php -->\n";
           echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite."; height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);  font-size:9pt; height:".$zellenhoehe.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"1\">\n";
           echo "<tbody>\n";
           $tableisset = true;
           $absneu = false;
           $prefdoppelt = $doppel;
        }

        if ( ($doppel) and ($conf_empf[$i]["fkt"] != "A/W") ) {

          echo "<!-- 004 liste.php -->\n";
          echo "<tr>\n";
          echo "<td style=\"background-color: ".
                    $userstatus [($conf_empf[$i]["rolle"])][($conf_empf[$i]["fkt"])]
                    ."height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".$conf_empf[$i]["fkt"] ;
          echo "</td>\n";

          echo "<td style=\"background-color: ".
                    $userstatus [($conf_empf[$i+1]["rolle"])][($conf_empf[$i+1]["fkt"])]
                    ."height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".$conf_empf[$i+1]["fkt"];
          echo "</td>\n";
          echo "</tr>\n";
          $i += 2;
          $prefdoppelt = true ;
        }
        if ( (!$doppel) and ($conf_empf[$i]["fkt"] != "A/W") ) {

          echo "<!-- 005 liste.php -->\n";
          echo "<tr>\n";
          echo "<td style=\"background-color: ".
                    $userstatus [($conf_empf[$i]["rolle"])][($conf_empf[$i]["fkt"])]
                    ."height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".$conf_empf[$i]["fkt"] ;
          echo "</td>\n";
          echo "</tr>\n";
          $i ++;
          $prefdoppelt = false;

        }

        if ($conf_empf[$i]["fkt"] == "A/W") {
          // Zeige wenigstens einen inaktiven Fermelder an

          echo "<!-- 006 liste.php -->\n";
          echo "</tbody>"; echo "</table>\n";
          $tableisset = false;
          echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite."; height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);  font-size:9pt; height:".$zellenhoehe.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"1\">\n";
          echo "<tbody>\n";
          $tableisset = true;

          if ($fernm_aw > 0) {
            for ($j=1; $j <= $fernm_aw; $j++) {
              echo "<tr>";
              echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">A/W</td>\n";
              echo "</tr>";
            }
          } else {  // keiner aktiv ==> einer inaktiv
            echo "<!-- 007 liste.php -->\n";
            echo "<tr>";
            echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">A/W</td>\n";
            echo "</tr>";
          }

          echo "</tbody>"; echo "</table>\n";
          $tableisset = false;
        $i++;
        $doppelt     = false;
        $prefdoppelt = false;
          echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite."; height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);  font-size:9pt; height:".$zellenhoehe.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"1\">\n";
          echo "<tbody>\n";
          echo "<!-- 008 liste.php -->\n";
          $tableisset = true;
        }
      }
    echo "<!-- 009 liste.php -->\n";
    echo "</tbody>";
    echo "</table>\n";
    showsrvtime ($direction);
    }
  }

/********************************************************************************************************
   Benutzerstatus
********************************************************************************************************/
  function benutzerstatus ($what){ // kann sein "anzeige" oder mit "verlinkt"
//    include ("../config.inc.php");
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["benutzer"]."` where 1 order by aktiv DESC, kuerzel";
    $result = $dbaccess->query_table ($query);
    $benutzer = $result ;

    $aktiv    = " rgb(100, 250,  20); color:&000000; ";
    $inaktiv  = " rgb(250,  60,  30); color:&FFFF00; ";
    $self     = " rgb( 50, 180, 220); color:&ffffff; ";
    $abgemldt = " rgb(240, 240, 240); color:&a0a0a0; ";

    $fernm_aw = 0;
    $leitstelle    = 0;
    /*Benutzerliste*/
    if ((count ($benutzer) > 0) and ($benutzer != "")){

      echo "<fieldset>";
      echo "<legend><b><big>Benutzerliste</big></b></legend>\n";
      echo "<table style=\"text-align: left; background-color: rgb(150, 150, 150); height: 32px;\" border=\"3\" cellpadding=\"5\" cellspacing=\"5\">\n";
      echo "<tbody>\n";
      echo "<tr>";
      echo "<td><b>Benutzer</b></td><td><b>Krzel</b></td><td><b>Rolle</b></td><td><b>Funktion</b></td>";
      echo "</tr>";
      foreach ($benutzer as $user){
        echo "<tr>";
        if ($_SESSION [menue] == "LOGIN"){
          $hreflink = "href=\"mainindex.php?benutzer=$user[benutzer]&kuerzel=$user[kuerzel]&funktion=$user[funktion]&anmelden=Anmelden\"";
        } else {
          $hreflink = "";
        }
        if (session_id () == $user ["sid"]){
          echo "<td style=\"background-color: ".$self." font-weight:bold;\"><a $hreflink>$user[benutzer]</a></td>
                <td style=\"background-color: ".$self." font-weight:bold;\"><a $hreflink>$user[kuerzel]</a></td>
                <td style=\"background-color: ".$self." font-weight:bold;\"><a $hreflink>$user[rolle]</a></td>
                <td style=\"background-color: ".$self." font-weight:bold;\"><a $hreflink>$user[funktion]</a></td>";
        } else {
          if ( $user [aktiv] == 1 ){
            echo "<td style=\"background-color: ".$aktiv." font-weight:bold;\"><a $hreflink>$user[benutzer]</a></td>
                  <td style=\"background-color: ".$aktiv." font-weight:bold;\"><a $hreflink>$user[kuerzel]</a></td>
                  <td style=\"background-color: ".$aktiv." font-weight:bold;\"><a $hreflink>$user[rolle]</a></td>
                  <td style=\"background-color: ".$aktiv." font-weight:bold;\"><a $hreflink>$user[funktion]</a></td>";
          } else {
            echo "<td style=\"background-color: ".$abgemldt." font-weight:bold;\"><a $hreflink>$user[benutzer]</a></td>
                  <td style=\"background-color: ".$abgemldt." font-weight:bold;\"><a $hreflink>$user[kuerzel]</a></td>
                  <td style=\"background-color: ".$abgemldt." font-weight:bold;\"><a $hreflink>$user[rolle]</a></td>
                  <td style=\"background-color: ".$abgemldt." font-weight:bold;\"><a $hreflink>$user[funktion]</a></td>";
          }
        }
        echo "</tr>";
      }
      echo "</tbody></table>\n";
      echo "</fieldset>\n";
    } else {
      echo "<br>";
      echo "<big><b>Es ist keine Funktion angemeldet.</b></big>";
    }
  }

/*****************************************************************************\


\*****************************************************************************/
  function reset_cookie (){
     if (isset ($_COOKIE ["vStab_benutzer"])){ setcookie ("vStab_benutzer" , "", (time()-60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     if (isset ($_COOKIE ["vStab_kuerzel"])){  setcookie ("vStab_kuerzel", "", (time()-60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     if (isset ($_COOKIE ["vStab_funktion"])){  setcookie ("vStab_funktion", "", (time()-60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     if (isset ($_COOKIE ["vStab_rolle"])){  setcookie ("vStab_rolle", "",   (time()+60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     session_destroy ();
  }


/*****************************************************************************\


\*****************************************************************************/
  function rollenfinder ( $funktion ){
    include ("../fkt_rolle.inc.php");
    $rolle = "";
    for ($i=1; $i <= count ($conf_empf); $i++ ) {
      if ( ( strcmp($conf_empf[$i]["fkt"], $funktion) ) == 0 ) {
        $rolle = $conf_empf[$i]["rolle"]; }
    }
    return $rolle;
  }

  function fktpos_finder ($fkt) {
    include ("../fkt_rolle.inc.php");
    $result = array ( 0, 0);
    for ($i=1; $i <= count ($conf_empf); $i++){
      if ($conf_empf [$i][2] == $fkt){
        $result [0] = $conf_empf [$i][0];
        $result [1] = $conf_empf [$i][1];
      }
    }
  return $result;
  }


/*****************************************************************************\


\*****************************************************************************/
   function get_last_nachw_num ($direction){
     include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
     $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
     if ( Nachweisung == "getrennt" ) {
       $query = "SELECT max(04_nummer)FROM ".$conf_4f_tbl ["nachrichten"]." WHERE `04_richtung` = \"$direction\" ";
     }
     if ( Nachweisung == "gemeinsam" ) {
       $query = "SELECT max(04_nummer)FROM ".$conf_4f_tbl ["nachrichten"]." WHERE 1 ";
     }
     $aktnum = $dbaccess->query_table_wert ($query);

     return $aktnum[0];
   }

/*****************************************************************************\


\*****************************************************************************/
  function errorwindow ($lokation, $parameter){
    $timestr = date ("His");
    echo "<!--  fehlermeldung ".$timestr."   -->";
    echo "<script type=\"text/javascript\">\n";
//    echo "<!--\n";

    echo "var Neufenster = window.open(\"./info.php?sub=$lokation&info=".$parameter."\",\"AnderesFenster\",\"width=640,height=480, resizable=yes, scrollbars=yes\");\n";
//    echo "//-->\n";
    echo "</script>\n";
  }

/******************************************************************************\

  Welche Farbe bekommt welcher Empfaenger

\******************************************************************************/
  function extraiereempfaenger ($empf){
    $receiver = explode (",",$empf);
    for ($i=0; $i < count( $receiver ); $i++ ) {
      $hilfeaus = explode ( "_", $receiver [$i] ) ;
      $fktcopycolor[$hilfeaus[0]] = $hilfeaus [1] ;
    }
    return $fktcopycolor;
  }

  function konv_datetime_taktime ($datetime){
    include ("../config.inc.php");
    // Datenbankzeit konvertiert in taktische Zeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    list ($datum, $zeit) = explode (" ",$datetime);
    list ($yyyy, $MM, $tt) = explode ("-", $datum);
    list ($hh, $mm, $ss) = explode (":", $zeit);
    return ($tt.$hh.$mm.$tak_monate[$MM].$yyyy);
  }

?>
