<?php

declare(strict_types=1);

/**
 * Fb Fü 44, column 7 "Quittung / Empfänger / Ausgehändigt".
 *
 * A message that has just been taken in has not been handed to anybody yet, so
 * the TBB row the intake writes cannot carry the receipt. The TBB is
 * append-only, so that row can never be completed later either. The Handbuch
 * ETB/TBB keeps its own entry kind for the event — "Quittung, Empfänger oder
 * Aushändigung" — and the completed sighting has to append one. The recipients
 * it names have to be readable text: field 16 stores internal matrix tokens
 * such as "S2_rt,", which name an application feature and not a person.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

$previousDirectory = getcwd();
if (!chdir($root . '/4fach')) {
    throw new RuntimeException('Could not enter the message controller directory');
}
try {
    require_once $root . '/4fach/data_hndl.php';
    require_once $root . '/app/logbook.php';
} finally {
    if (is_string($previousDirectory)) {
        chdir($previousDirectory);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$section = static function (string $source, string $start, string $end): string {
    $startOffset = strpos($source, $start);
    if (!is_int($startOffset)) {
        throw new RuntimeException('Missing source boundary: ' . $start);
    }
    $endOffset = strpos($source, $end, $startOffset + strlen($start));
    if (!is_int($endOffset) || $endOffset <= $startOffset) {
        throw new RuntimeException('Missing source boundary: ' . $end);
    }

    return substr($source, $startOffset, $endOffset - $startOffset);
};
$read = static function (string $path) use ($root, $assert): string {
    $source = file_get_contents($root . '/' . $path);
    $assert(
        is_string($source) && $source !== '',
        estab_dv_requirement(
            'TBB-QUITTUNG-AUSHAENDIGUNG',
            'Die Quelle ' . $path . ' ist nicht lesbar.'
        )
    );

    return (string) $source;
};

$lifecycle = $read('app/logbook_lifecycle.php');
$repository = $read('app/message_repository.php');
$handler = $read('4fach/data_hndl.php');
$migration = $read('docker/db/migrations/110-etb-tbb-rules.sql');

// 1. The book keeps an entry kind for exactly this event.
$assert(
    array_key_exists('quittung', estab_logbook_ttb_entry_types())
        && estab_logbook_ttb_entry_types()['quittung']
            === 'Quittung, Empfänger oder Aushändigung',
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Das TBB kennt keine Eintragsart für Quittung, Empfänger und '
        . 'Aushändigung.'
    )
);

// 2. The handover is appended, never merged into the immutable intake row.
$assert(
    function_exists('estab_logbook_lifecycle_message_handover'),
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Die Aushändigung der eingegangenen Nachricht erzeugt keinen eigenen '
        . 'TBB-Eintrag.'
    )
);

// 3. No message evidence carries internal tokens in the official column.
$transport = $section(
    $lifecycle,
    'function estab_logbook_lifecycle_message_transport(',
    'function estab_logbook_lifecycle_open_books('
);
$assert(
    !str_contains($transport, "\$message['16_empf']")
        && !str_contains($transport, '`16_empf`'),
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Der Nachweis der Aufnahme schreibt die internen Verteilerkennungen '
        . 'des Feldes 16 wörtlich in die amtliche Quittungsspalte.'
    )
);
$handover = $section(
    $lifecycle,
    'function estab_logbook_lifecycle_message_handover(',
    'function estab_logbook_lifecycle_open_books('
);
$assert(
    str_contains($handover, "'quittung',")
        && str_contains($handover, 'estab_logbook_lifecycle_insert_ttb_record(')
        && str_contains($handover, "'receipt' => 'Ausgehändigt an '")
        && !str_contains($handover, 'UPDATE `nv_tbb`')
        && !str_contains($lifecycle, 'UPDATE `nv_tbb`')
        && !str_contains($repository, 'UPDATE `nv_tbb`'),
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Die Quittungsspalte wird nicht durch einen weiteren Eintrag der Art '
        . '"Quittung, Empfänger oder Aushändigung" ergänzt.'
    )
);
$assert(
    str_contains(
        $migration,
        'ADD UNIQUE INDEX `idx_tbb_message` (`einsatz_id`, `estab_message_id`)'
    )
        && str_contains(
            $migration,
            'TTB message link requires canonical message entry'
        ),
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Der Ergänzungseintrag darf die eindeutige Nachrichtenverknüpfung des '
        . 'Aufnahmeeintrags nicht ein zweites Mal belegen.'
    )
);

// 4. The completed sighting books the handover in the same transaction.
$review = $section(
    $repository,
    'function estab_message_update_pending_review(',
    'function estab_message_resubmit_returned_outgoing('
);
$evidencePosition = strpos($review, 'estab_message_append_transition_evidence(');
$handoverPosition = strpos($review, 'estab_logbook_lifecycle_message_handover(');
$assert(
    is_int($evidencePosition)
        && is_int($handoverPosition)
        && $evidencePosition < $handoverPosition
        && str_contains($review, "(int) (\$fields['x00_status'] ?? 0) === 8")
        && str_contains($review, 'string $handedOverTo'),
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Der Abschluss der Sichtung schreibt die Aushändigung nicht '
        . 'unteilbar mit dem Statuswechsel fort.'
    )
);
$assert(
    str_contains(
        $handler,
        'estab_message_list_recipient_labels ('
    )
        && str_contains($handler, '$reviewRecipients')
        && function_exists('estab_message_list_recipient_labels'),
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Die Sichtung übergibt der Quittungsspalte keine übersetzten '
        . 'Empfängernamen.'
    )
);

// 5. The existing translation turns stored tokens into readable names.
$assert(
    estab_message_list_recipient_labels('S2_rt,S3_gn,A/W_bl,')
        === ['Fernmelder', 'S2', 'S3'],
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Die vorhandene Übersetzung liefert keine lesbaren Empfängernamen.'
    )
);

// 6. The official column refuses application-internal identifiers outright.
// Both rejections happen before any database access, so a stand-in connection
// keeps this executable in the dependency-free PHP container.
if (!class_exists('mysqli')) {
    class mysqli
    {
    }
}
$connection = new mysqli();
foreach ([
    '',
    '   ',
    'S2_rt',
    'S2_rt,',
    'S2_rt,S3_gn,',
    'S2_rt, S3_gn',
    'LS_bl',
    'A/W_gn',
] as $rejected) {
    $refused = false;
    try {
        estab_logbook_lifecycle_message_handover(
            $connection,
            17,
            42,
            '2026-08-18 10:15:00',
            'si0001',
            $rejected
        );
    } catch (InvalidArgumentException) {
        $refused = true;
    } catch (Throwable) {
        $refused = false;
    }
    $assert(
        $refused,
        estab_dv_requirement(
            'TBB-QUITTUNG-AUSHAENDIGUNG',
            'Die Quittungsspalte nimmt den Wert "' . $rejected . '" an.'
        )
    );
}

$plainTextAccepted = false;
try {
    estab_logbook_lifecycle_message_handover(
        $connection,
        17,
        42,
        '2026-08-18 10:15:00',
        'si0001',
        'Fernmelder, S2, S3'
    );
} catch (InvalidArgumentException) {
    $plainTextAccepted = false;
} catch (Throwable) {
    // The stand-in connection cannot answer; the readable list passed the gate.
    $plainTextAccepted = true;
}
$assert(
    $plainTextAccepted,
    estab_dv_requirement(
        'TBB-QUITTUNG-AUSHAENDIGUNG',
        'Übersetzte Empfängernamen werden wie interne Kennungen abgewiesen.'
    )
);

echo "TBB receipt handover: OK ({$assertions} assertions)\n";
