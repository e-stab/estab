<?php

require_once __DIR__ . "/../app/legacy_mysql.php";
require_once __DIR__ . "/../app/tabelle.php";

if (!defined ("debug")) { define ("debug", false); }
if (ob_get_level () === 0) { ob_start (); }
/******************************************************************************\
technisches Betriebsbuch

  Szenario "Kein globaler Einsatz aktiv."

    + Roter Sperrhinweis mit Verweis auf die Einsatzverwaltung
    + Keine fachlichen Eingaben

  Szenario "Globaler Einsatz aktiv."

    + Anzeige des globalen Einsatzkopfs
    + Lesender Zugriff mit der im Einsatzmodus wirksamen Funktion
    + Eintragsfunktion fuer A/W in der Rolle Fernmelder

  Szenario "Schaltflaeche TBB-Eintrag wird betaetigt"

    + Anzeige des globalen Einsatzkopfs
    + Anzeige des Menues zur Eingabe eines TBB Eintrags

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/
class tbb_liste {


  var $tbb_titel_tbl     = false ;
  var $tbb_titel_gesetzt = false;
  var $tbb_einsatz_aktiv = false;
  var $tbb_art ;
  var $tbb_ort ;
  var $tbb_fuehrungsstelle ;

  var $tbb_funktion ;
  var $tbb_kuerzel ;
  var $tbb_benutzer ;
  var $tbb_rolle ;
  var $tbb_identity = array ();
  var $tbb_authorized ;

/*****************************************************************************\

\*****************************************************************************/
  // Klassenkonstruktor
  function __construct (){
    $this->tbb_liste ();
  }

  function tbb_liste (){
    $this->read_out_tbbtitel ();
      if (debug == true){    echo "tbb_liste 2 ->"; var_dump ($this->tbb_titel_gesetzt); echo "<br>";}
    $conf_tbb [0] = "Lfd.-Nr.";
    $conf_tbb [1] = "Datum/Zeit";
    $conf_tbb [2] = "Betrieb / Personal / Dienst";
    $conf_tbb [3] = "Kanal / Rufgruppe / Bedienung";
    $conf_tbb [4] = "Nachricht von / an";
    $conf_tbb [5] = "Betriebsablauf / Ereignis / Störung";
    $conf_tbb [6] = "Quittung / Empfänger / Aushändigung";
    $this->spaltenanzahl = count ($conf_tbb);
    $this->spaltenkoepfe = $conf_tbb;
  }


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


/*****************************************************************************\

\*****************************************************************************/
  function tbb_ueberschrift(){
    echo "<header class=\"estab-tool-hero\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Einsatzdokumentation · TBB</p>\n";
    echo "<h1>Technisches Betriebsbuch</h1>\n";
    echo "<p>Chronologische Dokumentation des technischen Betriebs im aktiven ";
    echo "Einsatz. ";
    echo $this->tbb_authorized
      ? "Ihre Funktion darf neue Einträge anlegen."
      : "Ihre Funktion hat lesenden Zugriff.";
    echo "</p>\n</header>\n";
  }


/*****************************************************************************\

\*****************************************************************************/
  function set_db_para ($newdb_server, $newdb_name, $newdb_table, $newdb_user, $newdb_pw){
    $this->db_server = $newdb_server ;
    $this->db_name   = $newdb_name ;
    $this->db_table  = $newdb_table ;
    $this->db_user   = $newdb_user ;
    $this->db_pw     = $newdb_pw ;
// echo "Datenbankparameter = ".$this->db_server." - ".$this->db_name." - ".$this->db_table." - ".$this->db_user." - ".$this->db_pw."<br>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function query_table ($query){
    $this->result = array ();
    $this->sqlquery = $query ;

    $db = mysql_connect($this->db_server,$this->db_user, $this->db_pw)
       or die ("[query_table] Konnte keine Verbindung zur Datenbank herstellen");
    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       estab_legacy_database_failure ("tbb_query_table", $query);

    $this->resultcount = mysql_num_rows($query_result);

    for ($i=1;$i<=$this->resultcount;$i++){
      $this->result[$i] = mysql_fetch_assoc($query_result);
    }
    mysql_free_result($query_result);
    mysql_close ($db);
    return ($this->resultcount > 0 ? $this->result : "");
  } // function read_table


/*****************************************************************************\

\*****************************************************************************/
  function speichen_tbbtitel ($daten){
    unset ($daten);
    throw new LogicException (
      "Einsatzdaten werden ausschließlich in der Administration verwaltet."
    );
  }


/*****************************************************************************\

\*****************************************************************************/
  function create_tbbtitel_tbl(){
    // Legacy compatibility hook. Global incidents are migration-owned.
  }


/*****************************************************************************\

\*****************************************************************************/
  function tbb_tableexist () {
    $this->tbb_titel_tbl = true;
if (debug == true){ echo "tbb_tableexist==>"; var_dump($this->tbb_titel_tbl); echo "<br>"; }
    return true;
  }


/*****************************************************************************\

\*****************************************************************************/
  function read_out_tbbtitel (){
      if (debug == true){echo "read_out_tbbtitel<br>";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $incident = estab_logbook_active_incident ($conf_4f_db);
    if (is_array ($incident)){
      $this->tbb_einsatz_aktiv = true;
      $this->tbb_art =
        (string) ($incident ["kennung"] ?? "")." · ".
        (string) ($incident ["name"] ?? "");
      $this->tbb_ort = (string) ($incident ["ort"] ?? "") ;
      try {
        $this->tbb_fuehrungsstelle =
          estab_incident_command_post_name ($incident);
        $this->tbb_titel_gesetzt = true;
      } catch (EstabIncidentConfigurationException) {
        $this->tbb_fuehrungsstelle = "";
        $this->tbb_titel_gesetzt = false;
      }
      $this->tbb_titel_tbl = true;
    } else {
      $this->tbb_einsatz_aktiv = false;
      $this->tbb_art = "";
      $this->tbb_ort = "";
      $this->tbb_fuehrungsstelle = "";
      $this->tbb_titel_gesetzt = false;
      $this->tbb_titel_tbl = true;
    }
  }

  var $spaltenanzahl ;
  var $spaltenkoepfe ; // Array mit den Bezeichnungen der Spalten

/*****************************************************************************\

\*****************************************************************************/
  function tbb_pre_html (){
    echo "<!doctype html>\n";
    echo "<html lang=\"de\">\n";
    echo "<head>\n";
    echo "  <meta charset=\"UTF-8\">\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    if (!$this->tbb_authorized) {
      echo "<meta http-equiv=\"refresh\" content=\"10\">\n";
    }
    echo "  <title>eStab Technisches Betriebsbuch</title>\n";
    echo estab_session_ui_stylesheet ()."\n";
    echo "</head>\n";
    echo "<body class=\"estab-tool-page\">\n";
    echo "<main class=\"estab-tool-main estab-tool-main-wide\" ";
    echo "data-estab-logbook=\"ttb\">\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_post_html () {
    echo "<footer class=\"estab-tool-footer\">\n";
    echo "<a href=\"".estab_auth_html (estab_application_root ()).
         "\">Zur eStab-Übersicht</a>\n";
    echo "<span>Die Ansicht aktualisiert sich bei lesendem Zugriff automatisch.</span>\n";
    echo "</footer>\n";
    echo "</main>\n";
    echo "</body>\n";
    echo "</html>\n";
  }


/*****************************************************************************\

\*****************************************************************************/
  function konv_datetime_taktime ($datetime){
    include ("../4fcfg/config.inc.php");
    // Datenbankzeit konvertiert in taktische Zeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    list ($datum, $zeit) = explode (" ",$datetime);
    list ($yyyy, $MM, $tt) = explode ("-", $datum);
    list ($hh, $mm, $ss) = explode (":", $zeit);
    return ($tt.$hh.$mm.$tak_monate[$MM].$yyyy);
  }


/*****************************************************************************\

\*****************************************************************************/
  function convtodatetime ($datum, $zeit){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $jahr   = date ("Y");
    $datetime = $jahr."-".$monat."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }



/*****************************************************************************\

\*****************************************************************************/
  function tbb_menue (){
    $action = estab_auth_html (estab_application_url ("fmtbb/tbb.php"));
    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"ttb-action-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"ttb-action-title\">Neuer Betriebsbucheintrag</h2>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-actions\" action=\"".$action."\" method=\"get\">\n";
    echo "<button class=\"estab-button estab-button-primary\" ";
    echo "type=\"submit\" name=\"tbb_menue\" value=\"eintrag\">";
    echo "Neuen TBB-Eintrag anlegen</button>\n";
    echo "</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_getdate ( ){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $result = estab_logbook_entries (
      $conf_4f_db,
      $conf_tbl ["tbb"],
      "tbb"
    );
  if (debug == true){echo "tbb_getdate-->"; var_dump($result);echo "<br>";}
    return $result === array () ? "" : $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_tbb_eintrag ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_entry (
      is_array ($daten) ? $daten : array (),
      "tbb"
    );
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültiger TBB-Eintrag");
    }
    estab_logbook_insert_entry (
      $conf_4f_db,
      $conf_tbl ["tbb"],
      "tbb",
      $validation ["data"],
      $this->tbb_identity
    );
  }

/*****************************************************************************\

\*****************************************************************************/
var $lfd ;
var $task ;

  function tbb_eintragsmenue ($data) {
    $correctionId = is_int ($data) && $data > 0 ? $data : null;
    $action = estab_auth_html (estab_application_url ("fmtbb/tbb.php"));

    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"ttb-entry-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"ttb-entry-title\">";
    echo $correctionId === null
      ? "TBB-Eintrag erfassen"
      : "Ausgewählten TBB-Eintrag berichtigen";
    echo "</h2>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-form\" method=\"post\" action=\"".$action.
         "\" name=\"tbbeintrag\" data-estab-dirty-guard ".
         "data-estab-requires-incident>\n";
    echo estab_csrf_field ()."\n";
    echo "<input type=\"hidden\" name=\"logbook_action\" value=\"save_entry\">\n";
    if ($correctionId !== null) {
      echo "<input type=\"hidden\" name=\"entry_type\" value=\"korrektur\">\n";
      echo "<input type=\"hidden\" name=\"correction_of\" value=\"".
           (int) $correctionId."\">\n";
    }
    echo "<div class=\"estab-tool-form-grid\">\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"ttb-event-time\">Zeitpunkt des Vorgangs</label>\n";
    echo "<input id=\"ttb-event-time\" type=\"datetime-local\" ";
    echo "name=\"event_time\" required value=\"".
         estab_auth_html (date ("Y-m-d\\TH:i"))."\">\n";
    echo "<small>Die unveränderliche Erfassungszeit wird zusätzlich ";
    echo "automatisch gespeichert.</small>\n</div>\n";
    if ($correctionId === null) {
      echo "<div class=\"estab-tool-field\">\n";
      echo "<label for=\"ttb-entry-type\">Primärer Bereich</label>\n";
      echo "<select id=\"ttb-entry-type\" name=\"entry_type\" required>\n";
      foreach (estab_logbook_ttb_manual_entry_types () as $value => $label) {
        if ($value === "korrektur") { continue; }
        echo "<option value=\"".estab_auth_html ($value)."\">".
             estab_auth_html ($label)."</option>\n";
      }
      echo "</select>\n<small>Bestimmt die fachliche Einordnung; mehrere ";
      echo "Inhaltsspalten dürfen gemeinsam befüllt werden.</small>\n</div>\n";
    }
    echo "</div>\n";
    echo "<p class=\"estab-tool-feedback\">Nachrichtenbeförderungen werden ";
    echo "automatisch und genau einmal aus dem verbindlichen Nachrichtenworkflow ";
    echo "übernommen. Der Bereich „Nachricht von / an“ kann deshalb nicht ";
    echo "als manueller Primärbereich gewählt werden.</p>\n";
    echo "<p class=\"estab-tool-feedback\"><strong>Eigenständiger Nachweis:</strong> ";
    echo "Formulieren Sie jeden Eintrag so, dass das TBB auch ohne Anlagen in ";
    echo "Grundzügen verständlich bleibt.</p>\n";
    $fields = array (
      "personnel_duty" => array (
        "Betrieb, Personal und Dienstübergabe",
        "Betriebsaufnahme/-ende, Einsatzbereitschaft, Namen und Funktionen, Ablösung sowie Dienstübergabe/-übernahme."
      ),
      "channel" => array (
        "Kanal, Rufgruppe und Bedienung",
        "Kanal/Rufgruppe, Betriebsart, Bedienung sowie Wechsel mit bisherigem und neuem Wert."
      ),
      "message_route" => array (
        "Nachricht von / an",
        "Gegenstelle und Richtung einer aufgenommenen oder weitergegebenen Nachricht."
      ),
      "operations" => array (
        "Betriebsablauf, Ereignis und Störung",
        "Betriebsvorgang, besondere Ereignisse, Störung/Unterbrechung und deren Beseitigung."
      ),
      "receipt" => array (
        "Quittung, Empfänger und Aushändigung",
        "Empfangsbestätigung, Empfänger sowie Zeitpunkt und Person der Aushändigung."
      ),
    );
    foreach ($fields as $name => $field) {
      $id = "ttb-".str_replace ("_", "-", $name);
      echo "<div class=\"estab-tool-field\">\n";
      echo "<label for=\"".$id."\">".estab_auth_html ($field [0])."</label>\n";
      echo "<textarea id=\"".$id."\" maxlength=\"10000\" name=\"".
           estab_auth_html ($name)."\"".
           ($name === "operations" ? " autofocus" : "")."></textarea>\n";
      echo "<small>".estab_auth_html ($field [1]).
           " Höchstens 10.000 Zeichen.</small>\n</div>\n";
    }
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"ttb-comment\">Zusätzlicher Nachweis".
         ($correctionId !== null ? " / Korrekturbegründung *" : "")."</label>\n";
    echo "<textarea id=\"ttb-comment\" maxlength=\"10000\" ";
    echo "name=\"comment\"".($correctionId !== null ? " required" : "")."></textarea>\n";
    echo "<small>Optional; bei einer Berichtigung verpflichtend. Höchstens ";
    echo "10.000 Zeichen.</small>\n</div>\n";
    echo "<div class=\"estab-tool-actions\">\n";
    echo "<button class=\"estab-button estab-button-primary\" type=\"submit\">";
    echo "TBB-Eintrag speichern</button>\n";
    echo "<a class=\"estab-button\" href=\"".$action."\">Abbrechen</a>\n";
    echo "</div>\n</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function tbb_einsatzdaten (){
    echo "<section class=\"estab-tool-status estab-tool-status-active\" ";
    echo "aria-label=\"Aktiver Einsatz\">\n<div>\n";
    echo "<span>Aktiver Einsatz</span>\n";
    echo "<strong>".estab_auth_html ($this->tbb_art)."</strong>\n";
    echo "<span>Führungsstelle: ".estab_auth_html (
      $this->tbb_fuehrungsstelle !== ""
        ? $this->tbb_fuehrungsstelle
        : "nicht festgelegt"
    )."</span>\n";
    echo "<span>Ort: ".estab_auth_html (
      $this->tbb_ort !== "" ? $this->tbb_ort : "nicht angegeben"
    )."</span>\n";
    echo "</div>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function headline (){
    echo "<tr>\n";
    for ($i=0; $i<$this->spaltenanzahl; $i++){
      echo "<th scope=\"col\">".estab_auth_html ($this->spaltenkoepfe [$i]).
           "</th>\n";
    }
    echo "</tr>";
  }

/*****************************************************************************\

\*****************************************************************************/
  function inputeinsatzstammdaten (){
    echo "<aside class=\"estab-tool-notice estab-tool-notice-warning\">\n";
    echo "<strong>Einsatzdaten werden global verwaltet.</strong>\n";
    echo "<p>Legen Sie Einsätze ausschließlich in der Administration an und ";
    echo "aktivieren Sie dort den gewünschten Einsatz.</p>\n</aside>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function printlist ($daten){
    // Schreibe die Liste
    if ( $daten != "" ) {
      echo "<section class=\"estab-tool-panel\" aria-labelledby=\"ttb-list-title\">\n";
      echo "<header class=\"estab-tool-panel-heading\">\n";
      echo "<h2 id=\"ttb-list-title\">Einträge des aktiven Einsatzes</h2>\n";
      echo "</header>\n";
      /*
       * Das Technische Betriebsbuch kommt aus dem Tabellenbauteil
       * (app/tabelle.php). Der Inhalt jeder Zelle bleibt Zeile fuer Zeile,
       * wie er war -- die sieben Spalten des Fb Fue 44 eingeschlossen.
       */
      $localById = array ();
      foreach ($daten as $row) {
        $localById [(int) ($row ["tbb_lfd-nr"] ?? 0)] =
          (int) ($row ["estab_book_lfd"] ?? $row ["tbb_lfd-nr"] ?? 0);
      }
            $tbbZeilen = array ();
      foreach ( $daten as $line ){
        ob_start ();
        echo "<tr>";
        echo "<td class=\"estab-tool-table-number\" data-label=\"Lfd.-Nr.\">\n";
        echo (int) ($line ["estab_book_lfd"] ?? $line ["tbb_lfd-nr"]);
        $entryType = (string) ($line ["estab_entry_type"] ?? "legacy_import");
        $typeLabels = estab_logbook_ttb_entry_types ();
        $entryLabel = $typeLabels [$entryType] ?? (
          $entryType === "legacy_import" ? "Bestandseintrag" : $entryType
        );
        echo "<br><small>".estab_auth_html ($entryLabel)."</small>";
        if (!empty ($line ["estab_correction_of"])) {
          $targetId = (int) $line ["estab_correction_of"];
          echo "<br><small>zu Nr. ".(int) ($localById [$targetId] ?? $targetId).
               "</small>";
        }
        if (
          $this->tbb_authorized
          && $entryType !== "korrektur"
          && empty ($line ["estab_correction_of"])
        ) {
          echo "<br><a class=\"estab-button\" href=\"".
               estab_auth_html (estab_application_url ("fmtbb/tbb.php")).
               "?correct=".(int) $line ["tbb_lfd-nr"]."\">Berichtigen</a>";
        }
        echo "</td>\n";
        echo "<td data-label=\"Datum/Zeit\"><time>";
        $eventTime = (string) ($line ["estab_event_time"] ?? $line ["tbb_time"]);
        $recordedAt = (string) ($line ["estab_recorded_at"] ?? $line ["tbb_time"]);
        echo estab_auth_html ($this->konv_datetime_taktime ($eventTime));
        echo "</time><br><small>Erfasst ".estab_auth_html (
          $this->konv_datetime_taktime ($recordedAt)
        )."<br>".estab_auth_html ((string) ($line ["tbb_benutzer"] ?? "")).
        " · ".estab_auth_html (estab_function_display_name (
          (string) ($line ["tbb_funktion"] ?? "")
        )).
        " · ".estab_auth_html ((string) ($line ["tbb_kuerzel"] ?? "")).
        "</small>";
        echo "</td>\n";
        $contentColumns = array (
          "estab_personnel_duty" => "Betrieb / Personal / Dienst",
          "estab_channel" => "Kanal / Rufgruppe / Bedienung",
          "estab_message_route" => "Nachricht von / an",
          "estab_operations" => "Betriebsablauf / Ereignis / Störung",
          "estab_receipt" => "Quittung / Empfänger / Aushändigung",
        );
        foreach ($contentColumns as $column => $label) {
          $value = (string) ($line [$column] ?? "");
          if ($column === "estab_operations" && $value === "") {
            $value = (string) ($line ["tbb_aktion"] ?? "");
          }
          $value = estab_function_display_text ($value);
          echo "<td data-label=\"".estab_auth_html ($label)."\">";
          echo $value !== ""
            ? nl2br (estab_auth_html ($value), false)
            : "<span aria-label=\"keine Angabe\">—</span>";
          if ($column === "estab_operations" &&
              (string) ($line ["tbb_bemerk"] ?? "") !== "") {
            echo "<br><small>Nachweis: ".nl2br (estab_auth_html (
              estab_function_display_text ((string) $line ["tbb_bemerk"])
            ), false)."</small>";
          }
          echo "</td>\n";
        }
        $tbbRoh = (string) ob_get_clean ();
        $tbbZellen = estab_tabelle_zeile_zerlegen ($tbbRoh, 7);
        $tbbWerte = array (
          "nummer" => (string) (int) ($line ["estab_book_lfd"] ?? $line ["tbb_lfd-nr"]),
          "zeit" => $this->konv_datetime_taktime (
            (string) ($line ["estab_event_time"] ?? $line ["tbb_time"])
          ),
          "erfasst" => (string) ($line ["tbb_benutzer"] ?? "")." · "
            . (string) ($line ["tbb_kuerzel"] ?? ""),
        );
        $tbbSpaltenwerte = array (
          "estab_personnel_duty", "estab_channel", "estab_message_route",
          "estab_operations", "estab_receipt",
        );
        foreach ($tbbSpaltenwerte as $tbbLaufend => $tbbFeld) {
          $tbbWert = (string) ($line [$tbbFeld] ?? "");
          if ($tbbFeld === "estab_operations" && $tbbWert === "") {
            $tbbWert = (string) ($line ["tbb_aktion"] ?? "");
          }
          $tbbWerte ["s" . $tbbLaufend] = estab_function_display_text ($tbbWert);
        }
        foreach ($tbbZellen as $tbbNummer => $tbbInhalt) {
          $tbbWerte ["z" . $tbbNummer] = $tbbInhalt;
        }
        $tbbZeilen[] = $tbbWerte;
      }

      // Kopf, Schluessel, Breite, Art, sortierbar, klammern.
      $tbbAufbau = array ();
      // Sieben Spalten wie im Fb Fue 44: laufende Nummer, Datum/Zeit und
      // die fuenf Inhaltsspalten. Die Zahl steht auch im Zerleger; weicht
      // sie ab, bricht er ab, statt Zellen zu verschieben.
      foreach (array (
        array ("Lfd.-Nr.", "nummer", 7, "zahl", true, false),
        array ("Datum/Zeit", "zeit", 13, "zeit", true, false),
        array ("Betrieb / Personal / Dienst", "s0", 15, "text", false, true),
        array ("Kanal / Rufgruppe / Bedienung", "s1", 15, "text", false, true),
        array ("Nachricht von / an", "s2", 15, "text", false, true),
        array ("Betriebsablauf / Ereignis / Störung", "s3", 17, "text", false, true),
        array ("Quittung / Empfänger / Aushändigung", "s4", 18, "text", false, true),
      ) as $tbbNummer => $tbbSpalte) {
        $tbbAufbau[] = array (
          "schluessel" => $tbbSpalte [1],
          "kopf" => $tbbSpalte [0],
          "breite" => $tbbSpalte [2],
          "art" => $tbbSpalte [3],
          "sortierbar" => $tbbSpalte [4],
          "suchbar" => true,
          "klammern" => $tbbSpalte [5],
          "zelle" => static function (array $z) use ($tbbNummer): string {
            return $z ["z" . $tbbNummer];
          },
        );
      }

      echo estab_tabelle_markup (array (
        "id" => "tbb",
        "beschriftung" => "Einträge im Technischen Betriebsbuch",
        "mindestbreite" => "68rem",
        "spalten" => $tbbAufbau,
        "zeilen" => $tbbZeilen,
        "leer" => "Kein Eintrag entspricht den gesetzten Filtern.",
      ));
      echo "</section>\n";
    } else {
      echo "<section class=\"estab-tool-panel\" aria-label=\"Keine TBB-Einträge\">\n";
      echo "<p class=\"estab-tool-empty\">Noch keine TBB-Einträge vorhanden.</p>\n";
      echo "</section>\n";
    }
  }

} // class tbb_liste
/**************************************************************************************************************************/

if (session_status () !== PHP_SESSION_ACTIVE) {
  session_start ();
}
require_once __DIR__ . "/../app/logbook.php";
require_once __DIR__ . "/../app/navigation.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/session_ui.php";
estab_navigation_require_session ($_SESSION, "technical-log", $_SERVER);

include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");

$identity = estab_read_session_identity ($_SESSION);
estab_navigation_require_selected_duty (
  $_SESSION,
  $identity,
  "technical-log",
  $_SERVER
);
estab_session_ui_start ($_SESSION);

$berechtigt = false;
$hasTbbCapability = false;
$istTbbFuehrung = false;
$readConnection = null;
$readError = null;
try {
  $readConnection = estab_auth_connect ($conf_4f_db);
  $readScope = estab_read_require_operational_scope (
    $readConnection,
    $identity
  );
  $identity = $readScope ["identity"];
  estab_permission_context_set_from_incident ($readScope ["incident"]);
  // Die Fachzustaendigkeit des Buches, nicht die des Meldungsverkehrs.
  // BEFOERDERUNG traegt der A/W; das TBB fuehrt der LdF, und der traegt
  // FERNMELDEBETRIEB.
  $hasTbbCapability = estab_dv_has_write_capability (
    $readConnection,
    (int) $readScope ["incident"]["active_einsatz_id"],
    $identity,
    "FERNMELDEBETRIEB"
  );
  $istTbbFuehrung = estab_logbook_is_designated_writer (
    $readConnection,
    (int) $readScope ["incident"]["active_einsatz_id"],
    $identity,
    "tbb"
  );
  $berechtigt = $hasTbbCapability && $istTbbFuehrung;
} catch (EstabNoActiveIncidentException $exception) {
  $readError = array (
    409,
    "Kein Einsatz ist aktiv. Das technische Betriebsbuch enthält derzeit ".
    "keine freigegebenen Einsatzdaten."
  );
} catch (EstabReadPermissionException $exception) {
  $readError = array (403, $exception->getMessage ());
} catch (Throwable $exception) {
  error_log ("TBB read authorization failed: ".$exception->getMessage ());
  $readError = array (
    503,
    "Die Leseberechtigung für das technische Betriebsbuch kann derzeit nicht ".
    "geprüft werden."
  );
} finally {
  if (isset ($readConnection) && $readConnection instanceof mysqli) {
    estab_auth_close ($readConnection);
  }
}
if (is_array ($readError)) {
  estab_logbook_abort ($readError [0], $readError [1]);
}

$requestMethod = isset ($_SERVER ["REQUEST_METHOD"]) && is_string ($_SERVER ["REQUEST_METHOD"])
  ? strtoupper ($_SERVER ["REQUEST_METHOD"])
  : "GET";
if (!in_array ($requestMethod, array ("GET", "POST"), true)) {
  header ("Allow: GET, POST");
  estab_logbook_abort (405, "Nicht unterstützte Anfragemethode.");
}

try {
  $tbbobj = new tbb_liste;
} catch (Throwable $exception) {
  error_log ("TBB initialization failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das technische Betriebsbuch ist vorübergehend nicht verfügbar.");
}
$tbbobj->tbb_authorized =
  $berechtigt && $tbbobj->tbb_titel_gesetzt;
$tbbobj->tbb_funktion = $identity ["funktion"];
$tbbobj->tbb_kuerzel = $identity ["kuerzel"];
$tbbobj->tbb_benutzer = $identity ["benutzer"];
$tbbobj->tbb_rolle = $identity ["rolle"];
$tbbobj->tbb_identity = $identity;

if ($requestMethod === "POST") {
  if (!$berechtigt) {
    estab_logbook_abort (
      403,
      "Dieses Konto darf im aktuellen Einsatz keine TTB-Einträge schreiben. " .
      "Prüfen Sie Kontostatus und Berechtigungsmodus."
    );
  }
  estab_logbook_require_csrf ($_SERVER, $_POST);
  $action = isset ($_POST ["logbook_action"]) && is_string ($_POST ["logbook_action"])
    ? $_POST ["logbook_action"]
    : "";

  try {
    if ($action === "save_entry") {
      if (!$tbbobj->tbb_titel_gesetzt) {
        if ($tbbobj->tbb_einsatz_aktiv) {
          estab_logbook_abort (
            409,
            "Der aktive Einsatz ist unvollständig. TBB-Eingaben sind ".
            "gesperrt; ergänzen Sie zuerst den Namen der Führungsstelle in ".
            "der Administration."
          );
        }
        estab_logbook_abort (
          409,
          "Kein Einsatz ist aktiv. TBB-Eingaben sind gesperrt; aktivieren Sie ".
          "zuerst einen Einsatz in der Administration."
        );
      }
      $validation = estab_logbook_validate_entry ($_POST, "tbb");
      if (!$validation ["valid"]) {
        estab_logbook_abort (
          422,
          "Der TBB-Eintrag ist ungültig, leer oder überschreitet 10000 Zeichen."
        );
      }
      $tbbobj->speichen_tbb_eintrag ($validation ["data"]);
    } else {
      estab_logbook_abort (400, "Unbekannte TBB-Aktion.");
    }
  } catch (EstabDvPermissionException $exception) {
    estab_logbook_abort (403, $exception->getMessage ());
  } catch (EstabIncidentConfigurationException $exception) {
    error_log ("TBB write blocked by incident configuration: ".
      $exception->getMessage ());
    estab_logbook_abort (
      409,
      "Der aktive Einsatz ist unvollständig. TBB-Eingaben sind gesperrt; ".
      "ergänzen Sie zuerst den Namen der Führungsstelle in der Administration."
    );
  } catch (EstabIncidentConflictException|EstabDvConflictException $exception) {
    estab_logbook_abort (409, $exception->getMessage ());
  } catch (EstabNoActiveIncidentException $exception) {
    error_log ("TBB write blocked: ".$exception->getMessage ());
    estab_logbook_abort (
      409,
      "Kein Einsatz ist aktiv. TBB-Eingaben sind gesperrt; aktivieren Sie ".
      "zuerst einen Einsatz in der Administration."
    );
  } catch (Throwable $exception) {
    error_log ("TBB write failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Der TBB-Eintrag konnte nicht gespeichert werden.");
  }

  estab_logbook_redirect (estab_application_url ("fmtbb/tbb.php"));
}

$tbbobj->tbb_pre_html ();
$tbbobj->tbb_ueberschrift ();

if (!$tbbobj->tbb_titel_gesetzt) {
  echo "<section class=\"estab-tool-status estab-tool-status-danger\" ";
  echo "role=\"alert\" ";
  if ($tbbobj->tbb_einsatz_aktiv) {
    echo "data-estab-incident-incomplete><div>";
    echo "<strong>Aktiver Einsatz unvollständig – TBB-Eingaben sind ";
    echo "gesperrt.</strong>";
    echo "<span>Ergänzen Sie in der Administration zuerst den Namen der ";
    echo "Führungsstelle.</span></div></section>\n";
  } else {
    echo "data-estab-no-active-incident><div>";
    echo "<strong>Kein Einsatz aktiv – TBB-Eingaben sind gesperrt.</strong>";
    echo "<span>";
    echo "Legen Sie in der Administration einen Einsatz an oder aktivieren Sie ";
    echo "einen vorhandenen Einsatz.</span></div></section>\n";
  }
} else {
  $tbbobj->tbb_einsatzdaten ();
  $entryFormRequested = isset ($_GET ["tbb_eintrag_x"])
    || (
      isset ($_GET ["tbb_menue"])
      && is_string ($_GET ["tbb_menue"])
      && $_GET ["tbb_menue"] === "eintrag"
    );
  if ($tbbobj->tbb_authorized && $entryFormRequested) {
    $tbbobj->tbb_eintragsmenue ("");
  } elseif (
    $tbbobj->tbb_authorized
    && isset ($_GET ["correct"])
    && is_string ($_GET ["correct"])
    && preg_match ("/\\A[1-9][0-9]*\\z/D", $_GET ["correct"]) === 1
  ) {
    $tbbobj->tbb_eintragsmenue ((int) $_GET ["correct"]);
  } elseif ($tbbobj->tbb_authorized) {
    $tbbobj->tbb_menue ();
  } else {
    echo "<aside class=\"estab-tool-notice estab-tool-notice-warning\">\n";
    echo "<strong>TBB schreibgeschützt.</strong>\n<p>";
    echo "Ihre aktuell wirksamen Funktionen erlauben das Lesen, besitzen ".
      "aber nicht die Fachzuständigkeit für TTB-Einträge.";
    echo "</p>\n</aside>\n";
  }
  $tbbobj->printlist ($tbbobj->tbb_getdate ());
}

$tbbobj->tbb_post_html ();
?>
