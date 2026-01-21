<?php

namespace App\Jobs;

use App\Enums\TranscriptionChunkStatus;
use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use App\Models\TranscriptionChunk;
use App\Services\Transcription\ChunkBuilder;
use App\Services\Transcription\MediaProcessor;
use App\Services\Transcription\SilenceDetector;
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

    public int $timeout = 1200;

    public function __construct(public int $transcriptionId) {}

    public function handle(MediaProcessor $mediaProcessor, SilenceDetector $silenceDetector, ChunkBuilder $chunkBuilder): void
    {
        $transcription = Transcription::query()->with('chunks')->findOrFail($this->transcriptionId);

        if ($transcription->status === TranscriptionStatus::Completed) {
            return;
        }

        $disk = Storage::disk($transcription->storage_disk);
        $tempDirectory = rtrim((string) config('transcribe.temp_directory'), '/').'/'.$transcription->public_id;

        File::ensureDirectoryExists($tempDirectory);

        $sourceLocalPath = $tempDirectory.'/source.mp4';
        $this->streamToLocal($disk, $transcription->storage_path, $sourceLocalPath);

        $audioLocalPath = $tempDirectory.'/audio.wav';
        $audioStoragePath = $transcription->audio_path ?: $this->audioStoragePath($transcription);

        if (! $transcription->audio_path || ! $disk->exists($transcription->audio_path)) {
            $mediaProcessor->extractAudio($sourceLocalPath, $audioLocalPath);
            $this->storeFromLocal($disk, $audioLocalPath, $audioStoragePath);
            $transcription->audio_path = $audioStoragePath;
        } else {
            $this->streamToLocal($disk, $audioStoragePath, $audioLocalPath);
        }

        $duration = $mediaProcessor->probeDuration($audioLocalPath);

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

        File::delete([$sourceLocalPath, $audioLocalPath]);
    }

    protected function audioStoragePath(Transcription $transcription): string
    {
        return "transcriptions/{$transcription->public_id}/audio.wav";
    }

    protected function chunkStoragePath(Transcription $transcription, int $sequence): string
    {
        return "transcriptions/{$transcription->public_id}/chunks/{$sequence}.wav";
    }

    protected function streamToLocal(FilesystemAdapter $disk, string $storagePath, string $localPath): void
    {
        $readStream = $disk->readStream($storagePath);

        if ($readStream === false) {
            throw new RuntimeException("Unable to read file from storage: {$storagePath}");
        }

        File::ensureDirectoryExists(dirname($localPath));
        $writeStream = fopen($localPath, 'w');

        if (! $writeStream) {
            throw new RuntimeException("Unable to write local file: {$localPath}");
        }

        stream_copy_to_stream($readStream, $writeStream);

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

        $transcription->update([
            'status' => TranscriptionStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
