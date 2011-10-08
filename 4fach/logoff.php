<?php
/**********************************************************************************\
  Das Skript wird per include beim Abmelden ausgeführt.
\**********************************************************************************/
define ("debug", true);


if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}

if (debug){
  error_reporting(E_ALL ^ E_NOTICE);
} else {
  error_reporting(E_ERROR | E_WARNING);
}


  function check_sichter_logout (){

    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                         $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
    $query = "SELECT `00_lfd`,`07_durchspruch`,
                     `08_befhinweis`,
                     `08_befhinwausw`,
                     `09_vorrangstufe`,
                     `10_anschrift`,
                     `12_abfzeit`,
                     `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
              WHERE ( (  `15_quitdatum`    = 0 ) AND
                      (  `15_quitzeichen`  = 0 ) )  AND

                    ( (  `04_richtung`     =\"E\") OR
                      (  `03_datum`       != 0 ) AND
                      (  `03_zeichen`     != \"\" ) )
              order by `09_vorrangstufe` DESC, `12_abfzeit`; ";

    if (debug) {echo "<br>QUERY ===".$query;  echo "<br>";}

    $result = $dbaccess->query_table ($query);

    if (($result != NULL) && (debug)) {
      echo "<br>RESULT ===";  print_r ($result); echo "<br>";
    }

    if (($result != NULL) && (debug)){
      echo "<table style=\"text-align: center; background-color: rgb(255, 255, 255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
      echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
      echo "<td>ZEIT</td>\n";
      echo "<td>Vorst</td>\n";
      echo "<td>Anschrift</td>\n";
      echo "<td>Inhalt / Text</td>\n";
      echo "</tr>";
      foreach ($result as $row){
       if ( ( $row["09_vorrangstufe"] != "" ) and ($row["09_vorrangstufe"] != "eee")){
          echo "<tr style=\"background-color: rgb(220,0,0); color:#FFFFFF; font-weight:bold;\">\n";
       }
       $abfzeit = convdatetimeto ($row["12_abfzeit"]);
       echo "<td>"; if (($row["12_abfzeit"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit[stak]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
       echo "<td>"; if (($row["09_vorrangstufe"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
       echo "<td>"; if (($row["10_anschrift"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
       echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a href=\"mainindex.php?sichter=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
       echo "</tr>";
      } // foreach result row
      echo "</tbody></table>";
    } else {// if isset $result
//      echo "<big><big><big>LOGOFF - nothing to do</big></big></big>";
    }
  }

  check_sichter_logout ();
//exit;
?>
