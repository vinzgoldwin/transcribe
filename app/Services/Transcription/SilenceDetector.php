<?php

namespace App\Services\Transcription;

class SilenceDetector
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    /**
     * @return array<int, array{start: float, end: float, duration: float}>
     */
    public function parse(string $output, float $minDuration): array
    {
        $intervals = [];
        $currentStart = null;

        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/silence_start:\s*(?<start>\d+(\.\d+)?)/', $line, $matches)) {
                $currentStart = (float) $matches['start'];

                continue;
            }

            if (preg_match('/silence_end:\s*(?<end>\d+(\.\d+)?)(\s*\|\s*silence_duration:\s*(?<duration>\d+(\.\d+)?))?/', $line, $matches)) {
                $end = (float) $matches['end'];
                $duration = isset($matches['duration']) ? (float) $matches['duration'] : null;

                if ($duration === null && $currentStart !== null) {
                    $duration = $end - $currentStart;
                }

                if ($duration !== null && $duration >= $minDuration) {
                    $intervals[] = [
                        'start' => $currentStart ?? max(0.0, $end - $duration),
                        'end' => $end,
                        'duration' => $duration,
                    ];
                }

                $currentStart = null;
            }
        }

        return $intervals;
    }
}
