<?php
// Dieser historische Direktendpunkt umging die transaktionale Reservierung in
// ../anhang.php. Die URL bleibt als eindeutiger HTTP-410-Tombstone erhalten.
http_response_code(410);
header('Content-Type: text/html; charset=UTF-8');
echo '<!doctype html><html><body><p>Dieser alte Uploadpfad ist deaktiviert. '
    . 'Bitte die Anhang-Funktion der Anwendung verwenden.</p></body></html>';
exit;
