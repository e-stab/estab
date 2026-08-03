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


    $conf_menue["einrichtung"] = "eStab";
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

// Kanonische Bereichsreihenfolge wie in app/navigation.php
   $menue[1]["text"] = "Nachrichtenvordruck";
		$menue[1]["info"] = "Nachrichtenvordrucke des aktiven Einsatzes bearbeiten.\nIm Modus Streng folgen die Rechte der ausgew&auml;hlten Dienstbesetzung.\nIm Modus Locker gelten Prim&auml;rfunktion und ausdr&uuml;cklich vergebene Zusatzfunktionen.";
   $menue[1]["pic"]  = $conf_menue ["symbole"]."4fach_aktiv.png";
   $menue[1]["link"] = "./4fach/index.php";
   $menue[1]["navigation_key"] = "messages";
   $menue[1]["access"] = "application";
	$menue[1]["visible"] = true ;  

	$menue[2]["text"] = "Führungsstellenbetrieb";
	$menue[2]["info"] = "S6-Fernmeldepläne und Melderläufe für den aktiven Einsatz; Rechte folgen dem dort festgelegten Berechtigungsmodus.";
   $menue[2]["pic"]  = $conf_menue ["symbole"]."iuk_hs80.png";
   $menue[2]["link"] = "./4fach/fuehrungsstelle.php";
   $menue[2]["navigation_key"] = "command-post";
   $menue[2]["access"] = "application";
   $menue[2]["visible"] = true ;

	$menue[3]["text"] = "Meldungsübersicht";
	$menue[3]["info"] = "Für S2 als Lage- und Dokumentationsfunktion: Meldungen des aktiven Einsatzes sicher suchen, filtern und öffnen.";
   $menue[3]["pic"]  = $conf_menue ["symbole"]."all_msg.png";
   $menue[3]["link"] = "./4fueltg/ue_ltg.php";
   $menue[3]["navigation_key"] = "message-overview";
   $menue[3]["access"] = "application";
   $menue[3]["visible"] = true ;

	$menue[4]["text"] = "Vordrucke";
	$menue[4]["info"] = "Gesch&uuml;tzte Liste der im laufenden Einsatz erzeugten PDF-Vordrucke. Eine Anmeldung am Nachrichtenvordruck ist erforderlich.";
   $menue[4]["pic"]  = "./4fach/design/mr/folder_global.gif";
   $menue[4]["link"] = "./4fach/vordrucke.php";
   $menue[4]["navigation_key"] = "forms";
   $menue[4]["access"] = "application";
   $menue[4]["visible"] = true ;

   $zusatz_menue[1]["text"] = "Administration";
	$zusatz_menue[1]["info"] = "Separater technischer Zugang für Einstellungen, Datenbankstatus und Empfängermatrix.";
   $zusatz_menue[1]["pic"]  = $conf_menue ["symbole"]."adm_aktiv.png";
   $zusatz_menue[1]["link"] = "./4fadm/admin.php";
   $zusatz_menue[1]["navigation_key"] = "administration";
   $zusatz_menue[1]["access"] = "administration";
   $zusatz_menue[1]["visible"] = true ;

   $menue[5]["text"] = "Einsatztagebuch<BR>(ETB)";
	$menue[5]["info"] = "ETB-Einträge dürfen wirksame Funktionen S2 oder ETB schreiben. Andere berechtigte Funktionen können lesen.";
   $menue[5]["pic"]  = $conf_menue ["symbole"]."etb_aktiv.png";
   $menue[5]["link"] = "./stabetb/etb.php";
   $menue[5]["navigation_key"] = "incident-log";
   $menue[5]["access"] = "application";
   $menue[5]["visible"] = true ;

   $menue[6]["text"] = "Technisches Betriebsbuch<BR>(TBB)";
	$menue[6]["info"] = "Eintragungen sind für Fernmelder möglich. Andere Funktionen können lesen.";
   $menue[6]["pic"]  = $conf_menue ["symbole"]."tbb_aktiv.png";
   $menue[6]["link"] = "./fmtbb/tbb.php";
   $menue[6]["navigation_key"] = "technical-log";
   $menue[6]["access"] = "application";
   $menue[6]["visible"] = true ;

   $menue[7]["text"] = "Nachweisung";
	$menue[7]["info"] = "Klassische Nachweisung.";
   $menue[7]["pic"]  = $conf_menue ["symbole"]."nw.png";
   $menue[7]["link"] = "./4fach/nachwea.php?nwalle";
   $menue[7]["navigation_key"] = "tracking";
   $menue[7]["access"] = "application";
   $menue[7]["visible"] = true ;

   $menue[8]["text"] = "BOS-Info";
	$menue[8]["info"] = "Informationen rund um die Stabsarbeit.";
   $menue[8]["pic"]  = $conf_menue ["symbole"]."merke32.gif";
   $menue[8]["link"] = "./stabinfo/index.php";
   $menue[8]["navigation_key"] = "bos-info";
   $menue[8]["access"] = "public";
   $menue[8]["visible"] = true ;

   $zusatz_menue[2]["text"] = "Handbuch";
	$zusatz_menue[2]["info"] = "Aktuelles, durchsuchbares Web-Handbuch für Bedienung, Rollen, Einsatzablauf und Administration.";
   $zusatz_menue[2]["pic"]  = $conf_menue ["symbole"]."icon_handbuch.gif";
   $zusatz_menue[2]["link"] = "./handbuch/";
   $zusatz_menue[2]["navigation_key"] = "handbook";
   $zusatz_menue[2]["access"] = "public";
   $zusatz_menue[2]["visible"] = true ;

?>
