<?php

class vali_data_form {

  var $i_data ;      // Daten des Formulars
  var $validate ;    // Pruefungsergebnis

  /*****************************************************************************\
     Konstruktor
  \*****************************************************************************/
  function vali_data_form ($data){
    $this->i_data = $data ;
    $this->reset_validate() ;
     //    $this->validatethis ();
  }

  /*****************************************************************************\
    Voreinstellung für das Ergebnisarray
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
     $this->validate ["07_durchspruch"]   = false ;
     $this->validate ["08_befhinweis"]   = false ;
     $this->validate ["08_befhinwausw"]   = false ;
     $this->validate ["10_anschrift"]   = false ;
     $this->validate ["12_inhalt"]   = false ;
     $this->validate ["12_abfzeit"]   = false ;
     $this->validate ["13_abseinheit"]   = false ;
     $this->validate ["14_zeichen"]   = false ;
     $this->validate ["14_funktion"]   = false ;
     $this->validate ["15_quitdatum"]   = false ;
     $this->validate ["15_quitzeichen"]   = false ;
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
    // prüfe auf vollständige taktische Zeit
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
       case ("kuerzel"): // 3 stellig
           if ( ( strlen ($data) > 0 ) and
                ( strlen ($data) <= 3 ) ) { $valid["l_data"] = true; }
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
      $result = $this->datatest ( "text", $this->i_data ["01_medium"] ) ;
      $this->validate["01_medium"] = $result ["l_data"] ;
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

//    if (isset ( $this->i_data ["05_gegenstelle"] )) {  $this->validate["05_gegenstelle"]  = $this->i_datatest ( "zeit", $this->i_data ["05_gegenstelle"] ) ; }
//    if (isset ( $this->i_data ["06_befweg"] ))      {  $this->validate["06_befweg"]  = $this->i_datatest ( "zeit", $this->i_data ["06_befweg06_befweg"] ) ; }
//    if (isset ( $this->i_data ["06_befwegausw"] ))  {  $this->validate["06_befwegausw"]  = $this->i_datatest ( "zeit", $this->i_data ["06_befwegausw"] ) ; }
//    if (isset ( $this->i_data ["07_durchspruch"] )) {  $this->validate["07_durchspruch"]  = $this->i_datatest ( "zeit", $this->i_data ["07_durchspruch"] ) ; }
//    if (isset ( $this->i_data ["08_befhinweis"] ))  {  $this->validate["08_befhinweis"]  = $this->i_datatest ( "zeit", $this->i_data ["08_befhinweis"] ) ; }
//    if (isset ( $this->i_data ["08_befhinwausw"] )) {  $this->validate["08_befhinwausw"]  = $this->i_datatest ( "zeit", $this->i_data ["08_befhinwausw"] ) ; }

    if (isset ( $this->i_data ["10_anschrift"] ))     {
      $result =  $this->datatest ( "text", $this->i_data ["10_anschrift"] ) ;
      $this->validate["10_anschrift"]  = $result ["l_data"];
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
      $result = $this->datatest ( "text", $this->i_data ["13_abseinheit"] ) ;
      $this->validate["13_abseinheit"]  = $result ["l_data"];
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
                $this->validate["10_anschrift"] &&
                $this->validate["12_inhalt"] &&
                $this->validate["12_abfzeit"] &&
                $this->validate["13_abseinheit"] ;

        break ;
      case "FM-Eingang_Sichter" :
      case "FM-Eingang_Anhang_Sichter" :
         $zw = ($this->validate["01_medium"] &&
                $this->validate["01_datum"] &&
                $this->validate["01_zeichen"] &&
                $this->validate["10_anschrift"] &&
                $this->validate["12_inhalt"] &&
                $this->validate["12_abfzeit"] &&
                $this->validate["13_abseinheit"] &&
                $this->validate["15_quitzeichen"] &&
                $this->validate["15_quitdatum"] );

        break;
      case "Stab_schreiben":
          $zw =($this->validate["10_anschrift"] &&
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
                $this->validate["12_inhalt"] &&
                $this->validate["12_abfzeit"] &&
                $this->validate["13_abseinheit"] &&
                $this->validate["14_zeichen"] &&
                $this->validate["14_funktion"] &&
                $this->validate["15_quitzeichen"] &&
                $this->validate["15_quitdatum"] ) ;

        break;
      case "FM-Ausgang":
          $zw =($this->validate["03_datum"] &&
                $this->validate["03_zeichen"]);
        break ;
      case "FM-Ausgang_Sichter":
          $zw =($this->validate["03_datum"] &&
                $this->validate["03_zeichen"] &&
                $this->validate["15_quitzeichen"] &&
                $this->validate["15_quitdatum"]);
        break ;
      case "Stab_sichten":
         $zw = ($this->validate["15_quitzeichen"] &&
                $this->validate["15_quitdatum"] );
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
