# Transcribe

Transcribe is a Laravel 12 + Inertia app for creating Japanese to English subtitle files from MP4 uploads. Uploads go directly to S3-compatible storage, then queued jobs extract audio, detect silences, chunk the audio, transcribe, translate, format subtitles, and export SRT/VTT.

## Core flow

1. Client uploads MP4 via presigned URL (no long PHP uploads).
2. `StartTranscriptionJob` extracts audio, runs silencedetect, and cuts 30-90s chunks with 2s overlap.
3. `ProcessTranscriptionChunkJob` transcribes each chunk (JP), translates to EN, and formats subtitle text.
4. `FinalizeTranscriptionJob` deduplicates overlaps, rebases timestamps, and writes SRT/VTT.

## Requirements

- PHP 8.5+
- ffmpeg + ffprobe available on PATH
- Queue worker running (`database` queue is default)
- S3-compatible storage (or local disk for dev)

## Quick start

```bash
php artisan migrate
php artisan queue:work
npm run dev
```

Then visit `/dashboard` to upload an MP4 and monitor progress.

## Environment configuration

Set these in `.env` (see `.env.example` for full list):

```dotenv
TRANSCRIBE_STORAGE_DISK=transcriptions
TRANSCRIBE_STORAGE_DRIVER=s3   # or local for dev

# S3 (or MinIO) configuration
TRANSCRIBE_S3_BUCKET=your-bucket
TRANSCRIBE_S3_ENDPOINT=https://s3.your-provider.com
TRANSCRIBE_S3_URL=

# STT (Whisper)
OPENAI_API_KEY=...
OPENAI_BASE_URL=https://api.openai.com
OPENAI_WHISPER_MODEL=whisper-1

# Translation (DeepL)
DEEPL_API_KEY=...
DEEPL_BASE_URL=https://api.deepl.com
DEEPL_FORMALITY=default
```

## Storage notes

- `TRANSCRIBE_STORAGE_DRIVER=s3` uses presigned uploads.
- `TRANSCRIBE_STORAGE_DRIVER=local` uses a signed upload endpoint and is intended for dev-only, small files.
- Local files live in `storage/app/private/transcriptions`.

## Queueing

Processing is fully queued. Start a worker:

```bash
php artisan queue:work
```

## ffmpeg / ffprobe commands (reference)

Audio extraction:

```bash
ffmpeg -y -i input.mp4 -vn -ac 1 -ar 16000 -f wav output.wav
```

Silence detection:

```bash
ffmpeg -i output.wav -af silencedetect=noise=-30dB:d=0.6 -f null -
```

Chunk cutting:

```bash
ffmpeg -y -ss 68.000 -t 32.000 -i output.wav -ac 1 -ar 16000 -f wav chunk-2.wav
```

Duration probe:

```bash
ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 output.wav
```

## Testing

```bash
php artisan test --compact tests/Unit/SilenceDetectorTest.php
```

Run the full suite when ready:

```bash
php artisan test --compact
```
