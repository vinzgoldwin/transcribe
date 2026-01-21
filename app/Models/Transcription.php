<?php

namespace App\Models;

use App\Enums\TranscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Transcription extends Model
{
    /** @use HasFactory<\Database\Factories\TranscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'public_id',
        'original_filename',
        'content_type',
        'size_bytes',
        'storage_disk',
        'storage_path',
        'status',
        'duration_seconds',
        'audio_path',
        'srt_path',
        'vtt_path',
        'chunks_total',
        'chunks_completed',
        'error_message',
        'meta',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transcription $transcription): void {
            if (! $transcription->public_id) {
                $transcription->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(TranscriptionChunk::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptionSegment::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TranscriptionStatus::class,
            'duration_seconds' => 'float',
            'chunks_total' => 'integer',
            'chunks_completed' => 'integer',
            'size_bytes' => 'integer',
            'meta' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
