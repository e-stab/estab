#!/bin/sh
# Static PHP suite.
#
# Every check below is independent, so the suite runs them concurrently and
# reports all results. Aborting on the first failure used to hide the state of
# the remaining checks, which turned one defect into one unknown run.
set -u

repo_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
php_bin=${PHP_BIN:-php}

list_only=0
if [ "$#" -gt 0 ] && [ "$1" = --list ]; then
    list_only=1
fi

if [ "$list_only" -eq 0 ] && command -v git >/dev/null 2>&1; then
    if ! sh "$repo_root/tests/static/source_tree_hygiene.sh"; then
        printf '%s\n' 'PHP static suite: source tree hygiene failed' >&2
        exit 1
    fi
elif [ "$list_only" -eq 0 ] && [ "${ESTAB_SOURCE_TREE_HYGIENE_VERIFIED:-}" != 1 ]; then
    printf '%s\n' \
        'PHP static suite: source hygiene was not verified before entering the Git-free runtime' \
        >&2
    exit 1
fi

if [ -n "${ESTAB_TEST_JOBS:-}" ]; then
    jobs=$ESTAB_TEST_JOBS
elif command -v nproc >/dev/null 2>&1; then
    jobs=$(nproc)
else
    jobs=$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)
fi
case $jobs in
    ''|*[!0-9]*|0) jobs=4 ;;
esac

work=$(mktemp -d "${TMPDIR:-/tmp}/estab-static-suite.XXXXXX") || exit 2
cleanup()
{
    rm -rf -- "$work"
}
trap cleanup EXIT
trap 'cleanup; exit 130' HUP INT TERM
mkdir -p "$work/cmd" "$work/out" "$work/status"

shquote()
{
    printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

check_order=''
check_count=0

# register <id> <program> [arguments...]
register()
{
    id=$1
    shift
    line=''
    for argument in "$@"; do
        line="$line $(shquote "$argument")"
    done
    printf '%s\n' "$line" > "$work/cmd/$id"
    check_order="$check_order $id"
    check_count=$((check_count + 1))
}

# register_script <id> <shell source>
register_script()
{
    id=$1
    printf '%s\n' "$2" > "$work/cmd/$id"
    check_order="$check_order $id"
    check_count=$((check_count + 1))
}

register_php()
{
    register "$1" "$php_bin" "$repo_root/tests/php/$1.php"
}

register_shell()
{
    register "$1" sh "$repo_root/tests/static/$1.sh"
}

# OPcache turns the whole-tree lint into a single process; the tool falls back
# to per-file `php -l` when it is unavailable.
register lint_sources "$php_bin" -d opcache.enable_cli=1 \
    "$repo_root/tools/lint_sources.php"
register modernize_php_tokens "$php_bin" \
    "$repo_root/tools/modernize_php_tokens.php"
register check_legacy_constructors "$php_bin" \
    "$repo_root/tools/check_legacy_constructors.php"
register generate_notification_tones "$php_bin" \
    "$repo_root/tools/generate_notification_tones.php" --verify

for test_name in \
    runtime_compatibility \
    config_security \
    pipeline_workflow_contract \
    third_party_notice_contract \
    date_compatibility \
    auth_security \
    assignment_policy_security \
    password_policy_security \
    self_registration_security \
    user_admin_security \
    navigation_security \
    session_ui_security \
    sidebar_ui_security \
    audio_asset_security \
    tool_ui_security \
    root_menu_security \
    ui_encoding_security \
    bos_info_ui_security \
    handbook_ui_security \
    workflow_security \
    workflow_single_dispatch_security \
    message_security \
    message_list_filter_security \
    message_list_ui_security \
    list_contrast_security \
    list_style_coverage_security \
    message_timeline_security \
    message_timeline_integration_security \
    workflow_form_rehydration_security \
    message_form_fields_security \
    official_message_form_ui_security \
    message_priority_security \
    read_authorization_security \
    message_suggestion_security \
    message_transport_security \
    ldf_validation_security \
    ldf_return_security \
    ldf_ui_flow_security \
    exercise_leadership_view_security \
    csrf_security \
    admin_operations_security \
    category_security \
    logbook_security \
    dv_evidence_security \
    dv_operations_security \
    dv_rule_registry \
    telecom_plan_security \
    upload_security \
    attachment_security \
    email_attachment_security \
    file_access_security \
    generated_form_security \
    http_surface_security \
    export_security \
    incident_domain_security \
    permission_mode_security \
    permission_mode_attachment_scope_security \
    operational_incident_scope \
    incident_ui_security \
    incident_pdf_security \
    incident_export_security \
    schema_migration_contract \
    admin_secret_isolation_contract \
    registry_deployment_contract
do
    [ -f "$repo_root/tests/php/$test_name.php" ] || continue
    register_php "$test_name"
done

for shell_name in \
    backup_verifier \
    backup_operator \
    restore_operator \
    registry_release \
    offline_images \
    release_policy \
    runtime_image_surface \
    pdf_temp_cleanup
do
    register_shell "$shell_name"
done

register_script pdf_smoke "pdf_result=\$($(shquote "$php_bin") $(shquote "$repo_root/tests/php/pdf_smoke.php") 2>&1) || exit 1
printf '%s\n' \"\$pdf_result\"
printf '%s\n' \"\$pdf_result\" | grep -q '^PDF smoke test: OK ('"

if [ "$list_only" -eq 1 ]; then
    printf '%s\n' $check_order
    exit 0
fi

cat > "$work/runner.sh" <<'RUNNER'
#!/bin/sh
work=$1
id=$2
if sh "$work/cmd/$id" > "$work/out/$id" 2>&1; then
    printf '0\n' > "$work/status/$id"
else
    printf '%s\n' "$?" > "$work/status/$id"
fi
RUNNER

printf '%s\n' $check_order |
    xargs -P "$jobs" -I '{}' sh "$work/runner.sh" "$work" '{}'

failed=0
failed_ids=''
for id in $check_order; do
    status=$(cat "$work/status/$id" 2>/dev/null || echo 127)
    if [ "$status" -eq 0 ]; then
        continue
    fi
    failed=$((failed + 1))
    failed_ids="$failed_ids $id"
    printf '\n=== FAILED: %s (exit %s) ===\n' "$id" "$status" >&2
    cat "$work/out/$id" >&2
done

for id in $check_order; do
    status=$(cat "$work/status/$id" 2>/dev/null || echo 127)
    [ "$status" -eq 0 ] || continue
    cat "$work/out/$id"
done

if [ "$failed" -ne 0 ]; then
    printf '\nPHP static suite: %d of %d checks failed:%s\n' \
        "$failed" "$check_count" "$failed_ids" >&2
    exit 1
fi

printf 'PHP static suite: OK (%d checks, %d parallel)\n' "$check_count" "$jobs"
