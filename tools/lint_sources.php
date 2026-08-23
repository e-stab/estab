<?php

/**
 * Lint every tracked PHP source in one process.
 *
 * `php -l` needs one interpreter start per file, which dominates the static
 * suite. opcache_compile_file() reports the same two classes of defect -
 * compile errors and compile-time deprecations - inside a single process, so
 * the whole tree is checked in roughly a fiftieth of the time. Hosts without a
 * usable OPcache fall back to `php -l` so the check never silently weakens.
 *
 * Usage: php -d opcache.enable_cli=1 tools/lint_sources.php
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Cannot resolve repository root\n");
    exit(2);
}

$excluded = ['.git', 'docs', 'migration', 'tmp', 'var', 'vendor'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $entry) use ($root, $excluded): bool {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $topLevel = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
            return !in_array($topLevel, $excluded, true);
        }
    )
);

$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $files[] = $file->getPathname();
    }
}
if ($files === []) {
    fwrite(STDERR, "No PHP sources found below {$root}\n");
    exit(2);
}
sort($files, SORT_STRING);

/** @var list<string> $failures */
$failures = [];
$relative = static fn (string $path): string =>
    str_starts_with($path, $root . DIRECTORY_SEPARATOR)
        ? substr($path, strlen($root) + 1)
        : $path;

$compiled = function_exists('opcache_compile_file')
    && filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL);

if ($compiled) {
    // Deprecations are diagnostics, not exceptions: collect them per file
    // instead of letting the first one end the run.
    set_error_handler(
        static function (
            int $number,
            string $message,
            string $file,
            int $line
        ) use (&$failures, $relative): bool {
            if ($number === E_DEPRECATED || $number === E_USER_DEPRECATED) {
                $failures[] = $relative($file) . ':' . $line
                    . ': Deprecated: ' . $message;
            }
            return true;
        },
        E_DEPRECATED | E_USER_DEPRECATED
    );
    foreach ($files as $path) {
        try {
            opcache_compile_file($path);
        } catch (ParseError | CompileError $error) {
            $failures[] = $relative($error->getFile()) . ':' . $error->getLine()
                . ': ' . $error->getMessage();
        } catch (Throwable $error) {
            $failures[] = $relative($path) . ': '
                . get_class($error) . ': ' . $error->getMessage();
        }
    }
    restore_error_handler();
} else {
    // Fallback: one interpreter per file. Slower, but identical coverage.
    $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    foreach ($files as $path) {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // The interpreter comes from PHP_BINARY and the path from the
        // repository-internal file walk above. proc_open receives an argv
        // array, so no shell parses either value and nothing needs escaping.
        // nosemgrep: semgrep.php-dangerous-dynamic-exec
        $process = @proc_open(
            [$binary, '-l', $path],
            $descriptors,
            $pipes
        );
        if (!is_resource($process)) {
            $failures[] = $relative($path) . ': cannot start ' . $binary;
            continue;
        }
        $text = (string) stream_get_contents($pipes[1])
            . (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $status = proc_close($process);
        if ($status !== 0) {
            $failures[] = $relative($path) . ': ' . trim($text);
            continue;
        }
        foreach (preg_split('~\R~', $text) ?: [] as $line) {
            if (str_starts_with($line, 'Deprecated:')) {
                $failures[] = $relative($path) . ': ' . trim($line);
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "PHP source lint failed:\n");
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf(
    "PHP source lint: OK (%d files, %s)\n",
    count($files),
    $compiled ? 'single process' : 'per-file fallback'
);
