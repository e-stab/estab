<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/message_timeline.php';

$assertions = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectTimelineFailure = static function (
    callable $operation,
    string $message
) use ($assert): void {
    try {
        $operation();
    } catch (EstabMessageTimelineInputException|EstabMessageTimelineEvidenceException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$event = static function (
    int $id,
    string $type,
    ?int $from,
    int $to,
    string $recordedAt,
    array $snapshot = [],
    string $occurredAt = '2040-12-31 23:59:59.999999'
): array {
    return [
        'event_id' => $id,
        'event_type' => $type,
        'from_status' => $from,
        'to_status' => $to,
        'recorded_at' => $recordedAt,
        // Deliberately unrelated: durations must never use this fachliche time.
        'occurred_at' => $occurredAt,
        'field_snapshot' => json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ),
    ];
};

$outgoingEvents = [
    $event(1, 'created', null, 4, '2026-08-02 10:00:00.000000'),
    $event(
        2,
        'si_returned',
        4,
        10,
        '2026-08-02 10:05:00.000000',
        ['reason' => 'Anschrift <script>alert(1)</script> präzisieren']
    ),
    $event(3, 'author_resubmitted', 10, 4, '2026-08-02 10:08:00.000000'),
    $event(4, 'si_approved', 4, 1, '2026-08-02 10:12:00.000000'),
    $event(5, 'ldf_dispatched', 1, 2, '2026-08-02 10:17:00.000000'),
    $event(
        6,
        'aw_transport_returned',
        2,
        1,
        '2026-08-02 10:20:00.000000',
        ['transport_return_reason' => 'Relais ausgefallen & Ersatzweg nötig']
    ),
    $event(7, 'ldf_dispatched', 1, 2, '2026-08-02 10:23:00.000000'),
    $event(8, 'aw_transported', 2, 8, '2026-08-02 10:30:00.000000'),
];
$outgoing = estab_message_timeline_build(
    ['04_richtung' => 'A', '11_gesprnotiz' => 'f', 'x00_status' => 8],
    $outgoingEvents,
    '2026-08-02 12:00:00.000000'
);
$assert(
    $outgoing['kind'] === 'outgoing'
        && $outgoing['complete'] === true
        && $outgoing['reconstructable'] === true
        && count($outgoing['visits']) === 9
        && $outgoing['future'] === [],
    'The completed outgoing workflow was not reconstructed exactly'
);
$assert(
    array_column($outgoing['visits'], 'station') === [
        'author',
        'review',
        'author-correction',
        'review',
        'ldf',
        'telecommunications',
        'ldf',
        'telecommunications',
        'completed',
    ],
    'Repeated review/LdF/Fernmelder visits were collapsed or reordered'
);
$assert(
    $outgoing['visits'][0]['duration_seconds'] === null
        && $outgoing['visits'][0]['duration_label']
            === 'Erfassungsbeginn nicht dokumentiert'
        && $outgoing['visits'][1]['duration_seconds'] === 300
        && $outgoing['visits'][2]['duration_seconds'] === 180
        && $outgoing['visits'][3]['duration_seconds'] === 240
        && $outgoing['visits'][4]['duration_seconds'] === 300
        && $outgoing['visits'][5]['duration_seconds'] === 180
        && $outgoing['visits'][6]['duration_seconds'] === 180
        && $outgoing['visits'][7]['duration_seconds'] === 420,
    'Station durations were invented or not derived from recorded_at'
);
$assert(
    ($outgoing['visits'][2]['return']['event_type'] ?? null) === 'si_returned'
        && ($outgoing['visits'][2]['return']['from_label'] ?? null) === 'Sichter'
        && ($outgoing['visits'][6]['return']['event_type'] ?? null)
            === 'aw_transport_returned'
        && ($outgoing['visits'][6]['return']['from_label'] ?? null)
            === 'Fernmelder',
    'Sichter and Fernmelder returns are not explicit visits'
);

$html = estab_message_timeline_render($outgoing, 'message-route-42');
$assert(
    str_starts_with($html, '<section id="message-route-42"')
        && str_contains($html, 'data-estab-message-timeline')
        && str_contains(
            $html,
            '<ol class="estab-message-timeline__track" tabindex="0"'
        )
        && str_contains(
            $html,
            'aria-label="Stationen und Laufzeiten der Meldung"'
        )
        && str_contains($html, 'class="estab-message-timeline__summary"')
        && str_contains($html, 'class="estab-message-timeline__station"')
        && str_contains($html, 'class="estab-message-timeline__reason"')
        && substr_count($html, 'estab-message-timeline__step') >= 9
        && str_contains($html, 'estab-message-timeline__step--completed')
        && str_contains($html, 'estab-message-timeline__step--current')
        && str_contains($html, 'estab-message-timeline__step--returned')
        && str_contains($html, 'estab-message-timeline__step--terminal')
        && str_contains($html, 'class="estab-message-timeline__connector')
        && !str_contains(
            $html,
            '</li><span class="estab-message-timeline__connector'
        )
        && preg_match(
            '/<li[^>]*><span class="estab-message-timeline__connector/',
            $html
        ) === 1
        && str_contains($html, 'data-estab-timeline-station="ldf"')
        && str_contains($html, 'data-estab-timeline-state="current"')
        && str_contains($html, 'data-estab-timeline-return="si_returned"')
        && str_contains($html, 'data-estab-timeline-duration-seconds="300"')
        && substr_count($html, 'aria-current="step"') === 1,
    'The reusable semantic timeline HTML contract is incomplete'
);
$assert(
    !str_contains($html, '<script>alert(1)</script>')
        && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;')
        && str_contains($html, 'Relais ausgefallen &amp; Ersatzweg nötig'),
    'A return reason escaped the single HTML encoding boundary'
);

$ldfReturnedEvents = [
    $event(10, 'created', null, 4, '2026-08-02 11:00:00.000000'),
    $event(11, 'si_approved', 4, 1, '2026-08-02 11:03:00.000000'),
    $event(
        12,
        'ldf_returned',
        1,
        10,
        '2026-08-02 11:05:00.000000',
        ['return_reason' => 'Gegenstelle nicht eindeutig']
    ),
];
$ldfReturned = estab_message_timeline_build(
    ['04_richtung' => 'A', '11_gesprnotiz' => 'f', 'x00_status' => 10],
    $ldfReturnedEvents,
    '2026-08-02 11:09:30.000000'
);
$assert(
    ($ldfReturned['visits'][3]['return']['event_type'] ?? null)
        === 'ldf_returned'
        && ($ldfReturned['visits'][3]['return']['reason'] ?? null)
            === 'Gegenstelle nicht eindeutig'
        && $ldfReturned['visits'][3]['duration_seconds'] === 270
        && array_column($ldfReturned['future'], 'station') === [
            'review', 'ldf', 'telecommunications', 'completed',
        ],
    'Future LdF returns or planned subsequent stations are not represented'
);
$ldfHtml = estab_message_timeline_render($ldfReturned, 'ldf-return-route');
$assert(
    str_contains($ldfHtml, 'estab-message-timeline__step--pending')
        && !str_contains($ldfHtml, 'estab-message-timeline__step--terminal')
        && str_contains($ldfHtml, 'data-estab-timeline-return="ldf_returned"')
        && str_contains($ldfHtml, 'Zurückgegeben von LdF.')
        && substr_count($ldfHtml, 'aria-current="step"') === 1,
    'Current and planned LdF-return HTML is ambiguous'
);

$incoming = estab_message_timeline_build(
    ['04_richtung' => 'E', '11_gesprnotiz' => 'f', 'x00_status' => 8],
    [
        $event(20, 'created', null, 1, '2026-08-02 12:00:00.000000'),
        $event(21, 'ldf_dispatched', 1, 4, '2026-08-02 12:02:00.000000'),
        $event(22, 'incoming_routed', 4, 8, '2026-08-02 12:07:00.000000'),
    ],
    '2026-08-02 12:30:00.000000'
);
$assert(
    $incoming['kind'] === 'incoming'
        && array_column($incoming['visits'], 'station') === [
            'incoming-registration', 'ldf', 'review', 'completed',
        ]
        && $incoming['visits'][1]['duration_seconds'] === 120
        && $incoming['visits'][2]['duration_seconds'] === 300
        && $incoming['visits'][3]['label'] === 'Empfänger · abgeschlossen',
    'The incoming Fernmelder/LdF/Sichter route is incorrect'
);

$conversation = estab_message_timeline_build(
    ['04_richtung' => 'A', '11_gesprnotiz' => 't', 'x00_status' => 4],
    [
        $event(
            30,
            'conversation_note_created',
            null,
            4,
            '2026-08-02 13:00:00.000000',
            [],
            '1990-01-01 00:00:00.000000'
        ),
    ],
    '2026-08-02 13:01:30.000000'
);
$assert(
    $conversation['kind'] === 'conversation-note'
        && $conversation['visits'][0]['label']
            === 'Verfasser · Gesprächsnotiz'
        && $conversation['visits'][0]['duration_seconds'] === null
        && $conversation['visits'][1]['station'] === 'review'
        && $conversation['visits'][1]['duration_seconds'] === 90
        && array_column($conversation['future'], 'station') === [
            'ldf', 'telecommunications', 'completed',
        ],
    'Conversation-note duration used occurred_at or invented draft time'
);

$legacy = estab_message_timeline_build(
    ['04_richtung' => 'E', '11_gesprnotiz' => 'f', 'x00_status' => 4],
    [
        $event(40, 'legacy_import', null, 4, '2026-08-02 14:00:00.000000'),
    ],
    '2026-08-02 14:10:00.000000'
);
$legacyHtml = estab_message_timeline_render($legacy, 'legacy-route');
$assert(
    $legacy['reconstructable'] === false
        && $legacy['visits'][0]['station'] === 'legacy-import'
        && $legacy['visits'][1]['station'] === 'review'
        && $legacy['visits'][1]['duration_seconds'] === null
        && $legacy['visits'][1]['duration_label']
            === 'Erfassungsbeginn nicht dokumentiert'
        && str_contains($legacyHtml, 'Historischer Bestand')
        && str_contains($legacyHtml, 'nicht vollständig rekonstruierbar')
        && str_contains(
            $legacyHtml,
            'estab-message-timeline__step--legacy'
        ),
    'Legacy imports falsely claim a reconstructed workflow'
);

$outgoingDraft = estab_message_timeline_for_draft('A');
$incomingDraft = estab_message_timeline_for_draft('E');
$conversationDraft = estab_message_timeline_for_draft('A', true);
$assert(
    $outgoingDraft['draft'] === true
        && $outgoingDraft['reconstructable'] === true
        && $outgoingDraft['history_note'] === null
        && $outgoingDraft['visits'][0]['station'] === 'author'
        && $outgoingDraft['visits'][0]['duration_seconds'] === null
        && $outgoingDraft['visits'][0]['duration_label']
            === 'Noch nicht eingereicht'
        && array_column($outgoingDraft['future'], 'station') === [
            'review', 'ldf', 'telecommunications', 'completed',
        ],
    'An outgoing draft invents history, time or omits its planned route'
);
$assert(
    $incomingDraft['visits'][0]['station'] === 'incoming-registration'
        && $incomingDraft['visits'][0]['label'] === 'Fernmelder · Eingang'
        && array_column($incomingDraft['future'], 'station') === [
            'ldf', 'review', 'completed',
        ],
    'An incoming draft does not expose its prescribed route'
);
$assert(
    $conversationDraft['kind'] === 'conversation-note'
        && $conversationDraft['visits'][0]['label']
            === 'Verfasser · Gesprächsnotiz'
        && $conversationDraft['history_note'] === null
        && !str_contains(
            estab_message_timeline_render(
                $conversationDraft,
                'conversation-draft-route'
            ),
            'kein rekonstruierbarer Ereignisverlauf'
        ),
    'A conversation-note draft is mislabeled as missing evidence'
);
$expectTimelineFailure(
    static fn (): array => estab_message_timeline_for_draft('E', true),
    'An incoming conversation-note shortcut was accepted as a new draft'
);

$assert(
    estab_message_timeline_return_metadata(
        'si_returned',
        '{broken json'
    )['reason'] === null
        && estab_message_timeline_return_metadata(
            'si_returned',
            ['reason' => ['not a string']]
        )['reason'] === null
        && estab_message_timeline_return_metadata(
            'si_returned',
            ['reason' => "unsafe\0text"]
        )['reason'] === null
        && estab_message_timeline_return_metadata('created', []) === null,
    'Malformed return reasons passed the narrow JSON projection'
);
$assert(
    estab_message_timeline_duration_label(null)
        === 'Erfassungsbeginn nicht dokumentiert'
        && estab_message_timeline_duration_label(1) === '1 Sekunde'
        && estab_message_timeline_duration_label(61) === '1 Min. 1 Sek.'
        && estab_message_timeline_duration_label(90061)
            === '1 Tag 1 Std. 1 Min.',
    'Duration labels are not stable and human-readable'
);

$expectTimelineFailure(
    static fn (): array => estab_message_timeline_build(
        ['04_richtung' => 'A', 'x00_status' => 1],
        [
            $event(50, 'created', null, 4, '2026-08-02 15:00:00.000000'),
            $event(51, 'ldf_dispatched', 1, 2, '2026-08-02 15:01:00.000000'),
        ]
    ),
    'A broken status chain was accepted'
);
$expectTimelineFailure(
    static fn (): array => estab_message_timeline_build(
        ['04_richtung' => 'A', 'x00_status' => 4],
        [$event(60, 'si_approved', null, 4, '2026-08-02 15:00:00.000000')]
    ),
    'A known event with impossible statuses was accepted'
);
$expectTimelineFailure(
    static fn (): array => estab_message_timeline_build(
        ['04_richtung' => 'A', 'x00_status' => 8],
        [$event(70, 'created', null, 4, '2026-08-02 15:00:00.000000')]
    ),
    'A live status that disagrees with its evidence was accepted'
);
$expectTimelineFailure(
    static fn (): array => estab_message_timeline_build(
        ['04_richtung' => 'A', 'x00_status' => 1],
        [
            $event(71, 'created', null, 4, '2026-08-02 15:05:00.000000'),
            $event(72, 'si_approved', 4, 1, '2026-08-02 15:04:00.000000'),
        ],
        '2026-08-02 15:06:00.000000'
    ),
    'A decreasing recorded_at sequence was disguised as an unknown duration'
);
$expectTimelineFailure(
    static fn (): array => estab_message_timeline_build(
        ['04_richtung' => 'A', 'x00_status' => 4],
        [$event(73, 'created', null, 4, '2026-08-02 15:05:00.000000')],
        '2026-08-02 15:04:59.999999'
    ),
    'A database clock preceding the last event was accepted as elapsed time'
);
$tooMany = [];
for ($index = 1; $index <= ESTAB_MESSAGE_TIMELINE_MAX_EVENTS + 1; $index++) {
    $tooMany[] = $event(
        $index,
        $index === 1 ? 'created' : 'future_unknown_event',
        $index === 1 ? null : 4,
        4,
        '2026-08-02 16:00:00.000000'
    );
}
$expectTimelineFailure(
    static fn (): array => estab_message_timeline_build(
        ['04_richtung' => 'A', 'x00_status' => 4],
        $tooMany
    ),
    'The hard per-message event limit was bypassed'
);
$expectTimelineFailure(
    static fn (): string => estab_message_timeline_render(
        $outgoing,
        'invalid id<script>'
    ),
    'An unsafe DOM ID entered the HTML renderer'
);

$source = file_get_contents($root . '/app/message_timeline.php');
if (!is_string($source)) {
    throw new RuntimeException('Could not inspect message timeline source');
}
$assert(
    str_contains($source, 'ESTAB_MESSAGE_TIMELINE_MAX_EVENTS = 512')
        && str_contains(
            $source,
            'WHERE event_row.`einsatz_id` = ?'
        )
        && str_contains($source, 'AND event_row.`message_id` = ?')
        && str_contains($source, 'ORDER BY event_row.`event_id` ASC LIMIT ')
        && str_contains($source, 'nv_nachrichten_nachweiskopf')
        && str_contains($source, 'estab_message_evidence_event_hash')
        && str_contains($source, 'hash_equals'),
    'The database reader is not bounded, message-scoped, ordered and verified'
);
$assert(
    !str_contains($source, 'CREATE TABLE')
        && !str_contains($source, 'INSERT INTO')
        && !str_contains($source, 'UPDATE `nv_nachrichten')
        && !str_contains($source, 'DELETE FROM'),
    'The central timeline domain unexpectedly owns persistence mutations'
);

printf(
    "Message timeline security: OK (%d assertions)\n",
    $assertions
);
