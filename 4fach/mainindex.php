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
require_once __DIR__ . "/../app/attachment_upload.php";
require_once __DIR__ . "/../app/self_registration.php";
require_once __DIR__ . "/../4fcfg/config.inc.php";
require_once __DIR__ . "/../4fcfg/dbcfg.inc.php";
require_once __DIR__ . "/../4fcfg/fkt_rolle.inc.php";
estab_session_ui_start ($_SESSION);

if (estab_attachment_upload_post_body_exceeded (
  $_SERVER,
  $_POST,
  $_FILES
)) {
  estab_session_ui_abort (
    $_SESSION,
    413,
    "Die ausgewählte Datei ist zu groß",
    "PHP hat den gesamten Upload vor der Verarbeitung abgewiesen. Öffnen Sie den ".
      "Nachrichtenvordruck erneut und wählen Sie eine kleinere Datei.",
    "messages"
  );
}

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
if ($workflowIdentity !== null) {
  $permissionModeConnection = null;
  try {
    $permissionModeConnection = estab_auth_connect ($conf_4f_db);
    $permissionModeIncident = estab_incident_active ($permissionModeConnection);
    if (is_array ($permissionModeIncident)) {
      estab_permission_context_set_from_incident ($permissionModeIncident);
    }
  } catch (Throwable $exception) {
    // Missing/unavailable policy remains STRICT here. The complete read gate
    // below reports the authoritative database error before rendering data.
    error_log ("eStab permission mode unavailable: ".$exception->getMessage ());
  } finally {
    if ($permissionModeConnection instanceof mysqli) {
      estab_auth_close ($permissionModeConnection);
    }
  }
}
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
    || isset ($returnValue ["stab_korrekturen_x"])
    || in_array (($returnValue ["stab"] ?? ""), array (
      "meldung", "korrektur",
    ), true)
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

require_once ("../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben
require_once ("../4fcfg/dbcfg.inc.php");     // Datenbankparameter
require_once ("../4fcfg/fkt_rolle.inc.php"); // Mitspieler
include ("protokoll.php");              // Protokolllierung in der Datenbank
include ("db_operation.php");           // Datenbank operationen
include ("4fachform.php");              // Formular Behandlung 4fach Vordruck
include ("liste.php");                  // erzeuge Ausgabelisten
include ("data_hndl.php");              // Schnittstelle zur Datenbank

/** Stop before legacy list/query code when operational access is unavailable. */
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
  echo "<section class=\"estab-auth-card\">";
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

/** Apply the strict shared list request and retain it for this function view. */
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
    estab_permission_context_set_from_incident ($readScope ["incident"]);
    // Re-evaluate the route against the same authoritative snapshot that is
    // carried into the write transaction. A concurrent LOOSE -> STRICT
    // change must never leave an already admitted foreign-role action alive.
    if (!estab_workflow_route_allowed (
      $workflowSelectedIdentity,
      (string) ($_SERVER ["REQUEST_METHOD"] ?? "GET"),
      $returnValue
    )) {
      estab_workflow_forbid ();
    }
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
      "Operativer Zugang nicht verfügbar",
      "Prüfen Sie die feste Kontofunktion und ob Ihr Zugang in der ".
      "optionalen Schichtplanung aktiviert ist."
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
      "Berechtigungsstatus nicht verfügbar",
      "Die feste Kontofunktion kann derzeit nicht geprüft werden."
    );
  } finally {
    if ($readGateConnection instanceof mysqli) {
      estab_auth_close ($readGateConnection);
    }
  }
}

// A second-sighting mode is scoped to the fixed account function. Normalise
// stale sessions after an administrative function change.
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
      || isset ($request ["message_attachment_upload_x"])
      || isset ($request ["message_attachment_upload_y"])
      || isset ($request ["message_attachment_remove_x"])
      || isset ($request ["message_attachment_remove_y"])
    );
  $operational = $submittedTask
    || isset ($request ["action"])
    || isset ($request ["reset_record"])
    || isset ($request ["stab_anhang_x"])
    || isset ($request ["stab_korrekturen_x"])
    || isset ($request ["fm_anhang_x"])
    || (string) ($request ["stab"] ?? "") === "korrektur"
    || (string) ($request ["fm"] ?? "") === "meldung"
    || (string) ($request ["ldf"] ?? "") === "meldung";
  if (!$operational) {
    return;
  }

  $connection = null;
  $incidentGateError = null;
  try {
    $connection = estab_message_connect ($databaseConfig);
    $incident = estab_incident_active ($connection);
  } catch (Throwable $exception) {
    error_log ("eStab incident input gate unavailable: ".$exception->getMessage ());
    $incidentGateError = array (
      503,
      "Einsatzstatus vorübergehend nicht verfügbar",
      "Der Einsatzstatus kann derzeit nicht geprüft werden.",
    );
  } finally {
    if ($connection instanceof mysqli) {
      estab_auth_close ($connection);
    }
  }
  if (is_array ($incidentGateError)) {
    estab_session_ui_abort (
      $_SESSION,
      $incidentGateError [0],
      $incidentGateError [1],
      $incidentGateError [2],
      "messages"
    );
  }
  if ($incident !== null) {
    return;
  }

  estab_session_ui_abort (
    $_SESSION,
    409,
    "Kein aktiver Einsatz",
    "Kein Einsatz ist aktiv. Eingaben sind gesperrt. Aktivieren Sie zuerst ".
      "einen Einsatz in der Administration.",
    "messages"
  );
}

estab_workflow_require_active_incident_for_post (
  $workflowIdentity,
  $_SERVER,
  $returnValue,
  $conf_4f_db
);

$messageAttachmentRequestToken =
  $returnValue ["message_attachment_request_token"] ?? null;
$messageAttachmentFinalSubmitRequested =
  isset ($returnValue ["absenden_x"])
  && in_array (
    (string) ($returnValue ["task"] ?? ""),
    estab_workflow_attachment_edit_tasks (),
    true
  );
$messageAttachmentPreGateReplay = null;

// A correction changes its status as part of the successful commit. Resolve
// an already completed action before the status-dependent object gate, but
// only after the token has been bound to this account, incident, task and
// record. A pending token is accepted as success only when the immutable
// event written in the same database commit proves this exact action.
if ($messageAttachmentFinalSubmitRequested) {
  if (
    !is_array ($workflowSelectedIdentity)
    || !is_int ($workflowIncidentId)
    || !is_string ($messageAttachmentRequestToken)
    || $messageAttachmentRequestToken === ""
  ) {
    estab_workflow_forbid ();
  }
  $submittedAttachmentTask = (string) $returnValue ["task"];
  $submittedAttachmentRecord = $submittedAttachmentTask === "Stab_korrigieren"
    ? ($returnValue ["00_lfd"] ?? null)
    : null;
  try {
    $messageAttachmentPreGateReplay =
      estab_attachment_direct_action_replay_result (
        $_SESSION,
        $messageAttachmentRequestToken,
        $workflowSelectedIdentity,
        $workflowIncidentId,
        $submittedAttachmentTask,
        $submittedAttachmentRecord
      );
    $preGateMode = is_array ($messageAttachmentPreGateReplay)
      ? (string) ($messageAttachmentPreGateReplay ["mode"] ?? "")
      : "";
    if ($preGateMode === "submit") {
      header ("Cache-Control: private, no-store, max-age=0");
      header ("Location: ".(string) $conf_4f ["MainURL"], true, 303);
      exit;
    }
    if ($preGateMode === "pending-submit") {
      $actionEvidenceConnection = estab_message_connect ($conf_4f_db);
      try {
        $committedActionId = estab_message_committed_action_id (
          $actionEvidenceConnection,
          $workflowIncidentId,
          $messageAttachmentRequestToken,
          $submittedAttachmentTask,
          $workflowSelectedIdentity,
          $submittedAttachmentRecord
        );
      } finally {
        estab_auth_close ($actionEvidenceConnection);
      }
      if (is_int ($committedActionId)) {
        try {
          estab_attachment_direct_action_complete (
            $_SESSION,
            $messageAttachmentRequestToken,
            is_string ($messageAttachmentPreGateReplay ["reference"] ?? null)
              ? $messageAttachmentPreGateReplay ["reference"]
              : null,
            "submit"
          );
        } catch (Throwable $exception) {
          // The immutable event is the authoritative outcome. A session
          // bookkeeping error must not turn its proven commit into a retry.
          error_log (
            "eStab durable message action replay completion failed: ".
            $exception->getMessage ()
          );
        }
        header ("Cache-Control: private, no-store, max-age=0");
        header ("Location: ".(string) $conf_4f ["MainURL"], true, 303);
        exit;
      }
    }
  } catch (EstabAttachmentContextException $exception) {
    error_log ("eStab direct attachment pre-gate replay rejected");
    estab_workflow_forbid ();
  } catch (Throwable $exception) {
    error_log (
      "eStab durable message action lookup failed: ".
      $exception->getMessage ()
    );
    estab_workflow_forbid ();
  }
}

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
    $objectAllowed = is_array ($objectMessage)
      && estab_message_object_allowed (
        $workflowSelectedIdentity,
        $messageOperation,
        $objectMessage,
        true
      )
      && (
        (
          estab_message_operation_relaxes_write_role ($messageOperation)
          && !estab_permission_role_checks_enforced ()
        )
        || estab_read_message_allowed (
          $workflowSelectedIdentity,
          $objectMessage
        )
      );
    if (!$objectAllowed) {
      if (
        $messageOperation === "staff-correction"
        && is_array ($messageAttachmentPreGateReplay)
        && ($messageAttachmentPreGateReplay ["mode"] ?? null)
          === "pending-submit"
        && is_array ($objectMessage)
        && estab_read_message_allowed (
          $workflowSelectedIdentity,
          $objectMessage
        )
      ) {
        estab_workflow_render_read_gate (
          409,
          "Korrekturstand prüfen",
          "Die ursprüngliche Korrekturanfrage ist nicht mehr eindeutig ".
          "fortsetzbar. Prüfen Sie die Nachricht in der Meldungsliste; ".
          "eStab speichert sie nicht erneut."
        );
      }
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
$messageAttachmentWriteScope = (
  $messageOperation === "staff-correction"
  && is_array ($objectMessage)
  && is_array ($workflowSelectedIdentity)
)
  ? estab_read_attachment_write_scope (
      $workflowSelectedIdentity,
      "staff-correction",
      $objectMessage
    )
  : null;

/** Rehydrate one attachment round-trip without trusting route authority. */
function estab_message_attachment_form_data (
  array $draft,
  array $context,
  ?array $originMessage,
  array $recipientMatrix,
  string $redCopyFunction,
  array $identity,
  string $commandPostName,
  bool $strictDistribution = true
): array {
  $task = (string) ($context ["task"] ?? "");
  $requiredRecipients = $task === "Stab_gesprnoti"
    ? array (
        $redCopyFunction."_rt",
        (string) ($identity ["funktion"] ?? "")."_gn",
      )
    : array ();
  $formdata = estab_attachment_origin_draft_form_data (
    $draft,
    $context,
    $originMessage,
    $recipientMatrix,
    $strictDistribution,
    $redCopyFunction,
    $requiredRecipients
  );
  if (in_array (
    $task,
    array ("Stab_schreiben", "Stab_korrigieren", "Stab_gesprnoti"),
    true
  )) {
    $formdata ["13_abseinheit"] = $commandPostName;
    $formdata ["14_zeichen"] = (string) ($identity ["kuerzel"] ?? "");
    $formdata ["14_funktion"] = (string) ($identity ["funktion"] ?? "");
    if ($task === "Stab_gesprnoti") {
      $formdata ["01_zeichen"] = (string) ($identity ["kuerzel"] ?? "");
      $formdata ["15_quitdatum"] = "";
      $formdata ["15_quitzeichen"] = "";
    }
  } elseif (in_array (
    $task,
    array ("FM-Eingang", "FM-Eingang_Anhang"),
    true
  )) {
    $formdata ["01_zeichen"] = (string) ($identity ["kuerzel"] ?? "");
    $formdata ["13_abseinheit"] = "";
    $formdata ["15_quitdatum"] = "";
    $formdata ["15_quitzeichen"] = "";
    if ((string) ($formdata ["10_anschrift"] ?? "") === "") {
      $formdata ["10_anschrift"] = $commandPostName;
    }
  }
  return $formdata;
}

/** Render the same official form after a direct attachment action. */
function estab_message_attachment_render_form (
  array $formdata,
  string $task,
  string $notice = "",
  string $error = "",
  int $status = 200
): never {
  $formdata ["estab_attachment_notice"] = $notice;
  $formdata ["estab_attachment_error"] = $error;
  $formdata ["estab_attachment_comment"] = $error === ""
    ? ""
    : (is_string ($_POST ["message_attachment_comment"] ?? null)
      ? $_POST ["message_attachment_comment"]
      : "");
  http_response_code ($status);
  header ("Cache-Control: private, no-store, max-age=0");
  $form = new nachrichten4fach ($formdata, $task, array ());
  exit;
}

/** Rebuild the second conversation-note stage for an idempotent replay. */
function estab_message_attachment_render_conversation_stage (
  array $draft,
  array $context,
  ?array $originMessage,
  array $recipientMatrix,
  string $redCopyFunction,
  array $identity,
  string $commandPostName
): never {
  $context ["task"] = "Stab_gesprnoti";
  $context ["record_id"] = null;
  $draft ["11_gesprnotiz"] = "t";
  $formdata = estab_message_attachment_form_data (
    $draft,
    $context,
    $originMessage,
    $recipientMatrix,
    $redCopyFunction,
    $identity,
    $commandPostName
  );
  $formdata ["task"] = "Stab_gesprnoti";
  estab_message_attachment_render_form (
    $formdata,
    "Stab_gesprnoti",
    "Der Übergang zur Gesprächsnotiz wurde bereits vorbereitet. Die Anlage ".
    "wurde nur einmal gespeichert."
  );
}

/** Best-effort release of an unfinished direct-upload replay token. */
function estab_message_attachment_abandon_direct_action (mixed $token): void {
  if (!isset ($_SESSION) || !is_array ($_SESSION)) {
    return;
  }
  try {
    estab_attachment_direct_action_abandon ($_SESSION, $token);
  } catch (Throwable $exception) {
    error_log (
      "eStab direct attachment token cleanup failed: ".
      $exception->getMessage ()
    );
  }
}

/**
 * Durably checkpoint one replay state before continuing the workflow.
 *
 * PHP normally writes its session only at request shutdown. Without this
 * checkpoint a worker loss immediately after the message commit could leave
 * the durable message in MariaDB while the session still knew only the old
 * issued token. Closing and immediately reopening the same session persists
 * the server-generated attachment reference while retaining the normal
 * per-session request lock for the following save.
 */
function estab_message_attachment_checkpoint_pending_action (
  mixed $token,
  array $identity,
  int $incidentId,
  string $task,
  mixed $recordId = null,
  string $expectedMode = "pending-submit"
): void {
  if (session_status () !== PHP_SESSION_ACTIVE) {
    throw new EstabAttachmentContextException (
      "Der vorgemerkte Anhangvorgang besitzt keine aktive Sitzung."
    );
  }
  $expectedSessionId = session_id ();
  if (
    !is_string ($expectedSessionId)
    || $expectedSessionId === ""
    || !session_write_close ()
    || !session_start ()
    || !hash_equals ($expectedSessionId, session_id ())
  ) {
    throw new EstabAttachmentContextException (
      "Der vorgemerkte Anhangvorgang konnte nicht dauerhaft gesichert werden."
    );
  }
  $checkpoint = estab_attachment_direct_action_replay_result (
    $_SESSION,
    $token,
    $identity,
    $incidentId,
    $task,
    $recordId
  );
  if (
    !is_array ($checkpoint)
    || ($checkpoint ["mode"] ?? null) !== $expectedMode
  ) {
    throw new EstabAttachmentContextException (
      "Der vorgemerkte Anhangvorgang ging beim Sichern verloren."
    );
  }
}

$messageAttachmentUploadRequested =
  isset ($returnValue ["message_attachment_upload_x"])
  || isset ($returnValue ["message_attachment_upload_y"]);
$messageAttachmentRemoveRequested =
  isset ($returnValue ["message_attachment_remove_x"])
  || isset ($returnValue ["message_attachment_remove_y"]);
$messageAttachmentFile = $_FILES ["message_attachment_upload"] ?? null;
$messageAttachmentFilePending = is_array ($messageAttachmentFile)
  && (($messageAttachmentFile ["error"] ?? UPLOAD_ERR_NO_FILE)
      !== UPLOAD_ERR_NO_FILE);
$messageAttachmentSubmitWithFile =
  isset ($returnValue ["absenden_x"])
  && $messageAttachmentFilePending;
// The second stage is identified by the submitted task and the one-time form
// token. A session-wide flag would let one open browser tab alter another.
$messageAttachmentConversationStageRequested =
  $messageAttachmentFinalSubmitRequested
  && (string) ($returnValue ["task"] ?? "") === "Stab_schreiben"
  && (string) ($returnValue ["11_gesprnotiz"] ?? "") === "on";
$messageAttachmentPendingSubmitCompletion = null;
$messageAttachmentReplayWithoutFile =
  !$messageAttachmentFilePending
    ? $messageAttachmentPreGateReplay
    : null;

// Browsers may retry a completed multipart submit without resending its file
// bytes. Inspect the unchanged form token before the ordinary save path so the
// same message cannot be inserted a second time without its attachment. A
// fresh issued token deliberately returns null and remains a normal no-file
// message submission.
if (
  isset ($returnValue ["absenden_x"])
  && !$messageAttachmentFilePending
  && is_string ($messageAttachmentRequestToken)
  && $messageAttachmentRequestToken !== ""
  && is_array ($workflowSelectedIdentity)
  && is_int ($workflowIncidentId)
  && in_array (
    (string) ($returnValue ["task"] ?? ""),
    estab_workflow_attachment_edit_tasks (),
    true
  )
) {
  // The pre-gate lookup already validated and inspected every final submit.
  // Keep a defensive fallback for non-final attachment actions only.
  if (!$messageAttachmentFinalSubmitRequested) {
    try {
      $messageAttachmentReplayWithoutFile =
        estab_attachment_direct_action_replay_result (
          $_SESSION,
          $messageAttachmentRequestToken,
          $workflowSelectedIdentity,
          $workflowIncidentId,
          (string) $returnValue ["task"],
          (string) $returnValue ["task"] === "Stab_korrigieren"
            ? ($returnValue ["00_lfd"] ?? null)
            : null
        );
    } catch (EstabAttachmentContextException $exception) {
      error_log ("eStab direct attachment replay rejected");
      estab_workflow_forbid ();
    }
  }
}

$messageAttachmentAction =
  $messageAttachmentUploadRequested
  || $messageAttachmentRemoveRequested
  || $messageAttachmentSubmitWithFile
  || is_array ($messageAttachmentReplayWithoutFile);

if (
  $messageAttachmentFilePending
  && !in_array (
    (string) ($returnValue ["task"] ?? ""),
    estab_workflow_attachment_edit_tasks (),
    true
  )
) {
  estab_workflow_forbid ();
}

if ($messageAttachmentAction) {
  if (
    !is_array ($workflowSelectedIdentity)
    || !is_int ($workflowIncidentId)
  ) {
    estab_workflow_forbid ();
  }
  $attachmentContext = null;
  $attachmentDraft = null;
  try {
    $attachmentContext = estab_attachment_origin_context_create (
      $workflowSelectedIdentity,
      $workflowIncidentId,
      $returnValue,
      is_array ($objectMessage) ? $objectMessage : null
    );
    $attachmentDraft = estab_attachment_origin_draft_from_request (
      $returnValue,
      $workflowSelectedIdentity,
      $attachmentContext
    );
    $attachmentDraft ["12_anhang"] =
      estab_attachment_canonical_message_references (
        $attachmentDraft ["12_anhang"] ?? ""
      );

    if (!$messageAttachmentRemoveRequested) {
      $attachmentScopeConnection = estab_message_connect ($conf_4f_db);
      try {
        estab_read_require_attachment_use_scope (
          $attachmentScopeConnection,
          $conf_4f_tbl ["anhang"],
          $conf_4f_tbl ["nachrichten"],
          $workflowIncidentId,
          $attachmentDraft ["12_anhang"],
          $workflowSelectedIdentity,
          $messageAttachmentWriteScope
        );
      } finally {
        estab_auth_close ($attachmentScopeConnection);
      }
    }

    // Validate the recipient-matrix revision before bytes are persisted.
    estab_message_attachment_form_data (
      $attachmentDraft,
      $attachmentContext,
      is_array ($objectMessage) ? $objectMessage : null,
      $empf_matrix,
      (string) $redcopy2,
      $workflowSelectedIdentity,
      $activeCommandPostName
    );

    if ($messageAttachmentRemoveRequested) {
      $removeReference = $returnValue ["message_attachment_remove_x"]
        ?? $returnValue ["message_attachment_remove_y"]
        ?? null;
      if (!is_string ($removeReference)) {
        estab_workflow_forbid ();
      }
      $removeReference = estab_file_validate_name (
        "attachment",
        $removeReference
      );
      $currentReferences = estab_read_attachment_tokens (
        $attachmentDraft ["12_anhang"]
      );
      if (!in_array ($removeReference, $currentReferences, true)) {
        estab_workflow_forbid ();
      }
      $attachmentDraft ["12_anhang"] =
        estab_attachment_canonical_message_references (
          implode (";", array_values (array_filter (
            $currentReferences,
            static fn (string $reference): bool =>
              !hash_equals ($removeReference, $reference)
          )))
        );
      // A missing or no-longer-readable legacy reference must still be
      // removable from an authorised editable form. Reauthorise the complete
      // resulting list instead of requiring the broken token itself to pass.
      $remainingScopeConnection = estab_message_connect ($conf_4f_db);
      try {
        estab_read_require_attachment_use_scope (
          $remainingScopeConnection,
          $conf_4f_tbl ["anhang"],
          $conf_4f_tbl ["nachrichten"],
          $workflowIncidentId,
          $attachmentDraft ["12_anhang"],
          $workflowSelectedIdentity,
          $messageAttachmentWriteScope
        );
      } finally {
        estab_auth_close ($remainingScopeConnection);
      }
      $formdata = estab_message_attachment_form_data (
        $attachmentDraft,
        $attachmentContext,
        is_array ($objectMessage) ? $objectMessage : null,
        $empf_matrix,
        (string) $redcopy2,
        $workflowSelectedIdentity,
        $activeCommandPostName
      );
      estab_message_attachment_render_form (
        $formdata,
        (string) $attachmentContext ["task"],
        "Der Anhang wurde aus diesem Nachrichtenvordruck entfernt. " .
        "Die archivierte Datei wurde nicht gelöscht."
      );
    }

    if (is_array ($messageAttachmentReplayWithoutFile)) {
      $replayReference =
        $messageAttachmentReplayWithoutFile ["reference"] ?? null;
      if (is_string ($replayReference) && $replayReference !== "") {
        $attachmentDraft ["12_anhang"] =
          estab_attachment_canonical_message_references (
            estab_attachment_merge_message_references (
              $attachmentDraft ["12_anhang"],
              $replayReference
            )
          );
      }
      $replayScopeConnection = estab_message_connect ($conf_4f_db);
      try {
        estab_read_require_attachment_use_scope (
          $replayScopeConnection,
          $conf_4f_tbl ["anhang"],
          $conf_4f_tbl ["nachrichten"],
          $workflowIncidentId,
          $attachmentDraft ["12_anhang"],
          $workflowSelectedIdentity,
          $messageAttachmentWriteScope
        );
      } finally {
        estab_auth_close ($replayScopeConnection);
      }
      if (($messageAttachmentReplayWithoutFile ["mode"] ?? "") === "submit") {
        header ("Cache-Control: private, no-store, max-age=0");
        header ("Location: ".(string) $conf_4f ["MainURL"], true, 303);
        exit;
      }
      if (
        ($messageAttachmentReplayWithoutFile ["mode"] ?? "") ===
          "conversation-stage"
      ) {
        estab_message_attachment_render_conversation_stage (
          $attachmentDraft,
          $attachmentContext,
          is_array ($objectMessage) ? $objectMessage : null,
          $empf_matrix,
          (string) $redcopy2,
          $workflowSelectedIdentity,
          $activeCommandPostName
        );
      }
      if (
        ($messageAttachmentReplayWithoutFile ["mode"] ?? "") ===
          "pending-submit"
      ) {
        // A reference linked anywhere in the incident is not proof that this
        // exact message POST committed: attachments may intentionally be
        // reused. Recover the complete draft and require an explicit fresh
        // submit instead of discarding text or silently inserting a duplicate
        // after an interrupted response.
        $formdata = estab_message_attachment_form_data (
          $attachmentDraft,
          $attachmentContext,
          is_array ($objectMessage) ? $objectMessage : null,
          $empf_matrix,
          (string) $redcopy2,
          $workflowSelectedIdentity,
          $activeCommandPostName
        );
        estab_message_attachment_render_form (
          $formdata,
          (string) $attachmentContext ["task"],
          "Die Anlage wurde bereits sicher gespeichert. Der Abschluss des ".
          "Nachrichtenschritts ist nach der unterbrochenen Antwort nicht ".
          "eindeutig. Prüfen Sie die Meldungsliste und senden Sie diesen ".
          "wiederhergestellten Entwurf nur dann erneut, wenn er dort fehlt."
        );
      } else {
        $formdata = estab_message_attachment_form_data (
          $attachmentDraft,
          $attachmentContext,
          is_array ($objectMessage) ? $objectMessage : null,
          $empf_matrix,
          (string) $redcopy2,
          $workflowSelectedIdentity,
          $activeCommandPostName
        );
        estab_message_attachment_render_form (
          $formdata,
          (string) $attachmentContext ["task"],
          "Diese Upload-Anfrage wurde bereits verarbeitet. Die Anlage ".
          "wurde nur einmal gespeichert und sicher wiederhergestellt."
        );
      }
    }

    if (
      count (estab_read_attachment_tokens (
        $attachmentDraft ["12_anhang"]
      )) >= 100
    ) {
      throw new EstabAttachmentUploadUserException (
        "Einem Nachrichtenvordruck können höchstens 100 Anhänge ".
        "zugeordnet werden. Entfernen Sie zuerst eine vorhandene Anlage."
      );
    }

    try {
      $replayedAttachment = estab_attachment_direct_action_claim (
        $_SESSION,
        $messageAttachmentRequestToken,
        $workflowSelectedIdentity,
        $workflowIncidentId,
        (string) $attachmentContext ["task"],
        $attachmentContext ["record_id"] ?? null
      );
    } catch (EstabAttachmentContextException $exception) {
      $formdata = estab_message_attachment_form_data (
        $attachmentDraft,
        $attachmentContext,
        is_array ($objectMessage) ? $objectMessage : null,
        $empf_matrix,
        (string) $redcopy2,
        $workflowSelectedIdentity,
        $activeCommandPostName
      );
      estab_message_attachment_render_form (
        $formdata,
        (string) $attachmentContext ["task"],
        "",
        $exception->getMessage ().
        " Laden Sie den Nachrichtenvordruck neu und versuchen Sie es erneut.",
        409
      );
    }

    if (is_array ($replayedAttachment)) {
      $replayedReference = $replayedAttachment ["reference"] ?? null;
      if (is_string ($replayedReference) && $replayedReference !== "") {
        $attachmentDraft ["12_anhang"] =
          estab_attachment_canonical_message_references (
            estab_attachment_merge_message_references (
              $attachmentDraft ["12_anhang"],
              $replayedReference
            )
          );
      }
      $replayScopeConnection = estab_message_connect ($conf_4f_db);
      try {
        estab_read_require_attachment_use_scope (
          $replayScopeConnection,
          $conf_4f_tbl ["anhang"],
          $conf_4f_tbl ["nachrichten"],
          $workflowIncidentId,
          $attachmentDraft ["12_anhang"],
          $workflowSelectedIdentity,
          $messageAttachmentWriteScope
        );
      } finally {
        estab_auth_close ($replayScopeConnection);
      }
      if (($replayedAttachment ["mode"] ?? "") === "submit") {
        header ("Cache-Control: private, no-store, max-age=0");
        header ("Location: ".(string) $conf_4f ["MainURL"], true, 303);
        exit;
      }
      if (($replayedAttachment ["mode"] ?? "") === "conversation-stage") {
        estab_message_attachment_render_conversation_stage (
          $attachmentDraft,
          $attachmentContext,
          is_array ($objectMessage) ? $objectMessage : null,
          $empf_matrix,
          (string) $redcopy2,
          $workflowSelectedIdentity,
          $activeCommandPostName
        );
      }
      $formdata = estab_message_attachment_form_data (
        $attachmentDraft,
        $attachmentContext,
        is_array ($objectMessage) ? $objectMessage : null,
        $empf_matrix,
        (string) $redcopy2,
        $workflowSelectedIdentity,
        $activeCommandPostName
      );
      estab_message_attachment_render_form (
        $formdata,
        (string) $attachmentContext ["task"],
        ($replayedAttachment ["mode"] ?? "") === "pending-submit"
          ? "Die Anlage wurde bereits einmal sicher gespeichert, der ".
            "Nachrichtenschritt aber noch nicht bestätigt. Prüfen Sie die ".
            "Felder und senden Sie ohne erneute Dateiauswahl weiter."
          : "Diese Upload-Anfrage wurde bereits verarbeitet. Die Anlage ".
            "wurde nur einmal gespeichert und sicher wiederhergestellt."
      );
    }

    $upload = is_array ($messageAttachmentFile)
      ? $messageAttachmentFile
      : array (
          "tmp_name" => "",
          "name" => "",
          "error" => UPLOAD_ERR_NO_FILE,
        );
    $storedAttachment = estab_attachment_upload_browser_file (
      $upload,
      $returnValue ["message_attachment_comment"] ?? "",
      $workflowSelectedIdentity,
      $workflowIncidentId,
      $conf_4f_db,
      $conf_4f_tbl ["anhang"],
      $conf_4f_tbl ["protokoll"],
      (string) $conf_4f ["hoheit"],
      (string) $conf_4f ["ablage_dir"],
      session_id (),
      $attachmentContext,
      $_SERVER
    );
    if (
      $messageAttachmentSubmitWithFile
      && !$messageAttachmentConversationStageRequested
    ) {
      // The token remains processing until the ordinary message transaction
      // has returned successfully. Validation/stage failures must never look
      // like a completed message merely because its file was archived.
      $messageAttachmentPendingSubmitCompletion = array (
        "token" => (string) $messageAttachmentRequestToken,
        "reference" => (string) $storedAttachment ["reference"],
      );
      estab_attachment_direct_action_note_pending_submit (
        $_SESSION,
        (string) $messageAttachmentRequestToken,
        (string) $storedAttachment ["reference"]
      );
      estab_message_attachment_checkpoint_pending_action (
        $messageAttachmentRequestToken,
        $workflowSelectedIdentity,
        $workflowIncidentId,
        (string) $attachmentContext ["task"],
        $attachmentContext ["record_id"] ?? null
      );
    } elseif ($messageAttachmentSubmitWithFile) {
      // This first button only opens the second conversation-note form; it
      // does not commit a message. Persist that deterministic outcome now so
      // a worker loss cannot mislabel it as an ambiguous message save.
      estab_attachment_direct_action_complete (
        $_SESSION,
        (string) $messageAttachmentRequestToken,
        (string) $storedAttachment ["reference"],
        "conversation-stage"
      );
      estab_message_attachment_checkpoint_pending_action (
        $messageAttachmentRequestToken,
        $workflowSelectedIdentity,
        $workflowIncidentId,
        (string) $attachmentContext ["task"],
        $attachmentContext ["record_id"] ?? null,
        "conversation-stage"
      );
    } else {
      estab_attachment_direct_action_complete (
        $_SESSION,
        (string) $messageAttachmentRequestToken,
        (string) $storedAttachment ["reference"],
        "upload"
      );
    }
    $attachmentDraft ["12_anhang"] =
      estab_attachment_merge_message_references (
        $attachmentDraft ["12_anhang"],
        (string) $storedAttachment ["reference"]
      );
    $attachmentDraft ["12_anhang"] =
      estab_attachment_canonical_message_references (
        $attachmentDraft ["12_anhang"]
      );

    if ($messageAttachmentSubmitWithFile) {
      // Continue through the ordinary final message transaction. That
      // transaction reauthorises the new object reference once more.
      $returnValue ["12_anhang"] = $attachmentDraft ["12_anhang"];
      $_POST ["12_anhang"] = $attachmentDraft ["12_anhang"];
      unset ($_FILES ["message_attachment_upload"]);
    } else {
      $formdata = estab_message_attachment_form_data (
        $attachmentDraft,
        $attachmentContext,
        is_array ($objectMessage) ? $objectMessage : null,
        $empf_matrix,
        (string) $redcopy2,
        $workflowSelectedIdentity,
        $activeCommandPostName
      );
      estab_message_attachment_render_form (
        $formdata,
        (string) $attachmentContext ["task"],
        "Der Anhang „".
        (string) $storedAttachment ["org_filename"].
        "“ wurde hochgeladen und dieser Nachricht zugeordnet."
      );
    }
  } catch (EstabAttachmentUploadUserException $exception) {
    estab_message_attachment_abandon_direct_action (
      $messageAttachmentRequestToken
    );
    $safeDraft = is_array ($attachmentDraft) ? $attachmentDraft : array ();
    try {
      $formdata = estab_message_attachment_form_data (
        $safeDraft,
        is_array ($attachmentContext)
          ? $attachmentContext
          : array ("task" => (string) ($returnValue ["task"] ?? "")),
        is_array ($objectMessage) ? $objectMessage : null,
        $empf_matrix,
        (string) $redcopy2,
        $workflowSelectedIdentity,
        $activeCommandPostName,
        false
      );
      estab_message_attachment_render_form (
        $formdata,
        (string) ($attachmentContext ["task"]
          ?? ($returnValue ["task"] ?? "")),
        "",
        $exception->getMessage (),
        422
      );
    } catch (Throwable $renderException) {
      error_log (
        "eStab direct attachment error form failed: ".
        $renderException->getMessage ()
      );
      estab_workflow_forbid ();
    }
  } catch (EstabAttachmentDraftException $exception) {
    estab_message_attachment_abandon_direct_action (
      $messageAttachmentRequestToken
    );
    $safeDraft = $exception->draft ();
    try {
      $formdata = estab_message_attachment_form_data (
        $safeDraft,
        is_array ($attachmentContext)
          ? $attachmentContext
          : array ("task" => (string) ($returnValue ["task"] ?? "")),
        is_array ($objectMessage) ? $objectMessage : null,
        $empf_matrix,
        (string) $redcopy2,
        $workflowSelectedIdentity,
        $activeCommandPostName,
        false
      );
      estab_message_attachment_render_form (
        $formdata,
        (string) ($attachmentContext ["task"]
          ?? ($returnValue ["task"] ?? "")),
        "",
        $exception->getMessage (),
        422
      );
    } catch (Throwable $renderException) {
      estab_workflow_forbid ();
    }
  } catch (
    EstabAttachmentContextException
    | InvalidArgumentException
    | EstabReadPermissionException $exception
  ) {
    estab_message_attachment_abandon_direct_action (
      $messageAttachmentRequestToken
    );
    error_log ("eStab direct attachment request rejected");
    estab_workflow_forbid ();
  } catch (EstabIncidentConflictException | EstabNoActiveIncidentException $exception) {
    estab_message_attachment_abandon_direct_action (
      $messageAttachmentRequestToken
    );
    error_log ("eStab direct attachment incident changed: ".$exception->getMessage ());
    estab_session_ui_abort (
      $_SESSION,
      409,
      "Anhangvorgang nicht fortgesetzt",
      "Der aktive Einsatz hat sich geändert. Öffnen Sie den ".
        "Nachrichtenvordruck erneut.",
      "messages"
    );
  } catch (Throwable $exception) {
    estab_message_attachment_abandon_direct_action (
      $messageAttachmentRequestToken
    );
    error_log ("eStab direct attachment upload failed: ".$exception->getMessage ());
    if (is_array ($attachmentDraft) && is_array ($attachmentContext)) {
      try {
        $formdata = estab_message_attachment_form_data (
          $attachmentDraft,
          $attachmentContext,
          is_array ($objectMessage) ? $objectMessage : null,
          $empf_matrix,
          (string) $redcopy2,
          $workflowSelectedIdentity,
          $activeCommandPostName,
          false
        );
        estab_message_attachment_render_form (
          $formdata,
          (string) $attachmentContext ["task"],
          "",
          "Der Anhang kann derzeit nicht sicher gespeichert werden. " .
          "Ihre Formulareingaben sind erhalten geblieben.",
          503
        );
      } catch (Throwable $renderException) {
        // Fall through to the minimal fail-closed response.
      }
    }
    estab_session_ui_abort (
      $_SESSION,
      503,
      "Anhang vorübergehend nicht verfügbar",
      "Der Anhang kann derzeit nicht sicher gespeichert werden.",
      "messages"
    );
  }
}

// Every editable final form action uses the same one-time state machine,
// including submissions that do not carry a new multipart file. The pending
// state is flushed before check_and_save() can commit. A retry therefore
// either resolves its immutable event or remains fail-closed without a second
// INSERT/UPDATE.
if (
  $messageAttachmentFinalSubmitRequested
  && !$messageAttachmentFilePending
  && $messageAttachmentPreGateReplay === null
) {
  try {
    $claimedSubmit = estab_attachment_direct_action_claim (
      $_SESSION,
      $messageAttachmentRequestToken,
      $workflowSelectedIdentity,
      $workflowIncidentId,
      (string) $returnValue ["task"],
      (string) $returnValue ["task"] === "Stab_korrigieren"
        ? ($returnValue ["00_lfd"] ?? null)
        : null
    );
    if (is_array ($claimedSubmit)) {
      throw new EstabAttachmentContextException (
        "Der Nachrichtenvorgang wurde bereits verarbeitet."
      );
    }
    if ($messageAttachmentConversationStageRequested) {
      estab_attachment_direct_action_complete (
        $_SESSION,
        (string) $messageAttachmentRequestToken,
        null,
        "conversation-stage"
      );
      estab_message_attachment_checkpoint_pending_action (
        $messageAttachmentRequestToken,
        $workflowSelectedIdentity,
        $workflowIncidentId,
        (string) $returnValue ["task"],
        null,
        "conversation-stage"
      );
    } else {
      estab_attachment_direct_action_note_pending_submit (
        $_SESSION,
        (string) $messageAttachmentRequestToken,
        null
      );
      estab_message_attachment_checkpoint_pending_action (
        $messageAttachmentRequestToken,
        $workflowSelectedIdentity,
        $workflowIncidentId,
        (string) $returnValue ["task"],
        (string) $returnValue ["task"] === "Stab_korrigieren"
          ? ($returnValue ["00_lfd"] ?? null)
          : null
      );
      $messageAttachmentPendingSubmitCompletion = array (
        "token" => (string) $messageAttachmentRequestToken,
        "reference" => null,
      );
    }
  } catch (EstabAttachmentContextException $exception) {
    error_log ("eStab message submit action rejected");
    estab_workflow_forbid ();
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
    reset_record_lock ($returnValue ["reset_record"], $workflowSelectedIdentity);
    unset ($returnValue ["reset_record"]);
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
          array ("Stab_schreiben", "Stab_korrigieren", "Stab_gesprnoti"),
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
      estab_session_ui_abort (
        $_SESSION,
        503,
        "Anhang vorübergehend nicht verfügbar",
        "Der Anhangvorgang kann derzeit nicht sicher geöffnet werden.",
        "messages"
      );
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
        set_msg_read ( $returnValue["00_lfd"], $workflowSelectedIdentity );
      } else {
        unset_msg_read ( $returnValue["00_lfd"], $workflowSelectedIdentity );
      }
    // erledigt
    if ($returnValue ["action"] == "erledigt")
      if ($returnValue ["todo"] == "set"){
         set_msg_done ( $returnValue["00_lfd"], $workflowSelectedIdentity );
      } else {
        unset_msg_done ( $returnValue["00_lfd"], $workflowSelectedIdentity );
      }
    unset ($returnValue ["action"], $returnValue ["todo"]);
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
        unset ($_SESSION ["estab_pending_navigation_key"]);
        estab_navigation_open_after_login (
          $loginDestination ?? "messages"
        );
      }
    }
  }

  $gesprnotizsichter = false ; // false Voreinstellung fuer dieses Skript

/**********************************************************************
  Daten kommen vom Formular zurueck und koennen gespeichert bzw.
  verarbeitet werden.
  checkandsave befindet sich in data_hndl.php
***********************************************************************/


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
        if (is_array ($messageAttachmentPendingSubmitCompletion)) {
          try {
            // This first step only changes into the dedicated conversation-
            // note form; no message commit is expected yet. The archived
            // attachment is nevertheless complete and the old submit token
            // must not remain replayable as a pending message save.
            estab_attachment_direct_action_complete (
              $_SESSION,
              (string) $messageAttachmentPendingSubmitCompletion ["token"],
              is_string (
                $messageAttachmentPendingSubmitCompletion ["reference"]
                  ?? null
              )
                ? $messageAttachmentPendingSubmitCompletion ["reference"]
                : null,
              "conversation-stage"
            );
          } catch (Throwable $exception) {
            estab_attachment_direct_action_forget (
              $_SESSION,
              $messageAttachmentPendingSubmitCompletion ["token"] ?? null
            );
            error_log (
              "eStab conversation-note attachment token completion failed: ".
              $exception->getMessage ()
            );
          }
          $messageAttachmentPendingSubmitCompletion = null;
        }
        $form = new nachrichten4fach ($formdata, "Stab_gesprnoti", "");
        $gesprnotizsichter = true ;
    } else {

      if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### 369 check and save";  echo "<br>\n";}

      try {
        check_and_save (
          $returndata,
          $activeCommandPostName,
          $workflowIncidentId
        );
      } catch (EstabIncidentConflictException $exception) {
        estab_workflow_render_read_gate (
          409,
          "Einsatz wurde gewechselt",
          $exception->getMessage ()
        );
      } catch (EstabReadPermissionException $exception) {
        // Attachment filenames are object identifiers. A forged selection is
        // indistinguishable from any other forbidden operational object.
        estab_workflow_forbid ();
      }
      if (is_array ($messageAttachmentPendingSubmitCompletion)) {
        try {
          estab_attachment_direct_action_complete (
            $_SESSION,
            (string) $messageAttachmentPendingSubmitCompletion ["token"],
            is_string (
              $messageAttachmentPendingSubmitCompletion ["reference"] ?? null
            )
              ? $messageAttachmentPendingSubmitCompletion ["reference"]
              : null,
            "submit"
          );
        } catch (Throwable $exception) {
          // Keep the checkpointed pending token. Its immutable action event
          // proves the already committed message on the next request even if
          // this final session bookkeeping step fails.
          error_log (
            "eStab direct attachment submit token completion failed: ".
            $exception->getMessage ()
          );
        }
      }
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
           $workflowSelectedIdentity
         )) {
           throw new RuntimeException ("Message lock release lost its target");
         }
         } finally {
           estab_auth_close ($lockConnection);
         }
         // Return to the relevant queue after releasing either exact stage
         // lock. Keeping the submitted task would suppress the single default
         // renderer and leave the main frame empty.
         $returnValue ["task"] = "";
         if ($cancelIsLead) {
           $returnValue ["ldf"] = "";
         }
       }
  } elseif (estab_workflow_cancelled_new_form ($returnValue)) {
    // These forms own no persisted record lock. Their cancel action is a
    // navigation back to the fixed account's ordinary view, so it must become
    // a neutral request before the single-dispatch decision below.
    $returnValue ["task"] = "";
  } elseif (estab_workflow_acknowledged_read_form ($returnValue)) {
    // Opening the record already persisted the read marker. The form's
    // Gelesen/OK button is therefore a terminal return to the staff queue.
    $returnValue ["task"] = "";
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
try {
  $workflowPrimaryView = estab_workflow_primary_view_selector ($returnValue);
} catch (InvalidArgumentException $exception) {
  estab_workflow_forbid ();
}

if (estab_workflow_should_render_primary_view (
  $workflowPrimaryView,
  "staff-corrections",
  false
)) {
  $queueBufferLevel = ob_get_level ();
  ob_start ();
  try {
    $list = new listen ("KORREKTUR", "", $workflowIncidentId);
    $list->createlist ();
    $queueHtml = (string) ob_get_clean ();
  } catch (
    EstabReadPermissionException|
    EstabNoActiveIncidentException|
    EstabIncidentConflictException $exception
  ) {
    while (ob_get_level () > $queueBufferLevel) {
      ob_end_clean ();
    }
    estab_workflow_render_read_gate (
      409,
      "Berechtigungsstand geändert",
      "Der aktive Einsatz oder sein Berechtigungsmodus wurde geändert. ".
      "Laden Sie den Nachrichtenbereich neu."
    );
  }
  pre_html (
    "N",
    "Offene Korrekturen ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"],
    "",
    true
  );
  echo "<body class=\"estab-tool-page\"><main class=\"estab-tool-shell\">";
  echo $queueHtml;
  echo "</main></body></html>";
  exit;
}

if ($returnValue ["stab"] === "korrektur") {
  if (!is_array ($objectMessage)) {
    estab_workflow_forbid ();
  }
  $formdata = $objectMessage;
  $formdata ["13_abseinheit"] = $activeCommandPostName;
  $formdata ["14_zeichen"] = (string) $workflowSelectedIdentity ["kuerzel"];
  $formdata ["14_funktion"] = (string) $workflowSelectedIdentity ["funktion"];
  $form = new nachrichten4fach ($formdata, "Stab_korrigieren", "");
  echo "</body></html>";
  exit;
}

/**********************************************************************\
  --- S T A B  s c h r e i b e n ---
  Hier werden die Angaben:
    Abfasszeit, Absendeeinheit, Zeichen des Verfassers, Funktion
  der Stabsfunktion im Formular voreingestellt.
\**********************************************************************/

  if (estab_workflow_should_render_primary_view (
        $workflowPrimaryView,
        "staff-write",
        false
      ) and !$gesprnotizsichter ) {

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
   if (estab_workflow_should_render_primary_view (
         $workflowPrimaryView,
         "staff-list",
         (
           ($_SESSION ["vStab_rolle"] == "Stab" ) or
           ($_SESSION ["ROLLE"] == "Stab" ) or
           ($_SESSION ["vStab_rolle"] == "FB" ) or
           ($_SESSION ["ROLLE"] == "FB" )
         )
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

    set_msg_read ($returnValue["00_lfd"], $workflowSelectedIdentity);
    $formdata = get_msg_by_lfd ($returnValue["00_lfd"]);
    $staffCanCorrect = is_array ($workflowIdentity)
      && estab_message_object_allowed (
        $workflowIdentity,
        "staff-correction",
        $formdata,
        true
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
   if (estab_workflow_should_render_primary_view (
         $workflowPrimaryView,
         "viewer-list",
         (
           (($_SESSION ["vStab_rolle"] == "Stab") or
            ($_SESSION ["ROLLE"] == "Stab")) and
           ($_SESSION ["vStab_funktion"] == "Si")
         )
       ) and
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
    && estab_workflow_is_telecommunications_lead ($workflowIdentity, true)
    && estab_workflow_should_render_primary_view (
      $workflowPrimaryView,
      "telecommunications-lead-list",
      ($workflowIdentity ["funktion"] ?? "") === "LdF"
        && ($workflowIdentity ["rolle"] ?? "") === "Fernmelder"
    )
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
        $workflowSelectedIdentity,
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
  if (estab_workflow_should_render_primary_view (
        $workflowPrimaryView,
        "telecommunications-incoming-form",
        false
      )){
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
 if (estab_workflow_should_render_primary_view (
       $workflowPrimaryView,
       "telecommunications-outgoing-list",
       (
         (($_SESSION ["vStab_rolle"] == "Fernmelder") or
          ($_SESSION ["ROLLE"] == "Fernmelder")) and
         ($_SESSION ["vStab_funktion"] == "A/W")
       )
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
        $workflowSelectedIdentity,
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
	
  if (estab_workflow_should_render_primary_view (
        $workflowPrimaryView,
        "telecommunications-second-sighting",
        $_SESSION ["fm_zweite_sichtung"] == 1
      )
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
        echo "<p class=\"estab-tool-eyebrow\">Fernmelder · Nachrichtenvordrucke</p>";
        echo "<h1>Zweite Sichtung</h1>";
        echo "<p>Durchsuchen und öffnen Sie die für Ihre aktuelle ".
             "festen Kontofunktion sichtbaren Nachrichten des aktiven Einsatzes.</p>";
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

  if (estab_workflow_should_render_primary_view (
        $workflowPrimaryView,
        "viewer-second-sighting",
        $_SESSION ["si_zweite_sichtung"] == 1
      )
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
             "festen Kontofunktion sichtbaren Nachrichten des aktiven Einsatzes.</p>";
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
  if (estab_workflow_should_render_primary_view (
    $workflowPrimaryView,
    "logout",
    false
  )) {
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
    $registrationAvailable = false;
    $registrationAllowed = false;
    $registrationPolicy = null;
    $registrationPasswordPolicy = null;
    $policyConnection = null;
    try {
      $policyConnection = estab_auth_connect ($conf_4f_db);
      $registrationPolicy = estab_self_registration_load ($policyConnection);
      $registrationAllowed = estab_self_registration_is_allowed (
        $registrationPolicy
      );
      $registrationAvailable = true;
      if ($loginFlow === "new" && $registrationAllowed) {
        try {
          $registrationPasswordPolicy = estab_password_policy_load (
            $policyConnection
          );
        } catch (Throwable $exception) {
          error_log (
            "eStab registration password-policy load failed: ".
            $exception->getMessage ()
          );
        }
      }
    } catch (Throwable $exception) {
      error_log (
        "eStab registration policy load failed: ".
        $exception->getMessage ()
      );
      $registrationAvailable = false;
      $registrationAllowed = false;
      $registrationPasswordPolicy = null;
    } finally {
      if ($policyConnection instanceof mysqli) {
        estab_auth_close ($policyConnection);
      }
    }
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
    echo "<script src=\"../estab-password-policy.js\" defer></script>\n";
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
      if (!$registrationAvailable) {
        echo "<p class=\"estab-auth-note\">Der Status der Kontoanlage konnte nicht sicher geprüft werden. Neue Konten können deshalb momentan nicht selbst angelegt werden.</p>\n";
      } elseif (!$registrationAllowed) {
        echo "<p class=\"estab-auth-note\">Die Selbstregistrierung ist derzeit geschlossen. Bestehende Konten können sich weiterhin anmelden. Die zuständige Stelle legt neue Konten in der Benutzerverwaltung an oder gibt die Kontoanlage in der Administration zeitlich frei.</p>\n";
      }
      echo "<p class=\"estab-auth-note\">Ein Funktionskonto gewährt keinen Zugang zur separaten Administration.</p>\n";
    } elseif (
      $loginFlow === "new"
      && (!$registrationAvailable || !$registrationAllowed)
    ) {
      echo "<h2>Neues Konto anlegen</h2>\n";
      if ($loginError === "") {
        echo "<p class=\"estab-auth-error\" role=\"alert\" tabindex=\"-1\" autofocus>".
             ($registrationAvailable
               ? "Die Selbstregistrierung ist geschlossen. Die Freigabe ist deaktiviert oder der freigegebene Zeitraum ist abgelaufen. Kehren Sie zur Anmeldung mit einem bestehenden Konto zurück oder wenden Sie sich an die zuständige Stelle."
               : "Der Status der Kontoanlage konnte nicht sicher geprüft werden. Neue Konten können deshalb momentan nicht selbst angelegt werden.").
             "</p>\n";
      }
      echo "<form action=\"".$loginAction."\" method=\"POST\" target=\"_self\">\n";
      echo estab_csrf_field ()."\n";
      echo $loginDestinationField."\n";
      echo "<button class=\"estab-button\" type=\"submit\" name=\"login\" value=\"Anmelden\">Zurück zur Auswahl</button>\n";
      echo "</form>\n";
    } elseif (
      $loginFlow === "new"
      && !is_array ($registrationPasswordPolicy)
    ) {
      echo "<h2>Neues Konto anlegen</h2>\n";
      echo "<p class=\"estab-auth-error\" role=\"alert\">Die aktuelle ".
           "Kennwortrichtlinie konnte nicht geladen werden. Ein neues Konto ".
           "kann deshalb momentan nicht sicher angelegt werden.</p>\n";
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
      if ($isRegistration) {
        echo "<p id=\"estab-registration-password-policy\" class=\"estab-auth-note\">".
             estab_auth_html (estab_password_policy_requirements_text (
               $registrationPasswordPolicy
             ))."</p>\n";
      }
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
        $funktionsname = estab_auth_html (estab_function_display_name (
          (string) $conf_empf[$i]["fkt"]
        ));
        echo "<option value=\"".$funktion."\"".$selected.">".$funktionsname."</option>\n";
      }
      echo "</select></td></tr>\n";
      echo "<tr><th><label for=\"estab-login-password\">Kennwort</label></th>\n";
      echo "<td><input id=\"estab-login-password\" name=\"kennwort1\" type=\"password\" maxlength=\"".
           ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH."\" autocomplete=\"".
           $passwordAutocomplete."\"".
           ($isRegistration
             ? " data-estab-password-minimum-codepoints=\"".
               (int) $registrationPasswordPolicy ["minimum_length"].
               "\" aria-describedby=\"estab-registration-password-policy\""
             : "")." required".$passwordAutofocus."></td></tr>\n";
      if ($isRegistration) {
        echo "<tr><th><label for=\"estab-login-password-confirm\">Kennwort wiederholen</label></th>\n";
        echo "<td><input id=\"estab-login-password-confirm\" name=\"kennwort2\" type=\"password\" data-estab-password-minimum-codepoints=\"".
             (int) $registrationPasswordPolicy ["minimum_length"].
             "\" maxlength=\"".ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH.
             "\" autocomplete=\"new-password\" aria-describedby=\"estab-registration-password-policy\" required></td></tr>\n";
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
if (estab_workflow_should_render_primary_view (
       $workflowPrimaryView,
       "account-list",
       false
     ) OR
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
