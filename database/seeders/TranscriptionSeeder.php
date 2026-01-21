<?php

namespace Database\Seeders;

use App\Models\Transcription;
use Illuminate\Database\Seeder;

class TranscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Transcription::factory()
            ->count(3)
            ->create();
    }
}
