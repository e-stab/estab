<?php

require_once __DIR__ . "/../app/message_transport.php";
require_once __DIR__ . "/../app/message_priority.php";
require_once __DIR__ . "/../app/workflow.php";

class vali_data_form {

  var $i_data ;      // Daten des Formulars
  var $validate ;    // Pruefungsergebnis

  /*****************************************************************************\
     Konstruktor
  \*****************************************************************************/
  function __construct ($data){
    $this->vali_data_form ($data);
  }

  function vali_data_form ($data){
    $this->i_data = $data ;
    $this->reset_validate() ;
     //    $this->validatethis ();
  }

  /*****************************************************************************\
    Voreinstellung fÃ¼r das Ergebnisarray
  \*****************************************************************************/
  function reset_validate () {
     $this->validate ["01_medium"]   = false ;
     $this->validate ["01_datum"]   = false ;
//     $this->validate ["01_zeit"]   = false ;
     $this->validate ["01_zeichen"]   = false ;
     $this->validate ["02_zeit"]   = false ;
     $this->validate ["02_zeichen"]   = false ;
     $this->validate ["03_datum"]   = false ;
//     $this->validate ["03_zeit"]   = false ;
     $this->validate ["03_zeichen"]   = false ;
     //     $this->validate ["04_nummer"]   = false ;
     //     $this->validate ["04_richtung"]   = false ;
     $this->validate ["05_gegenstelle"]   = false ;
     $this->validate ["06_befweg"]   = false ;
     $this->validate ["06_befwegausw"]   = false ;
     $this->validate ["fernmeldeplan_eintrag_id"] = false;
     $this->validate ["incoming_transport_confirmed"] = false;
     $this->validate ["incoming_transport_correction_reason"] = true;
     $this->validate ["07_durchspruch"]   = false ;
     $this->validate ["08_befhinweis"]   = false ;
     $this->validate ["08_befhinwausw"]   = false ;
     $this->validate ["09_vorrangstufe"]   = false ;
     $this->validate ["10_anschrift"]   = false ;
     $this->validate ["11_rufnummer"]   = false ;
     $this->validate ["12_betreff"]   = false ;
     $this->validate ["12_inhalt"]   = false ;
     $this->validate ["12_abfzeit"]   = false ;
     $this->validate ["13_abseinheit"]   = false ;
     $this->validate ["14_zeichen"]   = false ;
     $this->validate ["14_funktion"]   = false ;
     $this->validate ["15_quitdatum"]   = false ;
     $this->validate ["15_quitzeichen"]   = false ;
     $this->validate ["16_empf"]   = false ;
     $this->validate ["17_vermerke"]   = false ;
   }



  /*****************************************************************************\
     Funktion: testzeit

     Aufgabe: Prueft ein Datum
  \*****************************************************************************/
  function testzeit ( $data ){
   $valid = false;
   if ( strlen ($data) == 4 ) {
     $stunde = substr ($data, 0, 2);
     $minute = substr ($data, 2, 2);

     if ( ( ( $stunde >= 0 ) and ( $stunde <= 23) ) and
          ( ( $minute >= 0 ) and ( $minute <= 59) ) ) { $valid = true; }
    }
    return $valid ;
  }

  /*****************************************************************************\
     Funktion: testdbdatum

     Aufgabe: Prueft ein timestamp aus der Datenbank
  \*****************************************************************************/
  function testdbdatum ($data){
//    if ( strlen ($data) == 10 ) {
     $jahr  = substr ($data, 0, 4);
     $monat = substr ($data, 5, 2);
     $tag   = substr ($data, 8, 2);
     $valid = false;
     if ( ( ( $tag   >= 0 ) and ( $tag <= 31) ) and
          ( ( $monat >= 0 ) and ( $monat <= 12) ) ) { $valid = true; }

//    }
    return $valid ;
  }


  /*****************************************************************************\
     funktion: testdatum

     testet auf ttMMJJJJ
  \*****************************************************************************/
  function testdatum  ( $data ){
    $valid = false;
    $tag   = substr ($data, 0, 2);
    $monat = substr ($data, 2, 2);
    if ( ( ( $tag   >= 0 ) and ( $tag <= 31) ) and
         ( ( $monat >= 0 ) and ( $monat <= 12) ) ) { $valid = true; }
    return $valid;
  }

  function test_vtzeit  ( $data){
    // prÃ¼fe auf vollstÃ¤ndige taktische Zeit
    // TThhmmMMMJJJJ
    $tag    = substr ($data, 0, 2);
    $stunde = substr ($data, 2, 2);
    $minute = substr ($data, 4, 2);
    $monat  = substr ($data, 6, 3);
    $jahr   = substr ($data, 0, 4);


  }

  /*****************************************************************************\
     funktion: datatest

  \*****************************************************************************/
  function datatest ( $testmethode, $data ){
    /* $data enthaelt die zu pruefenden Daten */
    $valid ["l_data"]= false;
    $valid ["data"] = "";
    switch ($testmethode){

       case ("zeit"): // 4 stellig ; 1. duppel - Stunnde 00..23 - 2. duppel - Minuten  00..59
         $valid = $this->testzeit ($data);
       break ;

       case ("datum"):// 4 stellig; 1. duppel Tag 1..31 - 2. Duppel Monat 1..12
         $valid = $this->testdatum ($data);
       break ;
       case ("datumzeit")://

         $valid = conv_time_datetime ($data);

       break;
       case ("text"): // 1..n Zeichen es muss Inhalt vorhanden sein
           if ( strlen ($data) > 0 ) { $valid["l_data"] = true; }
         break ;
       case ("kuerzel"): // 1 bis 6 Zeichen, wie das Benutzerkuerzel
           if ( ( strlen ($data) > 0 ) and
                ( strlen ($data) <= 6 ) ) { $valid["l_data"] = true; }
         break ;
       case ("binaer"): // logischer Wert - Ist gesetzt oder nicht
           if  ( $data == true  ) { $valid["l_data"] = true; }
         break ;

    } // switch
    return $valid;
  }

  /*****************************************************************************\
     funktion: checkallfields
  \*****************************************************************************/
  function checkallfields () {
    if (isset ($this->i_data ["01_medium"] ) )        {
      $canonicalMedium = estab_message_medium_storage_value (
        $this->i_data ["01_medium"]
      );
      $this->validate["01_medium"] = $canonicalMedium !== null;
      if ($canonicalMedium !== null) {
        $this->i_data ["01_medium"] = $canonicalMedium;
      }
    }

    if (isset ( $this->i_data ["01_datum"] ))         {
      $result = $this->datatest ( "datumzeit", $this->i_data ["01_datum"] ) ;
      if ( $result ["l_data"] ) { $this->i_data ["01_datum"] = $result ["data"] ; }
      $this->validate["01_datum"] = $result ["l_data"] ;
    }

    if (isset ( $this->i_data ["01_zeichen"] ))       {
      $result = $this->datatest ("kuerzel", $this->i_data ["01_zeichen"] ) ;
      $this->validate["01_zeichen"] = $result ["l_data"] ;
    }
    if (isset ( $this->i_data ["02_zeit"] ))          {
      $result = $this->datatest ( "datumzeit", $this->i_data ["02_zeit"] ) ;
      if ( $result ["l_data"] ) { $this->i_data ["02_zeit"] = $result ["data"] ; }
      $this->validate["02_zeit"] = $result ["l_data"] ;
    }
    if (isset ( $this->i_data ["02_zeichen"] ))       {
      $result = $this->datatest ( "kuerzel", $this->i_data ["02_zeichen"] ) ;
      $this->validate["02_zeichen"] = $result ["l_data"] ;
    }
    if (isset ( $this->i_data ["03_datum"] ))         {
      $result = $this->datatest ( "datumzeit", $this->i_data ["03_datum"] ) ;
      if ( $result ["l_data"] ) { $this->i_data ["03_datum"] = $result ["data"] ; }
      $this->validate["03_datum"] = $result ["l_data"] ;
    }
    if (isset ( $this->i_data ["03_zeichen"] ))       {
      $result = $this->datatest ( "kuerzel", $this->i_data ["03_zeichen"] ) ;
      $this->validate["03_zeichen"]  =  $result ["l_data"] ;
    }

    if (isset ($this->i_data ["05_gegenstelle"])) {
      $value = $this->i_data ["05_gegenstelle"];
      $valueLength = is_string ($value)
        ? (
          function_exists ("mb_strlen")
            ? mb_strlen ($value, "UTF-8")
            : strlen ($value)
        )
        : PHP_INT_MAX;
      $this->validate ["05_gegenstelle"] =
        is_string ($value)
        && strlen (trim ($value)) > 0
        && $valueLength <= 128
        && preg_match ('//u', $value) === 1
        && preg_match ('/[\p{C}]/u', $value) !== 1;
    }
    if (isset ($this->i_data ["06_befweg"])) {
      $value = $this->i_data ["06_befweg"];
      $this->validate ["06_befweg"] =
        is_string ($value)
        && strlen ($value) <= 128
        && preg_match ('//u', $value) === 1
        && preg_match ('/[\p{C}]/u', $value) !== 1;
    }
    if (array_key_exists ("06_befwegausw", $this->i_data)) {
      // Feld 7 states a wish, so leaving it empty stays valid. An invented
      // medium never reaches the SET column or the re-rendered form.
      $desiredMedium = $this->i_data ["06_befwegausw"];
      $desiredMediumValue = estab_message_medium_storage_value (
        $desiredMedium
      );
      $this->validate ["06_befwegausw"] =
        $desiredMediumValue !== null
        || (is_string ($desiredMedium) && trim ($desiredMedium) === "");
      $this->i_data ["06_befwegausw"] = $desiredMediumValue ?? "";
    }
    if (isset ($this->i_data ["fernmeldeplan_eintrag_id"])) {
      $routeId = $this->i_data ["fernmeldeplan_eintrag_id"];
      $this->validate ["fernmeldeplan_eintrag_id"] =
        (is_int ($routeId) && $routeId > 0)
        || (
          is_string ($routeId)
          && preg_match ('/\A[1-9][0-9]*\z/D', $routeId) === 1
        );
    }
    if (array_key_exists ("incoming_transport_confirmed", $this->i_data)) {
      $this->validate ["incoming_transport_confirmed"] =
        is_string ($this->i_data ["incoming_transport_confirmed"])
        && hash_equals ("1", $this->i_data ["incoming_transport_confirmed"]);
    }
    if (
      array_key_exists (
        "incoming_transport_correction_reason",
        $this->i_data
      )
    ) {
      $reason = $this->i_data ["incoming_transport_correction_reason"];
      $reasonLength = is_string ($reason)
        ? (
          function_exists ("mb_strlen")
            ? mb_strlen (trim ($reason), "UTF-8")
            : strlen (trim ($reason))
        )
        : PHP_INT_MAX;
      $reasonWithoutAllowedWhitespace = is_string ($reason)
        ? str_replace (array ("\t", "\r", "\n"), "", $reason)
        : "";
      $this->validate ["incoming_transport_correction_reason"] =
        is_string ($reason)
        && preg_match ('//u', $reason) === 1
        && $reasonLength <= 500
        && preg_match ('/\p{C}/u', $reasonWithoutAllowedWhitespace) !== 1;
    }
//    if (isset ( $this->i_data ["07_durchspruch"] )) {  $this->validate["07_durchspruch"]  = $this->i_datatest ( "zeit", $this->i_data ["07_durchspruch"] ) ; }
//    if (isset ( $this->i_data ["08_befhinweis"] ))  {  $this->validate["08_befhinweis"]  = $this->i_datatest ( "zeit", $this->i_data ["08_befhinweis"] ) ; }
//    if (isset ( $this->i_data ["08_befhinwausw"] )) {  $this->validate["08_befhinwausw"]  = $this->i_datatest ( "zeit", $this->i_data ["08_befhinwausw"] ) ; }

    if (array_key_exists ("09_vorrangstufe", $this->i_data)) {
      $priority = estab_message_priority_storage_value (
        $this->i_data ["09_vorrangstufe"]
      );
      $this->validate ["09_vorrangstufe"] = $priority !== null;
      if ($priority !== null) {
        $this->i_data ["09_vorrangstufe"] = $priority;
      }
    }

    if (isset ( $this->i_data ["10_anschrift"] ))     {
      $result =  $this->datatest ( "text", $this->i_data ["10_anschrift"] ) ;
      $this->validate["10_anschrift"]  = $result ["l_data"];
    }
    if (array_key_exists ("11_rufnummer", $this->i_data)) {
      $phoneNumber = estab_message_single_line_value (
        $this->i_data ["11_rufnummer"],
        128,
        true
      );
      $this->validate ["11_rufnummer"] = $phoneNumber !== null;
      // Never reflect malformed scalar/UTF-8 input into the form.
      $this->i_data ["11_rufnummer"] = $phoneNumber ?? "";
    }
    if (array_key_exists ("12_betreff", $this->i_data)) {
      $subject = estab_message_single_line_value (
        $this->i_data ["12_betreff"],
        255,
        false
      );
      $this->validate ["12_betreff"] = $subject !== null;
      // Keep the invalid marker while giving the renderer a safe scalar.
      $this->i_data ["12_betreff"] = $subject ?? "";
    }
	    if (isset ( $this->i_data ["12_inhalt"] ))        {
       $result = $this->datatest ( "text", $this->i_data ["12_inhalt"] ) ;
      $this->validate["12_inhalt"]  =  $result ["l_data"];
    }
    if (isset ( $this->i_data ["12_abfzeit"] ))       {

      $result = $this->datatest ( "datumzeit", $this->i_data ["12_abfzeit"] ) ;
      if ( $result ["l_data"] ) { $this->i_data ["12_abfzeit"] = $result ["data"] ; }
      $this->validate["12_abfzeit"] = $result ["l_data"] ;
    }
    if (isset ( $this->i_data ["13_abseinheit"] ))    {
      $value = $this->i_data ["13_abseinheit"];
      $valueLength = is_string ($value)
        ? (
          function_exists ("mb_strlen")
            ? mb_strlen ($value, "UTF-8")
            : strlen ($value)
        )
        : PHP_INT_MAX;
      $this->validate["13_abseinheit"] =
        is_string ($value)
        && strlen (trim ($value)) > 0
        && $valueLength <= 128
        && preg_match ('//u', $value) === 1
        && preg_match ('/[\p{C}]/u', $value) !== 1;
    }
    if (isset ( $this->i_data ["14_zeichen"] ))       {
      $result = $this->datatest ( "kuerzel", $this->i_data ["14_zeichen"] ) ;
      $this->validate["14_zeichen"]  = $result ["l_data"];
    }
    if (isset ( $this->i_data ["14_funktion"] ))      {
      $result =  $this->datatest ( "text", $this->i_data ["14_funktion"] ) ;
      $this->validate["14_funktion"]  = $result ["l_data"];
    }
    if (isset ( $this->i_data ["15_quitdatum"] ))     {
      $result = $this->datatest ( "datumzeit", $this->i_data ["15_quitdatum"] ) ;
      if ( $result ["l_data"] ) { $this->i_data ["15_quitdatum"] = $result ["data"] ; }
      $this->validate["15_quitdatum"] = $result ["l_data"] ;
    }
    if (isset ( $this->i_data ["15_quitzeichen"] ))   {
      $result = $this->datatest ( "kuerzel", $this->i_data ["15_quitzeichen"] ) ;
      $this->validate["15_quitzeichen"]  =  $result ["l_data"] ;
    }
    if (array_key_exists ("16_empf", $this->i_data)) {
      // Feld 19: Die rote Lage-/Dokumentationsdurchschrift setzt der Server
      // bei jedem Eingang selbst. Als benannter Empfaenger zaehlt daher nur
      // eine blaue Durchschrift, also ein Bearbeiter.
      $this->validate ["16_empf"] =
        is_string ($this->i_data ["16_empf"])
        && estab_workflow_distribution_has_processor (
          $this->i_data ["16_empf"]
        );
    }
    if (isset ( $this->i_data ["17_vermerke"] ))      {
      $this->validate["17_vermerke"]  = $this->datatest ( "text", $this->i_data ["17_vermerke"] ) ;
    }

  }


  /*****************************************************************************\

  \*****************************************************************************/
  function checkdata (){

    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");
    $task = $this->i_data["task"] ;
    $zw = false;
    switch ($task) {
      case "FM-Eingang":
      case "FM-Eingang_Anhang" :
          $zw = $this->validate["01_medium"] &&
                $this->validate["01_datum"] &&
                $this->validate["01_zeichen"] &&
                $this->validate["05_gegenstelle"] &&
	                $this->validate["09_vorrangstufe"] &&
	                $this->validate["10_anschrift"] &&
                $this->validate["11_rufnummer"] &&
                $this->validate["12_betreff"] &&
	                $this->validate["12_inhalt"] &&
                $this->validate["12_abfzeit"] ;

        break ;
      case "Stab_schreiben":
      case "Stab_korrigieren":
	          $zw =($this->validate["09_vorrangstufe"] &&
	                $this->validate["10_anschrift"] &&
                $this->validate["11_rufnummer"] &&
                $this->validate["12_betreff"] &&
	                $this->validate["12_inhalt"] &&
                $this->validate["12_abfzeit"] &&
                $this->validate["13_abseinheit"] &&
                $this->validate["14_zeichen"] &&
                $this->validate["14_funktion"]) ;

        break ;
      case "Stab_gesprnoti":
          $zw =($this->validate["01_medium"] &&
	                $this->validate["01_datum"] &&
	                $this->validate["10_anschrift"] &&
                $this->validate["11_rufnummer"] &&
                $this->validate["12_betreff"] &&
	                $this->validate["12_inhalt"] &&
                $this->validate["12_abfzeit"] &&
                $this->validate["13_abseinheit"] &&
                $this->validate["14_zeichen"] &&
                $this->validate["14_funktion"] ) ;

        break;
      case "FM-Ausgang":
          $zw =($this->validate["03_datum"] &&
                $this->validate["03_zeichen"]);
        break ;
      case "LdF-Eingang":
          $zw = ($this->validate["01_medium"] &&
                 $this->validate["incoming_transport_confirmed"] &&
                 $this->validate[
                   "incoming_transport_correction_reason"
                 ] &&
                 $this->validate["02_zeit"] &&
                 $this->validate["02_zeichen"] &&
                 $this->validate["13_abseinheit"]);
        break ;
      case "LdF-Ausgang":
          $zw = ($this->validate["02_zeit"] &&
                 $this->validate["02_zeichen"] &&
                 $this->validate["05_gegenstelle"] &&
                 $this->validate["fernmeldeplan_eintrag_id"]);
        break ;
      case "Stab_sichten":
         $zw = ($this->validate["15_quitzeichen"] &&
                $this->validate["15_quitdatum"] );
         if (($this->i_data ["04_richtung"] ?? "") === "E") {
           // Die Sichtung schliesst den Eingang ab. Ohne benannten
           // Bearbeiter im Verteiler erreicht die Nachricht danach niemanden
           // mehr, deshalb ist Feld 19 hier Pflicht.
           $zw = $zw && $this->validate ["16_empf"];
         }
      break ;
      case "FM-Admin": break ;
      case "SI-Admin": break ;

    }

//    if ($zw){ echo "zw===WAHR<br>"; } else { echo "zw===FALSCH<br>"; }

    return $zw;
  }  // checkdata !!!


  function validatethis (){
    $this->checkallfields ();
    $res = $this->checkdata ();
    return $res;
  }


} // class vali_data_form


?>
