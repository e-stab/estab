#!/bin/sh

set -eu

if [ "$#" -ne 4 ]; then
    echo 'Usage: provision_user.sh NAME CODE FUNCTION PASSWORD' >&2
    exit 2
fi

name=$1
code=$2
function_name=$3
password=$4
project_name=${COMPOSE_PROJECT_NAME:-estab}
case "$project_name" in
    estab_ci | estab_ci_*) ;;
    *)
        echo 'User provisioner: refusing mutation outside an estab_ci project' >&2
        exit 1
        ;;
esac

compose_engine=${ESTAB_TEST_COMPOSE_ENGINE:-${ESTAB_CONTAINER_CLI:-docker}}
case "$compose_engine" in
    docker | podman) ;;
    *)
        echo 'User provisioner: compose engine must be docker or podman' >&2
        exit 1
        ;;
esac

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

ESTAB_TEST_PROVISION_NAME=$name \
ESTAB_TEST_PROVISION_CODE=$code \
ESTAB_TEST_PROVISION_FUNCTION=$function_name \
ESTAB_TEST_PROVISION_PASSWORD=$password \
"$compose_engine" compose run --rm --no-deps -T \
    --env "COMPOSE_PROJECT_NAME=$project_name" \
    --env ESTAB_TEST_PROVISION_ALLOW_MUTATION=true \
    --env ESTAB_TEST_PROVISION_NAME \
    --env ESTAB_TEST_PROVISION_CODE \
    --env ESTAB_TEST_PROVISION_FUNCTION \
    --env ESTAB_TEST_PROVISION_PASSWORD \
    --volume "$repo_root:/workspace:ro" \
    --workdir /workspace \
    app php -d auto_prepend_file= tests/integration/provision_user.php
