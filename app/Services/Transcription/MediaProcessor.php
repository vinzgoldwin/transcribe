<?php

namespace App\Services\Transcription;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MediaProcessor
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $ffmpegBinary,
        public string $ffprobeBinary,
        public int $timeoutSeconds,
    ) {}

    public function probeDuration(string $inputPath): float
    {
        $process = new Process([
            $this->ffprobeBinary,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=nw=1:nk=1',
            $inputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return (float) trim($process->getOutput());
    }

    public function extractAudio(string $inputPath, string $outputPath): void
    {
        File::ensureDirectoryExists(dirname($outputPath));

        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-i',
            $inputPath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-f',
            'wav',
            $outputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    public function detectSilence(string $audioPath, float $minSeconds, string $noiseLevel): string
    {
        $process = new Process([
            $this->ffmpegBinary,
            '-i',
            $audioPath,
            '-af',
            "silencedetect=noise={$noiseLevel}:d={$minSeconds}",
            '-f',
            'null',
            '-',
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getErrorOutput().$process->getOutput();
    }

    public function cutChunk(string $audioPath, float $startSeconds, float $durationSeconds, string $outputPath): void
    {
        File::ensureDirectoryExists(dirname($outputPath));

        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-ss',
            number_format($startSeconds, 3, '.', ''),
            '-t',
            number_format($durationSeconds, 3, '.', ''),
            '-i',
            $audioPath,
            '-ac',
            '1',
            '-ar',
            '16000',
            '-f',
            'wav',
            $outputPath,
        ]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
