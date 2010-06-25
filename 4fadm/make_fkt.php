<?php
define ("debug", false);
/****************************************************************************\

\****************************************************************************/
  function check_fkt ($values){

//    echo "VALUES="; var_dump ($values); echo "<br><br><br>";

    foreach ($values as $key => $val){
      if (debug) echo "KEY = ".$key." - VAL =".$val."<br>";

      list ($pos,$xy) = explode ('_',$key);
      if ( ($pos == "pos") and ($val != "") )
        if (strlen ($xy) == 2){
          $x = substr ($xy, 0, 1);
          $y = substr ($xy, 1, 1);
//          echo "<b>##POS=".$pos." x=".$x." y=".$y." val=".$val."</b><br>";
        }
//      $suchmuster = "\!\"§\$\%\&/()=?";
//      preg_match($suchmuster, $val, $treffer, PREG_OFFSET_CAPTURE, 3);
    }

    return (true);
  }


/****************************************************************************\

\****************************************************************************/
  function write_fkt_file ($values, $filename){

    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");

// echo "VALUE=="; print_r ($values); echo "<br><br><br>";


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

        if ($values["pos_".$zeile.$spalte] == ""){ $values["rolle_".$zeile.$spalte] = "leer"; }

        $matrix .= "\t $spalte => array (";

        if ($values["pos_".$zeile.$spalte] == ""){ $matrix .= "\"typ\" => \"t\", "; }
        else { $matrix .=  "\"typ\" => \"cb\", "; }

        if ($values["rolle_".$zeile.$spalte] == "leer"){ $matrix .= "\"typ\" => \"t\", "; }
        else { $matrix .=  "\"typ\" => \"cb\", "; }

        $matrix .= "\"fkt\" => \"".$values["pos_".$zeile.$spalte]."\", ".
                   "\"rolle\" => \"".$values["rolle_".$zeile.$spalte]."\", ".
                   "\"mode\" => \"ro\" ";

        if ($spalte != 4){ $matrix .= "),\r\n"; } else { $matrix .= ")\r\n"; }

        if ( ( $values["rolle_".$zeile.$spalte] != "leer" ) and ( $values["pos_".$zeile.$spalte] != "alle") ){

          $anmelde_arr [$user_count] = array ("fkt" => $values["pos_".$zeile.$spalte], "rolle" => $values["rolle_".$zeile.$spalte] );

          $user_count ++;
        }

        if (isset ($values["lagerot"] )){ $roter_durchschlag = $values["pos_".$values["lagerot"]]; }
      }
      if ($zeile != 5){ $matrix .= "),\r\n"; } else { $matrix .= ")\r\n"; }
    }
    $matrix .= ");\r\n \r\n";

// echo "NICHT leer UND NICHT alle" ;print_r ($anmelde_arr); echo "<br>";

    $counter = 1;
    foreach ($anmelde_arr as $anmelde){
       if ( $anmelde ["rolle"] == "Stab" ){ $anm_arr [$counter++] = $anmelde ; }
    }

    sort ($anm_arr);

        $anm_arr [$counter++]= array ("fkt" => "Si", "rolle" => "Stab")    ;
        $anm_arr [$counter++]= array ("fkt" => "A/W", "rolle" => "Fernmelder")    ;

    foreach ($anmelde_arr as $anmelde){
       if ($anmelde ["rolle"] == "FB"){ $anm_arr [$counter++] = $anmelde ; }
    }

    $anmelde_str = "";
    $user_count = 1 ;
    foreach ($anm_arr as $anm){
      $anmelde_str .= "\t$"."conf_empf [$user_count] = array (\"fkt\" => \"".$anm["fkt"]."\", ".
                  "\"rolle\" => \"".$anm ["rolle"]."\" ); \r\n";
      $user_count++;
    }

    $zeile_durchschlag = "\r\n    $"."redcopy2 = \"".$roter_durchschlag."\" ;\r\n";

    $postfile = "\r\n\r\n\r\n?>";

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
  function write_fkt_db ($values){

    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include_once ("../4fach/db_operation.php");

//    include ("../4fcfg/fkt_rolle.inc.php");
//    echo "<br><br><br>"; echo "VALUES dbsave=";print_r ($values); echo "<br><br><br>";

    $lagekopie = "";
    foreach ($values as $key => $val){
//      echo "<br>KEY===".$key." - ".$val." +++ ";
        // lagerot
      if ($key == "lagerot"){
        $rotkopiex = substr ($val, 0, 1);
        $rotkopiey = substr ($val, 1, 1);
      } else {
          // key zerlegen in links der Schlüssel - rechts die Position
        list ($left,$right) = explode ('_',$key);
          /* left kann sein
              1. pos
              2. rolle
              3. stasi
          */
        $x = substr ($right, 0, 1);
        $y = substr ($right, 1, 1);
        switch ($left){
          case "pos": $pos [$x][$y] = $val ; break;
          case "rolle": $rolle [$x][$y] = $val;  break;
          case "stasi": $stasi [$x][$y] = 1;  break;
        }
      } // else key == lagerot
//      echo "+++<br>";

    } // foreach

//     echo "<br>"; echo "pos   dbsave=";print_r ($pos); echo "<br>";
//     echo "<br>"; echo "rolle dbsave=";print_r ($rolle); echo "<br>";
//     echo "<br>"; echo "stasi dbsave=";print_r ($stasi); echo "<br>";

    $fktquery = "INSERT INTO `".$conf_4f_tbl   ["empfmtx"]."` (`mtx_x`, `mtx_y`, `mtx_typ`, `mtx_fkt`, `mtx_rolle`, `mtx_mode`, `mtx_rc2`, `mtx_auto`) VALUES ";
    for ($zeile = 1; $zeile <= 5; $zeile ++) {
      for ($spalte = 1; $spalte <= 4; $spalte ++){
        if (($rolle[$zeile][$spalte] == "Stab") OR
            ($rolle[$zeile][$spalte] == "FB")){ $typ="\"cb\""; } else { $typ="\"t\""; }
        if (($rotkopiex == $zeile) and ($rotkopiey == $spalte)){ $redcpy = "1"; } else { $redcpy = "0"; }
        if ( $stasi [$zeile][$spalte] == 1 ) { $autosichter = "1" ; } else { $autosichter = "0"; }

        if ($pos[$zeile][$spalte] != ""){
          $fktquery .= "(".$zeile.",
                       ".$spalte.",
                       ".$typ.",
                       \"".$pos[$zeile][$spalte]."\",
                       \"".$rolle[$zeile][$spalte]."\",
                       \"ro\",
                       \"".$redcpy."\",
                       \"".$autosichter."\")" ;
        } else {
          $fktquery .= "(".$zeile.",".$spalte.", \"t\",\"\" ,\"\" , \"ro\", \"0\", \"0\")" ;
        }
        if (( $zeile == 5) and ($spalte == 4)) { $fktquery .= ""; } else { $fktquery .= ","; }
/*
        echo "Write in DB = ".
             $zeile.",
           ".$spalte.",
           ".$typ.",
           ".$pos[$zeile][$spalte].",
           ".$rolle[$zeile][$spalte].",
           \"ro\",
           ".$redcpy.",
           ".$autosichter."<br>" ;
*/

      }
    }


    $dbaccess = new db_access ($conf_4f_db  ["server"],
                               $conf_4f_db  ["datenbank"],
                               $conf_4f_tbl ["benutzer"],
                               $conf_4f_db  ["user"],
                               $conf_4f_db  ["password"] );

    $query = "TRUNCATE TABLE ".$conf_4f_tbl   ["empfmtx"].";" ;

//    echo "query=".$query."<br><br>";

    $result = $dbaccess->query_table_iu ($query);

//    echo "<b> FKTQUERY===".$fktquery."</b><br>";

    $result = $dbaccess->query_table_iu ($fktquery);

//    echo "<b>Nach DBaccess-query</b><br>";
//    exit;
  }



/****************************************************************************\

\****************************************************************************/
  function pre_html ($titel){
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
      // Hier kann noch Java rein    
    echo "<script type = \"text/javascript\" src = \"../js/prototype.js\"></script>";
    echo "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=utf-8\">";
    echo "<title>".$titel."</title>\n";

    echo "<script type='text/javascript'>\n";
    echo "<!--\n";
        echo " var color_err =     '#FF4500'\n";
    echo " var color_warning = '#FFD700'\n";
    echo " var color_ok =      '#32CD32'\n";
    echo " var color_empty =   '#ffffff'\n";
	 echo " var Felder = new Array(";
	 for ($zeile=1; $zeile <= 5; $zeile ++)
	 {
	 	for ($spalte=1; $spalte <= 4; $spalte ++)
	 	{
	 		echo ("'fktmtx_".$zeile.$spalte."_fkt'");
	 		if( ($zeile < 5) || ($spalte < 4) )
	 			echo(",");
	 	}
	 }
	 echo ")\n";
	 echo " \n";
    echo " function isValid(x) {\n";
    echo "   var RegExp = /^(\\w*)$/\n"; 
    echo "   var result = x.match(RegExp)\n";
    echo "   return !(result);\n";
    echo " }\n\n";
    
    echo "function checkAll() {\n";
    echo "   for (var i = 0; i < Felder.length; i++) {\n";
    echo "       checkInput(Felder[i]);\n";
    echo "   }\n";
    echo "}\n";
   
    echo "function checkForm() {\n";
    echo "   return false;\n";
    echo "   for (var i = 0; i < Felder.length; i++) {\n";
    echo "       if(checkInput(Felder[i])) return false;\n";
    echo "   }\n";
    echo "   return true;\n";
    echo "}\n";
   
    echo "function checkInput(ident) {\n";
    echo "   var check = 0\n";
    echo "   var ret = true\n";
    echo "   if (document.getElementById){\n";
    echo "      document.getElementById(ident).value = document.getElementById(ident).value.toUpperCase();\n";
    echo "\n";
    echo "   for (var i = 0; i < Felder.length; i++) {\n";
    echo "      if (Felder[i] != ident) {\n";
    echo "        if( document.getElementById(ident).value == document.getElementById(Felder[i]).value) {\n";
    echo "          check = 1;\n";
    echo "        }\n";
    echo "      }\n";
    echo "   }\n\n";
    	
    echo "   if (( check == 1 ) || (isValid(document.getElementById(ident).value))) {\n";

	 echo "      if ( document.getElementById(ident).value == '') {\n";    
    echo "          document.getElementById(ident).style.backgroundColor=color_empty;\n";
    echo "      } else {\n";    
    echo "		     document.getElementById(ident).style.backgroundColor=color_err;\n";
    echo "          ret = false;\n";
    echo "      }\n";

    echo "   } else {\n";
    echo "      document.getElementById(ident).style.backgroundColor=color_ok;\n";
    echo "   }\n";
    echo "  }\n";
    echo "  return ret;\n";
    echo " }\n";

    echo " -->\n";
    echo " </script>\n";
    echo " </head>\n";
    echo " <body onload='checkAll()'>";
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

                    ( $zeile, $spalte, $fkts [$zeile][$spalte], true, $stasi[$zeile][$spalte])
\****************************************************************************/
  function cellentry ($zeile, $spalte, $entry, $isredcopy2) {

    include ("../4fcfg/config.inc.php");

//    if ($entry[auto] == "1") {$isstasi = "t";} else {$isstasi = "f";};
/*
    echo "zeile  ===".$zeile."<br>";
    echo "spalte ===".$spalte."<br>";
    echo "entry  ===".$entry."<br>";
    echo "isred  ===".$isredcopy2."<br>";
    echo "isstasi===".$isstasi."<br>";
*/

    if ($isredcopy2) { // Hintergrundfarbe des Feldes umstellen
       // rot
       $bgcolor = "rgb(255, 0, 0)";
    } else {
      if ($entry[auto] == 1) { // Hintergrundfarbe des Feldes umstellen bei der standard Sichtung
         // blau
        $bgcolor = "rgb(  100,   100, 255)";
      } elseif ($entry[auto] == 0) {
         //
        $bgcolor = "rgb(204, 204, 152)";
      }
    }

    if ($isredcopy2) { // Hintergrundfarbe des Feldes umstellen
       // rot
      echo "<td style=\"font-size:18px; font-weight:900; text-align: center; width: 10px; background-color: ".$bgcolor.";\">\n";
      echo "<a><img src=\"".$conf_design_path."/null.gif\" alt=\"leer\"></a>\n";
    } else {
      if ($entry[auto] == 1) { // Hintergrundfarbe des Feldes umstellen bei der standard Sichtung
         // blau
        echo "<td style=\"font-size:18px; font-weight:800; text-align: center; width: 10px; background-color: ".$bgcolor.";\">\n";
      } else {
         //
        echo "<td style=\"font-size:18px; font-weight:800; text-align: center; width: 10px; background-color: ".$bgcolor.";\">\n";
      }
       // Autosichtung
      if ($entry[auto] == 1) {
        echo "<!-- ".$isstasi." -->";
        echo "<input type=\"checkbox\" name=\"stasi_".$zeile.$spalte."\" value=\"salami_".$zeile.$spalte."\" id=\"fktmtx_".$zeile.$spalte."_as\" checked=\"checked\" >";
      } elseif ($entry[auto] == 0) {
        echo "<input type=\"checkbox\" name=\"stasi_".$zeile.$spalte."\" value=\"salami_".$zeile.$spalte."\" id=\"fktmtx_".$zeile.$spalte."_as\" >";
      }
    }
    echo "</td>\n";

    echo "<td style=\"font-size:18px; font-weight:800; text-align: center; width: 10px; background-color: ".$bgcolor.";\">\n";
      // Radiobutton fÃ¼r die Rotkopie
    if ( $isredcopy2 ) {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"lagerot\" type=\"radio\"".$sel." value=\"".$zeile.$spalte."\" id=\"fktmtx_".$zeile.$spalte."_rk\" >\n";
    echo "</td>";
      // Funktionsbezeichnung (LS, S1...S6, OrglRD, LNA)
    echo "<td style=\"font-size:18px; font-weight:800; text-align: center; width: 10px; background-color: ".$bgcolor.";\">\n";
    echo "<input style=\"font-size:18px; font-weight:900;\" maxlength=\"6\" size=\"6\" name=pos_".$zeile.$spalte.
         " value=\"".$entry ["fkt"]."\" id=\"fktmtx_".$zeile.$spalte."_fkt\" onkeyup=\"checkInput('fktmtx_".$zeile.$spalte."_fkt')\">\n";
    echo "</td>";
      //  Stab oder
    echo "<td style=\"font-size:18px; font-weight:800; text-align: center; width: 100px; background-color: ".$bgcolor.";\">\n";
    if ($entry["rolle"]=="Stab") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"rolle_".$zeile.$spalte."\" value=\"Stab\" type=\"radio\" ".$sel."  id=\"fktmtx_".$zeile.$spalte."_stab\" >Stab";
    echo "</td>";
      // Fachberater
    echo "<td style=\"font-size:18px; font-weight:800; text-align: center; width: 100px; background-color: ".$bgcolor.";\">\n";
    if ($entry["rolle"]=="FB") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input name=\"rolle_".$zeile.$spalte."\" value=\"FB\" type=\"radio\" ".$sel." id=\"fktmtx_".$zeile.$spalte."_fb\">FB";

    echo "</td>";
  }

/****************************************************************************\

\****************************************************************************/
  function fkt_matrix ($fkts, $redcopy2){

    include ("../4fcfg/config.inc.php");

// echo "<br><br>FKTS==="; var_dump ($fkts); echo"<br><br>";

    echo "<form style=\"\" method=\"get\" action=\"".$_SERVER ['PHP_SELF']."\" name=\"Funktionseditor\" onsubmit='return checkForm()'>\n";
    echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
//    echo "</td>\n";
    for ($zeile=1; $zeile <= 5; $zeile ++){

      echo "<tr>\n";// align=\"center\">";

      for ($spalte=1; $spalte <= 4; $spalte ++) {

        echo "<td align=\"center\">\n";

        if ( ($fkts [$zeile][$spalte]["fkt"] == $redcopy2) and
             ($redcopy2 != "") ) {
          cellentry ( $zeile, $spalte, $fkts [$zeile][$spalte], true); } //, $stasi[$zeile][$spalte]); }
        else {
          cellentry ( $zeile, $spalte, $fkts [$zeile][$spalte], false); } //, $stasi[$zeile][$spalte]); }
        echo "</td>\n";

      }
      echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";

    echo "<table style=\"text-align: center; background-color: rgb(255,255,255); \" border=\"2\" cellpadding=\"2\" cellspacing=\"2\">\n<tbody>\n";
    echo "<tr><td>\n";
//    echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
//    echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
    echo "<input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/send.gif\">\n";
    echo "</td><td>\n";
    echo "<input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/cancel.gif\">\n";
    echo "</td><td>\n";
    echo "<input type=\"image\" name=\"laden\" src=\"".$conf_design_path."/load.gif\">\n";
    echo "</td><td>\n";
    echo "<input type=\"image\" name=\"speichern\" src=\"".$conf_design_path."/save.gif\">\n";

    echo "</td></tr>\n";
    echo "</tbody>";
    echo "</table>";
    echo "</form>";
  }

/****************************************************************************\

\****************************************************************************/
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");


/****************************************************************************\

\****************************************************************************/

if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}

    // Gibt es eine default Datei?
  define ("defaultfile","default.fkt");
  if (file_exists (filename) ){

  }


  if (isset($_GET ["absenden_x"] ) ){
    $check = check_fkt ($_GET);
    if ($check) {
//      write_fkt_file ($_GET);
      write_fkt_db   ($_GET);
    }
    header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/4fadm/admin.php");
  }

  if (isset($_GET ["abbrechen_x"] ) ){
    header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/4fadm/admin.php");
  }

  if (isset($_GET ["speichern_x"] ) ){
    $check = check_fkt ($_GET);
    if ($check) {
      $filename =  $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fcfg/deault.fkt.php";
      write_fkt_file ($_GET, $filename);
      write_fkt_db   ($_GET);
    }
    header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/4fadm/admin.php");
  }

  if (isset($_GET ["laden_x"] ) ){
       //     echo "<b>datei laden !!!<br></b>";
     $filename =  $conf_web ["srvroot"].$conf_web ["pre_path"]."/4fcfg/deault.fkt.php";
     include ($filename);
  }

    pre_html ("Funktionsbearbeitung");
    echo "<P><FONT FACE=\"Arial Black\"><FONT SIZE=4>Funktionseditor
          </FONT></FONT></P>";
    echo "<P><FONT FACE=\"Courier, monospace\"><FONT SIZE=4>Mit Hilfe diesem Men&uuml;s k&ouml;nnen die
          m&ouml;glichen Empf&auml;nger im Stab festgelegt werden, die durch die Sichtung ausgew&auml;hlt werden
          k&ouml;nnen.<br><br><big><b>WICHTIG !!!<br><br>Im laufenden Betrieb k&ouml;nnen
          Empf&auml;nger hinzugef&uuml;gt werden.<br>Im Betrieb sollten<i> keine Empf&auml;nger
          gel&ouml;scht oder umbenannt</i> werden, da diese dann nicht mehr erreicht wird.<br>
          !!! Funktionsk&uuml;rzel \"Si\" und \"A/W\" d&uuml;rfen nicht verwendet werden !!!</b></big><br><br>
          Die Funktionsbezeichnungen d&uuml;rfen 6 Stellen nicht &uuml;berschreiten.
          <b>Sonderzeichen</b> sind <i>nicht</i> erlaubt!</FONT></FONT></P>";
    fkt_matrix ( $empf_matrix , $redcopy2) ;
    post_html ();




?>
