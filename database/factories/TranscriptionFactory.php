<?php

namespace Database\Factories;

use App\Enums\TranscriptionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transcription>
 */
class TranscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'public_id' => (string) Str::uuid(),
            'original_filename' => fake()->slug(2).'.mp4',
            'content_type' => 'video/mp4',
            'size_bytes' => fake()->numberBetween(500_000, 20_000_000),
            'storage_disk' => 'local',
            'storage_path' => 'transcriptions/'.Str::uuid().'/source.mp4',
            'status' => TranscriptionStatus::Uploaded,
            'duration_seconds' => fake()->randomFloat(3, 60, 900),
            'chunks_total' => 0,
            'chunks_completed' => 0,
            'meta' => [],
        ];
    }
}
