<?php

declare(strict_types=1);

/**
 * Der Fernmeldeplan: welche Verbindungen gerade zur Verfügung stehen.
 *
 * Führungskräfte müssen wissen, über welche Verbindungen sie verfügen. Ein
 * Fernmeldeplan, der nur bei S 6 liegt, erfüllt das nicht -- er muss dort
 * sichtbar sein, wo jemand einen Weg auswählt.
 *
 * Zugleich muss erkennbar bleiben, welcher Stand gilt. Ein Plan, der sich
 * unbemerkt ändert, ist schlimmer als keiner: Der Leiter des
 * Fernmeldebetriebes disponiert dann nach einem Bild, das es nicht mehr gibt.
 * Deshalb trägt jeder Stand eine Version, und jede Änderung an einem Entwurf
 * nennt den Stand, auf dem sie aufsetzt.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';

$originalWorkingDirectory = getcwd();
if (!is_string($originalWorkingDirectory) || !chdir($root . '/4fach')) {
    throw new RuntimeException('Cannot enter the message runtime directory');
}
try {
    require_once $root . '/4fach/tools.php';
    require_once $root . '/4fach/vali_data.php';
} finally {
    chdir($originalWorkingDirectory);
}
if (!function_exists('estab_message_html')) {
    function estab_message_html(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}
require_once $root . '/app/permission_mode.php';
require_once $root . '/app/dv_operations.php';
require_once $root . '/4fach/official_message_form.php';

final class TelecomPlanFormFixture
{
    use EstabOfficialMessageFormView;

    /** @var array<string,mixed> */
    public array $formdata = [];

    /** @var array<string,bool> */
    public array $errorselect = [];

    /** @var array<int,bool> */
    public array $feld = [];

    /** @var list<array<string,mixed>> */
    public array $activeTelecomRoutes = [];

    /** @var array{name:string,erreichbarkeit:string}|null */
    public ?array $incomingCounterpart = null;

    public string $task = 'LdF-Ausgang';

    public function safe_message_value(string $field): string
    {
        return estab_message_html($this->formdata[$field] ?? '');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$render = static function (callable $callback): string {
    ob_start();
    try {
        $callback();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
};

/* --- Der geltende Stand trägt eine Version, und sie hängt am Inhalt --- */

$plan = [
    'fernmeldeplan_id' => 7,
    'version' => 3,
    'status' => 'AKTIV',
    'einsatzbezeichnung' => 'Übung Rheinhochwasser',
    'herkunft' => 'S 6',
    'gueltig_ab' => '2026-08-25 06:00:00',
    'gueltig_bis' => null,
    'betriebsleitung' => 'LdF',
    'bemerkungen' => '',
    'eintraege' => [
        [
            'fernmeldeplan_eintrag_id' => 41,
            'sortierung' => 1,
            'betriebsstelle' => 'Führungsstelle',
            'erreichbarkeit' => 'Heinsberg 10',
            'medium' => 'Fu',
            'kanal' => '55',
            'bandlage' => 'W/U',
            'verkehrsform' => 'Wechselverkehr',
            'besondere_vermerke' => '',
            'bemerkungen' => '',
        ],
    ],
];

$baseline = estab_dv_telecom_plan_revision($plan);
$assert(
    $baseline === estab_dv_telecom_plan_revision($plan),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Derselbe Plan ergibt zwei verschiedene Stände.'
    )
);

$changes = [
    'ein geänderter Kanal' => static function (array $plan): array {
        $plan['eintraege'][0]['kanal'] = '31';
        return $plan;
    },
    'ein zusätzlicher Fernmeldeweg' => static function (array $plan): array {
        $plan['eintraege'][] = $plan['eintraege'][0];
        $plan['eintraege'][1]['fernmeldeplan_eintrag_id'] = 42;
        return $plan;
    },
    'ein entfernter Fernmeldeweg' => static function (array $plan): array {
        $plan['eintraege'] = [];
        return $plan;
    },
    'eine andere Version' => static function (array $plan): array {
        $plan['version'] = 4;
        return $plan;
    },
    'ein anderer Status' => static function (array $plan): array {
        $plan['status'] = 'ENTWURF';
        return $plan;
    },
    'ein anderes Gültigkeitsende' => static function (array $plan): array {
        $plan['gueltig_bis'] = '2026-08-26 06:00:00';
        return $plan;
    },
    'eine andere Betriebsleitung' => static function (array $plan): array {
        $plan['betriebsleitung'] = 'A/W';
        return $plan;
    },
];
foreach ($changes as $what => $change) {
    $assert(
        estab_dv_telecom_plan_revision($change($plan)) !== $baseline,
        estab_dv_requirement(
            'TKM-FERNMELDEPLAN',
            'Der Stand des Fernmeldeplans bleibt gleich, obwohl ' . $what
                . ' vorliegt. Eine Änderung bliebe unbemerkt.'
        )
    );
}

// Ein Stand, der nicht wie ein Stand aussieht, wird zurückgewiesen -- nicht
// stillschweigend als "kein Stand" behandelt.
foreach ([null, '', 'aktuell', 42, str_repeat('g', 64), $baseline . '0'] as $bad) {
    $rejected = false;
    try {
        estab_dv_telecom_revision_token($bad);
    } catch (EstabDvInputException) {
        $rejected = true;
    }
    $assert(
        $rejected,
        estab_dv_requirement(
            'TKM-FERNMELDEPLAN',
            'Ein Entwurf lässt sich mit dem Bearbeitungsstand '
                . var_export($bad, true) . ' fortschreiben.'
        )
    );
}
$assert(
    estab_dv_telecom_revision_token($baseline) === $baseline,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Ein gültiger Bearbeitungsstand wird nicht angenommen.'
    )
);

// Jede Änderung am Plan setzt auf einem benannten Stand auf.
$operations = file_get_contents($root . '/app/dv_operations.php');
$assert(
    is_string($operations),
    'Die Fernmeldeplanung ist nicht lesbar'
);
$assert(
    substr_count($operations, 'estab_dv_require_telecom_plan_revision(') >= 6,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Eine Änderung am Fernmeldeplan prüft den Stand nicht, auf dem sie '
            . 'aufsetzt.'
    )
);

// Ein freigegebener Plan wird nicht überschrieben, sondern abgelöst.
$controls = file_get_contents(
    $root . '/docker/db/migrations/94-dv-organisational-controls.sql'
);
$assert(
    is_string($controls) && str_contains(
        $controls,
        'estab_dv94_fernmeldeplan_immutable'
    ),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Ein freigegebener Fernmeldeplan lässt sich nachträglich ändern.'
    )
);

/* --- Und er ist dort sichtbar, wo ein Weg gewählt wird --- */

$fixture = new TelecomPlanFormFixture();
$fixture->activeTelecomRoutes = [
    [
        'fernmeldeplan_eintrag_id' => 41,
        'plan_version' => 3,
        'medium' => 'Fu',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Heinsberg 10',
        'kanal' => '55',
        'bandlage' => 'W/U',
        'verkehrsform' => 'Wechselverkehr',
    ],
];
$offered = $render(static function () use ($fixture): void {
    $fixture->official_message_workflow_controls();
});

foreach (
    ['Heinsberg 10', 'Führungsstelle', '55', 'Wechselverkehr', 'Plan v3']
    as $detail
) {
    $assert(
        str_contains($offered, estab_message_html($detail)),
        estab_dv_requirement(
            'TKM-FERNMELDEPLAN',
            'Der freigegebene Fernmeldeweg nennt „' . $detail . '“ nicht. '
                . 'Wer disponiert, sieht nicht, worüber er verfügt.'
        )
    );
}
$assert(
    str_contains($offered, 'value="41"'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Der angebotene Fernmeldeweg lässt sich nicht auswählen.'
    )
);
$assert(
    !$fixture->official_message_manual_disposition(),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Trotz vorliegendem Fernmeldeplan wird von Hand disponiert.'
    )
);

/*
 * Ohne freigegebenen Plan wird das nicht verschwiegen, sondern gesagt -- und
 * was dann geschieht, hängt an der Betriebsart. In der Betriebsart "streng"
 * ist der Plan verbindlich: Die Nachricht bleibt liegen, bis S 6 ihn
 * veröffentlicht. In der Betriebsart "locker" darf der Leiter des
 * Fernmeldebetriebes den Weg unmittelbar benennen.
 */
$withMode = static function (?string $mode) use ($render): array {
    $previous = $GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY] ?? null;
    if ($mode === null) {
        unset($GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY]);
    } else {
        $GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY] = [
            'incident_id' => 1, 'mode' => $mode, 'revision' => 1,
        ];
    }
    try {
        $form = new TelecomPlanFormFixture();
        $markup = $render(static function () use ($form): void {
            $form->official_message_workflow_controls();
        });
        return ['markup' => $markup, 'manual' => $form->official_message_manual_disposition()];
    } finally {
        if ($previous === null) {
            unset($GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY]);
        } else {
            $GLOBALS[ESTAB_PERMISSION_CONTEXT_KEY] = $previous;
        }
    }
};

foreach (
    [ESTAB_PERMISSION_MODE_STRICT, ESTAB_PERMISSION_MODE_LOOSE, null]
    as $mode
) {
    $state = $withMode($mode);
    $assert(
        str_contains($state['markup'], 'Fernmeldeplan'),
        estab_dv_requirement(
            'TKM-FERNMELDEPLAN',
            'Fehlt der Fernmeldeplan, bleibt die Auswahl kommentarlos leer '
                . '(Betriebsart ' . var_export($mode, true) . ').'
        )
    );
}

$strict = $withMode(ESTAB_PERMISSION_MODE_STRICT);
$assert(
    $strict['manual'] === false,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'In der Betriebsart „streng“ wird ohne freigegebenen Plan von Hand '
            . 'disponiert; der Plan wäre dann nicht verbindlich.'
    )
);
$assert(
    str_contains($strict['markup'], 'estab-message-plan-blocked'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'In der Betriebsart „streng“ erfährt der Leiter des '
            . 'Fernmeldebetriebes nicht, warum er nicht disponieren kann.'
    )
);

$loose = $withMode(ESTAB_PERMISSION_MODE_LOOSE);
$assert(
    $loose['manual'] === true,
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'In der Betriebsart „locker“ gibt es ohne freigegebenen Plan keinen '
            . 'Weg, überhaupt zu disponieren.'
    )
);
$assert(
    str_contains($loose['markup'], 'name="06_befweg"'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'In der Betriebsart „locker“ fehlt das Feld für den unmittelbar '
            . 'benannten Beförderungsweg.'
    )
);

/* --- Auch der Eingang waehlt einen Weg, also sieht auch er den Plan --- */

/*
 * Der Weg des Eingangs ist FREIWILLIG.
 *
 * Der Fernmelder weiss das Mittel immer -- den Weg meistens, aber nicht
 * zwingend. Ein Pflichtfeld erzwaenge dort eine Angabe, und eine erzwungene
 * Angabe ist eine erfundene. Der Ausgang dagegen MUSS waehlen: dort
 * disponiert der Leiter des Fernmeldebetriebes.
 */
$incomingFixture = new TelecomPlanFormFixture();
$incomingFixture->task = 'FM-Eingang';
$incomingFixture->activeTelecomRoutes = [
    [
        'fernmeldeplan_eintrag_id' => 41,
        'plan_version' => 3,
        'medium' => 'Fu',
        'funkart' => 'DIGITAL',
        'betriebsstelle' => 'Führungsstelle',
        'erreichbarkeit' => 'Heros Heinsberg 10',
        'rufgruppe' => 'THW_NRW_1',
        'gegenstellen' => [
            [
                'gegenstelle_id' => 88,
                'name' => 'Kreisleitstelle',
                'erreichbarkeit' => 'Florian Heinsberg',
            ],
        ],
    ],
];
$incoming = $render(static function () use ($incomingFixture): void {
    $incomingFixture->official_message_workflow_controls();
});

foreach (
    ['Heros Heinsberg 10', 'Führungsstelle', 'THW_NRW_1', 'Funk (digital)']
    as $detail
) {
    $assert(
        str_contains($incoming, estab_message_html($detail)),
        estab_dv_requirement(
            'TKM-FERNMELDEPLAN',
            'Der Fernmelder waehlt einen Eingangsweg, ohne „' . $detail
                . '“ zu sehen. Er verfuegt ueber etwas, das er nicht kennt.'
        )
    );
}
$assert(
    str_contains($incoming, 'name="fernmeldeplan_eintrag_id"')
        && !str_contains(
            $incoming,
            'name="fernmeldeplan_eintrag_id" required'
        )
        && str_contains($incoming, '— kein Weg angegeben —'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Der Eingangsweg ist entweder gar nicht waehlbar oder Pflicht. '
            . 'Beides ist falsch: der Fernmelder kennt ihn meistens, aber '
            . 'nicht immer.'
    )
);
$assert(
    str_contains($incoming, 'name="estab_gegenstelle_id"')
        && str_contains($incoming, 'Kreisleitstelle · Florian Heinsberg')
        && str_contains($incoming, '<optgroup'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die Gegenstellen des gewaehlten Weges stehen dem Fernmelder nicht '
            . 'zur Auswahl. Feld 15 liesse sich dann beim LdF nicht '
            . 'vorbelegen.'
    )
);
$assert(
    str_contains($incoming, 'name="estab_eingangsweg_bemerkung"'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Der Fernmelder kann zum Eingangsweg nichts anmerken.'
    )
);

/*
 * Der eine sagt aus, der andere prueft.
 *
 * Beim Leiter des Fernmeldebetriebes steht die Bemerkung des Fernmelders als
 * Text, nicht als Eingabefeld. Koennte der Pruefer die Aussage umschreiben,
 * waere die Pruefung wertlos.
 */
$leadFixture = new TelecomPlanFormFixture();
$leadFixture->task = 'LdF-Eingang';
$leadFixture->activeTelecomRoutes = $incomingFixture->activeTelecomRoutes;
$leadFixture->formdata = [
    'fernmeldeplan_eintrag_id' => '41',
    'estab_eingangsweg_bemerkung' => 'Verbindung brach zweimal ab',
    '01_medium' => 'Fu',
];
$leadFixture->incomingCounterpart = [
    'name' => 'Kreisleitstelle',
    'erreichbarkeit' => 'Florian Heinsberg',
];
$lead = $render(static function () use ($leadFixture): void {
    $leadFixture->official_message_workflow_controls();
});
$assert(
    str_contains($lead, 'Verbindung brach zweimal ab')
        && !str_contains($lead, 'name="estab_eingangsweg_bemerkung"'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Der Leiter des Fernmeldebetriebes sieht die Bemerkung des '
            . 'Fernmelders nicht -- oder er kann sie umschreiben. Dann sagt '
            . 'der Nachweis nicht mehr, wer was behauptet hat.'
    )
);
$assert(
    str_contains($lead, 'Vom Fernmelder benannter Weg')
        && str_contains($lead, 'Funk (digital) · Führungsstelle')
        && str_contains($lead, 'Gegenstelle laut Fernmeldeplan')
        && str_contains($lead, 'Feld 15 ist daraus vorbelegt'),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Der Leiter des Fernmeldebetriebes erfaehrt nicht, welchen Weg und '
            . 'welche Gegenstelle der Fernmelder benannt hat. Er kann dann '
            . 'nichts pruefen.'
    )
);

$workflow = file_get_contents($root . '/app/workflow.php');
$assert(
    is_string($workflow)
        && str_contains(
            $workflow,
            "in_array(\$task, ['LdF-Eingang', 'LdF-Ausgang'], true)\n"
            . "            && array_key_exists('estab_eingangsweg_bemerkung', \$request)"
        ),
    estab_dv_requirement(
        'TKM-FERNMELDEPLAN',
        'Die Bemerkung des Fernmelders ist nur in der Maske geschuetzt. Wer '
            . 'die Maske umgeht, kann eine fremde Aussage umschreiben.'
    )
);

printf("Fernmeldeplan: OK (%d assertions)\n", $assertions);
