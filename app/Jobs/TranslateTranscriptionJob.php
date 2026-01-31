<?php

namespace App\Jobs;

use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use App\Services\Transcription\OverlapDeduplicator;
use App\Services\Transcription\SrtVttBuilder;
use App\Services\Transcription\SubtitleFormatter;
use App\Services\Transcription\Translation\Translator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TranslateTranscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [120, 300, 600];

    public int $timeout = 600;

    public int $batchSize = 50;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $transcriptionId)
    {
        $this->onQueue((string) config('transcribe.translation_queue', 'translations'));
    }

    /**
     * Execute the job.
     */
    public function handle(
        Translator $translator,
        SubtitleFormatter $formatter,
        SrtVttBuilder $builder,
        OverlapDeduplicator $deduplicator,
    ): void {
        $transcription = Transcription::query()->findOrFail($this->transcriptionId);

        if (! in_array($transcription->status, [TranscriptionStatus::AwaitingTranslation, TranscriptionStatus::Processing], true)) {
            return;
        }

        $transcription->update([
            'status' => TranscriptionStatus::Processing,
            'error_message' => null,
        ]);

        $segments = $transcription->segments()
            ->orderBy('sequence')
            ->get();

        $rawSourceLanguage = $this->resolveSourceLanguage($transcription);
        $segments = $this->filterSegments($segments, $rawSourceLanguage, $transcription);

        if ($segments->isEmpty()) {
            $transcription->update([
                'status' => TranscriptionStatus::Failed,
                'error_message' => 'No subtitle segments available for translation.',
            ]);

            return;
        }

        $exportSegments = [];
        $sourceLanguage = $this->resolveTranslationSourceLanguage($transcription);
        $targetLanguage = $this->resolveTranslationTargetLanguage();

        $batchSize = (int) config('transcribe.translation.batch_size', $this->batchSize);
        $throttleMs = (int) config('transcribe.translation.throttle_ms', 300);

        foreach ($segments->chunk($batchSize) as $chunkIndex => $segmentChunk) {
            if ($chunkIndex > 0 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }

            $translations = $translator->translate(
                $segmentChunk->pluck('text_jp')->all(),
                $sourceLanguage,
                $targetLanguage,
            );

            $segmentChunk->values()->each(function (TranscriptionSegment $segment, int $index) use ($formatter, $translations): void {
                $translatedText = trim((string) ($translations[$index] ?? ''));
                $textEn = $translatedText !== '' ? $translatedText : (string) $segment->text_en;
                $formattedText = $translatedText !== ''
                    ? $formatter->wrapText($translatedText)
                    : (string) $segment->formatted_text;

                $segment->update([
                    'text_en' => $textEn,
                    'formatted_text' => $formattedText,
                ]);
            });
        }

        $segmentsCollection = $transcription->segments()
            ->orderBy('start_seconds')
            ->get()
            ->map(fn (TranscriptionSegment $segment) => [
                'id' => $segment->id,
                'start_seconds' => $segment->start_seconds,
                'end_seconds' => $segment->end_seconds,
                'text_en' => $segment->text_en,
                'text_jp' => $segment->text_jp,
                'formatted_text' => $segment->formatted_text,
            ]);

        $deduped = $deduplicator->dedupe($segmentsCollection, (float) config('transcribe.subtitle.gap_seconds'));
        $sequence = 1;
        $exportSegments = [];

        foreach ($deduped as $segment) {
            TranscriptionSegment::query()
                ->whereKey($segment['id'])
                ->update([
                    'sequence' => $sequence,
                    'start_seconds' => $segment['start_seconds'],
                    'end_seconds' => $segment['end_seconds'],
                    'formatted_text' => $segment['formatted_text'],
                ]);

            $exportSegments[] = [
                'start' => $segment['start_seconds'],
                'end' => $segment['end_seconds'],
                'text' => $segment['formatted_text'],
            ];

            $sequence++;
        }

        $disk = Storage::disk($transcription->storage_disk);
        $outputPrefix = $this->storagePrefix()."/{$transcription->public_id}/output";
        $baseName = $this->outputBaseName($transcription);
        $srtPath = "{$outputPrefix}/{$baseName}_en.srt";
        $vttPath = "{$outputPrefix}/{$baseName}_en.vtt";

        $disk->put($srtPath, $builder->buildSrt($exportSegments));
        $disk->put($vttPath, $builder->buildVtt($exportSegments));

        $transcription->update([
            'status' => TranscriptionStatus::Completed,
            'srt_path' => $srtPath,
            'vtt_path' => $vttPath,
            'completed_at' => now(),
        ]);
    }

    protected function storagePrefix(): string
    {
        return trim((string) config('transcribe.storage_prefix', 'transcriptions'), '/');
    }

    protected function outputBaseName(Transcription $transcription): string
    {
        $filename = (string) ($transcription->original_filename ?? '');
        $baseName = trim((string) pathinfo($filename, PATHINFO_FILENAME));

        if ($baseName === '') {
            $baseName = 'transcription';
        }

        return str_replace(['..', '/', '\\'], '-', $baseName);
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

    protected function resolveTranslationSourceLanguage(Transcription $transcription): string
    {
        $language = $this->resolveSourceLanguage($transcription);
        $supported = (array) config('transcribe.language.supported', []);
        $driver = (string) config('transcribe.providers.translation.driver', 'azure');
        $translationMap = $supported[$language]['translation'] ?? [];

        return (string) ($translationMap[$driver] ?? $translationMap['default'] ?? $language);
    }

    protected function resolveTranslationTargetLanguage(): string
    {
        return 'EN';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TranscriptionSegment>  $segments
     * @return \Illuminate\Support\Collection<int, TranscriptionSegment>
     */
    protected function filterSegments($segments, string $sourceLanguage, Transcription $transcription)
    {
        if ($sourceLanguage !== 'zh') {
            return $segments;
        }

        $subtitleSource = $transcription->meta['subtitle_source'] ?? null;
        $minDuration = $subtitleSource === 'ocr'
            ? (float) config('transcribe.ocr.min_segment_seconds', 0.9)
            : null;

        $keep = $segments->filter(function (TranscriptionSegment $segment) use ($minDuration): bool {
            if ($minDuration !== null) {
                $duration = (float) $segment->end_seconds - (float) $segment->start_seconds;

                if ($duration < $minDuration) {
                    return false;
                }
            }

            return $this->isLikelyChineseSubtitle((string) $segment->text_jp);
        })->values();

        if ($keep->count() === $segments->count()) {
            return $segments;
        }

        $keepIds = $keep->pluck('id')->all();

        if ($keepIds !== []) {
            $transcription->segments()
                ->whereNotIn('id', $keepIds)
                ->delete();
        } else {
            $transcription->segments()->delete();
        }

        return $keep;
    }

    protected function isLikelyChineseSubtitle(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        $hanCount = preg_match_all('/\p{Han}/u', $text) ?: 0;
        $latinCount = preg_match_all('/[A-Za-z]/u', $text) ?: 0;
        $digitCount = preg_match_all('/\d/u', $text) ?: 0;
        $total = $hanCount + $latinCount + $digitCount;

        if ($total === 0 || $hanCount === 0) {
            return false;
        }

        $hanRatio = $hanCount / $total;
        $latinRatio = ($latinCount + $digitCount) / $total;

        if ($total <= 2) {
            return $hanRatio >= 0.5 && $latinRatio <= 0.2;
        }

        if ($hanRatio < 0.6) {
            return false;
        }

        if ($latinRatio > 0.2 && $hanRatio < 0.8) {
            return false;
        }

        return true;
    }

    public function failed(Throwable $exception): void
    {
        $transcription = Transcription::query()->find($this->transcriptionId);

        if (! $transcription) {
            return;
        }

        $transcription->update([
            'status' => TranscriptionStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
