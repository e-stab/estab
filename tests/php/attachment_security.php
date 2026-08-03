<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/attachment.php';
require_once __DIR__ . '/../../app/attachment_upload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertRejected = static function (callable $callback, string $message) use ($assert): void {
    $rejected = false;
    try {
        $callback();
    } catch (InvalidArgumentException | OverflowException) {
        $rejected = true;
    }
    $assert($rejected, $message);
};
$assertContextRejected = static function (
    callable $callback,
    string $message
) use ($assert): void {
    $rejected = false;
    try {
        $callback();
    } catch (EstabAttachmentContextException) {
        $rejected = true;
    }
    $assert($rejected, $message);
};

$staffIdentity = [
    'benutzer' => 'Erika Einsatz',
    'kuerzel' => 'ee',
    'funktion' => 'S1',
    'rolle' => 'Stab',
];
$telecommunicationsIdentity = [
    'benutzer' => 'Anton Funk',
    'kuerzel' => 'af',
    'funktion' => 'A/W',
    'rolle' => 'Fernmelder',
];
$viewerIdentity = [
    'benutzer' => 'Sina Sichtung',
    'kuerzel' => 'si',
    'funktion' => 'Si',
    'rolle' => 'Stab',
];
$staffIdentity['estab_permission_mode'] = ESTAB_PERMISSION_MODE_STRICT;
$staffIdentity['duty_assignment_id'] = 401;
$telecommunicationsIdentity['estab_permission_mode'] =
    ESTAB_PERMISSION_MODE_STRICT;
$telecommunicationsIdentity['duty_assignment_id'] = 402;
$viewerIdentity['estab_permission_mode'] = ESTAB_PERMISSION_MODE_STRICT;
$viewerIdentity['duty_assignment_id'] = 403;
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 9,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 6,
]);

$directActionSession = [];
$directActionToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1000
);
$authorityBoundDirectToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1000
);
$successorDirectIdentity = $staffIdentity;
$successorDirectIdentity['duty_assignment_id'] = 499;
$assertContextRejected(
    static fn () => estab_attachment_direct_action_replay_result(
        $directActionSession,
        $authorityBoundDirectToken,
        $successorDirectIdentity,
        9,
        'Stab_schreiben',
        null,
        1001
    ),
    'direct attachment draft token survived an exact assignment handover'
);
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 9,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 7,
]);
$assertContextRejected(
    static fn () => estab_attachment_direct_action_replay_result(
        $directActionSession,
        $authorityBoundDirectToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1001
    ),
    'direct attachment draft token survived an incident status revision'
);
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 9,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 6,
]);
$assert(
    preg_match('/\A[a-f0-9]{64}\z/D', $directActionToken) === 1
        && ($directActionSession['anhang_direct_actions'][$directActionToken]['state'] ?? null)
            === 'issued'
        && estab_attachment_direct_action_replay_result(
            $directActionSession,
            $directActionToken,
            $staffIdentity,
            9,
            'Stab_schreiben',
            null,
            1001
        ) === null
        && ($directActionSession['anhang_direct_actions'][$directActionToken]['state'] ?? null)
            === 'issued'
        && estab_attachment_direct_action_claim(
            $directActionSession,
            $directActionToken,
            $staffIdentity,
            9,
            'Stab_schreiben',
            null,
            1001
        ) === null,
    'direct attachment action is not issued and claimed exactly once'
);
$assertContextRejected(
    static fn () => estab_attachment_direct_action_claim(
        $directActionSession,
        $directActionToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1002
    ),
    'a concurrently processing direct attachment action can be claimed twice'
);
estab_attachment_direct_action_complete(
    $directActionSession,
    $directActionToken,
    'EL0099.PDF',
    'upload',
    1003
);
$assert(
    estab_attachment_direct_action_claim(
        $directActionSession,
        $directActionToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1004
    ) === ['reference' => 'EL0099.pdf', 'mode' => 'upload'],
    'completed direct upload replay does not recover the one canonical reference'
);
$assertContextRejected(
    static fn () => estab_attachment_direct_action_claim(
        $directActionSession,
        $directActionToken,
        $staffIdentity,
        10,
        'Stab_schreiben',
        null,
        1004
    ),
    'direct attachment replay crosses the incident boundary'
);
$pendingSubmitToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1005
);
estab_attachment_direct_action_claim(
    $directActionSession,
    $pendingSubmitToken,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1006
);
estab_attachment_direct_action_note_pending_submit(
    $directActionSession,
    $pendingSubmitToken,
    'EL0100.PNG',
    1007
);
$assert(
    estab_attachment_direct_action_replay_result(
        $directActionSession,
        $pendingSubmitToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1008
    ) === ['reference' => 'EL0100.png', 'mode' => 'pending-submit'],
    'pending message validation cannot recover its already stored upload'
);
estab_attachment_direct_action_complete(
    $directActionSession,
    $pendingSubmitToken,
    'EL0100.png',
    'submit',
    1009
);
$assert(
    estab_attachment_direct_action_replay_result(
        $directActionSession,
        $pendingSubmitToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1010
    ) === ['reference' => 'EL0100.png', 'mode' => 'submit'],
    'successfully saved upload-and-message action is replayable as a new write'
);
$noFileSubmitToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1010
);
estab_attachment_direct_action_claim(
    $directActionSession,
    $noFileSubmitToken,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1011
);
estab_attachment_direct_action_note_pending_submit(
    $directActionSession,
    $noFileSubmitToken,
    null,
    1012
);
$assert(
    estab_attachment_direct_action_replay_result(
        $directActionSession,
        $noFileSubmitToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1013
    ) === ['reference' => null, 'mode' => 'pending-submit'],
    'a message submit without a new file has no replay checkpoint'
);
estab_attachment_direct_action_complete(
    $directActionSession,
    $noFileSubmitToken,
    null,
    'submit',
    1014
);
$assert(
    estab_attachment_direct_action_replay_result(
        $directActionSession,
        $noFileSubmitToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1015
    ) === ['reference' => null, 'mode' => 'submit'],
    'a completed no-file message submit can be repeated as a second write'
);
$conversationStageToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1010
);
estab_attachment_direct_action_claim(
    $directActionSession,
    $conversationStageToken,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1011
);
estab_attachment_direct_action_complete(
    $directActionSession,
    $conversationStageToken,
    'EL0101.pdf',
    'conversation-stage',
    1012
);
$assert(
    estab_attachment_direct_action_replay_result(
        $directActionSession,
        $conversationStageToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1013
    ) === ['reference' => 'EL0101.pdf', 'mode' => 'conversation-stage'],
    'conversation-note transition replay can fall back to an ordinary message'
);
$conversationNoFileToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1014
);
estab_attachment_direct_action_claim(
    $directActionSession,
    $conversationNoFileToken,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1015
);
estab_attachment_direct_action_complete(
    $directActionSession,
    $conversationNoFileToken,
    null,
    'conversation-stage',
    1016
);
$assert(
    estab_attachment_direct_action_replay_result(
        $directActionSession,
        $conversationNoFileToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1017
    ) === ['reference' => null, 'mode' => 'conversation-stage'],
    'a no-file conversation transition is not replayable'
);
$forgottenToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1010
);
estab_attachment_direct_action_claim(
    $directActionSession,
    $forgottenToken,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1011
);
estab_attachment_direct_action_forget(
    $directActionSession,
    $forgottenToken,
    1012
);
$assertContextRejected(
    static fn () => estab_attachment_direct_action_claim(
        $directActionSession,
        $forgottenToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1013
    ),
    'explicitly forgotten direct attachment token remains replayable'
);
$abandonedToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1010
);
estab_attachment_direct_action_claim(
    $directActionSession,
    $abandonedToken,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    1011
);
estab_attachment_direct_action_abandon(
    $directActionSession,
    $abandonedToken,
    1012
);
$assertContextRejected(
    static fn () => estab_attachment_direct_action_claim(
        $directActionSession,
        $abandonedToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        1013
    ),
    'abandoned failed-upload token remains reusable'
);
$expiredToken = estab_attachment_direct_action_issue(
    $directActionSession,
    $staffIdentity,
    9,
    'Stab_schreiben',
    null,
    2000
);
$assertContextRejected(
    static fn () => estab_attachment_direct_action_claim(
        $directActionSession,
        $expiredToken,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        2000 + ESTAB_ATTACHMENT_DIRECT_ACTION_TTL_SECONDS + 1
    ),
    'expired direct attachment action remains reusable'
);
$boundedDirectActionSession = [];
for ($tokenNumber = 0; $tokenNumber < 70; $tokenNumber++) {
    estab_attachment_direct_action_issue(
        $boundedDirectActionSession,
        $staffIdentity,
        9,
        'Stab_schreiben',
        null,
        3000 + $tokenNumber
    );
}
$assert(
    count($boundedDirectActionSession['anhang_direct_actions'] ?? [])
        === ESTAB_ATTACHMENT_DIRECT_ACTION_MAX_TOKENS,
    'direct attachment tokens grow the PHP session without a hard bound'
);

$staffWriteContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    [
        'task' => 'Stab_schreiben',
        '00_lfd' => '',
        'permission_mode' => 'LOOSE',
        'permission_revision' => '999',
        'duty_assignment_id' => '999',
        'account_function' => 'A/W',
        'account_role' => 'Fernmelder',
        'function_source' => 'PRIMARY',
        'authority_fingerprint' => str_repeat('0', 64),
    ]
);
$assert(
    $staffWriteContext['task'] === 'Stab_schreiben'
        && $staffWriteContext['version'] === 3
        && $staffWriteContext['record_id'] === null
        && $staffWriteContext['incident_id'] === 9
        && $staffWriteContext['permission_mode'] === 'STRICT'
        && $staffWriteContext['permission_revision'] === 6
        && $staffWriteContext['duty_assignment_id'] === 401
        && $staffWriteContext['account_function'] === null
        && $staffWriteContext['account_role'] === null
        && $staffWriteContext['function_source'] === 'DUTY_ASSIGNMENT'
        && preg_match(
            '/\A[a-f0-9]{64}\z/D',
            $staffWriteContext['authority_fingerprint']
        ) === 1
        && preg_match(
            '/\A[a-f0-9]{32}\z/D',
            $staffWriteContext['flow_token']
        ) === 1,
    'new staff attachment origin trusts browser authority instead of its '
        . 'server identity and incident snapshot'
);
$validatedWriteContext = estab_attachment_origin_context_validate(
    $staffWriteContext,
    $staffIdentity,
    9,
    [
        'task' => 'Stab_schreiben',
        '00_lfd' => '',
        'attachment_flow' => $staffWriteContext['flow_token'],
    ],
    true
);
$assert(
    $validatedWriteContext === $staffWriteContext,
    'valid attachment continuation does not preserve the exact origin context'
);
$successorStaffIdentity = $staffIdentity;
$successorStaffIdentity['duty_assignment_id'] = 404;
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $successorStaffIdentity,
        9
    ),
    'STRICT attachment origin survived an exact duty-assignment handover'
);
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 9,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 7,
]);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $staffIdentity,
        9
    ),
    'attachment origin survived deactivation/reactivation status revision'
);
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 9,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 6,
]);
$tamperedAuthorityContext = $staffWriteContext;
$tamperedAuthorityContext['duty_assignment_id'] = 404;
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $tamperedAuthorityContext,
        $successorStaffIdentity,
        9
    ),
    'session authority fields can be changed without invalidating the fingerprint'
);
$semanticallyInvalidAuthorityContext = $staffWriteContext;
$semanticallyInvalidAuthorityContext['function_source'] = 'PRIMARY';
$semanticallyInvalidAuthorityContext['authority_fingerprint'] =
    estab_attachment_origin_context_fingerprint(
        $semanticallyInvalidAuthorityContext
    );
$assertContextRejected(
    static function () use ($semanticallyInvalidAuthorityContext): void {
        $session = [];
        estab_attachment_origin_context_store(
            $session,
            $semanticallyInvalidAuthorityContext
        );
    },
    'session storage accepted a re-fingerprinted inconsistent STRICT authority'
);
$conversationContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_gesprnoti', '00_lfd' => '']
);
$assert(
    $conversationContext['task'] === 'Stab_gesprnoti',
    'conversation-note attachment origin changes its workflow task'
);
$recipientMatrix = [
    1 => [
        1 => ['fkt' => 'S1', 'rolle' => 'Stab'],
        2 => ['fkt' => 'AB_C', 'rolle' => 'Stab'],
    ],
];
$recipientMatrixRevision = estab_workflow_recipient_matrix_revision(
    $recipientMatrix,
    'LdF'
);
$conversationDraft = estab_attachment_origin_draft_from_request(
    [
        '01_medium' => 'FAX',
        '12_betreff' => 'Lageänderung',
        '12_inhalt' => 'Entwurf mit Anhang',
        '16_12' => '16_12_bl',
        'recipient_matrix_revision' => $recipientMatrixRevision,
    ],
    $staffIdentity,
    $conversationContext
);
$assert(
    $conversationDraft['01_medium'] === 'FAX'
        && $conversationDraft['recipient_matrix_revision']
        === $recipientMatrixRevision
        && $conversationDraft['16_12'] === '16_12_bl'
        && !array_key_exists('16_gncopy', $conversationDraft),
    'attachment draft drops the matrix revision or its coordinate selections'
);
foreach (['', '16_11_gn'] as $retiredGreenValue) {
    $retiredGreenRejected = false;
    try {
        estab_attachment_origin_draft_from_request(
            [
                '16_gncopy' => $retiredGreenValue,
                'recipient_matrix_revision' => $recipientMatrixRevision,
            ],
            $staffIdentity,
            $conversationContext
        );
    } catch (EstabAttachmentDraftException $exception) {
        $retiredGreenRejected = $exception->draft() === [];
    }
    $assert(
        $retiredGreenRejected,
        'attachment draft accepted the retired green-copy field'
    );
}
$conversationFormData = estab_attachment_origin_draft_form_data(
    $conversationDraft,
    $conversationContext,
    null,
    $recipientMatrix,
    true,
    'LdF',
    ['LdF_rt', 'S1_gn']
);
$assert(
    $conversationFormData['01_medium'] === 'FAX'
        && $conversationFormData['16_empf'] === 'LdF_rt,S1_gn,AB_C_bl,'
        && $conversationFormData['recipient_matrix_revision']
            === $recipientMatrixRevision,
    'central conversation attachment return loses the medium, exact blue coordinate or server-required red/author-green copies'
);
$retiredStoredDraftRejected = false;
try {
    estab_attachment_origin_draft_form_data(
        $conversationDraft + ['16_gncopy' => ''],
        $conversationContext,
        null,
        $recipientMatrix,
        true,
        'LdF',
        ['LdF_rt', 'S1_gn']
    );
} catch (EstabAttachmentContextException) {
    $retiredStoredDraftRejected = true;
}
$assert(
    $retiredStoredDraftRejected,
    'attachment reconstruction accepted a stored retired green-copy field'
);
$changedRecipientMatrix = $recipientMatrix;
$changedRecipientMatrix[1][1]['fkt'] = 'S2';
$staleMatrixRejected = false;
try {
    estab_attachment_origin_draft_form_data(
        $conversationDraft,
        $conversationContext,
        null,
        $changedRecipientMatrix,
        true,
        'LdF',
        ['LdF_rt', 'S1_gn']
    );
} catch (EstabAttachmentDraftException) {
    $staleMatrixRejected = true;
}
$assert(
    $staleMatrixRejected,
    'central attachment return silently remaps stale recipient coordinates'
);
$safeStaleFormData = estab_attachment_origin_draft_form_data(
    $conversationDraft,
    $conversationContext,
    null,
    $changedRecipientMatrix,
    false,
    'LdF',
    ['LdF_rt', 'S1_gn']
);
$assert(
    $safeStaleFormData['16_empf'] === ''
        && $safeStaleFormData['recipient_matrix_revision']
            === $recipientMatrixRevision,
    'non-strict 422 recovery invents recipients after a matrix change'
);
$flowSession = [];
$writeToken = estab_attachment_origin_context_store(
    $flowSession,
    $staffWriteContext
);
estab_attachment_origin_draft_store(
    $flowSession,
    $staffWriteContext,
    ['12_inhalt' => 'Entwurf Tab A']
);
$conversationToken = estab_attachment_origin_context_store(
    $flowSession,
    $conversationContext
);
estab_attachment_origin_draft_store(
    $flowSession,
    $conversationContext,
    ['12_inhalt' => 'Entwurf Tab B']
);
$assert(
    $writeToken !== $conversationToken
        && estab_attachment_origin_context_find(
            $flowSession,
            $writeToken
        ) === $staffWriteContext
        && estab_attachment_origin_context_find(
            $flowSession,
            $conversationToken
        ) === $conversationContext
        && estab_attachment_origin_draft_find(
            $flowSession,
            $staffWriteContext
        )['12_inhalt'] === 'Entwurf Tab A'
        && estab_attachment_origin_draft_find(
            $flowSession,
            $conversationContext
        )['12_inhalt'] === 'Entwurf Tab B',
    'two browser tabs overwrite each other\'s attachment context or draft'
);
$limitContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_schreiben', '00_lfd' => '']
);
$limitSession = [];
estab_attachment_origin_context_store($limitSession, $limitContext);
$normalAttachmentReferences = [];
for ($attachmentNumber = 1; $attachmentNumber <= 100; $attachmentNumber++) {
    $normalAttachmentReferences[] = sprintf(
        'EL%04d.pdf',
        $attachmentNumber
    );
}
$normalMultibyteBody = str_repeat(
    "Führung 🚒 – Lageänderung\n",
    8000
);
$normalDraft = [
    '12_inhalt' => $normalMultibyteBody,
    '12_anhang' => implode(';', $normalAttachmentReferences) . ';',
    '17_vermerke' => 'Rückfrage an die Führungsstelle – bestätigt.',
];
estab_attachment_origin_draft_store(
    $limitSession,
    $limitContext,
    $normalDraft
);
$assert(
    estab_attachment_origin_draft_find(
        $limitSession,
        $limitContext
    ) === $normalDraft
        && estab_attachment_origin_draft_bytes($normalDraft)
            < ESTAB_ATTACHMENT_ORIGIN_DRAFT_MAX_BYTES,
    'bounded draft storage changes normal UTF-8 text or 100 attachment references'
);
$validLimitSession = $limitSession;
$assertContextRejected(
    static function () use (&$limitSession, $limitContext): void {
        estab_attachment_origin_draft_store(
            $limitSession,
            $limitContext,
            ['12_inhalt' => ['nested browser value']]
        );
    },
    'nested draft value bypasses scalar session storage'
);
$assert(
    $limitSession === $validLimitSession,
    'nested draft rejection partially mutated the valid session state'
);
$assertContextRejected(
    static function () use (&$limitSession, $limitContext): void {
        estab_attachment_origin_draft_store(
            $limitSession,
            $limitContext,
            ['12_inhalt' => "ungültig\xC3\x28"]
        );
    },
    'malformed UTF-8 is retained in an attachment draft'
);
$assertContextRejected(
    static function () use (&$limitSession, $limitContext): void {
        estab_attachment_origin_draft_store(
            $limitSession,
            $limitContext,
            ['browser_selected_task' => 'Stab_korrigieren']
        );
    },
    'unexpected browser field is retained in an attachment draft'
);
$assertContextRejected(
    static function () use (&$limitSession, $limitContext): void {
        estab_attachment_origin_draft_store(
            $limitSession,
            $limitContext,
            [
                '12_anhang' => str_repeat(
                    'A',
                    ESTAB_ATTACHMENT_ORIGIN_ATTACHMENT_LIST_MAX_BYTES + 1
                ),
            ]
        );
    },
    'attachment draft exceeds the database-safe reference-list boundary'
);
$assertContextRejected(
    static function () use (&$limitSession, $limitContext): void {
        estab_attachment_origin_draft_store(
            $limitSession,
            $limitContext,
            [
                '12_inhalt' => str_repeat(
                    'N',
                    ESTAB_ATTACHMENT_ORIGIN_DRAFT_MAX_BYTES
                ),
            ]
        );
    },
    'one oversized message draft exhausts PHP session storage'
);
$assert(
    $limitSession === $validLimitSession
        && estab_attachment_origin_draft_find(
            $limitSession,
            $limitContext
        ) === $normalDraft,
    'rejected draft replaces or deletes the last valid unsaved message'
);

$totalLimitSession = [];
$totalContexts = [];
for ($draftNumber = 1; $draftNumber <= 9; $draftNumber++) {
    $totalContext = estab_attachment_origin_context_create(
        $staffIdentity,
        9,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    );
    estab_attachment_origin_context_store(
        $totalLimitSession,
        $totalContext
    );
    estab_attachment_origin_draft_store(
        $totalLimitSession,
        $totalContext,
        ['12_inhalt' => str_repeat((string) $draftNumber, 850000)]
    );
    $totalContexts[] = $totalContext;
}
$tenthTotalContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_schreiben', '00_lfd' => '']
);
estab_attachment_origin_context_store(
    $totalLimitSession,
    $tenthTotalContext
);
$totalSessionBeforeRejection = $totalLimitSession;
$assertContextRejected(
    static function () use (
        &$totalLimitSession,
        $tenthTotalContext
    ): void {
        estab_attachment_origin_draft_store(
            $totalLimitSession,
            $tenthTotalContext,
            ['12_inhalt' => str_repeat('X', 850000)]
        );
    },
    'aggregate draft storage exceeds its bounded 16-flow session budget'
);
$assert(
    $totalLimitSession === $totalSessionBeforeRejection
        && count($totalLimitSession['anhang_origin_drafts']) === 9
        && estab_attachment_origin_draft_find(
            $totalLimitSession,
            $totalContexts[0]
        )['12_inhalt'] === str_repeat('1', 850000),
    'aggregate-size rejection loses an older draft or leaves a partial new one'
);
$assertRejected(
    static fn () => estab_attachment_origin_context_store(
        $totalLimitSession,
        $tenthTotalContext,
        ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS + 1
    ),
    'attachment context map can be configured beyond the audited 16-flow cap'
);

$atomicSession = [];
$atomicContexts = [];
$unexpectedAtomicReleases = [];
for ($flowNumber = 1; $flowNumber <= 16; $flowNumber++) {
    $atomicContext = estab_attachment_origin_context_create(
        $staffIdentity,
        9,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    );
    estab_attachment_origin_flow_store(
        $atomicSession,
        $atomicContext,
        ['12_inhalt' => 'Atomarer Entwurf ' . $flowNumber],
        ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
        static function (array $released) use (
            &$unexpectedAtomicReleases
        ): void {
            $unexpectedAtomicReleases[] = $released;
        }
    );
    $atomicContexts[] = $atomicContext;
}
$assert(
    count($atomicSession['anhang_origin_contexts']) === 16
        && count($atomicSession['anhang_origin_drafts']) === 16
        && $unexpectedAtomicReleases === [],
    'atomic flow store evicts before the audited 16-flow capacity is reached'
);
$oversizedAtomicContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_schreiben', '00_lfd' => '']
);
$atomicBeforeOversize = $atomicSession;
$assertContextRejected(
    static function () use (
        &$atomicSession,
        $oversizedAtomicContext,
        &$unexpectedAtomicReleases
    ): void {
        estab_attachment_origin_flow_store(
            $atomicSession,
            $oversizedAtomicContext,
            [
                '12_inhalt' => str_repeat(
                    'X',
                    ESTAB_ATTACHMENT_ORIGIN_DRAFT_MAX_BYTES
                ),
            ],
            ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
            static function (array $released) use (
                &$unexpectedAtomicReleases
            ): void {
                $unexpectedAtomicReleases[] = $released;
            }
        );
    },
    'oversized seventeenth draft reaches eviction before validation'
);
$assert(
    $atomicSession === $atomicBeforeOversize
        && $unexpectedAtomicReleases === []
        && estab_attachment_origin_context_find(
            $atomicSession,
            $oversizedAtomicContext['flow_token']
        ) === null,
    'oversized draft evicts an old flow, releases a reservation, or leaves an empty context'
);
$invalidAtomicContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_schreiben', '00_lfd' => '']
);
$assertContextRejected(
    static function () use (
        &$atomicSession,
        $invalidAtomicContext,
        &$unexpectedAtomicReleases
    ): void {
        estab_attachment_origin_flow_store(
            $atomicSession,
            $invalidAtomicContext,
            ['12_inhalt' => ['nested']],
            ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
            static function (array $released) use (
                &$unexpectedAtomicReleases
            ): void {
                $unexpectedAtomicReleases[] = $released;
            }
        );
    },
    'invalid seventeenth draft reaches eviction before scalar validation'
);
$assert(
    $atomicSession === $atomicBeforeOversize
        && $unexpectedAtomicReleases === []
        && estab_attachment_origin_context_find(
            $atomicSession,
            $invalidAtomicContext['flow_token']
        ) === null,
    'invalid draft mutates the full flow map or invokes reservation cleanup'
);

$validSeventeenthContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_schreiben', '00_lfd' => '']
);
$atomicReleases = [];
$validSeventeenthToken = estab_attachment_origin_flow_store(
    $atomicSession,
    $validSeventeenthContext,
    ['12_inhalt' => 'Gültiger siebzehnter Entwurf'],
    ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
    static function (array $released) use (&$atomicReleases): void {
        $atomicReleases[] = $released;
    }
);
$assert(
    $atomicReleases === [$atomicContexts[0]]
        && estab_attachment_origin_context_find(
            $atomicSession,
            $atomicContexts[0]['flow_token']
        ) === null
        && !isset(
            $atomicSession['anhang_origin_drafts'][
                $atomicContexts[0]['flow_token']
            ]
        )
        && estab_attachment_origin_context_find(
            $atomicSession,
            $validSeventeenthToken
        ) === $validSeventeenthContext
        && estab_attachment_origin_draft_find(
            $atomicSession,
            $validSeventeenthContext
        )['12_inhalt'] === 'Gültiger siebzehnter Entwurf',
    'valid full-capacity replacement is not committed with exact reservation cleanup'
);

$callbackFailureContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_schreiben', '00_lfd' => '']
);
$atomicBeforeCallbackFailure = $atomicSession;
$callbackFailureCaught = false;
try {
    estab_attachment_origin_flow_store(
        $atomicSession,
        $callbackFailureContext,
        ['12_inhalt' => 'Entwurf bei Cleanup-Fehler'],
        ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
        static function (array $released): void {
            throw new RuntimeException('simulated reservation release failure');
        }
    );
} catch (RuntimeException $exception) {
    $callbackFailureCaught = true;
}
$assert(
    $callbackFailureCaught
        && $atomicSession === $atomicBeforeCallbackFailure
        && estab_attachment_origin_context_find(
            $atomicSession,
            $callbackFailureContext['flow_token']
        ) === null,
    'reservation cleanup failure leaves partially replaced context/draft maps'
);
$writeReservationOwner = estab_attachment_reservation_owner_id(
    'session-safe_123',
    $staffWriteContext
);
$conversationReservationOwner = estab_attachment_reservation_owner_id(
    'session-safe_123',
    $conversationContext
);
$assert(
    $writeReservationOwner !== $conversationReservationOwner
        && $writeReservationOwner === estab_attachment_reservation_owner_id(
            'session-safe_123',
            $staffWriteContext
        )
        && estab_attachment_reservation_owner_id('session-safe_123') ===
            'session-safe_123',
    'parallel attachment tabs share or destabilise their upload reservation owner'
);
estab_attachment_origin_context_clear($flowSession, $writeToken);
$assert(
    estab_attachment_origin_context_find($flowSession, $writeToken) === null
        && estab_attachment_origin_context_find(
            $flowSession,
            $conversationToken
        ) === $conversationContext
        && estab_attachment_origin_draft_find(
            $flowSession,
            $conversationContext
        )['12_inhalt'] === 'Entwurf Tab B',
    'finishing one attachment flow deletes another browser tab'
);
estab_attachment_origin_context_clear($flowSession);
$assert(
    estab_attachment_origin_context_find(
        $flowSession,
        $conversationToken
    ) === $conversationContext,
    'opening the global attachment overview deletes a message-form flow'
);
$boundedSession = [];
estab_attachment_origin_context_store(
    $boundedSession,
    $staffWriteContext,
    2
);
estab_attachment_origin_draft_store(
    $boundedSession,
    $staffWriteContext,
    ['12_inhalt' => 'Alter begrenzter Entwurf']
);
estab_attachment_origin_context_store(
    $boundedSession,
    $conversationContext,
    2
);
$thirdContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_schreiben', '00_lfd' => '']
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_store(
        $boundedSession,
        $thirdContext,
        2
    ),
    'flow-limit eviction drops a context without releasing its reservation'
);
$evictedContexts = [];
$thirdToken = estab_attachment_origin_context_store(
    $boundedSession,
    $thirdContext,
    2,
    static function (array $context) use (&$evictedContexts): void {
        $evictedContexts[] = $context;
    }
);
$assert(
    $evictedContexts === [$staffWriteContext]
        && estab_attachment_origin_context_find(
            $boundedSession,
            $writeToken
        ) === null
        && estab_attachment_origin_context_find(
            $boundedSession,
            $conversationToken
        ) === $conversationContext
        && estab_attachment_origin_context_find(
            $boundedSession,
            $thirdToken
        ) === $thirdContext
        && !isset(
            $boundedSession['anhang_origin_drafts'][$writeToken]
        ),
    'bounded flow eviction runs after cleanup or deletes the wrong tab'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $conversationContext,
        $staffIdentity,
        9,
        ['attachment_flow' => $writeToken],
        true
    ),
    'one tab can validate itself with another tab\'s flow token'
);
$assert(
    estab_attachment_origin_context_find(
        $flowSession,
        $conversationToken
    ) === $conversationContext,
    'a mismatching flow token mutated the valid server-side context'
);
$correctionMessage = [
    '00_lfd' => 73,
    'einsatz_id' => 9,
];
$correctionContext = estab_attachment_origin_context_create(
    $staffIdentity,
    9,
    ['task' => 'Stab_korrigieren', '00_lfd' => 73],
    $correctionMessage
);
$assert(
    $correctionContext['task'] === 'Stab_korrigieren'
        && $correctionContext['record_id'] === 73,
    'correction attachment origin does not use the authorised server record'
);
$correctionDraft = estab_attachment_origin_draft_from_request(
    [
        '07_durchspruch' => 'D',
        '08_befhinweis' => 'Manipulierter Entwurfshinweis',
        '08_befhinwausw' => 'Fe',
        'recipient_matrix_revision' => $recipientMatrixRevision,
    ],
    $staffIdentity,
    $correctionContext
);
$allowedDraftFields = estab_attachment_origin_draft_fields();
$assert(
    !array_key_exists('08_befhinweis', $correctionDraft)
        && !array_key_exists('08_befhinwausw', $correctionDraft)
        && !isset($allowedDraftFields['08_befhinweis'])
        && !isset($allowedDraftFields['08_befhinwausw']),
    'attachment draft retained retired transport-hint browser values'
);
$correctionFormData = estab_attachment_origin_draft_form_data(
    $correctionDraft,
    $correctionContext,
    array_replace($correctionMessage, [
        '07_durchspruch' => 'S',
        '08_befhinweis' => 'Historischer Datenbankhinweis',
        '08_befhinwausw' => 'Fu',
    ]),
    $recipientMatrix,
    true,
    'LdF'
);
$assert(
    $correctionFormData['07_durchspruch'] === 'D'
        && $correctionFormData['08_befhinweis']
            === 'Historischer Datenbankhinweis'
        && $correctionFormData['08_befhinwausw'] === 'Fu',
    'attachment correction return replaced freshly authorised historical hint evidence'
);
foreach (['FM-Eingang', 'FM-Eingang_Anhang'] as $incomingTask) {
    $incomingContext = estab_attachment_origin_context_create(
        $telecommunicationsIdentity,
        9,
        ['task' => $incomingTask, '00_lfd' => '']
    );
    $assert(
        $incomingContext['task'] === $incomingTask
            && $incomingContext['record_id'] === null,
        'telecommunications attachment origin changes the exact incoming task'
    );
}
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $staffIdentity,
        9,
        ['task' => 'FM-Eingang', '00_lfd' => '']
    ),
    'staff identity can forge a telecommunications attachment origin'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $telecommunicationsIdentity,
        9,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    ),
    'telecommunications identity can forge a staff attachment origin'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $viewerIdentity,
        9,
        ['task' => 'FM-Eingang', '00_lfd' => '']
    ),
    'viewer identity can forge a telecommunications attachment origin in STRICT mode'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $viewerIdentity,
        9,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    ),
    'viewer identity can forge a staff attachment origin in STRICT mode'
);

$permissionContextKey = ESTAB_PERMISSION_CONTEXT_KEY;
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 9,
    'estab_permission_mode' => 'LOOSE',
    'revision' => 7,
]);
$looseStaffWithAw = array_replace($staffIdentity, [
    'estab_permission_mode' => 'LOOSE',
    'duty_assignment_id' => null,
    'authorization_account_function' => 'S1',
    'authorization_account_role' => 'Stab',
    'estab_additional_functions' => [
        ['funktion' => 'A/W', 'rolle' => 'Fernmelder'],
    ],
]);
$looseTelecomWithS1 = array_replace($telecommunicationsIdentity, [
    'estab_permission_mode' => 'LOOSE',
    'duty_assignment_id' => null,
    'authorization_account_function' => 'A/W',
    'authorization_account_role' => 'Fernmelder',
    'estab_additional_functions' => [
        ['funktion' => 'S1', 'rolle' => 'Stab'],
    ],
]);
$looseViewerWithAw = array_replace($viewerIdentity, [
    'estab_permission_mode' => 'LOOSE',
    'duty_assignment_id' => null,
    'authorization_account_function' => 'Si',
    'authorization_account_role' => 'Stab',
    'estab_additional_functions' => [
        ['funktion' => 'A/W', 'rolle' => 'Fernmelder'],
    ],
]);
$looseViewerWithS1 = array_replace($viewerIdentity, [
    'estab_permission_mode' => 'LOOSE',
    'duty_assignment_id' => null,
    'authorization_account_function' => 'Si',
    'authorization_account_role' => 'Stab',
    'estab_additional_functions' => [
        ['funktion' => 'S1', 'rolle' => 'Stab'],
    ],
]);
$looseGrantedOrigins = [
    estab_attachment_origin_context_create(
        estab_workflow_identity_as_tuple(
            $looseStaffWithAw,
            ['funktion' => 'A/W', 'rolle' => 'Fernmelder']
        ),
        9,
        ['task' => 'FM-Eingang', '00_lfd' => '']
    ),
    estab_attachment_origin_context_create(
        estab_workflow_identity_as_tuple(
            $looseTelecomWithS1,
            ['funktion' => 'S1', 'rolle' => 'Stab']
        ),
        9,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    ),
    estab_attachment_origin_context_create(
        estab_workflow_identity_as_tuple(
            $looseViewerWithAw,
            ['funktion' => 'A/W', 'rolle' => 'Fernmelder']
        ),
        9,
        ['task' => 'FM-Eingang_Anhang', '00_lfd' => '']
    ),
    estab_attachment_origin_context_create(
        estab_workflow_identity_as_tuple(
            $looseViewerWithS1,
            ['funktion' => 'S1', 'rolle' => 'Stab']
        ),
        9,
        ['task' => 'Stab_gesprnoti', '00_lfd' => '']
    ),
];
$assert(
    array_column($looseGrantedOrigins, 'task') === [
        'FM-Eingang',
        'Stab_schreiben',
        'FM-Eingang_Anhang',
        'Stab_gesprnoti',
    ]
        && array_column($looseGrantedOrigins, 'permission_mode') === [
            'LOOSE', 'LOOSE', 'LOOSE', 'LOOSE',
        ]
        && array_column($looseGrantedOrigins, 'permission_revision') === [
            7, 7, 7, 7,
        ]
        && array_column($looseGrantedOrigins, 'function_source') === [
            'ADDITIONAL', 'ADDITIONAL', 'ADDITIONAL', 'ADDITIONAL',
        ]
        && array_column($looseGrantedOrigins, 'duty_assignment_id') === [
            null, null, null, null,
        ],
    'LOOSE mode does not admit each explicitly granted attachment write flow'
);
$assert(
    estab_attachment_origin_context_validate(
        $looseGrantedOrigins[0],
        $looseStaffWithAw,
        9
    ) === $looseGrantedOrigins[0],
    'LOOSE attachment flow does not survive with its explicit function grant'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $looseGrantedOrigins[0],
        $staffIdentity,
        9
    ),
    'revoked LOOSE additional function retained its attachment flow'
);
$looseStaffChangedPrimary = array_replace($looseStaffWithAw, [
    'funktion' => 'A/W',
    'rolle' => 'Fernmelder',
    'authorization_account_function' => 'A/W',
    'authorization_account_role' => 'Fernmelder',
    'estab_additional_functions' => [
        ['funktion' => 'S1', 'rolle' => 'Stab'],
    ],
]);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $looseGrantedOrigins[0],
        $looseStaffChangedPrimary,
        9
    ),
    'LOOSE attachment origin changed an additional tuple into a primary grant'
);
unset($GLOBALS[$permissionContextKey]);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $staffIdentity,
        9,
        ['task' => 'Stab_schreiben', '00_lfd' => '']
    ),
    'attachment origin was created without a DB-derived permission context'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $staffIdentity,
        9
    ),
    'attachment origin was resumed without a DB-derived permission context'
);
estab_permission_context_set_from_incident([
    'active_einsatz_id' => 9,
    'estab_permission_mode' => ESTAB_PERMISSION_MODE_STRICT,
    'revision' => 6,
]);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $staffIdentity,
        9,
        ['task' => 'Stab_schreiben', '00_lfd' => '73']
    ),
    'new-message attachment origin accepts a browser-selected record id'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $staffIdentity,
        9,
        ['task' => 'Stab_korrigieren', '00_lfd' => 74],
        $correctionMessage
    ),
    'correction attachment origin accepts a record id differing from the authorised row'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_create(
        $staffIdentity,
        9,
        ['task' => 'Stab_korrigieren', '00_lfd' => 73]
    ),
    'correction attachment origin exists without an authorised message row'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $staffIdentity,
        10
    ),
    'attachment origin survives an active-incident switch'
);
$otherFunctionIdentity = $staffIdentity;
$otherFunctionIdentity['funktion'] = 'S2';
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $otherFunctionIdentity,
        9
    ),
    'attachment origin survives a fixed account-function change'
);
$legacyDutyContext = $staffWriteContext;
$legacyDutyContext['version'] = 1;
$legacyDutyContext['duty_assignment_id'] = 41;
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $legacyDutyContext,
        $staffIdentity,
        9
    ),
    'legacy duty-bound attachment context was not invalidated fail-closed'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $staffIdentity,
        9,
        ['task' => 'Stab_gesprnoti']
    ),
    'attachment continuation accepts a task different from its server context'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $correctionContext,
        $staffIdentity,
        9,
        ['00_lfd' => 74]
    ),
    'attachment continuation accepts a different correction record id'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $staffIdentity,
        9,
        ['attachment_flow' => str_repeat('0', 32)],
        true
    ),
    'attachment continuation accepts another browser flow token'
);
$assertContextRejected(
    static fn () => estab_attachment_origin_context_validate(
        $staffWriteContext,
        $staffIdentity,
        9,
        [],
        true
    ),
    'attachment continuation succeeds without its browser flow token'
);
$assert(
    estab_attachment_merge_message_references(
        'EL0001.PDF;EL0002.jpg;',
        'EL0002.JPG;EL0003.jpeg;../../bad.php;../../bad.pdf;'
    ) === 'EL0001.pdf;EL0002.jpg;EL0003.jpeg;',
    'attachment selection does not preserve existing valid references safely'
);
$assert(
    estab_attachment_canonical_message_references(null) === ''
        && estab_attachment_canonical_message_references('') === ''
        && estab_attachment_canonical_message_references(' ; ; ') === ''
        && estab_attachment_canonical_message_references(
            ' EL0001.PDF ;EL0002.jpeg;; '
        ) === 'EL0001.pdf;EL0002.jpeg;',
    'strict attachment-list canonicalisation changes valid object references'
);
$oneHundredAttachmentReferences = [];
for ($referenceNumber = 1; $referenceNumber <= 100; $referenceNumber++) {
    $oneHundredAttachmentReferences[] = sprintf(
        'EL%04d.pdf',
        $referenceNumber
    );
}
$assert(
    estab_attachment_canonical_message_references(
        implode(';', $oneHundredAttachmentReferences) . ';'
    ) === implode(';', $oneHundredAttachmentReferences) . ';',
    'strict attachment-list canonicalisation rejects its documented 100-object limit'
);
foreach ([
    ['EL0001.pdf;EL0001.pdf;', 'duplicate attachment reference accepted'],
    ['EL0001.PDF;EL0001.pdf;', 'case-variant duplicate attachment reference accepted'],
    ['../EL0001.pdf;', 'attachment path traversal accepted'],
    ['EL0001.php;', 'unsupported attachment extension accepted'],
    ['EL0001;', 'extensionless attachment reference accepted'],
    ["EL0001\0.pdf;", 'control character in attachment reference accepted'],
    [str_repeat('A', 65536), 'oversized attachment list accepted'],
    [array('EL0001.pdf'), 'nested attachment list accepted'],
    [42, 'non-string attachment list accepted'],
    [implode(';', array_merge(
        $oneHundredAttachmentReferences,
        ['EL0101.pdf']
    )), 'more than 100 attachment references accepted'],
] as [$unsafeReferenceList, $unsafeReferenceMessage]) {
    $assertRejected(
        static fn () => estab_attachment_canonical_message_references(
            $unsafeReferenceList
        ),
        $unsafeReferenceMessage
    );
}

$previousUploadLimit = getenv('ESTAB_UPLOAD_MAX_BYTES');
try {
    putenv('ESTAB_UPLOAD_MAX_BYTES');
    $assert(
        estab_attachment_upload_max_bytes() === 20971520,
        'shared upload service default is not 20 MiB'
    );
    putenv('ESTAB_UPLOAD_MAX_BYTES=1048576');
    $assert(
        estab_attachment_upload_max_bytes() === 1048576
            && estab_attachment_upload_limit_label() === '1 MiB',
        'configured upload limit and its label disagree'
    );
    putenv('ESTAB_UPLOAD_MAX_BYTES=999999999');
    $assert(
        estab_attachment_upload_max_bytes() === 52428800
            && estab_attachment_upload_limit_label() === '50 MiB',
        'shared upload service exceeds its audited 50 MiB ceiling'
    );
    putenv('ESTAB_UPLOAD_MAX_BYTES=invalid');
    $assert(
        estab_attachment_upload_max_bytes() === 20971520,
        'malformed upload-limit configuration changes the safe default'
    );
    $assert(
        estab_attachment_upload_limit_label(512) === '512 Byte'
            && estab_attachment_upload_limit_label(1536) === '1,5 KiB'
            && estab_attachment_upload_limit_label(1572864) === '1,5 MiB',
        'shared upload-limit labels are not human-readable binary sizes'
    );
} finally {
    putenv(
        $previousUploadLimit === false
            ? 'ESTAB_UPLOAD_MAX_BYTES'
            : 'ESTAB_UPLOAD_MAX_BYTES=' . $previousUploadLimit
    );
}
$expectedUploadAccept = implode(',', array_map(
    static fn (string $extension): string => '.' . $extension,
    estab_attachment_allowed_extensions()
));
$assert(
    estab_attachment_upload_accept() === $expectedUploadAccept
        && str_contains($expectedUploadAccept, '.jpg')
        && str_contains($expectedUploadAccept, '.jpeg')
        && str_contains($expectedUploadAccept, '.pdf')
        && str_contains($expectedUploadAccept, '.eml')
        && !str_contains($expectedUploadAccept, '.php'),
    'browser accept hint differs from the server attachment allowlist'
);
$assert(
    estab_attachment_upload_ini_bytes('56M') === 58720256
        && estab_attachment_upload_ini_bytes('50m') === 52428800
        && estab_attachment_upload_ini_bytes('1024K') === 1048576
        && estab_attachment_upload_ini_bytes('0') === null
        && estab_attachment_upload_ini_bytes('broken') === null,
    'PHP multipart transport limits are parsed unsafely'
);
$discardedMultipartServer = [
    'REQUEST_METHOD' => 'POST',
    'CONTENT_TYPE' => 'multipart/form-data; boundary=estab',
    'CONTENT_LENGTH' => '58720257',
];
$assert(
    estab_attachment_upload_post_body_exceeded(
        $discardedMultipartServer,
        [],
        [],
        '56M'
    )
        && !estab_attachment_upload_post_body_exceeded(
            $discardedMultipartServer,
            ['task' => 'Stab_schreiben'],
            [],
            '56M'
        )
        && !estab_attachment_upload_post_body_exceeded(
            array_merge($discardedMultipartServer, [
                'CONTENT_LENGTH' => '58720256',
            ]),
            [],
            [],
            '56M'
        )
        && !estab_attachment_upload_post_body_exceeded(
            array_merge($discardedMultipartServer, [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ]),
            [],
            [],
            '56M'
        ),
    'discarded over-post_max_size multipart body is not detected exactly'
);
$uploadFailure = new file_upload();
$uploadFailure->max_file_size = 1048576;
$uploadFailure->failure_code = UPLOAD_ERR_FORM_SIZE;
$safeUploadException = estab_attachment_upload_user_failure(
    $uploadFailure
);
$assert(
    $safeUploadException instanceof EstabAttachmentUploadUserException
        && $safeUploadException->getMessage()
            === 'Die Datei ist größer als die erlaubten 1 MiB.',
    'shared upload service exposes no stable, size-aware user failure'
);
$uploadFailure->failure_code = null;
$fallbackUploadException = estab_attachment_upload_user_failure(
    $uploadFailure,
    'Sichere Ersatzmeldung.'
);
$assert(
    $fallbackUploadException->getMessage() === 'Sichere Ersatzmeldung.',
    'shared upload service drops its safe fallback failure message'
);
$invalidBrowserUploads = [
    [
        ['tmp_name' => '/tmp/not-an-upload', 'name' => 'lage.pdf', 'error' => UPLOAD_ERR_OK],
        ['nested comment'],
        'nested attachment comment accepted',
    ],
    [
        ['tmp_name' => '/tmp/not-an-upload', 'name' => 'lage.pdf', 'error' => UPLOAD_ERR_OK],
        str_repeat('B', 256),
        'oversized attachment comment accepted',
    ],
    [
        ['name' => 'lage.pdf', 'error' => UPLOAD_ERR_OK],
        'Lagebild',
        'incomplete PHP upload structure accepted',
    ],
    [
        ['tmp_name' => '', 'name' => '', 'error' => UPLOAD_ERR_NO_FILE],
        'Lagebild',
        'missing browser file accepted',
    ],
    [
        ['tmp_name' => '/tmp/not-an-upload', 'name' => "lage\0.pdf", 'error' => UPLOAD_ERR_OK],
        'Lagebild',
        'control character in original upload name accepted',
    ],
];
foreach ($invalidBrowserUploads as [
    $invalidBrowserUpload,
    $invalidBrowserComment,
    $invalidBrowserMessage,
]) {
    $uploadRejectedBeforeDatabase = false;
    try {
        estab_attachment_upload_browser_file(
            $invalidBrowserUpload,
            $invalidBrowserComment,
            $staffIdentity,
            9,
            [],
            'tbl_anhang',
            'tbl_protokoll',
            'EL',
            '/tmp',
            'unit-session',
            null
        );
    } catch (EstabAttachmentUploadUserException) {
        $uploadRejectedBeforeDatabase = true;
    }
    $assert($uploadRejectedBeforeDatabase, $invalidBrowserMessage);
}

$assert(estab_attachment_validate_prefix(' el ') === 'EL', 'authority prefix is normalised');
$assertRejected(
    static fn () => estab_attachment_validate_prefix('EL.*'),
    'regular-expression syntax is rejected in authority prefix'
);
$assert(estab_attachment_validate_session_id('abcDEF0123,-_') === 'abcDEF0123,-_', 'safe session id accepted');
$assertRejected(
    static fn () => estab_attachment_validate_session_id("session\nOR 1=1"),
    'unsafe session id rejected'
);
$assert(
    estab_attachment_validate_reservation_name('EL0001', 'EL') === 'EL0001',
    'EL0001-style reservation accepted'
);
$assertRejected(
    static fn () => estab_attachment_validate_reservation_name('EL001', 'EL'),
    'reservation requires at least four sequence digits'
);
$assertRejected(
    static fn () => estab_attachment_validate_reservation_name('XX0001', 'EL'),
    'reservation is bound to configured authority prefix'
);
$assertRejected(
    static fn () => estab_attachment_validate_reservation_name('../EL0001', 'EL'),
    'reservation path traversal rejected'
);

$assert(estab_attachment_next_name('EL', 0) === 'EL0001', 'empty sequence begins at EL0001');
$assert(estab_attachment_next_name('EL', 41) === 'EL0042', 'sequence is zero padded');
$assert(estab_attachment_next_name('EL', 9999) === 'EL10000', 'sequence grows beyond four digits');
$assertRejected(
    static fn () => estab_attachment_next_name('EL', -1),
    'negative sequence rejected'
);
$assertRejected(
    static fn () => estab_attachment_next_name('EL', 0, 3),
    'width below EL0001 format rejected'
);
$assertRejected(
    static fn () => estab_attachment_next_name('EL', PHP_INT_MAX),
    'integer sequence overflow rejected'
);

$assert(
    estab_attachment_parse_tactical_time('221530JUL2026') === '2026-07-22 15:30:00',
    'English tactical timestamp parsed'
);
$assert(
    estab_attachment_parse_tactical_time('312359DEZ2026') === '2026-12-31 23:59:00',
    'German tactical month parsed'
);
$assert(
    estab_attachment_parse_tactical_time('290130FEB2024') === '2024-02-29 01:30:00',
    'valid leap day parsed'
);
$assert(
    estab_attachment_parse_tactical_time('311259DEC1999') === '1999-12-31 12:59:00',
    'historical SQL-range timestamp remains supported'
);
$assert(estab_attachment_parse_tactical_time('290130FEB2023') === null, 'invalid calendar day rejected');
$assert(estab_attachment_parse_tactical_time('312460DEZ2026') === null, 'invalid tactical time rejected');
$assert(estab_attachment_validate_sql_datetime('2026-07-22 15:30:00'), 'strict SQL datetime accepted');
$assert(!estab_attachment_validate_sql_datetime('2026-02-29 15:30:00'), 'normalised invalid SQL date rejected');
$assert(!estab_attachment_validate_sql_datetime('0999-12-31 23:59:00'), 'date below SQL range rejected');

$metadata = estab_attachment_validate_metadata([
    'filename' => '/srv/attachments/EL0001.PDF',
    'org_filename' => 'C:\\fakepath\\Lageplan.PDF',
    'comment' => '  Lage <Nord>  ',
    'kuerzel' => 'attacker-controlled',
    'time' => '2026-07-22 15:30:00',
    'md5hash' => 'ABCDEF0123456789ABCDEF0123456789',
    'sha256' => str_repeat('A1', 32),
    'size' => 1234,
], 'EL0001', ' ADA ');
$assert($metadata['filename'] === 'EL0001', 'stored base name is bound to reservation');
$assert($metadata['fileext'] === 'pdf', 'stored extension normalised');
$assert($metadata['org_filename'] === 'Lageplan.PDF', 'browser path removed from original filename');
$assert($metadata['comment'] === 'Lage <Nord>', 'comment trimmed without corrupting text');
$assert($metadata['kuerzel'] === 'ada', 'session code overrides submitted metadata');
$assert($metadata['md5hash'] === 'abcdef0123456789abcdef0123456789', 'digest normalised');
$assert($metadata['sha256'] === str_repeat('a1', 32), 'SHA-256 normalised');
$assert($metadata['size'] === 1234, 'byte length retained');
$assert(estab_attachment_extension_is_allowed('PDF'), 'supported extension accepted case-insensitively');
$assert(estab_attachment_extension_is_allowed('JPEG'), 'JPEG alias accepted case-insensitively');
$assert(estab_attachment_extension_is_allowed('TIFF'), 'TIFF alias matches the delivery allowlist');
$assert(!estab_attachment_extension_is_allowed('php'), 'executable extension rejected');

$jpegMetadata = estab_attachment_validate_metadata([
    'filename' => '/srv/attachments/EL0001.JPEG',
    'org_filename' => 'C:\\fakepath\\Lagebild.JPEG',
    'comment' => 'Lagebild',
    'time' => '2026-07-22 15:30:00',
    'md5hash' => 'abcdef0123456789abcdef0123456789',
    'sha256' => str_repeat('ab', 32),
    'size' => 2048,
], 'EL0001', 'ada');
$assert($jpegMetadata['fileext'] === 'jpeg', 'JPEG alias is stored in canonical lowercase');
$assert($jpegMetadata['org_filename'] === 'Lagebild.JPEG', 'JPEG original name is preserved');

$invalidMetadata = [
    'filename' => 'EL0001.pdf',
    'org_filename' => 'lage.pdf',
    'comment' => 'Lage',
    'time' => '2026-07-22 15:30:00',
    'md5hash' => 'abcdef0123456789abcdef0123456789',
    'sha256' => str_repeat('cd', 32),
    'size' => 42,
];
$sixCharacterCode = estab_attachment_validate_metadata($invalidMetadata, 'EL0001', 'abc123');
$assert($sixCharacterCode['kuerzel'] === 'abc123', 'six-character login code accepted for attachments');
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['filename' => 'EL0002.pdf']),
        'EL0001',
        'ada'
    ),
    'stored filename cannot switch reservations'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['filename' => 'EL0001.php']),
        'EL0001',
        'ada'
    ),
    'unsupported stored extension rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['org_filename' => "lage\0.pdf"]),
        'EL0001',
        'ada'
    ),
    'control characters in original filename rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['comment' => "Lage\nNord"]),
        'EL0001',
        'ada'
    ),
    'control characters in comment rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata($invalidMetadata, 'EL0001', 'toolong'),
    'session code exceeding schema rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['time' => '2026-02-29 15:30:00']),
        'EL0001',
        'ada'
    ),
    'invalid metadata timestamp rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['md5hash' => 'not-a-digest']),
        'EL0001',
        'ada'
    ),
    'invalid metadata digest rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['sha256' => 'not-a-sha256']),
        'EL0001',
        'ada'
    ),
    'invalid metadata SHA-256 rejected'
);
$assertRejected(
    static fn () => estab_attachment_validate_metadata(
        array_replace($invalidMetadata, ['size' => '42']),
        'EL0001',
        'ada'
    ),
    'non-integer metadata byte length rejected'
);

$escaped = estab_attachment_html('<a href="x">\'&');
$assert($escaped === '&lt;a href=&quot;x&quot;&gt;&#039;&amp;', 'HTML text and attributes escaped');
$assert(estab_attachment_table('nv_anhang') === '`nv_anhang`', 'configured table identifier quoted');
$assertRejected(
    static fn () => estab_attachment_table('nv_anhang; DROP TABLE nv_anhang'),
    'SQL in table identifier rejected'
);
$assert(estab_attachment_database_error_is_retryable(1062), 'duplicate-key conflict is retryable');
$assert(estab_attachment_database_error_is_retryable(1205), 'lock timeout is retryable');
$assert(estab_attachment_database_error_is_retryable(1213), 'deadlock is retryable');
$assert(!estab_attachment_database_error_is_retryable(1048), 'invalid data error is not retried');

$integrityFixtureRoot = sys_get_temp_dir()
    . '/estab-attachment-download-' . bin2hex(random_bytes(8));
$integrityFixturePath = $integrityFixtureRoot . '/EL0001.txt';
$integrityOriginalPath = $integrityFixturePath . '.original';
$integrityPayload = "verified attachment download fixture\n";
$integrityStream = null;
$legacyStream = null;
if (!mkdir($integrityFixtureRoot, 0700, true)) {
    throw new RuntimeException('Could not create attachment download fixture');
}
try {
    if (
        file_put_contents($integrityFixturePath, $integrityPayload)
            !== strlen($integrityPayload)
    ) {
        throw new RuntimeException('Could not write attachment download fixture');
    }
    $integrityRow = [
        'integrity_required' => 1,
        'ingest_sha256' => hash('sha256', $integrityPayload),
        'ingest_size' => strlen($integrityPayload),
        'integrity_captured_at' => '2026-07-30 12:00:00.000001',
    ];
    $opened = estab_attachment_integrity_open_snapshot(
        $integrityRow,
        $integrityFixtureRoot,
        'EL0001.txt'
    );
    $integrityStream = $opened['stream'];
    $assert(
        is_resource($integrityStream)
            && $opened['state'] === 'verified'
            && $opened['content_size'] === strlen($integrityPayload)
            && $opened['sha256'] === hash('sha256', $integrityPayload),
        'verified attachment snapshot has invalid integrity metadata'
    );

    if (
        !rename($integrityFixturePath, $integrityOriginalPath)
        || file_put_contents(
            $integrityFixturePath,
            str_repeat('X', strlen($integrityPayload))
        ) !== strlen($integrityPayload)
    ) {
        throw new RuntimeException('Could not replace attachment pathname fixture');
    }
    $assert(
        stream_get_contents($integrityStream) === $integrityPayload,
        'verified download handle followed a later pathname replacement'
    );
    fclose($integrityStream);
    $integrityStream = null;
    unlink($integrityFixturePath);
    rename($integrityOriginalPath, $integrityFixturePath);

    $tamperedPayload = str_repeat('T', strlen($integrityPayload));
    file_put_contents($integrityFixturePath, $tamperedPayload);
    $tamperRejected = false;
    try {
        $unexpected = estab_attachment_integrity_open_snapshot(
            $integrityRow,
            $integrityFixtureRoot,
            'EL0001.txt'
        );
        fclose($unexpected['stream']);
    } catch (EstabAttachmentIntegrityException) {
        $tamperRejected = true;
    }
    $assert(
        $tamperRejected,
        'same-size attachment tampering produced a verified download snapshot'
    );

    file_put_contents($integrityFixturePath, $integrityPayload);
    $legacy = estab_attachment_integrity_open_snapshot(
        [
            'integrity_required' => 0,
            'ingest_sha256' => null,
            'ingest_size' => null,
            'integrity_captured_at' => null,
        ],
        $integrityFixtureRoot,
        'EL0001.txt'
    );
    $legacyStream = $legacy['stream'];
    $assert(
        $legacy['state'] === 'legacy_unverifiable'
            && $legacy['statement']
                === 'Integrität beim Eingang nicht belegbar'
            && $legacy['sha256'] === null
            && $legacy['content_size'] === strlen($integrityPayload)
            && stream_get_contents($legacyStream) === $integrityPayload,
        'legacy download snapshot invented an ingest proof or changed bytes'
    );
} finally {
    if (is_resource($integrityStream)) {
        fclose($integrityStream);
    }
    if (is_resource($legacyStream)) {
        fclose($legacyStream);
    }
    if (is_file($integrityFixturePath)) {
        unlink($integrityFixturePath);
    }
    if (is_file($integrityOriginalPath)) {
        unlink($integrityOriginalPath);
    }
    rmdir($integrityFixtureRoot);
}

$attachmentSource = file_get_contents(__DIR__ . '/../../app/attachment.php');
$uploadServiceSource = file_get_contents(
    __DIR__ . '/../../app/attachment_upload.php'
);
$integritySource = file_get_contents(
    __DIR__ . '/../../app/attachment_integrity.php'
);
$downloadSource = file_get_contents(__DIR__ . '/../../4fach/download.php');
$controllerSource = file_get_contents(__DIR__ . '/../../4fach/anhang.php');
$mainControllerSource = file_get_contents(
    __DIR__ . '/../../4fach/mainindex.php'
);
$messageRepositorySource = file_get_contents(
    __DIR__ . '/../../app/message_repository.php'
);
$messageHandlerSource = file_get_contents(
    __DIR__ . '/../../4fach/data_hndl.php'
);
$messageFormSource = file_get_contents(__DIR__ . '/../../4fach/4fachform.php');
$schemaSource = file_get_contents(__DIR__ . '/../../docker/db/init/10-schema.sql');
$verifySource = file_get_contents(__DIR__ . '/../../docker/db/verify.sql');
$integrityMigration = file_get_contents(
    __DIR__ . '/../../docker/db/migrations/95-attachment-ingest-integrity.sql'
);
$assert(
    is_string($attachmentSource)
        && is_string($uploadServiceSource)
        && is_string($integritySource)
        && is_string($downloadSource)
        && is_string($controllerSource)
        && is_string($mainControllerSource)
        && is_string($messageFormSource),
    'attachment and message-form sources readable'
);
$assert(
    substr_count(
        $controllerSource,
        'require_once __DIR__ . "/upload_class.php";'
    ) === 1
        && substr_count($controllerSource, 'upload_class.php') === 1
        && preg_match(
            '/\b(?:include|include_once|require)\s*'
                . '(?:\(\s*)?["\'][^"\']*upload_class\.php/s',
            $controllerSource
        ) !== 1,
    'attachment controller can load the shared upload class twice or relative to the process working directory'
);
$assert(
    preg_match(
        '/function estab_attachment_find_for_incident\s*\(.*?SELECT .*?'
            . '`integrity_required`.*?`ingest_sha256`.*?`ingest_size`.*?'
            . '`integrity_captured_at`.*?WHERE `filename` = \?/s',
        $attachmentSource
    ) === 1,
    'attachment lookup omits immutable ingest-integrity evidence'
);
$assert(
    str_contains(
        $integritySource,
        'function estab_attachment_integrity_open_snapshot('
    )
        && str_contains($integritySource, '$snapshot = tmpfile();')
        && str_contains(
            $integritySource,
            'stream_copy_to_stream($source, $snapshot)'
        )
        && str_contains(
            $integritySource,
            'estab_attachment_integrity_measure_stream($snapshot)'
        )
        && str_contains($downloadSource, '$attachmentIntegrity =')
        && str_contains(
            $downloadSource,
            'estab_attachment_integrity_open_snapshot('
        )
        && str_contains(
            $downloadSource,
            "X-eStab-Attachment-Integrity: "
        )
        && str_contains(
            $downloadSource,
            "X-eStab-Attachment-SHA256: "
        ),
    'attachment download does not stream the exact verified private snapshot'
);
$assert(
    is_string($schemaSource)
        && is_string($verifySource)
        && is_string($integrityMigration),
    'container schema checks readable'
);
$assert(
    preg_match('/(?:mysql_query|query_table(?:_iu)?|->query\s*\()/i', $attachmentSource . $controllerSource) !== 1,
    'active attachment path contains no direct SQL execution'
);
$assert(
    substr_count($attachmentSource, 'estab_attachment_statement_result(') >= 3
        && substr_count($attachmentSource, 'estab_attachment_statement_row(') >= 4
        && substr_count($attachmentSource, '->get_result()') === 1
        && substr_count($attachmentSource, '->fetch_assoc()') === 1,
    'all attachment SELECT results are validated before they are consumed'
);
$assert(
    str_contains($attachmentSource, '$statement->errno ?: $connection->errno'),
    'deferred result errors preserve their retryable MariaDB error code'
);
$assert(str_contains($attachmentSource, 'begin_transaction()'), 'reservation starts a transaction');
$assert(substr_count($attachmentSource, 'FOR UPDATE') >= 2, 'reservation candidates are locked');
$attachmentFindStart = strpos(
    $attachmentSource,
    'function estab_attachment_find('
);
$attachmentFindEnd = strpos(
    $attachmentSource,
    '/** Prepared audit insert',
    $attachmentFindStart === false ? 0 : $attachmentFindStart
);
$attachmentFindWrapper = (
    $attachmentFindStart !== false
    && $attachmentFindEnd !== false
    && $attachmentFindEnd > $attachmentFindStart
) ? substr(
    $attachmentSource,
    $attachmentFindStart,
    $attachmentFindEnd - $attachmentFindStart
) : '';
$assert(
    str_contains(
        $attachmentSource,
        ". (\$forUpdate ? ' FOR UPDATE' : '')"
    )
        && $attachmentFindWrapper !== ''
        && strpos(
            $attachmentFindWrapper,
            'estab_attachment_validate_reservation_name($filename)'
        ) < strpos(
            $attachmentFindWrapper,
            'estab_incident_require_active($connection, true)'
        ),
    'attachment compatibility lookup does not validate first or honor its row-lock flag'
);
$assert(
    substr_count(
        $attachmentSource,
        'estab_incident_lock_command_post_for_write($connection, $incident);'
    ) >= 2,
    'reservation or upload accepts an active incident without a command-post name'
);
$assert(
    str_contains($attachmentSource, 'WHERE `filename` = ? AND `status` = 8 AND `id` = ?'),
    'claim requires exact active reservation and owner'
);
$assert(
    str_contains($attachmentSource, 'WHERE `filename` = ? AND `status` = 2 AND `id` = ?'),
    'finalisation requires exact claimed filename and owner'
);
$storeUploadStart = strpos(
    $attachmentSource,
    'function estab_attachment_store_upload('
);
$storeUploadEnd = strpos(
    $attachmentSource,
    'function estab_attachment_list_for_incident(',
    $storeUploadStart === false ? 0 : $storeUploadStart
);
$storeUploadSource = (
    $storeUploadStart !== false
    && $storeUploadEnd !== false
    && $storeUploadEnd > $storeUploadStart
) ? substr(
    $attachmentSource,
    $storeUploadStart,
    $storeUploadEnd - $storeUploadStart
) : '';
$reserveStart = strpos(
    $attachmentSource,
    'function estab_attachment_reserve('
);
$reserveEnd = strpos(
    $attachmentSource,
    'function estab_attachment_owned_reservation_incident_id(',
    $reserveStart === false ? 0 : $reserveStart
);
$reserveSource = (
    $reserveStart !== false
    && $reserveEnd !== false
    && $reserveEnd > $reserveStart
) ? substr(
    $attachmentSource,
    $reserveStart,
    $reserveEnd - $reserveStart
) : '';
$cleanupStateStart = strpos(
    $attachmentSource,
    'function estab_attachment_reservation_cleanup_state('
);
$cleanupStateEnd = strpos(
    $attachmentSource,
    'function estab_attachment_claim(',
    $cleanupStateStart === false ? 0 : $cleanupStateStart
);
$cleanupStateSource = (
    $cleanupStateStart !== false
    && $cleanupStateEnd !== false
    && $cleanupStateEnd > $cleanupStateStart
) ? substr(
    $attachmentSource,
    $cleanupStateStart,
    $cleanupStateEnd - $cleanupStateStart
) : '';
$assert(
    $storeUploadSource !== ''
        && !str_contains(
            $storeUploadSource,
            'SAVEPOINT estab_attachment_before_claim'
        )
        && str_contains(
            $storeUploadSource,
            'Could not commit atomic upload'
        )
        && str_contains(
            $uploadServiceSource,
            'estab_attachment_store_upload('
        )
        && str_contains(
            $controllerSource,
            'estab_attachment_upload_browser_file ('
        )
        && str_contains(
            $mainControllerSource,
            'estab_attachment_upload_browser_file ('
        )
        && strpos(
            $uploadServiceSource,
            'estab_attachment_integrity_measure_file($fullPath)'
        ) < strpos(
            $uploadServiceSource,
            'estab_attachment_store_upload('
        )
        && str_contains(
            $attachmentSource,
            'function estab_attachment_owned_reservation_incident_id('
        )
        && str_contains(
            $uploadServiceSource,
            'estab_attachment_owned_reservation_incident_id('
        )
        && str_contains(
            $uploadServiceSource,
            'estab_attachment_prepare_staged_extension('
        )
        && str_contains(
            $attachmentSource,
            'function estab_attachment_reservation_cleanup_state('
        )
        && str_contains(
            $attachmentSource,
            '$expectedIncidentId !== $incidentId'
        ),
    'browser upload does not stage file I/O before its short finalisation transaction'
);
$assert(
    $reserveSource !== ''
        && $storeUploadSource !== ''
        && str_contains(
            $reserveSource,
            'estab_permission_context_matches_incident($incident)'
        )
        && str_contains(
            $storeUploadSource,
            'estab_permission_context_matches_incident($incident)'
        )
        && strpos(
            $reserveSource,
            'estab_incident_require_active($connection, true)'
        ) < strpos(
            $reserveSource,
            'estab_permission_context_matches_incident($incident)'
        )
        && strpos(
            $reserveSource,
            'estab_permission_context_matches_incident($incident)'
        ) < strpos(
            $reserveSource,
            'estab_attachment_require_operational_identity('
        )
        && strpos(
            $storeUploadSource,
            'estab_incident_require_active($connection, true)'
        ) < strpos(
            $storeUploadSource,
            'estab_permission_context_matches_incident($incident)'
        )
        && strpos(
            $storeUploadSource,
            'estab_permission_context_matches_incident($incident)'
        ) < strpos(
            $storeUploadSource,
            'estab_attachment_require_operational_identity('
        ),
    'attachment reserve/store transactions can survive a LOOSE-to-STRICT or incident revision race'
);
$assert(
    $cleanupStateSource !== ''
        && str_contains($cleanupStateSource, '$connection->begin_transaction()')
        && str_contains($cleanupStateSource, 'LIMIT 1 FOR UPDATE')
        && str_contains(
            $cleanupStateSource,
            'SET `status` = 2 WHERE `filename` = ? AND `id` = ?'
        )
        && strpos(
            $cleanupStateSource,
            'SET `status` = 2 WHERE `filename` = ? AND `id` = ?'
        ) < strpos($cleanupStateSource, '$connection->commit()'),
    'staged-file cleanup does not claim its path before allowing reuse'
);
$assert(
    str_contains(
        $attachmentSource,
        'function estab_attachment_direct_action_replay_result('
    )
        && str_contains(
            $mainControllerSource,
            '$messageAttachmentReplayWithoutFile ='
        )
        && str_contains(
            $mainControllerSource,
            'estab_attachment_direct_action_replay_result ('
        )
        && str_contains(
            $mainControllerSource,
            '|| is_array ($messageAttachmentReplayWithoutFile);'
        )
        && str_contains(
            $mainControllerSource,
            '$messageAttachmentReplayWithoutFile ["mode"] ?? ""'
        )
        && str_contains(
            $mainControllerSource,
            'function estab_message_attachment_checkpoint_pending_action ('
        )
        && str_contains($mainControllerSource, 'session_write_close ()')
        && str_contains($mainControllerSource, 'session_start ()')
        && strrpos(
            $mainControllerSource,
            'estab_message_attachment_checkpoint_pending_action ('
        ) > strpos(
            $mainControllerSource,
            'estab_attachment_direct_action_note_pending_submit ('
        )
        && str_contains(
            $mainControllerSource,
            'eStab conversation-note attachment token completion failed: '
        )
        && str_contains(
            $mainControllerSource,
            'function estab_message_attachment_render_conversation_stage ('
        )
        && substr_count(
            $mainControllerSource,
            'estab_message_attachment_render_conversation_stage ('
        ) === 3
        && str_contains($mainControllerSource, '"conversation-stage"')
        && str_contains(
            $mainControllerSource,
            'Prüfen Sie die Meldungsliste und senden Sie diesen '
        ),
    'a multipart replay without its file can bypass attachment idempotency'
);
$assert(
    str_contains(
        $mainControllerSource,
        'if (!$messageAttachmentRemoveRequested) {'
    )
        && str_contains(
            $mainControllerSource,
            '$remainingScopeConnection = estab_message_connect ($conf_4f_db);'
        )
        && str_contains(
            $mainControllerSource,
            '$attachmentDraft ["12_anhang"],'
        )
        && str_contains(
            $mainControllerSource,
            'resulting list instead of requiring the broken token itself'
        ),
    'an unavailable attachment reference cannot be removed from an authorised draft'
);
$assert(
    str_contains(
        $mainControllerSource,
        '$messageAttachmentFinalSubmitRequested'
    )
        && str_contains(
            $mainControllerSource,
            'estab_message_committed_action_id ('
        )
        && str_contains(
            $messageRepositorySource,
            'function estab_message_action_lock('
        )
        && str_contains(
            $messageRepositorySource,
            'function estab_message_committed_action_id('
        )
        && str_contains(
            $messageRepositorySource,
            "'$.request_action.token_sha256'"
        )
        && substr_count(
            $messageHandlerSource,
            'estab_message_action_evidence_snapshot ('
        ) === 4
        && str_contains(
            $messageHandlerSource,
            'estab_message_action_lock ('
        ),
    'ordinary no-file message submits lack durable exactly-once evidence'
);
$assert(
    str_contains($uploadServiceSource, '$releaseReservation = false;')
        && str_contains(
            $uploadServiceSource,
            '@unlink($cleanupPath);'
        )
        && str_contains(
            $uploadServiceSource,
            '$releaseReservation = !is_file($cleanupPath);'
        )
        && str_contains(
            $uploadServiceSource,
            'reservation retained: '
        )
        && strpos(
            $uploadServiceSource,
            '&& $releaseReservation'
        ) < strpos(
            $uploadServiceSource,
            'estab_attachment_release_for_incident('
        ),
    'failed staged-file cleanup can release and later reuse an unsafe NAS path'
);
$assert(
    str_contains(
        $uploadServiceSource,
        'estab_attachment_integrity_measure_file($fullPath)'
    )
        && str_contains($attachmentSource, '`ingest_sha256` = ?')
        && str_contains($attachmentSource, '`ingest_size` = ?')
        && str_contains(
            $attachmentSource,
            '`integrity_captured_at` = NOW(6)'
        )
        && str_contains(
            (string) $integrityMigration,
            'Final attachment integrity evidence is immutable'
        ),
    'upload finalisation lacks immutable SHA-256/size ingest evidence'
);
$assert(
    !str_contains($attachmentSource, 'Could not release failed upload')
        && str_contains(
            $attachmentSource,
            'caller removes staged bytes first'
        )
        && !str_contains(
            $uploadServiceSource,
            '$uploader->claim_reservation'
        ),
    'failed upload can leave a claimed reservation across an incident switch'
);
$assert(
    str_contains(
        $uploadServiceSource,
        '&& $releaseReservation'
    )
        && str_contains(
            $uploadServiceSource,
            'estab_attachment_release_for_incident('
        )
        && str_contains(
            $attachmentSource,
            'function estab_attachment_release_for_incident('
        )
        && str_contains(
            $uploadServiceSource,
            'eStab attachment reservation cleanup failed: '
        )
        && str_contains(
            $uploadServiceSource,
            '$cleanupIncidentId'
        ),
    'shared upload service releases uploads rejected before finalisation'
);
$assert(
    str_contains($schemaSource, 'UNIQUE KEY `uq_anhang_filename` (`filename`)'),
    'schema provides unique filename race guard'
);
$assert(
    str_contains($attachmentSource, "hash_equals(\$filename, \$row['filename'])"),
    'case-insensitive database collation can authorize another attachment path'
);
$assert(
    str_contains($schemaSource, '`kuerzel` VARCHAR(6) NULL DEFAULT NULL')
        && str_contains($schemaSource, 'MODIFY COLUMN `kuerzel` VARCHAR(6) NULL DEFAULT NULL'),
    'fresh and existing attachment schemas support six-character login codes'
);
$assert(
    str_contains($verifySource, 'seq_in_index = 1')
        && substr_count($verifySource, "index_name = 'uq_anhang_filename'") >= 2,
    'schema verification requires an exact single-column filename index'
);
$assert(
    str_contains($controllerSource, 'estab_csrf_field ()')
        && str_contains($controllerSource, 'estab_csrf_require_post ($_SERVER, $_POST)')
        && str_contains(
            $controllerSource,
            'method=\\"post\\"'
        )
        && str_contains($controllerSource, '$attachmentGetActionRequested')
        && str_contains($controllerSource, '405,')
        && str_contains($controllerSource, 'estab_session_ui_abort (')
        && !str_contains($controllerSource, 'isset ($_GET ["ah_upload_x"])')
        && !str_contains($controllerSource, 'isset ($_GET["ah_auswahl_x"])')
        && !str_contains(
            $controllerSource,
            'readrecord_from_db((string) ($_POST'
        )
        && str_contains($controllerSource, 'session_status () === PHP_SESSION_NONE'),
    'attachment menu actions are not scalar-safe POSTs with enforced CSRF'
);
$csrfBoundary = strpos(
    $controllerSource,
    'estab_csrf_require_post ($_SERVER, $_POST);'
);
$contextBoundary = strpos(
    $controllerSource,
    'estab_attachment_origin_context_find ('
);
$contextCatch = [];
$contextCatchFound = preg_match(
    '/catch \(EstabAttachmentContextException \$exception\) \{'
        . '(?<body>.*?)\n\} catch \(EstabNoActiveIncidentException\)/s',
    $controllerSource,
    $contextCatch
) === 1;
$assert(
    is_int($csrfBoundary)
        && is_int($contextBoundary)
        && $csrfBoundary < $contextBoundary
        && $contextCatchFound
        && !str_contains(
            (string) ($contextCatch['body'] ?? ''),
            'estab_attachment_origin_context_clear'
        ),
    'attachment context is read or deleted before CSRF/token rejection'
);
$assert(
    str_contains($controllerSource, '$_SESSION ["anhang_menue"] ?? null')
        && str_contains($controllerSource, '$attachmentMenuState !== 100')
        && str_contains($controllerSource, '$attachmentMenuState !== 110')
        && str_contains($controllerSource, 'switch ($attachmentMenuState)')
        && !str_contains($controllerSource, 'switch ($_SESSION["anhang_menue"])'),
    'direct attachment entry normalises missing or malformed menu state'
);
$assert(
    str_contains(
        $mainControllerSource,
        'estab_attachment_origin_context_create ('
    )
        && str_contains(
            $mainControllerSource,
            'estab_attachment_origin_draft_from_request ('
        )
        && str_contains(
            $mainControllerSource,
            'estab_attachment_origin_flow_store ('
        )
        && !str_contains(
            $mainControllerSource,
            'estab_attachment_origin_context_clear ($_SESSION)'
        )
        && str_contains(
            $attachmentSource,
            'function estab_attachment_origin_context_validate('
        )
        && str_contains(
            $attachmentSource,
            'function estab_attachment_origin_flow_store('
        )
        && str_contains(
            $attachmentSource,
            'function estab_attachment_origin_context_find('
        )
        && str_contains($attachmentSource, 'random_bytes(16)')
        && str_contains(
            $controllerSource,
            'estab_attachment_origin_context_validate ('
        )
        && str_contains(
            $controllerSource,
            'name=\\"attachment_flow\\"'
        )
        && str_contains(
            $controllerSource,
            '$attachmentInternalRequest ? 100 : 110'
        )
        && !str_contains(
            $controllerSource,
            '&& !isset ($_POST ["anhang_plus_x"])'
        )
        && !str_contains(
            $controllerSource,
            '$_SESSION ["anhang_message_context"] = true'
        )
        && preg_match(
            '/(?<![A-Za-z0-9_])store_formdata\s*\(\$attachmentOriginContext\)/',
            $controllerSource
        ) !== 1
        && preg_match_all(
            '/\$attachmentMessageContext\s*&&\s*'
                . '\$attachment(?:Staff|Telecommunications)Origin\s*&&/s',
            $controllerSource
        ) === 2
        && str_contains(
            $controllerSource,
            'Zum Übernehmen von Anhängen öffnen Sie bitte zuerst einen Nachrichtenvordruck.'
        ),
    'attachment selection is not bound to a server-owned, per-browser message origin'
);
$draftCatchStart = strrpos(
    $mainControllerSource,
    '} catch (EstabAttachmentDraftException $exception) {'
);
$contextCatchStart = strrpos(
    $mainControllerSource,
    '} catch (EstabAttachmentContextException $exception) {'
);
$assert(
    is_int($draftCatchStart)
        && is_int($contextCatchStart)
        && $draftCatchStart < $contextCatchStart
        && str_contains($mainControllerSource, 'http_response_code (422);')
        && str_contains(
            $mainControllerSource,
            '$formdata ["estab_route_error"] ='
        )
        && str_contains(
            $mainControllerSource,
            'estab_attachment_origin_draft_form_data ('
        )
        && str_contains(
            $mainControllerSource,
            '$form = new nachrichten4fach ('
        )
        && str_contains(
            $mainControllerSource,
            'bleiben in diesem Formular erhalten.'
        ),
    'draft-limit failure reaches a generic 403/500 instead of the preserved message form'
);
$draftCatchBody = (
    is_int($draftCatchStart)
    && is_int($contextCatchStart)
    && $contextCatchStart > $draftCatchStart
) ? substr(
    $mainControllerSource,
    $draftCatchStart,
    $contextCatchStart - $draftCatchStart
) : '';
$assert(
    str_contains(
        $draftCatchBody,
        'array ("Stab_schreiben", "Stab_korrigieren", "Stab_gesprnoti")'
    )
        && str_contains(
            $draftCatchBody,
            '$formdata ["13_abseinheit"] = $activeCommandPostName;'
        )
        && str_contains(
            $draftCatchBody,
            '$workflowSelectedIdentity ["kuerzel"]'
        )
        && str_contains(
            $draftCatchBody,
            '$workflowSelectedIdentity ["funktion"]'
        )
        && str_contains(
            $draftCatchBody,
            '$formdata ["01_zeichen"] = $formdata ["14_zeichen"];'
        )
        && strpos(
            $draftCatchBody,
            '$formdata ["13_abseinheit"] = $activeCommandPostName;'
        ) < strpos(
            $draftCatchBody,
            '$form = new nachrichten4fach ('
        ),
    'rejected staff attachment drafts can display browser-owned identity metadata'
);
$atomicStoreStart = strpos(
    $attachmentSource,
    'function estab_attachment_origin_flow_store('
);
$atomicStoreEnd = strpos(
    $attachmentSource,
    '/** Bind the unsaved form fields',
    is_int($atomicStoreStart) ? $atomicStoreStart : 0
);
$atomicStoreSource = is_int($atomicStoreStart) && is_int($atomicStoreEnd)
    ? substr(
        $attachmentSource,
        $atomicStoreStart,
        $atomicStoreEnd - $atomicStoreStart
    )
    : '';
$atomicReleasePosition = strpos(
    $atomicStoreSource,
    '$releaseEvictedFlow($evictedContext);'
);
$atomicSessionCommitPosition = strpos(
    $atomicStoreSource,
    '$session[\'anhang_origin_contexts\'] = $contexts;'
);
$atomicCandidateValidationPosition = strrpos(
    $atomicStoreSource,
    'estab_attachment_origin_drafts_bytes($drafts, $contexts);'
);
$assert(
    $atomicStoreSource !== ''
        && substr_count(
            $atomicStoreSource,
            'estab_attachment_origin_drafts_bytes('
        ) >= 2
        && is_int($atomicReleasePosition)
        && is_int($atomicSessionCommitPosition)
        && is_int($atomicCandidateValidationPosition)
        && $atomicCandidateValidationPosition < $atomicReleasePosition
        && $atomicReleasePosition < $atomicSessionCommitPosition,
    'context/draft maps or reservation cleanup run before the full candidate validation'
);
$assert(
    str_contains(
        $controllerSource,
        'estab_message_fetch_for_incident_by_id ('
    )
        && str_contains(
            $controllerSource,
            '"staff-correction"'
        )
        && str_contains(
            $controllerSource,
            'estab_message_object_allowed ('
        )
        && str_contains(
            $controllerSource,
            '$attachmentPageIdentity'
        )
        && str_contains(
            $controllerSource,
            '$attachmentOriginContext ["record_id"]'
        ),
    'correction attachment continuation does not reauthorise its exact record'
);
$assert(
    substr_count(
        $controllerSource,
        '$attachmentOriginTask = $attachmentMessageContext'
    ) === 1
        && preg_match_all(
            '/new nachrichten4fach\s*\(\s*\$formdata,\s*'
                . '\$attachmentOriginTask,\s*""\s*\)/s',
            $controllerSource
        ) === 2
        && !str_contains(
            $controllerSource,
            'new nachrichten4fach ($formdata, "Stab_schreiben", "")'
        )
        && !str_contains(
            $controllerSource,
            'new nachrichten4fach ($formdata, "FM-Eingang_Anhang", "")'
        ),
    'attachment return changes the exact originating workflow task'
);
$assert(
    str_contains(
        $controllerSource,
        '$attachmentStaffOrigin = in_array ('
    )
        && str_contains(
            $controllerSource,
            'array ("Stab_schreiben", "Stab_korrigieren", "Stab_gesprnoti")'
        )
        && str_contains(
            $controllerSource,
            '$attachmentTelecommunicationsOrigin = in_array ('
        )
        && str_contains(
            $controllerSource,
            'array ("FM-Eingang", "FM-Eingang_Anhang")'
        )
        && preg_match_all(
            '/\$attachmentMessageContext\s*&&\s*'
                . '\$attachment(?:Staff|Telecommunications)Origin\s*&&/s',
            $controllerSource
        ) === 2
        && str_contains(
            $controllerSource,
            '$correctionData = $originMessage;'
        )
        && str_contains(
            $controllerSource,
            '"17_vermerke"'
        )
        && substr_count(
            $controllerSource,
            'estab_attachment_merge_message_references ('
        ) === 2,
    'attachment return dispatches by account role instead of its signed origin task, or loses correction data'
);
$assert(
    str_contains($controllerSource, 'Die Anhangübersicht wurde direkt geöffnet.')
        && str_contains(
            $controllerSource,
            'anhang_menue ($attachmentContextNotice, $attachmentOriginContext)'
        )
        && str_contains(
            $controllerSource,
            'Hier können Sie vorhandene Anhänge ansehen oder neue Dateien hochladen.'
        ),
    'direct attachment entry renders a user-oriented standalone overview'
);
$archiveIncidentPosition = strpos(
    $controllerSource,
    '$workflowIncidentId = estab_incident_positive_id ('
);
$archiveReturnFormPosition = strpos(
    $controllerSource,
    '$form = new nachrichten4fach ('
);
$assert(
    is_int($archiveIncidentPosition)
        && is_int($archiveReturnFormPosition)
        && $archiveIncidentPosition < $archiveReturnFormPosition,
    'archive selection return cannot issue an incident-bound direct action token'
);
$assert(
    !str_contains($controllerSource, 'case 999:')
        && !str_contains($controllerSource, 'if ($_POST["absenden_x"])'),
    'obsolete attachment state and unguarded POST read are absent'
);
$assert(
    str_contains($controllerSource, 'preg_match ("/\\\\Alfd_[0-9]+\\\\z/D", $key)')
        && !str_contains($controllerSource, 'list($lfd, $num) = explode("_", $key)'),
    'attachment selection accepts only numeric lfd form keys'
);
$assert(
    str_contains($controllerSource, 'eStab attachment list failed:')
        && str_contains($controllerSource, 'eStab attachment reservation failed:')
        && substr_count($controllerSource, 'http_response_code (503)') >= 2
        && str_contains(
            $controllerSource,
            'Die Anhangliste kann derzeit nicht geladen werden.'
        )
        && str_contains(
            $controllerSource,
            'Der Upload kann derzeit nicht vorbereitet werden.'
        ),
    'direct list and upload preparation handle database failures'
);
$assert(
    str_contains(
        $controllerSource,
        'require_once __DIR__ . "/../app/workflow.php";'
    ),
    'attachment controller uses workflow role helpers without loading them'
);
$assert(
    preg_match(
        '/function fileselectwindow \(\$messageContext = null\)\s*\{\s*'
            . 'require \("\.\.\/4fcfg\/dbcfg\.inc\.php"\);\s*'
            . 'require \("\.\.\/4fcfg\/config\.inc\.php"\);/',
        $controllerSource
    ) === 1,
    'upload finalisation loads the complete database configuration in function scope'
);
$assert(
    str_contains(
        $mainControllerSource,
        'estab_attachment_origin_draft_from_request ('
    )
        && str_contains(
            $mainControllerSource,
            'estab_attachment_origin_flow_store ('
        )
        && str_contains(
            $controllerSource,
            'estab_attachment_origin_draft_find ($_SESSION, $originContext)'
        )
        && str_contains(
            $controllerSource,
            '$this->reservation_owner_id ()'
        )
        && str_contains(
            $controllerSource,
            'estab_attachment_release_message_flow_reservation ('
        ),
    'attachment form state or unfinished upload is not isolated/cleaned per flow'
);
$assert(
    str_contains(
        $controllerSource,
        '$attachmentArchiveRoleAllowed ='
    )
        && str_contains(
            $controllerSource,
            'estab_workflow_is_staff_writer ($attachmentPageIdentity)'
        )
        && str_contains(
            $controllerSource,
            'estab_workflow_is_telecommunications ($attachmentPageIdentity)'
        )
        && str_contains(
            $controllerSource,
            'if (!$attachmentArchiveRoleAllowed)'
        )
        && str_contains(
            $controllerSource,
            'estab_attachment_origin_context_validate ('
        )
        && str_contains(
            $controllerSource,
            '$attachmentPageIdentity'
        ),
    'attachment archive or signed flow is not bound to the current effective function'
);
$assert(
    str_contains(
        $controllerSource,
        '$formdata ["01_zeichen"] = $_SESSION ["vStab_kuerzel"];'
    )
        && str_contains(
            $controllerSource,
            '$formdata ["14_zeichen"] ='
        )
        && str_contains(
            $controllerSource,
            '(string) $attachmentOriginContext ["kuerzel"]'
        )
        && str_contains(
            $controllerSource,
            '(string) $attachmentOriginContext ["funktion"]'
        ),
    'attachment return loses the server-bound acting function or receipt marks'
);
$assert(
    str_contains($controllerSource, '$distributionRequest = array ();')
        && str_contains(
            $controllerSource,
            '$distributionRequest ["recipient_matrix_revision"] ='
        )
        && !str_contains($controllerSource, '$draft ["16_gncopy"]')
        && str_contains(
            $controllerSource,
            '$distributionRequest [$recipientKey] = $draft [$recipientKey];'
        )
        && str_contains(
            $controllerSource,
            '$data ["16_empf"] = estab_workflow_distribution_tokens ('
        )
        && str_contains($controllerSource, '$distributionRequest,')
        && str_contains($controllerSource, '$empf_matrix')
        && str_contains(
            $controllerSource,
            'estab_workflow_require_recipient_matrix_revision ('
        )
        && str_contains($controllerSource, '(string) $redcopy2')
        && !str_contains(
            $controllerSource,
            '$data ["16_empf"] .= $recipientFunction'
        ),
    'attachment recipient restore is not constrained to in-form matrix positions'
);
$draftFieldsStart = strpos(
    $attachmentSource,
    'function estab_attachment_origin_draft_fields()'
);
$draftFromRequestStart = strpos(
    $attachmentSource,
    'function estab_attachment_origin_draft_from_request('
);
$draftFormDataStart = strpos(
    $attachmentSource,
    'function estab_attachment_origin_draft_form_data('
);
$draftFormDataEnd = strpos(
    $attachmentSource,
    "/**\n * Validate one draft",
    is_int($draftFormDataStart) ? $draftFormDataStart : 0
);
$draftFormDataSource = (
    is_int($draftFormDataStart)
    && is_int($draftFormDataEnd)
    && $draftFormDataEnd > $draftFormDataStart
) ? substr(
    $attachmentSource,
    $draftFormDataStart,
    $draftFormDataEnd - $draftFormDataStart
) : '';
$centralRevisionCheck = strpos(
    $draftFormDataSource,
    'estab_workflow_require_recipient_matrix_revision('
);
$centralDistributionBuild = strpos(
    $draftFormDataSource,
    'estab_workflow_distribution_tokens('
);
$assert(
    is_int($draftFieldsStart)
        && is_int($draftFromRequestStart)
        && is_int($draftFormDataStart)
        && str_contains(
            substr(
                $attachmentSource,
                $draftFieldsStart,
                $draftFromRequestStart - $draftFieldsStart
            ),
            "'recipient_matrix_revision'"
        )
        && !str_contains(
            substr(
                $attachmentSource,
                $draftFieldsStart,
                $draftFromRequestStart - $draftFieldsStart
            ),
            "'16_gncopy'"
        )
        && str_contains(
            substr(
                $attachmentSource,
                $draftFromRequestStart,
                $draftFormDataStart - $draftFromRequestStart
            ),
            "array_key_exists('16_gncopy', \$request)"
        )
        && str_contains(
            $draftFormDataSource,
            "\$field === 'recipient_matrix_revision'"
        )
        && !str_contains(
            $draftFormDataSource,
            "\$field === '16_gncopy'"
        )
        && is_int($centralRevisionCheck)
        && is_int($centralDistributionBuild)
        && $centralRevisionCheck < $centralDistributionBuild
        && str_contains($draftFormDataSource, '$redCopyFunction')
        && str_contains(
            $draftFormDataSource,
            '$requiredDistributionTokens'
        )
        && str_contains(
            $draftFormDataSource,
            'if ($strictDistribution) {'
        )
        && str_contains(
            $draftFormDataSource,
            'throw new EstabAttachmentDraftException('
        ),
    'central attachment draft does not retain and verify the exact recipient-matrix revision before reconstruction'
);
$assert(
    str_contains(
        $mainControllerSource,
        'estab_attachment_origin_draft_form_data ('
    )
        && str_contains(
            $draftCatchBody,
            '(string) $redcopy2'
        )
        && str_contains(
            $draftCatchBody,
            '$recoveryTask === "Stab_gesprnoti"'
        )
        && str_contains(
            $draftCatchBody,
            '$requiredRecoveryRecipients'
        )
        && str_contains(
            $draftCatchBody,
            '."_gn"'
        ),
    'central attachment recovery omits the matrix revision binding or the conversation-note red/author-green copies'
);
$legacyRestoreStart = strpos(
    $controllerSource,
    'function restore_formdata ($originContext, $originMessage = null)'
);
$legacyRestoreEnd = strpos(
    $controllerSource,
    'function fileselectwindow ($messageContext = null)',
    is_int($legacyRestoreStart) ? $legacyRestoreStart : 0
);
$legacyRestoreSource = (
    is_int($legacyRestoreStart)
    && is_int($legacyRestoreEnd)
    && $legacyRestoreEnd > $legacyRestoreStart
) ? substr(
    $controllerSource,
    $legacyRestoreStart,
    $legacyRestoreEnd - $legacyRestoreStart
) : '';
$legacyRevisionCheck = strpos(
    $legacyRestoreSource,
    'estab_workflow_require_recipient_matrix_revision ('
);
$legacyDistributionBuild = strpos(
    $legacyRestoreSource,
    'estab_workflow_distribution_tokens ('
);
$assert(
    $legacyRestoreSource !== ''
        && str_contains(
            $legacyRestoreSource,
            '"recipient_matrix_revision"'
        )
        && str_contains(
            $legacyRestoreSource,
            '$draft ["recipient_matrix_revision"]'
        )
        && is_int($legacyRevisionCheck)
        && is_int($legacyDistributionBuild)
        && $legacyRevisionCheck < $legacyDistributionBuild
        && str_contains($legacyRestoreSource, '(string) $redcopy2')
        && str_contains(
            $legacyRestoreSource,
            '$originTask === "Stab_gesprnoti"'
        )
        && str_contains(
            $legacyRestoreSource,
            '$requiredDistributionTokens'
        )
        && str_contains($legacyRestoreSource, '."_gn"'),
    'legacy attachment return does not restore the verified matrix coordinates plus conversation-note red/author-green copies'
);
$assert(
    preg_match(
        '/catch \(InvalidArgumentException \$exception\) \{.*?'
            . 'estab_session_ui_abort \(.*?409,.*?'
            . 'Die Empfängermatrix wurde während des Anhangvorgangs geändert\..*?'
            . '"messages".*?\);/s',
        $legacyRestoreSource
    ) === 1,
    'legacy attachment return does not stop stale matrix drafts with a styled 409 response'
);
$assert(
    !str_contains($draftFormDataSource, "'08_befhinweis'")
        && !str_contains($draftFormDataSource, "'08_befhinwausw'")
        && !str_contains($legacyRestoreSource, '"08_befhinweis"')
        && !str_contains($legacyRestoreSource, '"08_befhinwausw"'),
    'central or legacy attachment return still treats retired hints as draft-editable'
);
$assert(
    str_contains($controllerSource, '($formdata ["10_anschrift"] ?? "") === ""')
        && str_contains($messageFormSource, 'value=\\"16_".$m.$n."_bl\\"')
        && str_contains($messageFormSource, 'if (!$this->feld[17])')
        && str_contains($messageFormSource, 'name=\\"17_vermerke\\"'),
    'returned A/W attachment form preserves address, copy selection and sighter notes'
);

$senderTaskBlock = [];
$assert(
    preg_match(
        '/\$senderAssignedByLead\s*=\s*in_array\s*\(\s*'
            . '\$this->task,\s*array\s*\((?<tasks>.*?)\),\s*true\s*\);/s',
        $messageFormSource,
        $senderTaskBlock
    ) === 1,
    'message form does not define the protected A/W incoming sender tasks'
);
$protectedSenderTasks = [];
if (isset($senderTaskBlock['tasks'])) {
    preg_match_all('/"([^"]+)"/', $senderTaskBlock['tasks'], $protectedSenderTasks);
}
$actualProtectedSenderTasks = $protectedSenderTasks[1] ?? [];
$expectedProtectedSenderTasks = [
    'FM-Eingang',
    'FM-Eingang_Anhang',
];
sort($actualProtectedSenderTasks);
sort($expectedProtectedSenderTasks);
$assert(
    $actualProtectedSenderTasks === $expectedProtectedSenderTasks,
    'message form sender protection does not cover exactly every A/W incoming task'
);

$protectedSenderRender = [];
$assert(
    preg_match(
        '/if\s*\(\$senderAssignedByLead\)\s*\{'
            . '(?<body>.*?)'
            . '\}\s*elseif\s*\(!\$this->feld\s*\[13\]\)\s*\{/s',
        $messageFormSource,
        $protectedSenderRender
    ) === 1,
    'protected A/W sender rendering branch is missing'
);
$protectedSenderBody = $protectedSenderRender['body'] ?? '';
$assert(
    str_contains($protectedSenderBody, 'data-estab-readonly=\\"true\\"')
        && str_contains($protectedSenderBody, 'Wird durch LdF aus dem Rufnamen ergänzt')
        && !str_contains($protectedSenderBody, '<input')
        && !str_contains($protectedSenderBody, 'name=\\"13_abseinheit\\"'),
    'A/W incoming form renders a writable or named sender control'
);

$assert(
    str_contains(
        $attachmentSource,
        'function estab_attachment_origin_draft_from_request('
    )
        && str_contains(
            $attachmentSource,
            "str_starts_with(\$task, 'FM-Eingang')"
        )
        && str_contains(
            $attachmentSource,
            "\$draft['13_abseinheit'] = '';"
        )
        && !str_contains(
            $controllerSource,
            'estab_attachment_post_scalar ($_POST, "13_abseinheit")'
        ),
    'attachment storage trusts the browser task or does not discard an A/W incoming sender'
);
$assert(
    str_contains(
        $legacyRestoreSource,
        'in_array ($originTask, array ("FM-Eingang", "FM-Eingang_Anhang"), true)'
    )
        && str_contains(
            $legacyRestoreSource,
            '$data ["13_abseinheit"] = "";'
        )
        && !str_contains(
            $legacyRestoreSource,
            'estab_workflow_is_telecommunications ($restoreIdentity)'
        ),
    'attachment restore derives sender-unit protection from the current account role instead of the signed incoming task'
);
$assert(
    preg_match('/estab_attachment_html\s*\(\s*\$file\s*\[\s*"comment"/', $controllerSource) === 1,
    'database comment is escaped at HTML boundary'
);
$assert(
    preg_match('/estab_attachment_html\s*\(\s*\$storedFilename/', $controllerSource) === 1,
    'database filename is escaped at HTML boundary'
);
$assert(
    str_contains($controllerSource, 'Liste der verfügbaren Dateien'),
    'attachment list heading is stored as valid UTF-8'
);
$assert(
    str_contains($controllerSource, 'id=\\"attachment-upload-file\\"')
        && str_contains($controllerSource, 'accept=\\"".$accept."\\"')
        && str_contains($controllerSource, 'attachment-upload-help')
        && str_contains($controllerSource, 'Erlaubte Formate:')
        && str_contains($controllerSource, 'Maximale Dateigröße:')
        && str_contains($controllerSource, 'formnovalidate')
        && str_contains($uploadServiceSource, 'user_error_message()')
        && str_contains(
            $controllerSource,
            'estab_attachment_html ($exception->getMessage ())'
        ),
    'upload form does not advertise JPEG support, limit, or safe rejection reason'
);
$assert(
    !str_contains($controllerSource, 'Liste der verfÃ¼gbaren Dateien'),
    'attachment list heading contains no UTF-8 mojibake'
);
$assert(
    !str_contains($controllerSource, 'Kein Menüpunkt')
        && str_contains(
            $controllerSource,
            'Die Anhangübersicht konnte nicht initialisiert werden.'
        ),
    'attachment controller replaces the legacy dead-end fallback'
);

foreach (['../../4fach/upload.php', '../../4fach/upload/upload.php'] as $legacyPath) {
    $legacySource = file_get_contents(__DIR__ . '/' . $legacyPath);
    $classPosition = is_string($legacySource) ? strpos($legacySource, 'class fileupload') : false;
    $disabledPrefix = $classPosition === false
        ? (string) $legacySource
        : substr((string) $legacySource, 0, $classPosition);
    $assert(
        preg_match('/http_response_code\s*\(\s*410\s*\)/', $disabledPrefix) === 1
            && str_contains($disabledPrefix, 'exit;'),
        basename($legacyPath) . ' is disabled before legacy SQL can execute'
    );
}

printf("attachment security: OK (%d assertions)\n", $assertions);
