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
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('original_filename');
            $table->string('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('status')->default('uploading');
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->string('audio_path')->nullable();
            $table->string('srt_path')->nullable();
            $table->string('vtt_path')->nullable();
            $table->unsignedInteger('chunks_total')->default(0);
            $table->unsignedInteger('chunks_completed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
