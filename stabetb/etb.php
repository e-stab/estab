<?php

if (!defined ("debug")) { define ("debug", false); }
if (ob_get_level () === 0) { ob_start (); }
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
  function __construct (){
    $this->etb_liste ();
  }

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
  var $sqlquery;
  var $result = "";
  var $resultcount = 0;


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
  function query_table ($query){
    $this->result = array ();
    $this->sqlquery = $query ;

    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");
    mysql_query('SET NAMES utf8mb4');
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
    return ($this->resultcount > 0 ? $this->result : "");
  } // function read_table


/*****************************************************************************\

\*****************************************************************************/
  function speichen_etbtitel ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_title (is_array ($daten) ? $daten : array ());
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültige Einsatzdaten");
    }
    $table = $conf_4f_tbl ["prefix"]."etbtitel";
    estab_logbook_create_title_table ($conf_4f_db, $table);
    estab_logbook_insert_title ($conf_4f_db, $table, $validation ["data"]);
  }


/*****************************************************************************\

\*****************************************************************************/
  function create_etbtitel_tbl(){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    estab_logbook_create_title_table (
      $conf_4f_db,
      $conf_4f_tbl ["prefix"]."etbtitel"
    );
  }


/*****************************************************************************\

\*****************************************************************************/
  function etb_tableexist () {
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $eq = estab_logbook_table_exists (
      $conf_4f_db,
      $conf_4f_tbl ["prefix"]."etbtitel"
    );
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

    $query = "SELECT * FROM ".estab_auth_table ($conf_4f_tbl ["prefix"]."etbtitel")
      ." ORDER BY `lfd-nr` ASC LIMIT 1";
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
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "  <meta content=\"text/html; charset=UTF-8\" http-equiv=\"content-type\">\n";
    if (!$this->etb_authorized) {
      echo "<meta http-equiv=\"refresh\" content=\"10\">\n";
    }
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
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));
    echo "<form action=\"".$action."\" method=\"GET\" >\n";
    echo "<!-- Formularelemente und andere Elemente innerhalb des Formulars -->\n";
    echo "<!-- etb_menue -->\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\">\n";
    echo "<tr>\n";
    echo "<td>";
    echo "<input type=\"image\" name=\"etb_eintrag\" value=\"etb_eintrag\" src=\"".$conf_design_path."/logbook_entry.gif\">\n";
    echo "</td>";
    echo "</tr>";
    echo "</table>";
//    echo "<img src=\"http://localhost:80/kats/4fach/design/HS/timer.gif\">";
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
    $query = "SELECT * FROM ".estab_auth_table ($conf_tbl ["etb"])
      ." ORDER BY `etb_lfd-nr` DESC";
    $result = $this->query_table ($query);
  if (debug == true){echo "etb_getdate-->"; var_dump($result);echo "<br>";}
    return $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_etb_eintrag ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_entry (is_array ($daten) ? $daten : array ());
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültiger ETB-Eintrag");
    }
    estab_logbook_insert_entry (
      $conf_4f_db,
      $conf_tbl ["etb"],
      "etb",
      $validation ["data"],
      array (
        "funktion" => (string) $this->etb_funktion,
        "kuerzel" => (string) $this->etb_kuerzel,
        "benutzer" => (string) $this->etb_benutzer,
      )
    );
  }

/*****************************************************************************\

\*****************************************************************************/
var $lfd ;
var $task;

  function etb_eintragsmenue ($data) {
    include ("../4fcfg/config.inc.php");
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));

    echo "<big><big>Eintrag ins \n";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">E</span>\n";
    echo "insatz";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">t</span>\n";
    echo "age";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">b</span>\n";
    echo "uch<br>\n";
    echo "<br>\n";
    echo "</big></big>\n";
    echo "<form method=\"POST\" action=\"".$action."\" name=\"etbeintrag\">\n";
    echo estab_csrf_field ()."\n";
    echo "<input type=\"hidden\" name=\"logbook_action\" value=\"save_entry\">\n";
    echo "<table style=\"text-align: left;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td><b>Darstellung der Ereignisse</b><br>\n";
    echo "<textarea style=\"font-size:18px; font-weight:900;\" tabindex=\"1\" cols=\"80\" rows=\"4\" maxlength=\"10000\" required name=\"event\"></textarea></td>\n";
    echo "</tr>\n";
    echo "<tr>";
    echo "<td><b>Bemerkung</b><br>";
    echo "<textarea style=\"font-size:18px; font-weight:900;\" tabindex=\"2\" cols=\"80\" rows=\"4\" maxlength=\"10000\" name=\"comment\"></textarea></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "<table style=\"text-align: left;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" alt=\"absenden\" tabindex=\"3\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
    echo "<td bgcolor=$color_button_nok><a href=\"".$action."\"><img alt=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\"></a></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    echo "</form>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_einsatzdaten (){
    echo "<table width=\"500px\" style=\"text-align: left;\" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
      echo "<td>Einsatz</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".estab_auth_html ($this->etb_art)."</td>" ;
    echo "</tr>";
    echo "<tr>\n";
      echo "<td>Ort</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".estab_auth_html ($this->etb_ort)."</td>" ;
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
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));
    echo "<big><big><big><b>Einsatzdaten erfassen</b></big></big></big>\n";
    echo "<!-- einsatzdatenmenue -->";
    echo "<form method=\"POST\" action=\"".$action."\" name=\"Einsatzdaten\">\n";
    echo estab_csrf_field ()."\n";
    echo "<input type=\"hidden\" name=\"logbook_action\" value=\"save_title\">\n";
    echo "<table style=\"text-align: left; width: 603px; height: 64px;\" border=\"1\" cellpadding=\"2\" cellspacing=\"2\">";
    echo "<tbody>";
    echo "<tr>";
    echo "<td style=\"width: 100px;\">Einsatz</td>";
    echo "<td style=\"width: 506px;\">";
    echo "<input style=\"font-size:18px; font-weight:900;\" value=\"\" maxlength=\"255\" size=\"60\" required name=\"einsatz\">";
    echo "</td>";
    echo "</tr>";
    echo "<tr>";
    echo "<td style=\"width: 100px;\">Ort</td>";
    echo "<td style=\"width: 506px;\">";
    echo "<input style=\"font-size:18px; font-weight:900;\" value=\"\" maxlength=\"255\" size=\"60\" required name=\"ort\"></td>";
    echo "</tr>";
    echo "</tbody>";
    echo "</table>";
    if ($this->etb_authorized){
      echo "<table style=\"text-align: left;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
      echo "<tbody>\n";
      echo "<tr>\n";
      echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" alt=\"absenden\" tabindex=\"3\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
      echo "<td bgcolor=$color_button_nok><a href=\"".$action."\"><img alt=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\"></a></td>\n";
      echo "</tr>\n";
      echo "</tbody>\n";
      echo "</table>\n";
    }
    echo "</form>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function printlist ($daten){
    // Schreibe die Liste
    if ( $daten != "" ) {

      echo "<table style=\"border-width:medium; border-color:#66CC66; border-style:solid; padding:1px;\" border=\"1\" cellpadding=\"5\" cellspacing=\"1\" bordercolor=black>\n";
      echo "<tbody>\n";

      $this->headline ();

      foreach ( $daten as $line ){
//        var_dump ($line); echo "<br>";

        echo "<tr>";
        echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">\n";
        echo (int) $line ["etb_lfd-nr"];
        echo "</td>\n";
        echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">";
        echo $this->konv_datetime_taktime ($line ["etb_time"]);
        echo "</td>\n";
        if ( $line ["etb_aktion"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo estab_auth_html ($line ["etb_aktion"]);
        } else {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
        }
        echo "</td>\n";
        if ( $line ["etb_bemerk"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo estab_auth_html ($line ["etb_bemerk"]);
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
      echo "<p>Noch keine ETB-Einträge vorhanden.</p>\n";
    }
  }

} // class etb_liste

/***************************************************************************************************************************/


if (session_status () !== PHP_SESSION_ACTIVE) {
  session_start ();
}
require_once __DIR__ . "/../app/logbook.php";
require_once __DIR__ . "/../app/session_ui.php";
estab_auth_require_session ($_SESSION);

include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");

$identity = estab_auth_session_identity ($_SESSION);
if (!is_array ($identity)) {
  estab_logbook_abort (403, "Anmeldung erforderlich.");
}
estab_session_ui_start ($_SESSION);

try {
  // Older databases used "1"; current matrices store the red-copy flag as "t".
  $redcopyFunction = estab_logbook_redcopy_function (
    $conf_4f_db,
    $conf_4f_tbl ["empfmtx"]
  );
} catch (Throwable $exception) {
  error_log ("ETB authorization lookup failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das Einsatztagebuch ist vorübergehend nicht verfügbar.");
}

$berechtigt = is_string ($redcopyFunction)
  && strcasecmp ($identity ["funktion"], $redcopyFunction) === 0;

$requestMethod = isset ($_SERVER ["REQUEST_METHOD"]) && is_string ($_SERVER ["REQUEST_METHOD"])
  ? strtoupper ($_SERVER ["REQUEST_METHOD"])
  : "GET";
if (!in_array ($requestMethod, array ("GET", "POST"), true)) {
  header ("Allow: GET, POST");
  estab_logbook_abort (405, "Nicht unterstützte Anfragemethode.");
}

try {
  $etbobj = new etb_liste;
} catch (Throwable $exception) {
  error_log ("ETB initialization failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das Einsatztagebuch ist vorübergehend nicht verfügbar.");
}
$etbobj->etb_authorized = $berechtigt;
$etbobj->etb_funktion = $identity ["funktion"];
$etbobj->etb_kuerzel = $identity ["kuerzel"];
$etbobj->etb_benutzer = $identity ["benutzer"];

if ($requestMethod === "POST") {
  if (!$berechtigt) {
    estab_logbook_abort (403, "Nur die Red-Copy-Funktion darf ETB-Einträge schreiben.");
  }
  estab_logbook_require_csrf ($_SERVER, $_POST);
  $action = isset ($_POST ["logbook_action"]) && is_string ($_POST ["logbook_action"])
    ? $_POST ["logbook_action"]
    : "";

  try {
    if ($action === "save_title") {
      $validation = estab_logbook_validate_title ($_POST);
      if (!$validation ["valid"]) {
        estab_logbook_abort (422, "Einsatz und Ort sind erforderlich und auf 255 Zeichen begrenzt.");
      }
      $etbobj->speichen_etbtitel ($validation ["data"]);
    } elseif ($action === "save_entry") {
      if (!$etbobj->etb_titel_gesetzt) {
        estab_logbook_abort (409, "Vor dem ersten ETB-Eintrag müssen Einsatzdaten erfasst werden.");
      }
      $validation = estab_logbook_validate_entry ($_POST);
      if (!$validation ["valid"]) {
        estab_logbook_abort (422, "Der ETB-Eintrag ist leer oder überschreitet 10000 Zeichen.");
      }
      $etbobj->speichen_etb_eintrag ($validation ["data"]);
    } else {
      estab_logbook_abort (400, "Unbekannte ETB-Aktion.");
    }
  } catch (Throwable $exception) {
    error_log ("ETB write failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Der ETB-Eintrag konnte nicht gespeichert werden.");
  }

  estab_logbook_redirect (estab_application_url ("stabetb/etb.php"));
}

if (!$etbobj->etb_titel_gesetzt) {
  try {
    $etbobj->create_etbtitel_tbl ();
  } catch (Throwable $exception) {
    error_log ("ETB title table creation failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Das Einsatztagebuch konnte nicht vorbereitet werden.");
  }
}

$etbobj->etb_pre_html ();
$etbobj->etb_ueberschrift ();

if (!$etbobj->etb_titel_gesetzt) {
  if ($berechtigt) {
    $etbobj->inputeinsatzstammdaten ();
  } else {
    echo "<p><b>Einsatzdaten erfassen:</b> Die Einsatzdaten wurden noch nicht "
      ."durch die Red-Copy-Funktion erfasst.</p>\n";
  }
} else {
  $etbobj->etb_einsatzdaten ();
  $entryFormRequested = isset ($_GET ["etb_eintrag_x"])
    || (
      isset ($_GET ["etb_menue"])
      && is_string ($_GET ["etb_menue"])
      && $_GET ["etb_menue"] === "eintrag"
    );
  if ($berechtigt && $entryFormRequested) {
    $etbobj->etb_eintragsmenue ("");
  } elseif ($berechtigt) {
    $etbobj->etb_menue ();
  }
  $etbobj->printlist ($etbobj->etb_getdate ());
}

$etbobj->etb_post_html ();
?>
