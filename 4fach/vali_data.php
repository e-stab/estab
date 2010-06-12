<?php

class vali_data_form {

//include ("../valimatrix.inc.php");
//  include ("../config.inc.php");
//  include ("../fkt_rolle.inc.php");


  var $data ;        // Daten des Formulars
  var $validate ;    // Pruefungsergebnis

/*****************************************************************************\

  \*****************************************************************************/
  function vali_data_form ($data){
    $this->data = $data ;
    $this->reset_validate() ;
//    $this->validatethis ();
  }


  function reset_validate () {
     $this->validate ["01_medium"]   = false ;
     $this->validate ["01_datum"]   = false ;
     $this->validate ["01_zeit"]   = false ;
     $this->validate ["01_zeichen"]   = false ;
     $this->validate ["02_zeit"]   = false ;
     $this->validate ["02_zeichen"]   = false ;
     $this->validate ["03_datum"]   = false ;
     $this->validate ["03_zeit"]   = false ;
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
     Funktion: datatest

     Aufgabe: Prueft ein Datum auf kausalitaet
  \*****************************************************************************/
  function testzeit ( $data ){
    if ( strlen ($data) == 4 ) {
     $stunde = substr ($data, 0, 2);
     $minute = substr ($data, 2, 2);
     $valid = false;
     if ( ( ( $stunde >= 0 ) and ( $stunde <= 23) ) and
          ( ( $minute >= 0 ) and ( $minute <= 59) ) ) { $valid = true; }
    }
    return $valid ;
  }

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

  function testdatum  ( $data ){
    $tag   = substr ($data, 0, 2);
    $monat = substr ($data, 2, 2);
    if ( ( ( $tag   >= 0 ) and ( $tag <= 31) ) and
         ( ( $monat >= 0 ) and ( $monat <= 12) ) ) { $valid = true; }

  }


  function datatest ( $testmethode, $data ){
    /* $data enthaelt die zu pruefenden Daten */
    $valid = false;
    switch ($testmethode){
       case ("zeit"): // 4 stellig ; 1. duppel - Stunnde 00..23 - 2. duppel - Minuten  00..59
         $valid = $this->testzeit ($data);
       break ;
       case ("datum"):// 4 stellig; 1. duppel Tag 1..31 - 2. Duppel Monat 1..12
         $valid = $this->testdatum ($data);
       break ;
       case ("datumzeit")://
           if ( strlen ($data) == 19 ) {
             list ($datum, $zeit) = explode(" ",$data);
             $valid = ($this->testdbdatum  ( $datum )) and ($this->testzeit( $zeit ));
           }
       case ("text"): // 1..n Zeichen es muss Inhalt vorhanden sein
           if ( strlen ($data) > 0 ) { $valid = true; }
         break ;
       case ("kuerzel"): // 3 stellig
           if ( ( strlen ($data) > 0 ) and
                ( strlen ($data) <= 3 ) ) { $valid = true; }
         break ;
       case ("binaer"): // logischer Wert - Ist gesetzt oder nicht
           if  ( $data == true  ) { $valid = true; }
         break ;

    } // switch
    return $valid;
  }


  function checkallfields () {
    if (isset ($this->data ["01_medium"] ) )        {
      $this->validate["01_medium"] = $this->datatest ( "binaer", $this->data ["01_medium"] ) ;
    }
    if (isset ( $this->data ["01_datum"] ))         {
      $this->validate["01_datum"] = $this->datatest ( "datum", $this->data ["01_datum"] ) ;
    }
    if (isset ( $this->data ["01_zeit" ] ))         {
      $this->validate["01_zeit"]  = $this->datatest ( "zeit", $this->data ["01_zeit"] ) ;
    }
    if (isset ( $this->data ["01_zeichen"] ))       {
      $this->validate["01_zeichen"]  = $this->datatest ("kuerzel", $this->data ["01_zeichen"] ) ;
    }
    if (isset ( $this->data ["02_zeit"] ))          {
      $this->validate["02_zeit"]  = $this->datatest ( "zeit", $this->data ["02_zeit"] ) ;
    }
    if (isset ( $this->data ["02_zeichen"] ))       {
      $this->validate["02_zeichen"]  = $this->datatest ( "kuerzel", $this->data ["02_zeichen"] ) ;
    }
    if (isset ( $this->data ["03_datum"] ))         {
      $this->validate["03_datum"]  = $this->datatest ( "datum", $this->data ["03_datum"] ) ;
    }
    if (isset ( $this->data ["03_zeit"] ))          {
      $this->validate["03_zeit"]  = $this->datatest ( "zeit", $this->data ["03_zeit"] ) ;
    }
    if (isset ( $this->data ["03_zeichen"] ))       {
      $this->validate["03_zeichen"]  = $this->datatest ( "kuerzel", $this->data ["03_zeichen"] ) ;
    }

//    if (isset ( $this->data ["05_gegenstelle"] )) {  $this->validate["05_gegenstelle"]  = $this->datatest ( "zeit", $this->data ["05_gegenstelle"] ) ; }
//    if (isset ( $this->data ["06_befweg"] ))      {  $this->validate["06_befweg"]  = $this->datatest ( "zeit", $this->data ["06_befweg06_befweg"] ) ; }
//    if (isset ( $this->data ["06_befwegausw"] ))  {  $this->validate["06_befwegausw"]  = $this->datatest ( "zeit", $this->data ["06_befwegausw"] ) ; }
//    if (isset ( $this->data ["07_durchspruch"] )) {  $this->validate["07_durchspruch"]  = $this->datatest ( "zeit", $this->data ["07_durchspruch"] ) ; }
//    if (isset ( $this->data ["08_befhinweis"] ))  {  $this->validate["08_befhinweis"]  = $this->datatest ( "zeit", $this->data ["08_befhinweis"] ) ; }
//    if (isset ( $this->data ["08_befhinwausw"] )) {  $this->validate["08_befhinwausw"]  = $this->datatest ( "zeit", $this->data ["08_befhinwausw"] ) ; }

    if (isset ( $this->data ["10_anschrift"] ))     {
      $this->validate["10_anschrift"]  = $this->datatest ( "text", $this->data ["10_anschrift"] ) ;
    }
    if (isset ( $this->data ["12_inhalt"] ))        {
      $this->validate["12_inhalt"]  = $this->datatest ( "text", $this->data ["12_inhalt"] ) ;
    }
    if (isset ( $this->data ["12_abfzeit"] ))       {
      $this->validate["12_abfzeit"]  = $this->datatest ( "datumzeit", $this->data ["12_abfzeit"] ) ;
    }
    if (isset ( $this->data ["13_abseinheit"] ))    {
      $this->validate["13_abseinheit"]  = $this->datatest ( "text", $this->data ["13_abseinheit"] ) ;
    }
    if (isset ( $this->data ["14_zeichen"] ))       {
      $this->validate["14_zeichen"]  = $this->datatest ( "kuerzel", $this->data ["14_zeichen"] ) ;
    }
    if (isset ( $this->data ["14_funktion"] ))      {
      $this->validate["14_funktion"]  = $this->datatest ( "text", $this->data ["14_funktion"] ) ;
    }
    if (isset ( $this->data ["15_quitdatum"] ))     {
      $this->validate["15_quitdatum"]  = $this->datatest ( "zeit", $this->data ["15_quitdatum"] ) ;
    }
    if (isset ( $this->data ["15_quitzeichen"] ))   {
      $this->validate["15_quitzeichen"]  = $this->datatest ( "kuerzel", $this->data ["15_quitzeichen"] ) ;
    }
    if (isset ( $this->data ["17_vermerke"] ))      {
      $this->validate["17_vermerke"]  = $this->datatest ( "text", $this->data ["17_vermerke"] ) ;
    }
  }


  /*****************************************************************************\

  \*****************************************************************************/
  function checkdata (){

    include ("../config.inc.php");
    include ("../fkt_rolle.inc.php");
    $task = $this->data["task"] ;
    $zw = false;
    switch ($task) {
      case "FM-Eingang":
      case "FM-Eingang_Anhang" :
          $zw = $this->validate["01_medium"] &&
                $this->validate["01_zeichen"] &&
                $this->validate["10_anschrift"] &&
                $this->validate["12_inhalt"] &&
                $this->validate["13_abseinheit"] ;
        break ;
      case "FM-Eingang_Sichter" :
      case "FM-Eingang_Anhang_Sichter" :
          $zw = ($this->validate["01_medium"] &&
                 $this->validate["01_zeichen"] &&
                 $this->validate["10_anschrift"] &&
                 $this->validate["12_inhalt"] &&
                 $this->validate["13_abseinheit"] &&
                 $this->validate["15_quitzeichen"]);
/*
                 $this->validate["01_datum"] &&
                 $this->validate["01_zeit"] &&
                 $this->validate["12_abfzeit"] &&
                 $this->validate["15_quitdatum"] &&
*/
        break;
      case "Stab_schreiben":
          $zw =($this->validate["10_anschrift"] &&
                $this->validate["12_inhalt"] &&
                $this->validate["12_abfzeit"] &&
                $this->validate["13_abseinheit"] &&
                $this->validate["14_zeichen"] &&
                $this->validate["14_funktion"]) ;
        break ;
      case "FM-Ausgang": break ;
      case "FM-Ausgang_Sichter": break ;
      case "Stab_sichten": break ;
      case "FM-Admin": break ;
      case "SI-Admin": break ;
    }

 /*
    var_dump ($this->data); echo "<br><br><br>";
    while (list($key, $val) = each($this->data)) {
      echo "$key => $val  --- ";
      if ($this->validate [$key] ){ echo "key----WAHR<br>"; } else { echo "key----FALSCH<br>"; }
//      echo "<br>\n";
    }

    if ($zw){ echo "zw===WAHR<br>"; } else { echo "zw===FALSCH<br>"; }
*/
    return $zw;
  }  // checkdata !!!


function validatethis (){
  $this->checkallfields ();
  $res = $this->checkdata ();
  return $res;
}


} // class vali_data_form


?>
