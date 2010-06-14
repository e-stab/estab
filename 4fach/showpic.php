<?php
/*******************************************************************************\
  Parameter:
    filename: Pfad und Dateiname der dargestellt werden soll.
\*******************************************************************************/

  function getimagetypebyfilename ($filename){
    $filetype = exif_imagetype ( $filename );
    $def_filetype = array (
       1 => "IMAGETYPE_GIF",
       2 => "IMAGETYPE_JPEG",
       3 => "IMAGETYPE_PNG",
       4 => "IMAGETYPE_SWF",
       5 => "IMAGETYPE_PSD",
       6 => "IMAGETYPE_BMP", 
       7 => "IMAGETYPE_TIFF_II", // (intel-Bytefolge) 
       8 => "IMAGETYPE_TIFF_MM", // (motorola-Bytefolge)  
       9 => "IMAGETYPE_JPC", 
      10 => "IMAGETYPE_JP2",
      11 => "IMAGETYPE_JPX",
      12 => "IMAGETYPE_JB2",
      13 => "IMAGETYPE_SWC",
      14 => "IMAGETYPE_IFF",
      15 => "IMAGETYPE_WBMP",
      16 => "IMAGETYPE_XBM") ;
    $strfiletype = $def_filetype [$filetype];
    return ($strfiletype);  
  }






  function loadpic ($filename){
    // Datei öffnen
    $imgtype = getimagetypebyfilename( $filename );
    switch ($imgtype){
       case "IMAGETYPE_GIF":    $im = @imagecreatefromgif  ( $filename );      break;
       case "IMAGETYPE_JPEG":   $im = @ImageCreateFromJPEG ( $filename ); /* Versuch, Datei zu öffnen */   break;
       case "IMAGETYPE_PNG":    $im = @ImageCreateFrompng  ( $filename );      break;
       case "IMAGETYPE_SWF": break;
       case "IMAGETYPE_PSD": break;
       case "IMAGETYPE_BMP":    $im = @imagecreatefromwbmp ( $filename );  break;
       case "IMAGETYPE_TIFF_II": // (intel-Bytefolge)      break;
       case "IMAGETYPE_TIFF_MM": // (motorola-Bytefolge)   break;
       case "IMAGETYPE_JPC": break;
       case "IMAGETYPE_JP2": break;
       case "IMAGETYPE_JPX": break;
       case "IMAGETYPE_JB2": break;
       case "IMAGETYPE_SWC": break;
       case "IMAGETYPE_IFF": break;
       case "IMAGETYPE_WBMP": break;
       case "IMAGETYPE_XBM":  $im = imagecreatefromxbm ( $filename );      break;
    }
    if (!$im) {                            /* Prüfen, ob fehlgeschlagen */
        $im = ImageCreate (150, 30);       /* Erzeugen eines leeren Bildes */
        $bgc = ImageColorAllocate ($im, 255, 255, 255);
        $tc  = ImageColorAllocate ($im, 0, 0, 0);
        ImageFilledRectangle ($im, 0, 0, 150, 30, $bgc);
        /* Ausgabe einer Fehlermeldung */
        ImageString($im, 1, 5, 5, "Fehler beim Öffnen von: $imgname", $tc);
    }
    return $im;
  }

  if ( (isset ( $_GET ["file"] )) and
     ( (isset ( $_GET ["zoom"] ))  or  ( (isset( $_GET ["width"])) or (isset( $_GET ["height"] )) ) ) ) {
     // lade die Quelldatei
    $src = loadpic ($_GET ["file"]);
     // Breite und Höhe der Quelle
     // Ist die Quelle im Hochformat?
    

    $sx = imagesx ( $src );
    $sy = imagesy ( $src );
    $ist_hoch = ($sy/$sx)>1;

    if ($ist_hoch){ $breitzuhoch = ($sx / $sy); } else { $breitzuhoch = ( $sy / $sx); }
     // Zieldatei anlegen
    if (isset ( $_GET ["zoom"] )) {
      $dest = imagecreatetruecolor ( $sx * $_GET["zoom"], $sy * $_GET["zoom"] );
      imagecopyresized ( $dest, $src, 0, 0, 0 , 0 , $sx * $_GET["zoom"], $sy * $_GET["zoom"], $sx , $sy );

      // Beide Werte sind gesetzt
    } 
    if ( (isset( $_GET ["width"])) and (isset( $_GET ["height"] )) ) {
      if ($ist_hoch) {
        $dest = imagecreatetruecolor ( $_GET["height"], $_GET["width"] );
        imagecopyresized ( $dest, $src, 0, 0, 0 , 0 , $_GET["height"], $_GET["width"], $sx , $sy );
      } else {
        $dest = imagecreatetruecolor ( $_GET["width"], $_GET["height"] );
        imagecopyresized ( $dest, $src, 0, 0, 0 , 0 , $_GET["width"], $_GET["height"], $sx , $sy );
      }

     // Nur die Breite ist gesetzt
    } 

/*99*/
    if ( (isset( $_GET ["width"])) and (!isset( $_GET ["height"] )) ) {

/*
echo "width und !height<br>$breitzuhoch isthoch=";
if ( $isthoch ) { echo "wahr"; } else {echo "falsch";}
echo "<br>";
echo "_GET[width]                    =".$_GET["width"]."<br>";
echo "_GET[width] * breitzuhoch =".$_GET["width"] * $breitzuhoch."<br>";
*/

      if ($ist_hoch) {
        $dest = imagecreatetruecolor ( $_GET["width"] * $breitzuhoch,  $_GET["width"] );
        imagecopyresized ( $dest, $src, 0, 0, 0 , 0 , $_GET["width"] * $breitzuhoch, $_GET["width"], $sx , $sy );
      } else {
        $dest = imagecreatetruecolor ( $_GET["width"], $_GET["width"] * $breitzuhoch );
        imagecopyresized ( $dest, $src, 0, 0, 0 , 0 , $_GET["width"], $_GET["width"] * $breitzuhoch, $sx , $sy );
      }

    } 


    if ( (!isset( $_GET ["width"])) and (isset( $_GET ["height"] )) ) {
      if ($ist_hoch) {
        $dest = imagecreatetruecolor ( $_GET["height"], $_GET["height"] / $breitzuhoch );
        imagecopyresized ( $dest, $src, 0, 0, 0 , 0 , $_GET["height"], $_GET["height"] / $breitzuhoch, $sx , $sy );
      } else {
        $dest = imagecreatetruecolor ( $_GET["height"] / $breitzuhoch, $_GET["height"] );
        imagecopyresized ( $dest, $src, 0, 0, 0 , 0 , $_GET["height"] / $breitzuhoch , $_GET["height"], $sx , $sy );
      }
    }


    header ("Content-type: image/png");
    imagepng($dest);
    imagedestroy($dest);

  }
?>