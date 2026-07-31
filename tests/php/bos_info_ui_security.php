<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

/*
 * These hashes protect the complete historical BOS source material. The
 * presentation shell may evolve, but reference texts, tables, anchors, images
 * and even historical spelling must remain byte-for-byte unchanged.
 */
$protectedContent = [
    'stabinfo/Buchstabier.html'
        => '03303652dfa9ae3ca291a416eabd1511ad88048bcb55053d3b0bc134b906bae9',
    'stabinfo/DRK Rufnamenschema.html'
        => 'e7282f599ec0c3d5d3f6e67927ce06afebb119b6b9db159aa092e8e5bc8b4c8b',
    'stabinfo/FF-Rufnamenschema.html'
        => '3a14a4c4e6e36d9fce1c58503378565cc4be2818525b6b8db7a5d8c20b693123',
    'stabinfo/FF Rufrnamenschema.html'
        => '2b3ca6152963adfff8f52c2227df5f6e349f721c9a6f0d4407c91ee5de294027',
    'stabinfo/IuK-InfoPack.html'
        => '4fb1d98a0179b438a842cda72d0e103a8abe0abcbdddc500886d1423aaa3e6cb',
    'stabinfo/Kartendatum.html'
        => '4c58f6de6ca85173e462cd1e6bedc9ae2ad777cf52ef1a3c42ce836737682af6',
    'stabinfo/Orgas.html'
        => '4fd86f56618ecf3b88f576654c2b1d31728b6fe372eda360af4887339bff6d10',
    'stabinfo/THWFuRNR.html'
        => '8a6dae2c8addbb8fddef403a9f1135b72245e253c6549b62fa569549bf24a42a',
    'stabinfo/ELStab.jpg'
        => 'a7e3d6bdeabcea050e62c3c8abd44433f416a2107441f766571dd0dad83e7607',
    'stabinfo/Warendorf L4112 Kartendatum ED50.png'
        => '9aac66d36721fa6d2eb460357eaa30c2d2ea82e1c2f9784bca5c8e0d8d05c76b',
    'stabinfo/Warendorf L4112 Kartendatum WGS84.png'
        => 'fccec3dc23dadd28cca20f1ae268bb609ebf79854607739d7ffae2b602c54041',
];

foreach ($protectedContent as $relativePath => $expectedHash) {
    $absolutePath = $root . '/' . $relativePath;
    $assert(
        is_file($absolutePath)
            && hash_file('sha256', $absolutePath) === $expectedHash,
        'historical BOS content changed: ' . $relativePath
    );
}

$expectedDocuments = [
    [
        'href' => 'Buchstabier.html',
        'title' => 'Buchstabieralphabet',
        'description' => 'Deutsches und internationales Alphabet',
    ],
    [
        'href' => 'Kartendatum.html',
        'title' => 'Neues Kartendatum',
        'description' => 'Hinweise zu ED50, WGS84 und UTMREF',
    ],
    [
        'href' => 'IuK-InfoPack.html',
        'title' => 'Stabzusammensetzung',
        'description' => 'Aufbau und Aufgaben des Einsatzleitstabs',
    ],
    [
        'href' => 'Orgas.html',
        'title' => 'Behörden und Organisationen',
        'description' => 'Abkürzungen und Sprechfunk-Rufnamen',
    ],
    [
        'href' => 'FF-Rufnamenschema.html',
        'title' => 'F-Rufnamenregel',
        'description' => 'Rufnamenschema der Feuerwehr',
    ],
    [
        'href' => 'DRK%20Rufnamenschema.html',
        'title' => 'DRK-Rufnamenregel',
        'description' => 'Rufnamenschema des Deutschen Roten Kreuzes',
    ],
    [
        'href' => 'THWFuRNR.html',
        'title' => 'THW-Rufnamenregel',
        'description' => 'Rufnamenschema des Technischen Hilfswerks',
    ],
];

$documents = require $root . '/stabinfo/documents.php';
$assert(
    $documents === $expectedDocuments,
    'BOS navigation metadata or document order changed unexpectedly'
);

$workspaceSource = file_get_contents($root . '/stabinfo/index.php');
$navigationSource = file_get_contents($root . '/stabinfo/l_index.php');
$welcomeSource = file_get_contents($root . '/stabinfo/f_info.php');
$stylesheetSource = file_get_contents($root . '/estab-ui.css');

$assert(
    is_string($workspaceSource)
        && str_contains($workspaceSource, "require __DIR__ . '/documents.php'")
        && str_contains($workspaceSource, 'JSON_HEX_TAG')
        && str_contains($workspaceSource, 'wrapLegacyDocument(')
        && str_contains($workspaceSource, 'while (body.firstChild)')
        && str_contains(
            $workspaceSource,
            "originalContent.appendChild(body.firstChild)"
        )
        && str_contains(
            $workspaceSource,
            "shell.setAttribute('data-estab-bos-document-shell'"
        )
        && str_contains(
            $workspaceSource,
            "'data-estab-bos-original-content'"
        )
        && str_contains(
            $workspaceSource,
            "'data-estab-bos-layout-ready'"
        )
        && str_contains($workspaceSource, 'wrapLegacyTables(')
        && str_contains($workspaceSource, 'textContent = metadata.title')
        && str_contains(
            $workspaceSource,
            'textContent = metadata.description'
        )
        && !str_contains($workspaceSource, 'innerHTML')
        && !str_contains($workspaceSource, 'document.write'),
    'BOS workspace does not enhance legacy documents safely and idempotently'
);
$assert(
    is_string($navigationSource)
        && str_contains(
            $navigationSource,
            "require __DIR__ . '/documents.php'"
        ),
    'BOS sidebar does not use the shared document metadata'
);
$assert(
    is_string($welcomeSource)
        && str_contains($welcomeSource, 'estab-bos-document-header')
        && str_contains($welcomeSource, 'estab-bos-document-content'),
    'BOS welcome page does not use the shared visual hierarchy'
);
$assert(
    is_string($stylesheetSource)
        && str_contains($stylesheetSource, '.estab-bos-document-shell')
        && str_contains($stylesheetSource, '.estab-bos-document-header')
        && str_contains($stylesheetSource, '.estab-bos-document-content')
        && str_contains($stylesheetSource, '.estab-bos-table-scroll')
        && str_contains(
            $stylesheetSource,
            '.estab-bos-document-content font[face]'
        )
        && str_contains(
            $stylesheetSource,
            '.estab-bos-document-content font[size]'
        ),
    'shared BOS document presentation is incomplete'
);

if ($failures !== []) {
    fwrite(
        STDERR,
        "BOS info UI security test failed:\n- "
            . implode("\n- ", $failures)
            . "\n"
    );
    exit(1);
}

echo "BOS info UI security test: OK\n";
