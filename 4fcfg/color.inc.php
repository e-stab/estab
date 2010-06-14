<?php

define ("colorschema",aknz);
/*// iuk, thw, aknz
*/

switch (colorschema){
  case "alt":   //  alte Einstellung
    $this->bg_color_fm_a   = "rgb(255, 224, 200)"; // rosa Fernmelder aktiv
    $this->bg_color_fmp_a  = "rgb(100, 255, 100)"; // hell grün Fernmelderpflichtfeld  aktiv
    $this->bg_color_nw_a   = "rgb(255, 204, 51)";  // orange
    $this->bg_color_tx_a   = "rgb(224, 255, 255)"; // hell blau
    $this->bg_color_si_a   = "rgb(255, 224, 255)"; // hell violett
    $this->bg_color_inaktv = "rgb(255, 255, 255)";  // weiss
    $this->bg_color_aktv   = "rgb(255, 255, 255)";  // weiss
    $this->rbl_bg_color    = "rgb(255, 255, 255)";  // weiss
    $this->bg_color_aktv_must = "rgb(240, 20, 20)"; // rot
  break;
  case "iuk":
   // Gruenes Blatt oben
    $this->bg_color_fm_a   = "rgb(  0, 255,   0)"; // rosa Fernmelder aktiv
    $this->bg_color_fmp_a  = "rgb(200, 255, 200)"; // hell grün Fernmelderpflichtfeld  aktiv
    $this->bg_color_nw_a   = "rgb(255, 204, 51)";  // orange
    $this->bg_color_tx_a   = "rgb(  0, 255,   0)"; // hell blau
    $this->bg_color_si_a   = "rgb(  0, 255,   0)"; // hell violett
    $this->bg_color_inaktv = "rgb(100, 255, 100)";  // weiss
    $this->bg_color_aktv   = "rgb(255, 255, 255)";  // weiss
    $this->rbl_bg_color    = "rgb(255, 255, 255)";  // weiss
    $this->bg_color_aktv_must = "rgb(240, 20, 20)"; // rot
  break;
  case "thw":
     // Blau Blatt oben
    $this->bg_color_fm_a   = "rgb( 200,  0, 255)";              // Kannfeld: Rufname
    $this->bg_color_fmp_a  = "rgb( 50, 180, 220)";              // Pflichtfeld
    $this->bg_color_nw_a   = "rgb(255, 204,  51)";      // unklar (war: orange)
    $this->bg_color_tx_a   = "rgb(  0,   0, 255)";              // Kannfeld: Durchsage / vorrang / Zeiuchen,Fkt
    $this->bg_color_si_a   = "rgb(  0,   0, 255)";              // Kannfeld: Sichtung
    $this->bg_color_inaktv = "rgb(100, 100, 220)";      // Felf: inaktiv
    $this->bg_color_aktv   = "rgb(255, 255, 255)";      // unklar (war: weiss)
    $this->rbl_bg_color    = "rgb(255, 255, 255)";              // unklar (war: weiss)
    $this->bg_color_aktv_must = "rgb(240, 20, 20)";     // unklar (war: rot)
  break;
  case "aknz":
     // weißes Blatt oben
    $this->bg_color_fm_a   = "rgb(200, 200, 200)"; // grau
    $this->bg_color_fmp_a  = "rgb(255, 255, 255)"; // weiss
    $this->bg_color_nw_a   = "rgb(255, 204,  51)"; // orange
    $this->bg_color_tx_a   = "rgb(200, 200, 200)"; // grau
    $this->bg_color_si_a   = "rgb(255, 255, 255)"; // weiss
    $this->bg_color_inaktv = "rgb(180, 180, 180)";  // dunkel grau
    $this->bg_color_aktv   = "rgb(255, 255, 255)";  // weiss
    $this->rbl_bg_color    = "rgb(255, 255, 255)";  // weiss
    $this->bg_color_aktv_must = "rgb(240, 20, 20)"; // rot
  break;
}
/*

AKNZ   WS  GN  RT  GE
AKNZ   WS  RT  GE  GN

*/
?>
