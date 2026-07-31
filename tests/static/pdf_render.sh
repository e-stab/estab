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
    if grep -Eiq 'Dienstgebrauch|VS-NfD' "$text_file"; then
        echo "PDF fixture still contains a VS marking: $pdf_file" >&2
        exit 1
    fi
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
    if grep -Eiq 'Dienstgebrauch|VS-NfD' "$text_file"; then
        echo "Long message form still contains a VS marking: $pdf_file" >&2
        exit 1
    fi
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
if grep -Eiq 'Dienstgebrauch|VS-NfD' "$complete_text"; then
    echo "Complete dossier still contains a VS marking" >&2
    exit 1
fi
if awk 'NR > 2 && $1 ~ /^[0-9]+$/ { found = 1 } END { exit found ? 0 : 1 }' \
    "$complete_images"; then
    echo "Complete dossier still contains a rendered image" >&2
    exit 1
fi

for marker in \
    'VORLÄUFIG' \
    'Führungsstelle Musterstadt' \
    'Aufbewahrung bis' \
    'Legal Hold' \
    'Nachrichten-Head-Summenhash' \
    'Einsatztagebuch (ETB)' \
    'Ereigniszeit' \
    'Erfassungszeit' \
    'Ereignistyp' \
    'Technisches Betriebsbuch (TBB)' \
    'Nachrichtenereignisse und Nachweisköpfe' \
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
    'Integrität beim Eingang' \
    'SHA-256 und Größe stimmen'
do
    grep -Fq "$marker" "$complete_text"
done

message_page=
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
    page_number=$((page_number + 1))
done
[ -n "$message_page" ]
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

echo "PDF render comparison: OK"
