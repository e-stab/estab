<?php

if (!defined ("debug")) { define ("debug", false); }
if (ob_get_level () === 0) { ob_start (); }
/******************************************************************************\
technisches Betriebsbuch

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
  function __construct (){
    $this->tbb_liste ();
  }

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
  var $sqlquery;
  var $result = "";
  var $resultcount = 0;


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
  function speichen_tbbtitel ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_title (is_array ($daten) ? $daten : array ());
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültige Einsatzdaten");
    }
    $table = $conf_4f_tbl ["prefix"]."tbbtitel";
    estab_logbook_create_title_table ($conf_4f_db, $table);
    estab_logbook_insert_title ($conf_4f_db, $table, $validation ["data"]);
  }


/*****************************************************************************\

\*****************************************************************************/
  function create_tbbtitel_tbl(){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    estab_logbook_create_title_table (
      $conf_4f_db,
      $conf_4f_tbl ["prefix"]."tbbtitel"
    );
  }


/*****************************************************************************\

\*****************************************************************************/
  function tbb_tableexist () {
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $eq = estab_logbook_table_exists (
      $conf_4f_db,
      $conf_4f_tbl ["prefix"]."tbbtitel"
    );
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

    $query = "SELECT * FROM ".estab_auth_table ($conf_4f_tbl ["prefix"]."tbbtitel")
      ." ORDER BY `lfd-nr` ASC LIMIT 1";
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
    echo "  <meta content=\"text/html; charset=UTF-8\" http-equiv=\"content-type\">\n";
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
    $action = estab_auth_html (estab_public_root ()."fmtbb/tbb.php");
    echo "<form action=\"".$action."\" method=\"GET\" >\n";
    echo "<!-- Formularelemente und andere Elemente innerhalb des Formulars -->\n";
    echo "<!-- tbb_menue -->\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\">\n";
    echo "<tr>\n";
    echo "<td>";
//    echo "<input type=\"image\" name=\"tbb_eintrag\" value=\"tbb_eintrag\" src=\"".$conf_design_path."/logbook_entry.gif\">\n";
	echo "<input type=\"image\" name=\"tbb_eintrag\" src=\"../4fach/button.php?type=menue&m_text=TBB-Eintrag&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"TBb-Eintrag\">\n";
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
    $query = "SELECT * FROM ".estab_auth_table ($conf_tbl ["tbb"])
      ." ORDER BY `tbb_lfd-nr` DESC";
  if (debug == true){echo "tbb_getdate-->query=".$query; echo "<br>";}
    $result = $this->query_table ($query);
  if (debug == true){echo "tbb_getdate-->"; var_dump($result);echo "<br>";}
    return $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_tbb_eintrag ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_entry (is_array ($daten) ? $daten : array ());
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültiger TBB-Eintrag");
    }
    estab_logbook_insert_entry (
      $conf_4f_db,
      $conf_tbl ["tbb"],
      "tbb",
      $validation ["data"],
      array (
        "funktion" => (string) $this->tbb_funktion,
        "kuerzel" => (string) $this->tbb_kuerzel,
        "benutzer" => (string) $this->tbb_benutzer,
      )
    );
  }

/*****************************************************************************\

\*****************************************************************************/
var $lfd ;
var $task ;

  function tbb_eintragsmenue ($data) {
    include ("../4fcfg/config.inc.php");
    $action = estab_auth_html (estab_public_root ()."fmtbb/tbb.php");

    echo "<big><big>Eintrag ins \n";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">T</span>\n";
    echo "echnisches";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">B</span>\n";
    echo "etriebs";
    echo "<span style=\"color: rgb(255, 0, 0); font-weight: bold;\">b</span>\n";
    echo "uch<br>\n";
    echo "<br>\n";
    echo "</big></big>\n";
    echo "<form method=\"POST\" action=\"".$action."\" name=\"tbbeintrag\">\n";
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
  function tbb_einsatzdaten (){
    echo "<table width=\"500px\" style=\"text-align: left;\" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
      echo "<td>Einsatz</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".estab_auth_html ($this->tbb_art)."</td>" ;
    echo "</tr>";
    echo "<tr>\n";
      echo "<td>Ort</td>";
      echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">".estab_auth_html ($this->tbb_ort)."</td>" ;
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
    $action = estab_auth_html (estab_public_root ()."fmtbb/tbb.php");
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
    if ($this->tbb_authorized){
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
        echo (int) $line ["tbb_lfd-nr"];
        echo "</td>\n";
        echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\">";
        echo $this->konv_datetime_taktime ($line ["tbb_time"]);
        echo "</td>\n";
        if ( $line ["tbb_aktion"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo estab_auth_html ($line ["tbb_aktion"]);
        } else {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
        }
        echo "</td>\n";
        if ( $line ["tbb_bemerk"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo estab_auth_html ($line ["tbb_bemerk"]);
        } else {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
        }

        if ( $line ["tbb_kuerzel"] != "" ) {
          echo "<td style=\" outline:1px solid black; font-size:18px; font-weight:900;\" >";
          echo estab_auth_html ($line ["tbb_kuerzel"]);
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
      echo "<p>Noch keine TBB-Einträge vorhanden.</p>\n";
    }
  }

} // class tbb_liste
/**************************************************************************************************************************/

if (session_status () !== PHP_SESSION_ACTIVE) {
  session_start ();
}
require_once __DIR__ . "/../app/logbook.php";
estab_auth_require_session ($_SESSION);

$identity = estab_auth_session_identity ($_SESSION);
if (!is_array ($identity)) {
  estab_logbook_abort (403, "Anmeldung erforderlich.");
}

$berechtigt = strcasecmp ($identity ["funktion"], "A/W") === 0
  && strcasecmp ($identity ["rolle"], "Fernmelder") === 0;

$requestMethod = isset ($_SERVER ["REQUEST_METHOD"]) && is_string ($_SERVER ["REQUEST_METHOD"])
  ? strtoupper ($_SERVER ["REQUEST_METHOD"])
  : "GET";
if (!in_array ($requestMethod, array ("GET", "POST"), true)) {
  header ("Allow: GET, POST");
  estab_logbook_abort (405, "Nicht unterstützte Anfragemethode.");
}

try {
  $tbbobj = new tbb_liste;
} catch (Throwable $exception) {
  error_log ("TBB initialization failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das technische Betriebsbuch ist vorübergehend nicht verfügbar.");
}
$tbbobj->tbb_authorized = $berechtigt;
$tbbobj->tbb_funktion = $identity ["funktion"];
$tbbobj->tbb_kuerzel = $identity ["kuerzel"];
$tbbobj->tbb_benutzer = $identity ["benutzer"];

if ($requestMethod === "POST") {
  if (!$berechtigt) {
    estab_logbook_abort (403, "Nur die Fernmeldefunktion A/W darf TBB-Einträge schreiben.");
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
      $tbbobj->speichen_tbbtitel ($validation ["data"]);
    } elseif ($action === "save_entry") {
      if (!$tbbobj->tbb_titel_gesetzt) {
        estab_logbook_abort (409, "Vor dem ersten TBB-Eintrag müssen Einsatzdaten erfasst werden.");
      }
      $validation = estab_logbook_validate_entry ($_POST);
      if (!$validation ["valid"]) {
        estab_logbook_abort (422, "Der TBB-Eintrag ist leer oder überschreitet 10000 Zeichen.");
      }
      $tbbobj->speichen_tbb_eintrag ($validation ["data"]);
    } else {
      estab_logbook_abort (400, "Unbekannte TBB-Aktion.");
    }
  } catch (Throwable $exception) {
    error_log ("TBB write failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Der TBB-Eintrag konnte nicht gespeichert werden.");
  }

  estab_logbook_redirect (estab_public_root ()."fmtbb/tbb.php");
}

if (!$tbbobj->tbb_titel_gesetzt) {
  try {
    $tbbobj->create_tbbtitel_tbl ();
  } catch (Throwable $exception) {
    error_log ("TBB title table creation failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Das technische Betriebsbuch konnte nicht vorbereitet werden.");
  }
}

$tbbobj->tbb_pre_html ();
$tbbobj->tbb_ueberschrift ();

if (!$tbbobj->tbb_titel_gesetzt) {
  if ($berechtigt) {
    $tbbobj->inputeinsatzstammdaten ();
  } else {
    echo "<p>Die Einsatzdaten wurden noch nicht durch A/W erfasst.</p>\n";
  }
} else {
  $tbbobj->tbb_einsatzdaten ();
  $entryFormRequested = isset ($_GET ["tbb_eintrag_x"])
    || (
      isset ($_GET ["tbb_menue"])
      && is_string ($_GET ["tbb_menue"])
      && $_GET ["tbb_menue"] === "eintrag"
    );
  if ($berechtigt && $entryFormRequested) {
    $tbbobj->tbb_eintragsmenue ("");
  } elseif ($berechtigt) {
    $tbbobj->tbb_menue ();
  }
  $tbbobj->printlist ($tbbobj->tbb_getdate ());
}

$tbbobj->tbb_post_html ();
?>
