<?php

/**
 * Authenticated file-delivery boundary for attachments and generated forms.
 */

require_once __DIR__ . '/auth.php';

function estab_file_area(string $area): string
{
    if (!in_array($area, ['attachment', 'vordruck'], true)) {
        throw new InvalidArgumentException('Unknown file area');
    }
    return $area;
}

function estab_file_validate_name(string $area, string $filename): string
{
    $area = estab_file_area($area);
    $filename = trim($filename);
    if (
        $filename === ''
        || strlen($filename) > 255
        || $filename !== basename($filename)
        || str_contains($filename, '/')
        || str_contains($filename, '\\')
        || str_contains($filename, '..')
        || preg_match('/\p{C}/u', $filename) === 1
    ) {
        throw new InvalidArgumentException('Invalid stored filename');
    }

    if ($area === 'attachment') {
        if (
            preg_match('/\A[A-Za-z0-9_-]{2,238}\.([A-Za-z0-9]{1,16})\z/D', $filename, $parts) !== 1
            || !in_array(strtolower($parts[1]), [
                'jpg', 'jpeg', 'tif', 'tiff', 'gif', 'avi', 'png', 'bmp',
                'zip', 'pdf', 'doc', 'xls', 'odt', 'txt', 'xia',
            ], true)
        ) {
            throw new InvalidArgumentException('Invalid attachment filename');
        }
        return $filename;
    }

    if (
        preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} ._-]{0,246}\.(pdf|png|jpe?g)\z/Diu', $filename) !== 1
    ) {
        throw new InvalidArgumentException('Invalid generated-form filename');
    }
    return $filename;
}

/** Resolve a single basename below an allowed root, rejecting escaping links. */
function estab_file_resolve(string $root, string $area, string $filename): string
{
    $filename = estab_file_validate_name($area, $filename);
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
        throw new RuntimeException('File area is unavailable');
    }

    $candidate = realpath($resolvedRoot . DIRECTORY_SEPARATOR . $filename);
    $prefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (
        $candidate === false
        || !str_starts_with($candidate, $prefix)
        || !is_file($candidate)
        || !is_readable($candidate)
    ) {
        throw new RuntimeException('File not found');
    }
    return $candidate;
}

/**
 * Return safe generated forms only; invalid names, links and subdirectories
 * are deliberately omitted.
 *
 * @return array<int,array{name:string,size:int,modified:int}>
 */
function estab_file_list(string $root, string $area): array
{
    $area = estab_file_area($area);
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
        return [];
    }

    $entries = scandir($resolvedRoot);
    if ($entries === false) {
        return [];
    }
    $files = [];
    foreach ($entries as $entry) {
        try {
            $safeName = estab_file_validate_name($area, $entry);
            $path = estab_file_resolve($resolvedRoot, $area, $safeName);
        } catch (InvalidArgumentException | RuntimeException) {
            continue;
        }
        $files[] = [
            'name' => $safeName,
            'size' => max(0, (int) filesize($path)),
            'modified' => max(0, (int) filemtime($path)),
        ];
    }
    usort(
        $files,
        static fn (array $left, array $right): int =>
            $right['modified'] <=> $left['modified']
            ?: strnatcasecmp($left['name'], $right['name'])
    );
    return $files;
}

function estab_file_download_url(string $endpoint, string $area, string $filename): string
{
    $area = estab_file_area($area);
    $filename = estab_file_validate_name($area, $filename);
    $separator = str_contains($endpoint, '?') ? '&' : '?';
    return $endpoint . $separator . http_build_query(
        ['area' => $area, 'file' => $filename],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

function estab_file_content_type(string $path): string
{
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    return is_string($detected) && preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+\z/Di', $detected) === 1
        ? $detected
        : 'application/octet-stream';
}

function estab_file_content_disposition(string $filename, bool $inline): string
{
    $filename = str_replace(["\r", "\n", '"', '\\'], '_', $filename);
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';
    return ($inline ? 'inline' : 'attachment')
        . '; filename="' . $ascii . '"'
        . "; filename*=UTF-8''" . rawurlencode($filename);
}
