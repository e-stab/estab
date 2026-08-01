<?php

/**
 * Concurrency-safe persistence boundary for attachment reservations/uploads.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/incident.php';
require_once __DIR__ . '/dv_operations.php';
require_once __DIR__ . '/attachment_integrity.php';
require_once __DIR__ . '/file_access.php';
require_once __DIR__ . '/workflow.php';

final class EstabAttachmentDatabaseException extends RuntimeException
{
}

class EstabAttachmentContextException extends RuntimeException
{
}

final class EstabAttachmentDraftException extends EstabAttachmentContextException
{
    public function __construct(
        string $message,
        private readonly array $draft = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Safe scalar values that can be returned to the original form. */
    public function draft(): array
    {
        return $this->draft;
    }
}

const ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS = 16;
const ESTAB_ATTACHMENT_ORIGIN_DRAFT_MAX_BYTES = 1048576;
const ESTAB_ATTACHMENT_ORIGIN_DRAFTS_MAX_BYTES = 8388608;
const ESTAB_ATTACHMENT_ORIGIN_ATTACHMENT_LIST_MAX_BYTES = 65535;
const ESTAB_ATTACHMENT_DIRECT_ACTION_MAX_TOKENS = 64;
const ESTAB_ATTACHMENT_DIRECT_ACTION_TTL_SECONDS = 43200;

/** Tasks whose editable message form may enter the attachment picker. */
function estab_attachment_origin_tasks(): array
{
    return estab_workflow_attachment_edit_tasks();
}

/**
 * Return the authoritative account identity used to bind an attachment flow.
 *
 * Optional shift membership is deliberately absent from this context. A flow
 * remains bound to the complete fixed account identity and active incident.
 */
function estab_attachment_origin_identity(array $identity): array
{
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if ($shape === null) {
        throw new EstabAttachmentContextException(
            'Der Anhangvorgang besitzt keine gültige Benutzeridentität.'
        );
    }
    return $shape;
}

function estab_attachment_origin_role_allowed(array $identity, string $task): bool
{
    if (in_array($task, ['FM-Eingang', 'FM-Eingang_Anhang'], true)) {
        return estab_workflow_is_telecommunications($identity);
    }
    if (
        in_array(
            $task,
            ['Stab_schreiben', 'Stab_korrigieren', 'Stab_gesprnoti'],
            true
        )
    ) {
        return estab_workflow_is_staff_writer($identity);
    }
    return false;
}

/** Bind one direct upload button to its account, incident and form object. */
function estab_attachment_direct_action_fingerprint(
    array $identity,
    mixed $incidentId,
    mixed $task,
    mixed $recordId = null
): string {
    $identity = estab_attachment_origin_identity($identity);
    $incidentId = estab_incident_positive_id($incidentId);
    if (
        !is_string($task)
        || !in_array($task, estab_attachment_origin_tasks(), true)
        || !estab_attachment_origin_role_allowed($identity, $task)
    ) {
        throw new EstabAttachmentContextException(
            'Der direkte Anhangvorgang gehört zu keinem bearbeitbaren Formular.'
        );
    }
    if ($task === 'Stab_korrigieren') {
        $recordId = estab_incident_positive_id($recordId, 'Nachrichten-ID');
    } elseif ($recordId === null || $recordId === '') {
        $recordId = null;
    } else {
        throw new EstabAttachmentContextException(
            'Ein neuer Nachrichtenvordruck darf keine Datensatz-ID enthalten.'
        );
    }
    try {
        $encoded = json_encode(
            [
                'incident_id' => $incidentId,
                'task' => $task,
                'record_id' => $recordId,
                'benutzer' => $identity['benutzer'],
                'kuerzel' => $identity['kuerzel'],
                'funktion' => $identity['funktion'],
                'rolle' => $identity['rolle'],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        throw new EstabAttachmentContextException(
            'Der direkte Anhangvorgang konnte nicht gebunden werden.',
            previous: $exception
        );
    }
    return hash('sha256', $encoded);
}

/** Keep only bounded, recent and structurally valid direct-action tokens. */
function estab_attachment_direct_actions_prune(
    mixed $actions,
    int $now
): array {
    if ($actions === null) {
        return [];
    }
    if (!is_array($actions)) {
        throw new EstabAttachmentContextException(
            'Die gespeicherten direkten Anhangvorgänge sind ungültig.'
        );
    }
    $valid = [];
    foreach ($actions as $token => $entry) {
        if (
            !is_string($token)
            || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
            || !is_array($entry)
            || !is_int($entry['updated_at'] ?? null)
            || $entry['updated_at'] < $now - ESTAB_ATTACHMENT_DIRECT_ACTION_TTL_SECONDS
            || $entry['updated_at'] > $now + 300
            || !is_string($entry['fingerprint'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $entry['fingerprint']) !== 1
            || !in_array(
                $entry['state'] ?? null,
                ['issued', 'processing', 'completed'],
                true
            )
        ) {
            continue;
        }
        if (($entry['state'] ?? null) === 'completed') {
            $mode = $entry['mode'] ?? null;
            if (!in_array(
                $mode,
                ['upload', 'submit', 'conversation-stage'],
                true
            )) {
                continue;
            }
            if (array_key_exists('reference', $entry)) {
                try {
                    $entry['reference'] = estab_file_validate_name(
                        'attachment',
                        (string) $entry['reference']
                    );
                } catch (InvalidArgumentException) {
                    continue;
                }
            } elseif ($mode === 'upload') {
                // A standalone upload always has exactly one durable result.
                continue;
            }
        } elseif (
            ($entry['state'] ?? null) === 'processing'
            && array_key_exists('mode', $entry)
        ) {
            if (($entry['mode'] ?? null) !== 'pending-submit') {
                continue;
            }
            if (array_key_exists('reference', $entry)) {
                try {
                    $entry['reference'] = estab_file_validate_name(
                        'attachment',
                        (string) $entry['reference']
                    );
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }
        $valid[$token] = $entry;
    }
    while (count($valid) > ESTAB_ATTACHMENT_DIRECT_ACTION_MAX_TOKENS) {
        array_shift($valid);
    }
    return $valid;
}

/** Issue a one-time action token without coupling parallel browser tabs. */
function estab_attachment_direct_action_issue(
    array &$session,
    array $identity,
    mixed $incidentId,
    mixed $task,
    mixed $recordId = null,
    ?int $now = null
): string {
    $now = $now ?? time();
    $fingerprint = estab_attachment_direct_action_fingerprint(
        $identity,
        $incidentId,
        $task,
        $recordId
    );
    $actions = estab_attachment_direct_actions_prune(
        $session['anhang_direct_actions'] ?? null,
        $now
    );
    while (count($actions) >= ESTAB_ATTACHMENT_DIRECT_ACTION_MAX_TOKENS) {
        array_shift($actions);
    }
    try {
        do {
            $token = bin2hex(random_bytes(32));
        } while (isset($actions[$token]));
    } catch (Throwable $exception) {
        throw new EstabAttachmentContextException(
            'Der direkte Anhangvorgang konnte nicht sicher vorbereitet werden.',
            previous: $exception
        );
    }
    $actions[$token] = [
        'fingerprint' => $fingerprint,
        'state' => 'issued',
        'updated_at' => $now,
    ];
    $session['anhang_direct_actions'] = $actions;
    return $token;
}

/**
 * Inspect an action token without claiming a fresh, issued upload.
 *
 * Ordinary message submission also carries the form token when no file was
 * selected. This lookup lets a retry without a multipart file recover a
 * prior pending result or recognise a completed submit, while a genuinely
 * fresh no-file submission remains an ordinary message action.
 *
 * @return null|array{reference:?string,mode:string}
 */
function estab_attachment_direct_action_replay_result(
    array &$session,
    mixed $token,
    array $identity,
    mixed $incidentId,
    mixed $task,
    mixed $recordId = null,
    ?int $now = null
): ?array {
    $now = $now ?? time();
    if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
        throw new EstabAttachmentContextException(
            'Der direkte Anhangvorgang ist ungültig oder abgelaufen.'
        );
    }
    $actions = estab_attachment_direct_actions_prune(
        $session['anhang_direct_actions'] ?? null,
        $now
    );
    $entry = $actions[$token] ?? null;
    $fingerprint = estab_attachment_direct_action_fingerprint(
        $identity,
        $incidentId,
        $task,
        $recordId
    );
    if (
        !is_array($entry)
        || !hash_equals((string) $entry['fingerprint'], $fingerprint)
    ) {
        $session['anhang_direct_actions'] = $actions;
        throw new EstabAttachmentContextException(
            'Der direkte Anhangvorgang ist ungültig oder abgelaufen.'
        );
    }
    if ($entry['state'] === 'completed') {
        $session['anhang_direct_actions'] = $actions;
        return [
            'reference' => isset($entry['reference'])
                ? (string) $entry['reference']
                : null,
            'mode' => (string) $entry['mode'],
        ];
    }
    if (
        $entry['state'] === 'processing'
        && ($entry['mode'] ?? null) === 'pending-submit'
    ) {
        $session['anhang_direct_actions'] = $actions;
        return [
            'reference' => isset($entry['reference'])
                ? (string) $entry['reference']
                : null,
            'mode' => 'pending-submit',
        ];
    }
    if ($entry['state'] === 'issued') {
        $session['anhang_direct_actions'] = $actions;
        return null;
    }
    $session['anhang_direct_actions'] = $actions;
    throw new EstabAttachmentContextException(
        'Der direkte Anhangvorgang wird bereits verarbeitet.'
    );
}

/**
 * Claim a token once. A completed upload is returned for idempotent replay.
 *
 * @return null|array{reference:?string,mode:string}
 */
function estab_attachment_direct_action_claim(
    array &$session,
    mixed $token,
    array $identity,
    mixed $incidentId,
    mixed $task,
    mixed $recordId = null,
    ?int $now = null
): ?array {
    $now = $now ?? time();
    $replay = estab_attachment_direct_action_replay_result(
        $session,
        $token,
        $identity,
        $incidentId,
        $task,
        $recordId,
        $now
    );
    if (is_array($replay)) {
        return $replay;
    }
    $actions = estab_attachment_direct_actions_prune(
        $session['anhang_direct_actions'] ?? null,
        $now
    );
    $actions[$token]['state'] = 'processing';
    $actions[$token]['updated_at'] = $now;
    $session['anhang_direct_actions'] = $actions;
    return null;
}

/** Retain an uploaded file while the ordinary message validation is pending. */
function estab_attachment_direct_action_note_pending_submit(
    array &$session,
    string $token,
    ?string $reference = null,
    ?int $now = null
): void {
    $now = $now ?? time();
    if ($reference !== null) {
        $reference = estab_file_validate_name('attachment', $reference);
    }
    $actions = estab_attachment_direct_actions_prune(
        $session['anhang_direct_actions'] ?? null,
        $now
    );
    if (($actions[$token]['state'] ?? null) !== 'processing') {
        throw new EstabAttachmentContextException(
            'Der direkte Anhangvorgang kann nicht vorgemerkt werden.'
        );
    }
    if ($reference === null) {
        unset($actions[$token]['reference']);
    } else {
        $actions[$token]['reference'] = $reference;
    }
    $actions[$token]['mode'] = 'pending-submit';
    $actions[$token]['updated_at'] = $now;
    $session['anhang_direct_actions'] = $actions;
}

/** Publish the one server-generated reference as this token's replay result. */
function estab_attachment_direct_action_complete(
    array &$session,
    string $token,
    ?string $reference,
    string $mode,
    ?int $now = null
): void {
    $now = $now ?? time();
    if (!in_array($mode, ['upload', 'submit', 'conversation-stage'], true)) {
        throw new InvalidArgumentException('Invalid direct attachment action mode');
    }
    if ($reference !== null) {
        $reference = estab_file_validate_name('attachment', $reference);
    } elseif ($mode === 'upload') {
        throw new InvalidArgumentException(
            'A completed direct upload requires its attachment reference'
        );
    }
    $actions = estab_attachment_direct_actions_prune(
        $session['anhang_direct_actions'] ?? null,
        $now
    );
    if (($actions[$token]['state'] ?? null) !== 'processing') {
        throw new EstabAttachmentContextException(
            'Der direkte Anhangvorgang kann nicht abgeschlossen werden.'
        );
    }
    $actions[$token]['state'] = 'completed';
    if ($reference === null) {
        unset($actions[$token]['reference']);
    } else {
        $actions[$token]['reference'] = $reference;
    }
    $actions[$token]['mode'] = $mode;
    $actions[$token]['updated_at'] = $now;
    $session['anhang_direct_actions'] = $actions;
}

/** Release only an unfinished token after a visible, recoverable failure. */
function estab_attachment_direct_action_abandon(
    array &$session,
    mixed $token,
    ?int $now = null
): void {
    if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
        return;
    }
    $actions = estab_attachment_direct_actions_prune(
        $session['anhang_direct_actions'] ?? null,
        $now ?? time()
    );
    if (
        ($actions[$token]['state'] ?? null) === 'processing'
        && !isset($actions[$token]['reference'])
    ) {
        unset($actions[$token]);
    }
    if ($actions === []) {
        unset($session['anhang_direct_actions']);
    } else {
        $session['anhang_direct_actions'] = $actions;
    }
}

/** Remove one known action token after its durable outcome is already safe. */
function estab_attachment_direct_action_forget(
    array &$session,
    mixed $token,
    ?int $now = null
): void {
    if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
        return;
    }
    $actions = estab_attachment_direct_actions_prune(
        $session['anhang_direct_actions'] ?? null,
        $now ?? time()
    );
    unset($actions[$token]);
    if ($actions === []) {
        unset($session['anhang_direct_actions']);
    } else {
        $session['anhang_direct_actions'] = $actions;
    }
}

/**
 * Create a server-owned attachment origin after the main route/object gate.
 *
 * For a correction the record id is copied from the already authorised row,
 * then compared with the submitted hidden field. New-message forms may not
 * smuggle a record id into the session context.
 *
 * @return array{
 *   version:int,
 *   flow_token:string,
 *   incident_id:int,
 *   benutzer:string,
 *   kuerzel:string,
 *   funktion:string,
 *   rolle:string,
 *   task:string,
 *   record_id:?int
 * }
 */
function estab_attachment_origin_context_create(
    array $identity,
    mixed $incidentId,
    array $request,
    ?array $authorizedMessage = null
): array {
    $identity = estab_attachment_origin_identity($identity);
    try {
        $incidentId = estab_incident_positive_id($incidentId);
    } catch (InvalidArgumentException $exception) {
        throw new EstabAttachmentContextException(
            'Der Anhangvorgang besitzt keinen gültigen Einsatz.',
            previous: $exception
        );
    }
    $task = $request['task'] ?? null;
    if (
        !is_string($task)
        || !in_array($task, estab_attachment_origin_tasks(), true)
        || !estab_attachment_origin_role_allowed($identity, $task)
    ) {
        throw new EstabAttachmentContextException(
            'Dieser Nachrichtenvordruck darf keine Anhänge übernehmen.'
        );
    }
    if (array_key_exists('attachment_flow', $request)) {
        throw new EstabAttachmentContextException(
            'Ein Anhangvorgang darf nicht durch den Browser vorgegeben werden.'
        );
    }

    $recordId = null;
    if ($task === 'Stab_korrigieren') {
        if (!is_array($authorizedMessage)) {
            throw new EstabAttachmentContextException(
                'Der zu korrigierende Datensatz wurde nicht autorisiert.'
            );
        }
        try {
            $recordId = estab_incident_positive_id(
                $authorizedMessage['00_lfd'] ?? null,
                'Nachrichten-ID'
            );
            $messageIncidentId = estab_incident_positive_id(
                $authorizedMessage['einsatz_id'] ?? null
            );
            $requestedRecordId = estab_incident_positive_id(
                $request['00_lfd'] ?? null,
                'Nachrichten-ID'
            );
        } catch (InvalidArgumentException $exception) {
            throw new EstabAttachmentContextException(
                'Der zu korrigierende Datensatz ist ungültig.',
                previous: $exception
            );
        }
        if (
            $messageIncidentId !== $incidentId
            || $requestedRecordId !== $recordId
        ) {
            throw new EstabAttachmentContextException(
                'Der zu korrigierende Datensatz stimmt nicht mit dem '
                . 'autorisierten Nachrichtenvordruck überein.'
            );
        }
    } elseif (
        array_key_exists('00_lfd', $request)
        && $request['00_lfd'] !== ''
    ) {
        throw new EstabAttachmentContextException(
            'Ein neuer Nachrichtenvordruck darf keine Datensatz-ID enthalten.'
        );
    }

    try {
        $flowToken = bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        throw new EstabAttachmentContextException(
            'Der Anhangvorgang konnte nicht sicher initialisiert werden.',
            previous: $exception
        );
    }

    return [
        'version' => 2,
        'flow_token' => $flowToken,
        'incident_id' => $incidentId,
        'benutzer' => $identity['benutzer'],
        'kuerzel' => $identity['kuerzel'],
        'funktion' => $identity['funktion'],
        'rolle' => $identity['rolle'],
        'task' => $task,
        'record_id' => $recordId,
    ];
}

/**
 * Validate a stored origin against the current server identity and request.
 *
 * The browser may echo task/id fields, but it can never select them. All
 * continuation POSTs additionally carry an unpredictable flow token so two
 * open browser tabs cannot accidentally resume each other's message form.
 */
function estab_attachment_origin_context_validate(
    mixed $stored,
    array $identity,
    mixed $incidentId,
    array $request = [],
    bool $requireFlowToken = false
): array {
    if (!is_array($stored) || ($stored['version'] ?? null) !== 2) {
        throw new EstabAttachmentContextException(
            'Der gespeicherte Anhangvorgang ist ungültig oder abgelaufen.'
        );
    }
    $identity = estab_attachment_origin_identity($identity);
    try {
        $incidentId = estab_incident_positive_id($incidentId);
        $storedIncidentId = estab_incident_positive_id(
            $stored['incident_id'] ?? null
        );
    } catch (InvalidArgumentException $exception) {
        throw new EstabAttachmentContextException(
            'Der gespeicherte Anhangvorgang ist ungültig.',
            previous: $exception
        );
    }
    $task = $stored['task'] ?? null;
    $flowToken = $stored['flow_token'] ?? null;
    if (
        !is_string($task)
        || !in_array($task, estab_attachment_origin_tasks(), true)
        || !estab_attachment_origin_role_allowed($identity, $task)
        || !is_string($flowToken)
        || preg_match('/\A[a-f0-9]{32}\z/D', $flowToken) !== 1
        || $storedIncidentId !== $incidentId
    ) {
        throw new EstabAttachmentContextException(
            'Der gespeicherte Anhangvorgang gehört nicht zur aktuellen '
            . 'Kontofunktion oder zum aktiven Einsatz.'
        );
    }
    foreach (['benutzer', 'kuerzel', 'funktion', 'rolle'] as $field) {
        if (
            !isset($stored[$field])
            || !is_string($stored[$field])
            || !hash_equals($identity[$field], $stored[$field])
        ) {
            throw new EstabAttachmentContextException(
                'Der gespeicherte Anhangvorgang gehört nicht zur aktuellen '
                . 'Benutzeridentität.'
            );
        }
    }

    $recordId = null;
    if ($task === 'Stab_korrigieren') {
        try {
            $recordId = estab_incident_positive_id(
                $stored['record_id'] ?? null,
                'Nachrichten-ID'
            );
        } catch (InvalidArgumentException $exception) {
            throw new EstabAttachmentContextException(
                'Der gespeicherte Korrekturdatensatz ist ungültig.',
                previous: $exception
            );
        }
    } elseif (($stored['record_id'] ?? null) !== null) {
        throw new EstabAttachmentContextException(
            'Ein neuer Nachrichtenvordruck enthält unerwartet eine Datensatz-ID.'
        );
    }

    if (array_key_exists('task', $request)) {
        if (!is_string($request['task']) || !hash_equals($task, $request['task'])) {
            throw new EstabAttachmentContextException(
                'Die angeforderte Aufgabe stimmt nicht mit dem Anhangvorgang überein.'
            );
        }
    }
    if (array_key_exists('00_lfd', $request)) {
        if ($task === 'Stab_korrigieren') {
            try {
                $requestRecordId = estab_incident_positive_id(
                    $request['00_lfd'],
                    'Nachrichten-ID'
                );
            } catch (InvalidArgumentException $exception) {
                throw new EstabAttachmentContextException(
                    'Die angeforderte Datensatz-ID ist ungültig.',
                    previous: $exception
                );
            }
            if ($requestRecordId !== $recordId) {
                throw new EstabAttachmentContextException(
                    'Die angeforderte Datensatz-ID stimmt nicht mit dem '
                    . 'Anhangvorgang überein.'
                );
            }
        } elseif ($request['00_lfd'] !== '') {
            throw new EstabAttachmentContextException(
                'Ein neuer Nachrichtenvordruck darf keine Datensatz-ID enthalten.'
            );
        }
    }

    $submittedFlowToken = $request['attachment_flow'] ?? null;
    if (
        $requireFlowToken
        || array_key_exists('attachment_flow', $request)
    ) {
        if (
            !is_string($submittedFlowToken)
            || !hash_equals($flowToken, $submittedFlowToken)
        ) {
            throw new EstabAttachmentContextException(
                'Der Anhangvorgang ist ungültig oder wurde in einem anderen '
                . 'Browserfenster ersetzt.'
            );
        }
    }

    return $stored;
}

/** Parse the unguessable browser/server correlation token of one attachment flow. */
function estab_attachment_origin_flow_token(mixed $token): string
{
    if (
        !is_string($token)
        || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1
    ) {
        throw new EstabAttachmentContextException(
            'Der Anhangvorgang besitzt keinen gültigen Flow-Token.'
        );
    }
    return $token;
}

/**
 * Store one independently resumable origin without replacing other tabs.
 *
 * The bounded map avoids retaining abandoned drafts for an unbounded number
 * of tabs during a long-running operational session.
 */
function estab_attachment_origin_context_store(
    array &$session,
    array $context,
    int $maximumFlows = ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
    ?callable $releaseEvictedFlow = null
): string {
    if (
        $maximumFlows < 2
        || $maximumFlows > ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS
    ) {
        throw new InvalidArgumentException('Invalid attachment flow limit');
    }
    $token = estab_attachment_origin_flow_token(
        $context['flow_token'] ?? null
    );
    if (
        array_key_exists('anhang_origin_contexts', $session)
        && !is_array($session['anhang_origin_contexts'])
    ) {
        throw new EstabAttachmentContextException(
            'Die gespeicherten Anhangvorgänge sind ungültig.'
        );
    }
    if (
        array_key_exists('anhang_origin_drafts', $session)
        && !is_array($session['anhang_origin_drafts'])
    ) {
        throw new EstabAttachmentContextException(
            'Die gespeicherten Nachrichtenentwürfe sind ungültig.'
        );
    }
    $contexts = $session['anhang_origin_contexts'] ?? [];
    if (count($contexts) > $maximumFlows) {
        throw new EstabAttachmentContextException(
            'Zu viele gespeicherte Anhangvorgänge.'
        );
    }
    while (count($contexts) >= $maximumFlows && !isset($contexts[$token])) {
        $expiredToken = array_key_first($contexts);
        if (!is_string($expiredToken)) {
            throw new EstabAttachmentContextException(
                'Die gespeicherten Anhangvorgänge sind ungültig.'
            );
        }
        $expiredContext = $contexts[$expiredToken] ?? null;
        if (!is_array($expiredContext) || $releaseEvictedFlow === null) {
            throw new EstabAttachmentContextException(
                'Zu viele offene Anhangvorgänge. Schließen Sie zuerst einen '
                . 'älteren Nachrichtenvordruck.'
            );
        }
        // Release any DB reservation before the only context that can derive
        // its isolated owner is removed from the session.
        $releaseEvictedFlow($expiredContext);
        unset($contexts[$expiredToken]);
        if (is_array($session['anhang_origin_drafts'] ?? null)) {
            unset($session['anhang_origin_drafts'][$expiredToken]);
        }
    }
    $contexts[$token] = $context;
    $session['anhang_origin_contexts'] = $contexts;
    // A legacy singleton must never become an alternative authority source.
    unset($session['anhang_origin_context'], $session['anhang_message_context']);
    return $token;
}

/** Return one exact flow context; no token means no message-form flow. */
function estab_attachment_origin_context_find(
    array $session,
    mixed $token
): ?array {
    $token = estab_attachment_origin_flow_token($token);
    $contexts = $session['anhang_origin_contexts'] ?? null;
    if ($contexts === null) {
        return null;
    }
    if (!is_array($contexts)) {
        throw new EstabAttachmentContextException(
            'Die gespeicherten Anhangvorgänge sind ungültig.'
        );
    }
    $context = $contexts[$token] ?? null;
    return is_array($context) ? $context : null;
}

/** Exact scalar fields that one unsaved official message form may retain. */
function estab_attachment_origin_draft_fields(): array
{
    static $fields = null;
    if ($fields === null) {
        $fields = array_fill_keys([
            '01_medium', '01_datum', '01_zeichen', '05_gegenstelle',
            '06_befweg', '06_befwegausw', '07_durchspruch',
            '08_befhinweis', '08_befhinwausw', '09_vorrangstufe',
            '10_anschrift', '11_rufnummer', '11_gesprnotiz',
            '12_anhang', '12_betreff', '12_inhalt', '12_abfzeit',
            '13_abseinheit', '14_zeichen', '14_funktion',
            '15_quitdatum', '15_quitzeichen', '16_gncopy',
            'recipient_matrix_revision',
            '17_vermerke',
        ], true);
        for ($row = 1; $row <= 5; $row++) {
            for ($column = 1; $column <= 4; $column++) {
                $fields['16_' . $row . $column] = true;
            }
        }
    }
    return $fields;
}

/**
 * Copy only the official form's scalar values into a resumable draft.
 *
 * Invalid browser arrays or byte sequences are replaced only in the safe
 * return payload and then rejected, so every other valid field can still be
 * shown to the operator without becoming session authority.
 */
function estab_attachment_origin_draft_from_request(
    array $request,
    array $identity,
    array $context
): array {
    $draft = [];
    $invalid = false;
    foreach ([
        '01_medium', '01_datum', '01_zeichen', '05_gegenstelle',
        '06_befweg', '06_befwegausw', '07_durchspruch',
        '08_befhinweis', '08_befhinwausw', '09_vorrangstufe',
        '10_anschrift', '11_rufnummer', '11_gesprnotiz',
        '12_anhang', '12_betreff', '12_inhalt', '12_abfzeit',
        '14_zeichen', '14_funktion', '15_quitdatum',
        '15_quitzeichen', '16_gncopy', 'recipient_matrix_revision',
        '17_vermerke',
    ] as $field) {
        $value = $request[$field] ?? '';
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            $value = '';
            $invalid = true;
        }
        $draft[$field] = $value;
    }

    $task = is_string($context['task'] ?? null)
        ? $context['task']
        : '';
    $sender = $request['13_abseinheit'] ?? '';
    if (
        estab_workflow_is_telecommunications($identity)
        && str_starts_with($task, 'FM-Eingang')
    ) {
        $draft['13_abseinheit'] = '';
    } elseif (!is_string($sender) || preg_match('//u', $sender) !== 1) {
        $draft['13_abseinheit'] = '';
        $invalid = true;
    } else {
        $draft['13_abseinheit'] = $sender;
    }

    for ($row = 1; $row <= 5; $row++) {
        for ($column = 1; $column <= 4; $column++) {
            $field = '16_' . $row . $column;
            if (!array_key_exists($field, $request)) {
                continue;
            }
            $value = $request[$field];
            if (!is_string($value) || preg_match('//u', $value) !== 1) {
                $invalid = true;
                continue;
            }
            if ($value !== '') {
                $draft[$field] = $value;
            }
        }
    }
    if ($invalid) {
        throw new EstabAttachmentDraftException(
            'Der Nachrichtenentwurf enthält ungültige Formularwerte. '
            . 'Prüfen Sie die markierten Eingaben und versuchen Sie es erneut.',
            $draft
        );
    }
    return $draft;
}

/** Rebuild the editable form from a safe draft without trusting route fields. */
function estab_attachment_origin_draft_form_data(
    array $draft,
    array $context,
    ?array $originMessage,
    array $recipientMatrix,
    bool $strictDistribution = true,
    string $redCopyFunction = '',
    array $requiredDistributionTokens = []
): array {
    $allowedFields = estab_attachment_origin_draft_fields();
    foreach ($draft as $field => $value) {
        if (
            !is_string($field)
            || !isset($allowedFields[$field])
            || !is_string($value)
            || preg_match('//u', $value) !== 1
        ) {
            throw new EstabAttachmentContextException(
                'Der Nachrichtenentwurf kann nicht sicher angezeigt werden.'
            );
        }
    }
    $task = is_string($context['task'] ?? null)
        ? $context['task']
        : '';
    $distributionRequest = [];
    foreach ($draft as $field => $value) {
        if (
            $field === '16_gncopy'
            || $field === 'recipient_matrix_revision'
            || preg_match('/\A16_[1-5][1-4]\z/D', $field)
        ) {
            $distributionRequest[$field] = $value;
        }
    }
    try {
        estab_workflow_require_recipient_matrix_revision(
            $distributionRequest,
            $recipientMatrix,
            $redCopyFunction
        );
        $distribution = estab_workflow_distribution_tokens(
            $distributionRequest,
            $recipientMatrix,
            $requiredDistributionTokens
        );
    } catch (InvalidArgumentException $exception) {
        if ($strictDistribution) {
            throw new EstabAttachmentDraftException(
                'Die Empfängerauswahl des Nachrichtenentwurfs ist ungültig.',
                $draft,
                $exception
            );
        }
        $distribution = '';
    }

    $data = $draft;
    $data['16_empf'] = $distribution;
    if ($task === 'Stab_korrigieren') {
        if (!is_array($originMessage)) {
            throw new EstabAttachmentContextException(
                'Der Korrekturdatensatz kann nicht wiederhergestellt werden.'
            );
        }
        $editable = [
            '07_durchspruch', '08_befhinweis', '08_befhinwausw',
            '09_vorrangstufe', '10_anschrift', '11_rufnummer',
            '11_gesprnotiz', '12_anhang', '12_betreff',
            '12_inhalt', '12_abfzeit',
        ];
        $correction = $originMessage;
        foreach ($editable as $field) {
            if (array_key_exists($field, $draft)) {
                $correction[$field] = $draft[$field];
            }
        }
        $data = $correction;
    }
    $data['00_lfd'] = $task === 'Stab_korrigieren'
        ? (int) ($context['record_id'] ?? 0)
        : '';
    return $data;
}

/**
 * Validate one draft and return its actual PHP-session storage footprint.
 *
 * Byte limits intentionally apply after UTF-8 validation: this bounds memory
 * and session-file growth without splitting or miscounting multibyte text.
 */
function estab_attachment_origin_draft_bytes(array $draft): int
{
    $allowedFields = estab_attachment_origin_draft_fields();
    if (count($draft) > count($allowedFields)) {
        throw new EstabAttachmentDraftException(
            'Der Nachrichtenentwurf enthält zu viele Felder.',
            $draft
        );
    }
    foreach ($draft as $field => $value) {
        if (
            !is_string($field)
            || !isset($allowedFields[$field])
            || !is_string($value)
        ) {
            throw new EstabAttachmentDraftException(
                'Der Nachrichtenentwurf enthält ein ungültiges Feld.',
                $draft
            );
        }
        if (preg_match('//u', $value) !== 1) {
            throw new EstabAttachmentDraftException(
                'Der Nachrichtenentwurf enthält ungültiges UTF-8.',
                $draft
            );
        }
        if (
            $field === '12_anhang'
            && strlen($value)
                > ESTAB_ATTACHMENT_ORIGIN_ATTACHMENT_LIST_MAX_BYTES
        ) {
            throw new EstabAttachmentDraftException(
                'Die Anhangliste des Nachrichtenentwurfs ist zu groß.',
                $draft
            );
        }
    }
    $bytes = strlen(serialize($draft));
    if ($bytes > ESTAB_ATTACHMENT_ORIGIN_DRAFT_MAX_BYTES) {
        throw new EstabAttachmentDraftException(
            'Der Nachrichtenentwurf ist zu groß. Kürzen Sie den Text, bevor '
            . 'Sie die Anhangverwaltung öffnen.',
            $draft
        );
    }
    return $bytes;
}

/** Validate the complete bounded draft map before mutating the session. */
function estab_attachment_origin_drafts_bytes(
    array $drafts,
    array $contexts
): int {
    if (
        count($drafts) > ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS
        || count($contexts) > ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS
    ) {
        throw new EstabAttachmentDraftException(
            'Zu viele Nachrichtenentwürfe sind gleichzeitig geöffnet.'
        );
    }
    foreach ($drafts as $token => $draft) {
        $token = estab_attachment_origin_flow_token($token);
        if (!is_array($draft) || !is_array($contexts[$token] ?? null)) {
            throw new EstabAttachmentContextException(
                'Die gespeicherten Nachrichtenentwürfe sind ungültig.'
            );
        }
        estab_attachment_origin_draft_bytes($draft);
    }
    $bytes = strlen(serialize($drafts));
    if ($bytes > ESTAB_ATTACHMENT_ORIGIN_DRAFTS_MAX_BYTES) {
        throw new EstabAttachmentDraftException(
            'Die offenen Nachrichtenentwürfe belegen zu viel '
            . 'Sitzungsspeicher. Schließen Sie zuerst einen anderen Entwurf.'
        );
    }
    return $bytes;
}

/**
 * Atomically admit one new context together with its already validated draft.
 *
 * The complete prospective session map is checked before an eviction callback
 * can release a reservation. Only after that callback succeeds are both maps
 * replaced, so an invalid candidate cannot evict or leave an empty context.
 */
function estab_attachment_origin_flow_store(
    array &$session,
    array $context,
    array $draft,
    int $maximumFlows = ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS,
    ?callable $releaseEvictedFlow = null
): string {
    if (
        $maximumFlows < 2
        || $maximumFlows > ESTAB_ATTACHMENT_ORIGIN_MAX_FLOWS
    ) {
        throw new InvalidArgumentException('Invalid attachment flow limit');
    }
    $token = estab_attachment_origin_flow_token(
        $context['flow_token'] ?? null
    );
    try {
        estab_attachment_origin_draft_bytes($draft);
    } catch (EstabAttachmentDraftException $exception) {
        throw new EstabAttachmentDraftException(
            $exception->getMessage(),
            $draft,
            $exception
        );
    }
    foreach (['anhang_origin_contexts', 'anhang_origin_drafts'] as $key) {
        if (array_key_exists($key, $session) && !is_array($session[$key])) {
            throw new EstabAttachmentContextException(
                'Die gespeicherten Anhangvorgänge sind ungültig.'
            );
        }
    }
    $contexts = $session['anhang_origin_contexts'] ?? [];
    $drafts = $session['anhang_origin_drafts'] ?? [];
    if (count($contexts) > $maximumFlows) {
        throw new EstabAttachmentContextException(
            'Zu viele gespeicherte Anhangvorgänge.'
        );
    }
    try {
        estab_attachment_origin_drafts_bytes($drafts, $contexts);
    } catch (EstabAttachmentDraftException $exception) {
        throw new EstabAttachmentDraftException(
            $exception->getMessage(),
            $draft,
            $exception
        );
    }

    $evictedContext = null;
    $evictedToken = null;
    if (count($contexts) >= $maximumFlows && !isset($contexts[$token])) {
        $evictedToken = array_key_first($contexts);
        $evictedContext = is_string($evictedToken)
            ? ($contexts[$evictedToken] ?? null)
            : null;
        if (!is_string($evictedToken) || !is_array($evictedContext)) {
            throw new EstabAttachmentContextException(
                'Die gespeicherten Anhangvorgänge sind ungültig.'
            );
        }
        if ($releaseEvictedFlow === null) {
            throw new EstabAttachmentDraftException(
                'Zu viele Nachrichtenvordrucke sind gleichzeitig geöffnet. '
                . 'Schließen Sie zuerst einen älteren Entwurf.',
                $draft
            );
        }
        unset($contexts[$evictedToken], $drafts[$evictedToken]);
    }

    $contexts[$token] = $context;
    $drafts[$token] = $draft;
    try {
        estab_attachment_origin_drafts_bytes($drafts, $contexts);
    } catch (EstabAttachmentDraftException $exception) {
        throw new EstabAttachmentDraftException(
            $exception->getMessage(),
            $draft,
            $exception
        );
    }

    if (is_array($evictedContext)) {
        // The callback owns the database transaction. If it fails, neither
        // server-side session map has been mutated.
        $releaseEvictedFlow($evictedContext);
    }
    $session['anhang_origin_contexts'] = $contexts;
    $session['anhang_origin_drafts'] = $drafts;
    unset($session['anhang_origin_context'], $session['anhang_message_context']);
    return $token;
}

/** Bind the unsaved form fields to the same token as their origin context. */
function estab_attachment_origin_draft_store(
    array &$session,
    array $context,
    array $draft
): void {
    $token = estab_attachment_origin_flow_token(
        $context['flow_token'] ?? null
    );
    $stored = estab_attachment_origin_context_find($session, $token);
    if (!is_array($stored)) {
        throw new EstabAttachmentContextException(
            'Der Entwurf besitzt keinen aktiven Anhangvorgang.'
        );
    }
    if (
        array_key_exists('anhang_origin_drafts', $session)
        && !is_array($session['anhang_origin_drafts'])
    ) {
        throw new EstabAttachmentContextException(
            'Die gespeicherten Nachrichtenentwürfe sind ungültig.'
        );
    }
    $contexts = $session['anhang_origin_contexts'] ?? null;
    if (!is_array($contexts)) {
        throw new EstabAttachmentContextException(
            'Der Entwurf besitzt keinen gültigen Anhangvorgang.'
        );
    }
    $drafts = $session['anhang_origin_drafts'] ?? [];
    $drafts[$token] = $draft;
    // Validate the candidate map before the first session mutation. A size
    // rejection therefore preserves every existing draft and reservation;
    // this initial store runs before the new flow can reserve a filename.
    estab_attachment_origin_drafts_bytes($drafts, $contexts);
    $session['anhang_origin_drafts'] = $drafts;
}

/** Read, but do not consume, the independently stored draft of one tab. */
function estab_attachment_origin_draft_find(
    array $session,
    array $context
): array {
    $token = estab_attachment_origin_flow_token(
        $context['flow_token'] ?? null
    );
    $drafts = $session['anhang_origin_drafts'] ?? null;
    if ($drafts === null) {
        return [];
    }
    $contexts = $session['anhang_origin_contexts'] ?? null;
    if (!is_array($drafts) || !is_array($contexts)) {
        throw new EstabAttachmentContextException(
            'Die gespeicherten Nachrichtenentwürfe sind ungültig.'
        );
    }
    estab_attachment_origin_drafts_bytes($drafts, $contexts);
    $draft = $drafts[$token] ?? null;
    return is_array($draft) ? $draft : [];
}

/**
 * Remove exactly one completed flow, or only obsolete singleton markers.
 *
 * Omitting a token deliberately leaves every token-indexed tab untouched.
 */
function estab_attachment_origin_context_clear(
    array &$session,
    mixed $token = null
): void {
    unset($session['anhang_origin_context'], $session['anhang_message_context']);
    if ($token === null) {
        return;
    }
    $token = estab_attachment_origin_flow_token($token);
    if (is_array($session['anhang_origin_contexts'] ?? null)) {
        unset($session['anhang_origin_contexts'][$token]);
        if ($session['anhang_origin_contexts'] === []) {
            unset($session['anhang_origin_contexts']);
        }
    }
    if (is_array($session['anhang_origin_drafts'] ?? null)) {
        unset($session['anhang_origin_drafts'][$token]);
        if ($session['anhang_origin_drafts'] === []) {
            unset($session['anhang_origin_drafts']);
        }
    }
}

function estab_attachment_table(string $table): string
{
    return estab_auth_table($table);
}

function estab_attachment_validate_prefix(string $prefix): string
{
    $prefix = strtoupper(trim($prefix));
    if (preg_match('/\A[A-Z0-9_-]{1,24}\z/D', $prefix) !== 1) {
        throw new InvalidArgumentException('Invalid attachment filename prefix');
    }
    return $prefix;
}

function estab_attachment_validate_session_id(string $sessionId): string
{
    if (preg_match('/\A[A-Za-z0-9,_-]{1,128}\z/D', $sessionId) !== 1) {
        throw new InvalidArgumentException('Invalid attachment session id');
    }
    return $sessionId;
}

/** Isolate upload reservations of parallel message tabs in one PHP session. */
function estab_attachment_reservation_owner_id(
    string $sessionId,
    ?array $context = null
): string {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if ($context === null) {
        return $sessionId;
    }
    $token = estab_attachment_origin_flow_token(
        $context['flow_token'] ?? null
    );
    return estab_attachment_validate_session_id(
        'flow-' . hash('sha256', $sessionId . "\0" . $token)
    );
}

/** Release unfinished reservations owned by exactly one message-form flow. */
function estab_attachment_release_origin_reservation(
    mysqli $connection,
    string $table,
    string $sessionId,
    array $context
): void {
    estab_attachment_release(
        $connection,
        $table,
        estab_attachment_reservation_owner_id($sessionId, $context)
    );
}

function estab_attachment_validate_reservation_name(string $filename, ?string $prefix = null): string
{
    $filename = trim($filename);
    if (preg_match('/\A[A-Za-z0-9_-]{2,255}\z/D', $filename) !== 1) {
        throw new InvalidArgumentException('Invalid attachment reservation name');
    }
    if ($prefix !== null) {
        $prefix = estab_attachment_validate_prefix($prefix);
        if (preg_match('/\A' . preg_quote($prefix, '/') . '[0-9]{4,}\z/D', $filename) !== 1) {
            throw new InvalidArgumentException('Attachment reservation does not match its prefix');
        }
    }
    return $filename;
}

/** Deterministically format the next EL0001-style filename. */
function estab_attachment_next_name(string $prefix, int $highest, int $width = 4): string
{
    $prefix = estab_attachment_validate_prefix($prefix);
    if ($highest < 0 || $highest === PHP_INT_MAX || $width < 4 || $width > 12) {
        throw new InvalidArgumentException('Invalid attachment sequence parameters');
    }
    $next = $highest + 1;
    $number = str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    $filename = $prefix . $number;
    if (strlen($filename) > 255) {
        throw new OverflowException('Attachment filename is too long');
    }
    return $filename;
}

function estab_attachment_allowed_extensions(): array
{
    return [
        'jpg', 'jpeg', 'tif', 'tiff', 'gif', 'avi', 'png', 'bmp', 'zip',
        'pdf', 'doc', 'xls', 'odt', 'txt', 'eml', 'xia',
    ];
}

function estab_attachment_extension_is_allowed(string $extension): bool
{
    return in_array(strtolower($extension), estab_attachment_allowed_extensions(), true);
}

/** Merge two semicolon-separated message attachment lists without duplicates. */
function estab_attachment_merge_message_references(
    mixed $existing,
    mixed $selected
): string {
    $references = [];
    foreach ([$existing, $selected] as $list) {
        if (!is_string($list)) {
            continue;
        }
        foreach (explode(';', $list) as $reference) {
            $reference = trim($reference);
            if ($reference === '') {
                continue;
            }
            if (
                str_contains($reference, '/')
                || str_contains($reference, '\\')
            ) {
                continue;
            }
            $base = pathinfo($reference, PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($reference, PATHINFO_EXTENSION));
            try {
                $base = estab_attachment_validate_reservation_name($base);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (
                preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) !== 1
                || !estab_attachment_extension_is_allowed($extension)
            ) {
                continue;
            }
            $canonical = $base . '.' . $extension;
            $references[$canonical] = true;
        }
    }
    return $references === []
        ? ''
        : implode(';', array_keys($references)) . ';';
}

/**
 * Strictly canonicalise a browser-submitted message attachment list.
 *
 * Unlike the compatibility merge helper this rejects, rather than silently
 * dropping, malformed or duplicate object identifiers.
 */
function estab_attachment_canonical_message_references(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_string($value) || strlen($value) > 65535) {
        throw new InvalidArgumentException('Ungültige Anhangliste.');
    }
    $references = [];
    foreach (explode(';', $value) as $reference) {
        $reference = trim($reference);
        if ($reference === '') {
            continue;
        }
        $reference = estab_file_validate_name('attachment', $reference);
        if (isset($references[$reference])) {
            throw new InvalidArgumentException(
                'Ein Anhang darf nur einmal zugeordnet werden.'
            );
        }
        $references[$reference] = true;
        if (count($references) > 100) {
            throw new InvalidArgumentException(
                'Einer Nachricht können höchstens 100 Anhänge zugeordnet werden.'
            );
        }
    }
    return $references === []
        ? ''
        : implode(';', array_keys($references)) . ';';
}

function estab_attachment_database_error_is_retryable(int $code): bool
{
    return in_array($code, [1062, 1205, 1213], true);
}

function estab_attachment_text_is_valid(string $value, int $maxLength, bool $allowEmpty): bool
{
    $length = estab_auth_text_length($value);
    return $length >= ($allowEmpty ? 0 : 1)
        && $length <= $maxLength
        && preg_match('/\p{C}/u', $value) !== 1;
}

/** Convert the legacy ddHHmmMONYYYY timestamp into a strict SQL datetime. */
function estab_attachment_parse_tactical_time(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/\A(\d{2})(\d{2})(\d{2})([A-Za-z]{3})(\d{4})\z/D', $value, $parts)) {
        return null;
    }
    $months = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'mai' => 5, 'jun' => 6, 'jul' => 7,
        'aug' => 8, 'sep' => 9, 'oct' => 10, 'okt' => 10,
        'nov' => 11, 'dec' => 12, 'dez' => 12,
    ];
    $day = (int) $parts[1];
    $hour = (int) $parts[2];
    $minute = (int) $parts[3];
    $month = $months[strtolower($parts[4])] ?? 0;
    $year = (int) $parts[5];
    if ($hour > 23 || $minute > 59 || $year < 1000 || !checkdate($month, $day, $year)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
}

function estab_attachment_validate_sql_datetime(string $value): bool
{
    if (preg_match('/\A([0-9]{4})-/', $value, $parts) !== 1 || (int) $parts[1] < 1000) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value;
}

/**
 * Validate metadata and bind it to the server-side reservation/session user.
 */
function estab_attachment_validate_metadata(
    array $data,
    string $expectedReservation,
    string $sessionCode
): array {
    $expectedReservation = estab_attachment_validate_reservation_name($expectedReservation);

    $storedFilename = isset($data['filename']) && is_string($data['filename'])
        ? basename(str_replace('\\', '/', trim($data['filename'])))
        : '';
    $base = pathinfo($storedFilename, PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($storedFilename, PATHINFO_EXTENSION));
    if (
        $base !== $expectedReservation
        || preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) !== 1
        || !estab_attachment_extension_is_allowed($extension)
    ) {
        throw new InvalidArgumentException('Uploaded filename does not match its reservation');
    }

    $original = isset($data['org_filename']) && is_string($data['org_filename'])
        ? basename(str_replace('\\', '/', trim($data['org_filename'])))
        : '';
    if (!estab_attachment_text_is_valid($original, 255, false)) {
        throw new InvalidArgumentException('Invalid original attachment filename');
    }

    $comment = isset($data['comment']) && is_string($data['comment']) ? trim($data['comment']) : '';
    if (!estab_attachment_text_is_valid($comment, 255, true)) {
        throw new InvalidArgumentException('Invalid attachment comment');
    }

    $sessionCode = strtolower(trim($sessionCode));
    if (preg_match('/\A[a-z0-9_]{1,6}\z/D', $sessionCode) !== 1) {
        throw new InvalidArgumentException('Invalid attachment user code');
    }

    $timestamp = isset($data['time']) && is_string($data['time']) ? trim($data['time']) : '';
    if (!estab_attachment_validate_sql_datetime($timestamp)) {
        throw new InvalidArgumentException('Invalid attachment timestamp');
    }

    $md5 = isset($data['md5hash']) && is_string($data['md5hash'])
        ? strtolower(trim($data['md5hash']))
        : '';
    if (preg_match('/\A[a-f0-9]{32}\z/D', $md5) !== 1) {
        throw new InvalidArgumentException('Invalid attachment digest');
    }
    $sha256 = isset($data['sha256']) && is_string($data['sha256'])
        ? strtolower(trim($data['sha256']))
        : '';
    if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
        throw new InvalidArgumentException('Invalid attachment SHA-256');
    }
    $size = $data['size'] ?? null;
    if (!is_int($size) || $size < 0) {
        throw new InvalidArgumentException('Invalid attachment byte length');
    }

    return [
        'filename' => $expectedReservation,
        'fileext' => $extension,
        'org_filename' => $original,
        'comment' => $comment,
        'md5hash' => $md5,
        'sha256' => $sha256,
        'size' => $size,
        'kuerzel' => $sessionCode,
        'date' => $timestamp,
    ];
}

function estab_attachment_html(mixed $value): string
{
    return estab_auth_html($value);
}

function estab_attachment_statement_error(mysqli_stmt $statement, string $message): never
{
    throw new EstabAttachmentDatabaseException($message, $statement->errno);
}

function estab_attachment_statement_result(
    mysqli_stmt $statement,
    mysqli $connection,
    string $message
): mysqli_result {
    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        throw new EstabAttachmentDatabaseException(
            $message,
            $statement->errno ?: $connection->errno
        );
    }
    return $result;
}

function estab_attachment_statement_row(
    mysqli_stmt $statement,
    mysqli $connection,
    string $message
): ?array {
    $result = estab_attachment_statement_result($statement, $connection, $message);
    try {
        $row = $result->fetch_assoc();
        if ($row === false) {
            throw new EstabAttachmentDatabaseException(
                $message,
                $statement->errno ?: $connection->errno
            );
        }
        return $row;
    } finally {
        $result->free();
    }
}

function estab_attachment_connection(array $databaseConfig): mysqli
{
    return estab_auth_connect($databaseConfig);
}

function estab_attachment_close(mysqli $connection): void
{
    estab_auth_close($connection);
}

function estab_attachment_require_operational_identity(
    mysqli $connection,
    int $incidentId,
    array $identity,
    ?string $expectedCode = null
): void {
    estab_dv_require_operational_account(
        $connection,
        $incidentId,
        $identity
    );
    if ($expectedCode === null) {
        return;
    }
    $shape = estab_auth_session_identity_shape([
        'vStab_benutzer' => $identity['benutzer'] ?? null,
        'vStab_kuerzel' => $identity['kuerzel'] ?? null,
        'vStab_funktion' => $identity['funktion'] ?? null,
        'vStab_rolle' => $identity['rolle'] ?? null,
    ]);
    if (
        $shape === null
        || !hash_equals(
            estab_dv_code($expectedCode),
            (string) $shape['kuerzel']
        )
    ) {
        throw new EstabDvPermissionException(
            'Anhang und angemeldete Dienstidentität stimmen nicht überein.'
        );
    }
}

/** Release an unclaimed reservation after the incident row is locked. */
function estab_attachment_release_unclaimed_for_incident(
    mysqli $connection,
    string $table,
    string $sessionId,
    int $incidentId
): void {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $incidentId = estab_incident_positive_id($incidentId);
    $sql = 'UPDATE ' . estab_attachment_table($table)
        . " SET `status` = 4, `id` = ''"
        . ' WHERE `id` = ? AND `status` = 8 AND `einsatz_id` = ?';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare reservation release', $connection->errno);
    }
    try {
        $statement->bind_param('si', $sessionId, $incidentId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not release old reservation');
        }
    } finally {
        $statement->close();
    }
}

/** Release this session's active-incident reservation atomically. */
function estab_attachment_release_unclaimed(
    mysqli $connection,
    string $table,
    string $sessionId
): void {
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $sessionId
        ): void {
            estab_attachment_release_unclaimed_for_incident(
                $connection,
                $table,
                $sessionId,
                (int) $incident['active_einsatz_id']
            );
        }
    );
}

/**
 * Atomically reserve a reusable or next sequential filename.
 *
 * The unique filename index is the final concurrency guard. Duplicate keys,
 * deadlocks and lock timeouts are retried from a fresh transaction.
 *
 * @param null|callable(int, int): void $retryObserver Receives attempt and
 *     database error code immediately before a retry. Production callers
 *     normally leave this unset; integration tests use it as evidence that
 *     the real rollback/retry branch ran.
 */
function estab_attachment_reserve(
    mysqli $connection,
    string $table,
    string $prefix,
    string $sessionId,
    array $identity,
    int $width = 4,
    int $maxAttempts = 8,
    ?callable $retryObserver = null,
    mixed $expectedIncidentId = null
): string {
    $quotedTable = estab_attachment_table($table);
    $prefix = estab_attachment_validate_prefix($prefix);
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if ($width < 4 || $width > 12) {
        throw new InvalidArgumentException('Invalid attachment sequence width');
    }
    if ($maxAttempts < 1 || $maxAttempts > 50) {
        throw new InvalidArgumentException('Invalid reservation retry count');
    }
    $expectedIncidentId = $expectedIncidentId === null
        ? null
        : estab_incident_positive_id($expectedIncidentId);
    $pattern = '^' . $prefix . '[0-9]{' . $width . ',}$';
    $substringOffset = strlen($prefix) + 1;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        if (!$connection->begin_transaction()) {
            throw new EstabAttachmentDatabaseException('Could not start reservation transaction', $connection->errno);
        }
        try {
            $incident = estab_incident_require_active($connection, true);
            estab_incident_lock_command_post_for_write($connection, $incident);
            $incidentId = (int) $incident['active_einsatz_id'];
            if (
                $expectedIncidentId !== null
                && $expectedIncidentId !== $incidentId
            ) {
                throw new EstabIncidentConflictException(
                    'Der aktive Einsatz hat sich vor dem Upload geändert.'
                );
            }
            estab_attachment_require_operational_identity(
                $connection,
                $incidentId,
                $identity
            );
            // A prior failed NAS cleanup deliberately keeps its reservation
            // at status 8. Reuse that exact owner-bound name instead of
            // exposing it as free while stale bytes may still exist.
            $ownedSql = 'SELECT `filename` FROM ' . $quotedTable
                . ' WHERE `status` = 8 AND `id` = ? AND `einsatz_id` = ?'
                . ' AND `filename` REGEXP BINARY ?'
                . ' ORDER BY CAST(SUBSTRING(`filename`, ?) AS UNSIGNED),'
                . ' `filename` LIMIT 1 FOR UPDATE';
            $owned = $connection->prepare($ownedSql);
            if (!$owned) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare owned reservation lookup',
                    $connection->errno
                );
            }
            try {
                $owned->bind_param(
                    'sisi',
                    $sessionId,
                    $incidentId,
                    $pattern,
                    $substringOffset
                );
                if (!$owned->execute()) {
                    estab_attachment_statement_error(
                        $owned,
                        'Could not find owned reservation'
                    );
                }
                $ownedRow = estab_attachment_statement_row(
                    $owned,
                    $connection,
                    'Could not read owned reservation result'
                );
            } finally {
                $owned->close();
            }
            if (is_array($ownedRow ?? null)) {
                $candidate = estab_attachment_validate_reservation_name(
                    (string) $ownedRow['filename'],
                    $prefix
                );
                if (!$connection->commit()) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not commit owned reservation reuse',
                        $connection->errno
                    );
                }
                return $candidate;
            }

            $reuseSql = 'SELECT `filename` FROM ' . $quotedTable
                . ' WHERE `status` = 4 AND `einsatz_id` = ?'
                . ' AND `filename` REGEXP BINARY ?'
                . ' ORDER BY CAST(SUBSTRING(`filename`, ?) AS UNSIGNED), `filename` LIMIT 1 FOR UPDATE';
            $reuse = $connection->prepare($reuseSql);
            if (!$reuse) {
                throw new EstabAttachmentDatabaseException('Could not prepare reusable reservation lookup', $connection->errno);
            }
            try {
                $reuse->bind_param('isi', $incidentId, $pattern, $substringOffset);
                if (!$reuse->execute()) {
                    estab_attachment_statement_error($reuse, 'Could not find reusable reservation');
                }
                $row = estab_attachment_statement_row(
                    $reuse,
                    $connection,
                    'Could not read reusable reservation result'
                );
            } finally {
                $reuse->close();
            }

            if (is_array($row ?? null)) {
                $candidate = estab_attachment_validate_reservation_name((string) $row['filename'], $prefix);
                $updateSql = 'UPDATE ' . $quotedTable
                    . " SET `status` = 8, `id` = ?, `fileext` = '',"
                    . " `org_filename` = '', `comment` = '', `md5hash` = '',"
                    . ' `date` = NULL, `kuerzel` = NULL'
                    . ' WHERE `filename` = ? AND `status` = 4'
                    . ' AND `einsatz_id` = ?';
                $update = $connection->prepare($updateSql);
                if (!$update) {
                    throw new EstabAttachmentDatabaseException('Could not prepare reusable reservation', $connection->errno);
                }
                try {
                    $update->bind_param('ssi', $sessionId, $candidate, $incidentId);
                    if (!$update->execute()) {
                        estab_attachment_statement_error($update, 'Could not reserve reusable filename');
                    }
                    $reserved = $update->affected_rows === 1;
                } finally {
                    $update->close();
                }
                if ($reserved) {
                    if (!$connection->commit()) {
                        throw new EstabAttachmentDatabaseException('Could not commit reusable reservation', $connection->errno);
                    }
                    return $candidate;
                }
                throw new EstabAttachmentDatabaseException('Reusable reservation changed concurrently', 1213);
            }

            $highestSql = 'SELECT `filename` FROM ' . $quotedTable
                . ' WHERE `filename` REGEXP BINARY ?'
                . ' ORDER BY CAST(SUBSTRING(`filename`, ?) AS UNSIGNED) DESC LIMIT 1 FOR UPDATE';
            $highestStatement = $connection->prepare($highestSql);
            if (!$highestStatement) {
                throw new EstabAttachmentDatabaseException('Could not prepare filename sequence lookup', $connection->errno);
            }
            try {
                $highestStatement->bind_param('si', $pattern, $substringOffset);
                if (!$highestStatement->execute()) {
                    estab_attachment_statement_error($highestStatement, 'Could not read filename sequence');
                }
                $highestRow = estab_attachment_statement_row(
                    $highestStatement,
                    $connection,
                    'Could not read filename sequence result'
                );
            } finally {
                $highestStatement->close();
            }

            $highest = 0;
            if (is_array($highestRow ?? null)) {
                $highestName = estab_attachment_validate_reservation_name((string) $highestRow['filename'], $prefix);
                $highest = (int) substr($highestName, strlen($prefix));
            }
            $candidate = estab_attachment_next_name($prefix, $highest, $width);

            $insertSql = 'INSERT INTO ' . $quotedTable
                . ' (`einsatz_id`, `filename`, `status`, `id`)'
                . ' VALUES (?, ?, 8, ?)';
            $insert = $connection->prepare($insertSql);
            if (!$insert) {
                throw new EstabAttachmentDatabaseException('Could not prepare filename reservation', $connection->errno);
            }
            try {
                $insert->bind_param('iss', $incidentId, $candidate, $sessionId);
                if (!$insert->execute()) {
                    estab_attachment_statement_error($insert, 'Could not reserve next filename');
                }
            } finally {
                $insert->close();
            }
            if (!$connection->commit()) {
                throw new EstabAttachmentDatabaseException('Could not commit filename reservation', $connection->errno);
            }
            return $candidate;
        } catch (Throwable $exception) {
            $connection->rollback();
            if (
                ($exception instanceof EstabAttachmentDatabaseException
                    || $exception instanceof mysqli_sql_exception)
                && estab_attachment_database_error_is_retryable($exception->getCode())
                && $attempt < $maxAttempts
            ) {
                if ($retryObserver !== null) {
                    $retryObserver($attempt, (int) $exception->getCode());
                }
                continue;
            }
            throw $exception;
        }
    }
    throw new EstabAttachmentDatabaseException('Attachment reservation attempts exhausted');
}

/** Resolve the incident of one exact unfinished, server-owned reservation. */
function estab_attachment_owned_reservation_incident_id(
    mysqli $connection,
    string $table,
    string $sessionId,
    string $filename
): ?int {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $filename = estab_attachment_validate_reservation_name($filename);
    $statement = $connection->prepare(
        'SELECT `einsatz_id` FROM ' . estab_attachment_table($table)
        . ' WHERE `filename` = ? AND `id` = ?'
        . ' AND `status` IN (2, 8) LIMIT 1'
    );
    if (!$statement) {
        throw new EstabAttachmentDatabaseException(
            'Could not prepare owned reservation lookup',
            $connection->errno
        );
    }
    try {
        $statement->bind_param('ss', $filename, $sessionId);
        if (!$statement->execute()) {
            estab_attachment_statement_error(
                $statement,
                'Could not resolve owned reservation incident'
            );
        }
        $row = estab_attachment_statement_row(
            $statement,
            $connection,
            'Could not read owned reservation incident'
        );
        return is_array($row)
            ? estab_incident_positive_id($row['einsatz_id'] ?? null)
            : null;
    } finally {
        $statement->close();
    }
}

/**
 * Persist the staged suffix before any bytes are moved to shared storage.
 *
 * A retained reservation may originate from an interrupted cleanup. Its
 * existing suffix is authoritative so the caller can remove those exact
 * stale bytes before allowing another upload to reuse the internal name.
 */
function estab_attachment_prepare_staged_extension(
    mysqli $connection,
    string $table,
    string $sessionId,
    string $filename,
    mixed $incidentId,
    string $extension,
    array $identity
): string {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $filename = estab_attachment_validate_reservation_name($filename);
    $incidentId = estab_incident_positive_id($incidentId);
    $extension = strtolower($extension);
    if (!estab_attachment_extension_is_allowed($extension)) {
        throw new InvalidArgumentException('Invalid staged attachment extension');
    }
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $sessionId,
            $filename,
            $incidentId,
            $extension,
            $identity
        ): string {
            if ((int) $incident['active_einsatz_id'] !== $incidentId) {
                throw new EstabIncidentConflictException(
                    'Der aktive Einsatz hat sich vor dem Upload geändert.'
                );
            }
            estab_attachment_require_operational_identity(
                $connection,
                $incidentId,
                $identity
            );
            $statement = $connection->prepare(
                'SELECT `fileext` FROM ' . estab_attachment_table($table)
                . ' WHERE `filename` = ? AND `id` = ? AND `status` = 8'
                . ' AND `einsatz_id` = ? FOR UPDATE'
            );
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare staged extension lookup',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'ssi',
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not read staged attachment extension'
                    );
                }
                $row = estab_attachment_statement_row(
                    $statement,
                    $connection,
                    'Could not fetch staged attachment extension'
                );
            } finally {
                $statement->close();
            }
            if (!is_array($row)) {
                throw new EstabAttachmentContextException(
                    'Die Upload-Reservierung ist nicht mehr verfügbar.'
                );
            }
            $stored = strtolower((string) ($row['fileext'] ?? ''));
            if ($stored !== '') {
                if (!estab_attachment_extension_is_allowed($stored)) {
                    throw new EstabAttachmentDatabaseException(
                        'Owned reservation carries an invalid staged extension'
                    );
                }
                return $stored;
            }
            $update = $connection->prepare(
                'UPDATE ' . estab_attachment_table($table)
                . ' SET `fileext` = ? WHERE `filename` = ? AND `id` = ?'
                . ' AND `status` = 8 AND `einsatz_id` = ?'
            );
            if (!$update) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare staged extension update',
                    $connection->errno
                );
            }
            try {
                $update->bind_param(
                    'sssi',
                    $extension,
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not persist staged attachment extension',
                        $update->errno
                    );
                }
            } finally {
                $update->close();
            }
            return $extension;
        }
    );
}

/**
 * Atomically claim cleanup authority without treating a completed file as stale.
 *
 * Reusing an owner-bound status-8 row and removing its staged bytes must not
 * race. Move the exact unfinished row to status 2 while holding its row lock;
 * new reservations only reuse status 8, so no later uploader can publish the
 * same path between this decision and the caller's unlink operation.
 */
function estab_attachment_reservation_cleanup_state(
    mysqli $connection,
    string $table,
    string $sessionId,
    string $filename,
    mixed $incidentId
): array {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $filename = estab_attachment_validate_reservation_name($filename);
    $incidentId = estab_incident_positive_id($incidentId);
    if (!$connection->begin_transaction()) {
        throw new EstabAttachmentDatabaseException(
            'Could not start reservation cleanup claim',
            $connection->errno
        );
    }
    $transactionActive = true;
    try {
        $statement = $connection->prepare(
            'SELECT `status`, `id`, `fileext` FROM '
            . estab_attachment_table($table)
            . ' WHERE `filename` = ? AND `einsatz_id` = ? LIMIT 1 FOR UPDATE'
        );
        if (!$statement) {
            throw new EstabAttachmentDatabaseException(
                'Could not prepare reservation cleanup lookup',
                $connection->errno
            );
        }
        try {
            $statement->bind_param('si', $filename, $incidentId);
            if (!$statement->execute()) {
                estab_attachment_statement_error(
                    $statement,
                    'Could not resolve reservation cleanup state'
                );
            }
            $row = estab_attachment_statement_row(
                $statement,
                $connection,
                'Could not read reservation cleanup state'
            );
        } finally {
            $statement->close();
        }
        $state = ['state' => 'unsafe', 'extension' => null];
        if (!is_array($row)) {
            $state['state'] = 'missing';
        } elseif ((int) ($row['status'] ?? 0) === 1) {
            $state['state'] = 'finalized';
        } elseif (
            (int) ($row['status'] ?? 0) === 8
            && is_string($row['id'] ?? null)
            && hash_equals($sessionId, (string) $row['id'])
        ) {
            $extension = strtolower((string) ($row['fileext'] ?? ''));
            if (
                $extension === ''
                || estab_attachment_extension_is_allowed($extension)
            ) {
                $claim = $connection->prepare(
                    'UPDATE ' . estab_attachment_table($table)
                    . ' SET `status` = 2 WHERE `filename` = ? AND `id` = ?'
                    . ' AND `status` = 8 AND `einsatz_id` = ?'
                );
                if (!$claim) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not prepare reservation cleanup claim',
                        $connection->errno
                    );
                }
                try {
                    $claim->bind_param(
                        'ssi',
                        $filename,
                        $sessionId,
                        $incidentId
                    );
                    if (!$claim->execute() || $claim->affected_rows !== 1) {
                        throw new EstabAttachmentDatabaseException(
                            'Could not claim reservation cleanup',
                            $claim->errno
                        );
                    }
                } finally {
                    $claim->close();
                }
                $state = [
                    'state' => 'owned-unfinished',
                    'extension' => $extension === '' ? null : $extension,
                ];
            }
        }
        if (!$connection->commit()) {
            throw new EstabAttachmentDatabaseException(
                'Could not commit reservation cleanup claim',
                $connection->errno
            );
        }
        $transactionActive = false;
        return $state;
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        if ($transactionActive) {
            $connection->rollback();
        }
    }
}

/** Atomically claim an owned active reservation before moving upload bytes. */
function estab_attachment_claim(
    mysqli $connection,
    string $table,
    string $filename,
    string $sessionId,
    array $identity
): bool {
    $filename = estab_attachment_validate_reservation_name($filename);
    $sessionId = estab_attachment_validate_session_id($sessionId);
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $filename,
            $sessionId,
            $identity
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            estab_attachment_require_operational_identity(
                $connection,
                $incidentId,
                $identity
            );
            $sql = 'UPDATE ' . estab_attachment_table($table)
                . ' SET `status` = 2'
                . ' WHERE `filename` = ? AND `status` = 8 AND `id` = ?'
                . ' AND `einsatz_id` = ?';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare reservation claim',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'ssi',
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not claim reservation'
                    );
                }
                return $statement->affected_rows === 1;
            } finally {
                $statement->close();
            }
        }
    );
}

/** Release only this session's unfinished reservation/claim. */
function estab_attachment_release(
    mysqli $connection,
    string $table,
    string $sessionId,
    ?string $filename = null
): void {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if ($filename !== null) {
        $filename = estab_attachment_validate_reservation_name($filename);
    }
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $sessionId,
            $filename
        ): void {
            $incidentId = (int) $incident['active_einsatz_id'];
            if ($filename === null) {
                $sql = 'UPDATE ' . estab_attachment_table($table)
                    . " SET `status` = 4, `id` = ''"
                    . ' WHERE `id` = ? AND `status` IN (2, 8)'
                    . ' AND `einsatz_id` = ?';
                $statement = $connection->prepare($sql);
                if (!$statement) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not prepare reservation cancellation',
                        $connection->errno
                    );
                }
                try {
                    $statement->bind_param('si', $sessionId, $incidentId);
                    if (!$statement->execute()) {
                        estab_attachment_statement_error(
                            $statement,
                            'Could not cancel reservations'
                        );
                    }
                } finally {
                    $statement->close();
                }
                return;
            }

            $sql = 'UPDATE ' . estab_attachment_table($table)
                . " SET `status` = 4, `id` = ''"
                . ' WHERE `filename` = ? AND `id` = ?'
                . ' AND `status` IN (2, 8) AND `einsatz_id` = ?';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare reservation release',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'ssi',
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not release reservation'
                    );
                }
            } finally {
                $statement->close();
            }
        }
    );
}

/**
 * Release one unfinished reservation in its captured incident.
 *
 * Browser uploads remember the incident that owned the reservation. If the
 * global active incident changes before finalisation, cleanup must target
 * that captured row instead of whichever incident happens to be active now.
 * The unguessable owner id, exact filename and unfinished state remain
 * mandatory; completed attachment metadata can never be changed here.
 */
function estab_attachment_release_for_incident(
    mysqli $connection,
    string $table,
    string $sessionId,
    string $filename,
    mixed $incidentId
): void {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $filename = estab_attachment_validate_reservation_name($filename);
    $incidentId = estab_incident_positive_id($incidentId);
    $statement = $connection->prepare(
        'UPDATE ' . estab_attachment_table($table)
        . " SET `status` = 4, `id` = ''"
        . ' WHERE `filename` = ? AND `id` = ?'
        . ' AND `status` IN (2, 8) AND `einsatz_id` = ?'
    );
    if (!$statement) {
        throw new EstabAttachmentDatabaseException(
            'Could not prepare incident-bound reservation release',
            $connection->errno
        );
    }
    try {
        $statement->bind_param(
            'ssi',
            $filename,
            $sessionId,
            $incidentId
        );
        if (!$statement->execute()) {
            estab_attachment_statement_error(
                $statement,
                'Could not release incident-bound reservation'
            );
        }
    } finally {
        $statement->close();
    }
}

/** Finalise only the current session's claimed reservation. */
function estab_attachment_finalize(
    mysqli $connection,
    string $table,
    string $sessionId,
    array $metadata,
    array $identity
): bool {
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $filename = estab_attachment_validate_reservation_name((string) ($metadata['filename'] ?? ''));
    $extension = (string) $metadata['fileext'];
    $original = (string) $metadata['org_filename'];
    $comment = (string) $metadata['comment'];
    $md5 = (string) $metadata['md5hash'];
    $sha256 = isset($metadata['sha256'])
        && is_string($metadata['sha256'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $metadata['sha256']) === 1
        ? $metadata['sha256']
        : throw new InvalidArgumentException('Invalid attachment SHA-256');
    $size = $metadata['size'] ?? null;
    if (!is_int($size) || $size < 0) {
        throw new InvalidArgumentException('Invalid attachment byte length');
    }
    $code = (string) $metadata['kuerzel'];
    $date = (string) $metadata['date'];
    return estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $table,
            $extension,
            $original,
            $comment,
            $md5,
            $sha256,
            $size,
            $code,
            $date,
            $filename,
            $sessionId,
            $identity
        ): bool {
            $incidentId = (int) $incident['active_einsatz_id'];
            estab_attachment_require_operational_identity(
                $connection,
                $incidentId,
                $identity,
                $code
            );
            $sql = 'UPDATE ' . estab_attachment_table($table)
                . ' SET `fileext` = ?, `org_filename` = ?, `comment` = ?,'
                . ' `md5hash` = ?, `integrity_required` = 1,'
                . ' `ingest_sha256` = ?, `ingest_size` = ?,'
                . ' `integrity_captured_at` = NOW(6),'
                . " `kuerzel` = ?, `date` = ?, `status` = 1, `id` = ''"
                . ' WHERE `filename` = ? AND `status` = 2 AND `id` = ?'
                . ' AND `einsatz_id` = ?';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare attachment finalisation',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'sssssissssi',
                    $extension,
                    $original,
                    $comment,
                    $md5,
                    $sha256,
                    $size,
                    $code,
                    $date,
                    $filename,
                    $sessionId,
                    $incidentId
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not finalise attachment'
                    );
                }
                return $statement->affected_rows === 1;
            } finally {
                $statement->close();
            }
        }
    );
}

/**
 * Store one browser upload while the global incident cannot change.
 *
 * The callback supplies already prepared metadata. The browser-upload service
 * stages and hashes bytes before calling this function so large NAS files do
 * not hold operational database locks. Claim, metadata row, audit row and the
 * active incident are one short transaction. On failure the complete
 * transaction rolls back to the still-owned, unreadable reservation. The
 * caller removes staged bytes first and only then releases that reservation.
 *
 * @param callable():array<string,mixed> $storeAndDescribe
 * @param callable(array<string,string>):string $auditDetails
 * @return array<string,string>|null
 */
function estab_attachment_store_upload(
    mysqli $connection,
    string $attachmentTable,
    string $protocolTable,
    string $reservation,
    string $sessionId,
    string $sessionCode,
    array $identity,
    string $event,
    callable $storeAndDescribe,
    callable $auditDetails,
    mixed $expectedIncidentId = null
): ?array {
    $reservation = estab_attachment_validate_reservation_name($reservation);
    $sessionId = estab_attachment_validate_session_id($sessionId);
    if (!estab_attachment_text_is_valid($event, 30, false)) {
        throw new InvalidArgumentException('Invalid attachment audit event');
    }
    if (!$connection->begin_transaction()) {
        throw new EstabAttachmentDatabaseException(
            'Could not start atomic upload transaction',
            $connection->errno
        );
    }
    $transactionActive = true;
    try {
        $incident = estab_incident_require_active($connection, true);
        estab_incident_lock_command_post_for_write($connection, $incident);
        $incidentId = (int) $incident['active_einsatz_id'];
        if (
            $expectedIncidentId !== null
            && estab_incident_positive_id($expectedIncidentId) !== $incidentId
        ) {
            throw new EstabIncidentConflictException(
                'Der aktive Einsatz hat sich während des Uploads geändert.'
            );
        }
        estab_attachment_require_operational_identity(
            $connection,
            $incidentId,
            $identity,
            $sessionCode
        );
        $claim = $connection->prepare(
            'UPDATE ' . estab_attachment_table($attachmentTable)
            . ' SET `status` = 2'
            . ' WHERE `filename` = ? AND `status` = 8 AND `id` = ?'
            . ' AND `einsatz_id` = ?'
        );
        if (!$claim) {
            throw new EstabAttachmentDatabaseException(
                'Could not prepare atomic upload claim',
                $connection->errno
            );
        }
        try {
            $claim->bind_param(
                'ssi',
                $reservation,
                $sessionId,
                $incidentId
            );
            if (!$claim->execute()) {
                estab_attachment_statement_error(
                    $claim,
                    'Could not claim atomic upload reservation'
                );
            }
            $claimed = $claim->affected_rows === 1;
        } finally {
            $claim->close();
        }
        if (!$claimed) {
            $connection->rollback();
            $transactionActive = false;
            return null;
        }

        $rawMetadata = $storeAndDescribe();
            if (!is_array($rawMetadata)) {
                throw new RuntimeException('Upload callback returned no metadata');
            }
            $metadata = estab_attachment_validate_metadata(
                $rawMetadata,
                $reservation,
                $sessionCode
            );

            $finalize = $connection->prepare(
                'UPDATE ' . estab_attachment_table($attachmentTable)
                . ' SET `fileext` = ?, `org_filename` = ?, `comment` = ?,'
                . ' `md5hash` = ?, `integrity_required` = 1,'
                . ' `ingest_sha256` = ?, `ingest_size` = ?,'
                . ' `integrity_captured_at` = NOW(6),'
                . ' `kuerzel` = ?, `date` = ?,'
                . " `status` = 1, `id` = ''"
                . ' WHERE `filename` = ? AND `status` = 2 AND `id` = ?'
                . ' AND `einsatz_id` = ?'
            );
            if (!$finalize) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare atomic upload finalisation',
                    $connection->errno
                );
            }
            try {
                $finalize->bind_param(
                    'sssssissssi',
                    $metadata['fileext'],
                    $metadata['org_filename'],
                    $metadata['comment'],
                    $metadata['md5hash'],
                    $metadata['sha256'],
                    $metadata['size'],
                    $metadata['kuerzel'],
                    $metadata['date'],
                    $metadata['filename'],
                    $sessionId,
                    $incidentId
                );
                if (
                    !$finalize->execute()
                    || $finalize->affected_rows !== 1
                ) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not finalise atomic upload',
                        $finalize->errno
                    );
                }
            } finally {
                $finalize->close();
            }

            $details = $auditDetails($metadata);
            if (
                !is_string($details)
                || !estab_attachment_text_is_valid($details, 65535, true)
            ) {
                throw new InvalidArgumentException(
                    'Invalid atomic upload audit details'
                );
            }
            $audit = $connection->prepare(
                'INSERT INTO ' . estab_attachment_table($protocolTable)
                . ' (`einsatz_id`, `p_zeit`, `p_was`, `p_ereignis`)'
                . ' VALUES (?, NOW(), ?, ?)'
            );
            if (!$audit) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare atomic upload audit',
                    $connection->errno
                );
            }
            try {
                $audit->bind_param('iss', $incidentId, $event, $details);
                if (!$audit->execute()) {
                    estab_attachment_statement_error(
                        $audit,
                        'Could not write atomic upload audit'
                    );
                }
            } finally {
                $audit->close();
            }

            if (!$connection->commit()) {
                throw new EstabAttachmentDatabaseException(
                    'Could not commit atomic upload',
                    $connection->errno
                );
            }
            $transactionActive = false;
            return $metadata;
    } catch (Throwable $exception) {
        if ($transactionActive) {
            $connection->rollback();
            $transactionActive = false;
        }
        throw $exception;
    } finally {
        if ($transactionActive) {
            $connection->rollback();
        }
    }
}

function estab_attachment_list_for_incident(
    mysqli $connection,
    string $table,
    mixed $incidentId
): array
{
    $incidentId = estab_incident_positive_id($incidentId);
    $sql = 'SELECT `filename`, `fileext`, `org_filename`, `comment`,'
        . ' `md5hash`, `date`, `kuerzel`'
        . ' FROM ' . estab_attachment_table($table)
        . ' WHERE `status` = 1 AND `einsatz_id` = ?'
        . ' ORDER BY `filename` DESC';
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment listing', $connection->errno);
    }
    try {
        $statement->bind_param('i', $incidentId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not list attachments');
        }
        $result = estab_attachment_statement_result(
            $statement,
            $connection,
            'Could not read attachment listing result'
        );
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

function estab_attachment_list(mysqli $connection, string $table): array
{
    $incident = estab_incident_active($connection);
    return $incident === null
        ? []
        : estab_attachment_list_for_incident(
            $connection,
            $table,
            $incident['active_einsatz_id']
        );
}

function estab_attachment_find_for_incident(
    mysqli $connection,
    string $table,
    string $filename,
    mixed $incidentId,
    bool $forUpdate = false
): ?array
{
    $filename = estab_attachment_validate_reservation_name($filename);
    $incidentId = estab_incident_positive_id($incidentId);
    $sql = 'SELECT `filename`, `fileext`, `org_filename`, `comment`,'
        . ' `md5hash`, `integrity_required`, `ingest_sha256`,'
        . ' `ingest_size`, `integrity_captured_at`, `date`, `kuerzel`'
        . ' FROM ' . estab_attachment_table($table)
        . ' WHERE `filename` = ? AND `status` = 1'
        . ' AND `einsatz_id` = ? LIMIT 1'
        . ($forUpdate ? ' FOR UPDATE' : '');
    $statement = $connection->prepare($sql);
    if (!$statement) {
        throw new EstabAttachmentDatabaseException('Could not prepare attachment lookup', $connection->errno);
    }
    try {
        $statement->bind_param('si', $filename, $incidentId);
        if (!$statement->execute()) {
            estab_attachment_statement_error($statement, 'Could not find attachment');
        }
        $row = estab_attachment_statement_row(
            $statement,
            $connection,
            'Could not read attachment lookup result'
        );
        if (
            !is_array($row)
            || !isset($row['filename'])
            || !is_string($row['filename'])
            || !hash_equals($filename, $row['filename'])
        ) {
            return null;
        }
        return $row;
    } finally {
        $statement->close();
    }
}

function estab_attachment_find(
    mysqli $connection,
    string $table,
    string $filename,
    bool $forUpdate = false
): ?array {
    $filename = estab_attachment_validate_reservation_name($filename);
    $incident = $forUpdate
        ? estab_incident_require_active($connection, true)
        : estab_incident_active($connection);
    return $incident === null
        ? null
        : estab_attachment_find_for_incident(
            $connection,
            $table,
            $filename,
            $incident['active_einsatz_id'],
            $forUpdate
        );
}

/** Prepared audit insert for attachment-influenced values. */
function estab_attachment_log(
    mysqli $connection,
    string $protocolTable,
    string $event,
    string $details,
    ?string $attachmentTable = null,
    ?string $attachmentFilename = null
): void {
    if (!estab_attachment_text_is_valid($event, 30, false)) {
        throw new InvalidArgumentException('Invalid attachment audit event');
    }
    if (!estab_attachment_text_is_valid($details, 65535, true)) {
        throw new InvalidArgumentException('Invalid attachment audit details');
    }
    if (($attachmentTable === null) !== ($attachmentFilename === null)) {
        throw new InvalidArgumentException('Incomplete attachment audit scope');
    }
    if ($attachmentFilename !== null) {
        $attachmentFilename = estab_attachment_validate_reservation_name(
            $attachmentFilename
        );
    }
    $timestamp = date('Y-m-d H:i:s');
    estab_incident_with_active_write(
        $connection,
        static function (array $incident) use (
            $connection,
            $protocolTable,
            $event,
            $details,
            $timestamp,
            $attachmentTable,
            $attachmentFilename
        ): void {
            $incidentId = (int) $incident['active_einsatz_id'];
            if ($attachmentTable !== null && $attachmentFilename !== null) {
                $scope = $connection->prepare(
                    'SELECT COUNT(*) AS `scope_count` FROM '
                    . estab_attachment_table($attachmentTable)
                    . ' WHERE `filename` = ? AND `einsatz_id` = ?'
                );
                if (!$scope) {
                    throw new EstabAttachmentDatabaseException(
                        'Could not prepare attachment audit scope',
                        $connection->errno
                    );
                }
                try {
                    $scope->bind_param(
                        'si',
                        $attachmentFilename,
                        $incidentId
                    );
                    if (!$scope->execute()) {
                        estab_attachment_statement_error(
                            $scope,
                            'Could not verify attachment audit scope'
                        );
                    }
                    $row = estab_attachment_statement_row(
                        $scope,
                        $connection,
                        'Could not read attachment audit scope'
                    );
                    if ((int) ($row['scope_count'] ?? 0) !== 1) {
                        throw new EstabAttachmentDatabaseException(
                            'Attachment incident changed before audit'
                        );
                    }
                } finally {
                    $scope->close();
                }
            }

            $sql = 'INSERT INTO ' . estab_attachment_table($protocolTable)
                . ' (`einsatz_id`, `p_zeit`, `p_was`, `p_ereignis`)'
                . ' VALUES (?, ?, ?, ?)';
            $statement = $connection->prepare($sql);
            if (!$statement) {
                throw new EstabAttachmentDatabaseException(
                    'Could not prepare attachment audit insert',
                    $connection->errno
                );
            }
            try {
                $statement->bind_param(
                    'isss',
                    $incidentId,
                    $timestamp,
                    $event,
                    $details
                );
                if (!$statement->execute()) {
                    estab_attachment_statement_error(
                        $statement,
                        'Could not write attachment audit event'
                    );
                }
            } finally {
                $statement->close();
            }
        }
    );
}
