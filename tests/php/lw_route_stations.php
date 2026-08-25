<?php

declare(strict_types=1);

/**
 * Die Stationen des Nachrichtenlaufs, ein- und ausgehend.
 *
 * Die Stab-Unterlage beschreibt zwei Wege. Eingehend nimmt die
 * Fernmeldezentrale auf, der Sichter quittiert und kennzeichnet, danach wird
 * verteilt. Ausgehend fuellt der Stab aus, der Sichter prueft, der Leiter des
 * Fernmeldebetriebes gibt zur Befoerderung frei, und die Fernmeldezentrale
 * befoerdert und schliesst ab.
 *
 * Die Reihenfolge ist kein Ablaufvorschlag. Sie ist der Grund, warum sich der
 * Weg einer Nachricht spaeter rekonstruieren laesst: Jede Station hinterlaesst
 * ihren Vermerk, und keine kann uebersprungen werden. Ein Uebergang, der eine
 * Station auslaesst, zerstoert diesen Nachweis, ohne dass es jemandem auffiele.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/message_status.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$transitions = estab_message_status_transitions();

/** Gibt es genau diesen Uebergang, und traegt ihn die genannte Station? */
$hasStep = static function (
    string $direction,
    int $from,
    int $to,
    string $station
) use ($transitions): bool {
    foreach ($transitions[$direction] ?? [] as $step) {
        if (
            ($step['from'] ?? null) === $from
            && ($step['to'] ?? null) === $to
            && ($step['station'] ?? null) === $station
        ) {
            return true;
        }
    }
    return false;
};

/** Welche Ziele erlaubt ein Stand ueberhaupt? */
$targetsFrom = static function (string $direction, int $from) use ($transitions): array {
    $targets = [];
    foreach ($transitions[$direction] ?? [] as $step) {
        if (($step['from'] ?? null) === $from) {
            $targets[] = $step['to'];
        }
    }
    return array_values(array_unique($targets));
};

/* ------------------------------------------------ *
 * Eingang: Fernmeldezentrale, LdF, Sichter, Schluss *
 * ------------------------------------------------ */

$incoming = [
    [ESTAB_MESSAGE_STATUS_DRAFT, ESTAB_MESSAGE_STATUS_LDF, 'A/W',
        'Die Aufnahme durch die Fernmeldezentrale'],
    [ESTAB_MESSAGE_STATUS_LDF, ESTAB_MESSAGE_STATUS_REVIEW, 'LdF',
        'Die Weitergabe durch den Leiter des Fernmeldebetriebes'],
    [ESTAB_MESSAGE_STATUS_REVIEW, ESTAB_MESSAGE_STATUS_CLOSED, 'Si',
        'Der Abschluss durch den Sichter'],
];
foreach ($incoming as [$from, $to, $station, $what]) {
    $assert(
        $hasStep('incoming', $from, $to, $station),
        estab_dv_requirement(
            'LW-EINGANG-STATIONEN',
            $what . ' fehlt im Laufweg der eingehenden Nachricht.'
        )
    );
}

// Keine Abkuerzung von der Aufnahme direkt in die Sichtung oder den Abschluss.
foreach (
    [ESTAB_MESSAGE_STATUS_REVIEW, ESTAB_MESSAGE_STATUS_CLOSED] as $skipped
) {
    $assert(
        !in_array($skipped, $targetsFrom('incoming', ESTAB_MESSAGE_STATUS_DRAFT), true),
        estab_dv_requirement(
            'LW-EINGANG-STATIONEN',
            'Die Aufnahme überspringt den Leiter des Fernmeldebetriebes.'
        )
    );
}

/* -------------------------------------------------------- *
 * Ausgang: Verfasser, Sichter, LdF, Beförderung, Abschluss  *
 * -------------------------------------------------------- */

$outgoing = [
    [ESTAB_MESSAGE_STATUS_DRAFT, ESTAB_MESSAGE_STATUS_REVIEW, 'Verfasser',
        'Die Abgabe durch den Verfasser'],
    [ESTAB_MESSAGE_STATUS_REVIEW, ESTAB_MESSAGE_STATUS_LDF, 'Si',
        'Die Freigabe durch den Sichter'],
    [ESTAB_MESSAGE_STATUS_LDF, ESTAB_MESSAGE_STATUS_TRANSPORT, 'LdF',
        'Die Freigabe zur Beförderung durch den LdF'],
    [ESTAB_MESSAGE_STATUS_TRANSPORT, ESTAB_MESSAGE_STATUS_CLOSED, 'A/W',
        'Der Abschluss durch die Fernmeldezentrale'],
];
foreach ($outgoing as [$from, $to, $station, $what]) {
    $assert(
        $hasStep('outgoing', $from, $to, $station),
        estab_dv_requirement(
            'LW-AUSGANG-STATIONEN',
            $what . ' fehlt im Laufweg der ausgehenden Nachricht.'
        )
    );
}

// Der Verfasser gibt nicht unmittelbar zur Beförderung frei.
foreach (
    [ESTAB_MESSAGE_STATUS_TRANSPORT, ESTAB_MESSAGE_STATUS_CLOSED] as $skipped
) {
    $assert(
        !in_array($skipped, $targetsFrom('outgoing', ESTAB_MESSAGE_STATUS_DRAFT), true),
        estab_dv_requirement(
            'LW-AUSGANG-STATIONEN',
            'Der Verfasser befördert seine Nachricht ohne Sichtung selbst.'
        )
    );
}

// Die Sichtung führt nicht unmittelbar in die Beförderung; der LdF liegt dazwischen.
$assert(
    !in_array(
        ESTAB_MESSAGE_STATUS_TRANSPORT,
        $targetsFrom('outgoing', ESTAB_MESSAGE_STATUS_REVIEW),
        true
    ),
    estab_dv_requirement(
        'LW-AUSGANG-STATIONEN',
        'Die Sichtung überspringt den Leiter des Fernmeldebetriebes.'
    )
);

/* --- Jede Station des Laufwegs ist eine bekannte Station --- */

$known = ['A/W', 'LdF', 'Si', 'Verfasser'];
foreach (['incoming', 'outgoing'] as $direction) {
    foreach ($transitions[$direction] ?? [] as $step) {
        $assert(
            in_array($step['station'] ?? '', $known, true),
            estab_dv_requirement(
                $direction === 'incoming'
                    ? 'LW-EINGANG-STATIONEN'
                    : 'LW-AUSGANG-STATIONEN',
                'Der Laufweg kennt eine Station außerhalb der '
                    . 'Führungsstelle: ' . var_export($step['station'] ?? null, true)
            )
        );
    }
}

printf("Stationen des Nachrichtenlaufs: OK (%d assertions)\n", $assertions);
