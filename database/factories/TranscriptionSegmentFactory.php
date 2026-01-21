<?php

namespace Database\Factories;

use App\Models\Transcription;
use App\Models\TranscriptionChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TranscriptionSegment>
 */
class TranscriptionSegmentFactory extends Factory
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
            'transcription_chunk_id' => TranscriptionChunk::factory(),
            'sequence' => fake()->numberBetween(1, 50),
            'start_seconds' => fake()->randomFloat(3, 0, 120),
            'end_seconds' => fake()->randomFloat(3, 121, 240),
            'text_jp' => fake()->sentence(),
            'text_en' => fake()->sentence(),
            'formatted_text' => fake()->sentence(),
        ];
    }
}
