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

	$menue[5]["text"] = "PDFs & Anhänge";
    $menue[5]["pic"]  = "./4fach/design/mr/folder_global.gif";
    $menue[5]["link"] = "./4fdata";

    $menue[7]["text"] = "Infosammunlung BOS";
    $menue[7]["pic"]  = $conf_menue ["symbole"]."merke32.gif";
    $menue[7]["link"] = "./stabinfo/index.php";

    $zusatz_menue[1]["text"] = "administrative Massnahme";
    $zusatz_menue[1]["pic"]  = $conf_menue ["symbole"]."adm_aktiv.png";
    $zusatz_menue[1]["link"] = "./4fadm/admin.php";

// rechts
    $menue[2]["text"] = "Einsatztagebuch";
    $menue[2]["pic"]  = $conf_menue ["symbole"]."etb_aktiv.png";
    $menue[2]["link"] = "./stabetb/etb.php";

    $menue[4]["text"] = "Technisches Betriebsbuch";
    $menue[4]["pic"]  = $conf_menue ["symbole"]."tbb_aktiv.png";
    $menue[4]["link"] = "./fmtbb/tbb.php";

	$menue[6]["text"] = "Liste aller Meldungen";
    $menue[6]["pic"]  = $conf_menue ["symbole"]."all_msg.png";
    $menue[6]["link"] = "./4fueltg/ue_ltg.php";

        $zusatz_menue[2]["text"] = "Kurzanleitung zur eStab Installation & Nutzung";
    $zusatz_menue[2]["pic"]  = $conf_menue ["symbole"]."icon_handbuch.gif";
    $zusatz_menue[2]["link"] = "./doku/Handbuch_eStab.pdf";

?>
