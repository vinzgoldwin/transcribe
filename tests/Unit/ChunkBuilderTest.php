<?php

use App\Services\Transcription\ChunkBuilder;

it('builds overlapped chunks with silence-aware boundaries', function () {
    $builder = new ChunkBuilder;

    $silences = [
        ['start' => 59.0, 'end' => 60.0, 'duration' => 1.0],
        ['start' => 117.0, 'end' => 118.0, 'duration' => 1.0],
        ['start' => 174.0, 'end' => 175.0, 'duration' => 1.0],
    ];

    $chunks = $builder->build(240.0, $silences, 45.0, 120.0, 4.0);

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]['start'])->toBe(0.0)
        ->and($chunks[0]['end'])->toBe(118.0)
        ->and($chunks[1]['start'])->toBe(114.0)
        ->and($chunks[1]['end'])->toBe(175.0)
        ->and($chunks[2]['start'])->toBe(171.0)
        ->and($chunks[2]['end'])->toBe(240.0);
});
