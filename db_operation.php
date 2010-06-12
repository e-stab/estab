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
    // echo "Datenbankparameter = ".$this->db_server." - ".$this->db_name." - ".$this->db_table." - ".$this->db_user." - ".$this->db_pw."<br>";
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


  function create_user_table ($tablename) {
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("create_user_table [connect] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");

    $query = "CREATE TABLE IF NOT EXISTS ".$tablename."_read (
        `lfd` bigint(20) unsigned NOT NULL auto_increment,
        `zeit` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
        `nachnum` bigint(20) NOT NULL,
        `gelesen` datetime NOT NULL default '0000-00-00 00:00:00',
        `kategorie` tinyint(1) NULL,
        PRIMARY KEY  (`lfd`)
      ) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";

    $result = mysql_query($query);
    if (!$result) {
       die('Ungueltige Abfrage: ' . mysql_error());
    }

    $query = "CREATE TABLE IF NOT EXISTS ".$tablename."_erl (
        `lfd` bigint(20) unsigned NOT NULL auto_increment,
        `zeit` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
        `nachnum` bigint(20) NOT NULL,
        `gelesen` datetime NOT NULL default '0000-00-00 00:00:00',
        `kategorie` tinyint(1) NULL,
        PRIMARY KEY  (`lfd`)
      ) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";

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
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table] <br>$query<br>103-".mysql_error()." ".mysql_errno());

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
  } // function query_table_iu



  function query_usrtable ($query){
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

      $this->result[$i] = $this->result[$i]["00_lfd"] ;

//     echo "this->result[$i]=?=";var_dump ($this->result[$i]); echo "<br>";

    }

    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->result);
  } // function read_table

} // class


?>
