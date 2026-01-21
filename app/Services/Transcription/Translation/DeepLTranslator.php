<?php

namespace App\Services\Transcription\Translation;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class DeepLTranslator implements Translator
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public ?string $apiKey,
        public string $baseUrl,
        public ?string $formality,
    ) {}

    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        if (! $this->apiKey) {
            throw new InvalidArgumentException('Missing DeepL API key for translation.');
        }

        if ($texts === []) {
            return [];
        }

        $payload = [
            'text' => $texts,
            'source_lang' => strtoupper($sourceLanguage),
            'target_lang' => strtoupper($targetLanguage),
        ];

        if ($this->formality) {
            $payload['formality'] = $this->formality;
        }

        $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders([
                'Authorization' => 'DeepL-Auth-Key '.$this->apiKey,
            ])
            ->asForm()
            ->post('/v2/translate', $payload);

        $response->throw();

        $data = $response->json();
        $translations = $data['translations'] ?? [];

        return collect($translations)
            ->map(fn (array $translation) => (string) ($translation['text'] ?? ''))
            ->all();
    }
}
