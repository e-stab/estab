<?php
/*****************************************************************************\
   Datei: protokoll.php

   benÃ¶tigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big><big>Protokoll</big></big><br>";  }
require_once __DIR__ . "/../app/incident.php";
/*****************************************************************************\
  function protokolleintrag ();
       p_zeit,         - Zeitstempel
       p_was,        -  Art des Ereignis
       p_ereignis  -  Daten des Ereignis

Aufruf:

\*****************************************************************************/

  function protokolleintrag ($was, $daten){
     include ("../4fcfg/dbcfg.inc.php");
     include ("../4fcfg/e_cfg.inc.php");
     if (!is_string ($was) || !is_string ($daten)) {
       throw new InvalidArgumentException ("Ungültiger Protokolleintrag");
     }
     $connection = estab_auth_connect ($conf_4f_db);
     try {
       $incidentId = null;
       if (preg_match ("/(?:\\A|;)message_id=([1-9][0-9]*)(?:;|\\z)/D", $daten, $parts)) {
         $messageId = estab_incident_positive_id ($parts [1], "Meldungs-ID");
         $messageLookup = $connection->prepare (
           "SELECT `einsatz_id` FROM ".estab_auth_table ($conf_4f_tbl ["nachrichten"]).
           " WHERE `00_lfd` = ? LIMIT 1"
         );
         if (!$messageLookup) {
           throw new RuntimeException ("Protokollbezug konnte nicht vorbereitet werden");
         }
         try {
           $messageLookup->bind_param ("i", $messageId);
           if (!$messageLookup->execute ()) {
             throw new RuntimeException ("Protokollbezug konnte nicht gelesen werden");
           }
           $result = $messageLookup->get_result ();
           $row = $result->fetch_assoc ();
           $result->free ();
         } finally {
           $messageLookup->close ();
         }
         if (!is_array ($row) || $row ["einsatz_id"] === null) {
           throw new RuntimeException ("Protokollbezug wurde nicht gefunden");
         }
         $incidentId = estab_incident_positive_id (
           (string) $row ["einsatz_id"]
         );
       } elseif (!in_array (
         $was,
         array ("Anmelden", "Funktion Ummelden", "Sessiondaten neu setzen"),
         true
       )) {
         $incident = estab_incident_require_active ($connection);
         $incidentId = (int) $incident ["active_einsatz_id"];
       }

       $query = "INSERT INTO ".estab_auth_table ($conf_4f_tbl ["protokoll"]).
         " (`einsatz_id`, `p_zeit`, `p_was`, `p_ereignis`)".
         " VALUES (?, CURRENT_TIMESTAMP, ?, ?)";
       $statement = $connection->prepare ($query);
       if (!$statement) {
         throw new RuntimeException ("Protokolleintrag konnte nicht vorbereitet werden");
       }
       try {
         $statement->bind_param ("iss", $incidentId, $was, $daten);
         if (!$statement->execute ()) {
           throw new RuntimeException ("Protokolleintrag konnte nicht geschrieben werden");
         }
       } finally {
         $statement->close ();
       }
     } finally {
       estab_auth_close ($connection);
     }
  } // function protokolleintrag
?>
