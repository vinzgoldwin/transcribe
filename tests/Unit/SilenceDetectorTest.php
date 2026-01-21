<?php

use App\Services\Transcription\SilenceDetector;

it('parses silencedetect output and filters by duration', function () {
    $output = implode("\n", [
        '[silencedetect @ 0x123] silence_start: 12.345',
        '[silencedetect @ 0x123] silence_end: 12.700 | silence_duration: 0.355',
        '[silencedetect @ 0x123] silence_start: 24.000',
        '[silencedetect @ 0x123] silence_end: 25.050 | silence_duration: 1.050',
    ]);

    $detector = new SilenceDetector;
    $intervals = $detector->parse($output, 0.6);

    expect($intervals)->toHaveCount(1)
        ->and($intervals[0]['start'])->toBe(24.0)
        ->and($intervals[0]['end'])->toBe(25.05)
        ->and($intervals[0]['duration'])->toBe(1.05);
});
