<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../app/session_ui.php';
estab_session_ui_start($_SESSION, true);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');

?>
<!doctype html>
<html>
<head>



  <meta content="text/html; charset=UTF-8" http-equiv="content-type">
  <meta name="viewport" content="width=device-width, initial-scale=1">


  <title>Index.html</title>
</head>


<body style="background-color: rgb(51, 204, 0); color: rgb(0, 0, 0);" alink="#ee0000" link="#0000ee" vlink="#551a8b">

<big style="font-weight: bold;"><big>Info-Bereiche</big></big>
<table style="text-align: left; width: 167px; background-color: rgb(153, 255, 153);" border="0" cellpadding="0" cellspacing="10">

  <tbody>

    <tr>

      <td style="width: 157px;" align="undefined" valign="undefined">
        <a href="Buchstabier.html" target="mainframe"><big><span style="font-weight: bold;">Buchstabieralphabet</span></big></a></td>

    </tr>

    <tr>

      <td style="width: 157px;" align="undefined" valign="undefined">
        <a href="Kartendatum.html" target="mainframe"><big><span style="font-weight: bold;">neues Kartendatum</span></big></a></td>

    </tr>

    <tr>

      <td style="width: 157px;" align="undefined" valign="undefined">
      <big style="font-weight: bold;">
      <a href="IuK-InfoPack.html" target="mainframe">Stabzusammensetzung</a></big></td>

    </tr>


    <tr>

      <td style="width: 157px; background-color: rgb(153, 255, 153);" align="undefined" valign="undefined">
      <big style="font-weight: bold;">
      <a href="Orgas.html" target="mainframe">Behörden und<br>
Organisationen</a></big></td>

    </tr>


    <tr>

      <td style="width: 157px;" align="undefined" valign="undefined">
      <big style="font-weight: bold;">
      <a href="FF-Rufnamenschema.html" target="mainframe">F-Rufnamenregel</a></big></td>

    </tr>

    <tr>

      <td style="width: 157px;" align="undefined" valign="undefined">
      <big style="font-weight: bold;">
      <a href="DRK%20Rufnamenschema.html" target="mainframe">DRK-Rufnamenregel</a></big></td>

    </tr>


    <tr>

      <td style="width: 157px;" align="undefined" valign="undefined">
      <big style="font-weight: bold;">
      <a href="THWFuRNR.html" target="mainframe">THW-Rufnamenregel</a></big></td>

    </tr>

  </tbody>
</table>

<br>

</body>
</html>
