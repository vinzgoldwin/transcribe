<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { useToasts } from '@/composables/useToasts';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { store as storeTranscription, translate as translateTranscription } from '@/routes/transcriptions';
import { formatAbsoluteTime, formatRelativeTime } from '@/lib/utils';

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
        default_stop_after: string;
    };
}>();

const breadcrumbs = [
    {
        title: 'Transcribe',
        href: dashboard().url,
    },
];

const fileInput = ref<HTMLInputElement | null>(null);
const uploadSection = ref<HTMLElement | null>(null);
const selectedFile = ref<File | null>(null);
const isDragging = ref(false);
const stopAfter = ref<'whisper' | 'azure'>(
    props.upload.default_stop_after === 'whisper' ? 'whisper' : 'azure',
);
const stage = ref<'idle' | 'presigning' | 'uploading' | 'finalizing' | 'done'>(
    'idle',
);
const errorMessage = ref<string | null>(null);
const statusFilter = ref<
    'all' | 'processing' | 'completed' | 'failed' | 'awaiting-translation'
>('all');
const sortBy = ref<'newest' | 'oldest' | 'duration'>('newest');
const viewMode = ref<'list' | 'timeline'>('list');
const now = ref(Date.now());
let timeTicker: number | null = null;
const { pushToast } = useToasts();

const statusOptions: { value: typeof statusFilter.value; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'processing', label: 'Processing' },
    { value: 'completed', label: 'Completed' },
    { value: 'awaiting-translation', label: 'Awaiting translation' },
    { value: 'failed', label: 'Failed' },
];

const sortOptions: { value: typeof sortBy.value; label: string }[] = [
    { value: 'newest', label: 'Newest' },
    { value: 'oldest', label: 'Oldest' },
    { value: 'duration', label: 'Longest' },
];

const statusFilterLabel = computed(
    () => statusOptions.find((option) => option.value === statusFilter.value)?.label ?? 'All',
);
const sortLabel = computed(
    () => sortOptions.find((option) => option.value === sortBy.value)?.label ?? 'Newest',
);

// Computed stats
const stats = computed(() => ({
    total: props.transcriptions.length,
    processing: props.transcriptions.filter((t) =>
        ['processing', 'uploading', 'uploaded'].includes(t.status),
    ).length,
    completed: props.transcriptions.filter(t => t.status === 'completed').length,
    awaitingTranslation: props.transcriptions.filter(
        (t) => t.status === 'awaiting-translation',
    ).length,
}));

const awaitingTranslationItems = computed(() =>
    props.transcriptions.filter((t) => t.status === 'awaiting-translation'),
);

const isBusy = computed(() => stage.value !== 'idle' && stage.value !== 'done');
const isBulkTranslating = ref(false);
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

const formatStatus = (status: string) => {
    switch (status) {
        case 'awaiting-translation':
            return 'Awaiting translation';
        case 'completed':
            return 'Completed';
        case 'failed':
            return 'Failed';
        case 'processing':
            return 'Processing';
        case 'uploaded':
            return 'Uploaded';
        case 'uploading':
            return 'Uploading';
        default:
            return status;
    }
};

const statusColor = (status: string) => {
    switch (status) {
        case 'completed':
            return 'text-emerald-500';
        case 'failed':
            return 'text-red-500';
        case 'processing':
        case 'uploading':
        case 'uploaded':
            return 'text-amber-500';
        case 'awaiting-translation':
            return 'text-sky-500';
        default:
            return 'text-muted-foreground';
    }
};

const statusBgColor = (status: string) => {
    switch (status) {
        case 'completed':
            return 'bg-emerald-500';
        case 'failed':
            return 'bg-red-500';
        case 'processing':
        case 'uploading':
        case 'uploaded':
            return 'bg-amber-500';
        case 'awaiting-translation':
            return 'bg-sky-500';
        default:
            return 'bg-[var(--border)]';
    }
};

const progressPercent = (transcription: TranscriptionListItem) => {
    if (!transcription.chunks_total) {
        return 0;
    }

    return Math.min(
        100,
        Math.round(
            (transcription.chunks_completed / transcription.chunks_total) * 100,
        ),
    );
};

const progressBarColor = (status: string) => {
    switch (status) {
        case 'completed':
            return 'bg-emerald-400';
        case 'failed':
            return 'bg-red-400';
        case 'processing':
        case 'uploading':
        case 'uploaded':
            return 'bg-amber-400';
        case 'awaiting-translation':
            return 'bg-sky-400';
        default:
            return 'bg-[var(--border)]';
    }
};

const statusGlow = (status: string) => {
    switch (status) {
        case 'completed':
            return 'shadow-[0_0_12px_rgba(52,211,153,0.6)]';
        case 'failed':
            return 'shadow-[0_0_12px_rgba(239,68,68,0.6)]';
        case 'processing':
        case 'uploading':
        case 'uploaded':
            return 'shadow-[0_0_12px_rgba(251,191,36,0.6)]';
        case 'awaiting-translation':
            return 'shadow-[0_0_12px_rgba(56,189,248,0.6)]';
        default:
            return '';
    }
};

const handlePick = () => {
    fileInput.value?.click();
};

const scrollToUpload = () => {
    uploadSection.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
    });
};

const validateFile = (file: File) => {
    const isMp4 =
        file.type === 'video/mp4' || file.name.toLowerCase().endsWith('.mp4');

    if (!isMp4) {
        errorMessage.value = 'Only MP4 files are supported.';
    }

    return isMp4;
};

const setSelectedFile = (file: File | null) => {
    if (!file) {
        selectedFile.value = null;
        return;
    }

    if (!validateFile(file)) {
        selectedFile.value = null;
        return;
    }

    selectedFile.value = file;
    errorMessage.value = null;
    stage.value = 'idle';
};

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    setSelectedFile(input.files?.[0] ?? null);
};

const onDrop = (event: DragEvent) => {
    event.preventDefault();
    isDragging.value = false;

    const file = event.dataTransfer?.files?.[0] ?? null;
    setSelectedFile(file);
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
        if (!validateFile(selectedFile.value)) {
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
                stop_after: stopAfter.value,
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
        pushToast('Upload queued. Opening run...', {
            variant: 'success',
        });

        router.visit(completePayload.show_url);
    } catch (error) {
        stage.value = 'idle';
        errorMessage.value =
            error instanceof Error ? error.message : 'Unexpected error.';
        pushToast(errorMessage.value, { variant: 'error' });
    }
};

const startBulkTranslation = async () => {
    if (isBulkTranslating.value || isBusy.value) {
        return;
    }

    const items = awaitingTranslationItems.value;

    if (items.length === 0) {
        return;
    }

    isBulkTranslating.value = true;

    try {
        const csrf = getCsrfToken();

        for (const transcription of items) {
            const response = await fetch(
                translateTranscription({ transcription: transcription.id }).url,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                },
            );

            if (!response.ok && response.status !== 302) {
                throw new Error('Unable to start translation for all items.');
            }
        }

        pushToast(
            `Queued ${items.length} translation${items.length === 1 ? '' : 's'}.`,
            { variant: 'success' },
        );
        router.reload({ only: ['transcriptions'] });
    } catch (error) {
        const message =
            error instanceof Error ? error.message : 'Translation request failed.';
        pushToast(message, { variant: 'error' });
    } finally {
        isBulkTranslating.value = false;
    }
};

const filteredTranscriptions = computed(() => {
    let items = [...props.transcriptions];

    if (statusFilter.value !== 'all') {
        items = items.filter((transcription) => {
            if (statusFilter.value === 'processing') {
                return ['processing', 'uploading', 'uploaded'].includes(
                    transcription.status,
                );
            }

            return transcription.status === statusFilter.value;
        });
    }

    items.sort((a, b) => {
        if (sortBy.value === 'duration') {
            return (b.duration_seconds ?? 0) - (a.duration_seconds ?? 0);
        }

        const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
        const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;

        return sortBy.value === 'oldest' ? aTime - bTime : bTime - aTime;
    });

    return items;
});

const resetFilters = () => {
    statusFilter.value = 'all';
    sortBy.value = 'newest';
};

const handleKeydown = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement | null;
    const tagName = target?.tagName ?? '';

    if (target?.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(tagName)) {
        return;
    }

    if (event.shiftKey && event.key.toLowerCase() === 'u') {
        event.preventDefault();
        scrollToUpload();
        handlePick();
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    timeTicker = window.setInterval(() => {
        now.value = Date.now();
    }, 60000);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
    if (timeTicker) {
        clearInterval(timeTicker);
    }
});
</script>

<template>
    <Head title="Transcribe" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-10 px-6 pb-10 pt-6 lg:px-10">
            <!-- Hero Section with Upload -->
            <section
                ref="uploadSection"
                class="panel-noise animate-transcribe-rise relative overflow-hidden rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-6 shadow-[0_30px_70px_-50px_rgba(15,23,42,0.4)] backdrop-blur lg:p-10"
            >

                <div
                    class="grid items-start gap-10 lg:grid-cols-[1.1fr_0.9fr]"
                >
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col gap-4">
                            <p
                                class="text-[11px] uppercase tracking-[0.4em] text-muted-foreground"
                            >
                                Production Transcribe
                            </p>
                            <h1
                                class="max-w-xl text-3xl font-semibold tracking-[-0.02em] text-[var(--text)] sm:text-4xl lg:text-5xl"
                            >
                                Japanese audio in. Subtitle-ready English out.
                            </h1>
                            <p
                                class="max-w-lg text-sm text-muted-foreground sm:text-base"
                            >
                                Direct-to-storage uploads, silence-aware
                                chunking, queued transcription, and subtitle
                                formatting built for broadcast-safe cadence.
                            </p>
                        </div>

                        <p
                            class="text-xs font-semibold uppercase tracking-[0.3em] text-muted-foreground"
                        >
                            Queue-first · Silence-aware · JP → EN
                        </p>

                        <div
                            class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-5 shadow-[0_16px_36px_-28px_rgba(15,23,42,0.3)]"
                        >
                            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                                How it works
                            </p>
                            <div
                                class="mt-4 grid gap-3 text-xs font-medium text-[var(--text)] sm:grid-cols-5"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[var(--surface-2)] animate-transcribe-step"
                                        style="--step-delay: 0s"
                                    ></span>
                                    Upload
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[var(--surface-2)] animate-transcribe-step"
                                        style="--step-delay: 1.2s"
                                    ></span>
                                    Detect pauses
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[var(--surface-2)] animate-transcribe-step"
                                        style="--step-delay: 2.4s"
                                    ></span>
                                    Transcribe
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[var(--surface-2)] animate-transcribe-step"
                                        style="--step-delay: 3.6s"
                                    ></span>
                                    Translate
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2.5 w-2.5 rounded-full bg-[var(--surface-2)] animate-transcribe-step"
                                        style="--step-delay: 4.8s"
                                    ></span>
                                    Export
                                </div>
                            </div>
                            <div
                                class="relative mt-4 h-1.5 overflow-hidden rounded-full bg-[var(--surface-2)]"
                            >
                                <div
                                    class="absolute inset-y-0 left-0 w-16 animate-transcribe-progress rounded-full bg-[linear-gradient(90deg,transparent,rgba(255,255,255,0.6),transparent)]"
                                ></div>
                            </div>
                            <p class="mt-3 text-xs text-muted-foreground">
                                Captions stay readable: 2 lines max, 1-6s on
                                screen, pacing auto-balanced.
                            </p>
                        </div>
                    </div>

                    <!-- Upload Card -->
                    <div
                        class="relative flex flex-col gap-6 rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/85 p-6 shadow-[0_25px_60px_-45px_rgba(15,23,42,0.45)] backdrop-blur"
                    >
                        <div class="flex flex-col gap-3">
                            <p
                                class="text-xs uppercase tracking-[0.3em] text-muted-foreground"
                            >
                                Upload mp4
                            </p>
                            <h2
                                class="text-2xl font-semibold text-[var(--text)]"
                            >
                                Start a new transcription
                            </h2>
                            <p
                                class="text-sm text-muted-foreground"
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
                            class="group relative flex min-h-[140px] flex-col items-start justify-center gap-3 rounded-2xl border border-dashed border-[color:var(--border)]/80 bg-[var(--surface)]/80 px-6 text-left text-sm text-muted-foreground transition hover:border-[color:var(--accent)]/60 hover:text-foreground"
                            :class="isDragging ? 'border-[color:var(--accent)]/70 bg-[var(--accent-soft)]' : ''"
                            @click="handlePick"
                            @dragover.prevent="isDragging = true"
                            @dragleave.prevent="isDragging = false"
                            @drop="onDrop"
                        >
                            <span
                                class="text-[11px] uppercase tracking-[0.2em] text-muted-foreground/80 transition group-hover:text-muted-foreground"
                            >
                                Select mp4
                            </span>
                            <span class="text-base font-semibold text-[var(--text)]">
                                {{
                                    selectedFile
                                        ? selectedFile.name
                                        : 'Drop a Japanese-audio MP4 here'
                                }}
                            </span>
                            <span class="text-xs text-muted-foreground/80">
                                {{
                                    selectedFile
                                        ? `${(selectedFile.size / 1024 / 1024).toFixed(2)} MB`
                                        : 'Direct-to-storage - no PHP uploads'
                                }}
                            </span>
                        </button>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">
                                MP4 only. Shortcut: Shift + U
                            </span>
                            <button
                                v-if="selectedFile"
                                type="button"
                                class="text-[11px] font-semibold uppercase tracking-[0.2em] text-[var(--accent)]"
                                @click="handlePick"
                            >
                                Replace file
                            </button>
                        </div>
                        <p
                            v-if="errorMessage"
                            class="rounded-xl border border-red-200/60 bg-red-50/80 px-3 py-2 text-xs text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"
                        >
                            {{ errorMessage }}
                        </p>
                        <div
                            v-if="errorMessage"
                            class="flex flex-wrap items-center gap-3 text-xs uppercase tracking-[0.2em]"
                        >
                            <button
                                type="button"
                                class="rounded-full bg-primary px-4 py-2 font-semibold text-primary-foreground shadow-[0_12px_24px_-18px_rgba(15,23,42,0.35)] disabled:opacity-50"
                                :disabled="!selectedFile"
                                @click="startUpload"
                            >
                                Retry upload
                            </button>
                            <button
                                type="button"
                                class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-4 py-2 font-semibold text-muted-foreground"
                                @click="handlePick"
                            >
                                Pick another file
                            </button>
                        </div>

                        <div class="flex flex-col gap-2">
                            <span
                                class="text-[11px] uppercase tracking-[0.3em] text-muted-foreground"
                            >
                                Stop after
                            </span>
                            <div
                                class="inline-flex w-full rounded-full border border-[color:var(--border)]/70 bg-[var(--surface-2)] p-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                            >
                                <button
                                    type="button"
                                    class="flex-1 rounded-full px-3 py-2 transition"
                                    :class="
                                        stopAfter === 'azure'
                                            ? 'bg-[var(--surface)] text-[var(--text)] shadow-[0_10px_20px_-14px_rgba(15,23,42,0.3)]'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    @click="stopAfter = 'azure'"
                                >
                                    Azure (EN)
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 rounded-full px-3 py-2 transition"
                                    :class="
                                        stopAfter === 'whisper'
                                            ? 'bg-[var(--surface)] text-[var(--text)] shadow-[0_10px_20px_-14px_rgba(15,23,42,0.3)]'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    @click="stopAfter = 'whisper'"
                                >
                                    Whisper (JP)
                                </button>
                            </div>
                            <p
                                class="text-xs text-muted-foreground"
                            >
                                Whisper stops with a JP SRT for manual
                                translation.
                            </p>
                        </div>

                        <div class="flex flex-col gap-3">
                            <button
                                type="button"
                                class="rounded-full bg-primary px-5 py-3 text-sm font-semibold uppercase tracking-[0.2em] text-primary-foreground shadow-[0_20px_40px_-24px_rgba(15,23,42,0.4)] transition hover:translate-y-[-1px] hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!selectedFile || isBusy"
                                @click="startUpload"
                            >
                                {{ isBusy ? 'Processing...' : 'Queue Transcription' }}
                            </button>

                            <div
                                class="flex items-center gap-3 text-xs uppercase tracking-[0.2em] text-muted-foreground"
                            >
                                <span
                                    class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_12px_rgba(52,211,153,0.8)]"
                                />
                                {{ statusLabel }}
                            </div>

                            <p class="text-xs text-muted-foreground">
                                Press Shift + U to pick a file quickly.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Stats Overview -->
            <section class="animate-transcribe-fade grid gap-4 sm:grid-cols-2 lg:grid-cols-4" style="animation-delay: 0.1s">
                <!-- Total -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-5 shadow-[0_15px_40px_-24px_rgba(15,23,42,0.25)] backdrop-blur-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-[0_20px_50px_-22px_rgba(15,23,42,0.3)]"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] uppercase tracking-[0.3em] text-muted-foreground">
                                Total
                            </p>
                            <p class="mt-1 text-3xl font-semibold text-[var(--text)]">
                                {{ stats.total }}
                            </p>
                        </div>
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--surface-2)]"
                        >
                            <svg class="h-5 w-5 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Processing -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-5 shadow-[0_15px_40px_-24px_rgba(15,23,42,0.25)] backdrop-blur-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-[0_20px_50px_-22px_rgba(15,23,42,0.3)]"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] uppercase tracking-[0.3em] text-muted-foreground">
                                Processing
                            </p>
                            <p class="mt-1 text-3xl font-semibold text-amber-500">
                                {{ stats.processing }}
                            </p>
                        </div>
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10"
                        >
                            <div class="relative">
                                <div v-if="stats.processing > 0" class="h-3 w-3 animate-ping rounded-full bg-amber-400 opacity-75" />
                                <div class="absolute inset-0 h-3 w-3 rounded-full bg-amber-500" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Completed -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-5 shadow-[0_15px_40px_-24px_rgba(15,23,42,0.25)] backdrop-blur-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-[0_20px_50px_-22px_rgba(15,23,42,0.3)]"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] uppercase tracking-[0.3em] text-muted-foreground">
                                Completed
                            </p>
                            <p class="mt-1 text-3xl font-semibold text-emerald-500">
                                {{ stats.completed }}
                            </p>
                        </div>
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10"
                        >
                            <svg class="h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Awaiting Translation -->
                <div
                    class="group relative overflow-hidden rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-5 shadow-[0_15px_40px_-24px_rgba(15,23,42,0.25)] backdrop-blur-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-[0_20px_50px_-22px_rgba(15,23,42,0.3)]"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] uppercase tracking-[0.3em] text-muted-foreground">
                                Awaiting
                            </p>
                            <p class="mt-1 text-3xl font-semibold text-sky-500">
                                {{ stats.awaitingTranslation }}
                            </p>
                        </div>
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/10"
                        >
                            <svg class="h-5 w-5 text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                            </svg>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Recent Runs with Enhanced List -->
            <section class="animate-transcribe-fade flex flex-col gap-4" style="animation-delay: 0.2s">
                <div class="flex items-center justify-between">
                    <h3
                        class="text-xl font-semibold text-[var(--text)]"
                    >
                        Recent runs
                    </h3>
                    <div class="flex items-center gap-3">
                        <button
                            v-if="stats.awaitingTranslation > 0"
                            type="button"
                            class="rounded-full bg-primary px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-primary-foreground shadow-[0_12px_24px_-18px_rgba(15,23,42,0.35)] transition hover:-translate-y-0.5 hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="isBulkTranslating || isBusy"
                            @click="startBulkTranslation"
                        >
                            {{ isBulkTranslating ? 'Starting...' : `Translate ${stats.awaitingTranslation} awaiting` }}
                        </button>
                        <div
                            class="flex items-center gap-1 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-1 text-[10px] uppercase tracking-[0.2em] text-muted-foreground"
                        >
                            <button
                                type="button"
                                class="rounded-full px-3 py-2 font-semibold transition"
                                :class="viewMode === 'list' ? 'bg-[var(--surface)] text-[var(--text)] shadow-[0_8px_16px_-12px_rgba(15,23,42,0.25)]' : ''"
                                @click="viewMode = 'list'"
                            >
                                List
                            </button>
                            <button
                                type="button"
                                class="rounded-full px-3 py-2 font-semibold transition"
                                :class="viewMode === 'timeline' ? 'bg-[var(--surface)] text-[var(--text)] shadow-[0_8px_16px_-12px_rgba(15,23,42,0.25)]' : ''"
                                @click="viewMode = 'timeline'"
                            >
                                Timeline
                            </button>
                        </div>
                        <span
                            class="text-xs uppercase tracking-[0.3em] text-muted-foreground"
                        >
                            {{ filteredTranscriptions.length }} shown
                        </span>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center gap-4 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-4 text-xs text-muted-foreground"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.25em]">
                            Status
                        </span>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-2 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-4 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--text)] shadow-[0_10px_20px_-16px_rgba(15,23,42,0.25)] transition hover:border-[color:var(--accent)]/40"
                                >
                                    {{ statusFilterLabel }}
                                    <ChevronDown class="size-4 text-muted-foreground" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="min-w-[200px] rounded-2xl border border-[color:var(--border)]/80 bg-[var(--surface)]/95 p-2 text-[var(--text)] shadow-[0_20px_40px_-28px_rgba(15,23,42,0.4)] backdrop-blur"
                                align="start"
                                :side-offset="8"
                            >
                                <DropdownMenuItem
                                    v-for="option in statusOptions"
                                    :key="option.value"
                                    class="cursor-pointer rounded-xl px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground focus:bg-[var(--surface-2)] focus:text-[var(--text)]"
                                    :class="option.value === statusFilter ? 'bg-[var(--surface-2)] text-[var(--text)]' : ''"
                                    @click="statusFilter = option.value"
                                >
                                    {{ option.label }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.25em]">
                            Sort
                        </span>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-2 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-4 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--text)] shadow-[0_10px_20px_-16px_rgba(15,23,42,0.25)] transition hover:border-[color:var(--accent)]/40"
                                >
                                    {{ sortLabel }}
                                    <ChevronDown class="size-4 text-muted-foreground" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="min-w-[180px] rounded-2xl border border-[color:var(--border)]/80 bg-[var(--surface)]/95 p-2 text-[var(--text)] shadow-[0_20px_40px_-28px_rgba(15,23,42,0.4)] backdrop-blur"
                                align="start"
                                :side-offset="8"
                            >
                                <DropdownMenuItem
                                    v-for="option in sortOptions"
                                    :key="option.value"
                                    class="cursor-pointer rounded-xl px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground focus:bg-[var(--surface-2)] focus:text-[var(--text)]"
                                    :class="option.value === sortBy ? 'bg-[var(--surface-2)] text-[var(--text)]' : ''"
                                    @click="sortBy = option.value"
                                >
                                    {{ option.label }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    <button
                        v-if="statusFilter !== 'all' || sortBy !== 'newest'"
                        type="button"
                        class="ml-auto rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground transition hover:text-foreground"
                        @click="resetFilters"
                    >
                        Clear filters
                    </button>
                </div>

                <div
                    v-if="filteredTranscriptions.length === 0"
                    class="rounded-2xl border border-dashed border-[color:var(--border)]/70 bg-[var(--surface)]/60 p-8 text-center"
                >
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-[var(--surface-2)]">
                        <svg class="h-8 w-8 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z" />
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-[var(--text)]">
                        {{
                            props.transcriptions.length === 0
                                ? 'No transcriptions yet'
                                : 'No transcriptions match this view'
                        }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            props.transcriptions.length === 0
                                ? 'Upload a new MP4 to get started.'
                                : 'Adjust filters or upload a new MP4 to get started.'
                        }}
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-3">
                        <button
                            type="button"
                            class="rounded-full bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-primary-foreground shadow-[0_12px_24px_-18px_rgba(15,23,42,0.35)]"
                            @click="
                                () => {
                                    scrollToUpload();
                                    handlePick();
                                }
                            "
                        >
                            Upload MP4
                        </button>
                        <button
                            v-if="statusFilter !== 'all' || sortBy !== 'newest'"
                            type="button"
                            class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                            @click="resetFilters"
                        >
                            Clear filters
                        </button>
                    </div>
                </div>

                <div v-else-if="viewMode === 'list'" class="grid gap-3">
                    <Link
                        v-for="(transcription, index) in filteredTranscriptions"
                        :key="transcription.id"
                        :href="transcription.show_url"
                        class="group relative flex items-center gap-4 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-5 text-sm text-muted-foreground shadow-[0_18px_35px_-28px_rgba(15,23,42,0.3)] transition-all duration-300 hover:-translate-y-[2px] hover:border-[color:var(--border)] hover:shadow-[0_25px_50px_-20px_rgba(15,23,42,0.35)]"
                        :style="{ animationDelay: `${0.02 * index}s` }"
                    >
                        <div
                            class="h-3 w-3 shrink-0 rounded-full"
                            :class="[statusBgColor(transcription.status), statusGlow(transcription.status)]"
                        />

                        <div class="min-w-0 flex-1">
                            <span class="truncate text-base font-semibold text-[var(--text)]">
                                {{ transcription.filename }}
                            </span>
                            <div class="mt-2 h-0.5 w-full overflow-hidden rounded-full bg-[var(--surface-2)]/80">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="progressBarColor(transcription.status)"
                                    :style="{ width: `${progressPercent(transcription)}%` }"
                                ></div>
                            </div>
                            <div
                                class="mt-1 flex flex-wrap items-center gap-4 text-xs uppercase tracking-[0.15em] text-muted-foreground"
                            >
                                <span>
                                    {{
                                        transcription.duration_seconds
                                            ? `${transcription.duration_seconds.toFixed(1)}s`
                                            : 'Pending'
                                    }}
                                </span>
                                <span>
                                    {{ transcription.chunks_completed }} /
                                    {{ transcription.chunks_total }} chunks
                                </span>
                                <span
                                    v-if="transcription.created_at"
                                    :title="formatAbsoluteTime(transcription.created_at)"
                                >
                                    {{ formatRelativeTime(transcription.created_at, now.value) }}
                                </span>
                            </div>
                        </div>

                        <div class="flex w-32 justify-center">
                            <span
                                class="status-pill"
                                :class="statusColor(transcription.status)"
                            >
                                {{ formatStatus(transcription.status) }}
                            </span>
                        </div>

                        <svg
                            class="h-5 w-5 shrink-0 text-muted-foreground/50 transition-transform group-hover:translate-x-1"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </Link>
                </div>
                <div v-else class="relative pl-6">
                    <div class="absolute left-2 top-0 h-full w-px bg-[var(--border)]/70"></div>
                    <div class="grid gap-6">
                        <Link
                            v-for="(transcription, index) in filteredTranscriptions"
                            :key="transcription.id"
                            :href="transcription.show_url"
                            class="group relative flex items-start gap-4"
                            :style="{ animationDelay: `${0.02 * index}s` }"
                        >
                            <div
                                class="mt-2 h-3 w-3 rounded-full"
                                :class="[statusBgColor(transcription.status), statusGlow(transcription.status)]"
                            ></div>
                            <div
                                class="flex-1 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/70 p-5 shadow-[0_16px_30px_-26px_rgba(15,23,42,0.3)] transition hover:-translate-y-[2px]"
                            >
                                <div class="flex items-center justify-between gap-4">
                                    <span class="truncate text-base font-semibold text-[var(--text)]">
                                        {{ transcription.filename }}
                                    </span>
                                    <span class="status-pill" :class="statusColor(transcription.status)">
                                        {{ formatStatus(transcription.status) }}
                                    </span>
                                </div>
                                <div class="mt-2 h-0.5 w-full overflow-hidden rounded-full bg-[var(--surface-2)]/80">
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="progressBarColor(transcription.status)"
                                        :style="{ width: `${progressPercent(transcription)}%` }"
                                    ></div>
                                </div>
                                <div class="mt-3 flex flex-wrap items-center gap-4 text-xs uppercase tracking-[0.15em] text-muted-foreground">
                                    <span>
                                        {{
                                            transcription.duration_seconds
                                                ? `${transcription.duration_seconds.toFixed(1)}s`
                                                : 'Pending'
                                        }}
                                    </span>
                                    <span>
                                        {{ transcription.chunks_completed }} /
                                        {{ transcription.chunks_total }} chunks
                                    </span>
                                    <span
                                        v-if="transcription.created_at"
                                        :title="formatAbsoluteTime(transcription.created_at)"
                                    >
                                        {{ formatRelativeTime(transcription.created_at, now.value) }}
                                    </span>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
