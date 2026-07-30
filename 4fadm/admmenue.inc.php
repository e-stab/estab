<?php


  $menue_item = array  (
    array ("acl"   => "",
                "vis"   => false,
                "link"  => "",
                "menue" => "Schnellstart",
                "descr" => "Im Container durch Compose, Umgebungsvariablen und das Datenbankschema ersetzt."),

    array ("acl"   => "",
                "vis"   => false,
                "link"  => "",
                "menue" => "Schnellstart Einstellungen",
                "descr" => "Im Container durch Compose und Umgebungsvariablen ersetzt."),

    array ("acl" => "",
                "vis" => false,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    array ("acl"   => "",
                "vis"   => false,
                "link"  => "",
                "menue" => "EINSATZ erstellen",
                "descr" => "Datenbank und persistente Verzeichnisse werden beim Containerstart bereitgestellt."),

    array ("acl"   => "",
                "vis" => true,
                "link"  => "./make_fkt.php",
                "menue" => "Empf&auml;ngermatrix bearbeiten",
                "descr" => "Bearbeiten der Empf&auml;nger (Fachberater) im Sichterbereich sowie der Funktionen die sich anmelden k&ouml;nnen."),

    array ("acl"   => "",
                "vis" => true,
                "link"  => "./fuehrungsstelle.php",
                "menue" => "F&uuml;hrungsstelle und Dienstschichten",
                "descr" => "Dienstschichten, Mehrfachfunktionen und best&auml;tigte &Uuml;bergaben gem&auml;&szlig; DV 1-101 verwalten."),

    array ("acl"   => "",
                "vis" => false,
                "link"  => "",
                "menue" => "Ersatzsichter",
                "descr" => "Nicht verfügbar: Sichtung erfordert eine besetzte Funktion Si."),

    array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    array ("acl" => "",
                "vis" => true,
                "link"  => "./set_number_after_crash.php",
                "menue" => "Nachrichtennummerz&auml;hler setzen",
                "descr" => "Nachrichtenz&auml;hler nach Systemausfall erh&ouml;hen."),

    array ("acl" => "",
                "vis" => true,
                "link"  => "./export.php",
                "menue" => "Einsatzexport",
                "descr" => "Exporte aller Basistabellen erstellen, als ZIP herunterladen, mit Manifest und Pr&uuml;fsummen ansehen oder gezielt l&ouml;schen."),

    array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    array ("acl" => "",
                "vis" => true,
                "link"  => "../4fach/resetpic.php",
                "menue" => "Grafik zur&uuml;cksetzen",
                "descr" => "Zur&uuml;cksetzen des&nbsp;Grafikflags in der Datenbank."),

    array ("acl" => "",
                "vis" => false,
                "link"  => "",
                "menue" => "Grafiken erzeugen",
                "descr" => "Der unsichere Alt-Batch-Endpunkt ist im Container deaktiviert."),

    array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    array ("acl" => "",
                "vis" => false,
                "link"  => "",
                "menue" => "Datenbankparameter eingeben",
                "descr" => "Datenbankparameter werden ausschlie&szlig;lich &uuml;ber Compose, ENV und Secrets gesetzt."),

    array ("acl" => "",
                "vis" => true,
                "link"  => "./system_status.php",
                "menue" => "Systemstatus",
                "descr" => "Nicht-sensible Laufzeit-, Datenbank- und Speicherpr&uuml;fung sowie Hinweise zur Containerkonfiguration."),

    array ("acl" => "",
                "vis" => false,
                "link"  => "",
                "menue" => "Anlegen der Datenbank und der Tabellen",
                "descr" => "Das Schema wird ausschlie&szlig;lich durch den MariaDB-Container initialisiert."),

    array ("acl" => "",
                "vis" => true,
                "link"  => "breake",
                "menue" => "breake",
                "descr" => "breake"),

    array ("acl" => "",
                "vis" => false,
                "link"  => "",
                "menue" => "Betriebszustand/Statistiken",
                "descr" => "Anzeige des Betriebszustandes der Fernmeldezentrale"),

    array ("acl" => "",
                "vis" => false,
                "link"  => "",
                "menue" => "Anzeigeparameter",
                "descr" => "Einstellung der Farben und der Aktualisierungsintervalle"),

    array ("acl" => "",
                "vis" => false,
                "link"  => "",
                "menue" => "Konfigurationsdatei",
                "descr" => "Konfiguration und Secrets werden im Container nicht &uuml;ber das Web offengelegt."),

    array ("acl" => "",
                "vis" => false,
                "link"  => "",
                "menue" => "PHP Info",
                "descr" => "Die vollst&auml;ndige PHP-Diagnose ist im Container deaktiviert; der Systemstatus zeigt nur notwendige Merkmale.")


    );
?>
