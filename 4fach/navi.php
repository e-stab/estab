<?php
/*****************************************************************************\
   Datei: status.php

   benötigte Dateien:    tools.php, db_operation.php

   Beschreibung:

          Hier wird die Statusspalte links dargestellt.

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\******************************************************************************/

session_start ();

include ("tools.php");
include ("../db_operation.php");
include ("../config.inc.php");
pre_html ("status", "Status","");


echo "<body bgcolor=\"#EEEEFF\">";


/****************************************************************************
  O u t q u e u e c o u n t e r
*****************************************************************************/
if ( $_SESSION [ROLLE] == "Fernmelder") {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getoutqueuecount ();
  if ($outqueue == 0 ){
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";
}

if ( ( $_SESSION [ROLLE] == "Stab") and
     ($_SESSION ["vStab_funktion"] == "Si" ) ) {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getviewerqueuecount ();
  if ($outqueue == 0 ){
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } else {
    echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  }
  echo "</td>";
  echo "</tr>";
  echo "</table>";

}

if ( ( $_SESSION [ROLLE] == "FB") or
     (  ( $_SESSION [ROLLE] == "Stab") and
        ($_SESSION ["vStab_funktion"] != "Si" )
     )
   )  {
  echo "<table width=\"50\" align=\"center\" border=\"1\" cellspacing=\"2\" cellpeding=\"3\">";
  echo "<tr>";
  echo "<td>";
  $outqueue = getreadedcount ();
  if ( $outqueue == 0 ) {
    echo "<p style=\" text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
  } elseif ( $outqueue <= 99 ) {
      echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">".$outqueue."</p>\n";
    } else {
      echo "<p style=\" color:#FF0000; text-decoration:blink; text-align:center; font-size:x-large; font-weight:bold;\">XX</p>\n";

    }
  echo "</td>";
  echo "</tr>";
  echo "</table>";

}

systemstatus ("vertikal");

echo "<table align=\"center\" style=\"text-align:center; width: 50px; background-color: rgb(150, 150, 150); height: 10px;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
echo "<tbody>\n";
echo "<tr><td>";
echo "<img src=\"".$conf_design_path."/timer.gif\">";
echo "</td></tr>";
echo "</table>";



  if ( ( $_SESSION ["menue"] == "Usermode" ) and
       ( !isset ( $_SESSION ["menue"]))) { reset_cookie (); }

  include ("../config.inc.php");
  include ("../dbcfg.inc.php");  include ("../e_cfg.inc.php");
  include ("../fkt_rolle.inc.php");
    // hellblauer Hintergrund
  echo "<body align=\"center\" bgcolor=\"#DCDCFF\">";
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
             echo "<input type=\"image\" name=\"login\" src=\"button.php?type=menue&m_text=anmelden&m_fs=10&m_form=rund\">\n";
             echo "</td>\n";
             echo "</tr>\n";
  }


  switch ($_SESSION ["menue"]) {
    case "ROLLE" : // Taetigkeit nach Rolle ==>
      if ($_SESSION ["menue"] == "ROLLE") { // Taetigkeit nach Rolle ==>
        if (isset ($_SESSION [ROLLE])){
           switch ($_SESSION [ROLLE]){

             case "Stab" :  /* Hier gibt es den normalen Stab und die Sichterfunktion also muss hier noch
                               die Funktion ausgewertet werden.*/
               if (($_SESSION [vStab_funktion]) == "Si") {// Sichter
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_sichten\" src=\"button.php?type=menue&m_text=sichten&m_fs=10&m_form=rund\" alt=\"sichten\">\n";
                 echo "</td></tr>\n";
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"si_admin\" src=\"button.php?type=menue&m_text=2.Sichtung&m_fs=10&m_form=rund\" alt=\"2.Sichtung\">\n";
                 echo "</td></tr>\n";
               } else {
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_schreiben\" src=\"button.php?type=menue&m_text=schreiben&m_fs=10&m_form=rund\" alt=\"schreiben\">\n";
                 echo "</td></tr>\n";
/*
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_anhang\" src=\"".$conf_design_path."/attachment.gif\" alt=\"Anhang\">\n";
                 echo "</td></tr>\n";
*/
                 echo "<tr><td>\n";
                 echo "<input type=\"image\" name=\"stab_lesen\" src=\"button.php?type=menue&m_text=lesen&m_fs=10&m_form=rund\" alt=\"lesen\">\n";
                 echo "</td></tr>\n";
               }
             break;

             case "Fernmelder" :
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"fm_eingang\" src=\"button.php?type=menue&m_text=Eingang&m_fs=10&m_form=rund\" alt=\"Eingang\">\n";
                  echo "</td></tr>\n";
                  echo "<tr><td>";
                  echo "<input type=\"image\" name=\"fm_ausgang\" src=\"button.php?type=menue&m_text=Ausgang&m_fs=10&m_form=rund\" alt=\"Ausgang\">\n";
                  echo "</td></td>\n";
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"fm_admin\" src=\"button.php?type=menue&m_text=2.Sichtung&m_fs=10&m_form=rund\" alt=\"admin\">\n";
                  echo "</td></tr>\n";
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"fm_anhang\" src=\"button.php?type=menue&m_text=Anhänge&m_fs=10&m_form=rund\" alt=\"Anhang\">\n";
                  echo "</td></tr>\n";

             break;

             case "Administrator" : break;

             case "FB" :
                  echo "<tr>\n";
                  echo "<td>\n";
                  echo "<input type=\"image\" name=\"stab_schreiben\" src=\"button.php?type=menue&m_text=schreiben&m_fs=10&m_form=rund\">\n";
                  echo "</td></tr>\n";
                  echo "<tr><td>\n";
                  echo "<input type=\"image\" name=\"stab_lesen\" src=\"button.php?type=menue&m_text=lesen&m_fs=10&m_form=rund\">\n";
                  echo "</td></tr>\n";
             break;
           }


          echo "<tr>\n";
          echo "<td>\n";
          echo "<input type=\"image\" name=\"m2_benutzer\" value=\"benutzer\" src=\"button.php?type=menue&m_text=Benutzer&m_fs=10&m_form=rund\" alt=\"Benutzer\">\n";
          echo "</td></tr>\n";
          echo "<tr><td>\n";
          echo "<input type=\"image\" name=\"m2_abmelden\" value=\"Abmelden\" src=\"button.php?type=menue&m_text=abmelden&m_fs=10&m_form=rund\" alt=\"abmelden\">\n";
          echo "</td></tr>\n";
        }
      }
    break;
  }
  echo "</tbody>";
  echo "</table>";
  echo "</form>";

  echo "</body>";
  echo "</html>";

?>
