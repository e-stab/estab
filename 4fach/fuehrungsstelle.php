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
require_once __DIR__ . '/../app/tabelle.php';
require_once __DIR__ . '/../app/telecom_sketch.php';

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

/*
 * Zwei Ansichten auf denselben Bestand.
 *
 * Fb Fü 76 beschreibt zwei Leserkreise mit zwei Tiefen: der taktische Führer
 * bekommt nur, was er für seine Entscheidung braucht, das Betriebspersonal
 * sämtliche technischen Einzelheiten. Das ist kein Widerspruch im Vordruck,
 * sondern die Beschreibung zweier Unterlagen -- eStab führt einen Bestand und
 * zwei Ansichten darauf.
 *
 * Die Wahl steht in der Sitzung, nicht in der Adresse: wer umgeschaltet hat,
 * soll nach dem Speichern eines Weges dieselbe Ansicht wiederfinden, und die
 * Umschaltung ist ein Wunsch der Person, keine Eigenschaft der Seite.
 * Umgeschaltet wird mit einem Verweis; JavaScript ist dafür nicht nötig.
 */
$tiefenSchluessel = 'estab_fernmeldeplan_tiefe';
$gewuenschteTiefe = $_GET['ansicht'] ?? null;
if (in_array($gewuenschteTiefe, ['taktisch', 'betrieblich'], true)) {
    $_SESSION[$tiefenSchluessel] = $gewuenschteTiefe;
}
$planTiefe = in_array(
    $_SESSION[$tiefenSchluessel] ?? null,
    ['taktisch', 'betrieblich'],
    true
) ? (string) $_SESSION[$tiefenSchluessel] : 'taktisch';

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
            'add_plan_counterpart',
            'delete_plan_counterpart',
            'add_plan_extension',
            'delete_plan_extension',
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
    /*
     * Der Auswahlkasten fuehrt Wegarten, nicht Medien.
     *
     * "Funk (analog)" und "Funk (digital)" speichern beide das Medium `Fu` --
     * das ist der Wert, den Feld 1 des Vordrucks druckt. Was sie
     * unterscheidet, steht daneben in `funkart`. Der Bediener waehlt also die
     * Sache, die er kennt, und der Vordruck merkt davon nichts.
     */
    $kind = estab_dv_telecom_route_kind(
        $values['medium'] ?? null,
        $values['funkart'] ?? null
    );
    if (is_string($values['wegart'] ?? null)
        && isset(ESTAB_DV_TELECOM_ROUTE_KINDS[$values['wegart']])) {
        $kind = $values['wegart'];
    }
    $definition = $kind === null
        ? null
        : ESTAB_DV_TELECOM_ROUTE_KINDS[$kind];
    $legacyRadio = $kind === null
        && ($values['medium'] ?? null) !== null
        && (string) $values['medium'] !== '';
    ?>
    <div class="estab-tool-form-grid">
      <label>Stelle
        <input name="betriebsstelle" maxlength="255" required
          value="<?= dv_operations_html($values['betriebsstelle'] ?? '') ?>">
        <small><strong>Ihre eigene</strong> Betriebsstelle, die dieses
          Mittel führt: Führungsstelle, Fernmeldezentrale, Meldekopf. Wen Sie
          darüber erreichen, tragen Sie weiter unten als Gegenstelle ein.</small>
      </label>
      <?php
        /*
         * Hier stand einmal die Stellenart. Sie ist an die Gegenstelle
         * gewandert, und das war eine Berichtigung, keine Verschiebung:
         *
         * Der Plan legt die EIGENEN Erreichbarkeiten fest. Eine Zeile ist
         * eines UNSERER Mittel, getragen von einer unserer eigenen
         * Betriebsstellen -- ihr Verhältnis zu uns ist immer "eigen". Ober-
         * und Unterstellung sind Eigenschaften der ANDEREN Seite, und dort
         * werden sie jetzt auch gepflegt. Fb Fü 77 zeichnet es genauso: wir
         * in der Mitte, übergeordnet links, nachgeordnet rechts.
         */
      ?>
      <?php
        /*
         * Die Rueckfallebene ist ein Verweis, kein Schalter mit Ziel: NULL
         * heisst "keine". Angeboten werden nur die uebrigen Wege desselben
         * Entwurfs -- der eigene faellt heraus, weil ein Weg nicht seine
         * eigene Rueckfallebene sein kann.
         */
        $andereWege = [];
        foreach ($values['geschwister'] ?? [] as $geschwister) {
            if ((int) ($geschwister['weg_id'] ?? 0) < 1) {
                continue;
            }
            if (($geschwister['eigen'] ?? false) === true) {
                continue;
            }
            $andereWege[] = $geschwister;
        }
      ?>
      <label>Rückfallebene für
        <select name="rueckfallebene_fuer_weg"
          <?= $andereWege === [] ? 'disabled' : '' ?>>
          <option value="">keine Rückfallebene</option>
          <?php foreach ($andereWege as $geschwister): ?>
            <option value="<?= (int) $geschwister['weg_id'] ?>"
              <?= (int) ($values['rueckfallebene_fuer_weg'] ?? 0)
                  === (int) $geschwister['weg_id'] ? 'selected' : '' ?>>
              <?= dv_operations_html($geschwister['bezeichnung']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small><?= $andereWege === []
            ? 'Noch kein anderer Weg vorhanden, für den dieser einspringen '
              . 'könnte.'
            : 'Dieser Weg tritt an die Stelle des gewählten, wenn der '
              . 'ausfällt.' ?></small>
      </label>
      <label>Wegart
        <select name="wegart" required data-estab-telecom-kind>
          <option value="" <?= $kind === null ? 'selected' : '' ?>>
            Wegart auswählen
          </option>
          <?php foreach (ESTAB_DV_TELECOM_ROUTE_KINDS as $key => $art): ?>
            <option value="<?= dv_operations_html($key) ?>"
              <?= $key === $kind ? 'selected' : '' ?>>
              <?= dv_operations_html($art['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span data-estab-telecom-field-label="rufname"><?= dv_operations_html(
            $definition['erreichbarkeit'] ?? 'Erreichbar unter'
        ) ?></span>
        <input name="erreichbarkeit" maxlength="255" required
          value="<?= dv_operations_html($values['erreichbarkeit'] ?? '') ?>">
      </label>
      <?php foreach (
          ['band', 'kanal', 'bandlage', 'verkehrsform', 'relaisstelle',
           'betriebsart', 'rufgruppe', 'anschlussart', 'datenart']
          as $name
      ):
          $owned = $definition !== null
              && isset($definition['felder'][$name]);
          $needed = $definition !== null
              && in_array($name, $definition['pflicht'], true);
          $label = $definition['felder'][$name] ?? ucfirst($name);
          $choices = ESTAB_DV_TELECOM_FIELD_CHOICES[$name] ?? null;
          $current = (string) ($values[$name] ?? '');
      ?>
        <label data-estab-telecom-field="<?= dv_operations_html($name) ?>"
          <?= $owned ? '' : 'hidden' ?>>
          <span data-estab-telecom-field-label="<?= dv_operations_html($name)
            ?>"><?= dv_operations_html($label) ?></span>
          <?php if (is_array($choices)): ?>
            <select name="<?= dv_operations_html($name) ?>"
              <?= $owned ? ($needed ? 'required' : '') : 'disabled' ?>>
              <option value="">ohne Angabe</option>
              <?php foreach ($choices as $value => $text): ?>
                <option value="<?= dv_operations_html($value) ?>"
                  <?= $current === (string) $value ? 'selected' : '' ?>>
                  <?= dv_operations_html($text) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input name="<?= dv_operations_html($name) ?>" maxlength="128"
              value="<?= dv_operations_html($current) ?>"
              <?= $owned ? ($needed ? 'required' : '') : 'disabled' ?>>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
    <?php if ($legacyRadio): ?>
      <p class="estab-tool-notice estab-telecom-legacy-note">
        Dieser übernommene Weg stammt aus der Zeit vor der Trennung von
        Analog- und Digitalfunk und sagt nicht, welche der beiden er meint.
        Wählen Sie die Wegart; die Angaben, die zur gewählten Technik nicht
        gehören, werden beim Speichern entfernt.
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
      <small>Betriebszeiten, Einschränkungen, Ersatzweg, Verkehrskreis
        (Führung, Einsatz, Versorgung), Besonderheiten der Bedienung.
        Gerätekennungen wie OPTA oder ISSI gehören nicht hierher.</small>
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
        if ($action === 'add_plan_counterpart') {
            $planId = estab_dv_positive_id(
                $_POST['fernmeldeplan_id'] ?? null,
                'Fernmeldeplan'
            );
            $entryId = estab_dv_positive_id(
                $_POST['fernmeldeplan_eintrag_id'] ?? null,
                'Fernmeldeweg'
            );
            estab_dv_add_telecom_counterpart(
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
                'counterpart_added',
                'fernmeldeweg-' . $entryId,
                ['entry' => $entryId]
            );
        }
        if ($action === 'add_plan_extension') {
            $planId = estab_dv_positive_id(
                $_POST['fernmeldeplan_id'] ?? null,
                'Fernmeldeplan'
            );
            estab_dv_add_telecom_extension(
                $connection,
                $incidentId,
                $planId,
                $operationIdentity,
                $_POST,
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                ),
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'extension_added',
                'fernmeldeplan-nebenstellen'
            );
        }
        if ($action === 'delete_plan_extension') {
            $planId = estab_dv_positive_id(
                $_POST['fernmeldeplan_id'] ?? null,
                'Fernmeldeplan'
            );
            estab_dv_remove_telecom_extension(
                $connection,
                $incidentId,
                $planId,
                estab_dv_positive_id(
                    $_POST['nebenstelle_id'] ?? null,
                    'Nebenstelle'
                ),
                $operationIdentity,
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                ),
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'extension_removed',
                'fernmeldeplan-nebenstellen'
            );
        }
        if ($action === 'delete_plan_counterpart') {
            $planId = estab_dv_positive_id(
                $_POST['fernmeldeplan_id'] ?? null,
                'Fernmeldeplan'
            );
            $entryId = estab_dv_positive_id(
                $_POST['fernmeldeplan_eintrag_id'] ?? null,
                'Fernmeldeweg'
            );
            estab_dv_remove_telecom_counterpart(
                $connection,
                $incidentId,
                $planId,
                estab_dv_positive_id(
                    $_POST['gegenstelle_id'] ?? null,
                    'Gegenstelle'
                ),
                $operationIdentity,
                estab_dv_telecom_revision_token(
                    $_POST['plan_revision'] ?? null
                ),
                $conf_4f_tbl['protokoll']
            );
            dv_operations_redirect(
                'counterpart_removed',
                'fernmeldeweg-' . $entryId,
                ['entry' => $entryId]
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
        error_log('eStab Fernmeldeplan: ' . $exception->getMessage());
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
$isS6 = false;
$selectedIdentity = null;
$plansLoaded = false;
$strictMode = true;
$ausserplan = ['ohne_weg' => [], 'abgeloest' => []];
$fuehrungsstellenName = '';
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
            $plans = estab_dv_telecom_plans($connection, $incidentId);
            $plansLoaded = true;
            $fuehrungsstellenName = estab_incident_command_post_name($status);
            /*
             * Was lief, das der Plan nicht fuehrt -- hier geladen, nicht in
             * der Ansicht: die Verbindung wird lange vor dem Seitenaufbau
             * geschlossen, und eine Ansicht, die selbst noch abfragt, waere
             * eine zweite Stelle, an der eine Berechtigung geprueft werden
             * muesste.
             */
            try {
                $ausserplan = estab_read_unplanned_incoming_routes(
                    $connection,
                    'nv_nachrichten',
                    $operationIdentity
                );
            } catch (Throwable $unplannedException) {
                error_log(
                    'eStab unplanned incoming routes are temporarily '
                    . 'unavailable'
                );
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
    'counterpart_added' =>
        'Die Gegenstelle wurde am Weg erfasst. Sie steht dem Fernmelder und '
        . 'dem LdF künftig als Vorschlag zur Verfügung.',
    'counterpart_removed' => 'Die Gegenstelle wurde vom Weg entfernt.',
    'extension_added' =>
        'Die Nebenstelle wurde der Führungsstelle hinzugefügt.',
    'extension_removed' => 'Die Nebenstelle wurde entfernt.',
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
];
$result = $_GET['result'] ?? null;
$flash = is_string($result) ? ($flashMessages[$result] ?? null) : null;
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
  <title>eStab Fernmeldeplan</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
<main class="estab-tool-main" data-estab-dv-operations>
  <header class="estab-tool-hero">
    <p class="estab-tool-eyebrow">Einsatzführung · DV 1-101</p>
    <h1>Fernmeldeplan</h1>
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
          <?php
            /*
             * Die Dienstfunktionen kommen aus dem Tabellenbauteil.
             *
             * Wer in mehreren Schichten eingeteilt ist, hat hier schnell ein
             * Dutzend Zeilen -- und suchte die eine, die gerade gilt, ohne
             * Suche und ohne Sortierung.
             */
            $funktionsZeilen = [];
            foreach ($hats as $hat) {
                $istGewaehlt = is_array($selectedIdentity)
                    && (int) ($selectedIdentity['duty_assignment_id'] ?? 0)
                        === (int) $hat['dienstbesetzung_id'];
                $funktionsZeilen[] = [
                    'schicht' => '#' . (int) $hat['nummer'] . ' · '
                        . (string) $hat['bezeichnung'] . ' · '
                        . (string) $hat['schicht_status'],
                    'funktion' => estab_function_identity_display_name(
                        $hat['funktion'],
                        $hat['rolle']
                    ),
                    'status' => $istGewaehlt
                        ? 'Aktiv ausgewählt'
                        : (string) $hat['status'],
                    'gewaehlt' => $istGewaehlt,
                    'kennung' => (int) $hat['dienstbesetzung_id'],
                    'schicht_status' => (string) $hat['schicht_status'],
                    'roh_status' => (string) $hat['status'],
                ];
            }
            $funktionsStati = [];
            foreach ($funktionsZeilen as $z) {
                $funktionsStati[$z['status']] = true;
            }
            ksort($funktionsStati);
            echo estab_tabelle_markup([
                'id' => 'dienstfunktionen',
                'beschriftung' => 'Persönlich zugewiesene Dienstfunktionen',
                'mindestbreite' => '46rem',
                'schmal' => true,
                // Die wirksame Funktion ist die eine Zeile, die zählt.
                'zeilenmarke' => static fn (array $z): string =>
                    ($z['gewaehlt'] ?? false)
                        ? 'data-estab-selected-duty-hat'
                        : '',
                'spalten' => [
                    ['schluessel' => 'schicht', 'kopf' => 'Schicht',
                        'breite' => 30, 'sortierbar' => true,
                        'suchbar' => true, 'art' => 'text'],
                    ['schluessel' => 'funktion', 'kopf' => 'Funktion',
                        'breite' => 22, 'sortierbar' => true,
                        'suchbar' => true, 'art' => 'text'],
                    ['schluessel' => 'status', 'kopf' => 'Status',
                        'breite' => 20, 'sortierbar' => true,
                        'suchbar' => true, 'art' => 'text',
                        'filter' => array_keys($funktionsStati),
                        'filtername' => 'Alle Zustände'],
                    ['schluessel' => 'aktion', 'kopf' => 'Aktion',
                        'breite' => 28, 'sortierbar' => false,
                        'suchbar' => false, 'art' => 'text',
                        'zelle' => static function (array $z): string {
                            /*
                             * Annehmen und Wählen sind Handlungen und
                             * bleiben CSRF-geschützte Formulare. Eine
                             * Dienstfunktion persönlich anzunehmen ist ein
                             * Nachweis, kein Anzeigewechsel.
                             */
                            $formular = static fn (
                                string $aktion,
                                int $kennung,
                                string $klasse,
                                string $beschriftung
                            ): string =>
                                '<form method="post" action="fuehrungsstelle.php">'
                                . estab_csrf_field()
                                . '<input type="hidden" name="operation_action" value="'
                                . dv_operations_html($aktion) . '">'
                                . '<input type="hidden" name="dienstbesetzung_id" value="'
                                . $kennung . '">'
                                . '<button class="' . $klasse . '" type="submit">'
                                . dv_operations_html($beschriftung)
                                . '</button></form>';
                            if (
                                $z['roh_status'] === 'ZUGEWIESEN'
                                && in_array(
                                    $z['schicht_status'],
                                    ['GEPLANT', 'AKTIV'],
                                    true
                                )
                            ) {
                                return $formular(
                                    'accept_hat',
                                    (int) $z['kennung'],
                                    'estab-button estab-button-primary',
                                    $z['schicht_status'] === 'AKTIV'
                                        ? 'Ergänzung annehmen'
                                        : 'Verbindlich annehmen'
                                );
                            }
                            if (
                                $z['roh_status'] === 'ANGENOMMEN'
                                && $z['schicht_status'] === 'AKTIV'
                                && !($z['gewaehlt'] ?? false)
                            ) {
                                return $formular(
                                    'select_hat',
                                    (int) $z['kennung'],
                                    'estab-button',
                                    'Als Arbeitsfunktion wählen'
                                );
                            }
                            return '<span>' . (($z['gewaehlt'] ?? false)
                                ? 'Diese Funktion ist wirksam.'
                                : 'Keine Aktion verfügbar') . '</span>';
                        }],
                ],
                'zeilen' => $funktionsZeilen,
                'leer' => 'Keine Dienstfunktion entspricht den gesetzten Filtern.',
            ]);
          ?>
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
          <div><dt>Herausgebende Dienststelle</dt><dd
            data-estab-telecom-header-origin><?=
            dv_operations_html(
              $activePlan['herkunft']
          ) ?><?= trim((string) ($activePlan['verfasser_funktion'] ?? '')) === ''
              ? ''
              : ' · ' . dv_operations_html(
                  (string) $activePlan['verfasser_funktion']
              )
          ?></dd></div>
          <div><dt>Verwendungsbereich</dt><dd
            data-estab-telecom-header-scope>Kommunikationsplan für <?=
            dv_operations_html(
              $activePlan['einsatzbezeichnung']
          ) ?></dd></div>
          <div><dt>Stand</dt><dd data-estab-telecom-header-validity
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
          <div><dt>Verschlusssachenvermerk</dt><dd
            data-estab-telecom-header-classification><?=
            trim((string) ($activePlan['vs_vermerk'] ?? '')) === ''
              ? 'nicht angegeben'
              : dv_operations_html((string) $activePlan['vs_vermerk'])
          ?></dd></div>
          <div><dt>Betriebsleitung</dt><dd
            data-estab-telecom-header-lead><?= dv_operations_html(
              $activePlan['betriebsleitung']
          ) ?></dd></div>
          <?php
            /*
             * F.d.R. -- "Für die Richtigkeit".
             *
             * Q6 verlangt Unterschrift mit Dienststellung. Am Bildschirm gibt
             * es keine Unterschrift; an ihre Stelle tritt, wer freigegeben hat
             * und in welcher Dienststellung. Fehlt die Dienststellung, steht
             * trotzdem der Name da -- eine halbe Angabe ist mehr als keine,
             * und dass sie halb ist, sieht man.
             */
            $freigabeZeichen = trim(
                (string) ($activePlan['freigegeben_von'] ?? '')
            );
            $freigabeStellung = trim(
                (string) ($activePlan['freigabe_dienststellung'] ?? '')
            );
          ?>
          <?php if ($freigabeZeichen !== ''): ?>
            <div><dt>F.d.R.</dt><dd data-estab-telecom-header-attestation><?=
              dv_operations_html($freigabeZeichen)
            ?><?= $freigabeStellung === ''
                ? ''
                : ', ' . dv_operations_html($freigabeStellung)
            ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php if (trim((string) $activePlan['bemerkungen']) !== ''): ?>
          <p class="estab-telecom-plan-note">
            <strong>Bemerkungen:</strong>
            <span data-estab-telecom-header-remarks><?= dv_operations_html(
                $activePlan['bemerkungen']
            ) ?></span>
          </p>
        <?php endif; ?>
        <?php
          /*
           * Die Wege des Fernmeldeplans kommen aus dem Tabellenbauteil.
           *
           * Ein Fernmeldeplan hat schnell zwanzig Wege. Wer den einen
           * Rufnamen sucht, über den er gerade sprechen soll, suchte ihn
           * bisher mit dem Finger am Bildschirm.
           */
          /*
           * Die taktische Ansicht: je Stelle ein Kasten.
           *
           * Gruppiert wird nach der Stelle, weil die Frage des taktischen
           * Führers "wen erreiche ich womit" lautet und nicht "welche Wege
           * gibt es". Ein Ersatzweg steht eingerückt unter dem Weg, den er
           * ersetzt, und nicht noch einmal für sich -- sonst stünde derselbe
           * Weg zweimal im Bild und man müsste raten, welcher gilt.
           */
          $wegeNachKennung = [];
          foreach ($activePlan['eintraege'] as $entry) {
              if ($entry['weg_nummer'] !== null) {
                  $wegeNachKennung[(int) $entry['weg_nummer']] = $entry;
              }
          }
          $ersatzwegeZu = [];
          foreach ($activePlan['eintraege'] as $entry) {
              $ersetzt = $entry['rueckfallebene_fuer_weg'];
              if ($ersetzt !== null && isset($wegeNachKennung[(int) $ersetzt])) {
                  $ersatzwegeZu[(int) $ersetzt][] = $entry;
              }
          }
          $stellen = [];
          foreach ($activePlan['eintraege'] as $entry) {
              $ersetzt = $entry['rueckfallebene_fuer_weg'];
              if ($ersetzt !== null && isset($wegeNachKennung[(int) $ersetzt])) {
                  continue;
              }
              $stelle = (string) $entry['betriebsstelle'];
              if (!isset($stellen[$stelle])) {
                  $stellen[$stelle] = ['wege' => []];
              }
              $stellen[$stelle]['wege'][] = $entry;
          }
          $wegeZeilen = [];
          $wegeMedien = [];
          foreach ($activePlan['eintraege'] as $entry) {
              /*
               * Die technische Kurzangabe fuehrt nur, was die Technik des
               * Wegs kennt. Ein Digitalfunkweg hat keine Bandlage, ein
               * Analogfunkweg keine Rufgruppe -- ein Feld, das leer bleibt,
               * weil es nicht gemeint ist, gehoert nicht in die Zeile.
               */
              $teile = array_values(array_filter([
                  trim((string) ($entry['band'] ?? '')),
                  trim((string) $entry['kanal']),
                  trim((string) $entry['bandlage']),
                  trim((string) ($entry['betriebsart'] ?? '')),
                  trim((string) ($entry['rufgruppe'] ?? '')),
                  trim((string) ($entry['relaisstelle'] ?? '')),
                  trim((string) $entry['verkehrsform']),
              ], static fn (string $part): bool => $part !== ''));
              $mittel = estab_dv_telecom_route_label(
                  $entry['medium'],
                  $entry['funkart'] ?? null
              );
              $wegeMedien[$mittel] = true;
              $wegeZeilen[] = [
                  'betriebsstelle' => (string) $entry['betriebsstelle'],
                  'weg' => $entry['weg_nummer'] === null
                      ? ''
                      : (string) (int) $entry['weg_nummer'],
                  'rufname' => (string) $entry['erreichbarkeit'],
                  'mittel' => $mittel,
                  'technik' => implode(' · ', $teile),
                  'ersatz' => $entry['rueckfallebene_fuer_weg'] === null
                      ? ''
                      : 'für Weg ' . (int) $entry['rueckfallebene_fuer_weg'],
                  'gegenstellen' => implode(' · ', array_map(
                      static fn (array $g): string =>
                          (string) $g['name'] . ' ('
                          . (string) $g['erreichbarkeit'] . ')',
                      $entry['gegenstellen'] ?? []
                  )),
                  'vermerke' => trim(
                      (string) $entry['besondere_vermerke']
                      . ' ' . (string) $entry['bemerkungen']
                  ),
              ];
          }
          ksort($wegeMedien);
          $wegeTafel = [
              'id' => 'fernmeldewege',
              'beschriftung' => 'Wege des aktiven Fernmeldeplans',
              // Gemessen stehen hier 916 Bildpunkte zur Verfuegung; 58rem
              // waeren 928 gewesen und haetten quergescrollt.
              'mindestbreite' => '56rem',
              'spalten' => [
                  ['schluessel' => 'weg', 'kopf' => 'Weg', 'breite' => 6,
                      'sortierbar' => true, 'suchbar' => true, 'art' => 'text'],
                  ['schluessel' => 'betriebsstelle',
                      'kopf' => 'Stelle', 'breite' => 14,
                      'sortierbar' => true, 'suchbar' => true, 'art' => 'text'],
                  ['schluessel' => 'rufname', 'kopf' => 'Erreichbar unter',
                      'breite' => 16, 'sortierbar' => true,
                      'suchbar' => true, 'art' => 'text'],
                  /*
                   * Das Mittel bekommt eine eigene Spalte mit Filter --
                   * dieselbe Not wie im Ausgang: Wer den Funk betreut,
                   * sucht die Funkwege.
                   */
                  ['schluessel' => 'mittel', 'kopf' => 'Mittel',
                      'breite' => 12, 'sortierbar' => true,
                      'suchbar' => true, 'art' => 'text',
                      'filter' => array_keys($wegeMedien),
                      'filtername' => 'Alle Mittel'],
                  ['schluessel' => 'technik',
                      'kopf' => 'Technische Angaben', 'breite' => 18,
                      'sortierbar' => false, 'suchbar' => true,
                      'art' => 'text'],
                  ['schluessel' => 'ersatz', 'kopf' => 'Rückfallebene',
                      'breite' => 12, 'sortierbar' => true,
                      'suchbar' => true, 'art' => 'text'],
                  ['schluessel' => 'gegenstellen',
                      'kopf' => 'Erreicht', 'breite' => 12,
                      'sortierbar' => false, 'suchbar' => true,
                      'art' => 'text', 'klammern' => true],
                  ['schluessel' => 'vermerke', 'kopf' => 'Vermerke',
                      'breite' => 10, 'sortierbar' => false,
                      'suchbar' => true, 'art' => 'text',
                      'klammern' => true],
              ],
              'zeilen' => $wegeZeilen,
              'leer' => 'Kein Weg entspricht den gesetzten Filtern.',
          ];
          /*
           * Ein Weg im taktischen Bild -- als eigene Funktion, weil ein
           * Ersatzweg genauso dargestellt wird wie der Weg, den er ersetzt.
           * Nur die Einrückung unterscheidet sie, nicht der Inhalt.
           */
          $wegZeile = static function (array $weg): string {
              $stuecke = [
                  '<span class="estab-telecom-station-medium">'
                  . dv_operations_html(estab_dv_telecom_route_label(
                      $weg['medium'],
                      $weg['funkart'] ?? null
                  )) . '</span>',
                  '<span class="estab-telecom-station-address">'
                  . dv_operations_html((string) $weg['erreichbarkeit'])
                  . '</span>',
              ];
              /*
               * Das Kennzeichen des Wegs.
               *
               * Eine Führungsstelle hat mehrere Digitalfunkwege unter EINEM
               * eigenen Funkrufnamen -- nach oben eine andere Rufgruppe als
               * nach unten. Ohne sie stünde „Funk (digital) · Heros
               * Übungsplatz 10" zweimal da und wäre nicht auseinanderzuhalten.
               */
              $kennzeichen = estab_dv_telecom_route_key($weg);
              if ($kennzeichen !== '') {
                  $stuecke[] = '<span class="estab-telecom-station-key">'
                      . dv_operations_html($kennzeichen) . '</span>';
              }
              if ($weg['weg_nummer'] !== null) {
                  $stuecke[] = '<span class="estab-telecom-station-route">Weg '
                      . (int) $weg['weg_nummer'] . '</span>';
              }
              return implode(' ', $stuecke);
          };
        ?>
        <nav class="estab-telecom-depth" aria-label="Tiefe der Ansicht">
          <?php foreach ([
              'taktisch' => 'Taktisch',
              'betrieblich' => 'Betrieblich',
          ] as $tiefe => $beschriftung): ?>
            <?php if ($planTiefe === $tiefe): ?>
              <strong class="estab-telecom-depth-current"
                aria-current="true"><?= dv_operations_html($beschriftung)
              ?></strong>
            <?php else: ?>
              <a class="estab-telecom-depth-link"
                href="fuehrungsstelle.php?ansicht=<?= dv_operations_html($tiefe)
                ?>#fernmeldeplan"><?= dv_operations_html($beschriftung) ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <span class="estab-telecom-depth-hint"><?= $planTiefe === 'taktisch'
              ? 'Nur, was für die Führungsentscheidung nötig ist.'
              : 'Sämtliche technischen und betrieblichen Einzelheiten.'
          ?></span>
        </nav>
        <?php if ($planTiefe === 'betrieblich'): ?>
          <?= estab_tabelle_markup($wegeTafel) ?>
        <?php else: ?>
          <ol class="estab-telecom-stations">
            <?php foreach ($stellen as $stelleName => $stelle): ?>
              <li class="estab-telecom-station">
                <h3 class="estab-telecom-station-name"><?=
                  dv_operations_html((string) $stelleName)
                ?><span class="estab-telecom-station-kind">eigene
                  Betriebsstelle</span></h3>
                <ul class="estab-telecom-station-routes">
                  <?php foreach ($stelle['wege'] as $weg): ?>
                    <li>
                      <?= $wegZeile($weg) ?>
                      <?php $erreicht = $weg['gegenstellen'] ?? []; ?>
                      <?php if ($erreicht !== []): ?>
                        <ul class="estab-telecom-station-counterparts">
                          <?php foreach ($erreicht as $gegenstelle): ?>
                            <li><?= dv_operations_html(
                              (string) $gegenstelle['name'] . ' · '
                              . (string) $gegenstelle['erreichbarkeit']
                            ) ?><?php if (
                                ($gegenstelle['stellenart'] ?? null) !== null
                            ): ?>
                              <span class="estab-telecom-station-kind"><?=
                                dv_operations_html(
                                  ESTAB_DV_TELECOM_STATION_KINDS[
                                      $gegenstelle['stellenart']
                                  ] ?? (string) $gegenstelle['stellenart']
                                )
                              ?></span>
                            <?php endif; ?></li>
                          <?php endforeach; ?>
                        </ul>
                      <?php endif; ?>
                      <?php
                        $ersatz = $weg['weg_nummer'] === null
                          ? []
                          : ($ersatzwegeZu[(int) $weg['weg_nummer']] ?? []);
                      ?>
                      <?php if ($ersatz !== []): ?>
                        <ul class="estab-telecom-station-fallbacks">
                          <?php foreach ($ersatz as $ersatzweg): ?>
                            <li><span class="estab-telecom-station-fallback-mark">Ersatzweg</span>
                              <?= $wegZeile($ersatzweg) ?><?php
                                $fremd = (string) $ersatzweg['betriebsstelle'];
                              ?><?php if ($fremd !== (string) $stelleName): ?>
                                <span class="estab-telecom-station-elsewhere">über <?=
                                  dv_operations_html($fremd)
                                ?></span>
                              <?php endif; ?></li>
                          <?php endforeach; ?>
                        </ul>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php if ($stellen === []): ?>
            <p>Der aktive Plan führt keine Wege.</p>
          <?php endif; ?>
          <?php
            /*
             * Die Skizze ist erzeugt, nicht gezeichnet.
             *
             * Sie trägt denselben Stand wie die Liste darüber, weil sie aus
             * denselben Daten entsteht. Eine von Hand gepflegte Skizze wäre
             * nach der zweiten Planänderung falsch, ohne dass es jemand
             * merkte.
             */
          ?>
          <details class="estab-telecom-sketch-holder">
            <summary>Kommunikationsskizze nach Fb Fü 77</summary>
            <p class="estab-telecom-sketch-note">Erzeugt aus Version
              <?= (int) $activePlan['version'] ?> des Plans. Die Anordnung
              zeigt in der Mitte Ihre eigene Führungsstelle mit ihrem
              Funkrufnamen und ihren Mitteln, links die übergeordneten und
              rechts die nachgeordneten Gegenstellen. Die Linienart nennt das
              Mittel, auch ohne Farbe.</p>
            <?= estab_telecom_sketch_svg(
                $activePlan,
                $fuehrungsstellenName === ''
                    ? (string) $activePlan['herkunft']
                    : $fuehrungsstellenName
            ) ?>
          </details>
        <?php endif; ?>
        <?php if ($ausserplan['ohne_weg'] !== []
            || $ausserplan['abgeloest'] !== []): ?>
          <section class="estab-telecom-offplan"
            aria-labelledby="fernmeldeplan-ausserplan">
            <h3 id="fernmeldeplan-ausserplan">Verkehr, den der Plan nicht
              führt</h3>
            <?php foreach ([
                'ohne_weg' => 'Eingänge ohne Wegangabe',
                'abgeloest' => 'Eingänge über einen Weg, den die aktive '
                    . 'Fassung nicht mehr führt',
            ] as $art => $ueberschrift): ?>
              <?php if ($ausserplan[$art] !== []): ?>
                <h4><?= dv_operations_html($ueberschrift) ?></h4>
                <ul>
                  <?php foreach ($ausserplan[$art] as $zeile): ?>
                    <li><?= dv_operations_html(
                        estab_dv_telecom_medium_label($zeile['medium'])
                      ) ?>: <?= (int) $zeile['anzahl'] ?>
                      <?= $zeile['anzahl'] === 1 ? 'Eingang' : 'Eingänge' ?><?=
                        $zeile['zuletzt'] === null
                          ? ''
                          : ', zuletzt ' . dv_operations_html(
                              (string) $zeile['zuletzt']
                          )
                      ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>
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
              <label>Herausgebende Dienststelle
                <input name="herkunft" maxlength="255" required value="<?=
                  dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'herkunft',
                      ''
                  ))
                ?>">
                <small>Linkes Kopffeld nach Fb Fü 76.</small>
              </label>
              <label>Funktion des Verfassers
                <input name="verfasser_funktion" maxlength="120" value="<?=
                  dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'verfasser_funktion',
                      ''
                  ))
                ?>">
                <small>Freiwillig, etwa „S 6" oder „Fm-Zugführer".</small>
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
              <label>Verschlusssachenvermerk
                <input name="vs_vermerk" maxlength="40" value="<?=
                  dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'vs_vermerk',
                      'NfD'
                  ))
                ?>">
                <small>Rechtes Kopffeld. Vorgeschlagen ist „NfD"; die
                  Vorbelegung ist ein Vorschlag, keine Setzung.</small>
              </label>
              <label>Betriebsleitung
                <input name="betriebsleitung" maxlength="255" required
                  value="<?= dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'betriebsleitung',
                      ''
                  )) ?>">
              </label>
              <label>Dienststellung für die Freigabe
                <input name="freigabe_dienststellung" maxlength="120"
                  value="<?= dv_operations_html(dv_operations_post_value(
                      'create_plan',
                      'freigabe_dienststellung',
                      ''
                  )) ?>">
                <small>Erscheint bei der Freigabe als „F.d.R.".</small>
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
                  <label>Herausgebende Dienststelle
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
                  <label>Funktion des Verfassers
                    <input name="verfasser_funktion" maxlength="120"
                      value="<?= dv_operations_html(
                          dv_operations_post_value(
                              'update_plan',
                              'verfasser_funktion',
                              (string) ($plan['verfasser_funktion'] ?? ''),
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
                  <label>Verschlusssachenvermerk
                    <input name="vs_vermerk" maxlength="40"
                      value="<?= dv_operations_html(
                          dv_operations_post_value(
                              'update_plan',
                              'vs_vermerk',
                              (string) ($plan['vs_vermerk'] ?? 'NfD'),
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
                  <label>Dienststellung für die Freigabe
                    <input name="freigabe_dienststellung" maxlength="120"
                      value="<?= dv_operations_html(
                          dv_operations_post_value(
                              'update_plan',
                              'freigabe_dienststellung',
                              (string) (
                                  $plan['freigabe_dienststellung'] ?? ''
                              ),
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

            <?php
              /*
               * Die Nebenstellen der eigenen Führungsstelle.
               *
               * Fb Fü 77 zeichnet sie in der Mitte der Skizze, in der Tafel
               * unter der Führungsstelle: Technik, NSt-Nr., Teilnehmer. Sie
               * sind weder Weg noch Gegenstelle -- über sie wird niemand
               * draußen erreicht. Sie sagen, wer im Haus hinter welchem
               * Apparat sitzt, und das ist genau die Auskunft, die jemand
               * vor der Skizze braucht, wenn der Lagedienst ans Telefon soll
               * und nur der Raum bekannt ist.
               */
            ?>
            <section id="fernmeldeplan-nebenstellen"
              class="estab-telecom-extensions" aria-labelledby="<?=
                'telecom-extensions-' . $planId
              ?>">
              <header class="estab-telecom-routes-heading">
                <div>
                  <h3 id="<?= 'telecom-extensions-' . $planId ?>">
                    Nebenstellen der eigenen Führungsstelle
                  </h3>
                  <p>Die Tafel in der Mitte der Kommunikationsskizze: wer im
                    Haus hinter welchem Apparat zu erreichen ist.</p>
                </div>
                <span><?= count($plan['nebenstellen'] ?? []) ?>
                  Nebenstellen</span>
              </header>
              <?php if (($plan['nebenstellen'] ?? []) === []): ?>
                <p class="estab-tool-notice">Für diesen Entwurf ist noch keine
                  Nebenstelle erfasst.</p>
              <?php else: ?>
                <ul class="estab-telecom-counterpart-list">
                  <?php foreach ($plan['nebenstellen'] as $nebenstelle): ?>
                    <li>
                      <span><strong><?= dv_operations_html(
                          ESTAB_DV_TELECOM_EXTENSION_KINDS[
                              $nebenstelle['technik']
                          ] ?? (string) $nebenstelle['technik']
                      ) ?></strong> ·
                      <?= dv_operations_html($nebenstelle['nummer']) ?> ·
                      <?= dv_operations_html($nebenstelle['teilnehmer'])
                      ?><?= trim(
                          (string) ($nebenstelle['bemerkungen'] ?? '')
                      ) === ''
                          ? ''
                          : ' · ' . dv_operations_html(
                              (string) $nebenstelle['bemerkungen']
                          ) ?></span>
                      <form method="post" action="fuehrungsstelle.php">
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="operation_action"
                          value="delete_plan_extension">
                        <input type="hidden" name="fernmeldeplan_id"
                          value="<?= $planId ?>">
                        <input type="hidden" name="nebenstelle_id"
                          value="<?= (int) $nebenstelle['nebenstelle_id'] ?>">
                        <input type="hidden" name="plan_revision"
                          value="<?= dv_operations_html($revision) ?>">
                        <button
                          class="estab-button estab-button-danger-outline"
                          type="submit">Entfernen</button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <form class="estab-tool-form" method="post"
                action="fuehrungsstelle.php" data-estab-dirty-guard>
                <?= estab_csrf_field() ?>
                <input type="hidden" name="operation_action"
                  value="add_plan_extension">
                <input type="hidden" name="fernmeldeplan_id"
                  value="<?= $planId ?>">
                <input type="hidden" name="plan_revision"
                  value="<?= dv_operations_html($revision) ?>">
                <div class="estab-tool-form-grid">
                  <label>Technik
                    <select name="technik" required>
                      <?php foreach (
                          ESTAB_DV_TELECOM_EXTENSION_KINDS
                          as $technikWert => $technikText
                      ): ?>
                        <option value="<?= dv_operations_html($technikWert)
                          ?>"><?= dv_operations_html($technikText) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <small>Die Zeilen der Nebenstellentafel des Vordrucks.</small>
                  </label>
                  <label>NSt-Nr.
                    <input name="nummer" maxlength="40" required>
                    <small>Wie sie im Haus gewählt wird — „23",
                      „0228 940-1523" oder ein Apparatname.</small>
                  </label>
                  <label>Teilnehmer
                    <input name="teilnehmer" maxlength="255" required>
                    <small>Wer dort sitzt: Lagedienst, S 6, Meldekopf.</small>
                  </label>
                </div>
                <label>Bemerkungen
                  <textarea name="bemerkungen" maxlength="10000"></textarea>
                  <small>Besetzungszeiten, Einschränkungen.</small>
                </label>
                <button class="estab-button" type="submit">
                  Nebenstelle erfassen
                </button>
              </form>
            </section>

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
                  /*
                   * Die uebrigen Wege des Entwurfs, damit die Auswahl der
                   * Rueckfallebene sie anbieten kann. Der eigene ist
                   * gekennzeichnet und faellt in der Auswahl heraus.
                   */
                  $geschwister = [];
                  foreach ($plan['eintraege'] as $anderer) {
                      $geschwister[] = [
                          'weg_id' => (int) ($anderer['weg_id'] ?? 0),
                          'eigen' => (int) $anderer['fernmeldeplan_eintrag_id']
                              === $entryId,
                          'bezeichnung' => 'Weg '
                              . (int) ($anderer['weg_nummer'] ?? 0)
                              . ' · ' . (string) $anderer['betriebsstelle']
                              . ' · ' . estab_dv_telecom_route_label(
                                  $anderer['medium'],
                                  $anderer['funkart'] ?? null
                              ),
                      ];
                  }
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
                        $entry['erreichbarkeit']
                    ) ?></span>
                    <span><?= dv_operations_html(
                        estab_dv_telecom_route_label(
                            $entry['medium'],
                            $entry['funkart'] ?? null
                        )
                    ) ?></span>
                  </summary>
                  <form class="estab-tool-form" method="post"
                    action="fuehrungsstelle.php"
                    data-estab-telecom-entry-form
                    data-estab-telecom-entry-mode="edit"
                    data-estab-dirty-guard
                    data-estab-telecom-form-label="<?= dv_operations_html(
                        'Fernmeldeweg ' . $entry['betriebsstelle']
                            . ' / ' . $entry['erreichbarkeit']
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
                        $entryValues + ['geschwister' => $geschwister]
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
                  <section class="estab-telecom-counterparts">
                    <h4>Gegenstellen über diesen Weg</h4>
                    <p>Wer ist über diesen Weg zu erreichen, und unter welcher
                      Erreichbarkeit? Die Angaben stehen dem Fernmelder und
                      dem LdF später als Vorschlag im Vordruck zur Verfügung;
                      sie ersetzen die Felder der Nachricht nicht.</p>
                    <?php $gegenstellen = $entry['gegenstellen'] ?? []; ?>
                    <?php if ($gegenstellen === []): ?>
                      <p class="estab-tool-notice">Für diesen Weg ist noch
                        keine Gegenstelle erfasst.</p>
                    <?php else: ?>
                      <ul class="estab-telecom-counterpart-list">
                        <?php foreach ($gegenstellen as $gegenstelle): ?>
                          <li>
                            <span><strong><?= dv_operations_html(
                                $gegenstelle['name']
                            ) ?></strong> ·
                            <?= dv_operations_html(
                                $gegenstelle['erreichbarkeit']
                            ) ?><?= ($gegenstelle['stellenart'] ?? null) === null
                                ? ''
                                : ' · ' . dv_operations_html(
                                    ESTAB_DV_TELECOM_STATION_KINDS[
                                        $gegenstelle['stellenart']
                                    ] ?? (string) $gegenstelle['stellenart']
                                ) ?><?= trim(
                                (string) ($gegenstelle['bemerkungen'] ?? '')
                            ) === ''
                                ? ''
                                : ' · ' . dv_operations_html(
                                    (string) $gegenstelle['bemerkungen']
                                ) ?></span>
                            <form method="post" action="fuehrungsstelle.php">
                              <?= estab_csrf_field() ?>
                              <input type="hidden" name="operation_action"
                                value="delete_plan_counterpart">
                              <input type="hidden" name="fernmeldeplan_id"
                                value="<?= $planId ?>">
                              <input type="hidden"
                                name="fernmeldeplan_eintrag_id"
                                value="<?= $entryId ?>">
                              <input type="hidden" name="gegenstelle_id"
                                value="<?= (int)
                                  $gegenstelle['gegenstelle_id'] ?>">
                              <input type="hidden" name="plan_revision"
                                value="<?= dv_operations_html($revision) ?>">
                              <button
                                class="estab-button estab-button-danger-outline"
                                type="submit">Entfernen</button>
                            </form>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                    <form class="estab-tool-form" method="post"
                      action="fuehrungsstelle.php" data-estab-dirty-guard>
                      <?= estab_csrf_field() ?>
                      <input type="hidden" name="operation_action"
                        value="add_plan_counterpart">
                      <input type="hidden" name="fernmeldeplan_id"
                        value="<?= $planId ?>">
                      <input type="hidden" name="fernmeldeplan_eintrag_id"
                        value="<?= $entryId ?>">
                      <input type="hidden" name="plan_revision"
                        value="<?= dv_operations_html($revision) ?>">
                      <div class="estab-tool-form-grid">
                        <label>Name der Gegenstelle
                          <input name="name" maxlength="255" required>
                          <small>Klarbezeichnung der Stelle oder Einheit.</small>
                        </label>
                        <label>Stellenart
                          <select name="stellenart">
                            <option value="">ohne Angabe</option>
                            <?php foreach (
                                estab_dv_telecom_counterpart_kinds()
                                as $artWert => $artText
                            ): ?>
                              <option value="<?= dv_operations_html($artWert)
                                ?>"><?= dv_operations_html($artText) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <small>Steht diese Stelle über, unter oder neben
                            Ihnen? Die Skizze setzt übergeordnete nach links
                            und nachgeordnete nach rechts.</small>
                        </label>
                        <label><?= dv_operations_html(
                            'Erreichbar unter · '
                            . estab_dv_telecom_route_label(
                                $entry['medium'],
                                $entry['funkart'] ?? null
                            )
                        ) ?>
                          <input name="erreichbarkeit" maxlength="255" required>
                          <small>Das Mittel ist das dieses Wegs.</small>
                        </label>
                      </div>
                      <label>Bemerkungen
                        <textarea name="bemerkungen"
                          maxlength="10000"></textarea>
                        <small>Betriebszeiten, Einschränkungen.</small>
                      </label>
                      <button class="estab-button" type="submit">
                        Gegenstelle am Weg erfassen
                      </button>
                    </form>
                  </section>
                </details>
              <?php endforeach; ?>
              </div>
            </section>

            <?php
              $addValues = [];
              foreach (
                  [
                      'betriebsstelle', 'stellenart', 'erreichbarkeit',
                      'wegart', 'band', 'kanal', 'bandlage', 'verkehrsform',
                      'relaisstelle', 'betriebsart', 'rufgruppe',
                      'anschlussart', 'datenart', 'besondere_vermerke',
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
                <?php
                  $neueGeschwister = [];
                  foreach ($plan['eintraege'] as $anderer) {
                      $neueGeschwister[] = [
                          'weg_id' => (int) ($anderer['weg_id'] ?? 0),
                          'eigen' => false,
                          'bezeichnung' => 'Weg '
                              . (int) ($anderer['weg_nummer'] ?? 0)
                              . ' · ' . (string) $anderer['betriebsstelle']
                              . ' · ' . estab_dv_telecom_route_label(
                                  $anderer['medium'],
                                  $anderer['funkart'] ?? null
                              ),
                      ];
                  }
                  dv_operations_render_telecom_entry_fields(
                      $addValues + ['geschwister' => $neueGeschwister]
                  );
                ?>
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
                            estab_dv_telecom_route_label(
                                $entry['medium'],
                                $entry['funkart'] ?? null
                            ),
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
                            $entry['erreichbarkeit']
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
    <?php endif; ?>
  <?php endif; ?>

  <footer class="estab-tool-footer">
    <a href="melderauftraege.php">Zu den Melderaufträgen</a>
    <a href="mainindex.php">Zurück zu Nachrichten</a>
    <span>Alle Änderungen sind einsatzgebunden und hashverkettet.</span>
  </footer>
</main>
<script<?= estab_csp_script_attribute() ?> data-estab-telecom-kind-fields>
(function () {
  'use strict';
  var arten = <?= json_encode(
      ESTAB_DV_TELECOM_ROUTE_KINDS,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
          | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
  ) ?>;
  var felder = [
    'band', 'kanal', 'bandlage', 'verkehrsform', 'relaisstelle',
    'betriebsart', 'rufgruppe', 'anschlussart', 'datenart'
  ];
  function update(form) {
    var select = form.querySelector('[data-estab-telecom-kind]');
    if (!select) return;
    var art = arten[select.value] || null;
    var reach = form.querySelector(
      '[data-estab-telecom-field-label="rufname"]'
    );
    if (reach) {
      reach.textContent = art ? art.erreichbarkeit : 'Erreichbar unter';
    }
    felder.forEach(function (fieldName) {
      var wrapper = form.querySelector(
        '[data-estab-telecom-field="' + fieldName + '"]'
      );
      if (!wrapper) return;
      var input = wrapper.querySelector('input, select, textarea');
      var label = wrapper.querySelector(
        '[data-estab-telecom-field-label="' + fieldName + '"]'
      );
      var fieldLabel = art && art.felder ? art.felder[fieldName] : null;
      var visible = typeof fieldLabel === 'string' && fieldLabel !== '';
      wrapper.hidden = !visible;
      if (input) {
        input.disabled = !visible;
        input.required = visible
          && art.pflicht.indexOf(fieldName) !== -1;
      }
      if (label && visible) label.textContent = fieldLabel;
    });
  }
  document.querySelectorAll('[data-estab-telecom-entry-form]')
    .forEach(function (form) {
      update(form);
      var select = form.querySelector('[data-estab-telecom-kind]');
      if (select) select.addEventListener('change', function () {
        update(form);
      });
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
