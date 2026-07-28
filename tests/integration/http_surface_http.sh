#!/bin/sh

set -eu

base_url=${ESTAB_TEST_BASE_URL:-http://127.0.0.1:8080}
expected_app_root=${ESTAB_TEST_EXPECTED_APP_ROOT:-}
if [ -z "$expected_app_root" ]; then
    expected_app_root=${ESTAB_PUBLIC_URL:-/}
    expected_app_root=${expected_app_root%/}
    expected_base_path=${ESTAB_BASE_PATH:-}
    expected_base_path=${expected_base_path#/}
    expected_base_path=${expected_base_path%/}
    if [ -n "$expected_base_path" ]; then
        expected_app_root="$expected_app_root/$expected_base_path"
    fi
else
    expected_app_root=${expected_app_root%/}
fi
work_dir=$(mktemp -d /tmp/estab-http-surface.XXXXXX)
trap 'rm -rf -- "$work_dir"' EXIT HUP INT TERM
body=$work_dir/body
headers=$work_dir/headers

request_status() {
    curl --silent --show-error --max-time 20 --connect-timeout 5 \
        --dump-header "$headers" --output "$body" --write-out '%{http_code}' "$@"
}

assert_status() {
    expected=$1
    shift
    actual=$(request_status "$@")
    if [ "$actual" != "$expected" ]; then
        printf 'HTTP surface: expected %s, got %s for %s\n' "$expected" "$actual" "$*" >&2
        sed -n '1,40p' "$body" >&2
        exit 1
    fi
}

assert_body_fixed() {
    expected=$1
    if ! grep -Fq -- "$expected" "$body"; then
        printf 'HTTP surface: response does not contain %s\n' "$expected" >&2
        sed -n '1,40p' "$body" >&2
        exit 1
    fi
}

assert_no_insecure_resource() {
    if grep -Fiq 'http://' "$body"; then
        printf 'HTTP surface: stabinfo response still embeds an insecure remote resource\n' >&2
        exit 1
    fi
}

assert_nonempty_200() {
    assert_status 200 "$@"
    if [ ! -s "$body" ]; then
        printf 'HTTP surface: empty public asset for %s\n' "$*" >&2
        exit 1
    fi
}

assert_png() {
    assert_status 200 "$@"
    if ! grep -Eiq '^Content-Type:[[:space:]]*image/png([[:space:]]*;.*)?[[:space:]]*$' "$headers"; then
        printf 'HTTP surface: successful bitmap has no image/png content type for %s\n' "$*" >&2
        sed -n '1,30p' "$headers" >&2
        exit 1
    fi
    signature=$(od -An -tx1 -N8 "$body" | tr -d ' \n')
    if [ "$signature" != '89504e470d0a1a0a' ]; then
        printf 'HTTP surface: invalid PNG signature %s for %s\n' "$signature" "$*" >&2
        exit 1
    fi
}

# Every visible root-menu entry is exercised here or by the authenticated
# smoke/logbook/admin suites. This read-only part verifies the public shell,
# its frame documents and every repository-owned static asset.
assert_status 200 "$base_url/"
for label in \
    'Nachrichtenvordruck' \
    'Generierte Vordrucke' \
    'Liste aller Meldungen' \
    'Infosammlung BOS' \
    'Administration' \
    'Einsatztagebuch' \
    'Technisches Betriebsbuch' \
    'Nachweisung' \
    'Kurzanleitung zur eStab Installation'
do
    assert_body_fixed "$label"
done
assert_body_fixed 'id="estab-login"'
assert_body_fixed "href=\"$expected_app_root/4fach/index.php?login_flow=existing\""
assert_body_fixed '>Mit bestehendem Konto anmelden</a>'
assert_body_fixed 'id="estab-register"'
assert_body_fixed "href=\"$expected_app_root/4fach/index.php?login_flow=new\""
assert_body_fixed '>Neues Konto anlegen</a>'
assert_body_fixed 'Anmeldung erforderlich'
assert_body_fixed 'Separater Administrationszugang'
if grep -Fq 'href="./stabetb/etb.php"' "$body"; then
    printf 'HTTP surface: anonymous root menu exposes a protected module target\n' >&2
    exit 1
fi
if grep -Fq 'data-estab-session-bar' "$body"; then
    printf 'HTTP surface: anonymous root page contains authenticated session UI\n' >&2
    exit 1
fi
if grep -Fq 'name="kennwort1"' "$body"; then
    printf 'HTTP surface: root page unexpectedly contains a credential form\n' >&2
    exit 1
fi

for asset in \
    /favicon.ico \
    /estab-ui.css \
    /4fsym/el80.gif \
    /4fsym/iuk_80.jpg \
    /4fsym/4fach_aktiv.png \
    /4fach/design/mr/folder_global.gif \
    /4fsym/all_msg.png \
    /4fsym/merke32.gif \
    /4fsym/adm_aktiv.png \
    /4fsym/etb_aktiv.png \
    /4fsym/tbb_aktiv.png \
    /4fsym/nw.png \
    /4fsym/icon_handbuch.gif \
    /4fsym/null.gif \
    /doku/Handbuch_eStab.pdf
do
    assert_nonempty_200 "$base_url$asset"
done

assert_status 200 "$base_url/4fach/index.php"
for frame in './counter.php?embedded=1' './vorgaben.php' './status.php?embedded=1' './mainindex.php'; do
    assert_body_fixed "$frame"
done
assert_status 200 "$base_url/4fach/index.php?login_flow=existing"
assert_body_fixed 'SRC="./mainindex.php?login_flow=existing"'
assert_status 200 "$base_url/4fach/index.php?login_flow=new"
assert_body_fixed 'SRC="./mainindex.php?login_flow=new"'
assert_status 400 "$base_url/4fach/index.php?login_flow=unknown"
assert_nonempty_200 "$base_url/4fach/counter.php"
if grep -Fq 'data-estab-session-bar' "$body"; then
    printf 'HTTP surface: anonymous counter contains authenticated session UI\n' >&2
    exit 1
fi
assert_status 200 "$base_url/4fach/vorgaben.php"
assert_body_fixed 'm_text=anmelden'
if grep -Fq 'data-estab-session-bar' "$body"; then
    printf 'HTTP surface: anonymous navigation contains authenticated session UI\n' >&2
    exit 1
fi
assert_status 200 "$base_url/4fach/mainindex.php"
assert_body_fixed 'eStab-Funktionskonto'
assert_body_fixed 'Wie möchten Sie fortfahren?'
assert_body_fixed 'name="login_flow" value="existing"'
assert_body_fixed 'name="login_flow" value="new"'
assert_body_fixed 'name="csrf_token"'
if grep -Fq 'data-estab-session-bar' "$body"; then
    printf 'HTTP surface: anonymous login page contains authenticated session UI\n' >&2
    exit 1
fi

assert_status 200 "$base_url/4fach/mainindex.php?login_flow=existing"
assert_body_fixed 'Mit bestehendem Konto anmelden'
assert_body_fixed 'autocomplete="current-password"'
assert_status 200 "$base_url/4fach/mainindex.php?login_flow=new"
assert_body_fixed 'Neues Funktionskonto anlegen'
assert_body_fixed 'name="kennwort2"'
assert_status 403 "$base_url/4fach/mainindex.php?login_flow=unknown"

assert_status 405 "$base_url/4fach/logout.php"
assert_status 403 --request POST \
    --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"

assert_status 200 --request POST --data-urlencode 'login_flow=existing' \
    "$base_url/4fach/mainindex.php"
assert_body_fixed 'Mit bestehendem Konto anmelden'
assert_body_fixed 'name="login_flow" value="existing"'
assert_body_fixed 'autocomplete="current-password"'
assert_body_fixed '<option value="" disabled selected>Bitte Funktion wählen</option>'
assert_body_fixed 'name="csrf_token"'
if grep -Fq 'name="kennwort2"' "$body"; then
    printf 'HTTP surface: existing-account form contains password confirmation\n' >&2
    exit 1
fi

assert_status 200 --request POST --data-urlencode 'login_flow=new' \
    "$base_url/4fach/mainindex.php"
assert_body_fixed 'Neues Funktionskonto anlegen'
assert_body_fixed 'name="login_flow" value="new"'
assert_body_fixed 'name="kennwort2"'
assert_body_fixed 'Kennwort wiederholen'
assert_body_fixed 'Konto erstellen und anmelden'
assert_body_fixed 'name="csrf_token"'

assert_status 403 --request POST --data-urlencode 'login_flow=unknown' \
    "$base_url/4fach/mainindex.php"

assert_status 200 "$base_url/stabinfo/index.php"
assert_body_fixed './l_index.php'
assert_body_fixed './f_info.php'
assert_no_insecure_resource
assert_nonempty_200 "$base_url/stabinfo/f_info.php"
assert_no_insecure_resource
assert_status 200 "$base_url/stabinfo/l_index.php"
if grep -Fq 'data-estab-session-bar' "$body"; then
    printf 'HTTP surface: anonymous BOS navigation contains authenticated session UI\n' >&2
    exit 1
fi
assert_no_insecure_resource
for linked_info in \
    Buchstabier.html \
    Kartendatum.html \
    IuK-InfoPack.html \
    Orgas.html \
    FF-Rufnamenschema.html \
    DRK%20Rufnamenschema.html \
    THWFuRNR.html
do
    assert_body_fixed "$linked_info"
done
for linked_info in \
    Buchstabier.html \
    Kartendatum.html \
    IuK-InfoPack.html \
    Orgas.html \
    FF-Rufnamenschema.html \
    DRK%20Rufnamenschema.html \
    THWFuRNR.html
do
    assert_nonempty_200 "$base_url/stabinfo/$linked_info"
    assert_no_insecure_resource
    if [ "$linked_info" = 'THWFuRNR.html' ]; then
        comparison_count=$(grep -o 'aria-label="kleiner oder gleich">&le;</span>' "$body" | wc -l | tr -d ' ')
        if [ "$comparison_count" != 12 ]; then
            printf 'HTTP surface: expected 12 local THW comparison symbols, got %s\n' \
                "$comparison_count" >&2
            exit 1
        fi
    fi
done
for info_asset in \
    ELStab.jpg \
    Warendorf%20L4112%20Kartendatum%20ED50.png \
    Warendorf%20L4112%20Kartendatum%20WGS84.png
do
    assert_nonempty_200 "$base_url/stabinfo/$info_asset"
done

for path in \
    /4fach/4fachform.php \
    /4fach/color.inc.php \
    /4fach/data_hndl.php \
    /4fach/data_hndl_gespr_A.php \
    /4fach/data_hndl_gespr_E.php \
    /4fach/db_operation.php \
    /4fach/dummy.php \
    /4fach/katego.php \
    /4fach/liste.php \
    /4fach/logoff.php \
    /4fach/menue.php \
    /4fach/navi.php \
    /4fach/protokoll.php \
    /4fach/stab_status.php \
    /4fach/tools.php \
    /4fach/tools.php/path-info \
    /4fach/tools_neu.php \
    /4fach/topmenue.php \
    /4fach/upload_class.php \
    /4fach/vali_data.php \
    /4fbak/backup.php \
    /4fbak/backup_pdf.php \
    /4fbak/logo.png \
    /language/german/form.php \
    /language/german/helptextold.php \
    /language/german/hilfetext.php \
    /language/german/mennu.php \
    /ubltg/js_windowtwar.php \
    /4fadm/usermgr.php \
    /4fadm/usermgr.php/path-info \
    /4fadm/db_generator.php \
    /menue.inc.php \
    /4fadm/admmenue.inc.php \
    /4fadm/admmenue.inc.php/path-info
do
    assert_status 403 "$base_url$path"
done

assert_status 410 "$base_url/4fach/upload.php"
assert_status 410 "$base_url/4fach/upload/upload.php"

assert_status 200 --get \
    --data-urlencode 'sub=<script>alert(1)</script>' \
    --data-urlencode 'info=<img src=x onerror=alert(2)>' \
    "$base_url/4fach/info.php"
assert_body_fixed '&lt;script&gt;alert(1)&lt;/script&gt;'
assert_body_fixed '&lt;img src=x onerror=alert(2)&gt;'
if grep -Fq '<script>alert(1)</script>' "$body"; then
    printf 'HTTP surface: info.php reflected executable markup\n' >&2
    exit 1
fi
if ! grep -Eiq "^Content-Security-Policy:.*script-src 'self' 'unsafe-inline'" "$headers" ||
    grep -Fiq "'unsafe-eval'" "$headers"; then
    printf 'HTTP surface: CSP does not enforce the eval-free script policy\n' >&2
    sed -n '1,30p' "$headers" >&2
    exit 1
fi
assert_status 400 --get --data-urlencode 'sub[]=array' "$base_url/4fach/info.php"
assert_status 400 "$base_url/4fach/info.php?unknown=value"

assert_png "$base_url/4fach/button.php?type=icon&status=EIN&text=25&bg=lighterblue"
assert_png "$base_url/4fach/button.php?type=push&textpos=buttom&status=AUS&text=erledigt"
assert_png --get \
    --data-urlencode 'type=menue' \
    --data-urlencode 'm_text=Anhänge' \
    --data-urlencode 'm_fs=10' \
    --data-urlencode 'm_form=rund' \
    --data-urlencode 'width=99' \
    --data-urlencode 'bg=mlightblue' \
    "$base_url/4fach/button.php"
assert_png --get \
    --data-urlencode 'icontext=Datensatz freigeben' \
    --data-urlencode 'color=red' \
    "$base_url/4fach/createbutton.php"
assert_png "$base_url/4fach/kategobutton.php?icontext=ALLE&color=lightblue"

assert_status 400 "$base_url/4fach/button.php?type=radio&switches=1,A"
assert_status 400 "$base_url/4fach/button.php?type=icon&status=AN&text=1"
assert_status 400 "$base_url/4fach/button.php?type=menue&m_text=Test&m_fs=10&m_form=rund&width=321"
assert_status 400 --get --data-urlencode 'text[]=array' \
    --data-urlencode 'type=icon' --data-urlencode 'status=EIN' \
    "$base_url/4fach/button.php"
assert_status 400 "$base_url/4fach/createbutton.php?icontext=Test&color=purple"
assert_status 400 --get \
    --data-urlencode 'icontext=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' \
    --data-urlencode 'color=blue' \
    "$base_url/4fach/kategobutton.php"
assert_status 405 --request POST "$base_url/4fach/button.php"

assert_status 200 "$base_url/language/german/helptext.php?Errorart=01_medium"
assert_body_fixed 'Aufnahmevermerk'
assert_status 400 "$base_url/language/german/helptext.php"
assert_status 400 "$base_url/language/german/helptext.php?Errorart=unbekannt"
assert_status 400 --get --data-urlencode 'Errorart[]=01_medium' \
    "$base_url/language/german/helptext.php"

assert_status 200 "$base_url/4fach/status.php"
assert_body_fixed 'Status erst nach Anmeldung verfügbar.'
if grep -Eq '>LS<|>S[1-6]<|>A/W<' "$body"; then
    printf 'HTTP surface: anonymous status disclosed role occupancy\n' >&2
    exit 1
fi

printf 'HTTP surface integration: OK\n'
