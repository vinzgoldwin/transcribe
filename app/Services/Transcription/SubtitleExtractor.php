<?php

namespace App\Services\Transcription;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SubtitleExtractor
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $ffmpegBinary,
        public string $ffprobeBinary,
        public int $timeoutSeconds,
    ) {}

    /**
     * @return array<int, array{start: float, end: float, text: string}>|null
     */
    public function extract(
        string $inputPath,
        string $tempDirectory,
        ?string $preferredLanguage = null,
        ?bool $fallbackToFirstStream = null,
    ): ?array {
        $streams = $this->probeSubtitleStreams($inputPath);

        if ($streams === []) {
            return null;
        }

        $fallback = $fallbackToFirstStream ?? (bool) config('transcribe.subtitle.fallback_to_first_stream', true);
        $stream = $this->selectStream($streams, $preferredLanguage, $fallback);

        if (! $stream) {
            return null;
        }

        File::ensureDirectoryExists($tempDirectory);

        $outputPath = rtrim($tempDirectory, '/').'/embedded-subtitles.srt';
        File::delete($outputPath);

        $this->extractStreamToSrt($inputPath, $stream['index'], $outputPath);

        if (! File::exists($outputPath)) {
            return null;
        }

        $contents = (string) File::get($outputPath);
        File::delete($outputPath);

        if (trim($contents) === '') {
            return null;
        }

        $segments = SubtitleParser::parseSrt($contents);

        return $segments === [] ? null : $segments;
    }

    /**
     * @return array<int, array{index: int, language: string|null, title: string|null, codec: string|null}>
     */
    protected function probeSubtitleStreams(string $inputPath): array
    {
        $process = new Process([
            $this->ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            's',
            '-show_entries',
            'stream=index,codec_name:stream_tags=language,title',
            '-of',
            'json',
            $inputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $payload = json_decode($process->getOutput(), true);
        $streams = is_array($payload) ? ($payload['streams'] ?? []) : [];

        if (! is_array($streams)) {
            return [];
        }

        return collect($streams)
            ->map(fn (array $stream): array => [
                'index' => (int) ($stream['index'] ?? 0),
                'language' => isset($stream['tags']['language']) ? (string) $stream['tags']['language'] : null,
                'title' => isset($stream['tags']['title']) ? (string) $stream['tags']['title'] : null,
                'codec' => isset($stream['codec_name']) ? (string) $stream['codec_name'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{index: int, language: string|null, title: string|null, codec: string|null}>  $streams
     * @return array{index: int, language: string|null, title: string|null, codec: string|null}|null
     */
    protected function selectStream(array $streams, ?string $preferredLanguage, bool $fallbackToFirst): ?array
    {
        if ($preferredLanguage) {
            foreach ($streams as $stream) {
                if ($this->matchesLanguage($stream, $preferredLanguage)) {
                    return $stream;
                }
            }
        }

        return $fallbackToFirst ? ($streams[0] ?? null) : null;
    }

    /**
     * @param  array{index: int, language: string|null, title: string|null, codec: string|null}  $stream
     */
    protected function matchesLanguage(array $stream, string $preferredLanguage): bool
    {
        $preferred = strtolower(trim($preferredLanguage));

        if ($preferred === '') {
            return false;
        }

        $preferred = str_replace('_', '-', $preferred);
        $aliases = $this->languageAliases($preferred);

        $language = $stream['language'] ? strtolower($stream['language']) : '';
        $language = str_replace('_', '-', $language);

        if ($language !== '') {
            foreach ($aliases as $alias) {
                if ($language === $alias || str_starts_with($language, $alias.'-')) {
                    return true;
                }
            }
        }

        $title = $stream['title'] ? strtolower($stream['title']) : '';

        if ($title !== '') {
            foreach ($aliases as $alias) {
                if (str_contains($title, $alias)) {
                    return true;
                }
            }

            if ($preferred === 'zh' && str_contains($title, 'chinese')) {
                return true;
            }

            if ($preferred === 'ja' && str_contains($title, 'japanese')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function languageAliases(string $preferred): array
    {
        return match ($preferred) {
            'zh' => ['zh', 'zho', 'chi', 'zh-hans', 'zh-hant', 'zh-cn', 'zh-tw', 'cn'],
            'ja' => ['ja', 'jpn', 'jp'],
            default => [$preferred],
        };
    }

    protected function extractStreamToSrt(string $inputPath, int $streamIndex, string $outputPath): void
    {
        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-i',
            $inputPath,
            '-map',
            '0:'.$streamIndex,
            '-c:s',
            'srt',
            $outputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
