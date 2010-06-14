<?php

include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");
include ("../4fcfg/config.inc.php");


?>

<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
<HTML>
<HEAD>
        <META HTTP-EQUIV="CONTENT-TYPE" CONTENT="text/html; charset=utf-8">
        <TITLE></TITLE>
        <META NAME="GENERATOR" CONTENT="vi">
        <META NAME="AUTHOR" CONTENT="Hajo Landmesser">
        <META NAME="CREATED" CONTENT="20070327;15421200">
        <META NAME="CHANGEDBY" CONTENT="hajo">
        <META NAME="CHANGED" CONTENT="222206aug2007">
</HEAD>
<BODY LANG="de-DE" DIR="LTR">
<P ALIGN=CENTER STYLE="font-weight: medium"><FONT FACE="Arial Black">
        <FONT SIZE=5 STYLE="font-size: 20pt">Datenbank Verbindungspr&uuml;fung:</FONT></FONT></P>
<P><BR><BR>
</P>

<?php


// umdefinieren der Konstanten - nur in PHP 4
define("FATAL", E_USER_ERROR);
define("ERROR", E_USER_WARNING);
define("WARNING", E_USER_NOTICE);

// die Stufe für dieses Skript einstellen
error_reporting(E_ALL); //FATAL | ERROR | WARNING);

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
//    echo "Unkown error type: [$errno] $errstr<br />\n";
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
         echo "<p>Datenbank nicht vorhanden <a href=\"./create_db.php\">Datenbank anlegen</a></p>";
      break;
      case 2005 :
         echo "SQL-Server nicht bekannt.";
      break;
      default:
         echo "Fehlernummer = ".$errno."<br>Fehlertext = ".$errtxt;
      break;
    }
}



function cmp_tablename ($tables, $cmp_table) {
  $result = false;
  foreach ($tables as $table) {
    if ($cmp_table == $table){ $result = true; }
  }
  return $result;
}

// auf die benutzerdefinierte Fehlerbehandlung umstellen
$old_error_handler = set_error_handler("myErrorHandler");


  echo "<table>";
  echo "<tbody>";
  echo "<tr>";
  echo "<th>Test</th><th>Ergebnis</th><th>Massnahme</th>";
  echo "</tr>";
    /**************************************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Verbindungstest: Datenbankserver</big></big>";
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

    /**************************************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Verbindungstest: Datenbank <b>".$conf_4f_db ["datenbank"]."</b></big></big>";
  echo "</td><td>";
    $result = mysql_select_db ($conf_4f_db ["datenbank"], $db_hndl);

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

    /**************************************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Verbindungstest: Tabellen in der Datenbank <b>".$conf_4f_db ["datenbank"]."</b></big></big>";
  echo "</td><td>";

    $result = mysql_list_tables($conf_4f_db ["datenbank"]);

    $db_errno  = mysql_errno ();
    $db_errtxt = mysql_error ();

    if ($db_errno != 0) {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
    } else {
      echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
    }
    $i = 0;
    if ($result) {
      while ($row = mysql_fetch_row($result)) {
         $tables[$i++] = $row[0] ;
      }
    }
    mysql_free_result($result);

  echo "</td>";
  echo "<td>";
  echo "<big><b>";
    outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";

  /******  Benutzer *************************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_4f_tbl ["benutzer"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_4f_tbl ["benutzer"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }


  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";

  /******  Masterkategorie*******************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_4f_tbl ["masterkatego"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_4f_tbl ["masterkatego"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }


  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";

  /******  Masterkategorielink*******************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_4f_tbl ["masterkategolk"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_4f_tbl ["masterkategolk"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }


  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";



  /******  Nachrichten **********************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_4f_tbl ["nachrichten"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_4f_tbl ["nachrichten"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }


  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";

  /******  Protokoll ***********************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_4f_tbl ["protokoll"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_4f_tbl ["protokoll"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }
  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";


  /******   Anhang   ***********************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_4f_tbl ["anhang"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_4f_tbl ["anhang"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }
  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";


  /******  Einsatztagebuch ******************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_tbl ["etb"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_tbl ["etb"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }


  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";
  /******  Betriebbuch ***********************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_tbl ["tbb"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_tbl ["tbb"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }
  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";

  /******  Komunikationsplan******************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_tbl ["komplan"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_tbl ["komplan"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }
  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";


    /******  BHP 50   ***********************************************************/
  echo "<tr>";
  echo "<td>";
  echo "<big><big>Tabelle: <b>".$conf_tbl ["bhp50"]."</b></big></big>";
  echo "</td><td>";
  if (cmp_tablename ($tables, $conf_tbl ["bhp50"])) {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/greenlight.gif\" alt=\"keine Fehler\"></p>";
  } else {
    echo "<p><img src=\"".$conf_menue ["symbole"]."/redlight.gif\" alt=\"schwerer Fehler\"></p>";
  }
  echo "</td>";
  echo "<td>";
  echo "<big><b>";
  outerrormsg ($db_errno, $db_errtxt);
  echo "</b></big>";
  echo "</td>";
  echo "</tr>";




  echo "<tbody>";
  echo "</table>";

  mysql_close ($db_hndl);
  //  echo "mysqlerror=".mysql_errno()." ----- ".mysql_error()."<br>";

//   $conf_4f_db ["datenbank"]     = "nv_db";
?>
</BODY>
</HTML>
