<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/navigation.php';

$loginFlow = null;
$loginDestination = null;
foreach (array_keys($_GET) as $requestKey) {
   if (
      !is_string($requestKey)
      || !in_array($requestKey, ['login_flow', 'next'], true)
   ) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültige Anmeldeauswahl.';
      exit;
   }
}
if (array_key_exists('login_flow', $_GET)) {
   if (!is_string($_GET['login_flow'])) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültige Anmeldeauswahl.';
      exit;
   }
   $loginFlow = estab_auth_login_flow($_GET);
   if ($loginFlow === null) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültige Anmeldeauswahl.';
      exit;
   }
}
if (array_key_exists('next', $_GET)) {
   $loginDestination = estab_navigation_login_destination_key($_GET['next']);
   if ($loginDestination === null) {
      http_response_code(400);
      header('Content-Type: text/plain; charset=UTF-8');
      echo 'Ungültiges Anmeldeziel.';
      exit;
   }
}

session_start();
$mainFrameQuery = [];
if ($loginFlow !== null) {
   $mainFrameQuery['login_flow'] = $loginFlow;
}
if ($loginDestination !== null) {
   $mainFrameQuery['next'] = $loginDestination;
}
$mainFrameUrl = './mainindex.php'
   . ($mainFrameQuery === [] ? '' : '?' . http_build_query($mainFrameQuery));
$navigationFrameUrl = './vorgaben.php'
   . ($loginDestination === null
      ? ''
      : '?next=' . rawurlencode($loginDestination));
/*****************************************************************************\
   Datei: index.php

   Beschreibung:

          In dieser Datei wird der Frameset eingerichtet.
          links einStreifen mit der status.php
          rest die Datei mainindex.php

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
*****************************************************************************/
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
<HTML>
<HEAD>
<link REL="SHORTCUT ICON" HREF="../favicon.ico" />
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<meta name="author" content="Hajo Landmesser" >
<meta name="generator" content="Bluefish 2.2.5" >
<TITLE>Nachrichtenvordruck</TITLE>
<FRAMESET COLS="200,*" frameborder="0" framespacing="0" border="0">
   <FRAMESET ROWS="150,*" frameborder="0" framespacing="0" border="0">
      <FRAME NAME="counter" TITLE="counter" SRC="./counter.php?embedded=1" SCROLLING=NO MARGINWIDTH="0" MARGINHEIGHT="0" FRAMEBORDER="0" NORESIZE>
         <FRAMESET ROWS="360,*" frameborder="0" framespacing="0" border="0">
           <FRAME NAME="vorgaben" TITLE="vorgaben" SRC="<?= estab_auth_html($navigationFrameUrl) ?>" SCROLLING=AUTO MARGINWIDTH="0" MARGINHEIGHT="0" FRAMEBORDER="0" NORESIZE>
           <FRAME NAME="status" TITLE="status" SRC="./status.php?embedded=1" SCROLLING=NO MARGINWIDTH="0" MARGINHEIGHT="0" FRAMEBORDER="0" NORESIZE>
         </FRAMESET>
   </FRAMESET>
   <FRAME NAME="mainframe" TITLE="mainframe" SRC="<?= estab_auth_html($mainFrameUrl) ?>" SCROLLING=AUTO MARGINWIDTH="3" MARGINHEIGHT="3" FRAMEBORDER="0">
</FRAMESET>
</HEAD>
</HTML>

