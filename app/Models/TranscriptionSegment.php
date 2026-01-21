<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionSegment extends Model
{
    /** @use HasFactory<\Database\Factories\TranscriptionSegmentFactory> */
    use HasFactory;

    protected $fillable = [
        'transcription_id',
        'transcription_chunk_id',
        'sequence',
        'start_seconds',
        'end_seconds',
        'text_jp',
        'text_en',
        'formatted_text',
    ];

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(TranscriptionChunk::class, 'transcription_chunk_id');
    }

    protected function casts(): array
    {
        return [
            'start_seconds' => 'float',
            'end_seconds' => 'float',
            'sequence' => 'integer',
        ];
    }
}
