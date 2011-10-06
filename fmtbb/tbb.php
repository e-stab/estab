<?php

define ("debug", false);
/******************************************************************************\
Einsatz Tage Buch

  Szenario "Kein Eintrag vorhanden, kein Einsatz definiert."

    + Menue zur Eingabe der Einsatzdaten (Einsatzart und Ort)
       - Anzeige des Eingabemenues
       - Anlegen der Einsatztiteltabelle
       - Eintragen der Einsatzdaten

  Szenario "Kein Eintrag vorhanden, Einsatzdaten eingegeben."

       - Einsatzdaten aus Tabelle auslesen
    + Anzeige der Einsatzdaten
    + Anzeige der Schaltflaeche zur Eingabe eines TBB Eintrags

  Szenario "Schaltflaeche TBB-Eintrag wird betaetigt"

    + Anzeige der Einsatzdaten
    + Anzeige des Menues zur Eingabe eines TBB Eintrags

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/
class tbb_liste {


  var $tbb_titel_tbl     = false ;
  var $tbb_titel_gesetzt = false;
  var $tbb_art ;
  var $tbb_ort ;

  var $tbb_funktion ;
  var $tbb_kuerzel ;
  var $tbb_benutzer ;
  var $tbb_authorized ;

/*****************************************************************************\

\*****************************************************************************/
  // Klassenkonstruktor
  function tbb_liste (){
    $this->tbb_tableexist ();
      if (debug == true){    echo "tbb_liste 1 ->"; var_dump ($this->tbb_titel_tbl); echo "<br>";}
    if ( $this->tbb_titel_tbl ){
      $this->read_out_tbbtitel ();
    }
      if (debug == true){    echo "tbb_liste 2 ->"; var_dump ($this->tbb_titel_gesetzt); echo "<br>";}
    $conf_tbb [0] = "<b>Lfd-Nr</b>";
    $conf_tbb [1] = "<b>Datum/Zeit</b>";
    $conf_tbb [2] = "<b>Darstellung der Ereignisse</b>";
    $conf_tbb [3] = "<b>Bemerkung</b>";
    $conf_tbb [4] = "<b>K&uuml;rzel</b>";
    $this->spaltenanzahl = count ($conf_tbb);
    $this->spaltenkoepfe = $conf_tbb;
  }


  var $db_server ;
  var $db_name ;
  var $db_table ;
  var $db_user ;
  var $db_pw ;

  var $db_sqlquery;
  var $db_result;


/*****************************************************************************\

\*****************************************************************************/
  function tbb_ueberschrift(){
    echo "<big><big><big><big><span";
    echo " style=\"color: rgb(255, 0, 0);\">T</span>echnisches<span";
    echo " style=\"color: rgb(255, 0, 0);\">B</span>etriebs<span";
    echo " style=\"color: rgb(255, 0, 0);\">b</span>uch";
    echo "</big></big></big></big>";
    echo "<br><br>";
  }


/*****************************************************************************\

\*****************************************************************************/
  function set_db_para ($newdb_server, $newdb_name, $newdb_table, $newdb_user, $newdb_pw){
    $this->db_server = $newdb_server ;
    $this->db_name   = $newdb_name ;
    $this->db_table  = $newdb_table ;
    $this->db_user   = $newdb_user ;
    $this->db_pw     = $newdb_pw ;
// echo "Datenbankparameter = ".$this->db_server." - ".$this->db_name." - ".$this->db_table." - ".$this->db_user." - ".$this->db_pw."<br>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function query_table_iu ($query){
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table_iu] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table_iu] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table_iu] ".mysql_error()." ".mysql_errno());

    mysql_close ($db);
  } // function query_table_iu

/*****************************************************************************\

\*****************************************************************************/
  function query_table ($query){
    $this->result = "";
    $this->sqlquery = $query ;

    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table] 103-".mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);

    for ($i=1;$i<=$this->resultcount;$i++){
      $this->result[$i] = mysql_fetch_assoc($query_result);
    }
    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->result);
  } // function read_table


/*****************************************************************************\

\*****************************************************************************/
  function speichen_tbbtitel ($daten){

    if ( $tbb_titel_gesetzt ) {
      $this->create_tbbtitel_tbl();
    }
if (debug == true){ echo "D A T E N  W E R D E N  G E S P E I C H E R N<br>";}

    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["tbb"],
                        $conf_4f_db  ["user"],
                        $conf_4f_db  ["password"] );

    $query = "INSERT into `".$conf_4f_tbl ["prefix"]."tbbtitel` SET
                      `einsatz` = \"".$daten["einsatz"]."\",
                      `ort`     = \"".$daten["ort"]."\" ";

    $result = $this->query_table_iu ($query);

  }


/*****************************************************************************\

\*****************************************************************************/
  function create_tbbtitel_tbl(){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                               $conf_4f_db  ["datenbank"],
                               $conf_tbl    ["tbb"],
                               $conf_4f_db  ["user"],
                               $conf_4f_db  ["password"] );
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

    $query = "CREATE TABLE IF NOT EXISTS `".$conf_4f_tbl ["prefix"]."tbbtitel` (
            `lfd-nr` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `einsatz` varchar(255) NOT NULL,
            `ort` varchar(255) NOT NULL,
            PRIMARY KEY (`lfd-nr`)
          ) ENGINE=MyISAM AUTO_INCREMENT=1 ;";

    $result = mysql_query($query, $db);
    if (!$result) {
       die('Ungueltige Abfrage: ' . mysql_error());
    }
  }


/*****************************************************************************\

\*****************************************************************************/
  function tbb_tableexist () {
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["tbb"],
                        $conf_4f_db  ["user"],
                        $conf_4f_db  ["password"] );
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");
    $result = mysql_list_tables($conf_4f_db ["datenbank"]);
    $db_errno  = mysql_errno ();
    $db_errtxt = mysql_error ();
    if ($result) {
      $eq = false;
      while ( ($row = mysql_fetch_row($result)) and ($eq == false)) {
         $eq = ( $conf_4f_tbl ["prefix"]."tbbtitel" == $row[0] );
      }
    }
    mysql_free_result($result);
    $this->tbb_titel_tbl = $eq ;
if (debug == true){ echo "tbb_tableexist==>"; var_dump($this->tbb_titel_tbl); echo "<br>"; }
    return $eq;
  }


/*****************************************************************************\

\*****************************************************************************/
  function read_out_tbbtitel (){
      if (debug == true){echo "read_out_tbbtitel<br>";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["tbb"],
                        $conf_4f_db  ["user"],
                        $conf_4f_db  ["password"] );

    $query = "SELECT * FROM ".$conf_4f_tbl ["prefix"]."tbbtitel WHERE 1 ";
    $result = $this->query_table ($query);

      if (debug == true){echo "read_out_tbbtitel--result="; var_dump($result); echo "<br>";}

    if ($result != ""){
      $this->tbb_art = $result[1]["einsatz"] ;
      $this->tbb_ort = $result[1]["ort"] ;
      $this->tbb_titel_gesetzt = true;
    } else {
      $this->tbb_titel_gesetzt = false;
    }
  }

  var $spaltenanzahl ;
  var $spaltenkoepfe ; // Array mit den Bezeichnungen der Spalten

/*****************************************************************************\

\*****************************************************************************/
  function tbb_pre_html (){
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "  <meta content=\"text/html; charset=ISO-8859-1\" http-equiv=\"content-type\">\n";
    if (!$this->tbb_authorized) {
      echo "<meta http-equiv=\"refresh\" content=\"10\">\n";
    }
    echo "  <title>TBB-Eintrag</title>\n";
    echo "</head>\n";
    echo "<body>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_post_html () {
    echo "<!-- tbb_GET_html -->\n";
    echo "</body>\n";
    echo "</html>\n";
  }


/*****************************************************************************\

\*****************************************************************************/
  function konv_datetime_taktime ($datetime){
    include ("../4fcfg/config.inc.php");
    // Datenbankzeit konvertiert in taktische Zeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    list ($datum, $zeit) = explode (" ",$datetime);
    list ($yyyy, $MM, $tt) = explode ("-", $datum);
    list ($hh, $mm, $ss) = explode (":", $zeit);
    return ($tt.$hh.$mm.$tak_monate[$MM].$yyyy);
  }


/*****************************************************************************\

\*****************************************************************************/
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



/*****************************************************************************\

\*****************************************************************************/
  function tbb_menue (){
    include ("../4fcfg/config.inc.php");
    echo "<form action=\"".$_SERVER["PHP_SELF"]."\" method=\"GET\" >\n";
    echo "<!-- Formularelemente und andere Elemente innerhalb des Formulars -->\n";
    echo "<!-- tbb_menue -->\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\">\n";
    echo "<tr>\n";
    echo "<td>";
    echo "<input type=\"image\" name=\"tbb_eintrag\" value=\"tbb_eintrag\" src=\"".$conf_design_path."/logbook_entry.gif\">\n";
    echo "</td>";
    echo "</tr>";
    echo "</table>";
//    echo "<img src=\"http://localhost:80/kats/4fach/design/HS/timer.gif\">";
    echo "</form>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_getdate ( ){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                               $conf_4f_db  ["datenbank"],
                               $conf_tbl    ["tbb"],
                               $conf_4f_db  ["user"],
                               $conf_4f_db  ["password"] );
    $query = "SELECT * FROM ".$conf_tbl ["tbb"]." WHERE 1 order by `tbb_lfd-nr` DESC ;";
  if (debug == true){echo "tbb_getdate-->query=".$query; echo "<br>";}
    $result = $this->query_table ($query);
  if (debug == true){echo "tbb_getdate-->"; var_dump($result);echo "<br>";}
    return $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_tbb_eintrag ($daten){

    if ( $tbb_titel_gesetzt ) {
      $this->create_tbbtitel_tbl();
    }
    if (debug == true){     echo "D A T E N  W E R D E N  G E S P E I C H E R N<br>";}

    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["tbb"],
                        $conf_4f_db  ["user"],
                        $conf_4f_db  ["password"] );

    $query = "INSERT into `".$conf_tbl ["tbb"]."` SET
                      `tbb_time`   = \"".$this->convtodatetime ( date ("dm"),   date ("Hi") )."\",
                      `tbb_aktion` = \"".$daten["event"]."\",
                      `tbb_bemerk` = \"".$daten["comment"]."\",
                      `tbb_funktion` = \"".$this->tbb_funktion."\",
                      `tbb_kuerzel`  = \"".$this->tbb_kuerzel."\",
                      `tbb_benutzer` = \"".$this->tbb_benutzer."\" ";

    $result = $this->query_table_iu ($query);

  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_eintragsmenue ($data) {
    include ("../4fcfg/config.inc.php");

    echo "<big><big>Eintrag ins \n";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">T</span>\n";
    echo "echnisches";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">B</span>\n";
    echo "etriebs";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">b</span>\n";
    echo "uch<br>\n";
    echo "<br>\n";
    echo "</big></big>\n";
    echo "<form method=\"GET\" action=\"".$_SERVER["PHP_SELF"]."\" name=\"tbbeintrag\">\n";
    echo "<table style=\"text-align: left;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td><b>Darstellung der Ereignisse</b><br>\n";
    echo "<textarea style=\"font-size:18px; font-weight:900;\" tabindex=\"1\" cols=\"80\" rows=\"4\" name=\"event\"></textarea></td>\n";
    echo "</tr>\n";
    echo "<tr>";
    echo "<td><b>Bemerkung</b><br>";
    echo "<textarea style=\"font-size:18px; font-weight:900;\" tabindex=\"2\" cols=\"80\" rows=\"4\" name=\"comment\"></textarea></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "<table style=\"text-align: left;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" alt=\"absenden\" tabindex=\"3\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
    echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"abbrechen\" alt=\"abbrechen\" tabindex=\"4\" src=\"".$conf_design_path."/cancel.gif\"></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
    echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";

    echo "</form>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_einsatzdaten (){
    echo "<table width=\"500px\" style=\"text-align: left;\" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
      echo "<td>Einsatz</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".$this->tbb_art."</td>" ;
    echo "</tr>";
    echo "<tr>\n";
      echo "<td>Ort</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".$this->tbb_ort."</td>" ;
    echo "</tr>";
/*   echo "<tr>\n";
      echo "<td>Zeit</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".$this->tbb_zeit."</td>" ;
     echo "</tr>";
*/
    echo "</tbody>";
    echo "</table>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function headline (){
    echo "<tr style=\"text-align: left; background-color: rgb(201, 201, 150);\">\n"; // Zeilenanfang
    for ($i=0; $i<$this->spaltenanzahl; $i++){
      echo "<td style=\" outline:1px solid black;\">\n";
      echo $this->spaltenkoepfe [$i];
      echo "</td>\n";
    }
    echo "</tr>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function inputeinsatzstammdaten (){
  include ("../4fcfg/config.inc.php");
    echo "<big><big><big><b>Einsatzdaten erfassen</b></big></big></big>\n";
    echo "<!-- einsatzdatenmenue -->";
    echo "<form method=\"GET\" action=\"".$_SERVER ["PHP_SELF"]."\" name=\"Einsatzdaten\">\n";
    echo "<table style=\"text-align: left; width: 603px; height: 64px;\" border=\"1\" cellpadding=\"2\" cellspacing=\"2\">";
    echo "<tbody>";
    echo "<tr>";
    echo "<td style=\"width: 100px;\">Einsatz</td>";
    echo "<td style=\"width: 506px;\">";
    echo "<input style=\"font-size:18px; font-weight:900;\" value=\"\" maxlength=\"255\" size=\"60\" name=\"einsatz\">";
    echo "</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td style=\"width: 100px;\">Ort</td>";
    echo "<td style=\"width: 506px;\">";
    echo "<input style=\"font-size:18px; font-weight:900;\" value=\"\" maxlength=\"255\" size=\"60\" name=\"ort\"></td>";
    echo "</tr>";
    echo "</tbody>";
    echo "</table>";
    if ($this->tbb_authorized){
      echo "<table style=\"text-align: left;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
      echo "<tbody>\n";
      echo "<tr>\n";
      echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" alt=\"absenden\" tabindex=\"3\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
      echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"abbrechen\" alt=\"abbrechen\" tabindex=\"4\" src=\"".$conf_design_path."/cancel.gif\"></td>\n";
      echo "</tr>\n";
      echo "</tbody>\n";
      echo "</table>\n";
    }
    echo "<input type=\"hidden\" name=\"Einsatzdaten\" value=\"erfassen\">\n";
    echo "</form>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function printlist ($daten){
    include ("../4fach/tools.php");
    // Schreibe die Liste
    if ( $daten != "" ) {

      echo "<table style=\"border-width:medium; border-color:#66CC66; border-style:solid; padding:1px;\" border=\"1\" cellpadding=\"5\" cellspacing=\"1\" bordercolor=black>\n";
      echo "<tbody>\n";

      $this->headline ();

      foreach ( $daten as $line ){
//        var_dump ($line); echo "<br>";

        echo "<tr>";
        echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">\n";
        echo $line ["tbb_lfd-nr"];
        echo "</td>\n";
        echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">";
        echo $this->konv_datetime_taktime ($line ["tbb_time"]);
        echo "</td>\n";
        if ( $line ["tbb_aktion"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo $line ["tbb_aktion"];
        } else {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
        }
        echo "</td>\n";
        if ( $line ["tbb_bemerk"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo $line ["tbb_bemerk"];
        } else {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
        }

        if ( $line ["tbb_kuerzel"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo $line ["tbb_kuerzel"];
        } else {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
        }

        echo "</td>\n";
        echo "</tr>\n";
      }

      echo "</tbody>\n";
      echo "</table>\n";
    } else {
      $this->inputeinsatzstammdaten ();

    }
  }

} // class tbb_liste


    session_start();

if (debug == true){    echo ">strtoupper( _SESSION[ \"vStab_rolle\"]) ->"; var_dump (strtoupper( $_SESSION["vStab_rolle"])); echo "<br>";}
if (debug == true){    echo ">strtoupper( _SESSION[\"ROLLE\"]) ->"; var_dump (strtoupper( $_SESSION["ROLLE"])); echo "<br>";}

    if ((strtoupper( $_SESSION["vStab_rolle"])  == strtoupper("FERNMELDER")) or
        (strtoupper( $_SESSION["ROLLE"])        == strtoupper("FERNMELDER")) ){
      $berechtigt = true;
    } else {
      $berechtigt = false ;
    }

if (debug == true){    echo ">berechtigt ->"; var_dump ($berechtigt); echo "<br>";}

    $tbbobj = new tbb_liste ;

    $tbbobj->tbb_authorized = $berechtigt;

    $tbbobj->tbb_funktion = $_SESSION ["vStab_funktion"] ;
    $tbbobj->tbb_kuerzel  = $_SESSION ["vStab_kuerzel"] ;
    $tbbobj->tbb_benutzer = $_SESSION ["vStab_benutzer"] ;


if (debug == true){    echo ">tbb_authorized ->"; var_dump ($tbbobj->tbb_authorized); echo "<br>";}

  if ( !(isset ($_GET["absenden_x"] ))) {

    $tbbobj->tbb_pre_html();

    $tbbobj->tbb_ueberschrift ();
      // Einsatzdaten vorhanden !!!
  }

  if ( !$tbbobj->tbb_titel_gesetzt ){

    if ( (isset ($_GET["absenden_x"] )) and
         ($_GET["Einsatzdaten"] == "erfassen") ){

      if (debug == true){ echo "Daten können gespeichert werden !!!!<br>";}
      $tbbobj->speichen_tbbtitel ($_GET);
      header("Location: ".$_SERVER["PHP_SELF"]);

    } else {
        if (debug==true){      echo "Titel ist nicht gesetzt !!!!<br>";}
      $tbbobj->create_tbbtitel_tbl();
      $tbbobj->inputeinsatzstammdaten ();
    }

  } else {
//    echo "<big>Wir sind im zweiten Teil !!!</big><br>";


    if (( $tbbobj->tbb_titel_gesetzt ) and
        ( !(isset ($_GET["absenden_x"] ) ) ) ) {
      $tbbobj->tbb_einsatzdaten ();
    }

    if ( (isset ($_GET["absenden_x"] )) and
         ($_GET["Einsatzdaten"] == "erfassen") ){
        if (debug == true){ echo "M A R K E  0 0 1<br>";}
      $tbbobj->speichen_tbbtitel ($_GET);
      header("Location: ".$_SERVER["PHP_SELF"]);
    }


    if ( $_GET["tbb_menue"] == "eintrag" ){
        if (debug == true){ echo "M A R K E  0 0 2<br>";}
      $tbbobj->tbb_eintragsmenue ("");
    }

    if ( isset ($_GET["absenden_x"]) ){
        if (debug == true){ echo "M A R K E  0 0 3<br>";}
      $tbbobj->speichen_tbb_eintrag ($_GET);
      header("Location: ".$_SERVER["PHP_SELF"]);
    }

    if (isset ( $_GET ["tbb_eintrag_x"] ) ) {
        if (debug == true){ echo "M A R K E  0 0 4<br>";}
      $tbbobj->tbb_eintragsmenue ("");
    } else {
        if (debug == true){ echo "M A R K E  0 0 5<br>";}
      if ($tbbobj->tbb_authorized){
        $tbbobj->tbb_menue ();
      }
    }
    $daten = $tbbobj->tbb_getdate ();
    if (  $daten != "" ){
        if (debug == true){ echo "M A R K E  0 0 6<br>";}
      // Hole Daten aus der Datenbank
      $tbbobj->printlist ($daten);
    } else {

    }
  }

    if (debug == true){
      echo "<br><br>\n";
      echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
      echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
      echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
      // echo "SERVER  ="; var_dump ($_SERVER); echo "#<br><br>\n";
      echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
      echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
    }

  if ( !(isset ($_GET["absenden_x"] ))) {  $tbbobj->tbb_post_html(); }

?>
