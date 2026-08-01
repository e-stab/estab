#!/bin/sh
set -eu

if [ "$#" -ne 1 ] || [ ! -d "$1" ]; then
    echo "Usage: tests/static/pdf_render.sh /absolute/fixture-directory" >&2
    exit 2
fi

fixture_dir=$1
case "$fixture_dir" in
    /*) ;;
    *)
        echo "PDF fixture directory must be absolute" >&2
        exit 2
        ;;
esac

for command_name in pdfinfo pdftotext pdfimages pdftoppm pdfdetach cmp; do
    command -v "$command_name" >/dev/null 2>&1 || {
        echo "PDF render test requires $command_name" >&2
        exit 2
    }
done

single_pdf=$fixture_dir/message-form.pdf
dossier_pdf=$fixture_dir/dossier-message-form.pdf
complete_dossier_pdf=$fixture_dir/dossier-all.pdf
maximum_header_pdf=$fixture_dir/dossier-maximum-header.pdf
long_single_pdf=$fixture_dir/long-message-form.pdf
long_dossier_pdf=$fixture_dir/dossier-long-message-form.pdf
etb_form_pdf=$fixture_dir/etb-form.pdf
tbb_form_pdf=$fixture_dir/tbb-form.pdf
cross_shift_correction_pdf=$fixture_dir/cross-shift-correction.pdf
closed_etb_form_pdf=$fixture_dir/etb-form-closed.pdf
closed_tbb_form_pdf=$fixture_dir/tbb-form-closed.pdf
for pdf_file in "$single_pdf" "$dossier_pdf"; do
    [ -f "$pdf_file" ] || {
        echo "Missing PDF render fixture: $pdf_file" >&2
        exit 1
    }
    info_file=$pdf_file.info.txt
    text_file=$pdf_file.layout.txt
    image_file=$pdf_file.images.txt
    pdfinfo "$pdf_file" >"$info_file"
    pdftotext -layout "$pdf_file" "$text_file"
    pdfimages -list "$pdf_file" >"$image_file"

    grep -Eq '^Pages:[[:space:]]+1$' "$info_file"
    grep -Eq '^Page size:[[:space:]]+595\.28 x 841\.89 pts \(A4\)$' "$info_file"
    for marker in EINGANG AUSGANG Nachweis-Nr. Fm-Betriebsstelle; do
        grep -Fq "$marker" "$text_file"
    done
    grep -Fq 'Blitz' "$text_file"
    if grep -Fq 'bbb' "$text_file"; then
        echo "PDF fixture exposes a raw priority code: $pdf_file" >&2
        exit 1
    fi
    grep -Fq 'Empfänger außerhalb aktueller Matrix:' "$text_file"
    grep -Fq 'ALT_1 [gn]' "$text_file"
    grep -Fq 'ALT2 [rt]' "$text_file"
    grep -Fq 'Ruf Nr.' "$text_file"
    grep -Fq '0711 123456' "$text_file"
    grep -Fq 'Lagebetreff Nord' "$text_file"
    subject_line=$(grep -Fn 'Lagebetreff Nord' "$text_file" | head -n 1 | cut -d: -f1)
    content_line=$(grep -Fn 'eStab PDF Funktionsnachweis' "$text_file" | head -n 1 | cut -d: -f1)
    [ "$subject_line" -lt "$content_line" ] || {
        echo "PDF subject is not above the message text: $pdf_file" >&2
        exit 1
    }
    if awk 'NR > 2 && $1 ~ /^[0-9]+$/ { found = 1 } END { exit found ? 0 : 1 }' \
        "$image_file"; then
        echo "PDF fixture still contains a rendered image: $pdf_file" >&2
        exit 1
    fi
done

[ -f "$maximum_header_pdf" ] || {
    echo "Missing maximum-value PDF header fixture: $maximum_header_pdf" >&2
    exit 1
}
maximum_header_info=$maximum_header_pdf.info.txt
maximum_header_text=$maximum_header_pdf.layout.txt
maximum_header_bbox=$maximum_header_pdf.bbox.html
pdfinfo "$maximum_header_pdf" >"$maximum_header_info"
pdftotext -layout -f 1 -l 1 \
    "$maximum_header_pdf" "$maximum_header_text"
pdftotext -bbox -f 1 -l 1 \
    "$maximum_header_pdf" "$maximum_header_bbox"
grep -Eq '^Page size:[[:space:]]+595\.28 x 841\.89 pts \(A4\)$' \
    "$maximum_header_info"
grep -Eq '^[[:space:]]*Führungsstelle: F+' "$maximum_header_text"
grep -Eq '^[[:space:]]*Einsatz: MAX-K+' "$maximum_header_text"
[ "$(grep -Ec '\.\.\.$' "$maximum_header_text")" -ge 2 ]
awk '
    /<word / {
        x_min = ""
        x_max = ""
        y_max = ""
        for (field = 1; field <= NF; field++) {
            value = $field
            if (value ~ /^xMin=/) {
                gsub(/[^0-9.]/, "", value)
                x_min = value
            } else if (value ~ /^xMax=/) {
                gsub(/[^0-9.]/, "", value)
                x_max = value
            } else if (value ~ /^yMax=/) {
                gsub(/[^0-9.]/, "", value)
                y_max = value
            }
        }
        if (y_max != "" && (y_max + 0) <= 66 && \
            ((x_min + 0) < 44 || (x_max + 0) > 551)) {
            bad = 1
        }
    }
    END { exit bad ? 1 : 0 }
' "$maximum_header_bbox" || {
    echo "Maximum-value dossier header exceeds its A4 bounds" >&2
    exit 1
}
pdftoppm -png -r 144 -f 1 -l 1 -singlefile -hide-annotations \
    "$maximum_header_pdf" "$fixture_dir/dossier-maximum-header-page"
[ -s "$fixture_dir/dossier-maximum-header-page.png" ]

long_page_count=
for pdf_file in "$long_single_pdf" "$long_dossier_pdf"; do
    [ -f "$pdf_file" ] || {
        echo "Missing long PDF render fixture: $pdf_file" >&2
        exit 1
    }
    info_file=$pdf_file.info.txt
    text_file=$pdf_file.layout.txt
    image_file=$pdf_file.images.txt
    bbox_file=$pdf_file.bbox.html
    pdfinfo "$pdf_file" >"$info_file"
    pdftotext -layout "$pdf_file" "$text_file"
    pdftotext -bbox "$pdf_file" "$bbox_file"
    pdfimages -list "$pdf_file" >"$image_file"
    current_page_count=$(awk '/^Pages:/ { print $2 }' "$info_file")
    [ "$current_page_count" -ge 2 ]
    if [ -n "$long_page_count" ]; then
        [ "$current_page_count" -eq "$long_page_count" ]
    else
        long_page_count=$current_page_count
    fi
    grep -Eq '^Page size:[[:space:]]+595\.28 x 841\.89 pts \(A4\)$' \
        "$info_file"
    grep -Fq 'ENDE-MEHRSEITIGER-VORDRUCK' "$text_file"
    [ "$(grep -Fc 'EINGANG' "$text_file")" -eq "$current_page_count" ]
    if awk 'NR > 2 && $1 ~ /^[0-9]+$/ { found = 1 } END { exit found ? 0 : 1 }' \
        "$image_file"; then
        echo "Long message form still contains a rendered image: $pdf_file" >&2
        exit 1
    fi
    x_positions=$(sed -n \
        's/.*xMin="\([^"]*\)".*>Mehrseitiger<\/word>.*/\1/p' \
        "$bbox_file" | sort -u)
    [ -n "$x_positions" ]
    [ "$(printf '%s\n' "$x_positions" | wc -l | tr -d ' ')" -eq 1 ] || {
        echo "Long message continuation changed its left inset: $pdf_file" >&2
        exit 1
    }
done

pdftoppm -png -r 144 -hide-annotations \
    "$long_single_pdf" "$fixture_dir/long-single-page"
pdftoppm -png -r 144 -hide-annotations \
    "$long_dossier_pdf" "$fixture_dir/long-dossier-page"
page_number=1
while [ "$page_number" -le "$long_page_count" ]; do
    cmp \
        "$fixture_dir/long-single-page-$page_number.png" \
        "$fixture_dir/long-dossier-page-$page_number.png"
    page_number=$((page_number + 1))
done

assert_pdf_has_no_images() {
    image_list=$1
    label=$2
    if awk '
        NR > 2 && $1 ~ /^[0-9]+$/ { found = 1 }
        END { exit found ? 0 : 1 }
    ' "$image_list"; then
        echo "$label contains a rendered image" >&2
        exit 1
    fi
}

assert_pdf_has_only_thw_mark() {
    image_list=$1
    label=$2
    awk '
        NR > 2 && $1 ~ /^[0-9]+$/ {
            found = 1
            if (($4 + 0) != 400 || ($5 + 0) != 396) {
                bad = 1
            }
        }
        END { exit found && !bad ? 0 : 1 }
    ' "$image_list" || {
        echo "$label does not contain only the 400x396 THW header mark" >&2
        exit 1
    }
}

assert_bbox_word_range() {
    bbox_file=$1
    marker=$2
    minimum_x=$3
    maximum_x=$4
    awk -v marker="$marker" -v minimum_x="$minimum_x" \
        -v maximum_x="$maximum_x" '
        index($0, ">" marker "</word>") {
            x_min = ""
            x_max = ""
            for (field = 1; field <= NF; field++) {
                value = $field
                if (value ~ /^xMin=/) {
                    gsub(/[^0-9.]/, "", value)
                    x_min = value
                } else if (value ~ /^xMax=/) {
                    gsub(/[^0-9.]/, "", value)
                    x_max = value
                }
            }
            if (x_min != "" && x_max != "" && \
                (x_min + 0) >= (minimum_x + 0) && \
                (x_max + 0) <= (maximum_x + 0)) {
                found = 1
            } else {
                outside = 1
            }
        }
        END { exit found && !outside ? 0 : 1 }
    ' "$bbox_file" || {
        echo "$marker is outside its prescribed logbook column" >&2
        exit 1
    }
}

for form_pdf in "$etb_form_pdf" "$tbb_form_pdf"; do
    [ -f "$form_pdf" ] || {
        echo "Missing logbook PDF render fixture: $form_pdf" >&2
        exit 1
    }
    pdfinfo "$form_pdf" >"$form_pdf.info.txt"
    pdftotext -layout "$form_pdf" "$form_pdf.layout.txt"
    pdftotext -bbox "$form_pdf" "$form_pdf.bbox.html"
    pdfimages -list "$form_pdf" >"$form_pdf.images.txt"
    assert_pdf_has_only_thw_mark \
        "$form_pdf.images.txt" \
        "Logbook form $form_pdf"
    if grep -Fq '{etb#}' "$form_pdf.layout.txt" || \
        grep -Fq '{tbb#}' "$form_pdf.layout.txt"; then
        echo "Logbook form exposes an unresolved local page alias: $form_pdf" >&2
        exit 1
    fi
    if grep -Eq '(ETB|TBB) [0-9]+ ·|Ereigniszeit|Erfassungszeit|Ereignistyp' \
        "$form_pdf.layout.txt"; then
        echo "Logbook form fell back to the former generic card layout: $form_pdf" >&2
        exit 1
    fi
done

etb_info=$etb_form_pdf.info.txt
etb_text=$etb_form_pdf.layout.txt
etb_bbox=$etb_form_pdf.bbox.html
etb_page_count=$(awk '/^Pages:/ { print $2 }' "$etb_info")
[ "$etb_page_count" -ge 2 ]
pdfinfo -f 1 -l "$etb_page_count" "$etb_form_pdf" \
    >"$etb_form_pdf.pages.info.txt"
[ "$(grep -Ec '^Page[[:space:]]+[0-9]+ size:[[:space:]]+595\.28 x 841\.89 pts \(A4\)$' \
    "$etb_form_pdf.pages.info.txt")" -eq "$etb_page_count" ]
for marker in \
    'Fb Fü 2' \
    'Einsatztagebuch' \
    'Hilfswerk' \
    'Darstellung der Ereignisse' \
    'Bemerkungen' \
    'Leiter/-in Führungsstelle' \
    'ETB-Führer/-in'
do
    [ "$(grep -Fc "$marker" "$etb_text")" -eq "$etb_page_count" ] || {
        echo "ETB form does not repeat $marker on every page" >&2
        exit 1
    }
done
for marker in EVENTSPALTE BEMERKUNGSSPALTE ETB-LANGTEXT-ENDE; do
    grep -Fq "$marker" "$etb_text"
done
grep -Fq 'Anlage: ETB 1-1-1' "$etb_text"
if grep -Eq '9001|9002' "$etb_text"; then
    echo "ETB form used a legacy global number instead of the book-local number" >&2
    exit 1
fi
page_number=1
while [ "$page_number" -le "$etb_page_count" ]; do
    pdftotext -layout -f "$page_number" -l "$page_number" \
        "$etb_form_pdf" "$etb_form_pdf.page-$page_number.layout.txt"
    grep -Eq "Seite:[[:space:]]+$page_number von $etb_page_count" \
        "$etb_form_pdf.page-$page_number.layout.txt"
    page_number=$((page_number + 1))
done
assert_bbox_word_range "$etb_bbox" EVENTSPALTE 170 454
assert_bbox_word_range "$etb_bbox" BEMERKUNGSSPALTE 454 562

tbb_info=$tbb_form_pdf.info.txt
tbb_text=$tbb_form_pdf.layout.txt
tbb_bbox=$tbb_form_pdf.bbox.html
tbb_page_count=$(awk '/^Pages:/ { print $2 }' "$tbb_info")
[ "$tbb_page_count" -ge 2 ]
pdfinfo -f 1 -l "$tbb_page_count" "$tbb_form_pdf" \
    >"$tbb_form_pdf.pages.info.txt"
[ "$(grep -Ec '^Page[[:space:]]+[0-9]+ size:[[:space:]]+841\.89 x 595\.28 pts \(A4\)$' \
    "$tbb_form_pdf.pages.info.txt")" -eq "$tbb_page_count" ]
for marker in \
    'Fb Fü 44' \
    'Technisches Betriebsbuch' \
    'Hilfswerk' \
    'Fernmeldebetriebsstelle' \
    'Betriebsablauf/Ereignis' \
    'Störung/Störungsbeseitigung' \
    'Leiter/-in Fernmeldebetrieb (LdF)'
do
    [ "$(grep -Fc "$marker" "$tbb_text")" -eq "$tbb_page_count" ] || {
        echo "TBB form does not repeat $marker on every page" >&2
        exit 1
    }
done
for marker in \
    DIENSTSPALTE \
    KANALSPALTE \
    NACHRICHTVON \
    NACHRICHTAN \
    BETRIEBSSPALTE \
    QUITTUNGSSPALTE \
    'Legacy-Betriebsvorgang bleibt sichtbar' \
    'Legacy-Bemerkung bleibt sichtbar' \
    TBB-LANGTEXT-ENDE
do
    grep -Fq "$marker" "$tbb_text"
done
[ "$(grep -Fc 'DIENSTSPALTE' "$tbb_text")" -eq 1 ]
[ "$(grep -Fc 'KANALSPALTE' "$tbb_text")" -eq 1 ]
[ "$(grep -Fc 'BETRIEBSSPALTE' "$tbb_text")" -eq 1 ]
if grep -Fq 'Kompatibilitätszusammenfassung nicht erneut drucken' \
    "$tbb_text"; then
    echo "Structured TBB compatibility summary was printed twice" >&2
    exit 1
fi
[ "$(grep -Fc 'Zusatzbemerkung genau einmal drucken' \
    "$tbb_text")" -eq 1 ]
if grep -Eq '9101|9102|9103' "$tbb_text"; then
    echo "TBB form used a legacy global number instead of the book-local number" >&2
    exit 1
fi
page_number=1
while [ "$page_number" -le "$tbb_page_count" ]; do
    pdftotext -layout -f "$page_number" -l "$page_number" \
        "$tbb_form_pdf" "$tbb_form_pdf.page-$page_number.layout.txt"
    grep -Eq "Seite:[[:space:]]+$page_number von $tbb_page_count" \
        "$tbb_form_pdf.page-$page_number.layout.txt"
    page_number=$((page_number + 1))
done
assert_bbox_word_range "$tbb_bbox" DIENSTSPALTE 113 292
assert_bbox_word_range "$tbb_bbox" KANALSPALTE 292 428
assert_bbox_word_range "$tbb_bbox" NACHRICHTVON 428 541
assert_bbox_word_range "$tbb_bbox" NACHRICHTAN 428 541
assert_bbox_word_range "$tbb_bbox" BETRIEBSSPALTE 541 734
assert_bbox_word_range "$tbb_bbox" QUITTUNGSSPALTE 734 820

[ -f "$cross_shift_correction_pdf" ] || {
    echo "Missing cross-shift correction PDF fixture" >&2
    exit 1
}
cross_shift_text=$cross_shift_correction_pdf.layout.txt
cross_shift_info=$cross_shift_correction_pdf.info.txt
pdftotext -layout "$cross_shift_correction_pdf" "$cross_shift_text"
pdfinfo "$cross_shift_correction_pdf" >"$cross_shift_info"
grep -Eq '^Pages:[[:space:]]+2$' "$cross_shift_info"
for marker in \
    'ETB-KORREKTUR-AUS-ANDERER-SCHICHT' \
    'TBB-KORREKTUR-AUS-ANDERER-SCHICHT' \
    'Korrektur zu ETB-Nr.: 7' \
    'Korrektur zu TBB-Nr.: 8'
do
    grep -Fq "$marker" "$cross_shift_text"
done
if grep -Fq 'Referenz: 7' "$cross_shift_text"; then
    echo "ETB correction prints its canonical target twice" >&2
    exit 1
fi
[ "$(grep -Fc 'Korrektur zu ETB-Nr.: 7' "$cross_shift_text")" -eq 1 ]
if grep -Eq '876543|876544|987654|987655' "$cross_shift_text"; then
    echo "Cross-shift correction exposes a global database ID" >&2
    exit 1
fi
pdftoppm -f 1 -l 2 -r 144 -png \
    "$cross_shift_correction_pdf" \
    "$fixture_dir/cross-shift-correction-page" >/dev/null 2>&1
[ -s "$fixture_dir/cross-shift-correction-page-1.png" ]
[ -s "$fixture_dir/cross-shift-correction-page-2.png" ]

for closed_form_pdf in "$closed_etb_form_pdf" "$closed_tbb_form_pdf"; do
    [ -f "$closed_form_pdf" ] || {
        echo "Missing closed logbook PDF render fixture: $closed_form_pdf" >&2
        exit 1
    }
    pdfinfo "$closed_form_pdf" >"$closed_form_pdf.info.txt"
    pdftotext -layout "$closed_form_pdf" "$closed_form_pdf.layout.txt"
    grep -Eq '^Pages:[[:space:]]+1$' "$closed_form_pdf.info.txt"
    grep -Fq 'Nicht beschriebener Bereich' "$closed_form_pdf.layout.txt"
done
pdftoppm -png -r 144 -singlefile -hide-annotations \
    "$closed_etb_form_pdf" "$fixture_dir/etb-form-closed-page"
pdftoppm -png -r 144 -singlefile -hide-annotations \
    "$closed_tbb_form_pdf" "$fixture_dir/tbb-form-closed-page"
[ -s "$fixture_dir/etb-form-closed-page.png" ]
[ -s "$fixture_dir/tbb-form-closed-page.png" ]

[ -f "$complete_dossier_pdf" ] || {
    echo "Missing complete PDF render fixture: $complete_dossier_pdf" >&2
    exit 1
}
complete_info=$complete_dossier_pdf.info.txt
complete_text=$complete_dossier_pdf.layout.txt
complete_images=$complete_dossier_pdf.images.txt
pdfinfo "$complete_dossier_pdf" >"$complete_info"
pdftotext -layout "$complete_dossier_pdf" "$complete_text"
pdfimages -list "$complete_dossier_pdf" >"$complete_images"
complete_page_count=$(awk '/^Pages:/ { print $2 }' "$complete_info")
[ "$complete_page_count" -ge 10 ]
grep -Eq '^Page size:[[:space:]]+595\.28 x 841\.89 pts \(A4\)$' \
    "$complete_info"
if grep -Fq 'ZUORDNUNG-NUR-SUCHHILFE' "$complete_text"; then
    echo "ETB search assignment leaked into the official PDF form" >&2
    exit 1
fi

for marker in \
    'VORLÄUFIG' \
    'Führungsstelle Musterstadt' \
    'Aufbewahrung bis' \
    'Legal Hold' \
    'Nachrichten-Head-Summenhash' \
    'ETB: 1' \
    'Ereigniszeit' \
    'Erfassungszeit' \
    'Ereignistyp' \
    'TBB: 1' \
    'Logbuchauswahl' \
    'Nur Dienstschicht 2' \
    'Nachtschicht Rendernachweis' \
    'ID: 17' \
    'UEBERGEBEN' \
    '2026-07-29 18:00:00' \
    '2026-07-30 06:00:00' \
    'Nachrichten-Nachweis' \
    'Terminalbindungen' \
    'Dienstschichten, Besetzungen und Übergaben' \
    'Übergabeanforderungen' \
    'INITIIERT' \
    'STORNIERT' \
    'BESTAETIGT' \
    'Stornierungsgrund' \
    'S6-Fernmeldeplanversionen und Einträge' \
    'Gültig ab' \
    'Freigegeben am' \
    'Melderaufträge' \
    'Tatsächlicher Empfänger' \
    'Rücknachricht vorhanden' \
    'Rückweg am' \
    'Betriebsereignisse und Nachweiskopf' \
    'Gespeicherter Head-Hash' \
    'Anlagenverzeichnis' \
    'ETB-Anlagennummer' \
    'ETB 1-1-1' \
    'Ablagekennzeichen' \
    'Integrität beim Eingang' \
    'SHA-256 und Größe stimmen' \
    'Anlage 1 von 2' \
    'Anlage 2 von 2' \
    'Vollständige Textdarstellung' \
    'Bild 1 von 1' \
    'Render-Foto.jpg' \
    'Sichtbare Darstellung' \
    'bytegleiche Original'
do
    grep -Fq "$marker" "$complete_text"
done

message_page=
attachment_text_page=
attachment_image_page=
etb_dossier_pages=$complete_dossier_pdf.etb-pages.txt
tbb_dossier_pages=$complete_dossier_pdf.tbb-pages.txt
: >"$etb_dossier_pages"
: >"$tbb_dossier_pages"
page_number=1
while [ "$page_number" -le "$complete_page_count" ]; do
    pdftotext -layout -f "$page_number" -l "$page_number" \
        "$complete_dossier_pdf" \
        "$complete_dossier_pdf.page-$page_number.layout.txt"
    if grep -Fq 'EINGANG' \
        "$complete_dossier_pdf.page-$page_number.layout.txt"; then
        [ -z "$message_page" ] || {
            echo "Complete dossier contains more than one message-form page" >&2
            exit 1
        }
        message_page=$page_number
    fi
    if grep -Fq 'Fb Fü 2' \
        "$complete_dossier_pdf.page-$page_number.layout.txt"; then
        printf '%s\n' "$page_number" >>"$etb_dossier_pages"
    fi
    if grep -Fq 'Fb Fü 44' \
        "$complete_dossier_pdf.page-$page_number.layout.txt"; then
        printf '%s\n' "$page_number" >>"$tbb_dossier_pages"
    fi
    if grep -Fq 'Anlage 1 von 2' \
        "$complete_dossier_pdf.page-$page_number.layout.txt"; then
        [ -z "$attachment_text_page" ]
        attachment_text_page=$page_number
    fi
    if grep -Fq 'Anlage 2 von 2' \
        "$complete_dossier_pdf.page-$page_number.layout.txt"; then
        [ -z "$attachment_image_page" ]
        attachment_image_page=$page_number
    fi
    page_number=$((page_number + 1))
done
[ -n "$message_page" ]
[ -n "$attachment_text_page" ]
[ -n "$attachment_image_page" ]
[ "$attachment_text_page" -lt "$attachment_image_page" ]
[ "$(wc -l <"$etb_dossier_pages" | tr -d ' ')" -eq "$etb_page_count" ]
[ "$(wc -l <"$tbb_dossier_pages" | tr -d ' ')" -eq "$tbb_page_count" ]
complete_message_images=$complete_dossier_pdf.page-$message_page.images.txt
pdfimages -f "$message_page" -l "$message_page" -list \
    "$complete_dossier_pdf" >"$complete_message_images"
assert_pdf_has_no_images \
    "$complete_message_images" \
    "Complete dossier message-form page"
complete_text_attachment_images=$complete_dossier_pdf.page-$attachment_text_page.images.txt
pdfimages -f "$attachment_text_page" \
    -l "$attachment_text_page" -list \
    "$complete_dossier_pdf" >"$complete_text_attachment_images"
assert_pdf_has_no_images \
    "$complete_text_attachment_images" \
    "Complete dossier visible text attachment page"
complete_attachment_images=$complete_dossier_pdf.page-$attachment_image_page.images.txt
pdfimages -f "$attachment_image_page" -l "$attachment_image_page" -list \
    "$complete_dossier_pdf" >"$complete_attachment_images"
awk '
    NR > 2 && $1 ~ /^[0-9]+$/ {
        count++
        if (($4 + 0) == 100 && ($5 + 0) == 97) {
            visible = 1
        }
    }
    END { exit count == 1 && visible ? 0 : 1 }
' "$complete_attachment_images" || {
    echo "Visible JPEG attachment page lacks its exact content image" >&2
    exit 1
}
for marker in EINGANG AUSGANG Nachweis-Nr. Fm-Betriebsstelle; do
    grep -Fq "$marker" \
        "$complete_dossier_pdf.page-$message_page.layout.txt"
done
grep -Fq 'Blitz' \
    "$complete_dossier_pdf.page-$message_page.layout.txt"
if grep -Fq 'bbb' \
    "$complete_dossier_pdf.page-$message_page.layout.txt"; then
    echo "Complete dossier exposes a raw priority code" >&2
    exit 1
fi
grep -Fq 'Empfänger außerhalb aktueller Matrix:' \
    "$complete_dossier_pdf.page-$message_page.layout.txt"
grep -Fq '0711 123456' \
    "$complete_dossier_pdf.page-$message_page.layout.txt"
grep -Fq 'Lagebetreff Nord' \
    "$complete_dossier_pdf.page-$message_page.layout.txt"
grep -Fq "Seite $message_page/$complete_page_count" \
    "$complete_dossier_pdf.page-$message_page.layout.txt"

pdftoppm -png -r 144 -singlefile -hide-annotations \
    "$single_pdf" "$fixture_dir/message-form-page"
pdftoppm -png -r 144 -singlefile -hide-annotations \
    "$dossier_pdf" "$fixture_dir/dossier-message-form-page"
cmp \
    "$fixture_dir/message-form-page.png" \
    "$fixture_dir/dossier-message-form-page.png"

compare_logbook_pages() {
    standalone_pdf=$1
    page_list=$2
    orientation_pattern=$3
    fixture_name=$4
    standalone_page=1
    while IFS= read -r dossier_page; do
        [ -n "$dossier_page" ] || continue
        pdfinfo -f "$dossier_page" -l "$dossier_page" \
            "$complete_dossier_pdf" \
            >"$complete_dossier_pdf.page-$dossier_page.info.txt"
        grep -Eq "$orientation_pattern" \
            "$complete_dossier_pdf.page-$dossier_page.info.txt"
        pdftoppm -png -r 144 -f "$standalone_page" -l "$standalone_page" \
            -singlefile -hide-annotations \
            "$standalone_pdf" \
            "$fixture_dir/$fixture_name-standalone-$standalone_page"
        pdftoppm -png -r 144 -f "$dossier_page" -l "$dossier_page" \
            -singlefile -hide-annotations \
            "$complete_dossier_pdf" \
            "$fixture_dir/$fixture_name-dossier-$standalone_page"
        cmp \
            "$fixture_dir/$fixture_name-standalone-$standalone_page.png" \
            "$fixture_dir/$fixture_name-dossier-$standalone_page.png"
        standalone_page=$((standalone_page + 1))
    done <"$page_list"
}

compare_logbook_pages \
    "$etb_form_pdf" \
    "$etb_dossier_pages" \
    '^Page[[:space:]]+[0-9]+ size:[[:space:]]+595\.28 x 841\.89 pts \(A4\)$' \
    etb-form
compare_logbook_pages \
    "$tbb_form_pdf" \
    "$tbb_dossier_pages" \
    '^Page[[:space:]]+[0-9]+ size:[[:space:]]+841\.89 x 595\.28 pts \(A4\)$' \
    tbb-form

pdftoppm -png -r 144 \
    "$complete_dossier_pdf" "$fixture_dir/dossier-all-page"
page_number=1
while [ "$page_number" -le "$complete_page_count" ]; do
    page_suffix=$(printf "%0${#complete_page_count}d" "$page_number")
    [ -s "$fixture_dir/dossier-all-page-$page_suffix.png" ]
    page_number=$((page_number + 1))
done

compare_production_crop() {
    crop_name=$1
    crop_x=$2
    crop_y=$3
    crop_width=$4
    crop_height=$5
    pdftoppm -png -r 144 -f 1 -l 1 -singlefile -hide-annotations \
        -x "$crop_x" -y "$crop_y" -W "$crop_width" -H "$crop_height" \
        "$single_pdf" "$fixture_dir/single-$crop_name"
    pdftoppm -png -r 144 -f "$message_page" -l "$message_page" \
        -singlefile -hide-annotations \
        -x "$crop_x" -y "$crop_y" -W "$crop_width" -H "$crop_height" \
        "$complete_dossier_pdf" "$fixture_dir/dossier-all-$crop_name"
    cmp \
        "$fixture_dir/single-$crop_name.png" \
        "$fixture_dir/dossier-all-$crop_name.png"
}

# The only excluded rectangle contains the intentionally dossier-global
# dossier-global page counter. Every other pixel of the productive message
# page must equal the standalone form, including fields, grid and content.
compare_production_crop top 0 0 1191 180
compare_production_crop counter-left 0 180 840 50
compare_production_crop counter-right 1020 180 171 50
compare_production_crop below-counter 0 230 1191 1454

pdfdetach -save 1 -o "$fixture_dir/extracted-attachment.txt" "$dossier_pdf"
cmp \
    "$fixture_dir/original-attachment.txt" \
    "$fixture_dir/extracted-attachment.txt"
pdfdetach -save 1 -o "$fixture_dir/extracted-complete-attachment.txt" \
    "$complete_dossier_pdf"
cmp \
    "$fixture_dir/original-attachment.txt" \
    "$fixture_dir/extracted-complete-attachment.txt"
pdfdetach -save 2 -o "$fixture_dir/extracted-complete-attachment.jpg" \
    "$complete_dossier_pdf"
cmp \
    "$fixture_dir/original-attachment.jpg" \
    "$fixture_dir/extracted-complete-attachment.jpg"
[ "$(pdfdetach -list "$complete_dossier_pdf" | awk '/^[[:space:]]*[0-9]+:/ { count++ } END { print count + 0 }')" -eq 2 ]

echo "PDF render comparison: OK"
