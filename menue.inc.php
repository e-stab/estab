<?php
/*****************************************************************************

                      Definitionen fuer die Kats - Menue

  Menuestruktur des Startmenues

******************************************************************************/
include ("./4fcfg/config.inc.php");

/*    
	 $conf_menue["background_color"] = "rgb(240, 100, 100)";
    $conf_menue["foreground_color"] = "rgb(240,  80,  80)";
*/

	 $conf_menue["background_color"] = "rgb(240, 240, 240)";
    $conf_menue["foreground_color"] = "rgb(240, 240, 240)";


    $conf_menue["einrichtung"] = "Einsatzleitung";
    $conf_menue["titel"] = "eStab Webschnittstelle BETA Version 0.9";
//      $conf_menue["symbole"] = "./symbole/";
    $conf_menue["sym_top_left"] = $conf_menue ["symbole"]."el80.gif";
    $conf_menue["sym_top_right"] = $conf_menue ["symbole"]."iuk_80.jpg";


   /*
    	1		2	
    	3		4		
    	5		6
		7		8
		z1		z2
		  	
   $menue[1]["text"] = Menütext
	$menue[1]["info"] = Alternativtext (Mouseover)
   $menue[1]["pic"]  = Bild zum Menüpunkt
   $menue[1]["link"] = link zum Skript
	$menue[1]["visible"] = true|false = sichtbar oder nicht   
	
	
    */

// links
   $menue[1]["text"] = "Nachrichtenvordruck";
	$menue[1]["info"] = "Anmeldung als Teilnehmer des Stabs.\nAls Sachgebiet, Fachberater oder Verbindungsbeamter.\nDie Anmeldung entscheidet &uuml;ber die Zugriffsrechte auf andere Men&uuml;punkte.";
   $menue[1]["pic"]  = $conf_menue ["symbole"]."4fach_aktiv.png";
   $menue[1]["link"] = "./4fach/index.php";
	$menue[1]["visible"] = true ;  

	$menue[3]["text"] = "Generierte Vordrucke<BR>(S2)";
	$menue[3]["info"] = "Zeigt das Verzeichnis mit den abgeschlossenen\nNachrichtenvordrucken als angelegten PDF-Dateien.";
   $menue[3]["pic"]  = "./4fach/design/mr/folder_global.gif";
   $menue[3]["link"] = "./4fdata/" . $conf_4f_db["datenbank"] . "/vordruck/";
   $menue[3]["visible"] = true ;

	$menue[5]["text"] = "Liste aller Meldungen";
	$menue[5]["info"] = "Funktion gedacht f&uumlr eine &Uuml;bungsleitung.\nHier werden alle Meldungen mit ihrer Sichtung dargestellt.";
   $menue[5]["pic"]  = $conf_menue ["symbole"]."all_msg.png";
   $menue[5]["link"] = "./4fueltg/ue_ltg.php";
   $menue[5]["visible"] = true ;

   $menue[7]["text"] = "Infosammunlung BOS";
	$menue[7]["info"] = "Informationen rund um die Stabsarbeit.";
   $menue[7]["pic"]  = $conf_menue ["symbole"]."merke32.gif";
   $menue[7]["link"] = "./stabinfo/Content/index.php";
   $menue[7]["visible"] = true ;

   $zusatz_menue[1]["text"] = "administrative Massnahme";
	$zusatz_menue[1]["info"] = "Einstellungen und Vorgaben zum Betrieb von eStab.\nTechnische Vorgaben z.B. Verbindung zur Datenbank.\nAls auch Eingaben f&uuml;r die Sichtermatrix.";
   $zusatz_menue[1]["pic"]  = $conf_menue ["symbole"]."adm_aktiv.png";
   $zusatz_menue[1]["link"] = "./4fadm/admin.php";
   $zusatz_menue[1]["visible"] = true ;

// rechts
   $menue[2]["text"] = "Einsatztagebuch<BR>[S2]";
	$menue[2]["info"] = "Eintragungen ins ETB nur vom S2.\nAndrere Funktionen k&ouml;nnen nur lesen.";
   $menue[2]["pic"]  = $conf_menue ["symbole"]."etb_aktiv.png";
   $menue[2]["link"] = "./stabetb/etb.php";
   $menue[2]["visible"] = true ;

   $menue[4]["text"] = "Technisches Betriebsbuch<BR>[A/W]";
	$menue[4]["info"] = "Eintragungen durch einen Fernmelder A/W\nAndrere k&ouml;nnen nur lesen";
   $menue[4]["pic"]  = $conf_menue ["symbole"]."tbb_aktiv.png";
   $menue[4]["link"] = "./fmtbb/tbb.php";
   $menue[4]["visible"] = true ;

   $menue[6]["text"] = "Nachweisung";
	$menue[6]["info"] = "Klassische Nachweisung.";
   $menue[6]["pic"]  = $conf_menue ["symbole"]."nw.png";
   $menue[6]["link"] = "./4fach/nachwea.php?nwalle";
   $menue[6]["visible"] = true ;

   $zusatz_menue[2]["text"] = "Kurzanleitung zur eStab Installation & Nutzung";
	$zusatz_menue[2]["info"] = "Kurzbeschreibung von eStab als PDF-Dokument.";
   $zusatz_menue[2]["pic"]  = $conf_menue ["symbole"]."icon_handbuch.gif";
   $zusatz_menue[2]["link"] = "./doku/Handbuch_eStab.pdf";
   $zusatz_menue[2]["visible"] = true ;

?>
