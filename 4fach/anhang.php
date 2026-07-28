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

20160510 - store_formdata von $_GET auf $_POST umgestellt. 
******************************************************************************/

include ("./upload_class.php");
require_once __DIR__ . "/../app/attachment.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/file_access.php";
require_once __DIR__ . "/../app/session_ui.php";

class fileupload extends file_upload {
  // fs - fileselectform Dateiauswahl
  var $fs_savename;     // Einlagerungsdateiname  HSxxxxx
  var $fs_uplname;      // Uploaddateiname
  var $fs_comment;      // Beschreibung
  var $fs_shortname;    // Kuerzel
  var $fs_timestamp;    // Zeitstempel
  var $fs_nextfilename; // NÃ¤chster Dateiname

  var $ff_savename ;    // Name der gespeicherten Datei g.g. Darstellung im Menue
  var $ff_filename ;    // UrsprÃ¼nglicher Dateiname
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
      Wenn diese Routine aufgerufen wird mÃ¶chte man hier einen freien
      Dateinamen. Das bedeutet :
      1. Alle Reservierungen in der Datenbank fÃ¼r diese Session_ID kÃ¶nnen
         gelÃ¶scht werden. d.h. erstmal alle Datenbankreservierungen lÃ¶schen.
      2.

  \***************************************************************************/
  function get_next_filename_from_db () {
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      $this->fs_nextfilename = estab_attachment_reserve (
        $connection,
        $conf_4f_tbl ["anhang"],
        $conf_4f ["hoheit"],
        session_id (),
        $this->filenamezero
      );
      return $this->fs_nextfilename;
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /***************************************************************************\
    Funktion:     loesche_reservierungen
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function loesche_reservierungen ($db, $tbl){
    unset ($db);
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      estab_attachment_release_unclaimed ($connection, $tbl, session_id ());
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /***************************************************************************\
    Funktion:     loesche_reservierungen
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function reset_reservation (){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      estab_attachment_release ($connection, $conf_4f_tbl ["anhang"], session_id ());
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /***************************************************************************\
    Funktion:     res_filename_db
    Parameter:    $filename
    Beschreibung:

  \***************************************************************************/
  function res_filename_db ($filename){
    return ($filename === $this->fs_nextfilename);
  }

  /***************************************************************************\
    Funktion:     res_abgebr
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function res_abgebr ($db, $tbl){
    unset ($db, $tbl);
    return ("");
  }

  /***************************************************************************\
    Funktion:     next_filename
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function next_filename ($db, $tbl, $hoheit){
    unset ($db, $tbl, $hoheit);
    return $this->get_next_filename_from_db ();
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
      liest und Ã¤ndert die DatenbankeintrÃ¤ge fÃ¼r die Dateinamen.

  \***************************************************************************/
  function change_status_in_db ($change, $filename, $status){
    unset ($status);
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      if ((string) $change === "4") {
        estab_attachment_release ($connection, $conf_4f_tbl ["anhang"], session_id (), $filename);
        return true;
      }
      if ((string) $change === "8") {
        return $this->res_filename_db ($filename);
      }
      return false;
    } finally {
      estab_attachment_close ($connection);
    }
  } // change_status_in_db

  function claim_reservation ($filename){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      return estab_attachment_claim ($connection, $conf_4f_tbl ["anhang"], $filename, session_id ());
    } finally {
      estab_attachment_close ($connection);
    }
  }

  function release_reservation ($filename){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      estab_attachment_release ($connection, $conf_4f_tbl ["anhang"], session_id (), $filename);
    } finally {
      estab_attachment_close ($connection);
    }
  }


/*****************************************************************************\
\*****************************************************************************/
  function save_in_db ($data) {
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $reservation = (string) ($data ["reservation"] ?? "");
    $metadata = estab_attachment_validate_metadata (
      $data,
      $reservation,
      (string) ($_SESSION ["vStab_kuerzel"] ?? "")
    );
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      $saved = estab_attachment_finalize (
        $connection,
        $conf_4f_tbl ["anhang"],
        session_id (),
        $metadata
      );
      if (!$saved) {
        return false;
      }

      $details = (string) ($_SESSION ["vStab_benutzer"] ?? "").";".
                 (string) ($_SESSION ["vStab_kuerzel"] ?? "").";".
                 (string) ($_SESSION ["vStab_funktion"] ?? "").";".
                 (string) ($_SESSION ["vStab_rolle"] ?? "").";".
                 session_id ().";".
                 estab_auth_remote_ip ($_SERVER).";".
                 $metadata ["filename"].".".$metadata ["fileext"].";".
                 $metadata ["org_filename"].";".
                 $metadata ["date"];
      try {
        estab_attachment_log ($connection, $conf_4f_tbl ["protokoll"], "Anhangdaten speichern", $details);
      } catch (Throwable $exception) {
        error_log ("eStab attachment audit failed: ".$exception->getMessage ());
      }
      return true;
    } finally {
      estab_attachment_close ($connection);
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
    1. PrÃ¼fe kausalitÃ¤t Dateien und Datenbank
    2. Ziehe nÃ¤chsten Wert aus der Datenbank
    3. Setze Status in der Datenbank auf Vergeben.

\*****************************************************************************/
  function scan4nextfilename (){
    return $this->get_next_filename_from_db ();
  } // scan4nextfilename



/*****************************************************************************\

\*****************************************************************************/
  function konv_datetime_taktime ($datetime){
    require ("../4fcfg/config.inc.php");
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
    require ("../4fcfg/config.inc.php");
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
    echo "<head>";
    echo "<meta content=\"text/html; charset=UTF-8\" http-equiv=\"content-type\">\n";
    echo "<title>".estab_attachment_html ($titel)."</title>";
    echo "</head>";
    echo "<body>";
  }


  function fileselectform ($predata) {
    require ("../4fcfg/config.inc.php");
    $formAction = estab_attachment_html ($_SERVER['PHP_SELF'] ?? "anhang.php");
    $newFilename = estab_attachment_html ($predata["newfilename"] ?? "");
    $comment = estab_attachment_html ($predata["comment"] ?? "");
    $shortname = estab_attachment_html ($predata["kuerzel"] ?? "");
    $timestamp = estab_attachment_html ($predata["time"] ?? "");
    echo "<form name=\"uploadform\" enctype=\"multipart/form-data\" method=\"post\" action=\"".$formAction."\" data-estab-dirty-guard>\n";
    echo estab_csrf_field ()."\n";
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
    echo "  <td style=\"width: 769px;\"><big><big style=\"font-weight: bold;\">".$newFilename."</big></big></td>\n";
    echo "  <input type=\"hidden\" name=\"fs_nextfilename\" value=\"".$newFilename."\">\n";
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
    echo "   <input style=\"font-size:18px; font-weight:900;\" maxlength=\"255\" size=\"80\" name=\"fs_comment\" value=\"".$comment."\"></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td>K&uuml;rzel</td>\n";
    echo "  <td style=\"width: 769px;\"><big><input maxlength=\"6\" size=\"6\" name=\"fs_shortname\" value=\"".$shortname."\" readonly></big></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Zeitstempel</td>\n";
    echo "  <td style=\"width: 769px;\"><input maxlength=\"13\" size=\"13\" name=\"fs_timestamp\" value=\"".$timestamp."\"></td>\n";
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



if (session_status () === PHP_SESSION_NONE) {
  session_start ();
}
if (!estab_auth_session_is_authenticated ($_SESSION)) {
  http_response_code (403);
  header ("Content-Type: text/plain; charset=UTF-8");
  header ("Cache-Control: no-store");
  echo "Anmeldung erforderlich.";
  exit;
}
estab_session_ui_start ($_SESSION);

if (!defined ("debug")) { define ("debug", false); }

    require ("../4fcfg/config.inc.php");
    require_once ("./db_operation.php");  // Datenbank operationen

    require_once ("./4fachform.php");            // Formular Behandlung 4fach Vordruck
    require_once ("./tools.php");

if ( debug == true ){
  echo "<br><br>\n";
  echo "------ Anhang.PHP 518 an Anfang ------";     echo "#<br><br>\n";
  echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
  echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
}


/*****************************************************************************\

100 - AnhangmenÃ¼ + Anhange zur Auswahl
  Im HauptmenÃ¼ [AnhÃ¤nge] geklickt
  GET =  ["fm_anhang_x"]
     ==> Liste anzeigen mit Auswahl oder Uploadbutton
  101 - absenden
  102 - abbrechen
  103 - upload

101 - Aufruf Nachrichtenvordruck mit Ã¼bernahme altdaten

103 - Datei hochladen MenÃ¼
  Im AnhangmenÃ¼ [Upload] geklickt
  GET =  ["anhang"]=>  string(10) "ah_auswahl"
         ["ah_auswahl_x"]=>  string(2) "19"
         ["ah_auswahl_y"]=>  string(1) "6" } #
     ==> Vordruck mit Anhang Ã¶ffnen
  111 - absenden
  112 - abbrechen

\*****************************************************************************/


/**********************************************************************\
  --- S T A B   s c h r e i b e n   m i t  A n h a n g ---

  Anhang ausgewaehlt und kann in Vordruck uebernommen werde
\**********************************************************************/
  if ( ($_SESSION ["vStab_rolle"]== "Stab") and
       ( (isset ($_GET["ah_auswahl_x"])) OR
         (isset ($_GET["ah_abbrechen_x"]))
       )
      ){

    if ( debug == true ){ echo "### 559 Anhang ausgewaehlt und kann in Vordruck uebernommen werden ";  echo "<br>\n";}

    $keys = array_keys ($_GET);
    $ahkey = array ();
    foreach ($keys as $key){
      list($lfd, $num) = explode("_", $key);
      if ($lfd == "lfd") { $ahkey [] = "lfd_".$num;}
    }

    $anhang = "";
    $inhalt = "\n\r";
    foreach ($ahkey as $anh){
      $db_data = readrecord_from_db((string) ($_GET [$anh] ?? ""));
      if (!isset ($db_data [1])) { continue; }
      try {
        $selectedBase = estab_attachment_validate_reservation_name ((string) $db_data [1]["filename"]);
      } catch (InvalidArgumentException) {
        continue;
      }
      $selectedExtension = strtolower ((string) $db_data [1]["fileext"]);
      if (!estab_attachment_extension_is_allowed ($selectedExtension)
          || !estab_attachment_validate_sql_datetime ((string) $db_data [1]["date"])) {
        continue;
      }
      $selectedName = $selectedBase.".".$selectedExtension;
      $anhang .= $selectedName.";";
      $anhang_date = konv_datetime_taktime ($db_data[1]["date"]);
      $inhalt .= estab_attachment_html ($selectedName)." - ".estab_attachment_html ($db_data[1]["comment"])." - ".estab_attachment_html ($anhang_date)."\n";
    }
    $formdata = restore_formdata ();

    if (isset ($_GET["ah_auswahl_x"])) {
      $formdata ["12_anhang"]   = $anhang;
      $formdata ["12_inhalt"]  .= $inhalt;

      $formdata ["13_abseinheit"]  = $conf_4f     ["anschrift"];
      $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
      $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    }
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
    exit;

  }


/**********************************************************************\
   --- F E R N M E L D E R  schreiben mit Anhang

\**********************************************************************/
    // Anhang ausgewaelt und kann in Vordruck uebernommen werden
  if ( ( ($_SESSION ["vStab_rolle"]== "Fernmelder")  )and
       ( (isset ($_GET["ah_auswahl_x"])) OR
         (isset ($_GET["ah_abbrechen_x"]))
       )
      ){

    if ( debug == true ){ echo "### 417 anhang.php Vordruck aufrufen mit Daten fÃ¼llen ";  echo "<br>\n";}

    $keys = array_keys ($_GET);
    $ahkey = array ();
    foreach ($keys as $key){
	  if (preg_match('/_/', $key) > 0 )	{ 
	    list($lfd, $num) = preg_split('/_/', $key, 2); 
	    if ($lfd == "lfd") { $ahkey [] = "lfd_".$num;}
	  }
    }
    $anhang = "";
    $inhalt = "\n\r";
    foreach ($ahkey as $anh){
      $db_data = readrecord_from_db((string) ($_GET [$anh] ?? ""));
      if (!isset ($db_data [1])) { continue; }
      try {
        $selectedBase = estab_attachment_validate_reservation_name ((string) $db_data [1]["filename"]);
      } catch (InvalidArgumentException) {
        continue;
      }
      $selectedExtension = strtolower ((string) $db_data [1]["fileext"]);
      if (!estab_attachment_extension_is_allowed ($selectedExtension)
          || !estab_attachment_validate_sql_datetime ((string) $db_data [1]["date"])) {
        continue;
      }
      $selectedName = $selectedBase.".".$selectedExtension;
      $anhang .= $selectedName.";";
      $anhang_date = konv_datetime_taktime ($db_data[1]["date"]);
      $inhalt .= estab_attachment_html ($selectedName)." - ".estab_attachment_html ($db_data[1]["comment"])." - ".estab_attachment_html ($anhang_date)."\n";
    }
    $formdata = restore_formdata ();
    if ( debug == true ){
      echo "<b>anhang.php 623 restore_formdata</b>";
      echo "<br>\n";
      print_r ($formdata); echo "<br>";
    }

    if (isset ($_GET["ah_auswahl_x"])) {
      $formdata ["12_anhang"]   = $anhang;
      $formdata ["12_inhalt"]  .= $inhalt;
      $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
      $formdata ["10_anschrift"]  = $conf_4f ["anschrift"];
    }
    if (sichter_online()) {
      $form = new nachrichten4fach ($formdata, "FM-Eingang_Anhang", "");
    } else {
      $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
//      $formdata ["16_empf"]         = $_SESSION ["16_empf"]; //"";
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

require ("../4fcfg/dbcfg.inc.php");
require ("../4fcfg/e_cfg.inc.php");
require_once ("./db_operation.php");  // Datenbank operationen



  /**********************************************************************\
    Funktion: readFiles_from_db ()
    lese die DatensÃ¤tze aus der Datenbank
    benoetigte Datei:
  \**********************************************************************/
  function readFiles_from_db(){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      return estab_attachment_list ($connection, $conf_4f_tbl ["anhang"]);
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /**********************************************************************\
    Funktion: readFiles_from_db ()
    lese die DatensÃ¤tze aus der Datenbank
    benoetigte Datei:
  \**********************************************************************/
  function readrecord_from_db($anhangname){
    $filename = pathinfo (basename ((string) $anhangname), PATHINFO_FILENAME);
    try {
      $filename = estab_attachment_validate_reservation_name ($filename);
    } catch (InvalidArgumentException) {
      return array ();
    }
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      $result = estab_attachment_find ($connection, $conf_4f_tbl ["anhang"], $filename);
      return is_array ($result) ? array (1 => $result) : array ();
    } finally {
      estab_attachment_close ($connection);
    }
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
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"ah_auswahl\" src=\"".$conf_design_path."/ok.gif\" alt=\"Ausgewählte Anhänge übernehmen\"></td>\n";
    echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"ah_abbrechen\" src=\"".$conf_design_path."/cancel.gif\" alt=\"Zurück zum Nachrichtenvordruck\"></td>\n";
    echo "<td bgcolor=$color_button><input type=\"image\" name=\"ah_upload\" src=\"".$conf_design_path."/upload.gif\" alt=\"Neuen Anhang hochladen\"></td>\n";
    echo "</tr>\n";
    echo "</table>";
        echo "</fieldset>\n";

        echo "<fieldset>";
    echo "<legend>Liste der verfÃ¼gbaren Dateien</legend>\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\" bgcolor=\"#E0E0E0\">\n";

    $db_file_data = readFiles_from_db();
    if ($db_file_data !== array ()){
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
        try {
          $storedFilename = estab_attachment_validate_reservation_name ((string) ($file ["filename"] ?? ""));
        } catch (InvalidArgumentException) {
          continue;
        }
        $storedExtension = strtolower ((string) ($file ["fileext"] ?? ""));
        if (preg_match ("/\\A[a-z0-9]{1,16}\\z/D", $storedExtension) !== 1
            || !estab_attachment_extension_is_allowed ($storedExtension)) {
          continue;
        }
        $attachmentValue = $storedFilename.".".$storedExtension;
        try {
          $publicUrl = estab_file_download_url (
            (string) $conf_4f ["download_uri"],
            "attachment",
            $attachmentValue
          );
        } catch (InvalidArgumentException) {
          continue;
        }
        $safePublicUrl = estab_attachment_html ($publicUrl);
        echo "<tr>\n";
          // checkbox
        echo "<td style=\"text-align:center;\">\n";
        echo "<input type=\"checkbox\" name=\"lfd_".$i."\" value=\"".estab_attachment_html ($attachmentValue)."\">\n";
        echo "</td>\n";
          // Preview, if posible
        echo "<td>\n";
        echo "<a href=\"".$safePublicUrl."\" target=\"_blank\" rel=\"noopener\">\n";
        $previewUrl = $conf_urlroot.$conf_web ["pre_path"]."4fach/showpic.php?".
                      http_build_query (
                        array ("file" => $attachmentValue, "width" => 250),
                        "",
                        "&",
                        PHP_QUERY_RFC3986
                      );
        echo "<img border=\"0\" alt=\"Anhangdatei\" src=\"".estab_attachment_html ($previewUrl)."\"></a></td>\n";
        echo "</td>\n";
          // filename
        echo "<td style=\"text-align:center;\"> <a href=\"".$safePublicUrl."\" target=\"_blank\" rel=\"noopener\">".estab_attachment_html ($storedFilename)."</a></td>\n";
          // commend belong to the attechmant
        echo "<td> <a href=\"".$safePublicUrl."\" target=\"_blank\" rel=\"noopener\">".estab_attachment_html ($file ["comment"] ?? "")."</a></td>\n";
          // org Dateiname
        echo "<td> <a href=\"".$safePublicUrl."\" target=\"_blank\" rel=\"noopener\">".estab_attachment_html ($file ["org_filename"] ?? "")."</a></td>\n";
          // time when the attetchment was edit
        echo "<td> <a href=\"".$safePublicUrl."\" target=\"_blank\" rel=\"noopener\">".estab_attachment_html ($file ["date"] ?? "")."</a></td>\n";
        echo "</tr>\n";
        $i++;
      }
    }
    echo "</table>\n";
        echo "</fieldset>";
    echo "</form>\n";
  }

/***********************************************************************\
   Steuerung Ã¼ber ein Sessioncookie
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
  	 if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>store_formdata</big></big><br>";  }
    $_SESSION["01_medium"]       = $_POST["01_medium"];
    $_SESSION["01_datum"]        = $_POST["01_datum"];
    $_SESSION["01_zeichen"]      = $_POST["01_zeichen"];
    $_SESSION["05_gegenstelle"]  = $_POST["05_gegenstelle"];
    $_SESSION["06_befweg"]       = $_POST["06_befweg"];
    $_SESSION["06_befwegausw"]   = $_POST["06_befwegausw"];
    $_SESSION["07_durchspruch"]  = $_POST["07_durchspruch"];
    $_SESSION["08_befhinweis"]   = $_POST["08_befhinweis"];
    $_SESSION["08_befhinwausw"]  = $_POST["08_befhinwausw"];
    $_SESSION["09_vorrangstufe"] = $_POST["09_vorrangstufe"];
    $_SESSION["10_anschrift"]    = $_POST["10_anschrift"];
    $_SESSION["11_gesprnotiz"]   = $_POST["11_gesprnotiz"] ?? "";
    $_SESSION["12_anhang"]       = $_POST["12_anhang"];
    $_SESSION["12_inhalt"]       = $_POST["12_inhalt"];
    $_SESSION["12_abfzeit"]      = $_POST["12_abfzeit"];
    $_SESSION["13_abseinheit"]   = $_POST["13_abseinheit"];
    $_SESSION["14_zeichen"]      = $_POST["14_zeichen"];
    $_SESSION["14_funktion"]     = $_POST["14_funktion"];
    $_SESSION["15_quitdatum"]    = $_POST["15_quitdatum"] ?? "";
    $_SESSION["15_quitzeichen"]  = $_POST["15_quitzeichen"] ?? "";
    $_SESSION["16_gncopy"]       = $_POST["16_gncopy"] ?? "";
    for ($m=1; $m<=5; $m++){
      for ($n=1; $n<=4; $n++){
        if (isset ($_POST["16_".$m.$n])) $_SESSION["16_".$m.$n] = $_POST["16_".$m.$n] ;
//        echo "key==="."16_".$m.$n."  SESSION=====".$_SESSION["16_".$m.$n]."<br>";
      }
    }
    $_SESSION["17_vermerke"] = $_POST["17_vermerke"] ?? "";
  }

  /***************************************************************************\
  \***************************************************************************/
  function restore_formdata () {
    require ("../4fcfg/fkt_rolle.inc.php");
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big> restore_formdata</big></big><br>";  }
    if (isset ($_SESSION["01_medium"])){       $data["01_medium"]       = $_SESSION["01_medium"];       unset ($_SESSION["01_medium"]);  }      else { $data["01_medium"]      = "";}
    if (isset ($_SESSION["01_datum"])){        $data["01_datum"]        = $_SESSION["01_datum"];        unset ($_SESSION["01_datum"]);  }       else { $data["01_datum"]       = "";}
    if (isset ($_SESSION["01_zeichen"])){      $data["01_zeichen"]      = $_SESSION["01_zeichen"];      unset ($_SESSION["01_zeichen"]);  }     else { $data["01_zeichen"]     = "";}
	if (isset ($_SESSION["02_zeichen"])){      $data["02_zeichen"]      = $_SESSION["02_zeichen"];      unset ($_SESSION["02_zeichen"]);  }     else { $data["02_zeichen"]     = "";}
	if (isset ($_SESSION["03_zeichen"])){      $data["03_zeichen"]      = $_SESSION["03_zeichen"];      unset ($_SESSION["03_zeichen"]);  }     else { $data["03_zeichen"]     = "";}
	if (isset ($_SESSION["04_richtung"])){     $data["04_richtung"]     = $_SESSION["04_richtung"];     unset ($_SESSION["04_richtung"]);  }    else { $data["04_richtung"]    = "";}
	if (isset ($_SESSION["04_nummer"])){       $data["04_nummer"]       = $_SESSION["04_nummer"];       unset ($_SESSION["04_nummer"]);  }      else { $data["04_nummer"]      = "";}
    if (isset ($_SESSION["05_gegenstelle"])){  $data["05_gegenstelle"]  = $_SESSION["05_gegenstelle"];  unset ($_SESSION["05_gegenstelle"]);  } else { $data["05_gegenstelle"] = "";}
    if (isset ($_SESSION["06_befweg"])){       $data["06_befweg"]       = $_SESSION["06_befweg"];       unset ($_SESSION["06_befweg"]);  }      else { $data["06_befweg"]      = "";}
    if (isset ($_SESSION["06_befwegausw"])){   $data["06_befwegausw"]   = $_SESSION["06_befwegausw"];   unset ($_SESSION["06_befwegausw"]);  }  else { $data["06_befwegausw"]  = "";}
    if (isset ($_SESSION["07_durchspruch"])){  $data["07_durchspruch"]  = $_SESSION["07_durchspruch"];  unset ($_SESSION["07_durchspruch"]);  } else { $data["07_durchspruch"] = "";}
    if (isset ($_SESSION["08_befhinweis"])){   $data["08_befhinweis"]   = $_SESSION["08_befhinweis"];   unset ($_SESSION["08_befhinweis"]);  }  else { $data["08_befhinweis"]  = "";}
    if (isset ($_SESSION["08_befhinwausw"])){  $data["08_befhinwausw"]  = $_SESSION["08_befhinwausw"];  unset ($_SESSION["08_befhinwausw"]);  } else { $data["08_befhinwausw"] = "";}
    if (isset ($_SESSION["09_vorrangstufe"])){ $data["09_vorrangstufe"] = $_SESSION["09_vorrangstufe"]; unset ($_SESSION["09_vorrangstufe"]);  }else { $data["09_vorrangstufe"]= "";}
    if (isset ($_SESSION["10_anschrift"])){    $data["10_anschrift"]    = $_SESSION["10_anschrift"];    unset ($_SESSION["10_anschrift"]);  }   else { $data["10_anschrift"]   = "";}
    if (isset ($_SESSION["11_gesprnotiz"])){   $data["11_gesprnotiz"]   = $_SESSION["11_gesprnotiz"];   unset ($_SESSION["11_gesprnotiz"]);  }  else { $data["11_gesprnotiz"]  = "";}
    if (isset ($_SESSION["12_anhang"])){       $data["12_anhang"]       = $_SESSION["12_anhang"];       unset ($_SESSION["12_anhang"]);  }      else { $data["12_anhang"]      = "";}
    if (isset ($_SESSION["12_inhalt"])){       $data["12_inhalt"]       = $_SESSION["12_inhalt"];       unset ($_SESSION["12_inhalt"]);  }      else { $data["12_inhalt"]      = "";}
    if (isset ($_SESSION["12_abfzeit"])){      $data["12_abfzeit"]      = $_SESSION["12_abfzeit"];      unset ($_SESSION["12_abfzeit"]);  }     else { $data["12_abfzeit"]     = "";}
    if (isset ($_SESSION["13_abseinheit"])){   $data["13_abseinheit"]   = $_SESSION["13_abseinheit"];   unset ($_SESSION["13_abseinheit"]);  }  else { $data["13_abseinheit"]  = "";}
    if (isset ($_SESSION["14_zeichen"])){      $data["14_zeichen"]      = $_SESSION["14_zeichen"];      unset ($_SESSION["14_zeichen"]);  }     else { $data["14_zeichen"]     = "";}
    if (isset ($_SESSION["14_funktion"])){     $data["14_funktion"]     = $_SESSION["14_funktion"];     unset ($_SESSION["14_funktion"]);  }    else { $data["14_funktion"]    = "";}
    if (isset ($_SESSION["15_quitdatum"])){    $data["15_quitdatum"]    = $_SESSION["15_quitdatum"];    unset ($_SESSION["15_quitdatum"]); }    else { $data["15_quitdatum"]   = "";}
    if (isset ($_SESSION["15_quitzeichen"])){  $data["15_quitzeichen"]  = $_SESSION["15_quitzeichen"];  unset ($_SESSION["15_quitzeichen"]); }  else { $data["15_quitzeichen"] = "";}

    if (isset ($_SESSION["16_gncopy"]) &&
        preg_match ("/\\A([^_]+)_([^_]+)_([^_]+)\\z/D", (string) $_SESSION["16_gncopy"], $gncopyParts)){
      list (, $gncopyord, $gncopypos, $gncopyfkt) = $gncopyParts;
    }
	$data ["16_empf"] = "";
    for ($m=1; $m<=5; $m++){
      for ($n=1; $n<=4; $n++){
        if ( isset ( $_SESSION ["16_".$m.$n] ) ) {
           list ($ord, $pos, $fkt) = explode ("_", $_SESSION ["16_".$m.$n]);
           $data ["16_empf"] .= $empf_matrix [$m][$n]["fkt"]."_".$fkt.",";
//           echo "SESSION====".$_SESSION ["16_".$m.$n]." data=== ".$data ["16_empf"]."<br>";
           unset ($_SESSION ["16_".$m.$n]);
        }
        if (isset ($gncopypos) && ($m.$n == $gncopypos)) {
          $data ["16_empf"] .= $empf_matrix [$m][$n]["fkt"]."_".$gncopyfkt.",";
          unset ($_SESSION["16_gncopy"]);
        }
      }
    }
    if (isset ($_SESSION["17_vermerke"])){     $data["17_vermerke"]     = $_SESSION["17_vermerke"];     unset ($_SESSION["17_vermerke"]); }
    return $data;
  }


  function fileselectwindow (){
    require ("../4fcfg/config.inc.php");
    try {
      estab_csrf_require_post ($_SERVER, $_POST);
    } catch (RuntimeException $exception) {
      http_response_code (400);
      error_log ("eStab attachment CSRF validation failed: ".$exception->getMessage ());
      echo "<big><big><b>Die Upload-Anforderung ist ungültig oder abgelaufen.</b></big></big>";
      anhang_menue ();
      exit;
    }
    if (!isset($_POST["abbrechen_x"]) && isset($_POST["absenden_x"])) {
      $my_upload = new fileupload;
      $my_upload->upload_dir = rtrim ($conf_4f ["ablage_dir"], "/\\")."/";
      $my_upload->extensions = array_map (
        static fn ($extension) => ".".$extension,
        estab_attachment_allowed_extensions ()
      );
      $my_upload->max_length_filename = 100;
      $my_upload->rename_file = true;
      $my_upload->replace = false;
      $my_upload->do_filename_check = false;

      $claimed = false;
      $finalized = false;
      $full_path = null;
      $new_name = "";
      try {
        $new_name = estab_attachment_validate_reservation_name (
          is_string ($_POST ["fs_nextfilename"] ?? null) ? $_POST ["fs_nextfilename"] : "",
          $conf_4f ["hoheit"]
        );
        if (!$my_upload->claim_reservation ($new_name)) {
          throw new RuntimeException ("Die Dateinamenreservierung ist nicht mehr aktiv.");
        }
        $claimed = true;

        $upload = $_FILES ["upload"] ?? null;
        if (!is_array ($upload)
            || !isset ($upload ["tmp_name"], $upload ["name"], $upload ["error"])
            || !is_string ($upload ["tmp_name"])
            || !is_string ($upload ["name"])
            || !is_int ($upload ["error"])) {
          throw new InvalidArgumentException ("Ungültige Upload-Metadaten.");
        }
        $my_upload->the_temp_file = $upload ["tmp_name"];
        $my_upload->the_file = $upload ["name"];
        $my_upload->http_error = $upload ["error"];
        if ($my_upload->http_error !== UPLOAD_ERR_OK) {
          throw new RuntimeException ($my_upload->error_text ($my_upload->http_error));
        }
        if (!$my_upload->upload ($new_name)) {
          throw new RuntimeException ("Die Datei konnte nicht sicher hochgeladen werden.");
        }

        $full_path = $my_upload->upload_dir.$my_upload->file_copy;
        $timestamp = estab_attachment_parse_tactical_time (
          is_string ($_POST ["fs_timestamp"] ?? null) ? $_POST ["fs_timestamp"] : ""
        );
        if ($timestamp === null) {
          throw new InvalidArgumentException ("Der Zeitstempel ist ungültig.");
        }
        $digest = md5_file ($full_path);
        if (!is_string ($digest)) {
          throw new RuntimeException ("Die Dateiprüfsumme konnte nicht erstellt werden.");
        }
        $data = array (
          "reservation" => $new_name,
          "filename" => basename ($full_path),
          "org_filename" => $upload ["name"],
          "comment" => is_string ($_POST ["fs_comment"] ?? null) ? $_POST ["fs_comment"] : "",
          "time" => $timestamp,
          "md5hash" => $digest,
        );
        if (!$my_upload->save_in_db ($data)) {
          throw new RuntimeException ("Die Reservierung gehört nicht zu dieser Sitzung.");
        }
        $finalized = true;
      } catch (Throwable $exception) {
        error_log ("eStab attachment upload failed: ".$exception->getMessage ());
        echo "<big><big><b>Der Anhang konnte nicht sicher gespeichert werden.</b></big></big>";
      } finally {
        if (!$finalized && is_string ($full_path) && is_file ($full_path)) {
          $uploadRoot = rtrim ($my_upload->upload_dir, "/\\").DIRECTORY_SEPARATOR;
          if (str_starts_with ($full_path, $uploadRoot) && basename ($full_path) === $my_upload->file_copy) {
            @unlink ($full_path);
          }
        }
        if ($claimed && !$finalized) {
          try {
            $my_upload->release_reservation ($new_name);
          } catch (Throwable $exception) {
            error_log ("eStab attachment reservation release failed: ".$exception->getMessage ());
          }
        }
      }
    } else {
      file_unselect ();
    }
    unset ($_SESSION ["UPLOAD"]);
    anhang_menue ();
    exit;
  }


  switch ($_SESSION["anhang_menue"]){

    case 100: // Auswahlmenue
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big> 100 --> Auswahlmenue</big></big><br>";  }        
        store_formdata();
        anhang_menue ();
        $_SESSION["anhang_menue"] = 110;
    break;

    case 110: // UPLOAD Menue
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big> 110 --> UPLOADMENUE</big></big><br>";  }        

        if ( isset ($_GET ["ah_upload_x"])){
          fileselect ();
        }

        if ( isset ($_GET ["ah_abbrechen_x"])){
          unset ($_SESSION["anhang_menue"]);
          unset ($_SESSION["anhang"]);
          $_SESSION["anhang_result"] = "abbrechen" ;
          header("Location: ".$conf_4f ["MainURL"]);
        }

        if ( (isset ($_POST["absenden_x"] )) OR
             (isset ($_POST["abbrechen_x"]))){

          fileselectwindow ();
        }
    break;

    case 999: // ??
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big> 999 --> UPLOAD</big></big><br>";  }
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

    default:
      echo "<big><big><big>Kein MenÃ¼punkt !!!</big></big></big><br>" ;
  }



if ( debug == true ){
  echo "<br><br>\n";
  echo "------ anhang.php 985------";
  echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
  // echo "SERVER  ="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
  echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
}

?>
