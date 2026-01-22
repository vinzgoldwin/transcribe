<?php

namespace App\Enums;

enum TranscriptionStatus: string
{
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case AwaitingTranslation = 'awaiting-translation';
    case Completed = 'completed';
    case Failed = 'failed';
}
