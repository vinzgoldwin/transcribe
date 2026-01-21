<?php

namespace Database\Seeders;

use App\Models\Transcription;
use App\Models\TranscriptionChunk;
use App\Models\TranscriptionSegment;
use Illuminate\Database\Seeder;

class TranscriptionSegmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $chunk = TranscriptionChunk::query()->first();

        if (! $chunk) {
            $transcription = Transcription::factory()->create();
            $chunk = TranscriptionChunk::factory()->for($transcription)->create();
        }

        TranscriptionSegment::factory()
            ->count(12)
            ->for($chunk, 'chunk')
            ->state([
                'transcription_id' => $chunk->transcription_id,
            ])
            ->create();
    }
}
