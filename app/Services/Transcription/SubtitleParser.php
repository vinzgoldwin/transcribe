<?php

namespace App\Services\Transcription;

class SubtitleParser
{
    /**
     * @return array<int, array{start: float, end: float, text: string}>
     */
    public static function parseSrt(string $srt): array
    {
        $blocks = preg_split('/\R{2,}/', trim($srt)) ?: [];
        $segments = [];

        foreach ($blocks as $block) {
            $lines = preg_split('/\R/', trim($block)) ?: [];

            if ($lines === []) {
                continue;
            }

            $timeLineIndex = null;

            foreach ($lines as $index => $line) {
                if (str_contains($line, '-->')) {
                    $timeLineIndex = $index;
                    break;
                }
            }

            if ($timeLineIndex === null) {
                continue;
            }

            $timeLine = $lines[$timeLineIndex] ?? '';

            if (! preg_match(
                '/(\d{2}:\d{2}:\d{2}[,\.]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[,\.]\d{3})/',
                $timeLine,
                $matches,
            )) {
                continue;
            }

            $textLines = array_slice($lines, $timeLineIndex + 1);
            $text = self::sanitizeText(strip_tags(implode(' ', $textLines)));

            if ($text === '') {
                continue;
            }

            $segments[] = [
                'start' => self::srtTimestampToSeconds($matches[1]),
                'end' => self::srtTimestampToSeconds($matches[2]),
                'text' => $text,
            ];
        }

        return $segments;
    }

    protected static function srtTimestampToSeconds(string $timestamp): float
    {
        $timestamp = str_replace(',', '.', $timestamp);
        [$hours, $minutes, $seconds] = explode(':', $timestamp);

        return ((int) $hours * 3600) + ((int) $minutes * 60) + (float) $seconds;
    }

    protected static function sanitizeText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            $cleaned = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');

            if (preg_match('//u', $cleaned) === 1) {
                return trim($cleaned);
            }
        }

        return '';
    }
}
