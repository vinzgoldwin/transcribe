<?php

use App\Jobs\TranslateTranscriptionJob;
use App\Models\Transcription;
use App\Models\TranscriptionSegment;

uses(Tests\TestCase::class);
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('filters unlikely chinese subtitle lines', function () {
    $job = new class(1) extends TranslateTranscriptionJob
    {
        public function __construct(int $transcriptionId)
        {
            parent::__construct($transcriptionId);
        }

        public function check(string $text): bool
        {
            return $this->isLikelyChineseSubtitle($text);
        }
    };

    expect($job->check('白洁老师'))->toBeTrue()
        ->and($job->check('好'))->toBeTrue()
        ->and($job->check('M图'))->toBeFalse()
        ->and($job->check('AA人'))->toBeFalse()
        ->and($job->check('123'))->toBeFalse();
});

it('drops short OCR segments before translation', function () {
    $transcription = Transcription::factory()->create([
        'meta' => ['subtitle_source' => 'ocr', 'source_language' => 'zh'],
    ]);

    TranscriptionSegment::factory()->create([
        'transcription_id' => $transcription->id,
        'start_seconds' => 0.0,
        'end_seconds' => 0.2,
        'text_jp' => '白洁老师',
        'text_en' => 'placeholder',
        'formatted_text' => 'placeholder',
        'sequence' => 1,
    ]);

    $longSegment = TranscriptionSegment::factory()->create([
        'transcription_id' => $transcription->id,
        'start_seconds' => 1.0,
        'end_seconds' => 2.2,
        'text_jp' => '白洁老师',
        'text_en' => 'placeholder',
        'formatted_text' => 'placeholder',
        'sequence' => 2,
    ]);

    $job = new class($transcription->id) extends TranslateTranscriptionJob
    {
        public function __construct(int $transcriptionId)
        {
            parent::__construct($transcriptionId);
        }

        public function filterPublic($segments, string $sourceLanguage, Transcription $transcription)
        {
            return $this->filterSegments($segments, $sourceLanguage, $transcription);
        }
    };

    $filtered = $job->filterPublic(
        $transcription->segments()->orderBy('sequence')->get(),
        'zh',
        $transcription,
    );

    expect($filtered->count())->toBe(1)
        ->and($filtered->first()->id)->toBe($longSegment->id);
});
