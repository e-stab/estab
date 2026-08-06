#!/bin/sh

set -eu

required_paths='
index.php
health.php
estab-ui.css
estab-password-policy.js
menue.inc.php
4fach/4fachform.php
4fach/activity.php
4fach/anhang.php
4fach/button.php
4fach/createbutton.php
4fach/data_hndl.php
4fach/db_operation.php
4fach/download.php
4fach/email.php
4fach/fuehrungsstelle.php
4fach/index.php
4fach/info.php
4fach/katego.php
4fach/kategobutton.php
4fach/katgoedt.php
4fach/liste.php
4fach/logoff.php
4fach/logout.php
4fach/mainindex.php
4fach/nachwea.php
4fach/protokoll.php
4fach/resetpic.php
4fach/showpic.php
4fach/tools.php
4fach/upload.php
4fach/upload/upload.php
4fach/upload_class.php
4fach/vali_data.php
4fach/vordrucke.php
4fach/vorgaben.php
4fach/null.gif
4fach/audio/notify_aw.wav
4fach/audio/notify_si.wav
4fach/audio/notify_stab.wav
4fach/design/HS/000.gif
4fach/design/HS/attachment.gif
4fach/design/HS/cancel.gif
4fach/design/HS/ok.gif
4fach/design/HS/send.gif
4fach/design/mr/folder_global.gif
4fadm/admin.php
4fadm/export.php
4fadm/fuehrungsstelle.php
4fadm/incident_export.php
4fadm/incidents.php
4fadm/make_fkt.php
4fadm/password_policy.php
4fadm/self_registration.php
4fadm/set_number_after_crash.php
4fadm/system_status.php
4fadm/users.php
4fbak/backup.php
4fbak/backup_pdf.php
4fbak/fpdf.php
4fbak/thw.png
4fbak/fpdf/font/courier.php
4fbak/fpdf/font/helvetica.php
4fbak/fpdf/font/helveticab.php
4fbak/fpdf/font/helveticabi.php
4fbak/fpdf/font/helveticai.php
4fbak/fpdf/font/symbol.php
4fbak/fpdf/font/times.php
4fbak/fpdf/font/timesb.php
4fbak/fpdf/font/timesbi.php
4fbak/fpdf/font/timesi.php
4fbak/fpdf/font/zapfdingbats.php
4fbak/fonts/NotoSerif-BoldItalic.ttf
4fcfg/color.inc.php
4fcfg/config.inc.php
4fcfg/d_cfg.inc.php
4fcfg/dbcfg.inc.php
4fcfg/e_cfg.inc.php
4fcfg/fkt_rolle.inc.php
4fcfg/para.inc.php
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
4fueltg/ue_ltg.php
app/admin_operations.php
app/assignment.php
app/attachment.php
app/attachment_integrity.php
app/auth.php
app/bootstrap.php
app/category.php
app/csrf.php
app/datetime.php
app/dv_operations.php
app/dynamic_schema.php
app/email_attachment.php
app/export.php
app/file_access.php
app/generated_form.php
app/image_button.php
app/incident.php
app/incident_export.php
app/incident_pdf.php
app/incident_ui.php
app/legacy_mysql.php
app/legacy_php.php
app/logbook.php
app/logbook_lifecycle.php
app/logbook_numbering.php
app/message_evidence.php
app/logout.php
app/message_priority.php
app/message_repository.php
app/message_transport.php
app/navigation.php
app/operational_guard.php
app/password_policy.php
app/self_registration.php
app/readiness.php
app/read_authorization.php
app/root_menu.php
app/session_ui.php
app/sidebar.php
app/user_admin.php
app/workflow.php
handbuch/index.php
handbuch/handbuch.css
handbuch/handbuch.js
fmtbb/tbb.php
language/german/helptext.php
language/german/hilfetext.php
stabinfo/Buchstabier.html
stabinfo/DRK Rufnamenschema.html
stabinfo/ELStab.jpg
stabinfo/FF-Rufnamenschema.html
stabinfo/IuK-InfoPack.html
stabinfo/Kartendatum.html
stabinfo/Orgas.html
stabinfo/THWFuRNR.html
stabinfo/Warendorf L4112 Kartendatum ED50.png
stabinfo/Warendorf L4112 Kartendatum WGS84.png
stabinfo/documents.php
stabinfo/f_info.php
stabinfo/index.php
stabinfo/l_index.php
stabetb/etb.php
'

forbidden_paths='
config.inc.php
dbcfg.inc.php
e_cfg.inc.php
4fach/.directory
4fach/Print
4fach/all_msg.php
4fach/counter.php
4fach/color.inc.php
4fach/create_db.php
4fach/create_dir.php
4fach/css
4fach/data_hndl.nsd
4fach/data_hndl_gespr_A.php
4fach/data_hndl_gespr_E.php
4fach/design/2010preview
4fach/design/HL0001
4fach/design/HS/second.bs
4fach/design/HS/notify_aw.wav
4fach/design/HS/notify_si.wav
4fach/design/HS/notify_stab.wav
4fach/design/second
4fach/design/simple
4fach/design/Symboldesign.odt
4fach/design/Symboldesigne.odt
4fach/dummy.php
4fach/index.html
4fach/main.css
4fach/menue.php
4fach/navi.php
4fach/null.jpg
4fach/prototype.js
4fach/stab_status.php
4fach/status.php
4fach/test.php
4fach/tools_neu.php
4fach/topmenue.php
4fach/upload/abfall.php
4fach/upload/filemenue.php
4fach/upload/foto_upload.php
4fach/upload/multiple_upload_example.php
4fach/upload/upload_class.php
4fach/upload/upload_example.php
4fadm/00.htaccess
4fadm/00.htpasswd
4fadm/admmenue.inc.php
4fadm/create_db.php
4fadm/create_dir.php
4fadm/db_check.php
4fadm/db_generator.php
4fadm/jsFormular.js
4fadm/make_conf.php
4fadm/phpinfo.php
4fadm/usermgr.php
4fadm/z-doku.html
4fbak/backup_img.php
4fbak/ellipse.php
4fbak/fpdf/doc
4fbak/fpdf/ex.php
4fbak/fpdf/fpdf.php
4fbak/fpdf/tutorial
4fbak/fpdf181.zip
4fcfg/deault.fkt.php
4fcfg/echo_config.inc.php
4fcfg/edt_para.php
4fcfg/todo.txt
4fcfg/valimatrix.inc.php
4fcss
4fexch
4fueltg/favicon.ico
4fsym/famfamfam_silk_icons_v013.zip
br
doku/Tests.odt
doku/Tests.ott
doku/suhosin.odt
doku/Handbuch_eStab.pdf
language/german/form.php
language/german/helptextold.php
language/german/mennu.php
sammlung
ubltg
'

print_list()
{
    printf '%s\n' "$1" | sed '/^[[:space:]]*$/d'
}

case "${1:-}" in
    --list-required)
        print_list "$required_paths"
        exit 0
        ;;
    --list-forbidden)
        print_list "$forbidden_paths"
        exit 0
        ;;
esac

runtime_root=${1:-/var/www/html}
case "$runtime_root" in
    /*) ;;
    *)
        printf 'Runtime surface: root must be an absolute path: %s\n' \
            "$runtime_root" >&2
        exit 1
        ;;
esac

if [ ! -d "$runtime_root" ]; then
    printf 'Runtime surface: document root is missing: %s\n' "$runtime_root" >&2
    exit 1
fi

failed=0
while IFS= read -r relative_path; do
    [ -n "$relative_path" ] || continue
    if [ ! -s "$runtime_root/$relative_path" ]; then
        printf 'Runtime surface: required file is missing or empty: %s\n' \
            "$relative_path" >&2
        failed=1
    fi
done <<EOF
$(print_list "$required_paths")
EOF

while IFS= read -r relative_path; do
    [ -n "$relative_path" ] || continue
    if [ -e "$runtime_root/$relative_path" ] || [ -L "$runtime_root/$relative_path" ]; then
        printf 'Runtime surface: forbidden path is present: %s\n' \
            "$relative_path" >&2
        failed=1
    fi
done <<EOF
$(print_list "$forbidden_paths")
EOF

forbidden_suffixes=$(
    find "$runtime_root" -xdev \
        -path "$runtime_root/4fdata" -prune -o \
        -type f \( \
            -iname '*.afm' -o \
            -iname '*.bs' -o \
            -iname '*.directory' -o \
            -iname '*.htaccess' -o \
            -iname '*.htpasswd' -o \
            -iname '*.md' -o \
            -iname '*.mm' -o \
            -iname '*.nsd' -o \
            -iname '*.ods' -o \
            -iname '*.odt' -o \
            -iname '*.ott' -o \
            -iname '*.pap' -o \
            -iname '*.zip' -o \
            -iname '*.z' \
        \) -print
)
if [ -n "$forbidden_suffixes" ]; then
    printf 'Runtime surface: forbidden source/archive files are present:\n%s\n' \
        "$forbidden_suffixes" >&2
    failed=1
fi

unexpected_fonts=$(
    find "$runtime_root" -xdev \
        -path "$runtime_root/4fdata" -prune -o \
        -type f -iname '*.ttf' \
        ! -path "$runtime_root/4fbak/fonts/NotoSerif-BoldItalic.ttf" \
        -print
)
if [ -n "$unexpected_fonts" ]; then
    printf 'Runtime surface: non-allowlisted font files are present:\n%s\n' \
        "$unexpected_fonts" >&2
    failed=1
fi

unexpected_links=$(
    find "$runtime_root" -xdev \
        -path "$runtime_root/4fdata" -prune -o \
        -type l -print
)
if [ -n "$unexpected_links" ]; then
    printf 'Runtime surface: symbolic links are not allowed in the immutable tree:\n%s\n' \
        "$unexpected_links" >&2
    failed=1
fi

if [ "$failed" -ne 0 ]; then
    exit 1
fi

printf 'Runtime surface: OK\n'
