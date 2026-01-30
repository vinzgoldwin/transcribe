<?php

use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

it('shows a transcription run with download links', function () {
    $this->actingAs($user = User::factory()->create());

    $transcription = Transcription::factory()
        ->for($user)
        ->state([
            'status' => TranscriptionStatus::Completed,
            'srt_path' => 'transcriptions/'.fake()->uuid().'/output.srt',
            'vtt_path' => 'transcriptions/'.fake()->uuid().'/output.vtt',
            'chunks_total' => 10,
            'chunks_completed' => 10,
        ])
        ->create();

    $this->withoutMiddleware(ValidateSessionWithWorkOS::class)
        ->get(route('transcriptions.show', $transcription))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Transcriptions/Show')
                ->where('transcription.id', $transcription->public_id)
                ->where('transcription.status', 'completed')
                ->where('transcription.srt_ready', true)
                ->where('transcription.vtt_ready', true)
                ->where(
                    'transcription.download_srt_url',
                    route('transcriptions.download', [$transcription, 'srt']),
                )
                ->where(
                    'transcription.download_vtt_url',
                    route('transcriptions.download', [$transcription, 'vtt']),
                ),
        );
});
