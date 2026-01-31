<?php

namespace App\Jobs;

use App\Enums\TranscriptionChunkStatus;
use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use App\Models\TranscriptionChunk;
use App\Models\TranscriptionSegment;
use App\Services\Transcription\ChunkBuilder;
use App\Services\Transcription\MediaProcessor;
use App\Services\Transcription\OcrSubtitleExtractor;
use App\Services\Transcription\SilenceDetector;
use App\Services\Transcription\SubtitleExtractor;
use App\Services\Transcription\SubtitleFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StartTranscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 600];

    public int $timeout;

    public function __construct(public int $transcriptionId)
    {
        $this->timeout = (int) config('transcribe.queue.start_timeout_seconds', 3600);
    }

    public function handle(
        MediaProcessor $mediaProcessor,
        SilenceDetector $silenceDetector,
        ChunkBuilder $chunkBuilder,
        OcrSubtitleExtractor $ocrExtractor,
        SubtitleExtractor $subtitleExtractor,
        SubtitleFormatter $formatter,
    ): void {
        $transcription = Transcription::query()->with('chunks')->findOrFail($this->transcriptionId);

        if ($transcription->status === TranscriptionStatus::Completed) {
            return;
        }

        $diskName = (string) $transcription->storage_disk;
        $disk = Storage::disk($diskName);
        $driver = (string) config("filesystems.disks.{$diskName}.driver", 'local');
        $tempDirectory = rtrim((string) config('transcribe.temp_directory'), '/').'/'.$transcription->public_id;

        File::ensureDirectoryExists($tempDirectory);

        $sourceLocalPath = $tempDirectory.'/source.mp4';
        Log::info('Transcription start: downloading source', [
            'transcription_id' => $transcription->id,
            'public_id' => $transcription->public_id,
            'storage_path' => $transcription->storage_path,
            'driver' => $driver,
        ]);
        $this->downloadToLocal($disk, $transcription->storage_path, $sourceLocalPath, $driver);

        $sourceDuration = $mediaProcessor->probeDuration($sourceLocalPath);
        $sourceLanguage = $this->resolveSourceLanguage($transcription);
        $subtitleSource = $this->resolveSubtitleSource($transcription);

        if ($subtitleSource === 'ocr') {
            if (! $this->isOcrEnabled()) {
                $this->failTranscription($transcription, 'OCR is disabled for this environment.');
                File::delete([$sourceLocalPath]);

                return;
            }

            Log::info('Transcription start: running OCR on burned-in subtitles', [
                'transcription_id' => $transcription->id,
                'public_id' => $transcription->public_id,
                'source_language' => $sourceLanguage,
            ]);

            try {
                $passes = $this->resolveOcrPasses();
                $passCount = count($passes);
                $primarySegments = null;
                $secondarySegments = [];

                foreach ($passes as $index => $pass) {
                    $extractor = $ocrExtractor;

                    if (
                        $pass['width_ratio'] !== null ||
                        $pass['height_ratio'] !== null ||
                        $pass['bottom_padding_ratio'] !== null
                    ) {
                        $extractor = $ocrExtractor->withCropOverrides(
                            $pass['width_ratio'],
                            $pass['height_ratio'],
                            $pass['bottom_padding_ratio'],
                        );
                    }

                    if ($index > 0) {
                        Log::info('OCR: running secondary pass', array_merge($pass, [
                            'transcription_id' => $transcription->id,
                            'public_id' => $transcription->public_id,
                        ]));
                    }

                    $passSegments = $extractor->extract(
                        $sourceLocalPath,
                        $tempDirectory,
                        [
                            'transcription_id' => $transcription->id,
                            'public_id' => $transcription->public_id,
                            'ocr_pass' => $index + 1,
                            'ocr_pass_total' => $passCount,
                        ],
                        $this->buildOcrProgressCallback($transcription, $index + 1, $passCount),
                    );

                    if ($passSegments === null) {
                        if ($index === 0) {
                            $primarySegments = null;
                            break;
                        }

                        Log::warning('OCR: secondary pass returned no subtitles', [
                            'transcription_id' => $transcription->id,
                            'public_id' => $transcription->public_id,
                            'pass' => $index + 1,
                        ]);

                        continue;
                    }

                    if ($primarySegments === null) {
                        $primarySegments = $passSegments;
                    } else {
                        $secondarySegments[] = $passSegments;
                    }
                }

                $ocrSegments = $primarySegments;
                $mergeGap = (float) config('transcribe.ocr.merge_gap_seconds', config('transcribe.subtitle.gap_seconds', 0.05));

                foreach ($secondarySegments as $segments) {
                    $ocrSegments = $ocrSegments === null
                        ? $segments
                        : $this->mergeOcrSegments($ocrSegments, $segments, $mergeGap);
                }
            } catch (Throwable $exception) {
                $this->failTranscription($transcription, "OCR failed: {$exception->getMessage()}");
                File::delete([$sourceLocalPath]);

                return;
            }

            if ($ocrSegments === null) {
                $this->failTranscription($transcription, 'OCR did not detect any subtitles.');
                File::delete([$sourceLocalPath]);

                return;
            }

            $this->storeSubtitleSegments($transcription, $ocrSegments, $formatter, $sourceDuration, 'ocr');
            Log::info('Transcription start: OCR segments stored', [
                'transcription_id' => $transcription->id,
                'public_id' => $transcription->public_id,
                'segments_total' => count($ocrSegments),
            ]);

            if ($this->resolveStopAfter($transcription) === 'whisper') {
                FinalizeTranscriptionJob::dispatch($transcription->id);
            } else {
                TranslateTranscriptionJob::dispatch($transcription->id);
            }

            File::delete([$sourceLocalPath]);

            return;
        }

        if ($subtitleSource === 'embedded') {
            Log::info('Transcription start: probing embedded subtitles', [
                'transcription_id' => $transcription->id,
                'public_id' => $transcription->public_id,
                'source_language' => $sourceLanguage,
            ]);

            try {
                $subtitleSegments = $subtitleExtractor->extract($sourceLocalPath, $tempDirectory, $sourceLanguage);
            } catch (Throwable $exception) {
                $this->failTranscription($transcription, "Embedded subtitle extraction failed: {$exception->getMessage()}");
                File::delete([$sourceLocalPath]);

                return;
            }

            if ($subtitleSegments === null) {
                $this->failTranscription($transcription, 'No embedded subtitle track found.');
                File::delete([$sourceLocalPath]);

                return;
            }

            $this->storeSubtitleSegments($transcription, $subtitleSegments, $formatter, $sourceDuration, 'embedded');

            if ($this->resolveStopAfter($transcription) === 'whisper') {
                FinalizeTranscriptionJob::dispatch($transcription->id);
            } else {
                TranslateTranscriptionJob::dispatch($transcription->id);
            }

            File::delete([$sourceLocalPath]);

            return;
        }

        if ($subtitleSource === 'auto') {
            if ($this->isOcrEnabled()) {
                Log::info('Transcription start: running OCR on burned-in subtitles', [
                    'transcription_id' => $transcription->id,
                    'public_id' => $transcription->public_id,
                    'source_language' => $sourceLanguage,
                ]);

                try {
                    $ocrSegments = $ocrExtractor->extract(
                        $sourceLocalPath,
                        $tempDirectory,
                        [
                            'transcription_id' => $transcription->id,
                            'public_id' => $transcription->public_id,
                        ],
                        function (int $frame, int $framesTotal, float $progress) use ($transcription): void {
                            $this->recordSubtitleProgress($transcription, $frame, $framesTotal, $progress);
                        },
                    );
                } catch (Throwable $exception) {
                    Log::warning('Transcription start: OCR failed, falling back to other sources', [
                        'transcription_id' => $transcription->id,
                        'public_id' => $transcription->public_id,
                        'message' => $exception->getMessage(),
                    ]);
                    $ocrSegments = null;
                }

                if ($ocrSegments !== null) {
                    $this->storeSubtitleSegments($transcription, $ocrSegments, $formatter, $sourceDuration, 'ocr');
                    Log::info('Transcription start: OCR segments stored', [
                        'transcription_id' => $transcription->id,
                        'public_id' => $transcription->public_id,
                        'segments_total' => count($ocrSegments),
                    ]);

                    if ($this->resolveStopAfter($transcription) === 'whisper') {
                        FinalizeTranscriptionJob::dispatch($transcription->id);
                    } else {
                        TranslateTranscriptionJob::dispatch($transcription->id);
                    }

                    File::delete([$sourceLocalPath]);

                    return;
                }
            }

            Log::info('Transcription start: probing embedded subtitles', [
                'transcription_id' => $transcription->id,
                'public_id' => $transcription->public_id,
                'source_language' => $sourceLanguage,
            ]);

            try {
                $subtitleSegments = $subtitleExtractor->extract($sourceLocalPath, $tempDirectory, $sourceLanguage);
            } catch (Throwable $exception) {
                Log::warning('Transcription start: embedded subtitle extraction failed, falling back to audio', [
                    'transcription_id' => $transcription->id,
                    'public_id' => $transcription->public_id,
                    'message' => $exception->getMessage(),
                ]);
                $subtitleSegments = null;
            }

            if ($subtitleSegments !== null) {
                $this->storeSubtitleSegments($transcription, $subtitleSegments, $formatter, $sourceDuration, 'embedded');

                if ($this->resolveStopAfter($transcription) === 'whisper') {
                    FinalizeTranscriptionJob::dispatch($transcription->id);
                } else {
                    TranslateTranscriptionJob::dispatch($transcription->id);
                }

                File::delete([$sourceLocalPath]);

                return;
            }
        }

        $audioLocalPath = $tempDirectory.'/audio.wav';
        $audioStoragePath = $transcription->audio_path ?: $this->audioStoragePath($transcription);

        if (! $transcription->audio_path || ! $disk->exists($transcription->audio_path)) {
            Log::info('Transcription start: extracting audio', [
                'transcription_id' => $transcription->id,
                'public_id' => $transcription->public_id,
            ]);
            $mediaProcessor->extractAudio($sourceLocalPath, $audioLocalPath);
            $this->storeFromLocal($disk, $audioLocalPath, $audioStoragePath);
            $transcription->audio_path = $audioStoragePath;
        } else {
            $this->downloadToLocal($disk, $audioStoragePath, $audioLocalPath, $driver);
        }

        $duration = $mediaProcessor->probeDuration($audioLocalPath);
        Log::info('Transcription start: detecting silence', [
            'transcription_id' => $transcription->id,
            'public_id' => $transcription->public_id,
            'duration_seconds' => $duration,
        ]);

        $silenceOutput = $mediaProcessor->detectSilence(
            $audioLocalPath,
            (float) config('transcribe.silence.min_seconds'),
            (string) config('transcribe.silence.noise'),
        );

        $silences = $silenceDetector->parse($silenceOutput, (float) config('transcribe.silence.min_seconds'));

        $chunks = $chunkBuilder->build(
            $duration,
            $silences,
            (float) config('transcribe.chunk.min_seconds'),
            (float) config('transcribe.chunk.max_seconds'),
            (float) config('transcribe.chunk.overlap_seconds'),
        );

        $transcription->fill([
            'status' => TranscriptionStatus::Processing,
            'duration_seconds' => $duration,
            'chunks_total' => count($chunks),
            'chunks_completed' => $transcription->chunks
                ->where('status', TranscriptionChunkStatus::Completed)
                ->count(),
            'meta' => array_merge($transcription->meta ?? [], [
                'silences' => $silences,
            ]),
        ])->save();

        foreach ($chunks as $chunkData) {
            $chunk = TranscriptionChunk::firstOrNew([
                'transcription_id' => $transcription->id,
                'sequence' => $chunkData['sequence'],
            ]);

            if ($chunk->exists && $chunk->status === TranscriptionChunkStatus::Completed) {
                continue;
            }

            $chunk->fill([
                'start_seconds' => $chunkData['start'],
                'end_seconds' => $chunkData['end'],
                'status' => TranscriptionChunkStatus::Pending,
                'error_message' => null,
            ]);

            $chunk->save();

            $chunkLocalPath = $tempDirectory.'/chunk-'.$chunk->sequence.'.wav';
            $chunkDuration = max(0.1, $chunkData['end'] - $chunkData['start']);
            $mediaProcessor->cutChunk($audioLocalPath, $chunkData['start'], $chunkDuration, $chunkLocalPath);

            $chunkStoragePath = $this->chunkStoragePath($transcription, $chunk->sequence);
            $this->storeFromLocal($disk, $chunkLocalPath, $chunkStoragePath);

            $chunk->audio_path = $chunkStoragePath;
            $chunk->save();

            ProcessTranscriptionChunkJob::dispatch($chunk->id);
        }

        Log::info('Transcription start: queued chunks', [
            'transcription_id' => $transcription->id,
            'public_id' => $transcription->public_id,
            'chunks_total' => count($chunks),
        ]);

        File::delete([$sourceLocalPath, $audioLocalPath]);
    }

    protected function audioStoragePath(Transcription $transcription): string
    {
        return $this->storagePrefix()."/{$transcription->public_id}/audio.wav";
    }

    protected function chunkStoragePath(Transcription $transcription, int $sequence): string
    {
        return $this->storagePrefix()."/{$transcription->public_id}/chunks/{$sequence}.wav";
    }

    protected function storagePrefix(): string
    {
        return trim((string) config('transcribe.storage_prefix', 'transcriptions'), '/');
    }

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    protected function storeSubtitleSegments(
        Transcription $transcription,
        array $segments,
        SubtitleFormatter $formatter,
        float $durationSeconds,
        string $source,
    ): void {
        TranscriptionChunk::query()
            ->where('transcription_id', $transcription->id)
            ->delete();

        TranscriptionSegment::query()
            ->where('transcription_id', $transcription->id)
            ->delete();

        $segmentsToInsert = [];
        $sequence = 1;

        foreach ($segments as $segment) {
            $text = $this->sanitizeText(trim((string) ($segment['text'] ?? '')));
            $start = (float) ($segment['start'] ?? 0.0);
            $end = (float) ($segment['end'] ?? 0.0);

            if ($text === '' || $end <= $start) {
                continue;
            }

            $formattedText = $this->sanitizeText($formatter->wrapText($text));

            if ($formattedText === '') {
                $formattedText = $text;
            }

            $segmentsToInsert[] = [
                'transcription_id' => $transcription->id,
                'transcription_chunk_id' => null,
                'sequence' => $sequence,
                'start_seconds' => round($start, 3),
                'end_seconds' => round($end, 3),
                'text_jp' => $text,
                'text_en' => $text,
                'formatted_text' => $formattedText,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $sequence++;
        }

        if ($segmentsToInsert !== []) {
            TranscriptionSegment::query()->insert($segmentsToInsert);
        }

        $meta = array_merge($transcription->meta ?? [], [
            'subtitle_source' => $source,
            'subtitle_segment_count' => count($segmentsToInsert),
            'subtitle_progress_percent' => 100,
        ]);

        $transcription->update([
            'status' => TranscriptionStatus::Processing,
            'duration_seconds' => $durationSeconds,
            'chunks_total' => 0,
            'chunks_completed' => 0,
            'meta' => $meta,
        ]);
    }

    protected function resolveStopAfter(Transcription $transcription): string
    {
        $stopAfter = (string) ($transcription->meta['stop_after'] ?? config('transcribe.pipeline.stop_after', 'whisper'));
        $normalized = strtolower(trim($stopAfter));

        if ($normalized === 'whisper') {
            return 'whisper';
        }

        return in_array($normalized, ['azure', 'deepl'], true) ? 'azure' : 'whisper';
    }

    protected function resolveSourceLanguage(?Transcription $transcription): string
    {
        $language = (string) ($transcription?->meta['source_language'] ?? config('transcribe.language.default', 'ja'));
        $language = strtolower(trim($language));
        $supported = array_keys((array) config('transcribe.language.supported', []));

        if ($language !== '' && in_array($language, $supported, true)) {
            return $language;
        }

        return in_array('ja', $supported, true) ? 'ja' : ($supported[0] ?? 'ja');
    }

    /**
     * @return array<int, array{width_ratio: ?float, height_ratio: ?float, bottom_padding_ratio: ?float}>
     */
    protected function resolveOcrPasses(): array
    {
        $passes = [[
            'width_ratio' => null,
            'height_ratio' => null,
            'bottom_padding_ratio' => null,
        ]];

        $secondPass = (array) config('transcribe.ocr.second_pass', []);
        $enabled = (bool) ($secondPass['enabled'] ?? false);

        if (! $enabled) {
            return $passes;
        }

        $crop = (array) config('transcribe.ocr.crop', []);
        $primaryWidth = (float) ($crop['width_ratio'] ?? 0.8);
        $primaryHeight = (float) ($crop['height_ratio'] ?? 0.2);
        $primaryBottom = (float) ($crop['bottom_padding_ratio'] ?? 0.02);

        $width = $secondPass['width_ratio'] ?? null;
        $height = $secondPass['height_ratio'] ?? null;
        $bottom = $secondPass['bottom_padding_ratio'] ?? null;

        if ($width === null && $height === null && $bottom === null) {
            return $passes;
        }

        $widthValue = $width !== null ? (float) $width : $primaryWidth;
        $heightValue = $height !== null ? (float) $height : $primaryHeight;
        $bottomValue = $bottom !== null ? (float) $bottom : $primaryBottom;

        if (
            $widthValue === $primaryWidth &&
            $heightValue === $primaryHeight &&
            $bottomValue === $primaryBottom
        ) {
            return $passes;
        }

        $passes[] = [
            'width_ratio' => $width,
            'height_ratio' => $height,
            'bottom_padding_ratio' => $bottom,
        ];

        return $passes;
    }

    protected function buildOcrProgressCallback(
        Transcription $transcription,
        int $passIndex,
        int $passCount,
    ): callable {
        return function (int $frame, int $framesTotal, float $progress) use (
            $transcription,
            $passIndex,
            $passCount,
        ): void {
            $passCount = max(1, $passCount);
            $framesTotal = max(1, $framesTotal);

            $globalFramesTotal = $framesTotal * $passCount;
            $globalFrame = (($passIndex - 1) * $framesTotal) + $frame;
            $globalProgress = (($passIndex - 1) * (100 / $passCount)) + ($progress / $passCount);

            $this->recordSubtitleProgress($transcription, $globalFrame, $globalFramesTotal, $globalProgress);
        };
    }

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $primary
     * @param  array<int, array{start: float, end: float, text: string}>  $secondary
     * @return array<int, array{start: float, end: float, text: string}>
     */
    protected function mergeOcrSegments(array $primary, array $secondary, float $gapTolerance): array
    {
        if ($secondary === []) {
            return $primary;
        }

        $segments = array_merge($primary, $secondary);

        usort($segments, function (array $left, array $right): int {
            if ($left['start'] !== $right['start']) {
                return $left['start'] <=> $right['start'];
            }

            return $left['end'] <=> $right['end'];
        });

        $merged = [];

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            $start = (float) ($segment['start'] ?? 0.0);
            $end = (float) ($segment['end'] ?? 0.0);

            if ($text === '' || $end <= $start) {
                continue;
            }

            $current = [
                'start' => $start,
                'end' => $end,
                'text' => $text,
            ];

            if ($merged === []) {
                $merged[] = $current;

                continue;
            }

            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];
            $overlaps = $current['start'] <= ($last['end'] + $gapTolerance);

            if ($overlaps && $this->isSimilarOcrText($last['text'], $current['text'])) {
                $merged[$lastIndex]['end'] = max($last['end'], $current['end']);
                $merged[$lastIndex]['text'] = $this->pickPreferredOcrText($last['text'], $current['text']);

                continue;
            }

            $merged[] = $current;
        }

        return $merged;
    }

    protected function isSimilarOcrText(string $left, string $right): bool
    {
        $leftNormalized = $this->normalizeOcrText($left);
        $rightNormalized = $this->normalizeOcrText($right);

        if ($leftNormalized === '' || $rightNormalized === '') {
            return false;
        }

        if ($leftNormalized === $rightNormalized) {
            return true;
        }

        similar_text($leftNormalized, $rightNormalized, $percent);
        $threshold = (float) config('transcribe.ocr.similarity_threshold', 90);

        return $percent >= $threshold;
    }

    protected function normalizeOcrText(string $text): string
    {
        $normalized = trim(mb_strtolower($text));
        $normalized = preg_replace('/\s+/u', '', $normalized) ?? '';
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized) ?? '';

        return trim($normalized);
    }

    protected function pickPreferredOcrText(string $left, string $right): string
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        $leftHan = preg_match_all('/\p{Han}/u', $left) ?: 0;
        $rightHan = preg_match_all('/\p{Han}/u', $right) ?: 0;

        if ($leftHan !== $rightHan) {
            return $rightHan > $leftHan ? $right : $left;
        }

        return mb_strlen($right) > mb_strlen($left) ? $right : $left;
    }

    protected function shouldPreferSubtitles(Transcription $transcription): bool
    {
        if (is_array($transcription->meta) && array_key_exists('prefer_subtitles', $transcription->meta)) {
            return (bool) $transcription->meta['prefer_subtitles'];
        }

        return (bool) config('transcribe.subtitle.prefer_embedded', true);
    }

    protected function resolveSubtitleSource(Transcription $transcription): string
    {
        $metaSource = is_array($transcription->meta) ? ($transcription->meta['subtitle_source'] ?? null) : null;

        if (is_string($metaSource) && $metaSource !== '') {
            $normalized = strtolower(trim($metaSource));

            if (in_array($normalized, ['auto', 'embedded', 'ocr', 'audio'], true)) {
                return $normalized;
            }
        }

        return $this->shouldPreferSubtitles($transcription) ? 'auto' : 'audio';
    }

    protected function isOcrEnabled(): bool
    {
        return (bool) config('transcribe.ocr.enabled', true);
    }

    protected function failTranscription(Transcription $transcription, string $message): void
    {
        $message = $this->sanitizeText($message);

        if ($message === '') {
            $message = 'Transcription failed.';
        }

        $transcription->update([
            'status' => TranscriptionStatus::Failed,
            'error_message' => $message,
        ]);
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

    protected function recordSubtitleProgress(
        Transcription $transcription,
        int $frame,
        int $framesTotal,
        float $progress,
    ): void {
        $progress = round(max(0, min(100, $progress)), 1);
        $meta = $transcription->meta ?? [];
        $current = (float) ($meta['subtitle_progress_percent'] ?? 0.0);

        if ($progress <= $current) {
            return;
        }

        $meta['subtitle_progress_percent'] = $progress;
        $meta['subtitle_frames_total'] = $framesTotal;
        $meta['subtitle_frame'] = $frame;

        $transcription->update([
            'meta' => $meta,
        ]);
    }

    protected function downloadToLocal(FilesystemAdapter $disk, string $storagePath, string $localPath, string $driver): void
    {
        $maxAttempts = max(1, (int) config('transcribe.download.max_attempts', 3));
        $backoffSeconds = max(1, (int) config('transcribe.download.backoff_seconds', 5));
        $maxInMemoryBytes = max(1, (int) config('transcribe.download.max_in_memory_mb', 200)) * 1024 * 1024;
        $useTemporaryUrl = (bool) config('transcribe.download.use_temporary_url', true);
        $expectedSize = null;

        try {
            $expectedSize = $disk->size($storagePath);
        } catch (Throwable $exception) {
            Log::warning('Unable to resolve storage size before download', [
                'storage_path' => $storagePath,
                'message' => $exception->getMessage(),
            ]);
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                File::ensureDirectoryExists(dirname($localPath));
                File::delete($localPath);

                if ($expectedSize !== null && $expectedSize > $maxInMemoryBytes) {
                    $this->downloadViaStream($disk, $storagePath, $localPath, $expectedSize);
                } elseif ($driver === 's3' && $useTemporaryUrl) {
                    $this->downloadViaTemporaryUrl($disk, $storagePath, $localPath);
                } elseif ($expectedSize !== null && $expectedSize <= $maxInMemoryBytes) {
                    $this->downloadViaGet($disk, $storagePath, $localPath);
                } else {
                    $this->downloadViaStream($disk, $storagePath, $localPath, $expectedSize);
                }

                clearstatcache(true, $localPath);
                $localSize = File::exists($localPath) ? (int) File::size($localPath) : 0;

                if ($localSize === 0) {
                    throw new RuntimeException('Downloaded file is empty.');
                }

                if ($expectedSize !== null && $localSize !== (int) $expectedSize) {
                    throw new RuntimeException("Downloaded size mismatch. Expected {$expectedSize}, got {$localSize}.");
                }

                Log::info('Transcription start: download complete', [
                    'storage_path' => $storagePath,
                    'local_path' => $localPath,
                    'size_bytes' => $localSize,
                    'attempt' => $attempt,
                ]);

                return;
            } catch (Throwable $exception) {
                Log::warning('Transcription start: download failed', [
                    'storage_path' => $storagePath,
                    'local_path' => $localPath,
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                sleep($backoffSeconds * $attempt);
            }
        }
    }

    protected function downloadViaGet(FilesystemAdapter $disk, string $storagePath, string $localPath): void
    {
        $contents = $disk->get($storagePath);

        if ($contents === false || $contents === '') {
            throw new RuntimeException("Unable to download file contents: {$storagePath}");
        }

        $bytes = file_put_contents($localPath, $contents);

        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException("Unable to write local file: {$localPath}");
        }
    }

    protected function downloadViaTemporaryUrl(FilesystemAdapter $disk, string $storagePath, string $localPath): void
    {
        $expiresAt = now()->addMinutes((int) config('transcribe.download.url_expiration_minutes', 60));
        $timeoutSeconds = (int) config('transcribe.download.http_timeout_seconds', 3600);
        $connectTimeoutSeconds = (int) config('transcribe.download.http_connect_timeout_seconds', 10);

        $url = $disk->temporaryUrl($storagePath, $expiresAt);

        $response = Http::connectTimeout($connectTimeoutSeconds)
            ->timeout($timeoutSeconds)
            ->withOptions(['sink' => $localPath])
            ->get($url);

        $response->throw();

        if (! File::exists($localPath)) {
            throw new RuntimeException("Temporary URL download failed for {$storagePath}.");
        }
    }

    protected function downloadViaStream(FilesystemAdapter $disk, string $storagePath, string $localPath, ?int $expectedSize): void
    {
        $readStream = $disk->readStream($storagePath);

        if ($readStream === false) {
            throw new RuntimeException("Unable to read file from storage: {$storagePath}");
        }

        File::ensureDirectoryExists(dirname($localPath));
        $writeStream = fopen($localPath, 'w');

        if (! $writeStream) {
            if (is_resource($readStream)) {
                fclose($readStream);
            }

            throw new RuntimeException("Unable to write local file: {$localPath}");
        }

        $chunkBytes = max(1024 * 1024, (int) config('transcribe.download.chunk_bytes', 8 * 1024 * 1024));
        $progressBytes = max(1024 * 1024, (int) config('transcribe.download.progress_bytes', 50 * 1024 * 1024));
        $written = 0;
        $nextLogAt = $progressBytes;

        while (! feof($readStream)) {
            $buffer = fread($readStream, $chunkBytes);

            if ($buffer === false) {
                fclose($readStream);
                fclose($writeStream);
                throw new RuntimeException('Error while reading from storage stream.');
            }

            if ($buffer === '') {
                continue;
            }

            $bytes = fwrite($writeStream, $buffer);

            if ($bytes === false) {
                fclose($readStream);
                fclose($writeStream);
                throw new RuntimeException('Error while writing to local file.');
            }

            $written += $bytes;

            if ($written >= $nextLogAt) {
                Log::info('Transcription start: download progress', [
                    'storage_path' => $storagePath,
                    'written_bytes' => $written,
                    'expected_bytes' => $expectedSize,
                ]);
                $nextLogAt += $progressBytes;
            }
        }

        fclose($readStream);
        fclose($writeStream);
    }

    protected function storeFromLocal(FilesystemAdapter $disk, string $localPath, string $storagePath): void
    {
        $stream = fopen($localPath, 'r');

        if (! $stream) {
            throw new RuntimeException("Unable to open local file: {$localPath}");
        }

        $disk->put($storagePath, $stream);
        fclose($stream);
    }

    public function failed(Throwable $exception): void
    {
        $transcription = Transcription::query()->find($this->transcriptionId);

        if (! $transcription) {
            return;
        }

        $message = $this->sanitizeText($exception->getMessage());

        if ($message === '') {
            $message = 'Transcription failed.';
        }

        $transcription->update([
            'status' => TranscriptionStatus::Failed,
            'error_message' => $message,
        ]);
    }
}
