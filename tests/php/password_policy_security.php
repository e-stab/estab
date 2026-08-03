<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/password_policy.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsPolicyInput = static function (callable $operation): bool {
    try {
        $operation();
    } catch (EstabPasswordPolicyInputException) {
        return true;
    }
    return false;
};
$throwsRuntime = static function (callable $operation): bool {
    try {
        $operation();
    } catch (RuntimeException) {
        return true;
    }
    return false;
};
$functionSource = static function (string $source, string $function): string {
    $start = strpos($source, 'function ' . $function . '(');
    if ($start === false) {
        $start = strpos($source, 'function ' . $function . ' (');
    }
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\nfunction ", $start + 10);
    return $next === false
        ? substr($source, $start)
        : substr($source, $start, $next - $start);
};

$defaults = estab_password_policy_defaults();
$assert(
    $defaults === [
        'singleton_id' => 1,
        'minimum_length' => 12,
        'require_uppercase' => false,
        'require_lowercase' => false,
        'require_digit' => false,
        'require_symbol' => false,
        'revision' => 0,
        'updated_at' => '',
        'updated_by' => 'migration',
    ],
    'password-policy defaults changed without an explicit migration contract'
);
$assert(
    estab_password_policy_requirements_text($defaults)
        === 'Mindestens 12 Zeichen. Steuerzeichen sind nicht erlaubt.',
    'default password-policy guidance is incomplete or unstable'
);

$minimumRequest = estab_password_policy_configuration_from_request([
    'minimum_length' => '8',
]);
$assert(
    $minimumRequest === [
        'minimum_length' => 8,
        'require_uppercase' => false,
        'require_lowercase' => false,
        'require_digit' => false,
        'require_symbol' => false,
    ],
    'unchecked password-policy flags are not parsed as disabled'
);
$maximumRequest = estab_password_policy_configuration_from_request([
    'minimum_length' => '128',
    'require_uppercase' => '1',
    'require_lowercase' => 1,
    'require_digit' => true,
    'require_symbol' => '0',
]);
$assert(
    $maximumRequest === [
        'minimum_length' => 128,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_digit' => true,
        'require_symbol' => false,
    ],
    'password-policy request boundaries or checkbox parsing are incorrect'
);
foreach (['7', '129', '08', '-1', '1.0', '', '999'] as $invalidMinimum) {
    $assert(
        $throwsPolicyInput(
            static fn (): array =>
                estab_password_policy_configuration_from_request([
                    'minimum_length' => $invalidMinimum,
                ])
        ),
        'malformed or out-of-range minimum length was accepted'
    );
}
$assert(
    $throwsPolicyInput(
        static fn (): array => estab_password_policy_configuration_from_request([
            'minimum_length' => ['12'],
        ])
    ),
    'non-scalar minimum length was accepted'
);
foreach (['yes', 'on', '', ['1']] as $invalidFlag) {
    $assert(
        $throwsPolicyInput(
            static fn (): array =>
                estab_password_policy_configuration_from_request([
                    'minimum_length' => '12',
                    'require_uppercase' => $invalidFlag,
                ])
        ),
        'ambiguous password-policy checkbox value was accepted'
    );
}

$assert(
    estab_password_policy_revision(0) === 0
        && estab_password_policy_revision('0') === 0
        && estab_password_policy_revision('42') === 42,
    'valid password-policy revision was rejected'
);
foreach (['00', '01', '-1', '+1', '1.0', '', '9223372036854775808'] as $revision) {
    $assert(
        $throwsPolicyInput(
            static fn (): int => estab_password_policy_revision($revision)
        ),
        'ambiguous or overflowing password-policy revision was accepted'
    );
}
$assert(
    $throwsPolicyInput(
        static fn (): int => estab_password_policy_revision(['1'])
    ),
    'non-scalar password-policy revision was accepted'
);

$strictPolicy = [
    'minimum_length' => 8,
    'require_uppercase' => true,
    'require_lowercase' => true,
    'require_digit' => true,
    'require_symbol' => true,
];
$unicodePassword = 'Äbcdef١!';
$assert(
    estab_password_policy_validate_password(
        $unicodePassword,
        $unicodePassword,
        $strictPolicy
    ) === $unicodePassword,
    'Unicode letter or decimal-digit classes are not accepted by the policy'
);
$titlecasePassword = 'ǅbcdef١!';
$assert(
    estab_password_policy_validate_password(
        $titlecasePassword,
        $titlecasePassword,
        $strictPolicy
    ) === $titlecasePassword,
    'Unicode titlecase letter was not accepted as an uppercase character'
);
$joinedEmojiPassword = "Abcdef1!👩‍🚒";
$assert(
    estab_password_policy_validate_password(
        $joinedEmojiPassword,
        $joinedEmojiPassword,
        $strictPolicy
    ) === $joinedEmojiPassword,
    'valid Unicode format characters in an emoji sequence were rejected'
);
$longUnicodePassword = str_repeat("🧭", 128);
$assert(
    estab_password_policy_validate_password(
        $longUnicodePassword,
        $longUnicodePassword,
        array_replace($strictPolicy, [
            'minimum_length' => 128,
            'require_uppercase' => false,
            'require_lowercase' => false,
            'require_digit' => false,
            'require_symbol' => false,
        ])
    ) === $longUnicodePassword,
    'maximum configurable Unicode minimum cannot be fulfilled safely'
);
$assert(
    estab_password_policy_requirements_text($strictPolicy)
        === 'Mindestens 8 Zeichen; zusätzlich mindestens einen Großbuchstaben, '
            . 'einen Kleinbuchstaben, eine Ziffer und ein Sonderzeichen. '
            . 'Steuerzeichen sind nicht erlaubt.',
    'strict password-policy guidance does not describe every enforced class'
);
foreach ([
    'abcdef١!',
    'ÄBCDEF١!',
    'Äbcdefg!',
    'Äbcdef١ ',
] as $missingClass) {
    $assert(
        $throwsPolicyInput(
            static fn (): string => estab_password_policy_validate_password(
                $missingClass,
                $missingClass,
                $strictPolicy
            )
        ),
        'password missing a required Unicode character class was accepted'
    );
}
foreach ([
    ['Äbcdef١!', 'Äbcdef١?'],
    ["Abcdef1\0!", "Abcdef1\0!"],
    ["Abcdef1\n!", "Abcdef1\n!"],
    ["Abcdef1\xFF!", "Abcdef1\xFF!"],
    [
        str_repeat('a', ESTAB_PASSWORD_POLICY_MAXIMUM_BYTES + 1),
        str_repeat('a', ESTAB_PASSWORD_POLICY_MAXIMUM_BYTES + 1),
    ],
] as [$password, $confirmation]) {
    $assert(
        $throwsPolicyInput(
            static fn (): string => estab_password_policy_validate_password(
                $password,
                $confirmation,
                $strictPolicy
            )
        ),
        'mismatched, invalid or oversized new password was accepted'
    );
}
$assert(
    $throwsPolicyInput(
        static fn (): string => estab_password_policy_validate_password(
            ['not-a-password'],
            ['not-a-password'],
            $strictPolicy
        )
    ),
    'non-string password was accepted'
);

$normalised = estab_password_policy_normalize_row([
    'singleton_id' => '1',
    'minimum_length' => '16',
    'require_uppercase' => '1',
    'require_lowercase' => 0,
    'require_digit' => true,
    'require_symbol' => false,
    'revision' => '7',
    'updated_at' => '2026-08-01 12:00:00.123456',
    'updated_by' => 'admin@example.test',
]);
$assert(
    $normalised['minimum_length'] === 16
        && $normalised['require_uppercase'] === true
        && $normalised['require_lowercase'] === false
        && $normalised['require_digit'] === true
        && $normalised['require_symbol'] === false
        && $normalised['revision'] === 7,
    'valid database password-policy row was not normalised exactly'
);
$assert(
    $throwsRuntime(
        static fn (): array => estab_password_policy_normalize_row([
            'singleton_id' => 1,
            'minimum_length' => 12,
            'require_uppercase' => 'true',
            'require_lowercase' => 0,
            'require_digit' => 0,
            'require_symbol' => 0,
            'revision' => 0,
            'updated_at' => '',
            'updated_by' => 'migration',
        ])
    ),
    'ambiguous database boolean was accepted'
);

$lockName = estab_password_policy_lock_name('estab');
$assert(
    $lockName === 'estab:password-policy:'
        . substr(hash('sha256', 'estab'), 0, 40)
        && strlen($lockName) <= 64
        && !hash_equals($lockName, estab_password_policy_lock_name('estab2')),
    'password-policy advisory-lock namespace is unstable or database-agnostic'
);
$assert(
    estab_password_policy_actor('admin@example.test') === 'admin@example.test'
        && estab_password_policy_actor("admin\nforged") === 'unknown'
        && estab_password_policy_actor("admin\xFF") === 'unknown'
        && estab_password_policy_actor(null) === 'unknown',
    'unsafe password-policy audit actor reached the log boundary'
);
$before = array_replace($defaults, ['revision' => 4]);
$after = array_replace($defaults, [
    'minimum_length' => 16,
    'require_uppercase' => true,
    'revision' => 5,
]);
$audit = estab_password_policy_audit_details(
    $before,
    $after,
    'admin@example.test',
    '192.0.2.19'
);
$decodedAudit = json_decode($audit, true, 8, JSON_THROW_ON_ERROR);
$assert(
    $decodedAudit === [
        'version' => 1,
        'action' => 'password_policy_updated',
        'admin' => 'admin@example.test',
        'remote_address' => '192.0.2.19',
        'before_revision' => 4,
        'after_revision' => 5,
        'before' => estab_password_policy_configuration($before),
        'after' => estab_password_policy_configuration($after),
    ]
        && !str_contains($audit, 'cleartext-sentinel')
        && !str_contains($audit, 'hash-sentinel')
        && !str_contains($audit, 'secret-sentinel'),
    'password-policy audit is incomplete or contains credential fields'
);

$root = dirname(__DIR__, 2);
$policySource = file_get_contents($root . '/app/password_policy.php');
$userAdminSource = file_get_contents($root . '/app/user_admin.php');
$loginSource = file_get_contents($root . '/4fach/data_hndl.php');
$loginPage = file_get_contents($root . '/4fach/mainindex.php');
$userPage = file_get_contents($root . '/4fadm/users.php');
$policyPage = file_get_contents($root . '/4fadm/password_policy.php');
$adminPage = file_get_contents($root . '/4fadm/admin.php');
$dockerfile = file_get_contents($root . '/Dockerfile');
$runtimeVerifier = file_get_contents(
    $root . '/docker/app/verify-runtime-surface.sh'
);
$clientValidator = file_get_contents($root . '/estab-password-policy.js');
foreach ([
    $policySource,
    $userAdminSource,
    $loginSource,
    $loginPage,
    $userPage,
    $policyPage,
    $adminPage,
    $dockerfile,
    $runtimeVerifier,
    $clientValidator,
] as $source) {
    $assert(is_string($source), 'password-policy source is unreadable');
}

$createSource = $functionSource(
    (string) $userAdminSource,
    'estab_user_admin_create_account'
);
$resetSource = $functionSource(
    (string) $userAdminSource,
    'estab_user_admin_reset_password'
);
$createPasswordPolicy = strpos(
    $createSource,
    'estab_password_policy_acquire_lock('
);
$createPasswordLoad = strpos($createSource, 'estab_password_policy_load(');
$createAccountLock = strpos(
    $createSource,
    'estab_user_admin_acquire_account_lock('
);
$resetPasswordPolicy = strpos(
    $resetSource,
    'estab_password_policy_acquire_lock('
);
$resetPasswordLoad = strpos($resetSource, 'estab_password_policy_load(');
$resetAccountLock = strpos(
    $resetSource,
    'estab_user_admin_acquire_account_lock('
);
$assert(
    str_contains((string) $userAdminSource, "require_once __DIR__ . '/password_policy.php'")
        && $createSource !== ''
        && $createPasswordPolicy !== false
        && $createPasswordLoad !== false
        && $createAccountLock !== false
        && $createPasswordPolicy < $createPasswordLoad
        && $createPasswordLoad < $createAccountLock
        && str_contains($createSource, 'estab_password_policy_release_lock(')
        && $resetSource !== ''
        && $resetPasswordPolicy !== false
        && $resetPasswordLoad !== false
        && $resetAccountLock !== false
        && $resetPasswordPolicy < $resetPasswordLoad
        && $resetPasswordLoad < $resetAccountLock
        && str_contains($resetSource, 'estab_password_policy_release_lock('),
    'account create/reset does not enforce a locked central password policy'
);

$loginStart = strpos((string) $loginSource, 'function check_save_user (');
$loginEnd = strpos(
    (string) $loginSource,
    '} // function save_user',
    $loginStart === false ? 0 : $loginStart
);
$loginBody = $loginStart !== false && $loginEnd !== false
    ? substr((string) $loginSource, $loginStart, $loginEnd - $loginStart)
    : '';
$existingBranch = strpos($loginBody, 'if (is_array ($dbUser))');
$existingVerify = strpos($loginBody, 'estab_auth_verify_password');
$registrationValidation = strpos(
    $loginBody,
    'estab_password_policy_validate_password ('
);
$assert(
    $loginBody !== ''
        && str_contains(
            (string) $loginSource,
            'require_once __DIR__ . "/../app/password_policy.php"'
        )
        && $existingBranch !== false
        && $existingVerify !== false
        && $registrationValidation !== false
        && $existingBranch < $existingVerify
        && $existingVerify < $registrationValidation
        && str_contains($loginBody, 'estab_password_policy_acquire_lock (')
        && str_contains($loginBody, 'estab_password_policy_load ($connection)')
        && str_contains(
            $loginBody,
            'EstabPasswordPolicyInputException'
        )
        && str_contains(
            $loginBody,
            'EstabPasswordPolicyBusyException'
        )
        && str_contains(
            $loginBody,
            'EstabSelfRegistrationBusyException'
        )
        && str_contains($loginBody, 'estab_password_policy_release_lock ('),
    'self-registration bypasses policy or existing logins are policy-gated'
);

$assert(
    str_contains((string) $loginPage, 'estab_password_policy_load (')
        && str_contains(
            (string) $loginPage,
            'estab_password_policy_requirements_text ('
        )
        && str_contains(
            (string) $loginPage,
            '$registrationPasswordPolicy ["minimum_length"]'
        )
        && str_contains((string) $userPage, 'estab_password_policy_load(')
        && str_contains(
            (string) $userPage,
            'estab_password_policy_requirements_text($passwordPolicy)'
        )
        && str_contains(
            (string) $userPage,
            '$passwordPolicy[\'minimum_length\']'
        )
        && str_contains(
            (string) $loginPage,
            'data-estab-password-minimum-codepoints'
        )
        && str_contains(
            (string) $userPage,
            'data-estab-password-minimum-codepoints'
        )
        && str_contains(
            (string) $loginPage,
            '../estab-password-policy.js'
        )
        && str_contains(
            (string) $userPage,
            '../estab-password-policy.js'
        )
        && is_string($clientValidator)
        && str_contains($clientValidator, 'for (var character of value)')
        && str_contains($clientValidator, 'setCustomValidity(')
        && str_contains($clientValidator, "addEventListener('submit'")
        && str_contains(
            $clientValidator,
            '[data-estab-password-minimum-codepoints]'
        ),
    'password forms do not display and mirror the active server-side policy'
);
$assert(
    str_contains((string) $policyPage, 'estab_admin_require_http_auth($_SERVER)')
        && str_contains(
            (string) $policyPage,
            'estab_csrf_require_post($_SERVER, $_POST)'
        )
        && str_contains(
            (string) $policyPage,
            'estab_password_policy_configuration_from_request('
        )
        && str_contains(
            (string) $policyPage,
            'estab_password_policy_revision('
        )
        && str_contains((string) $policyPage, 'estab_password_policy_update(')
        && str_contains(
            (string) $policyPage,
            "header('Location: password_policy.php', true, 303)"
        )
        && !str_contains((string) $policyPage, 'type="password"')
        && !str_contains((string) $policyPage, '$_GET')
        && str_contains((string) $adminPage, "'href' => 'password_policy.php'"),
    'policy administration is not Basic-Auth, POST, CSRF, revision and PRG bound'
);
$assert(
    str_contains((string) $policySource, 'SELECT GET_LOCK(?, ?)')
        && str_contains((string) $policySource, 'SELECT RELEASE_LOCK(?)')
        && str_contains((string) $policySource, 'FOR UPDATE')
        && str_contains((string) $policySource, '`revision` = `revision` + 1')
        && str_contains((string) $policySource, 'estab_auth_log_event(')
        && str_contains((string) $policySource, '$connection->commit()')
        && str_contains((string) $policySource, '$connection->rollback()'),
    'policy update omits locking, revision control, transaction or audit'
);
$assert(
    str_contains((string) $userAdminSource, 'estab_auth_hash_password(')
        && str_contains((string) $loginSource, 'estab_auth_hash_password (')
        && !str_contains((string) $userAdminSource, 'PASSWORD_DEFAULT')
        && !str_contains((string) $loginSource, 'PASSWORD_DEFAULT')
        && str_contains((string) $dockerfile, 'PASSWORD_ARGON2ID')
        && str_contains(
            (string) $dockerfile,
            'Argon2id password verification is unsafe'
        ),
    'new password writes do not require non-truncating Argon2id support'
);
$passwordHashCallers = [];
$productionPhpFiles = glob($root . '/*.php') ?: [];
foreach ([
    '4fach', '4fadm', '4fbak', '4fcfg', '4fueltg', 'app', 'fmtbb',
    'handbuch', 'stabinfo', 'stabetb',
] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root . '/' . $directory,
            FilesystemIterator::SKIP_DOTS
        )
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $productionPhpFiles[] = $file->getPathname();
        }
    }
}
foreach (array_unique($productionPhpFiles) as $file) {
    $source = file_get_contents($file);
    if (
        is_string($source)
        && preg_match('/\bpassword_hash\s*\(/i', $source) === 1
    ) {
        $passwordHashCallers[] = ltrim(substr($file, strlen($root)), '/');
    }
}
sort($passwordHashCallers);
$assert(
    $passwordHashCallers === ['app/auth.php'],
    'a production password write bypasses the central Argon2id boundary: '
        . implode(', ', $passwordHashCallers)
);
$assert(
    str_contains((string) $dockerfile, 'COPY app/*.php ./app/')
        && str_contains((string) $dockerfile, '4fadm/password_policy.php')
        && str_contains((string) $runtimeVerifier, 'app/password_policy.php')
        && str_contains((string) $runtimeVerifier, '4fadm/password_policy.php')
        && str_contains(
            (string) $runtimeVerifier,
            'estab-password-policy.js'
        ),
    'container image does not ship and verify the password-policy boundary'
);

printf("Password-policy security checks: OK (%d assertions)\n", $assertions);
