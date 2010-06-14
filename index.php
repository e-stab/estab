<?php

define ("showmenue", true);


//include ("./4fcfg/config.inc.php");
include ("menue.inc.php");

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<meta content=\"text/html; charset=ISO-8859-1\" http-equiv=\"content-type\">\n";
    echo "<title>".$conf_menue ["titel"]."</title>\n";
    echo "</head>\n";
    echo "<body  style=\"background-color: rgb(240, 100, 100);\">\n";
    echo "<div style=\"text-align: center;\">";
    echo "<table style=\"background-color: rgb(150, 150, 150); text-align: center; margin-left: auto; margin-right: auto;\" border=\"3\" cellpadding=\"3\" cellspacing=\"3\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"text-align: center; width: 200px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
    echo "<p><img src=\"".$conf_menue ["sym_top_left"]."\" alt=\"taktisches Zeichen EL\"></p>";
    echo "</td>\n";
    echo "<td style=\"text-align: center; width: 600px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
    echo "<p><big><big><big><big>".$conf_menue["einrichtung"]."</big></big></big></p>";
    echo "</td>\n";
    echo "<td style=\"text-align: center; width: 200px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
    echo "<p><img src=\"".$conf_menue ["sym_top_right"]."\" alt=\"taktisches Zeichen IuK\"></p>";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</div>";
    echo "<div style=\"text-align: center;\">";
    echo "<br><br><br>";

    echo "<table style=\"background-color: rgb(150, 150, 150); text-align: left; margin-left: auto; margin-right: auto;\" border=\"1\" cellpadding=\"3\" cellspacing=\"3\">\n";
    echo "<tbody>\n";

    for ($m=1;$m <= count ($menue);$m++){

      $is_gerade = ($m % 2) == 0;
      if (!$is_gerade){echo "<tr>\n";}
        if ($menue[$m][link] != ""){
          echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a  href=\"".$menue[$m][link]."\" target=\"_blank\"><img src=\"".$menue[$m][pic]."\" alt=\"".$menue[$m][text]."\"></a>";
          echo "</td>\n";
        } else {
          echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
          echo "</td>\n";
        }

        if ($menue[$m][link] != ""){
          echo "<td style=\"text-align: center; width: 250px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a  href=\"".$menue[$m][link]."\" target=\"_blank\"><big><big>".$menue[$m][text]."</big></a>\n";
          echo "</td>\n";
        } else {
          echo "<td style=\"text-align: center; width: 250px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
          echo "</td>\n";
        }

      if (!$is_gerade){
        echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
        echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
        echo "</td>\n";
      }
      if ($is_gerade){echo "</tr>\n";}
    }
    if (!((count ($menue) % 2) == 0)) { // ist nicht gerade ==> nur bis zu einer linken Spalte
      // es muss eine leere rechte Spalte erstellt werden.
      echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
      echo "<img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\">";
      echo "</td>\n";

      echo "<td style=\"text-align: center; width: 250px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
      echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
      echo "</td>\n";

      echo "</td>\n";
    }
//    echo "</tbody>\n";
//    echo "</table>\n";

    if (showmenue){
//      echo "<table style=\"background-color: rgb(150, 150, 150); text-align: left; margin-left: auto; margin-right: auto;\" border=\"1\" cellpadding=\"3\" cellspacing=\"3\">\n";
//      echo "<tbody>\n";

      for ($m=1;$m <= count ($zusatz_menue);$m++){

        $is_gerade = ($m % 2) == 0;
        if (!$is_gerade){echo "<tr>\n";}
          if ($zusatz_menue[$m][link] != ""){
            echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a  href=\"".$zusatz_menue[$m][link]."\" target=\"_blank\"><img src=\"".$zusatz_menue[$m][pic]."\" alt=\"".$zusatz_menue[$m][text]."\"></a>";
            echo "</td>\n";
          } else {
            echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
            echo "</td>\n";
          }

          if ($zusatz_menue[$m][link] != ""){
            echo "<td style=\"text-align: center; width: 250px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a  href=\"".$zusatz_menue[$m][link]."\" target=\"_blank\"><big><big>".$zusatz_menue[$m][text]."</big></a>\n";
            echo "</td>\n";
          } else {
            echo "<td style=\"text-align: center; width: 250px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
            echo "</td>\n";
          }

        if (!$is_gerade){
          echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
          echo "</td>\n";
        }
        if ($is_gerade){echo "</tr>\n";}
      }
      if (!((count ($zusatz_menue) % 2) == 0)) { // ist nicht gerade ==> nur bis zu einer linken Spalte
        // es muss eine leere rechte Spalte erstellt werden.
        echo "<td style=\"text-align: center; width: 100px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
        echo "<img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\">";
        echo "</td>\n";

        echo "<td style=\"text-align: center; width: 250px; background-color: rgb(240, 100, 100);\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
        echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
        echo "</td>\n";

        echo "</td>\n";
      }
    }
  echo "</tbody>\n";
  echo "</table>\n";
    echo "</body>\n";
    echo "</head>\n";

?>
