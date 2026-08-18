<?php

declare(strict_types=1);

/**
 * Der Fb-Fü-2-Ausdruck weist den zugehörigen Nachrichtenvordruck aus.
 *
 * Das gedruckte Einsatztagebuch wird ohne die Anwendung gelesen. Ein Eintrag,
 * der aus einem Nachrichtenvordruck entstanden ist, taugt daher nur als
 * Nachweis, wenn der Ausdruck den Vordruck benennt. Sichtbar ist an diesem
 * Vordruck allein die einsatzlokale TBB-Nachweisnummer aus Feld 4; der
 * globale Nachrichtenschlüssel ist keine Dokumentennummer und darf im
 * Ausdruck nicht erscheinen. Der Bezug gehört in die Spalte Bemerkungen und
 * muss in deren amtliche Breite passen.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/incident_pdf.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$incident = [
    'einsatz_id' => 12,
    'kennung' => 'EL-2026-001',
    'name' => 'Sturm Muenster',
    'beginn' => '2026-07-29 10:30:00',
    'ende' => null,
    'ort' => 'Muenster',
    'organisation' => 'Kreis',
    'fuehrungsstellenname' => 'FueSt-Sued-42',
    'einsatzleitung' => 'Max Beispiel',
    'estab_permission_mode' => 'STRICT',
    'estab_status' => 'open',
];

$linkedRow = [
    'estab_book_lfd' => 4,
    'etb_lfd-nr' => 500001,
    'estab_event_time' => '2026-07-29 11:00:00.000000',
    'estab_recorded_at' => '2026-07-29 11:00:05.000000',
    'estab_event_type' => 'nachricht',
    'estab_message_id' => 987654321,
    'estab_message_ttb_lfd' => 17,
    'etb_aktion' => 'Lagemeldung Nord aufgenommen',
    'etb_bemerk' => '',
    'etb_benutzer' => 'eStab-System',
    'etb_kuerzel' => 'system',
    'etb_funktion' => 'System',
];
$unprovenRow = array_replace($linkedRow, [
    'estab_book_lfd' => 5,
    'etb_lfd-nr' => 500002,
    'estab_message_id' => 987654322,
    'estab_message_ttb_lfd' => null,
    'etb_aktion' => 'Vermerk zu einer Nachricht ohne TBB-Nachweis',
]);
$zeroRow = array_replace($unprovenRow, [
    'estab_book_lfd' => 6,
    'etb_lfd-nr' => 500003,
    'estab_message_ttb_lfd' => 0,
]);
$ownEntryRow = array_replace($linkedRow, [
    'estab_book_lfd' => 7,
    'etb_lfd-nr' => 500004,
    'estab_event_type' => 'lage',
    'estab_message_id' => null,
    'estab_message_ttb_lfd' => null,
    'etb_aktion' => 'Eigene Lagefeststellung ohne Vordruck',
]);

$probe = new EstabIncidentPdf($incident, 1024);
$printableRow = new ReflectionMethod(
    EstabIncidentPdf::class,
    'etbPrintableRow'
);
$wrappedLines = new ReflectionMethod(
    EstabIncidentPdf::class,
    'wrappedTextLines'
);

/** Return the Bemerkungen column of one printable Fb-Fü-2 row. */
$remarksOf = static function (array $row) use (
    $probe,
    $printableRow,
    $assert
): string {
    $printable = $printableRow->invoke($probe, $row);
    $columns = $printable['columns'] ?? null;
    $assert(
        is_array($columns) && count($columns) === 4,
        estab_dv_requirement(
            'ETB-FBFUE2-NACHRICHTENBEZUG',
            'der Ausdruck folgt nicht mehr dem vierspaltigen Raster'
        )
    );
    return (string) $columns[3];
};

$linkedRemarks = $remarksOf($linkedRow);
$assert(
    str_contains($linkedRemarks, 'Nachricht: TBB-Nachweis 17'),
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'die Spalte Bemerkungen nennt zum verknüpften Vordruck nicht dessen '
            . 'sichtbare Nummer, hier "TBB-Nachweis 17"'
    )
);
$assert(
    !str_contains($linkedRemarks, '987654321'),
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'der Ausdruck weist den globalen Nachrichtenschlüssel statt der am '
            . 'Vordruck sichtbaren Nummer aus'
    )
);
foreach ([$unprovenRow, $zeroRow] as $rowWithoutProof) {
    $remarks = $remarksOf($rowWithoutProof);
    $assert(
        str_contains($remarks, 'Nachricht: noch kein TBB-Nachweis')
            && !str_contains($remarks, 'TBB-Nachweis 0')
            && !str_contains($remarks, '987654322'),
        estab_dv_requirement(
            'ETB-FBFUE2-NACHRICHTENBEZUG',
            'ein Eintrag mit Nachricht ohne TBB-Nachweis verschweigt den '
                . 'Bezug oder erfindet eine Nummer'
        )
    );
}
$assert(
    !str_contains($remarksOf($ownEntryRow), 'Nachricht'),
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'ein Eintrag ohne Nachrichtenvordruck behauptet einen Bezug'
    )
);

// Die Bemerkungen sind 38 mm breit; ein Bezug, der herausläuft, ist im
// Ausdruck nicht lesbar.
$probe->AddPage();
$probe->SetFont('helvetica', '', 7.5);
$remarksWidth = 38.0;
foreach ($wrappedLines->invoke($probe, $remarksWidth, $linkedRemarks) as $line) {
    $assert(
        $probe->GetStringWidth($line)
            <= $remarksWidth - 2.0 * $probe->cMargin,
        estab_dv_requirement(
            'ETB-FBFUE2-NACHRICHTENBEZUG',
            'der Nachrichtenbezug läuft aus der Spalte Bemerkungen heraus: '
                . $line
        )
    );
}

$pdf = new EstabIncidentPdf($incident, 1024);
$pdf->SetCompression(false);
$pdf->addLogbook('ETB', [$linkedRow, $unprovenRow, $zeroRow, $ownEntryRow]);
$document = $pdf->Output('', 'S');
$assert(
    substr_count($document, 'Nachricht: TBB-Nachweis 17') === 1,
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'der gedruckte Bogen zeigt den Nachrichtenbezug nicht genau einmal '
            . 'in einer Zeile'
    )
);
$assert(
    !str_contains($document, '987654321')
        && !str_contains($document, '987654322'),
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'der gedruckte Bogen enthält den globalen Nachrichtenschlüssel'
    )
);

// Die Nummer steht nicht am ETB-Eintrag, sondern am TBB-Nachweis der
// Nachricht; ohne diesen Bezug in der Abfrage bliebe der Ausdruck leer.
$export = file_get_contents($root . '/app/incident_export.php');
$assert(
    is_string($export),
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'die Quelle des Einsatzexports ist nicht lesbar'
    )
);
$export = (string) $export;
$selectStart = strpos($export, "'SELECT entry_row.`etb_lfd-nr`");
$selectEnd = strpos(
    $export,
    'FROM `nv_etb` AS entry_row',
    $selectStart === false ? 0 : $selectStart
);
$assert(
    $selectStart !== false && $selectEnd !== false && $selectEnd > $selectStart,
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'die ETB-Abfrage des Einsatzexports ist nicht auffindbar'
    )
);
$etbSelect = substr(
    $export,
    (int) $selectStart,
    (int) $selectEnd - (int) $selectStart
);
$assert(
    str_contains($etbSelect, 'AS `estab_message_ttb_lfd`')
        && str_contains($etbSelect, 'FROM `nv_tbb` AS ttb_row')
        && str_contains($etbSelect, 'ttb_row.`estab_message_id` =')
        && str_contains(
            $etbSelect,
            "BINARY ttb_row.`estab_entry_type` = BINARY 'nachricht'"
        )
        && str_contains($etbSelect, 'ttb_row.`tbb_lfd-nr` LIMIT 1)'),
    estab_dv_requirement(
        'ETB-FBFUE2-NACHRICHTENBEZUG',
        'die ETB-Abfrage lädt die am Vordruck sichtbare Nummer nicht '
            . 'eindeutig aus dem TBB-Nachweis der Nachricht'
    )
);

echo 'Fb Fue 2 message reference: OK (' . $assertions . " assertions)\n";
