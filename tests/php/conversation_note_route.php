<?php

declare(strict_types=1);

/**
 * Eine Gesprächsnotiz läuft Verfasser → Sichter → abgeschlossen.
 *
 * Sie hält ein Gespräch fest, das bereits stattgefunden hat. Es gibt nichts zu
 * disponieren und nichts zu befördern. Bisher lief sie trotzdem über LdF und
 * A/W und erzeugte damit Nachweise über eine Beförderung, die nie stattfand.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/message_timeline.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
};

// 1. Der Laufweg selbst: kein LdF, keine Beförderung.
$route = estab_message_timeline_future_statuses('A', 0, 'conversation-note');
$assert(
    $route === [4, 8],
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Der Laufweg führt über weitere Stationen als Sichtung und Abschluss: '
            . implode(', ', array_map('strval', $route))
    )
);
$assert(
    estab_message_timeline_future_statuses('A', 4, 'conversation-note') === [8],
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Nach der Sichtung folgt noch eine weitere Station'
    )
);
$assert(
    estab_message_timeline_future_statuses('A', 8, 'conversation-note') === [],
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Die abgeschlossene Gesprächsnotiz erwartet noch etwas'
    )
);

// Der gewöhnliche Ausgang bleibt unangetastet.
$assert(
    estab_message_timeline_future_statuses('A', 0) === [4, 1, 2, 8]
        && estab_message_timeline_future_statuses('A', 4) === [1, 2, 8]
        && estab_message_timeline_future_statuses('E', 1) === [4, 8],
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Der kurze Laufweg greift auf gewöhnliche Nachrichten über'
    )
);

// 2. Der Abschluss ist als Ereignis gültig, und nur für eine Gesprächsnotiz.
$assert(
    estab_message_timeline_known_event_valid(
        'conversation_note_closed',
        'A',
        'conversation-note',
        4,
        8
    ) === true,
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Der Abschluss der Gesprächsnotiz gilt nicht als gültiger Übergang'
    )
);
foreach ([
    ['A', 'outgoing', 4, 8, 'eine gewöhnliche Ausgangsnachricht'],
    ['E', 'incoming', 4, 8, 'eine Eingangsnachricht'],
    ['A', 'conversation-note', 1, 8, 'einen Übergang aus der Disposition'],
    ['A', 'conversation-note', 2, 8, 'einen Übergang aus der Beförderung'],
] as [$direction, $kind, $from, $to, $what]) {
    $assert(
        estab_message_timeline_known_event_valid(
            'conversation_note_closed',
            $direction,
            $kind,
            $from,
            $to
        ) !== true,
        estab_dv_requirement(
            'NV-GESPRAECHSNOTIZ-LAUFWEG',
            'Der Abschluss der Gesprächsnotiz gilt auch für ' . $what
        )
    );
}

// 3. Der vollständige Weg am gerenderten Zeitstrahl.
$event = static function (
    int $id,
    string $type,
    ?int $from,
    int $to,
    string $recordedAt
): array {
    return [
        'event_id' => $id,
        'event_type' => $type,
        'from_status' => $from,
        'to_status' => $to,
        'recorded_at' => $recordedAt,
        'field_snapshot' => json_encode([]),
        'occurred_at' => null,
    ];
};
$timeline = estab_message_timeline_build(
    ['04_richtung' => 'A', '11_gesprnotiz' => 't', 'x00_status' => 8],
    [
        $event(1, 'conversation_note_created', null, 4, '2026-08-02 10:00:00.000000'),
        $event(2, 'conversation_note_closed', 4, 8, '2026-08-02 10:04:00.000000'),
    ],
    '2026-08-02 10:20:00.000000'
);
$stations = array_column($timeline['visits'], 'station');
$assert(
    $timeline['kind'] === 'conversation-note'
        && $stations === ['author', 'review', 'completed']
        && $timeline['future'] === [],
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Der Zeitstrahl führt die Gesprächsnotiz über: '
            . implode(' → ', $stations)
    )
);
$assert(
    !in_array('ldf', $stations, true)
        && !in_array('telecommunications', $stations, true),
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Die Gesprächsnotiz passiert Disposition oder Beförderung'
    )
);

// 4. Die Sichtung schliesst sie ab, statt sie an den LdF zu geben.
$handler = $read('4fach/data_hndl.php');
$reviewStart = strpos($handler, '$reviewFields ["x00_status"] = 8;');
$assert(
    $reviewStart !== false,
    'Der Sichtungszweig ist nicht mehr auffindbar'
);
$assert(
    str_contains($handler, '$isConversationNote =')
        && str_contains(
            $handler,
            '(string) ($reviewMessage ["11_gesprnotiz"] ?? "f") === "t"'
        ),
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Die Sichtung erkennt eine Gesprächsnotiz nicht am Datensatz'
    )
);
$assert(
    preg_match(
        '~\}\s*elseif\s*\(\$isConversationNote\)\s*\{[^}]*'
            . '\$reviewFields\s*\["x00_status"\]\s*=\s*8;[^}]*'
            . '\$reviewFields\s*\["x01_abschluss"\]\s*=\s*"t";~s',
        $handler
    ) === 1,
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Die Sichtung schliesst die Gesprächsnotiz nicht ab, sondern reicht '
            . 'sie an den LdF weiter'
    )
);
$assert(
    str_contains($handler, '"conversation_note_closed"'),
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Der Abschluss wird nicht als eigenes Ereignis nachgewiesen'
    )
);

// 5. Die Rückgabe an den Verfasser bleibt möglich.
$assert(
    preg_match(
        '~\}\s*elseif\s*\(\$isFormalReturn\)\s*\{[^}]*'
            . '\$reviewFields\s*\["x00_status"\]\s*=\s*10;~s',
        $handler
    ) === 1,
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Die formale Rückgabe an den Verfasser ist verlorengegangen'
    )
);

// 6. Kein Aushändigungseintrag im TBB: die Fernmeldebetriebsstelle hatte die
//    Gesprächsnotiz nie in der Hand.
$repository = $read('app/message_repository.php');
$assert(
    preg_match(
        "~\\(int\\) \\(\\\$fields\\['x00_status'\\] \\?\\? 0\\) === 8\\s*"
            . "&&\\s*\\\$handedOverTo !== ''~",
        $repository
    ) === 1,
    estab_dv_requirement(
        'NV-GESPRAECHSNOTIZ-LAUFWEG',
        'Der Abschluss der Gesprächsnotiz erzeugt einen TBB-Eintrag über eine '
            . 'Aushändigung, die nie stattgefunden hat'
    )
);

printf("conversation note route: OK (%d assertions)\n", $assertions);
