<?php

namespace App\Services\Transcription;

class ChunkBuilder
{
    /**
     * Create a new class instance.
     */
    public function __construct() {}

    /**
     * @param  array<int, array{start: float, end: float, duration: float}>  $silences
     * @return array<int, array{sequence: int, start: float, end: float}>
     */
    public function build(
        float $durationSeconds,
        array $silences,
        float $minSeconds,
        float $maxSeconds,
        float $overlapSeconds,
    ): array {
        $silenceEnds = array_map(static fn (array $silence): float => (float) $silence['end'], $silences);
        sort($silenceEnds);

        $chunks = [];
        $baseStart = 0.0;
        $sequence = 0;

        while ($baseStart < $durationSeconds) {
            $minEnd = $baseStart + $minSeconds;
            $maxEnd = min($baseStart + $maxSeconds, $durationSeconds);

            $chosenEnd = null;

            foreach ($silenceEnds as $silenceEnd) {
                if ($silenceEnd < $minEnd) {
                    continue;
                }

                if ($silenceEnd > $maxEnd) {
                    break;
                }

                $chosenEnd = $silenceEnd;
            }

            $baseEnd = $chosenEnd ?? $maxEnd;

            if ($durationSeconds - $baseEnd < $minSeconds) {
                $baseEnd = $durationSeconds;
            }

            $chunkStart = $sequence === 0 ? 0.0 : max(0.0, $baseStart - $overlapSeconds);

            $chunks[] = [
                'sequence' => $sequence,
                'start' => round($chunkStart, 3),
                'end' => round($baseEnd, 3),
            ];

            $sequence++;

            if ($baseEnd >= $durationSeconds) {
                break;
            }

            $baseStart = $baseEnd;
        }

        return $chunks;
    }
}
