<?php
/*****************************************************************************\
   Datei: db_operation.php

   benötigte Dateien:

   Beschreibung:



   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
class db_access {

  var $db_server ;
  var $db_name ;
  var $db_table ;
  var $db_user ;
  var $db_pw ;


  var $db_sqlquery;
  var $db_result;

  function db_access ($newdb_server, $newdb_name, $newdb_table, $newdb_user, $newdb_pw){
    $this->db_server = $newdb_server ;
    $this->db_name   = $newdb_name ;
    $this->db_table  = $newdb_table ;
    $this->db_user   = $newdb_user ;
    $this->db_pw     = $newdb_pw ;
  }


  function db_connection_check (){
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[table_exist] Konnte keine Verbindung zur Datenbank herstellen");

    $result = mysql_ping  ($db);

    return ($result);
  }



  function table_exist ($tablename) {
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[table_exist] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");

    $result = mysql_list_tables($this->db_name);

    if (!$result) {
      echo "DB Fehler, Tabellen können nicht angezeigt werden\n";
      echo 'MySQL Fehler: ' . mysql_error();
      exit;
    }
    $table_exist = FALSE;
    while ($row = mysql_fetch_row($result)) {
      if ( $tablename == $row [0] ) { $table_exist = TRUE; }
    }
    mysql_free_result($result);
    return ( $table_exist );
  } // function table_exist

/******************************************************************************\
  Funktion create_user_table ($tablename)
\******************************************************************************/

  function create_user_table ($tablename, $fkttblname) {
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("create_user_table [connect] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");
     // gelesen
    $query = "CREATE TABLE IF NOT EXISTS ".$tablename."_read (
        `lfd` bigint(20) unsigned NOT NULL auto_increment COMMENT 'Laufende Nummer',
        `zeit` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP COMMENT 'Zeitpunkt der letzte Änderung',
        `nachnum` bigint(20) NOT NULL COMMENT 'Fremdschlüssel der Erledigten Nachricht',
        `gelesen` datetime NOT NULL default '0000-00-00 00:00:00' COMMENT 'Zeitpunkt wann die Nachricht gelesen wurde',
        PRIMARY KEY  (`lfd`)
      ) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";
    $result = mysql_query($query);
    if (!$result) {
       die('89 db_oper Ungueltige Abfrage: ' . mysql_error());
    }

     $query = "ALTER TABLE `".$tablename."_read` ADD INDEX ( `nachnum` ) ";
     $result = mysql_query($query);
    if (!$result) {
       die('95 db_oper Ungueltige Abfrage: ' . mysql_error());
    }

     // erledigte bezogen auf die Funktion

    $query = "CREATE TABLE IF NOT EXISTS ".$fkttblname."_erl (
        `lfd` bigint(20) unsigned NOT NULL auto_increment COMMENT 'Laufende Nummer',
        `zeit` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP COMMENT 'Zeitpunkt der letzte Änderung',
        `nachnum` bigint(20) NOT NULL COMMENT 'Fremdschlüssel der Erledigten Nachricht',
        `erledigt` datetime NOT NULL default '0000-00-00 00:00:00' COMMENT 'Zeitpunkt wann die Nachricht gelesen wurde',
        PRIMARY KEY  (`lfd`)
      ) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";

    $result = mysql_query($query);
    if (!$result) {
       die('110 db_Oper Ungueltige Abfrage: ' . mysql_error());
    }


     $query = "ALTER TABLE `".$fkttblname."_erl` ADD INDEX ( `nachnum` ) ";
     $result = mysql_query($query);
    if (!$result) {
       die('117 db_oper Ungueltige Abfrage: ' . mysql_error());
    }

     // Kategorien der Funktion
    $query = "CREATE TABLE IF NOT EXISTS ".$fkttblname."_katego (
        `lfd` bigint(20) unsigned NOT NULL auto_increment COMMENT 'Laufende Nummer',
        `kategorie` varchar(10) NOT NULL COMMENT 'Benutzer definierte Kategorien',
        `beschreibung` varchar (254) NULL COMMENT 'Beschreibung zur Kategorie',
        PRIMARY KEY  (`lfd`)
      ) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";

    $result = mysql_query($query);
    if (!$result) {
       die('131 db_oper Ungueltige Abfrage: ' . mysql_error());
    }
     // Zuordnung Kategorien <--> Meldung
    $query = "CREATE TABLE IF NOT EXISTS ".$fkttblname."_kategolink (
             `msg` bigint(20) NOT NULL,
          `katego` bigint(20) NOT NULL
           ) ENGINE=MyISAM DEFAULT CHARSET=latin1 ;";

    $result = mysql_query($query);
    if (!$result) {
       die('Ungueltige Abfrage: ' . mysql_error());
    }



     // Kategorien
    $query = "CREATE TABLE IF NOT EXISTS ".$tablename."_katego (
        `lfd` bigint(20) unsigned NOT NULL auto_increment COMMENT 'Laufende Nummer',
        `kategorie` varchar(10) NOT NULL COMMENT 'Benutzer definierte Kategorien',
        `beschreibung` varchar (254) NULL COMMENT 'Beschreibung zur Kategorie',
        PRIMARY KEY  (`lfd`)
      ) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";

    $result = mysql_query($query);
    if (!$result) {
       die('131 db_oper Ungueltige Abfrage: ' . mysql_error());
    }
     // Zuordnung Kategorien <--> Meldung
    $query = "CREATE TABLE IF NOT EXISTS ".$tablename."_kategolink (
             `msg` bigint(20) NOT NULL,
          `katego` bigint(20) NOT NULL
           ) ENGINE=MyISAM DEFAULT CHARSET=latin1 ;";

    $result = mysql_query($query);
    if (!$result) {
       die('Ungueltige Abfrage: ' . mysql_error());
    }
  }


  function read_table (){
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[read_table] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");

    $this->sqlquery = "SELECT * FROM $this->db_table WHERE 1" ;

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[read_table]  103-".mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);

    for ($i=1;$i<=$this->resultcount;$i++){
      $this->result[$i] = mysql_fetch_assoc($query_result);
    }

    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->result);
  } // function read_table

  function query_table ($query){
    $this->result = "";
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table196] Konnte keine Verbindung zur Datenbank herstellen" . mysql_error());

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table199] Auswahl der Datenbank fehlgeschlagen" . mysql_error());

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table202] <br>$query<br>103-".mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);

    for ($i=1;$i<=$this->resultcount;$i++){
      $this->result[$i] = mysql_fetch_assoc($query_result);
    }

    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->result);
  } // function read_table

  function query_table_wert ($query){
    $this->result = "";
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table_wert] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table_wert] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table_wert] 103-".mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);

    $this->result = mysql_fetch_row($query_result);

    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->result);
  } // function query_table_wert


  function query_table_iu ($query){
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table_iu] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table_iu] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table_iu] ".mysql_error()." ".mysql_errno());
    mysql_close ($db);
    return ($query_result);
  } // function query_table_iu



  function query_usrtable ($query){
    $this->result = "";
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table257] Konnte keine Verbindung zur Datenbank herstellen".mysql_error()." ".mysql_errno());

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table250] Auswahl der Datenbank fehlgeschlagen".mysql_error()." ".mysql_errno());

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table263] 103-".mysql_error()." ".mysql_errno().mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);

    for ($i=1;$i<=$this->resultcount;$i++){
      $this->result[$i] = mysql_fetch_assoc($query_result);

      $this->result[$i] = $this->result[$i]["00_lfd"] ;

//     echo "this->result[$i]=?=";var_dump ($this->result[$i]); echo "<br>";

    }

    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->result);
  } // function read_table

} // class


?>
