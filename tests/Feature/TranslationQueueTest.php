<?php

use App\Enums\TranscriptionStatus;
use App\Jobs\TranslateTranscriptionJob;
use App\Models\Transcription;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('translation jobs are queued on the translation queue', function () {
    Queue::fake();

    $user = User::factory()->create();
    $transcription = Transcription::factory()
        ->for($user)
        ->state(['status' => TranscriptionStatus::AwaitingTranslation])
        ->create();

    $this->actingAs($user)
        ->post(route('transcriptions.translate', $transcription))
        ->assertRedirect(route('transcriptions.show', $transcription));

    $transcription->refresh();

    expect($transcription->status)->toBe(TranscriptionStatus::Processing);

    Queue::assertPushed(TranslateTranscriptionJob::class, function (TranslateTranscriptionJob $job) use ($transcription): bool {
        return $job->queue === config('transcribe.translation_queue', 'translations')
            && $job->transcriptionId === $transcription->id;
    });
});
