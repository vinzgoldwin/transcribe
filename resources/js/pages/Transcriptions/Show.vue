<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    ClipboardCopy,
    Download,
    FileDown,
    FileText,
    RefreshCw,
    Sparkles,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatAbsoluteTime, formatRelativeTime } from '@/lib/utils';
import { useToasts } from '@/composables/useToasts';
import { dashboard } from '@/routes';
import {
    download as downloadTranscription,
    status as statusTranscription,
    translate as translateTranscription,
} from '@/routes/transcriptions';

interface TranscriptionDetails {
    id: string;
    filename: string;
    status: string;
    duration_seconds: number | null;
    chunks_total: number;
    chunks_completed: number;
    created_at: string | null;
    error_message: string | null;
    srt_ready: boolean;
    vtt_ready: boolean;
    download_srt_url: string | null;
    download_vtt_url: string | null;
}

const props = defineProps<{
    transcription: TranscriptionDetails;
}>();

const breadcrumbs = [
    {
        title: 'Transcribe',
        href: dashboard().url,
    },
    {
        title: 'Run',
        href: '#',
    },
];

const transcription = ref<TranscriptionDetails>({ ...props.transcription });
const now = ref(Date.now());
const lastUpdatedAt = ref<Date | null>(null);
const shareUrl = ref('');
const isPolling = ref(false);
const isTranslating = ref(false);
const { pushToast } = useToasts();
let pollTimer: number | null = null;
let timeTicker: number | null = null;

const statusLabel = computed(() => {
    switch (transcription.value.status) {
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
            return transcription.value.status;
    }
});

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

const statusSurface = (status: string) => {
    switch (status) {
        case 'completed':
            return 'bg-emerald-500/10 text-emerald-600';
        case 'failed':
            return 'bg-red-500/10 text-red-600';
        case 'processing':
        case 'uploading':
        case 'uploaded':
            return 'bg-amber-500/10 text-amber-600';
        case 'awaiting-translation':
            return 'bg-sky-500/10 text-sky-600';
        default:
            return 'bg-[var(--surface-2)] text-muted-foreground';
    }
};

const isTerminalStatus = computed(() =>
    ['completed', 'failed'].includes(transcription.value.status),
);

const progressPercent = computed(() => {
    if (!transcription.value.chunks_total) {
        return transcription.value.status === 'completed' ? 100 : 0;
    }

    return Math.min(
        100,
        Math.round(
            (transcription.value.chunks_completed /
                transcription.value.chunks_total) *
                100,
        ),
    );
});

const chunkSummary = computed(() => {
    if (!transcription.value.chunks_total) {
        return 'Chunking scheduled';
    }

    return `${transcription.value.chunks_completed} of ${transcription.value.chunks_total} chunks processed`;
});

const formattedDuration = computed(() =>
    formatDuration(transcription.value.duration_seconds),
);

const srtUrl = computed(() => {
    if (transcription.value.download_srt_url) {
        return transcription.value.download_srt_url;
    }

    if (!transcription.value.srt_ready) {
        return null;
    }

    return downloadTranscription({
        transcription: transcription.value.id,
        format: 'srt',
    }).url;
});

const vttUrl = computed(() => {
    if (transcription.value.download_vtt_url) {
        return transcription.value.download_vtt_url;
    }

    if (!transcription.value.vtt_ready) {
        return null;
    }

    return downloadTranscription({
        transcription: transcription.value.id,
        format: 'vtt',
    }).url;
});

const hasAnyDownloads = computed(() => Boolean(srtUrl.value || vttUrl.value));

const showTranslateAction = computed(
    () => transcription.value.status === 'awaiting-translation',
);

const progressRingStyle = computed(() => ({
    background: `conic-gradient(var(--accent) ${progressPercent.value}%, var(--surface-2) 0)`,
}));

const pipelineSteps = computed(() => {
    const status = transcription.value.status;
    const chunksDone =
        transcription.value.chunks_total > 0 &&
        transcription.value.chunks_completed >= transcription.value.chunks_total;
    let currentIndex = 0;

    if (status === 'uploading') {
        currentIndex = 0;
    } else if (status === 'uploaded') {
        currentIndex = 1;
    } else if (status === 'processing') {
        currentIndex = transcription.value.chunks_total > 0 ? 2 : 1;
    } else if (status === 'awaiting-translation') {
        currentIndex = 3;
    } else if (status === 'completed') {
        currentIndex = 4;
    } else if (status === 'failed') {
        currentIndex = chunksDone ? 2 : 1;
    }

    const steps = [
        {
            key: 'upload',
            title: 'Upload',
            description: 'Direct storage ingest',
        },
        {
            key: 'chunk',
            title: 'Silence detection',
            description: 'Segmenting audio',
        },
        {
            key: 'transcribe',
            title: 'Transcription',
            description: 'Japanese to text',
        },
        {
            key: 'translate',
            title: 'Translation',
            description: 'English subtitles',
        },
        {
            key: 'export',
            title: 'Export',
            description: 'SRT + VTT formats',
        },
    ];

    return steps.map((step, index) => {
        let state: 'complete' | 'current' | 'pending' | 'failed' = 'pending';

        if (status === 'completed') {
            state = 'complete';
        } else if (status === 'failed' && index === currentIndex) {
            state = 'failed';
        } else if (index < currentIndex) {
            state = 'complete';
        } else if (index === currentIndex) {
            state = 'current';
        }

        return {
            ...step,
            state,
        };
    });
});

const refreshStatus = async (silent = true) => {
    if (isPolling.value) {
        return;
    }

    isPolling.value = true;

    try {
        const response = await fetch(
            statusTranscription({ transcription: transcription.value.id }).url,
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        if (!response.ok) {
            throw new Error('Unable to refresh status.');
        }

        const payload = await response.json();
        transcription.value = {
            ...transcription.value,
            ...payload,
        };
        lastUpdatedAt.value = new Date();

        if (!silent) {
            pushToast('Status refreshed.', { variant: 'success' });
        }
    } catch (error) {
        if (!silent) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Unable to refresh status.';
            pushToast(message, { variant: 'error' });
        }
    } finally {
        isPolling.value = false;

        if (isTerminalStatus.value && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }
};

const downloadAll = () => {
    const urls = [srtUrl.value, vttUrl.value].filter(
        (url): url is string => Boolean(url),
    );

    if (urls.length === 0) {
        return;
    }

    urls.forEach((url) => {
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.target = '_blank';
        anchor.rel = 'noopener';
        anchor.click();
    });
};

const copyShareLink = async () => {
    if (!shareUrl.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(shareUrl.value);
        pushToast('Share link copied.', { variant: 'success' });
    } catch (error) {
        pushToast('Unable to copy the link.', { variant: 'error' });
    }
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

const startTranslation = async () => {
    if (isTranslating.value || !showTranslateAction.value) {
        return;
    }

    isTranslating.value = true;

    try {
        const csrf = getCsrfToken();
        const response = await fetch(
            translateTranscription({ transcription: transcription.value.id }).url,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            },
        );

        if (!response.ok && response.status !== 302) {
            throw new Error('Unable to start translation.');
        }

        transcription.value.status = 'processing';
        pushToast('Translation queued.', { variant: 'success' });
        await refreshStatus();
    } catch (error) {
        const message =
            error instanceof Error ? error.message : 'Translation failed.';
        pushToast(message, { variant: 'error' });
    } finally {
        isTranslating.value = false;
    }
};

const formatDuration = (value: number | null) => {
    if (!value) {
        return '--';
    }

    const totalSeconds = Math.max(0, Math.round(value));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m ${seconds}s`;
    }

    if (minutes > 0) {
        return `${minutes}m ${seconds}s`;
    }

    return `${seconds}s`;
};

const stageMessage = computed(() => {
    if (transcription.value.status === 'completed') {
        return 'Subtitle files are ready to download.';
    }

    if (transcription.value.status === 'awaiting-translation') {
        return 'Japanese transcript ready. Start translation to generate EN subs.';
    }

    if (transcription.value.status === 'failed') {
        return 'The run failed. Review the error details below.';
    }

    if (transcription.value.status === 'uploaded') {
        return 'Upload complete. Preparing chunk pipeline.';
    }

    if (transcription.value.status === 'uploading') {
        return 'Streaming the asset to storage.';
    }

    return 'Transcription jobs are in progress.';
});

const lastUpdatedLabel = computed(() => {
    if (!lastUpdatedAt.value) {
        return 'Auto-refreshing';
    }

    return `Updated ${formatRelativeTime(lastUpdatedAt.value, now.value)}`;
});

onMounted(() => {
    shareUrl.value = window.location.href;
    timeTicker = window.setInterval(() => {
        now.value = Date.now();
    }, 60000);

    refreshStatus();

    pollTimer = window.setInterval(() => {
        if (!isTerminalStatus.value) {
            refreshStatus();
        }
    }, 6000);
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }

    if (timeTicker) {
        clearInterval(timeTicker);
    }
});
</script>

<template>
    <Head :title="transcription.filename" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="relative flex flex-col gap-8 px-6 pb-12 pt-6 lg:px-10">
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 overflow-hidden"
            >
                <div
                    class="absolute -left-20 top-4 h-[20rem] w-[20rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(332_80%_92%/0.5),transparent_70%)] blur-2xl"
                ></div>
                <div
                    class="absolute right-0 top-[35%] h-[22rem] w-[22rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(210_80%_92%/0.35),transparent_70%)] blur-2xl"
                ></div>
            </div>

            <section
                class="panel-noise animate-transcribe-rise relative overflow-hidden rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/82 p-6 shadow-[0_30px_70px_-50px_rgba(15,23,42,0.4)] backdrop-blur lg:p-10"
            >
                <div
                    class="absolute -right-24 top-[-40%] h-[20rem] w-[20rem] rounded-full bg-[radial-gradient(circle_at_center,hsl(332_75%_90%/0.4),transparent_70%)] blur-2xl"
                ></div>
                <div class="relative grid gap-8 lg:grid-cols-[1.25fr_0.75fr]">
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col gap-3">
                            <p
                                class="text-[11px] font-semibold uppercase tracking-[0.35em] text-muted-foreground"
                            >
                                Transcription run
                            </p>
                            <h1
                                class="text-3xl font-semibold tracking-[-0.02em] text-[var(--text)] sm:text-4xl"
                            >
                                {{ transcription.filename }}
                            </h1>
                            <div
                                class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
                            >
                                <span
                                    class="status-pill"
                                    :class="statusColor(transcription.status)"
                                >
                                    {{ statusLabel }}
                                </span>
                                <span
                                    class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.25em] text-muted-foreground"
                                >
                                    {{ transcription.id }}
                                </span>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div
                                class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/85 p-4 shadow-[0_14px_30px_-24px_rgba(15,23,42,0.25)]"
                            >
                                <p
                                    class="text-[11px] uppercase tracking-[0.3em] text-muted-foreground"
                                >
                                    Created
                                </p>
                                <p
                                    class="mt-2 text-sm font-semibold text-[var(--text)]"
                                >
                                    {{
                                        formatAbsoluteTime(
                                            transcription.created_at,
                                        ) || '--'
                                    }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{
                                        transcription.created_at
                                            ? formatRelativeTime(
                                                  transcription.created_at,
                                                  now.value,
                                              )
                                            : 'Just now'
                                    }}
                                </p>
                            </div>
                            <div
                                class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/85 p-4 shadow-[0_14px_30px_-24px_rgba(15,23,42,0.25)]"
                            >
                                <p
                                    class="text-[11px] uppercase tracking-[0.3em] text-muted-foreground"
                                >
                                    Duration
                                </p>
                                <p
                                    class="mt-2 text-sm font-semibold text-[var(--text)]"
                                >
                                    {{ formattedDuration }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Total runtime
                                </p>
                            </div>
                            <div
                                class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/85 p-4 shadow-[0_14px_30px_-24px_rgba(15,23,42,0.25)]"
                            >
                                <p
                                    class="text-[11px] uppercase tracking-[0.3em] text-muted-foreground"
                                >
                                    Chunks
                                </p>
                                <p
                                    class="mt-2 text-sm font-semibold text-[var(--text)]"
                                >
                                    {{
                                        transcription.chunks_total
                                            ? `${transcription.chunks_completed}/${transcription.chunks_total}`
                                            : '--'
                                    }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Silence-aware splits
                                </p>
                            </div>
                        </div>

                        <div
                            class="rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface-2)]/70 p-4 text-sm text-muted-foreground"
                        >
                            {{ stageMessage }}
                        </div>

                        <div
                            v-if="transcription.error_message"
                            class="rounded-2xl border border-red-200/60 bg-red-50/80 p-4 text-sm text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200"
                        >
                            <p class="text-xs font-semibold uppercase tracking-[0.2em]">
                                Error
                            </p>
                            <p class="mt-2">{{ transcription.error_message }}</p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-6">
                        <div
                            class="rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/85 p-6 shadow-[0_20px_45px_-34px_rgba(15,23,42,0.3)]"
                        >
                            <div class="flex items-center justify-between">
                                <p
                                    class="text-[11px] font-semibold uppercase tracking-[0.35em] text-muted-foreground"
                                >
                                    Chunk progress
                                </p>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground transition hover:text-[var(--text)]"
                                    :disabled="isPolling"
                                    @click="refreshStatus(false)"
                                >
                                    <RefreshCw class="h-3 w-3" />
                                    Refresh
                                </button>
                            </div>

                            <div class="mt-6 flex items-center gap-6">
                                <div
                                    class="relative h-24 w-24 rounded-full p-1"
                                    :style="progressRingStyle"
                                >
                                    <div
                                        class="flex h-full w-full items-center justify-center rounded-full bg-[var(--surface)]"
                                    >
                                        <span
                                            class="text-lg font-semibold text-[var(--text)]"
                                        >
                                            {{ progressPercent }}%
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <p
                                        class="text-lg font-semibold text-[var(--text)]"
                                    >
                                        {{ chunkSummary }}
                                    </p>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        {{ lastUpdatedLabel }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="mt-5 h-2 w-full overflow-hidden rounded-full bg-[var(--surface-2)]"
                            >
                                <div
                                    class="h-full rounded-full bg-[linear-gradient(90deg,var(--accent),hsl(332_70%_70%))]"
                                    :style="{ width: `${progressPercent}%` }"
                                ></div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            <button
                                type="button"
                                class="flex items-center justify-center gap-2 rounded-full bg-primary px-5 py-3 text-xs font-semibold uppercase tracking-[0.25em] text-primary-foreground shadow-[0_18px_36px_-24px_rgba(15,23,42,0.35)] transition hover:-translate-y-0.5 hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!hasAnyDownloads"
                                @click="downloadAll"
                            >
                                <Download class="h-4 w-4" />
                                Download all
                            </button>

                            <button
                                v-if="showTranslateAction"
                                type="button"
                                class="flex items-center justify-center gap-2 rounded-full border border-[color:var(--accent)]/30 bg-[var(--accent-soft)] px-5 py-3 text-xs font-semibold uppercase tracking-[0.25em] text-[var(--accent)] shadow-[0_18px_36px_-24px_rgba(15,23,42,0.25)] transition hover:-translate-y-0.5 hover:bg-[var(--accent-soft)]/80 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="isTranslating"
                                @click="startTranslation"
                            >
                                <Sparkles class="h-4 w-4" />
                                {{ isTranslating ? 'Starting...' : 'Translate to EN' }}
                            </button>

                            <button
                                type="button"
                                class="flex items-center justify-center gap-2 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-5 py-3 text-xs font-semibold uppercase tracking-[0.25em] text-muted-foreground transition hover:text-[var(--text)]"
                                @click="copyShareLink"
                            >
                                <ClipboardCopy class="h-4 w-4" />
                                Copy share link
                            </button>

                            <Link
                                :href="dashboard().url"
                                class="flex items-center justify-center gap-2 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-5 py-3 text-xs font-semibold uppercase tracking-[0.25em] text-muted-foreground transition hover:text-[var(--text)]"
                            >
                                <ArrowLeft class="h-4 w-4" />
                                Back to transcribe
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="animate-transcribe-fade grid gap-6 lg:grid-cols-[1.15fr_0.85fr]"
                style="animation-delay: 0.1s"
            >
                <div
                    class="rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-6 shadow-[0_24px_60px_-38px_rgba(15,23,42,0.35)]"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-[var(--text)]">
                                Output files
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Download subtitle exports as soon as they are ready.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-full border border-[color:var(--border)]/70 bg-[var(--surface)] px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground transition hover:text-[var(--text)]"
                                @click="copyShareLink"
                            >
                                <ClipboardCopy class="h-3 w-3" />
                                Copy link
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-full bg-primary px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-primary-foreground transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!hasAnyDownloads"
                                @click="downloadAll"
                            >
                                <Download class="h-3 w-3" />
                                Download all
                            </button>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4">
                        <div
                            class="flex items-center justify-between gap-4 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/85 p-4 shadow-[0_16px_30px_-26px_rgba(15,23,42,0.3)]"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--accent-soft)]"
                                >
                                    <FileText class="h-5 w-5 text-[var(--accent)]" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-[var(--text)]">
                                        Download SRT
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        SubRip subtitle format
                                    </p>
                                </div>
                            </div>
                            <a
                                v-if="srtUrl"
                                :href="srtUrl"
                                class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-[11px] font-semibold uppercase tracking-[0.2em] text-primary-foreground shadow-[0_12px_24px_-18px_rgba(15,23,42,0.35)] transition hover:-translate-y-0.5 hover:bg-primary/90"
                            >
                                <FileDown class="h-4 w-4" />
                                Download
                            </a>
                            <span
                                v-else
                                class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface-2)] px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                            >
                                Pending
                            </span>
                        </div>

                        <div
                            class="flex items-center justify-between gap-4 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface)]/85 p-4 shadow-[0_16px_30px_-26px_rgba(15,23,42,0.3)]"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--surface-2)]"
                                >
                                    <FileText class="h-5 w-5 text-muted-foreground" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-[var(--text)]">
                                        Download VTT
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        WebVTT for players
                                    </p>
                                </div>
                            </div>
                            <a
                                v-if="vttUrl"
                                :href="vttUrl"
                                class="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-[11px] font-semibold uppercase tracking-[0.2em] text-primary-foreground shadow-[0_12px_24px_-18px_rgba(15,23,42,0.35)] transition hover:-translate-y-0.5 hover:bg-primary/90"
                            >
                                <FileDown class="h-4 w-4" />
                                Download
                            </a>
                            <span
                                v-else
                                class="rounded-full border border-[color:var(--border)]/70 bg-[var(--surface-2)] px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                            >
                                Pending
                            </span>
                        </div>

                        <div
                            class="rounded-2xl border border-dashed border-[color:var(--border)]/70 bg-[var(--surface-2)]/60 px-4 py-4 text-xs text-muted-foreground"
                        >
                            Exports remain available while the run is retained.
                            Download the files locally for backup.
                        </div>
                    </div>
                </div>

                <div
                    class="rounded-3xl border border-[color:var(--border)]/70 bg-[var(--surface)]/80 p-6 shadow-[0_24px_60px_-38px_rgba(15,23,42,0.35)]"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-[var(--text)]">
                                Pipeline progress
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Current stage of the transcription pipeline.
                            </p>
                        </div>
                        <span
                            class="rounded-full px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em]"
                            :class="statusSurface(transcription.status)"
                        >
                            {{ statusLabel }}
                        </span>
                    </div>

                    <div class="mt-6 space-y-4">
                        <div
                            v-for="step in pipelineSteps"
                            :key="step.key"
                            class="flex items-start gap-4"
                        >
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-full border"
                                :class="
                                    step.state === 'complete'
                                        ? 'border-emerald-400/60 bg-emerald-400/20 text-emerald-500'
                                        : step.state === 'failed'
                                          ? 'border-red-400/60 bg-red-400/20 text-red-500'
                                          : step.state === 'current'
                                            ? 'border-[color:var(--accent)]/60 bg-[var(--accent-soft)] text-[var(--accent)]'
                                            : 'border-[color:var(--border)]/70 bg-[var(--surface)] text-muted-foreground'
                                "
                            >
                                <CheckCircle2
                                    v-if="step.state === 'complete'"
                                    class="h-4 w-4"
                                />
                                <span
                                    v-else
                                    class="text-[11px] font-semibold uppercase"
                                >
                                    {{ step.key.slice(0, 2) }}
                                </span>
                            </div>
                            <div class="flex-1">
                                <p
                                    class="text-sm font-semibold text-[var(--text)]"
                                >
                                    {{ step.title }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ step.description }}
                                </p>
                            </div>
                            <span
                                class="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"
                            >
                                {{
                                    step.state === 'complete'
                                        ? 'Done'
                                        : step.state === 'failed'
                                          ? 'Error'
                                          : step.state === 'current'
                                            ? 'Active'
                                            : 'Queued'
                                }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="mt-6 rounded-2xl border border-[color:var(--border)]/70 bg-[var(--surface-2)]/70 p-4 text-xs text-muted-foreground"
                    >
                        <p class="flex items-center gap-2">
                            <Sparkles class="h-4 w-4 text-[var(--accent)]" />
                            Chunk-aware cadence keeps captions readable on-air.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
