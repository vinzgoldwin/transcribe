<?php

namespace App\Services\Transcription;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class OcrSubtitleExtractor
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $ffmpegBinary,
        public string $tesseractBinary,
        public int $timeoutSeconds,
        public string $language,
        public int $psm,
        public int $oem,
        public float $fps,
        public int $scale,
        public int $minChars,
        public int $minConfidence,
        public float $cropWidthRatio,
        public float $cropHeightRatio,
        public float $cropBottomPaddingRatio,
        public string $filters,
        public int $logEvery,
        public int $minLineConfidence,
        public float $minLineHeightRatio,
        public float $similarityThreshold,
        public float $minLineBottomRatio,
        public float $minSegmentSeconds,
        public float $maxBlankSeconds,
    ) {}

    public function withCropOverrides(
        ?float $widthRatio = null,
        ?float $heightRatio = null,
        ?float $bottomPaddingRatio = null,
    ): self {
        return new self(
            $this->ffmpegBinary,
            $this->tesseractBinary,
            $this->timeoutSeconds,
            $this->language,
            $this->psm,
            $this->oem,
            $this->fps,
            $this->scale,
            $this->minChars,
            $this->minConfidence,
            $widthRatio ?? $this->cropWidthRatio,
            $heightRatio ?? $this->cropHeightRatio,
            $bottomPaddingRatio ?? $this->cropBottomPaddingRatio,
            $this->filters,
            $this->logEvery,
            $this->minLineConfidence,
            $this->minLineHeightRatio,
            $this->similarityThreshold,
            $this->minLineBottomRatio,
            $this->minSegmentSeconds,
            $this->maxBlankSeconds,
        );
    }

    /**
     * @param  callable(int, int, float): void|null  $onProgress
     * @return array<int, array{start: float, end: float, text: string}>|null
     */
    public function extract(
        string $inputPath,
        string $tempDirectory,
        array $context = [],
        ?callable $onProgress = null,
    ): ?array {
        $framesDirectory = rtrim($tempDirectory, '/').'/ocr_frames';
        File::ensureDirectoryExists($framesDirectory);
        File::cleanDirectory($framesDirectory);

        Log::info('OCR: extracting frames', array_merge($context, [
            'fps' => $this->fps,
            'scale' => $this->scale,
        ]));

        $this->extractFrames($inputPath, $framesDirectory);

        $frames = $this->listFrames($framesDirectory);

        if ($frames === []) {
            Log::warning('OCR: no frames extracted', $context);

            return null;
        }

        $totalFrames = count($frames);
        $logEvery = max(1, $this->logEvery);
        Log::info('OCR: frames ready', array_merge($context, [
            'frames_total' => $totalFrames,
        ]));

        $segments = [];
        $currentText = null;
        $currentStart = null;
        $currentNormalized = null;
        $lastSeen = null;
        $frameDuration = 1 / max(0.1, $this->fps);

        foreach ($frames as $index => $framePath) {
            $timestamp = $index / max(0.1, $this->fps);
            $text = $this->sanitizeText($this->ocrFrame($framePath));
            $normalized = $this->normalizeForComparison($text);

            if (($index + 1) % $logEvery === 0) {
                $progress = round((($index + 1) / $totalFrames) * 100, 1);
                Log::info('OCR: progress', array_merge($context, [
                    'frame' => $index + 1,
                    'frames_total' => $totalFrames,
                    'progress_percent' => $progress,
                ]));

                if ($onProgress !== null) {
                    $onProgress($index + 1, $totalFrames, $progress);
                }
            }

            if ($normalized === '' || mb_strlen($normalized) < $this->minChars) {
                if ($currentText !== null && $currentStart !== null && $lastSeen !== null) {
                    $gap = $timestamp - $lastSeen;

                    if ($gap <= $this->maxBlankSeconds) {
                        continue;
                    }

                    $end = $lastSeen + $frameDuration;
                    $duration = $end - $currentStart;

                    if ($duration >= $this->minSegmentSeconds) {
                        $segments[] = [
                            'start' => round($currentStart, 3),
                            'end' => round($end, 3),
                            'text' => $currentText,
                        ];
                    }
                }

                $currentText = null;
                $currentStart = null;
                $currentNormalized = null;
                $lastSeen = null;

                continue;
            }

            if ($currentNormalized === null) {
                $currentText = $text;
                $currentStart = $timestamp;
                $currentNormalized = $normalized;
                $lastSeen = $timestamp;

                continue;
            }

            if (
                $currentNormalized === $normalized ||
                $this->isSimilarText($currentNormalized, $normalized)
            ) {
                $lastSeen = $timestamp;

                continue;
            }

            if ($currentText !== null && $currentStart !== null && $lastSeen !== null) {
                $end = max($timestamp, $lastSeen + $frameDuration);
                $duration = $end - $currentStart;

                if ($duration >= $this->minSegmentSeconds) {
                    $segments[] = [
                        'start' => round((float) $currentStart, 3),
                        'end' => round($end, 3),
                        'text' => (string) $currentText,
                    ];
                }
            }

            $currentText = $text;
            $currentStart = $timestamp;
            $currentNormalized = $normalized;
            $lastSeen = $timestamp;
        }

        if ($currentText !== null && $currentStart !== null && $lastSeen !== null) {
            $end = $lastSeen + $frameDuration;
            $duration = $end - $currentStart;

            if ($duration >= $this->minSegmentSeconds) {
                $segments[] = [
                    'start' => round($currentStart, 3),
                    'end' => $end,
                    'text' => $currentText,
                ];
            }
        }

        File::deleteDirectory($framesDirectory);

        Log::info('OCR: segments built', array_merge($context, [
            'segments_total' => count($segments),
        ]));

        return $segments === [] ? null : $segments;
    }

    protected function extractFrames(string $inputPath, string $framesDirectory): void
    {
        $filters = $this->buildFilters();
        $outputPattern = $framesDirectory.'/frame_%06d.png';

        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-i',
            $inputPath,
            '-vf',
            $filters,
            '-q:v',
            '2',
            $outputPattern,
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function listFrames(string $framesDirectory): array
    {
        $frames = glob($framesDirectory.'/frame_*.png') ?: [];
        sort($frames);

        return array_values($frames);
    }

    protected function ocrFrame(string $framePath): string
    {
        if (! File::exists($framePath)) {
            return '';
        }

        $process = new Process([
            $this->tesseractBinary,
            $framePath,
            'stdout',
            '-l',
            $this->language,
            '--psm',
            (string) $this->psm,
            '--oem',
            (string) $this->oem,
            'tsv',
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $this->parseTsv($process->getOutput());
    }

    protected function parseTsv(string $tsv): string
    {
        $lines = preg_split('/\R/', trim($tsv)) ?: [];

        if ($lines === []) {
            return '';
        }

        $linesByKey = [];
        $pageWidth = null;
        $pageHeight = null;

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }

            $columns = explode("\t", $line);

            if (count($columns) < 12) {
                continue;
            }

            $level = (int) ($columns[0] ?? 0);
            $pageNum = (int) ($columns[1] ?? 0);
            $blockNum = (int) ($columns[2] ?? 0);
            $parNum = (int) ($columns[3] ?? 0);
            $lineNum = (int) ($columns[4] ?? 0);
            $wordNum = (int) ($columns[5] ?? 0);
            $left = (int) ($columns[6] ?? 0);
            $top = (int) ($columns[7] ?? 0);
            $width = (int) ($columns[8] ?? 0);
            $height = (int) ($columns[9] ?? 0);
            $confidence = (float) ($columns[10] ?? -1);
            $text = trim((string) ($columns[11] ?? ''));

            if ($level === 1 && $width > 0 && $height > 0) {
                $pageWidth = $width;
                $pageHeight = $height;

                continue;
            }

            if ($level !== 5 || $wordNum <= 0) {
                continue;
            }

            if ($confidence < $this->minConfidence || $text === '') {
                continue;
            }

            $key = implode('-', [$pageNum, $blockNum, $parNum, $lineNum]);
            $entry = $linesByKey[$key] ?? [
                'words' => [],
                'left' => $left,
                'top' => $top,
                'right' => $left + $width,
                'bottom' => $top + $height,
                'confidence_total' => 0.0,
                'confidence_count' => 0,
            ];

            $entry['words'][] = $text;
            $entry['left'] = min($entry['left'], $left);
            $entry['top'] = min($entry['top'], $top);
            $entry['right'] = max($entry['right'], $left + $width);
            $entry['bottom'] = max($entry['bottom'], $top + $height);
            $entry['confidence_total'] += max(0, $confidence);
            $entry['confidence_count']++;

            $linesByKey[$key] = $entry;
        }

        if ($linesByKey === []) {
            return '';
        }

        $candidates = [];
        $maxHeight = 0.0;

        foreach ($linesByKey as $entry) {
            $text = $this->sanitizeText(implode('', $entry['words']));

            if ($text === '') {
                continue;
            }

            $lineHeight = max(0, $entry['bottom'] - $entry['top']);
            $maxHeight = max($maxHeight, $lineHeight);

            $candidates[] = [
                'text' => $text,
                'left' => $entry['left'],
                'top' => $entry['top'],
                'right' => $entry['right'],
                'bottom' => $entry['bottom'],
                'height' => $lineHeight,
                'width' => max(0, $entry['right'] - $entry['left']),
                'avg_confidence' => $entry['confidence_total'] / max(1, $entry['confidence_count']),
            ];
        }

        if ($candidates === []) {
            return '';
        }

        $heightRatio = max(0.0, min(1.0, $this->minLineHeightRatio));
        $minHeight = $maxHeight > 0 ? $maxHeight * $heightRatio : 0.0;
        $heightFiltered = array_values(array_filter($candidates, fn (array $entry) => $entry['height'] >= $minHeight));
        $filtered = $heightFiltered !== [] ? $heightFiltered : $candidates;

        if ($pageHeight !== null && $pageHeight > 0) {
            $bottomRatio = max(0.0, min(1.0, $this->minLineBottomRatio));
            $bottomFiltered = array_values(array_filter(
                $filtered,
                fn (array $entry) => $entry['bottom'] >= ($pageHeight * $bottomRatio),
            ));
            $filtered = $bottomFiltered !== [] ? $bottomFiltered : $filtered;
        }
        $confidenceFiltered = array_values(array_filter(
            $filtered,
            fn (array $entry) => $entry['avg_confidence'] >= $this->minLineConfidence,
        ));
        $filtered = $confidenceFiltered !== [] ? $confidenceFiltered : $filtered;

        usort($filtered, function (array $left, array $right) use ($pageWidth, $pageHeight): int {
            if ($left['avg_confidence'] !== $right['avg_confidence']) {
                return $right['avg_confidence'] <=> $left['avg_confidence'];
            }

            if ($left['width'] !== $right['width']) {
                return $right['width'] <=> $left['width'];
            }

            $leftLength = mb_strlen($left['text']);
            $rightLength = mb_strlen($right['text']);

            if ($leftLength !== $rightLength) {
                return $rightLength <=> $leftLength;
            }

            if ($pageWidth) {
                $leftCenter = ($left['left'] + $left['right']) / 2;
                $rightCenter = ($right['left'] + $right['right']) / 2;
                $pageCenter = $pageWidth / 2;
                $leftDistance = abs($leftCenter - $pageCenter);
                $rightDistance = abs($rightCenter - $pageCenter);

                return $leftDistance <=> $rightDistance;
            }

            if ($pageHeight) {
                $leftDistance = $pageHeight - $left['bottom'];
                $rightDistance = $pageHeight - $right['bottom'];

                return $leftDistance <=> $rightDistance;
            }

            return 0;
        });

        return $filtered[0]['text'] ?? '';
    }

    protected function sanitizeText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

            if ($cleaned !== false) {
                $text = $cleaned;
            }
        }

        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $cleaned = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');

            if (preg_match('//u', $cleaned) === 1) {
                return trim($cleaned);
            }
        }

        return '';
    }

    protected function normalizeForComparison(string $text): string
    {
        $normalized = trim(mb_strtolower($text));
        $normalized = preg_replace('/\s+/u', '', $normalized) ?? '';
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized) ?? '';

        return trim($normalized);
    }

    protected function isSimilarText(string $current, string $next): bool
    {
        if ($current === '' || $next === '') {
            return false;
        }

        if ($current === $next) {
            return true;
        }

        similar_text($current, $next, $percent);

        return $percent >= $this->similarityThreshold;
    }

    protected function buildFilters(): string
    {
        $fps = max(0.1, $this->fps);
        $widthRatio = max(0.1, min(1.0, $this->cropWidthRatio));
        $heightRatio = max(0.1, min(1.0, $this->cropHeightRatio));
        $bottomPadding = max(0.0, min(0.3, $this->cropBottomPaddingRatio));
        $scale = max(1, $this->scale);

        $x = sprintf('(iw*(1-%0.4f)/2)', $widthRatio);
        $y = sprintf('(ih-(ih*%0.4f)-(ih*%0.4f))', $heightRatio, $bottomPadding);
        $crop = sprintf('crop=iw*%0.4f:ih*%0.4f:%s:%s', $widthRatio, $heightRatio, $x, $y);
        $scaleFilter = sprintf('scale=iw*%d:ih*%d', $scale, $scale);

        $filters = [
            sprintf('fps=%0.3f', $fps),
            $crop,
            $scaleFilter,
        ];

        $extraFilters = trim($this->filters);

        if ($extraFilters !== '') {
            $filters[] = $extraFilters;
        }

        return implode(',', $filters);
    }
}
