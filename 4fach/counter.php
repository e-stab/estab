<?php

/*
 * Retired standalone queue counter.
 *
 * The current sidebar/status component exposes incident-scoped workflow
 * information. This unreferenced legacy controller queried global queues
 * directly and could therefore disclose operational state without an
 * current authenticated account scope. The runtime image does not ship it;
 * source deployments fail closed before starting a session or querying data.
 */
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
echo 'Dieser alte Warteschlangenzähler ist stillgelegt.';
exit;

/*****************************************************************************\
   Datei: status.php

   benötigte Dateien:    tools.php, db_operation.php

   Beschreibung:

          Hier wird die Statusspalte links dargestellt.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/

session_start ();
require_once __DIR__ . "/../app/session_ui.php";
if (!estab_session_ui_is_embedded_frame ($_GET)) {
  estab_session_ui_start ($_SESSION, true);
}

define ("debug", false);              // true = gibt debuginformationen aus

include ("tools.php");
include ("db_operation.php");
include ("../4fcfg/config.inc.php");
pre_html ("status", "Status","");
echo "<body bgcolor=\"#ECECFF\">";

/****************************************************************************
  O u t q u e u e c o u n t e r
*****************************************************************************/

// Status A/W:
if ( (isset($_SESSION ['ROLLE'])) AND ($_SESSION ['ROLLE'] == "Fernmelder") ) {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getoutqueuecount ();
  if ($outqueue == 0 ){
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
        if ((isset($_SESSION['old_que_aw']))and( $_SESSION['old_que_aw'] < $outqueue) and ( $conf_4f['sounds'] ) ) {
        echo "<object height=\"0%\" width=\"0%\" classid=\"clsid:22D6F312-B0F6-11D0-94AB-0080C74C7E95\">
                <param name=\"FileName\" value=\"".$conf_design_path."/notify_aw.wav\" />
                </object>";
        }
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";
  $_SESSION['old_que_aw'] = $outqueue;
}

// Status Sichter:
if ( (isset($_SESSION ['ROLLE'])) AND (
     ( $_SESSION ['ROLLE'] == "Stab") and
     ( $_SESSION ['vStab_funktion'] == "Si" ) ) ) {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getviewerqueuecount ();
  if ($outqueue == 0 ){
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
        if ( ( $_SESSION["old_que_si"] < $outqueue) and ( $conf_4f["sounds"] ) ) {
        echo "<object height=\"0%\"     width=\"0%\" classid=\"clsid:22D6F312-B0F6-11D0-94AB-0080C74C7E95\">
                <param name=\"FileName\" value=\"".$conf_design_path."/notify_si.wav\" />
                </object>";
        }
 }
  echo "</td>";
  echo "</tr>";
  echo "</table>";
  $_SESSION['old_que_si'] = $outqueue;
}

//  Status Stab:
if ( (isset($_SESSION ['ROLLE'])) AND (
        ( $_SESSION ["ROLLE"] == "FB") or (($_SESSION ["ROLLE"] == "Stab") and ($_SESSION ["vStab_funktion"] != "Si" ))
     )
   )  {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getdonecount ();   //getreadedcount ();
  if ( $outqueue == 0 ) {
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    if ( $outqueue <= 99 ) {
      echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
    } else {
      echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">XX</p>\n";
    }

        if ( (isset($_SESSION['old_que_stab'])) and ( $_SESSION['old_que_stab'] < $outqueue) and ( $conf_4f["sounds"] ) ) {
        echo "<object height=\"0%\"     width=\"0%\" classid=\"clsid:22D6F312-B0F6-11D0-94AB-0080C74C7E95\">
                <param name=\"FileName\" value=\"".$conf_design_path."/notify_stab.wav\" />
                </object>";
        }
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";
  $_SESSION['old_que_stab'] = $outqueue;
}

showsrvtime ("vertikal");

echo "</body>";

echo "</html>";

?>
