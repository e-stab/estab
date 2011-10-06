<?php
define ("debug",false);

include ("upload_class.php");

class fileupload extends file_upload {

  // fs - fileselectform Dateiauswahl
  var $fs_savename;  // Einlagerungsdateiname  HSxxxxx
  var $fs_uplname;   // Uploaddateiname
  var $fs_comment;   // Beschreibung
  var $fs_shortname; // Kuerzel
  var $fs_timestamp; // Zeitstempel
  var $fs_nextfilename; // Nächster Dateiname

  var $ff_savename ; // Name der gespeicherten Datei g.g. Darstellung im Menue
  var $ff_filename ; // Ursprünglicher Dateiname
  var $ff_comment  ; // Beschreibung Faxkopf
  var $ff_timestamp; // Zeitstempel
  var $ff_kuerzel  ; // Kuerzel des Fm

  var $filenamezero = 4; // Anzahl der Zahlen

  function get_next_filename_from_db () {
    include ("../../dbcfg.inc.php");
    include ("../../e_cfg.inc.php");
    include ("../../db_operation.php");
    $db = new db_access ($conf_4f_db  ["server"],
                         $conf_4f_db  ["datenbank"],
                         $conf_4f_tbl ["anhang"],
                         $conf_4f_db  ["user"],
                         $conf_4f_db  ["password"]);
    $query = "SELECT * FROM ".$conf_4f_tbl ["anhang"]." WHERE 1 ";
    $result = $db->query_table ($query);
//    var_dump ($result);
  }
/*36*/
  function save_in_db ($data) {
    include ("../../dbcfg.inc.php");
    include ("../../e_cfg.inc.php");
    include ("../../db_operation.php");
    include ("../protokoll.php");
    $db = new db_access ($conf_4f_db  ["server"],
                         $conf_4f_db  ["datenbank"],
                         $conf_4f_tbl ["anhang"],
                         $conf_4f_db  ["user"],
                         $conf_4f_db  ["password"]);

      $query = "INSERT into ".$conf_4f_tbl ["anhang"]." SET
                      `filename`      = \"".$data["filename"]."\",
                      `org_filename`  = \"".$data["org_filename"]."\",
                      `comment`       = \"".$data["comment"]."\",
                      `kuerzel`       = \"".$data["kuerzel"]."\",
                      `date`          = \"".$data["time"]."\"";

      $result = $db->query_table_iu ($query);

/*
      protokolleintrag ("Anhangdaten speichern",
                            $_SESSION[vStab_benutzer].";".
                            $_SESSION[vStab_kuerzel].";".
                            $_SESSION[vStab_funktion].";".
                            $_SESSION[vStab_rolle].";".
                            session_id().";".
                            $_SERVER[REMOTE_ADDR].";".
                            $data["filename"].";".
                            $data["org_filename"].";".
                            $data["time"]
                            );
*/
  }
/*70*/
  /**********************************************************************\
    Funktion: readDirectory ()

    benoetigte Datei:
  \**********************************************************************/
  function readDirectory($directory){
//    include ("../config.inc.php");
      $filesArr = array();
      if($ordner = dir($directory))
      {
          while($datei = $ordner->read())
          {
          if($datei != "." && $datei != "..") array_push($filesArr,$datei);
          }
      }
      rsort ($filesArr);
      return $filesArr;
  }


  function scan4nextfilename (){
    include ("../../config.inc.php");
    include ("../../e_cfg.inc.php");
    include ("../../dbcfg.inc.php");
    $filenames = $this->readDirectory ( $conf_4f ["ablage_dir"] );

    $hoheit = $conf_4f[hoheit];
    $hoheitlen = strlen ( $hoheit );
    $highest = 0;
    foreach ($filenames as $file){
      list($filename, $extention) = explode (".",$file);
      $filelen = strlen ($filename);
      $filehoheit = substr ( $filename, 0, $hoheitlen );
      if (strtoupper ( $hoheit ) == strtoupper ( $filehoheit ) ) {
        $nummer = substr ( $filename, $hoheitlen, ($filelen - $hoheitlen) );
        if ($nummer > $highest){ $highest = $nummer; }
      }
    }
    $nextnum = $highest + 1 ;
    $expo = intval (log10 ($nextnum) )+1;
    $fillzero = "";
    for ( $i=1; $i<= ($this->filenamezero-$expo); $i++ ){
      $fillzero .= "0";
    }
    $this->fs_nextfilename = $hoheit.$fillzero.$nextnum ;
  }

/*****************************************************************************\

\*****************************************************************************/
  function konv_datetime_taktime ($datetime){
    include ("../config.inc.php");
    // Datenbankzeit konvertiert in taktische Zeit
    // yyyy-MM-tt hh:mm:ss ==> tthhmmMMMyyyy
    list ($datum, $zeit) = explode (" ",$datetime);
    list ($yyyy, $MM, $tt) = explode ("-", $datum);
    list ($hh, $mm, $ss) = explode (":", $zeit);
    return ($tt.$hh.$mm.$tak_monate[$MM].$yyyy);
  }

/*91*/
/*****************************************************************************\

\*****************************************************************************/
  function convtodatetime ($datum, $zeit){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $tag    = substr ($datum, 0, 2);
    $monat  = substr ($datum, 2, 2);
    $stunde = substr ($zeit, 0, 2);
    $minute = substr ($zeit, 2, 2);
    $jahr   = date ("Y");
    $datetime = $jahr."-".$monat."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }



  function convtaktodatetime ($taktime){
    /* Datum ~= TTMM, Zeit == ~= HHMM */
  //  echo "Datum=".$datum."  Zeit=".$zeit."<br>";
    $tag    = substr ($taktime, 0, 2);
    $stunde = substr ($taktime, 2, 2);
    $minute = substr ($taktime, 4, 2);
    $monat  = substr ($taktime, 6, 3);
    $jahr   = substr ($taktime, 9, 4);
    $datetime = $jahr."-".$rew_tak_monate[strtolower($monat)]."-".$tag." ".$stunde.":".$minute.":00";
    return $datetime;
  }

  function pre_html($titel){
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\">\n";
    echo "<html>\n";
    echo "<head>\n";
    echo "<meta content=\"text/html; charset=ISO-8859-1\" http-equiv=\"content-type\">\n";
    echo "<title>$titel</title>";
    echo "</head>";
    echo "<body>";
  }


  function fileselectform ($predata) {
    include ("../../config.inc.php");
    echo "<form name=\"uploadform\" enctype=\"multipart/form-data\" method=\"post\" action=\"".$_SERVER['PHP_SELF']."\">";
    echo "<table style=\"text-align: left; width: 745px; height: 170px;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td><big><big style=\"font-weight: bold;\">Anhang hochladen</big></big></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "<td>\n";
    echo "<table style=\"text-align: left; width: 740px; height: 143px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"1\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Dateiname:</td>\n";
    echo "  <td style=\"width: 769px;\"><big><big style=\"font-weight: bold;\">".$predata["newfilename"]."</big></big></td>\n";
    echo "  <input type=\"hidden\" name=\"fs_nextfilename\" value=\"".$predata["newfilename"]."\">\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Dateiname:</td>\n";
    echo "  <td style=\"width: 769px;\">";
    echo "  <input style=\"font-size:18px; font-weight:900; font-weight: bold;\" name=\"upload\" type=\"file\" size=\"60\">";
    echo "  </td>\n";
    echo "</tr>\n";
    echo "<tr>\n" ;
    echo "  <td style=\"width: 167px;\">Beschreibung</td>\n";
    echo "  <td style=\"width: 769px;\">";
    echo "   <input style=\"font-size:18px; font-weight:900;\" maxlength=\"255\" size=\"80\" name=\"fs_comment\" value=\"".$predata["comment"]."\"></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td>K&uuml;rzel</td>\n";
    echo "  <td style=\"width: 769px;\"><big><input maxlength=\"3\" size=\"3\" name=\"fs_shortname\" value=\"".$predata["kuerzel"]."\"></big></td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "  <td style=\"width: 167px;\">Zeitstempel</td>\n";
    echo "  <td style=\"width: 769px;\"><input maxlength=\"13\" size=\"13\" name=\"fs_timestamp\" value=\"".$predata["time"]."\"></td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "<td></td>\n";
    echo "</tr>\n";
    echo "<tr><td>\n";
    echo "<input type=\"image\" name=\"absenden\" src=\"".$conf_design_path."/003.jpg\">\n";
    echo "<input type=\"image\" name=\"abbrechen\" src=\"".$conf_design_path."/001.jpg\">\n";
    echo "</td></tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</form>";
  }


  function post_html () {
    echo "</body>";
    echo "</html>";
  }

} // class
/*************************************************************************************************************
                                            C L A S S
**************************************************************************************************************/

session_start();

    include ("../../config.inc.php");

/************************************************************************

   Steuerung über ein Sessioncookie

     $_SESSION ["UPLOAD"] ==
        "fileselect" :


*************************************************************************/

$status = $_SESSION ["UPLOAD"];


  switch ($status) {
      /*---------------------------------------------------------------*/
    case "fileselect":
      $instanz = new fileupload ();
      $instanz->pre_html("Upload");
      $instanz->scan4nextfilename();

      $data["newfilename"]  =  $instanz->fs_nextfilename;
      $data["kuerzel"]      =  $_SESSION["vStab_kuerzel"];
      $data["time"]         =  date("dHiMY");

      $instanz->fileselectform ($data);

      $instanz->get_next_filename_from_db ();

      $instanz->post_html ();
      $_SESSION ["UPLOAD"] = "fileselectwindow" ;

    break;
      /*---------------------------------------------------------------*/
    case "fileselectwindow":
          // zwei möglichkeiten 1. absenden oder 2. abbrechen
        $max_size = 1024*1024*5; // the max. size for uploading
/****300****/
        $my_upload = new fileupload;

        $my_upload->upload_dir = $conf_4f ["ablage_dir"]."/" ; // "files" is the folder for the uploaded files (you have to create this folder)
          if ( debug == true ){ echo "Upload-Dir:".$my_upload->upload_dir."<br>";}

        $my_upload->extensions = array(".jpg",".tif",".gif",".avi",".png", ".zip", ".pdf", ".xia"); // specify the allowed extensions here
          // $my_upload->extensions = "de"; // use this to switch the messages into an other language (translate first!!!)

        $my_upload->max_length_filename = 100; // change this value to fit your field length in your database (standard 100)

        $my_upload->rename_file = true;

        if (isset($_POST["absenden_x"])) {
            if ( debug == true ){ echo "001 is set POST absender_x<br>";}
          $my_upload->the_temp_file = $_FILES['upload']['tmp_name'];

            if ( debug == true ){ echo "002 tmpname =".$my_upload->the_temp_file."<br>";}
          $my_upload->the_file = $_FILES['upload']['name'];

            if ( debug == true ){ echo "003 name    =".$my_upload->the_file."<br>";}

          $my_upload->http_error = $_FILES['upload']['error'];

            if ( debug == true ){ echo "004 error   =".$my_upload->http_error."<br>";}
            if ( debug == true ){ echo "004a _FILES ="; var_dump ($_FILES); echo"<br><br>";}

          if ($my_upload->http_error != 0){
            $errortxt = $my_upload->error_text($my_upload->http_error);
            echo "<big><big><b>".$errortxt."</b></big></big>";
          }

          $my_upload->replace = true ; //(isset($_POST['replace'])) ? $_POST['replace'] : "n"; // because only a checked checkboxes is true

          $my_upload->do_filename_check = false; // (isset($_POST['check'])) ? $_POST['check'] : "n"; // use this boolean to check for a valid filename

          $new_name = (isset($_POST['fs_nextfilename'])) ? $_POST['fs_nextfilename'] : "";
            if ( debug == true ){ echo "005 newname   =".$new_name."<br>";}

          if ($my_upload->upload($new_name)) { // new name is an additional filename information, use this to rename the uploaded file
            $full_path = $my_upload->upload_dir.$my_upload->file_copy;
                if ( debug == true ){ echo "006 full_path   =".$full_path."<br>";}

              $info = $my_upload->get_uploaded_file_info($full_path);
                if ( debug == true ){ echo "007 info        =".$info."<br>";}


              $data["filename"]     = $_POST ["fs_nextfilename"] ;
              $data["org_filename"] = $_FILES["upload"]["name"];
              $data["comment"]      = $_POST ["fs_comment"];
              $data["kuerzel"]      = $_POST ["fs_shortname"];
              $data["time"]         = $my_upload->convtaktodatetime ($_POST ["fs_timestamp"]);
              $my_upload->save_in_db ($data);

        }
      }
      unset ($_SESSION ["UPLOAD"]);
      if (!debug) {     header("Location: ".$conf_4f ["MainURL"]); }
    break;
    default: echo "SESSION[UPLOAD]=".$_SESSION["UPLOAD"]."<br>";
  }




if ( debug == true ){
  echo "<br><br>\n";
  echo "------ UPLOAD.PHP ------";
  echo "GET     ="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST    ="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE  ="; var_dump ($_COOKIE); echo "#<br><br>\n";
  // echo "SERVER  ="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION ="; var_dump ($_SESSION); echo "#<br><br>\n";
  echo "FILES   ="; var_dump ($_FILES); echo "#<br><br>\n";
}


?>
