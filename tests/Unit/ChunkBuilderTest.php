<?php

use App\Services\Transcription\ChunkBuilder;

it('builds overlapped chunks with silence-aware boundaries', function () {
    $builder = new ChunkBuilder;

    $silences = [
        ['start' => 34.0, 'end' => 35.0, 'duration' => 1.0],
        ['start' => 69.0, 'end' => 70.0, 'duration' => 1.0],
        ['start' => 94.0, 'end' => 95.0, 'duration' => 1.0],
    ];

    $chunks = $builder->build(120.0, $silences, 30.0, 90.0, 2.0);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]['start'])->toBe(0.0)
        ->and($chunks[0]['end'])->toBe(70.0)
        ->and($chunks[1]['start'])->toBe(68.0)
        ->and($chunks[1]['end'])->toBe(120.0);
});
