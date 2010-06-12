<?php
/*
// umdefinieren der Konstanten - nur in PHP 4
define("FATAL", E_USER_ERROR);
define("ERROR", E_USER_WARNING);
define("WARNING", E_USER_NOTICE);

// die Stufe für dieses Skript einstellen
error_reporting(FATAL | ERROR | WARNING);
*/
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
      case 1049 :
         echo "<p>Datenbank nicht vorhanden <a href=\"./4fach/create_db.php\">Datenbank anlegen</a></p>";
      break;
      case 2005 :
         echo "SQL-Server nicht bekannt.";
      break;
      default:
         echo "Fehlernummer = ".$errno."<br>Fehlertext = ".$errtxt;
      break;
    }
}

/*-----------------------------------------------------------------------------\


\-----------------------------------------------------------------------------*/

//$old_error_handler = set_error_handler("myErrorHandler");

  include "../dbcfg.inc.php";

  echo "<big><big>";
  $link = mysql_connect(  $conf_4f_db ["server"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
  if (!$link) {
     die('keine Verbindung m&ouml;glich: ' . mysql_error());
  }
  echo "Verbindung erfolgreich<br>\n";

  $query = "CREATE DATABASE IF NOT EXISTS ".$conf_4f_db ["datenbank"] ;
  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ung&uuml;ltige Abfrage: ' . mysql_error());
  } else {
    echo 'Datenbank wurde angelegt oder war schon vorhanden';
    echo "<br>";
  }


  include "./create_dir.php";



  // benutze Datenbank vStab_db
  $db_selected = mysql_select_db($conf_4f_db ["datenbank"], $link);
  if (!$db_selected) {
     die ('Kann Datenbank nicht benutzen : ' . mysql_error());
  } else {
    echo 'Datenbank wurde ausgew&auml;hlt';
    echo "<br>";
  }

  $query = "CREATE TABLE IF NOT EXISTS `".$conf_4f_tbl ["nachrichten"]."` (
    `00_lfd` bigint(20) NOT NULL auto_increment,
    `01_medium` set('Fe','Fu','Me','FAX','FS','eMail') NOT NULL default '',
    `01_datum` datetime NOT NULL default '0000-00-00 00:00:00',
    `01_zeichen` char(3) NOT NULL default '',
    `02_zeit` datetime NOT NULL default '0000-00-00 00:00:00',
    `02_zeichen` char(3) NOT NULL default '',
    `03_datum` datetime NOT NULL default '0000-00-00 00:00:00',
    `03_zeichen` char(3) NOT NULL default '',
    `04_richtung` set('E','A') NOT NULL default '',
    `04_nummer` bigint(20) NOT NULL default '0',
    `05_gegenstelle` varchar(128) NOT NULL default '',
    `06_befweg` varchar(128) NOT NULL default '',
    `06_befwegausw` set('Fe','Fu','Me','FAX','FS','eMail') NOT NULL default '',
    `07_durchspruch` set('D','S') NOT NULL default '',
    `08_befhinweis` varchar(128) NOT NULL default '',
    `08_befhinwausw` set('Fe','Fu','Me','FAX','FS','eMail') NOT NULL default '',
    `09_vorrangstufe` set('eee','sss','bbb','aaa') NOT NULL default '',
    `10_anschrift` varchar(255) NOT NULL default '',
    `11_gesprnotiz` binary(1) NOT NULL default 'f',
    `12_anhang` text NULL,
    `12_inhalt` longtext NOT NULL,
    `12_abfzeit` datetime NOT NULL default '0000-00-00 00:00:00',
    `13_abseinheit` varchar(128) NOT NULL default '',
    `14_zeichen` char(3) NOT NULL default '',
    `14_funktion` varchar(128) NOT NULL default '',
    `15_quitdatum` datetime NOT NULL default '0000-00-00 00:00:00',
    `15_quitzeichen` char(3) NOT NULL default '',
    `16_empf` tinytext NULL,
    `17_vermerke` longtext NULL,
    `x00_status` smallint(6) NOT NULL default '0',
    `x01_abschluss` binary(1) NOT NULL default 'f',
    `x02_sperre` binary(1) NOT NULL default 'f',
    `x03_sperruser` char(3) NOT NULL default '',
    `x04_druck` binary(1) NOT NULL default 'f',
    `x05_druck_d` datetime NOT NULL default '0000-00-00 00:00:00',
    `99_lstacc` timestamp NOT NULL default '0000-00-00 00:00:00' on update CURRENT_TIMESTAMP,
    KEY `00_lfd` (`00_lfd`),
    FULLTEXT KEY `12_inhalt` (`12_inhalt`)
  ) ENGINE=MyISAM  AUTO_INCREMENT=1 ;";

  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ung&uuml;ltige Abfrage: ' . mysql_error());
  } else {
    echo 'Mailstabelle wurde angelegt.';
    echo "<br>";
  }

  $query = "CREATE TABLE IF NOT EXISTS `".$conf_4f_tbl ["benutzer"]."` (
    `benutzer` varchar(50) NOT NULL default '',
    `kuerzel` varchar(6) NOT NULL default '',
    `funktion` varchar(10) NOT NULL default '',
    `rolle` varchar(15) NOT NULL default '',
    `sid` varchar(50) NOT NULL default '',
    `ip` varchar(15) NOT NULL default '',
    `aktiv` smallint(1) NOT NULL,
    PRIMARY KEY  (`kuerzel`)
  ) ENGINE=MyISAM DEFAULT CHARSET=latin1;";


  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ungültige Abfrage: ' . mysql_error());
  } else {
    echo 'Benutzertabelle wurde angelegt.';
    echo "<br>";
  }

  $query = "CREATE TABLE IF NOT EXISTS `".$conf_4f_tbl ["protokoll"]."` (
    `p_lfd` bigint(20) NOT NULL auto_increment,
    `p_zeit` timestamp NOT NULL default CURRENT_TIMESTAMP,
    `p_was` varchar(30) NOT NULL default '',
    `p_ereignis` text NOT NULL,
    PRIMARY KEY  (`p_lfd`)
  ) ENGINE=MyISAM AUTO_INCREMENT=1 ;";

  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ung&uuml;ltige Abfrage: ' . mysql_error());
  } else {
    echo 'Protokolltabelle wurde angelegt.';
    echo "<br>";
  }


  $query = "CREATE TABLE IF NOT EXISTS `".$conf_4f_tbl ["anhang"]."` (
    `lfd-nr` bigint(20) NOT NULL AUTO_INCREMENT,
    `filename` varchar(255) NOT NULL,
    `org_filename` varchar(255) NOT NULL,
    `comment` varchar (255) NOT NULL,
    `date` datetime NOT NULL,
    `kuerzel` varchar(3),
    PRIMARY KEY (`lfd-nr`)
    ) ENGINE=MyISAM AUTO_INCREMENT=1; ";


  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ung&uuml;ltige Abfrage: ' . mysql_error());
  } else {
    echo 'Anhangtabelle wurde angelegt.';
    echo "<br>";
  }

  $query = "CREATE TABLE IF NOT EXISTS `".$conf_tbl ["etb"]."` (
  `etb_lfd-nr` INT NOT NULL auto_increment,
  `etb_time` DATETIME NOT NULL ,
  `etb_aktion` TEXT NOT NULL ,
  `etb_bemerk` TEXT NOT NULL,
  `etb_benutzer` varchar(50) NOT NULL default '',
  `etb_kuerzel` varchar(6) NOT NULL default '',
  `etb_funktion` varchar(10) NOT NULL default '',
   PRIMARY KEY  (`etb_lfd-nr`)
  ) ENGINE = MYISAM AUTO_INCREMENT=1;";

  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ung&uuml;ltige Abfrage: ' . mysql_error());
  } else {
    echo "Einsatztagebuch wurde angelegt.";
    echo "<br>";
  }



$query = "CREATE TABLE IF NOT EXISTS `".$conf_tbl ["komplan"]."` (
  `lfd` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stelle` varchar(255) NOT NULL,
  `orga` varchar(15) NULL,
  `rufname` varchar(40) NULL,
  `kanal4` varchar(8) NULL,
  `kanal2` varchar(7) NULL,
  `tel1` varchar(20) NULL,
  `tel2` varchar(20) NULL,
  `mobil1` varchar(20) NULL,
  `mobil2` varchar(20) NULL,
  `fax1` varchar(20) NULL,
  `fax2` varchar(20) NULL,
  `e-mail` varchar(255) NULL,
  `ftphttp` varchar(255) NULL,
  PRIMARY KEY (`lfd`)
) ENGINE=MyISAM AUTO_INCREMENT=1 ;";

  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ung&uuml;ltige Abfrage: ' . mysql_error());
  } else {
    echo "Komunikationsplan wurde angelegt.";
    echo "<br>";
  }

$query = "CREATE TABLE IF NOT EXISTS `".$conf_tbl ["bhp50"]."` (
  `lfd-nr` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `anhang` varchar(7) ,
  `name` varchar(20) ,
  `vorname` varchar(20) ,
  `geschlecht` set('m','w') ,
  `nation` varchar(20) ,
  `gebdat` varchar(10) ,
  `fundort` varchar(30) ,
  `datum` varchar(10) ,
  `sich1` set('1','2','3','4','5') ,
  `sich1_arzt` varchar(20) ,
  `sich1_zeit` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sich2` set('1','2','3','4','5') ,
  `sich2_arzt` varchar(20) ,
  `sich2_zeit` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `sich3` set('1','2','3','4','5') ,
  `sich3_arzt` varchar(20) ,
  `sich3_zeit` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `sich4` set('1','2','3','4','5') ,
  `sich4_arzt` varchar(20) ,
  `sich4_zeit` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `diagnose` text ,
  `trans_liegend` set('t','f') ,
  `trans_sitzend` set('t','f') ,
  `mit_arzt` set('t','f') ,
  `isoliert` set('t','f') ,
  `trans_mittel` varchar(15) ,
  `trans_ziel` varchar(15) ,
  `trans_start` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `trans_dauer` float NOT NULL,
  `sudi_wohnort` varchar(30) ,
  `sudi_strasse` varchar(30) ,
  `sudi_konfekt` varchar(3) ,
  `sudi_verbleib` varchar(30) ,
  `sudi_bemerk` text ,
  PRIMARY KEY (`lfd-nr`),
  KEY `anhang` (`anhang`)
) ENGINE=MyISAM AUTO_INCREMENT=1 ; ";

  $result = mysql_query($query, $link);
  if (!$result) {
     die('Ung&uuml;ltige Abfrage: ' . mysql_error());
  } else {
    echo "BHP 50 wurde angelegt.";
    echo "<br>";
  }
















/* SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";

--
-- Datenbank: `bhp50_db`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur f�r Tabelle `benutzer`
--

CREATE TABLE `benutzer` (
  `benutzer` varchar(50) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `kuerzel` varchar(6) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `funktion` varchar(10) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `rolle` varchar(15) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sid` varchar(50) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `ip` varchar(15) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`kuerzel`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;

--
-- Daten f�r Tabelle `benutzer`
--


-- --------------------------------------------------------

--
-- Tabellenstruktur f�r Tabelle `patienten`
--

CREATE TABLE `patienten` (
  `lfd` int(11) NOT NULL AUTO_INCREMENT,
  `patid` varchar(6) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `name` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `vorname` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `geschlecht` set('m','w') COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `nation` varchar(20) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `gebdat` date NOT NULL DEFAULT '0000-00-00',
  `fundort` varchar(128) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `datum` date NOT NULL DEFAULT '0000-00-00',
  `sicht_1` smallint(6) NOT NULL DEFAULT '0',
  `sicht_1_arzt` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sicht_1_zeit` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `sicht_2` smallint(6) NOT NULL DEFAULT '0',
  `sicht_2_arzt` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sicht_2_zeit` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `sicht_3` smallint(6) NOT NULL DEFAULT '0',
  `sicht_3_arzt` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sicht_3_zeit` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `sicht_4` smallint(6) NOT NULL DEFAULT '0',
  `sicht_4_arzt` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sicht_4_zeit` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `diagnose` text COLLATE latin1_german1_ci NOT NULL,
  `trans` binary(1) NOT NULL DEFAULT 'f',
  `trans_ligend` binary(1) NOT NULL DEFAULT 'f',
  `trans_sitzend` binary(1) NOT NULL DEFAULT 'f',
  `trans_mitarzt` binary(1) NOT NULL DEFAULT ' ',
  `trans_isoliert` binary(1) NOT NULL DEFAULT ' ',
  `trans_mittel` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `trans_ziel` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sd_wohnort` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sd_strasse` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sd_religion` varchar(10) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sd_verbleib` varchar(128) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `sd_bemerk` text COLLATE latin1_german1_ci NOT NULL,
  PRIMARY KEY (`lfd`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci AUTO_INCREMENT=1 ;

--
-- Daten f�r Tabelle `patienten`
--


-- --------------------------------------------------------

--
-- Tabellenstruktur f�r Tabelle `protokoll`
--

CREATE TABLE `protokoll` (
  `p_lfd` bigint(20) NOT NULL AUTO_INCREMENT,
  `p_zeit` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `p_was` varchar(30) COLLATE latin1_german1_ci NOT NULL DEFAULT '',
  `p_ereignis` text COLLATE latin1_german1_ci NOT NULL,
  PRIMARY KEY (`p_lfd`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci AUTO_INCREMENT=1 ;

--
-- Daten f�r Tabelle `protokoll`
--

*/







  echo 'Ich habe fertig.';

  echo "</big></big>";

  mysql_close($link);


?>
