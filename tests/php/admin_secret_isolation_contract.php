<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $path): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
};
$service = static function (string $compose, string $name): string {
    $startNeedle = "\n  {$name}:\n";
    $start = strpos($compose, $startNeedle);
    if ($start === false) {
        throw new RuntimeException("Compose service is missing: {$name}");
    }
    $start += strlen($startNeedle);
    $tail = substr($compose, $start);
    if (!is_string($tail)) {
        throw new RuntimeException("Could not extract Compose service: {$name}");
    }
    if (preg_match('/\n  [a-z][a-z0-9-]*:\n|\n(?:secrets|volumes|networks):\n/', $tail, $match, PREG_OFFSET_CAPTURE) === 1) {
        return substr($tail, 0, (int) $match[0][1]);
    }
    return $tail;
};

$sourceCompose = $read($root . '/compose.yaml');
$registryCompose = $read($root . '/deploy/registry/compose.yaml');
$initializer = $read($root . '/docker/app/init-admin-auth.sh');
$entrypoint = $read($root . '/docker/app/entrypoint.sh');
$apache = $read($root . '/docker/apache/estab.conf');
$dockerfile = $read($root . '/Dockerfile');
$systemStatus = $read($root . '/4fadm/system_status.php');
$ci = $read($root . '/tests/integration/ci.sh');
$registryIntegration = $read($root . '/tests/integration/registry_compose.sh');

foreach ([
    'source' => $sourceCompose,
    'registry' => $registryCompose,
] as $label => $compose) {
    $initService = $service($compose, 'admin-auth-init');
    $appService = $service($compose, 'app');

    $assert(
        str_contains($initService, 'entrypoint:')
        && str_contains($initService, 'estab-init-admin-auth')
        && str_contains($initService, 'ESTAB_ADMIN_PASSWORD_FILE: /run/secrets/estab_admin_password')
        && str_contains(
            $initService,
            '${ESTAB_ADMIN_PASSWORD_SECRET_FILE:-./secrets/admin_password.txt}:/run/secrets/estab_admin_password:ro,Z'
        )
        && str_contains($initService, '- estab_auth:/var/lib/estab/auth:z')
        && str_contains($initService, 'network_mode: none')
        && str_contains($initService, 'restart: "no"'),
        "{$label} Compose does not isolate cleartext initialization in a networkless one-shot service"
    );
    $assert(
        str_contains($appService, 'admin-auth-init:')
        && str_contains($appService, 'condition: service_completed_successfully')
        && str_contains($appService, '- estab_auth:/run/estab-auth:ro,z')
        && !str_contains($appService, 'estab_admin_password')
        && !str_contains($appService, 'ESTAB_ADMIN_PASSWORD')
        && !str_contains($appService, '/run/secrets/estab_admin_password'),
        "{$label} web service can still receive the cleartext admin secret or lacks its read-only derivative"
    );
    $assert(
        str_contains($compose, "\n  estab_auth:\n"),
        "{$label} Compose does not declare the derived authentication volume"
    );
}

$assert(
    str_contains($initializer, ': "${ESTAB_ADMIN_PASSWORD_FILE:=/run/secrets/estab_admin_password}"')
    && str_contains($initializer, 'NR == 1')
    && str_contains($initializer, 'admin_password=$(tr -d')
    && str_contains($initializer, '"$admin_password_bytes" -lt 16')
    && str_contains($initializer, '"$admin_password_bytes" -gt 72')
    && str_contains($initializer, 'htpasswd -B -C 12 -c -i "$temporary_file"')
    && str_contains($initializer, 'mktemp "$auth_root/.admin.htpasswd.XXXXXX"')
    && str_contains($initializer, 'chown root:www-data "$temporary_file"')
    && str_contains($initializer, 'chmod 0640 "$temporary_file"')
    && str_contains($initializer, 'mv -fT -- "$temporary_file" "$auth_file"')
    && !str_contains($initializer, 'ESTAB_DB_PASSWORD'),
    'Admin initializer is not atomic, permission-safe, or secret-minimal'
);
$assert(
    !str_contains($entrypoint, 'load_secret ESTAB_ADMIN_PASSWORD')
    && !str_contains($entrypoint, 'ESTAB_ADMIN_PASSWORD_FILE')
    && !str_contains($entrypoint, '/run/secrets/estab_admin_password')
    && str_contains($entrypoint, 'admin_auth_file=/run/estab-auth/admin.htpasswd')
    && str_contains($entrypoint, '-user root -group www-data -perm 0640')
    && str_contains($entrypoint, 'expected_user="$ESTAB_ADMIN_USER"')
    && str_contains($entrypoint, 'cost != "12"')
    && str_contains($entrypoint, 'length($2) != 60')
    && str_contains($entrypoint, 'length(payload) != 53'),
    'Web entrypoint still consumes cleartext admin credentials or accepts an unsafe derivative'
);
$assert(
    str_contains($apache, 'AuthUserFile /run/estab-auth/admin.htpasswd')
    && !str_contains($apache, 'AuthUserFile /run/estab/admin.htpasswd')
    && str_contains(
        $dockerfile,
        'COPY docker/app/init-admin-auth.sh /usr/local/bin/estab-init-admin-auth'
    )
    && str_contains($dockerfile, '/usr/local/bin/estab-init-admin-auth'),
    'Runtime image and Apache do not use the isolated authentication initializer'
);
$assert(
    str_contains($systemStatus, "'Admin-Anmeldedatei'")
    && substr_count($systemStatus, '/run/estab-auth/admin.htpasswd') >= 2
    && !str_contains($systemStatus, "estab_env('ESTAB_ADMIN_PASSWORD_FILE')"),
    'System status still expects a cleartext admin secret in the web container'
);
$assert(
    str_contains($ci, 'verify_admin_secret_isolation')
    && str_contains($registryIntegration, 'verify_admin_secret_isolation')
    && str_contains($ci, 'Derived authentication hash does not use bcrypt cost 12')
    && str_contains(
        $registryIntegration,
        'Derived authentication hash does not use bcrypt cost 12'
    )
    && str_contains($ci, 'no-deps admin isolation: OK')
    && str_contains($registryIntegration, 'registry no-deps admin isolation: OK'),
    'Source or registry integration does not prove secret isolation and no-deps execution'
);

echo "admin secret isolation contract: OK ({$assertions} assertions)\n";
