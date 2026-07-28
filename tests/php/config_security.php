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
        $loadReviewOutgoing(false, null, null) === false,
        'Outgoing review is not disabled by default'
    );
    $assert(
        $loadReviewOutgoing(true, true, null) === true
            && $loadReviewOutgoing(true, false, null) === false,
        'Boolean legacy m_cfg fallback is not preserved'
    );
    $assert(
        $loadReviewOutgoing(true, false, 'true') === true
            && $loadReviewOutgoing(true, true, 'false') === false,
        'Environment setting does not override the legacy fallback'
    );

    $invalidEnvironmentRejected = false;
    try {
        $loadReviewOutgoing(true, true, 'sometimes');
    } catch (InvalidArgumentException) {
        $invalidEnvironmentRejected = true;
    }
    $assert(
        $invalidEnvironmentRejected,
        'Invalid outgoing-review environment value was accepted'
    );

    $invalidLegacyRejected = true;
    foreach ([null, 'false'] as $environmentValue) {
        try {
            $loadReviewOutgoing(true, 'true', $environmentValue);
            $invalidLegacyRejected = false;
        } catch (RuntimeException) {
            // A configured environment must not hide malformed executable
            // legacy configuration.
        }
    }
    $assert(
        $invalidLegacyRejected,
        'Non-boolean legacy outgoing-review value was accepted'
    );
} finally {
    if ($originalEnvironment === false) {
        putenv($environmentName);
    } else {
        putenv($environmentName . '=' . $originalEnvironment);
    }
}

echo "configuration security: OK ({$assertions} assertions)\n";
