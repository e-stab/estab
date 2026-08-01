<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function estab_pdf_test_message_fixture(): array
{
    $fields = [
        '00_lfd', 'einsatz_id', '01_datum', '01_medium', '01_zeichen',
        '02_zeichen', '02_zeit', '03_datum', '03_zeichen', '04_nummer',
        '04_richtung', '05_gegenstelle', '06_befweg', '06_befwegausw',
        '07_durchspruch', '08_befhinwausw', '08_befhinweis',
        '09_vorrangstufe', '10_anschrift', '11_rufnummer',
        '11_gesprnotiz', '12_abfzeit', '12_anhang', '12_betreff',
        '12_inhalt', '13_abseinheit',
        '14_funktion', '14_zeichen', '15_quitdatum', '15_quitzeichen',
        '16_empf', '17_vermerke', '99_lstacc', 'x00_status',
        'x01_abschluss', 'x04_druck', 'x05_druck_d',
    ];
    return array_replace(array_fill_keys($fields, ''), [
        '00_lfd' => 1,
        'einsatz_id' => 1,
        '01_medium' => 'Funk',
        '01_datum' => '2026-07-29 08:10:00',
        '01_zeichen' => 'e2e001',
        '04_nummer' => 7,
        '04_richtung' => 'A',
        '05_gegenstelle' => 'Leitstelle',
        '06_befweg' => 'Funk',
        '06_befwegausw' => 'Fu',
        '07_durchspruch' => 'D',
        '08_befhinweis' => 'Sofort weiterleiten',
        '08_befhinwausw' => 'sofort',
        '09_vorrangstufe' => 'aaa',
        '10_anschrift' => 'Integrationsempfänger',
        '11_rufnummer' => '0711 123456',
        '11_gesprnotiz' => 't',
        '12_abfzeit' => '2026-07-29 08:15:00',
        '12_anhang' => 'Render-Anlage.txt;',
        '12_betreff' => 'Lagebetreff Nord',
        '12_inhalt' => 'eStab PDF Funktionsnachweis',
        '13_abseinheit' => 'Einsatzleitung',
        '14_funktion' => 'S1',
        '14_zeichen' => 'e2e001',
        '16_empf' => 'S2_rt,ALT_1_gn,ALT2_rt,A/W_gn,',
        '17_vermerke' => 'Vorlage und Raster vollständig',
        'x00_status' => 8,
        'x01_abschluss' => 't',
        'x04_druck' => 'f',
    ]);
}

/** @return array<int,array<int,array{fkt:string}>> */
function estab_pdf_test_recipient_matrix(): array
{
    $functions = [
        'LS', 'S1', 'S2', 'S3',
        'S4', 'S5', 'S6', 'POL',
        'THW', 'SAN', '', '',
        '', '', '', '',
        '', '', '', '',
    ];
    $matrix = [];
    $functionIndex = 0;
    for ($row = 1; $row <= 5; $row++) {
        for ($column = 1; $column <= 4; $column++) {
            $matrix[$row][$column] = [
                'fkt' => $functions[$functionIndex++],
            ];
        }
    }
    return $matrix;
}
