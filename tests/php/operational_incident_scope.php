<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$source = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $contents;
};

$messages = $source('app/message_repository.php');
$attachments = $source('app/attachment.php');
$logbook = $source('app/logbook.php');
$dataHandler = $source('4fach/data_hndl.php');
$main = $source('4fach/mainindex.php');
$list = $source('4fach/liste.php');
$overview = $source('4fueltg/ue_ltg.php');
$sidebar = $source('app/sidebar.php');
$etb = $source('stabetb/etb.php');
$tbb = $source('fmtbb/tbb.php');
$form = $source('4fach/4fachform.php');
$attachmentController = $source('4fach/anhang.php');
$download = $source('4fach/download.php');
$preview = $source('4fach/showpic.php');
$protocol = $source('4fach/protokoll.php');
$categories = $source('app/category.php');
$readAuthorization = $source('app/read_authorization.php');

$assert(
    str_contains($messages, "require_once __DIR__ . '/incident.php'"),
    'message repository does not load the incident boundary'
);
$assert(
    substr_count($messages, 'estab_incident_with_active_write(') >= 10,
    'message mutations are not consistently serialized with incident activation'
);
$assert(
    str_contains($messages, "array_merge(['einsatz_id'], array_keys(\$fields))"),
    'message inserts do not explicitly bind the active incident'
);
$assert(
    substr_count($messages, 'WHERE `einsatz_id` = ?') >= 3
        && substr_count($messages, 'AND `einsatz_id` = ?') >= 8,
    'message reads or mutations lack incident predicates'
);
$assert(
    substr_count($messages, 'MAX(`04_nummer`)') === 2
        && substr_count($messages, 'WHERE `einsatz_id` = ? FOR UPDATE') >= 1
        && str_contains(
            $messages,
            "' WHERE `einsatz_id` = ?'\n"
                . "                            . ' AND `04_richtung` = ? FOR UPDATE'"
        ),
    'message numbering is not allocated independently per incident'
);
$assert(
    str_contains($messages, 'estab_message_require_attachment_scope(')
        && str_contains(
            $messages,
            'Ein ausgewählter Anhang gehört nicht zum aktiven Einsatz.'
        ),
    'message submission does not revalidate attachment incident ownership'
);

$assert(
    substr_count($attachments, 'estab_incident_with_active_write(') >= 5
        && str_contains(
            $attachments,
            'estab_incident_require_active($connection, true)'
        ),
    'attachment mutations are not serialized with incident activation'
);
$assert(
    str_contains(
        $attachments,
        "(`einsatz_id`, `filename`, `status`, `id`)"
    ),
    'attachment reservations do not explicitly store the incident'
);
$assert(
    substr_count($attachments, 'AND `einsatz_id` = ?') >= 8,
    'attachment lifecycle or reads are not incident scoped'
);
$assert(
    str_contains(
        $attachments,
        "(`einsatz_id`, `p_zeit`, `p_was`, `p_ereignis`)"
    ),
    'attachment audit rows do not carry an incident'
);
$assert(
    (str_contains($download, 'estab_read_attachment(')
        || str_contains($download, 'estab_read_attachment ('))
        && (str_contains($preview, 'estab_read_attachment(')
            || str_contains($preview, 'estab_read_attachment ('))
        && str_contains(
            $readAuthorization,
            'estab_incident_require_active($connection, $forUpdate)'
        ),
    'direct attachment delivery bypasses active-incident authorization'
);
$assert(
    str_contains($download, 'begin_transaction()')
        && str_contains($download, '$readIdentity,')
        && str_contains($download, "            true\n        );")
        && str_contains($preview, 'begin_transaction ()')
        && str_contains($preview, '$readIdentity,')
        && str_contains($preview, "    true\n  );"),
    'file authorization is not serialized with active-incident switching'
);

$assert(
    str_contains($logbook, 'function estab_logbook_active_incident(')
        && str_contains($logbook, 'function estab_logbook_entries('),
    'logbooks do not use the global incident reader'
);
$assert(
    str_contains(
        $logbook,
        "' (`einsatz_id`, `' . \$prefix . 'time`,"
    )
        && str_contains($logbook, 'estab_incident_with_active_write('),
    'logbook entries are not atomically assigned to the active incident'
);
foreach ([$etb, $tbb] as $bookSource) {
    $assert(
        str_contains($bookSource, 'estab_logbook_active_incident (')
            && str_contains($bookSource, 'estab_logbook_entries ('),
        'ETB/TBB still reads a private title or an unscoped entry list'
    );
    $assert(
        !str_contains($bookSource, '$action === "save_title"'),
        'ETB/TBB still exposes its former private incident-title action'
    );
    $assert(
        str_contains($bookSource, 'data-estab-requires-incident'),
        'ETB/TBB write form lacks the central incident marker'
    );
}

$assert(
    str_contains(
        $dataHandler,
        '$messageIncident = estab_incident_require_active ($messageConnection);'
    )
        && str_contains(
            $dataHandler,
            'estab_incident_command_post_name ($messageIncident);'
        )
        && str_contains($dataHandler, 'Derzeit ist kein Einsatz aktiv')
        && str_contains($dataHandler, 'fehlt der Name der Führungsstelle'),
    'message controller lacks an understandable early incident-configuration failure'
);
$assert(
    str_contains(
        $main,
        'function estab_workflow_require_active_incident_for_post ('
    )
        && str_contains($main, 'isset ($request ["m2_abmelden_x"])')
        && str_contains($main, 'http_response_code (409)'),
    'central operational POST gate is missing or blocks logout'
);
$assert(
    str_contains($form, 'data-estab-requires-incident')
        && substr_count($attachmentController, 'data-estab-requires-incident') >= 2
        && str_contains($overview, 'data-estab-requires-incident'),
    'an operational message or attachment form lacks the incident marker'
);
$assert(
    str_contains($list, 'estab_read_require_operational_scope (')
        && str_contains(
            $list,
            '$incidentId = (int) $scope ["incident"] ["active_einsatz_id"];'
        )
        && str_contains($list, '$where = array ("m.`einsatz_id` = ?");')
        && str_contains($list, 'estab_read_filter_messages (')
        && str_contains($overview, 'estab_read_require_area (')
        && str_contains(
            $overview,
            '$overviewReadScope ["incident"]["active_einsatz_id"]'
        )
        && str_contains($overview, '.`einsatz_id` = ?')
        && !str_contains(
            $overview,
            '(SELECT `active_einsatz_id` FROM `nv_einsatz_status`'
        ),
    'message lists or leadership overview can cross incident boundaries'
);
$assert(
    substr_count($sidebar, '`einsatz_id` = ?') >= 4
        && str_contains($sidebar, 'estab_incident_active($connection)'),
    'sidebar queue counts are not restricted to the active incident'
);
$assert(
    str_contains(
        $protocol,
        '(`einsatz_id`, `p_zeit`, `p_was`, `p_ereignis`)'
    )
        && str_contains($protocol, 'message_id=([1-9][0-9]*)'),
    'operational protocol events are not tied to their message incident'
);
$assert(
    str_contains($categories, 'estab_incident_with_active_write(')
        && str_contains(
            $categories,
            'WHERE `00_lfd` = ? AND `einsatz_id` = ? FOR UPDATE'
        ),
    'category assignment can mutate a message outside the active incident'
);

echo "operational incident scope: OK ({$assertions} assertions)\n";
