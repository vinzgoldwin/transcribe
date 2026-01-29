<?php

namespace App\Jobs;

use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use App\Models\TranscriptionSegment;
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
    public function handle(Translator $translator, SubtitleFormatter $formatter, SrtVttBuilder $builder): void
    {
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

        if ($segments->isEmpty()) {
            $transcription->update([
                'status' => TranscriptionStatus::Failed,
                'error_message' => 'No subtitle segments available for translation.',
            ]);

            return;
        }

        $exportSegments = [];

        $batchSize = (int) config('transcribe.translation.batch_size', $this->batchSize);
        $throttleMs = (int) config('transcribe.translation.throttle_ms', 300);

        foreach ($segments->chunk($batchSize) as $chunkIndex => $segmentChunk) {
            if ($chunkIndex > 0 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }

            $translations = $translator->translate(
                $segmentChunk->pluck('text_jp')->all(),
                'JA',
                'EN',
            );

            $segmentChunk->values()->each(function (TranscriptionSegment $segment, int $index) use ($formatter, $translations, &$exportSegments): void {
                $translatedText = trim((string) ($translations[$index] ?? ''));
                $textEn = $translatedText !== '' ? $translatedText : (string) $segment->text_en;
                $formattedText = $translatedText !== ''
                    ? $formatter->wrapText($translatedText)
                    : (string) $segment->formatted_text;

                $segment->update([
                    'text_en' => $textEn,
                    'formatted_text' => $formattedText,
                ]);

                $exportSegments[] = [
                    'start' => $segment->start_seconds,
                    'end' => $segment->end_seconds,
                    'text' => $formattedText,
                ];
            });
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
