<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
<html>
<head>
  <meta http-equiv="CONTENT-TYPE" content="text/html; charset=iso">
  <title>Administrative Massnahme</title>
  <meta name="GENERATOR" content="OpenOffice.org 2.0  (Linux)">
  <meta name="AUTHOR" content="Hajo Landmesser">
  <meta name="CREATED" content="20070327;15421200">
  <meta name="CHANGEDBY" content="hajo">
  <meta name="CHANGED" content="20080612;18052200">
  <style type="text/css">

        A:link    { color:#0000EE; text-decoration:none; font-weight:normal; font-size:larger ; }
        A:visited { color:#0000EE; text-decoration:none; font-weight:normal; font-size:larger ; }
        A:hover   { color:#000000; text-decoration:none;  }
        A:active  { color:#0000EE; background-color:#FFFF99; }
        A:focus   { color:#0000EE; background-color:#FFFF99; }

        </style>
</head>
<body>
<p style="border: 4px double rgb(216, 253, 2); padding: 40px; background-color: rgb(128, 128, 64); color: rgb(216, 253, 2); font-family: 'Century Schoolbook',serif; font-size: 2em; letter-spacing: 3px;" align="center">Administrative Ma&szlig;nahmen</p>
<table border="1" cellpadding="3" cellspacing="3" width="100%">

  <tbody>
  </tbody><col width="100*">
        <col width="156*">

        <tbody>
    <tr>

                <td width="39%">
                <a href="./make_conf.php?task=einsatz_neu">EINSATZ erstellen</a>
                </td>

                <td valign="top" width="61%">
                
      <p><b>Anlegen eines neuen Einsatz.</b></p>
      <p>Es wird eine Datenbank und ein Verzeichnis angelegt.&nbsp;<b></b></p>

                </td>

        </tr>


        <tr>

                <td width="39%">
                <a href="./make_fkt.php">Empf&auml;ngermatrix bearbeiten</a>
                </td>

                <td valign="top" width="61%">

      <p>Bearbeiten der Empf&auml;nger (Fachberater) im Sichterbereich sowie der
                   Funktionen die sich anmelden k&ouml;nnen.</p>

                </td>

        </tr>


        
  </tbody>
</table>


        
<hr style="border: 1px dashed blue; width: 100%; color: yellow; background-color: yellow; height: 10px; margin-right: 0pt; text-align: left;">


        
<table border="1" cellpadding="3" cellspacing="3" width="100%">

        <tbody>


        <tr>

                <td width="39%">
                <a href="./make_conf.php?task=einsatz_ende">EINSATZ beenden</a>
                </td>

                <td valign="top" width="61%">
                
      <p><b>Abschliessen eines Einsatz.</b></p>
      <p>Alle Tabellen der Datenbank werden als CSV-Datei in das Einsatzverzeichnis gespeichert.<b> </b></p>

                </td>

        </tr>
  </tbody>
</table>

<hr style="border: 1px dashed blue; width: 100%; color: yellow; background-color: yellow; height: 10px; margin-right: 0pt; text-align: left;">
<table border="1" cellpadding="3" cellspacing="3" width="100%">

        <tbody>
        <tr>

                <td width="39%">
                <a href="./4fach/resetpic.php">Grafik zur&uuml;cksetzen</a>
                </td>

                <td valign="top" width="61%">
                
      <p>Zur&uuml;cksetzen des&nbsp;Grafikflags in der Datenbank </p>

                </td>

        </tr>


        <tr>

                <td width="39%">
                <a href="./bak/backup.php?anz=50">Grafiken erzeugen</a>
                </td>

                <td valign="top" width="61%">

      <p>Es wird versucht 50 Nachrichten in Grafiken zu konvertiert.</p>

                </td>

        </tr>



  </tbody>
</table>

 
        
<hr style="border: 1px dashed blue; width: 100%; color: yellow; background-color: yellow; height: 10px; margin-right: 0pt; text-align: left;">

        
<table border="1" cellpadding="3" cellspacing="3" width="100%">

        <tbody>



        <tr>

                <td width="39%">
                <a href="./make_conf.php?task=datenbank">Datenbankparameter eingeben</a>
                </td>

                <td valign="top" width="61%">
                
      <p>Anlegen der Datenbankparameter.<br>
Serveradresse, Datenbankbenutzer, Tabellenprefix usw.</p>

                </td>

        </tr>

        <tr>

                <td width="39%">
                <a href="./db_check.php">Datenbankverbindung pr&uuml;fen</a>
                </td>

                <td valign="top" width="61%">
                
      <p>Hiermit kann gepr&uuml;ft werden ob mit den gegebenen Einstellungen eine
                   Verbindung zur Datenbank aufgebaut werden kann.</p>

                </td>

        </tr>

        <tr>

                <td width="39%">
                <a href="./create_db.php">Anlegen der Datenbank und der Tabellen</a>
                </td>

                <td valign="top" width="61%">
                
      <p>Die Datenbank und die erforderlichen Tabellen werden angelegt, soweit diese nicht
                   vorhanden sind.</p>

                </td>

        </tr>



  </tbody>
</table>



<hr style="border: 1px dashed blue; width: 100%; color: yellow; background-color: yellow; height: 10px; margin-right: 0pt; text-align: left;">


<table border="1" cellpadding="3" cellspacing="3" width="100%">

        <tbody>

<?php /*

        <TR>
                <TD WIDTH=39%>
                <A HREF="./FMD_statis.php">Betriebszustand/Statistiken</A>
                </TD>
                <TD WIDTH=61% VALIGN=TOP>
                <P>Anzeige des Betriebszustandes der Fernmeldezentrale</P>
                </TD>
        </TR>
        <TR>
                <TD WIDTH=39%>
                        <P><FONT FACE="Arial Black"><FONT SIZE=4><A HREF="./edt_para.php">Anzeigeparameter
                        </A></FONT></FONT></P>
                </TD>
                <TD WIDTH=61% VALIGN=TOP>
                        <P><FONT FACE="Courier, monospace"><FONT SIZE=4>Einstellung der Farben und der
                        Aktualisierungsintervalle</FONT></FONT></P>
                </TD>
        </TR>
*/
?>
        <tr>

                <td width="39%">
                <a href="./phpinfo.php">PHP Info</a>
                </td>

                <td valign="top" width="61%">

      <p>Informationsseite zur PHP Installation</p>

                </td>

        </tr>



  </tbody>
</table>

</body>
</html>
