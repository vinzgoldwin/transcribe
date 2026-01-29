<?php

use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/dashboard')->assertOk();
});

test('dashboard includes awaiting translation items', function () {
    $this->actingAs($user = User::factory()->create());

    Transcription::factory()
        ->for($user)
        ->state([
            'status' => TranscriptionStatus::AwaitingTranslation,
            'created_at' => now()->addSecond(),
        ])
        ->create();

    Transcription::factory()
        ->for($user)
        ->state([
            'status' => TranscriptionStatus::Completed,
            'created_at' => now()->subSecond(),
        ])
        ->create();

    $this->get('/dashboard')->assertInertia(
        fn (Assert $page) => $page
            ->component('Transcriptions/Index')
            ->has('transcriptions', 2)
            ->where('transcriptions.0.status', 'awaiting-translation'),
    );
});
