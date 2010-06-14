<?php
/*****************************************************************************\
   Datei: tools.php

   benoetigte Dateien:

   Beschreibung:


   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");
include ("../4fcfg/para.inc.php");

  function pre_html ($art, $titel, $cssstr){
    include ("../4fcfg/para.inc.php");
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";

    echo "<script language=\"JavaScript\">\n";
    echo "<!--\n";
    echo "function FramesVeraendern(url1, frameziel1, url2, frameziel2, url3, frameziel3)";
    echo "{";
    echo "    Frame1 = eval(\"parent.\"+frameziel1);";
    echo "    Frame2 = eval(\"parent.\"+frameziel2);";
    echo "    Frame3 = eval(\"parent.\"+frameziel3);";
    echo "    Frame1.location.href = url1; ";
    echo "    Frame2.location.href = url2; ";
    echo "    Frame3.location.href = url3; ";
    echo "}";
    echo "//-->\n";
    echo "</script>\n";

    echo "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=iso\">\n";
   switch ($art){
     case "N":
        echo"<script type=\"text/javascript\">";
        echo "function FensterOeffnen (Adresse) {MeinFenster = window.open(Adresse, \"Zweitfenster\", \"width=500,height=400,left=100,top=100,menubar=no,location=no,resizable=no,status=no,toolbar=no\");  MeinFenster.focus();}";
        echo "</script>";

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
     case "reset":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
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

  /****************************************************************************\
  | Umwandlung von $datum -->
  | Formateingang: TTMM
  |                JJJJ -> aktuelle Systemjahr
  | Formatausgang: JJJJ-MM-TT
  \****************************************************************************/
  function convtodate ($datum){
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $jahr   = date ("Y");
    $date = $jahr."-".$monat."-".$tag ;
    return $date ;
  }

  /****************************************************************************\
  | Umwandlung von $zeit
  | Formateingang: hhmm
  | Formatausgang: hh:mm
  \****************************************************************************/
  function convtotime ($zeit){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $time = $stunde.":".$minute.":00";
    return $time;
  }

  /****************************************************************************\
  | Umwandlung von $datum und $zeit
  | Formateingang: Datum TTMM
  |                Zeit  hhmm
  | Formatausgang: YYYY-MM-TT hh:mm:00
  \****************************************************************************/
  function convtodatetime ($datum, $zeit){
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
  | Formatausgang:
  |       arr.datum = TTMM
  |       arr.zeit  = hhmm
  |       arr.stak  = TThhmm
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

  /****************************************************************************\
  | Umwandlung von datetime ->
  | Formateingang: YYYY-MM-TT hh:mm:ss
  | Formatausgang:
  |       arr.datum =  YYYY-MM-TT
  |       arr.zeit  =  hh:mm:ss
  \****************************************************************************/
  function convdbdatetimeto ($datetime){
    list ($datum, $zeit) = explode (" ",$datetime);
    //list ($jahr, $monat, $tag) = explode ("-", $datum);
    //list ($stunde, $minute, $sekunde) = explode (":", $zeit);
    $arr [datum] = $datum;
    $arr [zeit]  = $zeit;
    return $arr;
  }

  /****************************************************************************\
  | Umwandlung von conv_time_datetime ->
  | Formateinausgang:
  |       arr.datum =  TThhmmMMMjjjj
  |       arr.zeit  =  TThhmm
  |       arr.zeit  =  hhmm
  | Formatausgang: YYYY-MM-TT hh:mm:ss
  \****************************************************************************/
  function conv_time_datetime($data){
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
         "12" => 'dec' );
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
         "dec" => '12' );

    $laenge = strlen ($data);
//echo "tools181 --- länge = ".$laenge." und time = ".$data."<br>";
    switch ( $laenge ){
      case 13:// TThhmmMMMJJJJ
          $tag    = substr ($data, 0, 2);
          $stunde = substr ($data, 2, 2);
          $minute = substr ($data, 4, 2);
          $monat  = substr ($data, 6, 3);
          $jahr   = substr ($data, 9, 4);
          $monat = $rew_tak_monate [$monat];

//echo "<br>tag=".$tag."  stunde=".$stunde."  minute=".$minute."  monat=".$monat."  jahr=".$jahr."<br>";
          if ( (($tag    >= 1) and ($tag    <= 31)) and
               (($monat  >= 1) and ($monat  <= 12)) and
               (($jahr   >= 2000) and ($jahr <= 9999)) and
               (($minute >= 0) and ($minute <= 59)) and
               (($stunde >= 0) and ($stunde <= 23)) ) {
          $monat = $tak_monate [$monat];
//echo "<br>tag=".$tag."  stunde=".$stunde."  minute=".$minute."  monat=".$monat."  jahr=".$jahr."<br>";
            $data = $tag.$stunde.$minute.$monat.$jahr ;
            $l_data = true ;
          } else {
            $l_data = false;
          }
      break;
      case 6: // TThhmm
          $tag    = substr ($data, 0, 2);
          $stunde = substr ($data, 2, 2);
          $minute = substr ($data, 4, 2);
          $monat = $tak_monate [date ("m")];
          $jahr = date ("Y");

          if ( (($tag    >= 1) and ($tag    <= 31)) and
//               (($monat  >= 1) and ($monat  <= 12)) and
//               (($jahr   >= 2000) and ($jahr <= 9999) and
               (($minute >= 0) and ($minute <= 59)) and
               (($stunde >= 0) and ($stunde <= 23))) {
            $data = $tag.$stunde.$minute.$monat.$jahr ;
            $l_data = true ;
          } else {
            $data = $data;
            $l_data = false;
          }
      break;
      case 4: // hhmm
          $stunde = substr ($data, 0, 2);
          $minute = substr ($data, 2, 2);
//          $monat = date("m");

//echo "tools223 --- stunde  = "; var_dump ($stunde); echo "<br>";
//echo "tools224 --- minute  = "; var_dump ($minute); echo "<br>";
          if ( (($minute >= 0) and ($minute <= 59)) and
               (($stunde >= 0) and ($stunde <= 23))) {
            $tag   = date ("d");
            $monat = $tak_monate [date ("m")];
            $jahr  = date ("Y");
            $data = $tag.$stunde.$minute.$monat.$jahr ;
            $l_data = true ;
          } else {
            $l_data = false;
          }
      break;
      default: $l_data = false;
    }
    $back = array ("l_data" => $l_data, "data" => $data);
// echo "tools240 --- data = "; var_dump ( $back ); echo "<br>";
    return ( $back );
  } //conv_time_datetime



  function getoutqueuecount (){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."` WHERE ((`04_richtung` = \"A\") AND
                                                  (`03_datum` = 0) AND
                                                  (`03_zeichen` = \"\"));";
   $result = $dbaccess->query_table_wert ($query);
    return $result[0];
  }

  function getviewerqueuecount (){
//    include ("../config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
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
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

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

  function getdonecount (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."`
              WHERE ( `16_empf` like '%".$_SESSION ["vStab_funktion"]."%' ) ;";
    $result = $dbaccess->query_table_wert ($query);
    $gesamtmeldungen = $result[0];
    $fkttblname  = $conf_4f_tbl ["usrtblprefix"]."_fkt_".strtolower ($_SESSION["vStab_funktion"]);

    $query = "SELECT count(*) FROM `".$fkttblname."_erl"."`
              WHERE 1 ;";

    $query = "SELECT count(*) FROM `nv_nachrichten`,`".$fkttblname."_erl`
              WHERE
               (
                 ( `16_empf` like '%".$_SESSION ["vStab_funktion"]."%' ) AND
                 ( ".$conf_4f_tbl ["nachrichten"].".00_lfd = ".$fkttblname."_erl.nachnum )
               ) ;";

   $result = $dbaccess->query_table_wert ($query);
   $erledigtmeldungen = $result[0];
   return $gesamtmeldungen-$erledigtmeldungen;
  }

//343
/**************************************************************************************\
  Funktion: einhorn

  bist du der letzte deiner Art?
\**************************************************************************************/
  function einhorn ($fkt){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);

    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["benutzer"]."` WHERE
         ( (`funktion` = \"".$fkt."\") AND 
           (`aktiv` != \"0\" )); ";
    $result = $dbaccess->query_table_wert ($query);

    if ($result[0] > 1) { return (false); }   
    else { return (true);}
  } // funktion einhorn



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
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
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

    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["benutzer"]."` where 1";
    $result = $dbaccess->query_table ($query);
    $benutzer = $result ;
    $aktiv    = " rgb(100, 250,  20); color:&000000; "; // was (100, 250,  20)
    $inaktiv  = " rgb(200, 200, 200); color:&FFFF00; "; // was (250,  60,  30)
    $self     = " rgb(250,  60,  30); color:&ffffff; "; // was ( 50, 180, 220);

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

      if ($fernm_aw > 0) {
        echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">".$fernm_aw." A/W</td>\n";
      } else {  // keiner aktiv ==> einer inaktiv
         echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">A/W</td>\n";
      }

      // Zeige wenigstens einen inaktiven Fermelder an
/*
      if ($fernm_aw > 0) {
        for ($i=1; $i <= $fernm_aw; $i++) {
           echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">A/W</td>\n";
        }
      } else {  // keiner aktiv ==> einer inaktiv
         echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">A/W</td>\n";
      }
*/
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
//            for ($j=1; $j <= $fernm_aw; $j++) {
              echo "<tr>";
              echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".$fernm_aw." A/W</td>\n";
              echo "</tr>";
//            }
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

    }
  }

/********************************************************************************************************
   Benutzerstatus
********************************************************************************************************/
  function benutzerstatus ($what){ // kann sein "anzeige" oder mit "verlinkt"
//    include ("../config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["benutzer"]."` where 1 order by aktiv DESC, kuerzel";
    $result = $dbaccess->query_table ($query);
    $benutzer = $result ;

        $aktiv    = " rgb(100, 250,  20); color:&000000; "; // was (100, 250,  20)
    $inaktiv  = " rgb(200, 200, 200); color:&FFFF00; "; // was (250,  60,  30)
    $self     = " rgb(250,  60,  30); color:&ffffff; "; // was ( 50, 180, 220);
    $abgemldt = " rgb(200, 200, 200); color:&a0a0a0; "; // was ( 240, 240, 240);

    $fernm_aw = 0;
    $leitstelle    = 0;
    /*Benutzerliste*/
    if ((count ($benutzer) > 0) and ($benutzer != "")){

      echo "<fieldset>";
      echo "<legend><b><big>Benutzerliste</big></b></legend>\n";
      echo "<table style=\"text-align: left; background-color: rgb(150, 150, 150); height: 32px;\" border=\"3\" cellpadding=\"5\" cellspacing=\"5\">\n";
      echo "<tbody>\n";
      echo "<tr>";
      echo "<td><b>Benutzer</b></td><td><b>K&uuml;rzel</b></td><td><b>Rolle</b></td><td><b>Funktion</b></td>";
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
    include ("../4fcfg/fkt_rolle.inc.php");
    $rolle = "";
    for ($i=1; $i <= count ($conf_empf); $i++ ) {
      if ( ( strcmp($conf_empf[$i]["fkt"], $funktion) ) == 0 ) {
        $rolle = $conf_empf[$i]["rolle"]; }
    }
    return $rolle;
  }

  function fktpos_finder ($fkt) {
    include ("../4fcfg/fkt_rolle.inc.php");
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
     include ("../4fcfg/dbcfg.inc.php");
     include ("../4fcfg/e_cfg.inc.php");
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


  /****************************************************************************\
  | Umwandlung von conv_datetime_takzeit ->
  | Formateinausgang:  YYYY-MM-TT hh:mm:ss
  | Formatausgang   :  TThhmmMMYYYY
  \****************************************************************************/
  function konv_datetime_taktime ($datetime){
    include ("../4fcfg/config.inc.php");
    // Datenbankzeit konvertiert in taktische Zeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    if (strlen ($datetime) == 19 ){
      list ($datum, $zeit) = explode (" ",$datetime);
      list ($yyyy, $MM, $tt) = explode ("-", $datum);
      list ($hh, $mm, $ss) = explode (":", $zeit);
      return ($tt.$hh.$mm.$tak_monate[$MM].$yyyy);
    } else {
      return ("");
    }
  }


  /****************************************************************************\
  | Umwandlung von Taktischerzeit nach Datetime
  | Formateinausgang:  YYYY-MM-TT hh:mm:ss
  | Formatausgang   :  TThhmmMMYYYY
  \****************************************************************************/
  function konv_taktime_datetime ($taktime){
    include ("../4fcfg/config.inc.php");
    // taktische Zeit konvertiert in Datenbankzeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    if (strlen ($taktime) == 13){
      $tag    = substr ($taktime, 0, 2);
      $stunde = substr ($taktime, 2, 2);
      $minute = substr ($taktime, 4, 2);
      $monat  = substr ($taktime, 6, 3);
      $jahr   = substr ($taktime, 9, 4);
      $monat = $rew_tak_monate [$monat];
      return ($jahr."-".$monat."-".$tag." ".$stunde.":".$minute.":00" );
    } else {
      return ("");
    }
  }


?>
