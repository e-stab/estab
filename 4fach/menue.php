<?php
// $SYSTEM_ROOT = $_SERVER["DOCUMENT_ROOT"]."/intern/kats";
/*****************************************************************************\
   Datei: menue.php

   benoetigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
/*******************************************************************************
  Menues
********************************************************************************/

function menue (){
  echo "\n\n\n<!-- ANFANG file:menue.php fkt:menue -->\n";
  if ( ( $_SESSION ["menue"] == "Usermode" ) and
       ( !isset ( $_SESSION ["menue"]))) { reset_cookie (); }

  include ("../config.inc.php");
  include ("../dbcfg.inc.php");  include ("../e_cfg.inc.php");
  include ("../fkt_rolle.inc.php");


  echo "<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
  echo "<!-- Formularelemente und andere Elemente innerhalb des Formulars -->\n";

  echo "<table border=\"0\" cellspacing=\"0\" cellpeding=\"0\">\n";
  echo "<tbody>";
  echo "<tr>\n";
  echo "<td>\n";

  echo "<table border=\"0\" cellspacing=\"0\" cellpeding=\"0\">\n";
  echo "<tbody>";
  echo "<tr>\n";
  switch ($_SESSION ["menue"]) {
    case "WELCOME" : // nicht angemeldet ==> nur login Button
             echo "<td>\n";
             foreach ( $conf_4f ['NameVersion'] as $titel ) {
               echo $titel;
             }
             echo "</td>\n";
             echo "</tr>\n<tr>\n";
             echo "<td>\n";
//             echo "<input type=\"submit\" name=\"login\" value=\"Anmelden\">\n";
             echo "<input type=\"hidden\" name=\"login\" value=\"Anmelden\">\n";
             echo "<input type=\"image\" name=\"login\" src=\"".$conf_design_path."/006.jpg\">\n";
             echo "</td>\n";
    break;
    case "LOGIN" : // Anmeldeformular
             echo "<td>\nName, Vorname:</td>\n<td>\n<input style=\"font-size:20px; font-weight:900;\" type=\"text\" size=\"32\" maxlength=\"32\" name=\"benutzer\"></td>\n";
             echo "</tr>\n<tr>\n";
             echo "<td>\nK&uuml;rzel:</td>\n<td>\n<input style=\"font-size:20px; font-weight:900;\" type=\"text\" size=\"3\" maxlength=\"3\" name=\"kuerzel\"></td>\n";
             echo "</tr>\n<tr>\n";
             echo "<td>\nFunktion:</td>\n<td>\n<select style=\"font-size:20px; font-weight:900;\" name=\"funktion\">\n";
             for ($i=1; $i <= count ($conf_empf); $i++ ){
               echo "<option>".$conf_empf[$i]["fkt"]."</option>\n";
             }
             echo "</select>\n";
             echo "<td>\n</td>\n";
             echo "<td>\n<input type=\"submit\" name=\"anmelden\" value=\"Anmelden\">\n";
             echo "</td>\n";
    break;

    case "ROLLE" : // Taetigkeit nach Rolle ==>
          if (isset ($_SESSION [ROLLE])){
             switch ($_SESSION [ROLLE]){

               case "Stab" :  /* Hier gibt es den normalen Stab und die Sichterfunktion also muss hier noch
                                 die Funktion ausgewertet werden.*/
                 if (($_SESSION [vStab_funktion]) == "Si") {// Sichter
                   echo "<td>\n";
                   echo "<input type=\"image\" name=\"stab_sichten\" src=\"".$conf_design_path."/017.jpg\" alt=\"sichten\">\n";
                   echo "</td>\n";
                   echo "<td>\n";
                   echo "<input type=\"image\" name=\"si_admin\" src=\"".$conf_design_path."/012.jpg\" alt=\"Sichteradministration\">\n";
                   echo "</td>\n";

                 } else {

                   echo "<td>\n";
                   echo "<input type=\"image\" name=\"stab_schreiben\" src=\"".$conf_design_path."/016.jpg\" alt=\"schreiben\">\n";
                   echo "</td>\n";
                   echo "<td>\n";
                   echo "<input type=\"image\" name=\"stab_anhang\" src=\"".$conf_design_path."/005.jpg\" alt=\"Anhang\">\n";
                   echo "</td>\n";
                   echo "<td>\n";
                   echo "<input type=\"image\" name=\"stab_lesen\" src=\"".$conf_design_path."/011.jpg\" alt=\"lesen\">\n";
                   echo "</td>\n";
                 }
               break;

               case "Fernmelder" :
                    echo "<td>\n";
                    echo "<input type=\"image\" name=\"fm_eingang\" src=\"".$conf_design_path."/009.jpg\" alt=\"Eingang\">\n";
                    echo "</td>\n";
                    echo "<td aligne=\"center\">";
                    echo "<input type=\"image\" name=\"fm_ausgang\" src=\"".$conf_design_path."/007.jpg\" alt=\"Ausgang\">\n";
                    echo "</td>\n";
                    echo "<td>\n";
                    echo "<input type=\"image\" name=\"fm_admin\" src=\"".$conf_design_path."/004.jpg\" alt=\"admin\">\n";
                    echo "</td>\n";
                    echo "<td>\n";
                    echo "<input type=\"image\" name=\"fm_anhang\" src=\"".$conf_design_path."/005.jpg\" alt=\"Anhang\">\n";
                    echo "</td>\n";
               break;

               case "Administrator" : break;

               case "FB" :
                    echo "<td>\n";
                    echo "<input type=\"image\" name=\"stab_schreiben\" src=\"".$conf_design_path."/016.jpg\">\n";
                    echo "</td>\n";
                    echo "<td>\n";
                    echo "<input type=\"image\" name=\"stab_lesen\" src=\"".$conf_design_path."/011.jpg\">\n";
                    echo "</td>\n";
               break;
             }

            echo "</tr>\n";
            echo "</tbody>";
            echo "</table>";

            echo "</td>\n";

            echo "<td>\n";

            echo "<table border=\"0\" cellspacing=\"0\" cellpeding=\"0\">\n";
              echo "<tbody>";

                echo "<tr>\n";

                  echo "<td>\n";
                   echo "<input type=\"image\" name=\"m2_benutzer\" value=\"benutzer\" src=\"".$conf_design_path."/008.jpg\" alt=\"Benutzer\">\n";
                  echo "</td>\n";

                  echo "<td>\n";
                   echo "<input type=\"image\" name=\"m2_abmelden\" value=\"Abmelden\" src=\"".$conf_design_path."/002.jpg\" alt=\"abmelden\">\n";
                  echo "</td>\n";

          }
    break;
  }
  echo "</tr>\n";
  echo "</tbody>";
  echo "</table>";
  echo "</td>\n";
  echo "</tr>\n";
  echo "</tbody>";
  echo "</table>";
  echo "</form>";
  echo "\n<!-- ENDE file:menue.php fkt:menue -->\n";
}

?>
