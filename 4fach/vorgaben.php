<?php

define ("debug",false);

session_start ();

if ( debug == true ){
  echo "<br><br>\n";
//  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
//  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
//  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}

  if ( ( $_SESSION ["menue"] == "Usermode" ) and
       ( !isset ( $_SESSION ["menue"]))) { reset_cookie (); }

  include ("../4fcfg/config.inc.php");
  include ("../4fcfg/dbcfg.inc.php");
  include ("../4fcfg/e_cfg.inc.php");
  include ("../4fcfg/fkt_rolle.inc.php");
    // hellblauer Hintergrund
  echo "<body align=\"center\" bgcolor=\"#ECECFF\">";
  echo "<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
//  echo "<!-- Formularelemente und andere Elemente innerhalb des Formulars -->\n";
//  echo date ("His")."<br>";
  echo "<table align=\"center\" style=\"text-align:center;\" border=\"0\" cellspacing=\"0\" cellpeding=\"0\">\n";
  echo "<tbody>";
  if ( (count ($_SESSION) == 0) OR
       (!isset($_SESSION ["menue"])) OR
       ($_SESSION ["menue"] == "WELCOME") OR
       ($_SESSION ["menue"] == "LOGIN")){
             echo "<tr>\n";
             echo "<td>\n";
             echo "<input type=\"hidden\" name=\"login\" value=\"Anmelden\">\n";
             echo "<input type=\"image\" name=\"login\" src=\"button.php?type=menue&m_text=anmelden&m_fs=10&m_form=rund&width=99&bg=mlightblue\">\n";
             echo "</td>\n";
             echo "</tr>\n";
  }


  switch ($_SESSION ["menue"]) {
    case "ROLLE" : // Taetigkeit nach Rolle ==>
      if ($_SESSION ["menue"] == "ROLLE") { // Taetigkeit nach Rolle ==>
        if (isset ($_SESSION ["ROLLE"])){
           switch ($_SESSION ["ROLLE"]){

             case "Stab" :  /* Hier gibt es den normalen Stab und die Sichterfunktion also muss hier noch
                               die Funktion ausgewertet werden.*/
               if (($_SESSION ["vStab_funktion"]) == "Si") {// Sichter
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_sichten\" src=\"button.php?type=menue&m_text=sichten&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"sichten\">\n";
                 echo "</td></tr>\n";
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"si_admin\" src=\"button.php?type=menue&m_text=2.Sichtung&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"2.Sichtung\">\n";
                 echo "</td></tr>\n";
               } else {
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_schreiben\" src=\"button.php?type=menue&m_text=schreiben&m_fs=10&m_form=rund&width=99&bg=mlightblue&bg=mlightblue\" alt=\"schreiben\">\n";
                 echo "</td></tr>\n";
/*
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_anhang\" src=\"".$conf_design_path."/attachment.gif\" alt=\"Anhang\">\n";
                 echo "</td></tr>\n";
*/
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_lesen\" src=\"button.php?type=menue&m_text=lesen&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"lesen\">\n";
                 echo "</td></tr>\n";
               }
             break;

             case "Fernmelder" :
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"fm_eingang\" src=\"button.php?type=menue&m_text=Eingang&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"Eingang\">\n";
                  echo "</td></tr>\n";
                  echo "<tr><td>";
                  echo "<input type=\"image\" name=\"fm_ausgang\" src=\"button.php?type=menue&m_text=Ausgang&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"Ausgang\">\n";
                  echo "</td></td>\n";
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"fm_admin\" src=\"button.php?type=menue&m_text=2.Sichtung&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"admin\">\n";
                  echo "</td></tr>\n";
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"fm_anhang\" src=\"button.php?type=menue&m_text=Anhänge&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"Anhang\">\n";
                  echo "</td></tr>\n";

             break;

             case "Administrator" : break;

             case "FB" :
                  echo "<tr>\n";
                  echo "<td>\n";
                  echo "<input type=\"image\" name=\"stab_schreiben\" src=\"button.php?type=menue&m_text=schreiben&m_fs=10&m_form=rund&width=99&bg=mlightblue\">\n";
                  echo "</td></tr>\n";
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"stab_lesen\" src=\"button.php?type=menue&m_text=lesen&m_fs=10&m_form=rund&width=99&bg=mlightblue\">\n";
                  echo "</td></tr>\n";
             break;
           }


          echo "<tr>\n";
          echo "<td>\n";
          echo "<input type=\"image\" name=\"m2_benutzer\" value=\"benutzer\" src=\"button.php?type=menue&m_text=Benutzer&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"Benutzer\">\n";
          echo "</td></tr>\n";
          echo "<tr><td>\n";
          echo "<input type=\"image\" name=\"m2_abmelden\" value=\"Abmelden\" src=\"button.php?type=menue&m_text=abmelden&m_fs=10&m_form=rund&width=99&bg=mlightblue\" alt=\"abmelden\">\n";
          echo "</td></tr>\n";
        }
      }
    break;
  }
  echo "</tbody>";
  echo "</table>";
  echo "</form>";
  echo "</body>";
  echo "\n<!-- ENDE file:vorgabe.php -->\n";


?>
