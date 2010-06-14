<?php

    echo "<big><big><big> Parameter der config.inc.php</big></big></big>";
    echo date("l dS of F Y h:i:s A");

include ("config.inc.php");

    echo "<table>";
    echo "<tbody>";

    if ($conf_4f["sounds"]) {
      echo "<tr><td>"; echo " conf_4f[sounds]"; echo "</td><td>"; echo "TRUE" ; echo "</td></tr>";
    } else {
      echo "<tr><td>"; echo " conf_4f[sounds]"; echo "</td><td>"; echo "FALSE" ; echo "</td></tr>";
    }
    echo "<tr><td>"; echo " Nachweisung    ="; echo "</td><td>"; echo Nachweisung; echo "</td></tr>";
    echo "<tr><td>"; echo " posttakzeit    ="; echo "</td><td>"; echo posttakzeit ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_pre_dir ="; echo "</td><td>"; echo $conf_pre_dir ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_urlroot ="; echo "</td><td>"; echo $conf_urlroot ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_web [\"srvroot\"] ="; echo "</td><td>"; echo $conf_web ["srvroot"] ; echo "</td></tr>";
    echo "<tr><td>"; echo " pre_url ="; echo "</td><td>"; echo $pre_url ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_design ="; echo "</td><td>"; echo $conf_design ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_web [\"pre_path\"] ="; echo "</td><td>"; echo $conf_web ["pre_path"] ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_menue [\"symbole\"] ="; echo "</td><td>"; echo $conf_menue ["symbole"] ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_design_path ="; echo "</td><td>"; echo $conf_design_path ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_design_URI ="; echo "</td><td>"; echo $conf_design_URI ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"Titelkurz\"] ="; echo "</td><td>"; echo $conf_4f ["Titelkurz"] ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"SubTitel\"][\"env\"] ="; echo "</td><td>"; echo $conf_4f ["SubTitel"]["env"] ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"SubTitel\"][\"etb\"] ="; echo "</td><td>"; echo $conf_4f ["SubTitel"]["etb"] ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"Version\"] ="; echo "</td><td>"; echo $conf_4f ["Version"] ; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"Stelle\"] ="; echo "</td><td>"; echo $conf_4f ["Stelle"] ; echo "</td></tr>";
    foreach ($conf_4f ["NameVersion"] as $key => $value){
      echo "<tr><td>"; echo " $key ="; echo "</td><td>"; echo $value ; echo "</td></tr>";
    }
    echo "<tr><td>"; echo " conf_4f [\"data\"]    ="; echo "</td><td>"; echo $conf_4f ["data"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"anhang\"]    ="; echo "</td><td>"; echo $conf_4f ["anhang"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"vordruck\"]    ="; echo "</td><td>"; echo $conf_4f ["vordruck"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"MainURL\"]    ="; echo "</td><td>"; echo $conf_4f ["MainURL"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"ablage_dir\"]    ="; echo "</td><td>"; echo $conf_4f ["ablage_dir"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"ablage_uri\"]    ="; echo "</td><td>"; echo $conf_4f ["ablage_uri"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"vordruck_dir\"]    ="; echo "</td><td>"; echo $conf_4f ["vordruck_dir"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f [\"einsatzende_dir\"]    ="; echo "</td><td>"; echo $conf_4f ["einsatzende_dir"]; echo "</td></tr>";
    echo "<tr><td>"; echo " conf_4f_liste [\"inhalt\"]    ="; echo "</td><td>"; echo $conf_4f_liste ["inhalt"]; echo "</td></tr>";
    echo "</tbody>";
    echo "</table>";
?>
