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
        Schema::create('transcription_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('start_seconds', 10, 3);
            $table->decimal('end_seconds', 10, 3);
            $table->string('status')->default('pending');
            $table->string('audio_path')->nullable();
            $table->json('stt_payload')->nullable();
            $table->json('translated_payload')->nullable();
            $table->unsignedInteger('segment_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['transcription_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_chunks');
    }
};
