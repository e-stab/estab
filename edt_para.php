<?php

define ("debug",false);

class parametrierung {

  var $preconf ;

/****************************************************************************\

\****************************************************************************/
  function std_values (){

    $cfg ["vbg"] ["rt"]      =  "rgb( 255, 150, 150)" ;
    $cfg ["vbg"] ["gn"]      =  "rgb( 130, 230, 130)" ;
    $cfg ["vbg"] ["bl"]      =  "rgb( 150, 150, 255)" ;
    $cfg ["vbg"] ["ge"]      =  "rgb( 255, 255, 128)" ;
    $cfg ["vbg"] ["default"] =  "rgb( 255, 255, 255)" ;

    $cfg ["lbg"] ["rt"]      =  "rgb( 255, 150, 150)" ;
    $cfg ["lbg"] ["gn"]      =  "rgb( 130, 230, 130)" ;
    $cfg ["lbg"] ["bl"]      =  "rgb( 150, 150, 255)" ;
    $cfg ["lbg"] ["ge"]      =  "rgb( 255, 255, 128)" ;
    $cfg ["lbg"] ["default"] =  "rgb( 255, 255, 255)" ;

    $cfg ["itv"] ["stabliste"] = 120 ; // sekunden
    $cfg ["itv"] ["fmdliste"]  =  10 ; // sekunden
    $cfg ["itv"] ["siliste"]   =  10 ; // sekunden



  }

/****************************************************************************\

\****************************************************************************/
  function parametrierung (){
    include ("./para.inc.php");
    $preconf ["fbg"]["rt"] = $conf_formbg ["rt"];
    $preconf ["fbg"]["gn"] = $conf_formbg ["gn"];
    $preconf ["fbg"]["bl"] = $conf_formbg ["bl"];
    $preconf ["fbg"]["ge"] = $conf_formbg ["ge"];
    $preconf ["fbg"]["dflt"] = $conf_formbg ["default"];

    $preconf ["lbg"]["rt"] = $conf_lstbg ["rt"];
    $preconf ["lbg"]["gn"] = $conf_lstbg ["gn"];
    $preconf ["lbg"]["bl"] = $conf_lstbg ["bl"];
    $preconf ["lbg"]["ge"] = $conf_lstbg ["ge"];
    $preconf ["lbg"]["dflt"] = $conf_lstbg ["default"];

    $preconf ["itv"]["stbl"] = $conf_intervall ["stabliste"] = 120 ; // sekunden
    $preconf ["itv"]["fmdl"] = $conf_intervall ["fmdliste"]  =  10 ; // sekunden
    $preconf ["itv"]["sil"] = $conf_intervall ["siliste"]   =  10 ; // sekunden
  }


/****************************************************************************\

\****************************************************************************/
  function write_fkt_file ($values){

  include ("./config.inc.php");

    $prefile = "<"."?"."php \r\n".
    "/"."******************************************************************************\ \r\n".
    "              Definitionen fuer den Datenbankzugriff                              \r\n".
    "\******************************************************************************"."/ \r\n";

    $fileline [0]  = "$"."conf_4f_db [\"server\"]        = \"".$values ['serveradr']."\"; \n\r";
    $fileline [1]  = "$"."conf_4f_db [\"user\"]          = \"".$values ['db_user']."\"; \n\r";
    $fileline [2]  = "$"."conf_4f_db [\"password\"]      = \"".$values ['db_userpw']."\"; \n\r";
    $fileline [3]  = "$"."conf_4f_db [\"datenbank\"]     = \"".$values ['db_dbname']."\"; \n\r";

    $fileline [4]  = "$"."conf_4f_tbl [\"prefix\"]       = \"".$values ['tbl_pre']."\" ; \n\r";
    $fileline [5]  = "$"."conf_4f_tbl [\"benutzer\"]     = \"".$values ['tbl_pre']."benutzer\"; \n\r";
    $fileline [6]  = "$"."conf_4f_tbl [\"nachrichten\"]  = \"".$values ['tbl_pre']."nachrichten\"; \n\r";
    $fileline [7]  = "$"."conf_4f_tbl [\"protokoll\"]    = \"".$values ['tbl_pre']."protokoll\"; \n\r";
    $fileline [8]  = "$"."conf_4f_tbl [\"anhang\"]       = \"".$values ['tbl_pre']."anhang\"; \n\r";
    $fileline [9]  = "$"."conf_4f_tbl [\"usrtblprefix\"] = \"usr_\"; \n\r";
    $fileline [10] = "$"."conf_tbl    [\"bhp50\"]        = \"".$values ['tbl_pre']."bhp50\"; \n\r";
    $fileline [11] = "$"."conf_tbl    [\"komplan\"]      = \"".$values ['tbl_pre']."komplan\"; \n\r";
    $fileline [12] = "$"."conf_tbl    [\"etb\"]          = \"".$values ['tbl_pre']."etb\"; \n\r";

    $fileline [13] = "$"."conf_tbl    [\"tbb\"]          = \"".$values ['tbl_pre']."tbb\"; \n\r";

    $fileline [14] = "$"."conf_4f     [\"anschrift\"]    = \"".$values ['anschrift']."\"; \n\r";

    $fileline [15] = "$"."conf_4f     [\"hoheit\"]       = \"".$values ['hoheit']."\"; \n\r";

    $fileline [16] = "$"."usertablename11 = $"."conf_4f_tbl [\"usrtblprefix\"].$"."_GET [\"funktion\"].\"_\".strtoupper ( $"."_GET [\"kuerzel\"]);  ";
    $fileline [17] = "$"."tblusername11   = $"."conf_4f_tbl [\"usrtblprefix\"].$"."_SESSION[\"vStab_funktion\"].\"_\".$"."_SESSION[\"vStab_kuerzel\"];";


    $postfile = "\r\n\r\n\r\n?>";

    $filename =  $conf_web ["srvroot"].$conf_web ["pre_path"]."/dbcfg.inc.php";

    $fhndl = fopen ( $filename, "w+");

    fwrite ($fhndl, $prefile);

    for ($i=0; $i <= count ($fileline); $i++){
      fwrite ($fhndl, $fileline [$i]);
    }

    fwrite ($fhndl, $postfile);
    fclose ($fhndl);
  }


/******************************************************************************\

\******************************************************************************/
  function menue () {
    include ("./config.inc.php");
    $dbmenue = array (
        0 => array ('text' => "<b>Hostname oder IP-Adresse</b><br>des Datenbankservers<br><i>localhost</i> :",
                    'feld' => "name=\"serveradr\" type=\"text\" size=\"30\" maxlength=\"30\" value=\"".$this->preconf ['serveradr']."\""
                    ),
        1 => array ('text' => "<b>Datenbankbenutzer</b><br> :",
                    'feld' => "name=\"db_user\" type=\"text\" size=\"30\" maxlength=\"30\" value=\"".$this->preconf ['db_user']."\""
                    ),
        2 => array ('text' => "<b>Passwort</b> :",
                    'feld' => "name=\"db_userpw\" type=\"password\" size=\"30\" maxlength=\"30\""
                    ),
        3 => array ('text' => "<b>Passwortbestätigung</b> :",
                    'feld' => "name=\"db_userpwrep\" type=\"password\" size=\"30\" maxlength=\"30\""
                    ),
        4 => array ('text' => "<b>Datenbankname</b> :",
                    'feld' => "name=\"db_dbname\" type=\"text\" size=\"30\" maxlength=\"30\" value=\"".$this->preconf ['db_dbname']."\""
                    ),
        5 => array ('text' => "<b>Tabellenprefix</b><br>Zeichenfolge die den<br>Tabellennamen vorangestellt wird :",
                    'feld' => "name=\"tbl_pre\" type=\"text\" size=\"30\" maxlength=\"30\" value=\"".$this->preconf ['tbl_pre']."\""
                    )
      );

    $vordruckmenue = array (
        0 => array ('text' => "<b>Anschrift</b><br>Text der bei Eing&auml;ngen<br>im Anschriftfeld eingetragen <br>werden soll<br><i>EL KR HS</i> :",
                    'feld' => "name=\"anschrift\" type=\"text\" size=\"10\" maxlength=\"30\" value=\"".$this->preconf ['anschrift']."\""
                    ),
        1 => array ('text' => "<b>Hoheitskennzeichen</b><br>:",
                    'feld' => "name=\"hoheit\" type=\"text\" size=\"6\" maxlength=\"6\" value=\"".$this->preconf ['hoheit']."\""
                    )
      );
    echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\" \"http://www.w3.org/TR/html4/loose.dtd\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<title>Konfiguration erzeugen</title>\n";
    echo "</head>\n";
    echo "<body>\n";
    echo "<h1>Datenbankeinstellungen!</h1>\n";
    echo "<form action=\"".$_SERVER['PHP_SELF']."\" method=\"get\">\n";
    echo "<fieldset>\n";
    echo "<legend>Datenbankparameter</legend>\n";
    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    $i = 0;
    foreach ($dbmenue as $menueitem){
      echo "<tr>\n";
      echo "<td align=\"right\">".$menueitem ['text']."</td>\n";
      echo "<td><input ".$menueitem ['feld']."></td>\n";
      echo "</tr>\n";
      $i++;
    }
    echo "</table>\n";
    echo "</fieldset>\n";

    echo "<fieldset>\n";
    echo "<legend>Vordruckparameter</legend>\n";
    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";

    $i = 0;
    foreach ($vordruckmenue as $menueitem){
      echo "<tr>\n";
      echo "<td align=\"right\">".$menueitem ['text']."</td>\n";
      echo "<td><input ".$menueitem ['feld']."></td>\n";
      echo "</tr>\n";
      $i++;
    }
    echo "</table>\n";
    echo "</fieldset>\n";

    echo "<fieldset>\n";
    echo "<table border=\"2\" cellpadding=\"5\" cellspacing=\"0\" bgcolor=\"#E0E0E0\">\n";
    echo "<tr>\n";
    echo "<td align=\"right\">Formular:</td>\n";
    echo "<td>\n";

    echo "<input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/003.jpg\">\n";
    echo "<input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/001.jpg\">\n";

    echo "</td>\n";
    echo "</tr>\n";
    echo "</fieldset>\n";
    echo "</table>\n";
    echo "</form>\n";
    echo "</body>\n";
    echo "</html>\n";
  }


} //class  make_dbconf

  include ("./dbcfg.inc.php");
  include ("./config.inc.php");

  $a = new make_dbconf ($conf_4f_db, $conf_4f_tbl, $conf_tbl, $conf_4f);

  if (isset($_GET["absenden_x"])) {
    $a->write_fkt_file ($_GET);
    header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/admin.php");
  } elseif ( isset($_GET["abbrechen_x"]) ){
    header("Location: ".$conf_urlroot.$conf_web ["pre_path"]."/admin.php");
  }

    $a->menue ();


  if ( debug == true ){
    echo "<br><br>\n";
    echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
    echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
    echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
    //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
    echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
  }




}


echo "<b><big><big>Klasse noch nicht implementiert.</big></big></b>";

?>
