<?php

declare(strict_types=1);

/**
 * Aus jedem Zustand führt ein Weg heraus.
 *
 * Am 03.09.2026 kamen zwei Meldungen aus dem Betrieb, und es war zweimal
 * derselbe Fehler:
 *
 *   „Wenn ich eine Schicht plane und aus Versehen jemand Falschem eine Rolle
 *   gegeben habe, kann ich das nicht wieder entfernen, ohne die gesamte
 *   Planung zu verwerfen."
 *
 *   „Wenn keine Dienstschicht aktiv ist, kommt rechts nur das Feld mit
 *   ,operativer Zugriff nicht verfügbar' -- da fehlt mir aber der
 *   Abmeldeknopf."
 *
 * Einmal kostete der Ausweg mehr als der Fehler wert war, einmal gab es gar
 * keinen. Diese Prüfung hält beide Ausgänge fest.
 *
 * ## Die Rücknahme
 *
 * Die Einzelablösung taugt dafür nicht: Sie verlangt eine laufende Schicht
 * und eine übernehmende Person und weist den Wechsel im ETB nach. Vor der
 * Aktivierung gibt es weder ETB noch TBB, und eine Fehlzuweisung hat keine
 * übernehmende Person -- sie hat nur zu verschwinden.
 *
 * Die Datenbank kennt beide Wege bereits; die Anwendung nutzte sie nur nicht
 * einzeln:
 *
 *   ZUGEWIESEN -> ZURUECKGEZOGEN   Die Person hat nie zugestimmt.
 *   ANGENOMMEN -> ABGELOEST        Sie hat zugestimmt, die Schicht lief nie.
 *
 * Genau diese beiden Übergänge macht estab_dv_close_shift() heute schon --
 * nur für alle Zeilen auf einmal. Die Rücknahme ist damit ausdrücklich
 * *weniger* als der Ausweg, der bisher der einzige war.
 *
 * ## Der Weg hinaus
 *
 * Die Statuszeile trägt seit dem Umbau auch die Anmeldung und den
 * Abmeldeknopf. Sie wurde durch einen leeren String ersetzt, sobald keine
 * Arbeitsfunktion gewählt war -- und damit verschwand der Ausgang genau in
 * der Lage, die ihn nötig macht.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/ux_rules.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $relative) use ($root, $assert): string {
    $source = file_get_contents($root . '/' . $relative);
    $assert(
        is_string($source) && $source !== '',
        estab_ux_requirement(
            'UX-KEIN-EINBAHNWEG',
            'Die Quelle ' . $relative . ' ist nicht lesbar.'
        )
    );

    return (string) $source;
};
$slice = static function (
    string $source,
    string $von,
    string $bis
) use ($assert): string {
    $start = strpos($source, $von);
    $end = $start === false
        ? false
        : strpos($source, $bis, $start + strlen($von));
    $assert(
        is_int($start) && is_int($end) && $end > $start,
        estab_ux_requirement(
            'UX-KEIN-EINBAHNWEG',
            'Der Quelltextabschnitt „' . $von . '" fehlt.'
        )
    );

    return substr($source, (int) $start, (int) $end - (int) $start);
};

$dv = $read('app/dv_operations.php');
$adminUi = $read('4fadm/fuehrungsstelle.php');
$cockpit = $read('4fach/vorgaben.php');

// ---------------------------------------------------------------------------
// 1. Eine einzelne Zuweisung lässt sich einzeln zurücknehmen.
// ---------------------------------------------------------------------------

$assert(
    str_contains($dv, 'function estab_dv_withdraw_hat('),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Eine einzelne Zuweisung einer geplanten Schicht lässt sich nicht '
            . 'zurücknehmen; es bleibt nur, die ganze Planung zu verwerfen.'
    )
);

$ruecknahme = $slice(
    $dv,
    'function estab_dv_withdraw_hat(',
    "\nfunction "
);

/*
 * Beide Ausgangszustände werden bedient. Nur ZUGEWIESEN zu können hiesse:
 * Wer die falsche Person erwischt hat und deren Annahme abwartet, sitzt
 * wieder fest.
 */
$assert(
    str_contains($ruecknahme, "'ZUGEWIESEN' => 'ZURUECKGEZOGEN'")
        && str_contains($ruecknahme, "'ANGENOMMEN' => 'ABGELOEST'"),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Rücknahme bedient nicht beide Ausgangszustände. Eine bereits '
            . 'angenommene Fehlzuweisung bliebe stehen.'
    )
);
/*
 * Der Nachweis wandert mit: `abgeloest_am` ist die Bedingung, unter der die
 * Datenbank beide Übergänge überhaupt zulässt, und ein Nachfolger wird nicht
 * gesetzt -- es übernimmt ja niemand.
 */
$assert(
    str_contains($ruecknahme, '`abgeloest_am` = NOW(6)')
        && !str_contains($ruecknahme, 'nachfolger_id'),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Rücknahme setzt nicht den Zeitpunkt, den die Datenbank für '
            . 'beide Übergänge verlangt, oder benennt einen Nachfolger, den '
            . 'es nicht gibt.'
    )
);
/*
 * Sie gilt ausschliesslich für die geplante Schicht. Eine laufende wechselt
 * ihre Träger über die Einzelablösung mit übernehmender Person und ETB-Eintrag;
 * eine Rücknahme dort wäre ein stiller Rechteentzug.
 */
$assert(
    str_contains($ruecknahme, "\$shiftStatus !== 'GEPLANT'")
        && str_contains($ruecknahme, "\$row['schicht_aktiviert_am'] !== null"),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Rücknahme prüft nicht, dass die Schicht geplant und nie '
            . 'aktiviert ist.'
    )
);
$assert(
    str_contains($ruecknahme, 'estab_dv_require_strict_incident_snapshot(')
        && str_contains($ruecknahme, 'estab_incident_with_active_write(')
        && str_contains($ruecknahme, 'FOR UPDATE'),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Rücknahme läuft nicht unter denselben Bedingungen wie jede '
            . 'andere förmliche Schichtänderung: strenger Modus, aktiver '
            . 'Einsatz, gesperrte Zeile.'
    )
);
/* Sie prüft, dass sie genau eine Zeile traf, und weist sich nach. */
$assert(
    str_contains($ruecknahme, "\$update->affected_rows !== 1")
        && str_contains($ruecknahme, "'action' => 'hat_withdrawn'"),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Rücknahme prüft ihre Wirkung nicht oder weist sich nicht im '
            . 'Einsatzprotokoll nach.'
    )
);
/*
 * Kein Buch. Vor der Aktivierung gibt es weder ETB noch TBB, und die
 * Zuweisung selbst schreibt dort auch nichts. Was nie operativ wirkte,
 * hinterlässt beim Verschwinden nichts.
 */
$assert(
    !str_contains($ruecknahme, 'estab_logbook'),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Rücknahme schreibt ein Buch, obwohl die Schicht nie lief und '
            . 'die Zuweisung selbst keines geschrieben hat.'
    )
);

// ---------------------------------------------------------------------------
// 2. Die Seite bietet die Rücknahme dort an, wo der Fehler steht.
// ---------------------------------------------------------------------------

$assert(
    str_contains($adminUi, "value=\"withdraw_duty_function\"")
        && str_contains($adminUi, "\$action === 'withdraw_duty_function'")
        && str_contains($adminUi, 'estab_dv_withdraw_hat('),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Schichtverwaltung bietet die Rücknahme nicht an.'
    )
);
/*
 * In der Zeile, die sie betrifft. Ein Formular unter der Tafel, in dem man
 * die falsche Person noch einmal auswählen müsste, ist ein zweiter Griff
 * daneben.
 */
$tafel = $slice(
    $adminUi,
    '$besetzungMarkup = [];',
    "echo fuehrungsstelle_tafel(\n            'geplante-schicht-"
);
$assert(
    str_contains($tafel, 'value="withdraw_duty_function"')
        && str_contains($tafel, "value=\"<?= (int) \$hat['dienstbesetzung_id'] ?>\""),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Rücknahme steht nicht in der Zeile der betroffenen Besetzung.'
    )
);
$assert(
    str_contains(
        $adminUi,
        "['Funktion', 'Person', 'Status', 'Rücknahme']"
    ),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Tafel der geplanten Besetzung führt keine Spalte für die '
            . 'Rücknahme.'
    )
);
/*
 * Und die zurückgenommene Zeile zählt nicht mehr als besetzt.
 *
 * Die Zählung stand vor der Statusprüfung. Solange es keine Rücknahme gab,
 * fiel das nicht auf -- beendete Zeilen entstanden erst beim Schliessen der
 * Schicht, und dann verschwindet der Kasten ohnehin. Mit der Rücknahme wäre
 * daraus ein Fehler geworden: Die Funktion hätte weiter als besetzt gegolten,
 * und niemand hätte gesehen, dass sie neu vergeben werden muss.
 */
$zaehlung = $slice(
    $adminUi,
    '$shiftAssigned = [];',
    '$missingAssignment = estab_dv_shift_start_missing('
);
$statusPruefung = strpos($zaehlung, "\$status !== 'ZUGEWIESEN' && \$status !== 'ANGENOMMEN'");
$zaehlen = strpos($zaehlung, '$shiftAssigned[] =');
$assert(
    is_int($statusPruefung) && is_int($zaehlen) && $statusPruefung < $zaehlen,
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Seite zählt eine Besetzung als zugewiesen, bevor sie deren '
            . 'Status prüft -- eine zurückgenommene Zuweisung gälte weiter '
            . 'als besetzt.'
    )
);
/* Der Hinweis auf das Protokoll sagt auch, dass die Funktion wieder frei ist. */
$assert(
    str_contains($adminUi, 'im Einsatzprotokoll. Die Funktion ist wieder frei'),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Nach einer Rücknahme sagt die Seite nicht, dass die Funktion wieder '
            . 'vergeben werden kann.'
    )
);

// ---------------------------------------------------------------------------
// 3. Der Weg hinaus verschwindet nicht mit dem Grund, der ihn nötig macht.
// ---------------------------------------------------------------------------

/*
 * Ohne gewählte Arbeitsfunktion gibt es keine Warteschlangen -- aber es gibt
 * eine Anmeldung. Hier stand ein leerer String, und mit ihm verschwand die
 * Statuszeile, in der seit dem Umbau auch der Abmeldeknopf wohnt.
 */
$statuszeile = $slice(
    $cockpit,
    '$statusMarkup = $selectedIdentity !== null',
    "\nif (\$statusFragment) {"
);
$assert(
    str_contains($statuszeile, 'estab_session_ui_current_markup('),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Ohne gewählte Arbeitsfunktion wird die Anmeldeleiste nicht gebaut '
            . '-- und mit ihr fällt der Abmeldeknopf weg.'
    )
);
/*
 * Aber nur bei angemeldetem Funktionskonto.
 *
 * estab_session_ui_current_markup() fällt für Anonyme auf die öffentliche
 * Anmeldeleiste zurück. Ohne diese Bedingung stand sie auf jeder
 * Werkzeugseite hinter der Basic-Anmeldung -- die kennt kein eStab-Konto,
 * und dort ist keine Leiste richtig, nicht eine leere und erst recht keine
 * Anmeldeaufforderung. Die CI hat genau das gefangen
 * (tests/browser/headless_ui.py, „einzelne Shared-Bar").
 */
$assert(
    str_contains($statuszeile, "\$identity === null ? '' :"),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Ersatzleiste wird auch ohne angemeldetes Funktionskonto '
            . 'gebaut; auf den Werkzeugseiten hinter der Basic-Anmeldung '
            . 'stünde dann eine Anmeldeaufforderung, wo keine Leiste '
            . 'hingehört.'
    )
);
/*
 * Und zwar dieselbe wie sonst: schmal, als Seitenleiste, ohne Marke. Sonst
 * springt der Knopf beim Wechsel des Zustands an eine andere Stelle.
 */
$aufrufe = [];
$ort = 0;
while (
    ($ort = strpos($cockpit, 'estab_session_ui_current_markup(', $ort))
    !== false
) {
    $tiefe = 0;
    $lauf = $ort + strlen('estab_session_ui_current_markup(') - 1;
    for ($i = $lauf; $i < strlen($cockpit); $i++) {
        if ($cockpit[$i] === '(') {
            $tiefe++;
        } elseif ($cockpit[$i] === ')') {
            $tiefe--;
            if ($tiefe === 0) {
                /*
                 * Eine Nennung im Kommentar -- „estab_session_ui_current_markup()"
                 * -- ist kein Aufruf. Sie hat keine Argumente, und genau
                 * daran ist sie zu erkennen.
                 */
                $args = trim(preg_replace(
                    '~\s+~',
                    ' ',
                    substr($cockpit, $lauf + 1, $i - $lauf - 1)
                ) ?? '');
                if ($args !== '') {
                    $aufrufe[] = $args;
                }
                break;
            }
        }
    }
    $ort += 10;
}
/*
 * Verglichen wird ohne den ersten Parameter: Der eine Aufruf steht in einer
 * Funktion und reicht ihren Parameter `$session` weiter, der andere steht im
 * Dateirumpf und nimmt `$_SESSION`. Dieselbe Sitzung, zwei Namen. Alles
 * dahinter -- schmal, Seitenleiste, ohne Navigation, ohne Einsatzanzeige,
 * ohne Marke -- muss gleich sein, sonst springt der Knopf beim Wechsel des
 * Zustands an eine andere Stelle.
 */
$gestalt = array_map(
    static fn (string $args): string => trim(implode(
        ',',
        array_slice(explode(',', $args), 1)
    )),
    $aufrufe
);
$assert(
    count($gestalt) === 2 && $gestalt[0] === $gestalt[1] && $gestalt[0] !== '',
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Anmeldeleiste ohne Arbeitsfunktion wird anders gebaut als die '
            . 'mit -- der Abmeldeknopf stünde dann an einer anderen Stelle. '
            . 'Gefunden: ' . implode(' || ', $gestalt)
    )
);
/*
 * Gegenprobe an der Quelle des Knopfes: Er hängt an der Anmeldung, nicht an
 * der Arbeitsfunktion. estab_session_ui_markup() steigt allein bei fehlender
 * Anmeldung aus.
 */
$sessionUi = $read('app/session_ui.php');
$leiste = $slice(
    $sessionUi,
    'function estab_session_ui_markup(',
    "\nfunction "
);
$assert(
    str_contains($leiste, 'estab-session-logout')
        && str_contains($leiste, "name=\"logout_action\" value=\"logout\""),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Anmeldeleiste trägt kein Abmeldeformular mehr.'
    )
);
$assert(
    substr_count($leiste, 'return \'\';') === 1
        && str_contains($leiste, "\$identity = estab_auth_session_identity(\$session);"),
    estab_ux_requirement(
        'UX-KEIN-EINBAHNWEG',
        'Die Anmeldeleiste steigt aus mehr als einem Grund aus; der Weg '
            . 'hinaus hinge dann an mehr als der Anmeldung.'
    )
);

echo 'Kein Einbahnweg: OK (' . $assertions . " assertions)\n";
