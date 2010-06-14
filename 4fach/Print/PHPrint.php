<?php
/************************************************************************
**  PHPrint originally created by MikeNew.Net
*   Version 1.3
*
**  Updated for use with PHP 4.1.xx                	9-22-02
**  Updated opening and reading of html page     	9-22-02
**  Updated functions to pass values by reference	9-22-02
**  Updated function i_denude() to strip all img tags   9-25-02
**  Addition of Javascript printWin() function     	9-22-02
**  Updated i_denudef function; Added ability to	1-16-03
**		parse php code (except looping type code)
**		Added ability to get remove file by url
**		querystring.
**  Collaborative effort by: 	lunadesign.org
*                           	looneynav.com
*                           	thejehm.net
*								sijis.cjb.net
*
**  Legal: Users of this script are responsible for any damages caused
*   by use of this script. (Not that it will, probably. Never has.)
*   This script will make your pages printer friendly.
*   By default this version will strip images as well.
*
**  Use: Link the page you would like to print to this page with a
*   standard html <a href="">Link</a> tag.
*	Examples:
*		php: <a href="print.php?url=<?= $_SERVER['PHP_SELF']; ?>">Print this page</a>
*		html <a href="print.php?url=/directory/file.html">Print another page</a>
*
**  To leave images intact change line 52 to $stripImages = "no";
*
**  This page must be in the root directory.
*
**  Place <!-- startprint --> at the beginning of the content you
*   would like to print and <!-- stopprint --> at the end.
*
**  That's all there is to it.
*************************************************************************/
// Begin: Functions used.
function i_denude(&$variable){
	//STRIP ALL TAGS THAT START WITH <img...
	return eregi_replace("< *img[^>]* *>", "", $variable);
}

function i_denudef(&$variable){
	$tmpText = preg_replace("/(< *font[^>]* *>)(.*)((< *\/ *font[^>]* *>))/i","$2", $variable);
	return $tmpText;
}
// End functions used.

// Set Variables
$url = $_REQUEST['url'];
$url = ereg_replace("^/?", "", $url);	//strip slashes for security
$stripImages = "yes";
$startingpoint	= "<!-- startprint -->";
$endingpoint	= "<!-- stopprint -->";
$referer = $_SERVER['HTTP_REFERER'];

// Open and read file specified in URL.
$file = $url; // $chage $url to $_SERVER['HTTP_REFERER'];
			  // to use as older version.
$str_value = "";
$read = fopen($file, "rb");
while(!feof($read)){
    $str_value .= fread($read, filesize($file));
}
fclose($read);

// Get length of print text
$str_start = strpos($str_value, "$startingpoint");
$str_finish = strpos($str_value, "$endingpoint");
$str_length = $str_finish-$str_start;
$str_value = substr($str_value, $str_start, $str_length);

// Got length of string between $startingpoint and $endpoint
// and send to other $variable. Easy to debug for later revision
$PHPrint = $str_value;

// Strip HTML font tag and/or HTML img tags.
if ($stripImages == "yes") {
   $PHPrint = i_denude($PHPrint);
}
$PHPrint = i_denudef($PHPrint);
$PHPrint = stripslashes($PHPrint);
?>
<html>
<head>
<title>Print this Page</title>
<script language="Javascript">
function printWindow() {
    bV = parseInt(navigator.appVersion);
         if (bV >= 4) window.print();
}
</script>
</head>
<body onload="printWindow();">
<?php
// Put stripped $string into array to filter for php code
// for parsing on the page.
// Currently it doesn't output looping statements.
$mode = 0; // 0 for printing , 1 for executing.
$arrfile = explode("\n",$PHPrint);
foreach($arrfile as $line){
	if(strstr($line,"<?") && strstr($line,"?>")){
		eval('?>'.$line);
	}
	else{
		if (strstr($line,"<?")){
		   $mode = 1;
		}
		elseif (strstr($line,"?>")){
		   $mode = 0;
		}
		elseif ($mode){
			if(strstr($line,";")){
			   eval($line);
			}
		}
		else {
			echo $line;
		}
	}
}
echo "This page was printed from: $referer";
flush ();
?>
</body>
</html>
