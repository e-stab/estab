<?php

/*****************************************************************************\
   Datei: liste.php

   benötigte Dateien:

   Beschreibung:



   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

class Listen {
/******************************************************************************\
   $welche ~= Art der Liste die Ausggeben werden soll. Mï¿½lich sind:
     FMA    - Fernmeldeausgangsliste
     STUSER - Stabbenutzer
     STSI   - Stab Sichter
     FMNWE  - Fernmelde Nachweis Eingang
     FMNWA  - Fernmelde Nachweis Ausgang
     ADMIN  - Administrative Liste
\******************************************************************************/

  var $listenart;
  var $benutzer;

  // Listengestaltung

  function listen ($welche, $user){
    $this->listenart = $welche;
    $this->benutzer  = $user;
//    echo "listenart =".$this->listenart."- benutzer = ".$this->benutzer."<br>";
  }


  function createlist (){
    include ("../config.inc.php");
    switch ($this->listenart){

      case "FMA":           /***** F M A ****/
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`07_durchspruch`, `08_befhinweis`, `08_befhinwausw`,`09_vorrangstufe`, `10_anschrift`, `12_abfzeit`, `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE ((`04_richtung` = \"A\") AND (`03_datum` = 0) AND (`03_zeichen` = \"\")) order by 09_vorrangstufe DESC; ";

        $result = $dbaccess->query_table ($query);


        pre_html ("U_Liste","FMA ".$conf_4f ["NameVersion"]); // Normaler Seitenaufbau mit Auffrischung

        echo "<style type=\"text/css\">";
        echo "body { font-family:Arial,sans-serif; }";

        echo "a:link { color:#000000; text-decoration:none; font-weight:bold; }";
        echo "a:visited { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }";
        echo "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
        echo "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";

        echo "</style>";


        echo "<big><big><big>Nachrichten im Ausgang zur Beförderung!</big></big></big>";

        if ($result != "" ){

          echo "<table style=\"text-align: center; background-color: rgb(255, 255, 255); \" border=\"1\" cellpadding=\"10\" cellspacing=\"1\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(0,0,0); color:#FFFFFF; font-weight:bold;\">\n";
          echo "<td>ZEIT</td>\n";
          echo "<td>Vorst</td>\n";
          echo "<td>Anschr</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";

          foreach ($result as $row){
  //          var_dump ($row); echo "<br><br>";

           if ( ( $row["09_vorrangstufe"] != "" ) and ($row["09_vorrangstufe"] != "eee")){
              echo "<tr style=\"background-color: rgb(220,0,0); color:#FFFFFF; font-weight:bold;\">\n";
           }
           $abfzeit = convdatetimeto ($row["12_abfzeit"]);
           echo "<td>"; if (($row["12_abfzeit"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit["stak"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td>"; if (($row["09_vorrangstufe"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td>"; if (($row["10_anschrift"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a href=\"mainindex.php?fm=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
           echo "</tr>";
        }
        }// if isset $result
        echo "</tbody></table>";
      break;


      case "Stab_lesen":  // ******  S T A B    l e s e n *****

        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );

        $query = "SELECT `00_lfd`,
                         `09_vorrangstufe`,
                         `04_richtung`,
                         `04_nummer`,
                         `10_anschrift`,
                         `12_abfzeit`,
                         `12_inhalt`,
                         `13_abseinheit`,
                         `14_funktion`,
                         `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE ( ( `16_empf` like \"%".$_SESSION["vStab_funktion"]."%\" ) OR
                          ( `16_empf` like \"%alle%\" ) )
                  ORDER BY `12_abfzeit` DESC, `09_vorrangstufe` DESC ; ";

// echo "query=".$query."<br>";
        $result = $dbaccess->query_table ($query);

        pre_html ("U_Liste","Stab lesen ".$conf_4f ["NameVersion"]); // Normaler Seitenaufbau mit Auffrischung

        echo "<style type=\"text/css\">";
        echo "body { font-family:Arial,sans-serif; }";

        echo "a:link { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:visited { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }";
        echo "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
        echo "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";

        echo "</style>";

        echo "<big><big><big>Nachrichten im Eingang!</big></big></big>";

        if  ($result != "") {

          echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
          echo "<td align=\"center\">";
          echo "<p><img src=\"".$conf_design_path."020.png\" alt=\"gelesen\"></p>";
          echo "</td>\n"; // schon gelesen ?
          echo "<td align=\"center\">";
          echo "<p><img src=\"".$conf_design_path."023.png\" alt=\"gelesen\"></p>";
          echo "</td>\n"; // Eigene Meldungen !!!
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Num</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";

          foreach ($result as $row){
             // Muss die Zeile farblich unterlegt werden ?
             // Schon komplett transportiert?
             if ( $row["x01_abschluss"] == "f" ){
                echo "<tr style=\"background-color: rgb(240,200,200); color:#FFFFFF; font-weight:bold;\">\n";
             }
             // Liegt eine Vorrangstufe vor!!!
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ){
                echo "<tr style=\"background-color: rgb(0,0,0); color:#FFFFFF; font-weight:bold;\">\n";
             }

             $query = "SELECT count(*) FROM $tblusername WHERE `nachnum` = ".$row["00_lfd"].";";
//         echo "query(legere_nuntium)=".$query."<br>";
             $result = $dbaccess->query_table_wert ($query);
//         echo "queryresult=";var_dump ($result); echo "<br>";
             if ( $result [0] == 1 ){
               echo "<td align=\"center\">";
               echo "<p><img src=\"".$conf_design_path."021.png\" alt=\"gelesen\"></p>";
//               echo "<big>R</big>";
               echo "</td>\n";

             } else {
               echo "<td align=\"center\">";
               echo "<p><img src=\"".$conf_design_path."null.gif\" alt=\"lesen\"></p>";
               echo "</td>\n";
             }

             // Eigene Nachrichten?
             if ($_SESSION ["vStab_funktion"] == $row ["14_funktion"]){
               echo "<td align=\"center\">";
               echo "<p><img src=\"".$conf_design_path."022.png\" alt=\"gelesen\"></p>";
//               echo "<big>R</big>";
               echo "</td>\n";

             } else {
               echo "<td align=\"center\">";
               echo "<p><img src=\"".$conf_design_path."null.gif\" alt=\"fremd\"></p>";
               echo "</td>\n";
             }


             echo "<td>";
              // Vorrangstufe
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
               echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ;
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
             echo "</td>\n";
              // Eingang / Ausgang
             echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_richtung"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
              // Nachweisnummer
             echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_nummer"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
              // Muss der Absender oder die Absendende Einheit unter von / an
             if ($row["04_richtung"] == "A" ) {
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
             } else {
               echo "<td>";
               if (($row["13_abseinheit"] != "")) {
                 echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\"><big>".$row["13_abseinheit"]."</big></a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
             }
             echo "<td>";

             if (($row["12_abfzeit"] != "")) {
               $arr    = convdatetimeto ($row["12_abfzeit"]);
               $abzeit = $arr [stak];
               echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\"><big>".$abzeit."</big></a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               echo "<a href=\"mainindex.php?stab=meldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\"><big>".$row["12_inhalt"]."</big></a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             echo "</tr>";

          }  // foreach $result
        }
        echo "</tbody></table>";
      break;


      case "Stab_sichten":   /*********** S t a b   s i c h t e n ************/
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

        $result = $dbaccess->query_table ($query);

        pre_html ("U_Liste","FMA ".$conf_4f ["NameVersion"]); // Normaler Seitenaufbau mit Auffrischung

        echo "<style type=\"text/css\">";
        echo "body { font-family:Arial,sans-serif; }";

        echo "a:link { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:visited { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }";
        echo "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
        echo "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";

        echo "</style>";

        echo "<big><big><big>Nachrichten zur Sichtung!</big></big></big>";
        if ($result != "" ){
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
        }// if isset $result
        echo "</tbody></table>";
      break;


      case "FMADMIN":  // ***************  FERNMELDER ADMINISTRATOR  *********************
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`04_richtung`,`04_nummer`,`09_vorrangstufe`,`10_anschrift`, `12_abfzeit`,`13_abseinheit`, `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
                               WHERE 1 order by 12_abfzeit DESC, 09_vorrangstufe DESC ; ";          //

        $result = $dbaccess->query_table ($query);


        pre_html ("U_Liste","FM-Admin ".$conf_4f ["NameVersion"]); // Normaler Seitenaufbau mit Auffrischung

        echo "<style type=\"text/css\">";
        echo "body { font-family:Arial,sans-serif; }";

        echo "a:link { color:#000000; text-decoration:none; font-weight:bold; }";
        echo "a:visited { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }";
        echo "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
        echo "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";

        echo "</style>";


        echo "<big><big><big>Alle Nachrichten! </big></big></big>";
        if  ($result != ""){
          echo "<table style=\"text-align: center; background-color: rgb(250,200, 250); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(250,250,0); color:fm=meldung&0000FF; font-weight:bold;\">\n";
          echo "<td>Vorst</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Nw-Nr.</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";

          foreach ($result as $row){
//              var_dump ($row); echo "<br><br>";

             // VORRANGSTUFE
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ){
               echo "<tr style=\"background-color: rgb(0,255,0); color:fm=meldung&FFFFFF; font-weight:bold;\">\n";
             }
             echo "<td>";
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
               echo "<a href=\"mainindex.php?fm=FM-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ;
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // RICHTUNG Eingang / Ausgang
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=FM-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_richtung"]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // N a c h w e i s n u m m e r
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=FM-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_nummer"]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             if ($row["04_richtung"] == "A" ) {
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 echo "<a href=\"mainindex.php?fm=FM-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             } else {
               echo "<td>";

             // Absender / Einheit / Stelle / ...
             if (($row["13_abseinheit"] != "")) {
               echo "<a href=\"mainindex.php?fm=FM-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["13_abseinheit"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             // Abfassungs Z E I T
             if (($row["12_abfzeit"] != "")) {
               $abfzeit = convdatetimeto ($row["12_abfzeit"]);
               echo "<a href=\"mainindex.php?fm=FM-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit[stak]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
             // I N H A L T ! substr ( string string, int start [, int length] )
             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               echo "<a href=\"mainindex.php?fm=FM-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".
                      substr($row["12_inhalt"], 0, $conf_4f_liste ["inhalt"])." ..."."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
             echo "</tr>";
          }
        }
        echo "</tbody></table>";
      break;

      case "SIADMIN":  // ***************  SICHTER ADMINISTRATOR  *********************
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`04_richtung`,`04_nummer`,`09_vorrangstufe`,`10_anschrift`, `12_abfzeit`,`13_abseinheit`, `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
                               WHERE 1 order by 12_abfzeit DESC, 09_vorrangstufe DESC ; ";          //

         $result = $dbaccess->query_table ($query);


        pre_html ("U_Liste","FM-Admin ".$conf_4f ["NameVersion"]); // Normaler Seitenaufbau mit Auffrischung

        echo "<style type=\"text/css\">";
        echo "body { font-family:Arial,sans-serif; }";

        echo "a:link { color:#000000; text-decoration:none; font-weight:bold; }";
        echo "a:visited { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:bold; }";
        echo "a:active { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";
        echo "a:focus { color:#0000EE; background-color:#FFFF99; font-weight:bold; }";

        echo "</style>";

        echo "<big><big><big>Alle Nachrichten! </big></big></big>";

        if  ($result != ""){
          echo "<table style=\"text-align: center; background-color: rgb(250,200, 250); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(250,250,0); color:fm=meldung&0000FF; font-weight:bold;\">\n";
          echo "<td>Vorst</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Nw-Nr.</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";

          foreach ($result as $row){
//              var_dump ($row); echo "<br><br>";

             // VORRANGSTUFE
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ){
               echo "<tr style=\"background-color: rgb(0,255,0); color:fm=meldung&FFFFFF; font-weight:bold;\">\n";
             }
             echo "<td>";
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ;
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // RICHTUNG Eingang / Ausgang
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_richtung"]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             // N a c h w e i s n u m m e r
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_nummer"]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";

             if ($row["04_richtung"] == "A" ) {
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             } else {
               echo "<td>";

             // Absender / Einheit / Stelle / ...
             if (($row["13_abseinheit"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["13_abseinheit"]."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             // Abfassungs Z E I T
             if (($row["12_abfzeit"] != "")) {
               $abfzeit = convdatetimeto ($row["12_abfzeit"]);
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit[stak]."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
             // I N H A L T !
             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".
                        $row["00_lfd"]."\" target=\"_self\">".
                        substr($row["12_inhalt"], 0, $conf_4f_liste ["inhalt"])." ..."."</a>\n";
             } else {
               echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
             }
             echo "</td>\n";
             echo "</tr>";
          }
        }
        echo "</tbody></table>";
      break; // case SIADMIN



      case "FmNwE":  // *****  F M N W E ingang ******

        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`09_vorrangstufe`,`04_richtung`, `04_nummer`, `10_anschrift`,
                         `12_abfzeit`, `12_inhalt`, `13_abseinheit`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE 04_richtung = \"E\" order by 04_nummer ASC ; ";
// echo "query=".$query."<br>";
        $result = $dbaccess->query_table ($query);

        pre_html ("N_Liste","Nachweis E ".$conf_4f ["NameVersion"]); // Normaler Seitenaufbau ohne Auffrischung

        echo "<style type=\"text/css\">";
        echo "body { font-family:Arial,sans-serif; }";
        echo "a:link { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:visited { color:#EEAAAA; text-decoration:none; font-weight:bold; }";
        echo "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:normal; }";
        echo "a:active { color:#0000EE; background-color:#FFFF99; font-weight:normal; }";
        echo "a:focus { color:#00AA00; background-color:#FFFF77; font-weight:normal; }";
        echo "</style>";

        echo "<p align=\"center\"><big><big><big><b>Nachweisung Eingang</b></big></big></big></p>";

        if ( $result != "" ){

          echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Num</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ( $result != "" ) {
            foreach ($result as $row){
               echo "<td>";

               if ( ( $row["09_vorrangstufe"] != "") and
                    ( $row["09_vorrangstufe"] != "eee" ) ) {
                 echo "<a>".$row["09_vorrangstufe"]."</a>\n" ;
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".$row["04_richtung"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".$row["04_nummer"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".$row["10_anschrift"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".$row["13_abseinheit"]."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr [stak];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          } // if 2. result == ""
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

      case "FmNwA":  // *****  F M N W A usgang ******

        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,`09_vorrangstufe`,`04_richtung`, `04_nummer`, `10_anschrift`,
                         `12_abfzeit`, `12_inhalt`, `13_abseinheit`, `x01_abschluss`
                  FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE 04_richtung = \"A\" order by 04_nummer ASC ; ";
// echo "query=".$query."<br>";
        $result = $dbaccess->query_table ($query);


        pre_html ("N_Liste","Nachweis A".$conf_4f ["NameVersion"]); // Normaler Seitenaufbau mit Auffrischung

        echo "<style type=\"text/css\">";
        echo "body { font-family:Arial,sans-serif; }";
        echo "a:link { color:#EE0000; text-decoration:none; font-weight:bold; }";
        echo "a:visited { color:#EEAAAA; text-decoration:none; font-weight:bold; }";
        echo "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:normal; }";
        echo "a:active { color:#0000EE; background-color:#FFFF99; font-weight:normal; }";
        echo "a:focus { color:#00AA00; background-color:#FFFF77; font-weight:normal; }";
        echo "</style>";

        echo "<p align=\"center\"><big><big><big><b>Nachweisung Ausgang</b></big></big></big></p>";

        if ( $result != "" ){
          echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
          echo "<tr style=\"background-color: rgb(240,240,200); color:#000000; font-weight:bold;\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Num</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ($result != "") {
            foreach ($result as $row){
               echo "<td>";
               if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
                 echo "<a>".$row["09_vorrangstufe"]."</a>\n" ;
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";}
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".$row["04_richtung"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "<td>"; if (($row["04_nummer"] != "")) { echo "<a>".$row["04_nummer"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".$row["10_anschrift"]."</a>\n"; } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".$row["13_abseinheit"]."</a>\n";
                 } else {
                   echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr [stak];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
               }
               echo "</td>\n";
               echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { echo "<a>".$row["12_inhalt"]."</a>\n";  } else { echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";} echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          }
          echo "</tbody></table>";
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

    } // switch
  }


} // class

?>