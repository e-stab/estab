<?php

declare(strict_types=1);

/**
 * Entscheidungen im Nachrichtenlauf gehören ins Einsatztagebuch.
 *
 * Die Dienstvorschrift verlangt, Entscheidungen als Eintrag oder als Anlage
 * zum ETB zu dokumentieren. Der Grund ist der Zweck des Buches: Es soll
 * später beantworten, warum gehandelt wurde, wie gehandelt wurde -- nicht
 * nur, dass gehandelt wurde. Eine Nachricht, die befördert wurde, steht im
 * Technischen Betriebsbuch; die Entscheidung, sie so und nicht anders zu
 * befördern, steht dort nicht.
 *
 * Im Nachrichtenlauf fallen fünf Entscheidungen: Der Sichter gibt frei oder
 * gibt zurück, der Leiter des Fernmeldebetriebes disponiert oder gibt
 * zurück, die Fernmeldezentrale befördert oder meldet die Beförderung als
 * nicht möglich. Jede von ihnen wird als unveränderbares Ereignis
 * festgehalten, mit dem, der sie getroffen hat.
 *
 * Und jede erreicht das ETB auf zwei Wegen: über die rote Durchschrift, die
 * jede Nachricht an S 2 trägt, und über den Bezug, mit dem ein ETB-Eintrag
 * auf die Nachricht verweisen kann. Diese Prüfung hält beides fest.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/logbook.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$handler = file_get_contents($root . '/4fach/data_hndl.php');
$repository = file_get_contents($root . '/app/message_repository.php');
$evidence = file_get_contents($root . '/app/message_evidence.php');
if (!is_string($handler) || !is_string($repository) || !is_string($evidence)) {
    throw new RuntimeException('Speicherstrecke, Ablage oder Nachweis nicht lesbar.');
}
$route = $handler . $repository;

/* --- Jede Entscheidung wird festgehalten --- */

$decisions = [
    'si_approved' => 'der Sichter gibt eine Ausgangsnachricht frei',
    'si_returned' => 'der Sichter gibt sie an den Verfasser zurück',
    'incoming_routed' => 'der Sichter verteilt eine eingegangene Nachricht',
    'ldf_dispatched' => 'der Leiter des Fernmeldebetriebes disponiert den Weg',
    'ldf_returned' => 'der Leiter des Fernmeldebetriebes gibt zurück',
    'aw_transported' => 'die Fernmeldezentrale befördert',
    'aw_transport_returned' => 'die Beförderung ist nicht möglich',
];
foreach ($decisions as $event => $what) {
    $assert(
        str_contains($route, '"' . $event . '"')
            || str_contains($route, "'" . $event . "'"),
        estab_dv_requirement(
            'ETB-ENTSCHEIDUNGEN',
            'Die Entscheidung „' . $what . '“ wird nicht als Ereignis '
                . 'festgehalten. Das Einsatztagebuch soll beantworten, warum '
                . 'gehandelt wurde, nicht nur dass gehandelt wurde.'
        )
    );
}

/*
 * Und jede nennt den, der sie getroffen hat. Eine Entscheidung ohne
 * Entscheider ist keine Entscheidung, sondern ein Vorgang.
 */
$assert(
    str_contains($evidence, 'function estab_message_event_append')
        && preg_match(
            '~function estab_message_event_append\('
                . '(?:[^)]*\n)*?[^)]*array \$actor~',
            $evidence
        ) === 1,
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Ein Ereignis des Nachrichtenlaufs wird ohne Urheber geschrieben.'
    )
);
$assert(
    substr_count($route, '"actor" => $messageActor') >= 5,
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Nicht jede Entscheidung des Laufwegs nennt ihren Urheber; gefunden '
            . 'wurden ' . substr_count($route, '"actor" => $messageActor')
            . ' Stellen.'
    )
);

/*
 * Und mit dem Stand davor und danach. Ohne beide bleibt offen, was die
 * Entscheidung bewirkt hat.
 */
foreach (['"from_status" =>', '"to_status" =>'] as $field) {
    $assert(
        substr_count($route, $field) >= 5,
        estab_dv_requirement(
            'ETB-ENTSCHEIDUNGEN',
            'Die Ereignisse des Laufwegs führen ' . $field . ' nicht '
                . 'durchgängig; ohne den Stand davor und danach bleibt '
                . 'offen, was die Entscheidung bewirkt hat.'
        )
    );
}

/* --- Der Eintrag ins ETB: der Bezug auf die Nachricht --- */

$logbook = file_get_contents($root . '/app/logbook.php');
if (!is_string($logbook)) {
    throw new RuntimeException('Das Buchmodul ist nicht lesbar.');
}
$assert(
    str_contains($logbook, 'estab_message_id'),
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Ein ETB-Eintrag kann nicht auf die Nachricht verweisen, zu der er '
            . 'gehört.'
    )
);
$viewer = file_get_contents($root . '/stabetb/etb.php');
$assert(
    is_string($viewer) && str_contains($viewer, 'Nachricht #'),
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Das Einsatztagebuch zeigt den Bezug auf die Nachricht nicht an; '
            . 'wer es liest, findet die Entscheidung nicht wieder.'
    )
);

/* --- Die Anlage zum ETB --- */

$assert(
    str_contains($logbook, 'function estab_logbook_available_etb_attachments'),
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Dem Einsatztagebuch lässt sich nichts anhängen. Die Vorschrift '
            . 'lässt Eintrag oder Anlage zu; ohne Anlage bleibt nur der '
            . 'Eintrag.'
    )
);
$assert(
    str_contains($logbook, '`estab_attachment_id`'),
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Ein ETB-Eintrag führt seine Anlage nicht.'
    )
);

/*
 * Eine Anlage wird nur einmal angehängt: Zweimal dieselbe Anlage an zwei
 * Einträgen liest sich als zwei Vorgänge.
 */
$assert(
    str_contains($logbook, 'AND NOT EXISTS ('),
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Eine bereits angehängte Anlage wird erneut angeboten; zweimal '
            . 'dieselbe liest sich als zwei Vorgänge.'
    )
);

/* --- Und die Eintragsarten benennen Entscheidungen --- */

/*
 * Ein Buch, dessen Eintragsarten nur Beobachtungen kennen, lädt nicht dazu
 * ein, eine Entscheidung einzutragen. Befehl und Erledigung sind die beiden
 * Arten, unter denen sie steht.
 */
$types = estab_logbook_entry_types();
foreach (
    ['B' => 'Befehl', 'E' => 'Erledigung', 'A' => 'Aufgabe'] as $key => $word
) {
    $assert(
        isset($types[$key]) && str_contains($types[$key], $word),
        estab_dv_requirement(
            'ETB-ENTSCHEIDUNGEN',
            'Die Eintragsart „' . $key . ' - ' . $word . '“ fehlt im '
                . 'Einsatztagebuch. Ein Buch, das nur Beobachtungen kennt, '
                . 'lädt nicht dazu ein, eine Entscheidung einzutragen.'
        )
    );
}

/*
 * Und eine Korrektur ist eine eigene Art -- nicht eine geänderte
 * Entscheidung. Die Bücher sind fortschreibend.
 */
$assert(
    isset($types['korrektur']),
    estab_dv_requirement(
        'ETB-ENTSCHEIDUNGEN',
        'Das Einsatztagebuch kennt keine Korrektur als eigene Eintragsart; '
            . 'eine Berichtigung müsste den alten Eintrag ändern.'
    )
);

printf("Entscheidungen im Einsatztagebuch: OK (%d assertions)\n", $assertions);
