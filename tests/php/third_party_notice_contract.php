<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$noticePath = $root . '/THIRD_PARTY_NOTICES.md';
$notice = file_get_contents($noticePath);
$assert(is_string($notice) && strlen($notice) > 5000, 'Third-party notice is missing or incomplete');
if (!is_string($notice)) {
    throw new RuntimeException('Could not read third-party notice');
}

foreach ([
    'FPDF 1.6',
    'Olivier Plathey',
    'LicenseRef-FPDF',
    'Easy PHP Upload 2.29',
    'Olaf Lederer',
    'BSD-3-Clause',
    'Noto Serif Bold Italic',
    'ffebf8c1ee449e544955a7e813c54f9b73848eac',
    '4fb8737145b4a503d548af4b517afdfc532e44a96ac15378257e825741334eec',
    'SIL OPEN FONT LICENSE Version 1.1',
    'SPDX-SBOMs',
] as $requiredText) {
    $assert(
        str_contains($notice, $requiredText),
        'Third-party notice omits required text: ' . $requiredText
    );
}

foreach (['georgiaz.ttf', 'famfamfam_silk_icons_v013.zip'] as $removedAsset) {
    $assert(
        !str_contains($notice, $removedAsset),
        'Third-party notice still lists removed asset: ' . $removedAsset
    );
}

$assert(
    str_contains((string) file_get_contents($root . '/4fbak/fpdf.php'), "define('FPDF_VERSION','1.6')")
        && str_contains((string) file_get_contents($root . '/4fach/upload_class.php'), 'Easy PHP Upload - version 2.29')
        && hash_file('sha256', $root . '/4fbak/fonts/NotoSerif-BoldItalic.ttf')
            === '4fb8737145b4a503d548af4b517afdfc532e44a96ac15378257e825741334eec',
    'Notice inventory does not match the bundled component versions'
);

$dockerfile = (string) file_get_contents($root . '/Dockerfile');
$assert(
    str_contains(
        $dockerfile,
        'COPY --chmod=0444 THIRD_PARTY_NOTICES.md /usr/share/licenses/estab/THIRD_PARTY_NOTICES.md'
    )
    && str_contains(
        $dockerfile,
        'COPY --chmod=0444 third_party/Noto-OFL-1.1.txt /usr/share/licenses/estab/Noto-OFL-1.1.txt'
    ),
    'Application image does not ship the required notices'
);

printf("third-party notice contract: OK (%d assertions)\n", $assertions);
