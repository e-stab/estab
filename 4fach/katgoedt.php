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
require_once __DIR__ . '/../app/tabelle.php';

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
estab_navigation_require_selected_duty(
    $_SESSION,
    $categoryReadIdentity,
    'messages',
    $_SERVER
);
estab_session_ui_start($_SESSION, false, true);

/** @var array<string,string> $conf_4f_db */
/** @var array<string,string> $conf_4f_tbl */
$connection = null;

/** Keep category failures inside the styled application popup. */
function estab_category_endpoint_error(int $status, string $message): never
{
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    $title = match ($status) {
        403 => 'Aktion nicht erlaubt',
        404 => 'Kategorie nicht gefunden',
        409, 423 => 'Vorgang derzeit nicht möglich',
        422 => 'Eingabe konnte nicht verarbeitet werden',
        default => 'Kategorien vorübergehend nicht verfügbar',
    };
    estab_session_ui_abort(
        $_SESSION,
        $status,
        $title,
        $message,
        'messages',
        true
    );
}

/** Redirect only to a fixed application route. */
function estab_category_endpoint_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

/** Build the category manager URL without mixing raw values into HTML. */
function estab_category_manager_url(
    string $type,
    int $messageId,
    string $status = '',
    string $actingFunction = ''
): string
{
    $query = ['dbtyp' => $type, 'msgno' => (string) $messageId];
    if ($status !== '') {
        $query['status'] = $status;
    }
    if ($actingFunction !== '') {
        $query['acting_function'] = $actingFunction;
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
    $type = estab_category_validate_type($scope['type'] ?? null);
    $actingFunction = (string) ($scope['acting_function'] ?? '');
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
        'master' => 'Diese Kategorien stehen allen berechtigten Funktionen zur '
            . 'Verfügung. Eine leere Erstkonfiguration beginnt mit Allgemein '
            . 'und EA1 bis EA6 als anpassbarer Grundstruktur.',
        'fkt' => 'Diese Kategorien gelten für die aktuell wirksame Funktion.',
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
    /*
     * Die Kategorienliste kommt aus dem Tabellenbauteil.
     *
     * Eine Fuehrungsstelle sammelt im Lauf einer Uebung schnell dreissig
     * Kategorien; ohne Suche findet man eine bestimmte nur mit dem Finger
     * am Bildschirm.
     */
    $kategorieZeilen = array ();
    foreach ($rows as $row) {
        $categoryId = (int) $row['lfd'];
        $editUrl = estab_category_manager_url(
            $type,
            $messageId,
            '',
            $actingFunction
        )
            . '&edit_id=' . rawurlencode((string) $categoryId);
        $kategorieZeilen[] = array(
            'kategorie' => (string) $row['kategorie'],
            'beschreibung' => (string) $row['beschreibung'],
            'kennung' => $categoryId,
            'bearbeiten' => $editUrl,
        );
    }
    echo estab_tabelle_markup(array(
        'id' => 'kategorien',
        'beschriftung' => $heading,
        'mindestbreite' => '44rem',
        // Wenige Spalten auf schmalem Platz: erst spaet zu Karten.
        'schmal' => true,
        'spalten' => array(
            array('schluessel' => 'kategorie', 'kopf' => 'Kategorie',
                'breite' => 26, 'sortierbar' => true, 'suchbar' => true,
                'art' => 'text',
                'zelle' => static fn (array $z): string =>
                    '<strong>' . estab_auth_html($z['kategorie']) . '</strong>'),
            array('schluessel' => 'beschreibung', 'kopf' => 'Beschreibung',
                'breite' => 48, 'sortierbar' => true, 'suchbar' => true,
                'art' => 'text', 'klammern' => true),
            array('schluessel' => 'aktion', 'kopf' => 'Aktionen',
                'breite' => 26, 'sortierbar' => false, 'suchbar' => false,
                'art' => 'text',
                'zelle' => static function (array $z) use (
                    $formAction,
                    $type,
                    $messageId,
                    $actingFunction
                ): string {
                    /*
                     * Das Loeschen liegt hinter einem Aufklappfeld. Ein
                     * Knopf, der ohne Rueckfrage loescht, steht sonst
                     * neben einem, der nur bearbeitet -- und beide sehen
                     * gleich aus.
                     */
                    return '<div class="estab-tool-actions">'
                        . '<a class="estab-button" href="'
                        . estab_auth_html($z['bearbeiten'])
                        . '">Bearbeiten</a>'
                        . '<details class="estab-tool-details">'
                        . '<summary>Kategorie löschen …</summary>'
                        . '<p>Diese Kategorie und ihre Zuordnungen werden '
                        . 'entfernt.</p>'
                        . '<form method="post" action="'
                        . estab_auth_html($formAction) . '">'
                        . estab_csrf_field()
                        . '<input type="hidden" name="category_action" value="delete">'
                        . '<input type="hidden" name="dbtyp" value="'
                        . estab_auth_html($type) . '">'
                        . '<input type="hidden" name="msgno" value="'
                        . estab_auth_html($messageId) . '">'
                        . '<input type="hidden" name="acting_function" value="'
                        . estab_auth_html($actingFunction) . '">'
                        . '<input type="hidden" name="category_id" value="'
                        . estab_auth_html($z['kennung']) . '">'
                        . '<button class="estab-button estab-button-danger-outline" '
                        . 'type="submit" aria-label="Kategorie '
                        . estab_auth_html($z['kategorie'])
                        . ' löschen">Löschen</button>'
                        . '</form></details></div>';
                }),
        ),
        'zeilen' => $kategorieZeilen,
        'leer' => 'Noch keine Kategorien vorhanden.',
    ));
    echo '</section>';

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
    echo '<input type="hidden" name="acting_function" value="'
        . estab_auth_html($actingFunction) . '">';
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
            estab_category_manager_url(
                $type,
                $messageId,
                '',
                $actingFunction
            )
        ) . '">Abbrechen</a>';
    }
    echo '</div></form></section>';
    echo '<footer class="estab-tool-footer">';
    echo '<a href="mainindex.php">Zum Nachrichtenvordruck</a>';
    echo '<span>Kategorien sind nach ihrem Gültigkeitsbereich getrennt.</span>';
    echo '</footer></main></body></html>';
}

$categoryEndpointError = null;
$categoryRedirect = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $readScope = estab_read_require_operational_scope(
        $connection,
        estab_read_session_identity($_SESSION) ?? []
    );
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $actingFunction = $method === 'POST'
        ? ($_POST['acting_function'] ?? $_GET['acting_function'] ?? null)
        : ($_GET['acting_function'] ?? null);
    $identity = estab_category_identity_for_function(
        $readScope['identity'],
        $actingFunction
    );
    $redcopy = estab_category_redcopy_function(
        $connection,
        (string) $conf_4f_tbl['empfmtx']
    );
    if ($method === 'POST') {
        try {
            estab_csrf_require_post($_SERVER, $_POST);
        } catch (EstabCsrfException) {
            http_response_code(403);
            throw new EstabCategoryAuthorizationException(
                'Aktion nicht erlaubt.'
            );
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
            $categoryRedirect = 'mainindex.php';
        } else {
            $type = estab_category_validate_type($_POST['dbtyp'] ?? null);
            $messageId = estab_category_positive_id(
                $_POST['msgno'] ?? null,
                'Meldungs-ID'
            );
            $scope = estab_category_scope($type, $identity, $conf_4f_tbl);
            $categoryId = null;
            if ($action !== 'create') {
                $categoryId = estab_category_positive_id(
                    $_POST['category_id'] ?? null,
                    'Kategorie-ID'
                );
            }
            estab_category_mutate_authorized(
                $connection,
                $action,
                $messageId,
                (string) $conf_4f_tbl['nachrichten'],
                $identity,
                $scope,
                $categoryId,
                $_POST,
                (string) $conf_4f_tbl['empfmtx']
            );
            $status = '';
            if ($action === 'create') {
                $status = 'created';
            } elseif ($action === 'update') {
                $status = 'updated';
            } else {
                estab_category_clear_session_filter($_SESSION, $type);
                $status = 'deleted';
            }
            $categoryRedirect = estab_category_manager_url(
                $type,
                $messageId,
                $status,
                (string) $identity['funktion']
            );
        }
    } elseif ($method !== 'GET') {
        header('Allow: GET, POST');
        $categoryEndpointError = [405, 'Methode nicht erlaubt.'];
    } else {
        $type = estab_category_validate_type($_GET['dbtyp'] ?? null);
        $messageId = estab_category_positive_id(
            $_GET['msgno'] ?? null,
            'Meldungs-ID'
        );
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
            $categoryId = estab_category_positive_id(
                $_GET['edit_id'],
                'Kategorie-ID'
            );
            $editing = estab_category_fetch_one(
                $connection,
                $scope,
                $categoryId
            );
            if ($editing === null) {
                throw new EstabCategoryNotFoundException(
                    'Kategorie wurde nicht gefunden.'
                );
            }
        }
        $status = isset($_GET['status']) && is_string($_GET['status'])
            ? $_GET['status']
            : '';
        estab_category_render_manager(
            $connection,
            $scope,
            $messageId,
            $editing,
            $status
        );
    }
} catch (EstabCategoryAuthorizationException $exception) {
    $categoryEndpointError = [403, 'Aktion nicht erlaubt.'];
} catch (EstabReadPermissionException $exception) {
    $categoryEndpointError = [403, 'Aktion nicht erlaubt.'];
} catch (EstabNoActiveIncidentException $exception) {
    $categoryEndpointError = [409, 'Kein Einsatz ist aktiv.'];
} catch (EstabDvPermissionException $exception) {
    $categoryEndpointError = [423, $exception->getMessage()];
} catch (EstabCategoryNotFoundException $exception) {
    $categoryEndpointError = [404, $exception->getMessage()];
} catch (EstabCategoryInputException|EstabCategoryConflictException $exception) {
    $categoryEndpointError = [422, $exception->getMessage()];
} catch (Throwable $exception) {
    error_log('Category endpoint failure: ' . $exception->getMessage());
    $categoryEndpointError = [500, 'Kategorien konnten nicht verarbeitet werden.'];
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}
if (is_array($categoryEndpointError)) {
    estab_category_endpoint_error(
        $categoryEndpointError[0],
        $categoryEndpointError[1]
    );
}
if (is_string($categoryRedirect)) {
    estab_category_endpoint_redirect($categoryRedirect);
}
