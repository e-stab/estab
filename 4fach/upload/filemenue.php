<?php

print_r($_POST); echo "<br>";
print_r($_GET); echo "<br>";


function readDirectory($pfad)
{
    $filesArr = array();
    if($ordner = dir($pfad))
    {
        while($datei = $ordner->read())
        {
        if($datei != "." && $datei != "..") array_push($filesArr,$datei);
        }
    }
    return $filesArr;
}



$files = readDirectory ("/srv/www/htdocs/intern/kats_entw/4fach/upload/");

foreach ($files as $file){
  echo $file."<br>";
}
echo "<br><br><br><br><br>";

print_r ($files); echo "<br><br><br><br><br>";

//if (isset($_POST)){ echo "UPLOAD=".$_POST[upload]."<br>"; exit; }

?>


<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
"http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
<title>Upload example</title>
</head>

<body>
<h3>File upload script:</h3>
<p>Max. filesize = <?php echo $max_size; ?> bytes.</p>
<form name="form1" enctype="multipart/form-data" method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>">
  <input type="file" name="upload" size="150">

  <input type="submit" name="Submit" value="Submit">
</form>
</body>
</html>