<?php

declare(strict_types=1);

/**
 * Eine ausgefallene Kraft darf den Nachrichtenlauf nicht anhalten.
 *
 * Bisher liess sich eine angenommene Dienstfunktion nur über eine vollständige
 * Schichtübergabe neu besetzen. Fällt eine Person aus -- Verletzung, Ablösung,
 * Abbruch --, steht die Station bis zur fertigen Übergabe still, obwohl die
 * übrige Schicht weiterarbeitet. Dieser Test hält die Einzelablösung fest: sie
 * lässt die Schicht laufen, führt abgebende und übernehmende Person genauso in
 * den Nachweis wie die Übergabe, verlangt dieselbe Berechtigungsprüfung wie
 * die Schichtvergabe und lässt niemanden gehen, der noch einen Melderauftrag
 * oder einen gesperrten Vordruck in der Hand hält.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/logbook_lifecycle.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relative);
    }
    return $source;
};
$slice = static function (
    string $source,
    string $startMarker,
    string $endMarker
): string {
    $start = strpos($source, $startMarker);
    $end = strpos(
        $source,
        $endMarker,
        $start === false ? 0 : $start + strlen($startMarker)
    );
    if (!is_int($start) || !is_int($end) || $end <= $start) {
        throw new RuntimeException(
            'Could not isolate the region starting at ' . $startMarker
        );
    }
    return substr($source, $start, $end - $start);
};

$dv = $read('app/dv_operations.php');
$lifecycle = $read('app/logbook_lifecycle.php');
$adminUi = $read('4fadm/fuehrungsstelle.php');

/*
 * Es gibt den Vorgang überhaupt. Ohne ihn bleibt eine ausgefallene Kraft bis
 * zur fertigen Schichtübergabe auf ihrer Station stehen.
 */
$assert(
    str_contains($dv, 'function estab_dv_relieve_hat(')
        && function_exists('estab_logbook_lifecycle_relief')
        && function_exists('estab_logbook_lifecycle_relief_text'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Eine einzelne Dienstfunktion lässt sich in der laufenden Schicht '
            . 'nicht neu besetzen'
    )
);

$relief = $slice(
    $dv,
    'function estab_dv_relieve_hat(',
    'function estab_dv_accept_hat('
);
$assignment = $slice(
    $dv,
    'function estab_dv_assign_hat(',
    'function estab_dv_relieve_hat('
);
$handover = $slice(
    $dv,
    'function estab_dv_initiate_handover_shift(',
    'function estab_dv_handover_requests('
);

/*
 * Der Nachweis trägt beide Personen. Die Wortwahl wird ohne Datenbank
 * geprüft, damit die Zusicherung nicht am Betrieb hängt.
 */
$record = estab_logbook_lifecycle_relief_text(
    3,
    'Nachtschicht 31.07.',
    'S2',
    'Stab',
    'Renate Ruf',
    'ruf',
    'Bernd Boll',
    'boll',
    'Ausfall nach Verletzung'
);
$assert(
    str_contains($record['ereignis'], 'Renate Ruf [ruf]')
        && str_contains($record['ereignis'], 'Bernd Boll [boll]')
        && str_contains($record['ereignis'], 'abgebend')
        && str_contains($record['ereignis'], 'übernehmend')
        && str_contains($record['ereignis'], 'S2 (Stab)')
        && str_contains($record['ereignis'], 'Schicht #3')
        && str_contains($record['ereignis'], 'Ausfall nach Verletzung'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Der Tagebucheintrag der Einzelablösung benennt abgebende Person, '
            . 'übernehmende Person, Funktion, Schicht oder Grund nicht'
    )
);
$assert(
    !str_contains($record['ereignis'], 'Dienstübergabe')
        && str_contains($record['bemerkung'], 'läuft unverändert weiter')
        && str_contains($record['bemerkung'], 'persönlichen Annahme'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung wird als Schichtübergabe ausgewiesen oder '
            . 'verschweigt, dass sie erst mit der persönlichen Annahme wirkt'
    )
);
$incomplete = false;
try {
    estab_logbook_lifecycle_relief_text(
        3,
        'Nachtschicht 31.07.',
        'S2',
        'Stab',
        'Renate Ruf',
        'ruf',
        'Bernd Boll',
        'boll',
        ''
    );
} catch (InvalidArgumentException) {
    $incomplete = true;
}
$assert(
    $incomplete,
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Eine Ablösung ohne dokumentierten Grund erzeugt einen Nachweis'
    )
);

/* Die Ablösung schreibt in beide Bücher wie die Schichterweiterung. */
$reliefWriter = $slice(
    $lifecycle,
    'function estab_logbook_lifecycle_relief(',
    'function estab_logbook_lifecycle_last_sequence('
);
$assert(
    str_contains($reliefWriter, 'estab_logbook_lifecycle_insert_etb(')
        && str_contains($reliefWriter, 'estab_logbook_lifecycle_insert_ttb(')
        && str_contains($reliefWriter, "['LdF', 'A/W', 'TBB']"),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung erreicht das Einsatztagebuch oder das TBB der '
            . 'Fernmeldebetriebsstelle nicht'
    )
);
$assert(
    str_contains($relief, 'estab_logbook_lifecycle_relief(')
        && str_contains($relief, "'hat_relieved_in_shift'")
        && str_contains($relief, "'hat_assigned_as_relief'")
        && substr_count($relief, 'estab_dv_audit(') === 2
        && str_contains($relief, "'successor' => \$successorCode")
        && str_contains($relief, "'predecessor' => \$outgoingCode"),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung wird nicht mit beiden Personen in Tagebuch und '
            . 'Betriebsereigniskette nachgewiesen'
    )
);

/*
 * Dieselbe Berechtigungsprüfung wie die Schichtvergabe: der gesperrte
 * Einsatzschreibvorgang und die Momentaufnahme des strengen Modus.
 */
foreach (
    [
        'estab_incident_with_active_write(',
        'estab_dv_require_strict_incident_snapshot($incident, $incidentId)',
    ] as $guard
) {
    $assert(
        str_contains($assignment, $guard) && str_contains($relief, $guard),
        estab_dv_requirement(
            'FUEST-KLEIN-ABLOESUNG',
            'Die Einzelablösung nutzt nicht dieselbe Berechtigungsprüfung '
                . 'wie die Schichtvergabe: ' . $guard
        )
    );
}
$assert(
    str_contains($relief, "\$row['schicht_status'] !== 'AKTIV'")
        && str_contains($relief, "\$row['status'] !== 'ANGENOMMEN'")
        && str_contains($relief, 'AND s.`einsatz_id` = ? FOR UPDATE')
        && str_contains($relief, '$outgoingCode === $successorCode'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung greift auf eine fremde, unbesetzte oder nicht '
            . 'aktive Schicht durch oder löst eine Person durch sich selbst ab'
    )
);

/* Niemand geht mit einem offenen Auftrag oder einem gesperrten Vordruck. */
$assert(
    str_contains($relief, '`nv_melderauftraege`')
        && str_contains($relief, "NOT IN ('GEMELDET','ABGEBROCHEN')")
        && str_contains($relief, 'BINARY `melder_kuerzel` = BINARY ?')
        && str_contains($handover, "NOT IN ('GEMELDET','ABGEBROCHEN')"),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Eine Person mit offenem Melderauftrag kann abgelöst werden und '
            . 'lässt den Auftrag ohne Bearbeiter zurück'
    )
);
$assert(
    str_contains($relief, '`nv_nachrichten`')
        && str_contains($relief, "`x02_sperre` IN ('t','1')")
        && str_contains($relief, 'BINARY `x03_sperruser` = BINARY ?'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Eine in Bearbeitung befindliche Nachricht der abgebenden Person '
            . 'blockiert die Ablösung nicht'
    )
);

/*
 * Die Schicht bleibt unberührt: kein Schichtwechsel, keine
 * Übergabeanforderung, und die Nachbesetzung wird nur zugewiesen.
 */
$assert(
    !str_contains($relief, '`nv_dienstuebergabe_anfragen`')
        && !str_contains($relief, 'UPDATE `nv_dienstschichten`')
        && str_contains($relief, "SET `status` = 'ABGELOEST'")
        && str_contains(
            $relief,
            '(`dienstschicht_id`, `benutzer_kuerzel`, `funktion`,'
        )
        && !str_contains($relief, '`angenommen_am`'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung übergibt die Schicht mit oder nimmt die '
            . 'Nachbesetzung ungefragt an'
    )
);

/*
 * Die bestimmte ETB-Führung bleibt der Schichtübergabe vorbehalten: eine
 * Einzelablösung würde die Buchführung ohne Übergabe unterbrechen.
 */
$assert(
    str_contains($relief, "\$function === 'ETB'")
        && str_contains($relief, 'bestätigte Schichtübergabe.'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung wechselt die bestimmte ETB-Führung ohne '
            . 'bestätigte Schichtübergabe'
    )
);

/*
 * Welche Besetzung das Buch führt, entscheidet die Schicht und nicht der
 * Name der Funktion: ohne eigene ETB-Station führt der angenommene S2. Ein
 * Schutz, der nur auf die Zeichenfolge ETB sieht, lässt genau den häufigen
 * Fall durch und unterbricht die Buchführung ohne Übergabe.
 */
$assert(
    str_contains($relief, "\$designatedId === \$assignmentId"),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung vergleicht nicht mit der tatsächlich '
            . 'bestimmten Buchführung'
    )
);
$assert(
    preg_match(
        '~designated[\s\S]{0,600}?IN \(\x27ETB\x27,\x27S2\x27\)~',
        $relief
    ) === 1,
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Einzelablösung ermittelt die bestimmte Buchführung nicht '
            . 'nach derselben Regel wie das Logbuch'
    )
);

/*
 * Weist die Datenbank die Nachbesetzung ab, bleibt die Schicht unverändert
 * und der Betrieb liest den Grund im Klartext statt eines Programmabbruchs.
 */
$assert(
    str_contains($relief, '$code === 1644')
        && str_contains($relief, 'Schichtübergabe.')
        && str_contains($relief, 'catch (mysqli_sql_exception $exception)'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Eine vom Betrieb abgewiesene Nachbesetzung endet im Programmabbruch '
            . 'statt in einer lesbaren Meldung'
    )
);

/* Die Bedienoberfläche bietet den Vorgang in der Liste der aktiven Schicht. */
$assert(
    str_contains($adminUi, "\$action === 'relieve_duty_function'")
        && str_contains($adminUi, 'estab_dv_relieve_hat(')
        && str_contains($adminUi, "value=\"relieve_duty_function\"")
        && str_contains($adminUi, 'Funktion einzeln ablösen')
        && str_contains($adminUi, 'name="nachfolger_kuerzel"')
        && str_contains($adminUi, 'name="abloesungsgrund"')
        /*
         * Die Spalte hiess frueher <th>Einzelablösung</th> im
         * handgeschriebenen Tabellenmarkup. Die Besetzungsliste kommt
         * jetzt aus dem Tabellenbauteil, das seine Koepfe selbst setzt;
         * die Anforderung ist dieselbe geblieben, nur ihr Anker nicht.
         * Geprueft wird deshalb, dass *die Tafel der aktiven Schicht*
         * diese Spalte nennt -- nicht irgendeine Stelle der Datei.
         */
        && preg_match(
            // Kein ".*?" ueber Anweisungsgrenzen hinweg: Der erste
            // Versuch fand das Wort irgendwo spaeter in der Datei und
            // haette eine geloeschte Spalte durchgewunken. "[^;]"
            // haelt die Suche im Aufruf.
            '~fuehrungsstelle_tafel\(\s*.aktive-schicht-[^;]*'
                . 'Einzelablösung[^;]*\);~su',
            $adminUi
        ) === 1,
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Führungsstellenverwaltung bietet die Einzelablösung nicht in '
            . 'der Besetzungsliste der aktiven Schicht an'
    )
);
$assert(
    str_contains(
        $adminUi,
        "'assign_duty_function',\n                'relieve_duty_function',"
    ),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Nach einem Wechsel in den lockeren Berechtigungsmodus meldet die '
            . 'Einzelablösung eine unbekannte Aktion statt des gewechselten '
            . 'Betriebsmodus'
    )
);

// Der PHP-Weg allein genügt nicht: der Insert-Trigger aus Migration 94 wies
// jede Funktion ab, die in einer aktiven Schicht schon einmal besetzt war --
// ohne den Status der früheren Besetzung zu betrachten. Ohne die Migration
// wäre die Ablösung für alle Stationen ausser A/W wirkungslos, und dieser
// Test würde eine Regel als erfüllt melden, die im Betrieb nicht greift.
$relief = file_get_contents(
    $root . '/docker/db/migrations/120-single-function-relief.sql'
);
$assert(
    is_string($relief) && $relief !== '',
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Migration, die den Insert-Trigger für die Einzelablösung öffnet, '
            . 'fehlt'
    )
);
$relief = (string) $relief;
$assert(
    str_contains($relief, 'CREATE OR REPLACE TRIGGER `estab_dv94_hat_insert`')
        && str_contains(
            $relief,
            "existing_assignment.`status` IN ('ZUGEWIESEN','ANGENOMMEN')"
        )
        && str_contains($relief, 'relieved_predecessor_ignored'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Der Insert-Trigger zählt eine abgelöste Vorgängerbesetzung weiterhin '
            . 'als Besetzung und blockiert damit die Nachbesetzung'
    )
);
$assert(
    str_contains($relief, "'119-inactive-messenger-dispatch.sql'")
        && str_contains($relief, 'predecessor ledger is missing')
        && str_contains($relief, 'trigger collision')
        && str_contains($relief, 'trigger mismatch'),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Die Migration prüft weder ihren Vorgänger noch das Ergebnis und kann '
            . 'einen fremden Trigger stillschweigend überschreiben'
    )
);

// Einfachbesetzung bleibt erzwungen -- sonst öffnete die Ablösung die Tür für
// zwei gleichzeitige Inhaber derselben Funktion.
$controls = file_get_contents(
    $root . '/docker/db/migrations/94-dv-organisational-controls.sql'
);
$assert(
    is_string($controls)
        && str_contains(
            (string) $controls,
            'UNIQUE KEY `uq_dienstbesetzung_aktive_funktion`'
        ),
    estab_dv_requirement(
        'FUEST-KLEIN-ABLOESUNG',
        'Ohne den eindeutigen Schlüssel auf der aktiven Funktion könnte die '
            . 'Ablösung eine Funktion doppelt besetzen'
    )
);

// Die Migration muss in allen Registern stehen, sonst läuft sie nie.
foreach ([
    'docker/db/verify.sql',
    'app/readiness.php',
    'tests/integration/schema_migrator.sh',
] as $registry) {
    $source = file_get_contents($root . '/' . $registry);
    $assert(
        is_string($source)
            && str_contains((string) $source, '120-single-function-relief.sql'),
        estab_dv_requirement(
            'FUEST-KLEIN-ABLOESUNG',
            'Die Migration ist in ' . $registry . ' nicht registriert und '
                . 'würde im Betrieb nicht angewandt'
        )
    );
}

echo 'DV single-function relief: OK (' . $assertions . " assertions)\n";
