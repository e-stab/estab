<?php


/****************************************************************************\

\****************************************************************************/
  function check_fkt ($values){
/*
echo "<br><br><br>";
var_export ($values);
echo "<br><br><br>";
var_dump ($values);
echo "<br><br><br>";
*/
  }



/****************************************************************************\

\****************************************************************************/
  function write_fkt_file ($values){

    include ("./config.inc.php");
    include ("./fkt_rolle.inc.php");

// print_r ($values); echo "<br><br><br>";


    $prefile = "<"."?"."php \r\n".
      "/"."******************************************************************************\ \r\n".
      "       Definition der Mitspieler \r\n".
      "|-----|-----|-----|-----|\r\n".
      "| 1.1 | 2.1 | 3.1 | 4.1 |\r\n".
      "| 1.2 | 2.2 | 3.2 | 4.2 |\r\n".
      "| 1.3 | 2.3 | 3.3 | 4.3 |\r\n".
      "| 1.4 | 2.4 | 3.4 | 4.4 |\r\n".
      "| 1.5 | 2.5 | 3.5 | 4.5 |\r\n".
      "|-----|-----|-----|-----|\r\n".
      "\******************************************************************************"."/\r\n".
      "/"."/              lfd-Nr      PosForm  Fkt\r\n".
      "/"."/                       Spalte,Zeile          Roll\r\n";

    $fix = "\r\n\r\n";

    $anmelde = "\r\n\r\n";
    $user_count = 0; // Für die Anmeldeliste
    $matrix = "$"."empf_matrix = array ( \r\n";
    for ($zeile = 1; $zeile <= 5; $zeile ++) {
      $matrix .= "$zeile => array ( \r\n";
      for ($spalte = 1; $spalte <= 4; $spalte ++){
        $matrix .= "\t $spalte => array (";
        if ($values[rolle.$zeile.$spalte] == "leer"){ $matrix .= "\"typ\" => \"t\", "; }
        else { $matrix .=  "\"typ\" => \"cb\", "; }
        $matrix .= "\"fkt\" => \"".$values[pos_.$zeile.$spalte]."\", ".
                   "\"rolle\" => \"".$values[rolle.$zeile.$spalte]."\", ".
                   "\"mode\" => \"ro\" ";

        if ($spalte != 4){ $matrix .= "),\r\n"; } else { $matrix .= ")\r\n"; }

        if ( ( $values[rolle.$zeile.$spalte] != "leer" ) and ( $values[pos_.$zeile.$spalte] != "alle") ){
          $anmelde_arr [$user_count] = array ("fkt" => $values[pos_.$zeile.$spalte], "rolle" => $values[rolle.$zeile.$spalte] );
          $user_count ++;
        }
        if (isset ($values[lagerot] )){ $roter_durchschlag = $values[pos_.$values[lagerot]]; }
      }
      if ($zeile != 5){ $matrix .= "),\r\n"; } else { $matrix .= ")\r\n"; }
    }
    $matrix .= ");\r\n \r\n";

    $counter = 1;
    foreach ($anmelde_arr as $anmelde){
       if ( $anmelde [rolle] == "Stab" ){ $anm_arr [$counter++] = $anmelde ; }
    }

    sort ($anm_arr);

        $anm_arr [$counter++]= array ("fkt" => "Si", "rolle" => "Stab")    ;
        $anm_arr [$counter++]= array ("fkt" => "A/W", "rolle" => "Fernmelder")    ;

    foreach ($anmelde_arr as $anmelde){
       if ($anmelde [rolle] == "FB"){ $anm_arr [$counter++] = $anmelde ; }
    }

    $anmelde_str = "";
    $user_count = 1 ;
    foreach ($anm_arr as $anm){
      $anmelde_str .= "\t$"."conf_empf [$user_count] = array (\"fkt\" => \"".$anm[fkt]."\", ".
                  "\"rolle\" => \"".$anm [rolle]."\" ); \r\n";
      $user_count++;
    }

    $zeile_durchschlag = "\r\n    $"."redcopy2 = \"".$roter_durchschlag."\" ;\r\n";

    $postfile = "\r\n\r\n\r\n?>";

    $filename =  $conf_web ["srvroot"].$conf_web ["pre_path"]."/fkt_rolle.inc.php";
    $fhndl = fopen ( $filename, "w+");

    fwrite ($fhndl, $prefile);
    fwrite ($fhndl, $fix);
    fwrite ($fhndl, $matrix);
    fwrite ($fhndl, $anmelde_str);
    fwrite ($fhndl, $zeile_durchschlag);
    fwrite ($fhndl, $postfile);
    fclose ($fhndl);
  }


/****************************************************************************\

\****************************************************************************/
  function pre_html ($titel){
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=utf-8\">";
    echo "<title>".$titel."</title>\n";
    echo "</head>\n";
    echo "<body>";
  }

/****************************************************************************\

\****************************************************************************/
  function post_html () {
    echo "</body>";
  }

/****************************************************************************\

   $entry : typ    : cb  - checkbox
                     t   - text
                     cbt - checkbox mit textfeld
            fkt    : LS, S1 .. S6 ...
            rolle  : Stab - Stabsmitglied
                     Fb   - Fachberater
\****************************************************************************/
  function cellentry ($zeile, $spalte, $entry, $isredcopy2) {
    if ($readonly) {
      $param = " readonly ";}
    else {
      $param = "";}

    if ($isredcopy2) { // Hintergrundfarbe des Feldes umstellen
      echo "<td style=\"font-size:18px; font-weight:900; text-align: center; width: 275px; background-color: rgb(255, 0, 0);\">\n";
    } else {
      echo "<td style=\"font-size:18px; font-weight:900; text-align: center; width: 275px; background-color: rgb(204, 204, 152);\">\n";
    }

    if ( $isredcopy2 ) {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"lagerot\" type=\"radio\"".$sel." value=\"".$zeile.$spalte."\">\n";

    echo "<input style=\"font-size:18px; font-weight:900;\" maxlength=\"6\" size=\"6\" name=pos_".$zeile.$spalte.
         " value=\"".$entry [fkt]."\"".$param.">\n";

    if ($entry["rolle"]=="Stab") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"rolle".$zeile.$spalte."\" value=\"Stab\" type=\"radio\" ".$param.$sel.">Stab";
    if ($entry["rolle"]=="FB") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"rolle".$zeile.$spalte."\" value=\"FB\" type=\"radio\" ".$param.$sel.">FB";

    if ($entry["rolle"]=="leer") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"rolle".$zeile.$spalte."\" value=\"leer\" type=\"radio\" ".$param.$sel.">leer";

    echo "</td>";
  }

/****************************************************************************\

\****************************************************************************/
  function fkt_matrix ($fkts, $redcopy2){

  include ("./config.inc.php");

// echo "<br><br>"; print_r ($fkts); echo"<br><br>";

    echo "<form style=\"\" method=\"get\" action=\"".$_SERVER ['PHP_SELF']."\" name=\"Funktionseditor\">";
    echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
    echo "</td>\n"; // schon gelesen ?
    for ($zeile=1; $zeile <= 5; $zeile ++){
      echo "<td align=\"center\">";
      for ($spalte=1; $spalte <= 4; $spalte ++) {
        echo "<td align=\"center\">";
        if ( ($fkts [$zeile][$spalte][fkt] == $redcopy2) and
             ($redcopy2 != "") ) {
          cellentry ( $zeile, $spalte, $fkts [$zeile][$spalte], true); }
        else {
          cellentry ( $zeile, $spalte, $fkts [$zeile][$spalte], false); }
        echo "</td>";
      }
      echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";

    echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
    echo "<tr><td>\n";
//    echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
//    echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
    echo "<input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/003.jpg\">\n";
    echo "</td><td>\n";
    echo "<input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/001.jpg\">\n";
    echo "</td></tr>\n";
    echo "</tbody>";
    echo "</table>";
    echo "</form>";
  }

/****************************************************************************\

\****************************************************************************/
    include ("./config.inc.php");
    include ("./fkt_rolle.inc.php");


/****************************************************************************\

\****************************************************************************/

  if (isset($_GET ["absenden_x"] ) ){
    check_fkt ($_GET);
    write_fkt_file ($_GET);

    header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/admin.php");
  }

  if (isset($_GET ["abbrechen_x"] ) ){
    header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/admin.php");
  }


    pre_html ("Funktionsbearbeitung");
    echo "<P><FONT FACE=\"Arial Black\"><FONT SIZE=4>Funktionseditor
          </FONT></FONT></P>";
    echo "<P><FONT FACE=\"Courier, monospace\"><FONT SIZE=4>Mit Hilfe diesem Men&uuml;s k&ouml;nnen die
          m&ouml;glichen Empf&auml;nger im Stab festgelegt werden, die durch die Sichtung ausgew&auml;hlt werden
          k&ouml;nnen.<br><br><big><b>WICHTIG !!!<br><br>Im laufenden Betrieb k&ouml;nnen
          Empf&auml;nger hinzugef&uuml;gt werden.<br>Im Betrieb sollten<i> keine Empf&auml;nger
          gel&ouml;scht</i> werden, da diese dann nicht mehr erreicht wird.</b></big><br><br>
          Die Funktionsbezeichnungen d&uuml;rfen 6 Stellen nicht &uuml;berschreiten. Sonderzeichen sind <i>nicht</i> erlaubt!<br>Die Stabsfunktionen
          LS, S1, S2, S3, S4, S5 und S6 m&uuml;ssen erhalten bleiben.<br> Das Feld \"<b>alle</b>\" unten rechts
          muss erhalten bleiben!</FONT></FONT></P>";
    fkt_matrix ( $empf_matrix , $redcopy2) ;
    post_html ();

/****************************************************************************\

\****************************************************************************/
/*
echo "<br><br>\n";
echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
//echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
*/

?>
