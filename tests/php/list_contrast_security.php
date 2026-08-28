<?php

declare(strict_types=1);

/**
 * Every message row must stay readable.
 *
 * The recipient lists paint each row with the colour of the carbon copy that
 * reaches the reading function. Those colours are light pastels and the
 * fallback is plain white, so a fixed white foreground made every row without
 * an own copy invisible. The foreground is now derived from the background,
 * and this test holds it to the WCAG AA contrast ratio for bold body text
 * against the colours the application is actually configured with.
 */

$root = dirname(__DIR__, 2);
require_once __DIR__ . '/lib/farbe.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// The colour arithmetic and the copy-palette helpers both live in
// 4fach/tools.php, which cannot be required here -- it drags in relative
// includes, authentication and a session. The trick to reach them lives in
// tests/php/lib/farbe.php now, so this test states what it needs instead of
// carrying its own brace counter.
foreach ([
    'estab_recipient_copy_colours',
    'estab_recipient_copy_background',
    'estab_recipient_copy_ink',
] as $functionName) {
    estab_test_funktion_uebernehmen($root . '/4fach/tools.php', $functionName);
}
estab_test_farbrechnung_laden();

// The configured palette is the thing under test, not a fixture.
$cfg = [];
require $root . '/4fcfg/para.inc.php';
$assert(
    isset($cfg['lbg'], $cfg['vbg']) && is_array($cfg['lbg']) && is_array($cfg['vbg']),
    'The configuration no longer defines the carbon-copy palettes'
);

$copyKeys = ['rt', 'gn', 'bl', 'ge', 'gb'];
$combinations = [''];
foreach ($copyKeys as $single) {
    $combinations[] = $single;
}
$combinations[] = 'rt,bl';
$combinations[] = 'rt,gn,bl,ge';
$combinations[] = 'gn,ge';

// WCAG AA for bold text at this size; the rows are rendered in bold.
$minimumRatio = 4.5;

foreach (['lbg', 'vbg'] as $paletteName) {
    $palette = $cfg[$paletteName];
    $default = (string) ($palette['dflt'] ?? 'rgb(255, 255, 255)');
    foreach ($combinations as $combination) {
        $ink = estab_recipient_copy_ink($combination, $palette, $default);
        $assert(
            preg_match('~\A#[0-9a-f]{6}\z~D', $ink) === 1,
            'Ink for palette ' . $paletteName . ' combination "' . $combination
                . '" is not a colour: ' . $ink
        );
        $inkChannels = estab_colour_channels($ink);
        $assert(
            $inkChannels !== null,
            'Ink for combination "' . $combination . '" cannot be parsed'
        );
        $inkLuminance = estab_colour_relative_luminance($inkChannels);

        // Check the ink against every colour the row can actually show.
        $resolved = [];
        foreach (estab_recipient_copy_colours($combination) as $colour) {
            $lookup = $colour === 'gb' ? 'ge' : $colour;
            if (isset($palette[$lookup])) {
                $resolved[] = (string) $palette[$lookup];
            }
        }
        if ($resolved === []) {
            $resolved[] = $default;
        }
        foreach ($resolved as $background) {
            $backgroundChannels = estab_colour_channels($background);
            $assert(
                $backgroundChannels !== null,
                'Configured colour cannot be parsed: ' . $background
            );
            $ratio = estab_colour_contrast_ratio(
                $inkLuminance,
                estab_colour_relative_luminance($backgroundChannels)
            );
            $assert(
                $ratio >= $minimumRatio,
                sprintf(
                    'Row text %s on %s reaches only %.2f:1 in palette %s'
                        . ' (combination "%s"); %.1f:1 is required',
                    $ink,
                    $background,
                    $ratio,
                    $paletteName,
                    $combination,
                    $minimumRatio
                )
            );
        }
    }
}

// A dark palette must flip the ink; otherwise the calculation is a constant.
$darkPalette = ['bl' => '#101820', 'dflt' => '#0b0f14'];
$assert(
    estab_recipient_copy_ink('bl', $darkPalette, $darkPalette['dflt']) === '#ffffff'
        && estab_recipient_copy_ink('', $darkPalette, $darkPalette['dflt']) === '#ffffff',
    'The foreground does not follow a dark background and is therefore fixed'
);
$assert(
    estab_recipient_copy_ink('bl', ['bl' => 'papayawhip'], '#ffffff') === '#000000',
    'An unparsable colour does not fall back to dark ink'
);

// The row that takes its background from the carbon copy must take its
// foreground from the same colour, not from a constant.
$listSource = file_get_contents($root . '/4fach/liste.php');
if (!is_string($listSource)) {
    throw new RuntimeException('Could not read 4fach/liste.php');
}
$assert(
    substr_count($listSource, 'estab_recipient_copy_background (')
        === substr_count($listSource, 'estab_recipient_copy_ink ('),
    'A copy-coloured background is painted without a matching readable ink'
);

/*
 * Eine berechnete Flaeche braucht eine berechnete Schrift.
 *
 * Eine feste Farbe neben einer berechneten Flaeche ist genau der Fehler, der
 * Zeilen ohne eigene Durchschrift weiss auf weiss darstellte.
 *
 * Geprueft wird an der Farbe, nicht an der Ausgabeform: Seit der Umstellung
 * auf das Tabellenbauteil gibt die Liste "Stab lesen" ihr style-Attribut
 * zurueck, statt ein <tr> auszugeben. Die Regel gilt beidem.
 */
$styleStatements = 0;
preg_match_all(
    '~(?:echo|return)\s+"[^\n]*style=.*?;\n~s',
    $listSource,
    $statements,
    PREG_SET_ORDER
);
foreach ($statements as $statement) {
    $text = $statement[0];
    if (!str_contains($text, 'background')) {
        continue;
    }
    // Eine Flaeche aus einer festen Farbe darf eine feste Schrift haben --
    // sie ist nachrechenbar. Eine berechnete darf es nicht.
    $festeFlaeche = preg_match('~background(?:-color)?:\s*(?:\#|rgb\()~i', $text) === 1;
    if ($festeFlaeche) {
        continue;
    }
    $styleStatements++;
    $assert(
        preg_match('~color:\s*(?:\#|rgb\()~i', $text) !== 1,
        'A computed background is combined with a fixed foreground colour: '
            . trim(preg_replace('~\s+~', ' ', $text) ?? '')
    );
}
$assert(
    $styleStatements >= 1,
    'The copy-coloured row is no longer being checked for a readable foreground'
);

/*
 * Und die beiden Werte werden gemeinsam berechnet. Wer nur die Flaeche
 * bestimmt und die Schrift stehenlaesst, bekommt genau den Fehler, den die
 * Pruefung oben nicht mehr sehen kann -- weil er dann gar nicht mehr in
 * derselben Anweisung steht.
 */
preg_match_all(
    '~estab_recipient_copy_background\s*\(~',
    $listSource,
    $flaechen,
    PREG_OFFSET_CAPTURE
);
$assert(
    $flaechen[0] !== [],
    'The list no longer computes a recipient copy background at all'
);
foreach ($flaechen[0] as [$_treffer, $stelle]) {
    $umfeld = substr($listSource, $stelle, 1200);
    $assert(
        str_contains($umfeld, 'estab_recipient_copy_ink'),
        'A recipient copy background is computed without its ink nearby'
    );
}

// Rows painted with two fixed colours must clear the same contrast bar.
$fixedPairs = 0;
preg_match_all(
    '~background(?:-color)?:\s*(rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,'
        . '\s*\d{1,3}\s*\)|\#[0-9a-fA-F]{3,6})\s*;\s*color:\s*'
        . '(rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)'
        . '|\#[0-9a-fA-F]{3,6})~',
    $listSource,
    $pairs,
    PREG_SET_ORDER
);
foreach ($pairs as $pair) {
    $backgroundChannels = estab_colour_channels($pair[1]);
    $foregroundChannels = estab_colour_channels($pair[2]);
    $assert(
        $backgroundChannels !== null && $foregroundChannels !== null,
        'A fixed row colour pair cannot be parsed: ' . $pair[0]
    );
    $ratio = estab_colour_contrast_ratio(
        estab_colour_relative_luminance($foregroundChannels),
        estab_colour_relative_luminance($backgroundChannels)
    );
    $assert(
        $ratio >= $minimumRatio,
        sprintf(
            'Fixed row colours %s on %s reach only %.2f:1; %.1f:1 is required',
            $pair[2],
            $pair[1],
            $ratio,
            $minimumRatio
        )
    );
    $fixedPairs++;
}
$assert(
    $fixedPairs >= 2,
    'The fixed colour pairs of the list are no longer being checked'
);

printf(
    "list contrast: OK (%d assertions, %d fixed pairs)\n",
    $assertions,
    $fixedPairs
);
