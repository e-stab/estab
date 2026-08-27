<?php

declare(strict_types=1);

/**
 * Lesbar bleiben, auch auf dem blauen Vordruck.
 *
 * Der amtliche Vordruck hat einen farbigen Grund -- das ist sein Bild, und
 * das Bild bleibt. Auf einem farbigen Grund ist Lesbarkeit aber keine
 * Selbstverständlichkeit: Ein Grauton, der auf Weiß gut aussieht, wird auf
 * Hellblau zur Andeutung, und in einer Führungsstelle wird bei
 * Schreibtischlampe und schräg stehendem Laptop gelesen.
 *
 * Für die Nachrichtenlisten war der Kontrast bereits geprüft; für den
 * Vordruck nicht. Diese Prüfung schliesst die Lücke. Sie misst jede
 * Vorder-/Hintergrundpaarung des Vordrucks gegen das AA-Verhältnis der
 * WCAG. Seit der Betreiber UX-KONTRAST auf zwei Stufen gehoben hat, ist
 * der Sollwert 7:1; 4,5:1 bleibt das absolute Minimum, das nie
 * unterschritten wird.
 *
 * Der Zusammenhang, auf welchem Grund eine Farbe steht, ist im Stylesheet
 * nicht ablesbar: Ein Feld ist durchsichtig und liegt auf dem Vordruck, der
 * Hilfedialog liegt auf Weiss. Diese Zuordnung steht deshalb hier im Test,
 * als Feststellung dessen, was die Oberfläche tut -- und die Prüfung
 * verlangt, dass jede Farbe des Vordrucks darin vorkommt. Eine neue Farbe
 * ohne Zuordnung faellt auf, statt ungeprüft zu bleiben.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/farbe.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$stylesheet = file_get_contents($root . '/estab-ui.css');
if (!is_string($stylesheet)) {
    throw new RuntimeException('Das Stylesheet ist nicht lesbar.');
}
// Kommentare stehen vor der Regel, die sie erklären, und gehören sonst zum
// Auswähler dazu -- dann sucht die Prüfung den Grund für einen Kommentar.
$rulesSource = preg_replace('~/\*.*?\*/~s', ' ', $stylesheet) ?? $stylesheet;

/*
 * Die Rechnung stand hier als Closure ein zweites Mal abgeschrieben. Sie
 * steht jetzt einmal in tests/php/lib/farbe.php und kommt von dort -- aus
 * derselben Quelle wie im Betrieb, statt aus einer Kopie, die auseinander-
 * laufen kann. Die Namen bleiben, damit die Paarungen unten unveraendert
 * lesbar sind.
 */
$luminance = static fn (string $colour): float
    => estab_test_helligkeit($colour);
$contrast = static fn (string $front, string $back): float
    => estab_test_kontrast($front, $back);

$assert(
    abs($contrast('#000000', '#ffffff') - 21.0) < 0.01
        && abs($contrast('#ffffff', '#ffffff') - 1.0) < 0.01,
    estab_ux_requirement(
        'UX-KONTRAST',
        'Die Kontrastrechnung selbst stimmt nicht.'
    )
);

/* --- Der Grund, auf dem der Vordruck steht --- */

preg_match(
    '~--estab-official-blue:\s*(#[0-9a-fA-F]{3,6})~',
    $stylesheet,
    $blue
);
$assert(
    isset($blue[1]),
    estab_ux_requirement(
        'UX-KONTRAST',
        'Der Grund des Vordrucks ist nicht benannt.'
    )
);
$formBackground = $blue[1] ?? '#ffffff';

/*
 * Die Zuordnung: Auswähler-Merkmal => Grund, auf dem sein Text steht. Der
 * Vordruck ist der Regelfall; die Ausnahmen sind die Flächen, die einen
 * eigenen Grund setzen.
 */
$backgrounds = [
    'estab-official-help-dialog' => '#ffffff',
    'estab-official-help-close' => '#eef4fa',
    'estab-official-help-button:hover' => '#173a5e',
    'estab-official-help-button' => '#ffffff',
    'estab-official-field-error' => '#9d1c16',
    'estab-message-form-scroll' => '#ffffff',
    'estab-official-message-form' => $formBackground,
    'estab-official' => $formBackground,
];

/** Auf welchem Grund steht dieser Auswähler? */
$backgroundFor = static function (string $selector) use ($backgrounds): ?string {
    foreach ($backgrounds as $needle => $background) {
        if (str_contains($selector, $needle)) {
            return $background;
        }
    }
    return null;
};

/* --- Jede Farbe des Vordrucks wird gemessen --- */

/*
 * Grosser Text darf 3:1, gewöhnlicher muss 4,5:1. Als gross gilt nach WCAG
 * ab 24 Pixeln, fett ab 18,66 -- die Regeln des Vordrucks bleiben darunter,
 * deshalb wird durchweg 4,5 verlangt.
 */
// Der Sollwert der Gestaltungsspec. Das Blatt haelt ihn: Die einzige
// Paarung, die ihn verfehlte, war eine Streufarbe auf dem amtlichen Blau --
// nicht die Vorschriftfarbe selbst. Der Grund ist vorgeschrieben, die
// Schrift darauf war es nie.
$required = 7.0;
$measured = 0;

require_once __DIR__ . '/lib/stylesheet.php';
$markers = estab_test_css_definierte_marken($stylesheet);

preg_match_all('~([^{}]+)\{([^{}]*)\}~', $rulesSource, $rules, PREG_SET_ORDER);
foreach ($rules as $rule) {
    $selector = trim($rule[1]);
    if (
        !str_contains($selector, 'estab-official')
        && !str_contains($selector, 'estab-message-form')
    ) {
        continue;
    }
    $declarations = [];
    foreach (explode(';', $rule[2]) as $declaration) {
        $parts = explode(':', $declaration, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $declarations[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    // "!important" und weitere Angaben hinter der Farbe gehoeren nicht zur
    // Farbe; gelesen wird das erste Wort.
    $firstWord = static function (?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $word = strtok(trim($value), " \t");
        return $word === false ? null : $word;
    };
    /*
     * Eine Marke ist eine Farbe.
     *
     * Diese Pruefung las nur Hexliterale. Als der Vordruck auf Marken
     * umgestellt wurde, fielen ihre Paarungen von 14 auf 9 -- die
     * Umstellung haette die Deckung still verkleinert, und genau das ist
     * die Art Regression, die niemand bemerkt. Ein var()-Aufruf wird
     * deshalb aufgeloest, bevor gerechnet wird.
     */
    $aufloesen = static function (?string $value) use ($markers): ?string {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('~\Avar\(\s*(--[a-z0-9-]+)\s*\)\z~', $value, $t) === 1) {
            return $markers[$t[1]] ?? null;
        }
        return $value;
    };
    $isColour = static fn (?string $value): bool =>
        is_string($value)
        && preg_match('~\A#[0-9a-fA-F]{3,6}\z~', $value) === 1;

    $front = $aufloesen($firstWord($declarations['color'] ?? null));
    if (!$isColour($front)) {
        // "inherit" und "transparent" setzen keine eigene Farbe.
        continue;
    }
    $own = $aufloesen($firstWord(
        $declarations['background-color'] ?? $declarations['background'] ?? null
    ));
    $back = $isColour($own) ? $own : $backgroundFor($selector);
    $assert(
        $back !== null,
        estab_ux_requirement(
            'UX-KONTRAST',
            'Für „' . substr($selector, 0, 60) . '“ ist nicht bekannt, auf '
                . 'welchem Grund der Text steht; seine Lesbarkeit bliebe '
                . 'ungeprüft.'
        )
    );
    if ($back === null) {
        continue;
    }
    $ratio = $contrast($front, $back);
    $measured++;
    $assert(
        $ratio >= $required,
        estab_ux_requirement(
            'UX-KONTRAST',
            sprintf(
                'Der Text %s auf %s erreicht %.2f:1 statt %.1f:1 (%s). Bei '
                    . 'Schreibtischlampe und schräg stehendem Laptop wäre er '
                    . 'nicht mehr zu lesen.',
                $front,
                $back,
                $ratio,
                $required,
                substr($selector, 0, 50)
            )
        )
    );
}

$assert(
    $measured >= 8,
    estab_ux_requirement(
        'UX-KONTRAST',
        'Es wurden nur ' . $measured . ' Paarungen gemessen; die Prüfung '
            . 'greift zu wenig ab.'
    )
);

/* --- Und der Grund des Vordrucks trägt schwarzen Text --- */

$assert(
    $contrast('#000000', $formBackground) >= 7.0,
    estab_ux_requirement(
        'UX-KONTRAST',
        sprintf(
            'Der Vordruck erreicht mit schwarzem Text nur %.2f:1 auf seinem '
                . 'eigenen Grund; für das Hauptfeld der Arbeit ist AAA (7:1) '
                . 'die richtige Messlatte.',
            $contrast('#000000', $formBackground)
        )
    )
);

printf("Kontrast im Vordruck: OK (%d assertions, %d Paarungen)\n", $assertions, $measured);
