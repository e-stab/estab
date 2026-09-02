<?php

declare(strict_types=1);

/**
 * eStab passt in ein Gigabyte -- und bleibt darin.
 *
 * Das Zielgerät ist ein kleines NAS; für eStab stehen dort rund 1 GB zur
 * Verfügung. Gemessen belegte die Anwendung im Leerlauf 920 MB und hatte
 * unter Last überhaupt keine Obergrenze: Apache läuft im prefork-Modus mit
 * bis zu 150 Arbeitsprozessen zu je 256 MB. Rechnerisch 38 GB. Praktisch
 * heißt das: Es gibt keine Zahl, unterhalb derer der Dienst garantiert
 * bleibt, und auf einem Gerät mit 4 GB entscheidet dann der Kernel per
 * OOM-Killer, welcher Dienst stirbt -- im Zweifel die Datenbank mitten im
 * Einsatz.
 *
 * ## Warum die Werte so klein sind
 *
 * Sie sind nicht geschätzt, sondern an der laufenden Instanz abgelesen:
 *
 * - `KEY_BLOCKS_USED = 0` -- der 128 MB große MyISAM-Cache wird nie
 *   angefasst, alle 104 Tabellen sind InnoDB.
 * - 1.058 Plattenlesungen auf 10.011.157 Anfragen -- der Pufferpool trifft
 *   zu 99,99 %, bei 11,4 MB Datenbestand.
 * - `MAX_USED_CONNECTIONS = 7` in 3,9 Tagen, reserviert waren 151.
 *
 * Im Lasttest mit 20.000 Nachrichten war das kleinste Profil zugleich das
 * schnellste (0,591 s gegen 0,689 s bei den Vorgabewerten).
 *
 * ## Was diese Prüfung kann und was nicht
 *
 * Sie liest Konfiguration, sie misst nicht. Dass die Werte reichen, hat der
 * Lasttest gezeigt; dass sie gesetzt *sind* und in beiden compose-Dateien
 * übereinstimmen, hält diese Prüfung fest. Eine Grenze, die nur in einer
 * der beiden Dateien steht, ist im Betrieb keine Grenze.
 */

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$lies = static function (string $pfad) use ($root): string {
    $inhalt = file_get_contents($root . '/' . $pfad);
    if (!is_string($inhalt)) {
        throw new RuntimeException($pfad . ' ist nicht lesbar.');
    }
    return $inhalt;
};

$composeDateien = [
    'compose.yaml' => $lies('compose.yaml'),
    'deploy/registry/compose.yaml' => $lies('deploy/registry/compose.yaml'),
];

/*
 * Die Datenbankparameter. Beide Dateien, gleiche Werte.
 *
 * @var array<string,string>
 */
$datenbank = [
    // Der MyISAM-Cache wird nie angefasst; ganz abschalten geht nicht,
    // 1 MB ist der kleinste sinnvolle Wert.
    '--key-buffer-size=1M' => 'MyISAM-Cache, KEY_BLOCKS_USED = 0',
    '--aria-pagecache-buffer-size=8M' => '292 von 15.924 Blöcken belegt',
    '--innodb-buffer-pool-size=64M' => '99,99 % Trefferquote bei 11,4 MB Bestand',
    '--innodb-log-buffer-size=2M' => 'Schreiblast 0,15 Anfragen je Sekunde',
    '--max-connections=24' => 'MAX_USED_CONNECTIONS = 7 in 3,9 Tagen',
    '--thread-cache-size=8' => 'nicht mehr Fäden als Verbindungen',
    '--table-open-cache=256' => '104 Tabellen',
    '--table-definition-cache=256' => '104 Tabellen',
    '--tmp-table-size=8M' => 'je Verbindung',
    '--max-heap-table-size=8M' => 'je Verbindung',
    '--sort-buffer-size=256K' => 'je Verbindung',
    '--join-buffer-size=128K' => 'je Verbindung',
    '--read-rnd-buffer-size=128K' => 'je Verbindung',
];
foreach ($composeDateien as $name => $inhalt) {
    foreach ($datenbank as $argument => $grund) {
        $assert(
            str_contains($inhalt, $argument),
            $name . ' setzt ' . $argument . ' nicht (' . $grund . '). Eine '
                . 'Grenze, die nur in einer der beiden compose-Dateien steht, '
                . 'ist im Betrieb keine Grenze.'
        );
    }
}

/*
 * Harte Containergrenzen.
 *
 * Ohne sie ist jede Ersparnis nur eine Momentaufnahme: Ein Ausreißer im
 * einen Dienst trifft sonst das ganze Gerät statt nur sich selbst.
 */
foreach ($composeDateien as $name => $inhalt) {
    /*
     * 256m, nicht 192m.
     *
     * Nachgemessen mit dem echten Schema: Bei 24 gleichzeitigen
     * Verbindungen laeuft der Verbrauch auf 170 MB und bleibt dort. 192 MB
     * haetten 11 Prozent Reserve gelassen -- zu wenig fuer die Streuung,
     * die MariaDB ueber Tage im Speicherallokator aufbaut.
     */
    /*
     * Die Grenze steht als Vorgabewert einer Variablen, nicht als nackte
     * Zahl. Der Betrieb bekommt weiterhin 256m -- ohne gesetzte Variable
     * greift genau dieser Wert. Anheben kann sie nur, wer sie ausdruecklich
     * setzt; der einzige bekannte Fall ist die Migrationspruefung, die acht
     * Schemata gleichzeitig haelt (siehe tests/integration/ci.sh).
     *
     * Form und Vorgabewert bleiben damit gebunden: Wer die 256m aendert oder
     * die Variable entfernt, faellt hier auf.
     */
    $assert(
        preg_match(
            '~mem_limit:\s*\$\{ESTAB_DB_MEM_LIMIT:-256m\}~',
            $inhalt
        ) === 1,
        $name . ' setzt keine Speichergrenze von 256m für die Datenbank.'
    );
    $assert(
        preg_match('~mem_limit:\s*448m~', $inhalt) === 1,
        $name . ' setzt keine Speichergrenze von 448m für die Anwendung.'
    );
}

/*
 * PHP: Die teuerste Handlung -- die PDF-Ausgabe -- misst 65 MB Spitze.
 * 128 MB lassen das Doppelte an Luft; 256 MB waren eine Grenze, die auf
 * einem 1-GB-Budget keine ist.
 */
$phpKonfiguration = $lies('docker/php/estab.ini');
$assert(
    preg_match('~^memory_limit\s*=\s*128M$~m', $phpKonfiguration) === 1,
    'docker/php/estab.ini setzt memory_limit nicht auf 128M.'
);
$assert(
    preg_match('~^opcache\.memory_consumption\s*=\s*48$~m', $phpKonfiguration) === 1,
    'docker/php/estab.ini reserviert weiterhin mehr opcache als die '
        . 'gemessenen 17,6 MB brauchen.'
);
$assert(
    preg_match('~^opcache\.interned_strings_buffer\s*=\s*8$~m', $phpKonfiguration) === 1,
    'docker/php/estab.ini reserviert weiterhin mehr Zeichenkettenpuffer als '
        . 'die gemessenen 4,5 MB brauchen.'
);

/*
 * Apache: der eigentliche Deckel.
 *
 * `MaxConnectionsPerChild = 0` hiess, dass ein Arbeitsprozess seinen Heap
 * bis zum Neustart des Containers nie zurückgibt. Bei gemessenen 0,15
 * Anfragen je Sekunde tragen zwölf Prozesse auch zwanzig Arbeitsplätze im
 * 30-Sekunden-Takt.
 */
$mpm = $lies('docker/apache/mpm_prefork.conf');
foreach ([
    'MaxRequestWorkers' => '12',
    'MaxConnectionsPerChild' => '500',
    'StartServers' => '2',
    'MinSpareServers' => '2',
    'MaxSpareServers' => '4',
] as $einstellung => $wert) {
    $assert(
        preg_match(
            '~^\s*' . $einstellung . '\s+' . $wert . '\s*$~m',
            $mpm
        ) === 1,
        'docker/apache/mpm_prefork.conf setzt ' . $einstellung . ' nicht auf '
            . $wert . '.'
    );
}
$dockerfile = $lies('Dockerfile');
$assert(
    str_contains($dockerfile, 'docker/apache/mpm_prefork.conf'),
    'Das Abbild übernimmt die gedeckelte prefork-Einstellung nicht; sie läge '
        . 'im Baum, ohne im Betrieb zu gelten.'
);

/*
 * Die Rasterung von PDF-Anlagen.
 *
 * `prlimit --as` ist eine Schranke gegen bösartige Vorlagen. Bei 384 MB
 * konnte ein einziger Aufruf das ganze Gerätebudget sprengen. Gemessen
 * rendert ein Testdokument mit 20.000 gefüllten Pfaden, Transparenz und
 * Multiply-Mischmodus innerhalb von 48 MB; die Rastergröße ist durch
 * `-scale-to 2000` ohnehin fest gedeckelt.
 */
$pdf = $lies('app/incident_pdf.php');
$assert(
    str_contains($pdf, '134217728'),
    'app/incident_pdf.php lässt der Rasterung weiterhin mehr Adressraum als '
        . 'die gemessenen 48 MB brauchen.'
);
$assert(
    !str_contains($pdf, '402653184'),
    'app/incident_pdf.php trägt weiterhin die alte Schranke von 384 MB.'
);

/*
 * Das Stylesheet wird nicht bei jedem Rahmenaufbau neu uebertragen.
 *
 * Gemessen: Jede Anfrage an estab-ui.css beantwortete der Server mit 200
 * und den vollen 273 kB -- sechsmal allein waehrend der Anmeldung, einmal
 * je Ansichtswechsel. Nie eine 304. Es fehlte `Cache-Control`; ohne
 * diesen Kopf entscheidet der Browser nach eigenem Gutduenken, und in
 * parallel ladenden Rahmen entschied er sich jedes Mal fuer neu holen.
 *
 * Gesetzt ist `no-cache`, nicht `max-age`. Der Unterschied ist wichtig:
 * `no-cache` heisst "ablegen, aber vor jeder Benutzung rueckfragen". Die
 * Rueckfrage kostet einige hundert Byte und liefert 304 ohne Rumpf --
 * und anders als eine Ablaufzeit kann sie nach einer Aktualisierung kein
 * veraltetes Stylesheet ausliefern. In einer Fuehrungsstelle ist eine
 * Seite, die nach dem Einspielen anders aussieht als vorgesehen, teurer
 * als eine Rueckfrage je Aufbau.
 */
$apache = $lies('docker/apache/estab.conf');
$assert(
    preg_match(
        '~<FilesMatch[^>]*css[^>]*>.*?Cache-Control[^\n]*no-cache~s',
        $apache
    ) === 1,
    'Der Webserver setzt fuer das Stylesheet keinen Cache-Control-Kopf; es '
        . 'wird bei jedem Rahmenaufbau vollstaendig uebertragen.'
);
/*
 * Und die Rueckfrage wird auch beantwortet.
 *
 * Cache-Control allein reichte nicht. Nachgemessen im Browser: Er fragte
 * ab dem zweiten Aufruf zurueck (If-None-Match), und Apache antwortete
 * trotzdem mit 200 und dem vollen Rumpf.
 *
 * Der Grund liegt in mod_deflate: Beim Ausliefern haengt es "-gzip" an
 * das ETag, beim Vergleich der eingehenden Bedingung nimmt es aber das
 * unveraenderte. Der Vergleich schlaegt damit immer fehl. Die Rueckfrage
 * macht es dann sogar schlimmer als gar keine: Sie kostet zusaetzlich
 * Zeit und liefert doch die vollen 50 kB.
 *
 * `DeflateAlterETag NoChange` laesst das ETag unangetastet.
 */
$assert(
    preg_match('~^\s*DeflateAlterETag\s+NoChange\s*$~m', $apache) === 1,
    'Apache veraendert das ETag beim Komprimieren. Die Rueckfrage des '
        . 'Browsers trifft dann nie zu, und das Stylesheet wird bei jedem '
        . 'Rahmenaufbau vollstaendig uebertragen -- trotz Cache-Control.'
);
$assert(
    !preg_match(
        '~<FilesMatch[^>]*css[^>]*>.*?max-age=\s*[1-9]~s',
        $apache
    ),
    'Das Stylesheet bekommt eine Ablaufzeit. Nach einer Aktualisierung '
        . 'liefe der Browser bis zu dieser Zeit mit dem alten Aussehen.'
);

/*
 * Und die Anleitung sagt dasselbe wie die Konfiguration.
 *
 * Sie nannte "mindestens 2 GiB". Nach der Umstellung stimmt das nicht
 * mehr, und eine Anleitung, die mehr verlangt als noetig, haelt Leute von
 * einem Geraet fern, auf dem es laufen wuerde.
 */
$anleitung = $lies('docs/INSTALLATION.md');
$assert(
    !str_contains($anleitung, 'mindestens 2 GiB'),
    'docs/INSTALLATION.md verlangt weiterhin mindestens 2 GiB.'
);
$assert(
    str_contains($anleitung, '256 MiB') && str_contains($anleitung, '448 MiB'),
    'docs/INSTALLATION.md nennt die gesetzten Containergrenzen nicht.'
);

printf(
    "Betriebsmittel: OK (%d assertions, %d Datenbankparameter in %d "
        . "compose-Dateien)\n",
    $assertions,
    count($datenbank),
    count($composeDateien)
);
