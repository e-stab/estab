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

assert_body_absent_fixed() {
    forbidden=$1
    if grep -Fq -- "$forbidden" "$body"; then
        printf 'HTTP surface: response unexpectedly contains %s\n' "$forbidden" >&2
        sed -n '1,40p' "$body" >&2
        exit 1
    fi
}

assert_header_fixed() {
    expected=$1
    if ! grep -Fqi -- "$expected" "$headers"; then
        printf 'HTTP surface: response headers do not contain %s\n' "$expected" >&2
        sed -n '1,30p' "$headers" >&2
        exit 1
    fi
}

assert_header_regex() {
    expected=$1
    description=$2
    if ! grep -Eiq -- "$expected" "$headers"; then
        printf 'HTTP surface: response headers do not match %s\n' \
            "$description" >&2
        sed -n '1,30p' "$headers" >&2
        exit 1
    fi
}

assert_header_absent_fixed() {
    forbidden=$1
    if grep -Fqi -- "$forbidden" "$headers"; then
        printf 'HTTP surface: response headers unexpectedly contain %s\n' "$forbidden" >&2
        sed -n '1,30p' "$headers" >&2
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
    'Führungsstellenbetrieb' \
    'Meldungsübersicht' \
    'Vordrucke' \
    'BOS-Info' \
    'Administration' \
    'Einsatztagebuch' \
    'Technisches Betriebsbuch' \
    'Nachweisung' \
    'Handbuch'
do
    assert_body_fixed "$label"
done
assert_body_fixed 'id="estab-login"'
assert_body_fixed "href=\"$expected_app_root/4fach/index.php?login_flow=existing\""
assert_body_fixed '>Mit bestehendem Konto anmelden</a>'
assert_body_absent_fixed 'id="estab-register"'
assert_body_absent_fixed "href=\"$expected_app_root/4fach/index.php?login_flow=new\""
assert_body_fixed 'Neue Konten können auf dieser Installation nicht selbst angelegt werden'
assert_body_fixed 'Administration → Benutzerverwaltung'
assert_body_fixed 'Anmeldung erforderlich'
assert_body_fixed 'Separater Administrationszugang'
assert_body_fixed 'data-estab-navigation'
assert_body_fixed 'data-estab-nav-key="overview"'
assert_body_fixed 'href="./handbuch/"'
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
    /4fsym/iuk_hs80.png \
    /4fach/design/mr/folder_global.gif \
    /4fsym/all_msg.png \
    /4fsym/merke32.gif \
    /4fsym/adm_aktiv.png \
    /4fsym/etb_aktiv.png \
    /4fsym/tbb_aktiv.png \
    /4fsym/nw.png \
    /4fsym/icon_handbuch.gif \
    /4fsym/null.gif \
    /4fach/audio/notify_aw.wav \
    /4fach/audio/notify_si.wav \
    /4fach/audio/notify_stab.wav
do
    assert_nonempty_200 "$base_url$asset"
done

assert_status 200 "$base_url/handbuch/"
assert_header_fixed 'Content-Type: text/html; charset=UTF-8'
assert_header_fixed 'Cache-Control: private, no-store, max-age=0'
assert_header_fixed 'Vary: Cookie'
assert_body_fixed '<title>eStab Web-Handbuch</title>'
assert_body_fixed 'data-estab-handbook-version='
assert_body_fixed 'data-estab-handbook-search'
assert_body_fixed 'data-estab-handbook-status'
assert_body_fixed 'data-estab-handbook-toc'
assert_body_fixed 'href="./handbuch.css"'
assert_body_fixed 'src="./handbuch.js"'
assert_body_fixed 'data-estab-public-bar'
assert_body_fixed 'data-estab-nav-key="handbook" aria-current="page"'
assert_body_absent_fixed 'data-estab-session-bar'
assert_body_absent_fixed 'data-estab-logout-form'
handbook_chapter_count=$(
    grep -o 'data-estab-handbook-section' "$body" | wc -l | tr -d ' '
)
if [ "$handbook_chapter_count" != 19 ]; then
    printf 'HTTP surface: web handbook has %s chapters, expected 19\n' \
        "$handbook_chapter_count" >&2
    exit 1
fi

assert_status 405 --request POST "$base_url/handbuch/"
assert_header_fixed 'Allow: GET, HEAD'
assert_body_fixed 'Für das Web-Handbuch sind nur GET und HEAD erlaubt.'

assert_nonempty_200 "$base_url/handbuch/handbuch.css"
assert_header_regex \
    '^Content-Type:[[:space:]]*text/css([;[:space:]]|$)' \
    'the handbook CSS content type'
assert_body_fixed '.estab-handbook-layout'
assert_body_fixed '.estab-handbook-chapter[hidden]'
assert_body_fixed '@media (max-width: 48rem)'
assert_body_fixed '@media (prefers-reduced-motion: reduce)'

assert_nonempty_200 "$base_url/handbuch/handbuch.js"
assert_header_regex \
    '^Content-Type:[[:space:]]*(text|application)/(javascript|x-javascript)([;[:space:]]|$)' \
    'the handbook JavaScript content type'
assert_body_fixed "'use strict'"
assert_body_fixed "document.querySelector('[data-estab-handbook-search]')"
assert_body_fixed ".normalize('NFD')"
assert_body_fixed 'section.hidden = !matches'
assert_body_fixed 'status.textContent ='
assert_body_fixed 'window.history.replaceState'
assert_body_absent_fixed 'innerHTML'
assert_body_absent_fixed 'eval('

assert_status 404 "$base_url/doku/Handbuch_eStab.pdf"

assert_status 200 "$base_url/4fach/index.php"
iframe_count=$(grep -o '<iframe' "$body" | wc -l | tr -d ' ')
if [ "$iframe_count" != 2 ]; then
    printf 'HTTP surface: expected exactly two message workspace frames, got %s\n' \
        "$iframe_count" >&2
    sed -n '1,80p' "$body" >&2
    exit 1
fi
assert_body_fixed 'data-estab-message-workspace'
assert_body_fixed 'name="vorgaben"'
assert_body_fixed 'src="./vorgaben.php"'
assert_body_fixed 'name="mainframe"'
assert_body_fixed 'src="./mainindex.php"'
assert_body_fixed 'data-estab-mobile-menu-return'
assert_body_fixed 'data-estab-mobile-workspace-navigation'
assert_body_fixed "event.data === 'estab:show-content'"
assert_body_fixed 'event.source === sidebar.contentWindow'
assert_body_fixed "content.scrollIntoView({block: 'start'})"
assert_body_absent_fixed './counter.php?embedded=1'
assert_body_absent_fixed './status.php?embedded=1'
assert_body_absent_fixed '<frameset'
assert_status 200 "$base_url/4fach/index.php?login_flow=existing"
assert_body_fixed 'src="./mainindex.php?login_flow=existing"'
assert_status 200 "$base_url/4fach/index.php?login_flow=new"
assert_body_fixed 'src="./mainindex.php?login_flow=new"'
assert_status 200 "$base_url/4fach/index.php?next=incident-log"
assert_body_fixed 'src="./mainindex.php?next=incident-log"'
assert_body_fixed 'src="./vorgaben.php?next=incident-log"'
assert_status 200 "$base_url/4fach/index.php?login_flow=existing&next=tracking"
assert_body_fixed 'src="./mainindex.php?login_flow=existing&amp;next=tracking"'
assert_body_fixed 'src="./vorgaben.php?next=tracking"'
assert_status 400 "$base_url/4fach/index.php?login_flow=unknown"
assert_status 400 "$base_url/4fach/index.php?next=administration"
assert_status 400 "$base_url/4fach/index.php?next=https%3A%2F%2Fattacker.invalid"
assert_status 403 "$base_url/4fach/counter.php"
assert_status 200 "$base_url/4fach/vorgaben.php"
assert_body_fixed 'data-estab-sidebar-root'
assert_body_fixed 'data-estab-public-bar'
assert_body_fixed 'estab-session-bar-sidebar'
assert_body_fixed 'data-estab-navigation-mode="sidebar"'
assert_body_fixed '<h2>Bereiche</h2>'
assert_body_fixed '<p>Arbeitsbereich wechseln</p>'
assert_body_fixed '>Anmelden</a>'
navigation_item_count=$(
    grep -o 'data-estab-navigation-item' "$body" | wc -l | tr -d ' '
)
if [ "$navigation_item_count" != 11 ]; then
    printf 'HTTP surface: anonymous sidebar contains %s navigation items, expected 11\n' \
        "$navigation_item_count" >&2
    exit 1
fi
for forbidden in \
    '<details' \
    '<summary' \
    'data-estab-sidebar-status' \
    'data-estab-queue-count' \
    'data-estab-presence-state' \
    'data-estab-sidebar-refresh' \
    'data-estab-sound-toggle' \
    'data-estab-sidebar-audio' \
    'data-estab-workflow-menu'
do
    assert_body_absent_fixed "$forbidden"
done
if grep -Fq 'data-estab-session-bar' "$body"; then
    printf 'HTTP surface: anonymous navigation contains authenticated session UI\n' >&2
    exit 1
fi
assert_status 200 "$base_url/4fach/vorgaben.php?next=incident-log"
assert_body_fixed "href=\"$expected_app_root/4fach/index.php?login_flow=existing&amp;next=incident-log\""
assert_body_fixed 'data-estab-navigation-mode="sidebar"'
assert_body_absent_fixed '<summary'
assert_status 400 "$base_url/4fach/vorgaben.php?next=administration"
assert_status 400 "$base_url/4fach/vorgaben.php?unexpected=1"
assert_status 401 "$base_url/4fach/vorgaben.php?fragment=status"
assert_body_fixed 'Anmeldung erforderlich.'
assert_body_absent_fixed 'data-estab-sidebar-status'
assert_status 400 "$base_url/4fach/vorgaben.php?fragment=unknown"
assert_status 400 \
    "$base_url/4fach/vorgaben.php?fragment=status&next=incident-log"
assert_status 200 "$base_url/4fach/mainindex.php"
assert_body_fixed 'eStab-Funktionskonto'
assert_body_fixed 'Wie möchten Sie fortfahren?'
assert_body_fixed 'name="login_flow" value="existing"'
assert_body_absent_fixed 'name="login_flow" value="new"'
assert_body_fixed '<button class="estab-button" type="button" disabled>Neues Konto anlegen</button>'
assert_body_fixed 'Administration → Benutzerverwaltung'
assert_body_fixed 'name="csrf_token"'
if grep -Fq 'data-estab-session-bar' "$body"; then
    printf 'HTTP surface: anonymous login page contains authenticated session UI\n' >&2
    exit 1
fi

assert_status 200 "$base_url/4fach/mainindex.php?login_flow=existing"
assert_body_fixed 'Mit bestehendem Konto anmelden'
assert_body_fixed 'autocomplete="current-password"'
assert_body_fixed 'data-estab-auth-cancel'
assert_body_fixed '>Anmeldung abbrechen · Zur Übersicht</a>'
assert_body_fixed "href=\"$expected_app_root/\" target=\"_top\""
assert_body_fixed 'target="_self"'
assert_body_absent_fixed 'target="mainframe"'
assert_status 200 "$base_url/4fach/mainindex.php?login_flow=new"
assert_body_fixed '<h2>Neues Konto anlegen</h2>'
assert_body_fixed 'Neue Konten können hier nicht erstellt werden'
assert_body_fixed 'Administration → Benutzerverwaltung'
assert_body_absent_fixed 'name="kennwort2"'
assert_status 403 "$base_url/4fach/mainindex.php?login_flow=unknown"

assert_status 405 "$base_url/4fach/logout.php"
assert_status 303 --request POST \
    --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    --data-urlencode 'logout_action=logout' \
    "$base_url/4fach/logout.php"
assert_header_fixed "Location: $expected_app_root/"
assert_status 405 "$base_url/4fach/activity.php"
assert_status 401 --request POST \
    --data-urlencode 'csrf_token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    "$base_url/4fach/activity.php"

for protected_route in \
    '4fach/fuehrungsstelle.php|command-post|index.php' \
    '4fach/vordrucke.php|forms|index.php' \
    '4fueltg/ue_ltg.php|message-overview|index.php' \
    'stabetb/etb.php|incident-log|index.php' \
    'fmtbb/tbb.php|technical-log|index.php' \
    '4fach/nachwea.php?nwalle|tracking|index.php' \
    '4fach/anhang.php|messages|mainindex.php' \
    '4fach/katgoedt.php?dbtyp=fkt&msgno=1|messages|mainindex.php'
do
    route=${protected_route%%|*}
    protected_target=${protected_route#*|}
    destination=${protected_target%%|*}
    login_document=${protected_target#*|}
    assert_status 303 "$base_url/$route"
    assert_header_fixed \
        "Location: $expected_app_root/4fach/$login_document?login_flow=existing&next=$destination"
    assert_body_absent_fixed 'Anmeldung erforderlich'
done

assert_status 303 --header 'Sec-Fetch-Dest: iframe' \
    "$base_url/4fach/vordrucke.php"
assert_header_fixed \
    "Location: $expected_app_root/4fach/mainindex.php?login_flow=existing&next=forms"

assert_status 303 \
    "$base_url/4fach/download.php?area=vordruck&file=EL0001.pdf"
assert_header_fixed \
    "Location: $expected_app_root/4fach/index.php?login_flow=existing&next=forms"
assert_body_absent_fixed 'Anmeldung erforderlich'

assert_status 303 --request POST \
    --data-urlencode 'ah_upload_x=1' \
    --data-urlencode 'discarded_marker=HTTP_SURFACE_MUST_NOT_RUN' \
    "$base_url/4fach/anhang.php"
assert_header_fixed \
    "Location: $expected_app_root/4fach/mainindex.php?login_flow=existing&next=messages&interrupted=1"
assert_body_absent_fixed 'Anmeldung erforderlich'

assert_status 303 \
    "$base_url/4fach/mainindex.php?filter_anzahl_x=1&filter_anzahl=10"
assert_header_fixed \
    "Location: $expected_app_root/4fach/mainindex.php?login_flow=existing&next=messages"
assert_header_absent_fixed 'filter_anzahl'
assert_body_absent_fixed 'filter_anzahl'
assert_body_absent_fixed 'Aktion nicht erlaubt'

assert_status 403 \
    "$base_url/4fach/mainindex.php?benutzer=Mustermann&kuerzel=mm&funktion=S1&anmelden=Anmelden"
assert_body_fixed 'Aktion nicht erlaubt'
assert_header_absent_fixed 'Location:'

assert_status 303 --header 'Sec-Fetch-Site: same-origin' --request POST \
    --data-urlencode 'task=Stab_schreiben' \
    --data-urlencode '12_inhalt=HTTP_SURFACE_MUST_NOT_PERSIST' \
    "$base_url/4fach/mainindex.php"
assert_header_fixed \
    "Location: $expected_app_root/4fach/mainindex.php?login_flow=existing&next=messages&interrupted=1"
assert_body_absent_fixed 'Aktion nicht erlaubt'
assert_status 200 \
    "$base_url/4fach/mainindex.php?login_flow=existing&next=messages&interrupted=1"
assert_body_fixed 'data-estab-submission-discarded'
assert_body_fixed 'Die Eingabe wurde nicht gespeichert.'
assert_body_fixed 'data-estab-auth-cancel'
assert_status 200 \
    "$base_url/4fach/index.php?login_flow=existing&next=messages&interrupted=1"
assert_body_fixed \
    'src="./mainindex.php?login_flow=existing&amp;next=messages&amp;interrupted=1"'
assert_status 400 \
    "$base_url/4fach/index.php?login_flow=existing&interrupted=0"
assert_status 403 --header 'Sec-Fetch-Site: cross-site' --request POST \
    --data-urlencode 'task=Stab_schreiben' \
    "$base_url/4fach/mainindex.php"

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
assert_body_fixed '<h2>Neues Konto anlegen</h2>'
assert_body_fixed 'Neue Konten können hier nicht erstellt werden'
assert_body_fixed 'Administration → Benutzerverwaltung'
assert_body_absent_fixed 'name="login_flow" value="new"'
assert_body_absent_fixed 'name="kennwort2"'
assert_body_fixed 'name="csrf_token"'

assert_status 403 --request POST --data-urlencode 'login_flow=unknown' \
    "$base_url/4fach/mainindex.php"

assert_status 200 "$base_url/stabinfo/index.php"
assert_body_fixed './l_index.php'
assert_body_fixed './f_info.php'
assert_body_fixed 'data-estab-bos-workspace'
assert_body_fixed 'name="status"'
assert_body_fixed 'name="mainframe"'
assert_body_fixed 'data-estab-mobile-menu-return'
assert_body_fixed 'data-estab-bos-responsive-style'
assert_body_absent_fixed '<frameset'
assert_no_insecure_resource
assert_nonempty_200 "$base_url/stabinfo/f_info.php"
assert_body_fixed 'data-estab-bos-welcome'
assert_no_insecure_resource
assert_status 200 "$base_url/stabinfo/l_index.php"
assert_body_fixed 'data-estab-public-bar'
assert_body_fixed 'data-estab-navigation-mode="sidebar"'
assert_body_fixed 'data-estab-bos-document-navigation'
assert_body_fixed 'data-estab-bos-document-link'
assert_body_absent_fixed '<details'
assert_body_absent_fixed '<summary'
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
assert_status 200 "$base_url/language/german/helptext.php?Errorart=12_inhalt"
assert_body_fixed 'Sonstige Nachrichten an „W“-Fragen orientieren:'
assert_body_fixed '<b>Wer – Wo – Was – Wann – Wie</b>'
if grep -Fq '</<b>' "$body"; then
    printf 'HTTP surface: message help contains malformed closing markup\n' >&2
    exit 1
fi
assert_status 400 "$base_url/language/german/helptext.php"
assert_status 400 "$base_url/language/german/helptext.php?Errorart=unbekannt"
assert_status 400 --get --data-urlencode 'Errorart[]=01_medium' \
    "$base_url/language/german/helptext.php"

assert_status 403 "$base_url/4fach/status.php"

printf 'HTTP surface integration: OK\n'
