<?php
/*****************************************************************************

                      Definitionen fuer die Kats - Menue

  Menuestruktur des Startmenues

******************************************************************************/
include ("./4fcfg/config.inc.php");

    $conf_menue["background_color"] = "rgb(240, 100, 100)";
    $conf_menue["foreground_color"] = "rgb(240,  80,  80)";

    $conf_menue["einrichtung"] = "Einsatzleitung";
    $conf_menue["titel"] = "eStab Webschnittstelle BETA Version 0.9";
//      $conf_menue["symbole"] = "./symbole/";
    $conf_menue["sym_top_left"] = $conf_menue ["symbole"]."el80.gif";
    $conf_menue["sym_top_right"] = $conf_menue ["symbole"]."iuk_80.jpg";

// Anordnung:
// 1            2
//3             4
//...

// links
    $menue[1]["text"] = "Nachrichtenvordruck";
    $menue[1]["pic"]  = $conf_menue ["symbole"]."4fach_aktiv.png";
    $menue[1]["link"] = "./4fach/index.php";

    $menue[3]["text"] = "Nachweisung";
    $menue[3]["pic"]  = $conf_menue ["symbole"]."nw.png";
    $menue[3]["link"] = "./4fach/nachwea.php?nwalle";

        $menue[5]["text"] = "Liste aller Meldungen";
    $menue[5]["pic"]  = $conf_menue ["symbole"]."all_msg.png";
    $menue[5]["link"] = "./4fach/ue_ltg.php";

    $menue[7]["text"] = "";
    $menue[7]["pic"]  = $conf_menue ["symbole"]."null.gif";
    $menue[7]["link"] = "";

    $zusatz_menue[1]["text"] = "administrative Massnahme";
    $zusatz_menue[1]["pic"]  = $conf_menue ["symbole"]."adm_aktiv.png";
    $zusatz_menue[1]["link"] = "./4fadm/admin.php";

// rechts
    $menue[2]["text"] = "Einsatztagebuch";
    $menue[2]["pic"]  = $conf_menue ["symbole"]."etb_aktiv.png";
    $menue[2]["link"] = "./stabetb/etb.php";

    $menue[4]["text"] = "";
    $menue[4]["pic"]  = $conf_menue ["symbole"]."null.gif";
    $menue[4]["link"] = "";

    $menue[6]["text"] = "Infosammunlung BOS";
    $menue[6]["pic"]  = $conf_menue ["symbole"]."merke32.gif";
    $menue[6]["link"] = "./stabinfo/index.php";

?>
