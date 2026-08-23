<?php

/**
 * Conservative token-level rewrites needed for PHP 8.
 *
 * At present this quotes legacy bareword array keys (`$row[key]`). It ignores
 * strings, comments and real constants, and preserves every other source byte.
 * Run without --write in CI; use --write only for the mechanical migration.
 */

$write = in_array('--write', $argv, true);
$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Cannot resolve repository root\n");
    exit(2);
}

$excludedTopLevels = [
    '.git', 'app', 'docs', 'migration', 'tmp', 'tools', 'var', 'vendor',
];
$constantKeys = ['MYSQL_ASSOC', 'MYSQL_NUM', 'MYSQL_BOTH'];
// Eigene Konstanten dieser Anwendung tragen alle das Praefix ESTAB_.
// Als Index sind sie gemeint, nicht vergessen zu setzen: sie zu
// quotieren machte aus dem Wert stillschweigend seinen Namen.
$constantKeyPattern = '~\\AESTAB_[A-Z0-9_]+\\z~D';
$changedFiles = [];
$changedKeys = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($root, $excludedTopLevels): bool {
            $relative = substr($current->getPathname(), strlen($root) + 1);
            $topLevel = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
            return !in_array($topLevel, $excludedTopLevels, true);
        }
    )
);

/** @return int|string|null */
function significantToken(array $tokens, int $start, int $direction): int|string|null
{
    for ($index = $start; isset($tokens[$index]); $index += $direction) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return is_array($token) ? $token[0] : $token;
    }
    return null;
}

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(2);
    }
    $tokens = token_get_all($source);
    $rewritten = '';
    $fileChanges = 0;
    $insideInterpolatedString = false;
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            $rewritten .= $token;
            if ($token === '"') {
                $insideInterpolatedString = !$insideInterpolatedString;
            }
            continue;
        }
        [$id, $text] = $token;
        if ($id === T_START_HEREDOC) {
            $insideInterpolatedString = true;
        } elseif ($id === T_END_HEREDOC) {
            $insideInterpolatedString = false;
        }
        // `[` opens a subscript only behind something subscriptable. In every
        // other position it opens an array literal, and quoting a constant
        // there would silently turn ESTAB_X into the string 'ESTAB_X'.
        $bracketOwner = significantToken($tokens, $index - 1, -1) === '['
            ? significantToken($tokens, $index - 2, -1)
            : null;
        $isSubscript = in_array(
            $bracketOwner,
            [T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, ']', ')', '}'],
            true
        );
        $isBareArrayKey = $id === T_STRING
            && !$insideInterpolatedString
            && $isSubscript
            && significantToken($tokens, $index - 1, -1) === '['
            && significantToken($tokens, $index + 1, 1) === ']'
            && !in_array($text, $constantKeys, true)
            && preg_match($constantKeyPattern, $text) !== 1;
        if ($isBareArrayKey) {
            $rewritten .= "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $text) . "'";
            $fileChanges++;
            $changedKeys[$text] = ($changedKeys[$text] ?? 0) + 1;
        } else {
            $rewritten .= $text;
        }
    }
    if ($rewritten !== $source) {
        $relative = substr($path, strlen($root) + 1);
        $changedFiles[$relative] = $fileChanges;
        if ($write && file_put_contents($path, $rewritten) === false) {
            fwrite(STDERR, "Cannot write {$path}\n");
            exit(2);
        }
    }
}

ksort($changedFiles);
ksort($changedKeys);
foreach ($changedFiles as $path => $count) {
    echo "{$path}\t{$count}\n";
}
echo sprintf(
    "%s: %d keys in %d files\n",
    $write ? 'rewritten' : 'would rewrite',
    array_sum($changedKeys),
    count($changedFiles)
);

exit($write || count($changedFiles) === 0 ? 0 : 1);
