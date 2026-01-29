<?php

namespace App\Services\Transcription\Translation;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class AzureTranslator implements Translator
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public ?string $apiKey,
        public string $baseUrl,
        public ?string $region,
        public string $apiVersion,
        public array $retryDelays = [1000, 2000, 4000, 8000],
        public bool $retryOnly429 = true,
    ) {
        $this->retryDelays = array_values(array_filter(
            array_map(static fn (int $delay): int => max(0, $delay), $this->retryDelays),
            static fn (int $delay): bool => $delay >= 0,
        ));
    }

    public function translate(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        if (! $this->apiKey) {
            throw new InvalidArgumentException('Missing Azure Translator API key for translation.');
        }

        if ($texts === []) {
            return [];
        }

        $payload = collect($texts)
            ->map(fn (string $text) => ['Text' => $text])
            ->all();

        $headers = [
            'Ocp-Apim-Subscription-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->region) {
            $headers['Ocp-Apim-Subscription-Region'] = $this->region;
        }

        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders($headers)
            ->withQueryParameters([
                'api-version' => $this->apiVersion,
                'from' => strtolower($sourceLanguage),
                'to' => strtolower($targetLanguage),
            ])
            ->asJson();

        $delays = $this->retryDelays !== [] ? $this->retryDelays : [0];

        $response = retry(
            $delays,
            function () use ($request, $payload) {
                $response = $request->post('/translate', $payload);

                $response->throw();

                return $response;
            },
            0,
            function (Throwable $exception): bool {
                if (! $this->retryOnly429) {
                    return true;
                }

                return $exception instanceof RequestException
                    && $exception->response !== null
                    && $exception->response->status() === 429;
            },
        );

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        return collect($data)
            ->map(function (array $item): string {
                $translations = $item['translations'] ?? [];

                return (string) ($translations[0]['text'] ?? '');
            })
            ->all();
    }
}
