<?php
/*****************************************************************************

                      Definitionen fuer die Kats - Menue

  Menuestruktur des Startmenues

******************************************************************************/
include ("./4fcfg/config.inc.php");

    $conf_menue ["einrichtung"] = "Einsatzleitung";

    $conf_menue ["titel"] = "eStab Webschnittstelle BETA Version 0.9";

//    $conf_menue ["symbole"] = "./symbole/";

    $conf_menue ["sym_top_left"] = $conf_menue ["symbole"]."el80.gif";

    $conf_menue ["sym_top_right"] = $conf_menue ["symbole"]."iuk_80.jpg";

    $menue [1]["pic"]  = $conf_menue ["symbole"]."4fach_aktiv.png";
    $menue [1]["link"] = "./4fach/index.php";
    $menue [1]["text"] = "Nachrichtenvordruck";

    $menue [2]["pic"]  = $conf_menue ["symbole"]."nw.png";
    $menue [2]["link"] = "./4fach/nachwea.php?nwalle";
    $menue [2]["text"] = "Nachweisung";

    $menue [3]["pic"]  = $conf_menue ["symbole"]."etb_aktiv.png";
    $menue [3]["link"] = "./stabetb/etb.php";
    $menue [3]["text"] = "Einsatztagebuch";

    $menue [4]["pic"]  = $conf_menue ["symbole"]."null.gif";
    $menue [4]["link"] = "";
    $menue [4]["text"] = "";

    $menue [5]["pic"]  = $conf_menue ["symbole"]."adm_aktiv.png";
    $menue [5]["link"] = "./4fadm/admin.php";
    $menue [5]["text"] = "administrative Massnahme";

    $menue [6]["pic"]  = $conf_menue ["symbole"]."merke32.gif";
    $menue [6]["link"] = "./stabinfo/index.php";
    $menue [6]["text"] = "Informationen";

    $zusatz_menue [1]["pic"]  = $conf_menue ["symbole"]."all_msg.png";
    $zusatz_menue [1]["link"] = "./4fach/ue_ltg.php";
    $zusatz_menue [1]["text"] = "Liste aller Meldungen";
?>
