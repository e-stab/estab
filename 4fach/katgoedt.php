<?php

declare(strict_types=1);

/**
 * Active category editor and message-assignment endpoint.
 *
 * GET is read-only (list and edit form). Every mutation is POST-only, bound to
 * the current session by CSRF and followed by a 303 redirect.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../app/workflow.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';
require_once __DIR__ . '/../app/category.php';
require_once __DIR__ . '/../app/message_repository.php';
require_once __DIR__ . '/../app/navigation.php';
require_once __DIR__ . '/../app/read_authorization.php';
require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/e_cfg.inc.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$identity = estab_auth_session_identity($_SESSION);
if ($identity === null) {
    estab_navigation_require_session(
        $_SESSION,
        'messages',
        $_SERVER,
        true
    );
    throw new LogicException('Authenticated category identity missing');
}
$categoryReadIdentity = estab_read_session_identity($_SESSION);
estab_session_ui_start($_SESSION, false, true);

/** @var array<string,string> $conf_4f_db */
/** @var array<string,string> $conf_4f_tbl */
$connection = null;

/** Send a non-disclosing endpoint error. */
function estab_category_endpoint_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/** Redirect only to a fixed application route. */
function estab_category_endpoint_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

/** Build the category manager URL without mixing raw values into HTML. */
function estab_category_manager_url(string $type, int $messageId, string $status = ''): string
{
    $query = ['dbtyp' => $type, 'msgno' => (string) $messageId];
    if ($status !== '') {
        $query['status'] = $status;
    }
    return 'katgoedt.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/** Render the authenticated manager page. */
function estab_category_render_manager(
    mysqli $connection,
    array $scope,
    int $messageId,
    ?array $editing,
    string $status
): void {
    $rows = estab_category_fetch_all($connection, $scope);
    $type = (string) $scope['type'];
    $statusMessages = [
        'created' => 'Kategorie wurde angelegt.',
        'updated' => 'Kategorie wurde geändert.',
        'deleted' => 'Kategorie wurde gelöscht.',
    ];
    $heading = match ($type) {
        'master' => 'Globale Kategorien',
        'fkt' => 'Funktionskategorien',
        'user' => 'Persönliche Kategorien',
    };
    $scopeDescription = match ($type) {
        'master' => 'Diese Kategorien stehen allen berechtigten Funktionen zur Verfügung.',
        'fkt' => 'Diese Kategorien gelten für die aktuell angemeldete Funktion.',
        'user' => 'Diese Kategorien gehören ausschließlich zum angemeldeten Konto.',
    };
    $formAction = 'katgoedt.php';
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!doctype html>\n";
    echo "<html lang=\"de\"><head><meta charset=\"UTF-8\">";
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . estab_auth_html($heading) . '</title>';
    echo estab_session_ui_stylesheet();
    echo '</head><body class="estab-tool-page">';
    echo '<main class="estab-tool-main" data-estab-category-manager>';
    echo '<header class="estab-tool-hero">';
    echo '<p class="estab-tool-eyebrow">Nachrichten · Kategorien</p>';
    echo '<h1>' . estab_auth_html($heading) . '</h1>';
    echo '<p>' . estab_auth_html($scopeDescription)
        . ' Änderungen werden sofort für die Nachricht '
        . estab_auth_html($messageId) . ' angeboten.</p>';
    echo '</header>';
    if (isset($statusMessages[$status])) {
        echo '<p class="estab-tool-feedback estab-tool-feedback-success" role="status">'
            . estab_auth_html($statusMessages[$status]) . '</p>';
    }
    echo '<aside class="estab-tool-notice" aria-label="Bearbeitete Nachricht">';
    echo '<strong>Aktuelle Nachricht: ' . estab_auth_html($messageId) . '</strong>';
    echo '<p>Nach dem Anlegen oder Bearbeiten können Sie die Kategorie im '
        . 'Nachrichtenvordruck zuweisen.</p></aside>';

    echo '<section class="estab-tool-panel" aria-labelledby="category-list-title">';
    echo '<header class="estab-tool-panel-heading">';
    echo '<h2 id="category-list-title">Vorhandene Kategorien</h2>';
    echo '<p>Bearbeiten Sie Bezeichnung und Beschreibung oder entfernen Sie '
        . 'nicht mehr benötigte Kategorien.</p></header>';
    echo '<div class="estab-tool-table-wrap estab-tool-table-responsive">';
    echo '<table class="estab-tool-table">';
    echo '<caption class="estab-visually-hidden">'
        . estab_auth_html($heading) . '</caption>';
    echo '<thead><tr><th scope="col">Kategorie</th>'
        . '<th scope="col">Beschreibung</th><th scope="col">Aktionen</th>'
        . '</tr></thead><tbody>';
    if ($rows === []) {
        echo '<tr><td colspan="3" class="estab-tool-empty">'
            . 'Noch keine Kategorien vorhanden.</td></tr>';
    }
    foreach ($rows as $row) {
        $categoryId = (int) $row['lfd'];
        $editUrl = estab_category_manager_url($type, $messageId)
            . '&edit_id=' . rawurlencode((string) $categoryId);
        echo '<tr><td data-label="Kategorie"><strong>'
            . estab_auth_html($row['kategorie']) . '</strong></td>';
        echo '<td data-label="Beschreibung">'
            . estab_auth_html($row['beschreibung']) . '</td>';
        echo '<td data-label="Aktionen"><div class="estab-tool-actions">';
        echo '<a class="estab-button" href="' . estab_auth_html($editUrl)
            . '">Bearbeiten</a>';
        echo '<details class="estab-tool-details">';
        echo '<summary>Kategorie löschen …</summary>';
        echo '<p>Diese Kategorie und ihre Zuordnungen werden entfernt.</p>';
        echo '<form method="post" action="' . estab_auth_html($formAction) . '">';
        echo estab_csrf_field();
        echo '<input type="hidden" name="category_action" value="delete">';
        echo '<input type="hidden" name="dbtyp" value="' . estab_auth_html($type) . '">';
        echo '<input type="hidden" name="msgno" value="' . estab_auth_html($messageId) . '">';
        echo '<input type="hidden" name="category_id" value="'
            . estab_auth_html($categoryId) . '">';
        echo '<button class="estab-button estab-button-danger-outline" '
            . 'type="submit" aria-label="Kategorie '
            . estab_auth_html($row['kategorie']) . ' löschen">Löschen</button>';
        echo '</form></details>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></div></section>';

    $isUpdate = $editing !== null;
    echo '<section class="estab-tool-panel" aria-labelledby="category-form-title">';
    echo '<header class="estab-tool-panel-heading">';
    echo '<h2 id="category-form-title">'
        . ($isUpdate ? 'Kategorie bearbeiten' : 'Neue Kategorie anlegen')
        . '</h2>';
    echo '<p>' . ($isUpdate
        ? 'Speichern Sie die angepassten Werte oder brechen Sie die Bearbeitung ab.'
        : 'Legen Sie eine kurze Bezeichnung und eine verständliche Beschreibung fest.')
        . '</p></header>';
    echo '<form class="estab-tool-form" method="post" action="'
        . estab_auth_html($formAction) . '" data-estab-dirty-guard>';
    echo estab_csrf_field();
    echo '<input type="hidden" name="category_action" value="'
        . ($isUpdate ? 'update' : 'create') . '">';
    echo '<input type="hidden" name="dbtyp" value="' . estab_auth_html($type) . '">';
    echo '<input type="hidden" name="msgno" value="' . estab_auth_html($messageId) . '">';
    if ($isUpdate) {
        echo '<input type="hidden" name="category_id" value="'
            . estab_auth_html($editing['lfd']) . '">';
    }
    echo '<div class="estab-tool-form-grid">';
    echo '<div class="estab-tool-field">';
    echo '<label for="category-name">Kategorie</label>';
    echo '<input id="category-name" type="text" name="kategorie" maxlength="10" '
        . 'required autocomplete="off" value="'
        . estab_auth_html($editing['kategorie'] ?? '') . '">';
    echo '<small>Höchstens 10 Zeichen.</small></div>';
    echo '<div class="estab-tool-field">';
    echo '<label for="category-description">Beschreibung</label>';
    echo '<input id="category-description" type="text" name="beschreibung" '
        . 'maxlength="254" autocomplete="off" value="'
        . estab_auth_html($editing['beschreibung'] ?? '') . '">';
    echo '<small>Optional, höchstens 254 Zeichen.</small></div></div>';
    echo '<div class="estab-tool-actions">';
    echo '<button class="estab-button estab-button-primary" type="submit">'
        . ($isUpdate ? 'Änderung speichern' : 'Kategorie anlegen')
        . '</button>';
    if ($isUpdate) {
        echo '<a class="estab-button" href="' . estab_auth_html(
            estab_category_manager_url($type, $messageId)
        ) . '">Abbrechen</a>';
    }
    echo '</div></form></section>';
    echo '<footer class="estab-tool-footer">';
    echo '<a href="mainindex.php">Zum Nachrichtenvordruck</a>';
    echo '<span>Kategorien sind nach ihrem Gültigkeitsbereich getrennt.</span>';
    echo '</footer></main></body></html>';
}

try {
    $connection = estab_auth_connect($conf_4f_db);
    $readScope = estab_read_require_operational_scope(
        $connection,
        estab_read_session_identity($_SESSION) ?? []
    );
    $identity = $readScope['identity'];
    $redcopy = estab_category_redcopy_function(
        $connection,
        (string) $conf_4f_tbl['empfmtx']
    );
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        try {
            estab_csrf_require_post($_SERVER, $_POST);
        } catch (Throwable) {
            estab_workflow_forbid();
        }
        $activeIncident = estab_incident_active($connection);
        if ($activeIncident === null) {
            throw new EstabCategoryConflictException(
                'Kategorien können nur während eines aktiven Einsatzes '
                . 'geändert werden.'
            );
        }
        estab_dv_require_operational_account(
            $connection,
            (int) $activeIncident['active_einsatz_id'],
            $identity
        );

        $action = $_POST['category_action'] ?? null;
        if (!is_string($action) || !in_array(
            $action,
            ['create', 'update', 'delete', 'assign'],
            true
        )) {
            throw new EstabCategoryInputException('Unbekannte Kategorienaktion.');
        }

        if ($action === 'assign') {
            $messageId = estab_category_positive_id(
                $_POST['msglfd'] ?? $_POST['message_id'] ?? null,
                'Meldungs-ID'
            );
            $message = estab_read_message(
                $connection,
                (string) $conf_4f_tbl['nachrichten'],
                $messageId,
                $identity
            );
            if (
                !is_array($message)
                || !estab_message_object_allowed($identity, 'staff-read', $message)
            ) {
                throw new EstabCategoryAuthorizationException(
                    'Keine Berechtigung für diese Meldung.'
                );
            }
            $scopes = [];
            $assignments = [];
            foreach (ESTAB_CATEGORY_TYPES as $type) {
                $scope = estab_category_scope($type, $identity, $conf_4f_tbl);
                $currentId = estab_category_fetch_assignment_id(
                    $connection,
                    $scope,
                    $messageId
                );
                $selection = estab_category_resolve_selection(
                    $_POST,
                    $type,
                    $currentId
                );
                if (!$selection['present']) {
                    continue;
                }
                estab_category_require_management($type, $identity, $redcopy);
                $scopes[$type] = $scope;
                $assignments[$type] = $selection['value'];
            }
            if ($assignments === []) {
                throw new EstabCategoryInputException('Keine Kategorieauswahl übermittelt.');
            }
            estab_category_assign(
                $connection,
                $messageId,
                (string) $conf_4f_tbl['nachrichten'],
                $identity,
                $scopes,
                $assignments,
                (string) $conf_4f_tbl['empfmtx']
            );
            estab_category_endpoint_redirect(
                'mainindex.php'
            );
        }

        $type = estab_category_validate_type($_POST['dbtyp'] ?? null);
        $messageId = estab_category_positive_id($_POST['msgno'] ?? null, 'Meldungs-ID');
        if (
            estab_read_message(
                $connection,
                (string) $conf_4f_tbl['nachrichten'],
                $messageId,
                $identity
            ) === null
        ) {
            throw new EstabCategoryAuthorizationException(
                'Keine Berechtigung für diese Meldung.'
            );
        }
        estab_category_require_management($type, $identity, $redcopy);
        $scope = estab_category_scope($type, $identity, $conf_4f_tbl);
        $status = '';
        if ($action === 'create') {
            estab_category_create($connection, $scope, $_POST);
            $status = 'created';
        } elseif ($action === 'update') {
            $categoryId = estab_category_positive_id(
                $_POST['category_id'] ?? null,
                'Kategorie-ID'
            );
            estab_category_update($connection, $scope, $categoryId, $_POST);
            $status = 'updated';
        } else {
            $categoryId = estab_category_positive_id(
                $_POST['category_id'] ?? null,
                'Kategorie-ID'
            );
            estab_category_delete($connection, $scope, $categoryId);
            estab_category_clear_session_filter($_SESSION, $type);
            $status = 'deleted';
        }
        estab_category_endpoint_redirect(estab_category_manager_url($type, $messageId, $status));
    }

    if ($method !== 'GET') {
        header('Allow: GET, POST');
        estab_category_endpoint_error(405, 'Methode nicht erlaubt.');
    }

    $type = estab_category_validate_type($_GET['dbtyp'] ?? null);
    $messageId = estab_category_positive_id($_GET['msgno'] ?? null, 'Meldungs-ID');
    if (
        estab_read_message(
            $connection,
            (string) $conf_4f_tbl['nachrichten'],
            $messageId,
            $identity
        ) === null
    ) {
        throw new EstabCategoryAuthorizationException(
            'Keine Berechtigung für diese Meldung.'
        );
    }
    estab_category_require_management($type, $identity, $redcopy);
    $scope = estab_category_scope($type, $identity, $conf_4f_tbl);
    $editing = null;
    if (array_key_exists('edit_id', $_GET)) {
        $categoryId = estab_category_positive_id($_GET['edit_id'], 'Kategorie-ID');
        $editing = estab_category_fetch_one($connection, $scope, $categoryId);
        if ($editing === null) {
            throw new EstabCategoryNotFoundException('Kategorie wurde nicht gefunden.');
        }
    }
    $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
    estab_category_render_manager($connection, $scope, $messageId, $editing, $status);
} catch (EstabCategoryAuthorizationException $exception) {
    estab_category_endpoint_error(403, 'Aktion nicht erlaubt.');
} catch (EstabReadPermissionException $exception) {
    estab_category_endpoint_error(403, 'Aktion nicht erlaubt.');
} catch (EstabNoActiveIncidentException $exception) {
    estab_category_endpoint_error(409, 'Kein Einsatz ist aktiv.');
} catch (EstabDvPermissionException $exception) {
    estab_category_endpoint_error(423, $exception->getMessage());
} catch (EstabCategoryNotFoundException $exception) {
    estab_category_endpoint_error(404, $exception->getMessage());
} catch (EstabCategoryInputException|EstabCategoryConflictException $exception) {
    estab_category_endpoint_error(422, $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Category endpoint failure: ' . $exception->getMessage());
    estab_category_endpoint_error(500, 'Kategorien konnten nicht verarbeitet werden.');
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}
