<html>

<head>
  <title>Status und Statistik</title>
</head>

<body>

<?php

    include ("./dbcfg.inc.php");

    function mysql_status($db) {
      $res=mysql_query("show status",$db);
      while (list($key,$value)=mysql_fetch_array($res))
      $sql[$key]=$value;
      return $sql;
    }

    $db_hndl = mysql_connect ( $conf_4f_db ["server"] ,
                               $conf_4f_db ["user"] ,
                               $conf_4f_db ["password"] );

    $status = explode('  ', mysql_stat($db_hndl));

    print_r($status);

    echo "<br><br><br>";

    $sql = mysql_status ($db_hndl);

//    print_r ($sql);

    echo "<table>";

    $key = array_keys($sql);
    for ($i=0; $i<= count ($sql); $i++){
      echo "<tr>";
      echo "<td>";
      echo $key [$i];
      echo "</td><td>";
      echo $sql[$key [$i]];
      echo "</td></tr>";
    }
    echo "</table>";

?>

</body>

</html>