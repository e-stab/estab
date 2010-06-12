<html>

<head>
  <title></title>
</head>

<body>

<?php
define ("debug",true);

session_start ();

if ( debug == true ){
  echo "<br><br>\n";
  echo "GET="; var_dump ($_GET);    echo "#<br><br>\n";
  echo "POST="; var_dump ($_POST);   echo "#<br><br>\n";
  echo "COOKIE="; var_dump ($_COOKIE); echo "#<br><br>\n";
  //echo "SERVER="; var_dump ($_SERVER); echo "#<br><br>\n";
  echo "SESSION="; print_r ($_SESSION); echo "#<br>\n";
}

echo "<br><br>";

include "data_hndl.php";
include "../db_operation.php";

list_of_readed_msg ();

?>

</body>

</html>
