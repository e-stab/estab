<?php
/*****************************************************************************\
   Datei: anhang.php

   benoetigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

/*****************************************************************************\
    Auswahl einer Anhangdatei
    Datei auf den Server hochladen

20160510 - store_formdata von $_GET auf $_POST umgestellt. 
******************************************************************************/

require_once __DIR__ . "/upload_class.php";
require_once __DIR__ . "/../app/attachment.php";
require_once __DIR__ . "/../app/anhang_tabelle.php";
require_once __DIR__ . "/../app/attachment_upload.php";
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/file_access.php";
require_once __DIR__ . "/../app/navigation.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/session_ui.php";
require_once __DIR__ . "/../app/workflow.php";

function estab_attachment_current_identity (?array $originContext = null): array {
  if (is_array ($originContext)) {
    return estab_attachment_origin_authority_identity ($originContext);
  }
  if (!isset ($_SESSION) || !is_array ($_SESSION)) {
    throw new EstabDvPermissionException ("Anmeldung erforderlich.");
  }
  $identity = estab_auth_session_identity ($_SESSION);
  if (!is_array ($identity)) {
    throw new EstabDvPermissionException ("Anmeldung erforderlich.");
  }
  return $identity;
}

function estab_attachment_release_message_flow_reservation (
  array $context
): void {
  require ("../4fcfg/dbcfg.inc.php");
  require ("../4fcfg/e_cfg.inc.php");
  $connection = null;
  $cleanupFailed = false;
  try {
    $connection = estab_attachment_connection ($conf_4f_db);
    estab_attachment_release_origin_reservation (
      $connection,
      $conf_4f_tbl ["anhang"],
      session_id (),
      $context
    );
  } catch (Throwable $exception) {
    error_log (
      "eStab attachment flow cleanup failed: ".$exception->getMessage ()
    );
    $cleanupFailed = true;
  } finally {
    if ($connection instanceof mysqli) {
      estab_attachment_close ($connection);
    }
  }
  if ($cleanupFailed) {
    estab_session_ui_abort (
      isset ($_SESSION) && is_array ($_SESSION) ? $_SESSION : array (),
      503,
      "Anhangvorgang vorübergehend nicht verfügbar",
      "Der Anhangvorgang konnte nicht sicher abgeschlossen werden. ".
        "Bitte versuchen Sie es erneut.",
      "messages"
    );
  }
}

class fileupload extends file_upload {
  var $message_context = null;
  // fs - fileselectform Dateiauswahl
  var $fs_savename;     // Einlagerungsdateiname  HSxxxxx
  var $fs_uplname;      // Uploaddateiname
  var $fs_comment;      // Beschreibung
  var $fs_shortname;    // Kuerzel
  var $fs_timestamp;    // Zeitstempel
  var $fs_nextfilename; // Nächster Dateiname

  var $ff_savename ;    // Name der gespeicherten Datei g.g. Darstellung im Menue
  var $ff_filename ;    // Ursprünglicher Dateiname
  var $ff_comment  ;    // Beschreibung Faxkopf
  var $ff_timestamp;    // Zeitstempel
  var $ff_kuerzel  ;    // Kuerzel des Fm

  var $filenamezero = 4; // Anzahl der Zahlen

  function reservation_owner_id () {
    return estab_attachment_reservation_owner_id (
      session_id (),
      is_array ($this->message_context) ? $this->message_context : null
    );
  }

  /***************************************************************************\
    Funktion: get_next_filename_from_db ()
         DB Status
           1 : erledigt - upload vollzogen
           2 : ?
           4 : abgebrochen
           8 : reserviert

    Beschreibung:
      Wenn diese Routine aufgerufen wird möchte man hier einen freien
      Dateinamen. Das bedeutet :
      1. Alle Reservierungen in der Datenbank für diese Session_ID können
         gelöscht werden. d.h. erstmal alle Datenbankreservierungen löschen.
      2.

  \***************************************************************************/
  function get_next_filename_from_db () {
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      $this->fs_nextfilename = estab_attachment_reserve (
        $connection,
        $conf_4f_tbl ["anhang"],
        $conf_4f ["hoheit"],
        $this->reservation_owner_id (),
        estab_attachment_current_identity ($this->message_context),
        $this->filenamezero
      );
      return $this->fs_nextfilename;
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /***************************************************************************\
    Funktion:     loesche_reservierungen
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function loesche_reservierungen ($db, $tbl){
    unset ($db);
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      estab_attachment_release_unclaimed (
        $connection,
        $tbl,
        $this->reservation_owner_id ()
      );
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /***************************************************************************\
    Funktion:     loesche_reservierungen
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function reset_reservation (){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      estab_attachment_release (
        $connection,
        $conf_4f_tbl ["anhang"],
        $this->reservation_owner_id ()
      );
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /***************************************************************************\
    Funktion:     res_filename_db
    Parameter:    $filename
    Beschreibung:

  \***************************************************************************/
  function res_filename_db ($filename){
    return ($filename === $this->fs_nextfilename);
  }

  /***************************************************************************\
    Funktion:     res_abgebr
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function res_abgebr ($db, $tbl){
    unset ($db, $tbl);
    return ("");
  }

  /***************************************************************************\
    Funktion:     next_filename
    Parameter:    $db   Datenbankhandle
    Beschreibung:

  \***************************************************************************/
  function next_filename ($db, $tbl, $hoheit){
    unset ($db, $tbl, $hoheit);
    return $this->get_next_filename_from_db ();
  }


  /***************************************************************************\
    Funktion:     change_status_in_db
    Parameter:    $change     : set oder get
                  $filename   : Dateiname
                  $status     : 8 - reserviert
                                4 - frei
                                2 -
                                1 - gesetzt
    Beschreibung:
      liest und ändert die Datenbankeinträge für die Dateinamen.

  \***************************************************************************/
  function change_status_in_db ($change, $filename, $status){
    unset ($status);
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      if ((string) $change === "4") {
        estab_attachment_release (
          $connection,
          $conf_4f_tbl ["anhang"],
          $this->reservation_owner_id (),
          $filename
        );
        return true;
      }
      if ((string) $change === "8") {
        return $this->res_filename_db ($filename);
      }
      return false;
    } finally {
      estab_attachment_close ($connection);
    }
  } // change_status_in_db

  function claim_reservation ($filename){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      return estab_attachment_claim (
        $connection,
        $conf_4f_tbl ["anhang"],
        $filename,
        $this->reservation_owner_id (),
        estab_attachment_current_identity ($this->message_context)
      );
    } finally {
      estab_attachment_close ($connection);
    }
  }

  function release_reservation ($filename){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      estab_attachment_release (
        $connection,
        $conf_4f_tbl ["anhang"],
        $this->reservation_owner_id (),
        $filename
      );
    } finally {
      estab_attachment_close ($connection);
    }
  }


/*****************************************************************************\
\*****************************************************************************/
  function save_in_db ($data) {
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $actingIdentity = estab_attachment_current_identity (
      $this->message_context
    );
    $reservation = (string) ($data ["reservation"] ?? "");
    $metadata = estab_attachment_validate_metadata (
      $data,
      $reservation,
      (string) ($actingIdentity ["kuerzel"] ?? "")
    );
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      $saved = estab_attachment_finalize (
        $connection,
        $conf_4f_tbl ["anhang"],
        $this->reservation_owner_id (),
        $metadata,
        $actingIdentity
      );
      if (!$saved) {
        return false;
      }

      $details = (string) ($actingIdentity ["benutzer"] ?? "").";".
                 (string) ($actingIdentity ["kuerzel"] ?? "").";".
                 (string) ($actingIdentity ["funktion"] ?? "").";".
                 (string) ($actingIdentity ["rolle"] ?? "").";".
                 session_id ().";".
                 estab_auth_remote_ip ($_SERVER).";".
                 $metadata ["filename"].".".$metadata ["fileext"].";".
                 $metadata ["org_filename"].";".
                 $metadata ["date"];
      try {
        estab_attachment_log (
          $connection,
          $conf_4f_tbl ["protokoll"],
          "Anhangdaten speichern",
          $details,
          $conf_4f_tbl ["anhang"],
          $metadata ["filename"]
        );
      } catch (Throwable $exception) {
        error_log ("eStab attachment audit failed: ".$exception->getMessage ());
      }
      return true;
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /**********************************************************************\
    Funktion: readDirectory ()

    benoetigte Datei:
  \**********************************************************************/
  function readDirectory($directory){
//    include ("../config.inc.php");
      $filesArr = array();
      if($ordner = dir($directory))
      {
          while($datei = $ordner->read())
          {
          if($datei != "." && $datei != "..") array_push($filesArr,$datei);
          }
      }
      rsort ($filesArr);
      return $filesArr;
  }


/*****************************************************************************\
  Funktion:  scan4nextfilename ()
  Parameter:
  Beschreibung:
    1. Prüfe Kausalität Dateien und Datenbank
    2. Ziehe nächsten Wert aus der Datenbank
    3. Setze Status in der Datenbank auf Vergeben.

\*****************************************************************************/
  function scan4nextfilename (){
    return $this->get_next_filename_from_db ();
  } // scan4nextfilename



/*****************************************************************************\

\*****************************************************************************/
  function konv_datetime_taktime ($datetime){
    require ("../4fcfg/config.inc.php");
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



  function convtaktodatetime ($taktime){
    require ("../4fcfg/config.inc.php");
    /* Datum ~= TTMM, Zeit == ~= HHMM */
    $tag    = substr ($taktime, 0, 2);
    $stunde = substr ($taktime, 2, 2);
    $minute = substr ($taktime, 4, 2);
    $monat  = substr ($taktime, 6, 3);
    $jahr   = substr ($taktime, 9, 4);
    $datetime = $jahr."-".$rew_tak_monate[strtolower($monat)]."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }

  function pre_html($titel){
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>";
    echo "<meta content=\"text/html; charset=UTF-8\" http-equiv=\"content-type\">\n";
    echo "<title>".estab_attachment_html ($titel)."</title>";
    // Die Anhangseite trug bisher gar keine Gestaltung und stand deshalb als
    // einzige Seite in der Schrift und den Farben des Browsers da.
    echo estab_session_ui_stylesheet ()."\n";
    echo "</head>";
    echo "<body class=\"estab-legacy-page\">";
  }


  function fileselectform ($predata, $messageContext = null) {
    require ("../4fcfg/config.inc.php");
    $formAction = estab_attachment_html ($_SERVER['PHP_SELF'] ?? "anhang.php");
    $newFilename = estab_attachment_html ($predata["newfilename"] ?? "");
    $comment = estab_attachment_html ($predata["comment"] ?? "");
    $shortname = estab_attachment_html ($predata["kuerzel"] ?? "");
    $timestamp = estab_attachment_html ($predata["time"] ?? "");
    $allowedExtensions = estab_attachment_allowed_extensions ();
    $accept = estab_attachment_html (
      implode (",", array_map (
        static fn ($extension) => ".".$extension,
        $allowedExtensions
      ))
    );
    $formatNames = estab_attachment_html (
      strtoupper (implode (", ", $allowedExtensions))
    );
    $uploadLimit = estab_attachment_html ($this->upload_limit_label ());
    echo "<form name=\"uploadform\" enctype=\"multipart/form-data\" method=\"post\" ".
         "action=\"".$formAction."\" data-estab-dirty-guard ".
         "data-estab-requires-incident data-estab-attachment-upload>\n";
    echo estab_csrf_field ()."\n";
    $attachmentFlowToken = is_array ($messageContext)
      ? ($messageContext ["flow_token"] ?? null)
      : null;
    if (is_string ($attachmentFlowToken)) {
      echo "<input type=\"hidden\" name=\"attachment_flow\" value=\"".
           estab_attachment_html ($attachmentFlowToken)."\">\n";
    }
    echo "<fieldset>\n";
    echo "<legend><big>Anhang hochladen</big></legend>\n";
    echo "<table style=\"text-align: left; width: 745px; height: 170px;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td>\n";
    echo "<table style=\"text-align: left; width: 740px; height: 143px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"1\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Dateiname:</td>\n";
    echo "  <td style=\"width: 769px;\"><big><big style=\"font-weight: bold;\">".$newFilename."</big></big></td>\n";
    echo "  <input type=\"hidden\" name=\"fs_nextfilename\" value=\"".$newFilename."\">\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\"><label for=\"attachment-upload-file\">Datei:</label></td>\n";
    echo "  <td style=\"width: 769px;\">";
    echo "  <input id=\"attachment-upload-file\" style=\"font-size:18px; font-weight:900; font-weight: bold;\" ".
         "name=\"upload\" type=\"file\" size=\"60\" accept=\"".$accept."\" ".
         "aria-describedby=\"attachment-upload-help\" required>";
    echo "  <br><small id=\"attachment-upload-help\">Erlaubte Formate: ".$formatNames.
         ". Maximale Dateigröße: ".$uploadLimit.
         ". Für E-Mail-Dateien im .eml-Format gilt zusätzlich ein festes ".
         "Sicherheitslimit von 20 MiB.</small>";
    echo "  </td>\n";
    echo "</tr>\n";
    echo "<tr>\n" ;
    echo "  <td style=\"width: 167px;\">Beschreibung</td>\n";
    echo "  <td style=\"width: 769px;\">";
    echo "   <input style=\"font-size:18px; font-weight:900;\" maxlength=\"255\" size=\"80\" name=\"fs_comment\" value=\"".$comment."\"></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td>K&uuml;rzel</td>\n";
    echo "  <td style=\"width: 769px;\"><big><input maxlength=\"6\" size=\"6\" name=\"fs_shortname\" value=\"".$shortname."\" readonly></big></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Zeitstempel</td>\n";
    echo "  <td style=\"width: 769px;\"><input maxlength=\"13\" size=\"13\" name=\"fs_timestamp\" value=\"".$timestamp."\"></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "<td></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
        echo "</fieldset>\n";

        echo "<fieldset>";
    echo "<legend>Aktion:</legend>\n";
    echo "<table border=\"1\" cellpadding=\"2\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    echo "<tr>\n";
    echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" ".
         "src=\"".$conf_design_path."/ok.gif\" alt=\"Anhang speichern\"></td>\n";
    echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"abbrechen\" ".
         "src=\"".$conf_design_path."/cancel.gif\" alt=\"Upload abbrechen\" ".
         "formnovalidate></td>\n";
    echo "</td></tr>\n";
    echo "</table>\n";
        echo "</fieldset>\n";

    echo "</form>";
  }



  function post_html () {
    echo "</body>";
    echo "</html>";
  }

} // class fileupload


/*************************************************************************************************************
                                S T E U E R U N G
**************************************************************************************************************/



if (session_status () === PHP_SESSION_NONE) {
  session_start ();
}
estab_navigation_require_session (
  $_SESSION,
  "messages",
  $_SERVER,
  true
);
$attachmentPageIdentity = estab_read_session_identity ($_SESSION);
estab_navigation_require_selected_duty (
  $_SESSION,
  $attachmentPageIdentity,
  "messages",
  $_SERVER
);
$attachmentRequestMethod = strtoupper (
  (string) ($_SERVER ["REQUEST_METHOD"] ?? "GET")
);
$attachmentInternalRequest =
  isset ($attachmentInternalFlowToken)
  && is_string ($attachmentInternalFlowToken);
$attachmentGetActionRequested = false;
foreach (array ("ah_auswahl_x", "ah_abbrechen_x", "ah_upload_x") as $key) {
  $attachmentGetActionRequested =
    $attachmentGetActionRequested || isset ($_GET [$key]);
}
if ($attachmentGetActionRequested) {
  header ("Allow: POST");
  estab_session_ui_abort (
    $_SESSION,
    405,
    "Anhang-Aktion nicht möglich",
    "Diese Anhang-Aktion ist nur per Formular möglich.",
    "messages"
  );
}
// Every direct or internally included POST has a CSRF field. Validate it
// before looking up, replacing or deleting any flow-owned session state.
if ($attachmentRequestMethod === "POST") {
  try {
    estab_csrf_require_post ($_SERVER, $_POST);
  } catch (RuntimeException $exception) {
    error_log (
      "eStab attachment CSRF validation failed: ".
      $exception->getMessage ()
    );
    estab_session_ui_abort (
      $_SESSION,
      403,
      "Anhang-Aktion nicht erlaubt",
      "Die Anhang-Aktion ist ungültig oder abgelaufen.",
      "messages"
    );
  }
}
$attachmentPageConnection = null;
$attachmentOriginContext = null;
$attachmentOriginMessage = null;
$attachmentRequestFlowToken = null;
$attachmentPageError = null;
$attachmentPageReadTransaction = false;
try {
  if (
    !$attachmentInternalRequest
    && (
      isset ($_POST ["anhang_plus_x"])
      || isset ($_POST ["anhang_plus_y"])
    )
  ) {
    throw new EstabAttachmentContextException (
      "Der Browser darf keinen internen Anhang-Start markieren."
    );
  }
  if ($attachmentInternalRequest) {
    if (array_key_exists ("attachment_flow", $_POST)) {
      throw new EstabAttachmentContextException (
        "Ein interner Anhang-Start darf keinen Browser-Flow enthalten."
      );
    }
    $attachmentRequestFlowToken =
      estab_attachment_origin_flow_token ($attachmentInternalFlowToken);
  } elseif (array_key_exists ("attachment_flow", $_POST)) {
    $attachmentRequestFlowToken =
      estab_attachment_origin_flow_token ($_POST ["attachment_flow"]);
  }

  require ("../4fcfg/dbcfg.inc.php");
  require ("../4fcfg/e_cfg.inc.php");
  $attachmentPageConnection = estab_attachment_connection ($conf_4f_db);
  if (!$attachmentPageConnection->begin_transaction ()) {
    throw new RuntimeException (
      "Lesetransaktion konnte nicht begonnen werden."
    );
  }
  $attachmentPageReadTransaction = true;
  $attachmentPageIncident = estab_incident_require_active (
    $attachmentPageConnection,
    true
  );
  estab_permission_context_set_from_incident ($attachmentPageIncident);
  // Vordrucke rebuilt after the optional archive picker issue their next
  // one-time direct-action token during rendering. Expose the incident that
  // was just authorised exactly as the main message controller does.
  $workflowIncidentId = estab_incident_positive_id (
    $attachmentPageIncident ["active_einsatz_id"] ?? null
  );
  $attachmentPageIdentity = estab_read_require_identity_scope (
    $attachmentPageConnection,
    $workflowIncidentId,
    $attachmentPageIdentity,
    true
  );
  $attachmentArchiveRoleAllowed =
    estab_workflow_is_staff_writer ($attachmentPageIdentity)
    || estab_workflow_is_telecommunications ($attachmentPageIdentity);
  if (!$attachmentArchiveRoleAllowed) {
    throw new EstabReadPermissionException (
      "Keine Ihrer aktuell wirksamen Funktionen darf das Anhangarchiv öffnen."
    );
  }
  $attachmentCommandPostName = estab_incident_command_post_name (
    $attachmentPageIncident
  );
  $storedAttachmentOrigin = $attachmentRequestFlowToken === null
    ? null
    : estab_attachment_origin_context_find (
      $_SESSION,
      $attachmentRequestFlowToken
    );
  if ($attachmentRequestFlowToken !== null && !is_array ($storedAttachmentOrigin)) {
    throw new EstabAttachmentContextException (
      "Für diesen Anhang-Flow fehlt der serverseitige Ursprung."
    );
  }
  if (is_array ($storedAttachmentOrigin)) {
    $attachmentOriginContext =
      estab_attachment_origin_context_validate (
        $storedAttachmentOrigin,
        $attachmentPageIdentity,
        $workflowIncidentId,
        $_POST,
        !$attachmentInternalRequest
      );
    if (
      ($attachmentOriginContext ["task"] ?? null) ===
        "Stab_korrigieren"
    ) {
      $attachmentOriginMessage =
        estab_message_fetch_for_incident_by_id (
          $attachmentPageConnection,
          $conf_4f_tbl ["nachrichten"],
          $attachmentOriginContext ["record_id"] ?? null,
          $workflowIncidentId
        );
      if (
        !is_array ($attachmentOriginMessage)
        || !estab_message_object_allowed (
          $attachmentPageIdentity,
          "staff-correction",
          $attachmentOriginMessage,
          true
        )
      ) {
        throw new EstabAttachmentContextException (
          "Der Korrekturdatensatz ist nicht mehr für diesen ".
          "Anhangvorgang freigegeben."
        );
      }
    }
  } elseif (
    isset ($_POST ["ah_auswahl_x"])
    || isset ($_POST ["ah_abbrechen_x"])
    || (
      array_key_exists ("task", $_POST)
      && $_POST ["task"] !== ""
    )
    || (
      array_key_exists ("00_lfd", $_POST)
      && $_POST ["00_lfd"] !== ""
    )
  ) {
    throw new EstabAttachmentContextException (
      "Für diese Anhang-Anforderung fehlt der serverseitige Ursprung."
    );
  }
  if (!$attachmentPageConnection->commit ()) {
    throw new RuntimeException (
      "Lesetransaktion konnte nicht abgeschlossen werden."
    );
  }
  $attachmentPageReadTransaction = false;
} catch (EstabAttachmentContextException $exception) {
  error_log (
    "eStab attachment context rejected: ".$exception->getMessage ()
  );
  $attachmentPageError = array (
    403,
    "Anhangvorgang nicht erlaubt",
    "Der Anhangvorgang ist ungültig oder nicht mehr autorisiert. ".
      "Öffnen Sie den Nachrichtenvordruck erneut.",
  );
} catch (EstabNoActiveIncidentException) {
  $attachmentPageError = array (
    409,
    "Kein aktiver Einsatz",
    "Kein Einsatz aktiv.",
  );
} catch (EstabReadPermissionException) {
  $attachmentPageError = array (
    403,
    "Keine Berechtigung für die Anhangverwaltung",
    "Keine Ihrer aktuell wirksamen Funktionen darf die Anhangverwaltung ".
      "öffnen oder die Berechtigung ist nicht mehr aktiv.",
  );
} catch (EstabIncidentConfigurationException) {
  $attachmentPageError = array (
    409,
    "Einsatz noch nicht vollständig eingerichtet",
    "Für den aktiven Einsatz fehlt der Führungsstellenname.",
  );
} catch (Throwable $exception) {
  error_log (
    "eStab attachment page authorization failed: ".$exception->getMessage ()
  );
  $attachmentPageError = array (
    503,
    "Anhangverwaltung vorübergehend nicht verfügbar",
    "Die Anhangberechtigung kann derzeit nicht geprüft werden.",
  );
} finally {
  if ($attachmentPageConnection instanceof mysqli) {
    if ($attachmentPageReadTransaction) {
      $attachmentPageConnection->rollback ();
    }
    estab_attachment_close ($attachmentPageConnection);
  }
}
if (is_array ($attachmentPageError)) {
  estab_session_ui_abort (
    $_SESSION,
    $attachmentPageError [0],
    $attachmentPageError [1],
    $attachmentPageError [2],
    "messages"
  );
}
// Diese Seite ist der Inhalt der Huelle, nicht die Huelle selbst.
// Menue und Cockpit stehen aussen; hier waeren sie ein Menue im Menue.
estab_session_ui_start ($_SESSION, false, false, false);

if (!defined ("debug")) { define ("debug", false); }

$attachmentMessageContext = is_array ($attachmentOriginContext);
$attachmentOriginTask = $attachmentMessageContext
  ? (string) ($attachmentOriginContext ["task"] ?? "")
  : "";
$attachmentStaffOrigin = in_array (
  $attachmentOriginTask,
  array ("Stab_schreiben", "Stab_korrigieren", "Stab_gesprnoti"),
  true
);
$attachmentTelecommunicationsOrigin = in_array (
  $attachmentOriginTask,
  array ("FM-Eingang", "FM-Eingang_Anhang"),
  true
);
$attachmentContextNotice = "";
$attachmentSelectionRequested =
  isset ($_POST ["ah_auswahl_x"]) || isset ($_POST ["ah_abbrechen_x"]);
if ($attachmentSelectionRequested && !$attachmentMessageContext) {
  $attachmentContextNotice =
    "Zum Übernehmen von Anhängen öffnen Sie bitte zuerst einen Nachrichtenvordruck. ".
    "Die Anhangübersicht bleibt unabhängig davon verfügbar.";
}

    require ("../4fcfg/config.inc.php");
    require_once ("./db_operation.php");  // Datenbank operationen

    require_once ("./4fachform.php");            // Formular Behandlung 4fach Vordruck
    require_once ("./tools.php");

if ( debug == true ){
  echo "<br><br>\n";
  echo "------ Anhang.PHP 518 an Anfang ------";     echo "#<br><br>\n";
  echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
  echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
}


/*****************************************************************************\

100 - Anhangmenü + Anhänge zur Auswahl
  Im Hauptmenü [Anhänge] geklickt
  POST = ["fm_anhang_x"]
     ==> Liste anzeigen mit Auswahl oder Uploadbutton
  101 - absenden
  102 - abbrechen
  103 - upload

101 - Aufruf Nachrichtenvordruck mit Übernahme Altdaten

103 - Datei-hochladen-Menü
  Im Anhangmenü [Upload] geklickt
  POST = ["anhang"]=>  string(10) "ah_auswahl"
         ["ah_auswahl_x"]=>  string(2) "19"
         ["ah_auswahl_y"]=>  string(1) "6" } #
     ==> Vordruck mit Anhang öffnen
  111 - absenden
  112 - abbrechen

\*****************************************************************************/


/**********************************************************************\
  --- S T A B   s c h r e i b e n   m i t  A n h a n g ---

  Anhang ausgewaehlt und kann in Vordruck uebernommen werde
\**********************************************************************/
  if (
    $attachmentMessageContext
    && $attachmentStaffOrigin
    && (
      isset ($_POST["ah_auswahl_x"])
      || isset ($_POST["ah_abbrechen_x"])
    )
  ) {

    if ( debug == true ){ echo "### 559 Anhang ausgewaehlt und kann in Vordruck uebernommen werden ";  echo "<br>\n";}

    $keys = array_keys ($_POST);
    $ahkey = array ();
    foreach ($keys as $key){
      if (is_string ($key) && preg_match ("/\\Alfd_[0-9]+\\z/D", $key)) {
        $ahkey [] = $key;
      }
    }

    $anhang = "";
    foreach ($ahkey as $anh){
      $selectedValue = $_POST [$anh] ?? null;
      if (!is_string ($selectedValue)) { continue; }
      $db_data = readrecord_from_db($selectedValue);
      if (!isset ($db_data [1])) { continue; }
      try {
        $selectedBase = estab_attachment_validate_reservation_name ((string) $db_data [1]["filename"]);
      } catch (InvalidArgumentException) {
        continue;
      }
      $selectedExtension = strtolower ((string) $db_data [1]["fileext"]);
      if (!estab_attachment_extension_is_allowed ($selectedExtension)
          || !estab_attachment_validate_sql_datetime ((string) $db_data [1]["date"])) {
        continue;
      }
      $selectedName = $selectedBase.".".$selectedExtension;
      $anhang .= $selectedName.";";
    }
    $formdata = restore_formdata (
      $attachmentOriginContext,
      $attachmentOriginMessage
    );
    $formdata ["13_abseinheit"] = $attachmentCommandPostName;
    $formdata ["14_zeichen"] =
      (string) $attachmentOriginContext ["kuerzel"];
    $formdata ["14_funktion"] =
      (string) $attachmentOriginContext ["funktion"];
    if ($attachmentOriginTask === "Stab_gesprnoti") {
      $formdata ["01_zeichen"] = $_SESSION ["vStab_kuerzel"];
    }

    if (isset ($_POST["ah_auswahl_x"])) {
      $formdata ["12_anhang"] =
        estab_attachment_merge_message_references (
          $formdata ["12_anhang"] ?? "",
          $anhang
        );
    }
    estab_attachment_release_message_flow_reservation (
      $attachmentOriginContext
    );
    estab_attachment_origin_context_clear (
      $_SESSION,
      $attachmentOriginContext ["flow_token"] ?? null
    );
    unset ($_SESSION ["anhang_menue"]);
    $form = new nachrichten4fach (
      $formdata,
      $attachmentOriginTask,
      ""
    );
    exit;

  }


/**********************************************************************\
   --- F E R N M E L D E R  schreiben mit Anhang

\**********************************************************************/
    // Anhang ausgewaelt und kann in Vordruck uebernommen werden
  if (
    $attachmentMessageContext
    && $attachmentTelecommunicationsOrigin
    && (
      isset ($_POST["ah_auswahl_x"])
      || isset ($_POST["ah_abbrechen_x"])
    )
  ) {

    if ( debug == true ){ echo "### 417 anhang.php Vordruck aufrufen mit Daten füllen ";  echo "<br>\n";}

    $keys = array_keys ($_POST);
    $ahkey = array ();
    foreach ($keys as $key){
      if (is_string ($key) && preg_match ("/\\Alfd_[0-9]+\\z/D", $key)) {
        $ahkey [] = $key;
      }
    }
    $anhang = "";
    foreach ($ahkey as $anh){
      $selectedValue = $_POST [$anh] ?? null;
      if (!is_string ($selectedValue)) { continue; }
      $db_data = readrecord_from_db($selectedValue);
      if (!isset ($db_data [1])) { continue; }
      try {
        $selectedBase = estab_attachment_validate_reservation_name ((string) $db_data [1]["filename"]);
      } catch (InvalidArgumentException) {
        continue;
      }
      $selectedExtension = strtolower ((string) $db_data [1]["fileext"]);
      if (!estab_attachment_extension_is_allowed ($selectedExtension)
          || !estab_attachment_validate_sql_datetime ((string) $db_data [1]["date"])) {
        continue;
      }
      $selectedName = $selectedBase.".".$selectedExtension;
      $anhang .= $selectedName.";";
    }
    $formdata = restore_formdata ($attachmentOriginContext);
    $formdata ["01_zeichen"] = $_SESSION ["vStab_kuerzel"];
    if ( debug == true ){
      echo "<b>anhang.php 623 restore_formdata</b>";
      echo "<br>\n";
      print_r ($formdata); echo "<br>";
    }

    if (isset ($_POST["ah_auswahl_x"])) {
      $formdata ["12_anhang"] =
        estab_attachment_merge_message_references (
          $formdata ["12_anhang"] ?? "",
          $anhang
        );
      if (($formdata ["10_anschrift"] ?? "") === "") {
        $formdata ["10_anschrift"] = $attachmentCommandPostName;
      }
    }
    estab_attachment_release_message_flow_reservation (
      $attachmentOriginContext
    );
    estab_attachment_origin_context_clear (
      $_SESSION,
      $attachmentOriginContext ["flow_token"] ?? null
    );
    unset ($_SESSION ["anhang_menue"]);
    // Returning from attachment selection never changes the signed workflow
    // task and never lets A/W impersonate the mandatory Si review.
    unset ($formdata ["15_quitdatum"], $formdata ["15_quitzeichen"]);
    $form = new nachrichten4fach (
      $formdata,
      $attachmentOriginTask,
      ""
    );
    exit;
  }



/*****************************************************************************\
   Datei: anhang.php

   benoetigte Dateien:

   Beschreibung:
      Auflistung eines Verzeichnisses zur auswahl als Anhang


   (C)2006-2008 Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

require ("../4fcfg/dbcfg.inc.php");
require ("../4fcfg/e_cfg.inc.php");
require_once ("./db_operation.php");  // Datenbank operationen



  /**********************************************************************\
    Funktion: readFiles_from_db ()
    lese die Datensätze aus der Datenbank
    benoetigte Datei:
  \**********************************************************************/
  function readFiles_from_db(){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      $identity = estab_read_session_identity ($_SESSION);
      if (!is_array ($identity)) {
        throw new EstabReadPermissionException ("Anmeldung erforderlich.");
      }
      return estab_read_with_locked_operational_scope (
        $connection,
        $identity,
        static function (array $readScope) use (
          $connection,
          $conf_4f_tbl
        ): array {
          $incidentId = (int) (
            $readScope ["incident"]["active_einsatz_id"]
          );
          $attachments = estab_attachment_list_for_incident (
            $connection,
            $conf_4f_tbl ["anhang"],
            $incidentId
          );
          return estab_read_filter_attachments_for_incident (
            $connection,
            $conf_4f_tbl ["nachrichten"],
            $attachments,
            $readScope ["identity"],
            $incidentId
          );
        }
      );
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /**********************************************************************\
    Funktion: readFiles_from_db ()
    lese die Datensätze aus der Datenbank
    benoetigte Datei:
  \**********************************************************************/
  function readrecord_from_db($anhangname){
    try {
      $requested = estab_file_validate_name (
        "attachment",
        (string) $anhangname
      );
    } catch (InvalidArgumentException) {
      return array ();
    }
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/e_cfg.inc.php");
    $connection = estab_attachment_connection ($conf_4f_db);
    try {
      $identity = estab_read_session_identity ($_SESSION);
      if (!is_array ($identity)) {
        return array ();
      }
      $result = estab_read_with_locked_operational_scope (
        $connection,
        $identity,
        static fn (array $_scope): ?array => estab_read_attachment (
          $connection,
          $conf_4f_tbl ["anhang"],
          $conf_4f_tbl ["nachrichten"],
          $requested,
          $identity,
          true
        )
      );
      return is_array ($result) ? array (1 => $result) : array ();
    } finally {
      estab_attachment_close ($connection);
    }
  }

  /**********************************************************************\
    Funktion: readDirectory ()
    lese den Inhalt eines Verzeichnisses ein
    benoetigte Datei:
  \**********************************************************************/
  function readDirectory(){
    include ("../4fcfg/config.inc.php");
      $filesArr = array();
      if($ordner = dir($conf_4f ["ablage_dir"]))
      {
          while($datei = $ordner->read())
          {
          if($datei != "." && $datei != "..") array_push($filesArr,$datei);
          }
      }
      rsort ($filesArr);
      return $filesArr;
  }


/**********************************************************************\
   function: anhang_menue
\**********************************************************************/
  function anhang_menue ($notice = "", $messageContext = null){
    include ("../4fcfg/config.inc.php");
    $hasMessageContext = is_array ($messageContext);
    if (is_string ($notice) && $notice !== "") {
      echo "<p role=\"status\"><b>".estab_attachment_html ($notice)."</b></p>\n";
    }
    if (!$hasMessageContext) {
      echo "<p>Hier können Sie vorhandene Anhänge ansehen oder neue Dateien hochladen. ".
           "Anhänge werden erst aus einem geöffneten Nachrichtenvordruck übernommen.</p>\n";
    }
    /*
     * Die Kennung verbindet die Auswahlkaestchen der Dateiliste mit diesem
     * Formular. Die Liste steht seit der Umstellung auf das Tabellenbauteil
     * ausserhalb: Das Bauteil bringt ein eigenes Suchformular mit, und ein
     * Formular in einem Formular ist ungueltig -- der Browser wirft das
     * innere weg, und die Suche taete nichts.
     */
    echo "<form id=\"uploadform\" name=\"uploadform\" enctype=\"multipart/form-data\" method=\"post\" ".
         "action=\"anhang.php\" data-estab-requires-incident>\n";
    echo estab_csrf_field ()."\n";
    $attachmentFlowToken = is_array ($messageContext)
      ? ($messageContext ["flow_token"] ?? null)
      : null;
    if (is_string ($attachmentFlowToken)) {
      echo "<input type=\"hidden\" name=\"attachment_flow\" value=\"".
           estab_attachment_html ($attachmentFlowToken)."\">\n";
    }
    echo "<!-- anhang.php Formularelemente und andere Elemente innerhalb des Formulars -->\n";

        echo "<fieldset>";
    echo "<legend>Aktion:</legend>\n";
    echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\" bgcolor=\"#E0E0E0\">\n";
    echo "<tr>";
    echo "<input type=\"hidden\" name=\"anhang\" value=\"ah_auswahl\">\n";
    if ($hasMessageContext) {
      echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"ah_auswahl\" src=\"".$conf_design_path."/ok.gif\" alt=\"Ausgewählte Anhänge übernehmen\"></td>\n";
      echo "<td bgcolor=$color_button_nok><input type=\"image\" name=\"ah_abbrechen\" src=\"".$conf_design_path."/cancel.gif\" alt=\"Zurück zum Nachrichtenvordruck\"></td>\n";
    }
    echo "<td bgcolor=$color_button><input type=\"image\" name=\"ah_upload\" src=\"".$conf_design_path."/upload.gif\" alt=\"Neuen Anhang hochladen\"></td>\n";
    echo "</tr>\n";
    echo "</table>";
        echo "</fieldset>\n";

    echo "</form>\n";

    /*
     * Die Liste der Dateien kommt aus dem Tabellenbauteil (app/tabelle.php).
     *
     * Sie hatte weder Suche noch Sortierung und ihre eigene Gestaltung --
     * einer der sechs Befunde aus dem Betrieb. Die Auswahlkaestchen gehoeren
     * weiterhin zur Hochladeform darueber und haengen ueber form= an ihr.
     */
        echo "<fieldset>";
    echo "<legend>Liste der verfügbaren Dateien</legend>\n";
    try {
      $db_file_data = readFiles_from_db();
    } catch (Throwable $exception) {
      http_response_code (503);
      error_log ("eStab attachment list failed: ".$exception->getMessage ());
      echo "<p role=\"alert\">".
           "Die Anhangliste kann derzeit nicht geladen werden. ".
           "Bitte versuchen Sie es sp\xc3\xa4ter erneut.</p>\n";
      $db_file_data = array ();
    }
    echo estab_anhang_tabelle (
      $db_file_data,
      $hasMessageContext,
      $conf_4f,
      $conf_urlroot,
      $conf_web
    );
        echo "</fieldset>\n";
  }

/***********************************************************************\
   Steuerung über ein Sessioncookie
  anhang_menue();
     $_SESSION ["UPLOAD"] ==
        "fileselect" :

\***********************************************************************/

  function fileselect ($messageContext = null) {
    $instanz = new fileupload ();
    $instanz->message_context = $messageContext;
    $instanz->pre_html("Upload");
    try {
      $instanz->get_next_filename_from_db();
    } catch (Throwable $exception) {
      http_response_code (503);
      error_log ("eStab attachment reservation failed: ".$exception->getMessage ());
      echo "<p role=\"alert\"><b>Der Upload kann derzeit nicht vorbereitet werden. ".
           "Bitte versuchen Sie es später erneut.</b></p>";
      $instanz->post_html ();
      $_SESSION ["anhang_submenue"] = 110;
      return;
    }
    $data["newfilename"]  =  $instanz->fs_nextfilename;
    $data["kuerzel"]      =  $_SESSION["vStab_kuerzel"];
    $data["time"]         =  date("dHiMY");
    $instanz->fileselectform ($data, $messageContext);
    $instanz->post_html ();
    $_SESSION ["anhang_submenue"] =  110;
  }

  /****************************************************************************\
    Funktion: file_unselect
  \****************************************************************************/
  function file_unselect ($messageContext = null){
    $instanz = new fileupload ();
    $instanz->message_context = $messageContext;
    $instanz->reset_reservation ();
  }

  /** Cleanup must never replace the original upload result with a second 500. */
  function file_unselect_safely ($messageContext = null): void {
    try {
      file_unselect ($messageContext);
    } catch (Throwable $exception) {
      error_log (
        "eStab attachment best-effort cleanup failed: ".
        $exception->getMessage ()
      );
    }
  }

  /***************************************************************************\

  \***************************************************************************/
  /***************************************************************************\
  \***************************************************************************/
  function restore_formdata ($originContext, $originMessage = null) {
    require ("../4fcfg/fkt_rolle.inc.php");
    if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big> restore_formdata</big></big><br>";  }
    $draft = estab_attachment_origin_draft_find ($_SESSION, $originContext);
    $data = array_fill_keys (array (
      "01_medium", "01_datum", "01_zeichen", "02_zeichen",
      "03_zeichen", "04_richtung", "04_nummer", "05_gegenstelle",
      "06_befweg", "06_befwegausw", "07_durchspruch",
      "09_vorrangstufe",
      "10_anschrift", "11_rufnummer", "11_gesprnotiz", "12_anhang",
      "12_betreff", "12_inhalt", "12_abfzeit", "13_abseinheit",
      "14_zeichen", "14_funktion", "15_quitdatum", "15_quitzeichen",
      "recipient_matrix_revision",
      "17_vermerke",
    ), "");
    foreach ($data as $draftField => $unused) {
      if (is_string ($draft [$draftField] ?? null)) {
        $data [$draftField] = $draft [$draftField];
      }
    }
    $originTask = is_array ($originContext)
      && is_string ($originContext ["task"] ?? null)
      ? $originContext ["task"]
      : "";
    $restoreIdentity = is_array ($originContext)
      ? estab_attachment_origin_authority_identity ($originContext)
      : estab_auth_session_identity ($_SESSION);
    if (in_array ($originTask, array ("FM-Eingang", "FM-Eingang_Anhang"), true)) {
      $data ["13_abseinheit"] = "";
    }
    $requiredDistributionTokens = array ();
    if ($originTask === "Stab_gesprnoti") {
      $requiredDistributionTokens = array (
        (string) $redcopy2."_rt",
        (string) ($restoreIdentity ["funktion"] ?? "")."_gn",
      );
    }
    $distributionRequest = array ();
    if (is_string ($draft ["recipient_matrix_revision"] ?? null)) {
      $distributionRequest ["recipient_matrix_revision"] =
        $draft ["recipient_matrix_revision"];
    }
    for ($m=1; $m<=5; $m++){
      for ($n=1; $n<=4; $n++){
        $recipientKey = "16_".$m.$n;
        if (is_string ($draft [$recipientKey] ?? null)) {
          $distributionRequest [$recipientKey] = $draft [$recipientKey];
        }
      }
    }
    try {
      estab_workflow_require_recipient_matrix_revision (
        $distributionRequest,
        $empf_matrix,
        (string) $redcopy2
      );
      $data ["16_empf"] = estab_workflow_distribution_tokens (
        $distributionRequest,
        $empf_matrix,
        $requiredDistributionTokens
      );
    } catch (InvalidArgumentException $exception) {
      error_log (
        "eStab attachment recipient restore rejected: ".
        $exception->getMessage ()
      );
      estab_session_ui_abort (
        $_SESSION,
        409,
        "Empfängermatrix wurde geändert",
        "Die Empfängermatrix wurde während des Anhangvorgangs geändert. ".
          "Öffnen Sie den Nachrichtenvordruck erneut.",
        "messages"
      );
    }
    if ($originTask === "Stab_korrigieren") {
      if (!is_array ($originMessage)) {
        throw new EstabAttachmentContextException (
          "Der Korrekturdatensatz kann nicht wiederhergestellt werden."
        );
      }
      // Only fields editable in the correction form come from the draft.
      // Workflow state, routing, author/reviewer evidence and the immutable
      // return reason are rehydrated from the freshly authorised row.
      $editableCorrectionFields = array (
        "07_durchspruch",
        "09_vorrangstufe",
        "10_anschrift",
        "11_rufnummer",
        "11_gesprnotiz",
        "12_anhang",
        "12_betreff",
        "12_inhalt",
        "12_abfzeit",
      );
      $correctionData = $originMessage;
      foreach ($editableCorrectionFields as $editableCorrectionField) {
        if (array_key_exists ($editableCorrectionField, $data)) {
          $correctionData [$editableCorrectionField] =
            $data [$editableCorrectionField];
        }
      }
      $data = $correctionData;
    }
    $data ["00_lfd"] = $originTask === "Stab_korrigieren"
      ? (int) ($originContext ["record_id"] ?? 0)
      : "";
    return $data;
  }


  function fileselectwindow ($messageContext = null){
    require ("../4fcfg/dbcfg.inc.php");
    require ("../4fcfg/config.inc.php");
    try {
      estab_csrf_require_post ($_SERVER, $_POST);
    } catch (RuntimeException $exception) {
      http_response_code (400);
      error_log ("eStab attachment CSRF validation failed: ".$exception->getMessage ());
      echo "<big><big><b>Die Upload-Anforderung ist ungültig oder abgelaufen.</b></big></big>";
      anhang_menue ("", $messageContext);
      exit;
    }
    if (!isset($_POST["abbrechen_x"]) && isset($_POST["absenden_x"])) {
      try {
        $reservedName = estab_attachment_validate_reservation_name (
          is_string ($_POST ["fs_nextfilename"] ?? null) ? $_POST ["fs_nextfilename"] : "",
          $conf_4f ["hoheit"]
        );
        $capturedAt = estab_attachment_parse_tactical_time (
          is_string ($_POST ["fs_timestamp"] ?? null)
            ? $_POST ["fs_timestamp"]
            : ""
        );
        if ($capturedAt === null) {
          throw new EstabAttachmentUploadUserException (
            "Der Zeitstempel ist ungültig."
          );
        }
        $scopeConnection = estab_attachment_connection ($conf_4f_db);
        try {
          $activeIncident = estab_incident_require_active ($scopeConnection);
          $expectedIncidentId = (int) $activeIncident ["active_einsatz_id"];
        } finally {
          estab_attachment_close ($scopeConnection);
        }
        $upload = $_FILES ["upload"] ?? array (
          "tmp_name" => "",
          "name" => "",
          "error" => UPLOAD_ERR_NO_FILE,
        );
        estab_attachment_upload_browser_file (
          is_array ($upload) ? $upload : array (),
          $_POST ["fs_comment"] ?? "",
          estab_attachment_current_identity ($messageContext),
          $expectedIncidentId,
          $conf_4f_db,
          $conf_4f_tbl ["anhang"],
          $conf_4f_tbl ["protokoll"],
          (string) $conf_4f ["hoheit"],
          (string) $conf_4f ["ablage_dir"],
          session_id (),
          is_array ($messageContext) ? $messageContext : null,
          $_SERVER,
          $reservedName,
          $capturedAt
        );
      } catch (EstabAttachmentUploadUserException $exception) {
        error_log ("eStab attachment upload rejected: ".$exception->getMessage ());
        file_unselect_safely ($messageContext);
        echo "<p role=\"alert\"><b>".
             estab_attachment_html ($exception->getMessage ()).
             "</b></p>";
      } catch (Throwable $exception) {
        error_log ("eStab attachment upload failed: ".$exception->getMessage ());
        file_unselect_safely ($messageContext);
        echo "<p role=\"alert\"><b>".
             "Der Anhang konnte nicht sicher gespeichert werden.".
             "</b></p>";
      }
    } else {
      file_unselect_safely ($messageContext);
    }
    unset ($_SESSION ["UPLOAD"]);
    anhang_menue ("", $messageContext);
    exit;
  }


  if ($attachmentMessageContext) {
    // A message flow needs state 100 only during its server-side initial
    // include. Every later token-bearing request is an independent state-110
    // continuation and never reads or writes the global overview state.
    $attachmentMenuState = $attachmentInternalRequest ? 100 : 110;
  } else {
    $attachmentMenuState = $_SESSION ["anhang_menue"] ?? null;
    if ($attachmentMenuState === "100" || $attachmentMenuState === "110") {
      $attachmentMenuState = (int) $attachmentMenuState;
    }
    if ($attachmentMenuState !== 100 && $attachmentMenuState !== 110) {
      $attachmentMenuState = 110;
      $_SESSION ["anhang_menue"] = 110;
      if ($attachmentContextNotice === "") {
        $attachmentContextNotice =
          "Die Anhangübersicht wurde direkt geöffnet.";
      }
    }
  }

  /*
   * Die Anhangseite gab ihre Uebersicht bisher ohne Dokumentkopf aus: kein
   * <head>, kein Stylesheet, keine Angabe der Kodierung. Der Browser stellte
   * sie in seiner eigenen Schrift dar -- als einzige Seite der Anwendung. Mit
   * der Tabelle aus dem Bauteil faellt das sofort auf, weil deren Gestaltung
   * dort nicht ankam.
   *
   * fileselect() bringt seinen eigenen Kopf mit (pre_html); der Kopf hier
   * wird deshalb nur ausgegeben, wenn dieser Weg nicht genommen wird.
   */
  $attachmentUploadForm =
    isset ($_POST ["ah_upload_x"]) && $attachmentMenuState === 110;
  if (!$attachmentUploadForm) {
    echo "<!doctype html>\n<html lang=\"de\"><head>";
    echo "<meta charset=\"UTF-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>Anh\xc3\xa4nge</title>";
    echo estab_session_ui_stylesheet ()."\n";
    echo "</head><body class=\"estab-legacy-page\">\n";
  }

  switch ($attachmentMenuState){

    case 100: // Auswahlmenue
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big> 100 --> Auswahlmenue</big></big><br>";  }
        anhang_menue ("", $attachmentOriginContext);
        if (!$attachmentMessageContext) {
          $_SESSION["anhang_menue"] = 110;
        }
    break;

    case 110: // UPLOAD Menue
        if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big> 110 --> UPLOADMENUE</big></big><br>";  }        

        if ( isset ($_POST ["ah_upload_x"])){
          fileselect ($attachmentOriginContext);
          break;
        }

        if ( isset ($_POST ["ah_abbrechen_x"])){
          unset ($_SESSION["anhang_menue"]);
          unset ($_SESSION["anhang"]);
          $_SESSION["anhang_result"] = "abbrechen" ;
          header("Location: ".$conf_4f ["MainURL"]);
          exit;
        }

        if ( (isset ($_POST["absenden_x"] )) OR
             (isset ($_POST["abbrechen_x"]))){

          fileselectwindow ($attachmentOriginContext);
        }
        anhang_menue ($attachmentContextNotice, $attachmentOriginContext);
    break;

    default:
      http_response_code (500);
      echo "<p role=\"alert\"><b>Die Anhangübersicht konnte nicht initialisiert werden.</b></p>";
  }

  if (!$attachmentUploadForm) {
    echo "\n</body></html>\n";
  }



if ( debug == true ){
  echo "<br><br>\n";
  echo "------ anhang.php 985------";
  echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
  // echo "SERVER  ="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
  echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
}

?>
