<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/message_transport.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'Fe' => 'Fernsprecher',
    'Fu' => 'Funk',
    'Me' => 'Melder',
    'FAX' => 'Fax',
    'Fax' => 'Fax',
    'FS' => 'Fernschreiber',
    '@' => 'Datenübertragung',
    'DFÜ' => 'Datenübertragung',
] as $stored => $label) {
    $assert(
        estab_message_medium_text($stored) === $label,
        'Transport medium was not translated: ' . $stored
    );
}

$assert(
    estab_message_medium_storage_value('Fernsprecher') === 'Fe'
        && estab_message_medium_storage_value('Funk') === 'Fu'
        && estab_message_medium_storage_value('Melder') === 'Me'
        && estab_message_medium_storage_value('Telefaksimile') === 'FAX'
        && estab_message_medium_storage_value('Fernschreiber') === 'FS'
        && estab_message_medium_storage_value('DFÜ') === '@'
        && estab_message_medium_storage_value('Sat') === null
        && estab_message_medium_storage_value([]) === null,
    'Submitted transport media were not normalized to safe schema values'
);

$assert(
    estab_message_transport_text('Fu', 'Relaisstelle 1')
        === 'Funk · Relaisstelle 1',
    'Medium and operational transport route were not combined'
);
$assert(
    estab_message_transport_text('Fu', 'Funk') === 'Funk'
        && estab_message_transport_text('@', 'DFÜ') === 'Datenübertragung'
        && estab_message_transport_text('', 'Kurierweg') === 'Kurierweg',
    'Equivalent or route-only transport values were formatted incorrectly'
);
$assert(
    estab_message_medium_text('Sat') === 'Unbekannt (Sat)'
        && estab_message_medium_html('<script>alert(1)</script>')
            === 'Unbekannt (&lt;script&gt;alert(1)&lt;/script&gt;)'
        && estab_message_transport_html(
            'Fu',
            'Relais <img src=x onerror=alert(1)>'
        ) === 'Funk · Relais &lt;img src=x onerror=alert(1)&gt;',
    'Unknown transport values were hidden or emitted as active HTML'
);
$assert(
    estab_message_medium_text([]) === ''
        && estab_message_transport_text(new stdClass(), []) === '',
    'Non-scalar transport data was converted into display text'
);

$listSource = file_get_contents($root . '/4fach/liste.php');
$trackingPageSource = file_get_contents($root . '/4fach/nachwea.php');
if (!is_string($listSource) || !is_string($trackingPageSource)) {
    throw new RuntimeException('Could not inspect tracking-list source');
}

$section = static function (
    string $source,
    string $start,
    string $end
): string {
    $startPosition = strpos($source, $start);
    $endPosition = strpos(
        $source,
        $end,
        $startPosition === false ? 0 : $startPosition + strlen($start)
    );
    if ($startPosition === false || $endPosition === false) {
        throw new RuntimeException('Could not isolate tracking-list section');
    }
    return substr($source, $startPosition, $endPosition - $startPosition);
};

/*
 * Die Nachweisung liegt in app/nachweisung.php, nicht mehr in liste.php.
 *
 * Dort standen drei Zweige -- FmNwE, FmNwA, FmNw --, die niemand aufrief;
 * sie sind geloescht (siehe ges_tabelle_einheitlich, "kein Listenzweig
 * ohne Aufrufer"). Die Pruefungen, die hier standen, haben ihre Aussage
 * behalten und ihren Ort gewechselt.
 *
 * Eine Aussage ist dabei *nicht* mitgekommen, und das steht hier, damit
 * es nicht unbemerkt bleibt: Die geloeschten Zweige zeigten fuer Ausgaenge
 * den Befoerderungsweg (`06_befweg`, "Noch nicht befoerdert"). Die
 * lebende Nachweisung zeigt nur das Aufnahmemittel. Weil die Zweige
 * unerreichbar waren, hat das nie ein Bedienender gesehen -- ob der
 * Nachweis den Weg nennen soll, ist eine Frage an den Betreiber und keine
 * an die Technik.
 */
$nachweisung = (string) file_get_contents($root . '/app/nachweisung.php');
$nachweisungsSeite = (string) file_get_contents($root . '/4fach/nachwea.php');

/*
 * Das Uebermittlungsmittel wird zentral uebersetzt, nicht in der Seite.
 * "Fu" sagt einem Leser des Nachweises nichts, was "Funk" nicht
 * deutlicher saegte.
 */
$assert(
    str_contains($nachweisung, 'estab_message_medium_text')
        && str_contains($nachweisung, "'kopf' => 'Mittel'"),
    'Die Nachweisung nennt das Uebermittlungsmittel nicht oder uebersetzt '
        . 'es nicht zentral'
);

/*
 * Ein Nachweis gehoert zu genau einem Einsatz. Die Bedingung steht in der
 * Abfrage und nicht in einer Unterabfrage, die zwischen zwei Zeilen einen
 * anderen Einsatz treffen koennte.
 */
$assert(
    str_contains($nachweisung, 'WHERE m.`einsatz_id` = ?')
        && str_contains($nachweisungsSeite, 'estab_read_require_area (')
        && !str_contains(
            $nachweisung,
            '(SELECT `active_einsatz_id` FROM `nv_einsatz_status`'
        ),
    'Die Nachweisung ist nicht an genau einen Einsatz gebunden'
);

/*
 * Die Zeilen kommen als reiner Text ins Bauteil, das maskiert. Eine
 * Seite, die fertiges Markup liefert, umgeht die Maskierung -- und ein
 * Nachweis ist der letzte Ort, an dem fremder Text als Auszeichnung
 * gelten darf.
 */
$assert(
    str_contains($nachweisung, 'estab_message_plain_text')
        && !str_contains($nachweisung, '<td')
        && !str_contains($nachweisung, '<table'),
    'Die Nachweisung baut Markup selbst und umgeht damit die Maskierung'
);
$assert(
    str_contains(
        $trackingPageSource,
        '$trackingScope = estab_read_require_area ('
    )
        && str_contains(
            $trackingPageSource,
            'estab_incident_command_post_name ($trackingScope ["incident"]);'
        )
        && str_contains(
            $trackingPageSource,
            '$trackingScope ["incident"]["active_einsatz_id"]'
        )
        && substr_count(
            $trackingPageSource,
            '$trackingIncidentId'
        ) >= 6
        /*
         * Die Nachweisnummer kommt aus der gemeinsamen Ableitung, nicht
         * aus der technischen Ablaufnummer. Frueher stand das je einmal
         * fuer den Eingangs- und den Ausgangszweig von liste.php; beide
         * sind geloescht, und die Nachweisung leitet die Nummer an einer
         * Stelle ab.
         */
        && str_contains(
            $nachweisung,
            'estab_message_list_tbb_number_select_sql'
        )
        && str_contains(
            $nachweisung,
            'estab_message_list_tbb_evidence_label($zeile)'
        )
        && str_contains(
            $trackingPageSource,
            '} catch (EstabIncidentConfigurationException) {'
        )
        && substr_count(
            $trackingPageSource,
            'catch (EstabIncidentConfigurationException)'
        ) >= 2
        && str_contains($trackingPageSource, '@ob_clean ();')
        && substr_count(
            $trackingPageSource,
            'estab_session_ui_abort ('
        ) >= 2
        && str_contains($trackingPageSource, '"tracking"')
        && str_contains(
            $trackingPageSource,
            'Für den aktiven Einsatz fehlt der Führungsstellenname.'
        ),
    'Tracking page does not report an incomplete incident in the shared error UI'
);

echo 'Message transport/tracking security: OK (' . $assertions
    . " assertions)\n";
