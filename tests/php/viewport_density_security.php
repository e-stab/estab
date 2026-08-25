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

$toPixels = static function (string $value): ?float {
    $value = trim($value);
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

// The area navigation must stay reachable without scrolling the sidebar.
$assert(
    preg_match(
        '~\.estab-message-sidebar\s*>\s*\.estab-navigation\s*\{[^}]*position:\s*sticky~',
        $stylesheet
    ) === 1,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'The area navigation is not pinned inside the sidebar and therefore'
            . ' stays below the fold on a flat screen'
    )
);
$assert(
    preg_match(
        '~\.estab-message-sidebar\s*\{[^}]*display:\s*flex~',
        $stylesheet
    ) === 1,
    estab_ux_requirement(
        'UX-FLACHE-BILDSCHIRME',
        'The sidebar is not a flex column, so a sticky child has no'
            . ' containing block to stick within'
    )
);

printf(
    "viewport density: OK (%d assertions, %d breakpoints, %d comparisons)\n",
    $assertions,
    count($heightBlocks),
    $comparisons
);
