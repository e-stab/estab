<?php

declare(strict_types=1);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__, 2);
$stylesheet = file_get_contents($root . '/estab-ui.css');
$assert(is_string($stylesheet), 'shared stylesheet is unreadable');

$surfaces = [
    '4fadm/incidents.php' => 'data-estab-incident-admin',
    '4fadm/users.php' => 'data-estab-user-admin',
    '4fadm/set_number_after_crash.php' => 'data-estab-counter-tool',
    '4fach/resetpic.php' => 'data-estab-print-reset-tool',
    '4fach/vordrucke.php' => 'data-estab-generated-forms',
];
$sources = [];

foreach ($surfaces as $relativePath => $surfaceMarker) {
    $source = file_get_contents($root . '/' . $relativePath);
    $assert(is_string($source), $relativePath . ' is unreadable');
    $sources[$relativePath] = $source;

    $assert(
        substr_count($source, 'estab_session_ui_start(') === 1
            && substr_count($source, 'estab_session_ui_stylesheet()') === 1
            && !str_contains($source, 'data-estab-session-bar')
            && !str_contains($source, 'data-estab-public-bar'),
        $relativePath . ' can render a missing or duplicate shared session bar'
    );
    $assert(
        str_contains($source, '<body class="estab-tool-page">')
            && str_contains($source, 'class="estab-tool-main')
            && str_contains($source, $surfaceMarker)
            && str_contains($source, 'class="estab-tool-hero"')
            && str_contains($source, 'class="estab-tool-footer"'),
        $relativePath . ' does not use the common tool-page hierarchy'
    );
    $assert(
        !preg_match('/<style\b/i', $source),
        $relativePath . ' reintroduces page-local visual rules'
    );
}

$additionalSurfaces = [
    '4fadm/make_fkt.php' => 'data-estab-matrix-tool',
    '4fadm/system_status.php' => 'data-estab-system-status',
    '4fach/katgoedt.php' => 'data-estab-category-manager',
    '4fach/info.php' => 'data-estab-problem-report',
    'stabetb/etb.php' => 'data-estab-logbook="etb"',
    'fmtbb/tbb.php' => 'data-estab-logbook="ttb"',
    '4fach/nachwea.php' => 'data-estab-tracking-overview',
    '4fueltg/ue_ltg.php' => 'data-estab-message-overview',
    '4fadm/export.php' => 'data-estab-export-tool',
    '4fadm/incident_export.php' => 'data-estab-incident-export',
];
$additionalSources = [];

foreach ($additionalSurfaces as $relativePath => $surfaceMarker) {
    $source = file_get_contents($root . '/' . $relativePath);
    $assert(is_string($source), $relativePath . ' is unreadable');
    $additionalSources[$relativePath] = $source;
    $normalizedMarkup = str_replace('\\"', '"', $source);

    $assert(
        substr_count($source, 'estab_session_ui_start(')
                + substr_count($source, 'estab_session_ui_start (') === 1
            && substr_count($source, 'estab_session_ui_stylesheet()')
                + substr_count($source, 'estab_session_ui_stylesheet ()') === 1
            && !str_contains($source, 'data-estab-session-bar')
            && !str_contains($source, 'data-estab-public-bar'),
        $relativePath . ' can render a missing or duplicate shared session bar'
    );
    $assert(
        preg_match(
            '/<body\b[^>]*class="[^"]*\bestab-tool-page\b[^"]*"/i',
            $normalizedMarkup
        ) === 1
            && str_contains($normalizedMarkup, 'class="estab-tool-main')
            && str_contains($normalizedMarkup, $surfaceMarker)
            && str_contains($normalizedMarkup, 'estab-tool-hero')
            && str_contains($normalizedMarkup, 'estab-tool-footer'),
        $relativePath . ' does not use the common tool-page hierarchy'
    );
    $assert(
        !preg_match('/<style\b/i', $source),
        $relativePath . ' reintroduces page-local visual rules'
    );
}

foreach ([
    '.estab-tool-page',
    '.estab-tool-main',
    '.estab-tool-hero',
    '.estab-tool-panel',
    '.estab-tool-feedback-error',
    '.estab-tool-feedback-success',
    '.estab-error-page',
    '.estab-error-message',
    '.estab-error-actions',
    '.estab-tool-form-grid',
    '.estab-tool-field',
    '.estab-tool-actions',
    '.estab-tool-table-responsive',
    '.estab-tool-badge-success',
    '.estab-tool-badge-danger',
    '.estab-tool-footer',
    '.estab-tool-matrix-options',
    '.estab-tool-logbook-table',
    '.estab-tool-legacy-content',
] as $selector) {
    $assert(
        str_contains($stylesheet, $selector),
        'shared stylesheet is missing ' . $selector
    );
}
$assert(
    str_contains($stylesheet, '@media (max-width: 42rem)')
        && str_contains(
            $stylesheet,
            '.estab-tool-table-responsive .estab-tool-table td::before'
        )
        && str_contains($stylesheet, 'content: attr(data-label)')
        && str_contains(
            $stylesheet,
            '.estab-tool-action-stack input:focus-visible'
        ),
    'tool pages lack responsive table cards or visible keyboard focus'
);

$incident = $sources['4fadm/incidents.php'];
$assert(
    str_contains($incident, 'aria-labelledby="incident-create-title"')
        && str_contains($incident, 'for="incident-code"')
        && str_contains($incident, 'for="incident-start"')
        && str_contains($incident, 'data-estab-no-active-incident')
        && str_contains($incident, 'estab-tool-status-danger')
        && str_contains($incident, 'estab-tool-card-active')
        && str_contains($incident, 'data-estab-incident-card')
        && str_contains($incident, 'data-estab-incident-summary')
        && str_contains($incident, 'data-estab-incident-actions')
        && str_contains($incident, 'estab-incident-card-overview')
        && str_contains($incident, 'estab-incident-card-heading')
        && str_contains($incident, 'data-estab-incident-card-status')
        && str_contains($incident, 'estab-incident-card-actions')
        && str_contains($incident, 'estab-incident-action')
        && str_contains($incident, 'Einsatz verwalten'),
    'incident administration lost labelled controls or clear active-state UI'
);
$assert(
    preg_match(
        '~\.estab-incident-card\s*\{[^}]*grid-template-columns\s*:\s*'
            . 'minmax\(0\s*,\s*1fr\)[^}]*\}~s',
        $stylesheet
    ) === 1
        && preg_match(
            '~\.estab-incident-card-actions\s*\{[^}]*display\s*:\s*grid\s*;'
                . '[^}]*grid-template-columns\s*:\s*repeat\(auto-fit\s*,'
                . '\s*minmax\(18rem\s*,\s*1fr\)\)[^}]*\}~s',
            $stylesheet
        ) === 1,
    'incident cards can collapse their summary or crowd actions on desktop'
);
$assert(
    substr_count($incident, "incident_admin_html(\$status['kennung'])") === 1
        && substr_count($incident, '<strong>') === substr_count(
            $incident,
            '</strong>'
        )
        && substr_count($incident, '<section') === substr_count(
            $incident,
            '</section>'
        ),
    'incident administration contains duplicated or unbalanced status markup'
);

$users = $sources['4fadm/users.php'];
$assert(
    substr_count($users, '<table') === 1
        && substr_count($users, '</table>') === 1
        && str_contains($users, '<caption class="estab-visually-hidden">')
        && substr_count($users, 'data-label=') >= 5
        && substr_count($users, 'autocomplete="new-password"') === 4
        && str_contains($users, 'aria-labelledby="estab-create-user-title"')
        && str_contains($users, 'name="admin_action" value="create"')
        && str_contains($users, 'name="admin_action"')
        && str_contains($users, 'value="reassign"'),
    'user administration table or password controls are not accessible'
);

$counter = $sources['4fadm/set_number_after_crash.php'];
$assert(
    str_contains($counter, '<fieldset class="estab-tool-counter-grid">')
        && str_contains($counter, '<legend class="estab-visually-hidden">')
        && str_contains($counter, 'for="ea_nummer"')
        && str_contains($counter, 'for="e_nummer"')
        && str_contains($counter, 'for="a_nummer"')
        && str_contains($counter, 'data-estab-requires-incident'),
    'counter tool lacks labelled inputs or the active-incident gate'
);

$reset = $sources['4fach/resetpic.php'];
$assert(
    str_contains($reset, 'id="print-reset-impact"')
        && str_contains($reset, 'aria-describedby="print-reset-impact"')
        && str_contains($reset, 'estab-tool-notice-warning')
        && str_contains($reset, 'data-estab-requires-incident'),
    'print-reset impact is not described or incident-gated'
);

$forms = $sources['4fach/vordrucke.php'];
$assert(
    substr_count($forms, '<table') === 1
        && substr_count($forms, '</table>') === 1
        && str_contains($forms, '<caption class="estab-visually-hidden">')
        && substr_count($forms, 'data-label=') >= 4
        && str_contains($forms, 'estab-tool-table-number')
        && str_contains($forms, 'rel="noopener"')
        && str_contains($forms, 'target="_blank"')
        && str_contains($forms, '(öffnet in neuem Tab)')
        && str_contains($forms, '<code><?= estab_auth_html($file[\'name\']) ?>')
        && str_contains($forms, '<th scope="col">Aktuelles PDF</th>')
        && str_contains($forms, '<th scope="col">Archivdatei geändert</th>')
        && str_contains(
            $stylesheet,
            ".estab-tool-main code {\n    overflow-wrap: anywhere;"
        ),
    'generated-form listing is not responsive, labelled and download-safe'
);

$matrix = $additionalSources['4fadm/make_fkt.php'];
$assert(
    str_contains($matrix, 'data-estab-matrix-table')
        && str_contains($matrix, 'class="estab-tool-table')
        && str_contains($matrix, '<caption class="estab-visually-hidden">')
        && str_contains($matrix, 'for="matrix-function-')
        && str_contains($matrix, 'class="estab-tool-matrix-options"')
        && str_contains($matrix, 'aria-describedby="matrix-impact"')
        && str_contains($matrix, 'data-estab-confirm="replace-standard"'),
    'recipient matrix is not responsive, labelled or impact-aware'
);

$systemStatus = $additionalSources['4fadm/system_status.php'];
$assert(
    str_contains($systemStatus, 'data-estab-readiness=')
        && substr_count($systemStatus, 'class="estab-tool-table-wrap') === 4
        && substr_count($systemStatus, '<caption class="estab-visually-hidden">') === 4
        && str_contains($systemStatus, 'estab-tool-badge-success')
        && str_contains($systemStatus, 'estab-tool-badge-danger'),
    'system status does not expose responsive, accessible readiness groups'
);

$categories = $additionalSources['4fach/katgoedt.php'];
$assert(
    str_contains($categories, 'aria-labelledby="category-list-title"')
        && str_contains($categories, 'for="category-name"')
        && str_contains($categories, 'for="category-description"')
        && str_contains($categories, 'data-label="Aktionen"')
        && str_contains($categories, 'estab-tool-feedback-success'),
    'category manager lacks labelled controls or responsive status feedback'
);

$problemReport = $additionalSources['4fach/info.php'];
$assert(
    str_contains($problemReport, 'aria-labelledby="problem-report-title"')
        && str_contains($problemReport, 'estab-tool-feedback-error')
        && str_contains($problemReport, 'type="button"')
        && str_contains($problemReport, 'window.close()'),
    'problem report popup lacks an accessible, non-mutating close action'
);

foreach ([
    'stabetb/etb.php' => ['etb', 'ETB-Eintrag speichern', 'etb-event'],
    'fmtbb/tbb.php' => ['ttb', 'TBB-Eintrag speichern', 'ttb-entry-type'],
] as $relativePath => [$kind, $saveLabel, $eventId]) {
    $logbook = str_replace('\\"', '"', $additionalSources[$relativePath]);
    $assert(
        str_contains($logbook, 'data-estab-logbook="' . $kind . '"')
            && str_contains($logbook, 'class="estab-tool-table')
            && str_contains($logbook, 'estab-tool-table-responsive')
            && str_contains($logbook, 'for="' . $eventId . '"')
            && str_contains($logbook, $saveLabel)
            && str_contains($logbook, 'data-estab-no-active-incident')
            && !str_contains($logbook, 'type="image"'),
        strtoupper($kind) . ' retains legacy controls or lacks responsive UI'
    );
    if ($kind === 'ttb') {
        foreach ([
            'personnel_duty',
            'channel',
            'message_route',
            'operations',
            'receipt',
        ] as $officialField) {
            $assert(
                str_contains($logbook, '"' . $officialField . '" => array'),
                'TTB omits official structured field ' . $officialField
            );
        }
    }
}

$tracking = str_replace(
    '\\"',
    '"',
    $additionalSources['4fach/nachwea.php']
);
$overview = str_replace(
    '\\"',
    '"',
    $additionalSources['4fueltg/ue_ltg.php']
);
$assert(
    str_contains($tracking, 'data-estab-tracking-overview')
        && str_contains($tracking, 'estab-tool-legacy-content')
        && str_contains($tracking, 'estab_session_ui_abort (')
        && !str_contains($tracking, 'Content-Type: text/plain')
        && str_contains($overview, 'data-estab-message-overview')
        && str_contains($overview, 'data-estab-message-detail')
        && str_contains($overview, 'data-estab-message-list')
        && str_contains($overview, 'estab_message_list_render_controls')
        && str_contains($overview, 'estab_session_ui_abort (')
        && !str_contains($overview, 'Content-Type: text/plain')
        && substr_count($overview, 'estab-tool-legacy-content') >= 1,
    'reporting surfaces or their access errors escape the shared page shell'
);

foreach ([
    '4fadm/export.php' => 'data-estab-export-tool',
    '4fadm/incident_export.php' => 'data-estab-incident-export',
] as $relativePath => $marker) {
    $exportSource = $additionalSources[$relativePath];
    $assert(
        str_contains($exportSource, $marker)
            && str_contains($exportSource, 'estab-tool-page estab-export-page')
            && str_contains($exportSource, 'estab-tool-main')
            && str_contains($exportSource, 'estab-tool-hero')
            && str_contains($exportSource, 'estab-tool-footer'),
        $relativePath . ' is not aligned with the shared administration shell'
    );
}

echo "tool UI security: OK ({$assertions} assertions)\n";
