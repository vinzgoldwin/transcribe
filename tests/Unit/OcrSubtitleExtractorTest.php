<?php

use App\Services\Transcription\OcrSubtitleExtractor;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

it('builds subtitle segments from OCR frames', function () {
    $tempDirectory = storage_path('app/testing/ocr');
    File::ensureDirectoryExists($tempDirectory);

    $extractor = new class extends OcrSubtitleExtractor
    {
        /**
         * @var array<string, string>
         */
        private array $ocrMap = [];

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
                0.5,
                0.75,
            );
        }

        protected function extractFrames(string $inputPath, string $framesDirectory): void
        {
            $frames = [
                1 => 'hello',
                2 => 'hello',
                3 => '',
                4 => 'world',
            ];

            foreach ($frames as $index => $text) {
                $filename = sprintf('frame_%06d.png', $index);
                $path = $framesDirectory.'/'.$filename;
                File::put($path, 'frame');
                $this->ocrMap[$path] = $text;
            }
        }

        protected function ocrFrame(string $framePath): string
        {
            return $this->ocrMap[$framePath] ?? '';
        }
    };

    $segments = $extractor->extract('input.mp4', $tempDirectory);

    expect($segments)->toBe([
        ['start' => 0.0, 'end' => 1.5, 'text' => 'hello'],
        ['start' => 1.5, 'end' => 2.0, 'text' => 'world'],
    ]);
});

it('sanitizes invalid OCR text', function () {
    $extractor = new class extends OcrSubtitleExtractor
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
                0.5,
                0.75,
            );
        }

        public function sanitizePublic(string $text): string
        {
            return $this->sanitizeText($text);
        }
    };

    $sanitized = $extractor->sanitizePublic("\xE5");

    expect($sanitized === '' || preg_match('//u', $sanitized) === 1)->toBeTrue();
});

it('reports OCR progress via callback', function () {
    $tempDirectory = storage_path('app/testing/ocr-progress');
    File::ensureDirectoryExists($tempDirectory);

    $extractor = new class extends OcrSubtitleExtractor
    {
        /**
         * @var array<string, string>
         */
        private array $ocrMap = [];

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
                2,
                55,
                0.7,
                90,
                0.55,
                0.5,
                0.75,
            );
        }

        protected function extractFrames(string $inputPath, string $framesDirectory): void
        {
            foreach ([1 => 'hello', 2 => 'hello', 3 => 'world', 4 => 'world'] as $index => $text) {
                $filename = sprintf('frame_%06d.png', $index);
                $path = $framesDirectory.'/'.$filename;
                File::put($path, 'frame');
                $this->ocrMap[$path] = $text;
            }
        }

        protected function ocrFrame(string $framePath): string
        {
            return $this->ocrMap[$framePath] ?? '';
        }
    };

    $progress = [];

    $extractor->extract(
        'input.mp4',
        $tempDirectory,
        [],
        function (int $frame, int $total, float $percent) use (&$progress): void {
            $progress[] = [$frame, $total, $percent];
        },
    );

    expect($progress)->not->toBeEmpty()
        ->and($progress[0][1])->toBe(4)
        ->and($progress[count($progress) - 1][0])->toBe(4);
});
