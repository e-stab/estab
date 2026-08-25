<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csp.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/incident_ui.php';
require_once __DIR__ . '/navigation.php';
require_once __DIR__ . '/app_shell.php';

/** Return the configured browser root used by shared session controls. */
function estab_session_ui_root(): string
{
    return estab_application_root();
}

/** Return the shared stylesheet element for standalone application pages. */
function estab_session_ui_stylesheet(): string
{
    return '<link rel="stylesheet" href="'
        . estab_auth_html(estab_session_ui_root() . 'estab-ui.css')
        . '">';
}

/**
 * Return the separately authenticated Basic-Auth user on admin routes.
 *
 * REMOTE_USER is trusted only as display context after the web server has
 * admitted the request. It never becomes an eStab role or session identity.
 */
function estab_session_ui_admin_user(array $server): ?string
{
    if (estab_navigation_active_key($server) !== 'administration') {
        return null;
    }
    $user = $server['REMOTE_USER'] ?? null;
    if (!is_string($user) || trim($user) === '') {
        return null;
    }
    return trim($user);
}

/** Resolve an optional safe destination carried by the current login tab. */
function estab_session_ui_login_destination(
    array $get,
    array $post
): ?string {
    foreach ([$post, $get] as $input) {
        if (array_key_exists('next', $input)) {
            return estab_navigation_login_destination_key($input['next']);
        }
    }
    return null;
}

/**
 * Render one validated application session and its protected logout control.
 *
 * The session shape is validated by the same function that guards protected
 * endpoints. Every displayed value and attribute is escaped at this boundary.
 */
function estab_session_ui_markup(
    array $session,
    string $csrfToken,
    bool $compact = false,
    array $server = [],
    bool $popup = false,
    bool $sidebar = false,
    bool $includeNavigation = true,
    ?array $incidentState = null,
    bool $includeBrand = true
): string {
    $identity = estab_auth_session_identity($session);
    if ($identity === null) {
        return '';
    }
    if (preg_match('/\A[a-f0-9]{64}\z/D', $csrfToken) !== 1) {
        throw new InvalidArgumentException('Invalid session UI CSRF token');
    }

    if ($sidebar && !$compact) {
        throw new InvalidArgumentException(
            'Sidebar session UI requires compact presentation'
        );
    }
    $barClass = $compact
        ? 'estab-session-bar estab-session-bar-compact'
        : 'estab-session-bar';
    if ($sidebar) {
        $barClass .= ' estab-session-bar-sidebar';
    }
    $homeUrl = estab_application_root();
    $logoutUrl = estab_application_url('4fach/logout.php');
    $navigation = $includeNavigation
        ? estab_navigation_markup(
            true,
            $server === [] ? $_SERVER : $server,
            $compact,
            $sidebar,
            $identity
        )
        : '';
    $name = estab_auth_html($identity['benutzer']);
    $code = estab_auth_html($identity['kuerzel']);
    $function = estab_auth_html($identity['funktion']);
    $role = estab_auth_html($identity['rolle']);
    $functionAndRole = estab_auth_html(
        estab_function_identity_display_name(
            $identity['funktion'],
            $identity['rolle'],
            ' · Rolle '
        )
    );
    $adminUser = estab_session_ui_admin_user(
        $server === [] ? $_SERVER : $server
    );
    $adminContext = $adminUser === null
        ? ''
        : '<span class="estab-session-admin-context">'
            . 'Administrationszugang <strong data-estab-admin-user="'
            . estab_auth_html($adminUser) . '">'
            . estab_auth_html($adminUser) . '</strong></span>';
    $incident = $incidentState === null
        ? ''
        : estab_incident_ui_markup($incidentState, $compact, $sidebar);

    return '<aside class="' . $barClass . '" data-estab-session-bar'
        . ($popup ? ' data-estab-popup-ui' : '')
        . ' aria-label="Aktuelle Anmeldung">'
        . '<div class="estab-session-topline">'
        /*
         * Die Marke fuehrt zur Uebersicht. In der Huelle steht dieser Weg
         * links im Menue; ein zweiter daneben waere derselbe Weg zweimal.
         */
        . ($includeBrand
            ? '<a class="estab-session-brand" href="'
                . estab_auth_html($homeUrl) . '" target="_top"'
                . ' aria-label="eStab-Übersicht">eStab</a>'
            : '')
        . '<div class="estab-session-identity">'
        . '<span class="estab-session-prefix">Angemeldet als</span>'
        . '<strong data-estab-user-name="' . $name . '">' . $name . '</strong>'
        . '<span class="estab-session-detail"'
        . ' data-estab-user-code="' . $code . '"'
        . ' data-estab-user-function="' . $function . '"'
        . ' data-estab-user-role="' . $role . '">'
        . 'Kürzel ' . $code
        . ' · Funktion ' . $functionAndRole
        . '</span>'
        . $adminContext
        . '</div>'
        . '<div class="estab-session-actions">'
        . '<form class="estab-session-logout" data-estab-logout-form'
        . ' method="post" action="' . estab_auth_html($logoutUrl)
        . '" target="_top">'
        . '<input type="hidden" name="csrf_token" value="'
        . estab_auth_html($csrfToken) . '">'
        . '<input type="hidden" name="logout_action" value="logout">'
        . '<button class="estab-button estab-button-logout"'
        . ' type="submit">Abmelden</button>'
        . '</form>'
        . '</div>'
        . '</div>'
        . $incident
        . $navigation
        . '</aside>'
        . ($compact ? '' : estab_session_ui_mainframe_guard())
        . estab_session_ui_dirty_guard_script($popup)
        . estab_session_ui_activity_script($csrfToken, $identity['kuerzel']);
}

/**
 * Report genuine browser interaction without turning polling into activity.
 *
 * One shared localStorage timestamp throttles nested same-origin frames and
 * tabs of the same account to at most one database write per minute. There is
 * deliberately no interval and no page-load heartbeat.
 */
function estab_session_ui_activity_script(
    string $csrfToken,
    string $userCode
): string {
    if (
        preg_match('/\A[a-f0-9]{64}\z/D', $csrfToken) !== 1
        || preg_match('/\A[a-z0-9_]{1,6}\z/D', $userCode) !== 1
    ) {
        throw new InvalidArgumentException('Invalid activity monitor context');
    }
    $activityUrl = json_encode(
        estab_application_url('4fach/activity.php'),
        JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );
    $loginUrl = json_encode(
        estab_navigation_login_url(null, true),
        JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
    );
    $token = json_encode(
        $csrfToken,
        JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR
    );
    $storageKey = json_encode(
        'estab.activity.' . $userCode,
        JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR
    );

    return '<script' . estab_csp_script_attribute() . ' data-estab-activity-monitor>'
        . '(function(){'
        . 'var endpoint=' . $activityUrl . ';'
        . 'var login=' . $loginUrl . ';'
        . 'var token=' . $token . ';'
        . 'var storageKey=' . $storageKey . ';'
        . 'var throttle=60000;var pending=false;var memoryLast=0;'
        . 'function lastSignal(){try{var value=Number('
        . 'localStorage.getItem(storageKey)||0);if(Number.isFinite(value)'
        . '&&value>memoryLast){memoryLast=value;}}catch(ignore){}'
        . 'return memoryLast;}'
        . 'function remember(value){memoryLast=value;try{'
        . 'localStorage.setItem(storageKey,String(value));}catch(ignore){}}'
        . 'function forget(value){if(memoryLast===value){memoryLast=0;}'
        . 'try{if(Number(localStorage.getItem(storageKey)||0)===value){'
        . 'localStorage.removeItem(storageKey);}}catch(ignore){}}'
        . 'function expired(){try{window.top.location.assign(login);}'
        . 'catch(ignore){window.location.assign(login);}}'
        . 'function report(){if(pending){return;}var now=Date.now();'
        . 'if(now-lastSignal()<throttle){return;}remember(now);pending=true;'
        . 'fetch(endpoint,{method:"POST",credentials:"same-origin",'
        . 'cache:"no-store",keepalive:true,headers:{'
        . '"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8",'
        . '"X-Requested-With":"eStab-Activity"},body:'
        . '"csrf_token="+encodeURIComponent(token)})'
        . '.then(function(response){pending=false;if(response.status===401){'
        . 'expired();return;}if(!response.ok){forget(now);}})'
        . '.catch(function(){pending=false;forget(now);});}'
        . '["pointerdown","pointermove","keydown","input","change","wheel","touchstart"]'
        . '.forEach(function(name){document.addEventListener(name,report,'
        . '{capture:true,passive:true});});'
        . 'window.addEventListener("focus",report,{passive:true});'
        . 'document.addEventListener("visibilitychange",function(){'
        . 'if(document.visibilityState==="visible"){report();}},{passive:true});'
        . '})();'
        . '</script>';
}

/**
 * Render the same area navigation without claiming that a user is signed in.
 *
 * Public pages such as BOS-Info therefore retain a reliable route back to the
 * overview. Protected items lead to the explicit login entry.
 */
function estab_session_ui_public_markup(
    bool $compact = false,
    array $server = [],
    ?string $loginDestination = null,
    bool $popup = false,
    bool $sidebar = false,
    bool $includeNavigation = true,
    ?array $incidentState = null
): string {
    if ($sidebar && !$compact) {
        throw new InvalidArgumentException(
            'Public sidebar UI requires compact presentation'
        );
    }
    $barClass = $compact
        ? 'estab-session-bar estab-session-bar-public estab-session-bar-compact'
        : 'estab-session-bar estab-session-bar-public';
    if ($sidebar) {
        $barClass .= ' estab-session-bar-sidebar';
    }
    $homeUrl = estab_application_root();
    if ($loginDestination !== null) {
        $loginDestination = estab_navigation_login_destination_key(
            $loginDestination
        );
        if ($loginDestination === null) {
            throw new InvalidArgumentException('Invalid public login destination');
        }
    }
    $loginUrl = estab_navigation_login_url($loginDestination);
    $effectiveServer = $server === [] ? $_SERVER : $server;
    $adminUser = estab_session_ui_admin_user($effectiveServer);
    $navigation = $includeNavigation
        ? estab_navigation_markup(
            false,
            $effectiveServer,
            $compact,
            $sidebar
        )
        : '';
    $status = $adminUser === null
        ? '<span class="estab-session-anonymous">Nicht angemeldet</span>'
        : '<span class="estab-session-anonymous">'
            . 'Administrationszugang <strong data-estab-admin-user="'
            . estab_auth_html($adminUser) . '">'
            . estab_auth_html($adminUser) . '</strong>'
            . '<span class="estab-session-admin-note">'
            . ' · Kein eStab-Funktionskonto angemeldet</span></span>';
    $incident = $incidentState === null
        ? ''
        : estab_incident_ui_markup($incidentState, $compact, $sidebar);

    return '<aside class="' . $barClass . '" data-estab-public-bar'
        . ($popup ? ' data-estab-popup-ui' : '')
        . ' aria-label="eStab-Navigation">'
        . '<div class="estab-session-topline">'
        . '<a class="estab-session-brand" href="'
        . estab_auth_html($homeUrl) . '" target="_top"'
        . ' aria-label="eStab-Übersicht">eStab</a>'
        . $status
        . '<div class="estab-session-actions">'
        . '<a class="estab-button estab-button-login" href="'
        . estab_auth_html($loginUrl) . '" target="_top">Anmelden</a>'
        . '</div></div>'
        . $incident
        . $navigation
        . '</aside>'
        . ($compact ? '' : estab_session_ui_mainframe_guard())
        . estab_session_ui_dirty_guard_script($popup);
}

/**
 * Hide the main controller's standalone bar when it is rendered inside the
 * named application frame. The compact navigation-frame bar remains visible.
 */
function estab_session_ui_mainframe_guard(): string
{
    return '<script' . estab_csp_script_attribute() . ' data-estab-mainframe-guard>'
        . '(function(script){'
        . 'var bar=script.previousElementSibling;'
        . 'if(window.parent!==window&&window.name==="mainframe"'
        . '&&bar&&bar.tagName==="ASIDE"){'
        . 'bar.remove();'
        . '}'
        . '})(document.currentScript);'
        . '</script>';
}

/**
 * Confirm global navigation when an explicitly marked edit form is dirty.
 *
 * The compact frame recursively inspects the same-origin mainframe and a
 * popup's opener. Comparing current controls with their HTML defaults avoids
 * polling; an explicit server marker covers redisplayed unsaved values.
 * Historical local submit/cancel actions remain untouched.
 */
function estab_session_ui_dirty_guard_script(bool $popup = false): string
{
    return '<script' . estab_csp_script_attribute() . ' data-estab-dirty-guard>'
        . '(function(){'
        . 'var popupContext=' . ($popup ? 'true' : 'false') . ';'
        . 'function applicationWindow(){'
        . 'if(!popupContext){return null;}'
        . 'var candidate=window.opener;'
        . 'if(!candidate||candidate.closed){return null;}'
        . 'try{candidate=candidate.top;'
        . 'if(candidate.location.origin!==window.location.origin){return null;}'
        . 'void candidate.document;return candidate;}catch(ignore){return null;}}'
        . 'function docs(win,list){'
        . 'try{list.push(win.document);'
        . 'for(var i=0;i<win.frames.length;i++){docs(win.frames[i],list);}}'
        . 'catch(ignore){}return list;}'
        . 'function changed(form){'
        . 'if(form.hasAttribute("data-estab-dirty-initial")){return true;}'
        . 'var controls=form.elements;'
        . 'for(var i=0;i<controls.length;i++){'
        . 'var field=controls[i];'
        . 'if(!field||field.disabled){continue;}'
        . 'var tag=String(field.tagName||"").toLowerCase();'
        . 'var type=String(field.type||"").toLowerCase();'
        . 'if(type==="hidden"||type==="submit"||type==="button"'
        . '||type==="image"||type==="reset"){continue;}'
        . 'if(type==="checkbox"||type==="radio"){'
        . 'if(field.checked!==field.defaultChecked){return true;}'
        . '}else if(tag==="select"){'
        . 'for(var j=0;j<field.options.length;j++){'
        . 'if(field.options[j].selected!==field.options[j].defaultSelected){'
        . 'return true;}}'
        . '}else if(type==="file"){if(field.files&&field.files.length){return true;}}'
        . 'else if(field.value!==field.defaultValue){return true;}'
        . '}return false;}'
        . 'function dirty(){'
        . 'var all=docs(window.top,[]);'
        . 'var app=applicationWindow();'
        . 'if(app&&app!==window.top){docs(app,all);}'
        . 'for(var i=0;i<all.length;i++){'
        . 'var forms=all[i].querySelectorAll("form[data-estab-dirty-guard]");'
        . 'for(var j=0;j<forms.length;j++){if(changed(forms[j])){return true;}}'
        . '}return false;}'
        . 'function approve(){'
        . 'return !dirty()||window.confirm('
        . '"Ungespeicherte Eingaben gehen beim Bereichswechsel verloren. "'
        . '+"Möchten Sie die Seite wirklich verlassen?");}'
        . 'document.addEventListener("click",function(event){'
        . 'var origin=event.target;'
        . 'var link=origin&&typeof origin.closest==="function"'
        . '?origin.closest("[data-estab-navigation] a,.estab-session-brand,"'
        . '+".estab-button-login"):null;'
        . 'if(!link){return;}'
        . 'if(!approve()){event.preventDefault();return;}'
        . 'var app=applicationWindow();'
        . 'if(app){event.preventDefault();app.location.assign(link.href);'
        . 'window.close();}},true);'
        . 'document.addEventListener("submit",function(event){'
        . 'var form=event.target;'
        . 'var submitter=event.submitter;'
        . 'var confirmKey=submitter&&submitter.getAttribute'
        . '?submitter.getAttribute("data-estab-confirm"):"";'
        . 'var confirmMessage=confirmKey==="replace-editor-with-standard"'
        . '?"Die aktuellen Editorwerte werden verworfen und durch die "'
        . '+"gespeicherte Standardmatrix ersetzt. Fortfahren?"'
        . ':confirmKey==="replace-standard"'
        . '?"Die aktive Matrix wird gespeichert und die bisherige "'
        . '+"Standardmatrix unwiderruflich ersetzt. Vorherige Standards "'
        . '+"bleiben nur in einem Datenbankbackup erhalten. Fortfahren?":"";'
        . 'if(confirmKey==="delete-telecom-entry"){confirmMessage='
        . '"Diesen Fernmeldeweg wirklich aus dem Entwurf entfernen? "'
        . '+"Die aktive Planversion bleibt unverändert.";}'
        . 'if(confirmKey==="discard-telecom-draft"){confirmMessage='
        . '"Diesen gesamten Entwurf wirklich verwerfen? Die bereits "'
        . '+"gespeicherten Entwurfsdaten bleiben als verworfene Version in der "'
        . '+"Historie lesbar. Ungespeicherte Eingaben gehen verloren; der "'
        . '+"aktive Fernmeldeplan bleibt unverändert.";}'
        . 'if(confirmMessage&&!window.confirm(confirmMessage)){'
        . 'event.preventDefault();return;}'
        // The area buttons of the sidebar are the usual way out of a half
        // filled form. Guarding only the logout meant every one of them
        // discarded the entries without asking.
        . 'var isLogout=form.matches(".estab-session-logout");'
        . 'var isAreaSwitch=form.matches(".estab-sidebar-action-form");'
        . 'if(!isLogout&&!isAreaSwitch){return;}'
        . 'if(!approve()){event.preventDefault();return;}'
        . 'if(!isLogout){return;}'
        . 'var app=applicationWindow();'
        . 'if(app){var targetName="estab-application-"'
        . '+Date.now()+"-"+Math.random().toString(36).slice(2);'
        . 'app.name=targetName;form.target=targetName;'
        . 'window.setTimeout(function(){window.close();},100);}},true);'
        . '})();'
        . '</script>';
}

/**
 * Return the safe JavaScript call used to refresh the application sidebar and
 * content frame after login, logout-compatible legacy actions, and saves.
 */
function estab_session_ui_frame_refresh_script(): string
{
    $arguments = [
        // Der Rahmen "vorgaben" traegt das Cockpit. Ohne das Fragment kaeme
        // die alte kombinierte Seitenleiste zurueck, die es nicht mehr gibt.
        estab_application_url('4fach/vorgaben.php') . '?fragment=cockpit',
        'vorgaben',
        estab_application_url('4fach/mainindex.php'),
        'mainframe',
    ];
    $encoded = array_map(
        static fn (string $value): string => json_encode(
            $value,
            JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        ),
        $arguments
    );

    return 'FramesVeraendern(' . implode(',', $encoded) . ');';
}

/**
 * Refresh the sidebar alone after a completed message action.
 *
 * The answer document of that action is the confirmation the operator has to
 * read. Reloading the message frame as well would erase it before it can be
 * read, while the sidebar must reload at once so the queue counters and the
 * correction counter match the state the action just created.
 */
function estab_session_ui_sidebar_refresh_script(): string
{
    $arguments = [
        estab_application_url('4fach/vorgaben.php') . '?fragment=cockpit',
        'vorgaben',
    ];
    $encoded = array_map(
        static fn (string $value): string => json_encode(
            $value,
            JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        ),
        $arguments
    );

    return 'FramesVeraendern(' . implode(',', $encoded) . ');';
}

/**
 * Wrap a frame refresh in a script element the policy admits.
 *
 * The refresh used to sit in an onload attribute on the body. The policy
 * sends a nonce and no `'unsafe-inline'`, and a nonce can never apply to an
 * event-handler attribute -- the browser refused the attribute silently and
 * no frame was ever refreshed again. A script element carries the nonce, and
 * the load event keeps the original timing.
 */
function estab_session_ui_frame_refresh_markup(string $script): string
{
    return '<script' . estab_csp_script_attribute()
        . ' data-estab-frame-refresh>'
        . 'window.addEventListener("load",function(){' . $script . '});'
        . '</script>';
}

/**
 * Name the station one completed message action moved the message to.
 *
 * The historical message controller answers its own form POST inside the
 * message frame and has no redirect that could carry a query flash the way
 * the command-post pages do. The statement is therefore derived from the same
 * server-side task, action selector and stored direction that the save itself
 * used; no browser text reaches it. An unknown combination yields no
 * statement at all rather than an invented one.
 *
 * @return array{
 *     tone: string,
 *     title: string,
 *     destination: string,
 *     detail: string,
 *     acting_function: ?string,
 *     actions: list<array{name: string, label: string, primary: bool}>
 * }|null
 */
function estab_session_ui_message_outcome(
    string $task,
    array $request,
    string $direction = '',
    string $actingFunction = '',
    string $kind = ''
): ?array {
    $direction = in_array($direction, ['E', 'A'], true) ? $direction : '';
    // Die Nachrichtenart entscheidet über die nächste Station und wird vom
    // Aufrufer aus dem Datensatz gelesen, nie aus der Anfrage.
    $isConversationNote = $kind === 't';
    $actingFunction = preg_match(
        '/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D',
        $actingFunction
    ) === 1
        ? $actingFunction
        : null;
    // The historical image buttons submit x/y coordinates. Both spellings
    // select the same action, exactly as the save path reads them.
    $pressed = static fn (string $name): bool =>
        array_key_exists($name . '_x', $request)
        || array_key_exists($name . '_y', $request);
    $staffNext = [
        [
            'name' => 'stab_schreiben_x',
            'label' => 'Nächste Meldung schreiben',
            'primary' => true,
        ],
        [
            'name' => 'stab_lesen_x',
            'label' => 'Meldungen dieser Funktion',
            'primary' => false,
        ],
    ];
    $viewerNext = [
        [
            'name' => 'stab_sichten_x',
            'label' => 'Weiter sichten',
            'primary' => true,
        ],
    ];
    $leadNext = [
        [
            'name' => 'ldf_nachrichten_x',
            'label' => 'Weiter disponieren',
            'primary' => true,
        ],
    ];
    $operatorNext = [
        [
            'name' => 'fm_eingang_x',
            'label' => 'Nächsten Eingang aufnehmen',
            'primary' => true,
        ],
        [
            'name' => 'fm_ausgang_x',
            'label' => 'Ausgang bearbeiten',
            'primary' => false,
        ],
    ];

    switch ($task) {
        case 'Stab_schreiben':
            return [
                'tone' => 'forwarded',
                'title' => 'Nachrichtenvordruck abgesetzt',
                'destination' => 'An Sichter übergeben',
                'detail' => 'Die Ausgangsnachricht wartet in der '
                    . 'Sichtungswarteschlange. Nach der formalen Sichtung '
                    . 'legt der LdF Übermittlungsmittel und Beförderungsweg '
                    . 'fest.',
                'acting_function' => $actingFunction,
                'actions' => $staffNext,
            ];
        case 'Stab_korrigieren':
            return [
                'tone' => 'forwarded',
                'title' => 'Korrektur abgesetzt',
                'destination' => 'Erneut an Sichter übergeben',
                'detail' => 'Die überarbeitete Ausgangsnachricht hat Ihre '
                    . 'Korrekturschleife verlassen und wartet wieder auf die '
                    . 'formale Sichtung.',
                'acting_function' => $actingFunction,
                'actions' => $staffNext,
            ];
        case 'Stab_gesprnoti':
            return [
                'tone' => 'forwarded',
                'title' => 'Gesprächsnotiz abgesetzt',
                'destination' => 'An Sichter übergeben',
                'detail' => 'Die Gesprächsnotiz ist als eigener Vordruck '
                    . 'erfasst und wartet auf die formale Sichtung.',
                'acting_function' => $actingFunction,
                'actions' => $staffNext,
            ];
        case 'FM-Eingang':
        case 'FM-Eingang_Anhang':
            return [
                'tone' => 'forwarded',
                'title' => 'Eingang aufgenommen',
                'destination' => 'An LdF zur Annahme übergeben',
                'detail' => 'Der LdF bestätigt das Aufnahmemittel und trägt '
                    . 'den übersetzten Absender nach. Danach geht die '
                    . 'Nachricht in die Sichtung.',
                'acting_function' => null,
                'actions' => $operatorNext,
            ];
        case 'LdF-Eingang':
            return [
                'tone' => 'forwarded',
                'title' => 'Eingang angenommen',
                'destination' => 'An Sichter übergeben',
                'detail' => 'Der Sichter verteilt die Eingangsnachricht an '
                    . 'die im Laufweg angekreuzten Stabsfunktionen.',
                'acting_function' => null,
                'actions' => $leadNext,
            ];
        case 'LdF-Ausgang':
            if ($pressed('ldf_zurueckweisen')) {
                return [
                    'tone' => 'returned',
                    'title' => 'Ausgang an den Verfasser zurückgegeben',
                    'destination' => 'In die Korrekturschleife der '
                        . 'verfassenden Stabsfunktion',
                    'detail' => 'Ihre Begründung steht im Vordruck. Die '
                        . 'verfassende Funktion sieht die Nachricht jetzt in '
                        . 'ihrer Korrekturschleife.',
                    'acting_function' => null,
                    'actions' => $leadNext,
                ];
            }
            return [
                'tone' => 'forwarded',
                'title' => 'Beförderungsweg festgelegt',
                'destination' => 'Zur Beförderung an A/W',
                'detail' => 'Feld 1 trägt Ihre Disposition. A/W befördert '
                    . 'die Nachricht und weist Zeit und Zeichen der '
                    . 'Beförderung nach.',
                'acting_function' => null,
                'actions' => $leadNext,
            ];
        case 'FM-Ausgang':
            if ($pressed('transport_nicht_moeglich')) {
                return [
                    'tone' => 'returned',
                    'title' => 'Beförderung nicht möglich',
                    'destination' => 'An LdF zurückgegeben',
                    'detail' => 'Der LdF wählt ein anderes Übermittlungs'
                        . 'mittel oder einen anderen Beförderungsweg.',
                    'acting_function' => null,
                    'actions' => $operatorNext,
                ];
            }
            return [
                'tone' => 'completed',
                'title' => 'Nachricht befördert',
                'destination' => 'Vorgang abgeschlossen',
                'detail' => 'Zeit und Zeichen der Beförderung sind im '
                    . 'Vordruck nachgewiesen. Der Laufweg dieser Nachricht '
                    . 'ist beendet.',
                'acting_function' => null,
                'actions' => $operatorNext,
            ];
        case 'Stab_sichten':
            if ($pressed('zurueckweisen')) {
                return [
                    'tone' => 'returned',
                    'title' => 'Ausgang formal zurückgewiesen',
                    'destination' => 'In die Korrekturschleife der '
                        . 'verfassenden Stabsfunktion',
                    'detail' => 'Ihre Begründung steht in den Vermerken. Die '
                        . 'verfassende Funktion sieht die Nachricht jetzt in '
                        . 'ihrer Korrekturschleife.',
                    'acting_function' => null,
                    'actions' => $viewerNext,
                ];
            }
            if ($direction === 'E') {
                return [
                    'tone' => 'completed',
                    'title' => 'Eingang gesichtet',
                    'destination' => 'An die Stabsfunktionen verteilt',
                    'detail' => 'Die Durchschriften stehen den im Laufweg '
                        . 'angekreuzten Funktionen zur Verfügung. Der '
                        . 'Vordruck ist abgeschlossen.',
                    'acting_function' => null,
                    'actions' => $viewerNext,
                ];
            }
            if ($isConversationNote) {
                // Eine Gesprächsnotiz hält ein bereits geführtes Gespräch
                // fest. Mit der Sichtung ist ihr Laufweg beendet; weder der
                // LdF noch die Fernmelder kommen noch an die Reihe.
                return [
                    'tone' => 'completed',
                    'title' => 'Gesprächsnotiz gesichtet',
                    'destination' => 'Abgeschlossen und nachgewiesen',
                    'detail' => 'Die Notiz hält ein bereits geführtes '
                        . 'Gespräch fest. Ihr Laufweg endet mit der '
                        . 'Sichtung; eine Disposition und eine Beförderung '
                        . 'finden nicht statt.',
                    'acting_function' => null,
                    'actions' => $viewerNext,
                ];
            }
            return [
                'tone' => 'forwarded',
                'title' => 'Ausgang gesichtet',
                'destination' => 'An LdF zur Disposition übergeben',
                'detail' => 'Der LdF legt Übermittlungsmittel und '
                    . 'Beförderungsweg fest, danach befördert A/W die '
                    . 'Nachricht.',
                'acting_function' => null,
                'actions' => $viewerNext,
            ];
    }

    return null;
}

/**
 * Render the statement the message frame keeps after a completed action.
 *
 * Every sentence comes from the fixed table above. The continuation controls
 * carry nothing but a fixed action name and the server-resolved acting
 * function, so the confirmation stays a statement of fact while an
 * experienced operator keeps the next step one click away.
 */
function estab_session_ui_message_confirmation_markup(
    ?array $outcome,
    string $actionUrl
): string {
    if ($outcome === null) {
        return '';
    }
    $tone = $outcome['tone'] ?? null;
    if (!in_array($tone, ['forwarded', 'returned', 'completed'], true)) {
        throw new InvalidArgumentException('Invalid message outcome tone');
    }
    $texts = [];
    foreach (['title', 'destination', 'detail'] as $field) {
        $value = $outcome[$field] ?? null;
        if (
            !is_string($value)
            || trim($value) === ''
            || strlen($value) > 400
            || preg_match('//u', $value) !== 1
        ) {
            throw new InvalidArgumentException('Invalid message outcome text');
        }
        $texts[$field] = trim($value);
    }
    if (
        $actionUrl === ''
        || strlen($actionUrl) > 2048
        || preg_match('//u', $actionUrl) !== 1
        || preg_match('/[\x00-\x20]/', $actionUrl) === 1
    ) {
        throw new InvalidArgumentException('Invalid message outcome action');
    }
    $actingFunction = $outcome['acting_function'] ?? null;
    if (
        $actingFunction !== null
        && (
            !is_string($actingFunction)
            || preg_match(
                '/\A(?:A\/W|[A-Za-z0-9_]{1,10})\z/D',
                $actingFunction
            ) !== 1
        )
    ) {
        throw new InvalidArgumentException('Invalid message outcome function');
    }

    $controls = '';
    $steps = $outcome['actions'] ?? [];
    if (!is_array($steps)) {
        throw new InvalidArgumentException('Invalid message outcome steps');
    }
    foreach ($steps as $action) {
        if (
            !is_array($action)
            || !is_string($action['name'] ?? null)
            || preg_match('/\A[a-z0-9_]{1,32}_x\z/D', $action['name']) !== 1
            || !is_string($action['label'] ?? null)
            || trim($action['label']) === ''
            || strlen($action['label']) > 80
            || preg_match('//u', $action['label']) !== 1
        ) {
            throw new InvalidArgumentException('Invalid message outcome step');
        }
        // Der geuebte Bedienende soll den naechsten Griff mit der Tastatur
        // ausloesen koennen, ohne erst zur Maus zu greifen.
        $controls .= '<button class="estab-button'
            . (($action['primary'] ?? false) === true
                ? ' estab-button-primary" autofocus'
                : '"')
            . ' type="submit" name="' . estab_auth_html($action['name'])
            . '" value="1">'
            . estab_auth_html(trim($action['label']))
            . '</button>';
    }
    if ($controls !== '' && session_status() === PHP_SESSION_ACTIVE) {
        $controls = '<form class="estab-message-confirmation-actions"'
            . ' method="post" action="' . estab_auth_html($actionUrl) . '">'
            . estab_csrf_field()
            . ($actingFunction === null
                ? ''
                : '<input type="hidden" name="acting_function" value="'
                    . estab_auth_html($actingFunction) . '">')
            . $controls
            . '</form>';
    } else {
        // Without an active session there is no CSRF token, and a control
        // without one would be refused. The statement itself still stands.
        $controls = '';
    }

    return '<main class="estab-tool-main estab-tool-main-narrow'
        . ' estab-message-confirmation" data-estab-message-confirmation="'
        . $tone . '">'
        . '<section class="estab-tool-panel">'
        . '<p class="estab-tool-eyebrow">Nachrichtenvordruck</p>'
        . '<p class="estab-tool-feedback '
        . ($tone === 'returned'
            ? 'estab-tool-feedback-warning'
            : 'estab-tool-feedback-success')
        . '" role="status" aria-live="polite">'
        . '<strong>' . estab_auth_html($texts['title']) . '</strong>'
        . '<span class="estab-message-confirmation-target">'
        . estab_auth_html($texts['destination']) . '</span>'
        . '</p>'
        . '<p class="estab-message-confirmation-detail">'
        . estab_auth_html($texts['detail']) . '</p>'
        . $controls
        . '</section>'
        . '</main>';
}

/** Return the session bar for a valid current login, or an empty string. */
function estab_session_ui_current_markup(
    array $session,
    bool $compact = false,
    ?string $loginDestination = null,
    bool $popup = false,
    bool $sidebar = false,
    bool $includeNavigation = true,
    bool $includeIncident = true,
    ?array $incidentState = null,
    bool $includeBrand = true
): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (estab_auth_session_identity($session) === null) {
        if ($loginDestination === null) {
            $loginDestination = estab_session_ui_login_destination(
                $_GET,
                $_POST
            );
        }
        if (
            $includeIncident
            && $incidentState === null
            && PHP_SAPI !== 'cli'
        ) {
            $incidentState = estab_incident_ui_current_state();
        }
        return estab_session_ui_public_markup(
            $compact,
            $_SERVER,
            $loginDestination,
            $popup,
            $sidebar,
            $includeNavigation,
            $includeIncident ? $incidentState : null
        );
    }

    if (
        $includeIncident
        && $incidentState === null
        && PHP_SAPI !== 'cli'
    ) {
        $incidentState = estab_incident_ui_current_state();
    }
    return estab_session_ui_markup(
        $session,
        estab_csrf_token(),
        $compact,
        $_SERVER,
        $popup,
        $sidebar,
        $includeNavigation,
        $includeIncident ? $incidentState : null,
        $includeBrand
    );
}

/** Detect the actual shared element without colliding with escaped user text. */
function estab_session_ui_document_has_bar(string $html): bool
{
    /*
     * Eine Seite, die in der Huelle steht, bringt ihre Leiste selbst mit --
     * links das Menue, rechts das Cockpit. Ohne diese Ausnahme haenge der
     * Puffer eine zweite Navigation an, und der Bedienende haette zwei
     * Menues nebeneinander, die dasselbe tun.
     */
    if (preg_match('/<[^>]*\bdata-estab-shell\b[^>]*>/i', $html) === 1) {
        return true;
    }
    return preg_match(
        '/<aside\b[^>]*\bdata-estab-(?:session|public)-bar\b[^>]*>/i',
        $html
    ) === 1;
}

/** Detect an actual shared stylesheet link, not coincidental visible text. */
function estab_session_ui_document_has_stylesheet(string $html): bool
{
    return preg_match(
        '~<link\b'
            . '(?=[^>]*\brel=["\']stylesheet["\'])'
            . '(?=[^>]*\bhref=["\'][^"\']*estab-ui\.css'
            . '(?:[?#][^"\']*)?["\'])'
            . '[^>]*>~i',
        $html
    ) === 1;
}

/**
 * Decide whether the selected controller still produces an HTML response.
 *
 * Legacy HTML often has no explicit Content-Type, so an absent header remains
 * eligible. Explicit non-HTML responses and redirects must stay untouched.
 */
function estab_session_ui_response_is_html(array $headers, int $status): bool
{
    if (
        $status < 200
        || ($status >= 300 && $status < 400)
        || in_array($status, [204, 205], true)
    ) {
        return false;
    }

    foreach ($headers as $header) {
        if (!is_string($header) || stripos($header, 'Content-Type:') !== 0) {
            continue;
        }
        $contentType = trim(substr($header, strlen('Content-Type:')));
        return preg_match(
            '~^(?:text/html|application/xhtml\+xml)(?:\s*;|$)~i',
            $contentType
        ) === 1;
    }

    return true;
}

/** The frameset supplies one persistent bar, so its helper frames stay lean. */
function estab_session_ui_is_embedded_frame(array $query): bool
{
    return ($query['embedded'] ?? null) === '1';
}

/**
 * Render one persistent, navigable error document for a browser-facing page.
 *
 * The real HTTP status remains authoritative. Only fixed symbolic navigation
 * keys become recovery links, so an error can never reflect a request URL or
 * turn Referer into an open redirect. Resource, JSON and download endpoints
 * deliberately keep their own machine-readable error contracts.
 */
function estab_session_ui_error_document(
    int $status,
    string $title,
    string $message,
    string $contextKey,
    string $recoveryKey = 'overview'
): string {
    if ($status < 400 || $status > 599) {
        throw new InvalidArgumentException('Invalid UI error status');
    }
    if (
        preg_match('/\A[a-z][a-z0-9-]*\z/D', $contextKey) !== 1
        || trim($title) === ''
        || trim($message) === ''
    ) {
        throw new InvalidArgumentException('Invalid UI error content');
    }
    $recoveryItem = estab_navigation_item_for_key($recoveryKey);
    if ($recoveryItem === null || $recoveryItem['access'] !== 'public') {
        throw new InvalidArgumentException('Invalid UI error recovery target');
    }

    $safeTitle = estab_auth_html(trim($title));
    $safeMessage = estab_auth_html(trim($message));
    $safeContext = estab_auth_html($contextKey);
    $recoveryUrl = estab_navigation_item_url($recoveryItem);
    $recoveryLabel = $recoveryItem['key'] === 'overview'
        ? 'Zur eStab-Übersicht'
        : 'Zu ' . $recoveryItem['label'];

    return '<!doctype html><html lang="de"><head>'
        . '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $safeTitle . ' · eStab</title>'
        . estab_session_ui_stylesheet()
        . '</head><body class="estab-tool-page">'
        . '<main class="estab-tool-main estab-tool-main-narrow estab-error-page"'
        . ' data-estab-error-page data-estab-error-status="' . $status . '"'
        . ' data-estab-error-context="' . $safeContext . '">'
        . '<header class="estab-tool-hero estab-error-hero">'
        . '<p class="estab-tool-eyebrow">eStab · Hinweis</p>'
        . '<h1>' . $safeTitle . '</h1>'
        . '<p>Sie bleiben in der Anwendung und können direkt in einen anderen '
        . 'verfügbaren Bereich wechseln.</p>'
        . '</header>'
        . '<section class="estab-tool-panel estab-error-panel"'
        . ' aria-labelledby="estab-error-title">'
        . '<div class="estab-tool-feedback estab-tool-feedback-error estab-error-message"'
        . ' role="alert" aria-live="assertive">'
        . '<strong id="estab-error-title">Die Aktion wurde nicht ausgeführt.</strong>'
        . '<p>' . $safeMessage . '</p>'
        . '</div>'
        . '<p class="estab-error-guidance">Nutzen Sie die Bereichsnavigation '
        . 'oder kehren Sie zur Übersicht zurück.</p>'
        . '<div class="estab-tool-actions estab-error-actions"'
        . ' data-estab-error-recovery>'
        . '<a class="estab-button estab-button-primary" href="'
        . estab_auth_html($recoveryUrl) . '" target="_top">'
        . estab_auth_html($recoveryLabel) . '</a>'
        . '</div></section>'
        . '<footer class="estab-tool-footer">'
        . '<span>Über die Navigation können Sie direkt weiterarbeiten.</span>'
        . '<span>Die für diesen Zugriff verfügbaren Funktionen bleiben erreichbar.</span>'
        . '</footer></main></body></html>';
}

/** Emit a styled browser error without weakening or redirecting its status. */
function estab_session_ui_abort(
    array $session,
    int $status,
    string $title,
    string $message,
    string $contextKey,
    bool $popup = false
): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        exit;
    }
    estab_session_ui_start($session, false, $popup);
    echo estab_session_ui_error_document(
        $status,
        $title,
        $message,
        $contextKey
    );
    exit;
}

/**
 * Insert one bar into a complete document, or wrap a legacy HTML fragment.
 *
 * The last opening body tag is intentional: two historical form renderers
 * emit a harmless duplicate body tag immediately before their real content.
 */
function estab_session_ui_inject_document(string $html, string $markup): string
{
    if ($markup === '' || estab_session_ui_document_has_bar($html)) {
        return $html;
    }

    $bodyCount = preg_match_all(
        '/<body\b[^>]*>/i',
        $html,
        $bodyMatches,
        PREG_OFFSET_CAPTURE
    );
    if (is_int($bodyCount) && $bodyCount > 0) {
        $lastBody = $bodyMatches[0][array_key_last($bodyMatches[0])];
        $bodyEnd = $lastBody[1] + strlen($lastBody[0]);
        $html = substr($html, 0, $bodyEnd) . $markup . substr($html, $bodyEnd);

        if (!estab_session_ui_document_has_stylesheet($html)) {
            $headEnd = strripos($html, '</head>');
            if ($headEnd !== false) {
                $html = substr($html, 0, $headEnd)
                    . estab_session_ui_stylesheet()
                    . substr($html, $headEnd);
            }
        }
        return $html;
    }

    return '<!doctype html><html lang="de"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . estab_session_ui_stylesheet()
        . '</head><body>' . $markup . $html . '</body></html>';
}

/**
 * Enable the shared UI for one explicitly selected HTML controller.
 *
 * Registration is deliberately not global: downloads, image endpoints,
 * health checks and plain-text errors remain byte-for-byte untouched.
 */
function estab_session_ui_start(
    array $session,
    bool $compact = false,
    bool $popup = false,
    bool $shell = true
): void
{
    static $started = false;
    if ($started || PHP_SAPI === 'cli') {
        return;
    }
    $started = true;
    if (
        estab_auth_session_identity($session) !== null
        && !headers_sent()
    ) {
        header('Cache-Control: private, no-store, max-age=0');
    }

    ob_start(static function (string $html) use ($compact, $popup, $shell): string {
        $status = http_response_code();
        $status = is_int($status) ? $status : 200;
        if (!estab_session_ui_response_is_html(headers_list(), $status)) {
            return $html;
        }

        /*
         * Eine Seite fuer sich bekommt die Huelle: links das Menue, rechts
         * das Cockpit, in der Mitte das, was sie selbst ausgibt. Das ist der
         * Unterschied zu frueher, als hier eine Leiste oben angehaengt wurde
         * -- und damit jeder Bereich sein Menue woanders hatte als der
         * Nachrichtenvordruck.
         *
         * Seiten, die in einem Rahmen oder in einem eigenen Fenster stehen,
         * bekommen sie nicht: Sie sind bereits der Inhalt einer Huelle, und
         * eine zweite darin waere ein Menue im Menue.
         */
        if ($shell && !$popup) {
            return estab_session_ui_wrap_in_shell($html);
        }

        /*
         * Rahmeninhalt bekommt gar keine Leiste.
         *
         * Frueher hing hier auch fuer ihn eine Leiste samt Navigation dran,
         * und ein Skript entfernte sie nachtraeglich wieder, wenn es sich im
         * Rahmen "mainframe" wiederfand. Das war ein Umweg mit zwei Fehlern:
         * Ohne Skript blieb sie stehen, und in jedem anderen Rahmen -- etwa
         * der Dokumentenliste der Infosammlung -- blieb sie ohnehin stehen
         * und stand dann als zweites Menue neben dem der Huelle.
         *
         * Ein eigenes Fenster ist etwas anderes: Es hat keine Huelle um sich
         * und braucht seinen eigenen Weg zurueck.
         */
        if (!$shell && !$popup) {
            return $html;
        }

        return estab_session_ui_inject_document(
            $html,
            estab_session_ui_current_markup(
                $_SESSION,
                $compact,
                null,
                $popup
            )
        );
    });
}

/**
 * Ein fertiges Dokument in die dreispaltige Huelle legen.
 *
 * Gearbeitet wird am fertigen Text und nicht an der Seite selbst: So kommen
 * auch die Seiten in die Huelle, die ihren Rumpf noch mit echo zusammensetzen
 * -- und das sind die meisten. Wer die Huelle bereits selbst setzt, wird
 * nicht angefasst.
 */
function estab_session_ui_wrap_in_shell(string $html): string
{
    if (
        $html === ''
        || preg_match('/<[^>]*\bdata-estab-shell\b[^>]*>/i', $html) === 1
    ) {
        return $html;
    }
    if (
        preg_match('/<body\b[^>]*>/i', $html, $bodyOpen, PREG_OFFSET_CAPTURE)
            !== 1
    ) {
        return $html;
    }
    $bodyEnd = strripos($html, '</body>');
    if ($bodyEnd === false) {
        return $html;
    }
    $contentStart = $bodyOpen[0][1] + strlen($bodyOpen[0][0]);
    if ($contentStart > $bodyEnd) {
        return $html;
    }

    $identity = estab_auth_session_identity($_SESSION);
    $head = substr($html, 0, $contentStart);
    $body = substr($html, $contentStart, $bodyEnd - $contentStart);
    $tail = substr($html, $bodyEnd);

    // Der Koerper traegt die Klasse der Huelle, damit er nicht selbst
    // scrollt -- das tun die drei Spalten.
    $head = preg_replace(
        '/(<body\b)([^>]*)\bclass="([^"]*)"/i',
        '$1$2class="estab-shell-body $3"',
        $head,
        1,
        $classReplaced
    ) ?? $head;
    if (($classReplaced ?? 0) === 0) {
        $head = preg_replace(
            '/<body\b/i',
            '<body class="estab-shell-body"',
            $head,
            1
        ) ?? $head;
    }

    if (!estab_session_ui_document_has_stylesheet($head . $tail)) {
        $headEnd = stripos($head, '</head>');
        if ($headEnd !== false) {
            $head = substr($head, 0, $headEnd)
                . '<link rel="stylesheet" href="'
                . estab_auth_html(estab_application_url('estab-ui.css'))
                . '">'
                . substr($head, $headEnd);
        }
    }

    return $head
        . '<div class="estab-shell" data-estab-shell>'
        . estab_shell_menu_markup($identity, $_SERVER)
        . '<main class="estab-shell-content" data-estab-shell-content>'
        . $body
        . '</main>'
        . estab_shell_cockpit_markup()
        . '</div>'
        . $tail;
}
