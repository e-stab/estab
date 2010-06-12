<?php



    $text_01 =

    $zeittext = "'<b><big>Datums/Zeitformat:</big></b><br><b>keine Eingabe</b> - es wird automatisch die Serverzeit (links) eingetragen.<br><b>hhmm</b> - Stunde und Minute<br><b>TThhmm</b> - Tag, Stunde und Minute kurze taktische Zeit.<br><b>TThhmmMMMJJJJ</b> - Tag, Stunde, Minute, Monat und jahr<br>vollständige taktische Zeit.'";

    echo "<DIV ID=\"infodiv\" STYLE=\"position:absolute; visibility:hidden; z-index:20; top:0px; left:0px;\"></DIV>\n";
    echo "<SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\">\n";
    echo "maketip('01_medium'     ,'Aufnahmevermerk','<b><big>Übermittlungsmedium:</big></b><br>Ein Wert <b>muss</b> angegeben werden.<br><table><tr><td><b>Fe</b></td><td> - Fernsprecher<br></td></tr><tr><td><b>Fu</b></td><td> - Funk<br></td></tr><tr><td><b>Me</b></td><td> - Melder<br></td></tr><tr><td><b>Fax</b></td><td> - Telefaksimile<br></td></tr><tr><td><b>@</b></td><td> - Datenübertragung aller Art (HTTP, FTP, SMTP...)</td></tr></table>');";
    echo "maketip('01_datum'      ,'Aufnahmevermerk',$zeittext);";
    echo "maketip('01_zeichen'    ,'Aufnahmevermerk','Hier muss ein K&uuml;rzel eingegeben werden.');";
    echo "maketip('02_zeit'       ,'Annahmevermerk',$zeittext);";
    echo "maketip('02_zeichen'    ,'Annahmevermerk','Hier muss ein K&uuml;rzel eingegeben werden.');";
    echo "maketip('03_datum'      ,'Bef&ouml;rderungsvermerk','Datumsformat:<br><b>hhmm</b> - Stunde und Minute<br><b>TThhmm</b> - Tag, Stunde und Minute kurze taktische Zeit.<br><b>TThhmmMMMJJJJ</b> - Tag, Stunde, Minute, Monat und jahr<br>vollständige taktische Zeit.');";
    echo "maketip('03_zeichen'    ,'Bef&ouml;rderungsvermerk','Hier muss ein K&uuml;rzel eingegeben werden.');";
    echo "maketip('10_anschrift'  ,'Anschrift','Ziel für diese Meldung.');";
    echo "maketip('12_inhalt'     ,'Inhalt','Ganz wichtig, hier fehlt der Meldungstext! ');";
    echo "maketip('12_abfzeit'    ,'Abfassungszeit',$zeittext);\n";
    echo "maketip('13_abseinheit' ,'Absender','Hier fehlt die Bezeichnung des Absenders.');";
    echo "maketip('15_quitdatum'  ,'Quittung',$zeittext);";
    echo "maketip('15_quitzeichen','Quittung','Hier muss ein K&uuml;rzel eingegeben werden.');";
    echo "</SCRIPT>\n";



?>
