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
    // Rueckfall ohne opcache: die Syntax laesst sich auch im eigenen Prozess
    // pruefen. token_get_all wirft mit TOKEN_PARSE denselben ParseError, den
    // `php -l` meldet -- ohne einen einzigen Unterprozess, ohne Shell und
    // ohne einen dynamischen Wert an eine Ausfuehrungsfunktion zu reichen.
    //
    // Was dieser Weg NICHT sieht, sind Verwerfungen zur Uebersetzungszeit:
    // die entstehen erst beim Uebersetzen, und uebersetzt wird hier nichts.
    // Die Abschlussmeldung sagt das offen, damit ein Lauf ohne opcache nicht
    // als vollwertige Pruefung durchgeht.
    foreach ($files as $path) {
        $source = file_get_contents($path);
        if ($source === false) {
            $failures[] = $relative($path) . ': cannot read';
            continue;
        }
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (ParseError | CompileError $error) {
            $failures[] = $relative($path) . ':' . $error->getLine()
                . ': ' . $error->getMessage();
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
    $compiled
        ? 'single process'
        : 'syntax only, no opcache: deprecations not checked'
);
