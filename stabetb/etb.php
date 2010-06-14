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
    + Anzeige der Schaltflaeche zur Eingabe eines ETB Eintrags

  Szenario "Schaltflaeche ETB-Eintrag wird betaetigt"

    + Anzeige der Einsatzdaten
    + Anzeige des Menues zur Eingabe eines ETB Eintrags

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/
class etb_liste {


  var $etb_titel_tbl     = false ;
  var $etb_titel_gesetzt = false;
  var $etb_art ;
  var $etb_ort ;

  var $etb_funktion ;
  var $etb_kuerzel ;
  var $etb_benutzer ;
  var $etb_authorized ;

/*****************************************************************************\

\*****************************************************************************/
  // Klassenkonstruktor
  function etb_liste (){
    $this->etb_tableexist ();
      if (debug == true){    echo "etb_liste 1 ->"; var_dump ($this->etb_titel_tbl); echo "<br>";}
    if ( $this->etb_titel_tbl ){
      $this->read_out_etbtitel ();
    }
      if (debug == true){    echo "etb_liste 2 ->"; var_dump ($this->etb_titel_gesetzt); echo "<br>";}
    $conf_etb [0] = "<b>Lfd-Nr</b>";
    $conf_etb [1] = "<b>Datum/Zeit</b>";
    $conf_etb [2] = "<b>Darstellung der Ereignisse</b>";
    $conf_etb [3] = "<b>Bemerkung</b>";

    $this->spaltenanzahl = count ($conf_etb);
    $this->spaltenkoepfe = $conf_etb;
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
  function etb_ueberschrift(){
    echo "<big><big><big><big><span";
    echo " style=\"color: rgb(255, 0, 0);\">E</span>insatz<span";
    echo " style=\"color: rgb(255, 0, 0);\">t</span>age<span";
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
  function speichen_etbtitel ($daten){

    if ( $etb_titel_gesetzt ) {
      $this->create_etbtitel_tbl();
    }
if (debug == true){ echo "D A T E N  W E R D E N  G E S P E I C H E R N<br>";}

    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["etb"],
                        $conf_4f_db  ["user"],
                        $conf_4f_db  ["password"] );

    $query = "INSERT into `".$conf_4f_tbl ["prefix"]."etbtitel` SET
                      `einsatz` = \"".$daten["einsatz"]."\",
                      `ort`     = \"".$daten["ort"]."\" ";

    $result = $this->query_table_iu ($query);

  }


/*****************************************************************************\

\*****************************************************************************/
  function create_etbtitel_tbl(){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                               $conf_4f_db  ["datenbank"],
                               $conf_tbl    ["etb"],
                               $conf_4f_db  ["user"],
                               $conf_4f_db  ["password"] );
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

    $query = "CREATE TABLE IF NOT EXISTS `".$conf_4f_tbl ["prefix"]."etbtitel` (
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
  function etb_tableexist () {
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["etb"],
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
         $eq = ( $conf_4f_tbl ["prefix"]."etbtitel" == $row[0] );
      }
    }
    mysql_free_result($result);
    $this->etb_titel_tbl = $eq ;
if (debug == true){ echo "etb_tableexist==>"; var_dump($this->etb_titel_tbl); echo "<br>"; }
    return $eq;
  }


/*****************************************************************************\

\*****************************************************************************/
  function read_out_etbtitel (){
      if (debug == true){echo "read_out_etbtitel<br>";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["etb"],
                        $conf_4f_db  ["user"],
                        $conf_4f_db  ["password"] );

    $query = "SELECT * FROM ".$conf_4f_tbl ["prefix"]."etbtitel WHERE 1 ";
    $result = $this->query_table ($query);

      if (debug == true){echo "read_out_etbtitel--result="; var_dump($result); echo "<br>";}

    if ($result != ""){
      $this->etb_art = $result[1]["einsatz"] ;
      $this->etb_ort = $result[1]["ort"] ;
      $this->etb_titel_gesetzt = true;
    } else {
      $this->etb_titel_gesetzt = false;
    }
  }

  var $spaltenanzahl ;
  var $spaltenkoepfe ; // Array mit den Bezeichnungen der Spalten

/*****************************************************************************\

\*****************************************************************************/
  function etb_pre_html (){
    echo "<!-- etb_pre_html -->\n";
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "  <meta content=\"text/html; charset=ISO-8859-1\" http-equiv=\"content-type\">\n";
    echo "  <title>ETB-Eintrag</title>\n";
    echo "</head>\n";
    echo "<body>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_post_html () {
    echo "<!-- etb_GET_html -->\n";
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
  function etb_menue (){
    include ("../4fcfg/config.inc.php");
    echo "<form action=\"".$_SERVER["PHP_SELF"]."\" method=\"GET\" >\n";
    echo "<!-- Formularelemente und andere Elemente innerhalb des Formulars -->\n";
    echo "<!-- etb_menue -->\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\">\n";
    echo "<tr>\n";
    echo "<td>";
    echo "<input type=\"image\" name=\"etb_eintrag\" value=\"etb_eintrag\" src=\"".$conf_design_path."/logbook_entry.gif\">\n";
    echo "</td>";
    echo "</tr>";
    echo "</table>";
    echo "</form>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_getdate ( ){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                               $conf_4f_db  ["datenbank"],
                               $conf_tbl    ["etb"],
                               $conf_4f_db  ["user"],
                               $conf_4f_db  ["password"] );
    $query = "SELECT * FROM ".$conf_tbl ["etb"]." WHERE 1 order by `etb_lfd-nr` DESC ;";
    $result = $this->query_table ($query);
  if (debug == true){echo "etb_getdate-->"; var_dump($result);echo "<br>";}
    return $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_etb_eintrag ($daten){

    if ( $etb_titel_gesetzt ) {
      $this->create_etbtitel_tbl();
    }
    if (debug == true){     echo "D A T E N  W E R D E N  G E S P E I C H E R N<br>";}

    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $this->set_db_para ($conf_4f_db  ["server"],
                        $conf_4f_db  ["datenbank"],
                        $conf_tbl    ["etb"],
                        $conf_4f_db  ["user"],
                        $conf_4f_db  ["password"] );

    $query = "INSERT into `".$conf_tbl ["etb"]."` SET
                      `etb_time`   = \"".$this->convtodatetime ( date ("dm"),   date ("Hi") )."\",
                      `etb_aktion` = \"".$daten["event"]."\",
                      `etb_bemerk` = \"".$daten["comment"]."\",
                      `etb_funktion` = \"".$this->etb_funktion."\",
                      `etb_kuerzel`  = \"".$this->etb_kuerzel."\",
                      `etb_benutzer` = \"".$this->etb_benutzer."\" ";

    $result = $this->query_table_iu ($query);

  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_eintragsmenue ($data) {
    include ("../4fcfg/config.inc.php");

    echo "<big><big>Eintrag ins \n";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">E</span>\n";
    echo "insatz";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">t</span>\n";
    echo "age";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">b</span>\n";
    echo "uch<br>\n";
    echo "<br>\n";
    echo "</big></big>\n";
    echo "<form method=\"GET\" action=\"".$_SERVER["PHP_SELF"]."\" name=\"etbeintrag\">\n";
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
    echo "<td>\n";
    echo "<input type=\"image\" name=\"absenden\" alt=\"absenden\" tabindex=\"3\" src=\"".$conf_design_path."/send.gif\">\n";
    echo "</td><td>\n";
    echo "<input type=\"image\" name=\"abbrechen\" alt=\"abbrechen\" tabindex=\"4\" src=\"".$conf_design_path."/cancel.gif\">\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
    echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";

    echo "</form>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_einsatzdaten (){
    echo "<table width=\"500px\" style=\"text-align: left;\" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
      echo "<td>Einsatz</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".$this->etb_art."</td>" ;
    echo "</tr>";
    echo "<tr>\n";
      echo "<td>Ort</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".$this->etb_ort."</td>" ;
    echo "</tr>";
/*   echo "<tr>\n";
      echo "<td>Zeit</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".$this->etb_zeit."</td>" ;
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
    if ($this->etb_authorized){
      echo "<table style=\"text-align: left;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
      echo "<tbody>\n";
      echo "<tr>\n";
      echo "<td>\n";
      echo "<input type=\"image\" name=\"absenden\" alt=\"absenden\" tabindex=\"3\" src=\"".$conf_design_path."/send.gif\">\n";
      echo "</td><td>\n";
      echo "<input type=\"image\" name=\"abbrechen\" alt=\"abbrechen\" tabindex=\"4\" src=\"".$conf_design_path."/cancel.gif\">\n";
      echo "</td>\n";
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
        echo $line ["etb_lfd-nr"];
        echo "</td>\n";
        echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">";
        echo $this->konv_datetime_taktime ($line ["etb_time"]);
        echo "</td>\n";
        if ( $line ["etb_aktion"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo $line ["etb_aktion"];
        } else {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
        }
        echo "</td>\n";
        if ( $line ["etb_bemerk"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo $line ["etb_bemerk"];
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

} // class etb_liste


    session_start();

if (debug == true){    echo ">strtoupper( _SESSION[ \"vStab_rolle\"]) ->"; var_dump (strtoupper( $_SESSION["vStab_rolle"])); echo "<br>";}
if (debug == true){    echo ">strtoupper( _SESSION[\"ROLLE\"]) ->"; var_dump (strtoupper( $_SESSION["ROLLE"])); echo "<br>";}

    if ((strtoupper( $_SESSION["vStab_rolle"])  == strtoupper("STAB")) or
        (strtoupper( $_SESSION["ROLLE"])        == strtoupper("STAB")) ){
      $berechtigt = true;
    } else {
      $berechtigt = false ;
    }

if (debug == true){    echo ">berechtigt ->"; var_dump ($berechtigt); echo "<br>";}

    $etbobj = new etb_liste ;

    $etbobj->etb_authorized = $berechtigt;

    $etbobj->etb_funktion = $_SESSION ["vStab_funktion"] ;
    $etbobj->etb_kuerzel  = $_SESSION ["vStab_kuerzel"] ;
    $etbobj->etb_benutzer = $_SESSION ["vStab_benutzer"] ;


if (debug == true){    echo ">etb_authorized ->"; var_dump ($etbobj->etb_authorized); echo "<br>";}

  if ( !(isset ($_GET["absenden_x"] ))) {

    $etbobj->etb_pre_html();

    $etbobj->etb_ueberschrift ();
      // Einsatzdaten vorhanden !!!
  }

  if ( !$etbobj->etb_titel_gesetzt ){

    if ( (isset ($_GET["absenden_x"] )) and
         ($_GET["Einsatzdaten"] == "erfassen") ){

      if (debug == true){ echo "Daten können gespeichert werden !!!!<br>";}
      $etbobj->speichen_etbtitel ($_GET);
      header("Location: ".$_SERVER["PHP_SELF"]);

    } else {
        if (debug==true){      echo "Titel ist nicht gesetzt !!!!<br>";}
      $etbobj->create_etbtitel_tbl();
      $etbobj->inputeinsatzstammdaten ();
    }

  } else {
//    echo "<big>Wir sind im zweiten Teil !!!</big><br>";


    if (( $etbobj->etb_titel_gesetzt ) and
        ( !(isset ($_GET["absenden_x"] ) ) ) ) {
      $etbobj->etb_einsatzdaten ();
    }

    if ( (isset ($_GET["absenden_x"] )) and
         ($_GET["Einsatzdaten"] == "erfassen") ){
        if (debug == true){ echo "M A R K E  0 0 1<br>";}
      $etbobj->speichen_etbtitel ($_GET);
      header("Location: ".$_SERVER["PHP_SELF"]);
    }


    if ( $_GET["etb_menue"] == "eintrag" ){
        if (debug == true){ echo "M A R K E  0 0 2<br>";}
      $etbobj->etb_eintragsmenue ("");
    }

    if ( isset ($_GET["absenden_x"]) ){
        if (debug == true){ echo "M A R K E  0 0 3<br>";}
      $etbobj->speichen_etb_eintrag ($_GET);
      header("Location: ".$_SERVER["PHP_SELF"]);
    }

    if (isset ( $_GET ["etb_eintrag_x"] ) ) {
        if (debug == true){ echo "M A R K E  0 0 4<br>";}
      $etbobj->etb_eintragsmenue ("");
    } else {
        if (debug == true){ echo "M A R K E  0 0 5<br>";}
      if ($etbobj->etb_authorized){
        $etbobj->etb_menue ();
      }
    }
    $daten = $etbobj->etb_getdate ();
    if (  $daten != "" ){
        if (debug == true){ echo "M A R K E  0 0 6<br>";}
      // Hole Daten aus der Datenbank
      $etbobj->printlist ($daten);
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

  if ( !(isset ($_GET["absenden_x"] ))) {  $etbobj->etb_post_html(); }

?>
