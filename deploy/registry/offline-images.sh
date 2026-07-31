#!/bin/sh

set -eu

die()
{
    printf 'eStab offline images: %s\n' "$*" >&2
    exit 1
}

usage()
{
    cat >&2 <<'EOF'
Usage:
  offline-images.sh export ABSOLUTE_ARCHIVE_DIRECTORY
  offline-images.sh verify ABSOLUTE_ARCHIVE_DIRECTORY
  offline-images.sh check-mirror ABSOLUTE_ARCHIVE_DIRECTORY REGISTRY_PREFIX
EOF
    exit 64
}

[ "$#" -ge 1 ] || usage
action=$1
shift
case "$action:$#" in
    export:1|verify:1|check-mirror:2) ;;
    *) usage ;;
esac

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
release_verifier=$script_dir/verify-release.sh
release_file=$script_dir/RELEASE
compose_file=$script_dir/compose.yaml
[ -x "$release_verifier" ] ||
    die "bound release verifier is missing or not executable"
"$release_verifier" "$script_dir" >/dev/null
[ -f "$release_file" ] && [ ! -L "$release_file" ] ||
    die "bound RELEASE record is missing"
[ -f "$compose_file" ] && [ ! -L "$compose_file" ] ||
    die "bound Compose file is missing"

for required_command in skopeo jq; do
    command -v "$required_command" >/dev/null 2>&1 ||
        die "$required_command is required"
done

if command -v sha256sum >/dev/null 2>&1; then
    checksum_command=sha256sum
elif command -v shasum >/dev/null 2>&1; then
    checksum_command='shasum -a 256'
else
    die "sha256sum or shasum is required"
fi

release_value()
{
    value_label=$1
    awk -v label="$value_label" '
      index($0, label ": ") == 1 {
        count++
        value = substr($0, length(label) + 3)
      }
      END {
        if (count != 1 || value == "" || index(value, "\r") != 0) {
          exit 1
        }
        print value
      }
    ' "$release_file"
}

release_tag=$(release_value Git-Tag) ||
    die "cannot read the bound Git-Tag"
release_commit=$(release_value Git-Commit) ||
    die "cannot read the bound Git-Commit"
app_reference=$(release_value App-Image) ||
    die "cannot read the bound application image"
migrate_reference=$(release_value Migrator-Image) ||
    die "cannot read the bound migration image"
app_digest=${app_reference##*@}
migrate_digest=${migrate_reference##*@}
database_compose_reference=$(LC_ALL=C awk '
  $0 == "services:" {
    services_count++
    in_services = 1
    in_database = 0
    next
  }
  in_services && $0 == "  db:" {
    database_count++
    in_database = 1
    next
  }
  in_database && /^  [^[:space:]]/ {
    in_database = 0
  }
  in_services && /^[^[:space:]#]/ {
    in_services = 0
    in_database = 0
  }
  in_database && /^    image:[[:space:]]*/ {
    value = $0
    sub(/^    image:[[:space:]]*/, "", value)
    sub(/[[:space:]]+$/, "", value)
    image_count++
    image = value
  }
  END {
    if (services_count != 1 || database_count != 1 ||
        image_count != 1 || image == "") {
      exit 1
    }
    print image
  }
' "$compose_file") ||
    die "Compose must contain exactly one image for services.db"

case "$app_reference:$app_digest" in
    ghcr.io/e-stab/estab@sha256:*:sha256:*) ;;
    *) die "application image is not the canonical exact digest reference" ;;
esac
case "$migrate_reference:$migrate_digest" in
    ghcr.io/e-stab/estab-migrate@sha256:*:sha256:*) ;;
    *) die "migration image is not the canonical exact digest reference" ;;
esac

database_name_and_tag=${database_compose_reference%@*}
database_digest=${database_compose_reference##*@}
[ "$database_name_and_tag" != "$database_compose_reference" ] &&
    [ "$database_digest" != "$database_compose_reference" ] &&
    [ "$database_name_and_tag@$database_digest" = \
        "$database_compose_reference" ] ||
    die "database image must contain exactly one digest separator"
case "$database_name_and_tag" in
    *@*) die "database image must contain exactly one digest separator" ;;
esac
case "$database_name_and_tag" in
    mariadb)
        database_tag=
        ;;
    mariadb:)
        die "database image tag is not canonical"
        ;;
    mariadb:*)
        database_tag=${database_name_and_tag#mariadb:}
        ;;
    docker.io/library/mariadb)
        database_tag=
        ;;
    docker.io/library/mariadb:)
        die "database image tag is not canonical"
        ;;
    docker.io/library/mariadb:*)
        database_tag=${database_name_and_tag#docker.io/library/mariadb:}
        ;;
    *)
        die "database image must use the canonical official MariaDB repository"
        ;;
esac
if [ -n "$database_tag" ]; then
    case "$database_tag" in
        [A-Za-z0-9_]*)
            case "$database_tag" in
                *[!A-Za-z0-9_.-]*)
                    die "database image tag is not canonical"
                    ;;
            esac
            ;;
        *) die "database image tag is not canonical" ;;
    esac
    [ "${#database_tag}" -le 128 ] ||
        die "database image tag exceeds the OCI tag length"
fi
case "$database_digest" in
    sha256:*) database_digest_hex=${database_digest#sha256:} ;;
    *) die "database image is not an exact sha256 digest reference" ;;
esac
case "$database_digest_hex" in
    ''|*[!0-9a-f]*)
        die "database image digest is not lowercase hexadecimal"
        ;;
esac
[ "${#database_digest_hex}" -eq 64 ] ||
    die "database image digest must contain exactly 64 hexadecimal characters"
database_reference=docker.io/library/mariadb@$database_digest

absolute_target()
{
    requested_target=$1
    target_role=$2
    if ! printf '%s\n' "$requested_target" | LC_ALL=C awk '
      NR > 1 || /[[:cntrl:]]/ { unsafe = 1 }
      END { if (NR != 1 || unsafe) exit 1 }
    '; then
        die "$target_role must not contain control characters"
    fi
    case "$requested_target" in
        /*) ;;
        *) die "$target_role must be an absolute path" ;;
    esac
    case "$requested_target" in
        *:*)
            die "$target_role must not contain ':' because OCI transport paths end at the first colon"
            ;;
    esac
    case "$requested_target" in
        /|*/|*//*|*/./*|*/../*|*/.|*/..)
            die "$target_role contains an unsafe path component"
            ;;
    esac
    target_parent=$(dirname -- "$requested_target")
    target_name=$(basename -- "$requested_target")
    case "$target_name" in
        ''|.|..) die "$target_role has an unsafe final component" ;;
    esac
    [ -d "$target_parent" ] && [ ! -L "$target_parent" ] ||
        die "$target_role parent must be an existing real directory"
    target_parent=$(CDPATH= cd -- "$target_parent" && pwd -P) ||
        die "cannot resolve $target_role parent"
    [ "$target_parent" != / ] ||
        die "$target_role parent must not be the filesystem root"
    printf '%s/%s\n' "${target_parent%/}" "$target_name"
}

portable_path_identity()
{
    identity_path=$1
    if stat -c '%d:%i:%u:%a' "$identity_path" 2>/dev/null; then
        return
    fi
    stat -f '%d:%i:%u:%Lp' "$identity_path" 2>/dev/null
}

verify_private_directory_acl()
{
    acl_path=$1
    acl_label=$2
    if command -v synoacltool >/dev/null 2>&1; then
        synology_acl_status=0
        synology_acl_output=$(LC_ALL=C synoacltool -get "$acl_path" \
            2>&1) || synology_acl_status=$?
        case "$synology_acl_output" in
            *"It's Linux mode"*|*"is Linux mode"*)
                # The caller already binds the path to the operator, inode,
                # and exact 0700 numeric mode. Stock DSM may provide neither
                # GNU ls nor getfacl, so its native Linux-mode result is the
                # terminal proof that no DSM ACL grants additional access.
                return
                ;;
            *)
                [ "$synology_acl_status" -ne 0 ] ||
                    die "$acl_label has a Synology DSM ACL"
                die "cannot prove absence of a Synology DSM ACL for $acl_label"
                ;;
        esac
    fi

    acl_system=$(uname -s 2>/dev/null) ||
        die "cannot identify ACL platform for $acl_label"
    if [ "$acl_system" = Linux ] &&
        command -v getfacl >/dev/null 2>&1; then
        acl_listing=$(LC_ALL=C getfacl -cp -- "$acl_path" 2>/dev/null) ||
            die "cannot inspect POSIX ACLs for $acl_label"
        printf '%s\n' "$acl_listing" |
            LC_ALL=C awk '
              /^$/ { next }
              $0 == "user::rwx" { owner++; next }
              $0 == "group::---" { group++; next }
              $0 == "other::---" { other++; next }
              { extended = 1 }
              END {
                if (owner != 1 || group != 1 || other != 1 ||
                    extended) exit 1
              }
            ' ||
            die "$acl_label has an extended or access-granting POSIX ACL"
        return
    fi

    case "$acl_system" in
        Darwin)
            acl_listing=$(LC_ALL=C ls -lde "$acl_path" 2>/dev/null) ||
                die "cannot inspect macOS ACL metadata for $acl_label"
            acl_mode=$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'NR == 1 { print $1 }')
            case "$acl_mode" in
                drwx------|drwx------@) ;;
                *) die "$acl_label has an extended or unsafe macOS ACL marker" ;;
            esac
            [ "$(printf '%s\n' "$acl_listing" |
                LC_ALL=C awk 'END { print NR + 0 }')" -eq 1 ] ||
                die "$acl_label has extended macOS ACL entries"
            ;;
        FreeBSD|OpenBSD|NetBSD)
            acl_mode=$(LC_ALL=C ls -ld "$acl_path" 2>/dev/null |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot inspect BSD ACL metadata for $acl_label"
            [ "$acl_mode" = drwx------ ] ||
                die "$acl_label has an extended or unsafe BSD ACL marker"
            ;;
        Linux)
            LC_ALL=C ls --version 2>/dev/null |
                grep -Fq 'GNU coreutils' ||
                die "no trusted Linux ACL probe is available for $acl_label"
            acl_mode=$(LC_ALL=C ls -ld -- "$acl_path" 2>/dev/null |
                LC_ALL=C awk 'NR == 1 { print $1 }') ||
                die "cannot inspect Linux ACL metadata for $acl_label"
            case "$acl_mode" in
                drwx------|drwx------.) ;;
                *) die "$acl_label has an extended or unsafe Linux ACL marker" ;;
            esac
            ;;
        *)
            die "no trusted ACL probe is implemented for $acl_label on $acl_system"
            ;;
    esac
}

manifest_digest()
{
    inspected_transport=$1
    manifest_file=$(mktemp "${TMPDIR:-/tmp}/estab-manifest.XXXXXX")
    if ! skopeo inspect --raw "$inspected_transport" >"$manifest_file"; then
        rm -f -- "$manifest_file"
        die "cannot inspect $inspected_transport"
    fi
    if ! jq -e '
      .schemaVersion == 2
      and (.manifests | type == "array")
      and
      ([.manifests[] | select(
        .platform.os == "linux" and .platform.architecture == "amd64"
      )] | length) == 1
      and
      ([.manifests[] | select(
        .platform.os == "linux" and .platform.architecture == "arm64"
      )] | length) == 1
    ' "$manifest_file" >/dev/null; then
        rm -f -- "$manifest_file"
        die "$inspected_transport is not the required amd64/arm64 image index"
    fi
    if ! inspected_digest=$(skopeo manifest-digest "$manifest_file"); then
        rm -f -- "$manifest_file"
        die "cannot calculate the manifest digest for $inspected_transport"
    fi
    rm -f -- "$manifest_file"
    case "$inspected_digest" in
        sha256:*) inspected_digest_hex=${inspected_digest#sha256:} ;;
        *) die "inspection returned an invalid manifest digest" ;;
    esac
    case "$inspected_digest_hex" in
        ''|*[!0-9a-f]*)
            die "inspection returned an invalid manifest digest"
            ;;
    esac
    [ "${#inspected_digest_hex}" -eq 64 ] ||
        die "inspection returned a non-canonical manifest digest"
    printf '%s\n' "$inspected_digest"
}

verify_transport()
{
    transport_role=$1
    transport_reference=$2
    expected_digest=$3
    actual_digest=$(manifest_digest "$transport_reference")
    [ "$actual_digest" = "$expected_digest" ] ||
        die "$transport_role digest differs: expected $expected_digest, got $actual_digest"
}

checksum_files()
{
    checksum_directory=$1
    shift
    (
        cd "$checksum_directory"
        if [ "$checksum_command" = sha256sum ]; then
            sha256sum "$@"
        else
            shasum -a 256 "$@"
        fi
    )
}

check_checksums()
{
    checked_directory=$1
    if [ "$checksum_command" = sha256sum ]; then
        (cd "$checked_directory" && sha256sum -c SHA256SUMS >/dev/null)
    else
        (cd "$checked_directory" && shasum -a 256 -c SHA256SUMS >/dev/null)
    fi
}

verify_archive_set()
{
    verified_directory=$1
    [ -d "$verified_directory" ] && [ ! -L "$verified_directory" ] ||
        die "archive directory must be a real directory"
    verified_directory=$(CDPATH= cd -- "$verified_directory" && pwd -P) ||
        die "cannot resolve archive directory"
    archive_entry_count=0
    for archive_entry in \
        "$verified_directory"/.[!.]* \
        "$verified_directory"/..?* \
        "$verified_directory"/*
    do
        [ -e "$archive_entry" ] || [ -L "$archive_entry" ] || continue
        archive_entry_count=$((archive_entry_count + 1))
        archive_entry_name=${archive_entry##*/}
        case "$archive_entry_name" in
            OFFLINE-IMAGES|SHA256SUMS|app.oci.tar|database.oci.tar|migrate.oci.tar)
                ;;
            *)
                die "archive directory contains an unbound entry"
                ;;
        esac
    done
    [ "$archive_entry_count" -eq 5 ] ||
        die "archive directory must contain exactly the canonical five files"
    for archive_name in \
        OFFLINE-IMAGES \
        SHA256SUMS \
        app.oci.tar \
        database.oci.tar \
        migrate.oci.tar
    do
        [ -f "$verified_directory/$archive_name" ] &&
            [ ! -L "$verified_directory/$archive_name" ] &&
            [ -r "$verified_directory/$archive_name" ] ||
            die "archive set is missing regular file: $archive_name"
    done
    manifest_names=$(awk '
      {
        digest = substr($0, 1, 64)
        separator = substr($0, 65, 2)
        name = substr($0, 67)
        if (length(digest) != 64 || digest ~ /[^0-9a-f]/ ||
            (separator != "  " && separator != " *") ||
            name == "" || index(name, "/") != 0 || seen[name]++) {
          exit 1
        }
        print name
      }
    ' "$verified_directory/SHA256SUMS") ||
        die "archive SHA256SUMS has an unsafe format"
    [ "$manifest_names" = "OFFLINE-IMAGES
app.oci.tar
database.oci.tar
migrate.oci.tar" ] ||
        die "archive SHA256SUMS must bind exactly the canonical four payload files"
    check_checksums "$verified_directory" ||
        die "an offline archive checksum differs"

    expected_metadata=$(printf '%s\n' \
        'Format: estab-offline-images-v1' \
        "Git-Tag: $release_tag" \
        "Git-Commit: $release_commit" \
        "App-Image: $app_reference" \
        'App-Archive: app.oci.tar' \
        "Migrator-Image: $migrate_reference" \
        'Migrator-Archive: migrate.oci.tar' \
        "Database-Compose-Image: $database_compose_reference" \
        "Database-Image: $database_reference" \
        'Database-Archive: database.oci.tar')
    actual_metadata=$(cat "$verified_directory/OFFLINE-IMAGES")
    [ "$actual_metadata" = "$expected_metadata" ] ||
        die "OFFLINE-IMAGES differs from the bound release identity"

    verify_transport app \
        "oci-archive:$verified_directory/app.oci.tar" "$app_digest"
    verify_transport migrator \
        "oci-archive:$verified_directory/migrate.oci.tar" "$migrate_digest"
    verify_transport database \
        "oci-archive:$verified_directory/database.oci.tar" "$database_digest"
}

validate_registry_prefix()
{
    mirror_prefix=$1
    case "$mirror_prefix" in
        ''|/*|*/|*://*|*@*|*[!a-z0-9._:/-]*)
            die "registry prefix is not a canonical lowercase registry path"
            ;;
    esac
    case "/$mirror_prefix/" in
        *'//'*|*'/./'*|*'/../'*)
            die "registry prefix contains an empty or relative path component"
            ;;
    esac
    registry_host=${mirror_prefix%%/*}
    case "$registry_host" in
        localhost|*.*|*:[0-9]*)
            ;;
        *)
            die "registry prefix must start with an explicit registry host"
            ;;
    esac
    case "$registry_host" in
        *:*)
            registry_port=${registry_host##*:}
            registry_host_name=${registry_host%:*}
            case "$registry_host_name:$registry_port" in
                :*|*:|*:*[!0-9]*)
                    die "registry prefix contains an invalid registry host or port"
                    ;;
            esac
            [ "$registry_port" -ge 1 ] 2>/dev/null &&
                [ "$registry_port" -le 65535 ] 2>/dev/null ||
                die "registry prefix contains an invalid registry port"
            ;;
    esac
}

verify_mirror()
{
    verified_archive=$1
    verified_prefix=$2
    verify_archive_set "$verified_archive"
    validate_registry_prefix "$verified_prefix"
    mirror_app_repository=$verified_prefix/estab
    mirror_migrate_repository=$verified_prefix/estab-migrate
    mirror_database_repository=$verified_prefix/estab-db
    verify_transport mirror-app-digest \
        "docker://$mirror_app_repository@$app_digest" "$app_digest"
    verify_transport mirror-migrator-digest \
        "docker://$mirror_migrate_repository@$migrate_digest" "$migrate_digest"
    verify_transport mirror-database-digest \
        "docker://$mirror_database_repository@$database_digest" "$database_digest"
}

case "$action" in
    export)
        archive_directory=$(absolute_target "$1" "archive directory")
        archive_parent=$(dirname -- "$archive_directory")
        archive_operator_uid=$(id -u) ||
            die "cannot determine archive operator UID"
        archive_parent_identity=$(portable_path_identity "$archive_parent") ||
            die "cannot inspect archive parent metadata"
        case "$archive_parent_identity" in
            *:*:"$archive_operator_uid":700) ;;
            *)
                die "archive parent must be an owner-only 0700 directory owned by the archive operator"
                ;;
        esac
        verify_private_directory_acl "$archive_parent" "archive parent"
        [ ! -e "$archive_directory" ] && [ ! -L "$archive_directory" ] ||
            die "archive directory already exists"
        if ! mkdir -m 0700 "$archive_directory"; then
            die "cannot reserve archive directory without replacing an existing target"
        fi
        archive_directory_identity=$(portable_path_identity "$archive_directory") ||
            die "cannot bind the reserved archive directory to its filesystem identity"
        case "$archive_directory_identity" in
            *:*:"$archive_operator_uid":700) ;;
            *) die "reserved archive directory has unsafe ownership or mode" ;;
        esac
        verify_reserved_archive()
        {
            [ -d "$archive_directory" ] &&
                [ ! -L "$archive_directory" ] &&
                [ "$(portable_path_identity "$archive_directory" \
                    2>/dev/null || :)" = "$archive_directory_identity" ] ||
                die "reserved archive directory changed during export"
            verify_private_directory_acl "$archive_directory" \
                "reserved archive directory"
        }
        export_marker=$archive_directory/.estab-export-in-progress
        verify_reserved_archive
        {
            printf 'Format: estab-offline-export-in-progress-v1\n'
            printf 'Git-Commit: %s\n' "$release_commit"
        } >"$export_marker" ||
            die "cannot mark the reserved archive directory as incomplete"
        verify_reserved_archive
        report_incomplete_export()
        {
            export_status=$1
            trap - EXIT HUP INT TERM
            if [ "$export_status" -ne 0 ]; then
                printf 'eStab offline images: incomplete reserved archive retained for inspection: %s\n' \
                    "$archive_directory" >&2
            fi
            exit "$export_status"
        }
        trap 'report_incomplete_export $?' EXIT
        trap 'report_incomplete_export 129' HUP
        trap 'report_incomplete_export 130' INT
        trap 'report_incomplete_export 143' TERM

        skopeo copy --all --preserve-digests \
            "docker://$app_reference" \
            "oci-archive:$archive_directory/app.oci.tar:estab-app-$release_tag"
        verify_reserved_archive
        skopeo copy --all --preserve-digests \
            "docker://$migrate_reference" \
            "oci-archive:$archive_directory/migrate.oci.tar:estab-migrate-$release_tag"
        verify_reserved_archive
        skopeo copy --all --preserve-digests \
            "docker://$database_reference" \
            "oci-archive:$archive_directory/database.oci.tar:estab-db-$release_tag"
        verify_reserved_archive
        {
            printf 'Format: estab-offline-images-v1\n'
            printf 'Git-Tag: %s\n' "$release_tag"
            printf 'Git-Commit: %s\n' "$release_commit"
            printf 'App-Image: %s\n' "$app_reference"
            printf 'App-Archive: app.oci.tar\n'
            printf 'Migrator-Image: %s\n' "$migrate_reference"
            printf 'Migrator-Archive: migrate.oci.tar\n'
            printf 'Database-Compose-Image: %s\n' \
                "$database_compose_reference"
            printf 'Database-Image: %s\n' "$database_reference"
            printf 'Database-Archive: database.oci.tar\n'
        } >"$archive_directory/OFFLINE-IMAGES"
        verify_reserved_archive
        checksum_files "$archive_directory" \
            OFFLINE-IMAGES app.oci.tar database.oci.tar migrate.oci.tar \
            >"$archive_directory/SHA256SUMS"
        verify_reserved_archive
        [ -f "$export_marker" ] && [ ! -L "$export_marker" ] ||
            die "in-progress marker changed while the archive was exported"
        rm -f -- "$export_marker" ||
            die "cannot finalize the reserved archive directory"
        verify_reserved_archive
        verify_archive_set "$archive_directory"
        trap - EXIT HUP INT TERM
        printf 'eStab offline images: exported and verified %s\n' \
            "$archive_directory"
        ;;
    verify)
        archive_directory=$(absolute_target "$1" "archive directory")
        verify_archive_set "$archive_directory"
        printf 'eStab offline images: verified %s\n' "$archive_directory"
        ;;
    check-mirror)
        archive_directory=$(absolute_target "$1" "archive directory")
        registry_prefix=$2
        verify_mirror "$archive_directory" "$registry_prefix"
        printf 'eStab offline images: mirror digests verified\n'
        ;;
esac
