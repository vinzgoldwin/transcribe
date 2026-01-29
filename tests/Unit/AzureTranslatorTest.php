<?php

use App\Services\Transcription\Translation\AzureTranslator;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

test('azure translator retries on 429 responses', function () {
    Http::fakeSequence()
        ->push(['error' => ['code' => 429001, 'message' => 'Too Many Requests']], 429)
        ->push([
            [
                'translations' => [
                    ['text' => 'Hello'],
                ],
            ],
        ], 200);

    $translator = new AzureTranslator(
        'key',
        'https://example.test',
        null,
        '3.0',
        [1],
        true,
    );

    $result = $translator->translate(['こんにちは'], 'JA', 'EN');

    expect($result)->toBe(['Hello']);
    Http::assertSentCount(2);
});
