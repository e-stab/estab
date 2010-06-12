<?php
/*****************************************************************************\
   Datei: anhang.php

   benoetigte Dateien:

   Beschreibung:
      Auflistung eines Verzeichnisses zur auswahl als Anhang


   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/


  /**********************************************************************\
    Funktion: readDirectory ()

    benoetigte Datei:
  \**********************************************************************/
  function readDirectory(){
    include ("../config.inc.php");
      $filesArr = array();
      if($ordner = dir($conf_4f ["ablage_dir"]))
      {
          while($datei = $ordner->read())
          {
          if($datei != "." && $datei != "..") array_push($filesArr,$datei);
          }
      }
      rsort ($filesArr);
      return $filesArr;
  }

include ("../config.inc.php");


/**********************************************************************\

\**********************************************************************/
  echo "<form action=\"".$conf_4f ["MainURL"]."\" method=\"get\" target=\"mainframe\">\n";
  echo "<!-- anhang.php Formularelemente und andere Elemente innerhalb des Formulars -->\n";

  echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\">\n";

  echo "<tr><td>\n";
  echo "<input type=\"hidden\" name=\"anhang\" value=\"ah_auswahl\">\n";
  echo "<input type=\"image\" name=\"ah_auswahl\" src=\"".$conf_design_path."/003.jpg\">\n";
  echo "</td><td>\n";
  echo "<input type=\"image\" name=\"ah_abbrechen\" src=\"".$conf_design_path."/001.jpg\">\n";
  echo "</td><td>\n";
  echo "<input type=\"image\" name=\"ah_upload\" src=\"".$conf_design_path."/024.jpg\">\n";
  echo "</td></tr>\n";
  echo "</table>";

  echo "<table border=\"1\" cellspacing=\"2\" cellpeding=\"3\">\n";

  $files = readDirectory ();
  $i = 0;
  foreach ($files as $file){
    echo "<tr>\n";
    // checkbox
    echo "<td>\n";
    echo "<input type=\"checkbox\" name=\"lfd_".$i."\" value=\"".$file."\">";
    echo "</td>";

    echo "<td>\n";
    echo "<img  alt=\"Anhangdatei\" src=\"".$conf_pre_dir."/kats/showpic.php?file=".
          $conf_4f ["ablage_dir"]."/".$file."&width=500\"></td>\n";
    echo "</td>";

    echo "<td> <a href=\"".$conf_4f ["ablage_uri"]."/".$file."\" target=\"_blank\">$file</a></td>";
    // Dateiname
    //echo $file."<br>";
    echo "</tr>";
    $i++;
  }
  echo "</table>";
  echo "</form>";
?>
