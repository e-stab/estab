<?php

define ("showmenue", true);

require_once __DIR__ . "/app/session_ui.php";
if (session_status () !== PHP_SESSION_ACTIVE) {
    session_start ();
}
estab_session_ui_start ($_SESSION);
$rootIdentity = estab_auth_session_identity ($_SESSION);

/*eStab
         ---------------------
   -------                 --------------
             SSSSSSS    tt             aa
            SS          tt             aa
    eeeeee\ SS         ttttt  aaaaaa   aa
   ee |  ee\ SSSSSSS    tt         aa  aaaaaa
   eeeeeeEe |      SS   tt    aaaaaaa  aa    aa
   ee_|____/       SS   tt   aa    aa  aa    aa
    eeeeee | SSSSSSS    tttt  aaaaaaa  aaaaaaa
   \______/
 
 

*/


//include ("./4fcfg/config.inc.php");
include ("menue.inc.php");

    echo "<!doctype html>\n";
    echo "<html lang=\"de\">\n";
    echo "<head>\n";
    echo "<link REL=\"SHORTCUT ICON\" HREF=\"favicon.ico\" />";
    echo "<link rel=\"stylesheet\" href=\"./estab-ui.css\">";
    echo "<meta content=\"text/html; charset=UTF-8\" http-equiv=\"content-type\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<title>".$conf_menue ["titel"]."</title>\n";
    echo "</head>\n";
    echo "<body  style=\"background-color: ".$conf_menue["background_color"].";\">\n";
    echo "<div style=\"text-align: center;\">";
    echo "<table class=\"estab-root-header\" style=\"background-color: rgb(150, 150, 150); text-align: center; margin-left: auto; margin-right: auto;\" border=\"3\" cellpadding=\"3\" cellspacing=\"3\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"text-align: center; width: 200px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
    echo "<p><img src=\"".$conf_menue ["sym_top_left"]."\" alt=\"taktisches Zeichen EL\"></p>";
    echo "</td>\n";
    echo "<td style=\"text-align: center; width: 600px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
    echo "<p><big><big><big><big>".$conf_menue["einrichtung"]."</big></big></big></p>";
    echo "</td>\n";
    echo "<td style=\"text-align: center; width: 200px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
    echo "<p><img src=\"".$conf_menue ["sym_top_right"]."\" alt=\"taktisches Zeichen IuK\"></p>";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</div>";
    echo "<main>\n";
    echo "<section class=\"estab-login-cta\" aria-labelledby=\"estab-login-title\">\n";
    if ($rootIdentity === null) {
      echo "<h1 id=\"estab-login-title\">eStab-Anmeldung</h1>\n";
      echo "<p>Melden Sie sich mit einem bestehenden Funktionskonto an oder legen Sie ein neues Konto an, sofern die Registrierung freigeschaltet ist.</p>\n";
      echo "<p><a id=\"estab-login\" class=\"estab-button estab-button-primary\" href=\"./4fach/index.php\">Anmelden oder Konto anlegen</a></p>\n";
    } else {
      echo "<h1 id=\"estab-login-title\">eStab öffnen</h1>\n";
      echo "<p>Ihre Anmeldung ist aktiv. Öffnen Sie den Nachrichtenvordruck oder wählen Sie unten einen weiteren geschützten Bereich.</p>\n";
      echo "<p><a id=\"estab-open\" class=\"estab-button estab-button-primary\" href=\"./4fach/index.php\">Zum Nachrichtenvordruck</a></p>\n";
    }
    echo "<p><small>Die separate Administration finden Sie weiterhin unter „administrative Massnahme“.</small></p>\n";
    echo "</section>\n";
    echo "<div style=\"text-align: center;\">";
    echo "<br><br><br>";

    echo "<table class=\"estab-root-menu\" style=\"background-color: rgb(150, 150, 150); text-align: left; margin-left: auto; margin-right: auto;\" border=\"1\" cellpadding=\"3\" cellspacing=\"3\">\n";
    echo "<tbody>\n";

    for ($m=1;$m <= count ($menue);$m++){

      $is_gerade = ($m % 2) == 0; // linke Spalte des Menüs
      if (!$is_gerade){echo "<tr>\n";} // neue Zeile also erstmal ein <TR>

		if (($menue[$m]['visible']) AND ($menue[$m]['link'] != "")) { // 1. Spalte links (Pictogramme)
			echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
         echo "<a  href=\"".$menue[$m]['link']."\" target=\"_blank\"><img src=\"".$menue[$m]['pic']."\" title=\"".$menue[$m]['info']."\" alt=\"".$menue[$m]['text']."\"></a>";
         echo "</td>\n";
		} else {
         echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
         echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
         echo "</td>\n";
      }

      if (($menue[$m]['visible']) AND ($menue[$m]['link'] != "")){ // 2. Spalte links Text
          echo "<td style=\"text-align: center; width: 250px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a  href=\"".$menue[$m]['link']."\" title=\"".$menue[$m]['info']."\" target=\"_blank\"><big><big>".$menue[$m]['text']."</big></a>\n";
          echo "</td>\n";
        } else {
          echo "<td style=\"text-align: center; width: 250px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
          echo "</td>\n";
        }

      if (!$is_gerade){
        echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
        echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
        echo "</td>\n";
      }
      if ($is_gerade){echo "</tr>\n";}
    }
    if (!((count ($menue) % 2) == 0)) { // ist nicht gerade ==> nur bis zu einer 'link'en Spalte
      // es muss eine leere rechte Spalte erstellt werden.
      echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
      echo "<img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\">";
      echo "</td>\n";

      echo "<td style=\"text-align: center; width: 250px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
      echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
      echo "</td>\n";

      echo "</td>\n";
    }
//    echo "</tbody>\n";
//    echo "</table>\n";

    if (showmenue){
//      echo "<table style=\"background-color: rgb(150, 150, 150); text-align: left; margin-left: auto; margin-right: auto;\" border=\"1\" cellpadding=\"3\" cellspacing=\"3\">\n";
//      echo "<tbody>\n";

      for ($m=1;$m <= count ($zusatz_menue);$m++){

        $is_gerade = ($m % 2) == 0;
        if (!$is_gerade){echo "<tr>\n";}
          if ($zusatz_menue[$m]['link'] != ""){
            echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a  href=\"".$zusatz_menue[$m]['link']."\" target=\"_blank\"><img src=\"".$zusatz_menue[$m]['pic']."\" title=\"".$menue[$m]['info']."\" alt=\"".$zusatz_menue[$m]['text']."\"></a>";
            echo "</td>\n";
          } else {
            echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
            echo "</td>\n";
          }

          if ($zusatz_menue[$m]['link'] != ""){
            echo "<td style=\"text-align: center; width: 250px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a  href=\"".$zusatz_menue[$m]['link']."\" title=\"".$zusatz_menue[$m]['info']."\" target=\"_blank\"><big><big>".$zusatz_menue[$m]['text']."</big></a>\n";
            echo "</td>\n";
          } else {
            echo "<td style=\"text-align: center; width: 250px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
            echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
            echo "</td>\n";
          }

        if (!$is_gerade){
          echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
          echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" title=\"".$menue[$m]['info']."\" alt=\"leer\"></a>";
          echo "</td>\n";
        }
        if ($is_gerade){echo "</tr>\n";}
      }
      if (!((count ($zusatz_menue) % 2) == 0)) { // ist nicht gerade ==> nur bis zu einer 'link'en Spalte
        // es muss eine leere rechte Spalte erstellt werden.
        echo "<td style=\"text-align: center; width: 100px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
        echo "<img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\">";
        echo "</td>\n";

        echo "<td style=\"text-align: center; width: 250px; background-color: ".$conf_menue["foreground_color"].";\" BORDER=\"0\" CELLPADDING=\"1\" CELLSPACING=\"0\">\n";
        echo "<a><img src=\"".$conf_menue ["symbole"]."/null.gif \" alt=\"leer\"></a>";
        echo "</td>\n";

        echo "</td>\n";
      }
   }
	echo "</tbody>\n";
	echo "</table>\n";
    echo "</div>\n";
    echo "</main>\n";
	echo "</body>\n";
	echo "</html>\n";

?>
