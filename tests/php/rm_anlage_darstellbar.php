<?php

declare(strict_types=1);

/**
 * Eine Anlage, die sich später nicht verarbeiten lässt, kommt gar nicht erst
 * herein.
 *
 * > „Das Einsatzdossier konnte nicht vollständig erstellt werden:
 * > Dateiendung und erkannter Inhaltstyp einer darstellbaren Anlage stimmen
 * > nicht überein."
 *
 * Der Satz stimmt und hilft niemandem. Ein Einsatz hat vierzig Anlagen, und
 * „eine darstellbare Anlage" ist keine davon. Wer das Dossier braucht,
 * braucht es meist sofort -- und bekam nichts, ohne zu erfahren, welche
 * Datei er hätte richtigstellen müssen.
 *
 * Drei Dinge folgen daraus, und dieser Test hält alle drei fest:
 *
 * 1. **Am Anfang prüfen.** Der Upload wendet denselben Maßstab an wie das
 *    Dossier. Was hineinkommt, lässt sich später verarbeiten. Damit
 *    entstehen die Daten nicht mehr, die zu der Meldung führen.
 * 2. **Nicht am Ganzen scheitern.** Findet sich doch eine solche Anlage --
 *    aus der Zeit vor dieser Prüfung --, bekommt sie eine Hinweisseite wie
 *    jede andere nicht darstellbare Datei. Das Dossier entsteht vollständig;
 *    die Anlage liegt bytegleich darin, nur nicht abgebildet.
 * 3. **Im Klartext sagen, woran es liegt.** Name, Endung, erkannter Inhalt
 *    und die dazu passende Erwartung.
 *
 * Die Sicherheitsaussage bleibt unberührt: Eine Datei, deren Name etwas
 * anderes ankündigt als ihr Inhalt, wird **nicht dargestellt**. Sie wird nur
 * nicht mehr zum Anlass genommen, das ganze Dossier zu verweigern.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/anlage_darstellbar.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/* --- Der Befund --- */

foreach ([
    ['jpg', 'image/jpeg', ESTAB_ANLAGE_DARSTELLBAR],
    ['jpeg', 'image/jpeg', ESTAB_ANLAGE_DARSTELLBAR],
    ['png', 'image/png', ESTAB_ANLAGE_DARSTELLBAR],
    ['bmp', 'image/x-ms-bmp', ESTAB_ANLAGE_DARSTELLBAR],
    ['pdf', 'application/pdf', ESTAB_ANLAGE_DARSTELLBAR],
    ['txt', 'text/plain', ESTAB_ANLAGE_DARSTELLBAR],
    ['eml', 'message/rfc822', ESTAB_ANLAGE_DARSTELLBAR],
    // Weder Endung noch Inhalt sind darstellbar: eine Hinweisseite, kein
    // Widerspruch. Ein Archiv mit Archivbytes ist in Ordnung.
    ['zip', 'application/zip', ESTAB_ANLAGE_HINWEIS],
    ['doc', 'application/msword', ESTAB_ANLAGE_HINWEIS],
    ['avi', 'video/x-msvideo', ESTAB_ANLAGE_HINWEIS],
    ['xia', 'application/octet-stream', ESTAB_ANLAGE_HINWEIS],
    // Der gefaehrliche Fall, aus beiden Richtungen.
    ['pdf', 'text/plain', ESTAB_ANLAGE_WIDERSPRUCH],
    ['jpg', 'application/zip', ESTAB_ANLAGE_WIDERSPRUCH],
    ['eml', 'text/plain', ESTAB_ANLAGE_WIDERSPRUCH],
    ['txt', 'application/pdf', ESTAB_ANLAGE_WIDERSPRUCH],
    ['zip', 'image/jpeg', ESTAB_ANLAGE_WIDERSPRUCH],
    ['odt', 'application/pdf', ESTAB_ANLAGE_WIDERSPRUCH],
] as [$endung, $typ, $erwartet]) {
    $befund = estab_anlage_befund($endung, $typ);
    $assert(
        $befund === $erwartet,
        'Eine Datei .' . $endung . ' mit dem Inhalt ' . $typ . ' gilt als '
            . $befund . ' statt als ' . $erwartet . '.'
    );
}

// Gross- und Kleinschreibung und ein fuehrender Punkt aendern nichts.
$assert(
    estab_anlage_befund('.PDF', 'APPLICATION/PDF') === ESTAB_ANLAGE_DARSTELLBAR,
    'Die Prüfung unterscheidet Gross- und Kleinschreibung oder stolpert über '
        . 'einen führenden Punkt.'
);

/* --- Der Satz nennt Ross und Reiter --- */

$satz = estab_anlage_widerspruch_satz('Lagekarte-Nord.pdf', 'pdf', 'text/plain');
foreach ([
    'Lagekarte-Nord.pdf' => 'den Namen der Anlage',
    '.pdf' => 'ihre Endung',
    'text/plain' => 'den erkannten Inhalt',
    'application/pdf' => 'den zur Endung gehörenden Inhalt',
] as $teil => $was) {
    $assert(
        str_contains($satz, $teil),
        'Die Meldung zum Widerspruch nennt nicht ' . $was . ': ' . $satz
    );
}
// Auch aus der anderen Richtung: Endung unauffaellig, Inhalt darstellbar.
$satz = estab_anlage_widerspruch_satz('Bericht.zip', 'zip', 'image/jpeg');
$assert(
    str_contains($satz, 'Bericht.zip') && str_contains($satz, 'image/jpeg')
        && (str_contains($satz, 'jpg') || str_contains($satz, 'jpeg')),
    'Die Meldung nennt nicht, welche Endung zu dem erkannten Inhalt gehört: '
        . $satz
);

/* --- Der Upload wendet denselben Massstab an --- */

$upload = file_get_contents($root . '/app/attachment_upload.php');
$assert(is_string($upload), 'Der Upload-Dienst ist nicht lesbar.');
$upload = (string) $upload;
$assert(
    str_contains($upload, 'estab_anlage_befund('),
    'Der Upload prüft nicht, ob Endung und Inhalt zusammenpassen. Eine Datei '
        . 'mit diesem Widerspruch käme herein, läge monatelang im Einsatz und '
        . 'fiele erst auf, wenn das Dossier gebraucht wird.'
);
$assert(
    str_contains($upload, 'estab_anlage_widerspruch_satz('),
    'Der Upload weist einen Widerspruch ohne den erklärenden Satz ab. Wer '
        . 'nicht erfährt, was nicht zusammenpasst, lädt dieselbe Datei noch '
        . 'einmal hoch.'
);

/* --- Und das Dossier scheitert nicht mehr am Ganzen --- */

$dossier = file_get_contents($root . '/app/incident_pdf.php');
$assert(is_string($dossier), 'Das Dossier ist nicht lesbar.');
$dossier = (string) $dossier;
$assert(
    !str_contains(
        $dossier,
        'Dateiendung und erkannter Inhaltstyp einer darstellbaren Anlage '
            . 'stimmen nicht überein.'
    )
    && !str_contains(
        $dossier,
        'Dateiendung und erkannter Inhaltstyp einer Bildanlage stimmen nicht überein.'
    ),
    'Das Dossier bricht wegen einer einzigen widersprüchlichen Anlage noch '
        . 'immer ganz ab. Wer es braucht, braucht es meist sofort.'
);
$assert(
    str_contains($dossier, 'estab_anlage_widerspruch_satz('),
    'Die Hinweisseite einer widersprüchlichen Anlage nennt den Grund nicht '
        . 'im Klartext.'
);

/* --- Und die Exportseite verdeckt ihren eigenen Hinweis nicht --- */

/*
 * Der Abschnitt „Umfang festlegen" ist zweispaltig angelegt: links der
 * Hinweis, rechts die Schaltfläche. Die Einsatzdossier-Seite setzt dort
 * ein ganzes Formular hinein und schaltet deshalb auf **eine** Spalte um.
 *
 * Beide Regeln hatten dieselbe Spezifität, und die einspaltige stand
 * **früher** in der Datei -- also gewann die zweispaltige. Die rechte Spalte
 * ist `auto` und nahm sich die Breite des Formulars; für den Hinweis links
 * blieben rund fünfzig Bildpunkte, und das Formular lag über seinem Text.
 *
 * Die Variante trägt jetzt beide Klassen und gewinnt unabhängig von der
 * Reihenfolge. Eine Regel, die nur gewinnt, weil sie weiter unten steht, ist
 * eine Regel auf Widerruf.
 */
$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'Das Stylesheet ist nicht lesbar.');
$stylesheet = (string) $stylesheet;
$assert(
    str_contains(
        $stylesheet,
        '.estab-export-create.estab-incident-export-panel {'
    ),
    'Die einspaltige Fassung des Abschnitts „Umfang festlegen" trägt nicht '
        . 'beide Klassen. Sie hat damit dieselbe Spezifität wie die '
        . 'zweispaltige Grundregel und verliert gegen sie, weil die weiter '
        . 'unten steht -- der Hinweistext wird vom Formular überdeckt.'
);
$variante = strpos($stylesheet, '.estab-export-create.estab-incident-export-panel {');
$grund = strpos($stylesheet, "\n.estab-export-create {");
$assert(
    is_int($variante) && is_int($grund),
    'Grundregel oder Variante des Abschnitts sind nicht auffindbar.'
);

printf(
    "Anlage darstellbar: OK (%d assertions, %d Endungen darstellbar)\n",
    $assertions,
    count(estab_anlage_darstellbare_paare())
);
