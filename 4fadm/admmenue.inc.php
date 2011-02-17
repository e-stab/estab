<?php


  $menue_item = array  (
    1 => array ("acl"   => "",
                "vis"   => true,
                "link"  => "./make_conf.php?task=einsatz_neu",
                "menue" => "EINSATZ erstellen",
                "descr" => "<b>Anlegen eines neuen Einsatzes. </b>Es wird eine Datenbank und ein Verzeichnis angelegt.&nbsp;<b></b>"),

    2 => array ("acl"   => "",
                "vis" => true,
                "link"  => "./make_fkt.php",
                "menue" => "Empf&auml;ngermatrix bearbeiten",
                "descr" => "Bearbeiten der Empf&auml;nger (Fachberater) im Sichterbereich sowie der Funktionen die sich anmelden k&ouml;nnen."),

    3 => array ("acl"   => "",
                "vis" => false,
                "link"  => "./std_si_fkt.php",
                "menue" => "Ersatzsichter",
                "descr" => "Sichtung durch: Fernmelder (A/W) oder automatische Sichtung."),

    4 => array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    5 => array ("acl" => "",
                "vis" => true,
                "link"  => "./set_number_after_crash.php",
                "menue" => "Nachrichtennummerzähler setzen",
                "descr" => "Nachrichtenzähler nach Systemausfall erhöhen."),

    6 => array ("acl" => "",
                "vis" => true,
                "link"  => "./make_conf.php?task=einsatz_ende",
                "menue" => "EINSATZ beenden",
                "descr" => "<b>Abschliessen eines Einsatzes.</b> Alle Tabellen der Datenbank werden als CSV-Datei in das Einsatzverzeichnis gespeichert."),

    7 => array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    8 => array ("acl" => "",
                "vis" => true,
                "link"  => "../4fach/resetpic.php",
                "menue" => "Grafik zur&uuml;cksetzen",
                "descr" => "Zur&uuml;cksetzen des&nbsp;Grafikflags in der Datenbank."),

    9 => array ("acl" => "",
                "vis" => true,
                "link"  => "../4fbak/backup.php?anz=50",
                "menue" => "Grafiken erzeugen",
                "descr" => "Es wird versucht 50 Nachrichten in Grafiken zu konvertieren. Gegebenenfalls muß der Prozess mehrmals gestartet werden."),

    10 => array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    11=> array ("acl" => "",
                "vis" => true,
                "link"  => "./make_conf.php?task=datenbank",
                "menue" => "Datenbankparameter eingeben",
                "descr" => "Anlegen der Datenbankparameter.<br>Serveradresse, Datenbankbenutzer, Tabellenpräfix usw."),

    12=> array ("acl" => "",
                "vis" => true,
                "link"  => "./db_check.php",
                "menue" => "Datenbankverbindung pr&uuml;fen",
                "descr" => "Hiermit kann gepr&uuml;ft werden ob mit den gegebenen Einstellungen eine Verbindung zur Datenbank aufgebaut werden kann."),

    13=> array ("acl" => "",
                "vis" => true,
                "link"  => "./create_db.php",
                "menue" => "Anlegen der Datenbank und der Tabellen",
                "descr" => "Die Datenbank und die erforderlichen Tabellen werden angelegt, soweit diese nicht schon vorhanden sind."),

    14=> array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    15=> array ("acl" => "",
                "vis" => false,
                "link"  => "./FMD_statis.php",
                "menue" => "Betriebszustand/Statistiken",
                "descr" => "Anzeige des Betriebszustandes der Fernmeldezentrale"),

    16=> array ("acl" => "",
                "vis" => false,
                "link"  => "./edt_para.php",
                "menue" => "Anzeigeparameter",
                "descr" => "Einstellung der Farben und der Aktualisierungsintervalle"),

    17=> array ("acl" => "",
                "vis" => true,
                "link"  => "../4fcfg/echo_config.inc.php",
                "menue" => "Konfigurationsdatei",
                "descr" => "Listet den Inhalt der config.inc.php Datei auf."),

    18=> array ("acl" => "",
                "vis" => true,
                "link"  => "./phpinfo.php",
                "menue" => "PHP Info",
                "descr" => "Informationsseite zur PHP Installation")


    );
?>