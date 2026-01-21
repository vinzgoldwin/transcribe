<?php

use App\Services\Transcription\SubtitleFormatter;

it('formats subtitles within line, duration, and speed limits', function () {
    $formatter = new SubtitleFormatter(42, 2, 1.0, 6.0, 17.0, 0.05);

    $segments = [
        [
            'start' => 0.0,
            'end' => 2.0,
            'text' => str_repeat('Readable subtitle text ', 6),
            'text_jp' => 'dummy',
        ],
    ];

    $formatted = $formatter->format($segments);

    expect($formatted)->not->toBeEmpty();

    foreach ($formatted as $segment) {
        $duration = $segment['end'] - $segment['start'];
        $lines = explode("\n", $segment['formatted_text']);

        expect(count($lines))->toBeLessThanOrEqual(2)
            ->and($duration)->toBeGreaterThanOrEqual(1.0)
            ->and($duration)->toBeLessThanOrEqual(6.0);

        foreach ($lines as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual(42);
        }

        $charsPerSecond = mb_strlen($segment['text']) / max(0.01, $duration);
        expect($charsPerSecond)->toBeLessThanOrEqual(17.0);
    }
});
