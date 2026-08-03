#!/bin/sh

set -eu

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
janitor=$repo_root/docker/app/cleanup-pdf-render-tmp.sh
fixture=$(mktemp -d "${TMPDIR:-/tmp}/estab-pdf-cleanup-test.XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT HUP INT TERM
expected_owner=$(id -un)

fail()
{
    printf 'PDF renderer cleanup contract: %s\n' "$1" >&2
    exit 1
}

make_workspace()
{
    suffix=$1
    workspace=$fixture/estab-pdf-render-$suffix
    mkdir -m 0700 "$workspace"
    printf '%s\n' "$workspace"
}

mark_old()
{
    touch -t 200001010000 "$1"
}

sh -n "$janitor"

valid=$(make_workspace 00000000000000000000000000000001)
printf 'pdf\n' >"$valid/source-1.pdf"
printf 'jpeg\n' >"$valid/raster-1-01.jpg"
printf 'image\n' >"$valid/image-2.jpg"
chmod 0600 "$valid/source-1.pdf" "$valid/image-2.jpg"
chmod 0640 "$valid/raster-1-01.jpg"
mark_old "$valid"

empty=$(make_workspace 00000000000000000000000000000002)
mark_old "$empty"

recent=$(make_workspace 00000000000000000000000000000003)

unexpected=$(make_workspace 00000000000000000000000000000004)
printf 'pdf\n' >"$unexpected/source-1.pdf"
printf 'preserve\n' >"$unexpected/unexpected.txt"
chmod 0600 "$unexpected/source-1.pdf" "$unexpected/unexpected.txt"
mark_old "$unexpected"

nested=$(make_workspace 00000000000000000000000000000005)
mkdir "$nested/nested"
mark_old "$nested"

unsafe_mode=$(make_workspace 00000000000000000000000000000006)
chmod 0755 "$unsafe_mode"
mark_old "$unsafe_mode"

unsafe_file_mode=$(make_workspace 00000000000000000000000000000007)
printf 'pdf\n' >"$unsafe_file_mode/source-1.pdf"
chmod 0644 "$unsafe_file_mode/source-1.pdf"
mark_old "$unsafe_file_mode"

outside=$fixture/outside-sentinel.pdf
printf 'outside\n' >"$outside"
chmod 0600 "$outside"
linked_file=$(make_workspace 00000000000000000000000000000008)
ln -s "$outside" "$linked_file/source-1.pdf"
mark_old "$linked_file"

hardlinked_file=$(make_workspace 00000000000000000000000000000009)
ln "$outside" "$hardlinked_file/source-1.pdf"
mark_old "$hardlinked_file"

leading_zero=$(make_workspace 0000000000000000000000000000000a)
printf 'pdf\n' >"$leading_zero/source-01.pdf"
chmod 0600 "$leading_zero/source-01.pdf"
mark_old "$leading_zero"

wrong_suffix=$fixture/estab-pdf-render-0000000000000000000000000000000g
mkdir -m 0700 "$wrong_suffix"
mark_old "$wrong_suffix"

symlink_target=$fixture/symlink-target
mkdir "$symlink_target"
symlink_candidate=$fixture/estab-pdf-render-0000000000000000000000000000000b
ln -s "$symlink_target" "$symlink_candidate"
mark_old "$symlink_target"

sh "$janitor" "$fixture" 1 "$expected_owner"

[ ! -e "$valid" ] || fail 'valid old workspace was not removed'
[ ! -e "$empty" ] || fail 'valid empty old workspace was not removed'
[ -d "$recent" ] || fail 'recent workspace was removed'
[ -f "$unexpected/source-1.pdf" ] || \
    fail 'known file was removed from an unsafe workspace'
[ -f "$unexpected/unexpected.txt" ] || \
    fail 'unexpected file was removed'
[ -d "$nested/nested" ] || fail 'nested workspace content was removed'
[ -d "$unsafe_mode" ] || fail 'workspace with unsafe mode was removed'
[ -f "$unsafe_file_mode/source-1.pdf" ] || \
    fail 'file with unsafe mode was removed'
[ -L "$linked_file/source-1.pdf" ] || fail 'symlinked renderer file was removed'
[ -f "$hardlinked_file/source-1.pdf" ] || \
    fail 'hardlinked renderer file was removed'
[ -f "$leading_zero/source-01.pdf" ] || \
    fail 'non-canonical renderer filename was removed'
[ -d "$wrong_suffix" ] || fail 'workspace with invalid suffix was removed'
[ -L "$symlink_candidate" ] || fail 'symlinked workspace was removed'
[ "$(cat "$outside")" = outside ] || fail 'outside sentinel was changed'

root_target=$fixture/root-target
root_link=$fixture/root-link
mkdir "$root_target"
ln -s "$root_target" "$root_link"
if sh "$janitor" "$root_link" 1 "$expected_owner" >/dev/null 2>&1; then
    fail 'symlinked cleanup root was accepted'
fi
if sh "$janitor" / 1 "$expected_owner" >/dev/null 2>&1; then
    fail 'filesystem root was accepted'
fi
if sh "$janitor" "$fixture" 0 "$expected_owner" >/dev/null 2>&1; then
    fail 'non-positive age was accepted'
fi
if sh "$janitor" "$fixture" 1 -root >/dev/null 2>&1; then
    fail 'option-like owner was accepted'
fi

printf 'PDF renderer cleanup contract: OK\n'
