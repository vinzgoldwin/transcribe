<?php

namespace App\Jobs;

use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use App\Models\TranscriptionSegment;
use App\Services\Transcription\OverlapDeduplicator;
use App\Services\Transcription\SrtVttBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinalizeTranscriptionJob implements ShouldQueue
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

    public function __construct(public int $transcriptionId) {}

    public function handle(OverlapDeduplicator $deduplicator, SrtVttBuilder $builder): void
    {
        $transcription = Transcription::query()->findOrFail($this->transcriptionId);

        if ($transcription->status === TranscriptionStatus::Completed) {
            return;
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
        $keepIds = collect($deduped)->pluck('id')->all();

        TranscriptionSegment::query()
            ->where('transcription_id', $transcription->id)
            ->whereNotIn('id', $keepIds)
            ->delete();

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
        $srtPath = "{$outputPrefix}/transcription.srt";
        $vttPath = "{$outputPrefix}/transcription.vtt";

        $disk->put($srtPath, $builder->buildSrt($exportSegments));
        $disk->put($vttPath, $builder->buildVtt($exportSegments));

        $stopAfter = $this->resolveStopAfter($transcription);
        $status = $stopAfter === 'whisper'
            ? TranscriptionStatus::AwaitingTranslation
            : TranscriptionStatus::Completed;

        $transcription->update([
            'status' => $status,
            'srt_path' => $srtPath,
            'vtt_path' => $vttPath,
            'completed_at' => now(),
        ]);
    }

    protected function storagePrefix(): string
    {
        return trim((string) config('transcribe.storage_prefix', 'transcriptions'), '/');
    }

    protected function resolveStopAfter(Transcription $transcription): string
    {
        $stopAfter = (string) ($transcription->meta['stop_after'] ?? config('transcribe.pipeline.stop_after', 'deepl'));
        $normalized = strtolower(trim($stopAfter));

        return $normalized === 'whisper' ? 'whisper' : 'deepl';
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
