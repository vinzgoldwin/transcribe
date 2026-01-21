<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { store as storeTranscription } from '@/routes/transcriptions';

interface TranscriptionListItem {
    id: string;
    filename: string;
    status: string;
    created_at: string | null;
    duration_seconds: number | null;
    chunks_total: number;
    chunks_completed: number;
    show_url: string;
}

const props = defineProps<{
    transcriptions: TranscriptionListItem[];
    upload: {
        expires_minutes: number;
        storage_disk: string;
    };
}>();

const breadcrumbs = [
    {
        title: 'Transcribe',
        href: dashboard().url,
    },
];

const fileInput = ref<HTMLInputElement | null>(null);
const selectedFile = ref<File | null>(null);
const stage = ref<'idle' | 'presigning' | 'uploading' | 'finalizing' | 'done'>(
    'idle',
);
const errorMessage = ref<string | null>(null);

const isBusy = computed(() => stage.value !== 'idle' && stage.value !== 'done');
const statusLabel = computed(() => {
    switch (stage.value) {
        case 'presigning':
            return 'Preparing direct upload...';
        case 'uploading':
            return 'Streaming the MP4 to storage...';
        case 'finalizing':
            return 'Queueing transcription jobs...';
        case 'done':
            return 'Queued. Redirecting...';
        default:
            return 'Drop an MP4 with Japanese audio.';
    }
});

const handlePick = () => {
    fileInput.value?.click();
};

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    selectedFile.value = input.files?.[0] ?? null;
    errorMessage.value = null;
    stage.value = 'idle';
};

const resetUpload = () => {
    stage.value = 'idle';
    errorMessage.value = null;
};

const getCsrfToken = () => {
    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    if (!token) {
        throw new Error('Missing CSRF token.');
    }

    return token;
};

const startUpload = async () => {
    if (!selectedFile.value) {
        return;
    }

    resetUpload();

    try {
        if (
            selectedFile.value.type &&
            selectedFile.value.type !== 'video/mp4' &&
            !selectedFile.value.name.toLowerCase().endsWith('.mp4')
        ) {
            throw new Error('Only MP4 files are supported.');
        }

        stage.value = 'presigning';
        const csrf = getCsrfToken();

        const presignResponse = await fetch(storeTranscription().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                filename: selectedFile.value.name,
                content_type: selectedFile.value.type || 'video/mp4',
                size_bytes: selectedFile.value.size,
            }),
        });

        if (!presignResponse.ok) {
            throw new Error('Unable to prepare upload.');
        }

        const presignPayload = await presignResponse.json();

        stage.value = 'uploading';
        const uploadHeaders = {
            ...(presignPayload.upload.headers ?? {}),
            'Content-Type': selectedFile.value.type || 'video/mp4',
        } as Record<string, string>;

        const uploadResponse = await fetch(presignPayload.upload.url, {
            method: presignPayload.upload.method ?? 'PUT',
            headers: uploadHeaders,
            body: selectedFile.value,
        });

        if (!uploadResponse.ok) {
            throw new Error('Upload failed. Please retry.');
        }

        stage.value = 'finalizing';

        const completeResponse = await fetch(presignPayload.complete_url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
        });

        if (!completeResponse.ok) {
            throw new Error('Unable to finalize the upload.');
        }

        const completePayload = await completeResponse.json();
        stage.value = 'done';

        router.visit(completePayload.show_url);
    } catch (error) {
        stage.value = 'idle';
        errorMessage.value =
            error instanceof Error ? error.message : 'Unexpected error.';
    }
};
</script>

<template>
    <Head title="Transcribe">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link
            rel="preconnect"
            href="https://fonts.gstatic.com"
            crossorigin
        />
        <link
            href="https://fonts.googleapis.com/css2?family=Fraunces:wght@400;500;600;700&family=Manrope:wght@300;400;500;600&display=swap"
            rel="stylesheet"
        />
    </Head>

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-8">
            <section
                class="animate-transcribe-rise relative overflow-hidden rounded-[32px] border border-black/10 bg-[radial-gradient(circle_at_top,_rgba(255,255,255,0.85),_rgba(234,226,214,0.65),_rgba(206,221,226,0.35))] p-6 shadow-[0_30px_80px_-60px_rgba(18,24,38,0.5)] dark:border-white/10 dark:bg-[radial-gradient(circle_at_top,_rgba(20,20,22,0.95),_rgba(12,14,18,0.88),_rgba(5,6,8,0.95))] dark:shadow-[0_30px_80px_-50px_rgba(15,17,21,0.7)] lg:p-10"
            >
                <div
                    class="pointer-events-none absolute -top-24 right-6 h-56 w-56 rounded-full bg-[conic-gradient(from_180deg,_rgba(43,82,84,0.5),_rgba(232,135,64,0.4),_rgba(43,82,84,0.5))] blur-3xl dark:bg-[conic-gradient(from_180deg,_rgba(54,126,129,0.4),_rgba(175,92,30,0.3),_rgba(54,126,129,0.4))]"
                />
                <div
                    class="pointer-events-none absolute bottom-[-140px] left-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,_rgba(87,121,179,0.35),_transparent_65%)] blur-3xl"
                />

                <div
                    class="grid items-start gap-10 lg:grid-cols-[1.1fr_0.9fr]"
                >
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col gap-4">
                            <p
                                class="text-[11px] uppercase tracking-[0.4em] text-slate-500 dark:text-slate-400"
                            >
                                Production Transcribe
                            </p>
                            <h1
                                class="max-w-xl font-[Fraunces] text-3xl text-slate-900 dark:text-slate-100 sm:text-4xl lg:text-5xl"
                            >
                                Japanese audio in. Subtitle-ready English out.
                            </h1>
                            <p
                                class="max-w-lg font-[Manrope] text-sm text-slate-600 dark:text-slate-300 sm:text-base"
                            >
                                Direct-to-storage uploads, silence-aware
                                chunking, queued transcription, and subtitle
                                formatting built for broadcast-safe cadence.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <span
                                class="rounded-full border border-black/10 bg-white/70 px-4 py-1 text-xs uppercase tracking-[0.2em] text-slate-600 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-slate-200"
                            >
                                Queue-first
                            </span>
                            <span
                                class="rounded-full border border-black/10 bg-white/70 px-4 py-1 text-xs uppercase tracking-[0.2em] text-slate-600 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-slate-200"
                            >
                                Silence-aware
                            </span>
                            <span
                                class="rounded-full border border-black/10 bg-white/70 px-4 py-1 text-xs uppercase tracking-[0.2em] text-slate-600 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-slate-200"
                            >
                                JP to EN
                            </span>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div
                                class="rounded-2xl border border-black/10 bg-white/70 p-5 font-[Manrope] text-sm text-slate-700 shadow-[0_10px_30px_-18px_rgba(15,23,42,0.35)] dark:border-white/10 dark:bg-white/5 dark:text-slate-200"
                            >
                                <p class="text-xs uppercase tracking-[0.2em]">
                                    Flow
                                </p>
                                <p class="mt-2">
                                    Upload -> Silence chunking -> STT -> Translate
                                    -> Format -> Export
                                </p>
                            </div>
                            <div
                                class="rounded-2xl border border-black/10 bg-white/70 p-5 font-[Manrope] text-sm text-slate-700 shadow-[0_10px_30px_-18px_rgba(15,23,42,0.35)] dark:border-white/10 dark:bg-white/5 dark:text-slate-200"
                            >
                                <p class="text-xs uppercase tracking-[0.2em]">
                                    Constraints
                                </p>
                                <p class="mt-2">
                                    42 chars/line - 2 lines - 1-6s - 17 chars/s
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="relative flex flex-col gap-6 rounded-[28px] border border-black/10 bg-white/80 p-6 shadow-[0_25px_80px_-50px_rgba(15,23,42,0.45)] backdrop-blur dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex flex-col gap-3">
                            <p
                                class="text-xs uppercase tracking-[0.3em] text-slate-500 dark:text-slate-300"
                            >
                                Upload mp4
                            </p>
                            <h2
                                class="font-[Fraunces] text-2xl text-slate-900 dark:text-slate-100"
                            >
                                Start a new transcription
                            </h2>
                            <p
                                class="font-[Manrope] text-sm text-slate-600 dark:text-slate-300"
                            >
                                Direct upload to
                                <span class="font-medium">{{
                                    props.upload.storage_disk
                                }}</span>
                                expires in
                                <span class="font-medium">{{
                                    props.upload.expires_minutes
                                }}</span>
                                minutes.
                            </p>
                        </div>

                        <input
                            ref="fileInput"
                            type="file"
                            accept="video/mp4"
                            class="hidden"
                            @change="onFileChange"
                        />

                        <button
                            type="button"
                            class="group relative flex min-h-[140px] flex-col items-start justify-center gap-3 rounded-2xl border border-dashed border-black/20 bg-white/70 px-6 text-left font-[Manrope] text-sm text-slate-600 transition hover:border-black/40 hover:text-slate-800 dark:border-white/20 dark:bg-white/5 dark:text-slate-300 dark:hover:border-white/40 dark:hover:text-white"
                            @click="handlePick"
                        >
                            <span
                                class="text-xs uppercase tracking-[0.2em] text-slate-400 transition group-hover:text-slate-500 dark:text-slate-400"
                            >
                                Select mp4
                            </span>
                            <span class="text-base font-medium">
                                {{
                                    selectedFile
                                        ? selectedFile.name
                                        : 'Drop a Japanese-audio MP4 here'
                                }}
                            </span>
                            <span class="text-xs text-slate-400">
                                {{
                                    selectedFile
                                        ? `${(selectedFile.size / 1024 / 1024).toFixed(2)} MB`
                                        : 'Direct-to-storage - no PHP uploads'
                                }}
                            </span>
                        </button>

                        <div class="flex flex-col gap-3">
                            <button
                                type="button"
                                class="rounded-full bg-slate-900 px-5 py-3 font-[Manrope] text-sm font-semibold uppercase tracking-[0.2em] text-white shadow-[0_20px_40px_-24px_rgba(15,23,42,0.65)] transition hover:translate-y-[-1px] hover:bg-black disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100"
                                :disabled="!selectedFile || isBusy"
                                @click="startUpload"
                            >
                                {{ isBusy ? 'Processing...' : 'Queue Transcription' }}
                            </button>

                            <div
                                class="flex items-center gap-3 text-xs uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400"
                            >
                                <span
                                    class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_12px_rgba(52,211,153,0.8)]"
                                />
                                {{ statusLabel }}
                            </div>

                            <p
                                v-if="errorMessage"
                                class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"
                            >
                                {{ errorMessage }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="animate-transcribe-fade flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <h3
                        class="font-[Fraunces] text-xl text-slate-900 dark:text-slate-100"
                    >
                        Recent runs
                    </h3>
                    <span
                        class="text-xs uppercase tracking-[0.3em] text-slate-400"
                    >
                        {{ props.transcriptions.length }} total
                    </span>
                </div>

                <div
                    v-if="props.transcriptions.length === 0"
                    class="rounded-2xl border border-dashed border-slate-200 bg-white/50 p-6 text-sm text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                >
                    No transcriptions yet. Upload an MP4 to start.
                </div>

                <div v-else class="grid gap-3">
                    <Link
                        v-for="transcription in props.transcriptions"
                        :key="transcription.id"
                        :href="transcription.show_url"
                        class="group flex flex-col gap-3 rounded-2xl border border-black/10 bg-white/70 p-5 font-[Manrope] text-sm text-slate-600 shadow-[0_18px_35px_-28px_rgba(15,23,42,0.35)] transition hover:-translate-y-[2px] hover:border-black/30 hover:text-slate-900 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-white/30 dark:hover:text-white"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-base font-semibold">
                                {{ transcription.filename }}
                            </span>
                            <span
                                class="rounded-full border border-black/10 px-3 py-1 text-[10px] uppercase tracking-[0.25em] text-slate-500 dark:border-white/10 dark:text-slate-300"
                            >
                                {{ transcription.status }}
                            </span>
                        </div>
                        <div
                            class="flex flex-wrap items-center gap-4 text-xs uppercase tracking-[0.2em] text-slate-400"
                        >
                            <span>
                                {{
                                    transcription.duration_seconds
                                        ? `${transcription.duration_seconds.toFixed(1)}s`
                                        : 'Pending duration'
                                }}
                            </span>
                            <span>
                                {{ transcription.chunks_completed }} /
                                {{ transcription.chunks_total }} chunks
                            </span>
                            <span>{{ transcription.created_at ?? '' }}</span>
                        </div>
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
