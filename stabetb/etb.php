<?php

require_once __DIR__ . "/../app/legacy_mysql.php";

if (!defined ("debug")) { define ("debug", false); }
if (ob_get_level () === 0) { ob_start (); }
/******************************************************************************\
Einsatz Tage Buch

  Szenario "Kein globaler Einsatz aktiv."

    + Roter Sperrhinweis mit Verweis auf die Einsatzverwaltung
    + Keine fachlichen Eingaben

  Szenario "Globaler Einsatz aktiv."

    + Anzeige des globalen Einsatzkopfs
    + Lesender Zugriff mit der im Einsatzmodus wirksamen Funktion
    + Eintragsfunktion nur mit der Faehigkeit EINSATZTAGEBUCH

  Szenario "Schaltflaeche ETB-Eintrag wird betaetigt"

    + Anzeige des globalen Einsatzkopfs
    + Anzeige des Menues zur Eingabe eines ETB Eintrags

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/
class etb_liste {


  var $etb_titel_tbl     = false ;
  var $etb_titel_gesetzt = false;
  var $etb_einsatz_aktiv = false;
  var $etb_einsatz_id = 0;
  var $etb_art ;
  var $etb_ort ;
  var $etb_fuehrungsstelle ;

  var $etb_funktion ;
  var $etb_kuerzel ;
  var $etb_benutzer ;
  var $etb_rolle ;
  var $etb_identity = array ();
  var $etb_authorized ;

/*****************************************************************************\

\*****************************************************************************/
  // Klassenkonstruktor
  function __construct (){
    $this->etb_liste ();
  }

  function etb_liste (){
    $this->read_out_etbtitel ();
      if (debug == true){    echo "etb_liste 2 ->"; var_dump ($this->etb_titel_gesetzt); echo "<br>";}
    $conf_etb [0] = "Lfd.-Nr.";
    $conf_etb [1] = "Ereigniszeit";
    $conf_etb [2] = "Art";
    $conf_etb [3] = "Darstellung der Ereignisse";
    $conf_etb [4] = "Bemerkung / Nachweise";
    $conf_etb [5] = "Erfasst";
    $conf_etb [6] = "Aktion";

    $this->spaltenanzahl = count ($conf_etb);
    $this->spaltenkoepfe = $conf_etb;
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
  function etb_ueberschrift(){
    echo "<header class=\"estab-tool-hero\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Einsatzdokumentation · ETB</p>\n";
    echo "<h1>Einsatztagebuch</h1>\n";
    echo "<p>Chronologische Dokumentation des aktiven Einsatzes. ";
    echo $this->etb_authorized
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
       estab_legacy_database_failure ("etb_query_table", $query);

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
  function speichen_etbtitel ($daten){
    unset ($daten);
    throw new LogicException (
      "Einsatzdaten werden ausschließlich in der Administration verwaltet."
    );
  }


/*****************************************************************************\

\*****************************************************************************/
  function create_etbtitel_tbl(){
    // Legacy compatibility hook. Global incidents are migration-owned.
  }


/*****************************************************************************\

\*****************************************************************************/
  function etb_tableexist () {
    $this->etb_titel_tbl = true;
if (debug == true){ echo "etb_tableexist==>"; var_dump($this->etb_titel_tbl); echo "<br>"; }
    return true;
  }


/*****************************************************************************\

\*****************************************************************************/
  function read_out_etbtitel (){
      if (debug == true){echo "read_out_etbtitel<br>";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $incident = estab_logbook_active_incident ($conf_4f_db);
    if (is_array ($incident)){
      $this->etb_einsatz_aktiv = true;
      $this->etb_einsatz_id = (int) ($incident ["active_einsatz_id"] ?? 0);
      $this->etb_art =
        (string) ($incident ["kennung"] ?? "")." · ".
        (string) ($incident ["name"] ?? "");
      $this->etb_ort = (string) ($incident ["ort"] ?? "") ;
      try {
        $this->etb_fuehrungsstelle =
          estab_incident_command_post_name ($incident);
        $this->etb_titel_gesetzt = true;
      } catch (EstabIncidentConfigurationException) {
        $this->etb_fuehrungsstelle = "";
        $this->etb_titel_gesetzt = false;
      }
      $this->etb_titel_tbl = true;
    } else {
      $this->etb_einsatz_aktiv = false;
      $this->etb_einsatz_id = 0;
      $this->etb_art = "";
      $this->etb_ort = "";
      $this->etb_fuehrungsstelle = "";
      $this->etb_titel_gesetzt = false;
      $this->etb_titel_tbl = true;
    }
  }

  var $spaltenanzahl ;
  var $spaltenkoepfe ; // Array mit den Bezeichnungen der Spalten

/*****************************************************************************\

\*****************************************************************************/
  function etb_pre_html (){
    echo "<!doctype html>\n";
    echo "<html lang=\"de\">\n";
    echo "<head>\n";
    echo "  <meta charset=\"UTF-8\">\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    if (!$this->etb_authorized) {
      echo "<meta http-equiv=\"refresh\" content=\"10\">\n";
    }
    echo "  <title>eStab Einsatztagebuch</title>\n";
    echo estab_session_ui_stylesheet ()."\n";
    echo "</head>\n";
    echo "<body class=\"estab-tool-page\">\n";
    echo "<main class=\"estab-tool-main estab-tool-main-wide\" ";
    echo "data-estab-logbook=\"etb\">\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_post_html () {
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
  function etb_menue (){
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));
    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-action-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"etb-action-title\">Neuer Tagebucheintrag</h2>\n";
    echo "<p>Erfassen Sie den Vorgang in der unveränderlichen Reihenfolge des ";
    echo "Buches und kennzeichnen Sie Aufgaben, Befehle, Erledigungen, ";
    echo "Kräfteanforderungen oder besonders wichtige Einträge.</p>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-actions\" action=\"".$action."\" method=\"get\">\n";
    echo "<button class=\"estab-button estab-button-primary\" ";
    echo "type=\"submit\" name=\"etb_menue\" value=\"eintrag\">";
    echo "Neuen ETB-Eintrag anlegen</button>\n";
    echo "</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_getdate (array $filters = array ()){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $result = estab_logbook_entries (
      $conf_4f_db,
      $conf_tbl ["etb"],
      "etb",
      $filters
    );
  if (debug == true){echo "etb_getdate-->"; var_dump($result);echo "<br>";}
    return $result === array () ? "" : $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_etb_eintrag ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_entry (
      is_array ($daten) ? $daten : array (),
      "etb"
    );
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültiger ETB-Eintrag");
    }
    estab_logbook_insert_entry (
      $conf_4f_db,
      $conf_tbl ["etb"],
      "etb",
      $validation ["data"],
      $this->etb_identity
    );
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_suchmenue (array $filters, int $resultCount){
    $query = (string) ($filters ["query"] ?? "");
    $type = (string) ($filters ["type"] ?? "");
    $reference = (string) ($filters ["reference"] ?? "");
    $assignment = (string) ($filters ["assignment"] ?? "");
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));
    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-search-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Schnelle Auswertung</p>\n";
    echo "<h2 id=\"etb-search-title\">ETB durchsuchen und filtern</h2>\n";
    echo "<p>Eine leere Suche zeigt das vollständige Einsatztagebuch. ";
    echo "Volltext, Art, Zuordnung und Bezug können einzeln oder gemeinsam verwendet ";
    echo "werden.</p>\n</header>\n";
    echo "<form class=\"estab-tool-form\" method=\"get\" action=\"".$action."\">\n";
    echo "<div class=\"estab-tool-form-grid\">\n";
    echo "<div class=\"estab-tool-field estab-tool-field-wide\">\n";
    echo "<label for=\"etb-search-query\">Volltext</label>\n";
    echo "<input id=\"etb-search-query\" type=\"search\" name=\"q\" ";
    echo "maxlength=\"200\" value=\"".estab_auth_html ($query)."\" ";
    echo "placeholder=\"Ereignis, Bemerkung, Zuordnung, Person oder Referenz\">\n";
    echo "</div>\n<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-search-type\">Art</label>\n";
    echo "<select id=\"etb-search-type\" name=\"art\">\n";
    echo "<option value=\"\">Alle Arten</option>\n";
    $typeLabels = estab_logbook_entry_types ();
    $typeLabels ["legacy_import"] = "Bestandseintrag";
    foreach ($typeLabels as $value => $label) {
      echo "<option value=\"".estab_auth_html ($value)."\"";
      echo hash_equals ((string) $value, $type) ? " selected" : "";
      echo ">".estab_auth_html ($label)."</option>\n";
    }
    echo "</select>\n</div>\n<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-search-assignment\">Zuordnung</label>\n";
    echo "<input id=\"etb-search-assignment\" type=\"search\" ";
    echo "name=\"zuordnung\" maxlength=\"200\" value=\"".
         estab_auth_html ($assignment)."\" ";
    echo "placeholder=\"Funktion, Name oder Kürzel\">\n";
    echo "<small>Filtert nach der beim Speichern festgehaltenen ";
    echo "Bearbeitungszuordnung.</small>\n";
    echo "</div>\n<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-search-reference\">ETB-Nr. oder Bestandsbezug</label>\n";
    echo "<input id=\"etb-search-reference\" type=\"search\" name=\"bezug\" ";
    echo "maxlength=\"100\" value=\"".estab_auth_html ($reference)."\" ";
    echo "placeholder=\"z. B. 17 oder historischer Bezug\">\n";
    echo "<small>Findet lokale ETB-Nummer, Korrekturbezug, Nachricht, ";
    echo "ETB-Anlagennummer (z. B. ETB 12-17-1), Ablagekennzeichen oder ";
    echo "historische Bestandsreferenz.</small>\n</div>\n</div>\n";
    echo "<div class=\"estab-tool-actions\">\n";
    echo "<button class=\"estab-button estab-button-primary\" type=\"submit\">";
    echo "Suchen</button>\n";
    echo "<a class=\"estab-button\" href=\"".$action."\">Filter zurücksetzen</a>\n";
    echo "<span><strong>".(int) $resultCount."</strong> Treffer</span>\n";
    echo "</div>\n</form>\n</section>\n";
  }

  function etb_referenzauswertung (
    array $options,
    ?array $evaluation,
    ?string $error
  ) {
    $start = (string) ($options ["start"] ?? "");
    $direction = (string) ($options ["direction"] ?? "forward");
    $depth = (string) ($options ["depth"] ?? "5");
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));
    echo "<section id=\"etb-reference-evaluation\" class=\"estab-tool-panel\" ";
    echo "aria-labelledby=\"etb-reference-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<p class=\"estab-tool-eyebrow\">Referenznachweis</p>\n";
    echo "<h2 id=\"etb-reference-title\">ETB-Referenzen auswerten</h2>\n";
    echo "<p>Verfolgen Sie von einer lokalen ETB-Nummer aus alle ";
    echo "referenzierenden Folgeeinträge oder den Bezugspfad zurück. ";
    echo "Vorwärtssuchen zeigen auch verzweigte Zusammenhänge.</p>\n</header>\n";
    echo "<form class=\"estab-tool-form estab-tool-actions\" method=\"get\" ";
    echo "action=\"".$action."\">\n";
    echo "<div class=\"estab-tool-field\"><label for=\"etb-ref-start\">";
    echo "Startnummer</label><input id=\"etb-ref-start\" type=\"number\" ";
    echo "name=\"referenz_start\" min=\"1\" max=\"4294967295\" ";
    echo "step=\"1\" required value=\"".estab_auth_html ($start)."\"></div>\n";
    echo "<div class=\"estab-tool-field\"><label for=\"etb-ref-direction\">";
    echo "Richtung</label><select id=\"etb-ref-direction\" ";
    echo "name=\"referenz_richtung\">\n";
    foreach (array ("forward" => "Vorwärts · referenzierende Einträge",
                    "backward" => "Rückwärts · referenzierte Einträge")
             as $value => $label) {
      echo "<option value=\"".$value."\"".
           ($direction === $value ? " selected" : "").">".
           estab_auth_html ($label)."</option>\n";
    }
    echo "</select></div>\n";
    echo "<div class=\"estab-tool-field\"><label for=\"etb-ref-depth\">";
    echo "Maximale Tiefe</label><input id=\"etb-ref-depth\" type=\"number\" ";
    echo "name=\"referenz_tiefe\" min=\"1\" max=\"".
         ESTAB_LOGBOOK_REFERENCE_DEPTH_MAX."\" step=\"1\" value=\"".
         estab_auth_html ($depth)."\"></div>\n";
    echo "<button class=\"estab-button estab-button-primary\" type=\"submit\">";
    echo "Zusammenhang anzeigen</button>\n</form>\n";

    if ($error !== null) {
      echo "<aside class=\"estab-tool-notice estab-tool-notice-warning\" ";
      echo "role=\"alert\"><strong>Referenzauswertung nicht möglich.</strong> ";
      echo estab_auth_html ($error)."</aside>\n";
    } elseif ($evaluation !== null) {
      $rows = is_array ($evaluation ["rows"] ?? null)
        ? $evaluation ["rows"] : array ();
      $directionLabel = ($evaluation ["direction"] ?? "") === "forward"
        ? "vorwärts" : "rückwärts";
      echo "<div class=\"estab-tool-actions\">\n";
      echo "<span><strong>".count ($rows)."</strong> Einträge · ".
           estab_auth_html ($directionLabel)." · Tiefe ".
           (int) ($evaluation ["max_depth"] ?? 0)."</span>\n";
      $printQuery = http_build_query (array (
        "referenz_start" => (int) ($evaluation ["start_number"] ?? 0),
        "referenz_richtung" => (string) ($evaluation ["direction"] ?? ""),
        "referenz_tiefe" => (int) ($evaluation ["max_depth"] ?? 0),
        "referenz_druck" => "1",
      ));
      echo "<a class=\"estab-button\" target=\"_blank\" rel=\"noopener\" href=\"".
           $action."?".estab_auth_html ($printQuery)."\">";
      echo "Druckansicht öffnen</a>\n</div>\n";
      if (!empty ($evaluation ["truncated"])) {
        echo "<p class=\"estab-tool-notice estab-tool-notice-warning\">";
        echo "Weitere Verknüpfungen liegen außerhalb der gewählten Tiefe.";
        echo "</p>\n";
      }
      echo "<div class=\"estab-tool-table-wrap estab-tool-table-responsive\">";
      echo "<table class=\"estab-tool-table\"><thead><tr>";
      echo "<th>Tiefe</th><th>ETB-Nr.</th><th>Verknüpft über</th>";
      echo "<th>Ereigniszeit</th><th>Darstellung der Ereignisse</th>";
      echo "<th>Bemerkung</th></tr></thead><tbody>\n";
      foreach ($rows as $row) {
        $via = (int) ($row ["estab_reference_via"] ?? 0);
        $rowReference = estab_logbook_stored_etb_reference_number (
          $row ["estab_reference"] ?? null
        );
        echo "<tr><td>".(int) ($row ["estab_reference_depth"] ?? 0)."</td>";
        echo "<td>".(int) ($row ["estab_book_lfd"] ?? 0)."</td><td>";
        if ($via > 0) {
          echo "ETB-Nr. ".$via;
        } elseif ($rowReference !== null) {
          echo "Referenz auf ETB-Nr. ".$rowReference;
        } else {
          echo "Start";
        }
        echo "</td><td>".estab_auth_html ($this->konv_datetime_taktime (
          (string) ($row ["estab_event_time"] ?? $row ["etb_time"] ?? "")
        ))."</td><td>";
        echo nl2br (estab_auth_html (estab_function_display_text (
          (string) ($row ["etb_aktion"] ?? "")
        )), false);
        echo "</td><td>";
        echo nl2br (estab_auth_html (estab_function_display_text (
          (string) ($row ["etb_bemerk"] ?? "")
        )), false);
        echo "</td></tr>\n";
      }
      echo "</tbody></table></div>\n";
    }
    echo "</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
var $lfd ;
var $task;

  function etb_eintragsmenue ($data) {
    $correctionId = is_int ($data) && $data > 0 ? $data : null;
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $attachmentOptions = estab_logbook_available_etb_attachments (
      $conf_4f_db,
      $conf_4f_tbl ["anhang"],
      $conf_tbl ["etb"]
    );
    $assignmentOptions = estab_logbook_active_assignment_options (
      $conf_4f_db
    );

    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-entry-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"etb-entry-title\">";
    echo $correctionId === null
      ? "ETB-Eintrag erfassen"
      : "Ausgewählten ETB-Eintrag berichtigen";
    echo "</h2>\n";
    echo "<p>Fachliche Ereigniszeit und Erfassungszeit werden getrennt und ";
    echo "unveränderlich gespeichert. Fehler werden ausschließlich mit einem ";
    echo "neuen Korrektureintrag berichtigt.</p>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-form\" method=\"post\" action=\"".$action.
         "\" name=\"etbeintrag\" data-estab-dirty-guard ".
         "data-estab-requires-incident>\n";
    echo estab_csrf_field ()."\n";
    echo "<input type=\"hidden\" name=\"logbook_action\" value=\"save_entry\">\n";
    if ($correctionId !== null) {
      echo "<input type=\"hidden\" name=\"event_type\" value=\"korrektur\">\n";
      echo "<input type=\"hidden\" name=\"correction_of\" value=\"".
           (int) $correctionId."\">\n";
    }
    echo "<div class=\"estab-tool-form-grid\">\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-event-time\">Fachliche Ereigniszeit</label>\n";
    echo "<input id=\"etb-event-time\" type=\"datetime-local\" ";
    echo "name=\"event_time\" required value=\"".
         estab_auth_html (date ("Y-m-d\\TH:i"))."\">\n";
    echo "<small>Wann ist das dokumentierte Ereignis tatsächlich eingetreten?</small>\n";
    echo "</div>\n";
    if ($correctionId === null) {
      echo "<div class=\"estab-tool-field\">\n";
      echo "<label for=\"etb-event-type\">Art des Eintrags</label>\n";
      echo "<select id=\"etb-event-type\" name=\"event_type\" required>\n";
      foreach (estab_logbook_entry_types () as $value => $label) {
        if ($value === "korrektur") { continue; }
        echo "<option value=\"".estab_auth_html ($value)."\">".
             estab_auth_html ($label)."</option>\n";
      }
      echo "</select>\n</div>\n";
      echo "<p class=\"estab-tool-field estab-tool-field-wide\"><small>";
      echo "A = Aufgabe, B = Befehl/Auftrag, E = Erledigung, ";
      echo "K = Kräfteanforderung, W = sehr wichtig. Die Kennzeichnung ";
      echo "unterstützt Suche und Auswertung und wird im amtlichen ";
      echo "Fb-Fü-2-Ausdruck nicht als eigene Spalte ausgegeben.</small></p>\n";
    }
    echo "<div class=\"estab-tool-field estab-tool-field-wide\">\n";
    echo "<label for=\"etb-assignee-assignment\">Zuordnung (optional)</label>\n";
    echo "<select id=\"etb-assignee-assignment\" ";
    echo "name=\"assignee_assignment_id\">\n";
    echo "<option value=\"\">Keine Bearbeitungszuordnung</option>\n";
    foreach ($assignmentOptions as $assignmentOption) {
      $assignmentId = (int) (
        $assignmentOption ["dienstbesetzung_id"] ?? 0
      );
      if ($assignmentId < 1) { continue; }
      echo "<option value=\"".$assignmentId."\">".
           estab_auth_html (estab_function_display_text ((string) (
             $assignmentOption ["estab_assignment"] ?? ""
           )))."</option>\n";
    }
    echo "</select>\n";
    echo "<small>Ordnet den Eintrag optional einer historischen ";
    echo "Dienstbesetzung dieses Einsatzes als Bearbeitungs- und Suchhilfe zu. ";
    echo "wird nicht in das amtliche PDF-Formblatt übernommen.</small>\n";
    echo "</div>\n";
    echo "</div>\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-event\">Darstellung der Ereignisse</label>\n";
    echo "<textarea id=\"etb-event\" maxlength=\"10000\" required ";
    echo "name=\"event\" autofocus></textarea>\n";
    echo "<small>Der Eintrag muss mit Wer, Was, Wo und für wen auch ohne ";
    echo "Öffnen einer Anlage verständlich bleiben. Höchstens 10.000 ";
    echo "Zeichen.</small>\n</div>\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-comment\">Bemerkung</label>\n";
    echo "<textarea id=\"etb-comment\" maxlength=\"10000\" ";
    echo "name=\"comment\"></textarea>\n";
    echo "<small>Optional, höchstens 10.000 Zeichen.</small>\n</div>\n";
    echo "<details class=\"estab-tool-field\">\n";
    echo "<summary>Bezüge und Nachweise (optional)</summary>\n";
    echo "<div class=\"estab-tool-form-grid\">\n";
    echo "<div class=\"estab-tool-field\"><label for=\"etb-message-id\">";
    echo "Nachrichten-ID</label><input id=\"etb-message-id\" type=\"number\" ";
    echo "min=\"1\" step=\"1\" name=\"message_id\"></div>\n";
    echo "<div class=\"estab-tool-field estab-tool-field-wide\">";
    echo "<label for=\"etb-attachment-id\">ETB-Anlage</label>";
    echo "<select id=\"etb-attachment-id\" name=\"attachment_id\">\n";
    echo "<option value=\"\">Keine Anlage zuordnen</option>\n";
    foreach ($attachmentOptions as $attachment) {
      $attachmentId = (int) ($attachment ["lfd-nr"] ?? 0);
      if ($attachmentId < 1) { continue; }
      $filename = (string) ($attachment ["filename"] ?? "");
      $extension = trim ((string) ($attachment ["fileext"] ?? ""));
      if ($extension !== "") { $filename .= ".".$extension; }
      $optionParts = array ($filename);
      $original = trim ((string) ($attachment ["org_filename"] ?? ""));
      if ($original !== "") { $optionParts [] = $original; }
      $description = trim ((string) ($attachment ["comment"] ?? ""));
      if ($description !== "") { $optionParts [] = $description; }
      echo "<option value=\"".$attachmentId."\">".
           estab_auth_html (implode (" · ", $optionParts))."</option>\n";
    }
    echo "</select>\n";
    if ($attachmentOptions === array ()) {
      echo "<small>Derzeit ist kein abgeschlossener, noch unbenutzter ";
      echo "Einsatzanhang vorhanden. Laden Sie ihn zuerst über ";
      echo "<strong>Anhänge</strong> in der Seitenleiste hoch.</small>\n";
    } else {
      echo "<small>Beim Speichern wird automatisch eine eindeutige Nummer ";
      echo "nach dem Muster <strong>ETB ".(int) $this->etb_einsatz_id.
           "-[Eintrag]-1</strong> vergeben. Die Datei gilt als eine ";
      echo "zusammengehörige digitale Einheit.</small>\n";
    }
    echo "</div>\n";
    if ($correctionId === null) {
      echo "<div class=\"estab-tool-field estab-tool-field-wide\">";
      echo "<label for=\"etb-reference\">Referenz auf ETB-Nr.</label>";
      echo "<input id=\"etb-reference\" type=\"number\" min=\"1\" ";
      echo "max=\"4294967295\" step=\"1\" name=\"reference\">";
      echo "<small>Verknüpft diesen Eintrag mit einem bereits vorhandenen ";
      echo "Eintrag desselben Einsatzes. Verwenden Sie dessen lokale ";
      echo "ETB-Nummer.</small>\n</div>\n";
    } else {
      echo "<p class=\"estab-tool-field estab-tool-field-wide\"><small>";
      echo "Der Korrekturbezug wird beim Speichern unveränderlich aus der ";
      echo "lokalen ETB-Nummer des Originals gebildet.</small></p>\n";
    }
    echo "</div>\n</details>\n";
    echo "<div class=\"estab-tool-actions\">\n";
    echo "<button class=\"estab-button estab-button-primary\" type=\"submit\">";
    echo "ETB-Eintrag speichern</button>\n";
    echo "<a class=\"estab-button\" href=\"".$action."\">Abbrechen</a>\n";
    echo "</div>\n</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_einsatzdaten (){
    echo "<section class=\"estab-tool-status estab-tool-status-active\" ";
    echo "aria-label=\"Aktiver Einsatz\">\n<div>\n";
    echo "<span>Aktiver Einsatz</span>\n";
    echo "<strong>".estab_auth_html ($this->etb_art)."</strong>\n";
    echo "<span>Führungsstelle: ".estab_auth_html (
      $this->etb_fuehrungsstelle !== ""
        ? $this->etb_fuehrungsstelle
        : "nicht festgelegt"
    )."</span>\n";
    echo "<span>Ort: ".estab_auth_html (
      $this->etb_ort !== "" ? $this->etb_ort : "nicht angegeben"
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
      echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-list-title\">\n";
      echo "<header class=\"estab-tool-panel-heading\">\n";
      echo "<h2 id=\"etb-list-title\">Einträge des aktiven Einsatzes</h2>\n";
      echo "<p>Die neuesten Einträge stehen oben. Die laufende Nummer wird ";
      echo "beim Speichern je Einsatz vergeben und bleibt auch bei einer ";
      echo "rückdatierten Sachzeit unverändert.</p>\n</header>\n";
      echo "<div class=\"estab-tool-table-wrap estab-tool-table-responsive\">\n";
      echo "<table class=\"estab-tool-table estab-tool-logbook-table\">\n";
      echo "<caption class=\"estab-visually-hidden\">";
      echo "Einträge im Einsatztagebuch</caption>\n<thead>\n";

      $this->headline ();
      echo "</thead>\n<tbody>\n";

      foreach ( $daten as $line ){
        echo "<tr>";
        echo "<td class=\"estab-tool-table-number\" data-label=\"Lfd.-Nr.\">\n";
        echo (int) ($line ["estab_book_lfd"] ?? $line ["etb_lfd-nr"]);
        echo "</td>\n";
        $eventTime = isset ($line ["estab_event_time"])
          ? (string) $line ["estab_event_time"]
          : (string) $line ["etb_time"];
        $recordedAt = isset ($line ["estab_recorded_at"])
          ? (string) $line ["estab_recorded_at"]
          : (string) $line ["etb_time"];
        $eventType = isset ($line ["estab_event_type"])
          ? (string) $line ["estab_event_type"]
          : "legacy_import";
        $typeLabels = estab_logbook_entry_types ();
        $typeLabel = $typeLabels [$eventType] ?? (
          $eventType === "legacy_import" ? "Bestandseintrag" : $eventType
        );
        echo "<td data-label=\"Ereigniszeit\"><time>";
        echo estab_auth_html ($this->konv_datetime_taktime ($eventTime));
        echo "</time>";
        echo "</td>\n";
        echo "<td data-label=\"Art\">".estab_auth_html ($typeLabel);
        if (isset ($line ["estab_correction_of"]) &&
            $line ["estab_correction_of"] !== null) {
          $targetNumber = (int) (
            $line ["estab_correction_book_lfd"] ?? 0
          );
          echo "<br><small>".($targetNumber > 0
            ? "zu ETB-Nr. ".$targetNumber
            : "zum ursprünglichen ETB-Eintrag")."</small>";
        }
        echo "</td>\n";
        echo "<td data-label=\"Darstellung der Ereignisse\">";
        echo $line ["etb_aktion"] != ""
          ? nl2br (estab_auth_html (estab_function_display_text (
              (string) $line ["etb_aktion"]
            )), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        echo "</td>\n";
        echo "<td data-label=\"Bemerkung / Nachweise\">";
        echo $line ["etb_bemerk"] != ""
          ? nl2br (estab_auth_html (estab_function_display_text (
              (string) $line ["etb_bemerk"]
            )), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        $references = array ();
        if (!empty ($line ["estab_message_id"])) {
          $references [] = "Nachricht #".(int) $line ["estab_message_id"];
        }
        if (!empty ($line ["estab_attachment_id"])) {
          $incidentId = (int) ($line ["einsatz_id"] ?? $this->etb_einsatz_id);
          $entryNumber = (int) ($line ["estab_book_lfd"] ?? 0);
          if ($incidentId > 0 && $entryNumber > 0) {
            $attachmentLabel = estab_logbook_etb_attachment_number (
              $incidentId,
              $entryNumber
            );
          } else {
            $attachmentLabel = "ETB-Anlage";
          }
          $archiveName = trim ((string) (
            $line ["estab_attachment_filename"] ?? ""
          ));
          $archiveExtension = trim ((string) (
            $line ["estab_attachment_extension"] ?? ""
          ));
          if ($archiveName !== "" && $archiveExtension !== "") {
            $archiveName .= ".".$archiveExtension;
          }
          $references [] = "Anlage ".$attachmentLabel.
            ($archiveName !== "" ? " · Ablage ".$archiveName : "");
        }
        if (!empty ($line ["estab_reference"])) {
          $referenceNumber = estab_logbook_stored_etb_reference_number (
            $line ["estab_reference"]
          );
          $references [] = $referenceNumber !== null
            ? "Referenz auf ETB-Nr. ".$referenceNumber
            : "Historischer Bestandsbezug: ".(string) $line ["estab_reference"];
        }
        if (!empty ($line ["estab_assignment"])) {
          $references [] = "Zuordnung: ".estab_function_display_text (
            (string) $line ["estab_assignment"]
          );
        }
        if ($references !== array ()) {
          echo "<br><small>".estab_auth_html (implode (" · ", $references)).
               "</small>";
        }
        echo "</td>\n";
        echo "<td data-label=\"Erfasst\">";
        echo "<time>".estab_auth_html (
          $this->konv_datetime_taktime ($recordedAt)
        )."</time><br>";
        echo "<small>".estab_auth_html ((string) ($line ["etb_benutzer"] ?? "")).
             " · ".estab_auth_html (estab_function_display_name (
               (string) ($line ["etb_funktion"] ?? "")
             )).
             " · ".estab_auth_html ((string) ($line ["etb_kuerzel"] ?? "")).
             "</small></td>\n";
        echo "<td data-label=\"Aktion\">";
        if (
          $this->etb_authorized
          && $eventType !== "korrektur"
          && empty ($line ["estab_correction_of"])
        ) {
          echo "<a class=\"estab-button\" href=\"".
               estab_auth_html (estab_application_url ("stabetb/etb.php")).
               "?correct=".(int) $line ["etb_lfd-nr"]."\">";
          echo "Berichtigen</a>";
        } else {
          echo "<span aria-label=\"keine Aktion\">—</span>";
        }
        echo "</td>\n";
        echo "</tr>\n";
      }

      echo "</tbody>\n";
      echo "</table>\n";
      echo "</div>\n</section>\n";
    } else {
      echo "<section class=\"estab-tool-panel\" aria-label=\"Keine ETB-Einträge\">\n";
      echo "<p class=\"estab-tool-empty\">Noch keine ETB-Einträge vorhanden.</p>\n";
      echo "</section>\n";
    }
  }

} // class etb_liste

/***************************************************************************************************************************/


if (session_status () !== PHP_SESSION_ACTIVE) {
  session_start ();
}
require_once __DIR__ . "/../app/logbook.php";
require_once __DIR__ . "/../app/navigation.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/session_ui.php";
estab_navigation_require_session ($_SESSION, "incident-log", $_SERVER);

include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");

$identity = estab_read_session_identity ($_SESSION);
estab_navigation_require_selected_duty (
  $_SESSION,
  $identity,
  "incident-log",
  $_SERVER
);
estab_session_ui_start ($_SESSION);

$berechtigt = false;
$hasEtbCapability = false;
$istEtbFuehrung = false;
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
  $hasEtbCapability = estab_dv_has_write_capability (
    $readConnection,
    (int) $readScope ["incident"]["active_einsatz_id"],
    $identity,
    "EINSATZTAGEBUCH"
  );
  $istEtbFuehrung = estab_logbook_is_designated_writer (
    $readConnection,
    (int) $readScope ["incident"]["active_einsatz_id"],
    $identity,
    "etb"
  );
  $berechtigt = $hasEtbCapability && $istEtbFuehrung;
} catch (EstabNoActiveIncidentException $exception) {
  $readError = array (
    409,
    "Kein Einsatz ist aktiv. Das Einsatztagebuch enthält derzeit keine ".
    "freigegebenen Einsatzdaten."
  );
} catch (EstabReadPermissionException $exception) {
  $readError = array (403, $exception->getMessage ());
} catch (Throwable $exception) {
  error_log ("ETB read authorization failed: ".$exception->getMessage ());
  $readError = array (
    503,
    "Die Leseberechtigung für das Einsatztagebuch kann derzeit nicht geprüft ".
    "werden."
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
  $etbobj = new etb_liste;
} catch (Throwable $exception) {
  error_log ("ETB initialization failed: ".$exception->getMessage ());
  estab_logbook_abort (503, "Das Einsatztagebuch ist vorübergehend nicht verfügbar.");
}
$etbobj->etb_authorized =
  $berechtigt && $etbobj->etb_titel_gesetzt;
$etbobj->etb_funktion = $identity ["funktion"];
$etbobj->etb_kuerzel = $identity ["kuerzel"];
$etbobj->etb_benutzer = $identity ["benutzer"];
$etbobj->etb_rolle = $identity ["rolle"];
$etbobj->etb_identity = $identity;

if ($requestMethod === "POST") {
  if (!$berechtigt) {
    estab_logbook_abort (
      403,
      "Dieses Konto darf im aktuellen Einsatz keine ETB-Einträge schreiben. " .
      "Prüfen Sie Kontostatus und Berechtigungsmodus."
    );
  }
  estab_logbook_require_csrf ($_SERVER, $_POST);
  $action = isset ($_POST ["logbook_action"]) && is_string ($_POST ["logbook_action"])
    ? $_POST ["logbook_action"]
    : "";

  try {
    if ($action === "save_entry") {
      if (!$etbobj->etb_titel_gesetzt) {
        if ($etbobj->etb_einsatz_aktiv) {
          estab_logbook_abort (
            409,
            "Der aktive Einsatz ist unvollständig. ETB-Eingaben sind ".
            "gesperrt; ergänzen Sie zuerst den Namen der Führungsstelle in ".
            "der Administration."
          );
        }
        estab_logbook_abort (
          409,
          "Kein Einsatz ist aktiv. ETB-Eingaben sind gesperrt; aktivieren Sie ".
          "zuerst einen Einsatz in der Administration."
        );
      }
      $validation = estab_logbook_validate_entry ($_POST);
      if (!$validation ["valid"]) {
        estab_logbook_abort (422, "Der ETB-Eintrag ist leer oder überschreitet 10000 Zeichen.");
      }
      $etbobj->speichen_etb_eintrag ($validation ["data"]);
    } else {
      estab_logbook_abort (400, "Unbekannte ETB-Aktion.");
    }
  } catch (EstabDvPermissionException $exception) {
    estab_logbook_abort (403, $exception->getMessage ());
  } catch (EstabIncidentConfigurationException $exception) {
    error_log ("ETB write blocked by incident configuration: ".
      $exception->getMessage ());
    estab_logbook_abort (
      409,
      "Der aktive Einsatz ist unvollständig. ETB-Eingaben sind gesperrt; ".
      "ergänzen Sie zuerst den Namen der Führungsstelle in der Administration."
    );
  } catch (EstabIncidentConflictException|EstabDvConflictException $exception) {
    estab_logbook_abort (409, $exception->getMessage ());
  } catch (EstabNoActiveIncidentException $exception) {
    error_log ("ETB write blocked: ".$exception->getMessage ());
    estab_logbook_abort (
      409,
      "Kein Einsatz ist aktiv. ETB-Eingaben sind gesperrt; aktivieren Sie ".
      "zuerst einen Einsatz in der Administration."
    );
  } catch (Throwable $exception) {
    error_log ("ETB write failed: ".$exception->getMessage ());
    estab_logbook_abort (500, "Der ETB-Eintrag konnte nicht gespeichert werden.");
  }

  estab_logbook_redirect (estab_application_url ("stabetb/etb.php"));
}

$etbobj->etb_pre_html ();
$etbobj->etb_ueberschrift ();

if (!$etbobj->etb_titel_gesetzt) {
  echo "<section class=\"estab-tool-status estab-tool-status-danger\" ";
  echo "role=\"alert\" ";
  if ($etbobj->etb_einsatz_aktiv) {
    echo "data-estab-incident-incomplete><div>";
    echo "<strong>Aktiver Einsatz unvollständig – ETB-Eingaben sind ";
    echo "gesperrt.</strong>";
    echo "<span>Ergänzen Sie in der Administration zuerst den Namen der ";
    echo "Führungsstelle.</span></div></section>\n";
  } else {
    echo "data-estab-no-active-incident><div>";
    echo "<strong>Kein Einsatz aktiv – ETB-Eingaben sind gesperrt.</strong>";
    echo "<span>";
    echo "Legen Sie in der Administration einen Einsatz an oder aktivieren Sie ";
    echo "einen vorhandenen Einsatz.</span></div></section>\n";
  }
} else {
  $etbobj->etb_einsatzdaten ();
  $referenceOptions = array (
    "start" => isset ($_GET ["referenz_start"])
      && is_string ($_GET ["referenz_start"])
      ? $_GET ["referenz_start"] : "",
    "direction" => isset ($_GET ["referenz_richtung"])
      && is_string ($_GET ["referenz_richtung"])
      ? $_GET ["referenz_richtung"] : "forward",
    "depth" => isset ($_GET ["referenz_tiefe"])
      && is_string ($_GET ["referenz_tiefe"])
      ? $_GET ["referenz_tiefe"] : "5",
  );
  $referenceEvaluation = null;
  $referenceError = null;
  if ($referenceOptions ["start"] !== "") {
    try {
      $referenceEvaluation = estab_logbook_etb_reference_evaluation (
        $conf_4f_db,
        $conf_tbl ["etb"],
        $referenceOptions ["start"],
        $referenceOptions ["direction"],
        $referenceOptions ["depth"]
      );
    } catch (EstabIncidentInputException|EstabIncidentConflictException $exception) {
      $referenceError = $exception->getMessage ();
    }
  }
  $referencePrint = $referenceOptions ["start"] !== ""
    && isset ($_GET ["referenz_druck"])
    && is_string ($_GET ["referenz_druck"])
    && $_GET ["referenz_druck"] === "1";
  $entryFormRequested = isset ($_GET ["etb_eintrag_x"])
    || (
      isset ($_GET ["etb_menue"])
      && is_string ($_GET ["etb_menue"])
      && $_GET ["etb_menue"] === "eintrag"
    );
  if (!$referencePrint) {
    if ($etbobj->etb_authorized && $entryFormRequested) {
      $etbobj->etb_eintragsmenue ("");
    } elseif (
      $etbobj->etb_authorized
      && isset ($_GET ["correct"])
      && is_string ($_GET ["correct"])
      && preg_match ("/\\A[1-9][0-9]*\\z/D", $_GET ["correct"]) === 1
    ) {
      $etbobj->etb_eintragsmenue ((int) $_GET ["correct"]);
    } elseif ($etbobj->etb_authorized) {
      $etbobj->etb_menue ();
    } else {
      echo "<aside class=\"estab-tool-notice estab-tool-notice-warning\">\n";
      echo "<strong>ETB schreibgeschützt.</strong>\n<p>";
      echo "Ihre aktuell wirksamen Funktionen erlauben das Lesen, besitzen ".
        "aber nicht die Fachzuständigkeit für ETB-Einträge.";
      echo "</p>\n</aside>\n";
    }
  }
  $etbobj->etb_referenzauswertung (
    $referenceOptions,
    $referenceEvaluation,
    $referenceError
  );
  $etbFilters = array (
    "query" => isset ($_GET ["q"]) && is_string ($_GET ["q"])
      ? $_GET ["q"] : "",
    "type" => isset ($_GET ["art"]) && is_string ($_GET ["art"])
      ? $_GET ["art"] : "",
    "reference" => isset ($_GET ["bezug"]) && is_string ($_GET ["bezug"])
      ? $_GET ["bezug"] : "",
    "assignment" => isset ($_GET ["zuordnung"])
      && is_string ($_GET ["zuordnung"])
      ? $_GET ["zuordnung"] : "",
  );
  try {
    $etbRows = $etbobj->etb_getdate ($etbFilters);
  } catch (EstabIncidentInputException $exception) {
    estab_logbook_abort (422, $exception->getMessage ());
  }
  if (!$referencePrint) {
    $etbobj->etb_suchmenue ($etbFilters, is_array ($etbRows)
      ? count ($etbRows) : 0);
    $etbobj->printlist ($etbRows);
  }
}

$etbobj->etb_post_html ();
?>
