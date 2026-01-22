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

    public function handle(MediaProcessor $mediaProcessor, SilenceDetector $silenceDetector, ChunkBuilder $chunkBuilder): void
    {
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

        $transcription->update([
            'status' => TranscriptionStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
