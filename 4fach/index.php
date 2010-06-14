<?php session_start ();
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
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=ISO-8859-1">
<META NAME="Author" CONTENT="Hajo Landmesser">
<META NAME="Generator" CONTENT="self">
<TITLE>Nachrichtenvordruck</TITLE>
<FRAMESET COLS="100,*" frameborder="0" framespacing="0" border="0">
   <FRAMESET ROWS="150,*" frameborder="0" framespacing="0" border="0">
      <FRAME NAME="counter" TITLE="counter" SRC="./counter.php" SCROLLING=NO MARGINWIDTH="0" MARGINHEIGHT="0" FRAMEBORDER="0" NORESIZE>
         <FRAMESET ROWS="150,*" frameborder="0" framespacing="0" border="0">
           <FRAME NAME="vorgaben" TITLE="vorgaben" SRC="./vorgaben.php" SCROLLING=NO MARGINWIDTH="0" MARGINHEIGHT="0" FRAMEBORDER="0" NORESIZE>
           <FRAME NAME="status" TITLE="status" SRC="./status.php" SCROLLING=NO MARGINWIDTH="0" MARGINHEIGHT="0" FRAMEBORDER="0" NORESIZE>
         </FRAMESET>
   </FRAMESET>
   <FRAME NAME="mainframe" TITLE="mainframe" SRC="./mainindex.php" SCROLLING=AUTO MARGINWIDTH="3" MARGINHEIGHT="3" FRAMEBORDER="0">
</FRAMESET>
</HEAD>
</HTML>

