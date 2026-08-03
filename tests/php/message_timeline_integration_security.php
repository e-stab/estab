<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$formController = file_get_contents($root . '/4fach/4fachform.php');
$officialView = file_get_contents($root . '/4fach/official_message_form.php');
$overview = file_get_contents($root . '/4fueltg/ue_ltg.php');
$timeline = file_get_contents($root . '/app/message_timeline.php');
$stylesheet = file_get_contents($root . '/estab-ui.css');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    is_string($formController)
        && is_string($officialView)
        && is_string($overview)
        && is_string($timeline)
        && is_string($stylesheet),
    'Message timeline integration sources are unreadable'
);

/** Extract one named function or method without executing legacy controllers. */
$extractFunction = static function (string $php, string $functionName): string {
    $tokens = token_get_all($php);
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $nameIndex = $index + 1;
        while ($nameIndex < $tokenCount) {
            $candidate = $tokens[$nameIndex];
            if (
                (is_array($candidate) && $candidate[0] === T_WHITESPACE)
                || $candidate === '&'
                || (
                    is_array($candidate)
                    && in_array(
                        $candidate[0],
                        [
                            T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
                            T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
                        ],
                        true
                    )
                )
            ) {
                $nameIndex++;
                continue;
            }
            break;
        }

        if (
            $nameIndex >= $tokenCount
            || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING
            || $tokens[$nameIndex][1] !== $functionName
        ) {
            continue;
        }

        $body = '';
        $depth = 0;
        $started = false;
        for ($bodyIndex = $index; $bodyIndex < $tokenCount; $bodyIndex++) {
            $bodyToken = $tokens[$bodyIndex];
            $text = is_array($bodyToken) ? $bodyToken[1] : $bodyToken;
            $body .= $text;
            if (!is_array($bodyToken) && $bodyToken === '{') {
                $depth++;
                $started = true;
            } elseif (!is_array($bodyToken) && $bodyToken === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    return $body;
                }
            }
        }
    }

    throw new RuntimeException("Function {$functionName} not found");
};

/** Verify an ordered contract while allowing harmless formatting changes. */
$appearsInOrder = static function (string $source, array $needles): bool {
    $offset = 0;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle, $offset);
        if ($position === false) {
            return false;
        }
        $offset = $position + strlen($needle);
    }
    return true;
};

$formConstructor = $extractFunction($formController, 'nachrichten4fach');
$loadTimeline = $extractFunction($formController, 'load_message_timeline');
$timelineFallback = $extractFunction(
    $formController,
    'message_timeline_unavailable'
);
$formPlot = $extractFunction($formController, 'plot_form');
$officialPlot = $extractFunction($officialView, 'plot_official_message_form');
$overviewPlot = $extractFunction($overview, 'plot_form');

$assert(
    str_contains(
        $formController,
        'require_once __DIR__ . "/../app/message_timeline.php"'
    )
        && str_contains(
            $overview,
            'require_once __DIR__ . "/../app/message_timeline.php"'
        ),
    'A message view no longer loads the shared timeline module'
);
$assert(
    str_contains($timeline, 'function estab_message_timeline_render')
        && str_contains($stylesheet, 'section.estab-message-timeline')
        && str_contains($stylesheet, '.estab-message-timeline__track'),
    'The shared timeline renderer or component styling is missing'
);

$assert(
    $appearsInOrder(
        $formConstructor,
        [
            '$this->hasUnsavedValidationData = $errorselect !== "";',
            '$this->load_message_timeline ();',
            '$this->plot_form ()',
        ]
    ),
    'Validation rerenders can bypass the timeline before the message form is plotted'
);
$assert(
    str_contains($formPlot, '$this->plot_official_message_form ()'),
    'The runtime message form no longer delegates to the shared official view'
);
$assert(
    str_contains($loadTimeline, 'estab_message_timeline_for_draft')
        && str_contains($loadTimeline, 'estab_message_timeline_render')
        && str_contains($loadTimeline, 'message-timeline-draft'),
    'A new or validation-failed draft has no planned workflow timeline'
);
$assert(
    str_contains($loadTimeline, 'estab_read_session_identity')
        && str_contains(
            $loadTimeline,
            'estab_read_with_locked_operational_scope'
        )
        && str_contains(
            $loadTimeline,
            'estab_message_fetch_for_incident_by_id'
        )
        && str_contains($loadTimeline, 'estab_read_message_allowed')
        && str_contains($loadTimeline, 'estab_message_object_allowed'),
    'Persisted message history is not protected by identity, incident and object checks'
);
$assert(
    $appearsInOrder(
        $loadTimeline,
        [
            'if (!$readAllowed && !$writeAllowed)',
            'estab_message_timeline_for_message ($connection, $message)',
        ]
    ),
    'Persisted history can be rendered before its object authorization decision'
);
$assert(
    str_contains($timelineFallback, 'data-estab-message-timeline')
        && str_contains($timelineFallback, 'role="status"')
        && str_contains(
            $timelineFallback,
            'Der Nachrichtenvordruck bleibt verfügbar.'
        ),
    'Timeline failures no longer use a designed, navigable in-page notice'
);
$assert(
    $appearsInOrder(
        $officialPlot,
        [
            'echo (string)$this->messageTimelineHtml;',
            "echo '<form method=\"post\"",
            'data-estab-official-message-form',
        ]
    ),
    'The official view does not render the workflow band above the form'
);

$assert(
    str_contains($overview, 'estab_read_require_area')
        && str_contains($overview, '"message-overview"')
        && str_contains(
            $overview,
            'estab_message_fetch_for_incident_by_id ('
        )
        && str_contains($overview, '$overviewIncidentId'),
    'The overview detail is not bound to the authorized incident read scope'
);
$assert(
    $appearsInOrder(
        $overview,
        [
            '$formdata = estab_message_fetch_for_incident_by_id',
            'estab_message_timeline_for_message',
            '$messageConnection,',
            '$formdata',
            'if (!is_array ($formdata))',
            'estab_overview_forbid ();',
            '$form = new nachrichten4fach',
        ]
    ),
    'The detail row can bypass its incident-bound record or render an unavailable message'
);
$assert(
    str_contains($overview, 'estab_message_timeline_render (')
        && str_contains($overview, 'estab_message_timeline_for_message (')
        && str_contains($overview, 'data-estab-message-timeline')
        && str_contains(
            $overview,
            'Der Nachrichtenvordruck bleibt verfügbar.'
        ),
    'The overview detail lost the shared timeline renderer or its graceful fallback'
);
$assert(
    $appearsInOrder(
        $overviewPlot,
        [
            'echo $this->messageTimelineHtml;',
            'aria-labelledby=\"message-detail-title\"',
            '<form method=\"get\"',
        ]
    ),
    'The overview detail does not place the workflow band above its message form'
);
$assert(
    $appearsInOrder(
        $overview,
        [
            '$messageTimelineHtml',
            '$form = new nachrichten4fach (',
            '$formdata,',
            '"Stab_lesen",',
            '"",',
            '$messageTimelineHtml',
        ]
    ),
    'The authorized overview detail does not pass its timeline into the form view'
);

printf(
    "Message timeline integration security: OK (%d assertions)\n",
    $assertions
);
