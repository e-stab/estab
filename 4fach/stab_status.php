<?php

define ("debug",false);

class stab_status {

/**************************************************************************\
  Aktive Benutzer in der Benutzertabelle
\**************************************************************************/
  function get_aktiv_user ($fkt){
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );

    $query = "SELECT ".$conf_4f_tbl ["benutzer"].".`kuerzel`,".$conf_4f_tbl ["benutzer"].".`benutzer`".
                      " FROM ".$conf_4f_tbl ["benutzer"].
                      " WHERE ((".$conf_4f_tbl ["benutzer"].".`aktiv` = 1) ".
                      " AND (".$conf_4f_tbl ["benutzer"].".`funktion` = \"".$fkt."\")) ;";

    if ( debug == true ){  echo "<br>QUERY [get_list] =".$query."<br>";echo "<br>";}
    $tmp = $dbaccess->query_table ($query);
    return ($tmp);
  }


/**************************************************************************\
  Anzahl aller Nachrichten pro Funktion
\**************************************************************************/
  function get_all ($fkt){
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
    $query_where_arg1 = "( `16_empf` like \"%".$fkt."%\" )";
    $query_from_arg   = $conf_4f_tbl ["nachrichten"]; //.", ".$tblusername."_read , ".$tblusername."_erl ";
    $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
                  $query_where_arg1.";" ;
    if ( debug == true ){  echo "<br>QUERYCOUNT [get_list] =".$querycount."<br>";echo "<br>";}
    $tmp = $dbaccess->query_table_wert ($querycount);
    return ($tmp[0]);
  }


/**************************************************************************\
  Anzahl aller Nachrichten pro Funktion
\**************************************************************************/
  function get_all_gelesen ($fkt, $kzl){
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
  echo "get_all_gelesen = ".$fkt." kzl= ".$kzl."#<br>";
    $tblusername   = $conf_4f_tbl ["usrtblprefix"].strtolower ($fkt).
                     "_".strtolower ($kzl);

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
    $query_where_arg1 = "( `16_empf` like \"%".$fkt."%\" )";

    $query_where_arg2 = " (`".$conf_4f_tbl ["nachrichten"]."`.`04_nummer` ".$readwhat." IN
                          ( select `".$tblusername."_read`.`nachnum` from `".$tblusername."_read` where 1))";


    $query_from_arg   = $conf_4f_tbl ["nachrichten"]; //.", ".$tblusername."_read , ".$tblusername."_erl ";

    $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
                  $query_where_arg1." AND ".$query_where_arg2.";" ;

    if ( debug == true ){  echo "<br>QUERYCOUNT [get_list] =".$querycount."<br>";echo "<br>";}

    $tmp = $dbaccess->query_table_wert ($querycount);
    return ($tmp[0]);
  }


/**************************************************************************\
  Anzahl aller Nachrichten pro Funktion
\**************************************************************************/
  function get_all_erledigt ($fkt, $kzl){
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
    $query_where_arg1 = "( `16_empf` like \"%".$fkt."%\" )";
    $query_from_arg   = $conf_4f_tbl ["nachrichten"]; //.", ".$tblusername."_read , ".$tblusername."_erl ";
    $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
                  $query_where_arg1.";" ;
    if ( debug == true ){  echo "<br>QUERYCOUNT [get_list] =".$querycount."<br>";echo "<br>";}
    $tmp = $dbaccess->query_table_wert ($querycount);
    return ($tmp[0]);
  }



  function get_list (){
    echo "\n\n\n<!-- ANFANG file:stab_status.php fkt:createlist -->";
    include ("../config.inc.php");
    include ("../para.inc.php");
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    include ("../fkt_rolle.inc.php");

    $tblusername   = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"]).
                     "_".strtolower ($_SESSION["vStab_kuerzel"]);

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
    $query_select_arg = $conf_4f_tbl ["nachrichten"].".`00_lfd`, ".
                        $conf_4f_tbl ["nachrichten"].".`09_vorrangstufe`, ".
                        $conf_4f_tbl ["nachrichten"].".`04_richtung`, ".
                        $conf_4f_tbl ["nachrichten"].".`04_nummer`, ".
                        $conf_4f_tbl ["nachrichten"].".`10_anschrift`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_abfzeit`, ".
                        $conf_4f_tbl ["nachrichten"].".`12_inhalt`, ".
                        $conf_4f_tbl ["nachrichten"].".`13_abseinheit`, ".
                        $conf_4f_tbl ["nachrichten"].".`14_funktion`, ".
                        $conf_4f_tbl ["nachrichten"].".`16_empf`, ".
                        $conf_4f_tbl ["nachrichten"].".`X00_status`, ".
                        $conf_4f_tbl ["nachrichten"].".`x01_abschluss` ";

    $query_from_arg   = $conf_4f_tbl ["nachrichten"]; //.", ".$tblusername."_read , ".$tblusername."_erl ";

    $query_where_arg1 = "(( `16_empf` like \"%".$_SESSION["vStab_funktion"]."%\" ) OR
                          ( `16_empf` like \"%alle%\" ))";

//    if ($_SESSION [filter_gelesen]  != 1){$readwhat = " NOT ";} else {$readwhat = " ";}

    if ($_SESSION [filter_erledigt] != 1){$donewhat = " NOT ";} else {$donewhat = " ";}


    if ($_SESSION["filter_darstellung"] == "1" ){
      if ($_SESSION [filter_gelesen]  == 1){
        $query_where_arg2 = " AND (`".$conf_4f_tbl ["nachrichten"]."`.`04_nummer` ".$readwhat." IN
                              ( select `".$tblusername."_read`.`nachnum` from `".$tblusername."_read` where 1))";
      } else {
        $query_where_arg2 = "";
      }
      $query_where_arg3 = " AND (`".$conf_4f_tbl ["nachrichten"]."`.`04_nummer` ".$donewhat." IN
                          ( select `".$tblusername."_erl`.`nachnum` from `".$tblusername."_erl` where 1))";
    } else {
     $query_where_arg2 = "";
    }

    $query_orderby_arg = "`12_abfzeit` DESC, `09_vorrangstufe` DESC ";

    if (isset ($_SESSION["flt_search"])) {
      $query_search = "(".
          "(".$conf_4f_tbl ["nachrichten"].".`04_nummer` LIKE \"%".$_SESSION["flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`10_anschrift` LIKE \"%".$_SESSION["flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_abfzeit` LIKE \"%".$_SESSION["flt_search"]."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`12_inhalt` LIKE \"%".htmlentities ($_SESSION["flt_search"])."%\") OR ".
          "(".$conf_4f_tbl ["nachrichten"].".`13_abseinheit` LIKE \"%".$_SESSION["flt_search"]."%\") )";


      $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." AND ".$query_search.";" ;

      $query = "SELECT ".$query_select_arg." FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." AND ".$query_search." ORDER BY ".$query_orderby_arg ;

//      unset ($_SESSION["flt_search"]);

    } else {
      $query_search = "";
      $querycount = "SELECT COUNT(*) FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." ".$query_where_arg2." ".$query_where_arg3.";" ;

      $query = "SELECT ".$query_select_arg." FROM ".$query_from_arg." WHERE ".
               $query_where_arg1." ".$query_where_arg2." ".$query_where_arg3." ORDER BY ".$query_orderby_arg ;



    }


    if ( debug == true ){  echo "<br><br>QUERYCOUNT [get_list] =".$querycount."<br>";echo "<br><br>";}

    if ( $_SESSION["filter_darstellung"] == "1" ){
      $tmp = $dbaccess->query_table_wert ($querycount);
      $anzahl = $tmp[0];

      if ( debug == true ){ echo "<br>ANZAHL ===".$anzahl."<br>";}

      if (isset($_SESSION[flt_navi])) {

        switch ($_SESSION[flt_navi]) {
           // ANFANG
          case "start":
                  $_SESSION["filter_start"] = 0;
          break;
           // Eine Seite zurück
          case "back":
                  $_SESSION["filter_start"] -= $_SESSION[filter_anzahl];
                  if ($_SESSION["filter_start"] < 0){
                    $_SESSION["filter_start"]=0;}
          break;
           // Eine Seite vor
          case "for":
                  if ($anzahl < $_SESSION[filter_anzahl]){ $_SESSION[filter_start] = 0;
                  } else {
                    $_SESSION["filter_start"] += $_SESSION[filter_anzahl];
                    if ($_SESSION["filter_start"] >= $anzahl){
                      $_SESSION["filter_start"] = $anzahl-1;}
                  }
          break;
          // Letzte Seite
          case "end":
                  if ($anzahl < $_SESSION[filter_anzahl]){ $_SESSION[filter_start] = 0;
                  } else {
                    $seiten = floor ($anzahl / $_SESSION[filter_anzahl])-1 ;
                    $_SESSION["filter_start"] = $seiten * $_SESSION["filter_anzahl"];
                  }
          break;
        }
        unset ($_SESSION [flt_navi]);
      }
      $query .= " LIMIT ".$_SESSION["filter_start"].",".$_SESSION["filter_anzahl"];
    }


    $query = $query_select.$query;

    if ( debug == true ){  echo "QUERY [get_list] =".$query."<br>";echo "<br><br>";}

    $result = $dbaccess->query_table ($query);

//    if ( debug == true ){ echo "RESULT [get_list] ="; var_dump ($result); echo "<br><br>"; }

    return ($result[0]);

  }
}// class

  $status = new  stab_status ;
    include ("../config.inc.php");
    include ("../db_operation.php");
    include ("../para.inc.php");
    include ("../dbcfg.inc.php"); include ("../e_cfg.inc.php");
    include ("../fkt_rolle.inc.php");



  foreach ($conf_empf as $conf_fkt){

    if (($conf_fkt["rolle"] =="Stab" OR $conf_fkt["rolle"] =="FB") and $conf_fkt["fkt"] != "Si"){
      $logedon_user = $status->get_aktiv_user ($conf_fkt["fkt"]) ;
      if ($logedon_user != ""){
        echo "logedon_user ==="; var_dump ($logedon_user); echo "<br>";

        echo "ERGEBNIS für ".$conf_fkt["fkt"]."=".$status->get_all ($conf_fkt["fkt"], $logedon_user["kuerzel"]).
             "====".$status->get_all_gelesen ($conf_fkt["fkt"], $logedon_user[1]["kuerzel"])."<br><br>";
      }
    }
  }



?>
