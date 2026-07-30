<?php
require_once __DIR__ . "/../app/dynamic_schema.php";
//if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>DB-Operationen</big><br>";  }
/*****************************************************************************\
   Datei: db_operation.php

   benÃ¶tigte Dateien:

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
  var $sqlquery;
  var $result = "";
  var $resultcount = 0;

  function __construct ($newdb_server, $newdb_name, $newdb_table, $newdb_user, $newdb_pw){
    $this->db_access ($newdb_server, $newdb_name, $newdb_table, $newdb_user, $newdb_pw);
  }

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
    mysql_query('SET NAMES utf8');
    $result = mysql_ping  ($db);

    return ($result);
  }



  function table_exist ($tablename) {
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[table_exist] Konnte keine Verbindung zur Datenbank herstellen");
    mysql_query('SET NAMES utf8');
    $db_check = mysql_select_db ($this->db_name)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");

    $result = mysql_list_tables($this->db_name);

    if (!$result) {
      echo "DB-Fehler: Tabellen können nicht angezeigt werden.\n";
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
    // Keep the historic public method while delegating every caller to the
    // shared reconciliation boundary also used by personal duty-hat changes.
    // The separate connection is intentional: schema DDL commits implicitly
    // and must never commit the surrounding login transaction.
    $schema_db = mysql_connect (
      $this->db_server,
      $this->db_user,
      $this->db_pw
    );
    if (!$schema_db instanceof mysqli) {
      throw new RuntimeException (
        "[create_user_table] Konnte keine Verbindung zur Datenbank herstellen"
      );
    }
    try {
      if (!mysql_select_db ($this->db_name, $schema_db)) {
        throw new RuntimeException (
          "[create_user_table] Auswahl der Datenbank fehlgeschlagen"
        );
      }
      estab_dynamic_schema_reconcile_bases (
        $schema_db,
        $tablename,
        $fkttblname
      );
    } finally {
      mysql_close ($schema_db);
    }
    return;
  }

  function read_table (){
    $this->result = array ();
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[read_table] Konnte keine Verbindung zur Datenbank herstellen");
    mysql_query('SET NAMES utf8');
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
    return ($this->resultcount > 0 ? $this->result : "");
  } // function read_table

  function query_table ($query){
    $this->result = array ();
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table196] Konnte keine Verbindung zur Datenbank herstellen" . mysql_error());
    mysql_query('SET NAMES utf8');
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
    return ($this->resultcount > 0 ? $this->result : "");
  } // function read_table

  function query_table_wert ($query){
    $this->result = array ();
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table_wert] Konnte keine Verbindung zur Datenbank herstellen");
    mysql_query('SET NAMES utf8');
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
    mysql_query('SET NAMES utf8');
    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table_iu] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table_iu] ".mysql_error()." ".mysql_errno()."<br> Query=".$query);
    mysql_close ($db);
    return ($query_result);
  } // function query_table_iu



  function query_usrtable ($query){
    $this->result = array ();
    $this->sqlquery = $query ;
    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table257] Konnte keine Verbindung zur Datenbank herstellen".mysql_error()." ".mysql_errno());
    mysql_query('SET NAMES utf8');
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
    return ($this->resultcount > 0 ? $this->result : "");
  } // function read_table

} // class


?>
