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
if (!is_string($listSource)) {
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

$incoming = $section($listSource, 'case "FmNwE":', 'case "FmNwA":');
$outgoing = $section($listSource, 'case "FmNwA":', 'case "FmNw":');
$combined = $section($listSource, 'case "FmNw":', '} // switch');

$assert(
    str_contains($listSource, 'app/message_transport.php')
        && str_contains($incoming, '`01_medium`')
        && str_contains($incoming, 'Eingangsmedium')
        && str_contains($incoming, 'estab_message_medium_text'),
    'Incoming tracking list does not expose its translated receive medium'
);
$assert(
    str_contains($outgoing, '`03_datum`')
        && str_contains($outgoing, '`06_befweg`')
        && str_contains($outgoing, '`06_befwegausw`')
        && str_contains($outgoing, 'Beförderungsweg')
        && str_contains($outgoing, 'estab_message_transport_text')
        && str_contains($outgoing, 'Noch nicht befördert'),
    'Outgoing tracking list does not expose the actual transport route'
);
$assert(
    str_contains($combined, '`01_medium`')
        && str_contains($combined, '`06_befweg`')
        && str_contains($combined, '`06_befwegausw`')
        && str_contains($combined, 'Übermittlungsweg')
        && str_contains($combined, 'estab_message_medium_text')
        && str_contains($combined, 'estab_message_transport_text')
        && str_contains($combined, 'Noch nicht befördert'),
    'Combined tracking list does not distinguish incoming and outgoing routes'
);
$assert(
    str_contains($incoming, 'estab_message_html ($incomingMedium)')
        && str_contains($outgoing, 'estab_message_html ($transportPath)')
        && str_contains($combined, 'estab_message_html ($trackingPath)'),
    'Tracking-list transport values bypass the message HTML boundary'
);

echo 'Message transport/tracking security: OK (' . $assertions
    . " assertions)\n";
