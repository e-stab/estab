 <?php
  echo "<h1 align=\"center\">Problembericht</h1>\n";
  if ( isset($_GET) ){
    if (isset ($_GET[sub])){
      echo "<big><big>$_GET[sub]</big></big>\n";
    }
    echo "<br><br>";
    if (isset ($_GET[info])){
      echo "<big><big>$_GET[info]</big></big>\n";
    }
  }

  echo "<br><br><br>\n";
  echo "<input align=\"center\" type=\"button\" value=\"Fenster zu\" onClick=\"javascript:window.close()\"><br>";

?>
