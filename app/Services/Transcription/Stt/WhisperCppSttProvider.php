<?php

namespace App\Services\Transcription\Stt;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class WhisperCppSttProvider implements SttProvider
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public ?string $binaryPath,
        public ?string $modelPath,
        public int $timeoutSeconds,
        public ?int $threads,
        public string $outputFormat,
        public ?int $bestOf,
        public ?int $beamSize,
        public bool $suppressNonSpeechTokens,
    ) {}

    public function transcribe(string $audioPath, string $language): array
    {
        if (! $this->binaryPath) {
            throw new InvalidArgumentException('Missing whisper.cpp binary path.');
        }

        if (! $this->modelPath) {
            throw new InvalidArgumentException('Missing whisper.cpp model path.');
        }

        if (! File::exists($audioPath)) {
            throw new InvalidArgumentException("Audio file not found: {$audioPath}");
        }

        $format = strtolower($this->outputFormat);

        if (! in_array($format, ['srt', 'json'], true)) {
            throw new InvalidArgumentException("Unsupported whisper.cpp output format [{$this->outputFormat}].");
        }

        $command = [
            $this->binaryPath,
            '-m',
            $this->modelPath,
            '-f',
            $audioPath,
        ];

        if (trim($language) !== '') {
            $command[] = '-l';
            $command[] = $language;
        }

        if ($this->threads) {
            $command[] = '-t';
            $command[] = (string) $this->threads;
        }

        if ($this->bestOf !== null) {
            $command[] = '-bo';
            $command[] = (string) $this->bestOf;
        }

        if ($this->beamSize !== null) {
            $command[] = '-bs';
            $command[] = (string) $this->beamSize;
        }

        if ($this->suppressNonSpeechTokens) {
            $command[] = '-sns';
        }

        $command[] = $format === 'json' ? '--output-json' : '--output-srt';

        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = $this->readOutput($audioPath, $format) ?: trim($process->getOutput());

        if ($output === '') {
            $error = trim($process->getErrorOutput());

            throw new InvalidArgumentException($error !== '' ? $error : 'whisper.cpp returned no output.');
        }

        try {
            return $format === 'json'
                ? self::parseJson($output)
                : self::parseSrt($output);
        } finally {
            $this->cleanupOutputFiles($audioPath, $format);
        }
    }

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

    /**
     * @return array<int, array{start: float, end: float, text: string}>
     */
    public static function parseJson(string $json): array
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $segments = $payload['segments'] ?? [];

        if (! is_array($segments)) {
            return [];
        }

        return collect($segments)
            ->map(fn (array $segment) => [
                'start' => (float) ($segment['start'] ?? 0.0),
                'end' => (float) ($segment['end'] ?? 0.0),
                'text' => self::sanitizeText((string) ($segment['text'] ?? '')),
            ])
            ->filter(fn (array $segment) => $segment['text'] !== '')
            ->values()
            ->all();
    }

    protected static function srtTimestampToSeconds(string $timestamp): float
    {
        $timestamp = str_replace(',', '.', $timestamp);
        [$hours, $minutes, $seconds] = explode(':', $timestamp);

        return ((int) $hours * 3600) + ((int) $minutes * 60) + (float) $seconds;
    }

    protected function readOutput(string $audioPath, string $format): string
    {
        $paths = $this->outputCandidates($audioPath, $format);

        foreach ($paths as $path) {
            if (File::exists($path)) {
                return (string) File::get($path);
            }
        }

        return '';
    }

    protected function cleanupOutputFiles(string $audioPath, string $format): void
    {
        File::delete($this->outputCandidates($audioPath, $format));
    }

    /**
     * @return array<int, string>
     */
    protected function outputCandidates(string $audioPath, string $format): array
    {
        $baseName = pathinfo($audioPath, PATHINFO_FILENAME);
        $directory = dirname($audioPath);

        return [
            "{$audioPath}.{$format}",
            "{$directory}/{$baseName}.{$format}",
        ];
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
