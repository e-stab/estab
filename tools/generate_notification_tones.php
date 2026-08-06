<?php

declare(strict_types=1);

const ESTAB_TONE_SAMPLE_RATE = 22050;
const ESTAB_TONE_AMPLITUDE = 18000;
const ESTAB_TONE_FADE_MILLISECONDS = 12;

/**
 * Notification melodies expressed as frequency/duration pairs.
 * A frequency of zero produces silence.
 *
 * @return array<string, list<array{frequency:int, milliseconds:int}>>
 */
function estab_notification_tone_patterns(): array
{
    return [
        'notify_aw.wav' => [
            ['frequency' => 880, 'milliseconds' => 260],
            ['frequency' => 0, 'milliseconds' => 100],
            ['frequency' => 880, 'milliseconds' => 260],
            ['frequency' => 0, 'milliseconds' => 420],
        ],
        'notify_si.wav' => [
            ['frequency' => 660, 'milliseconds' => 220],
            ['frequency' => 0, 'milliseconds' => 60],
            ['frequency' => 880, 'milliseconds' => 280],
            ['frequency' => 0, 'milliseconds' => 480],
        ],
        'notify_stab.wav' => [
            ['frequency' => 440, 'milliseconds' => 200],
            ['frequency' => 0, 'milliseconds' => 70],
            ['frequency' => 523, 'milliseconds' => 200],
            ['frequency' => 0, 'milliseconds' => 70],
            ['frequency' => 659, 'milliseconds' => 250],
            ['frequency' => 0, 'milliseconds' => 300],
        ],
    ];
}

function estab_triangle_sample(int $sampleIndex, int $frequency): int
{
    $phase = ($sampleIndex * $frequency) % ESTAB_TONE_SAMPLE_RATE;
    $quarter = intdiv(ESTAB_TONE_SAMPLE_RATE, 4);
    $threeQuarters = ESTAB_TONE_SAMPLE_RATE - $quarter;
    $scaled = intdiv($phase * 4 * ESTAB_TONE_AMPLITUDE, ESTAB_TONE_SAMPLE_RATE);

    if ($phase < $quarter) {
        return $scaled;
    }
    if ($phase < $threeQuarters) {
        return (2 * ESTAB_TONE_AMPLITUDE) - $scaled;
    }
    return $scaled - (4 * ESTAB_TONE_AMPLITUDE);
}

/** @param list<array{frequency:int, milliseconds:int}> $pattern */
function estab_generate_notification_tone(array $pattern): string
{
    $data = '';
    $fadeSamples = intdiv(
        ESTAB_TONE_SAMPLE_RATE * ESTAB_TONE_FADE_MILLISECONDS,
        1000
    );

    foreach ($pattern as $segment) {
        $frequency = $segment['frequency'];
        $sampleCount = intdiv(
            ESTAB_TONE_SAMPLE_RATE * $segment['milliseconds'],
            1000
        );
        for ($sampleIndex = 0; $sampleIndex < $sampleCount; $sampleIndex++) {
            $sample = $frequency === 0
                ? 0
                : estab_triangle_sample($sampleIndex, $frequency);
            if ($sample !== 0) {
                $envelope = min(
                    $fadeSamples,
                    $sampleIndex,
                    $sampleCount - $sampleIndex - 1
                );
                $sample = intdiv($sample * $envelope, $fadeSamples);
            }
            $data .= pack('v', $sample & 0xffff);
        }
    }

    $dataSize = strlen($data);
    $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE';
    $header .= 'fmt ' . pack(
        'VvvVVvv',
        16,
        1,
        1,
        ESTAB_TONE_SAMPLE_RATE,
        ESTAB_TONE_SAMPLE_RATE * 2,
        2,
        16
    );
    $header .= 'data' . pack('V', $dataSize);

    return $header . $data;
}

function estab_tone_target_directory(): string
{
    return dirname(__DIR__) . '/4fach/audio';
}

function estab_write_notification_tones(): void
{
    $targetDirectory = estab_tone_target_directory();
    foreach (estab_notification_tone_patterns() as $fileName => $pattern) {
        $bytes = estab_generate_notification_tone($pattern);
        $target = $targetDirectory . '/' . $fileName;
        if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Could not write ' . $target);
        }
        printf("generated %s  sha256=%s\n", $fileName, hash('sha256', $bytes));
    }
}

function estab_verify_notification_tones(): void
{
    $targetDirectory = estab_tone_target_directory();
    foreach (estab_notification_tone_patterns() as $fileName => $pattern) {
        $expected = estab_generate_notification_tone($pattern);
        $target = $targetDirectory . '/' . $fileName;
        $actual = file_get_contents($target);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException(
                $fileName . ' is not the reproducible output of this generator'
            );
        }
        printf("verified %s  sha256=%s\n", $fileName, hash('sha256', $actual));
    }
}

$mode = $argv[1] ?? '';
try {
    if ($mode === '--write') {
        estab_write_notification_tones();
    } elseif ($mode === '--verify') {
        estab_verify_notification_tones();
    } else {
        fwrite(
            STDERR,
            "Usage: php tools/generate_notification_tones.php --write|--verify\n"
        );
        exit(2);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
