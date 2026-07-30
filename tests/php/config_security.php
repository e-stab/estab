<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$environmentName = 'ESTAB_REVIEW_OUTGOING_MESSAGES';
$originalEnvironment = getenv($environmentName);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$loadReviewOutgoing = static function (
    bool $legacyConfigured,
    mixed $legacyValue,
    ?string $environmentValue
) use ($root, $environmentName): bool {
    if ($environmentValue === null) {
        putenv($environmentName);
    } else {
        putenv($environmentName . '=' . $environmentValue);
    }

    $conf_4f = [];
    if ($legacyConfigured) {
        $conf_4f['si_in_out'] = $legacyValue;
    }
    include $root . '/4fcfg/config.inc.php';

    if (!is_bool($conf_4f['si_in_out'] ?? null)) {
        throw new RuntimeException('Effective review setting is not boolean');
    }
    return $conf_4f['si_in_out'];
};

try {
    $assert(
        $loadReviewOutgoing(false, null, null) === true,
        'Mandatory outgoing review is not enabled'
    );
    $assert(
        $loadReviewOutgoing(true, true, null) === true
            && $loadReviewOutgoing(true, false, null) === true,
        'Legacy configuration can disable mandatory outgoing review'
    );
    $assert(
        $loadReviewOutgoing(true, false, 'true') === true
            && $loadReviewOutgoing(true, true, 'false') === true
            && $loadReviewOutgoing(true, false, 'sometimes') === true,
        'Environment configuration can alter mandatory outgoing review'
    );
    $assert(
        $loadReviewOutgoing(true, 'false', null) === true,
        'Malformed historical switch can alter mandatory outgoing review'
    );
} finally {
    if ($originalEnvironment === false) {
        putenv($environmentName);
    } else {
        putenv($environmentName . '=' . $originalEnvironment);
    }
}

echo "configuration security: OK ({$assertions} assertions)\n";
