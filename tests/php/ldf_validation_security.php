<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/4fach/vali_data.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fieldIsValid = static function (string $field, mixed $value): bool {
    $validator = new vali_data_form([$field => $value]);
    $validator->checkallfields();

    return $validator->validate[$field] === true;
};

foreach (['', ' ', " \t\r\n "] as $emptyCallsign) {
    $assert(
        !$fieldIsValid('05_gegenstelle', $emptyCallsign),
        'An empty or whitespace-only callsign was accepted'
    );
}
$assert(
    $fieldIsValid('05_gegenstelle', ' Florian 1/11 '),
    'A non-empty callsign was rejected'
);

foreach (['', ' ', " \t\r\n "] as $emptySender) {
    $assert(
        !$fieldIsValid('13_abseinheit', $emptySender),
        'An empty or whitespace-only translated sender was accepted'
    );
}
$assert(
    $fieldIsValid('13_abseinheit', ' Leitstelle Nord '),
    'A non-empty translated sender was rejected'
);

$source = file_get_contents($root . '/4fach/vali_data.php');
if (!is_string($source)) {
    throw new RuntimeException('Could not inspect message validator source');
}

$caseSection = static function (
    string $source,
    string $case,
    string $followingCase
): string {
    $start = strpos($source, 'case "' . $case . '"');
    $end = strpos(
        $source,
        'case "' . $followingCase . '"',
        $start === false ? 0 : $start + 1
    );
    if ($start === false || $end === false) {
        throw new RuntimeException('Could not isolate validator case ' . $case);
    }

    return substr($source, $start, $end - $start);
};

$incoming = $caseSection($source, 'FM-Eingang', 'FM-Eingang_Sichter');
$incomingReviewed = $caseSection(
    $source,
    'FM-Eingang_Sichter',
    'Stab_schreiben'
);
$leadIncoming = $caseSection($source, 'LdF-Eingang', 'LdF-Ausgang');

$assert(
    str_contains($incoming, '$this->validate["05_gegenstelle"]'),
    'FM-Eingang does not require the validated callsign'
);
$assert(
    str_contains($incomingReviewed, '$this->validate["05_gegenstelle"]'),
    'FM-Eingang_Sichter does not require the validated callsign'
);
$assert(
    str_contains($leadIncoming, '$this->validate["13_abseinheit"]'),
    'LdF-Eingang does not require the validated translated sender'
);

echo 'LdF message validation security: OK (' . $assertions
    . " assertions)\n";
