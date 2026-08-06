#!/bin/sh

set -eu

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd -P)
verifier=$repo_root/docker/app/verify-runtime-surface.sh

git -C "$repo_root" rev-parse --is-inside-work-tree >/dev/null
sh -n "$verifier"

tracked_paths=$(git -C "$repo_root" -c core.quotePath=false ls-files)
failed=0

report_tracked_path()
{
    category=$1
    path=$2
    printf 'Source tree hygiene: %s path is still tracked: %s\n' \
        "$category" "$path" >&2
    failed=1
}

# These files remain in Git for a separate compatibility review, but are still
# forbidden in the immutable runtime image. They are deliberately not treated
# as pensioned source paths by this guard.
is_deferred_source_only_path()
{
    case "$1" in
        4fbak/backup_img.php | \
        4fbak/ellipse.php | \
        4fbak/fpdf/doc | \
        4fbak/fpdf/ex.php | \
        4fbak/fpdf/fpdf.php | \
        4fbak/fpdf/tutorial | \
        4fbak/fpdf181.zip)
            return 0
            ;;
    esac
    return 1
}

while IFS= read -r forbidden_path; do
    [ -n "$forbidden_path" ] || continue
    if is_deferred_source_only_path "$forbidden_path"; then
        continue
    fi
    while IFS= read -r tracked_path; do
        case "$tracked_path" in
            "$forbidden_path" | "$forbidden_path"/*)
                report_tracked_path runtime-forbidden "$tracked_path"
                ;;
        esac
    done <<EOF
$tracked_paths
EOF
done <<EOF
$(sh "$verifier" --list-forbidden)
EOF

retired_source_paths='
.buildpath
.project
.settings
graphictests
index_201443feb2016.php
'
while IFS= read -r retired_path; do
    [ -n "$retired_path" ] || continue
    while IFS= read -r tracked_path; do
        case "$tracked_path" in
            "$retired_path" | "$retired_path"/*)
                report_tracked_path retired-source "$tracked_path"
                ;;
        esac
    done <<EOF
$tracked_paths
EOF
done <<EOF
$retired_source_paths
EOF

active_menu_assets='
4fsym/4fach_aktiv.png
4fsym/adm_aktiv.png
4fsym/all_msg.png
4fsym/el80.gif
4fsym/etb_aktiv.png
4fsym/icon_handbuch.gif
4fsym/iuk_80.jpg
4fsym/iuk_hs80.png
4fsym/merke32.gif
4fsym/null.gif
4fsym/nw.png
4fsym/tbb_aktiv.png
'

is_allowed_menu_asset()
{
    case "$1" in
        4fsym/4fach_aktiv.png | \
        4fsym/adm_aktiv.png | \
        4fsym/all_msg.png | \
        4fsym/el80.gif | \
        4fsym/etb_aktiv.png | \
        4fsym/icon_handbuch.gif | \
        4fsym/iuk_80.jpg | \
        4fsym/iuk_hs80.png | \
        4fsym/merke32.gif | \
        4fsym/nw.png | \
        4fsym/tbb_aktiv.png | \
        4fsym/br.jpg | \
        4fsym/null.gif)
            return 0
            ;;
    esac
    return 1
}

while IFS= read -r tracked_path; do
    case "$tracked_path" in
        4fsym/*)
            if ! is_allowed_menu_asset "$tracked_path"; then
                report_tracked_path non-allowlisted-menu-asset "$tracked_path"
            fi
            ;;
    esac
done <<EOF
$tracked_paths
EOF

while IFS= read -r required_asset; do
    [ -n "$required_asset" ] || continue
    if ! git -C "$repo_root" ls-files --error-unmatch -- "$required_asset" \
        >/dev/null 2>&1; then
        printf 'Source tree hygiene: active menu asset is not tracked: %s\n' \
            "$required_asset" >&2
        failed=1
    fi
done <<EOF
$active_menu_assets
EOF

active_font_asset=4fbak/fonts/NotoSerif-BoldItalic.ttf
while IFS= read -r tracked_path; do
    case "$tracked_path" in
        4fbak/fonts/*)
            if [ "$tracked_path" != "$active_font_asset" ]; then
                report_tracked_path non-allowlisted-font "$tracked_path"
            fi
            ;;
    esac
done <<EOF
$tracked_paths
EOF

if ! git -C "$repo_root" ls-files --error-unmatch -- "$active_font_asset" \
    >/dev/null 2>&1; then
    printf 'Source tree hygiene: active font is not tracked: %s\n' \
        "$active_font_asset" >&2
    failed=1
fi

while IFS= read -r tracked_path; do
    case "$tracked_path" in
        4fach/design/mr/folder_global.gif)
            ;;
        4fach/design/mr/*)
            report_tracked_path non-allowlisted-design-asset "$tracked_path"
            ;;
    esac
done <<EOF
$tracked_paths
EOF

if ! git -C "$repo_root" ls-files --error-unmatch -- \
    4fach/design/mr/folder_global.gif >/dev/null 2>&1; then
    printf '%s\n' \
        'Source tree hygiene: active design asset is not tracked: 4fach/design/mr/folder_global.gif' \
        >&2
    failed=1
fi

if [ "$failed" -ne 0 ]; then
    exit 1
fi

printf 'Source tree hygiene: OK\n'
