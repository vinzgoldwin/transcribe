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

    public int $timeout;

    public function __construct(public int $chunkId)
    {
        $this->timeout = (int) config('transcribe.queue.process_timeout_seconds', 1800);
    }

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

            $sttSegments = $this->sanitizePayload($sttProvider->transcribe($localPath, 'ja'));
            $chunk->stt_payload = $sttSegments;

            $stopAfter = $this->resolveStopAfter($transcription);
            $translatedSegments = $stopAfter === 'whisper'
                ? $this->buildWhisperOnlySegments($sttSegments)
                : $this->buildTranslatedSegments($sttSegments, $translator);
            $translatedSegments = $this->sanitizePayload($translatedSegments);

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

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $sttSegments
     * @return array<int, array{start: float, end: float, text: string, text_jp: string}>
     */
    protected function buildWhisperOnlySegments(array $sttSegments): array
    {
        return collect($sttSegments)
            ->values()
            ->map(fn (array $segment) => [
                'start' => (float) $segment['start'],
                'end' => (float) $segment['end'],
                'text' => (string) $segment['text'],
                'text_jp' => (string) $segment['text'],
            ])
            ->filter(fn (array $segment) => trim($segment['text']) !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $sttSegments
     * @return array<int, array{start: float, end: float, text: string, text_jp: string}>
     */
    protected function buildTranslatedSegments(array $sttSegments, Translator $translator): array
    {
        $jpTexts = collect($sttSegments)->pluck('text')->all();
        $translations = $translator->translate($jpTexts, 'JA', 'EN');

        return collect($sttSegments)
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
    }

    protected function resolveStopAfter(?\App\Models\Transcription $transcription): string
    {
        $stopAfter = (string) ($transcription?->meta['stop_after'] ?? config('transcribe.pipeline.stop_after', 'whisper'));
        $normalized = strtolower(trim($stopAfter));

        if ($normalized === 'whisper') {
            return 'whisper';
        }

        return in_array($normalized, ['azure', 'deepl'], true) ? 'azure' : 'whisper';
    }

    protected function sanitizePayload(mixed $payload): mixed
    {
        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                $payload[$key] = $this->sanitizePayload($value);
            }

            return $payload;
        }

        if (! is_string($payload)) {
            return $payload;
        }

        if ($payload === '' || preg_match('//u', $payload) === 1) {
            return $payload;
        }

        if (function_exists('mb_convert_encoding')) {
            $cleaned = (string) mb_convert_encoding($payload, 'UTF-8', 'UTF-8');

            if (preg_match('//u', $cleaned) === 1) {
                return $cleaned;
            }
        }

        return '';
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
