# Transcribe

Transcribe is a Laravel 12 + Inertia app that turns MP4 videos into English SRT/VTT subtitles.
It supports Japanese and Simplified Chinese, and can read subtitles from:
- Burned-in text (OCR)
- Embedded subtitle tracks
- Audio transcription (Whisper)

## What this app does

1. Upload MP4 to storage (direct upload).
2. Choose subtitle source:
   - Burned-in (OCR) for hardcoded Chinese subs.
   - Embedded track for soft subs inside the MP4.
   - Audio only (Whisper).
   - Auto (OCR -> embedded -> audio).
3. Translate to English.
4. Export SRT + VTT.

## Requirements

- PHP 8.5+
- ffmpeg + ffprobe on PATH
- Queue worker running (database queue by default)
- Storage disk configured (local or S3-compatible)
- OCR (for burned-in subtitles): `tesseract` + Simplified Chinese language pack (`chi_sim`)
- STT:
  - OpenAI Whisper API, or
  - whisper.cpp with a multilingual model (not `.en`)
- Translation API (Azure or DeepL)

## System dependencies (install first)

### macOS (Homebrew)

```bash
brew install ffmpeg tesseract
# Ensure Simplified Chinese language data exists:
# If missing, install additional language packs:
brew install tesseract-lang
```

### Ubuntu / Debian

```bash
sudo apt-get update
sudo apt-get install -y ffmpeg tesseract-ocr tesseract-ocr-chi-sim
```

### Verify installs

```bash
ffmpeg -version
ffprobe -version
tesseract --version
tesseract --list-langs | grep chi_sim
```

## Installation

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

### Start the app (recommended)

```bash
composer run dev
```

This starts the web server, queue worker, logs, and Vite.

If you prefer running manually:

```bash
php artisan serve
php artisan queue:work --queue=translations,default --sleep=1 --tries=1 --timeout=3600
npm run dev
```

## Environment configuration

Common settings in `.env` (see `.env.example` for the full list):

```dotenv
# Storage
TRANSCRIBE_STORAGE_DISK=transcriptions
TRANSCRIBE_STORAGE_DRIVER=local   # or s3

# STT (Whisper)
TRANSCRIBE_STT_DRIVER=whisper
OPENAI_API_KEY=...
OPENAI_BASE_URL=https://api.openai.com
OPENAI_WHISPER_MODEL=whisper-1

# whisper.cpp (optional)
WHISPER_CPP_BIN=whisper-cli
WHISPER_CPP_MODEL=/path/to/model.bin   # must be multilingual for Chinese

# Translation
TRANSCRIBE_TRANSLATION_DRIVER=deepl    # or azure
DEEPL_API_KEY=...
DEEPL_BASE_URL=https://api.deepl.com

# OCR for burned-in subtitles
TRANSCRIBE_OCR_ENABLED=true
TRANSCRIBE_OCR_BINARY=tesseract
TRANSCRIBE_OCR_LANGUAGE=chi_sim
TRANSCRIBE_OCR_FPS=2
TRANSCRIBE_OCR_CROP_WIDTH=0.8
TRANSCRIBE_OCR_CROP_HEIGHT=0.2
TRANSCRIBE_OCR_CROP_BOTTOM_PADDING=0.03
```

## Usage

1. Open `/dashboard`.
2. Upload an MP4.
3. Select **Source language** (Japanese or Chinese).
4. Pick **Subtitle source**:
   - **Burned-in (OCR)** for hardcoded Chinese subtitles.
   - **Embedded track** for soft subs inside the MP4.
   - **Auto** to try OCR -> embedded -> audio.
   - **Audio only** to force Whisper.
5. Click **Queue Transcription**.
6. Download SRT/VTT when completed.

## Subtitle sources explained

- **Burned-in (OCR)**: Crops the bottom-center band and runs Tesseract (best for your case).
- **Embedded track**: Extracts subtitle streams inside the MP4 container.
- **Auto**: Tries OCR, then embedded track, then audio.
- **Audio only**: Always uses Whisper STT.

Note: Auto is the only mode that falls back. If you explicitly choose Burned-in or Embedded and no subtitles are found, the run fails.

## Testing

This project requires tests to run with an in-memory SQLite DB:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --compact
```

## Troubleshooting OCR quality

If OCR misses text or has low accuracy, adjust these:
- `TRANSCRIBE_OCR_CROP_WIDTH` / `TRANSCRIBE_OCR_CROP_HEIGHT` / `TRANSCRIBE_OCR_CROP_BOTTOM_PADDING`
- `TRANSCRIBE_OCR_FPS` (higher = more accurate but slower)
- `TRANSCRIBE_OCR_FILTERS` (contrast/sharpen filters)

If UI changes do not show up, run `npm run dev` or `composer run dev`.
