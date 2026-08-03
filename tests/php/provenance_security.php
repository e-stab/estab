<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$evidenceDirectory = $root . '/migration/provenance';
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectedSubjects = [
    'application-trunk-r84' => [
        'kind' => 'git',
        'manifest' => 'application-trunk-r84.jsonl',
        'source_path' => '/eStab_0.9/trunk',
        'last_changed_revision' => 84,
        'file_count' => 1683,
    ],
    'legacy-branch-0.9.20_bugfix' => [
        'kind' => 'git',
        'manifest' => 'legacy-branch-0.9.20_bugfix.jsonl',
        'source_path' => '/eStab_0.9/branch/0.9.20_bugfix',
        'last_changed_revision' => 69,
        'file_count' => 645,
    ],
    'legacy-branch-0.9.20_buttons_rework' => [
        'kind' => 'git',
        'manifest' => 'legacy-branch-0.9.20_buttons_rework.jsonl',
        'source_path' => '/eStab_0.9/branch/0.9.20_buttons_rework',
        'last_changed_revision' => 21,
        'file_count' => 579,
    ],
    'legacy-branch-0.9.20_kto_usr_fkt' => [
        'kind' => 'git',
        'manifest' => 'legacy-branch-0.9.20_kto_usr_fkt.jsonl',
        'source_path' => '/eStab_0.9/branch/0.9.20_kto_usr_fkt',
        'last_changed_revision' => 50,
        'file_count' => 578,
    ],
    'legacy-branch-0.9.20_ticket20' => [
        'kind' => 'git',
        'manifest' => 'legacy-branch-0.9.20_ticket20.jsonl',
        'source_path' => '/eStab_0.9/branch/0.9.20_ticket20',
        'last_changed_revision' => 58,
        'file_count' => 577,
    ],
    'legacy-tag-ver0.9.09' => [
        'kind' => 'git',
        'manifest' => 'legacy-tag-ver0.9.09.jsonl',
        'source_path' => '/eStab_0.9/tags/ver0.9.09',
        'last_changed_revision' => 4,
        'file_count' => 289,
    ],
    'legacy-tag-ver0.9.10' => [
        'kind' => 'git',
        'manifest' => 'legacy-tag-ver0.9.10.jsonl',
        'source_path' => '/eStab_0.9/tags/ver0.9.10',
        'last_changed_revision' => 7,
        'file_count' => 292,
    ],
    'legacy-tag-ver0.9.11' => [
        'kind' => 'git',
        'manifest' => 'legacy-tag-ver0.9.11.jsonl',
        'source_path' => '/eStab_0.9/tags/ver0.9.11',
        'last_changed_revision' => 10,
        'file_count' => 324,
    ],
    'legacy-tag-ver0.9.12' => [
        'kind' => 'git',
        'manifest' => 'legacy-tag-ver0.9.12.jsonl',
        'source_path' => '/eStab_0.9/tags/ver0.9.12',
        'last_changed_revision' => 13,
        'file_count' => 585,
    ],
    'legacy-tag-ver0.9.20' => [
        'kind' => 'git',
        'manifest' => 'legacy-tag-ver0.9.20.jsonl',
        'source_path' => '/eStab_0.9/tags/ver0.9.20',
        'last_changed_revision' => 31,
        'file_count' => 575,
    ],
    'legacy-tag-ver0.9.20b' => [
        'kind' => 'git',
        'manifest' => 'legacy-tag-ver0.9.20b.jsonl',
        'source_path' => '/eStab_0.9/tags/ver0.9.20b',
        'last_changed_revision' => 72,
        'file_count' => 633,
    ],
    'legacy-documentation-r85' => [
        'kind' => 'filesystem',
        'manifest' => 'legacy-documentation-r85.jsonl',
        'source_path' => '/eStab_0.9/docu',
        'last_changed_revision' => 47,
        'archive_git_commit'
            => '9cd6fc0779ed72181d71aa9042f85c971c92f0c1',
        'archive_git_subtree' => 'docs/legacy/svn-r85',
        'file_count' => 95,
    ],
    'sourceforge-release-ver0.9.26b' => [
        'kind' => 'git',
        'manifest' => 'sourceforge-release-ver0.9.26b.jsonl',
        'source_kind' => 'sourceforge-release',
        'source_path' => 'ver0.9.26b.zip:kats/',
        'release_version' => '0.9.26b',
        'archive_sha256'
            => 'fcedda942ff783141a75c806dfc89a2045ad74929d015185959518339de5c81d',
        'file_count' => 589,
    ],
    'sourceforge-release-ver0.9.26c' => [
        'kind' => 'git',
        'manifest' => 'sourceforge-release-ver0.9.26c.jsonl',
        'source_kind' => 'sourceforge-release',
        'source_path' => 'ver0.9.26c.zip:kats/',
        'release_version' => '0.9.26c',
        'archive_sha256'
            => '8376c58cfd5e57c3a9c24f56a2148088afbb98eb425fc2eb166f815cdbf06041',
        'file_count' => 589,
    ],
];
foreach ($expectedSubjects as &$expectedSubject) {
    if (!isset($expectedSubject['source_kind'])) {
        $expectedSubject['source_kind'] = 'svn';
    }
}
unset($expectedSubject);

$defaultReader = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read provenance file: ' . $path);
    }
    return $content;
};

$verifyBundle = static function (callable $reader) use (
    $root,
    $evidenceDirectory,
    $expectedSubjects
): void {
    $lock = $reader($evidenceDirectory . '/index.sha256');
    if (
        preg_match(
            '/\\A([0-9a-f]{64})  index\\.json\\n\\z/D',
            $lock,
            $lockMatch
        ) !== 1
    ) {
        throw new RuntimeException('Provenance index lock is not canonical');
    }
    $indexJson = $reader($evidenceDirectory . '/index.json');
    if (!hash_equals($lockMatch[1], hash('sha256', $indexJson))) {
        throw new RuntimeException('Provenance index checksum mismatch');
    }
    $index = json_decode($indexJson, true, 512, JSON_THROW_ON_ERROR);
    if (
        !is_array($index)
        || ($index['format'] ?? null) !== 'estab-source-provenance-v1'
        || ($index['git_object_format'] ?? null) !== 'sha1'
        || ($index['svn_snapshot_revision'] ?? null) !== 85
        || ($index['source_repository'] ?? null)
            !== 'https://svn.code.sf.net/p/estab/svn'
        || ($index['source_uuid'] ?? null)
            !== '595569ee-1d76-4581-98bb-cbe32a4b19d9'
        || !is_array($index['source_evidence_sha256'] ?? null)
        || !is_array($index['subjects'] ?? null)
    ) {
        throw new RuntimeException('Provenance index metadata is invalid');
    }
    $sourceEvidence = [
        'migration/sourceforge-releases.tsv',
        'migration/svn-documentation-r85.sha256',
        'migration/svn-documentation-verification.txt',
        'migration/svn-ref-verification.txt',
        'migration/svn-trunk-r84.sha256',
    ];
    if (array_keys($index['source_evidence_sha256']) !== $sourceEvidence) {
        throw new RuntimeException('Source evidence set is incomplete');
    }
    foreach ($sourceEvidence as $relativePath) {
        $expectedHash = $index['source_evidence_sha256'][$relativePath] ?? null;
        $content = $reader($root . '/' . $relativePath);
        if (
            !is_string($expectedHash)
            || !hash_equals($expectedHash, hash('sha256', $content))
        ) {
            throw new RuntimeException(
                'Source evidence checksum mismatch: ' . $relativePath
            );
        }
    }

    $records = [];
    foreach ($index['subjects'] as $record) {
        if (
            !is_array($record)
            || !is_string($record['subject'] ?? null)
            || isset($records[$record['subject']])
        ) {
            throw new RuntimeException('Provenance subject set is invalid');
        }
        $records[$record['subject']] = $record;
    }
    if (array_keys($records) !== array_keys($expectedSubjects)) {
        throw new RuntimeException('Provenance subject set is incomplete or reordered');
    }

    foreach ($expectedSubjects as $identifier => $expected) {
        $record = $records[$identifier];
        foreach ($expected as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                throw new RuntimeException(
                    $identifier . ': invalid index field ' . $field
                );
            }
        }
        if (
            ($record['subject'] ?? null) !== $identifier
            || !is_string($record['manifest_sha256'] ?? null)
            || !is_string($record['entries_sha256'] ?? null)
            || !is_int($record['total_bytes'] ?? null)
        ) {
            throw new RuntimeException($identifier . ': incomplete index record');
        }
        if (
            $expected['source_kind'] === 'svn'
            && ($record['snapshot_revision'] ?? null) !== 85
        ) {
            throw new RuntimeException($identifier . ': SVN snapshot mismatch');
        }

        $manifest = $reader($evidenceDirectory . '/' . $expected['manifest']);
        if (!hash_equals($record['manifest_sha256'], hash('sha256', $manifest))) {
            throw new RuntimeException($identifier . ': manifest checksum mismatch');
        }
        if (!str_ends_with($manifest, "\n")) {
            throw new RuntimeException($identifier . ': manifest lacks final LF');
        }
        $lines = explode("\n", $manifest);
        array_pop($lines);
        if (count($lines) !== $expected['file_count'] + 1) {
            throw new RuntimeException($identifier . ': manifest line count mismatch');
        }
        $header = json_decode(array_shift($lines), true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($header)
            || ($header['format'] ?? null) !== 'estab-source-provenance-v1'
            || ($header['kind'] ?? null) !== $expected['kind']
            || ($header['source_kind'] ?? null) !== $expected['source_kind']
            || ($header['source_path'] ?? null) !== $expected['source_path']
            || ($header['subject'] ?? null) !== $identifier
        ) {
            throw new RuntimeException($identifier . ': manifest header mismatch');
        }
        if (
            $expected['source_kind'] === 'svn'
            && (
                ($header['last_changed_revision'] ?? null)
                    !== $expected['last_changed_revision']
                || ($header['snapshot_revision'] ?? null) !== 85
            )
        ) {
            throw new RuntimeException($identifier . ': SVN manifest metadata mismatch');
        }
        if (
            isset($expected['archive_git_commit'])
            && (
                ($header['archive_git_commit'] ?? null)
                    !== $expected['archive_git_commit']
                || ($header['archive_git_subtree'] ?? null)
                    !== $expected['archive_git_subtree']
            )
        ) {
            throw new RuntimeException(
                $identifier . ': archived documentation locator mismatch'
            );
        }
        if (
            $expected['source_kind'] === 'sourceforge-release'
            && (
                ($header['release_version'] ?? null)
                    !== $expected['release_version']
                || ($header['archive_sha256'] ?? null)
                    !== $expected['archive_sha256']
                || ($header['excluded_root_entries'] ?? null)
                    !== ['.gitignore', '.mailmap', 'migration']
                || !is_int($header['archive_bytes'] ?? null)
                || !is_string($header['archive_md5'] ?? null)
                || !is_string($header['archive_source_url'] ?? null)
                || !is_string($header['released_utc'] ?? null)
                || !is_string($header['snapshot_policy'] ?? null)
            )
        ) {
            throw new RuntimeException(
                $identifier . ': release archive metadata mismatch'
            );
        }

        $entryHash = hash_init('sha256');
        $previousPath = null;
        $totalBytes = 0;
        $documentationPaths = [];
        foreach ($lines as $lineNumber => $line) {
            hash_update($entryHash, $line . "\n");
            $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (
                !is_array($entry)
                || array_keys($entry) !== [
                    'mode',
                    'path',
                    'path_bytes_base64',
                    'sha256',
                    'size',
                ]
                || !is_string($entry['mode'])
                || !is_string($entry['path'])
                || !is_string($entry['path_bytes_base64'])
                || !is_string($entry['sha256'])
                || !is_int($entry['size'])
                || $entry['size'] < 0
                || preg_match('/\\A[0-9a-f]{64}\\z/D', $entry['sha256']) !== 1
            ) {
                throw new RuntimeException(
                    $identifier . ': invalid entry ' . ($lineNumber + 2)
                );
            }
            $pathBytes = base64_decode($entry['path_bytes_base64'], true);
            if (
                $pathBytes === false
                || base64_encode($pathBytes) !== $entry['path_bytes_base64']
                || preg_match('//u', $pathBytes) !== 1
                || $pathBytes !== $entry['path']
                || $pathBytes === ''
                || str_starts_with($pathBytes, '/')
                || in_array('', explode('/', $pathBytes), true)
                || in_array('.', explode('/', $pathBytes), true)
                || in_array('..', explode('/', $pathBytes), true)
                || ($previousPath !== null && strcmp($pathBytes, $previousPath) <= 0)
            ) {
                throw new RuntimeException(
                    $identifier . ': unsafe, invalid, or unsorted UTF-8 path'
                );
            }
            $previousPath = $pathBytes;
            $totalBytes += $entry['size'];

            if ($expected['kind'] === 'filesystem') {
                if ($entry['mode'] !== 'file') {
                    throw new RuntimeException(
                        $identifier . ': documentation mode mismatch'
                    );
                }
                $documentationPaths[$pathBytes] = true;
            } elseif (
                !in_array($entry['mode'], ['100644', '100755', '120000'], true)
            ) {
                throw new RuntimeException($identifier . ': invalid Git mode');
            }
        }
        if (
            !hash_equals($record['entries_sha256'], hash_final($entryHash))
            || $totalBytes !== $record['total_bytes']
        ) {
            throw new RuntimeException($identifier . ': aggregate mismatch');
        }

        if (
            $expected['kind'] === 'filesystem'
            && count($documentationPaths) !== $expected['file_count']
        ) {
            throw new RuntimeException(
                $identifier . ': archived documentation path set mismatch'
            );
        }
    }
};

$verifyBundle($defaultReader);
$assert(true, 'canonical provenance bundle was rejected');
$ciWorkflow = $defaultReader($root . '/.github/workflows/ci.yml');
$staticRunner = $defaultReader($root . '/tests/static/run.sh');
$provenanceVerifier = $defaultReader($root . '/migration/verify_provenance.py');
$assert(
    str_contains($ciWorkflow, 'fetch-depth: 0')
        && str_contains($ciWorkflow, 'fetch-tags: true')
        && str_contains(
            $ciWorkflow,
            'python3 migration/verify_provenance.py --self-test'
        ),
    'CI does not fetch and verify the complete historical ref set'
);
$assert(
    str_contains(
        $staticRunner,
        '$repo_root/tests/php/provenance_security.php'
    ),
    'minimal static suite omits provenance bundle verification'
);
$assert(
    str_contains(
        $provenanceVerifier,
        '9cd6fc0779ed72181d71aa9042f85c971c92f0c1'
    )
        && str_contains(
            $provenanceVerifier,
            'archive_git_subtree="docs/legacy/svn-r85"'
        )
        && str_contains($provenanceVerifier, 'archived_filesystem_rows('),
    'removed original documentation is not verified from its pinned Git subtree'
);

$changedManifest = $evidenceDirectory . '/application-trunk-r84.jsonl';
$tamperedManifestDetected = false;
try {
    $verifyBundle(
        static function (string $path) use (
            $defaultReader,
            $changedManifest
        ): string {
            $content = $defaultReader($path);
            if ($path === $changedManifest) {
                $content[0] = $content[0] === '{' ? '[' : '{';
            }
            return $content;
        }
    );
} catch (RuntimeException $error) {
    $tamperedManifestDetected = str_contains(
        $error->getMessage(),
        'manifest checksum mismatch'
    );
}
$assert($tamperedManifestDetected, 'manifest manipulation was not detected');

$changedReleaseTable = $root . '/migration/sourceforge-releases.tsv';
$tamperedReleaseIdentityDetected = false;
try {
    $verifyBundle(
        static function (string $path) use (
            $defaultReader,
            $changedReleaseTable
        ): string {
            $content = $defaultReader($path);
            if ($path === $changedReleaseTable) {
                $content = str_replace(
                    'fcedda942ff783141',
                    '0cedda942ff783141',
                    $content
                );
            }
            return $content;
        }
    );
} catch (RuntimeException $error) {
    $tamperedReleaseIdentityDetected = str_contains(
        $error->getMessage(),
        'Source evidence checksum mismatch'
    );
}
$assert(
    $tamperedReleaseIdentityDetected,
    'recorded SourceForge archive identity manipulation was not detected'
);

$changedDocumentation = $evidenceDirectory
    . '/legacy-documentation-r85.jsonl';
$tamperedDocumentationDetected = false;
try {
    $verifyBundle(
        static function (string $path) use (
            $defaultReader,
            $changedDocumentation
        ): string {
            $content = $defaultReader($path);
            if ($path === $changedDocumentation && $content !== '') {
                $content[0] = chr(ord($content[0]) ^ 1);
            }
            return $content;
        }
    );
} catch (RuntimeException $error) {
    $tamperedDocumentationDetected = str_contains(
        $error->getMessage(),
        'manifest checksum mismatch'
    );
}
$assert(
    $tamperedDocumentationDetected,
    'archived documentation manifest manipulation was not detected'
);

echo 'provenance security: OK ('
    . $assertions
    . ' assertions, '
    . count($expectedSubjects)
    . " subjects)\n";
