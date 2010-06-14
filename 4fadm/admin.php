<?php

class admmenue {

  var $in_table ;
  var $last_col ;

  function admmenue (){
    include ("admmenue.inc.php");

    $this->Init_HTML ();
    $this->head ();
    $this->in_table = false ;
    $this->last_col = "";
    foreach ($menue_item as $item){
      if ($item ["vis"]== true) $this->print_menue_col ($item);
//      var_dump($item);  echo "<br>";
    }
  }

  function Init_HTML () {
    echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">";
    echo "<html>";
    echo "<head>";
    echo "  <meta http-equiv=\"CONTENT-TYPE\" content=\"text/html; charset=iso\">";
    echo "  <title>Administrative Massnahme</title>";
    echo "  <meta name=\"GENERATOR\" content=\"OpenOffice.org 2.0  (Linux)\">";
    echo "  <meta name=\"AUTHOR\" content=\"Hajo Landmesser\">";
    echo "  <meta name=\"CREATED\" content=\"20070327;15421200\">";
    echo "  <meta name=\"CHANGEDBY\" content=\"hajo\">";
    echo "  <meta name=\"CHANGED\" content=\"20080612;18052200\">";
    echo "  <style type=\"text/css\">";

    echo "        A:link    { color:#0000EE; text-decoration:none; font-weight:normal; font-size:larger ; }";
    echo "        A:visited { color:#0000EE; text-decoration:none; font-weight:normal; font-size:larger ; }";
    echo "        A:hover   { color:#000000; text-decoration:none;  }";
    echo "        A:active  { color:#0000EE; background-color:#FFFF99; }";
    echo "        A:focus   { color:#0000EE; background-color:#FFFF99; }";

    echo "        </style>";
    echo "</head>";
    echo "<body>";
  }

  function head (){
     // Überschrift
   echo "<p style=\"border: 4px double rgb(216, 253, 2);
                padding: 40px; background-color: rgb(128, 128, 64);
                color: rgb(216, 253, 2);
                font-family: 'Century Schoolbook',serif;
                font-size: 2em;
                letter-spacing: 3px;\"
                align=\"center\">Administrative Ma&szlig;nahmen</p>";
  }


  function inittable(){
    echo "<table border=\"1\" cellpadding=\"3\" cellspacing=\"3\" width=\"100%\">";
    echo "<col width=\"100*\">";
    echo "<col width=\"156*\">";
    echo "<tbody>";
  }


  function deinittable (){
    echo "</tbody>";
    echo "</table>";
  }


  function tablerow ($firstrow, $description, $link){
    echo "<tr>";
    echo "<td width=\"30%\">";
    echo "<a href=".$link.">".$firstrow."</a>";
    echo "</td>";
    echo "<td valign=\"top\" width=\"61%\">";
    echo "<p>".$description."</p>";
    echo "</td>";
    echo "</tr>";
  }


  function breake (){
    echo "<hr style=\"border: 1px dashed blue;
         width: 100%; color: yellow;
         background-color: yellow;
         height: 10px;
         margin-right: 0pt;
         text-align: left;\">";
  }


  function print_menue_col ($item){

      // Initialisie eine Tabelle
    if ((( $this->last_col == "" ) AND
        ( $item["link"] != "breake" )) OR

       (( $this->last_col == "breake" ) AND
        ( $this->in_table == false ))){
      $this->inittable();
      $this->in_table = true ;
    }

      // Tabellenzeile
    if ( ( $item["link"] != "breake" ) AND
         ( $this->in_table == true )) {
      $this->tablerow ($item ["menue"],
                       $item ["descr"],
                       $item ["link"]);
      $this->last_col = "tabelle";
      $this->in_table = true ;
    }

      //
    if ( $item["link"] == "breake" ){
      // Waren wir in einer Tabelle?
      if ( $this->last_col == "tabelle" ){
        $this->deinittable ();
        $this->breake ();
        $this->last_col = "breake";
        $this->in_table = false ;
      }
    }

  }




} // class admmenue


$the_menue = new admmenue ;


?>
