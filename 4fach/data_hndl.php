<?php
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Data hndl</big><br>\n";}
/*****************************************************************************\
   Datei: data_hndl.php

   benoetigte Dateien:

   Beschreibung:

   Funktionen:

     check_save_user ()
     check_and_save ($data, $activeCommandPostName, $expectedIncidentId)
     legere_nuntium ($krzl, $fktn, $lfd);
         ==> Zeit wann die Nachricht gelesen wurde,
         oder auch nicht!
   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

define ("validate",true);     // Soll das Formular überprüft werden
require_once __DIR__ . "/tools.php";
require_once __DIR__ . "/../app/auth.php";
require_once __DIR__ . "/../app/assignment.php";
require_once __DIR__ . "/../app/dynamic_schema.php";
require_once __DIR__ . "/../app/message_repository.php";
require_once __DIR__ . "/../app/message_transport.php";
require_once __DIR__ . "/../app/password_policy.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/../app/self_registration.php";
require_once __DIR__ . "/../app/workflow.php";

if (validate){
  require_once __DIR__ . "/vali_data.php";
}

/*******************************************************************************
  Benutzeranmeldung Cookies setzen und eintragen in die Datenbank
  1. Sind Cookiedaten vorhanden
     JA   --> Pruefe Cookiedaten mit Datenbankeintraege
          --> Datenabgleich
     NEIN --> Neueintrag Datenbank und COOKIES
********************************************************************************/

/*******************************************************************************\
    Funktion:  check_save_user ()

\*******************************************************************************/
function estab_login_account_lock_name (
  string $database,
  string $table,
  string $code
): string {
  return "estab:login:".substr (
    hash ("sha256", $database."\0".$table."\0".$code),
    0,
    52
  );
}

function estab_login_lock_timeout (): int {
  $timeout = defined ("ESTAB_LOGIN_LOCK_TIMEOUT_SECONDS")
    ? constant ("ESTAB_LOGIN_LOCK_TIMEOUT_SECONDS")
    : 10;
  if (!is_int ($timeout) || $timeout < 0 || $timeout > 30) {
    throw new RuntimeException ("Ungültiges Zeitlimit für die Kontoanmeldung");
  }
  return $timeout;
}

function estab_login_acquire_account_lock (
  mysqli $connection,
  string $lockName
): void {
  $timeout = estab_login_lock_timeout ();
  $statement = $connection->prepare ("SELECT GET_LOCK(?, ?)");
  if (!$statement) {
    throw new RuntimeException ("Kontosperre konnte nicht vorbereitet werden");
  }
  try {
    $statement->bind_param ("si", $lockName, $timeout);
    if (!$statement->execute ()) {
      throw new RuntimeException ("Kontosperre konnte nicht angefordert werden");
    }
    $result = $statement->get_result ();
    $row = $result->fetch_row ();
    $result->free ();
    if (!is_array ($row) || (string) ($row [0] ?? "") !== "1") {
      throw new RuntimeException ("Dieses Konto wird bereits angemeldet");
    }
  } finally {
    $statement->close ();
  }
}

function estab_login_release_account_lock (
  mysqli $connection,
  string $lockName
): void {
  $statement = $connection->prepare ("SELECT RELEASE_LOCK(?)");
  if (!$statement) {
    throw new RuntimeException ("Kontosperre konnte nicht freigegeben werden");
  }
  try {
    $statement->bind_param ("s", $lockName);
    if (!$statement->execute ()) {
      throw new RuntimeException ("Kontosperre konnte nicht freigegeben werden");
    }
    $result = $statement->get_result ();
    $row = $result->fetch_row ();
    $result->free ();
    if (!is_array ($row) || (string) ($row [0] ?? "") !== "1") {
      throw new RuntimeException ("Kontosperre ging verloren");
    }
  } finally {
    $statement->close ();
  }
}

function estab_login_write_audit (
  mysqli $connection,
  string $table,
  string $event,
  string $details
): void {
  $query = "INSERT INTO ".estab_auth_table ($table)
    ." (`p_zeit`, `p_was`, `p_ereignis`) VALUES (CURRENT_TIMESTAMP, ?, ?)";
  $statement = $connection->prepare ($query);
  if (!$statement) {
    throw new RuntimeException ("Anmeldeprotokoll konnte nicht vorbereitet werden");
  }
  try {
    $statement->bind_param ("ss", $event, $details);
    if (!$statement->execute ()) {
      throw new RuntimeException ("Anmeldeprotokoll konnte nicht geschrieben werden");
    }
  } finally {
    $statement->close ();
  }
}

function estab_login_clear_session_identity (): void {
  unset (
    $_SESSION ["vStab_benutzer"],
    $_SESSION ["vStab_kuerzel"],
    $_SESSION ["vStab_funktion"],
    $_SESSION ["vStab_rolle"],
    $_SESSION ["ROLLE"]
  );
  $_SESSION ["menue"] = "LOGIN";
}

function check_save_user (array $loginData, string &$loginError) {
  include ("../4fcfg/dbcfg.inc.php");
  include ("../4fcfg/e_cfg.inc.php");

  $loginFlow = estab_auth_login_flow ($loginData);
  $explicitLoginFlow = array_key_exists ("login_flow", $loginData);
  if ($loginFlow === null) {
    $_SESSION ["menue"] = "LOGIN";
    $loginError = "Bitte prüfen Sie Name, Kürzel, Funktion und Kennwort.";
    return true;
  }
  $connection = null;
  $policyLockName = null;
  $policyLockAcquired = false;
  $selfRegistrationLockName = null;
  $selfRegistrationLockAcquired = false;
  $accountLockName = null;
  $accountLockAcquired = false;
  $passwordPolicyLockName = null;
  $passwordPolicyLockAcquired = false;
  $transactionActive = false;
  try {
    $connection = estab_auth_connect ($conf_4f_db);
    $policyLockName = estab_assignment_acquire_policy_lock (
      $connection,
      (string) $conf_4f_db ["datenbank"],
      $conf_4f_tbl ["empfmtx"]
    );
    $policyLockAcquired = true;
    $validation = estab_auth_validate_login_with_roles (
      $loginData,
      estab_assignment_function_roles (
        $connection,
        $conf_4f_tbl ["empfmtx"]
      )
    );
    if (!$validation ["valid"]) {
      $_SESSION ["menue"] = "LOGIN";
      $loginError = "Bitte prüfen Sie Name, Kürzel, Funktion und Kennwort.";
      return true;
    }
    $login = $validation ["data"];
    $accountLockName = estab_login_account_lock_name (
      $conf_4f_db ["datenbank"],
      $conf_4f_tbl ["benutzer"],
      $login ["kuerzel"]
    );
    // Existing logins deliberately remain independent of later policy
    // changes. Registration takes the assignment, self-registration and
    // password-policy locks in this fixed order before the per-account lock.
    if ($loginFlow === "new") {
      $selfRegistrationLockName = estab_self_registration_acquire_lock (
        $connection,
        (string) $conf_4f_db ["datenbank"]
      );
      $selfRegistrationLockAcquired = true;
      $selfRegistrationPolicy = estab_self_registration_load ($connection);
      if (!estab_self_registration_is_allowed ($selfRegistrationPolicy)) {
        $_SESSION ["menue"] = "LOGIN";
        $loginError =
          ($selfRegistrationPolicy ["mode"] ?? null)
            === ESTAB_SELF_REGISTRATION_MODE_UNTIL
          ? "Der freigegebene Zeitraum für die Kontoanlage ist abgelaufen. Lassen Sie die Selbstregistrierung in der Administration erneut freigeben."
          : "Neue Konten können hier derzeit nicht selbst angelegt werden. Lassen Sie die Selbstregistrierung in der Administration freigeben.";
        return true;
      }
      $passwordPolicyLockName = estab_password_policy_acquire_lock (
        $connection,
        (string) $conf_4f_db ["datenbank"]
      );
      $passwordPolicyLockAcquired = true;
    }
    estab_login_acquire_account_lock ($connection, $accountLockName);
    $accountLockAcquired = true;
    if (!$connection->begin_transaction ()) {
      throw new RuntimeException ("Kontotransaktion konnte nicht gestartet werden");
    }
    $transactionActive = true;

    // The lookup must happen after acquiring the account lock. This makes
    // two simultaneous registrations deterministic even when no row exists.
    $dbUser = estab_auth_fetch_user (
      $connection,
      $conf_4f_tbl ["benutzer"],
      $login ["kuerzel"]
    );
    $ip = estab_auth_remote_ip ($_SERVER);
    $forwardedIp = estab_auth_forwarded_ip (
      $_SERVER,
      estab_request_trusts_proxy_headers ($_SERVER)
    );

    $dbaccess = new db_access ($conf_4f_db ["server"],
                              $conf_4f_db ["datenbank"],
                              $conf_4f_tbl ["benutzer"],
                              $conf_4f_db ["user"],
                              $conf_4f_db ["password"] );

    if (is_array ($dbUser)) {
      if ($loginFlow === "new" && $explicitLoginFlow) {
        $loginError = "Dieses Kürzel ist bereits vergeben. Melden Sie sich mit dem bestehenden Konto an oder wählen Sie ein anderes Kürzel.";
        return true;
      }
      $nameMatches = hash_equals ((string) $dbUser ["benutzer"], $login ["benutzer"]);
      $passwordCheck = estab_auth_verify_password ($login ["password"], (string) $dbUser ["password"]);
      if (!$nameMatches || !$passwordCheck ["valid"]) {
        $loginError = "Name, Kürzel oder Kennwort stimmen nicht mit dem bestehenden Konto überein.";
        return true;
      }
      if (estab_auth_account_is_blocked ($dbUser)) {
        $loginError = "Dieses Konto ist administrativ gesperrt. Wenden Sie sich an die zuständige Stelle.";
        return true;
      }

      $wasInactive = ((int) $dbUser ["aktiv"] === 0);
      if (!estab_auth_assignment_allowed ($dbUser, $login ["funktion"])) {
        $loginError = "Dieses Konto ist einer anderen Funktion zugeordnet. Wählen Sie die administrativ zugewiesene Funktion.";
        return true;
      }
      $loginPermissionMode = estab_auth_active_permission_mode ($connection);
      if (
        $loginPermissionMode === ESTAB_PERMISSION_MODE_LOOSE
        && !estab_auth_shift_access_allowed ($connection, $login ["kuerzel"])
      ) {
        $loginError = "Der Zugang dieses Kontos ist über die optionale Schichtplanung derzeit deaktiviert. Wenden Sie sich an die zuständige Stelle.";
        return true;
      }

      // DDL commits implicitly in MariaDB. It therefore runs on its own
      // connection, under a function-scoped advisory lock, before the account
      // is activated. A failed or interrupted reconciliation leaves the
      // account untouched and can safely be retried.
      if (estab_dynamic_schema_hat_requires_tables (
        $login ["funktion"],
        $login ["rolle"]
      )) {
        $usertablename = $conf_4f_tbl ["usrtblprefix"].strtolower ($login ["funktion"])."_".$login ["kuerzel"];
        $fkttblname = $conf_4f_tbl ["usrtblprefix"]."_fkt_".strtolower ($login ["funktion"]);
        $dbaccess->create_user_table ($usertablename, $fkttblname);
      }

      if (session_status () !== PHP_SESSION_ACTIVE || !session_regenerate_id (true)) {
        throw new RuntimeException ("Die Sitzung konnte nicht erneuert werden");
      }
      unset ($_SESSION ["estab_csrf_token"]);
      if (!estab_auth_session_id_is_valid (session_id ())) {
        throw new RuntimeException ("Die erneuerte Sitzungskennung ist ungültig");
      }

      $storedPassword = is_string ($passwordCheck ["replacement"])
        ? $passwordCheck ["replacement"]
        : (string) $dbUser ["password"];
      estab_auth_update_user ($connection, $conf_4f_tbl ["benutzer"], array (
        "funktion" => $login ["funktion"],
        "rolle" => $login ["rolle"],
        "sid" => session_id (),
        "ip" => $ip,
        "fwdip" => $forwardedIp,
        "password" => $storedPassword,
        "kuerzel" => $login ["kuerzel"],
      ));

      $event = $wasInactive ? "Anmelden" : "Sessiondaten neu setzen";
      estab_login_write_audit (
        $connection,
        $conf_4f_tbl ["protokoll"],
        $event,
        estab_auth_login_audit_details (
          $wasInactive ? "existing_login" : "session_refresh",
          $login,
          session_id (),
          $ip
        )
      );
      if (!$connection->commit ()) {
        throw new RuntimeException ("Kontotransaktion konnte nicht abgeschlossen werden");
      }
      $transactionActive = false;

      // Only a committed account row may become an authenticated PHP session.
      $_SESSION ["vStab_benutzer"] = $login ["benutzer"];
      $_SESSION ["vStab_kuerzel"] = $login ["kuerzel"];
      $_SESSION ["vStab_funktion"] = $login ["funktion"];
      $_SESSION ["vStab_rolle"] = $login ["rolle"];
      unset ($_SESSION ["estab_duty_assignment_id"]);
      $_SESSION ["menue"] = "ROLLE";
      $_SESSION ["ROLLE"] = $login ["rolle"];

      return false;
    }

    if ($loginFlow === "existing") {
      $loginError = "Name, Kürzel oder Kennwort stimmen nicht mit einem bestehenden Konto überein.";
      return true;
    }
    if (!$passwordPolicyLockAcquired) {
      throw new RuntimeException (
        "Kennwortrichtlinie der Selbstregistrierung wurde nicht gesperrt"
      );
    }
    $registrationPassword = estab_password_policy_validate_password (
      $login ["password"],
      $loginData ["kennwort2"] ?? null,
      estab_password_policy_load ($connection)
    );

    if (session_status () !== PHP_SESSION_ACTIVE || !session_regenerate_id (true)) {
      throw new RuntimeException ("Die Sitzung konnte nicht erneuert werden");
    }
    unset ($_SESSION ["estab_csrf_token"]);
    if (!estab_auth_session_id_is_valid (session_id ())) {
      throw new RuntimeException ("Die erneuerte Sitzungskennung ist ungültig");
    }

    $passwordHash = estab_auth_hash_password ($registrationPassword);
    unset ($registrationPassword);
    if (!is_string ($passwordHash)) {
      throw new RuntimeException ("Kennwort konnte nicht sicher gespeichert werden");
    }
    $registrationInserted = estab_self_registration_insert_user_if_allowed (
      $connection,
      $conf_4f_tbl ["benutzer"],
      array (
        "benutzer" => $login ["benutzer"],
        "kuerzel" => $login ["kuerzel"],
        "funktion" => $login ["funktion"],
        "rolle" => $login ["rolle"],
        "sid" => session_id (),
        "ip" => $ip,
        "fwdip" => $forwardedIp,
        "password" => $passwordHash,
      )
    );
    if (!$registrationInserted) {
      unset ($passwordHash);
      $loginError = "Der freigegebene Zeitraum für die Kontoanlage ist inzwischen abgelaufen oder wurde beendet. Es wurde kein Konto angelegt.";
      return true;
    }

    // The guarded INSERT remains uncommitted on the transactional connection.
    // Only after that atomic policy decision may the isolated connection create
    // dynamic tables. This prevents an expired registration window from
    // leaving tables behind for an account that was never admitted.
    if (estab_dynamic_schema_hat_requires_tables (
      $login ["funktion"],
      $login ["rolle"]
    )) {
      $usertablename = $conf_4f_tbl ["usrtblprefix"].strtolower ($login ["funktion"])."_".$login ["kuerzel"];
      $fkttblname = $conf_4f_tbl ["usrtblprefix"]."_fkt_".strtolower ($login ["funktion"]);
      $dbaccess->create_user_table ($usertablename, $fkttblname);
    }

    estab_login_write_audit (
      $connection,
      $conf_4f_tbl ["protokoll"],
      "Anmelden",
      estab_auth_login_audit_details (
        "self_registration",
        $login,
        session_id (),
        $ip
      )
    );
    if (!$connection->commit ()) {
      throw new RuntimeException ("Kontotransaktion konnte nicht abgeschlossen werden");
    }
    $transactionActive = false;

    $_SESSION ["vStab_benutzer"] = $login ["benutzer"];
    $_SESSION ["vStab_kuerzel"] = $login ["kuerzel"];
    $_SESSION ["vStab_funktion"] = $login ["funktion"];
    $_SESSION ["vStab_rolle"] = $login ["rolle"];
    unset ($_SESSION ["estab_duty_assignment_id"]);
    $_SESSION ["menue"] = "ROLLE";
    $_SESSION ["ROLLE"] = $login ["rolle"];

    return false;
  } catch (
    EstabPasswordPolicyInputException
    |EstabPasswordPolicyBusyException
    |EstabSelfRegistrationBusyException $exception
  ) {
    if ($transactionActive && $connection instanceof mysqli) {
      $connection->rollback ();
      $transactionActive = false;
    }
    unset ($registrationPassword, $passwordHash);
    estab_login_clear_session_identity ();
    $loginError = $exception->getMessage ();
    return true;
  } catch (Throwable $exception) {
    if ($transactionActive && $connection instanceof mysqli) {
      $connection->rollback ();
      $transactionActive = false;
    }
    estab_login_clear_session_identity ();
    error_log ("eStab authentication failed: ".$exception->getMessage ());
    $loginError = "Die Anmeldung konnte technisch nicht abgeschlossen werden. Versuchen Sie es erneut oder wenden Sie sich an die zuständige Stelle.";
    return true;
  } finally {
    if ($transactionActive && $connection instanceof mysqli) {
      $connection->rollback ();
    }
    if (
      $connection instanceof mysqli
      && $accountLockAcquired
      && is_string ($accountLockName)
      && $accountLockName !== ""
    ) {
      try {
        estab_login_release_account_lock ($connection, $accountLockName);
      } catch (Throwable $exception) {
        // Closing the owning connection below is the fail-safe release.
        error_log ("eStab account lock cleanup failed: ".$exception->getMessage ());
      }
    }
    if (
      $connection instanceof mysqli
      && $passwordPolicyLockAcquired
      && is_string ($passwordPolicyLockName)
      && $passwordPolicyLockName !== ""
    ) {
      try {
        estab_password_policy_release_lock (
          $connection,
          $passwordPolicyLockName
        );
      } catch (Throwable $exception) {
        error_log (
          "eStab password policy lock cleanup failed: ".
          $exception->getMessage ()
        );
      }
    }
    if (
      $connection instanceof mysqli
      && $selfRegistrationLockAcquired
      && is_string ($selfRegistrationLockName)
      && $selfRegistrationLockName !== ""
    ) {
      try {
        estab_self_registration_release_lock (
          $connection,
          $selfRegistrationLockName
        );
      } catch (Throwable $exception) {
        error_log (
          "eStab self-registration lock cleanup failed: ".
          $exception->getMessage ()
        );
      }
    }
    if (
      $connection instanceof mysqli
      && $policyLockAcquired
      && is_string ($policyLockName)
      && $policyLockName !== ""
    ) {
      try {
        estab_assignment_release_policy_lock ($connection, $policyLockName);
      } catch (Throwable $exception) {
        // Closing the owning connection below is the fail-safe release.
        error_log ("eStab assignment policy lock cleanup failed: ".$exception->getMessage ());
      }
    }
    if ($connection instanceof mysqli) {
      estab_auth_close ($connection);
    }
  }
} // function save_user

/**
 * Merge unsaved input into an authoritative message row for one exact task.
 *
 * The database row is always the base. The request may only contribute fields
 * which the official form makes editable in this workflow step. Read-only
 * timestamps, marks, routing, review notes and distribution evidence therefore
 * cannot disappear or be forged on a validation/conflict response.
 */
function estab_rehydrate_authoritative_message_form (
  array $authoritative,
  array $submitted,
  string $task,
  array $serverValues = array ()
): array {
  $editableFields = match ($task) {
    "Stab_korrigieren" => array (
      "06_befwegausw",
      "07_durchspruch",
      "09_vorrangstufe",
      "10_anschrift",
      "11_rufnummer",
      "12_anhang",
      "12_betreff",
      "12_inhalt",
      "12_abfzeit",
      "estab_route_error",
    ),
    "LdF-Eingang" => array (
      "01_medium",
      "02_zeit",
      "13_abseinheit",
      "incoming_transport_confirmed",
      "incoming_transport_correction_reason",
      "estab_route_error",
    ),
    "LdF-Ausgang" => array (
      "02_zeit",
      "05_gegenstelle",
      "fernmeldeplan_eintrag_id",
      "ldf_rueckgabegrund",
      "estab_route_error",
    ),
    "FM-Ausgang" => array (
      "03_datum",
      "transportweg_bestaetigt",
      "transport_rueckgabegrund",
      "estab_route_error",
    ),
    default => throw new InvalidArgumentException (
      "Unbekannter Workflow für die Formularwiederherstellung"
    ),
  };

  $editable = array ();
  foreach ($editableFields as $field) {
    $value = $submitted [$field] ?? "";
    $editable [$field] = is_string ($value) ? $value : "";
  }

  $rehydrated = array_replace (
    $authoritative,
    $editable,
    $serverValues
  );
  $rehydrated ["00_lfd"] = $authoritative ["00_lfd"] ?? "";
  $rehydrated ["task"] = $task;

  return $rehydrated;
}

/** Rebuild an exact LdF/A-W form from its still locked database row. */
function estab_rehydrate_locked_operator_form (
  mysqli $connection,
  string $table,
  string $operatorCode,
  string $task,
  array $submitted
): ?array {
  [$direction, $status, $serverValues] = match ($task) {
    "LdF-Eingang" => array (
      "E",
      1,
      array ("02_zeichen" => $operatorCode),
    ),
    "LdF-Ausgang" => array (
      "A",
      1,
      array ("02_zeichen" => $operatorCode),
    ),
    "FM-Ausgang" => array (
      "A",
      2,
      array ("03_zeichen" => $operatorCode),
    ),
    default => throw new InvalidArgumentException (
      "Unbekannte gesperrte Nachrichtenstufe"
    ),
  };
  $locked = estab_message_fetch_locked_operator_stage (
    $connection,
    $table,
    $submitted ["00_lfd"] ?? null,
    $operatorCode,
    $direction,
    $status
  );
  if (!is_array ($locked)) {
    return null;
  }

  $rehydrated = estab_rehydrate_authoritative_message_form (
    $locked,
    $submitted,
    $task,
    $serverValues
  );
  if ($task === "LdF-Eingang") {
    $rehydrated ["incoming_transport_original_medium"] =
      (string) ($locked ["01_medium"] ?? "");
  }

  return $rehydrated;
}

/** Rebuild the LdF incoming form from the still locked database row. */
function estab_rehydrate_ldf_incoming_form (
  mysqli $connection,
  string $table,
  string $operatorCode,
  array $submitted
): ?array {
  return estab_rehydrate_locked_operator_form (
    $connection,
    $table,
    $operatorCode,
    "LdF-Eingang",
    $submitted
  );
}

/** Rebuild an authorised returned outgoing form from the active incident. */
function estab_rehydrate_staff_correction_form (
  mysqli $connection,
  string $table,
  array $actor,
  string $commandPostName,
  array $submitted
): ?array {
  $message = estab_message_fetch_by_id (
    $connection,
    $table,
    $submitted ["00_lfd"] ?? null
  );
  if (
    !is_array ($message)
    || !estab_message_object_allowed (
      $actor,
      "staff-correction",
      $message,
      true
    )
    || (string) ($message ["x02_sperre"] ?? "") !== "f"
    || (string) ($message ["x03_sperruser"] ?? "") !== ""
  ) {
    return null;
  }

  return estab_rehydrate_authoritative_message_form (
    $message,
    $submitted,
    "Stab_korrigieren",
    array (
      // The type is immutable during a correction, but a returned
      // conversation note must keep its original marker.
      "11_gesprnotiz" => (string) ($message ["11_gesprnotiz"] ?? "f"),
      "13_abseinheit" => $commandPostName,
      "14_zeichen" => (string) ($actor ["kuerzel"] ?? ""),
      "14_funktion" => (string) ($actor ["funktion"] ?? ""),
    )
  );
}

/** Render a fail-closed conflict when an editable message stage was lost. */
function estab_render_message_stage_conflict (string $stage): never {
  http_response_code (409);
  echo "<div role=\"alert\" class=\"estab-message-transport-conflict\">";
  echo "<h2>Nachricht wurde zwischenzeitlich geändert</h2>";
  echo "<p>".estab_auth_html ($stage).
    " oder der Bearbeitungsstand ist nicht mehr gültig. ";
  echo "Öffnen Sie die Nachricht erneut aus der Warteschlange.</p></div>";
  exit;
}

/** Render the established LdF conflict wording. */
function estab_render_ldf_stage_conflict (): never {
  estab_render_message_stage_conflict ("Die LdF-Sperre");
}


/*****************************************************************************\

\*****************************************************************************/
function check_and_save ($data, $activeCommandPostName, $expectedIncidentId){
  if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>check_and_save</big><br>\n";}
  include ("../4fcfg/config.inc.php");
  include ("../4fcfg/dbcfg.inc.php");
  include ("../4fcfg/e_cfg.inc.php");
  include ("../4fcfg/fkt_rolle.inc.php");

  $activeCommandPostName = estab_incident_command_post_name (array (
    "fuehrungsstellenname" => $activeCommandPostName,
  ));
  $expectedIncidentId = estab_incident_positive_id ($expectedIncidentId);

  $browserData = is_array ($data) ? $data : array ();
  if (array_key_exists ("16_gncopy", $browserData)) {
    // Recipient boxes inside the official form are the only browser-owned
    // distribution controls. The former separate field fails closed even
    // when an old client submits an empty value.
    estab_workflow_forbid ();
  }
  $data = array_replace (array_fill_keys (array (
    "00_lfd", "01_medium", "01_datum", "01_zeichen",
    "02_zeit", "02_zeichen", "03_datum", "03_zeichen",
    "05_gegenstelle", "06_befweg", "06_befwegausw",
    "fernmeldeplan_eintrag_id",
    "transportweg_bestaetigt",
    "transport_rueckgabegrund",
    "ldf_rueckgabegrund",
    "incoming_transport_confirmed",
    "incoming_transport_correction_reason",
    "07_durchspruch", "08_befhinweis", "08_befhinwausw",
    "09_vorrangstufe", "10_anschrift", "11_rufnummer",
    "11_gesprnotiz", "12_anhang", "12_betreff", "12_inhalt",
    "12_abfzeit", "13_abseinheit",
    "14_zeichen", "14_funktion", "15_quitdatum",
    "15_quitzeichen", "16_empf",
    "recipient_matrix_revision", "17_vermerke",
    "task"
  ), ""), $browserData);

  $sessionCode = (string) ($_SESSION ["vStab_kuerzel"] ?? "");
  $sessionFunction = (string) ($_SESSION ["vStab_funktion"] ?? "");
  $sessionUser = (string) ($_SESSION ["vStab_benutzer"] ?? "");
  $sessionRole = (string) ($_SESSION ["vStab_rolle"] ?? "");
  if (
    preg_match ("/\\A[a-z0-9_]{1,6}\\z/D", $sessionCode) !== 1
    || trim ($sessionUser) === ""
    || trim ($sessionFunction) === ""
    || !in_array ($sessionRole, array ("Stab", "FB", "Fernmelder"), true)
    || strlen ($sessionFunction) > 25
    || str_contains ($sessionFunction, "\0")
  ) {
    throw new RuntimeException ("Ungültige angemeldete Nachrichtenidentität");
  }
  $attachmentReadIdentity = estab_read_session_identity ($_SESSION);
  if (!is_array ($attachmentReadIdentity)) {
    throw new EstabReadPermissionException ("Anmeldung erforderlich.");
  }
  // Resolve the exact acting function from the authenticated base account,
  // its server-loaded LOOSE additions and the original closed browser route.
  // Do this before the legacy normaliser's empty compatibility fields can be
  // mistaken for browser overposts. Browser fields still never grant or
  // extend the actor function.
  $messageActor = estab_workflow_identity_for_selected_route (
    $attachmentReadIdentity,
    $GLOBALS ["workflowSelectedIdentity"] ?? null,
    $browserData
  );
  // From here on every function-scoped mark, state table and recipient copy
  // must use the exact server-resolved route actor.  In LOOSE this may be one
  // explicitly assigned additional function; the raw session still contains
  // the account's fixed primary function and must not overwrite that actor.
  $sessionCode = (string) ($messageActor ["kuerzel"] ?? "");
  $sessionFunction = (string) ($messageActor ["funktion"] ?? "");
  $sessionUser = (string) ($messageActor ["benutzer"] ?? "");
  $sessionRole = (string) ($messageActor ["rolle"] ?? "");
  $attachmentReadIdentity = $messageActor;
  $messageActionTask = (string) ($data ["task"] ?? "");
  $messageActionToken = null;
  if (
    in_array ($messageActionTask, estab_message_action_tasks (), true)
    && array_key_exists ("message_attachment_request_token", $browserData)
  ) {
    // The raw high-entropy token remains session/browser state. Persist only
    // the server-computed hash in the immutable workflow event.
    estab_message_action_token_hash (
      $browserData ["message_attachment_request_token"]
    );
    $messageActionToken =
      (string) $browserData ["message_attachment_request_token"];
  }
  $attachmentAuthorizer = static function (
    mysqli $connection,
    int $incidentId,
    mixed $attachmentList,
    ?array $writeMessage = null
  ) use (
    $conf_4f_tbl,
    $attachmentReadIdentity,
    $messageActionTask
  ): void {
    $attachmentWriteScope = (
      $messageActionTask === "Stab_korrigieren"
      && is_array ($writeMessage)
    )
      ? estab_read_attachment_write_scope (
          $attachmentReadIdentity,
          "staff-correction",
          $writeMessage
        )
      : null;
    estab_read_require_attachment_use_scope (
      $connection,
      (string) $conf_4f_tbl ["anhang"],
      (string) $conf_4f_tbl ["nachrichten"],
      $incidentId,
      $attachmentList,
      $attachmentReadIdentity,
      $attachmentWriteScope
    );
  };

  // Local marks and the local sender identity are signed account attributes.
  // The browser may display them, but it can neither choose nor forge them.
  switch ($data ["task"]) {
    case "FM-Eingang":
    case "FM-Eingang_Anhang":
      $data ["01_zeichen"] = $sessionCode;
    break;

    case "Stab_schreiben":
    case "Stab_korrigieren":
      $data ["13_abseinheit"] = $activeCommandPostName;
      $data ["14_zeichen"] = $sessionCode;
      $data ["14_funktion"] = $sessionFunction;
    break;

    case "Stab_gesprnoti":
      // The author records the original conversation. Si, LdF and A/W add
      // their own evidence only in the following outgoing workflow stages.
      $data ["01_zeichen"] = $sessionCode;
      $data ["13_abseinheit"] = $activeCommandPostName;
      $data ["14_zeichen"] = $sessionCode;
      $data ["14_funktion"] = $sessionFunction;
      $data ["15_quitdatum"] = "";
      $data ["15_quitzeichen"] = "";
    break;

    case "Stab_sichten":
      $data ["15_quitzeichen"] = $sessionCode;
    break;

    case "LdF-Eingang":
    case "LdF-Ausgang":
      $data ["02_zeichen"] = $sessionCode;
    break;

    case "FM-Ausgang":
      $data ["03_zeichen"] = $sessionCode;
    break;
  }

  if (($data ["11_gesprnotiz"] ?? "") == "on") {
    $data ["11_gesprnotiz"] = "t" ;
  }  else {
    $data ["11_gesprnotiz"] = "f" ;
  }

  // Store raw UTF-8. Encoding belongs exclusively to the output context.
  foreach (array ("12_inhalt", "17_vermerke") as $rawTextField) {
    if (!isset ($data [$rawTextField]) || !is_string ($data [$rawTextField])) {
      $data [$rawTextField] = "";
    }
  }

  $messageConnection = estab_message_connect ($conf_4f_db);
  $messageActionLockName = null;
  try {
    try {
      $messageIncident = estab_incident_require_active ($messageConnection);
      estab_incident_command_post_name ($messageIncident);
      if (
        (int) $messageIncident ["active_einsatz_id"] !== $expectedIncidentId
      ) {
        throw new EstabIncidentConflictException (
          "Der aktive Einsatz hat sich seit dem Öffnen des " .
          "Nachrichtenvordrucks geändert. Die Eingabe wurde nicht gespeichert."
        );
      }
    } catch (
      EstabNoActiveIncidentException
      | EstabIncidentConfigurationException $exception
    ) {
      if (ob_get_level () > 0) {
        @ob_clean ();
      }
      http_response_code (409);
      header ("Content-Type: text/html; charset=UTF-8");
      header ("Cache-Control: no-store");
      $configurationMissing =
        $exception instanceof EstabIncidentConfigurationException;
      echo "<!doctype html><html lang=\"de\"><meta charset=\"UTF-8\">";
      echo "<title>Eingaben gesperrt</title><body>";
      echo "<h1>Keine Eingabe möglich</h1>";
      echo $configurationMissing
        ? "<p>Für den aktiven Einsatz fehlt der Name der Führungsstelle. ".
          "Legen Sie ihn zuerst in der Einsatzverwaltung fest.</p>"
        : "<p>Derzeit ist kein Einsatz aktiv. Legen Sie in der Administration ".
          "einen Einsatz an oder aktivieren Sie einen vorhandenen Einsatz.</p>";
      echo "</body></html>";
      exit;
    }

    if ($messageActionToken !== null) {
      $messageActionLockName = estab_message_action_lock (
        $messageConnection,
        $messageActionToken
      );
      $committedActionId = estab_message_committed_action_id (
        $messageConnection,
        $expectedIncidentId,
        $messageActionToken,
        $messageActionTask,
        $attachmentReadIdentity,
        $messageActionTask === "Stab_korrigieren"
          ? ($data ["00_lfd"] ?? null)
          : null
      );
      if (is_int ($committedActionId)) {
        // The prior request committed its message and event atomically. The
        // advisory lock makes this lookup plus the following mutation one
        // serial action even when a session backend does not lock requests.
        return;
      }
    }
	switch ($data["task"]){
		case "FM-Eingang":
    	case "FM-Eingang_Anhang":
    	if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>FM-Eingang, FM-Eingang_Anhang</big><br>\n";}
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
			// Until Si completes the substantive review, only the authoritative
			// Lage/Dokumentation red copy exists. Never append browser data.
			$data ["16_empf"] = $redcopy2."_rt,";
			if ($data ["01_datum"] == "" ) { $data ["01_datum"] = date ("Hi") ; }
			if ($data ["12_abfzeit"] == "" ) { $data ["12_abfzeit"] = date ("Hi") ; }
			if (validate){
         		/*----------------------------------------------------*/
				if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>"; var_dump ($data); echo "<br>\n";}         	
        	
				$vali = new vali_data_form ( $data ) ;
      	  	$result = $vali->validatethis (); //checkdata ();
/*        	if (debug){
          echo "DATAHNDL292=";
          echo "<b>RESULT</b>";
          var_dump ($result); echo "<br>";
          echo "<b>vali-data</b>";
          var_dump ($vali->i_data); echo "<br>";
          echo "<b>DATA</b>";
          var_dump ($data); echo "<br>";
          echo "<b>vali-VALIDATE</b>";
          var_dump ($vali->validate); echo "<br>";
        }
*/

				$data = $vali->i_data ;
				// Re-assert after legacy validation as a defense-in-depth
				// boundary against hidden 16_* fields and attachment state.
				$data ["16_empf"] = $redcopy2."_rt,";

          if (!$result) {
          		$form = new nachrichten4fach ($data, $data["task"], $vali->validate);
            exit ;
        		}
        			/*----------------------------------------------------*/
      }

      $storedMessage = estab_message_insert_numbered (
        $messageConnection,
        $conf_4f_db ["datenbank"],
        $conf_4f_tbl ["nachrichten"],
        "E",
        Nachweisung === "getrennt",
        estab_message_new_record_fields (array (
          "01_medium" => $data ["01_medium"],
          "01_datum" => konv_taktime_datetime ($data ["01_datum"]),
          "01_zeichen" => $data ["01_zeichen"],
          "05_gegenstelle" => $data ["05_gegenstelle"],
          "07_durchspruch" => $data ["07_durchspruch"],
          "09_vorrangstufe" => $data ["09_vorrangstufe"],
          "10_anschrift" => $data ["10_anschrift"],
          "11_rufnummer" => $data ["11_rufnummer"],
          "11_gesprnotiz" => $data ["11_gesprnotiz"],
          "12_anhang" => $data ["12_anhang"],
          "12_betreff" => $data ["12_betreff"],
          "12_inhalt" => $data ["12_inhalt"],
          "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
          // A/W records the received callsign only. The sender is translated
          // and supplied by LdF in the following, locked workflow stage.
          "13_abseinheit" => "",
          "14_zeichen" => $data ["14_zeichen"],
          "14_funktion" => $data ["14_funktion"],
          "16_empf" => $data ["16_empf"],
          "x00_status" => 1,
          "x01_abschluss" => "f",
        )),
        $conf_4f_tbl ["anhang"],
        array (
          "event_type" => "created",
          "actor" => $messageActor,
          "from_status" => null,
          "to_status" => 1,
          "snapshot" => estab_message_action_evidence_snapshot (
            array (
              "direction" => "E",
              "medium" => $data ["01_medium"],
              "recorded_by" => $sessionCode,
              "remote_callsign" => $data ["05_gegenstelle"],
              "recipients" => $data ["16_empf"],
              "content_sha256" => hash ("sha256", $data ["12_inhalt"]),
            ),
            $messageActionToken,
            $messageActionTask
          ),
        ),
        $attachmentAuthorizer,
        $expectedIncidentId
      );
      protokolleintrag ("FM-Eingang", "message_id=".$storedMessage ["id"]);
    break;

    case "Stab_schreiben":
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Stab_schreiben</big><br>\n";}
/*          07_durchspruch;          08_befhinweis;          08_befhinwausw;          09_vorrangstufe;          10_anschrift;          11_gesprnotiz;          12_inhalt;
          12_abfzeit;          13_abseinheit;          14_zeichen;          14_funktion;
          Workflow ==>
            Ergaenzung Nachweisdaten (A und Nachweisnummer) 04_richtung 04_nummer
            Daten in Datenbank mit einem INSERT
            INSERT INTO tabelle SET spalten_name=ausdruck, spalten_name=ausdruck, ...
*/


      if ($data ["12_abfzeit"] == "" ) { $data ["12_abfzeit"] = date ("Hi") ; }

      if (validate){
         /*----------------------------------------------------*/
        if (debug){ echo "DATAHNDL506="; var_dump ($data); echo "<br><br>";
        }
        $vali = new vali_data_form ( $data ) ;
        $result = $vali->validatethis (); //checkdata ();
        if (debug){
            echo "<b>DATA</b>"; var_dump ($data); echo "<br>";
          echo "DATAHNDL 453="; echo "<b>RESULT</b>"; var_dump ($result); echo "<br><br>";
          echo "<b>vali-data</b>"; var_dump ($vali->i_data); echo "<br><br>";
          echo "<b>vali-VALIDATE</b>"; var_dump ($vali->validate); echo "<br>";
        }

        $data = $vali->i_data ;

         if (!$result) {
           $form = new nachrichten4fach ($data, $data["task"], $vali->validate);
           exit ;
         }
         /*----------------------------------------------------*/
      }

       $data ["16_empf"] = $redcopy2."_rt,".$data ["14_funktion"]."_gn"; // Der Verfasser bekommt den gruenen
       $storedMessage = estab_message_insert_numbered (
         $messageConnection,
         $conf_4f_db ["datenbank"],
         $conf_4f_tbl ["nachrichten"],
         "A",
         Nachweisung === "getrennt",
         estab_message_new_record_fields (array (
           // The acceptance mark belongs to LdF, not to the author.
           "02_zeit" => null,
           "02_zeichen" => "",
           // Feld 7 is the author's wish. LdF replaces it with the medium of
           // the disposed S6 route; the actually used way stays in Feld 1.
           "06_befwegausw" => estab_message_medium_storage_value (
             $data ["06_befwegausw"]
           ) ?? "",
           "07_durchspruch" => $data ["07_durchspruch"],
           "09_vorrangstufe" => $data ["09_vorrangstufe"],
           "10_anschrift" => $data ["10_anschrift"],
           "11_rufnummer" => $data ["11_rufnummer"],
           "11_gesprnotiz" => $data ["11_gesprnotiz"],
           "12_anhang" => $data ["12_anhang"],
           "12_betreff" => $data ["12_betreff"],
           "12_inhalt" => $data ["12_inhalt"],
           "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
           "13_abseinheit" => $data ["13_abseinheit"],
           "14_zeichen" => $data ["14_zeichen"],
           "14_funktion" => $data ["14_funktion"],
           "16_empf" => $data ["16_empf"],
           // Every outgoing message must pass the Sichter's formal check
           // before it can enter the LdF/FmZt disposition chain.
           "x00_status" => 4,
           "x01_abschluss" => "f",
         )),
         $conf_4f_tbl ["anhang"],
         array (
           "event_type" => "created",
           "actor" => $messageActor,
           "from_status" => null,
           "to_status" => 4,
           "snapshot" => estab_message_action_evidence_snapshot (
             array (
               "direction" => "A",
               "address" => $data ["10_anschrift"],
               "author_code" => $sessionCode,
               "author_function" => $sessionFunction,
               "recipients" => $data ["16_empf"],
               "content_sha256" => hash ("sha256", $data ["12_inhalt"]),
             ),
             $messageActionToken,
             $messageActionTask
           ),
         ),
         $attachmentAuthorizer,
         $expectedIncidentId
       );
       protokolleintrag ("Stab-schreiben", "message_id=".$storedMessage ["id"]);
       set_msg_read ($storedMessage ["id"], $messageActor) ;
    break;

    case "Stab_korrigieren":
      if ($data ["12_abfzeit"] == "") {
        $data ["12_abfzeit"] = date ("Hi");
      }
      if (validate) {
        $vali = new vali_data_form ($data);
        $result = $vali->validatethis ();
        $data = $vali->i_data;
        // Re-assert session attributes after normalization as defense in depth.
        $data ["13_abseinheit"] = $activeCommandPostName;
        $data ["14_zeichen"] = $sessionCode;
        $data ["14_funktion"] = $sessionFunction;
        if (!$result) {
          $rehydratedCorrection = estab_rehydrate_staff_correction_form (
            $messageConnection,
            (string) $conf_4f_tbl ["nachrichten"],
            $messageActor,
            $activeCommandPostName,
            $data
          );
          if (!is_array ($rehydratedCorrection)) {
            estab_render_message_stage_conflict (
              "Die Korrekturberechtigung"
            );
          }
          $form = new nachrichten4fach (
            $rehydratedCorrection,
            "Stab_korrigieren",
            $vali->validate
          );
          exit;
        }
      }

      try {
        $correctionSaved = estab_message_resubmit_returned_outgoing (
          $messageConnection,
          $conf_4f_tbl ["nachrichten"],
          $data ["00_lfd"],
          $sessionCode,
          $sessionFunction,
          array (
          "06_befwegausw" => estab_message_medium_storage_value (
            $data ["06_befwegausw"]
          ) ?? "",
          "07_durchspruch" => $data ["07_durchspruch"],
          "09_vorrangstufe" => $data ["09_vorrangstufe"],
          "10_anschrift" => $data ["10_anschrift"],
          "11_rufnummer" => $data ["11_rufnummer"],
          "12_anhang" => $data ["12_anhang"],
          "12_betreff" => $data ["12_betreff"],
          "12_inhalt" => $data ["12_inhalt"],
          "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
          "13_abseinheit" => $activeCommandPostName,
          "14_zeichen" => $sessionCode,
          "14_funktion" => $sessionFunction,
          "15_quitdatum" => null,
          "15_quitzeichen" => "",
          "x00_status" => 4,
          "x01_abschluss" => "f",
          "x02_sperre" => "f",
          "x03_sperruser" => "",
          ),
          array (
          "event_type" => "author_resubmitted",
          "actor" => $messageActor,
          "from_status" => 10,
          "to_status" => 4,
          "snapshot" => estab_message_action_evidence_snapshot (
            array (
              "address" => $data ["10_anschrift"],
              "author_code" => $sessionCode,
              "author_function" => $sessionFunction,
              "content_sha256" => hash ("sha256", $data ["12_inhalt"]),
            ),
            $messageActionToken,
            $messageActionTask
          ),
          ),
          $conf_4f_tbl ["anhang"],
          $attachmentAuthorizer,
          $expectedIncidentId
        );
      } catch (EstabIncidentConflictException $exception) {
        http_response_code (409);
        $data ["estab_route_error"] = $exception->getMessage ();
        $rehydratedCorrection = estab_rehydrate_staff_correction_form (
          $messageConnection,
          (string) $conf_4f_tbl ["nachrichten"],
          $messageActor,
          $activeCommandPostName,
          $data
        );
        if (!is_array ($rehydratedCorrection)) {
          estab_render_message_stage_conflict (
            "Die Korrekturberechtigung"
          );
        }
        $form = new nachrichten4fach (
          $rehydratedCorrection,
          "Stab_korrigieren",
          array ()
        );
        exit;
      } catch (InvalidArgumentException $exception) {
        http_response_code (422);
        $data ["estab_route_error"] = $exception->getMessage ();
        $rehydratedCorrection = estab_rehydrate_staff_correction_form (
          $messageConnection,
          (string) $conf_4f_tbl ["nachrichten"],
          $messageActor,
          $activeCommandPostName,
          $data
        );
        if (!is_array ($rehydratedCorrection)) {
          estab_render_message_stage_conflict (
            "Die Korrekturberechtigung"
          );
        }
        $form = new nachrichten4fach (
          $rehydratedCorrection,
          "Stab_korrigieren",
          array ()
        );
        exit;
      }
      if (!$correctionSaved) {
        estab_render_message_stage_conflict (
          "Die Korrekturberechtigung"
        );
      }
      protokolleintrag (
        "Stab-korrigiert",
        "message_id=".estab_message_positive_id ($data ["00_lfd"])
      );
      set_msg_read ($data ["00_lfd"], $messageActor);
    break;

    /****************************************************************************\
      SSSSS TTTTT AAAAA BBBB        GGGGG EEEEE SSSSS PPPP  RRRR  N   N  OOO  TTTTT IIIII
      S       T   A   A B   B       G     E     S     P   P R   R NN  N O   O   T     I
      SSSSS   T   AAAAA BBBB        G GGG EEEE  SSSSS PPPP  RRRR  N N N O   O   T     I
          S   T   A   A B   B       G   G E         S P     R  R  N  NN O   O   T     I
      SSSSS   T   A   A BBBB  _____ GGGG  EEEEE SSSSS P     R   R N   N  OOO    T   IIIII
    \****************************************************************************/
    case "Stab_gesprnoti":
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Stab_gesprnoti</big><br>\n";}
      if ($data ["01_datum"] == "" )     { $data ["01_datum"]     = date ("Hi") ; }
      if ($data ["12_abfzeit"] == "" )   { $data ["12_abfzeit"]   = date ("Hi") ; }

      try {
        estab_workflow_require_recipient_matrix_revision (
          $browserData,
          $empf_matrix,
          (string) $redcopy2
        );
      } catch (InvalidArgumentException $exception) {
        estab_render_message_stage_conflict ("Die Empfängermatrix");
      }
      /*
       * Preserve the browser's validated coordinate selections before the
       * legacy validator can re-render the form. Recipient function names
       * remain server-owned because the coordinates are resolved exclusively
       * through the authoritative matrix.
       */
      try {
        $data ["16_empf"] = estab_workflow_distribution_tokens (
          $browserData,
          $empf_matrix,
          array ($redcopy2."_rt", $sessionFunction."_gn")
        );
      } catch (InvalidArgumentException $exception) {
        estab_workflow_forbid ();
      }

      if (validate){
         /*----------------------------------------------------*/
        if (debug){
          echo "DATAHNDL532====";
          var_dump ($data); echo "<br><br>";
        }

        $vali = new vali_data_form ( $data ) ;
        $result = $vali->validatethis (); //checkdata ();

        if (debug){
          echo "<b>DATA</b>";
          var_dump ($data); echo "<br>";

          echo "DATAHNDL512=";
          echo "<b>RESULT</b>";
          var_dump ($result); echo "<br><br>";

          echo "<b>vali-data</b>";
          var_dump ($vali->i_data); echo "<br><br>";

          echo "<b>vali-VALIDATE</b>";
          var_dump ($vali->validate); echo "<br>";
        }

        $data = $vali->i_data ;

        if (!$result) {
          $form = new nachrichten4fach ($data, $data["task"], $vali->validate);
        exit ;
         }
         /*----------------------------------------------------*/
      }

       $data ["16_empf"] = estab_workflow_distribution_tokens (
         $browserData,
         $empf_matrix,
         array ($redcopy2."_rt", $sessionFunction."_gn")
       );
       $data ["11_gesprnotiz"] = "t" ;
       $data ["01_zeichen"] = $sessionCode;
       $data ["13_abseinheit"] = $activeCommandPostName;
       $data ["14_zeichen"] = $sessionCode;
       $data ["14_funktion"] = $sessionFunction;
       $data ["15_quitdatum"] = "";
       $data ["15_quitzeichen"] = "";
       $storedMessage = estab_message_insert_numbered (
         $messageConnection,
         $conf_4f_db ["datenbank"],
         $conf_4f_tbl ["nachrichten"],
         "A",
         Nachweisung === "getrennt",
         estab_message_new_record_fields (array (
           "01_medium" => $data ["01_medium"],
           "01_datum" => konv_taktime_datetime ($data ["01_datum"]),
           "01_zeichen" => $data ["01_zeichen"],
           // Acceptance, disposition and transport belong to Si, LdF and
           // A/W. Creating the note must not pre-populate their evidence.
           "02_zeit" => null,
           "02_zeichen" => "",
           "03_datum" => null,
           "03_zeichen" => "",
           "07_durchspruch" => $data ["07_durchspruch"],
           "09_vorrangstufe" => $data ["09_vorrangstufe"],
           "10_anschrift" => $data ["10_anschrift"],
           "11_rufnummer" => $data ["11_rufnummer"],
           "11_gesprnotiz" => $data ["11_gesprnotiz"],
           "12_anhang" => $data ["12_anhang"],
           "12_betreff" => $data ["12_betreff"],
           "12_inhalt" => $data ["12_inhalt"],
           "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
           "13_abseinheit" => $data ["13_abseinheit"],
           "14_zeichen" => $data ["14_zeichen"],
           "14_funktion" => $data ["14_funktion"],
           "16_empf" => $data ["16_empf"],
           "17_vermerke" => $data ["17_vermerke"],
           "x00_status" => 4,
           "x01_abschluss" => "f",
           "x02_sperre" => "f",
           "x03_sperruser" => "",
         )),
         $conf_4f_tbl ["anhang"],
         array (
           "event_type" => "conversation_note_created",
           "actor" => $messageActor,
           "from_status" => null,
           "to_status" => 4,
           "snapshot" => estab_message_action_evidence_snapshot (
             array (
               "direction" => "A",
               "object_type" => "conversation_note",
               "conversation_note" => true,
               "author_code" => $sessionCode,
               "author_function" => $sessionFunction,
               "original_conversation_medium" => $data ["01_medium"],
               "review_required" => true,
               "ldf_disposition_required" => true,
               "transport_evidence_required" => true,
               "content_sha256" => hash ("sha256", $data ["12_inhalt"]),
             ),
             $messageActionToken,
             $messageActionTask
           ),
         ),
         $attachmentAuthorizer,
         $expectedIncidentId
       );
       protokolleintrag ("Stab-gesprnoti", "message_id=".$storedMessage ["id"]);
       set_msg_read ($storedMessage ["id"], $messageActor) ;

		break;

    case "LdF-Eingang":
    case "LdF-Ausgang":
      $ldfTask = $data ["task"] === "LdF-Eingang"
        ? "LdF-Eingang"
        : "LdF-Ausgang";
      $ldfDirection = $ldfTask === "LdF-Eingang" ? "E" : "A";
      $ldfReturnRequested = $ldfTask === "LdF-Ausgang"
        && (
          isset ($data ["ldf_zurueckweisen_x"])
          || isset ($data ["ldf_zurueckweisen_y"])
        );
      if ($ldfReturnRequested) {
        try {
          $ldfReturned = estab_message_ldf_return_outgoing (
            $messageConnection,
            (string) $conf_4f_tbl ["nachrichten"],
            $data ["00_lfd"],
            $messageActor,
            $data ["ldf_rueckgabegrund"],
            $expectedIncidentId
          );
        } catch (EstabDvInputException $exception) {
          http_response_code (422);
          $data ["estab_route_error"] = $exception->getMessage ();
          $rehydratedLead = estab_rehydrate_locked_operator_form (
            $messageConnection,
            (string) $conf_4f_tbl ["nachrichten"],
            (string) $_SESSION ["vStab_kuerzel"],
            $ldfTask,
            $data
          );
          if (!is_array ($rehydratedLead)) {
            estab_render_ldf_stage_conflict ();
          }
          $form = new nachrichten4fach (
            $rehydratedLead,
            $ldfTask,
            ""
          );
          exit;
        } catch (
          EstabIncidentConflictException|
          EstabDvPermissionException|
          EstabDvConflictException $exception
        ) {
          http_response_code (409);
          $data ["estab_route_error"] = $exception->getMessage ();
          $rehydratedLead = estab_rehydrate_locked_operator_form (
            $messageConnection,
            (string) $conf_4f_tbl ["nachrichten"],
            (string) $_SESSION ["vStab_kuerzel"],
            $ldfTask,
            $data
          );
          if (!is_array ($rehydratedLead)) {
            estab_render_ldf_stage_conflict ();
          }
          $form = new nachrichten4fach (
            $rehydratedLead,
            $ldfTask,
            ""
          );
          exit;
        }
        if (!$ldfReturned) {
          estab_render_ldf_stage_conflict ();
        }
        protokolleintrag (
          "LdF-Ausgang-zurueckgewiesen",
          "message_id=".estab_message_positive_id ($data ["00_lfd"])
        );
        break;
      }
      if ($ldfDirection === "E") {
        // Receipt time and A/W mark are immutable evidence. Discard forged
        // overposting before the legacy validator can parse or reflect it;
        // the repository update below never contains either field.
        $data ["01_datum"] = "";
        $data ["01_zeichen"] = "";
      }
      if ($data ["02_zeit"] == "") {
        $data ["02_zeit"] = date ("Hi");
      }
      // The signed acceptance mark is an identity attribute. A forged form
      // value must never choose another operator's code.
      $data ["02_zeichen"] = (string) $_SESSION ["vStab_kuerzel"];

      if (validate) {
        $vali = new vali_data_form ($data);
        $result = $vali->validatethis ();
        $data = $vali->i_data;
        if (!$result) {
          http_response_code (422);
          $rehydratedLead = estab_rehydrate_locked_operator_form (
            $messageConnection,
            (string) $conf_4f_tbl ["nachrichten"],
            (string) $_SESSION ["vStab_kuerzel"],
            $ldfTask,
            $data
          );
          if (!is_array ($rehydratedLead)) {
            estab_render_ldf_stage_conflict ();
          }
          $data = $rehydratedLead;
          $form = new nachrichten4fach (
            $data,
            $ldfTask,
            $vali->validate
          );
          exit;
        }
      }

      $ldfFields = array (
        "02_zeit" => konv_taktime_datetime ($data ["02_zeit"]),
        "02_zeichen" => (string) $_SESSION ["vStab_kuerzel"],
        "x02_sperre" => "f",
        "x03_sperruser" => "",
      );
      if ($ldfDirection === "E") {
        // A/W records the medium on receipt. LdF must explicitly confirm or
        // correct that transport path before the message enters the Si queue.
        // The validated canonical value is stored together with the signed
        // LdF acceptance mark and becomes part of the immutable event trail.
        $ldfFields ["01_medium"] = (string) $data ["01_medium"];
        $ldfFields ["13_abseinheit"] = trim (
          (string) $data ["13_abseinheit"]
        );
        // Missing Si staffing never closes or bypasses the viewer queue.
        $ldfFields ["x00_status"] = 4;
        $ldfFields ["x01_abschluss"] = "f";
      } else {
        $ldfFields ["05_gegenstelle"] = trim (
          (string) $data ["05_gegenstelle"]
        );
        // The repository resolves this immutable ID again while holding the
        // active incident transaction and derives medium/route from the
        // currently valid S6 plan. Browser-supplied 06_* values are ignored.
        $ldfFields ["estab_fernmeldeplan_eintrag_id"] =
          estab_message_positive_id ($data ["fernmeldeplan_eintrag_id"]);
        $ldfFields ["x00_status"] = 2;
        $ldfFields ["x01_abschluss"] = "f";
      }

      try {
        $ldfSaved = estab_message_update_locked_operator_stage (
          $messageConnection,
          $conf_4f_tbl ["nachrichten"],
          $data ["00_lfd"],
          $messageActor,
          $ldfDirection,
          1,
          $ldfFields,
          array (
            "event_type" => "ldf_dispatched",
            "actor" => $messageActor,
            "from_status" => 1,
            "to_status" => $ldfDirection === "E" ? 4 : 2,
            "snapshot" => $ldfDirection === "E"
              ? array (
                "direction" => "E",
                "incoming_transport_medium" =>
                  $ldfFields ["01_medium"],
                "incoming_transport_confirmed" => hash_equals (
                  "1",
                  (string) $data ["incoming_transport_confirmed"]
                ),
                "transport_confirmed_by" => $sessionCode,
                "requested_transport_correction_reason" => trim (
                  (string) $data ["incoming_transport_correction_reason"]
                ),
                "translated_sender" => $ldfFields ["13_abseinheit"],
                "accepted_by" => $sessionCode,
              )
              : array (
                "direction" => "A",
                "remote_callsign" => $ldfFields ["05_gegenstelle"],
                "requested_telecom_plan_entry_id" =>
                  $ldfFields ["estab_fernmeldeplan_eintrag_id"],
                "accepted_by" => $sessionCode,
              ),
            "occurred_at" => $ldfFields ["02_zeit"],
          ),
          $expectedIncidentId
        );
      } catch (EstabDvInputException|EstabDvConflictException $exception) {
        http_response_code (409);
        $data ["estab_route_error"] = $exception->getMessage ();
        $rehydratedLead = estab_rehydrate_locked_operator_form (
          $messageConnection,
          (string) $conf_4f_tbl ["nachrichten"],
          (string) $_SESSION ["vStab_kuerzel"],
          $ldfTask,
          $data
        );
        if (!is_array ($rehydratedLead)) {
          estab_render_ldf_stage_conflict ();
        }
        $data = $rehydratedLead;
        $routeValidation = $vali->validate;
        if ($ldfDirection === "A") {
          $routeValidation ["fernmeldeplan_eintrag_id"] = false;
        }
        $form = new nachrichten4fach (
          $data,
          $ldfTask,
          $routeValidation
        );
        exit;
      }
      if (!$ldfSaved) {
        estab_render_ldf_stage_conflict ();
      }
      protokolleintrag (
        $ldfDirection === "E" ? "LdF-Eingang" : "LdF-Ausgang",
        "message_id=".estab_message_positive_id ($data ["00_lfd"])
      );
    break;

			case "FM-Ausgang":
				if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>FM-Ausgang</big><br>\n";}
				if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - FM-Ausgang <br>\n";}
      $transportReturn =
        isset ($data ["transport_nicht_moeglich_x"])
        || isset ($data ["transport_nicht_moeglich_y"]);
      if ($transportReturn) {
        $returnReason = trim ((string) $data ["transport_rueckgabegrund"]);
        try {
          if (
            $returnReason === ""
            || strlen ($returnReason) > 2000
            || str_contains ($returnReason, "\0")
          ) {
            throw new EstabDvInputException (
              "Für die Rückgabe an LdF ist ein Grund erforderlich."
            );
          }
          $returnedToLdf = estab_message_update_locked_operator_stage (
            $messageConnection,
            $conf_4f_tbl ["nachrichten"],
            $data ["00_lfd"],
            $messageActor,
            "A",
            2,
            array (
              "02_zeit" => null,
              "02_zeichen" => "",
              "03_datum" => null,
              "03_zeichen" => "",
              "x00_status" => 1,
              "x01_abschluss" => "f",
              "x02_sperre" => "f",
              "x03_sperruser" => "",
            ),
            array (
              "event_type" => "aw_transport_returned",
              "actor" => $messageActor,
              "from_status" => 2,
              "to_status" => 1,
              "snapshot" => array (
                "direction" => "A",
                "returned_by" => $sessionCode,
                "transport_return_reason" => $returnReason,
              ),
            ),
            $expectedIncidentId
          );
        } catch (EstabDvInputException|EstabDvConflictException $exception) {
          http_response_code (
            $exception instanceof EstabDvInputException ? 422 : 409
          );
          $data ["estab_route_error"] = $exception->getMessage ();
          $rehydratedTransport = estab_rehydrate_locked_operator_form (
            $messageConnection,
            (string) $conf_4f_tbl ["nachrichten"],
            (string) $_SESSION ["vStab_kuerzel"],
            "FM-Ausgang",
            $data
          );
          if (!is_array ($rehydratedTransport)) {
            estab_render_message_stage_conflict ("Die Fernmelder-Sperre");
          }
          $form = new nachrichten4fach (
            $rehydratedTransport,
            "FM-Ausgang",
            array ()
          );
          exit;
        }
        if (!$returnedToLdf) {
          estab_render_message_stage_conflict ("Die Fernmelder-Sperre");
        }
        protokolleintrag (
          "FM-Ausgang-Rückgabe",
          "message_id=".estab_message_positive_id ($data ["00_lfd"])
        );
        break;
      }
      if ($data ["03_datum"] == "" ) { $data ["03_datum"] = date ("Hi") ; }
      $data ["03_zeichen"] = (string) $_SESSION ["vStab_kuerzel"];
	      if (validate){
   	   	   /*----------------------------------------------------*/
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>"; var_dump ($data); echo "<br><br>";}
        	$vali = new vali_data_form ( $data ) ;
        	$result = $vali->validatethis (); //checkdata ();
/*
			if (debug){
          echo "<b>DATA</b>";
          var_dump ($data); echo "<br>";

          echo "DATAHNDL667=";
          echo "<b>RESULT</b>";
          var_dump ($result); echo "<br><br>";

          echo "<b>vali-data</b>";
          var_dump ($vali->i_data); echo "<br><br>";

          echo "<b>vali-VALIDATE</b>";
          var_dump ($vali->validate); echo "<br>";
        }
*/
			$data = $vali->i_data ;
         if (!$result) {
            $rehydratedTransport = estab_rehydrate_locked_operator_form (
              $messageConnection,
              (string) $conf_4f_tbl ["nachrichten"],
              (string) $_SESSION ["vStab_kuerzel"],
              "FM-Ausgang",
              $data
            );
            if (!is_array ($rehydratedTransport)) {
              estab_render_message_stage_conflict ("Die Fernmelder-Sperre");
            }
				$form = new nachrichten4fach (
              $rehydratedTransport,
              "FM-Ausgang",
              $vali->validate
            );
            exit;
         }
      }
       try {
         $transportSaved = estab_message_update_locked_operator_stage (
           $messageConnection,
           $conf_4f_tbl ["nachrichten"],
           $data ["00_lfd"],
           $messageActor,
           "A",
           2,
           array (
             "03_datum" => konv_taktime_datetime ($data ["03_datum"]),
             // As with LdF, the authenticated identity owns the mark.
             "03_zeichen" => (string) $_SESSION ["vStab_kuerzel"],
             // Formal Si approval and LdF disposition are prerequisites of
             // this exact stage; the transport therefore completes the form.
             "x00_status" => 8,
             "x01_abschluss" => "t",
             "x02_sperre" => "f",
             "x03_sperruser" => "",
           ),
           array (
             "event_type" => "aw_transported",
             "actor" => $messageActor,
             "from_status" => 2,
             "to_status" => 8,
             "snapshot" => array (
               "direction" => "A",
               "transported_by" => $sessionCode,
               "transported_at" =>
                 konv_taktime_datetime ($data ["03_datum"]),
               "transport_route_confirmed" =>
                 hash_equals (
                   "1",
                   (string) $data ["transportweg_bestaetigt"]
                 ),
             ),
             "occurred_at" => konv_taktime_datetime ($data ["03_datum"]),
           ),
           $expectedIncidentId
         );
       } catch (EstabDvInputException|EstabDvConflictException $exception) {
         http_response_code (409);
         $data ["estab_route_error"] = $exception->getMessage ();
         $rehydratedTransport = estab_rehydrate_locked_operator_form (
           $messageConnection,
           (string) $conf_4f_tbl ["nachrichten"],
           (string) $_SESSION ["vStab_kuerzel"],
           "FM-Ausgang",
           $data
         );
         if (!is_array ($rehydratedTransport)) {
           estab_render_message_stage_conflict ("Die Fernmelder-Sperre");
         }
         $form = new nachrichten4fach (
           $rehydratedTransport,
           "FM-Ausgang",
           $vali->validate
         );
         exit;
       }
       if (!$transportSaved) {
         estab_render_message_stage_conflict ("Die Fernmelder-Sperre");
       }
       protokolleintrag ("FM-Ausgang", "message_id=".estab_message_positive_id ($data ["00_lfd"]));
   break;

			case "Stab_sichten":
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Stab_sichten</big><br>\n";}
       $reviewMessage = estab_message_fetch_by_id (
         $messageConnection,
         $conf_4f_tbl ["nachrichten"],
         $data ["00_lfd"]
       );
       if (!is_array ($reviewMessage)) {
         throw new RuntimeException ("Nachricht zur Sichtung nicht gefunden");
       }
       $reviewDirection = (string) ($reviewMessage ["04_richtung"] ?? "");
       $isFormalReturn =
         isset ($data ["zurueckweisen_x"])
         || isset ($data ["zurueckweisen_y"]);
       if ($isFormalReturn && $reviewDirection !== "A") {
         throw new InvalidArgumentException (
           "Nur Ausgangsnachrichten können formal zurückgegeben werden"
         );
       }
       $formalOutgoingComplete =
         trim ((string) ($reviewMessage ["10_anschrift"] ?? "")) !== ""
         && trim ((string) ($reviewMessage ["13_abseinheit"] ?? "")) !== ""
         && trim ((string) ($reviewMessage ["14_zeichen"] ?? "")) !== ""
         && trim ((string) ($reviewMessage ["14_funktion"] ?? "")) !== "";
       if (
         $reviewDirection === "A"
         && !$isFormalReturn
         && !$formalOutgoingComplete
       ) {
         throw new InvalidArgumentException (
           "Anschrift, Absender, Verfasserzeichen und Funktion sind ".
           "unvollständig. Die Nachricht muss zurückgegeben werden."
         );
       }
       if ($isFormalReturn && trim ((string) $data ["17_vermerke"]) === "") {
         $data ["15_quitzeichen"] = $sessionCode;
         $validationErrors = array_fill_keys (array (
           "01_medium", "01_datum", "01_zeichen", "02_zeit", "02_zeichen",
           "03_datum", "03_zeichen", "05_gegenstelle", "06_befweg",
           "06_befwegausw", "07_durchspruch", "08_befhinweis",
           "08_befhinwausw", "10_anschrift", "11_rufnummer",
           "12_betreff", "12_inhalt", "12_abfzeit",
           "13_abseinheit", "14_zeichen", "14_funktion", "15_quitdatum",
           "15_quitzeichen", "17_vermerke"
         ), true);
         $validationErrors ["17_vermerke"] = false;
         $form = new nachrichten4fach (
           array_replace ($reviewMessage, array (
             "15_quitdatum" => $data ["15_quitdatum"],
             "15_quitzeichen" => $sessionCode,
             "17_vermerke" => $data ["17_vermerke"],
           )),
           "Stab_sichten",
           $validationErrors
         );
         exit;
       }

       // The sighting time is evidence of the successful server-side
       // transition. Browser values are rejected by the workflow gate.
       $data ["15_quitdatum"] = date ("Hi");
       $reviewFields = array (
         "15_quitdatum" => convtodatetime (
           date ("dm"),
           $data ["15_quitdatum"]
         ),
         "15_quitzeichen" => $sessionCode,
         "17_vermerke" => $data ["17_vermerke"],
         "x02_sperre" => "f",
         "x03_sperruser" => "",
       );
       if ($reviewDirection === "E") {
         try {
           estab_workflow_require_recipient_matrix_revision (
             $browserData,
             $empf_matrix,
             (string) $redcopy2
           );
         } catch (InvalidArgumentException $exception) {
           estab_render_message_stage_conflict ("Die Empfängermatrix");
         }
         // The Sichter's substantive incoming analysis chooses the recipients.
         $data ["16_empf"] = estab_workflow_distribution_tokens (
           $browserData,
           $empf_matrix,
           array ($redcopy2."_rt")
         );
         if (!estab_workflow_distribution_has_processor ($data ["16_empf"])) {
           // Feld 19, Laufweg Eingang: Die Sichtung schliesst den Nachweis
           // ab. Die rote Lage-/Dokumentationsdurchschrift steht hier immer,
           // sie ist kein Empfaenger im Sinne des Laufwegs. Ohne blaue
           // Durchschrift bliebe die Nachricht ohne Bearbeiter liegen.
           $validationErrors = array_fill_keys (array (
             "01_medium", "01_datum", "01_zeichen", "02_zeit", "02_zeichen",
             "03_datum", "03_zeichen", "05_gegenstelle", "06_befweg",
             "06_befwegausw", "07_durchspruch", "08_befhinweis",
             "08_befhinwausw", "10_anschrift", "11_rufnummer",
             "12_betreff", "12_inhalt", "12_abfzeit",
             "13_abseinheit", "14_zeichen", "14_funktion", "15_quitdatum",
             "15_quitzeichen", "17_vermerke"
           ), true);
           $validationErrors ["16_empf"] = false;
           $form = new nachrichten4fach (
             array_replace ($reviewMessage, array (
               "15_quitdatum" => $data ["15_quitdatum"],
               "15_quitzeichen" => $sessionCode,
               "16_empf" => $data ["16_empf"],
               "17_vermerke" => $data ["17_vermerke"],
               "estab_route_error" =>
                 estab_workflow_missing_processor_message (),
             )),
             "Stab_sichten",
             $validationErrors
           );
           exit;
         }
         $reviewFields ["16_empf"] = $data ["16_empf"];
         $reviewFields ["x00_status"] = 8;
         $reviewFields ["x01_abschluss"] = "t";
       } else {
         // Outgoing review is formal only: address, author mark and function.
         // Neither content nor recipient routing is accepted from the browser.
         $reviewFields ["x00_status"] = $isFormalReturn ? 10 : 1;
         $reviewFields ["x01_abschluss"] = "f";
       }

       $reviewSaved = estab_message_update_pending_review (
         $messageConnection,
         $conf_4f_tbl ["nachrichten"],
         $data ["00_lfd"],
         $reviewFields,
         array (
           "event_type" => $reviewDirection === "E"
             ? "incoming_routed"
             : ($isFormalReturn ? "si_returned" : "si_approved"),
           "actor" => $messageActor,
           "from_status" => 4,
           "to_status" => (int) $reviewFields ["x00_status"],
           "snapshot" => $reviewDirection === "E"
             ? array (
               "direction" => "E",
               "reviewed_by" => $sessionCode,
               "recipients" => $reviewFields ["16_empf"],
               "review_note" => $reviewFields ["17_vermerke"],
             )
             : array (
               "direction" => "A",
               "reviewed_by" => $sessionCode,
               "address" => $reviewMessage ["10_anschrift"] ?? "",
               "author_code" => $reviewMessage ["14_zeichen"] ?? "",
               "author_function" => $reviewMessage ["14_funktion"] ?? "",
               "reason" => $isFormalReturn
                 ? $reviewFields ["17_vermerke"]
                 : "",
             ),
           "occurred_at" => $reviewFields ["15_quitdatum"],
         ),
         $expectedIncidentId
       );
       if (!$reviewSaved) {
         throw new RuntimeException ("Message review status changed");
       }
       $reviewEvent = $reviewDirection === "E"
         ? "Stab-sichten-Eingang"
         : ($isFormalReturn
           ? "Stab-formal-zurueckgewiesen"
           : "Stab-formal-freigegeben");
       protokolleintrag (
         $reviewEvent,
         "message_id=".estab_message_positive_id ($data ["00_lfd"])
       );
    break;

		case "Nachweis":
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Nachweis</big><br>\n";}
/*
          04_richtung;
          04_nummer;
*/
   break;

	  }
	  } finally {
    try {
      estab_message_action_unlock (
        $messageConnection,
        $messageActionLockName
      );
    } catch (Throwable $exception) {
      error_log (
        "eStab message action lock release failed: ".
        $exception->getMessage ()
      );
    }
    estab_auth_close ($messageConnection);
  }
}

/*****************************************************************************
 $_SESSION
 [vStab_kuerzel] => LKW
 [vStab_funktion] => S2
 [vStab_rolle] => Stab
 *****************************************************************************/

	function legere_nuntium ($lfd) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>legere nuntium ".$lfd."</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) $_SESSION ["vStab_funktion"],
      (string) $_SESSION ["vStab_kuerzel"],
      "read"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      return estab_message_state_exists (
        $connection,
        $stateTable,
        $lfd,
        $conf_4f_tbl ["nachrichten"]
      ) ? 1 : 0;
    } finally {
      estab_auth_close ($connection);
    }
  }


/*****************************************************************************

  set_msg_read ($lfd)

\*****************************************************************************/
	function set_msg_read ($lfd, array $actor) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>set_msg_read</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) ($actor ["funktion"] ?? ""),
      (string) ($actor ["kuerzel"] ?? ""),
      "read"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_set_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        $actor,
        "read",
        convtodatetime (date ("dmY"), date ("Hi"))
      )) {
        throw new RuntimeException ("Message read state is no longer authorised");
      }
    } finally {
      estab_auth_close ($connection);
    }
    protokolleintrag ("Nachricht gelesen", "message_id=".$recordId);
  }

/*****************************************************************************

  unset_msg_read ($lfd)

  DELETE FROM `usr_ls_ls_read` WHERE `usr_ls_ls_read`.`lfd` = 3 LIMIT 1
\*****************************************************************************/
	function unset_msg_read ($lfd, array $actor) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>unset_msg_read</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("../4fach/protokoll.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) ($actor ["funktion"] ?? ""),
      (string) ($actor ["kuerzel"] ?? ""),
      "read"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_unset_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        $actor
      )) {
        throw new RuntimeException ("Message read state is no longer authorised");
      }
    } finally {
      estab_auth_close ($connection);
    }
    protokolleintrag ("Nachricht ungelesen", "message_id=".$recordId);
  }



/*****************************************************************************

   set_msg_done ($lfd)

 *****************************************************************************/
	function set_msg_done ($lfd, array $actor) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>set_msg_done</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("../4fach/protokoll.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) ($actor ["funktion"] ?? ""),
      (string) ($actor ["kuerzel"] ?? ""),
      "done"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_set_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        $actor,
        "done",
        convtodatetime (date ("dmY"), date ("Hi"))
      )) {
        throw new RuntimeException ("Message done state is no longer authorised");
      }
    } finally {
      estab_auth_close ($connection);
    }
    protokolleintrag ("Nachricht erledigt", "message_id=".$recordId);
  }



/*****************************************************************************

   unset_msg_done ($lfd)

 *****************************************************************************/
	function unset_msg_done ($lfd, array $actor) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>unset_msg_done</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("../4fach/protokoll.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) ($actor ["funktion"] ?? ""),
      (string) ($actor ["kuerzel"] ?? ""),
      "done"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_unset_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        $actor
      )) {
        throw new RuntimeException ("Message done state is no longer authorised");
      }
    } finally {
      estab_auth_close ($connection);
    }
    protokolleintrag ("Nachricht unerledigt", "message_id=".$recordId);
  }




/*****************************************************************************\
 select nv_nachrichten.00_lfd

 from nv_nachrichten, usr_ls_ls_read

 where nv_nachrichten.00_lfd = usr_ls_ls_read.nachnum

 ==> Liste der gelesenen Nachrichten
\*****************************************************************************/
	function list_of_readed_msg (array $actor){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>list_of_readed_msg</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) ($actor ["funktion"] ?? ""),
      (string) ($actor ["kuerzel"] ?? ""),
      "read"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      $ids = estab_message_state_ids (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable
      );
      return $ids === array () ? "" : $ids;
    } finally {
      estab_auth_close ($connection);
    }
  }

/*****************************************************************************\
 select nv_nachrichten.00_lfd

 from nv_nachrichten, usr_ls_ls_erl

 where nv_nachrichten.00_lfd = usr_ls_ls_erl.nachnum

 ==> Liste der erledigten Nachrichten
\*****************************************************************************/
	function list_of_done_msg (array $actor){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>list_of_done_msg</big><br>\n";}
		
		include ("../4fcfg/config.inc.php");
  		include ("../4fcfg/dbcfg.inc.php");
  		include ("../4fcfg/e_cfg.inc.php");

      $stateTable = estab_message_state_table (
        $conf_4f_tbl ["usrtblprefix"],
        (string) ($actor ["funktion"] ?? ""),
        (string) ($actor ["kuerzel"] ?? ""),
        "done"
      );
      $connection = estab_message_connect ($conf_4f_db);
      try {
        $ids = estab_message_state_ids (
          $connection,
          $conf_4f_tbl ["nachrichten"],
          $stateTable
        );
        return $ids === array () ? "" : $ids;
      } finally {
        estab_auth_close ($connection);
      }
	}


	function get_flt_gelesen (){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>get_flt_gelesen</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"],
                               $conf_4f_db ["datenbank"],
                               $conf_4f_tbl ["benutzer"],
                               $conf_4f_db ["user"],
                               $conf_4f_db ["password"]);

    $tblusername = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"])."_".strtolower ($_SESSION["vStab_kuerzel"]);

    $fkttblname  = $conf_4f_tbl ["usrtblprefix"]."_fkt_".strtolower ($_SESSION["vStab_funktion"]);

    $tblusername_r = $tblusername."_read";
    $tblusername_e = $fkttblname."_erl";

    $query_r = "SELECT COUNT(*) FROM ".$conf_4f_tbl ["nachrichten"]."
                WHERE (`".$conf_4f_tbl ["nachrichten"]."`.`04_nummer` IN ( select `".$tblusername_r."`.`nachnum` from `".$tblusername_r."` where 1))";
    $result = $dbaccess->query_table_wert ($query_r);
    $flt_gelesene = $result [0];
  }

	function get_flt_erledigt (){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>get_flt_erledigt</big><br>\n";}  	
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $dbaccess = new db_access ($conf_4f_db ["server"],
                               $conf_4f_db ["datenbank"],
                               $conf_4f_tbl ["benutzer"],
                               $conf_4f_db ["user"],
                               $conf_4f_db ["password"]);

    $tblusername  = $conf_4f_tbl ["usrtblprefix"].strtolower ($_SESSION["vStab_funktion"])."_".strtolower ($_SESSION["vStab_kuerzel"]);

    $fkttblname  = $conf_4f_tbl ["usrtblprefix"]."_fkt_".strtolower ($_SESSION["vStab_funktion"]);

    $tblusername_r = $tblusername."_read";
    $tblusername_e = $fkttblname."_erl";

    $query_e = "SELECT COUNT(*) FROM ".$conf_4f_tbl ["nachrichten"]."
                WHERE (`".$conf_4f_tbl ["nachrichten"]."`.`04_nummer` IN ( select `".$tblusername_e."`.`nachnum` from `".$tblusername_e."` where 1))";

    $result = $dbaccess->query_table_wert ($query_e);
    $flt_erledigte = $result [0];
  }

/*****************************************************************************\
 get_msg_by_lfd ( $lfd )
\*****************************************************************************/
	function get_msg_by_lfd ( $lfd ){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>get_msg_by_lfd = ".$lfd."</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $connection = estab_message_connect ($conf_4f_db);
    try {
      $data = estab_message_fetch_by_id (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $lfd
      );
    } finally {
      estab_auth_close ($connection);
    }
    if (!is_array ($data)) {
      throw new RuntimeException ("Message not found");
    }

    foreach (array ("01_datum", "02_zeit", "03_datum", "12_abfzeit", "15_quitdatum") as $dateField) {
      if (estab_datetime_is_unset ($data [$dateField] ?? null)) {
        $data [$dateField] = "";
      }
    }

     //  Umwandlung Datenbankdatum --> taktischer Zeit falls erforderlich
    if (!estab_datetime_is_unset ($data["01_datum"])){
      $data["01_datum"]   = konv_datetime_taktime ($data["01_datum"]);
    }
    if (!estab_datetime_is_unset ($data["02_zeit"])){
      $data["02_zeit"]   = konv_datetime_taktime ($data["02_zeit"]);
    }
    if (!estab_datetime_is_unset ($data["03_datum"])){
      $data["03_datum"]   = konv_datetime_taktime ($data["03_datum"]);
    }
    if (!estab_datetime_is_unset ($data["12_abfzeit"])){
      $data["12_abfzeit"] = konv_datetime_taktime ($data["12_abfzeit"]);
    }
    if (!estab_datetime_is_unset ($data["15_quitdatum"])){
      $data["15_quitdatum"] = konv_datetime_taktime ($data["15_quitdatum"]);
    }

    return ($data);

  }

  /**************************************************************************\

  \**************************************************************************/
  function reset_record_lock ( $lfd, array $actor ){

    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $recordId = estab_message_positive_id ($lfd);
    $connection = estab_message_connect ($conf_4f_db);
    try {
      $lockedMessage = estab_message_fetch_by_id (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $recordId
      );
      if (!is_array ($lockedMessage)) {
        throw new RuntimeException ("Message lock reset target not found");
      }
      $stageStatus = (int) ($lockedMessage ["x00_status"] ?? 0);
      $stageDirection = (string) (
        $lockedMessage ["04_richtung"] ?? ""
      );
      if (
        !(
          ($stageStatus === 1
            && in_array ($stageDirection, array ("E", "A"), true))
          || ($stageStatus === 2 && $stageDirection === "A")
        )
      ) {
        throw new RuntimeException ("Message is not in an operator stage");
      }
      if (!estab_message_release_operator_stage_lock (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $recordId,
        $stageDirection,
        $stageStatus,
        $actor,
        true
      )) {
        throw new RuntimeException ("Message lock reset lost its target");
      }
    } finally {
      estab_auth_close ($connection);
    }
    protokolleintrag ("Nachrichtensperre freigegeben", "message_id=".$recordId);
  }

?>
