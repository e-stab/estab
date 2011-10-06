<?php
/******************************************************************************\
       Definition der Mitspieler
|-----|-----|-----|-----|
| 1.1 | 2.1 | 3.1 | 4.1 |
| 1.2 | 2.2 | 3.2 | 4.2 |
| 1.3 | 2.3 | 3.3 | 4.3 |
| 1.4 | 2.4 | 3.4 | 4.4 |
| 1.5 | 2.5 | 3.5 | 4.5 |
|-----|-----|-----|-----|
\******************************************************************************/
//              lfd-Nr      PosForm  Fkt
//                       Spalte,Zeile          Roll
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
//    include_once ("../4fach/db_operation.php");

// echo "<h1>fkt_rolle.inc.php</h1>";
/*
    $dbaccess = new db_access ($conf_4f_db  ["server"],
                               $conf_4f_db  ["datenbank"],
                               $conf_4f_tbl ["benutzer"],
                               $conf_4f_db  ["user"],
                               $conf_4f_db  ["password"] );

echo "DBDATA--".$conf_4f_db  ["server"]." - ".$conf_4f_db  ["datenbank"]." - ".
                               $conf_4f_tbl ["benutzer"]." - ".
                               $conf_4f_db  ["user"]." - ".
                               $conf_4f_db  ["password"]."<br>" ;

*/
    $db = mysql_connect($conf_4f_db  ["server"],$conf_4f_db ["user"], $conf_4f_db  ["password"])
       or die ("[query_table]34 Konnte keine Verbindung zur Datenbank herstellen".mysql_error()." ".mysql_errno());

    $db_check = mysql_select_db ($conf_4f_db  ["datenbank"])
       or die ("[query_table]37 Auswahl der Datenbank fehlgeschlagen".mysql_error()." ".mysql_errno());

    $query = "SELECT
                 mtx_x as `m`,
                 mtx_y as `n`,
                 mtx_typ as `typ`,
                 mtx_fkt as `fkt`,
                 mtx_rolle as `rolle`,
                 mtx_mode as `mode`,
                 mtx_rc2 as `redcopy`,
                 mtx_auto as `auto` FROM ".$conf_4f_tbl   ["empfmtx"]." WHERE 1 ;" ;

    $query_result = mysql_query ($query, $db) or
       die("[query_table]50 <br>$query<br>103-".mysql_error()." ".mysql_errno());

    $resultcount = mysql_num_rows($query_result);

    for ($i=1;$i<=$resultcount;$i++){
      $result[$i] = mysql_fetch_assoc($query_result);
    }

    mysql_free_result($query_result);

//    echo "QUERY(fkt_rolle.inc.php)=".$query."<br><br>";

      // Voreinstellung für die leere Tabelle
    for ($i=1; $i<=5; $i++){
      for ($j=1; $j<=4; $j++){
         $empf_matrix [$i][$j] = array(
                           "typ" => "t",
                           "fkt" => "",
                           "rolle" => "leer",
                           "mode" => "ro",
                           "auto" =>  "f");
      }
    }

    if ($result != ""){
      foreach ($result as $fktdata){

// echo "DATA=fktdata=="; var_dump ($fktdata); echo "<br>",

        $empf_matrix [$fktdata["m"]] [$fktdata["n"]] = array(
                       "typ"   => $fktdata["typ"],
                       "fkt"   => $fktdata["fkt"],
                       "rolle" => $fktdata["rolle"],
                       "mode"  => $fktdata["mode"],
                       "auto"  => $fktdata["auto"] ) ;
        if ( ($fktdata["redcopy"] == "t") or
             ($fktdata["redcopy"] == "1")){ $redcopy2 = $fktdata ["fkt"] ;}
      }

      $db = mysql_connect($conf_4f_db  ["server"],$conf_4f_db ["user"], $conf_4f_db  ["password"])
         or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");

      $db_check = mysql_select_db ($conf_4f_db  ["datenbank"])
         or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

      $query = "SELECT
                 mtx_x as `m`,
                 mtx_y as `n`,
                 mtx_typ as `typ`,
                 mtx_fkt as `fkt`,
                 mtx_rolle as `rolle`,
                 mtx_mode as `mode`,
                 mtx_rc2 as `redcopy`,
                 mtx_auto as `auto` FROM ".$conf_4f_tbl   ["empfmtx"]." WHERE 1 ;" ;

      $query_result = mysql_query ($query, $db) or
         die("[query_table] <br>$query<br>103-".mysql_error()." ".mysql_errno());

      $resultcount = mysql_num_rows($query_result);

      for ($i=1;$i<=$resultcount;$i++){
        $result[$i] = mysql_fetch_assoc($query_result);
      }

      mysql_free_result($query_result);

       // Voreinstellung für die leere Tabelle
     $stab = NULL;
     $fb   = Null;
     $i    = 0 ;
     $j    = 0 ;
     foreach ($result as $fktdata){
       if ($fktdata ["fkt"] != "" ) {
         switch ($fktdata["rolle"]){
           case "Stab":
             $stab [$i++] = $fktdata ["fkt"] ;
           break;
           case "FB":
             $fb   [$j++] = $fktdata ["fkt"] ;
           break;
         }
       }
     }

/*
echo "STAB="; print_r ($stab); echo "<br>";
echo "FB  ="; print_r ($fb); echo "<br>";
*/
     if ($stab != NULL) sort ($stab);
     if ($fb   != NULL) sort ($fb);
/*
echo "STAB="; print_r ($stab); echo "<br>";
echo "FB  ="; print_r ($fb); echo "<br>";
*/
     $conf_empf = NULL ;
     $i = 1;
     if ($stab != NULL){
       foreach ($stab as $fktdata){
         $conf_empf [$i++] = array(
                             "fkt"   => $fktdata,
                             "rolle" => "Stab" );
       }
     }
       // Sichter und Fernmelder dazu
     $conf_empf [$i++] = array ("fkt" => "Si",  "rolle" => "Stab" );
     $conf_empf [$i++] = array ("fkt" => "A/W", "rolle" => "Fernmelder" );
     if ($fb != NULL){
       foreach ($fb as $fktdata){
         $conf_empf [$i++] = array(
                             "fkt"   => $fktdata,
                             "rolle" => "FB" );
       }
     }
/*
     foreach ($conf_empf as $fktdata){
       echo "<br>CONF_EMPF_SORTIERT ====="; var_dump ($fktdata); echo "<br>";
     }
*/
   }


//   echo "168 - fkt_rolle.inc.php ==="; var_dump ($empf_matrix); echo "<br><br>";



?>
