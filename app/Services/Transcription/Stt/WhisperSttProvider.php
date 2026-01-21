<?php

namespace App\Services\Transcription\Stt;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class WhisperSttProvider implements SttProvider
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public ?string $apiKey,
        public string $baseUrl,
        public string $model,
    ) {}

    public function transcribe(string $audioPath, string $language): array
    {
        if (! $this->apiKey) {
            throw new InvalidArgumentException('Missing OpenAI API key for Whisper transcription.');
        }

        $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->apiKey)
            ->attach('file', fopen($audioPath, 'r'), basename($audioPath))
            ->post('/v1/audio/transcriptions', [
                'model' => $this->model,
                'language' => $language,
                'response_format' => 'verbose_json',
            ]);

        $response->throw();

        $payload = $response->json();
        $segments = $payload['segments'] ?? [];

        return collect($segments)
            ->map(fn (array $segment) => [
                'start' => (float) ($segment['start'] ?? 0.0),
                'end' => (float) ($segment['end'] ?? 0.0),
                'text' => trim((string) ($segment['text'] ?? '')),
            ])
            ->filter(fn (array $segment) => $segment['text'] !== '')
            ->values()
            ->all();
    }
}
