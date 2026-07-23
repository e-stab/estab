<?php

declare(strict_types=1);

/**
 * Validation and rendering for the three legacy bitmap-button endpoints.
 *
 * Validation is deliberately independent from GD so it can be covered by the
 * static test suite. Rendering is only entered after every request value has
 * been normalised and bounded.
 */

/** @return positive-int */
function estab_image_text_length(string $value): int
{
    $length = preg_match_all('/./us', $value, $matches);
    if ($length === false) {
        throw new InvalidArgumentException('Text is not valid UTF-8');
    }
    return max(1, $length);
}

/** Reject parameters that are not part of the selected button contract. */
function estab_image_assert_allowed_keys(array $query, array $allowed): void
{
    foreach (array_keys($query) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            throw new InvalidArgumentException('Unexpected image parameter');
        }
    }
}

/** Read one printable, scalar UTF-8 query value. */
function estab_image_text_parameter(
    array $query,
    string $key,
    int $maximumCharacters,
    int $maximumBytes,
    ?string $default = null
): string {
    if (!array_key_exists($key, $query)) {
        if ($default !== null) {
            return $default;
        }
        throw new InvalidArgumentException('Required image parameter is missing');
    }

    $value = $query[$key];
    if (!is_string($value)) {
        throw new InvalidArgumentException('Image parameters must be scalar strings');
    }
    $value = trim($value);
    if (
        $value === ''
        || strlen($value) > $maximumBytes
        || preg_match('//u', $value) !== 1
        || preg_match('/[\p{C}]/u', $value) === 1
        || estab_image_text_length($value) > $maximumCharacters
    ) {
        throw new InvalidArgumentException('Image text is invalid or too long');
    }
    return $value;
}

/** Read a query value from a closed vocabulary. */
function estab_image_enum_parameter(
    array $query,
    string $key,
    array $allowed,
    ?string $default = null
): string {
    $maximum = max(array_map('strlen', $allowed));
    $value = estab_image_text_parameter($query, $key, $maximum, $maximum, $default);
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException('Unknown image parameter value');
    }
    return $value;
}

/** Read a canonical decimal integer from a bounded range. */
function estab_image_integer_parameter(
    array $query,
    string $key,
    int $minimum,
    int $maximum,
    ?int $default = null
): int {
    if (!array_key_exists($key, $query)) {
        if ($default !== null) {
            return $default;
        }
        throw new InvalidArgumentException('Required numeric image parameter is missing');
    }

    $raw = $query[$key];
    if (is_int($raw)) {
        $value = $raw;
    } elseif (is_string($raw) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $raw) === 1) {
        $value = (int) $raw;
    } else {
        throw new InvalidArgumentException('Image size must be a decimal integer');
    }
    if ($value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException('Image size is outside the allowed range');
    }
    return $value;
}

/** Names accepted by the fixed renderer palette. */
function estab_image_color_names(): array
{
    return [
        'white',
        'yellow',
        'blue',
        'red',
        'green',
        'lightyellow',
        'lightblue',
        'lighterblue',
        'mlightblue',
        'lightred',
        'lightgreen',
        'black',
    ];
}

/**
 * Validate the public button.php request.
 *
 * @return array<string, int|string>
 */
function estab_image_validate_button_request(array $query): array
{
    $type = estab_image_enum_parameter($query, 'type', ['icon', 'push', 'menue']);
    $colors = estab_image_color_names();

    if ($type === 'icon') {
        estab_image_assert_allowed_keys(
            $query,
            ['type', 'status', 'text', 'bg', 'textcol', 'bordercol', 'font_size']
        );
        return [
            'type' => 'icon',
            'status' => estab_image_enum_parameter($query, 'status', ['EIN', 'AUS']),
            'text' => estab_image_text_parameter($query, 'text', 32, 128),
            'bg' => estab_image_enum_parameter($query, 'bg', $colors, 'lightblue'),
            'textcol' => estab_image_enum_parameter($query, 'textcol', $colors, 'black'),
            'bordercol' => estab_image_enum_parameter($query, 'bordercol', $colors, 'black'),
            'font_size' => estab_image_integer_parameter($query, 'font_size', 1, 5, 5),
        ];
    }

    if ($type === 'push') {
        estab_image_assert_allowed_keys($query, ['type', 'status', 'text', 'textpos']);
        return [
            'type' => 'push',
            'status' => estab_image_enum_parameter($query, 'status', ['EIN', 'AUS']),
            'text' => estab_image_text_parameter($query, 'text', 32, 128),
            // "buttom" is the historical spelling used throughout the UI.
            'textpos' => estab_image_enum_parameter(
                $query,
                'textpos',
                ['top', 'buttom', 'left', 'right'],
                'buttom'
            ),
        ];
    }

    estab_image_assert_allowed_keys(
        $query,
        ['type', 'm_text', 'm_fs', 'm_form', 'width', 'bg', 'm_tc', 'm_bc']
    );
    return [
        'type' => 'menue',
        'm_text' => estab_image_text_parameter($query, 'm_text', 48, 192),
        'm_fs' => estab_image_integer_parameter($query, 'm_fs', 8, 16),
        'm_form' => estab_image_enum_parameter($query, 'm_form', ['rund', 'spitz']),
        'width' => estab_image_integer_parameter($query, 'width', 20, 320, 20),
        'bg' => estab_image_enum_parameter($query, 'bg', $colors, 'mlightblue'),
        'm_tc' => estab_image_enum_parameter($query, 'm_tc', $colors, 'black'),
        'm_bc' => estab_image_enum_parameter($query, 'm_bc', $colors, 'black'),
    ];
}

/**
 * Validate createbutton.php and kategobutton.php.
 *
 * @return array{icontext: string, color: string}
 */
function estab_image_validate_label_request(array $query): array
{
    estab_image_assert_allowed_keys($query, ['icontext', 'color']);
    return [
        'icontext' => estab_image_text_parameter($query, 'icontext', 48, 192),
        'color' => estab_image_enum_parameter(
            $query,
            'color',
            [
                'blue',
                'red',
                'yellow',
                'green',
                'lightblue',
                'lightred',
                'lightyellow',
                'lightgreen',
            ]
        ),
    ];
}

/** @return array<string, int> */
function estab_image_allocate_palette(mixed $image): array
{
    $rgb = [
        'white' => [255, 255, 255],
        'yellow' => [225, 225, 150],
        'blue' => [150, 150, 255],
        'red' => [255, 100, 100],
        'green' => [80, 255, 80],
        'lightyellow' => [225, 225, 200],
        'lightblue' => [200, 200, 255],
        'lighterblue' => [220, 220, 255],
        'mlightblue' => [220, 220, 255],
        'lightred' => [255, 200, 200],
        'lightgreen' => [200, 255, 200],
        'black' => [0, 0, 0],
    ];
    $palette = [];
    foreach ($rgb as $name => [$red, $green, $blue]) {
        $allocated = imagecolorallocate($image, $red, $green, $blue);
        if ($allocated === false) {
            throw new RuntimeException('Could not allocate image colour');
        }
        $palette[$name] = $allocated;
    }
    return $palette;
}

/** Create a bounded true-colour canvas. */
function estab_image_canvas(int $width, int $height): mixed
{
    if ($width < 1 || $width > 320 || $height < 1 || $height > 80) {
        throw new InvalidArgumentException('Calculated image dimensions are outside the allowed range');
    }
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('GD image canvas is unavailable');
    }
    $image = imagecreatetruecolor($width, $height);
    if ($image === false) {
        throw new RuntimeException('Could not create image canvas');
    }
    return $image;
}

/** @return array{width: int, height: int, min_x: int, min_y: int}|null */
function estab_image_ttf_metrics(string $text, int $size): ?array
{
    $font = dirname(__DIR__) . '/4fbak/fonts/georgiaz.ttf';
    if (!function_exists('imagettfbbox') || !is_readable($font)) {
        return null;
    }
    $box = @imagettfbbox($size, 0, $font, $text);
    if (!is_array($box)) {
        return null;
    }
    $minimumX = min($box[0], $box[2], $box[4], $box[6]);
    $maximumX = max($box[0], $box[2], $box[4], $box[6]);
    $minimumY = min($box[1], $box[3], $box[5], $box[7]);
    $maximumY = max($box[1], $box[3], $box[5], $box[7]);
    return [
        'width' => max(1, $maximumX - $minimumX),
        'height' => max(1, $maximumY - $minimumY),
        'min_x' => $minimumX,
        'min_y' => $minimumY,
    ];
}

/** Draw centred text with the bundled font, falling back to a GD font. */
function estab_image_draw_centred_text(
    mixed $image,
    string $text,
    int $fontSize,
    int $width,
    int $height,
    int $colour,
    ?array $metrics = null
): void {
    $font = dirname(__DIR__) . '/4fbak/fonts/georgiaz.ttf';
    if ($metrics !== null && function_exists('imagettftext')) {
        $x = (int) floor(($width - $metrics['width']) / 2) - $metrics['min_x'];
        $y = (int) floor(($height - $metrics['height']) / 2) - $metrics['min_y'];
        if (@imagettftext($image, $fontSize, 0, $x, $y, $colour, $font, $text) !== false) {
            return;
        }
    }

    $fontId = 5;
    $textWidth = imagefontwidth($fontId) * strlen($text);
    $textHeight = imagefontheight($fontId);
    imagestring(
        $image,
        $fontId,
        max(1, (int) floor(($width - $textWidth) / 2)),
        max(1, (int) floor(($height - $textHeight) / 2)),
        $text,
        $colour
    );
}

/** Render the validated button.php parameter set. */
function estab_image_render_button(array $parameters): mixed
{
    if ($parameters['type'] === 'icon') {
        $font = (int) $parameters['font_size'];
        $text = (string) $parameters['text'];
        $height = max(16, (int) ceil(imagefontheight($font) * 1.5));
        $width = imagefontwidth($font) * strlen($text) + $height;
        $image = estab_image_canvas($width, $height);
        $palette = estab_image_allocate_palette($image);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $palette[$parameters['bg']]);
        if ($parameters['status'] === 'EIN') {
            imagerectangle($image, 0, 0, $width - 1, $height - 1, $palette[$parameters['bordercol']]);
        } else {
            imageline($image, 0, $height - 1, 0, 0, $palette[$parameters['bordercol']]);
            imageline($image, 0, 0, $width - 1, 0, $palette[$parameters['bordercol']]);
            imageline(
                $image,
                $width - 1,
                0,
                $width - 1,
                $height - 1,
                $palette[$parameters['bordercol']]
            );
        }
        imagestring(
            $image,
            $font,
            max(1, (int) floor(($width - imagefontwidth($font) * strlen($text)) / 2)),
            max(1, (int) floor(($height - imagefontheight($font)) / 2)),
            $text,
            $palette[$parameters['textcol']]
        );
        return $image;
    }

    if ($parameters['type'] === 'push') {
        $font = 5;
        $text = (string) $parameters['text'];
        $textWidth = imagefontwidth($font) * strlen($text);
        $textHeight = imagefontheight($font);
        $switchWidth = 20;
        $switchHeight = 30;
        $position = (string) $parameters['textpos'];

        if ($position === 'left' || $position === 'right') {
            $width = $switchWidth + $textWidth + 6;
            $height = max($switchHeight, $textHeight + 4);
            $switchX = $position === 'left' ? $textWidth + 6 : 0;
            $textX = $position === 'left' ? 1 : $switchWidth + 5;
            $switchY = 0;
            $textY = (int) floor(($height - $textHeight) / 2);
        } else {
            $width = max($switchWidth, $textWidth + 2);
            $height = $switchHeight + $textHeight + 4;
            $switchX = (int) floor(($width - $switchWidth) / 2);
            $textX = max(1, (int) floor(($width - $textWidth) / 2));
            if ($position === 'top') {
                $textY = 1;
                $switchY = $textHeight + 4;
            } else {
                $switchY = 0;
                $textY = $switchHeight + 2;
            }
        }

        $image = estab_image_canvas($width, $height);
        $palette = estab_image_allocate_palette($image);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $palette['white']);
        $active = $parameters['status'] === 'EIN';
        $centreX = $switchX + (int) floor($switchWidth / 2);
        $centreY = $switchY + ($active ? 15 : 8);
        imagefilledellipse(
            $image,
            $centreX,
            $centreY,
            12,
            12,
            $active ? $palette['green'] : $palette['red']
        );
        imageellipse($image, $centreX, $centreY, 13, 13, $palette['blue']);
        imagestring($image, $font, $textX, $textY, $text, $palette['black']);
        return $image;
    }

    $text = (string) $parameters['m_text'];
    $fontSize = (int) $parameters['m_fs'];
    $metrics = estab_image_ttf_metrics($text, $fontSize);
    $textWidth = $metrics['width'] ?? (imagefontwidth(5) * strlen($text));
    $textHeight = $metrics['height'] ?? imagefontheight(5);
    $height = max(24, $textHeight + 10);
    $width = max((int) $parameters['width'], $textWidth + $height + 6);
    $image = estab_image_canvas($width, $height);
    $palette = estab_image_allocate_palette($image);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $palette['white']);

    if ($parameters['m_form'] === 'spitz') {
        $offset = max(6, (int) floor($height / 2));
        $points = [$offset, 1, $width - $offset - 1, 1, $width - 1, (int) floor($height / 2),
            $width - $offset - 1, $height - 2, $offset, $height - 2, 1, (int) floor($height / 2)];
        imagefilledpolygon($image, $points, $palette[$parameters['bg']]);
        imagepolygon($image, $points, $palette[$parameters['m_bc']]);
    } else {
        $radius = $height - 2;
        imagefilledrectangle(
            $image,
            (int) floor($radius / 2),
            1,
            $width - (int) floor($radius / 2) - 1,
            $height - 2,
            $palette[$parameters['bg']]
        );
        imagefilledellipse(
            $image,
            (int) floor($radius / 2),
            (int) floor($height / 2),
            $radius,
            $radius,
            $palette[$parameters['bg']]
        );
        imagefilledellipse(
            $image,
            $width - (int) floor($radius / 2) - 1,
            (int) floor($height / 2),
            $radius,
            $radius,
            $palette[$parameters['bg']]
        );
        imageline(
            $image,
            (int) floor($radius / 2),
            1,
            $width - (int) floor($radius / 2) - 1,
            1,
            $palette[$parameters['m_bc']]
        );
        imageline(
            $image,
            (int) floor($radius / 2),
            $height - 2,
            $width - (int) floor($radius / 2) - 1,
            $height - 2,
            $palette[$parameters['m_bc']]
        );
        imagearc(
            $image,
            (int) floor($radius / 2),
            (int) floor($height / 2),
            $radius,
            $radius,
            90,
            270,
            $palette[$parameters['m_bc']]
        );
        imagearc(
            $image,
            $width - (int) floor($radius / 2) - 1,
            (int) floor($height / 2),
            $radius,
            $radius,
            270,
            450,
            $palette[$parameters['m_bc']]
        );
    }
    estab_image_draw_centred_text(
        $image,
        $text,
        $fontSize,
        $width,
        $height,
        $palette[$parameters['m_tc']],
        $metrics
    );
    return $image;
}

/** Render createbutton.php or kategobutton.php after validation. */
function estab_image_render_label(array $parameters, bool $categoryStyle): mixed
{
    $text = (string) $parameters['icontext'];
    $fontSize = 12;
    $metrics = estab_image_ttf_metrics($text, $fontSize);
    $textWidth = $metrics['width'] ?? (imagefontwidth(5) * strlen($text));
    $textHeight = $metrics['height'] ?? imagefontheight(5);
    $height = max(24, $textHeight + 10);
    $width = $textWidth + $height;
    $image = estab_image_canvas($width, $height);
    $palette = estab_image_allocate_palette($image);
    $colour = (string) $parameters['color'];
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $palette[$colour]);

    $highlighted = str_starts_with($colour, 'light');
    if (!$categoryStyle || $highlighted) {
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $palette['black']);
        if (!$categoryStyle) {
            imagerectangle($image, 1, 1, $width - 2, $height - 2, $palette['black']);
        }
    } else {
        imageline($image, 0, $height - 1, 0, 0, $palette['black']);
        imageline($image, 1, $height - 1, 1, 0, $palette['black']);
        imageline($image, 0, 0, $width - 1, 0, $palette['black']);
        imageline($image, 0, 1, $width - 1, 1, $palette['black']);
        imageline($image, $width - 1, 0, $width - 1, $height - 1, $palette['black']);
        imageline($image, $width - 2, 0, $width - 2, $height - 1, $palette['black']);
    }

    $textColour = (!$categoryStyle && in_array($colour, ['blue', 'lightblue'], true))
        ? $palette['white']
        : $palette['black'];
    estab_image_draw_centred_text(
        $image,
        $text,
        $fontSize,
        $width,
        $height,
        $textColour,
        $metrics
    );
    return $image;
}

/** Send a deliberately generic error response for the public renderers. */
function estab_image_http_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $message;
}

/** Validate the HTTP method shared by every image endpoint. */
function estab_image_http_method(array $server): string
{
    $method = $server['REQUEST_METHOD'] ?? 'GET';
    if (!is_string($method) || !in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        throw new LogicException('Unsupported image request method');
    }
    return $method;
}

/** Serve button.php without exposing validation or rendering diagnostics. */
function estab_image_serve_button(array $server, array $query): void
{
    $image = null;
    try {
        $method = estab_image_http_method($server);
        $parameters = estab_image_validate_button_request($query);
        $image = estab_image_render_button($parameters);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');
        if ($method !== 'HEAD' && !imagepng($image)) {
            throw new RuntimeException('PNG encoding failed');
        }
    } catch (InvalidArgumentException) {
        estab_image_http_error(400, 'Ungültige Bildparameter.');
    } catch (LogicException) {
        estab_image_http_error(405, 'Method not allowed.');
    } catch (Throwable $exception) {
        error_log('eStab image renderer failed: ' . $exception->getMessage());
        estab_image_http_error(500, 'Bild konnte nicht erzeugt werden.');
    } finally {
        // GdImage is released by normal object lifetime; explicit destruction
        // is deprecated and a no-op on the PHP 8.5 runtime.
        $image = null;
    }
}

/** Serve one of the two category-label endpoints. */
function estab_image_serve_label(array $server, array $query, bool $categoryStyle): void
{
    $image = null;
    try {
        $method = estab_image_http_method($server);
        $parameters = estab_image_validate_label_request($query);
        $image = estab_image_render_label($parameters, $categoryStyle);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');
        if ($method !== 'HEAD' && !imagepng($image)) {
            throw new RuntimeException('PNG encoding failed');
        }
    } catch (InvalidArgumentException) {
        estab_image_http_error(400, 'Ungültige Bildparameter.');
    } catch (LogicException) {
        estab_image_http_error(405, 'Method not allowed.');
    } catch (Throwable $exception) {
        error_log('eStab image renderer failed: ' . $exception->getMessage());
        estab_image_http_error(500, 'Bild konnte nicht erzeugt werden.');
    } finally {
        $image = null;
    }
}
