<?php

    $TimeInfo =  '<b><big><big>Datums/Zeitformat:</big></big></b>
                <table border=\"0\" cellspacing=\"5\" cellpadding=\"5\"><tbody>
                <tr><td><b>keine Eingabe</b></td><td>es wird automatisch die Serverzeit eingetragen.</td></tr>
                <tr><td><b>hhmm</b></td><td>Stunde und Minute<br>
                                   hh - Stunde 2 stellig<br>
                                   mm - Minuten 2 stellig</td></tr>
                <tr><td><b>TThhmm</b></td><td>Tag, Stunde und Minute kurze taktische Zeit.<br>
                                   TT - Tag des Monats 2 stellig.</td></tr>
                <tr><td><b>TThhmmMMMJJJJ</b></td><td>Tag, Stunde, Minute, Monat und jahr<br>
                                                     vollständige taktische Zeit.<br>
                           MMM - Monat als Kürzen sind zulässig:(jan,feb,mar,apr,mai,may,jun,jul,aug,sep,okt,oct,nov,dez,dec)</td></tr>
                </tbody></table><br>Die Analyse und Fehlererkennung des Formates setzt auf die Länge der Zeichenfolge.<br>
                Aus diesem Grund muss bei der Eingabe vom Tag, Stunde und Minute auf die führende Null "0"
                geachtet werden. Wichtig ist auch das Jahr 4 stellig einzugeben.';

    $KuerzelInfo = '';

    $ZeichenInfo = 'Hier muss ein K&uuml;rzel eingegeben werden das im System dokumentiert ist.<br><br>unter Umständen muss das Kürzel im ETB dokumentiert werden.';

    $Infotext = array (
      '01_medium'      =>  array ('Aufnahmevermerk',
                                  '<b><big>Übermittlungsmedium:</big></b><br>
                                  Ein Wert <b>muss</b> angegeben werden.<br>
                                  <table border=\"0\" cellspacing=\"5\" cellpadding=\"5\">
                                  <tr><td><b>Fe</b></td><td> Fernsprecher</td></tr>
                                  <tr><td><b>Fu</b></td><td> Funk</td></tr>
                                  <tr><td><b>Me</b></td><td> Melder</td></tr>
                                  <tr><td><b>Fax</b></td><td>Telefaksimile</td></tr>
                                  <tr><td><b>@</b></td><td>elektronische Datenübertragung (HTTP, FTP, SMTP...)<br>
                                  Das Zeichen "@" ist hier nicht offiziell. In anderen Vordrucken wird des K&uuml;rze "DFÜ" benutzt.</td></tr></table>'),

      '01_datum'       =>  array ('Aufnahmevermerk',          $TimeInfo),
      '01_zeichen'     =>  array ('Aufnahmevermerk',          $ZeichenInfo),

      '02_zeit'        =>  array ('Annahmevermerk',           $TimeInfo),
      '02_zeichen'     =>  array ('Annahmevermerk',           $ZeichenInfo),

      '03_datum'       =>  array ('Bef&ouml;rderungsvermerk', $TimeInfo),
      '03_zeichen'     =>  array ('Bef&ouml;rderungsvermerk', $ZeichenInfo),

      '10_anschrift'   =>  array ('Anschrift',                '<b><big>Anschrift</big></b><br>
                                                               Bei einem Nachrichteneingang ist hier die Führungsstelle gemeint.
                                                               Insbesondere kann hier auch eine Sachbearbeiter eingetragen werden.<br><br>
                                                               Bei einem Nachrichtenausgang muss hier möglichst präzise das Ziel der Meldung angegeben werden.
                                                               Im Feld "Beförderungshinweis" können spezifische Informationen über die Erreichbarkeit eingetragen werden.'),

      '12_inhalt'      =>  array ('Inhalt',                   '<big><big><b>Meldungstext!</b></big></big><br>
                                                              Text der zu übermittelnden Nachricht ...
                                                              <li> kurz und knapp, aber trotzdem unmißverständlich</li>
                                                              <li> keine Taktischen Zeichen oder Schadensymbole</li>
                                                              <li> Leserlich schreiben!</li>
                                                              <li> Orts- und Straßenangaben in Großbuchstaben</li>
                                                              <br><br>
                                                               Einsatzbefehle an Befehlsschema anpassen<br>
                                                               <b>Einheit - Auftrag - Mittel - Ziel - Weg</b> <br><br>
                                                               Sonstige Nachrichten an „W“-Fragen orientieren:<br>
                                                               <b>Wer – Wo – Was – Wann - Wie</<b>'),
      '12_abfzeit'     =>  array ('Abfassungszeit',           $TimeInfo),
      '13_abseinheit'  =>  array ('Absender',                 '<big><big><b>Absender</b></big></big><br>
                                                              <li>Der Verfasser trägt den Absender ein</li>
                                                              <li>Absender ist immer die Einheit oder Einrichtung, nicht die Person/Funktion</li>'),
      '15_quitdatum'   =>  array ('Quittung',                 $TimeInfo),
      '15_quitzeichen' =>  array ('Quittung',                 $ZeichenInfo)
    );

?>
