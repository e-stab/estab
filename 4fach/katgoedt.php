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
require_once __DIR__ . '/../app/category.php';
require_once __DIR__ . '/../app/message_repository.php';
require_once __DIR__ . '/../4fcfg/config.inc.php';
require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../4fcfg/e_cfg.inc.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$identity = estab_auth_session_identity($_SESSION);
if ($identity === null) {
    estab_workflow_forbid();
}

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
    $formAction = 'katgoedt.php';
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!doctype html>\n";
    echo "<html lang=\"de\"><head><meta charset=\"UTF-8\">";
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . estab_auth_html($heading) . '</title>';
    echo '<style>'
        . 'body{font-family:system-ui,sans-serif;max-width:70rem;margin:2rem auto;padding:0 1rem}'
        . 'table{border-collapse:collapse;width:100%;margin:1rem 0}'
        . 'th,td{border:1px solid #aaa;padding:.55rem;text-align:left;vertical-align:top}'
        . '.actions{display:flex;gap:.5rem;align-items:center}.actions form{margin:0}'
        . '.notice{padding:.7rem;background:#e7f5e7;border:1px solid #5a8}'
        . 'label{display:block;margin:.7rem 0}input[type=text]{width:min(100%,48rem);padding:.35rem}'
        . 'button,a.button{padding:.4rem .7rem}'
        . '</style></head><body>';
    echo '<h1>' . estab_auth_html($heading) . '</h1>';
    echo '<p>Meldung ' . estab_auth_html($messageId) . '</p>';
    if (isset($statusMessages[$status])) {
        echo '<p class="notice" role="status">' . estab_auth_html($statusMessages[$status]) . '</p>';
    }
    echo '<p><a href="mainindex.php">Zur Übersicht</a></p>';

    echo '<table><thead><tr><th>Kategorie</th><th>Beschreibung</th><th>Aktionen</th>'
        . '</tr></thead><tbody>';
    if ($rows === []) {
        echo '<tr><td colspan="3">Noch keine Kategorien vorhanden.</td></tr>';
    }
    foreach ($rows as $row) {
        $categoryId = (int) $row['lfd'];
        $editUrl = estab_category_manager_url($type, $messageId)
            . '&edit_id=' . rawurlencode((string) $categoryId);
        echo '<tr><td>' . estab_auth_html($row['kategorie']) . '</td>';
        echo '<td>' . estab_auth_html($row['beschreibung']) . '</td>';
        echo '<td><div class="actions">';
        echo '<a class="button" href="' . estab_auth_html($editUrl) . '">Bearbeiten</a>';
        echo '<form method="post" action="' . estab_auth_html($formAction) . '">';
        echo estab_csrf_field();
        echo '<input type="hidden" name="category_action" value="delete">';
        echo '<input type="hidden" name="dbtyp" value="' . estab_auth_html($type) . '">';
        echo '<input type="hidden" name="msgno" value="' . estab_auth_html($messageId) . '">';
        echo '<input type="hidden" name="category_id" value="'
            . estab_auth_html($categoryId) . '">';
        echo '<button type="submit">Löschen</button></form>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table>';

    $isUpdate = $editing !== null;
    echo '<h2>' . ($isUpdate ? 'Kategorie bearbeiten' : 'Neue Kategorie') . '</h2>';
    echo '<form method="post" action="' . estab_auth_html($formAction) . '">';
    echo estab_csrf_field();
    echo '<input type="hidden" name="category_action" value="'
        . ($isUpdate ? 'update' : 'create') . '">';
    echo '<input type="hidden" name="dbtyp" value="' . estab_auth_html($type) . '">';
    echo '<input type="hidden" name="msgno" value="' . estab_auth_html($messageId) . '">';
    if ($isUpdate) {
        echo '<input type="hidden" name="category_id" value="'
            . estab_auth_html($editing['lfd']) . '">';
    }
    echo '<label>Kategorie '
        . '<input type="text" name="kategorie" maxlength="10" required value="'
        . estab_auth_html($editing['kategorie'] ?? '') . '"></label>';
    echo '<label>Beschreibung '
        . '<input type="text" name="beschreibung" maxlength="254" value="'
        . estab_auth_html($editing['beschreibung'] ?? '') . '"></label>';
    echo '<button type="submit">' . ($isUpdate ? 'Änderung speichern' : 'Kategorie anlegen')
        . '</button>';
    if ($isUpdate) {
        echo ' <a href="' . estab_auth_html(
            estab_category_manager_url($type, $messageId)
        ) . '">Abbrechen</a>';
    }
    echo '</form></body></html>';
}

try {
    $connection = estab_auth_connect($conf_4f_db);
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
            $message = estab_message_fetch_by_id(
                $connection,
                (string) $conf_4f_tbl['nachrichten'],
                $messageId
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
                $assignments
            );
            estab_category_endpoint_redirect(
                'mainindex.php'
            );
        }

        $type = estab_category_validate_type($_POST['dbtyp'] ?? null);
        $messageId = estab_category_positive_id($_POST['msgno'] ?? null, 'Meldungs-ID');
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
