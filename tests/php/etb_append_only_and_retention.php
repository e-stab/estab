<?php

declare(strict_types=1);

/**
 * Fortschreibung und Aufbewahrung: Was einmal gebucht ist, bleibt stehen.
 *
 * Einsatztagebuch und Betriebsbuch werden fortschreibend geführt. Eine
 * Korrektur löscht nichts und ändert nichts; sie wird als neuer Eintrag
 * geschrieben, der auf den alten verweist. Der Grund ist der Zweck der
 * Bücher: Sie sollen später belegen, was zu welcher Zeit bekannt war. Ein
 * nachträglich geänderter Eintrag belegt nur noch, was man heute gern
 * geschrieben hätte.
 *
 * Die Dienstvorschrift verlangt ein Jahr Aufbewahrung. Der Bestand hält
 * zehn. Der Test prüft nicht die Zahl zehn, sondern dass die zugesagte Frist
 * die vorgeschriebene nicht unterschreitet -- damit eine spätere Kürzung
 * auffällt, solange sie noch über einem Jahr liegt, und ebenso, wenn sie
 * darunter fiele.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/dv_rules.php';
require_once $root . '/app/incident.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$readSource = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . '/' . $relative);
    if (!is_string($contents)) {
        throw new RuntimeException('Nicht lesbar: ' . $relative);
    }
    return $contents;
};

/* ------------------------------------------- *
 * Fortschreibung: kein Pfad ändert oder löscht *
 * ------------------------------------------- */

// Die jüngste Fassung der Regeln gilt. Ältere Migrationen haben dieselben
// Trigger schon einmal gesetzt; verbindlich ist, was zuletzt angelegt wurde.
$rules = $readSource('docker/db/migrations/110-etb-tbb-rules.sql');

foreach (['nv_etb', 'nv_tbb'] as $book) {
    foreach (['UPDATE', 'DELETE'] as $operation) {
        $pattern = '~CREATE TRIGGER `estab_'
            . ($book === 'nv_etb' ? 'etb' : 'tbb')
            . '_b' . ($operation === 'UPDATE' ? 'u' : 'd')
            . '_einsatz`\s+BEFORE ' . $operation . ' ON `' . $book
            . '`.*?END~s';
        $found = preg_match($pattern, $rules, $trigger) === 1;
        $assert(
            $found,
            estab_dv_requirement(
                'ETB-APPEND-ONLY',
                'Für ' . $book . ' fehlt die Sperre gegen ' . $operation . '.'
            )
        );
        $assert(
            $found && str_contains($trigger[0], "SIGNAL SQLSTATE '45000'"),
            estab_dv_requirement(
                'ETB-APPEND-ONLY',
                'Die Sperre gegen ' . $operation . ' auf ' . $book
                    . ' weist den Schreibversuch nicht zurück.'
            )
        );
    }
}

// Eine Datenbank ohne diese Sperren gilt nicht als betriebsbereit.
$readiness = $readSource('app/readiness.php');
foreach (
    [
        'estab_etb_bu_einsatz', 'estab_etb_bd_einsatz',
        'estab_tbb_bu_einsatz', 'estab_tbb_bd_einsatz',
    ] as $trigger
) {
    $assert(
        str_contains($readiness, "'" . $trigger . "'"),
        estab_dv_requirement(
            'ETB-APPEND-ONLY',
            'Die Betriebsbereitschaft verlangt die Sperre ' . $trigger
                . ' nicht; ein Bestand ohne sie liefe unbemerkt.'
        )
    );
}

// Und die Anwendung selbst schreibt nirgends an einem gebuchten Eintrag.
foreach (glob($root . '/app/*.php') ?: [] as $path) {
    $source = $readSource('app/' . basename($path));
    foreach (['nv_etb', 'nv_tbb'] as $book) {
        $writes = preg_match(
            '~(?:UPDATE|DELETE\s+FROM)\s+`?' . $book . '`?~i',
            $source
        ) === 1;
        $assert(
            !$writes,
            estab_dv_requirement(
                'ETB-APPEND-ONLY',
                basename($path) . ' ändert oder löscht Einträge in '
                    . $book . '.'
            )
        );
    }
}

// Die Korrektur ist ein eigener Eintrag, der den alten benennt.
$assert(
    str_contains($rules, '`estab_correction_of`'),
    estab_dv_requirement(
        'ETB-APPEND-ONLY',
        'Ein Eintrag kann nicht auf den Eintrag verweisen, den er korrigiert.'
    )
);
$lifecycle = $readSource('app/logbook_lifecycle.php');
$assert(
    str_contains(
        $lifecycle,
        'function estab_logbook_lifecycle_message_transport_correction'
    ),
    estab_dv_requirement(
        'ETB-APPEND-ONLY',
        'Es gibt keinen Weg, eine falsch gebuchte Beförderung zu berichtigen.'
    )
);

/* ------------------------------------------ *
 * Aufbewahrung: mindestens die Frist der DV   *
 * ------------------------------------------ */

/** Eine SQL-Frist in Tagen, grob, aber für einen Vergleich ausreichend. */
$intervalDays = static function (string $amount, string $unit): float {
    return (float) $amount * match (strtoupper($unit)) {
        'YEAR' => 365.0,
        'MONTH' => 30.0,
        'WEEK' => 7.0,
        'DAY' => 1.0,
        'HOUR' => 1.0 / 24.0,
        default => 0.0,
    };
};

// Die Dienstvorschrift verlangt ein Jahr.
$requiredDays = $intervalDays('1', 'YEAR');

$incident = $readSource('app/incident.php');
$found = preg_match(
    '~`estab_retain_until`\s*=\s*DATE_ADD\(NOW\(6\),\s*'
        . 'INTERVAL\s+(\d+)\s+([A-Z]+)\)~',
    $incident,
    $promised
) === 1;
$assert(
    $found,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Der Einsatzabschluss legt keine Aufbewahrungsfrist fest.'
    )
);
$assert(
    $found && $intervalDays($promised[1], $promised[2]) >= $requiredDays,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Der Einsatzabschluss sagt nur ' . ($promised[1] ?? '?') . ' '
            . ($promised[2] ?? '?') . ' Aufbewahrung zu; verlangt ist '
            . 'mindestens ein Jahr.'
    )
);

// Die Datenbank hält dieselbe Untergrenze fest, auch gegen einen Schreiber,
// der die Anwendung umgeht.
$guarded = preg_match(
    '~estab_einsaetze_bu_logbook_retention`.*?`estab_retain_until`\s*'
        . '<\s*DATE_ADD\(NEW\.`estab_closed_at`,\s*INTERVAL\s+(\d+)\s+'
        . '([A-Z]+)\).*?END~s',
    $rules,
    $enforced
) === 1;
$assert(
    $guarded,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Die Datenbank erzwingt keine Mindestaufbewahrung für einen '
            . 'abgeschlossenen Einsatz.'
    )
);
$assert(
    $guarded && $intervalDays($enforced[1], $enforced[2]) >= $requiredDays,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Die erzwungene Mindestaufbewahrung unterschreitet ein Jahr.'
    )
);

/* --- Vor Ablauf der Frist wird nichts gelöscht --- */

$future = date('Y-m-d H:i:s.u', strtotime('+5 years'));
$past = date('Y-m-d H:i:s.u', strtotime('-1 day'));

$running = estab_incident_retention_state(['estab_retain_until' => $future]);
$assert(
    $running['deletion_allowed'] === false,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Ein Einsatz darf gelöscht werden, obwohl seine Aufbewahrungsfrist '
            . 'noch läuft.'
    )
);
$assert(
    estab_incident_retention_state([])['deletion_allowed'] === false,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Ein Einsatz ohne festgelegte Frist darf gelöscht werden.'
    )
);
$assert(
    estab_incident_retention_state([
        'estab_retain_until' => $past,
        'estab_legal_hold' => true,
    ])['deletion_allowed'] === false,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Eine Aufbewahrungssperre hindert die Löschung nicht.'
    )
);
$assert(
    estab_incident_retention_state([
        'estab_retain_until' => $past,
    ])['deletion_allowed'] === true,
    estab_dv_requirement(
        'ETB-AUFBEWAHRUNG',
        'Auch nach Ablauf der Frist bleibt der Bestand unlöschbar; die '
            . 'Aufbewahrung wäre dann keine Frist, sondern ein Dauerzustand.'
    )
);

printf("Fortschreibung und Aufbewahrung: OK (%d assertions)\n", $assertions);
