#!/bin/sh
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

container_cli=${ESTAB_CONTAINER_CLI:-docker}
project=${ESTAB_REGISTRY_PROJECT:-estab_ci_registry}
http_port=${ESTAB_REGISTRY_HTTP_PORT:-18081}
app_image=${ESTAB_REGISTRY_APP_IMAGE:-}
migrate_image=${ESTAB_REGISTRY_MIGRATE_IMAGE:-}
compose_file=$repo_root/deploy/registry/compose.yaml

case "$container_cli" in
    docker | podman) ;;
    *) echo "Registry compose integration: unsupported container CLI" >&2; exit 1 ;;
esac
case "$project" in
    estab_ci | estab_ci_*) ;;
    *)
        echo "Registry compose integration: refusing destructive cleanup outside estab_ci" >&2
        exit 1
        ;;
esac
case "$http_port" in
    '' | *[!0-9]*)
        echo "Registry compose integration: HTTP port must be numeric" >&2
        exit 1
        ;;
esac
if [ "$http_port" -lt 1024 ] || [ "$http_port" -gt 65535 ]; then
    echo "Registry compose integration: HTTP port is outside 1024-65535" >&2
    exit 1
fi
if [ -z "$app_image" ] || [ -z "$migrate_image" ]; then
    echo "Registry compose integration: both local image references are required" >&2
    exit 1
fi
for secret_file in \
    "${ESTAB_DB_PASSWORD_SECRET_FILE:-}" \
    "${ESTAB_DB_ROOT_PASSWORD_SECRET_FILE:-}" \
    "${ESTAB_ADMIN_PASSWORD_SECRET_FILE:-}"
do
    if [ -z "$secret_file" ] || [ ! -r "$secret_file" ]; then
        echo "Registry compose integration: a secret file is unreadable" >&2
        exit 1
    fi
done

export ESTAB_APP_IMAGE=$app_image
export ESTAB_MIGRATE_IMAGE=$migrate_image
export ESTAB_HTTP_BIND=127.0.0.1
export ESTAB_HTTP_PORT=$http_port
export ESTAB_DB_DATA_SOURCE=estab_db
export ESTAB_APP_DATA_SOURCE=estab_data
export ESTAB_EXPORT_DATA_SOURCE=estab_export

compose()
{
    "$container_cli" compose -f "$compose_file" -p "$project" "$@"
}

capture_diagnostics()
{
    echo "Registry compose integration: container status" >&2
    compose ps --all >&2 || true
    echo "Registry compose integration: last logs" >&2
    compose logs --no-color --tail=200 >&2 || true
}

test_completed=0
cleanup()
{
    status=$?
    trap - EXIT HUP INT TERM
    if [ "$status" -ne 0 ]; then
        capture_diagnostics
    fi
    cleanup_failed=0
    if ! compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1; then
        echo "Registry compose integration: final Compose cleanup failed" >&2
        cleanup_failed=1
    fi
    if ! remaining_containers=$(compose ps --all -q 2>/dev/null); then
        echo "Registry compose integration: could not verify container cleanup" >&2
        cleanup_failed=1
        remaining_containers=unknown
    fi
    if ! remaining_volumes=$("$container_cli" volume ls --quiet \
        --filter "label=com.docker.compose.project=$project" 2>/dev/null); then
        echo "Registry compose integration: could not verify volume cleanup" >&2
        cleanup_failed=1
        remaining_volumes=unknown
    fi
    if [ -n "$remaining_containers" ] || [ -n "$remaining_volumes" ]; then
        echo "Registry compose integration: isolated resources remain after cleanup" >&2
        cleanup_failed=1
    fi
    if [ "$status" -eq 0 ]; then
        if [ "$cleanup_failed" -ne 0 ] || [ "$test_completed" -ne 1 ]; then
            status=1
        else
            echo "Registry compose integration: OK"
        fi
    fi
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

compose config >/dev/null
compose down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1

"$container_cli" image inspect "$app_image" >/dev/null
"$container_cli" image inspect "$migrate_image" >/dev/null

echo "Registry compose integration: starting pull-only fresh stack"
compose up --detach --pull never

deadline=$(( $(date +%s) + 240 ))
while :; do
    app_id=$(compose ps -q app 2>/dev/null || true)
    if [ -n "$app_id" ]; then
        app_status=$("$container_cli" inspect --format \
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
            "$app_id" 2>/dev/null || true)
        case "$app_status" in
            healthy) break ;;
            unhealthy | exited | dead)
                echo "Registry compose integration: app entered $app_status" >&2
                exit 1
                ;;
        esac
    fi
    if [ "$(date +%s)" -ge "$deadline" ]; then
        echo "Registry compose integration: app did not become healthy" >&2
        exit 1
    fi
    sleep 3
done

migrate_id=$(compose ps --all -q migrate)
if [ -z "$migrate_id" ]; then
    echo "Registry compose integration: one-shot migrator container is missing" >&2
    exit 1
fi
migrate_status=$("$container_cli" inspect --format \
    '{{.State.Status}} {{.State.ExitCode}}' "$migrate_id")
if [ "$migrate_status" != "exited 0" ]; then
    echo "Registry compose integration: migrator ended as $migrate_status" >&2
    exit 1
fi

expected_app_image_id=$("$container_cli" image inspect --format '{{.Id}}' "$app_image")
expected_migrate_image_id=$("$container_cli" image inspect --format '{{.Id}}' "$migrate_image")
actual_app_image_id=$("$container_cli" inspect --format '{{.Image}}' "$app_id")
actual_migrate_image_id=$("$container_cli" inspect --format '{{.Image}}' "$migrate_id")
if [ "$actual_app_image_id" != "$expected_app_image_id" ] ||
    [ "$actual_migrate_image_id" != "$expected_migrate_image_id" ]; then
    echo "Registry compose integration: stack did not use the requested images" >&2
    exit 1
fi

compose run --rm --no-deps -T migrate
curl --fail --silent --show-error --max-time 20 \
    "http://127.0.0.1:$http_port/health.php" |
    grep -Fq '"status":"ready"'

app_logs=$(compose logs --no-color app)
if printf '%s\n' "$app_logs" |
    grep -Eq 'PHP (Warning|Deprecated|Notice|Fatal error|Parse error):|Uncaught'; then
    echo "Registry compose integration: PHP runtime error found in app log" >&2
    exit 1
fi

test_completed=1
