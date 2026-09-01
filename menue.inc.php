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
		9
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

	$menue[2]["text"] = "Fernmeldeplan";
	$menue[2]["info"] = "Fb Fü 76 und Fb Fü 77 für den aktiven Einsatz: eigene Kommunikationsmittel, Erreichbarkeiten, Gegenstellen und Nebenstellen. Der S6 führt ihn; Rechte folgen dem festgelegten Berechtigungsmodus.";
   $menue[2]["pic"]  = $conf_menue ["symbole"]."iuk_hs80.png";
   $menue[2]["link"] = "./4fach/fuehrungsstelle.php";
   $menue[2]["navigation_key"] = "command-post";
   $menue[2]["access"] = "application";
   $menue[2]["visible"] = true ;

	$menue[3]["text"] = "Melderaufträge";
	$menue[3]["info"] = "Kurier- und Melderläufe des aktiven Einsatzes: beauftragen, übernehmen, Übergabe und Rückkehr nachweisen. Der LdF beauftragt, der Fernmelder weist nach.";
   $menue[3]["pic"]  = $conf_menue ["symbole"]."taktische-zeichen/Melder.svg";
   $menue[3]["link"] = "./4fach/melderauftraege.php";
   $menue[3]["navigation_key"] = "messenger-jobs";
   $menue[3]["access"] = "application";
   $menue[3]["visible"] = true ;

	$menue[4]["text"] = "Meldungsübersicht";
	$menue[4]["info"] = "Für S2 als Lage- und Dokumentationsfunktion: Meldungen des aktiven Einsatzes sicher suchen, filtern und öffnen.";
   $menue[4]["pic"]  = $conf_menue ["symbole"]."all_msg.png";
   $menue[4]["link"] = "./4fueltg/ue_ltg.php";
   $menue[4]["navigation_key"] = "message-overview";
   $menue[4]["access"] = "application";
   $menue[4]["visible"] = true ;

	$menue[5]["text"] = "Vordrucke";
	$menue[5]["info"] = "Gesch&uuml;tzte Liste der im laufenden Einsatz erzeugten PDF-Vordrucke. Eine Anmeldung am Nachrichtenvordruck ist erforderlich.";
   $menue[5]["pic"]  = "./4fach/design/mr/folder_global.gif";
   $menue[5]["link"] = "./4fach/vordrucke.php";
   $menue[5]["navigation_key"] = "forms";
   $menue[5]["access"] = "application";
   $menue[5]["visible"] = true ;

   $zusatz_menue[1]["text"] = "Administration";
	$zusatz_menue[1]["info"] = "Separater technischer Zugang für Einstellungen, Datenbankstatus und Empfängermatrix.";
   $zusatz_menue[1]["pic"]  = $conf_menue ["symbole"]."adm_aktiv.png";
   $zusatz_menue[1]["link"] = "./4fadm/admin.php";
   $zusatz_menue[1]["navigation_key"] = "administration";
   $zusatz_menue[1]["access"] = "administration";
   $zusatz_menue[1]["visible"] = true ;

   $menue[6]["text"] = "Einsatztagebuch<BR>(ETB)";
	$menue[6]["info"] = "ETB-Einträge dürfen wirksame Funktionen S2 oder ETB schreiben. Andere berechtigte Funktionen können lesen.";
   $menue[6]["pic"]  = $conf_menue ["symbole"]."etb_aktiv.png";
   $menue[6]["link"] = "./stabetb/etb.php";
   $menue[6]["navigation_key"] = "incident-log";
   $menue[6]["access"] = "application";
   $menue[6]["visible"] = true ;

   $menue[7]["text"] = "Technisches Betriebsbuch<BR>(TBB)";
	$menue[7]["info"] = "Eintragungen sind für Fernmelder möglich. Andere Funktionen können lesen.";
   $menue[7]["pic"]  = $conf_menue ["symbole"]."tbb_aktiv.png";
   $menue[7]["link"] = "./fmtbb/tbb.php";
   $menue[7]["navigation_key"] = "technical-log";
   $menue[7]["access"] = "application";
   $menue[7]["visible"] = true ;

   $menue[8]["text"] = "Nachweisung";
	$menue[8]["info"] = "Klassische Nachweisung.";
   $menue[8]["pic"]  = $conf_menue ["symbole"]."nw.png";
   $menue[8]["link"] = "./4fach/nachwea.php?nwalle";
   $menue[8]["navigation_key"] = "tracking";
   $menue[8]["access"] = "application";
   $menue[8]["visible"] = true ;

   $menue[9]["text"] = "BOS-Info";
	$menue[9]["info"] = "Informationen rund um die Stabsarbeit.";
   $menue[9]["pic"]  = $conf_menue ["symbole"]."merke32.gif";
   $menue[9]["link"] = "./stabinfo/index.php";
   $menue[9]["navigation_key"] = "bos-info";
   $menue[9]["access"] = "public";
   $menue[9]["visible"] = true ;

   $zusatz_menue[2]["text"] = "Handbuch";
	$zusatz_menue[2]["info"] = "Aktuelles, durchsuchbares Web-Handbuch für Bedienung, Rollen, Einsatzablauf und Administration.";
   $zusatz_menue[2]["pic"]  = $conf_menue ["symbole"]."icon_handbuch.gif";
   $zusatz_menue[2]["link"] = "./handbuch/";
   $zusatz_menue[2]["navigation_key"] = "handbook";
   $zusatz_menue[2]["access"] = "public";
   $zusatz_menue[2]["visible"] = true ;

?>
