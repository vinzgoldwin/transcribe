<?php

namespace App\Enums;

enum TranscriptionChunkStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
