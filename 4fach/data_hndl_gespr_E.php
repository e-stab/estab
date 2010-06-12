<?php
/*****************************************************************************\
   Datei: data_hndl.php

   benoetigte Dateien:

   Beschreibung:

   Funktionen:

     check_save_user ()
     check_and_save ($data)
     legere_nuntium ($krzl, $fktn, $lfd);
         ==> Zeit wann die Nachricht gelesen wurde,
         oder auch nicht!
   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

include ("tools.php");
include ("vali_data.php");

/*******************************************************************************
  Benutzeranmeldung Cookies setzen und eintrag in die Datenbank
  1. Sind Cookiedaten vorhanden
     JA   --> Prfe Cookiedaten mit Datenbankeintrï¿½en
          --> Datenabgleich
     NEIN --> Neueintrag Datenbank und COOKIES
********************************************************************************/


/*******************************************************************************\

\*******************************************************************************/
function check_save_user () {
  $error_userlogin = false;



  // Als allererstes Pruefen wir mal die Formulardaten auf Vollstaedigkeit
//echo "check_save_user GET="; var_dump ($_GET);    echo "#<br><br>\n";

  if ( ( $_GET [kuerzel] != "" ) AND ( $_GET [benutzer] != "" ) ) {

    // Daten sind in $_GET vorhanden
    $GETkuerzel = strtoupper ( $_GET ["kuerzel"]);

//    include ("../config.inc.php");
    include ("../dbcfg.inc.php");
     /* Die Daten in der Datenbank vorhanden?
        Also suchen wird erst mal nach dem Krzel in der Datenbank  */
    $dbaccess = new db_access ($conf_4f_db  ["server"],
                               $conf_4f_db  ["datenbank"],
                               $conf_4f_tbl ["benutzer"],
                               $conf_4f_db  ["user"],
                               $conf_4f_db  ["password"] );

    $query = "SELECT * FROM ".$conf_4f_tbl ["benutzer"]." WHERE `kuerzel` LIKE \"".$GETkuerzel."\";";
    // echo "query1=".$query."<br>";
    $result = $dbaccess->query_table ($query);

    if ( ( count ($result) > 0 ) AND ( $result != "" ) ){
//      echo "<big><big>Krzel in der Datenbank vorhanden.</big></big><br>";
        /* OHa - ein Eintrag ist vorhanden
           Zwei Moeglichkeiten -
           1. der Benutzer ist identisch ==> Daten koennen uebernommen werden
           oder
           2. es wird versucht sich unter anderem Namen mit gleichem Krzel anzumelden
               ==> Benutzer muss ein neues Kruezel waehlen.
        */

      $db_result = $result [1];
//echo " Ergebnis aus der Datenbank:"; var_dump ($db_result); echo "<br>";

      $user_eq = ( $_GET["benutzer"] == $db_result ["benutzer"] );
      $kuerzel_eq = ( $GETkuerzel == $db_result ["kuerzel"] );
      $db_gleich  = ( $user_eq  AND $kuerzel_eq );
      $sd_gleich  = ( ( $_SESSION ["vStab_benutzer"] == $db_result [1]["benutzer"] ) AND
                        ( $_SESSION ["vStab_kuerzel"] == $db_result [1]["kuerzel"] ) AND
                        ( $_SESSION ["vStab_funktion"] == $db_result [1]["benutzer"] )  );

      $sid_gleich = ( ( session_id() == $db_result ["sid"] ));
      $ip_gleich   = ( ( $_SERVER [REMOTE_ADDR] == $db_result ["ip"] ));
/*
echo "db_gleich="; var_dump ($db_gleich); echo "<br>";
echo "GET-benutzer=";  var_dump ( $_GET["benutzer"] ); echo "<br>";
echo "result-benutzer=";  var_dump ( $db_result ["benutzer"] ); echo "<br>";
echo "<br>";
echo " sd_gleich="; var_dump ($s_gleich); echo "<br>";
echo "ip_gleich="; var_dump ($ip_gleich); echo "<br>";
*/

  /*************************************************************************************

  **********************************************************************************+++*/
      /* Anzumeldender Benutzer ist gleich mit einem Datenbank Benutzer der angemeldet ist
         ==> Browser ist abgestrzt
         ==> Andere IP Adresse
       */

      if (  $db_gleich  ) {
        /*** Wiederanmeldung ***/
        if ($db_result ["aktiv"] == 1 ){
          $query = "UPDATE ".$conf_4f_tbl ["benutzer"]."
                    SET   `SID` = \"".session_id()."\",
                           `ip` = \"".$_SERVER [REMOTE_ADDR]."\",
                        `aktiv` = \"1\" WHERE `kuerzel` = \"".$GETkuerzel."\";";

          $result = $dbaccess->query_table_iu ($query);
          $_SESSION [menue] = "ROLLE";  // Starte Menue im Rollenmodus
          $rolle = rollenfinder ( $_GET["funktion"] );
          $_SESSION ["vStab_benutzer"] = $_GET["benutzer"];
          $_SESSION ["vStab_kuerzel"]  = $GETkuerzel;
          $_SESSION ["vStab_funktion"] = $_GET["funktion"];
          $_SESSION ["vStab_rolle"]    = $rolle;
          $_SESSION [menue] = "ROLLE";  // Starte Menu im Rollenmodus
          $_SESSION [ROLLE] = $rolle;
          protokolleintrag ("Sessiondaten neu setzen", $_SESSION[vStab_benutzer].";".$_SESSION[vStab_kuerzel].";".$_SESSION[vStab_funktion].";".$_SESSION[vStab_rolle].";".session_id().";".$_SERVER[REMOTE_ADDR]);
        }
        /***
          Wiederanmeldung nach Abmeldung
          g.g. Funktionswechsel ==> neue Datenbank fr Krzel und neuer Funktion ***/
        if ($db_result ["aktiv"] == 0 ){
          $rolle = rollenfinder ( $_GET["funktion"] );
          $query = "UPDATE ".$conf_4f_tbl ["benutzer"]."
                   SET `funktion` = \"".$_GET ["funktion"]."\",
                       `rolle`    = \"".$rolle."\",
                            `SID` = \"".session_id()."\",
                             `ip` = \"".$_SERVER [REMOTE_ADDR]."\",
                          `aktiv` = \"1\" WHERE kuerzel = \"".$GETkuerzel."\";";
          $result = $dbaccess->query_table_iu ($query);
           // Tabelle fr die Benutzerfunktion anlegen
          if ($_GET ["funktion"] != "A/W"){
            $usertablename = $conf_4f_tbl ["usrtblprefix"].$_GET ["funktion"]."_".strtoupper ( $_GET ["kuerzel"]);
            $dbaccess->create_user_table ($usertablename);
          }
          $rolle = rollenfinder ( $_GET["funktion"] );
          $_SESSION [ROLLE] = $rolle;
          $_SESSION ["vStab_benutzer"] = $_GET["benutzer"];
          $_SESSION ["vStab_kuerzel"]  = $GETkuerzel;
          $_SESSION ["vStab_funktion"] = $_GET["funktion"];
          $_SESSION ["vStab_rolle"]    = $rolle;
          $_SESSION ["menue"] = "ROLLE";  // Starte Menu im Rollenmodus
          $_SESSION ["ROLLE"] = $rolle;
          protokolleintrag ("Funktion Ummelden", $_SESSION[vStab_benutzer].";".$_SESSION[vStab_kuerzel].";".$_SESSION[vStab_funktion].";".$_SESSION[vStab_rolle].";".session_id().";".$_SERVER[REMOTE_ADDR]);
        }
      } // $db_gleich
      if ($kuerzel_eq and !$user_eq) {
        // Kürzel in Datenbank vorhanden -- Benutzername passt NICHT dazu !!!
        $infotext = "Kürzel schon vorhanden !!!<br>Benutzername stimmt nicht mit den gespeicherten Daten überein.";
        errorwindow( "Benutzeranmeldung", $infotext );
        $error_userlogin = true;
      }
    }  else { // nicht in der Datenbank
       /**********************************************************************
                 Es sind keine Daten in der Datenbank ==> Neuer Benutzer
                 Setze die Daten im Session Cookie und in der Datenbank.
        **********************************************************************/
      $rolle = rollenfinder ( $_GET["funktion"] );
      $_SESSION ["vStab_benutzer"] = $_GET["benutzer"];
      $_SESSION ["vStab_kuerzel"]  = $GETkuerzel;
      $_SESSION ["vStab_funktion"] = $_GET["funktion"];
      $_SESSION ["vStab_rolle"]    = $rolle;

      $query = "INSERT into ".$conf_4f_tbl ["benutzer"]." SET
                      `benutzer` = \"".$_GET["benutzer"]."\",
                      `kuerzel`  = \"".$GETkuerzel."\",
                      `funktion` = \"".$_GET["funktion"]."\",
                      `rolle`    = \"".$rolle."\",
                      `sid`      = \"".session_id()  ."\",
                      `ip`       = \"".$_SERVER[REMOTE_ADDR]."\",
                      `aktiv`    = \"1\"";

      $result = $dbaccess->query_table_iu ($query);

      protokolleintrag ("Anmelden", $_SESSION[vStab_benutzer].";".$_SESSION[vStab_kuerzel].";".$_SESSION[vStab_funktion].";".$_SESSION[vStab_rolle].";".session_id().";".$_SERVER[REMOTE_ADDR]);

      if ($_SESSION ["vStab_funktion"] != "A/W"){
        $usertablename = $conf_4f_tbl ["usrtblprefix"].$_GET ["funktion"]."_".strtoupper ( $_GET ["kuerzel"]);
//        $usertablename = $conf_4f_tbl ["usrtblprefix"].$_SESSION ["vStab_kuerzel"]."_".$_SESSION ["vStab_funktion"] ;
        $dbaccess->create_user_table ($usertablename);
      }
      $_SESSION [menue] = "ROLLE";  // Starte Menu im Rollenmodus
      $_SESSION [ROLLE] = $rolle;
    }
  }  else {  // if $GET [kuerzel und benutzer] == ""
    $_SESSION [menue] = "LOGIN";
    $infotext = "Keine Daten eingegeben !!!";
    errorwindow( "Benutzeranmeldung", $infotext );
    $error_userlogin = true;
  }
  return ($error_userlogin);
} // function save_user


/*****************************************************************************\

\*****************************************************************************/
function check_and_save ($data){

  include ("../config.inc.php");
  include ("../dbcfg.inc.php");
  include ("../fkt_rolle.inc.php");

  if ($data ["11_gesprnotiz"] == "on") {
    $data ["11_gesprnotiz"] = "t" ;
  }  else {
    $data ["11_gesprnotiz"] = "f" ;
  }



/*
  echo "check_and_save --->   "; var_dump ($data);
  echo "<br><br><br>\n";
  while (list($key, $val) = each($data)) {
     echo "$key => $val  ---> $data[$key]<br>\n";
  }
*/


// Umwandlung Sonderzeichen in HTML Zeichencode
  if ($data ["12_inhalt"] != ""){
    $data ["12_inhalt"] = htmlentities (  $data ["12_inhalt"] ); }
  if ($data ["17_vermerk"] != ""){
    $data ["17_vermerke"] = htmlentities (  $data ["17_vermerke"] ); }
  $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],
                             $conf_4f_db ["password"] );

  switch ($data["task"]){

    case "FM-Eingang":
    case "FM-Eingang_Anhang":
       /*****************************************************************************************************
           Betroffene Felder:
            01_medium            01_datum   TTMM            01_zeit    SSMM            01_zeichen            05_gegenstelle            07_durchspruch;            08_befhinweis;
            08_befhinwausw;            09_vorrangstufe;            10_anschrift;            11_gesprnotiz;            12_inhalt;            12_abfzeit;            13_abseinheit;
            14_zeichen;            14_funktion;
          Workflow ==>
            Ergaenzung Nachweisdaten (E und Nachweisnummer) 04_richtung 04_nummer
            Daten in Datenbank mit einem INSERT
            INSERT INTO tabelle SET spalten_name=ausdruck, spalten_name=ausdruck, ...
      ******************************************************************************************************/


       /*----------------------------------------------------*/

       $vali = new vali_data_form ( $data ) ;
       $result = $vali->checkdata ();
       if (!$result) {
         $form = new nachrichten4fach ($data, $data["task"], "");
         exit ;
       }
       /*----------------------------------------------------*/


       if ($data ["01_datum"] == "" ) {
         $data ["01_datum"] = date ("dm") ;
       }  else {
         $data ["01_datum"] = $data ["01_datum"] ;
       }

       if ($data ["01_zeit"] == "" ) {
         $data ["01_zeit"] = date ("Hi") ;
       }  else {
         $data ["01_zeit"] = $data ["01_zeit"] ;
       }

       if ($data ["12_abfzeit"] == "" ) {
         $data ["12_abfzeit"] = convtodatetime ( date ("dm"),   date ("Hi") )  ;
       }  else {
         $data ["12_abfzeit"] = convtodatetime ( date ("dm"),   $data ["12_abfzeit"]) ;
       }

       $nachweis_E = get_last_nachw_num ("E") + 1;
       $query = "INSERT into `".$conf_4f_tbl ["nachrichten"]."` SET
            `01_medium`       = \"".$data ["01_medium"]      ."\",
            `01_datum`        = \"".convtodatetime ($data ["01_datum"], $data ["01_zeit"]) ."\",
            `01_zeichen`      = \"".$data ["01_zeichen"]     ."\",
            `04_richtung`     = \"E\",
            `04_nummer`       = \"".$nachweis_E              ."\",
            `05_gegenstelle`  = \"".$data ["05_gegenstelle"] ."\",
            `07_durchspruch`  = \"".$data ["07_durchspruch"] ."\",
            `08_befhinweis`   = \"".$data ["08_befhinweis"]  ."\",
            `08_befhinwausw`  = \"".$data ["08_befhinwausw"] ."\",
            `09_vorrangstufe` = \"".$data ["09_vorrangstufe"]."\",
            `10_anschrift`    = \"".$data ["10_anschrift"]   ."\",
            `11_gesprnotiz`   = \"".$data ["11_gesprnotiz"]  ."\",
            `12_anhang`       = \"".$data ["12_anhang"]      ."\",
            `12_inhalt`       = \"".$data ["12_inhalt"]      ."\",
            `12_abfzeit`      = \"".$data ["12_abfzeit"]     ."\",
            `13_abseinheit`   = \"".$data ["13_abseinheit"]  ."\",
            `14_zeichen`      = \"".$data ["14_zeichen"]     ."\",
            `14_funktion`     = \"".$data ["14_funktion"]."\",
    	    `x00_status`      = \"4\",
            `x01_abschluss`   = \"f\"";

// echo "query[FM-Eingang]===".$query."<br>";

        $result = $dbaccess->query_table_iu ($query);
        protokolleintrag ("FM-Eingang",$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);
    break;

    case "FM-Eingang_Sichter":
    case "FM-Eingang_Anhang_Sichter" :

       /*****************************************************************************************************
           Betroffene Felder:
            01_medium            01_datum   TTMM            01_zeit    SSMM            01_zeichen            05_gegenstelle            07_durchspruch;            08_befhinweis;
            08_befhinwausw;            09_vorrangstufe;            10_anschrift;            11_gesprnotiz;            12_inhalt;            12_abfzeit;            13_abseinheit;
            14_zeichen;            14_funktion;             15_quitdatum;          15_quitzeichen;          16_empf;          17_vermerke;
        Workflow ==>
            Ergaenzung Nachweisdaten (E und Nachweisnummer) 04_richtung 04_numme
            Daten in Datenbank mit einem INSERT
            INSERT INTO tabelle SET spalten_name=ausdruck, spalten_name=ausdruck, ...
      ******************************************************************************************************/
       $data ["16_empf"] = $redcopy2."_rt,";

       for (  $i = 1 ; $i <= 5 ; $i++ ){
         for ( $j = 1 ; $j <= 5 ; $j++ ){
           if ( isset ( $data ["16_".$i.$j] ) ) {
             list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           }
           if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }

         }
       }

       if ($data ["01_datum"] == "" ) {
         $data ["01_datum"] = date ("dm") ;
       }  else {
         $data ["01_datum"] = $data ["01_datum"] ;
       }

       if ($data ["01_zeit"] == "" ) {
         $data ["01_zeit"] = date ("Hi") ;
       }  else {
         $data ["01_zeit"] = $data ["01_zeit"] ;
       }

       if ($data ["12_abfzeit"] == "" ) {
         $data ["12_abfzeit"] = convtodatetime ( date ("dm"),   date ("Hi") )  ;
       }  else {
         $data ["12_abfzeit"] = convtodatetime ( date ("dm"),   $data ["12_abfzeit"]) ;
       }

       if ($data ["15_quitdatum"] == "" ) {
         $data ["15_quitdatum"] = convtodatetime ( date ("dm"),   date ("Hi") )  ;
       }  else {
         $data ["15_quitdatum"] = convtodatetime ( date ("dm"),   $data ["15_quitdatum"]) ;
       }

       $nachweis_E = get_last_nachw_num ("E") + 1;
       $query = "INSERT into `".$conf_4f_tbl ["nachrichten"]."` SET
            `01_medium`       = \"".$data ["01_medium"]      ."\",
            `01_datum`        = \"".convtodatetime ($data ["01_datum"], $data ["01_zeit"])."\",
            `01_zeichen`      = \"".$data ["01_zeichen"]     ."\",
            `04_richtung`     = \"E\",
            `04_nummer`       = \"".$nachweis_E              ."\",
            `05_gegenstelle`  = \"".$data ["05_gegenstelle"] ."\",
            `07_durchspruch`  = \"".$data ["07_durchspruch"] ."\",
            `08_befhinweis`   = \"".$data ["08_befhinweis"]  ."\",
            `08_befhinwausw`  = \"".$data ["08_befhinwausw"] ."\",
            `09_vorrangstufe` = \"".$data ["09_vorrangstufe"]."\",
            `10_anschrift`    = \"".$data ["10_anschrift"]   ."\",
            `11_gesprnotiz`   = \"".$data ["11_gesprnotiz"]  ."\",
            `12_anhang`       = \"".$data ["12_anhang"]      ."\",
            `12_inhalt`       = \"".$data ["12_inhalt"]      ."\",
            `12_abfzeit`      = \"".$data ["12_abfzeit"]     ."\",
            `13_abseinheit`   = \"".$data ["13_abseinheit"]  ."\",
            `14_zeichen`      = \"".$data ["14_zeichen"]     ."\",
            `14_funktion`     = \"".$data ["14_funktion"]    ."\",
            `15_quitdatum`    = \"".$data ["15_quitdatum"]   ."\",
            `15_quitzeichen`  =  \"".$data ["15_quitzeichen"]."\",
            `16_empf`         =  \"".$data ["16_empf"]."\",
            `17_vermerke`     =  \"".$data ["17_vermerke"]."\",
            `x00_status`      = \"8\",
            `x01_abschluss`   = \"t\"";

// echo "query[FM-Eingang_Sichter]===".$query."<br>";
       $result = $dbaccess->query_table_iu ($query);
       protokolleintrag ("FM-Eingang-Sichter",$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);
    break;

    case "Stab_schreiben":
/*          07_durchspruch;          08_befhinweis;          08_befhinwausw;          09_vorrangstufe;          10_anschrift;          11_gesprnotiz;          12_inhalt;
          12_abfzeit;          13_abseinheit;          14_zeichen;          14_funktion;
          Workflow ==>
            Ergï¿½zung Nachweisdaten (A und Nachweisnummer) 04_richtung 04_nummer
            Daten in Datenbank mit einem INSERT
            INSERT INTO tabelle SET spalten_name=ausdruck, spalten_name=ausdruck, ...
*/
//       $nachweis_A       = get_last_nachw_num ("A") + 1;
        $datum            = date ("dm");
        if ($data ["12_abfzeit"] == "" ) {
          $data ["12_abfzeit"] = convtodatetime ( date ("dm"),   date ("Hi") )  ;
        }  else {
          $data ["12_abfzeit"] = convtodatetime ( date ("dm"),   $data ["12_abfzeit"]) ;
        }

       if ($data ["11_gesprnotiz"] == "t") {      // == "on"
         $nachweis_E     = get_last_nachw_num ("E") + 1; // E weil Gspraechsnotiz als Eingang
         $data ["16_empf"] = $redcopy2."_rt,".$data ["14_funktion"]."_gn"; // Der Verfasser bekommt den gruenen
         $query = "INSERT into `".$conf_4f_tbl ["nachrichten"]."` SET
              `01_datum`        = \"".convtodatetime ( date ("dm"),   date ("Hi")) ."\",
              `01_zeichen`      = \"".$_SESSION["vStab_kuerzel"]."\",
              `04_nummer`       = \"".$nachweis_E              ."\",
              `04_richtung`     = \"E\",
              `07_durchspruch`  = \"".$data ["07_durchspruch"] ."\",
              `08_befhinweis`   = \"".$data ["08_befhinweis"]  ."\",
              `08_befhinwausw`  = \"".$data ["08_befhinwausw"] ."\",
              `09_vorrangstufe` = \"".$data ["09_vorrangstufe"]."\",
              `10_anschrift`    = \"".$data ["10_anschrift"]   ."\",
              `11_gesprnotiz`   = \"".$data ["11_gesprnotiz"]  ."\",
              `12_anhang`       = \"".$data ["12_anhang"]      ."\",
              `12_inhalt`       = \"".$data ["12_inhalt"]      ."\",
              `12_abfzeit`      = \"".$data ["12_abfzeit"]     ."\",
              `13_abseinheit`   = \"".$data ["13_abseinheit"]  ."\",
              `14_zeichen`      = \"".$data ["14_zeichen"]     ."\",
              `14_funktion`     = \"".$data ["14_funktion"]    ."\",
              `16_empf`         = \"".$data ["16_empf"]        ."\",

              `x00_status`      = \"16\",
              `x01_abschluss`   = \"t\",
              `x02_sperre`      = \"f\",
              `x03_sperruser`   = \"\" ";
       } else {
         $data ["16_empf"] = $redcopy2."_rt,".$data ["14_funktion"]."_gn"; // Der Verfasser bekommt den gruenen
         $gesprnotiz_or_not = "`x00_status`      = \"10\",
                  `04_richtung`     = \"A\",
                  `x01_abschluss`   = \"f\"";
         $nachweis_A       = get_last_nachw_num ("A") + 1;
         $query = "INSERT into `".$conf_4f_tbl ["nachrichten"]."` SET
              `02_zeit`         = \"".convtodatetime ( date ("dm"),   date ("Hi"))     ."\",
              `02_zeichen`      = \"".$_COOKIE["vStab_kuerzel"]."\",
              `04_nummer`       = \"".$nachweis_A              ."\",
              `04_richtung`     = \"A\",
              `07_durchspruch`  = \"".$data ["07_durchspruch"] ."\",
              `08_befhinweis`   = \"".$data ["08_befhinweis"]  ."\",
              `08_befhinwausw`  = \"".$data ["08_befhinwausw"] ."\",
              `09_vorrangstufe` = \"".$data ["09_vorrangstufe"]."\",
              `10_anschrift`    = \"".$data ["10_anschrift"]   ."\",
              `11_gesprnotiz`   = \"".$data ["11_gesprnotiz"]  ."\",
              `12_anhang`       = \"".$data ["12_anhang"]      ."\",
              `12_inhalt`       = \"".$data ["12_inhalt"]      ."\",
              `12_abfzeit`      = \"".$data ["12_abfzeit"]     ."\",
              `13_abseinheit`   = \"".$data ["13_abseinheit"]  ."\",
              `14_zeichen`      = \"".$data ["14_zeichen"]     ."\",
              `14_funktion`     = \"".$data ["14_funktion"]    ."\",
              `16_empf`         = \"".$data ["16_empf"]."\",
              `x00_status`      = \"2\",
              `x01_abschluss`   = \"f\"; ";
       }
//echo "query[Stab schreiben]===".$query."<br>";
       $result = $dbaccess->query_table_iu ($query);
       protokolleintrag ("Stab-schreiben",$query);
    break;

    case "FM-Ausgang":
/*
          02_zeit;      02_zeichen;       03_datum;       03_zeichen;       04_richtung;       04_nummer;         05_gegenstelle
          06_bef_vermerk;        06_bef_ausw;
*/
       if ($data ["03_datum"] == "" ) {
         $data ["03_datum"] = date ("dm") ;
       }  else {
         $data ["03_datum"] = $data ["03_datum"];
       }
       if ($data ["03_zeit"] == "" ) {
         $data ["03_zeit"] = date ("Hi") ;
       }  else {
         $data ["03_zeit"] = $data ["03_zeit"] ;
       }
       $query = "UPDATE `".$conf_4f_tbl ["nachrichten"]."` SET
            `03_datum`        = \"".convtodatetime ($data ["03_datum"], $data ["03_zeit"]) ."\",
            `03_zeichen`      = \"".$data ["03_zeichen"]  ."\",
            `05_gegenstelle`  = \"".$data ["05_gegenstelle"] ."\",
            `06_befweg`       = \"".$data ["06_befweg"]."\",
            `06_befwegausw`   = \"".$data ["06_befwegausw"]   ."\",
            `x00_status`      = \"4\",
            `x01_abschluss`   = \"f\",
            `x02_sperre`      = \"f\",
            `x03_sperruser`   = \"\"
             WHERE `00_lfd` = \"".$data ["00_lfd"]."\"";
//echo "query[FM AUSGANG]===".$query."<br>";
       $result = $dbaccess->query_table_iu ($query);
       protokolleintrag ("FM-Ausgang",$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);
    break;

    case "FM-Ausgang_Sichter":
/*
          02_zeit;      02_zeichen;       03_datum;       03_zeichen;       04_richtung;       04_nummer;         05_gegenstelle
          06_bef_vermerk;        06_bef_ausw;
*/
       $data ["16_empf"] = $redcopy2."_rt,";

       for (  $i = 1 ; $i <= 5 ; $i++ ){
         for ( $j = 1 ; $j <= 5 ; $j++ ){
// echo "2="; var_dump ($empf_matrix); echo "<br><br>IJ = ".$empf_matrix[$i][$j]["fkt"]."<br>";
           if ( isset ( $data ["16_".$i.$j] ) ) {
             list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           } // if
           if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }
         } // for 2.
       } // for 1.

       if ($data ["03_datum"] == "" ) {
         $data ["03_datum"] = date ("dm") ;
       }  else {
         $data ["03_datum"] = $data ["03_datum"];
      }
       if ($data ["03_zeit"] == "" ) {
         $data ["03_zeit"] = date ("Hi") ;
       }  else {
         $data ["03_zeit"] = $data ["03_zeit"] ;
      }
      if ($data ["15_quitdatum"] == "" ) {
         $data ["15_quitdatum"] = convtodatetime ( date ("dm"),   date ("Hi") )  ;
       }  else {
         $data ["15_quitdatum"] = convtodatetime ( date ("dm"),   $data ["15_quitdatum"]) ;
      }
      $query = "UPDATE `".$conf_4f_tbl ["nachrichten"]."` SET
            `03_datum`  = \"".convtodatetime ($data ["03_datum"], $data ["03_zeit"]) ."\",
            `03_zeichen`   = \"".$data ["03_zeichen"]  ."\",
            `05_gegenstelle`  = \"".$data ["05_gegenstelle"] ."\",
            `06_befweg` = \"".$data ["06_befweg"]."\",
            `06_befwegausw`    = \"".$data ["06_befwegausw"]   ."\",
            `15_quitdatum`   = \"".$data ["15_quitdatum"] ."\",
            `15_quitzeichen` =  \"".$data ["15_quitzeichen"]."\",
            `16_empf`          =  \"".$data ["16_empf"]."\",
            `17_vermerke`   =  \"".$data ["17_vermerke"]."\",
	        `x00_status`      = \"8\",
            `x01_abschluss`   = \"t\",
            `x02_sperre`      = \"f\",
            `x03_sperruser`   = \"\"
             WHERE `00_lfd` = \"".$data ["00_lfd"]."\";";
//echo "query[FM AUSGANG Sichter]===".$query."<br>";
       $result = $dbaccess->query_table_iu ($query);
        protokolleintrag ("FM-Ausgang-Sichter",$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);
    break;

   case "Stab_sichten":
/*
          15_quitdatum;
          15_quitzeichen;
          16_empf;
          17_vermerke;
*/
       $data ["16_empf"] = $redcopy2."_rt,";

       for (  $i = 1 ; $i <= 5 ; $i++ ){
         for ( $j = 1 ; $j <= 5 ; $j++ ){
// echo "2="; var_dump ($empf_matrix); echo "<br><br>IJ = ".$empf_matrix[$i][$j]["fkt"]."<br>";
           if ( isset ( $data ["16_".$i.$j] ) ) {
             list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           }
           if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }
         }
       }


       if ($data ["15_quitdatum"] == "" ) {
         $data ["15_quitdatum"] = date ("Hi")  ;
       }  else {
         $data ["15_quitdatum"] = $data ["15_quitdatum"] ;
       }
       $query = "UPDATE `".$conf_4f_tbl ["nachrichten"]."` SET
            `15_quitdatum`   = \"".convtodatetime (date ("dm"), $data ["15_quitdatum"]) ."\",
            `15_quitzeichen` =  \"".$data ["15_quitzeichen"]."\",
            `16_empf`           =  \"".$data ["16_empf"]."\",
            `17_vermerke`   =  \"".$data ["17_vermerke"]."\",
            `x00_status`      = \"8\",
            `x01_abschluss`   = \"t\",
            `x02_sperre`      = \"f\",
            `x03_sperruser`   = \"\"
             WHERE `00_lfd` = \"".$data ["00_lfd"]."\";";
// echo "query[Stab sichten]===".$query."<br>";
       $result = $dbaccess->query_table_iu ($query);
        protokolleintrag ("Stab_sichten",$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);
    break;

    case "Nachweis":
/*
          04_richtung;
          04_nummer;
*/
    break;

    case "FM-Admin":
    case "SI-Admin":
       // Holen wir erst einmal die nicht sichtbaren Datumsangaben
       $query    = "SELECT `01_datum`, `02_zeit`, `03_datum`, `12_abfzeit`, `15_quitdatum`
                    FROM ".$conf_4f_tbl ["nachrichten"]." WHERE `00_lfd` = \"".$data ['00_lfd']."\"; ";
       $result   = $dbaccess->query_table ($query);
       $db_datum = $result [1];
       $convdate ['01_datum']     = convdbdatetimeto ($db_datum ['01_datum']);
       $convdate ['02_zeit']      = convdbdatetimeto ($db_datum ['02_zeit']);
       $convdate ['03_datum']     = convdbdatetimeto ($db_datum ['03_datum']);
       $convdate ['12_abfzeit']   = convdbdatetimeto ($db_datum ['12_abfzeit']);
       $convdate ['15_quitdatum'] = convdbdatetimeto ($db_datum ['15_quitdatum']);
/*
       echo "<br><br>";
       var_dump ($db_datum);
       echo "<br><br>";
       var_dump ($convdate);
       echo "<br><br>";
*/
       $data ["16_empf"] = $redcopy2."_rt,";
       for (  $i = 1 ; $i <= 5 ; $i++ ){
         for ( $j = 1 ; $j <= 5 ; $j++ ){
// echo "2="; var_dump ($empf_matrix); echo "<br><br>IJ = ".$empf_matrix[$i][$j]["fkt"]."<br>";
           if ( isset ( $data ["16_".$i.$j] ) ) {
             list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           }
           if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }
         }
       }



       $query = "UPDATE  `".$conf_4f_tbl ["nachrichten"]."` SET
            `15_quitdatum`    = \"".$convdate ['15_quitdatum']['datum']." ".convtotime ($data ['15_quitdatum']) ."\",
            `15_quitzeichen`  =  \"".$data ["15_quitzeichen"]."\",
            `16_empf`         =  \"".$data ["16_empf"]."\",
            `17_vermerke`     =  \"".$data ["17_vermerke"]."\"
             WHERE `00_lfd` = \"".$data ["00_lfd"]."\"";
//echo "query[FM-Admin]===".$query."<br>";
       $result = $dbaccess->query_table_iu ($query);
       if ($data["task"] == "FM-Admin") {
         protokolleintrag ("++ FM-Admin",$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);
       } else {
         protokolleintrag ("++ SI-Admin",$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);
       }
    break;
  }
}

/*****************************************************************************
 $_SESSION
 [vStab_kuerzel] => LKW
 [vStab_funktion] => S2
 [vStab_rolle] => Stab
 *****************************************************************************/

  function legere_nuntium ($lfd) {

    include ("../config.inc.php");
    include ("../dbcfg.inc.php");
     // Gibt es einen Eintrag zu der Nachricht mit der Nummer $lfd
    $dbaccess = new db_access ($conf_4f_db ["server"],
                               $conf_4f_db ["datenbank"],
                               $conf_4f_tbl ["benutzer"],
                               $conf_4f_db ["user"],
                               $conf_4f_db ["password"] );
    $tblusername   = $conf_4f_tbl ["usrtblprefix"].$_SESSION["vStab_funktion"]."_".$_SESSION["vStab_kuerzel"];
    $query = "SELECT count(*) FROM $tblusername WHERE `nachnum` = $lfd;";
  //  echo "query(legere_nuntium)=".$query."<br>";
    $result = $dbaccess->query_table_wert ($query);
  //  echo "queryresult=";var_dump ($result); echo "<br>";
    return ($result [0]);
  }


/*****************************************************************************
 $_SESSION
 [vStab_kuerzel] => LKW
 [vStab_funktion] => S2
 [vStab_rolle] => Stab
 *****************************************************************************/
  function set_msg_read ($lfd) {

    include ("../config.inc.php");
    include ("../dbcfg.inc.php");
     // Gibt es einen Eintrag zu der Nachricht mit der Nummer $lfd
    $dbaccess = new db_access ($conf_4f_db ["server"],
                               $conf_4f_db ["datenbank"],
                               $conf_4f_tbl ["benutzer"],
                               $conf_4f_db ["user"],
                               $conf_4f_db ["password"] );

    $tblusername   = $conf_4f_tbl ["usrtblprefix"].$_SESSION["vStab_funktion"]."_".$_SESSION["vStab_kuerzel"];
    $query = "SELECT count(*) FROM $tblusername WHERE `nachnum` = $lfd;";
//  echo "query(legere_nuntium)=".$query."<br>";
    $result = $dbaccess->query_table_wert ($query);
//  echo "queryresult=";var_dump ($result); echo "<br>";
    if ($result [0] == 0){
       $query = "INSERT into ".$tblusername." SET
            `nachnum`      = \"".$lfd."\",
            `gelesen`      = \"".convtodatetime (date ("dmY"), date ("Hi"))."\"";

// echo "query[STAB_lesen]===".$query."<br>";

       $result = $dbaccess->query_table_iu ($query);
        protokolleintrag ("Stab_".$_SESSION["vStab_funktion"]." gelesen_".$lfd,$query.";".session_id().";".$_SERVER[REMOTE_ADDR]);

    }
  }
?>