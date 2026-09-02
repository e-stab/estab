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
require_once __DIR__ . "/../app/nv_raster.php";
require_once __DIR__ . "/../app/nv_verteiler.php";
require_once __DIR__ . "/../app/message_transport.php";
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
  // Die Tinte der Eintragungen. Sie ist schwarz wie am Bildschirm: der
  // Vordruck und das Eingetragene stehen dort in derselben Farbe, und drei
  // Ausgaben desselben Blattes sollen sich nicht in der Farbe unterscheiden.
  var $color_eintrag ;

// Schrift und Schriftgrössen
  var $font ;
  var $fontsize00 = 8 ;
  var $fontsize03 = 16 ;
  var $fontsize04 = 21 ;
  var $fontsize30 = 30 ;
  var $fontsize50 = 60 ;


// Das Bild
  var $image ;

  var $db_dataset ;
  var $messageNumber ;
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
    $this->SetAutoPageBreak (true, $this->message_form_break_margin ()) ;

    $this->fleft   = $this->left   + $this->border['left'] ;
    $this->fright  = $this->right  - $this->border['right'] ;
    $this->ftop    = $this->top    + $this->border['top'] ;
    $this->fbottom = $this->bottom - $this->border['bottom'] ;
    $this->color_sw = array ( "r" =>   0, "g" =>   0, "b" =>   0 );
    $this->color_rd = array ( "r" => 255, "g" =>   0, "b" =>   0 );
    $this->color_eintrag = array ( "r" =>   0, "g" =>   0, "b" =>   0 );
    $this->recipientMatrix = is_array ($recipientMatrix) ? $recipientMatrix : null;
    $this->db_dataset = array ();
    $this->messageNumber = null;
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
    // The message number is the stable archive identity. The visible
    // "Nachweis-Nr." is instead the first linked TBB book number and may be
    // empty for an internal conversation note that never entered the TBB.
    $this->messageNumber                   = $data ["04_nummer"] ?? null ;
    $this->db_dataset ["04_nummer"]       =
      array_key_exists ("estab_ttb_lfd", $data)
        ? ($data ["estab_ttb_lfd"] ?? "")
        : ($data ["04_nummer"] ?? "") ;
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
    $this->db_dataset ["14_funktion"]     = estab_function_display_name (
      (string) $data ["14_funktion"]
    ) ;

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

  var $raster ;

  /**
   * Legt das Raster fest und setzt das Blatt mittig auf die Seite.
   *
   * Der Rand ist kein Gestaltungswert, sondern das, was von A4 uebrig
   * bleibt, wenn das Blatt in seinem gemessenen Mass daraufliegt. Damit
   * gelten fuer alle Zeichenbefehle Blattkoordinaten: Ursprung ist die
   * linke obere Ecke des Vordrucks, nicht die der Seite.
   */
  function init_pkts (){
    $this->raster = estab_nv_raster ();
    $this->border ['left']   = round (
      ($this->right - $this->raster ['breite']) / 2,
      2
    );
    $this->border ['right']  = $this->border ['left'];
    $this->border ['top']    = 9.0;
    $this->border ['bottom'] = round (
      $this->bottom - $this->border ['top'] - $this->raster ['hoehe'],
      2
    );
  }

  /** Unterkante des Nachrichtentextes (Feld 14) in Seitenkoordinaten. */
  function message_form_text_bottom (){
    return $this->border ['top'] + $this->raster ['y']['text_ende'];
  }

  /**
   * Abstand vom unteren Seitenrand, ab dem der Nachrichtentext umbricht.
   *
   * Der Vordruck bricht nicht dort um, wo die Seite endet, sondern dort, wo
   * das Feld endet. Alles darunter gehoert schon wieder dem Vordruck.
   */
  function message_form_break_margin (){
    return $this->bottom - $this->message_form_text_bottom ();
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
                                    $this->color_eintrag, $this->fontsize00, "b", "o", "l", $anhang."   ", $link) ;
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

  /*****************************************************************************
     Zeichnen auf dem Blatt.

     Alle Masse sind Blattkoordinaten in Millimetern. Die Umrechnung auf die
     Seite macht ausschliesslich dieser Abschnitt; wer weiter unten ein Feld
     setzt, rechnet nicht.
  ******************************************************************************/

  function nv_linie ($x1, $y1, $x2, $y2, $staerke = null){
    $this->draw_line (
      $this->border ['left'] + $x1,
      $this->border ['top'] + $y1,
      $this->border ['left'] + $x2,
      $this->border ['top'] + $y2,
      $staerke === null ? $this->raster ['strich']['zelle'] : $staerke,
      $this->color_sw
    );
  }

  function nv_rahmen ($x1, $y1, $x2, $y2, $staerke = null){
    $this->nv_linie ($x1, $y1, $x2, $y1, $staerke);
    $this->nv_linie ($x2, $y1, $x2, $y2, $staerke);
    $this->nv_linie ($x2, $y2, $x1, $y2, $staerke);
    $this->nv_linie ($x1, $y2, $x1, $y1, $staerke);
  }

  function nv_flaeche ($x1, $y1, $x2, $y2, $farbe){
    $this->SetFillColor ($farbe ['r'], $farbe ['g'], $farbe ['b']);
    $this->Rect (
      $this->border ['left'] + $x1,
      $this->border ['top'] + $y1,
      $x2 - $x1,
      $y2 - $y1,
      "F"
    );
  }

  /** Millimeter in die Punktzaehlung der Schriftgroesse. */
  function nv_punkt ($millimeter){
    return $millimeter * 72 / 25.4;
  }

  /**
   * Setzt eine Zeile mit ihrer Oberkante auf $y.
   *
   * Mit $breite und $aus laesst sich in einer Zelle zentrieren oder rechts
   * ausrichten; ohne Breite steht der Text linksbuendig ab $x.
   */
  function nv_text (
    $x, $y, $groesse, $stil, $text,
    $breite = 0, $aus = "L", $farbe = null
  ){
    $farbe = $farbe === null ? $this->color_sw : $farbe;
    $this->SetTextColor ($farbe ['r'], $farbe ['g'], $farbe ['b']);
    $this->SetFont ("helvetica", $stil, $this->nv_punkt ($groesse));
    $this->SetXY (
      $this->border ['left'] + $x,
      $this->border ['top'] + $y
    );
    $this->Cell (
      $breite > 0 ? $breite : 1,
      $groesse,
      estab_fpdf_text (estab_message_plain_text ($text)),
      0,
      0,
      $aus
    );
  }

  /**
   * Setzt einen eingetragenen Wert einzeilig in sein Feld.
   *
   * Was nicht hineinpasst, wird gekuerzt statt in das Nachbarfeld zu laufen.
   * Die Kernschriften von FPDF rechnen in Windows-1252, deshalb ist das
   * byteweise Kuerzen nach der Umwandlung eindeutig.
   */
  function nv_wert (
    $x, $y, $breite, $text, $groesse = null, $aus = "L"
  ){
    $groesse = $groesse === null ? $this->raster ['schrift']['wert'] : $groesse;
    $klartext = preg_replace (
      "/[\\r\\n]+/",
      " ",
      estab_message_plain_text ((string) $text)
    );
    $text = estab_fpdf_text ($klartext === null ? "" : $klartext);
    $this->SetFont ("helvetica", "B", $this->nv_punkt ($groesse));
    if ($text !== "" && $this->GetStringWidth ($text) > $breite) {
      $suffix = "...";
      $platz = max (0, $breite - $this->GetStringWidth ($suffix));
      while ($text !== "" && $this->GetStringWidth ($text) > $platz) {
        $text = rtrim (substr ($text, 0, -1));
      }
      $text .= $suffix;
    }
    $this->SetTextColor (
      $this->color_eintrag ['r'],
      $this->color_eintrag ['g'],
      $this->color_eintrag ['b']
    );
    $this->SetXY (
      $this->border ['left'] + $x,
      $this->border ['top'] + $y
    );
    $this->Cell ($breite, $groesse, $text, 0, 0, $aus);
  }

  /**
   * Ein Ankreuzfeld des Vordrucks.
   *
   * $x und $y sind die linke obere Ecke, $kante die Kantenlaenge. Gesetzt
   * ist es als ausgefuellte Flaeche im Kaestchen -- so, wie die Oberflaeche
   * es zeigt, und nicht als Kreuz, das ueber den Rahmen hinausragt.
   */
  function nv_ankreuzfeld ($x, $y, $kante, $gesetzt){
    $px = $this->border ['left'] + $x;
    $py = $this->border ['top'] + $y;
    $this->SetLineWidth (0.4);
    $this->SetDrawColor (
      $this->color_sw ['r'],
      $this->color_sw ['g'],
      $this->color_sw ['b']
    );
    $this->SetFillColor (255, 255, 255);
    $this->Rect ($px, $py, $kante, $kante, "FD");
    if (!$gesetzt) {
      return;
    }
    $rand = $kante * 0.24;
    $this->SetFillColor (
      $this->color_eintrag ['r'],
      $this->color_eintrag ['g'],
      $this->color_eintrag ['b']
    );
    $this->Rect (
      $px + $rand,
      $py + $rand,
      $kante - 2 * $rand,
      $kante - 2 * $rand,
      "F"
    );
  }

  /** Hochkant von unten nach oben, wie die Zonentitel am linken Rand. */
  function nv_gedrehter_text ($x, $y, $groesse, $stil, $text){
    $this->SetTextColor (
      $this->color_sw ['r'],
      $this->color_sw ['g'],
      $this->color_sw ['b']
    );
    $this->SetFont ("helvetica", $stil, $this->nv_punkt ($groesse));
    $this->RotatedText (
      $this->border ['left'] + $x,
      $this->border ['top'] + $y,
      estab_fpdf_text (estab_message_plain_text ($text)),
      90
    );
  }

  /** Die gedruckte Feldnummer der Ausfuellanleitung in der Feldecke. */
  function nv_nummer ($x, $y, $nummer){
    $this->nv_text (
      $x,
      $y,
      $this->raster ['schrift']['nummer'],
      "",
      (string) $nummer
    );
  }

  /** Die Linie, auf der ein eingetragener Wert steht. */
  function nv_schreiblinie ($x1, $x2, $y){
    $this->nv_linie ($x1, $y, $x2, $y, 0.25);
  }

  /** Ist dieses Kaestchen der Uebermittlungsmittel angekreuzt? */
  function nv_mittel_gewaehlt (array $mittel, $gespeichert){
    $wert = estab_message_medium_storage_value ($gespeichert);
    return $wert !== null && in_array ($wert, $mittel ['werte'], true);
  }


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
  /*****************************************************************************
     Das Blatt.

     Gezeichnet wird der Vordruck selbst: Rahmen, feste Beschriftungen,
     Ankreuzfelder und die Feldnummern der Ausfuellanleitung. Die
     eingetragenen Angaben setzt writedata_without_inhalt() darueber.
  ******************************************************************************/

  function nv_blatt (){
    $this->ziele ();
    // Blatt 1 des Vordrucks ist blau. Oberflaeche und Browserdruck zeigen
    // denselben Ton; ein weisses PDF waere wieder ein anderes Blatt.
    $this->nv_flaeche (
      0,
      0,
      $this->raster ['breite'],
      $this->raster ['hoehe'],
      $this->raster ['papier']
    );
    $this->nv_rand ();
    $this->nv_zone_fernmeldezentrale ();
    $this->nv_zone_verfasser ();
    $this->nv_zone_sichter ();
  }

  /** Die linke Randspalte: Zonentitel, Durchschriften, Lochmarken. */
  function nv_rand (){
    $r = $this->raster;
    $this->nv_gedrehter_text (
      10.6, 37.4, $r ['schrift']['zonentitel'], "", "Fm-Zentrale"
    );
    $this->nv_gedrehter_text (
      10.6, 261.9, $r ['schrift']['zonentitel'], "", "Sichter"
    );
    foreach ($r ['durchschriften'] as $durchschrift) {
      $this->nv_gedrehter_text (
        $durchschrift ['x'],
        $durchschrift ['unten'],
        $r ['schrift']['durchschrift'],
        "",
        $durchschrift ['text']
      );
    }
    $this->SetLineWidth (0.25);
    $this->SetDrawColor (
      $this->color_sw ['r'],
      $this->color_sw ['g'],
      $this->color_sw ['b']
    );
    $this->SetFillColor (255, 255, 255);
    foreach ($r ['lochmarken']['y'] as $mitte) {
      $this->Circle (
        $this->border ['left'] + $r ['lochmarken']['x'],
        $this->border ['top'] + $mitte,
        $r ['lochmarken']['radius'],
        "FD"
      );
    }
    // Die beiden Balken trennen Fernmeldezentrale, Verfasser und Sichter.
    // Sie sind das einzige, was man auf Armlaenge noch erkennt.
    foreach (
      array ($r ['y']['fmz_ende'], $r ['y']['inhalt_ende']) as $balken
    ) {
      $this->nv_flaeche (
        11.2,
        $balken,
        11.2 + 173.7,
        $balken + $r ['strich']['balken'],
        $this->color_sw
      );
    }
  }

  /** Felder 1 bis 6: was die Fernmeldezentrale traegt. */
  function nv_zone_fernmeldezentrale (){
    $r = $this->raster;
    $x = $r ['x'];
    $y = $r ['y'];
    $schrift = $r ['schrift'];

    $this->nv_rahmen (
      $x ['randspalte'], $y ['fmz_oben'], $x ['blatt'], $y ['fmz_ende'],
      $r ['strich']['rahmen']
    );

    // Feld 1: das tatsaechlich benutzte Uebermittlungsmittel.
    $this->nv_linie (
      $x ['randspalte'], $y ['medium_ende'], $x ['ttb'], $y ['medium_ende']
    );
    foreach ($r ['mittel'] as $spalte => $mittel) {
      $links = $r ['mittelspalten']['feld1'][$spalte];
      $this->nv_ankreuzfeld (
        $links,
        1.3,
        $r ['kaestchen']['mittel'],
        $this->nv_mittel_gewaehlt ($mittel, $this->db_dataset ["01_medium"])
      );
      $this->nv_text (
        $links + $r ['kaestchen']['mittel'] + 1.0,
        2.1,
        $schrift ['mittel'],
        "",
        $mittel ['name']
      );
    }
    $this->nv_nummer (18.3, 5.1, 1);

    // Feld 5: das Technische Betriebsbuch fuehrt Richtung und Nummer.
    $this->nv_linie (
      $x ['ttb'], $y ['fmz_oben'], $x ['ttb'], $y ['stempel_ende']
    );
    $this->nv_text (152.4, 1.1, $schrift ['feld'], "", "Technisches");
    $this->nv_text (152.4, 5.4, $schrift ['feld'], "", "Betriebsbuch");
    $this->nv_text (152.4, 13.2, $schrift ['feld'], "", "Nr.");
    $this->nv_rahmen (159.8, 11.4, 183.5, 18.7);
    $richtung = (string) $this->db_dataset ["04_richtung"];
    $this->nv_ankreuzfeld (
      158.2, 20.0, $r ['kaestchen']['ttb'], $richtung === "E"
    );
    $this->nv_text (164.3, 20.5, $schrift ['feld'], "", "Eingang");
    $this->nv_ankreuzfeld (
      158.2, 27.3, $r ['kaestchen']['ttb'], $richtung === "A"
    );
    $this->nv_text (164.3, 27.9, $schrift ['feld'], "", "Ausgang");
    $this->nv_nummer (152.0, 31.5, 5);

    // Eingang und Ausgang teilen die drei Bearbeitungsvermerke.
    $this->nv_linie (
      $x ['randspalte'], $y ['richtung_ende'],
      $x ['ttb'], $y ['richtung_ende']
    );
    $this->nv_text (
      $x ['raster'], 7.8, $schrift ['feld'], "", "Eingang",
      $x ['eingang_ende'] - $x ['raster'], "C"
    );
    $this->nv_text (
      $x ['eingang_ende'], 7.8, $schrift ['feld'], "", "Ausgang",
      $x ['ttb'] - $x ['eingang_ende'], "C"
    );
    $this->nv_linie (
      $x ['eingang_ende'], $y ['medium_ende'],
      $x ['eingang_ende'], $y ['richtung_ende']
    );

    // Felder 2 bis 4: Aufnahme-, Annahme- und Befoerderungsvermerk.
    $this->nv_linie (
      $x ['randspalte'], $y ['stempel_ende'],
      $x ['ttb'], $y ['stempel_ende']
    );
    foreach ($r ['vermerke'] as $vermerk) {
      if ($vermerk ['links'] > $x ['raster']) {
        $this->nv_linie (
          $vermerk ['links'], $y ['richtung_ende'],
          $vermerk ['links'], $y ['stempel_ende']
        );
      }
      $this->nv_text (
        $vermerk ['links'], 13.2, $schrift ['feld'], "B", $vermerk ['titel'],
        $vermerk ['rechts'] - $vermerk ['links'], "C"
      );
      foreach (
        array ($vermerk ['datum'], $vermerk ['zeit']) as $teiler
      ) {
        $this->nv_linie (
          $teiler, $y ['stempel_kopf'], $teiler, $y ['stempel_ende']
        );
      }
      $this->nv_linie (
        $vermerk ['links'], $y ['stempel_fuss'],
        $vermerk ['rechts'], $y ['stempel_fuss']
      );
      $spalten = array (
        array ($vermerk ['links'], $vermerk ['datum'], "Datum"),
        array ($vermerk ['datum'], $vermerk ['zeit'], "Uhrzeit"),
        array ($vermerk ['zeichen'], $vermerk ['rechts'], "Hdz."),
      );
      foreach ($spalten as $spalte) {
        $this->nv_text (
          $spalte [0], 29.2, $schrift ['klein'], "", $spalte [2],
          $spalte [1] - $spalte [0], "C"
        );
        $this->nv_schreiblinie ($spalte [0] + 1.2, $spalte [1] - 1.2, 26.9);
      }
      $this->nv_nummer (
        $vermerk ['links'] + 0.7, 31.2, $vermerk ['nummer']
      );
    }

    // Feld 6: Rufname der Gegenstelle.
    $this->nv_linie (72.7, $y ['stempel_ende'], 72.7, $y ['fmz_ende']);
    $this->nv_text (
      18.5, 34.4, $schrift ['feld'], "", "Rufname der Gegenstelle"
    );
    $this->nv_text (19.4, 38.5, $schrift ['mittel'], "", "Spruchkopf");
    $this->nv_schreiblinie (74.2, 183.2, 43.1);
    $this->nv_nummer (18.3, 44.2, 6);
  }

  /** Felder 7 bis 17: was der Verfasser traegt. */
  function nv_zone_verfasser (){
    $r = $this->raster;
    $x = $r ['x'];
    $y = $r ['y'];
    $schrift = $r ['schrift'];

    $this->nv_linie (
      $x ['randspalte'], $y ['inhalt_oben'],
      $x ['randspalte'], $y ['inhalt_ende']
    );
    $this->nv_linie (
      $x ['blatt'], $y ['inhalt_oben'], $x ['blatt'], $y ['inhalt_ende']
    );

    // Feld 7: das gewuenschte Uebermittlungsmittel.
    foreach ($r ['mittel'] as $spalte => $mittel) {
      $links = $r ['mittelspalten']['feld7'][$spalte];
      $this->nv_ankreuzfeld (
        $links,
        48.9,
        $r ['kaestchen']['mittel'],
        $this->nv_mittel_gewaehlt ($mittel, $this->db_dataset ["06_befwegausw"])
      );
      $this->nv_text (
        $links + $r ['kaestchen']['mittel'] + 1.0,
        49.7,
        $schrift ['mittel'],
        "",
        $mittel ['name']
      );
    }
    $this->nv_nummer (18.3, 53.4, 7);
    $this->nv_linie (
      $x ['randspalte'], $y ['weg_ende'], $x ['blatt'], $y ['weg_ende']
    );

    // Feld 8: Durchsage oder Spruch. Keines von beiden ist vorbelegt.
    $durchspruch = (string) $this->db_dataset ["07_durchspruch"];
    $this->nv_ankreuzfeld (
      19.4, 57.3, $r ['kaestchen']['art'], $durchspruch === "D"
    );
    $this->nv_text (25.7, 58.1, $schrift ['feld'], "", "DURCHSAGE");
    $this->nv_ankreuzfeld (
      52.4, 57.3, $r ['kaestchen']['art'], $durchspruch === "S"
    );
    $this->nv_text (58.7, 58.1, $schrift ['feld'], "", "SPRUCH");
    $this->nv_nummer (18.3, 61.8, 8);

    // Feld 9: die Vorrangstufe. Ohne besondere Stufe bleibt das Feld leer.
    $this->nv_linie (
      $x ['zeichen'], $y ['weg_ende'], $x ['zeichen'], $y ['art_ende']
    );
    $stufe = (string) $this->db_dataset ["09_vorrangstufe"];
    $this->nv_ankreuzfeld (
      121.6, 57.9, $r ['kaestchen']['vorrang'], $stufe === "Sofort"
    );
    $this->nv_text (125.8, 58.4, $schrift ['hinweis'], "", "Sofort");
    $this->nv_ankreuzfeld (
      134.3, 57.9, $r ['kaestchen']['vorrang'], $stufe === "Blitz"
    );
    $this->nv_text (138.5, 58.4, $schrift ['hinweis'], "", "Blitz");
    if ($stufe !== "" && $stufe !== "Sofort" && $stufe !== "Blitz") {
      // Der Ausdruck darf kein Ankreuzfeld vortaeuschen, das der Vordruck
      // nicht hat. Eine weitere Stufe wird deshalb benannt, nicht gekreuzt.
      $this->nv_text (
        144.8, 58.4, $schrift ['hinweis'], "", "Vorrangstufe: " . $stufe
      );
    }
    $this->nv_nummer (120.5, 61.8, 9);
    $this->nv_linie (
      $x ['randspalte'], $y ['art_ende'], $x ['blatt'], $y ['art_ende']
    );

    // Felder 10 und 11: Anschrift und Rufnummer.
    $this->nv_linie (
      $x ['kopfspalte'], $y ['art_ende'],
      $x ['kopfspalte'], $y ['adresse_ende']
    );
    $this->nv_linie (
      $x ['randspalte'], $y ['anschrift_ende'],
      $x ['kopfspalte'], $y ['anschrift_ende']
    );
    $this->nv_text (18.5, 64.9, $schrift ['feld'], "", "Anschrift:");
    $this->nv_nummer (18.3, 70.7, 10);
    $this->nv_schreiblinie (52.1, 149.9, 71.8);
    $this->nv_text (18.5, 73.8, $schrift ['feld'], "", "Ruf Nr.");
    $this->nv_nummer (18.3, 84.9, 11);
    $this->nv_schreiblinie (52.4, 149.9, 83.2);

    // Feld 12: die Gespraechsnotiz steht neben Anschrift und Rufnummer.
    $this->nv_linie (
      $x ['notiz'], $y ['art_ende'], $x ['notiz'], $y ['adresse_ende']
    );
    $this->nv_linie (
      $x ['notiz'], $y ['anschrift_ende'],
      $x ['blatt'], $y ['anschrift_ende']
    );
    $this->nv_text (152.4, 64.7, $schrift ['feld'], "", "GESPRÄCHS-");
    $this->nv_text (152.4, 68.7, $schrift ['feld'], "", "NOTIZ");
    $this->nv_ankreuzfeld (
      164.3,
      76.0,
      $r ['kaestchen']['notiz'],
      $this->db_dataset ["11_gesprnotiz"] == true
    );
    $this->nv_nummer (152.4, 84.9, 12);
    $this->nv_linie (
      $x ['randspalte'], $y ['adresse_ende'],
      $x ['blatt'], $y ['adresse_ende']
    );

    // Feld 13: der Betreff steht in der Kopfzeile des Inhalts.
    $this->nv_linie (
      $x ['kopfspalte'], $y ['adresse_ende'],
      $x ['kopfspalte'], $y ['betreff_ende']
    );
    $this->nv_text (18.5, 88.0, $schrift ['feld'], "", "Inhalt");
    $this->nv_nummer (18.3, 94.8, 13);
    $this->nv_schreiblinie (52.1, 183.1, 95.2);
    $this->nv_linie (
      $x ['randspalte'], $y ['betreff_ende'],
      $x ['blatt'], $y ['betreff_ende']
    );

    // Feld 14: der Nachrichtentext. Die Linien traegt das Papier, auch
    // wenn niemand darauf schreibt.
    $zeile = $y ['betreff_ende'] + $r ['zeilenhoehe'];
    while ($zeile < $y ['text_ende'] - 0.5) {
      $this->nv_schreiblinie ($x ['raster'], $x ['blatt'] - 0.2, $zeile);
      $zeile += $r ['zeilenhoehe'];
    }
    $this->nv_nummer (18.3, 192.9, 14);
    $this->nv_linie (
      $x ['randspalte'], $y ['text_ende'], $x ['blatt'], $y ['text_ende']
    );

    // Feld 15: der Absender.
    $this->nv_linie (
      $x ['kopfspalte'], $y ['text_ende'],
      $x ['kopfspalte'], $y ['absender_ende']
    );
    $this->nv_text (18.5, 196.0, $schrift ['feld'], "", "Absender:");
    $this->nv_nummer (18.3, 203.4, 15);
    $this->nv_schreiblinie (52.1, 183.1, 203.5);
    $this->nv_linie (
      $x ['randspalte'], $y ['absender_ende'],
      $x ['blatt'], $y ['absender_ende']
    );

    // Feld 16: die Abfassungszeit.
    $this->nv_linie (
      $x ['kopfspalte'], $y ['absender_ende'],
      $x ['kopfspalte'], $y ['abfassung_ende']
    );
    $this->nv_linie (
      $x ['abfassung_ende'], $y ['absender_ende'],
      $x ['abfassung_ende'], $y ['abfassung_ende']
    );
    $this->nv_text (18.5, 206.6, $schrift ['feld'], "", "Abfassungszeit:");
    $this->nv_nummer (18.3, 214.0, 16);
    $this->nv_schreiblinie (52.1, 117.2, 214.1);
    $this->nv_linie (
      $x ['randspalte'], $y ['abfassung_ende'],
      $x ['blatt'], $y ['abfassung_ende']
    );

    // Feld 17: Einheit, Zeichen und Funktion des Verfassers.
    $this->nv_linie (
      $x ['zeichen'], $y ['abfassung_ende'],
      $x ['zeichen'], $y ['inhalt_ende']
    );
    $this->nv_linie (
      $x ['funktion'], $y ['abfassung_ende'],
      $x ['funktion'], $y ['inhalt_ende']
    );
    $this->nv_schreiblinie (18.9, 118.2, 223.2);
    $this->nv_text (
      18.9, 223.6, $schrift ['feld'], "", "Einheit/Einrichtung/Stelle",
      99.3, "C"
    );
    $this->nv_rahmen (121.1, 216.9, 151.6, 223.5);
    $this->nv_text (
      121.1, 223.9, $schrift ['feld'], "", "Zeichen", 30.5, "C"
    );
    $this->nv_schreiblinie (154.5, 183.3, 223.5);
    $this->nv_text (
      154.5, 223.9, $schrift ['feld'], "", "Funktion", 28.8, "C"
    );
    $this->nv_nummer (120.5, 225.9, 17);
  }

  /** Felder 18 bis 20: was der Sichter traegt. */
  function nv_zone_sichter (){
    $r = $this->raster;
    $x = $r ['x'];
    $y = $r ['y'];
    $schrift = $r ['schrift'];

    $this->nv_rahmen (
      $x ['randspalte'], $y ['sichter_oben'],
      $x ['blatt'], $y ['sichter_ende'],
      $r ['strich']['rahmen']
    );
    $this->nv_linie (
      $x ['sichter_teiler'], $y ['sichter_oben'],
      $x ['sichter_teiler'], $y ['sichter_ende']
    );

    // Feld 18: die Quittung.
    $this->nv_linie (17.6, 237.3, 119.2, 237.3);
    $this->nv_linie (55.6, $y ['sichter_oben'], 55.6, 237.3);
    $this->nv_text (18.5, 229.7, $schrift ['feld'], "", "Quittung:");
    $this->nv_schreiblinie (56.9, 96.8, 236.4);
    $this->nv_schreiblinie (97.9, 117.8, 236.4);
    $this->nv_text (
      17.6, 237.9, $schrift ['feld'], "", "Uhrzeit", 50.8, "C"
    );
    $this->nv_text (
      68.4, 237.9, $schrift ['feld'], "", "Zeichen", 50.8, "C"
    );
    $this->nv_nummer (18.3, 240.6, 18);
    $this->nv_linie (
      $x ['randspalte'], $y ['quittung_ende'],
      $x ['sichter_teiler'], $y ['quittung_ende']
    );

    // Feld 19: der Verteiler in seinen drei gedruckten Bloecken.
    $this->nv_verteiler ();
    $this->nv_nummer (18.3, 276.5, 19);

    // Feld 20: Vermerke und Erledigung.
    $this->nv_linie (
      $x ['sichter_teiler'], 238.2, $x ['blatt'], 238.2
    );
    $this->nv_text (120.2, 229.7, $schrift ['feld'], "", "Vermerke:");
    $this->nv_nummer (120.2, 276.5, 20);
  }

  /**
   * Feld 19: die Empfaengermatrix als die drei Bloecke des Vordrucks.
   *
   * Fuehrung, Fachberater und Verbindungsstellen -- dieselbe Zuordnung, die
   * die Bildschirmansicht benutzt (app/nv_verteiler.php). Ein Empfaenger,
   * der gespeichert ist, aber in keiner Zelle der Matrix mehr steht, hat auf
   * dem Blatt keinen Platz; er wird unter dem Nachrichtentext benannt.
   */
  function nv_verteiler (){
    $r = $this->raster;
    $schrift = $r ['schrift'];
    $modell = estab_nv_verteiler_modell (
      is_array ($this->empfarray) ? $this->empfarray : array (),
      estab_nv_gespeicherte_empfaenger (
        (string) $this->db_dataset ["16_empf"]
      )
    );
    $ueberschriften = estab_nv_verteiler_ueberschriften ();
    $bloecke = array (
      "lead" => array ("x" => 19.3, "breite" => 40.3),
      "adviser" => array ("x" => 60.7, "breite" => 27.8),
      "liaison" => array ("x" => 89.7, "breite" => 27.8),
    );
    $kante = $r ['kaestchen']['verteiler'];
    foreach ($bloecke as $name => $block) {
      $this->nv_text (
        $block ['x'], 243.4, $schrift ['gruppe'], "B", $ueberschriften [$name]
      );
      $eintraege = $modell ['groups'][$name] ?? array ();
      if ($name === "lead") {
        // Der Leiter steht ueber der Spalte der Sachgebiete, nicht in ihr.
        $this->nv_verteiler_eintrag (
          19.3, 247.0, 17.4, $kante, $eintraege [0] ?? null
        );
        foreach (array_slice ($eintraege, 1) as $platz => $eintrag) {
          $this->nv_verteiler_eintrag (
            37.4, 247.4 + $platz * 4.78, 22.2, $kante, $eintrag
          );
        }
        continue;
      }
      foreach ($eintraege as $platz => $eintrag) {
        $this->nv_verteiler_eintrag (
          $block ['x'],
          247.0 + $platz * 5.05,
          $block ['breite'],
          $kante,
          $eintrag
        );
      }
    }
  }

  /** Ein Platz des Verteilers: Kaestchen, Name und die Linie darunter. */
  function nv_verteiler_eintrag ($x, $y, $breite, $kante, $eintrag){
    $r = $this->raster;
    $this->nv_schreiblinie ($x, $x + $breite, $y + $kante + 0.6);
    if (!is_array ($eintrag)) {
      return;
    }
    $name = estab_function_display_name (
      (string) ($eintrag ['display'] ?? $eintrag ['function'] ?? "")
    );
    $unbesetzt = (bool) ($eintrag ['unavailable'] ?? false);
    $gewaehlt = is_array ($eintrag ['copies'] ?? null)
      && $eintrag ['copies'] !== array ();
    if (!$unbesetzt || $gewaehlt) {
      $this->nv_ankreuzfeld ($x, $y, $kante, $gewaehlt);
    } else {
      // Ein Platz, den die Matrix nicht besetzt, bleibt ein leerer Kasten.
      $this->nv_ankreuzfeld ($x, $y, $kante, false);
    }
    if ($name === "") {
      return;
    }
    $this->nv_text (
      $x + $kante + 0.9,
      $y + 0.2,
      $r ['schrift']['verteiler'],
      "",
      $name,
      max (0.0, $breite - $kante - 0.9),
      "L"
    );
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
        $this->empfarray [$i][$j]["fkt"]     = $empf_matrix [$i][$j]["fkt"];
        // Die Rolle entscheidet, in welchen der drei gedruckten Bloecke
        // von Feld 19 die Zelle gehoert (app/nv_verteiler.php).
        $this->empfarray [$i][$j]["rolle"]   =
          isset ($empf_matrix [$i][$j]["rolle"])
            ? $empf_matrix [$i][$j]["rolle"]
            : "";
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
      $displayFunction = estab_function_display_name ((string) $fkt);
      $empf_array [] = array (
        "fkt" => $fkt,
        "cpy" => $cpycol,
        "display" => $cpycol == ""
          ? $displayFunction
          : $displayFunction . " [" . $cpycol . "]"
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




  /**
   * Zerlegt eine taktische Zeitangabe in Datum und Uhrzeit.
   *
   * Gespeichert wird "011847sep2026": Tag, Uhrzeit, Monat, Jahr in einem
   * Stueck. Der Vordruck fuehrt dafuer zwei Spalten. Was nicht dem Muster
   * folgt, bleibt ungeteilt in der Datumsspalte -- lieber ganz lesbar als
   * halb falsch getrennt.
   */
  function nv_taktzeit_teile ($taktzeit){
    $taktzeit = trim ((string) $taktzeit);
    if (preg_match ("/\A(\d{2})(\d{4})([a-z]{3})(\d{4})\z/D", $taktzeit, $teile) === 1) {
      return array ($teile [1] . $teile [3] . $teile [4], $teile [2]);
    }
    return array ($taktzeit, "");
  }

  /**
   * Setzt einen mehrzeiligen Wert in seinen Kasten.
   *
   * Der Umbruch geschieht an Wortgrenzen. Was nicht mehr in den Kasten
   * passt, wird mit Auslassung abgeschnitten, statt ueber den Rahmen in das
   * naechste Feld zu laufen.
   */
  function nv_textblock ($x, $y, $breite, $hoehe, $text, $groesse = null){
    $groesse = $groesse === null ? $this->raster ['schrift']['wert'] : $groesse;
    $zeilenhoehe = $groesse * 1.35;
    $plaetze = (int) floor ($hoehe / $zeilenhoehe);
    if ($plaetze < 1) {
      return;
    }
    $klartext = estab_message_plain_text ((string) $text);
    $this->SetFont ("helvetica", "B", $this->nv_punkt ($groesse));
    $zeilen = array ();
    foreach (preg_split ("/\R/", $klartext) as $absatz) {
      $zeile = "";
      foreach (preg_split ("/\s+/", trim ($absatz)) as $wort) {
        if ($wort === "") {
          continue;
        }
        $versuch = $zeile === "" ? $wort : $zeile . " " . $wort;
        if (
          $this->GetStringWidth (estab_fpdf_text ($versuch)) <= $breite
          || $zeile === ""
        ) {
          $zeile = $versuch;
          continue;
        }
        $zeilen [] = $zeile;
        $zeile = $wort;
      }
      $zeilen [] = $zeile;
    }
    $zeilen = array_values (array_filter (
      $zeilen,
      static function ($zeile) { return $zeile !== ""; }
    ));
    $abgeschnitten = count ($zeilen) > $plaetze;
    $zeilen = array_slice ($zeilen, 0, $plaetze);
    if ($abgeschnitten && $zeilen !== array ()) {
      $zeilen [count ($zeilen) - 1] .= " ...";
    }
    foreach ($zeilen as $nummer => $zeile) {
      $this->nv_wert (
        $x, $y + $nummer * $zeilenhoehe, $breite, $zeile, $groesse
      );
    }
  }

/*******************************************************************************/
  /**
   * Setzt alle eingetragenen Angaben ausser dem Nachrichtentext.
   *
   * Der Nachrichtentext steht allein, weil nur er ueber Seiten laeuft.
   */
  function writedata_without_inhalt (){
    $r = $this->raster;
    $schrift = $r ['schrift'];

    // Felder 2 bis 4: Datum, Uhrzeit und Handzeichen der drei Vermerke.
    $eintraege = array (
      array ($this->db_dataset ["01_datum"], $this->db_dataset ["01_zeichen"]),
      array ($this->db_dataset ["02_zeit"], $this->db_dataset ["02_zeichen"]),
      array ($this->db_dataset ["03_datum"], $this->db_dataset ["03_zeichen"]),
    );
    foreach ($r ['vermerke'] as $platz => $vermerk) {
      $geteilt = $this->nv_taktzeit_teile ($eintraege [$platz][0]);
      $this->nv_wert (
        $vermerk ['links'], 22.8, $vermerk ['datum'] - $vermerk ['links'],
        $geteilt [0], $schrift ['klein'], "C"
      );
      $this->nv_wert (
        $vermerk ['datum'], 22.8, $vermerk ['zeit'] - $vermerk ['datum'],
        $geteilt [1], $schrift ['klein'], "C"
      );
      $this->nv_wert (
        $vermerk ['zeichen'], 22.8,
        $vermerk ['rechts'] - $vermerk ['zeichen'],
        $eintraege [$platz][1], $schrift ['klein'], "C"
      );
    }

    // Feld 5: die Nummer im Technischen Betriebsbuch. Sie fehlt, solange
    // die Nachricht dort keinen Nachweis hat -- eine interne Notiz bekommt
    // nie einen.
    $nachweis = trim ((string) $this->db_dataset ["04_nummer"]);
    $this->nv_wert (
      160.3, 13.2, 22.7,
      $nachweis === "" ? "noch kein TBB-Nachweis" : $nachweis,
      $nachweis === "" ? $schrift ['durchschrift'] * 1.6 : $schrift ['wert'],
      "C"
    );

    // Feld 6: Rufname der Gegenstelle und der benutzte Befoerderungsweg.
    // Der Weg gehoert nach der Ausfuellanleitung in dieses Feld; er wird
    // benannt und nicht als Kaestchen vorgetaeuscht.
    $weg = trim ((string) $this->db_dataset ["06_befweg"]);
    if ($weg !== "") {
      $this->nv_text (74.2, 34.8, $schrift ['hinweis'], "", "Beförderungsweg");
      $this->nv_wert (96.2, 34.8, 87.0, $weg, $schrift ['hinweis']);
    }
    $this->nv_wert (74.2, 39.4, 109.0, $this->db_dataset ["05_gegenstelle"]);

    // Felder 10, 11 und 13.
    $this->nv_wert (52.1, 67.4, 97.8, $this->db_dataset ["10_anschrift"]);
    $this->nv_wert (52.4, 79.8, 97.5, $this->db_dataset ["11_rufnummer"]);
    $this->nv_wert (52.1, 91.8, 131.0, $this->db_dataset ["12_betreff"]);

    // Felder 15 bis 17.
    $this->nv_wert (52.1, 200.1, 131.0, $this->db_dataset ["13_abseinheit"]);
    $this->nv_wert (52.1, 210.7, 65.1, $this->db_dataset ["12_abfzeit"]);
    $this->nv_wert (
      121.6, 218.4, 29.5, $this->db_dataset ["14_zeichen"],
      $schrift ['wert'], "C"
    );
    $this->nv_wert (154.5, 220.1, 28.8, $this->db_dataset ["14_funktion"]);

    // Feld 18: die Quittung des Sichters.
    $quittung = $this->nv_taktzeit_teile ($this->db_dataset ["15_quitdatum"]);
    $this->nv_wert (
      56.9, 233.0, 39.9,
      trim ($quittung [0] . " " . $quittung [1]),
      $schrift ['klein'], "C"
    );
    $this->nv_wert (
      97.9, 233.0, 19.9, $this->db_dataset ["15_quitzeichen"],
      $schrift ['klein'], "C"
    );

    // Feld 20: Vermerke und Erledigung.
    $this->nv_textblock (
      120.7, 239.4, 63.3, 36.4, $this->db_dataset ["17_vermerke"],
      $schrift ['klein']
    );
  }


/*******************************************************************************/

  /**
   * Feld 14: der Nachrichtentext, und was ihm sonst noch anhaengt.
   *
   * Als einziges Feld laeuft der Text ueber Seiten. Jede Folgeseite traegt
   * denselben Vordruck; nur der Text setzt sich fort.
   */
  function writedata_inhalt () {
    $r = $this->raster;
    $this->set_message_content_continuation_position ();
    $this->SetTextColor (
      $this->color_eintrag ['r'],
      $this->color_eintrag ['g'],
      $this->color_eintrag ['b']
    );
    $this->SetFont ("helvetica", "B", $this->nv_punkt ($r ['schrift']['wert']));
    // Linksbuendig, nicht im Blocksatz: auf dem Vordruck wird geschrieben,
    // nicht gesetzt, und gedehnte Wortabstaende erschweren das Lesen.
    $this->MultiCell (
      $r ['x']['blatt'] - $r ['x']['raster'] - 3.4,
      $r ['zeilenhoehe'],
      estab_fpdf_text (
        estab_message_plain_text ($this->db_dataset ["12_inhalt"])
      ),
      0,
      "L"
    );
    // MultiCell() setzt die Schreibmarke auf den Seitenrand zurueck. Die
    // Anlagen und die zusaetzlichen Empfaenger gehoeren aber unter den Text,
    // also in dieselbe Einrueckung -- sonst stehen sie am Blattrand.
    $textrand = $this->border ['left'] + $r ['x']['raster'] + 1.7;
    $y = $this->GetY ();

    if (count ($this->unmatchedRecipientLabels) > 0) {
      $this->SetXY ($textrand, $y);
      $this->SetTextColor (
        $this->color_eintrag ["r"],
        $this->color_eintrag ["g"],
        $this->color_eintrag ["b"]
      );
      $this->SetFont ("helvetica", "B", $this->nv_punkt ($r ['schrift']['klein']));
      $this->MultiCell (
        $r ['x']['blatt'] - $r ['x']['raster'] - 3.4,
        $r ['zeilenhoehe'],
        estab_fpdf_text (
          "Empfänger außerhalb aktueller Matrix: "
          . implode (", ", $this->unmatchedRecipientLabels)
        ),
        0,
        "L"
      );
      $y = $this->GetY ();
    }

    if ($this->db_dataset ["12_anhang"] != ""){
      $this->list_anhang ($textrand, $y);
    }
  }



/*******************************************************************************/
  /** Die Stelle, an der der Nachrichtentext beginnt und fortgesetzt wird. */
  function set_message_content_continuation_position () {
    $this->SetXY (
      $this->border ['left'] + $this->raster ['x']['raster'] + 1.7,
      $this->border ['top'] + $this->raster ['y']['betreff_ende'] + 0.6
    );
  }

/*******************************************************************************/
  function Footer () {
    include (__DIR__ . "/../4fcfg/config.inc.php");    // Konfigurationseinstellungen und Vorgaben

    // Seitenzahl und Herkunftsvermerk stehen unter dem Blatt, nicht darauf.
    // Auf dem Papiervordruck gibt es sie nicht; im Abzug braucht sie, wer
    // einen mehrseitigen Nachrichtentext zusammenhalten muss.
    $fuss = $this->border ['top'] + $this->raster ['hoehe'] + 2.0;
    $this->SetTextColor (110, 110, 110);
    $this->SetFont ("helvetica", "", $this->nv_punkt (2.6));
    $this->SetXY ($this->border ['left'], $fuss);
    $this->Cell (
      $this->raster ['breite'] / 2,
      3.0,
      estab_fpdf_text (
        "eStab " . $conf_4f ['Version']
        . " · (C) 2005 bis 2013 Hajo Landmesser"
      ),
      0,
      0,
      "L"
    );
    $this->SetXY (
      $this->border ['left'] + $this->raster ['breite'] / 2,
      $fuss
    );
    $this->Cell (
      $this->raster ['breite'] / 2,
      3.0,
      estab_fpdf_text ("Seite " . $this->PageNo () . "/{nb}"),
      0,
      0,
      "R"
    );
  }

/*******************************************************************************/
  function Header (){
    $this->nv_blatt ();
    $this->writedata_without_inhalt ();
  }

/*******************************************************************************/
  function AcceptPageBreak() {
    if (
      $this->GetY () >= $this->message_form_text_bottom ()
        - $this->raster ['zeilenhoehe']
    ) {
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
      $this->messageNumber,
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
