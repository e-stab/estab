<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/4fach/mainindex.php');
$tools = file_get_contents($root . '/4fach/tools.php');
$form = file_get_contents($root . '/4fach/4fachform.php');
$handler = file_get_contents($root . '/4fach/data_hndl.php');
$repository = file_get_contents($root . '/app/message_repository.php');

$assert(is_string($controller), 'LdF controller source is unreadable');
$assert(is_string($tools), 'HTML helper source is unreadable');
$assert(is_string($form), 'LdF form source is unreadable');
$assert(is_string($handler), 'LdF handler source is unreadable');
$assert(is_string($repository), 'Message repository source is unreadable');

$incomingRepositoryStart = strpos(
    $repository,
    "if (\$direction === 'E' && \$status === 1)"
);
$outgoingRepositoryStart = strpos(
    $repository,
    "if (\$direction === 'A' && \$status === 1)",
    $incomingRepositoryStart === false ? 0 : $incomingRepositoryStart
);
$incomingRepository = (
    $incomingRepositoryStart !== false
    && $outgoingRepositoryStart !== false
    && $outgoingRepositoryStart > $incomingRepositoryStart
) ? substr(
    $repository,
    $incomingRepositoryStart,
    $outgoingRepositoryStart - $incomingRepositoryStart
) : '';

$assert(
    str_contains(
        $form,
        '$this->feld [1] || $this->task === "LdF-Eingang"'
    )
        && str_contains(
            $form,
            'data-estab-incoming-transport-confirmation=\\"required\\"'
        )
        && str_contains(
            $form,
            'name=\\"incoming_transport_correction_reason\\" maxlength=\\"500\\"'
        )
        && str_contains(
            $form,
            'Eingangsweg durch LdF bestätigen'
        )
        && str_contains(
            $form,
            'data-estab-incoming-transport-original=\\"'
        )
        && str_contains(
            $form,
            'role=\\"radiogroup\\"'
        )
        && str_contains(
            $form,
            '<label for=\\"f_01_medium_fu\\"><input id=\\"f_01_medium_fu\\"'
        ),
    'LdF incoming form does not expose the explicit route confirmation'
);
$assert(
    str_contains(
        $handler,
        '$ldfFields ["01_medium"] = (string) $data ["01_medium"];'
    )
        && str_contains(
            $handler,
            '"incoming_transport_confirmed" => hash_equals ('
        )
        && str_contains(
            $handler,
            '"requested_transport_correction_reason" => trim ('
        )
        && str_contains(
            $handler,
            '$data ["01_datum"] = "";'
        )
        && str_contains(
            $handler,
            '$data ["01_zeichen"] = "";'
        )
        && str_contains(
            $handler,
            'catch (EstabDvInputException|EstabDvConflictException'
        )
        && str_contains(
            $handler,
            'function estab_rehydrate_ldf_incoming_form ('
        )
        && str_contains(
            $handler,
            'function estab_rehydrate_locked_operator_form ('
        )
        && str_contains(
            $handler,
            '$rehydrated = estab_rehydrate_authoritative_message_form ('
        )
        && str_contains(
            $handler,
            '$rehydrated ["incoming_transport_original_medium"] ='
        )
        && str_contains(
            $handler,
            "if (!\$result) {\n          http_response_code (422);"
        )
        && str_contains(
            $handler,
            'function estab_render_ldf_stage_conflict (): never'
        )
        && str_contains(
            $handler,
            'if (!$ldfSaved) {'
        )
        && str_contains(
            $handler,
            'estab_render_ldf_stage_conflict ();'
        ),
    'LdF handler does not carry or safely rehydrate route evidence'
);
$assert(
    str_contains(
        $repository,
        "if (\$direction === 'E' && \$status === 1)"
    )
        && str_contains(
            $incomingRepository,
            "'SELECT `01_medium`, `13_abseinheit`, `05_gegenstelle` FROM '"
        )
        && str_contains(
            $incomingRepository,
            ". ' FOR UPDATE'"
        )
        && str_contains(
            $repository,
            "'previous_incoming_transport_medium'"
        )
        && str_contains($repository, "'transport_correction_reason'")
        && str_contains($repository, "'transport_corrected'")
        && str_contains(
            $repository,
            'Für die Korrektur des Eingangswegs ist eine '
        )
        && str_contains(
            $repository,
            'function estab_message_fetch_locked_operator_stage('
        )
        && str_contains(
            $repository,
            "' AND BINARY `x03_sperruser` = BINARY ?'"
        )
        && str_contains(
            $repository,
            "preg_match(\n                        '/\\p{C}/u'"
        )
        && str_contains(
            $repository,
            'estab_logbook_lifecycle_message_transport_correction('
        )
        && str_contains($repository, '$incomingTbbCorrection'),
    'Repository does not atomically confirm and evidence the incoming route'
);

$cancelStart = strpos($controller, '} elseif ( ( in_array (');
$cancelEnd = strpos(
    $controller,
    'Daten kommen vom Formular und sollen als Antwort dienen.',
    $cancelStart === false ? 0 : $cancelStart
);
$assert(
    $cancelStart !== false && $cancelEnd !== false && $cancelEnd > $cancelStart,
    'operator cancellation branch cannot be isolated'
);
$cancelBranch = substr(
    $controller,
    $cancelStart === false ? 0 : $cancelStart,
    ($cancelStart === false || $cancelEnd === false)
        ? 0
        : $cancelEnd - $cancelStart
);

$assert(
    str_contains($cancelBranch, '"LdF-Eingang", "LdF-Ausgang"')
        && str_contains(
            $cancelBranch,
            'estab_message_release_operator_stage_lock ('
        )
        && str_contains($cancelBranch, '$cancelIsLead ? 1 : 2')
        && str_contains($cancelBranch, '$workflowSelectedIdentity'),
    'LdF cancellation does not release the identity-bound stage-one lock'
);
$assert(
    str_contains($cancelBranch, 'if ($cancelIsLead) {')
        && str_contains($cancelBranch, '$returnValue ["task"] = "";')
        && str_contains($cancelBranch, '$returnValue ["ldf"] = "";'),
    'LdF cancellation does not restore neutral queue routing'
);

$queueStart = strpos($controller, '--- L d F   D i s p o s i t i o n ---');
$queueEnd = strpos(
    $controller,
    'if ($returnValue ["ldf"] === "meldung")',
    $queueStart === false ? 0 : $queueStart
);
$assert(
    $queueStart !== false && $queueEnd !== false && $queueEnd > $queueStart,
    'LdF queue renderer cannot be isolated'
);
$queueRenderer = substr(
    $controller,
    $queueStart === false ? 0 : $queueStart,
    ($queueStart === false || $queueEnd === false)
        ? 0
        : $queueEnd - $queueStart
);
$assert(
    str_contains($queueRenderer, '$returnValue ["task"] === ""')
        && str_contains($queueRenderer, 'pre_html (')
        && str_contains($queueRenderer, '"ldfliste"')
        && str_contains(
            $queueRenderer,
            'new listen ("LDF", "", $workflowIncidentId)'
        )
        && str_contains($queueRenderer, '$list->createlist ();'),
    'neutral LdF routing no longer renders the disposition queue'
);

$ldfListCase = strpos($tools, 'case "ldfliste":');
$defaultCase = strpos($tools, 'default:');
$assert(
    $ldfListCase !== false
        && $defaultCase !== false
        && $ldfListCase < $defaultCase,
    'ldfliste falls through to the invalid pre_html page type'
);

$listHeadersStart = strrpos(
    substr($tools, 0, $ldfListCase === false ? 0 : $ldfListCase),
    'case "fmdliste":'
);
$listHeadersEnd = strpos(
    $tools,
    'break;',
    $ldfListCase === false ? 0 : $ldfListCase
);
$assert(
    $listHeadersStart !== false
        && $listHeadersEnd !== false
        && $listHeadersEnd > $listHeadersStart,
    'LdF refresh header branch cannot be isolated'
);
$listHeaders = substr(
    $tools,
    $listHeadersStart === false ? 0 : $listHeadersStart,
    ($listHeadersStart === false || $listHeadersEnd === false)
        ? 0
        : $listHeadersEnd - $listHeadersStart
);
$assert(
    str_contains($listHeaders, 'case "ldfliste":')
        && str_contains($listHeaders, 'http-equiv=\"pragma\"')
        && str_contains($listHeaders, 'http-equiv=\"expires\"')
        && str_contains($listHeaders, 'estab_list_refresh_script')
        && str_contains($listHeaders, '$cfg ["itv"] ["fmdliste"]'),
    'LdF queue does not inherit the no-cache headers and the automatic refresh'
);

// The queue has to keep refreshing itself, and it must not do so while the
// operator is working in the page: an unconditional reload discarded the
// search term and the scroll position every ten seconds.
$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
} finally {
    chdir($originalWorkingDirectory);
}
$ldfRefresh = estab_list_refresh_script(10);
$assert(
    $ldfRefresh !== '' && str_contains($ldfRefresh, 'window.location.reload()'),
    'The LdF queue no longer refreshes itself'
);
$assert(
    !str_contains($ldfRefresh, 'http-equiv')
        && str_contains($ldfRefresh, 'schedule(5000)'),
    'The LdF queue reloads unconditionally and interrupts the operator'
);

printf("LdF UI flow security test: OK (%d assertions)\n", $assertions);
