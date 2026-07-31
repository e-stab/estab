<?php
/*****************************************************************************\
   Datei: backup.php

   benoetigte Dateien:

   Beschreibung:

   Funktionen:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

if (!defined ("debug")) { define ("debug", false); }

/*****************************************************************************

******************************************************************************/
if ((!isset ($returnValue)) and (isset ($_GET))){ 
  $returnValue = $_GET;
}


include  ("../4fcfg/config.inc.php");
include  ("../4fcfg/dbcfg.inc.php");     // Datenbankparameter
include  ("../4fcfg/e_cfg.inc.php");     // Datenbankparameter
require_once  ("../4fach/db_operation.php");  // Datenbank operationen
require_once  ("../4fach/tools.php") ;
require_once  ("../4fbak/backup_pdf.php") ;
require_once  ("../app/generated_form.php") ;

//define('FPDF_FONTPATH',$_SERVER ["DOCUMENT_ROOT"]."/".$conf_web ["pre_path"].'4fbak/fpdf/font/');


@ini_set('memory_limit', '64M');

  // schalte Ausführungslimit aus = unbegrenzte Laufzeit
set_time_limit ( 0 );

  if (isset($returnValue["anz"])){
    echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">";
    echo "<HTML>";
    echo "<HEAD>";
    echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">";
    echo "<TITLE>Einsatz abschliessen.</TITLE>";
    echo "<META NAME=\"GENERATOR\" CONTENT=\"OpenOffice.org 2.0  (Linux)\">";
    echo "<META NAME=\"AUTHOR\" CONTENT=\"Hajo Landmesser\">";
    echo "<META NAME=\"CREATED\" CONTENT=\"20070327;15421200\">";
    echo "<META NAME=\"CHANGEDBY\" CONTENT=\"hajo\">";
    echo "<META NAME=\"CHANGED\" CONTENT=\"20080612;18052200\">";
    echo "<meta http-equiv=\"cache-control\" content=\"no-cache\">";
    echo "<meta http-equiv=\"pragma\" content=\"no-cache\">";
    echo "</HEAD>";
    echo "<BODY>";
  }


  if (isset ( $returnValue["anz"] )){ $anzahl = $returnValue["anz"]; } else { $anzahl = 1 ; }

  $generatedCount = 0;
  try {
    $connection = estab_auth_connect ($conf_4f_db);
    try {
      if (!$connection->begin_transaction ()) {
        throw new RuntimeException ("Vordrucktransaktion konnte nicht gestartet werden");
      }
      try {
        $incident = estab_incident_require_active ($connection, true);
        estab_incident_command_post_name ($incident);
        $incidentId = (int) $incident ["active_einsatz_id"];
        $dbdata = estab_generated_form_fetch_pending (
          $connection,
          $conf_4f_tbl ["nachrichten"],
          $incidentId,
          $anzahl
        );
        foreach ($dbdata as $formdata) {
          $vordruckpdf = new vordruckaspdf ($formdata);
          $vordruckpdf->SetFont ('helvetica');
          $vordruckpdf->SetAutoPageBreak (
            true,
            $vordruckpdf->bottom - $vordruckpdf->point [38][1]
          );
          $vordruckpdf->main ();
          estab_generated_form_mark_published (
            $connection,
            $conf_4f_tbl ["nachrichten"],
            $incidentId,
            $formdata ["00_lfd"]
          );
          $generatedCount++;
        }
        if (!$connection->commit ()) {
          throw new RuntimeException ("Vordrucktransaktion konnte nicht gespeichert werden");
        }
      } catch (Throwable $exception) {
        $connection->rollback ();
        throw $exception;
      }
    } finally {
      estab_auth_close ($connection);
    }
  } catch (EstabNoActiveIncidentException | EstabIncidentConfigurationException $exception) {
    http_response_code (409);
    header ("Cache-Control: no-store");
    $generationBlocked = $exception instanceof EstabNoActiveIncidentException
      ? "Kein Einsatz ist aktiv. PDF-Vordrucke können nicht erzeugt werden."
      : "Für den aktiven Einsatz fehlt der Name der Führungsstelle. ".
        "Legen Sie ihn zuerst in der Einsatzverwaltung fest.";
    if (!isset ($returnValue ["anz"])) {
      header ("Content-Type: text/plain; charset=UTF-8");
      echo $generationBlocked;
    } else {
      echo "<p role=\"alert\"><b>".
        htmlspecialchars ($generationBlocked, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8").
        "</b></p></BODY></HTML>";
    }
    exit;
  }

  if (isset($returnValue["anz"])){
  echo "<FORM action=\"../4fadm/admin.php\" method=\"get\" target=\"_self\">\n";
  echo "<fieldset>";
  echo "<legend>Aktion:</legend>\n";
  echo "<table border=\"1\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=$color_data_table>\n";
  echo "<tr>\n";
  echo "<td bgcolor=$color_button_ok><input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/ok.gif\"></td>\n";
  echo "</td></tr>\n";
  echo "</table>\n";
  echo "</fieldset>";
  echo "<br>";
  }


  if (isset($returnValue["anz"])){
    echo "<big><big>".$generatedCount." PDF-Vordruck(e) erzeugt</big></big>";
    echo "</BODY></HTML>";
  }

?>
