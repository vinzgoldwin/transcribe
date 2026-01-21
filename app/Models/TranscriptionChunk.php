<?php

namespace App\Models;

use App\Enums\TranscriptionChunkStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranscriptionChunk extends Model
{
    /** @use HasFactory<\Database\Factories\TranscriptionChunkFactory> */
    use HasFactory;

    protected $fillable = [
        'transcription_id',
        'sequence',
        'start_seconds',
        'end_seconds',
        'status',
        'audio_path',
        'stt_payload',
        'translated_payload',
        'segment_count',
        'completed_at',
        'error_message',
    ];

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptionSegment::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TranscriptionChunkStatus::class,
            'start_seconds' => 'float',
            'end_seconds' => 'float',
            'segment_count' => 'integer',
            'stt_payload' => 'array',
            'translated_payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
