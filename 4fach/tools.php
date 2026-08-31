<?php

require_once __DIR__ . "/../app/nv_datetime_group.php";

//define ("debug", false);              // true = gibt debuginformationen aus

/*****************************************************************************\
   Datei: tools.php

   benoetigte Dateien:

   Beschreibung:


   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");
include ("../4fcfg/para.inc.php");
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/file_access.php";
require_once __DIR__ . "/../app/navigation.php";
require_once __DIR__ . "/../app/tabelle.php";
require_once __DIR__ . "/../app/self_registration.php";
require_once __DIR__ . "/../app/session_ui.php";

  /*
   * $sharedUi war frueher abgeschaltet, und kein Aufrufer schaltete es ein.
   * Damit bekam keine der uebernommenen Seiten das gemeinsame Stylesheet --
   * sie standen in der Schrift und den Farben des Browsers da, waehrend
   * daneben die Huelle in der Gestaltung der Anwendung stand. Wer beides
   * gleichzeitig sah, sah zwei Programme.
   */
  /**
   * Die Rueckmeldung einer abgeschlossenen Handlung einsetzen.
   *
   * Seit der Umstellung auf die Weiterleitung (siehe resetframeset in
   * mainindex.php) beantwortet der Steuerlauf eine Handlung nicht mehr mit
   * einer Seite, sondern mit 303. Was der Bedienende lesen soll -- was
   * geschehen ist und wohin die Nachricht ging -- traegt die Sitzung
   * hinueber und wird auf der Zielseite gezeigt.
   *
   * Eingesetzt wird sie hier, in der einen Funktion, die jede Ansicht des
   * Nachrichtenrahmens vor ihrem <body> aufruft. Der Aufrufer schreibt
   * sein <body> selbst und mit eigener Klasse; deshalb ein Ausgabepuffer,
   * der die Tafel hinter das erste <body> setzt, statt sie davor
   * auszugeben. Vor dem <body> ausgegeben, oeffnete der Browser den
   * Koerper selbst -- und die Klasse des Aufrufers ginge verloren.
   *
   * Ohne diese eine Stelle muesste jede der sieben Ansichten dieselbe
   * Zeile tragen, und die achte vergaesse sie.
   */
  function estab_rueckmeldung_einsetzen (){
    if (!isset ($_SESSION) || !is_array ($_SESSION)) {
      return;
    }
    $rueckmeldung = estab_session_ui_outcome_take ($_SESSION);
    if ($rueckmeldung === null) {
      return;
    }
    include ("../4fcfg/config.inc.php");
    try {
      $tafel = estab_session_ui_message_confirmation_markup (
        $rueckmeldung,
        (string) ($conf_4f ["MainURL"] ?? "")
      );
    } catch (Throwable $exception) {
      // Die Nachricht ist gespeichert. Scheitert allein die Tafel, bleibt
      // die Ansicht stehen, statt dass die Seite zerbricht.
      error_log (
        "eStab message confirmation rendering failed: ".
        $exception->getMessage ()
      );
      return;
    }
    if ($tafel === "") {
      return;
    }
    /*
     * Mit der Tafel wird die Seitenleiste aufgefrischt.
     *
     * Sonst stimmten die Warteschlangenzaehler und der Zaehler der
     * Korrekturschleife bis zu dreissig Sekunden lang nicht -- so lange
     * bis das Cockpit von allein nachsieht. Wer eine Meldung abgesetzt hat
     * und daneben unveraendert dieselbe Zahl liest, glaubt, sie sei nicht
     * durchgelaufen.
     *
     * Nur die Seitenleiste: Wer auch den Nachrichtenrahmen auffrischte,
     * loeschte die Tafel, bevor sie gelesen werden kann.
     */
    $tafel = estab_session_ui_frame_refresh_markup (
      estab_session_ui_sidebar_refresh_script ()
    ).$tafel;
    ob_start (static function (string $ausgabe) use ($tafel): string {
      $stelle = stripos ($ausgabe, "<body");
      if ($stelle === false) {
        return $ausgabe;
      }
      $ende = strpos ($ausgabe, ">", $stelle);
      if ($ende === false) {
        return $ausgabe;
      }
      return substr ($ausgabe, 0, $ende + 1)
        . $tafel
        . substr ($ausgabe, $ende + 1);
    });
  }

  function pre_html ($art, $titel, $cssstr, $sharedUi = true){
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/config.inc.php");
    estab_rueckmeldung_einsetzen ();
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head><meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\" />\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";

    echo "<script".estab_csp_script_attribute()." language=\"JavaScript\">\n";
    echo "<!--\n";
    echo "function FramesVeraendern(){";
    echo "for(var i=0;i+1<arguments.length;i+=2){";
    echo "var frame=parent[arguments[i+1]];";
    echo "if(frame){frame.location.href=arguments[i];}";
    echo "}";
    echo "}";
    echo "//-->\n";
    echo "</script>\n";

    // Der Druckknopf trug frueher onclick beziehungsweise eine
    // Adresse mit javascript-Schema. Beides verwirft die Richtlinie. Die
    // Bindung liegt jetzt zentral am Datenattribut und traegt eine Sperre
    // gegen Mehrfachbindung, weil einzelne Seiten mehrere Formulare
    // aufbauen.
    echo "<script".estab_csp_script_attribute()." data-estab-print-binding>";
    echo "if(!window.estabPrintBound){window.estabPrintBound=true;";
    echo "document.addEventListener(\"click\",function(event){";
    echo "var target=event.target;";
    echo "if(!target||typeof target.closest!==\"function\"){return;}";
    echo "if(!target.closest(\"[data-estab-print]\")){return;}";
    echo "event.preventDefault();window.print();});}";
    echo "</script>\n";

    echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n";
   switch ($art){
     case "N":
        echo"<script".estab_csp_script_attribute()." type=\"text/javascript\">";
        echo "function FensterOeffnen (Adresse) {MeinFenster = window.open(Adresse, \"Zweitfenster\", \"width=700,height=650,left=100,top=100,menubar=no,location=no,resizable=yes,scrollbars=yes,status=no,toolbar=no\"); if (MeinFenster) { MeinFenster.focus(); }}";
        echo "</script>";
        // Der Hilfeverweis trug einen onclick-Handler; den lehnt die
        // Richtlinie ab. Die Bindung liegt jetzt am Datenattribut.
        echo "<script".estab_csp_script_attribute()." data-estab-help-window-binding>";
        echo "document.addEventListener(\"click\",function(event){";
        echo "var target=event.target;";
        echo "if(!target||typeof target.closest!==\"function\"){return;}";
        echo "var link=target.closest(\"[data-estab-help-window]\");";
        echo "if(!link){return;}";
        echo "event.preventDefault();";
        echo "FensterOeffnen(link.href);";
        echo "});";
        echo "</script>";

     break;
     case "status":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo "<meta http-equiv=\"refresh\" content=\"".$cfg ["itv"] ["status"]."\">\n";
     break;
     case "fmdliste":
     case "ldfliste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo estab_list_refresh_script ($cfg ["itv"] ["fmdliste"]);
     break;
     case "stabliste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo estab_list_refresh_script ($cfg ["itv"] ["stabliste"]);
     break;
     case "siliste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo estab_list_refresh_script ($cfg ["itv"] ["siliste"]);
     break;
     case "si2liste":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
       echo "<meta http-equiv=\"expires\" content=\"0\">\n";
       echo estab_list_refresh_script ($cfg ["itv"] ["si2liste"]);
     break;
     case "reset":
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
     break;
     default:
       echo "<big><big><big>DEFAULT PRE_HTML !!!</big></big></big><br>";
       echo "<meta http-equiv=\"pragma\" content=\"no cache\">\n";
     break;
   }//switch
   echo "<title>".$titel." ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"]."</title>\n";
   if ($sharedUi === true) {
     echo estab_session_ui_stylesheet ()."\n";
   }
   echo "<style type=\"text/css\">";
   echo $cssstr."\n";
   echo "</style>";
   echo "</head>\n";
  }



  /****************************************************************************\
  | Umwandlung von $datum -->
  | Formateingang: TTMM
  |                JJJJ -> aktuelle Systemjahr
  | Formatausgang: JJJJ-MM-TT
  \****************************************************************************/
  function convtodate ($datum){
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $jahr   = date ("Y");
    $date = $jahr."-".$monat."-".$tag ;
    return $date ;
  }


  /****************************************************************************\
  | Umwandlung von $zeit
  | Formateingang: hhmm
  | Formatausgang: hh:mm
  \****************************************************************************/
  function convtotime ($zeit){
    // Datum ~= TTMM, Zeit == ~= HHMM
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $time = $stunde.":".$minute.":00";
    return $time;
  }

  /****************************************************************************\
  | Umwandlung von $datum und $zeit
  | Formateingang: Datum TTMM
  |                Zeit  hhmm
  | Formatausgang: YYYY-MM-TT hh:mm:00
  \****************************************************************************/
  function convtodatetime ($datum, $zeit){
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $jahr   = date ("Y");
    $datetime = $jahr."-".$monat."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }


  /****************************************************************************\
  | Umwandlung von Datum und Zeit ==> Datetimeformat
  | Formateingang: YYYY-MM-TT HH:mm:ss
  | Formatausgang:
  |       arr.datum = TTMM
  |       arr.zeit  = hhmm
  |       arr.stak  = TThhmm
  \****************************************************************************/
  function convdatetimeto ($datetime){
    $parts = estab_datetime_parts ($datetime);
    return array (
      "datum" => $parts ["datum"],
      "zeit"  => $parts ["zeit"],
      "stak"  => $parts ["stak"],
    );
  }

  /****************************************************************************\
  | Umwandlung von datetime ->
  | Formateingang: YYYY-MM-TT hh:mm:ss
  | Formatausgang:
  |       arr.datum =  YYYY-MM-TT
  |       arr.zeit  =  hh:mm:ss
  \****************************************************************************/
  function convdbdatetimeto ($datetime){
    $parts = estab_datetime_parts ($datetime);
    return array (
      'datum' => $parts ['date'],
      'zeit'  => $parts ['time'],
    );
  }

  /****************************************************************************\
  | Umwandlung von conv_time_datetime ->
  | Formateinausgang:
  |       arr.datum =  TThhmmMMMjjjj
  |       arr.zeit  =  TThhmm
  |       arr.zeit  =  hhmm
  | Formatausgang: YYYY-MM-TT hh:mm:ss
  \****************************************************************************/
  function conv_time_datetime($data){
    // Beide Tabellen kommen aus app/nv_datetime_group.php: geschrieben wird
    // englisch, gelesen zusaetzlich die deutschen Kuerzel des Bestands.
    $tak_monate = estab_nv_month_abbreviations ();
    $rew_tak_monate = estab_nv_month_numbers ();

    $laenge = strlen ($data);
    /* Ausfuellanleitung Nachrichtenvordruck: Uhrzeiten werden vierstellig
       gefuehrt, Datumsangaben mindestens zweistellig. Nur Ziffern sind eine
       Zeitangabe - ohne diese Pruefung vergleicht PHP eine Eingabe wie "1x"
       als Zeichenkette mit 23, und "1x59" wandert bis in die Datenbankzeit. */
    $zeitangabe = (string) $data;
    $ziffernform = true;
    if ( ($laenge == 4) or ($laenge == 6) ){
      $ziffernform = ctype_digit ($zeitangabe);
    }
    if ( $laenge == 13 ){
      $ziffernform = (ctype_digit (substr ($zeitangabe, 0, 6))
                      && ctype_digit (substr ($zeitangabe, 9, 4))
                      && isset ($rew_tak_monate [substr ($zeitangabe, 6, 3)]));
    }
    if ( !$ziffernform ){
      return array ("l_data" => false, "data" => $data);
    }
    switch ( $laenge ){
      case 13:// TThhmmMMMJJJJ
          $tag    = substr ($data, 0, 2);
          $stunde = substr ($data, 2, 2);
          $minute = substr ($data, 4, 2);
          $monat  = substr ($data, 6, 3);
          $jahr   = substr ($data, 9, 4);
          $monat = $rew_tak_monate [$monat];

          if ( (($tag    >= 1) and ($tag    <= 31)) and
               (($monat  >= 1) and ($monat  <= 12)) and
               (($jahr   >= 2000) and ($jahr <= 9999)) and
               (($minute >= 0) and ($minute <= 59)) and
               (($stunde >= 0) and ($stunde <= 23)) ) {
          $monat = $tak_monate [$monat];
            $data = $tag.$stunde.$minute.$monat.$jahr ;
            $l_data = true ;
          } else {
            $l_data = false;
          }
      break;
      case 6: // TThhmm
          $tag    = substr ($data, 0, 2);
          $stunde = substr ($data, 2, 2);
          $minute = substr ($data, 4, 2);
          $monat = $tak_monate [date ("m")];
          $jahr = date ("Y");

          if ( (($tag    >= 1) and ($tag    <= 31)) and
               (($minute >= 0) and ($minute <= 59)) and
               (($stunde >= 0) and ($stunde <= 23))) {
            $data = $tag.$stunde.$minute.$monat.$jahr ;
            $l_data = true ;
          } else {
            $data = $data;
            $l_data = false;
          }
      break;
      case 4: // hhmm
          $stunde = substr ($data, 0, 2);
          $minute = substr ($data, 2, 2);

          if ( (($minute >= 0) and ($minute <= 59)) and
               (($stunde >= 0) and ($stunde <= 23))) {
            $tag   = date ("d");
            $monat = $tak_monate [date ("m")];
            $jahr  = date ("Y");
            $data = $tag.$stunde.$minute.$monat.$jahr ;
            $l_data = true ;
          } else {
            $l_data = false;
          }
      break;
      default: $l_data = false;
    }
    $back = array ("l_data" => $l_data, "data" => $data);
    return ( $back );
  } //conv_time_datetime


  function getoutqueuecount (){
  	 if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>getoutqueuecount</big><br>";  }
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."` WHERE ((`04_richtung` = \"A\") AND
                                                  (`03_datum` IS NULL) AND
                                                  (`03_zeichen` = \"\"));";
   $result = $dbaccess->query_table_wert ($query);
    return $result[0];
  }


	function getviewerqueuecount (){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>getviewerqueuecount</big><br>";  }
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);

		$WHERE_inout = "WHERE ( ( `15_quitdatum` IS NULL ) AND ( `15_quitzeichen` = \"\" ) ) AND ( ( `04_richtung` =\"E\") OR ( (`03_datum` IS NOT NULL) AND ( `03_zeichen` != \"\" ) ) )";
		$WHERE_in    = "WHERE ( ( `15_quitdatum` IS NULL ) AND ( `15_quitzeichen` = \"\" ) ) AND ( `04_richtung` =\"E\")";

		if($conf_4f["si_in_out"]) {  //  Ein- und Ausänge sichten
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### Ein- und Ausgänge sichten<br>"; }
				$query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."` ".$WHERE_inout.";";
      } else {
       	if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### nur Eingänge sichten<br>"; }
				$query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."` ".$WHERE_in.";";				
		}
	if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>getviewerqueuecount:query=".$query."</big><br>";  }                      
   $result = $dbaccess->query_table_wert ($query);
    return $result[0];
  }



  function getreadedcount (){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>getreadedcount</big><br>";  }
		include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."`
              WHERE ( `16_empf` like '%".$_SESSION ["vStab_funktion"]."%' ) ;";
    $result = $dbaccess->query_table_wert ($query);
    $gesamtmeldungen = $result[0];

    $tblusername = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"])."_".strtolower ($_SESSION["vStab_kuerzel"]);
    $query = "SELECT count(*) FROM `".$tblusername."_read"."`
              WHERE 1 ;";
    $result = $dbaccess->query_table_wert ($query);
    $gelesenemeldungen = $result[0];

    return $gesamtmeldungen-$gelesenemeldungen;
  }

  function getdonecount (){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>getdonecount</big><br>";  }  	
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["nachrichten"]."`
              WHERE ( `16_empf` like '%".$_SESSION ["vStab_funktion"]."%' ) ;";
    $result = $dbaccess->query_table_wert ($query);
    $gesamtmeldungen = $result[0];
    $fkttblname  = $conf_4f_tbl ["usrtblprefix"]."_fkt_".strtolower ($_SESSION["vStab_funktion"]);

    $query = "SELECT count(*) FROM `".$fkttblname."_erl"."`
              WHERE 1 ;";

    $query = "SELECT count(*) FROM `nv_nachrichten`,`".$fkttblname."_erl`
              WHERE
               (
                 ( `16_empf` like '%".$_SESSION ["vStab_funktion"]."%' ) AND
                 ( ".$conf_4f_tbl ["nachrichten"].".00_lfd = ".$fkttblname."_erl.nachnum )
               ) ;";

   $result = $dbaccess->query_table_wert ($query);
   $erledigtmeldungen = $result[0];
   return $gesamtmeldungen-$erledigtmeldungen;
  }

/**************************************************************************************\
  Funktion: einhorn

  bist du der letzte deiner Art?
\**************************************************************************************/
  function einhorn ($fkt){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>einhor</big><br>";  }  	
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);

    $query = "SELECT count(*) FROM `".$conf_4f_tbl ["benutzer"]."` WHERE
         ( (`funktion` = \"".$fkt."\") AND 
           (`aktiv` != \"0\" )); ";
    $result = $dbaccess->query_table_wert ($query);

    if ($result[0] > 1) { return (false); }   
    else { return (true);}
  } // funktion einhorn



  function showsrvtime ($dir){
      // setze die Zeitzone
    date_default_timezone_set  ( "Europe/Berlin" );     
    echo "<table align=\"center\" style=\"text-align:center; background-color: \"\"; height: 52px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"2\">\n";
    echo "<tbody>";
      $hour = date ("H");
      $min  = date ("i");
      $day  = date ("d");
      $mom  = date ("m");
      $year = date ("Y");
    if ($dir == "vertikal"){
      echo "<tr><td style=\"text-align:center;\">";
      echo "<span style=\"font-size:1.2em\">$day.$mom</span>";
      echo "</td></tr>";
      echo "<tr><td style=\"text-align:center;\">";
      echo "<span style=\"font-size:1.2em\">$year</span>";
      echo "</td></tr>";
      echo "<tr><td style=\"text-align:center;\">";
      echo "<span style=\"font-size:1.2em\">$hour:$min</span>";
      echo "</td></tr>";
    }// if direction
    echo "</tbody>";
    echo "</table>";
  }

/******************************************************************************
Gibt eine Tabelle aus in der alle angemeldeten Rollen und Funktionen
bersichtlich dargestellt werden.
******************************************************************************/
  function systemstatus ($direction){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");
    $benutzer = array ();
    $statusConnection = null;
    try {
      $statusConnection = estab_auth_connect ($conf_4f_db);
      $benutzer = estab_auth_fetch_users ($statusConnection, $conf_4f_tbl ["benutzer"]);
    } catch (Throwable $exception) {
      error_log ("eStab system status lookup failed: ".$exception->getMessage ());
    } finally {
      if ($statusConnection instanceof mysqli) {
        estab_auth_close ($statusConnection);
      }
    }
    $aktiv    = " rgb(100, 250,  20); color:&000000; "; // was (100, 250,  20)
    $idle     = " rgb(235, 190,  85); color:&000000; ";
    $inaktiv  = " rgb(200, 200, 200); color:&FFFF00; "; // was (250,  60,  30)
    $self     = " rgb(250,  60,  30); color:&ffffff; "; // was ( 50, 180, 220);

    $fernm_aw = 0;

    for ($i=1; $i <= count ($conf_empf); $i++){
      $userstatus [ ($conf_empf[$i]["rolle"]) ]  [ ($conf_empf[$i]["fkt"]) ]  = $inaktiv;
    }

    if ($benutzer !== array ()){
      foreach ($benutzer as $user){
        $presence = estab_auth_presence_state ($user);
        if ($presence === "online") {
          $userstatus [$user['rolle']][$user['funktion']] = $aktiv;
        } elseif (
          $presence === "inactive"
          && ($userstatus [$user['rolle']][$user['funktion']] ?? null) !== $aktiv
        ) {
          $userstatus [$user['rolle']][$user['funktion']] = $idle;
        }

        if ( ($user ["funktion"] == "A/W") AND in_array ($presence, array ("online", "inactive"), true) ){ $fernm_aw ++;}
      }
    }
    if (isset ($_SESSION ["vStab_rolle"], $_SESSION ["vStab_funktion"])) {
      $userstatus [$_SESSION["vStab_rolle"]][$_SESSION["vStab_funktion"]] = $self;
    }
    if ($direction == "horizontal"){
      echo "<fieldset>";
      echo "<legend><b><big>Funktionsbersicht</big></b></legend>\n";
      echo "<table style=\"text-align:left; background-color: rgb(150, 150, 150); height: 32px; font-size:9pt; border=\"3\" cellpadding=\"5\" cellspacing=\"5\">\n";
      echo "<tbody>\n";
      echo "<tr>\n"; // Zeilen begin

      echo "<td style=\"background-color: ".$userstatus ["Stab"]["LS"]." font-weight:bold;\">LS</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S1"]." font-weight:bold;\">S1</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S2"]." font-weight:bold;\">S2</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S3"]." font-weight:bold;\">S3</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S4"]." font-weight:bold;\">S4</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S5"]." font-weight:bold;\">S5</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["S6"]." font-weight:bold;\">S6</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["Stab"]["Si"]." font-weight:bold;\">Si</td>\n";

      echo "<td style=\"background-color: ".$userstatus ["FB"]["BS"]." font-weight:bold;\">BS</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["Fm"] ." font-weight:bold;\">Fm</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["ABC"]." font-weight:bold;\">ABC</td>\n";

      echo "<td style=\"background-color: ".$userstatus ["FB"]["THW"] ." font-weight:bold;\">THW</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["Bt"]." font-weight:bold;\">Bt</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["San"] ." font-weight:bold;\">San</td>\n";
      echo "<td style=\"background-color: ".$userstatus ["FB"]["Vers"]." font-weight:bold;\">Vers</td>\n";


      echo "<td style=\"background-color: ".$userstatus ["FB"]["Pol"] ." font-weight:bold;\">Pol</td>\n";

      if ($fernm_aw > 0) {
        echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">".$fernm_aw." Fernmelder</td>\n";
      } else {  // keiner aktiv ==> einer inaktiv
         echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." font-weight:bold;\">Fernmelder</td>\n";
      }

      echo "</tr>";
      echo "</tbody></table>\n";
      echo "</fieldset>\n";

    }


    if ($direction == "vertikal") {
      $zellenbreite = "50";
      $zellenhoehe  = "20";

/******************************************************************************\
  1. erste Zeile doppelt oder einfach

\******************************************************************************/


      $i = 1;
      $doppel = false;  // zweisymbole nebeneinander ja/nein
      $prefdoppelt = false; // vorgaenger doppelt
      $entryCount = count ($conf_empf);

      while ( $i <= $entryCount ){

        if ($i == 1) {
           echo "<!-- 001 list.php -->\n";
           echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite.";
                  height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);
                  font-size:9pt; border=\"1\" cellpadding=\"1\" cellspacing=\"1\">\n";
           echo "<tbody>\n";
           $tableisset = true;
        }

        if ( ( $i <= $entryCount - 1 ) and
             ( strlen( $conf_empf [$i]["fkt"] ) <= 2 ) and
             ( strlen( $conf_empf [$i+1]["fkt"] ) <= 2 ) ) { // die naechsten zwei sind max zweistellig
            $doppel = true;
          } else {
            $doppel = false;
          }

        if (($prefdoppelt != $doppel) and ($i >1) )  {
           echo "<!-- 002 list.php -->\n";
           echo "</tbody>"; echo "</table>\n";
           $tableisset = false;
        }
        if ( ( $doppel != $prefdoppelt) and !($tableisset) ) {
           echo "<!-- 003 liste.php -->\n";
           echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite."; height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);  font-size:9pt; height:".$zellenhoehe.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"1\">\n";
           echo "<tbody>\n";
           $tableisset = true;
           $prefdoppelt = $doppel;
        }

        if ( ($doppel) and ($conf_empf[$i]["fkt"] != "A/W") ) {

          echo "<!-- 004 liste.php -->\n";
          echo "<tr>\n";
          echo "<td style=\"background-color: ".
                    $userstatus [($conf_empf[$i]["rolle"])][($conf_empf[$i]["fkt"])]
                    ."height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".
                    estab_function_display_name ((string) $conf_empf[$i]["fkt"]);
          echo "</td>\n";

          echo "<td style=\"background-color: ".
                    $userstatus [($conf_empf[$i+1]["rolle"])][($conf_empf[$i+1]["fkt"])]
                    ."height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".
                    estab_function_display_name ((string) $conf_empf[$i+1]["fkt"]);
          echo "</td>\n";
          echo "</tr>\n";
          $i += 2;
          $prefdoppelt = true ;
        }
        if ( (!$doppel) and ($conf_empf[$i]["fkt"] != "A/W") ) {

          echo "<!-- 005 liste.php -->\n";
          echo "<tr>\n";
          echo "<td style=\"background-color: ".
                    $userstatus [($conf_empf[$i]["rolle"])][($conf_empf[$i]["fkt"])]
                    ."height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".
                    estab_function_display_name ((string) $conf_empf[$i]["fkt"]);
          echo "</td>\n";
          echo "</tr>\n";
          $i ++;
          $prefdoppelt = false;

        }

        if ( ($i <= $entryCount) and ($conf_empf[$i]["fkt"] == "A/W") ) {
          // Zeige wenigstens einen inaktiven Fermelder an

          echo "<!-- 006 liste.php -->\n";
          echo "</tbody>"; echo "</table>\n";
          $tableisset = false;
          echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite."; height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);  font-size:9pt; height:".$zellenhoehe.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"1\">\n";
          echo "<tbody>\n";
          $tableisset = true;

          if ($fernm_aw > 0) {
          echo "<tr>";
          echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">".$fernm_aw." Fernmelder</td>\n";
          echo "</tr>";
          } else {  // keiner aktiv ==> einer inaktiv
            echo "<!-- 007 liste.php -->\n";
            echo "<tr>";
            echo "<td style=\"background-color: ".$userstatus ["Fernmelder"]["A/W"]." height:".$zellenhoehe."; font-size:9pt; font-weight:bold;\">Fernmelder</td>\n";
            echo "</tr>";
          }

          echo "</tbody>"; echo "</table>\n";
          $tableisset = false;
        $i++;
        $doppel      = false;
        $prefdoppelt = false;
          echo "<table align=\"center\" style=\"text-align:center; width:".$zellenbreite."; height:".$zellenhoehe."; font-size:9pt; background-color: rgb(150, 150, 150);  font-size:9pt; height:".$zellenhoehe.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"1\">\n";
          echo "<tbody>\n";
          echo "<!-- 008 liste.php -->\n";
          $tableisset = true;
        }
      }
    echo "<!-- 009 liste.php -->\n";
    echo "</tbody>";
    echo "</table>\n";

    }
  }

/********************************************************************************************************
   Benutzerstatus
********************************************************************************************************/
  function benutzerstatus ($what, $loginDestination = null){ // kann sein "anzeige" oder mit "verlinkt"
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $benutzer = array ();
    $registrationAvailable = false;
    $registrationAllowed = false;
    $statusConnection = null;
    try {
      $statusConnection = estab_auth_connect ($conf_4f_db);
      $benutzer = estab_auth_fetch_users ($statusConnection, $conf_4f_tbl ["benutzer"]);
      try {
        $registrationPolicy = estab_self_registration_load ($statusConnection);
        $registrationAllowed = estab_self_registration_is_allowed (
          $registrationPolicy
        );
        $registrationAvailable = true;
      } catch (Throwable $exception) {
        error_log (
          "eStab self-registration status lookup failed: ".
          $exception->getMessage ()
        );
      }
    } catch (Throwable $exception) {
      error_log ("eStab user status lookup failed: ".$exception->getMessage ());
    } finally {
      if ($statusConnection instanceof mysqli) {
        estab_auth_close ($statusConnection);
      }
    }

    /*Benutzerliste*/
    // Vor der Verzweigung: Auch der Fall "noch kein Konto" muss wissen, ob er
    // auf der Anmeldeseite steht -- dort bringt die Seite die Huelle mit.
    $loginSelectable = $what == "verlinkt"
      && in_array (($_SESSION ["menue"] ?? ""), array ("WELCOME", "LOGIN"), true);
    if ($benutzer !== array ()){
      include ("../4fcfg/config.inc.php");
      if ($what == 'verlinkt'){
         /*
          * Die Kennung verbindet die Auswahlknoepfe der Kontenliste mit
          * diesem Formular.
          *
          * Es traegt nur die verborgenen Felder und ist sofort wieder zu.
          * Frueher umschloss es Ueberschrift und Hilfssatz und endete
          * mitten im <fieldset>, das nach ihm begonnen hatte -- der
          * Browser flickt das, aber verlassen sollte man sich darauf
          * nicht. Die Auswahlknoepfe stehen ohnehin nicht darin: Sie
          * haengen ueber form="estab-kontenwahl" daran, denn das
          * Tabellenbauteil bringt sein eigenes Suchformular mit, und ein
          * Formular im Formular wirft der Browser weg.
          */
         echo "\n\n<form id=\"estab-kontenwahl\" action=\"".estab_auth_html ($conf_4f ["MainURL"])."\" method=\"POST\" target=\"_self\">\n";
         echo estab_csrf_field ()."\n";
         echo estab_navigation_login_destination_field ($loginDestination)."\n";
         echo "<!-- Benutzerliste mit POST-Auswahl zur Anmeldung -->\n";
         echo "</form>\n";
      }

      /*
       * Nur die Tafel, keine Huelle: Die Anmeldeseite stellt die Liste in
       * ihren Arbeitsbereich neben die Felder, die Kontenuebersicht der
       * angemeldeten Sitzung in ihre Inhaltsspalte. Wer beides mitbraechte,
       * haette zwei Huellen ineinander mit zwei Innenabstaenden.
       */
      echo "<section class=\"estab-tool-panel".
           ($loginSelectable
             ? " estab-anmeldung-tafel estab-anmeldung-konten"
             : "")."\">\n";
      echo "<div class=\"estab-tool-panel-heading\"><h2>".
           ($loginSelectable ? "Bestehendes Konto auswählen" : "Benutzerliste").
           "</h2></div>\n";
      if ($loginSelectable) {
        echo "<p class=\"estab-auth-help\">Die Auswahl übernimmt Name, Kürzel und Funktion. Zum Anmelden benötigen Sie weiterhin das zugehörige Kennwort.</p>\n";
      }
      /*
       * Die Kontenliste kommt aus dem Tabellenbauteil (app/tabelle.php).
       *
       * Sie war die letzte Liste mit eigenem Markup und eigener Klasse
       * (estab-account-list) -- und deshalb ist sie meinem Waechter
       * entgangen, der nur zwei bestimmte Klassennamen zaehlte. Bei einer
       * Uebung mit hundert Konten sucht man ein Kuerzel sonst mit dem Finger
       * am Bildschirm.
       */
      $kontenZeilen = array ();
      foreach ($benutzer as $user){
        $presence = estab_auth_presence_state ($user);
        $isCurrentSession = (string) ($user ["sid"] ?? "") !== ""
          && session_id () === (string) $user ["sid"]
          && in_array ($presence, array ("online", "inactive"), true);
        $rowClass = $presence === "online"
          ? "estab-account-active"
          : ($presence === "inactive" ? "estab-account-idle" : "estab-account-inactive");
        $statusText = $isCurrentSession
          ? ($presence === "online" ? "Aktuelle Sitzung · aktiv" : "Aktuelle Sitzung · inaktiv")
          : match ($presence) {
              "online" => "Aktiv",
              "inactive" => "Inaktiv (seit mindestens 15 Minuten)",
              "blocked" => "Gesperrt",
              default => "Abgemeldet",
            };
        $kontenZeilen[] = array (
          "benutzer" => (string) ($user ["benutzer"] ?? ""),
          "kuerzel" => (string) ($user ["kuerzel"] ?? ""),
          "rolle" => (string) ($user ["rolle"] ?? ""),
          "funktion" => estab_function_display_name (
            (string) ($user ["funktion"] ?? "")
          ),
          "status" => $statusText,
          "marke" => $rowClass,
          "kennung" => $loginSelectable
            ? estab_auth_identity_token ($user)
            : "",
        );
      }

      $kontenStati = array ();
      foreach ($kontenZeilen as $kontenZeile) {
        $kontenStati [$kontenZeile ["status"]] = true;
      }
      ksort ($kontenStati);

      /*
       * Die Breiten sind an der engsten Lage gerechnet, in der diese Liste
       * neben den Anmeldefeldern steht: Fenster 1024, Liste 656
       * Bildpunkte. Jede Spalte muss dort ihren laengsten unteilbaren
       * Inhalt tragen -- "Fernmelder" 68 Punkte, der Auswahlknopf 82, der
       * Kopf "KÜRZEL" mit seinem Sortierzeichen 73 -- plus 16 Punkte
       * Zellpolster. Waren die Spalten schmaler, brach der Browser mitten
       * im Wort um, und der Knopf stand ausserhalb seiner Spalte.
       */
      $kontenSpalten = array (
        array ("schluessel" => "benutzer", "kopf" => "Benutzer",
          "breite" => $loginSelectable ? 19 : 28,
          "sortierbar" => true, "suchbar" => true, "art" => "text"),
        array ("schluessel" => "kuerzel", "kopf" => "Kürzel",
          "breite" => 12,
          "sortierbar" => true, "suchbar" => true, "art" => "text"),
        array ("schluessel" => "rolle", "kopf" => "Rolle",
          "breite" => 14,
          "sortierbar" => true, "suchbar" => true, "art" => "text"),
        array ("schluessel" => "funktion", "kopf" => "Funktion",
          "breite" => $loginSelectable ? 15 : 16,
          "sortierbar" => true, "suchbar" => true, "art" => "text"),
        array ("schluessel" => "status", "kopf" => "Status",
          "breite" => $loginSelectable ? 23 : 30,
          "sortierbar" => true, "suchbar" => true, "art" => "text",
          "filter" => array_keys ($kontenStati),
          "filtername" => "Alle Zustände"),
      );
      if ($loginSelectable) {
        $kontenSpalten[] = array (
          "schluessel" => "kennung", "kopf" => "Aktion", "breite" => 17,
          "sortierbar" => false, "suchbar" => false, "art" => "text",
          "zelle" => static function (array $z): string {
            return "<button class=\"estab-button\" type=\"submit\""
              . " form=\"estab-kontenwahl\""
              . " name=\"login_identity\" value=\""
              . estab_auth_html ($z ["kennung"])."\""
              . " aria-label=\"Konto ".estab_auth_html ($z ["benutzer"])
              . " mit Kürzel ".estab_auth_html ($z ["kuerzel"])
              . " auswählen\">Auswählen</button>";
          },
        );
      }

      echo estab_tabelle_markup (array (
        "id" => "konten",
        "beschriftung" => $loginSelectable
          ? "Bestehende Konten mit Rolle, Funktion und Anmeldestatus"
          : "Benutzerkonten mit Rolle, Funktion und Anmeldestatus",
        "mindestbreite" => "36rem",
        // Wenige Spalten, aber ein schmaler Platz: erst unter 30rem Karten.
        "schmal" => true,
        "zeilenmarke" => static function (array $z): string {
          return "class=\"".estab_auth_html ($z ["marke"])."\"";
        },
        "spalten" => $kontenSpalten,
        "zeilen" => $kontenZeilen,
        "leer" => "Kein Konto entspricht den gesetzten Filtern.",
      ));
      echo "</section>\n";
    } else {
      echo "<section class=\"estab-tool-panel".
           ($loginSelectable ? " estab-anmeldung-tafel" : "")."\">\n";
      echo "<div class=\"estab-tool-panel-heading\">".
           "<h2>Noch keine Konten vorhanden</h2></div>\n";
      if ($registrationAvailable && $registrationAllowed) {
        echo "<p>Legen Sie das erste Funktionskonto über „Neues Konto anlegen“ an.</p>\n";
      } elseif (!$registrationAvailable) {
        echo "<p>Der Status der Kontoanlage konnte nicht sicher geprüft werden. Neue Konten können deshalb momentan nicht selbst angelegt werden.</p>\n";
      } else {
        echo "<p>Die Selbstregistrierung ist geschlossen. Die zuständige Stelle kann ein Konto in der Benutzerverwaltung anlegen oder die Kontoanlage zeitlich freigeben.</p>\n";
      }
      echo "</section>\n";
    }
  }

/*****************************************************************************\


\*****************************************************************************/
  function reset_cookie (){
     if (isset ($_COOKIE ["vStab_benutzer"])){ setcookie ("vStab_benutzer" , "", (time()-60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     if (isset ($_COOKIE ["vStab_kuerzel"])){  setcookie ("vStab_kuerzel", "", (time()-60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     if (isset ($_COOKIE ["vStab_funktion"])){  setcookie ("vStab_funktion", "", (time()-60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     if (isset ($_COOKIE ["vStab_rolle"])){  setcookie ("vStab_rolle", "",   (time()+60*60*24*30),"/intern/4fach/", "team-landmesser.homelinux.net");}
     session_destroy ();
  }


/*****************************************************************************\


\*****************************************************************************/
  function rollenfinder ( $funktion ){
    include ("../4fcfg/fkt_rolle.inc.php");
    $rolle = "";
    for ($i=1; $i <= count ($conf_empf); $i++ ) {
      if ( ( strcmp($conf_empf[$i]["fkt"], $funktion) ) == 0 ) {
        $rolle = $conf_empf[$i]["rolle"]; }
    }
    return $rolle;
  }

  function fktpos_finder ($fkt) {
    include ("../4fcfg/fkt_rolle.inc.php");
    $result = array ( 0, 0);
    for ($i=1; $i <= count ($conf_empf); $i++){
      if ($conf_empf [$i][2] == $fkt){
        $result [0] = $conf_empf [$i][0];
        $result [1] = $conf_empf [$i][1];
      }
    }
  return $result;
  }


/*****************************************************************************\


\*****************************************************************************/
   function get_last_nachw_num ($direction){
     include ("../4fcfg/dbcfg.inc.php");
     include ("../4fcfg/e_cfg.inc.php");
     $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
     if ( Nachweisung == "getrennt" ) {
       $query = "SELECT max(04_nummer)FROM ".$conf_4f_tbl ["nachrichten"]." WHERE `04_richtung` = \"$direction\" ";
     }
     if ( Nachweisung == "gemeinsam" ) {
       $query = "SELECT max(04_nummer)FROM ".$conf_4f_tbl ["nachrichten"]." WHERE 1 ";
     }
     $aktnum = $dbaccess->query_table_wert ($query);

     return $aktnum[0];
   }

/*****************************************************************************\


\*****************************************************************************/
  function errorwindow ($lokation, $parameter){
    $timestr = date ("His");
    echo "<!--  fehlermeldung ".$timestr."   -->";
    echo "<script".estab_csp_script_attribute()." type=\"text/javascript\">\n";
    echo "var Neufenster = window.open(\"./info.php?sub=$lokation&info=".$parameter."\",\"AnderesFenster\",\"width=640,height=480, resizable=yes, scrollbars=yes\");\n";
    echo "</script>\n";
  }

/******************************************************************************\

  Welche Farben bekommt welcher Empfaenger

\******************************************************************************/
  function estab_recipient_copy_map ($empf){
    $coloursByFunction = array ();
    foreach (explode (",", (string) $empf) as $token) {
      $token = trim ((string) $token);
      if (
        preg_match (
          "/\\A(.+)_(bl|gn|rt|ge|gb)\\z/Di",
          $token,
          $parts
        ) !== 1
      ) {
        continue;
      }
      $function = trim ((string) $parts [1]);
      $colour = strtolower ((string) $parts [2]);
      if ($function === "") {
        continue;
      }
      $existing = isset ($coloursByFunction [$function])
        ? explode (",", $coloursByFunction [$function])
        : array ();
      if (!in_array ($colour, $existing, true)) {
        $existing [] = $colour;
      }
      $coloursByFunction [$function] = implode (",", $existing);
    }
    return $coloursByFunction;
  }

  /****************************************************************************\
  | Selbsttaetige Aktualisierung einer Liste.
  |
  | Bisher stand hier ein <meta http-equiv="refresh">. Der laedt die Seite
  | unbedingt neu und ist nicht aufzuhalten: alle zehn Sekunden verlor die
  | Sichter- und Fernmelderliste die Sucheingabe und die Scrollposition, und
  | wer gerade tippte, tippte ins Leere. Der Ersatz verschiebt die
  | Aktualisierung, solange jemand in einem Eingabefeld steht oder Text
  | markiert hat, und stellt die Scrollposition danach wieder her.
  \****************************************************************************/
  /*
   * Die Liste erneuert ihren Inhalt, ohne die Seite zu verlassen.
   *
   * Sie lud sich frueher selbst neu (window.location.reload). Das hatte
   * drei Nachteile, und der dritte war der schlimmste:
   *
   * 1. Die Bildlaufstelle ging verloren und musste umstaendlich gemerkt
   *    und wiederhergestellt werden.
   * 2. Kopf, Menue und Skripte wurden mit aufgebaut, obwohl sich nur die
   *    Liste aendert.
   * 3. Die Listenseiten kommen aus einem POST. Ein Neuladen einer
   *    POST-Antwort laesst den Browser fragen, ob die Daten erneut
   *    gesendet werden sollen -- bei zehn Sekunden Takt alle zehn
   *    Sekunden. Genau das hat der Betrieb gemeldet.
   *
   * Nachgemessen: Ein GET auf dieselbe Adresse liefert dieselbe Ansicht,
   * weil die Sitzung den Zustand traegt. Der Inhalt laesst sich also
   * holen und einsetzen.
   *
   * ## Warum keine Skripte aus der Antwort ausgefuehrt werden
   *
   * Eingesetztes Markup fuehrt seine <script>-Elemente ohnehin nicht aus.
   * Sie neu zu erzeugen waere die uebliche Abhilfe; hier waere sie falsch.
   * Die Sicherheitsrichtlinie bindet Skripte an eine Einmalkennung, und
   * die der geholten Antwort ist eine andere als die des laufenden
   * Dokuments. Ein nachgebautes Skript waere entweder gesperrt, oder man
   * muesste die Kennung durchreichen -- und haette die Richtlinie
   * ausgehebelt. Die Listenseiten tragen ihre Skripte im Kopf, nicht im
   * Inhalt; die Pruefung list_refresh_security haelt das fest.
   */
  function estab_list_refresh_script ($seconds){
    $interval = filter_var (
      $seconds,
      FILTER_VALIDATE_INT,
      array ("options" => array ("min_range" => 5, "max_range" => 3600))
    );
    if ($interval === false) {
      return "";
    }
    $milliseconds = $interval * 1000;
    return "<script".estab_csp_script_attribute().
      " data-estab-list-refresh=\"".$interval."\">\n".
      "(function(){\n".
      // Niemanden unterbrechen, der gerade arbeitet.
      "function busy(){\n".
      "var active=document.activeElement;\n".
      "if(active){var tag=String(active.tagName||'').toLowerCase();\n".
      "if(tag==='input'||tag==='textarea'||tag==='select'){return true;}\n".
      "if(active.isContentEditable){return true;}}\n".
      "var selection=window.getSelection&&window.getSelection();\n".
      "if(selection&&String(selection).length>0){return true;}\n".
      // Ein geoeffnetes Aufklappfeld ist eine Absicht des Lesenden.
      "if(document.querySelector('details[open]')){return true;}\n".
      "return false;}\n".
      "function einsetzen(text){\n".
      "var doc=new DOMParser().parseFromString(text,'text/html');\n".
      "var neu=doc.body;\n".
      "if(!neu){return;}\n".
      // Skripte werden nicht uebernommen; siehe Erklaerung oben.
      "var skripte=neu.querySelectorAll('script');\n".
      "for(var i=0;i<skripte.length;i++){skripte[i].remove();}\n".
      "if(busy()){return;}\n".
      "document.body.replaceChildren.apply(document.body,\n".
      "Array.prototype.slice.call(neu.childNodes));}\n".
      "function holen(){\n".
      "return fetch(window.location.href,{credentials:'same-origin',\n".
      "headers:{'Accept':'text/html'}}).then(function(antwort){\n".
      // Eine Weiterleitung auf die Anmeldung ist keine Liste. Sie
      // einzusetzen zeigte ein Anmeldeformular im Listenrahmen; besser
      // gar nichts tun und beim naechsten Takt erneut versuchen.
      "if(!antwort.ok||antwort.redirected){return null;}\n".
      "var art=antwort.headers.get('content-type')||'';\n".
      "if(art.indexOf('text/html')<0){return null;}\n".
      "return antwort.text();}).then(function(text){\n".
      "if(text!==null){einsetzen(text);}});}\n".
      "function schedule(delay){window.setTimeout(function(){\n".
      "if(busy()){schedule(5000);return;}\n".
      // Ein Fehler beim Holen laesst die Seite stehen, wie sie ist, und
      // versucht es beim naechsten Takt wieder. Eine leere Liste waere
      // schlimmer als eine, die einen Takt alt ist.
      "holen().catch(function(){}).then(function(){\n".
      "schedule(".$milliseconds.");});},delay);}\n".
      "function start(){schedule(".$milliseconds.");}\n".
      "if(document.readyState==='complete'){start();}\n".
      "else{window.addEventListener('load',start);}\n".
      "})();\n".
      "</script>\n";
  }

  function estab_recipient_copy_colours ($copyColours){
    $result = array ();
    foreach (explode (",", (string) $copyColours) as $colour) {
      $colour = strtolower (trim ((string) $colour));
      if (
        in_array ($colour, array ("bl", "gn", "rt", "ge", "gb"), true)
        && !in_array ($colour, $result, true)
      ) {
        $result [] = $colour;
      }
    }
    return $result;
  }

  /****************************************************************************\
  | Lesbare Schriftfarbe zu einem Durchschriften-Hintergrund.
  |
  | Die Durchschriftenfarben sind helle Pastelltoene und der Standardwert ist
  | Weiss. Eine fest verdrahtete weisse Schrift verschwindet darauf. Die Farbe
  | wird deshalb aus der Helligkeit des Hintergrunds bestimmt, und bei mehreren
  | Durchschriften so gewaehlt, dass sie auf jedem Abschnitt lesbar bleibt.
  \****************************************************************************/
  function estab_colour_channels ($colour){
    $colour = trim (strtolower ((string) $colour));
    if (preg_match ('/\A#([0-9a-f]{3})\z/', $colour, $short) === 1) {
      return array (
        hexdec (str_repeat ($short [1] [0], 2)),
        hexdec (str_repeat ($short [1] [1], 2)),
        hexdec (str_repeat ($short [1] [2], 2))
      );
    }
    if (preg_match ('/\A#([0-9a-f]{6})\z/', $colour, $long) === 1) {
      return array (
        hexdec (substr ($long [1], 0, 2)),
        hexdec (substr ($long [1], 2, 2)),
        hexdec (substr ($long [1], 4, 2))
      );
    }
    if (
      preg_match (
        '/\Argba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*[,)]/',
        $colour,
        $rgb
      ) === 1
    ) {
      $channels = array ((int) $rgb [1], (int) $rgb [2], (int) $rgb [3]);
      foreach ($channels as $channel) {
        if ($channel > 255) {
          return null;
        }
      }
      return $channels;
    }
    return null;
  }

  function estab_colour_relative_luminance (array $channels){
    $linear = array ();
    foreach ($channels as $channel) {
      $value = $channel / 255;
      $linear [] = $value <= 0.03928
        ? $value / 12.92
        : pow (($value + 0.055) / 1.055, 2.4);
    }
    return 0.2126 * $linear [0] + 0.7152 * $linear [1] + 0.0722 * $linear [2];
  }

  function estab_colour_contrast_ratio ($first, $second){
    $lighter = max ($first, $second);
    $darker = min ($first, $second);
    return ($lighter + 0.05) / ($darker + 0.05);
  }

  function estab_recipient_copy_ink (
    $copyColours,
    array $backgroundColours,
    $defaultColour
  ){
    $resolved = array ();
    foreach (estab_recipient_copy_colours ($copyColours) as $colour) {
      $lookup = $colour === "gb" ? "ge" : $colour;
      if (isset ($backgroundColours [$lookup])) {
        $resolved [] = (string) $backgroundColours [$lookup];
      }
    }
    if (count ($resolved) === 0) {
      $resolved [] = (string) $defaultColour;
    }
    $darkWorst = null;
    $lightWorst = null;
    foreach ($resolved as $colour) {
      $channels = estab_colour_channels ($colour);
      if ($channels === null) {
        // Unbekannte Notation: die Vordruckfarben sind hell, also dunkle Tinte.
        return "#000000";
      }
      $luminance = estab_colour_relative_luminance ($channels);
      $againstDark = estab_colour_contrast_ratio ($luminance, 0.0);
      $againstLight = estab_colour_contrast_ratio ($luminance, 1.0);
      $darkWorst = $darkWorst === null
        ? $againstDark
        : min ($darkWorst, $againstDark);
      $lightWorst = $lightWorst === null
        ? $againstLight
        : min ($lightWorst, $againstLight);
    }
    return $darkWorst >= $lightWorst ? "#000000" : "#ffffff";
  }

  function estab_recipient_copy_background (
    $copyColours,
    array $backgroundColours,
    $defaultColour
  ){
    $resolved = array ();
    foreach (estab_recipient_copy_colours ($copyColours) as $colour) {
      $lookup = $colour === "gb" ? "ge" : $colour;
      if (isset ($backgroundColours [$lookup])) {
        $resolved [] = (string) $backgroundColours [$lookup];
      }
    }
    if (count ($resolved) === 0) {
      return (string) $defaultColour;
    }
    if (count ($resolved) === 1) {
      return $resolved [0];
    }
    $segments = array ();
    $count = count ($resolved);
    foreach ($resolved as $index => $colour) {
      $start = ($index * 100) / $count;
      $end = (($index + 1) * 100) / $count;
      $segments [] = $colour." ".$start."%";
      $segments [] = $colour." ".$end."%";
    }
    return "linear-gradient(to right, ".implode (", ", $segments).")";
  }

  function estab_recipient_copy_cell_html (
    $copyColours,
    array $backgroundColours,
    $emptyMarkup
  ){
    $colours = estab_recipient_copy_colours ($copyColours);
    if (count ($colours) === 0) {
      return "<td style=\"text-align: center; background: rgb(250, 250, 250); \">".
        (string) $emptyMarkup."</td>";
    }
    $background = estab_recipient_copy_background (
      $copyColours,
      $backgroundColours,
      "rgb(250, 250, 250)"
    );
    $names = array (
      "bl" => "blau",
      "gn" => "grün",
      "rt" => "rot",
      "ge" => "gelb",
      "gb" => "gelb",
    );
    $labels = array ();
    foreach ($colours as $colour) {
      $labels [] = $names [$colour];
    }
    $ink = estab_recipient_copy_ink (
      $copyColours,
      $backgroundColours,
      "rgb(250, 250, 250)"
    );
    return "<td style=\"text-align: center; background: ".
      htmlspecialchars (
        $background,
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        "UTF-8"
      ).
      "; color: ".
      htmlspecialchars (
        $ink,
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        "UTF-8"
      ).
      ";\" title=\"".
      htmlspecialchars (
        "Durchschriften: ".implode (", ", $labels),
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        "UTF-8"
      ).
      "\">X</td>";
  }

  function extraiereempfaenger ($empf){
    return estab_recipient_copy_map ($empf);
  }


  /****************************************************************************\
  | Umwandlung von conv_datetime_takzeit ->
  | Formateinausgang:  YYYY-MM-TT hh:mm:ss
  | Formatausgang   :  TThhmmMMYYYY
  \****************************************************************************/
  function konv_datetime_taktime ($datetime){
    include ("../4fcfg/config.inc.php");
    // Datenbankzeit konvertiert in taktische Zeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    return estab_datetime_to_tactical ($datetime, $tak_monate);
  }


  /****************************************************************************\
  | Umwandlung von Taktischerzeit nach Datetime
  | Formateinausgang:  YYYY-MM-TT hh:mm:ss
  | Formatausgang   :  TThhmmMMYYYY
  \****************************************************************************/
  /**
   * Eine taktische Zeitgruppe in eine Datenbankzeit -- oder null.
   *
   * Null heisst: Es gibt keine Zeitangabe. Frueher stand hier "", und das
   * ist keine Zeit, sondern eine Behauptung: In einer DATETIME-Spalte weist
   * MariaDB den leeren Text im strikten Modus mit Fehler 1292 ab, und der
   * ganze Vorgang scheitert.
   *
   * Aufgefallen ist das, als die Abfassungszeit dem Fernmelder gesperrt
   * wurde. Sie bleibt beim Eingang leer -- so gewollt, denn er hat die
   * Nachricht nicht abgefasst -- und das gesperrte Feld sendet trotzdem mit.
   * Der Fernmelder konnte daraufhin keinen Eingang mehr aufnehmen.
   *
   * Die Spalten lassen NULL zu; das ist die Schreibweise fuer "nicht
   * angegeben".
   */
  function konv_taktime_datetime ($taktime){
    include ("../4fcfg/config.inc.php");
    // taktische Zeit konvertiert in Datenbankzeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    if (strlen ($taktime) == 13){
      $tag    = substr ($taktime, 0, 2);
      $stunde = substr ($taktime, 2, 2);
      $minute = substr ($taktime, 4, 2);
      $monat  = substr ($taktime, 6, 3);
      $jahr   = substr ($taktime, 9, 4);
      if (!isset ($rew_tak_monate [$monat])) {
        return (null);
      }
      $monat = $rew_tak_monate [$monat];
      return ($jahr."-".$monat."-".$tag." ".$stunde.":".$minute.":00" );
    } else {
      return (null);
    }
  }


?>
