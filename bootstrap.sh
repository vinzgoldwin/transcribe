#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
env_created=0
install_whisper_cpp=0
skip_whisper_cpp=0

get_env_value() {
    local key="$1"
    local line
    line="$(grep -E "^${key}=" .env | head -n 1 || true)"

    if [ -z "$line" ]; then
        echo ""
    else
        echo "${line#*=}"
    fi
}

set_env_value() {
    local key="$1"
    local value="$2"

    if grep -qE "^${key}=" .env; then
        awk -v key="$key" -v value="$value" 'BEGIN { FS=OFS="=" } $1 == key { $0 = key "=" value } { print }' .env > .env.tmp
        mv .env.tmp .env
    else
        echo "${key}=${value}" >> .env
    fi
}

ensure_whisper_cpp() {
    local stt_driver
    stt_driver="$(get_env_value TRANSCRIBE_STT_DRIVER)"
    local whisper_cpp_bin
    whisper_cpp_bin="$(get_env_value WHISPER_CPP_BIN)"
    local whisper_cpp_model
    whisper_cpp_model="$(get_env_value WHISPER_CPP_MODEL)"
    local whisper_cpp_root
    whisper_cpp_root="$(get_env_value WHISPER_CPP_ROOT)"

    if [ -z "$whisper_cpp_root" ]; then
        if [ -n "$whisper_cpp_bin" ] && [[ "$whisper_cpp_bin" == *"/whisper.cpp/"* ]]; then
            whisper_cpp_root="${whisper_cpp_bin%%/build/bin/*}"
        else
            whisper_cpp_root="$HOME/whisper.cpp"
        fi
    fi

    local should_install=0
    if [ "$skip_whisper_cpp" -eq 1 ]; then
        should_install=0
    elif [ "$install_whisper_cpp" -eq 1 ] || [ "$env_created" -eq 1 ]; then
        should_install=1
    elif [ "$stt_driver" = "whisper_cpp" ] || [ -n "$whisper_cpp_model" ]; then
        should_install=1
    elif [ -n "$whisper_cpp_bin" ] && [ "$whisper_cpp_bin" != "whisper-cli" ]; then
        should_install=1
    fi

    if [ "$should_install" -eq 0 ]; then
        return 0
    fi

    if { [ "$install_whisper_cpp" -eq 1 ] || [ "$env_created" -eq 1 ]; } && [ "$stt_driver" != "whisper_cpp" ]; then
        set_env_value TRANSCRIBE_STT_DRIVER whisper_cpp
        stt_driver="whisper_cpp"
    fi

    if [ -z "$whisper_cpp_bin" ] || [ "$whisper_cpp_bin" = "whisper-cli" ]; then
        whisper_cpp_bin="$whisper_cpp_root/build/bin/whisper-cli"
        set_env_value WHISPER_CPP_BIN "$whisper_cpp_bin"
    fi

    if [ -z "$whisper_cpp_model" ]; then
        whisper_cpp_model="$whisper_cpp_root/models/ggml-large-v3.bin"
        set_env_value WHISPER_CPP_MODEL "$whisper_cpp_model"
    fi

    if [ ! -d "$whisper_cpp_root" ]; then
        if ! command -v git >/dev/null 2>&1; then
            echo "Missing git; required to install whisper.cpp."
            return 1
        fi
        echo "Cloning whisper.cpp into $whisper_cpp_root"
        git clone --depth 1 https://github.com/ggerganov/whisper.cpp "$whisper_cpp_root"
    fi

    if [ ! -x "$whisper_cpp_bin" ]; then
        if ! command -v cmake >/dev/null 2>&1; then
            echo "Missing cmake; required to build whisper.cpp."
            return 1
        fi
        echo "Building whisper.cpp"
        cmake -S "$whisper_cpp_root" -B "$whisper_cpp_root/build"
        cmake --build "$whisper_cpp_root/build" --parallel
    fi

    if [ ! -f "$whisper_cpp_model" ]; then
        local download_script="$whisper_cpp_root/models/download-ggml-model.sh"
        if [ -f "$download_script" ]; then
            local model_basename
            model_basename="$(basename "$whisper_cpp_model")"
            local model_name
            model_name="${model_basename#ggml-}"
            model_name="${model_name%.bin}"

            if [ -z "$model_name" ]; then
                echo "Unable to infer model name from $whisper_cpp_model"
                return 1
            fi

            echo "Downloading whisper.cpp model ($model_name)"
            (cd "$whisper_cpp_root" && bash "models/download-ggml-model.sh" "$model_name")
        else
            echo "Missing whisper.cpp model at $whisper_cpp_model"
            echo "Download the model manually and rerun the script."
            return 1
        fi
    fi
}

print_usage() {
    cat <<'EOF'
Usage: ./bootstrap.sh [options]

Options:
  --start         Start dev processes (server, queue, logs, vite) after setup.
  --skip-build    Skip Vite build step.
  --skip-tests    Skip running the bootstrap test.
  --install-whisper-cpp  Install whisper.cpp + model using paths in .env.
  --skip-whisper-cpp     Skip whisper.cpp setup (useful if you only use API STT).
  -h, --help      Show this help.
EOF
}

run_dev=0
skip_build=0
skip_tests=0

while [ $# -gt 0 ]; do
    case "$1" in
        --start)
            run_dev=1
            ;;
        --skip-build)
            skip_build=1
            ;;
        --skip-tests)
            skip_tests=1
            ;;
        --install-whisper-cpp)
            install_whisper_cpp=1
            ;;
        --skip-whisper-cpp)
            skip_whisper_cpp=1
            ;;
        -h|--help)
            print_usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            print_usage
            exit 1
            ;;
    esac
    shift
done

cd "$ROOT_DIR"

required_tools=(php composer node npm)
missing_tools=()

for tool in "${required_tools[@]}"; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        missing_tools+=("$tool")
    fi
done

if [ "${#missing_tools[@]}" -ne 0 ]; then
    echo "Missing required tools: ${missing_tools[*]}"
    exit 1
fi

optional_tools=(ffmpeg ffprobe)
missing_optional=()

for tool in "${optional_tools[@]}"; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        missing_optional+=("$tool")
    fi
done

if [ "${#missing_optional[@]}" -ne 0 ]; then
    echo "Warning: missing optional tools: ${missing_optional[*]}"
    echo "Transcription requires ffmpeg + ffprobe on PATH."
fi

if [ ! -f .env ]; then
    cp .env.example .env
    env_created=1
    echo "Created .env from .env.example"
fi

db_connection="$(get_env_value DB_CONNECTION)"
if [ "${db_connection:-}" = "sqlite" ]; then
    if [ ! -f database/database.sqlite ]; then
        touch database/database.sqlite
        echo "Created database/database.sqlite"
    fi
fi

composer install --no-interaction --prefer-dist --optimize-autoloader

app_key_value="$(get_env_value APP_KEY)"
if [ -z "$app_key_value" ]; then
    php artisan key:generate --ansi
fi

php artisan migrate --force

npm install

if [ "$skip_build" -eq 0 ]; then
    npm run build
fi

ensure_whisper_cpp

if [ "$skip_tests" -eq 0 ]; then
    php artisan test --compact tests/Unit/BootstrapScriptTest.php
fi

stt_driver="$(get_env_value TRANSCRIBE_STT_DRIVER)"
translation_driver="$(get_env_value TRANSCRIBE_TRANSLATION_DRIVER)"

if [ "${stt_driver:-}" = "whisper" ]; then
    if ! grep -qE '^OPENAI_API_KEY=.+$' .env; then
        echo "Warning: OPENAI_API_KEY is empty. Set it to use the Whisper API."
    fi
fi

if [ "${translation_driver:-}" = "deepl" ]; then
    if ! grep -qE '^DEEPL_API_KEY=.+$' .env; then
        echo "Warning: DEEPL_API_KEY is empty. Set it to enable translations."
    fi
fi

echo "Setup complete."

if [ "$run_dev" -eq 1 ]; then
    composer run dev
else
    echo "Run: composer run dev"
fi
