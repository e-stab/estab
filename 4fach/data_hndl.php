<?php
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Data hndl</big><br>\n";}
/*****************************************************************************\
   Datei: data_hndl.php

   benoetigte Dateien:

   Beschreibung:

   Funktionen:

     check_save_user ()
     check_and_save ($data)
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
require_once __DIR__ . "/../app/message_repository.php";

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
  if ($loginFlow === "new" && $explicitLoginFlow && !estab_auth_self_registration_allowed ()) {
    $_SESSION ["menue"] = "LOGIN";
    $loginError = "Neue Konten können hier nicht erstellt werden. Wenden Sie sich an die zuständige Stelle.";
    return true;
  }

  $connection = null;
  $policyLockName = null;
  $policyLockAcquired = false;
  $accountLockName = null;
  $accountLockAcquired = false;
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

      // DDL commits implicitly in MariaDB. It therefore runs on its own
      // connection, under a function-scoped advisory lock, before the account
      // is activated. A failed or interrupted reconciliation leaves the
      // account untouched and can safely be retried.
      if ($login ["funktion"] != "A/W") {
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
      $_SESSION ["menue"] = "ROLLE";
      $_SESSION ["ROLLE"] = $login ["rolle"];

      return false;
    }

    if ($loginFlow === "existing") {
      $loginError = "Name, Kürzel oder Kennwort stimmen nicht mit einem bestehenden Konto überein.";
      return true;
    }
    if (!estab_auth_self_registration_allowed ()) {
      $loginError = "Neue Konten können hier nicht erstellt werden. Wenden Sie sich an die zuständige Stelle.";
      return true;
    }

    if ($login ["funktion"] != "A/W") {
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

    $passwordHash = password_hash ($login ["password"], PASSWORD_DEFAULT);
    if (!is_string ($passwordHash)) {
      throw new RuntimeException ("Kennwort konnte nicht sicher gespeichert werden");
    }
    estab_auth_insert_user ($connection, $conf_4f_tbl ["benutzer"], array (
      "benutzer" => $login ["benutzer"],
      "kuerzel" => $login ["kuerzel"],
      "funktion" => $login ["funktion"],
      "rolle" => $login ["rolle"],
      "sid" => session_id (),
      "ip" => $ip,
      "fwdip" => $forwardedIp,
      "password" => $passwordHash,
    ));

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
    $_SESSION ["menue"] = "ROLLE";
    $_SESSION ["ROLLE"] = $login ["rolle"];

    return false;
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


/*****************************************************************************\

\*****************************************************************************/
function check_and_save ($data){
  if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>check_and_save</big><br>\n";}
  include ("../4fcfg/config.inc.php");
  include ("../4fcfg/dbcfg.inc.php");
  include ("../4fcfg/e_cfg.inc.php");
  include ("../4fcfg/fkt_rolle.inc.php");

  $data = array_replace (array_fill_keys (array (
    "00_lfd", "01_medium", "01_datum", "01_zeichen",
    "02_zeit", "02_zeichen", "03_datum", "03_zeichen",
    "05_gegenstelle", "06_befweg", "06_befwegausw",
    "07_durchspruch", "08_befhinweis", "08_befhinwausw",
    "09_vorrangstufe", "10_anschrift", "11_gesprnotiz",
    "12_anhang", "12_inhalt", "12_abfzeit", "13_abseinheit",
    "14_zeichen", "14_funktion", "15_quitdatum",
    "15_quitzeichen", "16_empf", "16_gncopy", "17_vermerke",
    "task"
  ), ""), is_array ($data) ? $data : array ());

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
  try {
    try {
      estab_incident_require_active ($messageConnection);
    } catch (EstabNoActiveIncidentException) {
      if (ob_get_level () > 0) {
        @ob_clean ();
      }
      http_response_code (409);
      header ("Content-Type: text/html; charset=UTF-8");
      header ("Cache-Control: no-store");
      echo "<!doctype html><html lang=\"de\"><meta charset=\"UTF-8\">";
      echo "<title>Kein Einsatz aktiv</title><body>";
      echo "<h1>Keine Eingabe möglich</h1>";
      echo "<p>Derzeit ist kein Einsatz aktiv. Legen Sie in der Administration ".
           "einen Einsatz an oder aktivieren Sie einen vorhandenen Einsatz.</p>";
      echo "</body></html>";
      exit;
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
			$data ["16_empf"] .= $redcopy2."_rt,";
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
        array (
          "01_medium" => $data ["01_medium"],
          "01_datum" => konv_taktime_datetime ($data ["01_datum"]),
          "01_zeichen" => $data ["01_zeichen"],
          "05_gegenstelle" => $data ["05_gegenstelle"],
          "07_durchspruch" => $data ["07_durchspruch"],
          "08_befhinweis" => $data ["08_befhinweis"],
          "08_befhinwausw" => $data ["08_befhinwausw"],
          "09_vorrangstufe" => $data ["09_vorrangstufe"],
          "10_anschrift" => $data ["10_anschrift"],
          "11_gesprnotiz" => $data ["11_gesprnotiz"],
          "12_anhang" => $data ["12_anhang"],
          "12_inhalt" => $data ["12_inhalt"],
          "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
          "13_abseinheit" => $data ["13_abseinheit"],
          "14_zeichen" => $data ["14_zeichen"],
          "14_funktion" => $data ["14_funktion"],
          "16_empf" => $data ["16_empf"],
          "x00_status" => 4,
          "x01_abschluss" => "f",
        ),
        $conf_4f_tbl ["anhang"]
      );
      protokolleintrag ("FM-Eingang", "message_id=".$storedMessage ["id"]);
    break;

		case "FM-Eingang_Sichter":
		case "FM-Eingang_Anhang_Sichter" :
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>FM-Eingang_Sichter, FM-Eingang_Anhang_Sichter</big><br>\n";}
       /*****************************************************************************************************
           Betroffene Felder:
            01_medium            01_datum   TTMM            01_zeit    SSMM            01_zeichen            05_gegenstelle            07_durchspruch;            08_befhinweis;
            08_befhinwausw;            09_vorrangstufe;            10_anschrift;            11_gesprnotiz;            12_inhalt;            12_abfzeit;            13_abseinheit;
            14_zeichen;            14_funktion;             15_quitdatum;          15_quitzeichen;          16_empf;          17_vermerke;
        Workflow ==>
            Ergaenzung Nachweisdaten (E und Nachweisnummer) 04_richtung 04_numme
            Daten in Datenbank mit einem INSERT
            INSERT INTO tabelle SET spalten_name=ausdruck, spalten_name=ausdruck, ...
      ******************************************************************************************************/
			$data ["16_empf"] = $redcopy2."_rt,";

       	for (  $i = 1 ; $i <= 5 ; $i++ ){
         	for ( $j = 1 ; $j <= 5 ; $j++ ){
           		if ( isset ( $data ["16_".$i.$j] ) ) {
             		list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             		$data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           		}
           		if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             		$data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           		}
         	}
       	}

       	if ($data ["01_datum"] == "" ) { $data ["01_datum"] = date ("Hi") ; }
       	if ($data ["12_abfzeit"] == "" ) { $data ["12_abfzeit"] = date ("Hi") ; }
       	if ($data ["15_quitdatum"] == "" ) { $data ["15_quitdatum"] = date ("Hi") ; }

        	if (validate){
         	  /*----------------------------------------------------*/
				if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>"; var_dump ($data); echo "<br>\n";}
          	$vali = new vali_data_form ( $data ) ;
          	$result = $vali->validatethis (); //checkdata ();
/*
          if (debug){
            echo "DATAHNDL374=";
            echo "<b>RESULT</b>";
            var_dump ($result); echo "<br>";

            echo "<b>vali-data</b>";
            var_dump ($vali->i_data); echo "<br>";

            echo "<b>vali-VALIDATE</b>";
            var_dump ($vali->validate); echo "<br>";
          }
*/
				$data = $vali->i_data ;
				if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b>"; var_dump ($data); echo "<br>\n";}          
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
        array (
          "01_medium" => $data ["01_medium"],
          "01_datum" => konv_taktime_datetime ($data ["01_datum"]),
          "01_zeichen" => $data ["01_zeichen"],
          "05_gegenstelle" => $data ["05_gegenstelle"],
          "07_durchspruch" => $data ["07_durchspruch"],
          "08_befhinweis" => $data ["08_befhinweis"],
          "08_befhinwausw" => $data ["08_befhinwausw"],
          "09_vorrangstufe" => $data ["09_vorrangstufe"],
          "10_anschrift" => $data ["10_anschrift"],
          "11_gesprnotiz" => $data ["11_gesprnotiz"],
          "12_anhang" => $data ["12_anhang"],
          "12_inhalt" => $data ["12_inhalt"],
          "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
          "13_abseinheit" => $data ["13_abseinheit"],
          "14_zeichen" => $data ["14_zeichen"],
          "14_funktion" => $data ["14_funktion"],
          "15_quitdatum" => konv_taktime_datetime ($data ["15_quitdatum"]),
          "15_quitzeichen" => $data ["15_quitzeichen"],
          "16_empf" => $data ["16_empf"],
          "17_vermerke" => $data ["17_vermerke"],
          "x00_status" => 8,
          "x01_abschluss" => "t",
        ),
        $conf_4f_tbl ["anhang"]
      );
      protokolleintrag ("FM-Eingang-Sichter", "message_id=".$storedMessage ["id"]);
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

      if ($data ["02_zeit"] == "" ) {
        $data ["02_zeit"] = convtodatetime ( date ("dm"),   date ("Hi") )  ;
      }


       $data ["16_empf"] = $redcopy2."_rt,".$data ["14_funktion"]."_gn"; // Der Verfasser bekommt den gruenen
       $storedMessage = estab_message_insert_numbered (
         $messageConnection,
         $conf_4f_db ["datenbank"],
         $conf_4f_tbl ["nachrichten"],
         "A",
         Nachweisung === "getrennt",
         array (
           "02_zeit" => $data ["02_zeit"],
           "02_zeichen" => "",
           "07_durchspruch" => $data ["07_durchspruch"],
           "08_befhinweis" => $data ["08_befhinweis"],
           "08_befhinwausw" => $data ["08_befhinwausw"],
           "09_vorrangstufe" => $data ["09_vorrangstufe"],
           "10_anschrift" => $data ["10_anschrift"],
           "11_gesprnotiz" => $data ["11_gesprnotiz"],
           "12_anhang" => $data ["12_anhang"],
           "12_inhalt" => $data ["12_inhalt"],
           "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
           "13_abseinheit" => $data ["13_abseinheit"],
           "14_zeichen" => $data ["14_zeichen"],
           "14_funktion" => $data ["14_funktion"],
           "16_empf" => $data ["16_empf"],
           "x00_status" => 2,
           "x01_abschluss" => "f",
         ),
         $conf_4f_tbl ["anhang"]
       );
       protokolleintrag ("Stab-schreiben", "message_id=".$storedMessage ["id"]);
       set_msg_read ($storedMessage ["id"]) ;
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
      if ($data ["15_quitdatum"] == "" ) { $data ["15_quitdatum"] = date ("Hi") ; }

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

      if ($data ["02_zeit"] == "" ) {
        $data ["02_zeit"] = convtodatetime ( date ("dm"),   date ("Hi") )  ;
      }

       for (  $i = 1 ; $i <= 5 ; $i++ ){
         for ( $j = 1 ; $j <= 5 ; $j++ ){
           if ( isset ( $data ["16_".$i.$j] ) ) {
             list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           }
           if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }
         }
       }
       $data ["11_gesprnotiz"] = "t" ;
       $data ["16_empf"] .= $redcopy2."_rt,".$data ["14_funktion"]."_gn"; // Der Verfasser bekommt den gruenen
       $storedMessage = estab_message_insert_numbered (
         $messageConnection,
         $conf_4f_db ["datenbank"],
         $conf_4f_tbl ["nachrichten"],
         "E",
         Nachweisung === "getrennt",
         array (
           "01_medium" => $data ["01_medium"],
           "01_datum" => konv_taktime_datetime ($data ["01_datum"]),
           "01_zeichen" => $data ["01_zeichen"],
           "07_durchspruch" => $data ["07_durchspruch"],
           "08_befhinweis" => $data ["08_befhinweis"],
           "08_befhinwausw" => $data ["08_befhinwausw"],
           "09_vorrangstufe" => $data ["09_vorrangstufe"],
           "10_anschrift" => $data ["10_anschrift"],
           "11_gesprnotiz" => $data ["11_gesprnotiz"],
           "12_anhang" => $data ["12_anhang"],
           "12_inhalt" => $data ["12_inhalt"],
           "12_abfzeit" => konv_taktime_datetime ($data ["12_abfzeit"]),
           "13_abseinheit" => $data ["13_abseinheit"],
           "14_zeichen" => $data ["14_zeichen"],
           "14_funktion" => $data ["14_funktion"],
           "16_empf" => $data ["16_empf"],
           "17_vermerke" => $data ["17_vermerke"],
           "x00_status" => 8,
           "x01_abschluss" => "t",
           "x02_sperre" => "f",
           "x03_sperruser" => "",
         ),
         $conf_4f_tbl ["anhang"]
       );
       protokolleintrag ("Stab-gesprnoti", "message_id=".$storedMessage ["id"]);
       set_msg_read ($storedMessage ["id"]) ;

		break;

		case "FM-Ausgang":
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>FM-Ausgang</big><br>\n";}		
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### - FM-Ausgang <br>\n";}
      	if ($data ["03_datum"] == "" ) { $data ["03_datum"] = date ("Hi") ; }
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
				$form = new nachrichten4fach ($data, $data["task"], $vali->validate);
           	exit ;
         }
      }
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### VOR conf_4f[\"si_in_out\"]".$conf_4f["si_in_out"]."<br>"; }      
			if($conf_4f["si_in_out"]) {  //  Ein- und Ausänge sichten
				if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### Ein- und Ausgänge sichten<br>"; }
        $transportStatus = 4;
        $transportClosed = "f";
       } else {
         if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b> ### nur Eingänge sichten<br>"; }
        $transportStatus = 8;
        $transportClosed = "t";
       }
       $transportSaved = estab_message_update_locked_outgoing (
         $messageConnection,
         $conf_4f_tbl ["nachrichten"],
         $data ["00_lfd"],
         (string) $_SESSION ["vStab_kuerzel"],
         array (
           "03_datum" => konv_taktime_datetime ($data ["03_datum"]),
           "03_zeichen" => $data ["03_zeichen"],
           "05_gegenstelle" => $data ["05_gegenstelle"],
           "06_befweg" => $data ["06_befweg"],
           "06_befwegausw" => $data ["06_befwegausw"],
           "x00_status" => $transportStatus,
           "x01_abschluss" => $transportClosed,
           "x02_sperre" => "f",
           "x03_sperruser" => "",
         )
       );
       if (!$transportSaved) {
         throw new RuntimeException ("Message lock or status changed");
       }
       protokolleintrag ("FM-Ausgang", "message_id=".estab_message_positive_id ($data ["00_lfd"]));
   break;

		case "FM-Ausgang_Sichter":
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>FM-Ausgang_Sichter</big><br>\n";}
		if ($data ["15_quitdatum"] == "" ) { $data ["15_quitdatum"] = date ("Hi") ; }
		$data ["16_empf"] = $redcopy2."_rt,";

      for (  $i = 1 ; $i <= 5 ; $i++ ){
      	for ( $j = 1 ; $j <= 5 ; $j++ ){
         	if ( isset ( $data ["16_".$i.$j] ) ) {
            	list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             	$data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           	} // if
           	if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
            	$data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }
         } // for 2.
       } // for 1.

      if ($data ["03_datum"] == "" ) { $data ["03_datum"] = date ("Hi") ; }

      if (validate){
			if (debug){
         	echo "DATAHNDL FM-Ausgang_Sichter =";
          	var_dump ($data); echo "<br><br>";
        	}
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
         	$form = new nachrichten4fach ($data, $data["task"], $vali->validate);
           	exit ;
         }
      }
      $transportAndReviewSaved = estab_message_update_locked_outgoing (
        $messageConnection,
        $conf_4f_tbl ["nachrichten"],
        $data ["00_lfd"],
        (string) $_SESSION ["vStab_kuerzel"],
        array (
          "03_datum" => konv_taktime_datetime ($data ["03_datum"]),
          "03_zeichen" => $data ["03_zeichen"],
          "05_gegenstelle" => $data ["05_gegenstelle"],
          "06_befweg" => $data ["06_befweg"],
          "06_befwegausw" => $data ["06_befwegausw"],
          "15_quitdatum" => konv_taktime_datetime ($data ["15_quitdatum"]),
          "15_quitzeichen" => $data ["15_quitzeichen"],
          "16_empf" => $data ["16_empf"],
          "17_vermerke" => $data ["17_vermerke"],
          "x00_status" => 8,
          "x01_abschluss" => "t",
          "x02_sperre" => "f",
          "x03_sperruser" => "",
        )
      );
      if (!$transportAndReviewSaved) {
        throw new RuntimeException ("Message lock or status changed");
      }
      protokolleintrag ("FM-Ausgang-Sichter", "message_id=".estab_message_positive_id ($data ["00_lfd"]));
		break;


		case "Stab_sichten":
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Stab_sichten</big><br>\n";}   
/*
          15_quitdatum;
          15_quitzeichen;
          16_empf;
          17_vermerke;
*/
       $data ["16_empf"] = $redcopy2."_rt,";

       for (  $i = 1 ; $i <= 5 ; $i++ ){
         for ( $j = 1 ; $j <= 5 ; $j++ ){
           if ( isset ( $data ["16_".$i.$j] ) ) {
             list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           }
           if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }
         }
       }


       if ($data ["15_quitdatum"] == "" ) {
         $data ["15_quitdatum"] = date ("Hi")  ;
       }  else {
         $data ["15_quitdatum"] = $data ["15_quitdatum"] ;
       }
       $reviewSaved = estab_message_update_pending_review (
         $messageConnection,
         $conf_4f_tbl ["nachrichten"],
         $data ["00_lfd"],
         (bool) ($conf_4f ["si_in_out"] ?? true),
         array (
           "15_quitdatum" => convtodatetime (date ("dm"), $data ["15_quitdatum"]),
           "15_quitzeichen" => $data ["15_quitzeichen"],
           "16_empf" => $data ["16_empf"],
           "17_vermerke" => $data ["17_vermerke"],
           "x00_status" => 8,
           "x01_abschluss" => "t",
           "x02_sperre" => "f",
           "x03_sperruser" => "",
         )
       );
       if (!$reviewSaved) {
         throw new RuntimeException ("Message review status changed");
       }
       protokolleintrag ("Stab_sichten", "message_id=".estab_message_positive_id ($data ["00_lfd"]));
    break;

		case "Nachweis":
			if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>Nachweis</big><br>\n";}
/*
          04_richtung;
          04_nummer;
*/
   break;

	case "FM-Admin":
	case "SI-Admin":
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>FM-Admin, SI-Admin</big><br>\n";}
       // Preserve the original review timestamp exactly. The administration
       // form renders it read-only and only permits code, recipients and note.
       $storedAdminMessage = estab_message_fetch_by_id (
         $messageConnection,
         $conf_4f_tbl ["nachrichten"],
         $data ["00_lfd"]
       );
       if (!is_array ($storedAdminMessage)) {
         throw new RuntimeException ("Message not found");
       }
       $data ["16_empf"] = $redcopy2."_rt,";
       for (  $i = 1 ; $i <= 5 ; $i++ ){
         for ( $j = 1 ; $j <= 5 ; $j++ ){
           if ( isset ( $data ["16_".$i.$j] ) ) {
             list ($ord, $pos, $fkt) = explode ("_", $data ["16_".$i.$j]);
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_".$fkt.",";
           }
           if ( $data ["16_gncopy"] == "16_".$i.$j."_gn" ) {
             $data ["16_empf"] .= $empf_matrix [$i][$j]["fkt"]."_gn,";
           }
         }
       }



       if (!estab_message_update (
         $messageConnection,
         $conf_4f_tbl ["nachrichten"],
         $data ["00_lfd"],
         array (
           "15_quitdatum" => $storedAdminMessage ["15_quitdatum"],
           "15_quitzeichen" => $data ["15_quitzeichen"],
           "16_empf" => $data ["16_empf"],
           "17_vermerke" => $data ["17_vermerke"],
         )
       )) {
         throw new RuntimeException ("Admin message update lost its target");
       }
       if ($data["task"] == "FM-Admin") {
         protokolleintrag ("++ FM-Admin", "message_id=".estab_message_positive_id ($data ["00_lfd"]));
       } else {
         protokolleintrag ("++ SI-Admin", "message_id=".estab_message_positive_id ($data ["00_lfd"]));
       }
	    break;
	  }
  } finally {
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
	function set_msg_read ($lfd) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>set_msg_read</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) $_SESSION ["vStab_funktion"],
      (string) $_SESSION ["vStab_kuerzel"],
      "read"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_set_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        (string) $_SESSION ["vStab_funktion"],
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
	function unset_msg_read ($lfd) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>unset_msg_read</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("../4fach/protokoll.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) $_SESSION ["vStab_funktion"],
      (string) $_SESSION ["vStab_kuerzel"],
      "read"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_unset_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        (string) $_SESSION ["vStab_funktion"]
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
	function set_msg_done ($lfd) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>set_msg_done</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("../4fach/protokoll.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) $_SESSION ["vStab_funktion"],
      (string) $_SESSION ["vStab_kuerzel"],
      "done"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_set_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        (string) $_SESSION ["vStab_funktion"],
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
	function unset_msg_done ($lfd) {
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>unset_msg_done</big><br>\n";}
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("../4fach/protokoll.php");
    $recordId = estab_message_positive_id ($lfd);
    $stateTable = estab_message_state_table (
      $conf_4f_tbl ["usrtblprefix"],
      (string) $_SESSION ["vStab_funktion"],
      (string) $_SESSION ["vStab_kuerzel"],
      "done"
    );
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_state_unset_for_recipient (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $stateTable,
        $recordId,
        (string) $_SESSION ["vStab_funktion"]
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
	function list_of_readed_msg (){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>list_of_readed_msg</big><br>\n";}
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
	function list_of_done_msg (){
		if ( debug ){ echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>list_of_done_msg</big><br>\n";}
		
		include ("../4fcfg/config.inc.php");
  		include ("../4fcfg/dbcfg.inc.php");
  		include ("../4fcfg/e_cfg.inc.php");

      $stateTable = estab_message_state_table (
        $conf_4f_tbl ["usrtblprefix"],
        (string) $_SESSION ["vStab_funktion"],
        (string) $_SESSION ["vStab_kuerzel"],
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
  function reset_record_lock ( $lfd ){

    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");

    $recordId = estab_message_positive_id ($lfd);
    $connection = estab_message_connect ($conf_4f_db);
    try {
      if (!estab_message_release_lock (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $recordId
      )) {
        throw new RuntimeException ("Message lock reset lost its target");
      }
    } finally {
      estab_auth_close ($connection);
    }
    protokolleintrag ("Fernmelder Free_record", "message_id=".$recordId);
  }

?>
