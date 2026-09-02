<?php


    $cfg ["vbg"] ["rt"]      =  "rgb( 255, 150, 150)" ;
    $cfg ["vbg"] ["gn"]      =  "rgb( 130, 230, 130)" ;
    $cfg ["vbg"] ["bl"]      =  "rgb( 150, 150, 255)" ;
    $cfg ["vbg"] ["ge"]      =  "rgb( 255, 255, 128)" ;
    $cfg ["vbg"] ["dflt"]    =  "rgb( 255, 255, 255)" ;
	$cfg ["vbg"] ["default"] =  "rgb( 255, 255, 255)" ;

    $cfg ["lbg"] ["rt"]      =  "rgb( 255, 150, 150)" ;
    $cfg ["lbg"] ["gn"]      =  "rgb( 130, 230, 130)" ;
    $cfg ["lbg"] ["bl"]      =  "rgb( 150, 150, 255)" ;
    $cfg ["lbg"] ["ge"]      =  "rgb( 255, 255, 128)" ;
    $cfg ["lbg"] ["dflt"] =  "rgb( 255, 255, 255)" ;

    // Der Takt der Selbstaktualisierung.
    //
    // Die Listen standen auf 10 Sekunden. Jeder Takt war ein
    // vollstaendiger Seitenneuaufbau samt Datenbankverbindung,
    // Sitzungspruefung und Listenabfrage -- der groesste Rechenposten
    // der Anwendung: 1.873.467 temporaere Tabellen in 3,9 Tagen auf
    // einer Instanz ohne echten Betrieb.
    //
    // 30 Sekunden drittelt das. Der Betreiber hat das am 30.08.2026
    // entschieden: Zehn Sekunden werden nicht gebraucht.
    //
    // Seit derselben Aenderung wird ausserdem nur noch der Inhalt
    // getauscht statt die Seite neu geladen -- siehe
    // estab_list_refresh_script in 4fach/tools.php.
    $cfg ["itv"] ["status"]    =  30 ; // Status
    $cfg ["itv"] ["stabliste"] = 120 ; // sekunden
    $cfg ["itv"] ["fmdliste"]  =  30 ; // sekunden
    $cfg ["itv"] ["siliste"]   =  30 ; // sekunden
    $cfg ["itv"] ["si2liste"]  = 120 ; // sekunden  

?>
