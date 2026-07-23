<?php

/**
 * Fail when a PHP-4-style constructor has no PHP 8 __construct wrapper.
 *
 * Usage: php tools/check_legacy_constructors.php
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Cannot resolve repository root\n");
    exit(2);
}

$excluded = ['.git', 'docs', 'migration', 'tmp', 'var', 'vendor'];
$failures = [];
$classesChecked = 0;

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

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        fwrite(STDERR, "Cannot read {$file->getPathname()}\n");
        exit(2);
    }
    $tokens = token_get_all($source);
    $class = null;
    $classDepth = null;
    $braceDepth = 0;
    $expectClassName = false;
    $expectMethodName = false;
    $methods = [];

    $finishClass = static function () use (&$class, &$methods, &$failures, &$classesChecked, $file, $root): void {
        if ($class === null) {
            return;
        }
        $classesChecked++;
        $legacyName = strtolower($class);
        if (isset($methods[$legacyName]) && !isset($methods['__construct'])) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $failures[] = "{$relative}:{$methods[$legacyName]} {$class}::{$class}()";
        }
        $class = null;
        $methods = [];
    };

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            if ($token === '{') {
                $braceDepth++;
                if ($class !== null && $classDepth === null) {
                    $classDepth = $braceDepth;
                }
            } elseif ($token === '}') {
                if ($class !== null && $classDepth === $braceDepth) {
                    $finishClass();
                    $classDepth = null;
                }
                $braceDepth--;
            }
            continue;
        }

        [$id, $text, $line] = $token;
        if ($id === T_CLASS) {
            $expectClassName = true;
            continue;
        }
        if ($expectClassName && $id === T_STRING) {
            $class = $text;
            $methods = [];
            $expectClassName = false;
            $classDepth = null;
            continue;
        }
        if ($class !== null && $classDepth !== null && $braceDepth === $classDepth && $id === T_FUNCTION) {
            $expectMethodName = true;
            continue;
        }
        if ($expectMethodName && $id === T_STRING) {
            $methods[strtolower($text)] = $line;
            $expectMethodName = false;
        }
    }
    $finishClass();
}

if ($failures !== []) {
    fwrite(STDERR, "PHP-4 constructors without __construct wrapper:\n");
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "legacy constructors: OK ({$classesChecked} classes checked)\n";
