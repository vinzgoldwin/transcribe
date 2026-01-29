<?php

namespace App\Services\Transcription;

class SubtitleFormatter
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public int $maxCharsPerLine,
        public int $maxLines,
        public float $minDuration,
        public float $maxDuration,
        public float $maxCharsPerSecond,
        public float $gapSeconds,
    ) {}

    /**
     * @param  array<int, array{start: float, end: float, text: string, text_jp?: string}>  $segments
     * @return array<int, array{start: float, end: float, text: string, formatted_text: string, text_jp: string}>
     */
    public function format(array $segments): array
    {
        $formatted = [];
        $cursor = 0.0;

        foreach ($segments as $segment) {
            $text = trim((string) $segment['text']);
            $textJp = (string) ($segment['text_jp'] ?? '');
            $start = (float) $segment['start'];
            $end = (float) $segment['end'];

            if ($text === '') {
                continue;
            }

            $duration = max(0.01, $end - $start);
            $start = max($start, $cursor + $this->gapSeconds);

            $requiredDuration = max($duration, $this->requiredDuration($text));
            $maxCharsPerPart = $this->maxCharsPerLine * $this->maxLines;
            $partsByLine = (int) max(1, ceil($this->textLength($text) / $maxCharsPerPart));
            $partsByDuration = (int) max(1, ceil($requiredDuration / $this->maxDuration));
            $parts = max(1, $partsByLine, $partsByDuration);

            $targetDuration = max($requiredDuration, $parts * $this->minDuration);
            $partDuration = $targetDuration / $parts;

            if ($partDuration > $this->maxDuration) {
                $parts = (int) ceil($targetDuration / $this->maxDuration);
                $partDuration = $targetDuration / $parts;
            }

            $partsTexts = $this->splitTextIntoParts($text, $parts);
            $parts = max(1, count($partsTexts));
            $partDuration = max($this->minDuration, $targetDuration / $parts);

            if ($partDuration > $this->maxDuration) {
                $parts = (int) ceil($targetDuration / $this->maxDuration);
                $partsTexts = $this->splitTextIntoParts($text, $parts);
                $parts = max(1, count($partsTexts));
                $partDuration = max($this->minDuration, $targetDuration / $parts);
            }

            foreach ($partsTexts as $index => $partText) {
                $partStart = $start + ($index * $partDuration);
                $partEnd = $partStart + $partDuration;

                $formatted[] = [
                    'start' => round($partStart, 3),
                    'end' => round($partEnd, 3),
                    'text' => $partText,
                    'formatted_text' => $this->wrapLines($partText),
                    'text_jp' => $textJp,
                ];

                $cursor = $partEnd;
            }
        }

        return $formatted;
    }

    public function wrapText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        return $this->wrapLines($text);
    }

    protected function requiredDuration(string $text): float
    {
        $length = $this->textLength($text);

        if ($length === 0) {
            return $this->minDuration;
        }

        return max($this->minDuration, $length / $this->maxCharsPerSecond);
    }

    /**
     * @return array<int, string>
     */
    protected function splitTextIntoParts(string $text, int $parts): array
    {
        if ($parts <= 1) {
            return [$text];
        }

        $words = $this->splitWords($text);
        $targetLength = max(1, (int) ceil($this->textLength($text) / $parts));
        $segments = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->textLength($candidate) <= $targetLength || $current === '') {
                $current = $candidate;

                continue;
            }

            $segments[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    protected function wrapLines(string $text): string
    {
        $words = $this->splitWords($text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->textLength($candidate) <= $this->maxCharsPerLine) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            $current = $word;

            if (count($lines) >= $this->maxLines) {
                $current = '';
                break;
            }
        }

        if ($current !== '' && count($lines) < $this->maxLines) {
            $lines[] = $current;
        }

        if ($lines === []) {
            $lines[] = $text;
        }

        return implode("\n", array_slice($lines, 0, $this->maxLines));
    }

    /**
     * @return array<int, string>
     */
    protected function splitWords(string $text): array
    {
        $rawWords = preg_split('/\s+/', trim($text)) ?: [];
        $words = [];

        foreach ($rawWords as $word) {
            if ($this->textLength($word) <= $this->maxCharsPerLine) {
                $words[] = $word;

                continue;
            }

            foreach ($this->splitLongWord($word) as $part) {
                $words[] = $part;
            }
        }

        return $words;
    }

    /**
     * @return array<int, string>
     */
    protected function splitLongWord(string $word): array
    {
        $parts = [];
        $length = $this->textLength($word);
        $offset = 0;

        while ($offset < $length) {
            $parts[] = $this->substring($word, $offset, $this->maxCharsPerLine);
            $offset += $this->maxCharsPerLine;
        }

        return $parts;
    }

    protected function textLength(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text);
        }

        return strlen($text);
    }

    protected function substring(string $text, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $start, $length);
        }

        return substr($text, $start, $length);
    }
}
