<?php

namespace Database\Factories;

use App\Enums\TranscriptionChunkStatus;
use App\Models\Transcription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TranscriptionChunk>
 */
class TranscriptionChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transcription_id' => Transcription::factory(),
            'sequence' => fake()->numberBetween(0, 10),
            'start_seconds' => 0.0,
            'end_seconds' => fake()->randomFloat(3, 30, 90),
            'status' => TranscriptionChunkStatus::Pending,
            'segment_count' => 0,
        ];
    }
}
