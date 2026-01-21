<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transcription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transcription_chunk_id')->nullable()->constrained('transcription_chunks')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('start_seconds', 10, 3);
            $table->decimal('end_seconds', 10, 3);
            $table->text('text_jp');
            $table->text('text_en');
            $table->text('formatted_text');
            $table->timestamps();

            $table->index(['transcription_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_segments');
    }
};
