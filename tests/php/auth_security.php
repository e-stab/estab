<?php

require_once __DIR__ . '/../../app/auth.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$confEmpf = [
    1 => ['fkt' => 'S1', 'rolle' => 'Stab'],
    2 => ['fkt' => 'Si', 'rolle' => 'Stab'],
    3 => ['fkt' => 'A/W', 'rolle' => 'Fernmelder'],
    4 => ['fkt' => 'THW', 'rolle' => 'FB'],
];

$valid = estab_auth_validate_login([
    'benutzer' => '  Müller, Ada  ',
    'kuerzel' => ' ADA ',
    'funktion' => 'S1',
    'kennwort1' => 'legacy-compatible',
], $confEmpf);
$assert($valid['valid'] === true, 'valid identity accepted');
$assert($valid['data']['benutzer'] === 'Müller, Ada', 'name trimmed');
$assert($valid['data']['kuerzel'] === 'ada', 'code normalised');
$assert($valid['data']['rolle'] === 'Stab', 'role derived from configuration');

$unknownFunction = estab_auth_validate_login([
    'benutzer' => 'Ada Müller',
    'kuerzel' => 'ada',
    'funktion' => 'ROOT',
    'kennwort1' => 'secret',
], $confEmpf);
$assert($unknownFunction['valid'] === false, 'unknown function rejected');
$assert(in_array('funktion', $unknownFunction['errors'], true), 'function error identified');

$invalidIdentity = estab_auth_validate_login([
    'benutzer' => "Ada\nAdmin",
    'kuerzel' => '../ada',
    'funktion' => 'S1',
    'kennwort1' => "bad\0password",
], $confEmpf);
$assert($invalidIdentity['valid'] === false, 'control characters and unsafe code rejected');
$assert(in_array('benutzer', $invalidIdentity['errors'], true), 'name error identified');
$assert(in_array('kuerzel', $invalidIdentity['errors'], true), 'code error identified');
$assert(in_array('kennwort1', $invalidIdentity['errors'], true), 'password NUL rejected');

$legacy = estab_auth_verify_password('old-secret', 'old-secret');
$assert($legacy['valid'] === true, 'legacy plaintext accepted once');
$assert($legacy['migrated'] === true, 'legacy plaintext requests migration');
$assert(is_string($legacy['replacement']), 'migration produces a hash');
$assert(password_verify('old-secret', $legacy['replacement']), 'migration hash verifies');
$assert($legacy['replacement'] !== 'old-secret', 'plaintext is not retained');

$legacyWrong = estab_auth_verify_password('wrong', 'old-secret');
$assert($legacyWrong['valid'] === false, 'wrong legacy password rejected');
$assert($legacyWrong['replacement'] === null, 'failed password has no migration hash');

$modernHash = password_hash('modern-secret', PASSWORD_DEFAULT);
$modern = estab_auth_verify_password('modern-secret', $modernHash);
$assert($modern['valid'] === true, 'modern hash verifies');
$assert($modern['replacement'] === null, 'current modern hash is retained');
$assert(estab_auth_verify_password('wrong', $modernHash)['valid'] === false, 'wrong modern password rejected');

$assert(estab_parse_bool('YES', false) === true, 'safe true boolean parsed');
$assert(estab_parse_bool('off', true) === false, 'safe false boolean parsed');
$invalidBooleanRejected = false;
try {
    estab_parse_bool('truthy typo', true);
} catch (InvalidArgumentException) {
    $invalidBooleanRejected = true;
}
$assert($invalidBooleanRejected, 'unknown boolean rejected');

$assert(estab_auth_remote_ip(['REMOTE_ADDR' => '2001:db8::1']) === '2001:db8::1', 'IPv6 peer accepted');
$assert(estab_auth_remote_ip(['REMOTE_ADDR' => 'not-an-ip']) === '', 'invalid peer rejected');
$forwarded = ['HTTP_X_FORWARDED_FOR' => '198.51.100.7, 2001:db8::2'];
$assert(estab_auth_forwarded_ip($forwarded, false) === '', 'proxy header ignored by default');
$assert(estab_auth_forwarded_ip($forwarded, true) === '198.51.100.7', 'validated trusted proxy chain accepted');
$assert(
    estab_auth_forwarded_ip(['HTTP_X_FORWARDED_FOR' => '198.51.100.7, injected'], true) === '',
    'invalid proxy chain rejected in full'
);

$token = estab_auth_identity_token([
    'benutzer' => 'Müller, Ada',
    'kuerzel' => 'ada',
    'funktion' => 'S1',
]);
$decoded = estab_auth_decode_identity_token($token, $confEmpf);
$assert($decoded === ['benutzer' => 'Müller, Ada', 'kuerzel' => 'ada', 'funktion' => 'S1'], 'POST prefill token round trip');
$assert(estab_auth_decode_identity_token('not+base64', $confEmpf) === null, 'malformed prefill token rejected');

$loginController = file_get_contents(dirname(__DIR__, 2) . '/4fach/data_hndl.php');
$assert(is_string($loginController), 'login controller source readable');
$assert(
    substr_count($loginController, 'if ($login ["funktion"] != "A/W")') >= 2
        && !str_contains($loginController, '$wasInactive && $login ["funktion"] != "A/W"'),
    'active imported users do not reconcile their legacy dynamic tables on login'
);

echo "authentication security: OK ({$assertions} assertions)\n";
