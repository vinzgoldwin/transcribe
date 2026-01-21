<?php

namespace App\Services\Transcription\Stt;

interface SttProvider
{
    /**
     * @return array<int, array{start: float, end: float, text: string}>
     */
    public function transcribe(string $audioPath, string $language): array;
}
