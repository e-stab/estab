<?php

include ("../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben
include ("../4fcfg/dbcfg.inc.php");     // Datenbankparameter
include ("../4fcfg/e_cfg.inc.php");
include ("./db_operation.php");  // Datenbank operationen

echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
echo "<html>\n";
echo "<head>\n";
echo "<meta content=\"text/html; charset=ISO-8859-1\" http-equiv=\"content-type\">\n";
echo "<title>Grafikerzeugung zur&uumlcksetze</title>";
echo "</head>";
echo "<body>";

echo "<P align=center><FONT FACE=\"Arial Black\"><FONT SIZE=4>Datenbank Verbindungspr&uuml;fung:</FONT></FONT></P>";
echo "<br>\n";

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
