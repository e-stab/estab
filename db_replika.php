<?php



// umdefinieren der Konstanten - nur in PHP 4
define("FATAL", E_USER_ERROR);
define("ERROR", E_USER_WARNING);
define("WARNING", E_USER_NOTICE);

// die Stufe für dieses Skript einstellen
error_reporting(FATAL | ERROR | WARNING);

// Fehlerbehandlungsfunktion
function myErrorHandler($errno, $errstr, $errfile, $errline)
{
  switch ($errno) {
  case FATAL:
    echo "<b>FATAL</b> [$errno] $errstr<br />\n";
    echo "  Fatal error in line $errline of file $errfile";
    echo ", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br />\n";
    echo "Aborting...<br />\n";
    exit(1);
    break;
  case ERROR:
    echo "<b>ERROR</b> [$errno] $errstr<br />\n";
    break;
  case WARNING:
    echo "<b>WARNING</b> [$errno] $errstr<br />\n";
    break;
  default:
    echo "Unkown error type: [$errno] $errstr<br />\n";
    break;
  }
}

// Funktion zum Test der Fehlerbehandlung
function scale_by_log($vect, $scale)
{
  if (!is_numeric($scale) || $scale <= 0) {
    trigger_error("log(x) for x <= 0 is undefined, you used: scale = $scale",
      FATAL);
  }

  if (!is_array($vect)) {
    trigger_error("Incorrect input vector, array of values expected", ERROR);
    return null;
  }

  for ($i=0; $i<count($vect); $i++) {
    if (!is_numeric($vect[$i]))
      trigger_error("Value at position $i is not a number, using 0 (zero)",
        WARNING);
    $temp[$i] = log($scale) * $vect[$i];
  }
  return $temp;
}

// auf die benutzerdefinierte Fehlerbehandlung umstellen
$old_error_handler = set_error_handler("myErrorHandler");

// einige Fehler auslösen, zuerst wird ein gemischtes Array
// definiert mit einem nichtnummerischen Eintrag
echo "vector a\n";
$a = array(2, 3, "foo", 5.5, 43.3, 21.11);
print_r($a);

// ein zweites Array erzeugen, das Warnungen generiert
echo "----\nvector b - a warning (b = log(PI) * a)\n";
$b = scale_by_log($a, M_PI);
print_r($b);

// hier ist der Grund für das Problem: anstatt einem Array
// wird ein String übergeben
echo "----\nvector c - an error\n";
$c = scale_by_log("not array", 2.3);
var_dump($c);

// dies ist ein kritischer Fehler, der log() ist für null
// oder negative Werte nicht definiert
echo "----\nvector d - fatal error\n";
$d = scale_by_log($a, -2.5);

?>
