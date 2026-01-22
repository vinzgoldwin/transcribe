<?php

return [
    'storage_disk' => env('TRANSCRIBE_STORAGE_DISK', 'transcriptions'),
    'storage_prefix' => env('TRANSCRIBE_STORAGE_PREFIX', 'transcriptions'),
    'upload_expiration_minutes' => env('TRANSCRIBE_UPLOAD_EXPIRES', 20),
    'process_timeout_seconds' => env('TRANSCRIBE_PROCESS_TIMEOUT', 1200),
    'ffmpeg_path' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_path' => env('FFPROBE_BINARY', 'ffprobe'),
    'temp_directory' => env('TRANSCRIBE_TEMP_DIR', storage_path('app/private/transcriptions')),
    'silence' => [
        'min_seconds' => env('TRANSCRIBE_SILENCE_MIN_SECONDS', 0.6),
        'noise' => env('TRANSCRIBE_SILENCE_NOISE', '-30dB'),
    ],
    'chunk' => [
        'min_seconds' => env('TRANSCRIBE_CHUNK_MIN_SECONDS', 30),
        'max_seconds' => env('TRANSCRIBE_CHUNK_MAX_SECONDS', 90),
        'overlap_seconds' => env('TRANSCRIBE_CHUNK_OVERLAP_SECONDS', 2),
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
                'timeout' => env('WHISPER_CPP_TIMEOUT'),
            ],
        ],
        'translation' => [
            'driver' => env('TRANSCRIBE_TRANSLATION_DRIVER', 'deepl'),
            'deepl' => [
                'api_key' => env('DEEPL_API_KEY'),
                'base_url' => env('DEEPL_BASE_URL', 'https://api.deepl.com'),
                'formality' => env('DEEPL_FORMALITY'),
            ],
        ],
    ],
];
