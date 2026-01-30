<?php

return [
    'storage_disk' => env('TRANSCRIBE_STORAGE_DISK', 'transcriptions'),
    'storage_prefix' => env('TRANSCRIBE_STORAGE_PREFIX', 'transcriptions'),
    'upload_expiration_minutes' => env('TRANSCRIBE_UPLOAD_EXPIRES', 20),
    'translation_queue' => env('TRANSCRIBE_TRANSLATION_QUEUE', 'translations'),
    'process_timeout_seconds' => env('TRANSCRIBE_PROCESS_TIMEOUT', 1200),
    'translation' => [
        'batch_size' => (int) env('TRANSCRIBE_TRANSLATION_BATCH_SIZE', 20),
        'throttle_ms' => (int) env('TRANSCRIBE_TRANSLATION_THROTTLE_MS', 300),
        'retry_delays' => array_values(array_filter(array_map(
            static fn (string $delay): int => (int) trim($delay),
            explode(',', (string) env('TRANSCRIBE_TRANSLATION_RETRY_DELAYS', '1000,2000,4000,8000')),
        ), static fn (int $delay): bool => $delay >= 0)),
        'retry_only_429' => (bool) env('TRANSCRIBE_TRANSLATION_RETRY_ONLY_429', true),
    ],
    'ffmpeg_path' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_path' => env('FFPROBE_BINARY', 'ffprobe'),
    'temp_directory' => env('TRANSCRIBE_TEMP_DIR', storage_path('app/private/transcriptions')),
    'silence' => [
        'min_seconds' => env('TRANSCRIBE_SILENCE_MIN_SECONDS', 0.8),
        'noise' => env('TRANSCRIBE_SILENCE_NOISE', '-30dB'),
    ],
    'chunk' => [
        'min_seconds' => env('TRANSCRIBE_CHUNK_MIN_SECONDS', 45),
        'max_seconds' => env('TRANSCRIBE_CHUNK_MAX_SECONDS', 120),
        'overlap_seconds' => env('TRANSCRIBE_CHUNK_OVERLAP_SECONDS', 4),
    ],
    'subtitle' => [
        'max_chars_per_line' => env('TRANSCRIBE_SUBTITLE_MAX_CHARS_PER_LINE', 42),
        'max_lines' => env('TRANSCRIBE_SUBTITLE_MAX_LINES', 2),
        'min_duration' => env('TRANSCRIBE_SUBTITLE_MIN_DURATION', 1),
        'max_duration' => env('TRANSCRIBE_SUBTITLE_MAX_DURATION', 6),
        'max_chars_per_second' => env('TRANSCRIBE_SUBTITLE_MAX_CHARS_PER_SECOND', 17),
        'gap_seconds' => env('TRANSCRIBE_SUBTITLE_GAP_SECONDS', 0.05),
    ],
    'pipeline' => [
        'stop_after' => env('TRANSCRIBE_STOP_AFTER', 'whisper'),
    ],
    'queue' => [
        'start_timeout_seconds' => env('TRANSCRIBE_START_TIMEOUT', 3600),
        'process_timeout_seconds' => env('TRANSCRIBE_CHUNK_TIMEOUT', 1800),
    ],
    'download' => [
        'max_attempts' => env('TRANSCRIBE_DOWNLOAD_MAX_ATTEMPTS', 3),
        'backoff_seconds' => env('TRANSCRIBE_DOWNLOAD_BACKOFF_SECONDS', 5),
        'max_in_memory_mb' => env('TRANSCRIBE_DOWNLOAD_MAX_IN_MEMORY_MB', 200),
        'chunk_bytes' => env('TRANSCRIBE_DOWNLOAD_CHUNK_BYTES', 8 * 1024 * 1024),
        'progress_bytes' => env('TRANSCRIBE_DOWNLOAD_PROGRESS_BYTES', 50 * 1024 * 1024),
        'use_temporary_url' => env('TRANSCRIBE_DOWNLOAD_USE_TEMP_URL', true),
        'url_expiration_minutes' => env('TRANSCRIBE_DOWNLOAD_URL_MINUTES', 60),
        'http_timeout_seconds' => env('TRANSCRIBE_DOWNLOAD_HTTP_TIMEOUT', 3600),
        'http_connect_timeout_seconds' => env('TRANSCRIBE_DOWNLOAD_HTTP_CONNECT_TIMEOUT', 10),
    ],
    'providers' => [
        'stt' => [
            'driver' => env('TRANSCRIBE_STT_DRIVER', 'whisper'),
            'whisper' => [
                'api_key' => env('OPENAI_API_KEY'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
                'model' => env('OPENAI_WHISPER_MODEL', 'whisper-1'),
            ],
            'whisper_cpp' => [
                'binary' => env('WHISPER_CPP_BIN', 'whisper-cli'),
                'model' => env('WHISPER_CPP_MODEL'),
                'threads' => env('WHISPER_CPP_THREADS'),
                'output_format' => env('WHISPER_CPP_OUTPUT_FORMAT', 'srt'),
                'best_of' => env('WHISPER_CPP_BEST_OF', 7),
                'beam_size' => env('WHISPER_CPP_BEAM_SIZE', 7),
                'suppress_nst' => env('WHISPER_CPP_SUPPRESS_NST', true),
                'no_gpu' => env('WHISPER_CPP_NO_GPU', false),
                'timeout' => env('WHISPER_CPP_TIMEOUT'),
            ],
        ],
        'translation' => [
            'driver' => env('TRANSCRIBE_TRANSLATION_DRIVER', 'azure'),
            'azure' => [
                'api_key' => env('AZURE_TRANSLATOR_API_KEY'),
                'base_url' => env('AZURE_TRANSLATOR_BASE_URL', 'https://api.cognitive.microsofttranslator.com'),
                'region' => env('AZURE_TRANSLATOR_REGION'),
                'api_version' => env('AZURE_TRANSLATOR_API_VERSION', '3.0'),
            ],
            'deepl' => [
                'api_key' => env('DEEPL_API_KEY'),
                'base_url' => env('DEEPL_BASE_URL', 'https://api.deepl.com'),
                'formality' => env('DEEPL_FORMALITY'),
            ],
        ],
    ],
];
