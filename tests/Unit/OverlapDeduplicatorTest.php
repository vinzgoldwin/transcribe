<?php

use App\Services\Transcription\OverlapDeduplicator;
use Illuminate\Support\Collection;

it('removes duplicated overlap segments and trims remaining overlaps', function () {
    $segments = new Collection([
        [
            'id' => 1,
            'start_seconds' => 0.0,
            'end_seconds' => 2.0,
            'text_en' => 'Hello world',
            'text_jp' => 'konnichiwa sekai',
            'formatted_text' => 'Hello world',
        ],
        [
            'id' => 2,
            'start_seconds' => 1.6,
            'end_seconds' => 3.0,
            'text_en' => 'Hello world',
            'text_jp' => 'konnichiwa sekai',
            'formatted_text' => 'Hello world',
        ],
        [
            'id' => 3,
            'start_seconds' => 2.8,
            'end_seconds' => 4.2,
            'text_en' => 'Next line',
            'text_jp' => 'next line',
            'formatted_text' => 'Next line',
        ],
    ]);

    $deduplicator = new OverlapDeduplicator;
    $deduped = $deduplicator->dedupe($segments, 0.05);

    expect($deduped)->toHaveCount(2)
        ->and($deduped[0]['id'])->toBe(1)
        ->and($deduped[1]['id'])->toBe(3)
        ->and($deduped[1]['start_seconds'])->toBeGreaterThanOrEqual(2.0);
});
