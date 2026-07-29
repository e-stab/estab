<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/generated_form.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$filename = estab_generated_form_filename('estab_test', 17, 42, 'E');
$assert(
    $filename === 'estab_test Einsatz-17 42 E.pdf',
    'generated form omits its collision-free incident identity'
);
$assert(
    estab_generated_form_parse_filename('estab_test', $filename) === [
        'incident_id' => 17,
        'number' => 42,
        'direction' => 'E',
    ],
    'canonical generated-form filename does not round-trip'
);

foreach ([
    ['estab-test', 17, 42, 'E'],
    ['estab', 0, 42, 'E'],
    ['estab', 17, 0, 'E'],
    ['estab', 17, 42, 'X'],
] as $invalid) {
    $rejected = false;
    try {
        estab_generated_form_filename(...$invalid);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert($rejected, 'invalid generated-form identity accepted');
}

foreach ([
    ['estab_test Einsatz-18 42 E.pdf', 18, 42, 'E'],
    ['estab_test Einsatz-17 42 A.pdf', 17, 42, 'A'],
] as [$candidate, $incidentId, $number, $direction]) {
    $parsed = estab_generated_form_parse_filename('estab_test', $candidate);
    $assert(
        $parsed === [
            'incident_id' => $incidentId,
            'number' => $number,
            'direction' => $direction,
        ],
        'canonical generated-form variant cannot be parsed'
    );
}
foreach ([
    'estab_test 42 E.pdf',
    '../estab_test Einsatz-17 42 E.pdf',
] as $candidate) {
    $rejected = false;
    try {
        estab_generated_form_parse_filename('estab_test', $candidate);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert($rejected, 'legacy or traversal filename accepted');
}

$temporary = sys_get_temp_dir() . '/estab-generated-form-'
    . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700)) {
    throw new RuntimeException('Could not create generated-form test directory');
}
try {
    $document = "%PDF-1.7\nincident-scoped\n%%EOF\n";
    $path = estab_generated_form_publish($temporary, $filename, $document);
    $assert(
        is_file($path) && file_get_contents($path) === $document,
        'atomic generated-form publication changed the PDF bytes'
    );
    $partials = glob($temporary . '/.estab-vordruck-*');
    $assert(
        is_array($partials) && $partials === [],
        'atomic generated-form publication left a temporary file'
    );
    $incompleteRejected = false;
    try {
        estab_generated_form_publish($temporary, $filename, '%PDF-partial');
    } catch (RuntimeException) {
        $incompleteRejected = true;
    }
    $assert(
        $incompleteRejected && file_get_contents($path) === $document,
        'incomplete PDF replaced the last complete generated form'
    );
} finally {
    if (isset($path) && is_string($path) && is_file($path)) {
        unlink($path);
    }
    rmdir($temporary);
}

$root = dirname(__DIR__, 2);
$backup = (string) file_get_contents($root . '/4fbak/backup.php');
$pdf = (string) file_get_contents($root . '/4fbak/backup_pdf.php');
$list = (string) file_get_contents($root . '/4fach/vordrucke.php');
$download = (string) file_get_contents($root . '/4fach/download.php');
$helper = (string) file_get_contents($root . '/app/generated_form.php');
$incidentExport = (string) file_get_contents(
    $root . '/app/incident_export.php'
);

$assert(
    str_contains($backup, 'estab_incident_require_active ($connection, true)')
        && str_contains($backup, 'estab_generated_form_fetch_pending')
        && str_contains($backup, 'estab_generated_form_mark_published')
        && !str_contains($backup, "where ((`x04_druck` = 'f')"),
    'legacy generator is not locked and scoped to the active incident'
);
$assert(
    str_contains($pdf, 'estab_generated_form_filename')
        && str_contains($pdf, 'estab_generated_form_publish')
        && str_contains($pdf, '$this->db_dataset ["einsatz_id"]')
        && str_contains($pdf, 'function render_message_form_document()')
        && str_contains($pdf, 'function Error ($message)')
        && str_contains(
            $pdf,
            'throw new RuntimeException ('
        )
        && str_contains(
            $pdf,
            '$document = $this->render_message_form_document ();'
        ),
    'PDF writer can still collide across incidents or publish partial bytes'
);
$assert(
    str_contains($list, 'estab_generated_form_list_active')
        && !str_contains($list, 'estab_file_list(')
        && str_contains($list, ") . '&layout=current';")
        && str_contains($list, 'PDF im aktuellen Layout öffnen')
        && str_contains($download, 'estab_generated_form_require_active')
        && str_contains($download, 'estab_generated_form_fetch_active')
        && str_contains($download, 'estab_generated_form_recipient_matrix')
        && str_contains($download, 'render_message_form_document')
        && str_contains($download, '$currentLayout')
        && str_contains($download, 'X-eStab-PDF-Layout: current')
        && str_contains($download, "            true\n        );")
        && str_contains(
            $helper,
            'estab_incident_require_active($connection, $forUpdate)'
        ),
    'generated-form list or download lacks locked active-incident authorization'
);
$assert(
    str_contains($helper, 'AND `einsatz_id` = ?')
        && str_contains($helper, "AND `x04_druck` = 't'")
        && str_contains($helper, "AND `x01_abschluss` = 't'")
        && str_contains($helper, "\$forUpdate ? ' FOR UPDATE' : ''")
        && str_contains($helper, "'message' => \$row"),
    'generated-form authorization lacks row, print, or completion scope'
);
$assert(
    str_contains($helper, 'function estab_generated_form_recipient_matrix(')
        && str_contains($helper, "array_sum(array_map('count', \$matrix)) !== 20")
        && str_contains($helper, "preg_match('/\\A[A-Za-z0-9_]{1,6}\\z/D'")
        && str_contains($download, "\$conf_4f_tbl['empfmtx']")
        && str_contains(
            $incidentExport,
            'return estab_generated_form_recipient_matrix($connection);'
        ),
    'current-layout download and dossier do not share a complete safe matrix'
);
$assert(
    str_contains($download, "\$layout !== 'current'")
        && str_contains($download, '$layoutProvided && !is_string')
        && str_contains($download, '$layoutProvided')
        && str_contains($download, '$archiveProof = estab_file_open')
        && str_contains($download, 'estab_file_open($root, $area, $filename)'),
    'layout selection is ambiguous or removed the immutable archive path'
);
$assert(
    strpos($download, '$connection->commit()') !== false
        && strpos($download, 'new vordruckaspdf(') !== false
        && strpos($download, '$connection->commit()')
            < strpos($download, 'new vordruckaspdf('),
    'current-layout PDF rendering still holds the active-incident DB locks'
);

echo "generated form security: OK ({$assertions} assertions)\n";
