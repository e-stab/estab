#!/bin/sh

set -eu

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
dockerfile=$repo_root/Dockerfile
verifier=$repo_root/docker/app/verify-runtime-surface.sh
admin_initializer=$repo_root/docker/app/init-admin-auth.sh
app_entrypoint=$repo_root/docker/app/entrypoint.sh
pdf_temp_cleanup=$repo_root/docker/app/cleanup-pdf-render-tmp.sh
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
sh -n "$admin_initializer"
sh -n "$app_entrypoint"
sh -n "$pdf_temp_cleanup"
for writable_root_contract in \
    'prepare_writable_directory "$app_data_root"' \
    'prepare_writable_directory "$export_root"' \
    'prepare_writable_directory "$data_root"' \
    'prepare_writable_directory "$data_root/anhang"' \
    'prepare_writable_directory "$data_root/vordruck"' \
    'prepare_writable_directory /var/lib/php/sessions' \
    'install -d -o www-data -g www-data -m 0770' \
    'setfacl -b -k -- "$writable_directory"' \
    'getfacl -cp -- "$writable_directory"' \
    '[ -L "$writable_directory" ]' \
    '-perm 0770' \
    '-user www-data -group www-data' \
    'setpriv --reuid=www-data --regid=www-data --clear-groups'
do
    if ! grep -Fq -- "$writable_root_contract" "$app_entrypoint"; then
        printf 'Runtime surface contract: entrypoint is missing writable-root contract %s\n' \
            "$writable_root_contract" >&2
        exit 1
    fi
done
if grep -Fq -- '/var/lib/mysql' "$app_entrypoint"; then
    printf 'Runtime surface contract: app entrypoint must not change the database mount\n' >&2
    exit 1
fi
assert_dockerfile_absent 'COPY 4fach/ ./4fach/'
assert_dockerfile_absent 'COPY 4fadm/ ./4fadm/'
assert_dockerfile_absent 'COPY 4fbak/ ./4fbak/'
assert_dockerfile_absent 'COPY 4fcfg/ ./4fcfg/'
assert_dockerfile_absent 'COPY 4fsym/ ./4fsym/'
assert_dockerfile_absent 'COPY app/ ./app/'
assert_dockerfile_absent 'COPY doku/ ./doku/'
assert_dockerfile_absent 'COPY handbuch/ ./handbuch/'
assert_dockerfile_absent 'COPY language/ ./language/'
assert_dockerfile_absent 'COPY ubltg/ ./ubltg/'
assert_dockerfile_contains 'COPY 4fadm/admin.php'
assert_dockerfile_contains 'estab-password-policy.js'
assert_dockerfile_contains '4fach/activity.php'
assert_dockerfile_contains '4fach/fuehrungsstelle.php'
assert_dockerfile_contains '4fadm/fuehrungsstelle.php'
assert_dockerfile_contains '4fadm/incident_export.php'
assert_dockerfile_contains '4fadm/incidents.php'
assert_dockerfile_contains '4fadm/self_registration.php'
assert_dockerfile_contains '4fadm/users.php'
assert_dockerfile_contains 'COPY 4fbak/backup.php'
assert_dockerfile_contains '4fbak/thw.png'
assert_dockerfile_contains 'COPY app/*.php ./app/'
assert_dockerfile_contains 'COPY 4fbak/fpdf/font/*.php ./4fbak/fpdf/font/'
assert_dockerfile_contains 'COPY 4fbak/fonts/NotoSerif-BoldItalic.ttf ./4fbak/fonts/'
assert_dockerfile_contains 'THIRD_PARTY_NOTICES.md /usr/share/licenses/estab/THIRD_PARTY_NOTICES.md'
assert_dockerfile_contains 'third_party/Noto-OFL-1.1.txt /usr/share/licenses/estab/Noto-OFL-1.1.txt'
assert_dockerfile_contains 'COPY handbuch/index.php'
assert_dockerfile_contains 'handbuch/handbuch.css'
assert_dockerfile_contains 'handbuch/handbuch.js'
assert_dockerfile_contains './handbuch/'
assert_dockerfile_absent 'COPY doku/Handbuch_eStab.pdf ./doku/'
assert_dockerfile_contains 'COPY docker/app/verify-runtime-surface.sh /usr/local/bin/estab-verify-runtime-surface'
assert_dockerfile_contains 'COPY docker/app/init-admin-auth.sh /usr/local/bin/estab-init-admin-auth'
assert_dockerfile_contains 'COPY docker/app/cleanup-pdf-render-tmp.sh /usr/local/bin/estab-cleanup-pdf-render-tmp'
assert_dockerfile_contains 'estab-verify-runtime-surface /var/www/html'
assert_dockerfile_contains '"fileinfo", "gd", "mbstring"'
assert_dockerfile_contains '"JPEG Support", "PNG Support", "GIF Read Support", "BMP Support"'
assert_dockerfile_contains 'PASSWORD_ARGON2ID'
assert_dockerfile_contains 'Argon2id password verification is unsafe'
assert_dockerfile_contains '!password_verify($prefix . "x", $hash)'
assert_dockerfile_contains '($info["options"] ?? null) !== $options'
assert_dockerfile_contains 'poppler-utils'
assert_dockerfile_contains 'command -v setpriv >/dev/null'
assert_dockerfile_contains 'command -v prlimit >/dev/null'
assert_dockerfile_contains 'command -v pdfinfo >/dev/null'
assert_dockerfile_contains 'command -v pdftoppm >/dev/null'
assert_dockerfile_contains 'command -v getfacl >/dev/null'
assert_dockerfile_contains 'command -v setfacl >/dev/null'
for pdf_cleanup_contract in \
    'TMPDIR=/tmp' \
    'estab-cleanup-pdf-render-tmp /tmp 1440 www-data'
do
    if ! grep -Fq -- "$pdf_cleanup_contract" "$app_entrypoint"; then
        printf 'Runtime surface contract: entrypoint is missing PDF cleanup contract %s\n' \
            "$pdf_cleanup_contract" >&2
        exit 1
    fi
done
for required_runtime_path in \
    estab-password-policy.js \
    4fach/activity.php \
    4fach/fuehrungsstelle.php \
    4fadm/fuehrungsstelle.php \
    4fadm/self_registration.php \
    4fbak/thw.png \
    4fbak/fonts/NotoSerif-BoldItalic.ttf \
    4fsym/4fach_aktiv.png \
    4fsym/adm_aktiv.png \
    4fsym/all_msg.png \
    4fsym/el80.gif \
    4fsym/etb_aktiv.png \
    4fsym/icon_handbuch.gif \
    4fsym/iuk_80.jpg \
    4fsym/iuk_hs80.png \
    4fsym/merke32.gif \
    4fsym/null.gif \
    4fsym/nw.png \
    4fsym/tbb_aktiv.png \
    app/attachment_integrity.php \
    app/dv_operations.php \
    app/dynamic_schema.php \
    app/logbook_lifecycle.php \
    app/logbook_numbering.php \
    app/message_evidence.php \
    app/message_priority.php \
    app/message_transport.php \
    app/operational_guard.php \
    app/read_authorization.php \
    app/self_registration.php \
    handbuch/index.php \
    handbuch/handbuch.css \
    handbuch/handbuch.js
do
    if ! sh "$verifier" --list-required |
        grep -Fxq -- "$required_runtime_path"; then
        printf 'Runtime surface contract: verifier is missing %s\n' \
            "$required_runtime_path" >&2
        exit 1
    fi
done

if ! sh "$verifier" --list-forbidden |
    grep -Fxq -- 'doku/Handbuch_eStab.pdf'; then
    printf '%s\n' \
        'Runtime surface contract: historical handbook PDF is not forbidden' >&2
    exit 1
fi

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

mkdir -p "$fixture/doku"
printf 'historical handbook pdf\n' >"$fixture/doku/Handbuch_eStab.pdf"
if sh "$verifier" "$fixture" >/dev/null 2>&1; then
    printf 'Runtime surface contract: historical handbook PDF was not rejected\n' >&2
    exit 1
fi
rm -f "$fixture/doku/Handbuch_eStab.pdf"

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
