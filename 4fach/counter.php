<?php
/*****************************************************************************\
   Datei: status.php

   benötigte Dateien:    tools.php, db_operation.php

   Beschreibung:

          Hier wird die Statusspalte links dargestellt.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/

session_start ();

include ("tools.php");
include ("db_operation.php");
include ("../4fcfg/config.inc.php");
pre_html ("status", "Status","");
echo "<body bgcolor=\"#ECECFF\">";

/****************************************************************************
  O u t q u e u e c o u n t e r
*****************************************************************************/

// Status A/W:
if ( $_SESSION ["ROLLE"] == "Fernmelder") {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getoutqueuecount ();
  if ($outqueue == 0 ){
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
        if (( $_SESSION["old_que_aw"] < $outqueue) and ( $conf_4f["sounds"] ) ) {
        echo "<object height=\"0%\" width=\"0%\" classid=\"clsid:22D6F312-B0F6-11D0-94AB-0080C74C7E95\">
                <param name=\"FileName\" value=\"".$conf_design_path."/notify_aw.wav\" />
                </object>";
        }
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";
  $_SESSION[old_que_aw] = $outqueue;
}

// Status Sichter:
if ( ( $_SESSION ["ROLLE"] == "Stab") and
     ($_SESSION ["vStab_funktion"] == "Si" ) ) {
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
  $_SESSION[old_que_si] = $outqueue;
}

//  Status Stab:
if ( ( $_SESSION ["ROLLE"] == "FB") or
     (  ( $_SESSION ["ROLLE"] == "Stab") and
        ($_SESSION ["vStab_funktion"] != "Si" )
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
        if ( ( $_SESSION[old_que_stab] < $outqueue) and ( $conf_4f["sounds"] ) ) {
        echo "<object height=\"0%\"     width=\"0%\" classid=\"clsid:22D6F312-B0F6-11D0-94AB-0080C74C7E95\">
                <param name=\"FileName\" value=\"".$conf_design_path."/notify_stab.wav\" />
                </object>";
        }
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";
  $_SESSION[old_que_stab] = $outqueue;
}

showsrvtime ("vertikal");

echo "</body>";

echo "</html>";

?>
