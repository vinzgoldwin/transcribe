<?php

use App\Services\Transcription\SubtitleExtractor;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

it('extracts the preferred subtitle stream', function () {
    $tempDirectory = storage_path('app/testing/subtitles');
    File::ensureDirectoryExists($tempDirectory);

    $srt = <<<'SRT'
1
00:00:00,000 --> 00:00:01,000
ni hao

2
00:00:01,000 --> 00:00:02,000
shi jie
SRT;

    $extractor = new class($srt) extends SubtitleExtractor
    {
        public function __construct(private string $payload)
        {
            parent::__construct('ffmpeg', 'ffprobe', 5);
        }

        protected function probeSubtitleStreams(string $inputPath): array
        {
            return [
                ['index' => 2, 'language' => 'jpn', 'title' => null, 'codec' => 'mov_text'],
                ['index' => 3, 'language' => 'zh', 'title' => null, 'codec' => 'mov_text'],
            ];
        }

        protected function extractStreamToSrt(string $inputPath, int $streamIndex, string $outputPath): void
        {
            file_put_contents($outputPath, $this->payload);
        }
    };

    $segments = $extractor->extract('input.mp4', $tempDirectory, 'zh', true);

    expect($segments)->toBe([
        ['start' => 0.0, 'end' => 1.0, 'text' => 'ni hao'],
        ['start' => 1.0, 'end' => 2.0, 'text' => 'shi jie'],
    ]);
});

it('returns null when no subtitle streams are found', function () {
    $tempDirectory = storage_path('app/testing/subtitles');
    File::ensureDirectoryExists($tempDirectory);

    $extractor = new class extends SubtitleExtractor
    {
        public function __construct()
        {
            parent::__construct('ffmpeg', 'ffprobe', 5);
        }

        protected function probeSubtitleStreams(string $inputPath): array
        {
            return [];
        }
    };

    $segments = $extractor->extract('input.mp4', $tempDirectory, 'zh', false);

    expect($segments)->toBeNull();
});
