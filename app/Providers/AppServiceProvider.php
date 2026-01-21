<?php

namespace App\Providers;

use App\Services\Transcription\MediaProcessor;
use App\Services\Transcription\OverlapDeduplicator;
use App\Services\Transcription\SrtVttBuilder;
use App\Services\Transcription\Stt\SttProvider;
use App\Services\Transcription\Stt\WhisperCppSttProvider;
use App\Services\Transcription\Stt\WhisperSttProvider;
use App\Services\Transcription\SubtitleFormatter;
use App\Services\Transcription\Translation\DeepLTranslator;
use App\Services\Transcription\Translation\Translator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MediaProcessor::class, function (): MediaProcessor {
            return new MediaProcessor(
                config('transcribe.ffmpeg_path'),
                config('transcribe.ffprobe_path'),
                (int) config('transcribe.process_timeout_seconds', 1200),
            );
        });

        $this->app->singleton(SubtitleFormatter::class, function (): SubtitleFormatter {
            return new SubtitleFormatter(
                (int) config('transcribe.subtitle.max_chars_per_line', 42),
                (int) config('transcribe.subtitle.max_lines', 2),
                (float) config('transcribe.subtitle.min_duration', 1),
                (float) config('transcribe.subtitle.max_duration', 6),
                (float) config('transcribe.subtitle.max_chars_per_second', 17),
                (float) config('transcribe.subtitle.gap_seconds', 0.05),
            );
        });

        $this->app->singleton(OverlapDeduplicator::class);
        $this->app->singleton(SrtVttBuilder::class);

        $this->app->bind(SttProvider::class, function (): SttProvider {
            $driver = config('transcribe.providers.stt.driver', 'whisper');

            return match ($driver) {
                'whisper' => new WhisperSttProvider(
                    config('transcribe.providers.stt.whisper.api_key'),
                    config('transcribe.providers.stt.whisper.base_url'),
                    config('transcribe.providers.stt.whisper.model', 'whisper-1'),
                ),
                'whisper_cpp' => new WhisperCppSttProvider(
                    config('transcribe.providers.stt.whisper_cpp.binary'),
                    config('transcribe.providers.stt.whisper_cpp.model'),
                    (int) config('transcribe.providers.stt.whisper_cpp.timeout', config('transcribe.process_timeout_seconds', 1200)),
                    config('transcribe.providers.stt.whisper_cpp.threads'),
                    (string) config('transcribe.providers.stt.whisper_cpp.output_format', 'srt'),
                ),
                default => throw new InvalidArgumentException("Unsupported STT driver [{$driver}]."),
            };
        });

        $this->app->bind(Translator::class, function (): Translator {
            $driver = config('transcribe.providers.translation.driver', 'deepl');

            return match ($driver) {
                'deepl' => new DeepLTranslator(
                    config('transcribe.providers.translation.deepl.api_key'),
                    config('transcribe.providers.translation.deepl.base_url'),
                    config('transcribe.providers.translation.deepl.formality'),
                ),
                default => throw new InvalidArgumentException("Unsupported translation driver [{$driver}]."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
