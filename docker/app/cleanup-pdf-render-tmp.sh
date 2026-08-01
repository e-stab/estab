#!/bin/sh

set -eu

temporary_root=${1:-/tmp}
minimum_age_minutes=${2:-1440}
expected_owner=${3:-www-data}

case "$temporary_root" in
    /*) ;;
    *)
        printf 'PDF renderer cleanup root must be absolute: %s\n' \
            "$temporary_root" >&2
        exit 1
        ;;
esac
if [ "$temporary_root" = / ] || [ "${temporary_root%/}" != "$temporary_root" ]; then
    printf 'PDF renderer cleanup refuses unsafe root: %s\n' \
        "$temporary_root" >&2
    exit 1
fi
case "$minimum_age_minutes" in
    ''|*[!0-9]*|0)
        printf 'PDF renderer cleanup age must be a positive number of minutes\n' \
            >&2
        exit 1
        ;;
esac
case "$expected_owner" in
    [A-Za-z0-9]*)
        case "$expected_owner" in
            *[!A-Za-z0-9_.-]*)
                printf 'PDF renderer cleanup owner is invalid\n' >&2
                exit 1
                ;;
        esac
        ;;
    *)
        printf 'PDF renderer cleanup owner is invalid\n' >&2
        exit 1
        ;;
esac
if [ -L "$temporary_root" ] || [ ! -d "$temporary_root" ]; then
    printf 'PDF renderer cleanup root must be a real directory: %s\n' \
        "$temporary_root" >&2
    exit 1
fi
if ! id "$expected_owner" >/dev/null 2>&1; then
    printf 'PDF renderer cleanup owner does not exist: %s\n' \
        "$expected_owner" >&2
    exit 1
fi

is_decimal()
{
    case "$1" in
        ''|*[!0-9]*) return 1 ;;
        *) return 0 ;;
    esac
}

is_positive_position()
{
    is_decimal "$1" || return 1
    case "$1" in
        0|0*) return 1 ;;
        *) return 0 ;;
    esac
}

is_expected_file_name()
{
    render_name=$1
    case "$render_name" in
        source-*.pdf)
            render_position=${render_name#source-}
            render_position=${render_position%.pdf}
            is_positive_position "$render_position"
            ;;
        image-*.jpg)
            render_position=${render_name#image-}
            render_position=${render_position%.jpg}
            is_positive_position "$render_position"
            ;;
        raster-*.jpg)
            render_numbers=${render_name#raster-}
            render_numbers=${render_numbers%.jpg}
            render_position=${render_numbers%%-*}
            render_page=${render_numbers#*-}
            [ "$render_page" != "$render_numbers" ] || return 1
            case "$render_page" in
                *-*) return 1 ;;
            esac
            is_positive_position "$render_position" || return 1
            is_decimal "$render_page" || return 1
            case "$render_page" in
                *[1-9]*) return 0 ;;
                *) return 1 ;;
            esac
            ;;
        *)
            return 1
            ;;
    esac
}

is_expected_regular_file()
{
    render_path=$1
    [ -L "$render_path" ] && return 1
    render_metadata=$(find "$render_path" -prune \
        -type f -user "$expected_owner" -links 1 \
        \( -perm 0600 -o -perm 0640 \) -print 2>/dev/null || true)
    [ "$render_metadata" = "$render_path" ]
}

cleanup_candidate()
(
    render_directory=$1
    render_name=${render_directory##*/}
    render_suffix=${render_name#estab-pdf-render-}

    [ "$render_suffix" != "$render_name" ] || exit 0
    [ "${#render_suffix}" -eq 32 ] || exit 0
    case "$render_suffix" in
        *[!0-9a-f]*) exit 0 ;;
    esac
    [ ! -L "$render_directory" ] || exit 0

    render_directory_metadata=$(find "$render_directory" -prune \
        -type d -user "$expected_owner" -perm 0700 \
        -mmin "+$minimum_age_minutes" -print 2>/dev/null || true)
    [ "$render_directory_metadata" = "$render_directory" ] || exit 0

    # Renderer workspaces are deliberately flat. Validate every entry before
    # unlinking even one file so an unexpected object preserves all evidence.
    for render_path in \
        "$render_directory"/* \
        "$render_directory"/.[!.]* \
        "$render_directory"/..?*
    do
        if [ ! -e "$render_path" ] && [ ! -L "$render_path" ]; then
            continue
        fi
        render_entry=${render_path##*/}
        if ! is_expected_file_name "$render_entry" || \
            ! is_expected_regular_file "$render_path"; then
            exit 0
        fi
    done

    for render_path in \
        "$render_directory"/* \
        "$render_directory"/.[!.]* \
        "$render_directory"/..?*
    do
        if [ ! -e "$render_path" ] && [ ! -L "$render_path" ]; then
            continue
        fi
        render_entry=${render_path##*/}
        is_expected_file_name "$render_entry" || exit 0
        is_expected_regular_file "$render_path" || exit 0
        rm -f -- "$render_path"
    done
    rmdir -- "$render_directory"
)

for cleanup_path in "$temporary_root"/estab-pdf-render-*
do
    if [ ! -e "$cleanup_path" ] && [ ! -L "$cleanup_path" ]; then
        continue
    fi
    cleanup_candidate "$cleanup_path"
done
