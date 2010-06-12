<html>

<head>
  <title>Grafikerzeugung zur&uumlcksetzen</title>
</head>

<body>

<?php

include ("../config.inc.php");    // Konfigurationseinstellungen und Vorgaben
include ("../dbcfg.inc.php");     // Datenbankparameter
include ("../db_operation.php");  // Datenbank operationen


  $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);

  $query = "UPDATE `".$conf_4f_tbl ["nachrichten"]."` SET `x04_druck` = 'f' where  1; ";

  $res = $dbaccess->query_table_iu ($query);

  echo "<a><big><big>Die Datenbankmarkierung die kennzeichnet, das eine Nachricht als Grafik<br>
        erzeugt worden ist, wurde zur&uuml;ckgesetzt.<br><br>
        Nach jeder <b>abgeschlossenen</b> Nachricht wird nun bis zu 5 Nachrichten erneut konvertiert.<br><br>
        <b>WICHTIG!!!<br>Dieser Konvertierungsvorgang gibt KEINE Rückmeldung und kann bis zu 10s dauern.</b></big></big>";

?>

</body>

</html>
