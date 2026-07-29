<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/incident_export.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (
        EstabIncidentExportInputException
        | EstabIncidentExportDataException
        | EstabIncidentInputException
    ) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$sections = estab_incident_export_sections([
    'include_etb' => '1',
    'include_ttb' => '1',
    'include_messages' => '1',
    'include_attachments' => '1',
]);
$assert(
    $sections === ['etb', 'ttb', 'messages', 'attachments'],
    'Complete section selection changed'
);
$assert(
    estab_incident_export_sections(['include_etb' => '1']) === ['etb'],
    'Single section selection changed'
);
$throws(
    static fn (): array => estab_incident_export_sections([]),
    'Empty selection was accepted'
);
$throws(
    static fn (): array => estab_incident_export_sections([
        'include_attachments' => '1',
    ]),
    'Attachments without message forms were accepted'
);
$throws(
    static fn (): array => estab_incident_export_sections([
        'include_etb' => ['1'],
    ]),
    'Array-valued checkbox was accepted'
);
$throws(
    static fn (): array => estab_incident_export_sections([
        'include_etb' => 'true',
    ]),
    'Non-canonical checkbox value was accepted'
);

$assert(
    estab_incident_export_message_attachments('EL0001.pdf;EL0002.txt;')
        === ['EL0001.pdf', 'EL0002.txt'],
    'Message attachments were not parsed'
);
$assert(
    estab_incident_export_message_attachments(
        'EL0001.pdf; EL0001.pdf ;'
    ) === ['EL0001.pdf'],
    'Message attachment duplicates were not removed'
);
$assert(
    estab_incident_export_message_attachments(null) === [],
    'Null attachment field was not treated as empty'
);
$throws(
    static fn (): array => estab_incident_export_message_attachments(
        '../secret.pdf;'
    ),
    'Traversal attachment was accepted'
);
$throws(
    static fn (): array => estab_incident_export_message_attachments(
        'EL0001.exe;'
    ),
    'Forbidden attachment extension was accepted'
);

$filename = estab_incident_export_filename(
    ['einsatz_id' => 12, 'kennung' => 'EL/2026_001'],
    new DateTimeImmutable('2026-07-29 14:15:16')
);
$assert(
    $filename === 'estab-einsatz-12-el-2026-001-20260729-141516.pdf',
    'Portable PDF filename changed'
);
$throws(
    static fn (): string => estab_incident_export_filename([
        'einsatz_id' => 1,
        'kennung' => '../secret',
    ]),
    'Unsafe incident filename was accepted'
);
$embeddedName = estab_incident_export_embedded_name(
    1,
    str_repeat('A', 220) . '.pdf'
);
$assert(
    strlen($embeddedName) <= 181
        && str_starts_with($embeddedName, 'Anlage-0001-')
        && str_ends_with($embeddedName, '.pdf'),
    'Long embedded attachment name was not shortened safely'
);
$assert(
    estab_incident_export_embedded_name(2, 'EL0001.txt')
        !== estab_incident_export_embedded_name(3, 'EL0001.txt'),
    'Embedded attachment names are not position-unique'
);
$throws(
    static fn (): string => estab_incident_export_embedded_name(
        0,
        'EL0001.txt'
    ),
    'Invalid embedded attachment position was accepted'
);

$source = file_get_contents(__DIR__ . '/../../app/incident_export.php');
if (!is_string($source)) {
    throw new RuntimeException('Could not read incident export source');
}
foreach ([
    'FROM `nv_etb` WHERE `einsatz_id` = ?',
    'FROM `nv_tbb` WHERE `einsatz_id` = ?',
    'FROM `nv_nachrichten` WHERE `einsatz_id` = ?',
    'WHERE `einsatz_id` = ? AND `status` = 1',
    'estab_incident_export_recipient_matrix($connection)',
    '`06_befwegausw`, `07_durchspruch`',
    '`08_befhinwausw`, `09_vorrangstufe`',
    '`10_anschrift`, `11_gesprnotiz`',
    '`x04_druck`, `x05_druck_d`, `99_lstacc`',
    'estab_file_resolve(',
    'Ein Nachrichtenvordruck verweist auf einen nicht ',
    'hash(\'sha256\', $bytes)',
] as $requiredBoundary) {
    $assert(
        str_contains($source, $requiredBoundary),
        'Incident export boundary is missing: ' . $requiredBoundary
    );
}
$assert(
    !str_contains($source, 'SELECT *'),
    'Incident export uses an unbounded SELECT *'
);

$controller = file_get_contents(
    __DIR__ . '/../../4fadm/incident_export.php'
);
$dashboard = file_get_contents(__DIR__ . '/../../4fadm/admin.php');
$dockerfile = file_get_contents(__DIR__ . '/../../Dockerfile');
$runtimeVerifier = file_get_contents(
    __DIR__ . '/../../docker/app/verify-runtime-surface.sh'
);
$pdfRenderer = file_get_contents(__DIR__ . '/../../app/incident_pdf.php');
$messageTemplate = file_get_contents(
    __DIR__ . '/../../4fbak/backup_pdf.php'
);
foreach (
    [
        $controller,
        $dashboard,
        $dockerfile,
        $runtimeVerifier,
        $pdfRenderer,
        $messageTemplate,
    ] as $surface
) {
    $assert(is_string($surface), 'Incident PDF integration source is unreadable');
}
$assert(
    str_contains($pdfRenderer, 'extends vordruckaspdf')
        && str_contains($pdfRenderer, '$this->set_message_form_data($message)')
        && str_contains($pdfRenderer, 'parent::Header()')
        && str_contains($pdfRenderer, 'parent::Footer()')
        && !str_contains($pdfRenderer, "'Datensatz' => \$recordId"),
    'Incident dossier does not reuse the generated message-form renderer'
);
$assert(
    !str_contains($messageTemplate, 'Nur für den Dienstgebrauch')
        && !str_contains($messageTemplate, '4fbak/logo.png')
        && !str_contains($messageTemplate, '/logo.png')
        && !str_contains($messageTemplate, "ini_set('memory_limit'"),
    'Message-form template still prints removed assets or lowers dossier memory'
);
$assert(
    str_contains($controller, "empty(\$_SERVER['REMOTE_USER'])")
        && str_contains(
            $controller,
            'estab_csrf_require_post($_SERVER, $_POST)'
        )
        && str_contains($controller, 'estab_incident_positive_id(')
        && str_contains($controller, 'MYSQLI_TRANS_START_READ_ONLY')
        && str_contains(
            $controller,
            'MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT'
        ),
    'Incident PDF controller lacks admin, CSRF, strict-ID, or snapshot boundary'
);
$assert(
    str_contains($controller, "'pdf_export'")
        && str_contains($controller, "'sha256' => \$rendered['sha256']")
        && str_contains($controller, "header('Content-Type: application/pdf')")
        && str_contains($controller, "Content-Security-Policy: sandbox"),
    'Incident PDF response or audit boundary is incomplete'
);
$assert(
    str_contains($dashboard, "'key' => 'incident-pdf'")
        && str_contains($dashboard, "'href' => 'incident_export.php'")
        && str_contains($dashboard, "'key' => 'incidents'"),
    'Administration dashboard omits incident PDF or incident management'
);
$assert(
    str_contains($dockerfile, '4fadm/incident_export.php')
        && str_contains($dockerfile, '4fadm/incidents.php')
        && !str_contains($dockerfile, '4fbak/logo.png')
        && str_contains($runtimeVerifier, '4fadm/incident_export.php')
        && str_contains($runtimeVerifier, '4fadm/incidents.php')
        && !str_contains($runtimeVerifier, '4fbak/logo.png')
        && str_contains($runtimeVerifier, 'app/incident_export.php')
        && str_contains($runtimeVerifier, 'app/incident_pdf.php'),
    'Container runtime omits incident PDF or incident management files'
);

echo 'incident export security: OK (' . $assertions . " assertions)\n";
