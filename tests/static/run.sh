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
$php_bin "$repo_root/tests/php/date_compatibility.php"
$php_bin "$repo_root/tests/php/auth_security.php"
$php_bin "$repo_root/tests/php/navigation_security.php"
$php_bin "$repo_root/tests/php/session_ui_security.php"
$php_bin "$repo_root/tests/php/sidebar_ui_security.php"
$php_bin "$repo_root/tests/php/root_menu_security.php"
$php_bin "$repo_root/tests/php/ui_encoding_security.php"
$php_bin "$repo_root/tests/php/workflow_security.php"
$php_bin "$repo_root/tests/php/message_security.php"
$php_bin "$repo_root/tests/php/csrf_security.php"
$php_bin "$repo_root/tests/php/admin_operations_security.php"
$php_bin "$repo_root/tests/php/category_security.php"
$php_bin "$repo_root/tests/php/logbook_security.php"
$php_bin "$repo_root/tests/php/upload_security.php"
$php_bin "$repo_root/tests/php/attachment_security.php"
$php_bin "$repo_root/tests/php/file_access_security.php"
$php_bin "$repo_root/tests/php/http_surface_security.php"
$php_bin "$repo_root/tests/php/export_security.php"
$php_bin "$repo_root/tests/php/schema_migration_contract.php"
pdf_result=$($php_bin "$repo_root/tests/php/pdf_smoke.php" 2>&1)
printf '%s\n' "$pdf_result"
printf '%s\n' "$pdf_result" | grep -q '^PDF smoke test: OK ('

printf 'PHP static suite: OK (%s files linted)\n' "$checked"
