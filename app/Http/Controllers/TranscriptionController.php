<?php

namespace App\Http\Controllers;

use App\Enums\TranscriptionStatus;
use App\Http\Requests\CompleteTranscriptionUploadRequest;
use App\Http\Requests\StoreTranscriptionRequest;
use App\Jobs\StartTranscriptionJob;
use App\Models\Transcription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TranscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $transcriptions = Transcription::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (Transcription $transcription) => [
                'id' => $transcription->public_id,
                'filename' => $transcription->original_filename,
                'status' => $transcription->status?->value,
                'created_at' => $transcription->created_at?->toIso8601String(),
                'duration_seconds' => $transcription->duration_seconds,
                'chunks_total' => $transcription->chunks_total,
                'chunks_completed' => $transcription->chunks_completed,
                'show_url' => route('transcriptions.show', $transcription),
            ]);

        return Inertia::render('Transcriptions/Index', [
            'transcriptions' => $transcriptions,
            'upload' => [
                'expires_minutes' => (int) config('transcribe.upload_expiration_minutes'),
                'storage_disk' => (string) config('transcribe.storage_disk'),
                'default_stop_after' => (string) config('transcribe.pipeline.stop_after', 'deepl'),
            ],
        ]);
    }

    public function store(StoreTranscriptionRequest $request): JsonResponse
    {
        $storageDisk = (string) config('transcribe.storage_disk');
        $publicId = (string) Str::uuid();
        $stopAfter = $this->normalizeStopAfter(
            $request->string('stop_after')->toString(),
        );

        $transcription = Transcription::query()->create([
            'user_id' => $request->user()->id,
            'public_id' => $publicId,
            'original_filename' => $request->string('filename')->toString(),
            'content_type' => $request->string('content_type')->toString(),
            'size_bytes' => $request->integer('size_bytes'),
            'storage_disk' => $storageDisk,
            'storage_path' => $this->buildStoragePath($publicId, $request->string('filename')->toString()),
            'status' => TranscriptionStatus::Uploading,
            'meta' => [
                'stop_after' => $stopAfter,
            ],
        ]);

        [$uploadUrl, $headers] = $this->presignUpload($transcription);

        return response()->json([
            'transcription' => [
                'id' => $transcription->public_id,
                'status' => $transcription->status->value,
                'show_url' => route('transcriptions.show', $transcription),
            ],
            'upload' => [
                'method' => 'PUT',
                'url' => $uploadUrl,
                'headers' => $headers,
            ],
            'complete_url' => route('transcriptions.complete', $transcription),
        ]);
    }

    public function upload(Request $request, Transcription $transcription): JsonResponse
    {
        $this->authorizeTranscription($request, $transcription);

        $driver = config("filesystems.disks.{$transcription->storage_disk}.driver");

        if ($driver !== 'local') {
            return response()->json(['message' => 'Local upload disabled for this disk.'], HttpResponse::HTTP_NOT_FOUND);
        }

        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid upload signature.'], HttpResponse::HTTP_FORBIDDEN);
        }

        $stream = fopen('php://input', 'r');

        if (! $stream) {
            throw new RuntimeException('Unable to read upload stream.');
        }

        Storage::disk($transcription->storage_disk)->makeDirectory(dirname($transcription->storage_path));
        $stored = Storage::disk($transcription->storage_disk)->writeStream($transcription->storage_path, $stream);
        fclose($stream);

        if (! $stored) {
            throw new RuntimeException('Unable to store the uploaded file.');
        }

        return response()->json(['status' => 'uploaded']);
    }

    public function complete(CompleteTranscriptionUploadRequest $request, Transcription $transcription): JsonResponse
    {
        $this->authorizeTranscription($request, $transcription);

        $disk = Storage::disk($transcription->storage_disk);

        if (! $disk->exists($transcription->storage_path)) {
            return response()->json([
                'message' => 'Upload not found in storage.',
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $transcription->update([
            'status' => TranscriptionStatus::Uploaded,
            'error_message' => null,
        ]);

        StartTranscriptionJob::dispatch($transcription->id);

        return response()->json([
            'status' => $transcription->status->value,
            'show_url' => route('transcriptions.show', $transcription),
        ]);
    }

    public function show(Request $request, Transcription $transcription): Response
    {
        $this->authorizeTranscription($request, $transcription);

        $transcription->loadCount('chunks');

        return Inertia::render('Transcriptions/Show', [
            'transcription' => [
                'id' => $transcription->public_id,
                'filename' => $transcription->original_filename,
                'status' => $transcription->status?->value,
                'duration_seconds' => $transcription->duration_seconds,
                'chunks_total' => $transcription->chunks_total,
                'chunks_completed' => $transcription->chunks_completed,
                'created_at' => $transcription->created_at?->toIso8601String(),
                'error_message' => $transcription->error_message,
                'srt_ready' => $transcription->srt_path !== null,
                'vtt_ready' => $transcription->vtt_path !== null,
                'download_srt_url' => $transcription->srt_path
                    ? route('transcriptions.download', [$transcription, 'srt'])
                    : null,
                'download_vtt_url' => $transcription->vtt_path
                    ? route('transcriptions.download', [$transcription, 'vtt'])
                    : null,
            ],
        ]);
    }

    public function status(Request $request, Transcription $transcription): JsonResponse
    {
        $this->authorizeTranscription($request, $transcription);

        return response()->json([
            'id' => $transcription->public_id,
            'status' => $transcription->status?->value,
            'chunks_total' => $transcription->chunks_total,
            'chunks_completed' => $transcription->chunks_completed,
            'error_message' => $transcription->error_message,
            'srt_ready' => $transcription->srt_path !== null,
            'vtt_ready' => $transcription->vtt_path !== null,
        ]);
    }

    public function download(Request $request, Transcription $transcription, string $format): RedirectResponse|StreamedResponse
    {
        $this->authorizeTranscription($request, $transcription);

        if (! in_array($transcription->status, [TranscriptionStatus::Completed, TranscriptionStatus::AwaitingTranslation], true)) {
            abort(404);
        }

        $path = match ($format) {
            'srt' => $transcription->srt_path,
            'vtt' => $transcription->vtt_path,
            default => null,
        };

        if (! $path) {
            abort(404);
        }

        $disk = Storage::disk($transcription->storage_disk);

        try {
            $url = $disk->temporaryUrl($path, now()->addMinutes(10));

            return redirect()->away($url);
        } catch (Throwable) {
            return response()->streamDownload(function () use ($disk, $path): void {
                echo $disk->get($path);
            }, basename($path));
        }
    }

    protected function authorizeTranscription(Request $request, Transcription $transcription): void
    {
        if ($transcription->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    protected function presignUpload(Transcription $transcription): array
    {
        $disk = Storage::disk($transcription->storage_disk);
        $expiresAt = now()->addMinutes((int) config('transcribe.upload_expiration_minutes'));
        $driver = config("filesystems.disks.{$transcription->storage_disk}.driver");

        if ($driver === 's3') {
            ['url' => $url, 'headers' => $headers] = $disk->temporaryUploadUrl($transcription->storage_path, $expiresAt);

            return [$url, $headers];
        }

        $url = URL::temporarySignedRoute(
            'transcriptions.upload',
            $expiresAt,
            ['transcription' => $transcription->public_id],
        );

        return [$url, ['Content-Type' => $transcription->content_type]];
    }

    protected function buildStoragePath(string $publicId, string $filename): string
    {
        $safeName = str_replace(['..', '/', '\\'], '-', $filename);

        return $this->storagePrefix()."/{$publicId}/{$safeName}";
    }

    protected function storagePrefix(): string
    {
        return trim((string) config('transcribe.storage_prefix', 'transcriptions'), '/');
    }

    protected function normalizeStopAfter(string $stopAfter): string
    {
        $normalized = strtolower(trim($stopAfter));

        if ($normalized === '') {
            $normalized = (string) config('transcribe.pipeline.stop_after', 'deepl');
            $normalized = strtolower(trim($normalized));
        }

        return $normalized === 'whisper' ? 'whisper' : 'deepl';
    }
}
