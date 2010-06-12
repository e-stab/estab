<?php

/*******************************************************************************
  Klasse: kategorien

  Funktionen:
    - Auflisten der Kategorien
    - neu eintragen von Kategorien
    - editieren/ändern der Kategorien
    - löschen von Kategorien
    - speichern der Kategorien
    - Zuweisung einer Kategorie zu einer Meldung

********************************************************************************/
class kategorien {

  var $db_server;
  var $db_benutzer;
  var $db_passwort;
  var $db_name ;
  var $db_tablname ;
  var $db_tablnamelk;

  var $db_master_katego ;

  var $sqlquery;
  var $db_hndl ;
  var $result ;
  var $resultcount ;

  var $redcopy2 ;
  var $dbtyp ;

  var $grundkatego;
/*******************************************************************************
  KONSTRUKTOR
********************************************************************************/
  function kategorien ($table) {
    include ("../config.inc.php");
    include ("../dbcfg.inc.php");
    include ("../e_cfg.inc.php");
    include ("../fkt_rolle.inc.php");
    $this->redcopy2  = $redcopy2 ;

    if (!isset ($_SESSION ["vStab_funktion"])) session_start ();

    $this->stab_fkt  = $_SESSION ["vStab_funktion"] ;
    $this->dbtyp = $table;

    if ($table == "master") {
      $this->db_master_katego = $conf_4f_tbl ["masterkatego"] ;
      $this->db_tablname      = $conf_4f_tbl ["masterkatego"] ;
      $this->db_tablnamelk    = $conf_4f_tbl ["masterkategolk"];
    } else {
      $this->db_tbl = $conf_4f_tbl ["usrtblprefix"].
                    strtolower ($_SESSION["vStab_funktion"])."_".
                    strtolower ($_SESSION["vStab_kuerzel"]) ;
      $this->db_tablname   = $this->db_tbl."_katego";
      $this->db_tablnamelk = $this->db_tbl."_kategolink";
    }
//    $this->db_master_katego = $conf_4f_tbl ["masterkatego"] ;

    $this->db_server   = $conf_4f_db ["server"];
    $this->db_benutzer = $conf_4f_db ["user"];
    $this->db_passwort = $conf_4f_db ["password"];
    $this->db_name     = $conf_4f_db ["datenbank"];
    $this->grundkatego = array (
          1 => array ("kategorie"    => "Alle",
                      "beschreibung" => "ohne Berücksichtigung der Kategorien"),
          2 => array ("kategorie"    => "ohne",
                      "beschreibung" => "Ohne Kategorie"));

    $this->db_hndl = mysql_connect($this->db_server,$this->db_benutzer, $this->db_passwort)
       or die ("[connection] katego.php 73 Konnte keine Verbindung zur Datenbank herstellen");

    $db_check = mysql_select_db ($this->db_name, $this->db_hndl)
       or die ("[read_table] Auswahl der Datenbank fehlgeschlagen");
    $result = mysql_ping  ($this->db_hndl);
    return ($result);
  }

/*******************************************************************************
   Funktion: lese_kategorien ()
********************************************************************************/
  function lese_kategorien (){
    $this->sqlquery = "SELECT * FROM `".$this->db_tablname."` WHERE 1  ORDER BY `kategorie`;";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
    $this->resultcount = mysql_num_rows($query_result);
    $this->result = "";
    for ($i=1; $i<=$this->resultcount; $i++){
      $this->result[$i] = mysql_fetch_assoc($query_result);
    }
    mysql_free_result($query_result);
  }


/*******************************************************************************
   Funktion: db_get ( $lfd )
********************************************************************************/
  function db_get ( $lfd ){
    $this->sqlquery = "SELECT `lfd`,`kategorie`,`beschreibung` FROM `".$this->db_tablname."`
                        WHERE `".$this->db_tablname."`.`lfd` = '".$lfd."'
                        LIMIT 1;";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$query<br>103-".mysql_error()." ".mysql_errno());
    $this->result = "";
    $this->resultcount = mysql_num_rows($query_result);
    $this->result = mysql_fetch_assoc($query_result);

    return ($this->result);

    mysql_free_result($query_result);
  }

/*******************************************************************************
   Funktion: db_get_kategobymsg ()
********************************************************************************/
  function db_get_kategobymsg ( $lfd ){
    $this->sqlquery = "SELECT `lfd`,`kategorie`,`beschreibung` FROM `".$this->db_tablname."`, `".$this->db_tablnamelk."`
                        WHERE `".$this->db_tablname."`.`lfd` = (
                        SELECT `katego`
                        FROM `".$this->db_tablnamelk."`
                        WHERE `".$this->db_tablnamelk."`.`msg` = '".$lfd."')
                        LIMIT 1;";

//    echo "QUERY====".$this->sqlquery."<br><br><br>";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>133-".mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);
    $this->result = mysql_fetch_assoc($query_result);
    return ($this->result);
    mysql_free_result($query_result);
  }


/******************************************************************************\
  Funktion : get_data ( $no )

  $no =
    1 - Nur die Kategorien
    2 - Nur die Beschreibungen
    3 - Kategortie und Beschreibung
\******************************************************************************/
  function get_data ( $no ){
    switch ($no){
      case 1: // Kategorie
       $this->sqlquery = "SELECT `kategorie` FROM `".$this->db_tablname."`
                          WHERE 1 ;";
      break;
      case 2: // Beschreibung
       $this->sqlquery = "SELECT `beschreibung` FROM `".$this->db_tablname."`
                          WHERE 1 ;";
      break;
      case 3: // Kategorie und Beschreibung
       $this->sqlquery = "SELECT `lfd`,`kategorie`,`beschreibung` FROM `".$this->db_tablname."`
                          WHERE 1 ;";
      break;
      case 4: // Lfd, Kategorie
       $this->sqlquery = "SELECT `lfd`,`kategorie` FROM `".$this->db_tablname."`
                          WHERE 1 ;";
      break;
      case 5: // Lfd, Beschreibung
       $this->sqlquery = "SELECT `lfd`,`beschreibung` FROM `".$this->db_tablname."`
                          WHERE 1 ;";
      break;
      default:
       $this->sqlquery = "SELECT `lfd`,`kategorie`,`beschreibung` FROM `".$this->db_tablname."`
                          WHERE 1 ;";
      break;
    }
//    echo "QUERY===".$this->sqlquery."<br><br>";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$query<br>103-".mysql_error()." ".mysql_errno());

    $this->resultcount = mysql_num_rows($query_result);

//    echo "COUNT===".$this->resultcount."<br><br>";

    for ( $i=0; $i< $this->resultcount; $i++ ){
      $this->result[$i] = mysql_fetch_assoc($query_result);
    }
    return ($this->result);
    mysql_free_result($query_result);
  }


/*
Kategorie bei gegebener Nachrichtennummer

SELECT katego, beschreibung FROM nv_masterkatego, nv_masterkategolink
WHERE nv_masterkatego.lfd =
(SELECT katego
FROM nv_masterkategolink
WHERE nv_masterkategolink.msg = 100)

*/


/*******************************************************************************
   Funktion: db_aendern ()
********************************************************************************/
  function db_aendern ($lfd, $kategorie, $beschreibung){
    $this->sqlquery = "UPDATE `".$this->db_tablname."`
                    SET   `kategorie` = \"".$kategorie."\",
                          `beschreibung` = \"".$beschreibung."\"
                    WHERE `lfd` = \"".$lfd."\";";

//echo "<br><br>QUERY==="; var_dump ($this->sqlquery);echo "<br><br>";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
  }


/*******************************************************************************
   Funktion: db_neu()
********************************************************************************/
  function db_neu ($kategorie, $beschreibung){
    $this->sqlquery = "INSERT INTO `".$this->db_tablname."`
                       SET `kategorie` = \"".$kategorie."\",
                           `beschreibung` = \"".$beschreibung."\"";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
  }

/*******************************************************************************
   Funktion: db_neu()
********************************************************************************/
  function db_delete ( $lfd ){
    $this->sqlquery = "DELETE FROM `".$this->db_tablname."`
                        WHERE `".$this->db_tablname."`.`lfd` = \"".$lfd."\"
                        LIMIT 1;";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
  }


/*******************************************************************************
   Funktion: liste_kategorien ()
********************************************************************************/
  function liste_kategorien (){
    include ("../config.inc.php");
    $this->lese_kategorien (); // hole die Liste der Kategorien
    if ($this->result != NULL) {
      if (debug) print_r ($this->result);
      // Tabelle bauen
      echo "<FORM action=\"".$_SERVER['PHP_SELF']."\" method=\"get\" target=\"_self\">\n";

      echo "<TABLE border=\"1\" cellspacing=\"5\" cellpeding=\"50\">\n";
      echo "<THEAD>\n";
      echo "<TR>\n";
      echo "<TH>Kategorie</TH>\n";
      echo "<TH>Beschreibung</TH>\n";
      echo "<TH>Aktion</TH>\n";
      echo "</TR>\n";
      echo "</THEAD>\n";
      echo "<TBODY>\n";
      for ($i=1; $this->result[$i]!= NULL; $i++){
        echo "<TR>\n";
        echo "<TD>\n";
        echo "<big>".$this->result[$i][kategorie]."</big>\n";
        echo "</TD>\n";
        echo "<TD>\n";
        echo "<big>".$this->result[$i][beschreibung]."</big>\n";
        echo "</TD>\n";
        echo "<TD>\n";
          echo "<TABLE>";
          echo "<TBODY>";
          echo "<TR>";
          echo "<TD align=\"center\">";
          echo "<a href=\"".$_SERVER['PHP_SELF']."?kate_todo=editrecord&lfd=".$this->result[$i][lfd]."&dbtyp=".$this->dbtyp."\">";
          echo "<img class=\"icon\" width=\"16\" height=\"16\" src=\"".$conf_design_path."/112.png\" alt=\"editieren\" title=\"Edit\" border=\"0\" /></a>";
          echo "</TD>";
          echo "<TD align=\"center\">";
          echo "<a href=\"".$_SERVER['PHP_SELF']."?kate_todo=deleterecord&lfd=".$this->result[$i][lfd]."&dbtyp=".$this->dbtyp."\">";
          echo "<img class=\"icon\" width=\"16\" height=\"16\" src=\"".$conf_design_path."/113.png\" alt=\"l&ouml;schen\" title=\"L&ouml;schen\" border=\"0\" /></a>";
          echo "</TD>";
          echo "</TR>";
          echo "</TBODY>";
          echo "</TABLE>";
        echo "</TD>";
        echo "</TR>\n";
      }
      echo "</TBODY>\n";
      echo "</TABLE>\n";
      echo "</FORM>\n";
    }

  }

/*******************************************************************************
   Funktion: zeige_kategorien ()
********************************************************************************/
  function zeige_kategorien ($lfd){
    include ("../config.inc.php");
    $this->db_get ($lfd); // hole die Liste der Kategorien

    if ($this->result != NULL) {

      echo "<td style=\"width: 90px; background-color: ".$this->bg[9].";\">\n";
          echo "<div style=\"text-align: center; font-size:24px; font-weight:900;\"><big><big><b>";
          echo $this->result["kategorie"];
          echo "</b></big></big></div>";
    }
  }

/*******************************************************************************
   Funktion: pulldown_kategorien ()
********************************************************************************/
  function pulldown_kategorien ($katego_no, $mit_leer){
    include ("../config.inc.php");

    $this->lese_kategorien (); // hole die Liste der Kategorien

    if ($this->result != NULL) {
//      if (debug) print_r ($this->result);
      echo "\n<select ".$param." name=\"kategorien_".$this->dbtyp."\">\n";
      if ($mit_leer) {
        if ($katego_no == "") {$sel = " selected ";} else {$sel = "";}
        echo "<option ".$sel.">  </option>\n";
      }
      foreach ($this->result as $katego){
        if ($katego_no == $katego["lfd"]) {$sel = " selected ";} else {$sel = "";}
        echo "<option ".$sel.">".$katego["kategorie"]."</option>\n";
      }
      echo "</select>\n";
    }
  }

/*******************************************************************************\

\*******************************************************************************/
  function eingabezeile ($posttask, $lfd, $kategorie, $beschreibung){
    include ("../config.inc.php");
    echo "<FORM action=\"".$_SERVER['PHP_SELF']."\" method=\"get\" target=\"_self\">\n";
    echo "<TABLE border=\"1\" cellspacing=\"5\" cellpeding=\"5\">\n";
    echo "<THEAD>\n";
    echo "<TR>\n";
    echo "<TH>Kategorie</TH>\n";
    echo "<TH>Beschreibung</TH>\n";
    echo "<TH>Aktion</TH>\n";
    echo "</TR>\n";
    echo "</THEAD>\n";
    echo "<TBODY>";
    echo "<TR>\n";
    echo "<TD>\n";
    echo "<input type=\"hidden\" name=\"kate_todo\" value=\"".$posttask."\">\n";
    echo "<input type=\"hidden\" name=\"kate_tbl\" value=\"".$this->db_tablname."\">\n";
    echo "<input type=\"hidden\" name=\"kate_dbtbl\" value=\"".$this->dbtyp."\">\n";
    echo "<input type=\"hidden\" name=\"lfd\" value=\"".$lfd."\">\n";

    echo "<input style=\"font-size:16px; font-weight:900;\" maxlength=\"10\" size=\"10\" name=\"kategorie\" value=\"".$kategorie."\">\n";
    echo "</TD>\n";
    echo "<TD>\n";
    echo "<input style=\"font-size:16px; font-weight:900;\" maxlength=\"254\" size=\"50\" name=\"beschreibung\" value=\"".$beschreibung."\">\n";
    echo "</TD>\n";
    echo "<TD align=\"center\">";
    echo "<input type=\"image\" name=\"katego_absenden\" src=\"".$conf_design_path."/120.gif\" alt=\"OK\">\n";
    echo "<input type=\"image\" name=\"katego_abbrechen\" src=\"".$conf_design_path."/001.jpg\" alt=\"Abbrechen\">\n";
    echo "</TD>";
    echo "</TR>\n";
    echo "</TBODY>\n";
    echo "</TABLE>\n";
    echo "</FORM>";

  }


/******************************************************************************\

    ?kate_todo=
       neu      &$_GET["kategorie"]
                 $_GET["beschreibung"]

       delete   &$_GET["lfd"]

       edit     &$_GET["lfd"]

       update   &$_GET ["lfd"], $_GET ["kategorie"], $_GET ["beschreibung"]

       pulldown

       zeige   &lfd=

\******************************************************************************/
  function kategorie_menue ($todo,
                            $katego_lfd,
                            $kategorie,
                            $beschreibung
                             ){
    if (isset ($todo)){
      switch ( $todo ){
        case "liste": // Liste der Kategorien und Eingabemöglichkeit einer neuen
            $this->liste_kategorien ();
            $this->eingabezeile ("neu","","","");

        break;

        case "neu":
            // INSERT
            if ( $kategorie != "") {
              $this->db_neu($kategorie, $beschreibung );
            }
            if (!debug) header("Location: ".$_SERVER['PHP_SELF']);
        break;
        case "deleterecord":
            $this->db_delete( $katego_lfd );
//            if (!debug) header("Location: ".$_SERVER['PHP_SELF']);
        break;
        case "editrecord":
          $this->db_get ( $katego_lfd );

          $this->eingabezeile ("update",
                                   $this->result ["lfd"],
                                   $this->result ["kategorie"],
                                   $this->result ["beschreibung"]);
        break;
        case "update":
          $this->db_aendern ($katego_lfd, $kategorie, $beschreibung );
//          if (!debug) header("Location: ".$_SERVER['PHP_SELF']);
        break;
        case "pulldown":
          $this->pulldown_kategorien ("zeigen");
        break;
        case "zeige":
          $this->zeige_kategorien ( $katego_lfd );
        break;

      } // switch
    }
  }



/*******************************************************************************
   Funktion: dblk_neu()
   INSERT INTO nv_masterkategolink
SET `msg` = "3", `katego` = (

SELECT lfd FROM nv_masterkatego WHERE nv_masterkatego.kategorie = "EA1")
********************************************************************************/
  function dblk_neu ($msg_no, $kategorie){
    $this->sqlquery = "INSERT INTO `".$this->db_tablnamelk."`
                       SET `msg`    = \"".$msg_no."\",
                           `katego` = (
                       SELECT `lfd` FROM `".$this->db_tablname."`
                       WHERE `".$this->db_tablname."`.`kategorie` = \"".$kategorie."\");";

if (debug) echo "QUERY dblk_neu=".$this->sqlquery."<br>";

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>460-".mysql_error()." ".mysql_errno());
  }

/*******************************************************************************
   Funktion: dblk_aendern ()
********************************************************************************/
  function dblk_aendern ($msg_no, $kategorie ){
    $this->sqlquery = "UPDATE ".$this->db_tablnamelk."
                    SET   `katego` = (
                       SELECT `lfd` FROM `".$this->db_tablname."`
                       WHERE `".$this->db_tablname."`.`kategorie` = \"".$kategorie."\")
                    WHERE `msg` = \"".$msg_no."\";";

if (debug) {echo "<br><br>QUERY====="; var_dump ($this->sqlquery);echo "<br><br>";}

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
  }

/*******************************************************************************
   Funktion: dblk_aendern ()
   DELETE FROM `estab_25082008`.`nv_masterkategolink` WHERE `nv_masterkategolink`.`msg` = 1 LIMIT 1
********************************************************************************/
  function dblk_loeschen ( $msg_no ){
    $this->sqlquery = "DELETE FROM `".$this->db_tablnamelk."`
                        WHERE `msg` = \"".$msg_no."\";";

if (debug) {echo "<br><br>QUERY==="; var_dump ($this->sqlquery);echo "<br><br>";}

    $query_result = mysql_query ($this->sqlquery, $this->db_hndl) or
       die("[query_table] <br>$this->sqlquery<br>103-".mysql_error()." ".mysql_errno());
  }

} // class kategorien

?>
