<?php

session_start();

define ("debug",false);

// umdefinieren der Konstanten - nur in PHP 4
define("FATAL", E_USER_ERROR);
define("ERROR", E_USER_WARNING);
define("WARNING", E_USER_NOTICE);

// die Stufe für dieses Skript einstellen
error_reporting(E_ALL); 

// Fehlerbehandlungsfunktion
function myErrorHandler($errno, $errstr, $errfile, $errline)
{
  switch ($errno) {
  case FATAL:
    echo "<b>FATAL</b> [$errno] $errstr<br />\n";
    echo "  Fatal error in line $errline of file $errfile";
    echo ", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br />\n";
    echo "Aborting...<br />\n";
    exit(1);
    break;
  case ERROR:
    echo "<b>ERROR</b> [$errno] $errstr<br />\n";
    break;
  case WARNING:
    echo "<b>WARNING</b> [$errno] $errstr<br />\n";
    break;
  default:
    break;
  }
}


// Funktion zum Test der Fehlerbehandlung
function scale_by_log($vect, $scale)
{
  if (!is_numeric($scale) || $scale <= 0) {
    trigger_error("log(x) for x <= 0 is undefined, you used: scale = $scale",
      FATAL);
  }

  if (!is_array($vect)) {
    trigger_error("Incorrect input vector, array of values expected", ERROR);
    return null;
  }

  for ($i=0; $i<count($vect); $i++) {
    if (!is_numeric($vect[$i]))
      trigger_error("Value at position $i is not a number, using 0 (zero)",
        WARNING);
    $temp[$i] = log($scale) * $vect[$i];
  }
  return $temp;
}


function outerrormsg ($errno, $errtxt) {
   switch ($errno){
      case 0 :
         echo "OK";
      break;
      case 1045 :
         echo "Benutzer oder Passwort zur Anmeldung am SQL-Server falsch.";
      break;
       case 1046 :
         echo "Es ist keine Datenbank ausgewählt.";
      break;
      case 1049 :
         echo "<p>Datenbank nicht vorhanden <a href=\"../4fadm/create_db.php\">Datenbank anlegen</a></p>";
      break;
      case 1086 :
         echo "Datei ist schon vorhanden.";
      break;
      case 2005 :
         echo "SQL-Server nicht bekannt.";
      break;
      default:
         echo "Fehlernummer = ".$errno."<br>Fehlertext = ".$errtxt;
      break;
    }
}


// auf die benutzerdefinierte Fehlerbehandlung umstellen
$old_error_handler = set_error_handler("myErrorHandler");

  if ( debug == true ){
    echo "<br><br>\n";
    echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
    echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
    echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
    echo "SESSION="; var_dump ($_SESSION); echo "#<br>\n";
  }

/*
error_reporting() Level Konstanten und Bit-Werte     value      constant
1       E_ERROR
2       E_WARNING
4       E_PARSE
8       E_NOTICE
16      E_CORE_ERROR
32      E_CORE_WARNING
64      E_COMPILE_ERROR
128     E_COMPILE_WARNING
256     E_USER_ERROR
512     E_USER_WARNING
1024    E_USER_NOTICE
6143    E_ALL
2048    E_STRICT
4096    E_RECOVERABLE_ERROR
*/


/*******************************************************************************\
|*******************************************************************************|
                            CLASS make_dbconf
|*******************************************************************************|
\*******************************************************************************/
class make_dbconf {

  var $preconf ;

  function make_dbconf ($conf_4f_db, $conf_4f_tbl, $conf_tbl, $conf_4f){
    $this->preconf ['serveradr'] = $conf_4f_db  ["server"] ;
    $this->preconf ['db_user']   = $conf_4f_db  ["user"] ;
    $this->preconf ['db_userpw'] = $conf_4f_db  ["password"] ;
    $this->preconf ['db_dbname'] = $conf_4f_db  ["datenbank"] ;
    $this->preconf ['tbl_pre']   = $conf_4f_tbl ["prefix"] ;
    $this->preconf ['anschrift'] = $conf_4f     ["anschrift"] ;
    $this->preconf ['hoheit']    = $conf_4f     ["hoheit"] ;
  }

/****************************************************************************\

\****************************************************************************/
  function write_dbfkt_file ($values){

  include ("../4fcfg/config.inc.php");

    $prefile = "<"."?"."php \r\n".
    "/"."******************************************************************************\ \r\n".
    "              Definitionen fuer den Datenbankzugriff                              \r\n".
    "\******************************************************************************"."/ \r\n";

    $fileline [0]  = "$"."conf_4f_db   [\"server\"]        = \"".$values ['serveradr']."\"; \r\n";
    $fileline [1]  = "$"."conf_4f_db   [\"user\"]          = \"".$values ['db_user']."\"; \r\n";
    $fileline [2]  = "$"."conf_4f_db   [\"password\"]      = \"".$values ['db_userpw']."\"; \r\n";
    $fileline [3]  = "$"."conf_4f_tbl  [\"prefix\"]        = \"".$values ['tbl_pre']."\" ; \r\n";
    $fileline [4]  = "$"."conf_4f_tbl  [\"benutzer\"]      = \"".$values ['tbl_pre']."benutzer\"; \r\n";
    $fileline [5]  = "$"."conf_4f_tbl  [\"masterkatego\"]  = \"".$values ['tbl_pre']."masterkatego\"; \r\n";
    $fileline [6]  = "$"."conf_4f_tbl  [\"masterkategolk\"]= \"".$values ['tbl_pre']."masterkategolink\"; \r\n";
    $fileline [7]  = "$"."conf_4f_tbl  [\"nachrichten\"]   = \"".$values ['tbl_pre']."nachrichten\"; \r\n";
    $fileline [8]  = "$"."conf_4f_tbl  [\"empfmtx\"]       = \"".$values ['tbl_pre']."empfmtx\"; \r\n";
    $fileline [9]  = "$"."conf_4f_tbl  [\"protokoll\"]     = \"".$values ['tbl_pre']."protokoll\"; \r\n";
    $fileline [10] = "$"."conf_4f_tbl  [\"anhang\"]        = \"".$values ['tbl_pre']."anhang\"; \r\n";
    $fileline [11] = "$"."conf_4f_tbl  [\"usrtblprefix\"]  = \"usr_\"; \r\n";
    $fileline [12] = "$"."conf_tbl     [\"bhp50\"]         = \"".$values ['tbl_pre']."bhp50\"; \r\n";
    $fileline [13] = "$"."conf_tbl     [\"komplan\"]       = \"".$values ['tbl_pre']."komplan\"; \r\n";
    $fileline [14] = "$"."conf_tbl     [\"etb\"]           = \"".$values ['tbl_pre']."etb\"; \r\n";
    $fileline [15] = "$"."conf_tbl     [\"ubb\"]           = \"".$values ['tbl_pre']."ubb\"; \r\n";



    $postfile = "\r\r\n\r\n\n?>";

    $filename =  $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fcfg/dbcfg.inc.php";

    $fhndl = fopen ( $filename, "w+");

    fwrite ($fhndl, $prefile);

    for ($i=0; $i <= count ($fileline); $i++){
      fwrite ($fhndl, $fileline [$i]);
    }

    fwrite ($fhndl, $postfile);
    fclose ($fhndl);
  }


/******************************************************************************\

\******************************************************************************/
  function menue () {
    include ("../4fcfg/config.inc.php");
    $dbmenue = array (
        0 => array ('text' => "<b>Hostname oder IP-Adresse</b><br>des Datenbankservers<br><i>localhost</i> :",
                    'feld' => "name=\"serveradr\" type=\"text\" size=\"30\" maxlength=\"30\" value=\"".$this->preconf ['serveradr']."\""
                    ),
        1 => array ('text' => "<b>Datenbankbenutzer</b><br> :",
                    'feld' => "name=\"db_user\" type=\"text\" size=\"30\" maxlength=\"30\" value=\"".$this->preconf ['db_user']."\""
                    ),
        2 => array ('text' => "<b>Passwort</b> :",
                    'feld' => "name=\"db_userpw\" type=\"text\" size=\"30\" maxlength=\"30\""
                    ),
        3 => array ('text' => "<b>Tabellenpr&auml;fix</b><br>Zeichenfolge die den<br>Tabellennamen vorangestellt wird :",
                    'feld' => "name=\"tbl_pre\" type=\"text\" size=\"30\" maxlength=\"30\" value=\"".$this->preconf ['tbl_pre']."\""
                    )
      );

    $vordruckmenue = array (
        0 => array ('text' => "<b>Anschrift</b><br>Text der bei Eing&auml;ngen<br>im Anschriftfeld eingetragen <br>werden soll<br><i>EL KR HS</i> :",
                    'feld' => "name=\"anschrift\" type=\"text\" size=\"10\" maxlength=\"30\" value=\"".$this->preconf ['anschrift']."\""
                    ),
        1 => array ('text' => "<b>Hoheitskennzeichen</b><br>:",
                    'feld' => "name=\"hoheit\" type=\"text\" size=\"6\" maxlength=\"6\" value=\"".$this->preconf ['hoheit']."\""
                    )
      );
    echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\" \"http://www.w3.org/TR/html4/loose.dtd\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<title>Konfiguration erzeugen</title>\n";
    echo "</head>\n";
    echo "<body>\n";
    echo "<h1>Datenbankeinstellungen!</h1>\n";
    echo "<form action=\"".$_SERVER['PHP_SELF']."\" method=\"get\">\n";
    echo "<fieldset>\n";
    echo "<legend>Datenbankparameter</legend>\n";
    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    $i = 0;
    foreach ($dbmenue as $menueitem){
      echo "<tr>\n";
      echo "<td align=\"right\">".$menueitem ['text']."</td>\n";
      echo "<td><input ".$menueitem ['feld']."></td>\n";
      echo "</tr>\n";
      $i++;
    }
    echo "</table>\n";
    echo "</fieldset>\n";

    echo "<fieldset>\n";
    echo "<legend>Aktion:</legend>\n";
    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    echo "<tr>\n";
    echo "<input type=\"hidden\" name=\"task\" value=\"datenbank\">\n";
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
    echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\"></td>\n";
    echo "</tr>\n";
    echo "</fieldset>\n";
    echo "</table>\n";
    echo "</form>\n";
    echo "</body>\n";
    echo "</html>\n";
  }


} //class  make_dbconf


/*******************************************************************************\
|*******************************************************************************|
                                 CLASS make_econf
|*******************************************************************************|
\*******************************************************************************/
class make_econf {

  var $preconf ;

  function make_econf ($conf_4f_db, $conf_4f_tbl, $conf_tbl, $conf_4f){
    $this->preconf ['db_dbname'] = $conf_4f_db  ["datenbank"] ;
    $this->preconf ['anschrift'] = $conf_4f     ["anschrift"] ;
    $this->preconf ['hoheit']    = $conf_4f     ["hoheit"] ;
  }

/****************************************************************************\

\****************************************************************************/
  function write_efkt_file ($values){

  include ("../4fcfg/config.inc.php");

    $prefile = "<"."?"."php \r\n".
    "/"."******************************************************************************\ \r\n".
    "              Definitionen fuer den Einsatz                                        \r\n".
    "\******************************************************************************"."/ \r\n";

    $fileline [1]  = "$"."conf_4f_db [\"datenbank\"]     = \"".$values ['db_dbname']."\"; \r\n";

    $fileline [2] = "$"."conf_4f     [\"anschrift\"]    = \"".$values ['anschrift']."\"; \r\n";

    $fileline [3] = "$"."conf_4f     [\"hoheit\"]       = \"".$values ['hoheit']."\"; \r\n";

    $postfile = "\r\r\n\r\n\n?>";

    $filename =  $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fcfg/e_cfg.inc.php";

    $fhndl = fopen ( $filename, "w+");

    fwrite ($fhndl, $prefile);

    for ($i=0; $i <= count ($fileline); $i++){
      fwrite ($fhndl, $fileline [$i]);
    }

    fwrite ($fhndl, $postfile);
    fclose ($fhndl);
  }


/******************************************************************************\

\******************************************************************************/
  function menue () {
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fach/db_operation.php");
    echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\" \"http://www.w3.org/TR/html4/loose.dtd\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<title>Konfiguration erzeugen</title>\n";
    echo "</head>\n";
    echo "<body>\n";
    echo "<form action=\"".$_SERVER['PHP_SELF']."\" method=\"get\">\n";
    echo "<fieldset>\n";
    echo "<legend>Einsatzdaten</legend>\n";


    $db = mysql_connect($conf_4f_db ["server"],$conf_4f_db ["user"], $conf_4f_db ["password"]);

    $db_con_is_ok = mysql_ping  ($db);

    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";

    echo "<tr>\n";
    echo "<td align=\"right\"><b>Einsatzname/<br>Datenbankname</b> :</td>\n";
    echo "<td>";
    if (!$db_con_is_ok) {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
    } else {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
    }
    echo "</td>";

    echo "<td><input style=\"font-size:18px; font-weight:900;\" cols=\"40\" ".
         "rows=\"2\" name=\"db_dbname\" type=\"text\" size=\"30\"".
         " maxlength=\"30\" value=\"".$this->preconf ['db_dbname']."\"></td>\n";

    echo "<td>";
    echo "<p><b>Keine Sonderzeichen oder Umlaute</b><br>Beispiel: <b>\"e_15jun2006\"</b></p>";
    if (!$db_con_is_ok) {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\">".
           " Es besteht keine Verbindung zur Datenbank.<br>Bitte unter \"Datenbankparameter eingeben\" die Parameter prüfen</p>";
    } else {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\">".
           "Verbindung zur Datenbank ist in Ordnung.<br>Die Datenbank kann angelegt werden.</p>";
    }
    echo "</td>";

    echo "</tr>\n";

    echo "</table>\n";
    echo "</fieldset>\n";

    echo "<fieldset>\n";
    echo "<legend>Vordruckparameter</legend>\n";
    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    $vordruckmenue = array (
        0 => array ('text' => "<b>Anschrift :</b>",
                    'feld' => "name=\"anschrift\" type=\"text\" size=\"10\" maxlength=\"30\" value=\"".$this->preconf ['anschrift']."\"",
                    'com'  => "Text der automatisch bei Eing&auml;ngen im Anschriftfeld eingetragen <br>werden soll<br><i>EL KR HS</i> "
                    ),
        1 => array ('text' => "<b>Hoheitskennzeichen</b><br>:",
                    'feld' => "name=\"hoheit\" type=\"text\" size=\"6\" maxlength=\"6\" value=\"".$this->preconf ['hoheit']."\"",
                    'com'  => "Wird als Pr&auml;fix für die Anh&auml;nge ben&ouml;tigt."
                    )
      );

        $i = 0;
    foreach ($vordruckmenue as $menueitem){
      echo "<tr>\n";
      echo "<td align=\"right\">".$menueitem ['text']."</td>\n";
      echo "<td><input style=\"font-size:18px; font-weight:900;\" cols=\"40\" ".$menueitem ['feld']."></td>\n";
      echo "<td align=\"left\">".$menueitem ['com']."</td>\n";
      echo "</tr>\n";
      $i++;
    }

    echo "</table>\n";
    echo "</fieldset>\n";

    echo "<fieldset>\n";
    echo "<legend>Aktion:</legend>\n";
    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    echo "<tr>\n";
    echo "<input type=\"hidden\" name=\"task\" value=\"einsatz_neu\">\n";
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
    echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\"></td>\n";
    echo "</tr>\n";
    echo "</fieldset>\n";
    echo "</table>\n";
    echo "</form>\n";
    echo "</body>\n";
    echo "</html>\n";
  }



  function schliesse_einsatz (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fach/db_operation.php");

    echo "<table border=\"1\" cellspacing=\"2\" cellpadding=\"5\">";
    echo "<tbody>";
    echo "<tr>";
    echo "<td>";
    echo "<big>Verbindungstest: Datenbankserver</big>";
    echo "</td><td>";
      $db_hndl = mysql_connect ( $conf_4f_db ["server"] ,
                                 $conf_4f_db ["user"] ,
                                 $conf_4f_db ["password"] );

      $db_errno  = mysql_errno ();
      $db_errtxt = mysql_error ();
      if ($db_errno != 0) {
        echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
      } else {
        echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
      }
    echo "</td>";
    echo "<td>";
    echo "<big><b>";
      outerrormsg ($db_errno, $db_errtxt);
    echo "</b></big>";
    echo "</td>";
    echo "</tr>";

    // als erstes holen wir uns eine Liste aller Tabellen

    echo "<tr>";
    echo "<td>";
    echo "<big>Auslesen der Tabellennamen</big>";
    echo "</td>";
    echo "<td>";
    $result = mysql_list_tables($conf_4f_db ["datenbank"]);

    $db_errno  = mysql_errno ();
    $db_errtxt = mysql_error ();

    if ($db_errno != 0) {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
    } else {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
    }

    echo "</td>";
    echo "<td>";

    echo "<big><b>";
      outerrormsg ($db_errno, $db_errtxt);
    echo "</b></big>";

    $i=0;
    while ($row = mysql_fetch_row($result)) {
        $tables[$i++] = $row[0] ;
    }
    mysql_free_result($result);

    echo "</td>";
    echo "</tr>";
    echo "</tbody></table>";

    echo "<big>Die Datenbanktabellen werden in das Verzeichnis:<br><b>\"".
          $conf_4f ["einsatzende_dir"]."\"</b> kopiert.<br><br></big>";

    // dann schreiben wir alle Tabellen als csv-Datei in das Einsatzverzeichnis

    echo "<table border=\"1\" cellspacing=\"2\" cellpadding=\"5\">";
    echo "<tbody>";
    foreach ($tables as $table){
      echo "<tr>";
      echo "<td>";
      $filename = $conf_4f ["einsatzende_dir"]."/".$table.".csv" ;
      echo "<b>".$table."</b>";
      echo "</td>";

      echo "<td>";

      $query = "SELECT * INTO OUTFILE '".$filename."' FIELDS TERMINATED BY ';'
                OPTIONALLY ENCLOSED BY '\"' FROM ".$table." WHERE 1;";

      $sqlquery = $query ;

      $result = mysql_query ($sqlquery, $db_hndl) ;

      $db_errno  = mysql_errno ();
      $db_errtxt = mysql_error ();
      if ($db_errno != 0) {
        echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
      } else {
        echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
      }
      echo "</td><td>";
      echo "<b>";
        outerrormsg ($db_errno, $db_errtxt);
      echo "</b>";
      echo "</td></tr>";
    }

    echo "</tbody></table>";

    echo "<br><big><big>Die Tabellen wurden als Komma getrennte Werte gespeichert und können so in<br>".
         " OpenOffice.Calc oder Excel importiert und ausgewertet werden.<br></big></big>";
    // zum Schluss übertragen wir das gesamte Verzeichnis als ZIP-Datei an den anforderer

  }

} //class  make_econf

  set_time_limit ( 30 );


  include ("../4fcfg/dbcfg.inc.php");
  include ("../4fcfg/e_cfg.inc.php");
  include ("../4fcfg/config.inc.php");


// auf die benutzerdefinierte Fehlerbehandlung umstellen
  $old_error_handler = set_error_handler("myErrorHandler");


  // Aufruf von admin.php
  // aufruf von mainindex

  // vom formular datenbankparameter
  // vom formular Einsatzdaten


  if  ( $_GET ["task"] == "datenbank" ) {
    $a = new make_dbconf ($conf_4f_db, $conf_4f_tbl, $conf_tbl, $conf_4f);

    if (isset($_GET["absenden_x"])) {
      $a->write_dbfkt_file ($_GET);
      header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/4fadm/admin.php");
    }
    if ( isset($_GET["abbrechen_x"]) ){
      header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/4fadm/admin.php");
    }

    $a->menue ();
  }

  if  ( $_GET ["task"] == "einsatz_neu" ) {
    $e = new make_econf ($conf_4f_db, $conf_4f_tbl, $conf_tbl, $conf_4f);
    if (isset($_GET["absenden_x"])) {
      $e->write_efkt_file ($_GET);
      header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/4fadm/create_db.php");
    }
    if ( isset($_GET["abbrechen_x"]) ){
      header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/4fadm/admin.php");
    }
    $e->menue ();
  }

  switch ($_GET ["task"]){
    case "datenbank"    : $titel = "DB-Parameter einstellen."; break;
    case "einsatz_neu"  : $titel = "Einsatz anlegen."; break;
    case "einsatz_ende" : $titel = "Einsatz abschliessen."; break;
  }

  echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">";
  echo "<HTML>";
  echo "<HEAD>";
  echo "<META HTTP-EQUIV=\"CONTENT-TYPE\" CONTENT=\"text/html; charset=iso\">";
  echo "<TITLE>".$titel."</TITLE>";
  echo "<META NAME=\"GENERATOR\" CONTENT=\"OpenOffice.org 2.0  (Linux)\">";
  echo "<META NAME=\"AUTHOR\" CONTENT=\"Hajo Landmesser\">";
  echo "<META NAME=\"CREATED\" CONTENT=\"20070327;15421200\">";
  echo "<META NAME=\"CHANGEDBY\" CONTENT=\"hajo\">";
  echo "<META NAME=\"CHANGED\" CONTENT=\"20080612;18052200\">";
  echo "</HEAD>";
  echo "<BODY LANG=\"de-DE\">";


  if  ( $_GET ["task"] == "einsatz_ende" ) {
    $e = new make_econf ($conf_4f_db, $conf_4f_tbl, $conf_tbl, $conf_4f);

    $e->schliesse_einsatz ();

  }

  echo "</BODY></HTML>";

  if ( debug == true ){
    echo "<br><br>\n";
    echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
    echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
    echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
    echo "SESSION="; var_dump ($_SESSION); echo "#<br>\n";
  }

?>
