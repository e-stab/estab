<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/read_authorization.php';

$root = dirname(__DIR__, 2);
$assertions = 0;
$failures = [];
$assert = static function (
    bool $condition,
    string $message
) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $relativePath) use ($root): string {
    $contents = file_get_contents($root . '/' . $relativePath);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $relativePath);
    }
    return $contents;
};
$slice = static function (
    string $source,
    string $start,
    string $end
): string {
    $startOffset = strpos($source, $start);
    if (!is_int($startOffset)) {
        return '';
    }
    $endOffset = strpos($source, $end, $startOffset + strlen($start));
    if (!is_int($endOffset)) {
        return '';
    }
    return substr($source, $startOffset, $endOffset - $startOffset);
};

$readSource = $read('app/read_authorization.php');
$messageSource = $read('app/message_repository.php');
$controllerSource = $read('4fach/mainindex.php');
$handlerSource = $read('4fach/data_hndl.php');
$formSource = $read('4fach/4fachform.php');
$officialFormSource = $read('4fach/official_message_form.php');
$downloadSource = $read('4fach/download.php');
$previewSource = $read('4fach/showpic.php');
$emailSource = $read('4fach/email.php');

$expectedScopeApi = [
    'estab_read_attachment_write_scope',
    'estab_read_attachment_write_scope_allows',
];
foreach ($expectedScopeApi as $function) {
    $assert(
        function_exists($function),
        'missing action-bound attachment API: ' . $function
    );
}

$authorizationColumns = $slice(
    $readSource,
    'function estab_read_attachment_authorization_columns(): string',
    '/** Build an exact filename-to-message map'
);
$readSeveral = $slice(
    $readSource,
    'function estab_read_attachments(',
    'function estab_read_require_attachment_use_scope('
);
$requireUseScope = $slice(
    $readSource,
    'function estab_read_require_attachment_use_scope(',
    "\n}"
);
$pureReadDecision = $slice(
    $readSource,
    'function estab_read_attachment_allowed(',
    '/** Return the canonical stored filename'
);
$assert(
    str_contains($authorizationColumns, '`00_lfd`'),
    'attachment authorization rows cannot identify the exact scoped message'
);
$assert(
    str_contains($readSeveral, '?array $writeScope = null')
        && str_contains(
            $readSeveral,
            'estab_read_attachment_write_scope_allows('
        )
        && str_contains($readSeveral, '$messageMap[$requestedFilename] ?? []'),
    'attachment-card lookup has no optional exact write-object scope'
);
$assert(
    str_contains($requireUseScope, '?array $writeScope = null')
        && str_contains($requireUseScope, 'estab_read_attachments(')
        && str_contains($requireUseScope, '$writeScope'),
    'final attachment-use validation drops the write-object scope'
);
$assert(
    !str_contains($pureReadDecision, 'permission_mode')
        && !str_contains($pureReadDecision, 'role_checks_enforced')
        && !str_contains($pureReadDecision, 'writeScope'),
    'ordinary attachment reads were relaxed instead of adding a write-only scope'
);

$assert(
    str_contains(
        $controllerSource,
        '$messageAttachmentWriteScope = ('
    )
        && str_contains(
            $controllerSource,
        'estab_read_attachment_write_scope ('
        )
        && str_contains($controllerSource, '$workflowSelectedIdentity')
        && str_contains($controllerSource, '"staff-correction"')
        && str_contains($controllerSource, '$objectMessage')
        && str_contains(
            $controllerSource,
            '$messageAttachmentWriteScope'
        )
        && !str_contains(
            $controllerSource,
            'estab_read_attachment_write_scope ($returnValue'
        ),
    'controller does not derive the preview/use scope from the authorised correction object only'
);
$assert(
    str_contains(
        $formSource,
        '$this->task === "Stab_korrigieren"'
    )
        && str_contains(
            $formSource,
            'estab_read_attachment_write_scope ('
        )
        && str_contains($formSource, '$this->formdata')
        && str_contains($formSource, '$attachmentWriteScope'),
    'message form does not pass the authorised write scope to attachment previews'
);
$resubmitSource = $slice(
    $messageSource,
    'function estab_message_resubmit_returned_outgoing(',
    'function estab_message_fetch_for_incident_by_id('
);
$assert(
    preg_match(
        '/\$attachmentAuthorizer\s*\(\s*\$connection,\s*'
            . '\$incidentId,\s*\$fields\[\'12_anhang\'\],\s*'
            . '\$originalMessage\s*\)/s',
        $resubmitSource
    ) === 1
        && strpos($resubmitSource, ' FOR UPDATE')
            < strpos($resubmitSource, '$attachmentAuthorizer('),
    'resubmission authorizer does not receive the transaction-locked original message'
);
$assert(
    str_contains(
        $handlerSource,
        'estab_read_attachment_write_scope ('
    )
        && str_contains($handlerSource, '"staff-correction"')
        && str_contains($handlerSource, '$writeMessage')
        && str_contains($handlerSource, '$attachmentWriteScope')
        && str_contains(
            $handlerSource,
            'estab_read_require_attachment_use_scope ('
        ),
    'final data handler does not rebuild and apply scope from the locked correction row'
);
$scopeForRecord = $slice(
    $readSource,
    'function estab_read_attachment_write_scope_for_record(',
    '/** Check one write scope against the freshly selected linked message. */'
);
$assert(
    str_contains($scopeForRecord, 'estab_incident_require_active(')
        && str_contains(
            $scopeForRecord,
            'estab_permission_context_set_from_incident($incident)'
        )
        && str_contains(
            $scopeForRecord,
            'estab_read_require_identity_scope('
        )
        && str_contains(
            $scopeForRecord,
            'estab_message_fetch_for_incident_by_id('
        )
        && str_contains($scopeForRecord, "'staff-correction'"),
    'browser record selector is not rebuilt from the current incident, account and message'
);
$assert(
    str_contains(
        $officialFormSource,
        '$this->task === \'Stab_korrigieren\''
    )
        && str_contains($officialFormSource, "'message_write_record'")
        && str_contains($officialFormSource, '$downloadUrl')
        && str_contains($officialFormSource, '$previewParameters')
        && str_contains($officialFormSource, '$emailParameters'),
    'correction attachment links do not carry their non-secret object selector'
);
foreach (
    [
        'download' => $downloadSource,
        'image preview' => $previewSource,
        'email preview' => $emailSource,
    ] as $endpointName => $endpointSource
) {
    $assert(
        preg_match(
            '/estab_read_attachment_write_record\s*\(/',
            $endpointSource
        ) === 1
            && preg_match_all(
                '/estab_read_attachment_write_scope_for_record\s*\(/',
                $endpointSource
            ) === 2
            && str_contains(
                $endpointSource,
                '$attachmentWritePermissionContext'
            )
            && str_contains($endpointSource, '$currentAttachmentWriteScope'),
        $endpointName . ' does not reauthorise the exact correction object before streaming'
    );
}
$assert(
    substr_count(
        $readSource,
        'estab_permission_context_matches_incident('
    ) >= 3,
    'attachment write scopes can survive an incident-mode revision change'
);

if (
    function_exists('estab_read_attachment_write_scope')
    && function_exists('estab_read_attachment_write_scope_allows')
) {
    $permissionContextKey = ESTAB_PERMISSION_CONTEXT_KEY;
    $takeoverIdentity = [
        'benutzer' => 'Anton Funk',
        'kuerzel' => 'aw0001',
        'funktion' => 'A/W',
        'rolle' => 'Fernmelder',
    ];
    $otherIdentity = [
        'benutzer' => 'Sina Sichtung',
        'kuerzel' => 'si0001',
        'funktion' => 'Si',
        'rolle' => 'Stab',
    ];
    $returned = [
        '00_lfd' => 101,
        'einsatz_id' => 7,
        '04_richtung' => 'A',
        '02_zeit' => null,
        '02_zeichen' => '',
        '03_datum' => null,
        '03_zeichen' => '',
        '06_befwegausw' => 'Fu',
        '12_anhang' => 'EL0001.pdf;EL0002.eml;',
        '14_zeichen' => 's10001',
        '14_funktion' => 'S1',
        '15_quitdatum' => '2026-08-01 12:05:00',
        '15_quitzeichen' => 'si0001',
        '16_empf' => 'S1_rt',
        'x00_status' => 10,
        'x01_abschluss' => 'f',
        'x02_sperre' => 'f',
        'x03_sperruser' => '',
    ];

    estab_permission_context_set_from_incident([
        'active_einsatz_id' => 7,
        'estab_permission_mode' => 'STRICT',
        'revision' => 11,
    ]);
    $assert(
        estab_read_attachment_write_scope(
            $takeoverIdentity,
            'staff-correction',
            $returned
        ) === null,
        'STRICT mode created a cross-role attachment write scope'
    );

    estab_permission_context_set_from_incident([
        'active_einsatz_id' => 7,
        'estab_permission_mode' => 'LOOSE',
        'revision' => 12,
    ]);
    $looseSnapshot = estab_permission_context();
    $assert(
        is_array($looseSnapshot)
            && estab_permission_context_snapshot_matches_incident(
                $looseSnapshot,
                [
                    'active_einsatz_id' => 7,
                    'estab_permission_mode' => 'LOOSE',
                    'revision' => 12,
                ]
            )
            && !estab_permission_context_snapshot_matches_incident(
                $looseSnapshot,
                [
                    'active_einsatz_id' => 7,
                    'estab_permission_mode' => 'LOOSE',
                    'revision' => 14,
                ]
            ),
        'LOOSE-to-STRICT-to-LOOSE ABA survived the explicit revision snapshot'
    );
    $assert(
        !estab_read_message_allowed($takeoverIdentity, $returned),
        'LOOSE mode changed the ordinary read rule used by attachments'
    );
    $scope = estab_read_attachment_write_scope(
        $takeoverIdentity,
        'staff-correction',
        $returned
    );
    $assert(
        is_array($scope)
            && ($scope['incident_id'] ?? null) === 7
            && ($scope['message_id'] ?? null) === 101
            && ($scope['operation'] ?? null) === 'staff-correction'
            && ($scope['benutzer'] ?? null) === 'Anton Funk'
            && ($scope['kuerzel'] ?? null) === 'aw0001'
            && ($scope['funktion'] ?? null) === 'A/W'
            && ($scope['rolle'] ?? null) === 'Fernmelder',
        'LOOSE write scope is not bound to the exact actor and correction object'
    );

    if (is_array($scope)) {
        $assert(
            estab_read_attachment_write_scope_allows(
                $scope,
                $takeoverIdentity,
                [$returned],
                7
            ),
            'authorised LOOSE correction cannot reuse its linked attachments'
        );
        $assert(
            !estab_read_attachment_write_scope_allows(
                $scope,
                $takeoverIdentity,
                [],
                7
            ),
            'originless archive attachment entered the correction scope'
        );
        $foreignMessage = $returned;
        $foreignMessage['00_lfd'] = 102;
        $foreignMessage['12_anhang'] = 'EL9999.pdf;';
        $assert(
            !estab_read_attachment_write_scope_allows(
                $scope,
                $takeoverIdentity,
                [$foreignMessage],
                7
            ),
            'attachment linked to a different message entered the correction scope'
        );
        $assert(
            !estab_read_attachment_write_scope_allows(
                $scope,
                $otherIdentity,
                [$returned],
                7
            ),
            'another account reused an attachment write scope'
        );
        $assert(
            !estab_read_attachment_write_scope_allows(
                $scope,
                $takeoverIdentity,
                [$returned],
                8
            ),
            'attachment write scope survived an incident mismatch'
        );
        estab_permission_context_set_from_incident([
            'active_einsatz_id' => 7,
            'estab_permission_mode' => 'STRICT',
            'revision' => 13,
        ]);
        $assert(
            !estab_read_attachment_write_scope_allows(
                $scope,
                $takeoverIdentity,
                [$returned],
                7
            ),
            'LOOSE attachment write scope remained usable after switching to STRICT'
        );
    }

    estab_permission_context_set_from_incident([
        'active_einsatz_id' => 7,
        'estab_permission_mode' => 'LOOSE',
        'revision' => 14,
    ]);
    foreach (
        [
            'read operation' => ['staff-read', $returned],
            'wrong status' => [
                'staff-correction',
                array_replace($returned, ['x00_status' => 4]),
            ],
            'wrong direction' => [
                'staff-correction',
                array_replace($returned, ['04_richtung' => 'E']),
            ],
        ] as $invalidScopeName => [$operation, $message]
    ) {
        $assert(
            estab_read_attachment_write_scope(
                $takeoverIdentity,
                $operation,
                $message
            ) === null,
            'write scope accepted ' . $invalidScopeName
        );
    }
    unset($GLOBALS[$permissionContextKey]);
}

if ($failures !== []) {
    throw new RuntimeException(
        "permission-mode attachment scope failures:\n- "
            . implode("\n- ", $failures)
    );
}

echo 'permission mode attachment scope security: OK ('
    . $assertions . " assertions)\n";
