<?php

namespace App\Services\Transcription\Translation;

interface Translator
{
    /**
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array;
}
