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

$assert(is_string($controller), 'LdF controller source is unreadable');
$assert(is_string($tools), 'HTML helper source is unreadable');

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
        && str_contains($cancelBranch, '$workflowIdentity ["kuerzel"]'),
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
        && str_contains($queueRenderer, 'new listen ("LDF", "")')
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
        && str_contains($listHeaders, 'http-equiv=\"refresh\"')
        && str_contains($listHeaders, '$cfg ["itv"] ["fmdliste"]'),
    'LdF queue does not inherit the no-cache and automatic refresh headers'
);

printf("LdF UI flow security test: OK (%d assertions)\n", $assertions);
