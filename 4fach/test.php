<?php include ("../config.inc.php"); ?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="de" lang="de" dir="ltr">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <link rel="icon" href="./favicon.ico" type="image/x-icon" />
    <link rel="shortcut icon" href="./favicon.ico" type="image/x-icon" />
    <title>phpMyAdmin</title>

    <meta name="robots" content="noindex,nofollow" />
    <meta name="OBGZip" content="true" />

    <style type="text/css">

html {
    font-size: 100%;
}


a:link,
a:visited,
a:active {
    text-decoration:    none;
    color:              #333399;
}

a:hover {
    text-decoration:    underline;
    color:              #cc0000;
}
a img {
    border:             0;
}



/******************************************************************************/
/* specific elements */

/* topmenu */
ul#topmenu {
    font-weight:        bold;
    list-style-type:    none;
    margin:             0;
    padding:            0;
}

ul#topmenu li {
    float:              left;
    margin:             0;
    padding:            0;
    vertical-align:     middle;
}

#topmenu img {
    vertical-align:     middle;
    margin-right:       0.1em;
}

/* default tab styles */
.tab, .tabcaution, .tabactive {
    display:            block;
    margin:             0.2em 0.2em 0 0.2em;
    padding:            0.2em 0.2em 0 0.2em;
    white-space:        nowrap;
}

/* disabled tabs */
span.tab {
    color:              #666666;
}

/* disabled drop/empty tabs */
span.tabcaution {
    color:              #ff6666;
}

/* enabled drop/empty tabs */
a.tabcaution {
    color:              #FF0000;
}
a.tabcaution:hover {
    color: #FFFFFF;
    background-color:   #FF0000;
}

#topmenu {
    margin-top:         0.5em;
    padding:            0.1em 0.3em 0.1em 0.3em;
}

ul#topmenu li {
    border-bottom:      1pt solid black;
}

/* default tab styles */
.tab, .tabcaution, .tabactive {
    background-color:   #E5E5E5;
    border:             1pt solid #D5D5D5;
    border-bottom:      0;
    border-top-left-radius: 0.4em;
    border-top-right-radius: 0.4em;
}

/* enabled hover/active tabs */
a.tab:hover,
a.tabcaution:hover,
.tabactive,
.tabactive:hover {
    margin:             0;
    padding:            0.2em 0.4em 0.2em 0.4em;
    text-decoration:    none;
}

a.tab:hover,
.tabactive {
    background-color:   #ffffff;
}

/* to be able to cancel the bottom border, use <li class="active"> */
ul#topmenu li.active {
     border-bottom:      1pt solid #ffffff;
}





    </style>


</head>
<?php
echo "<body>";
echo "<div id=\"topmenucontainer\">";
?>
<ul id="topmenu">
  <li>
    <a class="tab" href="server_databases.php" >
      <?php
      echo "<img class=\"icon\" src=\"".$conf_design_path."/034.gif\" width=\"16\" height=\"16\" alt=\"Kategorielose Meldungen\">Kategorie";
      ?>
    </a>
  </li>
  <li>
    <a class="tab" href="server_databases.php" >
      <?php
      echo "<img class=\"icon\" src=\"".$conf_design_path."/034.gif\" width=\"16\" height=\"16\" alt=\"Kategorielose Meldungen\">ohne Kategorie";
      ?>
    </a>
  </li>
  <li>
    <a class="tab" href="server_databases.php" >
      <?php
      echo "<img class=\"icon\" src=\"".$conf_design_path."/034.gif\" width=\"16\" height=\"16\" alt=\"Kategorielose Meldungen\">ohne Kategorie";
      ?>
    </a>
  </li>
  <li>
    <a class="tab" href="server_databases.php" >
      <?php
      echo "<img class=\"icon\" src=\"".$conf_design_path."/034.gif\" width=\"16\" height=\"16\" alt=\"Kategorielose Meldungen\">ohne Kategorie";
      ?>
    </a>
  </li>
  <li>
    <a class="tab" href="server_databases.php" >
      <?php
      echo "<img class=\"icon\" src=\"".$conf_design_path."/034.gif\" width=\"16\" height=\"16\" alt=\"Kategorielose Meldungen\">ohne Kategorie";
      ?>
    </a>
  </li>



</ul>
<div class="clearfloat"></div></div>
</body>
</html>

