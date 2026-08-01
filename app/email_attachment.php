<?php

declare(strict_types=1);

/**
 * Bounded, dependency-free RFC-822/MIME reader for .eml browser previews.
 *
 * This module deliberately returns metadata only for enclosed files. It never
 * exposes decoded attachment bytes. The original .eml remains the sole
 * downloadable object. `body_html` and `headers_html` are escaped presentation
 * values; callers must use those values (or escape the plain values themselves)
 * when inserting data into HTML.
 */

const ESTAB_EMAIL_ATTACHMENT_MAX_INPUT_BYTES = 20971520;
const ESTAB_EMAIL_ATTACHMENT_MAX_HEADER_BYTES = 262144;
const ESTAB_EMAIL_ATTACHMENT_MAX_PARTS = 200;
const ESTAB_EMAIL_ATTACHMENT_MAX_DEPTH = 10;
const ESTAB_EMAIL_ATTACHMENT_MAX_DECODED_BYTES = 25165824;
const ESTAB_EMAIL_ATTACHMENT_MAX_BODY_BYTES = 1048576;
const ESTAB_EMAIL_ATTACHMENT_MAX_HEADER_VALUE_BYTES = 8192;

final class EstabEmailAttachmentParseException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

/** @return array<string,int> */
function estab_email_attachment_limits(?array $overrides = null): array
{
    $limits = [
        'input_bytes' => ESTAB_EMAIL_ATTACHMENT_MAX_INPUT_BYTES,
        'header_bytes' => ESTAB_EMAIL_ATTACHMENT_MAX_HEADER_BYTES,
        'parts' => ESTAB_EMAIL_ATTACHMENT_MAX_PARTS,
        'depth' => ESTAB_EMAIL_ATTACHMENT_MAX_DEPTH,
        'decoded_bytes' => ESTAB_EMAIL_ATTACHMENT_MAX_DECODED_BYTES,
        'body_bytes' => ESTAB_EMAIL_ATTACHMENT_MAX_BODY_BYTES,
        'header_value_bytes' => ESTAB_EMAIL_ATTACHMENT_MAX_HEADER_VALUE_BYTES,
    ];
    $hardMaximums = [
        'input_bytes' => 52428800,
        'header_bytes' => 1048576,
        'parts' => 1000,
        'depth' => 30,
        'decoded_bytes' => 52428800,
        'body_bytes' => 5242880,
        'header_value_bytes' => 65536,
    ];
    foreach ($overrides ?? [] as $name => $value) {
        if (
            !is_string($name)
            || !array_key_exists($name, $limits)
            || !is_int($value)
            || $value < 1
            || $value > $hardMaximums[$name]
        ) {
            throw new InvalidArgumentException('Invalid email parser limit');
        }
        $limits[$name] = $value;
    }
    return $limits;
}

/** @return array<string,mixed> */
function estab_email_attachment_empty_result(): array
{
    $headers = ['from' => '', 'to' => '', 'cc' => '', 'date' => '', 'subject' => ''];
    return [
        'ok' => false,
        'error_code' => null,
        'error' => null,
        'headers' => $headers,
        'headers_html' => $headers,
        'body' => '',
        'body_html' => '',
        'body_source' => 'none',
        'attachments' => [],
        'warnings' => [],
    ];
}

/**
 * Parse one complete .eml string without throwing for hostile message data.
 * Invalid limit configuration remains a programmer error and is thrown.
 *
 * @return array<string,mixed>
 */
function estab_email_attachment_parse(string $raw, ?array $limits = null): array
{
    $limits = estab_email_attachment_limits($limits);
    $result = estab_email_attachment_empty_result();
    try {
        $parser = new EstabEmailAttachmentParser($limits);
        return $parser->parse($raw);
    } catch (EstabEmailAttachmentParseException $exception) {
        $result['error_code'] = $exception->errorCode;
        $result['error'] = $exception->getMessage();
        return $result;
    } catch (Throwable) {
        $result['error_code'] = 'internal_error';
        $result['error'] = 'Die E-Mail konnte nicht sicher dargestellt werden.';
        return $result;
    }
}

/**
 * Read and parse an already-authorized stream, stopping before an oversized
 * file is copied completely into memory.
 *
 * @param resource $stream
 * @return array<string,mixed>
 */
function estab_email_attachment_parse_stream(mixed $stream, ?array $limits = null): array
{
    $limits = estab_email_attachment_limits($limits);
    if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
        throw new InvalidArgumentException('A readable email stream is required');
    }
    $raw = '';
    while (!feof($stream)) {
        $remaining = $limits['input_bytes'] + 1 - strlen($raw);
        if ($remaining <= 0) {
            return estab_email_attachment_error_result(
                'message_too_large',
                'Die E-Mail ist für die sichere Vorschau zu groß.'
            );
        }
        $chunk = fread($stream, min(8192, $remaining));
        if ($chunk === false) {
            return estab_email_attachment_error_result(
                'read_failed',
                'Die E-Mail konnte nicht gelesen werden.'
            );
        }
        $raw .= $chunk;
    }
    return estab_email_attachment_parse($raw, $limits);
}

/** @return array<string,mixed> */
function estab_email_attachment_error_result(string $code, string $message): array
{
    $result = estab_email_attachment_empty_result();
    $result['error_code'] = $code;
    $result['error'] = $message;
    return $result;
}

/** Escape untrusted email text for an HTML text context. */
function estab_email_attachment_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

final class EstabEmailAttachmentParser
{
    private int $partCount = 0;
    private int $decodedBytes = 0;
    private int $candidateOrder = 0;
    /** @var list<string> */
    private array $warnings = [];
    /** @var list<array{source:string,text:string,order:int}> */
    private array $bodyCandidates = [];
    /** @var list<array{filename:string,content_type:string,size:int}> */
    private array $attachments = [];

    /** @param array<string,int> $limits */
    public function __construct(private readonly array $limits)
    {
    }

    /** @return array<string,mixed> */
    public function parse(string $raw): array
    {
        if (strlen($raw) > $this->limits['input_bytes']) {
            $this->fail('message_too_large', 'Die E-Mail ist für die sichere Vorschau zu groß.');
        }
        if ($raw === '') {
            $this->fail('empty_message', 'Die E-Mail-Datei ist leer.');
        }

        [$rawHeaders] = $this->splitEntity($raw);
        $rootHeaders = $this->parseHeaders($rawHeaders);
        $this->parseEntity($raw, 0, true);

        $headers = [
            'from' => $this->headerValue($rootHeaders, 'from', false),
            'to' => $this->headerValue($rootHeaders, 'to', true),
            'cc' => $this->headerValue($rootHeaders, 'cc', true),
            'date' => $this->headerValue($rootHeaders, 'date', false),
            'subject' => $this->headerValue($rootHeaders, 'subject', false),
        ];
        $headersHtml = [];
        foreach ($headers as $name => $value) {
            $headersHtml[$name] = estab_email_attachment_html($value);
        }

        usort(
            $this->bodyCandidates,
            static fn (array $left, array $right): int =>
                ($left['source'] === 'plain' ? 0 : 1)
                    <=> ($right['source'] === 'plain' ? 0 : 1)
                ?: $left['order'] <=> $right['order']
        );
        $candidate = $this->bodyCandidates[0] ?? null;
        $body = is_array($candidate) ? $candidate['text'] : '';
        $source = is_array($candidate) ? $candidate['source'] : 'none';

        return [
            'ok' => true,
            'error_code' => null,
            'error' => null,
            'headers' => $headers,
            'headers_html' => $headersHtml,
            'body' => $body,
            'body_html' => nl2br(estab_email_attachment_html($body), false),
            'body_source' => $source,
            'attachments' => $this->attachments,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    private function parseEntity(string $raw, int $depth, bool $root): void
    {
        if ($depth > $this->limits['depth']) {
            $this->fail('mime_depth_exceeded', 'Die MIME-Struktur der E-Mail ist zu tief verschachtelt.');
        }
        $this->partCount++;
        if ($this->partCount > $this->limits['parts']) {
            $this->fail('mime_parts_exceeded', 'Die E-Mail enthält zu viele MIME-Bestandteile.');
        }

        [$rawHeaders, $body] = $this->splitEntity($raw);
        $headers = $this->parseHeaders($rawHeaders);
        [$contentType, $typeParameters] = $this->structuredHeader(
            $headers['content-type'][0] ?? 'text/plain; charset=us-ascii'
        );
        [$disposition, $dispositionParameters] = $this->structuredHeader(
            $headers['content-disposition'][0] ?? ''
        );
        $contentType = $this->contentType($contentType);
        $filename = $this->attachmentFilename($dispositionParameters, $typeParameters);
        $isAttachment = !$root && (
            $disposition === 'attachment'
            || $filename !== null
        );

        if ($isAttachment) {
            $decoded = $this->decodeTransfer(
                $body,
                $headers['content-transfer-encoding'][0] ?? ''
            );
            $this->attachments[] = [
                'filename' => $filename ?? $this->unnamedAttachment($contentType),
                'content_type' => $contentType,
                'size' => strlen($decoded),
            ];
            return;
        }

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $typeParameters['boundary'] ?? null;
            if (!is_string($boundary) || $boundary === '' || strlen($boundary) > 200) {
                $this->fail('mime_boundary_missing', 'Eine MIME-Grenze der E-Mail fehlt oder ist ungültig.');
            }
            $parts = $this->splitMultipart($body, $boundary);
            if ($parts === []) {
                $this->fail('mime_boundary_invalid', 'Die MIME-Struktur der E-Mail ist beschädigt.');
            }
            foreach ($parts as $part) {
                $this->parseEntity($part, $depth + 1, false);
            }
            return;
        }

        $decoded = $this->decodeTransfer(
            $body,
            $headers['content-transfer-encoding'][0] ?? ''
        );
        if ($contentType === 'message/rfc822' && !$root) {
            $this->attachments[] = [
                'filename' => $this->unnamedAttachment($contentType),
                'content_type' => $contentType,
                'size' => strlen($decoded),
            ];
            return;
        }
        if ($contentType !== 'text/plain' && $contentType !== 'text/html') {
            if (!$root || $decoded !== '') {
                $this->attachments[] = [
                    'filename' => $this->unnamedAttachment($contentType),
                    'content_type' => $contentType,
                    'size' => strlen($decoded),
                ];
            }
            return;
        }

        $text = $this->toUtf8($decoded, $typeParameters['charset'] ?? 'us-ascii');
        if ($contentType === 'text/html') {
            $text = $this->htmlToText($text);
            $source = 'html';
        } else {
            $text = $this->cleanBodyText($text);
            $source = 'plain';
        }
        if (strlen($text) > $this->limits['body_bytes']) {
            $text = $this->truncateUtf8($text, $this->limits['body_bytes']) . "\n…";
            $this->warnings[] = 'Der Nachrichtentext wurde für die Vorschau gekürzt.';
        }
        $this->bodyCandidates[] = [
            'source' => $source,
            'text' => $text,
            'order' => $this->candidateOrder++,
        ];
    }

    /** @return array{0:string,1:string} */
    private function splitEntity(string $raw): array
    {
        $positions = [];
        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $separator) {
            $position = strpos($raw, $separator);
            if ($position !== false) {
                $positions[] = [$position, strlen($separator)];
            }
        }
        if ($positions === []) {
            $this->fail('malformed_message', 'Die E-Mail besitzt keinen gültigen Kopfbereich.');
        }
        usort($positions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        [$position, $length] = $positions[0];
        if ($position > $this->limits['header_bytes']) {
            $this->fail('headers_too_large', 'Der Kopfbereich der E-Mail ist zu groß.');
        }
        return [substr($raw, 0, $position), substr($raw, $position + $length)];
    }

    /** @return array<string,list<string>> */
    private function parseHeaders(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                if ($unfolded === []) {
                    $this->warnings[] = 'Eine ungültige Header-Fortsetzung wurde ignoriert.';
                    continue;
                }
                $last = array_key_last($unfolded);
                $unfolded[$last] .= ' ' . ltrim($line, " \t");
            } else {
                $unfolded[] = $line;
            }
        }

        $headers = [];
        foreach ($unfolded as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                if (trim($line) !== '') {
                    $this->warnings[] = 'Eine ungültige Header-Zeile wurde ignoriert.';
                }
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            if (preg_match('/\A[a-z0-9!#$%&\'*+.^_`|~-]{1,78}\z/D', $name) !== 1) {
                $this->warnings[] = 'Ein ungültiger Header-Name wurde ignoriert.';
                continue;
            }
            $value = trim(substr($line, $colon + 1));
            if (strlen($value) > $this->limits['header_value_bytes'] * 4) {
                $this->fail('header_value_too_large', 'Ein Header-Wert der E-Mail ist zu groß.');
            }
            $headers[$name][] = $value;
        }
        return $headers;
    }

    /** @param array<string,list<string>> $headers */
    private function headerValue(array $headers, string $name, bool $combine): string
    {
        $values = $headers[$name] ?? [];
        if (!$combine && $values !== []) {
            $values = [$values[0]];
        }
        $decoded = [];
        foreach ($values as $value) {
            $decoded[] = $this->cleanHeaderText(
                $this->ensureUtf8($this->decodeEncodedWords($value))
            );
        }
        $result = implode(', ', array_filter($decoded, static fn (string $v): bool => $v !== ''));
        if (strlen($result) > $this->limits['header_value_bytes']) {
            $result = $this->truncateUtf8($result, $this->limits['header_value_bytes']) . '…';
            $this->warnings[] = 'Ein Header-Wert wurde für die Vorschau gekürzt.';
        }
        return $result;
    }

    private function decodeEncodedWords(string $value): string
    {
        $value = preg_replace('/(\?=)[ \t]+(?==\?)/', '$1$2', $value) ?? $value;
        $decoded = preg_replace_callback(
            '/=\?([^?\x00-\x20]{1,40})\?([bq])\?([^?\r\n]{0,16384})\?=/i',
            function (array $match): string {
                $bytes = strtolower($match[2]) === 'b'
                    ? base64_decode($match[3], true)
                    : quoted_printable_decode(str_replace('_', ' ', $match[3]));
                if (!is_string($bytes)) {
                    $this->warnings[] = 'Ein codierter Header-Wert konnte nicht gelesen werden.';
                    return '';
                }
                return $this->toUtf8($bytes, $match[1]);
            },
            $value
        );
        return is_string($decoded) ? $decoded : $value;
    }

    /** @return array{0:string,1:array<string,string>} */
    private function structuredHeader(string $value): array
    {
        $segments = [];
        $current = '';
        $quoted = false;
        $escaped = false;
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $character = $value[$index];
            if ($escaped) {
                // MIME quoted-pairs escape quotes and backslashes. Preserve a
                // non-special backslash so Windows-style sender paths can
                // still be reduced to their basename below.
                $current .= ($character === '\\' || $character === '"')
                    ? $character
                    : '\\' . $character;
                $escaped = false;
            } elseif ($quoted && $character === '\\') {
                $escaped = true;
            } elseif ($character === '"') {
                $quoted = !$quoted;
            } elseif ($character === ';' && !$quoted) {
                $segments[] = trim($current);
                $current = '';
            } else {
                $current .= $character;
            }
        }
        $segments[] = trim($current);
        $main = strtolower(array_shift($segments) ?? '');
        $rawParameters = [];
        foreach ($segments as $segment) {
            $equals = strpos($segment, '=');
            if ($equals === false) {
                continue;
            }
            $name = strtolower(trim(substr($segment, 0, $equals)));
            $parameterValue = trim(substr($segment, $equals + 1));
            if ($name === '' || strlen($parameterValue) > 4096) {
                continue;
            }
            $rawParameters[$name] = $parameterValue;
        }
        $parameters = [];
        foreach ($rawParameters as $name => $parameterValue) {
            if (!str_contains($name, '*')) {
                $parameters[$name] = $parameterValue;
            }
        }
        foreach (['filename', 'name'] as $base) {
            $extended = $this->extendedParameter($rawParameters, $base);
            if ($extended !== null) {
                $parameters[$base] = $extended;
            }
        }
        return [$main, $parameters];
    }

    /** @param array<string,string> $parameters */
    private function extendedParameter(array $parameters, string $base): ?string
    {
        $value = null;
        if (isset($parameters[$base . '*'])) {
            $value = $parameters[$base . '*'];
        } else {
            $pieces = [];
            $encoded = false;
            foreach ($parameters as $name => $piece) {
                if (preg_match('/\A' . preg_quote($base, '/') . '\*(\d+)(\*)?\z/D', $name, $match) === 1) {
                    $pieces[(int) $match[1]] = $piece;
                    $encoded = $encoded || ($match[2] ?? '') === '*';
                }
            }
            if ($pieces !== []) {
                ksort($pieces);
                if (array_keys($pieces) === range(0, count($pieces) - 1)) {
                    $value = implode('', $pieces);
                    if (!$encoded) {
                        return $value;
                    }
                }
            }
        }
        if (!is_string($value)) {
            return null;
        }
        $charset = 'utf-8';
        if (preg_match("/\\A([^']*)'[^']*'(.*)\\z/Ds", $value, $match) === 1) {
            $charset = $match[1] !== '' ? $match[1] : 'utf-8';
            $value = $match[2];
        }
        if (preg_match('/%(?![0-9a-f]{2})/i', $value) === 1) {
            $this->warnings[] = 'Ein codierter Dateiname war ungültig.';
            return null;
        }
        return $this->toUtf8(rawurldecode($value), $charset);
    }

    /** @param array<string,string> $disposition @param array<string,string> $type */
    private function attachmentFilename(array $disposition, array $type): ?string
    {
        $filename = $disposition['filename'] ?? $type['name'] ?? null;
        if (!is_string($filename)) {
            return null;
        }
        $filename = $this->ensureUtf8($this->decodeEncodedWords($filename));
        $filename = str_replace('\\', '/', $filename);
        $filename = basename($filename);
        $filename = $this->cleanHeaderText($filename);
        $filename = $this->truncateUtf8($filename, 255);
        return $filename !== '' && $filename !== '.' ? $filename : null;
    }

    private function contentType(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/\A[a-z0-9!#$&^_.+-]{1,64}\/[a-z0-9!#$&^_.+-]{1,64}\z/D', $value) === 1
            ? $value
            : 'application/octet-stream';
    }

    /** @return list<string> */
    private function splitMultipart(string $body, string $boundary): array
    {
        // RFC 2046 permits spaces inside a quoted boundary, but not as its
        // final character. Keep matching strictly line-based and ASCII-only.
        if (
            preg_match('/\A[\x20-\x7E]{1,200}\z/D', $boundary) !== 1
            || $boundary[0] === ' '
            || str_ends_with($boundary, ' ')
        ) {
            return [];
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        $open = '--' . $boundary;
        $close = $open . '--';
        $parts = [];
        $current = null;
        $seenOpen = false;
        $closed = false;
        foreach ($lines as $line) {
            $marker = rtrim($line, " \t");
            if ($marker === $open || $marker === $close) {
                if (is_array($current)) {
                    $parts[] = implode("\n", $current);
                }
                if ($marker === $close) {
                    $current = null;
                    $closed = true;
                    break;
                }
                $seenOpen = true;
                $current = [];
            } elseif (is_array($current)) {
                $current[] = $line;
            }
        }
        if (!$seenOpen || !$closed) {
            return [];
        }
        return array_values(array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== ''
        ));
    }

    private function decodeTransfer(string $body, string $encoding): string
    {
        $encoding = strtolower(trim($encoding));
        if ($encoding === 'base64') {
            $compact = str_replace(["\r", "\n", "\t", ' '], '', $body);
            $decoded = base64_decode($compact, true);
            if (!is_string($decoded)) {
                $this->fail('invalid_transfer_encoding', 'Ein MIME-Bestandteil ist ungültig codiert.');
            }
        } elseif ($encoding === 'quoted-printable') {
            $decoded = quoted_printable_decode($body);
        } elseif ($encoding === '' || in_array($encoding, ['7bit', '8bit', 'binary'], true)) {
            $decoded = $body;
        } else {
            $this->fail('unsupported_transfer_encoding', 'Die E-Mail verwendet eine nicht unterstützte Inhaltscodierung.');
        }
        $this->decodedBytes += strlen($decoded);
        if ($this->decodedBytes > $this->limits['decoded_bytes']) {
            $this->fail('decoded_data_too_large', 'Die decodierten Inhalte der E-Mail sind zu groß.');
        }
        return $decoded;
    }

    private function toUtf8(string $value, string $charset): string
    {
        $charset = strtolower(trim($charset, " \t\r\n\"'"));
        if ($charset === '' || in_array($charset, ['us-ascii', 'ascii', 'utf-8', 'utf8'], true)) {
            return $this->ensureUtf8($value);
        }
        $converted = false;
        if (function_exists('mb_convert_encoding')) {
            try {
                $converted = mb_convert_encoding($value, 'UTF-8', $charset);
            } catch (ValueError) {
                $converted = false;
            }
        }
        if (!is_string($converted) && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
        }
        if (!is_string($converted)) {
            $this->warnings[] = 'Ein unbekannter Zeichensatz wurde ersatzweise gelesen.';
            return $this->ensureUtf8($value);
        }
        return $this->ensureUtf8($converted);
    }

    private function ensureUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }
        if (function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
            if (is_string($converted)) {
                return $converted;
            }
        }
        $result = '';
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $byte = ord($value[$index]);
            if ($byte < 128) {
                $result .= $value[$index];
            } else {
                $result .= chr(0xC0 | ($byte >> 6)) . chr(0x80 | ($byte & 0x3F));
            }
        }
        return $result;
    }

    private function cleanHeaderText(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        $value = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/[ \t]+/', ' ', $value) ?? $value);
    }

    private function cleanBodyText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $value = preg_replace('/[ \t]+\n/', "\n", $value) ?? $value;
        return trim($value);
    }

    private function htmlToText(string $html): string
    {
        $withoutActive = preg_replace(
            '/<(script|style|noscript|template|svg|head)\b[^>]*>.*?<\/\1\s*>/isu',
            '',
            $html
        );
        if (!is_string($withoutActive)) {
            $withoutActive = $html;
        }
        $withoutActive = preg_replace(
            '/<(script|style|noscript|template|svg|head)\b[^>]*>.*\z/isu',
            '',
            $withoutActive
        ) ?? $withoutActive;
        $withBreaks = preg_replace(
            '/<\s*\/?\s*(?:br|p|div|li|tr|table|h[1-6]|blockquote|pre|hr)\b[^>]*>/iu',
            "\n",
            $withoutActive
        ) ?? $withoutActive;
        $text = strip_tags($withBreaks);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $text = $this->cleanBodyText($text);
        return preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    }

    private function truncateUtf8(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }
        $value = substr($value, 0, $bytes);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    private function unnamedAttachment(string $contentType): string
    {
        $extension = [
            'message/rfc822' => 'eml',
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'text/plain' => 'txt',
        ][$contentType] ?? 'bin';
        return 'Unbenannte Anlage ' . (count($this->attachments) + 1) . '.' . $extension;
    }

    private function fail(string $code, string $message): never
    {
        throw new EstabEmailAttachmentParseException($code, $message);
    }
}
