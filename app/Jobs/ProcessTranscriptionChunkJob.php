<?php

namespace App\Jobs;

use App\Enums\TranscriptionChunkStatus;
use App\Enums\TranscriptionStatus;
use App\Models\TranscriptionChunk;
use App\Models\TranscriptionSegment;
use App\Services\Transcription\Stt\SttProvider;
use App\Services\Transcription\SubtitleFormatter;
use App\Services\Transcription\Translation\Translator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessTranscriptionChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 180, 300, 600];

    public int $timeout = 900;

    public function __construct(public int $chunkId) {}

    public function handle(SttProvider $sttProvider, Translator $translator, SubtitleFormatter $formatter): void
    {
        $chunk = TranscriptionChunk::query()
            ->with('transcription')
            ->findOrFail($this->chunkId);

        if ($chunk->status === TranscriptionChunkStatus::Completed) {
            return;
        }

        $chunk->update([
            'status' => TranscriptionChunkStatus::Processing,
            'error_message' => null,
        ]);

        $transcription = $chunk->transcription;
        $disk = Storage::disk($transcription->storage_disk);

        if (! $chunk->audio_path || ! $disk->exists($chunk->audio_path)) {
            $chunk->update([
                'status' => TranscriptionChunkStatus::Failed,
                'error_message' => 'Missing chunk audio file.',
            ]);

            return;
        }

        $tempDirectory = rtrim((string) config('transcribe.temp_directory'), '/').'/'.$transcription->public_id.'/chunks';
        File::ensureDirectoryExists($tempDirectory);

        $localPath = $tempDirectory.'/chunk-'.$chunk->sequence.'.wav';

        try {
            $this->streamToLocal($disk, $chunk->audio_path, $localPath);

            $sttSegments = $sttProvider->transcribe($localPath, 'ja');
            $chunk->stt_payload = $sttSegments;

            $jpTexts = collect($sttSegments)->pluck('text')->all();
            $translations = $translator->translate($jpTexts, 'JA', 'EN');

            $translatedSegments = collect($sttSegments)
                ->values()
                ->map(function (array $segment, int $index) use ($translations): array {
                    return [
                        'start' => (float) $segment['start'],
                        'end' => (float) $segment['end'],
                        'text' => (string) ($translations[$index] ?? ''),
                        'text_jp' => (string) $segment['text'],
                    ];
                })
                ->filter(fn (array $segment) => trim($segment['text']) !== '')
                ->values()
                ->all();

            $chunk->translated_payload = $translatedSegments;

            $formattedSegments = $formatter->format(
                collect($translatedSegments)
                    ->map(fn (array $segment) => [
                        'start' => $segment['start'],
                        'end' => $segment['end'],
                        'text' => $segment['text'],
                        'text_jp' => $segment['text_jp'],
                    ])
                    ->all(),
            );

            TranscriptionSegment::query()
                ->where('transcription_chunk_id', $chunk->id)
                ->delete();

            $segmentsToInsert = [];
            $sequence = 1;

            foreach ($formattedSegments as $formattedSegment) {
                $segmentsToInsert[] = [
                    'transcription_id' => $transcription->id,
                    'transcription_chunk_id' => $chunk->id,
                    'sequence' => $sequence,
                    'start_seconds' => round($chunk->start_seconds + $formattedSegment['start'], 3),
                    'end_seconds' => round($chunk->start_seconds + $formattedSegment['end'], 3),
                    'text_jp' => (string) $formattedSegment['text_jp'],
                    'text_en' => (string) $formattedSegment['text'],
                    'formatted_text' => (string) $formattedSegment['formatted_text'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $sequence++;
            }

            if ($segmentsToInsert !== []) {
                TranscriptionSegment::query()->insert($segmentsToInsert);
            }

            $chunk->update([
                'status' => TranscriptionChunkStatus::Completed,
                'segment_count' => count($segmentsToInsert),
                'completed_at' => now(),
                'translated_payload' => $translatedSegments,
                'stt_payload' => $sttSegments,
            ]);

            $transcription->refresh();
            $completedCount = $transcription->chunks()->where('status', TranscriptionChunkStatus::Completed)->count();

            $transcription->update([
                'chunks_completed' => $completedCount,
                'status' => TranscriptionStatus::Processing,
            ]);

            if ($completedCount >= $transcription->chunks_total) {
                FinalizeTranscriptionJob::dispatch($transcription->id);
            }
        } catch (Throwable $exception) {
            $chunk->update([
                'status' => TranscriptionChunkStatus::Failed,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            File::delete($localPath);
        }
    }

    protected function streamToLocal(FilesystemAdapter $disk, string $storagePath, string $localPath): void
    {
        $readStream = $disk->readStream($storagePath);

        if ($readStream === false) {
            throw new RuntimeException("Unable to read file from storage: {$storagePath}");
        }

        $writeStream = fopen($localPath, 'w');

        if (! $writeStream) {
            throw new RuntimeException("Unable to write local file: {$localPath}");
        }

        stream_copy_to_stream($readStream, $writeStream);

        fclose($readStream);
        fclose($writeStream);
    }

    public function failed(Throwable $exception): void
    {
        $chunk = TranscriptionChunk::query()->with('transcription')->find($this->chunkId);

        if (! $chunk) {
            return;
        }

        $chunk->update([
            'status' => TranscriptionChunkStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);

        $chunk->transcription?->update([
            'status' => TranscriptionStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
