<?php
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Liste</big></big><br>";  }
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/message_repository.php";
require_once __DIR__ . "/../app/message_priority.php";
require_once __DIR__ . "/../app/message_transport.php";
require_once __DIR__ . "/../app/tabelle.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/message_list.php";
require_once __DIR__ . "/../app/message_list_ui.php";
require_once __DIR__ . "/../app/workflow.php";
include ("katego.php");
include ("../4fcfg/e_cfg.inc.php");

/** Carry the already server-selected Stab/FB workspace into one list form. */
function estab_list_acting_function_field () {
  $function = estab_workflow_staff_acting_function (
    $GLOBALS ["workflowSelectedIdentity"] ?? null
  );
  return $function === null
    ? ""
    : "<input type=\"hidden\" name=\"acting_function\" value=\"".
      estab_auth_html ($function)."\">";
}

/**
 * Einen Zustand umschalten -- als Knopf mit Zeichen und Namen.
 *
 * Der Knopf trug ein GIF: mail_prio_unread, task_due, 000. Ein Zeichen in
 * einem Bild laesst sich nicht umfaerben, nicht vergroessern und nicht
 * vorlesen; in Graustufen sind die Zustaende nicht zu unterscheiden, und
 * 000.gif war ein durchsichtiges Bild -- also gar nichts, wo ein Zustand
 * stehen sollte.
 *
 * Er traegt jetzt je Zustand ein eigenes Zeichen und den Namen dazu. Der
 * Name steht fuer Vorleseprogramme immer; sichtbar ist er, wo Platz ist.
 */
function estab_list_state_action ($action, $recordId, $todo, $zustand, $alt) {
  $zeichen = array (
    "offen-vorrang" => "\u{25B2}",  // Dreieck -- ungelesen, mit Vorrang
    "offen" => "\u{25CB}",          // Kreis offen -- ungelesen
    "erledigt-offen" => "\u{25A1}", // Quadrat offen -- noch nicht erledigt
    "gesetzt" => "\u{25CF}",        // Kreis voll -- Zustand steht
  );
  $safeAction = estab_auth_html ($action);
  $safeRecordId = estab_auth_html ($recordId);
  $safeTodo = estab_auth_html ($todo);
  $safeZustand = estab_auth_html ($zustand);
  echo "<form class=\"estab-list-cell-form\" method=\"post\""
    ." action=\"mainindex.php\" target=\"_self\">";
  echo estab_csrf_field ();
  echo estab_list_acting_function_field ();
  echo "<input type=\"hidden\" name=\"action\" value=\"".$safeAction."\">";
  echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$safeRecordId."\">";
  echo "<input type=\"hidden\" name=\"todo\" value=\"".$safeTodo."\">";
  echo "<button type=\"submit\" class=\"estab-list-state-toggle"
    ." estab-list-state-toggle--".$safeZustand."\">";
  echo "<span aria-hidden=\"true\">".($zeichen [$zustand] ?? "\u{25CF}")."</span>";
  echo "<span class=\"estab-visually-hidden\">".estab_message_html ($alt)."</span>";
  echo "</button></form>";
}

/** Render a detail navigation as a CSRF-protected POST control. */
function estab_list_detail_action (
  $route,
  $value,
  $recordId,
  $label,
  $large = false,
  $modern = false
) {
  if (!in_array ($route, array ("stab", "sichter", "fm", "ldf"), true)) {
    throw new InvalidArgumentException ("Unbekannte Detailroute");
  }
  $safeRoute = estab_auth_html ($route);
  $safeValue = estab_auth_html ($value);
  $safeRecordId = estab_auth_html (estab_message_positive_id ($recordId));
  $safeLabel = estab_message_html ($label);
  echo "<form class=\"estab-list-cell-form\" method=\"post\""
    ." action=\"mainindex.php\" target=\"_self\">";
  echo estab_csrf_field ();
  echo estab_list_acting_function_field ();
  echo "<input type=\"hidden\" name=\"".$safeRoute."\" value=\"".$safeValue."\">";
  echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$safeRecordId."\">";
  if ($modern) {
    echo "<button type=\"submit\" class=\"estab-button estab-button-primary ".
         "estab-message-list-open\">";
  } else {
    /*
     * Der Knopf trug seine Gestaltung als feste Angabe im Markup und war
     * damit von aussen nicht erreichbar: nicht zu verkleinern, nicht zu
     * entstreichen, nicht zu begrenzen. Er traegt jetzt einen Namen; das
     * Aussehen steht im Stylesheet.
     */
    echo "<button type=\"submit\" class=\"estab-list-cell-button\">";
  }
  if ($large) { echo "<big>"; }
  echo $safeLabel;
  if ($large) { echo "</big>"; }
  echo "</button></form>";
}

/**
 * Einen Transportzustand als Marke ausgeben -- Zeichen, Farbe und Wort.
 *
 * Der Zustand stand als GIF in der Zeile: status_yellow, status_red,
 * status_green. Text in einem Bild laesst sich nicht umfaerben, nicht
 * vergroessern und nicht vorlesen, und in Graustufen sind drei Ampelfarben
 * nicht mehr zu unterscheiden. Die Marke traegt deshalb ein eigenes Zeichen
 * je Zustand und das Wort dazu; die Farbe kommt hinzu, sie traegt nichts
 * allein.
 */
function estab_list_transport_badge ($zustand, $wort) {
  $zeichen = array (
    "wartet" => "\u{25B2}",   // Dreieck -- liegt noch bei jemandem
    "sichter" => "\u{25A0}",  // Quadrat -- liegt vorm Sichter
    "fertig" => "\u{25CF}",   // Kreis   -- Transport abgeschlossen
  );
  $klasse = "estab-list-state estab-list-state--".estab_auth_html ($zustand);
  echo "<span class=\"".$klasse."\">";
  echo "<span aria-hidden=\"true\">".($zeichen [$zustand] ?? "")."</span>";
  echo "<span>".estab_message_html ($wort)."</span>";
  echo "</span>";
}

/** Show a safe attachment count beside one legacy operational-list message. */
function estab_list_attachment_badge ($storedReferences) {
  $attachmentCount = count (
    estab_message_list_attachment_tokens ($storedReferences)
  );
  if ($attachmentCount < 1) { return; }
  $attachmentLabel = estab_message_list_attachment_label (
    $attachmentCount
  );
  echo "<span class=\"estab-tool-badge estab-tool-badge-warning ".
       "estab-message-list-attachments\" ".
       "data-estab-message-attachment-badge ".
       "data-estab-message-attachment-count=\"".$attachmentCount."\" ".
       "aria-label=\"".estab_auth_html ($attachmentLabel)."\">".
       estab_auth_html ($attachmentLabel)."</span>";
}

/** Build and escape a category navigation URL without mixing data into HTML. */
function estab_list_category_url ($baseUrl, array $parameters) {
  $actingFunction = estab_workflow_staff_acting_function (
    $GLOBALS ["workflowSelectedIdentity"] ?? null
  );
  if (
    $actingFunction !== null
    && !array_key_exists ("acting_function", $parameters)
  ) {
    $parameters ["acting_function"] = $actingFunction;
  }
  $separator = strpos ((string) $baseUrl, "?") === false ? "?" : "&";
  return estab_message_html (
    (string) $baseUrl.$separator.http_build_query ($parameters, "", "&", PHP_QUERY_RFC3986)
  );
}

/** Build and escape the fixed local category-button URL. */
/*
 * Bedienelemente der Meldungsliste.
 *
 * Sie waren Bilder: Jeder Reiter, jede Seitengroesse und jeder Filter kam
 * als PNG vom Server, gezeichnet mit der einzigen mitgelieferten Schrift --
 * einer Serifenschrift in Kursiv. Neben der uebrigen Anwendung sah die
 * Liste dadurch aus wie ein anderes Programm, und kein Stylesheet erreichte
 * sie: Text in einem Bild laesst sich nicht umfaerben, nicht vergroessern
 * und nicht vorlesen.
 *
 * Es sind jetzt Verweise und Knoepfe. Sie tragen dieselbe Schrift wie alles
 * andere, weil sie gar keine eigene mitbringen, und sie sind klein: Eine
 * Liste lebt von den Zeilen darunter, nicht von ihrer Leiste.
 */

/** Ein Reiter der Kategorienleiste. */
function estab_list_tab_markup ($url, $label, $title, $active, $kind) {
  return "<a class=\"estab-list-tab estab-list-tab--".estab_message_html ((string) $kind)
    .($active ? " is-active" : "")."\""
    ." href=\"".$url."\""
    .($active ? " aria-current=\"page\"" : "")
    ." title=\"".estab_message_html ((string) $title)."\">"
    .estab_message_html ((string) $label)."</a>";
}

/** Eine Marke fuer die Zahl der Meldungen je Seite. */
function estab_list_size_markup ($url, $label, $active) {
  return "<a class=\"estab-list-chip".($active ? " is-active" : "")."\""
    ." href=\"".$url."\""
    .($active ? " aria-current=\"true\"" : "")
    ." title=\"".estab_message_html ((string) $label)." Meldungen je Seite\">"
    .estab_message_html ((string) $label)."</a>";
}

/**
 * Der Name, unter dem eine Handlung der Meldungsliste ausgewertet wird.
 *
 * Diese Bedienelemente waren einmal Bilder. Ein Bildknopf sendet nicht seinen
 * Namen, sondern die Klickkoordinaten darauf -- flt_for_x und flt_for_y --,
 * und die ganze Auswertung in mainindex.php sowie die Liste der erlaubten
 * Handlungen in app/workflow.php fragen deshalb nach dem _x.
 *
 * Als die Bilder durch echte Knoepfe ersetzt wurden, fiel das Suffix weg.
 * Blaettern, Erledigt-Filter, Unerledigt-Filter und Suchmaske sendeten
 * seither Namen, die niemand liest: Der Knopf sah aus wie ein Knopf, die
 * Seite lud neu, und es aenderte sich nichts.
 *
 * Das Suffix bleibt, weil es der Name der Handlung im ganzen Bestand ist --
 * nicht, weil es noch ein Bild gaebe.
 */
function estab_list_handlungsname ($name) {
  return estab_message_html ((string) $name)."_x";
}

/** Ein Schalter der Filterleiste; sein Zustand steht in aria-pressed. */
function estab_list_toggle_markup ($name, $label, $active) {
  return "<button class=\"estab-list-toggle".($active ? " is-active" : "")."\""
    ." type=\"submit\" name=\"".estab_list_handlungsname ($name)."\" value=\"1\""
    ." aria-pressed=\"".($active ? "true" : "false")."\">"
    .estab_message_html ((string) $label)."</button>";
}

/** Ein Knopf zum Blaettern. Das Zeichen ist Schmuck, das Wort ist die Angabe. */
function estab_list_pager_markup ($name, $glyph, $label) {
  // form= verbindet den Knopf mit der Filterleiste, in der er nicht steht.
  // Ohne diese Zeile ist er ein Absendeknopf ohne Formular -- und der tut
  // nichts.
  return "<button class=\"estab-list-pager\" type=\"submit\""
    ." form=\"estab-list-filter\""
    ." name=\"".estab_list_handlungsname ($name)."\" value=\"1\""
    ." title=\"".estab_message_html ((string) $label)."\">"
    ."<span aria-hidden=\"true\">".$glyph."</span>"
    ."<span class=\"estab-visually-hidden\">"
    .estab_message_html ((string) $label)."</span></button>";
}


/** Clamp and advance the legacy list pager without putting request data in SQL. */
function estab_list_page_window ($resultCount) {
  $pageSize = filter_var ($_SESSION["filter_anzahl"] ?? 15, FILTER_VALIDATE_INT, array (
    "options" => array ("min_range" => 1, "max_range" => 200),
  ));
  if (!is_int ($pageSize)) { $pageSize = 15; }
  $start = filter_var ($_SESSION["filter_start"] ?? 0, FILTER_VALIDATE_INT, array (
    "options" => array ("min_range" => 0, "max_range" => PHP_INT_MAX),
  ));
  if (!is_int ($start)) { $start = 0; }

  switch ((string) ($_SESSION["flt_navi"] ?? "")) {
    case "start":
      $start = 0;
    break;
    case "back":
      $start = max (0, $start - $pageSize);
    break;
    case "for":
      if ($start + $pageSize < $resultCount) { $start += $pageSize; }
    break;
    case "end":
      $start = $resultCount > 0
        ? intdiv ($resultCount - 1, $pageSize) * $pageSize
        : 0;
    break;
  }
  unset ($_SESSION["flt_navi"]);

  if ($resultCount === 0) {
    $start = 0;
  } elseif ($start >= $resultCount) {
    $start = intdiv ($resultCount - 1, $pageSize) * $pageSize;
  }
  $_SESSION["filter_anzahl"] = $pageSize;
  $_SESSION["filter_start"] = $start;
  $_SESSION["filter_rescount"] = $resultCount;
  return array ($start, $pageSize);
}

/**
 * Return the fixed Nachweisung view of the current request.
 *
 * The value is never echoed as data: it selects one of three known keys and
 * is used to keep the page control on the view the operator is looking at.
 */
function estab_list_tracking_view () {
  $request = $_GET;
  foreach (array ("nwalle", "nwe", "nwa") as $view) {
    if (array_key_exists ($view, $request)) { return $view; }
  }
  return "nwalle";
}

/**
 * Page state of one Nachweisung, read from its own request namespace.
 *
 * The order of a Nachweisung is prescribed by the TBB evidence number, so
 * only the page and the page size are negotiable. Each list carries its own
 * prefix, so two Nachweisungen on one page turn their pages independently.
 */
function estab_list_tracking_filters ($prefix) {
  $filters = estab_message_list_default_filters ();
  $filters ["sort"] = "number_asc";
  $request = array ();
  $source = $_GET;
  foreach (array ("page", "page_size") as $field) {
    if (array_key_exists ($prefix.$field, $source)) {
      $request [$prefix.$field] = $source [$prefix.$field];
    }
  }
  return estab_message_list_apply_request (
    $filters,
    $request,
    array (),
    $prefix
  );
}

/** Render the page control of one Nachweisung without losing its sibling. */
function estab_list_tracking_pager (array $filters, array $pageWindow, $prefix) {
  $hidden = array (estab_list_tracking_view () => "1");
  $source = $_GET;
  foreach (array ("nwe_", "nwa_") as $sibling) {
    if ($sibling === $prefix) { continue; }
    $page = $source [$sibling."page"] ?? null;
    if (
      is_string ($page)
      && preg_match ("/\\A[1-9][0-9]{0,6}\\z/D", $page) === 1
    ) {
      $hidden [$sibling."page"] = $page;
    }
  }
  estab_message_list_render_pager ($filters, $pageWindow, array (
    "action" => "nachwea.php",
    "method" => "get",
    "target" => "_self",
    "prefix" => $prefix,
    "hidden" => $hidden,
  ));
}

/** Count the combined transmission log of one already captured incident. */
function estab_list_combined_tracking_count (
  mysqli $connection,
  string $messageTable,
  int $incidentId
): int {
  $messageTable = estab_message_table ($messageTable);
  $incidentId = estab_message_positive_id ($incidentId);
  return estab_message_query_int (
    $connection,
    "SELECT COUNT(*) FROM ".$messageTable." AS m"
      ." WHERE m.`einsatz_id` = ?",
    array ($incidentId)
  );
}

/**
 * Read the combined transmission log for one already captured incident.
 *
 * The incident ID is deliberately explicit. A concurrent activation after
 * the caller captured the heading may therefore make the response stale, but
 * it can never mix the old heading with rows from the newly active incident.
 *
 * @return list<array<string,mixed>>
 */
function estab_list_combined_tracking_rows (
  mysqli $connection,
  string $messageTable,
  int $incidentId,
  int $limit,
  int $offset
): array {
  $messageTable = estab_message_table ($messageTable);
  $incidentId = estab_message_positive_id ($incidentId);
  if ($limit < 1 || $offset < 0) {
    throw new InvalidArgumentException ("Ungültiges Nachweisungsfenster");
  }
  return estab_message_query_rows (
    $connection,
    "SELECT m.`00_lfd`,m.`01_medium`,m.`01_datum`,m.`01_zeichen`,"
      ."m.`02_zeit`,m.`03_datum`,m.`06_befweg`,m.`06_befwegausw`,"
      ."m.`09_vorrangstufe`,m.`04_richtung`,"
      .estab_message_list_tbb_number_select_sql ("m").","
      ."m.`10_anschrift`,m.`12_inhalt`,m.`12_anhang`,m.`13_abseinheit`,"
      ."m.`14_zeichen`,m.`x01_abschluss`"
      ." FROM ".$messageTable." AS m"
      ." WHERE m.`einsatz_id` = ?"
      ." ORDER BY COALESCE(".
        estab_message_list_tbb_number_sql ("m").", 4294967296) ASC,"
      ." m.`00_lfd` ASC LIMIT ? OFFSET ?",
    array ($incidentId, $limit, $offset)
  );
}

/**
 * Read the heading and rows for an incident already authorised by the caller.
 *
 * @return array{
 *   incident:array<string,mixed>,
 *   page_window:array<string,mixed>,
 *   rows:list<array<string,mixed>>
 * }
 */
function estab_list_combined_tracking_data (
  mysqli $connection,
  int $incidentId,
  string $messageTable,
  array $filters
): array {
  $incidentId = estab_message_positive_id ($incidentId);
  $incident = estab_incident_find ($connection, $incidentId);
  if (!is_array ($incident)) {
    throw new RuntimeException ("Einsatz für Nachweisung nicht gefunden");
  }
  $pageWindow = estab_message_list_page_window (
    estab_list_combined_tracking_count (
      $connection,
      $messageTable,
      $incidentId
    ),
    $filters
  );
  return array (
    "incident" => $incident,
    "page_window" => $pageWindow,
    "rows" => estab_list_combined_tracking_rows (
      $connection,
      $messageTable,
      $incidentId,
      (int) $pageWindow ["page_size"],
      (int) $pageWindow ["offset"]
    ),
  );
}
/*****************************************************************************\
   Datei: liste.php

   benötigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }

class Listen extends kategorien {
/******************************************************************************\
   $welche ~= Art der Liste die Ausggeben werden soll. Möglich sind:
     FMA    - Fernmeldeausgangsliste
     STUSER - Stabbenutzer
     STSI   - Stab Sichter
     FMNWE  - Fernmelde Nachweis Eingang
     FMNWA  - Fernmelde Nachweis Ausgang
     ADMIN  - Administrative Liste
\******************************************************************************/

  var $listenart;
  var $benutzer;
  var $flt_status;
  var $flt_msg_pro_seite ;
  var $flt_start_msg;
  var $flt_gelesen ;
  var $flt_erledigt;
  var $incidentId;
  var $filters;
  var $pageWindow;
  var $operationalIdentity;
  var $stateFilterAvailable;


  // Listengestaltung

  function __construct (
    $welche,
    $user,
    $incidentId = null,
    $filters = null,
    $pageWindow = null
  ){
    $this->listen ($welche, $user, $incidentId, $filters, $pageWindow);
  }

/******************************************************************************\

\******************************************************************************/
  function explodereceiver ( $empf) {
    return estab_recipient_copy_map ($empf);
  }



/******************************************************************************\

\******************************************************************************/
  function listen (
    $welche,
    $user,
    $incidentId = null,
    $filters = null,
    $pageWindow = null
  ){
    $this->listenart = $welche;
    $this->benutzer  = $user;
    $this->incidentId = $incidentId === null
      ? null
      : estab_message_positive_id ($incidentId);
    $allowedRecipients = $this->message_list_recipient_functions ();
    $candidateFilters = is_array ($filters)
      ? $filters
      : estab_message_list_default_filters ();
    $this->filters = estab_message_list_parse_filters (
      estab_message_list_state_input ($candidateFilters, "ml_"),
      $allowedRecipients
    );
    $windowCount = is_array ($pageWindow)
      ? filter_var (
          $pageWindow ["count"] ?? null,
          FILTER_VALIDATE_INT,
          array ("options" => array ("min_range" => 0))
        )
      : 0;
    if (!is_int ($windowCount)) {
      throw new InvalidArgumentException ("Ungültiges Listenfenster");
    }
    $this->pageWindow = estab_message_list_page_window (
      $windowCount,
      $this->filters
    );
    $this->operationalIdentity = null;
    if (isset($_SESSION["filter_darstellung"])) { $this->flt_status   = $_SESSION["filter_darstellung"]; } else { $this->flt_status = NULL; }
    if (isset($_SESSION["filter_anzahl"])) { $this->flt_msg_pro_seite = $_SESSION["filter_anzahl"] ;     } else { $this->flt_msg_pro_seite = NULL; }
    if (isset($_SESSION["startmit"])) { $this->flt_start_msg          = $_SESSION["startmit"];           } else { $this->flt_start_msg = NULL; }
    if (isset($_SESSION["gelesene"])) { $this->flt_gelesen            = $_SESSION["gelesene"] ;          } else { $this->flt_gelesen  = NULL; }
    if (isset($_SESSION["erledigte"])) { $this->flt_erledigt          = $_SESSION["erledigte"] ;         } else { $this->flt_erledigt = NULL; }
  }

  /** Return configured recipient functions once, in display order. */
  function message_list_recipient_functions (){
    global $conf_empf;
    $recipients = array ();
    foreach (is_array ($conf_empf ?? null) ? $conf_empf : array () as $definition) {
      $function = is_array ($definition)
        ? (string) ($definition ["fkt"] ?? "")
        : "";
      if ($function !== "" && !in_array ($function, $recipients, true)) {
        $recipients [] = $function;
      }
    }
    return $recipients;
  }

  /**
   * Resolve the read/done state tables of one signed-in identity.
   *
   * Only a staff function owns these tables. A Fernmelder function such as
   * "A/W" carries no per-user read table, so the unread/done filter stays
   * unavailable there instead of guessing a table name.
   */
  function message_list_state_tables ($identity){
    include ("../4fcfg/dbcfg.inc.php");
    $prefix = (string) ($conf_4f_tbl ["usrtblprefix"] ?? "");
    $function = (string) ($identity ["funktion"] ?? "");
    $userCode = (string) ($identity ["kuerzel"] ?? "");
    if ($prefix === "" || $function === "" || $userCode === "") {
      return array ();
    }
    try {
      return array (
        "read" => estab_message_state_table (
          $prefix, $function, $userCode, "read"
        ),
        "done" => estab_message_state_table (
          $prefix, $function, $userCode, "done"
        ),
      );
    } catch (InvalidArgumentException) {
      return array ();
    }
  }

  /** Keep clamped pages separate for A/W and Si second-sighting views. */
  function message_list_session_key (){
    return $this->listenart === "FMADMIN"
      ? "estab_message_second_sighting_aw_filters"
      : "estab_message_second_sighting_si_filters";
  }

  function required_incident_id (){
    if ($this->incidentId === null) {
      throw new RuntimeException (
        "Für diese Liste wurde kein autorisierter Einsatz übergeben"
      );
    }
    return estab_message_positive_id ($this->incidentId);
  }

/******************************************************************************\

\******************************************************************************/
  function darstellungs_art ( $what ){

    include ("../4fcfg/config.inc.php");

    switch ($this->listenart){
      /*************************************************************************\
                               FFFFF M   M  AAA
                               F     MM MM A   A
                               FFF   M M M AAAAA
                               F     M   M A   A
                               F     M   M A   A
      \*************************************************************************/
      case "FMA":           /***** F M A ****/
      case "LDF":           /***** L d F ****/
      break;

      /*************************************************************************\
        SSSSS  TTTTT   AAA  BBBB   l
        S        T    A   A B   B  l
        SSSSS    T    AAAAA BBBBB  l 
            S    T    A   A B   B  l
        SSSSS    T    A   A BBBB   l esen
      \*************************************************************************/
      case "Stab_lesen":  // ******  S T A B    l e s e n *****
          if ( debug ) { echo "<b>file:liste.php:_92 fkt:darstellungsart - switch (this->listenart):Stab_lesen </b><br>"; }
          /*
           * Die Kennung verbindet den Blaetterer mit dieser Filterleiste.
           *
           * listen_navi() gibt die Blaetterknoepfe nach darstellungs_art()
           * aus -- also ausserhalb dieses Formulars. Ein type="submit" ohne
           * Formular tut gar nichts: Das war der eigentliche Grund, warum
           * das Blaettern in "Stab lesen" wirkungslos blieb. Das
           * form-Attribut der Knoepfe zeigt hierher.
           */
          echo "\n<form id=\"estab-list-filter\" action=\"".estab_message_html ($conf_4f ["MainURL"])."\" method=\"POST\" target=\"mainframe\" data-estab-list-filter>\n";
          echo estab_list_acting_function_field ();
          echo "<table><tbody>";
          echo "<tr>";
          echo "<td>";
          echo "<big><b>".($_SESSION['filter_start']+1)."|".($_SESSION['filter_start']+$_SESSION['filter_anzahl'])."|<big>".($_SESSION["filter_rescount"])."</big></b></big>";
          echo "</td>";
          echo "<td>";
          echo "Meldung/Seite:<br>\n";

            // Voreinstellung fÃ¼r die Meldungen pro Seite
          if ( !(isset ($_SESSION["filter_anzahl"])) OR
              ( $_SESSION["filter_anzahl"] == "" )
           ){$_SESSION["filter_anzahl"] = 5; }

          echo "<table border=\"0\" ><tbody>";
          echo "<tr>";

          echo "<td>";
          echo "<span class=\"estab-list-chips\">";
            for ($pps=5; $pps <=25; $pps+=5){
              echo estab_list_size_markup (
                estab_list_category_url ($conf_4f ["MainURL"], array (
                  "filter_anzahl_x" => 1, "filter_anzahl" => $pps,
                )),
                $pps,
                $_SESSION["filter_anzahl"] == $pps
              );
            }
          echo "</span>";
          echo "</td>";
          echo "</tr>";
          echo "</tbody></table>";
          echo "</td>";
          echo "<td>";
          $an = $_SESSION ['filter_unerledigt'] != 0;
          echo estab_list_toggle_markup (
            $an ? "filter_unerledigt_aus" : "filter_unerledigt_ein", "Unerledigt", $an
          );
          echo "</td>";
          echo "<td>";
          $an = $_SESSION ['filter_erledigt'] != 0;
          echo estab_list_toggle_markup (
            $an ? "filter_erledigt_aus" : "filter_erledigt_ein", "Erledigt", $an
          );
          echo "</td>";
          echo "<td>";
          $an = $_SESSION ['flt_find_mask'] != 0;
          echo estab_list_toggle_markup (
            $an ? "flt_find_mask_aus" : "flt_find_mask_ein", "Suche", $an
          );
          echo "</td>";

        echo "<!-- liste.php 233 -->";
        echo "<td>";
        echo "<table><tbody>";
        echo "<tr>";
        if ($_SESSION["flt_find_mask"] == 1){
          echo "<td>";
          if (isset ($_SESSION ["flt_search"]) ) { $defvalue = $_SESSION ["flt_search"] ;}
          else {$defvalue = "";}
          echo "<p>Suchbegriff: <input name=\"flt_search\" value=\"".estab_message_html ($defvalue)."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
          echo "</td>";
          echo "<td>";
          echo "<input name=\"filter_suche\" value=\"suchen\" type=\"submit\">\n";
          echo "</td>";
        }
        echo "</tr>";
        echo "</tbody></table>";
        echo "</td>";
        echo "</tr>";
        echo "</tbody></table>";
        echo "</form>\n";
      break;


      case "Stab_sichten":   /*********** S t a b   s i c h t e n ************/
      break;
      /*************************************************************************\
               SSSSS III  AAA  DDDD  M   M III N   N
               S      I  A   A D   D MM MM  I  NN  N
               SSSSS  I  AAAAA D   D M M M  I  N N N
                   S  I  A   A D   D M   M  I  N  NN
               SSSSS III A   A DDDD  M   M III N   N
      \*************************************************************************/
      case "SIADMIN":  // ***************  SICHTER ADMINISTRATOR  *********************
      case "FMADMIN":
          if ( debug ) { echo "<b>file:liste.php:194 fkt:darstellungsart - switch (this->listenart):SIADMIN/FMADMIN </b><br>"; }
          /*
           * Die Kennung verbindet den Blaetterer mit dieser Filterleiste.
           *
           * listen_navi() gibt die Blaetterknoepfe nach darstellungs_art()
           * aus -- also ausserhalb dieses Formulars. Ein type="submit" ohne
           * Formular tut gar nichts: Das war der eigentliche Grund, warum
           * das Blaettern in "Stab lesen" wirkungslos blieb. Das
           * form-Attribut der Knoepfe zeigt hierher.
           */
          echo "\n<form id=\"estab-list-filter\" action=\"".estab_message_html ($conf_4f ["MainURL"])."\" method=\"POST\" target=\"mainframe\" data-estab-list-filter>\n";
          echo estab_list_acting_function_field ();
          echo "<table><tbody>";
          echo "<tr>";
          echo "<td>";
          echo "<big><b>".($_SESSION['filter_start']+1)."|".($_SESSION['filter_start']+$_SESSION['filter_anzahl'])."|<big>".($_SESSION["filter_rescount"])."</big></b></big>";
          echo "</td>";
          echo "<td>";
          echo "Meldung/Seite:<br>\n";

            // Voreinstellung fÃ¼r die Meldungen pro Seite
          if ( !(isset ($_SESSION["filter_anzahl"])) OR
              ( $_SESSION["filter_anzahl"] == "" )
           ){$_SESSION["filter_anzahl"] = 5; }

          echo "<table border=\"0\" ><tbody>";
          echo "<tr>";

          echo "<td>";
          echo "<span class=\"estab-list-chips\">";
            for ($pps=5; $pps <=25; $pps+=5){
              echo estab_list_size_markup (
                estab_list_category_url ($conf_4f ["MainURL"], array (
                  "filter_anzahl_x" => 1, "filter_anzahl" => $pps,
                )),
                $pps,
                $_SESSION["filter_anzahl"] == $pps
              );
            }
          echo "</span>";
          echo "</td>";
          echo "</tr>";
          echo "</tbody></table>";
          echo "</td>";

		  
          echo "<td>";
          $an = $_SESSION ['flt_find_mask'] != 0;
          echo estab_list_toggle_markup (
            $an ? "flt_find_mask_aus" : "flt_find_mask_ein", "Suche", $an
          );
          echo "</td>";

        echo "<!-- liste.php 233 -->";
        echo "<td>";
        echo "<table><tbody>";
        echo "<tr>";
        if ($_SESSION["flt_find_mask"] == 1){
          echo "<td>";
          if (isset ($_SESSION ["flt_search"]) ) { $defvalue = $_SESSION ["flt_search"] ;}
          else {$defvalue = "";}
          echo "<p>Suchbegriff: <input name=\"flt_search\" value=\"".estab_message_html ($defvalue)."\" type=\"text\" size=\"30\" maxlength=\"30\"></p>";
          echo "</td>";
          echo "<td>";
          echo "<input name=\"filter_suche\" value=\"suchen\" type=\"submit\">\n";
          echo "</td>";
        }
        echo "</tr>";
        echo "</tbody></table>";
        echo "</td>";
        echo "</tr>";
        echo "</tbody></table>";
        echo "</form>\n";


      break;
    }
    if ( debug ) { echo "<b>file:liste.php:279 fkt:darstellungsart_ENDE </b><br>"; }
  }

  /******************************************************************************\
    Funktion:  listen_navi ()
  SELECT * FROM `nv_nachrichten` WHERE `00_lfd` IN

  (SELECT msg FROM `nv_masterkategolink` WHERE `katego` = (

  SELECT lfd FROM `nv_masterkatego` WHERE `kategorie` = "2m"));

  \******************************************************************************/
  function  listen_navi (){
    include ("../4fcfg/config.inc.php");
    echo "<span class=\"estab-list-pagers\">";
    echo estab_list_pager_markup ("flt_start", "&#124;&#9664;", "Erste Seite");
    echo estab_list_pager_markup ("flt_back", "&#9664;", "Seite zurück");
    echo estab_list_pager_markup ("flt_for", "&#9654;", "Seite vor");
    echo estab_list_pager_markup ("flt_end", "&#9654;&#124;", "Letzte Seite");
    echo "</span>";
  }


  /******************************************************************************\
    Funktion:  kategoliste
  SELECT * FROM `nv_nachrichten` WHERE `00_lfd` IN

  (SELECT msg FROM `nv_masterkategolink` WHERE `katego` = (

  SELECT lfd FROM `nv_masterkatego` WHERE `kategorie` = "2m"));

  \******************************************************************************/

  var $db_server;
  var $db_benutzer;
  var $db_passwort;
  var $db_name ;
  var $db_tablname ;
  var $db_tablnamelk;

  var $db_master_katego ;

  var $sqlquery;
  var $db_hndl ;
  var $masterresult ;
  var $fktresult ;
  var $userresult ;
  var $resultcount ;

  var $redcopy2 ;
  var $dbtyp ;

  var $grundkatego;

  function set_katego_para ($table){

    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");
    $this->redcopy2  = $redcopy2 ;

    $identity = estab_auth_session_identity ($_SESSION);
    if ($identity === null) {
      throw new EstabCategoryAuthorizationException ("Anmeldung erforderlich.");
    }
    $identity = estab_category_route_identity (
      $identity,
      $GLOBALS ["workflowSelectedIdentity"] ?? null
    );
    $this->categoryIdentity = $identity;
    $this->categoryScope = estab_category_scope ((string) $table, $identity, $conf_4f_tbl);
    $this->stab_fkt = $identity ["funktion"];
    $this->dbtyp = $this->categoryScope ["type"];
    $this->db_tablname = $this->categoryScope ["category_table"];
    $this->db_tablnamelk = $this->categoryScope ["link_table"];
    $this->db_tbl = str_ends_with ($this->db_tablname, "_katego")
      ? substr ($this->db_tablname, 0, -7)
      : $this->db_tablname;
    if ($this->dbtyp === "master") {
      $this->db_master_katego = $this->db_tablname;
    }

    $this->db_server   = $conf_4f_db ["server"];
    $this->db_benutzer = $conf_4f_db ["user"];
    $this->db_passwort = $conf_4f_db ["password"];
    $this->db_name     = $conf_4f_db ["datenbank"];
    $this->grundkatego = array (
          1 => array ("kategorie"    => "Alle",
                      "beschreibung" => "ohne Berücksichtigung der Kategorien"),
          2 => array ("kategorie"    => "ohne",
                      "beschreibung" => "Ohne Kategorie"));

    if (!$this->categoryConnection instanceof mysqli) {
      $this->categoryConnection = estab_auth_connect ($conf_4f_db);
    }
    // Preserve the historical public handle for callers outside this class.
    $this->db_hndl = $this->categoryConnection;
  }

  /** Preserve the one-based result arrays consumed by the legacy renderer. */
  function category_rows_one_based (){
    $rows = estab_category_fetch_all ($this->connection (), $this->categoryScope);
    $result = array ();
    foreach ($rows as $index => $row) {
      $result [$index + 1] = $row;
    }
    $this->resultcount = count ($result);
    return $result;
  }

  /***********************************************************************************

  ***********************************************************************************/
  function kategoliste (){
    if ($_SESSION["filter_darstellung"] == "1" ){
      include ("../4fcfg/config.inc.php");

      $this->set_katego_para ("master");   // MASTER
      $this->masterresult = $this->category_rows_one_based ();

      $this->set_katego_para ("fkt");     // FUNKTION
      $this->fktresult = $this->category_rows_one_based ();

      $this->set_katego_para ("user");     // USER
      $this->userresult = $this->category_rows_one_based ();

      $mastercount = COUNT($this->masterresult) ;
      $fktcount    = COUNT($this->fktresult) ;
      $usercount   = COUNT($this->userresult) ;

      if (isset ($_SESSION ['global_katego'])) {
        $kategoselected = $_SESSION ['global_katego'];
      }

      /*
       * Die Kategorien stehen in einem Aufklapp.
       *
       * Es sind fuenfundzwanzig; nebeneinander gestellt assen sie drei
       * Zeilen ueber der Liste. docs/GESTALTUNG.md Abschnitt 7 laesst
       * hoechstens fuenf Schnellfilter zu -- was darueber hinausgeht, gehoert
       * in einen Aufklapp. Der steht offen, sobald eine Kategorie gewaehlt
       * ist, und sagt es im Namen: Ein zugeklappter Kasten mit einem
       * wirksamen Filter darin erzeugt genau die Frage "warum sehe ich meine
       * Meldung nicht".
       *
       * Als <details>, damit er ohne JavaScript auf- und zugeht.
       */
      $kategorieGewaehlt = isset ($_SESSION ['ma_katego'])
        || isset ($_SESSION ['fk_katego'])
        || isset ($_SESSION ['us_katego']);
      echo "<details class=\"estab-list-categories\""
        .($kategorieGewaehlt ? " open" : "").">";
      echo "<summary>Kategorien"
        .($kategorieGewaehlt ? " \u{00B7} aktiv" : "")."</summary>";

        // MASTER KATEGORIE
      if ($mastercount != 0){
        echo "<nav class=\"estab-list-tabs estab-list-tabs--global\" aria-label=\"Kategorien\">";
        $aktiv = !isset ($_SESSION ['ma_katego']);
        echo estab_list_tab_markup (
          estab_list_category_url ($conf_4f ["MainURL"], array (
            "ma_ktgotyp" => "global", "ma_ktgo" => "alle",
          )), "Alle", "Alle Kategorien", $aktiv, "global"
        )."\n";
        for ($i = 1; $i <= $mastercount; $i++) {
          if ( $this->masterresult[$i]["kategorie"] == "" ) { continue; }
          $aktiv = (($_SESSION ['ma_katego'] ?? "") == $this->masterresult[$i]["lfd"])
            AND (($_SESSION ['ma_kategotyp'] ?? "") == "global");
          echo estab_list_tab_markup (
            estab_list_category_url ($conf_4f ["MainURL"], array (
              "ma_ktgotyp" => "global", "ma_ktgo" => $this->masterresult[$i]["lfd"],
            )),
            $this->masterresult[$i]["kategorie"],
            $this->masterresult[$i]["beschreibung"] ?? "",
            $aktiv, "global"
          )."\n";
        }
        echo "</nav>";
      }

        // FUNKTIONS KATEGORIE
      if ($fktcount != 0){
        echo "<nav class=\"estab-list-tabs estab-list-tabs--funktion\" aria-label=\"Kategorien\">";
        $aktiv = !isset ($_SESSION ['fk_katego']);
        echo estab_list_tab_markup (
          estab_list_category_url ($conf_4f ["MainURL"], array (
            "fk_ktgotyp" => "fkt", "fk_ktgo" => "alle",
          )), "Alle", "Alle Funktionskategorien", $aktiv, "funktion"
        )."\n";
        for ($i = 1; $i <= $fktcount; $i++) {
          if ( $this->fktresult[$i]["kategorie"] == "" ) { continue; }
          $aktiv = (($_SESSION ['fk_katego'] ?? "") == $this->fktresult[$i]["lfd"])
            AND (($_SESSION ['fk_kategotyp'] ?? "") == "fkt");
          echo estab_list_tab_markup (
            estab_list_category_url ($conf_4f ["MainURL"], array (
              "fk_ktgotyp" => "fkt", "fk_ktgo" => $this->fktresult[$i]["lfd"],
            )),
            $this->fktresult[$i]["kategorie"],
            $this->fktresult[$i]["beschreibung"] ?? "",
            $aktiv, "funktion"
          )."\n";
        }
        echo "</nav>";
      }

        // USER KATEGORIE
      if ($usercount != 0){
        echo "<nav class=\"estab-list-tabs estab-list-tabs--benutzer\" aria-label=\"Kategorien\">";
        $aktiv = !isset ($_SESSION ['us_katego']);
        echo estab_list_tab_markup (
          estab_list_category_url ($conf_4f ["MainURL"], array (
            "us_ktgotyp" => "user", "us_ktgo" => "alle",
          )), "Alle", "Alle eigenen Kategorien", $aktiv, "benutzer"
        )."\n";
        for ($i = 1; $i <= $usercount; $i++) {
          if ( $this->userresult[$i]["kategorie"] == "" ) { continue; }
          $aktiv = (($_SESSION ['us_katego'] ?? "") == $this->userresult[$i]["lfd"])
            AND (($_SESSION ['us_kategotyp'] ?? "") == "user");
          echo estab_list_tab_markup (
            estab_list_category_url ($conf_4f ["MainURL"], array (
              "us_ktgotyp" => "user", "us_ktgo" => $this->userresult[$i]["lfd"],
            )),
            $this->userresult[$i]["kategorie"],
            $this->userresult[$i]["beschreibung"] ?? "",
            $aktiv, "benutzer"
          )."\n";
        }
        echo "</nav>";
      }
      echo "</details>";


    }
  }



  /**
   * Read one authorised, server-side page for the two second-sighting views.
   *
   * SQL applies the same object visibility before COUNT/LIMIT which PHP then
   * verifies again for every returned object. A mismatch fails closed instead
   * of exposing rows or publishing misleading result totals.
   */
  function get_admin_message_list (
    $messageConnection,
    $messageTable,
    $identity,
    $incidentId,
    $listenart
  ){
    $expectedFunction = $listenart === "FMADMIN" ? "A/W" : "Si";
    $expectedRole = $listenart === "FMADMIN" ? "Fernmelder" : "Stab";
    if (
      ($identity ["funktion"] ?? null) !== $expectedFunction
      || ($identity ["rolle"] ?? null) !== $expectedRole
    ) {
      throw new EstabReadPermissionException (
        "Die zweite Sichtung ist für diese Dienstfunktion nicht freigegeben."
      );
    }
    if (
      $this->incidentId !== null
      && $this->required_incident_id () !== (int) $incidentId
    ) {
      throw new EstabReadPermissionException (
        "Der aktive Einsatz hat sich während des Listenaufrufs geändert."
      );
    }

    $visibility = estab_read_message_visibility_sql ($identity, "m");
    $stateTables = $this->message_list_state_tables ($identity);
    $this->stateFilterAvailable =
      estab_message_list_has_state_tables ($stateTables);
    if (!$this->stateFilterAvailable) {
      // A stored filter must not trap a function which cannot answer it.
      $this->filters ["read_state"] = "";
      $this->filters ["done_state"] = "";
    }
    $filter = estab_message_list_filter_sql (
      $this->filters,
      "m",
      $stateTables
    );
    $where = array (
      "m.`einsatz_id` = ?",
      $visibility ["sql"],
    );
    if ($filter ["sql"] !== "") {
      $where [] = $filter ["sql"];
    }
    $parameters = array_merge (
      array ((int) $incidentId),
      $visibility ["params"],
      $filter ["params"]
    );
    $whereSql = implode (" AND ", $where);
    $count = estab_message_query_int (
      $messageConnection,
      "SELECT COUNT(*) FROM ".$messageTable." AS m WHERE ".$whereSql,
      $parameters
    );
    $this->pageWindow = estab_message_list_page_window (
      $count,
      $this->filters
    );
    $this->filters ["page"] = $this->pageWindow ["page"];
    $_SESSION [$this->message_list_session_key ()] = $this->filters;

    $stateSelect = estab_message_list_state_select_sql ($stateTables, "m");
    $query = "SELECT m.`00_lfd`,m.`01_zeichen`,m.`02_zeit`,".
      "m.`02_zeichen`,m.`03_datum`,m.`03_zeichen`,m.`04_richtung`,".
      estab_message_list_tbb_number_select_sql ("m").",".
      "m.`01_medium`,m.`05_gegenstelle`,m.`06_befweg`,".
      "m.`09_vorrangstufe`,m.`10_anschrift`,m.`11_rufnummer`,".
      "m.`12_anhang`,m.`12_betreff`,m.`12_inhalt`,m.`12_abfzeit`,".
      "m.`13_abseinheit`,m.`14_funktion`,m.`15_quitdatum`,".
      "m.`15_quitzeichen`,m.`16_empf`,m.`x00_status`,".
      "m.`x01_abschluss`,m.`x02_sperre`,m.`x03_sperruser`,".
      estab_message_list_dwell_select_sql ("m").
      ($stateSelect === "" ? "" : ",".$stateSelect).
      " FROM ".$messageTable." AS m WHERE ".$whereSql." ORDER BY ".
      estab_message_list_order_sql ($this->filters, "m").
      " LIMIT ? OFFSET ?";
    $result = estab_message_query_rows (
      $messageConnection,
      $query,
      array_merge ($parameters, array (
        (int) $this->pageWindow ["page_size"],
        (int) $this->pageWindow ["offset"],
      ))
    );
    $verified = estab_read_filter_messages ($result, $identity);
    if (count ($verified) !== count ($result)) {
      throw new LogicException (
        "SQL/PHP visibility drift in second-sighting message list"
      );
    }
    return $verified;
  }

  function get_list ($listenart){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $messageConnection = estab_message_connect ($conf_4f_db);
    $readTransactionActive = false;
    try {
      if (!$messageConnection->begin_transaction ()) {
        throw new RuntimeException ("Lesetransaktion konnte nicht begonnen werden.");
      }
      $readTransactionActive = true;
      $identity = estab_read_session_identity ($_SESSION);
      if ($identity === null) {
        throw new EstabReadPermissionException ("Anmeldung erforderlich.");
      }
      $identity = estab_category_route_identity (
        $identity,
        $GLOBALS ["workflowSelectedIdentity"] ?? null
      );
      $scope = estab_read_require_operational_scope (
        $messageConnection,
        $identity,
        true
      );
      $identity = $scope ["identity"];
      $this->operationalIdentity = $identity;
      $incidentId = (int) $scope ["incident"] ["active_einsatz_id"];

      $messageTable = estab_message_table (
        (string) $conf_4f_tbl ["nachrichten"]
      );
      if (in_array ($listenart, array ("FMADMIN", "SIADMIN"), true)) {
        $adminResult = $this->get_admin_message_list (
          $messageConnection,
          $messageTable,
          $identity,
          $incidentId,
          $listenart
        );
        if (!$messageConnection->commit ()) {
          throw new RuntimeException (
            "Lesetransaktion konnte nicht abgeschlossen werden."
          );
        }
        $readTransactionActive = false;
        return $adminResult;
      }
      $parameters = array ($incidentId);
      $where = array ("m.`einsatz_id` = ?");
      $joins = array ();
      $displayFilters =
        (int) ($_SESSION["filter_darstellung"] ?? 1) === 1;

      switch ($listenart) {
        case "Stab_lesen":
        case "global":
          // Before the terminal workflow state, only the author may inspect
          // their outgoing draft. Recipient copies use an exact token.
          $where[] = estab_message_staff_access_sql ("m");
          $parameters[] = estab_message_recipient_pattern (
            $identity ["funktion"]
          );
          $parameters[] = $identity ["funktion"];

          $prefix = (string) $conf_4f_tbl ["usrtblprefix"];
          $readTable = estab_message_table (estab_message_state_table (
            $prefix, $identity ["funktion"], $identity ["kuerzel"], "read"
          ));
          $doneTable = estab_message_table (estab_message_state_table (
            $prefix, $identity ["funktion"], $identity ["kuerzel"], "done"
          ));
          $functionBase =
            $prefix."_fkt_".strtolower ($identity ["funktion"]);
          $userBase = $prefix.strtolower ($identity ["funktion"]).
            "_".$identity ["kuerzel"];
          // An empty search term is no search. Treating it as one turned the
          // pattern into "%%", matched every message and silently disabled the
          // read/done/category filters below.
          $searchTerm = trim ((string) ($_SESSION["flt_search"] ?? ""));
          $searchActive = $searchTerm !== "";
          if ($displayFilters && !$searchActive) {
            if ((int) ($_SESSION["filter_gelesen"] ?? 0) === 1) {
              $where[] =
                "m.`00_lfd` IN (SELECT `nachnum` FROM ".$readTable.")";
            }

            $showDone = (int) ($_SESSION["filter_erledigt"] ?? 0) === 1;
            $showOpen = (int) ($_SESSION["filter_unerledigt"] ?? 0) === 1;
            if ($showDone xor $showOpen) {
              $where[] = "m.`00_lfd` ".($showDone ? "" : "NOT ").
                "IN (SELECT `nachnum` FROM ".$doneTable.")";
            } elseif (!$showDone && !$showOpen) {
              $where[] = "1 = 0";
            }

            $categorySpecs = array (
              array ("ma_katego", $conf_4f_tbl ["masterkatego"], $conf_4f_tbl ["masterkategolk"], "ma"),
              array ("fk_katego", $functionBase."_katego", $functionBase."_kategolink", "fk"),
              array ("us_katego", $userBase."_katego", $userBase."_kategolink", "us"),
            );
            foreach ($categorySpecs as $spec) {
              if (!isset ($_SESSION[$spec[0]])) { continue; }
              $categoryId = estab_message_positive_id (
                $_SESSION[$spec[0]]
              );
              $categoryTable = estab_message_table ((string) $spec[1]);
              $linkTable = estab_message_table ((string) $spec[2]);
              $alias = $spec[3];
              $joins[] = "INNER JOIN ".$linkTable." AS ".$alias."l".
                " ON ".$alias."l.`msg` = m.`00_lfd`";
              $joins[] = "INNER JOIN ".$categoryTable." AS ".$alias."c".
                " ON ".$alias."c.`lfd` = ".$alias."l.`katego`";
              $where[] = $alias."c.`lfd` = ?";
              $parameters[] = $categoryId;
            }
          }

          if ($searchActive) {
            // A percent sign or underscore in the search term is a character
            // the operator typed, not a wildcard.
            $searchPattern = estab_message_list_like_pattern ($searchTerm);
            $where[] =
              "(CAST(".estab_message_list_tbb_number_sql ("m").
              " AS CHAR) LIKE ? ESCAPE '!' OR ".
              "m.`10_anschrift` LIKE ? ESCAPE '!' OR ".
              "m.`12_abfzeit` LIKE ? ESCAPE '!' OR ".
              "m.`12_inhalt` LIKE ? ESCAPE '!' OR ".
              "m.`13_abseinheit` LIKE ? ESCAPE '!')";
            for ($i = 0; $i < 5; $i++) {
              $parameters[] = $searchPattern;
            }
          }
        break;

        default:
          throw new InvalidArgumentException ("Unbekannte Listenart");
      }

      $from = " FROM ".$messageTable." AS m ".implode (" ", $joins);
      $whereSql = implode (" AND ", $where);
      $query = "SELECT DISTINCT m.*,".
        estab_message_list_tbb_number_select_sql ("m").
        $from." WHERE ".$whereSql.
        " ORDER BY ".
        estab_message_priority_order_sql ("m.`09_vorrangstufe`").
        " DESC, COALESCE(".estab_message_list_tbb_number_sql ("m").
        ", 0) DESC, m.`12_abfzeit` DESC, m.`00_lfd` DESC";
      $result = estab_message_query_rows (
        $messageConnection,
        $query,
        $parameters
      );
      $result = estab_read_filter_messages ($result, $identity);
      if ($displayFilters) {
        list ($start, $pageSize) = estab_list_page_window (count ($result));
        $result = array_slice ($result, $start, $pageSize);
      }
      if (!$messageConnection->commit ()) {
        throw new RuntimeException (
          "Lesetransaktion konnte nicht abgeschlossen werden."
        );
      }
      $readTransactionActive = false;
    } finally {
      if ($readTransactionActive) {
        $messageConnection->rollback ();
      }
      estab_auth_close ($messageConnection);
    }
    return $result === array () ? "" : $result;
  }

/******************************************************************************\

\******************************************************************************/
  function createlist (){
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>".$this->listenart."<br>";  }
    switch ($this->listenart){
      case "LDF":
        $incidentId = $this->required_incident_id ();
        $query = "SELECT `00_lfd`,`04_richtung`,`05_gegenstelle`,
                         `09_vorrangstufe`,`10_anschrift`,`12_abfzeit`,
                         `12_anhang`,`12_inhalt`,`13_abseinheit`
                    FROM `".$conf_4f_tbl ["nachrichten"]."`
                   WHERE `einsatz_id` = ?
                     AND `x00_status` = 1
                     AND `02_zeit` IS NULL AND `02_zeichen` = \"\"
                     AND `03_datum` IS NULL AND `03_zeichen` = \"\"
                     AND (
                       (`04_richtung` = \"E\"
                         AND `15_quitdatum` IS NULL
                         AND `15_quitzeichen` = \"\")
                       OR
                       (`04_richtung` = \"A\"
                         AND `15_quitdatum` IS NOT NULL
                         AND `15_quitzeichen` != \"\")
                     )
                     AND `x01_abschluss` = \"f\"
                ORDER BY ".
                estab_message_priority_order_sql ("`09_vorrangstufe`").
                " DESC, `12_abfzeit`;";
        $messageConnection = estab_message_connect ($conf_4f_db);
        try {
          $result = estab_message_query_rows (
            $messageConnection,
            $query,
            array ($incidentId)
          );
        } finally {
          estab_auth_close ($messageConnection);
        }
        $result = $result === array () ? "" : $result;
        /*
         * Die Tabelle stand in schwarzen Rahmen mit weisser Kopfzeile und
         * acht Pixeln Polster je Zelle -- eine Darstellung aus der Zeit, in
         * der Tabellen die Gestaltung waren. Sie traegt jetzt denselben Stil
         * wie jede andere Tabelle der Anwendung.
         */
        echo "<header class=\"estab-tool-panel-heading\">";
        echo "<span class=\"estab-tool-eyebrow\">Disposition</span>";
        echo "<h2>LdF: Rufnamen und Beförderungswege</h2>";
        echo "</header>";
        if ($result != "") {
          echo "<div class=\"estab-tool-table-wrap\">";
          echo "<table class=\"estab-tool-table estab-list-table\"><thead>";
          echo "<tr>";
          echo "<th>E/A</th><th>Zeit</th><th>Vorrang</th><th>Rufname</th><th>Von/An</th><th>Inhalt</th></tr>";
          echo "</thead><tbody>";
          foreach ($result as $row) {
            $abfzeit = convdatetimeto ($row ["12_abfzeit"]);
            $direction = (string) $row ["04_richtung"];
            $address = $direction === "E"
              ? ((string) $row ["13_abseinheit"] !== ""
                ? $row ["13_abseinheit"]
                : "Absender zu übersetzen")
              : $row ["10_anschrift"];
            echo "<tr>";
            echo "<td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $direction);
            echo "</td><td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $abfzeit ["stak"] ?? "");
            echo "</td><td>";
            estab_list_detail_action (
              "ldf",
              "meldung",
              $row ["00_lfd"],
              estab_message_priority_label ($row ["09_vorrangstufe"])
            );
            echo "</td><td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $row ["05_gegenstelle"] ?: "noch offen");
            echo "</td><td>";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $address);
            echo "</td><td style=\"text-align:left\">";
            estab_list_detail_action ("ldf", "meldung", $row ["00_lfd"], $row ["12_inhalt"]);
            estab_list_attachment_badge ($row ["12_anhang"] ?? null);
            echo "</td></tr>";
          }
          echo "</tbody></table></div>";
        } else {
          echo "<p class=\"estab-message-list-empty\">".
               "Zur Zeit keine Nachricht für die LdF</p>";
        }
      break;

      case "FMA":           /***** F M A ****/
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - fkt:createlist - switch(listenart) -- case (FMA)<br>";}
        $incidentId = $this->required_incident_id ();
        $query = "SELECT `00_lfd`,`07_durchspruch`, `08_befhinweis`, `08_befhinwausw`,`09_vorrangstufe`, `10_anschrift`, `12_abfzeit`, `12_anhang`, `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
                  WHERE `einsatz_id` = ?
                  AND `x00_status` = 2
                  AND `02_zeit` IS NOT NULL AND `02_zeichen` != \"\"
                  AND `01_medium` != \"\"
                  AND `06_befweg` != \"\"
                  AND `15_quitdatum` IS NOT NULL
                  AND `15_quitzeichen` != \"\"
                  AND ((`04_richtung` = \"A\") AND (`03_datum` IS NULL) AND (`03_zeichen` = \"\")) order by ".
                  estab_message_priority_order_sql ("`09_vorrangstufe`").
                  " DESC, `12_abfzeit` ; ";
        $messageConnection = estab_message_connect ($conf_4f_db);
        try {
          $result = estab_message_query_rows (
            $messageConnection,
            $query,
            array ($incidentId)
          );
        } finally {
          estab_auth_close ($messageConnection);
        }
        $result = $result === array () ? "" : $result;
        if ($result != "" ){
          echo "<div class=\"estab-tool-table-wrap\">";
          echo "<table class=\"estab-tool-table estab-list-table\">\n<thead>\n";
          echo "<tr>\n";
          echo "<th>Zeit</th>\n";
          echo "<th>Vorrang</th>\n";
          echo "<th>Anschrift</th>\n";
          echo "<th>Inhalt</th>\n";
          echo "</tr>\n</thead>\n<tbody>\n";
          foreach ($result as $row){
            $priorityStyle = estab_message_priority_requires_attention (
              $row["09_vorrangstufe"]
            )
                ? " style=\"background-color: rgb(255,255,100); color:#000000; font-weight:bold;\""
                : "";
            echo "<tr".$priorityStyle.">\n";
            $abfzeit = convdatetimeto ($row["12_abfzeit"]);
            echo "<td>"; if (($row["12_abfzeit"] != "")) { estab_list_detail_action ("fm", "meldung", $row["00_lfd"], $abfzeit["stak"]); } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
            echo "<td>";
            estab_list_detail_action (
              "fm",
              "meldung",
              $row["00_lfd"],
              estab_message_priority_label ($row["09_vorrangstufe"])
            );
            echo "</td>\n";
            echo "<td>"; if (($row["10_anschrift"] != "")) { estab_list_detail_action ("fm", "meldung", $row["00_lfd"], $row["10_anschrift"]); } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
            echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { estab_list_detail_action ("fm", "meldung", $row["00_lfd"], $row["12_inhalt"]); } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} estab_list_attachment_badge ($row ["12_anhang"] ?? null); echo "</td>\n";           echo "</tr>";
          }
          echo "</tbody></table></div>";
        } else {// if $result != ""
          echo "<p class=\"estab-message-list-empty\">".
               "Zur Zeit keine Meldung im Ausgang</p>";
        }
      break;
      case "Stab_lesen":  // ******  S T A B    l e s e n *****
        if (debug) {echo "<b>file:liste.php:749 fkt:createlist - switch(listenart) -- case (Stab_lesen) ></b><br>";}
        /*
          Hole die Liste der gelesenen und der erledigten Nachrichten
        */
        $result = $this->get_list ("global");
        $this->darstellungs_art ( $this->listenart );
        if (!is_array ($this->operationalIdentity)) {
          throw new EstabReadPermissionException (
            "Die wirksame Stabsfunktion ist nicht verfügbar."
          );
        }
        $dbschongelesen = list_of_readed_msg ($this->operationalIdentity) ;
        $dbschonerledigt = list_of_done_msg ($this->operationalIdentity) ;
        $this->kategoliste ();
        $this->listen_navi () ;  //Navigationsbutton
        /*
         * Die Liste "Stab lesen" kommt aus dem Tabellenbauteil
         * (app/tabelle.php), in dessen zweiter Betriebsart: Gesiebt,
         * sortiert und geblaettert wird weiter hier -- ueber die
         * Filterleiste und die Sitzung --, das Bauteil stellt dar.
         *
         * Der Inhalt jeder Zelle bleibt Zeile fuer Zeile, wie er war. Er
         * wird zwischengespeichert und danach zerlegt: Die Zellen entstehen
         * in Verzweigungen -- dieselbe Spalte wird an vier Stellen
         * ausgegeben, je nachdem ob gelesen, erledigt, dringend oder nichts
         * davon --, und aus dem Quelltext ist nicht ablesbar, welches <td>
         * zu welcher Spalte gehoert. Aus dem Ergebnis schon.
         */
        if  ($result != "") {
          // Breite und Klammerung je Spalte. Die Angaben stammen aus einer
          // Messung im Browser: Mit gleich verteilten Breiten ueberlagerte
          // der Transportstand die Vorrangstufe, und der Nachrichtentext
          // machte Zeilen von 450 Bildpunkten Hoehe.
          $stabSpalten = array (
            array ("Kenntnis", 8, false), array ("Erledigt", 8, false),
            array ("Transport", 10, false), array ("Vorrang", 8, false),
            array ("E/A", 4, false), array ("TBB-Nachweis", 10, false),
            array ("Von", 9, true), array ("An", 9, true),
            array ("Abfasszeit", 9, false), array ("Inhalt", 25, true),
          );
          $stabZeilen = array ();
          foreach ($result as $row){
             $hilf = $this->explodereceiver ( $row ["16_empf"] );
             $receivercolor = $hilf [
               (string) $this->operationalIdentity ["funktion"]
             ] ?? ""; // Empfänger dieser wirksamen Funktion
             $receiverbackground = estab_recipient_copy_background (
               $receivercolor,
               $cfg ["lbg"],
               $cfg ["lbg"] ["dflt"]
             );
             // Die Durchschriftenfarben sind helle Pastelltoene und der
             // Standardwert ist Weiss. Eine feste weisse Schrift machte jede
             // Zeile ohne eigene Durchschrift unsichtbar.
             $receiverink = estab_recipient_copy_ink (
               $receivercolor,
               $cfg ["lbg"],
               $cfg ["lbg"] ["dflt"]
             );
             ob_start ();
             // Liegt eine Vorrangstufe vor!!!
             $vorrang = estab_message_priority_requires_attention (
               $row["09_vorrangstufe"]
             );
             // 1. Spalte schon gelesen?
             $schongelesen = false;
             if ($dbschongelesen != "") {
               if ( in_array ( $row["00_lfd"], $dbschongelesen ) ) {
                 $schongelesen = true;
               }
             }

             if ( $vorrang ){
               if ( $schongelesen ){
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "unset", "gesetzt", "gelesen -- als ungelesen kennzeichnen");
                 echo "</td>\n";
               } else {
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "set", "offen-vorrang", "ungelesen, mit Vorrang -- als gelesen kennzeichnen");
                 echo "</td>\n";
               }
             } else {
               if ( $schongelesen ){  // ==> wurde schon gelesen
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "unset", "gesetzt", "gelesen -- als ungelesen kennzeichnen");
                 echo "</td>\n";
               } else {
                 echo "<td align=\"center\">";
                 estab_list_state_action ("gelesen", $row ["00_lfd"], "set", "offen", "ungelesen -- als gelesen kennzeichnen");
                 echo "</td>\n";
               }
             }

             $schonerledigt = false;

             if ($dbschonerledigt != "") {
               if ( in_array ( $row["00_lfd"], $dbschonerledigt ) ) {
                 $schonerledigt = true;
               }
             }


             if ( $schonerledigt ){  // ==> wurde schon erledigt
                 echo "<td style=\"text-align: center; vertical-align: middle;\">";
                 estab_list_state_action ("erledigt", $row ["00_lfd"], "unset", "gesetzt", "erledigt -- als offen kennzeichnen");
                 echo "</td>\n";
             } else {
                 echo "<td style=\"text-align: center; vertical-align: middle;\">";
                 estab_list_state_action ("erledigt", $row ["00_lfd"], "set", "erledigt-offen", "nicht erledigt -- als erledigt kennzeichnen");
                 echo "</td>\n";
             }

               // Status für ausgehende Nachrichten
             echo "<td align=\"center\">";
             if ( ( $row["04_richtung"] == "A") ){
               switch ( $row["x00_status"] ) {
                 case 1:
                   estab_list_transport_badge ("wartet", "bei LdF");
                 break;
                 case 2: // liegt vor dem Fernmelder
                   estab_list_transport_badge ("wartet", "beim Fernmelder");
                 break;
                 case 4: // liegt vor dem Sichter ==> gelb
                   estab_list_transport_badge ("sichter", "beim Sichter");
                 break;
                 case 8: // fertig == gruen
                   estab_list_transport_badge ("fertig", "abgeschlossen");
                 break;
                 default:
                   echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
                 break;
               }
             } else { // ist ein Eingang, damit eine NULL nummer
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             echo "</td>\n";

             echo "<td>";
              // Vorrangstufe
             estab_list_detail_action (
               "stab",
               "meldung",
               $row["00_lfd"],
               estab_message_priority_label ($row["09_vorrangstufe"])
             );
             echo "</td>\n";
              // Eingang / Ausgang
             echo "<td>"; if (($row["04_richtung"] != "")) { estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["04_richtung"]); } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
              // Einsatzlokaler Nachrichtennachweis im TTB
             echo "<td>";
             estab_list_detail_action (
               "stab",
               "meldung",
               $row["00_lfd"],
               estab_message_list_tbb_evidence_label ($row)
             );
             echo "</td>\n";
              // Muss der Absender oder die Absendende Einheit unter von / an
             if ($row["04_richtung"] == "A" ) { // von = 14_funktion an=10_anschrift
                // Ausgang VON
               echo "<td>";
               if (($row["14_funktion"] != "")) {
                 estab_list_detail_action (
                   "stab",
                   "meldung",
                   $row["00_lfd"],
                   estab_function_display_name ((string) $row["14_funktion"])
                 );
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";}
               echo "</td>\n";

                // Ausgang AN
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["10_anschrift"]);
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";}
               echo "</td>\n";

             }

             if ($row["04_richtung"] == "E" ) {  // von = 13_abseinheit/14_funktion an=10_anschrift
               echo "<td>";
               if ( ($row["13_abseinheit"] != "") ) {
                 estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["13_abseinheit"], true);
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";


               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["10_anschrift"], true);
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             if (($row["12_abfzeit"] != "")) {
               $arr    = convdatetimeto ($row["12_abfzeit"]);
               $abzeit = $arr ["stak"];
               estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $abzeit, true);
             } else {
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             echo "</td>\n";

             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               estab_list_detail_action ("stab", "meldung", $row["00_lfd"], $row["12_inhalt"], true);
             } else {
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             estab_list_attachment_badge ($row ["12_anhang"] ?? null);
             echo "</td>\n";
             $stabRoh = (string) ob_get_clean ();
             $stabZellen = estab_tabelle_zeile_zerlegen (
               $stabRoh,
               count ($stabSpalten)
             );
             $stabZeile = array (
               "id" => (string) ($row ["00_lfd"] ?? ""),
               "grund" => $receiverbackground,
               "tinte" => $receiverink,
             );
             foreach ($stabZellen as $stabNummer => $stabInhalt) {
               $stabZeile ["z" . $stabNummer] = $stabInhalt;
             }
             $stabZeilen[] = $stabZeile;
          }  // foreach $result

          $stabAufbau = array ();
          foreach ($stabSpalten as $stabNummer => $stabAngabe) {
            $stabAufbau[] = array (
              "schluessel" => "id",
              "kopf" => $stabAngabe [0],
              "breite" => $stabAngabe [1],
              "sortierbar" => false,
              "suchbar" => false,
              "art" => "text",
              "klammern" => $stabAngabe [2],
              "zelle" => static function (array $z) use ($stabNummer): string {
                return $z ["z" . $stabNummer];
              },
            );
          }
          echo estab_tabelle_markup (array (
            "id" => "stab-lesen",
            "beschriftung" => "Meldungen des aktiven Einsatzes mit "
              . "Kenntnis-, Erledigt- und Transportstand",
            "baender" => false,
            "mindestbreite" => "60rem",
            "fremd" => array (
              "treffer" => count ($stabZeilen),
              "gesamt" => (int) ($_SESSION ["filter_rescount"] ?? count ($stabZeilen)),
            ),
            // Die Durchschriftenfarbe der eigenen Funktion. Sie kommt aus der
            // Einrichtung, nicht aus dem Stylesheet, und sagt auf einen Blick,
            // ob diese Meldung einen selbst betrifft.
            "zeilenmarke" => static function (array $z): string {
              return "style=\"background: " . estab_message_html ($z ["grund"])
                . "; color:" . estab_message_html ($z ["tinte"])
                . "; font-weight:bold;\"";
            },
            "spalten" => $stabAufbau,
            "zeilen" => $stabZeilen,
            "leer" => "Keine Meldung entspricht den gesetzten Filtern.",
          ));
        }
        $this->listen_navi () ;  //Navigationsbutton
      break;

      case "KORREKTUR":
        $incidentId = $this->required_incident_id ();
        $messageConnection = estab_message_connect ($conf_4f_db);
        $readTransactionActive = false;
        try {
          if (!$messageConnection->begin_transaction ()) {
            throw new RuntimeException (
              "Lesetransaktion konnte nicht begonnen werden."
            );
          }
          $readTransactionActive = true;
          $identity = estab_read_session_identity ($_SESSION);
          if ($identity === null) {
            throw new EstabReadPermissionException ("Anmeldung erforderlich.");
          }
          $scope = estab_read_require_operational_scope (
            $messageConnection,
            $identity,
            true
          );
          $identity = $scope ["identity"];
          estab_permission_context_set_from_incident ($scope ["incident"]);
          if (
            (int) $scope ["incident"] ["active_einsatz_id"] !== $incidentId
          ) {
            throw new EstabReadPermissionException (
              "Die Korrekturwarteschlange gehört nicht zum aktiven Einsatz."
            );
          }
          $result = estab_message_query_rows (
            $messageConnection,
            "SELECT m.*,".
              estab_message_list_tbb_number_select_sql ("m").
              " FROM ".estab_message_table ($conf_4f_tbl ["nachrichten"])." AS m".
              " WHERE m.`einsatz_id` = ?".
              " AND m.`x00_status` = 10".
              " AND m.`04_richtung` = 'A'".
              " AND m.`14_zeichen` <> ''".
              " AND m.`14_funktion` <> ''".
              " AND m.`02_zeit` IS NULL AND m.`02_zeichen` = ''".
              " AND m.`03_datum` IS NULL AND m.`03_zeichen` = ''".
              " AND m.`15_quitdatum` IS NOT NULL".
              " AND m.`15_quitzeichen` <> ''".
              " AND m.`x01_abschluss` = 'f'".
              " ORDER BY ".
              estab_message_priority_order_sql ("m.`09_vorrangstufe`").
              " DESC, m.`12_abfzeit` ASC, m.`00_lfd` ASC",
            array ($incidentId)
          );
          $result = array_values (array_filter (
            $result,
            static fn (array $row): bool => estab_message_object_allowed (
              $identity,
              "staff-correction",
              $row,
              true
            )
          ));
          if (!$messageConnection->commit ()) {
            throw new RuntimeException (
              "Lesetransaktion konnte nicht abgeschlossen werden."
            );
          }
          $readTransactionActive = false;
        } finally {
          if ($readTransactionActive) {
            $messageConnection->rollback ();
          }
          estab_auth_close ($messageConnection);
        }

        echo "<section class=\"estab-tool-panel\" data-estab-correction-queue ".
             "aria-labelledby=\"estab-correction-queue-title\">";
        echo "<header class=\"estab-tool-panel-heading\">";
        echo "<h2 id=\"estab-correction-queue-title\">Zurückgewiesene Meldungen</h2>";
        echo "<p>Diese Meldungen warten auf Überarbeitung und wurden an eine ".
             "deiner aktuell wirksamen Stabsfunktionen zurückgegeben.</p>";
        echo "</header>";
        if ($result === array ()) {
          echo "<div class=\"estab-message-list-empty\">".
               "<h3>Keine Korrekturen offen</h3>".
               "<p>Aktuell wurde keine Ausgangsmeldung zur Überarbeitung zurückgegeben.</p>".
               "</div>";
        } else {
          estab_message_list_render_table (
            $result,
            static function (array $row): void {
              $recordId = estab_message_positive_id ($row ["00_lfd"] ?? null);
              estab_list_detail_action (
                "stab",
                "korrektur",
                $recordId,
                "Korrektur übernehmen",
                false,
                true
              );
            }
          );
        }
        echo "</section>";
      break;

      case "Stab_sichten":   /*********** S t a b   s i c h t e n ************/
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>fkt:createlist - switch(listenart) -- case (Stab_sichten) </b><br>";}
			$incidentId = $this->required_incident_id ();

			/*
			 * Status 4 ist die verbindliche Sichter-Warteschlange:
			 * Eingang nach der LdF-Disposition, Ausgang vor LdF und A/W.
			 * Ausgehende Vordrucke dürfen nicht mehr per Konfiguration an
			 * dieser formalen Prüfung vorbeigeführt werden.
			 */
			$WHERE_inout = "WHERE `einsatz_id` = ? AND `x00_status` = 4 AND ( ( `15_quitdatum` IS NULL ) AND ( `15_quitzeichen` = \"\" ) ) AND ( `04_richtung` IN (\"E\", \"A\") )";

//order by `09_vorrangstufe` DESC, `12_abfzeit`; 

			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### Ein- und Ausgänge sichten<br>"; }
			$query = "SELECT `00_lfd`,`07_durchspruch`,
                         `08_befhinweis`,
                         `08_befhinwausw`,
                         `09_vorrangstufe`,
                         `10_anschrift`,
                         `12_abfzeit`,
                         `12_anhang`,
                         `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."` ".$WHERE_inout."
                       order by ".
                       estab_message_priority_order_sql ("`09_vorrangstufe`").
                       " DESC, `12_abfzeit`; ";
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>Stab_sichten:".$query." </b><br>";}
        $messageConnection = estab_message_connect ($conf_4f_db);
        try {
          $result = estab_message_query_rows (
            $messageConnection,
            $query,
            array ($incidentId)
          );
        } finally {
          estab_auth_close ($messageConnection);
        }
        $result = $result === array () ? "" : $result;
        if ($result != "" ){
          echo "<table class=\"estab-tool-table estab-list-table\">\n<tbody>\n";
          echo "<tr class=\"estab-list-head\">\n";
          echo "<td>ZEIT</td>\n";
          echo "<td>Vorst</td>\n";
          echo "<td>Anschrift</td>\n";
          echo "<td>Inhalt / Text</td>\n";
          echo "</tr>";
          foreach ($result as $row){
           $priorityStyle = estab_message_priority_requires_attention (
             $row["09_vorrangstufe"]
           )
               ? " style=\"background-color: rgb(220,0,0); color:#FFFFFF; font-weight:bold;\""
               : "";
           echo "<tr".$priorityStyle.">\n";
           $abfzeit = convdatetimeto ($row["12_abfzeit"]);
           echo "<td>"; if (($row["12_abfzeit"] != "")) { estab_list_detail_action ("sichter", "meldung", $row["00_lfd"], $abfzeit["stak"]); } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
           echo "<td>";
           estab_list_detail_action (
             "sichter",
             "meldung",
             $row["00_lfd"],
             estab_message_priority_label ($row["09_vorrangstufe"])
           );
           echo "</td>\n";
           echo "<td>"; if (($row["10_anschrift"] != "")) { estab_list_detail_action ("sichter", "meldung", $row["00_lfd"], $row["10_anschrift"]); } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
           echo "<td align=\"left\">"; if (($row["12_inhalt"] != "")) { estab_list_detail_action ("sichter", "meldung", $row["00_lfd"], $row["12_inhalt"]); } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} estab_list_attachment_badge ($row ["12_anhang"] ?? null); echo "</td>\n";
           echo "</tr>";
          } // foreach result row
          echo "</tbody></table>";
        } else {// if isset $result
          echo "<big><big><big>Zur Zeit keine Meldung zu sichten</big></big></big>";
        }
		
		
      break;

      /*************************************************************************\
               SSSSS III  AAA  DDDD  M   M III N   N
               S      I  A   A D   D MM MM  I  NN  N
               SSSSS  I  AAAAA D   D M M M  I  N N N
                   S  I  A   A D   D M   M  I  N  NN
               SSSSS III A   A DDDD  M   M III N   N
      \*************************************************************************/
	      case "SIADMIN":  // ***************  SICHTER ADMINISTRATOR  *********************
	      case "FMADMIN":
		    if (debug) {echo "<b>file:liste.php fkt:createlist - moderne zweite Sichtung</b><br>";}
        $result = $this->get_list ($this->listenart);
        $adminMessageRoute = $this->listenart === "FMADMIN"
          ? "FM-Adminmeldung"
          : "SI-Adminmeldung";
        $hiddenRoute = $this->listenart === "FMADMIN"
          ? array ("fm_admin_x" => "1")
          : array ("si_admin_x" => "1");
        $domPrefix = $this->listenart === "FMADMIN"
          ? "aw-second-sighting"
          : "si-second-sighting";
        $renderOptions = array (
          "action" => $conf_4f ["MainURL"],
          "method" => "post",
          "target" => "mainframe",
          "dom_prefix" => $domPrefix,
          "hidden" => $hiddenRoute,
          "csrf_html" => estab_csrf_field (),
          "state_filters" => $this->stateFilterAvailable === true,
        );

        echo "<section class=\"estab-tool-panel\" data-estab-message-list ".
             "aria-labelledby=\"".$domPrefix."-title\">";
        echo "<header class=\"estab-tool-panel-heading\">";
        echo "<h2 id=\"".$domPrefix."-title\">Nachrichtenvordrucke</h2>";
        echo "<p>Suche und Filter werden miteinander kombiniert. ".
             "Ein Klick auf „Vordruck öffnen“ zeigt die vollständige Nachricht.</p>";
        echo "</header>";
        estab_message_list_render_controls (
          $this->filters,
          $this->message_list_recipient_functions (),
          $renderOptions
        );
        estab_message_list_render_resultbar (
          $this->filters,
          $this->pageWindow
        );
        if ($result === array ()) {
          estab_message_list_render_empty ($this->filters);
        } else {
          estab_message_list_render_table (
            $result,
            static function (array $row) use ($adminMessageRoute): void {
              $recordId = estab_message_positive_id ($row ["00_lfd"] ?? null);
              $label = "Vordruck ".
                estab_message_list_direction_label (
                  $row ["04_richtung"] ?? ""
                )." – ".estab_message_list_tbb_evidence_label ($row).
                " öffnen";
              estab_list_detail_action (
                "fm",
                $adminMessageRoute,
                $recordId,
                $label,
                false,
                true
              );
            }
          );
        }
        estab_message_list_render_pager (
          $this->filters,
          $this->pageWindow,
          $renderOptions
        );
        echo "</section>";
		
		
/******************************************************************************************************************************************
        $this->darstellungs_art ( $this->listenart );
		$this->listen_navi () ;  //Navigationsbutton
        include ("../4fcfg/fkt_rolle.inc.php");
        $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],
                             $conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"] );
        $query = "SELECT `00_lfd`,
                         `04_richtung`,
                         `04_nummer`,
                         `09_vorrangstufe`,
                         `10_anschrift`,
                         `12_abfzeit`,
                         `13_abseinheit`,
                         `12_inhalt`,
                         `16_empf`
                   FROM `".$conf_4f_tbl ["nachrichten"]."`
                               WHERE 1 order by 04_nummer DESC, 09_vorrangstufe DESC ; ";          //

        $result = $dbaccess->query_table ($query);
        if  ($result != ""){
          echo "<table class=\"estab-tool-table estab-list-table\">\n<tbody>\n";
          echo "<tr class=\"estab-list-head\">\n";
          echo "<td>Vorst</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>Nw-Nr.</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          // Funktionen und Farben
          for ( $i=1; $i<= count ($conf_empf); $i++ ) {
            if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
              echo "<td>";
              echo $conf_empf [$i]["fkt"];
              echo "</td>\n";
            }
          }
          echo "<td>Inhalt</td>\n";
          echo "</tr>";

          foreach ($result as $row){
             // VORRANGSTUFE
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ){
               echo "<tr class=\"estab-list-head\">\n";
             }
             echo "<td>";
             if ( ( $row["09_vorrangstufe"] != "") and ( $row["09_vorrangstufe"] != "eee" ) ) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["09_vorrangstufe"]."</a>\n" ;
             } else {
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             echo "</td>\n";

             // RICHTUNG Eingang / Ausgang
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_richtung"]."</a>\n";
             } else {
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             echo "</td>\n";

             // N a c h w e i s n u m m e r
             echo "<td>";
             if (($row["04_richtung"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["04_nummer"]."</a>\n";
             } else {
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             echo "</td>\n";

             if ($row["04_richtung"] == "A" ) {
               echo "<td>";
               if (($row["10_anschrift"] != "")) {
                 echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["10_anschrift"]."</a>\n";
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
             } else {
               echo "<td>";

             // Absender / Einheit / Stelle / ...
             if (($row["13_abseinheit"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$row["13_abseinheit"]."</a>\n";
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
             }
             echo "<td>";
             // Abfassungs Z E I T
             if (($row["12_abfzeit"] != "")) {
               $abfzeit = convdatetimeto ($row["12_abfzeit"]);
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".$row["00_lfd"]."\" target=\"_self\">".$abfzeit['stak']."</a>\n";
             } else {
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             echo "</td>\n";

             // Funktionen und Farben
             $empfcolor = extraiereempfaenger ( $row ["16_empf"] ) ;
             for ( $i=1; $i<= count ($conf_empf); $i++ ) {
               if ( ( $conf_empf [$i]["fkt"] != "Si" ) and ( $conf_empf [$i]["fkt"] != "A/W" ) ) {
                 $recipientFunction = $conf_empf [$i]['fkt'];
                 echo estab_recipient_copy_cell_html (
                   $empfcolor [$recipientFunction] ?? "",
                   $cfg ["vbg"],
                   "<span class=\"estab-message-list-clamp--empty\">–</span>"
                 );
               }
             }

             // I N H A L T !
             echo "<td align=\"left\">";
             if (($row["12_inhalt"] != "")) {
               echo "<a href=\"mainindex.php?fm=SI-Adminmeldung&00_lfd=".
                        $row["00_lfd"]."\" target=\"_self\">".
                        substr($row["12_inhalt"], 0, $conf_4f_liste ["inhalt"])." ..."."</a>\n";
             } else {
               echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
             }
             echo "</td>\n";
             echo "</tr>";
          }
        }
        echo "</tbody></table>";
************************************************************************************************************************************/

      break; // case SIADMIN

      case "FmNwE":  // *****  F M N W E ingang ******
	    if (debug) {echo "<b>file:liste.php:714 fkt:createlist - switch(listenart) -- case (FmNwE) ></b><br>";}
        $incidentId = $this->required_incident_id ();
        $trackingFilters = estab_list_tracking_filters ("nwe_");
        $trackingScope = " FROM `".$conf_4f_tbl ["nachrichten"]."` AS m".
                  " WHERE m.`einsatz_id` = ?".
                  " AND m.`04_richtung` = \"E\"";
        $query = "SELECT m.`00_lfd`,m.`01_medium`,m.`09_vorrangstufe`,".
                  "m.`04_richtung`,".
                  estab_message_list_tbb_number_select_sql ("m").",".
                  "m.`10_anschrift`,m.`12_abfzeit`,m.`12_inhalt`,".
                  "m.`12_anhang`,".
                  "m.`13_abseinheit`,m.`x01_abschluss`".
                  $trackingScope.
                  " ORDER BY COALESCE(".
                  estab_message_list_tbb_number_sql ("m").
                  ", 4294967296) ASC, m.`00_lfd` ASC LIMIT ? OFFSET ?";
        $messageConnection = estab_message_connect ($conf_4f_db);
        try {
          $trackingWindow = estab_message_list_page_window (
            estab_message_query_int (
              $messageConnection,
              "SELECT COUNT(*)".$trackingScope,
              array ($incidentId)
            ),
            $trackingFilters
          );
          $trackingFilters ["page"] = $trackingWindow ["page"];
          $result = estab_message_query_rows (
            $messageConnection,
            $query,
            array (
              $incidentId,
              (int) $trackingWindow ["page_size"],
              (int) $trackingWindow ["offset"]
            )
          );
        } finally {
          estab_auth_close ($messageConnection);
        }
        $result = $result === array () ? "" : $result;
        echo "<p align=\"center\"><big><big><big><b>Nachweisung Eingang</b></big></big></big></p>";
        estab_message_list_render_resultbar ($trackingFilters, $trackingWindow);
        if ( $result != "" ){
          echo "<table class=\"estab-tool-table estab-list-table\">\n<tbody>\n";
          echo "<tr class=\"estab-list-head\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>TBB-Nachweis</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Eingangsmedium</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ( $result != "" ) {
            foreach ($result as $row){
               echo "<tr>\n";
               echo "<td>";
               echo "<a>".estab_message_html (
                 estab_message_priority_label ($row["09_vorrangstufe"])
               )."</a>\n" ;
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".estab_message_html ($row["04_richtung"])."</a>\n";  } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               echo "<td><a>".estab_message_html (
                 estab_message_list_tbb_evidence_label ($row)
               )."</a></td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".estab_message_html ($row["10_anschrift"])."</a>\n"; } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".estab_message_html ($row["13_abseinheit"])."</a>\n";
                 } else {
                   echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
               echo "<td>";
               $incomingMedium = estab_message_medium_text ($row["01_medium"] ?? "");
               if ($incomingMedium !== "") {
                 echo "<a>".estab_message_html ($incomingMedium)."</a>\n";
               } else {
                 echo "Nicht dokumentiert";
               }
               echo "</td>\n";
               echo "<td align=\"left\">".estab_message_list_clamped_text ($row["12_inhalt"] ?? ""); estab_list_attachment_badge ($row ["12_anhang"] ?? null); echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          } // if 2. result == ""
          echo "</tbody></table>";
          estab_list_tracking_pager ($trackingFilters, $trackingWindow, "nwe_");
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

      case "FmNwA":  // *****  F M N W A usgang ******
        if (debug) {echo "<b>file:liste.php:714 fkt:createlist - switch(listenart) -- case (FmNwA) ></b><br>";}
        $incidentId = $this->required_incident_id ();
        $trackingFilters = estab_list_tracking_filters ("nwa_");
        $trackingScope = " FROM `".$conf_4f_tbl ["nachrichten"]."` AS m".
                  " WHERE m.`einsatz_id` = ?".
                  " AND m.`04_richtung` = \"A\"";
        $query = "SELECT m.`00_lfd`,m.`01_medium`,m.`03_datum`,".
                  "m.`06_befweg`,m.`09_vorrangstufe`,".
                  "m.`04_richtung`,".
                  estab_message_list_tbb_number_select_sql ("m").",".
                  "m.`10_anschrift`,m.`12_abfzeit`,m.`12_inhalt`,".
                  "m.`12_anhang`,".
                  "m.`13_abseinheit`,m.`x01_abschluss`".
                  $trackingScope.
                  " ORDER BY COALESCE(".
                  estab_message_list_tbb_number_sql ("m").
                  ", 4294967296) ASC, m.`00_lfd` ASC LIMIT ? OFFSET ?";
        $messageConnection = estab_message_connect ($conf_4f_db);
        try {
          $trackingWindow = estab_message_list_page_window (
            estab_message_query_int (
              $messageConnection,
              "SELECT COUNT(*)".$trackingScope,
              array ($incidentId)
            ),
            $trackingFilters
          );
          $trackingFilters ["page"] = $trackingWindow ["page"];
          $result = estab_message_query_rows (
            $messageConnection,
            $query,
            array (
              $incidentId,
              (int) $trackingWindow ["page_size"],
              (int) $trackingWindow ["offset"]
            )
          );
        } finally {
          estab_auth_close ($messageConnection);
        }
        $result = $result === array () ? "" : $result;
        echo "<p align=\"center\"><big><big><big><b>Nachweisung Ausgang</b></big></big></big></p>";
        estab_message_list_render_resultbar ($trackingFilters, $trackingWindow);
        if ( $result != "" ){
          echo "<table class=\"estab-tool-table estab-list-table\">\n<tbody>\n";
          echo "<tr class=\"estab-list-head\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>TBB-Nachweis</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Abfasszeit</td>\n";
          echo "<td>Beförderungsweg</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ($result != "") {
            foreach ($result as $row){
               echo "<tr>\n";
               echo "<td>";
               echo "<a>".estab_message_html (
                 estab_message_priority_label ($row["09_vorrangstufe"])
               )."</a>\n" ;
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".estab_message_html ($row["04_richtung"])."</a>\n";  } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               echo "<td><a>".estab_message_html (
                 estab_message_list_tbb_evidence_label ($row)
               )."</a></td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".estab_message_html ($row["10_anschrift"])."</a>\n"; } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".estab_message_html ($row["13_abseinheit"])."</a>\n";
                 } else {
                   echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               }
               echo "<td>";
               if (($row["12_abfzeit"] != "")) {
                 $arr    = convdatetimeto ($row["12_abfzeit"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
               echo "<td>";
               if (estab_datetime_is_unset ($row["03_datum"] ?? null)) {
                 echo "Noch nicht befördert";
               } else {
                 $transportPath = estab_message_transport_text (
                   $row["01_medium"] ?? "",
                   $row["06_befweg"] ?? ""
                 );
                 echo $transportPath !== ""
                   ? estab_message_html ($transportPath)
                   : "Nicht dokumentiert";
               }
               echo "</td>\n";
               echo "<td align=\"left\">".estab_message_list_clamped_text ($row["12_inhalt"] ?? ""); estab_list_attachment_badge ($row ["12_anhang"] ?? null); echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          }
          echo "</tbody></table>";
          estab_list_tracking_pager ($trackingFilters, $trackingWindow, "nwa_");
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;

      case "FmNw":  // *****  F M N W  ******
        if (debug) {echo "<b>file:liste.php:714 fkt:createlist - switch(listenart) -- case (FmNw) ></b><br>";}
        $incidentId = $this->required_incident_id ();
        $trackingFilters = estab_list_tracking_filters ("nw_");
        $messageConnection = estab_message_connect ($conf_4f_db);
        try {
          $trackingData = estab_list_combined_tracking_data (
            $messageConnection,
            $incidentId,
            (string) $conf_4f_tbl ["nachrichten"],
            $trackingFilters
          );
        } finally {
          estab_auth_close ($messageConnection);
        }
        $incidentUi = $trackingData ["incident"];
        $trackingRows = $trackingData ["rows"];
        $trackingWindow = $trackingData ["page_window"];
        $trackingFilters ["page"] = $trackingWindow ["page"];
        $result = $trackingRows === array () ? "" : $trackingRows;
        $commandPostName = estab_incident_command_post_name ($incidentUi);
        echo "<p align=\"center\"><big><big><big><b>Führungsstelle ".
             estab_message_html ($commandPostName)." – Einsatz ".
             estab_message_html ($incidentUi ["kennung"] ?? "").
             "</big><br>Nachweisung Eingang / Ausgang</b></big></big></p>";
        estab_message_list_render_resultbar ($trackingFilters, $trackingWindow);
        if ( $result != "" ){
          echo "<table class=\"estab-tool-table estab-list-table\">\n<tbody>\n";
          echo "<tr class=\"estab-list-head\">\n";
          echo "<td>Vorrang</td>\n";
          echo "<td>E/A</td>\n";
          echo "<td>TBB-Nachweis</td>\n";
          echo "<td>Von/An</td>";
          echo "<td>Aufnahme</td>\n";
//          echo "<td>Aufn.Z</td>\n";
          echo "<td>Annahme</td>\n";
          echo "<td>Beförderzeit</td>\n";
          echo "<td>Übermittlungsweg</td>\n";
//          echo "<td>Verfasser</td>\n";
          echo "<td>Inhalt</td>\n";
          echo "</tr>";
          if  ($result != "") {
            foreach ($result as $row){
               echo "<tr>\n";
               echo "<td>";
               echo "<a>".estab_message_html (
                 estab_message_priority_label ($row["09_vorrangstufe"])
               )."</a>\n" ;
               echo "</td>\n";
               echo "<td>"; if (($row["04_richtung"] != "")) { echo "<a>".estab_message_html ($row["04_richtung"])."</a>\n";  } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               echo "<td><a>".estab_message_html (
                 estab_message_list_tbb_evidence_label ($row)
               )."</a></td>\n";
               if ($row["04_richtung"] == "A" ) {
                 echo "<td>";
                 if (($row["10_anschrift"] != "")) {
                   echo "<a>".estab_message_html ($row["10_anschrift"])."</a>\n"; } else { echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               } else {
                 echo "<td>";
                 if (($row["13_abseinheit"] != "")) {
                   echo "<a>".estab_message_html ($row["13_abseinheit"])."</a>\n";
                 } else {
                   echo "<span class=\"estab-message-list-clamp--empty\">–</span>";} echo "</td>\n";
               }


               echo "<td>";
               if (!estab_datetime_is_unset ($row["01_datum"])) {
                 $arr    = convdatetimeto ($row["01_datum"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>";
// Aufn. Zeichen
                 // Aufn. Zeichen
/*              echo "<td>";
               if (($row["01_zeichen"] != "")) {
                 echo "<a>".$row["01_zeichen"]."</a>\n"; }
               else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
*/
/*
               echo "<td>";
               if (($row["01_zeichen"] != "")) {
                 echo "<a>".$row["01_zeichen"]."</a>\n"; }
               else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
*/
               echo "<td>";
               if (!estab_datetime_is_unset ($row["02_zeit"])) {
                 $arr    = convdatetimeto ($row["02_zeit"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>";

               echo "<td>";
               if (!estab_datetime_is_unset ($row["03_datum"])) {
                 $arr    = convdatetimeto ($row["03_datum"]);
                 $abzeit = $arr ['stak'];
                 echo "<a>".$abzeit."</a>\n";
               } else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
               echo "<td>";
               if (($row["04_richtung"] ?? "") === "E") {
                 $trackingPath = estab_message_medium_text ($row["01_medium"] ?? "");
                 echo $trackingPath !== ""
                   ? estab_message_html ($trackingPath)
                   : "Nicht dokumentiert";
               } elseif (estab_datetime_is_unset ($row["03_datum"] ?? null)) {
                 echo "Noch nicht befördert";
               } else {
                 $trackingPath = estab_message_transport_text (
                   $row["01_medium"] ?? "",
                   $row["06_befweg"] ?? ""
                 );
                 echo $trackingPath !== ""
                   ? estab_message_html ($trackingPath)
                   : "Nicht dokumentiert";
               }
               echo "</td>\n";
                 // Verfasser
/*               echo "<td>";
               if (($row["14_zeichen"] != "")) {
                 echo "<a>".$row["14_zeichen"]."</a>\n"; }
               else {
                 echo "<span class=\"estab-message-list-clamp--empty\">–</span>";
               }
               echo "</td>\n";
*/
               echo "<td align=\"left\">".estab_message_list_clamped_text ($row["12_inhalt"] ?? ""); estab_list_attachment_badge ($row ["12_anhang"] ?? null); echo "</td>\n";
               echo "</tr>";
            }  // foreach $result
          }
          echo "</tbody></table>";
          estab_list_tracking_pager ($trackingFilters, $trackingWindow, "nw_");
        } else { // Result ist leer
          echo "<big><big><big>Keine Daten vorhanden!</big></big></big>";
        }
      break;
    } // switch
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - fkt:createlist - switch_ENDE(listenart) ></b><br>";}  
  }
} // class

?>
