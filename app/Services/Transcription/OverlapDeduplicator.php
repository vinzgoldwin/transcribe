<?php

namespace App\Services\Transcription;

use Illuminate\Support\Collection;

class OverlapDeduplicator
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    /**
     * @param  Collection<int, array{id: int, start_seconds: float, end_seconds: float, text_en: string, text_jp: string, formatted_text: string}>  $segments
     * @return array<int, array{id: int, start_seconds: float, end_seconds: float, text_en: string, text_jp: string, formatted_text: string}>
     */
    public function dedupe(Collection $segments, float $overlapTolerance = 0.05): array
    {
        $ordered = $segments->sortBy('start_seconds')->values();
        $results = [];

        foreach ($ordered as $segment) {
            $item = [
                'id' => (int) $segment['id'],
                'start_seconds' => (float) $segment['start_seconds'],
                'end_seconds' => (float) $segment['end_seconds'],
                'text_en' => (string) $segment['text_en'],
                'text_jp' => (string) $segment['text_jp'],
                'formatted_text' => (string) $segment['formatted_text'],
            ];

            if ($item['end_seconds'] <= $item['start_seconds']) {
                continue;
            }

            if ($results === []) {
                $results[] = $item;

                continue;
            }

            $lastIndex = count($results) - 1;
            $last = $results[$lastIndex];

            if ($item['start_seconds'] <= ($last['end_seconds'] - $overlapTolerance)) {
                if ($this->isSimilar($last['text_en'], $item['text_en'])) {
                    continue;
                }

                $item['start_seconds'] = max($item['start_seconds'], $last['end_seconds'] + $overlapTolerance);

                if ($item['start_seconds'] >= $item['end_seconds']) {
                    continue;
                }
            }

            $results[] = $item;
        }

        return $results;
    }

    protected function isSimilar(string $left, string $right): bool
    {
        $leftNormalized = $this->normalize($left);
        $rightNormalized = $this->normalize($right);

        if ($leftNormalized === '' || $rightNormalized === '') {
            return false;
        }

        if ($leftNormalized === $rightNormalized) {
            return true;
        }

        similar_text($leftNormalized, $rightNormalized, $percent);

        return $percent >= 85.0;
    }

    protected function normalize(string $text): string
    {
        $lowered = mb_strtolower($text);
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lowered);

        return trim(preg_replace('/\s+/', ' ', $stripped ?? ''));
    }
}
