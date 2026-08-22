<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/dv_operations.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/read_authorization.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_session_ui_start($_SESSION);
estab_navigation_require_session(
    $_SESSION,
    'command-post',
    $_SERVER
);
$identity = estab_auth_session_identity($_SESSION);
if ($identity === null) {
    throw new LogicException('Authenticated command-post identity missing');
}
$operationIdentity = estab_read_session_identity($_SESSION) ?? $identity;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function dv_operations_redirect(
    string $result,
    string $fragment = '',
    array $parameters = []
): never
{
    $query = http_build_query(
        ['result' => $result] + $parameters,
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    header(
        'Location: fuehrungsstelle.php?' . $query
            . ($fragment === '' ? '' : '#' . rawurlencode($fragment)),
        true,
        303
    );
    exit;
}

/** Continue to the originally requested area after selecting a STRICT hat. */
function dv_operations_redirect_after_hat(array $selectedIdentity): never
{
    $candidate = $_SESSION['estab_pending_navigation_key'] ?? null;
    unset($_SESSION['estab_pending_navigation_key']);
    $key = estab_navigation_login_destination_key($candidate);
    if ($key !== null && $key !== 'command-post') {
        $item = estab_navigation_item_for_key($key);
        if (
            is_array($item)
            && estab_navigation_duty_access_allowed($item, $selectedIdentity)
        ) {
            header(
                'Location: ' . estab_navigation_url_for_key($key),
                true,
                303
            );
            exit;
        }
    }
    dv_operations_redirect('hat_selected', 'meine-dienstfunktionen');
}

function dv_operations_html(mixed $value): string
{
    return estab_auth_html($value);
}

function dv_operations_datetime_input(mixed $value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    return str_replace(' ', 'T', substr($value, 0, 16));
}

function dv_operations_datetime_display(mixed $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return 'Nicht dokumentiert';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y · H:i') . ' Uhr';
    } catch (Throwable) {
        return $value;
    }
}

function dv_operations_is_telecom_revision_action(mixed $action): bool
{
    return is_string($action) && in_array(
        $action,
        [
            'update_plan',
            'add_plan_entry',
            'update_plan_entry',
            'delete_plan_entry',
            'discard_plan',
            'activate_plan',
        ],
        true
    );
}

/** @param list<array<string, mixed>> $plans */
function dv_operations_posted_telecom_revision_is_stale(array $plans): bool
{
    global $error, $requestMethod;
    if (
        $requestMethod !== 'POST'
        || $error === null
        || !dv_operations_is_telecom_revision_action(
            $_POST['operation_action'] ?? null
        )
    ) {
        return false;
    }
    $postedPlanId = filter_var(
        $_POST['fernmeldeplan_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $postedRevision = $_POST['plan_revision'] ?? null;
    if (!is_int($postedPlanId) || !is_string($postedRevision)) {
        return true;
    }
    foreach ($plans as $plan) {
        if ((int) ($plan['fernmeldeplan_id'] ?? 0) !== $postedPlanId) {
            continue;
        }
        $currentRevision = $plan['revision'] ?? null;
        return ($plan['status'] ?? null) !== 'ENTWURF'
            || !is_string($currentRevision)
            || !hash_equals($currentRevision, $postedRevision);
    }
    return true;
}

function dv_operations_failed_post(
    string $action,
    ?int $planId = null,
    ?int $entryId = null
): bool {
    global $error, $requestMethod, $telecomRevisionConflict;
    if (
        $requestMethod !== 'POST'
        || $error === null
        || $telecomRevisionConflict
        || ($_POST['operation_action'] ?? null) !== $action
    ) {
        return false;
    }
    if (
        $planId !== null
        && (string) ($_POST['fernmeldeplan_id'] ?? '') !== (string) $planId
    ) {
        return false;
    }
    return $entryId === null
        || (string) ($_POST['fernmeldeplan_eintrag_id'] ?? '')
            === (string) $entryId;
}

function dv_operations_post_value(
    string $action,
    string $name,
    mixed $fallback,
    ?int $planId = null,
    ?int $entryId = null
): mixed {
    if (!dv_operations_failed_post($action, $planId, $entryId)) {
        return $fallback;
    }
    $value = $_POST[$name] ?? null;
    return is_string($value) ? $value : $fallback;
}

/** @param array<string, mixed> $values */
function dv_operations_render_telecom_entry_fields(array $values): void
{
    $medium = is_string($values['medium'] ?? null)
        && isset(ESTAB_DV_MEDIA_DEFINITIONS[$values['medium']])
        ? $values['medium']
        : '';
    $definition = $medium === ''
        ? null
        : ESTAB_DV_MEDIA_DEFINITIONS[$medium];
    $channelVisible = $definition === null
        || $definition['kanal'] !== null;
    $bandVisible = $definition === null
        || $definition['bandlage'] !== null;
    $channelRequired = $definition !== null
        && $definition['kanal'] !== null;
    $bandRequired = $definition !== null
        && $definition['bandlage'] !== null;
    ?>
    <div class="estab-tool-form-grid">
      <label>Betriebsstellen-Klarbezeichnung
        <input name="betriebsstelle" maxlength="255" required
          value="<?= dv_operations_html($values['betriebsstelle'] ?? '') ?>">
      </label>
      <label>Rufname
        <input name="rufname" maxlength="128" required
          value="<?= dv_operations_html($values['rufname'] ?? '') ?>">
      </label>
      <label>Übertragungsmedium
        <select name="medium" required data-estab-telecom-medium>
          <option value="" <?= $medium === '' ? 'selected' : '' ?>>
            Medium auswählen
          </option>
          <?php foreach (ESTAB_DV_MEDIA as $candidate): ?>
            <option value="<?= dv_operations_html($candidate) ?>"
              <?= $candidate === $medium ? 'selected' : '' ?>>
              <?= dv_operations_html(
                  estab_dv_telecom_medium_label($candidate)
              ) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label data-estab-telecom-field="kanal"
        <?= $channelVisible ? '' : 'hidden' ?>>
        <span data-estab-telecom-field-label="kanal"><?= dv_operations_html(
            $definition['kanal'] ?? 'Kanal oder Rufgruppe'
        ) ?></span>
        <input name="kanal" maxlength="64"
          value="<?= dv_operations_html($values['kanal'] ?? '') ?>"
          <?= $channelVisible
              ? ($channelRequired ? 'required' : '')
              : 'disabled' ?>>
      </label>
      <label data-estab-telecom-field="bandlage"
        <?= $bandVisible ? '' : 'hidden' ?>>
        <span data-estab-telecom-field-label="bandlage"><?= dv_operations_html(
            $definition['bandlage'] ?? 'Bandlage'
        ) ?></span>
        <input name="bandlage" maxlength="64"
          value="<?= dv_operations_html($values['bandlage'] ?? '') ?>"
          <?= $bandVisible
              ? ($bandRequired ? 'required' : '')
              : 'disabled' ?>>
      </label>
      <label data-estab-telecom-field="verkehrsform">
        <span data-estab-telecom-field-label="verkehrsform">
          Verkehrsform oder besondere Behandlung
        </span>
        <input name="verkehrsform" maxlength="128" required
          value="<?= dv_operations_html($values['verkehrsform'] ?? '') ?>">
      </label>
    </div>
    <?php if (
        $medium !== 'Fu'
        && (
            trim((string) ($values['kanal'] ?? '')) !== ''
            || trim((string) ($values['bandlage'] ?? '')) !== ''
        )
    ): ?>
      <p class="estab-tool-notice estab-telecom-legacy-note">
        Dieser übernommene Weg enthält technische Altangaben. Für das
        gewählte Medium sind Kanal und Bandlage nicht vorgesehen; beim
        Speichern dieses Wegs werden sie entfernt.
      </p>
    <?php endif; ?>
    <label>Besondere Vermerke
      <textarea name="besondere_vermerke"
        maxlength="10000"><?= dv_operations_html(
            $values['besondere_vermerke'] ?? ''
        ) ?></textarea>
    </label>
    <label>Bemerkungen
      <textarea name="bemerkungen" maxlength="10000"><?=
        dv_operations_html($values['bemerkungen'] ?? '')
      ?></textarea>
    </label>
    <?php
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$error = null;
$telecomRevisionConflict = false;
$connection = null;

if ($requestMethod === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $action = $_POST['operation_action'] ?? null;
        if (!is_string($action)) {
            throw new EstabDvInputException('Unbekannte Führungsstellenaktion.');
        }
        $connection = estab_auth_connect($conf_4f_db);
        $incident = estab_incident_require_active($connection);
        estab_permission_context_set_from_incident($incident);
        $incidentId = (int) $incident['active_einsatz_id'];
        $code = (string) $identity['kuerzel'];
        if ($action === 'accept_hat') {
            $acceptedHat = estab_dv_accept_hat(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstbesetzung_id'] ?? null,
                    'Dienstbesetzung'
                ),
                $code,
                $conf_4f_tbl['protokoll'],
                $conf_4f_tbl['empfmtx'],
                $conf_4f_tbl['usrtblprefix']
            );
            dv_operations_redirect(
                ($acceptedHat['active_shift_extension'] ?? false) === true
                    ? 'hat_extension_accepted'
                    : 'hat_accepted',
                'meine-dienstfunktionen'
            );
        }
        if ($action === 'select_hat') {
            $selectedHat = estab_dv_select_session_hat(
                $connection,
                $_SESSION,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstbesetzung_id'] ?? null,
                    'Dienstbesetzung'
                ),
                $conf_4f_tbl['protokoll'],
                $conf_4f_tbl['empfmtx'],
                $conf_4f_tbl['usrtblprefix']
            );
            dv_operations_redirect_after_hat($selectedHat);
        }
        if ($action === 'confirm_handover') {
            estab_dv_confirm_handover_shift(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['dienstuebergabe_anfrage_id'] ?? null,
                    'Übergabeanforderung'
                ),
                estab_dv_positive_id(
                    $_POST['dienstbesetzung_id'] ?? null,
                    'Bestätigende Dienstbesetzung'
                ),
                $identity,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'shift_handover_confirmed',
                'meine-dienstfunktionen'
            );
        }
        if ($action === 'create_plan') {
            estab_dv_create_telecom_plan(
                $connection,
                $incidentId,
                $operationIdentity,
                $_POST,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('plan_created', 'fernmeldeplan-entwurf');
        }
        if ($action === 'start_plan_revision') {
            estab_dv_start_telecom_plan_revision(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Aktiver Fernmeldeplan'
                ),
                $operationIdentity,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'plan_revision_started',
                'fernmeldeplan-entwurf'
            );
        }
        if ($action === 'update_plan') {
            estab_dv_update_telecom_plan_draft(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Fernmeldeplan'
                ),
                $operationIdentity,
                $_POST,
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                ),
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('plan_updated', 'fernmeldeplan-entwurf');
        }
        if ($action === 'add_plan_entry') {
            estab_dv_add_telecom_entry(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Fernmeldeplan'
                ),
                $operationIdentity,
                $_POST,
                $conf_4f_tbl['protokoll'],
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                )
            );
            dv_operations_redirect(
                'plan_entry_added',
                'fernmeldeplan-entwurf'
            );
        }
        if ($action === 'update_plan_entry') {
            $planId = estab_dv_positive_id(
                $_POST['fernmeldeplan_id'] ?? null,
                'Fernmeldeplan'
            );
            $entryId = estab_dv_positive_id(
                $_POST['fernmeldeplan_eintrag_id'] ?? null,
                'Fernmeldeweg'
            );
            estab_dv_update_telecom_entry(
                $connection,
                $incidentId,
                $planId,
                $entryId,
                $operationIdentity,
                $_POST,
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                ),
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'plan_entry_updated',
                'fernmeldeweg-' . $entryId,
                ['entry' => $entryId]
            );
        }
        if ($action === 'delete_plan_entry') {
            estab_dv_delete_telecom_entry(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Fernmeldeplan'
                ),
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_eintrag_id'] ?? null,
                    'Fernmeldeweg'
                ),
                $operationIdentity,
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                ),
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'plan_entry_deleted',
                'fernmeldeplan-entwurf'
            );
        }
        if ($action === 'discard_plan') {
            estab_dv_discard_telecom_plan_draft(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Fernmeldeplan'
                ),
                $operationIdentity,
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                ),
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'plan_draft_discarded',
                'fernmeldeplan-entwurf'
            );
        }
        if ($action === 'activate_plan') {
            estab_dv_activate_telecom_plan(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['fernmeldeplan_id'] ?? null,
                    'Fernmeldeplan'
                ),
                $operationIdentity,
                $conf_4f_tbl['protokoll'],
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                )
            );
            dv_operations_redirect('plan_activated');
        }
        if ($action === 'assign_messenger') {
            $assignmentDetails = null;
            estab_dv_assign_messenger(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['nachricht_id'] ?? null,
                    'Nachricht'
                ),
                $_POST['melder_kuerzel'] ?? null,
                $_POST['ziel'] ?? null,
                $operationIdentity,
                $conf_4f_tbl['protokoll'],
                $assignmentDetails
            );
            $requiresNotification = !is_array($assignmentDetails)
                || ($assignmentDetails['requires_separate_notification']
                    ?? true) === true;
            $presenceState = is_array($assignmentDetails)
                && is_string($assignmentDetails['presence_state'] ?? null)
                    ? $assignmentDetails['presence_state']
                    : 'unknown';
            dv_operations_redirect(
                $requiresNotification
                    ? 'messenger_assigned_notification_required'
                    : 'messenger_assigned',
                'melderauftraege',
                ['presence' => $presenceState]
            );
        }
        if ($action === 'messenger_transition') {
            $transition = $_POST['transition'] ?? null;
            if (!is_string($transition)) {
                throw new EstabDvInputException('Unbekannter Melderstatus.');
            }
            estab_dv_transition_messenger(
                $connection,
                $incidentId,
                estab_dv_positive_id(
                    $_POST['melderauftrag_id'] ?? null,
                    'Melderauftrag'
                ),
                $transition,
                $operationIdentity,
                $_POST,
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect('messenger_updated');
        }
        throw new EstabDvInputException('Unbekannte Führungsstellenaktion.');
    } catch (EstabCsrfException) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    } catch (EstabDvInputException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (EstabDvPermissionException $exception) {
        http_response_code(403);
        $error = $exception->getMessage();
    } catch (EstabDvConflictException $exception) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (
        EstabIncidentConfigurationException
        | EstabNoActiveIncidentException $exception
    ) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('eStab Führungsstellenbetrieb: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Die Aktion konnte nicht vollständig gespeichert werden.';
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
} elseif ($requestMethod !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $error = 'Diese Seite unterstützt nur GET und POST.';
}

$status = null;
$plans = [];
$jobs = [];
$users = [];
$eligibleMessages = [];
$isS6 = false;
$isLdf = false;
$isAw = false;
$selectedIdentity = null;
$plansLoaded = false;
$strictMode = true;
$activeDutyShift = null;
$hats = [];
$handoverRequests = [];
$confirmableHandovers = [];
try {
    $connection = estab_auth_connect($conf_4f_db);
    $status = estab_incident_status($connection);
    if ($status['active_einsatz_id'] !== null) {
        estab_permission_context_set_from_incident($status);
        $incidentId = (int) $status['active_einsatz_id'];
        $code = (string) $identity['kuerzel'];
        $strictMode = estab_incident_duty_shift_required($status);
        if ($strictMode) {
            $activeDutyShift = estab_dv_active_shift_summary(
                $connection,
                $incidentId
            );
            $hats = estab_dv_user_hats($connection, $incidentId, $code);
            $handoverRequests = estab_dv_user_handover_requests(
                $connection,
                $incidentId,
                $code
            );
            foreach ($handoverRequests as $handoverRequest) {
                if (($handoverRequest['status'] ?? null) !== 'INITIIERT') {
                    continue;
                }
                foreach ($hats as $hat) {
                    if (
                        (int) ($hat['dienstschicht_id'] ?? 0)
                            === (int) $handoverRequest['an_dienstschicht_id']
                        && ($hat['status'] ?? null) === 'ANGENOMMEN'
                        && ($hat['schicht_status'] ?? null) === 'GEPLANT'
                    ) {
                        $confirmableHandovers[] = [
                            'request' => $handoverRequest,
                            'assignment' => $hat,
                        ];
                    }
                }
            }
        }
        try {
            $readScope = estab_read_require_operational_scope(
                $connection,
                estab_read_session_identity($_SESSION) ?? []
            );
            $selectedIdentity = $readScope['identity'];
        } catch (EstabReadPermissionException $exception) {
            if (!$strictMode) {
                throw $exception;
            }
            // STRICT deliberately exposes the personal duty bootstrap before
            // an accepted active assignment has been selected.
        }
        if (is_array($selectedIdentity)) {
            $operationIdentity = $selectedIdentity;
            $isS6 = estab_dv_has_write_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'FERNMELDEPLANUNG',
                false
            );
            $isLdf = estab_dv_has_write_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'FERNMELDEBETRIEB',
                false
            );
            $isAw = estab_dv_has_write_capability(
                $connection,
                $incidentId,
                $selectedIdentity,
                'BEFOERDERUNG',
                false
            );
            $plans = estab_dv_telecom_plans($connection, $incidentId);
            $plansLoaded = true;
            $jobs = estab_dv_messenger_jobs(
                $connection,
                $incidentId,
                ($isLdf || $isAw) ? null : $code
            );
            if ($isLdf) {
                $users = estab_dv_messenger_candidates(
                    $connection,
                    $incidentId
                );
                $messageStatement = $connection->prepare(
                    'SELECT n.`00_lfd`, n.`04_nummer`, n.`10_anschrift`,'
                    . ' n.`12_inhalt` FROM `nv_nachrichten` AS n'
                    . ' WHERE n.`einsatz_id` = ?'
                    . " AND n.`04_richtung` = 'A'"
                    . " AND n.`01_medium` = 'Me'"
                    . ' AND n.`x00_status` = 2'
                    . " AND n.`x01_abschluss` = 'f'"
                    . ' AND NOT EXISTS ('
                    . '   SELECT 1 FROM `nv_melderauftraege` AS m'
                    . '   WHERE m.`einsatz_id` = n.`einsatz_id`'
                    . '     AND m.`nachricht_id` = n.`00_lfd`'
                    . "     AND m.`status` <> 'ABGEBROCHEN'"
                    . ' )'
                    . ' ORDER BY n.`04_nummer`, n.`00_lfd`'
                );
                if (!$messageStatement) {
                    throw new RuntimeException(
                        'Melderfähige Nachrichten konnten nicht vorbereitet '
                        . 'werden.'
                    );
                }
                try {
                    $messageStatement->bind_param('i', $incidentId);
                    $messageStatement->execute();
                    $messageResult = $messageStatement->get_result();
                    $eligibleMessages = $messageResult->fetch_all(MYSQLI_ASSOC);
                    $messageResult->free();
                } finally {
                    $messageStatement->close();
                }
            }
        }
    }
} catch (Throwable $exception) {
    error_log('eStab Führungsstellenansicht: ' . $exception->getMessage());
    if ($error === null) {
        http_response_code(503);
        $error = 'Der Führungsstellenstatus ist derzeit nicht verfügbar.';
    }
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}

if (
    $plansLoaded
    && dv_operations_posted_telecom_revision_is_stale($plans)
) {
    $telecomRevisionConflict = true;
    $error .= ' Der aktuelle gespeicherte Stand wurde neu geladen. '
        . 'Veraltete Eingaben aus diesem Browser-Tab wurden nicht in die '
        . 'Formulare übernommen.';
}

$flashMessages = [
    'hat_accepted' => 'Die Dienstfunktion wurde persönlich angenommen.',
    'hat_extension_accepted' =>
        'Die Ergänzung der aktiven Schicht wurde persönlich angenommen und '
        . 'in den Betriebsbüchern nachgewiesen.',
    'hat_selected' => 'Die aktive Arbeitsfunktion wurde gewechselt.',
    'shift_handover_confirmed' =>
        'Sie haben die Schichtübernahme persönlich bestätigt. Die '
        . 'Nachfolgeschicht ist jetzt aktiv.',
    'plan_created' => 'Der erste Fernmeldeplanentwurf wurde angelegt.',
    'plan_revision_started' => 'Der aktive Fernmeldeplan wurde vollständig '
        . 'in einen bearbeitbaren Entwurf kopiert.',
    'plan_updated' => 'Die Kopfdaten des Entwurfs wurden gespeichert.',
    'plan_entry_added' => 'Der Fernmeldeweg wurde dem Entwurf hinzugefügt.',
    'plan_entry_updated' => 'Der Fernmeldeweg wurde im Entwurf gespeichert.',
    'plan_entry_deleted' => 'Der Fernmeldeweg wurde aus dem Entwurf entfernt.',
    'plan_draft_discarded' => 'Der gespeicherte Entwurf wurde verworfen und '
        . 'in die Versionshistorie übernommen. Sie können jetzt eine neue '
        . 'Bearbeitung auf Basis des aktiven Plans starten.',
    'plan_activated' => 'Der Fernmeldeplan wurde freigegeben und versioniert.',
    'messenger_assigned' => 'Der Melderauftrag wurde verbindlich erteilt.',
    'messenger_assigned_notification_required' =>
        'Der Melderauftrag wurde verbindlich erteilt.',
    'messenger_updated' => 'Der Melderstatus wurde nachgewiesen.',
];
$result = $_GET['result'] ?? null;
$flash = is_string($result) ? ($flashMessages[$result] ?? null) : null;
$flashWarning = null;
if ($result === 'messenger_assigned_notification_required') {
    $presenceResult = $_GET['presence'] ?? null;
    $presenceLabel = estab_dv_messenger_presence_label(
        is_string($presenceResult) ? $presenceResult : null
    );
    $flashWarning = 'Status des Fernmelders: ' . $presenceLabel . '. '
        . 'Der LdF muss ihn separat über den Auftrag informieren.';
}
$highlightEntryId = null;
$entryResult = $_GET['entry'] ?? null;
if (is_string($entryResult)) {
    $validatedEntry = filter_var(
        $entryResult,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if (is_int($validatedEntry)) {
        $highlightEntryId = $validatedEntry;
    }
}
$activePlan = null;
$draftPlans = [];
$archivedPlans = [];
foreach ($plans as $plan) {
    if (($plan['status'] ?? null) === 'AKTIV') {
        $activePlan = $plan;
    } elseif (($plan['status'] ?? null) === 'ENTWURF') {
        $draftPlans[] = $plan;
    } elseif (($plan['status'] ?? null) === 'ERSETZT') {
        $archivedPlans[] = $plan;
    }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Führungsstellenbetrieb</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
<main class="estab-tool-main" data-estab-dv-operations>
  <header class="estab-tool-hero">
    <p class="estab-tool-eyebrow">Einsatzführung · DV 1-101</p>
    <h1>Führungsstellenbetrieb</h1>
    <p>Den Fernmeldeplan als S6 führen und Melderaufträge lückenlos
      nachweisen. Fachliche Schreibaktionen folgen dem am Einsatz
      festgelegten Berechtigungsmodus; Anmeldung, Einsatzbezug,
      Melder-Eignung und Nachweise bleiben verbindlich.</p>
  </header>

  <section class="estab-tool-status estab-tool-status-active
    estab-tool-status-summary">
    <?php if (
        is_array($status)
        && $status['active_einsatz_id'] !== null
        && ($status['fuehrungsstellenname'] ?? null) !== null
    ): ?>
      <div>
        <span>Führungsstelle</span>
        <strong><?= dv_operations_html(
            $status['fuehrungsstellenname']
        ) ?></strong>
      </div>
    <?php endif; ?>
    <div>
      <span>Angemeldet als</span>
      <strong><?= dv_operations_html(
          $identity['benutzer'] . ' · ' . $identity['kuerzel']
      ) ?></strong>
    </div>
    <div>
      <span><?= $strictMode
          ? 'Aktive Arbeitsfunktion'
          : 'Kontofunktion' ?></span>
      <strong><?php if ($strictMode && !is_array($selectedIdentity)): ?>
        Noch nicht ausgewählt
      <?php else: ?><?= dv_operations_html(
          estab_function_identity_display_name(
              (string) ($selectedIdentity['funktion'] ?? $identity['funktion']),
              (string) ($selectedIdentity['rolle'] ?? $identity['rolle'])
          )
      ) ?><?php endif; ?></strong>
    </div>
    <div>
      <span>Berechtigungsmodus</span>
      <strong><?= $strictMode ? 'Streng' : 'Locker' ?></strong>
    </div>
    <?php if (!$strictMode && is_array($selectedIdentity)): ?>
      <?php
        $effectiveFunctions = array_map(
            static fn (array $tuple): string =>
                estab_function_identity_display_name(
                    $tuple['funktion'],
                    $tuple['rolle']
                ),
            estab_auth_effective_function_roles($selectedIdentity)
        );
      ?>
      <div>
        <span>Wirksame Funktionen</span>
        <strong><?= dv_operations_html(implode(' · ', $effectiveFunctions)) ?></strong>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($error !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
      <?= dv_operations_html($error) ?>
    </p>
  <?php endif; ?>
  <?php if ($flash !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
      <?= dv_operations_html($flash) ?>
    </p>
  <?php endif; ?>
  <?php if ($flashWarning !== null): ?>
    <p class="estab-tool-feedback estab-tool-feedback-warning" role="status">
      <?= dv_operations_html($flashWarning) ?>
    </p>
  <?php endif; ?>

  <?php if (!is_array($status) || $status['active_einsatz_id'] === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Kein Einsatz aktiv.</strong>
      <span>Operative Aktionen sind gesperrt, bis ein Einsatz aktiv ist.</span>
    </section>
  <?php elseif (($status['fuehrungsstellenname'] ?? null) === null): ?>
    <section class="estab-tool-status estab-tool-status-danger" role="alert">
      <strong>Name der Führungsstelle fehlt.</strong>
      <span>Operative Aktionen sind gesperrt, bis der Name am Einsatz
        festgelegt wurde.</span>
      <a class="estab-button" href="../4fadm/incidents.php">
        Zur Einsatzverwaltung
      </a>
    </section>
  <?php else: ?>
    <?php if ($strictMode): ?>
      <?php if (!is_array($activeDutyShift)): ?>
        <section class="estab-tool-status estab-tool-status-danger" role="alert">
          <strong>Keine Dienstschicht aktiv.</strong>
          <span>Operative Eingaben sind gesperrt. Die Administration muss
            eine geplante Dienstschicht aktivieren.</span>
        </section>
      <?php endif; ?>

      <section id="meine-dienstfunktionen" class="estab-tool-panel"
        data-estab-duty-functions>
        <header class="estab-tool-panel-heading">
          <h2>Meine Dienstfunktionen</h2>
          <p>Im strengen Modus wird eine Zuweisung erst nach Ihrer
            persönlichen Annahme wirksam. Wählen Sie anschließend genau die
            Funktion, in der Sie aktuell arbeiten.</p>
        </header>
        <?php if ($hats === []): ?>
          <p class="estab-tool-empty">Ihrem Konto ist in der aktuellen oder
            geplanten Schicht noch keine Funktion zugewiesen.</p>
        <?php else: ?>
          <div class="estab-tool-table-wrap estab-tool-table-responsive">
            <table class="estab-tool-table">
              <caption class="estab-visually-hidden">
                Persönlich zugewiesene Dienstfunktionen
              </caption>
              <thead><tr>
                <th scope="col">Schicht</th>
                <th scope="col">Funktion</th>
                <th scope="col">Status</th>
                <th scope="col">Aktion</th>
              </tr></thead>
              <tbody>
              <?php foreach ($hats as $hat): ?>
                <?php
                  $isSelectedHat = is_array($selectedIdentity)
                      && (int) ($selectedIdentity['duty_assignment_id'] ?? 0)
                          === (int) $hat['dienstbesetzung_id'];
                ?>
                <tr<?= $isSelectedHat ? ' data-estab-selected-duty-hat' : '' ?>>
                  <td data-label="Schicht">#<?= (int) $hat['nummer'] ?> ·
                    <?= dv_operations_html($hat['bezeichnung']) ?><br>
                    <?= dv_operations_html($hat['schicht_status']) ?></td>
                  <td data-label="Funktion"><?= dv_operations_html(
                      estab_function_identity_display_name(
                          $hat['funktion'],
                          $hat['rolle']
                      )
                  ) ?></td>
                  <td data-label="Status"><?= $isSelectedHat
                      ? 'Aktiv ausgewählt'
                      : dv_operations_html($hat['status']) ?></td>
                  <td data-label="Aktion">
                    <?php if (
                        $hat['status'] === 'ZUGEWIESEN'
                        && in_array(
                            $hat['schicht_status'],
                            ['GEPLANT', 'AKTIV'],
                            true
                        )
                    ): ?>
                      <form method="post" action="fuehrungsstelle.php">
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="operation_action"
                          value="accept_hat">
                        <input type="hidden" name="dienstbesetzung_id"
                          value="<?= (int) $hat['dienstbesetzung_id'] ?>">
                        <button class="estab-button estab-button-primary"
                          type="submit"><?= $hat['schicht_status'] === 'AKTIV'
                              ? 'Ergänzung annehmen'
                              : 'Verbindlich annehmen' ?></button>
                      </form>
                    <?php elseif (
                        $hat['status'] === 'ANGENOMMEN'
                        && $hat['schicht_status'] === 'AKTIV'
                        && !$isSelectedHat
                    ): ?>
                      <form method="post" action="fuehrungsstelle.php">
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="operation_action"
                          value="select_hat">
                        <input type="hidden" name="dienstbesetzung_id"
                          value="<?= (int) $hat['dienstbesetzung_id'] ?>">
                        <button class="estab-button" type="submit">
                          Als Arbeitsfunktion wählen
                        </button>
                      </form>
                    <?php else: ?>
                      <span><?= $isSelectedHat
                          ? 'Diese Funktion ist wirksam.'
                          : 'Keine Aktion verfügbar' ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($confirmableHandovers !== []): ?>
        <section class="estab-tool-panel" aria-labelledby="handover-title">
          <header class="estab-tool-panel-heading">
            <h2 id="handover-title">Schichtübernahme bestätigen</h2>
            <p>Die Administration hat die Übergabe initiiert. Erst die
              persönliche Bestätigung aktiviert die Nachfolgeschicht.</p>
          </header>
          <?php foreach ($confirmableHandovers as $confirmation): ?>
            <?php
              $handoverRequest = $confirmation['request'];
              $assignment = $confirmation['assignment'];
            ?>
            <form class="estab-tool-form" method="post"
              action="fuehrungsstelle.php">
              <?= estab_csrf_field() ?>
              <input type="hidden" name="operation_action"
                value="confirm_handover">
              <input type="hidden" name="dienstuebergabe_anfrage_id"
                value="<?= (int) $handoverRequest['dienstuebergabe_anfrage_id'] ?>">
              <input type="hidden" name="dienstbesetzung_id"
                value="<?= (int) $assignment['dienstbesetzung_id'] ?>">
              <p><strong>Schicht #<?= (int) $handoverRequest['von_nummer'] ?>
                an Schicht #<?= (int) $handoverRequest['an_nummer'] ?></strong></p>
              <p><?= nl2br(dv_operations_html(
                  $handoverRequest['zusammenfassung']
              )) ?></p>
              <button class="estab-button estab-button-primary" type="submit">
                Übernahme als <?= dv_operations_html(
                    estab_function_identity_display_name(
                        $assignment['funktion'],
                        $assignment['rolle']
                    )
                ) ?> bestätigen
              </button>
            </form>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

      <?php if (!is_array($selectedIdentity)): ?>
        <section class="estab-tool-status estab-tool-status-danger"
          role="alert" data-estab-duty-selection-required>
          <strong>Keine Arbeitsfunktion ausgewählt.</strong>
          <span>Nehmen Sie oben eine zugewiesene Funktion an und wählen Sie
            sie aus. Erst danach sind operative Bereiche freigeschaltet.</span>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$strictMode || is_array($selectedIdentity)): ?>
    <section class="estab-tool-panel" data-estab-active-telecom-plan>
      <header class="estab-tool-panel-heading">
        <h2>Aktiver Fernmeldeplan</h2>
        <p>Diese freigegebene Fassung bleibt während einer Bearbeitung gültig.
          Erst die bewusste Veröffentlichung des Entwurfs ersetzt sie.</p>
      </header>
      <?php if ($activePlan === null): ?>
        <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
          Noch kein gültiger Fernmeldeplan freigegeben.
        </p>
      <?php else: ?>
        <dl class="estab-telecom-plan-meta">
          <div><dt>Status</dt><dd>Aktiv · Version
            <?= (int) $activePlan['version'] ?></dd></div>
          <div><dt>Herkunft</dt><dd data-estab-telecom-header-origin><?=
            dv_operations_html(
              $activePlan['herkunft']
          ) ?></dd></div>
          <div><dt>Gültigkeit</dt><dd data-estab-telecom-header-validity
            data-estab-valid-from="<?= dv_operations_html(
                dv_operations_datetime_input($activePlan['gueltig_ab'])
            ) ?>" data-estab-valid-until="<?= dv_operations_html(
                dv_operations_datetime_input($activePlan['gueltig_bis'])
            ) ?>">ab <?= dv_operations_html(
                $activePlan['gueltig_ab']
            ) ?><?= $activePlan['gueltig_bis'] === null
              ? ''
              : ' bis ' . dv_operations_html($activePlan['gueltig_bis'])
          ?></dd></div>
          <div><dt>Betriebsleitung</dt><dd
            data-estab-telecom-header-lead><?= dv_operations_html(
              $activePlan['betriebsleitung']
          ) ?></dd></div>
        </dl>
        <?php if (trim((string) $activePlan['bemerkungen']) !== ''): ?>
          <p class="estab-telecom-plan-note">
            <strong>Bemerkungen:</strong>
            <span data-estab-telecom-header-remarks><?= dv_operations_html(
                $activePlan['bemerkungen']
            ) ?></span>
          </p>
        <?php endif; ?>
        <div class="estab-tool-table-wrap estab-tool-table-responsive">
          <table class="estab-tool-table">
            <caption class="estab-visually-hidden">
              Wege des aktiven Fernmeldeplans
            </caption>
            <thead><tr>
              <th scope="col">Betriebsstelle</th>
              <th scope="col">Rufname</th>
              <th scope="col">Medium und technische Angaben</th>
              <th scope="col">Verkehrsform</th>
              <th scope="col">Vermerke</th>
            </tr></thead>
            <tbody>
            <?php foreach ($activePlan['eintraege'] as $entry): ?>
              <?php $routeParts = array_values(array_filter([
                  estab_dv_telecom_medium_label($entry['medium']),
                  trim((string) $entry['kanal']),
                  trim((string) $entry['bandlage']),
              ], static fn (string $part): bool => $part !== '')); ?>
              <tr>
                <td data-label="Betriebsstelle"><?= dv_operations_html(
                    $entry['betriebsstelle']
                ) ?></td>
                <td data-label="Rufname"><?= dv_operations_html(
                    $entry['rufname']
                ) ?></td>
                <td data-label="Medium und technische Angaben"><?=
                  dv_operations_html(implode(' · ', $routeParts))
                ?></td>
                <td data-label="Verkehrsform"><?= dv_operations_html(
                    $entry['verkehrsform']
                ) ?></td>
                <td data-label="Vermerke"><?= dv_operations_html(
                    trim(
                        (string) $entry['besondere_vermerke']
                        . ' ' . (string) $entry['bemerkungen']
                    )
                ) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($isS6): ?>
      <section id="fernmeldeplan-entwurf" class="estab-tool-panel"
        data-estab-telecom-editor>
        <header class="estab-tool-panel-heading">
          <h2>S6 · Fernmeldeplan bearbeiten und versionieren</h2>
          <p>Beim Bearbeitungsstart werden Kopfdaten und sämtliche Wege des
            aktiven Plans übernommen. Sie ändern nur den Entwurf; der aktive
            Plan bleibt bis zur Veröffentlichung unverändert verfügbar.</p>
        </header>

        <?php if ($draftPlans === [] && $activePlan !== null): ?>
          <form class="estab-tool-form estab-telecom-start-form" method="post"
            action="fuehrungsstelle.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="operation_action"
              value="start_plan_revision">
            <input type="hidden" name="fernmeldeplan_id"
              value="<?= (int) $activePlan['fernmeldeplan_id'] ?>">
            <p><strong>Version <?= (int) $activePlan['version'] ?> bearbeiten</strong><br>
              Es entsteht ein vollständig vorbefüllter Entwurf für die nächste
              Version. Die aktive Fassung wird dabei nicht verändert.</p>
            <button class="estab-button estab-button-primary" type="submit">
              Bearbeitung starten
            </button>
          </form>
        <?php elseif ($draftPlans === []): ?>
          <h3>Ersten Fernmeldeplan vorbereiten</h3>
          <p class="estab-tool-notice">Nur bei der Erstanlage werden die
            Kopfdaten einmalig erfasst. Jede spätere Bearbeitung übernimmt den
            dann aktiven Plan vollständig.</p>
          <form class="estab-tool-form" method="post"
            action="fuehrungsstelle.php" data-estab-dirty-guard
            <?= dv_operations_failed_post('create_plan')
                ? 'data-estab-dirty-initial="true"'
                : '' ?>>
            <?= estab_csrf_field() ?>
            <input type="hidden" name="operation_action" value="create_plan">
            <div class="estab-tool-form-grid">
              <label>Herkunft
                <input name="herkunft" maxlength="255" required value="<?=
                  dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'herkunft',
                      ''
                  ))
                ?>">
              </label>
              <label>Gültig ab
                <input type="datetime-local" name="gueltig_ab" required
                  value="<?= dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'gueltig_ab',
                      date('Y-m-d\TH:i')
                  )) ?>">
              </label>
              <label>Gültig bis
                <input type="datetime-local" name="gueltig_bis" value="<?=
                  dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'gueltig_bis',
                      ''
                  ))
                ?>">
              </label>
              <label>Betriebsleitung
                <input name="betriebsleitung" maxlength="255" required
                  value="<?= dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'betriebsleitung',
                      ''
                  )) ?>">
              </label>
            </div>
            <label>Bemerkungen
              <textarea name="bemerkungen" maxlength="10000"><?=
                dv_operations_html(dv_operations_post_value(
                    'create_plan',
                    'bemerkungen',
                    ''
                ))
              ?></textarea>
            </label>
            <button class="estab-button estab-button-primary" type="submit">
              Ersten Entwurf anlegen
            </button>
          </form>
        <?php else: ?>
          <p class="estab-tool-notice" role="status">
            Ein Entwurf ist vorhanden. Bearbeiten Sie ihn weiter und
            veröffentlichen Sie ihn anschließend; ein zweiter paralleler
            Entwurf wird bewusst nicht angelegt.
          </p>
        <?php endif; ?>

        <?php foreach ($draftPlans as $plan): ?>
          <?php
            $planId = (int) $plan['fernmeldeplan_id'];
            $revision = (string) $plan['revision'];
          ?>
          <article class="estab-telecom-draft" data-estab-telecom-draft
            data-estab-plan-version="<?= (int) $plan['version'] ?>">
            <header class="estab-telecom-draft-heading">
              <div>
                <p class="estab-tool-eyebrow">Bearbeitbarer Entwurf</p>
                <h3>Vorgesehene Version <?= (int) $plan['version'] ?></h3>
              </div>
              <span class="estab-tool-badge estab-tool-badge-warning">
                Noch nicht aktiv
              </span>
            </header>
            <div class="estab-tool-feedback estab-tool-feedback-error
              estab-telecom-draft-warning" role="alert" tabindex="-1" hidden
              data-estab-telecom-draft-warning
              data-estab-telecom-publish-warning>
              <p><strong>Aktion noch nicht ausgeführt.</strong>
                Im Bereich <strong
                  data-estab-telecom-dirty-context>dieses Entwurfs</strong>
                gibt es ungespeicherte Änderungen.</p>
              <p>Speichern Sie diesen Bereich zuerst. Falls diese anderen
                Browser-Eingaben bewusst verloren gehen dürfen, können Sie die
                ursprünglich gewählte Aktion
                „<strong data-estab-telecom-pending-action>fortsetzen</strong>“
                ausdrücklich trotzdem ausführen.</p>
              <div class="estab-tool-actions estab-telecom-warning-actions">
                <button class="estab-button" type="button"
                  data-estab-telecom-focus-unsaved>
                  Ungespeicherten Bereich prüfen
                </button>
                <button class="estab-button estab-button-danger-outline"
                  type="button" data-estab-telecom-continue-action>
                  Andere Eingaben verwerfen und Aktion fortsetzen
                </button>
              </div>
            </div>

            <details class="estab-tool-details estab-telecom-section" open>
              <summary>Kopfdaten bearbeiten</summary>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php" data-estab-dirty-guard
                data-estab-telecom-form-label="Kopfdaten"
                <?= dv_operations_failed_post('update_plan', $planId)
                    ? 'data-estab-dirty-initial="true"'
                    : '' ?>>
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="update_plan">
                <input type="hidden" name="fernmeldeplan_id"
                  value="<?= $planId ?>">
                <input type="hidden" name="plan_revision"
                  value="<?= dv_operations_html($revision) ?>">
                <div class="estab-tool-form-grid">
                  <label>Herkunft
                    <input name="herkunft" maxlength="255" required
                      value="<?= dv_operations_html(
                          dv_operations_post_value(
                              'update_plan',
                              'herkunft',
                              $plan['herkunft'],
                              $planId
                          )
                      ) ?>">
                  </label>
                  <label>Gültig ab
                    <input type="datetime-local" name="gueltig_ab" required
                      value="<?= dv_operations_html(
                          dv_operations_post_value(
                              'update_plan',
                              'gueltig_ab',
                              dv_operations_datetime_input($plan['gueltig_ab']),
                              $planId
                          )
                      ) ?>">
                  </label>
                  <label>Gültig bis
                    <input type="datetime-local" name="gueltig_bis"
                      value="<?= dv_operations_html(
                          dv_operations_post_value(
                              'update_plan',
                              'gueltig_bis',
                              dv_operations_datetime_input(
                                  $plan['gueltig_bis']
                              ),
                              $planId
                          )
                      ) ?>">
                  </label>
                  <label>Betriebsleitung
                    <input name="betriebsleitung" maxlength="255" required
                      value="<?= dv_operations_html(
                          dv_operations_post_value(
                              'update_plan',
                              'betriebsleitung',
                              $plan['betriebsleitung'],
                              $planId
                          )
                      ) ?>">
                  </label>
                </div>
                <label>Bemerkungen
                  <textarea name="bemerkungen" maxlength="10000"><?=
                    dv_operations_html(dv_operations_post_value(
                        'update_plan',
                        'bemerkungen',
                        (string) $plan['bemerkungen'],
                        $planId
                    ))
                  ?></textarea>
                </label>
                <button class="estab-button" type="submit">
                  Kopfdaten speichern
                </button>
              </form>
            </details>

            <section class="estab-telecom-routes" aria-labelledby="<?=
              'telecom-routes-' . $planId
            ?>">
              <header class="estab-telecom-routes-heading">
                <div>
                  <h3 id="<?= 'telecom-routes-' . $planId ?>">
                    Übernommene Fernmeldewege
                  </h3>
                  <p>Jeder Weg kann einzeln angepasst oder entfernt werden.</p>
                </div>
                <span><?= count($plan['eintraege']) ?> Wege</span>
              </header>
              <?php if ($plan['eintraege'] === []): ?>
                <p class="estab-tool-empty">Noch kein Fernmeldeweg vorhanden.</p>
              <?php endif; ?>
              <div class="estab-telecom-route-list">
              <?php foreach ($plan['eintraege'] as $entry): ?>
                <?php
                  $entryId = (int) $entry['fernmeldeplan_eintrag_id'];
                  $entryValues = $entry;
                  foreach (array_keys($entryValues) as $field) {
                      $entryValues[$field] = dv_operations_post_value(
                          'update_plan_entry',
                          (string) $field,
                          $entryValues[$field],
                          $planId,
                          $entryId
                      );
                  }
                  $entryHasError = dv_operations_failed_post(
                      'update_plan_entry',
                      $planId,
                      $entryId
                  );
                  $entryHasConflict = $telecomRevisionConflict
                      && ($_POST['operation_action'] ?? null)
                          === 'update_plan_entry'
                      && (string) ($_POST['fernmeldeplan_id'] ?? '')
                          === (string) $planId
                      && (string) ($_POST['fernmeldeplan_eintrag_id'] ?? '')
                          === (string) $entryId;
                ?>
                <details class="estab-tool-details estab-telecom-route"
                  id="<?= 'fernmeldeweg-' . $entryId ?>"
                  data-estab-telecom-entry-id="<?= $entryId ?>"
                  <?= $entryHasError || $entryHasConflict
                      || $highlightEntryId === $entryId
                      ? 'open'
                      : '' ?>>
                  <summary>
                    <span><strong><?= dv_operations_html(
                        $entry['betriebsstelle']
                    ) ?></strong> · <?= dv_operations_html(
                        $entry['rufname']
                    ) ?></span>
                    <span><?= dv_operations_html(
                        estab_dv_telecom_medium_label($entry['medium'])
                    ) ?></span>
                  </summary>
                  <form class="estab-tool-form" method="post"
                    action="fuehrungsstelle.php"
                    data-estab-telecom-entry-form
                    data-estab-telecom-entry-mode="edit"
                    data-estab-dirty-guard
                    data-estab-telecom-form-label="<?= dv_operations_html(
                        'Fernmeldeweg ' . $entry['betriebsstelle']
                            . ' / ' . $entry['rufname']
                    ) ?>"
                    <?= $entryHasError
                        ? 'data-estab-dirty-initial="true"'
                        : '' ?>>
                    <?= estab_csrf_field() ?>
                    <input type="hidden" name="operation_action"
                      value="update_plan_entry">
                    <input type="hidden" name="fernmeldeplan_id"
                      value="<?= $planId ?>">
                    <input type="hidden" name="fernmeldeplan_eintrag_id"
                      value="<?= $entryId ?>">
                    <input type="hidden" name="plan_revision"
                      value="<?= dv_operations_html($revision) ?>">
                    <?php dv_operations_render_telecom_entry_fields(
                        $entryValues
                    ); ?>
                    <button class="estab-button" type="submit">
                      Änderungen am Weg speichern
                    </button>
                  </form>
                  <form class="estab-telecom-delete-form" method="post"
                    action="fuehrungsstelle.php">
                    <?= estab_csrf_field() ?>
                    <input type="hidden" name="operation_action"
                      value="delete_plan_entry">
                    <input type="hidden" name="fernmeldeplan_id"
                      value="<?= $planId ?>">
                    <input type="hidden" name="fernmeldeplan_eintrag_id"
                      value="<?= $entryId ?>">
                    <input type="hidden" name="plan_revision"
                      value="<?= dv_operations_html($revision) ?>">
                    <button class="estab-button estab-button-danger-outline"
                      type="submit" data-estab-confirm="delete-telecom-entry">
                      Weg aus dem Entwurf entfernen
                    </button>
                  </form>
                </details>
              <?php endforeach; ?>
              </div>
            </section>

            <?php
              $addValues = [];
              foreach (
                  [
                      'betriebsstelle', 'rufname', 'medium', 'kanal',
                      'bandlage', 'verkehrsform', 'besondere_vermerke',
                      'bemerkungen',
                  ] as $field
              ) {
                  $addValues[$field] = dv_operations_post_value(
                      'add_plan_entry',
                      $field,
                      '',
                      $planId
                  );
              }
            ?>
            <details class="estab-tool-details estab-telecom-section"
              <?= $error !== null
                  && ($_POST['operation_action'] ?? null) === 'add_plan_entry'
                  && (string) ($_POST['fernmeldeplan_id'] ?? '')
                      === (string) $planId ? 'open' : '' ?>>
              <summary>Weiteren Fernmeldeweg hinzufügen</summary>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php" data-estab-telecom-entry-form
                data-estab-telecom-entry-mode="add"
                data-estab-dirty-guard
                data-estab-telecom-form-label="Neuer Fernmeldeweg"
                <?= dv_operations_failed_post(
                    'add_plan_entry',
                    $planId
                ) ? 'data-estab-dirty-initial="true"' : '' ?>>
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="add_plan_entry">
                <input type="hidden" name="fernmeldeplan_id"
                  value="<?= $planId ?>">
                <input type="hidden" name="plan_revision"
                  value="<?= dv_operations_html($revision) ?>">
                <?php dv_operations_render_telecom_entry_fields($addValues); ?>
                <button class="estab-button" type="submit">
                  Weg zum Entwurf hinzufügen
                </button>
              </form>
            </details>

            <section class="estab-telecom-publish" aria-labelledby="<?=
              'telecom-publish-' . $planId
            ?>">
              <div>
                <h3 id="<?= 'telecom-publish-' . $planId ?>">
                  Entwurf veröffentlichen
                </h3>
                <p>Prüfen Sie alle Angaben. Danach wird Version
                  <?= (int) $plan['version'] ?> aktiv und die bisher aktive
                  Version unveränderlich als ersetzt archiviert.</p>
              </div>
              <div class="estab-telecom-publish-actions">
                <form method="post" action="fuehrungsstelle.php"
                  data-estab-telecom-publish-form>
                  <?= estab_csrf_field() ?>
                  <input type="hidden" name="operation_action"
                    value="activate_plan">
                  <input type="hidden" name="fernmeldeplan_id"
                    value="<?= $planId ?>">
                  <input type="hidden" name="plan_revision"
                    value="<?= dv_operations_html($revision) ?>">
                  <button class="estab-button estab-button-primary" type="submit"
                    <?= $plan['eintraege'] === [] ? 'disabled' : '' ?>>
                    Als Version <?= (int) $plan['version'] ?> aktiv schalten
                  </button>
                </form>
                <form method="post" action="fuehrungsstelle.php"
                  data-estab-telecom-discard-form>
                  <?= estab_csrf_field() ?>
                  <input type="hidden" name="operation_action"
                    value="discard_plan">
                  <input type="hidden" name="fernmeldeplan_id"
                    value="<?= $planId ?>">
                  <input type="hidden" name="plan_revision"
                    value="<?= dv_operations_html($revision) ?>">
                  <button class="estab-button estab-button-danger-outline"
                    type="submit" data-estab-confirm="discard-telecom-draft">
                    Entwurf verwerfen
                  </button>
                </form>
              </div>
            </section>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <?php if ($archivedPlans !== []): ?>
      <section class="estab-tool-panel estab-telecom-history"
        data-estab-telecom-history>
        <header class="estab-tool-panel-heading">
          <h2>Versionshistorie Fernmeldeplan</h2>
          <p>Frühere und verworfene Fassungen bleiben als unveränderlicher
            Nachweis lesbar. Zum Arbeiten gilt ausschließlich die oben als
            aktiv gekennzeichnete Version.</p>
        </header>
        <div class="estab-telecom-history-list">
          <?php foreach ($archivedPlans as $plan): ?>
            <?php
              $historyWasReleased = is_string($plan['freigegeben_von'])
                  && trim($plan['freigegeben_von']) !== '';
              $historyState = $historyWasReleased ? 'Ersetzt' : 'Verworfen';
              $historyStateKey = $historyWasReleased
                  ? 'replaced'
                  : 'discarded';
              $historyRouteCount = count($plan['eintraege']);
            ?>
            <details class="estab-tool-details estab-telecom-history-item"
              data-estab-telecom-history-version="<?=
                (int) $plan['version']
              ?>" data-estab-telecom-history-state="<?= $historyStateKey ?>">
              <summary>
                <span>
                  <strong>Version <?= (int) $plan['version'] ?></strong>
                  <small><?= dv_operations_html($plan['herkunft']) ?></small>
                </span>
                <span class="estab-telecom-history-summary-meta">
                  <span class="estab-tool-badge <?= $historyWasReleased
                      ? 'estab-tool-badge-neutral'
                      : 'estab-tool-badge-warning'
                  ?>"><?= $historyState ?></span>
                  <span><?= $historyRouteCount ?> <?= $historyRouteCount === 1
                      ? 'Weg'
                      : 'Wege'
                  ?></span>
                </span>
              </summary>
              <div class="estab-telecom-history-content">
                <dl class="estab-telecom-plan-meta">
                  <div><dt>Gültigkeit</dt><dd>ab <?= dv_operations_html(
                      $plan['gueltig_ab']
                  ) ?><?= $plan['gueltig_bis'] === null
                      ? ''
                      : ' bis ' . dv_operations_html($plan['gueltig_bis'])
                  ?></dd></div>
                  <div><dt>Betriebsleitung</dt><dd><?= dv_operations_html(
                      $plan['betriebsleitung']
                  ) ?></dd></div>
                  <div><dt>Angelegt</dt><dd><?= dv_operations_html(
                      $plan['erstellt_von']
                  ) ?> · <?= dv_operations_html(
                      dv_operations_datetime_display($plan['erstellt_am'])
                  ) ?></dd></div>
                  <div><dt>Freigegeben</dt><dd><?= $historyWasReleased
                      ? dv_operations_html($plan['freigegeben_von'])
                          . ' · ' . dv_operations_html(
                              dv_operations_datetime_display(
                                  $plan['freigegeben_am']
                              )
                          )
                      : 'Nicht freigegeben'
                  ?></dd></div>
                </dl>
                <?php if (trim((string) $plan['bemerkungen']) !== ''): ?>
                  <p class="estab-telecom-history-note">
                    <strong>Bemerkungen:</strong>
                    <span><?= dv_operations_html(
                        $plan['bemerkungen']
                    ) ?></span>
                  </p>
                <?php endif; ?>
                <?php if ($plan['eintraege'] === []): ?>
                  <p class="estab-tool-empty">Diese Fassung enthält keine
                    Fernmeldewege.</p>
                <?php else: ?>
                  <ul class="estab-telecom-history-routes">
                    <?php foreach ($plan['eintraege'] as $entry): ?>
                      <?php
                        $historyRouteParts = array_values(array_filter([
                            estab_dv_telecom_medium_label($entry['medium']),
                            trim((string) $entry['kanal']),
                            trim((string) $entry['bandlage']),
                            trim((string) $entry['verkehrsform']),
                        ], static fn (string $part): bool => $part !== ''));
                        $historySpecialNotes = trim(
                            (string) $entry['besondere_vermerke']
                        );
                        $historyRouteNotes = trim(
                            (string) $entry['bemerkungen']
                        );
                      ?>
                      <li>
                        <span><strong><?= dv_operations_html(
                            $entry['betriebsstelle']
                        ) ?></strong><small><?= dv_operations_html(
                            $entry['rufname']
                        ) ?></small></span>
                        <span><?= dv_operations_html(
                            implode(' · ', $historyRouteParts)
                        ) ?></span>
                        <?php if (
                            $historySpecialNotes !== ''
                            || $historyRouteNotes !== ''
                        ): ?>
                          <div class="estab-telecom-history-route-notes">
                            <?php if ($historySpecialNotes !== ''): ?>
                              <p><strong>Besondere Vermerke:</strong>
                                <span><?= dv_operations_html(
                                    $historySpecialNotes
                                ) ?></span></p>
                            <?php endif; ?>
                            <?php if ($historyRouteNotes !== ''): ?>
                              <p><strong>Bemerkungen zum Weg:</strong>
                                <span><?= dv_operations_html(
                                    $historyRouteNotes
                                ) ?></span></p>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="estab-tool-panel" id="melderauftraege">
      <header class="estab-tool-panel-heading">
        <h2>Melderaufträge</h2>
        <p>Übernahme, tatsächlicher Empfänger, Rücknachricht, Rückkehr und
          Abschlussmeldung werden als eigene unveränderbare Ereignisse
          protokolliert.</p>
      </header>
      <?php if ($isLdf): ?>
        <form class="estab-tool-form" method="post"
          action="fuehrungsstelle.php" data-estab-messenger-assignment>
          <?= estab_csrf_field() ?>
          <input type="hidden" name="operation_action"
            value="assign_messenger">
          <label>Ausgangsnachricht mit Weg „Melder“
            <select name="nachricht_id" required>
              <?php foreach ($eligibleMessages as $message): ?>
                <option value="<?= (int) $message['00_lfd'] ?>">
                  A<?= (int) $message['04_nummer'] ?> ·
                  <?= dv_operations_html($message['10_anschrift']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Melder
            <select name="melder_kuerzel" required
              data-estab-messenger-select>
              <?php if ($users === []): ?>
                <option value="">Kein fachlich berechtigter Fernmelder verfügbar</option>
              <?php else: ?>
                <option value="" selected>Bitte Fernmelder auswählen</option>
              <?php endif; ?>
              <?php foreach ($users as $user): ?>
                <option value="<?= dv_operations_html($user['kuerzel']) ?>"
                  data-estab-presence-state="<?= dv_operations_html(
                      $user['presence_state']
                  ) ?>" data-estab-presence-label="<?= dv_operations_html(
                      $user['presence_label']
                  ) ?>" data-estab-notification-required="<?=
                      ($user['requires_separate_notification'] ?? true)
                          ? '1'
                          : '0' ?>">
                  <?= dv_operations_html(
                      $user['benutzer'] . ' (' . $user['kuerzel'] . ')'
                          . ' · ' . $user['presence_label']
                  ) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <p class="estab-tool-notice estab-tool-notice-warning" role="status"
            aria-live="polite" hidden data-estab-messenger-presence-warning>
            <strong>Separat informieren:</strong>
            Der gewählte Fernmelder ist aktuell
            <span data-estab-messenger-presence-label>nicht aktiv</span>.
            Der LdF muss ihn separat über den Auftrag informieren.
          </p>
          <label>Ziel
            <input name="ziel" maxlength="255" required>
          </label>
          <button class="estab-button estab-button-primary" type="submit"
            <?= $eligibleMessages === [] || $users === []
                ? 'disabled'
                : '' ?>>
            Melder verbindlich beauftragen
          </button>
        </form>
      <?php endif; ?>

      <?php if ($jobs === []): ?>
        <p class="estab-tool-empty">Keine sichtbaren Melderaufträge.</p>
      <?php else: ?>
        <?php foreach ($jobs as $job): ?>
          <article class="estab-tool-panel">
            <h3>Auftrag #<?= (int) $job['melderauftrag_id'] ?> ·
              A<?= (int) $job['04_nummer'] ?> ·
              <?= dv_operations_html($job['status']) ?></h3>
            <p><strong>Melder:</strong>
              <?= dv_operations_html(
                  $job['melder_name'] . ' (' . $job['melder_kuerzel'] . ')'
              ) ?><br>
              <strong>Ziel:</strong> <?= dv_operations_html($job['ziel']) ?>
            </p>
            <?php
              $isOwnJob = hash_equals(
                  (string) $job['melder_kuerzel'],
                  (string) $identity['kuerzel']
              );
              $transition = null;
              $button = '';
              if ($isOwnJob) {
                  [$transition, $button] = match ($job['status']) {
                      'BEAUFTRAGT' => ['accept', 'Auftrag übernehmen'],
                      'UEBERNOMMEN' => ['deliver', 'Übergabe nachweisen'],
                      'UEBERGEBEN' => ['return_path', 'Rückweg antreten'],
                      'RUECKWEG' => ['returned', 'Rückkehr melden'],
                      default => [null, ''],
                  };
              } elseif ($isLdf && $job['status'] === 'ZURUECK') {
                  $transition = 'report';
                  $button = 'Abschluss an FmZt bestätigen';
              }
            ?>
            <?php if ($transition !== null): ?>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="messenger_transition">
                <input type="hidden" name="melderauftrag_id"
                  value="<?= (int) $job['melderauftrag_id'] ?>">
                <input type="hidden" name="transition"
                  value="<?= dv_operations_html($transition) ?>">
                <?php if ($transition === 'deliver'): ?>
                  <label>Tatsächlicher Empfänger
                    <input name="tatsaechlicher_empfaenger" maxlength="255"
                      required>
                  </label>
                <?php elseif ($transition === 'return_path'): ?>
                  <fieldset>
                    <legend>Liegt eine Rücknachricht vor?</legend>
                    <label>
                      <input type="radio"
                        name="ruecknachricht_vorhanden" value="ja" required>
                      Ja, Rücknachricht nachfolgend erfassen
                    </label>
                    <label>
                      <input type="radio"
                        name="ruecknachricht_vorhanden" value="nein" required>
                      Nein, ausdrücklich keine Rücknachricht
                    </label>
                  </fieldset>
                  <label>Rücknachricht (nur bei „Ja“)
                    <textarea name="ruecknachricht"
                      maxlength="10000"></textarea>
                  </label>
                <?php elseif ($transition === 'report'): ?>
                  <label>Abschlussvermerk
                    <textarea name="abschlussvermerk" maxlength="10000"
                      required></textarea>
                  </label>
                <?php endif; ?>
                <button class="estab-button estab-button-primary" type="submit">
                  <?= dv_operations_html($button) ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($isLdf && $job['status'] === 'BEAUFTRAGT'): ?>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php">
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="messenger_transition">
                <input type="hidden" name="melderauftrag_id"
                  value="<?= (int) $job['melderauftrag_id'] ?>">
                <input type="hidden" name="transition" value="cancel">
                <label>Abbruchgrund
                  <textarea name="abbruchgrund" maxlength="10000"
                    required></textarea>
                </label>
                <button class="estab-button estab-button-danger-outline"
                  type="submit">Auftrag begründet abbrechen</button>
              </form>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  <?php endif; ?>

  <footer class="estab-tool-footer">
    <a href="mainindex.php">Zurück zu Nachrichten</a>
    <span>Alle Änderungen sind einsatzgebunden und hashverkettet.</span>
  </footer>
</main>
<script<?= estab_csp_script_attribute() ?> data-estab-telecom-media-fields>
(function () {
  'use strict';
  var media = <?= json_encode(
      ESTAB_DV_MEDIA_DEFINITIONS,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
          | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
  ) ?>;
  function update(form) {
    var select = form.querySelector('[data-estab-telecom-medium]');
    if (!select) return;
    var definition = media[select.value] || null;
    ['kanal', 'bandlage', 'verkehrsform'].forEach(function (fieldName) {
      var wrapper = form.querySelector(
        '[data-estab-telecom-field="' + fieldName + '"]'
      );
      if (!wrapper) return;
      var input = wrapper.querySelector('input, select, textarea');
      var label = wrapper.querySelector(
        '[data-estab-telecom-field-label="' + fieldName + '"]'
      );
      var fieldLabel = definition ? definition[fieldName] : null;
      var visible = typeof fieldLabel === 'string' && fieldLabel !== '';
      wrapper.hidden = !visible;
      if (input) {
        input.disabled = !visible;
        input.required = visible;
      }
      if (label && visible) label.textContent = fieldLabel;
    });
  }
  document.querySelectorAll('[data-estab-telecom-entry-form]')
    .forEach(function (form) {
      update(form);
      var select = form.querySelector('[data-estab-telecom-medium]');
      if (select) select.addEventListener('change', function () {
        update(form);
      });
    });
  document.querySelectorAll('[data-estab-messenger-assignment]')
    .forEach(function (form) {
      var select = form.querySelector('[data-estab-messenger-select]');
      var warning = form.querySelector(
        '[data-estab-messenger-presence-warning]'
      );
      var label = warning && warning.querySelector(
        '[data-estab-messenger-presence-label]'
      );
      function updateMessengerPresence() {
        if (!select || !warning) return;
        var option = select.options[select.selectedIndex] || null;
        var required = option
          && option.dataset.estabNotificationRequired === '1';
        warning.hidden = !required;
        if (label && option) {
          label.textContent = option.dataset.estabPresenceLabel
            || 'nicht aktiv';
        }
      }
      updateMessengerPresence();
      if (select) {
        select.addEventListener('change', updateMessengerPresence);
      }
    });
  function changed(form) {
    if (form.hasAttribute('data-estab-dirty-initial')) return true;
    var controls = form.elements;
    for (var index = 0; index < controls.length; index += 1) {
      var field = controls[index];
      if (!field || field.disabled) continue;
      var tag = String(field.tagName || '').toLowerCase();
      var type = String(field.type || '').toLowerCase();
      if (['hidden', 'submit', 'button', 'image', 'reset'].includes(type)) {
        continue;
      }
      if (type === 'checkbox' || type === 'radio') {
        if (field.checked !== field.defaultChecked) return true;
      } else if (tag === 'select') {
        for (var optionIndex = 0;
          optionIndex < field.options.length;
          optionIndex += 1
        ) {
          if (
            field.options[optionIndex].selected
              !== field.options[optionIndex].defaultSelected
          ) return true;
        }
      } else if (type === 'file') {
        if (field.files && field.files.length > 0) return true;
      } else if (field.value !== field.defaultValue) {
        return true;
      }
    }
    return false;
  }
  function reveal(form, focusControl) {
    var disclosure = form && form.closest('details');
    if (disclosure) disclosure.open = true;
    if (!form) return;
    form.scrollIntoView({block: 'center', behavior: 'smooth'});
    if (!focusControl) return;
    var control = Array.from(form.elements).find(function (field) {
      if (!field || field.disabled) return false;
      var type = String(field.type || '').toLowerCase();
      return !['hidden', 'submit', 'button', 'image', 'reset'].includes(type);
    });
    if (control && typeof control.focus === 'function') control.focus();
  }
  document.querySelectorAll('[data-estab-telecom-draft]')
    .forEach(function (draft) {
      var warning = draft.querySelector('[data-estab-telecom-draft-warning]');
      var focusUnsaved = warning && warning.querySelector(
        '[data-estab-telecom-focus-unsaved]'
      );
      var continueAction = warning && warning.querySelector(
        '[data-estab-telecom-continue-action]'
      );
      var pendingForm = null;
      var pendingSubmitter = null;
      var pendingDirtyForm = null;
      var bypassForm = null;
      var bypassSubmitter = null;
      function clearHighlight() {
        draft.querySelectorAll('.estab-telecom-unsaved')
          .forEach(function (form) {
            form.classList.remove('estab-telecom-unsaved');
          });
      }
      function clearPending(hideWarning) {
        pendingForm = null;
        pendingSubmitter = null;
        pendingDirtyForm = null;
        if (hideWarning && warning) warning.hidden = true;
        clearHighlight();
      }
      draft.addEventListener('submit', function (event) {
        var submittedForm = event.target;
        if (!(submittedForm instanceof HTMLFormElement)) return;
        if (
          submittedForm === bypassForm
          && (
            bypassSubmitter === null
            || event.submitter === bypassSubmitter
          )
        ) {
          bypassForm = null;
          bypassSubmitter = null;
          clearPending(true);
          return;
        }
        bypassForm = null;
        bypassSubmitter = null;
        var dirtyForm = Array.from(draft.querySelectorAll(
          'form[data-estab-dirty-guard]'
        )).find(function (form) {
          return form !== submittedForm && changed(form);
        });
        if (!dirtyForm) {
          clearPending(true);
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        pendingForm = submittedForm;
        pendingSubmitter = event.submitter || null;
        pendingDirtyForm = dirtyForm;
        clearHighlight();
        dirtyForm.classList.add('estab-telecom-unsaved');
        reveal(dirtyForm, false);
        if (warning) {
          var dirtyAction = dirtyForm.querySelector(
            'input[name="operation_action"]'
          );
          var dirtyContext = warning.querySelector(
            '[data-estab-telecom-dirty-context]'
          );
          if (dirtyContext) {
            dirtyContext.textContent = dirtyForm.dataset.estabTelecomFormLabel
              || 'dieses Entwurfs';
          }
          var pendingAction = warning.querySelector(
            '[data-estab-telecom-pending-action]'
          );
          if (pendingAction) {
            var submitterLabel = pendingSubmitter
              ? String(
                  pendingSubmitter.textContent || pendingSubmitter.value || ''
                ).trim()
              : '';
            pendingAction.textContent = submitterLabel || 'fortsetzen';
          }
          warning.dataset.estabTelecomDirtyAction = dirtyAction
            ? dirtyAction.value
            : 'unknown';
          warning.hidden = false;
          warning.focus();
          warning.scrollIntoView({block: 'center', behavior: 'smooth'});
        }
      });
      if (focusUnsaved) {
        focusUnsaved.addEventListener('click', function () {
          if (!pendingDirtyForm || !pendingDirtyForm.isConnected) return;
          reveal(pendingDirtyForm, true);
        });
      }
      if (continueAction) {
        continueAction.addEventListener('click', function () {
          if (!pendingForm || !pendingForm.isConnected) {
            clearPending(true);
            return;
          }
          if (!pendingForm.reportValidity()) return;
          var form = pendingForm;
          var submitter = pendingSubmitter;
          bypassForm = form;
          bypassSubmitter = submitter;
          clearPending(true);
          if (submitter && submitter.form === form) {
            form.requestSubmit(submitter);
          } else {
            form.requestSubmit();
          }
        });
      }
      function cancelPendingAction() {
        bypassForm = null;
        bypassSubmitter = null;
        clearPending(true);
      }
      draft.addEventListener('input', cancelPendingAction);
      draft.addEventListener('change', cancelPendingAction);
    });
}());
</script>
</body>
</html>
