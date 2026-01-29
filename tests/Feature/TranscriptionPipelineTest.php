<?php

use App\Enums\TranscriptionChunkStatus;
use App\Enums\TranscriptionStatus;
use App\Jobs\FinalizeTranscriptionJob;
use App\Jobs\ProcessTranscriptionChunkJob;
use App\Jobs\TranslateTranscriptionJob;
use App\Models\Transcription;
use App\Models\TranscriptionChunk;
use App\Models\TranscriptionSegment;
use App\Services\Transcription\OverlapDeduplicator;
use App\Services\Transcription\SrtVttBuilder;
use App\Services\Transcription\Stt\SttProvider;
use App\Services\Transcription\SubtitleFormatter;
use App\Services\Transcription\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

uses(RefreshDatabase::class);

it('stores storage path on creation', function () {
    $user = \App\Models\User::factory()->create();
    config(['transcribe.storage_prefix' => 'transcriptions']);

    $response = $this->actingAs($user)
        ->withoutMiddleware(ValidateSessionWithWorkOS::class)
        ->postJson(route('transcriptions.store'), [
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'size_bytes' => 1234,
            'stop_after' => 'whisper',
        ]);

    $response->assertSuccessful();

    $publicId = $response->json('transcription.id');
    $transcription = Transcription::query()->where('public_id', $publicId)->first();

    expect($transcription)->not->toBeNull()
        ->and($transcription->storage_path)->toBe("transcriptions/{$publicId}/clip.mp4")
        ->and($transcription->meta['stop_after'])->toBe('whisper');
});

it('stores azure stop after on creation', function () {
    $user = \App\Models\User::factory()->create();
    config(['transcribe.storage_prefix' => 'transcriptions']);

    $response = $this->actingAs($user)
        ->withoutMiddleware(ValidateSessionWithWorkOS::class)
        ->postJson(route('transcriptions.store'), [
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'size_bytes' => 1234,
            'stop_after' => 'azure',
        ]);

    $response->assertSuccessful();

    $publicId = $response->json('transcription.id');
    $transcription = Transcription::query()->where('public_id', $publicId)->first();

    expect($transcription)->not->toBeNull()
        ->and($transcription->meta['stop_after'])->toBe('azure');
});

it('builds storage paths using a custom prefix', function () {
    $user = \App\Models\User::factory()->create();
    config(['transcribe.storage_prefix' => 'transcribe']);

    $response = $this->actingAs($user)
        ->withoutMiddleware(ValidateSessionWithWorkOS::class)
        ->postJson(route('transcriptions.store'), [
            'filename' => 'movie.mp4',
            'content_type' => 'video/mp4',
            'size_bytes' => 4321,
        ]);

    $response->assertSuccessful();

    $publicId = $response->json('transcription.id');
    $transcription = Transcription::query()->where('public_id', $publicId)->first();

    expect($transcription)->not->toBeNull()
        ->and($transcription->storage_path)->toBe("transcribe/{$publicId}/movie.mp4");
});

it('skips translation when stop after whisper', function () {
    Storage::fake('local');
    config(['transcribe.temp_directory' => storage_path('app/testing')]);

    $transcription = Transcription::factory()->create([
        'storage_disk' => 'local',
        'status' => TranscriptionStatus::Processing,
        'chunks_total' => 1,
        'chunks_completed' => 0,
        'meta' => ['stop_after' => 'whisper'],
    ]);

    $chunk = TranscriptionChunk::factory()->for($transcription)->create([
        'sequence' => 1,
        'audio_path' => 'transcriptions/'.$transcription->public_id.'/chunk-1.wav',
        'status' => TranscriptionChunkStatus::Pending,
        'start_seconds' => 0.0,
        'end_seconds' => 1.0,
    ]);

    Storage::disk('local')->put($chunk->audio_path, 'audio');

    $sttProvider = new class implements SttProvider
    {
        public function transcribe(string $audioPath, string $language): array
        {
            return [
                ['start' => 0.0, 'end' => 1.0, 'text' => 'こんにちは'],
            ];
        }
    };

    $translator = new class implements Translator
    {
        public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
        {
            throw new \RuntimeException('Translator should not be called.');
        }
    };

    $job = new ProcessTranscriptionChunkJob($chunk->id);
    $job->handle($sttProvider, $translator, app(SubtitleFormatter::class));

    $segment = TranscriptionSegment::query()->first();

    expect($segment)->not->toBeNull()
        ->and($segment->text_jp)->toBe('こんにちは')
        ->and($segment->text_en)->toBe('こんにちは');
});

it('sanitizes invalid utf8 in stt payload before storing', function () {
    Storage::fake('local');
    config(['transcribe.temp_directory' => storage_path('app/testing')]);

    $transcription = Transcription::factory()->create([
        'storage_disk' => 'local',
        'status' => TranscriptionStatus::Processing,
        'chunks_total' => 1,
        'chunks_completed' => 0,
        'meta' => ['stop_after' => 'whisper'],
    ]);

    $chunk = TranscriptionChunk::factory()->for($transcription)->create([
        'sequence' => 1,
        'audio_path' => 'transcriptions/'.$transcription->public_id.'/chunk-1.wav',
        'status' => TranscriptionChunkStatus::Pending,
        'start_seconds' => 0.0,
        'end_seconds' => 1.0,
    ]);

    Storage::disk('local')->put($chunk->audio_path, 'audio');

    $invalid = 'hello '.chr(0xC3).chr(0x28);

    $sttProvider = new class($invalid) implements SttProvider
    {
        public function __construct(private string $invalid) {}

        public function transcribe(string $audioPath, string $language): array
        {
            return [
                ['start' => 0.0, 'end' => 1.0, 'text' => $this->invalid],
            ];
        }
    };

    $translator = new class implements Translator
    {
        public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
        {
            throw new \RuntimeException('Translator should not be called.');
        }
    };

    $job = new ProcessTranscriptionChunkJob($chunk->id);
    $job->handle($sttProvider, $translator, app(SubtitleFormatter::class));

    $chunk->refresh();

    $storedText = (string) ($chunk->stt_payload[0]['text'] ?? '');

    expect($storedText)->toBeString()
        ->and(preg_match('//u', $storedText))->toBe(1);
});

it('marks transcription awaiting translation when stop after whisper', function () {
    Storage::fake('local');

    $transcription = Transcription::factory()->create([
        'storage_disk' => 'local',
        'original_filename' => 'sample-video.mp4',
        'status' => TranscriptionStatus::Processing,
        'chunks_total' => 1,
        'chunks_completed' => 1,
        'meta' => ['stop_after' => 'whisper'],
    ]);

    TranscriptionSegment::factory()->for($transcription)->create([
        'sequence' => 1,
        'start_seconds' => 0.0,
        'end_seconds' => 1.25,
        'text_jp' => 'こんにちは',
        'text_en' => 'こんにちは',
        'formatted_text' => 'こんにちは',
    ]);

    $job = new FinalizeTranscriptionJob($transcription->id);
    $job->handle(app(OverlapDeduplicator::class), app(SrtVttBuilder::class));

    $transcription->refresh();
    $outputPrefix = "transcriptions/{$transcription->public_id}/output";

    expect($transcription->status)->toBe(TranscriptionStatus::AwaitingTranslation)
        ->and($transcription->srt_path)->toBe("{$outputPrefix}/sample-video_en.srt")
        ->and($transcription->vtt_path)->toBe("{$outputPrefix}/sample-video_en.vtt");

    Storage::disk('local')->assertExists($transcription->srt_path);
    Storage::disk('local')->assertExists($transcription->vtt_path);
});

it('translates segments after awaiting translation', function () {
    Storage::fake('local');
    config(['transcribe.storage_prefix' => 'transcriptions']);

    $transcription = Transcription::factory()->create([
        'storage_disk' => 'local',
        'status' => TranscriptionStatus::AwaitingTranslation,
        'original_filename' => 'sample-video.mp4',
    ]);

    TranscriptionSegment::factory()
        ->for($transcription)
        ->count(2)
        ->sequence(
            [
                'sequence' => 1,
                'start_seconds' => 0.0,
                'end_seconds' => 1.2,
                'text_jp' => 'こんにちは',
                'text_en' => 'こんにちは',
                'formatted_text' => 'こんにちは',
            ],
            [
                'sequence' => 2,
                'start_seconds' => 1.2,
                'end_seconds' => 2.4,
                'text_jp' => '世界',
                'text_en' => '世界',
                'formatted_text' => '世界',
            ],
        )
        ->create();

    $capturedTexts = [];
    $translator = new class($capturedTexts) implements Translator
    {
        public function __construct(private array &$capturedTexts) {}

        public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
        {
            $this->capturedTexts = $texts;

            return ['Hello', 'World'];
        }
    };

    $job = new TranslateTranscriptionJob($transcription->id);
    $job->handle($translator, app(SubtitleFormatter::class), app(SrtVttBuilder::class));

    $transcription->refresh();
    $outputPrefix = "transcriptions/{$transcription->public_id}/output";
    $segments = TranscriptionSegment::query()
        ->where('transcription_id', $transcription->id)
        ->orderBy('sequence')
        ->get();

    expect($capturedTexts)->toBe(['こんにちは', '世界'])
        ->and($transcription->status)->toBe(TranscriptionStatus::Completed)
        ->and($transcription->srt_path)->toBe("{$outputPrefix}/sample-video_en.srt")
        ->and($transcription->vtt_path)->toBe("{$outputPrefix}/sample-video_en.vtt")
        ->and($segments[0]->text_en)->toBe('Hello')
        ->and($segments[1]->text_en)->toBe('World');

    Storage::disk('local')->assertExists($transcription->srt_path);
    $srtContents = Storage::disk('local')->get($transcription->srt_path);

    expect($srtContents)->toContain('Hello')
        ->and($srtContents)->toContain('World');
});
