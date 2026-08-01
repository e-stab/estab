#!/bin/sh
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
php_bin=${PHP_BIN:-php}
failed=0
checked=0

while IFS= read -r file; do
    checked=$((checked + 1))
    if ! output=$($php_bin -l "$file" 2>&1); then
        printf '%s\n' "$output" >&2
        failed=1
    elif printf '%s\n' "$output" | grep -q '^Deprecated:'; then
        printf '%s\n' "$output" >&2
        failed=1
    fi
done <<EOF
$(find "$repo_root" -type f -name '*.php' \
    -not -path "$repo_root/.git/*" \
    -not -path "$repo_root/docs/*" \
    -not -path "$repo_root/migration/*" \
    -not -path "$repo_root/tmp/*" \
    -not -path "$repo_root/var/*" \
    -not -path "$repo_root/vendor/*" \
    | LC_ALL=C sort)
EOF

if [ "$failed" -ne 0 ]; then
    exit 1
fi

$php_bin "$repo_root/tools/modernize_php_tokens.php"
$php_bin "$repo_root/tools/check_legacy_constructors.php"
$php_bin "$repo_root/tests/php/runtime_compatibility.php"
$php_bin "$repo_root/tests/php/config_security.php"
$php_bin "$repo_root/tests/php/provenance_security.php"
$php_bin "$repo_root/tests/php/date_compatibility.php"
$php_bin "$repo_root/tests/php/auth_security.php"
$php_bin "$repo_root/tests/php/assignment_policy_security.php"
$php_bin "$repo_root/tests/php/user_admin_security.php"
$php_bin "$repo_root/tests/php/navigation_security.php"
$php_bin "$repo_root/tests/php/session_ui_security.php"
$php_bin "$repo_root/tests/php/sidebar_ui_security.php"
$php_bin "$repo_root/tests/php/audio_asset_security.php"
$php_bin "$repo_root/tests/php/tool_ui_security.php"
$php_bin "$repo_root/tests/php/root_menu_security.php"
$php_bin "$repo_root/tests/php/ui_encoding_security.php"
$php_bin "$repo_root/tests/php/bos_info_ui_security.php"
$php_bin "$repo_root/tests/php/workflow_security.php"
$php_bin "$repo_root/tests/php/message_security.php"
$php_bin "$repo_root/tests/php/message_list_filter_security.php"
$php_bin "$repo_root/tests/php/message_list_ui_security.php"
$php_bin "$repo_root/tests/php/workflow_form_rehydration_security.php"
$php_bin "$repo_root/tests/php/message_form_fields_security.php"
$php_bin "$repo_root/tests/php/official_message_form_ui_security.php"
$php_bin "$repo_root/tests/php/message_priority_security.php"
$php_bin "$repo_root/tests/php/read_authorization_security.php"
$php_bin "$repo_root/tests/php/message_suggestion_security.php"
$php_bin "$repo_root/tests/php/message_transport_security.php"
$php_bin "$repo_root/tests/php/ldf_validation_security.php"
$php_bin "$repo_root/tests/php/ldf_ui_flow_security.php"
$php_bin "$repo_root/tests/php/exercise_leadership_view_security.php"
$php_bin "$repo_root/tests/php/csrf_security.php"
$php_bin "$repo_root/tests/php/admin_operations_security.php"
$php_bin "$repo_root/tests/php/category_security.php"
$php_bin "$repo_root/tests/php/logbook_security.php"
$php_bin "$repo_root/tests/php/dv_evidence_security.php"
$php_bin "$repo_root/tests/php/dv_operations_security.php"
$php_bin "$repo_root/tests/php/upload_security.php"
$php_bin "$repo_root/tests/php/attachment_security.php"
$php_bin "$repo_root/tests/php/file_access_security.php"
$php_bin "$repo_root/tests/php/generated_form_security.php"
$php_bin "$repo_root/tests/php/http_surface_security.php"
$php_bin "$repo_root/tests/php/export_security.php"
$php_bin "$repo_root/tests/php/incident_domain_security.php"
$php_bin "$repo_root/tests/php/operational_incident_scope.php"
$php_bin "$repo_root/tests/php/incident_ui_security.php"
$php_bin "$repo_root/tests/php/incident_pdf_security.php"
$php_bin "$repo_root/tests/php/incident_export_security.php"
$php_bin "$repo_root/tests/php/schema_migration_contract.php"
$php_bin "$repo_root/tests/php/admin_secret_isolation_contract.php"
$php_bin "$repo_root/tests/php/registry_deployment_contract.php"
sh "$repo_root/tests/static/backup_verifier.sh"
sh "$repo_root/tests/static/backup_operator.sh"
sh "$repo_root/tests/static/restore_operator.sh"
sh "$repo_root/tests/static/registry_release.sh"
sh "$repo_root/tests/static/offline_images.sh"
sh "$repo_root/tests/static/release_policy.sh"
sh "$repo_root/tests/static/runtime_image_surface.sh"
sh "$repo_root/tests/static/pdf_temp_cleanup.sh"
pdf_result=$($php_bin "$repo_root/tests/php/pdf_smoke.php" 2>&1)
printf '%s\n' "$pdf_result"
printf '%s\n' "$pdf_result" | grep -q '^PDF smoke test: OK ('

printf 'PHP static suite: OK (%s files linted)\n' "$checked"
