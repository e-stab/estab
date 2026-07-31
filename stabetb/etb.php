<?php

if (!defined ("debug")) { define ("debug", false); }
if (ob_get_level () === 0) { ob_start (); }
/******************************************************************************\
Einsatz Tage Buch

  Szenario "Kein globaler Einsatz aktiv."

    + Roter Sperrhinweis mit Verweis auf die Einsatzverwaltung
    + Keine fachlichen Eingaben

  Szenario "Globaler Einsatz aktiv."

    + Anzeige des globalen Einsatzkopfs
    + Lesender Zugriff nur mit ausgewaehlter, aktiver Dienstfunktion
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
  var $etb_art ;
  var $etb_ort ;
  var $etb_fuehrungsstelle ;

  var $etb_funktion ;
  var $etb_kuerzel ;
  var $etb_benutzer ;
  var $etb_rolle ;
  var $etb_authorized ;
  public int $etb_duty_assignment_id = 0;

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
    mysql_query('SET NAMES utf8mb4');
    $db_check = mysql_select_db ($this->db_name)
       or die ("[query_table] Auswahl der Datenbank fehlgeschlagen");

    $query_result = mysql_query ($this->sqlquery, $db) or
       die("[query_table] 103-".mysql_error()." ".mysql_errno());

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
    echo "<p>Erfassen Sie ein Ereignis oder eine Entscheidung im aktiven Einsatz.</p>\n";
    echo "</header>\n";
    echo "<form class=\"estab-tool-actions\" action=\"".$action."\" method=\"get\">\n";
    echo "<button class=\"estab-button estab-button-primary\" ";
    echo "type=\"submit\" name=\"etb_menue\" value=\"eintrag\">";
    echo "Neuen ETB-Eintrag anlegen</button>\n";
    echo "</form>\n</section>\n";
  }

/*****************************************************************************\

\*****************************************************************************/
  function etb_getdate ( ){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $result = estab_logbook_entries (
      $conf_4f_db,
      $conf_tbl ["etb"],
      "etb"
    );
  if (debug == true){echo "etb_getdate-->"; var_dump($result);echo "<br>";}
    return $result === array () ? "" : $result;
  }

/*****************************************************************************\

\*****************************************************************************/
  function speichen_etb_eintrag ($daten){
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $validation = estab_logbook_validate_entry (is_array ($daten) ? $daten : array ());
    if (!$validation ["valid"]) {
      throw new InvalidArgumentException ("Ungültiger ETB-Eintrag");
    }
    estab_logbook_insert_entry (
      $conf_4f_db,
      $conf_tbl ["etb"],
      "etb",
      $validation ["data"],
      array (
        "funktion" => (string) $this->etb_funktion,
        "kuerzel" => (string) $this->etb_kuerzel,
        "benutzer" => (string) $this->etb_benutzer,
        "rolle" => (string) $this->etb_rolle,
        "duty_assignment_id" => $this->etb_duty_assignment_id,
      )
    );
  }

/*****************************************************************************\

\*****************************************************************************/
var $lfd ;
var $task;

  function etb_eintragsmenue ($data) {
    $correctionId = is_int ($data) && $data > 0 ? $data : null;
    $action = estab_auth_html (estab_application_url ("stabetb/etb.php"));

    echo "<section class=\"estab-tool-panel\" aria-labelledby=\"etb-entry-title\">\n";
    echo "<header class=\"estab-tool-panel-heading\">\n";
    echo "<h2 id=\"etb-entry-title\">";
    echo $correctionId === null
      ? "ETB-Eintrag erfassen"
      : "ETB-Eintrag Nr. ".(int) $correctionId." berichtigen";
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
    }
    echo "</div>\n";
    echo "<div class=\"estab-tool-field\">\n";
    echo "<label for=\"etb-event\">Darstellung der Ereignisse</label>\n";
    echo "<textarea id=\"etb-event\" maxlength=\"10000\" required ";
    echo "name=\"event\" autofocus></textarea>\n";
    echo "<small>Höchstens 10.000 Zeichen.</small>\n</div>\n";
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
    echo "<div class=\"estab-tool-field\"><label for=\"etb-attachment-id\">";
    echo "Anhang-ID</label><input id=\"etb-attachment-id\" type=\"number\" ";
    echo "min=\"1\" step=\"1\" name=\"attachment_id\"></div>\n";
    echo "<div class=\"estab-tool-field estab-tool-field-wide\">";
    echo "<label for=\"etb-reference\">Weiterer Akten-/Dokumentenbezug</label>";
    echo "<input id=\"etb-reference\" maxlength=\"255\" name=\"reference\">";
    echo "</div>\n</div>\n</details>\n";
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
      echo "<p>Die neuesten Einträge werden entsprechend der fachlichen ";
      echo "Datenbankreihenfolge angezeigt.</p>\n</header>\n";
      echo "<div class=\"estab-tool-table-wrap estab-tool-table-responsive\">\n";
      echo "<table class=\"estab-tool-table estab-tool-logbook-table\">\n";
      echo "<caption class=\"estab-visually-hidden\">";
      echo "Einträge im Einsatztagebuch</caption>\n<thead>\n";

      $this->headline ();
      echo "</thead>\n<tbody>\n";

      foreach ( $daten as $line ){
        echo "<tr>";
        echo "<td class=\"estab-tool-table-number\" data-label=\"Lfd.-Nr.\">\n";
        echo (int) $line ["etb_lfd-nr"];
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
          echo "<br><small>zu Nr. ".(int) $line ["estab_correction_of"]."</small>";
        }
        echo "</td>\n";
        echo "<td data-label=\"Darstellung der Ereignisse\">";
        echo $line ["etb_aktion"] != ""
          ? nl2br (estab_auth_html ($line ["etb_aktion"]), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        echo "</td>\n";
        echo "<td data-label=\"Bemerkung / Nachweise\">";
        echo $line ["etb_bemerk"] != ""
          ? nl2br (estab_auth_html ($line ["etb_bemerk"]), false)
          : "<span aria-label=\"keine Angabe\">—</span>";
        $references = array ();
        if (!empty ($line ["estab_message_id"])) {
          $references [] = "Nachricht #".(int) $line ["estab_message_id"];
        }
        if (!empty ($line ["estab_attachment_id"])) {
          $references [] = "Anhang #".(int) $line ["estab_attachment_id"];
        }
        if (!empty ($line ["estab_reference"])) {
          $references [] = (string) $line ["estab_reference"];
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
             " · ".estab_auth_html ((string) ($line ["etb_funktion"] ?? "")).
             " · ".estab_auth_html ((string) ($line ["etb_kuerzel"] ?? "")).
             "</small></td>\n";
        echo "<td data-label=\"Aktion\">";
        if ($this->etb_authorized) {
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
if (
  !is_array ($identity)
  || estab_read_duty_assignment_id (
    $identity ["duty_assignment_id"] ?? null
  ) === null
) {
  estab_navigation_select_duty ($_SERVER);
}
estab_session_ui_start ($_SESSION);

$berechtigt = false;
$dutyAssignmentId = null;
try {
  $readConnection = estab_auth_connect ($conf_4f_db);
  $readScope = estab_read_require_operational_scope (
    $readConnection,
    $identity
  );
  $identity = $readScope ["identity"];
  $dutyAssignmentId = estab_read_duty_assignment_id (
    $identity ["duty_assignment_id"] ?? null
  );
  if ($dutyAssignmentId === null) {
    throw new EstabReadPermissionException (
      "Wählen Sie zuerst eine persönlich angenommene Dienstfunktion."
    );
  }
  $berechtigt = estab_dv_has_selected_capability (
    $readConnection,
    (int) $readScope ["incident"]["active_einsatz_id"],
    $identity,
    "EINSATZTAGEBUCH"
  );
} catch (EstabNoActiveIncidentException $exception) {
  estab_logbook_abort (
    409,
    "Kein Einsatz ist aktiv. Das Einsatztagebuch enthält derzeit keine ".
    "freigegebenen Einsatzdaten."
  );
} catch (EstabReadPermissionException $exception) {
  estab_logbook_abort (403, $exception->getMessage ());
} catch (Throwable $exception) {
  error_log ("ETB read authorization failed: ".$exception->getMessage ());
  estab_logbook_abort (
    503,
    "Die Leseberechtigung für das Einsatztagebuch kann derzeit nicht geprüft ".
    "werden."
  );
} finally {
  if (isset ($readConnection) && $readConnection instanceof mysqli) {
    estab_auth_close ($readConnection);
  }
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
$etbobj->etb_duty_assignment_id = $dutyAssignmentId;

if ($requestMethod === "POST") {
  if (!$berechtigt) {
    estab_logbook_abort (
      403,
      "Nur eine aktive S2- oder ETB-Funktion darf ETB-Einträge schreiben."
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
  } catch (EstabIncidentConfigurationException $exception) {
    error_log ("ETB write blocked by incident configuration: ".
      $exception->getMessage ());
    estab_logbook_abort (
      409,
      "Der aktive Einsatz ist unvollständig. ETB-Eingaben sind gesperrt; ".
      "ergänzen Sie zuerst den Namen der Führungsstelle in der Administration."
    );
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
  $entryFormRequested = isset ($_GET ["etb_eintrag_x"])
    || (
      isset ($_GET ["etb_menue"])
      && is_string ($_GET ["etb_menue"])
      && $_GET ["etb_menue"] === "eintrag"
    );
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
  }
  $etbobj->printlist ($etbobj->etb_getdate ());
}

$etbobj->etb_post_html ();
?>
