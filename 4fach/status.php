<?php
/*****************************************************************************\
   Datei: status.php

   benötigte Dateien:    tools.php, db_operation.php

   Beschreibung:

          Hier wird die Statusspalte links dargestellt.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/

  error_reporting(E_ERROR | E_WARNING);

session_start ();

/*!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!*/
include ("../4fcfg/dbcfg.inc.php");    // Datenbankparameter

  $db = mysql_connect($conf_4f_db   ["server"],$conf_4f_db   ["user"], $conf_4f_db   ["password"] );
  $result = mysql_ping  ($db);
  
  if ($result == false){
    echo "<big>DB?</big>";
    exit;
  }
  if (isset($db)){
    mysql_close($db);
  }

/*!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!*/

include ("tools.php");
include ("db_operation.php");
include ("../4fcfg/config.inc.php");


pre_html ("status", "Status","");

echo "<body bgcolor=\"#ECECFF\">";

systemstatus ("vertikal");

echo "<table align=\"center\" style=\"text-align:center; width: 50px; background-color: rgb(150, 150, 150); height: 10px;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
echo "<tbody>\n";
echo "<tr><td>";
echo "<img src=\"".$conf_design_path."/timer.gif\">";
echo "</td></tr>";
echo "</table>";
?>

</body>

</html>
