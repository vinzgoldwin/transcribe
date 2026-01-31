<?php

use App\Enums\TranscriptionStatus;
use App\Jobs\StartTranscriptionJob;
use App\Models\Transcription;
use App\Services\Transcription\ChunkBuilder;
use App\Services\Transcription\MediaProcessor;
use App\Services\Transcription\OcrSubtitleExtractor;
use App\Services\Transcription\SilenceDetector;
use App\Services\Transcription\SubtitleExtractor;
use App\Services\Transcription\SubtitleFormatter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('fails when OCR is selected and no subtitles are detected', function () {
    Storage::fake('local');
    config(['transcribe.temp_directory' => storage_path('app/testing')]);

    $transcription = Transcription::factory()->create([
        'storage_disk' => 'local',
        'storage_path' => 'transcriptions/source.mp4',
        'status' => TranscriptionStatus::Uploaded,
        'meta' => [
            'source_language' => 'zh',
            'subtitle_source' => 'ocr',
        ],
    ]);

    Storage::disk('local')->put($transcription->storage_path, 'video');

    $job = new class($transcription->id) extends StartTranscriptionJob
    {
        protected function downloadToLocal(
            \Illuminate\Filesystem\FilesystemAdapter $disk,
            string $storagePath,
            string $localPath,
            string $driver,
        ): void {
            File::ensureDirectoryExists(dirname($localPath));
            File::put($localPath, 'video');
        }
    };

    $mediaProcessor = new class extends MediaProcessor
    {
        public function __construct()
        {
            parent::__construct('ffmpeg', 'ffprobe', 10);
        }

        public function probeDuration(string $inputPath): float
        {
            return 60.0;
        }
    };

    $ocrExtractor = new class extends OcrSubtitleExtractor
    {
        public function __construct()
        {
            parent::__construct(
                'ffmpeg',
                'tesseract',
                5,
                'chi_sim',
                7,
                1,
                2.0,
                1,
                1,
                0,
                0.8,
                0.2,
                0.03,
                '',
                25,
                55,
                0.7,
                90,
                0.55,
                0.9,
                0.75,
            );
        }

        public function extract(
            string $inputPath,
            string $tempDirectory,
            array $context = [],
            ?callable $onProgress = null,
        ): ?array {
            return null;
        }
    };

    $subtitleExtractor = new class extends SubtitleExtractor
    {
        public function __construct()
        {
            parent::__construct('ffmpeg', 'ffprobe', 5);
        }
    };

    $job->handle(
        $mediaProcessor,
        new SilenceDetector,
        new ChunkBuilder,
        $ocrExtractor,
        $subtitleExtractor,
        app(SubtitleFormatter::class),
    );

    $transcription->refresh();

    expect($transcription->status)->toBe(TranscriptionStatus::Failed)
        ->and($transcription->error_message)->toBe('OCR did not detect any subtitles.')
        ->and($transcription->audio_path)->toBeNull();
});

it('sanitizes transcription error messages', function () {
    $job = new class(1) extends StartTranscriptionJob
    {
        public function sanitizePublic(string $text): string
        {
            return $this->sanitizeText($text);
        }
    };

    $sanitized = $job->sanitizePublic("\xE5");

    expect($sanitized === '' || preg_match('//u', $sanitized) === 1)->toBeTrue();
});

it('marks subtitle progress as complete when storing segments', function () {
    $transcription = Transcription::factory()->create([
        'meta' => ['subtitle_source' => 'ocr'],
    ]);

    $job = new class($transcription->id) extends StartTranscriptionJob
    {
        /**
         * @param  array<int, array{start: float, end: float, text: string}>  $segments
         */
        public function storePublic(
            Transcription $transcription,
            array $segments,
            \App\Services\Transcription\SubtitleFormatter $formatter,
            float $durationSeconds,
            string $source,
        ): void {
            $this->storeSubtitleSegments($transcription, $segments, $formatter, $durationSeconds, $source);
        }
    };

    $job->storePublic(
        $transcription,
        [['start' => 0.0, 'end' => 1.0, 'text' => 'hello']],
        app(\App\Services\Transcription\SubtitleFormatter::class),
        10.0,
        'ocr',
    );

    $transcription->refresh();

    expect($transcription->meta['subtitle_progress_percent'] ?? null)->toBe(100);
});

it('merges OCR segments from multiple passes', function () {
    $job = new class(1) extends StartTranscriptionJob
    {
        /**
         * @param  array<int, array{start: float, end: float, text: string}>  $primary
         * @param  array<int, array{start: float, end: float, text: string}>  $secondary
         * @return array<int, array{start: float, end: float, text: string}>
         */
        public function mergePublic(array $primary, array $secondary, float $gap): array
        {
            return $this->mergeOcrSegments($primary, $secondary, $gap);
        }
    };

    $primary = [
        ['start' => 0.0, 'end' => 1.0, 'text' => '你好'],
        ['start' => 3.0, 'end' => 4.0, 'text' => '再见'],
    ];

    $secondary = [
        ['start' => 0.9, 'end' => 1.4, 'text' => '你好'],
        ['start' => 2.9, 'end' => 4.2, 'text' => '再见'],
    ];

    $merged = $job->mergePublic($primary, $secondary, 0.1);

    expect($merged)->toBe([
        ['start' => 0.0, 'end' => 1.4, 'text' => '你好'],
        ['start' => 2.9, 'end' => 4.2, 'text' => '再见'],
    ]);
});
