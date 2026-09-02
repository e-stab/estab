<?php

declare(strict_types=1);

/**
 * The application must adapt to flat screens, not only to narrow ones.
 *
 * A command post works on laptops where 768 physical pixels leave roughly 600
 * usable ones. The stylesheet knew width-based queries only, so a flat screen
 * paid the same spacing as a tall one and the area navigation sat permanently
 * below the fold. This test proves that height-based rules exist, that they
 * actually shrink the values they override, and that the navigation stays
 * reachable.
 *
 * Die Prüfung bestand bereits, bevor der Bedienkatalog sie benannte. Sie
 * trägt jetzt die Kennung UX-FLACHE-BILDSCHIRME, damit ein Leser des
 * Katalogs sie findet und ein Entfernen der Regel auffällt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Could not read estab-ui.css');
}

/** Split a stylesheet region into selector => declarations. */
$parseRules = static function (string $region): array {
    $rules = [];
    preg_match_all('~([^{}]+)\{([^{}]*)\}~', $region, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $selectors = array_map('trim', explode(',', trim($match[1])));
        $declarations = [];
        foreach (explode(';', $match[2]) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $declarations[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        foreach ($selectors as $selector) {
            if ($selector === '') {
                continue;
            }
            $rules[$selector] = array_merge(
                $rules[$selector] ?? [],
                $declarations
            );
        }
    }
    return $rules;
};

/** Collect the body of every `@media (max-height: ...)` block. */
$heightBlocks = [];
$offset = 0;
while (
    ($start = strpos($stylesheet, '@media (max-height:', $offset)) !== false
) {
    $braceStart = strpos($stylesheet, '{', $start);
    if ($braceStart === false) {
        break;
    }
    $depth = 0;
    $length = strlen($stylesheet);
    $end = $braceStart;
    for ($index = $braceStart; $index < $length; $index++) {
        if ($stylesheet[$index] === '{') {
            $depth++;
        } elseif ($stylesheet[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $index;
                break;
            }
        }
    }
    $header = substr($stylesheet, $start, $braceStart - $start);
    if (preg_match('~max-height:\s*([\d.]+)(rem|px)~', $header, $limit) === 1) {
        $pixels = $limit[2] === 'rem'
            ? (float) $limit[1] * 16
            : (float) $limit[1];
        $heightBlocks[] = [
            'limit' => $pixels,
            'body' => substr($stylesheet, $braceStart + 1, $end - $braceStart - 1),
        ];
    }
    $offset = $end + 1;
}

$assert(
    count($heightBlocks) >= 2,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'The stylesheet has fewer than two height-based breakpoints, so a flat'
            . ' screen is not treated differently from a tall one'
    )
);

// One breakpoint must actually cover the 768-pixel laptop after browser chrome.
$smallest = min(array_column($heightBlocks, 'limit'));
$assert(
    $smallest <= 740.0,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        sprintf(
            'The lowest height breakpoint is %.0f px and therefore never'
                . ' applies on a 768-pixel laptop',
            $smallest
        )
    )
);

$baseRules = $parseRules(
    preg_replace('~@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}~', '', $stylesheet)
    ?? ''
);

/*
 * Eine Marke ist eine Laenge.
 *
 * Dieser Vergleich las nur rohe Laengen. Als die Masse auf Marken umgestellt
 * wurden, konnte er keinen einzigen Wert mehr aufloesen und uebersprang jeden
 * Vergleich -- die Verdichtung waere ungeprueft geblieben, ohne dass es
 * jemand bemerkt. Ein var()-Aufruf wird deshalb aufgeloest, bevor gerechnet
 * wird. Die Dichtestufe setzt einige Marken selbst um; der erste Wert ist
 * der Regelfall und der, gegen den verglichen wird.
 */
require_once __DIR__ . '/lib/stylesheet.php';
$marken = estab_test_css_marken($stylesheet);

$toPixels = static function (string $value) use ($marken): ?float {
    $value = trim($value);
    if (preg_match('~\Avar\(\s*(--[a-z0-9-]+)\s*\)\z~', $value, $treffer) === 1) {
        $value = $marken[$treffer[1]] ?? '';
    }
    if (preg_match('~\A([\d.]+)rem\z~', $value, $match) === 1) {
        return (float) $match[1] * 16;
    }
    if (preg_match('~\A([\d.]+)px\z~', $value, $match) === 1) {
        return (float) $match[1];
    }
    if (preg_match('~\A([\d.]+)\z~', $value, $match) === 1) {
        return (float) $match[1];
    }
    return null;
};

// Compaction must compact: every overridden length has to get smaller.
$shrinkingProperties = [
    'padding', 'padding-top', 'padding-bottom', 'gap', 'row-gap',
    'min-height', 'font-size', 'margin', 'margin-top', 'margin-bottom',
];
$comparisons = 0;
foreach ($heightBlocks as $block) {
    foreach ($parseRules($block['body']) as $selector => $declarations) {
        $base = $baseRules[$selector] ?? null;
        if ($base === null) {
            continue;
        }
        foreach ($declarations as $property => $value) {
            if (!in_array($property, $shrinkingProperties, true)) {
                continue;
            }
            if (!isset($base[$property])) {
                continue;
            }
            // Compare only the first length so shorthands stay comparable.
            $compactFirst = $toPixels(explode(' ', $value)[0]);
            $baseFirst = $toPixels(explode(' ', $base[$property])[0]);
            if ($compactFirst === null || $baseFirst === null) {
                continue;
            }
            $comparisons++;
            $assert(
                $compactFirst <= $baseFirst,
                estab_ux_requirement(
                    'UX-FLACHE-BILDSCHIRME',
                    sprintf(
                        'At most %.0f px height, %s { %s } grows from %s to'
                            . ' %s instead of shrinking',
                        $block['limit'],
                        $selector,
                        $property,
                        $base[$property],
                        $value
                    )
                )
            );
        }
    }
}
$assert(
    $comparisons >= 5,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'The height-based rules override almost nothing measurable'
    )
);

/*
 * Die Bereichsnavigation muss auf einem flachen Bildschirm erreichbar
 * bleiben. Frueher klebte sie dafuer mit `position: sticky` am unteren Rand
 * einer mitscrollenden Seitenleiste -- und legte sich dabei ueber ihre
 * Nachbarn.
 *
 * Die Huelle loest das anders und besser: Das Menue hat eine eigene Spalte
 * mit eigenem Scrollbereich, und die Navigation steht darin oben. Sie ist
 * damit ohne jedes Scrollen erreichbar, unabhaengig davon, wie hoch der
 * Inhalt daneben ist. Geprueft wird deshalb die Spalte, nicht der Klebstoff.
 */
$assert(
    preg_match(
        '~\.estab-shell\s*\{[^}]*grid-template-columns:~s',
        $stylesheet
    ) === 1,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'Die Huelle teilt den Bildschirm nicht in feste Spalten; das Menue'
            . ' haette dann keinen eigenen Platz'
    )
);
$assert(
    preg_match(
        '~\.estab-shell-menu,\s*\.estab-shell-content,\s*'
            . '\.estab-shell-cockpit\s*\{[^}]*overflow-y:\s*auto~s',
        $stylesheet
    ) === 1,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'Die drei Spalten scrollen nicht fuer sich. Dann scrollt wieder alles'
            . ' gemeinsam, und die Navigation rutscht unter den Rand'
    )
);
$assert(
    preg_match(
        '~\.estab-shell\s*\{[^}]*height:\s*100dvh~s',
        $stylesheet
    ) === 1,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'Die Huelle nimmt nicht die Hoehe des Fensters ein; die Spalten'
            . ' haetten dann keinen eigenen Scrollbereich'
    )
);
// Und die Navigation klebt nirgends mehr -- was nicht klebt, ueberlagert
// auch nichts.
$assert(
    preg_match(
        '~\.estab-shell-menu\s*>\s*\.estab-navigation\s*\{[^}]*'
            . 'position:\s*static~s',
        $stylesheet
    ) === 1,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'Die Navigation klebt in der Menuespalte. In einem eigenen'
            . ' Scrollbereich braucht sie das nicht, und Klebstoff war der'
            . ' Grund, aus dem sich Elemente beim Scrollen ueberlagerten'
    )
);

printf(
    "viewport density: OK (%d assertions, %d breakpoints, %d comparisons)\n",
    $assertions,
    count($heightBlocks),
    $comparisons
);
