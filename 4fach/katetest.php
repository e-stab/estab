<?php
session_start ();

define("debug",true);

echo "<html>";

echo "<head>";
echo "<title>Kategorietest</title>";
echo "</head>";

echo "<body>";

include ("./katego.php");

$kate = new kategorien ("master") ;

$kate->pulldown_kategorien (1);

echo "</body>";

echo "</html>";

?>
