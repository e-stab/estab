<?php
/*****************************************************************************\
   Datei: as_pic.php

   benoetigte Dateien:

   Beschreibung:

   Funktionen:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

define ("debug",false);

class vordruckasimg {

// Vollbild
  var $left   =    1 ;
  var $right  = 1200 ;
  var $top    =    1 ;
  var $bottom = 1500 ;

// Trennungslinie FM / Stab bzw Stab / Sichter
  var $fm_stab_line = 275 ; // 300 px von oben
  var $stab_sichter_line = 1100 ; // 400 px für den Sichter

// Bildbereich Formulardaten
  var $fleft ;
  var $fright ;
  var $ftop ;
  var $fbottom ;

// Liniendicke
  var $fline00 = 2 ;
  var $fline01 = 3 ;
  var $fline02 = 5 ;

// Farben
  var $color_sw ;
  var $color_rd ;

// Schrift und Schriftgrössen
  var $font ;
  var $fontsize00 = 9 ;
  var $fontsize01 = 12 ;
  var $fontsize02 = 14 ;
  var $fontsize03 = 18 ;
  var $fontsize04 = 22 ;

  var $fontsize50 = 60 ;

  var $fkt_size = 11 ;

// Das Bild
  var $image ;

  var $db_dataset ;

  /*******************************************************************************
            Klassen Konstruktor
  ********************************************************************************/
  function vordruckasimg ($data) {
  require_once ("../4fach/tools.php") ;
    $this->fleft   = $this->left   + 50 ;
    $this->fright  = $this->right  - 10 ;
    $this->ftop    = $this->top    + 10 ;
    $this->fbottom = $this->bottom - 10 ;
/*
    $this->font ["n"] = "georgia.ttf";
    $this->font ["b"] = "georgiab.ttf";
    $this->font ["i"] = "georgiai.ttf";
    $this->font ["z"] = "georgiaz.ttf";
*/

    $this->font ["n"] = "../bak/fonts/Garrison Sans.ttf";
    $this->font ["b"] = "../bak/fonts/Garrison Sans BOLD.ttf";
    $this->font ["i"] = "../bak/fonts/Garrison Sans ITALIC.ttf";
    $this->font ["z"] = "georgiaz.ttf";


    $this->db_dataset ["00_lfd"]          = $data ["00_lfd"] ;
    $this->db_dataset ["01_medium"]       = $data ["01_medium"];

    if ($data ["01_datum"] != "0000-00-00 00:00:00") {
      $arr = convdatetimeto ($data ["01_datum"]);
      $this->db_dataset ["01_datum"]        = konv_datetime_taktime (convtodatetime ($arr[datum], $arr[zeit]));
    } else { $this->db_dataset ["01_datum"] = ""; }

    $this->db_dataset ["01_zeichen"]      = $data  ["01_zeichen"];

    if ($data ["02_zeit"] != "0000-00-00 00:00:00") {
      $arr = convdatetimeto ($data ["02_zeit"]);
      $this->db_dataset ["02_zeit"]         = konv_datetime_taktime (convtodatetime ($arr[datum], $arr[zeit]));
    } else { $this->db_dataset ["02_zeit"] = ""; }

    $this->db_dataset ["02_zeichen"]      = $data ["02_zeichen"];

    if ($data ["03_datum"] != "0000-00-00 00:00:00") {
      $arr = convdatetimeto ($data ["03_datum"]);
      $this->db_dataset ["03_datum"]        = konv_datetime_taktime (convtodatetime ($arr[datum], $arr[zeit]));
    } else { $this->db_dataset ["03_datum"] = ""; }

    $this->db_dataset ["03_zeichen"]      = $data ["03_zeichen"] ;
    $this->db_dataset ["04_richtung"]     = $data ["04_richtung"] ;
    $this->db_dataset ["04_nummer"]       = $data ["04_nummer"] ;
    $this->db_dataset ["05_gegenstelle"]  = $data ["05_gegenstelle"] ;
    $this->db_dataset ["06_befweg"]       = $data ["06_befweg"] ;
    $this->db_dataset ["06_befwegausw"]   = $data ["06_befwegausw"] ;
    $this->db_dataset ["07_durchspruch"]  = $data ["07_durchspruch"] ;
    $this->db_dataset ["08_befhinweis"]   = $data ["08_befhinweis"] ;
    $this->db_dataset ["08_befhinwausw"]  = $data ["08_befhinwausw"] ;
    $this->db_dataset ["09_vorrangstufe"] = $data ["09_vorrangstufe"] ;
    $this->db_dataset ["10_anschrift"]    = $data ["10_anschrift"] ;
    $this->db_dataset ["11_gesprnotiz"]   = $data ["11_gesprnotiz"] == "t" ;
    $this->db_dataset ["12_anhang"]       = $data ["12_anhang"] ;
    $this->db_dataset ["12_inhalt"]       = $data ["12_inhalt"] ;

      $arr = convdatetimeto ($data ["12_abfzeit"]);
    $this->db_dataset ["12_abfzeit"]      = konv_datetime_taktime (convtodatetime ($arr[datum], $arr[zeit]));
    $this->db_dataset ["13_abseinheit"]   = $data ["13_abseinheit"] ;
    $this->db_dataset ["14_zeichen"]      = $data ["14_zeichen"] ;
    $this->db_dataset ["14_funktion"]     = $data ["14_funktion"] ;

      $arr = convdatetimeto ($data ["15_quitdatum"]);
    $this->db_dataset ["15_quitdatum"]    = konv_datetime_taktime (convtodatetime ($arr[datum], $arr[zeit]));
    $this->db_dataset ["15_quitzeichen"]  = $data ["15_quitzeichen"] ;
    $this->db_dataset ["16_empf"]         = $data ["16_empf"] ;
    $this->db_dataset ["17_vermerke"]     = $data ["17_vermerke"] ;
    $this->db_dataset ["x00_status"]      = $data ["x00_status"] ;
    $this->db_dataset ["x01_abschluss"]   = $data ["x01_abschluss"];
    $this->db_dataset ["x04_druck"]       = $data ["x04_druck"] == "t" ;

      $arr = convdatetimeto ($data ["x05_druck_d"]);
    $this->db_dataset ["x05_druck_d"]     = konv_datetime_taktime (convtodatetime ($arr[datum], $arr[zeit]));
    $this->db_dataset ["99_lstacc"]       = $data ["99_lstacc"];
  }


  function imagegrid($image, $w, $h, $s, $color) {
      imageline($image, 0, 0, $w-1, 0, $color);
      imageline($image, $w-1, 0, $w-1, $h-1, $color);
      imageline($image, $w-1, $h-1, 0, $h-1, $color);
      imageline($image, 0, $h-1, 0, 0, $color);
      for($iw=1; $iw<=$w/$s+1; $iw++){imageline($image, $iw*$s, 0, $iw*$s, $h, $color);}
      for($ih=1; $ih<=$h/$s+1; $ih++){imageline($image, 0, $ih*$s, $w, $ih*$s, $color);}
  }

  /*******************************************************************************
            erweiterte grafische Grundfunktionen
  ********************************************************************************/

  /*******************************************************************************
    Linie x1y1 x2y2 dicke farbe
  ********************************************************************************/
  function draw_line ( $x1, $y1, $x2, $y2, $pixel, $color){
    imagesetthickness ($this->image, $pixel );
    imageline($this->image, $x1, $y1, $x2, $y2, $color);
  }

  function draw_rb_select ($x, $y){
    $this->draw_line ( $x-15, $y+15, $x+15, $y-15, 8, $this_color_sw);
    $this->draw_line ( $x+15, $y+15, $x-15, $y-15, 6, $this_color_sw);
  }

  /*******************************************************************************
    Linie x1y1 x2y2 dicke farbe
  ********************************************************************************/
  function draw_circle_text ( $x1, $y1, $x2, $y2, $pixel, $color){
    imagesetthickness ($this->image, $pixel );
    imageline($this->image, $x1, $y1, $x2, $y2, $color);
  }

  function draw_rectagle ($x1, $y1, $x2, $y2, $pixel, $color){
    imagesetthickness ($this->image, $pixel );
    imageline($this->image, $x1, $y1, $x1, $y2, $color);
    imageline($this->image, $x1, $y2, $x2, $y2, $color);
    imageline($this->image, $x2, $y2, $x2, $y1, $color);
    imageline($this->image, $x2, $y1, $x1, $y1, $color);
  }

  function draw_radiobutton ( $x, $y, $select, $size, $text ){
//    imagesetthickness ($this->image, 7 );
    for ($i=0; $i<=5; $i++){
      imageellipse ( $this->image, $x, $y, 20+$i, 20+$i, $his->color_sw );
    }
    if ( $select ){ $this->draw_rb_select ($x, $y); }
    $this->draw_text ($x+20, $y, 0, $his->color_sw, $size, "n", "m", "l", $text);
  }

  function draw_mediumselect ($x, $y, $selectvalue){
    $aa1 = array ("Fu","Fe","Me","Fax","DFÜ");
    for ($o=0; $o<= 4; $o++){
      if ( $aa1[$o] == $selectvalue ){ $select = true; }else{ $select=false; }
      $this->draw_radiobutton ( $x+$o*55, $y, $select, $this->fontsize00, $aa1[$o] );
    }
  }

  /*****************************************************************************
      function draw_text
        $x                : Position x
        $y                : Position y
        $angle            : Ausrichtungswinkel
        $color            : Farbe
        $fontauszeichnung : n = normal
                            b = fett
                            i = kursiv
                            z =
        $posv             : vertikal o = oben
                                     m = mitte
                                     u = unten
        $posh             : horizontal l = links
                                       z = zentriert
                                       r = rechts
        $text             : Text
  ******************************************************************************/
  function draw_text ($x, $y, $angle, $color, $size, $fontaz, $posv, $posh, $text){
    $p = imagettfbbox ( $size, $angle, $this->font [$fontaz], $text );
/*
    if (debug){ print_r ($textbox); echo "<br>";
       echo "untere linke Ecke, X-Position  =".$textbox[0]."<br>";       echo "untere linke Ecke, Y-Position  =".$textbox[1]."<br>";       echo "untere rechte Ecke, X-Position =".$textbox[2]."<br>";
       echo "untere rechte Ecke, Y-Position =".$textbox[3]."<br>";       echo "obere rechte Ecke, X-Position  =".$textbox[4]."<br>";       echo "obere rechte Ecke, Y-Position  =".$textbox[5]."<br>";
       echo "obere linke Ecke, X-Position   =".$textbox[6]."<br>";       echo "obere linke Ecke, Y-Position   =".$textbox[7]."<br>";
    }
*/
/*  p[6],p[7] O---------------------O p[4],p[5]
              |                     |
    p[0],p[1] O---------------------O p[2],p[3]  */


    switch ($posh){
      case "l": //links
        //nothing to do
      break;
      case "z": // mitte
        $x -= ($p[2]-$p[0])/2 ;
      break;
      case "r": // rechts
        $x -= ($p[2]-$p[0]) ;// do nothing
      break;
      default; // nothing
    }

    switch ($posv){
      case "o": //top
        $y += ($p[1]-$p[7]) ;
      break;
      case "m": // middle
        $y += ($p[1]-$p[7])/2 ;
      break;
      case "u": // bottom
        // do nothing
      break;
      default; // nothing
    }
    imagettftext ( $this->image, $size, $angle, $x, $y, $color, $this->font [$fontaz], $text );
  }

/*******************************************************************************/
/*******************************************************************************/
/*******************************************************************************/
/*******************************************************************************/
  function gesamtrahmen (){
    $this->draw_rectagle ( $this->fleft, $this->ftop, $this->fright, $this->fbottom, $this->fline01, $this->color_sw );
    $this->draw_rectagle ( $this->fleft, $this->fm_stab_line, $this->fright, $this->stab_sichter_line, $this->fline02, $this->color_sw );

  }

  function lines (){
    $this->draw_line ( $this->fleft,   40,  900,   40, $this->fline00, $this->color_sw);
    $this->draw_line ( $this->fleft,  150,  900,  150, $this->fline00, $this->color_sw);
    $this->draw_line ( $this->fleft,  175,  $this->fright,  175, $this->fline00, $this->color_sw);
    $this->draw_line (  375, $this->ftop,  375,  225, $this->fline00, $this->color_sw);
    $this->draw_line (  900, $this->ftop,  900,  175, $this->fline00, $this->color_sw);
    $this->draw_line (  625, 40,  625,  175, $this->fline00, $this->color_sw);
    $this->draw_line ( $this->fleft,  225,  $this->fright,  225, $this->fline00, $this->color_sw);
    $this->draw_line ( $this->fleft,  350,  $this->fright,  350, $this->fline00, $this->color_sw);

    $this->draw_line (  250, 225,  250,  275, $this->fline00, $this->color_sw);
    $this->draw_line (  200, 275,  200,  350, $this->fline00, $this->color_sw);
    $this->draw_line (  350, 275,  350,  350, $this->fline00, $this->color_sw);
    $this->draw_line (  250, 350,  250,  450, $this->fline00, $this->color_sw);

    $this->draw_line ( $this->fleft,  450,  $this->fright,  450, $this->fline00, $this->color_sw);

    $this->draw_line (  850, 225,  850,  450, $this->fline00, $this->color_sw);

//$this->draw_line ( $this->fleft, 1000,  $this->fright, 1000, $this->fline02, $this->color_rd);

    $this->draw_line ( $this->fleft,  975,  $this->fright,  975, $this->fline00, $this->color_sw);
    $this->draw_line ( $this->fleft, 1025,  $this->fright, 1025, $this->fline00, $this->color_sw);

    $this->draw_line (  200,  975,  200, 1100, $this->fline00, $this->color_sw);
    $this->draw_line (  600,  1025,  600, 1100, $this->fline00, $this->color_sw);
    $this->draw_line (  850,  1025,  850, 1100, $this->fline00, $this->color_sw);

    $this->draw_line ( $this->fleft, 1175,  650, 1175, $this->fline00, $this->color_sw);

    $this->draw_line (  650,  1100,  650, $this->fbottom, $this->fline00, $this->color_sw);
  }

/*******************************************************************************/
  function fixtext (){
    // Feld 1
    $this->draw_text ( 200,   35,  0, $this->color_sw, $this->fontsize01, "b", "u", "z", "EINGANG" );
    $this->draw_text ( 200,   60,  0, $this->color_sw, $this->fontsize01, "n", "u", "z", "Aufnahmevermerk" );
    $this->draw_text ( 200,  170,  0, $this->color_sw, $this->fontsize01, "n", "u", "z", "Datum   Zeit   Kürzel" );

    $this->draw_text ( 625,   35,  0, $this->color_sw, $this->fontsize01, "b", "u", "z", "AUSGANG" );
    $this->draw_text ( 500,   60,  0, $this->color_sw, $this->fontsize01, "n", "u", "z", "Annahmevermerk" );
    $this->draw_text ( 500,  170,  0, $this->color_sw, $this->fontsize01, "n", "u", "z", "Zeit   Kürzel" );

    $this->draw_text ( 760,   60,  0, $this->color_sw, $this->fontsize01, "n", "u", "z", "Beförderungsvermerk" );
    $this->draw_text ( 760,  170,  0, $this->color_sw, $this->fontsize01, "n", "u", "z", "Datum   Zeit   Kürzel" );


    $this->draw_text (1050,   35,  0, $this->color_sw, $this->fontsize01, "n", "u", "z", "Nachweis-Nr." );

    $this->draw_text ( 75,  195,  0, $this->color_sw, $this->fontsize01, "n", "u", "l", "Rufname der Gegenstelle/" );
    $this->draw_text ( 75,  200,  0, $this->color_sw, $this->fontsize01, "n", "o", "l", "Spruchkopf" );

    $this->draw_text ( 75,  240,  0, $this->color_sw, $this->fontsize01, "n", "m", "l", "Beförderungsweg" );

    $this->draw_text ( 212, 312,  0, $this->color_sw, $this->fontsize01, "n", "m", "l", "Beförderungshinweis" );

    $this->draw_text (  75,  360,  0, $this->color_sw, $this->fontsize01, "n", "m", "l", "Vorrang" );
    $this->draw_text ( 260,  360,  0, $this->color_sw, $this->fontsize01, "n", "m", "l", "Anschrift" );
    $this->draw_text ( 860,  360,  0, $this->color_sw, $this->fontsize01, "n", "m", "l", "Gesprächsnotiz" );

    $this->draw_text (  75,  460,  0, $this->color_sw, $this->fontsize01, "n", "m", "l", "Inhalt" );

    $this->draw_text (  75,  995,  0, $this->color_sw, $this->fontsize01, "n", "u", "l", "Abfassungszeit" );

    $this->draw_text (  75, 1025,  0, $this->color_sw, $this->fontsize01, "n", "o", "l", "Absender" );

    $this->draw_text ( 300, 1075,  0, $this->color_sw, $this->fontsize01, "n", "o", "l", "Einheit/Einrichtung/Stelle" );
    $this->draw_text ( 700, 1075,  0, $this->color_sw, $this->fontsize01, "n", "o", "l", "Zeichen" );
    $this->draw_text ( 900, 1075,  0, $this->color_sw, $this->fontsize01, "n", "o", "l", "Funktion" );

    $this->draw_text (  75, 1125,  0, $this->color_sw, $this->fontsize01, "n", "u", "l", "Quittung" );
    $this->draw_text ( 200, 1170,  0, $this->color_sw, $this->fontsize01, "n", "u", "l", "Zeit  Zeichen" );

    $this->draw_text ( 655, 1125,  0, $this->color_sw, $this->fontsize01, "n", "u", "l", "Vermerk" );
  }


  function draw_textfield ($x1, $y1, $x2, $y2, $cols, $ntext){
    $text = wordwrap( $ntext,  $cols, "\n" );
    $delta_x = $x2 - $x1 ;
    $delta_y = $y2 - $y1 ;
    $fontsize = 20;
    do {
      $p = imageftbbox ( $fontsize, 0, $this->font["b"], $text, array('lineheight'=>15.0) );
      $pdelta_y = abs($p[5]-$p[1]) ;
      $fontsize -= 2;
    } while ( ($pdelta_y >= $delta_y) and ($fontsize >= 0) );
//    echo "delta X = ".$ndelta_x."<br>";
//    echo "delta Y = ".$ndelta_y."<br>";

/*
    echo "x1,y1=".$p [0].",".$p [1]."<br>";
    echo "x2,y2=".$p [2].",".$p [3]."<br>";
    echo "x3,y3=".$p [4].",".$p [5]."<br>";
    echo "x4,y4=".$p [6].",".$p [7]."<br>";
*/
    imagefttext( $this->image, $fontsize, 0, $x1, $y1, $this->color_sw, $this->font["b"],
               $text, array('lineheight'=>15.0) );
  }

  var $empfarray ;

/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function ziele (){
  include ("../fkt_rolle.inc.php");

    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        $this->empfarray [$i][$j]["checked"] = false;
//        $this->empfarray [$i][$j]["cpycol"]  = "";
//        $this->empfarray [$i][$j]["typ"]     = $empf_matrix [$i][$j]["typ"];
        $this->empfarray [$i][$j]["fkt"]     = $empf_matrix [$i][$j]["fkt"];
//        $this->empfarray [$i][$j]["rolle"]   = $empf_matrix [$i][$j]["rolle"];
      }
    }
    $empf_text  = $this->db_dataset ["16_empf"] ; // Zeile mit den Empfaengern aus der DB
      // Wandel die Textzeile mit den Empfaengern in ein ARRAY um
    $empf_array_color = explode (",",$empf_text);

    for ( $i=0; $i <= count ( $empf_array_color ); $i++ ) {
        //  die Farbe der Kopie
      list ( $fkt, $cpycol ) = explode ("_", $empf_array_color [$i]);
      if ( $fkt != "" ){
        $empf_array [$i]['fkt'] = $fkt ;
        $empf_array [$i]['cpy'] = $cpycol ;

      }
    }
    $sonstcount = 2;
    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        if (isset ($empf_array)){
          foreach ($empf_array as $empfaenger){
            if ( ( strtoupper ( $empfaenger['fkt'] ) ==  strtoupper ( $empf_matrix [$i][$j]["fkt"]) ) and
                 ( $empf_matrix [$i][$j]["fkt"] != "" ) ){
              $this->empfarray [$i][$j]["checked"] = true;
//              $this->empfarray [$i][$j]["cpycol"] = $empfaenger['cpy'];
            }
          }
        }
      }
    }
  }



  function mediumselect () {
      // Aufnamevermerk
    $this->draw_mediumselect ( 75,  80, $this->db_dataset ["01_medium"]);
      // Beförderungsweg
    $this->draw_mediumselect (900, 250, $this->db_dataset ["06_befwegausw"]);
     // Beförderungshinweis
    $this->draw_mediumselect (900, 310, $this->db_dataset ["08_befhinwausw"]);

    if ( $this->db_dataset ["07_durchspruch"] == "D"){ $select_D = true;}else{$select_D = false;}
    $this->draw_radiobutton  ( 75, 295, $select_D, $this->fontsize00, "DURCHSAGE" );

    if ( $this->db_dataset ["07_durchspruch"] == "S"){ $select_S = true;}else{$select_S = false;}
    $this->draw_radiobutton  ( 75, 330, $select_S, $this->fontsize00, "SPRUCH" );

    if ( $this->db_dataset ["11_gesprnotiz"] == "t"){ $select = true;}else{$select = false;}
    $this->draw_radiobutton  ( 1025, 400, $select, $this->fontsize00, "" );

    $this->ziele();

    $empf_farbe = explode (",",$this->db_dataset ["16_empf"]);



    include ("../fkt_rolle.inc.php");
    $x0 =  100 ;
    $y0 = 1225 ;
    $dx =  130 ;
    $dy =   50 ;
    $this->ziele();


// var_dump ($this->empfarray); echo "<br>";

    for ($y=1; $y<=5; $y++){
      for ($x=1; $x<=4; $x++){
        if ( $empf_matrix[$y][$x][fkt] != "" ) {
          if ( $this->empfarray [$y][$x]["checked"] ){ $select = true;}else{$select = false;}
          $this->draw_radiobutton  ( $x0 + ($x-1)*$dx, $y0 + ($y-1)*$dy, $select, $this->fkt_size,  $empf_matrix[$y][$x][fkt] );
        }
      }
    }
  }

  function writedata (){
      // Eingang Aufnahmevermerk Datum/Zeit
    $this->draw_text ( 200,  135,  0, $this->color_sw, $this->fontsize04, "b", "u", "z",
                       $this->db_dataset ["01_datum"]."".$this->db_dataset ["01_zeichen"] );
      // Ausgang Annahmevermerk Datum/Zeit
    $this->draw_text ( 500,  135,  0, $this->color_sw, $this->fontsize03, "b", "u", "z",
               $this->db_dataset ["02_zeit"]." ".$this->db_dataset ["02_zeichen"] );
      // Eingang Aufnahmevermerk Datum/Zeit
    $this->draw_text ( 762,  135,  0, $this->color_sw, $this->fontsize03, "b", "u", "z",
               $this->db_dataset ["03_datum"]." ".$this->db_dataset ["03_zeichen"] );
      // Nachweisung E/A Nummer
    $this->draw_text ( 1050,  135,  0, $this->color_sw, $this->fontsize50, "b", "u", "z",
               $this->db_dataset ["04_richtung"]." ".$this->db_dataset ["04_nummer"] );
      // Rufname der Gegenstelle
    $this->draw_text ( 400,  200,  0, $this->color_sw, $this->fontsize03, "b", "m", "l",
               $this->db_dataset ["05_gegenstelle"] );
      // Beförderungsweg
    $this->draw_text ( 275,  250,  0, $this->color_sw, $this->fontsize03, "b", "m", "l",
               $this->db_dataset ["06_befweg"] );
      // Beförderungshinweis
    $this->draw_text ( 400,  312,  0, $this->color_sw, $this->fontsize03, "b", "m", "l",
               $this->db_dataset ["08_befhinweis"] );
      // Vorrandstufe
    $this->draw_text ( 150,  400,  0, $this->color_sw, $this->fontsize50, "b", "m", "z",
               $this->db_dataset ["09_vorrangstufe"] );
      // Anschrift
    $this->draw_text ( 275,  375,  0, $this->color_sw, $this->fontsize04, "b", "o", "l",
               $this->db_dataset ["10_anschrift"] );

      // Gesprächjsnotiz
    $this->draw_text ( 275,  375,  0, $this->color_sw, $this->fontsize04, "b", "o", "l",
               $this->db_dataset ["10_anschrift"] );


    $this->draw_textfield ( 100, 500, 1150, 950, 80,html_entity_decode($this->db_dataset["12_inhalt"]));

      // Anfassungszeit
    $this->draw_text ( 250,  1000,  0, $this->color_sw, $this->fontsize04, "b", "m", "l",
               $this->db_dataset ["12_abfzeit"] );

     // Absende Einheit Stelle Einrichtung
    $this->draw_text ( 250,  1050,  0, $this->color_sw, $this->fontsize04, "b", "m", "l",
               $this->db_dataset ["13_abseinheit"] );
      // Kürzel
    $this->draw_text ( 725,  1050,  0, $this->color_sw, $this->fontsize04, "b", "m", "z",
               $this->db_dataset ["14_zeichen"] );
      // Funktion
    $this->draw_text ( 900,  1050,  0, $this->color_sw, $this->fontsize04, "b", "m", "z ",
               $this->db_dataset ["14_funktion"] );

      // Quittung
    $this->draw_text ( 200,  1125,  0, $this->color_sw, $this->fontsize04, "b", "m", "z ",
               $this->db_dataset ["15_quitdatum"]."  ".$this->db_dataset ["15_quitzeichen"] );

    $this->draw_textfield ( 700, 1150, 1150, 1450, 45, html_entity_decode($this->db_dataset["17_vermerke"]));

  }


/*******************************************************************************/
/*******************************************************************************/
/*******************************************************************************/
/*******************************************************************************/
  function main(){
    include ("../config.inc.php");    // Konfigurationseinstellungen und Vorgaben
    include ("../dbcfg.inc.php");     // Datenbankparameter

    $this->image = @imagecreatetruecolor($this->right - $this->left, $this->bottom - $this->top)
          or die("Cannot Initialize new GD image stream");

    $bg = imagecolorallocate($this->image, 255, 255, 255);
    imagefill($this->image, 0, 0, $bg);

    $text_color = imagecolorallocate($this->image, 233, 14, 91);
    $this->color_rd = imagecolorallocate($this->image, 255,   0,   0);
    $this->color_sw = imagecolorallocate($this->image,   0,   0,   0);

//    $this->imagegrid ($this->image, $this->right-$this->left, $this->bottom-$this->top, 50, $this->color_rd);

    $this->gesamtrahmen();
    $this->lines ();
    $this->fixtext ();
    $this->mediumselect ();
    $this->writedata ();

    $this->draw_text ( 45, 1490, 90, $this->color_sw,  5, "n", "u", "l", "(C) 2007 Hajo Landmesser eStab alle Rechte vorbehalten" );
    $this->draw_text ( 35,  240, 90, $this->color_sw, 20, "i", "u", "l", "Fm-Betriebsstelle" );
    $this->draw_text ( 35,  700, 90, $this->color_sw, 20, "i", "u", "l", "Verfasser" );
    $this->draw_text ( 35, 1350, 90, $this->color_sw, 20, "i", "u", "l", "Sichter" );



//    if (!debug) {
//      header ("Content-type: image/png");
//      imagepng($this->image);
        // Datenbankname und Nachweisung
      $filename = $conf_4f ["vordruck_dir"]."/".$conf_4f_db ["datenbank"]." ".
                  $this->db_dataset ["04_nummer"]." ".$this->db_dataset ["04_richtung"].".png" ;
      imagepng($this->image, $filename);
//    }
    imagedestroy($this->image);
  }

} // class


include  ("../dbcfg.inc.php");     // Datenbankparameter
include  ("../e_cfg.inc.php");     // Datenbankparameter
require_once  ("../db_operation.php");  // Datenbank operationen
require_once  ("../4fach/tools.php") ;

//  pre_html ("N","Erstellen der Backupvordrucke");
//  echo "<body>";

//  echo "Erstelle Grafiken für:<br>";

//    do {
//      echo "...";
set_time_limit ( 0 );

  if (isset($_GET["anz"])){
    echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">";
    echo "<HTML>";
    echo "<HEAD>";
    echo "<META HTTP-EQUIV=\"CONTENT-TYPE\" CONTENT=\"text/html; charset=iso\">";
    echo "<TITLE>Einsatz abschliessen.</TITLE>";
    echo "<META NAME=\"GENERATOR\" CONTENT=\"OpenOffice.org 2.0  (Linux)\">";
    echo "<META NAME=\"AUTHOR\" CONTENT=\"Hajo Landmesser\">";
    echo "<META NAME=\"CREATED\" CONTENT=\"20070327;15421200\">";
    echo "<META NAME=\"CHANGEDBY\" CONTENT=\"hajo\">";
    echo "<META NAME=\"CHANGED\" CONTENT=\"20080612;18052200\">";
    echo "<meta http-equiv=\"cache-control\" content=\"no-cache\">";
    echo "<meta http-equiv=\"pragma\" content=\"no-cache\">";
    echo "</HEAD>";
    echo "<BODY>";
  }


      if (isset ( $_GET["anz"] )){ $anzahl = $_GET["anz"]; } else { $anzahl = 5 ; }

      $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
      $query = "SELECT * FROM `".$conf_4f_tbl ["nachrichten"]."` where ((`x04_druck` = 'f') and (`x01_abschluss` = 't')) LIMIT $anzahl";
        if (debug) { echo "query===".$query."<br>"; }
      $result = $dbaccess->query_table ($query);
        if (debug) { echo "result==="; var_dump ($result); echo "<br>"; }
      $dbdata = $result ; //[1];

      if ( $dbdata != "" ) {
        foreach ($dbdata as $formdata){

          $vordruck = new vordruckasimg ($formdata);
          $vordruck->main();

          $dbaccess = new db_access ($conf_4f_db ["server"], $conf_4f_db ["datenbank"],$conf_4f_tbl ["benutzer"], $conf_4f_db ["user"],  $conf_4f_db ["password"]);
          $query = "UPDATE `".$conf_4f_tbl ["nachrichten"]."` SET `x04_druck` = 't' where  `00_lfd` = ".$formdata ["00_lfd"]."; ";
          if (debug) { echo "query===".$query."<br>"; }

          $res = $dbaccess->query_table_iu ($query);
          if (isset($_GET["anz"])){
//            echo "Nachweisung: ".$formdata ["04_nummer"]." ".$formdata ["04_richtung"]."<br>";
          }
        }
      }
      if (isset($_GET["anz"])){
        echo "<big><big>Habe bis zu ".$anzahl." Vordrucke als Grafik erzeugt</big></big>";
        echo "</BODY></HTML>";
      }

?>
