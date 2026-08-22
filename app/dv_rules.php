<?php

declare(strict_types=1);

/**
 * Catalogue of the service-regulation rules this application must uphold.
 *
 * Every rule carries the document it comes from, so a test failure names the
 * regulation instead of an implementation detail. Tests state the rule they
 * cover by passing its identifier to estab_dv_requirement(); unknown
 * identifiers fail loudly, and tests/php/dv_rule_registry.php proves that no
 * rule sits in this table without a test behind it.
 */

const ESTAB_DV_SOURCE_AUSFUELLANLEITUNG =
    'Ausfüllanleitung Nachrichtenvordruck, Stand April 2022';
const ESTAB_DV_SOURCE_UNTERLAGE =
    'Unterlage Nachrichtenvordruck, Ausfüllanweisungen für den Stab';
const ESTAB_DV_SOURCE_HANDBUCH =
    'Handbuch ETB/TBB, Führung in der THW-Führungsstelle, Stand März 2022';
const ESTAB_DV_SOURCE_DV_1_101 =
    'Dienstvorschrift 1-101 Führen im THW, Stand 01.01.2006';

/**
 * @return array<string, array{source:string,reference:string,requirement:string}>
 */
function estab_dv_rules(): array
{
    return [
        'NV-FELDNUMMERN' => [
            'source' => ESTAB_DV_SOURCE_AUSFUELLANLEITUNG,
            'reference' => 'Felder 1 bis 20',
            'requirement' => 'Der Vordruck beziffert seine Felder nach der '
                . 'geltenden Ausfüllanleitung. Die am Feld sichtbare Nummer '
                . 'und die Nummer der zugehörigen Ausfüllhilfe sind gleich, '
                . 'und jedes der zwanzig Felder trägt eine Nummer.',
        ],
        'NV-PFLICHTFELDER' => [
            'source' => ESTAB_DV_SOURCE_AUSFUELLANLEITUNG,
            'reference' => 'Felder 10 und 13 bis 17',
            'requirement' => 'Die Ausfüllanleitung bezeichnet Felder, die '
                . 'immer auszufüllen sind. Der Vordruck weist die Felder aus, '
                . 'die der jeweilige Arbeitsschritt verlangt, und benennt bei '
                . 'einer Rückweisung das Feld und den Grund.',
        ],
        'NV-07-TKM-WUNSCH' => [
            'source' => ESTAB_DV_SOURCE_AUSFUELLANLEITUNG,
            'reference' => 'Feld 7',
            'requirement' => 'Feld 7 nimmt das gewünschte Übermittlungsmittel '
                . 'auf. Wer die Nachricht verfasst, muss es ausfüllen können.',
        ],
        'NV-16-ABFASSUNGSZEIT' => [
            'source' => ESTAB_DV_SOURCE_AUSFUELLANLEITUNG,
            'reference' => 'Feld 16',
            'requirement' => 'Feld 16 trägt die Abfassungszeit der Nachricht. '
                . 'Der Server setzt an ihrer Stelle keine Erfassungszeit ein, '
                . 'ohne dies am Vordruck auszuweisen.',
        ],
        'NV-19-VERTEILER-EINGANG' => [
            'source' => ESTAB_DV_SOURCE_UNTERLAGE,
            'reference' => 'Feld 19, Laufweg Eingang',
            'requirement' => 'Die Sichtung einer eingehenden Nachricht benennt '
                . 'mindestens einen Empfänger im Verteiler. Ohne Empfänger '
                . 'lässt sich die Sichtung nicht abschließen.',
        ],
        'NV-19-VERTEILER-AUSGANG' => [
            'source' => ESTAB_DV_SOURCE_UNTERLAGE,
            'reference' => 'Feld 19, Laufweg Ausgang',
            'requirement' => 'Der Verteiler gilt für ein- und ausgehende '
                . 'Nachrichten. Wer eine ausgehende Nachricht verfasst, muss '
                . 'den Verteiler ausfüllen können.',
        ],
        'NV-20-VERMERKE-ERHALT' => [
            'source' => ESTAB_DV_SOURCE_UNTERLAGE,
            'reference' => 'Feld 20',
            'requirement' => 'Feld 20 sammelt die Bearbeitungsvermerke des '
                . 'Laufwegs. Eine spätere Eintragung ergänzt die vorhandenen '
                . 'Vermerke und löscht sie nicht.',
        ],
        'NV-ZEIT-FORMAT' => [
            'source' => ESTAB_DV_SOURCE_AUSFUELLANLEITUNG,
            'reference' => 'Felder 2, 3, 4, 16 und 18',
            'requirement' => 'Uhrzeiten werden vierstellig geführt, Datumsangaben '
                . 'mindestens zweistellig.',
        ],
        'ETB-FBFUE2-NACHRICHTENBEZUG' => [
            'source' => ESTAB_DV_SOURCE_HANDBUCH,
            'reference' => 'Fb Fü 2',
            'requirement' => 'Der Ausdruck des Einsatztagebuchs weist zu jedem '
                . 'Eintrag den zugehörigen Nachrichtenvordruck aus.',
        ],
        'TBB-QUITTUNG-AUSHAENDIGUNG' => [
            'source' => ESTAB_DV_SOURCE_HANDBUCH,
            'reference' => 'Fb Fü 44, Spalte 7',
            'requirement' => 'Die Spalte Quittung/Empfänger/Ausgehändigt wird '
                . 'mit der Aushändigung der eingegangenen Nachricht ergänzt und '
                . 'enthält keine anwendungsinternen Kennungen.',
        ],
        'NV-GESPRAECHSNOTIZ-LAUFWEG' => [
            'source' => ESTAB_DV_SOURCE_UNTERLAGE,
            'reference' => 'Feld 12, Gesprächsnotiz',
            'requirement' => 'Eine Gesprächsnotiz hält ein bereits geführtes '
                . 'Gespräch fest. Sie durchläuft die Sichtung und ist damit '
                . 'abgeschlossen; eine Disposition und eine Beförderung finden '
                . 'nicht statt.',
        ],
        'FUEST-KLEIN-BEFOERDERUNG' => [
            'source' => ESTAB_DV_SOURCE_DV_1_101,
            'reference' => 'Führungsstelle ohne Stab',
            'requirement' => 'Eine Führungsstelle ohne eigenes Sachgebiet S6 '
                . 'befördert ausgehende Nachrichten auch ohne veröffentlichten '
                . 'Fernmeldeplan.',
        ],
        'FUEST-KLEIN-ABLOESUNG' => [
            'source' => ESTAB_DV_SOURCE_DV_1_101,
            'reference' => 'Besetzung der Führungsstelle',
            'requirement' => 'Fällt eine Kraft aus, lässt sich ihre Funktion '
                . 'einzeln neu besetzen, ohne die gesamte Dienstschicht zu '
                . 'übergeben.',
        ],
        'FUEST-AUFWUCHS' => [
            'source' => ESTAB_DV_SOURCE_DV_1_101,
            'reference' => 'Führungsstufen A bis D',
            'requirement' => 'Eine Führungsstelle wächst im laufenden Einsatz '
                . 'von der Führungsstelle ohne Stab zur Führungsstelle mit Stab '
                . 'auf. Der Berechtigungsmodus folgt diesem Aufwuchs.',
        ],
        'FUEST-BESETZUNG-VOLLSTAENDIG' => [
            'source' => ESTAB_DV_SOURCE_DV_1_101,
            'reference' => 'Besetzung der Führungsstelle',
            'requirement' => 'Vor der Freigabe des Einsatzes benennt die '
                . 'Anwendung die Stationen des Nachrichtenlaufs, die nicht '
                . 'besetzt sind.',
        ],
        'FUEST-DOPPELFUNKTION' => [
            'source' => ESTAB_DV_SOURCE_DV_1_101,
            'reference' => 'Mehrfachbesetzung',
            'requirement' => 'Trägt eine Person mehrere Funktionen, weist die '
                . 'Anwendung die Warteschlange jeder getragenen Funktion aus.',
        ],
    ];
}

/**
 * Resolve one rule, failing loudly on an identifier that does not exist.
 *
 * @return array{source:string,reference:string,requirement:string}
 */
function estab_dv_rule(string $id): array
{
    $rules = estab_dv_rules();
    if (!array_key_exists($id, $rules)) {
        throw new InvalidArgumentException(
            'Unknown service-regulation rule: ' . $id
        );
    }
    return $rules[$id];
}

/**
 * Message for a test that covers one rule.
 *
 * Calling this records the identifier when ESTAB_DV_COVERAGE names a file, so
 * the registry test can prove that every catalogued rule has a test.
 */
function estab_dv_requirement(string $id, string $detail = ''): string
{
    $rule = estab_dv_rule($id);
    $coverage = getenv('ESTAB_DV_COVERAGE');
    if (is_string($coverage) && $coverage !== '') {
        file_put_contents($coverage, $id . "\n", FILE_APPEND | LOCK_EX);
    }
    return '[' . $id . '] ' . $rule['source'] . ', ' . $rule['reference']
        . ': ' . $rule['requirement']
        . ($detail === '' ? '' : ' — ' . $detail);
}
