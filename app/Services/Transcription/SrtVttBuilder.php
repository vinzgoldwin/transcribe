<?php

namespace App\Services\Transcription;

class SrtVttBuilder
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    public function buildSrt(array $segments): string
    {
        $lines = [];

        foreach ($segments as $index => $segment) {
            $number = $index + 1;
            $lines[] = (string) $number;
            $lines[] = $this->formatTimestamp($segment['start'], ',').' --> '.$this->formatTimestamp($segment['end'], ',');
            $lines[] = $segment['text'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{start: float, end: float, text: string}>  $segments
     */
    public function buildVtt(array $segments): string
    {
        $lines = ['WEBVTT', ''];

        foreach ($segments as $segment) {
            $lines[] = $this->formatTimestamp($segment['start'], '.').' --> '.$this->formatTimestamp($segment['end'], '.');
            $lines[] = $segment['text'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function formatTimestamp(float $seconds, string $separator): string
    {
        $totalMilliseconds = max(0, (int) round($seconds * 1000));
        $milliseconds = $totalMilliseconds % 1000;
        $totalSeconds = intdiv($totalMilliseconds, 1000);
        $secondsPart = $totalSeconds % 60;
        $totalMinutes = intdiv($totalSeconds, 60);
        $minutesPart = $totalMinutes % 60;
        $hoursPart = intdiv($totalMinutes, 60);

        return sprintf('%02d:%02d:%02d%s%03d', $hoursPart, $minutesPart, $secondsPart, $separator, $milliseconds);
    }
}
