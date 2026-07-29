<?php

declare(strict_types=1);

/**
 * Provision one application account for the disposable integration stack.
 *
 * This deliberately uses the production user-administration domain API
 * instead of the public compatibility registration flow. The surrounding
 * shell guard restricts execution to an estab_ci Compose project, and this
 * process repeats that guard before any database write.
 */

require_once dirname(__DIR__, 2) . '/app/user_admin.php';

function provision_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Missing integration value: {$name}");
    }
    return $value;
}

if (getenv('ESTAB_TEST_PROVISION_ALLOW_MUTATION') !== 'true') {
    throw new RuntimeException('Integration provisioning mutation flag is required');
}
$project = provision_env('COMPOSE_PROJECT_NAME');
if (
    $project !== 'estab_ci'
    && !str_starts_with($project, 'estab_ci_')
) {
    throw new RuntimeException('Refusing provisioning outside an estab_ci project');
}

$config = [
    'server' => provision_env('ESTAB_DB_HOST')
        . ':' . provision_env('ESTAB_DB_PORT'),
    'user' => provision_env('ESTAB_DB_USER'),
    'password' => provision_env('ESTAB_DB_PASSWORD'),
    'datenbank' => provision_env('ESTAB_DB_NAME'),
];
$name = provision_env('ESTAB_TEST_PROVISION_NAME');
$code = provision_env('ESTAB_TEST_PROVISION_CODE');
$function = provision_env('ESTAB_TEST_PROVISION_FUNCTION');
$password = provision_env('ESTAB_TEST_PROVISION_PASSWORD');

$connection = estab_auth_connect($config);
try {
    $existing = estab_auth_fetch_user($connection, 'nv_benutzer', strtolower($code));
    if ($existing === null) {
        $result = estab_user_admin_create_account(
            $connection,
            $config['datenbank'],
            'nv_benutzer',
            'nv_protokoll',
            $name,
            $code,
            $function,
            $password,
            $password,
            'nv_empfmtx',
            'ci-provisioner',
            '127.0.0.1'
        );
        $state = 'created';
    } else {
        if (!hash_equals((string) ($existing['benutzer'] ?? ''), trim($name))) {
            throw new RuntimeException(
                'Existing integration account has a different identity'
            );
        }
        if (estab_auth_account_is_blocked($existing)) {
            estab_user_admin_set_blocked(
                $connection,
                $config['datenbank'],
                'nv_benutzer',
                'nv_protokoll',
                $code,
                false,
                'ci-provisioner',
                '127.0.0.1'
            );
        }
        $result = estab_user_admin_reassign(
            $connection,
            $config['datenbank'],
            'nv_benutzer',
            'nv_protokoll',
            $code,
            $function,
            'nv_empfmtx',
            'ci-provisioner',
            '127.0.0.1'
        );
        estab_user_admin_reset_password(
            $connection,
            $config['datenbank'],
            'nv_benutzer',
            'nv_protokoll',
            $code,
            $password,
            'ci-provisioner',
            '127.0.0.1'
        );
        $state = 'updated';
    }
    unset($password);
    printf(
        "Integration account %s: %s (%s)\n",
        $state,
        $result['kuerzel'] ?? strtolower($code),
        $result['funktion'] ?? $function
    );
} finally {
    unset($password);
    estab_auth_close($connection);
}
