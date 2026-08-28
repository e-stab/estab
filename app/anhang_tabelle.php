<?php

declare(strict_types=1);

/**
 * Die Liste der Anhänge -- aus dem Tabellenbauteil.
 *
 * > „Die Anhänge Seite ist auch nicht angepasst. Hier bräuchte es auch eine
 * > Tabelle in der man suchen kann."
 *
 * Sie hatte ihre eigene Gestaltung -- `border="1" bgcolor="#E0E0E0"` --, keine
 * Suche, keine Sortierung und keine Seitenaufteilung. Wer eine Datei suchte,
 * las die ganze Liste.
 *
 * Zwei Spalten tragen Bedienelemente und werden deshalb von hier gebaut: das
 * Auswahlkästchen und die Vorschau. Alles andere sind Werte, und das Bauteil
 * maskiert sie selbst.
 *
 * Die Kästchen gehören zur Hochladeform, in der die Liste nicht mehr steht.
 * Sie hängen über `form="uploadform"` an ihr -- dasselbe Mittel, mit dem das
 * Bauteil seine Spaltenmasken an sein Suchband bindet.
 */

require_once __DIR__ . '/tabelle.php';
require_once __DIR__ . '/attachment.php';
require_once __DIR__ . '/file_access.php';

/**
 * Eine Anhangzeile für die Tabelle vorbereiten, oder null.
 *
 * Null heisst: Der Datensatz hat einen Namen oder eine Endung, die diese
 * Anwendung nicht ausliefert. Er wird ausgelassen, nicht angezeigt -- eine
 * Zeile, deren Ziel nicht gebaut werden kann, ist keine Zeile.
 *
 * @param array<string,mixed> $datei
 * @return array<string,string>|null
 */
function estab_anhang_zeile(array $datei, array $conf_4f, int $nummer): ?array
{
    try {
        $gespeichert = estab_attachment_validate_reservation_name(
            (string) ($datei['filename'] ?? '')
        );
    } catch (InvalidArgumentException) {
        return null;
    }
    $endung = strtolower((string) ($datei['fileext'] ?? ''));
    if (preg_match('/\A[a-z0-9]{1,16}\z/D', $endung) !== 1
        || !estab_attachment_extension_is_allowed($endung)) {
        return null;
    }
    $wert = $gespeichert . '.' . $endung;
    try {
        $adresse = estab_file_download_url(
            (string) $conf_4f['download_uri'],
            'attachment',
            $wert
        );
    } catch (InvalidArgumentException) {
        return null;
    }
    $istMail = $endung === 'eml';
    $mailAdresse = dirname((string) $conf_4f['download_uri']) . '/email.php?'
        . http_build_query(['file' => $wert], '', '&', PHP_QUERY_RFC3986);
    $ursprung = basename(str_replace(
        '\\',
        '/',
        trim((string) ($datei['org_filename'] ?? $wert))
    ));
    return [
        // Die laufende Nummer stammt aus der vollstaendigen Liste, nicht aus
        // der angezeigten Seite: Ein Kaestchen darf seinen Namen nicht
        // aendern, wenn jemand sortiert.
        'nummer' => (string) $nummer,
        'wert' => $wert,
        'adresse' => $adresse,
        'anzeige' => $istMail ? $mailAdresse : $adresse,
        'mailadresse' => $mailAdresse,
        'istmail' => $istMail ? '1' : '',
        'dateiname' => $gespeichert,
        'bemerkung' => (string) ($datei['comment'] ?? ''),
        'ursprung' => $ursprung === '' ? $wert : $ursprung,
        'zeit' => (string) ($datei['date'] ?? ''),
    ];
}

/**
 * Die Tabelle der Anhänge.
 *
 * @param list<array<string,mixed>> $dateien
 */
function estab_anhang_tabelle(
    array $dateien,
    bool $mitAuswahl,
    array $conf_4f,
    string $conf_urlroot,
    array $conf_web
): string {
    $zeilen = [];
    $nummer = 0;
    foreach ($dateien as $datei) {
        $zeile = estab_anhang_zeile((array) $datei, $conf_4f, $nummer);
        if ($zeile === null) {
            continue;
        }
        $zeilen[] = $zeile;
        $nummer++;
    }

    $spalten = [];
    if ($mitAuswahl) {
        $spalten[] = [
            'schluessel' => 'wert', 'kopf' => 'Auswahl', 'breite' => 7,
            'sortierbar' => false, 'suchbar' => false, 'art' => 'text',
            'zelle' => static fn (array $z): string =>
                '<input type="checkbox" form="uploadform" name="lfd_'
                    . estab_attachment_html($z['nummer']) . '" value="'
                    . estab_attachment_html($z['wert']) . '" aria-label="'
                    . estab_attachment_html($z['ursprung'] . ' übernehmen')
                    . '">',
        ];
    }
    $spalten[] = [
        'schluessel' => 'wert', 'kopf' => 'Vorschau', 'breite' => 16,
        'sortierbar' => false, 'suchbar' => false, 'art' => 'text',
        'zelle' => static function (array $z) use ($conf_urlroot, $conf_web): string {
            if ($z['istmail'] !== '') {
                return '<div data-estab-email-attachment>'
                    . '<a class="estab-button estab-button-primary" href="'
                    . estab_attachment_html($z['mailadresse'])
                    . '" target="_blank" rel="noopener">E-Mail ansehen</a>'
                    . '<a href="' . estab_attachment_html($z['adresse'])
                    . '" download="' . estab_attachment_html($z['ursprung'])
                    . '">Originaldatei herunterladen</a></div>';
            }
            $vorschau = $conf_urlroot . $conf_web['pre_path']
                . '4fach/showpic.php?'
                . http_build_query(
                    ['file' => $z['wert'], 'width' => 250],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
            return '<a href="' . estab_attachment_html($z['adresse'])
                . '" target="_blank" rel="noopener">'
                . '<img class="estab-anhang-vorschau" alt="Vorschau von '
                . estab_attachment_html($z['ursprung']) . '" src="'
                . estab_attachment_html($vorschau) . '"></a>';
        },
    ];
    $spalten[] = [
        'schluessel' => 'dateiname', 'kopf' => 'Dateiname', 'breite' => 15,
        'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
        'zelle' => static fn (array $z): string =>
            '<a href="' . estab_attachment_html($z['anzeige'])
                . '" target="_blank" rel="noopener">'
                . estab_attachment_html($z['dateiname']) . '</a>',
    ];
    $spalten[] = [
        'schluessel' => 'bemerkung', 'kopf' => 'Bemerkung',
        'breite' => $mitAuswahl ? 24 : 31,
        'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
    ];
    $spalten[] = [
        'schluessel' => 'ursprung', 'kopf' => 'Ursprünglicher Dateiname',
        'breite' => 22, 'sortierbar' => true, 'suchbar' => true, 'art' => 'text',
    ];
    $spalten[] = [
        'schluessel' => 'zeit', 'kopf' => 'Datum/Zeit', 'breite' => 16,
        'sortierbar' => true, 'suchbar' => true, 'art' => 'zeit',
    ];

    return estab_tabelle_markup([
        'id' => 'anhaenge',
        'spalten' => $spalten,
        'zeilen' => $zeilen,
        'leer' => 'Keine Datei entspricht den gesetzten Filtern.',
    ]);
}
