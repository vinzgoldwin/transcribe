<?php

namespace Database\Seeders;

use App\Models\Transcription;
use App\Models\TranscriptionChunk;
use Illuminate\Database\Seeder;

class TranscriptionChunkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $transcription = Transcription::query()->first()
            ?? Transcription::factory()->create();

        TranscriptionChunk::factory()
            ->count(6)
            ->for($transcription)
            ->create();
    }
}
