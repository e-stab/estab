<?php
/******************************************************************************\
|  Mehrfach Schaltergenerator
|  Es sollen Schalter folgender Art hergestellt werden:
|    1. Kippschalter Aus/Ein
|    2. Druckschalter Rund ohne Füllung Aus / mit Füllung (ggf farbig) Ein
|    3. Radioschalter mehrere Schalter nur einer ist aktiv
|
|
\******************************************************************************/

class createbutton {

  var $white ;
  var $yellow ;
  var $blue ;
  var $red ;
  var $green ;
  var $lightyellow ;
  var $lightblue ;
  var $lightred ;
  var $lightgreen ;
  var $black ;

  var $tumbler ; // array der Kippschalterparameter
  var $push ;    // array der Druckschalterparameter
  var $radioswt ;   // array der Radioschalterparameter

  var $status ;
  var $form   ;
  var $oncol  ;
  var $color ;
  var $textpos ;

  var $text ;
  var $textarr ;

  var $font_size ;
  var $height ;
  var $width ;

  var $bg ;
  var $textcol ;
  var $bordercol ;

  var $img ;

/*******************************************************************************\
   Funktion: createbutton ()
   Konstruktor der Klasse createbutton

   Argumente werden mit der $_GET Variable übergeben.

   Beim Radiobutton wird zwischen der Grafikerzeugung und der übermittlung der
   map-Daten unterschieden.

     1. "tumbler" Kippschalter
       a. status  0 oder 1 für Kippschalter nach oben oder unten
       b. ontext  Beschriftungstext oben
       c. offtext Beschriftungstext unten

     http://1service.no-ip.org/kats/4fach/buttonxxx.php?type=push&status=EIN&text=erledigt
     2. "push"    Druckschalter
       a. status  0 oder 1 für Druckschalter eingedrückt oder nicht
       b. form    rund oder quadratisch
       c. oncol   on color farbe wenn eingeschaltet
       d. text    Schalterbeschriftung
       e. textpos Textposition rechts, links, drüber oder drunter

     3. "radio"   Radioschalter
       "switches" Schalter
       a. status  ein oder aus
       b. text    Schaltertext
       c. semikolon ; zum Trennen der Schalterabschnitte
         Beispiel: 1,Text1;0,Text2;0,Text3


\********************************************************************************/
  function createbutton (){


    $this->font_size = 5;

    switch ($_GET["type"]){
      case "tumbler ": //Kippschalter
        $this->tumbler = $_GET ;
      break;
      case "push":
        $this->status = $_GET [status];
        $this->form   = $_GET [form];
        $this->oncol  = $_GET [oncol];
        $this->text   = $_GET [text];
        $this->textpos= $_GET [textpos];
        $this->create_push_button ();
      break;
      case "radio":
         // einzelne Schalterwerte in swt speichern
        $this->radioswt = split (";", $_GET["switches"]);
      break;
      case "icon":
        $this->status = $_GET [status];  // EIN oder AUS
        if (isset($_GET [bg]       )){$this->bg        = $_GET [bg]       ;}else{ $this->bg        = lighterblue;}    // Hintergrundfarbe
        if (isset($_GET [textcol]  )){$this->textcol   = $_GET [textcol]  ;}else{ $this->textcol   = black;}// Text farbe
        if (isset($_GET [bordercol])){$this->bordercol = $_GET [bordercol];}else{ $this->bordercol = black;}// Linienfarbe
        $this->color  = $_GET [color];
        if (isset ($_GET [font_size])) {$this->font_size = $_GET [font_size] ;}
        $this->text   = $_GET [text];
        $this->textpos= $_GET [textpos]; // in or under

        $this->create_icon_button ();
      break;
        // Menuetaster
      case "menue":

        if (isset($_GET [bg]))    {$this->m_bg   = $_GET [bg]       ;}else{ $this->m_bg      = lighterblue;}    // Hintergrundfarbe
        if (isset($_GET [m_tc]))  {$this->m_tc   = $_GET [textcol]  ;}else{ $this->textcol   = black;}// Text farbe
        if (isset($_GET [m_bc]))  {$this->m_bc   = $_GET [bordercol];}else{ $this->bordercol = black;}// Linienfarbe
        if (isset($_GET [m_form])){$this->m_form = $_GET [m_form];   }else{ $this->m_form    = "spitz";}
        if (isset($_GET [font_size])) {$this->font_size = $_GET [font_size] ;}
        if (isset($_GET [m_text])){$this->m_text = $_GET [m_text];   }else{ $this->m_text    = "!Textfehler!";}
        if (isset($_GET [m_fs])){$this->m_fs = $_GET [m_fs];         }   // Fontsize
        if (isset($_GET [width])){$this->m_width = $_GET [width];    }   // Wunschbreite
        $this->create_menue_button ();
      break;
    }
  }

  function set_color(){
    $this->white       = imagecolorallocate($this->img,255,255,255);
    $this->yellow      = imagecolorallocate($this->img,225,225,150);
    $this->blue        = imagecolorallocate($this->img,150,150,255);
    $this->red         = imagecolorallocate($this->img,255,100,100);
    $this->green       = imagecolorallocate($this->img, 80,255, 80);
    $this->lightyellow = imagecolorallocate($this->img,225,225,200);
    $this->lightblue   = imagecolorallocate($this->img,200,200,255);
    $this->lighterblue = imagecolorallocate($this->img,220,220,255);
    $this->mlightblue  = imagecolorallocate($this->img,220,220,255);
    $this->lightred    = imagecolorallocate($this->img,255,200,200);
    $this->lightgreen  = imagecolorallocate($this->img,200,255,200);
    $this->black       = imagecolorallocate($this->img,  0,  0,  0);
  }

  function get_color ($colorwords){
    switch ($colorwords){
      case "white":       return ($this->white);       break;
      case "yellow":      return ($this->yellow);      break;
      case "blue":        return ($this->blue);        break;
      case "red":         return ($this->red);         break;
      case "green":       return ($this->green);       break;
      case "lightyellow": return ($this->lightyellow); break;
      case "lightblue":   return ($this->lightblue);   break;
      case "lighterblue": return ($this->lighterblue); break;
      case "mlightblue":  return ($this->mlightblue);  break;
      case "lightred":    return ($this->lightred);    break;
      case "lightgreen":  return ($this->lightgreen);  break;
      case "black":       return ($this->black);       break;
      default ;  return ($this->black);
    }
  }

  /*******************************************************************************
   Funktion: katego_icon ()
     $color =
      "blue"       "red"      "yellow"      "green"
      "lightblue"  "lightred" "lightyellow" "lightgreen"

  ********************************************************************************/
  function create_icon_button (){

    $this->height = imagefontheight($this->font_size)*1.5;
    $fontwidth    = imagefontwidth ($this->font_size)*strlen($this->text);
    $this->width  = $fontwidth + $this->height;
    $this->img = imagecreate($this->width, $this->height);
    $this->set_color ();
      // Hintergrundfrage
    imagefill  ( $this->img, 1, 1, $this->get_color ($this->bg) );
    $len=strlen($this->text);
      // Rahmen
    if ($this->status == "EIN"){
      imagerectangle  ( $this->img , 0, 0, $this->width-1 , $this->height-1, $this->get_color ($this->bordercol) ) ;
    } elseif ($this->status == "AUS") {
      imageline ( $this->img ,   0, $this->height-1, 0, 0 , $this->get_color ($this->bordercol) );
      imageline ( $this->img ,   0, 0, $this->width-1,  0, $this->get_color ($this->bordercol) );
      imageline ( $this->img , $this->width-1,  0, $this->width-1,  $this->height-1, $this->get_color ($this->bordercol) );
    }
    $xpos= ($this->width - $fontwidth) / 2 ;//$i*imagefontwidth($font_size);
    $ypos= ($this->height - imagefontheight($this->font_size) ) / 2 ;
    imagestring($this->img, $this->font_size, $xpos, $ypos, $this->text, $this->get_color ($this->textcol) );
    header("Content-Type: image/png");
    imagepng($this->img);
    imagedestroy($this->img);
  }



/*******************************************************************************\
   Funktion: create_push_button ()
     $text  : Text Auf dem Button
     $color : Farbe des Button als
       Array[
         hf : (R,G,B), Hintergrundfarbe
         lf : (R,G,B), Randlinienfarbe
         tf : (R,G,B), Textfarbe
       ]
     $form (ecken, gerundet, elypse)

\********************************************************************************/
  function create_push_button (){
    $switchwidth  = 20 ; // Schalterbreite
    $switchheight = 30 ; // Schalterhöhe
    $xsymcent     = $switchwidth / 2 ;
    $ysymcent     = $switchheight *(1-1/3) ;
    $elliheight   = $switchheight / 2;
      // wieviele Zeichen sind es
    $lentext     = strlen($this->text);
      // das macht x Pixel
    $fontwidth   = imagefontwidth ($this->font_size)*$lentext + 2 ;
    $fontheight  = imagefontheight($this->font_size)*1.5;

    switch ( $this->textpos ){
        // drüber
        // schalterhöhe + Texthöhe
        // Breiteste Schalter oder Text
      case "top":
      break;

        // drunter
        // schalterhöhe + Texthöhe
        // Breiteste Schalter oder Text
      case "buttom":
        $this->height = $switchheight + $fontheight ;
        if ($switchwidth >= $fontwidth){ $this->width = $switchwidth; } else { $this->width = $fontwidth;}
        // x/y Position der oberen linken Ecke des Schalters
        $xpos_img = ($this->width - $switchwidth) / 2 ; // links
        $ypos_img = 0 ; // oben
        // x/y Position des Textes
        $xpos_txt = 1 ;
        $ypos_txt = $switchheight + 1 ;
      break;

        // links daneben
        // schalterbreite + textbreite
        // Größte von schalterhöte oder Texthöhe
      case "left":
        // x/y Position der oberen linken Ecke des Schalters
        $xpos_img = 0 ;
        $ypos_img = 0 ;
        // x/y Position des Textes
        $xpos_txt = 0 ;
        $ypos_txt = 0 ;
      break;

        // rechts daneben
        // schalterbreite + textbreite
        // Größte von schalterhöte oder Texthöhe
      case "right":
        $this->height = $this->fontheight ;
        if ($this->height < $switchheight){ $this->height = $switchheight ; }
        $this->width = $switchwidth + $fontwidth ;// + $this->height;
        // x/y Position der oberen linken Ecke des Schalters
        $xpos_img = 0 ; // links
        $ypos_img = 0 ; // oben
        // x/y Position des Textes
        $xpos_txt = $xpos_img + $switchwidth + 1 ;
        $ypos_txt = ($this->height - $this->font_size) /2 - 4 ;
      break;

      default ;
        $this->height = $switchheight + $fontheight ;
        if ($switchwidth >= $fontwidth){ $this->width = $switchwidth; } else { $this->width = $fontwidth;}
        // x/y Position der oberen linken Ecke des Schalters
        $xpos_img = ($this->width - $switchwidth) / 2 ; // links
        $ypos_img = 0 ; // oben
        // x/y Position des Textes
        $xpos_txt = 1 ;
        $ypos_txt = $switchheight + 1 ;
    }


    $this->img   = imagecreate($this->width,$this->height);
    $this->set_color ($this->oncol);

    if ($this->status == "EIN"){
      // Eingeschaltet ==> grüner Knopf nach unten
      $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent, $ypos_img + $ysymcent-5,  $switchwidth-9,  $elliheight-9,  0, 360, $this->green, IMG_ARC_PIE);
      $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent, $ypos_img + $ysymcent-5,  $switchwidth-8,  $elliheight-8,  0, 360, $this->blue, IMG_ARC_NOFILL);
      $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent, $ypos_img + $ysymcent-5,  $switchwidth-7,  $elliheight-7,  0, 360, $this->blue, IMG_ARC_NOFILL);
    } else {
      // Ausgeschaltet ==> roter Kopf ganz oben
      $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent, $ypos_img + $ysymcent-12,  $switchwidth-9,  $elliheight-9,  0, 360, $this->red, IMG_ARC_PIE);
      $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent, $ypos_img + $ysymcent-12,  $switchwidth-8,  $elliheight-8,  0, 360, $this->blue, IMG_ARC_NOFILL);
      $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent, $ypos_img + $ysymcent-12,  $switchwidth-7,  $elliheight-7,  0, 360, $this->blue, IMG_ARC_NOFILL);
      imageline  ( $this->img, $xpos_img + $xsymcent-(($switchwidth-9)/2  ), $ypos_img + $ysymcent-12, $xpos_img + $xsymcent-(($switchwidth-9)/2  ), $ypos_img + $ysymcent , $this->blue ) ;
      imageline  ( $this->img, $xpos_img + $xsymcent-(($switchwidth-9)/2-1), $ypos_img + $ysymcent-12, $xpos_img + $xsymcent-(($switchwidth-9)/2-1), $ypos_img + $ysymcent , $this->blue ) ;
      imageline  ( $this->img, $xpos_img + $xsymcent+(($switchwidth-9)/2  ), $ypos_img + $ysymcent-12, $xpos_img + $xsymcent+(($switchwidth-9)/2  ), $ypos_img + $ysymcent , $this->blue ) ;
      imageline  ( $this->img, $xpos_img + $xsymcent+(($switchwidth-9)/2+1), $ypos_img + $ysymcent-12, $xpos_img + $xsymcent+(($switchwidth-9)/2+1), $ypos_img + $ysymcent , $this->blue ) ;
    }
    // Untere Ebene innere Kreise
    $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent,  $ysymcent,  $switchwidth-8,  $elliheight-8,  0, 180, $this->blue, IMG_ARC_NOFILL);
    $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent,  $ysymcent,  $switchwidth-7,  $elliheight-7,  0, 180, $this->blue, IMG_ARC_NOFILL);
    $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent,  $ysymcent,  $switchwidth-2,  $elliheight-2,  320, 220, $this->blue, IMG_ARC_NOFILL);
    $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent,  $ysymcent,  $switchwidth-1,  $elliheight-1,  320, 220, $this->blue, IMG_ARC_NOFILL);
    $noerror = imagefilledarc ($this->img, $xpos_img + $xsymcent,  $ysymcent,  $switchwidth  ,  $elliheight  ,  320, 220, $this->blue, IMG_ARC_NOFILL);

    imagefill  ( $this->img, 1, 1, $this->lighterblue );
    imagestring($this->img,$this->font_size,$xpos_txt,$ypos_txt,$this->text,$this->black) ; //textcol);

    header("Content-Type: image/png");
    imagepng($this->img);
    imagedestroy($this->img);
  }




/*******************************************************************************\
   Funktion: create_radio_button ()
     $textarr : Text Auf den Button

\********************************************************************************/
  function create_radio_button (){


  }



  var $m_form ; // Form des Tasters rund, spitz, eckig
  var $m_text ; // Text des Tasters
  var $m_bg ;   // Hintergrundfarbe im mittleren Bereich
  var $m_bc ;   // Randfarbe
  var $m_pc ;   // Farbe der Umgebung
  var $m_font ; // Zeichensatz
  var $m_fs ;   // Schriftgrösse

  function create_menue_button (){
    $this->m_font = "../4fbak/fonts/georgiaz.ttf";

    $b = @imageTTFBbox($this->m_fs, 0, $this->m_font, $this->m_text);
/*  p[6],p[7] O---------------------O p[4],p[5]
              |                     |
    p[0],p[1] O---------------------O p[2],p[3]  */

    $txt_dx = abs ($b[4]) + abs ($b[6]) ;
    $ober   = abs ($this->m_fs - abs ($b[7])) ;
    $mittel = $this->m_fs ;
    $unter  = abs ($b[1]) ;

    $txt_dy = $ober + $mittel + $unter ;

    $this->height = $txt_dy + 5 ;
    $this->width  = $txt_dx + $ober + $mittel ;
    if ( $this->m_width > $this->width ){ $this->width = $this->m_width ;}
    $this->img   = imagecreate($this->width, $this->height);
    $this->set_color ($this->oncol);

    switch ($this->m_form){
      case "spitz":
          // spitz seiten
          // 1. Strich links oben nach rechts oben vom Rand 10 Punkte
            /*    x4 x1                x2 x3
              x1|y1 /------------------\ x2|y1      y1
                   /                    \
            x4|y2 *                      * x3|y2    y2
                   \                    /
              x1|y3 \------------------/ x2|y3      y3
            */
        $x1 = 10 ;
        $x2 = $this->width - $this->height /2 ;
        $x3 = $this->width -  1 ;
        $x4 = 1 ;
        $y1 = 1 ;
        $y2 = $this->height /2 ;
        $y3 = $this->height-1 ;

        imageline  ( $this->img, $x1, $y1, $x2, $y1, $this->black ) ;
        imageline  ( $this->img, $x2, $y1, $x3, $y2, $this->black ) ;
        imageline  ( $this->img, $x3, $y2, $x2, $y3, $this->black ) ;
        imageline  ( $this->img, $x2, $y3, $x1, $y3, $this->black ) ;
        imageline  ( $this->img, $x1, $y3, $x4, $y2, $this->black ) ;
        imageline  ( $this->img, $x4, $y2, $x1, $y1, $this->black ) ;

        imagefill  ( $this->img, 1, 1, $this->lighterblue );
      break;

      case "rund":

            /*
              x1|y1 ------------------ x2|y1      y1

                    *                *

              x1|y2 ------------------ x2|y2      y3
            */
        $x1 = $this->height / 2 ;
        $x2 = $this->width - $this->height / 2-1;

        $y1 = 0 ;
        $y2 = $this->height - 1 ;
        $y3 = $y2 / 2 ;

        imageline  ( $this->img, $x1, $y1, $x2, $y1, $this->black ) ;
        imageline  ( $this->img, $x1, $y2, $x2, $y2, $this->black ) ;

        imagearc  ( $this->img, $x1, $y3, $this->height, $this->height, 90 , 270, $this->black  );
        imagearc  ( $this->img, $x2, $y3, $this->height, $this->height, 270, 450, $this->black  );

        imagefill  ( $this->img,            0,   0, $this->get_color ($this->m_bg) );
        imagefill  ( $this->img,            0, $y2, $this->get_color ($this->m_bg) );
        imagefill  ( $this->img, $this->width,   0, $this->get_color ($this->m_bg) );
        imagefill  ( $this->img, $this->width, $y2, $this->get_color ($this->m_bg) );
      break;

    }

    $bo = imagettftext ( $this->img, $this->m_fs, 0, $x1-3, ($oben+$mittel+$unten+4), $this->black, $this->m_font,  $this->m_text );

    header("Content-Type: image/png");
    imagepng($this->img);
    imagedestroy($this->img);

  }


} // class button
/*******************************************************************************
*******************************************************************************/


define ("debug", false);
  if (isset ($_GET["type"])){
    switch ($_GET["type"]){
      case "tumbler":
        if (isset ( $_GET ["ontext"] )) {
          $ontext = $_GET ["ontext"];
        } else { $ontext = "!FEHLER!"; }

        if (isset ( $_GET ["offtext"] )) {
          $offtext = $_GET ["offtext"];
        } else { $offtext = "!FEHLER!"; }

        if (isset ( $_GET ["color"] )) {
          $color = $_GET ["color"];
        } else {
          $color = "blue";
        }
        $button = new createbutton ("push",$text, $color, $form);
        break ;

      case "push":
        if (isset ( $_GET ["text"] )) {
          $text = $_GET ["text"];
        } else { $text = "!FEHLER!"; }

        if (isset ( $_GET ["color"] )) {
          $color = $_GET ["color"];
        } else {
          $color = "blue";
        }
        $button = new createbutton ("push", $text, $color, debug);
        break ;

      case "radio":
        $textarr = split(',', $text);
        $text = "!FEHLER!";

      break ;

      case "icon":
        $button = new createbutton ("icon",$text, $color, $form);
        break ;

      case "menue":
        if (isset ( $_GET ["text"] )) {
          $text = $_GET ["text"];
        } else { $text = "!FEHLER!"; }

        if (isset ( $_GET ["color"] )) {
          $color = $_GET ["color"];
        } else {
          $color = "blue";
        }
        $button = new createbutton ("menue", $text, $color, debug);
        break ;


      default:
    }
  }


if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}

?>
