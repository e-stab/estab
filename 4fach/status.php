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
include ("../db_operation.php");
include ("../config.inc.php");
pre_html ("status", "Status","");

?>

<body>

<?php
/****************************************************************************
  O u t q u e u e c o u n t e r
*****************************************************************************/
if ( $_SESSION [ROLLE] == "Fernmelder") {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getoutqueuecount ();
  if ($outqueue == 0 ){
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";
}

if ( ( $_SESSION [ROLLE] == "Stab") and
     ($_SESSION ["vStab_funktion"] == "Si" ) ) {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getviewerqueuecount ();
  if ($outqueue == 0 ){
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";

}

if ( ( $_SESSION [ROLLE] == "FB") or
     (  ( $_SESSION [ROLLE] == "Stab") and
        ($_SESSION ["vStab_funktion"] != "Si" )
     )
   )  {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getreadedcount ();
  if ( $outqueue == 0 ) {
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } elseif ( $outqueue <= 99 ) {
      echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
    } else {
      echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">XX</p>\n";

    }
  echo "</td>";
  echo "</tr>";
  echo "</table>";

}

systemstatus ("vertikal");

echo "<table align=\"center\" style=\"text-align:center; width: 50px; background-color: rgb(150, 150, 150); height: 10px;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
echo "<tbody>\n";
echo "<tr><td>";
echo "<img src=\"".$conf_design_path."/018.gif\">";
echo "</td></tr>";
echo "</table>";
?>

</body>

</html>
