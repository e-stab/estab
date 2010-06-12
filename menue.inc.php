<?php
/*****************************************************************************

                      Definitionen fuer die Kats - Menue

  Menuestruktur des Startmenues

******************************************************************************/

    $conf_menue ["einrichtung"] = "Einsatzleitung";

    $conf_menue ["titel"] = "eStab Webschnittstelle BETA Version 0.9";

    $conf_menue ["symbole"] = "./symbole/";

    $conf_menue ["sym_top_left"] = $conf_menue ["symbole"]."el80.gif";

    $conf_menue ["sym_top_right"] = $conf_menue ["symbole"]."iuk_80.jpg";

    $menue [1]["pic"]  = $conf_menue ["symbole"]."4fach_aktiv.png";
    $menue [1]["link"] = "./4fach/index.php";
    $menue [1]["text"] = "Nachrichtenvordruck";

    $menue [2]["pic"]  = $conf_menue ["symbole"]."nw.png";
    $menue [2]["link"] = "./4fach/nachwea.php?nwalle";
    $menue [2]["text"] = "Nachweisung";

    $menue [3]["pic"]  = $conf_menue ["symbole"]."etb_aktiv.png";
    $menue [3]["link"] = "./etb/etb.php";
    $menue [3]["text"] = "Einsatztagebuch";
/*
    $menue [4]["pic"]  = $conf_menue ["symbole"]."tbb_inaktiv.png";
    $menue [4]["link"] = "./tbb/index.php";
    $menue [4]["text"] = "technisches Betriebsbuch";

    $menue [5]["pic"]  = $conf_menue ["symbole"]."null.gif";
    $menue [5]["link"] = "";
    $menue [5]["text"] = "";

    $menue [6]["pic"]  = $conf_menue ["symbole"]."null.gif";
    $menue [6]["link"] = "";
    $menue [6]["text"] = "";

    $menue [7]["pic"]  = $conf_menue ["symbole"]."abc_inaktiv.png";
    $menue [7]["link"] = "./abc-erk/index.php";
    $menue [7]["text"] = "ABC-Erkunder";

    $menue [8]["pic"]  = $conf_menue ["symbole"]."br.jpg";
    $menue [8]["link"] = "./br/br.php";
    $menue [8]["text"] = "Bereitstellungsraum<br>BR-Manager";

    $menue [9]["pic"]  = $conf_menue ["symbole"]."kh_inaktiv.jpg";
    $menue [9]["link"] = "./bnw/bnw.html";
    $menue [9]["text"] = "Bettennachweis";

    $menue [10]["pic"]  = $conf_menue ["symbole"]."bhp_inaktiv.png";
    $menue [10]["link"] = "./bhp50/index00.php";
    $menue [10]["text"] = "Behandlungsplatz BHP 50";

    $menue [11]["pic"]  = $conf_menue ["symbole"]."null.gif";
    $menue [11]["link"] = "";
    $menue [11]["text"] = "";
*/
    $menue [4]["pic"]  = $conf_menue ["symbole"]."null.gif";
    $menue [4]["link"] = "";
    $menue [4]["text"] = "";

    $menue [5]["pic"]  = $conf_menue ["symbole"]."adm_aktiv.png";
    $menue [5]["link"] = "./admin.php";
    $menue [5]["text"] = "administrative Massnahme";

    $menue [6]["pic"]  = $conf_menue ["symbole"]."merke.gif";
    $menue [6]["link"] = "./info/index.php";
    $menue [6]["text"] = "Informationen";


    $zusatz_menue [1]["pic"]  = $conf_menue ["symbole"]."merke.gif";
    $zusatz_menue [1]["link"] = "./4fach/ue_ltg.php";
    $zusatz_menue [1]["text"] = "Liste aller Meldungen";




?>
