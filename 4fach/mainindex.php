<?php

/*****************************************************************************\
   Datei: mainindex.php

   benoetigte Dateien: config.inc.php, protokoll.php, db_operation.php,
                      4fachform.php, liste.php, data_hndl.php, menue.php
   Beschreibung:
           HAUPTSTEUERUNGSDATEI

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

define ("debug", false);              // true = gibt debuginformationen aus

define ("create_vordrucke", true);   // Erstellt PDF oder/und PNG Dokumente fÃ¼r die RÃ¼ckfallebene

if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Steuerungsdatei</big></big><br>";  }

session_start ();

require_once __DIR__ . "/../app/workflow.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/session_ui.php";
require_once __DIR__ . "/../app/logout.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/message_list.php";
estab_session_ui_start ($_SESSION);

$returnValue = array (); // no request data is a valid, warning-free state
if (count($_GET)>0)  { $returnValue = $_GET; }   // GET Daten, wenn vorhanden speichern
if (count($_POST)>0) { $returnValue = $_POST; }  // POST Daten, wenn vorhanden speichern

// Frequently compared routing values have neutral defaults. Button fields
// intentionally remain absent because the legacy code uses isset() for them.
$returnValue += array (
  "task" => "",
  "fm" => "",
  "ldf" => "",
  "stab" => "",
  "sichter" => "",
);

// PHP 8 reports reads of missing session keys. These neutral values preserve
// the old loose-comparison behaviour without flooding production logs.
$_SESSION += array (
  "ROLLE" => "",
  "UPLOAD" => "",
  "fm_zweite_sichtung" => 0,
  "gesprnoti" => false,
  "si_zweite_sichtung" => 0,
  "vStab_benutzer" => "",
  "vStab_funktion" => "",
  "vStab_kuerzel" => "",
  "vStab_rolle" => "",
);

// The controller historically mixed public login handling with every
// message route. Admit only an exact login request before authentication and
// enforce the established function/role boundary before any database action.
$workflowIdentity = estab_auth_session_identity ($_SESSION);
if ($workflowIdentity === null) {
  if (!estab_workflow_public_login_request ($_SERVER, $_GET, $_POST)) {
    if (
      estab_workflow_anonymous_operational_get (
        $_SERVER,
        $_GET,
        $_POST
      )
    ) {
      header ("Cache-Control: no-store");
      header ("Vary: Cookie");
      header (
        "Location: ".estab_navigation_login_content_url ("messages"),
        true,
        303
      );
      exit;
    }
    if (
      estab_workflow_anonymous_operational_post (
        $_SERVER,
        $_GET,
        $_POST
      )
    ) {
      header ("Cache-Control: no-store");
      header ("Vary: Cookie, Sec-Fetch-Site");
      header (
        "Location: ".estab_navigation_login_content_url (
          "messages",
          true
        ),
        true,
        303
      );
      exit;
    }
    estab_workflow_forbid ();
  }
  if (
    (string) ($_SERVER ["REQUEST_METHOD"] ?? "") === "POST"
    && estab_workflow_login_credentials_present ($_POST)
    && !estab_csrf_is_valid ($_POST ["csrf_token"] ?? null)
    && !estab_workflow_legacy_login_without_csrf_allowed ($_SERVER, $_POST)
  ) {
    estab_workflow_forbid ();
  }
} elseif (!estab_workflow_route_allowed (
  $workflowIdentity,
  (string) ($_SERVER ["REQUEST_METHOD"] ?? "GET"),
  $returnValue
)) {
  estab_workflow_forbid ();
}

if (
  $workflowIdentity !== null
  && (
    ($returnValue ["task"] ?? "") !== ""
    || isset ($returnValue ["action"])
    || isset ($returnValue ["reset_record"])
    || isset ($returnValue ["m2_abmelden_x"])
    || ($returnValue ["stab"] ?? "") === "meldung"
    || ($returnValue ["sichter"] ?? "") === "meldung"
    || ($returnValue ["ldf"] ?? "") === "meldung"
    || in_array (($returnValue ["fm"] ?? ""), array (
      "meldung", "FM-Adminmeldung", "SI-Adminmeldung",
    ), true)
    || array_filter (
      array_keys ($returnValue),
      static fn ($key) => is_string ($key) && str_starts_with ($key, "ml_")
    ) !== array ()
  )
) {
  try {
    estab_csrf_require_post ($_SERVER, $_POST);
  } catch (Throwable $exception) {
    estab_workflow_forbid ();
  }
}

foreach (array ("reset_record", "00_lfd", "msglfd") as $recordKey) {
  if (isset ($returnValue [$recordKey]) && $returnValue [$recordKey] !== "") {
    $returnValue [$recordKey] = estab_workflow_record_id ($returnValue [$recordKey]);
  }
}

/*
$pre_01medium = "Fu";

Für Marc beim Southside Festival zum schnelleren Eintragen von Nachrichten
Weiter unten wird bei einem Nachrichteneingang das Medium Fe Fu Me Fax @
*/

if ( debug){
  echo "<br><br>\n";
  echo "returnValue="; var_dump ($returnValue);    echo "#<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}
// exit;
error_reporting(E_ALL);

include ("../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben
include ("../4fcfg/dbcfg.inc.php");     // Datenbankparameter
include ("../4fcfg/fkt_rolle.inc.php"); // Mitspieler
include ("protokoll.php");              // Protokolllierung in der Datenbank
include ("db_operation.php");           // Datenbank operationen
include ("4fachform.php");              // Formular Behandlung 4fach Vordruck
include ("liste.php");                  // erzeuge Ausgabelisten
include ("data_hndl.php");              // Schnittstelle zur Datenbank

/** Stop before any legacy list/query when no selected active duty exists. */
function estab_workflow_render_read_gate (
  int $status,
  string $title,
  string $message
): never {
  http_response_code ($status);
  header ("Content-Type: text/html; charset=UTF-8");
  header ("Cache-Control: private, no-store, max-age=0");
  $commandPostUrl = estab_navigation_url_for_key ("command-post");
  echo "<!doctype html><html lang=\"de\"><head><meta charset=\"UTF-8\">";
  echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
  echo estab_session_ui_stylesheet ();
  echo "<title>".estab_auth_html ($title)."</title></head>";
  echo "<body><main class=\"estab-auth-shell\">";
  echo "<section class=\"estab-auth-card\" data-estab-duty-selection-required>";
  echo "<h1>".estab_auth_html ($title)."</h1>";
  echo "<p>".estab_auth_html ($message)."</p>";
  echo "<p><a class=\"estab-button estab-button-primary\" href=\"".
       estab_auth_html ($commandPostUrl).
       "\" target=\"_top\">Führungsstellenbetrieb öffnen</a></p>";
  echo "</section></main></body></html>";
  exit;
}

/** Return the configured recipient functions once, in their display order. */
function estab_workflow_message_list_recipients (array $definitions): array {
  $recipients = array ();
  foreach ($definitions as $definition) {
    $function = is_array ($definition)
      ? (string) ($definition ["fkt"] ?? "")
      : "";
    if ($function !== "" && !in_array ($function, $recipients, true)) {
      $recipients [] = $function;
    }
  }
  return $recipients;
}

/** Apply the strict shared list request and retain it for this duty view. */
function estab_workflow_message_list_filters (
  string $sessionKey,
  array $request,
  array $recipients
): array {
  $allowedKeys = array_fill_keys (array (
    "ml_q", "ml_direction", "ml_priority", "ml_status", "ml_from",
    "ml_to", "ml_recipient", "ml_sort", "ml_page", "ml_page_size",
    "ml_apply", "ml_reset", "ml_remove",
  ), true);
  foreach (array_keys ($request) as $key) {
    if (
      is_string ($key)
      && str_starts_with ($key, "ml_")
      && !isset ($allowedKeys [$key])
    ) {
      estab_workflow_forbid ();
    }
  }
  try {
    $filters = estab_message_list_apply_request (
      is_array ($_SESSION [$sessionKey] ?? null)
        ? $_SESSION [$sessionKey]
        : estab_message_list_default_filters (),
      $request,
      $recipients
    );
  } catch (Throwable $exception) {
    estab_workflow_forbid ();
  }
  $_SESSION [$sessionKey] = $filters;
  return $filters;
}

$workflowSelectedIdentity = $workflowIdentity;
$workflowIncidentId = null;
$activeCommandPostName = "";
if ($workflowIdentity !== null) {
  $readGateConnection = null;
  try {
    $readGateConnection = estab_message_connect ($conf_4f_db);
    $readScope = estab_read_require_operational_scope (
      $readGateConnection,
      estab_read_session_identity ($_SESSION) ?? array ()
    );
    $workflowSelectedIdentity = $readScope ["identity"];
    $workflowIdentity = $workflowSelectedIdentity;
    $workflowIncidentId = (int) (
      $readScope ["incident"]["active_einsatz_id"]
    );
    $activeCommandPostName = estab_incident_command_post_name (
      $readScope ["incident"]
    );
  } catch (EstabNoActiveIncidentException $exception) {
    estab_workflow_render_read_gate (
      409,
      "Kein Einsatz aktiv",
      "Operative Nachrichten sind erst verfügbar, wenn in der ".
      "Administration ein Einsatz aktiviert wurde."
    );
  } catch (EstabReadPermissionException $exception) {
    estab_workflow_render_read_gate (
      403,
      "Dienstfunktion auswählen",
      "Nehmen Sie zuerst Ihre persönliche Dienstfunktion an und wählen ".
      "Sie sie für diese Sitzung aus."
    );
  } catch (EstabIncidentConfigurationException $exception) {
    estab_workflow_render_read_gate (
      409,
      "Führungsstellenname fehlt",
      "Legen Sie in der Administration zuerst den Namen der Führungsstelle ".
      "für den aktiven Einsatz fest."
    );
  } catch (Throwable $exception) {
    error_log ("eStab main read gate failed: ".$exception->getMessage ());
    estab_workflow_render_read_gate (
      503,
      "Dienststatus nicht verfügbar",
      "Die aktive Dienstfunktion kann derzeit nicht geprüft werden."
    );
  } finally {
    if ($readGateConnection instanceof mysqli) {
      estab_auth_close ($readGateConnection);
    }
  }
}

// A second-sighting mode is scoped to the currently selected hat. Normalise
// sessions created before that rule existed and fail closed after duty changes.
if (
  !is_array ($workflowSelectedIdentity)
  || ($workflowSelectedIdentity ["funktion"] ?? null) !== "A/W"
  || ($workflowSelectedIdentity ["rolle"] ?? null) !== "Fernmelder"
) {
  $_SESSION ["fm_zweite_sichtung"] = 0;
}
if (
  !is_array ($workflowSelectedIdentity)
  || ($workflowSelectedIdentity ["funktion"] ?? null) !== "Si"
  || ($workflowSelectedIdentity ["rolle"] ?? null) !== "Stab"
) {
  $_SESSION ["si_zweite_sichtung"] = 0;
}

/**
 * Reject authenticated operational POSTs before opening or changing domain
 * objects when the global incident input lock is closed.
 */
function estab_workflow_require_active_incident_for_post (
  ?array $identity,
  array $server,
  array $request,
  array $databaseConfig
): void {
  if (
    $identity === null
    || strtoupper ((string) ($server ["REQUEST_METHOD"] ?? "GET")) !== "POST"
    || isset ($request ["m2_abmelden_x"])
  ) {
    return;
  }

  $submittedTask = (string) ($request ["task"] ?? "") !== ""
    && (
      isset ($request ["absenden_x"])
      || isset ($request ["zurueckweisen_x"])
      || isset ($request ["transport_nicht_moeglich_x"])
      || isset ($request ["transport_nicht_moeglich_y"])
      || isset ($request ["antwort_x"])
      || isset ($request ["weiterleiten_x"])
      || isset ($request ["abbrechen_x"])
    );
  $operational = $submittedTask
    || isset ($request ["action"])
    || isset ($request ["reset_record"])
    || isset ($request ["stab_anhang_x"])
    || isset ($request ["fm_anhang_x"])
    || (string) ($request ["fm"] ?? "") === "meldung"
    || (string) ($request ["ldf"] ?? "") === "meldung";
  if (!$operational) {
    return;
  }

  $connection = null;
  try {
    $connection = estab_message_connect ($databaseConfig);
    $incident = estab_incident_active ($connection);
  } catch (Throwable $exception) {
    error_log ("eStab incident input gate unavailable: ".$exception->getMessage ());
    http_response_code (503);
    header ("Content-Type: text/plain; charset=UTF-8");
    header ("Cache-Control: no-store");
    echo "Der Einsatzstatus kann derzeit nicht geprüft werden.";
    exit;
  } finally {
    if ($connection instanceof mysqli) {
      estab_auth_close ($connection);
    }
  }
  if ($incident !== null) {
    return;
  }

  http_response_code (409);
  header ("Content-Type: text/plain; charset=UTF-8");
  header ("Cache-Control: no-store");
  echo "Kein Einsatz ist aktiv. Eingaben sind gesperrt. Aktivieren Sie zuerst ".
       "einen Einsatz in der Administration.";
  exit;
}

estab_workflow_require_active_incident_for_post (
  $workflowIdentity,
  $_SERVER,
  $returnValue,
  $conf_4f_db
);

// Role checks alone are insufficient for record identifiers supplied by a
// browser. Bind every data-bearing route to the addressed message before any
// read, state transition, lock change or form save can run.
$messageOperation = $workflowIdentity === null
  ? null
  : estab_workflow_message_operation ($returnValue);
$objectMessage = null;
if ($messageOperation !== null) {
  $messageRecordId = $messageOperation === "message-operator-reset"
    ? ($returnValue ["reset_record"] ?? null)
    : ($returnValue ["00_lfd"] ?? null);
  if (!is_int ($messageRecordId) || $messageRecordId < 1) {
    estab_workflow_forbid ();
  }
  $objectConnection = null;
  try {
    $objectConnection = estab_message_connect ($conf_4f_db);
    $objectMessage = estab_message_fetch_for_incident_by_id (
      $objectConnection,
      $conf_4f_tbl ["nachrichten"],
      $messageRecordId,
      $workflowIncidentId
    );
    if (
      !is_array ($objectMessage)
      || !estab_message_object_allowed (
        $workflowSelectedIdentity,
        $messageOperation,
        $objectMessage
      )
      || !estab_read_message_allowed (
        $workflowSelectedIdentity,
        $objectMessage
      )
    ) {
      estab_workflow_forbid ();
    }
  } catch (Throwable $exception) {
    error_log ("eStab message object gate failed");
    estab_workflow_forbid ();
  } finally {
    if ($objectConnection instanceof mysqli) {
      estab_auth_close ($objectConnection);
    }
  }
}

  $db = mysql_connect($conf_4f_db   ["server"],$conf_4f_db   ["user"], $conf_4f_db   ["password"] );
  mysql_query('SET NAMES utf8');
  $result = mysql_ping  ($db);

  if ($result == false){
    echo "<h1>Es besteht keine Verbindung zur Datenbank.</h1>";
    echo "<big><b>Mögliche Ursachen:<br></b>";
    echo " 1. Datenbankserver ist nicht erreichbar weil aus.<br>";
    echo " 2. Netzwerkfehler, wenn DB-Server auf anderem Server.<br>";
    echo " 3. Benutzer oder Passwort stimmen nicht.<br><br>";
    echo "Bitte unter \"administrative Massnahme\" - \"Datenbankparameter eingeben\" die Parameter einstellen.";
    echo "</big>";
    exit;
  }
  if (isset($db)){
    mysql_close($db);
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Close DB</big></big><br>";  }
  }

/**********************************************************************\
\**********************************************************************/
  function resetframeset () {
    global $conf_4f;
    pre_html ("reset", "Framereset ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"], ""); // Normaler Seitenaufbau mit Auffrischung
    echo "<body onload=\"".
         estab_auth_html (estab_session_ui_frame_refresh_script ()).
         "\">";
  }

  /**
   * Replace the complete login frameset with the selected standalone area.
   *
   * The login POST runs inside the historical mainframe. A top-level browser
   * replacement therefore keeps the selected area out of that narrow frame;
   * the ordinary link remains available when JavaScript is disabled.
   */
  function estab_navigation_open_after_login (string $destinationKey): never {
    $destinationUrl = estab_navigation_url_for_key ($destinationKey);
    $encodedUrl = json_encode (
      $destinationUrl,
      JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
    header ("Content-Type: text/html; charset=UTF-8");
    echo "<!doctype html><html lang=\"de\"><head><meta charset=\"UTF-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>eStab-Bereich öffnen</title></head><body>";
    echo "<p>Der gewählte eStab-Bereich wird geöffnet.</p>";
    echo "<p><a href=\"".estab_auth_html ($destinationUrl).
         "\" target=\"_top\">Jetzt öffnen</a></p>";
    echo "<script>window.top.location.replace(".$encodedUrl.");</script>";
    echo "</body></html>";
    exit;
  }


  if (isset ($returnValue ["reset_record"])){
    try {
      estab_csrf_require_post ($_SERVER, $_POST);
    } catch (Throwable $exception) {
      estab_workflow_forbid ();
    }
    reset_record_lock ($returnValue ["reset_record"]);
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>eset Recordlock</big></big><br>";  }
  }


/**********************************************************************\
  Es gab noch keinen Kontakt ==> Begruessung
\**********************************************************************/
/*******************************************************************
ANTWORT % WEITERLEITUNG
*******************************************************************/
  $weiterantwort = false;
  $telecommunicationsAnswerRequested = isset ($returnValue ["antwort_x"]);
  $telecommunicationsForwardRequested =
    isset ($returnValue ["weiterleiten_x"]);
  if (
    $telecommunicationsAnswerRequested
    && $telecommunicationsForwardRequested
    && $returnValue ["task"] == "FM-Ausgang"
  ) {
    estab_workflow_forbid ();
  }
  if ( ( ($telecommunicationsForwardRequested) or
         ($telecommunicationsAnswerRequested) ) and
         ( $returnValue["task"] == "FM-Ausgang" ) )  {
    if (!is_array ($objectMessage)) {
      estab_workflow_forbid ();
    }
    $weiterantwort = true;
    // The follow-up form is derived solely from the record admitted by the
    // object gate. Browser-hidden message data is never an authority.
    $_SESSION ["sw_data"] = $objectMessage;
    $_SESSION ["sw_data"]["task"] = "FM-Ausgang";
    $_SESSION ["sw_data"][
      $telecommunicationsAnswerRequested
        ? "antwort_x"
        : "weiterleiten_x"
    ] = "1";
  } elseif (  (isset($returnValue ["abbrechen_x"]) and (isset ($_SESSION["sw_data"]))) and
              ( $returnValue["task"] == "FM-Eingang" ) ) {
    unset ($_SESSION["sw_data"]);
  }

  /****************************************************************************\
    Kategorien
  \****************************************************************************/
    // Kategorie Master
  if (isset($returnValue ["ma_ktgotyp"])){
    $masterCategory = estab_workflow_category_filter ($returnValue ["ma_ktgo"] ?? null);
    if ($returnValue ["ma_ktgotyp"] !== "global" || $masterCategory === null) {
      estab_workflow_forbid ();
    }
    if ($masterCategory === "alle") {
      unset ($_SESSION ["ma_kategotyp"]);
      unset ($_SESSION ["ma_katego"]);
    } else {
      $_SESSION ["ma_kategotyp"] = $returnValue ["ma_ktgotyp"];
      $_SESSION ["ma_katego"]    = $masterCategory;
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }
    // Kategorie FUNKTION
  if (isset($returnValue ["fk_ktgotyp"])){
    $functionCategory = estab_workflow_category_filter ($returnValue ["fk_ktgo"] ?? null);
    if ($returnValue ["fk_ktgotyp"] !== "fkt" || $functionCategory === null) {
      estab_workflow_forbid ();
    }
    if ($functionCategory === "alle") {
      unset ($_SESSION ["fk_kategotyp"]);
      unset ($_SESSION ["fk_katego"]);
    } else {
      $_SESSION ["fk_kategotyp"] = $returnValue ["fk_ktgotyp"];
      $_SESSION ["fk_katego"]    = $functionCategory;
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }
    // Kategorie USER
  if (isset($returnValue ["us_ktgotyp"])){
    $userCategory = estab_workflow_category_filter ($returnValue ["us_ktgo"] ?? null);
    if ($returnValue ["us_ktgotyp"] !== "user" || $userCategory === null) {
      estab_workflow_forbid ();
    }
    if ($userCategory === "alle") {
      unset ($_SESSION ["us_kategotyp"]);
      unset ($_SESSION ["us_katego"]);
    } else {
      $_SESSION ["us_kategotyp"] = $returnValue ["us_ktgotyp"];
      $_SESSION ["us_katego"]    = $userCategory;
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
    }
  }


  // Aufruf von Anhang vom 4fach Vordruck aus ==> es könnte schon Inhalt im Formular vorhanden sein
  if ( isset ($returnValue["anhang_plus_x"])) {
    $attachmentOriginContext = null;
    $attachmentOriginDraft = null;
    try {
      $attachmentOriginContext =
        estab_attachment_origin_context_create (
          is_array ($workflowSelectedIdentity)
            ? $workflowSelectedIdentity
            : array (),
          $workflowIncidentId,
          $returnValue,
          is_array ($objectMessage) ? $objectMessage : null
        );
      $attachmentOriginDraft =
        estab_attachment_origin_draft_from_request (
          $returnValue,
          is_array ($workflowSelectedIdentity)
            ? $workflowSelectedIdentity
            : array (),
          $attachmentOriginContext
        );
      // This local variable is visible only to the server-side include below.
      // Direct browser requests must always supply their own flow token.
      $attachmentInternalFlowToken =
        estab_attachment_origin_flow_store (
          $_SESSION,
          $attachmentOriginContext,
          $attachmentOriginDraft,
          ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
          static function (array $evictedContext) use (
            $conf_4f_db,
            $conf_4f_tbl
          ): void {
            $evictionConnection =
              estab_attachment_connection ($conf_4f_db);
            try {
              estab_attachment_release_origin_reservation (
                $evictionConnection,
                $conf_4f_tbl ["anhang"],
                session_id (),
                $evictedContext
              );
            } finally {
              estab_attachment_close ($evictionConnection);
            }
          }
        );
    } catch (EstabAttachmentDraftException $exception) {
      error_log (
        "eStab attachment draft rejected: ".$exception->getMessage ()
      );
      $safeDraft = $exception->draft ();
      if ($safeDraft === array () && is_array ($attachmentOriginDraft)) {
        $safeDraft = $attachmentOriginDraft;
      }
      $recoveryTask = (string) (
        $attachmentOriginContext ["task"] ??
        ($returnValue ["task"] ?? "")
      );
      $requiredRecoveryRecipients = array ();
      if ($recoveryTask === "Stab_gesprnoti") {
        $requiredRecoveryRecipients = array (
          (string) $redcopy2."_rt",
          (string) (
            $workflowSelectedIdentity ["funktion"] ?? ""
          )."_gn",
        );
      }
      try {
        $formdata = estab_attachment_origin_draft_form_data (
          $safeDraft,
          is_array ($attachmentOriginContext)
            ? $attachmentOriginContext
            : array (
              "task" => (string) ($returnValue ["task"] ?? ""),
              "record_id" => $returnValue ["00_lfd"] ?? null,
            ),
          is_array ($objectMessage) ? $objectMessage : null,
          $empf_matrix,
          false,
          (string) $redcopy2,
          $requiredRecoveryRecipients
        );
        if (in_array (
          $recoveryTask,
          array ("Stab_schreiben", "Stab_gesprnoti"),
          true
        )) {
          $formdata ["13_abseinheit"] = $activeCommandPostName;
          $formdata ["14_zeichen"] = (string) (
            $workflowSelectedIdentity ["kuerzel"] ?? ""
          );
          $formdata ["14_funktion"] = (string) (
            $workflowSelectedIdentity ["funktion"] ?? ""
          );
          if ($recoveryTask === "Stab_gesprnoti") {
            $formdata ["01_zeichen"] = $formdata ["14_zeichen"];
          }
        }
      } catch (Throwable $renderException) {
        error_log (
          "eStab attachment draft recovery failed: ".
          $renderException->getMessage ()
        );
        estab_workflow_forbid ();
      }
      $formdata ["estab_route_error"] =
        "Die Anhangverwaltung wurde nicht geöffnet: ".
        $exception->getMessage ().
        " Ihre bisherigen Eingaben wurden nicht in den Anhang-Flow ".
        "übernommen und bleiben in diesem Formular erhalten.";
      http_response_code (422);
      header ("Cache-Control: private, no-store, max-age=0");
      $form = new nachrichten4fach (
        $formdata,
        (string) ($attachmentOriginContext ["task"] ??
          ($returnValue ["task"] ?? "")),
        array ()
      );
      exit;
    } catch (EstabAttachmentContextException $exception) {
      error_log (
        "eStab attachment origin rejected: ".$exception->getMessage ()
      );
      estab_workflow_forbid ();
    } catch (Throwable $exception) {
      error_log (
        "eStab attachment flow cleanup failed: ".$exception->getMessage ()
      );
      http_response_code (503);
      header ("Content-Type: text/plain; charset=UTF-8");
      header ("Cache-Control: no-store");
      echo "Der Anhangvorgang kann derzeit nicht sicher geöffnet werden.";
      exit;
    }
    $_SESSION ["anhang_menue"] = "100";
    include ("anhang.php");
    exit;
  }

  /**********************************************************************\
    --- S T A B und F M Z   s c h r e i b e n   m i t  A n h a n g ---

    Oeffnet ein Fenster in dem Anhaenge ausgewaehlt werden koennen
  \**********************************************************************/
  if ( ( isset ( $returnValue["stab_anhang_x"] ) or  isset ( $returnValue["fm_anhang_x"] )
       ) and  ( !isset( $returnValue["ah_auswahl_x"] ) ) )  {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";}
    // The sidebar opens a standalone overview. Token-indexed message drafts in
    // other tabs remain independently resumable.
    $_SESSION ["anhang_menue"] = 100;
    include ("anhang.php");
    $menue1 = "anhang";
    exit;
  }




  /**********************************************************************\
    Überprüfe ob die Listendarstellung geändert werden soll
  \**********************************************************************/
  if (!isset ( $_SESSION["filter_darstellung"])){
    $_SESSION["filter_darstellung"] = 1;
    $_SESSION["filter_erledigt"]    = 0;
    $_SESSION["filter_unerledigt"]  = 1;
    $_SESSION["filter_anzahl"]      = 15;
    $_SESSION["filter_start"]       = 0 ;
    $_SESSION["filter_position"]    = 0;
    $_SESSION["filter_gelesen"]     = 0;
    $_SESSION["flt_find_mask"]      = 0;
  }
  if (!isset ($_SESSION["filter_gelesen"])) { $_SESSION["filter_gelesen"] = 0; }
  if (!isset ($_SESSION["flt_find_mask"])) { $_SESSION["flt_find_mask"] = 0; }
  // filtern EIN / AUS
  if ( (isset ($returnValue["filter_darstellung_aus_x"])) or
       (isset ($returnValue["filter_darstellung_ein_x"])) ){

    if ( ($_SESSION["filter_darstellung"] == 1) and (isset ($returnValue["filter_darstellung_aus_x"])) ) {
      $_SESSION["filter_darstellung"] = 0;
    } elseif ( ($_SESSION["filter_darstellung"] == 0) and (isset ($returnValue["filter_darstellung_ein_x"])) ){
      $_SESSION["filter_darstellung"] = 1;
    }
  }

  // erledigte SICHTBAR UNSICHTBAR
  if (( (isset ($returnValue["filter_erledigt_aus_x"])) or
       (isset ($returnValue["filter_erledigt_ein_x"])) ) AND !isset($returnValue["flt_search"])){

    if ( ($_SESSION["filter_erledigt"] == 1) and (isset($returnValue["filter_erledigt_aus_x"])) ) {
      $_SESSION["filter_erledigt"] = 0;
    } elseif ( ($_SESSION["filter_erledigt"] == 0) and (isset ($returnValue["filter_erledigt_ein_x"])) ){
      $_SESSION["filter_erledigt"] = 1;
    }
  }
  // unerledigte SICHTBAR UNSICHTBAR
  if (( (isset ($returnValue["filter_unerledigt_aus_x"])) or
       (isset ($returnValue["filter_unerledigt_ein_x"])) ) AND !isset($returnValue["flt_search"])){

    if ( ($_SESSION["filter_unerledigt"] == 1) and (isset($returnValue["filter_unerledigt_aus_x"])) ) {
      $_SESSION["filter_unerledigt"] = 0;
    } elseif ( ($_SESSION["filter_unerledigt"] == 0) and (isset ($returnValue["filter_unerledigt_ein_x"])) ){
      $_SESSION["filter_unerledigt"] = 1;
    }
  }

  // finde Menü Ein und Ausschalten des Findemenüs
  if ( (isset ($returnValue["flt_find_mask_aus_x"])) or
       (isset ($returnValue["flt_find_mask_ein_x"])) ){
    if ( ($_SESSION["flt_find_mask"] == 1) and (isset($returnValue["flt_find_mask_aus_x"])) ) {
      $_SESSION["flt_search"] = NULL;
      unset ($_SESSION["flt_search"]);
      $_SESSION["flt_find_mask"] = 0;
    } elseif ( ($_SESSION["flt_find_mask"] == 0) and (isset ($returnValue["flt_find_mask_ein_x"])) ){
      $_SESSION["flt_find_mask"] = 1;
    }
  }

  if (isset($returnValue["filter_suche_reset"])){ unset ($_SESSION["flt_search"]); }

  if (isset($returnValue["flt_search"]) AND ($_SESSION["flt_find_mask"] == 1)){
    if (
      !is_string ($returnValue ["flt_search"])
      || strlen ($returnValue ["flt_search"]) > 120
      || str_contains ($returnValue ["flt_search"], "\0")
    ) {
      estab_workflow_forbid ();
    }
    if (($_SESSION["flt_search"] ?? null) !== $returnValue ["flt_search"]){
      $_SESSION["filter_start"] = 0 ;
      $_SESSION["filter_position"] = 0;
	  $_SESSION["flt_search"] = $returnValue ["flt_search"];
    } else {
      $_SESSION["flt_search"] = $returnValue ["flt_search"];
	}
  }

  if (isset($returnValue['filter_anzahl_x'])) {
    $requestedPageSize = filter_var (
      $returnValue ['filter_anzahl'] ?? null,
      FILTER_VALIDATE_INT,
      array ("options" => array ("min_range" => 5, "max_range" => 25))
    );
    if (!is_int ($requestedPageSize) || ($requestedPageSize % 5) !== 0) {
      estab_workflow_forbid ();
    }
    $_SESSION['filter_anzahl'] = $requestedPageSize;
  }
  if (isset($returnValue['flt_start_x'])) { $_SESSION['flt_navi'] = "start";}
  if (isset($returnValue['flt_back_x']))  { $_SESSION['flt_navi'] = "back";}
  if (isset($returnValue['flt_for_x']))   { $_SESSION['flt_navi'] = "for";}
  if (isset($returnValue['flt_end_x']))   { $_SESSION['flt_navi'] = "end";}

  /************************************************************************\

  \************************************************************************/
  if ( isset ($returnValue  ["action"]) ){
    // gelesen
    if ($returnValue  ["action"] == "gelesen")
      if ($returnValue ["todo"] == "set"){
        set_msg_read ( $returnValue["00_lfd"] );
      } else {
        unset_msg_read ( $returnValue["00_lfd"] );
      }
    // erledigt
    if ($returnValue ["action"] == "erledigt")
      if ($returnValue ["todo"] == "set"){
         set_msg_done ( $returnValue["00_lfd"] );
      } else {
        unset_msg_done ( $returnValue["00_lfd"] );
      }
  }



  /**********************************************************************\
    Es gab noch keinen Kontakt ==> Begruessung
  \**********************************************************************/
  if (!isset ( $_SESSION ["menue"] ))
     { $_SESSION ["menue"] = "WELCOME"; }

  /**********************************************************************\
    Der Anmelde Button wurde gedrueckt
  \**********************************************************************/
  if (
    ($returnValue["login"] ?? null) === "Anmelden"
    || (
      isset ($returnValue ["login_x"], $returnValue ["login_y"])
      && estab_workflow_public_login_request ($_SERVER, $_GET, $_POST)
    )
  ) {
    $_SESSION ["menue"] = "LOGIN"; }




  /**********************************************************************\
    Es kommen Anmeldedaten die geprueft und gespeichert werden muessen
  \**********************************************************************/
  // Identität und Kennwort werden ausschließlich aus einem POST-Request
  // gelesen. GET darf Konto-Flow und geschützten Zielschlüssel vorwählen.
  $requestMethod = (string) ($_SERVER ["REQUEST_METHOD"] ?? "GET");
  $loginData = $requestMethod === "POST" ? $_POST : array ();
  $loginFlowRequest = $loginData;
  $loginDestination = null;
  $loginInterrupted =
    $requestMethod === "GET"
    && ($_GET ["interrupted"] ?? null) === "1";
  if ($requestMethod === "GET") {
    $loginFlowRequest = array_key_exists ("login_flow", $_GET)
      ? array ("login_flow" => $_GET ["login_flow"])
      : array ();
    if (array_key_exists ("next", $_GET)) {
      $loginDestination = estab_navigation_login_destination_key (
        $_GET ["next"]
      );
      if ($loginDestination === null) {
        estab_workflow_forbid ();
      }
    }
  } elseif (array_key_exists ("next", $loginData)) {
    $loginDestination = estab_navigation_login_destination_key (
      $loginData ["next"]
    );
    if ($loginDestination === null) {
      estab_workflow_forbid ();
    }
  }
  $loginFlow = estab_auth_login_flow ($loginFlowRequest);
  $loginError = "";
  if ($loginFlow !== null) {
    $_SESSION ["menue"] = "LOGIN";
  }
  $identitySelected = false;
  if (isset ($loginData ["login_identity"]) && is_string ($loginData ["login_identity"])) {
    $selectedIdentity = estab_auth_decode_identity_token ($loginData ["login_identity"], is_array ($conf_empf) ? $conf_empf : array ());
    if (is_array ($selectedIdentity)) {
      $loginData = array_replace ($loginData, $selectedIdentity);
      $identitySelected = true;
      $loginFlow = "existing";
      $_SESSION ["menue"] = "LOGIN";
    }
  }

  $menuename = "";
  $menuekuerzel = "";
  $menuefunktion = "";
  if (isset ($loginData ["benutzer"]) && is_string ($loginData ["benutzer"])) { $menuename = $loginData ["benutzer"]; }
  if (isset ($loginData ["kuerzel"]) && is_string ($loginData ["kuerzel"])) { $menuekuerzel = $loginData ["kuerzel"]; }
  if (isset ($loginData ["funktion"]) && is_string ($loginData ["funktion"])) { $menuefunktion = $loginData ["funktion"]; }

  $doppelkennwort = $loginFlow === "new";
  $hasLoginIdentity = isset ($loginData ["benutzer"], $loginData ["kuerzel"], $loginData ["funktion"]);
  $hasPassword = isset ($loginData ["kennwort1"]) && is_string ($loginData ["kennwort1"]) && $loginData ["kennwort1"] !== "";
  $requiresConfirmation = $loginFlow === "new";
  $confirmationMatches = !$requiresConfirmation
    || (isset ($loginData ["kennwort1"], $loginData ["kennwort2"])
        && is_string ($loginData ["kennwort1"])
        && is_string ($loginData ["kennwort2"])
        && hash_equals ($loginData ["kennwort1"], $loginData ["kennwort2"]));

  if ($hasLoginIdentity && $hasPassword && ($_SESSION ["menue"] ?? "") === "LOGIN") {
    if ($loginFlow === null) {
      $loginError = "Wählen Sie zuerst, ob Sie ein bestehendes Konto verwenden oder ein neues Konto anlegen möchten.";
    } elseif (!$confirmationMatches) {
      $loginError = "Die beiden Kennwörter stimmen nicht überein.";
      $doppelkennwort = true;
    } else {
      $error = check_save_user ($loginData, $loginError);
      if (!$error) {
        $_SESSION ["menue"] = "ROLLE";
        $_SESSION ["estab_pending_navigation_key"] =
          $loginDestination ?? "messages";
        estab_navigation_open_after_login ("command-post");
      }
    }
  }

  $gesprnotizsichter = false ; // false Voreinstellung fuer dieses Skript

/**********************************************************************
  Daten kommen vom Formular zurueck und koennen gespeichert bzw.
  verarbeitet werden.
  checkandsave befindet sich in data_hndl.php
***********************************************************************/

  // Abbruch der Gesprächsnotiz beim Sichten
  if ( !empty ($returnValue["abbrechen_x"]) and !empty ($_SESSION ["gesprnoti"]) ){
    unset ( $_SESSION ['gesprnoti'] );
  }


  $workflowTaskSubmitted = isset ($returnValue ["absenden_x"])
    || isset ($returnValue ["zurueckweisen_x"])
    || isset ($returnValue ["transport_nicht_moeglich_x"])
    || isset ($returnValue ["transport_nicht_moeglich_y"])
    || isset ($returnValue ["antwort_x"])
    || isset ($returnValue ["weiterleiten_x"]);

  if ( $workflowTaskSubmitted and ( ( $returnValue["task"] == "Stab_schreiben" ) or
         ( $returnValue["task"] == "Stab_korrigieren" ) or
         ( $returnValue["task"] == "Stab_gesprnoti" ) or
         ( $returnValue["task"] == "LdF-Eingang" ) or
         ( $returnValue["task"] == "LdF-Ausgang" ) or
         ( $returnValue["task"] == "FM-Ausgang" ) or
         ( $returnValue["task"] == "FM-Eingang" ) or
         ( $returnValue["task"] == "FM-Eingang_Anhang" ) or
	         ( $returnValue["task"] == "Stab_sichten" ) ) ) {
    $returndata = $returnValue;

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> Daten kommen vom Formular und können gespeichert werden";  echo "<br>\n";}

    if ( ( ($returnValue ["11_gesprnotiz"] ?? "") == "on" ) and
         ( !$_SESSION ["gesprnoti"] ) and
         ( $returnValue ["task"] == "Stab_schreiben" ) ){
        // Bei GesprÃ¤chsnotiz 2. Vorlage beim Verfasser fÃ¼r Sichtung

        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> ### Gesprächsnotiz == 2. Sichtung<br>\n";}

        $formdata = $returnValue ;
        $formdata ["01_zeichen"]      = $_SESSION ["vStab_kuerzel"];
        $formdata ["11_gesprnotiz"]   = "t";
        $formdata ["13_abseinheit"] = $activeCommandPostName;
        $formdata ["14_zeichen"]      = $_SESSION ["vStab_kuerzel"];
        $formdata ["14_funktion"]     = $_SESSION ["vStab_funktion"];
        $formdata ["16_empf"]         = $redcopy2."_rt,".$_SESSION ["vStab_funktion"]."_gn" ;
        $formdata ["15_quitdatum"]    = "";
        $formdata ["15_quitzeichen"]  = "";
        $formdata ["task"]            = "Stab_gesprnoti";
        $form = new nachrichten4fach ($formdata, "Stab_gesprnoti", "");
        $_SESSION ["gesprnoti"] = true;
        $gesprnotizsichter = true ;
    } else {

      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### 369 check and save";  echo "<br>\n";}

      try {
        check_and_save ($returndata, $activeCommandPostName);
      } catch (EstabReadPermissionException $exception) {
        // Attachment filenames are object identifiers. A forged selection is
        // indistinguishable from any other forbidden operational object.
        estab_workflow_forbid ();
      }

      // verhindert das erneute Speichern bei Betätigung von F5
      if (isset ($_SESSION ['gesprnoti'])) { unset ( $_SESSION ['gesprnoti'] ); }
      if (create_vordrucke){
      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> ";}
        include ("../4fbak/backup.php");
      }

      if ( !$weiterantwort ){
        resetframeset ();
      }
    }
  } elseif ( ( in_array (
               $returnValue["task"],
               array (
                 "Stab_korrigieren", "Stab_sichten",
                 "FM-Ausgang",
                 "LdF-Eingang", "LdF-Ausgang"
               ),
               true
             ) ) and
             ($returnValue ["abbrechen_x"]) ) {

      /************************************************************************\
FM-Ausgang (Sichter) abgebrochen
      \************************************************************************/

       if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> Nachrichtentransport abgebrochen";  echo "<br>\n";}

       if (in_array (
         $returnValue ["task"],
         array ("Stab_korrigieren", "Stab_sichten"),
         true
       )) {
         // These stages use an atomic compare-and-transition on submit and
         // hold no long-lived record lock to release on cancellation.
         $returnValue ["task"] = "";
         $returnValue ["stab"] = "";
         $returnValue ["sichter"] = "";
       } else {
         $lockConnection = estab_message_connect ($conf_4f_db);
         try {
         $cancelIsLead = str_starts_with (
           $returnValue ["task"],
           "LdF-"
         );
         $cancelDirection = $returnValue ["task"] === "LdF-Eingang"
           ? "E"
           : "A";
         if (!estab_message_release_operator_stage_lock (
           $lockConnection,
           $conf_4f_tbl ["nachrichten"],
           $returnValue ["00_lfd"],
           $cancelDirection,
           $cancelIsLead ? 1 : 2,
           $workflowIdentity ["kuerzel"]
         )) {
           throw new RuntimeException ("Message lock release lost its target");
         }
         } finally {
           estab_auth_close ($lockConnection);
         }
         if ($cancelIsLead) {
         // Return to the LdF disposition queue after releasing the exact
         // stage lock. Keeping the submitted task would suppress the queue
         // renderer and leave the main frame empty.
         $returnValue ["task"] = "";
         $returnValue ["ldf"] = "";
         }
       }
  }

/**********************************************************************\
Daten kommen vom Formular und sollen als Antwort dienen.
\**********************************************************************/

// A N T W O R T
  $staffAnswerRequested = isset ($returnValue ["antwort_x"])
    && $returnValue ["task"] == "Stab_lesen";
  $staffForwardRequested = isset ($returnValue ["weiterleiten_x"])
    && $returnValue ["task"] == "Stab_lesen";
  if ($staffAnswerRequested && $staffForwardRequested) {
    estab_workflow_forbid ();
  }
  if ($staffAnswerRequested) {
//  A N T W O R T  --  "Stab_lesen"

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### Antwort <br> ";}

    if (!is_array ($objectMessage)) {
      estab_workflow_forbid ();
    }
    $formdata = $objectMessage;
    $formdata ["10_anschrift"] =  $formdata ["13_abseinheit"]."  ".$formdata["14_funktion"];
    $formdata ["13_abseinheit"] = $activeCommandPostName;
    $formdata = array_replace (
      $formdata,
      estab_message_followup_contact_fields ($objectMessage, "AW")
    );
    $formdata ["12_abfzeit"] = "" ;
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\"".$formdata ["12_inhalt"]."\"\n";
    $formdata ["04_richtung"] = "";
    $formdata ["04_nummer"] = "";
    $formdata = estab_message_followup_new_record ($formdata);
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }

// Weiterleitung
  if ($staffForwardRequested) {
    if (
      !is_array ($objectMessage)
      || !in_array (
        (string) ($objectMessage ["04_richtung"] ?? ""),
        array ("E", "A"),
        true
      )
    ) {
      estab_workflow_forbid ();
    }

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> ### 225 WEITERLEITUNG - Stab_lesen";  echo "<br>\n";}

    $formdata = $objectMessage;
    // W E I T E R L E I T U N G  --  "Stab_lesen" ---- "E" Anschrift frei; Absender normal
    $formdata ["10_anschrift"] = "";
    $formdata = array_replace (
      $formdata,
      estab_message_followup_contact_fields ($objectMessage, "WG")
    );
    $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\"".$formdata ["12_inhalt"]."\"\n";
    $formdata ["04_richtung"] = "";
    $formdata ["04_nummer"] = "";
    $formdata ["11_gesprnotiz"] = "";
    $formdata ["13_abseinheit"] = $activeCommandPostName;
    $formdata ["12_abfzeit"] = "" ;
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $formdata = estab_message_followup_new_record ($formdata);
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }

   if (isset ($_SESSION ["sw_data"] )) {

     $formdata = $_SESSION ["sw_data"] ;
    if  (( isset ($formdata["antwort_x"]) ) and
        ( $formdata["task"] == "FM-Ausgang" ) ) {

      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### antwort_x und FM_Ausgang(_Sichter) <br>\n";}
//  A N T W O R T  --  "FM-Ausgang"
      $aushilfe = $formdata ["10_anschrift"];
      $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
      $formdata ["10_anschrift"] =  $formdata ["13_abseinheit"]."  ".$formdata["14_funktion"];
      $formdata ["13_abseinheit"] = $aushilfe ;
      $formdata = array_replace (
        $formdata,
        estab_message_followup_contact_fields ($formdata, "AW")
      );
      $formdata ["12_abfzeit"] = "" ;
      $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\"".$formdata ["12_inhalt"]."\"\n";
      $formdata ["04_richtung"] = "";
      $formdata ["04_nummer"] = "";
      $formdata ["02_zeit"] = "";
      $formdata ["02_zeichen"] = "";
      $formdata ["03_datum"] = "";
      $formdata ["03_zeichen"] = "";
      unset ($_SESSION ["sw_data"]);
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";}

      $formdata = estab_message_followup_new_record ($formdata);
      $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
    }

	 if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### Weiterleitung <br>";} 

    if ( (isset ($formdata["weiterleiten_x"])) and
         ($formdata["task"] == "FM-Ausgang") ) {

// W E I T E R L E I T U N G  --  "FM-Ausgang"

      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> WEITERLEITUNG - FM-Ausgang(_Sichter)";  echo "<br>\n";}

      $aushilfe = $formdata ["10_anschrift"];
      $formdata ["10_anschrift"] =  $formdata ["13_abseinheit"]."  ".$formdata["14_funktion"];
      $formdata ["13_abseinheit"] = $aushilfe ;
      $formdata = array_replace (
        $formdata,
        estab_message_followup_contact_fields ($formdata, "WG")
      );
      $formdata ["12_abfzeit"] = "" ;
      $formdata ["12_inhalt"] = "Zitat: von ".$formdata["04_richtung"]." ".$formdata["04_nummer"]." \n\n\"".$formdata ["12_inhalt"]."\"\n\n";
      $formdata ["04_richtung"] = "";
      $formdata ["04_nummer"] = "";

      unset ($_SESSION ["sw_data"]);

      $formdata = estab_message_followup_new_record ($formdata);
      $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
    }
  } //  if (isset ($_SESSION ["sw_data"] ))


/**********************************************************************
 Voreinstellung fuer das Menue
***********************************************************************/
if  ((isset ($_SESSION ["vStab_benutzer"])) AND
   (!(isset ($returnValue["m2auswahl"]))) ) {
   $mode = 2;
}

$formdata = array (); // setze die Formulardaten zurueck

/**********************************************************************\
  --- S T A B  s c h r e i b e n ---
  Hier werden die Angaben:
    Abfasszeit, Absendeeinheit, Zeichen des Verfassers, Funktion
  der Stabsfunktion im Formular voreingestellt.
\**********************************************************************/

  if ( (isset ( $returnValue["stab_schreiben_x"] )) and !$gesprnotizsichter ) {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>  _GET[stab_schreiben_x] )) and !gesprnotizsichter ";  echo "<br>\n";}
    $formdata ["13_abseinheit"] = $activeCommandPostName;
    $formdata ["14_zeichen"]     = $_SESSION["vStab_kuerzel"];
    $formdata ["14_funktion"]    = $_SESSION["vStab_funktion"];
    $form = new nachrichten4fach ($formdata, "Stab_schreiben", "");
  }




/**********************************************************************\
  --- S T A B   l e s e n  ---

  Menue und Liste
\**********************************************************************/
   if ( ( ($_SESSION ["vStab_rolle"] == "Stab" ) or
          ($_SESSION ["ROLLE"] == "Stab" ) or
          ($_SESSION ["vStab_rolle"] == "FB" ) or
          ($_SESSION ["ROLLE"] == "FB" )
         ) and
        (
          ( $_SESSION ["vStab_funktion"] != "Si"
           ) and
          ( $returnValue ["stab"] != "meldung"
           ) and
          ( !(isset ($returnValue ["m2_benutzer_x"]
           )
         ) and
        (
          ( !(isset ($returnValue ["stab_schreiben_x"] ) ) ) and
          ( !$gesprnotizsichter ) and
          ( !(isset ($returnValue ["stab_anhang_x"] ) ) ) and
          ( !(isset ($returnValue ["fm_anhang_x"] ) ) ) and
          ( !(isset ($returnValue ["ah_auswahl_x"] ) ) ) and
          ( !(isset ($returnValue["m2_abmelden_x"] ) ) ) and
          ( !(isset ($returnValue["antwort_x"] ) ) ) and
          ( !(isset ($returnValue["weiterleiten_x"] ) ) ) and
          ( !(isset ($_SESSION["sw_data"] ) ) ) and
          ( $_SESSION ["UPLOAD"] != "fileupload" ) and
          ( $_SESSION ["UPLOAD"] != "upload" )
         )))
         ) {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>  Stab_lesen - Menue und Liste ";  echo "<br>\n";}

     $csskatego = "html { font-size: 100%; }
                a:link, a:visited, a:active {    text-decoration:    none;    color:              #333399; }
                a:hover { text-decoration:    underline; color:              #cc0000; }
                a:img {   border:             0; } 
        /******************************************************************************/
        /* specific elements */
        /* topmenu */
        ul#topmenu { font-weight:bold; list-style-type:none; margin:0; padding:0; }
        ul#topmenu li { float:left; margin:0; padding:0; vertical-align: middle; }
          #topmenu img {vertical-align:middle; margin-right:0.1em; }

        /* default tab styles */
        .tab,
        .tabcaution,
        .tabactive {display: block; margin: 0.2em 0.2em 0 0.2em; padding: 0.2em 0.2em 0 0.2em; white-space: nowrap; }

        /* disabled tabs */
        span.tab {color: #666666; }

        /* disabled drop/empty tabs */
        span.tabcaution { color: #ff6666; }

        /* enabled drop/empty tabs */
        a.tabcaution {color:  #FF0000;  }
        a.tabcaution:hover { color: #FFFFFF; background-color:   #FF0000; }

        #topmenu { margin-top: 0.5em; padding: 0.1em 0.3em 0.1em 0.3em; }

ul#topmenu li {
    border-bottom:      1pt solid black;
}

/* default tab styles */
.tab, .tabcaution, .tabactive {
    background-color:   #E5E5E5;
    border:             1pt solid #D5D5D5;
    border-bottom:      0;
    border-top-left-radius: 0.4em;
    border-top-right-radius: 0.4em;
}

/* enabled hover/active tabs */
a.tab:hover,
a.tabcaution:hover,
.tabactive,
.tabactive:hover {
    margin:             0;
    padding:            0.2em 0.4em 0.2em 0.4em;
    text-decoration:    none;
}

a.tab:hover,
.tabactive {
    background-color:   #ffffff;
}

/* to be able to cancel the bottom border, use <li class=\"active\"> */
ul#topmenu li.active {
     border-bottom:      1pt solid #ffffff;
}";


      pre_html ("stabliste", "Stab lesen ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"], $csskatego); // Normaler Seitenaufbau mit Auffrischung
      echo "<body bgcolor=\"#DCDCFF\">";
      $list = new listen ("Stab_lesen", "");
      $list->createlist ();
   }

/**********************************************************************\
  --- S T A B   M e l d u n g   l e s e n ---

  Darstellung der Meldung ber die laufende Nummer
\**********************************************************************/
  if (( $returnValue["stab"] == "meldung")){

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> Stab Meldung lesen - Darstellung der Meldung über die laufende Nummer ";  echo "<br>\n";}

    set_msg_read ($returnValue["00_lfd"]);
    $formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
    $staffCanCorrect = is_array ($workflowIdentity)
      && estab_message_object_allowed (
        $workflowIdentity,
        "staff-correction",
        $formdata
      );
    $form = new nachrichten4fach (
      $formdata,
      $staffCanCorrect ? "Stab_korrigieren" : "Stab_lesen",
      ""
    );
  }


/**********************************************************************\
  --- S i c h t e r   M e l d u n g   s i c h t e n ---

  Darstellung der Meldung ueber die laufende Nummer
\**********************************************************************/
  if (( $returnValue["sichter"] == "meldung")){

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
    $formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
    $formdata ["15_quitzeichen"]  = $_SESSION ["vStab_kuerzel"];
    $form = new nachrichten4fach ($formdata, "Stab_sichten", "");
  }

/***************************************************************************************************/
// 2. Sichtungsliste zurücksetzen, wenn sichten, FM_Ausgang,  angeklickt wurde
   if (((isset($returnValue['stab_sichten_x'])) AND 
            ($_SESSION ['si_zweite_sichtung'] == 1 )))			{ 
	  if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br> reset SI 2. sichtung </b><br>";  }	
	  $_SESSION ["si_zweite_sichtung"] = 0 ;
	}
	
	if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }	
	if (((isset($returnValue['fm_ausgang_x'])) AND 
            ($_SESSION ['fm_zweite_sichtung'] == 1 )))			{ 
	  if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ." reset FM 2. sichtung </b><br>";  }	
	  $_SESSION ["fm_zweite_sichtung"] = 0 ;
	}
	
/***************************************************************************************************/  


/**********************************************************************\
  --- S i c h t e r   l e s e n  ---

  Menue und Liste
\**********************************************************************/
   if ( ( ($_SESSION ["vStab_rolle"] == "Stab") or
          ($_SESSION ["ROLLE"] == "Stab") ) and
        ( $_SESSION ["vStab_funktion"] == "Si" ) and
        ( !($returnValue["sichter"] == "meldung") ) and
        ( !(isset($returnValue["si_admin_x"])) ) and
        ( !(isset ($returnValue ["m2_abmelden_x"]) ) and 
        ( ($returnValue["fm"] != "SI-Adminmeldung") AND ($returnValue["fm"] != "SI-Adminmeldung") ) )
		and ($_SESSION ['si_zweite_sichtung'] == 0 )
      )
      {

    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ." Sichter lesen - Menue und liste </b><br>\n";}

     $css = "a:link { color:#000000; text-decoration:none; font-weight:normal; }\n".
            "a:visited { color:#EE0000; text-decoration:none; font-weight:normal; }\n".
            "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:normal; }\n".
            "a:active { color:#0000EE; background-color:#FFFF99; }\n".
            "a:focus { color:#0000EE; background-color:#FFFF99;  }";
        pre_html (
          "siliste",
          "Sichterliste ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"],
          $css
        ); // Normaler Seitenaufbau mit Auffrischung
        echo "<body bgcolor=\"#DCDCFF\">";
        $list = new listen ("Stab_sichten", "STSI", $workflowIncidentId);
        $list->createlist ();
   }



/**********************************************************************\
  --- L d F   D i s p o s i t i o n ---

  Eingänge: Rufname in einen eindeutigen Absender übersetzen.
  Ausgänge: Gegenstelle und verbindlichen Beförderungsweg festlegen.
\**********************************************************************/
  if (
    is_array ($workflowIdentity)
    && estab_workflow_is_telecommunications_lead ($workflowIdentity)
    && $returnValue ["ldf"] !== "meldung"
    && $returnValue ["task"] === ""
    && !isset ($returnValue ["m2_abmelden_x"])
  ) {
    $css = "a:link { color:#000000; text-decoration:none; font-weight:normal; }\n".
           "a:visited { color:#EE0000; text-decoration:none; font-weight:normal; }\n".
           "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:normal; }\n".
           "a:active { color:#0000EE; background-color:#FFFF99; }\n".
           "a:focus { color:#0000EE; background-color:#FFFF99; }";
    pre_html (
      "ldfliste",
      "LdF-Disposition ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"],
      $css
    );
    echo "<body bgcolor=\"#DCDCFF\">";
    $list = new listen ("LDF", "", $workflowIncidentId);
    $list->createlist ();
  }

  if ($returnValue ["ldf"] === "meldung") {
    $lockConnection = estab_message_connect ($conf_4f_db);
    try {
      $leadCandidate = estab_message_fetch_for_incident_by_id (
        $lockConnection,
        $conf_4f_tbl ["nachrichten"],
        $returnValue ["00_lfd"],
        $workflowIncidentId
      );
      if (!is_array ($leadCandidate)) {
        throw new RuntimeException ("LdF message not found");
      }
      $leadDirection = (string) ($leadCandidate ["04_richtung"] ?? "");
      $lockAcquired = estab_message_acquire_operator_stage_lock (
        $lockConnection,
        $conf_4f_tbl ["nachrichten"],
        $returnValue ["00_lfd"],
        $workflowIdentity ["kuerzel"],
        $leadDirection,
        1
      );
      $lockedMessage = estab_message_fetch_for_incident_by_id (
        $lockConnection,
        $conf_4f_tbl ["nachrichten"],
        $returnValue ["00_lfd"],
        $workflowIncidentId
      );
    } finally {
      estab_auth_close ($lockConnection);
    }

    if ($lockAcquired && is_array ($lockedMessage)) {
      $formdata = $lockedMessage;
      $formdata ["02_zeichen"] = $_SESSION ["vStab_kuerzel"];
      $leadTask = $leadDirection === "E"
        ? "LdF-Eingang"
        : "LdF-Ausgang";
      $form = new nachrichten4fach ($formdata, $leadTask, "");
    } else {
      $lockOwner = is_array ($lockedMessage)
        ? (string) ($lockedMessage ["x03_sperruser"] ?? "")
        : "";
      echo "<big><big><big>Datensatz ist im Zugriff von <b>".
           estab_message_html ($lockOwner)."!</b><br></big></big></big>";
      echo "<p>Freigabe nur nach Prüfung durch die Leitung.</p>";
      echo "<form method=\"post\" action=\"./mainindex.php\" target=\"_self\">";
      echo estab_csrf_field ();
      echo "<input type=\"hidden\" name=\"reset_record\" value=\"".
           estab_auth_html ($returnValue ["00_lfd"])."\">";
      echo "<button type=\"submit\">Datensatz freigeben</button></form>";
    }
  }

/**********************************************************************\
  --- F e r n m e l d e r   E i n g a n g  ---
      Es wurde der "Eingang"-Button beim Fernmelder betÃ¤tigt.
\**********************************************************************/
  if (isset ($returnValue["fm_eingang_x"])){
    if ($_SESSION["fm_zweite_sichtung"] == 1){$_SESSION["fm_zweite_sichtung"] = 0;}
    if ( debug == true ){ echo "### 730 Fernmelder Eingang ";  echo "<br>\n";}

//    if ($pre_01medium != "") { $formdata ["01_medium"]   = $pre_01medium;}

    $formdata ["01_zeichen"]  = $_SESSION ["vStab_kuerzel"];
    $formdata ["10_anschrift"] = $activeCommandPostName;

    $form = new nachrichten4fach ($formdata, "FM-Eingang", "");
  }


/**********************************************************************\
  --- F M   A u s g a n g  ---

  Menue und Liste
\**********************************************************************/
 if ( ( ($_SESSION ["vStab_rolle"] == "Fernmelder") or
          ($_SESSION ["ROLLE"] == "Fernmelder")
        ) and
        ( $_SESSION ["vStab_funktion"] == "A/W"
        ) and
        !( ( isset ($returnValue ["fm_anhang_x"])  ) OR
           ( isset ($returnValue ["ah_upload_x"])  ) OR
           ( isset ($returnValue ["ah_auswahl_x"]) ) OR
		   ($_SESSION ["fm_zweite_sichtung"] == 1)
        ) and
        (!isset ($returnValue["m2_abmelden_x"])) and
        (!isset ($returnValue["fm_eingang_x"]) ) and
        (!isset ($returnValue["fm_admin_x"])   )and
        (!isset ($returnValue["etb_eintrag_x"])) and
        (!isset ($returnValue["antwort_x"])) and
        (!(isset ($_SESSION["sw_data"]))) and
        ( ($returnValue["fm"] != "FM-Adminmeldung") )and
        ( ($returnValue["fm"] != "SI-Adminmeldung") ) and
        ( ($returnValue["fm"]  != "FM-Adminmeldung") )and
        ( ($returnValue["fm"]  != "SI-Adminmeldung") ) and
        ( $returnValue["fm"]  != "meldung"
        )
      ) {
    
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### FM Ausgang - Menue und Liste <br>\n";}

    $css = "a:link { color:#000000; text-decoration:none; font-weight:normal; }\n".
          "a:visited { color:#EE0000; text-decoration:none; font-weight:normal; }\n".
          "a:hover { color:#EE0000; text-decoration:none; background-color:#FFFF99; font-weight:normal; }\n".
          "a:active { color:#0000EE; background-color:#FFFF99; }\n".
          "a:focus { color:#0000EE; background-color:#FFFF99;  }";
    pre_html ("fmdliste","FMD Ausgang ".$conf_4f ["Titelkurz"]."".$conf_4f ["Version"],$css); // Normaler Seitenaufbau mit Auffrischung
    echo "<body bgcolor=\"#DCDCFF\">";
    $list = new listen ("FMA", "", $workflowIncidentId);
    $list->createlist ();
  }


/**********************************************************************\
  FM-Ausgang-Sichter
if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### FM Ausgang - Menue und Liste <br>\n";}
\**********************************************************************/
  if ( $returnValue["fm"] == "meldung" ){
  	
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ### FM - Ausgang  ";  echo "<br>\n";}

    $lockConnection = estab_message_connect ($conf_4f_db);
    try {
      $lockAcquired = estab_message_acquire_operator_stage_lock (
        $lockConnection,
        $conf_4f_tbl ["nachrichten"],
        $returnValue ["00_lfd"],
        $workflowIdentity ["kuerzel"],
        "A",
        2
      );
      $lockedMessage = estab_message_fetch_for_incident_by_id (
        $lockConnection,
        $conf_4f_tbl ["nachrichten"],
        $returnValue ["00_lfd"],
        $workflowIncidentId
      );
    } finally {
      estab_auth_close ($lockConnection);
    }

    if ($lockAcquired && is_array ($lockedMessage)) {
      // Jetzt holen wir uns den kompletten, gesperrten Eintrag.
      $formdata = $lockedMessage;
      // Voreinstellungen fuer den Befoerderungsvermerk
      $formdata ["03_zeichen"]  = $_SESSION ["vStab_kuerzel"];

		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>  ### <BR>";var_dump ($conf_4f);echo " <br>\n";}

      $form = new nachrichten4fach ($formdata, "FM-Ausgang", "");
    } else {
      $lockOwner = is_array ($lockedMessage)
        ? (string) ($lockedMessage ["x03_sperruser"] ?? "")
        : "";
      if ($_SESSION ["vStab_kuerzel"] !== $lockOwner) {
        // Kruezel sind gleich
        echo "<big><big><big>Datensatz ist im Zugriff von <b>".
             estab_message_html ($lockOwner)."!</b><br></big></big></big>";
        echo "<br><br><br><br><br><br>";
        echo "!!!Achtung!!!<br>";
        echo "Datensatzfreischaltung nur auf Anordnung des Betriebsstellenleiters.<br>";
        echo "<form method=\"post\" action=\"./mainindex.php\" target=\"_self\">";
        echo estab_csrf_field ();
        echo "<input type=\"hidden\" name=\"reset_record\" value=\"".
             estab_auth_html ($returnValue ["00_lfd"])."\">";
        echo "<button type=\"submit\" style=\"border:0;background:transparent;padding:0\">".
             "<img src=\"./createbutton.php?icontext=Datensatz%20freigeben&amp;color=red\" ".
             "alt=\"Datensatz freigeben\"></button></form>";
      }
    }
  }  //  if ( $returnValue["fm"] == "meldung" 

 
/**********************************************************************\
   2. SICHTUNG

\**********************************************************************/
	
  if ( (isset ( $returnValue["fm_admin_x"] ) OR ( $_SESSION ["fm_zweite_sichtung"] == 1 ) )
    and is_array ($workflowSelectedIdentity)
    and (($workflowSelectedIdentity ["funktion"] ?? null) === "A/W")
    and (($workflowSelectedIdentity ["rolle"] ?? null) === "Fernmelder")
    and ($returnValue ["fm"] != "FM-Adminmeldung" )
    and ($returnValue ["fm"] != "SI-Adminmeldung" ) ) {

		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ###  FM Admin <br>";  }
		
		$_SESSION ["fm_zweite_sichtung"] = 1 ;
        pre_html (
          "N",
          "Zweite Sichtung ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"],
          "",
          true
        );
        $secondSightingRecipients =
          estab_workflow_message_list_recipients ($conf_empf);
        $secondSightingFilters = estab_workflow_message_list_filters (
          "estab_message_second_sighting_aw_filters",
          $returnValue,
          $secondSightingRecipients
        );
        echo "<body class=\"estab-tool-page\">\n<!-- 2. Sichtung -->\n";
        echo "<main class=\"estab-tool-main estab-tool-main-wide\" ".
             "data-estab-second-sighting=\"aw\">\n";
        echo "<header class=\"estab-tool-hero\">";
        echo "<p class=\"estab-tool-eyebrow\">A/W · Nachrichtenvordrucke</p>";
        echo "<h1>Zweite Sichtung</h1>";
        echo "<p>Durchsuchen und öffnen Sie die für Ihre aktuelle ".
             "Dienstfunktion sichtbaren Nachrichten des aktiven Einsatzes.</p>";
        echo "</header>";
        $list = new listen (
          "FMADMIN",
          "",
          $workflowIncidentId,
          $secondSightingFilters
        );
        $list->createlist ();
        echo "</main>\n";
  }

  if ( (isset ( $returnValue["si_admin_x"] ) OR ( $_SESSION ["si_zweite_sichtung"] == 1 ) )
      and is_array ($workflowSelectedIdentity)
      and (($workflowSelectedIdentity ["funktion"] ?? null) === "Si")
      and (($workflowSelectedIdentity ["rolle"] ?? null) === "Stab")
      and ($returnValue ["fm"] != "SI-Adminmeldung" ) )  {
    	
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ###  Sichter Admin ";  echo "<br>\n";}

    	$_SESSION ["si_zweite_sichtung"] = 1 ;
        pre_html (
          "N",
          "Zweite Sichtung ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"],
          "",
          true
        );
        $secondSightingRecipients =
          estab_workflow_message_list_recipients ($conf_empf);
        $secondSightingFilters = estab_workflow_message_list_filters (
          "estab_message_second_sighting_si_filters",
          $returnValue,
          $secondSightingRecipients
        );
        echo "<body class=\"estab-tool-page\">";
        echo "<main class=\"estab-tool-main estab-tool-main-wide\" ".
             "data-estab-second-sighting=\"si\">\n";
        echo "<header class=\"estab-tool-hero\">";
        echo "<p class=\"estab-tool-eyebrow\">Si · Nachrichtenvordrucke</p>";
        echo "<h1>Zweite Sichtung</h1>";
        echo "<p>Durchsuchen und öffnen Sie die für Ihre aktuelle ".
             "Dienstfunktion sichtbaren Nachrichten des aktiven Einsatzes.</p>";
        echo "</header>";
        $list = new listen (
          "SIADMIN",
          "",
          $workflowIncidentId,
          $secondSightingFilters
        );
        $list->createlist ();
        echo "</main>\n";
	   }

/**********************************************************************\
Nachricht als Sichtung anzeigen

\**********************************************************************/
  if ( ( $returnValue["fm"] == "FM-Adminmeldung" ) OR
       ( $returnValue["fm"] == "SI-Adminmeldung" )) {

		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>   ###  FM & Si Adminmeldung <br>\n";}

		$formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
		if (isset ($returnValue["fm"])) {$fm = $returnValue["fm"]; }
		if (isset ($returnValue ["fm"])) {$fm = $returnValue ["fm"]; }
    	switch  ($fm) {
      	case "FM-Adminmeldung" :
        		$form = new nachrichten4fach ($formdata, "FM-Admin", "");
      	break;

      	case "SI-Adminmeldung" :
        		$form = new nachrichten4fach ($formdata, "SI-Admin", "");
      	break;
    	}
  }


/**********************************************************************\
   A B M E L D E N
\**********************************************************************/
  if (isset ($returnValue["m2_abmelden_x"])) {
    if ( debug == true ){ echo "### 907 m2_abmelden_x ";  echo "<br>\n";}

     // Compatibility for the historical image button. New UI controls use
     // logout.php and a 303 redirect that also works from standalone tabs.
     estab_logout_current_session (
       $conf_4f_db,
       $conf_4f_tbl ["benutzer"],
       $conf_4f_tbl ["protokoll"],
       $_SERVER
     );
     include_once ("./logoff.php");

     resetframeset ();
     exit;

  } // isset ($returnValue["m2_abmelden_x"]


  /**********************************************************************\

  \**********************************************************************/
  if ($_SESSION ["menue"] == "LOGIN" or $_SESSION ["menue"] == "WELCOME" ) {
    $registrationAllowed = estab_auth_self_registration_allowed ();
    $loginAction = estab_auth_html ($conf_4f ["MainURL"]);
    $loginDestinationField = estab_navigation_login_destination_field (
      $loginDestination
    );
    echo "<!doctype html>\n";
    echo "<html lang=\"de\">\n";
    echo "<head>\n";
    echo "<meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\" />\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<link rel=\"stylesheet\" href=\"../estab-ui.css\">\n";
    echo "<title>eStab – Anmeldung</title>\n";
    echo "</head>\n";
    echo "<body bgcolor=\"#DCDCFF\">\n";
    echo "<main class=\"estab-auth-shell\">\n";
    echo "<section class=\"estab-auth-card\" aria-labelledby=\"estab-auth-title\">\n";
    echo "<h1 id=\"estab-auth-title\">eStab-Funktionskonto</h1>\n";
    echo "<p class=\"estab-auth-help\"><strong>".
         estab_auth_html ($conf_4f ["Titelkurz"].$conf_4f ["SubTitel"]["env"]).
         "</strong><br>Version ".estab_auth_html ($conf_4f ["Version"]).
         " · ".estab_auth_html ($conf_4f ["Stelle"])."</p>\n";
    echo "<p class=\"estab-auth-exit\"><a class=\"estab-button\" ".
         "data-estab-auth-cancel href=\"".
         estab_auth_html (estab_application_root ()).
         "\" target=\"_top\">Anmeldung abbrechen · Zur Übersicht</a></p>\n";
    if ($loginInterrupted) {
      echo "<div class=\"estab-auth-error\" ".
           "data-estab-submission-discarded role=\"status\">".
           "<strong>Ihre Sitzung war nicht mehr gültig.</strong> ".
           "Die Eingabe wurde nicht gespeichert. Melden Sie sich erneut an ".
           "und erfassen Sie die Eingabe danach noch einmal.</div>\n";
    }
    if ($loginDestination !== null) {
      $loginDestinationItem = estab_navigation_item_for_key (
        $loginDestination
      );
      if (is_array ($loginDestinationItem)) {
        echo "<p class=\"estab-auth-note\" data-estab-login-destination>".
             "Nach erfolgreicher Anmeldung öffnen wir: <strong>".
             estab_auth_html ($loginDestinationItem ["label"]).
             "</strong>.</p>\n";
      }
    }

    if ($loginError !== "") {
      echo "<div class=\"estab-auth-error\" role=\"alert\" tabindex=\"-1\" autofocus>".
           estab_auth_html ($loginError)."</div>\n";
    }

    if ($loginFlow === null) {
      echo "<h2>Wie möchten Sie fortfahren?</h2>\n";
      echo "<p class=\"estab-auth-help\">Wählen Sie, ob Sie sich mit einem bestehenden Funktionskonto anmelden oder ein neues Funktionskonto erstellen.</p>\n";
      echo "<div class=\"estab-auth-actions\">\n";
      echo "<form action=\"".$loginAction."\" method=\"POST\" target=\"_self\">\n";
      echo estab_csrf_field ()."\n";
      echo $loginDestinationField."\n";
      echo "<button class=\"estab-button estab-button-primary\" type=\"submit\" name=\"login_flow\" value=\"existing\">Mit bestehendem Konto anmelden</button>\n";
      echo "</form>\n";
      if ($registrationAllowed) {
        echo "<form action=\"".$loginAction."\" method=\"POST\" target=\"_self\">\n";
        echo estab_csrf_field ()."\n";
        echo $loginDestinationField."\n";
        echo "<button class=\"estab-button\" type=\"submit\" name=\"login_flow\" value=\"new\">Neues Konto anlegen</button>\n";
        echo "</form>\n";
      } else {
        echo "<button class=\"estab-button\" type=\"button\" disabled>Neues Konto anlegen</button>\n";
      }
      echo "</div>\n";
      if (!$registrationAllowed) {
        echo "<p class=\"estab-auth-note\">Neue Konten können hier nicht erstellt werden. Die zuständige Stelle legt sie unter Administration → Benutzerverwaltung an.</p>\n";
      }
      echo "<p class=\"estab-auth-note\">Ein Funktionskonto gewährt keinen Zugang zur separaten Administration.</p>\n";
    } elseif ($loginFlow === "new" && !$registrationAllowed) {
      echo "<h2>Neues Konto anlegen</h2>\n";
      if ($loginError === "") {
        echo "<p class=\"estab-auth-error\" role=\"alert\" tabindex=\"-1\" autofocus>Neue Konten können hier nicht erstellt werden. Die zuständige Stelle legt sie unter Administration → Benutzerverwaltung an.</p>\n";
      }
      echo "<form action=\"".$loginAction."\" method=\"POST\" target=\"_self\">\n";
      echo estab_csrf_field ()."\n";
      echo $loginDestinationField."\n";
      echo "<button class=\"estab-button\" type=\"submit\" name=\"login\" value=\"Anmelden\">Zurück zur Auswahl</button>\n";
      echo "</form>\n";
    } else {
      $isRegistration = $loginFlow === "new";
      $formTitle = $isRegistration
        ? "Neues Funktionskonto anlegen"
        : "Mit bestehendem Konto anmelden";
      $formHelp = $isRegistration
        ? "Erstellen Sie ein Konto nur nach organisatorischer Freigabe. Wählen Sie die Ihnen zugeteilte Funktion; eStab leitet daraus die Rolle gemäß Empfängermatrix ab."
        : "Wählen Sie Ihr Konto aus der Liste oder geben Sie Name, Kürzel und Ihre zugeteilte Funktion ein. Verwenden Sie Ihr bestehendes Kennwort.";
      $submitLabel = $isRegistration
        ? "Konto erstellen und anmelden"
        : "Anmelden";
      $legacyConfirmation = $isRegistration ? "Yes" : "No";
      $passwordAutocomplete = $isRegistration ? "new-password" : "current-password";
      $hasLoginError = $loginError !== "";
      $nameAutofocus = !$hasLoginError && !$identitySelected ? " autofocus" : "";
      $passwordAutofocus = !$hasLoginError && $identitySelected ? " autofocus" : "";

      echo "<h2>".$formTitle."</h2>\n";
      echo "<p class=\"estab-auth-help\">".$formHelp."</p>\n";
      echo "<form action=\"".$loginAction."\" method=\"POST\" target=\"_self\">\n";
      echo "<fieldset class=\"estab-auth-form\">\n";
      echo "<legend>Zugangsdaten</legend>\n";
      echo estab_csrf_field ()."\n";
      echo $loginDestinationField."\n";
      echo "<input type=\"hidden\" name=\"login_flow\" value=\"".$loginFlow."\">\n";
      echo "<input type=\"hidden\" name=\"2teskennwort\" value=\"".$legacyConfirmation."\">\n";
      echo "<table class=\"estab-auth-fields\"><tbody>\n";
      echo "<tr><th><label for=\"estab-login-name\">Name, Vorname</label></th>\n";
      echo "<td><input id=\"estab-login-name\" name=\"benutzer\" type=\"text\" value=\"".
           estab_auth_html ($menuename)."\" maxlength=\"50\" autocomplete=\"name\" required".$nameAutofocus."></td></tr>\n";
      echo "<tr><th><label for=\"estab-login-code\">Kürzel</label></th>\n";
      echo "<td><input id=\"estab-login-code\" name=\"kuerzel\" type=\"text\" value=\"".
           estab_auth_html ($menuekuerzel)."\" minlength=\"1\" maxlength=\"6\" pattern=\"[A-Za-z0-9_]{1,6}\" autocomplete=\"username\" required></td></tr>\n";
      echo "<tr><th><label for=\"estab-login-function\">Funktion</label></th>\n";
      echo "<td><select id=\"estab-login-function\" name=\"funktion\" required>\n";
      $placeholderSelected = $menuefunktion === "" ? " selected" : "";
      echo "<option value=\"\" disabled".$placeholderSelected.">Bitte Funktion wählen</option>\n";
      for ($i=1; $i <= count ($conf_empf); $i++) {
        $selected = $menuefunktion == $conf_empf[$i]["fkt"] ? " selected" : "";
        $funktion = estab_auth_html ($conf_empf[$i]["fkt"]);
        echo "<option value=\"".$funktion."\"".$selected.">".$funktion."</option>\n";
      }
      echo "</select></td></tr>\n";
      echo "<tr><th><label for=\"estab-login-password\">Kennwort</label></th>\n";
      echo "<td><input id=\"estab-login-password\" name=\"kennwort1\" type=\"password\" maxlength=\"255\" autocomplete=\"".
           $passwordAutocomplete."\" required".$passwordAutofocus."></td></tr>\n";
      if ($isRegistration) {
        echo "<tr><th><label for=\"estab-login-password-confirm\">Kennwort wiederholen</label></th>\n";
        echo "<td><input id=\"estab-login-password-confirm\" name=\"kennwort2\" type=\"password\" maxlength=\"255\" autocomplete=\"new-password\" required></td></tr>\n";
      }
      echo "</tbody></table>\n";
      echo "<div class=\"estab-auth-actions\">\n";
      echo "<button class=\"estab-button estab-button-primary\" type=\"submit\">".$submitLabel."</button>\n";
      echo "</div>\n";
      echo "</fieldset>\n";
      echo "</form>\n";
      echo "<form action=\"".$loginAction."\" method=\"POST\" target=\"_self\">\n";
      echo estab_csrf_field ()."\n";
      echo $loginDestinationField."\n";
      echo "<button class=\"estab-button\" type=\"submit\" name=\"login\" value=\"Anmelden\">Andere Kontoaktion wählen</button>\n";
      echo "</form>\n";
    }
    echo "</section>\n";

  }


/**********************************************************************\

\**********************************************************************/
if ( ( isset ($returnValue["m2_benutzer_x"])) OR
     ( ( $_SESSION ["menue"] == "WELCOME" OR $_SESSION ["menue"] == "LOGIN" )
       AND $loginFlow !== "new" ) )
  {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><br>";  }
      benutzerstatus ("verlinkt", $loginDestination);
	  
   }

if ($_SESSION ["menue"] == "LOGIN" or $_SESSION ["menue"] == "WELCOME") {
  echo "</main>\n";
}


if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}


?>
</body>
</html>
