<?php
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
      echo "DB Fehler, Tabellen kÃ¶nnen nicht angezeigt werden\n";
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

  function quote_dynamic_table_identifier ($basename, $suffix) {
    if (!is_string($basename) || $basename === "") {
      throw new InvalidArgumentException ("Dynamischer Tabellenname darf nicht leer sein");
    }

    $identifier = $basename.$suffix;
    if ((strlen ($identifier) > 64) ||
        (!preg_match ("/\\A[A-Za-z_][A-Za-z0-9_]*\\z/D", $identifier))) {
      throw new InvalidArgumentException ("Ungueltiger dynamischer Tabellenname");
    }

    return "`".$identifier."`";
  }

  function execute_dynamic_schema_query ($query, $db) {
    $result = mysql_query ($query, $db);
    if (!$result) {
      throw new RuntimeException (
        "[create_user_table] Ungueltige Abfrage: ".mysql_error ($db)
      );
    }
  }

  function create_user_table ($tablename, $fkttblname) {
    $user_read       = $this->quote_dynamic_table_identifier ($tablename, "_read");
    $function_done   = $this->quote_dynamic_table_identifier ($fkttblname, "_erl");
    $function_katego = $this->quote_dynamic_table_identifier ($fkttblname, "_katego");
    $function_link   = $this->quote_dynamic_table_identifier ($fkttblname, "_kategolink");
    $user_katego     = $this->quote_dynamic_table_identifier ($tablename, "_katego");
    $user_link       = $this->quote_dynamic_table_identifier ($tablename, "_kategolink");

    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("create_user_table [connect] Konnte keine Verbindung zur Datenbank herstellen");
    $this->execute_dynamic_schema_query (
      'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
      $db
    );
    $db_check = mysql_select_db ($this->db_name, $db)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");

    $mode_result = mysql_query ("SELECT @@SESSION.sql_mode", $db);
    if (!$mode_result) {
      throw new RuntimeException (
        "[create_user_table] SQL-Mode konnte nicht gelesen werden: ".mysql_error ($db)
      );
    }
    $mode_row = mysql_fetch_row ($mode_result);
    mysql_free_result ($mode_result);
    $original_sql_mode = $mode_row [0];

    $create_queries = array (
      "CREATE TABLE IF NOT EXISTS ".$user_read." (
        `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Laufende Nummer',
        `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Zeitpunkt der letzten Aenderung',
        `nachnum` BIGINT NOT NULL COMMENT 'Nachrichtennummer',
        `gelesen` DATETIME NULL DEFAULT NULL COMMENT 'Zeitpunkt, zu dem die Nachricht gelesen wurde',
        PRIMARY KEY (`lfd`),
        KEY `idx_nachnum` (`nachnum`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

      "CREATE TABLE IF NOT EXISTS ".$function_done." (
        `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Laufende Nummer',
        `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Zeitpunkt der letzten Aenderung',
        `nachnum` BIGINT NOT NULL COMMENT 'Nachrichtennummer',
        `erledigt` DATETIME NULL DEFAULT NULL COMMENT 'Zeitpunkt, zu dem die Nachricht erledigt wurde',
        PRIMARY KEY (`lfd`),
        KEY `idx_nachnum` (`nachnum`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

      "CREATE TABLE IF NOT EXISTS ".$function_katego." (
        `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Laufende Nummer',
        `kategorie` VARCHAR(10) NOT NULL COMMENT 'Benutzerdefinierte Kategorie',
        `beschreibung` VARCHAR(254) NULL COMMENT 'Beschreibung zur Kategorie',
        PRIMARY KEY (`lfd`),
        KEY `idx_kategorie` (`kategorie`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

      "CREATE TABLE IF NOT EXISTS ".$function_link." (
        `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `msg` BIGINT NOT NULL,
        `katego` BIGINT NOT NULL,
        PRIMARY KEY (`lfd`),
        KEY `idx_msg_katego` (`msg`, `katego`),
        KEY `idx_katego_msg` (`katego`, `msg`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

      "CREATE TABLE IF NOT EXISTS ".$user_katego." (
        `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Laufende Nummer',
        `kategorie` VARCHAR(10) NOT NULL COMMENT 'Benutzerdefinierte Kategorie',
        `beschreibung` VARCHAR(254) NULL COMMENT 'Beschreibung zur Kategorie',
        PRIMARY KEY (`lfd`),
        KEY `idx_kategorie` (`kategorie`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

      "CREATE TABLE IF NOT EXISTS ".$user_link." (
        `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `msg` BIGINT NOT NULL,
        `katego` BIGINT NOT NULL,
        PRIMARY KEY (`lfd`),
        KEY `idx_msg_katego` (`msg`, `katego`),
        KEY `idx_katego_msg` (`katego`, `msg`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $migration_queries = array (
      "ALTER TABLE ".$user_read."
         MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         MODIFY `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         MODIFY `nachnum` BIGINT NOT NULL,
         MODIFY `gelesen` DATETIME NULL DEFAULT NULL,
         ENGINE=InnoDB,
         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
      "UPDATE ".$user_read."
          SET `gelesen` = NULL
        WHERE `gelesen` = '0000-00-00 00:00:00'",
      "CREATE INDEX IF NOT EXISTS `idx_nachnum` ON ".$user_read." (`nachnum`)",

      "ALTER TABLE ".$function_done."
         MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         MODIFY `zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         MODIFY `nachnum` BIGINT NOT NULL,
         MODIFY `erledigt` DATETIME NULL DEFAULT NULL,
         ENGINE=InnoDB,
         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
      "UPDATE ".$function_done."
          SET `erledigt` = NULL
        WHERE `erledigt` = '0000-00-00 00:00:00'",
      "CREATE INDEX IF NOT EXISTS `idx_nachnum` ON ".$function_done." (`nachnum`)",

      "ALTER TABLE ".$function_katego."
         MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         MODIFY `kategorie` VARCHAR(10) NOT NULL,
         MODIFY `beschreibung` VARCHAR(254) NULL,
         ENGINE=InnoDB,
         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
      "CREATE INDEX IF NOT EXISTS `idx_kategorie` ON ".$function_katego." (`kategorie`)",

      "ALTER TABLE ".$function_link."
         ADD COLUMN IF NOT EXISTS `lfd`
           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST",
      "ALTER TABLE ".$function_link."
         MODIFY `msg` BIGINT NOT NULL,
         MODIFY `katego` BIGINT NOT NULL,
         ENGINE=InnoDB,
         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
      "CREATE INDEX IF NOT EXISTS `idx_msg_katego`
         ON ".$function_link." (`msg`, `katego`)",
      "CREATE INDEX IF NOT EXISTS `idx_katego_msg`
         ON ".$function_link." (`katego`, `msg`)",

      "ALTER TABLE ".$user_katego."
         MODIFY `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         MODIFY `kategorie` VARCHAR(10) NOT NULL,
         MODIFY `beschreibung` VARCHAR(254) NULL,
         ENGINE=InnoDB,
         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
      "CREATE INDEX IF NOT EXISTS `idx_kategorie` ON ".$user_katego." (`kategorie`)",

      "ALTER TABLE ".$user_link."
         ADD COLUMN IF NOT EXISTS `lfd`
           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST",
      "ALTER TABLE ".$user_link."
         MODIFY `msg` BIGINT NOT NULL,
         MODIFY `katego` BIGINT NOT NULL,
         ENGINE=InnoDB,
         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
      "CREATE INDEX IF NOT EXISTS `idx_msg_katego`
         ON ".$user_link." (`msg`, `katego`)",
      "CREATE INDEX IF NOT EXISTS `idx_katego_msg`
         ON ".$user_link." (`katego`, `msg`)"
    );

    try {
      // Only this connection is relaxed while invalid legacy zero dates are
      // made nullable. The original strict mode is restored in every case.
      $this->execute_dynamic_schema_query ("SET SESSION sql_mode = ''", $db);
      foreach ($create_queries as $query) {
        $this->execute_dynamic_schema_query ($query, $db);
      }
      foreach ($migration_queries as $query) {
        $this->execute_dynamic_schema_query ($query, $db);
      }
    } finally {
      $escaped_sql_mode = mysql_real_escape_string ($original_sql_mode, $db);
      $this->execute_dynamic_schema_query (
        "SET SESSION sql_mode = '".$escaped_sql_mode."'",
        $db
      );
    }
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
