<?php
    include ("../para.inc.php");
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=iso\">\n";
    echo "<title>".$titel." ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"]."</title>\n";
    echo "<style type=\"text/css\">";

    echo "\n";

    echo "</style>";
    echo "</head>\n";
    echo "<body>";

    
      echo "<div style=\"height:21px; 
                         background-color:#FFC8C8; 
                         border-top-color:#FFC8C8; 
                         border-left-color:#FFC8C8; 
                         border-right-color:#FFC8C8; 
                         border-bottom-color:#000000; 
                         border-width:2px; 
                         border-style:solid; 
                         padding-top:10px; 
                         padding-left:10px; 
                         padding-right:10px; 
                         padding-bottom:0px;\">\n";
      $color = "red";
      $min = 1;
      $max = 16;
      $gewinner = rand  ($min  , $max  ) ; 
      for ($i=$min; $i<= $max; $i++) {
        if ($i == $gewinner){
          $color = "red";
          echo "<a href=\"puup\"><img src=\"./kategobutton.php?icontext=Test".$i."&color=".$color."\"   alt=\"".$i."\" border=\"0\" title=\"".$i."\"></a>\n";
        }else{
          $color = "lightred";
          echo "<a href=\"puup\"><img src=\"./kategobutton.php?icontext=Test".$i."&color=".$color."\"   alt=\"".$i."\" border=\"0\" title=\"".$i."\"></a>\n";
        }
      }
    
      echo "</div>";



    echo "</body>";
    echo "</html>";



?>