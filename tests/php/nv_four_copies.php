<?php

declare(strict_types=1);

/**
 * Die vier Durchschriften des Nachrichtenvordrucks.
 *
 * Auf Papier ist der Vordruck vierfach: Die weiße Ausfertigung bleibt beim
 * Fernmeldebetrieb, die blaue geht an den Bearbeiter, die grüne behält der
 * Verfasser, die rote geht an S 2 für Einsatztagebuch und Lage. Die gelbe
 * Durchschrift ist der Nachweis im Technischen Betriebsbuch.
 *
 * Digital heißt das nicht "vier Ausdrucke", sondern: Jede dieser vier Stellen
 * erreicht die Nachricht. Zwei davon sind nicht verhandelbar. Wer die rote
 * Durchschrift abwählen könnte, könnte eine Nachricht an der Dokumentation
 * vorbeischleusen; wer die grüne abwählen könnte, verlöre den Beleg darüber,
 * dass er selbst sie abgesetzt hat. Beide werden deshalb vom Server gesetzt
 * und nicht vom Browser erfragt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/workflow.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** Eine Empfängermatrix mit zwei besetzten Positionen. */
$matrix = [];
for ($row = 1; $row <= 5; $row++) {
    for ($column = 1; $column <= 4; $column++) {
        $matrix[$row][$column] = [
            'typ' => 't', 'fkt' => '', 'rolle' => 'leer',
            'mode' => 'ro', 'auto' => 'f',
        ];
    }
}
$matrix[1][1] = ['typ' => 't', 'fkt' => 'S2', 'rolle' => 'Stab', 'mode' => 'rw', 'auto' => 'f'];
$matrix[2][3] = ['typ' => 't', 'fkt' => 'S3', 'rolle' => 'Stab', 'mode' => 'rw', 'auto' => 'f'];

$tokensOf = static function (string $distribution): array {
    return array_values(array_filter(
        array_map('trim', explode(',', $distribution)),
        'strlen'
    ));
};

/* --- Rot und grün stehen, auch wenn der Browser nichts anbietet --- */

$required = ['S2_rt', 'S1_gn'];

$empty = estab_workflow_distribution_tokens([], $matrix, $required);
foreach ($required as $token) {
    $assert(
        in_array($token, $tokensOf($empty), true),
        estab_dv_requirement(
            'NV-4FACH-VERTEILUNG',
            'Die Durchschrift ' . $token . ' fehlt, wenn der Browser keinen '
                . 'Empfänger anbietet.'
        )
    );
}

// Auch dann, wenn der Verfasser einen Bearbeiter ankreuzt.
$chosen = estab_workflow_distribution_tokens(
    ['16_23' => '16_23_bl'],
    $matrix,
    $required
);
$chosenTokens = $tokensOf($chosen);
foreach ($required as $token) {
    $assert(
        in_array($token, $chosenTokens, true),
        estab_dv_requirement(
            'NV-4FACH-VERTEILUNG',
            'Die Durchschrift ' . $token . ' verschwindet, sobald ein '
                . 'Bearbeiter angekreuzt wird.'
        )
    );
}
$assert(
    in_array('S3_bl', $chosenTokens, true),
    estab_dv_requirement(
        'NV-4FACH-VERTEILUNG',
        'Die blaue Durchschrift erreicht den angekreuzten Bearbeiter nicht.'
    )
);

/*
 * Der Browser kann die vorgeschriebenen Durchschriften nicht abwählen: Es gibt
 * kein Eingabefeld dafür. Ein Client, der es dennoch versucht, wird
 * zurückgewiesen -- nicht stillschweigend ignoriert.
 */
foreach (
    [
        ['16_gncopy', ''],
        ['16_11', '16_11_rt'],
        ['16_11', '16_23_bl'],
        ['16_empf', 'S2_rt,'],
    ] as [$field, $value]
) {
    $rejected = false;
    try {
        estab_workflow_distribution_tokens([$field => $value], $matrix, $required);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert(
        $rejected,
        estab_dv_requirement(
            'NV-4FACH-VERTEILUNG',
            'Der Browser darf die Verteilung über ' . $field . ' = '
                . var_export($value, true) . ' umschreiben.'
        )
    );
}

/* --- Nur vier Farben, und die gelbe ist kein Kästchen im Feld 19 --- */

// Funktionskürzel dürfen Unterstriche tragen, "S2_bl_bl" ist deshalb die
// blaue Durchschrift der Funktion "S2_bl" und keine erfundene Farbe. Geprüft
// werden Farben, die es im Feld 19 nicht gibt, und Kürzel, die zu lang sind.
foreach (
    ['S2_ge', 'S2_gb', 'S2_ws', 'S2', 'S2_RT', 'Zulang1_bl', ''] as $invalid
) {
    $rejected = false;
    try {
        estab_workflow_distribution_tokens([], $matrix, [$invalid]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert(
        $rejected,
        estab_dv_requirement(
            'NV-4FACH-VERTEILUNG',
            'Der Verteiler nimmt die erfundene Durchschrift '
                . var_export($invalid, true) . ' an.'
        )
    );
}

/* --- Blau benennt den Bearbeiter, rot und grün tun das nicht --- */

$assert(
    estab_workflow_distribution_has_processor('S3_bl,') === true,
    estab_dv_requirement(
        'NV-4FACH-VERTEILUNG',
        'Die blaue Durchschrift benennt keinen Bearbeiter.'
    )
);
foreach (['S2_rt,', 'S1_gn,', 'S2_rt,S1_gn,', ''] as $withoutProcessor) {
    $assert(
        estab_workflow_distribution_has_processor($withoutProcessor) === false,
        estab_dv_requirement(
            'NV-4FACH-VERTEILUNG',
            'Der Verteiler ' . var_export($withoutProcessor, true)
                . ' gilt als Bearbeiter, obwohl er keine blaue Durchschrift '
                . 'enthält.'
        )
    );
}

/* --- Die gelbe Durchschrift: das Technische Betriebsbuch --- */

$repository = file_get_contents($root . '/app/message_repository.php');
$assert(
    is_string($repository),
    'Die Nachrichtenablage ist nicht lesbar'
);
$assert(
    substr_count($repository, 'estab_logbook_lifecycle_message_transport(') >= 2,
    estab_dv_requirement(
        'NV-4FACH-VERTEILUNG',
        'Nicht jeder Nachrichtenlauf schreibt in das Technische '
            . 'Betriebsbuch; die gelbe Durchschrift bliebe leer.'
    )
);
$assert(
    str_contains($repository, 'estab_logbook_lifecycle_message_handover('),
    estab_dv_requirement(
        'NV-4FACH-VERTEILUNG',
        'Die Aushändigung der eingegangenen Nachricht erreicht das '
            . 'Technische Betriebsbuch nicht.'
    )
);

/*
 * Die roten und grünen Durchschriften werden dort gesetzt, wo gespeichert
 * wird. Der Verfasser bekommt beide, der Sichter einer eingehenden Nachricht
 * nur die rote: Eine von außen eingegangene Nachricht hat im Stab keinen
 * Verfasser, dem eine grüne Durchschrift zustünde.
 */
$handler = file_get_contents($root . '/4fach/data_hndl.php');
$assert(
    is_string($handler),
    'Die Speicherstrecke ist nicht lesbar'
);
$assert(
    substr_count($handler, '$redcopy2."_rt"') >= 3,
    estab_dv_requirement(
        'NV-4FACH-VERTEILUNG',
        'Eine Speicherstrecke setzt die rote Durchschrift für Lage und '
            . 'Dokumentation nicht.'
    )
);
$assert(
    substr_count($handler, '$sessionFunction."_gn"') >= 2,
    estab_dv_requirement(
        'NV-4FACH-VERTEILUNG',
        'Eine Speicherstrecke des Verfassers setzt seine eigene grüne '
            . 'Durchschrift nicht.'
    )
);

printf("Die vier Durchschriften: OK (%d assertions)\n", $assertions);
