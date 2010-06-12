<?php

 define ("debug",false);


 include ("hilfetext.php");

// var_dump ($Infotext);

/*

    if (isset ($_GET ['Errorart'])){
      $errorkind = $_GET ['Errorart'];
    }

    if ( debug == true ){
      echo "<br><br>\n";
      echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
      echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
      echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
      //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
      echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
    }

    // Wie lautet der Infotext
    switch ($errorkind){
      case '01_medium':
        $titel = 'Aufnahmevermerk';
        $info  = '<b><big>Übermittlungsmedium:</big></b><br>Ein Wert <b>muss</b> angegeben werden.<br><table><tr><td><b>Fe</b></td><td> - Fernsprecher<br></td></tr><tr><td><b>Fu</b></td><td> - Funk<br></td></tr><tr><td><b>Me</b></td><td> - Melder<br></td></tr><tr><td><b>Fax</b></td><td> - Telefaksimile<br></td></tr><tr><td><b>@</b></td><td> - Datenübertragung aller Art (HTTP, FTP, SMTP...)</td></tr></table>';
      break;
      case '01_datum':
      case '02_zeit':
      case '03_datum':
      case '12_abfzeit':
      case '15_quitdatum':
        // Wie lautet der Titel
        switch ($errorkind){
         case '01_datum':
           $titel = 'Aufnahmevermerk';
         break;
         case '02_zeit':
           $titel = 'Aufnahmevermerk';
         break;
         case '03_datum':
           $titel = 'Bef&ouml;rderungsvermerk';
         break;
         case '12_abfzeit':
           $titel = 'Abfassungszeit';
         break;
         case '15_quitdatum':
           $titel = 'Quittung';
         break;
        }
       $info  = '<b><big>Datums/Zeitformat:</big></b><br><b>keine Eingabe</b> - es wird automatisch die Serverzeit (links) eingetragen.<br><b>hhmm</b> - Stunde und Minute<br><b>TThhmm</b> - Tag, Stunde und Minute kurze taktische Zeit.<br><b>TThhmmMMMJJJJ</b> - Tag, Stunde, Minute, Monat und jahr<br>vollständige taktische Zeit.';
      break;

      case '01_zeichen':
      case '02_zeichen':
      case '03_zeichen':
      case '15_quitzeichen':
        // Wie lautet der Titel
        switch ($errorkind){
         case '01_zeichen':
           $titel = 'Aufnahmevermerk';
         break;
         case '02_zeichen':
           $titel = 'Aufnahmevermerk';
         break;
         case '03_zeichen':
           $titel = 'Bef&ouml;rderungsvermerk';
         break;
         case '15_quitzeichen':
           $titel = 'Quittung';
         break;
        }
       $info  = 'Hier muss ein K&uuml;rzel eingegeben werden.';
      break;

      case '10_anschrift':
        $titel = 'Anschrift';
        $info  = 'Ziel für diese Meldung.';
      break;

      case '12_inhalt':
        $titel = 'Inhalt';
        $info  = 'Ganz wichtig, hier fehlt der Meldungstext! ';
      break;

      case '13_abseinheit':
        $titel = 'Absender';
        $info  = 'Hier fehlt die Bezeichnung des Absenders. ';
      break;
    }
*/

    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=iso\">\n";
    echo "<title>".$Infotext[$_GET ['Errorart']][0]."</title>\n";
    echo "</head>\n";
    echo "<body>";
    echo $Infotext[$_GET ['Errorart']][1];
    echo "</body>";
    echo "</html>";

?>
