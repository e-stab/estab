<?php

declare(strict_types=1);

/**
 * Einen Nachrichtenvordruck, nicht zwei.
 *
 * > „wenn ich bei Meldungen auf Vordruck oeffnen gehe kommt noch eine alte
 * > Ansicht vom Vordruck. Die Seite bitte aktualisieren. Bei derselben
 * > Seite funktioniert auch die Druck funktion nicht."
 *
 * Beides hatte eine Ursache: 4fueltg/ue_ltg.php trug eine zweite Klasse
 * `nachrichten4fach` mit einer eigenen, nie mitgezogenen Fassung des
 * Vordrucks -- 1222 Zeilen neben den 2300 der gepflegten. Sie zeichnete
 * `<body class="estab-tool-page">`.
 *
 * Der Druckblock des Stylesheets spricht aber ueber
 * `body.estab-message-form-body`. Auf der zweiten Fassung griff deshalb
 * keine einzige Druckregel: Der Knopf rief `window.print()`, und der
 * Browser druckte den Bildschirm samt Menuespalte statt des Papierbildes.
 *
 * Auch die Zugriffsregeln liefen auseinander -- `get_access_by_task` hatte
 * dort 121 Zeilen, hier 161. Welche Felder ein Arbeitsschritt oeffnet, ist
 * keine Frage, die zwei Antworten haben darf.
 *
 * Diese Pruefung haelt fest, dass es bei einer Fassung bleibt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';
require_once __DIR__ . '/lib/quelltext.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/**
 * Alle Stellen, die die Klasse des Nachrichtenvordrucks erklaeren.
 *
 * Gesucht wird im ganzen Baum, nicht in einer Liste bekannter Dateien.
 * Eine dritte Fassung entstuende sonst genau dort, wo niemand hinsieht.
 */
$erklaerungen = [];
$verzeichnisse = ['4fach', '4fadm', '4fueltg', 'app', 'stabetb', 'fmtbb'];
foreach ($verzeichnisse as $verzeichnis) {
    $pfad = $root . '/' . $verzeichnis;
    if (!is_dir($pfad)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS)
    ) as $datei) {
        if (!$datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }
        $quelle = file_get_contents($datei->getPathname());
        if (!is_string($quelle)) {
            continue;
        }
        $zeile = 0;
        foreach (preg_split('~\R~', $quelle) ?: [] as $text) {
            $zeile++;
            if (preg_match('~^\s*class\s+nachrichten4fach\b~', $text) === 1) {
                $erklaerungen[] = substr(
                    $datei->getPathname(),
                    strlen($root) + 1
                ) . ':' . $zeile;
            }
        }
    }
}
$assert(
    $erklaerungen === ['4fach/4fachform.php:38'],
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Der Nachrichtenvordruck wird an mehr als einer Stelle erklaert: '
            . implode(', ', $erklaerungen)
    )
);

/*
 * Und die gepflegte Fassung zeichnet den Koerper, auf den der Druckblock
 * hoert. Ohne diese zweite Haelfte pruefte die erste nur, dass es eine
 * Klasse gibt -- nicht, dass ihr Ausdruck das Papierbild trifft.
 */
$vordruck = file_get_contents($root . '/4fach/official_message_form.php');
$assert(is_string($vordruck), 'Der Vordruck ist nicht lesbar.');
$assert(
    str_contains((string) $vordruck, 'estab-message-form-body'),
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Der Vordruck zeichnet nicht mehr `estab-message-form-body`; der '
            . 'Druckblock des Stylesheets griffe damit nirgends.'
    )
);
$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$assert(
    str_contains((string) $stylesheet, 'body.estab-message-form-body'),
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Der Druckblock spricht nicht mehr ueber den Koerper des Vordrucks.'
    )
);

/*
 * Die Uebersicht baut den Vordruck ueber diese eine Klasse. Sie zieht sie
 * dafuer ausdruecklich herein -- ohne require faende PHP sie nicht, und
 * die Seite braeche erst zur Laufzeit, beim ersten "Vordruck oeffnen".
 */
$uebersicht = file_get_contents($root . '/4fueltg/ue_ltg.php');
$assert(is_string($uebersicht), 'Die Meldungsuebersicht ist nicht lesbar.');
/*
 * Ohne Kommentare geprueft.
 *
 * Beim Proben dieser Pruefung -- Anweisung entfernt, schlaegt sie an? --
 * blieb sie still: Der erklaerende Satz daneben nennt denselben Pfad, und
 * str_contains sieht keinen Unterschied zwischen Rede und Anweisung.
 */
$uebersicht = estab_test_ohne_kommentare((string) $uebersicht);
$assert(
    str_contains($uebersicht, '/../4fach/4fachform.php')
        && str_contains($uebersicht, 'new nachrichten4fach ('),
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Die Meldungsuebersicht baut den Vordruck nicht aus der gepflegten '
            . 'Klasse.'
    )
);
/*
 * Und sie zeichnet keinen Vordruck mehr selbst.
 *
 * Geprueft wird an `plot_form` -- der Methode, die die geloeschte Fassung
 * ausmachte. Nicht am Koerper `estab-tool-page`: Den zeichnet die
 * Uebersichtsseite selbst, und zwar zu Recht; sie ist eine Werkzeugseite.
 */
$assert(
    !str_contains($uebersicht, 'function plot_form'),
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Die Meldungsuebersicht zeichnet wieder einen eigenen Vordruck.'
    )
);

/*
 * Gedruckt wird der Inhalt, nicht die Bedienung.
 *
 * Der Vordruck steht in der mittleren Spalte einer dreispaltigen Huelle --
 * links das Menue, rechts das Cockpit. Beide kosten zusammen rund zwei
 * Drittel der Blattbreite und sagen auf Papier nichts. Ohne diese Regeln
 * kam aus dem Drucker ein Bildschirmfoto mit einem Vordruck darin.
 */
$druckblock = '';
$stelle = 0;
while (($stelle = strpos((string) $stylesheet, '@media print', $stelle)) !== false) {
    $offen = 0;
    $ende = strpos((string) $stylesheet, '{', $stelle);
    for ($i = (int) $ende; $i < strlen((string) $stylesheet); $i++) {
        if ($stylesheet[$i] === '{') {
            $offen++;
        } elseif ($stylesheet[$i] === '}') {
            $offen--;
            if ($offen === 0) {
                $druckblock .= substr(
                    (string) $stylesheet,
                    $stelle,
                    $i - $stelle + 1
                );
                break;
            }
        }
    }
    $stelle++;
}
$assert(
    $druckblock !== '',
    estab_ux_requirement('GES-DRUCK-OHNE-HUELLE', 'Es gibt keinen Druckblock.')
);
foreach (['.estab-shell-menu', '.estab-shell-cockpit'] as $spalte) {
    $assert(
        preg_match(
            '~' . preg_quote($spalte, '~') . '[^{}]*\{[^}]*display:\s*none~s',
            $druckblock
        ) === 1,
        estab_ux_requirement(
            'GES-DRUCK-OHNE-HUELLE',
            'Im Druck bleibt ' . $spalte . ' stehen und frisst Blattbreite.'
        )
    );
}
$assert(
    preg_match(
        '~\.estab-shell[^-{}][^{}]*\{[^}]*display:\s*block~s',
        $druckblock
    ) === 1,
    estab_ux_requirement(
        'GES-DRUCK-OHNE-HUELLE',
        'Die Huelle bleibt im Druck ein dreispaltiges Raster. Die mittlere '
            . 'Spalte behielte ihre Breite, auch wenn die beiden anderen '
            . 'verschwinden -- der Vordruck stuende auf einem Drittel Papier.'
    )
);

/*
 * Der Bearbeitungsweg steht neben dem Vordruck -- und passt dort hinein.
 *
 * Die Spalte ist rund 190 Bildpunkte breit. Die Stationen kamen aus dem
 * waagerechten Band, wo `white-space: nowrap` richtig ist: Eine Station,
 * die umbricht, macht das Band ungleich hoch. In der schmalen Spalte
 * schnitt dieselbe Regel die Angaben ab -- gemessen 349 Bildpunkte Text
 * in einem 140 Punkte breiten Kasten, zu lesen als "Bearbeitungsweg der
 * Meldun" und "Dauer an dieser Station: 0 S".
 *
 * Dass eine Angabe abgeschnitten ist, sieht man ihr nicht an: Sie sieht
 * aus wie eine kurze Angabe. Deshalb steht die Regel hier.
 */
$spaltenblock = '';
$stelle = strpos((string) $stylesheet, '@media (min-width: 70rem)');
if (is_int($stelle)) {
    $offen = 0;
    for ($i = (int) strpos((string) $stylesheet, '{', $stelle);
         $i < strlen((string) $stylesheet); $i++) {
        if ($stylesheet[$i] === '{') {
            $offen++;
        } elseif ($stylesheet[$i] === '}') {
            $offen--;
            if ($offen === 0) {
                $spaltenblock = substr(
                    (string) $stylesheet,
                    $stelle,
                    $i - $stelle + 1
                );
                break;
            }
        }
    }
}
$assert(
    $spaltenblock !== '',
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Es gibt keinen Spaltenaufbau fuer den Vordruck ab 70rem.'
    )
);
$assert(
    preg_match(
        '~timeline__station,[^{}]*timeline__time,[^{}]*'
            . 'timeline__duration\s*\{[^}]*white-space:\s*normal~s',
        $spaltenblock
    ) === 1,
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Die Stationen des Bearbeitungswegs brechen in der schmalen Spalte '
            . 'nicht um; ihre Angaben stehen damit halb ausserhalb.'
    )
);
$assert(
    preg_match(
        '~timeline__content\s*\{[^}]*grid-template-columns:\s*'
            . 'minmax\(0,\s*1fr\)~s',
        $spaltenblock
    ) === 1,
    estab_ux_requirement(
        'UX-EIN-VORDRUCK',
        'Der Inhalt einer Station steht in der schmalen Spalte weiterhin '
            . 'zweispaltig; der Zeitpunkt liegt damit ausserhalb.'
    )
);

printf(
    "Ein Vordruck: OK (%d assertions, %d Erklaerung in %d Verzeichnissen "
        . "gesucht)\n",
    $assertions,
    count($erklaerungen),
    count($verzeichnisse)
);
