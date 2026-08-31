<?php
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Liste</big></big><br>";  }
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/message_repository.php";
require_once __DIR__ . "/../app/message_priority.php";
require_once __DIR__ . "/../app/message_transport.php";
require_once __DIR__ . "/../app/tabelle.php";
require_once __DIR__ . "/../app/liste_spalten.php";
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
    ." type=\"submit\" form=\"estab-list-filter\""
    ." name=\"".estab_list_handlungsname ($name)."\" value=\"1\""
    ." aria-pressed=\"".($active ? "true" : "false")."\">"
    .estab_message_html ((string) $label)."</button>";
}


/*
 * Hier stand estab_list_page_window() -- der Seitenrahmen der alten Liste.
 *
 * Er las flt_navi, filter_start und filter_anzahl aus der Sitzung und
 * schnitt daraus eine Seite. Seit "Stab lesen" vollstaendig laedt und das
 * Bauteil blaettert, ruft ihn niemand mehr; die Knoepfe und die
 * Seitengroessenmarken, die ihn fuellten, sind mit der alten Filterleiste
 * gegangen. Die Uebungsleitung hat ihren eigenen Rahmen und ist davon
 * unberuehrt.
 */


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
  /****************************************************************************\
  | Die Schalter der eigenen Filterleiste von "Stab lesen".
  |
  | Ein Aufrufer, eine Listenart: createlist() ruft diese Funktion nur im
  | Zweig "Stab lesen" und puffert ihre Ausgabe in das Band des
  | Tabellenbauteils. Die Zweige fuer FMA, LdF, "Stab sichten" und die
  | beiden Administrationssichten standen hier trotzdem noch -- die
  | letzte mit drei eigenen Layouttabellen, einem eigenen Zaehler und
  | einer eigenen Suche, die nie jemand zu Gesicht bekam. Sie sind fort.
  |
  | Geblieben sind die beiden Schalter, die etwas koennen, was das
  | Bauteil nicht kann: Sie entscheiden, *welche* Zeilen die Datenbank
  | ueberhaupt liefert. Gegangen sind Zaehler, Seitengroesse und
  | Suchfeld. Die brachte das Bauteil daneben ein zweites Mal mit -- und
  | seine Angaben stimmten, waehrend die alten nichts mehr beschrieben:
  | Seit "Stab lesen" vollstaendig laedt, blaettert das Bauteil, nicht
  | die Abfrage. Auf dem Bildschirm standen zwei Seitengroessen
  | nebeneinander, die eine mit 5/10/15/20/25, die andere mit 25/50/100.
  |
  | Das Suchfeld war dabei mehr als eine Dopplung: Ein Suchbegriff
  | schaltete die Filter "Unerledigt", "Erledigt" und die Kategorien
  | stillschweigend ab (siehe get_list). Wer suchte, bekam ungefragt
  | einen anderen Ausschnitt, als die Schalter daneben anzeigten.
  |
  | Hier stehen nur die Knoepfe, kein Formular. Das Band liegt im
  | Suchformular des Bauteils, und ein Formular im Formular verwirft der
  | Zerleger des Browsers: Die Knoepfe gehoerten dann zur Suche des
  | Bauteils und schickten einen GET dorthin. Ihr Formular steht deshalb
  | neben der Tabelle, und das form-Attribut verbindet sie damit.
  \****************************************************************************/
  function darstellungs_art (){

    if ($this->listenart !== "Stab_lesen") {
      return;
    }
    $an = $_SESSION ['filter_unerledigt'] != 0;
    echo estab_list_toggle_markup (
      $an ? "filter_unerledigt_aus" : "filter_unerledigt_ein", "Unerledigt", $an
    );
    $an = $_SESSION ['filter_erledigt'] != 0;
    echo estab_list_toggle_markup (
      $an ? "filter_erledigt_aus" : "filter_erledigt_ein", "Erledigt", $an
    );
  }

  /****************************************************************************\
  | Das Formular, zu dem die Schalter gehoeren.
  |
  | Es traegt keinen sichtbaren Inhalt -- nur die wirksame Funktion -- und
  | steht neben der Tabelle statt in ihrem Band. Der Grund steht bei
  | darstellungs_art(): Ein Formular im Formular ueberlebt den Zerleger
  | des Browsers nicht.
  \****************************************************************************/
  function filterleiste_formular (){
    include ("../4fcfg/config.inc.php");
    echo "\n<form id=\"estab-list-filter\""
      ." action=\"".estab_message_html ($conf_4f ["MainURL"])."\""
      ." method=\"POST\" target=\"mainframe\" data-estab-list-filter>";
    echo estab_list_acting_function_field ();
    echo "</form>\n";
  }

  /*
   * Hier stand listen_navi() -- die vier Blaetterknoepfe. Sie hatte seit
   * der Umstellung von "Stab lesen" auf das Tabellenbauteil keinen
   * Aufrufer mehr: Das Bauteil bringt seinen eigenen Blaetterer mit, und
   * zwei Blaetterer nebeneinander sind schlimmer als einer.
   *
   * Die Uebungsleitung (4fueltg/ue_ltg.php) hat ihren eigenen Blaetterer;
   * der ist davon unberuehrt.
   */

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
      // Fuer die Beschriftung des TBB-Nachweises: Eine Gespraechsnotiz
      // bekommt nie eine Nummer.
      "m.`11_gesprnotiz`,".
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

  /**
   * Die Zeilen einer Liste -- immer alle.
   *
   * Es gab hier einmal einen Schalter `$vollstaendig`, der wahlweise eine
   * Seite oder alle Zeilen lieferte. Er war schon nicht mehr wahlweise:
   * Von den beiden Aufrufern lädt der eine vollständig, der andere
   * (Sichter- und Fernmeldeadministration) verlässt die Funktion weiter
   * oben über `get_admin_message_list`. Der Zweig für eine einzelne Seite
   * war unerreichbar.
   *
   * Und er soll es bleiben: Wer das Tabellenbauteil sieben, sortieren und
   * blättern lässt, braucht alle Zeilen. Eine Sortierung über eine Seite
   * ist keine Sortierung, sondern eine Umordnung von fünfzehn zufälligen
   * Zeilen.
   */
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
          /*
           * Gesucht wird im Tabellenbauteil, nicht in der Abfrage.
           *
           * Hier stand eine zweite Suche ueber Nachweis, Anschrift,
           * Abfasszeit, Inhalt und absendende Einheit. Zwei Gruende, sie
           * zu streichen. Erstens schaltete ein Suchbegriff die Filter
           * darunter -- gelesen, erledigt, Kategorien -- stillschweigend
           * ab; wer suchte, bekam ungefragt einen anderen Ausschnitt, als
           * die Schalter daneben anzeigten. Zweitens durchsuchte sie die
           * Abfasszeit in der Datenbankform "2026-08-24 02:15:26",
           * waehrend auf dem Bildschirm "240215" steht: Wer eintippte,
           * was er sah, fand nichts.
           *
           * "Stab lesen" laedt vollstaendig; das Bauteil sucht damit ueber
           * denselben Bestand und kombiniert die Suche mit den Filtern,
           * statt sie abzuschalten.
           */
          if ($displayFilters) {
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
          /*
           * Die Disposition kommt aus dem Tabellenbauteil.
           *
           * Sie fuehrt Eingaenge und Ausgaenge nebeneinander -- beim
           * Disponieren ist beides zu sehen. Trennen koennen muss man sie
           * trotzdem, deshalb traegt die Richtungsspalte einen Filter.
           */
          $dispoZeilen = array ();
          foreach ($result as $row) {
            $abfzeit = convdatetimeto ($row ["12_abfzeit"]);
            $direction = (string) $row ["04_richtung"];
            $address = $direction === "E"
              ? ((string) $row ["13_abseinheit"] !== ""
                ? $row ["13_abseinheit"]
                : "Absender zu übersetzen")
              : $row ["10_anschrift"];
            $dispoZeilen[] = array (
              "lfd" => estab_message_positive_id ($row ["00_lfd"]),
              "richtung" => $direction === "E" ? "Eingang" : "Ausgang",
              "zeit" => estab_liste_zeitwert ($row ["12_abfzeit"] ?? null),
              "zeit_kurz" => (string) ($abfzeit ["stak"] ?? ""),
              "vorrang" => estab_message_priority_label ($row ["09_vorrangstufe"]),
              "rufname" => (string) ($row ["05_gegenstelle"] ?: "noch offen"),
              "gegenstelle" => (string) $address,
              "inhalt" => (string) ($row ["12_inhalt"] ?? ""),
              "anhang" => $row ["12_anhang"] ?? null,
            );
          }
          $dispoSpalten = estab_liste_spalten_disposition ();
          foreach ($dispoSpalten as $i => $dispoSpalte) {
            if ($dispoSpalte ["schluessel"] === "inhalt") {
              $dispoSpalten [$i]["zelle"] = static function (array $z): string {
                ob_start ();
                estab_list_attachment_badge ($z ["anhang"] ?? null);
                $marke = (string) ob_get_clean ();
                return estab_tabelle_zelleninhalt ((string) $z ["inhalt"]).$marke;
              };
            }
          }
          $dispoSpalten[] = array (
            "schluessel" => "aktion", "kopf" => "Aktion", "breite" => 12,
            "sortierbar" => false, "suchbar" => false, "art" => "text",
            "zelle" => static function (array $z): string {
              ob_start ();
              estab_list_detail_action (
                "ldf", "meldung", $z ["lfd"], "Vordruck öffnen", false, true
              );
              return (string) ob_get_clean ();
            },
          );
          echo estab_tabelle_markup (array (
            "id" => "disposition",
            "beschriftung" => "Nachrichten zur Disposition mit Richtung, "
              . "Vorrang und Rufname",
            "mindestbreite" => "58rem",
            "spalten" => $dispoSpalten,
            "zeilen" => $dispoZeilen,
            "leer" => "Keine Nachricht entspricht den gesetzten Filtern.",
          ));
        } else {
          echo "<p class=\"estab-message-list-empty\">".
               "Zur Zeit keine Nachricht für die LdF</p>";
        }
      break;

      case "FMA":           /***** F M A ****/
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - fkt:createlist - switch(listenart) -- case (FMA)<br>";}
        $incidentId = $this->required_incident_id ();
        $query = "SELECT `00_lfd`,`01_medium`,`07_durchspruch`, `08_befhinweis`, `08_befhinwausw`,`09_vorrangstufe`, `10_anschrift`, `12_abfzeit`, `12_anhang`, `12_inhalt` FROM `".$conf_4f_tbl ["nachrichten"]."`
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
        /*
         * Der Ausgang kommt aus dem Tabellenbauteil.
         *
         * Er trug vier Spalten ohne Sortierung, ohne Suche und ohne
         * Blaetterer -- und das Uebermittlungsmittel gar nicht, obwohl die
         * Abfrage seit jeher danach filtert. Eine Fernmeldestelle teilt
         * ihre Plaetze aber nach Mitteln auf: Wer den Funk betreut, sucht
         * seine Auftraege sonst zwischen den Faxen der anderen.
         */
        if ($result != "" ){
          $ausgangZeilen = array ();
          $ausgangMedien = array ();
          foreach ($result as $row) {
            $abfzeit = convdatetimeto ($row ["12_abfzeit"]);
            $medium = estab_liste_medium_name ($row ["01_medium"] ?? "");
            if ($medium !== "") {
              $ausgangMedien [$medium] = true;
            }
            $ausgangZeilen[] = array (
              "lfd" => estab_message_positive_id ($row ["00_lfd"]),
              "zeit" => estab_liste_zeitwert ($row ["12_abfzeit"] ?? null),
              "zeit_kurz" => (string) ($abfzeit ["stak"] ?? ""),
              "medium" => $medium,
              "vorrang" => estab_message_priority_label ($row ["09_vorrangstufe"]),
              "anschrift" => (string) ($row ["10_anschrift"] ?? ""),
              "inhalt" => (string) ($row ["12_inhalt"] ?? ""),
              "anhang" => $row ["12_anhang"] ?? null,
              // Dringliches faellt auf -- als Marke, nicht als fest
              // eingetragene Farbe im Markup.
              "dringend" => estab_message_priority_requires_attention (
                $row ["09_vorrangstufe"]
              ),
            );
          }
          ksort ($ausgangMedien);
          $ausgangSpalten = estab_liste_spalten_ausgang (
            array_keys ($ausgangMedien)
          );
          /*
           * Der Inhalt traegt die Anlagenmarke, die Aktionsspalte den
           * Knopf. Beides steht in der Spalte, nicht in der Zeile: Frueher
           * war jede Zelle ein Knopf, und ein Klick auf den Text loeste
           * ungewollt aus.
           */
          foreach ($ausgangSpalten as $i => $ausgangSpalte) {
            if ($ausgangSpalte ["schluessel"] === "inhalt") {
              $ausgangSpalten [$i]["zelle"] = static function (array $z): string {
                ob_start ();
                estab_list_attachment_badge ($z ["anhang"] ?? null);
                $marke = (string) ob_get_clean ();
                return estab_tabelle_zelleninhalt ((string) $z ["inhalt"]).$marke;
              };
            }
          }
          $ausgangSpalten[] = array (
            "schluessel" => "aktion", "kopf" => "Aktion", "breite" => 12,
            "sortierbar" => false, "suchbar" => false, "art" => "text",
            "zelle" => static function (array $z): string {
              ob_start ();
              estab_list_detail_action (
                "fm", "meldung", $z ["lfd"], "Vordruck öffnen", false, true
              );
              return (string) ob_get_clean ();
            },
          );
          echo estab_tabelle_markup (array (
            "id" => "ausgang",
            "beschriftung" => "Meldungen im Ausgang mit Übermittlungsmittel, "
              . "Vorrang und Anschrift",
            "mindestbreite" => "56rem",
            "zeilenmarke" => static function (array $z): string {
              return ($z ["dringend"] ?? false)
                ? "class=\"estab-tabelle-zeile--achtung\""
                : "";
            },
            "spalten" => $ausgangSpalten,
            "zeilen" => $ausgangZeilen,
            "leer" => "Keine Meldung entspricht den gesetzten Filtern.",
          ));
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
        // Vollstaendig: Das Tabellenbauteil siebt, sortiert und blaettert.
        $result = $this->get_list ("global");
        /*
         * Die Filterleiste und die Kategorienauswahl gehoeren *in* das Band
         * des Tabellenbauteils, nicht daneben. Sie werden deshalb
         * zwischengespeichert und dort eingesetzt.
         *
         * Ohne diese Stelle stuende neben jeder Tabelle wieder eine eigene
         * Leiste -- und dann sieht wieder jede anders aus. Der Blaetterer
         * (listen_navi) faellt ganz weg: Das Bauteil bringt seinen eigenen
         * mit, und zwei Blaetterer nebeneinander sind schlimmer als einer.
         */
        ob_start ();
        $this->darstellungs_art ();
        $this->kategoliste ();
        $stabZusatz = (string) ob_get_clean ();
        // Erst das Formular, dann die Tabelle mit den Knoepfen darin.
        $this->filterleiste_formular ();
        if (!is_array ($this->operationalIdentity)) {
          throw new EstabReadPermissionException (
            "Die wirksame Stabsfunktion ist nicht verfügbar."
          );
        }
        $dbschongelesen = list_of_readed_msg ($this->operationalIdentity) ;
        $dbschonerledigt = list_of_done_msg ($this->operationalIdentity) ;
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
        /*
         * Auch die leere Menge wird gezeichnet.
         *
         * Hier stand ein "if ($result != \"\")" ohne Gegenzweig. Wer beide
         * Schalter ausschaltete, bekam eine WHERE-Bedingung "1 = 0" -- und
         * damit eine vollstaendig leere Flaeche: keine Tabelle, kein Satz,
         * keine Leiste. Das sieht aus wie ein Absturz, und schlimmer: Ohne
         * Leiste gibt es keinen Weg zurueck. Man kam nur heraus, indem man
         * die Ansicht verliess.
         *
         * Das Bauteil bringt seinen Leerzustand mit ("leer" weiter unten)
         * und zeichnet die Baender dazu. Also wird immer gezeichnet.
         */
        $result = is_array ($result) ? $result : array ();
        {
          /*
           * Breite und Klammerung je Spalte.
           *
           * Die Zahlen stammen aus einer Messung im Browser, nicht aus
           * dem Gefühl. Gemessen wurde, wie breit jeder Kopf in seiner
           * Schrift wirklich sein will -- die Großschreibung und der
           * Buchstabenabstand kommen aus dem Stilblatt und machen ihn
           * breiter, als er im Quelltext aussieht. Vier Köpfe waren zu
           * schmal: „ABFASSZEI" stand für „ABFASSZEIT", und ein halber
           * Spaltenname ist schlimmer als ein zweizeiliger, denn er
           * sieht aus wie ein ganzer.
           *
           * Bezahlt hat das die Inhaltsspalte: Sie hatte 197
           * Bildpunkte für einen Kopf, der 73 braucht. Sie klammert
           * ohnehin auf zwei Zeilen und klappt auf Wunsch auf; ein
           * richtiger Spaltenname wiegt schwerer als zwanzig Bildpunkte
           * Vorschautext.
           *
           * „TBB-Nachweis" bricht am Bindestrich und braucht deshalb
           * nur die Breite von „NACHWEIS". „Transport" braucht die
           * seiner längsten Marke, „abgeschlossen".
           *
           * Kopf, Breite, Klammerung, Art, sortierbar, suchbar.
           */
          $stabSpalten = array (
            array ("Kenntnis", 10, false, "text", false, false),
            array ("Erledigt", 10, false, "text", false, false),
            array ("Transport", 12, false, "text", false, false),
            array ("Vorrang", 10, false, "vorrang", true, false),
            array ("E/A", 6, false, "text", true, false),
            array ("TBB-Nachweis", 10, false, "zahl", true, true),
            array ("Von", 8, true, "text", true, true),
            array ("An", 8, true, "text", true, true),
            array ("Abfasszeit", 12, false, "zeit", true, false),
            array ("Inhalt", 16, true, "text", false, true),
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
               // Die Werte, nach denen gesiebt und sortiert wird. Nicht das
               // Markup: Sonst suchte man in Klassennamen statt in Angaben.
               "w3" => estab_message_priority_label ($row ["09_vorrangstufe"] ?? ""),
               "w4" => (string) ($row ["04_richtung"] ?? ""),
               "w5" => estab_message_list_tbb_evidence_label ($row),
               "w6" => (string) ($row ["13_abseinheit"] ?? ""),
               "w7" => (string) ($row ["10_anschrift"] ?? ""),
               "w8" => (string) ($row ["12_abfzeit"] ?? ""),
               "w9" => estab_message_plain_text ($row ["12_inhalt"] ?? ""),
             );
             foreach ($stabZellen as $stabNummer => $stabInhalt) {
               $stabZeile ["z" . $stabNummer] = $stabInhalt;
             }
             $stabZeilen[] = $stabZeile;
          }  // foreach $result

          $stabAufbau = array ();
          foreach ($stabSpalten as $stabNummer => $stabAngabe) {
            $stabAufbau[] = array (
              // Gesiebt und sortiert wird ueber den Wert der Spalte, das
              // Markup steht in "zelle".
              "schluessel" => (($stabAngabe [4] ?? false) || ($stabAngabe [5] ?? false))
                ? "w" . $stabNummer
                : "id",
              "kopf" => $stabAngabe [0],
              "breite" => $stabAngabe [1],
              "sortierbar" => (bool) ($stabAngabe [4] ?? false),
              "suchbar" => (bool) ($stabAngabe [5] ?? false),
              "art" => (string) ($stabAngabe [3] ?? "text"),
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
            "mindestbreite" => "60rem",
            // Die eigene Filterleiste steht im Band des Bauteils.
            "zusatzbaender" => $stabZusatz,
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
        // Kein listen_navi mehr: Das Bauteil bringt seinen Blaetterer mit.
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
        /*
         * Die Sichtungsliste kommt aus dem Tabellenbauteil.
         *
         * Sie trug vier Spalten ohne Sortierung, ohne Suche und ohne
         * Blaetterer. Ein Sichter sieht sie bei jeder Schicht; bei einer
         * Uebung stehen dort schnell hundert Meldungen.
         */
        if ($result != "" ){
          $sichtZeilen = array ();
          foreach ($result as $row) {
            $abfzeit = convdatetimeto ($row ["12_abfzeit"]);
            $sichtZeilen[] = array (
              "lfd" => estab_message_positive_id ($row ["00_lfd"]),
              "zeit" => estab_liste_zeitwert ($row ["12_abfzeit"] ?? null),
              "zeit_kurz" => (string) ($abfzeit ["stak"] ?? ""),
              "vorrang" => estab_message_priority_label ($row ["09_vorrangstufe"]),
              "anschrift" => (string) ($row ["10_anschrift"] ?? ""),
              "inhalt" => (string) ($row ["12_inhalt"] ?? ""),
              "anhang" => $row ["12_anhang"] ?? null,
              /*
               * Dringliches faellt auf -- als Marke, nicht als fest
               * eingetragene Farbe. Hier stand rgb(220,0,0) auf Weiss,
               * waehrend der Ausgang fuer dieselbe Bedingung Gelb nahm.
               * Gleiche Bedeutung, gleiches Aussehen: beide tragen jetzt
               * dieselbe Achtungsmarke.
               */
              "dringend" => estab_message_priority_requires_attention (
                $row ["09_vorrangstufe"]
              ),
            );
          }
          $sichtSpalten = array (
            estab_liste_spalte_zeit (),
            array ("schluessel" => "vorrang", "kopf" => "Vorrang",
              "breite" => 12,
              "sortierbar" => true, "suchbar" => true, "art" => "vorrang"),
            array ("schluessel" => "anschrift", "kopf" => "Anschrift",
              "breite" => 22,
              "sortierbar" => true, "suchbar" => true, "art" => "text"),
            array ("schluessel" => "inhalt", "kopf" => "Inhalt / Text",
              "breite" => 42,
              "sortierbar" => false, "suchbar" => true, "art" => "text",
              "zelle" => static function (array $z): string {
                ob_start ();
                estab_list_attachment_badge ($z ["anhang"] ?? null);
                $marke = (string) ob_get_clean ();
                return estab_tabelle_zelleninhalt ((string) $z ["inhalt"]).$marke;
              }),
            array ("schluessel" => "aktion", "kopf" => "Aktion", "breite" => 12,
              "sortierbar" => false, "suchbar" => false, "art" => "text",
              "zelle" => static function (array $z): string {
                ob_start ();
                estab_list_detail_action (
                  "sichter", "meldung", $z ["lfd"], "Vordruck öffnen",
                  false, true
                );
                return (string) ob_get_clean ();
              }),
          );
          echo estab_tabelle_markup (array (
            "id" => "sichtung",
            "beschriftung" => "Meldungen zur Sichtung mit Zeit, Vorrang und "
              . "Anschrift",
            "mindestbreite" => "54rem",
            "zeilenmarke" => static function (array $z): string {
              return ($z ["dringend"] ?? false)
                ? "class=\"estab-tabelle-zeile--achtung\""
                : "";
            },
            "spalten" => $sichtSpalten,
            "zeilen" => $sichtZeilen,
            "leer" => "Keine Meldung entspricht den gesetzten Filtern.",
          ));
        } else {// if isset $result
          echo "<p class=\"estab-message-list-empty\">".
               "Zur Zeit keine Meldung zu sichten</p>";
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
		
		
      /*
       * Hier stand ein auskommentierter Block von 126 Zeilen: eine
       * zweite Sichterliste mit eigenen Spalten je Empfaengerfunktion.
       *
       * Kein Hinweis stand dabei, warum sie stillgelegt wurde. Ihre
       * Abfrage las `WHERE 1` -- also die Nachrichten *aller*
       * Einsaetze, nicht die des aktiven -- und ihre Zeilen oeffneten
       * ihr <tr> nur bei gesetzter Vorrangstufe, gaben die Zellen aber
       * immer aus. Beides waere heute ein Befund; auskommentiert war
       * es keiner, aber es stand da und sah aus wie Vorrat.
       *
       * Geloescht: 126 Zeilen. Wer eine solche Liste braucht, baut sie
       * aus dem Tabellenbauteil -- mit Einsatzbindung.
       */

      break; // case SIADMIN

      /*
       * Hier standen drei Nachweisungszweige -- FmNwE, FmNwA, FmNw --
       * mit je einer handgeschriebenen Tabelle. Niemand rief sie auf:
       * Die Nachweisung geht seit der Umstellung durch
       * app/nachweisung.php und kommt dort aus dem Tabellenbauteil.
       *
       * Toter Code, der aussieht wie eine Liste, ist teurer als eine
       * Liste: Wer die Nachweisung sucht, findet zwei Umsetzungen und
       * muss erst herausfinden, welche gilt -- und wer eine davon
       * verbessert, verbessert womoeglich die falsche.
       *
       * Geloescht: 366 Zeilen. Dass kein Zweig ohne Aufrufer
       * zurueckkommt, haelt ges_tabelle_einheitlich fest.
       */
      break;
    } // switch
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - fkt:createlist - switch_ENDE(listenart) ></b><br>";}  
  }
} // class

?>
