<?php
/*****************************************************************************\
   Datei: anhang.php

   benoetigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

/*****************************************************************************\
    Auswahl einer Anhangdatei
    Datei auf den Server hochladen
******************************************************************************/

include ("./upload_class.php");

class fileupload extends file_upload {
  // fs - fileselectform Dateiauswahl
  var $fs_savename;     // Einlagerungsdateiname  HSxxxxx
  var $fs_uplname;      // Uploaddateiname
  var $fs_comment;      // Beschreibung
  var $fs_shortname;    // Kuerzel
  var $fs_timestamp;    // Zeitstempel
  var $fs_nextfilename; // Nächster Dateiname

  var $ff_savename ;    // Name der gespeicherten Datei g.g. Darstellung im Menue
  var $ff_filename ;    // Ursprünglicher Dateiname
  var $ff_comment  ;    // Beschreibung Faxkopf
  var $ff_timestamp;    // Zeitstempel
  var $ff_kuerzel  ;    // Kuerzel des Fm

  var $filenamezero = 4; // Anzahl der Zahlen

  /***************************************************************************\
    Funktion: get_next_filename_from_db ()
         DB Status
           1 : erledigt - upload vollzogen
           2 : ?
           4 : abgebrochen
           8 : reserviert

    Beschreibung:
      Wenn diese Routine aufgerufen wird möchte man hier einen freien
      Dateinamen. Das bedeutet :
      1. Alle Reservierungen in der Datenbank für diese Session_ID können
         gelöscht werden. d.h. erstmal alle Datenbankreservierungen löschen.
      2.

  \***************************************************************************/
  function get_next_filename_from_db () {
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("./db_operation.php");

    $db = new db_access ($conf_4f_db  ["server"],
                         $conf_4f_db  ["datenbank"],
                         $conf_4f_tbl ["anhang"],
                         $conf_4f_db  ["user"],
                         $conf_4f_db  ["password"]);
      //setze alte Reservierungen auf status = 4  für eigene Session ID
    $this->loesche_reservierungen ($db, $conf_4f_tbl ["anhang"]);
      // Dateinamen aus abgebrochene Reservierrungen
    $frei_res = $this->res_abgebr ($db, $conf_4f_tbl ["anhang"]);
//echo "FREIRES===";var_dump($frei_res); echo "<br>";
    if ($frei_res) {
      $this->fs_nextfilename = $frei_res;
    }else{
      $this->next_filename ($db, $conf_4f_tbl ["anhang"], $conf_4f[hoheit]);
    }
  }

  /***************************************************************************\
    Funktion:     loesche_reservierungen
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function loesche_reservierungen ($db, $tbl){
    $query = "SELECT * FROM ".$tbl."
              WHERE ((`id` = \"".session_id()."\")AND
                     (`status` = \"8\"));";
    $result = $db->query_table ($query);
    $query = "UPDATE ".$tbl."
              SET   `status` = \"4\",
                    `id` = \"\"
              WHERE ((`id` = \"".session_id()."\")AND
                     (`status` = \"8\"));";
    $result = $db->query_table_iu ($query);
    $query = "SELECT * FROM ".$tbl."
              WHERE ((`id` = \"".session_id()."\")AND
                     (`status` = \"8\"));";
    $result = $db->query_table ($query);
  }

  /***************************************************************************\
    Funktion:     loesche_reservierungen
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function reset_reservation (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("./db_operation.php");
    $db = new db_access ($conf_4f_db  ["server"],
                         $conf_4f_db  ["datenbank"],
                         $conf_4f_tbl ["anhang"],
                         $conf_4f_db  ["user"],
                         $conf_4f_db  ["password"]);
    $this->loesche_reservierungen ($db, $conf_4f_tbl ["anhang"]);
  }

  /***************************************************************************\
    Funktion:     res_filename_db
    Parameter:    $filename
    Beschreibung:

  \***************************************************************************/
  function res_filename_db ($filename){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("./db_operation.php");
    $db = new db_access ($conf_4f_db  ["server"],
                         $conf_4f_db  ["datenbank"],
                         $conf_4f_tbl ["anhang"],
                         $conf_4f_db  ["user"],
                         $conf_4f_db  ["password"]);
      // Welchen Status hat filename?
    $query = "SELECT `status`
              FROM ".$conf_4f_tbl ["anhang"]."
              WHERE ".$conf_4f_tbl ["anhang"].".filename =\"".$filename."\";";
    $result = $db->query_table ($query);
    if ($result != ""){
      $status_abfrage = $result [1][status];}
    else {
      $status_abfrage = NULL;
    }
      // Nicht in der Datenbank => anlegen
    if ($status_abfrage == "") {
      $query = "INSERT INTO ".$conf_4f_tbl ["anhang"]."
                SET `filename` = \"".$filename."\",
                    `status`   = \"8\",
                    `id`       = \"".session_id()."\";";
      $result = $db->query_table_iu ($query);
    } elseif ($status_abfrage == "4") {

      $query = "UPDATE ".$conf_4f_tbl ["anhang"]."
                SET   `status` = \"8\",
                      `id`     = \"".session_id()."\"
                WHERE ((`filename` = \"".$filename."\") AND
                       (`status` = \"4\"));";
    $result = $db->query_table_iu ($query);
    }
  }

  /***************************************************************************\
    Funktion:     res_abgebr
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function res_abgebr ($db, $tbl){
      // Abgebrochene Uploads
    $query = "SELECT  min(filename) as filename FROM ".$tbl." WHERE `status` = 4 GROUP BY filename";
    $result = $db->query_table ($query);
    if ($result != "") {return ($result[1][filename]);}
    else {
      return ("");
    }
  }

  /***************************************************************************\
    Funktion:     next_filename
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function next_filename ($db, $tbl, $hoheit){
      // Dateiname mit der höchsten Zahl
    $query = "SELECT MAX(filename) as filename,status FROM ".$tbl." WHERE 1 GROUP BY `status` ";
//echo "QUERY==="; var_dump($query); echo "<br>";
    $result = $db->query_table ($query);
//echo "RESULT==="; var_dump($result); echo "<br>";

    if ($result != "") {
      if ( ($result[2] != NULL) && ($result [2][filename] > $result [1][filename])){
        $filename = $result [2][filename];
        $status   = $result [2][status];
      } else {
        $filename = $result [1][filename];
        $status   = $result [1][status];
    }
    } else {
      $filename = "";
      $status   = "";
    }


    if ($filename != ""){
      $hoheitlen = strlen ( $hoheit );
      $filelen = strlen ($filename);
      $filehoheit = substr ( $filename, 0, $hoheitlen );
      if (strtoupper ( $hoheit ) == strtoupper ( $filehoheit ) ) {
        $nummer = substr ( $filename, $hoheitlen, ($filelen - $hoheitlen) );
        if ($nummer > $highest){ $highest = $nummer; }
      }
      $nextnum = $highest + 1 ;
      $expo = intval (log10 ($nextnum) )+1;
    } else {
      $expo = 1;
      $nextnum  = "1";
    }
    $fillzero = "";
      // fuelle mit Nullen auf
    for ( $i=1; $i<= ($this->filenamezero-$expo); $i++ ){
      $fillzero .= "0";
    }
      // Filename == hoheit + Nullen + Nächste Zahl
    $this->fs_nextfilename = $hoheit.$fillzero.$nextnum ;
//echo "FS_NEXTFILENAME==="; var_dump($this->fs_nextfilename); echo "<br>";
  }


  /***************************************************************************\
    Funktion:     change_status_in_db
    Parameter:    $change     : set oder get
                  $filename   : Dateiname
                  $status     : 8 - reserviert
                                4 - frei
                                2 -
                                1 - gesetzt
    Beschreibung:
      liest und ändert die Datenbankeinträge für die Dateinamen.

  \***************************************************************************/
  function change_status_in_db ($change, $filename, $status){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("./db_operation.php");
    $db = new db_access ($conf_4f_db  ["server"],
                         $conf_4f_db  ["datenbank"],
                         $conf_4f_tbl ["anhang"],
                         $conf_4f_db  ["user"],
                         $conf_4f_db  ["password"]);
      // Welchen Status hat filename?
    $query = "SELECT `status`
              FROM ".$conf_4f_tbl ["anhang"]."
              WHERE ".$conf_4f_tbl ["anhang"].".filename =\"".$filename."\";";
    if (debug) echo "anhang.php 75 -- Query ===".$query."<br>";
    $result = $db->query_table ($query);
    if ($result == "") {// filename ist noch nicht in der DB
      // Es kann Status 8 gesetzt werden
      if ($change == 8) {
        $query = "INSERT INTO ".$conf_4f_tbl ["anhang"]."
                  SET `filename` = ".$filename.",
                      `status`   = '8' ; ";
      }
    }
    $query = "SELECT * FROM ".$conf_4f_tbl ["anhang"]." WHERE 1 ";

    if (debug) echo "anhang.php 41 -- Query ===".$query."<br>";
    $result = $db->query_table ($query);
  } // change_status_in_db


/*****************************************************************************\
\*****************************************************************************/
  function save_in_db ($data) {
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("./db_operation.php");
    include ("./protokoll.php");

    list($filename, $extention) = explode (".",$data["filename"]);

    $db = new db_access ($conf_4f_db  ["server"],
                         $conf_4f_db  ["datenbank"],
                         $conf_4f_tbl ["anhang"],
                         $conf_4f_db  ["user"],
                         $conf_4f_db  ["password"]);

    $query = "SELECT `status`
              FROM ".$conf_4f_tbl ["anhang"]."
              WHERE (".$conf_4f_tbl ["anhang"].".filename =\"".$filename."\")";

    if (debug) echo "anhang.php 231 -- Query ===".$query."<br>";
    $result = $db->query_table ($query);
    if ($result == ""){
      $status = "";
    }else {
      $status =  $result [1][status];
    }
      // status == 8 ==> aktualisieren
    if ($status == "8") {
    $query = "UPDATE ".$conf_4f_tbl ["anhang"]." SET
                        `fileext`       = \"".$extention."\",
                        `org_filename`  = \"".$data["org_filename"]."\",
                        `comment`       = \"".$data["comment"]."\",
                        `md5hash`       = \"".$data["md5hash"]."\",
                        `kuerzel`       = \"".$data["kuerzel"]."\",
                        `date`          = \"".$data["time"]."\",
                        `status`        = \"1\",
                        `id`            = \"".session_id()."\"
                        WHERE `filename` = \"".$filename."\"";
    if (debug) echo "anhang.php 245 -- Query ===".$query."<br>";
    $result = $db->query_table_iu ($query);

    protokolleintrag ("Anhangdaten speichern",
                              $_SESSION[vStab_benutzer].";".
                              $_SESSION[vStab_kuerzel].";".
                              $_SESSION[vStab_funktion].";".
                              $_SESSION[vStab_rolle].";".
                              session_id().";".
                              $_SERVER[REMOTE_ADDR].";".
                              $data["filename"].";".
                              $data["org_filename"].";".
                              $data["time"]
                              );
    }
  }

  /**********************************************************************\
    Funktion: readDirectory ()

    benoetigte Datei:
  \**********************************************************************/
  function readDirectory($directory){
//    include ("../config.inc.php");
      $filesArr = array();
      if($ordner = dir($directory))
      {
          while($datei = $ordner->read())
          {
          if($datei != "." && $datei != "..") array_push($filesArr,$datei);
          }
      }
      rsort ($filesArr);
      return $filesArr;
  }


/*****************************************************************************\
  Funktion:  scan4nextfilename ()
  Parameter:
  Beschreibung:
    1. Prüfe kausalität Dateien und Datenbank
    2. Ziehe nächsten Wert aus der Datenbank
    3. Setze Status in der Datenbank auf Vergeben.

\*****************************************************************************/
  function scan4nextfilename (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/dbcfg.inc.php");

    $nextfile = $this->get_next_filename_from_db ();

    $hoheit = $conf_4f[hoheit];
    $hoheitlen = strlen ( $hoheit );
    $highest = 0;

    $nextnum = $highest + 1 ;
    $expo = intval (log10 ($nextnum) )+1;
    $fillzero = "";
      // fuelle mit Nullen auf
    for ( $i=1; $i<= ($this->filenamezero-$expo); $i++ ){
      $fillzero .= "0";
    }
      // Filename == hoheit + Nullen + Nächste Zahl
    $this->fs_nextfilename = $hoheit.$fillzero.$nextnum ;
  } // scan4nextfilename



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



  function convtaktodatetime ($taktime){
    include ("../4fcfg/config.inc.php");
    /* Datum ~= TTMM, Zeit == ~= HHMM */
    $tag    = substr ($taktime, 0, 2);
    $stunde = substr ($taktime, 2, 2);
    $minute = substr ($taktime, 4, 2);
    $monat  = substr ($taktime, 6, 3);
    $jahr   = substr ($taktime, 9, 4);
    $datetime = $jahr."-".$rew_tak_monate[strtolower($monat)]."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }

  function pre_html($titel){
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<meta content=\"text/html; charset=ISO-8859-1\" http-equiv=\"content-type\">\n";
    echo "<title>$titel</title>";
    echo "</head>";
    echo "<body>";
  }


  function fileselectform ($predata) {
    include ("../4fcfg/config.inc.php");
    echo "<form name=\"uploadform\" enctype=\"multipart/form-data\" method=\"post\" action=\"".$_SERVER['PHP_SELF']."\">\n";
	echo "<fieldset>\n";
    echo "<legend><big>Anhang hochladen</big></legend>\n";
    echo "<table style=\"text-align: left; width: 745px; height: 170px;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td>\n";
    echo "<table style=\"text-align: left; width: 740px; height: 143px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"1\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Dateiname:</td>\n";
    echo "  <td style=\"width: 769px;\"><big><big style=\"font-weight: bold;\">".$predata["newfilename"]."</big></big></td>\n";
    echo "  <input type=\"hidden\" name=\"fs_nextfilename\" value=\"".$predata["newfilename"]."\">\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Datei:</td>\n";
    echo "  <td style=\"width: 769px;\">";
    echo "  <input style=\"font-size:18px; font-weight:900; font-weight: bold;\" name=\"upload\" type=\"file\" size=\"60\">";
    echo "  </td>\n";
    echo "</tr>\n";
    echo "<tr>\n" ;
    echo "  <td style=\"width: 167px;\">Beschreibung</td>\n";
    echo "  <td style=\"width: 769px;\">";
    echo "   <input style=\"font-size:18px; font-weight:900;\" maxlength=\"255\" size=\"80\" name=\"fs_comment\" value=\"".$predata["comment"]."\"></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td>K&uuml;rzel</td>\n";
    echo "  <td style=\"width: 769px;\"><big><input maxlength=\"3\" size=\"3\" name=\"fs_shortname\" value=\"".$predata["kuerzel"]."\"></big></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Zeitstempel</td>\n";
    echo "  <td style=\"width: 769px;\"><input maxlength=\"13\" size=\"13\" name=\"fs_timestamp\" value=\"".$predata["time"]."\"></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "<td></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
	echo "</fieldset>\n";
	
	echo "<fieldset>";
    echo "<legend>Aktion:</legend>\n";
    echo "<table border=\"1\" cellpadding=\"2\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    echo "<tr>\n";
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
    echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\"></td>\n";
    echo "</td></tr>\n";
    echo "</table>\n";
	echo "</fieldset>\n";
	
    echo "</form>";
  }



  function post_html () {
    echo "</body>";
    echo "</html>";
  }

} // class fileupload


/*************************************************************************************************************
                                S T E U E R U N G
**************************************************************************************************************/



session_start();

define ("debug", false);

    include ("../4fcfg/config.inc.php");
    include_once ("./db_operation.php");  // Datenbank operationen

    include_once ("./4fachform.php");            // Formular Behandlung 4fach Vordruck
    include_once ("./tools.php");

if ( debug == true ){
  echo "<br><br>\n";
  echo "------ Anhang.PHP 261 an Anfang ------";     echo "#<br><br>\n";
  echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
  echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
}


/*****************************************************************************\

100 - Anhangmenü + Anhange zur Auswahl
  Im Hauptmenü [Anhänge] geklickt
  GET =  ["fm_anhang_x"]
     ==> Liste anzeigen mit Auswahl oder Uploadbutton
  101 - absenden
  102 - abbrechen
  103 - upload

101 - Aufruf Nachrichtenvordruck mit übernahme altdaten

103 - Datei hochladen Menü
  Im Anhangmenü [Upload] geklickt
  GET =  ["anhang"]=>  string(10) "ah_auswahl"
         ["ah_auswahl_x"]=>  string(2) "19"
         ["ah_auswahl_y"]=>  string(1) "6" } #
     ==> Vordruck mit Anhang öffnen
  111 - absenden
  112 - abbrechen

\*****************************************************************************/


/**********************************************************************\
  --- S T A B   s c h r e i b e n   m i t  A n h a n g ---

  Anhang ausgewaehlt und kann in Vordruck uebernommen werde
\**********************************************************************/
  if ( ($_SESSION ["vStab_rolle"]== "Stab") and
       (isset ($_GET["ah_auswahl_x"])) ){

    if ( debug == true ){ echo "### 358 Anhang ausgewaehlt und kann in Vordruck uebernommen werden ";  echo "<br>\n";}

    $keys = array_keys ($_GET);
    $ahkey = array ();
    foreach ($keys as $key){
      list($lfd, $num) = split("_", $key);
      if ($lfd == "lfd") { $ahkey [] = "lfd_".$num;}
    }

    $anhang = "";
    $inhalt = "\n\r";
    foreach ($ahkey as $anh){
      $db_data = readrecord_from_db($_GET [$anh]);
      $anhang .= $_GET [$anh].";";
      $anhang_date = konv_datetime_taktime ($db_data[1]["date"]);
      $inhalt .= $_GET [$anh]." - ".$db_data[1]["comment"]." - ".$anhang_date."\n";
    }
    $formdata = restore_formdata ();

    $formdata ["12_anhang"]   = $anhang;
    $formdata ["12_inhalt"]  .= $inhalt;

    $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"];
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
    exit;

  }


/**********************************************************************\
   --- F E R N M E L D E R  schreiben mit Anhang

\**********************************************************************/
    // Anhang ausgewaelt und kann in Vordruck uebernommen werden
  if ( ( ($_SESSION ["vStab_rolle"]== "Fernmelder")  )and
       (isset ($_GET["ah_auswahl_x"])) ){

    if ( debug == true ){ echo "### 417 anhang.php Vordruck aufrufen mit Daten füllen ";  echo "<br>\n";}

    $keys = array_keys ($_GET);
    $ahkey = array ();
    foreach ($keys as $key){
      list($lfd, $num) = split("_", $key);
      if ($lfd == "lfd") { $ahkey [] = "lfd_".$num;}
    }
    $anhang = "";
    $inhalt = "\n\r";
    foreach ($ahkey as $anh){
      $db_data = readrecord_from_db($_GET [$anh]);
      $anhang .= $_GET [$anh].";";
      $anhang_date = konv_datetime_taktime ($db_data[1]["date"]);
      $inhalt .= $_GET [$anh]." - ".$db_data[1]["comment"]." - ".$anhang_date."\n";
    }
    $formdata = restore_formdata ();

    $formdata ["12_anhang"]   = $anhang;
    $formdata ["12_inhalt"]  .= $inhalt;
    $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
    $formdata ["10_anschrift"]  = $conf_4f ["anschrift"];
    if (sichter_online()) {
      $form = new nachrichten4fach ($formdata, "FM-Eingang_Anhang", "");
    } else {
      $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
      $formdata ["16_empf"]         = "";
      $form = new nachrichten4fach ($formdata, "FM-Eingang_Anhang_Sichter", "");
    }
    exit;
  }



/*****************************************************************************\
   Datei: anhang.php

   benoetigte Dateien:

   Beschreibung:
      Auflistung eines Verzeichnisses zur auswahl als Anhang


   (C)2006-2008 Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

include_once ("../4fcfg/dbcfg.inc.php");
include_once ("../4fcfg/e_cfg.inc.php");
include_once ("./db_operation.php");  // Datenbank operationen



  /**********************************************************************\
    Funktion: readFiles_from_db ()
    lese die Datensätze aus der Datenbank
    benoetigte Datei:
  \**********************************************************************/
  function readFiles_from_db(){
    include ("../4fcfg/config.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["anhang"]."` where `status` = 1 ORDER BY filename DESC";
    $result = $dbaccess->query_table ($query);
    return ($result);
  }

  /**********************************************************************\
    Funktion: readFiles_from_db ()
    lese die Datensätze aus der Datenbank
    benoetigte Datei:
  \**********************************************************************/
  function readrecord_from_db($anhangname){
    list ($filename, $fileext) = explode (".",$anhangname);
    include ("../4fcfg/config.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT * FROM `".$conf_4f_tbl ["anhang"]."` where `filename` = \"".$filename."\" ORDER BY filename DESC";
    $result = $dbaccess->query_table ($query);
    return ($result);
  }

  /**********************************************************************\
    Funktion: readDirectory ()
    lese den Inhalt eines Verzeichnisses ein
    benoetigte Datei:
  \**********************************************************************/
  function readDirectory(){
    include ("../4fcfg/config.inc.php");
      $filesArr = array();
      if($ordner = dir($conf_4f ["ablage_dir"]))
      {
          while($datei = $ordner->read())
          {
          if($datei != "." && $datei != "..") array_push($filesArr,$datei);
          }
      }
      rsort ($filesArr);
      return $filesArr;
  }


/**********************************************************************\
   function: anhang_menue
\**********************************************************************/
  function anhang_menue (){
    include ("../4fcfg/config.inc.php");
    echo "<form name=\"uploadform\" enctype=\"multipart/form-data\" method=\"get\" action=\"anhang.php\">\n"; // action=\"".$_SERVER['PHP_SELF']."\">";
    echo "<!-- anhang.php Formularelemente und andere Elemente innerhalb des Formulars -->\n";

	echo "<fieldset>";
    echo "<legend>Aktion:</legend>\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\" bgcolor=\"#E0E0E0\">\n";
    echo "<tr>";
    echo "<input type=\"hidden\" name=\"anhang\" value=\"ah_auswahl\">\n";
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"ah_auswahl\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
    echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"ah_abbrechen\" src=\"".$conf_design_path."/cancel.gif\"></td>\n";
    echo "<td bgcolor=$color_button><input type=\"image\" name=\"ah_upload\" src=\"".$conf_design_path."/upload.gif\"></td>\n";
    echo "</tr>\n";
    echo "</table>";
	echo "</fieldset>\n";

 	echo "<fieldset>";
    echo "<legend>Liste der verfügbaren Dateien</legend>\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\" bgcolor=\"#E0E0E0\">\n";

    $files = readDirectory ();

    $db_file_data = readFiles_from_db();
    if ($db_file_data != NULL){
      $i = 0;
      echo "<TR>";
      echo "<TH>Auswahl</TH>";
      echo "<TH>Vorschau</TH>";
      echo "<TH>Dateiname</TH>";
      echo "<TH>Bemerkung</TH>";
      echo "<TH>org. Dateiname</TH>";
      echo "<TH>Datum/Zeit</TH>";
      echo "</TR>";
      foreach ($db_file_data as $file){
        echo "<tr>\n";
          // checkbox
        echo "<td style=\"text-align:center;\">\n";
        echo "<input type=\"checkbox\" name=\"lfd_".$i."\" value=\"".$file[filename].".".$file["fileext"]."\">\n";
        echo "</td>\n";
          // Preview, if posible
        echo "<td>\n";
        echo "<a href=\"".$conf_4f ["ablage_uri"]."/".$file["filename"].".".$file["fileext"]."\" target=\"_blank\">\n";
        echo "<img  border=\"0\" alt=\"Anhangdatei\" src=\"".$conf_pre_dir."/kats/4fach/showpic.php?file=".
            $conf_4f ["ablage_dir"]."/".$file["filename"].".".$file["fileext"]."&width=250\"></a></td>\n";
        echo "</td>\n";
          // filename
        echo "<td style=\"text-align:center;\"> <a href=\"".$conf_4f ["ablage_uri"]."/".$file[filename].".".$file["fileext"]."\" target=\"_blank\">$file[filename]</a></td>\n";
          // commend belong to the attechmant
        echo "<td> <a href=\"".$conf_4f ["ablage_uri"]."/".$file[filename].".".$file["fileext"]."\" target=\"_blank\">$file[comment]</a></td>\n";
          // org Dateiname
        echo "<td> <a href=\"".$conf_4f ["ablage_uri"]."/".$file[filename].".".$file["fileext"]."\" target=\"_blank\">localh$file[org_filename]</a></td>\n";
          // time when the attetchment was edit
        echo "<td> <a href=\"".$conf_4f ["ablage_uri"]."/".$file[filename].".".$file["fileext"]."\" target=\"_blank\">$file[date]</a></td>\n";
        echo "</tr>\n";
        $i++;
      }
    }
    echo "</table>\n";
	echo "</fieldset>";
    echo "</form>\n";
  }

/***********************************************************************\
   Steuerung über ein Sessioncookie
  anhang_menue();
     $_SESSION ["UPLOAD"] ==
        "fileselect" :

\***********************************************************************/

  function fileselect () {
    $instanz = new fileupload ();
    $instanz->pre_html("Upload");
    $instanz->get_next_filename_from_db();
    $data["newfilename"]  =  $instanz->fs_nextfilename;
    $data["kuerzel"]      =  $_SESSION["vStab_kuerzel"];
    $data["time"]         =  date("dHiMY");
    $instanz->res_filename_db ($data["newfilename"]);
    $instanz->fileselectform ($data);
    $instanz->post_html ();
    $_SESSION ["anhang_submenue"] =  110;
  }

  /****************************************************************************\
    Funktion: file_unselect
  \****************************************************************************/
  function file_unselect (){
    $instanz = new fileupload ();
    $instanz->reset_reservation ();
  }

  /***************************************************************************\

  \***************************************************************************/
  function store_formdata () {
    $_SESSION["01_medium"]       = $_GET["01_medium"];
    $_SESSION["01_datum"]        = $_GET["01_datum"];
    $_SESSION["01_zeichen"]      = $_GET["01_zeichen"];
    $_SESSION["05_gegenstelle"]  = $_GET["05_gegenstelle"];
    $_SESSION["06_befweg"]       = $_GET["06_befweg"];
    $_SESSION["06_befwegausw"]   = $_GET["06_befwegausw"];
    $_SESSION["07_durchspruch"]  = $_GET["07_durchspruch"];
    $_SESSION["08_befhinweis"]   = $_GET["08_befhinweis"];
    $_SESSION["08_befhinwausw"]  = $_GET["08_befhinwausw"];
    $_SESSION["09_vorrangstufe"] = $_GET["09_vorrangstufe"];
    $_SESSION["10_anschrift"]    = $_GET["10_anschrift"];
    $_SESSION["11_gesprnotiz"]   = $_GET["11_gesprnotiz"];
    $_SESSION["12_anhang"]       = $_GET["12_anhang"];
    $_SESSION["12_inhalt"]       = $_GET["12_inhalt"];
    $_SESSION["12_abfzeit"]      = $_GET["12_abfzeit"];
    $_SESSION["13_abseinheit"]   = $_GET["13_abseinheit"];
    $_SESSION["14_zeichen"]      = $_GET["14_zeichen"];
    $_SESSION["14_funktion"]     = $_GET["14_funktion"];
  }

  /***************************************************************************\
  \***************************************************************************/
  function restore_formdata () {
    if (isset ($_SESSION["01_medium"])){       $data["01_medium"]       = $_SESSION["01_medium"];       unset ($_SESSION["01_medium"]);  }
    if (isset ($_SESSION["01_datum"])){        $data["01_datum"]        = $_SESSION["01_datum"];        unset ($_SESSION["01_datum"]);  }
    if (isset ($_SESSION["01_zeichen"])){      $data["01_zeichen"]      = $_SESSION["01_zeichen"];      unset ($_SESSION["01_zeichen"]);  }
    if (isset ($_SESSION["05_gegenstelle"])){  $data["05_gegenstelle"]  = $_SESSION["05_gegenstelle"];  unset ($_SESSION["05_gegenstelle"]);  }
    if (isset ($_SESSION["06_befweg"])){       $data["06_befweg"]       = $_SESSION["06_befweg"];       unset ($_SESSION["06_befweg"]);  }
    if (isset ($_SESSION["06_befwegausw"])){   $data["06_befwegausw"]   = $_SESSION["06_befwegausw"];   unset ($_SESSION["06_befwegausw"]);  }
    if (isset ($_SESSION["07_durchspruch"])){  $data["07_durchspruch"]  = $_SESSION["07_durchspruch"];  unset ($_SESSION["07_durchspruch"]);  }
    if (isset ($_SESSION["08_befhinweis"])){   $data["08_befhinweis"]   = $_SESSION["08_befhinweis"];   unset ($_SESSION["08_befhinweis"]);  }
    if (isset ($_SESSION["08_befhinwausw"])){  $data["08_befhinwausw"]  = $_SESSION["08_befhinwausw"];  unset ($_SESSION["08_befhinwausw"]);  }
    if (isset ($_SESSION["09_vorrangstufe"])){ $data["09_vorrangstufe"] = $_SESSION["09_vorrangstufe"]; unset ($_SESSION["09_vorrangstufe"]);  }
    if (isset ($_SESSION["10_anschrift"])){    $data["10_anschrift"]    = $_SESSION["10_anschrift"];    unset ($_SESSION["10_anschrift"]);  }
    if (isset ($_SESSION["11_gesprnotiz"])){   $data["11_gesprnotiz"]   = $_SESSION["11_gesprnotiz"];   unset ($_SESSION["11_gesprnotiz"]);  }
    if (isset ($_SESSION["12_anhang"])){       $data["12_anhang"]       = $_SESSION["12_anhang"];       unset ($_SESSION["12_anhang"]);  }
    if (isset ($_SESSION["12_inhalt"])){       $data["12_inhalt"]       = $_SESSION["12_inhalt"];       unset ($_SESSION["12_inhalt"]);  }
    if (isset ($_SESSION["12_abfzeit"])){      $data["12_abfzeit"]      = $_SESSION["12_abfzeit"];      unset ($_SESSION["12_abfzeit"]);  }
    if (isset ($_SESSION["13_abseinheit"])){   $data["13_abseinheit"]   = $_SESSION["13_abseinheit"];   unset ($_SESSION["13_abseinheit"]);  }
    if (isset ($_SESSION["14_zeichen"])){      $data["14_zeichen"]      = $_SESSION["14_zeichen"];      unset ($_SESSION["14_zeichen"]);  }
    if (isset ($_SESSION["14_funktion"])){     $data["14_funktion"]     = $_SESSION["14_funktion"];     unset ($_SESSION["14_funktion"]);  }
    return $data;
  }


  function fileselectwindow (){
    include ("../4fcfg/config.inc.php");
        // zwei möglichkeiten 1. absenden oder 2. abbrechen
    if (!isset($_POST["abbrechen_x"])) {
      $max_size = 1024*1024*5; // the max. size for uploading
      $my_upload = new fileupload;
      $my_upload->upload_dir = $conf_4f ["ablage_dir"]."/" ; // "files" is the folder for the uploaded files (you have to create this folder)
        if ( debug == true ){ echo "Upload-Dir:".$my_upload->upload_dir."<br>";}

      $my_upload->extensions = array(".jpg",".tif",".gif",".avi",".png",".bmp",".zip",".pdf",".doc",".xls",".odt",".txt", ".xia"); // Erlaubte Dateierweiterungen
      $my_upload->max_length_filename = 100; // change this value to fit your field length in your database (standard 100)
      $my_upload->rename_file = true;
      if (isset($_POST["absenden_x"])) {							if ( debug == true ){ echo "001 is set POST absender_x<br>";}
        $my_upload->the_temp_file = $_FILES['upload']['tmp_name'];	if ( debug == true ){ echo "002 tmpname =".$my_upload->the_temp_file."<br>";}
        $my_upload->the_file = $_FILES['upload']['name'];			if ( debug == true ){ echo "003 name    =".$my_upload->the_file."<br>";}
        $my_upload->http_error = $_FILES['upload']['error'];		if ( debug == true ){ echo "004 error   =".$my_upload->http_error."<br>";}
																	if ( debug == true ){ echo "004a _FILES ="; var_dump ($_FILES); echo"<br><br>";}
		if ($my_upload->http_error != 0){
          $errortxt = $my_upload->error_text($my_upload->http_error);
          echo "<big><big><b>".$errortxt."</b></big></big>";
        }
        $my_upload->replace = true ; //(isset($_POST['replace'])) ? $_POST['replace'] : "n"; // because only a checked checkboxes is true
        $my_upload->do_filename_check = false; // (isset($_POST['check'])) ? $_POST['check'] : "n"; // use this boolean to check for a valid filename

        $new_name = (isset($_POST['fs_nextfilename'])) ? $_POST['fs_nextfilename'] : "";	if ( debug == true ){ echo "005 newname   =".$new_name."<br>";}

        if ($my_upload->upload($new_name)) { // new name is an additional filename information, use this to rename the uploaded file
          $full_path = $my_upload->upload_dir.$my_upload->file_copy;	if ( debug == true ){ echo "006 full_path   =".$full_path."<br>";}
          $info = $my_upload->get_uploaded_file_info($full_path);		if ( debug == true ){ echo "007 info        =".$info."<br>";}
          $data["filename"]     = basename ($full_path); //$_POST ["fs_nextfilename"] ;
          $data["org_filename"] = $_FILES["upload"]["name"];
          $data["comment"]      = $_POST ["fs_comment"];
          $data["kuerzel"]      = $_POST ["fs_shortname"];
          $data["time"]         = $my_upload->convtaktodatetime ($_POST ["fs_timestamp"]);
          $data["md5hash"]      = md5_file($full_path);					if (debug){echo "data==="; var_dump($data); echo "<br>";}
          $my_upload->save_in_db ($data);
        }
      }
    }
    file_unselect ();
    unset ($_SESSION ["UPLOAD"]);
    anhang_menue ();
    exit;
  }


  switch ($_SESSION["anhang_menue"]){

    case 100: // Auswahlmenue
        if (debug) echo "anhang.php 663 -- Auswahlmenue<br>";
        store_formdata();
        anhang_menue ();
        $_SESSION["anhang_menue"] = 110;
    break;

    case 110: // UPLOAD Menue
        if (debug) echo "anhang.php 789 -- anhang_menue == 110 --> UPLOADMENUE<br>";

        if ( isset ($_GET ["ah_upload_x"])){
          fileselect ();
        }

        if ( isset ($_GET ["ah_abbrechen_x"])){
          unset ($_SESSION["anhang_menue"]);
          unset ($_SESSION["anhang"]);
          header("Location: ".$conf_4f ["MainURL"]);
        }

        if ( (isset ($_POST["absenden_x"] )) OR
             (isset ($_POST["abbrechen_x"]))){

          fileselectwindow ();
        }
    break;

    case 999: // ??
        if (debug) echo "anhang.php 670 -- anhang_menue == 110 --> UPLOADMENUE<br>";
        if ( isset ($_GET ["ah_upload_x"])){
          fileselect ();
        }
        if ( isset ($_GET ["ah_abbrechen_x"])){
           unset ($_SESSION["anhang_menue"]);
           unset ($_SESSION["anhang"]);
           header("Location: ".$conf_4f ["MainURL"]);
        }
        if ($_POST["absenden_x"]){
          fileselectwindow ();
        }
    break;
	
    default;
      echo "<big><big><big>Kein Menüpunkt !!!</big></big></big><br>" ;
  }



if ( debug == true ){
  echo "<br><br>\n";
  echo "------ anhang.php 692------";
  echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
  // echo "SERVER  ="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
  echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
}

?>
