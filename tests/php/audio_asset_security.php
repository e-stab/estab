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

/**
 * Parse the parts of a PCM WAV file needed for the notification-sound gate.
 *
 * @return array{
 *     format:int,
 *     channels:int,
 *     sample_rate:int,
 *     bits_per_sample:int,
 *     frames:int,
 *     duration:float,
 *     peak:int,
 *     rms:float
 * }
 */
$readPcmWave = static function (string $path) use ($assert): array {
    $bytes = file_get_contents($path);
    $assert(is_string($bytes), 'Could not read notification sound ' . $path);
    if (!is_string($bytes)) {
        throw new RuntimeException('Could not read notification sound ' . $path);
    }

    $length = strlen($bytes);
    $assert($length >= 44, 'Notification sound is shorter than a WAV header: ' . $path);
    $assert(substr($bytes, 0, 4) === 'RIFF', 'Notification sound is not RIFF: ' . $path);
    $assert(substr($bytes, 8, 4) === 'WAVE', 'Notification sound is not WAVE: ' . $path);

    $riffSize = unpack('Vsize', substr($bytes, 4, 4));
    $assert(
        is_array($riffSize)
        && ($riffSize['size'] ?? null) === $length - 8,
        'Notification sound RIFF size is inconsistent: ' . $path
    );

    $formatChunk = null;
    $dataChunk = null;
    $offset = 12;
    while ($offset + 8 <= $length) {
        $chunkName = substr($bytes, $offset, 4);
        $chunkSizeValue = unpack('Vsize', substr($bytes, $offset + 4, 4));
        if (!is_array($chunkSizeValue)) {
            throw new RuntimeException('Could not parse WAV chunk size: ' . $path);
        }
        $chunkSize = (int) $chunkSizeValue['size'];
        $chunkStart = $offset + 8;
        $chunkEnd = $chunkStart + $chunkSize;
        $assert($chunkEnd <= $length, 'WAV chunk exceeds the file boundary: ' . $path);
        if ($chunkEnd > $length) {
            throw new RuntimeException('WAV chunk exceeds the file boundary: ' . $path);
        }

        if ($chunkName === 'fmt ' && $formatChunk === null) {
            $formatChunk = substr($bytes, $chunkStart, $chunkSize);
        } elseif ($chunkName === 'data' && $dataChunk === null) {
            $dataChunk = substr($bytes, $chunkStart, $chunkSize);
        }
        $offset = $chunkEnd + ($chunkSize % 2);
    }

    $assert(
        is_string($formatChunk) && strlen($formatChunk) >= 16,
        'Notification sound has no complete fmt chunk: ' . $path
    );
    $assert(
        is_string($dataChunk) && $dataChunk !== '',
        'Notification sound has no audio data: ' . $path
    );
    if (!is_string($formatChunk) || !is_string($dataChunk)) {
        throw new RuntimeException('Notification sound has incomplete WAV chunks: ' . $path);
    }

    $format = unpack(
        'vformat/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits_per_sample',
        substr($formatChunk, 0, 16)
    );
    if (!is_array($format)) {
        throw new RuntimeException('Could not parse WAV format: ' . $path);
    }

    $audioFormat = (int) $format['format'];
    $channels = (int) $format['channels'];
    $sampleRate = (int) $format['sample_rate'];
    $byteRate = (int) $format['byte_rate'];
    $blockAlign = (int) $format['block_align'];
    $bitsPerSample = (int) $format['bits_per_sample'];

    $assert($audioFormat === 1, 'Notification sound is not uncompressed PCM: ' . $path);
    $assert(
        in_array($channels, [1, 2], true),
        'Notification sound has an unsupported channel count: ' . $path
    );
    $assert(
        in_array($sampleRate, [22050, 44100], true),
        'Notification sound has an unexpected sample rate: ' . $path
    );
    $assert($bitsPerSample === 16, 'Notification sound is not PCM-16: ' . $path);

    $expectedBlockAlign = $channels * intdiv($bitsPerSample, 8);
    $assert(
        $blockAlign === $expectedBlockAlign,
        'Notification sound block alignment is inconsistent: ' . $path
    );
    $assert(
        $byteRate === $sampleRate * $blockAlign,
        'Notification sound byte rate is inconsistent: ' . $path
    );
    $dataLength = strlen($dataChunk);
    $assert(
        $blockAlign > 0 && $dataLength % $blockAlign === 0,
        'Notification sound data is not frame-aligned: ' . $path
    );

    $sampleCount = intdiv($dataLength, 2);
    $sumSquares = 0.0;
    $peak = 0;
    for ($sampleOffset = 0; $sampleOffset < $dataLength; $sampleOffset += 2) {
        $sample = ord($dataChunk[$sampleOffset])
            | (ord($dataChunk[$sampleOffset + 1]) << 8);
        if ($sample >= 0x8000) {
            $sample -= 0x10000;
        }
        $absolute = abs($sample);
        $peak = max($peak, $absolute);
        $sumSquares += $sample * $sample;
    }

    $frames = intdiv($dataLength, $blockAlign);
    return [
        'format' => $audioFormat,
        'channels' => $channels,
        'sample_rate' => $sampleRate,
        'bits_per_sample' => $bitsPerSample,
        'frames' => $frames,
        'duration' => $frames / $sampleRate,
        'peak' => $peak,
        'rms' => sqrt($sumSquares / $sampleCount),
    ];
};

$expectedSounds = [
    'notify_aw.wav' => [
        'sha256' => '448b4d0ac84265363194ca4ea77e093549da6536625d14ed778136d2c863a7b7',
        'channels' => 1,
        'sample_rate' => 22050,
        'frames' => 22932,
    ],
    'notify_si.wav' => [
        'sha256' => 'ca5a29a14e425f464513c47cac98ac2bfc7b019050f34b4ee32da12020b0019f',
        'channels' => 1,
        'sample_rate' => 22050,
        'frames' => 22932,
    ],
    'notify_stab.wav' => [
        'sha256' => 'beac1b45c2fe2dd2ad239e728b08312ad152454f007750a4da2b4d8c98ce90d3',
        'channels' => 1,
        'sample_rate' => 22050,
        'frames' => 24033,
    ],
];

foreach ($expectedSounds as $fileName => $expected) {
    $path = $root . '/4fach/audio/' . $fileName;
    $digest = hash_file('sha256', $path);
    $assert(
        is_string($digest) && hash_equals($expected['sha256'], $digest),
        'Notification sound bytes changed unexpectedly: ' . $fileName
    );

    $wave = $readPcmWave($path);
    $assert(
        $wave['channels'] === $expected['channels'],
        'Notification sound channel count changed: ' . $fileName
    );
    $assert(
        $wave['sample_rate'] === $expected['sample_rate'],
        'Notification sound sample rate changed: ' . $fileName
    );
    $assert(
        $wave['frames'] === $expected['frames'],
        'Notification sound frame count changed: ' . $fileName
    );
    $assert(
        $wave['duration'] >= 1.0 && $wave['duration'] <= 6.0,
        'Notification sound duration is not operationally useful: ' . $fileName
    );
    $assert(
        $wave['peak'] >= 4096,
        'Notification sound has no meaningful signal peak: ' . $fileName
    );
    $assert(
        $wave['rms'] >= 1000.0,
        'Notification sound is silent or nearly silent: ' . $fileName
    );
}

printf("audio asset security: OK (%d assertions)\n", $assertions);
