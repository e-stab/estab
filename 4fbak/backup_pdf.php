<?php
/*****************************************************************************\
   Datei: as_pdf.php

   benoetigte Dateien:

   Beschreibung:

   Funktionen:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

//define ("debug",false);
if (!defined('FPDF_FONTPATH')) {
  define('FPDF_FONTPATH', __DIR__ . "/fpdf/font/");
}

require_once __DIR__ . "/fpdf.php";
require_once __DIR__ . "/../app/message_repository.php";
require_once __DIR__ . "/../app/generated_form.php";
require_once __DIR__ . "/../app/message_priority.php";
// require_once ("./fpdf/ellipse/ellipse.php");

/** Encode UTF-8 application text for FPDF's built-in Windows-1252 fonts. */
function estab_fpdf_text ($text) {
  return mb_convert_encoding ((string) $text, "Windows-1252", "UTF-8");
}

/** Format one database timestamp exactly like the historic message form. */
function estab_message_form_tactical_time ($value) {
  static $months = array (
    "01" => "jan",
    "02" => "feb",
    "03" => "mar",
    "04" => "apr",
    "05" => "mai",
    "06" => "jun",
    "07" => "jul",
    "08" => "aug",
    "09" => "sep",
    "10" => "oct",
    "11" => "nov",
    "12" => "dec"
  );
  return estab_datetime_to_tactical ($value, $months);
}


 /*****
 *  - Example CIX88 -
 *  Legende zu den benutzen Konstanten:
 *
 *  TP = relativer Pfad zu einer Bilddatei
 *  CL_PHP = absoluter Pfad zu einer PHP-Klasse oder extra Modul
 *  CL_TTF = absoluter Pfad zu einer TTF-Datei
 *  CL_FPDF = absoluter Pfad zu einer FPDF-Klasse oder extra Modul
 *  CL_IMG = absoluter Pfad zu einer Bilddatei
 *  CL_AUDIO = absoluter Pfad zu einer Audiodatei
 *
 *  Die hier benutzten Konstanten beziehen sich nur auf diese Beispiele.
 *  ! Der Pfad muss natürlich auf deine Gegebenheiten angepasst werden !
*/

//error_reporting(E_ALL);

class PDF_Rotate extends FPDF {
    var $angle=0;

    function Rotate($angle, $x=-1, $y=-1) {
        if ($x==-1) $x=$this->x;
        if ($y==-1) $y=$this->y;
        if ($this->angle != 0) $this->_out('Q');
        $this->angle = $angle;
        if ($angle != 0) {
            $angle*=M_PI/180;
            $c=cos($angle);
            $s=sin($angle);
            $cx=$x*$this->k;
            $cy=($this->h-$y)*$this->k;
        $this->_out(sprintf('q %.5f %.5f %.5f %.5f %.2f %.2f cm 1 0 0 1 %.2f %.2f cm',$c,$s,-$s,$c,$cx,$cy,-$cx,-$cy));
        }
    }
    function _endpage() {
        if($this->angle!=0) {
        $this->angle=0;
        $this->_out('Q');
        }
        parent::_endpage();
    }
}


class PDF extends PDF_Rotate {

    // um Text zu drehen
    function RotatedText($x, $y, $txt, $angle) {
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $txt);
        $this->Rotate(0);
    }
}


class PDF_Ellipse extends PDF
{
function Circle($x, $y, $r, $style='D')
{
        $this->Ellipse($x,$y,$r,$r,$style);
}

function Ellipse($x, $y, $rx, $ry, $style='D')
{
        if($style=='F')
                $op='f';
        elseif($style=='FD' || $style=='DF')
                $op='B';
        else
                $op='S';
        $lx=4/3*(M_SQRT2-1)*$rx;
        $ly=4/3*(M_SQRT2-1)*$ry;
        $k=$this->k;
        $h=$this->h;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
                ($x+$rx)*$k,($h-$y)*$k,
                ($x+$rx)*$k,($h-($y-$ly))*$k,
                ($x+$lx)*$k,($h-($y-$ry))*$k,
                $x*$k,($h-($y-$ry))*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                ($x-$lx)*$k,($h-($y-$ry))*$k,
                ($x-$rx)*$k,($h-($y-$ly))*$k,
                ($x-$rx)*$k,($h-$y)*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                ($x-$rx)*$k,($h-($y+$ly))*$k,
                ($x-$lx)*$k,($h-($y+$ry))*$k,
                $x*$k,($h-($y+$ry))*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s',
                ($x+$lx)*$k,($h-($y+$ry))*$k,
                ($x+$rx)*$k,($h-($y+$ly))*$k,
                ($x+$rx)*$k,($h-$y)*$k,
                $op));
}
}


class vordruckaspdf extends PDF_Ellipse {
  function Error ($message) {
    throw new RuntimeException (
      "Message form PDF rendering failed: " . (string) $message
    );
  }

// Vollbild
  var $left   =    0 ;
  var $right  = 210 ;
  var $top    =    0 ;
  var $bottom = 297 ;

  var $border = array (
                'top'   => 10,
                'left'   => 10,
                'right'  => 10,
                'bottom' => 10
                );

  var $paperform = "P" ;  // für Portrait
  var $papertyp  = "A4" ; // DIN A4 2100 mm x 2970 mm

// Trennungslinie FM / Stab bzw Stab / Sichter
  var $fm_stab_line = 60 ; // 300 px von oben
  var $stab_sichter_line = 200 ; // 400 px für den Sichter

// Bildbereich Formulardaten
  var $fleft ;
  var $fright ;
  var $ftop ;
  var $fbottom ;

// Liniendicke
  var $fline00 = 0.2 ;
  var $fline01 = 0.4 ;
  var $fline02 = 1 ;

// Farben
  var $color_sw ;
  var $color_rd ;
  var $color_bl ;

// Schrift und Schriftgrössen
  var $font ;
  var $fontsize00 = 8 ;
  var $fontsize01 = 10 ;
  var $fontsize02 = 12 ;
  var $fontsize03 = 16 ;
  var $fontsize04 = 21 ;
  var $fontsize30 = 30 ;
  var $fontsize35 = 35 ;
  var $fontsize50 = 60 ;

  var $fkt_size = 11 ;

// Das Bild
  var $image ;

  var $db_dataset ;
  var $recipientMatrix ;
  var $attachmentLinksEnabled = true ;
  var $unmatchedRecipientLabels = array () ;

  /*******************************************************************************
            Klassen Konstruktor
  ********************************************************************************/
  function __construct ($data, $recipientMatrix = null) {
    $this->vordruckaspdf ($data, $recipientMatrix);
  }

  function vordruckaspdf ($data, $recipientMatrix = null) {
    $this->initialize_message_form_document ($recipientMatrix);
    $this->set_message_form_data ($data);
  }

  function initialize_message_form_document ($recipientMatrix = null) {
    $this->FPDF ('P', 'mm', 'A4');
    $this->init_pkts ();
    $this->SetMargins (
      $this->border['left'],
      $this->border['top'],
      $this->border['right']
    );
    $this->SetAutoPageBreak(true, $this->bottom - $this->point[38][1]) ;

    $this->fleft   = $this->left   + $this->border['left'] ;
    $this->fright  = $this->right  - $this->border['right'] ;
    $this->ftop    = $this->top    + $this->border['top'] ;
    $this->fbottom = $this->bottom - $this->border['bottom'] ;
/*
    $this->font ["n"] = "georgia.ttf";
    $this->font ["b"] = "georgiab.ttf";
    $this->font ["i"] = "georgiai.ttf";
    $this->font ["z"] = "georgiaz.ttf";
*/
/*
    $this->font ["n"] = $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fbak/fonts/Garrison Sans.ttf";
    $this->font ["b"] = $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fbak/fonts/Garrison Sans BOLD.ttf";
    $this->font ["i"] = $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fbak/fonts/Garrison Sans ITALIC.ttf";
    $this->font ["z"] = $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fbak/fonts/georgiaz.ttf";
*/
    $this->color_sw = array ( "r" =>   0, "g" =>   0, "b" =>   0 );
    $this->color_rd = array ( "r" => 255, "g" =>   0, "b" =>   0 );
    $this->color_bl = array ( "r" =>   0, "g" =>   0, "b" => 255 );
    $this->recipientMatrix = is_array ($recipientMatrix) ? $recipientMatrix : null;
    $this->db_dataset = array ();
    $this->empfarray = array ();
    $this->unmatchedRecipientLabels = array ();
  }

  function set_message_form_data ($data) {
    if (!is_array ($data)) {
      throw new InvalidArgumentException ("Message form data must be an array");
    }
    $fields = array (
      "00_lfd", "einsatz_id", "01_medium", "01_datum", "01_zeichen",
      "02_zeit", "02_zeichen", "03_datum", "03_zeichen", "04_richtung",
      "04_nummer", "05_gegenstelle", "06_befweg", "06_befwegausw",
      "07_durchspruch", "08_befhinweis", "08_befhinwausw",
      "09_vorrangstufe", "10_anschrift", "11_rufnummer",
      "11_gesprnotiz", "12_anhang", "12_betreff", "12_inhalt",
      "12_abfzeit", "13_abseinheit", "14_zeichen",
      "14_funktion", "15_quitdatum", "15_quitzeichen", "16_empf",
      "17_vermerke", "x00_status", "x01_abschluss", "x04_druck",
      "x05_druck_d", "99_lstacc"
    );
    $data = array_replace (array_fill_keys ($fields, ""), $data);

    $this->db_dataset ["00_lfd"]          = $data ["00_lfd"] ;
    $this->db_dataset ["einsatz_id"]      = $data ["einsatz_id"] ;
    $this->db_dataset ["01_medium"]       = $data ["01_medium"];

    $this->db_dataset ["01_datum"] = estab_datetime_is_unset ($data ["01_datum"])
      ? "" : estab_message_form_tactical_time ($data ["01_datum"]);

    $this->db_dataset ["01_zeichen"]      = $data  ["01_zeichen"];

    $this->db_dataset ["02_zeit"] = estab_datetime_is_unset ($data ["02_zeit"])
      ? "" : estab_message_form_tactical_time ($data ["02_zeit"]);

    $this->db_dataset ["02_zeichen"]      = $data ["02_zeichen"];

    $this->db_dataset ["03_datum"] = estab_datetime_is_unset ($data ["03_datum"])
      ? "" : estab_message_form_tactical_time ($data ["03_datum"]);

    $this->db_dataset ["03_zeichen"]      = $data ["03_zeichen"] ;
    $this->db_dataset ["04_richtung"]     = $data ["04_richtung"] ;
    $this->db_dataset ["04_nummer"]       = $data ["04_nummer"] ;
    $this->db_dataset ["05_gegenstelle"]  = $data ["05_gegenstelle"] ;
    $this->db_dataset ["06_befweg"]       = $data ["06_befweg"] ;
    $this->db_dataset ["06_befwegausw"]   = $data ["06_befwegausw"] ;
    $this->db_dataset ["07_durchspruch"]  = $data ["07_durchspruch"] ;
    $this->db_dataset ["08_befhinweis"]   = $data ["08_befhinweis"] ;
    $this->db_dataset ["08_befhinwausw"]  = $data ["08_befhinwausw"] ;
    $this->db_dataset ["09_vorrangstufe"] =
      estab_message_priority_document_label ($data ["09_vorrangstufe"]);
    $this->db_dataset ["10_anschrift"]    = $data ["10_anschrift"] ;
    $this->db_dataset ["11_rufnummer"]    = $data ["11_rufnummer"] ;
    $this->db_dataset ["11_gesprnotiz"]   = $data ["11_gesprnotiz"] == "t" ;
    $this->db_dataset ["12_anhang"]       = $data ["12_anhang"] ;
    $this->db_dataset ["12_betreff"]      = $data ["12_betreff"] ;
    $this->db_dataset ["12_inhalt"]       = $data ["12_inhalt"] ;

    $this->db_dataset ["12_abfzeit"] = estab_datetime_is_unset ($data ["12_abfzeit"])
      ? "" : estab_message_form_tactical_time ($data ["12_abfzeit"]);
    $this->db_dataset ["13_abseinheit"]   = $data ["13_abseinheit"] ;
    $this->db_dataset ["14_zeichen"]      = $data ["14_zeichen"] ;
    $this->db_dataset ["14_funktion"]     = $data ["14_funktion"] ;

    $this->db_dataset ["15_quitdatum"] = estab_datetime_is_unset ($data ["15_quitdatum"])
      ? "" : estab_message_form_tactical_time ($data ["15_quitdatum"]);
    $this->db_dataset ["15_quitzeichen"]  = $data ["15_quitzeichen"] ;
    $this->db_dataset ["16_empf"]         = $data ["16_empf"] ;
    $this->db_dataset ["17_vermerke"]     = $data ["17_vermerke"] ;
    $this->db_dataset ["x00_status"]      = $data ["x00_status"] ;
    $this->db_dataset ["x01_abschluss"]   = $data ["x01_abschluss"];
    $this->db_dataset ["x04_druck"]       = $data ["x04_druck"] == "t" ;

    $this->db_dataset ["x05_druck_d"] = estab_datetime_is_unset ($data ["x05_druck_d"])
      ? "" : estab_message_form_tactical_time ($data ["x05_druck_d"]);
    $this->db_dataset ["99_lstacc"]       = $data ["99_lstacc"];

  }

  var $point ;

  function init_pkts(){
    $this->point [ 1] = array (   0,   0);
    $this->point [ 2] = array (  55,   0);
    $this->point [ 3] = array ( 144,   0);
    $this->point [ 4] = array ( 190,   0);

    $this->point [ 5] = array (   0,   5);
    $this->point [ 6] = array (  55,   5);
    $this->point [ 7] = array (  97,   5);
    $this->point [ 8] = array ( 144,   5);

    $this->point [ 9] = array (   0,  24);
    $this->point [10] = array (  55,  24);
    $this->point [11] = array (  97,  24);
    $this->point [12] = array ( 144,  24);

    $this->point [13] = array (   0,  29);
    $this->point [14] = array (  55,  29);
    $this->point [15] = array (  97,  29);
    $this->point [16] = array ( 144,  29);
    $this->point [17] = array ( 190,  29);

    $this->point [18] = array (   0,  38);
    $this->point [19] = array (  55,  38);
    $this->point [20] = array ( 135,  38);
    $this->point [21] = array ( 190,  38);

    $this->point [22] = array (   0,  46);
    $this->point [23] = array (  25,  46);
    $this->point [24] = array (  34,  38);
    $this->point [25] = array (  34,  46);
    $this->point [26] = array (  55,  46);
    $this->point [27] = array ( 135,  46);
    $this->point [28] = array ( 190,  46);

    $this->point [29] = array (   0,  59);
    $this->point [30] = array (  25,  59);
    $this->point [31] = array (  34,  59);
    $this->point [57] = array (  55,  59);
    $this->point [32] = array ( 135,  59);
    $this->point [33] = array ( 190,  59);

    $this->point [34] = array (   0,  76);
    $this->point [35] = array (  34,  76);
    $this->point [36] = array ( 135,  76);
    $this->point [37] = array ( 190,  76);

    $this->point [38] = array (   0, 160+27);
    $this->point [39] = array (  25, 160+27);
    $this->point [40] = array ( 190, 160+27);

    $this->point [41] = array (   0, 169+27);
    $this->point [42] = array (  25, 169+27);
    $this->point [43] = array (  92, 169+27);
    $this->point [44] = array ( 134, 169+27);
    $this->point [45] = array ( 190, 169+27);

    $this->point [46] = array (   0, 182+27);
    $this->point [47] = array (  25, 182+27);
    $this->point [48] = array (  92, 182+27);
    $this->point [49] = array ( 100, 182+27);
    $this->point [50] = array ( 134, 182+27);
    $this->point [51] = array ( 190, 182+27);

    $this->point [52] = array (   0, 194+27);
    $this->point [53] = array ( 100, 194+27);

    $this->point [54] = array (   0, 248+30);
    $this->point [55] = array ( 100, 248+30);
    $this->point [56] = array ( 190, 248+30);

    $this->point [60] = array ( 190-25, 248+30-25);
  }



  function imagegrid($image, $w, $h, $s, $color) {
      $this->SetDrawColor ($color['r'],$color['g'],$color['b']);
      $this->line( 0, 0, $w-1, 0);
      $this->line( $w-1, 0, $w-1, $h-1 );
      $this->line( $w-1, $h-1, 0, $h-1 );
      $this->line( 0, $h-1, 0, 0 );

      for($iw=1; $iw<=$w/$s+1; $iw++){
        $this->line( $iw*$s, 0, $iw*$s, $h );}

      for($ih=1; $ih<=$h/$s+1; $ih++){
        $this->line( 0, $ih*$s, $w, $ih*$s );}
  }

  /*******************************************************************************
            erweiterte grafische Grundfunktionen
  ********************************************************************************/


  /*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
  \*****************************************************************************/
    // Listet unter Inhalt eventuelle Anhangsdateien als href auf
  function list_anhang ($x, $y){
//    include ("../4fcfg/dbcfg.inc.php");
//    include ("../4fcfg/e_cfg.inc.php");
    if ($this->attachmentLinksEnabled) {
      include (__DIR__ . "/../4fcfg/config.inc.php");
    }
      // in 12_anhang stehen die Anhangdateien mit ";" getrennt.
    $anhaenge = preg_split("/;/", $this->db_dataset ["12_anhang"]);
    foreach ($anhaenge as $anhang){
      if ($anhang != "") {
        try {
          $anhang = estab_file_validate_name ("attachment", trim ($anhang));
          $link = "";
          if ($this->attachmentLinksEnabled) {
            $link = estab_file_download_url (
              $conf_4f ["download_uri"],
              "attachment",
              $anhang
            );
          }
        } catch (InvalidArgumentException) {
          continue;
        }
        $this->draw_text_with_link ($x,
                                    $y, 0,
                                    $this->color_bl, $this->fontsize00, "b", "o", "l", $anhang."   ", $link) ;
        $x = $this->GetX() ;
        $y = $this->GetY() ;

//        echo "Anhang=".$anhang."  link=".$link."<br>";

      }
    }
  } // list_anhang ()


  /*******************************************************************************
    Linie x1y1 x2y2 dicke farbe
  ********************************************************************************/
  function draw_line ( $x1, $y1, $x2, $y2, $pixel, $color){
    $this->SetLineWidth($pixel);
    $this->SetDrawColor ($color['r'],$color['g'],$color['b']);
    $this->line( $x1, $y1, $x2, $y2 );
  }

  function draw_linebypoint ( $p1, $p2, $pixel, $color){
    $bl = $this->border ['left'];
    $bt = $this->border ['top'];
    $br = $this->border ['right'];
    $bb = $this->border ['bottom'];

    $this->SetLineWidth($pixel);
    $this->SetDrawColor ($color['r'],$color['g'],$color['b']);
    $this->line( $bl+$this->point[$p1][0],
                 $bt+$this->point[$p1][1],
                 $bl+$this->point[$p2][0],
                 $bt+$this->point[$p2][1] );
  }




  function draw_rb_select ($x, $y, $color){
    $this->draw_line ( $x-2, $y+2, $x+2, $y-2, 0.5 ,$color);
    $this->draw_line ( $x+2, $y+2, $x-2, $y-2, 0.5 ,$color);
  }

  /*******************************************************************************
    Linie x1y1 x2y2 dicke farbe
  ********************************************************************************/

  function draw_rectagle ($x1, $y1, $x2, $y2, $pixel, $color){
    $this->SetDrawColor ($color['r'],$color['g'],$color['b']);
    $this->SetLineWidth ($pixel);
    $this->line($x1, $y1, $x1, $y2 );
    $this->line($x1, $y2, $x2, $y2 );
    $this->line($x2, $y2, $x2, $y1 );
    $this->line($x2, $y1, $x1, $y1 );
  }



  function draw_rectaglebypoints ($x1, $y1, $x2, $y2, $pixel, $color){
    $bl = $this->border ['left'];
    $bt = $this->border ['top'];
    $br = $this->border ['right'];
    $bb = $this->border ['bottom'];
    $x1 += $bl; $x2 += $bl; $y1 += $bt; $y2 += $bt;
    $this->SetDrawColor ($color['r'],$color['g'],$color['b']);
    $this->SetLineWidth ($pixel);
    $this->line($x1, $y1, $x1, $y2 );
    $this->line($x1, $y2, $x2, $y2 );
    $this->line($x2, $y2, $x2, $y1 );
    $this->line($x2, $y1, $x1, $y1 );
  }

  function draw_radiobutton ( $x, $y, $select, $size, $text ){
    $this->SetLineWidth (0.5);
    $this->SetDrawColor ($this->color_sw['r'],$this->color_sw['g'],$this->color_sw['b']);
    $this->draw_text ($x+2, $y, 0, $this->color_sw, $size, "n", "o", "l", $text);
    $x += $this->border ['left'];
    $y += $this->border ['top'];
    $this->Circle ($x, $y, 1.5);
    if ( $select ){ $this->draw_rb_select ($x, $y, $this->color_bl); }
  }

  function draw_mediumselect ($x, $y, $selectvalue){
    $aa1 = array ("Fu","Fe","Me","Fax","DFÜ");
    for ($o=0; $o<= 4; $o++){
      if ( $aa1[$o] == $selectvalue ){ $select = true; }else{ $select=false; }
      $this->draw_radiobutton ( $x+$o*10, $y, $select, $this->fontsize00, $aa1[$o] );
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
    $x += $this->border ['left'];
    $y += $this->border ['top'];
     // Linienfarbe auf Blau einstellen
    $this->SetTextColor($color['r'],$color['g'],$color['b']);
    switch ($fontaz){
      case "n": //links
        $az = "";
      break;
      case "i": // mitte
        $az = "I";
      break;
      case "b": // rechts
        $az = "B";
      break;
      default: // nothing
    }
    // Schriftart definieren
    $this->SetFont('helvetica', $az, $size );
    switch ($posv){
      case "o": //links
      break;
      case "m": // mitte
        $y -= $size/2 ;
      break;
      case "u": // rechts
        $y -= $size ;
      break;
      default: // nothing
    }
    switch ($posh){
      case "l": //links
        $align = "L";
      break;
      case "z": // mitte
        $align = "C";
      break;
      case "r": // rechts
        $align = "R";
      break;
      default: $align = "L"; // nothing
    }
    $this->SetXY ($x, $y);
    $this->Cell(
      1,
      1,
      estab_fpdf_text(estab_message_plain_text($text)),
      0,
      0,
      $align,
      0
    );
  }


/*******************************************************************************/

  function draw_text_with_link ($x, $y, $angle, $color, $size, $fontaz, $posv, $posh, $text, $link){
//    $x += $this->border [left];
//    $y += $this->border [top];
     // Linienfarbe auf Blau einstellen
    $this->SetTextColor($color['r'],$color['g'],$color['b']);
    switch ($fontaz){
      case "n": //links
        $az = "";
      break;
      case "i": // mitte
        $az = "I";
      break;
      case "b": // rechts
        $az = "B";
      break;
      default: // nothing
    }
    // Schriftart definieren
    $this->SetFont('helvetica', $az, $size );
    switch ($posv){
      case "o": //links
      break;
      case "m": // mitte
        $y -= $size/2 ;
      break;
      case "u": // rechts
        $y -= $size ;
      break;
      default: // nothing
    }
    switch ($posh){
      case "l": //links
        $align = "L";
      break;
      case "z": // mitte
        $align = "C";
      break;
      case "r": // rechts
        $align = "R";
      break;
      default: $align = "L"; // nothing
    }
    $this->SetXY ($x, $y);
    $pdftext = estab_fpdf_text(estab_message_plain_text($text));
    $w = $this->GetStringWidth( $pdftext );

    $this->Cell( $w, $size, $pdftext, "LTRB",  0, $align, 0, $link);
  }

/*******************************************************************************/
/*******************************************************************************/
/*******************************************************************************/
  function gesamtrahmenbypoints (){

//  print_r ($this->border);

    $this->draw_rectagle ( $this->left + $this->border ['left'],
                           $this->top + $this->border ['top'],
                           $this->right - $this->border ['right'],
                           $this->bottom - $this->border ['bottom'],
                           $this->fline01,
                           $this->color_sw );
    $this->draw_rectaglebypoints ( $this->point[22][0],
                           $this->point[22][1],
                           $this->point[51][0],
                           $this->point[51][1],
                           $this->fline02,
                           $this->color_sw );

  }

  function linesbypoints (){

    $this->draw_linebypoint (   5,  8, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (   2, 19, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (   7, 15, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (   3, 16, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (   9, 12, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  13, 17, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  18, 21, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  24, 25, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  20, 36, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  23, 30, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  31, 35, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  29, 33, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  34, 37, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  26, 57, $this->fline00, $this->color_sw);

    // Der Betreff belegt die amtliche Inhalts-Kopfzeile. Der eigentliche
    // Nachrichtentext beginnt darunter und bleibt über Folgeseiten identisch
    // eingerückt.
    $subjectBottom = $this->border ['top'] + $this->point [34][1] + 10;
    $this->draw_line (
      $this->border ['left'] + $this->point [34][0],
      $subjectBottom,
      $this->border ['left'] + $this->point [37][0],
      $subjectBottom,
      $this->fline00,
      $this->color_sw
    );
    $this->draw_line (
      $this->border ['left'] + $this->point [34][0] + 34,
      $this->border ['top'] + $this->point [34][1],
      $this->border ['left'] + $this->point [34][0] + 34,
      $subjectBottom,
      $this->fline00,
      $this->color_sw
    );

    $this->draw_linebypoint (  38, 40, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  41, 45, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  39, 47, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  43, 48, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  44, 50, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  52, 53, $this->fline00, $this->color_sw);
    $this->draw_linebypoint (  49, 55, $this->fline00, $this->color_sw);
  }

/*******************************************************************************/
  function fixtext (){
    // Feld 1
    $this->draw_text (  27,   2.5,  0, $this->color_sw, $this->fontsize01, "b", "o", "z", "EINGANG" );
    $this->draw_text (  27,   7,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Aufnahmevermerk" );
    $this->draw_text (  27,  26,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Datum   Zeit   Kürzel" );



    $this->draw_text (  97,   2.4,  0, $this->color_sw, $this->fontsize01, "b", "o", "z", "AUSGANG" );
    $this->draw_text (  76,   7,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Annahmevermerk" );
    $this->draw_text (  76,  26,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Zeit   Kürzel" );

    $this->draw_text ( 120,   7,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Beförderungsvermerk" );
    $this->draw_text ( 120,  26,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Datum   Zeit   Kürzel" );


    $this->draw_text ( 165,  2.3,   0, $this->color_sw, $this->fontsize01, "b", "o", "z", "Nachweis-Nr." );

    $this->draw_text ( 2,  31,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Rufname der Gegenstelle/" );
    $this->draw_text ( 2,  34,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Spruchkopf" );

    $this->draw_text ( 2,  40,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Beförderungsweg" );

    $this->draw_text (  26,  52,  0, $this->color_sw, $this->fontsize00, "n", "m", "l", "Beförderungshinweis" );

    $this->draw_text (   2,  61,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Vorrang" );
    $this->draw_text (  35,  61,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Anschrift" );
    $this->draw_text (  35,  69,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Ruf Nr." );
    $this->draw_text ( 137,  61,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Gesprächsnotiz" );

    $this->draw_text ( $this->point [34][0]+2,
                       $this->point [34][1]+2,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Inhalt" );

    $this->draw_text ( $this->point [38][0]+2,
                       $this->point [38][1]+2,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Abfassungszeit" );

    $this->draw_text ( $this->point [41][0]+2,
                       $this->point [41][1]+2,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Absender" );

    $this->draw_text ( $this->point [42][0]+($this->point [43][0]-$this->point [42][0])/2,
                       $this->point [47][1]-3,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Einheit/Einrichtung/Stelle" );

    $this->draw_text ( $this->point [43][0]+($this->point [44][0]-$this->point [43][0])/2,
                       $this->point [48][1]-3,  0, $this->color_sw, $this->fontsize00, "n", "o", "z", "Zeichen" );

    $this->draw_text ( $this->point [44][0]+2,
                       $this->point [50][1]-3,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Funktion" );

    $this->draw_text ( $this->point [46][0]+2,
                       $this->point [46][1]+2,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Quittung" );

    $this->draw_text ( $this->point [46][0]+30,
                       $this->point [46][1]+10,  0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Zeit  Zeichen" );

    $this->draw_text ( $this->point [49][0]+2,
                       $this->point [49][1]+2,   0, $this->color_sw, $this->fontsize00, "n", "o", "l", "Vermerk" );
  }


  function draw_textfield ($x1, $y1, $x2, $y2, $color, $ntext){

    $x1 += $this->border ['left'];
    $y1 += $this->border ['top'];
    $x2 += $this->border ['left'];
    $y2 += $this->border ['top'];


    $this->SetXY ($x1, $y1);
    $this->SetTextColor ($color['r'],$color['g'],$color['b']);
    $this->SetFont ("helvetica","B",$this->fontsize01);
    $text = estab_fpdf_text(estab_message_plain_text($ntext));

    $delta_x = $x2 - $x1 ;
    $delta_y = $y2 - $y1 ;

    $this->MultiCell ($delta_x, 5, $text);
//    $this->MultiCell (0,5,$text);
  }

  /**
   * Draw one field value on a single official-form line without crossing the
   * neighbouring cell. FPDF core fonts use Windows-1252, so byte-wise fitting
   * is deterministic after conversion.
   */
  function draw_fitted_textfield (
    $x1,
    $y1,
    $x2,
    $color,
    $size,
    $ntext
  ) {
    $x1 += $this->border ['left'];
    $y1 += $this->border ['top'];
    $x2 += $this->border ['left'];

    $this->SetTextColor ($color['r'], $color['g'], $color['b']);
    $this->SetFont ("helvetica", "B", $size);
    $plain = preg_replace (
      "/[\\r\\n]+/",
      " ",
      estab_message_plain_text ($ntext)
    );
    $text = estab_fpdf_text ($plain === null ? "" : $plain);
    $maximumWidth = max (0, $x2 - $x1 - 1);
    if ($this->GetStringWidth ($text) > $maximumWidth) {
      $suffix = "...";
      $maximumTextWidth = max (
        0,
        $maximumWidth - $this->GetStringWidth ($suffix)
      );
      while (
        $text !== ""
        && $this->GetStringWidth ($text) > $maximumTextWidth
      ) {
        $text = rtrim (substr ($text, 0, -1));
      }
      $text .= $suffix;
    }
    $this->SetXY ($x1, $y1);
    $this->Cell ($maximumWidth, 5, $text, 0, 0, "L");
  }

  var $empfarray ;

/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function ziele (){
    if (!is_array ($this->recipientMatrix)) {
      include ("../4fcfg/fkt_rolle.inc.php");
      $this->recipientMatrix = $empf_matrix;
    }
    $empf_matrix = $this->recipientMatrix;
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
    $empf_array = array ();
    foreach (explode (",", (string) $empf_text) as $empf_code) {
      $empf_code = trim ($empf_code);
      if ($empf_code == "") {
        continue;
      }
      $fkt = $empf_code;
      $cpycol = "";
      $empf_separator = strrpos ($empf_code, "_");
      if ($empf_separator !== false) {
        $empf_suffix = substr ($empf_code, $empf_separator + 1);
        if (in_array ($empf_suffix, array ("rt", "gn", "bl"), true)) {
          $fkt = substr ($empf_code, 0, $empf_separator);
          $cpycol = $empf_suffix;
        }
      }
      $fkt = trim ($fkt);
      if ($fkt == "") {
        continue;
      }
      $empf_array [] = array (
        "fkt" => $fkt,
        "cpy" => $cpycol,
        "display" => $cpycol == ""
          ? $empf_code
          : $fkt . " [" . $cpycol . "]"
      );
    }
    $matched_empf = array ();

    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        foreach ($empf_array as $empf_index => $empfaenger){
            if ( ( strtoupper ( $empfaenger['fkt'] ) ==  strtoupper ( $empf_matrix [$i][$j]["fkt"]) ) and
                 ( $empf_matrix [$i][$j]["fkt"] != "" ) ){
              $this->empfarray [$i][$j]["checked"] = true;
              $matched_empf [$empf_index] = true;
//              $this->empfarray [$i][$j]["cpycol"] = $empfaenger['cpy'];
            }
        }
      }
    }
    $this->unmatchedRecipientLabels = array ();
    foreach ($empf_array as $empf_index => $empfaenger) {
      if (!isset ($matched_empf [$empf_index])) {
        $this->unmatchedRecipientLabels [] = $empfaenger ["display"];
      }
    }
    $this->unmatchedRecipientLabels = array_values (
      array_unique ($this->unmatchedRecipientLabels)
    );
  }



  function mediumselect () {
      // Aufnamevermerk
    $this->draw_mediumselect (  3,  11, $this->db_dataset ["01_medium"]);
      // Beförderungsweg
    $this->draw_mediumselect (138,  42, $this->db_dataset ["06_befwegausw"]);
     // Beförderungshinweis
    $this->draw_mediumselect (138,  52, $this->db_dataset ["08_befhinwausw"]);

    if ( $this->db_dataset ["07_durchspruch"] == "D"){ $select_D = true;}else{$select_D = false;}
    $this->draw_radiobutton  (  3,  49, $select_D, $this->fontsize00, "DURCHSAGE" );

    if ( $this->db_dataset ["07_durchspruch"] == "S"){ $select_S = true;}else{$select_S = false;}
    $this->draw_radiobutton  (  3,  53, $select_S, $this->fontsize00, "SPRUCH" );

    if ( $this->db_dataset ["11_gesprnotiz"] == "t"){ $select = true;}else{$select = false;}
    $this->draw_radiobutton  ( 164, 66, $select, $this->fontsize00, "" );

    $this->ziele();

    $empf_matrix = $this->recipientMatrix;
    $x0 =  $this->point [52][0] + 5 ;
    $y0 =  $this->point [52][1] + 5 ;
    $dx =  24 ;
    $dy =  10 ;
    for ($y=1; $y<=5; $y++){
      for ($x=1; $x<=4; $x++){
        if ( $empf_matrix[$y][$x]['fkt'] != "" ) {
          if ( $this->empfarray [$y][$x]["checked"] ){ $select = true;}else{$select = false;}
          $this->draw_radiobutton  ( $x0 + ($x-1)*$dx, $y0 + ($y-1)*$dy, $select, $this->fkt_size,  $empf_matrix[$y][$x]['fkt'] );
        }
      }
    }
  }

  function writedata_without_inhalt(){
      // Eingang Aufnahmevermerk Datum/Zeit
    $this->draw_text ( 27,  20,  0, $this->color_bl, $this->fontsize02, "b", "o", "z",
                       $this->db_dataset ["01_datum"]." ".$this->db_dataset ["01_zeichen"] );
      // Ausgang Annahmevermerk Datum/Zeit
    $this->draw_text ( 76,  20,  0, $this->color_bl, $this->fontsize02, "b", "o", "z",
               $this->db_dataset ["02_zeit"]." ".$this->db_dataset ["02_zeichen"] );
      // Eingang Aufnahmevermerk Datum/Zeit
    $this->draw_text ( 120, 20,  0, $this->color_bl, $this->fontsize02, "b", "o", "z",
               $this->db_dataset ["03_datum"]." ".$this->db_dataset ["03_zeichen"] );
      // Nachweisung E/A Nummer
    $this->draw_text ( 146,  33,  0, $this->color_bl, $this->fontsize35, "b", "m", "l",
               $this->db_dataset ["04_richtung"]." ".$this->db_dataset ["04_nummer"] );

      // Rufname der Gegenstelle
    $this->draw_text ( $this->point[14][0]+2,
                       $this->point[14][1]+4,  0,
                       $this->color_bl,
                       $this->fontsize01, "b", "o", "l",
                       $this->db_dataset ["05_gegenstelle"] );
      // Beförderungsweg
    $this->draw_text ( $this->point[24][0]+2,
                       $this->point[24][1]+4,  0,
                       $this->color_bl,
                       $this->fontsize01, "b", "o", "l",
                       $this->db_dataset ["06_befweg"] );
      // Beförderungshinweis
    $this->draw_text ( $this->point[26][0]+2,
                       $this->point[26][1]+4,  0,
                       $this->color_bl,
                       $this->fontsize01, "b", "o", "l",
                       $this->db_dataset ["08_befhinweis"] );
      // Vorrandstufe
    $this->draw_text ( $this->point[29][0]+16,
                       $this->point[29][1]+10,  0,
                       $this->color_bl,
                       $this->fontsize35, "b", "o", "z",
                       $this->db_dataset ["09_vorrangstufe"] );
      // Anschrift und Rufnummer stehen wie im amtlichen Vordruck gemeinsam
      // neben dem Gesprächsnotiz-Feld.
    $this->draw_fitted_textfield (
      $this->point[31][0]+23,
      $this->point[31][1]+1,
      $this->point[36][0],
      $this->color_bl,
      $this->fontsize01,
      $this->db_dataset ["10_anschrift"]
    );
    $this->draw_fitted_textfield (
      $this->point[31][0]+23,
      $this->point[31][1]+9,
      $this->point[36][0],
      $this->color_bl,
      $this->fontsize01,
      $this->db_dataset ["11_rufnummer"]
    );

      // Betreff in der eigenen Inhalts-Kopfzeile.
    $this->draw_fitted_textfield (
      $this->point[34][0]+36,
      $this->point[34][1]+1,
      $this->point[37][0],
      $this->color_bl,
      $this->fontsize01,
      $this->db_dataset ["12_betreff"]
    );


      // Anfassungszeit
    $this->draw_text ( $this->point[39][0]+2,
                       $this->point[39][1]+4,  0,
                       $this->color_bl,
                       $this->fontsize01, "b", "o", "l",
                       $this->db_dataset ["12_abfzeit"] );

     // Absende Einheit Stelle Einrichtung
    $this->draw_text ( $this->point[42][0]+2,
                       $this->point[42][1]+4,  0,
                       $this->color_bl,
                       $this->fontsize01, "b", "o", "l",
                       $this->db_dataset ["13_abseinheit"] );
      // Kürzel
    $this->draw_text ( $this->point[43][0]+2,
                       $this->point[43][1]+4,  0,
                       $this->color_bl,
                       $this->fontsize01, "b", "o", "l",
                       $this->db_dataset ["14_zeichen"] );
      // Funktion
    $this->draw_text ( $this->point[44][0]+2,
                       $this->point[44][1]+4,  0,
                       $this->color_bl,
                       $this->fontsize02, "b", "o", "l",
                       $this->db_dataset ["14_funktion"] );

      // Quittung
    $this->draw_text ( $this->point[42][0]+2,
                       $this->point[46][1]+6,  0,
                       $this->color_bl,
                       $this->fontsize01, "b", "o", "z ",
                       $this->db_dataset ["15_quitdatum"]."  ".$this->db_dataset ["15_quitzeichen"] );

    $this->draw_textfield ( $this->point[49][0],
                            $this->point[49][1]+5,
                            $this->point[56][0],
                            $this->point[56][1],
                            $this->color_bl,
                            $this->db_dataset["17_vermerke"]);

  }


/*******************************************************************************/

  function writedata_inhalt () {
    $this->draw_textfield ( $this->point[34][0]+5,
                            $this->point[34][1]+15,
                            $this->point[40][0]-10,
                            $this->point[40][1]-5,
                            $this->color_bl,
                            $this->db_dataset["12_inhalt"]
//                            html_entity_decode($this->db_dataset["12_inhalt"]
                            );
    $x = $this->GetX ();
    $y = $this->GetY ();

    if (count ($this->unmatchedRecipientLabels) > 0) {
      $this->SetXY ($x + 5, $y);
      $this->SetTextColor (
        $this->color_bl ["r"],
        $this->color_bl ["g"],
        $this->color_bl ["b"]
      );
      $this->SetFont ("helvetica", "B", $this->fontsize00);
      $this->MultiCell (
        $this->point[40][0] - $this->point[34][0] - 15,
        5,
        estab_fpdf_text (
          "Empfänger außerhalb aktueller Matrix: "
          . implode (", ", $this->unmatchedRecipientLabels)
        )
      );
      $x = $this->GetX ();
      $y = $this->GetY ();
    }

    if ($this->db_dataset["12_anhang"] != ""){
      $this->list_anhang ($x+5,$y);
    }
  }



/*******************************************************************************/
  function set_message_content_continuation_position () {
    $this->SetXY (
      $this->point[34][0] + $this->border['left'] + 5,
      $this->point[34][1] + $this->border['top'] + 15
    );
  }

/*******************************************************************************/
  function Footer () {
    include (__DIR__ . "/../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben


    $this->SetTextColor ( 0, 0, 0);
    $this->SetFont ("times","B",12);

    $this->RotatedText( 7, $this->point[22][1]+2 , "Fm-Betriebsstelle", 90) ;
    $this->RotatedText( 7, 10 + $this->point[22][1] + ( $this->point[46][1] - $this->point[22][1] )/2 ,
                        "Verfasser", 90) ;

    $this->RotatedText( 7, 10 + $this->point[46][1] + ( $this->point[54][1] - $this->point[46][1] )/2 ,
                        "Sichter", 90) ;

    $this->SetTextColor (220,220,220);
    $this->SetFont ("times","",3);
    $text = "(C) 2005 bis 2013 Hajo Landmesser - ".$conf_4f['Titelkurz'] ."  ".$conf_4f['Version']." alle Rechte vorbehalten" ;
    $this->RotatedText( 9, $this->point[54][1]+9 , $text, 90) ;

  }

/*******************************************************************************/
  function Header (){
//    $this->SetAutoPageBreak(false, 0) ;
    $this->gesamtrahmenbypoints();
    $this->linesbypoints ();

    $this->fixtext ();
    $this->mediumselect ();

        //Position 1,5 cm von unten
    $this->SetXY ($this->point[16][0]-15, $this->point[16][1]+2);
    //Arial kursiv 8
    $this->SetFont('Arial','I',8);
    //Seitenzahl
    $this->Cell(0,10,'Seite '.$this->PageNo().'/{nb}',0,0,'C');


    $this->writedata_without_inhalt ();
  }

/*******************************************************************************/
  function AcceptPageBreak() {
    if($this->GetY() >= $this->point[38][1]-10) {
      $this->AddPage();
      $this->set_message_content_continuation_position();
    }
    return false;
  }

/*******************************************************************************/
  function render_message_form_document(){
    if ($this->PageNo() !== 0) {
      throw new LogicException ("Message form document was already rendered");
    }
    $this->SetFont('helvetica','',12);
    $this->AliasNbPages();
    $this->AddPage();
    $this->writedata_inhalt ();

    $document = $this->Output ("", "S");
    if (
      !is_string ($document)
      || !str_starts_with ($document, "%PDF-")
      || !str_ends_with ($document, "%%EOF\n")
    ) {
      throw new RuntimeException ("Message form renderer returned an incomplete PDF");
    }
    return $document;
  }

/*******************************************************************************/
  function main(){
    include (__DIR__ . "/../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben
    include (__DIR__ . "/../4fcfg/dbcfg.inc.php");     // Datenbankparameter
    include (__DIR__ . "/../4fcfg/e_cfg.inc.php");     // Datenbankname

    $filename = estab_generated_form_filename (
      $conf_4f_db ["datenbank"],
      $this->db_dataset ["einsatz_id"],
      $this->db_dataset ["04_nummer"],
      $this->db_dataset ["04_richtung"]
    );
    $document = $this->render_message_form_document ();
    estab_generated_form_publish (
      $conf_4f ["vordruck_dir"],
      $filename,
      $document
    );
    return $filename;
  }


} // class
/**********************************************************************************************\
                          E N D       C L A S S       E N D
\**********************************************************************************************/



?>
