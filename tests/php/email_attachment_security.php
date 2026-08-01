<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/email_attachment.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertError = static function (
    array $result,
    string $code,
    string $message
) use ($assert): void {
    $assert(
        ($result['ok'] ?? null) === false
            && ($result['error_code'] ?? null) === $code
            && is_string($result['error'] ?? null)
            && $result['error'] !== ''
            && ($result['body'] ?? null) === ''
            && ($result['attachments'] ?? null) === [],
        $message
    );
};

$fixturePath = __DIR__ . '/../fixtures/email-multipart-xss-utf8.eml';
$fixtureBytes = file_get_contents($fixturePath);
$assert(is_string($fixtureBytes), 'the canonical EML acceptance fixture is unreadable');
$assert(
    (new finfo(FILEINFO_MIME_TYPE))->buffer($fixtureBytes) === 'message/rfc822',
    'the canonical EML fixture is not detected as message/rfc822'
);
$fixtureResult = estab_email_attachment_parse($fixtureBytes);
$assert(
    $fixtureResult['ok'] === true
        && $fixtureResult['headers']['from']
            === 'Erika Müller <erika.mueller@example.test>'
        && $fixtureResult['headers']['to']
            === 'Führungsstelle Göppingen <fuehrungsstelle@example.test>'
        && $fixtureResult['headers']['subject']
            === 'Lage <Übung> – Grüße'
        && $fixtureResult['headers_html']['subject']
            === 'Lage &lt;Übung&gt; – Grüße',
    'the canonical EML fixture loses decoded or escaped headers'
);
$assert(
    $fixtureResult['body_source'] === 'html'
        && str_contains($fixtureResult['body'], 'E-Mail-Lagemeldung')
        && str_contains(
            $fixtureResult['body'],
            'Gefahr & Rückmeldung aus der Übung.'
        )
        && str_contains($fixtureResult['body'], 'Rückfrage')
        && !str_contains($fixtureResult['body'], 'window.__estabEmailXss')
        && !str_contains($fixtureResult['body'], 'evil.invalid'),
    'the canonical HTML-only email is not converted into safe useful text'
);
$assert(
    $fixtureResult['attachments'] === [
        [
            'filename' => 'Lage-Übung.png',
            'content_type' => 'image/png',
            'size' => 68,
        ],
        [
            'filename' => 'Notiz-Übung.txt',
            'content_type' => 'text/plain',
            'size' => 18,
        ],
    ],
    'the canonical email attachment metadata is incomplete or inaccurate'
);
$assert(
    !str_contains(strtolower($fixtureResult['body_html']), '<script')
        && !str_contains(strtolower($fixtureResult['body_html']), '<img')
        && !str_contains(strtolower($fixtureResult['body_html']), '<iframe')
        && !str_contains(strtolower($fixtureResult['body_html']), 'onerror')
        && !str_contains(strtolower($fixtureResult['body_html']), 'javascript:')
        && !str_contains(strtolower($fixtureResult['body_html']), 'evil.invalid'),
    'active or remote content survives the canonical email presentation output'
);

$plain = implode("\r\n", [
    'From: =?UTF-8?Q?J=C3=B6rg_Einsatz?= <joerg@example.test>',
    'To: fuehrungsstelle@example.test',
    'To: zweite@example.test',
    'Cc: lage@example.test',
    'Date: Sat, 1 Aug 2026 13:45:00 +0200',
    'Subject: =?UTF-8?Q?Einsatz_=C3=9C?=',
    ' =?UTF-8?B?YmVyZ2FiZQ==?=',
    'Content-Type: text/plain; charset="UTF-8"',
    'Content-Transfer-Encoding: quoted-printable',
    '',
    "Erste Zeile=0A=0DZweite Zeile mit Umlaut: =C3=A4",
]);
$result = estab_email_attachment_parse($plain);
$assert($result['ok'] === true, 'a regular plain-text email is rejected');
$assert(
    $result['headers'] === [
        'from' => 'Jörg Einsatz <joerg@example.test>',
        'to' => 'fuehrungsstelle@example.test, zweite@example.test',
        'cc' => 'lage@example.test',
        'date' => 'Sat, 1 Aug 2026 13:45:00 +0200',
        'subject' => 'Einsatz Übergabe',
    ],
    'folded or RFC-2047 headers are not decoded safely'
);
$assert(
    $result['body_source'] === 'plain'
        && $result['body'] === "Erste Zeile\n\nZweite Zeile mit Umlaut: ä",
    'quoted-printable UTF-8 plain text is not decoded'
);
$assert(
    $result['headers_html']['from'] === 'Jörg Einsatz &lt;joerg@example.test&gt;'
        && str_contains($result['body_html'], '<br>'),
    'the explicit HTML presentation fields are not safely escaped'
);

$nested = implode("\r\n", [
    'From: Leitstelle <leitstelle@example.test>',
    'To: FueSt <fuest@example.test>',
    'Subject: Lagebild',
    'Content-Type: multipart/related; boundary="outer-boundary"',
    '',
    'Preamble must be ignored.',
    '--outer-boundary',
    'Content-Type: multipart/alternative; boundary=alternative-boundary',
    '',
    '--alternative-boundary',
    'Content-Type: text/html; charset=UTF-8',
    '',
    '<p>HTML-Ausweichtext</p>',
    '--alternative-boundary',
    'Content-Type: text/plain; charset=UTF-8',
    '',
    'Bevorzugter Klartext',
    '--alternative-boundary--',
    '--outer-boundary',
    'Content-Type: image/png; name="lage.png"',
    'Content-Disposition: inline; filename="lage.png"',
    'Content-Transfer-Encoding: base64',
    '',
    'iVBORw0KGgo=',
    '--outer-boundary',
    "Content-Type: application/pdf; name*=UTF-8''Lageplan%20S%C3%BCd.pdf",
    "Content-Disposition: attachment; filename*0*=UTF-8''Lageplan%20; filename*1*=S%C3%BCd.pdf",
    'Content-Transfer-Encoding: base64',
    '',
    'JVBERg==',
    '--outer-boundary--',
    'Epilogue must be ignored.',
]);
$nestedResult = estab_email_attachment_parse($nested);
$assert($nestedResult['ok'] === true, 'a nested multipart message is rejected');
$assert(
    $nestedResult['body_source'] === 'plain'
        && $nestedResult['body'] === 'Bevorzugter Klartext',
    'text/plain is not preferred across nested multipart/alternative data'
);
$assert(
    $nestedResult['attachments'] === [
        ['filename' => 'lage.png', 'content_type' => 'image/png', 'size' => 8],
        ['filename' => 'Lageplan Süd.pdf', 'content_type' => 'application/pdf', 'size' => 4],
    ],
    'inline files or RFC-2231 filenames are not represented as metadata'
);
$assert(
    array_keys($nestedResult['attachments'][0]) === ['filename', 'content_type', 'size']
        && !str_contains(serialize($nestedResult['attachments']), 'PNG'),
    'decoded attachment payload leaked into the parser result'
);

$hostileHtml = implode("\r\n", [
    'From: <img src=x onerror=alert(1)>',
    'Subject: <script>alert(2)</script>',
    'Content-Type: text/html; charset=UTF-8',
    '',
    '<head><style>body{display:none}</style></head>',
    '<p>Alarm &amp; Lage</p><img src="https://attacker.invalid/pixel">',
    '<script>fetch("https://attacker.invalid")</script>',
    '<a href="javascript:alert(3)">Öffnen</a><br><b>Ende</b>',
]);
$hostileResult = estab_email_attachment_parse($hostileHtml);
$assert(
    $hostileResult['ok'] === true
        && $hostileResult['body_source'] === 'html'
        && str_contains($hostileResult['body'], 'Alarm & Lage')
        && str_contains($hostileResult['body'], 'Öffnen')
        && str_contains($hostileResult['body'], 'Ende')
        && !str_contains($hostileResult['body'], 'fetch('),
    'HTML-only mail is not converted into useful inert text'
);
$assert(
    !str_contains(strtolower($hostileResult['body_html']), '<script')
        && !str_contains(strtolower($hostileResult['body_html']), '<img')
        && !str_contains(strtolower($hostileResult['body_html']), 'javascript:')
        && str_contains($hostileResult['headers_html']['from'], '&lt;img'),
    'active email-controlled HTML reaches a web presentation field'
);
$hostilePlainResult = estab_email_attachment_parse(
    "Subject: plain\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
        . '<img src=x onerror=alert(1)>'
);
$assert(
    $hostilePlainResult['body'] === '<img src=x onerror=alert(1)>'
        && $hostilePlainResult['body_html'] === '&lt;img src=x onerror=alert(1)&gt;',
    'plain email text is exposed as active HTML instead of escaped presentation text'
);

$spacedBoundary = implode("\r\n", [
    'Content-Type: multipart/alternative; boundary="valid boundary"', '',
    '--valid boundary', 'Content-Type: text/plain', '', 'RFC boundary',
    '--valid boundary--',
]);
$spacedBoundaryResult = estab_email_attachment_parse($spacedBoundary);
$assert(
    $spacedBoundaryResult['ok'] === true
        && $spacedBoundaryResult['body'] === 'RFC boundary',
    'a valid quoted RFC-2046 boundary containing a space is rejected'
);

$latin1 = "From: Test <test@example.test>\r\n"
    . "Subject: =?ISO-8859-1?Q?Gr=FC=DFe?=\r\n"
    . "Content-Type: text/plain; charset=ISO-8859-1\r\n\r\n"
    . "Stra\xDFe";
$latinResult = estab_email_attachment_parse($latin1);
$assert(
    $latinResult['headers']['subject'] === 'Grüße'
        && $latinResult['body'] === 'Straße',
    'common legacy email charsets are not converted to UTF-8'
);

$attachedMessage = implode("\r\n", [
    'Subject: Weiterleitung',
    'Content-Type: multipart/mixed; boundary=x',
    '',
    '--x',
    'Content-Type: text/plain',
    '',
    'Begleittext',
    '--x',
    'Content-Type: message/rfc822',
    'Content-Transfer-Encoding: 8bit',
    '',
    'From: fremd@example.test',
    'Subject: Fremde Nachricht',
    '',
    'Nicht als Haupttext darstellen.',
    '--x--',
]);
$attachedResult = estab_email_attachment_parse($attachedMessage);
$assert(
    $attachedResult['ok'] === true
        && $attachedResult['body'] === 'Begleittext'
        && $attachedResult['attachments'][0]['filename'] === 'Unbenannte Anlage 1.eml'
        && $attachedResult['attachments'][0]['content_type'] === 'message/rfc822'
        && $attachedResult['attachments'][0]['size'] > 20,
    'an enclosed RFC-822 message is mistaken for the visible parent message'
);

$unsafeFilename = implode("\r\n", [
    'Subject: Anlage',
    'Content-Type: multipart/mixed; boundary=z',
    '',
    '--z',
    'Content-Type: text/plain',
    '',
    'Text',
    '--z',
    'Content-Type: application/octet-stream',
    'Content-Disposition: attachment; filename="..\\..\\=?UTF-8?Q?Pr=C3=BCfung=3B_1.bin?="',
    'Content-Transfer-Encoding: base64',
    '',
    'AQID',
    '--z--',
]);
$unsafeResult = estab_email_attachment_parse($unsafeFilename);
$assert(
    $unsafeResult['ok'] === true
        && $unsafeResult['attachments'][0] === [
            'filename' => 'Prüfung; 1.bin',
            'content_type' => 'application/octet-stream',
            'size' => 3,
        ],
    'attachment filenames are not decoded and reduced to a safe display basename'
);

$bidiFilename = implode("\r\n", [
    'Content-Type: multipart/mixed; boundary=bidi', '',
    '--bidi', 'Content-Type: text/plain', '', 'Text',
    '--bidi', 'Content-Type: application/octet-stream',
    'Content-Disposition: attachment; filename="=?UTF-8?Q?Bild=E2=80=AEgpj.exe?="',
    'Content-Transfer-Encoding: base64', '', 'AA==',
    '--bidi--',
]);
$bidiResult = estab_email_attachment_parse($bidiFilename);
$assert(
    $bidiResult['ok'] === true
        && $bidiResult['attachments'][0]['filename'] === 'Bild gpj.exe',
    'a bidirectional Unicode control survives in an attachment display name'
);

$malformedHeader = " Broken continuation\r\nInvalid line\r\nSubject: Okay\r\n\r\nBody";
$malformedHeaderResult = estab_email_attachment_parse($malformedHeader);
$assert(
    $malformedHeaderResult['ok'] === true
        && $malformedHeaderResult['headers']['subject'] === 'Okay'
        && count($malformedHeaderResult['warnings']) >= 2,
    'recoverable malformed header lines do not produce bounded warnings'
);

$assertError(
    estab_email_attachment_parse(''),
    'empty_message',
    'an empty email has no clear error state'
);
$assertError(
    estab_email_attachment_parse('Subject: no separator'),
    'malformed_message',
    'an email without a header/body separator is accepted'
);
$assertError(
    estab_email_attachment_parse(
        "Subject: large\r\n\r\n0123456789",
        ['input_bytes' => 20]
    ),
    'message_too_large',
    'the total input-size limit is not enforced'
);
$assertError(
    estab_email_attachment_parse(
        "Subject: oversized header\r\n\r\ntext",
        ['header_bytes' => 8]
    ),
    'headers_too_large',
    'the per-entity header-size limit is not enforced'
);
$assertError(
    estab_email_attachment_parse(
        "Subject: b64\r\nContent-Transfer-Encoding: base64\r\n\r\n%%%",
    ),
    'invalid_transfer_encoding',
    'invalid strict base64 is accepted'
);
$assertError(
    estab_email_attachment_parse(
        "Subject: rot\r\nContent-Transfer-Encoding: x-rot13\r\n\r\nabc",
    ),
    'unsupported_transfer_encoding',
    'an unsupported transfer encoding is accepted'
);
$assertError(
    estab_email_attachment_parse(
        "Subject: no boundary\r\nContent-Type: multipart/mixed\r\n\r\ntext"
    ),
    'mime_boundary_missing',
    'multipart data without a boundary is accepted'
);
$assertError(
    estab_email_attachment_parse(
        "Subject: bad boundary\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n--wrong--"
    ),
    'mime_boundary_invalid',
    'multipart data without matching delimiters is accepted'
);
$assertError(
    estab_email_attachment_parse(implode("\r\n", [
        'Content-Type: multipart/mixed; boundary=x', '',
        '--x', 'Content-Type: text/plain', '', 'first',
        '--x', 'Content-Type: text/plain', '', 'unterminated',
    ])),
    'mime_boundary_invalid',
    'an unterminated multipart body is partially accepted'
);

$threeParts = implode("\r\n", [
    'Content-Type: multipart/mixed; boundary=p', '',
    '--p', 'Content-Type: text/plain', '', 'one',
    '--p', 'Content-Type: text/plain', '', 'two',
    '--p--',
]);
$assertError(
    estab_email_attachment_parse($threeParts, ['parts' => 2]),
    'mime_parts_exceeded',
    'the recursive MIME-part count is not bounded'
);
$deep = implode("\r\n", [
    'Content-Type: multipart/mixed; boundary=a', '',
    '--a', 'Content-Type: multipart/mixed; boundary=b', '',
    '--b', 'Content-Type: text/plain', '', 'deep', '--b--',
    '--a--',
]);
$assertError(
    estab_email_attachment_parse($deep, ['depth' => 1]),
    'mime_depth_exceeded',
    'the recursive MIME-depth limit is not enforced'
);
$assertError(
    estab_email_attachment_parse(
        "Content-Type: text/plain\r\n\r\n123456",
        ['decoded_bytes' => 5]
    ),
    'decoded_data_too_large',
    'the aggregate decoded-byte limit is not enforced'
);
$truncated = estab_email_attachment_parse(
    "Content-Type: text/plain; charset=UTF-8\r\n\r\n123456789",
    ['body_bytes' => 5]
);
$assert(
    $truncated['ok'] === true
        && $truncated['body'] === "12345\n…"
        && $truncated['warnings'] !== [],
    'oversized visible body text is not safely truncated and reported'
);

$stream = fopen('php://temp', 'w+b');
$assert(is_resource($stream), 'temporary test stream is unavailable');
fwrite($stream, $plain);
rewind($stream);
$streamResult = estab_email_attachment_parse_stream($stream);
fclose($stream);
$assert(
    $streamResult['ok'] === true
        && $streamResult['headers']['subject'] === 'Einsatz Übergabe',
    'the bounded stream parser changes valid parser output'
);
$largeStream = fopen('php://temp', 'w+b');
$assert(is_resource($largeStream), 'large temporary test stream is unavailable');
fwrite($largeStream, str_repeat('x', 64));
rewind($largeStream);
$assertError(
    estab_email_attachment_parse_stream($largeStream, ['input_bytes' => 32]),
    'message_too_large',
    'the stream reader does not stop at its configured byte limit'
);
fclose($largeStream);

$invalidStreamRejected = false;
try {
    estab_email_attachment_parse_stream('not-a-stream');
} catch (InvalidArgumentException) {
    $invalidStreamRejected = true;
}
$assert($invalidStreamRejected, 'a non-stream is accepted by the stream API');
$invalidLimitRejected = false;
try {
    estab_email_attachment_parse($plain, ['parts' => 0]);
} catch (InvalidArgumentException) {
    $invalidLimitRejected = true;
}
$assert($invalidLimitRejected, 'invalid parser limit configuration is silently accepted');

fwrite(STDOUT, "Email attachment security: OK ({$assertions} assertions)\n");
