#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
dockerfile=$repo_root/Dockerfile
verifier=$repo_root/docker/app/verify-runtime-surface.sh
fixture=$(mktemp -d "${TMPDIR:-/tmp}/estab-runtime-surface.XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT HUP INT TERM

assert_dockerfile_contains()
{
    needle=$1
    if ! grep -Fq -- "$needle" "$dockerfile"; then
        printf 'Runtime surface contract: Dockerfile is missing %s\n' \
            "$needle" >&2
        exit 1
    fi
}

assert_dockerfile_absent()
{
    needle=$1
    if grep -Fq -- "$needle" "$dockerfile"; then
        printf 'Runtime surface contract: Dockerfile still contains broad copy %s\n' \
            "$needle" >&2
        exit 1
    fi
}

sh -n "$verifier"
assert_dockerfile_absent 'COPY 4fach/ ./4fach/'
assert_dockerfile_absent 'COPY 4fadm/ ./4fadm/'
assert_dockerfile_absent 'COPY 4fbak/ ./4fbak/'
assert_dockerfile_absent 'COPY 4fcfg/ ./4fcfg/'
assert_dockerfile_absent 'COPY 4fsym/ ./4fsym/'
assert_dockerfile_absent 'COPY app/ ./app/'
assert_dockerfile_absent 'COPY doku/ ./doku/'
assert_dockerfile_absent 'COPY language/ ./language/'
assert_dockerfile_absent 'COPY ubltg/ ./ubltg/'
assert_dockerfile_contains 'COPY 4fadm/admin.php'
assert_dockerfile_contains '4fadm/incident_export.php'
assert_dockerfile_contains '4fadm/incidents.php'
assert_dockerfile_contains '4fadm/users.php'
assert_dockerfile_contains 'COPY 4fbak/backup.php'
assert_dockerfile_contains 'COPY app/*.php ./app/'
assert_dockerfile_contains 'COPY 4fbak/fpdf/font/*.php ./4fbak/fpdf/font/'
assert_dockerfile_contains 'COPY doku/Handbuch_eStab.pdf ./doku/'
assert_dockerfile_contains 'COPY docker/app/verify-runtime-surface.sh /usr/local/bin/estab-verify-runtime-surface'
assert_dockerfile_contains 'estab-verify-runtime-surface /var/www/html'
assert_dockerfile_contains '"fileinfo", "gd", "mbstring"'
assert_dockerfile_contains 'gd_info()["JPEG Support"]'

sh "$verifier" --list-required | while IFS= read -r relative_path; do
    mkdir -p "$fixture/$(dirname -- "$relative_path")"
    printf 'required\n' >"$fixture/$relative_path"
done

sh "$verifier" "$fixture" >/dev/null

mkdir -p "$fixture/4fadm"
printf 'legacy-user:legacy-hash\n' >"$fixture/4fadm/00.htpasswd"
if sh "$verifier" "$fixture" >/dev/null 2>&1; then
    printf 'Runtime surface contract: legacy htpasswd was not rejected\n' >&2
    exit 1
fi
rm -f "$fixture/4fadm/00.htpasswd"

mkdir -p "$fixture/4fach/design/source"
printf 'editable design source\n' >"$fixture/4fach/design/source/layout.odt"
if sh "$verifier" "$fixture" >/dev/null 2>&1; then
    printf 'Runtime surface contract: forbidden document suffix was not rejected\n' >&2
    exit 1
fi
rm -f "$fixture/4fach/design/source/layout.odt"

mkdir -p "$fixture/4fbak/fonts"
printf 'unused font\n' >"$fixture/4fbak/fonts/unused.ttf"
if sh "$verifier" "$fixture" >/dev/null 2>&1; then
    printf 'Runtime surface contract: non-allowlisted font was not rejected\n' >&2
    exit 1
fi
rm -f "$fixture/4fbak/fonts/unused.ttf"

mkdir -p "$fixture/4fdata/estab/anhang"
printf 'persisted user attachment\n' >"$fixture/4fdata/estab/anhang/lage.odt"
sh "$verifier" "$fixture" >/dev/null

printf 'Runtime image surface contract: OK\n'
