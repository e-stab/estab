<?php
/*******************************************************************************
   Funktion: katego_icon ()
     $color =
      "blue"       "red"      "yellow"      "green"
      "lightblue"  "lightred" "lightyellow" "lightgreen"

********************************************************************************/
  function katego_icon ($icontext, $color){
    $font_size = 12;

    $height=imagefontheight($font_size)*1.5;
    $widthfont=imagefontwidth($font_size)*strlen($icontext);
    $width=$widthfont+$height;

    $img         = imagecreate($width,$height);

    $white       = imagecolorallocate($img,255,255,255);
    $yellow      = imagecolorallocate($img,225,225,150);
    $blue        = imagecolorallocate($img,150,150,255);
    $red         = imagecolorallocate($img,255,100,100);
    $green       = imagecolorallocate($img, 80,255, 80);
    $lightyellow = imagecolorallocate($img,225,225,200);
    $lightblue   = imagecolorallocate($img,200,200,255);
    $lightred    = imagecolorallocate($img,255,200,200);
    $lightgreen  = imagecolorallocate($img,200,255,200);

    $black       = imagecolorallocate($img,  0,  0,  0);

    switch ($color){
      case "blue"        : $bg = $blue;        $textcol= $white; $is_highlighted = false; break;
      case "red"         : $bg = $red;         $textcol= $black; $is_highlighted = false; break;
      case "yellow"      : $bg = $yellow;      $textcol= $black; $is_highlighted = false; break;
      case "green"       : $bg = $green;       $textcol= $black; $is_highlighted = false; break;
      case "lightblue"   : $bg = $lightblue;   $textcol= $white; $is_highlighted = true; break;
      case "lightred"    : $bg = $lightred;    $textcol= $black; $is_highlighted = true; break;
      case "lightyellow" : $bg = $lightyellow; $textcol= $black; $is_highlighted = true; break;
      case "lightgreen"  : $bg = $lightgreen;  $textcol= $black; $is_highlighted = true; break;
    }
      // Hintergrundfrage
    imagefill  ( $img, 1, 1, $bg );

    $len=strlen($icontext);
      // Rahmen
    if ( !$is_highlighted ){

      imageline  ( $img  , 0, $height-1, 0, 1, $black  ); // linke oben nach unten
      imageline  ( $img  , 1, $height-1, 1, 1, $black  ); // linke oben nach unten

      imageline  ( $img  , 0, 0, $width-1, 0, $black  ); // oben  linke nach rechts
      imageline  ( $img  , 0, 1, $width-1, 1, $black  ); // oben  linke nach rechts

      imageline  ( $img  , $width-1, $height-1, $width-1, 0, $black  ); // linke oben nach unten
      imageline  ( $img  , $width-2, $height-1, $width-2, 0, $black  ); // linke oben nach unten
    } else {
      imagerectangle  ( $img , 0, 0, $width-1  , $height-1, $black  ) ;
    }
    $xpos= $height/2 ;//$i*imagefontwidth($font_size);
    $ypos= ($height-$font_size)/2-1;
    imagestring($img,$font_size,$xpos,$ypos,$icontext,$textcol);
    header("Content-Type: image/png");
    imagepng($img);
    imagedestroy($img);
  }
  if (isset ( $_GET ["icontext"] )) {
    $icontext = $_GET ["icontext"];
  } else {
    $icontext = "!FEHLER!";
  }

  if (isset ( $_GET ["color"] )) {
    $color = $_GET ["color"];
  } else {
    $color = "blue";
  }

  katego_icon ( $icontext, $color )

?>
